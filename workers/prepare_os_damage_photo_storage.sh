#!/usr/bin/env bash
set -euo pipefail
APP_ROOT="$(cd "$(dirname "$0")/.." && pwd)"
TARGET="$APP_ROOT/storage/os_damage_photos"
mkdir -p "$TARGET"
chmod 0770 "$TARGET"
if id www-data >/dev/null 2>&1; then
  chown -R www-data:www-data "$TARGET"
  echo "[OK] Pasta preparada para www-data: $TARGET"
else
  echo "[INFO] Pasta criada: $TARGET"
  echo "[INFO] O usuário www-data não existe. Confirme que o usuário do PHP possui leitura/gravação nesta pasta."
fi
