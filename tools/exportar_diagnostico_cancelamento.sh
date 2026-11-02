#!/usr/bin/env bash
set -euo pipefail

NUMERO="${1:-}"
EMPRESA_ID="${2:-1}"
BANCO="${NEXA_DB_NAME:-nexaprospero}"
APP_DIR="${NEXA_APP_DIR:-/var/www/nexa}"

if [[ ! "$NUMERO" =~ ^[0-9]+$ ]]; then
  echo "Uso: sudo bash $APP_DIR/tools/exportar_diagnostico_cancelamento.sh NUMERO_NFCE [EMPRESA_ID]"
  echo "Exemplo: sudo bash $APP_DIR/tools/exportar_diagnostico_cancelamento.sh 283 1"
  exit 1
fi
if [[ ! "$EMPRESA_ID" =~ ^[0-9]+$ ]]; then
  echo "EMPRESA_ID inválido."
  exit 1
fi

if [[ "${EUID:-$(id -u)}" -eq 0 ]]; then
  MYSQL=(mysql)
else
  MYSQL=(sudo mysql)
fi

if ! "${MYSQL[@]}" --batch --skip-column-names -e "SELECT 1" >/dev/null 2>&1; then
  cat <<'EOF'
Não foi possível acessar o MySQL pela autenticação administrativa do Ubuntu.
Execute o script como root com sudo ou exporte um comando MySQL compatível antes de executar.
EOF
  exit 2
fi

STAMP="$(date +%Y%m%d_%H%M%S)"
WORK="/tmp/nexa_cancelamento_${NUMERO}_${STAMP}"
ZIP_DEST="${PWD}/Diagnostico_Cancelamento_NFCE_${NUMERO}_${STAMP}.zip"
mkdir -p "$WORK"

read -r -d '' BASE_WHERE <<SQL || true
d.company_id=${EMPRESA_ID}
AND d.model='65'
AND d.number=${NUMERO}
SQL

"${MYSQL[@]}" --batch --raw --skip-column-names "$BANCO" -e "
SELECT CONCAT(
  'event_id=',COALESCE(e.id,''),'\n',
  'event_type=',COALESCE(e.event_type,''),'\n',
  'event_sequence=',COALESCE(e.event_sequence,''),'\n',
  'event_datetime=',COALESCE(DATE_FORMAT(e.event_datetime,'%Y-%m-%d %H:%i:%s'),''),'\n',
  'event_status_code=',COALESCE(e.status_code,''),'\n',
  'event_status_message=',COALESCE(e.status_message,''),'\n',
  'document_id=',COALESCE(d.id,''),'\n',
  'company_id=',COALESCE(d.company_id,''),'\n',
  'model=',COALESCE(d.model,''),'\n',
  'series=',COALESCE(d.series,''),'\n',
  'number=',COALESCE(d.number,''),'\n',
  'access_key=',COALESCE(d.access_key,''),'\n',
  'protocol_number=',COALESCE(d.protocol_number,''),'\n',
  'environment=',COALESCE(d.environment,''),'\n',
  'document_status=',COALESCE(d.status,''),'\n',
  'document_sefaz_code=',COALESCE(d.sefaz_status_code,''),'\n',
  'document_sefaz_message=',COALESCE(d.sefaz_status_message,''),'\n',
  'event_notes=',COALESCE(e.notes,'')
)
FROM fiscal_document_events e
JOIN fiscal_documents d
  ON d.id=e.fiscal_document_id AND d.company_id=e.company_id
WHERE ${BASE_WHERE}
  AND e.event_type IN ('DIAGNOSTICO_CANCELAMENTO_NFCE','CANCELAMENTO_NFCE')
ORDER BY e.id DESC
LIMIT 1;
" > "$WORK/metadata.txt"

"${MYSQL[@]}" --batch --raw --skip-column-names "$BANCO" -e "
SELECT COALESCE(e.request_xml,'')
FROM fiscal_document_events e
JOIN fiscal_documents d
  ON d.id=e.fiscal_document_id AND d.company_id=e.company_id
WHERE ${BASE_WHERE}
  AND e.event_type IN ('DIAGNOSTICO_CANCELAMENTO_NFCE','CANCELAMENTO_NFCE')
