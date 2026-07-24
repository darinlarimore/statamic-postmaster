<?php

namespace Darinlarimore\Postmaster\Models;

use Darinlarimore\Postmaster\Contracts\ListContract;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MailingList extends Model implements ListContract
{
    protected $table = 'postmaster_lists';

    protected $guarded = [];

    protected $casts = [
        'double_opt_in' => 'boolean',
    ];

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, 'postmaster_list_subscriber', 'list_id', 'subscriber_id')
            ->withPivot(['subscribed_at', 'unsubscribed_at'])
            ->withTimestamps();
    }

    public function activeSubscribers(): BelongsToMany
    {
        return $this->subscribers()
            ->where('status', Subscriber::STATUS_SUBSCRIBED)
            ->wherePivotNull('unsubscribed_at');
    }

    public function usesDoubleOptIn(): bool
    {
        return $this->double_opt_in ?? config('postmaster.double_opt_in', true);
    }

    public function getKey(): string
    {
        return (string) $this->getAttribute($this->getKeyName());
    }
}
