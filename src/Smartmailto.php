<?php

namespace Agavesoft\Smartmailto;

use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Jobs\DeliverToSmartmailto;
use Agavesoft\Smartmailto\Mail\MessageEvents;
use Agavesoft\Smartmailto\Outbox\Outbox;
use Agavesoft\Smartmailto\Testing\PullTester;
use DateTimeInterface;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Contracts\Container\Container;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Queue\SyncQueue;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Punto de entrada del SDK (facade `Smartmailto`).
 *
 * Contrato de ingesta v2 de Smartmailto. Reglas que tu app debe cumplir:
 *  - `eventId` debe derivar del hecho de negocio ("ff:orden_pagada:{order_id}"), nunca de un uuid
 *    nuevo: asi un reintento de tu listener no duplica el evento.
 *  - `secrets` son valores de un solo uso (ligas de activacion o pago): Smartmailto los guarda
 *    cifrados y solo los usa dentro del correo.
 *  - Por default todo se encola despues del commit y se reintenta si Smartmailto no responde.
 *  - F-010: con `outbox.enabled`, track/send/identify/link se guardan en la transaccion de tu app
 *    (tabla smartmailto_outbox) y el worker los entrega hasta tener el acuse de Smartmailto.
 */
class Smartmailto
{
    public const MAX_BATCH = 100;

    /** F-008 (B3): maximo por lista de destinatarios adicionales (to, cc, bcc). */
    public const MAX_RECIPIENTS = 10;

    public function __construct(private readonly Container $app) {}

    public function enabled(): bool
    {
        return (bool) $this->config('enabled', true);
    }

    public function isConfigured(): bool
    {
        return $this->enabled() && $this->app->make(SmartmailtoClient::class)->isConfigured();
    }

    /**
     * Crea o actualiza el contacto y sus atributos. Con user_id + email une al invitado con su cuenta.
     *
     * F-011 (R-11): `updatedAt` es la hora del cambio en tu app. Si push y pull traen el mismo atributo,
     * Smartmailto conserva el de la hora mas reciente; sin ella usa la hora de llegada.
     *
     * F-010 (D7): `consent: true` registra que la persona volvio a aceptar correos (por ejemplo al marcar
     * la casilla de tu formulario). Apaga el modo "solo transaccionales" de un contacto que se habia
     * borrado por ARCO y deja la fecha (`consented_at`). Mandalo solo con un consentimiento explicito.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function identify(Identity $identity, array $attributes = [], ?DateTimeInterface $updatedAt = null, bool $consent = false): ?array
    {
        return $this->deliver('identify', $this->identifyBody($identity, $attributes, $updatedAt, $consent));
    }

    /**
     * @internal usado por PendingBatch
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public function identifyBody(Identity $identity, array $attributes, ?DateTimeInterface $updatedAt, bool $consent = false): array
    {
        self::assertContact($identity, 'identify');

        return array_filter([
            ...$identity->toArray(),
            'attributes' => $attributes,
            'updated_at' => $updatedAt?->format(DATE_ATOM),
            'consent' => $consent ?: null,
        ], fn ($value) => $value !== null);
    }

    /**
     * F-010 (J8, regla 14): liga dos contactos como la misma persona; `absorbed` se fusiona en `survivor`
     * (eventos, atributos y workflows en curso). Idempotente por `linkId`, que sale del hecho de negocio
     * (`"ff:link:order:{id}"`). Smartmailto nunca crea contactos aqui: ambos deben existir. Dos cuentas con
     * user_id no se ligan (409 both_have_user_id).
     */
    public function link(Identity $survivor, Identity $absorbed, string $reason, string $linkId): ?array
    {
        if (trim($linkId) === '') {
            throw new InvalidArgumentException('Smartmailto::link() requires a linkId derived from the business fact (e.g. "app:link:order:{id}").');
        }
        self::assertContact($survivor, 'link');
        self::assertContact($absorbed, 'link');

        return $this->deliver('contacts/link', [
            'survivor' => $survivor->toArray(),
            'absorbed' => $absorbed->toArray(),
            'reason' => $reason,
            'link_id' => $linkId,
        ], key: $linkId);
    }

