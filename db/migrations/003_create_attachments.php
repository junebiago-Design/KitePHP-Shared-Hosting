<?php
/**
 * Uploaded files. Each row points to a file in storage/uploads (post_id is NULL for
 * files uploaded straight to the Files library). Also gives existing roles sensible
 * default file permissions.
 */
return function (Core\Database $db, string $driver) {
    $sqlite = $driver === 'sqlite';
    $id    = $sqlite ? 'INTEGER PRIMARY KEY AUTOINCREMENT' : 'INT UNSIGNED AUTO_INCREMENT PRIMARY KEY';
    $int   = $sqlite ? 'INTEGER' : 'INT UNSIGNED';
    $str   = $sqlite ? 'TEXT' : 'VARCHAR(255)';
    $short = $sqlite ? 'TEXT' : 'VARCHAR(30)';
    $time  = $sqlite ? 'TEXT' : 'DATETIME';
    $tail  = $sqlite ? '' : ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';

    $db->query("CREATE TABLE IF NOT EXISTS attachments (
        id $id,
        post_id $int NULL,
        user_id $int NULL,
        original_name $str NOT NULL,
        stored_name $str NOT NULL UNIQUE,
        mime $str NOT NULL,
        ext $short NOT NULL,
        size $int NOT NULL DEFAULT 0,
        kind $short NOT NULL,
        created_at $time NOT NULL,
        updated_at $time NOT NULL
    )$tail");

    try {
        $db->query('CREATE INDEX idx_attachments_post ON attachments (post_id)');
    } catch (Throwable $e) {
        // index already exists: fine
    }

    // Existing (non-locked) roles: everyone may view the library; roles that can create posts may upload
    try {
        $roles = $db->query('SELECT id, is_locked FROM roles')->fetchAll();
    } catch (Throwable $e) {
        $roles = [];
    }
    foreach ($roles as $role) {
        if ((int) $role['is_locked'] === 1) {
            continue;
        }
        $has = $db->query('SELECT permission FROM role_permissions WHERE role_id = ?', [$role['id']])->fetchAll(PDO::FETCH_COLUMN);
        $grant = ['files.view'];
        if (in_array('posts.create', $has, true)) {
            $grant[] = 'files.upload';
        }
        foreach ($grant as $permission) {
            if (!in_array($permission, $has, true)) {
                $db->query('INSERT INTO role_permissions (role_id, permission) VALUES (?, ?)', [$role['id'], $permission]);
            }
        }
    }
};
