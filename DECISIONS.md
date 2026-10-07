# Decisions

Accepted choices for this raw PHP API. The built-in SAPI has no framework convention for recording decisions, so this file is the log.

Each record has a status, context, decision, and consequences. Git history is the changelog. Append a record when a durable choice is added or changed. Do not rewrite an accepted decision; supersede it with a newer record that names it. When an operational fact changes and the choice does not, edit `MEMORY.md` instead.

Do not put secrets, production tokens, tailnet hostnames, or home-directory paths in this file.

## D1. No root Composer application

- Status: accepted
- Context: The public surface is a small read-only JSON API. A Composer framework would become the process bootstrap and pull a runtime dependency tree into the image. Quality tools still need a lockfile.
- Decision: Ship root PHP files (`carolina.php`, `router.php`, `server.php`) with no root `composer.json`. The identity framework string is `built-in SAPI`. Psalm and PHP-CS-Fixer live only under `tools/` (`tools/composer.json` requires `php >=8.5` and the dev packages `vimeo/psalm` and `php-cs-fixer/shim`).
- Consequences: The image copies the three PHP files and does not run `composer install`. `make audit` fails if a root `composer.json` appears, then audits the tools lockfile. App sources are not loaded through Composer's autoload.
- Rejected: A root Composer project whose HTTP stack is a framework package.

## D2. PDO against v1_* views only

- Status: accepted
- Context: The CMS publishes read-only PostgreSQL `v1_*` views. Ash resource tables are the CMS write model. This repo does not own the schema.
- Decision: Query with PDO (`pdo_pgsql`) and prepared statements. Statements select only `v1_speakers`, `v1_sponsors`, `v1_years`, `v1_talks`, `v1_sponsorships`, and `v1_year_sponsors`. Never query Ash tables or base catalog tables. There are no writes.
- Consequences: Schema changes belong to the CMS. This repo does not ship `db/*.sql` or a local `openapi.yaml`. Handler tests inject a fake catalog and do not need Postgres. Year-scoped speakers are `v1_speakers` filtered through `v1_talks`, with `languages` and `topics` attached in process. Year-scoped sponsors, including `tier` and `blurb`, come from `v1_year_sponsors`. `v1_year_speakers` is not selected. The image compiles `pdo_pgsql`, then removes the compiler toolchain and keeps `libpq`.
- Rejected: Mapping Ash tables, or shipping the starter catalog SQL in this remote.

## D3. pcntl_exec so the listener is the process

- Status: accepted
- Context: An early `passthru` of `php -S` left a child after SIGTERM of `server.php`, so a pidfile kill did not stop the listener. A register POST that runs before the socket binds can stall `GET /health` when the CMS accepts the connection and never answers.
- Decision: When `pcntl_fork` and `pcntl_exec` exist, double-fork `register_with_elixir` (the grandchild posts, the intermediate child exits), wait for that intermediate child, then `pcntl_exec` the built-in SAPI so the listener replaces this process. Registration stays off the request path and does not open Postgres. Without pcntl, start `php -S` with `proc_open` and register in this process after the child is running. The image installs the `pcntl` extension for the exec path.
- Consequences: A pidfile kill of the entry process is the listener. The SAPI binds without waiting for the register POST to finish. The no-pcntl path still serves when `pcntl_exec` is missing.
- Rejected: `passthru` of `php -S` as a child of `server.php`. Register-then-listen in the same process.

## D4. CLI opcache on, JIT off

- Status: accepted
- Context: The listener is `php -S` on a single shared CPU and a 256 MB Fly machine. CLI opcache stays off unless it is turned on before that process starts. The image is immutable for the life of the process. The tree sets the JIT buffer to zero.
- Decision: Enable CLI opcache and leave the JIT off. `Dockerfile` writes `/usr/local/etc/php/conf.d/opcache-cli.ini`, and `server_sapi_args` passes the same `-d` flags: `opcache.enable=1`, `opcache.enable_cli=1`, `opcache.jit=disable`, `opcache.jit_buffer_size=0`, `opcache.validate_timestamps=0`, `opcache.file_update_protection=0`.
- Consequences: The SAPI caches opcodes for the process lifetime and does not re-stat PHP files. No JIT buffer is reserved.
- Rejected: Leaving CLI opcache at the PHP default. Giving the tracing JIT a buffer.

## D5. Discard a dead PDO session after Fly suspend

- Status: accepted
- Context: `fly.toml` sets `auto_stop_machines = "suspend"`. Resume can return a process whose cached PDO still looks open after Postgres has closed the TCP session. The next query then fails with a disconnect (SQLSTATE class `08`, `57P01`, `57P02`, `57P03`, or a message such as "server closed the connection").
- Decision: On a disconnect-class `PDOException`, `db_query` calls `pdo_discard` (drops the cached PDO and connection identity) and runs the statement once more. Other PDO errors propagate and keep the cached session.
- Consequences: One dead session does not fail the request. A second failure propagates. `/health` still does not open Postgres, so the Fly check does not probe the session. Keep the cached connection between requests.
- Rejected: Failing the first request after resume. Opening a new connection on every query.

## D6. Quality gates are Psalm taint, Composer audit, gitleaks, and PHP-CS-Fixer

- Status: accepted
- Context: The API has no runtime Composer packages, so a root-project audit would describe an application stack this repo does not have. The same checks have to run locally, as pre-commit, and as separate Gitea jobs.
- Decision: `make check` runs `make test` (`php test.php`), `make sast` (Psalm `--taint-analysis` on the app sources), `make audit` (assert no root `composer.json`, then `composer audit --locked` on `tools/`), `make secrets` (`gitleaks detect --no-git`), and `make lint` (PHP-CS-Fixer `--dry-run`, PSR-12). `make hooks` installs those five as pre-commit. Gitea runs one job per check after a shared prepare job.
- Consequences: A new runtime dependency does not belong in `tools/composer.json`. Psalm and PHP-CS-Fixer stay dev-only. The secrets scan does not require a git binary on the check image.
- Rejected: A root Composer application whose lockfile is the audit target. One combined CI job for all five checks.
