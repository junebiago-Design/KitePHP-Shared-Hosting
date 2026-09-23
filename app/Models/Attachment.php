<?php
namespace App\Models;

use Core\Model;
use Core\Uploader;

class Attachment extends Model
{
    protected static $table = 'attachments';
    protected static $fillable = ['post_id', 'user_id', 'original_name', 'stored_name', 'mime', 'ext', 'size', 'kind'];

    public function humanSize(): string
    {
        return human_size((int) $this->size);
    }

    public function isImage(): bool
    {
        return $this->kind === 'image';
    }

    /** Files attached to one post. */
    public static function forPost($postId): array
    {
        return self::where(['post_id' => $postId], 'id ASC');
    }

    public static function deleteForPost($postId): void
    {
        foreach (self::forPost($postId) as $attachment) {
            $attachment->delete();
        }
    }

    /** Removes the file from disk as well as the database row. */
    public function delete(): bool
    {
        Uploader::delete((string) $this->stored_name);
        return parent::delete();
    }
}
