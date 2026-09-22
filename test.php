<?php

require __DIR__ . '/carolina.php';

$failed = 0;

function expect(bool $cond, string $msg): void
{
    global $failed;
    if ($cond) {
        fwrite(STDERR, "ok: {$msg}\n");
    } else {
        fwrite(STDERR, "FAIL: {$msg}\n");
        $failed++;
    }
}

/** @return list<string> */
function precommit_hook_ids(string $yaml): array
{
    preg_match_all('/^\s+- id:\s*([A-Za-z0-9_-]+)\s*$/m', $yaml, $m);
    return array_values($m[1]);
}

/** @return array<string, string> */
function gitea_job_bodies(string $yml): array
{
    $jobs = [];
    $inJobs = false;
    $current = null;
    $buf = [];
    foreach (explode("\n", $yml) as $line) {
        if (preg_match('/^jobs:\s*$/', $line)) {
            $inJobs = true;
            continue;
        }
        if ($inJobs) {
            if (preg_match('/^[A-Za-z]/', $line)) {
                break;
            }
            if (preg_match('/^  ([A-Za-z0-9_-]+):\s*$/', $line, $m)) {
                if ($current !== null) {
                    $jobs[$current] = implode("\n", $buf);
                }
                $current = $m[1];
                $buf = [];
                continue;
            }
            if ($current !== null) {
                $buf[] = $line;
            }
        }
    }
    if ($current !== null) {
        $jobs[$current] = implode("\n", $buf);
    }
    return $jobs;
}

/** @return list<string> */
function gitea_job_names(string $yml): array
{
    return array_keys(gitea_job_bodies($yml));
}

/** @return list<string> */
function makefile_phony_targets(string $makefile): array
{
    if (!preg_match('/^\.PHONY:\s*(.+)$/m', $makefile, $m)) {
        return [];
    }
    return preg_split('/\s+/', trim($m[1])) ?: [];
}

function yaml_without_comments(string $yml): string
{
    $out = [];
    foreach (explode("\n", $yml) as $line) {
        if (preg_match('/^\s*#/', $line)) {
            continue;
        }
        $out[] = $line;
    }
    return implode("\n", $out);
}

/** @return array<string, string> */
function current_process_env(): array
{
    $env = getenv();
    if (!is_array($env)) {
        $env = [];
    }
    foreach ($_ENV as $key => $value) {
        if (is_string($key) && is_string($value) && !isset($env[$key])) {
            $env[$key] = $value;
        }
    }
    foreach ($_SERVER as $key => $value) {
        if (is_string($key) && is_string($value) && !isset($env[$key])) {
            $env[$key] = $value;
        }
    }
    return $env;
}

/**
 * @param list<string> $args
 * @param array<string, string> $env
 * @return array{code: int, stdout: string, stderr: string}
 */
function run_ci_artifact(array $args, array $env): array
{
    $cmd = array_merge([PHP_BINARY, __DIR__ . '/scripts/ci_artifact.php'], $args);
    $proc = proc_open(
        $cmd,
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $env
    );
    if (!is_resource($proc)) {
        return ['code' => 127, 'stdout' => '', 'stderr' => 'proc_open failed'];
    }
    fclose($pipes[0]);
    $stdout = (string) stream_get_contents($pipes[1]);
    $stderr = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return ['code' => proc_close($proc), 'stdout' => $stdout, 'stderr' => $stderr];
}

final class FakeArtifactStore
{
    public string $token = 'test-token';
    public string $runId = '42';
    public string $publicOrigin = '';
    /** @var array<string, array{files: array<string, string>, confirmed: bool, ids: array<string, int>}> */
    public array $pending = [];
    /** @var array<int, array{0: string, 1: string}> */
    public array $blobs = [];
    public int $nextId = 100;
}

/** @param resource $conn */
function fake_artifact_read_http($conn): ?array
{
    $raw = '';
    while (!str_contains($raw, "\r\n\r\n") && !str_contains($raw, "\n\n")) {
        $chunk = fread($conn, 8192);
        if ($chunk === false || $chunk === '') {
            return null;
        }
        $raw .= $chunk;
        if (strlen($raw) > 1024 * 1024) {
            return null;
        }
    }
    if (str_contains($raw, "\r\n\r\n")) {
        [$head, $rest] = explode("\r\n\r\n", $raw, 2);
        $sep = "\r\n";
    } else {
        [$head, $rest] = explode("\n\n", $raw, 2);
        $sep = "\n";
    }
    $lines = explode($sep, $head);
    $req = array_shift($lines) ?? '';
    if (!preg_match('/^([A-Z]+)\s+(\S+)\s+HTTP\//', $req, $m)) {
        return null;
    }
    $headers = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $pos = strpos($line, ':');
        if ($pos === false) {
            continue;
        }
        $headers[strtolower(trim(substr($line, 0, $pos)))] = trim(substr($line, $pos + 1));
    }
    $len = (int) ($headers['content-length'] ?? 0);
    $body = $rest;
    while (strlen($body) < $len) {
        $chunk = fread($conn, $len - strlen($body));
        if ($chunk === false || $chunk === '') {
            break;
        }
        $body .= $chunk;
    }
    return [
        'method' => $m[1],
        'uri' => $m[2],
        'headers' => $headers,
        'body' => substr($body, 0, $len),
    ];
}

/** @param resource $conn */
function fake_artifact_write_http($conn, int $code, string $body, string $type = 'application/json'): void
{
    $msg = [200 => 'OK', 204 => 'No Content', 400 => 'Bad Request', 401 => 'Unauthorized', 404 => 'Not Found'][$code] ?? 'OK';
    $out = "HTTP/1.1 {$code} {$msg}\r\n";
    $out .= "Content-Type: {$type}\r\n";
    $out .= 'Content-Length: ' . strlen($body) . "\r\n";
    $out .= "Connection: close\r\n\r\n";
    $out .= $body;
    fwrite($conn, $out);
}

function fake_artifact_md5_name(string $name): string
{
    return md5($name);
}

function fake_artifact_query_value(array $query, string $key): string
{
    $value = $query[$key] ?? '';
    if (is_array($value)) {
        return (string) ($value[0] ?? '');
    }
    return (string) $value;
}

function fake_artifact_parse_path(string $uri): ?array
{
    $parsed = parse_url($uri);
    if (!is_array($parsed)) {
        return null;
    }
    $path = $parsed['path'] ?? '';
    if (!preg_match('#^/api/actions_pipeline/_apis/pipelines/workflows/([^/]+)/artifacts(?:/(.*))?$#', $path, $m)) {
        return null;
    }
    $query = [];
    if (!empty($parsed['query'])) {
        parse_str($parsed['query'], $query);
    }
    return [$m[1], $m[2] ?? '', $query];
}

function fake_artifact_public_url(FakeArtifactStore $store, string $path, string $query = ''): string
{
    $prefix = rtrim($store->publicOrigin, '/');
    return $query === '' ? $prefix . $path : $prefix . $path . '?' . $query;
}

