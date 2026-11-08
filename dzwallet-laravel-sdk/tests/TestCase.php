<?php

namespace DzWallet\Tests;

use DzWallet\DzWalletServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    protected function getPackageProviders($app): array
    {
        return [DzWalletServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('dzwallet.base_url', 'https://dzwallet.test');
        $app['config']->set('dzwallet.mode', 'test');
        $app['config']->set('dzwallet.public_key', 'pk_test_123');
        $app['config']->set('dzwallet.secret_key', 'sk_secret_456');
        $app['config']->set('dzwallet.status_retries', 0);
    }
}
