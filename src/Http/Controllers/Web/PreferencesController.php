<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class PreferencesController extends Controller
{
    public function show(Request $request, SubscriberContract $subscriber)
    {
        return view('postmaster::web.preferences', [
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

        return back()->with('postmaster.success', __('Your preferences have been updated.'));
    }
}
