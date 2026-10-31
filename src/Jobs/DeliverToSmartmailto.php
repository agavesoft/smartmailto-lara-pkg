<?php

namespace Agavesoft\Smartmailto\Jobs;

use Agavesoft\Smartmailto\Events\SmartmailtoDeliveryFailed;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Mail\MessageEvents;
use Agavesoft\Smartmailto\SmartmailtoClient;
use DateTimeInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Entrega una llamada a Smartmailto desde la cola.
 *
 * Reintenta solo lo transitorio (conexion, 5xx, 429 respetando Retry-After) con backoff hasta
 * `retry_hours`. Un rechazo (4xx) no se reintenta: se marca fallido y se dispara
 * SmartmailtoDeliveryFailed. Es seguro reintentar porque cada llamada lleva su event_id o
 * idempotency key: Smartmailto descarta los duplicados.
 */
class DeliverToSmartmailto implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    // Sin tope por numero de intentos ni de excepciones: manda retryUntil() (retry_hours).
    public int $tries = 0;

    /**
     * @param  array<string, mixed>  $body
     * @param  array<string, string>  $headers
     */
    public function __construct(
        public string $endpoint,
        public array $body,
        public array $headers = [],
        public ?string $key = null,
        public array $meta = [], // SmartmailtoMessageSent/Failed (sendRaw); nunca va a Smartmailto
    ) {}

    public function backoff(): array
    {
        return config('smartmailto.backoff', [30, 120, 600, 3600]);
    }

    public function retryUntil(): DateTimeInterface
    {
        return now()->addHours((int) config('smartmailto.retry_hours', 24));
    }

    public function handle(SmartmailtoClient $client): void
    {
        try {
            $response = $client->post($this->endpoint, $this->body, $this->headers);
        } catch (SmartmailtoException $e) {
            if (! $e->transient) {
                $this->fail($e);

                return;
            }

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);

                return;
            }

            throw $e;
        }

        $this->reportBatchItems($response);
        MessageEvents::sent($this->meta, $response);
    }

    /**
     * Un lote se acepta aunque traiga items invalidos (207): cada invalido se reporta, sin reintento.
     * Un item con `error` (falla del servidor) lanza una excepcion transitoria para reintentar el lote
     * completo: los ya aceptados vuelven como duplicados por su event_id.
     *
     * @param  array<string, mixed>  $response
     */
    public function reportBatchItems(array $response): void
    {
        if ($this->endpoint !== 'batch') {
            return;
        }

        $results = $response['results'] ?? [];

        $errors = array_filter($results, fn ($result) => ($result['status'] ?? null) === 'error');
        if ($errors !== []) {
            throw SmartmailtoException::transient(count($errors).' batch item(s) failed on the server.');
        }

        foreach ($results as $result) {
            if (($result['status'] ?? null) === 'invalid') {
                $item = $this->body['items'][$result['index'] ?? -1] ?? [];
                app('events')->dispatch(new SmartmailtoDeliveryFailed(
                    endpoint: 'batch',
                    key: $item['event_id'] ?? null,
                    status: 422,
                    error: 'Batch item rejected.',
                    response: $result,
                ));
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        app('events')->dispatch(new SmartmailtoDeliveryFailed(
            endpoint: $this->endpoint,
            key: $this->key,
            status: $exception instanceof SmartmailtoException ? $exception->status : null,
            error: $exception->getMessage(),
            response: $exception instanceof SmartmailtoException ? $exception->response : [],
        ));
        MessageEvents::failed(
            $this->meta,
            $exception instanceof SmartmailtoException ? $exception->status : null,
            $exception->getMessage(),
            $exception instanceof SmartmailtoException && ! $exception->transient ? 'rejected' : 'failed',
            $exception instanceof SmartmailtoException ? $exception->response : [],
        );
    }
}
