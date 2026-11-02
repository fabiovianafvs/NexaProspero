#!/usr/bin/env bash
set -u

PORT="${1:-8081}"

echo '== Redis =='
redis-cli ping || true

echo
echo '== Serviço Nexa Realtime =='
systemctl --no-pager --full status nexa-realtime.service 2>/dev/null | sed -n '1,22p' || true

echo
echo "== Porta ${PORT} =="
ss -ltnp "sport = :${PORT}" 2>/dev/null || true

echo
echo '== Health =='
curl -sS "http://127.0.0.1:${PORT}/health" || true
echo

echo
echo '== Últimos logs =='
journalctl -u nexa-realtime.service -n 50 --no-pager 2>/dev/null || true
