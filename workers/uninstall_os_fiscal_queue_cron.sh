#!/usr/bin/env bash
set -euo pipefail

MARKER="NEXA_OS_FISCAL_QUEUE"
TMP="$(mktemp)"
trap 'rm -f "$TMP"' EXIT

(crontab -l 2>/dev/null || true) | grep -v "$MARKER" > "$TMP" || true
crontab "$TMP"

echo "[OK] Cron exclusivo da fila fiscal da O.S. removido."
echo "[OK] Nenhum cron/worker fiscal do PDV foi alterado."
