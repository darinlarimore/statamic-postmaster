<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Mail\UnsubscribeLinkMail;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Darinlarimore\StatamicEmailSubscribe\Support\Urls;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Mail;

class UnsubscribeController extends Controller
{
    /**
     * Site-facing unsubscribe form submission. We never unsubscribe
     * directly from an unauthenticated email address - we email a signed
     * confirmation link instead, and respond identically whether or not
     * the address is subscribed so the form can't be used to probe the
     * list.
     */
    public function request(Request $request)
    {
        if ($request->filled('es_website')) {
            return $this->requested($request);
        }

        $validated = $request->validate(['email' => ['required', 'email']]);

        $subscriber = Subscriber::firstWhere('email', strtolower($validated['email']));

        if ($subscriber && $subscriber->status !== Subscriber::STATUS_UNSUBSCRIBED) {
            Mail::mailer(config('email_subscribe.mailer'))
                ->to($subscriber->email)
                ->send(new UnsubscribeLinkMail($subscriber));
        }

        return $this->requested($request);
    }

    protected function requested(Request $request)
    {
        $message = __('If that address is subscribed, we\'ve emailed it an unsubscribe link.');

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'message' => $message]);
        }

        if ($redirect = $request->input('_redirect')) {
            return redirect($redirect)->with('email_subscribe.success', $message);
        }

        return back()->with('email_subscribe.success', $message);
    }

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
