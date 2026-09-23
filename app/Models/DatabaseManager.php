<?php

namespace App\Models;

use PDO;
use PDOException;
use RuntimeException;

/**
 * DatabaseManager
 *
 * Works directly against the SQLite file configured in config/config.php:
 *
 *     'database' => [
 *         'driver' => 'sqlite',
 *         'path'   => __DIR__ . '/../storage/database.sqlite',
 *     ],
 *
 * This is a schema/data inspection and editing tool, not an ORM.
 * Regular application code should keep using Core\Model.
 */
class DatabaseManager
{
    /** Column types the table builder is allowed to emit. */
    public const TYPES = [
        'INTEGER', 'TEXT', 'REAL', 'NUMERIC', 'BLOB',
        'VARCHAR', 'CHAR', 'DECIMAL', 'BOOLEAN', 'DATE', 'DATETIME',
    ];

    /** Types that accept a length/precision suffix. */
    protected const SIZED_TYPES = ['VARCHAR', 'CHAR', 'DECIMAL', 'NUMERIC'];

    /** Tables the manager refuses to drop or empty from the UI. */
    protected array $protectedTables = [
        'migrations', 'users', 'roles', 'permissions',
        'role_permissions', 'user_roles', 'sessions', 'password_resets',
    ];

    protected PDO $pdo;
    protected string $path;

    /**
     * @param array|null $config Defaults to the 'db' block in config/config.php,
     *                           falling back to 'database' for older configs.
     */
    public function __construct(?array $config = null)
    {
        $config = $config ?? (array) (config('db') ?: config('database'));

        $driver = $config['driver'] ?? 'sqlite';
        if ($driver !== 'sqlite') {
            throw new RuntimeException(
                "The database manager supports the sqlite driver only. Configured driver: {$driver}."
            );
        }

        // 'sqlite_path' (relative to the project root) is the KitePHP key;
        // 'path' is accepted too, for configs that use an absolute path.
        $path = (string) ($config['sqlite_path'] ?? $config['path'] ?? '');
        if ($path === '') {
            throw new RuntimeException(
                "No SQLite path is configured. Add 'sqlite_path' to the 'db' block in config/config.php."
            );
        }

        $path = $this->absolutePath($path);
        $dir  = dirname($path);
        if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
            throw new RuntimeException("Cannot create the storage directory: {$dir}");
        }

        $this->path = $path;

        try {
            $this->pdo = new PDO('sqlite:' . $path, null, null, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $this->pdo->exec('PRAGMA foreign_keys = ON');
        } catch (PDOException $e) {
            throw new RuntimeException('Cannot open the database: ' . $e->getMessage());
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /**
     * Turns a project-root-relative path such as 'storage/database.sqlite'
     * into an absolute one. Absolute paths are returned untouched.
     */
    protected function absolutePath(string $path): string
    {
        $isAbsolute = $path[0] === '/'
            || $path[0] === '\\'
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path);

        if ($isAbsolute) {
            return $path;
        }

        foreach (['BASE_PATH', 'ROOT_PATH', 'APP_ROOT', 'ROOT'] as $constant) {
            if (defined($constant)) {
                return rtrim((string) constant($constant), '/\\') . '/' . ltrim($path, '/\\');
            }
        }

        // app/Models/DatabaseManager.php → project root
        return dirname(__DIR__, 2) . '/' . ltrim($path, '/\\');
    }

    // ---------------------------------------------------------
    // Database
    // ---------------------------------------------------------

    public function info(): array
    {
        $size = is_file($this->path) ? (int) filesize($this->path) : 0;

        return [
            'path'           => $this->path,
            'file'           => basename($this->path),
            'size'           => $size,
            'size_human'     => $this->humanSize($size),
            'writable'       => is_writable($this->path) && is_writable(dirname($this->path)),
            'sqlite_version' => $this->pdo->query('SELECT sqlite_version()')->fetchColumn(),
        ];
    }

    public function isProtected(string $table): bool
    {
        return in_array(strtolower($table), $this->protectedTables, true);
    }

    // ---------------------------------------------------------
    // Tables
    // ---------------------------------------------------------

    public function listTables(): array
    {
        $stmt = $this->pdo->query(
            "SELECT name, sql FROM sqlite_master
             WHERE type = 'table' AND name NOT LIKE 'sqlite_%'
             ORDER BY name"
        );

        $tables = [];
        foreach ($stmt->fetchAll() as $row) {
            $name = $row['name'];
            $tables[] = [
                'name'      => $name,
                'rows'      => $this->countRows($name),
                'columns'   => count($this->columns($name)),
                'protected' => $this->isProtected($name),
                'sql'       => $row['sql'],
            ];
        }

        return $tables;
    }

    public function tableExists(string $table): bool
    {
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = ? LIMIT 1"
        );
        $stmt->execute([$table]);

        return (bool) $stmt->fetchColumn();
    }

