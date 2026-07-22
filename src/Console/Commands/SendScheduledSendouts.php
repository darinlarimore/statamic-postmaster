<?php

namespace Darinlarimore\StatamicEmailSubscribe\Console\Commands;

use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Illuminate\Console\Command;

class SendScheduledSendouts extends Command
{
    protected $signature = 'email-subscribe:send-scheduled';

    protected $description = 'Queue any scheduled sendouts whose send time has arrived';

    public function handle(SendoutService $sendouts): int
    {
        $count = $sendouts->startDue();

        $this->info($count === 0
            ? 'No scheduled sendouts are due.'
            : "Queued {$count} scheduled sendout(s).");

        return self::SUCCESS;
    }
}
