#!/bin/bash
# Nexa Próspero - atualiza o serviço de WhatsApp (NEXA-R9):
#  - sessões salvas voltam depois de reinícios (tempo maior para abrir o Chrome,
#    reabertura sem logout e fechamento calmo antes de reiniciar);
#  - instala o reinício diário atualizado.
# Uso (como root): bash /var/www/nexa/deploy/whatsapp/atualizar_servico_whatsapp.sh
set -euo pipefail
DIR="$(cd "$(dirname "$0")" && pwd)"
REPO="$(cd "$DIR/../.." && pwd)"
case "$REPO" in *homolog*) echo "ERRO: rode a partir da produção: bash /var/www/nexa/deploy/whatsapp/atualizar_servico_whatsapp.sh"; exit 1;; esac
SOURCE="$REPO/tools/whatsapp-service-server-com-pdf.js"
SERVICE_DIR="/opt/nexa-whatsapp-service"
TARGET="$SERVICE_DIR/server.js"
# Versão que estava no repositório antes desta atualização e a nova.
OLD_SHA="cf04b503c876f983d5fced0f990e9509dd62321921a7511ae008d653d70b7cf9"
NEW_SHA="46af4f1dd51f763a6d3731fd7da699df28981e95e237f4cb1ffbc715210c1a5b"

[ -f "$TARGET" ] || { echo "ERRO: $TARGET não encontrado."; exit 1; }
CUR_SHA="$(sha256sum "$TARGET" | cut -d' ' -f1)"
if [ "$CUR_SHA" = "$NEW_SHA" ]; then
  echo "O serviço já está nesta versão. Só atualizando o reinício diário."
elif [ "$CUR_SHA" != "$OLD_SHA" ]; then
  echo "ATENÇÃO: o server.js instalado é diferente da versão conhecida do repositório."
  echo "Nada foi alterado. Mande para o Claude o resultado de:"
  echo "  diff <(git -C $REPO show ba70ebb:tools/whatsapp-service-server-com-pdf.js) $TARGET | head -80"
  exit 1
else
  PKG="$SERVICE_DIR/node_modules/whatsapp-web.js/package.json"
  VERSION="$(node -p "require('$PKG').version")"
  [ "$VERSION" = "1.34.7" ] || { echo "ERRO: esperado whatsapp-web.js 1.34.7, encontrado $VERSION. Nada foi alterado."; exit 1; }
  node --check "$SOURCE"
  STAMP="$(date +%Y%m%d-%H%M%S)"
  cp -a "$TARGET" "$TARGET.bak-$STAMP"
  echo "Backup: $TARGET.bak-$STAMP"
  OWNER="$(stat -c '%U:%G' "$TARGET")"; MODE="$(stat -c '%a' "$TARGET")"
  cp "$SOURCE" "$TARGET"; chown "$OWNER" "$TARGET"; chmod "$MODE" "$TARGET"
  node --check "$TARGET"
fi

install -o root -g root -m 0750 "$DIR/nexa-whatsapp-restart.sh" /usr/local/sbin/nexa-whatsapp-restart
/usr/local/sbin/nexa-whatsapp-restart
sleep 3
echo "Saúde do serviço:"
curl -s -m 5 "http://127.0.0.1:${WHATSAPP_PORT:-3333}/health" || echo "(sem resposta ainda; aguarde alguns segundos)"
echo
echo "Pronto. As sessões voltam aos poucos (2 por vez) nos próximos minutos pelo cron whatsapp_keepalive."
