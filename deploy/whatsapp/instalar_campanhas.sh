#!/usr/bin/env bash
# Instala o agendador das Campanhas do WhatsApp (timer a cada minuto).
# Uso (como root, na VPS de produção):  bash /var/www/nexa/deploy/whatsapp/instalar_campanhas.sh
# Na homologação não instale: lá as campanhas só montam a lista (nada é enviado).
set -euo pipefail
REPO="$(cd "$(dirname "$0")/../.." && pwd)"
if [ "$(id -u)" -ne 0 ]; then echo "Rode como root (sudo)."; exit 1; fi
if [ "$REPO" != "/var/www/nexa" ]; then echo "Use o caminho da produção: bash /var/www/nexa/deploy/whatsapp/instalar_campanhas.sh"; exit 1; fi
install -o root -g root -m 0644 "$REPO/deploy/systemd/nexa-whatsapp-campanhas.service" /etc/systemd/system/nexa-whatsapp-campanhas.service
install -o root -g root -m 0644 "$REPO/deploy/systemd/nexa-whatsapp-campanhas.timer" /etc/systemd/system/nexa-whatsapp-campanhas.timer
systemctl daemon-reload
systemctl enable --now nexa-whatsapp-campanhas.timer
echo
echo "Agendador das campanhas instalado."
systemctl list-timers nexa-whatsapp-campanhas.timer --no-pager | head -3
