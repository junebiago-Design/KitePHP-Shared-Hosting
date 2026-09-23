<?php

namespace App\Models;

use Core\Model;

/**
 * Backed by the SQL VIEW `UsersLogView`, not a table — read-only.
 *
 * Columns:
 *   log_id (INTEGER)
 *   name (TEXT)
 *   email (TEXT)
 *   Activity (TEXT)
 *   created_at (TEXT)
 *   updated_at (TEXT)
 *
 * $fillable is deliberately empty: SQLite views can't be written to
 * directly (no INSTEAD OF triggers are defined for this one), so
 * create()/update() here would just fail at the database level. Use
 * all()/find() to read; edit the underlying tables for writes.
 */
class UsersLogView extends Model
{
    protected static $table = 'UsersLogView';

    protected static $fillable = [];
}
