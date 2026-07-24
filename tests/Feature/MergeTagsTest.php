<?php

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Contracts\SubscriberRepository;
use Darinlarimore\Postmaster\Mail\CampaignMail;
use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Models\Subscriber;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Darinlarimore\Postmaster\Support\MergeTags;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

beforeEach(function () {
    $this->useDriver('database');
    $this->artisan('postmaster:scaffold-newsletter');
});

// The subscriber blueprint persists in the testbench skeleton between runs -
// strip the company field the available-tags test adds, even if it failed.
afterEach(function () {
    $handle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');
    $blueprint = Blueprint::find('collections.'.$handle.'.'.$handle);

    if (! $blueprint) {
        return;
    }

    $contents = $blueprint->contents();
    $fields = collect($contents['tabs']['main']['sections'][0]['fields'])
        ->reject(fn (array $field) => ($field['handle'] ?? null) === 'company')
        ->values()
        ->all();

    $contents['tabs']['main']['sections'][0]['fields'] = $fields;
    $blueprint->setContents($contents)->save();
});

function makeMergeTagEntry(array $blocks, string $slug): mixed
{
    $entry = Entry::make()
        ->collection('newsletters')
        ->slug($slug)
        ->data(['title' => 'Merge Issue', 'email_blocks' => $blocks]);

    $entry->save();

    return $entry;
}

function makeMergeTagSendout(mixed $entry): Sendout
{
    $list = MailingList::create(['title' => 'Merge List', 'handle' => 'merge-'.uniqid()]);

    return Sendout::create([
        'title' => 'Merge Issue',
        'subject' => 'Hi {{ first_name }} from {{ company }}',
        'entry_id' => $entry->id(),
        'list_ids' => [$list->id],
        'status' => Sendout::STATUS_DRAFT,
    ]);
}

test('editor-typed merge tags personalize per subscriber, including custom fields', function () {
    $entry = makeMergeTagEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Hello {{ first_name }}', 'size' => 'h2'],
        ['type' => 'text', 'enabled' => true, 'text' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'You work at {{ company }}, and {{ nonexistent }} is unknown.']]],
        ]],
    ], 'personalized');

    $renderer = app(CampaignRenderer::class);
    $sendout = makeMergeTagSendout($entry);
    $html = $renderer->render($sendout);

    expect($html)->toContain('%%es:first_name%%');
    expect($html)->toContain('%%es:field:company%%');

    $subscriber = Subscriber::create([
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'status' => Subscriber::STATUS_SUBSCRIBED,
        'fields' => ['company' => 'Acme Co'],
    ]);

    $personalized = $renderer->personalize($html, $sendout, $subscriber);

    expect($personalized)->toContain('Hello Jane');
    expect($personalized)->toContain('You work at Acme Co, and  is unknown.');
    expect($personalized)->not->toContain('%%es:');
});

test('subject lines personalize merge tags', function () {
    $subscriber = Subscriber::create([
        'email' => 'jane@example.com',
        'first_name' => 'Jane',
        'status' => Subscriber::STATUS_SUBSCRIBED,
        'fields' => ['company' => 'Acme Co'],
    ]);

    $sendout = makeMergeTagSendout(makeMergeTagEntry([], 'subject-tags'));

    $mail = new CampaignMail($sendout, $subscriber, '<html></html>');

    expect($mail->envelope()->subject)->toBe('Hi Jane from Acme Co');
});

test('previews show readable sample values for custom field tags', function () {
    $entry = makeMergeTagEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'For {{ company_name }}', 'size' => 'h2'],
    ], 'sample-preview');

    $renderer = app(CampaignRenderer::class);
    $html = $renderer->withSampleData($renderer->render(makeMergeTagSendout($entry)));

    expect($html)->toContain('For Company Name');
    expect($html)->not->toContain('%%es:');
});

test('blueprint-added subscriber fields resolve under the content driver', function () {
    $this->useDriver('content');

    $repository = app(SubscriberRepository::class);
    $key = $repository->keyForEmail('custom-jane@example.com');

    $entry = Entry::make()
        ->collection('postmaster_subscribers')
        ->id($key)
        ->slug($key)
        ->data([
            'email' => 'custom-jane@example.com',
            'first_name' => 'Jane',
            'sub_status' => 'subscribed',
            'company' => 'Acme Co',
            'lists' => [],
        ]);
    $entry->save();

    $subscriber = $repository->findByEmail('custom-jane@example.com');

    expect($subscriber)->not->toBeNull();
    expect(MergeTags::resolve($subscriber, 'company'))->toBe('Acme Co');
});

