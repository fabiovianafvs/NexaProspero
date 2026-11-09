#!/bin/bash
# Nexa Próspero - instala (ou atualiza) o motor de WhatsApp leve numa VPS própria.
#
# Rode NA VPS DO SISTEMA, como root:
#   bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP_DA_VPS_DO_WHATSAPP [PORTA_SSH]
#
# O que faz, num comando só:
#  1) cria uma chave SSH e pede a senha de root da VPS do WhatsApp UMA vez;
#  2) instala lá o Node.js, o motor (Baileys, sem Chrome), firewall e o serviço;
#  3) cria um túnel criptografado (WireGuard) entre as duas VPS: o motor só
#     aceita conexões vindas do sistema, pelo túnel;
#  4) grava no banco o endereço e as chaves do motor.
# Pode rodar de novo para atualizar o motor: as sessões das empresas são mantidas.
# Opcional: ATUALIZAR_BAILEYS=1 antes do comando atualiza também a biblioteca.
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$DIR/../.." && pwd)"
case "$REPO" in *homolog*) echo "ERRO: rode a partir da produção: bash /var/www/nexa/deploy/whatsapp-engine/instalar_motor_whatsapp.sh IP"; exit 1;; esac
[ "$(id -u)" = "0" ] || { echo "ERRO: rode como root."; exit 1; }
WA_IP="${1:-}"
SSH_PORT="${2:-22}"
if ! [[ "$WA_IP" =~ ^[0-9]{1,3}(\.[0-9]{1,3}){3}$ ]]; then
  echo "Uso: bash $0 IP_DA_VPS_DO_WHATSAPP [PORTA_SSH]"
  exit 1
