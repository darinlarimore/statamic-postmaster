<?php

namespace Darinlarimore\StatamicEmailSubscribe\Support;

use Darinlarimore\StatamicEmailSubscribe\Contracts\SubscriberContract;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Statamic\Facades\Blueprint;

/**
 * Editor-facing personalization tags: authors type {{ first_name }} (or any
 * custom subscriber field) inside block content or the subject line, and the
 * value is swapped per recipient at send time.
 *
 * Content renders and compiles ONCE per sendout, so tags convert to the same
 * %%es:*%% sentinels the templates use - core tags map onto the existing
 * tokens, anything else becomes %%es:field:name%% and resolves from the
 * subscriber's custom fields.
 */
class MergeTags
{
    /**
     * @var array<string, string> tag name => sentinel token
     */
    const CORE = [
        'email' => CampaignRenderer::TOKEN_EMAIL,
        'first_name' => CampaignRenderer::TOKEN_FIRST_NAME,
        'last_name' => CampaignRenderer::TOKEN_LAST_NAME,
        'full_name' => CampaignRenderer::TOKEN_FULL_NAME,
        'unsubscribe_url' => CampaignRenderer::TOKEN_UNSUBSCRIBE_URL,
        'preferences_url' => CampaignRenderer::TOKEN_PREFERENCES_URL,
    ];

    /**
     * Subscriber-blueprint handles that are bookkeeping, not personalization.
     *
     * @var array<int, string>
     */
    const INTERNAL = [
        'title', 'slug', 'date', 'blueprint', 'updated_by', 'updated_at',
        'sub_status', 'lists', 'fields', 'active_lists', 'activity_history',
        'verified_at', 'unsubscribed_at', 'bounced_at', 'complained_at',
        'source', 'subscribe_ip', 'legacy_id',
    ];

    /**
     * Swap editor-typed {{ tag }} placeholders for sentinel tokens.
     */
    public static function convert(string $html): string
    {
        return preg_replace_callback(
            '/\{\{\s*([a-zA-Z][a-zA-Z0-9_]*)\s*\}\}/',
            fn (array $matches) => self::CORE[$matches[1]] ?? '%%es:field:'.$matches[1].'%%',
            $html,
        );
    }

    /**
     * A subscriber's value for a custom field tag, or an empty string.
     */
    public static function resolve(SubscriberContract $subscriber, string $field): string
    {
        $fields = $subscriber->fields ?? [];

        return (string) ($fields[$field] ?? '');
    }

    /**
     * Swap %%es:field:*%% sentinels for the given subscriber's values.
     */
    public static function resolveFieldTokens(string $html, SubscriberContract $subscriber): string
    {
        return preg_replace_callback(
            '/%%es:field:([a-zA-Z0-9_]+)%%/',
            fn (array $matches) => e(self::resolve($subscriber, $matches[1])),
            $html,
        );
    }

    /**
     * Swap %%es:field:*%% sentinels for readable sample values (previews).
     */
    public static function sampleFieldTokens(string $html): string
    {
        return preg_replace_callback(
            '/%%es:field:([a-zA-Z0-9_]+)%%/',
            fn (array $matches) => e(Str::headline($matches[1])),
            $html,
        );
    }

    /**
     * Personalize a plain-text string (e.g. a subject line) for a subscriber.
     * URL tags don't belong in text, so they resolve to empty strings.
     */
    public static function personalizeText(string $text, SubscriberContract $subscriber): string
    {
        $text = strtr(self::convert($text), [
            CampaignRenderer::TOKEN_EMAIL => $subscriber->email,
            CampaignRenderer::TOKEN_FIRST_NAME => $subscriber->first_name ?? '',
            CampaignRenderer::TOKEN_LAST_NAME => $subscriber->last_name ?? '',
            CampaignRenderer::TOKEN_FULL_NAME => $subscriber->fullName() ?? '',
            CampaignRenderer::TOKEN_UNSUBSCRIBE_URL => '',
            CampaignRenderer::TOKEN_PREFERENCES_URL => '',
        ]);

        return preg_replace_callback(
            '/%%es:field:([a-zA-Z0-9_]+)%%/',
            fn (array $matches) => self::resolve($subscriber, $matches[1]),
            $text,
        );
    }

    /**
     * Custom personalization field handles: the subscriber blueprint is the
     * schema - add a field there and it becomes capturable (forms, imports)
     * and taggable. The email_subscribe.custom_fields config supplements it
     * for database-driver sites, which have no blueprint to read.
     *
     * @return Collection<int, string>
     */
    public static function customFieldHandles(): Collection
    {
        $core = collect(array_keys(self::CORE));

        $handle = config('email_subscribe.storage.collections.subscribers', 'email_subscribers');
        $blueprint = Blueprint::find('collections.'.$handle.'.'.$handle);

        return ($blueprint ? $blueprint->fields()->all()->keys() : collect())
            ->merge(config('email_subscribe.custom_fields', []))
            ->reject(fn (string $field) => in_array($field, self::INTERNAL) || $core->contains($field))
            ->unique()
            ->values();
    }

    /**
     * Tag names available to authors: the core set plus the custom fields.
     *
     * @return Collection<int, string>
     */
    public static function available(): Collection
    {
        return collect(array_keys(self::CORE))->merge(self::customFieldHandles())->values();
    }
}
