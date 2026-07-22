<?php

namespace Darinlarimore\StatamicEmailSubscribe;

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

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/email_subscribe.php', 'email_subscribe');

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

        $this->bootPermissions();
        $this->bootNav();
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
                ->icon('email');
        });
    }
}
