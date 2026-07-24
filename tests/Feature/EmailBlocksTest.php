<?php

use Darinlarimore\Postmaster\Models\Link;
use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Illuminate\Support\Facades\Storage;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Fieldset;
use Statamic\Facades\User;

/**
 * Creates a newsletters collection whose blueprint IMPORTS the addon fieldset,
 * proving the namespaced import resolves end-to-end, then an entry with the
 * given raw replicator rows.
 */
function makeBlocksEntry(array $blocks, string $slug = 'welcome'): mixed
{
    if (! Collection::handleExists('newsletters')) {
        Collection::make('newsletters')->title('Newsletters')->save();

        Blueprint::make('newsletters')
            ->setNamespace('collections.newsletters')
            ->setContents([
                'title' => 'Newsletter',
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text']],
                                    ['import' => 'postmaster::email_blocks'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();
    }

    $entry = Entry::make()
        ->collection('newsletters')
        ->slug($slug)
        ->data(['title' => 'Welcome Issue', 'email_blocks' => $blocks]);

    $entry->save();

    return $entry;
}

function makeBlocksSendout(mixed $entry, ?string $template = null): Sendout
{
    $list = MailingList::create(['title' => 'Newsletter', 'handle' => 'blocks-list-'.uniqid()]);

    return Sendout::create([
        'title' => 'Blocks Issue',
        'subject' => 'Blocks Subject',
        'entry_id' => $entry?->id(),
        'template' => $template,
        'list_ids' => [$list->id],
        'status' => Sendout::STATUS_DRAFT,
    ]);
}

beforeEach(function () {
    $this->useDriver('database');
});

test('the email_blocks fieldset is registered under the addon namespace', function () {
    $fieldset = Fieldset::find('postmaster::email_blocks');

    expect($fieldset)->not->toBeNull();

    $fields = collect($fieldset->contents()['fields'])->keyBy('handle');

    expect($fields->keys()->all())->toBe(['email_subject', 'email_preheader', 'email_blocks', 'email_template']);

    $sets = $fields['email_blocks']['field']['sets']['content']['sets'];

    expect(array_keys($sets))->toBe(['heading', 'text', 'image', 'button', 'divider', 'spacer']);
});

test('each block type renders to MJML and disabled blocks are skipped', function () {
    $entry = makeBlocksEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Big News', 'size' => 'h1'],
        ['type' => 'text', 'enabled' => true, 'text' => [
            ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Hello from the blocks builder.']]],
        ]],
        ['type' => 'button', 'enabled' => true, 'label' => 'Read more', 'url' => 'https://example.com/post'],
        ['type' => 'divider', 'enabled' => true],
        ['type' => 'spacer', 'enabled' => true, 'height' => 32],
        ['type' => 'heading', 'enabled' => false, 'text' => 'Hidden Heading', 'size' => 'h2'],
    ]);

    $html = app(CampaignRenderer::class)->render(makeBlocksSendout($entry));

    expect($html)->toContain('Big News');
    expect($html)->toContain('font-size="28px"');
    expect($html)->toContain('Hello from the blocks builder.');
    expect($html)->toContain('<mj-button href="https://example.com/post"');
    expect($html)->toContain('<mj-divider');
    expect($html)->toContain('<mj-spacer height="32px"');
    expect($html)->not->toContain('Hidden Heading');
});

test('image blocks render an absolute URL and empty ones render nothing', function () {
    config()->set('filesystems.disks.test_assets', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/test-assets'),
        'url' => 'http://localhost/test-assets',
    ]);
    Storage::disk('test_assets')->put('img.jpg', 'fake-image-bytes');
    AssetContainer::make('test_assets')->disk('test_assets')->title('Assets')->save();

    $entry = makeBlocksEntry([
        ['type' => 'image', 'enabled' => true, 'image' => 'img.jpg', 'alt' => 'A drone shot'],
        ['type' => 'image', 'enabled' => true, 'image' => null, 'alt' => 'Ghost'],
        ['type' => 'heading', 'enabled' => true, 'text' => 'Still here', 'size' => 'h2'],
    ], 'with-images');

    $html = app(CampaignRenderer::class)->render(makeBlocksSendout($entry));

    expect($html)->toContain('<mj-image src="http://localhost/test-assets/img.jpg" alt="A drone shot"');
    expect(substr_count($html, '<mj-image'))->toBe(1);
    expect($html)->toContain('Still here');
});

