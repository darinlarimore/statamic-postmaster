<?php

namespace Darinlarimore\StatamicEmailSubscribe\Tests;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\ContentListRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\ContentSubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\EloquentListRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\EloquentSubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\ServiceProvider;
use Darinlarimore\StatamicEmailSubscribe\Support\Storage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Statamic\Testing\AddonTestCase;

abstract class TestCase extends AddonTestCase
{
    use RefreshDatabase;

    protected string $addonServiceProvider = ServiceProvider::class;

    /**
     * The storage driver under test. Overridden per test via
     * $this->driver(...) or the driver dataset.
     */
    protected string $storageDriver = 'content';

    protected function getEnvironmentSetUp($app)
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', [
            'driver' => 'sqlite',
            'database' => ':memory:',
        ]);

        // The Stache reads/writes its index through the cache store. In tests the
        // default cache is a database table that doesn't exist, so pin it to the
        // in-process array store (single-process tests are always coherent).
        $app['config']->set('cache.default', 'array');
        $app['config']->set('queue.default', 'sync');
        $app['config']->set('statamic.stache.cache_store', 'array');
        $app['config']->set('statamic.stache.watcher', false);
        $app['config']->set('statamic.search.indexes', []);

        $app['config']->set('statamic.editions.pro', true);
        $app['config']->set('email_subscribe.mjml.strategy', 'none');
        $app['config']->set('email_subscribe.webhooks.signing_key', 'test-signing-key');
        $app['config']->set('email_subscribe.storage.driver', $this->storageDriver);
        $app['config']->set('mail.from.address', 'news@example.com');
        $app['config']->set('mail.from.name', 'Example News');

        // Content-driver entries need a writable, per-run temp directory so
        // they don't collide across tests. Blueprints are saved by Statamic into
        // resource_path('blueprints'), which testbench already makes writable.
        $temp = sys_get_temp_dir().'/es-tests-'.getmypid();
        File::ensureDirectoryExists($temp.'/content/collections');

        $app['config']->set('statamic.stache.stores.collections.directory', $temp.'/content/collections');
        $app['config']->set('statamic.stache.stores.entries.directory', $temp.'/content/collections');
        $app['config']->set('view.paths', array_merge(
            $app['config']->get('view.paths', []),
            [__DIR__.'/views'],
        ));

        $this->tempPath = $temp;
    }

    protected string $tempPath = '';

    /**
     * Switch the active storage driver mid-test and rebind the repositories, so
     * a test or dataset can run under either driver.
     */
    public function useDriver(string $driver): void
    {
        config()->set('email_subscribe.storage.driver', $driver);

        $this->app->forgetInstance(SubscriberRepository::class);
        $this->app->forgetInstance(ListRepository::class);

        $this->app->singleton(
            SubscriberRepository::class,
            fn () => $driver === 'database'
                ? new EloquentSubscriberRepository
                : new ContentSubscriberRepository,
        );

        $this->app->singleton(
            ListRepository::class,
            fn () => $driver === 'database'
                ? new EloquentListRepository
                : new ContentListRepository,
        );

        if ($driver === 'content') {
            Storage::bootStorage();
        }
    }

    protected function tearDown(): void
    {
        if ($this->tempPath && is_dir($this->tempPath)) {
            File::deleteDirectory($this->tempPath);
        }

        parent::tearDown();
    }
}
