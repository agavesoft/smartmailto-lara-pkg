<?php

// Cuerpos exactos del contrato de ingesta v2 de Smartmailto (F-003). Si el servidor cambia el
// contrato, estas pruebas y tests/Feature/F003 del servidor deben cambiar juntas.

use Agavesoft\Smartmailto\Attachment;
use Agavesoft\Smartmailto\Exceptions\SmartmailtoException;
use Agavesoft\Smartmailto\Facades\Smartmailto;
use Agavesoft\Smartmailto\Identity;
use Agavesoft\Smartmailto\Outbox\Outbox;
use Agavesoft\Smartmailto\Outbox\OutboxWorker;
use Agavesoft\Smartmailto\Pull\PullContact;
use Agavesoft\Smartmailto\Pull\PullEvent;
use Agavesoft\Smartmailto\Pull\PullSignature;
use Agavesoft\Smartmailto\Tests\Fixtures\InMemoryPullResolver;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

beforeEach(fn () => config(['smartmailto.queue' => false]));

test('track manda identidad, event_id, secrets, object y occurred_at', function () {
    Http::fake(['smartmailto.test/api/track' => Http::response(['id' => 10], 202)]);

    Smartmailto::track(
        'orden_pagada',
        Identity::guest('ana@example.com'),
        ['timbres' => 50, 'total' => 199.0],
        eventId: 'ff:orden_pagada:100',
        secrets: ['activation_url' => 'https://ff.mx/activar?t=abc'],
        object: ['order', 100],
        occurredAt: Carbon::parse('2026-09-01T10:00:00-06:00'),
    );

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/track'
        && $request->hasHeader('Authorization', 'Bearer mf_live_test')
        && $request->data() === [
            'email' => 'ana@example.com',
            'event' => 'orden_pagada',
            'event_id' => 'ff:orden_pagada:100',
            'properties' => ['timbres' => 50, 'total' => 199.0],
            'secrets' => ['activation_url' => 'https://ff.mx/activar?t=abc'],
            'object' => ['type' => 'order', 'id' => '100'],
            'occurred_at' => '2026-09-01T10:00:00-06:00',
        ]);
});

test('identify de una cuenta con su correo une al invitado', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    Smartmailto::identify(Identity::user(7, 'Ana@Example.com '), ['name' => 'Ana']);

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/identify')
        && $request->data() === ['user_id' => '7', 'email' => 'Ana@Example.com', 'attributes' => ['name' => 'Ana']]);
});

test('send manda la idempotency key en el cuerpo y en el header', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo-compra', Identity::user(7, 'ana@example.com'), ['total' => 100], idempotencyKey: 'ff:recibo:55');

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/send')
        && $request->hasHeader('Idempotency-Key', 'ff:recibo:55')
        && $request['idempotency_key'] === 'ff:recibo:55'
        && $request['template'] === 'recibo-compra');
});

test('el lote de carga inicial se parte en grupos de 100 con backfill', function () {
    Http::fake(['*' => Http::response(['results' => []], 207)]);

    $batch = Smartmailto::batch(backfill: true);
    foreach (range(1, 150) as $i) {
        $batch->track('orden_pagada', Identity::guest("p{$i}@example.com"), eventId: "ff:orden_pagada:{$i}");
    }
    $batch->dispatch();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request) => $request['backfill'] === true && count($request['items']) === 100 && $request['items'][0]['type'] === 'track');
});

test('track sin event_id y send sin idempotency key se rechazan antes de salir', function () {
    Http::fake();

    expect(fn () => Smartmailto::track('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'eventId');
    expect(fn () => Smartmailto::send('x', Identity::guest('a@example.com')))->toThrow(InvalidArgumentException::class, 'idempotencyKey');
    Http::assertNothingSent();
});

test('una identidad necesita id o correo', function () {
    expect(fn () => Identity::user(''))->toThrow(InvalidArgumentException::class)
        ->and(fn () => Identity::guest(' '))->toThrow(InvalidArgumentException::class);
});

test('send con sendBefore manda send_before (F-008)', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), [], idempotencyKey: 'ff:recibo:1',
        sendBefore: Carbon::parse('2026-10-05T12:10:00-06:00'));

    Http::assertSent(fn (Request $request) => $request['send_before'] === '2026-10-05T12:10:00-06:00');
});

test('health consulta la salud del proyecto (F-008)', function () {
    Http::fake(['smartmailto.test/api/health' => Http::response(['status' => 'degraded'], 200)]);

    expect(Smartmailto::health())->toBe(['status' => 'degraded']);
});