ORDER BY e.id DESC
LIMIT 1;
" > "$WORK/request.xml"

"${MYSQL[@]}" --batch --raw --skip-column-names "$BANCO" -e "
SELECT COALESCE(e.response_xml,'')
FROM fiscal_document_events e
JOIN fiscal_documents d
  ON d.id=e.fiscal_document_id AND d.company_id=e.company_id
WHERE ${BASE_WHERE}
  AND e.event_type IN ('DIAGNOSTICO_CANCELAMENTO_NFCE','CANCELAMENTO_NFCE')
ORDER BY e.id DESC
LIMIT 1;
" > "$WORK/response.xml"

"${MYSQL[@]}" --batch --raw --skip-column-names "$BANCO" -e "
SELECT COALESCE(d.xml_raw,'')
FROM fiscal_documents d
WHERE ${BASE_WHERE}
ORDER BY d.id DESC
LIMIT 1;
" > "$WORK/documento_autorizado.xml"

{
  echo "Gerado em: $(date --iso-8601=seconds)"
  echo "Servidor: $(hostname 2>/dev/null || true)"
  echo "PHP: $(php -r 'echo PHP_VERSION;' 2>/dev/null || true)"
  echo "Aplicação: $APP_DIR"
  echo
  echo "Marcadores de versão:"
  grep -nH "NEXA_PATCH_.*CANCELAMENTO" \
    "$APP_DIR/admin/fiscal_nfce_acao.php" \
    "$APP_DIR/config/fiscal_operational.php" 2>/dev/null || true
  echo
  echo "SHA-256 dos arquivos fiscais:"
  sha256sum \
    "$APP_DIR/admin/fiscal_nfce_acao.php" \
    "$APP_DIR/config/fiscal_operational.php" 2>/dev/null || true
  echo
  echo "Versão NFePHP:"
  if command -v composer >/dev/null 2>&1 && [[ -f "$APP_DIR/composer.json" ]]; then
    (cd "$APP_DIR" && composer show nfephp-org/sped-nfe --no-ansi 2>/dev/null) || true
  fi
} > "$WORK/ambiente.txt"

{
  echo "=== REQUISIÇÃO ==="
  grep -oE '<(tpAmb|cOrgao|CNPJ|CPF|chNFe|tpEvento|nSeqEvento|verEvento|nProt)>[^<]*</[^>]+>' "$WORK/request.xml" || true
  echo
  echo "=== RESPOSTA ==="
  grep -oE '<(tpAmb|cOrgao|chNFe|tpEvento|nSeqEvento|cStat|xMotivo|nProt)>[^<]*</[^>]+>' "$WORK/response.xml" || true
  echo
  echo "=== GTIN NO DOCUMENTO AUTORIZADO ==="
  grep -oE '<(cProd|cEAN|cEANTrib)>[^<]*</[^>]+>' "$WORK/documento_autorizado.xml" || true
} > "$WORK/resumo_tags.txt"

if command -v xmllint >/dev/null 2>&1; then
  for name in request response documento_autorizado; do
    if [[ -s "$WORK/${name}.xml" ]]; then
      xmllint --format "$WORK/${name}.xml" > "$WORK/${name}_formatado.xml" 2>/dev/null || true
    fi
  done
fi

if [[ ! -s "$WORK/request.xml" || ! -s "$WORK/response.xml" ]]; then
  echo "AVISO: não foi localizado o XML mais recente de diagnóstico/cancelamento para a NFC-e ${NUMERO}." > "$WORK/AVISO.txt"
fi

if command -v zip >/dev/null 2>&1; then
  (cd "$WORK" && zip -q -r "$ZIP_DEST" .)
else
  tar -czf "${ZIP_DEST%.zip}.tar.gz" -C "$WORK" .
  ZIP_DEST="${ZIP_DEST%.zip}.tar.gz"
fi
chmod 600 "$ZIP_DEST" 2>/dev/null || true

echo
echo "Diagnóstico criado em:"
echo "$ZIP_DEST"
echo
echo "ATENÇÃO: o arquivo contém chave, protocolo e XML fiscal. Compartilhe apenas em canal privado."
