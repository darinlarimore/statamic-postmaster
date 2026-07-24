<?php

namespace Darinlarimore\Postmaster\Sendout;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Darinlarimore\Postmaster\Support\Diagnostics;

/**
 * Resolves a sendout's recipients across the content/database split. The
 * candidate set (who is eligible) comes from the subscriber store; the terminal
 * set (who is already sent/failed/skipped) comes from the DB events table, which
 * is the single source of send-truth. Pending = candidates minus terminal,
 * re-derived every batch, so pause/resume/retry can never double-send.
 */
class SendoutRecipientResolver
{
    protected const TERMINAL_TYPES = [
        SubscriberEvent::TYPE_SENT,
        SubscriberEvent::TYPE_FAILED,
        SubscriberEvent::TYPE_SKIPPED,
    ];

    protected const SUPPRESSION_TYPES = [
        SubscriberEvent::TYPE_UNSUBSCRIBE,
        SubscriberEvent::TYPE_BOUNCE,
        SubscriberEvent::TYPE_COMPLAINT,
    ];

    public function __construct(protected SubscriberRepository $subscribers) {}

    /**
     * Every subscribed subscriber active on at least one of the sendout's lists,
     * deduplicated. Read once at send start to freeze the audience.
     *
     * @return array<int, string>
     */
    public function candidateKeys(Sendout $sendout): array
    {
        $listKeys = $sendout->list_ids ?? [];
        $keys = [];

        foreach ($listKeys as $listKey) {
            foreach ($this->subscribers->allForList($listKey) as $record) {
                $keys[$record->getKey()] = true;
            }
        }

        $keys = array_keys($keys);

        if ($keys === [] && $listKeys !== []) {
            Diagnostics::recipientAnomaly('zero_candidates', [
                'sendout_id' => $sendout->id,
                'list_ids' => $listKeys,
            ]);
        }

        return $keys;
    }

    public function recipientCount(Sendout $sendout): int
    {
        return count($this->candidateKeys($sendout));
    }

    /**
     * The frozen candidate set captured at send start, falling back to a live
     * computation if a sendout predates the freeze.
     *
     * @return array<int, string>
     */
    public function frozenKeys(Sendout $sendout): array
    {
        return $sendout->recipient_keys ?: $this->candidateKeys($sendout);
    }

    /**
     * Subscriber keys with a terminal (sent/failed/skipped) event for this
     * sendout — the DB source of send-truth.
     *
     * @return array<int, string>
     */
    public function terminalKeys(Sendout $sendout): array
    {
        return SubscriberEvent::query()
            ->where('sendout_id', $sendout->id)
            ->whereIn('type', self::TERMINAL_TYPES)
            ->pluck('subscriber_key')
            ->filter()
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Frozen candidates minus terminal keys, deterministically ordered.
     *
     * @return array<int, string>
     */
    public function pendingKeys(Sendout $sendout): array
    {
        $pending = array_values(array_diff($this->frozenKeys($sendout), $this->terminalKeys($sendout)));
        sort($pending);

        return $pending;
    }

    /**
     * The next batch of recipients, hydrated. Keys that no longer resolve to a
     * sendable subscriber are logged and dropped.
     *
     * @return array<int, SubscriberContract>
     */
    public function nextBatch(Sendout $sendout, int $size): array
    {
        $keys = array_slice($this->pendingKeys($sendout), 0, $size);
        $batch = [];

        foreach ($keys as $key) {
            $record = $this->subscribers->findByKey($key);

            if (! $record) {
                Diagnostics::droppedRecipient($key, $sendout->id, ['reason' => 'not_found']);

                continue;
            }

            $batch[] = $record;
        }

        return $batch;
    }

    public function hasPending(Sendout $sendout): bool
    {
        return $this->pendingKeys($sendout) !== [];
    }

    /**
     * DB-authoritative mid-send suppression: has this subscriber unsubscribed,
     * bounced, or complained? Read from the events table, not the (possibly
     * stale) content store.
     */
    public function suppressedByEvent(string $subscriberKey): bool
    {
        return SubscriberEvent::query()
            ->where('subscriber_key', $subscriberKey)
            ->whereIn('type', self::SUPPRESSION_TYPES)
            ->exists();
    }

    /**
     * Integrity check run at completion: does the accounting add up?
     *
     * @return array<string, int>
     */
    public function reconcile(Sendout $sendout): array
    {
        $recipientCount = $sendout->recipient_count;
        $sent = $sendout->sent_count;
        $failed = $sendout->fail_count;
        $skipped = $sendout->skip_count;
        $unaccounted = $recipientCount - ($sent + $failed + $skipped);

        $drift = [
            'recipient_count' => $recipientCount,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'unaccounted' => $unaccounted,
        ];

        if ($unaccounted !== 0) {
            Diagnostics::reconcileDrift($sendout->id, $drift);
            Diagnostics::countMismatch($sendout->id, $recipientCount, $sent, $failed, $skipped);
        }

        return $drift;
    }
}