test('contact consulta por id y forget borra por correo (F-006)', function () {
    Http::fake([
        'smartmailto.test/api/contacts?user_id=7' => Http::response(['id' => 1, 'email' => 'a@example.com'], 200),
        'smartmailto.test/api/contacts?email=*' => Http::response(['erased' => true], 200),
    ]);

    expect(Smartmailto::contact(Identity::user(7, 'a@example.com')))->toBe(['id' => 1, 'email' => 'a@example.com'])
        ->and(Smartmailto::forget(Identity::guest('a@example.com')))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE' && $request->url() === 'https://smartmailto.test/api/contacts?email=a%40example.com');
});

test('contact y forget de alguien que no existe', function () {
    Http::fake(['*' => Http::response(['error' => 'contact_not_found'], 404)]);

    expect(Smartmailto::contact(Identity::guest('x@example.com')))->toBeNull()
        ->and(Smartmailto::forget(Identity::guest('x@example.com')))->toBeFalse();
});

// F-008 (B3): envio completo para Factura Facilita. Espejo de tests/Feature/F008 del servidor y de
// docs/api-envio-y-aprovisionamiento.md de notificabot-lara-mailflow.

const PDF = "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF";
const XML = '<?xml version="1.0"?><cfdi:Comprobante/>';

test('send sin opciones B3 manda exactamente el cuerpo de antes (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);

    Smartmailto::send('recibo', Identity::user(7, 'a@example.com'), ['folio' => 'A-9'], 'ff:recibo:9');

    Http::assertSent(fn (Request $request) => $request->data() === [
        'user_id' => '7',
        'email' => 'a@example.com',
        'template' => 'recibo',
        'data' => ['folio' => 'A-9'],
        'idempotency_key' => 'ff:recibo:9',
    ]);
});

test('send con adjuntos, to, cc, bcc, reply_to, from y secrets (F-008 B3)', function () {
    Http::fake(['smartmailto.test/api/send' => Http::response(['id' => 812], 202)]);
    $pdf = tempnam(sys_get_temp_dir(), 'sm');
    file_put_contents($pdf, PDF);

    Smartmailto::send('cfdi', Identity::user(7, 'ana@example.com'), ['folio' => 'A-9'], 'ff:cfdi:9',
        sendBefore: Carbon::parse('2026-10-07T18:30:00Z'),
        attachments: [
            Attachment::fromPath($pdf, 'RFC_SERIEFOLIO.PDF'),
            Attachment::fromData(XML, 'RFC_SERIEFOLIO.xml', 'text/xml'),
        ],
        cc: ['cc@example.com'],
        bcc: [' auditoria@example.com '],
        replyTo: ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
        to: ['contador@example.com'],
        from: 'soporte@facturafacilita.com',
        secrets: ['reset_url' => 'https://ff.mx/reset?t=abc'],
    );

    Http::assertSent(fn (Request $request) => $request->hasHeader('Idempotency-Key', 'ff:cfdi:9') && $request->data() === [
        'user_id' => '7',
        'email' => 'ana@example.com',
        'template' => 'cfdi',
        'data' => ['folio' => 'A-9'],
        'idempotency_key' => 'ff:cfdi:9',
        'send_before' => '2026-10-07T18:30:00+00:00',
        'to' => ['contador@example.com'],
        'cc' => ['cc@example.com'],
        'bcc' => ['auditoria@example.com'],
        'reply_to' => ['email' => 'soporte@facturafacilita.com', 'name' => 'Soporte'],
        'from' => ['email' => 'soporte@facturafacilita.com'],
        'attachments' => [
            ['filename' => 'RFC_SERIEFOLIO.PDF', 'content' => base64_encode(PDF), 'content_type' => 'application/pdf'],
            ['filename' => 'RFC_SERIEFOLIO.xml', 'content' => base64_encode(XML), 'content_type' => 'text/xml'],
        ],
        'secrets' => ['reset_url' => 'https://ff.mx/reset?t=abc'],
    ]);
});

test('un adjunto puede ser una ruta o un archivo subido (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['id' => 1], 202)]);
    $upload = UploadedFile::fake()->createWithContent('Comprobante.pdf', PDF);
    $path = sys_get_temp_dir().'/smartmailto-'.uniqid().'.xml';
    file_put_contents($path, XML);

    Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'ff:recibo:1', attachments: [$upload, $path]);

    Http::assertSent(fn (Request $request) => $request['attachments'] === [
        ['filename' => 'Comprobante.pdf', 'content' => base64_encode(PDF), 'content_type' => 'application/pdf'],
        ['filename' => basename($path), 'content' => base64_encode(XML), 'content_type' => 'application/xml'],
    ]);
});

