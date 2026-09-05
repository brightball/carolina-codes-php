<?php
/**
 * Process entry: register once, then the PHP built-in SAPI.
 */
require __DIR__ . '/carolina.php';

$port = getenv('PORT') ?: '4021';
register_with_elixir();
$router = __DIR__ . '/router.php';
$bind = '[::]:' . $port;
fwrite(STDERR, "carolina-codes-php listening on {$bind}\n");
passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($bind) . ' ' . escapeshellarg($router));
