<?php

namespace App\Models;

use Core\Model;

/**
 * Table: UsersLog
 * Columns:
 *   id (INTEGER, primary key)
 *   UserID (INTEGER)
 *   Activity (TEXT)
 *   created_at (TEXT)
 *   updated_at (TEXT)
 */
class UsersLog extends Model
{
    protected static $table = 'UsersLog';

    protected static $fillable = [
        'UserID',
        'Activity',
    ];
}
