<?php
/**
 * Moves roles + permissions into the database so admins can manage them at /admin/roles.
 * Seeds from config('roles') and config('permissions'). Runs once.
 */
return function (Core\Database $db, string $driver) {
    $sqlite = $driver === 'sqlite';
    $id   = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int  = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $str  = $sqlite ? 'TEXT' : 'VARCHAR(100)';
    $flag = $sqlite ? 'INTEGER' : 'TINYINT(1)';
    $time = $sqlite ? 'TEXT' : 'DATETIME';
    $tail = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $db->query("CREATE TABLE IF NOT EXISTS roles (
        id $id,
        name $str NOT NULL UNIQUE,
        label $str NOT NULL,
        is_locked $flag NOT NULL DEFAULT 0,
        created_at $time NOT NULL,
        updated_at $time NOT NULL
    )$tail");

    $db->query("CREATE TABLE IF NOT EXISTS role_permissions (
        role_id $int NOT NULL,
        permission $str NOT NULL,
        PRIMARY KEY (role_id, permission)
    )$tail");

    // Flat list of every permission in the catalog
    $catalog = [];
    foreach ((array) config('permissions', []) as $group) {
        foreach (array_keys((array) $group) as $perm) {
            $catalog[] = $perm;
        }
    }

    $now = date('Y-m-d H:i:s');
    $seeded = [];
    foreach ((array) config('roles', []) as $name => $granted) {
        $granted = (array) $granted;
        $locked = in_array('*', $granted, true) ? 1 : 0;
        $db->query(
            'INSERT INTO roles (name, label, is_locked, created_at, updated_at) VALUES (?, ?, ?, ?, ?)',
            [$name, ucfirst($name), $locked, $now, $now]
        );
        $roleId = (int) $db->lastInsertId();
        $seeded[] = $name;

        if ($locked) {
            continue;   // locked roles have full access; nothing to store
        }
        foreach ($catalog as $perm) {
            foreach ($granted as $g) {
                if ($g === $perm || fnmatch($g, $perm)) {
                    $db->query('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)', [$roleId, $perm]);
                    break;
                }
            }
        }
    }

    // Any role already used by a user but missing from config: create it with no permissions
    $used = $db->query('SELECT DISTINCT role FROM users')->fetchAll(PDO::FETCH_COLUMN);
    foreach ($used as $name) {
        if ($name !== null && $name !== '' && !in_array($name, $seeded, true)) {
            $db->query(
                'INSERT INTO roles (name, label, is_locked, created_at, updated_at) VALUES (?, ?, 0, ?, ?)',
                [$name, ucfirst($name), $now, $now]
            );
        }
    }
};
