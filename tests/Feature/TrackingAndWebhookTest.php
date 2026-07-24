<?php

use Darinlarimore\Postmaster\Models\Link;
use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Models\SubscriberEvent;
use Darinlarimore\Postmaster\Support\Urls;

beforeEach(function () {
    $this->useDriver('database');
    $this->list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);
    $this->subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $this->sendout = Sendout::create([
        'title' => 'Issue',
        'subject' => 'Issue',
        'list_ids' => [$this->list->id],
        'status' => Sendout::STATUS_SENT,
    ]);
});

test('the open pixel records an open and returns a gif', function () {
    $response = $this->get(Urls::open($this->sendout, $this->subscriber));

    $response->assertOk()->assertHeader('Content-Type', 'image/gif');
    expect($this->sendout->events()->where('type', SubscriberEvent::TYPE_OPEN)->count())->toBe(1);
});

test('clicks record an event, infer an open, and redirect to the target', function () {
    $link = Link::create(['sendout_id' => $this->sendout->id, 'url' => 'https://example.com/article']);

    $this->get(Urls::click($link->id, $this->subscriber))
        ->assertRedirect('https://example.com/article');

    expect($this->sendout->events()->where('type', SubscriberEvent::TYPE_CLICK)->count())->toBe(1);
    expect($this->sendout->events()->where('type', SubscriberEvent::TYPE_OPEN)->count())->toBe(1);
});

test('unsigned tracking URLs are rejected', function () {
    $this->get(route('postmaster.track.open', ['sendout' => $this->sendout->id, 'subscriber' => $this->subscriber->id]))
        ->assertForbidden();
});

test('a bounce webhook suppresses the subscriber', function () {
    $payload = [
        'RecordType' => 'Bounce',
        'Type' => 'HardBounce',
        'Email' => 'jane@example.com',
        'Description' => 'Mailbox does not exist',
    ];

    $this->postJson(route('postmaster.webhook', ['provider' => 'postmark', 'key' => 'test-signing-key']), $payload)
        ->assertOk();

    $fresh = $this->subscriber->fresh();
    expect($fresh->status)->toBe(Subscriber::STATUS_BOUNCED);
    expect(SubscriberEvent::where('subscriber_key', $fresh->getKey())->where('type', SubscriberEvent::TYPE_BOUNCE)->count())->toBe(1);
});

test('a complaint webhook suppresses the subscriber', function () {
    $payload = [
        ['email' => 'jane@example.com', 'event' => 'spamreport'],
    ];

    $this->postJson(route('postmaster.webhook', ['provider' => 'sendgrid', 'key' => 'test-signing-key']), $payload)
        ->assertOk();

    expect($this->subscriber->fresh()->status)->toBe(Subscriber::STATUS_COMPLAINED);
});

test('webhooks with a bad key are rejected', function () {
    $this->postJson(route('postmaster.webhook', ['provider' => 'postmark', 'key' => 'wrong']), [])
        ->assertForbidden();
});

test('webhooks for unmatched events still return 2xx', function () {
    $this->postJson(route('postmaster.webhook', ['provider' => 'postmark', 'key' => 'test-signing-key']), [
        'RecordType' => 'Delivery',
        'Email' => 'jane@example.com',
    ])->assertOk();

    $this->postJson(route('postmaster.webhook', ['provider' => 'unknown-provider', 'key' => 'test-signing-key']), [])
        ->assertOk();

    expect($this->subscriber->fresh()->status)->toBe(Subscriber::STATUS_SUBSCRIBED);
});
