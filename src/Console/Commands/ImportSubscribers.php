<?php

namespace Darinlarimore\StatamicEmailSubscribe\Console\Commands;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberImporter;
use Illuminate\Console\Command;

class ImportSubscribers extends Command
{
    protected $signature = 'email-subscribe:import
        {file : Path to a CSV with an email column (first_name/last_name and custom fields optional)}
        {--list= : Mailing list handle to subscribe imported emails to}
        {--subscribed : Import as already-subscribed, skipping double opt-in}';

    protected $description = 'Import subscribers from a CSV file';

    public function handle(SubscriberImporter $importer): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Can't read file: {$path}");

            return self::FAILURE;
        }

        $list = null;

        if ($handle = $this->option('list')) {
            $list = app(ListRepository::class)->findByHandle($handle);

            if (! $list) {
                $this->error("Mailing list [{$handle}] doesn't exist.");

                return self::FAILURE;
            }
        }

        try {
            $result = $importer->import($path, $list, (bool) $this->option('subscribed'));
        } catch (\InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Imported: {$result['added']} added, {$result['updated']} updated, {$result['skipped']} skipped.");

        return self::SUCCESS;
    }
}
