<?php

namespace Darinlarimore\Postmaster;

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Repositories\ContentListRepository;
use Darinlarimore\Postmaster\Repositories\ContentSubscriberRepository;
use Darinlarimore\Postmaster\Repositories\EloquentListRepository;
use Darinlarimore\Postmaster\Repositories\EloquentSubscriberRepository;
use Darinlarimore\Postmaster\Support\DatabaseProvisioner;
use Darinlarimore\Postmaster\Support\Diagnostics;
use Darinlarimore\Postmaster\Support\Storage;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Statamic\Events\EntryBlueprintFound;
use Statamic\Events\EntrySaved;
use Statamic\Facades\Collection;
use Statamic\Facades\CP\Nav;
use Statamic\Facades\Entry;
use Statamic\Facades\Permission;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $tags = [
        Tags\PostmasterTag::class,
    ];

    protected $routes = [
        'cp' => __DIR__.'/../routes/cp.php',
        'web' => __DIR__.'/../routes/web.php',
    ];

    protected $commands = [
        Console\Commands\SendScheduledSendouts::class,
        Console\Commands\ImportSubscribers::class,
        Console\Commands\ScaffoldNewsletter::class,
    ];

    protected $vite = [
        'input' => ['resources/css/cp.css'],
        'publicDirectory' => 'resources/dist',
    ];

    /**
     * Matches the view namespace instead of the composer package slug, so
     * blueprints import the fieldset as postmaster::email_blocks.
     */
    protected $fieldsetNamespace = 'postmaster';

    protected $middlewareGroups = [
        'statamic.cp' => [
            Http\Middleware\RedirectInternalCollections::class,
        ],
    ];

    public function register()
    {
        parent::register();

        $this->mergeConfigFrom(__DIR__.'/../config/postmaster.php', 'postmaster');

        Diagnostics::boot();

        DatabaseProvisioner::registerConnection();

        $this->app->singleton(SubscriberRepository::class, fn () => $this->driver() === 'database'
            ? new EloquentSubscriberRepository
            : new ContentSubscriberRepository);

        $this->app->singleton(ListRepository::class, fn () => $this->driver() === 'database'
            ? new EloquentListRepository
            : new ContentListRepository);
    }

    public function bootAddon()
    {
        $this->mergeConfigFrom(__DIR__.'/../config/postmaster.php', 'postmaster');

        Diagnostics::boot();

        // Tracking pixels, one-click unsubscribes, and provider webhooks are
        // hit without a browser session - keep them outside the web group so
        // CSRF and cookies never get in the way.
        Route::middleware([
            SubstituteBindings::class,
            'throttle:120,1',
        ])->group(__DIR__.'/../routes/stateless.php');

        $this->publishes([
            __DIR__.'/../config/postmaster.php' => config_path('postmaster.php'),
        ], 'postmaster-config');

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        $this->loadViewsFrom(__DIR__.'/../resources/views', 'postmaster');

        $this->publishes([
            __DIR__.'/../resources/views/emails' => resource_path('views/vendor/postmaster/emails'),
        ], 'postmaster-views');

        // A Claude Code skill teaching agents this addon's workflow, published
        // into the project so a buyer's AI assistant knows how to drive it.
        $this->publishes([
            __DIR__.'/../resources/skills/postmaster' => base_path('.claude/skills/postmaster'),
        ], 'postmaster-skill');

        // statamic:install runs on every composer install/update, so publish
        // without --force - existing (possibly customized) files are kept.
        Statamic::afterInstalled(function ($command) {
            $command->call('vendor:publish', ['--tag' => 'postmaster-views']);
            $command->call('vendor:publish', ['--tag' => 'postmaster-skill']);
        });

        // Sendouts and tracking events are always relational - provision the
        // addon's own database (self-created SQLite by default) so a flat-file
        // site needs no database configuration.
        DatabaseProvisioner::ensureProvisioned();

        if ($this->driver() === 'content') {
            Storage::bootStorage();
            $this->guardContentDriver();
        }

        // register() instead of the $actions property - Statamic 5's
        // AddonServiceProvider doesn't have one.
        Actions\SendTestEmail::register();

        $this->bootEntryBlueprintFields();
        $this->bootAutoSendouts();
        $this->bootListEntryDefaults();
        $this->bootSubscriberEntryFields();
        $this->bootSubscriberEntrySync();

        $this->bootRouteBindings();
        $this->bootPermissions();
        $this->bootNav();
    }

    /**
     * Runtime additions to entry blueprints that carry Email Blocks - these
     * can't live in the fieldset YAML because their content is dynamic: the
     * merge-tag hint reflects the subscriber blueprint, and the recipient
     * checkboxes reflect the site's mailing lists.
     */
    protected function bootEntryBlueprintFields(): void
    {
        Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event): void {
            if (! $event->blueprint->hasField('email_blocks')) {
                return;
            }

            $tags = Support\MergeTags::available()
                ->map(fn (string $tag) => '`{{ '.$tag.' }}`')
                ->implode(', ');

            $event->blueprint->ensureField('email_merge_tags_hint', [
                'type' => 'section',
                'display' => __('Merge Tags'),
                'instructions' => __('Personalize with merge tags anywhere in text: :tags. Unknown tags render as empty text.', ['tags' => $tags]),
            ]);

            // Injected at runtime rather than shipped in the fieldset so the
            // placeholders can surface the site's actual configured defaults.
            $event->blueprint->ensureField('email_from_name', [
                'type' => 'text',
                'display' => __('From Name'),
                'placeholder' => config('postmaster.from.name'),
                'instructions' => __('Blank uses the site default.'),
            ], 'sidebar');

            $event->blueprint->ensureField('email_from_email', [
                'type' => 'text',
                'input_type' => 'email',
                'display' => __('From Email'),
                'placeholder' => config('postmaster.from.email'),
                'instructions' => __('Blank uses the site default.'),
            ], 'sidebar');

            $event->blueprint->ensureField('email_reply_to', [
                'type' => 'text',
                'input_type' => 'email',
                'display' => __('Reply To'),
                'placeholder' => config('postmaster.reply_to') ?: config('postmaster.from.email'),
                'instructions' => __('Blank uses the site default.'),
            ], 'sidebar');

            $listOptions = app(ListRepository::class)->all()
                ->mapWithKeys(fn ($list) => [(string) $list->getKey() => $list->title])
                ->all();

            $event->blueprint->ensureField('postmaster_lists', [
                'type' => 'checkboxes',
                'display' => __('Send to lists'),
                'instructions' => __('Recipients for sendouts created from this entry.'),
                'options' => $listOptions,
            ], 'sidebar');
        });
    }

    /**
     * Saving an entry that carries Email Blocks ensures a draft sendout
     * exists for it, so authored emails appear on the Sendouts screen without
     * a manual step. rescue() keeps entry saves working even if the addon's
     * migrations haven't run yet.
     */
    protected function bootAutoSendouts(): void
    {
        Event::listen(EntrySaved::class, function (EntrySaved $event): void {
            $entry = $event->entry;

            if (! $entry->blueprint()->hasField(Support\CampaignRenderer::BLOCKS_FIELD)) {
                return;
            }

            rescue(function () use ($entry): void {
                // One ACTIVE sendout per entry: skip while a draft exists or
                // a run is underway, but an edit after a completed send spins
                // up a fresh draft, which is how re-sends happen.
                $hasActive = Models\Sendout::query()
                    ->where('entry_id', (string) $entry->id())
                    ->whereIn('status', [
                        Models\Sendout::STATUS_DRAFT,
                        Models\Sendout::STATUS_PENDING,
                        Models\Sendout::STATUS_SENDING,
                        Models\Sendout::STATUS_PAUSED,
                    ])
                    ->exists();

                if ($hasActive) {
                    return;
                }

                $sendout = new Models\Sendout([
                    'title' => (string) $entry->get('title'),
                    'subject' => (string) $entry->get('title'),
                    'entry_id' => (string) $entry->id(),
                    'list_ids' => [],
                    'status' => Models\Sendout::STATUS_DRAFT,
                ]);

                $sendout->refreshFromEntry();
                $sendout->save();
            });
        });
    }

    /**
     * Mailing lists can be created as ordinary CP entries; the handle field
     * is the immutable key, so default it from the slug when left blank.
     */
    protected function bootListEntryDefaults(): void
    {
        Event::listen(EntrySaved::class, function (EntrySaved $event): void {
            $entry = $event->entry;
            $listsCollection = config('postmaster.storage.collections.lists', 'postmaster_lists');

            if ($entry->collectionHandle() !== $listsCollection || $entry->get('handle')) {
                return;
            }

            $entry->set('handle', $entry->slug())->saveQuietly();
        });
    }

    /**
     * The native entry publish form is the subscriber editor (it renders
     * custom blueprint fields with real fieldtypes), so inject the parts the
     * blueprint can't express statically: list-membership checkboxes with
     * per-entry defaults, and a read-only activity history.
     */
    protected function bootSubscriberEntryFields(): void
    {
        Event::listen(EntryBlueprintFound::class, function (EntryBlueprintFound $event): void {
            $subscribersHandle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');

            if ($event->blueprint->namespace() !== 'collections.'.$subscribersHandle) {
                return;
            }

            rescue(function () use ($event): void {
                $activeHandles = collect($event->entry?->get('lists') ?: [])
                    ->filter(fn ($membership) => empty($membership['unsubscribed_at']))
                    ->pluck('handle')
                    ->values()
                    ->all();

                // Spliced into contents (in-memory, never saved) instead of
                // ensureField: blueprint objects are cached aggressively and
                // an ensure can't be refreshed, but strip-and-append is
                // idempotent and always carries the current entry's state.
                // The default feeds the publish form; the save listener
                // strips the submitted key so it never becomes entry data.
                $contents = $event->blueprint->contents();

                foreach ($contents['tabs'] ?? [] as $tabKey => $tab) {
                    foreach ($tab['sections'] ?? [] as $sectionKey => $section) {
                        $contents['tabs'][$tabKey]['sections'][$sectionKey]['fields'] = array_values(array_filter(
                            $section['fields'] ?? [],
                            fn ($field) => ! in_array($field['handle'] ?? null, ['active_lists', 'activity_history']),
                        ));
                    }
                }

                $contents['tabs']['sidebar']['sections'][0]['fields'][] = [
                    'handle' => 'active_lists',
                    'field' => [
                        'type' => 'checkboxes',
                        'display' => __('List memberships'),
                        'instructions' => __('Unchecking a list unsubscribes them from it.'),
                        'options' => app(ListRepository::class)->all()
                            ->mapWithKeys(fn ($list) => [(string) $list->getKey() => $list->title])
                            ->all(),
                        'default' => $activeHandles,
                    ],
                ];

                if ($event->entry) {
                    $history = Models\SubscriberEvent::query()
                        ->where('subscriber_key', $event->entry->id())
                        ->with('sendout')
                        ->latest()
                        ->limit(10)
                        ->get()
                        ->map(fn ($activityEvent) => sprintf(
                            '**%s** · %s · %s',
                            $activityEvent->type,
                            $activityEvent->sendout?->title ?? ($activityEvent->url ?? $activityEvent->detail ?? '—'),
                            $activityEvent->created_at->format('M j, Y g:ia'),
                        ))
                        ->implode("\n\n");

                    $contents['tabs']['main']['sections'][0]['fields'][] = [
                        'handle' => 'activity_history',
                        'field' => [
                            'type' => 'section',
                            'display' => __('Activity'),
                            'instructions' => $history !== '' ? $history : __('No activity yet.'),
                        ],
                    ];
                }

                $event->blueprint->setContents($contents);
            });
        });
    }

    /**
     * Reconcile a subscriber entry after a CP save: the injected checkboxes
     * become real list memberships (then the transient key is stripped), an
     * email edit re-keys the entry to sha1(email), and going straight to
     * subscribed backfills the verification timestamp. Repository writes use
     * saveQuietly(), so this only fires for human edits.
     */
    protected function bootSubscriberEntrySync(): void
    {
        Event::listen(EntrySaved::class, function (EntrySaved $event): void {
            $entry = $event->entry;
            $subscribersHandle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');

            if ($entry->collectionHandle() !== $subscribersHandle) {
                return;
            }

            rescue(function () use ($entry): void {
                $repository = app(SubscriberRepository::class);
                $email = strtolower(trim((string) $entry->get('email')));

                if (filter_var($email, FILTER_VALIDATE_EMAIL) && $entry->id() !== ($key = $repository->keyForEmail($email))) {
                    $rekeyed = Entry::make()
                        ->collection($entry->collectionHandle())
                        ->id($key)
                        ->slug($key)
                        ->date($entry->date() ?? now())
                        ->published(true)
                        ->data($entry->data()->all());

                    $rekeyed->saveQuietly();
                    $entry->deleteQuietly();
                    $entry = $rekeyed;
                }

                $requested = $entry->get('active_lists');
                $active = collect($entry->get('lists') ?: [])
                    ->filter(fn ($membership) => empty($membership['unsubscribed_at']))
                    ->pluck('handle')
                    ->all();

                // Strip the transient key (and backfill verification) BEFORE
                // touching memberships - membership writes re-save the entry,
                // and saving our then-stale copy afterwards would clobber them.
                $changed = is_array($requested);
                $entry->remove('active_lists');

                if ($entry->get('sub_status') === 'subscribed' && ! $entry->get('verified_at')) {
                    $entry->set('verified_at', now()->toIso8601String());
                    $changed = true;
                }

                if ($changed) {
                    $entry->saveQuietly();
                }

                if (is_array($requested)) {
                    foreach (app(ListRepository::class)->all() as $list) {
                        $listKey = (string) $list->getKey();
                        $isRequested = in_array($listKey, array_map('strval', $requested), true);
                        $isActive = in_array($listKey, $active, true);

                        if ($isRequested && ! $isActive) {
                            $repository->setMembership($entry->id(), $listKey, now(), null);
                        } elseif (! $isRequested && $isActive) {
                            $repository->setMembership($entry->id(), $listKey, null, now());
                        }
                    }
                }
            });
        });
    }

    protected function driver(): string
    {
        return config('postmaster.storage.driver', 'content');
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
            Permission::group('postmaster', 'Postmaster', function () {
                Permission::register('view email subscribe')
                    ->label('View Postmaster')
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
            $nav->create('Postmaster')
                ->section('Tools')
                ->can('view email subscribe')
                ->route('postmaster.index')
                ->icon('<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><rect x="2.75" y="4.75" width="18.5" height="14.5" rx="2"/><path d="m3 6.5 9 6.5 9-6.5"/></svg>');

            // The content driver's storage collections are internals - people
            // manage subscribers and lists through the addon's own pages, so
            // hide the raw collections from the Content nav.
            if ($this->driver() === 'content') {
                foreach (['subscribers' => 'postmaster_subscribers', 'lists' => 'postmaster_lists'] as $key => $default) {
                    $handle = config('postmaster.storage.collections.'.$key, $default);

                    if ($collection = Collection::findByHandle($handle)) {
                        $nav->remove('Content', 'Collections', $collection->title());
                    }
                }
            }
        });
    }
}
