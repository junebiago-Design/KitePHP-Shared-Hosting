# KitePHP — AI-Ready Project Reference

> **Purpose:** compact, source-grounded documentation for developers and AI coding agents working on this repository.
>
> **Project identity:** KitePHP is a small, dependency-free PHP MVC framework/application hybrid designed for shared hosting. It provides routing, controllers, models, views/layouts, sessions, CSRF, validation, authentication/RBAC, uploads, JSON APIs, SQLite/MySQL access, migrations, a browser database manager, and CLI helpers.
>
> **Important:** this repository contains both framework code (`core/`) and application/features (`app/`). Treat `core/` as reusable infrastructure and `app/` as project-specific behavior.

---

## 0. Agent Operating Contract

When modifying this project:

1. **Read before changing.** Inspect the target controller, model, route, view, config entry, and migration before editing.
2. **Preserve architecture:** `Route → Middleware → Controller → Model/Service → View/Response`.
3. **Do not put application features in `core/`** unless the requested change is genuinely framework-wide.
4. **Database changes use forward-only migrations** in `db/migrations/`. Never rewrite a migration that may already have run; add the next numbered migration.
5. **Protect writes:** POST/PUT/PATCH/DELETE are CSRF-protected by the router unless explicitly excepted.
6. **Protect authorization at the route/controller level.** Hiding a button is not access control.
7. **Escape normal HTML output with `e()`** and use `url()` / `asset()` for generated links.
8. **Preserve shared-host compatibility:** request parsing accepts JSON even when the request is not `application/json`; PUT/PATCH/DELETE can be tunneled through POST using `_method` or `X-HTTP-METHOD-OVERRIDE`.
9. **Do not expose secrets or internal storage files.** `config/`, `app/`, `core/`, `routes/`, `db/`, and `storage/` have `.htaccess` protection.
10. **Keep debug disabled in production.**
11. **Do not assume copied files are active.** Files named `Copy` are historical/experimental variants unless referenced by active code.
12. **Verify route order.** Fixed routes such as `/posts/create` must be registered before `/posts/{id}`.
13. **After a structural change, update this document's relevant section** so future agents can understand the new contract.

---

## 1. System Map

```text
Browser / CLI
    │
    ├── Web request
    │      ↓
    │  index.php
    │      ↓
    │  core/bootstrap.php
    │      ├── PSR-4-style App\ / Core\ autoloading
    │      └── core/helpers.php
    │      ↓
    │  Core\App
    │      ├── config/config.php
    │      ├── Session::start()
    │      └── routes/web.php
    │             ↓
    │        Core\Router
    │        ├── method/path matching
    │        ├── method override
    │        ├── upload-size guard
    │        ├── CSRF guard
    │        └── route middleware
    │             ↓
    │        App\Controller
    │        ├── Core\Model / Core\Database
    │        ├── App\Services
    │        └── Core\Uploader / Auth / Session
    │             ↓
    │        Core\View / Core\Response
    │             ↓
    │          Browser
    │
    └── CLI
           ├── kite.php → App\Services\KiteConsole
           └── database.php → App\Services\DatabaseCli
```

### Core responsibilities

| Layer | Location | Responsibility |
|---|---|---|
| Entry point | `index.php` | Front controller |
| Bootstrap | `core/bootstrap.php` | Autoload + helpers |
| Application kernel | `core/App.php` | Config, session, dispatch, global error handling |
| Routing | `core/Router.php` | Routes, HTTP methods, middleware, CSRF |
| Request | `core/Request.php` | Query/body/files/headers/path/method |
| Response | `core/Response.php` | HTML, JSON, redirect, streamed files |
| Controllers | `app/Controllers/` | Application use-cases |
| Models | `app/Models/` | Database entities/query behavior |
| Database | `core/Database.php` | PDO + schema + migrations |
| ORM-lite | `core/Model.php` | CRUD/query/hydration |
| Views | `app/Views/` | HTML templates |
| Auth | `core/Auth.php` | Session authentication + permissions |
| Middleware | `core/Middleware.php` | Auth/guest/role/permission/registration guards |
| Uploads | `core/Uploader.php` | Validation + safe storage |
| Services | `app/Services/` | Database CLI / generator utilities |
| Static assets | `assets/` | CSS and browser JavaScript |

