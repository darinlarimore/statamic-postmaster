<?php

namespace Darinlarimore\Postmaster\Services;

use Darinlarimore\Postmaster\Contracts\ListContract;
use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Mail\VerifyMail;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Darinlarimore\Postmaster\Records\SubscriberRecord;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SubscriberService
{
    public function __construct(
        protected SubscriberRepository $subscribers,
        protected ListRepository $lists,
    ) {}

    /**
     * Subscribe an email address to one or more lists. Returns the subscriber;
     * their status tells you whether they're live or awaiting double opt-in.
     *
     * @param  Collection<int, ListContract|string>|array<int, ListContract|string>  $lists
     * @param  array<string, mixed>  $data
     */
    public function subscribe(
        string $email,
        Collection|array $lists,
        array $data = [],
        string $source = 'form',
        ?string $ip = null,
    ): SubscriberContract {
        $lists = collect($lists)
            ->map(fn ($list): ListContract => $list instanceof ListContract
                ? $list
                : $this->requireList($list));

        $record = $this->toRecord($this->subscribers->findByEmail($email))
            ?? new SubscriberRecord(
                routeKey: $this->subscribers->keyForEmail($email),
                email: strtolower(trim($email)),
                status: Subscriber::STATUS_PENDING,
                source: $source,
                subscribe_ip: $ip,
                exists: false,
            );

        $record->first_name = $data['first_name'] ?? $record->first_name;
        $record->last_name = $data['last_name'] ?? $record->last_name;
        $customFields = array_diff_key($data, array_flip(['first_name', 'last_name']));

        if ($customFields !== []) {
            $record->fields = array_merge($record->fields, $customFields);
        }

        // A returning unsubscriber who signs up again gets a clean slate;
        // bounced/complained addresses stay suppressed.
        if ($record->status === Subscriber::STATUS_UNSUBSCRIBED) {
            $record->status = Subscriber::STATUS_PENDING;
            $record->unsubscribed_at = null;
            $record->verified_at = null;
        }

        $saved = $this->subscribers->upsert($record);
        $key = $saved->getKey();

        foreach ($lists as $list) {
            $this->subscribers->setMembership($key, $list->getKey(), now(), null);
        }

        $needsVerification = $lists->contains(fn (ListContract $list): bool => $list->usesDoubleOptIn());

        if ($record->status === Subscriber::STATUS_PENDING) {
            if ($needsVerification && ! $record->verified_at) {
                Mail::mailer(config('postmaster.mailer'))
                    ->to($saved->email)
                    ->send(new VerifyMail($saved));
            } else {
                $saved = $this->markStatus($key, Subscriber::STATUS_SUBSCRIBED);
            }
        }

        return $saved;
    }

    /**
     * Update a subscriber's profile fields (name), leaving status/memberships
     * untouched.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateProfile(SubscriberContract $subscriber, array $data): SubscriberContract
    {
        $record = $this->toRecord($subscriber);

        if (! $record) {
            return $subscriber;
        }

        if (($data['first_name'] ?? null) !== null) {
            $record->first_name = $data['first_name'];
        }

        if (($data['last_name'] ?? null) !== null) {
            $record->last_name = $data['last_name'];
        }

        return $this->subscribers->upsert($record);
    }

    /**
     * Re-read a subscriber from storage so membership reflects the latest write.
     */
    public function refresh(SubscriberContract $subscriber): SubscriberContract
    {
        return $this->subscribers->findByKey($subscriber->getKey()) ?? $subscriber;
    }

    public function verify(SubscriberContract $subscriber): void
    {
        if (in_array($subscriber->status, [Subscriber::STATUS_PENDING, Subscriber::STATUS_UNSUBSCRIBED], true)) {
            $this->markStatus($subscriber->getKey(), Subscriber::STATUS_SUBSCRIBED);
        }
    }

    /**
     * Unsubscribe from specific lists (e.g. the lists a sendout went to), or
     * from everything when no context is given.
     */
    public function unsubscribe(SubscriberContract $subscriber, ?Sendout $sendout = null): void
    {
        $key = $subscriber->getKey();
        $listKeys = $sendout?->list_ids ?? [];

        if ($listKeys !== []) {
            foreach ($listKeys as $listKey) {
                $this->subscribers->setMembership($key, (string) $listKey, null, now());
            }

            if ($this->subscribers->activeListKeys($key) === []) {
                $this->markStatus($key, Subscriber::STATUS_UNSUBSCRIBED);
            }
        } else {
            foreach ($this->subscribers->activeListKeys($key) as $listKey) {
                $this->subscribers->setMembership($key, $listKey, null, now());
            }

            $this->markStatus($key, Subscriber::STATUS_UNSUBSCRIBED);
        }

        SubscriberEvent::create([
            'type' => SubscriberEvent::TYPE_UNSUBSCRIBE,
            'sendout_id' => $sendout?->id,
            'subscriber_key' => $key,
        ]);
    }

    /**
     * Preference-center update: set exactly these list handles as active.
     *
     * @param  array<int, string>  $listKeys
     */
    public function updateListMemberships(SubscriberContract $subscriber, array $listKeys): void
    {
        $key = $subscriber->getKey();
        $active = $this->subscribers->activeListKeys($key);

        // Leave the lists they deselected.
        foreach ($active as $listKey) {
            if (! in_array($listKey, $listKeys, true)) {
                $this->subscribers->setMembership($key, $listKey, null, now());
            }
        }

        // Join (or rejoin) the lists they selected.
        foreach ($listKeys as $listKey) {
            $this->subscribers->setMembership($key, (string) $listKey, now(), null);
        }

        if ($subscriber->status === Subscriber::STATUS_UNSUBSCRIBED && $listKeys !== []) {
            $this->markStatus($key, Subscriber::STATUS_SUBSCRIBED);
        }
    }

    /**
     * Suppress a bounced address: flip its status and record a bounce event
     * (keyed by subscriber_key so mid-send suppression and stats see it).
     */
    public function markBounced(SubscriberContract $subscriber, ?string $detail = null): void
    {
        $this->markSuppressed($subscriber, Subscriber::STATUS_BOUNCED, SubscriberEvent::TYPE_BOUNCE, $detail);
    }

    /**
     * Suppress a complained address (spam report).
     */
    public function markComplained(SubscriberContract $subscriber, ?string $detail = null): void
    {
        $this->markSuppressed($subscriber, Subscriber::STATUS_COMPLAINED, SubscriberEvent::TYPE_COMPLAINT, $detail);
    }

    protected function markSuppressed(SubscriberContract $subscriber, string $status, string $eventType, ?string $detail): void
    {
        $key = $subscriber->getKey();
        $record = $this->toRecord($this->subscribers->findByKey($key) ?? $subscriber);

        if ($record) {
            $record->status = $status;

            if ($status === Subscriber::STATUS_BOUNCED) {
                $record->bounced_at = now();
            }

            if ($status === Subscriber::STATUS_COMPLAINED) {
                $record->complained_at = now();
            }

            $this->subscribers->upsert($record);
        }

        SubscriberEvent::create([
            'type' => $eventType,
            'subscriber_key' => $key,
            'detail' => $detail,
        ]);
    }

    protected function markStatus(string $key, string $status): SubscriberContract
    {
        $record = $this->toRecord($this->subscribers->findByKey($key));

        if (! $record) {
            throw new \RuntimeException("Subscriber [{$key}] not found.");
        }

        $record->status = $status;

        if ($status === Subscriber::STATUS_SUBSCRIBED) {
            $record->verified_at = $record->verified_at ?? now();
            $record->unsubscribed_at = null;
        }

        if ($status === Subscriber::STATUS_UNSUBSCRIBED) {
            $record->unsubscribed_at = now();
        }

        return $this->subscribers->upsert($record);
    }

    protected function requireList(string $handle): ListContract
    {
        $list = $this->lists->findByHandle($handle);

        if (! $list) {
            abort(422, "Unknown mailing list [{$handle}].");
        }

        return $list;
    }

    /**
     * Normalise whatever the repository returned into a mutable SubscriberRecord.
     */
    protected function toRecord(?SubscriberContract $subscriber): ?SubscriberRecord
    {
        if ($subscriber === null) {
            return null;
        }

        if ($subscriber instanceof SubscriberRecord) {
            return $subscriber;
        }

        /** @var Subscriber $subscriber */
        return new SubscriberRecord(
            routeKey: $subscriber->getKey(),
            email: $subscriber->email,
            first_name: $subscriber->first_name,
            last_name: $subscriber->last_name,
            status: $subscriber->status,
            fields: $subscriber->fields ?: [],
            verified_at: $subscriber->verified_at,
            unsubscribed_at: $subscriber->unsubscribed_at,
            bounced_at: $subscriber->bounced_at,
            complained_at: $subscriber->complained_at,
            source: $subscriber->source,
            subscribe_ip: $subscriber->subscribe_ip,
            legacy_id: (int) $subscriber->getKey(),
            exists: true,
        );
    }
}
