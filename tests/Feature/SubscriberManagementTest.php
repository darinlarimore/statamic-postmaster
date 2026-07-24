<?php

use Darinlarimore\StatamicEmailSubscribe\Contracts\ListRepository;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Mail\VerifyMail;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Services\SubscriberImporter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Statamic\Facades\Blink;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useDriver('database');
    $this->actingAs(tap(User::make()->makeSuper()->email('admin@example.com'))->save());
    config()->set('email_subscribe.custom_fields', ['company']);
});

test('subscribers can be added from the CP as already-subscribed', function () {
    $list = MailingList::create(['title' => 'CP List', 'handle' => 'cp-list']);

    $this->post(cp_route('email-subscribe.subscribers.store'), [
        'email' => 'added@example.com',
        'first_name' => 'Addie',
        'company' => 'Added Co',
        'list_ids' => [(string) $list->id],
    ])->assertSessionHasNoErrors()->assertRedirect();

    $subscriber = app(SubscriberRepository::class)->findByEmail('added@example.com');

    expect($subscriber->status)->toBe('subscribed');
    expect($subscriber->first_name)->toBe('Addie');
    expect(($subscriber->fields ?? [])['company'] ?? null)->toBe('Added Co');
    expect($subscriber->source)->toBe('cp');

    $this->post(cp_route('email-subscribe.subscribers.store'), ['email' => 'added@example.com'])
        ->assertSessionHasErrors('email');
});

test('subscribers can be imported from the CP with custom columns', function () {
    $list = MailingList::create(['title' => 'Import List', 'handle' => 'import-list']);

    $file = UploadedFile::fake()->createWithContent('subscribers.csv',
        "email,first_name,company\nimported@example.com,Immy,Import Co\nnot-an-email,Bad,\n");

    $this->post(cp_route('email-subscribe.subscribers.import'), [
        'file' => $file,
        'list' => (string) $list->id,
        'subscribed' => '1',
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $subscriber = app(SubscriberRepository::class)->findByEmail('imported@example.com');

    expect($subscriber)->not->toBeNull();
    expect($subscriber->status)->toBe('subscribed');
    expect(($subscriber->fields ?? [])['company'] ?? null)->toBe('Import Co');
});

test('a CSV without an email column is rejected with a clear error', function () {
    $file = UploadedFile::fake()->createWithContent('bad.csv', "name,company\nJane,Acme\n");

    $this->post(cp_route('email-subscribe.subscribers.import'), ['file' => $file])
        ->assertSessionHasErrors('file');
});

test('the CSV template includes custom field columns', function () {
    $response = $this->get(cp_route('email-subscribe.subscribers.import-template'))->assertOk();

    expect($response->streamedContent())->toContain('email,first_name,last_name,company');
});

test('the subscribers page shows the add and import tools', function () {
    $this->get(cp_route('email-subscribe.subscribers.index'))
        ->assertOk()
        ->assertSee('Add subscriber')
        ->assertSee('es-add-subscriber', escape: false)
        ->assertSee('es-import-subscribers', escape: false)
        ->assertSee('Download the CSV template')
        ->assertSee('Company');
});

test('list titles link to their underlying entry under the content driver', function () {
    $this->useDriver('content');

    $list = app(ListRepository::class)->create([
        'title' => 'Linked List', 'handle' => 'linked-list', 'double_opt_in' => true,
    ]);

    $entryUrl = Entry::find($list->getKey())->editUrl();

    $this->get(cp_route('email-subscribe.lists.index'))
        ->assertOk()
        ->assertSee($entryUrl, escape: false);
});

test('adding with the opt-in option sends a confirmation email and stays pending', function () {
    Mail::fake();

    $this->post(cp_route('email-subscribe.subscribers.store'), [
        'email' => 'optin@example.com',
        'opt_in' => 'confirm',
    ])->assertSessionHasNoErrors();

    $subscriber = app(SubscriberRepository::class)->findByEmail('optin@example.com');

    expect($subscriber->status)->toBe('pending');
    Mail::assertSent(VerifyMail::class, 1);
});

test('all subscriber data is editable, including lists and custom fields', function () {
    $listA = MailingList::create(['title' => 'List A', 'handle' => 'list-a']);
    $listB = MailingList::create(['title' => 'List B', 'handle' => 'list-b']);

    $this->post(cp_route('email-subscribe.subscribers.store'), [
        'email' => 'editable@example.com',
        'first_name' => 'Before',
        'company' => 'Before Co',
        'list_ids' => [(string) $listA->id],
    ]);

    $repository = app(SubscriberRepository::class);
    $subscriber = $repository->findByEmail('editable@example.com');

    $this->post(cp_route('email-subscribe.subscribers.update', $subscriber->getKey()), [
        'email' => 'edited@example.com',
        'first_name' => 'After',
        'sub_status' => 'subscribed',
        'company' => 'After Co',
        'list_ids' => [(string) $listB->id],
    ])->assertSessionHasNoErrors();

    expect($repository->findByEmail('editable@example.com'))->toBeNull();

    $edited = $repository->findByEmail('edited@example.com');

    expect($edited->first_name)->toBe('After');
    expect(($edited->fields ?? [])['company'] ?? null)->toBe('After Co');

    $activeTitles = $edited->listsForView()
        ->filter(fn ($list) => ! $list->pivot->unsubscribed_at)
        ->pluck('title');

    expect($activeTitles->all())->toBe(['List B']);
});

test('subscriber edits rekey the entry under the content driver', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);

    $this->post(cp_route('email-subscribe.subscribers.store'), ['email' => 'rekey@example.com']);

    $subscriber = $repository->findByEmail('rekey@example.com');
    $oldKey = $subscriber->getKey();

    $this->post(cp_route('email-subscribe.subscribers.update', $oldKey), [
        'email' => 'rekeyed@example.com',
        'sub_status' => 'subscribed',
    ])->assertSessionHasNoErrors();

    expect(Entry::find($oldKey))->toBeNull();
    expect($repository->findByEmail('rekeyed@example.com')->getKey())->toBe($repository->keyForEmail('rekeyed@example.com'));
});

