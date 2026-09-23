<?php

declare(strict_types=1);

namespace App\Services;

use PDO;
use PDOException;
use ReflectionFunction;
use RuntimeException;
use Throwable;

/**
 * DatabaseCli
 *
 * The engine behind both the `database.php` CLI script and the in-browser
 * console on the Database page, so they run exactly the same code — same
 * reasoning as KiteConsole. One difference from the original standalone
 * `database.php`: `test` no longer shells out to `php -l` via exec(), since
 * shared hosts like InfinityFree routinely disable exec()/shell_exec(). A
 * migration file's syntax is checked by requiring it directly and catching
 * the ParseError, which works the same way in-process.
 *
 * Supported commands:
 *   status
 *   migrate
 *   backup [--label NAME]
 *   verify
 *   test
 *
 * Migrations are forward-only — there's no rollback command here, matching
 * the original script, because the migration file format has no down().
 */
class DatabaseCli
{
    protected const COMMANDS = ['status', 'migrate', 'backup', 'verify', 'test'];

    protected string $baseDir;

    public function __construct(?string $baseDir = null)
    {
        $this->baseDir = $baseDir ?? dirname(__DIR__, 2);
    }

    public function availableCommands(): array
    {
        return self::COMMANDS;
    }

    /**
     * Parses one typed line ("backup --label before-feature") and runs it.
     *
     * @return array{ok: bool, output: string}
     */
    public function run(string $line): array
    {
        return $this->runArgs($this->tokenize($line));
    }

    /**
     * Runs a command from an already-tokenized argument list — what
     * database.php gets from $_SERVER['argv'] once the script name is
     * shifted off.
     *
     * @return array{ok: bool, output: string}
     */
    public function runArgs(array $args): array
    {
        $command = array_shift($args);

        if ($command === null || $command === '') {
            return $this->fail('Type a command, e.g. status');
        }

        try {
            switch ($command) {
                case 'status':
                    return $this->status();
                case 'migrate':
                    return $this->migrate();
                case 'backup':
                    return $this->backup($args);
                case 'verify':
                    return $this->verify();
                case 'test':
                    return $this->test();
                default:
                    return $this->fail(
                        "Unknown command: {$command}\nAvailable: " . implode(', ', self::COMMANDS)
                    );
            }
        } catch (Throwable $e) {
            // Anything unexpected (a PDOException from a locked/corrupt file,
            // for instance) still comes back as console output rather than
            // bubbling up as an uncaught error.
            return $this->fail('Error: ' . $e->getMessage());
        }
    }

    // ---------------------------------------------------------
    // status
    // ---------------------------------------------------------

    protected function status(): array
    {
        $pdo  = $this->connect();
        $rows = $this->migrationStatus($pdo);

        if (!$rows) {
            return $this->ok(['No migration files found under db/migrations/.']);
        }

        $lines = [];
        foreach ($rows as $row) {
            $lines[] = sprintf('[%s] %s', strtoupper($row['status']), $row['name']);
        }

        return $this->ok($lines);
    }

    // ---------------------------------------------------------
    // migrate
    // ---------------------------------------------------------

    protected function migrate(): array
    {
        $bootstrapFile = $this->baseDir . '/core/bootstrap.php';
        $helpersFile   = $this->baseDir . '/core/helpers.php';
        $databaseFile  = $this->baseDir . '/core/Database.php';
        $configFile    = $this->baseDir . '/config/config.php';

        if (!is_file($databaseFile)) {
            return $this->fail('Error: core/Database.php not found.');
        }

        // The original standalone script defined this at the top of every
        // run; core/Database.php or the migration files themselves may
        // depend on it existing. Only define it if nothing already has —
        // the normal web bootstrap likely already sets it before apiCli()
        // ever runs.
        if (!defined('BASE_PATH')) {
            define('BASE_PATH', $this->baseDir);
        }

        // Core\Database (and whatever the migration files call) normally
        // runs inside the framework's own request bootstrap, which this
        // standalone call bypasses entirely. Load the same core/bootstrap.php
        // index.php uses, if there is one, else fall back to just the
        // helper functions.
        if (is_file($bootstrapFile)) {
            require_once $bootstrapFile;
        } elseif (is_file($helpersFile)) {
            require_once $helpersFile;
        }

        // Core\App's constructor normally loads this; nothing here ever
        // constructs an App, so config('db') would return null and
        // Database's constructor (which needs an array) would throw a
        // TypeError — same reasoning tests/database_test.php documents.
        if (is_file($configFile) && class_exists('Core\\App')) {
            \Core\App::$config = require $configFile;
        }

        require_once $databaseFile;

        $class = 'Core\\Database';

        if (!class_exists($class)) {
            return $this->fail('Error: Core\\Database class not found after loading core/Database.php.');
        }

        // Same call the original script made — this invokes the framework's
        // real migration engine, which reads db/migrations/ itself and
        // respects config/config.php and the configured driver.
        $class::instance();

        return $this->ok(['Migration process completed.']);
    }

    // ---------------------------------------------------------
    // backup
    // ---------------------------------------------------------

