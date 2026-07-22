<?php

namespace Darinlarimore\StatamicEmailSubscribe\Console\Commands;

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
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

        $list = null;

        if ($handle = $this->option('list')) {
            $list = MailingList::where('handle', $handle)->first();

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

            $subscriber = Subscriber::firstOrNew(['email' => $email]);
            $existed = $subscriber->exists;

            $subscriber->fill(array_filter([
                'first_name' => $data['first_name'] ?? null,
                'last_name' => $data['last_name'] ?? null,
            ]));

            if (! $existed) {
                $subscriber->source = 'import';
                $subscriber->status = $this->option('subscribed')
                    ? Subscriber::STATUS_SUBSCRIBED
                    : Subscriber::STATUS_PENDING;

                if ($this->option('subscribed')) {
                    $subscriber->verified_at = now();
                }
            }

            $subscriber->save();

            if ($list) {
                $subscriber->lists()->syncWithoutDetaching([
                    $list->id => ['subscribed_at' => now()],
                ]);
            }

            $existed ? $updated++ : $added++;
        }

        fclose($handle);

        $this->info("Imported: {$added} added, {$updated} updated, {$skipped} skipped.");

        return self::SUCCESS;
    }
}
