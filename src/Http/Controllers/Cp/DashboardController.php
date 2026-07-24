<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Cp;

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\Subscriber;
use Statamic\Http\Controllers\CP\CpController;

class DashboardController extends CpController
{
    public function __invoke()
    {
        $subscribers = app(SubscriberRepository::class);

        return view('postmaster::cp.dashboard', [
            'title' => 'Postmaster',
            'subscriberCount' => $subscribers->query(['status' => Subscriber::STATUS_SUBSCRIBED, 'per_page' => 1])->total(),
            'pendingCount' => $subscribers->query(['status' => Subscriber::STATUS_PENDING, 'per_page' => 1])->total(),
            'listCount' => app(ListRepository::class)->all()->count(),
            'recentSendouts' => Sendout::latest()->limit(5)->get(),
            'recentSubscribers' => collect($subscribers->query(['per_page' => 8])->items()),
        ]);
    }
}
