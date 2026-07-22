<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Darinlarimore\StatamicEmailSubscribe\Support\Urls;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UnsubscribeController extends Controller
{
    public function unsubscribe(Request $request, Subscriber $subscriber, SubscriberService $subscribers)
    {
        $sendout = $request->query('sendout') ? Sendout::find($request->query('sendout')) : null;

        $subscribers->unsubscribe($subscriber, $sendout);

        if ($redirect = config('email_subscribe.redirects.unsubscribe')) {
            return redirect($redirect);
        }

        return view('email-subscribe::web.unsubscribed', [
            'subscriber' => $subscriber,
            'preferencesUrl' => Urls::preferences($subscriber),
        ]);
    }

    /**
     * RFC 8058 one-click unsubscribe (POSTed by the mail client, no body
     * rendered to a human).
     */
    public function oneClick(Request $request, Subscriber $subscriber, SubscriberService $subscribers)
    {
        $sendout = $request->query('sendout') ? Sendout::find($request->query('sendout')) : null;

        $subscribers->unsubscribe($subscriber, $sendout);

        return response()->noContent();
    }
}
