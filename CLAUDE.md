# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Commercial Statamic addon: mailing lists, subscribers with double opt-in, MJML campaign templates, queue-batched sendouts, open/click tracking, and bounce/complaint webhooks. Supports both Statamic 5 and 6 (`statamic/cms: ^5.0|^6.0`, PHP `^8.2`). Data lives in the database (migrations under `database/migrations/`), not the Stache.

## Commands

```bash
composer test          # Pest test suite
composer format        # Auto-fix style with Pint
composer format:check  # Check style without modifying
npm install            # Installs the mjml binary (dev-only, for local MJML compile tests)
```

To run tests against the other Statamic major: `composer require "statamic/cms:^5.74" -W` (or `^6.0`), run pest, then restore `^5.0|^6.0`. CI runs the matrix automatically.

## Architecture

### Rendering pipeline (the key design)

`Support/CampaignRenderer.php` renders a sendout's template (Antlers or Blade, usually MJML) **once per sendout** with `%%es:*%%` sentinel tokens standing in for per-subscriber values, compiles MJML once (`Support/Mjml.php` — binary/api/none strategies), rewrites external links to `%%es:click:{linkId}%%` placeholders (links stored in `email_subscribe_links`, avoiding open-redirect abuse), and stores the result as the sendout's `html` snapshot. At send time `personalize()` swaps sentinels per recipient — so MJML never compiles per-recipient. Test emails (`SendoutService::sendTest`) personalize with `tracking: false` and an unsaved Subscriber (URLs become `#`).

### Sendout engine

`Jobs/ProcessSendoutBatch.php` sends ONE batch then re-dispatches itself until done (chain, not a loop — keeps every job inside queue timeouts). Idempotency comes from the recipient query, not a cursor: `Sendout::pendingRecipientsQuery()` excludes subscribers who already have a `sent`/`failed` event for the sendout, so pause/resume/job-retries can never double-send. Pausing just flips `status`; the running job checks status per recipient and stops. The whole sendout aborts once `sendout.max_failures` failed recipients accumulate.

Statuses — Sendout: `draft, pending, sending, paused, sent, cancelled, failed`. Subscriber: `pending, subscribed, unsubscribed, bounced, complained` (global suppression) plus per-list pivot `unsubscribed_at`.

### Routes

- `routes/web.php` — subscribe POST, signed verify/unsubscribe/preferences (web middleware, CSRF).
- `routes/stateless.php` — tracking pixel/click, RFC 8058 one-click unsubscribe POST, provider webhooks. Registered in the ServiceProvider with only `SubstituteBindings` + throttle (no session/CSRF — mail clients and providers hit these).
- `routes/cp.php` — CP pages, guarded by `can:` middleware (permissions registered in ServiceProvider).

All subscriber-facing URLs are Laravel signed routes (`Support/Urls.php`) — ids alone are never treated as authorization.

### Webhooks

`Http/Controllers/Web/WebhookController.php` handles ses/mailgun/postmark/sendgrid, gated by `?key=` vs `email_subscribe.webhooks.signing_key`. **Always return 2xx for events we don't act on** — providers auto-disable webhooks that keep failing.

### CP UI

Blade views extending `statamic::layout` with self-contained `es-*` styles (`resources/views/cp/partials/shell.blade.php`) — intentionally **no Vue/JS build**, which is what makes one codebase work on both Statamic 5 and 6 CPs. Don't introduce Vue fieldtypes without setting up the dual-version IIFE build pattern (see statamic-imagehotspots).

## Cross-version compatibility rules

- Code must run on Statamic 5 (Laravel 11/12) and 6 (Laravel 12/13). No `Statamic::version()` branching exists; keep it that way — resolve divergences at runtime (`Route::has(...)` etc.).
- Tests: `Statamic\Testing\AddonTestCase` + sqlite `:memory:`; `statamic.editions.pro` is enabled because file-based users persist across tests in one run.
- The local composer environment blocks packages with security advisories; when pinning old Statamic versions for testing you may need `composer config policy.advisories.block false` temporarily (never commit that).

## Gotchas

