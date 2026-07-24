<?php

use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Darinlarimore\Postmaster\Support\Storage;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useDriver('database');
    $this->actingAs(tap(User::make()->makeSuper()->email('admin@example.com'))->save());
});

test('the CP pages render', function () {
    $list = MailingList::create(['title' => 'Newsletter', 'handle' => 'newsletter']);
    $subscriber = Subscriber::create(['email' => 'jane@example.com', 'status' => Subscriber::STATUS_SUBSCRIBED]);
    $sendout = Sendout::create(['title' => 'Issue', 'subject' => 'Issue', 'list_ids' => [$list->id], 'status' => 'draft']);

    $this->get(cp_route('postmaster.index'))->assertOk()->assertSee('Postmaster');
    $this->get(cp_route('postmaster.lists.index'))->assertOk()->assertSee('Newsletter');
    $this->get(cp_route('postmaster.subscribers.index'))->assertOk()->assertSee('jane@example.com');
    $this->get(cp_route('postmaster.subscribers.show', $subscriber->id))->assertOk();
    $this->get(cp_route('postmaster.sendouts.index'))->assertOk()->assertSee('Issue');
    $this->get(cp_route('postmaster.sendouts.show', $sendout->id))->assertOk();
});

test('CP routes reject users without permission', function () {
    $this->actingAs(tap(User::make()->email('nobody@example.com'))->save());

    $status = $this->get(cp_route('postmaster.index'))->getStatusCode();

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

test('the subscriber CP pages render list memberships under both drivers', function (string $driver) {
    $this->useDriver($driver);

    $list = makeList(['title' => 'Newsletter', 'handle' => 'newsletter']);
    $subscriber = makeSubscriber([
        'email' => 'member@example.com',
        'status' => Subscriber::STATUS_SUBSCRIBED,
    ], [$list]);

    // The content driver stores memberships by handle; Eloquent joins titles.
    $expectedLabel = $driver === 'content' ? 'newsletter' : 'Newsletter';

    $this->get(cp_route('postmaster.subscribers.index'))
        ->assertOk()
        ->assertSee('member@example.com')
        ->assertSee($expectedLabel);

    $this->get(cp_route('postmaster.subscribers.show', $subscriber->getKey()))
        ->assertOk()
        ->assertSee($expectedLabel);
})->with(['content', 'database']);

test('legacy storage-bag fields are removed from the subscriber blueprint', function () {
    $handle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');
    $blueprint = Blueprint::find('collections.'.$handle.'.'.$handle);

    $contents = $blueprint->contents();
    $contents['tabs']['main']['sections'][0]['fields'][] = ['handle' => 'lists', 'field' => ['type' => 'array', 'display' => 'List Memberships']];
    $contents['tabs']['main']['sections'][0]['fields'][] = ['handle' => 'fields', 'field' => ['type' => 'yaml', 'display' => 'Custom Fields']];
    $blueprint->setContents($contents)->save();

    Storage::bootStorage();

    $healed = Blueprint::find('collections.'.$handle.'.'.$handle);
    expect($healed->hasField('lists'))->toBeFalse();
    expect($healed->hasField('fields'))->toBeFalse();
});

test('the internal storage collections are excluded from SEO', function () {
    expect(Collection::findByHandle('postmaster_subscribers')->cascade('seo'))->toBeFalse();
    expect(Collection::findByHandle('postmaster_lists')->cascade('seo'))->toBeFalse();
});

test('the internal storage collections are hidden from the content nav', function () {
    $this->useDriver('content');

    makeList(['title' => 'Nav List', 'handle' => 'nav-list']);

    $response = $this->get(cp_route('postmaster.index'))->assertOk();

    expect($response->getContent())->not->toContain('Postmasterrs');
    expect($response->getContent())->not->toContain('Email Lists');
});

test('the native collection tables are reachable; only subscriber entry-create is guarded', function () {
    $this->useDriver('content');

    $list = makeList(['title' => 'Redirect List', 'handle' => 'redirect-list']);
    $subscriber = makeSubscriber(['email' => 'redirected@example.com', 'status' => 'subscribed'], [$list]);

    // Native entry tables open normally (with membership data present).
    $this->get(cp_route('collections.show', 'postmaster_subscribers'))->assertOk();
    $this->get(cp_route('collections.show', 'postmaster_lists'))->assertOk();

    // Entry editing stays native too.
    $this->get(cp_route('collections.entries.edit', ['postmaster_subscribers', $subscriber->getKey()]))->assertOk();

    // Creating a subscriber as a raw entry would bypass sha1(email) keying.
    $this->get(cp_route('collections.entries.create', ['postmaster_subscribers', 'default']))
        ->assertRedirect(cp_route('postmaster.subscribers.index'));

    // List entries are created natively - that's the editing UI.
    $this->get(cp_route('collections.entries.create', ['postmaster_lists', 'default']))->assertOk();
});