---

## 2. Repository Structure

```text
/
├── index.php                         # web front controller
├── kite.php                          # CLI code generator
├── database.php                      # CLI DB migration/backup utility
├── .htaccess                         # root web rules/protection
│
├── core/                             # framework infrastructure
│   ├── App.php
│   ├── Auth.php
│   ├── Controller.php
│   ├── Database.php
│   ├── HttpException.php
│   ├── Middleware.php
│   ├── Model.php
│   ├── Request.php
│   ├── Response.php
│   ├── Router.php
│   ├── Session.php
│   ├── Uploader.php
│   ├── ValidationException.php
│   ├── Validator.php
│   ├── View.php
│   ├── bootstrap.php
│   ├── helpers.php
│   └── .htaccess
│
├── config/
│   └── config.php                    # runtime configuration + permission catalog
│
├── routes/
│   └── web.php                       # canonical application route registry
│
├── app/
│   ├── Controllers/                  # HTTP/application actions
│   ├── Models/                       # DB models
│   ├── Services/                     # CLI/support services
│   └── Views/                        # templates
│
├── db/
│   ├── migrations/                   # forward-only incremental DB changes
│   ├── schema.sqlite.sql             # first-run SQLite seed schema
│   └── schema.mysql.sql              # MySQL sample schema
│
├── storage/
│   ├── database.sqlite               # runtime SQLite DB
│   ├── backups/                      # SQLite backups
│   └── uploads/                      # private uploaded files
│
├── assets/
│   ├── css/app.css
│   └── js/                           # browser-side behavior
│
├── test/                             # ad-hoc security/database tests
└── bootstrap-5.0.2-examples/         # vendored Bootstrap examples; not application logic
```

### Active vs legacy files

The repository contains several `KiteConsole - Copy*.php`, `DashboardController - Copy.php`, and `database - Copy.js` variants. They are **not part of the canonical execution path** unless a route/import explicitly references them.

Canonical files currently used by the application include:

- `app/Services/KiteConsole.php`
- `app/Services/DatabaseCli.php`
- `app/Controllers/DashboardController.php`
- `assets/js/database.js`

---

## 3. Runtime Configuration

Source: `config/config.php`.

### Application

```php
app.name          // KitePHP
app.debug         // false in current source
app.timezone      // Asia/Manila
app.pretty_urls   // true
app.csrf          // true
app.csrf_except   // []
```

Pretty URLs:

```text
true  → /posts
false → /index.php?route=/posts
```

### Database

Supported drivers:

```text
sqlite
mysql
```

SQLite default:

```text
storage/database.sqlite
```

First creation loads:

```text
db/schema.sqlite.sql
```

Then automatic migrations run when `db.auto_migrate` is enabled.

MySQL configuration is read from:

```text
db.host
db.port
db.name
db.user
db.pass
```

### Authentication

```text
auth.model        → App\Models\User
auth.role_model   → App\Models\Role
auth.default_role → user
auth.home         → /dashboard
```

### Upload configuration

Default storage:

```text
storage/uploads
```

Current source settings:

```text
max_size_mb = 50
max_files   = 10
```

Allowed extensions:

```text
jpg jpeg png gif webp
pdf
xlsx xls csv ods
docx doc odt
pptx ppt
txt md
```

The effective file-size limit is the smaller of the application setting and PHP's `upload_max_filesize` / `post_max_size`.

---

## 4. Request Lifecycle

For a normal web request:

