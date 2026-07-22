<?php

namespace Darinlarimore\StatamicEmailSubscribe;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\ContentListRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\ContentSubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\EloquentListRepository;
use Darinlarimore\StatamicEmailSubscribe\Repositories\EloquentSubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Support\Diagnostics;
use Darinlarimore\StatamicEmailSubscribe\Support\Storage;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Route;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Tags\EmailSubscribeTag::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $commands = [
        Console\Commands\SendScheduledSendouts::class,
        Console\Commands\ImportSubscribers::class,
    ];

    protected $vite = [
        'input' => ['resources/css/cp.css'],
        'publicDirectory' => 'resources/dist',
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/email_subscribe.php', 'email_subscribe');

        Diagnostics::boot();

        $this->app->singleton(SubscriberRepository::class, fn () => $this->driver() === 'database'
            ? new EloquentSubscriberRepository
            : new ContentSubscriberRepository);

        $this->app->singleton(ListRepository::class, fn () => $this->driver() === 'database'
            ? new EloquentListRepository
            : new ContentListRepository);
    }

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email_subscribe.php', 'email_subscribe');

        Diagnostics::boot();

        // Tracking pixels, one-click unsubscribes, and provider webhooks are
        // hit without a browser session - keep them outside the web group so
        // CSRF and cookies never get in the way.
        Route::middleware([
            SubstituteBindings::class,
            'throttle:120,1',
        ])->group(__DIR__.'/../routes/stateless.php');

        $this->publishes([
            __DIR__.'/../config/email_subscribe.php' => config_path('email_subscribe.php'),
        ], 'email-subscribe-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'email-subscribe');

        $this->publishes([
            __DIR__.'/../resources/views/emails' => resource_path('views/vendor/email-subscribe/emails'),
        ], 'email-subscribe-views');

        if ($this->driver() === 'content') {
            Storage::bootStorage();
            $this->guardContentDriver();
        }

        $this->bootRouteBindings();
        $this->bootPermissions();
        $this->bootNav();
    }

    protected function driver(): string
    {
        return config('email_subscribe.storage.driver', 'content');
    }

    /**
     * The content driver's cross-process safety depends on a shared, lock-capable
     * cache store when a real queue worker is in play. On the "sync" queue
     * everything runs in one process, so any cache store is fine.
     */
    protected function guardContentDriver(): void
    {
        $safeStores = ['redis', 'memcached', 'dynamodb', 'database'];
        $cacheStore = config('cache.default');
        $usesWorker = config('queue.default') !== 'sync';

        if ($usesWorker && ! in_array($cacheStore, $safeStores, true)) {
            Diagnostics::unsafeConfig([
                'cache_store' => $cacheStore,
                'queue' => config('queue.default'),
                'advice' => 'Use a shared, lock-capable cache store (redis/database/memcached) with the content driver and a queue worker, or switch to the database storage driver.',
            ]);
        }
    }

    /**
     * Subscribers and lists aren't Eloquent models under the content driver, so
     * bind their route params explicitly. The all-digits branch resolves legacy
     * integer ids embedded in signed URLs delivered before a content cutover.
     */
    protected function bootRouteBindings(): void
    {
        Route::bind('subscriber', function ($value) {
            $repository = $this->app->make(SubscriberRepository::class);

            if (ctype_digit((string) $value) && ($legacy = $repository->findByLegacyId($value))) {
                return $legacy;
            }

            return $repository->findByKey((string) $value) ?? abort(404);
        });

        Route::bind('list', function ($value) {
            $repository = $this->app->make(ListRepository::class);

            if (ctype_digit((string) $value) && ($legacy = $repository->findByLegacyId($value))) {
                return $legacy;
            }

            return $repository->find((string) $value) ?? abort(404);
        });
    }

    protected function bootPermissions()
    {
        Permission::extend(function () {
            Permission::group('email_subscribe', 'Email Subscribe', function () {
                Permission::register('view email subscribe')
                    ->label('View Email Subscribe')
                    ->children([
                        Permission::register('edit email subscribe lists')->label('Manage Lists'),
                        Permission::register('edit email subscribe subscribers')->label('Manage Subscribers'),
                        Permission::register('send email subscribe sendouts')->label('Create & Send Sendouts'),
                    ]);
            });
        });
    }

    protected function bootNav()
    {
        Nav::extend(function ($nav) {
            $nav->create('Email Subscribe')
                ->section('Tools')
                ->can('view email subscribe')
                ->route('email-subscribe.index')
                ->icon('email-utility');
        });
    }
}
