#!/usr/bin/env bash
set -Eeuo pipefail

APP_ROOT="${1:-/var/www/nexa}"
DEST_DIR="${2:-/opt/nexa/nfse/xsd-prodrest-20260727}"
ENV_FILE="${3:-/etc/nexa/nfse.env}"

OFFICIAL_URL="https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/esquemas-nfse-rtc-v1-01-20260727.zip"
PACKAGE_NAME="NFSe-ESQUEMAS_XSD-PRODREST-v1.01-20260727"

fail() {
  echo
  echo "ERRO: $*" >&2
  exit 1
}

[[ $EUID -eq 0 ]] || fail "Execute com sudo."
[[ -d "$APP_ROOT" ]] || fail "Aplicação não encontrada em: $APP_ROOT"
[[ -f "$APP_ROOT/config/nfse_runtime_env.php" ]] || fail "Arquivo config/nfse_runtime_env.php não encontrado."
[[ -f "$APP_ROOT/tools/configurar_nfse_xsd_seguro.sh" ]] || fail "Ferramenta configurar_nfse_xsd_seguro.sh não encontrada."

for cmd in unzip xmllint php find sort awk sed; do
  command -v "$cmd" >/dev/null 2>&1 || fail "Comando obrigatório não encontrado: $cmd"
done

if command -v curl >/dev/null 2>&1; then
  DOWNLOADER="curl"
elif command -v wget >/dev/null 2>&1; then
  DOWNLOADER="wget"
else
  fail "Instale curl ou wget para baixar o pacote oficial."
fi

WORK_DIR="$(mktemp -d)"
ZIP_FILE="$WORK_DIR/esquemas-nfse.zip"
EXTRACT_DIR="$WORK_DIR/extraido"
trap 'rm -rf "$WORK_DIR"' EXIT

mkdir -p "$EXTRACT_DIR"

echo "Baixando o pacote oficial:"
echo "$PACKAGE_NAME"
echo

if [[ "$DOWNLOADER" == "curl" ]]; then
  curl -L --fail --show-error --retry 3 --connect-timeout 30 \
    "$OFFICIAL_URL" -o "$ZIP_FILE"
else
  wget --tries=3 --timeout=30 -O "$ZIP_FILE" "$OFFICIAL_URL"
fi

[[ -s "$ZIP_FILE" ]] || fail "O download gerou um arquivo vazio."

echo
echo "Validando o arquivo ZIP..."
unzip -t "$ZIP_FILE" >/dev/null || fail "O arquivo baixado não é um ZIP íntegro."

unzip -q "$ZIP_FILE" -d "$EXTRACT_DIR"

XSD_COUNT="$(find "$EXTRACT_DIR" -type f -iname '*.xsd' | wc -l | tr -d ' ')"
[[ "$XSD_COUNT" -gt 0 ]] || fail "O pacote oficial não contém arquivos XSD."

XSD_ROOT=""
while IFS= read -r -d '' arquivo; do
  total="$(xmllint --xpath 'count(/*[local-name()="schema"]/*[local-name()="element"][@name="DPS"])' "$arquivo" 2>/dev/null || printf '0')"
  if [[ "$total" != "0" && "$total" != $'0\n' ]]; then
    XSD_ROOT="$arquivo"
    break
  fi
done < <(find "$EXTRACT_DIR" -type f -iname '*.xsd' -print0 | sort -z)

[[ -n "$XSD_ROOT" ]] || fail "Nenhum XSD raiz contendo o elemento DPS foi encontrado."

if [[ -d "$DEST_DIR" ]] && find "$DEST_DIR" -mindepth 1 -print -quit | grep -q .; then
  BACKUP_DIR="${DEST_DIR}.bak-$(date +%Y%m%d-%H%M%S)"
  echo "Criando cópia da pasta anterior em:"
  echo "$BACKUP_DIR"
  mv "$DEST_DIR" "$BACKUP_DIR"
fi

install -d -m 0755 "$DEST_DIR"
cp -a "$EXTRACT_DIR"/. "$DEST_DIR"/

FINAL_ROOT_REL="${XSD_ROOT#"$EXTRACT_DIR"/}"
FINAL_ROOT="$DEST_DIR/$FINAL_ROOT_REL"

[[ -f "$FINAL_ROOT" ]] || fail "O XSD raiz não foi copiado corretamente."

chmod -R a+rX "$DEST_DIR"

install -d -m 0750 "$(dirname "$ENV_FILE")"
touch "$ENV_FILE"
cp -a "$ENV_FILE" "${ENV_FILE}.bak-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true

TMP_ENV="$(mktemp)"
awk -F= '
  $1 != "NFSE_DPS_XSD_PATH" &&
  $1 != "NFSE_ENABLE_PRODUCTION" {
    print
  }
' "$ENV_FILE" > "$TMP_ENV"

printf 'NFSE_DPS_XSD_PATH=%s\n' "$FINAL_ROOT" >> "$TMP_ENV"
printf 'NFSE_ENABLE_PRODUCTION=0\n' >> "$TMP_ENV"
cat "$TMP_ENV" > "$ENV_FILE"
rm -f "$TMP_ENV"

if getent group www-data >/dev/null 2>&1; then
  chown root:www-data "$ENV_FILE"
else
  chown root:root "$ENV_FILE"
fi
chmod 0640 "$ENV_FILE"

echo
echo "Conferindo leitura pelo Nexa..."
php -r "require '$APP_ROOT/config/nfse_runtime_env.php';
echo 'NFSE_DPS_XSD_PATH='.(getenv('NFSE_DPS_XSD_PATH') ?: '[VAZIO]').PHP_EOL;
echo 'NFSE_ENABLE_PRODUCTION='.(getenv('NFSE_ENABLE_PRODUCTION') ?: '[VAZIO]').PHP_EOL;"

echo
echo "INSTALAÇÃO DOS XSDs CONCLUÍDA COM SUCESSO."
echo "Quantidade de XSDs: $XSD_COUNT"
echo "Pasta: $DEST_DIR"
echo "XSD raiz da DPS: $FINAL_ROOT"
echo "Produção permanece bloqueada: NFSE_ENABLE_PRODUCTION=0"
echo "DB_NAME, DB_HOST, DB_USER e DB_PASS não foram lidos nem alterados."
