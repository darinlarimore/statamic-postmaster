<?php

namespace Darinlarimore\Postmaster\Contracts;

use Illuminate\Support\Collection;

/**
 * The shared behavioural surface both the Eloquent Subscriber model (database
 * driver) and the SubscriberRecord value object (content driver) expose, so the
 * renderer, mail, signed URLs, and the sendout job treat them identically.
 *
 * Data is read as properties on both implementers — `->id`, `->email`,
 * `->first_name`, `->last_name`, `->status`, `->verified_at`, `->exists` — which
 * the Eloquent model provides via attributes and SubscriberRecord provides as
 * real public properties. Only behaviour lives on the interface.
 */
interface SubscriberContract
{
    /**
     * The route/signing key: the deterministic sha1(email) hash under the
     * content driver, the (string) primary key under the database driver.
     * Equal to `->id` so Urls and route binding are unchanged.
     */
    public function getKey(): string;

    public function fullName(): ?string;

    public function isSendable(): bool;

    /**
     * List memberships shaped for the CP views: each item exposes `->title`,
     * `->handle`, and `->pivot->subscribed_at` / `->pivot->unsubscribed_at`,
     * whichever driver is backing the subscriber.
     *
     * @return Collection<int, object>
     */
    public function listsForView(): Collection;
}
