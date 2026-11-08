<?php

namespace DzWallet;

use DzWallet\Http\IpnController;
use DzWallet\Http\VerifyDzWalletSignature;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

class DzWalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/dzwallet.php', 'dzwallet');

        $this->app->singleton(DzWalletClient::class, function ($app) {
            return new DzWalletClient($app->make(Http::class), $app['config']->get('dzwallet', []));
        });
        $this->app->alias(DzWalletClient::class, 'dzwallet');
    }

    public function boot(): void
    {
        $this->publishes([__DIR__ . '/../config/dzwallet.php' => config_path('dzwallet.php')], 'dzwallet-config');

        $this->app->make(Router::class)->aliasMiddleware('dzwallet.signature', VerifyDzWalletSignature::class);

        if (config('dzwallet.ipn.enabled')) {
            Route::post(config('dzwallet.ipn.path', 'dzwallet/ipn'), IpnController::class)
                ->middleware(config('dzwallet.ipn.middleware', []))
                ->withoutMiddleware([\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::class, \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class])
                ->name('dzwallet.ipn');
        }
    }
}
