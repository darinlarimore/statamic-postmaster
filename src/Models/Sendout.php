<?php

namespace Darinlarimore\StatamicEmailSubscribe\Models;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Darinlarimore\StatamicEmailSubscribe\Sendout\SendoutRecipientResolver;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Statamic\Facades\Entry;

class Sendout extends Model
{
    const STATUS_DRAFT = 'draft';

    const STATUS_PENDING = 'pending';

    const STATUS_SENDING = 'sending';

    const STATUS_PAUSED = 'paused';

    const STATUS_SENT = 'sent';

    const STATUS_CANCELLED = 'cancelled';

    const STATUS_FAILED = 'failed';

    protected $table = 'email_subscribe_sendouts';

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
        return $this->from_email ?: config('email_subscribe.from.email');
    }

    public function fromName(): ?string
    {
        return $this->from_name ?: config('email_subscribe.from.name');
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
