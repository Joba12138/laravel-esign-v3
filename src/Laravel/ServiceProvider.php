<?php

namespace Joba\Esign\Laravel;

use Joba\Esign\Client;
use Joba\Esign\Laravel\Middleware\VerifyWebhookSignature;

class ServiceProvider extends \Illuminate\Support\ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/esign.php', 'esign');

        $this->app->singleton(Client::class, function ($app) {
            return new Client($app->make('config')->get('esign', []));
        });

        $this->app->alias(Client::class, 'esign');
    }

    public function boot()
    {
        $this->callAfterResolving('router', function ($router) {
            $router->aliasMiddleware('esign.signature', VerifyWebhookSignature::class);
        });

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/esign.php' => $this->app->configPath('esign.php'),
            ], 'esign-config');
        }
    }
}
