<?php

namespace Agavesoft\Smartmailto;

use Agavesoft\Smartmailto\Console\OutboxPruneCommand;
use Agavesoft\Smartmailto\Console\OutboxRetryCommand;
use Agavesoft\Smartmailto\Console\OutboxStatusCommand;
use Agavesoft\Smartmailto\Console\OutboxWorkCommand;
use Agavesoft\Smartmailto\Console\ProvisionCommand;
use Agavesoft\Smartmailto\Http\Middleware\VerifySmartmailtoPull;
use Agavesoft\Smartmailto\Http\Middleware\VerifySmartmailtoWebhook;
use Agavesoft\Smartmailto\Mail\SmartmailtoTransport;
use Agavesoft\Smartmailto\Outbox\Outbox;
use Agavesoft\Smartmailto\Pull\PullRoute;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Support\ServiceProvider;

class SmartmailtoServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/smartmailto.php', 'smartmailto');

        // El cliente se construye solo cuando se usa: con SMARTMAILTO_ENABLED=false nunca se crea y
        // credenciales vacias no rompen el arranque de la app.
        $this->app->bind(SmartmailtoClient::class, fn ($app) => new SmartmailtoClient(
            $app->make(HttpFactory::class),
            $app['config']->get('smartmailto.api_url'),
            $app['config']->get('smartmailto.api_token'),
            (int) $app['config']->get('smartmailto.timeout', 10),
        ));

        $this->app->singleton(Outbox::class, fn ($app) => new Outbox($app));

        $this->app->singleton(Smartmailto::class, fn ($app) => new Smartmailto($app));
        $this->app->alias(Smartmailto::class, 'smartmailto');
    }

    public function boot(): void
    {
        // Fase 0: MAIL_MAILER=smartmailto manda los correos de la app por /api/send (sin plantilla).
        $this->callAfterResolving('mail.manager', function ($manager) {
            $manager->extend('smartmailto', fn (array $config) => new SmartmailtoTransport(
                fn () => $this->app->make(Smartmailto::class),
                $config,
            ));
        });

        // F-008 (B2): middleware opcional para la ruta del webhook de falla. F-011: el de la ruta del pull.
        $this->callAfterResolving('router', function ($router) {
            $router->aliasMiddleware('smartmailto.webhook', VerifySmartmailtoWebhook::class);
            $router->aliasMiddleware('smartmailto.pull', VerifySmartmailtoPull::class);
        });

        // F-011: la ruta del pull solo existe con pull.enabled y un resolver. Despues de arrancar, para ver
        // los bindings de PullResolver que la app registre en sus providers.
        if (! $this->app->routesAreCached()) {
            $this->app->booted(fn () => PullRoute::register($this->app));
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/smartmailto.php' => $this->app->configPath('smartmailto.php'),
            ], 'smartmailto-config');

            // F-010: primera migracion del paquete (outbox). Solo se publica: no se corre sola.
            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => $this->app->databasePath('migrations'),
            ], 'smartmailto-migrations');

            $this->commands([
                ProvisionCommand::class,
                OutboxWorkCommand::class,
                OutboxStatusCommand::class,
                OutboxRetryCommand::class,
                OutboxPruneCommand::class,
            ]);
        }
    }
}
