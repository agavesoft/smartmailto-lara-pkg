<?php

// SmartmailtoMessageSent / SmartmailtoMessageFailed: el mailer avisa la respuesta de Smartmailto con los
// headers originales del mensaje, en modo directo, cola y outbox. Nunca llevan la llave.

use Agavesoft\Smartmailto\Events\SmartmailtoMessageFailed;
use Agavesoft\Smartmailto\Events\SmartmailtoMessageSent;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Outbox\OutboxWorker;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    config(['mail.mailers.smartmailto' => ['transport' => 'smartmailto'], 'mail.default' => 'smartmailto']);
    $this->sent = [];
    $this->failed = [];
    Event::listen(SmartmailtoMessageSent::class, fn ($e) => $this->sent[] = $e);
    Event::listen(SmartmailtoMessageFailed::class, fn ($e) => $this->failed[] = $e);
});

function meMail(string $subject = 'Invitacion'): void
{
    Mail::raw('Hola', function (Message $m) use ($subject) {
        $m->from('no-reply@notificabot.com')->to('ana@example.com')->subject($subject);
        $m->getSymfonyMessage()->getHeaders()->addTextHeader('X-Invitacion-Id', 'inv-42');
    });
}

function meNoSecret(object $event): void
{
    expect(serialize($event))->not->toContain('mf_live_test')->not->toContain('Bearer')->not->toContain('Authorization');
}

test('modo directo: Sent con id, status, idempotency_key y el header propio', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::response(['ack' => true, 'id' => 77, 'duplicate' => false, 'simulated' => true, 'idempotency_key' => 'x'], 202)]);

    meMail();

    expect($this->sent)->toHaveCount(1);
    $e = $this->sent[0];
    expect($e->sendId)->toBe(77)
        ->and($e->status)->toBe('simulated')
        ->and($e->idempotencyKey)->toStartWith('mail:')
        ->and($e->header('x-invitacion-id'))->toBe('inv-42')
        ->and($e->headers['Subject'])->toBe('Invitacion')
        ->and($this->failed)->toBe([]);
    meNoSecret($e);
});

test('modo directo: un status del servidor se respeta; sin status ni simulated = queued; duplicate', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::sequence()
        ->push(['ack' => true, 'id' => 1, 'status' => 'blocked_allowlist'], 202)
        ->push(['ack' => true, 'id' => 2, 'duplicate' => false], 202)
        ->push(['ack' => true, 'id' => 2, 'duplicate' => true], 200)]);

    meMail('a');
    meMail('b');
    meMail('c');

    expect(array_map(fn ($e) => $e->status, $this->sent))->toBe(['blocked_allowlist', 'queued', 'duplicate'])
        ->and($this->sent[2]->duplicate)->toBeTrue();
});

test('modo directo: un rechazo dispara Failed con los headers y sube TransportException', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::response(['error' => 'recipients_require_transactional'], 422)]);

    expect(fn () => meMail())->toThrow(TransportException::class);

    expect($this->failed)->toHaveCount(1)
        ->and($this->failed[0]->status)->toBe(422)
        ->and($this->failed[0]->reason)->toBe('direct')
        ->and($this->failed[0]->header('X-Invitacion-Id'))->toBe('inv-42')
        ->and($this->sent)->toBe([]);
    meNoSecret($this->failed[0]);
});

test('cola (sync): Sent al responder; un rechazo da Failed rejected', function () {
    config(['queue.default' => 'sync']);
    Http::fake(['*/api/send' => Http::sequence()
        ->push(['ack' => true, 'id' => 5, 'duplicate' => false], 202)
        ->push(['error' => 'invalid'], 422)]);

    meMail('a');
    meMail('b');

    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]->sendId)->toBe(5)
        ->and($this->sent[0]->header('X-Invitacion-Id'))->toBe('inv-42')
        ->and($this->failed)->toHaveCount(1)
        ->and($this->failed[0]->reason)->toBe('rejected')
        ->and($this->failed[0]->header('X-Invitacion-Id'))->toBe('inv-42');
});

