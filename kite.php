#!/usr/bin/env php
<?php

/**
 * KitePHP CLI
 *
 * Usage:
 *   php kite.php make:controller {ControllerName}
 *   php kite.php make:controller {ControllerName} --force   (overwrite existing files)
 *
 * This is a thin wrapper around App\Services\KiteConsole, which also powers
 * the in-browser console on the Database page — the two run identical code,
 * so a command behaves the same way from a terminal or from the browser.
 *
 * Run this from the project root (the folder that contains app/ and routes/).
 */

require __DIR__ . '/app/Services/KiteConsole.php';

use App\Services\KiteConsole;

$args = $_SERVER['argv'] ?? [];
array_shift($args); // drop the script name itself

$console = new KiteConsole(__DIR__);
$result  = $console->runArgs($args);

$stream = $result['ok'] ? STDOUT : STDERR;
fwrite($stream, rtrim($result['output'], "\n") . "\n");

exit($result['ok'] ? 0 : 1);
