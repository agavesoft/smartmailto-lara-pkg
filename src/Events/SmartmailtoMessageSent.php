<?php

namespace Agavesoft\Smartmailto\Events;

/**
 * Smartmailto acepto un correo de `sendRaw()` / del mailer `smartmailto` (en modo directo, cola u outbox).
 *
 * - `sendId`: id del envio en Smartmailto (para `GET /api/sends/{id}`); null si el acuse no lo trajo.
 * - `status`: el que reporta Smartmailto en la respuesta; si no trae uno: `simulated` (sender Dummy),
 *   `duplicate` (misma idempotency_key) o `queued`. Estados posteriores (sent, delivered, bounced,
 *   blocked_allowlist, ...) no estan aqui: consultalos con `GET /api/sends/{id}` o el webhook.
 * - `headers`: encabezados del mensaje original (`X-Mi-Invitacion: 123`, `Message-ID`, ...), para que tu
 *   app relacione el envio con su propio registro. Nunca incluye la llave de la API.
 */
class SmartmailtoMessageSent
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly ?int $sendId,
        public readonly string $status,
        public readonly string $idempotencyKey,
        public readonly array $headers = [],
        public readonly bool $duplicate = false,
        public readonly array $response = [],
    ) {}

    /** Valor de un encabezado del mensaje original (sin distinguir mayusculas). */
    public function header(string $name): ?string
    {
        foreach ($this->headers as $key => $value) {
            if (strcasecmp($key, $name) === 0) {
                return $value;
            }
        }

        return null;
    }
}