test('cola real: el job lleva los headers y dispara Sent al entregarse', function () {
    config(['queue.default' => 'database']);
    \Illuminate\Support\Facades\Queue::fake();

    meMail();

    \Illuminate\Support\Facades\Queue::assertPushed(\Agavesoft\Smartmailto\Jobs\DeliverToSmartmailto::class, function ($job) {
        Http::fake(['*/api/send' => Http::response(['ack' => true, 'id' => 9, 'duplicate' => false], 202)]);
        expect($job->body)->not->toHaveKey('_smartmailto_message');
        $job->handle(app(\Agavesoft\Smartmailto\SmartmailtoClient::class));

        return true;
    });
    expect($this->sent[0]->sendId)->toBe(9)->and($this->sent[0]->header('X-Invitacion-Id'))->toBe('inv-42');
});

test('outbox: los headers viajan cifrados en la fila, no se mandan a Smartmailto, y vuelven en Sent', function () {
    config(['smartmailto.outbox.enabled' => true]);
    (require __DIR__.'/../../database/migrations/2026_10_08_000000_create_smartmailto_outbox_tables.php')->up();
    meMail();
    $row = DB::table('smartmailto_outbox')->sole();
    expect($row->payload)->not->toContain('inv-42');

    // El worker manda la llave de idempotencia de la fila; el acuse la debe repetir.
    Http::fake(['*/api/send' => fn (Request $r) => Http::response(['ack' => true, 'idempotency_key' => $r['idempotency_key'], 'id' => 812, 'duplicate' => false], 202)]);
    app(OutboxWorker::class)->runOnce();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/send') && ! isset($r['_smartmailto_message']));
    expect($this->sent)->toHaveCount(1)
        ->and($this->sent[0]->sendId)->toBe(812)
        ->and($this->sent[0]->header('X-Invitacion-Id'))->toBe('inv-42');
});

test('outbox: un rechazo definitivo dispara Failed con los headers', function () {
    config(['smartmailto.outbox.enabled' => true, 'mail.mailers.array' => ['transport' => 'array']]);
    (require __DIR__.'/../../database/migrations/2026_10_08_000000_create_smartmailto_outbox_tables.php')->up();

    meMail();
    Http::fake(['*/api/send/*' => Http::response(['error' => 'send_not_found'], 404), '*/api/send' => Http::response(['error' => 'invalid'], 422), '*' => Http::response([], 200)]);
    app(OutboxWorker::class)->runOnce();

    expect($this->failed)->toHaveCount(1)
        ->and($this->failed[0]->reason)->toBe('rejected')
        ->and($this->failed[0]->status)->toBe(422)
        ->and($this->failed[0]->header('X-Invitacion-Id'))->toBe('inv-42');
});

test('el fake dispara Sent como queued (para probar tus listeners)', function () {
    Smartmailto::fake();

    meMail();

    expect($this->sent[0]->status)->toBe('queued')->and($this->sent[0]->header('X-Invitacion-Id'))->toBe('inv-42');
});

test('sendRaw directo tambien acepta messageHeaders; send() con plantilla no dispara estos eventos', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::response(['ack' => true, 'id' => 3], 202)]);

    Smartmailto::sendRaw(Identity::external('a@example.com'), 'S', '<p>x</p>', idempotencyKey: 'app:1', messageHeaders: ['X-Mio' => 'z']);
    Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'app:2');

    expect($this->sent)->toHaveCount(1)->and($this->sent[0]->header('X-Mio'))->toBe('z')->and($this->sent[0]->idempotencyKey)->toBe('app:1');
});

test('un listener que truena no rompe el envio', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::response(['ack' => true, 'id' => 4], 202)]);
    Event::listen(SmartmailtoMessageSent::class, fn () => throw new RuntimeException('boom'));

    $sent = Mail::raw('x', fn (Message $m) => $m->from('a@b.test')->to('ana@example.com')->subject('S'));

    expect($sent->getMessageId())->toBe('smartmailto-4');
});
