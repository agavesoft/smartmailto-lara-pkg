<?php

namespace Agavesoft\Smartmailto\Outbox;

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Events\SmartmailtoOutboxFailed;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Mail\MessageEvents;
use Agavesoft\Smartmailto\SmartmailtoClient;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * F-010 (J1, regla 2 y 17): entrega las filas del outbox.
 *
 * Cada transicion es un UPDATE condicionado al estado esperado (`WHERE status = ...`): dos workers no
 * entregan la misma fila y una fila `sending` nunca se pisa (el reporte de emergencia exige que no este
 * en vuelo; al volver de la peticion el worker solo escribe si la fila sigue `sending`).
 *
 * - Se reintenta lo transitorio (red, 5xx, 429, 401/403, sin acuse) con backoff hasta `give_up_after`.
 * - Un `send` que llega a su `send_before` sin acuse se cierra `expired`; un rechazo definitivo, `failed`.
 *   Antes de cerrarlo (emergencia) se consulta GET /api/send/{key}: si ya salio o esta en cola, queda
 *   `acked` y no hay emergencia.
 */
class OutboxWorker
{
    /** Estados de la consulta (contrato §2b) que significan "sin emergencia". */
    private const NO_EMERGENCY = ['queued', 'sending', 'sent', 'sent_externally', 'duplicate_external', 'suppressed', 'skipped'];

    public function __construct(
        private readonly Container $app,
        private readonly Outbox $outbox,
        private readonly OutboxAlerts $alerts,
    ) {}

    /**
     * Una pasada: aviso de vida, filas colgadas, vencidas, rendidas, entrega de lo debido y alertas.
     *
     * @return array{delivered: int, acked: int, retried: int, closed: int}
     */
    public function runOnce(): array
    {
        $stats = ['delivered' => 0, 'acked' => 0, 'retried' => 0, 'closed' => 0];

        $this->heartbeat();
        $this->reclaimStale();

        // Un `send` vencido se cierra aunque su siguiente intento sea despues (backoff de 1 h > 10 min).
        foreach ($this->outbox->table()->where('kind', 'send')->where('status', Outbox::PENDING)
            ->whereNotNull('send_before')->where('send_before', '<=', now())->orderBy('id')->get() as $row) {
            if ($this->claim($row)) {
                $stats[$this->close($row, Outbox::EXPIRED, 'expired')]++;
            }
        }

        $giveUp = now()->subMinutes((int) $this->outbox->config('give_up_after', 72 * 60));
        foreach ($this->outbox->table()->where('status', Outbox::PENDING)->whereNotNull('first_attempt_at')
            ->where('first_attempt_at', '<=', $giveUp)->orderBy('id')->get() as $row) {
            if ($this->claim($row)) {
                $stats[$this->close($row, Outbox::FAILED, 'gave_up')]++;
            }
        }

        $due = $this->outbox->table()->where('status', Outbox::PENDING)->where('next_attempt_at', '<=', now())
            ->orderBy('id')->limit((int) $this->outbox->config('batch_size', 100))->get();
        foreach ($due as $row) {
            if (! $this->claim($row)) {
                continue;
            }
            $stats['delivered']++;
            $stats[$this->deliver($row)]++;
        }

        $this->alerts->evaluate();

        return $stats;
    }

    /** pending → sending, solo si sigue pending (otro worker o un reporte de emergencia la pudo tomar). */
    private function claim(object $row): bool
    {
        $now = now();

        return $this->outbox->table()->where('id', $row->id)->where('status', Outbox::PENDING)->update([
            'status' => Outbox::SENDING,
            'attempts' => $row->attempts + 1,
            'first_attempt_at' => $row->first_attempt_at ?? $now,
            'updated_at' => $now,
        ]) === 1;
    }

