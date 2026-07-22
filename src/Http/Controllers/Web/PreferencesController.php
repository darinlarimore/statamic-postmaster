<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PreferencesController extends Controller
{
    public function show(Request $request, Subscriber $subscriber)
    {
        return view('email-subscribe::web.preferences', [
            'subscriber' => $subscriber,
            'lists' => MailingList::orderBy('title')->get(),
            'activeListIds' => $subscriber->lists()->wherePivotNull('unsubscribed_at')->pluck('email_subscribe_lists.id')->all(),
            'actionUrl' => $request->fullUrl(), // signed; POST goes to the same signed URL
        ]);
    }

    public function update(Request $request, Subscriber $subscriber, SubscriberService $subscribers)
    {
        $validated = $request->validate([
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'lists' => ['nullable', 'array'],
            'lists.*' => ['integer'],
        ]);

        $subscriber->update(array_filter([
            'first_name' => $validated['first_name'] ?? null,
            'last_name' => $validated['last_name'] ?? null,
        ], fn ($value) => $value !== null));

        $subscribers->updateListMemberships($subscriber, array_map('intval', $validated['lists'] ?? []));

        return back()->with('email_subscribe.success', __('Your preferences have been updated.'));
    }
}
