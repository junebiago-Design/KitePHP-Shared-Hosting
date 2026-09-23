<?php

declare(strict_types=1);

/**
 * KitePHP Database Test Suite
 *
 * Tests:
 * - Database connection
 * - SQLite integrity
 * - Table structure
 * - Required columns
 * - CRUD operations
 * - Data loading speed
 * - Insert speed
 * - Update speed
 * - Delete speed
 * - Query speed
 * - Indexes
 * - Row counts
 *
 * Run:
 *
 *     php tests/database_test.php
 *
 * IMPORTANT:
 * This test creates a temporary table called:
 *
 *     kitephp_db_test
 *
 * The table is removed automatically when the test finishes.
 */

// BASE_PATH + the class autoloader (same two lines index.php uses).
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/core/bootstrap.php';

// Core\App's constructor normally loads this; this script never creates an
// App, so config('db') would return null and Database's constructor
// (which needs an array) would throw a TypeError.
\Core\App::$config = require BASE_PATH . '/config/config.php';

use Core\Database;


/*
|--------------------------------------------------------------------------
| Configuration
|--------------------------------------------------------------------------
*/

const TEST_TABLE = 'kitephp_db_test';
const TEST_ROWS  = 1000;

$passed = 0;
$failed = 0;


/*
|--------------------------------------------------------------------------
| Console Helpers
|--------------------------------------------------------------------------
*/

function line(): void
{
    echo str_repeat('-', 70) . PHP_EOL;
}

function title(string $text): void
{
    echo PHP_EOL;
    line();
    echo "  {$text}" . PHP_EOL;
    line();
}

function pass(string $message): void
{
    global $passed;

    echo "  [PASS] {$message}" . PHP_EOL;
    $passed++;
}

function fail(string $message): void
{
    global $failed;

    echo "  [FAIL] {$message}" . PHP_EOL;
    echo "         {$message}" . PHP_EOL;

    $failed++;
}

function test(string $name, callable $callback): void
{
    echo PHP_EOL;
    echo "[TEST] {$name}" . PHP_EOL;

    try {
        $result = $callback();

        if ($result === true) {
            pass($name);
        } else {
            fail($name);
        }

    } catch (Throwable $e) {

        fail(
            $name . ' - ' .
            $e::class . ': ' .
            $e->getMessage()
        );
    }
}


/*
|--------------------------------------------------------------------------
| Assertions
|--------------------------------------------------------------------------
*/

function assertTrue(
    bool $condition,
    string $message = 'Assertion failed.'
): bool {

    if (!$condition) {
        throw new RuntimeException($message);
    }

    return true;
}

