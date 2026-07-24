<?php

namespace Darinlarimore\Postmaster\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;

/**
 * Sendouts, links, and tracking events are always relational (write-heavy,
 * aggregate-queried) - a poor fit for flat files. So the addon owns its own
 * database rather than requiring the host app to configure one.
 *
 * By default that database is a self-provisioned SQLite file the addon creates
 * and migrates on boot, letting a flat-file Statamic site run the addon with
 * zero database configuration. A site can instead point
 * postmaster.storage.connection at one of its own database.connections to
 * co-locate the data with an existing database.
 */
class DatabaseProvisioner
{
    /**
     * Register the addon's own SQLite connection so its models resolve even on
     * a host app that defines no database connection. A user-supplied
     * connection name (e.g. 'mysql') already exists in database.connections and
     * is left untouched.
     */
    public static function registerConnection(): void
    {
        $name = static::connectionName();

        if ($name !== 'postmaster' || config("database.connections.{$name}")) {
            return;
        }

        config([
            "database.connections.{$name}" => [
                'driver' => 'sqlite',
                'database' => config('postmaster.storage.database'),
                'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
    }

    /**
     * Create the SQLite file if missing and bring its schema up to date. Only
     * touches the filesystem for the addon-owned SQLite connection; a
     * user-supplied connection is assumed to be migrated by the host app's own
     * deploy flow, so this just ensures the tables exist.
     */
    public static function ensureProvisioned(): void
    {
        if (static::shouldDefer()) {
            return;
        }

        $name = static::connectionName();

        if (static::ownsSqlite($name)) {
            $path = config("database.connections.{$name}.database");

            if (is_string($path) && $path !== ':memory:' && ! File::exists($path)) {
                File::ensureDirectoryExists(dirname($path));
                File::put($path, '');
            }
        }

        DB::purge($name);

        if (static::isMigrated($name)) {
            return;
        }

        // Run ONLY the addon's migrations against our connection - a bare
        // --database would also run the host app's migrations against our
        // SQLite file. --realpath with __DIR__ resolves whether the package is
        // the test root or installed under any vendor dir.
        Artisan::call('migrate', [
            '--database' => $name,
            '--path' => realpath(__DIR__.'/../../database/migrations'),
            '--realpath' => true,
            '--force' => true,
        ]);
    }

    protected static function connectionName(): string
    {
        return config('postmaster.storage.connection') ?: config('database.default');
    }

    /**
     * Skip provisioning when a migrate command is already running (avoids a
     * nested migrate) or in the test suite, where RefreshDatabase owns the
     * schema. Provisioning DOES run during statamic:install (deploy) and on the
     * first real request, which is what makes a fresh install self-heal.
     */
    protected static function shouldDefer(): bool
    {
        if (app()->runningUnitTests()) {
            return true;
        }

        $command = $_SERVER['argv'][1] ?? null;

        return is_string($command) && str_starts_with($command, 'migrate');
    }

    protected static function ownsSqlite(string $name): bool
    {
        return $name === 'postmaster'
            && config("database.connections.{$name}.driver") === 'sqlite';
    }

    /**
     * The last table the create migration builds - its presence means the base
     * schema is in place. Cheaper and more robust than reading the migrations
     * table, which can drift out of sync with the actual schema.
     */
    protected static function isMigrated(string $name): bool
    {
        try {
            return Schema::connection($name)->hasTable('postmaster_events');
        } catch (\Throwable) {
            return false;
        }
    }
}
