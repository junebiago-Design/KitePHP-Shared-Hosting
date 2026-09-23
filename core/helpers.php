<?php
use Core\App;
use Core\Auth;
use Core\Request;
use Core\Session;
use Core\View;

function config(string $key, $default = null)
{
    $value = App::$config;
    foreach (explode('.', $key) as $part) {
        if (!is_array($value) || !array_key_exists($part, $value)) {
            return $default;
        }
        $value = $value[$part];
    }
    return $value;
}

function url(string $path = ''): string
{
    $base = Request::basePath();
    $path = ltrim($path, '/');
    if (config('app.pretty_urls')) {
        return $base . '/' . $path;
    }
    $parts = explode('?', $path, 2);
    $u = $base . '/index.php';
    if ($parts[0] !== '') {
        $u .= '?route=/' . $parts[0];
        if (isset($parts[1])) {
            $u .= '&' . $parts[1];
        }
    } elseif (isset($parts[1])) {
        $u .= '?' . $parts[1];
    }
    return $u;
}

function asset(string $path): string
{
    // Full URLs (CDNs) pass through untouched
    if (preg_match('#^(https?:)?//#', $path)) {
        return $path;
    }
    $path = ltrim($path, '/');
    $file = BASE_PATH . '/assets/' . $path;
    // ?v=<file modified time> busts the browser cache whenever you edit the file
    $version = is_file($file) ? '?v=' . filemtime($file) : '';
    return Request::basePath() . '/assets/' . $path . $version;
}

/** Internal store for per-page CSS/JS files: [type => [path => integrity]]. */
function asset_stack(string $type, ?string $path = null, string $integrity = ''): array
{
    static $stack = ['css' => [], 'js' => []];
    if ($path !== null && !isset($stack[$type][$path])) {
        $stack[$type][$path] = $integrity;
    }
    return $stack[$type];
}

/** Load a CSS file for this page only: add_css('css/x.css') or add_css($cdnUrl, 'sha384-...'). */
function add_css(string $path, string $integrity = ''): void
{
    asset_stack('css', $path, $integrity);
}

/** Load a JS file for this page only: add_js('js/x.js') or add_js($cdnUrl, 'sha384-...'). */
function add_js(string $path, string $integrity = ''): void
{
    asset_stack('js', $path, $integrity);
}

/** Used by the layout: prints <link> tags for everything passed to add_css(). */
function render_css(): string
{
    $out = '';
    foreach (asset_stack('css') as $path => $integrity) {
        $sri = $integrity !== '' ? ' integrity="' . e($integrity) . '" crossorigin="anonymous"' : '';
        $out .= '<link rel="stylesheet" href="' . e(asset($path)) . '"' . $sri . '>' . "\n";
    }
    return $out;
}

/** Used by the layout: prints <script> tags for everything passed to add_js(). */
function render_js(): string
{
    $out = '';
    foreach (asset_stack('js') as $path => $integrity) {
        $sri = $integrity !== '' ? ' integrity="' . e($integrity) . '" crossorigin="anonymous"' : '';
        $out .= '<script src="' . e(asset($path)) . '"' . $sri . '></script>' . "\n";
    }
    return $out;
}