    /**
     * F-010 (regla 17.4): tu app mando por su canal de emergencia el correo de `$idempotencyKey` (al
     * escuchar SmartmailtoOutboxFailed o el webhook `send_expired`). Smartmailto lo registra como "enviado
     * por emergencia desde el proyecto" y ya no lo manda aunque le llegue despues.
     *
     * Con el outbox, la fila `send` pasa a superseded (lanza OutboxRowInFlight si esta en vuelo: reintenta)
     * y el reporte viaja por el outbox con la misma garantia; la identidad y la plantilla salen de esa fila.
     * Sin fila (o sin outbox) pasa `$identity` y `$template`.
     *
     * @param  string|null  $reason  motivo corto: el `reason` del evento ("422", "expired")
     */
    public function reportExternalSend(
        string $idempotencyKey,
        ?Identity $identity = null,
        ?string $template = null,
        ?DateTimeInterface $sentAt = null,
        string $channel = 'ses_direct',
        ?string $reason = null,
    ): void {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Smartmailto::reportExternalSend() requires the idempotencyKey of the send.');
        }
        if (! $this->enabled()) {
            return;
        }

        $sentAt ??= now();
        if ($this->outbox()->enabled()) {
            $this->outbox()->reportExternalSend($idempotencyKey, $identity, $template, $sentAt, $channel, $reason);

            return;
        }

        if ($identity === null || $template === null || $template === '') {
            throw new InvalidArgumentException('Smartmailto::reportExternalSend() requires the identity and the template without the outbox.');
        }

