<?php
namespace App\Models;

use Core\Model;

class Post extends Model
{
    protected static $table = 'posts';
    protected static $fillable = ['title', 'body'];
}
