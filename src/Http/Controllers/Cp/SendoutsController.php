<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Cp;

use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Services\SendoutService;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Statamic\Contracts\Entries\Entry;
use Statamic\Facades\Collection;
use Statamic\Facades\Site;
use Statamic\Http\Controllers\CP\CpController;

class SendoutsController extends CpController
{
    public function index()
    {
        return view('postmaster::cp.sendouts', [
            'title' => 'Sendouts',
            'sendouts' => Sendout::latest()->paginate(25),
            'pendingEntries' => $this->entriesAwaitingSendout(),
            'createEntryUrls' => $this->blocksCollections()
                ->mapWithKeys(fn ($collection) => [
                    $collection->title() => cp_route('collections.entries.create', [
                        $collection->handle(),
                        Site::selected()->handle(),
                    ]),
                ])
                ->all(),
        ]);
    }

    /**
     * Collections whose entries are newsletters - any blueprint carrying the
     * Email Blocks field.
     *
     * @return \Illuminate\Support\Collection<int, \Statamic\Entries\Collection>
     */
    protected function blocksCollections()
    {
        return Collection::all()
            ->filter(fn ($collection) => $collection->entryBlueprints()
                ->some(fn ($blueprint) => $blueprint->hasField(CampaignRenderer::BLOCKS_FIELD)))
            ->values();
    }

    /**
     * Newsletter entries (any collection whose blueprint carries Email
     * Blocks) that no sendout references yet, so freshly authored emails are
     * visible from this screen instead of silently living elsewhere.
     *
     * @return \Illuminate\Support\Collection<int, Entry>
     */
    protected function entriesAwaitingSendout()
    {
        $usedEntryIds = Sendout::query()->whereNotNull('entry_id')->pluck('entry_id')->all();

        return $this->blocksCollections()
            ->flatMap(fn ($collection) => $collection->queryEntries()->limit(50)->get())
            ->reject(fn ($entry) => in_array((string) $entry->id(), $usedEntryIds))
            ->take(10)
            ->values();
    }

    public function show(Sendout $sendout)
    {
        return view('postmaster::cp.sendout', [
            'title' => $sendout->title,
            'sendout' => $sendout,
            'stats' => $sendout->stats(),
            'topLinks' => $sendout->events()
                ->where('type', 'click')
                ->selectRaw('url, count(*) as clicks')
                ->groupBy('url')
                ->orderByDesc('clicks')
                ->limit(10)
                ->get(),
        ]);
    }

    public function destroy(Sendout $sendout)
    {
        if (in_array($sendout->status, [Sendout::STATUS_SENDING, Sendout::STATUS_PENDING])) {
            return back()->withErrors(['status' => __('Cancel the sendout before deleting it.')]);
        }

        $sendout->delete();

        return redirect(cp_route('postmaster.sendouts.index'))
            ->with('success', __('Sendout deleted'));
    }

    public function preview(Sendout $sendout, CampaignRenderer $renderer)
    {
        $html = $sendout->html ?: $renderer->render($sendout);

        return response($renderer->withSampleData($html));
    }

    public function sendTest(Request $request, Sendout $sendout, SendoutService $service)
    {
        $validated = $request->validate(['emails' => ['required', 'string']]);

        $emails = collect(explode(',', $validated['emails']))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique()
            ->values();

        if ($emails->isEmpty() || $emails->reject(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL))->isNotEmpty()) {
            return back()->withErrors(['emails' => __('Enter one or more valid email addresses, comma separated.')]);
        }

        try {
            $service->sendTests($sendout, $emails->all());
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['emails' => __('Test send failed: :message', ['message' => $exception->getMessage()])]);
        }

        return back()->with('success', __('Test email sent to :emails', ['emails' => $emails->implode(', ')]));
    }

    public function start(Request $request, Sendout $sendout, SendoutService $service)
    {
        if (! in_array($sendout->status, [Sendout::STATUS_DRAFT, Sendout::STATUS_CANCELLED])) {
            return back()->withErrors(['status' => __('This sendout has already been started.')]);
        }

        $sendAt = $request->input('send_at') ? Carbon::parse($request->input('send_at')) : null;

        try {
            $service->start($sendout, $sendAt);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['status' => __('Could not start sendout: :message', ['message' => $exception->getMessage()])]);
        }

        return back()->with('success', $sendAt
            ? __('Sendout scheduled for :time', ['time' => $sendAt->toDayDateTimeString()])
            : __('Sendout queued'));
    }

    public function pause(Sendout $sendout, SendoutService $service)
    {
        $service->pause($sendout);

        return back()->with('success', __('Sendout paused'));
    }

    public function resume(Sendout $sendout, SendoutService $service)
    {
        $service->resume($sendout);

        return back()->with('success', __('Sendout resumed'));
    }

    public function cancel(Sendout $sendout, SendoutService $service)
    {
        $service->cancel($sendout);

        return back()->with('success', $sendout->status === Sendout::STATUS_DRAFT
            ? __('Sendout cancelled - back to a draft, ready to send again.')
            : __('Sendout cancelled'));
    }
}
