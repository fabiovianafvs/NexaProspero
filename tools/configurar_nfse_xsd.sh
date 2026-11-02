#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/nexa}"
XSD_DIR="${2:-/opt/nexa/nfse/xsd-prodrest-20260727}"
DB_NAME="${3:-u807353405_nexadelivery}"
ENV_DIR="/etc/nexa"
ENV_FILE="${ENV_DIR}/nexa-brain.env"

if [[ ! -d "$APP_ROOT" ]]; then
  echo "Pasta do Nexa não encontrada: $APP_ROOT" >&2
  exit 2
fi
if [[ ! -d "$XSD_DIR" ]]; then
  echo "Pasta dos XSDs não encontrada: $XSD_DIR" >&2
  exit 3
fi

if ! command -v xmllint >/dev/null 2>&1; then
  if command -v apt-get >/dev/null 2>&1; then
    apt-get update
    apt-get install -y libxml2-utils
  else
    echo "Instale o comando xmllint (pacote libxml2-utils) e execute novamente." >&2
    exit 4
  fi
fi

XSD_RAIZ=""
while IFS= read -r -d '' arquivo; do
  total="$(xmllint --xpath 'count(/*[local-name()="schema"]/*[local-name()="element"][@name="DPS"])' "$arquivo" 2>/dev/null || printf '0')"
  if [[ "$total" != "0" && "$total" != "0\n" ]]; then
    XSD_RAIZ="$arquivo"
    break
  fi
done < <(find "$XSD_DIR" -type f -name '*.xsd' -print0 | sort -z)

if [[ -z "$XSD_RAIZ" ]]; then
  echo "Nenhum XSD com o elemento raiz DPS foi encontrado em $XSD_DIR" >&2
  echo "O arquivo tiposComplexos_v1.01.xsd é auxiliar e não deve ser usado como raiz sem essa declaração." >&2
  exit 5
fi

if [[ ! -r "$XSD_RAIZ" ]]; then
  echo "O XSD foi encontrado, mas não está legível: $XSD_RAIZ" >&2
  exit 6
fi

install -d -m 0750 "$ENV_DIR"
touch "$ENV_FILE"
cp -a "$ENV_FILE" "${ENV_FILE}.bak-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true

update_env() {
  local key="$1"
  local value="$2"
  local temp
  temp="$(mktemp)"
  awk -v k="$key" -F= '$1 != k { print }' "$ENV_FILE" > "$temp"
  printf '%s=%s\n' "$key" "$value" >> "$temp"
  cat "$temp" > "$ENV_FILE"
  rm -f "$temp"
}

update_env "DB_NAME" "$DB_NAME"
update_env "NFSE_DPS_XSD_PATH" "$XSD_RAIZ"

if getent group www-data >/dev/null 2>&1; then
  chown root:www-data "$ENV_FILE"
else
  chown root:root "$ENV_FILE"
fi
chmod 0640 "$ENV_FILE"
chmod -R a+rX "$XSD_DIR"

PHPVER="$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')"
if systemctl list-unit-files "php${PHPVER}-fpm.service" >/dev/null 2>&1; then
  systemctl restart "php${PHPVER}-fpm"
fi
if systemctl is-active --quiet apache2; then
  systemctl reload apache2
fi
if systemctl is-active --quiet nginx; then
  systemctl reload nginx
fi

echo
printf 'DB_NAME configurado: %s\n' "$DB_NAME"
printf 'XSD raiz da DPS: %s\n' "$XSD_RAIZ"
printf 'Arquivo de ambiente: %s\n' "$ENV_FILE"
echo
php -r "require '$APP_ROOT/config/nexa_env.php'; echo 'PHP lê NFSE_DPS_XSD_PATH: '.(getenv('NFSE_DPS_XSD_PATH') ?: '[VAZIO]').PHP_EOL; echo 'PHP lê DB_NAME: '.(getenv('DB_NAME') ?: '[VAZIO]').PHP_EOL;"
