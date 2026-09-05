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

$src = file_get_contents(__DIR__ . '/carolina.php');
$start = file_get_contents(__DIR__ . '/server.php');
expect(str_contains($src, "const LANGUAGE = 'PHP'"), 'identity language is PHP');
expect(str_contains($src, "const FRAMEWORK = 'built-in SAPI'"), 'framework is built-in SAPI');
expect(!str_contains($src, 'laravel'), 'not Laravel');
expect(!str_contains($src, 'symfony'), 'not Symfony');
expect(!str_contains($src, 'slim'), 'not Slim');
expect(!file_exists(__DIR__ . '/composer.json'), 'no Composer app bootstrap');
expect(str_contains($start, 'php -S') || str_contains($start, '-S '), 'listen via PHP built-in SAPI');
expect(str_contains($src, 'v1_speakers'), 'queries v1_speakers');
expect(str_contains($src, 'v1_talks'), 'queries v1_talks');
expect(str_contains($src, 'v1_year_sponsors'), 'queries v1_year_sponsors');
expect(str_contains($src, 'register_with_elixir'), 'one-shot register exists');
expect(!str_contains(explode('function register_with_elixir', $src, 2)[1] ?? '', 'pdo()'), 'register does not open PDO');

reset_counts();
[$hstatus, $hbody] = handle_get('/health');
expect($hstatus === 200, '/health returns 200');
$hjson = json_encode($hbody);
expect(str_contains($hjson, '"status"'), '/health JSON has status');
expect(str_contains($hjson, '"ok"'), '/health JSON has ok');
expect(($hbody['status'] ?? '') === 'ok', '/health status is ok');
expect($GLOBALS['SQL_COUNT'] === 0, '/health does not run SQL');
expect($GLOBALS['CONNECT_COUNT'] === 0, '/health does not open Postgres');

[$istatus, $ibody] = handle_get('/');
expect($istatus === 200, 'GET / returns 200');
expect(($ibody['language'] ?? '') === 'PHP', 'GET / language is PHP');
expect(($ibody['framework'] ?? '') === 'built-in SAPI', 'GET / framework is built-in SAPI');
expect($GLOBALS['SQL_COUNT'] === 0, 'GET / does not run SQL');

$live = false;
try {
    db_query('SELECT 1 AS ok');
    $live = true;
} catch (Throwable $e) {
    fwrite(STDERR, "postgres unavailable, using query hook: {$e->getMessage()}\n");
    $GLOBALS['QUERY_FN'] = function (string $sql, array $args): array {
        if (str_contains($sql, 'FROM v1_speakers WHERE slug =')) {
            return [];
        }
        if (str_contains($sql, 'FROM v1_speakers')) {
            return [['slug' => 'diana-pham', 'first_name' => 'Diana', 'last_name' => 'Pham', 'name' => 'Diana Pham']];
        }
        if (str_contains($sql, 'FROM v1_talks')) {
            return [[
                'slug' => 'talk',
                'title' => 'Talk',
                'speaker_slug' => 'diana-pham',
                'year' => 2026,
                'languages' => '{php}',
                'topics' => '{development}',
            ]];
        }
        if (str_contains($sql, 'FROM v1_year_sponsors')) {
            return [['slug' => 'flywheel', 'name' => 'Flywheel', 'tier' => 'platinum', 'year' => 2026]];
        }
        if (str_contains($sql, 'FROM v1_sponsors WHERE slug')) {
            return [];
        }
        return [];
    };
}

reset_counts();
[$s404, $b404] = handle_get('/v1/speakers/no-such-slug');
expect($s404 === 404, 'unknown speaker slug returns 404');
expect(str_contains(json_encode($b404), 'not_found'), '404 body is not_found');

reset_counts();
[$ss, $sp] = handle_get('/v1/speakers', ['year' => '2026']);
expect($ss === 200, 'year-scoped speakers return 200');
expect(isset($sp['data']) && is_array($sp['data']), 'year-scoped speakers wrapped as {data: ...}');
expect($sp['data'] !== [], 'year-scoped speakers returned rows');
expect(isset($sp['data'][0]['languages']), 'year-scoped speaker row has languages');
expect(isset($sp['data'][0]['topics']), 'year-scoped speaker row has topics');
expect(is_array($sp['data'][0]['languages']), 'languages is a list');
expect($GLOBALS['SQL_COUNT'] > 0, 'year-scoped list hits catalog SQL');

[$ys, $yp] = handle_get('/v1/sponsors', ['year' => '2026']);
expect($ys === 200, 'year-scoped sponsors return 200');
expect(isset($yp['data'][0]['tier']), 'year-scoped sponsor row includes tier');

if ($failed) {
    fwrite(STDERR, "handler tests failed\n");
    exit(1);
}
fwrite(STDERR, "handler tests passed\n");
exit(0);
