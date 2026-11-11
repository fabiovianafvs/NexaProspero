#!/usr/bin/env bash
# =============================================================================
# Nexa Próspero - Endereço EXTRA de homologação apontando para outra empresa.
#
# Uso (na VPS, como root):
#   bash /var/www/nexa-homolog/deploy/homolog/adicionar_endereco_homologacao.sh homolog-multi.nexaprospero.com.br teste
#
# - O endereço principal (homolog.nexaprospero.com.br -> empresa-padrao) NÃO muda.
# - O endereço extra usa o mesmo código (/var/www/nexa-homolog), o mesmo banco de
#   homologação e as mesmas travas (notas/bancos em teste, WhatsApp e e-mail desligados).
# - Fica salvo em /etc/nexa/homolog_enderecos.conf e é refeito automaticamente quando
#   a homologação é recriada (criar_homologacao.sh).
# - Produção não é alterada.
# =============================================================================
set -euo pipefail

HML_DB=nexaprospero_homolog
HML_MAIN_HOST=homolog.nexaprospero.com.br
VHOST_FILE=/etc/apache2/sites-available/nexa-homolog.conf
MAP_FILE=/etc/nexa/homolog_enderecos.conf
HOST="$(echo "${1:-}" | tr 'A-Z' 'a-z')"
SLUG="${2:-}"

ok()   { echo -e "\e[32m[OK]\e[0m $*"; }
die()  { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Execute como root."
[[ "$HOST" =~ ^[a-z0-9-]+\.nexaprospero\.com\.br$ ]] || die "Endereço inválido. Ex.: homolog-multi.nexaprospero.com.br"
[ "$HOST" != "$HML_MAIN_HOST" ] || die "Este é o endereço principal da homologação; escolha outro nome."
[[ "$SLUG" =~ ^[a-z0-9-]+$ ]] || die "Informe o endereço (slug) da empresa. Ex.: teste"
[ -f "$VHOST_FILE" ] || die "Site de homologação não encontrado ($VHOST_FILE). Rode primeiro criar_homologacao.sh."

ID="$(mysql -N "$HML_DB" -e "SELECT id FROM companies WHERE slug='$SLUG' LIMIT 1")"
[ -n "$ID" ] || die "Empresa '$SLUG' não existe no banco de homologação. Recrie a cópia: bash /var/www/nexa/deploy/homolog/criar_homologacao.sh"

# 1) Apache: o endereço extra entra no MESMO site da homologação (antes do curinga da produção).
if ! grep -qiE "^[[:space:]]*ServerAlias[[:space:]].*\b${HOST//./\\.}\b" "$VHOST_FILE"; then
  cp "$VHOST_FILE" "$VHOST_FILE.bak"
  sed -i -E "0,/^([[:space:]]*)ServerName[[:space:]]+${HML_MAIN_HOST//./\\.}[[:space:]]*$/s##&\n\1ServerAlias $HOST#" "$VHOST_FILE"
  grep -qi "ServerAlias $HOST" "$VHOST_FILE" || { mv "$VHOST_FILE.bak" "$VHOST_FILE"; die "Não consegui incluir o endereço no site de homologação."; }
  if ! apache2ctl configtest >/dev/null 2>&1; then
    mv "$VHOST_FILE.bak" "$VHOST_FILE"
    die "Configuração do Apache inválida. Nada foi alterado."
  fi
  rm -f "$VHOST_FILE.bak"
  systemctl reload apache2
  ok "Endereço $HOST incluído no site de homologação."
else
  ok "Endereço $HOST já estava no site de homologação."
fi

# 2) Guarda o vínculo endereço -> empresa (refeito quando a homologação é recriada).
mkdir -p /etc/nexa
touch "$MAP_FILE"
grep -viE "^${HOST//./\\.}[[:space:]]" "$MAP_FILE" > "$MAP_FILE.tmp" || true
echo "$HOST $SLUG" >> "$MAP_FILE.tmp"
mv "$MAP_FILE.tmp" "$MAP_FILE"
chmod 644 "$MAP_FILE"

# 3) Banco de homologação: a empresa responde nesse endereço.
mysql "$HML_DB" -e "UPDATE companies SET custom_domain=NULL WHERE LOWER(custom_domain)='$HOST';
                    UPDATE companies SET custom_domain='$HOST' WHERE id=$ID;"
ok "Empresa '$SLUG' (id $ID) responde em https://$HOST/admin/login.php"

code="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HOST" "http://127.0.0.1/admin/login.php")"
echo "  admin/login.php -> $code (esperado 200)"
echo "  Se o endereço não abrir no navegador, crie na Cloudflare um registro DNS igual ao do 'homolog' com o nome '${HOST%%.nexaprospero.com.br}'."
