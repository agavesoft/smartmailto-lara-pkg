# Smartmailto — SDK para Laravel

`agavesoft/smartmailto` conecta una aplicacion Laravel (12 o 13) con Smartmailto: identifica personas (con o sin cuenta), registra eventos idempotentes, carga el historico y manda correos transaccionales.

> Antes `agavesoft/mailflow` (v1). La v2 cambia la API publica; ver [Migrar desde v1](#migrar-desde-v1).

## Instalacion

```bash
composer require agavesoft/smartmailto
php artisan vendor:publish --tag=smartmailto-config   # opcional
```

Version recomendada: `"agavesoft/smartmailto": "^2.5"` (tag estable), con un repositorio `vcs` a `https://github.com/agavesoft/smartmailto-lara-pkg` mientras el paquete no este en Packagist.
`^2.5@dev` (develop como `2.5.x-dev`) solo es para probar lo que aun no se publica.

```dotenv
SMARTMAILTO_API_URL=https://smartmailto-dev.agv.dev   # sin /api: el SDK lo agrega
SMARTMAILTO_API_TOKEN=mf_dev_...         # API key del proyecto (Conectar tu app; se muestra una sola vez)
SMARTMAILTO_ENABLED=true                 # false = el SDK no hace nada
# SMARTMAILTO_QUEUE=true                 # default: encola tras el commit y reintenta
# SMARTMAILTO_QUEUE_CONNECTION=
# SMARTMAILTO_QUEUE_NAME=
# SMARTMAILTO_WEBHOOK_SECRET=whsec_...   # webhook de falla (F-008)
# SMARTMAILTO_ATTACHMENTS_MAX_FILES=10
# SMARTMAILTO_ATTACHMENTS_MAX_BYTES=7340032
# SMARTMAILTO_PROVISION_TIMEOUT=120      # espera del aprovisionamiento y el catalogo (F-009)
# SMARTMAILTO_PULL_ENABLED=false         # pull: Smartmailto le pide datos a tu app (F-011)
# SMARTMAILTO_PULL_SECRET=pullsec_...
# SMARTMAILTO_PULL_RESOLVER=App\Smartmailto\MiPullResolver
# SMARTMAILTO_PULL_HISTORY_MONTHS=       # vacio = toda la historia de eventos
# SMARTMAILTO_OUTBOX_ENABLED=false       # outbox transaccional: eventos garantizados (F-010)
# SMARTMAILTO_OUTBOX_CONNECTION=         # conexion de BD de tus transacciones (vacio = la default)
# SMARTMAILTO_ALERTS_MAIL_TO=soporte@agavesoft.com.mx
# SMARTMAILTO_ALERTS_TEAMS_WEBHOOK_URL=  # webhook de un flujo de Workflows de Power Automate
```

Por default **cada llamada se encola despues del commit** de la transaccion en curso: necesitas un worker de cola corriendo (`php artisan queue:work`). Con la cola `sync` la llamada se hace una sola vez al terminar el commit, sin reintentos; una falla dispara `SmartmailtoDeliveryFailed`. Para reintentos reales usa una cola de verdad (`database`, `redis`). Con `SMARTMAILTO_QUEUE=false` las llamadas son sincronas y lanzan `SmartmailtoException` si fallan.

## Que se configura en Smartmailto y que en tu app

Hay dos lados. **En Smartmailto** (panel → *Configuracion del proyecto*) se crean la API key y los secretos, y se configuran el remitente, las reglas de envio y las alertas del proyecto. **En tu app** van las variables de abajo, con estos nombres exactos. La pestaña *Conectar tu app* (`#conectar`) del panel trae la guia paso a paso con los mismos snippets.

| Variable en tu app | De donde sale en Smartmailto | ¿Obligatoria? |
|---|---|---|
| `SMARTMAILTO_API_URL` | La URL del panel del ambiente, **sin `/api`** (el SDK lo agrega) | Si |
| `SMARTMAILTO_API_TOKEN` | *Conectar tu app* → **Crear API key**. Se muestra completa una sola vez; si la pierdes, crea otra y revoca la vieja | Si |
| `SMARTMAILTO_WEBHOOK_SECRET` | *Avisos y alertas* → webhook de falla: guardas la URL de tu app y se muestra el secreto `whsec_...` una sola vez ([Webhook de falla](#webhook-de-falla-f-008)) | Recomendado si mandas correos que no pueden perderse |
| `SMARTMAILTO_PULL_SECRET` | *Avanzado* → *Sincronizacion desde tu app (pull)*: se muestra el secreto `pullsec_...` una sola vez ([Pull](#pull-smartmailto-le-pide-datos-a-tu-app-f-011)) | Solo con pull |

Lo demas vive solo en Smartmailto y tu app no hace nada: remitente y su politica de `from`, Reply-To, tracking, horario, exclusiones y retencion. Las alertas del **outbox** son la excepcion: las manda tu app y se configuran en ella (`SMARTMAILTO_ALERTS_MAIL_TO`, `SMARTMAILTO_ALERTS_TEAMS_WEBHOOK_URL`), aparte de las alertas del proyecto que se configuran en el panel.

**Ambientes:**

| Ambiente | `SMARTMAILTO_API_URL` | Prefijo de las API keys nuevas |
|---|---|---|
| DEV | `https://smartmailto-dev.agv.dev` | `mf_dev_` |
| Produccion | `https://smartmailto.com` (aun no desplegado) | `mf_live_` |

El prefijo `mf_dev_` (DEV y local) o `mf_live_` (produccion) solo distingue a simple vista de que ambiente es una key: ni Smartmailto ni el SDK lo validan. Las keys que ya existen (incluidas las `mf_live_...` generadas en DEV) siguen sirviendo sin cambios. Una key solo funciona contra el ambiente donde se creo.

## Uso

```php
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;

// Persona sin cuenta (compra como invitado): se identifica por correo.
Smartmailto::track('orden_creada', Identity::guest($order->email), [
    'timbres' => $order->stamps, 'total' => $order->total, 'paquete' => $order->package_name,
], eventId: "ff:orden_creada:{$order->id}", object: ['order', $order->id],
   secrets: ['checkout_url' => $checkoutUrl]);

// Persona con cuenta. Mandar su correo une su historial de invitado con la cuenta.
// updatedAt (opcional, F-011): hora del cambio en tu app; si push y pull traen el mismo atributo, gana el mas reciente.
Smartmailto::identify(Identity::user($user->id, $user->email), ['name' => $user->name], updatedAt: $user->updated_at);

// Correo transaccional inmediato (idempotente).
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), [
    'total' => $order->total,
], idempotencyKey: "ff:recibo:{$order->id}");
```

### Reglas del contrato

1. **`eventId` sale del hecho de negocio**, nunca de un uuid nuevo: `"{app}:{evento}:{id del objeto}"`. Asi un reintento (tuyo o del SDK) no duplica el evento: Smartmailto descarta el repetido.
2. **`idempotencyKey` en cada `send`**: el mismo valor nunca manda dos correos.
3. **`secrets`** son valores de un solo uso (ligas de activacion o de pago). Smartmailto los guarda cifrados, no los muestra en su panel ni en logs, y solo las plantillas los leen (`{{secret:activation_url}}`). Esas ligas no pasan por el seguimiento de clics.
4. **Datos fiscales y sensibles segun el canal** (RFC, UUID/folio fiscal, contenido de CFDI, montos fiscales). La regla depende de si Smartmailto guarda el dato o solo lo entrega:

   | Canal | Datos fiscales o sensibles | Por que |
   |---|---|---|
   | Propiedades de eventos (`track`) | **Prohibidos** | Se guardan 13 meses, se ven en la linea de tiempo y alimentan workflows |
   | Atributos del contacto (`identify`) | **Prohibidos** | Se guardan, se muestran y alimentan condiciones |
   | Datos de plantilla (`send` → `data`) | **Prohibidos** | Se conservan para recrear el correo |
   | `secrets` (`send` / `track`) | **Permitidos** | Cifrados, se ven como `[secreto]` y se borran al estado final. Ahi va, por ejemplo, el folio fiscal que imprime la plantilla |
   | Adjuntos de envios **transaccionales** | **Permitidos** | Smartmailto no los conserva despues de la entrega, salvo que el proyecto retenga adjuntos con la copia del correo |
   | Nombre del archivo adjunto | **Permitido** | Puede llevar RFC o UUID: Smartmailto lo enmascara en logs y en "recrear correo" (`***_****.xml #a1b2c3`) |

   Una variable marcada `sensitive` en el catalogo (F-009) no se puede usar en condiciones ni se muestra en claro. Manda solo lo que el correo necesita.
5. **`object`** (`['order', 100]`) identifica el objeto de negocio; Smartmailto lo usa para llevar una secuencia por orden.
6. **Unir invitado y cuenta**: cuando la persona reclama su orden o se registra, manda un evento o `identify` con `Identity::user($id, $correoDeLaOrden)`. Si tu app borra el correo de la orden al reclamarla, leelo antes.

### Correos esenciales y emergencia

Para registro y recibo de compra (plantillas transaccionales en Smartmailto):

```php
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), $data,
    idempotencyKey: "ff:recibo:{$order->id}", sendBefore: now()->addMinutes(10));
```

**Todo correo sale por Smartmailto; tu envio directo (SES) es solo emergencia y se reporta.** Con el outbox (F-010, ver abajo) la emergencia ocurre **solo** en estos casos, y nunca por un fallo de red ni por `health()`:

| Disparador | Cuando |
|---|---|
| Evento `SmartmailtoOutboxFailed` con `needsEmergencySend()` | La fila `send` recibio un rechazo definitivo (`reason` = `"422"`, `"404"`...) o llego a su `sendBefore` sin acuse (`reason` = `"expired"`). Antes de disparar, el SDK pregunta a Smartmailto (`GET /api/send/{key}`): si el envio ya salio, esta en cola o se descarto a proposito (supresion, regla de repeticion), no hay emergencia |
| Webhook de falla `send_expired` | Smartmailto lo habia aceptado pero no lo mando antes de `sendBefore` (su cola se detuvo) |

En los dos casos tu app manda el correo por su canal directo, lo registra (FF: `correos_salientes`) y lo **reporta**:

El listener **debe ir en cola** (`implements ShouldQueue`): el evento se dispara una sola vez, y si un listener sincrono falla (SES caido, bug) el worker solo lo registra en el log y esa emergencia se pierde. En cola, la cola lo reintenta.

```php
// app/Listeners/SmartmailtoEmergencia.php
class SmartmailtoEmergencia implements ShouldQueue
{
    public function handle(SmartmailtoOutboxFailed $event): void
    {
        if (! $event->needsEmergencySend()) {
            return;   // track/identify/link: no hay correo que mandar; soporte revisa con el runbook
        }

        // Idempotente por la llave: si la cola reintenta el listener, el correo no sale dos veces.
        $salida = CorreoSaliente::firstWhere('idempotency_key', $event->key)
            ?? CorreoSaliente::mandarPorSes($event->key);   // tu app sabe armar el correo de esa llave

        // Smartmailto lo registra como "enviado por emergencia desde el proyecto" y ya no lo manda aunque
        // le llegue despues. Si la fila esta en vuelo lanza OutboxRowInFlight: deja que la cola reintente.
        Smartmailto::reportExternalSend($event->key, sentAt: $salida->sent_at, reason: $event->reason);
    }
}
```

- Con el outbox, `reportExternalSend()` toma la identidad y la plantilla de la fila `send` (o pasalas: `reportExternalSend($key, $identity, 'recibo')`), cierra esa fila como `superseded` y deja el reporte en el outbox con la misma garantia de entrega. Sin outbox lo encola como cualquier llamada (identidad y plantilla obligatorias).
- **Por que no hay duplicados:** Smartmailto revisa `send_before` justo antes de entregar al proveedor, y una llave reportada se acusa sin enviar (`duplicate: true`) en cualquier reintento posterior.
- **Riesgo residual aceptado:** si Smartmailto entrego antes de `sendBefore`, el acuse se perdio y Smartmailto no responde la consulta, sale una segunda copia; al llegar tu reporte queda como `duplicate_external` (metrica, sin alerta critica).
- **Costo aceptado:** con Smartmailto caido, el recibo sale por emergencia al vencer `sendBefore` (10 min en los esenciales de FF).
- Sin outbox sigue el contrato de v2.1: 410 → `SmartmailtoDeliveryFailed`, y el webhook `send_expired`; reporta igual con `reportExternalSend()`.
- `Smartmailto::health()` (`ok`, `degraded`, `down`) sirve para tableros; ya no es motivo para mandar directo.

### Correo sin plantilla y mailer de Laravel (Fase 0)

`sendRaw()` manda un correo que arma tu app (sin plantilla en Smartmailto). Es siempre transaccional; Smartmailto aplica lista blanca, remitente (o el Dummy en DEV), idempotencia, CC/BCC/Reply-To y adjuntos, y lo guarda para consultarlo.

```php
Smartmailto::sendRaw(
    Identity::external('ana@example.com'),
    subject: 'Tu aviso',
    html: '<p>Hola</p>',
    text: 'Hola',                      // opcional: sin text, Smartmailto lo genera del html
    idempotencyKey: "nb:aviso:{$id}",
    cc: ['c@example.com'],
);
```

#### `MAIL_MAILER=smartmailto`

Con el mailer, **todos** los correos de la app (Mailables, Notifications, `Mail::raw`) salen por Smartmailto sin cambiar codigo:

```php
// config/mail.php
'mailers' => [
    // ...
    'smartmailto' => [
        'transport' => 'smartmailto',
        // 'send_from' => true,          // false: no manda el From; usa el remitente del sender en Smartmailto
        // 'send_before_minutes' => 60,  // solo con el outbox: vigencia del envio
    ],
],
```

```dotenv
MAIL_MAILER=smartmailto
MAIL_FROM_ADDRESS=no-reply@tu-dominio.com
MAIL_FROM_NAME="${APP_NAME}"
SMARTMAILTO_API_URL=https://smartmailto-dev.agv.dev
SMARTMAILTO_API_TOKEN=            # llave del proyecto (desde tu gestor de secretos)
SMARTMAILTO_QUEUE=false           # recomendado con el mailer: un rechazo lanza TransportException y Laravel lo ve
```

Como se convierte el mensaje:

| Correo de Laravel | `/api/send` |
|---|---|
| Primer `To` | destinatario (`Identity::external`: no se vuelve contacto) |
| Demas `To`, `Cc`, `Bcc` | `to`, `cc`, `bcc` (max 10 por lista) |
| `Reply-To` (el primero), `From` | `reply_to`, `from` |
| Asunto, HTML, texto | `subject`, `html`, `text` |
| Adjuntos | base64 inline (tipos de `Attachment::TYPES`; otro tipo lanza `InvalidArgumentException`) |
| `Message-ID` | `idempotency_key` = `mail:<message-id>` |

- Con `SMARTMAILTO_QUEUE=true` (default del SDK) el mailer regresa de inmediato y la entrega va en la cola; una falla dispara `SmartmailtoDeliveryFailed` en vez de una excepcion.
- Con `SMARTMAILTO_ENABLED=false` el correo se descarta en silencio.
- Las imagenes embebidas (`cid:`) viajan como adjuntos normales.
- En pruebas: `Smartmailto::fake()` y `assertSentRaw('Asunto', fn ($body) => ...)` / `assertNothingSentRaw()`.

### Outbox: eventos garantizados (F-010)

Con el outbox, `track`, `send`, `identify`, `link` y `reportExternalSend` **no encolan un job**: escriben una fila en la tabla `smartmailto_outbox` **dentro de la transaccion de tu app**. Si la accion de negocio hace rollback no queda nada; si hace commit, la fila espera hasta que Smartmailto **acusa** recibo con su llave. Requiere un servidor con F-010.

```bash
php artisan vendor:publish --tag=smartmailto-migrations
php artisan migrate
```

```dotenv
SMARTMAILTO_OUTBOX_ENABLED=true
SMARTMAILTO_OUTBOX_CONNECTION=           # la conexion de tus transacciones (vacio = la default)
SMARTMAILTO_ALERTS_MAIL_TO=soporte@agavesoft.com.mx
SMARTMAILTO_ALERTS_TEAMS_WEBHOOK_URL=https://...   # webhook de un flujo de Workflows de Power Automate
```

```php
// routes/console.php
Schedule::command('smartmailto:outbox:work --max-time=55')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('smartmailto:outbox:prune')->daily();

// o como daemon (supervisor): php artisan smartmailto:outbox:work
```

```php
DB::transaction(function () use ($order) {
    $order->markPaid();

    // La fila queda en esta transaccion: sin commit no hay evento ni correo.
    Smartmailto::track('orden_pagada', Identity::user($order->user_id, $order->email), ['total' => $order->total],
        eventId: "ff:orden_pagada:{$order->id}", occurredAt: $order->paid_at);
    Smartmailto::send('recibo-compra', Identity::user($order->user_id, $order->email), $data,
        idempotencyKey: "ff:recibo:{$order->id}", sendBefore: now()->addMinutes(10));   // obligatorio con el outbox
});
```

**Que hace el worker** (`smartmailto:outbox:work`, una pasada con `--once`):

| Situacion | Resultado |
|---|---|
| Acuse con la llave (`event_id`, `idempotency_key`, `link_id`; `identify` con cualquier 2xx) | `acked` |
| Red, 5xx, 429, 401/403 (token rotado), SDK sin configurar, respuesta sin acuse | Reintento: 30 s, 2 min, 10 min, 1 h y despues cada hora |
| 72 h sin acuse | `failed` (`reason=gave_up`) y `SmartmailtoOutboxFailed`; se reprocesa con `smartmailto:outbox:retry` |
| `send` que llega a `sendBefore` sin acuse, o 410 | `expired` + emergencia (despues de la consulta previa) |
| Rechazo definitivo (422, 404 de plantilla, 413) | `failed` + alerta inmediata agrupada por plantilla + `SmartmailtoOutboxFailed` |
| `link` con 404 (el contacto aun no llega) o 409 `contact_changed`; reporte con 409 | Reintento |

- **Idempotencia:** la misma llave pendiente no se duplica en la tabla y Smartmailto descarta los repetidos. `identify` lleva una llave por llamada y manda `updated_at` (la hora en que lo llamaste) para que gane el mas reciente aunque lleguen fuera de orden.
- **Hora real:** un `track` sin `occurredAt` guarda la hora del commit; los workflows cuentan desde ahi aunque el evento llegue horas despues.
- **Aviso de vida:** cada 5 min el worker hace `POST /api/outbox/heartbeat`. Smartmailto alerta (con su propio canal) si el worker pasa 30 min sin avisar.
- **Cifrado:** el payload de cada fila va cifrado con `APP_KEY`. Rotar la llave con filas pendientes las deja sin poder leerse (se reintentan y alertan por antiguedad): vacia el outbox antes de rotarla.
- `smartmailto:outbox:status` (conteos, la mas vieja, alertas abiertas), `smartmailto:outbox:retry {id*} --key= --failed` (nunca un `send`: al fallar ya paso a tu emergencia), `smartmailto:outbox:prune` (acked 7 dias; failed, expired y superseded 30 dias) y `smartmailto:outbox:prune --contact=correo|user_id` (ARCO: borra las filas de esa persona).
- `batch()` (carga inicial) no usa el outbox: sigue por la cola; un lote se puede repetir sin riesgo.

**Alertas (las emite tu app, agrupadas, nunca una por fila):**

- Filas sin acuse: **una** alerta a los 15 min ("N pendientes, la mas vieja de hace X, ultimo error"), recordatorios a 1 h, 4 h, 12 h, 24 h y 48 h con conteos actualizados, **un solo resumen** a las 72 h de las filas que se rindieron y **"recuperado"** cuando ya no queda nada atrasado.
- Rechazos definitivos: alerta inmediata agrupada por plantilla; los siguientes de la misma plantilla se juntan en ventanas de 15 min.
- Canales: correo (`alerts.mail_to`, con el mailer de tu app) y Teams (`alerts.teams_webhook_url`, tarjeta adaptable). Siempre quedan tambien en el log. Un escalon que no sale por ningun canal se reintenta en la siguiente pasada.
- Sin datos personales: conteos, edades, llaves y codigos de error.

**Adjuntos con el outbox (J12):** hasta **1 MB** por archivo van dentro del mensaje (cifrados en la fila). Uno mas grande va por URL firmada que Smartmailto descarga al aceptar el envio:

```php
Attachment::fromDisk('s3', "cfdi/{$cfdi->uuid}.pdf", "{$cfdi->rfc}_{$cfdi->folio}.pdf");   // URL firmada nueva en cada intento (24 h)
Attachment::fromUrl($urlHttps, 'reporte.pdf', $sha256);                                       // URL que controlas tu
```

El SDK calcula el `sha256` al llamar `send()` y Smartmailto lo verifica. El archivo debe seguir en el disco hasta el acuse. Tope por archivo: 15 MB (`attachments.url_max_bytes`). `fromDisk`/`fromUrl` tambien funcionan sin outbox.

### Destinatarios externos, ligar contactos y consentimiento (F-010)

```php
// Receptor de un CFDI: no se vuelve contacto (solo plantillas transaccionales). `origin` liga el envio
// a quien lo emitio, para verlo en Envios.
Smartmailto::send('cfdi-documentos', Identity::external($receptor->email), $data,
    idempotencyKey: "ff:cfdi-documentos:{$cfdi->id}:{$envio->id}", sendBefore: now()->addDay(),
    origin: Identity::user($user->id));

// La compra de un invitado era de una cuenta (timbres en otra cuenta): ligalos.
Smartmailto::link(Identity::user($user->id), Identity::guest($order->email), 'timbres-en-otra-cuenta',
    linkId: "ff:link:order:{$order->id}");

// La persona volvio a aceptar correos (casilla explicita): reactiva a un contacto borrado por ARCO.
Smartmailto::identify(Identity::user($user->id, $user->email), [], consent: true);
```

- `Identity::external()` solo vale en `send()` y `reportExternalSend()`; en `track`, `identify` o `link` lanza `InvalidArgumentException`.
- `link()` nunca crea contactos: los dos deben existir (`404 contact_not_found`). Dos cuentas con `user_id` no se ligan (`409 both_have_user_id`). Es idempotente por `linkId`.

### Adjuntos, copias y remitente (F-008)

Todo es opcional y con nombre: un `send()` sin estos parametros manda exactamente lo mismo que antes.

```php
use Agavesoft\Smartmailto\Attachment;

// CFDI por correo: PDF + XML, varios destinatarios, copia oculta y Reply-To de soporte.
// Regla 4: el RFC en el NOMBRE del adjunto y el XML como adjunto estan permitidos (transaccional, no se
// conservan); el folio fiscal va en `secrets`, nunca en `data`.
Smartmailto::send('cfdi-por-correo', Identity::user($user->id, $user->email), [
    'folio' => $cfdi->folio,
], idempotencyKey: "ff:cfdi-correo:{$cfdi->id}:{$request->id}",
    secrets: ['folio_fiscal' => $cfdi->uuid],
    attachments: [
        Attachment::fromPath(storage_path("cfdi/{$cfdi->uuid}.pdf"), "{$cfdi->rfc}_{$cfdi->folio}.pdf"),
        Attachment::fromData($cfdi->xml, "{$cfdi->rfc}_{$cfdi->folio}.xml"),   // contenido crudo, no base64
    ],
    to: ['contador@example.com'],                 // destinatarios ADICIONALES (el contacto siempre va)
    cc: ['administracion@example.com'],
    bcc: ['auditoria@facturafacilita.com'],
    replyTo: ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
);

// Recibo de compra con el PDF y otro remitente que la politica del sender permite.
Smartmailto::send('recibo-compra', Identity::user($user->id, $user->email), $data,
    idempotencyKey: "ff:recibo:{$order->id}", sendBefore: now()->addMinutes(10),
    attachments: [Attachment::fromData($pdf, 'Comprobante.pdf')],
    from: ['email' => 'soporte@facturafacilita.com', 'name' => 'Factura Facilita'],
);

// Restablecer contrasena: la liga de un solo uso viaja en secrets ({{secret:reset_url}}), no en data.
Smartmailto::send('restablecer-contrasena', Identity::user($user->id, $user->email), [],
    idempotencyKey: "ff:reset:{$token->id}", secrets: ['reset_url' => $url]);
```

- **Adjuntos:** `Attachment::fromPath($ruta, $nombre?)`, `Attachment::fromData($bytes, $nombre)` o `Attachment::fromUpload($request->file('x'))`; tambien se acepta directo un `UploadedFile` o una ruta. El SDK los codifica en base64. El tipo sale de la extension (`pdf`, `xml`, `zip`, `png`, `jpg`/`jpeg`, `csv`, `txt`) o del `contentType` que pases si corresponde a ella. Nombre simple, max 150, sin rutas.
- **Limites:** 10 archivos y **7 MB decodificados en total** por envio. El SDK los valida **antes de encolar** (lanza `InvalidArgumentException`); si el servidor tiene otros, ajusta `SMARTMAILTO_ATTACHMENTS_MAX_FILES` / `SMARTMAILTO_ATTACHMENTS_MAX_BYTES`. El servidor ademas revisa la firma del archivo (un `.pdf` debe ser PDF).
- **Adjuntos y cola:** el contenido se lee al llamar `send()` y el job lleva el base64 (~1.37x el tamano de los archivos): no depende de que el archivo siga en disco cuando corre el worker. Consecuencias: (1) el payload del job puede pesar ~10 MB y queda **sin cifrar** en tu almacen de cola (`jobs` de la cola `database`, Redis); (2) **SQS no sirve** para envios con adjuntos (limite de 256 KB por mensaje): usa `database`/`redis` o `SMARTMAILTO_QUEUE=false` para esos envios. `Smartmailto::fake()` tambien guarda el base64.
- **`to`, `cc`, `bcc`:** max 10 por lista; solo con plantillas `kind: transactional` (si no: 422 `recipients_require_transactional`). No crean contactos y la lista de supresion del proyecto les aplica.
- **`replyTo` / `from`:** un correo o `['email' => ..., 'name' => ...]`. El `from` lo autoriza la politica del remitente (sender) con el que sale el envio (F-014): `fijo`, `lista` de direcciones permitidas o `dominio`; fuera de ella, 422 `from_not_allowed`. Si el remitente no permite copias o Reply-To: 422 `cc_not_allowed`, `bcc_not_allowed` o `reply_to_not_allowed`. Sin `from`, sale con el del remitente.
- Contrato completo: `docs/api-envio-y-aprovisionamiento.md` del servidor (`smartmailto-lara-manager`).

### Plantillas como codigo (aprovisionamiento, F-008 y F-009)

Un admin debe habilitar *Aprovisionamiento por API* en el proyecto (apagado: 403 `provisioning_disabled`). Todo es idempotente: lo que no cambio responde `unchanged` y no crea version.

> **v2.3 requiere un servidor con F-009** (`POST /api/provision`): contra un servidor anterior `smartmailto:provision` falla con 404 y no cambia nada. Los metodos `put*()` sueltos siguen funcionando contra ambos.

```text
resources/smartmailto/
├── variables/contact.yaml              # F-009: catalogo de variables (o .yml / .json)
├── variables/eventos.yaml
├── partials/pie.html                   # {{> pie}}; frontmatter opcional: description
├── templates/recibo-compra.md          # frontmatter: subject (requerido), kind, layout, display_name, description
└── workflows/checkout.yaml             # o .json
```

```markdown
---
subject: Tu recibo {{data:folio}}
kind: transactional
layout: default
display_name: Recibo de compra
---
<p>Hola {{contact:name}}</p>
{{> pie}}
```

```bash
php artisan smartmailto:provision resources/smartmailto --dry-run    # lee los archivos, no llama
php artisan smartmailto:provision resources/smartmailto --validate   # valida contra Smartmailto sin guardar (CI)
php artisan smartmailto:provision resources/smartmailto              # un solo paquete; lo nuevo queda en borrador
php artisan smartmailto:provision resources/smartmailto --activate   # ademas activa plantillas y workflows
```

- **Todo o nada (F-009, RN-16):** el comando arma **un solo paquete** (variables → bloques → plantillas → workflows → activaciones) y lo manda a `POST /api/provision`. Si cualquier pieza falla, Smartmailto no cambia nada, lo que estaba activo **sigue enviando** con su version anterior, y el comando imprime **todos** los items fallidos y sale con codigo 1. Correrlo en cada deploy es seguro. Si no hubo respuesta (timeout, `SMARTMAILTO_PROVISION_TIMEOUT`, default 120 s), el paquete pudo aplicarse: vuelve a correrlo, porque es idempotente.
- **Borrador y activacion (RN-4):** sin `--activate`, lo nuevo queda `draft` y un workflow cuya definicion cambia queda inactivo. Si estaba activo, deja de inscribir contactos y el comando lo advierte. Un workflow pausado por quejas solo se reactiva en el panel. Con `--activate` se activan en la misma operacion, con las mismas reglas que el panel: cero referencias a variables inexistentes, y ninguna referencia **nueva** a una obsoleta. Un workflow revisa tambien todas sus plantillas, que deben venir activas o activarse en el mismo paquete.
- **Avisos (`warnings`):** se imprimen (`aviso template recibo: unknown_variable contact:nombre en body`) y no hacen fallar. Codigos: `unknown_variable`, `obsolete_variable`, `secret_in_subject`, `unknown_event`, `missing_group_operator`, `sensitive_kept` y `fiscal_key`.
- **`--validate`** corre el mismo paquete en `POST /api/validate` sin guardar nada. Sale con codigo 1 si no es valido. Pide el token y el aprovisionamiento habilitado, igual que el deploy. Con `--activate` valida tambien la activacion. Reemplaza cualquier copia local del parser de plantillas: la unica regla es la del servidor (RN-13).
- El cuerpo va tal cual (metalenguaje de Smartmailto; **no se convierte Markdown**: `.md` solo es el formato del archivo). Frontmatter plano `llave: valor`. El nombre es el del archivo (`^[a-z0-9][a-z0-9_-]*$`).
- Los bloques se mandan en orden alfabetico de archivo, y el servidor los aplica en ese orden: un bloque que incluye a otro debe nombrarse despues que el incluido.
- Sin `layout` en el frontmatter el servidor conserva el actual; sin `kind`, una plantilla nueva nace `marketing`.

#### Catalogo de variables (F-009)

Cada archivo de `variables/` es una **lista** de variables. El catalogo dice que datos existen. Smartmailto valida contra el las plantillas y los workflows: una referencia a una variable que no existe se guarda con aviso, pero no se puede activar.

```yaml
# variables/contact.yaml: atributos del contacto (los que mandas con identify)
- scope: contact
  key: plan
  type: enum
  allowed_values: [free, pro]
  description: Plan contratado        # obligatoria: es la documentacion que leen personas y agentes
  filterable: true                    # se puede buscar en Contactos (nunca si es sensible)
- scope: contact
  key: telefono_movil
  type: string
  description: Movil del contacto
  sensitive: true                     # solo un admin lo ve; por API solo se enciende
- scope: contact
  key: ciudad
  type: string
  description: Ciudad
  default: Mexico                     # respaldo si falta el dato y la plantilla no trae |default

# variables/eventos.yaml: datos de evento ({{data:x}} y properties.x) y secretos ({{secret:x}})
- scope: event
  key: logo_url                       # sin `event`: comun (datos de send())
  type: url
  description: Logo de la app
- scope: event
  key: orden
  event: orden_creada                 # dato del evento orden_creada
  type: string
  description: Folio de la orden
  required: true                      # si el track no lo trae: 422 missing_event_data
- scope: secret
  key: checkout_url
  event: orden_creada
  type: url
  description: Liga de pago de un solo uso
```

| Campo | Regla |
|---|---|
| `scope` | `contact` (atributo del contacto, persistente), `event` (dato por envio) o `secret` (dato de un solo uso; siempre sensible, nunca en el asunto) |
| `key` | Inmutable, `^[a-z][a-z0-9_]{0,63}$`. Unica por seccion y evento. `contact:email` y `contact:external_id` son reservadas |
| `event` | Solo en `event`/`secret`: el evento al que pertenece. Sin `event` es comun (`send()`) |
| `type` | `string`, `integer`, `decimal`, `boolean`, `date`, `datetime`, `url`, `email` o `enum` (con `allowed_values`). No cambia mientras tenga usos (`type_locked`) |
| `allowed_values` | Se pueden agregar siempre; quitar uno contra el que compara una condicion: 409 `allowed_value_in_use` |
| `required` | Solo `event`/`secret`: se valida al recibir (422). En `contact` no existe (`required_not_allowed`): la obligatoriedad es **de cada plantilla** |
| `default` | Respaldo del catalogo. Orden: valor del contacto o evento → `|default` de la plantilla → `default` del catalogo |
| `filterable` | Solo `contact` y no sensible (`filterable_sensitive`) |
| `sensitive` | Solo `contact` (un dato de evento sensible va como `secret`). Por API, SDK o `provision` **solo se enciende**: un `sensitive: false` sobre una sensible la conserva con el aviso `sensitive_kept` y no falla el deploy. Apagarla es exclusivo de un admin en el panel |
| `label` | Etiqueta visible, editable (default: la clave) |

Reglas que conviene saber al escribir plantillas:

- **Una variable que la plantilla imprime sin `|default` es obligatoria para esa plantilla.** Si falta en un workflow, el envio queda en `datos incompletos` y se reintenta hasta 24 h; un `data:`/`secret:` faltante omite el paso de inmediato. En `send()` falla al momento con 422 `missing_variables`. Dentro de `{{#if contact:x}}` no es obligatoria.
- Sin datos fiscales (RFC, folio fiscal, regimen, CFDI) en `contact` ni `event`: el folio fiscal va como `secret`. Una clave que lo parece trae el aviso `fiscal_key`.
- Tope de 100 variables `contact` activas (`catalog_full`). Una variable en uso no se borra (409 `in_use` con la lista de usos): se marca **obsoleta**. Lo que ya la usa sigue enviando, pero nada nuevo se activa con ella.
- **YAML:** escribe las fechas entre comillas (`default: '2026-01-01'`). Sin comillas YAML las lee como fecha y el comando las rechaza antes de llamar.
- El SDK solo revisa que los archivos se lean: lista valida, `scope`, `key`, `event` y que no haya repetidas. Lo demas lo valida el servidor, igual para el panel, la API, el SDK y MCP.

#### Desde codigo

```php
Smartmailto::putVariable('contact', 'plan', ['type' => 'enum', 'allowed_values' => ['free', 'pro'], 'description' => 'Plan']);
Smartmailto::putVariable('event', 'orden', ['type' => 'string', 'description' => 'Folio'], event: 'orden_creada');
Smartmailto::variables('event', 'orden_creada');          // catalogo (filtros opcionales)
Smartmailto::variableUsages('contact', 'plan');           // donde se usa
Smartmailto::obsoleteVariable('contact', 'plan');
Smartmailto::deleteVariable('contact', 'plan');           // false si no existia; 409 in_use si tiene usos
Smartmailto::activateTemplate('recibo-compra');
Smartmailto::activateWorkflow('checkout');
Smartmailto::provisionPackage(['variables' => [...], 'templates' => [...]], activate: true);   // todo o nada
Smartmailto::validatePackage([...]);                      // { valid, errors, warnings, results }: revisa `valid`
Smartmailto::schema();                                    // esquema para agentes (mismo que la ayuda del panel)
```

Tambien `Smartmailto::putPartial($name, $body)`, `Smartmailto::putTemplate($name, $subject, $body, layout:, kind:, displayName:, description:)`, `Smartmailto::putWorkflow($name, $yamlOArreglo)` y `Smartmailto::templates()` (con `checksum` = sha256 de `json_encode([subject, body, layout, kind])`). Una plantilla nueva queda `draft` y guardar un workflow nunca lo activa: usa `activate*()`. Las respuestas traen `warnings`. Son sincronos y lanzan `SmartmailtoException` si el servidor rechaza:

```php
try {
    Smartmailto::provisionPackage($package, activate: true);
} catch (SmartmailtoException $e) {
    $e->error();      // provision_failed | invalid_references | in_use | type_locked | ...
    $e->items();      // lo que fallo: [{ type, name, code, ref, location }]
    $e->usages();     // donde se usa la variable (in_use, type_locked, allowed_value_in_use)
    $e->warnings();
}
```

### Webhook de falla (F-008)

Cuando un envio aceptado vence (`send_before`) o falla, Smartmailto avisa por el webhook de falla del proyecto (panel → *Configuracion del proyecto* → pestaña *Avisos y alertas*). El secreto `whsec_...` se muestra una vez:

```dotenv
SMARTMAILTO_WEBHOOK_SECRET=whsec_...
```

```php
// routes/api.php (fuera de CSRF)
Route::post('/smartmailto/webhook', SmartmailtoWebhookController::class)->middleware('smartmailto.webhook');

// o a mano:
if (! Smartmailto::verifyWebhook($request)) {   // usa smartmailto.webhook_secret; o verifyWebhook($request, $secret, 300)
    abort(401);
}

$event = $request->json()->all();               // version, event, delivery_id, send_id, idempotency_key, template, reason
if ($event['event'] === 'send_expired') {
    // manda directo el correo de $event['idempotency_key'] (idempotente por delivery_id)
}
```

- Verifica `X-Smartmailto-Signature` (`sha256=` + HMAC-SHA256 de `"{timestamp}.{cuerpo crudo}"`) en tiempo constante y rechaza un `X-Smartmailto-Timestamp` a mas de 5 minutos (en cualquier sentido) contra replay. Cada reintento llega con timestamp nuevo.
- El middleware responde 401 si la firma no vale (Smartmailto no reintenta un 4xx) y **500 si falta `SMARTMAILTO_WEBHOOK_SECRET`** (error de configuracion de tu app: con 5xx Smartmailto reintenta ~10 h y el aviso no se pierde). `smartmailto.webhook:600` cambia la ventana.
- Responde rapido (2xx) y procesa en cola.

### Carga inicial

```php
$batch = Smartmailto::batch(backfill: true);   // no dispara correos

Order::query()->paid()->unclaimed()->each(fn ($order) => $batch->track(
    'orden_pagada', Identity::guest($order->email), ['timbres' => $order->stamps],
    eventId: "ff:orden_pagada:{$order->id}", object: ['order', $order->id], occurredAt: $order->paid_at,
));

$batch->dispatch();   // se parte en lotes de 100
```

Volver a correr la carga es seguro: los `eventId` repetidos se descartan.

Con el pull (siguiente seccion) la carga inicial la hace Smartmailto: no hace falta escribir este comando.

### Pull: Smartmailto le pide datos a tu app (F-011)

Con el modo `push+pull` del proyecto, Smartmailto llama a tu app para:

- la **carga inicial**, que lanza un admin desde el panel; nunca corre sola;
- la **reconciliacion** tras una caida;
- la **resincronizacion nocturna**, apagada por default;
- pedir **un contacto** cuando le falta un dato obligatorio para enviar.

Lo que llega por pull **nunca dispara correos ni workflows**: los eventos se guardan como historicos (`source = pull`) y si cuentan para condiciones y filtros.

**1. Implementa el resolver.** Lo que llega a Smartmailto se decide aqui:

```php
use Agavesoft\Smartmailto\Contracts\PullResolver;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Pull\{PullContact, PullCursor, PullEvent, PullPage};
use Carbon\CarbonInterface;

class MiPullResolver implements PullResolver
{
    public function contact(Identity $identity, ?CarbonInterface $eventsSince): ?PullContact
    {
        $user = $identity->userId !== null ? User::find($identity->userId) : User::firstWhere('email', $identity->email);

        return $user && $user->esContacto() ? $this->toContact($user, $eventsSince) : null;
    }

    public function contacts(?CarbonInterface $updatedSince, ?PullCursor $after, int $limit): PullPage
    {
        $users = User::query()->contactos()
            ->when($updatedSince, fn ($q) => $q->where('updated_at', '>=', $updatedSince))
            // Estrictamente despues del cursor, en el mismo orden (updated_at, id):
            ->when($after, fn ($q) => $q->where(fn ($q) => $q->where('updated_at', '>', $after->updatedAt)
                ->orWhere(fn ($q) => $q->where('updated_at', $after->updatedAt)->where('id', '>', $after->key))))
            ->orderBy('updated_at')->orderBy('id')
            ->limit($limit)->get();

        return new PullPage($users->map(fn ($user) => $this->toContact($user, null))->all());
    }

    private function toContact(User $user, ?CarbonInterface $eventsSince): PullContact
    {
        return new PullContact(
            Identity::user($user->id, $user->email),
            updatedAt: $user->updated_at,
            contactSince: $user->contacto_desde,          // tu regla de "desde cuando es contacto"
            attributes: ['plan' => $user->plan, 'last_purchase_at' => $user->ultima_compra_at?->toIso8601String()],
            events: $user->ordenesPagadas($eventsSince)->map(fn ($order) => new PullEvent(
                "app:orden_pagada:{$order->id}",          // EL MISMO eventId que mandas por track()
                'orden_pagada', $order->paid_at, ['total' => $order->total], ['order', $order->id],
            ))->all(),
            unsubscribed: $user->sin_correos,
            key: $user->id,                               // desempate del cursor: la misma columna del orderBy
        );
    }
}
```

**2. Configura.** El panel (*Configuracion del proyecto* → pestaña *Avanzado* → *Sincronizacion desde tu app (pull)*) muestra el secreto `pullsec_...` una sola vez y pide la URL de tu app: `https://tu-app/api/smartmailto/pull`.

```dotenv
SMARTMAILTO_PULL_ENABLED=true
SMARTMAILTO_PULL_SECRET=pullsec_...
SMARTMAILTO_PULL_RESOLVER=App\Smartmailto\MiPullResolver   # o enlaza Contracts\PullResolver en un provider
```

- La ruta `POST {prefix}/smartmailto/pull` (prefijo `api` por default, nombre `smartmailto.pull`) **solo existe** con el pull prendido y un resolver: si no, es 404. Se registra al arrancar la app: con `php artisan route:cache` regenera la cache al cambiar esto.
- **Middleware extra:** `smartmailto.pull.route.middleware`, por ejemplo un interruptor propio. Va fuera del grupo `web`, sin CSRF.
- **Throttle propio:** `smartmailto.pull.route.throttle`, default `120,1`. Corre antes de la firma, asi que tambien cuenta las peticiones rechazadas. Smartmailto manda a lo mas 60/min de corridas y 60/min de un contacto.
- **Interruptores independientes:** el pull no depende de `SMARTMAILTO_ENABLED`, que es el interruptor del push. Con el push apagado, el catalogo remoto no se puede leer y la ruta responde 503, salvo que fijes `smartmailto.pull.catalog`.

**3. Pruebalo sin red** contra el contrato:

```php
$pull = Smartmailto::fakePull(MiPullResolver::class, catalog: ['plan', 'last_purchase_at']);

$pull->contact(Identity::user($user->id));   // el contacto tal como lo recibe Smartmailto (o null)
$pull->assertPullContract(limit: 2);         // recorre todas las paginas: orden, cursor estable, eventos completos
```

`fakePull()` prende el pull con un secreto de prueba y pasa por la ruta real (firma, filtro, historia, cursor). Con `catalog` no consulta a Smartmailto.

**Reglas:**

1. **Misma identidad y mismo `eventId` que en el push.** Si el resolver arma `event_id` distintos a los de tu `track()`, la carga duplica eventos y las condiciones `events.X.count` cuentan doble.
2. **Solo atributos del catalogo.** Antes de responder, el SDK descarta las llaves que no son variables de contacto del catalogo del proyecto (F-009).
   - El catalogo se lee de Smartmailto y se guarda en cache 5 min (`pull.catalog_ttl`), o se fija con `smartmailto.pull.catalog` (lista de llaves).
   - Si no se puede leer, la ruta responde 503 y Smartmailto reintenta: nunca se mandan atributos sin filtrar.
   - Las `properties` de los eventos no se filtran.
3. **Condiciones por atributo, no por evento.** El pull trae **estado**.
   - Escribe las condiciones de tus workflows sobre atributos (`contact.attributes.last_purchase_at`), no sobre "llego el evento X".
   - Las condiciones de compra **deben** usar `last_purchase_at`: los eventos de mas de 13 meses ya no estan en Smartmailto, y alguien que compro hace 14 meses no es "nunca compro".
4. **Historia.** `history_months` (`SMARTMAILTO_PULL_HISTORY_MONTHS`) limita los eventos que se mandan; vacio = toda.
   - El SDK no topa la historia: Smartmailto descarta lo que este fuera de su retencion de eventos.
   - `contact()` recibe `eventsSince` ya calculado. En `contacts()` el SDK filtra los eventos despues.
5. **Orden y cursor.** `contacts()` devuelve en orden `(updatedAt, key)` y estrictamente despues de `$after`.
   - El SDK arma el cursor desde el ultimo contacto y lo firma con el secreto del pull.
   - Rotar el secreto invalida los cursores guardados: una corrida fallida tiene que empezar de cero.
   - Si `updatedAt` retrocede dentro de una pagina, la ruta responde 500 en vez de saltarse contactos.
   - Si tu columna guarda fracciones de segundo (`timestamp(6)`), compara con `$after->updatedAt->format('Y-m-d H:i:s.u')`. El query builder formatea las fechas sin microsegundos y repetiria filas.
   - `contacts()` no recibe el horizonte de historia. El SDK descarta los eventos viejos despues, pero si tienes mucha historia, limita tu consulta con `config('smartmailto.pull.history_months')`.
   - `hasMore: true` con una pagina vacia responde 500: el cursor no tiene desde donde seguir.
6. **Quien es contacto lo decides tu.** `contactSince` es tu regla; sin ella, Smartmailto usa la fecha en que lo conocio. No devuelvas destinatarios externos, por ejemplo receptores de un CFDI.
7. **Bajas y borrados.**
   - `unsubscribed: true` llega como baja y el pull nunca la quita.
   - `PullPage` acepta `deleted: [Identity, ...]`: personas borradas en tu app, que Smartmailto deja inactivas sin borrarlas.
   - El borrado ARCO va siempre por `Smartmailto::forget()`, y un contacto borrado por ARCO no se recrea por pull.

**Contrato v1** (para implementarlo a mano en apps no Laravel). Smartmailto hace `POST` con cuerpo JSON:

```jsonc
// Un contacto
{ "version": 1, "op": "contact", "project_id": 12,
  "identity": { "user_id": "123" } | { "email": "a@b.mx" },
  "include": ["attributes", "events"], "events_since": null }

// Pagina (updated_since null = carga inicial; limit default 200, max 500)
{ "version": 1, "op": "contacts", "project_id": 12,
  "updated_since": "2026-10-07T03:00:00Z" | null, "cursor": "opaco" | null, "limit": 200,
  "include": ["attributes", "events"] }
```

| Header de la peticion | Valor |
|---|---|
| `User-Agent` | `Smartmailto-Pull/1` |
| `X-Smartmailto-Timestamp` | segundos Unix |
| `X-Smartmailto-Request` | uuid nuevo en cada intento |
| `X-Smartmailto-Signature` | `sha256=` + HMAC-SHA256 hex de `"{timestamp}.{cuerpo}"` con el secreto del pull |

Respuesta `200` con `X-Smartmailto-Timestamp` y `X-Smartmailto-Signature` = `sha256=` + HMAC-SHA256 hex de `"{timestamp}.{X-Smartmailto-Request}.{cuerpo}"`. Smartmailto descarta la pagina si la firma no coincide.

```jsonc
{ "version": 1,
  "contacts": [ { "user_id": "123", "email": "a@b.mx",
      "contact_since": "2024-03-01T10:00:00Z", "updated_at": "2026-10-07T21:10:00Z", "unsubscribed": false,
      "attributes": { "last_purchase_at": "2026-09-30" },
      "events": [ { "event_id": "ff:orden_pagada:991", "event": "orden_pagada", "occurred_at": "2026-09-30T10:00:00Z",
                    "properties": { "total": 100 }, "object": { "type": "order", "id": "991" } } ] } ],
  "deleted": [ { "user_id": "77" } ],
  "next_cursor": "opaco" | null }
```

- **Contacto no encontrado:** `contacts: []`, no es error.
- **Rechazos definitivos:**
  - `401`: firma invalida, timestamp a mas de 300 s o `X-Smartmailto-Request` repetido;
  - `404`: pull apagado o sin resolver;
  - `422`: version, `op`, identidad, fecha, `limit` o cursor invalidos.
- **Se reintentan:**
  - `503`: sin secreto o sin catalogo;
  - `5xx`: falla del resolver;
  - `429`.
- La respuesta no debe pasar de 5 MB. El SDK recorta la pagina a `pull.max_response_bytes` y el cursor sigue desde el ultimo contacto que cupo. Si un solo contacto no cabe, responde 500: baja `history_months`.

### Fallas

- Se reintenta solo lo transitorio (red, 5xx, 429 respetando `Retry-After`) con espera de 30 s, 2 min, 10 min y despues cada hora, hasta 24 h.
- Un rechazo (422 por datos invalidos, 401 por token) no se reintenta.
- En ambos casos, al rendirse se dispara `Agavesoft\Smartmailto\Events\SmartmailtoDeliveryFailed` (`endpoint`, `key` = event_id o idempotency key, `status`, `error`). Escuchalo para registrar o alertar. En un lote, cada item invalido dispara su propio evento.
- Con el outbox (F-010) las reglas son las de su seccion: 72 h de reintentos, alertas agrupadas y `SmartmailtoOutboxFailed` (`outboxId`, `kind`, `key`, `reason`, `template`, `status`, `error`) en vez de `SmartmailtoDeliveryFailed`.

### Privacidad (consulta y borrado)

Smartmailto guarda correos y atributos cifrados con una llave por proyecto. Para atender a una persona:

```php
Smartmailto::contact(Identity::user($user->id));        // correo, atributos, eventos y envios (o null)
Smartmailto::renderedEmail($sendId);                     // el correo enviado, re-generado sin ligas de un solo uso
Smartmailto::forget(Identity::guest('ana@example.com')); // borrado ARCO; true si existia
```

Cada consulta queda registrada en la bitacora de acceso del proyecto.

### Pruebas en tu app

```php
$fake = Smartmailto::fake();

// ... ejecuta tu codigo ...

$fake->assertTracked('orden_pagada', fn ($body) => $body['object']['id'] === '100');
$fake->assertSent('recibo-compra');
$fake->assertNotTracked('orden_creada');
$fake->assertSent('cfdi-por-correo', fn ($body) => count($body['attachments']) === 2);
$fake->assertProvisioned('templates', 'recibo-compra');
$fake->assertPackageProvisioned(fn (array $package, bool $activate) => $activate);   // smartmailto:provision
$fake->assertVariablePut('contact', 'plan');
$fake->assertActivated('workflow', 'checkout');
$fake->assertLinked(fn ($body) => $body['link_id'] === 'ff:link:order:555');   // F-010
$fake->assertExternalSendReported('ff:recibo:555');                            // F-010: tu listener de emergencia
```

Con el fake las llamadas no pasan por el outbox, pero sus validaciones si (por ejemplo `sendBefore` obligatorio si `outbox.enabled`).

El fake no sale a la red. Sus respuestas se ajustan con `provisionResponse`, `validateResponse`, `variablesResponse`, `usagesResponse`, `deleteResponse` y `schemaResponse`.

Para probar tu resolver del pull, usa `Smartmailto::fakePull()` (ver [Pull](#pull-smartmailto-le-pide-datos-a-tu-app-f-011)). Funciona junto con `fake()`: sin `catalog`, toma las variables de contacto de `variablesResponse`.

## Contrato de eventos de Factura Facilita

Fuente de verdad: `smartmailto-conocimiento/features/2026-10-F003-sdk-ingesta-robusta/dimensiones/integracion.md`.

| Evento | eventId | Identidad | object | properties | secrets |
|---|---|---|---|---|---|
| `orden_creada` | `ff:orden_creada:{order_id}` | guest(email) | order | timbres, total, paquete | checkout_url |
| `orden_pagada` | `ff:orden_pagada:{order_id}` | guest(email) o user(id, email) | order | invitado (bool), timbres, total, paid_at | activation_url, activation_expires_at (invitado) |
| `orden_reclamada` | `ff:orden_reclamada:{order_id}` | user(id, correo de la orden) | order | via | — |
| `cuenta_creada` | `ff:cuenta_creada:{user_id}` | user(id, email) | — | name | — |
| `sesion_iniciada` | `ff:sesion_iniciada:{user_id}:{fecha}` | user(id) | — | — | — |
| `timbres_asignados` | `ff:timbres_asignados:{package_account_id}` | user(id) | order? | disponibles, origen | — |
| `empresa_creada` | `ff:empresa_creada:{company_id}` | user(id) | company | es_primera | — |
| `cfdi_timbrado` | `ff:cfdi_timbrado:{cfdi_id}` | user(id) | cfdi | es_primero | — |

## Migrar desde v1

| v1 (`agavesoft/mailflow`) | v2 (`agavesoft/smartmailto`) |
|---|---|
| `Mailflow::identify($userId, $attrs, async: true)` | `Smartmailto::identify(Identity::user($userId, $email), $attrs)` |
| `Mailflow::track($userId, $event, $props, async: true)` | `Smartmailto::track($event, Identity::user($userId), $props, eventId: '...')` |
| `Mailflow::send($userId, $slug, $data)` | `Smartmailto::send($slug, Identity::user($userId, $email), $data, idempotencyKey: '...')` |
| `MAILFLOW_API_URL`, `MAILFLOW_API_TOKEN` | `SMARTMAILTO_API_URL`, `SMARTMAILTO_API_TOKEN` |
| errores ignorados en silencio | reintentos reales + `SmartmailtoDeliveryFailed` |

## Licencia

MIT.
