<?php

namespace Darinlarimore\StatamicEmailSubscribe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
        'send_at' => 'datetime',
        'sent_at' => 'datetime',
    ];

    public function events(): HasMany
    {
        return $this->hasMany(SubscriberEvent::class, 'sendout_id');
    }

    public function lists()
    {
        return MailingList::whereIn('id', $this->list_ids ?? [])->get();
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

    /**
     * Subscribers eligible for this sendout: subscribed status, active on at
     * least one of the sendout's lists, deduplicated across lists.
     */
    public function recipientsQuery()
    {
        return Subscriber::query()
            ->where('status', Subscriber::STATUS_SUBSCRIBED)
            ->whereIn('id', function ($query) {
                $query->select('subscriber_id')
                    ->from('email_subscribe_list_subscriber')
                    ->whereIn('list_id', $this->list_ids ?? [])
                    ->whereNull('unsubscribed_at');
            });
    }

    /**
     * Recipients that haven't been sent this sendout yet. Re-queried each
     * batch so pause/resume and job retries can never double-send: a
     * subscriber leaves the pending set the moment their "sent" event is
     * recorded.
     */
    public function pendingRecipientsQuery()
    {
        return $this->recipientsQuery()
            ->whereNotIn('id', function ($query) {
                $query->select('subscriber_id')
                    ->from('email_subscribe_events')
                    ->where('sendout_id', $this->id)
                    ->whereIn('type', [SubscriberEvent::TYPE_SENT, SubscriberEvent::TYPE_FAILED]);
            })
            ->orderBy('id');
    }

    public function nextBatch(int $size)
    {
        return $this->pendingRecipientsQuery()->limit($size)->get();
    }

    public function stats(): array
    {
        $counts = $this->events()
            ->select('type', DB::raw('count(distinct subscriber_id) as total'))
            ->groupBy('type')
            ->pluck('total', 'type');

        return [
            'recipients' => $this->recipient_count,
            'sent' => $this->sent_count,
            'failed' => $this->fail_count,
            'opens' => $counts[SubscriberEvent::TYPE_OPEN] ?? 0,
            'clicks' => $counts[SubscriberEvent::TYPE_CLICK] ?? 0,
            'bounces' => $counts[SubscriberEvent::TYPE_BOUNCE] ?? 0,
            'complaints' => $counts[SubscriberEvent::TYPE_COMPLAINT] ?? 0,
            'unsubscribes' => $counts[SubscriberEvent::TYPE_UNSUBSCRIBE] ?? 0,
        ];
    }
}