```text
HTTP request
  ↓
index.php
  ↓
define BASE_PATH
  ↓
core/bootstrap.php
  ↓
Core\App::__construct()
  ├── loads config
  ├── sets timezone
  ├── configures debug error display
  └── starts Session
  ↓
Core\App::run()
  ├── new Request()
  ├── new Router()
  ├── require routes/web.php
  └── Router::dispatch()
       ├── match path
       ├── match HTTP method
       ├── set route parameters
       └── Router::run()
            ├── upload-size check
            ├── CSRF check
            ├── middleware
            ├── controller/service handler
            └── normalize result to Response
  ↓
Response::send()
```

Global errors are converted to:

- JSON for API/AJAX-like requests
- `app/Views/errors/error.php` for HTML requests

Validation failures use `ValidationException`, flash errors/old input, and redirect back.

---

## 5. Routing Contract

Canonical route source:

```text
routes/web.php
```

Supported registration methods:

```php
$router->get()
$router->post()
$router->put()
$router->patch()
$router->delete()
$router->any()
$router->group()
```

Handlers may be:

```php
'Controller@method'
[ClassName::class, 'method']
closure
```

`Controller@method` is automatically resolved to:

```text
App\Controllers\Controller
```

Route parameters use:

```text
/posts/{id}
/hello/{name}
```

They are passed to the controller action and are also available through:

```php
$this->request->param('id')
```

### Middleware

Built-in:

```php
M::auth()
M::guest()
M::registration()
M::role('admin')
M::can('posts.edit')
```

Middleware returns:

```text
null      → continue
Response  → stop request
```

### CSRF

The router checks POST/PUT/PATCH/DELETE automatically when `app.csrf=true`.

Accepted token locations:

```text
_token
X-CSRF-TOKEN
```

Forms normally use:

```php
<?= csrf_field() ?>
```

HTTP method spoofing is supported from POST through:

```text
_method
X-HTTP-METHOD-OVERRIDE
```

---

## 6. Current Route Inventory

### Public

| Method | Path | Handler | Access |
|---|---|---|---|
| GET | `/` | `HomeController@index` | public |
| GET | `/about` | `HomeController@about` | public |
| GET | `/guide/{id}` | `PostController@guide` | public |
| GET | `/blog` | `BlogController@index` | public |
| GET | `/test` | `TestController@index` | public |
| GET | `/pugak` | `PugakController@index` | public |
| GET | `/uten` | `UtenController@index` | public |
| GET | `/hello/{name}` | closure | public |

### Authentication

| Method | Path | Handler | Access |
|---|---|---|---|
| GET | `/login` | `AuthController@showLogin` | guest |
| POST | `/login` | `AuthController@login` | guest |
| GET | `/register` | `AuthController@showRegister` | guest + registration-open |
| POST | `/register` | `AuthController@register` | guest + registration-open |
| POST | `/logout` | `AuthController@logout` | authenticated |

Registration is open in debug mode and, in production, only while there are no users; after the first account is created, normal production registration closes.

### Authenticated dashboard

| Method | Path | Handler | Access |
|---|---|---|---|
| GET | `/dashboard` | `DashboardController@index` | login required |

### User administration

All `/admin` user routes require:

```text
users.manage
```

Routes:

```text
GET    /admin/users
GET    /admin/users/create
POST   /admin/users
GET    /admin/users/{id}/edit
PUT    /admin/users/{id}
POST   /admin/users/{id}/role
DELETE /admin/users/{id}
```

### Roles

All `/admin/roles` routes require:

```text
roles.manage
```

Routes:

```text
GET    /admin/roles
GET    /admin/roles/create
POST   /admin/roles
PUT    /admin/roles/permissions
DELETE /admin/roles/{id}
```

### Posts

```text
GET    /posts
GET    /posts/create
POST   /posts
GET    /posts/{id}
GET    /posts/{id}/edit
PUT    /posts/{id}
DELETE /posts/{id}
```

