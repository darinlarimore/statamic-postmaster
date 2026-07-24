<?php

namespace Darinlarimore\StatamicEmailSubscribe\Actions;

use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Statamic\Actions\Action;
use Statamic\Contracts\Entries\Entry;

/**
 * Entry action: test-send a newsletter entry straight from the entry page.
 * Renders through an ephemeral (unsaved) sendout, so no sendout record is
 * needed and nothing is tracked or recorded.
 */
class SendTestEmail extends Action
{
    public static function title()
    {
        return __('Send Test Email');
    }

    public function visibleTo($item)
    {
        return $item instanceof Entry && $item->blueprint()->hasField('email_blocks');
    }

    public function authorize($user, $item)
    {
        return $user->can('send email subscribe sendouts');
    }

    public function buttonText()
    {
        return __('Send Test|Send Tests');
    }

    public function confirmationText()
    {
        return __('Sends immediately - no queue, no tracking, no subscriber records touched.');
    }

    protected function fieldItems()
    {
        return [
            'emails' => [
                'display' => __('Send test to'),
                'type' => 'text',
                'instructions' => __('One or more email addresses, comma separated.'),
                'validate' => 'required',
            ],
        ];
    }

    public function run($items, $values)
    {
        $emails = collect(explode(',', (string) $values['emails']))
            ->map(fn (string $email) => trim($email))
            ->filter()
            ->unique()
            ->values();

        $invalid = $emails->reject(fn (string $email) => filter_var($email, FILTER_VALIDATE_EMAIL));

        if ($emails->isEmpty() || $invalid->isNotEmpty()) {
            throw new \Exception(__('Invalid email address: :emails', ['emails' => $invalid->implode(', ') ?: '-']));
        }

        $service = app(SendoutService::class);

        foreach ($items as $entry) {
            $sendout = new Sendout([
                'title' => (string) $entry->get('title'),
                'subject' => (string) $entry->get('title'),
                'entry_id' => (string) $entry->id(),
                'list_ids' => [],
                'status' => Sendout::STATUS_DRAFT,
            ]);

            $sendout->refreshFromEntry();

            $service->sendTests($sendout, $emails->all());
        }

        return trans_choice('Test email sent to :emails|Test emails sent to :emails', $emails->count(), ['emails' => $emails->implode(', ')]);
    }
}