test('available tags list core tags plus custom subscriber blueprint fields', function () {
    $tags = MergeTags::available();

    expect($tags)->toContain('email', 'first_name', 'last_name', 'full_name', 'unsubscribe_url', 'preferences_url');
    expect($tags)->not->toContain('sub_status', 'subscribe_ip', 'legacy_id');

    $handle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');
    $blueprint = Blueprint::find('collections.'.$handle.'.'.$handle);
    $contents = $blueprint->contents();
    $fields = collect($contents['tabs']['main']['sections'][0]['fields']);

    if (! $fields->contains(fn (array $field) => ($field['handle'] ?? null) === 'company')) {
        $contents['tabs']['main']['sections'][0]['fields'][] = ['handle' => 'company', 'field' => ['type' => 'text', 'display' => 'Company']];
        $blueprint->setContents($contents)->save();
    }

    expect(MergeTags::available())->toContain('company');
});

test('the entry edit blueprint lists available merge tags', function () {
    $entry = makeMergeTagEntry([], 'hint-check');

    $instructions = $entry->blueprint()->field('email_merge_tags_hint')->config()['instructions'] ?? '';

    expect($instructions)->toContain('{{ first_name }}');
    expect($instructions)->toContain('{{ unsubscribe_url }}');
});

function addCompanyToSubscriberBlueprint(): void
{
    $handle = config('postmaster.storage.collections.subscribers', 'postmaster_subscribers');
    $blueprint = Blueprint::find('collections.'.$handle.'.'.$handle);
    $contents = $blueprint->contents();
    $fields = collect($contents['tabs']['main']['sections'][0]['fields']);

    if (! $fields->contains(fn (array $field) => ($field['handle'] ?? null) === 'company')) {
        $contents['tabs']['main']['sections'][0]['fields'][] = ['handle' => 'company', 'field' => ['type' => 'text', 'display' => 'Company']];
        $blueprint->setContents($contents)->save();
    }
}

test('subscribe forms capture blueprint-defined custom fields and ignore the rest', function () {
    addCompanyToSubscriberBlueprint();
    MailingList::create(['title' => 'Signup', 'handle' => 'signup']);

    $this->post('/postmaster/subscribe', [
        'email' => 'custom@example.com',
        'first_name' => 'Custom',
        'company' => 'Acme Co',
        'not_a_field' => 'ignore me',
        'lists' => 'signup',
    ])->assertSessionHasNoErrors();

    $subscriber = app(SubscriberRepository::class)->findByEmail('custom@example.com');

    expect($subscriber->fields['company'] ?? null)->toBe('Acme Co');
    expect($subscriber->fields)->not->toHaveKey('not_a_field');
    expect(MergeTags::resolve($subscriber, 'company'))->toBe('Acme Co');
});

test('csv imports capture custom field columns', function () {
    addCompanyToSubscriberBlueprint();
    MailingList::create(['title' => 'Import', 'handle' => 'import-list']);

    $csv = tempnam(sys_get_temp_dir(), 'es-import').'.csv';
    file_put_contents($csv, "email,first_name,company,unknown_column\nimported@example.com,Immy,Import Co,nope\n");

    $this->artisan('postmaster:import', ['file' => $csv, '--list' => 'import-list', '--subscribed' => true])
        ->assertSuccessful();

    $subscriber = app(SubscriberRepository::class)->findByEmail('imported@example.com');

    expect($subscriber->fields['company'] ?? null)->toBe('Import Co');
    expect($subscriber->fields)->not->toHaveKey('unknown_column');

    unlink($csv);
});

test('the subscriber page shows custom field values', function () {
    config()->set('postmaster.custom_fields', ['company']);
    $this->actingAs(tap(User::make()->makeSuper()->email('viewer@example.com'))->save());

    $subscriber = Subscriber::create([
        'email' => 'shown@example.com',
        'status' => Subscriber::STATUS_SUBSCRIBED,
        'fields' => ['company' => 'Visible Co'],
    ]);

    $this->get(cp_route('postmaster.subscribers.show', $subscriber->id))
        ->assertOk()
        ->assertSee('Company')
        ->assertSee('Visible Co');
});

test('configured custom_fields supplement the blueprint for the whitelist', function () {
    config()->set('postmaster.custom_fields', ['city']);

    expect(MergeTags::customFieldHandles())->toContain('city');
    expect(MergeTags::available())->toContain('city');
});

test('captured custom fields persist as top-level entry data under the content driver', function () {
    $this->useDriver('content');
    addCompanyToSubscriberBlueprint();
    app(ListRepository::class)->create([
        'title' => 'Content Signup', 'handle' => 'content-signup', 'double_opt_in' => false,
    ]);

    $this->post('/postmaster/subscribe', [
        'email' => 'toplevel@example.com',
        'company' => 'Top Level Co',
        'lists' => 'content-signup',
    ])->assertSessionHasNoErrors();

    $repository = app(SubscriberRepository::class);
    $entry = Entry::find($repository->keyForEmail('toplevel@example.com'));

    expect($entry->get('company'))->toBe('Top Level Co');
    expect($entry->get('fields'))->toBeNull();
    expect(MergeTags::resolve($repository->findByEmail('toplevel@example.com'), 'company'))->toBe('Top Level Co');
});
