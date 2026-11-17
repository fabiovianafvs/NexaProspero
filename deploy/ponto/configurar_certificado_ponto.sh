#!/usr/bin/env bash
# =============================================================================
# Nexa Ponto - configura o certificado digital do DESENVOLVEDOR do programa (REP-P).
#
# Uso (na VPS, como root):
#   bash /var/www/nexa/deploy/ponto/configurar_certificado_ponto.sh /caminho/certificado.pfx
#
# O que faz:
#  - Confere o certificado A1 (.pfx/.p12, ICP-Brasil) e a senha.
#  - Regrava o arquivo num formato que o PHP do servidor consegue ler (alguns .pfx vêm com
#    criptografia antiga) e guarda em /etc/nexa/ponto_dev.pfx (root:www-data 640).
#  - Grava a senha nova (aleatória) do arquivo regravado e os dados do programa em
#    /etc/nexa/ponto.env (root:www-data 640). A senha original não é guardada.
#    Nada disso vai para o Git nem para o banco.
#
# Com ele o Nexa assina: o AFD (arquivo .p7s, padrão CAdES) e o comprovante de ponto em PDF
# (padrão PAdES), como pede a Portaria 671 (arts. 86 e 88). Pode rodar de novo para trocar o
# certificado (ex.: quando vencer).
# =============================================================================
set -euo pipefail

ok()  { echo -e "\e[32m[OK]\e[0m $*"; }
die() { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Execute como root (sudo bash $0 arquivo.pfx)."
PFX="${1:-}"
[ -n "$PFX" ] && [ -f "$PFX" ] || die "Informe o caminho do certificado: bash $0 /root/certificado.pfx"
command -v openssl >/dev/null || die "openssl não encontrado."

echo "Digite a senha do certificado e tecle Enter (não aparece na tela):"
read -rs SENHA
echo
[ -n "$SENHA" ] || die "Senha vazia."

TMPD="$(mktemp -d)"
chmod 700 "$TMPD"
trap 'rm -rf "$TMPD"' EXIT
export SENHA

# Lê o .pfx (tenta o formato atual e, se precisar, o antigo "legacy").
if ! openssl pkcs12 -in "$PFX" -nodes -passin env:SENHA -out "$TMPD/all.pem" 2>/dev/null; then
  openssl pkcs12 -legacy -in "$PFX" -nodes -passin env:SENHA -out "$TMPD/all.pem" 2>/dev/null \
    || die "Não consegui abrir o certificado. Confira o arquivo e a senha."
fi
openssl x509 -in "$TMPD/all.pem" -noout >/dev/null 2>&1 || die "O arquivo não tem um certificado válido."
grep -q "PRIVATE KEY" "$TMPD/all.pem" || die "O arquivo não tem a chave privada (precisa ser certificado A1 .pfx)."

FIM="$(openssl x509 -in "$TMPD/all.pem" -noout -enddate | cut -d= -f2)"
TITULAR="$(openssl x509 -in "$TMPD/all.pem" -noout -subject -nameopt utf8,sep_comma_plus | sed 's/^subject=//')"
if ! openssl x509 -in "$TMPD/all.pem" -noout -checkend 0 >/dev/null; then die "Certificado vencido em $FIM."; fi

# Regrava em formato moderno (AES-256), lido pelo PHP com OpenSSL 3, com uma senha nova
# aleatória só do servidor (a senha original do certificado não fica gravada em lugar nenhum).
NOVA="$(openssl rand -hex 24)"
export NOVA
openssl pkcs12 -export -in "$TMPD/all.pem" -passout env:NOVA -keypbe AES-256-CBC -certpbe AES-256-CBC -macalg sha256 -out "$TMPD/novo.pfx" \
  || die "Não consegui regravar o certificado."

echo
echo "Dados do programa (aparecem no AFD, no AEJ e no Atestado Técnico). Enter mantém o valor entre [ ]."
ENV=/etc/nexa/ponto.env
val() { [ -f "$ENV" ] && grep -E "^$1=" "$ENV" | head -1 | cut -d= -f2- || true; }
pergunta() { local nome="$1" rotulo="$2" atual; atual="$(val "$nome")"; read -rp "$rotulo [${atual}]: " r; printf '%s' "${r:-$atual}"; }
INPI="$(pergunta NEXA_PONTO_INPI 'Número do registro no INPI')"
VERSAO="$(pergunta NEXA_PONTO_VERSAO 'Versão do programa (ex.: 1.7)')"
DEVNOME="$(pergunta NEXA_PONTO_DEV_NOME 'Razão social do desenvolvedor')"
DEVDOC="$(pergunta NEXA_PONTO_DEV_CNPJ 'CNPJ do desenvolvedor (só números)')"
DEVMAIL="$(pergunta NEXA_PONTO_DEV_EMAIL 'E-mail do desenvolvedor')"
RLEGAL="$(pergunta NEXA_PONTO_RESP_LEGAL 'Responsável legal (Nome - CPF)')"
RTEC="$(pergunta NEXA_PONTO_RESP_TECNICO 'Responsável técnico (Nome - CPF)')"
DEVDOC="$(printf '%s' "$DEVDOC" | tr -cd '0-9A-Za-z')"

install -d -m 750 -o root -g www-data /etc/nexa
install -m 640 -o root -g www-data "$TMPD/novo.pfx" /etc/nexa/ponto_dev.pfx
limpa() { printf '%s' "$1" | tr -d '\r\n"'; }
{
  echo "# Nexa Ponto - certificado do desenvolvedor e dados do programa REP-P"
  echo "# (gravado por deploy/ponto/configurar_certificado_ponto.sh em $(date '+%d/%m/%Y %H:%M'))"
  echo "NEXA_PONTO_CERT_FILE=/etc/nexa/ponto_dev.pfx"
  echo "NEXA_PONTO_CERT_PASSWORD=$NOVA"
  echo "NEXA_PONTO_INPI=$(limpa "$INPI")"
  echo "NEXA_PONTO_VERSAO=$(limpa "$VERSAO")"
  echo "NEXA_PONTO_DEV_NOME=$(limpa "$DEVNOME")"
  echo "NEXA_PONTO_DEV_CNPJ=$(limpa "$DEVDOC")"
  echo "NEXA_PONTO_DEV_EMAIL=$(limpa "$DEVMAIL")"
  echo "NEXA_PONTO_RESP_LEGAL=$(limpa "$RLEGAL")"
  echo "NEXA_PONTO_RESP_TECNICO=$(limpa "$RTEC")"
} > "$TMPD/ponto.env"
install -m 640 -o root -g www-data "$TMPD/ponto.env" "$ENV"

# Teste: assinatura CAdES de um texto qualquer.
echo "teste" > "$TMPD/t.txt"
if openssl cms -sign -binary -cades -md sha256 -outform DER -in "$TMPD/t.txt" -signer "$TMPD/all.pem" -out "$TMPD/t.p7s" 2>/dev/null; then
  ok "Teste de assinatura (CAdES) funcionou."
else
  echo "[AVISO] O teste de assinatura falhou. Avise o suporte."
fi
ok "Certificado gravado. Titular: $TITULAR"
ok "Válido até: $FIM"
echo "Lembre de rodar este script de novo antes dessa data, com o certificado renovado."
