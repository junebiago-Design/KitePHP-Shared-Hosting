<?php
namespace App\Models;

use Core\Database;
use Core\Model;

class Role extends Model
{
    protected static $table = 'roles';
    protected static $fillable = ['name', 'label'];   // is_locked is only ever set in code

    /** Locked roles (admin) have full access and cannot be edited or deleted. */
    public function isLocked(): bool
    {
        return (int) $this->is_locked === 1;
    }

    /** Permission names granted to this role. */
    public function permissions(): array
    {
        return Database::instance()
            ->query('SELECT permission FROM role_permissions WHERE role_id = ?', [$this->id])
            ->fetchAll(\PDO::FETCH_COLUMN);
    }

    /** Replace this role's permissions with exactly this list. */
    public function syncPermissions(array $permissions): void
    {
        $db = Database::instance();
        $pdo = $db->pdo();
        $pdo->beginTransaction();
        try {
            $db->query('DELETE FROM role_permissions WHERE role_id = ?', [$this->id]);
            foreach (array_unique($permissions) as $permission) {
                $db->query('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)', [$this->id, $permission]);
            }
            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    public function userCount(): int
    {
        return User::count(['role' => $this->name]);
    }

    public function delete(): bool
    {
        Database::instance()->query('DELETE FROM role_permissions WHERE role_id = ?', [$this->id]);
        return parent::delete();
    }

    /** Role names, optionally leaving out locked ones. */
    public static function names(bool $includeLocked = true): array
    {
        $roles = $includeLocked ? self::all('id ASC') : self::where(['is_locked' => 0], 'id ASC');
        return array_map(function ($r) {
            return $r->name;
        }, $roles);
    }

    public static function lockedNames(): array
    {
        return array_map(function ($r) {
            return $r->name;
        }, self::where(['is_locked' => 1]));
    }

    /** Grouped catalog from config: ['Posts' => ['posts.edit' => 'Edit posts', ...], ...] */
    public static function catalog(): array
    {
        return (array) config('permissions', []);
    }

    /** Flat list of every permission name in the catalog. */
    public static function catalogNames(): array
    {
        $names = [];
        foreach (self::catalog() as $group) {
            foreach (array_keys((array) $group) as $perm) {
                $names[] = $perm;
            }
        }
        return $names;
    }
}
