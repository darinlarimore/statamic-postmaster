<?php

use Darinlarimore\StatamicEmailSubscribe\Jobs\ProcessSendoutBatch;
use Darinlarimore\StatamicEmailSubscribe\Mail\CampaignMail;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Models\SubscriberEvent;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;

function makeSendout(array $attributes = []): Sendout
{
    $list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);

    foreach (['a', 'b', 'c'] as $letter) {
        $subscriber = Subscriber::create([
            'email' => "{$letter}@example.com",
            'first_name' => strtoupper($letter),
            'status' => Subscriber::STATUS_SUBSCRIBED,
        ]);
        $subscriber->lists()->attach($list->id, ['subscribed_at' => now()]);
    }

    return Sendout::create(array_merge([
        'title' => 'July Issue',
        'subject' => 'The July Issue',
        'template' => 'test-campaign',
        'list_ids' => [$list->id],
        'status' => Sendout::STATUS_DRAFT,
    ], $attributes));
}

test('starting a sendout renders a snapshot and processes batches until everyone is sent', function () {
    Mail::fake();

    $sendout = makeSendout();

    app(SendoutService::class)->start($sendout);

    $sendout->refresh();
    expect($sendout->html)->toContain('%%es:'); // sentinel tokens survive the snapshot
    expect($sendout->recipient_count)->toBe(3);
    expect($sendout->status)->toBe(Sendout::STATUS_SENT); // sync queue runs the whole chain

    Mail::assertSent(CampaignMail::class, 3);
    expect($sendout->events()->where('type', SubscriberEvent::TYPE_SENT)->count())->toBe(3);
});

test('personalization replaces sentinels per recipient and rewrites links', function () {
    $htmls = [];
    Mail::fake();

    $sendout = makeSendout();
    app(SendoutService::class)->start($sendout);

    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) use (&$htmls) {
        $htmls[$mail->subscriber->email] = $mail->htmlBody;

        return true;
    });

    $html = $htmls['a@example.com'];
    expect($html)->toContain('Hi A (a@example.com)');
    expect($html)->not->toContain('%%es:');
    expect($html)->not->toContain('href="https://example.com/article"'); // rewritten to tracking redirect
    expect($html)->toContain('/t/click/');
    expect($html)->toContain('/t/open/');
    expect($html)->toContain('/unsubscribe/');
});

test('unsubscribed, bounced, and pending subscribers are excluded', function () {
    Mail::fake();

    $sendout = makeSendout();

    Subscriber::firstWhere('email', 'a@example.com')->markUnsubscribed();
    Subscriber::firstWhere('email', 'b@example.com')->markBounced();

    app(SendoutService::class)->start($sendout);

    Mail::assertSent(CampaignMail::class, 1);
    expect($sendout->fresh()->recipient_count)->toBe(1);
});

test('pausing mid-send stops the chain and resuming picks up without double-sending', function () {
    Mail::fake();
    config(['email_subscribe.sendout.batch_size' => 1]);

    $sendout = makeSendout();
    $service = app(SendoutService::class);

    // Simulate: snapshot + first batch only (queue faked so no chain).
    Queue::fake();
    $service->start($sendout);
    Queue::assertPushed(ProcessSendoutBatch::class, 1);

    (new ProcessSendoutBatch($sendout->id))->handle($service);
    expect($sendout->fresh()->sent_count)->toBe(1);

    $service->pause($sendout->fresh());

    // A stale queued job for a paused sendout must do nothing.
    (new ProcessSendoutBatch($sendout->id))->handle($service);
    expect($sendout->fresh()->sent_count)->toBe(1);

    // Resume without the real queue, then drain batches manually.
    Queue::fake();
    $service->resume($sendout->fresh());

    (new ProcessSendoutBatch($sendout->id))->handle($service);
    (new ProcessSendoutBatch($sendout->id))->handle($service);

    $sendout->refresh();
    expect($sendout->sent_count)->toBe(3);
    expect($sendout->status)->toBe(Sendout::STATUS_SENT);
    expect($sendout->events()->where('type', SubscriberEvent::TYPE_SENT)->pluck('subscriber_id')->duplicates())->toBeEmpty();
});

test('test emails send synchronously with no tracking and no events', function () {
    Mail::fake();
    Queue::fake();

    $sendout = makeSendout();

    app(SendoutService::class)->sendTest($sendout, 'preview@example.com');

    Queue::assertNothingPushed();
    Mail::assertSent(CampaignMail::class, function (CampaignMail $mail) {
        return $mail->isTest
            && $mail->hasTo('preview@example.com')
            && ! str_contains($mail->htmlBody, '/t/open/')
            && ! str_contains($mail->htmlBody, '%%es:');
    });
    expect(SubscriberEvent::count())->toBe(0);
});

test('scheduled sendouts wait for the cron to launch them', function () {
    Mail::fake();
    Queue::fake();

    $sendout = makeSendout();
    $service = app(SendoutService::class);

    $service->start($sendout, now()->addHour());
    Queue::assertNothingPushed();

    expect($service->startDue())->toBe(0);

    $sendout->update(['send_at' => now()->subMinute()]);
    expect($service->startDue())->toBe(1);
    Queue::assertPushed(ProcessSendoutBatch::class, 1);
});
