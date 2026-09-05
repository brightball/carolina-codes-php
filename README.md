# carolina-codes-php

Read-only v1 polyglot API for Carolina Code Conference. **Raw PHP** (CLI + built-in SAPI, no Composer framework).

Queries PostgreSQL `v1_*` views via PDO. Registers with Elixir once, then `php -S`.

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
php test.php
```
