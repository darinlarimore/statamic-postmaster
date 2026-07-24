<?php

namespace Darinlarimore\Postmaster\Contracts;

/**
 * The shared surface both the Eloquent MailingList model (database driver) and
 * the ListRecord value object (content driver) expose. Data is read as
 * properties (`->id`, `->handle`, `->title`, `->double_opt_in`); only behaviour
 * lives here.
 */
interface ListContract
{
    /**
     * The list's immutable key: its handle under the content driver, the
     * (string) primary key under the database driver. Equal to `->id`, and the
     * value stored in a sendout's list_ids.
     */
    public function getKey(): string;

    public function usesDoubleOptIn(): bool;
}