function assertEquals(
    mixed $expected,
    mixed $actual,
    string $message = 'Values are not equal.'
): bool {

    if ($expected !== $actual) {
        throw new RuntimeException(
            $message .
            ' Expected: ' .
            var_export($expected, true) .
            ' Got: ' .
            var_export($actual, true)
        );
    }

    return true;
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

$db = null;

title('KitePHP DATABASE TEST SUITE');


/*
|--------------------------------------------------------------------------
| TEST 1 - DATABASE CONNECTION
|--------------------------------------------------------------------------
*/

test('Database connection', function () use (&$db): bool {

    // Core\Database::instance() returns the framework's own wrapper object,
    // not PDO -- it only exposes query()/lastInsertId()/pdo(). Every test below
    // uses raw PDO methods (exec, prepare, getAttribute...), so fetch the real
    // PDO instance and reassign $db to it; every later closure captures $db by
    // reference, so they all see the PDO object from here on.
    $manager = Database::instance();
    $db = $manager->pdo();

    assertTrue(
        $db instanceof PDO,
        'Database::instance()->pdo() did not return PDO.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 2 - PDO CONFIGURATION
|--------------------------------------------------------------------------
*/

test('PDO configuration', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    assertTrue(
        $driver !== false,
        'Could not determine PDO driver.'
    );

    echo "         Driver: {$driver}" . PHP_EOL;

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 3 - DATABASE INTEGRITY
|--------------------------------------------------------------------------
*/

test('Database integrity check', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {

        $result = $db
            ->query('PRAGMA integrity_check')
            ->fetchColumn();

        assertEquals(
            'ok',
            strtolower((string) $result),
            'SQLite integrity check failed.'
        );

    } else {

        echo "         Integrity check skipped for {$driver}." . PHP_EOL;
    }

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 4 - CREATE TEST TABLE
|--------------------------------------------------------------------------
*/

test('Create test table', function () use (&$db): bool {

    $db->exec(
        'DROP TABLE IF EXISTS ' . TEST_TABLE
    );

    $sql = '
        CREATE TABLE ' . TEST_TABLE . ' (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name VARCHAR(120) NOT NULL,
            email VARCHAR(180) NOT NULL,
            status VARCHAR(30) NOT NULL DEFAULT \'active\',
            score INTEGER NOT NULL DEFAULT 0,
            created_at DATETIME NOT NULL
        )
    ';

    $db->exec($sql);

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 5 - TABLE EXISTS
|--------------------------------------------------------------------------
*/

test('Test table exists', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {

        $statement = $db->prepare(
            "SELECT name
             FROM sqlite_master
             WHERE type = 'table'
             AND name = ?"
        );

        $statement->execute([TEST_TABLE]);

        $table = $statement->fetchColumn();

        assertEquals(
            TEST_TABLE,
            $table,
            'Test table does not exist.'
        );

    } else {

        $statement = $db->prepare(
            'SHOW TABLES LIKE ?'
        );

        $statement->execute([TEST_TABLE]);

        assertTrue(
            $statement->fetchColumn() !== false,
            'Test table does not exist.'
        );
    }

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 6 - TABLE STRUCTURE
|--------------------------------------------------------------------------
*/

test('Table structure', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    $columns = [];

    if ($driver === 'sqlite') {

        $rows = $db
            ->query('PRAGMA table_info(' . TEST_TABLE . ')')
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $columns[$row['name']] = $row;
        }

    } else {

        $rows = $db
            ->query('DESCRIBE ' . TEST_TABLE)
            ->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $columns[$row['Field']] = $row;
        }
    }

    $required = [
        'id',
        'name',
        'email',
        'status',
        'score',
        'created_at'
    ];

    foreach ($required as $column) {

        assertTrue(
            isset($columns[$column]),
            "Missing column: {$column}"
        );
    }

    echo "         Columns found: " . count($columns) . PHP_EOL;

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 7 - PRIMARY KEY
|--------------------------------------------------------------------------
*/

test('Primary key structure', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {

        $rows = $db
            ->query('PRAGMA table_info(' . TEST_TABLE . ')')
            ->fetchAll(PDO::FETCH_ASSOC);

        $idColumn = null;

        foreach ($rows as $row) {

            if ($row['name'] === 'id') {
                $idColumn = $row;
                break;
            }
        }

        assertTrue(
            $idColumn !== null,
            'id column not found.'
        );

        assertEquals(
            1,
            (int) $idColumn['pk'],
            'id is not the primary key.'
        );
    }

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 8 - INSERT / CREATE
|--------------------------------------------------------------------------
*/

test('CRUD CREATE - Insert record', function () use (&$db): bool {

    $statement = $db->prepare(
        'INSERT INTO ' . TEST_TABLE . '
        (name, email, status, score, created_at)
        VALUES (?, ?, ?, ?, ?)'
    );

    $statement->execute([
        'Test User',
        'test@example.com',
        'active',
        100,
        date('Y-m-d H:i:s')
    ]);

    $id = (int) $db->lastInsertId();

    assertTrue(
        $id > 0,
        'Insert did not return a valid ID.'
    );

    echo "         Inserted ID: {$id}" . PHP_EOL;

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 9 - READ
|--------------------------------------------------------------------------
*/

test('CRUD READ - Read record', function () use (&$db): bool {

    $statement = $db->prepare(
        'SELECT *
         FROM ' . TEST_TABLE . '
         WHERE email = ?'
    );

    $statement->execute([
        'test@example.com'
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    assertTrue(
        is_array($row),
        'Record could not be read.'
    );

    assertEquals(
        'Test User',
        $row['name'],
        'Incorrect name returned.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 10 - UPDATE
|--------------------------------------------------------------------------
*/

test('CRUD UPDATE - Update record', function () use (&$db): bool {

    $statement = $db->prepare(
        'UPDATE ' . TEST_TABLE . '
         SET name = ?, score = ?
         WHERE email = ?'
    );

    $statement->execute([
        'Updated User',
        200,
        'test@example.com'
    ]);

    assertEquals(
        1,
        $statement->rowCount(),
        'Update did not affect exactly one record.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 11 - VERIFY UPDATE
|--------------------------------------------------------------------------
*/

test('Verify UPDATE result', function () use (&$db): bool {

    $statement = $db->prepare(
        'SELECT name, score
         FROM ' . TEST_TABLE . '
         WHERE email = ?'
    );

    $statement->execute([
        'test@example.com'
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    assertEquals(
        'Updated User',
        $row['name']
    );

    assertEquals(
        200,
        (int) $row['score']
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 12 - DELETE
|--------------------------------------------------------------------------
*/

test('CRUD DELETE - Delete record', function () use (&$db): bool {

    $statement = $db->prepare(
        'DELETE FROM ' . TEST_TABLE . '
         WHERE email = ?'
    );

    $statement->execute([
        'test@example.com'
    ]);

    assertEquals(
        1,
        $statement->rowCount(),
        'Delete did not affect exactly one record.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 13 - VERIFY DELETE
|--------------------------------------------------------------------------
*/

test('Verify DELETE result', function () use (&$db): bool {

    $statement = $db->prepare(
        'SELECT COUNT(*)
         FROM ' . TEST_TABLE . '
         WHERE email = ?'
    );

    $statement->execute([
        'test@example.com'
    ]);

    $count = (int) $statement->fetchColumn();

    assertEquals(
        0,
        $count,
        'Deleted record still exists.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 14 - BULK INSERT SPEED
|--------------------------------------------------------------------------
*/

test('Bulk INSERT performance', function () use (&$db): bool {

    $start = microtime(true);

    $db->beginTransaction();

    try {

        $statement = $db->prepare(
            'INSERT INTO ' . TEST_TABLE . '
            (name, email, status, score, created_at)
            VALUES (?, ?, ?, ?, ?)'
        );

        for ($i = 1; $i <= TEST_ROWS; $i++) {

            $statement->execute([
                'User ' . $i,
                'user' . $i . '@example.com',
                'active',
                $i,
                date('Y-m-d H:i:s')
            ]);
        }

        $db->commit();

    } catch (Throwable $e) {

        if ($db->inTransaction()) {
            $db->rollBack();
        }

        throw $e;
    }

    $elapsed = microtime(true) - $start;

    $rowsPerSecond = $elapsed > 0
        ? TEST_ROWS / $elapsed
        : 0;

    echo "         Rows inserted : " . TEST_ROWS . PHP_EOL;
    echo "         Time           : " . number_format($elapsed, 4) . " sec" . PHP_EOL;
    echo "         Rows/sec       : " . number_format($rowsPerSecond, 2) . PHP_EOL;

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 15 - DATA LOAD SPEED
|--------------------------------------------------------------------------
*/

test('Full table data loading speed', function () use (&$db): bool {

    $start = microtime(true);

    $statement = $db->query(
        'SELECT *
         FROM ' . TEST_TABLE
    );

    $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

    $elapsed = microtime(true) - $start;

    echo "         Rows loaded : " . count($rows) . PHP_EOL;
    echo "         Time        : " . number_format($elapsed, 4) . " sec" . PHP_EOL;

    assertEquals(
        TEST_ROWS,
        count($rows),
        'Unexpected number of rows loaded.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 16 - SELECT QUERY SPEED
|--------------------------------------------------------------------------
*/

test('SELECT query performance', function () use (&$db): bool {

    $start = microtime(true);

    $statement = $db->prepare(
        'SELECT *
         FROM ' . TEST_TABLE . '
         WHERE email = ?'
    );

    $statement->execute([
        'user500@example.com'
    ]);

    $row = $statement->fetch(PDO::FETCH_ASSOC);

    $elapsed = microtime(true) - $start;

    echo "         Query time : " . number_format($elapsed, 6) . " sec" . PHP_EOL;

    assertTrue(
        is_array($row),
        'Expected record was not found.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 17 - COUNT SPEED
|--------------------------------------------------------------------------
*/

test('COUNT query performance', function () use (&$db): bool {

    $start = microtime(true);

    $count = $db
        ->query(
            'SELECT COUNT(*)
             FROM ' . TEST_TABLE
        )
        ->fetchColumn();

    $elapsed = microtime(true) - $start;

    echo "         Count      : {$count}" . PHP_EOL;
    echo "         Query time : " . number_format($elapsed, 6) . " sec" . PHP_EOL;

    assertEquals(
        TEST_ROWS,
        (int) $count
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 18 - UPDATE SPEED
|--------------------------------------------------------------------------
*/

test('Bulk UPDATE performance', function () use (&$db): bool {

    $start = microtime(true);

    $statement = $db->prepare(
        'UPDATE ' . TEST_TABLE . '
         SET score = ?
         WHERE id = ?'
    );

    for ($i = 1; $i <= TEST_ROWS; $i++) {

        /*
         * The first test record was deleted.
         * Therefore update IDs that exist after the first record.
         */

        $statement->execute([
            $i * 2,
            $i + 1
        ]);
    }

    $elapsed = microtime(true) - $start;

    echo "         Updates : " . TEST_ROWS . PHP_EOL;
    echo "         Time    : " . number_format($elapsed, 4) . " sec" . PHP_EOL;

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 19 - DELETE SPEED
|--------------------------------------------------------------------------
*/

test('Bulk DELETE performance', function () use (&$db): bool {

    $start = microtime(true);

    $db->exec(
        'DELETE FROM ' . TEST_TABLE
    );

    $elapsed = microtime(true) - $start;

    $remaining = (int) $db
        ->query(
            'SELECT COUNT(*)
             FROM ' . TEST_TABLE
        )
        ->fetchColumn();

    echo "         Delete time : " . number_format($elapsed, 4) . " sec" . PHP_EOL;
    echo "         Remaining   : {$remaining}" . PHP_EOL;

    assertEquals(
        0,
        $remaining,
        'Records remain after DELETE.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 20 - INDEX TEST
|--------------------------------------------------------------------------
*/

test('Index inspection', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {

        $indexes = $db
            ->query(
                'PRAGMA index_list(' . TEST_TABLE . ')'
            )
            ->fetchAll(PDO::FETCH_ASSOC);

        echo "         Indexes found: " . count($indexes) . PHP_EOL;

        foreach ($indexes as $index) {

            echo "         - " .
                ($index['name'] ?? 'unknown') .
                PHP_EOL;
        }

    } else {

        echo "         Index inspection depends on database driver." . PHP_EOL;
    }

    return true;
});


/*
|--------------------------------------------------------------------------
| TEST 21 - FOREIGN KEY CHECK
|--------------------------------------------------------------------------
*/

test('Foreign key configuration', function () use (&$db): bool {

    $driver = $db->getAttribute(PDO::ATTR_DRIVER_NAME);

    if ($driver === 'sqlite') {

        $foreignKeys = $db
            ->query('PRAGMA foreign_keys')
            ->fetchColumn();

        echo "         Foreign keys: {$foreignKeys}" . PHP_EOL;
    }

    return true;
});


/*
|--------------------------------------------------------------------------
| CLEANUP
|--------------------------------------------------------------------------
*/

title('CLEANUP');

try {

    $db->exec(
        'DROP TABLE IF EXISTS ' . TEST_TABLE
    );

    echo "  [CLEAN] Temporary table removed." . PHP_EOL;

} catch (Throwable $e) {

    echo "  [WARNING] Could not remove test table." . PHP_EOL;
    echo "            {$e->getMessage()}" . PHP_EOL;
}


/*
|--------------------------------------------------------------------------
| FINAL RESULTS
|--------------------------------------------------------------------------
*/

title('DATABASE TEST RESULTS');

$total = $passed + $failed;

echo "  Passed : {$passed}" . PHP_EOL;
echo "  Failed : {$failed}" . PHP_EOL;
echo "  Total  : {$total}" . PHP_EOL;

echo PHP_EOL;

if ($failed > 0) {

    echo "  DATABASE TESTS FAILED." . PHP_EOL;
    echo "  Review the [FAIL] messages above." . PHP_EOL;

    exit(1);
}

echo "  ALL DATABASE TESTS PASSED." . PHP_EOL;

exit(0);
?>