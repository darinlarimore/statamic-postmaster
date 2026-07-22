<?php

use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Jobs\ProcessSendoutBatch;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Models\SubscriberEvent;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Statamic\Facades\Entry;

/**
 * Proves the content storage driver works and that every must-fix invariant
 * holds. The core send/subscribe assertions also run under the database driver
 * via the dataset so both drivers stay in lockstep.
 */
function makeContentSendout(string $driver): Sendout
{
    test()->useDriver($driver);

    $list = makeList(['title' => 'Newsletter', 'handle' => 'newsletter']);

    foreach (['a', 'b', 'c'] as $letter) {
        makeSubscriber([
            'email' => "{$letter}@example.com",
            'first_name' => strtoupper($letter),
            'status' => Subscriber::STATUS_SUBSCRIBED,
        ], [$list]);
    }

    return Sendout::create([
        'title' => 'Issue',
        'subject' => 'The Issue',
        'template' => 'test-campaign',
        'list_ids' => [$list->getKey()],
        'status' => Sendout::STATUS_DRAFT,
    ]);
}

test('a sendout reaches every subscribed recipient exactly once', function (string $driver) {
    Mail::fake();
    $sendout = makeContentSendout($driver);

    // Fake the queue so the chain doesn't auto-drain during start(); drive it.
    Queue::fake();
    app(SendoutService::class)->start($sendout);

    (new ProcessSendoutBatch($sendout->id))->handle(app(SendoutService::class));

    $sendout->refresh();
    expect($sendout->status)->toBe(Sendout::STATUS_SENT);
    expect($sendout->sent_count)->toBe(3);
    expect($sendout->events()->where('type', SubscriberEvent::TYPE_SENT)->pluck('subscriber_key')->duplicates())->toBeEmpty();
})->with(['content', 'database']);

test('subscribing the same email twice collapses to one deterministic entry', function () {
    $this->useDriver('content');
    $list = makeList();

    $first = makeSubscriber(['email' => 'jane@example.com'], [$list]);
    $second = makeSubscriber(['email' => 'JANE@example.com'], [$list]);

    expect($second->getKey())->toBe($first->getKey());
    expect($first->getKey())->toBe(sha1('jane@example.com'));

    $entries = Entry::query()->where('collection', 'email_subscribers')->get();
    expect($entries)->toHaveCount(1);
    expect($entries->first()->id())->toBe($entries->first()->slug());
})->group('content');

test('a mid-send unsubscribe is skipped, not failed, and does not abort the sendout', function (string $driver) {
    Mail::fake();
    config(['email_subscribe.sendout.batch_size' => 1, 'email_subscribe.sendout.max_failures' => 2]);

    $sendout = makeContentSendout($driver);
    $service = app(SendoutService::class);

    // Fake the queue so start() only freezes + dispatches; batches are driven
    // one at a time below (batch_size = 1).
    Queue::fake();
    $service->start($sendout);

    // One of the frozen recipients unsubscribes before their batch — recorded
    // as a DB event keyed by the recipient's own key, which is what the
    // suppression re-check reads (driver-agnostic).
    SubscriberEvent::create([
        'type' => SubscriberEvent::TYPE_UNSUBSCRIBE,
        'subscriber_key' => app(SubscriberRepository::class)->findByEmail('b@example.com')->getKey(),
    ]);

    for ($i = 0; $i < 4; $i++) {
        (new ProcessSendoutBatch($sendout->id))->handle($service);
    }

    $sendout->refresh();
    expect($sendout->status)->toBe(Sendout::STATUS_SENT);
    expect($sendout->skip_count)->toBe(1);
    expect($sendout->fail_count)->toBe(0);
    expect($sendout->sent_count)->toBe(2);
})->with(['content', 'database']);

test('a duplicate terminal event is swallowed but other errors are not', function (string $driver) {
    $sendout = makeContentSendout($driver);
    $key = app(SubscriberRepository::class)->keyForEmail('a@example.com');

    SubscriberEvent::create([
        'sendout_id' => $sendout->id,
        'subscriber_key' => $key,
        'terminal_key' => $sendout->id.'-'.$key,
        'type' => SubscriberEvent::TYPE_SENT,
    ]);

    // Same terminal_key again must violate the unique index.
    expect(fn () => SubscriberEvent::create([
        'sendout_id' => $sendout->id,
        'subscriber_key' => $key,
        'terminal_key' => $sendout->id.'-'.$key,
        'type' => SubscriberEvent::TYPE_SENT,
    ]))->toThrow(QueryException::class);
})->with(['content', 'database']);

test('a legacy integer id in a signed URL still resolves under the content driver', function () {
    $this->useDriver('content');
    $list = makeList();
    $subscriber = makeSubscriber(['email' => 'jane@example.com', 'legacy_id' => 42], [$list]);

    $resolved = app(SubscriberRepository::class)->findByLegacyId(42);
    expect($resolved)->not->toBeNull();
    expect($resolved->getKey())->toBe($subscriber->getKey());
})->group('content');

test('the audience is frozen at send start', function (string $driver) {
    Mail::fake();
    $sendout = makeContentSendout($driver);
    $service = app(SendoutService::class);
    $service->start($sendout);

    $sendout->refresh();
    expect($sendout->recipient_count)->toBe(3);
    expect($sendout->recipient_keys)->toHaveCount(3);

    // A new eligible subscriber joins AFTER the freeze — must not enlarge it.
    makeSubscriber(['email' => 'late@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED], ['newsletter']);

    $sendout->refresh();
    expect($sendout->recipient_keys)->toHaveCount(3);
})->with(['content', 'database']);

test('a dropped frozen recipient is logged and surfaces as integrity drift', function () {
    Mail::fake();

    // Capture the diagnostics channel output via an in-memory Monolog handler.
    $handler = new TestHandler;
    $logger = new Logger('email_subscribe', [$handler]);
    Log::shouldReceive('channel')->with('email_subscribe')->andReturn($logger)->byDefault();

    $sendout = makeContentSendout('content');
    $service = app(SendoutService::class);

    // Fake the queue so start() freezes + dispatches without draining the send;
    // we drive the batch by hand after deleting a recipient.
    Queue::fake();
    $service->start($sendout);

    // Hard-delete one frozen subscriber before their batch is sent.
    Entry::find(app(SubscriberRepository::class)->keyForEmail('c@example.com'))?->delete();

    (new ProcessSendoutBatch($sendout->id))->handle($service);

    // Two recipients delivered; the deleted one is dropped, not sent.
    expect($sendout->fresh()->sent_count)->toBe(2);

    $messages = collect($handler->getRecords())->map(fn ($record) => is_array($record) ? $record['message'] : $record->message);
    expect($messages)->toContain('email-subscribe: dropped recipient');
})->group('content');