Permissions:

```text
posts.view
posts.create
posts.edit
posts.delete
```

### Files

```text
GET    /files
POST   /files
GET    /files/{id}/view
GET    /files/{id}/download
DELETE /files/{id}
```

Permissions:

```text
files.view
files.upload
files.delete
```

### JSON posts API

```text
GET  /api/posts
POST /api/posts             # posts.create required
```

Uses the same login session and CSRF system.

### Database manager

All database-manager endpoints require:

```text
database.manage
```

```text
GET    /database
GET    /database/api/tables
POST   /database/api/tables
GET    /database/api/tables/{table}
DELETE /database/api/tables/{table}
POST   /database/api/tables/{table}/columns
POST   /database/api/tables/{table}/empty
GET    /database/api/tables/{table}/rows
POST   /database/api/tables/{table}/rows
PUT    /database/api/tables/{table}/rows
DELETE /database/api/tables/{table}/rows
POST   /database/api/sql
POST   /database/api/cli
```

### UsersLog / experimental application routes

These routes exist without permission middleware:

```text
GET    /UsersLog
GET    /UsersLog/create
POST   /UsersLog
GET    /UsersLog/{id}
GET    /UsersLog/{id}/edit
PUT    /UsersLog/{id}
DELETE /UsersLog/{id}

GET    /users_log_view
```

`UsersLogView` is read-only and maps to a SQL view.

---

## 7. Controller Map

| Controller | Main responsibility |
|---|---|
| `HomeController` | Home/about pages |
| `AuthController` | Login, registration, logout |
| `DashboardController` | Authenticated dashboard |
| `AdminController` | User CRUD, role assignment, self-protection |
| `RoleController` | Role creation/deletion and permission matrix |
| `PostController` | Post CRUD, guide/finish behavior |
| `FileController` | Upload library, secure file serving, delete |
| `ApiController` | JSON post API |
| `DatabaseController` | Browser DB manager and command console bridge |
| `BlogController` | Blog page |
| `UsersLogController` | UsersLog CRUD |
| `UsersLogViewController` | Read-only UsersLog view |
| `UsersLogSummaryController` | Controller exists but is not registered in `routes/web.php` |
| `PugakController` | Pugak page |
| `UtenController` | Uten page |
| `TestController` | Test page |

`DashboardController - Copy.php` is a duplicate/legacy variant and is not the canonical route target.

---

## 8. Model / Data Layer

### `Core\Model`

Lightweight Active-Record-like base class.

Capabilities:

```php
all()
where()
first()
find()
findOrFail()
count()
paginate()
create()
fill()
update()
save()
delete()
toArray()
jsonSerialize()
query()
```

Conventions:

```php
protected static $table = 'table_name';
protected static $primaryKey = 'id';
protected static $fillable = [];
protected static $timestamps = true;
protected static $hidden = [];
```

Models with timestamps expect:

```text
created_at
updated_at
```

Queries use PDO prepared statements for values. Column/order identifiers are sanitized by the base model.

### Current models

| Model | Table/View | Notes |
|---|---|---|
| `User` | `users` | password hidden; role assigned explicitly |
| `Role` | `roles` | permissions stored in `role_permissions` |
| `Post` | `posts` | title/body |
| `Attachment` | `attachments` | links DB metadata to stored upload |
| `UsersLog` | `UsersLog` | activity log entity |
| `UsersLogView` | `UsersLogView` | read-only SQL view |
| `DatabaseManager` | dynamic | low-level DB administration service/model |

---

## 9. Database Architecture

`Core\Database` owns the PDO connection.

Supported:

```text
SQLite
MySQL
```

SQLite enables:

```sql
PRAGMA foreign_keys = ON
```

Migration behavior:

1. Discover `db/migrations/*.php`.
2. Sort filenames.
3. Create `migrations` table.
4. Skip migrations already recorded.
5. `require` each pending migration.
6. Execute its returned callback.
7. Record `name` + `ran_at`.

