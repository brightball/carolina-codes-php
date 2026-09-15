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

/** @return list<string> */
function gitea_job_names(string $yml): array
{
    $names = [];
    $inJobs = false;
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
                $names[] = $m[1];
            }
        }
    }
    return $names;
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
$hooks = precommit_hook_ids($precommit);
$jobs = gitea_job_names($workflow);
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
expect(count($jobs) === count($hookChecks), 'gitea is not a single combined job');
expect(!preg_match('/^\s+needs:/m', $workflowActive), 'gitea check jobs have no needs');
expect(!str_contains($workflowActive, 'actions/checkout'), 'gitea does not use actions/checkout');
expect(!preg_match('/\bgit init\b/', $workflowActive), 'gitea does not git init');
expect(str_contains($workflowActive, 'GITHUB_SHA'), 'gitea clones GITHUB_SHA');
expect(str_contains($workflow, 'github.token'), 'gitea clone uses job token');
expect(!preg_match('/make check\b/', $workflowActive), 'gitea does not run combined make check');
expect(str_contains($precommit, 'fail_fast: false'), 'pre-commit fail_fast is false');
expect(str_contains($makefile, 'detect --source'), 'secrets target runs gitleaks detect');
expect(str_contains($makefile, '--taint-analysis'), 'sast runs psalm taint analysis');
expect(str_contains($makefile, 'audit --working-dir'), 'audit runs composer audit');
expect(str_contains($makefile, 'php-cs-fixer'), 'lint runs php-cs-fixer');
expect(str_contains($makefile, 'test.php'), 'test target runs test.php');

if ($failed) {
    fwrite(STDERR, "handler tests failed\n");
    exit(1);
}
fwrite(STDERR, "handler tests passed\n");
exit(0);