    /** @return 'acked'|'retried'|'closed' */
    private function deliver(object $row): string
    {
        $row->attempts++;

        try {
            $body = $this->outbox->decode($row);
            $row->message_meta = MessageEvents::extract($body); // nunca viaja a Smartmailto
            $body = $this->resolveAttachments($body);
            $headers = $row->kind === 'send' ? ['Idempotency-Key' => $row->key] : [];
            $response = $this->client()->post(Outbox::KINDS[$row->kind], $body, $headers);
        } catch (SmartmailtoException $e) {
            return match ($this->classify($row->kind, $e)) {
                'retry' => $this->retry($row, self::describe($e), $e->retryAfter),
                'expired' => $this->close($row, Outbox::EXPIRED, 'expired', $e),
                default => $this->close($row, Outbox::FAILED, (string) $e->status, $e),
            };
        } catch (Throwable $e) {
            // Disco del adjunto, payload ilegible (APP_KEY rotada): se reintenta y alerta por antiguedad.
            return $this->retry($row, 'error '.class_basename($e));
        }

        if (! $this->acknowledged($row, $response)) {
            return $this->retry($row, 'missing ack');
        }

        $this->ack($row, $response);
        MessageEvents::sent($row->message_meta ?? [], $response);

        return 'acked';
    }

    /**
     * Acuse que cierra la fila (D20 del servidor): track con su event_id; send y external_report con su
     * idempotency_key; link con su link_id; identify (sin campos de acuse) con cualquier 2xx.
     *
     * @param  array<string, mixed>  $response
     */
    private function acknowledged(object $row, array $response): bool
    {
        return match ($row->kind) {
            'track' => ($response['ack'] ?? null) === true && ($response['event_id'] ?? null) === $row->key,
            'send', 'external_report' => ($response['ack'] ?? null) === true && ($response['idempotency_key'] ?? null) === $row->key,
            'link' => ($response['link_id'] ?? null) === $row->key,
            default => true,
        };
    }

    /** @return 'retry'|'expired'|'rejected' */
    private function classify(string $kind, SmartmailtoException $e): string
    {
        $status = (int) $e->status;

        return match (true) {
            // Sin configurar (0), token rotado o sin permiso: es de la app, no de la fila. Nunca la falla.
            $e->transient, in_array($status, [0, 401, 403, 408], true) => 'retry',
            $kind === 'send' && $status === 410 => 'expired',
            // El reporte de un envio en vuelo (send_in_flight) o una carrera (retry): reintentable.
            $kind === 'external_report' && $status === 409 => 'retry',
            // El contacto puede venir en una fila anterior aun pendiente; contact_changed es una carrera.
            $kind === 'link' && ($status === 404 || ($status === 409 && $e->error() === 'contact_changed')) => 'retry',
            default => 'rejected',
        };
    }

    /** @return 'retried' */
    private function retry(object $row, string $error, ?int $retryAfter = null): string
    {
        $backoff = array_values((array) $this->outbox->config('backoff', [30, 120, 600, 3600])) ?: [3600];
        $delay = $retryAfter ?? $backoff[min(max($row->attempts - 1, 0), count($backoff) - 1)];
        $next = now()->addSeconds($delay);

        // Un send no espera mas alla de su send_before: ahi se cierra (y se decide la emergencia).
        if ($row->kind === 'send' && $row->send_before !== null) {
            $next = $next->min(Carbon::parse($row->send_before));
        }

        $this->outbox->table()->where('id', $row->id)->where('status', Outbox::SENDING)->update([
            'status' => Outbox::PENDING,
            'next_attempt_at' => $next,
            'last_error' => mb_substr($error, 0, 255),
            'updated_at' => now(),
        ]);

        return 'retried';
    }

