<?php

namespace Darinlarimore\StatamicEmailSubscribe\Support;

use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
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
            'email-subscribe.verify',
            now()->addDays(config('email_subscribe.verification_expiry', 7)),
            ['subscriber' => $subscriber->id],
        );
    }

    public static function unsubscribe(SubscriberContract $subscriber, ?Sendout $sendout = null): string
    {
        return URL::signedRoute('email-subscribe.unsubscribe', array_filter([
            'subscriber' => $subscriber->id,
            'sendout' => $sendout?->id,
        ]));
    }

    public static function oneClickUnsubscribe(SubscriberContract $subscriber, ?Sendout $sendout = null): string
    {
        return URL::signedRoute('email-subscribe.unsubscribe.one-click', array_filter([
            'subscriber' => $subscriber->id,
            'sendout' => $sendout?->id,
        ]));
    }

    public static function preferences(SubscriberContract $subscriber): string
    {
        return URL::signedRoute('email-subscribe.preferences', ['subscriber' => $subscriber->id]);
    }

    public static function open(Sendout $sendout, SubscriberContract $subscriber): string
    {
        return URL::signedRoute('email-subscribe.track.open', [
            'sendout' => $sendout->id,
            'subscriber' => $subscriber->id,
        ]);
    }

    public static function click(int $linkId, SubscriberContract $subscriber): string
    {
        return URL::signedRoute('email-subscribe.track.click', [
            'link' => $linkId,
            'subscriber' => $subscriber->id,
        ]);
    }
}