test('sentinel tokens survive the blocks template', function () {
    $entry = makeBlocksEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Sentinels', 'size' => 'h2'],
    ], 'sentinels');

    $html = app(CampaignRenderer::class)->render(makeBlocksSendout($entry));

    expect($html)->toContain('%%es:unsubscribe_url%%');
    expect($html)->toContain('%%es:preferences_url%%');
    expect($html)->toContain('%%es:open_pixel%%');
});

test('the default template renders blocks or classic content, and overrides win', function () {
    $blocksEntry = makeBlocksEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Auto Selected', 'size' => 'h2'],
    ], 'auto-selected');

    $plainEntry = Entry::make()->collection('newsletters')->slug('plain')->data(['title' => 'Plain']);
    $plainEntry->save();

    $renderer = app(CampaignRenderer::class);

    expect($renderer->render(makeBlocksSendout($blocksEntry)))->toContain('Auto Selected');

    // No blocks on the entry: the same default template falls back to the
    // classic subject headline, which drafts refresh from the entry title.
    expect($renderer->render(makeBlocksSendout($plainEntry)))->toContain('Plain');

    // An entry-level template override beats auto-detection: the plain-HTML
    // Blade fixture renders its <h1>, which neither MJML template produces.
    $overrideEntry = Entry::make()->collection('newsletters')->slug('override')->data([
        'title' => 'Override Issue',
        'email_template' => 'test-campaign',
        'email_blocks' => [['type' => 'heading', 'enabled' => true, 'text' => 'Ignored', 'size' => 'h2']],
    ]);
    $overrideEntry->save();

    expect($renderer->render(makeBlocksSendout($overrideEntry)))->toContain('<h1>Override Issue</h1>');
});

test('templates receive an absolute site_url and the current year', function () {
    config()->set('app.url', 'https://mysite.test');
    config()->set('app.name', 'My Site');

    $entry = makeBlocksEntry([], 'urls');
    $entry->set('email_template', 'test-campaign')->save();

    $html = app(CampaignRenderer::class)->render(makeBlocksSendout($entry));

    // Absolute URL for images (Statamic's site:url can be relative).
    expect($html)->toContain('src="https://mysite.test/logo.png"');
    expect($html)->toContain('&copy; My Site '.date('Y'));
});

test('the entry live-preview route renders the email with sample data', function () {
    $this->actingAs(tap(User::make()->makeSuper()->email('previewer@example.com'))->save());

    $entry = makeBlocksEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Live Preview Heading', 'size' => 'h2'],
        ['type' => 'button', 'enabled' => true, 'label' => 'Click', 'url' => 'https://example.com/tracked'],
    ], 'live-preview');

    $response = $this->get('/postmaster/entry-preview/'.$entry->id())->assertOk();

    expect($response->getContent())->toContain('Live Preview Heading');
    expect($response->getContent())->toContain('subscriber@example.com');
    expect($response->getContent())->not->toContain('%%es:');

    // Ephemeral renders must not mint click-tracking links.
    expect(Link::count())->toBe(0);

    $this->get('/postmaster/entry-preview/not-a-real-entry')->assertNotFound();
});

test('the entry live-preview route rejects visitors without CP access', function () {
    $entry = makeBlocksEntry([
        ['type' => 'heading', 'enabled' => true, 'text' => 'Secret Draft', 'size' => 'h2'],
    ], 'guarded-preview');

    $this->get('/postmaster/entry-preview/'.$entry->id())->assertForbidden();
});

test('sender fields are injected with the site defaults as placeholders', function () {
    config()->set('postmaster.from.email', 'news@daring.test');
    config()->set('postmaster.from.name', 'Daring News');

    $entry = makeBlocksEntry([], 'placeholder-check');
    $blueprint = $entry->blueprint();

    expect($blueprint->hasField('email_from_email'))->toBeTrue();
    expect($blueprint->field('email_from_email')->config()['placeholder'] ?? null)->toBe('news@daring.test');
    expect($blueprint->field('email_from_name')->config()['placeholder'] ?? null)->toBe('Daring News');
    expect($blueprint->field('email_reply_to')->config()['placeholder'] ?? null)->toBe('news@daring.test');
});