    /**
     * Cierra una fila tomada (sending) como failed o expired. Para un `send` es la emergencia: antes,
     * si Smartmailto responde, se consulta el envio; si ya salio, esta en cola o se descarto a proposito
     * (supresion, regla de repeticion), queda acked y no se dispara nada.
     *
     * @return 'acked'|'closed'
     */
    private function close(object $row, string $status, string $reason, ?SmartmailtoException $e = null): string
    {
        if (! isset($row->message_meta)) {
            try {
                $decoded = $this->outbox->decode($row);
                $row->message_meta = MessageEvents::extract($decoded);
            } catch (Throwable) {
                $row->message_meta = [];
            }
        }
        $error = $e !== null ? self::describe($e) : $reason;

        if ($row->kind === 'send' && ($state = $this->consult($row->key)) !== null && in_array($state, self::NO_EMERGENCY, true)) {
            $this->ack($row, ['status' => $state]);
            MessageEvents::sent($row->message_meta ?? [], ['status' => $state]);

            return 'acked';
        }

        $changed = $this->outbox->table()->where('id', $row->id)->where('status', Outbox::SENDING)->update([
            'status' => $status,
            'reason' => $reason,
            'last_error' => mb_substr($error, 0, 255),
            'updated_at' => now(),
        ]);
        if ($changed === 0) {
            return 'closed';
        }
        MessageEvents::failed($row->message_meta ?? [], $e?->status, $error, $reason === 'expired' ? 'expired' : (ctype_digit($reason) ? 'rejected' : 'failed'), $e?->response ?? []);

        if ($status === Outbox::FAILED && $reason !== 'gave_up') {
            $this->alerts->rejected($row->template ?? $row->kind, $row->kind, $row->key, $error);
        }

        // Despues del UPDATE: un listener en cola ve el estado ya cerrado. Un listener sincrono que truena
        // no corta la pasada (las demas filas y las alertas siguen); queda en el log con la llave.
        try {
            $this->app->make('events')->dispatch(new SmartmailtoOutboxFailed(
                outboxId: (int) $row->id,
                kind: $row->kind,
                key: $row->key,
                reason: $reason,
                template: $row->template,
                status: $e?->status,
                error: $e?->error(),
            ));
        } catch (Throwable $listenerError) {
            $this->app->make('log')->error("Smartmailto outbox: SmartmailtoOutboxFailed listener failed for {$row->kind} {$row->key} ({$reason}): ".class_basename($listenerError).'. Use a queued listener (ShouldQueue) so the emergency is retried.');
        }

        return 'closed';
    }

    /**
     * GET /api/send/{key} (regla 17.3). null = Smartmailto no respondio (la emergencia procede).
     */
    private function consult(string $key): ?string
    {
        try {
            $response = $this->client()->get('send/'.implode('/', array_map(rawurlencode(...), explode('/', $key))));

            return is_string($response['status'] ?? null) ? $response['status'] : null;
        } catch (SmartmailtoException $e) {
            return $e->status === 404 ? 'not_found' : null;
        } catch (Throwable) {
            return null;
        }
    }

    /** @param  array<string, mixed>  $response */
    private function ack(object $row, array $response): void
    {
        $this->outbox->table()->where('id', $row->id)->where('status', Outbox::SENDING)->update([
            'status' => Outbox::ACKED,
            'acked_at' => now(),
            'ack' => json_encode(array_intersect_key($response, array_flip(['id', 'duplicate', 'received_at', 'status']))),
            'last_error' => null,
            'updated_at' => now(),
        ]);
    }

    /** Un fromDisk se firma en cada intento: la URL de un intento viejo ya pudo caducar. */
    private function resolveAttachments(array $body): array
    {
        if (isset($body['attachments'])) {
            $body['attachments'] = array_map(fn (array $stored) => Attachment::fromOutbox($stored)?->toArray() ?? $stored, $body['attachments']);
        }

        return $body;
    }

    /** Un worker que murio a media peticion deja la fila `sending`: vuelve a `pending` y se reintenta. */
    private function reclaimStale(): void
    {
        $this->outbox->table()->where('status', Outbox::SENDING)
            ->where('updated_at', '<', now()->subMinutes((int) $this->outbox->config('sending_timeout', 10)))
            ->update(['status' => Outbox::PENDING, 'next_attempt_at' => now(), 'last_error' => 'stale sending', 'updated_at' => now()]);
    }

    /** Regla 25: POST /api/outbox/heartbeat cada `heartbeat_every` minutos (Smartmailto alerta a los 30 min sin aviso). */
    private function heartbeat(): void
    {
        $every = (int) $this->outbox->config('heartbeat_every', 5);
        $state = $this->outbox->alertTable()->where('type', 'heartbeat')->first();
        if ($state?->last_alert_at !== null && Carbon::parse($state->last_alert_at)->gt(now()->subMinutes($every))) {
            return;
        }

        try {
            $this->client()->post('outbox/heartbeat', []);
        } catch (Throwable) {
            return;
        }

        $this->outbox->saveAlertState('heartbeat', ['last_alert_at' => now()]);
    }

    private function client(): SmartmailtoClient
    {
        return $this->app->make(SmartmailtoClient::class);
    }

    /** Estado HTTP y codigo de error del contrato; nunca el cuerpo (puede traer datos). */
    private static function describe(SmartmailtoException $e): string
    {
        return $e->status !== null && $e->status > 0
            ? trim($e->status.' '.($e->error() ?? ($e->transient ? 'transient' : 'rejected')))
            : ($e->transient ? 'unreachable' : 'not configured');
    }
}