    protected function backup(array $args): array
    {
        $label      = null;
        $labelIndex = array_search('--label', $args, true);

        if ($labelIndex !== false) {
            $label = $args[$labelIndex + 1] ?? null;
        }

        $source = $this->sqlitePath();

        if (!is_file($source)) {
            return $this->fail("Error: SQLite database does not exist: {$source}");
        }

        $backupDirectory = $this->baseDir . '/storage/backups';

        if (!is_dir($backupDirectory) && !@mkdir($backupDirectory, 0755, true) && !is_dir($backupDirectory)) {
            return $this->fail("Error: could not create {$backupDirectory}. Check folder permissions.");
        }

        $suffix      = $label ? '-' . preg_replace('/[^a-zA-Z0-9_-]+/', '-', $label) : '';
        $destination = $backupDirectory . '/database-' . date('Y-m-d-His') . $suffix . '.sqlite';

        try {
            $pdo    = $this->connect($source);
            $quoted = $pdo->quote($destination);
            $pdo->exec("VACUUM INTO {$quoted}");
        } catch (PDOException $e) {
            return $this->fail('Error creating backup — ' . $e->getMessage());
        }

        return $this->ok(["Backup created: {$destination}"]);
    }

    // ---------------------------------------------------------
    // verify
    // ---------------------------------------------------------

    protected function verify(): array
    {
        $pdo = $this->connect();

        $result = $pdo->query('PRAGMA integrity_check')->fetchColumn();

        if ($result !== 'ok') {
            return $this->fail("SQLite integrity check failed: {$result}");
        }

        $lines = ['SQLite integrity check: OK'];

        $tables = $pdo->query(
            "SELECT name FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        )->fetchAll(PDO::FETCH_COLUMN);

        $lines[] = 'Tables: ' . count($tables);
        foreach ($tables as $table) {
            $lines[] = "  - {$table}";
        }

        return $this->ok($lines);
    }

    // ---------------------------------------------------------
    // test
    // ---------------------------------------------------------

    /**
     * Checks that every migration file parses and returns a callable with
     * the right signature. This is a structural check only — it does not
     * actually run the migrations against a copy of the database, despite
     * what the original script's usage text implied; nothing here executes
     * a migration's SQL.
     */
    protected function test(): array
    {
        $lines = ['Testing migration files...'];
        $files = $this->migrationFiles();

        if (!$files) {
            $lines[] = 'No migration files found.';
            return $this->ok($lines);
        }

        foreach ($files as $file) {
            $name = basename($file);

            try {
                $migration = require $file;
            } catch (\ParseError $e) {
                return $this->fail("Syntax error in {$name}: " . $e->getMessage(), $lines);
            } catch (Throwable $e) {
                return $this->fail("Error loading {$name}: " . $e->getMessage(), $lines);
            }

            if (!is_callable($migration)) {
                return $this->fail("{$name} must return a callable migration function.", $lines);
            }

            $reflection = new ReflectionFunction($migration);
            if ($reflection->getNumberOfParameters() < 2) {
                return $this->fail("{$name} must accept (Core\\Database \$db, string \$driver).", $lines);
            }

            $lines[] = "  OK {$name}";
        }

        $lines[] = 'Migration file test completed successfully.';
        $lines[] = 'Run "migrate" to execute pending migrations.';

        return $this->ok($lines);
    }

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------

    protected function connect(?string $file = null): PDO
    {
        $file ??= $this->sqlitePath();

        if (!extension_loaded('pdo_sqlite')) {
            throw new RuntimeException('The pdo_sqlite extension is not enabled.');
        }

        $directory = dirname($file);

        if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) {
            throw new RuntimeException("Could not create {$directory}.");
        }

        $pdo = new PDO('sqlite:' . $file);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }

    protected function ensureMigrationTable(PDO $pdo): void
    {
        $pdo->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                name VARCHAR(191) PRIMARY KEY,
                ran_at VARCHAR(30) NOT NULL
            )'
        );
    }

    protected function migrationStatus(PDO $pdo): array
    {
        $this->ensureMigrationTable($pdo);

        $done = $pdo->query('SELECT name FROM migrations')->fetchAll(PDO::FETCH_COLUMN);

        $rows = [];
        foreach ($this->migrationFiles() as $file) {
            $name    = basename($file);
            $rows[]  = [
                'name'   => $name,
                'status' => in_array($name, $done, true) ? 'ran' : 'pending',
            ];
        }

        return $rows;
    }

    protected function migrationFiles(): array
    {
        $files = glob($this->baseDir . '/db/migrations/*.php') ?: [];
        sort($files, SORT_STRING);

        return $files;
    }

    /**
     * Reads the 'db' block straight out of config/config.php — same
     * approach KiteConsole uses, so this works standalone from a terminal
     * without the framework's normal bootstrap.
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

    protected function sqlitePath(): string
    {
        $db   = $this->dbConfig() ?? [];
        $path = (string) ($db['sqlite_path'] ?? $db['path'] ?? 'storage/database.sqlite');

        return $this->baseDir . '/' . ltrim($path, '/');
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