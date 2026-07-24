<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Link;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class TrackingController extends Controller
{
    // Transparent 1x1 GIF.
    const PIXEL = 'R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7';

    public function open(Request $request, Sendout $sendout, SubscriberContract $subscriber)
    {
        $sendout->events()->create([
            'subscriber_key' => $subscriber->getKey(),
            'type' => SubscriberEvent::TYPE_OPEN,
            'detail' => str($request->userAgent() ?? '')->limit(250),
        ]);

        return response(base64_decode(self::PIXEL))
            ->header('Content-Type', 'image/gif')
            ->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
    }

    public function click(Request $request, Link $link, SubscriberContract $subscriber)
    {
        $link->sendout->events()->create([
            'subscriber_key' => $subscriber->getKey(),
            'type' => SubscriberEvent::TYPE_CLICK,
            'url' => $link->url,
        ]);

        // A click implies an open, even if the pixel was blocked.
        $hasOpen = $link->sendout->events()
            ->where('subscriber_key', $subscriber->getKey())
            ->where('type', SubscriberEvent::TYPE_OPEN)
            ->exists();

        if (! $hasOpen) {
            $link->sendout->events()->create([
                'subscriber_key' => $subscriber->getKey(),
                'type' => SubscriberEvent::TYPE_OPEN,
                'detail' => 'inferred-from-click',
            ]);
        }

        return redirect()->away($link->url);
    }
}
