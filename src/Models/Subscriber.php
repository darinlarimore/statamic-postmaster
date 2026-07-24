<?php

namespace Darinlarimore\Postmaster\Models;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

class Subscriber extends Model implements SubscriberContract
{
    const STATUS_PENDING = 'pending';

    const STATUS_SUBSCRIBED = 'subscribed';

    const STATUS_UNSUBSCRIBED = 'unsubscribed';

    const STATUS_BOUNCED = 'bounced';

    const STATUS_COMPLAINED = 'complained';

    protected $table = 'postmaster_subscribers';

    protected $guarded = [];

    protected $casts = [
        'fields' => 'array',
        'verified_at' => 'datetime',
        'unsubscribed_at' => 'datetime',
        'bounced_at' => 'datetime',
        'complained_at' => 'datetime',
    ];

    public function lists(): BelongsToMany
    {
        return $this->belongsToMany(MailingList::class, 'postmaster_list_subscriber', 'subscriber_id', 'list_id')
            ->withPivot(['subscribed_at', 'unsubscribed_at'])
            ->withTimestamps();
    }

    public function events(): HasMany
    {
        return $this->hasMany(SubscriberEvent::class, 'subscriber_id');
    }

    /**
     * @return Collection<int, object>
     */
    public function listsForView(): Collection
    {
        return $this->lists;
    }

    public function fullName(): ?string
    {
        return trim($this->first_name.' '.$this->last_name) ?: null;
    }

    public function isSendable(): bool
    {
        return $this->status === self::STATUS_SUBSCRIBED;
    }

    public function getKey(): string
    {
        return (string) $this->getAttribute($this->getKeyName());
    }

    public function markSubscribed(): void
    {
        $this->update([
            'status' => self::STATUS_SUBSCRIBED,
            'verified_at' => $this->verified_at ?? now(),
            'unsubscribed_at' => null,
        ]);
    }

    public function markUnsubscribed(): void
    {
        $this->update([
            'status' => self::STATUS_UNSUBSCRIBED,
            'unsubscribed_at' => now(),
        ]);
    }

    public function markBounced(?string $detail = null): void
    {
        $this->update([
            'status' => self::STATUS_BOUNCED,
            'bounced_at' => now(),
        ]);

        $this->events()->create(['type' => SubscriberEvent::TYPE_BOUNCE, 'detail' => $detail]);
    }

    public function markComplained(?string $detail = null): void
    {
        $this->update([
            'status' => self::STATUS_COMPLAINED,
            'complained_at' => now(),
        ]);

        $this->events()->create(['type' => SubscriberEvent::TYPE_COMPLAINT, 'detail' => $detail]);
    }
}
