<?php

use Darinlarimore\StatamicEmailSubscribe\Mail\VerifyMail;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Support\Urls;
use Illuminate\Support\Facades\Mail;

beforeEach(function () {
    $this->useDriver('database');
    Mail::fake();
    $this->list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);
});

test('subscribing with double opt-in stays pending and sends a verification email', function () {
    $response = $this->post(route('email-subscribe.subscribe'), [
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'lists' => 'newsletter',
    ]);

    $response->assertRedirect();

    $subscriber = Subscriber::firstWhere('email', 'jane@example.com');
    expect($subscriber->status)->toBe(Subscriber::STATUS_PENDING);
    expect($subscriber->lists()->count())->toBe(1);

    Mail::assertSent(VerifyMail::class, fn ($mail) => $mail->hasTo('jane@example.com'));
});

test('the signed verification link subscribes the pending subscriber', function () {
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_PENDING]);
    $subscriber->lists()->attach($this->list->id, ['subscribed_at' => now()]);

    $this->get(Urls::verify($subscriber))->assertOk();

    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_SUBSCRIBED);
    expect($subscriber->fresh()->verified_at)->not->toBeNull();
});

test('a tampered verification link is rejected', function () {
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_PENDING]);
    $other = Subscriber::create(['email' => 'mallory@example.com', 'status' => Subscriber::STATUS_PENDING]);

    $tampered = str_replace('/verify/'.$subscriber->id, '/verify/'.$other->id, Urls::verify($subscriber));

    $this->get($tampered)->assertForbidden();

    expect($other->fresh()->status)->toBe(Subscriber::STATUS_PENDING);
});

test('subscribing without double opt-in activates immediately', function () {
    $this->list->update(['double_opt_in' => false]);

    $this->post(route('email-subscribe.subscribe'), [
        'email' => 'joe@example.com',
        'lists' => 'newsletter',
    ]);

    expect(Subscriber::firstWhere('email', 'joe@example.com')->status)
        ->toBe(Subscriber::STATUS_SUBSCRIBED);

    Mail::assertNothingSent();
});

test('the honeypot swallows bot submissions', function () {
    $this->post(route('email-subscribe.subscribe'), [
        'email' => 'bot@example.com',
        'lists' => 'newsletter',
        'es_website' => 'https://spam.example',
    ])->assertRedirect();

    expect(Subscriber::count())->toBe(0);
});

test('signed unsubscribe link unsubscribes', function () {
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $subscriber->lists()->attach($this->list->id, ['subscribed_at' => now()]);

    $this->get(Urls::unsubscribe($subscriber))->assertOk();

    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);
});

test('one-click unsubscribe works via POST without a session', function () {
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $subscriber->lists()->attach($this->list->id, ['subscribed_at' => now()]);

    $this->post(Urls::oneClickUnsubscribe($subscriber))->assertNoContent();

    expect($subscriber->fresh()->status)->toBe(Subscriber::STATUS_UNSUBSCRIBED);
});

test('preferences page updates list memberships', function () {
    $second = MailingList::create(['title' => 'Product Updates', 'handle' => 'product']);
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $subscriber->lists()->attach($this->list->id, ['subscribed_at' => now()]);

    $this->get(Urls::preferences($subscriber))->assertOk()->assertSee('Newsletter');

    $this->post(Urls::preferences($subscriber), [
        'first_name' => 'Jane',
        'lists' => [$second->id],
    ])->assertRedirect();

    $fresh = $subscriber->fresh();
    expect($fresh->first_name)->toBe('Jane');
    expect($fresh->lists()->wherePivotNull('unsubscribed_at')->pluck('handle')->all())->toBe(['product']);
});
