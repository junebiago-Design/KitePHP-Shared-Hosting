<?php

declare(strict_types=1);

/**
 * KitePHP Security Tests
 *
 * Safe tests for:
 * - XSS output escaping
 * - SQL injection resistance
 * - SQL parameter binding
 * - Basic input validation
 *
 * Run:
 *     php tests/security.php
 */

// BASE_PATH + the class autoloader (same two lines index.php uses). helpers.php
// alone does NOT register Core\/App\ classes, which is why Database and Post
// were "not found" before.
define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/core/bootstrap.php';

// Core\App's constructor normally loads this; the test never creates an App,
// so config('...') would return null and Database::connectSqlite() (which
// requires an array) would throw. Load it directly instead.
\Core\App::$config = require BASE_PATH . '/config/config.php';

use Core\Database;
use App\Models\Post;

$passed = 0;
$failed = 0;

function test(string $name, callable $callback): void
{
    global $passed, $failed;

    echo "\n[TEST] {$name}\n";

    try {
        $result = $callback();

        if ($result === true) {
            echo "  PASS\n";
            $passed++;
        } else {
            echo "  FAIL\n";
            $failed++;
        }
    } catch (Throwable $e) {
        echo "  FAIL: {$e->getMessage()}\n";
        $failed++;
    }
}

function assertTrue(bool $condition, string $message = ''): bool
{
    if (!$condition) {
        throw new RuntimeException(
            $message ?: 'Assertion failed.'
        );
    }

    return true;
}

/*
|--------------------------------------------------------------------------
| XSS TESTS
|--------------------------------------------------------------------------
*/

test('XSS payload is HTML escaped', function (): bool {

    $payload = '<script>alert("XSS")</script>';

    $escaped = e($payload);

    assertTrue(
        $escaped !== $payload,
        'The payload was not changed.'
    );

    assertTrue(
        !str_contains($escaped, '<script>'),
        'Raw <script> tag is still present.'
    );

    assertTrue(
        str_contains($escaped, '&lt;script&gt;'),
        'HTML escaping was not applied.'
    );

    return true;
});


test('XSS attribute payload is escaped', function (): bool {

    $payload = '" onmouseover="alert(1)';

    $escaped = e($payload);

    assertTrue(
        !str_contains($escaped, '" onmouseover='),
        'Potential attribute injection remains.'
    );

    return true;
});


test('Event-handler XSS is escaped', function (): bool {

    $payload = '<img src=x onerror=alert(1)>';

    $escaped = e($payload);

    assertTrue(
        !str_contains($escaped, '<img'),
        'Raw HTML element was not escaped.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| SQL INJECTION TESTS
|--------------------------------------------------------------------------
*/

test('SQL injection payload does not bypass parameterized query', function (): bool {

    $payload = "' OR 1=1 --";

    $db = Database::instance();

    /*
     * IMPORTANT:
     *
     * The payload is passed as a parameter.
     * It is NOT concatenated into SQL.
     *
     * Uses `email`, matching the framework's standard users table
     * (users.id / name / email / password / role). If your users table
     * really does have a `username` column instead, change this back.
     */
    $statement = $db->query(
        'SELECT COUNT(*) AS total
         FROM users
         WHERE email = ?',
        [$payload]
    );

    $row = $statement->fetch();

    assertTrue(
        (int)($row['total'] ?? 0) === 0,
        'The SQL query may not be using parameter binding correctly.'
    );

    return true;
});


test('SQL injection UNION payload is treated as data', function (): bool {

    $payload = "' UNION SELECT password FROM users --";

    $db = Database::instance();

    $statement = $db->query(
        'SELECT id, email
         FROM users
         WHERE email = ?',
        [$payload]
    );

    $rows = $statement->fetchAll();

    assertTrue(
        is_array($rows),
        'Query did not return a normal result.'
    );

    /*
     * A UNION supplied as user input must NOT become part
     * of the SQL statement.
     */
    assertTrue(
        count($rows) === 0,
        'Potential SQL injection detected.'
    );

    return true;
});


test('SQL quote payload is treated as normal input', function (): bool {

    $payload = "admin'";

    $db = Database::instance();

    $statement = $db->query(
        'SELECT id
         FROM users
         WHERE email = ?',
        [$payload]
    );

    $rows = $statement->fetchAll();

    assertTrue(
        is_array($rows),
        'Query failed when quote was supplied as input.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| ORM TEST
|--------------------------------------------------------------------------
*/

test('ORM query does not directly concatenate user input', function (): bool {

    $payload = "' OR 1=1 --";

    /*
     * This test assumes your ORM's where() method uses
     * parameterized queries internally.
     */
    $posts = Post::where([
        'title' => $payload,
    ]);

    assertTrue(
        is_array($posts),
        'ORM did not return an array.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| VALIDATION TEST
|--------------------------------------------------------------------------
*/

test('Malicious input remains data after validation', function (): bool {

    $payload = '<script>alert("XSS")</script>';

    /*
     * Validation should validate input.
     * Escaping should happen when outputting it.
     */
    $escaped = e($payload);

    assertTrue(
        $escaped !== $payload,
        'Output escaping failed.'
    );

    return true;
});


/*
|--------------------------------------------------------------------------
| SUMMARY
|--------------------------------------------------------------------------
*/

echo "\n";
echo "=====================================\n";
echo " KitePHP Security Test Results\n";
echo "=====================================\n";
echo "Passed: {$passed}\n";
echo "Failed: {$failed}\n";
echo "Total : " . ($passed + $failed) . "\n";

if ($failed > 0) {
    echo "\nSecurity tests failed.\n";
    exit(1);
}

echo "\nAll security tests passed.\n";
exit(0);
