<?php

namespace Darinlarimore\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriberEvent extends Model
{
    const TYPE_SENT = 'sent';

    const TYPE_OPEN = 'open';

    const TYPE_CLICK = 'click';

    const TYPE_BOUNCE = 'bounce';

    const TYPE_COMPLAINT = 'complaint';

    const TYPE_UNSUBSCRIBE = 'unsubscribe';

    const TYPE_FAILED = 'failed';

    const TYPE_SKIPPED = 'skipped';

    protected $table = 'postmaster_events';

    protected $guarded = [];

    public function sendout(): BelongsTo
    {
        return $this->belongsTo(Sendout::class, 'sendout_id');
    }

    /**
     * Only meaningful under the database driver; content-driver events are keyed
     * by subscriber_key instead.
     */
    public function subscriber(): BelongsTo
    {
        return $this->belongsTo(Subscriber::class, 'subscriber_id');
    }
}
