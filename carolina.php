<?php

/**
 * Carolina Code Conference polyglot API — raw PHP (built-in SAPI).
 * Read-only SQL against PostgreSQL v1_* views. No Composer framework.
 */

const LANGUAGE = 'PHP';
const FRAMEWORK = 'built-in SAPI';
const API_VERSION = '0.2.0';
const CREATED_YEAR = 2026;
const SCHEMA_VERSION = 1;

const ENDPOINTS = [
    ['method' => 'GET', 'path' => '/', 'query' => []],
    ['method' => 'GET', 'path' => '/health', 'query' => []],
    ['method' => 'GET', 'path' => '/v1/years', 'query' => []],
    ['method' => 'GET', 'path' => '/v1/speakers', 'query' => ['year']],
    ['method' => 'GET', 'path' => '/v1/speakers/:slug', 'query' => []],
    ['method' => 'GET', 'path' => '/v1/speakers/:year/:slug', 'query' => []],
    ['method' => 'GET', 'path' => '/v1/sponsors', 'query' => ['year']],
    ['method' => 'GET', 'path' => '/v1/sponsors/:slug', 'query' => []],
    ['method' => 'GET', 'path' => '/v1/sponsors/:year/:slug', 'query' => []],
];

const SPEAKER_COLS = 'slug, first_name, last_name, name, tagline, bio, company, location, photo_path, twitter_url, linkedin_url, website_url, github_url, featured';
const YEAR_SPONSOR_COLS = 'slug, name, website, logo_path, description, blurb, tier, featured, year, twitter_url, linkedin_url, youtube_url, instagram_url, facebook_url';
const SPONSOR_COLS = 'slug, name, website, logo_path, description, twitter_url, linkedin_url, youtube_url, instagram_url, facebook_url';
const TALK_COLS = 'slug, title, description, format, youtube_id, year, speaker_slug, languages, topics';

$SQL_COUNT = 0;
$CONNECT_COUNT = 0;
$QUERY_FN = null;

/** @var PDO|null */
$PDO = null;

/**
 * Identity of the cached session. Replaced whenever the session is dropped.
 *
 * @var object|null
 */
$CONNECTION = null;

function language_version(): string
{
    return PHP_VERSION;
}

function reset_counts(): void
{
    global $SQL_COUNT, $CONNECT_COUNT;
    $SQL_COUNT = 0;
    $CONNECT_COUNT = 0;
}

/**
 * @return array{0: string, 1: string, 2: string}
 */
function pdo_dsn(): array
{
    $raw = getenv('DATABASE_URL') ?: 'postgres://postgres:postgres@127.0.0.1:5432/carolina_dev';
    if (!str_contains($raw, 'sslmode=')) {
        $raw .= (str_contains($raw, '?') ? '&' : '?') . 'sslmode=disable';
    }
    $url = parse_url(str_replace('postgres://', 'postgresql://', $raw));
    $host = $url['host'] ?? '127.0.0.1';
    $port = $url['port'] ?? 5432;
    $db = ltrim($url['path'] ?? '/carolina_dev', '/');
    $db = explode('?', $db)[0];
    $user = isset($url['user']) ? urldecode($url['user']) : 'postgres';
    $pass = isset($url['pass']) ? urldecode($url['pass']) : 'postgres';
    $query = [];
    if (!empty($url['query'])) {
        parse_str($url['query'], $query);
    }
    $ssl = $query['sslmode'] ?? 'disable';
    return ["pgsql:host={$host};port={$port};dbname={$db};sslmode={$ssl}", $user, $pass];
}

function pdo(): PDO
{
    global $PDO, $CONNECT_COUNT;
    if ($PDO instanceof PDO) {
        return $PDO;
    }
    $CONNECT_COUNT++;
    [$dsn, $user, $pass] = pdo_dsn();
    $PDO = new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    return $PDO;
}

/**
 * Drop a cached Postgres session.
 * Fly suspend/resume can leave the previous TCP connection dead.
 */
function pdo_discard(): void
{
    global $PDO, $CONNECTION;
    $PDO = null;
    $CONNECTION = null;
}