    protected function requireTable(string $table): string
    {
        $table = $this->identifier($table);
        if (!$this->tableExists($table)) {
            throw new RuntimeException("Table '{$table}' does not exist.");
        }

        return $table;
    }

    public function countRows(string $table): int
    {
        $table = $this->identifier($table);

        try {
            return (int) $this->pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }

    /**
     * Full structure of one table: columns, foreign keys, indexes and DDL.
     */
    public function schema(string $table): array
    {
        $table = $this->requireTable($table);

        $foreignKeys = $this->pdo->query("PRAGMA foreign_key_list(`{$table}`)")->fetchAll();

        $indexes = [];
        foreach ($this->pdo->query("PRAGMA index_list(`{$table}`)")->fetchAll() as $index) {
            $cols = $this->pdo->query("PRAGMA index_info(`{$index['name']}`)")->fetchAll();
            $indexes[] = [
                'name'    => $index['name'],
                'unique'  => (bool) $index['unique'],
                'columns' => array_column($cols, 'name'),
            ];
        }

        $stmt = $this->pdo->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
        $stmt->execute([$table]);

        return [
            'table'        => $table,
            'protected'    => $this->isProtected($table),
            'columns'      => $this->columns($table),
            'foreign_keys' => $foreignKeys,
            'indexes'      => $indexes,
            'rows'         => $this->countRows($table),
            'has_rowid'    => $this->hasRowid($table),
            'sql'          => $stmt->fetchColumn(),
        ];
    }

    public function columns(string $table): array
    {
        $table = $this->identifier($table);

        try {
            $cols = $this->pdo->query("PRAGMA table_info(`{$table}`)")->fetchAll();
        } catch (PDOException $e) {
            return [];
        }

        return array_map(fn($c) => [
            'name'     => $c['name'],
            'type'     => $c['type'] !== '' ? $c['type'] : 'BLOB',
            'notnull'  => (bool) $c['notnull'],
            'default'  => $c['dflt_value'],
            'pk'       => (bool) $c['pk'],
            'masked'   => $this->isSecret($c['name']),
        ], $cols);
    }

    /**
     * @param array $columns Each: name, type, length, notnull, pk,
     *                       autoincrement, unique, default, fk_table, fk_column
     */
    public function createTable(string $name, array $columns): void
    {
        $name = $this->identifier($name);

        if ($this->tableExists($name)) {
            throw new RuntimeException("Table '{$name}' already exists.");
        }
        if (empty($columns)) {
            throw new RuntimeException('Add at least one column.');
        }

        $defs   = [];
        $pkCols = [];
        $fkDefs = [];
        $autoIncrement = false;

        foreach ($columns as $col) {
            $colName = $this->identifier($col['name'] ?? '');
            $type    = $this->columnType($col['type'] ?? 'TEXT');

            if (!empty($col['length']) && in_array($type, self::SIZED_TYPES, true)) {
                $type .= '(' . preg_replace('/[^0-9,]/', '', (string) $col['length']) . ')';
            }

            $def = "`{$colName}` {$type}";

            $isAutoPk = !empty($col['pk'])
                && !empty($col['autoincrement'])
                && strtoupper($col['type'] ?? '') === 'INTEGER';

            if ($isAutoPk) {
                $def .= ' PRIMARY KEY AUTOINCREMENT';
                $autoIncrement = true;
            } elseif (!empty($col['pk'])) {
                $pkCols[] = $colName;
            }

            if (!empty($col['notnull'])) {
                $def .= ' NOT NULL';
            }
            if (!empty($col['unique'])) {
                $def .= ' UNIQUE';
            }
            if (isset($col['default']) && $col['default'] !== '') {
                $def .= ' DEFAULT ' . $this->pdo->quote((string) $col['default']);
            }

            $defs[] = $def;

            if (!empty($col['fk_table']) && !empty($col['fk_column'])) {
                $fkTable  = $this->identifier($col['fk_table']);
                $fkColumn = $this->identifier($col['fk_column']);
                $fkDefs[] = "FOREIGN KEY (`{$colName}`) REFERENCES `{$fkTable}`(`{$fkColumn}`) ON DELETE RESTRICT";
            }
        }

        if (!$autoIncrement && $pkCols) {
            $defs[] = 'PRIMARY KEY (' . implode(', ', array_map(fn($c) => "`{$c}`", $pkCols)) . ')';
        }

        $sql = "CREATE TABLE `{$name}` (\n  "
            . implode(",\n  ", array_merge($defs, $fkDefs))
            . "\n)";

        $this->pdo->exec($sql);
    }

    public function addColumn(string $table, array $column): void
    {
        $table   = $this->requireTable($table);
        $colName = $this->identifier($column['name'] ?? '');
        $type    = $this->columnType($column['type'] ?? 'TEXT');

        $def = "`{$colName}` {$type}";

        if (!empty($column['notnull'])) {
            if (!isset($column['default']) || $column['default'] === '') {
                throw new RuntimeException('A NOT NULL column added to an existing table needs a default value.');
            }
            $def .= ' NOT NULL';
        }
        if (isset($column['default']) && $column['default'] !== '') {
            $def .= ' DEFAULT ' . $this->pdo->quote((string) $column['default']);
        }

        $this->pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$def}");
    }

