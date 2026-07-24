<?php

namespace Darinlarimore\Postmaster\Models;

use Darinlarimore\Postmaster\Contracts\ListContract;
use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Sendout\SendoutRecipientResolver;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;

class Sendout extends PostmasterModel
{
    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_SENDING = 'sending';

    const STATUS_PAUSED = 'paused';

    const STATUS_SENT = 'sent';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_FAILED = 'failed';

    protected $table = 'postmaster_sendouts';

    protected $guarded = [];

    protected $casts = [
        'list_ids' => 'array',
        'recipient_keys' => 'array',
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(SubscriberEvent::class, 'sendout_id');
    }

    /**
     * @return Collection<int, ListContract>
     */
    public function lists()
    {
        $repository = app(ListRepository::class);

        return collect($this->list_ids ?? [])
            ->map(fn ($key) => $repository->find((string) $key))
            ->filter()
            ->values();
    }

    public function entry()
    {
        return $this->entry_id ? Entry::find($this->entry_id) : null;
    }

    /**
     * Draft sendouts follow their entry - the entry is the authoring surface
     * for everything: content, subject, sender, recipients, and template.
     * The refreshed values persist when start() saves, freezing the run.
     */
    public function refreshFromEntry(): void
    {
        if ($this->status !== self::STATUS_DRAFT || ! ($entry = $this->entry())) {
            return;
        }

        $this->title = (string) ($entry->get('title') ?: $this->title);
        $this->subject = (string) ($entry->get('email_subject') ?: $entry->get('title') ?: $this->subject);
        $this->preheader = $entry->get('email_preheader') ?? $this->preheader;
        $this->from_name = $entry->get('email_from_name') ?: null;
        $this->from_email = $entry->get('email_from_email') ?: null;
        $this->reply_to = $entry->get('email_reply_to') ?: null;
        $this->template = $entry->get('email_template') ?: null;
        $this->list_ids = array_map('strval', $entry->get('postmaster_lists') ?: []);
    }

    /**
     * A draft whose entry is published has cleared every gate the start
     * button will check - the CP badges show it green as "ready to send".
     */
    public function isReadyToSend(): bool
    {
        return $this->status === self::STATUS_DRAFT && (bool) $this->entry()?->published();
    }

    /**
     * Pending with a future send_at means the scheduler will pick it up -
     * "scheduled" tells editors that, where "pending" reads like a stall.
     */
    public function isScheduled(): bool
    {
        return $this->status === self::STATUS_PENDING && $this->send_at !== null;
    }

    public function statusLabel(): string
    {
        return match (true) {
            $this->isReadyToSend() => __('ready to send'),
            $this->isScheduled() => __('scheduled'),
            default => $this->status,
        };
    }

    public function statusBadgeClass(): string
    {
        return match (true) {
            $this->isReadyToSend() => 'ready',
            $this->isScheduled() => 'scheduled',
            default => $this->status,
        };
    }

    public function isSendable(): bool
    {
        return in_array($this->status, [self::STATUS_PENDING, self::STATUS_SENDING]);
    }

    public function isPaused(): bool
    {
        return $this->status === self::STATUS_PAUSED;
    }

    public function fromEmail(): string
    {
        return $this->from_email ?: config('postmaster.from.email');
    }

    public function fromName(): ?string
    {
        return $this->from_name ?: config('postmaster.from.name');
    }

    protected function resolver(): SendoutRecipientResolver
    {
        return app(SendoutRecipientResolver::class);
    }

    /**
     * Eligible recipient count: subscribed and active on at least one of the
     * sendout's lists, deduplicated. Delegated to the driver-aware resolver.
     */
    public function recipientCount(): int
    {
        return $this->resolver()->recipientCount($this);
    }

    /**
     * Freeze the eligible recipient set at send start so the audience is
     * deterministic and per-batch cost is bounded. Sent-truth still lives in the
     * DB events table, so no-double-send is unaffected.
     */
    public function freezeRecipients(): void
    {
        $this->update(['recipient_keys' => $this->resolver()->candidateKeys($this)]);
    }

    /**
     * The next batch of hydrated recipients that have not yet received this
     * sendout. Re-derived each batch (frozen candidates minus DB terminal
     * events), so pause/resume and job retries can never double-send.
     *
     * @return array<int, SubscriberContract>
     */
    public function nextBatch(int $size): array
    {
        return $this->resolver()->nextBatch($this, $size);
    }

    public function hasPendingRecipients(): bool
    {
        return $this->resolver()->hasPending($this);
    }

    public function stats(): array
    {
        $counts = $this->events()
            ->select('type', DB::raw('count(distinct subscriber_key) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'recipients' => $this->recipient_count,
            'sent' => $this->sent_count,
            'failed' => $this->fail_count,
            'skipped' => $this->skip_count,
            'opens' => $counts[SubscriberEvent::TYPE_OPEN] ?? 0,
            'clicks' => $counts[SubscriberEvent::TYPE_CLICK] ?? 0,
            'bounces' => $counts[SubscriberEvent::TYPE_BOUNCE] ?? 0,
            'complaints' => $counts[SubscriberEvent::TYPE_COMPLAINT] ?? 0,
            'unsubscribes' => $counts[SubscriberEvent::TYPE_UNSUBSCRIBE] ?? 0,
        ];
    }
}
