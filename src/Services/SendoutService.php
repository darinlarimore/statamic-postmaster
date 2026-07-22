<?php

namespace Darinlarimore\StatamicEmailSubscribe\Services;

use Darinlarimore\StatamicEmailSubscribe\Jobs\ProcessSendoutBatch;
use Darinlarimore\StatamicEmailSubscribe\Mail\CampaignMail;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Support\CampaignRenderer;
use Illuminate\Support\Facades\Mail;

class SendoutService
{
    public function __construct(protected CampaignRenderer $renderer) {}

    /**
     * Compile the HTML snapshot and put the sendout on the queue. Passing a
     * $sendAt schedules it - the campaigns:send-scheduled command (cron)
     * launches it when the time comes.
     */
    public function start(Sendout $sendout, ?\DateTimeInterface $sendAt = null): Sendout
    {
        $sendout->update([
            'html' => $this->renderer->render($sendout),
            'recipient_count' => $sendout->recipientsQuery()->count(),
            'status' => Sendout::STATUS_PENDING,
            'send_at' => $sendAt,
            'error' => null,
        ]);

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
        if (! in_array($sendout->status, [Sendout::STATUS_SENT, Sendout::STATUS_DRAFT])) {
            $sendout->update(['status' => Sendout::STATUS_CANCELLED]);
        }
    }

    /**
     * Send a rendered copy straight to an address - synchronously, skipping
     * the queue, with no tracking and no events recorded.
     */
    public function sendTest(Sendout $sendout, string $email): void
    {
        $subscriber = Subscriber::firstWhere('email', $email) ?? new Subscriber([
            'email' => $email,
            'first_name' => 'Test',
            'last_name' => 'Subscriber',
        ]);

        $html = $this->renderer->personalize(
            $this->renderer->render($sendout),
            $sendout,
            $subscriber,
            tracking: false,
        );

        Mail::mailer(config('email_subscribe.mailer'))
            ->to($email)
            ->send(new CampaignMail($sendout, $subscriber, $html, isTest: true));
    }

    /**
     * Deliver the sendout to one subscriber. Called from the batch job.
     */
    public function deliver(Sendout $sendout, Subscriber $subscriber): void
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
