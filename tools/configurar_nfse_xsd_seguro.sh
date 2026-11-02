#!/usr/bin/env bash
set -euo pipefail

APP_ROOT="${1:-/var/www/nexa}"
XSD_DIR="${2:-/opt/nexa/nfse/xsd-prodrest-20260727}"
ENV_FILE="${3:-/etc/nexa/nfse.env}"

if [[ ! -d "$APP_ROOT" ]]; then
  echo "Pasta do Nexa não encontrada: $APP_ROOT" >&2
  exit 2
fi
if [[ ! -d "$XSD_DIR" ]]; then
  echo "Pasta dos XSDs não encontrada: $XSD_DIR" >&2
  exit 3
fi
if ! command -v xmllint >/dev/null 2>&1; then
  echo "Instale libxml2-utils para disponibilizar o comando xmllint." >&2
  exit 4
fi

XSD_RAIZ=""
while IFS= read -r -d '' arquivo; do
  total="$(xmllint --xpath 'count(/*[local-name()="schema"]/*[local-name()="element"][@name="DPS"])' "$arquivo" 2>/dev/null || printf '0')"
  if [[ "$total" != "0" && "$total" != $'0\n' ]]; then
    XSD_RAIZ="$arquivo"
    break
  fi
done < <(find "$XSD_DIR" -type f -name '*.xsd' -print0 | sort -z)

if [[ -z "$XSD_RAIZ" ]]; then
  echo "Nenhum XSD raiz com o elemento DPS foi encontrado em $XSD_DIR" >&2
  exit 5
fi

install -d -m 0750 "$(dirname "$ENV_FILE")"
touch "$ENV_FILE"
cp -a "$ENV_FILE" "${ENV_FILE}.bak-$(date +%Y%m%d-%H%M%S)" 2>/dev/null || true

tmp="$(mktemp)"
trap 'rm -f "$tmp"' EXIT
awk -F= '$1 != "NFSE_DPS_XSD_PATH" { print }' "$ENV_FILE" > "$tmp"
printf 'NFSE_DPS_XSD_PATH=%s\n' "$XSD_RAIZ" >> "$tmp"
cat "$tmp" > "$ENV_FILE"

if getent group www-data >/dev/null 2>&1; then
  chown root:www-data "$ENV_FILE"
else
  chown root:root "$ENV_FILE"
fi
chmod 0640 "$ENV_FILE"
chmod -R a+rX "$XSD_DIR"

printf '\nConfiguração segura concluída.\n'
printf 'Arquivo: %s\n' "$ENV_FILE"
printf 'XSD raiz: %s\n' "$XSD_RAIZ"
printf 'DB_NAME não foi lido nem alterado.\n\n'

php -r "require '$APP_ROOT/config/nfse_runtime_env.php'; echo 'PHP lê NFSE_DPS_XSD_PATH: '.(getenv('NFSE_DPS_XSD_PATH') ?: '[VAZIO]').PHP_EOL;"

echo
echo "Reinicie o serviço PHP usado pelo site e recarregue o servidor web."
echo "Exemplo: systemctl restart php8.3-fpm && systemctl reload nginx"
