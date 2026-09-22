#!/usr/bin/env php
<?php

/**
 * Upload and download Gitea Actions artifacts without Node.
 *
 * Talks the v3 Actions artifact protocol that Gitea exposes at
 * /api/actions_pipeline/_apis/pipelines/workflows/{run_id}/artifacts.
 * Used by the prepare job (repo is already cloned) and by check jobs after
 * they fetch this file with the job token.
 */

const CI_ARTIFACT_CHUNK_SIZE = 8 * 1024 * 1024;
const CI_ARTIFACT_API_VERSION = '6.0-preview';

/** @param array<string, string> $env */
function ci_artifact_env_get(array $env, array $names, bool $required = true): string
{
    foreach ($names as $name) {
        $value = $env[$name] ?? '';
        if (is_string($value) && $value !== '') {
            return $value;
        }
    }
    if ($required) {
        fwrite(STDERR, 'missing required environment: ' . implode(', ', $names) . "\n");
        exit(1);
    }
    return '';
}

/** @param array<string, string> $env */
function ci_artifact_runtime_url(array $env): string
{
    $url = $env['ACTIONS_RUNTIME_URL'] ?? '';
    if (is_string($url) && $url !== '') {
        return str_ends_with($url, '/') ? $url : $url . '/';
    }
    $server = rtrim(ci_artifact_env_get($env, ['GITHUB_SERVER_URL', 'GITEA_SERVER_URL']), '/');
    return $server . '/api/actions_pipeline/';
}

/** @param array<string, string> $env */
function ci_artifact_runtime_token(array $env): string
{
    return ci_artifact_env_get($env, ['ACTIONS_RUNTIME_TOKEN', 'GITHUB_TOKEN', 'GITEA_TOKEN']);
}

/** @param array<string, string> $env */
function ci_artifact_run_id(array $env): string
{
    return ci_artifact_env_get($env, ['GITHUB_RUN_ID', 'GITEA_RUN_ID']);
}

/** @param array<string, string> $env */
function ci_artifact_resolve_url(string $url, array $env): string
{
    if ($url === '') {
        fwrite(STDERR, "artifact API returned an empty URL\n");
        exit(1);
    }
    $runtime = ci_artifact_runtime_url($env);
    $parsed = parse_url($url);
    $rt = parse_url($runtime);
    if (!is_array($parsed) || empty($parsed['scheme'])) {
        return rtrim($runtime, '/') . '/' . ltrim($url, '/');
    }
    $scheme = $rt['scheme'] ?? 'http';
    $host = $rt['host'] ?? '';
    $netloc = $host;
    if (isset($rt['port'])) {
        $netloc .= ':' . $rt['port'];
    }
    $path = $parsed['path'] ?? '';
    $query = isset($parsed['query']) ? '?' . $parsed['query'] : '';
    $fragment = isset($parsed['fragment']) ? '#' . $parsed['fragment'] : '';
    return "{$scheme}://{$netloc}{$path}{$query}{$fragment}";
}

/** @param array<string, string> $env */
function ci_artifact_artifacts_url(array $env): string
{
    return ci_artifact_runtime_url($env)
        . '_apis/pipelines/workflows/'
        . ci_artifact_run_id($env)
        . '/artifacts?api-version='
        . CI_ARTIFACT_API_VERSION;
}

/**
 * @param array<string, string> $env
 * @param array<string, string> $headers
 */
function ci_artifact_request(string $method, string $url, array $env, ?string $body = null, array $headers = []): string
{
    if (!function_exists('curl_init')) {
        fwrite(STDERR, "curl extension is required for artifact transfer\n");
        exit(1);
    }
    $hdrs = ['Authorization: Bearer ' . ci_artifact_runtime_token($env)];
    foreach ($headers as $name => $value) {
        $hdrs[] = $name . ': ' . $value;
    }
    $ch = curl_init($url);
    if ($ch === false) {
        fwrite(STDERR, "{$method} {$url} failed to init curl\n");
        exit(1);
    }
    $opts = [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $hdrs,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => false,
        CURLOPT_TIMEOUT => 600,
        CURLOPT_FOLLOWLOCATION => true,
    ];
    if ($body !== null) {
        $opts[CURLOPT_POSTFIELDS] = $body;
    }
    curl_setopt_array($ch, $opts);
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($response)) {
        fwrite(STDERR, "{$method} {$url} failed: {$err}\n");
        exit(1);
    }
    if ($code < 200 || $code >= 300) {
        fwrite(STDERR, "{$method} {$url} failed: {$code} {$response}\n");
        exit(1);
    }
    return $response;
}

function ci_artifact_json(string $raw): array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        fwrite(STDERR, "artifact API returned invalid JSON\n");
        exit(1);
    }
    return $data;
}

