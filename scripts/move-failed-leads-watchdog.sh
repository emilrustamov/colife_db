#!/usr/bin/env bash
set -eu
cd /var/www/colife_db
LOG=storage/logs/move-failed-leads-sync.log
BATCH="${BATCH:-10}"
SLEEP_MS="${SLEEP_MS:-3000}"

remaining() {
  php artisan tinker --execute='echo app(\App\Services\MoveFailedLeads::class)->count();' 2>/dev/null | tr -dc '0-9'
}

while true; do
  LEFT="$(remaining || echo 1)"
  if [[ -z "$LEFT" ]]; then
    LEFT=1
  fi
  if [[ "$LEFT" -eq 0 ]]; then
    echo "$(date -u +%FT%TZ) watchdog: remaining=0, exit" | tee -a "$LOG"
    exit 0
  fi

  echo "$(date -u +%FT%TZ) watchdog: start sync left=$LEFT batch=$BATCH" | tee -a "$LOG"
  set +e
  php artisan bitrix:move-failed-leads --execute --sync --batch="$BATCH" --sleep-ms="$SLEEP_MS" >>"$LOG" 2>&1
  CODE=$?
  set -e

  LEFT="$(remaining || echo 1)"
  if [[ -z "$LEFT" ]]; then
    LEFT=1
  fi
  if [[ "$LEFT" -eq 0 ]]; then
    echo "$(date -u +%FT%TZ) watchdog: remaining=0 after exit=$CODE" | tee -a "$LOG"
    exit 0
  fi

  echo "$(date -u +%FT%TZ) watchdog: process exited code=$CODE left=$LEFT — restart in 30s" | tee -a "$LOG"
  sleep 30
done