    public function dropTable(string $table): void
    {
        $table = $this->requireTable($table);

        if ($this->isProtected($table)) {
            throw new RuntimeException("'{$table}' is a framework table and cannot be dropped here.");
        }

        $this->pdo->exec("DROP TABLE `{$table}`");
    }

    public function emptyTable(string $table): int
    {
        $table = $this->requireTable($table);

        if ($this->isProtected($table)) {
            throw new RuntimeException("'{$table}' is a framework table and cannot be emptied here.");
        }

        return $this->pdo->exec("DELETE FROM `{$table}`");
    }

    // ---------------------------------------------------------
    // Rows
    // ---------------------------------------------------------

    /**
     * @return array{rows: array, total: int, limit: int, offset: int}
     */
    public function rows(string $table, int $limit = 25, int $offset = 0, string $search = ''): array
    {
        $table  = $this->requireTable($table);
        $limit  = max(1, min(200, $limit));
        $offset = max(0, $offset);

        $select = $this->hasRowid($table) ? 'rowid AS __rowid, *' : '*';

        $where = '';
        $bind  = [];

        if ($search !== '') {
            $parts = [];
            foreach ($this->columns($table) as $i => $col) {
                if ($col['masked']) {
                    continue;
                }
                $parts[] = "CAST(`{$col['name']}` AS TEXT) LIKE :s{$i}";
                $bind[":s{$i}"] = '%' . $search . '%';
            }
            if ($parts) {
                $where = ' WHERE ' . implode(' OR ', $parts);
            }
        }

        $countStmt = $this->pdo->prepare("SELECT COUNT(*) FROM `{$table}`{$where}");
        $countStmt->execute($bind);
        $total = (int) $countStmt->fetchColumn();

        $stmt = $this->pdo->prepare(
            "SELECT {$select} FROM `{$table}`{$where} LIMIT {$limit} OFFSET {$offset}"
        );
        $stmt->execute($bind);

        $masked = array_column(array_filter($this->columns($table), fn($c) => $c['masked']), 'name');

        $rows = array_map(function (array $row) use ($masked) {
            foreach ($masked as $col) {
                if (isset($row[$col]) && $row[$col] !== '') {
                    $row[$col] = '••••••••';
                }
            }
            return $row;
        }, $stmt->fetchAll());

        return [
            'rows'   => $rows,
            'total'  => $total,
            'limit'  => $limit,
            'offset' => $offset,
        ];
    }