test('los adjuntos fuera del contrato se rechazan antes de salir (F-008 B3)', function () {
    Http::fake();
    $send = fn (array $attachments) => fn () => Smartmailto::send('recibo', Identity::guest('a@example.com'), idempotencyKey: 'k', attachments: $attachments);

    expect($send([Attachment::fromData(str_repeat('a', 7 * 1024 * 1024 - 10), 'a.txt'), Attachment::fromData(str_repeat('b', 11), 'b.txt')]))
        ->toThrow(InvalidArgumentException::class, 'limit')
        ->and($send(array_fill(0, 11, Attachment::fromData(PDF, 'a.pdf'))))->toThrow(InvalidArgumentException::class, 'at most 10')
        ->and(fn () => Attachment::fromData('x', 'malware.exe'))->toThrow(InvalidArgumentException::class, 'not allowed')
        ->and(fn () => Attachment::fromData('x', '../etc/passwd.txt'))->toThrow(InvalidArgumentException::class, 'plain file name')
        ->and(fn () => Attachment::fromData(PDF, 'a.pdf', 'text/plain'))->toThrow(InvalidArgumentException::class, 'does not match')
        ->and(fn () => Attachment::fromPath('/no/existe.pdf'))->toThrow(InvalidArgumentException::class, 'not readable')
        ->and(fn () => Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', cc: array_fill(0, 11, 'x@example.com')))
        ->toThrow(InvalidArgumentException::class, 'cc')
        ->and(fn () => Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', cc: [['email' => 'x@example.com']]))
        ->toThrow(InvalidArgumentException::class, 'list of email strings')
        // Firma del archivo, igual que el servidor: una pagina de error con nombre .pdf no sale.
        ->and(fn () => Attachment::fromData('<html>error</html>', 'factura.pdf'))->toThrow(InvalidArgumentException::class, 'does not look like')
        ->and(fn () => Attachment::fromData("PK\x03\x04", 'a.zip'))->not->toThrow(InvalidArgumentException::class)
        ->and(fn () => Attachment::fromData("a\0b", 'a.xml'))->toThrow(InvalidArgumentException::class, 'does not look like');

    // Un archivo que solo ya rebasa el limite no se carga en memoria.
    $big = sys_get_temp_dir().'/smartmailto-'.uniqid().'.txt';
    file_put_contents($big, 'abc');
    config(['smartmailto.attachments.max_bytes' => 2]);
    expect(fn () => Attachment::fromPath($big))->toThrow(InvalidArgumentException::class, 'over the 2 bytes')
        ->and(fn () => Attachment::fromUpload(UploadedFile::fake()->createWithContent('a.txt', 'abc')))->toThrow(InvalidArgumentException::class, 'over the 2 bytes');

    // El limite es configurable para seguir al del servidor.
    config(['smartmailto.attachments.max_bytes' => 10]);
    expect($send([Attachment::fromData(PDF, 'a.pdf')]))->toThrow(InvalidArgumentException::class, 'limit');

    Http::assertNothingSent();
});

test('con enabled=false send no lee los adjuntos (F-008 B3)', function () {
    config(['smartmailto.enabled' => false]);
    Http::fake();

    expect(Smartmailto::send('r', Identity::guest('a@example.com'), idempotencyKey: 'k', attachments: ['/no/existe.pdf']))->toBeNull();
    Http::assertNothingSent();
});

test('templates lista y putTemplate manda solo lo que se pasa (F-008 B3)', function () {
    Http::fake([
        'smartmailto.test/api/templates/*' => Http::response(['result' => 'created', 'name' => 'recibo'], 201),
        'smartmailto.test/api/templates' => Http::response(['templates' => [['name' => 'recibo', 'checksum' => 'abc']]], 200),
    ]);

    expect(Smartmailto::templates())->toBe([['name' => 'recibo', 'checksum' => 'abc']])
        ->and(Smartmailto::putTemplate('recibo', 'Tu recibo {{data:folio}}', '<p>Hola</p>{{> pie}}', kind: 'transactional'))
        ->toBe(['result' => 'created', 'name' => 'recibo']);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://smartmailto.test/api/templates/recibo'
        && $request->hasHeader('Authorization', 'Bearer mf_live_test')
        && $request->data() === ['subject' => 'Tu recibo {{data:folio}}', 'body' => '<p>Hola</p>{{> pie}}', 'kind' => 'transactional']);
});

test('putPartial y putWorkflow (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['result' => 'unchanged'], 200)]);

    Smartmailto::putPartial('pie', '<p>FF</p>', description: 'Pie');
    Smartmailto::putWorkflow('checkout', "name: checkout\n");
    Smartmailto::putWorkflow('activacion', ['name' => 'activacion', 'steps' => []]);

    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://smartmailto.test/api/partials/pie'
        && $request->data() === ['body' => '<p>FF</p>', 'description' => 'Pie']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/workflows/checkout'
        && $request->data() === ['definition' => "name: checkout\n", 'format' => 'yaml']);
    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/workflows/activacion'
        && $request->data() === ['definition' => ['name' => 'activacion', 'steps' => []]]);
});

