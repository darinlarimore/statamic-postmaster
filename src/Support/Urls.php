<?php

namespace Darinlarimore\Postmaster\Support;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Sendout;
use Illuminate\Support\Facades\URL;

/**
 * Signed public URLs for subscriber-facing actions. Every URL carries an
 * HMAC signature, so ids can't be enumerated to unsubscribe other people
 * or pollute tracking stats.
 */
class Urls
{
    public static function verify(SubscriberContract $subscriber): string
    {
        return URL::temporarySignedRoute(
            'postmaster.verify',
            now()->addDays(config('postmaster.verification_expiry', 7)),
            ['subscriber' => $subscriber->id],
        );
    }

    public static function unsubscribe(SubscriberContract $subscriber, ?Sendout $sendout = null): string
    {
        return URL::signedRoute('postmaster.unsubscribe', array_filter([
            'subscriber' => $subscriber->id,
            'sendout' => $sendout?->id,
        ]));
    }

    public static function oneClickUnsubscribe(SubscriberContract $subscriber, ?Sendout $sendout = null): string
    {
        return URL::signedRoute('postmaster.unsubscribe.one-click', array_filter([
            'subscriber' => $subscriber->id,
            'sendout' => $sendout?->id,
        ]));
    }

    public static function preferences(SubscriberContract $subscriber): string
    {
        return URL::signedRoute('postmaster.preferences', ['subscriber' => $subscriber->id]);
    }

    public static function open(Sendout $sendout, SubscriberContract $subscriber): string
    {
        return URL::signedRoute('postmaster.track.open', [
            'sendout' => $sendout->id,
            'subscriber' => $subscriber->id,
        ]);
    }

    public static function click(int $linkId, SubscriberContract $subscriber): string
    {
        return URL::signedRoute('postmaster.track.click', [
            'link' => $linkId,
            'subscriber' => $subscriber->id,
        ]);
    }
}
