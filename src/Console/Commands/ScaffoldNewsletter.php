<?php

namespace Darinlarimore\Postmaster\Console\Commands;

use Darinlarimore\Postmaster\Contracts\ListRepository;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Statamic\Facades\AssetContainer;
use Statamic\Facades\Blueprint;
use Statamic\Facades\Collection;
use Statamic\Facades\Entry;
use Statamic\Facades\Site;

class ScaffoldNewsletter extends Command
{
    protected $signature = 'postmaster:scaffold-newsletter
        {--collection=newsletters : Handle of the collection to create}';

    protected $description = 'Create a newsletter collection wired for Email Blocks, with an example entry ready to send';

    public function handle(): int
    {
        $handle = $this->option('collection');

        $this->ensureCollection($handle);
        $this->ensureBlueprint($handle);
        $this->ensurePreviewTarget($handle);
        $this->ensureMailingList();
        $this->ensureExampleEntry($handle);

        // Same non-forced publish as the install hook, so the email templates
        // are in resources/views/vendor/postmaster/emails for customizing
        // even when scaffolding ran before any composer-triggered install.
        $this->call('vendor:publish', ['--tag' => 'postmaster-views']);

        $this->newLine();
        $this->info('Ready to test:');
        $this->line('  1. Open the "Example Newsletter" entry - Live Preview shows the compiled email as you edit blocks.');
        $this->line('  2. Send yourself a copy via the entry\'s "Send Test Email" action.');
        $this->line('  3. Pick lists in the sidebar and toggle Published - its sendout flips to "ready to send".');
        $this->line('  4. Open Postmaster → Sendouts to send now or schedule.');
        $this->line('  Templates live in resources/views/vendor/postmaster/emails - edit them freely.');

        return self::SUCCESS;
    }

    protected function ensureCollection(string $handle): void
    {
        if (Collection::handleExists($handle)) {
            $this->line("Collection [{$handle}] already exists.");

            return;
        }

        Collection::make($handle)
            ->title(Str::of($handle)->replace(['-', '_'], ' ')->title()->toString())
            ->revisionsEnabled(false)
            ->save();

        $this->info("Created the [{$handle}] collection.");
    }

    protected function ensureBlueprint(string $handle): void
    {
        if (Blueprint::find("collections.{$handle}.{$handle}")) {
            $this->line("Blueprint for [{$handle}] already exists - make sure it imports postmaster::email_blocks.");

            return;
        }

        Blueprint::make($handle)
            ->setNamespace('collections.'.$handle)
            ->setContents([
                'title' => Str::of($handle)->replace(['-', '_'], ' ')->title()->singular()->toString(),
                'tabs' => [
                    'main' => [
                        'sections' => [
                            [
                                'fields' => [
                                    ['handle' => 'title', 'field' => ['type' => 'text', 'display' => 'Title', 'validate' => ['required']]],
                                    ['import' => 'postmaster::email_blocks'],
                                ],
                            ],
                        ],
                    ],
                ],
            ])
            ->save();

        $this->info("Created the [{$handle}] blueprint with the Email Blocks fieldset.");
    }

    /**
     * Point Statamic's Live Preview at the addon's entry-preview route so the
     * CP shows the compiled email while editing, refreshing as blocks change.
     *
     * The CP only shows the Live Preview button for routable collections, so a
     * route-less collection gets the preview URL as its route too - the
     * addon's permission-gated controller answers it ahead of Statamic's
     * front-end catch-all, making entry permalinks render the email as well.
     */
    protected function ensurePreviewTarget(string $handle): void
    {
        $collection = Collection::findByHandle($handle);
        $format = '/'.config('postmaster.route_prefix', 'postmaster').'/entry-preview/{id}';
        $changed = false;

        if (! $collection->route(Site::default()->handle())) {
            $collection->routes($format);
            $changed = true;
        }

        if (! $collection->basePreviewTargets()->pluck('format')->contains($format)) {
            $collection->previewTargets([
                ['label' => 'Email', 'format' => $format, 'refresh' => true],
            ]);
            $changed = true;
        }

        // Emails aren't web pages: `seo: false` in the cascade keeps SEO Pro
        // from adding an SEO tab to entries or listing them in the sitemap.
        // Only set when absent so deliberate per-site settings survive.
        if (! $collection->cascade()->has('seo')) {
            $collection->cascade($collection->cascade()->put('seo', false)->all());
            $changed = true;
        }

        if ($changed) {
            $collection->save();
            $this->info('Wired Live Preview to the email renderer.');
        }
    }

    protected function ensureExampleEntry(string $handle): void
    {
        $slug = 'example-newsletter';

        $exists = Entry::query()
            ->where('collection', $handle)
            ->where('slug', $slug)
            ->first();

        if ($exists) {
            $this->line('The example entry already exists.');

            return;
        }

        $entry = Entry::make()
            ->collection($handle)
            ->slug($slug)
            ->data([
                'title' => 'Example Newsletter',
                'email_preheader' => 'Every block in action, ready to send.',
                'postmaster_lists' => app(ListRepository::class)->all()
                    ->map(fn ($list) => (string) $list->getKey())
                    ->take(1)
                    ->values()
                    ->all(),
                'email_blocks' => $this->exampleBlocks(),
            ]);

        $entry->save();

        $this->info('Created the "Example Newsletter" entry with one of each block.');
    }

    protected function ensureMailingList(): void
    {
        $lists = app(ListRepository::class);

        if ($lists->all()->isNotEmpty()) {
            return;
        }

        $lists->create([
            'title' => 'Newsletter',
            'handle' => 'newsletter',
            'double_opt_in' => true,
        ]);

        $this->info('Created a "Newsletter" mailing list (double opt-in).');
    }

    /**
     * One of each block type. The image block ships enabled but empty so the
     * asset picker is visible in the CP; it's omitted entirely on sites with
     * no asset containers, where the assets fieldtype can't even render.
     *
     * @return array<int, array<string, mixed>>
     */
    protected function exampleBlocks(): array
    {
        $imageBlocks = AssetContainer::all()->isEmpty()
            ? []
            : [['type' => 'image', 'enabled' => true, 'image' => null, 'alt' => '']];

        return [
            ['type' => 'heading', 'enabled' => true, 'text' => 'Welcome to your newsletter, {{ first_name }}', 'size' => 'h1'],
            ['type' => 'text', 'enabled' => true, 'text' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'This entry was scaffolded by '],
                    ['type' => 'text', 'marks' => [['type' => 'bold']], 'text' => 'Postmaster'],
                    ['type' => 'text', 'text' => ' so you can see every block in action. Edit it, reorder the blocks, then preview a sendout that uses it.'],
                ]],
            ]],
            ['type' => 'divider', 'enabled' => true],
            ...$imageBlocks,
            ['type' => 'text', 'enabled' => true, 'text' => [
                ['type' => 'paragraph', 'content' => [
                    ['type' => 'text', 'text' => 'Blocks can be disabled without deleting them - this entry has a hidden one below the button.'],
                ]],
            ]],
            ['type' => 'button', 'enabled' => true, 'label' => 'Visit your site', 'url' => config('app.url')],
            ['type' => 'heading', 'enabled' => false, 'text' => 'This block is disabled and never renders', 'size' => 'h3'],
            ['type' => 'spacer', 'enabled' => true, 'height' => 24],
        ];
    }
}
