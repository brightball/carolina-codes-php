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
// Replace this process so stop-polyglot-apis.sh's pidfile kill is the listener
// (passthru would leave php -S as an orphan after SIGTERM of server.php).
$args = ['-S', $bind, $router];
if (function_exists('pcntl_exec')) {
    pcntl_exec(PHP_BINARY, $args);
    fwrite(STDERR, "pcntl_exec failed to start php -S\n");
}
passthru(escapeshellarg(PHP_BINARY) . ' -S ' . escapeshellarg($bind) . ' ' . escapeshellarg($router));
exit(1);
