<?php

namespace Darinlarimore\Postmaster\Repositories;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Records\SubscriberRecord;
use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/**
 * The database storage driver: subscribers live in the postmaster_subscribers
 * table via the Eloquent Subscriber model. This wraps that model with zero
 * behavioural change from the pre-refactor code.
 */
class EloquentSubscriberRepository implements SubscriberRepository
{
    public function keyForEmail(string $email): string
    {
        return sha1(strtolower(trim($email)));
    }

    public function findByKey(string $key): ?SubscriberContract
    {
        return ctype_digit($key) ? Subscriber::find((int) $key) : null;
    }

    public function findByEmail(string $email): ?SubscriberContract
    {
        return Subscriber::firstWhere('email', strtolower(trim($email)));
    }

    public function findByLegacyId(string|int $id): ?SubscriberContract
    {
        return Subscriber::find((int) $id);
    }

    public function upsert(SubscriberContract $record): SubscriberContract
    {
        if ($record instanceof Subscriber) {
            $record->save();

            return $record;
        }

        $subscriber = Subscriber::firstOrNew(['email' => strtolower(trim($record->email))]);
        $subscriber->fill($this->attributesFrom($record));
        $subscriber->save();

        return $subscriber;
    }

    public function delete(string $key): void
    {
        Subscriber::where('id', (int) $key)->delete();
    }

    public function query(array $filters = []): LengthAwarePaginator
    {
        $query = Subscriber::query()->with('lists');

        if ($search = $filters['search'] ?? null) {
            $query->where(function ($inner) use ($search): void {
                $inner->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($status = $filters['status'] ?? null) {
            $query->where('status', $status);
        }

        if ($listKey = $filters['list'] ?? null) {
            $query->whereHas('lists', fn ($inner) => $inner->where('postmaster_lists.id', $listKey));
        }

        return $query->latest()->paginate($filters['per_page'] ?? 50);
    }

    public function allForList(string $listKey): LazyCollection
    {
        $list = ctype_digit($listKey)
            ? MailingList::find((int) $listKey)
            : MailingList::firstWhere('handle', $listKey);

        if (! $list) {
            return LazyCollection::make();
        }

        return LazyCollection::make(fn () => yield from $list->activeSubscribers()->lazy());
    }

    public function countActiveForList(string $listKey): int
    {
        $list = ctype_digit($listKey)
            ? MailingList::find((int) $listKey)
            : MailingList::firstWhere('handle', $listKey);

        return $list ? $list->activeSubscribers()->count() : 0;
    }

    public function setMembership(
        string $key,
        string $listKey,
        ?DateTimeInterface $subscribedAt,
        ?DateTimeInterface $unsubscribedAt,
    ): void {
        $subscriber = Subscriber::find((int) $key);
        $list = ctype_digit($listKey)
            ? MailingList::find((int) $listKey)
            : MailingList::firstWhere('handle', $listKey);

        if (! $subscriber || ! $list) {
            return;
        }

        $subscriber->lists()->syncWithoutDetaching([
            $list->id => ['subscribed_at' => $subscribedAt, 'unsubscribed_at' => $unsubscribedAt],
        ]);

        $subscriber->lists()->updateExistingPivot($list->id, [
            'subscribed_at' => $subscribedAt,
            'unsubscribed_at' => $unsubscribedAt,
        ]);
    }

    public function activeListKeys(string $key): array
    {
        $subscriber = Subscriber::find((int) $key);

        if (! $subscriber) {
            return [];
        }

        return $subscriber->lists()
            ->wherePivotNull('unsubscribed_at')
            ->pluck('postmaster_lists.id')
            ->map(fn ($id): string => (string) $id)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    protected function attributesFrom(SubscriberRecord $record): array
    {
        return [
            'email' => strtolower(trim($record->email)),
            'first_name' => $record->first_name,
            'last_name' => $record->last_name,
            'status' => $record->status,
            'fields' => $record->fields ?: null,
            'source' => $record->source,
            'subscribe_ip' => $record->subscribe_ip,
            'verified_at' => $record->verified_at,
            'unsubscribed_at' => $record->unsubscribed_at,
            'bounced_at' => $record->bounced_at,
            'complained_at' => $record->complained_at,
        ];
    }
}
