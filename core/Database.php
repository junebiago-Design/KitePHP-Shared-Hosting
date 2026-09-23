<?php
namespace Core;

class Database
{
    private static $instance;
    private $pdo;

    private function __construct()
    {
        $c = config('db');
        $driver = $c['driver'] ?? 'sqlite';

        if ($driver === 'sqlite') {
            $this->connectSqlite($c);
        } else {
            $dsn = "mysql:host={$c['host']};port={$c['port']};dbname={$c['name']};charset=utf8mb4";
            $this->pdo = new \PDO($dsn, $c['user'], $c['pass'], [
                \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
        }

        if ($c['auto_migrate'] ?? true) {
            $this->migrate($driver);
        }
    }

    private function connectSqlite(array $c): void
    {
        if (!extension_loaded('pdo_sqlite')) {
            throw new \RuntimeException('The pdo_sqlite PHP extension is not available on this server. Set db.driver to "mysql" in config/config.php.');
        }

        $file = BASE_PATH . '/' . ltrim($c['sqlite_path'] ?? 'storage/database.sqlite', '/');
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0755, true)) {
            throw new \RuntimeException("Cannot create database folder: $dir");
        }
        if (!is_writable($dir)) {
            throw new \RuntimeException("Database folder is not writable: $dir");
        }

        $isNew = !is_file($file);

        $this->pdo = new \PDO('sqlite:' . $file, null, null, [
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_TIMEOUT            => 5,   // wait up to 5s if the file is locked
        ]);
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        // First run: create tables (and seed data) from the schema file
        if ($isNew) {
            $schema = BASE_PATH . '/' . ltrim($c['sqlite_schema'] ?? 'db/schema.sqlite.sql', '/');
            if (is_file($schema)) {
                $this->pdo->exec(file_get_contents($schema));
            }
        }
    }

    /**
     * Runs every db/migrations/*.php file that has not run yet (in filename order).
     * A migration file returns: function (Core\Database $db, string $driver) { ... }
     */
    private function migrate(string $driver): void
    {
        $files = glob(BASE_PATH . '/db/migrations/*.php');
        if (!$files) {
            return;
        }
        sort($files);

        $this->pdo->exec('CREATE TABLE IF NOT EXISTS migrations (name VARCHAR(191) PRIMARY KEY, ran_at VARCHAR(30) NOT NULL)');
        $done = $this->pdo->query('SELECT name FROM migrations')->fetchAll(\PDO::FETCH_COLUMN);

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $done, true)) {
                continue;
            }
            $run = require $file;
            $run($this, $driver);
            $this->query('INSERT INTO migrations (name, ran_at) VALUES (?, ?)', [$name, date('Y-m-d H:i:s')]);
        }
    }

    public static function instance(): self
    {
        if (!self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function query(string $sql, array $params = []): \PDOStatement
    {
        $st = $this->pdo->prepare($sql);
        $st->execute($params);
        return $st;
    }

    public function lastInsertId(): string
    {
        return $this->pdo->lastInsertId();
    }

    public function pdo(): \PDO
    {
        return $this->pdo;
    }
}
