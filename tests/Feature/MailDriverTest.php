<?php

// Fase 0: sendRaw() y el mailer `smartmailto` (MAIL_MAILER=smartmailto -> POST /api/send sin plantilla).

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Mail\SmartmailtoTransport;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\Message;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mailer\Exception\TransportException;

beforeEach(function () {
    config(['mail.mailers.smartmailto' => ['transport' => 'smartmailto'], 'mail.default' => 'smartmailto', 'mail.from' => ['address' => 'no-reply@notificabot.com', 'name' => 'Notificabot']]);
});

test('sendRaw manda asunto y cuerpo sin plantilla', function () {
    $fake = Smartmailto::fake();

    Smartmailto::sendRaw(Identity::external('ana@example.com'), 'Hola', '<p>Hola</p>', 'Hola', idempotencyKey: 'nb:1', cc: ['c@example.com']);

    $fake->assertSentRaw('Hola', fn ($body) => $body['html'] === '<p>Hola</p>' && $body['text'] === 'Hola'
        && $body['email'] === 'ana@example.com' && $body['recipient_kind'] === 'external' && $body['cc'] === ['c@example.com'] && ! isset($body['template']));
});

test('sendRaw valida llave, asunto y cuerpo', function () {
    Smartmailto::fake();

    expect(fn () => Smartmailto::sendRaw(Identity::external('a@example.com'), 'S', '<p>x</p>'))->toThrow(InvalidArgumentException::class, 'idempotencyKey')
        ->and(fn () => Smartmailto::sendRaw(Identity::external('a@example.com'), '', '<p>x</p>', idempotencyKey: 'k'))->toThrow(InvalidArgumentException::class, 'subject')
        ->and(fn () => Smartmailto::sendRaw(Identity::external('a@example.com'), 'S', idempotencyKey: 'k'))->toThrow(InvalidArgumentException::class, 'html or text');
});

test('el mailer convierte el Email en /api/send sin plantilla (cc, bcc, reply_to, from, adjuntos, llave del Message-ID)', function () {
    $fake = Smartmailto::fake();

    Mail::html('<p>Hola <b>Ana</b></p>', function (Message $m) {
        $m->to('ana@example.com')->to('beto@example.com')->cc('c@example.com')->bcc('b@example.com')
            ->replyTo('soporte@notificabot.com', 'Soporte')->subject('Tu aviso')
            ->text('Hola Ana')
            ->attachData('hola mundo', 'nota.txt', ['mime' => 'text/plain']);
    });

    $fake->assertSentRaw('Tu aviso', function ($body) {
        expect($body['email'])->toBe('ana@example.com')
            ->and($body['recipient_kind'])->toBe('external')
            ->and($body['to'])->toBe(['beto@example.com'])
            ->and($body['cc'])->toBe(['c@example.com'])
            ->and($body['bcc'])->toBe(['b@example.com'])
            ->and($body['reply_to'])->toBe(['email' => 'soporte@notificabot.com', 'name' => 'Soporte'])
            ->and($body['from'])->toBe(['email' => 'no-reply@notificabot.com', 'name' => 'Notificabot'])
            ->and($body['html'])->toBe('<p>Hola <b>Ana</b></p>')
            ->and($body['text'])->toBe('Hola Ana')
            ->and($body['attachments'][0])->toBe(['filename' => 'nota.txt', 'content' => base64_encode('hola mundo'), 'content_type' => 'text/plain'])
            ->and($body['idempotency_key'])->toStartWith('mail:')->not->toContain('<');

        return true;
    });
});

test('con send_from=false no manda From (usa el del sender en Smartmailto)', function () {
    config(['mail.mailers.smartmailto.send_from' => false]);
    $fake = Smartmailto::fake();

    Mail::raw('solo texto', fn (Message $m) => $m->to('ana@example.com')->subject('Texto'));

    $fake->assertSentRaw('Texto', fn ($body) => ! isset($body['from']) && $body['text'] === 'solo texto' && ! isset($body['html']));
});

test('sin cola llega por HTTP a /api/send con Idempotency-Key y un rechazo es TransportException', function () {
    config(['smartmailto.queue' => false]);
    Http::fake(['*/api/send' => Http::sequence()
        ->push(['ack' => true, 'id' => 77, 'duplicate' => false, 'simulated' => true], 202)
        ->push(['error' => 'send_rate_limited', 'limit' => 'minute'], 429)]);

    $sent = Mail::raw('x', fn (Message $m) => $m->to('ana@example.com')->subject('S'));
    expect($sent->getMessageId())->toBe('smartmailto-77');
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/send') && str_starts_with($r->header('Idempotency-Key')[0], 'mail:') && $r['subject'] === 'S');

    expect(fn () => Mail::raw('x', fn (Message $m) => $m->to('ana@example.com')->subject('S2')))->toThrow(TransportException::class);
});

test('la llave de idempotencia larga se resume', function () {
    expect(SmartmailtoTransport::idempotencyKey('<abc@x>'))->toBe('mail:abc@x')
        ->and(strlen(SmartmailtoTransport::idempotencyKey(str_repeat('a', 300).'@x')))->toBeLessThanOrEqual(191);
});

test('attachData sin mime (application/octet-stream de Laravel) infiere el tipo por la extension', function () {
    $fake = Smartmailto::fake();

    Mail::raw('Hola', function (Message $m) {
        $m->from('no-reply@notificabot.com')->to('ana@example.com')->subject('Sin mime')
            ->attachData('hola', 'prueba.txt')
            ->attachData('a,b', 'datos.csv', ['mime' => 'application/octet-stream']);
    });

    $fake->assertSentRaw('Sin mime', fn ($body) => expect(array_column($body['attachments'], 'content_type'))->toBe(['text/plain', 'text/csv']) !== null);
});

test('un mime concreto que no corresponde a la extension se sigue rechazando', function () {
    expect(fn () => Attachment::fromData('hola', 'prueba.txt', 'application/pdf'))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(Attachment::fromData('hola', 'prueba.txt', 'application/octet-stream')->toArray()['content_type'])->toBe('text/plain')
        ->and(Attachment::fromData('hola', 'prueba.txt', '')->toArray()['content_type'])->toBe('text/plain')
        ->and(fn () => Attachment::fromData('x', 'malware.exe', 'application/octet-stream'))->toThrow(InvalidArgumentException::class, 'not allowed');
});