/** @param resource $conn */
function fake_artifact_handle(FakeArtifactStore $store, $conn): void
{
    $req = fake_artifact_read_http($conn);
    if ($req === null) {
        return;
    }
    $expected = 'Bearer ' . $store->token;
    if (($req['headers']['authorization'] ?? '') !== $expected) {
        fake_artifact_write_http($conn, 401, 'Bad authorization header', 'text/plain');
        return;
    }
    $parsed = fake_artifact_parse_path($req['uri']);
    if ($parsed === null) {
        fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
        return;
    }
    [$run, $rest, $query] = $parsed;
    $method = $req['method'];
    if ($method === 'POST') {
        if ($rest !== '') {
            fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
            return;
        }
        if ($run !== $store->runId) {
            fake_artifact_write_http($conn, 400, 'run id mismatch', 'text/plain');
            return;
        }
        $payload = json_decode($req['body'] ?: '{}', true);
        $name = is_array($payload) ? (string) ($payload['Name'] ?? $payload['name'] ?? '') : '';
        if ($name === '') {
            fake_artifact_write_http($conn, 400, 'missing Name', 'text/plain');
            return;
        }
        $store->pending[$name] = ['files' => [], 'confirmed' => false, 'ids' => []];
        $retention = is_array($payload) ? ($payload['RetentionDays'] ?? $payload['retentionDays'] ?? '') : '';
        $q = $retention !== '' && $retention !== null ? 'retentionDays=' . $retention : '';
        $hash = fake_artifact_md5_name($name);
        fake_artifact_write_http($conn, 200, json_encode([
            'fileContainerResourceUrl' => fake_artifact_public_url(
                $store,
                "/api/actions_pipeline/_apis/pipelines/workflows/{$run}/artifacts/{$hash}/upload",
                $q
            ),
        ]));
        return;
    }
    if ($method === 'PUT') {
        if (!str_ends_with($rest, '/upload')) {
            fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
            return;
        }
        $itemPath = fake_artifact_query_value($query, 'itemPath');
        if (!str_contains($itemPath, '/')) {
            fake_artifact_write_http($conn, 400, 'itemPath', 'text/plain');
            return;
        }
        [$name, $rel] = explode('/', $itemPath, 2);
        $hash = explode('/', $rest)[0];
        if (fake_artifact_md5_name($name) !== $hash) {
            fake_artifact_write_http($conn, 400, 'Invalid artifact hash', 'text/plain');
            return;
        }
        $expectedMd5 = $req['headers']['x-actions-results-md5'] ?? '';
        $got = base64_encode(hash('md5', $req['body'], true));
        if ($expectedMd5 !== $got) {
            fake_artifact_write_http($conn, 400, 'md5 mismatch', 'text/plain');
            return;
        }
        $range = $req['headers']['content-range'] ?? '';
        if (!preg_match('/bytes (\d+)-(\d+)\/(\d+)$/', $range, $rm)) {
            fake_artifact_write_http($conn, 400, 'Content-Range', 'text/plain');
            return;
        }
        $start = (int) $rm[1];
        $end = (int) $rm[2];
        $total = (int) $rm[3];
        if (!isset($store->pending[$name])) {
            $store->pending[$name] = ['files' => [], 'confirmed' => false, 'ids' => []];
        }
        $buf = $store->pending[$name]['files'][$rel] ?? str_repeat("\0", $total);
        if (strlen($buf) !== $total) {
            $buf = str_repeat("\0", $total);
        }
        $store->pending[$name]['files'][$rel] = substr($buf, 0, $start) . $req['body'] . substr($buf, $end + 1);
        if (!isset($store->pending[$name]['ids'][$rel])) {
            $store->nextId++;
            $id = $store->nextId;
            $store->pending[$name]['ids'][$rel] = $id;
            $store->blobs[$id] = [$name, $rel];
        }
        fake_artifact_write_http($conn, 200, json_encode(['message' => 'success']));
        return;
    }
    if ($method === 'PATCH') {
        if ($rest !== '') {
            fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
            return;
        }
        $name = fake_artifact_query_value($query, 'artifactName');
        if (!isset($store->pending[$name])) {
            fake_artifact_write_http($conn, 400, 'artifact name is empty', 'text/plain');
            return;
        }
        $store->pending[$name]['confirmed'] = true;
        fake_artifact_write_http($conn, 200, json_encode(['message' => 'success']));
        return;
    }
    if ($method === 'GET') {
        if ($rest === '') {
            $items = [];
            foreach ($store->pending as $name => $meta) {
                if (!$meta['confirmed']) {
                    continue;
                }
                $items[] = [
                    'name' => $name,
                    'fileContainerResourceUrl' => fake_artifact_public_url(
                        $store,
                        '/api/actions_pipeline/_apis/pipelines/workflows/' . $run . '/artifacts/' . fake_artifact_md5_name($name) . '/download_url'
                    ),
                ];
            }
            if ($items === []) {
                fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
                return;
            }
            fake_artifact_write_http($conn, 200, json_encode(['count' => count($items), 'value' => $items]));
            return;
        }
        if (str_ends_with($rest, '/download_url')) {
            $name = fake_artifact_query_value($query, 'itemPath');
            $meta = $store->pending[$name] ?? null;
            if ($meta === null || !$meta['confirmed']) {
                fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
                return;
            }
            $values = [];
            foreach ($meta['ids'] as $rel => $fileId) {
                $values[] = [
                    'path' => $name . '/' . $rel,
                    'itemType' => 'file',
                    'contentLocation' => fake_artifact_public_url(
                        $store,
                        '/api/actions_pipeline/_apis/pipelines/workflows/' . $run . '/artifacts/' . $fileId . '/download'
                    ),
                ];
            }
            fake_artifact_write_http($conn, 200, json_encode(['value' => $values]));
            return;
        }
        if (str_ends_with($rest, '/download')) {
            $fileId = (int) explode('/', $rest)[0];
            $pair = $store->blobs[$fileId] ?? null;
            if ($pair === null) {
                fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
                return;
            }
            [$name, $rel] = $pair;
            $blob = $store->pending[$name]['files'][$rel] ?? '';
            fake_artifact_write_http($conn, 200, $blob, 'application/octet-stream');
            return;
        }
        fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
        return;
    }
    fake_artifact_write_http($conn, 404, 'not found', 'text/plain');
}

function fake_artifact_http_main(): void
{
    $store = new FakeArtifactStore();
    $store->token = getenv('FAKE_ARTIFACT_TOKEN') ?: 'test-token';
    $store->runId = getenv('FAKE_ARTIFACT_RUN_ID') ?: '42';
    $store->publicOrigin = (string) (getenv('FAKE_ARTIFACT_PUBLIC_ORIGIN') ?: '');
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "bind failed: {$errstr}\n");
        exit(1);
    }
    $name = stream_socket_get_name($server, false);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        fwrite(STDERR, "could not determine bound port\n");
        exit(1);
    }
    $port = $m[1];
    if ($store->publicOrigin === '') {
        $store->publicOrigin = "http://127.0.0.1:{$port}";
    }
    fwrite(STDOUT, "READY {$port}\n");
    fflush(STDOUT);
    stream_set_timeout($server, 1);
    while (true) {
        $conn = @stream_socket_accept($server, 1);
        if ($conn === false) {
            continue;
        }
        fake_artifact_handle($store, $conn);
        fclose($conn);
    }
}

/**
 * @param array{token?: string, run_id?: string, public_origin?: string} $opts
 * @return array{proc: resource, pipes: array, origin: string, env: array<string, string>}
 */
function start_fake_artifact_server(array $opts = []): array
{
    $token = $opts['token'] ?? 'test-token';
    $runId = $opts['run_id'] ?? '42';
    $publicOrigin = $opts['public_origin'] ?? '';
    $env = current_process_env();
    $env['FAKE_ARTIFACT_TOKEN'] = $token;
    $env['FAKE_ARTIFACT_RUN_ID'] = $runId;
    $env['FAKE_ARTIFACT_PUBLIC_ORIGIN'] = $publicOrigin;
    $proc = proc_open(
        [PHP_BINARY, __FILE__, '--fake-artifact-http'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $env
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "failed to start fake artifact server\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    $buf = '';
    $port = null;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $buf .= (string) stream_get_contents($pipes[1]);
        if (preg_match('/READY (\d+)/', $buf, $m)) {
            $port = $m[1];
            break;
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $err = (string) stream_get_contents($pipes[2]);
            fwrite(STDERR, "fake artifact server exited: {$err}\n");
            exit(1);
        }
        usleep(20000);
    }
    if ($port === null) {
        proc_terminate($proc);
        fwrite(STDERR, "fake artifact server did not become ready\n");
        exit(1);
    }
    $origin = "http://127.0.0.1:{$port}";
    return [
        'proc' => $proc,
        'pipes' => $pipes,
        'origin' => $origin,
        'env' => [
            'ACTIONS_RUNTIME_URL' => $origin . '/api/actions_pipeline/',
            'ACTIONS_RUNTIME_TOKEN' => $token,
            'GITHUB_RUN_ID' => $runId,
        ],
    ];
}

function stop_fake_artifact_server(array $server): void
{
    if (isset($server['pipes'][0]) && is_resource($server['pipes'][0])) {
        fclose($server['pipes'][0]);
    }
    if (isset($server['pipes'][1]) && is_resource($server['pipes'][1])) {
        fclose($server['pipes'][1]);
    }
    if (isset($server['pipes'][2]) && is_resource($server['pipes'][2])) {
        fclose($server['pipes'][2]);
    }
    if (isset($server['proc']) && is_resource($server['proc'])) {
        proc_terminate($server['proc']);
        proc_close($server['proc']);
    }
}

function stall_http_main(): void
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "bind failed: {$errstr}\n");
        exit(1);
    }
    $name = stream_socket_get_name($server, false);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        fwrite(STDERR, "could not determine bound port\n");
        exit(1);
    }
    fwrite(STDOUT, "READY {$m[1]}\n");
    fflush(STDOUT);
    $held = [];
    while (true) {
        $conn = @stream_socket_accept($server, 2);
        if ($conn === false) {
            continue;
        }
        stream_set_blocking($conn, false);
        fread($conn, 65536);
        $held[] = $conn;
    }
}

