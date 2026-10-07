# carolina-codes-php

Instructions for this read-only v1 HTTP API. A Carolina Code Conference Elixir site can rotate onto it.

This repository is a finished API. The implementation is raw PHP on the CLI built-in SAPI (`php -S`). There is no Composer application framework and no root `composer.json`. Quality-gate tooling lives under `tools/`.

The source of truth for routes and payloads is the CMS contract: `priv/api/openapi.yaml` and `priv/api/AGENTS.md` in the Phoenix CMS repository (`github.com/brightball/carolina-codes`). This repo does not ship `openapi.yaml`. Siblings speak ordinary JSON over the v1 REST + SQL-view contract. Do not implement Ash JSON:API (`application/vnd.api+json`).

This repository is the workspace root. Treat this git remote as the whole tree. The Phoenix CMS is a different remote. Do not assume `../elixir` or any other sibling directory exists unless that remote is attached to the same environment. Do not fold this tree into the CMS git remote.

Registration is best-effort. If `CAROLINA_URL` is unset or the CMS is down, skip the register call and still serve HTTP.

## Agent memory

Raw PHP and the built-in SAPI have no framework-native decision store. Use the files below. Git history is the changelog.

| File | Role |
| --- | --- |
| `AGENTS.md` | Current working instructions: contract, commands, and pointers. Update it when the way to run or change the API changes. |
| `DECISIONS.md` | Nygard-style log. Each record has a status, context, decision, and consequences (or a rejected alternative). Append a record when a durable choice is added or changed. Leave an accepted record's text in place and supersede it with a newer record. |
| `MEMORY.md` | Operational lessons that are easy to get wrong. Edit a lesson in place when it stops being true. Do not copy decision records into it. |

Do not put secrets, production tokens, tailnet hostnames, or home-directory paths in `DECISIONS.md` or `MEMORY.md`. The public local examples (`postgres` / `postgres`, register token `dev`, `127.0.0.1`) are the only credentials that belong in these docs.

Read `DECISIONS.md` and `MEMORY.md` before changing the listener, SQL, registration, opcache flags, or the quality gates.

## Purpose

The Phoenix app (`Carolina.Polyglot`) keeps at most one language API warm and reads speakers and sponsors from it. With no APIs registered, it falls back to Ash. This process must:

1. Query PostgreSQL `v1_*` views in the CMS database. Never query Ash resource tables or base catalog tables.
2. Expose the routes below. List payloads are `{ "data": [ ... ] }`.
3. Register once on boot with the Elixir site. There is no heartbeat. If the site is not running, log and continue. The listener binds without waiting on that POST. Registration does not open Postgres.

## Environment

| Variable | Example | Role |
| --- | --- | --- |
| `DATABASE_URL` | `postgres://postgres:postgres@127.0.0.1:5432/carolina_dev` | CMS `v1_*` views |
| `CAROLINA_URL` | `http://127.0.0.1:4000` | Elixir site (optional; register no-ops if down) |
| `POLYGLOT_REGISTER_TOKEN` | `dev` | Bearer token for register |
| `PUBLIC_BASE_URL` | `http://127.0.0.1:4021` | URL Elixir will call |
| `PORT` | `4021` locally, `8080` in the image | Listen port |

`server.php` defaults `PORT` to `4021` when it is unset. The image and `fly.toml` set `PORT=8080`. Handler tests that install a fake catalog do not need Postgres. For live HTTP against the views, use the CMS database that already holds the `v1_*` views and set the variables above.

`pdo_dsn()` appends `sslmode=disable` when `DATABASE_URL` does not already set `sslmode`.

## SQL views (query these)

This process selects from `v1_speakers`, `v1_sponsors`, `v1_years`, `v1_talks`, `v1_sponsorships`, and `v1_year_sponsors`.

The views live in the CMS database. This repo does not ship the view SQL. Year-scoped speaker listings read `v1_speakers` whose slug appears in `v1_talks` for that year, then attach `languages` and `topics` from those talks. Year-scoped sponsor rows read `v1_year_sponsors` and include `tier` and `blurb`. This process does not query `v1_year_speakers`.

Do not `SELECT` from `speakers`, `organizations`, `talks`, or other base tables. Do not query Ash tables. There are no writes.

## Required HTTP routes

Wrap list payloads as `{ "data": [ ... ] }`. A single resource is `{ "data": { ... } }`. An unknown slug returns `404` and `{ "error": "not_found" }`.

