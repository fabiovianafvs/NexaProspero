#!/bin/bash
# Nexa Próspero - Etapa 1: o WhatsApp não pode travar o sistema.
#  1) Limita CPU e memória do serviço de WhatsApp (PM2 + Chromes).
#  2) Reinicia o WhatsApp todo dia às 4h30, limpando Chromes acumulados.
#  3) Instala o processo da fila de envio (nexa-whatsapp-outbox).
# Uso (como root): bash /var/www/nexa/deploy/whatsapp/proteger_whatsapp.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$DIR/../.." && pwd)"
export HOME=/root PM2_HOME=/root/.pm2
case "$REPO" in *homolog*) echo "ERRO: rode a partir da produção: bash /var/www/nexa/deploy/whatsapp/proteger_whatsapp.sh"; exit 1;; esac

PM2="$(command -v pm2 || true)"
[ -n "$PM2" ] || { echo "ERRO: PM2 não encontrado."; exit 1; }
"$PM2" describe nexa-whatsapp-service >/dev/null 2>&1 || { echo "ERRO: processo PM2 'nexa-whatsapp-service' não encontrado."; "$PM2" list; exit 1; }

echo "== Programas gerenciados pelo PM2 (todos recebem o mesmo limite) =="
"$PM2" list

# O PM2 precisa rodar como serviço do sistema (pm2-root) para receber limites.
UNIT=pm2-root.service
PM2_PID="$(pgrep -f 'PM2 v' | head -1 || true)"
if [ -z "$PM2_PID" ] || ! grep -q "$UNIT" "/proc/$PM2_PID/cgroup" 2>/dev/null; then
  echo "== Colocando o PM2 como serviço do sistema (o WhatsApp reconecta sozinho em 1-2 minutos) =="
  "$PM2" save
  "$PM2" startup systemd -u root --hp /root >/dev/null
  "$PM2" kill >/dev/null 2>&1 || true
  pkill -9 -f '/opt/nexa-whatsapp-service/.*session-company-' >/dev/null 2>&1 || true
  systemctl daemon-reload
  systemctl enable --now "$UNIT"
  sleep 5
fi

CORES="$(nproc)"
MEM_MB="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)"
QUOTA=$(( CORES * 60 ))          # no máximo 60% de todo o processamento
# Com 18+ empresas os Chromes passaram de 45% e o sistema ficava "espremendo" a
# memória sem parar (CPU de sistema alta = lentidão geral). Folga maior, mas
# sempre deixando pelo menos ~4,5 GB para MySQL, PHP e o resto.
HIGH=$(( MEM_MB * 62 / 100 ))     # acima disso a memória do WhatsApp é comprimida
MAX=$(( MEM_MB * 72 / 100 ))      # teto: acima disso o próprio WhatsApp é encerrado e o PM2 reabre
echo "== Limites: CPU ${QUOTA}% de $((CORES*100))% · memória ${HIGH}-${MAX} MB de ${MEM_MB} MB · prioridade baixa =="
systemctl set-property "$UNIT" CPUWeight=20 CPUQuota="${QUOTA}%" MemoryHigh="${HIGH}M" MemoryMax="${MAX}M"

echo "== Reinício diário do WhatsApp às 4h30 =="
install -o root -g root -m 0750 "$DIR/nexa-whatsapp-restart.sh" /usr/local/sbin/nexa-whatsapp-restart
install -o root -g root -m 0644 "$DIR/nexa-whatsapp-restart.service" /etc/systemd/system/nexa-whatsapp-restart.service
install -o root -g root -m 0644 "$DIR/nexa-whatsapp-restart.timer" /etc/systemd/system/nexa-whatsapp-restart.timer

echo "== Fila de envio do WhatsApp (só roda quando chega mensagem) =="
install -d -o root -g www-data -m 0750 /var/lib/nexa-whatsapp
install -d -o root -g www-data -m 0770 /var/lib/nexa-whatsapp/sinais
install -o root -g root -m 0644 "$REPO/deploy/systemd/nexa-whatsapp-outbox.service" /etc/systemd/system/nexa-whatsapp-outbox.service
install -o root -g root -m 0644 "$REPO/deploy/systemd/nexa-whatsapp-outbox.path" /etc/systemd/system/nexa-whatsapp-outbox.path

systemctl daemon-reload
systemctl enable --now nexa-whatsapp-restart.timer
systemctl enable nexa-whatsapp-outbox.service
systemctl enable --now nexa-whatsapp-outbox.path

echo
echo "Pronto:"
echo "  Limites do WhatsApp: $(systemctl show -p CPUQuotaPerSecUSec --value $UNIT) CPU/s · MemoryMax $(systemctl show -p MemoryMax --value $UNIT | awk '{printf "%d MB", $1/1048576}')"
echo "  Próximo reinício diário: $(systemctl list-timers nexa-whatsapp-restart.timer --no-legend | awk '{print $1, $2, $3}')"
echo "  Fila de envio (aguardando mensagens): $(systemctl is-active nexa-whatsapp-outbox.path)"
