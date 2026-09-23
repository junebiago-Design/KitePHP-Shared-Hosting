#!/usr/bin/env php
<?php

/**
 * KitePHP Database Utility
 *
 * Usage:
 *   php database.php status
 *   php database.php migrate
 *   php database.php backup
 *   php database.php backup --label before-feature
 *   php database.php test
 *   php database.php verify
 *
 * This is a thin wrapper around App\Services\DatabaseCli, which also powers
 * the in-browser console on the Database page — the two run identical code,
 * so a command behaves the same way from a terminal or from the browser.
 *
 * Migrations are forward-only. A rollback command is intentionally not
 * included because the current migration format has no down() operation.
 *
 * Run this from the project root (the folder that contains app/ and routes/).
 */

require __DIR__ . '/app/Services/DatabaseCli.php';

use App\Services\DatabaseCli;

$args = $_SERVER['argv'] ?? [];
array_shift($args); // drop the script name itself

if (!($args[0] ?? null)) {
    fwrite(STDOUT, "KitePHP Database Utility\n\n");
    fwrite(STDOUT, "Commands:\n");
    fwrite(STDOUT, "  status                         Show migration status\n");
    fwrite(STDOUT, "  migrate                        Apply pending migrations\n");
    fwrite(STDOUT, "  backup [--label NAME]          Create a SQLite backup\n");
    fwrite(STDOUT, "  verify                         Run SQLite integrity checks\n");
    fwrite(STDOUT, "  test                           Check migration files are well-formed\n\n");
    fwrite(STDOUT, "Examples:\n");
    fwrite(STDOUT, "  php database.php status\n");
    fwrite(STDOUT, "  php database.php migrate\n");
    fwrite(STDOUT, "  php database.php backup --label before-users-change\n");
    fwrite(STDOUT, "  php database.php test\n");
    exit(0);
}

$console = new DatabaseCli(__DIR__);
$result  = $console->runArgs($args);

$stream = $result['ok'] ? STDOUT : STDERR;
fwrite($stream, rtrim($result['output'], "\n") . "\n");

exit($result['ok'] ? 0 : 1);
