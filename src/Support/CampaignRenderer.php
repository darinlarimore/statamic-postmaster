<?php

namespace Darinlarimore\Postmaster\Support;

use Darinlarimore\Postmaster\Contracts\SubscriberContract;
use Darinlarimore\Postmaster\Models\Link;
use Darinlarimore\Postmaster\Models\Sendout;

/**
 * Renders a sendout's email HTML.
 *
 * The template (Antlers or Blade, usually MJML) renders ONCE per sendout
 * with sentinel tokens standing in for per-subscriber values, and the MJML
 * binary runs once on that output. At send time personalize() swaps the
 * sentinels for each recipient's real values - so a 10,000 recipient
 * sendout compiles MJML a single time instead of 10,000 times.
 */
class CampaignRenderer
{
    const TOKEN_EMAIL = '%%es:email%%';

    const TOKEN_FIRST_NAME = '%%es:first_name%%';

    const TOKEN_LAST_NAME = '%%es:last_name%%';

    const TOKEN_FULL_NAME = '%%es:full_name%%';

    const TOKEN_UNSUBSCRIBE_URL = '%%es:unsubscribe_url%%';

    const TOKEN_PREFERENCES_URL = '%%es:preferences_url%%';

    const TOKEN_OPEN_PIXEL = '%%es:open_pixel%%';

    const BLOCKS_FIELD = 'email_blocks';

    /**
     * Render the sendout template to a compiled, trackable HTML snapshot.
     * Pass $trackable false for ephemeral renders (live preview) - unsaved
     * sendouts have no id for click links to attach to.
     */
    public function render(Sendout $sendout, bool $trackable = true): string
    {
        $sendout->refreshFromEntry();

        $entry = $sendout->entry();

        $html = view($sendout->template ?: 'postmaster::emails.default', [
            'sendout' => $sendout,
            'entry' => $entry,
            'subject' => $sendout->subject,
            'preheader' => $sendout->preheader,
            'email' => self::TOKEN_EMAIL,
            'first_name' => self::TOKEN_FIRST_NAME,
            'last_name' => self::TOKEN_LAST_NAME,
            'full_name' => self::TOKEN_FULL_NAME,
            'unsubscribe_url' => self::TOKEN_UNSUBSCRIBE_URL,
            'preferences_url' => self::TOKEN_PREFERENCES_URL,
        ])->render();

        $html = MergeTags::convert($html);

        $html = Mjml::compile($html);

        if ($trackable && config('postmaster.tracking.clicks', true)) {
            $html = $this->rewriteLinks($html, $sendout);
        }

        if ($trackable && config('postmaster.tracking.opens', true)) {
            $html = str_replace('</body>', self::TOKEN_OPEN_PIXEL.'</body>', $html);

            if (! str_contains($html, self::TOKEN_OPEN_PIXEL)) {
                $html .= self::TOKEN_OPEN_PIXEL;
            }
        }

        return $html;
    }

    /**
     * Swap sentinel tokens for sample values so rendered HTML can be shown to
     * a human instead of sent - CP previews and entry live previews both use this.
     */
    public function withSampleData(string $html): string
    {
        $html = strtr($html, [
            self::TOKEN_EMAIL => 'subscriber@example.com',
            self::TOKEN_FIRST_NAME => 'First',
            self::TOKEN_LAST_NAME => 'Last',
            self::TOKEN_FULL_NAME => 'First Last',
            self::TOKEN_UNSUBSCRIBE_URL => '#',
            self::TOKEN_PREFERENCES_URL => '#',
            self::TOKEN_OPEN_PIXEL => '',
        ]);

        $html = MergeTags::sampleFieldTokens($html);

        return preg_replace('/%%es:click:\d+%%/', '#', $html);
    }

    /**
     * Swap sentinel tokens for a real recipient's values.
     */
    public function personalize(string $html, Sendout $sendout, SubscriberContract $subscriber, bool $tracking = true): string
    {
        $pixel = $tracking && config('postmaster.tracking.opens', true)
            ? '<img src="'.e(Urls::open($sendout, $subscriber)).'" width="1" height="1" alt="" style="display:none;" />'
            : '';

        // Unsaved subscribers (test sends to arbitrary addresses) have no id
        // to sign URLs against.
        $exists = $subscriber->exists;

        $html = strtr($html, [
            self::TOKEN_EMAIL => e($subscriber->email),
            self::TOKEN_FIRST_NAME => e($subscriber->first_name ?? ''),
            self::TOKEN_LAST_NAME => e($subscriber->last_name ?? ''),
            self::TOKEN_FULL_NAME => e($subscriber->fullName() ?? ''),
            self::TOKEN_UNSUBSCRIBE_URL => $exists ? e(Urls::unsubscribe($subscriber, $sendout)) : '#',
            self::TOKEN_PREFERENCES_URL => $exists ? e(Urls::preferences($subscriber)) : '#',
            self::TOKEN_OPEN_PIXEL => $pixel,
        ]);

        $html = MergeTags::resolveFieldTokens($html, $subscriber);

        // Click placeholders carry the link id: %%es:click:123%%
        return preg_replace_callback('/%%es:click:(\d+)%%/', function ($matches) use ($subscriber, $tracking) {
            $link = Link::find($matches[1]);

            if (! $link) {
                return '';
            }

            return $tracking ? e(Urls::click($link->id, $subscriber)) : e($link->url);
        }, $html);
    }

    /**
     * Point every external link at the click-tracking redirect. Unsubscribe
     * and preference links (and their sentinels) are left alone.
     */
    protected function rewriteLinks(string $html, Sendout $sendout): string
    {
        return preg_replace_callback(
            '/(<a\s[^>]*href=")(https?:\/\/[^"]+)(")/i',
            function ($matches) use ($sendout) {
                $url = $matches[2];

                if (str_contains($url, '%%es:')) {
                    return $matches[0];
                }

                $link = Link::firstOrCreate([
                    'sendout_id' => $sendout->id,
                    'url' => $url,
                ]);

                return $matches[1].'%%es:click:'.$link->id.'%%'.$matches[3];
            },
            $html,
        );
    }
}
