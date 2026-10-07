# Memory

Operational lessons for this raw PHP API. These are failures that are easy to repeat. Settled choices, including rejected alternatives, are in `DECISIONS.md`. Edit a lesson in place when it stops being true. Append to `DECISIONS.md` when a durable choice is added or changed.

Do not put secrets, production tokens, tailnet hostnames, or home-directory paths in this file.

## opcache.enable_cli cannot be turned on with ini_set

`opcache.enable_cli` is read when the process starts. `ini_set('opcache.enable_cli', '1')` does not turn the CLI cache on. `server_sapi_args` passes `-d opcache.enable_cli=1` on the `php -S` command line, and the image writes the same setting under `conf.d` before the process starts. A setting applied only inside `router.php` arrives too late. The choice to enable the cache and disable the JIT is D4 in `DECISIONS.md`.

## /health is served before Elixir registration and does not touch Postgres

The built-in SAPI binds before a register POST can finish. A CMS that accepts the TCP connection and never answers must not delay `/health`. `handle_get('/health')` returns `{ "status": "ok" }` and does not call `pdo()` or run SQL. `register_with_elixir` does not open Postgres. Do not add a database ping to the health route, and do not move the register call onto the request path.

## Fly suspend can leave a dead Postgres connection

`fly.toml` uses `auto_stop_machines = "suspend"`. After resume, the process can still hold a PDO handle whose TCP session Postgres has already closed. The next catalog query then sees a disconnect. `/health` does not open a connection, so a green health check does not mean the cached session is alive. How that dead session is dropped is D5 in `DECISIONS.md`.

## Gitea gitleaks runs without a git binary

Check jobs use the `php:8.5-cli` image, which does not include git. gitleaks 8.30 will not scan a repository unless the git binary is on `PATH`. `make secrets` runs `gitleaks detect --source . --no-git`, which walks the tree instead. Removing `--no-git` fails the secrets job even when the tree has no leaks. The prepare job may install git. The parallel check jobs do not have that binary.