test('aprovisionamiento apagado en el proyecto se rechaza sin reintento (F-008 B3)', function () {
    Http::fake(['*' => Http::response(['error' => 'provisioning_disabled'], 403)]);

    expect(fn () => Smartmailto::putPartial('pie', 'x'))->toThrow(function (SmartmailtoException $e) {
        expect($e->transient)->toBeFalse()->and($e->status)->toBe(403)->and($e->response)->toBe(['error' => 'provisioning_disabled']);
    });
});

// ─── F-009: catalogo de variables, activacion y paquete atomico ─────────
// Espejo de tests/Feature/F009/CatalogoTest.php del servidor.

test('catalogo: variables, putVariable, obsoleteVariable, deleteVariable y variableUsages (F-009)', function () {
    Http::fake([
        'smartmailto.test/api/variables?*' => Http::response(['data' => [['scope' => 'event', 'key' => 'orden', 'event' => 'orden_creada']]], 200),
        'smartmailto.test/api/variables/event/orden/usages*' => Http::response(['usages' => [['type' => 'template', 'name' => 'checkout-2', 'location' => 'body']]], 200),
        'smartmailto.test/api/variables/event/orden/obsolete*' => Http::response(['result' => 'obsolete', 'status' => 'obsolete'], 200),
        'smartmailto.test/api/variables/contact/plan' => Http::response(['result' => 'created', 'key' => 'plan', 'warnings' => []], 201),
        'smartmailto.test/api/variables/event/orden?event=orden_creada' => Http::response(null, 204),
    ]);

    expect(Smartmailto::variables('event', 'orden_creada'))->toBe([['scope' => 'event', 'key' => 'orden', 'event' => 'orden_creada']])
        ->and(Smartmailto::putVariable('contact', 'plan', ['type' => 'enum', 'allowed_values' => ['free', 'pro'], 'description' => 'Plan', 'filterable' => true]))
        ->toBe(['result' => 'created', 'key' => 'plan', 'warnings' => []])
        ->and(Smartmailto::obsoleteVariable('event', 'orden', 'orden_creada'))->toBe(['result' => 'obsolete', 'status' => 'obsolete'])
        ->and(Smartmailto::variableUsages('event', 'orden', 'orden_creada'))->toBe([['type' => 'template', 'name' => 'checkout-2', 'location' => 'body']])
        ->and(Smartmailto::deleteVariable('event', 'orden', 'orden_creada'))->toBeTrue();

    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://smartmailto.test/api/variables?scope=event&event=orden_creada');
    Http::assertSent(fn (Request $request) => $request->method() === 'PUT'
        && $request->url() === 'https://smartmailto.test/api/variables/contact/plan'
        && $request->data() === ['type' => 'enum', 'allowed_values' => ['free', 'pro'], 'description' => 'Plan', 'filterable' => true]);
    // `?event=` en obsolete, usages y delete: sin el, una variable de evento da 404.
    Http::assertSent(fn (Request $request) => $request->method() === 'POST'
        && $request->url() === 'https://smartmailto.test/api/variables/event/orden/obsolete?event=orden_creada');
    Http::assertSent(fn (Request $request) => $request->method() === 'GET'
        && $request->url() === 'https://smartmailto.test/api/variables/event/orden/usages?event=orden_creada');
    Http::assertSent(fn (Request $request) => $request->method() === 'DELETE'
        && $request->url() === 'https://smartmailto.test/api/variables/event/orden?event=orden_creada');
});

test('una variable en uso no se borra: la excepcion expone usages (F-009 RN-2)', function () {
    Http::fake([
        'smartmailto.test/api/variables/contact/plan' => Http::response(['error' => 'in_use', 'usages' => [['type' => 'template', 'name' => 'bienvenida', 'location' => 'body']]], 409),
        'smartmailto.test/api/variables/contact/nadie' => Http::response(['error' => 'not_found'], 404),
    ]);

    expect(fn () => Smartmailto::deleteVariable('contact', 'plan'))->toThrow(function (SmartmailtoException $e) {
        expect($e->transient)->toBeFalse()
            ->and($e->status)->toBe(409)
            ->and($e->error())->toBe('in_use')
            ->and($e->getMessage())->toContain('409 in_use')
            ->and($e->usages())->toBe([['type' => 'template', 'name' => 'bienvenida', 'location' => 'body']])
            ->and($e->items())->toBe([]);
    });
    expect(Smartmailto::deleteVariable('contact', 'nadie'))->toBeFalse();
});

