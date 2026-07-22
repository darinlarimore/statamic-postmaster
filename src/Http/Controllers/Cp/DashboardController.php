<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    public function __invoke()
    {
        return view('email-subscribe::cp.dashboard', [
            'title' => 'Email Subscribe',
            'subscriberCount' => Subscriber::where('status', Subscriber::STATUS_SUBSCRIBED)->count(),
            'pendingCount' => Subscriber::where('status', Subscriber::STATUS_PENDING)->count(),
            'listCount' => MailingList::count(),
            'recentSendouts' => Sendout::latest()->limit(5)->get(),
            'recentSubscribers' => Subscriber::latest()->limit(8)->get(),
        ]);
    }
}
