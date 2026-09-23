<?php
/**
 * Creates the users table. Runs automatically once (see Core\Database::migrate).
 * To add another migration, create 002_something.php that returns a function like this one.
 */
return function (Core\Database $db, string $driver) {
    if ($driver === 'sqlite') {
        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password TEXT NOT NULL,
            role TEXT NOT NULL DEFAULT 'user',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
    } else {
        $db->query("CREATE TABLE IF NOT EXISTS users (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            email VARCHAR(191) NOT NULL UNIQUE,
            password VARCHAR(255) NOT NULL,
            role VARCHAR(30) NOT NULL DEFAULT 'user',
            created_at DATETIME NOT NULL,
            updated_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }

    // Demo accounts, ONLY while debug is on. In production the first person to
    // register becomes the admin instead.
    if (config('app.debug')) {
        $now = date('Y-m-d H:i:s');
        $demo = [
            ['Admin',  'admin@example.com',  'admin'],
            ['Editor', 'editor@example.com', 'editor'],
            ['Member', 'user@example.com',   'user'],
        ];
        foreach ($demo as $u) {
            $db->query(
                'INSERT INTO users (name, email, password, role, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?)',
                [$u[0], $u[1], password_hash('password', PASSWORD_DEFAULT), $u[2], $now, $now]
            );
        }
    }
};
