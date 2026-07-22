<?php

namespace Darinlarimore\StatamicEmailSubscribe\Repositories;

use Carbon\Carbon;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Records\ListRecord;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Statamic\Contracts\Entries\Entry as EntryContract;
use Statamic\Facades\Entry;

/**
 * The content storage driver for mailing lists: each list is a Statamic entry
 * whose id, slug, and file path are its handle. The handle is the immutable
 * primary key — subscriber membership is keyed by handle, so update() refuses a
 * handle change.
 */
class ContentListRepository implements ListRepository
{
    protected function collection(): string
    {
        return config('email_subscribe.storage.collections.lists', 'email_lists');
    }

    public function find(string $key): ?ListContract
    {
        $entry = Entry::find($key);

        return $entry && $entry->collectionHandle() === $this->collection()
            ? $this->hydrate($entry)
            : null;
    }

    public function findByHandle(string $handle): ?ListContract
    {
        return $this->find($handle);
    }

    public function findByLegacyId(string|int $id): ?ListContract
    {
        $entry = Entry::query()
            ->where('collection', $this->collection())
            ->where('legacy_id', (string) $id)
            ->first();

        return $entry ? $this->hydrate($entry) : null;
    }

    public function all(): Collection
    {
        return Entry::query()
            ->where('collection', $this->collection())
            ->get()
            ->map(fn (EntryContract $entry): ListRecord => $this->hydrate($entry))
            ->sortBy(fn (ListRecord $record): string => $record->title)
            ->values();
    }

    public function create(array $data): ListContract
    {
        $handle = $data['handle'];

        $entry = Entry::make()
            ->collection($this->collection())
            ->id($handle)
            ->slug($handle)
            ->date(now())
            ->published(true)
            ->data([
                'title' => $data['title'],
                'handle' => $handle,
                'double_opt_in' => $data['double_opt_in'] ?? null,
            ]);

        $entry->saveQuietly();

        return $this->hydrate($entry);
    }

    public function update(string $key, array $data): ListContract
    {
        if (array_key_exists('handle', $data) && $data['handle'] !== $key) {
            throw new InvalidArgumentException('List handle is immutable.');
        }

        $entry = Entry::find($key);

        if (! $entry) {
            throw new InvalidArgumentException("Mailing list [{$key}] not found.");
        }

        $merge = [];

        if (array_key_exists('title', $data)) {
            $merge['title'] = $data['title'];
        }

        if (array_key_exists('double_opt_in', $data)) {
            $merge['double_opt_in'] = $data['double_opt_in'];
        }

        $entry->merge($merge)->saveQuietly();

        return $this->hydrate($entry);
    }

    public function delete(string $key): void
    {
        Entry::find($key)?->delete();
    }

    public function countActiveSubscribers(string $key): int
    {
        return app(SubscriberRepository::class)->countActiveForList($key);
    }

    public function handleExists(string $handle, ?string $exceptKey = null): bool
    {
        return Entry::find($handle) !== null && $handle !== $exceptKey;
    }

    protected function hydrate(EntryContract $entry): ListRecord
    {
        $doubleOptIn = $entry->get('double_opt_in');

        return new ListRecord(
            handle: $entry->id(),
            title: (string) $entry->get('title'),
            double_opt_in: $doubleOptIn === null ? null : (bool) $doubleOptIn,
            created_at: $entry->date() ? Carbon::instance($entry->date()) : null,
            legacy_id: $entry->get('legacy_id') !== null ? (int) $entry->get('legacy_id') : null,
        );
    }
}