test('activateTemplate y activateWorkflow; un rechazo expone items (F-009 RN-4)', function () {
    Http::fake([
        'smartmailto.test/api/templates/recibo/activate' => Http::response(['result' => 'activated', 'status' => 'active'], 200),
        'smartmailto.test/api/workflows/checkout/activate' => Http::response(['error' => 'invalid_references', 'items' => [
            ['type' => 'template', 'name' => 'checkout-2', 'code' => 'unknown_variable', 'ref' => 'data:totl', 'location' => 'body'],
        ]], 422),
    ]);

    expect(Smartmailto::activateTemplate('recibo'))->toBe(['result' => 'activated', 'status' => 'active']);
    expect(fn () => Smartmailto::activateWorkflow('checkout'))->toThrow(function (SmartmailtoException $e) {
        expect($e->error())->toBe('invalid_references')
            ->and($e->items())->toBe([['type' => 'template', 'name' => 'checkout-2', 'code' => 'unknown_variable', 'ref' => 'data:totl', 'location' => 'body']]);
    });

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://smartmailto.test/api/templates/recibo/activate');
});

test('provisionPackage manda el paquete y activate; provision_failed expone items y warnings (F-009 RN-16)', function () {
    $package = [
        'variables' => [['scope' => 'event', 'key' => 'orden', 'event' => 'orden_creada', 'type' => 'string', 'description' => 'Folio']],
        'templates' => [['name' => 'checkout-2', 'subject' => 'Tu orden', 'body' => 'Orden {{data:orden}}']],
    ];
    Http::fake(['smartmailto.test/api/provision' => Http::sequence()
        ->push(['results' => [['type' => 'template', 'name' => 'checkout-2', 'result' => 'created', 'status' => 'draft']], 'warnings' => []], 200)
        ->push(['error' => 'provision_failed', 'items' => [['type' => 'workflow', 'name' => 'checkout', 'code' => 'unknown_variable', 'ref' => 'data:no_existe']],
            'warnings' => [['type' => 'variable', 'name' => 'contact:telefono', 'code' => 'sensitive_kept']]], 422),
    ]);

    expect(Smartmailto::provisionPackage($package)['results'][0]['status'])->toBe('draft');
    expect(fn () => Smartmailto::provisionPackage($package, activate: true))->toThrow(function (SmartmailtoException $e) {
        expect($e->error())->toBe('provision_failed')
            ->and($e->items()[0]['ref'])->toBe('data:no_existe')
            ->and($e->warnings()[0]['code'])->toBe('sensitive_kept');
    });

    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->data() === [...$package, 'activate' => false]);
    Http::assertSent(fn (Request $request) => $request->data() === [...$package, 'activate' => true]);
});

test('validatePackage devuelve valid=false sin lanzar y schema lee el esquema (F-009)', function () {
    Http::fake([
        'smartmailto.test/api/validate' => Http::response(['valid' => false, 'errors' => [['code' => 'unknown_variable']], 'warnings' => [], 'results' => []], 200),
        'smartmailto.test/api/schema' => Http::response(['version' => '2026-10-08', 'variables' => ['contact' => []]], 200),
    ]);

    expect(Smartmailto::validatePackage(['templates' => [['name' => 'a', 'subject' => 's', 'body' => '{{contact:nombre}}']]], activate: true))
        ->toMatchArray(['valid' => false, 'errors' => [['code' => 'unknown_variable']]])
        ->and(Smartmailto::schema()['version'])->toBe('2026-10-08');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/validate' && $request->data()['activate'] === true);
});

test('con enabled=false el catalogo no sale a la red (F-009)', function () {
    config(['smartmailto.enabled' => false]);
    Http::fake();

    expect(Smartmailto::variables())->toBeNull()
        ->and(Smartmailto::putVariable('contact', 'plan', ['type' => 'string']))->toBeNull()
        ->and(Smartmailto::deleteVariable('contact', 'plan'))->toBeFalse()
        ->and(Smartmailto::provisionPackage([]))->toBeNull()
        ->and(Smartmailto::schema())->toBeNull();

    Http::assertNothingSent();
});

// ─── F-011: contrato de pull v1 ─────────────────────────────────────────
// Espejo de tests/Feature/F011/PullTest.php del servidor (App\Services\Pull\PullSignature y PullClient).

test('las firmas del pull coinciden con los vectores fijos del servidor (F-011)', function () {
    expect(PullSignature::request('pullsec_vector', 1700000000, '{"version":1,"op":"contacts"}'))
        ->toBe('sha256=cb63e97e98d2264be129646d56d69a3cfac8f5e39d8ad573641c924123ba1839')
        ->and(PullSignature::response('pullsec_vector', 1700000000, '7f1c1f0e-0000-4000-8000-000000000001', '{"version":1,"contacts":[]}'))
        ->toBe('sha256=b7d202a022135daf05aa90304060d420c84efb668d639c227fcd30f72b924e83');
});

