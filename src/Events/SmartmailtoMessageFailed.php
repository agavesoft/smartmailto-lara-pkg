<?php

namespace Agavesoft\Smartmailto\Events;

/**
 * Un correo de `sendRaw()` / del mailer `smartmailto` no se pudo entregar a Smartmailto: rechazo (4xx),
 * reintentos agotados, `send_before` vencido (outbox) o error en modo directo. Lleva los mismos
 * `headers` del mensaje original que SmartmailtoMessageSent. Nunca incluye la llave de la API.
 *
 * - `status`: estado HTTP de la respuesta (null si no hubo respuesta).
 * - `reason`: `rejected`, `failed`, `expired` (outbox) o `direct` (modo sincrono; la excepcion sube).
 */
class SmartmailtoMessageFailed
{
    /**
     * @param  array<string, string>  $headers
     * @param  array<string, mixed>  $response
     */
    public function __construct(
        public readonly string $idempotencyKey,
        public readonly array $headers,
        public readonly ?int $status,
        public readonly string $error,
        public readonly string $reason = 'failed',
        public readonly array $response = [],
    ) {}

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
