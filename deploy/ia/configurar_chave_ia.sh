#!/usr/bin/env bash
# Nexa Próspero - grava a chave da IA da plataforma (Anthropic) no servidor.
# Uso (como root):  bash /var/www/nexa/deploy/ia/configurar_chave_ia.sh
# A chave fica em /etc/nexa/ia.env (root:www-data 640), fora do código e do Git.
# Opcional: bash configurar_chave_ia.sh --modelo claude-sonnet-5-5  (padrão: claude-haiku-5-5)
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Rode como root:  sudo bash $0"
  exit 1
fi

MODELO=""
if [ "${1:-}" = "--modelo" ] && [ -n "${2:-}" ]; then
  MODELO="$2"
fi

echo "Cole a chave da API da Anthropic (começa com sk-ant-) e tecle Enter."
echo "(o que você colar não aparece na tela)"
read -rs CHAVE
echo
CHAVE="$(printf '%s' "$CHAVE" | tr -d '[:space:]')"
if [[ ! "$CHAVE" =~ ^sk-ant-[A-Za-z0-9_-]{20,}$ ]]; then
  echo "Isso não parece uma chave da Anthropic. Nada foi alterado."
  exit 1
fi

install -d -m 750 -o root -g www-data /etc/nexa
ARQ=/etc/nexa/ia.env
TMP="$(mktemp)"
{
  echo "# Chave da IA da plataforma Nexa (gravada por deploy/ia/configurar_chave_ia.sh em $(date '+%d/%m/%Y %H:%M'))"
  echo "NEXA_IA_API_KEY=$CHAVE"
  if [ -n "$MODELO" ]; then echo "NEXA_IA_MODEL=$MODELO"; fi
} > "$TMP"
install -m 640 -o root -g www-data "$TMP" "$ARQ"
rm -f "$TMP"

echo "Chave gravada em $ARQ."
echo "Testando a chave..."
if curl -sS -o /dev/null -w '%{http_code}' https://api.anthropic.com/v1/models \
     -H "x-api-key: $CHAVE" -H 'anthropic-version: 2023-06-01' | grep -q '^200$'; then
  echo "OK: a Anthropic aceitou a chave."
else
  echo "Atenção: a Anthropic não confirmou a chave agora. Confira a chave e rode o script de novo."
fi
echo "Pronto. Nas empresas com o módulo Agentes de IA, vá em Configurações > Agentes de IA e clique em Testar conexão."