test('la ruta acepta la peticion exacta del servidor y contesta el cuerpo que el servidor procesa (F-011)', function () {
    Carbon::setTestNow('2026-10-08 12:00:00');
    $resolver = new InMemoryPullResolver;
    $resolver->rows = [new PullContact(Identity::user(123, 'a@b.mx'), Carbon::parse('2026-10-07T21:10:00Z'),
        contactSince: Carbon::parse('2024-03-01T10:00:00Z'),
        attributes: ['last_purchase_at' => '2026-09-30', 'fuera_de_catalogo' => 1],
        events: [new PullEvent('ff:orden_pagada:991', 'orden_pagada', Carbon::parse('2026-09-30T10:00:00Z'), ['total' => 100], ['order', 991])])];
    $resolver->deleted = [Identity::user(77)];
    config(['smartmailto.pull.secret' => 'pullsec_contrato']);
    Smartmailto::fakePull($resolver, catalog: ['last_purchase_at']);

    // Cuerpo y headers como los arma PullClient::call() (RunPullJob: op contacts, limit 200).
    $body = '{"version":1,"op":"contacts","updated_since":null,"cursor":null,"limit":200,"include":["attributes","events"],"project_id":12}';
    $timestamp = now()->getTimestamp();
    $response = $this->call('POST', '/api/smartmailto/pull', server: [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_USER_AGENT' => 'Smartmailto-Pull/1',
        'HTTP_X_SMARTMAILTO_TIMESTAMP' => (string) $timestamp,
        'HTTP_X_SMARTMAILTO_REQUEST' => '7f1c1f0e-0000-4000-8000-000000000001',
        'HTTP_X_SMARTMAILTO_SIGNATURE' => 'sha256='.hash_hmac('sha256', $timestamp.'.'.$body, 'pullsec_contrato'),
    ], content: $body);

    $raw = $response->getContent();
    expect($response->status())->toBe(200)
        ->and($raw)->toBe('{"version":1,"contacts":[{"user_id":"123","email":"a@b.mx","contact_since":"2024-03-01T10:00:00Z","updated_at":"2026-10-07T21:10:00Z","unsubscribed":false,"attributes":{"last_purchase_at":"2026-09-30"},"events":[{"event_id":"ff:orden_pagada:991","event":"orden_pagada","occurred_at":"2026-09-30T10:00:00Z","properties":{"total":100},"object":{"type":"order","id":"991"}}]}],"deleted":[{"user_id":"77"}],"next_cursor":null}')
        // Verificacion del servidor: "{ts}.{request_id}.{cuerpo}" con el mismo secreto.
        ->and($response->headers->get('X-Smartmailto-Signature'))
        ->toBe('sha256='.hash_hmac('sha256', $response->headers->get('X-Smartmailto-Timestamp').'.7f1c1f0e-0000-4000-8000-000000000001.'.$raw, 'pullsec_contrato'));

    Carbon::setTestNow();
});

test('identify manda updated_at (hora de cambio en la app) solo si se da (F-011 R-11)', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    Smartmailto::identify(Identity::user(7, 'a@example.com'), ['plan' => 'pro'], Carbon::parse('2026-10-07T21:10:00-06:00'));
    Smartmailto::batch()->identify(Identity::guest('b@example.com'), [], Carbon::parse('2026-10-07T03:00:00Z'))->dispatch();

    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/identify')
        && $request->data() === ['user_id' => '7', 'email' => 'a@example.com', 'attributes' => ['plan' => 'pro'], 'updated_at' => '2026-10-07T21:10:00-06:00']);
    Http::assertSent(fn (Request $request) => str_ends_with($request->url(), '/api/batch')
        && $request['items'][0] === ['type' => 'identify', 'email' => 'b@example.com', 'attributes' => [], 'updated_at' => '2026-10-07T03:00:00+00:00']);
});

test('el fake registra catalogo, paquetes y activaciones sin red (F-009)', function () {
    Http::fake();
    $fake = Smartmailto::fake();
    $fake->variablesResponse = [['scope' => 'contact', 'key' => 'plan'], ['scope' => 'event', 'key' => 'orden', 'event' => 'orden_creada']];

    Smartmailto::putVariable('event', 'orden', ['type' => 'string', 'description' => 'Folio'], 'orden_creada');
    Smartmailto::provisionPackage(['templates' => []], activate: true);
    Smartmailto::validatePackage(['templates' => []]);
    Smartmailto::activateWorkflow('checkout');

    $fake->assertVariablePut('event', 'orden', fn (array $definition, ?string $event) => $event === 'orden_creada' && $definition['type'] === 'string');
    $fake->assertPackageProvisioned(fn (array $package, bool $activate) => $activate);
    $fake->assertActivated('workflow', 'checkout');
    expect(Smartmailto::variables('contact'))->toBe([['scope' => 'contact', 'key' => 'plan']])
        ->and($fake->packages)->toHaveCount(2);
    Http::assertNothingSent();
});

