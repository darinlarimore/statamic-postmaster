<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    public function __invoke()
    {
        $subscribers = app(SubscriberRepository::class);

        return view('email-subscribe::cp.dashboard', [
            'title' => 'Email Subscribe',
            'subscriberCount' => $subscribers->query(['status' => Subscriber::STATUS_SUBSCRIBED, 'per_page' => 1])->total(),
            'pendingCount' => $subscribers->query(['status' => Subscriber::STATUS_PENDING, 'per_page' => 1])->total(),
            'listCount' => app(ListRepository::class)->all()->count(),
            'recentSendouts' => Sendout::latest()->limit(5)->get(),
            'recentSubscribers' => collect($subscribers->query(['per_page' => 8])->items()),
        ]);
    }
}