    public function insert(string $table, array $data): string
    {
        $table = $this->requireTable($table);
        $data  = $this->prepareValues($table, $data);

        if (!$data) {
            throw new RuntimeException('Fill in at least one column.');
        }

        $cols    = array_keys($data);
        $holders = array_map(fn($c) => ':' . $c, $cols);

        $stmt = $this->pdo->prepare(
            "INSERT INTO `{$table}` (`" . implode('`,`', $cols) . "`) VALUES (" . implode(', ', $holders) . ")"
        );
        foreach ($data as $k => $v) {
            $stmt->bindValue(':' . $k, $v, $v === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
        }
        $stmt->execute();

        return (string) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, array $where): int
    {
        $table = $this->requireTable($table);
        $data  = $this->prepareValues($table, $data);

        if (!$data) {
            throw new RuntimeException('Nothing to update.');
        }

        $set  = [];
        $bind = [];
        foreach ($data as $k => $v) {
            $col = $this->identifier($k);
            $set[] = "`{$col}` = :set_{$col}";
            $bind[":set_{$col}"] = $v;
        }

        [$clause, $whereBind] = $this->whereClause($table, $where);

        $stmt = $this->pdo->prepare(
            "UPDATE `{$table}` SET " . implode(', ', $set) . " WHERE {$clause}"
        );
        $stmt->execute($bind + $whereBind);

        return $stmt->rowCount();
    }

    public function delete(string $table, array $where): int
    {
        $table = $this->requireTable($table);

        [$clause, $bind] = $this->whereClause($table, $where);

        $stmt = $this->pdo->prepare("DELETE FROM `{$table}` WHERE {$clause}");
        $stmt->execute($bind);

        return $stmt->rowCount();
    }

    // ---------------------------------------------------------
    // Raw SQL
    // ---------------------------------------------------------

    /**
     * Runs one or more statements in a single transaction.
     * Any failure rolls the whole batch back.
     */
    public function runSql(string $sql): array
    {
        $statements = array_filter(array_map('trim', $this->splitSql($sql)), fn($s) => $s !== '');

        if (!$statements) {
            throw new RuntimeException('Enter a SQL statement to run.');
        }

        $results = [];
        $this->pdo->beginTransaction();

        try {
            foreach ($statements as $statement) {
                $isRead = (bool) preg_match('/^\s*(SELECT|WITH|PRAGMA|EXPLAIN)\b/i', $statement);
                $stmt   = $this->pdo->query($statement);

                if ($isRead) {
                    $rows = $stmt->fetchAll();
                    $results[] = [
                        'sql'   => $statement,
                        'type'  => 'read',
                        'rows'  => array_slice($rows, 0, 200),
                        'count' => count($rows),
                    ];
                } else {
                    $results[] = [
                        'sql'      => $statement,
                        'type'     => 'write',
                        'affected' => $stmt->rowCount(),
                    ];
                }
            }
            $this->pdo->commit();
        } catch (PDOException $e) {
            $this->pdo->rollBack();
            throw new RuntimeException('SQL error: ' . $e->getMessage());
        }

        return $results;
    }

    protected function splitSql(string $sql): array
    {
        $statements = [];
        $current    = '';
        $inString   = false;
        $stringChar = '';
        $inComment  = false;
        $len        = strlen($sql);

        for ($i = 0; $i < $len; $i++) {
            $ch   = $sql[$i];
            $next = $i + 1 < $len ? $sql[$i + 1] : '';

            if ($inComment) {
                if ($ch === "\n") {
                    $inComment = false;
                    $current  .= $ch;
                }
                continue;
            }
            if (!$inString && $ch === '-' && $next === '-') {
                $inComment = true;
                $i++;
                continue;
            }
            if (!$inString && ($ch === "'" || $ch === '"')) {
                $inString   = true;
                $stringChar = $ch;
                $current   .= $ch;
                continue;
            }
            if ($inString) {
                $current .= $ch;
                if ($ch === $stringChar && ($i === 0 || $sql[$i - 1] !== '\\')) {
                    $inString = false;
                }
                continue;
            }
            if ($ch === ';') {
                $statements[] = $current;
                $current = '';
                continue;
            }
            $current .= $ch;
        }

        $statements[] = $current;

        return $statements;
    }

    // ---------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------

    /**
     * Builds a WHERE clause. Prefers the hidden rowid the row list sends back,
     * falls back to matching every supplied column.
     */
    protected function whereClause(string $table, array $where): array
    {
        if (isset($where['__rowid']) && $this->hasRowid($table)) {
            return ['rowid = :w_rowid', [':w_rowid' => $where['__rowid']]];
        }

        unset($where['__rowid']);

        if (!$where) {
            throw new RuntimeException('No row selected.');
        }

        $conditions = [];
        $bind       = [];

        foreach ($where as $k => $v) {
            $col = $this->identifier($k);
            if ($v === null) {
                $conditions[] = "`{$col}` IS NULL";
                continue;
            }
            $conditions[] = "`{$col}` = :w_{$col}";
            $bind[":w_{$col}"] = $v;
        }

        return [implode(' AND ', $conditions), $bind];
    }

    /**
     * Drops unknown columns, turns empty strings into NULL where the column
     * allows it, and hashes values in password-like columns.
     */
    protected function prepareValues(string $table, array $data): array
    {
        $columns = [];
        foreach ($this->columns($table) as $col) {
            $columns[$col['name']] = $col;
        }

        $out = [];
        foreach ($data as $key => $value) {
            if (!isset($columns[$key])) {
                continue;
            }
            $col = $columns[$key];

            if (is_string($value)) {
                $value = trim($value);
            }
            if ($value === '' || $value === '••••••••') {
                if ($value === '••••••••') {
                    continue; // masked placeholder came back unchanged
                }
                if (!$col['notnull'] && !$col['pk']) {
                    $out[$key] = null;
                    continue;
                }
            }
            if ($col['masked'] && is_string($value) && $value !== '' && !$this->looksHashed($value)) {
                $value = password_hash($value, PASSWORD_DEFAULT);
            }

            $out[$key] = $value;
        }

        return $out;
    }

    protected function hasRowid(string $table): bool
    {
        try {
            $this->pdo->query("SELECT rowid FROM `{$table}` LIMIT 1");
            return true;
        } catch (PDOException $e) {
            return false;
        }
    }

    protected function isSecret(string $column): bool
    {
        return (bool) preg_match('/(password|passwd|secret|api_key|token)/i', $column);
    }

    protected function looksHashed(string $value): bool
    {
        return (bool) preg_match('/^\$(2[aby]|argon2i?d?)\$/', $value);
    }

    protected function columnType(string $type): string
    {
        $type = strtoupper(trim($type));

        if (!in_array($type, self::TYPES, true)) {
            throw new RuntimeException("Unsupported column type: '{$type}'");
        }

        return $type;
    }

    protected function identifier(string $name): string
    {
        $name = trim($name);

        if ($name === '' || !preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name)) {
            throw new RuntimeException("Invalid name: '{$name}'. Use letters, numbers and underscores.");
        }

        return $name;
    }

    protected function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }

        return round($bytes, $i === 0 ? 0 : 1) . ' ' . $units[$i];
    }
}