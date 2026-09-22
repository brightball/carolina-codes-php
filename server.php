<?php

/**
 * Process entry. The built-in SAPI binds before any registration I/O so a
 * hung Elixir callback cannot delay /health. Registration still runs once
 * per process and does not open Postgres.
 *
 * pcntl_exec replaces this process so a pidfile kill is the listener.
 * Without pcntl, the listener is a child and registration stays in this process.
 */
require __DIR__ . '/carolina.php';

/**
 * opcache.enable_cli is read at process start. ini_set cannot turn it on.
 *
 * @return list<string>
 */
function server_sapi_args(string $bind, string $router): array
{
    return [
        '-d', 'opcache.enable=1',
        '-d', 'opcache.enable_cli=1',
        '-d', 'opcache.jit=disable',
        '-d', 'opcache.jit_buffer_size=0',
        '-d', 'opcache.validate_timestamps=0',
        '-d', 'opcache.file_update_protection=0',
        '-S', $bind,
        $router,
    ];
}

$port = getenv('PORT') ?: '4021';
$router = __DIR__ . '/router.php';
$bind = '[::]:' . $port;
$args = server_sapi_args($bind, $router);
fwrite(STDERR, "carolina-codes-php listening on {$bind}\n");

if (function_exists('pcntl_fork') && function_exists('pcntl_exec')) {
    $starter = pcntl_fork();
    if ($starter === 0) {
        $worker = pcntl_fork();
        if ($worker === 0) {
            register_with_elixir();
            exit(0);
        }
        exit(0);
    }
    if ($starter > 0) {
        $status = 0;
        pcntl_waitpid($starter, $status);
        pcntl_exec(PHP_BINARY, $args);
        fwrite(STDERR, "pcntl_exec failed to start php -S\n");
    }
}

$command = array_merge([PHP_BINARY], $args);
$proc = proc_open($command, [0 => ['pipe', 'r'], 1 => STDOUT, 2 => STDERR], $pipes);
if (!is_resource($proc)) {
    fwrite(STDERR, "failed to start php -S\n");
    exit(1);
}
register_with_elixir();
$exitCode = proc_close($proc);
exit($exitCode);