function prompt_http_main(): void
{
    $countFile = getenv('PROMPT_COUNT_FILE') ?: '';
    $logFile = getenv('PROMPT_LOG_FILE') ?: '';
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "bind failed: {$errstr}\n");
        exit(1);
    }
    $name = stream_socket_get_name($server, false);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        fwrite(STDERR, "could not determine bound port\n");
        exit(1);
    }
    fwrite(STDOUT, "READY {$m[1]}\n");
    fflush(STDOUT);
    $count = 0;
    while (true) {
        $conn = @stream_socket_accept($server, 2);
        if ($conn === false) {
            continue;
        }
        $req = fake_artifact_read_http($conn);
        if ($req === null) {
            fclose($conn);
            continue;
        }
        $count++;
        if ($countFile !== '') {
            file_put_contents($countFile, (string) $count);
        }
        if ($logFile !== '') {
            $method = is_string($req['method'] ?? null) ? $req['method'] : '';
            $uri = is_string($req['uri'] ?? null) ? $req['uri'] : '';
            $body = is_string($req['body'] ?? null) ? $req['body'] : '';
            file_put_contents($logFile, $method . ' ' . $uri . "\n" . $body . "\n", FILE_APPEND);
        }
        fake_artifact_write_http($conn, 204, '');
        fclose($conn);
    }
}

/**
 * @param array<string, string> $env
 * @return array{proc: resource, pipes: array<int, resource>, port: string}
 */
function start_mode_server(string $mode, array $env = []): array
{
    $procEnv = current_process_env();
    foreach ($env as $key => $value) {
        $procEnv[$key] = $value;
    }
    $proc = proc_open(
        [PHP_BINARY, __FILE__, $mode],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $procEnv
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "failed to start {$mode}\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    $buf = '';
    $port = null;
    $deadline = microtime(true) + 5;
    while (microtime(true) < $deadline) {
        $buf .= (string) stream_get_contents($pipes[1]);
        if (preg_match('/READY (\d+)/', $buf, $m)) {
            $port = $m[1];
            break;
        }
        $status = proc_get_status($proc);
        if (!$status['running']) {
            $err = (string) stream_get_contents($pipes[2]);
            fwrite(STDERR, "{$mode} exited: {$err}\n");
            exit(1);
        }
        usleep(20000);
    }
    if ($port === null) {
        proc_terminate($proc);
        fwrite(STDERR, "{$mode} did not become ready\n");
        exit(1);
    }

    return ['proc' => $proc, 'pipes' => $pipes, 'port' => $port];
}

function free_port(): int
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    if ($server === false) {
        fwrite(STDERR, "bind failed: {$errstr}\n");
        exit(1);
    }
    $name = stream_socket_get_name($server, false);
    fclose($server);
    if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $m)) {
        fwrite(STDERR, "could not determine free port\n");
        exit(1);
    }

    return (int) $m[1];
}

/** @return list<int> */
function descendant_pids(int $pid): array
{
    if ($pid <= 0) {
        return [];
    }
    $raw = @file_get_contents("/proc/{$pid}/task/{$pid}/children");
    if (!is_string($raw) || trim($raw) === '') {
        return [];
    }
    $out = [];
    foreach (preg_split('/\s+/', trim($raw)) ?: [] as $part) {
        if (!is_string($part) || !ctype_digit($part)) {
            continue;
        }
        $child = (int) $part;
        $out[] = $child;
        foreach (descendant_pids($child) as $nested) {
            $out[] = $nested;
        }
    }

    return $out;
}

function signal_pid(int $pid, int $sig): void
{
    if ($pid <= 1) {
        return;
    }
    if (function_exists('posix_kill')) {
        @posix_kill($pid, $sig);

        return;
    }
    exec('kill -' . $sig . ' ' . $pid . ' >/dev/null 2>&1');
}

function process_cmdline(int $pid): string
{
    $raw = @file_get_contents("/proc/{$pid}/cmdline");
    if (!is_string($raw) || $raw === '') {
        return '';
    }

    return trim(str_replace("\0", ' ', $raw));
}

function listening_cmdline(int $pid): string
{
    $commands = [process_cmdline($pid)];
    foreach (descendant_pids($pid) as $child) {
        $commands[] = process_cmdline($child);
    }
    foreach ($commands as $command) {
        if (str_contains($command, 'opcache.enable_cli=1') && str_contains($command, ' -S ')) {
            return $command;
        }
    }

    return implode(' || ', $commands);
}

/**
 * @param array{proc?: resource, pipes?: array<int, resource>, pid?: int} $server
 */
function stop_api_server(array $server): void
{
    $pid = (int) ($server['pid'] ?? 0);
    foreach (array_reverse(descendant_pids($pid)) as $child) {
        signal_pid($child, 15);
    }
    stop_fake_artifact_server($server);
    foreach (array_reverse(descendant_pids($pid)) as $child) {
        signal_pid($child, 9);
    }
}

/** @param array<int, resource> $pipes */
function drain_pipes(array $pipes): string
{
    $out = '';
    foreach ([1, 2] as $index) {
        if (isset($pipes[$index]) && is_resource($pipes[$index])) {
            $out .= (string) stream_get_contents($pipes[$index]);
        }
    }

    return $out;
}

/**
 * @return array{code: int, body: string, headers: array<string, string>, elapsed: float, error: string}
 */
