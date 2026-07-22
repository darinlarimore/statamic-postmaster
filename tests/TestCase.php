<?php

namespace Darinlarimore\StatamicEmailSubscribe\Tests;

use Darinlarimore\StatamicEmailSubscribe\ServiceProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('email_subscribe.mjml.strategy', 'none');
        $app['config']->set('email_subscribe.webhooks.signing_key', 'test-signing-key');
        $app['config']->set('mail.from.address', 'news@example.com');
        $app['config']->set('mail.from.name', 'Example News');

        $app['config']->set('view.paths', array_merge(
            $app['config']->get('view.paths', []),
            [__DIR__.'/views'],
        ));
    }
}
