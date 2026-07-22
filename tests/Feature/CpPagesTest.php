<?php

use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Support\CampaignRenderer;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useDriver('database');
    $this->actingAs(tap(User::make()->makeSuper()->email('admin@example.com'))->save());
});

test('the CP pages render', function () {
    $list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $sendout = Sendout::create(['title' => 'Issue', 'subject' => 'Issue', 'list_ids' => [$list->id], 'status' => 'draft']);

    $this->get(cp_route('email-subscribe.index'))->assertOk()->assertSee('Email Subscribe');
    $this->get(cp_route('email-subscribe.lists.index'))->assertOk()->assertSee('Newsletter');
    $this->get(cp_route('email-subscribe.subscribers.index'))->assertOk()->assertSee('jane@example.com');
    $this->get(cp_route('email-subscribe.subscribers.show', $subscriber->id))->assertOk();
    $this->get(cp_route('email-subscribe.sendouts.index'))->assertOk()->assertSee('Issue');
    $this->get(cp_route('email-subscribe.sendouts.create'))->assertOk();
    $this->get(cp_route('email-subscribe.sendouts.show', $sendout->id))->assertOk();
    $this->get(cp_route('email-subscribe.sendouts.edit', $sendout->id))->assertOk();
});

test('CP routes reject users without permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    $status = $this->get(cp_route('email-subscribe.index'))->getStatusCode();

    // Statamic 403s or bounces to login depending on version - either way,
    // no page.
    expect(in_array($status, [302, 403]))->toBeTrue();
});

test('the default MJML template renders with sentinels and entry-less content', function () {
    $list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);
    $sendout = Sendout::create([
        'title' => 'Issue',
        'subject' => 'Hello World',
        'preheader' => 'A preview',
        'list_ids' => [$list->id],
        'status' => 'draft',
    ]);

    $html = app(CampaignRenderer::class)->render($sendout);

    expect($html)->toContain('Hello World');
    expect($html)->toContain('%%es:unsubscribe_url%%');
    expect($html)->toContain('%%es:open_pixel%%');
});
