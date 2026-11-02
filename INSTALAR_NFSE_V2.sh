#!/usr/bin/env bash
set -euo pipefail
SOURCE_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APP_DIR="${NEXA_APP_DIR:-/var/www/nexa}"
TARGET="$APP_DIR/config/nfse_pronim.php"
SOURCE="$SOURCE_DIR/config/nfse_pronim.php"
ORIG='e8f3c73c161c976ca630121dc712f5d9024eaec05df799df6c85e044ab9a1020'
V1='80e5ea08dd3d0590deac6f2f45663b8b2bf100353d0d331439a8d8d6ecea16eb'
FINAL='2a2123ec935eb57f40a34e1d0de86303e89d242c3156274f43d509dcc0d97522'
[[ -f "$TARGET" && -f "$SOURCE" ]] || { echo 'ERRO: PHP de origem ou destino não encontrado.'; exit 1; }
[[ "$(sha256sum "$SOURCE" | awk '{print $1}')" == "$FINAL" ]] || { echo 'ERRO: o pacote foi alterado'; exit 1; }
CURRENT="$(sha256sum "$TARGET" | awk '{print $1}')"
if [[ "$CURRENT" == "$FINAL" ]]; then
  echo 'A versão V2 já está instalada. Nenhuma ação realizada.';exit 0
fi
if [[ "$CURRENT" != "$ORIG" && "$CURRENT" != "$V1" ]]; then
  echo "BLOQUEADO: arquivo atual difere das duas versões analisadas ($CURRENT)."
  echo 'Não sobrescreva. Envie o arquivo atual para revisão.'
  exit 2
fi
php -l "$SOURCE" >/dev/null
STAMP="$(date +%Y%m%d-%H%M%S)"
BACKUP="${TARGET}.bak-V2-${STAMP}"
cp -a "$TARGET" "$BACKUP"
TMP="${TARGET}.tmp-V2-$$"
trap 'rm -f "$TMP"' EXIT
cp "$SOURCE" "$TMP"
chown --reference="$TARGET" "$TMP"
chmod --reference="$TARGET" "$TMP"
php -l "$TMP" >/dev/null
mv -f "$TMP" "$TARGET"
trap - EXIT
echo 'INSTALACAO CONCLUIDA. Backup:'
echo "$BACKUP"
sha256sum "$TARGET"
php -l "$TARGET"
echo 'Confira OPcache e valide as próximas DPS no Pronim; nenhuma nota foi transmitida pelo instalador.'
