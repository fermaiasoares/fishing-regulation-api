#!/usr/bin/env bash
# Local stack for fishing-regulation-api (PostGIS + Redis + Laravel API via Compose).
#
#   stack.sh up       build, start db/redis, composer install, key, migrate, start API, wait for /api/health
#   stack.sh import   load the published legal catalogs (br, mg) and MG legal areas; safe to repeat
#   stack.sh test     Python suites + catalog checks on the host, Laravel suite in the fishing_test DB
#   stack.sh annex    check the MMA 1.667 annex CSV against its PDF (throwaway python container)
#   stack.sh down     stop containers (add -v to also drop the data volumes)
#   stack.sh dc ...   any docker compose command with the right --env-file / -f
set -euo pipefail

ROOT="$(git -C "$(dirname "$0")" rev-parse --show-toplevel)"
cd "$ROOT"
dc() { docker compose --env-file infrastructure/.env -f infrastructure/compose.yaml "$@"; }
api_port() { grep -E '^API_PORT=' infrastructure/.env | cut -d= -f2 || true; }
health_url() { echo "http://127.0.0.1:$(api_port || echo 8000)/api/health"; }

ensure_env() {
  if [ ! -f infrastructure/.env ]; then
    sed "s/^POSTGRES_PASSWORD=.*/POSTGRES_PASSWORD=$(openssl rand -hex 16)/" infrastructure/.env.example > infrastructure/.env
    chmod 600 infrastructure/.env
    echo "created infrastructure/.env with a random local password"
  fi
  [ -f apps/api/.env ] || { cp apps/api/.env.example apps/api/.env; echo "created apps/api/.env"; }
}

cmd_up() {
  ensure_env
  dc build api
  dc up -d --wait db redis
  dc run --rm --no-deps api composer install --no-interaction --prefer-dist --no-progress
  # key:generate rewrites APP_KEY every time; only do it once per checkout.
  grep -qE '^APP_KEY=.+' apps/api/.env || dc run --rm --no-deps api php artisan key:generate --force
  dc run --rm --no-deps api php artisan migrate --force --step
  dc up -d api
  curl --fail --silent --show-error --retry 15 --retry-all-errors --retry-max-time 60 "$(health_url)"
  echo
}

cmd_import() {
  dc exec -T api php artisan legal:import --publish
  dc exec -T api php artisan geo:import-legal-areas
}

cmd_test() {
  (cd services/legal-catalog
   for catalog in ../../catalog/legal/*/; do
     python3 catalog.py "$catalog" --check-export "${catalog}catalog.json" > /dev/null && echo "catalog ok: $catalog"
   done
   python3 -m unittest discover -s tests)
  (cd services/geo-ingestion
   python3 legal_areas.py ../../catalog/geo/mg --mapping ../../catalog/legal/mg/area_mapping.toml \
     --basins ../../catalog/legal/mg/basins.toml --check > /dev/null && echo "area mapping ok"
   python3 -m unittest discover -s tests)
  # The Laravel suite fails on a database that already holds the real catalogs,
  # so it runs in its own database, like CI.
  user="$(grep -E '^POSTGRES_USER=' infrastructure/.env | cut -d= -f2)"; user="${user:-fishing}"
  main_db="$(grep -E '^POSTGRES_DB=' infrastructure/.env | cut -d= -f2)"; main_db="${main_db:-fishing}"
  dc exec -T db psql -U "$user" -d "$main_db" -qtAc "SELECT 1 FROM pg_database WHERE datname = 'fishing_test'" | grep -q 1 \
    || dc exec -T db psql -U "$user" -d "$main_db" -v ON_ERROR_STOP=1 -qc 'CREATE DATABASE fishing_test'
  dc exec -T db psql -U "$user" -d fishing_test -v ON_ERROR_STOP=1 -qc 'CREATE EXTENSION IF NOT EXISTS postgis'
  dc exec -T -e DB_DATABASE=fishing_test api php artisan migrate --force > /dev/null
  dc exec -T -e DB_DATABASE=fishing_test api php artisan test
}

cmd_annex() {
  # pdfplumber is not on the host and python3-venv may be missing; use a throwaway container.
  docker run --rm -v "$ROOT":/repo:ro -w /repo/services/legal-catalog python:3.12-slim \
    sh -c 'pip install --quiet --root-user-action=ignore pdfplumber==0.11.10 2>/dev/null && python annex_1667.py ../../catalog/legal/br --check'
}

case "${1:-}" in
  up) cmd_up ;;
  import) cmd_import ;;
  test) cmd_test ;;
  annex) cmd_annex ;;
  down) shift; dc down "$@" ;;
  dc) shift; dc "$@" ;;
  *) sed -n '2,10p' "$0"; exit 2 ;;
esac
