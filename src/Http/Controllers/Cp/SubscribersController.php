<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Cp;

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Mail\VerifyMail;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Darinlarimore\Postmaster\Records\SubscriberRecord;
use Darinlarimore\Postmaster\Services\SubscriberImporter;
use Darinlarimore\Postmaster\Support\MergeTags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Entry;
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

        $entryEditUrls = config('postmaster.storage.driver', 'content') === 'content'
            ? collect($subscribers->items())
                ->mapWithKeys(fn ($subscriber) => [
                    $subscriber->getKey() => Entry::find($subscriber->getKey())?->editUrl(),
                ])
                ->filter()
                ->all()
            : [];

        return view('postmaster::cp.subscribers', [
            'title' => 'Subscribers',
            'subscribers' => $subscribers,
            'entryEditUrls' => $entryEditUrls,
            'lists' => app(ListRepository::class)->all(),
            'customFieldHandles' => MergeTags::customFieldHandles(),
            'statuses' => [
                Subscriber::STATUS_SUBSCRIBED,
                Subscriber::STATUS_PENDING,
                Subscriber::STATUS_UNSUBSCRIBED,
                Subscriber::STATUS_BOUNCED,
                Subscriber::STATUS_COMPLAINED,
            ],
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'list_ids' => ['nullable', 'array'],
        ]);

        $subscribers = app(SubscriberRepository::class);
        $lists = app(ListRepository::class);
        $email = strtolower(trim($validated['email']));

        if ($subscribers->findByEmail($email)) {
            return back()->withErrors(['email' => __('That address is already a subscriber.')]);
        }

        $sendOptIn = $request->input('opt_in') === 'confirm';

        $record = new SubscriberRecord(
            routeKey: $subscribers->keyForEmail($email),
            email: $email,
            first_name: $validated['first_name'] ?? null,
            last_name: $validated['last_name'] ?? null,
            status: $sendOptIn ? Subscriber::STATUS_PENDING : Subscriber::STATUS_SUBSCRIBED,
            source: 'cp',
            verified_at: $sendOptIn ? null : now(),
            exists: false,
        );

        $record->fields = collect($request->only(MergeTags::customFieldHandles()->all()))
            ->filter(fn ($value) => is_scalar($value) && $value !== '')
            ->map(fn ($value) => mb_substr((string) $value, 0, 255))
            ->all();

        $saved = $subscribers->upsert($record);

        foreach ($validated['list_ids'] ?? [] as $listKey) {
            if ($lists->find((string) $listKey)) {
                $subscribers->setMembership($saved->getKey(), (string) $listKey, now(), null);
            }
        }

        if ($sendOptIn) {
            Mail::mailer(config('postmaster.mailer'))
                ->to($saved->email)
                ->send(new VerifyMail($saved));
        }

        return redirect(cp_route('postmaster.subscribers.show', $saved->getKey()))
            ->with('success', $sendOptIn ? __('Subscriber added - confirmation email sent') : __('Subscriber added'));
    }

    public function import(Request $request, SubscriberImporter $importer)
    {
        $validated = $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:10240'],
            'list' => ['nullable', 'string'],
            'subscribed' => ['nullable', 'boolean'],
        ]);

        $list = null;

        if ($listKey = $validated['list'] ?? null) {
            $list = app(ListRepository::class)->find($listKey);

            if (! $list) {
                return back()->withErrors(['list' => __('The selected mailing list is invalid.')]);
            }
        }

        try {
            $result = $importer->import(
                $request->file('file')->getRealPath(),
                $list,
                $request->boolean('subscribed'),
            );
        } catch (\InvalidArgumentException $exception) {
            return back()->withErrors(['file' => $exception->getMessage()]);
        }

        return back()->with('success', __(':added added, :updated updated, :skipped skipped.', $result));
    }

    public function importTemplate(SubscriberImporter $importer): StreamedResponse
    {
        return response()->streamDownload(function () use ($importer): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $importer->templateColumns());
            fputcsv($out, array_merge(
                ['jane@example.com', 'Jane', 'Doe'],
                array_fill(0, count($importer->templateColumns()) - 3, ''),
            ));
            fclose($out);
        }, 'subscribers-template.csv', ['Content-Type' => 'text/csv']);
    }

    public function show(SubscriberContract $subscriber)
    {
        $activeListKeys = $subscriber->listsForView()
            ->filter(fn ($list) => ! $list->pivot->unsubscribed_at)
            ->map(fn ($list) => (string) ($subscriber instanceof SubscriberRecord ? $list->handle : $list->id))
            ->values()
            ->all();

        return view('postmaster::cp.subscriber', [
            'title' => $subscriber->email,
            'subscriber' => $subscriber,
            'lists' => app(ListRepository::class)->all(),
            'customFieldHandles' => MergeTags::customFieldHandles(),
            'activeListKeys' => $activeListKeys,
            'statuses' => [
                Subscriber::STATUS_SUBSCRIBED,
                Subscriber::STATUS_PENDING,
                Subscriber::STATUS_UNSUBSCRIBED,
                Subscriber::STATUS_BOUNCED,
                Subscriber::STATUS_COMPLAINED,
            ],
            'events' => SubscriberEvent::query()
                ->where('subscriber_key', $subscriber->getKey())
                ->with('sendout')
                ->latest()
                ->limit(100)
                ->get(),
        ]);
    }

    public function update(Request $request, SubscriberContract $subscriber)
    {
        $validated = $request->validate([
            'email' => ['required', 'email'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'sub_status' => ['required', 'in:subscribed,pending,unsubscribed,bounced,complained'],
            'list_ids' => ['nullable', 'array'],
        ]);

        $repository = app(SubscriberRepository::class);
        $lists = app(ListRepository::class);
        $email = strtolower(trim($validated['email']));
        $status = $validated['sub_status'];
        $emailChanged = $email !== strtolower($subscriber->email);

        if ($emailChanged && $repository->findByEmail($email)) {
            return back()->withErrors(['email' => __('Another subscriber already has that address.')]);
        }

        $customValues = collect($request->only(MergeTags::customFieldHandles()->all()))
            ->filter(fn ($value) => is_scalar($value))
            ->map(fn ($value) => mb_substr(trim((string) $value), 0, 255))
            ->filter(fn (string $value) => $value !== '')
            ->all();

        if ($subscriber instanceof SubscriberRecord) {
            // Content driver: the entry is keyed by sha1(email), so an email
            // change means a new entry carrying everything over.
            $record = new SubscriberRecord(
                routeKey: $emailChanged ? $repository->keyForEmail($email) : $subscriber->getKey(),
                email: $email,
                first_name: $validated['first_name'] ?? null,
                last_name: $validated['last_name'] ?? null,
                status: $status,
                fields: $customValues,
                lists: $subscriber->lists,
                verified_at: $status === Subscriber::STATUS_SUBSCRIBED ? ($subscriber->verified_at ?? now()) : $subscriber->verified_at,
                unsubscribed_at: $subscriber->unsubscribed_at,
                bounced_at: $subscriber->bounced_at,
                complained_at: $subscriber->complained_at,
                source: $subscriber->source,
                subscribe_ip: $subscriber->subscribe_ip,
                created_at: $subscriber->created_at,
                legacy_id: $subscriber->legacy_id,
                exists: ! $emailChanged,
            );

            $saved = $repository->upsert($record);

            if ($emailChanged) {
                $repository->delete($subscriber->getKey());
            }
        } else {
            $subscriber->update([
                'email' => $email,
                'first_name' => $validated['first_name'] ?? null,
                'last_name' => $validated['last_name'] ?? null,
                'status' => $status,
                'fields' => $customValues,
                'verified_at' => $status === Subscriber::STATUS_SUBSCRIBED
                    ? ($subscriber->verified_at ?? now())
                    : $subscriber->verified_at,
            ]);

            $saved = $subscriber;
        }

        $requested = array_map('strval', $validated['list_ids'] ?? []);
        $active = $saved->listsForView()
            ->filter(fn ($list) => ! $list->pivot->unsubscribed_at)
            ->map(fn ($list) => (string) ($saved instanceof SubscriberRecord ? $list->handle : $list->id))
            ->all();

        foreach ($lists->all() as $list) {
            $listKey = (string) $list->getKey();
            $isRequested = in_array($listKey, $requested, true);
            $isActive = in_array($listKey, $active, true);

            if ($isRequested && ! $isActive) {
                $repository->setMembership($saved->getKey(), $listKey, now(), null);
            } elseif (! $isRequested && $isActive) {
                $repository->setMembership($saved->getKey(), $listKey, null, now());
            }
        }

        return redirect(cp_route('postmaster.subscribers.show', $saved->getKey()))
            ->with('success', __('Subscriber updated'));
    }

    public function destroy(SubscriberContract $subscriber)
    {
        app(SubscriberRepository::class)->delete($subscriber->getKey());

        return redirect(cp_route('postmaster.subscribers.index'))
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

                    fputcsv($out, array_map([SubscriberImporter::class, 'sanitizeCsvValue'], [
                        $subscriber->email,
                        $subscriber->first_name,
                        $subscriber->last_name,
                        $subscriber->status,
                        $handles,
                        $subscriber->created_at?->toDateTimeString(),
                    ]));
                }
            }

            fclose($out);
        }, 'subscribers.csv', ['Content-Type' => 'text/csv']);
    }
}
