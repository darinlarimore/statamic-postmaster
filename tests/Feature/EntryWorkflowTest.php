<?php

use Darinlarimore\StatamicEmailSubscribe\Actions\SendTestEmail;
use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberRepository;
use Darinlarimore\StatamicEmailSubscribe\Jobs\ProcessSendoutBatch;
use Darinlarimore\StatamicEmailSubscribe\Mail\CampaignMail;
use Darinlarimore\StatamicEmailSubscribe\Models\MailingList;
use Darinlarimore\StatamicEmailSubscribe\Models\Sendout;
use Darinlarimore\StatamicEmailSubscribe\Models\Subscriber;
use Darinlarimore\StatamicEmailSubscribe\Services\SendoutService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useDriver('database');
    $this->artisan('email-subscribe:scaffold-newsletter');
});

function makeWorkflowEntry(array $data = [], string $slug = 'workflow-issue', bool $published = true): mixed
{
    $entry = Entry::make()->collection('newsletters')->slug($slug)->published($published)->data($data + [
        'title' => 'Workflow Issue',
        'email_blocks' => [['type' => 'heading', 'enabled' => true, 'text' => 'Hi', 'size' => 'h2']],
    ]);
    $entry->save();

    return $entry;
}

function sendoutFor(mixed $entry): Sendout
{
    return Sendout::where('entry_id', (string) $entry->id())->latest('id')->firstOrFail();
}

test('saving a new entry automatically creates one draft sendout', function () {
    $list = MailingList::create(['title' => 'Auto List', 'handle' => 'auto-list']);

    $entry = makeWorkflowEntry([
        'email_subject' => 'Custom Subject {{ first_name }}',
        'email_preheader' => 'A preheader',
        'email_lists' => [(string) $list->id],
    ], 'auto-issue');

    $sendout = sendoutFor($entry);

    expect($sendout->status)->toBe(Sendout::STATUS_DRAFT);
    expect($sendout->subject)->toBe('Custom Subject {{ first_name }}');
    expect($sendout->preheader)->toBe('A preheader');
    expect($sendout->list_ids)->toBe([(string) $list->id]);

    $entry->save();
    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(1);
});

test('the subject falls back to the entry title', function () {
    $entry = makeWorkflowEntry([], 'fallback-issue');

    expect(sendoutFor($entry)->subject)->toBe('Workflow Issue');
});

test('editing an entry after a completed send creates a fresh draft for re-sending', function () {
    $entry = makeWorkflowEntry([], 'resend-issue');

    sendoutFor($entry)->update(['status' => Sendout::STATUS_SENT, 'sent_count' => 3]);

    $entry->save();

    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(2);
    expect(sendoutFor($entry)->status)->toBe(Sendout::STATUS_DRAFT);
});

test('no new draft appears while a run is underway', function () {
    $entry = makeWorkflowEntry([], 'busy-issue');

    sendoutFor($entry)->update(['status' => Sendout::STATUS_SENDING, 'sent_count' => 1]);

    $entry->save();

    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(1);
});

test('starting requires a published entry and picked lists', function () {
    $list = MailingList::create(['title' => 'Gate List', 'handle' => 'gate-list']);
    $service = app(SendoutService::class);

    $unpublished = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'gate-unpublished', published: false);
    expect(fn () => $service->start(sendoutFor($unpublished)))
        ->toThrow(RuntimeException::class, 'unpublished');

    $noLists = makeWorkflowEntry(['email_lists' => []], 'gate-no-lists');
    expect(fn () => $service->start(sendoutFor($noLists)))
        ->toThrow(RuntimeException::class, 'Send to lists');
});

test('draft sendouts read "ready to send" once their entry is published', function () {
    $list = MailingList::create(['title' => 'Label List', 'handle' => 'label-list']);
    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'label-issue');

    expect(sendoutFor($entry)->statusLabel())->toBe('ready to send');

    $entry->published(false)->save();
    expect(sendoutFor($entry)->statusLabel())->toBe('draft');
});

test('scheduled sendouts read "scheduled" instead of "pending"', function () {
    $list = MailingList::create(['title' => 'Schedule List', 'handle' => 'schedule-list']);
    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'schedule-issue');

    $sendout = sendoutFor($entry);
    app(SendoutService::class)->start($sendout, now()->addDay());
    $sendout->refresh();

    expect($sendout->status)->toBe(Sendout::STATUS_PENDING);
    expect($sendout->statusLabel())->toBe('scheduled');
    expect($sendout->statusBadgeClass())->toBe('scheduled');
});

test('cancelling before anything sends reverts to a ready-to-send draft', function () {
    $list = MailingList::create(['title' => 'Cancel List', 'handle' => 'cancel-list']);
    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'cancel-issue');

    $sendout = sendoutFor($entry);
    $service = app(SendoutService::class);
    $service->start($sendout, now()->addDay());
    $service->cancel($sendout->refresh());
    $sendout->refresh();

    expect($sendout->status)->toBe(Sendout::STATUS_DRAFT);
    expect($sendout->send_at)->toBeNull();
    expect($sendout->html)->toBeNull();
    expect($sendout->statusLabel())->toBe('ready to send');
});

test('cancelling mid-send keeps the cancelled run record', function () {
    $list = MailingList::create(['title' => 'Midsend List', 'handle' => 'midsend-list']);
    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'midsend-issue');

    $sendout = sendoutFor($entry);
    $sendout->update(['status' => Sendout::STATUS_SENDING, 'sent_count' => 5]);

    app(SendoutService::class)->cancel($sendout);

    expect($sendout->refresh()->status)->toBe(Sendout::STATUS_CANCELLED);
});