function pdo_is_disconnect(PDOException $e): bool
{
    $states = [];
    $code = (string) $e->getCode();
    if ($code !== '' && $code !== '0') {
        $states[] = $code;
    }
    $info = $e->errorInfo;
    if (is_array($info) && isset($info[0]) && is_string($info[0]) && $info[0] !== '') {
        $states[] = $info[0];
    }
    foreach ($states as $state) {
        if (str_starts_with($state, '08') || $state === '57P01' || $state === '57P02' || $state === '57P03') {
            return true;
        }
    }
    $msg = strtolower($e->getMessage());
    foreach ([
        'server closed the connection',
        'connection reset by peer',
        'no connection to the server',
        'terminating connection',
        'ssl connection has been closed',
        'could not connect to server',
        'connection refused',
        'broken pipe',
        'gone away',
        'lost connection',
    ] as $needle) {
        if (str_contains($msg, $needle)) {
            return true;
        }
    }
    return false;
}

function connection_acquire(): object
{
    global $QUERY_FN, $CONNECTION, $CONNECT_COUNT;
    if (is_object($CONNECTION)) {
        return $CONNECTION;
    }
    if ($QUERY_FN !== null) {
        $CONNECT_COUNT++;
        $CONNECTION = new stdClass();

        return $CONNECTION;
    }
    pdo();
    $CONNECTION = new stdClass();

    return $CONNECTION;
}

/**
 * @param array<int|string, mixed> $args
 * @return list<array<string, mixed>>
 */
