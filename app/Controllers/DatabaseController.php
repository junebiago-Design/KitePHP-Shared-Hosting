<?php

namespace App\Controllers;

use App\Models\DatabaseManager;
use App\Services\DatabaseCli;
use App\Services\KiteConsole;
use Core\Controller;
use Core\Response;
use Throwable;

/**
 * Database manager.
 *
 * One HTML page plus a small JSON API, both pointed at the SQLite file
 * configured in config/config.php ('database' => ['driver' => 'sqlite', 'path' => ...]).
 *
 * Every route is protected in routes/web.php with M::can('database.manage').
 */
class DatabaseController extends Controller
{
    protected ?DatabaseManager $manager = null;

    // ---------------------------------------------------------
    // Page
    // ---------------------------------------------------------

    /** GET /database */
    public function index(): Response
    {
        $error = null;
        $info  = [];

        try {
            $info = $this->db()->info();
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }

        return $this->view('database/index', [
            'title' => 'Database',
            'info'  => $info,
            'error' => $error,
        ]);
    }

    // ---------------------------------------------------------
    // Tables
    // ---------------------------------------------------------

    /** GET /database/api/tables */
    public function apiTables(): Response
    {
        return $this->handle(fn() => [
            'info'   => $this->db()->info(),
            'tables' => $this->db()->listTables(),
        ]);
    }

    /** POST /database/api/tables */
    public function apiCreateTable(): Response
    {
        return $this->handle(function () {
            $input   = $this->payload();
            $columns = $input['columns'] ?? [];

            if (is_string($columns)) {
                $columns = json_decode($columns, true) ?: [];
            }

            $this->db()->createTable((string) ($input['name'] ?? ''), (array) $columns);

            return ['message' => 'Table created.'];
        }, 201);
    }

    /** GET /database/api/tables/{table} */
    public function apiSchema(string $table): Response
    {
        return $this->handle(fn() => $this->db()->schema($table));
    }

    /** POST /database/api/tables/{table}/columns */
    public function apiAddColumn(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $this->db()->addColumn($table, $this->payload());

            return ['message' => 'Column added.'];
        }, 201);
    }

    /** DELETE /database/api/tables/{table} */
    public function apiDropTable(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $this->db()->dropTable($table);

            return ['message' => "Table {$table} dropped."];
        });
    }

    /** POST /database/api/tables/{table}/empty */
    public function apiEmptyTable(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $rows = $this->db()->emptyTable($table);

            return ['message' => "{$rows} row(s) deleted.", 'rows' => $rows];
        });
    }

    // ---------------------------------------------------------
    // Rows
    // ---------------------------------------------------------

    /** GET /database/api/tables/{table}/rows */
    public function apiRows(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $result = $this->db()->rows(
                $table,
                (int) ($_GET['limit'] ?? 25),
                (int) ($_GET['offset'] ?? 0),
                trim((string) ($_GET['search'] ?? ''))
            );

            $result['columns'] = $this->db()->columns($table);

            return $result;
        });
    }

    /** POST /database/api/tables/{table}/rows */
    public function apiInsertRow(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $input = $this->payload();
            $id    = $this->db()->insert($table, (array) ($input['data'] ?? []));

            return ['message' => 'Row added.', 'id' => $id];
        }, 201);
    }

    /** PUT /database/api/tables/{table}/rows */
    public function apiUpdateRow(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $input = $this->payload();
            $rows  = $this->db()->update(
                $table,
                (array) ($input['data'] ?? []),
                (array) ($input['where'] ?? [])
            );

            return ['message' => 'Row updated.', 'rows' => $rows];
        });
    }

    /** DELETE /database/api/tables/{table}/rows */
    public function apiDeleteRow(string $table): Response
    {
        return $this->handle(function () use ($table) {
            $input = $this->payload();
            $rows  = $this->db()->delete($table, (array) ($input['where'] ?? []));

            return ['message' => 'Row deleted.', 'rows' => $rows];
        });
    }

    // ---------------------------------------------------------
    // SQL console
    // ---------------------------------------------------------

    /** POST /database/api/sql */
    public function apiSql(): Response
    {
        return $this->handle(function () {
            $input = $this->payload();

            return ['results' => $this->db()->runSql((string) ($input['sql'] ?? ''))];
        });
    }

    // ---------------------------------------------------------
    // kite.php console
    // ---------------------------------------------------------

    /** POST /database/api/cli */
    public function apiCli(): Response
    {
        $input   = $this->payload();
        $command = trim((string) ($input['command'] ?? ''));

        if ($command === '') {
            return $this->json(['success' => true, 'ok' => false, 'output' => 'Type a command.']);
        }

        try {
            $first  = strtolower((string) strtok($command, " \t"));
            $dbCli  = new DatabaseCli();

            // status/migrate/backup/verify/test go to DatabaseCli; anything
            // else (the make:* commands) goes to KiteConsole, which reports
            // its own "unknown command" if it doesn't recognize it either.
            $result = in_array($first, $dbCli->availableCommands(), true)
                ? $dbCli->run($command)
                : (new KiteConsole())->run($command);

            return $this->json([
                'success' => true,
                'ok'      => $result['ok'],
                'output'  => $result['output'],
            ]);
        } catch (Throwable $e) {
            // A genuine PHP-level failure (not a normal "command failed" outcome,
            // which both services already report through 'ok' => false above).
            return $this->json(['success' => false, 'error' => $e->getMessage()], 500);
        }
    }

    // ---------------------------------------------------------
    // Internals
    // ---------------------------------------------------------

    protected function db(): DatabaseManager
    {
        return $this->manager ??= new DatabaseManager();
    }

    /**
     * Runs a callback and turns its return value — or any thrown error —
     * into a JSON response.
     */
    protected function handle(callable $callback, int $status = 200): Response
    {
        try {
            return $this->json(['success' => true] + $callback(), $status);
        } catch (Throwable $e) {
            return $this->json([
                'success' => false,
                'error'   => $e->getMessage(),
            ], 400);
        }
    }

    /**
     * Reads the request body, whether it arrives as JSON or as form fields.
     */
    protected function payload(): array
    {
        $raw = file_get_contents('php://input');

        if (is_string($raw) && $raw !== '') {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                return $decoded;
            }
        }

        return $_POST;
    }
}