- `GET /health` — liveness `{ "status": "ok" }`. This route does not open Postgres and does not run SQL. The built-in SAPI binds before registration I/O can block it.
- `GET /` — identity (`language`, `language_version`, `api_version`, `framework`, `created_year`, `schema_version`, `endpoints`)
- `GET /v1/years`
- `GET /v1/speakers` and `GET /v1/speakers?year=2025`
- `GET /v1/speakers/{slug}` and `GET /v1/speakers/{year}/{slug}`
- `GET /v1/sponsors` and `GET /v1/sponsors?year=2025`
- `GET /v1/sponsors/{slug}` and `GET /v1/sponsors/{year}/{slug}`

`language` is `PHP`. `framework` is `built-in SAPI` (no separate framework package or version). `language_version` is `PHP_VERSION`. `api_version` is `0.2.0`. `schema_version` is `1`. `created_year` is `2026`.

Contract examples use slugs such as `mira-calder` and `copperline-labs` in years 2024 and 2025 (`/v1/speakers/2025/mira-calder`, `/v1/sponsors/2024/copperline-labs`). This repo does not ship that seed.

`photo_path` and `logo_path` are returned as stored web paths. This process does not serve the image bytes. The CMS usually hosts them.

## Register on boot (once)

When `pcntl_fork` and `pcntl_exec` exist, `server.php` double-forks `register_with_elixir` so the POST is not the listener, then replaces this process with `php -S`. Without pcntl, it starts the SAPI with `proc_open` and registers in this process. Either way the listener is up without waiting for the CMS, and registration stays off the request path. See `DECISIONS.md`.

`POST {CAROLINA_URL}/internal/api-endpoints/register`

```
Authorization: Bearer {POLYGLOT_REGISTER_TOKEN}
Content-Type: application/json
```

Body fields: `language`, `language_version`, `api_version`, `framework`, `created_year`, `base_url` (`PUBLIC_BASE_URL`, or `http://127.0.0.1:$PORT` when that variable is unset), `schema_version` (1), `endpoints` (the same `{ method, path, query }` objects that `GET /` returns).

The POST times out in 5 seconds. It does not open Postgres. `register_with_elixir` runs at most once per process.

Do not heartbeat. Elixir keep-alives the currently warm API.

If `CAROLINA_URL` or `POLYGLOT_REGISTER_TOKEN` is empty, skip registration. If the POST fails (connection refused, timeout, 4xx, or 5xx), log and keep serving.

## Listen

The built-in SAPI binds `[::]:$PORT`. CLI opcache is enabled on that process and the JIT is disabled. `opcache.enable_cli` is read at process start; see `MEMORY.md`.

## Layout

| Path | Role |
| --- | --- |
| `carolina.php` | Routes, SQL against `v1_*` views, one-shot register |
| `router.php` | Built-in SAPI front controller |
| `server.php` | Process entry: detached register, then `pcntl_exec` of `php -S` |
| `test.php` | Handler tests. Fake catalog; no Postgres |
| `Dockerfile` | `php:8.5-cli`, extensions `pdo_pgsql` and `pcntl`, CLI opcache ini |
| `fly.toml` | Fly service. Idle machines suspend. `/health` is the check |
| `Makefile` | Quality gates listed below |
| `tools/composer.json` | Dev-only Psalm and PHP-CS-Fixer. Not the API bootstrap |
| `.gitea/workflows/precommit.yml` | One parallel job per quality gate |
| `DECISIONS.md` | Accepted choices |
| `MEMORY.md` | Operational lessons |

## Quality gates

```bash
make test        # php test.php (shipped handle_get)
make sast        # Psalm taint analysis of app sources
make audit       # composer audit of the tools lockfile (no runtime packages)
make secrets     # gitleaks detect --source . --no-git
make lint        # PHP-CS-Fixer --dry-run (PSR-12)
make check       # all of the above
make hooks       # install local pre-commit hooks
make fmt         # apply PHP-CS-Fixer
```

There is no root `composer.json`. `make audit` checks that, then audits `tools/composer.lock`. Psalm and PHP-CS-Fixer are dev-only (`vimeo/psalm`, `php-cs-fixer/shim`).

Pre-commit runs the same five checks (`test`, `sast`, `audit`, `secrets`, `lint`). Install once with `make hooks` (needs `pre-commit` and `gitleaks` on PATH). Emergency skip: `SKIP=test,sast,audit,secrets,lint git commit`.

Gitea Actions prepares the environment once, then runs those five checks as parallel jobs. The secrets job has no git binary; `make secrets` scans with `--no-git`. See `MEMORY.md`.

## Invariants

- CMS OpenAPI paths return 200 with example-shaped JSON, and 404 for an unknown slug
- `?year=` speaker rows include `languages` and `topics`
- `?year=` sponsor rows include `tier` (and `blurb`)
- Register runs once per process, off the request path, and the process still serves if the Elixir site is down
- No writes, and no Ash table names in SQL
- `GET /health` stays `{ "status": "ok" }` and stays off the database
- `make check` passes