// ─── F-010: eventos garantizados ────────────────────────────────────────
// Espejo de tests/Feature/F010 del servidor y de docs/api-envio-y-aprovisionamiento.md §2b
// (consulta, reporte, heartbeat) de notificabot-lara-mailflow.

test('identify con consent manda consent: true solo si se da (F-010 D7)', function () {
    Http::fake(['*' => Http::response(['id' => 1], 200)]);

    Smartmailto::identify(Identity::user(7, 'a@example.com'), [], consent: true);
    Smartmailto::identify(Identity::user(8, 'b@example.com'));

    Http::assertSent(fn (Request $request) => $request->data() === ['user_id' => '7', 'email' => 'a@example.com', 'attributes' => [], 'consent' => true]);
    Http::assertSent(fn (Request $request) => $request->data() === ['user_id' => '8', 'email' => 'b@example.com', 'attributes' => []]);
});

test('link manda POST /api/contacts/link con survivor, absorbed, reason y link_id (F-010 J8)', function () {
    Http::fake(['smartmailto.test/api/contacts/link' => Http::response(['link_id' => 'ff:link:order:555', 'duplicate' => false, 'contact' => ['id' => 1, 'user_id' => '7']], 200)]);

    expect(Smartmailto::link(Identity::user(7), Identity::guest('compra@x.com'), 'timbres-en-otra-cuenta', 'ff:link:order:555'))
        ->toBe(['link_id' => 'ff:link:order:555', 'duplicate' => false, 'contact' => ['id' => 1, 'user_id' => '7']]);

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/contacts/link' && $request->data() === [
        'survivor' => ['user_id' => '7'],
        'absorbed' => ['email' => 'compra@x.com'],
        'reason' => 'timbres-en-otra-cuenta',
        'link_id' => 'ff:link:order:555',
    ]);
    expect(fn () => Smartmailto::link(Identity::user(7), Identity::guest('a@x.com'), 'x', ' '))->toThrow(InvalidArgumentException::class, 'linkId');
});

test('send a un destinatario externo manda recipient_kind y origin (F-010 R2)', function () {
    Http::fake(['*' => Http::response(['ack' => true, 'idempotency_key' => 'ff:cfdi:9', 'id' => 5, 'duplicate' => false], 202)]);

    Smartmailto::send('cfdi-documentos', Identity::external('receptor@cliente.mx'), ['folio' => 'A-9'], 'ff:cfdi:9', origin: Identity::user(7));

    Http::assertSent(fn (Request $request) => $request->data() === [
        'email' => 'receptor@cliente.mx',
        'template' => 'cfdi-documentos',
        'data' => ['folio' => 'A-9'],
        'idempotency_key' => 'ff:cfdi:9',
        'recipient_kind' => 'external',
        'origin' => ['user_id' => '7'],
    ]);
});

test('un destinatario externo nunca es identidad de track, identify ni link (F-010 R2)', function () {
    Http::fake();
    $external = Identity::external('receptor@cliente.mx');

    expect(fn () => Smartmailto::track('x', $external, eventId: 'k'))->toThrow(InvalidArgumentException::class, 'external')
        ->and(fn () => Smartmailto::identify($external))->toThrow(InvalidArgumentException::class, 'external')
        ->and(fn () => Smartmailto::link(Identity::user(1), $external, 'x', 'k'))->toThrow(InvalidArgumentException::class, 'external')
        ->and(fn () => Smartmailto::send('x', Identity::user(1), [], 'k', origin: $external))->toThrow(InvalidArgumentException::class, 'origin')
        ->and(fn () => Identity::external(' '))->toThrow(InvalidArgumentException::class);
    Http::assertNothingSent();
});

test('adjunto por URL: {filename, content_type, url, sha256} sin content (F-010 J12)', function () {
    Http::fake(['*' => Http::response(['id' => 5], 202)]);
    $sha = hash('sha256', PDF);

    Smartmailto::send('cfdi', Identity::user(7), [], 'ff:cfdi:1', attachments: [Attachment::fromUrl('https://files.ff.mx/a.pdf?sig=x', 'RFC_UUID.pdf', strtoupper($sha))]);

    Http::assertSent(fn (Request $request) => $request['attachments'] === [
        ['filename' => 'RFC_UUID.pdf', 'content_type' => 'application/pdf', 'url' => 'https://files.ff.mx/a.pdf?sig=x', 'sha256' => $sha],
    ]);
    expect(fn () => Attachment::fromUrl('http://files.ff.mx/a.pdf', 'a.pdf', $sha))->toThrow(InvalidArgumentException::class, 'https')
        ->and(fn () => Attachment::fromUrl('https://files.ff.mx/a.pdf', 'a.pdf', 'abc'))->toThrow(InvalidArgumentException::class, 'sha256');
});

