#!/usr/bin/env bash
set -euo pipefail

SOURCE_FILE="${1:-/var/www/nexa/tools/whatsapp-service-server-com-pdf.js}"
SERVICE_DIR="${2:-/opt/nexa-whatsapp-service}"
PM2_PROCESS="${3:-nexa-whatsapp-service}"
PATCH_FILE="${4:-/var/www/nexa/tools/patch_whatsapp_webjs_media_r8_1.js}"
TARGET_FILE="${SERVICE_DIR}/server.js"
STAMP="$(date +%Y%m%d-%H%M%S)"

for f in "$SOURCE_FILE" "$PATCH_FILE"; do
  [[ -f "$f" ]] || { echo "ERRO: arquivo não encontrado: $f" >&2; exit 1; }
done
[[ -d "$SERVICE_DIR" ]] || { echo "ERRO: pasta do serviço não encontrada: $SERVICE_DIR" >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "ERRO: Node.js não instalado." >&2; exit 1; }
command -v pm2 >/dev/null 2>&1 || { echo "ERRO: PM2 não encontrado." >&2; exit 1; }

node --check "$SOURCE_FILE"
node --check "$PATCH_FILE"

# Primeiro aplica somente o patch novo. Se falhar, o server em execução não é tocado.
node "$PATCH_FILE" "$SERVICE_DIR"

OWNER="root:root"; MODE="644"
if [[ -f "$TARGET_FILE" ]]; then
  OWNER="$(stat -c '%U:%G' "$TARGET_FILE" 2>/dev/null || echo root:root)"
  MODE="$(stat -c '%a' "$TARGET_FILE" 2>/dev/null || echo 644)"
  cp -a "$TARGET_FILE" "${TARGET_FILE}.bak-${STAMP}"
  echo "Backup criado: ${TARGET_FILE}.bak-${STAMP}"
fi

cp "$SOURCE_FILE" "$TARGET_FILE"
chown "$OWNER" "$TARGET_FILE" 2>/dev/null || true
chmod "$MODE" "$TARGET_FILE" 2>/dev/null || chmod 644 "$TARGET_FILE"
node --check "$TARGET_FILE"

if ! pm2 describe "$PM2_PROCESS" >/dev/null 2>&1; then
  echo "ERRO: processo PM2 '$PM2_PROCESS' não encontrado." >&2
  exit 1
fi

pm2 restart "$PM2_PROCESS" --update-env
pm2 save >/dev/null 2>&1 || true
sleep 3

echo "Health:"
curl -fsS "http://127.0.0.1:${WHATSAPP_PORT:-3333}/health"
echo

echo "Instalação R8.1 concluída. R7 não foi reaplicada. LocalAuth/tokens.json preservados. Sem polling em segundo plano."
