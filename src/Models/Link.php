<?php

namespace Darinlarimore\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Link extends Model
{
    protected $table = 'postmaster_links';

    protected $guarded = [];

    public function sendout(): BelongsTo
    {
        return $this->belongsTo(Sendout::class, 'sendout_id');
    }
}
