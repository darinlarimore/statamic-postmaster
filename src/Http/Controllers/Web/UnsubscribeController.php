<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Services\SubscriberService;
use Darinlarimore\Postmaster\Support\Urls;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class UnsubscribeController extends Controller
{
    public function unsubscribe(Request $request, SubscriberContract $subscriber, SubscriberService $subscribers)
    {
        $sendout = $request->query('sendout') ? Sendout::find($request->query('sendout')) : null;

        $subscribers->unsubscribe($subscriber, $sendout);

        if ($redirect = config('postmaster.redirects.unsubscribe')) {
            return redirect($redirect);
        }

        return view('postmaster::web.unsubscribed', [
            'subscriber' => $subscriber,
            'preferencesUrl' => Urls::preferences($subscriber),
        ]);
    }

    /**
     * RFC 8058 one-click unsubscribe (POSTed by the mail client, no body
     * rendered to a human).
     */
    public function oneClick(Request $request, SubscriberContract $subscriber, SubscriberService $subscribers)
    {
        $sendout = $request->query('sendout') ? Sendout::find($request->query('sendout')) : null;

        $subscribers->unsubscribe($subscriber, $sendout);

        return response()->noContent();
    }
}
