<?php

namespace Darinlarimore\Postmaster\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Base for the addon's relational models. Resolves the database connection from
 * config at query time (rather than a static $connection property) so the
 * addon-owned SQLite connection, or a user-supplied one, is honoured without
 * every model hardcoding it. A null config value falls through to Eloquent's
 * default connection - which is what the test suite migrates against.
 */
abstract class PostmasterModel extends Model
{
    public function getConnectionName(): ?string
    {
        return config('postmaster.storage.connection') ?: parent::getConnectionName();
    }
}
