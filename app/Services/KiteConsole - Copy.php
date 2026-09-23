<?php

namespace App\Services;

/**
 * KiteConsole
 *
 * The engine behind both the `kite.php` CLI script and the in-browser console
 * on the Database page, so they run exactly the same code. Deliberately does
 * not shell out (no exec/shell_exec/proc_open) — every command here is plain
 * file I/O, which also means it works on hosts like InfinityFree that disable
 * those functions.
 *
 * Supported commands:
 *   make:controller {Name} [--force]
 *
 * Add a new command by giving it its own protected method and a case in
 * runArgs() — both the CLI and the web console pick it up automatically.
 */
class KiteConsole
{
    protected const COMMANDS = ['make:controller'];

    protected string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        // app/Services/KiteConsole.php → project root is two levels up.
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
    }

    public function availableCommands(): array
    {
        return self::COMMANDS;
    }

    /**
     * Parses one typed line ("make:controller Foo --force") and runs it.
     *
     * @return array{ok: bool, output: string}
     */
    public function run(string $line): array
    {
        return $this->runArgs($this->tokenize($line));
    }

    /**
     * Runs a command from an already-tokenized argument list — what kite.php
     * gets from $_SERVER['argv'] once the script name is shifted off.
     *
     * @return array{ok: bool, output: string}
     */
    public function runArgs(array $args): array
    {
        $command = array_shift($args);

        if ($command === null || $command === '') {
            return $this->fail('Type a command, e.g. make:controller Products');
        }

        if ($command === 'make:controller') {
            return $this->makeController($args);
        }

        return $this->fail(
            "Unknown command: {$command}\nAvailable: " . implode(', ', self::COMMANDS)
        );
    }

    // ---------------------------------------------------------
    // make:controller
    // ---------------------------------------------------------

    protected function makeController(array $args): array
    {
        $force   = in_array('--force', $args, true);
        $rawName = null;
        foreach ($args as $arg) {
            if ($arg !== '--force') {
                $rawName = $arg;
                break;
            }
        }

        if (!$rawName) {
            return $this->fail("Error: controller name is required.\nUsage: make:controller {ControllerName}");
        }

        $name = preg_replace('/Controller$/', '', $rawName);
        $name = preg_replace('/[^A-Za-z0-9_]/', '', $name);

        if ($name === '' || !preg_match('/^[A-Za-z_]/', $name)) {
            return $this->fail("Error: \"{$rawName}\" is not a valid controller name.");
        }

        $name      = ucfirst($name);
        $className = "{$name}Controller";

        $controllersDir = $this->baseDir . '/app/Controllers';
        $viewsDir       = $this->baseDir . '/app/Views/pages';
        $routesFile     = $this->baseDir . '/routes/web.php';

        if (!is_dir($controllersDir)) {
            return $this->fail("Error: app/Controllers not found under {$this->baseDir}.");
        }

        $controllerPath = "{$controllersDir}/{$className}.php";
        $viewPath       = "{$viewsDir}/{$name}.php";

        if (file_exists($controllerPath) && !$force) {
            return $this->fail("Error: app/Controllers/{$className}.php already exists. Re-run with --force to overwrite.");
        }
        if (file_exists($viewPath) && !$force) {
            return $this->fail("Error: app/Views/pages/{$name}.php already exists. Re-run with --force to overwrite.");
        }

        $lines = [];

        // ---- 1. Controller ----

        $stub = <<<PHP
<?php

namespace App\Controllers;

use Core\Controller;
use Core\Response;

class {$className} extends Controller
{
    public function index(): Response
    {
        return \$this->view('pages/{$name}', [
            'title' => '{$name}',
            'message' => 'Welcome to {$name}!',
        ]);
    }
}

PHP;

        if (@file_put_contents($controllerPath, $stub) === false) {
            return $this->fail("Error: could not write {$controllerPath}. Check folder permissions.");
        }
        $lines[] = "Created: app/Controllers/{$className}.php";

        // ---- 2. View ----

        if (!is_dir($viewsDir) && !@mkdir($viewsDir, 0755, true) && !is_dir($viewsDir)) {
            return $this->fail("Error: could not create {$viewsDir}. Check folder permissions.", $lines);
        }

        $viewStub = <<<PHP
<div class="container">
    <h1><?= e(\$title) ?></h1>

    <p><?= e(\$message) ?></p>
</div>

PHP;

        if (@file_put_contents($viewPath, $viewStub) === false) {
            return $this->fail("Error: could not write {$viewPath}. Check folder permissions.", $lines);
        }
        $lines[] = "Created: app/Views/pages/{$name}.php";

        // ---- 3. Route ----

        if (!file_exists($routesFile)) {
            $lines[] = 'Warning: routes/web.php not found — skipping route insertion.';
            return $this->ok($lines);
        }

        $slug      = strtolower($name);
        $routeLine = "\$router->get('/{$slug}', '{$className}@index');";

        $routesContent = file_get_contents($routesFile);

        if (strpos($routesContent, $routeLine) !== false) {
            $lines[] = 'Route already exists in routes/web.php — skipped.';
            return $this->ok($lines);
        }

        $block  = "// ---- {$name} ----\n{$routeLine}\n\n";
        $marker = '// Closure example';

        if (strpos($routesContent, $marker) !== false) {
            $routesContent = str_replace($marker, $block . $marker, $routesContent);
        } else {
            $routesContent = rtrim($routesContent) . "\n\n" . $block;
        }

        if (@file_put_contents($routesFile, $routesContent) === false) {
            return $this->fail('Error: could not update routes/web.php. Check file permissions.', $lines);
        }
        $lines[] = "Route added: GET /{$slug} -> {$className}@index";

        return $this->ok($lines);
    }

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------

    protected function ok(array $lines): array
    {
        return ['ok' => true, 'output' => implode("\n", $lines)];
    }

    protected function fail(string $message, array $priorLines = []): array
    {
        return ['ok' => false, 'output' => implode("\n", array_merge($priorLines, [$message]))];
    }

    /**
     * Splits a typed command line into tokens, honoring "quoted strings"
     * and 'single-quoted strings' for arguments that contain spaces.
     */
    protected function tokenize(string $line): array
    {
        $line = trim($line);
        if ($line === '') {
            return [];
        }

        preg_match_all('/"([^"]*)"|\'([^\']*)\'|(\S+)/', $line, $matches, PREG_SET_ORDER);

        $tokens = [];
        foreach ($matches as $m) {
            if (isset($m[1]) && $m[1] !== '') {
                $tokens[] = $m[1];
            } elseif (isset($m[2]) && $m[2] !== '') {
                $tokens[] = $m[2];
            } else {
                $tokens[] = $m[3] ?? '';
            }
        }

        return array_values(array_filter($tokens, fn($t) => $t !== ''));
    }
}
