<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Services\SubscriberService;
use Illuminate\Routing\Controller;

class VerifyController extends Controller
{
    public function __invoke(SubscriberContract $subscriber, SubscriberService $subscribers)
    {
        $subscribers->verify($subscriber);

        if ($redirect = config('postmaster.redirects.verify')) {
            return redirect($redirect);
        }

        return view('postmaster::web.verified', ['subscriber' => $subscriber]);
    }
}
