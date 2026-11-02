#!/usr/bin/env bash
set -u

WS_HEALTH_URL="${WS_HEALTH_URL:-http://127.0.0.1:8081/health}"
PHP_BIN="${PHP_BIN:-php}"
APP_DIR="${APP_DIR:-/var/www/html/nexa}"

ok=0
fail=0
check() {
  local label="$1"; shift
  if "$@" >/tmp/nexa-check.out 2>/tmp/nexa-check.err; then
    printf '[OK]   %s\n' "$label"; ok=$((ok+1))
  else
    printf '[ERRO] %s\n' "$label"; sed -n '1,3p' /tmp/nexa-check.err; fail=$((fail+1))
  fi
}

check 'PHP disponível' "$PHP_BIN" -v
check 'Redis responde PING' redis-cli ping
check 'WebSocket/Redis health' curl -fsS "$WS_HEALTH_URL"
check 'Status do Outbox' "$PHP_BIN" "$APP_DIR/workers/brain_outbox_status.php"

printf '\nResultado: %d OK, %d erro(s).\n' "$ok" "$fail"
exit "$fail"
