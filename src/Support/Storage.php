<?php

namespace Darinlarimore\Postmaster\Support;

use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;

/**
 * Ensures the content driver's subscribers and lists collections (and their
 * blueprints) exist. Created programmatically rather than shipped as YAML in the
 * package, because Statamic only discovers blueprints from the app's resources
 * directory — setNamespace('collections.'.$handle)->save() writes there.
 *
 * Only invoked under the content driver.
 */
class Storage
{
    public static function bootStorage(): void
    {
        static::ensureSubscribers();
        static::ensureLists();
    }

    protected static function ensureSubscribers(): void
    {
        $handle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');

        if (! Collection::handleExists($handle)) {
            Collection::make($handle)
                ->title('Postmasterrs')
                ->dated(true)
                ->revisionsEnabled(false)
                ->save();
        }

        static::ensureSeoExclusion(Collection::findByHandle($handle));
        static::ensureTitleFormat(Collection::findByHandle($handle));

        if ($blueprint = Blueprint::find('collections.'.$handle.'.'.$handle)) {
            static::healLegacyBagFields($blueprint);

            return;
        }

        Blueprint::make($handle)
            ->setNamespace('collections.'.$handle)
            ->setContents([
                'title' => 'Subscriber',
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'email', 'field' => ['type' => 'text', 'input_type' => 'email', 'display' => 'Email']],
                                    ['handle' => 'first_name', 'field' => ['type' => 'text', 'display' => 'First Name']],
                                    ['handle' => 'last_name', 'field' => ['type' => 'text', 'display' => 'Last Name']],
                                    ['handle' => 'sub_status', 'field' => [
                                        'type' => 'select',
                                        'display' => 'Status',
                                        'multiple' => false,
                                        'cast_booleans' => false,
                                        'options' => [
                                            'pending' => 'Pending',
                                            'subscribed' => 'Subscribed',
                                            'unsubscribed' => 'Unsubscribed',
                                            'bounced' => 'Bounced',
                                            'complained' => 'Complained',
                                        ],
                                    ]],
                                    ['handle' => 'verified_at', 'field' => ['type' => 'text', 'display' => 'Verified At']],
                                    ['handle' => 'unsubscribed_at', 'field' => ['type' => 'text', 'display' => 'Unsubscribed At']],
                                    ['handle' => 'bounced_at', 'field' => ['type' => 'text', 'display' => 'Bounced At']],
                                    ['handle' => 'complained_at', 'field' => ['type' => 'text', 'display' => 'Complained At']],
                                    ['handle' => 'source', 'field' => ['type' => 'text', 'display' => 'Source']],
                                    ['handle' => 'subscribe_ip', 'field' => ['type' => 'text', 'display' => 'Subscribe IP']],
                                    ['handle' => 'legacy_id', 'field' => ['type' => 'text', 'display' => 'Legacy ID']],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();
    }

    /**
     * Early blueprints exposed the raw lists/fields storage bags as blueprint
     * fields, and runtime-ensured fields (active_lists, activity_history) can
     * crystallize into the file when the blueprint is saved for any reason.
     * None of them belong in the file: the bags are machinery, and the
     * ensured fields are injected fresh per request with per-entry state.
     * Entry data is untouched.
     */
    protected static function healLegacyBagFields($blueprint): void
    {
        $contents = $blueprint->contents();
        $fields = collect($contents['tabs']['main']['sections'][0]['fields'] ?? []);

        $kept = $fields->reject(fn (array $field) => in_array($field['handle'] ?? null, ['lists', 'fields', 'active_lists', 'activity_history']));

        if ($kept->count() !== $fields->count()) {
            $contents['tabs']['main']['sections'][0]['fields'] = $kept->values()->all();
            $blueprint->setContents($contents)->save();
        }
    }

    /**
     * Subscriber entries are keyed by sha1(email), so without this the CP
     * shows blank titles everywhere. The title format auto-generates the
     * title from the email and hides the Title input on the publish form.
     */
    protected static function ensureTitleFormat($collection): void
    {
        if (! $collection->autoGeneratesTitles()) {
            $collection->titleFormats('{email}')->save();
        }
    }

    /**
     * Subscribers and lists are storage, not web pages - keep SEO addons
     * from decorating them with SEO tabs. Deliberate per-site settings win.
     */
    protected static function ensureSeoExclusion($collection): void
    {
        if (! $collection->cascade()->has('seo')) {
            $collection->cascade($collection->cascade()->put('seo', false)->all())->save();
        }
    }

    protected static function ensureLists(): void
    {
        $handle = config('postmaster.storage.collections.lists', 'postmaster_lists');

        if (! Collection::handleExists($handle)) {
            Collection::make($handle)
                ->title('Email Lists')
                ->dated(true)
                ->revisionsEnabled(false)
                ->save();
        }

        static::ensureSeoExclusion(Collection::findByHandle($handle));

        if (Blueprint::find('collections.'.$handle.'.'.$handle)) {
            return;
        }

        Blueprint::make($handle)
            ->setNamespace('collections.'.$handle)
            ->setContents([
                'title' => 'Mailing List',
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title']],
                                    ['handle' => 'handle', 'field' => ['type' => 'text', 'display' => 'Handle']],
                                    ['handle' => 'double_opt_in', 'field' => ['type' => 'toggle', 'display' => 'Double Opt-in']],
                                    ['handle' => 'legacy_id', 'field' => ['type' => 'text', 'display' => 'Legacy ID']],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();
    }
}
