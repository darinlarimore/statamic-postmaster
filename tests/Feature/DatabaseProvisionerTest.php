<?php

use Darinlarimore\Postmaster\Support\DatabaseProvisioner;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Sendouts and tracking events are always relational, so the addon provisions
 * its own SQLite database rather than requiring the host app to configure one.
 * These tests cover that provisioning against a real (temp) SQLite file, which
 * the RefreshDatabase harness otherwise bypasses by pointing the connection at
 * the in-memory testing database.
 */
it('registers a self-owned sqlite connection when none is configured', function () {
    config()->set('database.connections.postmaster', null);
    config()->set('postmaster.storage.connection', 'postmaster');
    config()->set('postmaster.storage.database', '/tmp/pm-provision-'.getmypid().'.sqlite');

    DatabaseProvisioner::registerConnection();

    expect(config('database.connections.postmaster'))
        ->toMatchArray(['driver' => 'sqlite'])
        ->and(config('database.connections.postmaster.database'))
        ->toBe('/tmp/pm-provision-'.getmypid().'.sqlite');
});

it('leaves a user-supplied connection untouched', function () {
    config()->set('postmaster.storage.connection', 'mysql');
    config()->set('database.connections.postmaster', null);

    DatabaseProvisioner::registerConnection();

    // Only the addon-owned 'postmaster' connection is ever auto-created.
    expect(config('database.connections.postmaster'))->toBeNull();
});

it('creates the sqlite file and migrates the addon tables', function () {
    $path = sys_get_temp_dir().'/pm-provision-'.uniqid().'.sqlite';
    File::delete($path);

    config()->set('database.connections.postmaster', [
        'driver' => 'sqlite',
        'database' => $path,
        'prefix' => '',
        'foreign_key_constraints' => true,
    ]);

    expect(File::exists($path))->toBeFalse();

    // Mirror ensureProvisioned()'s work directly - the guard skips it under the
    // test runner, so exercise the file creation + migration here.
    File::put($path, '');

    $exit = Artisan::call('migrate', [
        '--database' => 'postmaster',
        '--path' => realpath(__DIR__.'/../../database/migrations'),
        '--realpath' => true,
        '--force' => true,
    ]);

    expect($exit)->toBe(0)
        ->and(File::exists($path))->toBeTrue()
        ->and(Schema::connection('postmaster')->hasTable('postmaster_sendouts'))->toBeTrue()
        ->and(Schema::connection('postmaster')->hasTable('postmaster_events'))->toBeTrue()
        ->and(Schema::connection('postmaster')->hasColumn('postmaster_events', 'subscriber_key'))->toBeTrue();

    File::delete($path);
});
