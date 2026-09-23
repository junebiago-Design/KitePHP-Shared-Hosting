# AGENTS.md — KitePHP Coding Context

## Mission
Modify KitePHP safely while preserving its MVC flow, RBAC, CSRF, migration, upload, and shared-host compatibility contracts.

## Canonical architecture
`index.php → core/bootstrap.php → Core\App → routes/web.php → Core\Router → middleware → App\Controllers → Models/Services → View/Response`

## Canonical source
- Routes: `routes/web.php`
- Config/permissions: `config/config.php`
- Framework: `core/`
- Application: `app/`
- Schema changes: `db/migrations/`
- Runtime DB: `storage/database.sqlite` (state, not schema authority)
- Browser assets: `assets/`

## Mandatory rules
- Add schema changes as a new numbered migration; do not rewrite applied migrations.
- Use `M::auth()`, `M::role()`, or `M::can()` for route protection.
- Keep CSRF enabled for state-changing requests.
- Use `e()` for normal HTML output.
- Use `url()` and `asset()` for generated URLs.
- Preserve POST method override and JSON-body compatibility.
- Do not expose `storage/`, `config/`, `core/`, `routes/`, `db/`, or `app/`.
- Do not treat `* Copy*.php` files as active unless referenced.
- Keep `debug=false` for production.
- Update `KitePHP-README.md` when architecture, routes, permissions, schema, or feature contracts change.

## Important known drift
- `ViewReadme.md` is the actual view guide; older README text may refer to `README-VIEWS.md`.
- Runtime SQLite contains `UsersLog` and `UsersLogView`, but no tracked migration creates them.
- `UsersLogSummaryController` exists but is not routed.
- `users.role` references `roles.name` logically, not through a SQL foreign key.
- `attachments.post_id` / `user_id` are nullable logical references without declared FK constraints.
- Duplicate `Copy` files are legacy/experimental variants.

## Before changing code
Read the relevant route, controller, model, view, config permissions, migration/schema, and JS/CSS. Verify the actual active file rather than guessing from filenames.

## After changing code
Check namespaces, route order, middleware, validation, CSRF, output escaping, DB conventions, migration numbering, and documentation.
