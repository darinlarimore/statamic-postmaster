<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Models\SubscriberEvent;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;
use Illuminate\Http\Request;
use Statamic\Http\Controllers\CP\CpController;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubscribersController extends CpController
{
    public function index(Request $request)
    {
        $subscribers = app(SubscriberRepository::class)->query([
            'search' => $request->query('search'),
            'status' => $request->query('status'),
            'list' => $request->query('list'),
            'per_page' => 50,
        ])->withQueryString();

        return view('email-subscribe::cp.subscribers', [
            'title' => 'Subscribers',
            'subscribers' => $subscribers,
            'lists' => app(ListRepository::class)->all(),
            'statuses' => [
                Subscriber::STATUS_SUBSCRIBED,
                Subscriber::STATUS_PENDING,
                Subscriber::STATUS_UNSUBSCRIBED,
                Subscriber::STATUS_BOUNCED,
                Subscriber::STATUS_COMPLAINED,
            ],
        ]);
    }

    public function show(SubscriberContract $subscriber)
    {
        return view('email-subscribe::cp.subscriber', [
            'title' => $subscriber->email,
            'subscriber' => $subscriber,
            'events' => SubscriberEvent::query()
                ->where('subscriber_key', $subscriber->getKey())
                ->with('sendout')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function destroy(SubscriberContract $subscriber)
    {
        app(SubscriberRepository::class)->delete($subscriber->getKey());

        return redirect(cp_route('email-subscribe.subscribers.index'))
            ->with('success', __('Subscriber deleted'));
    }

    public function export(Request $request): StreamedResponse
    {
        $status = $request->query('status');
        $repository = app(SubscriberRepository::class);
        $lists = app(ListRepository::class)->all();

        return response()->streamDownload(function () use ($repository, $lists, $status): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['email', 'first_name', 'last_name', 'status', 'lists', 'created_at']);

            foreach ($lists as $list) {
                foreach ($repository->allForList($list->getKey()) as $subscriber) {
                    // Filtering by status here would exclude non-subscribed
                    // members entirely; export() streams active list members.
                    if ($status && $subscriber->status !== $status) {
                        continue;
                    }

                    $handles = $subscriber instanceof SubscriberRecord
                        ? implode('|', $subscriber->activeListHandles())
                        : $subscriber->lists->pluck('handle')->implode('|');

                    fputcsv($out, [
                        $subscriber->email,
                        $subscriber->first_name,
                        $subscriber->last_name,
                        $subscriber->status,
                        $handles,
                        $subscriber->created_at?->toDateTimeString(),
                    ]);
                }
            }

            fclose($out);
        }, 'subscribers.csv', ['Content-Type' => 'text/csv']);
    }
}
