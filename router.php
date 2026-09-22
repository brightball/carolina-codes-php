<?php

/**
 * PHP built-in SAPI router. php -S [::]:PORT router.php
 */
require __DIR__ . '/carolina.php';

$uri = $_SERVER['REQUEST_URI'] ?? '/';
if (!is_string($uri) || $uri === '') {
    $uri = '/';
}
$parsedPath = parse_url($uri, PHP_URL_PATH);
$path = is_string($parsedPath) && $parsedPath !== '' ? $parsedPath : '/';
$parsedQuery = parse_url($uri, PHP_URL_QUERY);
$qs = [];
if (is_string($parsedQuery) && $parsedQuery !== '') {
    parse_str($parsedQuery, $qs);
}
[$status, $payload] = handle_get($path, $qs);
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('X-Polyglot-Language: ' . LANGUAGE);
header('X-Polyglot-Framework: ' . FRAMEWORK);
echo encode_payload($payload);
