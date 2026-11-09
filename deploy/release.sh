#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

php artisan migrate --force
php artisan horizon:terminate

if command -v supervisorctl >/dev/null 2>&1; then
  supervisorctl restart horizon || true
fi

echo "Release done: migrations applied, Horizon terminate signaled."
