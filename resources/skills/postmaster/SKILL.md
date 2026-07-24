---
name: postmaster
description: >-
  Author, send, and manage newsletters with the Postmaster addon.
  Use when the user wants to create/send an email campaign or newsletter, manage
  mailing lists or subscribers, add subscribers or import a CSV, personalize
  emails with merge tags, or wire a subscribe form. Covers the addon's
  entry-centric workflow and its non-obvious rules.
---

# Postmaster

This addon treats a newsletter as **content**: you author an email as a Statamic
entry, and a delivery record (a "sendout") is created and driven automatically.
Almost everything is a native entry, so use the **Statamic MCP tools / normal
entry APIs** to do the work — this skill exists to teach the rules that aren't
obvious from the entry itself.

## The core rule: the entry drives everything

A collection whose blueprint imports the `postmaster::email_blocks` fieldset
is a "newsletter collection". For an entry in one:

- **Saving the entry auto-creates its draft sendout.** Never create a Sendout
  record by hand — save the entry and the addon makes one. One active sendout
  per entry.
- **The draft follows the entry live** (subject, sender, recipient lists, blocks)
  until the run starts, then freezes.
- **`Published` is the send-approval gate.** A sendout cannot start until its
  entry is published. Publishing an unstarted draft flips its status to
  "ready to send".
- **A completed send unpublishes the entry.** Editing + re-publishing creates a
  fresh draft — that is how a re-send works.

So the end-to-end flow to send a newsletter is:

1. Create/find an entry in the newsletter collection (e.g. `newsletters`).
2. Set its content: `email_blocks` (the builder), `email_subject`
   (blank → entry title), `email_preheader`, and pick recipients in
   `email_lists`. Optional sender overrides: `email_from_name`,
   `email_from_email`, `email_reply_to`. Optional `email_template` override.
3. **Save it** — the sendout now exists (find it by `entry_id`).
4. **Publish** the entry to approve it.
5. Start or schedule the sendout (see "Actions the entry can't do" below).

If starting a fresh site, `php artisan postmaster:scaffold-newsletter`
creates the collection, an example entry, a list, and wires Live Preview.

## Email Blocks

`email_blocks` is a replicator with these set types: `heading` (text + size
h1/h2/h3), `text` (bard/rich text), `image` (asset + alt + optional link),
`button` (label + url + optional color), `divider`, `spacer` (height). Toggle a
block's `enabled` off to hide it without deleting. Blocks render through one
template (`resources/views/vendor/postmaster/emails/default.antlers.html`,
which imports a partial per type from `emails/blocks/`).

## Merge tags

Type these anywhere in block text or the subject line; they resolve per
recipient at send time:

`{{ email }}`, `{{ first_name }}`, `{{ last_name }}`, `{{ full_name }}`,
`{{ unsubscribe_url }}`, `{{ preferences_url }}`, **plus any custom subscriber
field**. Unknown tags render as empty text.

## Subscribers, lists, and custom fields

- Subscribers and lists are entries (content driver) in the `postmaster_subscribers`
  and `email_lists` collections. **Subscriber entries are keyed by
  `sha1(lowercased email)`** — so to create one correctly, use the addon's Add
  form / `SubscriberService::subscribe()`, or the CP, NOT a raw
  `Entry::make()`. The CP redirects raw subscriber entry-create for this reason.
- **The subscriber blueprint is the schema for custom fields.** Add a field
  (e.g. `company`) to it and it is automatically captured from subscribe forms
  and CSV imports, editable on the subscriber, and usable as `{{ company }}`.
- Import subscribers via **Postmaster → Subscribers → Import CSV** (there's
  a downloadable template) or `php artisan postmaster:import file.csv
  --list=handle --subscribed`. The CSV needs an `email` column; names and any
  custom-field columns are picked up when present.

## Collecting subscribers on the front end

Drop the tag in any template (wrap in `{{ nocache }}` if using static caching):

```antlers
{{ postmaster:form list="newsletter" redirect="/thanks" }}
    <input type="email" name="email" required>
    <input type="text" name="first_name">
    <button>Subscribe</button>
    {{ postmaster:errors }}<p>{{ error }}</p>{{ /postmaster:errors }}
{{ /postmaster:form }}
```

The tag injects the form wrapper, CSRF, a honeypot, and the list field. Valid
inputs: `email` (required), `first_name`, `last_name`, and any custom field.
With double opt-in (the default per list) new subscribers stay `pending` until
they click the signed verification link.

## Actions the entry can't do (drive these directly)

These aren't entry writes — the sendout page in the CP does them, or drive the
services in code:

- **Start / schedule / pause / resume / cancel** a sendout — `SendoutService`
  (`start($sendout, $sendAt?)`, `pause`, `resume`, `cancel`). `start()` throws
  if the entry is unpublished or no lists are selected.
- **Send a test** — the entry's "Send Test Email" action, or
  `SendoutService::sendTests($sendout, ['you@example.com', ...])`. Instant, no
  queue, no tracking, no records touched.
- **Read results** — opens/clicks/bounces/complaints live in DB event tables,
  surfaced on the sendout page and via `$sendout->stats()`.

## Gotchas

- Scheduled sendouts need the scheduler running
  `postmaster:send-scheduled`; queued sends need a worker. With `sync`
  queue, sending happens in-request.
- Live Preview only shows for routable collections — the scaffold uses the
  addon's preview URL as the collection route to satisfy this.
- To use your own collection, import the fieldset **without a `prefix`** (the
  fixed field handles drive the automation).
