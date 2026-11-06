#!/bin/bash
# Instala o agente de manutenção usado pelos botões do painel Saúde do Sistema.
# Uso (como root, na pasta de produção): bash /var/www/nexa/deploy/ops/instalar_nexa_ops.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
install -o root -g root -m 0750 "$DIR/nexa-ops-runner.sh" /usr/local/sbin/nexa-ops-runner
install -o root -g root -m 0644 "$DIR/nexa-ops.service" /etc/systemd/system/nexa-ops.service
install -o root -g root -m 0644 "$DIR/nexa-ops.path" /etc/systemd/system/nexa-ops.path
install -d -o root -g www-data -m 0770 /var/lib/nexa-ops/queue
install -d -o root -g www-data -m 0750 /var/lib/nexa-ops/results
install -d -o root -g www-data -m 0750 /var/lib/nexa-ops
touch /var/log/nexa-ops.log && chmod 0640 /var/log/nexa-ops.log
systemctl daemon-reload
systemctl enable --now nexa-ops.path
echo "Agente de manutenção instalado:"
systemctl is-active nexa-ops.path
