<?php

declare(strict_types=1);

/**
 * KitePHP Database Stress Test
 *
 * Simulates concurrent traffic against the database layer and reports
 * throughput/latency — a load test, not a correctness test (see
 * tests/database_test.php for that).
 *
 * Run:
 *
 *     php tests/database_stress.php --records=10000 --users=50 --duration=30
 *
 * Flags (all optional):
 *
 *     --records=N    Rows to seed before the timed run starts. Default 1000.
 *     --users=N      Virtual users. Default 10.
 *     --duration=N   How long the timed run lasts, in seconds. Default 10.
 *     --quiet        Suppress the per-operation "User NNN -> ..." lines and
 *                    only print the final report.
 *
 * IMPORTANT — what "virtual users" actually means here:
 * Plain PHP CLI has no pcntl/proc_open on most shared hosts (InfinityFree
 * included), so this can't spawn real concurrent processes, and SQLite is
 * a single-writer database anyway — genuine concurrent writers would just
 * serialize on a lock. "50 concurrent users" is simulated as round-robin
 * interleaving in a single process: each tick of the loop hands the next
 * operation to the next user in sequence, cycling through all of them for
 * the full duration. This still exercises the same query patterns and
 * volume real concurrent traffic would generate, and if the live site is
 * ALSO receiving real traffic while this runs, genuine lock contention
 * between this script and those other requests is exactly what you'd see
 * reflected in the failure/error counts below.
 *
 * IMPORTANT — what this actually touches:
 * Exactly like tests/database_test.php, this creates its own disposable
 * table (kitephp_stress_test), never your real tables, and drops it when
 * finished (including on Ctrl+C, via a shutdown handler). "GET /posts",
 * "GET /users", "GET /dashboard", "CREATE post", "UPDATE user" are labels
 * describing the SHAPE of each simulated query (a single-row lookup, a
 * paginated listing, a composite dashboard read, ...), not literal routes
 * or your actual posts/users tables — this script doesn't know your real
 * schema, so it never touches it.
 */

// BASE_PATH + the class autoloader (same two lines index.php uses, and the
// same two lines tests/database_test.php uses).
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

const STRESS_TABLE = 'kitephp_stress_test';

function parseArgs(array $argv): array
{
    $out = [];

    foreach ($argv as $arg) {
        if ($arg === '--quiet') {
            $out['quiet'] = true;
            continue;
        }

        if (preg_match('/^--([a-zA-Z0-9_]+)=(.*)$/', $arg, $m)) {
            $out[$m[1]] = $m[2];
        }
    }

    return $out;
}

$options = parseArgs($argv);

$records  = max(1, (int) ($options['records'] ?? 1000));
$users    = max(1, (int) ($options['users'] ?? 10));
$duration = max(1, (int) ($options['duration'] ?? 10));
$quiet    = (bool) ($options['quiet'] ?? false);


/*
|--------------------------------------------------------------------------
| Console Helpers
|--------------------------------------------------------------------------
*/

function line(string $char = '='): void
{
    echo str_repeat($char, 60) . PHP_EOL;
}

function title(string $text): void
{
    echo PHP_EOL;
    line();
    echo "  {$text}" . PHP_EOL;
    line();
    echo PHP_EOL;
}

function row(string $label, string $value): void
{
    echo str_pad($label, 20) . ': ' . $value . PHP_EOL;
}


/*
|--------------------------------------------------------------------------
| Database Connection
|--------------------------------------------------------------------------
*/

title('KitePHP DATABASE STRESS TEST');

row('Records', number_format($records));
row('Virtual users', (string) $users);
row('Duration', "{$duration} sec");
echo PHP_EOL;

try {
    $manager = Database::instance();
    $db      = $manager->pdo();
} catch (Throwable $e) {
    fwrite(STDERR, 'Could not connect to the database — ' . $e->getMessage() . PHP_EOL);
    exit(1);
}


/*
|--------------------------------------------------------------------------
| Cleanup (always runs — Ctrl+C included)
|--------------------------------------------------------------------------
*/

register_shutdown_function(function () use ($db): void {
    try {
        $db->exec('DROP TABLE IF EXISTS ' . STRESS_TABLE);
    } catch (Throwable $e) {
        // Best-effort — if the connection itself is gone there's nothing
        // left to clean up.
    }
});


/*
|--------------------------------------------------------------------------
| Table Setup
|--------------------------------------------------------------------------
*/

$db->exec('DROP TABLE IF EXISTS ' . STRESS_TABLE);