function http_get(string $url, float $timeout): array
{
    $started = microtime(true);
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return ['code' => 0, 'body' => '', 'headers' => [], 'elapsed' => 0.0, 'error' => 'bad url'];
    }
    $host = is_string($parts['host'] ?? null) ? $parts['host'] : '127.0.0.1';
    $port = (int) ($parts['port'] ?? 80);
    $path = is_string($parts['path'] ?? null) ? $parts['path'] : '/';
    if (isset($parts['query']) && is_string($parts['query']) && $parts['query'] !== '') {
        $path .= '?' . $parts['query'];
    }
    $errno = 0;
    $errstr = '';
    $target = str_contains($host, ':') ? "tcp://[{$host}]:{$port}" : "tcp://{$host}:{$port}";
    $sock = @stream_socket_client($target, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if ($sock === false) {
        return [
            'code' => 0,
            'body' => '',
            'headers' => [],
            'elapsed' => microtime(true) - $started,
            'error' => $errstr !== '' ? $errstr : 'connect failed',
        ];
    }
    $seconds = (int) floor($timeout);
    $micros = (int) (($timeout - $seconds) * 1000000);
    stream_set_timeout($sock, max(0, $seconds), max(0, $micros));
    $hostHeader = str_contains($host, ':') ? '[' . $host . ']' : $host;
    fwrite($sock, "GET {$path} HTTP/1.1\r\nHost: {$hostHeader}:{$port}\r\nAccept: application/json\r\nConnection: close\r\n\r\n");
    $raw = '';
    while (!feof($sock) && strlen($raw) < 1024 * 1024) {
        $chunk = fread($sock, 8192);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $raw .= $chunk;
    }
    fclose($sock);
    $headerBlob = $raw;
    $body = '';
    if (str_contains($raw, "\r\n\r\n")) {
        [$headerBlob, $body] = explode("\r\n\r\n", $raw, 2);
    }
    $code = 0;
    $headers = [];
    foreach (explode("\r\n", $headerBlob) as $line) {
        if (preg_match('#^HTTP/\S+\s+(\d{3})#', $line, $match)) {
            $code = (int) $match[1];
            continue;
        }
        $colon = strpos($line, ':');
        if ($colon === false) {
            continue;
        }
        $headers[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
    }

    return [
        'code' => $code,
        'body' => $body,
        'headers' => $headers,
        'elapsed' => microtime(true) - $started,
        'error' => '',
    ];
}

/**
 * @param array<string, string> $env
 * @return array{proc: resource, pipes: array<int, resource>, port: int, pid: int}
 */
function start_api_server(array $env): array
{
    $procEnv = current_process_env();
    foreach ($env as $key => $value) {
        $procEnv[$key] = $value;
    }
    $proc = proc_open(
        [PHP_BINARY, __DIR__ . '/server.php'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        __DIR__,
        $procEnv
    );
    if (!is_resource($proc)) {
        fwrite(STDERR, "failed to start server.php\n");
        exit(1);
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $status = proc_get_status($proc);

    return [
        'proc' => $proc,
        'pipes' => $pipes,
        'port' => (int) $env['PORT'],
        'pid' => (int) $status['pid'],
    ];
}

/**
 * @param array<string, string> $env
 */
function run_cold_launch(string $label, array $env, ?string $countFile = null): void
{
    $port = free_port();
    $env['PORT'] = (string) $port;
    $t0 = microtime(true);
    $server = start_api_server($env);
    $deadline = $t0 + 1.0;
    $empty = ['code' => 0, 'body' => '', 'headers' => [], 'elapsed' => 0.0, 'error' => 'not tried'];
    $health = $empty;
    $root = $empty;
    $count = 0;
    while (microtime(true) < $deadline) {
        if ($health['code'] !== 200) {
            $health = http_get('http://127.0.0.1:' . $port . '/health', 0.2);
            if ($health['code'] !== 200) {
                $health6 = http_get('http://[::1]:' . $port . '/health', 0.2);
                if ($health6['code'] === 200) {
                    $health = $health6;
                }
            }
        }
        if ($health['code'] === 200 && $root['code'] !== 200) {
            $root = http_get('http://127.0.0.1:' . $port . '/', 0.2);
            if ($root['code'] !== 200) {
                $root6 = http_get('http://[::1]:' . $port . '/', 0.2);
                if ($root6['code'] === 200) {
                    $root = $root6;
                }
            }
        }
        if ($countFile !== null) {
            $rawCount = @file_get_contents($countFile);
            $count = is_string($rawCount) ? (int) $rawCount : 0;
        }
        $countOk = $countFile === null || $count === 1;
        if ($health['code'] === 200 && $root['code'] === 200 && $countOk) {
            break;
        }
        usleep(15000);
    }
    $total = microtime(true) - $t0;
    $cmdline = listening_cmdline($server['pid']);
    $healthType = $health['headers']['content-type'] ?? '';
    $healthLang = $health['headers']['x-polyglot-language'] ?? '';
    $rootType = $root['headers']['content-type'] ?? '';
    $rootLang = $root['headers']['x-polyglot-language'] ?? '';
    fwrite(STDERR, sprintf(
        "curl -sS -D- --max-time 1 http://127.0.0.1:%d/health  # launch %s status=%d time=%.4fs content-type=%s x-polyglot-language=%s body=%s\n",
        $port,
        $label,
        $health['code'],
        $health['elapsed'],
        $healthType,
        $healthLang,
        $health['body']
    ));
    fwrite(STDERR, sprintf(
        "curl -sS -D- --max-time 1 http://127.0.0.1:%d/  # launch %s status=%d time=%.4fs total=%.4fs content-type=%s x-polyglot-language=%s body=%s\n",
        $port,
        $label,
        $root['code'],
        $root['elapsed'],
        $total,
        $rootType,
        $rootLang,
        $root['body']
    ));
    fwrite(STDERR, "launch {$label} cmdline {$cmdline}\n");
    if ($countFile !== null) {
        fwrite(STDERR, "launch {$label} registration_requests={$count}\n");
    }
    $healthBody = json_decode($health['body'], true);
    $rootBody = json_decode($root['body'], true);
    $passed = $total < 1.0
        && $health['code'] === 200
        && is_array($healthBody)
        && ($healthBody['status'] ?? '') === 'ok'
        && stripos($healthType, 'application/json') !== false
        && $healthLang === 'PHP'
        && $root['code'] === 200
        && is_array($rootBody)
        && ($rootBody['language'] ?? '') === 'PHP'
        && ($rootBody['framework'] ?? '') === 'built-in SAPI'
        && stripos($rootType, 'application/json') !== false
        && $rootLang === 'PHP'
        && str_contains($cmdline, 'opcache.enable_cli=1');
    if (!$passed) {
        fwrite(STDERR, "launch {$label} server log:\n" . drain_pipes($server['pipes']) . "\n");
    }
    expect($total < 1.0, "{$label} GET /health and GET / within 1s ({$total}s)");
    expect(
        $health['code'] === 200 && is_array($healthBody) && ($healthBody['status'] ?? '') === 'ok',
        "{$label} /health is 200 ok"
    );
    expect(stripos($healthType, 'application/json') !== false, "{$label} /health Content-Type is application/json");
    expect($healthLang === 'PHP', "{$label} /health X-Polyglot-Language is PHP");
    expect(
        $root['code'] === 200
            && is_array($rootBody)
            && ($rootBody['language'] ?? '') === 'PHP'
            && ($rootBody['framework'] ?? '') === 'built-in SAPI',
        "{$label} GET / identity"
    );
    expect(stripos($rootType, 'application/json') !== false, "{$label} GET / Content-Type is application/json");
    expect($rootLang === 'PHP', "{$label} GET / X-Polyglot-Language is PHP");
    expect(str_contains($cmdline, 'opcache.enable_cli=1'), "{$label} listening process enables CLI opcache");
    if ($countFile !== null) {
        expect($count === 1, "{$label} registration ran once ({$count})");
    }
    stop_api_server($server);
}

/** @return array<string, array<string, mixed>> */
function catalog_speakers(): array
{
    return [
        'ada-lovelace' => [
            'slug' => 'ada-lovelace',
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'name' => 'Ada Lovelace',
            'tagline' => 'Notes',
            'bio' => 'Mathematician',
            'company' => 'Analytical',
            'location' => 'London',
            'photo_path' => '/ada.jpg',
            'twitter_url' => '',
            'linkedin_url' => '',
            'website_url' => 'https://example.com/ada',
            'github_url' => '',
            'featured' => true,
        ],
        'grace-hopper' => [
            'slug' => 'grace-hopper',
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'name' => 'Grace Hopper',
            'tagline' => 'COBOL',
            'bio' => 'Admiral',
            'company' => 'Navy',
            'location' => 'New York',
            'photo_path' => '/grace.jpg',
            'twitter_url' => '',
            'linkedin_url' => '',
            'website_url' => '',
            'github_url' => '',
            'featured' => false,
        ],
    ];
}

/** @return list<array<string, mixed>> */
function catalog_talks(): array
{
    return [
        [
            'slug' => 'analytical',
            'title' => 'Analytical Engine',
            'description' => 'Notes on the engine',
            'format' => 'talk',
            'youtube_id' => 'abc',
            'year' => 2026,
            'speaker_slug' => 'ada-lovelace',
            'languages' => '{php,sql}',
            'topics' => '{compilers,history}',
        ],
        [
            'slug' => 'notes',
            'title' => 'Notes',
            'description' => 'Earlier notes',
            'format' => 'talk',
            'youtube_id' => '',
            'year' => 2025,
            'speaker_slug' => 'ada-lovelace',
            'languages' => '{php}',
            'topics' => '{math}',
        ],
        [
            'slug' => 'cobol',
            'title' => 'COBOL',
            'description' => 'Compilers',
            'format' => 'talk',
            'youtube_id' => 'def',
            'year' => 2026,
            'speaker_slug' => 'grace-hopper',
            'languages' => '{cobol}',
            'topics' => '{systems}',
        ],
    ];
}

/** @return array<string, array<string, mixed>> */
function catalog_sponsors(): array
{
    return [
        'flywheel' => [
            'slug' => 'flywheel',
            'name' => 'Flywheel',
            'website' => 'https://flywheel.example',
            'logo_path' => '/fly.png',
            'description' => 'Hosting',
            'twitter_url' => '',
            'linkedin_url' => '',
            'youtube_url' => '',
            'instagram_url' => '',
            'facebook_url' => '',
        ],
    ];
}

/** @return list<array<string, mixed>> */
function catalog_year_sponsors(): array
{
    return [
        [
            'slug' => 'flywheel',
            'name' => 'Flywheel',
            'website' => 'https://flywheel.example',
            'logo_path' => '/fly.png',
            'description' => 'Hosting',
            'blurb' => 'Platinum host',
            'tier' => 'platinum',
            'featured' => true,
            'year' => 2026,
            'twitter_url' => '',
            'linkedin_url' => '',
            'youtube_url' => '',
            'instagram_url' => '',
            'facebook_url' => '',
        ],
    ];
}

/** @return list<array<string, mixed>> */
function catalog_sponsorships(): array
{
    return [
        [
            'sponsor_slug' => 'flywheel',
            'slug' => 'flywheel',
            'year' => 2026,
            'tier' => 'platinum',
        ],
    ];
}

/**
 * @param array<int|string, mixed> $args
 * @return list<array<string, mixed>>
 */
function catalog_rows(string $sql, array $args): array
{
    $speakers = catalog_speakers();
    $talks = catalog_talks();
    $sponsors = catalog_sponsors();
    $yearSponsors = catalog_year_sponsors();
    $sponsorships = catalog_sponsorships();

    if (str_contains($sql, 'FROM v1_years')) {
        return [
            ['year' => 2026, 'slug' => '2026', 'name' => 'Carolina 2026', 'status' => 'published'],
            ['year' => 2025, 'slug' => '2025', 'name' => 'Carolina 2025', 'status' => 'published'],
        ];
    }
    if (str_contains($sql, 'FROM v1_speakers WHERE slug =')) {
        $slug = (string) ($args[0] ?? '');

        return isset($speakers[$slug]) ? [$speakers[$slug]] : [];
    }
    if (str_contains($sql, 'FROM v1_speakers')) {
        $rows = array_values($speakers);
        if (str_contains($sql, 'WHERE slug IN')) {
            $year = (int) ($args[0] ?? 0);
            $slugs = [];
            foreach ($talks as $talk) {
                if ((int) $talk['year'] === $year) {
                    $slugs[(string) $talk['speaker_slug']] = true;
                }
            }
            $rows = array_values(array_filter(
                $rows,
                static fn (array $row): bool => isset($slugs[(string) $row['slug']])
            ));
        }
        usort(
            $rows,
            static fn (array $a, array $b): int => [$a['last_name'], $a['first_name']] <=> [$b['last_name'], $b['first_name']]
        );

        return $rows;
    }
    if (str_contains($sql, 'FROM v1_talks')) {
        if (str_contains($sql, 'SELECT DISTINCT year')) {
            $slug = (string) ($args[0] ?? '');
            $years = [];
            foreach ($talks as $talk) {
                if ($talk['speaker_slug'] === $slug) {
                    $years[(int) $talk['year']] = true;
                }
            }
            $list = array_keys($years);
            rsort($list);

            return array_map(static fn (int $year): array => ['year' => $year], $list);
        }
        if (str_contains($sql, 'speaker_slug IN')) {
            $want = array_map(static fn ($value): string => (string) $value, $args);
            $rows = [];
            foreach ($talks as $talk) {
                if (in_array((string) $talk['speaker_slug'], $want, true)) {
                    $rows[] = ['speaker_slug' => $talk['speaker_slug'], 'year' => (int) $talk['year']];
                }
            }

            return $rows;
        }
        if (str_contains($sql, 'speaker_slug =') && str_contains($sql, 'AND year =')) {
            $slug = (string) ($args[0] ?? '');
            $year = (int) ($args[1] ?? 0);

            return array_values(array_filter(
                $talks,
                static fn (array $talk): bool => $talk['speaker_slug'] === $slug && (int) $talk['year'] === $year
            ));
        }
        if (str_contains($sql, 'WHERE year =')) {
            $year = (int) ($args[0] ?? 0);

            return array_values(array_filter(
                $talks,
                static fn (array $talk): bool => (int) $talk['year'] === $year
            ));
        }
        if (str_contains($sql, 'speaker_slug =')) {
            $slug = (string) ($args[0] ?? '');

            return array_values(array_filter(
                $talks,
                static fn (array $talk): bool => $talk['speaker_slug'] === $slug
            ));
        }

        return [];
    }
    if (str_contains($sql, 'FROM v1_year_sponsors')) {
        if (str_contains($sql, 'AND slug =')) {
            $year = (int) ($args[0] ?? 0);
            $slug = (string) ($args[1] ?? '');

            return array_values(array_filter(
                $yearSponsors,
                static fn (array $row): bool => (int) $row['year'] === $year && $row['slug'] === $slug
            ));
        }
        $year = (int) ($args[0] ?? 0);

        return array_values(array_filter(
            $yearSponsors,
            static fn (array $row): bool => (int) $row['year'] === $year
        ));
    }
    if (str_contains($sql, 'FROM v1_sponsorships')) {
        $slug = (string) ($args[0] ?? '');
        $rows = array_values(array_filter(
            $sponsorships,
            static fn (array $row): bool => $row['sponsor_slug'] === $slug
        ));
        if (str_contains($sql, 'SELECT DISTINCT year')) {
            $years = [];
            foreach ($rows as $row) {
                $years[(int) $row['year']] = true;
            }
            $list = array_keys($years);
            rsort($list);

            return array_map(static fn (int $year): array => ['year' => $year], $list);
        }

        return $rows;
    }
    if (str_contains($sql, 'FROM v1_sponsors WHERE slug')) {
        $slug = (string) ($args[0] ?? '');

        return isset($sponsors[$slug]) ? [$sponsors[$slug]] : [];
    }
    if (str_contains($sql, 'FROM v1_sponsors')) {
        return array_values($sponsors);
    }

    return [];
}

/**
 * @param array<string, mixed> $qs
 * @return array{0: int, 1: array<string, mixed>}
 */
function expect_handled(string $path, array $qs = []): array
{
    [$status, $body] = handle_get($path, $qs);
    expect(is_int($status) && is_array($body), "{$path} returns a status and body");

    return [$status, $body];
}

/** @param array<string, mixed> $qs */
function expect_not_found(string $path, array $qs = []): void
{
    [$status, $body] = expect_handled($path, $qs);
    expect($status === 404, "{$path} returns 404");
    expect($body === ['error' => 'not_found'], "{$path} body is {\"error\":\"not_found\"}");
}

/**
 * @param array<string, mixed> $qs
 * @return array<string, mixed>
 */
function expect_data_list(string $path, array $qs = []): array
{
    [$status, $body] = expect_handled($path, $qs);
    expect($status === 200, "{$path} returns 200");
    $data = $body['data'] ?? null;
    expect(is_array($data) && array_is_list($data), "{$path} JSON data is an array");

    return $body;
}

function fly_memory_mb(string $toml): int
{
    if (preg_match('/^\s*memory\s*=\s*"(\d+)\s*(mb|gb)"\s*$/mi', $toml, $m) !== 1) {
        return -1;
    }
    $amount = (int) $m[1];

    return strtolower($m[2]) === 'gb' ? $amount * 1024 : $amount;
}

if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--fake-artifact-http') {
    fake_artifact_http_main();
    exit(0);
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--stall-http') {
    stall_http_main();
    exit(0);
}
if (PHP_SAPI === 'cli' && ($argv[1] ?? '') === '--prompt-http') {
    prompt_http_main();
    exit(0);
}

$src = file_get_contents(__DIR__ . '/carolina.php');
$start = file_get_contents(__DIR__ . '/server.php');
expect(str_contains($src, "const LANGUAGE = 'PHP'"), 'identity language is PHP');
expect(str_contains($src, "const FRAMEWORK = 'built-in SAPI'"), 'framework is built-in SAPI');
expect(!str_contains($src, 'laravel'), 'not Laravel');
expect(!str_contains($src, 'symfony'), 'not Symfony');
expect(!str_contains($src, 'slim'), 'not Slim');
expect(!file_exists(__DIR__ . '/composer.json'), 'no Composer app bootstrap');
expect(str_contains($start, 'php -S') || str_contains($start, '-S '), 'listen via PHP built-in SAPI');
expect(str_contains($start, 'opcache.enable_cli=1'), 'shipped server enables CLI opcache');
expect(str_contains($src, 'v1_speakers'), 'queries v1_speakers');
expect(str_contains($src, 'v1_talks'), 'queries v1_talks');
expect(str_contains($src, 'v1_year_sponsors'), 'queries v1_year_sponsors');
expect(str_contains($src, 'register_with_elixir'), 'one-shot register exists');
expect(!str_contains(explode('function register_with_elixir', $src, 2)[1] ?? '', 'pdo()'), 'register does not open PDO');

putenv('DATABASE_URL=postgres://postgres:postgres@127.0.0.1:1/carolina_dev');
$_ENV['DATABASE_URL'] = 'postgres://postgres:postgres@127.0.0.1:1/carolina_dev';
$GLOBALS['QUERY_FN'] = 'catalog_rows';
$GLOBALS['PDO'] = null;
$GLOBALS['CONNECTION'] = null;
reset_counts();

[$hstatus, $hbody] = handle_get('/health');
expect($hstatus === 200, '/health returns 200');
$hjson = json_encode($hbody);
expect(is_string($hjson) && str_contains($hjson, '"status"'), '/health JSON has status');
expect(is_string($hjson) && str_contains($hjson, '"ok"'), '/health JSON has ok');
expect(($hbody['status'] ?? '') === 'ok', '/health status is ok');
expect($GLOBALS['SQL_COUNT'] === 0, '/health does not run SQL');
expect($GLOBALS['CONNECT_COUNT'] === 0, '/health does not open Postgres');

[$istatus, $ibody] = handle_get('/');
expect($istatus === 200, 'GET / returns 200');
expect(($ibody['language'] ?? '') === 'PHP', 'GET / language is PHP');
expect(($ibody['framework'] ?? '') === 'built-in SAPI', 'GET / framework is built-in SAPI');
expect($GLOBALS['SQL_COUNT'] === 0, 'GET / does not run SQL');
expect($GLOBALS['CONNECT_COUNT'] === 0, 'GET / does not open Postgres');

$years = expect_data_list('/v1/years');
expect($years['data'] !== [], '/v1/years data is non-empty');
$speakers = expect_data_list('/v1/speakers');
expect($speakers['data'] !== [], '/v1/speakers data is non-empty');
$sponsors = expect_data_list('/v1/sponsors');
expect($sponsors['data'] !== [], '/v1/sponsors data is non-empty');

$yearSpeakers = expect_data_list('/v1/speakers', ['year' => '2026']);
expect($yearSpeakers['data'] !== [], 'year-scoped speakers returned rows');
$ada = null;
foreach ($yearSpeakers['data'] as $row) {
    expect(isset($row['languages']) && isset($row['topics']), 'year-scoped speaker row has languages and topics');
    expect(is_array($row['languages']) && array_is_list($row['languages']), 'languages is a list');
    expect(is_array($row['topics']) && array_is_list($row['topics']), 'topics is a list');
    if (($row['slug'] ?? '') === 'ada-lovelace') {
        $ada = $row;
    }
}
expect(is_array($ada), 'year list includes ada-lovelace');
if (is_array($ada)) {
    expect(in_array('php', $ada['languages'], true), 'ada languages list includes php');
    expect(in_array('compilers', $ada['topics'], true), 'ada topics list includes compilers');
}
expect($GLOBALS['SQL_COUNT'] > 0, 'year-scoped list hits catalog SQL');

$yearSponsors = expect_data_list('/v1/sponsors', ['year' => '2026']);
$flywheel = null;
foreach ($yearSponsors['data'] as $row) {
    if (($row['slug'] ?? '') === 'flywheel') {
        $flywheel = $row;
    }
}
expect(is_array($flywheel) && ($flywheel['tier'] ?? '') === 'platinum', 'year-scoped sponsor row includes tier');

[$speakerStatus, $speakerBody] = expect_handled('/v1/speakers/ada-lovelace');
expect(
    $speakerStatus === 200
        && is_array($speakerBody['data'])
        && !array_is_list($speakerBody['data'])
        && ($speakerBody['data']['slug'] ?? '') === 'ada-lovelace',
    'speaker slug returns a data object'
);
[$yearSpeakerStatus, $yearSpeakerBody] = expect_handled('/v1/speakers/2026/ada-lovelace');
expect(
    $yearSpeakerStatus === 200
        && is_array($yearSpeakerBody['data'])
        && !array_is_list($yearSpeakerBody['data'])
        && ($yearSpeakerBody['data']['slug'] ?? '') === 'ada-lovelace'
        && ($yearSpeakerBody['data']['year'] ?? null) === 2026,
    'year speaker slug returns a data object'
);
expect_not_found('/v1/speakers/no-such-slug');
expect_not_found('/v1/speakers/missing-speaker');
expect_not_found('/v1/speakers/2026/missing-speaker');
expect_not_found('/v1/speakers/2019/ada-lovelace');

[$sponsorStatus, $sponsorBody] = expect_handled('/v1/sponsors/flywheel');
expect(
    $sponsorStatus === 200
        && is_array($sponsorBody['data'])
        && !array_is_list($sponsorBody['data'])
        && ($sponsorBody['data']['slug'] ?? '') === 'flywheel',
    'sponsor slug returns a data object'
);
[$yearSponsorStatus, $yearSponsorBody] = expect_handled('/v1/sponsors/2026/flywheel');
expect(
    $yearSponsorStatus === 200
        && is_array($yearSponsorBody['data'])
        && !array_is_list($yearSponsorBody['data'])
        && ($yearSponsorBody['data']['tier'] ?? '') === 'platinum',
    'year sponsor slug returns a data object'
);
expect_not_found('/v1/sponsors/missing-sponsor');
expect_not_found('/v1/sponsors/2026/missing-sponsor');
expect_not_found('/v1/sponsors/2019/flywheel');
expect_not_found('/v1/nope');
expect_not_found('/favicon.ico');

reset_counts();
[, $oneBody] = handle_get('/v1/speakers', ['year' => '2025']);
$oneSql = $GLOBALS['SQL_COUNT'];
reset_counts();
[, $twoBody] = handle_get('/v1/speakers', ['year' => '2026']);
$twoSql = $GLOBALS['SQL_COUNT'];
expect(is_array($oneBody['data']) && count($oneBody['data']) === 1, '2025 list has one speaker');
expect(is_array($twoBody['data']) && count($twoBody['data']) === 2, '2026 list has two speakers');
expect($oneSql === $twoSql && $oneSql > 0, "year-scoped SQL round-trips match ({$oneSql} vs {$twoSql})");

$marker = new PDO('sqlite::memory:');
$GLOBALS['PDO'] = $marker;
$GLOBALS['CONNECTION'] = null;
reset_counts();
$attempts = 0;
$seenConn = [];
$seenPdo = [];
$GLOBALS['QUERY_FN'] = function (string $sql, array $args) use (&$attempts, &$seenConn, &$seenPdo): array {
    $attempts++;
    $seenConn[] = $GLOBALS['CONNECTION'];
    $seenPdo[] = $GLOBALS['PDO'];
    if ($attempts === 1) {
        $error = new PDOException('SQLSTATE[08006] [7] server closed the connection unexpectedly');
        $error->errorInfo = ['08006', 7, 'server closed the connection unexpectedly'];
        throw $error;
    }

    return [['ok' => 1, 'year' => 2026]];
};
$retried = db_query('SELECT 1', []);
expect($retried === [['ok' => 1, 'year' => 2026]], 'disconnect retry returns the rows from the second attempt');
expect($attempts === 2, 'disconnect retries once');
expect(
    is_object($seenConn[0] ?? null) && is_object($seenConn[1] ?? null) && $seenConn[0] !== $seenConn[1],
    'retry does not reuse the failed connection'
);
expect(($seenPdo[0] ?? null) === $marker, 'first attempt still held the cached PDO');
expect(($seenPdo[1] ?? null) === null && $GLOBALS['PDO'] !== $marker, 'failed PDO was discarded');
expect($GLOBALS['CONNECTION'] !== ($seenConn[0] ?? null), 'cached connection identity changed');

$GLOBALS['PDO'] = $marker;
$GLOBALS['CONNECTION'] = null;
reset_counts();
$syntaxAttempts = 0;
$kept = null;
$GLOBALS['QUERY_FN'] = function (string $sql, array $args) use (&$syntaxAttempts, &$kept): array {
    $syntaxAttempts++;
    $kept = $GLOBALS['CONNECTION'];
    throw new PDOException('SQLSTATE[42601] syntax error');
};
$syntaxThrew = false;
try {
    db_query('SELECT bad', []);
} catch (PDOException $syntax) {
    $syntaxThrew = str_contains($syntax->getMessage(), 'syntax error');
}
expect($syntaxThrew, 'non-disconnect PDO errors propagate');
expect($syntaxAttempts === 1, 'non-disconnect errors are not retried');
expect($GLOBALS['CONNECTION'] === $kept && $GLOBALS['PDO'] === $marker, 'non-disconnect keeps the cached connection');
$GLOBALS['QUERY_FN'] = null;
$GLOBALS['PDO'] = null;
$GLOBALS['CONNECTION'] = null;

$regCountFile = sys_get_temp_dir() . '/carolina-reg-' . getmypid() . '.count';
$regLogFile = sys_get_temp_dir() . '/carolina-reg-' . getmypid() . '.log';
@unlink($regCountFile);
@unlink($regLogFile);
$regPrompt = start_mode_server('--prompt-http', [
    'PROMPT_COUNT_FILE' => $regCountFile,
    'PROMPT_LOG_FILE' => $regLogFile,
]);
putenv('CAROLINA_URL=http://127.0.0.1:' . $regPrompt['port']);
putenv('POLYGLOT_REGISTER_TOKEN=dev-token');
putenv('PUBLIC_BASE_URL=http://127.0.0.1:9');
putenv('PORT=9');
reset_counts();
register_with_elixir();
register_with_elixir();
[$regHealthStatus] = handle_get('/health');
$regCountRaw = @file_get_contents($regCountFile);
$regCount = is_string($regCountRaw) ? (int) $regCountRaw : 0;
$regLog = is_file($regLogFile) ? (string) file_get_contents($regLogFile) : '';
expect($regHealthStatus === 200, 'health after register is 200');
expect($GLOBALS['CONNECT_COUNT'] === 0, 'handler connection counter stays 0 across register and /health');
expect($GLOBALS['SQL_COUNT'] === 0, 'register and /health do not run SQL');
expect($regCount === 1, 'in-process register sends one request');
expect(str_contains($regLog, 'POST /internal/api-endpoints/register'), 'register posts to the elixir endpoint');
expect(str_contains($regLog, '"language":"PHP"'), 'register body is the PHP identity');
stop_fake_artifact_server($regPrompt);

$stall = start_mode_server('--stall-http');
$stalledEnv = [
    'CAROLINA_URL' => 'http://127.0.0.1:' . $stall['port'],
    'POLYGLOT_REGISTER_TOKEN' => 'stall-token',
    'PUBLIC_BASE_URL' => 'http://127.0.0.1:9',
    'DATABASE_URL' => 'postgres://postgres:postgres@127.0.0.1:1/none',
];
run_cold_launch('stalled-1', $stalledEnv);
run_cold_launch('stalled-2', $stalledEnv);

$promptCountFile = sys_get_temp_dir() . '/carolina-prompt-' . getmypid() . '.count';
$promptLogFile = sys_get_temp_dir() . '/carolina-prompt-' . getmypid() . '.log';
@unlink($promptCountFile);
@unlink($promptLogFile);
$promptLaunch = start_mode_server('--prompt-http', [
    'PROMPT_COUNT_FILE' => $promptCountFile,
    'PROMPT_LOG_FILE' => $promptLogFile,
]);
run_cold_launch('prompt', [
    'CAROLINA_URL' => 'http://127.0.0.1:' . $promptLaunch['port'],
    'POLYGLOT_REGISTER_TOKEN' => 'prompt-token',
    'PUBLIC_BASE_URL' => 'http://127.0.0.1:9',
    'DATABASE_URL' => 'postgres://postgres:postgres@127.0.0.1:' . $stall['port'] . '/carolina_dev',
], $promptCountFile);
$promptLog = is_file($promptLogFile) ? (string) file_get_contents($promptLogFile) : '';
expect(str_contains($promptLog, 'POST /internal/api-endpoints/register'), 'prompt launch hit the register endpoint');
expect($GLOBALS['CONNECT_COUNT'] === 0, 'handler connection counter stays 0 across /health');
stop_fake_artifact_server($promptLaunch);
stop_fake_artifact_server($stall);

$fly = file_get_contents(__DIR__ . '/fly.toml');
expect(is_string($fly) && preg_match('/auto_stop_machines\s*=\s*"suspend"/', $fly) === 1, 'fly auto_stop_machines is suspend');
expect(is_string($fly) && preg_match('/auto_stop_machines\s*=\s*"stop"/', $fly) !== 1, 'fly does not stop machines');
expect(is_string($fly) && preg_match('/auto_start_machines\s*=\s*true/', $fly) === 1, 'fly auto_start_machines is true');
expect(is_string($fly) && preg_match('/cpu_kind\s*=\s*"shared"/', $fly) === 1, 'fly cpu is shared');
expect(is_string($fly) && preg_match('/cpus\s*=\s*1\b/', $fly) === 1, 'fly uses one cpu');
$memory = is_string($fly) ? fly_memory_mb($fly) : -1;
expect($memory > 0 && $memory <= 2048, "fly memory {$memory}mb is within the suspend limit");
expect(
    is_string($fly) && preg_match('/soft_limit\s*=\s*(\d+)/', $fly, $soft) === 1 && (int) $soft[1] <= 4 && (int) $soft[1] > 0,
    'fly request soft_limit is at most 4'
);
expect(is_string($fly) && preg_match('/type\s*=\s*"requests"/', $fly) === 1, 'fly concurrency type is requests');
expect(is_string($fly) && preg_match('/swap_size_mb|\bswap\b/i', $fly) !== 1, 'fly does not configure swap');

$dockerFile = file_get_contents(__DIR__ . '/Dockerfile');
$installAt = is_string($dockerFile) ? strpos($dockerFile, 'pdo_pgsql') : false;
$purgeAt = is_string($dockerFile) ? strpos($dockerFile, 'apt-get purge') : false;
expect($installAt !== false && $purgeAt !== false && $purgeAt > $installAt, 'image installs pdo_pgsql before purging the toolchain');
$purge = is_string($dockerFile) && is_int($purgeAt) ? substr($dockerFile, $purgeAt) : '';
expect(str_contains($purge, 'gcc') && str_contains($purge, 'PHPIZE_DEPS'), 'image purge removes gcc and PHPIZE_DEPS');
expect(preg_match('/apt-get install[^\n]*\bgcc\b/', $purge) !== 1, 'image does not reinstall gcc');

$psalmXml = file_get_contents(__DIR__ . '/psalm.xml');
expect(
    is_string($psalmXml) && preg_match('/errorLevel="(\d+)"/', $psalmXml, $level) === 1 && (int) $level[1] <= 4,
    'psalm errorLevel is 4 or stricter'
);
expect(
    is_string($psalmXml) && str_contains($psalmXml, 'carolina.php') && str_contains($psalmXml, 'router.php') && str_contains($psalmXml, 'server.php'),
    'psalm scans the API sources'
);
expect(is_string($psalmXml) && str_contains($psalmXml, 'scripts/ci_artifact.php'), 'psalm scans scripts/ci_artifact.php');
expect(is_string($psalmXml) && str_contains($psalmXml, '<directory name="tools" />'), 'psalm does not scan tools');

$required = ['test', 'sast', 'audit', 'secrets', 'lint'];
$precommitPath = __DIR__ . '/.pre-commit-config.yaml';
$workflowPath = __DIR__ . '/.gitea/workflows/precommit.yml';
$makefilePath = __DIR__ . '/Makefile';
expect(is_file($precommitPath), 'pre-commit config exists');
expect(is_file($workflowPath), 'gitea workflow exists');
expect(is_file($makefilePath), 'Makefile exists');
expect(is_file(__DIR__ . '/.githooks/pre-commit'), 'githooks pre-commit exists');
expect(is_file(__DIR__ . '/tools/composer.json'), 'tools-only composer.json exists');
expect(is_file(__DIR__ . '/tools/composer.lock'), 'tools composer.lock exists');
expect(!file_exists(__DIR__ . '/composer.json'), 'no Composer app bootstrap');
expect(!str_contains($src, 'vendor/autoload'), 'app does not load Composer autoload');

$precommit = file_get_contents($precommitPath);
$workflow = file_get_contents($workflowPath);
$workflowActive = yaml_without_comments($workflow);
$makefile = file_get_contents($makefilePath);
$helperPath = __DIR__ . '/scripts/ci_artifact.php';
expect(is_file($helperPath), 'artifact helper exists');
$hooks = precommit_hook_ids($precommit);
$jobs = gitea_job_names($workflow);
$bodies = gitea_job_bodies($workflow);
$targets = makefile_phony_targets($makefile);
foreach ($required as $name) {
    expect(in_array($name, $hooks, true), "precommit hook {$name}");
    expect(in_array($name, $jobs, true), "gitea job {$name}");
    expect(in_array($name, $targets, true), "makefile target {$name}");
    expect(str_contains($precommit, "entry: make {$name}"), "precommit {$name} runs make {$name}");
    expect(str_contains($workflow, "make {$name}"), "gitea invokes make {$name}");
}
$hookChecks = array_values(array_filter($hooks, fn ($id) => in_array($id, $required, true)));
$jobChecks = array_values(array_filter($jobs, fn ($id) => in_array($id, $required, true)));
sort($hookChecks);
sort($jobChecks);
expect($hookChecks === $jobChecks, 'gitea jobs match precommit check ids');
expect(in_array('prepare', $jobs, true), 'gitea has prepare job');
expect(count($jobs) === 6, 'gitea has prepare plus five checks');
expect(count($jobChecks) === 5, 'gitea is not a single combined job');
expect(!str_contains($workflowActive, 'actions/checkout'), 'gitea does not use actions/checkout');
expect(!str_contains($workflowActive, 'actions/upload-artifact'), 'gitea does not use Node upload-artifact');
expect(!str_contains($workflowActive, 'actions/download-artifact'), 'gitea does not use Node download-artifact');
expect(!preg_match('/\bgit init\b/', $workflowActive), 'gitea does not git init');
expect(str_contains($workflow, 'github.token'), 'gitea clone uses job token');
expect(!preg_match('/make check\b/', $workflowActive), 'gitea does not run combined make check');

$prep = $bodies['prepare'] ?? '';
expect($prep !== '', 'prepare job body exists');
expect(!preg_match('/^\s+needs:/m', $prep), 'prepare has no needs');
expect(str_contains($prep, 'git clone --depth 1 --no-checkout "https://x-access-token:${token}@${host}/${GITHUB_REPOSITORY}" .'), 'prepare token-clones the repo');
expect(str_contains($prep, 'git fetch --depth 1 origin "${GITHUB_SHA}"'), 'prepare clones GITHUB_SHA');
expect(str_contains($prep, 'missing job token for git fetch'), 'prepare requires the job token');
expect(str_contains($prep, 'apt-get'), 'prepare installs shared OS packages');
expect(str_contains($prep, 'getcomposer.org/installer'), 'prepare downloads Composer');
expect(str_contains($prep, 'gitleaks_8.30.1_linux_x64.tar.gz'), 'prepare downloads gitleaks');
expect(str_contains($prep, 'make tools'), 'prepare installs tools vendor');
expect(str_contains($prep, 'tar -czf'), 'prepare packs the workspace');
expect(str_contains($prep, 'prepared-env'), 'prepare publishes prepared-env');
expect(str_contains($prep, 'ci_artifact.php upload'), 'prepare uploads via the PHP helper');
foreach ($required as $name) {
    expect(!preg_match('/^\s+- run: make ' . preg_quote($name, '/') . '\s*$/m', $prep), "prepare does not run make {$name}");
}

foreach ($required as $name) {
    $body = $bodies[$name] ?? '';
    expect($body !== '', "check job {$name} body exists");
    expect(str_contains($body, 'needs: prepare'), "{$name} needs only prepare");
    expect(preg_match('/^\s+- run: make ' . preg_quote($name, '/') . '\s*$/m', $body) === 1, "{$name} runs make {$name}");
    expect(str_contains($body, 'ci_artifact.php download'), "{$name} restores via the PHP helper");
    expect(str_contains($body, 'prepared-env'), "{$name} restores prepared-env");
    expect(str_contains($body, 'tar -xzf'), "{$name} unpacks the prepared env");
    expect(!str_contains($body, 'git clone'), "{$name} does not clone");
    expect(!str_contains($body, 'apt-get'), "{$name} does not apt-get");
    expect(!str_contains($body, 'getcomposer.org'), "{$name} does not curl Composer");
    expect(!str_contains($body, 'gitleaks_8.30.1_linux_x64.tar.gz'), "{$name} does not curl the gitleaks tarball");
    foreach ($required as $other) {
        if ($other === $name) {
            continue;
        }
        expect(!preg_match('/^\s+needs:\s*' . preg_quote($other, '/') . '\s*$/m', $body), "{$name} does not needs: {$other}");
        expect(!preg_match('/^\s+- run: make ' . preg_quote($other, '/') . '\s*$/m', $body), "{$name} does not run make {$other}");
    }
}

expect(str_contains($precommit, 'fail_fast: false'), 'pre-commit fail_fast is false');
expect(str_contains($makefile, 'detect --source'), 'secrets target runs gitleaks detect');
expect(str_contains($makefile, '--taint-analysis'), 'sast runs psalm taint analysis');
expect(str_contains($makefile, 'audit --working-dir'), 'audit runs composer audit');
expect(str_contains($makefile, 'php-cs-fixer'), 'lint runs php-cs-fixer');
expect(str_contains($makefile, 'test.php'), 'test target runs test.php');

$tmp = sys_get_temp_dir() . '/ci-artifact-' . getmypid();
mkdir($tmp, 0777, true);
$payload = '';
for ($i = 0; $i < (64 * 1024 + 17); $i++) {
    $payload .= chr(($i * 31) % 256);
}
$srcFile = $tmp . '/prepared-env.tar.gz';
$destFile = $tmp . '/restored.tar.gz';
file_put_contents($srcFile, $payload);

$server = start_fake_artifact_server();
$helperEnv = current_process_env();
foreach ($server['env'] as $key => $value) {
    $helperEnv[$key] = $value;
}
$upload = run_ci_artifact(['upload', 'prepared-env', $srcFile], $helperEnv);
expect($upload['code'] === 0, 'artifact helper upload exits 0');
$download = run_ci_artifact(['download', 'prepared-env', $destFile], $helperEnv);
expect($download['code'] === 0, 'artifact helper download exits 0');
expect(is_file($destFile) && file_get_contents($destFile) === $payload, 'artifact helper round-trips file bytes');
stop_fake_artifact_server($server);

$chunkServer = start_fake_artifact_server();
$chunkEnv = current_process_env();
foreach ($chunkServer['env'] as $key => $value) {
    $chunkEnv[$key] = $value;
}
$chunkEnv['CI_ARTIFACT_CHUNK_SIZE'] = '8';
$chunkSrc = $tmp . '/chunk-in.bin';
$chunkDest = $tmp . '/chunk-out.bin';
$chunkPayload = str_repeat('x', 40);
file_put_contents($chunkSrc, $chunkPayload);
$chunkUp = run_ci_artifact(['upload', 'prepared-env', $chunkSrc], $chunkEnv);
expect($chunkUp['code'] === 0, 'chunked artifact upload exits 0');
$chunkDown = run_ci_artifact(['download', 'prepared-env', $chunkDest], $chunkEnv);
expect($chunkDown['code'] === 0, 'chunked artifact download exits 0');
expect(is_file($chunkDest) && file_get_contents($chunkDest) === $chunkPayload, 'chunked artifact round-trips file bytes');
stop_fake_artifact_server($chunkServer);

$rebaseServer = start_fake_artifact_server(['public_origin' => 'http://gitea.example']);
$rebaseEnv = current_process_env();
foreach ($rebaseServer['env'] as $key => $value) {
    $rebaseEnv[$key] = $value;
}
$rebaseSrc = $tmp . '/rebase-in.bin';
$rebaseDest = $tmp . '/rebase-out.bin';
file_put_contents($rebaseSrc, 'rebased-artifact-bytes');
$rebaseUp = run_ci_artifact(['upload', 'prepared-env', $rebaseSrc], $rebaseEnv);
expect($rebaseUp['code'] === 0, 'rebased artifact upload exits 0');
$rebaseDown = run_ci_artifact(['download', 'prepared-env', $rebaseDest], $rebaseEnv);
expect($rebaseDown['code'] === 0, 'rebased artifact download exits 0');
expect(is_file($rebaseDest) && file_get_contents($rebaseDest) === 'rebased-artifact-bytes', 'helper rebases ROOT_URL onto runtime origin');
stop_fake_artifact_server($rebaseServer);

$authServer = start_fake_artifact_server();
$authEnv = current_process_env();
foreach ($authServer['env'] as $key => $value) {
    $authEnv[$key] = $value;
}
$authEnv['ACTIONS_RUNTIME_TOKEN'] = 'wrong-token';
$authUp = run_ci_artifact(['upload', 'prepared-env', $srcFile], $authEnv);
expect($authUp['code'] !== 0, 'artifact helper auth failure is non-zero');
stop_fake_artifact_server($authServer);

$missingServer = start_fake_artifact_server();
$missingEnv = current_process_env();
foreach ($missingServer['env'] as $key => $value) {
    $missingEnv[$key] = $value;
}
$missingDest = $tmp . '/missing.tar.gz';
$missing = run_ci_artifact(['download', 'prepared-env', $missingDest], $missingEnv);
expect($missing['code'] !== 0, 'download of missing artifact is non-zero');
stop_fake_artifact_server($missingServer);

if ($failed) {
    fwrite(STDERR, "handler tests failed\n");
    exit(1);
}
fwrite(STDERR, "handler tests passed\n");
exit(0);
