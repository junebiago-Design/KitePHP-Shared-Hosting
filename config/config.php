<?php
return [
    'app' => [
        'name'        => 'KitePHP',
        'debug'       => false,          // set false in production
        'timezone'    => 'Asia/Manila',
        'pretty_urls' => true,         // false: /index.php?route=/posts  |  true: /posts (needs mod_rewrite)
        'csrf'        => true,
        'csrf_except' => [],            // paths that skip CSRF (leave empty: sessions need it)
    ],
    'db' => [
        'driver' => 'sqlite',                       // 'sqlite' or 'mysql'
        'auto_migrate' => true,                     // run new files in db/migrations on connect

        // SQLite (default): file is created + seeded automatically on first run
        'sqlite_path' => 'storage/database.sqlite', // relative to project root
        'sqlite_schema' => 'db/schema.sqlite.sql',  // run once when the file is created

        // MySQL (only used when driver = 'mysql')
        'host' => 'sqlXXX.infinityfree.com',
        'port' => 3306,
        'name' => 'if0_XXXXXXXX_mydb',
        'user' => 'if0_XXXXXXXX',
        'pass' => 'your-password',
    ],
    'auth' => [
        'model'        => 'App\\Models\\User',
        'role_model'   => 'App\\Models\\Role',
        'default_role' => 'user',        // role given to new registrations
        'home'         => '/dashboard',  // where to go after login
    ],

    // PERMISSION CATALOG: every permission your app knows about, grouped for the
    // Roles page (/admin/roles). To add one: add a line here, protect the route with
    // Middleware::can('name'), then tick it for the right roles on the Roles page.
    'permissions' => [
        'Posts' => [
            'posts.create' => 'Create posts',
            'posts.view'   => 'View posts',
            'posts.edit'   => 'Edit posts',
            'posts.delete' => 'Delete posts',
        ],
        'Database' => [
            'database.manage' => 'Manage database tables and rows',
        ],
        'Files' => [
            'files.view'   => 'View the Files library',
            'files.upload' => 'Upload files',
            'files.delete' => 'Delete any file',
        ],
        'Administration' => [
            'users.manage' => 'Manage users (add, edit, delete)',
            'roles.manage' => 'Manage roles and permissions',
        ],
    ],

    // STARTING roles, copied into the database once by db/migrations/002_create_roles.php.
    // After that, roles and their permissions are managed at /admin/roles, not here.
    // A role containing '*' becomes a locked "full access" role (admin).
    // (Also used as a fallback if the roles tables can't be read.)
    'roles' => [
        'admin'  => ['*'],
        'editor' => ['posts.create', 'posts.edit', 'files.view', 'files.upload'],
        'user'   => ['posts.create', 'files.view', 'files.upload'],
        'viewer'   => ['posts.view', 'files.view'],
    ],

    // FILE UPLOADS (see README-FILES.md). Files are stored in storage/uploads, outside the web folder.
    // The real per-file limit is the smaller of max_size_mb and your host's PHP limits.
    'uploads' => [
        'dir'         => 'storage/uploads',
        'max_size_mb' => 50,
        'max_files'   => 10,
        'allowed'     => ['jpg', 'jpeg', 'png', 'gif', 'webp', 'pdf',
                          'xlsx', 'xls', 'csv', 'ods', 'docx', 'doc', 'odt', 'pptx', 'ppt', 'txt', 'md'],
    ],
];