test('lists can be created as CP entries and resolve by handle', function () {
    $this->useDriver('content');

    $entry = Entry::make()
        ->collection('email_lists')
        ->slug('cp-made-list')
        ->data(['title' => 'CP Made List']);
    $entry->save();

    $list = app(ListRepository::class)->findByHandle('cp-made-list');

    expect($list)->not->toBeNull();
    expect($list->title)->toBe('CP Made List');
    expect($entry->fresh()->get('handle'))->toBe('cp-made-list');
});

test('subscriber entries get membership checkboxes and activity history injected', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);
    $listRepository = app(ListRepository::class);
    $list = $listRepository->create(['title' => 'Inject List', 'handle' => 'inject-list', 'double_opt_in' => false]);

    $this->post(cp_route('email-subscribe.subscribers.store'), [
        'email' => 'inject@example.com',
        'list_ids' => ['inject-list'],
    ]);

    // Each CP page load is a fresh request; emulate that so the
    // blueprint-found event re-fires with the up-to-date entry.
    Blink::flush();
    Blink::store('blueprints.found')->flush();
    Blink::store('blueprints.from-file')->flush();

    $entry = Entry::find($repository->keyForEmail('inject@example.com'));
    $blueprint = $entry->blueprint();

    fwrite(STDERR, 'LISTS: '.json_encode($entry->get('lists')).' NS: '.$blueprint->namespace()."\n");
    expect($blueprint->hasField('active_lists'))->toBeTrue();
    expect($blueprint->field('active_lists')->config()['default'] ?? null)->toBe(['inject-list']);
    expect($blueprint->hasField('activity_history'))->toBeTrue();
});

