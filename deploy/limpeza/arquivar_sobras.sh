#!/usr/bin/env bash
# =============================================================================
# Nexa Próspero - Arquiva as sobras do antigo fluxo de zips (LEIA_ME, README,
# VALIDACAO, MANIFESTO, .bak, .zip, .sql soltos...) de /var/www/nexa e /var/www.
#
# Uso (na VPS, como root):
#   bash arquivar_sobras.sh              -> SIMULAÇÃO: só lista, não mexe em nada
#   bash arquivar_sobras.sh --executar   -> MOVE os arquivos para /root/backups
#
# Segurança:
#   - Nada é apagado: os arquivos são MOVIDOS para /root/backups/sobras-<data>/,
#     mantendo as pastas de origem. Para desfazer, basta mover de volta.
#   - Nunca move arquivos que estão no Git (código do sistema).
#   - Nunca mexe em uploads/, storage/, vendor/, tmp/, downloads/, nem em
#     config/db.php, config/database.php, arquivos .env e logs em uso.
# =============================================================================
set -euo pipefail

PROD_DIR=/var/www/nexa
WWW_DIR=/var/www
DEST="/root/backups/sobras-$(date +%F-%H%M)"
EXECUTAR=0
[ "${1:-}" = "--executar" ] && EXECUTAR=1
[ "$(id -u)" = "0" ] || { echo "Execute como root."; exit 1; }

# Nomes típicos das sobras dos zips (na raiz de /var/www/nexa e de /var/www).
ROOT_PATTERN='^(_?LEIA[-_ ]?ME|_?LEIA_PRIMEIRO|LEIA-ME|README[-_]|README\.txt$|_?VALIDACAO|VALIDACOES|MANIFESTO|SHA256|CHECKSUMS|HASHES|REGISTRO|ALTERACOES|ARQUIVOS-ALTERADOS|AUDITORIA|TESTES_E|LISTA_ARQUIVOS|DIFERENCAS|MENU_COMPRAS|INSTALAR_|INSTALACAO_|README_INSTALACAO|README_SICOOB|API_PROSPERO)|\.(zip|patch|save|sql)$|\.bak'
# Em qualquer pasta de código: só backups e cópias (.bak*, .save).
ANY_PATTERN='\.bak|\.save$'

PROTECTED='^(config/db\.php|config/database\.php|.*\.env|.*\.log)$'
SKIP_DIRS='^(uploads|storage|vendor|tmp|downloads|node_modules)/'

list=()

in_git() { git -C "$PROD_DIR" ls-files --error-unmatch -- "$1" >/dev/null 2>&1; }

# 1) Raiz de /var/www/nexa
while IFS= read -r -d '' f; do
  rel="${f#$PROD_DIR/}"
  [[ "$rel" =~ $PROTECTED ]] && continue
  [[ "$rel" =~ $ROOT_PATTERN ]] || continue
  in_git "$rel" && continue
  list+=("$f")
done < <(find "$PROD_DIR" -maxdepth 1 -type f -print0)

# 2) Subpastas de código de /var/www/nexa: somente .bak* e .save
while IFS= read -r -d '' f; do
  rel="${f#$PROD_DIR/}"
  [[ "$rel" == */* ]] || continue
  [[ "$rel" =~ $SKIP_DIRS ]] && continue
  [[ "$(basename "$rel")" =~ $ANY_PATTERN ]] || continue
  in_git "$rel" && continue
  list+=("$f")
done < <(find "$PROD_DIR" -mindepth 2 -type f \( -name '*.bak*' -o -name '*.save' \) -not -path "$PROD_DIR/.git/*" -print0)

# 3) Arquivos soltos direto em /var/www (as pastas dos sites não são tocadas)
while IFS= read -r -d '' f; do
  name="$(basename "$f")"
  [[ "$name" =~ $ROOT_PATTERN ]] || continue
  list+=("$f")
done < <(find "$WWW_DIR" -maxdepth 1 -type f -print0)

if [ "${#list[@]}" -eq 0 ]; then
  echo "Nenhuma sobra encontrada. Nada a fazer."
  exit 0
fi

total_kb=0
for f in "${list[@]}"; do total_kb=$((total_kb + $(du -k "$f" | cut -f1))); done

echo "=== ${#list[@]} arquivos ($((total_kb / 1024)) MB) ==="
printf '%s\n' "${list[@]}" | sed "s#^$WWW_DIR/##" | sort | awk -F/ '{d=(NF>1?$1"/"(NF>2?$2"/…":""):"(raiz de /var/www)"); c[d]++} END{for (k in c) printf "  %-40s %d arquivos\n", k, c[k]}' | sort
echo
echo "Exemplos:"
printf '%s\n' "${list[@]}" | sed "s#^$WWW_DIR/##" | sort | head -15 | sed 's/^/  /'

if [ "$EXECUTAR" -ne 1 ]; then
  echo
  echo "SIMULAÇÃO: nada foi movido. Para arquivar, rode de novo com --executar"
  exit 0
fi

mkdir -p "$DEST"
for f in "${list[@]}"; do
  rel="${f#$WWW_DIR/}"
  mkdir -p "$DEST/$(dirname "$rel")"
  mv -n -- "$f" "$DEST/$rel"
done
printf '%s\n' "${list[@]}" > "$DEST/_LISTA_ORIGINAL.txt"
echo
echo "[OK] ${#list[@]} arquivos movidos para $DEST"
echo "Para desfazer: cd $DEST && find . -type f ! -name _LISTA_ORIGINAL.txt -exec sh -c 'mkdir -p \"$WWW_DIR/\$(dirname \"\$1\")\" && mv -n \"\$1\" \"$WWW_DIR/\$1\"' _ {} \\;"