test('the send test action mails each comma-separated address without extra records', function () {
    Mail::fake();

    $entry = makeWorkflowEntry([], 'test-send-issue', published: false);

    $message = (new SendTestEmail)->run(collect([$entry]), ['emails' => 'one@example.com, two@example.com, one@example.com']);

    Mail::assertSent(CampaignMail::class, 2);
    Mail::assertSent(CampaignMail::class, fn (CampaignMail $mail) => $mail->isTest);
    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(1);
    expect($message)->toContain('one@example.com');
});

test('the send test action rejects invalid addresses', function () {
    Mail::fake();

    $entry = makeWorkflowEntry([], 'bad-test-issue');

    expect(fn () => (new SendTestEmail)->run(collect([$entry]), ['emails' => 'one@example.com, not-an-email']))
        ->toThrow(Exception::class, 'not-an-email');

    Mail::assertNothingSent();
});

test('the send test action is gated and scoped to block entries', function () {
    $entry = Entry::query()->where('collection', 'newsletters')->first();
    $action = new SendTestEmail;

    expect($action->visibleTo($entry))->toBeTrue();
    expect($action->visibleTo('not-an-entry'))->toBeFalse();

    $superUser = tap(User::make()->makeSuper()->email('boss@example.com'))->save();
    expect($action->authorize($superUser, $entry))->toBeTrue();

    $nobody = tap(User::make()->email('nobody@example.com'))->save();
    expect($action->authorize($nobody, $entry))->toBeFalse();
});

test('the sendouts index surfaces entries that have no sendout yet', function () {
    $this->actingAs(tap(User::make()->makeSuper()->email('index@example.com'))->save());

    $entry = makeWorkflowEntry([], 'index-issue');

    // Simulate an entry predating auto-created sendouts.
    Sendout::where('entry_id', (string) $entry->id())->delete();

    $this->get(cp_route('email-subscribe.sendouts.index'))
        ->assertOk()
        ->assertSee('Entries without a sendout')
        ->assertSee($entry->editUrl(), escape: false);

    $entry->save();

    $this->get(cp_route('email-subscribe.sendouts.index'))
        ->assertOk()
        ->assertDontSee($entry->editUrl(), escape: false);
});

test('sender overrides and template flow from the entry to the sendout', function () {
    $entry = makeWorkflowEntry([
        'email_from_name' => 'The Team',
        'email_from_email' => 'team@example.com',
        'email_reply_to' => 'replies@example.com',
        'email_template' => 'test-campaign',
    ], 'sender-issue');

    $sendout = sendoutFor($entry);

    expect($sendout->fromName())->toBe('The Team');
    expect($sendout->fromEmail())->toBe('team@example.com');
    expect($sendout->reply_to)->toBe('replies@example.com');
    expect($sendout->template)->toBe('test-campaign');

    // Blank sender fields fall back to the site defaults.
    $plain = makeWorkflowEntry([], 'plain-sender');
    expect(sendoutFor($plain)->fromEmail())->toBe(config('email_subscribe.from.email'));
});

test('the sendout page shows which lists it sends to', function () {
    $this->actingAs(tap(User::make()->makeSuper()->email('lists@example.com'))->save());

    $list = MailingList::create(['title' => 'VIP Subscribers', 'handle' => 'vip-subscribers']);
    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'lists-shown');

    $this->get(cp_route('email-subscribe.sendouts.show', sendoutFor($entry)->id))
        ->assertOk()
        ->assertSee('VIP Subscribers');
});

test('the sendouts index offers a button to create a new newsletter entry', function () {
    $this->actingAs(tap(User::make()->makeSuper()->email('creator@example.com'))->save());

    $this->get(cp_route('email-subscribe.sendouts.index'))
        ->assertOk()
        ->assertSee('New Newsletter entry')
        ->assertSee(cp_route('collections.entries.create', ['newsletters', 'default']), escape: false);
});

test('the sendout page shows how many people it will send to', function () {
    $this->actingAs(tap(User::make()->makeSuper()->email('counter@example.com'))->save());

    $list = MailingList::create(['title' => 'Count List', 'handle' => 'count-list']);

    foreach (['one', 'two'] as $name) {
        $subscriber = Subscriber::create([
            'email' => "{$name}@example.com",
            'status' => 'subscribed',
        ]);
        app(SubscriberRepository::class)
            ->setMembership($subscriber->getKey(), (string) $list->id, now(), null);
    }

    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'count-issue');

    $this->get(cp_route('email-subscribe.sendouts.show', sendoutFor($entry)->id))
        ->assertOk()
        ->assertSee('2 recipients');
});

test('a completed send unpublishes the entry', function () {
    Mail::fake();
    Queue::fake();

    $list = MailingList::create(['title' => 'Done List', 'handle' => 'done-list']);
    $subscriber = Subscriber::create([
        'email' => 'done@example.com',
        'status' => 'subscribed',
    ]);
    app(SubscriberRepository::class)
        ->setMembership($subscriber->getKey(), (string) $list->id, now(), null);

    $entry = makeWorkflowEntry(['email_lists' => [(string) $list->id]], 'done-issue');
    $sendout = sendoutFor($entry);

    app(SendoutService::class)->start($sendout);
    (new ProcessSendoutBatch($sendout->id))
        ->handle(app(SendoutService::class));

    expect($sendout->refresh()->status)->toBe(Sendout::STATUS_SENT);
    expect(Entry::find($entry->id())->published())->toBeFalse();

    // No new draft appears from the quiet unpublish save...
    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(1);

    // ...but a deliberate edit-and-save creates the re-send draft as usual.
    Entry::find($entry->id())->save();
    expect(Sendout::where('entry_id', (string) $entry->id())->count())->toBe(2);
});
