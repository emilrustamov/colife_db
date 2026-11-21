#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

# Always run artisan as www-data — never as root (root-owned laravel-*.log breaks php-fpm/schedule).
run_artisan() {
  if [[ "$(id -u)" -eq 0 ]]; then
    sudo -u www-data -H php artisan "$@"
  elif [[ "$(id -un)" == "www-data" ]]; then
    php artisan "$@"
  else
    echo "Run as root or www-data (got $(id -un))" >&2
    exit 1
  fi
}

run_artisan migrate --force
run_artisan horizon:terminate

if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl restart horizon || true
fi

echo "Release done: migrations applied, Horizon terminate signaled."