$db->exec('
    CREATE TABLE ' . STRESS_TABLE . ' (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title VARCHAR(150) NOT NULL,
        body TEXT NOT NULL,
        author VARCHAR(60) NOT NULL,
        status VARCHAR(20) NOT NULL DEFAULT \'draft\',
        views INTEGER NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL,
        updated_at DATETIME NOT NULL
    )
');


/*
|--------------------------------------------------------------------------
| Seed Records
|--------------------------------------------------------------------------
*/

echo "Seeding {$records} record(s)..." . PHP_EOL;

$seedStart = microtime(true);

$db->beginTransaction();

try {
    $insert = $db->prepare(
        'INSERT INTO ' . STRESS_TABLE . '
        (title, body, author, status, views, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    for ($i = 1; $i <= $records; $i++) {
        $now = date('Y-m-d H:i:s');

        $insert->execute([
            'Post ' . $i,
            'Body text for post ' . $i,
            'author' . (($i % 25) + 1),
            $i % 7 === 0 ? 'draft' : 'published',
            random_int(0, 500),
            $now,
            $now,
        ]);
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }

    fwrite(STDERR, 'Seeding failed — ' . $e->getMessage() . PHP_EOL);
    exit(1);
}

$seedElapsed = microtime(true) - $seedStart;

echo '  done in ' . number_format($seedElapsed, 2) . ' sec' . PHP_EOL;
echo PHP_EOL;


/*
|--------------------------------------------------------------------------
| Stress Loop
|--------------------------------------------------------------------------
*/

/**
 * Relative weights for the operation mix — roughly modeled on typical
 * read-heavy web traffic. Adjust these if your real workload skews
 * differently (e.g. an import-heavy admin tool would want more insert/update).
 */
const OPERATION_WEIGHTS = [
    'select' => 35, // single-row lookup:      GET /posts/{id}
    'page'   => 30, // paginated/composite read: GET /posts, GET /users, GET /dashboard
    'insert' => 12, // CREATE post
    'update' => 13, // UPDATE user
    'delete' => 4,  // DELETE post
    'count'  => 6,  // COUNT posts
];

function pickOperation(): string
{
    $roll = random_int(1, 100);
    $sum  = 0;

    foreach (OPERATION_WEIGHTS as $operation => $weight) {
        $sum += $weight;
        if ($roll <= $sum) {
            return $operation;
        }
    }

    return 'select'; // unreachable in practice — weights sum to 100
}

$stats = [
    'total'      => 0,
    'success'    => 0,
    'failed'     => 0,
    'db_errors'  => 0,
    'created'    => 0,
    'updated'    => 0,
    'deleted'    => 0,
    'by_op'      => ['select' => 0, 'page' => 0, 'insert' => 0, 'update' => 0, 'delete' => 0, 'count' => 0],
    'total_time' => 0.0,
    'fastest'    => null,
    'slowest'    => null,
];

$maxId       = $records;
$pageTypes   = ['posts', 'users', 'dashboard'];
$preparedSql = [
    'select_by_id'    => $db->prepare('SELECT * FROM ' . STRESS_TABLE . ' WHERE id = ?'),
    'page'            => $db->prepare('SELECT * FROM ' . STRESS_TABLE . ' ORDER BY id DESC LIMIT 20 OFFSET ?'),
    'users_style'     => $db->prepare('SELECT * FROM ' . STRESS_TABLE . ' WHERE status = ? ORDER BY id ASC LIMIT 20 OFFSET ?'),
    'dashboard_count' => $db->prepare('SELECT COUNT(*) FROM ' . STRESS_TABLE),
    'dashboard_recent' => $db->prepare('SELECT * FROM ' . STRESS_TABLE . ' ORDER BY id DESC LIMIT 5'),
    'insert'          => $db->prepare(
        'INSERT INTO ' . STRESS_TABLE . '
        (title, body, author, status, views, created_at, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?)'
    ),
    'update'          => $db->prepare(
        'UPDATE ' . STRESS_TABLE . ' SET views = views + 1, updated_at = ? WHERE id = ?'
    ),
    'delete'          => $db->prepare('DELETE FROM ' . STRESS_TABLE . ' WHERE id = ?'),
    'count'           => $db->prepare('SELECT COUNT(*) FROM ' . STRESS_TABLE),
];

/**
 * Runs one operation and returns its display label. Throws on a genuine
 * database error — the caller records that as a failure and moves on.
 */
function performOperation(string $operation, PDO $db, array $prepared, int &$maxId): string
{
    switch ($operation) {
        case 'select':
            $id = random_int(1, $maxId);
            $prepared['select_by_id']->execute([$id]);
            $prepared['select_by_id']->fetch();
            return "GET /posts/{$id}";

        case 'page':
            $pageStyle = ['posts', 'users', 'dashboard'][random_int(0, 2)];

            if ($pageStyle === 'dashboard') {
                $prepared['dashboard_count']->execute();
                $prepared['dashboard_count']->fetchColumn();
                $prepared['dashboard_recent']->execute();
                $prepared['dashboard_recent']->fetchAll();
                return 'GET /dashboard';
            }

            if ($pageStyle === 'users') {
                $offset = random_int(0, max(0, $maxId - 20));
                $prepared['users_style']->execute(['published', $offset]);
                $prepared['users_style']->fetchAll();
                return 'GET /users';
            }

            $offset = random_int(0, max(0, $maxId - 20));
            $prepared['page']->execute([$offset]);
            $prepared['page']->fetchAll();
            return 'GET /posts';

        case 'insert':
            $now = date('Y-m-d H:i:s');
            $prepared['insert']->execute([
                'Stress post ' . ($maxId + 1),
                'Generated during the stress run.',
                'author' . random_int(1, 25),
                'published',
                0,
                $now,
                $now,
            ]);
            $maxId++;
            return 'CREATE post';

        case 'update':
            $id = random_int(1, $maxId);
            $prepared['update']->execute([date('Y-m-d H:i:s'), $id]);
            return 'UPDATE user';

        case 'delete':
            $id = random_int(1, $maxId);
            $prepared['delete']->execute([$id]);
            return 'DELETE post';

        case 'count':
        default:
            $prepared['count']->execute();
            $prepared['count']->fetchColumn();
            return 'COUNT posts';
    }
}

echo 'Starting...' . PHP_EOL;
echo PHP_EOL;

$runStart   = microtime(true);
$deadline   = $runStart + $duration;
$currentUser = 0;
$printed    = 0;
$printLimit = 2000; // avoid flooding the terminal on very high-throughput runs

while (microtime(true) < $deadline) {
    $currentUser = ($currentUser % $users) + 1;
    $operation   = pickOperation();

    $opStart = microtime(true);

    try {
        $label = performOperation($operation, $db, $preparedSql, $maxId);
        $ok    = true;
    } catch (Throwable $e) {
        $label = $operation . ' (error: ' . $e->getMessage() . ')';
        $ok    = false;
    }

    $elapsedMs = (microtime(true) - $opStart) * 1000;

    $stats['total']++;
    $stats['total_time'] += $elapsedMs;
    $stats['by_op'][$operation]++;

    if ($stats['fastest'] === null || $elapsedMs < $stats['fastest']) {
        $stats['fastest'] = $elapsedMs;
    }
    if ($stats['slowest'] === null || $elapsedMs > $stats['slowest']) {
        $stats['slowest'] = $elapsedMs;
    }

    if ($ok) {
        $stats['success']++;

        if ($operation === 'insert') {
            $stats['created']++;
        } elseif ($operation === 'update') {
            $stats['updated']++;
        } elseif ($operation === 'delete') {
            $stats['deleted']++;
        }
    } else {
        $stats['failed']++;
        $stats['db_errors']++;
    }

    if (!$quiet && $printed < $printLimit) {
        echo sprintf('User %03d -> %s', $currentUser, $label) . PHP_EOL;
        $printed++;

        if ($printed === $printLimit) {
            echo "... output capped at {$printLimit} lines to avoid flooding the terminal;";
            echo ' pass --quiet next time to skip these and go straight to the summary.' . PHP_EOL;
        }
    }
}

$actualDuration = microtime(true) - $runStart;

// SQLite refuses schema-altering statements (our cleanup DROP TABLE below)
// while any prepared statement handle still references the table, even one
// whose result set was already fully fetched. Close every cursor and drop
// the references before touching the table again, or the cleanup step
// fails with "database table is locked".
foreach ($preparedSql as $preparedStatement) {
    $preparedStatement->closeCursor();
}
unset($preparedSql);


/*
|--------------------------------------------------------------------------
| Results
|--------------------------------------------------------------------------
*/

echo PHP_EOL;
line();
echo '  STRESS TEST RESULTS' . PHP_EOL;
line();
echo PHP_EOL;

$opsPerSecond   = $actualDuration > 0 ? $stats['total'] / $actualDuration : 0;
$averageMs      = $stats['total'] > 0 ? $stats['total_time'] / $stats['total'] : 0;

row('Virtual users', (string) $users);
row('Initial records', number_format($records));
row('Test duration', number_format($actualDuration, 1) . ' sec');
row('Total operations', number_format($stats['total']));
row('Successful', number_format($stats['success']));
row('Failed', number_format($stats['failed']));
row('Operations/sec', number_format($opsPerSecond, 2));
row('Average response', number_format($averageMs, 1) . ' ms');
row('Fastest', number_format($stats['fastest'] ?? 0, 1) . ' ms');
row('Slowest', number_format($stats['slowest'] ?? 0, 1) . ' ms');
echo PHP_EOL;

row('SELECT', number_format($stats['by_op']['select']));
row('PAGE-STYLE', number_format($stats['by_op']['page']));
row('INSERT', number_format($stats['by_op']['insert']));
row('UPDATE', number_format($stats['by_op']['update']));
row('DELETE', number_format($stats['by_op']['delete']));
row('COUNT', number_format($stats['by_op']['count']));
echo PHP_EOL;

row('Database errors', number_format($stats['db_errors']));
row('Records created', number_format($stats['created']));
row('Records updated', number_format($stats['updated']));
row('Records deleted', number_format($stats['deleted']));
echo PHP_EOL;


/*
|--------------------------------------------------------------------------
| Cleanup
|--------------------------------------------------------------------------
*/

$db->exec('DROP TABLE IF EXISTS ' . STRESS_TABLE);
echo '[CLEAN] Temporary table removed.' . PHP_EOL;

exit(0);