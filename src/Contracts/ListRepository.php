<?php

namespace Darinlarimore\Postmaster\Contracts;

use Illuminate\Support\Collection;

/**
 * Storage-agnostic access to mailing lists. A list's handle is its immutable
 * primary key: subscriber membership is keyed by handle, so renames are
 * forbidden (update() throws on a handle change) to avoid orphaning memberships.
 */
interface ListRepository
{
    public function find(string $key): ?ListContract;

    public function findByHandle(string $handle): ?ListContract;

    public function findByLegacyId(string|int $id): ?ListContract;

    /**
     * @return Collection<int, ListContract>
     */
    public function all(): Collection;

    /**
     * @param  array{title: string, handle: string, double_opt_in?: ?bool}  $data
     */
    public function create(array $data): ListContract;

    /**
     * @param  array{title?: string, handle?: string, double_opt_in?: ?bool}  $data
     *
     * @throws \InvalidArgumentException when $data['handle'] differs from $key
     */
    public function update(string $key, array $data): ListContract;

    public function delete(string $key): void;

    public function countActiveSubscribers(string $key): int;

    public function handleExists(string $handle, ?string $exceptKey = null): bool;
}
