<?php
/** @var Core\Router $router */

use Core\Middleware as M;

// Handlers: 'Controller@method' (namespace App\Controllers), [Class::class, 'method'], or a closure.
// Third argument = middleware list, run before the controller:
//   M::auth()            logged in
//   M::guest()           NOT logged in
//   M::role('admin')     logged in + one of these roles (prefer M::can, it follows the Roles page)
//   M::can('posts.edit') logged in + role grants this permission (see config 'roles')

// ---- Public pages ----
$router->get('/', 'HomeController@index');
$router->get('/about', 'HomeController@about');
$router->get('/guide/{id}', 'PostController@guide');
// ---- Authentication ----
$router->get('/login', 'AuthController@showLogin', [M::guest()]);
$router->post('/login', 'AuthController@login', [M::guest()]);
// Public sign-up: open in debug mode; in production only until the first (admin) user exists.
// After that, admins create accounts at /admin/users/create.
$router->get('/register', 'AuthController@showRegister', [M::guest(), M::registration()]);
$router->post('/register', 'AuthController@register', [M::guest(), M::registration()]);
$router->post('/logout', 'AuthController@logout', [M::auth()]);


// ---- Any logged-in user ----
$router->get('/dashboard', 'DashboardController@index', [M::auth()]);

// ---- Administration: permission based (catalog in config 'permissions', managed at /admin/roles) ----
$router->group('/admin', function ($r) {
    $r->get('/users', 'AdminController@users');
    $r->get('/users/create', 'AdminController@create');
    $r->post('/users', 'AdminController@store');
    $r->get('/users/{id}/edit', 'AdminController@edit');
    $r->put('/users/{id}', 'AdminController@update');
    $r->post('/users/{id}/role', 'AdminController@updateRole');
    $r->delete('/users/{id}', 'AdminController@destroy');
}, [M::can('users.manage')]);

$router->group('/admin/roles', function ($r) {
    $r->get('/', 'RoleController@index');
    $r->get('/create', 'RoleController@create');
    $r->post('/', 'RoleController@store');
    $r->put('/permissions', 'RoleController@savePermissions');
    $r->delete('/{id}', 'RoleController@destroy');
}, [M::can('roles.manage')]);

// ---- Posts: reading is public, writing needs permissions ----
$router->get('/posts', 'PostController@index', [M::can('posts.view')]);
$router->get('/posts/create', 'PostController@create', [M::can('posts.create')]);
$router->post('/posts', 'PostController@store', [M::can('posts.create')]);
$router->get('/posts/{id}', 'PostController@show', [M::can('posts.view')]);
$router->get('/posts/{id}/edit', 'PostController@edit', [M::can('posts.edit')]);
$router->put('/posts/{id}', 'PostController@update', [M::can('posts.edit')]);
$router->delete('/posts/{id}', 'PostController@destroy', [M::can('posts.delete')]);

// ---- Files: library + serving uploads (see README-FILES.md) ----
$router->get('/files', 'FileController@index', [M::can('files.view')]);
$router->post('/files', 'FileController@store', [M::can('files.upload')]);
$router->get('/files/{id}/view', 'FileController@show', [M::can('files.view')]);          // access is checked in the controller
$router->get('/files/{id}/download', 'FileController@download', [M::can('files.view')]);  // (files on posts are public, library files need files.view)
$router->delete('/files/{id}', 'FileController@destroy', [M::auth()]);

// ---- JSON API (uses the same login session and CSRF token; see csrfFetch in app.js) ----
$router->group('/api', function ($r) {
    $r->get('/posts', 'ApiController@index');
    $r->post('/posts', 'ApiController@store', [M::can('posts.create')]);
});

// ---- Blog ----
$router->get('/blog', 'BlogController@index');

// ---- Test ----
$router->get('/test', 'TestController@index');



// ---- UsersLogController ----
$router->get('/UsersLog', 'UsersLogController@index');
$router->get('/UsersLog/create', 'UsersLogController@create');
$router->post('/UsersLog', 'UsersLogController@store');
$router->get('/UsersLog/{id}', 'UsersLogController@show');
$router->get('/UsersLog/{id}/edit', 'UsersLogController@edit');
$router->put('/UsersLog/{id}', 'UsersLogController@update');
$router->delete('/UsersLog/{id}', 'UsersLogController@destroy');

// ---- UsersLogViewController (read-only view) ----
$router->get('/users_log_view', 'UsersLogViewController@index');

// ---- Pugak ----
$router->get('/pugak', 'PugakController@index');

// ---- Uten ----
$router->get('/uten', 'UtenController@index');

// Closure example
$router->get('/hello/{name}', function ($request, $name) {
    return 'Hello, ' . htmlspecialchars($name) . '!';
});




$router->get('/database', 'DatabaseController@index', [M::can('database.manage')]);

$router->get   ('/database/api/tables',                   'DatabaseController@apiTables',      [M::can('database.manage')]);
$router->post  ('/database/api/tables',                   'DatabaseController@apiCreateTable', [M::can('database.manage')]);
$router->get   ('/database/api/tables/{table}',           'DatabaseController@apiSchema',      [M::can('database.manage')]);
$router->delete('/database/api/tables/{table}',           'DatabaseController@apiDropTable',   [M::can('database.manage')]);
$router->post  ('/database/api/tables/{table}/columns',   'DatabaseController@apiAddColumn',   [M::can('database.manage')]);
$router->post  ('/database/api/tables/{table}/empty',     'DatabaseController@apiEmptyTable',  [M::can('database.manage')]);
$router->get   ('/database/api/tables/{table}/rows',      'DatabaseController@apiRows',        [M::can('database.manage')]);
$router->post  ('/database/api/tables/{table}/rows',      'DatabaseController@apiInsertRow',   [M::can('database.manage')]);
$router->put   ('/database/api/tables/{table}/rows',      'DatabaseController@apiUpdateRow',   [M::can('database.manage')]);
$router->delete('/database/api/tables/{table}/rows',      'DatabaseController@apiDeleteRow',   [M::can('database.manage')]);
$router->post  ('/database/api/sql',                      'DatabaseController@apiSql',         [M::can('database.manage')]);
$router->post  ('/database/api/cli',                      'DatabaseController@apiCli',         [M::can('database.manage')]);



