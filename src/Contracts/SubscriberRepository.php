<?php

namespace Darinlarimore\Postmaster\Contracts;

use DateTimeInterface;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

/**
 * Storage-agnostic access to subscribers. The content implementation stores
 * them as Statamic entries; the database implementation wraps the Eloquent
 * Subscriber model. Both return objects implementing SubscriberContract.
 *
 * upsert() and setMembership() acquire a per-subscriber lock in the content
 * implementation so the read-modify-write of the membership map serializes.
 */
interface SubscriberRepository
{
    public function findByKey(string $key): ?SubscriberContract;

    public function findByEmail(string $email): ?SubscriberContract;

    public function findByLegacyId(string|int $id): ?SubscriberContract;

    public function upsert(SubscriberContract $record): SubscriberContract;

    public function delete(string $key): void;

    /**
     * @param  array{search?: ?string, status?: ?string, list?: ?string, per_page?: int}  $filters
     */
    public function query(array $filters = []): LengthAwarePaginator;

    /**
     * Active, sendable members of a list, streamed. O(n) over the collection
     * under the content driver.
     *
     * @return LazyCollection<int, SubscriberContract>
     */
    public function allForList(string $listKey): LazyCollection;

    public function countActiveForList(string $listKey): int;

    /**
     * The single source of the deterministic subscriber key, shared by both
     * drivers so events written under either agree: sha1(strtolower(trim(email))).
     */
    public function keyForEmail(string $email): string;

    public function setMembership(
        string $key,
        string $listKey,
        ?DateTimeInterface $subscribedAt,
        ?DateTimeInterface $unsubscribedAt,
    ): void;

    /**
     * @return array<int, string> list keys the subscriber is actively subscribed to
     */
    public function activeListKeys(string $key): array;
}
