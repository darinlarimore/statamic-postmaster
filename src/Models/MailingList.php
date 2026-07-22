<?php

namespace Darinlarimore\StatamicEmailSubscribe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class MailingList extends Model
{
    protected $table = 'email_subscribe_lists';

    protected $guarded = [];

    protected $casts = [
        'double_opt_in' => 'boolean',
    ];

    public function subscribers(): BelongsToMany
    {
        return $this->belongsToMany(Subscriber::class, 'email_subscribe_list_subscriber', 'list_id', 'subscriber_id')
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
        return $this->double_opt_in ?? config('email_subscribe.double_opt_in', true);
    }
}
