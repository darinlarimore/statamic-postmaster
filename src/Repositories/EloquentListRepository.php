<?php

namespace Darinlarimore\StatamicEmailSubscribe\Repositories;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * The database storage driver for mailing lists: they live in the
 * email_subscribe_lists table via the Eloquent MailingList model.
 */
class EloquentListRepository implements ListRepository
{
    public function find(string $key): ?ListContract
    {
        return ctype_digit($key) ? MailingList::find((int) $key) : $this->findByHandle($key);
    }

    public function findByHandle(string $handle): ?ListContract
    {
        return MailingList::firstWhere('handle', $handle);
    }

    public function findByLegacyId(string|int $id): ?ListContract
    {
        return MailingList::find((int) $id);
    }

    public function all(): Collection
    {
        return MailingList::orderBy('title')->get();
    }

    public function create(array $data): ListContract
    {
        return MailingList::create([
            'title' => $data['title'],
            'handle' => $data['handle'],
            'double_opt_in' => $data['double_opt_in'] ?? null,
        ]);
    }

    public function update(string $key, array $data): ListContract
    {
        $list = $this->resolve($key);

        if (array_key_exists('handle', $data) && $data['handle'] !== $list->handle) {
            throw new InvalidArgumentException('List handle is immutable.');
        }

        if (array_key_exists('title', $data)) {
            $list->title = $data['title'];
        }

        if (array_key_exists('double_opt_in', $data)) {
            $list->double_opt_in = $data['double_opt_in'];
        }

        $list->save();

        return $list;
    }

    public function delete(string $key): void
    {
        $this->resolve($key)->delete();
    }

    public function countActiveSubscribers(string $key): int
    {
        return $this->resolve($key)->activeSubscribers()->count();
    }

    public function handleExists(string $handle, ?string $exceptKey = null): bool
    {
        return MailingList::where('handle', $handle)
            ->when($exceptKey, fn ($query) => $query->where('id', '!=', (int) $exceptKey))
            ->exists();
    }

    protected function resolve(string $key): MailingList
    {
        $list = ctype_digit($key) ? MailingList::find((int) $key) : MailingList::firstWhere('handle', $key);

        if (! $list) {
            throw new InvalidArgumentException("Mailing list [{$key}] not found.");
        }

        return $list;
    }
}
