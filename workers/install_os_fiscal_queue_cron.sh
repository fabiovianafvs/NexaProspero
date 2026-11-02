#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${NEXA_APP_DIR:-/var/www/nexa}"
PHP_BIN="${PHP_BIN:-$(command -v php)}"
WORKER="$APP_DIR/workers/os_fiscal_queue_worker.php"
LOG_DIR="$APP_DIR/storage"
LOG_FILE="$LOG_DIR/os_fiscal_queue.log"
LOCK_FILE="/tmp/nexa_os_fiscal_queue.lock"
MARKER="NEXA_OS_FISCAL_QUEUE"

if [[ ! -f "$WORKER" ]]; then
  echo "[ERRO] Worker não encontrado: $WORKER" >&2
  exit 1
fi
mkdir -p "$LOG_DIR"
touch "$LOG_FILE"

CRON_LINE="* * * * * /usr/bin/flock -n $LOCK_FILE $PHP_BIN $WORKER --once --batch=10 >> $LOG_FILE 2>&1 # $MARKER"
TMP="$(mktemp)"
(crontab -l 2>/dev/null || true) | grep -v "$MARKER" > "$TMP" || true
echo "$CRON_LINE" >> "$TMP"
crontab "$TMP"
rm -f "$TMP"

echo "[OK] Cron exclusivo da fila fiscal da O.S. instalado (a cada 1 minuto)."
echo "[OK] Nenhum cron/worker fiscal do PDV foi alterado."
echo "[INFO] Log: $LOG_FILE"
"$PHP_BIN" "$WORKER" --once --batch=10 || true