function db_execute(string $sql, array $args): array
{
    global $QUERY_FN;
    connection_acquire();
    if ($QUERY_FN !== null) {
        $rows = ($QUERY_FN)($sql, $args);
        if (!is_array($rows)) {
            return [];
        }
        $out = [];
        foreach ($rows as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
    $stmt = pdo()->prepare($sql);
    $stmt->execute($args);
    $fetched = $stmt->fetchAll();
    $out = [];
    foreach ($fetched as $row) {
        if (is_array($row)) {
            $out[] = $row;
        }
    }

    return $out;
}

/**
 * @param array<int|string, mixed> $args
 * @return list<array<string, mixed>>
 */
function db_query(string $sql, array $args = []): array
{
    global $SQL_COUNT;
    $SQL_COUNT++;
    try {
        return db_execute($sql, $args);
    } catch (PDOException $e) {
        if (!pdo_is_disconnect($e)) {
            throw $e;
        }
        pdo_discard();

        return db_execute($sql, $args);
    }
}

function db_query_one(string $sql, array $args = []): ?array
{
    $rows = db_query($sql, $args);
    return $rows[0] ?? null;
}

function pg_text_array(mixed $value): array
{
    if ($value === null || $value === '') {
        return [];
    }
    if (is_array($value)) {
        return array_values(array_filter(array_map('strval', $value), fn ($v) => $v !== ''));
    }
    $stripped = trim((string) $value);
    if ($stripped === '{}' || $stripped === '') {
        return [];
    }
    if ($stripped[0] === '{' && str_ends_with($stripped, '}')) {
        $stripped = substr($stripped, 1, -1);
    }
    $parts = array_map(fn ($p) => trim($p, " \t\"'"), explode(',', $stripped));
    return array_values(array_filter($parts, fn ($v) => $v !== ''));
}

function clean(?array $row): ?array
{
    if ($row === null) {
        return null;
    }
    $out = [];
    foreach ($row as $k => $v) {
        if ($k === 'languages' || $k === 'topics') {
            $out[$k] = pg_text_array($v);
        } elseif ($k === 'year' && $v !== null && $v !== '') {
            $out[$k] = (int) $v;
        } else {
            $out[$k] = $v;
        }
    }
    return $out;
}

function uniq_tags(array $talks, string $key): array
{
    $seen = [];
    $out = [];
    foreach ($talks as $talk) {
        foreach (pg_text_array($talk[$key] ?? []) as $val) {
            if (!isset($seen[$val])) {
                $seen[$val] = true;
                $out[] = $val;
            }
        }
    }
    return $out;
}

function talks_for(string $slug, ?int $year = null): array
{
    if ($year === null) {
        $rows = db_query('SELECT ' . TALK_COLS . ' FROM v1_talks WHERE speaker_slug = ? ORDER BY year DESC', [$slug]);
    } else {
        $rows = db_query(
            'SELECT ' . TALK_COLS . ' FROM v1_talks WHERE speaker_slug = ? AND year = ? ORDER BY year DESC',
            [$slug, $year]
        );
    }
    return array_map('clean', $rows);
}

function talk_years(string $slug): array
{
    $rows = db_query('SELECT DISTINCT year FROM v1_talks WHERE speaker_slug = ? ORDER BY year DESC', [$slug]);
    return array_map(fn ($r) => (int) $r['year'], $rows);
}

function sponsor_years(string $slug): array
{
    $rows = db_query('SELECT DISTINCT year FROM v1_sponsorships WHERE sponsor_slug = ? ORDER BY year DESC', [$slug]);
    return array_map(fn ($r) => (int) $r['year'], $rows);
}

function load_talks_for_year(int $year): array
{
    $rows = db_query('SELECT ' . TALK_COLS . ' FROM v1_talks WHERE year = ? ORDER BY speaker_slug, year DESC', [$year]);
    $out = [];
    foreach ($rows as $row) {
        $talk = clean($row);
        $slug = $talk['speaker_slug'] ?? '';
        $out[$slug][] = $talk;
    }
    return $out;
}

function load_years_for_slugs(array $slugs): array
{
    if (!$slugs) {
        return [];
    }
    $placeholders = implode(',', array_fill(0, count($slugs), '?'));
    $rows = db_query(
        "SELECT DISTINCT speaker_slug, year FROM v1_talks WHERE speaker_slug IN ({$placeholders}) ORDER BY speaker_slug, year DESC",
        array_values($slugs)
    );
    $out = [];
    foreach ($rows as $row) {
        $out[$row['speaker_slug']][] = (int) $row['year'];
    }
    return $out;
}

function attach_year_tags(array $speakers, int $year): array
{
    if (!$speakers) {
        return $speakers;
    }
    $slugs = array_column($speakers, 'slug');
    $talksBy = load_talks_for_year($year);
    $yearsBy = load_years_for_slugs($slugs);
    foreach ($speakers as &$sp) {
        $slug = $sp['slug'];
        $talks = $talksBy[$slug] ?? [];
        $years = $yearsBy[$slug] ?? [];
        $sp['year'] = $year;
        $sp['talks'] = $talks;
        $sp['languages'] = uniq_tags($talks, 'languages');
        $sp['topics'] = uniq_tags($talks, 'topics');
        $sp['years'] = $years;
    }
    unset($sp);
    return $speakers;
}

function list_speakers(?int $year = null): array
{
    if ($year === null) {
        return array_map('clean', db_query('SELECT ' . SPEAKER_COLS . ' FROM v1_speakers ORDER BY last_name, first_name'));
    }
    $rows = db_query(
        'SELECT ' . SPEAKER_COLS . ' FROM v1_speakers WHERE slug IN (SELECT speaker_slug FROM v1_talks WHERE year = ?) ORDER BY last_name, first_name',
        [$year]
    );
    return attach_year_tags(array_map('clean', $rows), $year);
}

/**
 * JSON body for the built-in SAPI. Escapes taint so the response sink
 * is the encoded document rather than raw query text.
 *
 * @psalm-taint-escape html
 * @psalm-taint-escape has_quotes
 */
function encode_payload(mixed $payload): string
{
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
    if (!is_string($json)) {
        return '{}';
    }

    return $json;
}

function identity(): array
{
    return [
        'language' => LANGUAGE,
        'language_version' => language_version(),
        'api_version' => API_VERSION,
        'framework' => FRAMEWORK,
        'created_year' => CREATED_YEAR,
        'schema_version' => SCHEMA_VERSION,
        'endpoints' => ENDPOINTS,
    ];
}

/**
 * Shipped request handler. Tests call this directly.
 * @return array{0:int,1:array}
 */
function handle_get(string $path, array $qs = []): array
{
    $path = rtrim($path, '/') ?: '/';
    $parts = array_values(array_filter(explode('/', $path), fn ($p) => $p !== ''));

    if ($path === '/health') {
        return [200, ['status' => 'ok']];
    }
    if ($path === '/') {
        return [200, identity()];
    }
    if ($path === '/v1/years') {
        $rows = db_query('SELECT year, slug, name, status FROM v1_years ORDER BY year DESC');
        return [200, ['data' => array_map('clean', $rows)]];
    }
    if ($path === '/v1/speakers') {
        $year = isset($qs['year']) && $qs['year'] !== '' ? (int) $qs['year'] : null;
        return [200, ['data' => list_speakers($year)]];
    }
    if (count($parts) === 4 && $parts[0] === 'v1' && $parts[1] === 'speakers' && ctype_digit($parts[2])) {
        $year = (int) $parts[2];
        $slug = $parts[3];
        $speaker = clean(db_query_one('SELECT ' . SPEAKER_COLS . ' FROM v1_speakers WHERE slug = ?', [$slug]));
        if (!$speaker) {
            return [404, ['error' => 'not_found']];
        }
        $talks = talks_for($slug, $year);
        if (!$talks) {
            return [404, ['error' => 'not_found']];
        }
        $years = talk_years($slug);
        $speaker['year'] = $year;
        $speaker['years'] = $years;
        $speaker['other_years'] = array_values(array_filter($years, fn ($y) => $y !== $year));
        $speaker['talks'] = $talks;
        $speaker['languages'] = uniq_tags($talks, 'languages');
        $speaker['topics'] = uniq_tags($talks, 'topics');
        return [200, ['data' => $speaker]];
    }
    if (count($parts) === 3 && $parts[0] === 'v1' && $parts[1] === 'speakers') {
        $slug = $parts[2];
        $speaker = clean(db_query_one('SELECT ' . SPEAKER_COLS . ' FROM v1_speakers WHERE slug = ?', [$slug]));
        if (!$speaker) {
            return [404, ['error' => 'not_found']];
        }
        $speaker['talks'] = talks_for($slug);
        $speaker['years'] = talk_years($slug);
        return [200, ['data' => $speaker]];
    }
    if ($path === '/v1/sponsors') {
        $year = $qs['year'] ?? '';
        if ($year !== '') {
            $rows = db_query('SELECT ' . YEAR_SPONSOR_COLS . ' FROM v1_year_sponsors WHERE year = ? ORDER BY name', [(int) $year]);
        } else {
            $rows = db_query('SELECT ' . SPONSOR_COLS . ' FROM v1_sponsors ORDER BY name');
        }
        return [200, ['data' => array_map('clean', $rows)]];
    }
    if (count($parts) === 4 && $parts[0] === 'v1' && $parts[1] === 'sponsors' && ctype_digit($parts[2])) {
        $year = (int) $parts[2];
        $slug = $parts[3];
        $row = clean(db_query_one('SELECT ' . YEAR_SPONSOR_COLS . ' FROM v1_year_sponsors WHERE year = ? AND slug = ?', [$year, $slug]));
        if (!$row) {
            return [404, ['error' => 'not_found']];
        }
        $years = sponsor_years($slug);
        $row['years'] = $years;
        $row['other_years'] = array_values(array_filter($years, fn ($y) => $y !== $year));
        return [200, ['data' => $row]];
    }
    if (count($parts) === 3 && $parts[0] === 'v1' && $parts[1] === 'sponsors') {
        $slug = $parts[2];
        $row = clean(db_query_one('SELECT ' . SPONSOR_COLS . ' FROM v1_sponsors WHERE slug = ?', [$slug]));
        if (!$row) {
            return [404, ['error' => 'not_found']];
        }
        $row['sponsorships'] = array_map('clean', db_query('SELECT * FROM v1_sponsorships WHERE sponsor_slug = ?', [$slug]));
        return [200, ['data' => $row]];
    }
    return [404, ['error' => 'not_found']];
}

function register_with_elixir(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;

    $url = getenv('CAROLINA_URL') ?: '';
    $token = getenv('POLYGLOT_REGISTER_TOKEN') ?: '';
    if ($url === '' || $token === '') {
        return;
    }
    $port = getenv('PORT') ?: '4021';
    $base = getenv('PUBLIC_BASE_URL') ?: "http://127.0.0.1:{$port}";
    $body = json_encode(identity() + ['base_url' => $base]);
    if (!is_string($body)) {
        fwrite(STDERR, "register: failed\n");

        return;
    }
    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Authorization: Bearer {$token}\r\nContent-Type: application/json\r\n",
            'content' => $body,
            'timeout' => 5,
            'ignore_errors' => true,
        ],
    ]);
    /** @var list<string> $http_response_header */
    $http_response_header = [];
    $resp = @file_get_contents(rtrim($url, '/') . '/internal/api-endpoints/register', false, $ctx);
    $code = 0;
    $statusLine = '';
    if (isset($http_response_header[0]) && is_string($http_response_header[0])) {
        $statusLine = $http_response_header[0];
    }
    if (preg_match('/\s(\d{3})\s/', $statusLine, $m) === 1) {
        $code = (int) $m[1];
    }
    fwrite(STDERR, $resp !== false ? "registered with elixir: {$code}\n" : "register: failed\n");
}
