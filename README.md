# carolina-codes-php

Read-only v1 polyglot API for Carolina Code Conference. **Raw PHP** (CLI + built-in SAPI, no Composer framework).

## Versions

- **Language:** PHP 8.5. The runtime image is `php:8.5-cli`. Quality-gate tooling under `tools/` requires `php >=8.5`.
- **Framework:** PHP's built-in SAPI (`php -S`). There is no separate framework package and no framework version. `GET /` reports `framework` as `built-in SAPI`.
- **Extensions:** `pdo_pgsql` and `pcntl`, compiled into the image. CLI opcache is on (`opcache.enable_cli=1`) and the JIT is disabled (`opcache.jit=disable`, `opcache.jit_buffer_size=0`).
- **Dev-only packages** in `tools/composer.json` (not loaded by the API): `vimeo/psalm` (`^6.13`) and `php-cs-fixer/shim` (`^3.91`).

Working notes for agents live in `AGENTS.md`, `DECISIONS.md`, and `MEMORY.md`.

Queries PostgreSQL `v1_*` views via PDO. Listens with `php -S` (CLI opcache on), then registers with Elixir once in the background.

```bash
DATABASE_URL=postgres://postgres:postgres@127.0.0.1:5432/carolina_dev \
CAROLINA_URL=http://127.0.0.1:4000 \
POLYGLOT_REGISTER_TOKEN=dev \
PUBLIC_BASE_URL=http://127.0.0.1:4021 \
PORT=4021 \
php server.php
```

`GET /` reports `language: "PHP"` and `framework: "built-in SAPI"`. `GET /health` returns `{"status":"ok"}` without touching Postgres.

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

Quality-gate tooling lives under `tools/` (`composer.json` / `composer.lock`) and is not the API bootstrap. There is no root `composer.json`.

Pre-commit runs the same five checks (`test`, `sast`, `audit`, `secrets`, `lint`). Install once with `make hooks` (needs `pre-commit` and `gitleaks` on PATH). Emergency skip: `SKIP=test,sast,audit,secrets,lint git commit`.

Gitea Actions (`.gitea/workflows/precommit.yml`) prepares the environment once, then runs those five checks as parallel jobs.