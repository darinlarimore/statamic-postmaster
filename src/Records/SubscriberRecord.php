<?php

namespace Darinlarimore\Postmaster\Records;

use Carbon\CarbonInterface;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Subscriber;
use Illuminate\Support\Collection;

/**
 * Content-driver carrier for a subscriber. Its public properties mirror the
 * Eloquent Subscriber's attributes (id, email, first_name, ...) so the renderer,
 * mail, and signed URLs read it unchanged. The database driver returns the
 * Eloquent model directly; this object is only used under the content driver.
 *
 * @property-read string $id alias of $routeKey, for Urls and route binding
 */
class SubscriberRecord implements SubscriberContract
{
    /**
     * @param  array<string, mixed>  $fields  arbitrary custom fields
     * @param  array<int, array{handle: string, subscribed_at: ?string, unsubscribed_at: ?string}>  $lists
     */
    public function __construct(
        public string $routeKey,
        public string $email,
        public ?string $first_name = null,
        public ?string $last_name = null,
        public string $status = Subscriber::STATUS_PENDING,
        public array $fields = [],
        public array $lists = [],
        public ?CarbonInterface $verified_at = null,
        public ?CarbonInterface $unsubscribed_at = null,
        public ?CarbonInterface $bounced_at = null,
        public ?CarbonInterface $complained_at = null,
        public ?string $source = null,
        public ?string $subscribe_ip = null,
        public ?CarbonInterface $created_at = null,
        public ?int $legacy_id = null,
        public bool $exists = true,
    ) {}

    /**
     * A transient (unsaved) record — used by test sendouts to a non-subscriber.
     */
    public static function transient(string $email, ?string $firstName = null, ?string $lastName = null): self
    {
        return new self(
            routeKey: sha1(strtolower(trim($email))),
            email: $email,
            first_name: $firstName,
            last_name: $lastName,
            exists: false,
        );
    }

    public function __get(string $name): mixed
    {
        return $name === 'id' ? $this->routeKey : null;
    }

    public function getKey(): string
    {
        return $this->routeKey;
    }

    public function fullName(): ?string
    {
        return trim(($this->first_name ?? '').' '.($this->last_name ?? '')) ?: null;
    }

    public function isSendable(): bool
    {
        return $this->status === Subscriber::STATUS_SUBSCRIBED;
    }

    /**
     * @return array<int, string> handles of lists the subscriber is actively on
     */
    public function activeListHandles(): array
    {
        return collect($this->lists)
            ->filter(fn (array $membership): bool => ($membership['unsubscribed_at'] ?? null) === null)
            ->pluck('handle')
            ->values()
            ->all();
    }

    /**
     * A pivot-shaped view of memberships so cp/subscriber.blade.php can read
     * `$list->title` and `$list->pivot->unsubscribed_at` exactly as it does for
     * the Eloquent relation.
     *
     * @return Collection<int, object>
     */
    public function listsForView(): Collection
    {
        return collect($this->lists)->map(fn (array $membership): object => (object) [
            'handle' => $membership['handle'],
            'title' => $membership['title'] ?? $membership['handle'],
            'pivot' => (object) [
                'subscribed_at' => $membership['subscribed_at'] ?? null,
                'unsubscribed_at' => $membership['unsubscribed_at'] ?? null,
            ],
        ]);
    }
}
