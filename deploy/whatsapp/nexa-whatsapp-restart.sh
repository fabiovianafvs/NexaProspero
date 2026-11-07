#!/bin/bash
# Reinicia o serviço de WhatsApp (PM2) eliminando Chromes "órfãos" que sobram
# travados. As sessões continuam salvas (LocalAuth): não precisa ler QR Code.
set -u
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
export HOME=/root PM2_HOME=/root/.pm2
PROC="${NEXA_WA_PM2_PROCESS:-nexa-whatsapp-service}"
PM2="$(command -v pm2 || echo /usr/bin/pm2)"
echo "$(date '+%d/%m/%Y %H:%M:%S') Reiniciando WhatsApp ($PROC)..."
"$PM2" stop "$PROC" >/dev/null 2>&1
sleep 3
# Somente os Chromes do serviço de WhatsApp (perfil session-company-*).
pkill -9 -f '/opt/nexa-whatsapp-service/.*session-company-' >/dev/null 2>&1
sleep 1
"$PM2" start "$PROC" >/dev/null 2>&1 || "$PM2" restart "$PROC" >/dev/null 2>&1
echo "$(date '+%d/%m/%Y %H:%M:%S') WhatsApp reiniciado. Chromes ativos: $(pgrep -fc '/opt/nexa-whatsapp-service/.*session-company-' || echo 0)"
