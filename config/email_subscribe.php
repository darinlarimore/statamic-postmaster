<?php

return [

    /*
    |--------------------------------------------------------------------------
    | From Address
    |--------------------------------------------------------------------------
    |
    | The default from name and email used for campaign sendouts and
    | confirmation emails. Falls back to your app's mail.from config.
    |
    */

    'from' => [
        'email' => env('EMAIL_SUBSCRIBE_FROM_EMAIL', config('mail.from.address')),
        'name' => env('EMAIL_SUBSCRIBE_FROM_NAME', config('mail.from.name')),
    ],

    'reply_to' => env('EMAIL_SUBSCRIBE_REPLY_TO'),

    /*
    |--------------------------------------------------------------------------
    | Mailer
    |--------------------------------------------------------------------------
    |
    | Which mailer from config/mail.php to send through. Null uses the
    | default mailer.
    |
    */

    'mailer' => env('EMAIL_SUBSCRIBE_MAILER'),

    /*
    |--------------------------------------------------------------------------
    | Sendouts
    |--------------------------------------------------------------------------
    |
    | Sendouts are processed on the queue in batches. Tune the batch size
    | and the delay between batches to respect your mail provider's rate
    | limits. Test emails always send immediately and skip the queue.
    |
    */

    'sendout' => [
        'queue' => env('EMAIL_SUBSCRIBE_QUEUE', 'default'),
        'connection' => env('EMAIL_SUBSCRIBE_QUEUE_CONNECTION'),
        'batch_size' => 100,
        'batch_delay' => 0, // seconds to wait between batches
        'max_attempts' => 3, // send attempts per recipient before marking failed
        'max_failures' => 10, // failed recipients before the whole sendout aborts
    ],

    /*
    |--------------------------------------------------------------------------
    | Double Opt-in
    |--------------------------------------------------------------------------
    |
    | When enabled, new subscribers receive a confirmation email and stay
    | "pending" until they click the verification link. Links are signed
    | and expire after the configured number of days.
    |
    */

    'double_opt_in' => true,

    'verification_expiry' => 7, // days

    /*
    |--------------------------------------------------------------------------
    | Tracking
    |--------------------------------------------------------------------------
    |
    | Track opens (via a 1x1 pixel) and clicks (via redirect URLs) for
    | campaign sendouts.
    |
    */

    'tracking' => [
        'opens' => true,
        'clicks' => true,
    ],

    /*
    |--------------------------------------------------------------------------
    | MJML
    |--------------------------------------------------------------------------
    |
    | Campaign emails written in MJML compile to email-safe HTML. The
    | "binary" strategy shells out to the local mjml node package. The
    | "api" strategy uses the MJML API (https://mjml.io/api). "none"
    | disables compilation - templates are treated as plain HTML.
    |
    */

    'mjml' => [
        'strategy' => env('EMAIL_SUBSCRIBE_MJML', 'binary'), // binary, api, none
        'node_path' => env('MJML_NODE_PATH'),
        'api_id' => env('MJML_API_ID'),
        'api_secret' => env('MJML_API_SECRET'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks
    |--------------------------------------------------------------------------
    |
    | Inbound webhooks from your mail provider mark subscribers as bounced
    | or complained so they stop receiving sendouts. The signing key
    | protects the webhook URLs from forgery - set it to any random string
    | and include it in the webhook URL you give your provider.
    |
    */

    'webhooks' => [
        'signing_key' => env('EMAIL_SUBSCRIBE_WEBHOOK_KEY'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirects
    |--------------------------------------------------------------------------
    |
    | Where to send visitors after verifying or unsubscribing. Null renders
    | the addon's built-in pages (publish the views to customize them).
    |
    */

    'redirects' => [
        'verify' => null,
        'unsubscribe' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | URI prefix for the addon's public routes (subscribe, verify,
    | unsubscribe, tracking, webhooks).
    |
    */

    'route_prefix' => 'email-subscribe',

    /*
    |--------------------------------------------------------------------------
    | Storage
    |--------------------------------------------------------------------------
    |
    | Where mailing lists and subscribers live. The "content" driver stores
    | them as flat-file Statamic entries (git-trackable, no database server);
    | the "database" driver keeps them in your database via Eloquent. Sendouts,
    | tracking events, and links always live in the default database driver.
    |
    | The content driver resolves recipients with an O(n) scan of the
    | subscribers collection, which is fine for small-to-medium lists. Large or
    | high-signup-rate installs should use the "database" driver.
    |
    */

    'storage' => [
        'driver' => env('EMAIL_SUBSCRIBE_STORAGE', 'content'), // content, database

        'collections' => [
            'subscribers' => 'email_subscribers',
            'lists' => 'email_lists',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Diagnostics Logging
    |--------------------------------------------------------------------------
    |
    | The addon logs recipient-resolution anomalies, concurrency signals, and
    | integrity drift to a dedicated "email_subscribe" log channel so they are
    | easy to watch (and are picked up by warning/error-level monitoring). Use
    | these signals to decide when to move from the content driver to the
    | database driver. Define your own "email_subscribe" channel in
    | config/logging.php to override where these lines land.
    |
    */

    'logging' => [
        'channel' => 'email_subscribe',
        'path' => storage_path('logs/email_subscribe.log'),
        'level' => 'warning',
    ],

    /*
    |--------------------------------------------------------------------------
    | Custom Subscriber Fields
    |--------------------------------------------------------------------------
    |
    | Under the content driver the subscriber blueprint is the schema: any
    | field you add there is captured from subscribe forms and CSV imports,
    | shown on the subscriber page, and available as a merge tag. This list
    | supplements the blueprint for database-driver installs (which have no
    | blueprint), e.g. ['company', 'city'].
    |
    */

    'custom_fields' => [],

];