Migration signature:

```php
return function (Core\Database $db, string $driver) {
    // schema/data change
};
```

### Migration sequence

```text
001_create_users.php
002_create_roles.php
003_create_attachments.php
```

### Tables created by the tracked migrations

```text
users
roles
role_permissions
attachments
migrations
```

### Base schema

`db/schema.sqlite.sql` and `db/schema.mysql.sql` create/seed:

```text
posts
```

### Runtime database note

The supplied runtime `storage/database.sqlite` currently also contains:

```text
UsersLog
UsersLogView
```

These objects are **not created by the tracked migrations in this source snapshot**. Therefore the UsersLog feature is not fully reproducible from `db/migrations/` alone. If that feature is intended to be permanent, add a proper forward migration rather than relying on a manually modified SQLite file.

---

## 10. Current Data Model

```text
users
 ├── id PK
 ├── name
 ├── email UNIQUE
 ├── password
 ├── role
 ├── created_at
 └── updated_at

roles
 ├── id PK
 ├── name UNIQUE
 ├── label
 ├── is_locked
 ├── created_at
 └── updated_at

role_permissions
 ├── role_id
 └── permission
 PK(role_id, permission)

posts
 ├── id PK
 ├── title
 ├── body
 ├── created_at
 └── updated_at

attachments
 ├── id PK
 ├── post_id NULL
 ├── user_id NULL
 ├── original_name
 ├── stored_name UNIQUE
 ├── mime
 ├── ext
 ├── size
 ├── kind
 ├── created_at
 └── updated_at

UsersLog
 ├── id PK
 ├── UserID
 ├── Activity
 ├── created_at
 └── updated_at

UsersLogView
 ├── log_id
 ├── name
 ├── email
 ├── Activity
 ├── created_at
 └── updated_at
```

Logical relationships:

```text
users.role
   └── matches roles.name

roles.id
   └── role_permissions.role_id

posts.id
   └── attachments.post_id (nullable)

users.id
   └── attachments.user_id (nullable)

UsersLog.UserID
   └── users.id

UsersLogView
   └── UsersLog JOIN users
```

Note: `users.role` is a string reference to `roles.name`, not a declared SQL foreign key.

---

## 11. Authentication + RBAC

### Authentication flow

`AuthController` uses:

```text
Core\Auth
Core\Session
App\Models\User
```

Login:

```text
email/password
  ↓
User lookup by normalized email
  ↓
password_verify()
  ↓
session_regenerate_id(true)
  ↓
store user ID in session
  ↓
fresh CSRF token
```

Password rehashing is performed when `password_needs_rehash()` is true.

Logout removes the user ID and CSRF token and regenerates the session ID.

### RBAC

Permission catalog is defined in:

```text
config/config.php
```

Current permission groups:

```text
Posts
  posts.create
  posts.view
  posts.edit
  posts.delete

Database
  database.manage

Files
  files.view
  files.upload
  files.delete

Administration
  users.manage
  roles.manage
```

Roles are seeded once from config into the DB. After seeding, the browser Roles page is the operational source for role permissions.

Current starting roles:

```text
admin  → *
editor → posts.create, posts.edit, files.view, files.upload
user   → posts.create, files.view, files.upload
viewer → posts.view, files.view
```

A role containing `*` becomes locked/full-access.

### Authorization API

```php
Auth::check()
Auth::user()
Auth::id()
Auth::role()
Auth::hasRole(...)
Auth::can('permission')
Auth::isSuper()
Auth::permissionList()
```

Route authorization:

```php
M::can('permission')
M::role('role')
```

Controller authorization:

```php
$this->authorize('permission')
```

View helpers:

```php
can('permission')
has_role('role')
auth_user()
```

### User safety rules

`AdminController` prevents:

- changing your own role
- deleting your own account
- duplicate email addresses

