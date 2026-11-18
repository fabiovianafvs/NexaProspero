#!/usr/bin/env bash
# =============================================================================
# Nexa - grava o RESPONSÁVEL TÉCNICO (empresa desenvolvedora do sistema) usado no
# grupo infRespTec dos documentos fiscais eletrônicos (CT-e; depois MDF-e).
#
# Uso (na VPS, como root):
#   bash /var/www/nexa/deploy/fiscal/configurar_responsavel_tecnico.sh
#
# Pergunta CNPJ, nome do contato, e-mail e telefone da empresa desenvolvedora e grava em
# /etc/nexa/resp_tecnico.env (root:www-data 640). Vale para todas as empresas do Nexa.
# Não são dados secretos, mas ficam fora do Git e do banco como os demais ajustes do servidor.
# Pode rodar de novo para alterar.
# =============================================================================
set -euo pipefail

ok()  { echo -e "\e[32m[OK]\e[0m $*"; }
die() { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Execute como root (sudo bash $0)."

ler() { local p="$1" v; read -rp "$p: " v; echo "$v"; }
CNPJ="$(ler 'CNPJ da empresa desenvolvedora (só números)' | tr -cd '0-9')"
[ "${#CNPJ}" -eq 14 ] || die "CNPJ deve ter 14 números."
CONTATO="$(ler 'Nome da pessoa de contato')"
[ -n "$CONTATO" ] || die "Informe o nome do contato."
EMAIL="$(ler 'E-mail de contato')"
[[ "$EMAIL" == *@*.* ]] || die "E-mail inválido."
FONE="$(ler 'Telefone com DDD (só números)' | tr -cd '0-9')"
[ "${#FONE}" -ge 10 ] && [ "${#FONE}" -le 12 ] || die "Telefone deve ter de 10 a 12 números (com DDD)."

limpa() { printf '%s' "$1" | tr -d '"\\\r\n'; }
mkdir -p /etc/nexa
ARQ=/etc/nexa/resp_tecnico.env
{
  echo "# Responsável técnico dos documentos fiscais (infRespTec). Gerado por configurar_responsavel_tecnico.sh em $(date '+%d/%m/%Y %H:%M')."
  echo "NEXA_RESP_TEC_CNPJ=\"$CNPJ\""
  echo "NEXA_RESP_TEC_CONTATO=\"$(limpa "$CONTATO")\""
  echo "NEXA_RESP_TEC_EMAIL=\"$(limpa "$EMAIL")\""
  echo "NEXA_RESP_TEC_FONE=\"$FONE\""
} > "$ARQ"
chown root:www-data "$ARQ"
chmod 640 "$ARQ"
ok "Responsável técnico gravado em $ARQ. Os próximos CT-e já saem com estes dados."
