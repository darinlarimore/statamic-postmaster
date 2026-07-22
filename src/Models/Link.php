<?php

namespace Darinlarimore\StatamicEmailSubscribe\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    protected $table = 'email_subscribe_links';

    protected $guarded = [];

    public function sendout(): BelongsTo
    {
        return $this->belongsTo(Sendout::class, 'sendout_id');
    }
}
