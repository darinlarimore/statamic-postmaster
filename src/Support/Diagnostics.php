<?php

namespace Darinlarimore\StatamicEmailSubscribe\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Diagnostic logging for the content storage driver. The content driver trades
 * a database's transactional guarantees for flat files, so a handful of
 * conditions (a recipient that couldn't be resolved, a lock timeout, integrity
 * drift after a sendout) are worth surfacing. They land on a dedicated
 * "email_subscribe" log channel at warning/error level so log-based monitoring
 * picks them up — watch these to decide when to move to the database driver.
 *
 * A host app can override where these lines go by declaring an "email_subscribe"
 * channel in its own config/logging.php; boot() will not clobber it.
 */
class Diagnostics
{
    public static function boot(): void
    {
        if (config()->has('logging.channels.email_subscribe')) {
            return;
        }

        config()->set('logging.channels.email_subscribe', [
            'driver' => 'single',
            'path' => config('email_subscribe.logging.path', storage_path('logs/email_subscribe.log')),
            'level' => config('email_subscribe.logging.level', 'warning'),
        ]);
    }

    public static function channel(): LoggerInterface
    {
        return Log::channel(config('email_subscribe.logging.channel', 'email_subscribe'));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function recipientAnomaly(string $reason, array $context = []): void
    {
        static::channel()->warning("email-subscribe: recipient anomaly [{$reason}]", $context);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function droppedRecipient(string $subscriberKey, int $sendoutId, array $context = []): void
    {
        static::channel()->warning('email-subscribe: dropped recipient', array_merge([
            'subscriber_key' => $subscriberKey,
            'sendout_id' => $sendoutId,
        ], $context));
    }

    public static function countMismatch(int $sendoutId, int $recipientCount, int $sent, int $failed, int $skipped): void
    {
        static::channel()->warning('email-subscribe: sendout count mismatch', [
            'sendout_id' => $sendoutId,
            'recipient_count' => $recipientCount,
            'sent' => $sent,
            'failed' => $failed,
            'skipped' => $skipped,
            'unaccounted' => $recipientCount - ($sent + $failed + $skipped),
        ]);
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function lockTimeout(string $key, array $context = []): void
    {
        static::channel()->warning('email-subscribe: subscriber lock timeout', array_merge([
            'subscriber_key' => $key,
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function staleMerge(string $key, array $context = []): void
    {
        static::channel()->warning('email-subscribe: subscriber changed during merge', array_merge([
            'subscriber_key' => $key,
        ], $context));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public static function unsafeConfig(array $context = []): void
    {
        static::channel()->error('email-subscribe: unsafe content-driver configuration', $context);
    }

    /**
     * @param  array<string, mixed>  $drift
     */
    public static function reconcileDrift(int $sendoutId, array $drift): void
    {
        static::channel()->warning('email-subscribe: sendout integrity drift', array_merge([
            'sendout_id' => $sendoutId,
        ], $drift));
    }
}