Locked roles cannot be deleted. Roles with users cannot be deleted.

---

## 12. Views / Rendering

`Core\View` maps:

```text
$this->view('pages/home')
```

to:

```text
app/Views/pages/home.php
```

Default layout:

```text
app/Views/layouts/main.php
```

Auth layout:

```text
app/Views/layouts/auth.php
```

Rendering sequence:

```text
feature view
   ↓
captured as $content
   ↓
layout receives $content
   ↓
HTML Response
```

Current view areas:

```text
admin/
auth/
dashboard/
Database/
errors/
files/
layouts/
pages/
partials/
posts/
UsersLog/
users_log_view/
```

Important helpers include:

```php
e()
url()
asset()
csrf_field()
csrf_token()
method_field()
old()
error()
invalid()
flash()
partial()
add_css()
add_js()
nav_active()
config()
auth_user()
can()
has_role()
```

Normal user-controlled/database values should be rendered with:

```php
<?= e($value) ?>
```

---

## 13. Posts + Markdown

Posts store raw:

```text
title
body
```

The browser-side Markdown renderer is:

```text
assets/js/markdown.js
```

The project documents Markdown + HTML support with client-side:

```text
marked
DOMPurify
highlight.js
```

The same rendering path is used for preview/read display.

Supported documented content includes:

- headings
- bold/italic/strike
- links/images
- lists
- blockquotes
- tables
- inline/fenced code
- selected HTML/Bootstrap markup

Dangerous HTML such as scripts, event handlers, embeds, forms, styles, and `javascript:` links is stripped by the documented rendering pipeline.

Post editor support includes:

```text
formatting toolbar
live preview
load .md/.markdown/.txt
insert attachments
code highlighting
```

---

## 14. File Upload Architecture

Upload service:

```text
Core\Uploader
```

Metadata model:

```text
App\Models\Attachment
```

Storage:

```text
storage/uploads/
```

Files are stored with random names and served through `FileController`, rather than exposed as direct public paths.

Validation includes:

```text
extension allow-list
server-measured file size
is_uploaded_file()
content/signature checks
random stored filename
safe original filename
```

Content checks include:

```text
images → getimagesize()
PDF → %PDF- signature
Office ZIP formats → PK signature
old Office OLE → OLE signature
text → reject NUL bytes
```

Library files are protected by `files.view`.

Post attachments are intended to be public with the post.

Attachment deletion removes:

```text
physical file
+
database metadata
```

Previewable browser types are defined in `Uploader` and include images, PDF, spreadsheets, DOCX, TXT, and Markdown.

---

## 15. Database Manager

`DatabaseController` + `DatabaseManager` implement a browser database administration UI.

Capabilities:

```text
list tables
inspect schema
create table
add column
drop table
empty table
list/search rows
insert row
update row
delete row
run SQL
run KitePHP CLI commands
```

All database-manager routes require:

```text
database.manage
```

The browser console delegates:

```text
database commands → DatabaseCli
make:* commands  → KiteConsole
```

This is a privileged feature. Do not expose `database.manage` to untrusted roles.

`DatabaseManager` contains identifier/secret-aware handling intended to reduce accidental exposure of sensitive columns in the UI. Review it before extending SQL execution features.

---

## 16. CLI

### `database.php`

Commands:

```bash
php database.php status
php database.php migrate
php database.php backup
php database.php backup --label before-feature
php database.php verify
php database.php test
```

Purpose:

```text
status  → migration state
migrate → apply pending migrations
backup  → SQLite backup
verify  → SQLite integrity checks
test    → migration-file checks
```

There is intentionally no rollback command because the migration format has no `down()` operation.

### `kite.php`

Primary generator:

```bash
php kite.php make:controller ExampleController
php kite.php make:controller ExampleController --force
```

The active `KiteConsole.php` also contains generator logic for models/views/resources; confirm `availableCommands()` before documenting or relying on a specific generator command.

