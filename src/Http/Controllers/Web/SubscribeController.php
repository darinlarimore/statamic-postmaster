<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Services\SubscriberService;
use Darinlarimore\Postmaster\Support\MergeTags;
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

        // Form inputs matching custom subscriber fields (blueprint-defined or
        // configured) are captured too - anything else is ignored.
        $customValues = collect($request->only(MergeTags::customFieldHandles()->all()))
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value) => mb_substr((string) $value, 0, 255))
            ->all();

        $subscriber = $subscribers->subscribe(
            email: $validated['email'],
            lists: $lists,
            data: array_filter([
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
            ]) + $customValues,
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

        if ($redirect = $this->safeRedirect($request->input('_redirect'))) {
            return redirect($redirect)->with('postmaster.success', $message);
        }

        return back()->with('postmaster.success', $message);
    }

    /**
     * Only honor a redirect target that stays on this site: a root-relative
     * path, or an absolute URL whose host matches the app. Anything else
     * (protocol-relative `//evil`, external hosts) is dropped so the public
     * form can't be turned into an open redirect.
     */
    protected function safeRedirect(mixed $target): ?string
    {
        if (! is_string($target) || $target === '') {
            return null;
        }

        if (str_starts_with($target, '/') && ! str_starts_with($target, '//')) {
            return $target;
        }

        $host = parse_url($target, PHP_URL_HOST);

        return $host && $host === parse_url((string) config('app.url'), PHP_URL_HOST)
            ? $target
            : null;
    }
}