- `Mailable` already defines `$html`; the campaign HTML lives on `CampaignMail::$htmlBody`.
- `firstOrNew` doesn't apply DB column defaults — new `Subscriber` models get `status` set explicitly in `SubscriberService`.
- Blade eats literal `{{ }}` even inside `__()` strings — don't put Antlers examples in CP view strings.
- The MJML sentinel tokens must survive both the view engine and MJML compilation; if you add a token, add it to `render()`, `personalize()`, AND `SendoutsController::preview()`.
- Laravel Boost runs via `vendor/bin/testbench boost:mcp` (no artisan in a package — the agent MCP configs are already pointed there). Never re-run `boost:install`: it writes a Laravel-app `.env` (database cache/queue drivers) into `vendor/orchestra/testbench-core/laravel/`, which testbench loads and which breaks the whole suite with "no such table: cache/jobs". If that happens, delete that `.env`.
- The site-facing unsubscribe form (`{{ email_subscribe:unsubscribe_form }}`) never unsubscribes directly — it emails a signed link, and responds identically for unknown addresses so the list can't be probed.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4

## Skills Activation

This project has domain-specific skills available in `**/skills/**`. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `npm run build`, `npm run dev`, or `composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

## Tools

- Laravel Boost is an MCP server with tools designed specifically for this application. Prefer Boost tools over manual alternatives like shell commands or file reads.
- Use `database-query` to run read-only queries against the database instead of writing raw SQL in tinker.
- Use `database-schema` to inspect table structure before writing migrations or models.
- Use `get-absolute-url` to resolve the correct scheme, domain, and port for project URLs. Always use this before sharing a URL with the user.
- Use `browser-logs` to read browser logs, errors, and exceptions. Only recent logs are useful, ignore old entries.

## Searching Documentation (IMPORTANT)

- Always use `search-docs` before making code changes. Do not skip this step. It returns version-specific docs based on installed packages automatically.
- Pass a `packages` array to scope results when you know which packages are relevant.
- Use multiple broad, topic-based queries: `['rate limiting', 'routing rate limiting', 'routing']`. Expect the most relevant results first.
- Do not add package names to queries because package info is already shared. Use `test resource table`, not `filament 4 test resource table`.

### Search Syntax

1. Use words for auto-stemmed AND logic: `rate limit` matches both "rate" AND "limit".
2. Use `"quoted phrases"` for exact position matching: `"infinite scroll"` requires adjacent words in order.
3. Combine words and phrases for mixed queries: `middleware "rate limit"`.
4. Use multiple queries for OR logic: `queries=["authentication", "middleware"]`.

## Artisan

- Run Artisan commands directly via the command line (e.g., `php artisan route:list`). Use `php artisan list` to discover available commands and `php artisan [command] --help` to check parameters.
- Inspect routes with `php artisan route:list`. Filter with: `--method=GET`, `--name=users`, `--path=api`, `--except-vendor`, `--only-vendor`.
- Read configuration values using dot notation: `php artisan config:show app.name`, `php artisan config:show database.default`. Or read config files directly from the `config/` directory.

## Tinker

- Execute PHP in app context for debugging and testing code. Do not create models without user approval, prefer tests with factories instead. Prefer existing Artisan commands over custom tinker code.
- Always use single quotes to prevent shell expansion: `php artisan tinker --execute 'Your::code();'`
  - Double quotes for PHP strings inside: `php artisan tinker --execute 'User::where("active", true)->count();'`

=== php rules ===

# PHP

- Always use curly braces for control structures, even for single-line bodies.
- Use PHP 8 constructor property promotion: `public function __construct(public GitHub $github) { }`. Do not leave empty zero-parameter `__construct()` methods unless the constructor is private.
- Use explicit return type declarations and type hints for all method parameters: `function isAccessible(User $user, ?string $path = null): bool`
- Use TitleCase for Enum keys: `FavoritePerson`, `BestLake`, `Monthly`.
- Prefer PHPDoc blocks over inline comments. Only add inline comments for exceptionally complex logic.
- Use array shape type definitions in PHPDoc blocks.

=== deployments rules ===

# Deployment

- Laravel can be deployed using [Laravel Cloud](https://cloud.laravel.com/), which is the fastest way to deploy and scale production Laravel applications.

</laravel-boost-guidelines>
