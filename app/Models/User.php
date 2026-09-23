<?php
namespace App\Models;

use Core\Model;

class User extends Model
{
    protected static $table = 'users';
    // 'role' is deliberately NOT fillable: it is only ever set explicitly in code
    protected static $fillable = ['name', 'email', 'password'];
    protected static $hidden = ['password'];
}
