<?php

namespace Darinlarimore\StatamicEmailSubscribe\Repositories;

use Carbon\Carbon;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;
use Darinlarimore\StatamicEmailSubscribe\Support\Diagnostics;
use Darinlarimore\StatamicEmailSubscribe\Support\MergeTags;
use DateTimeInterface;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\LengthAwarePaginator as Paginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\LazyCollection;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * The content storage driver: subscribers are Statamic entries, one per person,
 * in the subscribers collection. The entry's id, slug, and file path are all the
 * deterministic sha1(email) key, so one email maps to exactly one entry by
 * construction. Membership lives in a structured `lists` field on the entry.
 *
 * Writes go through a per-subscriber lock and saveQuietly() so concurrent
 * subscribes don't clobber each other's membership map and per-write side
 * effects (git auto-commit, search reindex) don't fire.
 */
class ContentSubscriberRepository implements SubscriberRepository
{
    protected int $lockSeconds = 10;

    protected int $blockSeconds = 5;

    protected function collection(): string
    {
        return config('email_subscribe.storage.collections.subscribers', 'email_subscribers');
    }

    public function keyForEmail(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    public function findByKey(string $key): ?SubscriberContract
    {
        $entry = Entry::find($key);

        return $entry && $entry->collectionHandle() === $this->collection()
            ? $this->hydrate($entry)
            : null;
    }

    public function findByEmail(string $email): ?SubscriberContract
    {
        return $this->findByKey($this->keyForEmail($email));
    }

    public function findByLegacyId(string|int $id): ?SubscriberContract
    {
        $entry = Entry::query()
            ->where('collection', $this->collection())
            ->where('legacy_id', (string) $id)
            ->first();

        return $entry ? $this->hydrate($entry) : null;
    }

    public function upsert(SubscriberContract $record): SubscriberContract
    {
        /** @var SubscriberRecord $record */
        $key = $record->getKey();

        return $this->withLock($key, function () use ($record, $key): SubscriberRecord {
            $entry = Entry::find($key);

            $data = [
                'title' => strtolower(trim($record->email)),
                'email' => strtolower(trim($record->email)),
                'first_name' => $record->first_name,
                'last_name' => $record->last_name,
                'sub_status' => $record->status,
                'verified_at' => $this->iso($record->verified_at),
                'unsubscribed_at' => $this->iso($record->unsubscribed_at),
                'bounced_at' => $this->iso($record->bounced_at),
                'complained_at' => $this->iso($record->complained_at),
                'source' => $record->source,
                'subscribe_ip' => $record->subscribe_ip,
                'legacy_id' => $record->legacy_id !== null ? (string) $record->legacy_id : null,
            ];

            // Custom fields are real top-level entry data, so blueprint
            // fields added by the site show captured values when editing.
            // The whitelist guarantees no collision with the core keys.
            $data += $record->fields ?: [];

            if (! $entry) {
                $entry = Entry::make()
                    ->collection($this->collection())
                    ->id($key)
                    ->slug($key)
                    ->date(now())
                    ->published(true)
                    ->data($data + ['lists' => $record->lists]);
            } else {
                $entry->merge($data);
            }

            $entry->saveQuietly();

            return $this->hydrate($entry);
        });
    }

    public function delete(string $key): void
    {
        Entry::find($key)?->delete();
    }

    public function query(array $filters = []): LengthAwarePaginator
    {
        $entries = Entry::query()
            ->where('collection', $this->collection())
            ->get();

        $records = $entries->map(fn (EntryContract $entry): SubscriberRecord => $this->hydrate($entry));

        if ($search = $filters['search'] ?? null) {
            $needle = strtolower($search);
            $records = $records->filter(fn (SubscriberRecord $record): bool => str_contains(strtolower($record->email), $needle)
                || str_contains(strtolower((string) $record->first_name), $needle)
                || str_contains(strtolower((string) $record->last_name), $needle));
        }

        if ($status = $filters['status'] ?? null) {
            $records = $records->filter(fn (SubscriberRecord $record): bool => $record->status === $status);
        }

        if ($listKey = $filters['list'] ?? null) {
            $records = $records->filter(fn (SubscriberRecord $record): bool => in_array($listKey, $record->activeListHandles(), true));
        }

        $records = $records->sortByDesc(fn (SubscriberRecord $record) => $record->created_at?->timestamp ?? 0)->values();

        $perPage = $filters['per_page'] ?? 50;
        $page = (int) (request()->query('page', 1));

        return new Paginator(
            $records->forPage($page, $perPage)->values(),
            $records->count(),
            $perPage,
            $page,
            ['path' => Paginator::resolveCurrentPath()],
        );
    }

    public function allForList(string $listKey): LazyCollection
    {
        return LazyCollection::make(function () use ($listKey) {
            $entries = Entry::query()
                ->where('collection', $this->collection())
                ->where('sub_status', Subscriber::STATUS_SUBSCRIBED)
                ->get();

            foreach ($entries as $entry) {
                $record = $this->hydrate($entry);

                if ($record->isSendable() && in_array($listKey, $record->activeListHandles(), true)) {
                    yield $record;
                }
            }
        });
    }

    public function countActiveForList(string $listKey): int
    {
        return $this->allForList($listKey)->count();
    }

    public function setMembership(
        string $key,
        string $listKey,
        ?DateTimeInterface $subscribedAt,
        ?DateTimeInterface $unsubscribedAt,
    ): void {
        $this->withLock($key, function () use ($key, $listKey, $subscribedAt, $unsubscribedAt): void {
            $entry = Entry::find($key);

            if (! $entry) {
                return;
            }

            $lists = collect($entry->get('lists', []));
            $existing = $lists->firstWhere('handle', $listKey);

            $membership = [
                'handle' => $listKey,
                'subscribed_at' => $this->iso($subscribedAt) ?? ($existing['subscribed_at'] ?? null),
                'unsubscribed_at' => $this->iso($unsubscribedAt),
            ];

            $lists = $lists->reject(fn (array $item): bool => ($item['handle'] ?? null) === $listKey)
                ->push($membership)
                ->values()
                ->all();

            $entry->merge(['lists' => $lists])->saveQuietly();
        });
    }

    public function activeListKeys(string $key): array
    {
        $record = $this->findByKey($key);

        return $record instanceof SubscriberRecord ? $record->activeListHandles() : [];
    }

    protected function hydrate(EntryContract $entry): SubscriberRecord
    {
        return new SubscriberRecord(
            routeKey: $entry->id(),
            email: (string) $entry->get('email'),
            first_name: $entry->get('first_name'),
            last_name: $entry->get('last_name'),
            status: (string) ($entry->get('sub_status') ?? Subscriber::STATUS_PENDING),
            fields: $this->customFields($entry),
            lists: array_map(fn ($membership): array => (array) $membership, $entry->get('lists') ?: []),
            verified_at: $this->carbon($entry->get('verified_at')),
            unsubscribed_at: $this->carbon($entry->get('unsubscribed_at')),
            bounced_at: $this->carbon($entry->get('bounced_at')),
            complained_at: $this->carbon($entry->get('complained_at')),
            source: $entry->get('source'),
            subscribe_ip: $entry->get('subscribe_ip'),
            created_at: $entry->date() ? Carbon::instance($entry->date()) : null,
            legacy_id: $entry->get('legacy_id') !== null ? (int) $entry->get('legacy_id') : null,
            exists: true,
        );
    }

    /**
     * Custom personalization fields: every non-internal top-level data key,
     * which is exactly the set of blueprint-added (or capture-whitelisted)
     * fields - the blueprint is the schema.
     *
     * @return array<string, mixed>
     */
    protected function customFields(EntryContract $entry): array
    {
        return collect($entry->data()->all())
            ->except(array_merge(MergeTags::INTERNAL, ['email', 'first_name', 'last_name']))
            ->all();
    }

    /**
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function withLock(string $key, callable $callback): mixed
    {
        $lock = Cache::lock('es-sub-'.$key, $this->lockSeconds);

        try {
            return $lock->block($this->blockSeconds, $callback);
        } catch (LockTimeoutException $exception) {
            Diagnostics::lockTimeout($key);

            throw $exception;
        }
    }

    protected function iso(?DateTimeInterface $date): ?string
    {
        return $date?->format(DateTimeInterface::ATOM);
    }

    protected function carbon(?string $value): ?Carbon
    {
        return $value ? Carbon::parse($value) : null;
    }
}
