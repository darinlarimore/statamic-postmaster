# Statamic Email Subscribe

> Newsletter subscriptions, MJML campaigns, and queue-powered sendouts — natively in your Statamic control panel.

Email Subscribe brings the full newsletter workflow to Statamic: collect subscribers with double opt-in, write campaigns as MJML templates fed by your entries, and deliver them through your own mail provider with batched, pausable, queue-backed sendouts. Opens, clicks, bounces, and complaints are tracked and reported per sendout.

Supports **Statamic 5 and 6**.

## Features

- 📬 **Mailing lists** — as many as you need, each with its own double opt-in setting
- ✅ **Double opt-in** — signed, expiring verification links; pending subscribers never get sendouts
- 📝 **Campaigns from entries** — point a sendout at any entry and render it through an MJML or HTML template (Antlers or Blade)
- 💌 **MJML templating** — write emails in [MJML](https://mjml.io) and get bulletproof, email-client-safe HTML; plain HTML templates work too
- 🚀 **Queued sendouts** — recipients are processed in configurable batches on your queue; pause, resume, or cancel mid-send with no double-sending, and schedule sendouts for later
- ⚡ **Test emails skip the queue** — send yourself a rendered test instantly, with no tracking
- 📈 **Tracking & reports** — unique opens (pixel), clicks (redirect), unsubscribes, bounces, complaints, and top links per sendout
- 🔗 **Signed URLs everywhere** — unsubscribe, preference, and tracking links are HMAC-signed so they can't be forged or enumerated
- 📥 **One-click unsubscribe** — RFC 8058 `List-Unsubscribe` headers (required by Gmail & Yahoo for bulk senders)
- 🪝 **Bounce & complaint webhooks** — Amazon SES, Mailgun, Postmark, and SendGrid; suppressed addresses never get emailed again
- 🧑‍🤝‍🧑 **Preference center** — subscribers manage their name and list memberships from a signed link
- 📄 **CSV import & export** — bring an existing list along, or take yours with you
- 🛡️ **Honeypot** spam protection on the subscribe form
- 🔐 **CP permissions** for lists, subscribers, and sendouts

## How to Install

```bash
composer require darinlarimore/statamic-email-subscribe
php artisan migrate
```

The addon stores subscribers, sendouts, and tracking events in your database (any Laravel-supported driver).

Optionally publish the config:

```bash
php artisan vendor:publish --tag=email-subscribe-config
```

### MJML

To compile MJML templates locally, install the MJML node package in your project:

```bash
npm install mjml
```

No Node on your production server? Set `EMAIL_SUBSCRIBE_MJML=api` and use your [MJML API](https://mjml.io/api) credentials, or `EMAIL_SUBSCRIBE_MJML=none` to write plain HTML templates instead. MJML compiles **once per sendout** (not per recipient), so even huge audiences only invoke it a single time.

### Queue

Sendouts are processed in batches on your queue — make sure a worker is running (`php artisan queue:work`). With the `sync` driver everything still works; sending just happens in-request.

For scheduled sendouts, the scheduler needs to call:

```bash
php artisan email-subscribe:send-scheduled
```

e.g. in `routes/console.php`: `Schedule::command('email-subscribe:send-scheduled')->everyMinute();`

## Collecting subscribers

Create a list in the CP (**Email Subscribe → Lists**), then drop the form tag in any template:

```antlers
{{ email_subscribe:form list="newsletter" redirect="/thanks" class="newsletter-form" }}
    {{ if success }}
        <p>{{ success }}</p>
    {{ else }}
        <input type="email" name="email" placeholder="you@example.com" required>
        <input type="text" name="first_name" placeholder="First name">
        <button>Subscribe</button>
    {{ /if }}

    {{ email_subscribe:errors }}
        <p class="error">{{ error }}</p>
    {{ /email_subscribe:errors }}
{{ /email_subscribe:form }}
```

Use `lists="newsletter|product"` to subscribe to multiple lists at once. The form posts JSON-friendly responses too, so it works with AJAX submissions.

With double opt-in enabled (the default), new subscribers get a confirmation email and stay `pending` until they click the signed verification link.

### Unsubscribe page

Every campaign already carries signed unsubscribe/preferences links (plus the one-click header), but you can also offer an unsubscribe form anywhere on your site:

```antlers
{{ email_subscribe:unsubscribe_form redirect="/unsubscribed" }}
    {{ if success }}
        <p>{{ success }}</p>
    {{ else }}
        <input type="email" name="email" placeholder="you@example.com" required>
        <button>Unsubscribe</button>
    {{ /if }}
{{ /email_subscribe:unsubscribe_form }}
```

For safety this never unsubscribes directly: the visitor gets a signed confirmation link by email, and the response is identical whether or not the address is subscribed — so the form can't be abused to unsubscribe someone else or probe your list.

## Campaign templates

A sendout renders a template of your choosing (default: the built-in MJML starter). Templates receive:

| Variable | Description |
| --- | --- |
| `subject`, `preheader`, `sendout` | Sendout fields |
| `entry` | The Statamic entry selected as the content source (optional) |
| `email`, `first_name`, `last_name`, `full_name` | Per-subscriber personalization |
| `unsubscribe_url`, `preferences_url` | Signed per-subscriber links |

Example (`resources/views/emails/newsletter.antlers.html`, set the sendout template to `emails.newsletter`):

```antlers
<mjml>
    <mj-body>
        <mj-section>
            <mj-column>
                <mj-text font-size="20px">{{ subject }}</mj-text>
                <mj-text>Hi {{ first_name }},</mj-text>
                <mj-text>{{ entry:content }}</mj-text>
                <mj-text font-size="12px">
                    <a href="{{ unsubscribe_url }}">Unsubscribe</a> ·
                    <a href="{{ preferences_url }}">Preferences</a>
                </mj-text>
            </mj-column>
        </mj-section>
    </mj-body>
</mjml>
```

The template renders and compiles **once per sendout**; personalization values are substituted per recipient at send time. Links are automatically rewritten for click tracking (disable with `email_subscribe.tracking.clicks`).

## Sending

1. **Email Subscribe → Sendouts → Create sendout**: subject, lists, content entry, template, sender.
2. **Send a test** to yourself — instant, no queue, no tracking, `[Test]` subject prefix.
3. **Preview** in the browser, then **Send now** or **Schedule** for a date.
4. Watch progress on the sendout page — **Pause**, **Resume**, or **Cancel** any time. Already-sent recipients are excluded by query, so resuming (or a retried queue job) can never double-send.

Batch size, delay between batches, per-recipient retry attempts, and the abort threshold are configurable under `email_subscribe.sendout`.

## Bounce & complaint webhooks

Set a signing key in `.env`:

```
EMAIL_SUBSCRIBE_WEBHOOK_KEY=some-long-random-string
```

Then point your provider's webhook at:

```
POST https://your-site.com/email-subscribe/webhook/{ses|mailgun|postmark|sendgrid}?key=YOUR_KEY
```

Hard bounces and spam complaints flip the subscriber to `bounced`/`complained` and permanently exclude them from sendouts. (SES SNS subscriptions are auto-confirmed on first delivery.)

## Importing

```bash
php artisan email-subscribe:import subscribers.csv --list=newsletter --subscribed
```

The CSV needs an `email` column; `first_name`/`last_name` are picked up when present. Omit `--subscribed` to import as pending. Export is available from the Subscribers CP page.

## Config reference

See [config/email_subscribe.php](config/email_subscribe.php) — from name/email, mailer, queue connection/name, batch settings, double opt-in, verification link expiry, open/click tracking toggles, MJML strategy, redirect URLs, and route prefix.

## License

Commercial — one project per license. See [LICENSE](LICENSE).
