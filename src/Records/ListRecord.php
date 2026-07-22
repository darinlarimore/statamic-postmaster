<?php

namespace Darinlarimore\StatamicEmailSubscribe\Records;

use Carbon\CarbonInterface;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;

/**
 * Content-driver carrier for a mailing list. Its key is its immutable handle.
 * The database driver returns the Eloquent MailingList directly.
 *
 * @property-read string $id alias of $handle
 */
class ListRecord implements ListContract
{
    public function __construct(
        public string $handle,
        public string $title,
        public ?bool $double_opt_in = null,
        public ?CarbonInterface $created_at = null,
        public ?int $legacy_id = null,
        public ?int $active_subscribers_count = null,
    ) {}

    public function __get(string $name): mixed
    {
        return $name === 'id' ? $this->handle : null;
    }

    public function getKey(): string
    {
        return $this->handle;
    }

    public function usesDoubleOptIn(): bool
    {
        return $this->double_opt_in ?? config('email_subscribe.double_opt_in', true);
    }
}
