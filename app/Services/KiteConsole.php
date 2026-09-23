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
 *   make:view       {ViewName} ["CREATE VIEW ... AS SELECT ..."] [--force]
 *
 * make:model {table} reads the table's real columns via DatabaseManager and
 * generates a full working CRUD slice for it, in the same style as this
 * project's own Post feature (Core\Controller, Session::flash, findOrFail,
 * \$this->validate(), csrf_field()/method_field(), Bootstrap 5 views):
 *   - app/Models/{Name}.php                 $fillable from the real columns
 *   - app/Controllers/{Name}Controller.php  index/create/store/show/edit/update/destroy
 *   - routes/web.php                        the matching 7 resourceful routes
 *   - app/Views/{table}/index.php           card list + pagination
 *   - app/Views/{table}/form.php            one form shared by create and edit
 *   - app/Views/{table}/show.php            single-record detail page
 * The generated code is a solid starting point, not final — review the
 * guessed validation rules and field types (guessRule/guessInputType) before
 * relying on them, especially for anything sensitive.
 *
 * make:view {ViewName} scaffolds a *read-only* slice for a SQL VIEW instead
 * of a table: checks the view exists (creating it from an optional quoted
 * CREATE VIEW statement if it doesn't), then generates a model with an
 * empty $fillable (SQLite views aren't writable without INSTEAD OF triggers,
 * so mass assignment is deliberately disabled), a controller with only
 * index(), one route, and a plain HTML <table> listing view — no
 * create/edit/delete anywhere, because there's nothing to write to.
 *
 * Add a new command by giving it its own protected method and a case in
 * runArgs() — both the CLI and the web console pick it up automatically.
 */
class KiteConsole
{
    protected const COMMANDS = ['make:controller', 'make:model', 'make:view'];

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

        if ($command === 'make:view') {
            return $this->makeView($args);
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

        // ---- 7. Views: index.php, form.php (create + edit), show.php ----

        $viewsDir = $this->baseDir . '/app/Views/' . $table;

        if (!is_dir($viewsDir) && !@mkdir($viewsDir, 0755, true) && !is_dir($viewsDir)) {
            $lines[] = "Skipped views: could not create app/Views/{$table}. Check folder permissions.";
            return $this->ok($lines);
        }

        $views = [
            'index' => fn() => $this->indexViewStub($table, $fillableColumns),
            'form'  => fn() => $this->formViewStub($table, $fillableColumns),
            'show'  => fn() => $this->showViewStub($table, $fillableColumns),
        ];

        foreach ($views as $name => $build) {
            $path = "{$viewsDir}/{$name}.php";

            if (file_exists($path) && !$force) {
                $lines[] = "Skipped: app/Views/{$table}/{$name}.php already exists (re-run with --force to overwrite).";
                continue;
            }

            if (@file_put_contents($path, $build()) === false) {
                $lines[] = "Error: could not write {$path}. Check folder permissions.";
                continue;
            }

            $lines[] = "Created: app/Views/{$table}/{$name}.php";
        }

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

        // Every fillable column needs an entry here, even with an empty rule
        // string — $this->validate(self::RULES) is what builds $data for both
        // create() and update(), so any column left out of RULES is silently
        // dropped from every save, no matter what the form submitted.
        $rules = array_map(
            fn($c) => "        '" . $this->quote($c['name']) . "' => '" . $this->guessRule($c) . "',",
            $fillableColumns
        );
        $rulesBlock = $rules ? implode("\n", $rules) : '        // no fillable columns need validation';

        // Password/secret-style columns (DatabaseManager's 'masked' flag): hashed on
        // save, and left unchanged when the field is submitted blank on the edit form.
        $secretFields = array_values(array_map(
            fn($c) => $c['name'],
            array_filter($fillableColumns, fn($c) => $this->isMaskedColumn($c))
        ));

        if ($secretFields) {
            $secretList = "'" . implode("', '", array_map([$this, 'quote'], $secretFields)) . "'";
            $secretConst = <<<PHP


    /**
     * Hashed on save; left unchanged on edit when submitted blank. A blank value
     * on CREATE is simply omitted — if the column is NOT NULL, the database needs
     * its own default, or the form should require it before you rely on this.
     */
    private const SECRET_FIELDS = [{$secretList}];

    private function hashSecrets(array \$data): array
    {
        foreach (self::SECRET_FIELDS as \$field) {
            if (!array_key_exists(\$field, \$data)) {
                continue;
            }
            if (\$data[\$field] === '') {
                unset(\$data[\$field]);
            } else {
                \$data[\$field] = password_hash(\$data[\$field], PASSWORD_DEFAULT);
            }
        }
        return \$data;
    }
PHP;
            $storeData  = '$this->hashSecrets($this->validate(self::RULES))';
            $updateData = '$this->hashSecrets($this->validate(self::RULES))';
        } else {
            $secretConst = '';
            $storeData  = '$this->validate(self::RULES)';
            $updateData = '$this->validate(self::RULES)';
        }

        return <<<PHP
<?php

namespace App\Controllers;

use App\Models\\{$className};
use Core\Controller;
use Core\Response;
use Core\Session;

/**
 * Generated by make:model {$table}. Follows the same shape as PostController:
 * findOrFail() for 404s, \$this->validate() for input, Session::flash() for
 * one-time messages, and a single form.php view shared by create and edit.
 */
class {$controllerClass} extends Controller
{
    private const RULES = [
{$rulesBlock}
    ];
{$secretConst}

    public function index(): Response
    {
        \$page = (int) \$this->request->query('page', 1);
        \$result = {$className}::paginate(10, \$page, [], 'id DESC');

        return \$this->view('{$table}/index', ['title' => '{$humanPl}'] + \$result);
    }

    public function create(): Response
    {
        return \$this->view('{$table}/form', [
            'title' => 'New {$human}',
            'item'  => null,
        ]);
    }

    public function store(): Response
    {
        \$item = {$className}::create({$storeData});

        Session::flash('success', '{$human} created.');

        return \$this->redirect('/{$table}/' . \$item->id);
    }

    public function show(\$id): Response
    {
        \$item = {$className}::findOrFail(\$id);

        return \$this->view('{$table}/show', [
            'title' => '{$human} details',
            'item'  => \$item,
        ]);
    }

    public function edit(\$id): Response
    {
        \$item = {$className}::findOrFail(\$id);

        return \$this->view('{$table}/form', [
            'title' => 'Edit {$human}',
            'item'  => \$item,
        ]);
    }

    public function update(\$id): Response
    {
        \$item = {$className}::findOrFail(\$id);
        \$item->update({$updateData});

        Session::flash('success', '{$human} updated.');

        return \$this->redirect('/{$table}/' . \$item->id);
    }

    public function destroy(\$id): Response
    {
        {$className}::findOrFail(\$id)->delete();

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
        if ($this->isBooleanColumn($column)) {
            return '';   // checkboxes: nothing to validate, see isBooleanColumn()
        }

        $masked = $this->isMaskedColumn($column);
        $bits = [];

        if ($column['notnull'] && !$masked) {
            $bits[] = 'required';   // masked columns stay optional: blank means "leave unchanged" on edit
        }

        if (preg_match('/^(VARCHAR|CHAR)\((\d+)\)/i', $column['type'], $m)) {
            $bits[] = 'max:' . $m[2];
        }

        if ($masked) {
            $bits[] = 'min:8';
        } elseif (strtolower($column['name']) === 'email') {
            $bits[] = 'email';
        } elseif (strtoupper($column['type']) === 'INTEGER' || strtoupper($column['type']) === 'REAL') {
            $bits[] = 'numeric';
        }

        return implode('|', $bits);
    }

    /**
     * True for password/secret-style columns. DatabaseManager::columns()
     * already flags these as 'masked' (it hides their values in the row
     * browser and hashes them on save) — trust that flag; fall back to the
     * same name pattern DatabaseManager::isSecret() uses if it's missing.
     */
    protected function isMaskedColumn(array $column): bool
    {
        if (array_key_exists('masked', $column)) {
            return (bool) $column['masked'];
        }

        return (bool) preg_match('/(password|passwd|secret|api_key|token)/i', $column['name']);
    }

    /**
     * True for columns that read better as a checkbox than a text field:
     * is_active, has_avatar, published, enabled, featured, archived...
     */
    protected function isBooleanColumn(array $column): bool
    {
        $name = strtolower($column['name']);
        $type = strtoupper($column['type']);

        if (preg_match('/^(is|has)_/', $name)) {
            return true;
        }
        if (in_array($name, ['active', 'enabled', 'published', 'featured', 'archived', 'verified'], true)) {
            return true;
        }

        return false;
    }

    /**
     * Picks an HTML input type/shape for a column, for the generated form.
     * Returns one of: checkbox, textarea, number, email, password, date,
     * datetime-local, text.
     */
    protected function guessInputType(array $column): string
    {
        if ($this->isBooleanColumn($column)) {
            return 'checkbox';
        }

        $name = strtolower($column['name']);
        $type = strtoupper($column['type']);

        if ($this->isMaskedColumn($column)) {
            return 'password';
        }
        if ($name === 'email') {
            return 'email';
        }
        if (preg_match('/(^|_)(date)$/', $name) || $type === 'DATE') {
            return 'date';
        }
        if (preg_match('/(^|_)(at|time)$/', $name) || in_array($type, ['DATETIME', 'TIMESTAMP'], true)) {
            return 'datetime-local';
        }
        if (in_array($type, ['INTEGER', 'REAL', 'NUMERIC', 'DECIMAL', 'FLOAT', 'DOUBLE'], true)) {
            return 'number';
        }
        if ($type === 'TEXT' || stripos($type, 'TEXT') !== false || in_array($name, ['body', 'description', 'content', 'notes', 'bio', 'summary'], true)) {
            return 'textarea';
        }

        return 'text';
    }

    /**
     * Builds app/Views/{table}/index.php: a Bootstrap card per row (same
     * shape as posts/index.php), using the first fillable column as the
     * heading and the rest as labeled fields, with pagination.
     */
    protected function indexViewStub(string $table, array $fillableColumns): string
    {
        $human        = ucwords(str_replace('_', ' ', $this->singularize($table)));
        $humanPl      = ucwords(str_replace('_', ' ', $table));
        $humanPlLower = strtolower($humanPl);

        $safeColumns = array_values(array_filter(
            $fillableColumns,
            fn($c) => $this->isPhpIdentifier($c['name']) && !$this->isMaskedColumn($c)
        ));
        $heading = $safeColumns[0]['name'] ?? 'id';
        $rest    = $safeColumns ? array_slice($safeColumns, 1) : [];

        $fields = implode("\n", array_map(function ($c) {
            $label = ucwords(str_replace('_', ' ', $c['name']));
            if ($this->isBooleanColumn($c)) {
                return <<<HTML
                <p class="card-text mb-1"><strong>{$label}:</strong> <?= \$item->{$c['name']} ? 'Yes' : 'No' ?></p>
HTML;
            }
            return <<<HTML
                <p class="card-text mb-1"><strong>{$label}:</strong> <?= e(excerpt((string) \$item->{$c['name']}, 120)) ?></p>
HTML;
        }, $rest));

        if ($fields === '') {
            $fields = '                <!-- no other fillable columns -->';
        }

        return <<<PHP
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h2 mb-0">{$humanPl}</h1>
    <a class="btn btn-primary" href="<?= url('/{$table}/create') ?>">New {$human}</a>
</div>

<?php if (!\$data): ?>
    <div class="alert alert-secondary">No {$humanPlLower} yet.</div>
<?php else: ?>
    <?php foreach (\$data as \$item): ?>
        <div class="card mb-3">
            <div class="card-body">
                <h2 class="h5 card-title">
                    <a class="text-decoration-none" href="<?= url('/{$table}/' . \$item->id) ?>"><?= e(\$item->{$heading}) ?></a>
                </h2>
{$fields}
                <div class="d-flex gap-2 mt-2">
                    <a class="btn btn-sm btn-outline-secondary" href="<?= url('/{$table}/' . \$item->id . '/edit') ?>">Edit</a>
                    <form method="post" action="<?= url('/{$table}/' . \$item->id) ?>" data-confirm="Delete this {$human}?">
                        <?= csrf_field() ?>
                        <?= method_field('DELETE') ?>
                        <button class="btn btn-sm btn-danger" type="submit">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    <?php endforeach; ?>
<?php endif; ?>

<?php if (\$pages > 1): ?>
    <nav aria-label="{$humanPl} pages">
        <ul class="pagination justify-content-center">
            <?php for (\$i = 1; \$i <= \$pages; \$i++): ?>
                <li class="page-item<?= \$i === \$page ? ' active' : '' ?>">
                    <a class="page-link" href="<?= url('/{$table}?page=' . \$i) ?>"<?= \$i === \$page ? ' aria-current="page"' : '' ?>><?= \$i ?></a>
                </li>
            <?php endfor; ?>
        </ul>
    </nav>
<?php endif; ?>

PHP;
    }

    /**
     * Builds app/Views/{table}/form.php: one form shared by create and edit
     * (same pattern as posts/form.php), with one field per fillable column.
     * Field types and the "leave blank to keep it" password behavior come
     * straight from the real table structure (DatabaseManager::columns()):
     * notnull, type, and the 'masked' flag for password/secret columns.
     */
    protected function formViewStub(string $table, array $fillableColumns): string
    {
        $human = ucwords(str_replace('_', ' ', $this->singularize($table)));

        $fields = implode("\n\n", array_map(function ($c) {
            $name    = $c['name'];
            $label   = ucwords(str_replace('_', ' ', $name));
            $kind    = $this->guessInputType($c);
            $masked  = $this->isMaskedColumn($c);
            $req     = $c['notnull'] && !$this->isBooleanColumn($c) && !$masked ? ' required' : '';
            // The column's own DEFAULT, used to pre-fill a new (not-yet-saved) record.
            $default = $this->quote((string) ($c['default'] ?? ''));

            if ($kind === 'checkbox') {
                return <<<HTML
            <div class="mb-3 form-check">
                <input type="hidden" name="{$name}" value="0">
                <input type="checkbox" class="form-check-input" id="{$name}" name="{$name}" value="1"
                       <?= old('{$name}', \$editing ? \$item->{$name} : '{$default}') ? 'checked' : '' ?>>
                <label class="form-check-label" for="{$name}">{$label}</label>
            </div>
HTML;
            }

            if ($kind === 'password') {
                // Never pre-filled from \$item — that would leak the password hash
                // into the page. old() still repopulates it after a failed submit.
                $hint = $masked
                    ? '<div class="form-text">' . ($req === '' ? 'Leave blank to keep the current value.' : 'Required.') . '</div>'
                    : '';
                return <<<HTML
            <div class="mb-3">
                <label for="{$name}" class="form-label">{$label}</label>
                <input type="password" class="form-control<?= invalid('{$name}') ?>" id="{$name}" name="{$name}"
                       value="<?= e(old('{$name}')) ?>" autocomplete="new-password"{$req}>
                {$hint}
                <?php if (\$err = error('{$name}')): ?><div class="invalid-feedback"><?= e(\$err) ?></div><?php endif; ?>
            </div>
HTML;
            }

            if ($kind === 'textarea') {
                return <<<HTML
            <div class="mb-3">
                <label for="{$name}" class="form-label">{$label}</label>
                <textarea class="form-control<?= invalid('{$name}') ?>" id="{$name}" name="{$name}" rows="6"{$req}><?= e(old('{$name}', \$editing ? \$item->{$name} : '{$default}')) ?></textarea>
                <?php if (\$err = error('{$name}')): ?><div class="invalid-feedback"><?= e(\$err) ?></div><?php endif; ?>
            </div>
HTML;
            }

            return <<<HTML
            <div class="mb-3">
                <label for="{$name}" class="form-label">{$label}</label>
                <input type="{$kind}" class="form-control<?= invalid('{$name}') ?>" id="{$name}" name="{$name}"
                       value="<?= e(old('{$name}', \$editing ? \$item->{$name} : '{$default}')) ?>"{$req}>
                <?php if (\$err = error('{$name}')): ?><div class="invalid-feedback"><?= e(\$err) ?></div><?php endif; ?>
            </div>
HTML;
        }, $fillableColumns));

        if ($fields === '') {
            $fields = '            <!-- no fillable columns -->';
        }

        return <<<PHP
<?php
\$editing = \$item !== null;
\$action = \$editing ? url('/{$table}/' . \$item->id) : url('/{$table}');
?>
<div class="row justify-content-center">
    <div class="col-lg-8">
        <h1 class="h3 mb-4"><?= \$editing ? 'Edit {$human}' : 'New {$human}' ?></h1>

        <form method="post" action="<?= \$action ?>">
            <?= csrf_field() ?>
            <?php if (\$editing): ?><?= method_field('PUT') ?><?php endif; ?>

{$fields}

            <div class="d-flex gap-2">
                <button class="btn btn-primary" type="submit"><?= \$editing ? 'Save changes' : 'Create {$human}' ?></button>
                <a class="btn btn-outline-secondary" href="<?= \$editing ? url('/{$table}/' . \$item->id) : url('/{$table}') ?>">Cancel</a>
            </div>
        </form>
    </div>
</div>

PHP;
    }

    /**
     * Builds app/Views/{table}/show.php: every fillable column as a labeled
     * field, plus Edit/Delete/Back buttons (same shape as posts/show.php).
     * Password/secret columns show as a fixed placeholder, never the real
     * (hashed) value.
     */
    protected function showViewStub(string $table, array $fillableColumns): string
    {
        $human = ucwords(str_replace('_', ' ', $this->singularize($table)));

        $safeColumns = array_values(array_filter($fillableColumns, fn($c) => $this->isPhpIdentifier($c['name'])));
        $heading     = $safeColumns[0]['name'] ?? null;
        $rest        = $heading !== null ? array_slice($safeColumns, 1) : $safeColumns;

        $fields = implode("\n\n", array_map(function ($c) {
            $label = ucwords(str_replace('_', ' ', $c['name']));
            if ($this->isMaskedColumn($c)) {
                return <<<HTML
    <p class="mb-2"><strong>{$label}:</strong> <span class="text-body-secondary">••••••••</span></p>
HTML;
            }
            if ($this->isBooleanColumn($c)) {
                return <<<HTML
    <p class="mb-2"><strong>{$label}:</strong> <?= \$item->{$c['name']} ? 'Yes' : 'No' ?></p>
HTML;
            }
            return <<<HTML
    <p class="mb-2"><strong>{$label}:</strong><br><?= nl2br(e((string) \$item->{$c['name']})) ?></p>
HTML;
        }, $rest));

        if ($fields === '') {
            $fields = '    <!-- no other fillable columns -->';
        }

        $titleLine = $heading !== null
            ? "    <h1 class=\"display-6\"><?= e(\$item->{$heading}) ?></h1>"
            : "    <h1 class=\"display-6\">{$human} #<?= (int) \$item->id ?></h1>";

        return <<<PHP
<article class="mb-4">
{$titleLine}
    <p class="text-body-secondary"><?= e(\$item->created_at ?? '') ?></p>

{$fields}
</article>

<hr>
<div class="d-flex flex-wrap gap-2">
    <a class="btn btn-outline-secondary" href="<?= url('/{$table}') ?>">← Back</a>
    <a class="btn btn-primary" href="<?= url('/{$table}/' . \$item->id . '/edit') ?>">Edit</a>
    <form method="post" action="<?= url('/{$table}/' . \$item->id) ?>" data-confirm="Delete this {$human}?">
        <?= csrf_field() ?>
        <?= method_field('DELETE') ?>
        <button class="btn btn-danger" type="submit">Delete</button>
    </form>
</div>

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
    // make:view
    // ---------------------------------------------------------

    protected function makeView(array $args): array
    {
        $force      = in_array('--force', $args, true);
        $positional = array_values(array_filter($args, fn($a) => $a !== '--force'));
        $viewName   = $positional[0] ?? null;
        $createSql  = $positional[1] ?? null;

        if (!$viewName) {
            return $this->fail(
                "Error: view name is required.\n"
                . 'Usage: make:view {ViewName} ["CREATE VIEW ... AS SELECT ..."] [--force]'
            );
        }

        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $viewName)) {
            return $this->fail("Error: \"{$viewName}\" is not a valid view name. Use letters, numbers and underscores.");
        }

        // ---- 1. Connect ----

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

        $lines = [];

        // ---- 2. Confirm the view exists, or create it from the supplied SQL ----

        if ($this->viewExists($manager, $viewName)) {
            $lines[] = "View already exists: {$viewName}";
        } elseif ($createSql === null) {
            $known = $this->listViews($manager);

            return $this->fail(
                "Error: view \"{$viewName}\" does not exist.\n"
                . ($known ? 'Available views: ' . implode(', ', $known) : 'No views exist yet.')
                . "\nPass the CREATE VIEW statement as a second (quoted) argument to create it, e.g.:\n"
                . "make:view {$viewName} \"CREATE VIEW `{$viewName}` AS SELECT ...\""
            );
        } else {
            if (stripos($createSql, 'create view') === false || stripos($createSql, $viewName) === false) {
                return $this->fail("Error: that doesn't look like a CREATE VIEW statement for \"{$viewName}\".");
            }

            try {
                $manager->runSql($createSql);
            } catch (Throwable $e) {
                return $this->fail('Error creating the view — ' . $e->getMessage());
            }

            if (!$this->viewExists($manager, $viewName)) {
                return $this->fail("Error: the statement ran but \"{$viewName}\" still isn't a view. Check the SQL.");
            }

            $lines[] = "Created view: {$viewName}";
        }

        // ---- 3. Read its columns ----
        // PRAGMA table_info works on views the same as on tables — SQLite
        // doesn't distinguish between them for this.

        $columns = $manager->columns($viewName);

        if (!$columns) {
            return $this->fail("Error: \"{$viewName}\" has no columns to read.", $lines);
        }

        $className = $this->studly($viewName);

        if ($className === '') {
            return $this->fail("Error: could not derive a class name from \"{$viewName}\".", $lines);
        }

        $slug = $this->toSnakeCase($className);

        // ---- 4. Model (read-only: empty $fillable) ----

        $modelsDir = $this->baseDir . '/app/Models';
        $modelPath = "{$modelsDir}/{$className}.php";

        if (!is_dir($modelsDir)) {
            $lines[] = "Skipped model: app/Models not found under {$this->baseDir}.";
        } elseif (file_exists($modelPath) && !$force) {
            $lines[] = "Skipped: app/Models/{$className}.php already exists (re-run with --force to overwrite).";
        } else {
            $modelStub = $this->viewModelStub($className, $viewName, $columns);

            if (@file_put_contents($modelPath, $modelStub) === false) {
                $lines[] = "Error: could not write {$modelPath}. Check folder permissions.";
            } else {
                $lines[] = "Created: app/Models/{$className}.php";
            }
        }

        $lines[] = 'Columns: ' . implode(', ', array_column($columns, 'name'));

        // ---- 5. Controller (index() only — nothing to write to) ----

        $controllerClass = "{$className}Controller";
        $controllersDir  = $this->baseDir . '/app/Controllers';
        $controllerPath  = "{$controllersDir}/{$controllerClass}.php";

        if (!is_dir($controllersDir)) {
            $lines[] = "Skipped controller: app/Controllers not found under {$this->baseDir}.";
        } elseif (file_exists($controllerPath) && !$force) {
            $lines[] = "Skipped: app/Controllers/{$controllerClass}.php already exists (re-run with --force to overwrite).";
        } else {
            $controllerStub = $this->viewControllerStub($className, $controllerClass, $slug);

            if (@file_put_contents($controllerPath, $controllerStub) === false) {
                $lines[] = "Error: could not write {$controllerPath}. Check folder permissions.";
            } else {
                $lines[] = "Created: app/Controllers/{$controllerClass}.php";
            }
        }

        // ---- 6. Route ----

        $routesFile = $this->baseDir . '/routes/web.php';

        if (!file_exists($routesFile)) {
            $lines[] = 'Skipped route: routes/web.php not found.';
        } else {
            $lines[] = $this->insertViewRoute($routesFile, $slug, $controllerClass);
        }

        // ---- 7. Table-format listing view ----

        $viewsDir  = $this->baseDir . '/app/Views/' . $slug;
        $indexPath = $viewsDir . '/index.php';

        if (!is_dir($viewsDir) && !@mkdir($viewsDir, 0755, true) && !is_dir($viewsDir)) {
            $lines[] = "Skipped view: could not create app/Views/{$slug}. Check folder permissions.";
        } elseif (file_exists($indexPath) && !$force) {
            $lines[] = "Skipped: app/Views/{$slug}/index.php already exists (re-run with --force to overwrite).";
        } else {
            $tableStub = $this->viewTableStub($slug, $columns);

            if (@file_put_contents($indexPath, $tableStub) === false) {
                $lines[] = "Error: could not write {$indexPath}. Check folder permissions.";
            } else {
                $lines[] = "Created: app/Views/{$slug}/index.php";
            }
        }

        $lines[] = "Note: read-only by design — {$className} has no create/edit/delete since it's backed by a VIEW.";

        return $this->ok($lines);
    }

    protected function viewExists(DatabaseManager $manager, string $name): bool
    {
        $stmt = $manager->pdo()->prepare("SELECT 1 FROM sqlite_master WHERE type = 'view' AND name = ? LIMIT 1");
        $stmt->execute([$name]);

        return (bool) $stmt->fetchColumn();
    }

    /** @return string[] */
    protected function listViews(DatabaseManager $manager): array
    {
        $stmt = $manager->pdo()->query("SELECT name FROM sqlite_master WHERE type = 'view' ORDER BY name");

        return array_column($stmt->fetchAll(), 'name');
    }

    /**
     * Builds the Model file's contents for a VIEW. $fillable is always
     * empty — SQLite views aren't writable without INSTEAD OF triggers
     * (none are set up here), so mass assignment is turned off on purpose
     * rather than generating create()/update() calls that would just fail.
     */
    protected function viewModelStub(string $className, string $viewName, array $columns): string
    {
        $schemaComment = implode("\n", array_map(
            fn($c) => " *   {$c['name']} ({$c['type']})",
            $columns
        ));

        return <<<PHP
<?php

namespace App\Models;

use Core\Model;

/**
 * Backed by the SQL VIEW `{$viewName}`, not a table — read-only.
 *
 * Columns:
{$schemaComment}
 *
 * \$fillable is deliberately empty: SQLite views can't be written to
 * directly (no INSTEAD OF triggers are defined for this one), so
 * create()/update() here would just fail at the database level. Use
 * all()/find() to read; edit the underlying tables for writes.
 */
class {$className} extends Model
{
    protected static \$table = '{$viewName}';

    protected static \$fillable = [];
}

PHP;
    }

    /**
     * Builds the Controller file's contents for a VIEW: index() only.
     * No create/store/edit/update/destroy — there's nothing to write to.
     */
    protected function viewControllerStub(string $className, string $controllerClass, string $slug): string
    {
        $human = ucwords(str_replace('_', ' ', $slug));

        return <<<PHP
<?php

namespace App\Controllers;

use App\Models\\{$className};
use Core\Controller;
use Core\Response;

/**
 * Generated by make:view {$className}. Read-only — {$className} is backed by a
 * SQL VIEW, so this only lists data; there's no create, edit or delete.
 */
class {$controllerClass} extends Controller
{
    public function index(): Response
    {
        return \$this->view('{$slug}/index', [
            'title' => '{$human}',
            'rows'  => {$className}::all(),
        ]);
    }
}

PHP;
    }

    /**
     * Inserts the single read-only route for a scaffolded view, using the
     * same marker-or-append strategy as make:controller/make:model.
     */
    protected function insertViewRoute(string $routesFile, string $slug, string $controllerClass): string
    {
        $routeLine = "\$router->get('/{$slug}', '{$controllerClass}@index');";

        $routesContent = file_get_contents($routesFile);

        if (strpos($routesContent, $routeLine) !== false) {
            return 'Route already exists in routes/web.php — skipped.';
        }

        $block  = "// ---- {$controllerClass} (read-only view) ----\n{$routeLine}\n\n";
        $marker = '// Closure example';

        if (strpos($routesContent, $marker) !== false) {
            $routesContent = str_replace($marker, $block . $marker, $routesContent);
        } else {
            $routesContent = rtrim($routesContent) . "\n\n" . $block;
        }

        if (@file_put_contents($routesFile, $routesContent) === false) {
            return 'Error: could not update routes/web.php. Check file permissions.';
        }

        return "Route added: GET /{$slug} -> {$controllerClass}@index";
    }

    /**
     * Builds app/Views/{slug}/index.php: a plain HTML <table> with one
     * column per view column — a table-format listing, not the card style
     * make:model uses, since the request was specifically for a table.
     */
    protected function viewTableStub(string $slug, array $columns): string
    {
        $human = ucwords(str_replace('_', ' ', $slug));

        $safeColumns = array_values(array_filter($columns, fn($c) => $this->isPhpIdentifier($c['name'])));

        $headerCells = implode("\n", array_map(
            fn($c) => '                    <th>' . ucwords(str_replace('_', ' ', $c['name'])) . '</th>',
            $safeColumns
        ));

        $bodyCells = implode("\n", array_map(
            fn($c) => "                    <td><?= e(\$row->{$c['name']}) ?></td>",
            $safeColumns
        ));

        return <<<PHP
<div class="d-flex justify-content-between align-items-center mb-4">
    <h1 class="h2 mb-0">{$human}</h1>
</div>

<?php if (!\$rows): ?>

    <div class="alert alert-secondary">
        No {$human} rows found.
    </div>

<?php else: ?>

    <div class="table-responsive">
        <table class="table table-striped table-sm align-middle">
            <thead>
                <tr>
{$headerCells}
                </tr>
            </thead>
            <tbody>
                <?php foreach (\$rows as \$row): ?>
                <tr>
{$bodyCells}
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

<?php endif; ?>

PHP;
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

    /**
     * TableName / camelCase → table_name. Used for the URL slug of a
     * scaffolded VIEW, whose name (e.g. UsersLogView) doesn't already
     * look like a table name the way regular tables do.
     */
    protected function toSnakeCase(string $word): string
    {
        return strtolower(preg_replace('/(?<!^)[A-Z]/', '_$0', $word));
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