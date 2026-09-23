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
            require_once $this->baseDir . '/app/Models/DatabaseManager.php';
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

        $fillableColumns = array_values(array_filter(
            $columns,
            fn($c) => !in_array($c['name'], $skip, true)
        ));
        $fillable = array_column($fillableColumns, 'name');

        $lines = [];

        // ---- 4. Model ----

        if (file_exists($modelPath) && !$force) {
            $lines[] = "Skipped: app/Models/{$className}.php already exists (re-run with --force to overwrite).";
        } else {
            $modelStub = $this->modelStub($className, $table, $columns, $fillable);

            if (@file_put_contents($modelPath, $modelStub) === false) {
                return $this->fail("Error: could not write {$modelPath}. Check folder permissions.", $lines);
            }
            $lines[] = "Created: app/Models/{$className}.php";
        }

        $lines[] = "Table: {$table} (" . count($columns) . ' column(s))';
        $lines[] = 'Fillable: ' . ($fillable ? implode(', ', $fillable) : '(none — nothing besides the primary key/timestamps)');

        // ---- 5. Controller ----

        $controllerClass = "{$className}Controller";
        $controllersDir  = $this->baseDir . '/app/Controllers';
        $controllerPath  = "{$controllersDir}/{$controllerClass}.php";

        if (!is_dir($controllersDir)) {
            $lines[] = "Skipped controller: app/Controllers not found under {$this->baseDir}.";
        } elseif (file_exists($controllerPath) && !$force) {
            $lines[] = "Skipped: app/Controllers/{$controllerClass}.php already exists (re-run with --force to overwrite).";
        } else {
            $controllerStub = $this->controllerStub($className, $controllerClass, $table, $fillableColumns);

            if (@file_put_contents($controllerPath, $controllerStub) === false) {
                $lines[] = "Error: could not write {$controllerPath}. Check folder permissions.";
            } else {
                $lines[] = "Created: app/Controllers/{$controllerClass}.php";
            }
        }

        // ---- 6. Routes ----

        $routesFile = $this->baseDir . '/routes/web.php';

        if (!file_exists($routesFile)) {
            $lines[] = 'Skipped routes: routes/web.php not found.';
        } else {
            $lines[] = $this->insertResourceRoutes($routesFile, $table, $controllerClass);
        }

        // ---- 7. Index view ----

        $viewsDir  = $this->baseDir . '/app/Views/' . $table;
        $indexPath = $viewsDir . '/index.php';

        if (!is_dir($viewsDir) && !@mkdir($viewsDir, 0755, true) && !is_dir($viewsDir)) {
            $lines[] = "Skipped view: could not create app/Views/{$table}. Check folder permissions.";
        } elseif (file_exists($indexPath) && !$force) {
            $lines[] = "Skipped: app/Views/{$table}/index.php already exists (re-run with --force to overwrite).";
        } else {
            $indexStub = $this->indexViewStub($table, $fillableColumns);

            if (@file_put_contents($indexPath, $indexStub) === false) {
                $lines[] = "Error: could not write {$indexPath}. Check folder permissions.";
            } else {
                $lines[] = "Created: app/Views/{$table}/index.php";
            }
        }

        $lines[] = "Note: create.php, edit.php and show.php were not generated — add those under app/Views/{$table}/ when you're ready for forms.";

        return $this->ok($lines);
    }

    /**
     * Builds the Model file's contents.
     */
    protected function modelStub(string $className, string $table, array $columns, array $fillable): string
    {
        $fillableCode = $fillable
            ? "[\n        '" . implode("',\n        '", array_map([$this, 'quote'], $fillable)) . "',\n    ]"
            : '[]';

        $schemaComment = implode("\n", array_map(function ($c) {
            $bits = [$c['type']];
            if ($c['pk']) $bits[] = 'primary key';
            if ($c['notnull']) $bits[] = 'required';
            return " *   {$c['name']} (" . implode(', ', $bits) . ')';
        }, $columns));

        return <<<PHP
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
    }

    /**
     * Builds the Controller file's contents: full CRUD, following the same
     * shape as the README's own PostController example.
     */
    protected function controllerStub(string $className, string $controllerClass, string $table, array $fillableColumns): string
    {
        $human   = ucwords(str_replace('_', ' ', $this->singularize($table)));
        $humanPl = ucwords(str_replace('_', ' ', $table));
        $var     = lcfirst($className);

        $rules = array_map(
            fn($c) => "            '" . $this->quote($c['name']) . "' => '" . $this->guessRule($c) . "',",
            $fillableColumns
        );
        $rulesBlock = $rules ? implode("\n", $rules) : '            // no fillable columns to validate';

        return <<<PHP
<?php

namespace App\Controllers;

use App\Models\\{$className};
use Core\Controller;
use Core\Response;
use Core\Session;

class {$controllerClass} extends Controller
{
    public function index(): Response
    {
        return \$this->view('{$table}/index', [
            'title' => '{$humanPl}',
            '{$table}' => {$className}::all('id DESC'),
        ]);
    }

    public function create(): Response
    {
        return \$this->view('{$table}/create', [
            'title' => 'Create {$human}',
        ]);
    }

    public function store(): Response
    {
        \$data = \$this->validate([
{$rulesBlock}
        ]);

        {$className}::create(\$data);

        Session::flash('success', '{$human} created.');

        return \$this->redirect('/{$table}');
    }

    public function show(\$id): Response
    {
        \${$var} = {$className}::find(\$id);

        if (!\${$var}) {
            return \$this->redirect('/{$table}');
        }

        return \$this->view('{$table}/show', [
            'title' => '{$human} Details',
            '{$var}' => \${$var},
        ]);
    }

    public function edit(\$id): Response
    {
        \${$var} = {$className}::find(\$id);

        if (!\${$var}) {
            return \$this->redirect('/{$table}');
        }

        return \$this->view('{$table}/edit', [
            'title' => 'Edit {$human}',
            '{$var}' => \${$var},
        ]);
    }

    public function update(\$id): Response
    {
        \${$var} = {$className}::find(\$id);

        if (!\${$var}) {
            return \$this->redirect('/{$table}');
        }

        \$data = \$this->validate([
{$rulesBlock}
        ]);

        \${$var}->update(\$data);

        Session::flash('success', '{$human} updated.');

        return \$this->redirect('/{$table}');
    }

    public function destroy(\$id): Response
    {
        \${$var} = {$className}::find(\$id);

        if (\${$var}) {
            \${$var}->delete();
        }

        Session::flash('success', '{$human} deleted.');

        return \$this->redirect('/{$table}');
    }
}

PHP;
    }

    /**
     * A generator-only guess at a validate() rule string for one column.
     * Deliberately conservative — review these before relying on them.
     */
    protected function guessRule(array $column): string
    {
        $bits = [];

        if ($column['notnull']) {
            $bits[] = 'required';
        }

        if (preg_match('/^(VARCHAR|CHAR)\((\d+)\)/i', $column['type'], $m)) {
            $bits[] = 'max:' . $m[2];
        }

        if (strtolower($column['name']) === 'email') {
            $bits[] = 'email';
        } elseif (stripos($column['name'], 'password') !== false) {
            $bits[] = 'min:8';
        } elseif (strtoupper($column['type']) === 'INTEGER' || strtoupper($column['type']) === 'REAL') {
            $bits[] = 'numeric';
        }

        return implode('|', $bits);
    }

    /**
     * Builds the index view's contents: a card per row, using the first
     * fillable column as the heading and the rest as labeled fields.
     */
    protected function indexViewStub(string $table, array $fillableColumns): string
    {
        $human   = ucwords(str_replace('_', ' ', $this->singularize($table)));
        $humanPl = ucwords(str_replace('_', ' ', $table));
        $humanPlLower = str_replace('_', ' ', $table);
        $var     = lcfirst($this->studly($this->singularize($table)));

        $safeColumns = array_values(array_filter($fillableColumns, fn($c) => $this->isPhpIdentifier($c['name'])));

        $heading = $safeColumns[0]['name'] ?? 'id';
        $rest    = $safeColumns ? array_slice($safeColumns, 1) : [];

        $fields = implode("\n\n", array_map(function ($c) use ($var) {
            $label = ucwords(str_replace('_', ' ', $c['name']));
            return <<<HTML
            <p class="mb-1">
                <strong>{$label}:</strong>
                <?= e(\${$var}->{$c['name']}) ?>
            </p>
HTML;
        }, $rest));

        if ($fields === '') {
            $fields = '            <!-- no other fillable columns -->';
        }

        return <<<PHP
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h2 mb-0">{$humanPl}</h1>

    <a
        class="btn btn-primary"
        href="<?= url('/{$table}/create') ?>"
    >
        New {$human}
    </a>
</div>

<?php if (!\${$table}): ?>

    <div class="alert alert-secondary">
        No {$humanPlLower} found.
    </div>

<?php endif; ?>

<?php foreach (\${$table} as \${$var}): ?>

    <div class="card mb-3">
        <div class="card-body">

            <h2 class="h5">
                <?= e(\${$var}->{$heading}) ?>
            </h2>

{$fields}

            <a
                class="btn btn-sm btn-outline-primary"
                href="<?= url('/{$table}/' . \${$var}->id) ?>"
            >
                View
            </a>

            <a
                class="btn btn-sm btn-outline-secondary"
                href="<?= url('/{$table}/' . \${$var}->id . '/edit') ?>"
            >
                Edit
            </a>

        </div>
    </div>

<?php endforeach; ?>

PHP;
    }

    /**
     * Inserts the seven resourceful routes for a scaffolded table, using the
     * same marker-or-append strategy as make:controller. Returns one status
     * line for the output, rather than failing the whole command.
     */
    protected function insertResourceRoutes(string $routesFile, string $table, string $controllerClass): string
    {
        $indexRoute = "\$router->get('/{$table}', '{$controllerClass}@index');";

        $routesContent = file_get_contents($routesFile);

        if (strpos($routesContent, $indexRoute) !== false) {
            return 'Routes already exist in routes/web.php — skipped.';
        }

        $block = "// ---- {$controllerClass} ----\n"
            . $indexRoute . "\n"
            . "\$router->get('/{$table}/create', '{$controllerClass}@create');\n"
            . "\$router->post('/{$table}', '{$controllerClass}@store');\n"
            . "\$router->get('/{$table}/{id}', '{$controllerClass}@show');\n"
            . "\$router->get('/{$table}/{id}/edit', '{$controllerClass}@edit');\n"
            . "\$router->put('/{$table}/{id}', '{$controllerClass}@update');\n"
            . "\$router->delete('/{$table}/{id}', '{$controllerClass}@destroy');\n\n";

        $marker = '// Closure example';

        if (strpos($routesContent, $marker) !== false) {
            $routesContent = str_replace($marker, $block . $marker, $routesContent);
        } else {
            $routesContent = rtrim($routesContent) . "\n\n" . $block;
        }

        if (@file_put_contents($routesFile, $routesContent) === false) {
            return 'Error: could not update routes/web.php. Check file permissions.';
        }

        return "Routes added: GET/POST/PUT/DELETE /{$table}[...] -> {$controllerClass}";
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

    /**
     * True if a name is safe to write as a bare PHP property/variable token
     * (used before generating $obj->columnName in the view). Column names
     * come straight from PRAGMA table_info, not from the sanitized table-name
     * argument, so this is checked separately.
     */
    protected function isPhpIdentifier(string $name): bool
    {
        return (bool) preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name);
    }

    /**
     * Escapes a value for safe embedding inside a single-quoted PHP string
     * literal in generated code.
     */
    protected function quote(string $value): string
    {
        return str_replace(["\\", "'"], ["\\\\", "\\'"], $value);
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