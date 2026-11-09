#!/bin/bash
# Nexa Próspero - instala/atualiza o motor de WhatsApp leve NA VPS DO WHATSAPP.
# NÃO rode este arquivo direto: ele é enviado e executado pelo
#   bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP_DA_VPS_DO_WHATSAPP
# (rodado na VPS do sistema), que passa os parâmetros em params.env.
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
DIR="$(cd "$(dirname "$0")" && pwd)"
# shellcheck disable=SC1091
source "$DIR/params.env"
: "${MAIN_IP:?}" "${WA_WG_PRIV:?}" "${MAIN_WG_PUB:?}" "${API_TOKEN:?}" "${WEBHOOK_SECRET:?}"
APP=/opt/nexa-wa-engine
DATA=/var/lib/nexa-wa
WG_IF=wg-nexawa
WA_TUN_IP=10.77.0.2
MAIN_TUN_IP=10.77.0.1
step() { echo; echo "== $* =="; }
trap 'echo; echo "ERRO na VPS do WhatsApp (linha $LINENO): $BASH_COMMAND"' ERR

step "Pacotes do sistema"
apt-get update -qq
apt-get install -y -qq curl ca-certificates ufw wireguard >/dev/null

if ! command -v node >/dev/null || [ "$(node -p 'process.versions.node.split(".")[0]')" -lt 20 ]; then
  step "Instalando Node.js 22"
  curl -fsSL https://deb.nodesource.com/setup_22.x -o /tmp/nodesource_setup.sh
  bash /tmp/nodesource_setup.sh >/dev/null
  apt-get install -y -qq nodejs >/dev/null
fi
echo "Node $(node -v)"

step "Memória de reserva (swap) e limite de logs"
if ! swapon --show | grep -q .; then
  fallocate -l 2G /swapfile && chmod 600 /swapfile && mkswap /swapfile >/dev/null && swapon /swapfile
  grep -q '^/swapfile ' /etc/fstab || echo '/swapfile none swap sw 0 0' >> /etc/fstab
  echo 'vm.swappiness=10' > /etc/sysctl.d/90-nexa-wa.conf && sysctl -q -p /etc/sysctl.d/90-nexa-wa.conf
  echo "Swap de 2 GB criado."
fi
mkdir -p /etc/systemd/journald.conf.d
printf '[Journal]\nSystemMaxUse=300M\n' > /etc/systemd/journald.conf.d/nexa-wa.conf
systemctl restart systemd-journald

step "Usuário e pastas"
id nexawa >/dev/null 2>&1 || useradd --system --home "$DATA" --shell /usr/sbin/nologin nexawa
install -d -o root -g root -m 0755 "$APP"
install -d -o nexawa -g nexawa -m 0700 "$DATA"
install -d -o root -g nexawa -m 0750 /etc/nexa-wa

step "Programa do motor"
install -o root -g root -m 0644 "$DIR/server.mjs" "$APP/server.mjs"
if [ ! -f "$APP/package.json" ]; then
  install -o root -g root -m 0644 "$DIR/package.json" "$APP/package.json"
fi
cd "$APP"
npm install --omit=dev --no-audit --no-fund --loglevel=error pino@^9 qrcode@^1.5.3 >/dev/null
CUR_BAILEYS="$(node -p "try{require('$APP/node_modules/baileys/package.json').version}catch(e){''}")"
if [ -z "$CUR_BAILEYS" ] || [ "${ATUALIZAR_BAILEYS:-0}" = "1" ]; then
  if [ -n "${BAILEYS_VERSION:-}" ]; then
    PICK="$BAILEYS_VERSION"
  else
    # Usa a versão estável mais nova da série 7 (ou a candidata mais nova, se ainda não houver estável).
    PICK="$(npm view baileys versions --json | node -e '
      const vs = JSON.parse(require("fs").readFileSync(0, "utf8"));
      const major = (v) => Number(String(v).split(".")[0]);
      const ok = vs.filter((v) => major(v) >= 7);
      const stable = ok.filter((v) => !v.includes("-"));
      console.log((stable.length ? stable : ok).pop() || vs.pop());
    ')"
  fi
  echo "Instalando a biblioteca Baileys $PICK"
  npm install --omit=dev --no-audit --no-fund --loglevel=error --save-exact "baileys@$PICK" >/dev/null
fi
echo "Baileys $(node -p "require('$APP/node_modules/baileys/package.json').version")"
node --check "$APP/server.mjs"

step "Túnel criptografado com a VPS do sistema"
umask 077
cat > "/etc/wireguard/$WG_IF.conf" <<EOF
[Interface]
Address = $WA_TUN_IP/30
ListenPort = 51820
PrivateKey = $WA_WG_PRIV

[Peer]
PublicKey = $MAIN_WG_PUB
AllowedIPs = $MAIN_TUN_IP/32
Endpoint = $MAIN_IP:51821
EOF
umask 022
systemctl enable "wg-quick@$WG_IF" >/dev/null 2>&1
systemctl restart "wg-quick@$WG_IF"

step "Firewall (só SSH e o túnel)"
# A porta do SSH vem da VPS do sistema (é a mesma usada para chegar aqui).
SSH_PORT="${SSH_PORT:-22}"
ufw allow "$SSH_PORT/tcp" >/dev/null
ufw allow from "$MAIN_IP" to any port 51820 proto udp >/dev/null
ufw allow in on "$WG_IF" to any port 3333 proto tcp >/dev/null
ufw default deny incoming >/dev/null
ufw default allow outgoing >/dev/null
ufw --force enable >/dev/null
echo "Liberado: SSH ($SSH_PORT), túnel (51820/udp só do $MAIN_IP) e o motor só pelo túnel."

step "Configuração do motor"
umask 027
cat > /etc/nexa-wa/engine.env <<EOF
HOST=$WA_TUN_IP
PORT=3333
DATA_DIR=$DATA
API_TOKEN=$API_TOKEN
SEND_MIN_INTERVAL_MS=1500
STARTUP_STAGGER_MS=1500
BROWSER_NAME=Nexa Próspero
INBOUND_WEBHOOK_URL=http://$MAIN_TUN_IP/webhooks/whatsapp_entrada.php
INBOUND_WEBHOOK_HOST=${WEBHOOK_HOST:-app.nexaprospero.com.br}
INBOUND_WEBHOOK_SECRET=$WEBHOOK_SECRET
LOG_LEVEL=warn
EOF
umask 022
chown root:nexawa /etc/nexa-wa/engine.env
chmod 0640 /etc/nexa-wa/engine.env

step "Serviço (reinicia sozinho se cair)"
MEM_MB="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)"
HEAP=$(( MEM_MB * 60 / 100 )); [ "$HEAP" -gt 8192 ] && HEAP=8192
sed -e "s/__HEAP_MB__/$HEAP/" -e "s/__MEM_HIGH__/$(( MEM_MB * 70 / 100 ))/" -e "s/__MEM_MAX__/$(( MEM_MB * 80 / 100 ))/" \
  "$DIR/nexa-wa-engine.service" > /etc/systemd/system/nexa-wa-engine.service
systemctl daemon-reload
systemctl enable nexa-wa-engine >/dev/null 2>&1
systemctl restart nexa-wa-engine
sleep 3
systemctl --no-pager --lines=5 status nexa-wa-engine | sed -n '1,12p' || true
curl -s -m 5 "http://$WA_TUN_IP:3333/health" || echo "(motor ainda iniciando)"
echo
rm -f "$DIR/params.env"
echo "VPS do WhatsApp pronta."
