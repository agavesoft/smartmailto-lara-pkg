<?php

namespace Agavesoft\Smartmailto\Mail;

use Agavesoft\Smartmailto\Events\SmartmailtoMessageFailed;
use Agavesoft\Smartmailto\Events\SmartmailtoMessageSent;
use Throwable;

/**
 * Despacha SmartmailtoMessageSent / SmartmailtoMessageFailed para una llamada de `sendRaw()`. `$meta` viaja
 * con la llamada (en el job, o cifrado en el payload del outbox) y nunca se manda a Smartmailto:
 * `['idempotency_key' => ..., 'headers' => [...]]`. Un listener que truena no rompe la entrega (ya acusada).
 */
final class MessageEvents
{
    public const META_KEY = '_smartmailto_message';

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $response
     */
    public static function sent(array $meta, array $response): void
    {
        if (! isset($meta['idempotency_key'])) {
            return;
        }
        $duplicate = (bool) ($response['duplicate'] ?? false);
        $status = is_string($response['status'] ?? null) ? $response['status']
            : (($response['simulated'] ?? false) ? 'simulated' : ($duplicate ? 'duplicate' : 'queued'));

        self::dispatch(new SmartmailtoMessageSent(
            sendId: isset($response['id']) ? (int) $response['id'] : null,
            status: $status,
            idempotencyKey: (string) $meta['idempotency_key'],
            headers: $meta['headers'] ?? [],
            duplicate: $duplicate,
            response: self::safe($response),
        ));
    }

    /**
     * @param  array<string, mixed>  $meta
     * @param  array<string, mixed>  $response
     */
    public static function failed(array $meta, ?int $status, string $error, string $reason = 'failed', array $response = []): void
    {
        if (! isset($meta['idempotency_key'])) {
            return;
        }

        self::dispatch(new SmartmailtoMessageFailed(
            idempotencyKey: (string) $meta['idempotency_key'],
            headers: $meta['headers'] ?? [],
            status: $status,
            error: $error,
            reason: $reason,
            response: self::safe($response),
        ));
    }

    /** Separa el meta del cuerpo (outbox): el meta nunca se manda a Smartmailto. */
    public static function extract(array &$body): array
    {
        $meta = is_array($body[self::META_KEY] ?? null) ? $body[self::META_KEY] : [];
        unset($body[self::META_KEY]);

        return $meta;
    }

    /** Solo campos del acuse (nada que pudiera traer credenciales). */
    private static function safe(array $response): array
    {
        return array_intersect_key($response, array_flip(['id', 'ack', 'idempotency_key', 'received_at', 'duplicate', 'simulated', 'status', 'error', 'field', 'message', 'retry_after']));
    }

    private static function dispatch(object $event): void
    {
        try {
            app('events')->dispatch($event);
        } catch (Throwable $e) {
            app('log')->error('Smartmailto: '.class_basename($event).' listener failed: '.class_basename($e).' '.$e->getMessage());
        }
    }
}
