<?php

namespace Darinlarimore\StatamicEmailSubscribe\Console\Commands;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;
use Illuminate\Console\Command;

class ImportSubscribers extends Command
{
    protected $signature = 'email-subscribe:import
        {file : Path to a CSV with an email column (first_name/last_name optional)}
        {--list= : Mailing list handle to subscribe imported emails to}
        {--subscribed : Import as already-subscribed, skipping double opt-in}';

    protected $description = 'Import subscribers from a CSV file';

    public function handle(): int
    {
        $path = $this->argument('file');

        if (! is_readable($path)) {
            $this->error("Can't read file: {$path}");

            return self::FAILURE;
        }

        $subscribers = app(SubscriberRepository::class);
        $lists = app(ListRepository::class);
        $list = null;

        if ($handle = $this->option('list')) {
            $list = $lists->findByHandle($handle);

            if (! $list) {
                $this->error("Mailing list [{$handle}] doesn't exist.");

                return self::FAILURE;
            }
        }

        $handle = fopen($path, 'r');
        $header = array_map(fn ($column) => strtolower(trim($column)), fgetcsv($handle) ?: []);
        $emailIndex = array_search('email', $header);

        if ($emailIndex === false) {
            $this->error('CSV needs an "email" column in its header row.');

            return self::FAILURE;
        }

        $added = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($handle)) !== false) {
            $data = array_combine($header, array_pad($row, count($header), null));
            $email = strtolower(trim($data['email'] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                continue;
            }

            $existing = $subscribers->findByEmail($email);
            $existed = $existing !== null;

            $record = $existing instanceof SubscriberRecord
                ? $existing
                : new SubscriberRecord(
                    routeKey: $subscribers->keyForEmail($email),
                    email: $email,
                    status: $this->option('subscribed') ? Subscriber::STATUS_SUBSCRIBED : Subscriber::STATUS_PENDING,
                    source: 'import',
                    verified_at: $this->option('subscribed') ? now() : null,
                    exists: $existed,
                );

            if ($existing && ! $existing instanceof SubscriberRecord) {
                // Database driver returned an Eloquent model; carry its identity.
                $record = new SubscriberRecord(
                    routeKey: $existing->getKey(),
                    email: $email,
                    status: $existing->status,
                    source: $existing->source,
                    exists: true,
                );
            }

            if (($data['first_name'] ?? null) !== null) {
                $record->first_name = $data['first_name'];
            }

            if (($data['last_name'] ?? null) !== null) {
                $record->last_name = $data['last_name'];
            }

            $saved = $subscribers->upsert($record);

            if ($list) {
                $subscribers->setMembership($saved->getKey(), $list->getKey(), now(), null);
            }

            $existed ? $updated++ : $added++;
        }

        fclose($handle);

        $this->info("Imported: {$added} added, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