---

## 17. Browser JavaScript

Important active assets:

```text
assets/js/app.js
assets/js/auth.js
assets/js/database.js
assets/js/file-preview.js
assets/js/markdown.js
assets/js/post-editor.js
```

Responsibilities:

| File | Purpose |
|---|---|
| `app.js` | global browser helpers including CSRF-aware requests |
| `auth.js` | authentication-page UI helpers |
| `database.js` | browser database manager |
| `file-preview.js` | attachment preview UI |
| `markdown.js` | Markdown rendering/sanitization/highlighting integration |
| `post-editor.js` | post editor behavior |

`assets/js/database - Copy.js` is a legacy duplicate.

---

## 18. Security Invariants

These are architectural constraints, not optional style choices:

```text
CSRF enabled by default
session ID regenerated at login/logout
passwords use password_hash/password_verify
password field hidden from User JSON
route middleware enforces authentication/permissions
uploaded files stored outside direct public serving
uploaded content checked against extension
stored upload names are random
normal output should use e()
debug details disabled when debug=false
```

Additional operational protections:

```text
storage/.htaccess
config/.htaccess
app/.htaccess
core/.htaccess
routes/.htaccess
db/.htaccess
```

Do not remove these protections without replacing their purpose.

---

## 19. Shared-Hosting Compatibility Rules

The codebase is intentionally suitable for environments such as InfinityFree.

Preserve these behaviors:

### JSON request compatibility

`Request` attempts to parse JSON from `php://input` even if `Content-Type` is not `application/json`.

This supports restrictive shared-host/WAF environments.

### Method override

Clients can send:

```text
POST + _method=PUT
POST + _method=DELETE
```

or:

```text
X-HTTP-METHOD-OVERRIDE
```

### No Composer dependency

The project uses a small custom PSR-4-style autoloader instead of Composer.

### Pretty URL fallback

If Apache rewrite is unavailable:

```text
/index.php?route=/posts
```

remains supported.

---

## 20. Feature-to-File Map

### Add a normal page

```text
routes/web.php
  ↓
app/Controllers/XController.php
  ↓
app/Views/x/index.php
```

### Add a database feature

```text
db/migrations/00N_create_x.php
  ↓
app/Models/X.php
  ↓
app/Controllers/XController.php
  ↓
routes/web.php
  ↓
app/Views/x/
```

### Add authorization

```text
config/config.php
  ↓
permission catalog
  ↓
routes/web.php → M::can('x.action')
  ↓
/admin/roles → assign permission
```

### Add upload support

```text
config/config.php → uploads
  ↓
Core\Uploader
  ↓
Attachment model
  ↓
FileController
  ↓
storage/uploads/
```

---

## 21. Recommended Feature Development Sequence

For a new feature:

```text
1. Define data model / relationships
2. Add forward migration
3. Add model
4. Add controller/service
5. Add route(s)
6. Add permissions if needed
7. Add views
8. Add JS/CSS only where needed
9. Add navigation
10. Test public/auth/unauthorized/error paths
11. Update documentation
```

For CRUD:

```text
index
create
store
show
edit
update
destroy
```

Use validation before writes and authorization before protected actions.

---

## 22. Known Repository Caveats

These are important for AI agents because they prevent false assumptions:

