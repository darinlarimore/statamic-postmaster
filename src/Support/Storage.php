<?php

namespace Darinlarimore\StatamicEmailSubscribe\Support;

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
        $handle = config('email_subscribe.storage.collections.subscribers', 'email_subscribers');

        if (! Collection::handleExists($handle)) {
            Collection::make($handle)
                ->title('Email Subscribers')
                ->dated(true)
                ->revisionsEnabled(false)
                ->save();
        }

        if (Blueprint::find('collections.'.$handle.'.'.$handle)) {
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
                                    ['handle' => 'lists', 'field' => ['type' => 'array', 'display' => 'List Memberships']],
                                    ['handle' => 'fields', 'field' => ['type' => 'array', 'display' => 'Custom Fields']],
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

    protected static function ensureLists(): void
    {
        $handle = config('email_subscribe.storage.collections.lists', 'email_lists');

        if (! Collection::handleExists($handle)) {
            Collection::make($handle)
                ->title('Email Lists')
                ->dated(true)
                ->revisionsEnabled(false)
                ->save();
        }

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
