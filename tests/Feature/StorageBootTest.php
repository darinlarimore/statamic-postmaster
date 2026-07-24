<?php

use Darinlarimore\Postmaster\Support\Storage;
use Statamic\Facades\Collection;

/**
 * The content driver's storage collections are created at runtime, but a site
 * that commits them to git (the normal Statamic workflow) must not crash on
 * deploy: saving a collection while statamic:install warms the Stache re-indexes
 * entries against a not-yet-registered collection and throws entryClass()-on-null.
 * bootStorage() therefore stands down while a warm command is running.
 *
 * Each test points the storage handles at unique, addon-untouched names so the
 * assertions are about this boot alone, not collections a prior boot created.
 */
beforeEach(function () {
    $this->useDriver('content');
});

function withConsoleCommand(?string $command, callable $callback): void
{
    $original = $_SERVER['argv'] ?? null;
    $_SERVER['argv'] = $command === null ? ['artisan'] : ['artisan', $command];

    try {
        $callback();
    } finally {
        if ($original === null) {
            unset($_SERVER['argv']);
        } else {
            $_SERVER['argv'] = $original;
        }
    }
}

function pointStorageAt(string $suffix): array
{
    $subscribers = 'boot_subscribers_'.$suffix;
    $lists = 'boot_lists_'.$suffix;

    config()->set('postmaster.storage.collections.subscribers', $subscribers);
    config()->set('postmaster.storage.collections.lists', $lists);

    return [$subscribers, $lists];
}

test('bootStorage creates the storage collections on a normal boot', function () {
    [$subscribers, $lists] = pointStorageAt('normal');

    expect(Collection::handleExists($subscribers))->toBeFalse();

    Storage::bootStorage();

    expect(Collection::handleExists($subscribers))->toBeTrue();
    expect(Collection::handleExists($lists))->toBeTrue();
    expect(Collection::findByHandle($subscribers)->title())->toBe('Subscribers');
});

test('bootStorage heals a legacy Postmasterrs collection title', function () {
    [$subscribers] = pointStorageAt('title-heal');

    Collection::make($subscribers)->title('Postmasterrs')->dated(true)->save();
    expect(Collection::findByHandle($subscribers)->title())->toBe('Postmasterrs');

    Storage::bootStorage();

    expect(Collection::findByHandle($subscribers)->title())->toBe('Subscribers');
});

test('bootStorage stands down while the Stache is being warmed', function () {
    [$subscribers, $lists] = pointStorageAt('warm');

    withConsoleCommand('statamic:install', function () {
        Storage::bootStorage();
    });

    expect(Collection::handleExists($subscribers))->toBeFalse();
    expect(Collection::handleExists($lists))->toBeFalse();
});

test('an already-created collection survives a warm and reconciles idempotently', function () {
    [$subscribers, $lists] = pointStorageAt('committed');

    // Site already has the collection (committed to git / created earlier).
    Storage::bootStorage();
    expect(Collection::handleExists($lists))->toBeTrue();

    // A warm must not throw and must leave it intact.
    withConsoleCommand('statamic:stache:warm', function () {
        Storage::bootStorage();
    });

    expect(Collection::handleExists($lists))->toBeTrue();

    // A normal request reconciles again without throwing.
    Storage::bootStorage();

    expect(Collection::handleExists($lists))->toBeTrue();
    expect(Collection::handleExists($subscribers))->toBeTrue();
});
