<?php

// Fase 1: listSends(), suppression() y rotateKey().

use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

test('listSends arma la consulta con filtros y content', function () {
    Http::fake(['*/api/sends*' => Http::response(['data' => [['id' => 1, 'subject' => 'Hola']], 'meta' => ['page' => 1, 'per_page' => 25, 'total' => 1, 'last_page' => 1]])]);

    $page = Smartmailto::listSends([
        'from' => Carbon::parse('2026-10-30T00:00:00Z'), 'status' => ['sent', 'failed'], 'recipient' => 'ana@example.com', 'origin' => 'api', 'per_page' => 50, 'to' => null,
    ], content: true);

    expect($page['data'][0]['subject'])->toBe('Hola');
    Http::assertSent(function (Request $r) {
        parse_str((string) parse_url($r->url(), PHP_URL_QUERY), $q);

        return $r->method() === 'GET' && str_contains($r->url(), '/api/sends?')
            && $q === ['from' => '2026-10-30T00:00:00+00:00', 'status' => 'sent,failed', 'recipient' => 'ana@example.com', 'origin' => 'api', 'per_page' => '50', 'include' => 'content'];
    });
});

test('suppression pregunta por una direccion y rotateKey manda la gracia', function () {
    Http::fake([
        '*/api/suppressions*' => Http::response(['email' => 'ana@example.com', 'suppressed' => true, 'blocks_transactional' => true, 'reasons' => [['reason' => 'hard_bounce']]]),
        '*/api/keys/rotate' => Http::response(['api_token' => 'mf_dev_nueva', 'key' => ['id' => 2], 'previous' => ['id' => 1]], 201),
    ]);

    expect(Smartmailto::suppression('ana@example.com')['blocks_transactional'])->toBeTrue()
        ->and(Smartmailto::rotateKey(6)['api_token'])->toBe('mf_dev_nueva');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'suppressions?email=ana%40example.com'));
    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/api/keys/rotate') && $r['grace_hours'] === 6);
});

test('apagado no llama', function () {
    config(['smartmailto.enabled' => false]);
    Http::fake();

    expect(Smartmailto::listSends())->toBeNull()->and(Smartmailto::suppression('a@example.com'))->toBeNull()->and(Smartmailto::rotateKey())->toBeNull();
    Http::assertNothingSent();
});

test('el fake lista lo que se mando', function () {
    $fake = Smartmailto::fake();
    Smartmailto::sendRaw(Identity::external('ana@example.com'), 'Hola', '<p>x</p>', idempotencyKey: 'k1');
    $fake->suppressed['ana@example.com'] = true;

    expect(Smartmailto::listSends(content: true)['data'][0])->toMatchArray(['recipient' => 'ana@example.com', 'subject' => 'Hola', 'content' => ['subject' => 'Hola', 'html' => '<p>x</p>', 'source' => 'raw']])
        ->and(Smartmailto::suppression('Ana@example.com')['suppressed'])->toBeTrue();
});
