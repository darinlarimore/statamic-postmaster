<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscribersController extends CpController
{
    public function index(Request $request)
    {
        $query = Subscriber::query()->with('lists');

        if ($search = $request->query('search')) {
            $query->where(function ($inner) use ($search) {
                $inner->where('email', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            });
        }

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        if ($listId = $request->query('list')) {
            $query->whereHas('lists', fn ($inner) => $inner->where('email_subscribe_lists.id', $listId));
        }

        return view('email-subscribe::cp.subscribers', [
            'title' => 'Subscribers',
            'subscribers' => $query->latest()->paginate(50)->withQueryString(),
            'lists' => MailingList::orderBy('title')->get(),
            'statuses' => [
                Subscriber::STATUS_SUBSCRIBED,
                Subscriber::STATUS_PENDING,
                Subscriber::STATUS_UNSUBSCRIBED,
                Subscriber::STATUS_BOUNCED,
                Subscriber::STATUS_COMPLAINED,
            ],
        ]);
    }

    public function show(Subscriber $subscriber)
    {
        return view('email-subscribe::cp.subscriber', [
            'title' => $subscriber->email,
            'subscriber' => $subscriber->load('lists'),
            'events' => $subscriber->events()->with('sendout')->latest()->limit(100)->get(),
        ]);
    }

    public function destroy(Subscriber $subscriber)
    {
        $subscriber->delete();

        return redirect(cp_route('email-subscribe.subscribers.index'))
            ->with('success', __('Subscriber deleted'));
    }

    public function export(Request $request): StreamedResponse
    {
        $query = Subscriber::query()->with('lists');

        if ($status = $request->query('status')) {
            $query->where('status', $status);
        }

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'first_name', 'last_name', 'status', 'lists', 'created_at']);

            $query->chunk(500, function ($subscribers) use ($out) {
                foreach ($subscribers as $subscriber) {
                    fputcsv($out, [
                        $subscriber->email,
                        $subscriber->first_name,
                        $subscriber->last_name,
                        $subscriber->status,
                        $subscriber->lists->pluck('handle')->implode('|'),
                        $subscriber->created_at?->toDateTimeString(),
                    ]);
                }
            });

            fclose($out);
        }, 'subscribers.csv', ['Content-Type' => 'text/csv']);
    }
}