        $this->deliver('send/external', Outbox::reportBody($idempotencyKey, Outbox::identityBody($identity), $template, $sentAt, $channel, $reason), key: $idempotencyKey);
    }

    /**
     * F-011: prueba tu PullResolver contra el contrato de pull sin red (en las pruebas de tu app). Prende
     * el pull con un secreto de prueba, registra la ruta y devuelve un cliente que firma como Smartmailto.
     *
     * @param  PullResolver|class-string<PullResolver>|null  $resolver  default: el configurado
     * @param  list<string>|null  $catalog  llaves de contacto permitidas (sin red); null = la config
     */
    public function fakePull(PullResolver|string|null $resolver = null, ?array $catalog = null): PullTester
    {
        return PullTester::install($this->app, $resolver, $catalog);
    }

    /**
     * Registra un evento. Es idempotente por `eventId`.
     *
     * @param  array<string, mixed>  $properties  datos para decidir y armar el correo (sin datos fiscales)
     * @param  array<string, string>  $secrets  valores de un solo uso, solo visibles dentro del correo
     * @param  array{0: string, 1: string|int}|array{type: string, id: string|int}|null  $object  objeto de negocio, ej. ['order', 100]
     */
    public function track(
        string $event,
        Identity $identity,
        array $properties = [],
        string $eventId = '',
        array $secrets = [],
        ?array $object = null,
        ?DateTimeInterface $occurredAt = null,
    ): ?array {
        return $this->deliver('track', $this->trackBody($event, $identity, $properties, $eventId, $secrets, $object, $occurredAt), key: $eventId);
    }

    /**
     * Envio transaccional inmediato con una plantilla. Es idempotente por `idempotencyKey`.
     *
     * F-008 (B3): `to`, `cc` y `bcc` son destinatarios adicionales (solo plantillas `transactional`, max 10
     * por lista, no crean contactos). `replyTo` y `from` aceptan un correo o ['email' => ..., 'name' => ...]
     * (`from` solo del dominio del proyecto). `secrets` son ligas de un solo uso (`{{secret:nombre}}`).
     *
     * @param  array<string, mixed>  $data
     * @param  list<Attachment|UploadedFile|string>  $attachments  Attachment, archivo subido o ruta
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  string|array{email: string, name?: string|null}|null  $replyTo
     * @param  string|array{email: string, name?: string|null}|null  $from
     * @param  array<string, string>  $secrets
     *
     * F-010 (R2): con `Identity::external($correo)` el destinatario no se vuelve contacto (solo plantillas
     * transaccionales); `origin` liga el envio al contacto de tu app que lo origino (p. ej. quien emitio
     * el CFDI). Con el outbox, `sendBefore` es obligatorio: es lo que evita un duplicado con tu envio de
     * emergencia (regla 17).
     */
    public function send(
        string $template,
        Identity $identity,
        array $data = [],
        string $idempotencyKey = '',
        ?DateTimeInterface $sendBefore = null,
        array $attachments = [],
        array $cc = [],
        array $bcc = [],
        string|array|null $replyTo = null,
        array $to = [],
        string|array|null $from = null,
        array $secrets = [],
        ?Identity $origin = null,
    ): ?array {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Smartmailto::send() requires an idempotencyKey (e.g. "app:receipt:{order_id}").');
        }
        if ($origin?->external) {
            throw new InvalidArgumentException('Smartmailto::send() origin must be a contact of your app (Identity::user or guest), not an external recipient.');
        }

        // Apagado no hace nada: ni lee ni codifica adjuntos.
        if (! $this->enabled()) {
            return null;
        }

        if ($sendBefore === null && $this->outbox()->enabled()) {
            throw new InvalidArgumentException('Smartmailto::send() requires sendBefore with the outbox enabled (e.g. now()->addMinutes(10) for a receipt).');
        }

        // F-008: si no sale antes de sendBefore, Smartmailto no lo envia (410) y se dispara
        // SmartmailtoDeliveryFailed: la app lo manda por su cuenta sin riesgo de duplicado.
        // Lo opcional vacio no viaja: un send() sin B3 manda exactamente el cuerpo de antes.
        return $this->deliver(
            'send',
            array_filter([
                ...$identity->toArray(),
                'template' => $template,
                'data' => $data,
                'idempotency_key' => $idempotencyKey,
                'send_before' => $sendBefore?->format(DATE_ATOM),
                'to' => $this->recipients('to', $to),
                'cc' => $this->recipients('cc', $cc),
                'bcc' => $this->recipients('bcc', $bcc),
                'reply_to' => $this->address('replyTo', $replyTo),
                'from' => $this->address('from', $from),
                'attachments' => $this->attachments($attachments),
                'secrets' => $secrets ?: null,
                'recipient_kind' => $identity->external ? 'external' : null,
                'origin' => $origin?->toArray(),
            ], fn ($value) => $value !== null),
            ['Idempotency-Key' => $idempotencyKey],
            $idempotencyKey,
        );
    }

    /**
     * Fase 0: correo sin plantilla. Tu app arma el asunto y el cuerpo (`html` y/o `text`); Smartmailto
     * aplica lista blanca, remitente, Dummy, idempotencia y adjuntos igual que con plantilla. Siempre es
     * transaccional (sin baja de un clic ni pixel). Es lo que usa el mailer `smartmailto` de Laravel.
     *
     * @param  list<Attachment|UploadedFile|string>  $attachments
     * @param  list<string>  $to
     * @param  list<string>  $cc
     * @param  list<string>  $bcc
     * @param  string|array{email: string, name?: string|null}|null  $replyTo
     * @param  string|array{email: string, name?: string|null}|null  $from
     */
    public function sendRaw(
        Identity $identity,
        string $subject,
        ?string $html = null,
        ?string $text = null,
        string $idempotencyKey = '',
        ?DateTimeInterface $sendBefore = null,
        array $attachments = [],
        array $cc = [],
        array $bcc = [],
        string|array|null $replyTo = null,
        array $to = [],
        string|array|null $from = null,
        array $messageHeaders = [],
    ): ?array {
        if (trim($idempotencyKey) === '') {
            throw new InvalidArgumentException('Smartmailto::sendRaw() requires an idempotencyKey (e.g. "app:aviso:{id}").');
        }
        if (trim($subject) === '') {
            throw new InvalidArgumentException('Smartmailto::sendRaw() requires a subject.');
        }
        if (($html === null || $html === '') && ($text === null || $text === '')) {
            throw new InvalidArgumentException('Smartmailto::sendRaw() requires html or text.');
        }

        if (! $this->enabled()) {
            return null;
        }

        if ($sendBefore === null && $this->outbox()->enabled()) {
            throw new InvalidArgumentException('Smartmailto::sendRaw() requires sendBefore with the outbox enabled.');
        }

        return $this->deliver(
            'send',
            array_filter([
                ...$identity->toArray(),
                'subject' => $subject,
                'html' => $html !== '' ? $html : null,
                'text' => $text !== '' ? $text : null,
                'idempotency_key' => $idempotencyKey,
                'send_before' => $sendBefore?->format(DATE_ATOM),
                'to' => $this->recipients('to', $to),
                'cc' => $this->recipients('cc', $cc),
                'bcc' => $this->recipients('bcc', $bcc),
                'reply_to' => $this->address('replyTo', $replyTo),
                'from' => $this->address('from', $from),
                'attachments' => $this->attachments($attachments),
                'recipient_kind' => $identity->external ? 'external' : null,
            ], fn ($value) => $value !== null),
            ['Idempotency-Key' => $idempotencyKey],
            $idempotencyKey,
            // Vuelve en SmartmailtoMessageSent/Failed; no se manda a Smartmailto.
            ['idempotency_key' => $idempotencyKey, 'headers' => array_map('strval', $messageHeaders)],
        );
    }

    /**
     * Lote de identify/track. Con backfill=true los eventos se guardan con su fecha real y no
     * disparan workflows (carga inicial). Se parte en lotes de 100.
     */
    public function batch(bool $backfill = false): PendingBatch
    {
        return new PendingBatch($this, $backfill);
    }

    /**
     * Fase 1: envios del proyecto (sincrono), del mas nuevo al mas viejo. Filtros: `from`, `to`
     * (DateTimeInterface o ISO 8601), `status` (string o lista: sent, suppressed, failed...), `recipient`,
     * `origin` (api|workflow|event), `idempotency_key`, `per_page` (max 100), `page`. Con `content: true`
     * cada envio trae `content.html` (el correo capturado).
     *
     * @param  array<string, mixed>  $filters
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}|null
     */
    public function listSends(array $filters = [], bool $content = false): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $query = [];
        foreach ($filters as $key => $value) {
            $query[$key] = match (true) {
                $value instanceof DateTimeInterface => $value->format(DATE_ATOM),
                is_array($value) => implode(',', $value),
                default => $value,
            };
        }
        if ($content) {
            $query['include'] = 'content';
        }
        $query = array_filter($query, fn ($v) => $v !== null && $v !== '');

        return $this->app->make(SmartmailtoClient::class)->get('sends'.($query ? '?'.http_build_query($query) : ''));
    }

    /**
     * Fase 1: si una direccion esta suprimida en el proyecto (rebote duro, queja, baja o manual).
     * `blocks_transactional` es lo que importa para un transaccional (la baja de marketing no lo bloquea).
     *
     * @return array{email: string, suppressed: bool, blocks_transactional: bool, reasons: list<array<string, mixed>>}|null
     */
    public function suppression(string $email): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get('suppressions?'.http_build_query(['email' => $email]));
    }

    /**
     * Fase 4: remitentes que puede usar el ambiente de la llave (`source`: project | account). Un
     * remitente de cuenta con `revoked: true` hace fallar sus envios con `sender_revoked`. Sin credenciales.
     *
     * @return array{environment: string, data: list<array<string, mixed>>}|null
     */
    public function senders(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get('senders');
    }

    /**
     * Fase 1: rota la llave configurada. Devuelve `api_token` (la nueva, unica vez); la actual sigue
     * sirviendo `graceHours` (null = default del servidor, 24) y despues se revoca sola. Guarda la nueva en
     * tu gestor de secretos y actualiza SMARTMAILTO_API_TOKEN antes de que venza la gracia.
     *
     * @return array{api_token: string, key: array<string, mixed>, previous: array<string, mixed>}|null
     */
    public function rotateKey(?int $graceHours = null): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->post('keys/rotate', array_filter(['grace_hours' => $graceHours], fn ($v) => $v !== null));
    }

    /**
     * F-008: salud del proyecto en Smartmailto (sincrono). `status`: ok | degraded | down. Pensado para
     * la bandera de emergencia de la app (mandar sus correos esenciales directo mientras no sea ok).
     *
     * @return array<string, mixed>|null
     */
    public function health(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get('health');
    }

    /**
     * F-006: datos que Smartmailto guarda de una persona (correo, atributos, eventos y envios).
     * Sincrono; queda registrado en la bitacora de acceso del proyecto. null si no existe.
     *
     * @return array<string, mixed>|null
     */
    public function contact(Identity $identity): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        try {
            return $this->app->make(SmartmailtoClient::class)->get('contacts?'.http_build_query($this->lookup($identity)));
        } catch (SmartmailtoException $e) {
            if ($e->status === 404) {
                return null;
            }
            throw $e;
        }
    }

    /**
     * F-006: borrado ARCO (cancelacion) de una persona. Sincrono. Devuelve false si no existia.
     * Smartmailto conserva solo la marca (hash) de no escribirle si se habia dado de baja.
     */
    public function forget(Identity $identity): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $this->app->make(SmartmailtoClient::class)->delete('contacts?'.http_build_query($this->lookup($identity)));

            return true;
        } catch (SmartmailtoException $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
    }

    /** F-006: el correo enviado, re-generado (ligas de un solo uso ocultas). */
    public function renderedEmail(int $sendId): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get("sends/{$sendId}/render");
    }

    /**
     * F-008 (B3): plantillas del proyecto (sin cuerpos; `checksum` para comparar sin descargar).
     * Sincrono. Requiere el aprovisionamiento habilitado en el proyecto (si no: 403).
     *
     * @return list<array<string, mixed>>|null
     */
    public function templates(): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get('templates')['templates'] ?? [];
    }

    /**
     * F-008 (B3): crea o actualiza una plantilla por nombre. Idempotente (`result`: created | updated |
     * unchanged). Solo viaja lo que se pasa: sin `layout` el servidor conserva el actual y sin `kind` usa
     * `marketing` al crear. F-009: una plantilla nueva queda `draft` (se activa con activateTemplate());
     * las referencias a variables fuera del catalogo vuelven como `warnings`.
     *
     * @return array<string, mixed>|null
     */
    public function putTemplate(
        string $name,
        string $subject,
        string $body,
        ?string $layout = null,
        ?string $kind = null,
        ?string $displayName = null,
        ?string $description = null,
    ): ?array {
        return $this->provision('templates', $name, array_filter([
            'subject' => $subject,
            'body' => $body,
            'layout' => $layout,
            'kind' => $kind,
            'display_name' => $displayName,
            'description' => $description,
        ], fn ($value) => $value !== null));
    }

    /**
     * F-008 (B3): crea o actualiza un bloque reutilizable (`{{> nombre}}`). Aplica a todas las
     * plantillas desde el siguiente envio.
     *
     * @return array<string, mixed>|null
     */
    public function putPartial(string $name, string $body, ?string $description = null): ?array
    {
        return $this->provision('partials', $name, array_filter(['body' => $body, 'description' => $description], fn ($value) => $value !== null));
    }

    /**
     * F-008 (B3): crea o actualiza un workflow. Guardar nunca lo activa: se crea sin activar y si la
     * definicion cambia se guarda inactivo (`requires_activation`). F-009: se activa con
     * activateWorkflow() (o en el panel), con las mismas validaciones.
     *
     * @param  string|array<string, mixed>  $definition  YAML/JSON en texto o la definicion como arreglo
     * @return array<string, mixed>|null
     */
    public function putWorkflow(string $name, string|array $definition, string $format = 'yaml'): ?array
    {
        return $this->provision('workflows', $name, is_array($definition) ? ['definition' => $definition] : ['definition' => $definition, 'format' => $format]);
    }

    /**
     * F-009: activa una plantilla. Exige cero referencias a variables inexistentes u obsoletas nuevas;
     * si no, SmartmailtoException 422 `invalid_references` con `items()`.
     *
     * @return array<string, mixed>|null `{ result: activated|unchanged, status: active }`
     */
    public function activateTemplate(string $name): ?array
    {
        return $this->provisioning('post', 'templates/'.rawurlencode($name).'/activate');
    }

    /**
     * Fase 3: versiones inmutables de la plantilla (v1..n), la version que usa cada ambiente y la del
     * ambiente de esta llave (`effective`). Requiere el aprovisionamiento habilitado.
     *
     * @return array<string, mixed>|null
     */
    public function templateRevisions(string $name): ?array
    {
        return $this->provisioning('get', 'templates/'.rawurlencode($name).'/revisions');
    }

    /**
     * Fase 3: fija la version `$revision` en el ambiente de esta llave (null = seguir la ultima).
     *
     * @return array<string, mixed>|null la activacion (`action`, `revision`, `previous_revision`, ...)
     */
    public function activateTemplateRevision(string $name, ?int $revision): ?array
    {
        return $this->provisioning('post', 'templates/'.rawurlencode($name).'/revisions/activate', ['revision' => $revision]);
    }

    /**
     * Fase 3: fija en el ambiente de esta llave la version que usa `$from` (p. ej. con una llave de pro,
     * promoteTemplate('recibo', 'dev') sube a pro lo probado en dev). Una llave de dev nunca cambia pro.
     *
     * @return array<string, mixed>|null
     */
    public function promoteTemplate(string $name, string $from): ?array
    {
        return $this->provisioning('post', 'templates/'.rawurlencode($name).'/promote', ['from' => $from]);
    }

    /**
     * Fase 3: regresa el ambiente de esta llave a la version que tenia antes de su ultimo cambio.
     * Sin cambio previo: SmartmailtoException 422 `nothing_to_rollback`.
     *
     * @return array<string, mixed>|null
     */
    public function rollbackTemplate(string $name): ?array
    {
        return $this->provisioning('post', 'templates/'.rawurlencode($name).'/rollback');
    }

    /**
     * F-009: activa un workflow. Revisa sus condiciones y todas sus plantillas (que deben estar activas);
     * si no, SmartmailtoException 422 `invalid_references` con `items()` (incluye las plantillas).
     *
     * @return array<string, mixed>|null
     */
    public function activateWorkflow(string $name): ?array
    {
        return $this->provisioning('post', 'workflows/'.rawurlencode($name).'/activate');
    }

    /**
     * F-009 (RN-16): aplica un paquete completo en una sola peticion, todo o nada:
     * `{ variables, partials, templates, workflows }` (cada item con su `name`, y las variables con
     * `scope`, `key` y `event`). Con `activate` activa plantillas y workflows en la misma transaccion.
     * Si algo falla no cambia nada (lo activo sigue enviando) y lanza SmartmailtoException 422
     * `provision_failed` con todos los `items()` fallidos.
     *
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>|null `{ results, warnings }`
     */
    public function provisionPackage(array $package, bool $activate = false): ?array
    {
        return $this->provisioning('post', 'provision', [...$package, 'activate' => $activate]);
    }

    /**
     * F-009: el mismo paquete que provisionPackage() en modo de prueba (no guarda nada). Responde 200
     * aunque haya errores: revisa `valid` (`{ valid, errors, warnings, results }`).
     *
     * @param  array<string, mixed>  $package
     * @return array<string, mixed>|null
     */
    public function validatePackage(array $package, bool $activate = false): ?array
    {
        return $this->provisioning('post', 'validate', [...$package, 'activate' => $activate]);
    }

    /**
     * F-009: catalogo de variables del proyecto (sincrono). Filtra por seccion y por evento.
     *
     * @param  string|null  $scope  contact | event | secret
     * @return list<array<string, mixed>>|null
     */
    public function variables(?string $scope = null, ?string $event = null): ?array
    {
        $query = http_build_query(array_filter(['scope' => $scope, 'event' => $event], fn ($value) => $value !== null));

        $response = $this->provisioning('get', 'variables'.($query !== '' ? "?{$query}" : ''));

        return $response === null ? null : ($response['data'] ?? []);
    }

    /**
     * F-009: crea o actualiza una variable del catalogo (`result`: created | updated | unchanged, mas
     * `warnings`). `$definition`: label, type, description, allowed_values, required, default, filterable,
     * sensitive. `$event` solo en `event`/`secret` (null = comun). La marca `sensitive` por API solo se
     * enciende: un `false` sobre una sensible la conserva con el aviso `sensitive_kept`.
     *
     * @param  array<string, mixed>  $definition
     * @return array<string, mixed>|null
     */
    public function putVariable(string $scope, string $key, array $definition, ?string $event = null): ?array
    {
        return $this->provisioning('put', $this->variablePath($scope, $key, $event), $definition);
    }

    /**
     * F-009: marca una variable como obsoleta: lo que ya la usa sigue funcionando, pero nada nuevo puede
     * activarse con ella.
     *
     * @return array<string, mixed>|null
     */
    public function obsoleteVariable(string $scope, string $key, ?string $event = null): ?array
    {
        return $this->provisioning('post', $this->variablePath($scope, $key, $event, '/obsolete'));
    }

    /**
     * F-009: borra una variable sin usos. Con usos: SmartmailtoException 409 `in_use` con `usages()`.
     * Devuelve false si no existia.
     */
    public function deleteVariable(string $scope, string $key, ?string $event = null): bool
    {
        if (! $this->enabled()) {
            return false;
        }

        try {
            $this->provisioning('delete', $this->variablePath($scope, $key, $event));

            return true;
        } catch (SmartmailtoException $e) {
            if ($e->status === 404) {
                return false;
            }
            throw $e;
        }
    }

    /**
     * F-009: donde se usa una variable (tipo, nombre y ubicacion).
     *
     * @return list<array<string, mixed>>|null
     */
    public function variableUsages(string $scope, string $key, ?string $event = null): ?array
    {
        $response = $this->provisioning('get', $this->variablePath($scope, $key, $event, '/usages'));

        return $response === null ? null : ($response['usages'] ?? []);
    }

    /**
     * F-009: esquema para agentes y herramientas (tipos de paso, operadores, reglas de plantilla y el
     * catalogo con un fragmento JSON Schema por variable). Es la misma fuente que la ayuda del panel.
     *
     * @return array<string, mixed>|null
     */
    public function schema(): ?array
    {
        return $this->provisioning('get', 'schema');
    }

    /**
     * F-008 (B2): verifica el webhook de falla de Smartmailto (`send_expired`, `job_failed`, `test`).
     * Firma `sha256=hmac(secret, "{timestamp}.{cuerpo crudo}")` comparada en tiempo constante y ventana
     * contra replay sobre `X-Smartmailto-Timestamp` (en ambos sentidos).
     *
     * @param  string|null  $secret  default: `smartmailto.webhook_secret` (SMARTMAILTO_WEBHOOK_SECRET)
     */
    public function verifyWebhook(Request $request, ?string $secret = null, int $toleranceSeconds = 300): bool
    {
        $secret ??= (string) $this->config('webhook_secret');
        $timestamp = (string) $request->header('X-Smartmailto-Timestamp', '');
        $signature = (string) $request->header('X-Smartmailto-Signature', '');

        if ($secret === '' || $signature === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->getTimestamp() - (int) $timestamp) > $toleranceSeconds) {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$request->getContent(), $secret);

        return hash_equals($expected, $signature);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function provision(string $resource, string $name, array $body): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->put($resource.'/'.rawurlencode($name), $body);
    }

    /**
     * F-009: llamada sincrona de aprovisionamiento o catalogo (el fake la reemplaza sin red).
     *
     * @param  'get'|'post'|'put'|'delete'  $method
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>|null
     */
    protected function provisioning(string $method, string $path, array $body = []): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        $client = $this->app->make(SmartmailtoClient::class)->withTimeout((int) $this->config('provision_timeout', 120));

        return match ($method) {
            'get' => $client->get($path),
            'post' => $client->post($path, $body),
            'put' => $client->put($path, $body),
            'delete' => $client->delete($path),
        };
    }

    /** `?event=` tambien en obsolete, delete y usages: sin el, una variable de evento no se encuentra. */
    private function variablePath(string $scope, string $key, ?string $event, string $suffix = ''): string
    {
        return 'variables/'.rawurlencode($scope).'/'.rawurlencode($key).$suffix
            .($event !== null ? '?'.http_build_query(['event' => $event]) : '');
    }

    /** @return array{user_id?: string, email?: string} */
    private function lookup(Identity $identity): array
    {
        // Con id se busca por id (el correo de una cuenta puede no ser unico).
        return $identity->userId !== null ? ['user_id' => $identity->userId] : ['email' => (string) $identity->email];
    }

    /** Estado de un evento ya registrado (sincrono). */
    public function eventStatus(int $id): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        return $this->app->make(SmartmailtoClient::class)->get("events/{$id}");
    }

    /**
     * @param  list<array<string, mixed>>  $items
     *
     * @internal usado por PendingBatch
     */
    public function deliverBatch(array $items, bool $backfill): void
    {
        foreach (array_chunk($items, self::MAX_BATCH) as $index => $chunk) {
            $this->deliver('batch', ['backfill' => $backfill, 'items' => $chunk], key: 'batch:'.$index);
        }
    }

    /**
     * @internal usado por PendingBatch
     *
     * @return array<string, mixed>
     */
    public function trackBody(string $event, Identity $identity, array $properties, string $eventId, array $secrets, ?array $object, ?DateTimeInterface $occurredAt): array
    {
        if (trim($eventId) === '') {
            throw new InvalidArgumentException('Smartmailto::track() requires an eventId derived from the business fact (e.g. "app:order_paid:{order_id}").');
        }
        self::assertContact($identity, 'track');

        return array_filter([
            ...$identity->toArray(),
            'event' => $event,
            'event_id' => $eventId,
            'properties' => $properties,
            'secrets' => $secrets ?: null,
            'object' => $this->normalizeObject($object),
            'occurred_at' => $occurredAt?->format(DATE_ATOM),
        ], fn ($value) => $value !== null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    protected function deliver(string $endpoint, array $body, array $headers = [], ?string $key = null, array $meta = []): ?array
    {
        if (! $this->enabled()) {
            return null;
        }

        // F-010 (J1): con el outbox la llamada queda en la transaccion de tu app y la entrega el worker.
        if ($this->outbox()->enabled() && ($kind = $this->outbox()->kindFor($endpoint)) !== null) {
            $this->outbox()->write($kind, $key, $meta !== [] ? [...$body, MessageEvents::META_KEY => $meta] : $body, $body['template'] ?? null, isset($body['send_before']) ? Carbon::parse($body['send_before']) : null);

            return null;
        }

        if (! $this->config('queue', true)) {
            try {
                $response = $this->app->make(SmartmailtoClient::class)->post($endpoint, $body, $headers);
            } catch (SmartmailtoException $e) {
                MessageEvents::failed($meta, $e->status, $e->getMessage(), 'direct', $e->response);

                throw $e;
            }
            MessageEvents::sent($meta, $response);

            return $response;
        }

        $job = (new DeliverToSmartmailto($endpoint, $body, $headers, $key, $meta))->afterCommit();

        $connection = $this->config('queue_connection');
        if ($connection) {
            $job->onConnection($connection);
        }

        // Con la cola `sync` no hay reintento posible (release() no reencola y una excepcion saldria del
        // commit de la app): se entrega una vez y una falla se reporta con SmartmailtoDeliveryFailed.
        if ($this->app->make('queue')->connection($connection ?: null) instanceof SyncQueue) {
            $this->app->bound('db')
                ? $this->app->make('db')->afterCommit(fn () => $this->deliverOnce($job))
                : $this->deliverOnce($job);

            return null;
        }
        if ($queue = $this->config('queue_name')) {
            $job->onQueue($queue);
        }

        $this->app->make(Dispatcher::class)->dispatch($job);

        return null;
    }

    private function deliverOnce(DeliverToSmartmailto $job): void
    {
        try {
            $response = $this->app->make(SmartmailtoClient::class)->post($job->endpoint, $job->body, $job->headers);
            $job->reportBatchItems($response);
            MessageEvents::sent($job->meta, $response);
        } catch (SmartmailtoException $e) {
            $job->failed($e);
        }
    }

    /** @return list<string>|null */
    private function recipients(string $field, array $emails): ?array
    {
        foreach ($emails as $email) {
            if (! is_string($email)) {
                throw new InvalidArgumentException("Smartmailto::send() {$field} must be a list of email strings.");
            }
        }
        $emails = array_values(array_filter(array_map(trim(...), $emails), fn ($email) => $email !== ''));
        if (count($emails) > self::MAX_RECIPIENTS) {
            throw new InvalidArgumentException("Smartmailto::send() {$field} may not have more than ".self::MAX_RECIPIENTS.' recipients.');
        }

        return $emails ?: null;
    }

    /** @return array{email: string, name?: string}|null */
    private function address(string $field, string|array|null $address): ?array
    {
        if ($address === null || $address === '' || $address === []) {
            return null;
        }

        $email = trim((string) (is_string($address) ? $address : ($address['email'] ?? '')));
        if ($email === '') {
            throw new InvalidArgumentException("Smartmailto::send() {$field} requires an email.");
        }
        $name = is_array($address) ? ($address['name'] ?? null) : null;

        return $name !== null && $name !== '' ? ['email' => $email, 'name' => (string) $name] : ['email' => $email];
    }

    /**
     * Valida los limites del servidor antes de encolar: un adjunto de mas falla aqui, no en un job
     * horas despues.
     *
     * @param  list<Attachment|UploadedFile|string>  $attachments
     * @return list<array{filename: string, content: string, content_type: string}>|null
     */
    private function attachments(array $attachments): ?array
    {
        if ($attachments === []) {
            return null;
        }

        $maxFiles = (int) $this->config('attachments.max_files', 10);
        if (count($attachments) > $maxFiles) {
            throw new InvalidArgumentException("Smartmailto::send() accepts at most {$maxFiles} attachments.");
        }

        $attachments = array_map(fn ($attachment) => Attachment::from($attachment), array_values($attachments));
        $inline = array_filter($attachments, fn (Attachment $attachment) => $attachment->isInline());

        $maxBytes = (int) $this->config('attachments.max_bytes', 7 * 1024 * 1024);
        $total = array_sum(array_map(fn (Attachment $attachment) => $attachment->size(), $inline));
        if ($total > $maxBytes) {
            throw new InvalidArgumentException("Smartmailto::send() attachments total {$total} bytes, over the {$maxBytes} bytes limit.");
        }

        if (! $this->outbox()->enabled()) {
            return array_map(fn (Attachment $attachment) => $attachment->toArray(), $attachments);
        }

        // F-010 (J12): el outbox no guarda base64 grande; un fromDisk se guarda como referencia y se firma
        // en cada intento.
        $inlineMax = (int) $this->config('attachments.inline_max_bytes', 1024 * 1024);
        foreach ($inline as $attachment) {
            if ($attachment->size() > $inlineMax) {
                throw new InvalidArgumentException("Smartmailto::send() attachment {$attachment->filename} has {$attachment->size()} bytes, over the {$inlineMax} bytes inline limit of the outbox: use Attachment::fromDisk() or fromUrl().");
            }
        }

        return array_map(fn (Attachment $attachment) => $attachment->toOutbox(), $attachments);
    }

    /** Un destinatario externo solo existe en send(): nunca es identidad de un evento ni de un contacto. */
    private static function assertContact(Identity $identity, string $method): void
    {
        if ($identity->external) {
            throw new InvalidArgumentException("Smartmailto::{$method}() does not accept Identity::external(): external recipients are never contacts.");
        }
    }

    /** Fase 0: el mailer `smartmailto` lo usa para poner sendBefore cuando el outbox esta prendido. */
    public function outboxEnabled(): bool
    {
        return $this->outbox()->enabled();
    }

    protected function outbox(): Outbox
    {
        return $this->app->make(Outbox::class);
    }

    /** @return array{type: string, id: string}|null */
    private function normalizeObject(?array $object): ?array
    {
        if ($object === null) {
            return null;
        }

        $type = $object['type'] ?? $object[0] ?? null;
        $id = $object['id'] ?? $object[1] ?? null;

        if (! is_string($type) || $id === null || $id === '') {
            throw new InvalidArgumentException('Smartmailto object must be [type, id], e.g. ["order", 100].');
        }

        return ['type' => $type, 'id' => (string) $id];
    }

    private function config(string $key, mixed $default = null): mixed
    {
        return $this->app->make('config')->get("smartmailto.{$key}", $default);
    }
}
