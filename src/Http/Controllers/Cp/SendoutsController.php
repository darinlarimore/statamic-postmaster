<?php

namespace Darinlarimore\StatamicEmailSubscribe\Http\Controllers\Cp;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Darinlarimore\StatamicEmailSubscribe\Support\CampaignRenderer;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Statamic\Facades\Collection;
use Statamic\Http\Controllers\CP\CpController;

class SendoutsController extends CpController
{
    public function index()
    {
        return view('email-subscribe::cp.sendouts', [
            'title' => 'Sendouts',
            'sendouts' => Sendout::latest()->paginate(25),
        ]);
    }

    public function create()
    {
        return view('email-subscribe::cp.sendout-form', [
            'title' => 'Create Sendout',
            'sendout' => new Sendout(['list_ids' => []]),
            'lists' => app(ListRepository::class)->all(),
            'collections' => Collection::all(),
            'action' => cp_route('email-subscribe.sendouts.store'),
        ]);
    }

    public function store(Request $request, SendoutService $service)
    {
        $sendout = Sendout::create($this->validated($request) + ['status' => Sendout::STATUS_DRAFT]);

        return redirect(cp_route('email-subscribe.sendouts.show', $sendout->id))
            ->with('success', __('Sendout created'));
    }

    public function show(Sendout $sendout)
    {
        return view('email-subscribe::cp.sendout', [
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

    public function edit(Sendout $sendout)
    {
        return view('email-subscribe::cp.sendout-form', [
            'title' => 'Edit Sendout',
            'sendout' => $sendout,
            'lists' => app(ListRepository::class)->all(),
            'collections' => Collection::all(),
            'action' => cp_route('email-subscribe.sendouts.update', $sendout->id),
        ]);
    }

    public function update(Request $request, Sendout $sendout)
    {
        if ($sendout->status !== Sendout::STATUS_DRAFT) {
            return back()->withErrors(['status' => __('Only draft sendouts can be edited.')]);
        }

        $sendout->update($this->validated($request));

        return redirect(cp_route('email-subscribe.sendouts.show', $sendout->id))
            ->with('success', __('Sendout updated'));
    }

    public function destroy(Sendout $sendout)
    {
        if (in_array($sendout->status, [Sendout::STATUS_SENDING, Sendout::STATUS_PENDING])) {
            return back()->withErrors(['status' => __('Cancel the sendout before deleting it.')]);
        }

        $sendout->delete();

        return redirect(cp_route('email-subscribe.sendouts.index'))
            ->with('success', __('Sendout deleted'));
    }

    public function preview(Sendout $sendout, CampaignRenderer $renderer)
    {
        $html = $sendout->html ?: $renderer->render($sendout);

        // Show the preview with sentinel tokens swapped for sample values.
        $html = strtr($html, [
            CampaignRenderer::TOKEN_EMAIL => 'subscriber@example.com',
            CampaignRenderer::TOKEN_FIRST_NAME => 'First',
            CampaignRenderer::TOKEN_LAST_NAME => 'Last',
            CampaignRenderer::TOKEN_FULL_NAME => 'First Last',
            CampaignRenderer::TOKEN_UNSUBSCRIBE_URL => '#',
            CampaignRenderer::TOKEN_PREFERENCES_URL => '#',
            CampaignRenderer::TOKEN_OPEN_PIXEL => '',
        ]);

        $html = preg_replace('/%%es:click:\d+%%/', '#', $html);

        return response($html);
    }

    public function sendTest(Request $request, Sendout $sendout, SendoutService $service)
    {
        $validated = $request->validate(['email' => ['required', 'email']]);

        try {
            $service->sendTest($sendout, $validated['email']);
        } catch (\Throwable $exception) {
            report($exception);

            return back()->withErrors(['email' => __('Test send failed: :message', ['message' => $exception->getMessage()])]);
        }

        return back()->with('success', __('Test email sent to :email', ['email' => $validated['email']]));
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

        return back()->with('success', __('Sendout cancelled'));
    }

    protected function validated(Request $request): array
    {
        $repository = app(ListRepository::class);

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'subject' => ['required', 'string', 'max:255'],
            'preheader' => ['nullable', 'string', 'max:255'],
            'from_email' => ['nullable', 'email'],
            'from_name' => ['nullable', 'string', 'max:255'],
            'reply_to' => ['nullable', 'email'],
            'entry_id' => ['nullable', 'string'],
            'template' => ['nullable', 'string', 'max:255'],
            'list_ids' => ['required', 'array', 'min:1'],
            'list_ids.*' => ['string', function (string $attribute, mixed $value, callable $fail) use ($repository): void {
                if (! $repository->find((string) $value)) {
                    $fail(__('The selected mailing list is invalid.'));
                }
            }],
        ]);

        $validated['list_ids'] = array_map('strval', $validated['list_ids']);

        return $validated;
    }
}
