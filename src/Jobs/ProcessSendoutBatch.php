<?php

namespace Darinlarimore\Postmaster\Jobs;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Darinlarimore\Postmaster\Sendout\SendoutRecipientResolver;
use Darinlarimore\Postmaster\Services\SendoutService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\QueryException;
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
            (new WithoutOverlapping('postmaster-sendout-'.$this->sendoutId))
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

        $resolver = app(SendoutRecipientResolver::class);
        $batch = $sendout->nextBatch(config('postmaster.sendout.batch_size', 100));

        foreach ($batch as $subscriber) {
            $sendout->refresh();

            if ($sendout->status !== Sendout::STATUS_SENDING) {
                return;
            }

            // DB-authoritative mid-send suppression: a subscriber who
            // unsubscribed, bounced, or complained after the audience was frozen
            // is skipped (a distinct terminal event, so they leave the pending
            // set) without a real send and without counting as a failure.
            if ($resolver->suppressedByEvent($subscriber->getKey())) {
                $this->recordTerminal($sendout, $subscriber, SubscriberEvent::TYPE_SKIPPED, 'suppressed');
                $sendout->increment('skip_count');

                continue;
            }

            $this->sendToSubscriber($sendout, $subscriber, $service);

            if ($sendout->fail_count >= config('postmaster.sendout.max_failures', 10)) {
                $sendout->update([
                    'status' => Sendout::STATUS_FAILED,
                    'error' => 'Aborted after '.$sendout->fail_count.' failed recipients.',
                ]);

                return;
            }
        }

        if ($resolver->hasPending($sendout)) {
            self::dispatch($this->sendoutId)
                ->onConnection($this->connection)
                ->onQueue($this->queue)
                ->delay(config('postmaster.sendout.batch_delay', 0));

            return;
        }

        $resolver->reconcile($sendout);

        $sendout->update([
            'status' => Sendout::STATUS_SENT,
            'sent_at' => now(),
        ]);

        // The Published toggle means "approved for a send that hasn't
        // happened yet", so a completed run unpublishes the entry. Editing
        // and re-publishing is the deliberate path to a re-send. Quiet save:
        // a normal save here would auto-create the next draft immediately.
        rescue(function () use ($sendout): void {
            $sendout->entry()?->published(false)->saveQuietly();
        });
    }

    protected function sendToSubscriber(Sendout $sendout, SubscriberContract $subscriber, SendoutService $service): void
    {
        $attempts = max(1, config('postmaster.sendout.max_attempts', 3));

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            try {
                $service->deliver($sendout, $subscriber);

                if ($this->recordTerminal($sendout, $subscriber, SubscriberEvent::TYPE_SENT)) {
                    $sendout->increment('sent_count');
                }

                return;
            } catch (\Throwable $exception) {
                if ($attempt === $attempts) {
                    report($exception);

                    if ($this->recordTerminal($sendout, $subscriber, SubscriberEvent::TYPE_FAILED, str($exception->getMessage())->limit(250))) {
                        $sendout->increment('fail_count');
                    }
                } else {
                    sleep(1);
                }
            }
        }
    }

    /**
     * Record a terminal event (sent/failed/skipped) idempotently. The unique
     * terminal_key index makes a duplicate insert throw; a unique violation is
     * treated as already-recorded (returns false, so counters aren't double
     * bumped), but any other database error is rethrown so the recipient is
     * retried rather than silently dropped.
     */
    protected function recordTerminal(Sendout $sendout, SubscriberContract $subscriber, string $type, ?string $detail = null): bool
    {
        try {
            $sendout->events()->create([
                'subscriber_key' => $subscriber->getKey(),
                'terminal_key' => $sendout->id.'-'.$subscriber->getKey(),
                'type' => $type,
                'detail' => $detail,
            ]);

            return true;
        } catch (QueryException $exception) {
            if (in_array($exception->getCode(), ['23000', '23505'], true)) {
                return false;
            }

            throw $exception;
        }
    }
}