function e($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function csrf_token(): string
{
    return Session::csrfToken();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_token" value="' . e(csrf_token()) . '">';
}

function method_field(string $method): string
{
    return '<input type="hidden" name="_method" value="' . e(strtoupper($method)) . '">';
}

function old(string $key, $default = '')
{
    $old = Session::getFlash('old', []);
    return $old[$key] ?? $default;
}

function error(string $field): ?string
{
    $errors = Session::getFlash('errors', []);
    return $errors[$field][0] ?? null;
}

function flash(string $key, $default = null)
{
    return Session::getFlash($key, $default);
}

function partial(string $view, array $data = []): void
{
    echo View::render($view, $data, null);
}

function auth_user()
{
    return Auth::user();
}

function can(string $permission): bool
{
    return Auth::can($permission);
}

function has_role(string ...$roles): bool
{
    return Auth::hasRole(...$roles);
}

/**
 * Is the public register page available?
 * - debug ON: yes (development convenience)
 * - debug OFF (production): only while there are NO users yet, so the first
 *   admin can be created. After that, admins add users from /admin/users.
 */
function registration_open(): bool
{
    if (config('app.debug')) {
        return true;
    }
    static $open = null;
    if ($open === null) {
        try {
            $class = config('auth.model');
            $open = $class::count() === 0;
        } catch (\Throwable $e) {
            $open = false;
        }
    }
    return $open;
}

// ---------------------------------------------------------------------------
// Bootstrap 5.3 (loaded from the jsDelivr CDN with integrity hashes).
// To upgrade: change the version below and copy the new hashes from
// https://getbootstrap.com/docs/5.3/getting-started/introduction/
// ---------------------------------------------------------------------------
function bootstrap_css(): string
{
    return '<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/css/bootstrap.min.css" rel="stylesheet"'
        . ' integrity="sha384-LN+7fdVzj6u52u30Kp6M/trliBMCMKTyK833zpbD+pXdCLuTusPj697FH4R/5mcr" crossorigin="anonymous">' . "\n";
}

function bootstrap_js(): string
{
    return '<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.7/dist/js/bootstrap.bundle.min.js"'
        . ' integrity="sha384-ndDqU0Gzau9qJ1lfW4pNLlhNTkCfHzAVBReH9diLvGRem5+R9g2FzA8ZGN954O5Q" crossorigin="anonymous"></script>' . "\n";
}

/** ' is-invalid' when the field has a validation error (use inside class="form-control..."). */
function invalid(string $field): string
{
    return error($field) ? ' is-invalid' : '';
}

function current_path(): string
{
    static $path = null;
    if ($path === null) {
        $path = (new Request())->path();
    }
    return $path;
}

/** ' active' when the current page is $path or below it (use inside class="nav-link..."). */
function nav_active(string $path): string
{
    $current = current_path();
    $on = $current === $path || ($path !== '/' && strpos($current, $path . '/') === 0);
    return $on ? ' active' : '';
}

/** A Bootstrap badge for a role name (returns HTML, already escaped). */
function role_badge(string $role): string
{
    $class = $role === 'admin' ? 'text-bg-primary' : ($role === 'editor' ? 'text-bg-info' : 'text-bg-secondary');
    return '<span class="badge ' . $class . '">' . e($role) . '</span>';
}

/** 1536 => "1.5 KB" */
function human_size(int $bytes): string
{
    $units = ['B', 'KB', 'MB', 'GB'];
    $n = (float) $bytes;
    $i = 0;
    while ($n >= 1024 && $i < 3) {
        $n /= 1024;
        $i++;
    }
    return ($i === 0 ? (string) (int) $n : number_format($n, $n >= 10 ? 0 : 1)) . ' ' . $units[$i];
}

/** Emoji icon for an attachment group (image, pdf, spreadsheet, document, presentation, text). */
function file_icon(string $group): string
{
    $icons = [
        'image' => '🖼️', 'pdf' => '📕', 'spreadsheet' => '📊',
        'document' => '📄', 'presentation' => '📽️', 'text' => '📝',
    ];
    return $icons[$group] ?? '📎';
}

/** Plain-text teaser from a Markdown/HTML post body (for lists). */
function excerpt(string $markdown, int $length = 140): string
{
    $t = preg_replace('/```.*?```/s', ' ', $markdown);      // code blocks
    $t = preg_replace('/!\[[^\]]*\]\([^)]*\)/', ' ', $t);   // images
    $t = preg_replace('/\[([^\]]*)\]\([^)]*\)/', '$1', $t);  // links -> their text
    $t = strip_tags((string) $t);
    $t = preg_replace('/[#>*_`~|]+/', ' ', (string) $t);
    $t = trim((string) preg_replace('/\s+/', ' ', (string) $t));
    return mb_strimwidth($t, 0, $length, '…');
}