fi
WG_IF=wg-nexawa
WG_DIR=/etc/wireguard/nexawa
KEY=/root/.ssh/nexa_wa_ed25519
SSHO=(-i "$KEY" -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
SCPO=(-i "$KEY" -P "$SSH_PORT" -o StrictHostKeyChecking=accept-new -o ConnectTimeout=15)
step() { echo; echo "== $* =="; }
trap 'echo; echo "ERRO (linha $LINENO): $BASH_COMMAND"; echo "Mande um print desta tela para o Claude."' ERR

step "Acesso à VPS do WhatsApp ($WA_IP)"
[ -f "$KEY" ] || ssh-keygen -q -t ed25519 -N '' -f "$KEY" -C "nexa-sistema->whatsapp"
if ! ssh "${SSHO[@]}" -o BatchMode=yes "root@$WA_IP" true 2>/dev/null; then
  echo "Digite a senha de root da VPS do WhatsApp (só desta vez):"
  ssh-copy-id -i "$KEY.pub" -p "$SSH_PORT" -o StrictHostKeyChecking=accept-new "root@$WA_IP" >/dev/null
fi
ssh "${SSHO[@]}" -o BatchMode=yes "root@$WA_IP" true
echo "Acesso OK."

step "Chaves do túnel"
command -v wg >/dev/null || { apt-get update -qq && DEBIAN_FRONTEND=noninteractive apt-get install -y -qq wireguard >/dev/null; }
install -d -m 0700 "$WG_DIR"
umask 077
[ -f "$WG_DIR/main.key" ] || wg genkey > "$WG_DIR/main.key"
[ -f "$WG_DIR/wa.key" ] || wg genkey > "$WG_DIR/wa.key"
umask 022
MAIN_PUB="$(wg pubkey < "$WG_DIR/main.key")"
WA_PUB="$(wg pubkey < "$WG_DIR/wa.key")"
MAIN_IP="${MAIN_IP:-$(ip -4 route get 1.1.1.1 | awk '{for(i=1;i<=NF;i++) if($i=="src"){print $(i+1); exit}}')}"
[ -n "$MAIN_IP" ] || { echo "ERRO: não consegui descobrir o IP desta VPS. Rode com MAIN_IP=x.x.x.x antes do comando."; exit 1; }
echo "IP desta VPS: $MAIN_IP"

step "Senhas do motor (reaproveita as existentes)"
OLD_ENV="$(ssh "${SSHO[@]}" "root@$WA_IP" "cat /etc/nexa-wa/engine.env 2>/dev/null || true")"
API_TOKEN="$(printf '%s\n' "$OLD_ENV" | sed -n 's/^API_TOKEN=//p' | head -1)"
WEBHOOK_SECRET="$(printf '%s\n' "$OLD_ENV" | sed -n 's/^INBOUND_WEBHOOK_SECRET=//p' | head -1)"
[ ${#API_TOKEN} -ge 32 ] || API_TOKEN="$(openssl rand -hex 32)"
[ ${#WEBHOOK_SECRET} -ge 32 ] || WEBHOOK_SECRET="$(openssl rand -hex 32)"

step "Enviando arquivos"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT
umask 077
cat > "$TMP/params.env" <<EOF
MAIN_IP=$MAIN_IP
WA_WG_PRIV=$(cat "$WG_DIR/wa.key")
MAIN_WG_PUB=$MAIN_PUB
API_TOKEN=$API_TOKEN
WEBHOOK_SECRET=$WEBHOOK_SECRET
ATUALIZAR_BAILEYS=${ATUALIZAR_BAILEYS:-0}
BAILEYS_VERSION=${BAILEYS_VERSION:-}
SSH_PORT=$SSH_PORT
EOF
umask 022
cp "$REPO/whatsapp-engine/server.mjs" "$REPO/whatsapp-engine/package.json" "$DIR/remoto_vps_whatsapp.sh" "$DIR/nexa-wa-engine.service" "$TMP/"
ssh "${SSHO[@]}" "root@$WA_IP" "install -d -m 0700 /root/nexa-wa-install"
scp -q "${SCPO[@]}" "$TMP"/* "root@$WA_IP:/root/nexa-wa-install/"

step "Instalando na VPS do WhatsApp (pode levar alguns minutos)"
ssh "${SSHO[@]}" "root@$WA_IP" "bash /root/nexa-wa-install/remoto_vps_whatsapp.sh"

step "Túnel do lado do sistema"
umask 077
cat > "/etc/wireguard/$WG_IF.conf" <<EOF
[Interface]
Address = 10.77.0.1/30
ListenPort = 51821
PrivateKey = $(cat "$WG_DIR/main.key")

[Peer]
PublicKey = $WA_PUB
AllowedIPs = 10.77.0.2/32
Endpoint = $WA_IP:51820
EOF
umask 022
systemctl enable "wg-quick@$WG_IF" >/dev/null 2>&1
systemctl restart "wg-quick@$WG_IF"
if command -v ufw >/dev/null && ufw status 2>/dev/null | grep -q "Status: active"; then
  ufw allow from "$WA_IP" to any port 51821 proto udp >/dev/null
  ufw allow in on "$WG_IF" to any port 80 proto tcp >/dev/null
  echo "Firewall desta VPS: túnel liberado só para $WA_IP."
fi

step "Testando o motor pelo túnel"
HEALTH=""
for _ in $(seq 1 15); do
  HEALTH="$(curl -s -m 4 http://10.77.0.2:3333/health || true)"
  case "$HEALTH" in *'"ok":true'*) break ;; esac
  sleep 3
done
case "$HEALTH" in
  *'"ok":true'*) echo "$HEALTH" ;;
  *) echo "ERRO: o motor não respondeu pelo túnel. Mande para o Claude o resultado de:"
     echo "  wg show; ssh -i $KEY -p $SSH_PORT root@$WA_IP 'journalctl -u nexa-wa-engine -n 40 --no-pager'"
     exit 1 ;;
esac

step "Registrando o motor no sistema"
ENGINE_FILE=/etc/nexa/whatsapp_engine.env
INSTALLED_AT="$(sed -n 's/^NEXA_WA_INSTALLED_AT=//p' "$ENGINE_FILE" 2>/dev/null | head -1 || true)"
[ -n "$INSTALLED_AT" ] || INSTALLED_AT="$(date '+%Y-%m-%d %H:%M:%S')"
install -d -m 0755 /etc/nexa
umask 027
{
  echo "# Motor de WhatsApp leve (gerado pelo instalador; não editar à mão)"
  echo "NEXA_WA_URL=http://10.77.0.2:3333"
  echo "NEXA_WA_TOKEN=$API_TOKEN"
  echo "NEXA_WA_SECRET=$WEBHOOK_SECRET"
  echo "NEXA_WA_INSTALLED_AT=$INSTALLED_AT"
} > "$ENGINE_FILE.tmp"
umask 022
chown root:www-data "$ENGINE_FILE.tmp"
chmod 0640 "$ENGINE_FILE.tmp"
mv "$ENGINE_FILE.tmp" "$ENGINE_FILE"
php "$REPO/workers/whatsapp_engine_config.php" --mostrar

echo
echo "Pronto! Agora, na Central SaaS > WhatsApp > Motor novo (leve), passe as empresas"
echo "uma de cada vez: https://app.nexaprospero.com.br/saas/whatsapp_motor.php"
