<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Services\SubscriberService;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Inbound bounce/complaint webhooks. Point your provider at:
 *
 *   POST /postmaster/webhook/{provider}?key={signing_key}
 *
 * Supported providers: ses, mailgun, postmark, sendgrid.
 *
 * Handlers always return 2xx for events they don't act on - most providers
 * auto-disable webhooks that keep failing.
 */
class WebhookController extends Controller
{
    public function __invoke(Request $request, string $provider)
    {
        if (! $this->authorized($request)) {
            return response()->json(['message' => 'Invalid key'], 403);
        }

        $interactions = match ($provider) {
            'ses' => $this->parseSes($request),
            'mailgun' => $this->parseMailgun($request),
            'postmark' => $this->parsePostmark($request),
            'sendgrid' => $this->parseSendgrid($request),
            default => null,
        };

        if ($interactions === null) {
            // Unknown provider slug: 2xx so a typo'd URL doesn't get the
            // webhook auto-disabled, but log loudly.
            Log::warning("Postmaster: webhook received for unknown provider [{$provider}]");

            return response()->json(['message' => 'Unknown provider, ignored']);
        }

        $subscribers = app(SubscriberRepository::class);
        $service = app(SubscriberService::class);

        foreach ($interactions as [$type, $email, $detail]) {
            $subscriber = $subscribers->findByEmail(strtolower($email));

            if (! $subscriber) {
                continue;
            }

            match ($type) {
                'bounce' => $service->markBounced($subscriber, $detail),
                'complaint' => $service->markComplained($subscriber, $detail),
                default => null,
            };
        }

        return response()->json(['message' => 'ok']);
    }

    protected function authorized(Request $request): bool
    {
        $key = config('postmaster.webhooks.signing_key');

        return $key && hash_equals($key, (string) $request->query('key'));
    }

    /**
     * Amazon SES via SNS. Auto-confirms the SNS subscription on first
     * contact, then handles Bounce (permanent only) and Complaint events.
     */
    protected function parseSes(Request $request): array
    {
        $body = json_decode($request->getContent(), true) ?? [];

        if (($body['Type'] ?? null) === 'SubscriptionConfirmation' && isset($body['SubscribeURL'])) {
            Http::get($body['SubscribeURL']);

            return [];
        }

        $message = is_string($body['Message'] ?? null)
            ? (json_decode($body['Message'], true) ?? [])
            : ($body['Message'] ?? []);

        $eventType = $message['notificationType'] ?? $message['eventType'] ?? null;

        if ($eventType === 'Bounce' && ($message['bounce']['bounceType'] ?? null) === 'Permanent') {
            return collect($message['bounce']['bouncedRecipients'] ?? [])
                ->map(fn ($recipient) => ['bounce', $recipient['emailAddress'], $recipient['diagnosticCode'] ?? 'ses-permanent-bounce'])
                ->all();
        }

        if ($eventType === 'Complaint') {
            return collect($message['complaint']['complainedRecipients'] ?? [])
                ->map(fn ($recipient) => ['complaint', $recipient['emailAddress'], $message['complaint']['complaintFeedbackType'] ?? 'ses-complaint'])
                ->all();
        }

        return [];
    }

    protected function parseMailgun(Request $request): array
    {
        $event = $request->input('event-data', []);
        $email = $event['recipient'] ?? null;

        if (! $email) {
            return [];
        }

        if (($event['event'] ?? null) === 'complained') {
            return [['complaint', $email, 'mailgun-complaint']];
        }

        if (($event['event'] ?? null) === 'failed' && ($event['severity'] ?? null) === 'permanent') {
            return [['bounce', $email, $event['delivery-status']['message'] ?? 'mailgun-permanent-failure']];
        }

        return [];
    }

    protected function parsePostmark(Request $request): array
    {
        $email = $request->input('Email') ?? $request->input('Recipient');

        if (! $email) {
            return [];
        }

        return match ($request->input('RecordType')) {
            'Bounce' => in_array($request->input('Type'), ['HardBounce', 'BadEmailAddress', 'ManuallyDeactivated'])
                ? [['bounce', $email, $request->input('Description', 'postmark-bounce')]]
                : [],
            'SpamComplaint' => [['complaint', $email, 'postmark-spam-complaint']],
            default => [],
        };
    }

    protected function parseSendgrid(Request $request): array
    {
        return collect($request->json()->all())
            ->filter(fn ($event) => is_array($event) && isset($event['email']))
            ->flatMap(fn ($event) => match ($event['event'] ?? null) {
                'bounce', 'dropped' => [['bounce', $event['email'], $event['reason'] ?? 'sendgrid-bounce']],
                'spamreport' => [['complaint', $event['email'], 'sendgrid-spam-report']],
                default => [],
            })
            ->all();
    }
}
