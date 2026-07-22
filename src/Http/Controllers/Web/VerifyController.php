<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Illuminate\Routing\Controller;

class VerifyController extends Controller
{
    public function __invoke(SubscriberContract $subscriber, SubscriberService $subscribers)
    {
        $subscribers->verify($subscriber);

        if ($redirect = config('email_subscribe.redirects.verify')) {
            return redirect($redirect);
        }

        return view('email-subscribe::web.verified', ['subscriber' => $subscriber]);
    }
}
