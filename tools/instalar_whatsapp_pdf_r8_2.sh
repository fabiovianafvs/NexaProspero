#!/usr/bin/env bash
set -euo pipefail

SOURCE_FILE="${1:-/var/www/nexa/tools/whatsapp-service-server-com-pdf.js}"
SERVICE_DIR="${2:-/opt/nexa-whatsapp-service}"
PM2_PROCESS="${3:-nexa-whatsapp-service}"
TARGET_FILE="${SERVICE_DIR}/server.js"
STAMP="$(date +%Y%m%d-%H%M%S)"

[[ -f "$SOURCE_FILE" ]] || { echo "ERRO: arquivo não encontrado: $SOURCE_FILE" >&2; exit 1; }
[[ -d "$SERVICE_DIR" ]] || { echo "ERRO: pasta do serviço não encontrada: $SERVICE_DIR" >&2; exit 1; }
command -v node >/dev/null 2>&1 || { echo "ERRO: Node.js não instalado." >&2; exit 1; }
command -v pm2 >/dev/null 2>&1 || { echo "ERRO: PM2 não encontrado." >&2; exit 1; }

# A R8.2 é somente de serviço. Exige que os patches R7/R8.1 já estejam presentes,
# mas não altera novamente o node_modules.
PKG="$SERVICE_DIR/node_modules/whatsapp-web.js/package.json"
UTILS="$SERVICE_DIR/node_modules/whatsapp-web.js/src/util/Injected/Utils.js"
BASE="$SERVICE_DIR/node_modules/whatsapp-web.js/src/structures/Base.js"
[[ -f "$PKG" && -f "$UTILS" && -f "$BASE" ]] || { echo "ERRO: whatsapp-web.js não encontrado no serviço." >&2; exit 1; }
VERSION="$(node -p "require('$PKG').version")"
[[ "$VERSION" == "1.34.7" ]] || { echo "ERRO: R8.2 homologada para whatsapp-web.js 1.34.7. Encontrado: $VERSION" >&2; exit 1; }
grep -q 'static _normalizeId(id)' "$BASE" || { echo "ERRO: patch R7 não detectado." >&2; exit 1; }
grep -q 'NEXA-R8: remove MediaData private id' "$UTILS" || { echo "ERRO: patch R8.1 não detectado." >&2; exit 1; }

node --check "$SOURCE_FILE"
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
pm2 describe "$PM2_PROCESS" >/dev/null 2>&1 || { echo "ERRO: processo PM2 '$PM2_PROCESS' não encontrado." >&2; exit 1; }
pm2 restart "$PM2_PROCESS" --update-env
pm2 save >/dev/null 2>&1 || true
sleep 3

echo "Health:"
curl -fsS "http://127.0.0.1:${WHATSAPP_PORT:-3333}/health"
echo

echo "Instalação R8.2 concluída. Apenas o serviço foi atualizado; patches R7/R8.1 e LocalAuth foram preservados. Sem polling em segundo plano."
