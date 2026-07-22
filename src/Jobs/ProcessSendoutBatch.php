<?php

namespace Darinlarimore\StatamicEmailSubscribe\Jobs;

use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Models\SubscriberEvent;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;

/**
 * Sends one batch of a sendout, then re-dispatches itself until no pending
 * recipients remain. One batch per job keeps each job comfortably inside
 * queue timeouts no matter how large the audience is.
 */
class ProcessSendoutBatch implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $timeout = 300;

    public function __construct(public int $sendoutId) {}

    public function middleware(): array
    {
        return [
            (new WithoutOverlapping('email-subscribe-sendout-'.$this->sendoutId))
                ->releaseAfter(30)
                ->expireAfter($this->timeout + 60),
        ];
    }

    public function handle(SendoutService $service): void
    {
        $sendout = Sendout::find($this->sendoutId);

        // Paused, cancelled, or deleted mid-send: stop the chain. Resuming
        // dispatches a fresh job, and the pending-recipients query picks up
        // exactly where this one left off.
        if (! $sendout || ! in_array($sendout->status, [Sendout::STATUS_PENDING, Sendout::STATUS_SENDING])) {
            return;
        }

        if ($sendout->status === Sendout::STATUS_PENDING) {
            $sendout->update(['status' => Sendout::STATUS_SENDING]);
        }

        $batch = $sendout->nextBatch(config('email_subscribe.sendout.batch_size', 100));

        foreach ($batch as $subscriber) {
            $sendout->refresh();

            if ($sendout->status !== Sendout::STATUS_SENDING) {
                return;
            }

            $this->sendToSubscriber($sendout, $subscriber, $service);

            if ($sendout->fail_count >= config('email_subscribe.sendout.max_failures', 10)) {
                $sendout->update([
                    'status' => Sendout::STATUS_FAILED,
                    'error' => 'Aborted after '.$sendout->fail_count.' failed recipients.',
                ]);

                return;
            }
        }

        if ($sendout->pendingRecipientsQuery()->exists()) {
            self::dispatch($this->sendoutId)
                ->onConnection($this->connection)
                ->onQueue($this->queue)
                ->delay(config('email_subscribe.sendout.batch_delay', 0));

            return;
        }

        $sendout->update([
            'status' => Sendout::STATUS_SENT,
            'sent_at' => now(),
        ]);
    }

    protected function sendToSubscriber(Sendout $sendout, Subscriber $subscriber, SendoutService $service): void
    {
        $attempts = max(1, config('email_subscribe.sendout.max_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $service->deliver($sendout, $subscriber);

                $sendout->events()->create([
                    'subscriber_id' => $subscriber->id,
                    'type' => SubscriberEvent::TYPE_SENT,
                ]);
                $sendout->increment('sent_count');

                return;
            } catch (\Throwable $exception) {
                if ($attempt === $attempts) {
                    report($exception);

                    $sendout->events()->create([
                        'subscriber_id' => $subscriber->id,
                        'type' => SubscriberEvent::TYPE_FAILED,
                        'detail' => str($exception->getMessage())->limit(250),
                    ]);
                    $sendout->increment('fail_count');
                } else {
                    sleep(1);
                }
            }
        }
    }
}
