<?php

namespace Darinlarimore\Postmaster\Http\Controllers\Web;

use Darinlarimore\Postmaster\Models\Sendout;
use Darinlarimore\Postmaster\Support\CampaignRenderer;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Statamic\Facades\Entry;
use Statamic\Facades\User;

/**
 * Renders an entry through the email pipeline for Statamic's Live Preview.
 *
 * Registered as a collection preview target, so the CP loads it in the Live
 * Preview iframe. The statamic.web middleware group runs HandleToken, which
 * substitutes the editor's unsaved working copy into the entry repository -
 * Entry::find() here returns live edits without any extra wiring.
 */
class EntryPreviewController extends Controller
{
    public function __invoke(string $entry, CampaignRenderer $renderer): Response
    {
        $user = User::current();

        abort_unless($user && $user->can('view email subscribe'), 403);

        $found = Entry::find($entry);

        abort_unless((bool) $found, 404);

        $sendout = new Sendout([
            'subject' => (string) ($found->get('title') ?: 'Preview'),
            'entry_id' => $entry,
        ]);

        $html = $renderer->render($sendout, trackable: false);

        return response($renderer->withSampleData($html));
    }
}
