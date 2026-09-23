<?php

namespace App\Services;

use App\Models\DatabaseManager;
use Throwable;

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
 *   make:controller {Name}          [--force]
 *   make:model      {table_name}    [--force]
 *
 * Add a new command by giving it its own protected method and a case in
 * runArgs() — both the CLI and the web console pick it up automatically.
 */
class KiteConsole
{
    protected const COMMANDS = ['make:controller', 'make:model'];

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

        if ($command === 'make:model') {
            return $this->makeModel($args);
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
    // make:model
    // ---------------------------------------------------------

    protected function makeModel(array $args): array
    {
        $force = in_array('--force', $args, true);
        $table = null;
        foreach ($args as $arg) {
            if ($arg !== '--force') {
                $table = $arg;
                break;
            }
        }

        if (!$table) {
            return $this->fail("Error: table name is required.\nUsage: make:model {table_name}");
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $table)) {
            return $this->fail("Error: \"{$table}\" is not a valid table name. Use letters, numbers and underscores.");
        }

        // ---- 1. Connect and confirm the table exists ----

        $dbConfig = $this->dbConfig();

        if ($dbConfig === null) {
            return $this->fail("Error: config/config.php not found under {$this->baseDir}.");
        }

        try {
            $manager = new DatabaseManager($dbConfig);
        } catch (Throwable $e) {
            return $this->fail('Error: could not connect to the database — ' . $e->getMessage());
        }

        if (!$manager->tableExists($table)) {
            $known = array_column($manager->listTables(), 'name');

            return $this->fail(
                "Error: table \"{$table}\" does not exist.\n"
                . ($known
                    ? 'Available tables: ' . implode(', ', $known)
                    : 'No tables exist yet — create one on the Database page first.')
            );
        }

        $columns = $manager->columns($table);

        if (!$columns) {
            return $this->fail("Error: \"{$table}\" has no columns to read.");
        }

        // ---- 2. Work out the class name ----

        $className = $this->studly($this->singularize($table));

        if ($className === '') {
            return $this->fail("Error: could not derive a class name from \"{$table}\".");
        }

        $modelsDir = $this->baseDir . '/app/Models';
        $modelPath = "{$modelsDir}/{$className}.php";

        if (file_exists($modelPath) && !$force) {
            return $this->fail("Error: app/Models/{$className}.php already exists. Re-run with --force to overwrite.");
        }

        if (!is_dir($modelsDir)) {
            return $this->fail("Error: app/Models not found under {$this->baseDir}.");
        }

        // ---- 3. Work out $fillable ----
        // Skip the primary key (auto-generated) and the two timestamp columns
        // the migrations in this README always name the same way.

        $skip = array_merge(
            array_column(array_filter($columns, fn($c) => $c['pk']), 'name'),
            ['created_at', 'updated_at']
        );

        $fillable = array_values(array_filter(
            array_column($columns, 'name'),
            fn($name) => !in_array($name, $skip, true)
        ));

        $fillableCode = $fillable
            ? "[\n        '" . implode("',\n        '", $fillable) . "',\n    ]"
            : '[]';

        $schemaComment = implode("\n", array_map(function ($c) {
            $bits = [$c['type']];
            if ($c['pk']) $bits[] = 'primary key';
            if ($c['notnull']) $bits[] = 'required';
            return " *   {$c['name']} (" . implode(', ', $bits) . ')';
        }, $columns));

        // ---- 4. Write the model ----

        $stub = <<<PHP
<?php

namespace App\Models;

use Core\Model;

/**
 * Table: {$table}
 * Columns:
{$schemaComment}
 */
class {$className} extends Model
{
    protected static \$table = '{$table}';

    protected static \$fillable = {$fillableCode};
}

PHP;

        if (@file_put_contents($modelPath, $stub) === false) {
            return $this->fail("Error: could not write {$modelPath}. Check folder permissions.");
        }

        $lines = [
            "Created: app/Models/{$className}.php",
            "Table: {$table} (" . count($columns) . ' column(s))',
            'Fillable: ' . ($fillable ? implode(', ', $fillable) : '(none — nothing besides the primary key/timestamps)'),
        ];

        return $this->ok($lines);
    }

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------

    /**
     * Reads the 'db' block straight out of config/config.php. Loaded this
     * way (not through the config() helper) because kite.php runs standalone
     * from a terminal, without the framework's normal bootstrap.
     */
    protected function dbConfig(): ?array
    {
        $configFile = $this->baseDir . '/config/config.php';

        if (!file_exists($configFile)) {
            return null;
        }

        $config = require $configFile;

        return (array) ($config['db'] ?? $config['database'] ?? []);
    }

    /**
     * A small, deliberately simple English singularizer — just enough for
     * ordinary table names (posts, categories, boxes, users).
     */
    protected function singularize(string $word): string
    {
        $lower = strtolower($word);

        if (strlen($lower) > 3 && substr($lower, -3) === 'ies') {
            return substr($word, 0, -3) . 'y';
        }
        if (preg_match('/(x|ch|sh|ss)es$/i', $word)) {
            return substr($word, 0, -2);
        }
        if (substr($lower, -1) === 's' && substr($lower, -2) !== 'ss') {
            return substr($word, 0, -1);
        }

        return $word;
    }

    /**
     * table_name → TableName. Handles underscored table names by
     * capitalizing each segment.
     */
    protected function studly(string $word): string
    {
        $parts = array_filter(explode('_', $word), fn($p) => $p !== '');

        return implode('', array_map('ucfirst', $parts));
    }

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