1. **README naming mismatch:** the repository contains `ViewReadme.md`, while older `README.md` text may refer to `README-VIEWS.md`. Use the actual `ViewReadme.md` file unless a rename is intentionally made.
2. **UsersLog schema mismatch:** runtime SQLite contains `UsersLog` and `UsersLogView`, but there is no tracked migration creating them in this snapshot.
3. **Duplicate files exist:** several `Copy` files are historical variants; do not modify them merely because their names appear in the tree.
4. **`UsersLogSummaryController` is not registered in `routes/web.php`.**
5. **`posts` is created by the base schema, while users/roles/attachments are migration-managed.** Do not assume every table has a migration.
6. **Role assignment is string-based:** `users.role` matches `roles.name`; it is not a SQL FK.
7. **`attachments.post_id` and `attachments.user_id` are nullable integer references without declared foreign keys in the migration.**
8. **`storage/database.sqlite` is runtime state, not the authoritative schema source.** For reproducible structure, inspect schema + migrations.
9. **MySQL support exists in the core DB layer and migrations, but the supplied MySQL schema is only the base `posts` schema; migrations are the intended mechanism for the rest.**
10. **The project is framework/application hybrid.** Changes to `core/` can affect every application feature.

---

## 23. AI Change Checklist

Before generating code:

```text
[ ] Identify exact feature and current route
[ ] Identify controller
[ ] Identify model/table
[ ] Identify view(s)
[ ] Identify JS/CSS dependencies
[ ] Identify permission
[ ] Identify migration state
[ ] Check whether a similarly named Copy file is inactive
[ ] Preserve existing shared-host workarounds
```

After generating code:

```text
[ ] Namespace matches directory
[ ] Filename matches class
[ ] Route method/path/handler match
[ ] Middleware is applied to protected operations
[ ] CSRF is present on state-changing forms/API requests
[ ] Inputs are validated
[ ] Outputs are escaped where appropriate
[ ] Database writes use existing Model/Database conventions
[ ] New schema uses a new migration
[ ] Existing migration files are not rewritten
[ ] Links use url()
[ ] Assets use asset()
[ ] Debug information is not exposed
[ ] Documentation reflects the new feature
```

---

## 24. Minimal Mental Model

```text
URL
 ↓
routes/web.php
 ↓
middleware
 ↓
Controller
 ↓
Model / Service
 ↓
Database
 ↓
Controller prepares data
 ↓
View + Layout
 ↓
Response
 ↓
Browser
```

For an API:

```text
URL
 ↓
route + middleware
 ↓
Controller
 ↓
Model / Service
 ↓
Response::json()
```

For a form write:

```text
POST/PUT/DELETE
 ↓
method override (if used)
 ↓
CSRF
 ↓
authorization middleware
 ↓
validation
 ↓
model/service write
 ↓
flash + redirect
```

---

## 25. Source-of-Truth Priority

When documentation conflicts with code, an AI agent should resolve facts in this order:

```text
1. Active source code referenced by routes/bootstrap
2. Database migrations/schema
3. Active configuration
4. Active views/assets
5. This KitePHP-README.md
6. Other README/tutorial documents
7. Copy/legacy files
```

When runtime SQLite differs from source migrations:

```text
source migrations + schema = reproducible design
runtime database            = current deployed state
```

Do not silently treat runtime-only objects as reproducible features.

---

## 26. Primary Files to Read First

For almost any task, inspect these first:

```text
index.php
core/bootstrap.php
core/App.php
core/Router.php
core/Request.php
core/Response.php
core/Controller.php
core/Model.php
core/Database.php
core/Auth.php
core/Middleware.php
core/helpers.php
config/config.php
routes/web.php
```

Then inspect the target feature:

```text
app/Controllers/<Target>.php
app/Models/<Target>.php
app/Views/<target>/
assets/js/<target>.js
db/migrations/
```

---

## 27. Project Status Snapshot

This documentation describes the repository snapshot supplied for analysis.

The project is functional as a compact MVC framework/application, with:

- front-controller routing
- MVC-style controllers/models/views
- SQLite/MySQL database layer
- automatic migrations
- authentication
- database-backed RBAC
- CRUD posts
- Markdown rendering
- private file library + public post attachments
- browser database manager
- CLI database utilities
- code-generation utilities
- shared-host compatibility measures

The largest maintenance concern is **documentation/source drift** around legacy duplicate files and the runtime-only `UsersLog` database objects. Treat those as explicit areas to reconcile before a major refactor.
