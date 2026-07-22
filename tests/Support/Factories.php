<?php

namespace Darinlarimore\StatamicEmailSubscribe\Tests\Support;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;

/**
 * Driver-aware test data factories. Every test body creates lists and
 * subscribers through these so the same assertions run under both the content
 * and database storage drivers.
 */
class Factories
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function makeList(array $attributes = []): ListContract
    {
        return app(ListRepository::class)->create([
            'title' => $attributes['title'] ?? 'Newsletter',
            'handle' => $attributes['handle'] ?? 'newsletter',
            'double_opt_in' => $attributes['double_opt_in'] ?? null,
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @param  array<int, ListContract|string>  $lists
     */
    public static function makeSubscriber(array $attributes = [], array $lists = []): SubscriberContract
    {
        $repository = app(SubscriberRepository::class);
        $email = $attributes['email'] ?? 'jane@example.com';

        $record = new SubscriberRecord(
            routeKey: $repository->keyForEmail($email),
            email: $email,
            first_name: $attributes['first_name'] ?? null,
            last_name: $attributes['last_name'] ?? null,
            status: $attributes['status'] ?? Subscriber::STATUS_SUBSCRIBED,
            verified_at: ($attributes['status'] ?? null) === Subscriber::STATUS_SUBSCRIBED ? now() : null,
            legacy_id: $attributes['legacy_id'] ?? null,
            exists: false,
        );

        $subscriber = $repository->upsert($record);

        foreach ($lists as $list) {
            $key = $list instanceof ListContract ? $list->getKey() : (string) $list;
            $repository->setMembership($subscriber->getKey(), $key, now(), null);
        }

        return $repository->findByKey($subscriber->getKey()) ?? $subscriber;
    }

    public static function attachToList(SubscriberContract $subscriber, ListContract|string $list): void
    {
        $key = $list instanceof ListContract ? $list->getKey() : (string) $list;
        app(SubscriberRepository::class)->setMembership($subscriber->getKey(), $key, now(), null);
    }
}
