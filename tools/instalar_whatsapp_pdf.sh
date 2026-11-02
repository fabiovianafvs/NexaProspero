#!/usr/bin/env bash
set -euo pipefail

SOURCE_FILE="${1:-/var/www/nexa/tools/whatsapp-service-server-com-pdf.js}"
SERVICE_DIR="${2:-/opt/nexa-whatsapp-service}"
PM2_PROCESS="${3:-nexa-whatsapp-service}"
PATCH_FILE_R7="${4:-/var/www/nexa/tools/patch_whatsapp_webjs_media_r7.js}"
PATCH_FILE_R8="${5:-/var/www/nexa/tools/patch_whatsapp_webjs_media_r8.js}"
TARGET_FILE="${SERVICE_DIR}/server.js"
STAMP="$(date +%Y%m%d-%H%M%S)"

for f in "$SOURCE_FILE" "$PATCH_FILE_R7" "$PATCH_FILE_R8"; do
  [[ -f "$f" ]] || { echo "ERRO: arquivo não encontrado: $f" >&2; exit 1; }
done
[[ -d "$SERVICE_DIR" ]] || { echo "ERRO: pasta do serviço não encontrada: $SERVICE_DIR" >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "ERRO: Node.js não instalado." >&2; exit 1; }

node --check "$SOURCE_FILE"
node --check "$PATCH_FILE_R7"
node --check "$PATCH_FILE_R8"

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

cd "$SERVICE_DIR"
node "$PATCH_FILE_R7" "$SERVICE_DIR"
node "$PATCH_FILE_R8" "$SERVICE_DIR"
node --check "$TARGET_FILE"

echo "server.js atualizado - R8: mídia/documentos + recuperação sob demanda."
echo "whatsapp-web.js 1.34.7 recebeu patches locais _serialized/$1 e MediaData.__x_id."
echo "Sessões LocalAuth e tokens.json não foram apagados. Não existe polling de sessão."

if command -v pm2 >/dev/null 2>&1; then
  if pm2 describe "$PM2_PROCESS" >/dev/null 2>&1; then
    pm2 restart "$PM2_PROCESS" --update-env
    pm2 save >/dev/null 2>&1 || true
    echo "PM2 reiniciado: $PM2_PROCESS"
  else
    echo "ERRO: processo PM2 '$PM2_PROCESS' não encontrado. Nenhum processo alternativo será reiniciado automaticamente." >&2
    exit 1
  fi
else
  echo "ERRO: PM2 não encontrado." >&2
  exit 1
fi

sleep 3
if command -v curl >/dev/null 2>&1; then
  echo "Health:" 
  curl -fsS "http://127.0.0.1:${WHATSAPP_PORT:-3333}/health"
  echo
fi

echo "Instalação R8 concluída."
