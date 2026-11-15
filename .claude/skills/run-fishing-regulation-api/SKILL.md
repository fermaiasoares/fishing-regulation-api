---
name: run-fishing-regulation-api
description: Start, run, test and drive the fishing-regulation-api locally (Docker Compose with PostGIS, Redis and the Laravel API). Use when asked to run or start the API, bring up the stack, import the legal catalogs or MG legal areas, run the Laravel/Python tests, or call POST /api/fishing/evaluate with real scenarios.
---

The API runs in Docker Compose (`infrastructure/compose.yaml`). Bring it up and drive it with two scripts
in this directory: `stack.sh` (start, import, test, stop) and `evaluate.py` (calls `POST /api/fishing/evaluate`).
Paths are relative to the repo root.

## Prerequisites

Docker with Compose v2, plus `python3`, `curl`, `openssl` and `git` on the host (verified: Docker 29.9,
Compose v5.7, Python 3.14 on Ubuntu). No other host packages are needed: PHP and Composer run in the `api`
container, and the PDF check runs in a throwaway `python:3.12-slim` container.

## Run (agent path)

```bash
S=.claude/skills/run-fishing-regulation-api
$S/stack.sh up        # creates the .env files if missing, builds, migrates, starts the API, waits for /api/health
$S/stack.sh import    # publishes catalogs br + mg and loads the 43 MG hydrographic districts; repeat = no-op
$S/evaluate.py        # 15 real scenarios + 3 invalid inputs, each compared with the expected status; exit 1 on divergence
```

Single queries (full JSON response):

```bash
$S/evaluate.py --town caratinga "Prochilodus vimboides" 2027-06-15
$S/evaluate.py -19.9167 -43.9345 tambaqui 2026-12-15     # LAT LON SPECIES [DATE]
```

Towns available in `--town`: caratinga (Doce), brumadinho (Paraopeba), bh and montes-claros (São Francisco),
uberaba (Grande), patos-de-minas (Paranaíba), teofilo-otoni and juiz-de-fora (Leste), extrema (PJ1, no rules)
and sao-paulo (outside the map).

The API listens on `http://127.0.0.1:8000` (bound to localhost only). `curl http://127.0.0.1:8000/api/health`
returns `{"status":"ok"}` once PostGIS and Redis are reachable.

| command | what it does |
|---|---|
| `stack.sh up` | idempotent; ~15 s with the image already built (a cold build was not timed) |
| `stack.sh import` | `legal:import --publish` + `geo:import-legal-areas` |
| `stack.sh test` | catalog checks + Python suites on the host, Laravel suite in DB `fishing_test` |
| `stack.sh annex` | checks `catalog/legal/br/sources/mma-1667-2026.annex.csv` against the PDF |
| `stack.sh dc <args>` | any `docker compose` command with the right `--env-file`/`-f` (e.g. `dc logs api`) |
| `stack.sh down [-v]` | stops the stack; `-v` also deletes the database and Redis volumes |

## Test

```bash
.claude/skills/run-fishing-regulation-api/stack.sh test
.claude/skills/run-fishing-regulation-api/stack.sh annex
```

Expected: both catalogs ok, 33 tests in `legal-catalog`, area mapping ok, 10 tests in `geo-ingestion`,
39 Laravel tests, and `OK: mma-1667-2026.annex.csv matches the PDF`.

## Gotchas

- **The Laravel suite fails on the dev database once the real data is imported** (5 failures in
  `LegalAreaImportTest` and `LegalCatalogImportTest`). The tests use `DatabaseTransactions` on whatever DB
  the API is configured for, and they assume no published catalog. `stack.sh test` creates `fishing_test`
  (with PostGIS), migrates it, and runs `php artisan test` with `-e DB_DATABASE=fishing_test`. Don't run
  `php artisan test` against `fishing` directly.
- **Running the suite on the dev DB advances its sequences.** Catalog versions come out as 50/51 instead
  of 1/2. This is harmless, but it is a sign that the suite ran on the wrong DB.
- **Rate limit of 60 req/min per client.** One `evaluate.py` run makes 18 requests; three runs in the
  same minute start returning `429`. Wait a minute.
- **Common names often come out `unknown`.** This is a catalog gap, not a bug: São Francisco, Grande,
  Paranaíba and Leste have no official native list, and the threatened list only has scientific names.
  So "dourado", "surubim" or "tambaqui" in Uberaba come out `undetermined`. Use the scientific name to
  exercise the federal rule. The scenarios flagged `gap:` in `evaluate.py` pin this behavior.
- **`key:generate` rewrites `APP_KEY` on every call.** `stack.sh up` only runs it when the key is empty.
- **zsh does not split words in an unquoted variable.** `DC="docker compose --env-file …"; $DC up` fails
  with `no such file or directory: docker compose --env-file …`. Use `stack.sh dc …` instead.
- **The host may not have `python3-venv`** (`ensurepip is not available`), so the pdfplumber venv used
  in CI can't be created. `stack.sh annex` uses a container instead.

## Troubleshooting

- **`curl: (56) Recv failure: Connection reset by peer` during `up`**: the first attempt happens before
  `artisan serve` opens the port; the `--retry` gets `{"status":"ok"}` right after. It is only a problem
  if the last line is not `{"status":"ok"}`. In that case look at `stack.sh dc logs api`.
- **`503` with `catalog_unavailable`**: no federal (`br`) catalog is published yet. Run `stack.sh import`.