test('reportExternalSend sin outbox manda POST /api/send/external con el cuerpo del contrato (F-010 regla 17.4)', function () {
    Http::fake(['smartmailto.test/api/send/external' => Http::response(['ack' => true, 'idempotency_key' => 'ff:recibo:555', 'received_at' => '2026-10-08T12:10:00Z', 'id' => 812, 'duplicate' => false, 'status' => 'sent_externally'], 201)]);

    Smartmailto::reportExternalSend('ff:recibo:555', Identity::user(123, 'ana@ff.mx'), 'recibo', Carbon::parse('2026-10-08T12:10:00Z'), reason: 'expired');

    Http::assertSent(fn (Request $request) => $request->url() === 'https://smartmailto.test/api/send/external' && $request->data() === [
        'idempotency_key' => 'ff:recibo:555', 'template' => 'recibo', 'user_id' => '123', 'email' => 'ana@ff.mx',
        'sent_at' => '2026-10-08T12:10:00+00:00', 'channel' => 'ses_direct', 'reason' => 'expired',
    ]);
    expect(fn () => Smartmailto::reportExternalSend('ff:recibo:1'))->toThrow(InvalidArgumentException::class, 'identity and the template');
});

test('la consulta previa codifica cada segmento de la llave y conserva la diagonal; heartbeat por POST (F-010 regla 17.3 y 25)', function () {
    config(['smartmailto.outbox.enabled' => true]);
    (require __DIR__.'/../../database/migrations/2026_10_08_000000_create_smartmailto_outbox_tables.php')->up();
    Http::fake([
        'smartmailto.test/api/send/ff%3Arecibo/2026%2010/1' => Http::response(['idempotency_key' => 'ff:recibo/2026 10/1', 'status' => 'sent', 'sent_at' => '2026-10-08T12:00:02Z'], 200),
        'smartmailto.test/api/outbox/heartbeat' => Http::response(['ok' => true, 'received_at' => '2026-10-08T12:00:00Z'], 200),
        '*' => Http::response(['message' => 'down'], 503),
    ]);

    Smartmailto::send('recibo', Identity::user(7), [], 'ff:recibo/2026 10/1', now()->addMinutes(10));
    Carbon::setTestNow(now()->addMinutes(10));
    app(OutboxWorker::class)->runOnce();
    Carbon::setTestNow();

    expect(app(Outbox::class)->table()->value('status'))->toBe('acked');
    Http::assertSent(fn (Request $request) => $request->method() === 'POST' && $request->url() === 'https://smartmailto.test/api/outbox/heartbeat' && $request->data() === []);
});

test('Fase 3: versiones de plantilla, fijar, promover y rollback en el ambiente de la llave', function () {
    Http::fake([
        'smartmailto.test/api/templates/recibo/revisions/activate' => Http::response(['action' => 'activate', 'environment' => 'dev', 'revision' => 2, 'previous_revision' => null], 200),
        'smartmailto.test/api/templates/recibo/revisions' => Http::response(['template' => 'recibo', 'environment' => 'dev', 'effective' => 2, 'environments' => [], 'revisions' => [['number' => 2], ['number' => 1]]], 200),
        'smartmailto.test/api/templates/recibo/promote' => Http::response(['action' => 'promote', 'environment' => 'pro', 'revision' => 2, 'from_environment' => 'dev'], 200),
        'smartmailto.test/api/templates/recibo/rollback' => Http::response(['error' => 'nothing_to_rollback'], 422),
    ]);

    expect(Smartmailto::templateRevisions('recibo')['effective'])->toBe(2)
        ->and(Smartmailto::activateTemplateRevision('recibo', 2)['revision'])->toBe(2)
        ->and(Smartmailto::promoteTemplate('recibo', 'dev')['from_environment'])->toBe('dev');
    expect(fn () => Smartmailto::rollbackTemplate('recibo'))->toThrow(fn (SmartmailtoException $e) => expect($e->error())->toBe('nothing_to_rollback'));

    Http::assertSent(fn (Request $r) => $r->url() === 'https://smartmailto.test/api/templates/recibo/revisions/activate' && $r['revision'] === 2);
    Http::assertSent(fn (Request $r) => $r->url() === 'https://smartmailto.test/api/templates/recibo/promote' && $r['from'] === 'dev');
});

test('Fase 4: senders() lista los remitentes del ambiente de la llave', function () {
    Http::fake(['smartmailto.test/api/senders' => Http::response(['environment' => 'dev', 'data' => [['id' => 7, 'source' => 'account', 'revoked' => true]]], 200)]);

    expect(Smartmailto::senders())->toBe(['environment' => 'dev', 'data' => [['id' => 7, 'source' => 'account', 'revoked' => true]]]);
    Http::assertSent(fn (Request $r) => $r->method() === 'GET' && $r->url() === 'https://smartmailto.test/api/senders');
});