/** @param array<string, string> $env */
function ci_artifact_upload(string $name, string $path, array $env): void
{
    $source = $path;
    $size = filesize($source);
    if ($size === false || $size < 1) {
        fwrite(STDERR, "refusing to upload empty artifact: {$source}\n");
        exit(1);
    }
    $payload = json_encode(
        ['Type' => 'actions_storage', 'Name' => $name, 'RetentionDays' => 1],
        JSON_UNESCAPED_SLASHES
    );
    if (!is_string($payload)) {
        fwrite(STDERR, "failed to encode artifact create payload\n");
        exit(1);
    }
    $created = ci_artifact_json(ci_artifact_request(
        'POST',
        ci_artifact_artifacts_url($env),
        $env,
        $payload,
        ['Content-Type' => 'application/json']
    ));
    $uploadBase = ci_artifact_resolve_url(
        (string) ($created['fileContainerResourceUrl'] ?? $created['fileContainerResourceURL'] ?? ''),
        $env
    );
    $filename = basename($source);
    $item = rawurlencode($name . '/' . $filename);
    $sep = str_contains($uploadBase, '?') ? '&' : '?';
    $putUrl = $uploadBase . $sep . 'itemPath=' . $item;
    $chunkSize = (int) ($env['CI_ARTIFACT_CHUNK_SIZE'] ?? CI_ARTIFACT_CHUNK_SIZE);
    if ($chunkSize < 1) {
        $chunkSize = CI_ARTIFACT_CHUNK_SIZE;
    }
    $fh = fopen($source, 'rb');
    if ($fh === false) {
        fwrite(STDERR, "cannot read {$source}\n");
        exit(1);
    }
    $sent = 0;
    while ($sent < $size) {
        $chunk = fread($fh, $chunkSize);
        if (!is_string($chunk) || $chunk === '') {
            break;
        }
        $end = $sent + strlen($chunk) - 1;
        $md5 = base64_encode(hash('md5', $chunk, true));
        ci_artifact_request('PUT', $putUrl, $env, $chunk, [
            'Content-Type' => 'application/octet-stream',
            'Content-Range' => "bytes {$sent}-{$end}/{$size}",
            'x-tfs-filelength' => (string) $size,
            'x-actions-results-md5' => $md5,
        ]);
        $sent += strlen($chunk);
    }
    fclose($fh);
    $confirm = ci_artifact_artifacts_url($env) . '&artifactName=' . rawurlencode($name);
    ci_artifact_request('PATCH', $confirm, $env, '');
}

/** @param array<string, string> $env */
function ci_artifact_download(string $name, string $dest, array $env): void
{
    $listing = ci_artifact_json(ci_artifact_request('GET', ci_artifact_artifacts_url($env), $env));
    $items = $listing['value'] ?? [];
    if (!is_array($items)) {
        $items = [];
    }
    $match = null;
    foreach ($items as $item) {
        if (is_array($item) && ($item['name'] ?? '') === $name) {
            $match = $item;
            break;
        }
    }
    if ($match === null) {
        $names = [];
        foreach ($items as $item) {
            if (is_array($item)) {
                $names[] = $item['name'] ?? '';
            }
        }
        fwrite(STDERR, 'artifact ' . $name . ' not found; have ' . json_encode($names) . "\n");
        exit(1);
    }
    $container = ci_artifact_resolve_url(
        (string) ($match['fileContainerResourceUrl'] ?? $match['fileContainerResourceURL'] ?? ''),
        $env
    );
    $sep = str_contains($container, '?') ? '&' : '?';
    $filesUrl = $container . $sep . 'itemPath=' . rawurlencode($name);
    $files = ci_artifact_json(ci_artifact_request('GET', $filesUrl, $env));
    $entries = $files['value'] ?? [];
    if (!is_array($entries) || $entries === []) {
        fwrite(STDERR, "artifact {$name} has no files\n");
        exit(1);
    }
    $entry = $entries[0];
    foreach ($entries as $row) {
        if (is_array($row) && str_ends_with((string) ($row['path'] ?? ''), '.tar.gz')) {
            $entry = $row;
            break;
        }
    }
    if (!is_array($entry)) {
        fwrite(STDERR, "artifact {$name} has no files\n");
        exit(1);
    }
    $location = ci_artifact_resolve_url((string) ($entry['contentLocation'] ?? ''), $env);
    $blob = ci_artifact_request('GET', $location, $env);
    $parent = dirname($dest);
    if ($parent !== '' && $parent !== '.' && !is_dir($parent)) {
        mkdir($parent, 0777, true);
    }
    if (file_put_contents($dest, $blob) === false) {
        fwrite(STDERR, "cannot write {$dest}\n");
        exit(1);
    }
}

/** @return array<string, string> */
function ci_artifact_process_env(): array
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
 */
function ci_artifact_main(array $args, array $env): int
{
    if (count($args) !== 3 || !in_array($args[0], ['upload', 'download'], true)) {
        fwrite(STDERR, "usage: ci_artifact.php upload|download NAME FILE\n");
        return 2;
    }
    [$action, $name, $path] = $args;
    if ($action === 'upload') {
        ci_artifact_upload($name, $path, $env);
    } else {
        ci_artifact_download($name, $path, $env);
    }
    return 0;
}

if (PHP_SAPI === 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
    exit(ci_artifact_main(array_slice($argv, 1), ci_artifact_process_env()));
}
