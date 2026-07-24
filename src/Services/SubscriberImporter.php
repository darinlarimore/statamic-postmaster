<?php

namespace Darinlarimore\StatamicEmailSubscribe\Services;

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListContract;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Records\SubscriberRecord;
use Darinlarimore\StatamicEmailSubscribe\Support\MergeTags;
use InvalidArgumentException;

/**
 * Shared CSV import behind the CLI command and the CP upload. The header
 * needs an email column; first_name/last_name and any custom subscriber
 * field columns are picked up when present.
 */
class SubscriberImporter
{
    public function __construct(protected SubscriberRepository $subscribers) {}

    /**
     * @return array{added: int, updated: int, skipped: int}
     *
     * @throws InvalidArgumentException when the header has no email column
     */
    public function import(string $path, ?ListContract $list = null, bool $asSubscribed = false): array
    {
        $stream = fopen($path, 'r');
        $header = array_map(fn ($column) => strtolower(trim((string) $column)), fgetcsv($stream) ?: []);

        if (! in_array('email', $header, true)) {
            fclose($stream);

            throw new InvalidArgumentException(__('The CSV needs an "email" column in its header row.'));
        }

        $customHandles = MergeTags::customFieldHandles();

        $added = 0;
        $updated = 0;
        $skipped = 0;

        while (($row = fgetcsv($stream)) !== false) {
            $data = array_combine($header, array_pad($row, count($header), null));
            $email = strtolower(trim($data['email'] ?? ''));

            if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $skipped++;

                continue;
            }

            $existing = $this->subscribers->findByEmail($email);
            $existed = $existing !== null;

            $record = $existing instanceof SubscriberRecord
                ? $existing
                : new SubscriberRecord(
                    routeKey: $this->subscribers->keyForEmail($email),
                    email: $email,
                    status: $asSubscribed ? Subscriber::STATUS_SUBSCRIBED : Subscriber::STATUS_PENDING,
                    source: 'import',
                    verified_at: $asSubscribed ? now() : null,
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

            $customValues = array_filter(
                array_intersect_key($data, array_flip($customHandles->all())),
                fn ($value) => $value !== null && $value !== '',
            );

            if ($customValues !== []) {
                $record->fields = array_merge($record->fields, $customValues);
            }

            $saved = $this->subscribers->upsert($record);

            if ($list) {
                $this->subscribers->setMembership($saved->getKey(), $list->getKey(), now(), null);
            }

            $existed ? $updated++ : $added++;
        }

        fclose($stream);

        return ['added' => $added, 'updated' => $updated, 'skipped' => $skipped];
    }

    /**
     * Header row for a starter CSV: the core columns plus every custom
     * subscriber field currently defined.
     *
     * @return array<int, string>
     */
    public function templateColumns(): array
    {
        return array_merge(
            ['email', 'first_name', 'last_name'],
            MergeTags::customFieldHandles()->all(),
        );
    }

    /**
     * Neutralize CSV-injection payloads before writing a cell. Subscriber
     * names/fields come from the public subscribe form, so a value like
     * `=HYPERLINK(...)` would execute as a formula when the export is opened
     * in a spreadsheet. Prefixing the risky leading characters with a single
     * quote keeps the value readable but inert.
     */
    public static function sanitizeCsvValue(?string $value): string
    {
        $value = (string) $value;

        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            return "'".$value;
        }

        return $value;
    }
}