test('saving a subscriber entry reconciles memberships and strips the transient key', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);
    $listRepository = app(ListRepository::class);
    $listRepository->create(['title' => 'Sync A', 'handle' => 'sync-a', 'double_opt_in' => false]);
    $listRepository->create(['title' => 'Sync B', 'handle' => 'sync-b', 'double_opt_in' => false]);

    $this->post(cp_route('email-subscribe.subscribers.store'), [
        'email' => 'sync@example.com',
        'list_ids' => ['sync-a'],
    ]);

    $entry = Entry::find($repository->keyForEmail('sync@example.com'));
    $entry->set('active_lists', ['sync-b']);
    $entry->save();

    $subscriber = $repository->findByEmail('sync@example.com');

    expect($subscriber->activeListHandles())->toBe(['sync-b']);
    expect(Entry::find($subscriber->getKey())->get('active_lists'))->toBeNull();
});

test('editing the email on a subscriber entry rekeys it to sha1', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);
    $this->post(cp_route('email-subscribe.subscribers.store'), ['email' => 'entrykey@example.com']);

    $oldKey = $repository->keyForEmail('entrykey@example.com');
    $entry = Entry::find($oldKey);
    $entry->set('email', 'entrykeyed@example.com');
    $entry->save();

    expect(Entry::find($oldKey))->toBeNull();
    expect($repository->findByEmail('entrykeyed@example.com'))->not->toBeNull();
    expect($repository->findByEmail('entrykeyed@example.com')->getKey())->toBe($repository->keyForEmail('entrykeyed@example.com'));
});

test('subscriber rows link to the entry editor under the content driver', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);
    $this->post(cp_route('email-subscribe.subscribers.store'), ['email' => 'rowlink@example.com']);

    $entry = Entry::find($repository->keyForEmail('rowlink@example.com'));

    $this->get(cp_route('email-subscribe.subscribers.index'))
        ->assertOk()
        ->assertSee($entry->editUrl(), escape: false);
});

test('subscriber entries carry the email as their title', function () {
    $this->useDriver('content');

    expect(Collection::findByHandle('email_subscribers')->autoGeneratesTitles())->toBeTrue();

    $this->post(cp_route('email-subscribe.subscribers.store'), ['email' => 'titled@example.com']);

    $repository = app(SubscriberRepository::class);
    $entry = Entry::find($repository->keyForEmail('titled@example.com'));

    expect($entry->get('title'))->toBe('titled@example.com');
});

test('CSV export neutralizes formula-injection payloads in subscriber data', function () {
    $list = MailingList::create(['title' => 'Export List', 'handle' => 'export-list']);
    $subscriber = Subscriber::create([
        'email' => 'attacker@example.com',
        'first_name' => '=HYPERLINK("http://evil","click")',
        'last_name' => '+1+2',
        'status' => 'subscribed',
    ]);
    app(SubscriberRepository::class)->setMembership($subscriber->getKey(), (string) $list->id, now(), null);

    $content = $this->get(cp_route('email-subscribe.subscribers.export'))->assertOk()->streamedContent();

    // Dangerous leading characters are prefixed with a quote, so no formula runs.
    expect($content)->toContain("'=HYPERLINK");
    expect($content)->toContain("'+1+2");
    expect($content)->not->toContain(',=HYPERLINK');
});

test('the CSV sanitizer only quotes risky leading characters', function () {
    $sanitize = fn ($value) => SubscriberImporter::sanitizeCsvValue($value);

    expect($sanitize('Jane'))->toBe('Jane');
    expect($sanitize('jane@example.com'))->toBe('jane@example.com');
    expect($sanitize('=cmd'))->toBe("'=cmd");
    expect($sanitize('@SUM(1)'))->toBe("'@SUM(1)");
    expect($sanitize('-2+3'))->toBe("'-2+3");
    expect($sanitize(null))->toBe('');
});
