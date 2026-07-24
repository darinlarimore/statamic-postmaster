<?php

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Darinlarimore\Postmaster\Models\MailingList;
use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Illuminate\Support\Facades\File;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

beforeEach(function () {
    $this->useDriver('database');
});

test('the scaffold command creates a working newsletter setup', function () {
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    expect(Collection::handleExists('newsletters'))->toBeTrue();

    $blueprint = Blueprint::find('collections.newsletters.newsletters');
    expect($blueprint)->not->toBeNull();
    expect(json_encode($blueprint->contents()))->toContain('postmaster::email_blocks');

    $entry = Entry::query()->where('collection', 'newsletters')->where('slug', 'example-newsletter')->first();
    expect($entry)->not->toBeNull();
    expect($entry->get('email_blocks'))->not->toBeEmpty();

    expect(app(ListRepository::class)->findByHandle('newsletter'))->not->toBeNull();

    expect(File::exists(resource_path('views/vendor/postmaster/emails/blocks/heading.antlers.html')))->toBeTrue();
});

test('the scaffolded example entry renders through the blocks template', function () {
    config()->set('filesystems.disks.assets', [
        'driver' => 'local',
        'root' => storage_path('framework/testing/assets'),
        'url' => 'http://localhost/assets',
    ]);

    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    $entry = Entry::query()->where('collection', 'newsletters')->where('slug', 'example-newsletter')->first();
    $list = MailingList::create(['title' => 'Render List', 'handle' => 'render-list']);

    $sendout = Sendout::create([
        'title' => 'Scaffold Issue',
        'subject' => 'Scaffold Subject',
        'entry_id' => $entry->id(),
        'list_ids' => [$list->id],
        'status' => Sendout::STATUS_DRAFT,
    ]);

    $html = app(CampaignRenderer::class)->render($sendout);

    expect($html)->toContain('Welcome to your newsletter');
    expect($html)->toContain('<mj-button');
    expect($html)->not->toContain('This block is disabled and never renders');
    expect($html)->not->toContain('<mj-image');
});

test('running the scaffold command twice is idempotent', function () {
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    expect(Entry::query()->where('collection', 'newsletters')->where('slug', 'example-newsletter')->count())->toBe(1);
    expect(app(ListRepository::class)->all())->toHaveCount(1);
});

test('the scaffold command wires Live Preview to the email renderer', function () {
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    $collection = Collection::findByHandle('newsletters');
    $targets = $collection->basePreviewTargets();

    expect($targets)->toHaveCount(1);
    expect($targets->first()['format'])->toBe('/postmaster/entry-preview/{id}');
    expect($targets->first()['refresh'])->toBeTrue();

    // The CP only shows the Live Preview button for routable collections, so
    // the preview URL doubles as the collection route.
    expect($collection->route(Site::default()->handle()))->toBe('/postmaster/entry-preview/{id}');

    $entry = Entry::query()->where('collection', 'newsletters')->where('slug', 'example-newsletter')->first();
    expect($entry->livePreviewUrl())->not->toBeNull();
});

test('the scaffold command leaves an existing collection route alone', function () {
    Collection::make('newsletters')->title('Newsletters')->routes('/news/{slug}')->save();

    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    expect(Collection::findByHandle('newsletters')->route(Site::default()->handle()))->toBe('/news/{slug}');
});

test('the scaffold command keeps newsletter entries out of SEO tabs and sitemaps', function () {
    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    expect(Collection::findByHandle('newsletters')->cascade('seo'))->toBeFalse();
});

test('the scaffold command respects an existing seo cascade setting', function () {
    Collection::make('newsletters')->title('Newsletters')->cascade(['seo' => ['sitemap' => false]])->save();

    $this->artisan('postmaster:scaffold-newsletter')->assertSuccessful();

    expect(Collection::findByHandle('newsletters')->cascade('seo'))->toBe(['sitemap' => false]);
});
