<?php

/**
 * PHP built-in SAPI router. php -S [::]:PORT router.php
 */
require __DIR__ . '/carolina.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
parse_str(parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_QUERY) ?? '', $qs);
[$status, $payload] = handle_get($path, $qs);
http_response_code($status);
header('Content-Type: application/json; charset=utf-8');
header('X-Polyglot-Language: ' . LANGUAGE);
header('X-Polyglot-Framework: ' . FRAMEWORK);
echo json_encode($payload, JSON_UNESCAPED_SLASHES);
