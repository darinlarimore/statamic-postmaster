<?php

namespace Darinlarimore\StatamicEmailSubscribe\Services;

use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Jobs\ProcessSendoutBatch;
use Darinlarimore\StatamicEmailSubscribe\Mail\CampaignMail;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;
use Darinlarimore\StatamicEmailSubscribe\Support\CampaignRenderer;
use Illuminate\Support\Facades\Mail;

class SendoutService
{
    public function __construct(
        protected CampaignRenderer $renderer,
        protected SubscriberRepository $subscribers,
    ) {}

    /**
     * Compile the HTML snapshot and put the sendout on the queue. Passing a
     * $sendAt schedules it - the campaigns:send-scheduled command (cron)
     * launches it when the time comes.
     */
    public function start(Sendout $sendout, ?\DateTimeInterface $sendAt = null): Sendout
    {
        if (($entry = $sendout->entry()) && ! $entry->published()) {
            throw new \RuntimeException(__('The content entry is unpublished. Publish it to approve this email for sending.'));
        }

        $sendout->refreshFromEntry();

        if (blank($sendout->list_ids)) {
            throw new \RuntimeException(__('No recipients: pick at least one list in the entry\'s "Send to lists" field.'));
        }

        $sendout->update([
            'html' => $this->renderer->render($sendout),
            'recipient_count' => $sendout->recipientCount(),
            'status' => Sendout::STATUS_PENDING,
            'send_at' => $sendAt,
            'error' => null,
        ]);

        $sendout->freezeRecipients();

        if (! $sendAt) {
            $this->dispatchBatchJob($sendout);
        }

        return $sendout;
    }

    /**
     * Launch scheduled sendouts whose time has arrived. Called from the
     * scheduler/cron.
     */
    public function startDue(): int
    {
        $due = Sendout::where('status', Sendout::STATUS_PENDING)
            ->whereNotNull('send_at')
            ->where('send_at', '<=', now())
            ->get();

        foreach ($due as $sendout) {
            $sendout->update(['send_at' => null]);
            $this->dispatchBatchJob($sendout);
        }

        return $due->count();
    }

    public function pause(Sendout $sendout): void
    {
        if (in_array($sendout->status, [Sendout::STATUS_PENDING, Sendout::STATUS_SENDING])) {
            $sendout->update(['status' => Sendout::STATUS_PAUSED]);
        }
    }

    public function resume(Sendout $sendout): void
    {
        if (in_array($sendout->status, [Sendout::STATUS_PAUSED, Sendout::STATUS_FAILED])) {
            $sendout->update(['status' => Sendout::STATUS_SENDING, 'error' => null]);
            $this->dispatchBatchJob($sendout);
        }
    }

    public function cancel(Sendout $sendout): void
    {
        if (in_array($sendout->status, [Sendout::STATUS_SENT, Sendout::STATUS_DRAFT])) {
            return;
        }

        // Nothing delivered yet (e.g. cancelling a schedule): revert to a
        // clean draft - the frozen snapshot is discarded so a later start
        // re-renders and re-freezes fresh. Once emails have gone out, the
        // sendout stays a cancelled run record with its stats.
        if ($sendout->sent_count === 0) {
            $sendout->update([
                'status' => Sendout::STATUS_DRAFT,
                'send_at' => null,
                'html' => null,
                'recipient_keys' => null,
                'recipient_count' => 0,
                'error' => null,
            ]);

            return;
        }

        $sendout->update(['status' => Sendout::STATUS_CANCELLED]);
    }

    /**
     * Send a rendered copy straight to an address - synchronously, skipping
     * the queue, with no tracking and no events recorded.
     */
    public function sendTest(Sendout $sendout, string $email): void
    {
        $this->sendTests($sendout, [$email]);
    }

    /**
     * Test-send to several addresses, rendering once. Renders untracked so
     * ephemeral sendouts (entry test sends) never mint click-tracking links.
     *
     * @param  array<int, string>  $emails
     */
    public function sendTests(Sendout $sendout, array $emails): void
    {
        $html = $this->renderer->render($sendout, trackable: false);

        foreach ($emails as $email) {
            $subscriber = $this->subscribers->findByEmail($email)
                ?? SubscriberRecord::transient($email, 'Test', 'Subscriber');

            $personalized = $this->renderer->personalize($html, $sendout, $subscriber, tracking: false);

            Mail::mailer(config('email_subscribe.mailer'))
                ->to($email)
                ->send(new CampaignMail($sendout, $subscriber, $personalized, isTest: true));
        }
    }

    /**
     * Deliver the sendout to one subscriber. Called from the batch job.
     */
    public function deliver(Sendout $sendout, SubscriberContract $subscriber): void
    {
        $html = $this->renderer->personalize($sendout->html, $sendout, $subscriber);

        Mail::mailer(config('email_subscribe.mailer'))
            ->to($subscriber->email, $subscriber->fullName())
            ->send(new CampaignMail($sendout, $subscriber, $html));
    }

    protected function dispatchBatchJob(Sendout $sendout): void
    {
        ProcessSendoutBatch::dispatch($sendout->id)
            ->onConnection(config('email_subscribe.sendout.connection'))
            ->onQueue(config('email_subscribe.sendout.queue', 'default'));
    }
}
