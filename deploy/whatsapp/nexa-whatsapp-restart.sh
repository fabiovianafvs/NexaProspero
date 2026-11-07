#!/bin/bash
# Reinicia o serviço de WhatsApp (PM2) com calma, para as sessões voltarem
# sem pedir QR Code:
#  1) pede ao serviço para fechar todos os Chromes gravando a sessão (até 30s);
#  2) encerra o processo com SIGINT e espera ele sair (o PM2 sobe de novo);
#  3) só então elimina Chromes "órfãos" que tenham sobrado travados.
# As sessões são reabertas aos poucos pelo cron whatsapp_keepalive (2 por vez).
set -u
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export HOME=/root PM2_HOME=/root/.pm2
PROC="${NEXA_WA_PM2_PROCESS:-nexa-whatsapp-service}"
PORT="${WHATSAPP_PORT:-3333}"
PM2="$(command -v pm2 || echo /usr/bin/pm2)"
log() { echo "$(date '+%d/%m/%Y %H:%M:%S') $*"; }

log "Reiniciando WhatsApp ($PROC)..."
closed="$(curl -s -m 40 -X POST -H 'Content-Type: application/json' "http://127.0.0.1:${PORT}/admin/shutdown-sessions" 2>/dev/null || true)"
case "$closed" in *'"ok":true'*) log "Sessões fechadas com calma: $closed" ;; *) log "Serviço não respondeu ao fechamento calmo; seguindo." ;; esac

OLD_PID="$("$PM2" pid "$PROC" 2>/dev/null | tr -dc '0-9')"
if [ -n "$OLD_PID" ] && [ "$OLD_PID" != "0" ]; then
  kill -INT "$OLD_PID" 2>/dev/null
  for _ in $(seq 1 30); do kill -0 "$OLD_PID" 2>/dev/null || break; sleep 1; done
  kill -0 "$OLD_PID" 2>/dev/null && { log "Processo não saiu em 30s; forçando."; kill -9 "$OLD_PID" 2>/dev/null; }
fi
# Chromes que sobraram do processo antigo (perfil session-company-*).
pkill -9 -f '/opt/nexa-whatsapp-service/.*session-company-' >/dev/null 2>&1
sleep 5
if ! "$PM2" describe "$PROC" 2>/dev/null | grep -q 'status.*online'; then
  "$PM2" start "$PROC" >/dev/null 2>&1 || "$PM2" restart "$PROC" >/dev/null 2>&1
fi
log "WhatsApp reiniciado. As sessões voltam aos poucos nos próximos minutos."
