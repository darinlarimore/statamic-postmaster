<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Web;

use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

class SubscribeController extends Controller
{
    public function store(Request $request, SubscriberService $subscribers)
    {
        // Honeypot: bots fill every field; humans never see this one.
        if ($request->filled('es_website')) {
            return $this->success($request);
        }

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'lists' => ['required'],
        ]);

        $lists = is_array($validated['lists']) ? $validated['lists'] : explode('|', $validated['lists']);

        $subscriber = $subscribers->subscribe(
            email: $validated['email'],
            lists: $lists,
            data: array_filter([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
            ]),
            source: 'form',
            ip: $request->ip(),
        );

        return $this->success($request, $subscriber->status === 'pending');
    }

    protected function success(Request $request, bool $pending = false)
    {
        $message = $pending
            ? __('Almost there! Check your inbox to confirm your subscription.')
            : __('You\'re subscribed!');

        if ($request->expectsJson()) {
            return response()->json(['success' => true, 'pending' => $pending, 'message' => $message]);
        }

        if ($redirect = $request->input('_redirect')) {
            return redirect($redirect)->with('email_subscribe.success', $message);
        }

        return back()->with('email_subscribe.success', $message);
    }
}
