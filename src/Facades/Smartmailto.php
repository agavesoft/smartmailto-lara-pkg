<?php

namespace Agavesoft\Smartmailto\Facades;

use Agavesoft\Smartmailto\Testing\SmartmailtoFake;
use Illuminate\Support\Facades\Facade;

/**
 * @method static array|null identify(\Agavesoft\Smartmailto\Identity $identity, array $attributes = [], ?\DateTimeInterface $updatedAt = null)
 * @method static \Agavesoft\Smartmailto\Testing\PullTester fakePull(\Agavesoft\Smartmailto\Contracts\PullResolver|string|null $resolver = null, ?array $catalog = null)
 * @method static array|null track(string $event, \Agavesoft\Smartmailto\Identity $identity, array $properties = [], string $eventId = '', array $secrets = [], ?array $object = null, ?\DateTimeInterface $occurredAt = null)
 * @method static array|null send(string $template, \Agavesoft\Smartmailto\Identity $identity, array $data = [], string $idempotencyKey = '', ?\DateTimeInterface $sendBefore = null, array $attachments = [], array $cc = [], array $bcc = [], string|array|null $replyTo = null, array $to = [], string|array|null $from = null, array $secrets = [])
 * @method static array|null templates()
 * @method static array|null senders()
 * @method static array|null putTemplate(string $name, string $subject, string $body, ?string $layout = null, ?string $kind = null, ?string $displayName = null, ?string $description = null)
 * @method static array|null putPartial(string $name, string $body, ?string $description = null)
 * @method static array|null putWorkflow(string $name, string|array $definition, string $format = 'yaml')
 * @method static array|null activateTemplate(string $name)
 * @method static array|null templateRevisions(string $name)
 * @method static array|null activateTemplateRevision(string $name, ?int $revision)
 * @method static array|null promoteTemplate(string $name, string $from)
 * @method static array|null rollbackTemplate(string $name)
 * @method static array|null activateWorkflow(string $name)
 * @method static array|null provisionPackage(array $package, bool $activate = false)
 * @method static array|null validatePackage(array $package, bool $activate = false)
 * @method static array|null variables(?string $scope = null, ?string $event = null)
 * @method static array|null putVariable(string $scope, string $key, array $definition, ?string $event = null)
 * @method static array|null obsoleteVariable(string $scope, string $key, ?string $event = null)
 * @method static bool deleteVariable(string $scope, string $key, ?string $event = null)
 * @method static array|null variableUsages(string $scope, string $key, ?string $event = null)
 * @method static array|null schema()
 * @method static bool verifyWebhook(\Illuminate\Http\Request $request, ?string $secret = null, int $toleranceSeconds = 300)
 * @method static array|null health()
 * @method static array|null contact(\Agavesoft\Smartmailto\Identity $identity)
 * @method static bool forget(\Agavesoft\Smartmailto\Identity $identity)
 * @method static array|null renderedEmail(int $sendId)
 * @method static \Agavesoft\Smartmailto\PendingBatch batch(bool $backfill = false)
 * @method static array|null eventStatus(int $id)
 * @method static bool enabled()
 * @method static bool isConfigured()
 *
 * @see \Agavesoft\Smartmailto\Smartmailto
 */
class Smartmailto extends Facade
{
    /**
     * Reemplaza el SDK por un doble que registra las llamadas sin red (para las pruebas de tu app).
     */
    public static function fake(): SmartmailtoFake
    {
        $app = static::getFacadeApplication();
        $fake = new SmartmailtoFake($app);
        static::swap($fake);
        $app->instance(\Agavesoft\Smartmailto\Smartmailto::class, $fake);

        return $fake;
    }

    protected static function getFacadeAccessor(): string
    {
        return 'smartmailto';
    }
}
