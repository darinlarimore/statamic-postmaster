<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PreferencesController extends Controller
{
    public function show(Request $request, SubscriberContract $subscriber)
    {
        return view('email-subscribe::web.preferences', [
            'subscriber' => $subscriber,
            'lists' => app(ListRepository::class)->all()->sortBy(fn ($list) => $list->title)->values(),
            'activeListIds' => app(SubscriberRepository::class)->activeListKeys($subscriber->getKey()),
            'actionUrl' => $request->fullUrl(), // signed; POST goes to the same signed URL
        ]);
    }

    public function update(Request $request, SubscriberContract $subscriber, SubscriberService $subscribers)
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'lists' => ['nullable', 'array'],
        ]);

        $subscribers->updateProfile($subscriber, [
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
        ]);

        $subscribers->updateListMemberships(
            $subscribers->refresh($subscriber),
            array_map('strval', $validated['lists'] ?? []),
        );

        return back()->with('email_subscribe.success', __('Your preferences have been updated.'));
    }
}
