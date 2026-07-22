<?php

namespace Darinlarimore\StatamicEmailSubscribe\Services;

use Darinlarimore\StatamicEmailSubscribe\Mail\VerifyMail;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Models\SubscriberEvent;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Mail;

class SubscriberService
{
    /**
     * Subscribe an email address to one or more lists. Returns the
     * subscriber; their status tells you whether they're live or awaiting
     * double opt-in verification.
     */
    public function subscribe(
        string $email,
        Collection|array $lists,
        array $data = [],
        string $source = 'form',
        ?string $ip = null,
    ): Subscriber {
        $lists = collect($lists)
            ->map(fn ($list) => $list instanceof MailingList ? $list : MailingList::where('handle', $list)->firstOrFail());

        $subscriber = Subscriber::firstOrNew(['email' => strtolower($email)]);

        $subscriber->fill(array_filter([
            'first_name' => $data['first_name'] ?? null,
            'last_name' => $data['last_name'] ?? null,
            'fields' => array_diff_key($data, array_flip(['first_name', 'last_name'])) ?: null,
        ]));

        if (! $subscriber->exists) {
            $subscriber->status = Subscriber::STATUS_PENDING;
            $subscriber->source = $source;
            $subscriber->subscribe_ip = $ip;
        }

        // A returning unsubscriber who signs up again gets a clean slate;
        // bounced/complained addresses stay suppressed.
        if ($subscriber->status === Subscriber::STATUS_UNSUBSCRIBED) {
            $subscriber->status = Subscriber::STATUS_PENDING;
            $subscriber->unsubscribed_at = null;
            $subscriber->verified_at = null;
        }

        $subscriber->save();

        foreach ($lists as $list) {
            $subscriber->lists()->syncWithoutDetaching([
                $list->id => ['subscribed_at' => now(), 'unsubscribed_at' => null],
            ]);
            // Re-subscribing to a list they'd left: clear the pivot flag.
            $subscriber->lists()->updateExistingPivot($list->id, [
                'subscribed_at' => now(),
                'unsubscribed_at' => null,
            ]);
        }

        $needsVerification = $lists->contains(fn ($list) => $list->usesDoubleOptIn());

        if ($subscriber->status === Subscriber::STATUS_PENDING) {
            if ($needsVerification && ! $subscriber->verified_at) {
                Mail::mailer(config('email_subscribe.mailer'))
                    ->to($subscriber->email)
                    ->send(new VerifyMail($subscriber));
            } else {
                $subscriber->markSubscribed();
            }
        }

        return $subscriber;
    }

    public function verify(Subscriber $subscriber): void
    {
        if (in_array($subscriber->status, [Subscriber::STATUS_PENDING, Subscriber::STATUS_UNSUBSCRIBED])) {
            $subscriber->markSubscribed();
        }
    }

    /**
     * Unsubscribe from specific lists (e.g. the lists a sendout went to),
     * or from everything when no context is given.
     */
    public function unsubscribe(Subscriber $subscriber, ?Sendout $sendout = null): void
    {
        $listIds = $sendout?->list_ids;

        if ($listIds) {
            foreach ($listIds as $listId) {
                $subscriber->lists()->updateExistingPivot($listId, ['unsubscribed_at' => now()]);
            }

            // Off their last list? Treat it as a full unsubscribe.
            $remaining = $subscriber->lists()->wherePivotNull('unsubscribed_at')->count();

            if ($remaining === 0) {
                $subscriber->markUnsubscribed();
            }
        } else {
            $subscriber->lists()->newPivotStatement()
                ->where('subscriber_id', $subscriber->id)
                ->whereNull('unsubscribed_at')
                ->update(['unsubscribed_at' => now()]);

            $subscriber->markUnsubscribed();
        }

        $subscriber->events()->create([
            'type' => SubscriberEvent::TYPE_UNSUBSCRIBE,
            'sendout_id' => $sendout?->id,
        ]);
    }

    /**
     * Preference-center update: set exactly these lists as active.
     */
    public function updateListMemberships(Subscriber $subscriber, array $listIds): void
    {
        foreach ($subscriber->lists as $list) {
            $active = in_array($list->id, $listIds);

            $subscriber->lists()->updateExistingPivot($list->id, [
                'unsubscribed_at' => $active ? null : ($list->pivot->unsubscribed_at ?? now()),
                'subscribed_at' => $active ? ($list->pivot->subscribed_at ?? now()) : $list->pivot->subscribed_at,
            ]);
        }

        $current = $subscriber->lists()->pluck('email_subscribe_lists.id')->all();

        foreach (array_diff($listIds, $current) as $newListId) {
            $subscriber->lists()->attach($newListId, ['subscribed_at' => now()]);
        }

        if ($subscriber->status === Subscriber::STATUS_UNSUBSCRIBED && $listIds !== []) {
            $subscriber->markSubscribed();
        }
    }
}
