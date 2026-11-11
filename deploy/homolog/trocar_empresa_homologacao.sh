#!/usr/bin/env bash
# =============================================================================
# Nexa Próspero - Troca a empresa que responde em homolog.nexaprospero.com.br.
#
# Uso (na VPS, como root):
#   bash /var/www/nexa-homolog/deploy/homolog/trocar_empresa_homologacao.sh teste
#   bash /var/www/nexa-homolog/deploy/homolog/trocar_empresa_homologacao.sh empresa-padrao   (volta ao normal)
#
# Mexe SOMENTE no banco de homologação (nexaprospero_homolog). A produção não é tocada.
# Entre com os mesmos usuários e senhas da empresa escolhida.
# =============================================================================
set -euo pipefail

HML_DB=nexaprospero_homolog
HML_HOST=homolog.nexaprospero.com.br
SLUG="${1:-}"

ok()   { echo -e "\e[32m[OK]\e[0m $*"; }
die()  { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Execute como root."
[[ "$SLUG" =~ ^[a-z0-9-]+$ ]] || die "Informe o endereço (slug) da empresa. Ex.: bash $0 teste"

ID="$(mysql -N "$HML_DB" -e "SELECT id FROM companies WHERE slug='$SLUG' LIMIT 1")"
[ -n "$ID" ] || die "Empresa '$SLUG' não existe no banco de homologação. Se ela foi criada depois da última cópia, recrie a homologação: HML_EMPRESA=$SLUG bash /var/www/nexa/deploy/homolog/criar_homologacao.sh"

mysql "$HML_DB" -e "UPDATE companies SET custom_domain=NULL WHERE LOWER(custom_domain)='$HML_HOST';
                    UPDATE companies SET custom_domain='$HML_HOST' WHERE id=$ID;"
ok "https://$HML_HOST/admin/login.php agora abre a empresa '$SLUG' (id $ID)."

echo
echo "Grupo empresarial desta empresa (na cópia de homologação):"
mysql -t "$HML_DB" -e "SELECT g.id AS grupo, g.name AS nome, g.operation_mode AS modo, c.id AS empresa_id, c.slug, gc.is_primary AS principal
  FROM business_group_companies gc
  JOIN business_groups g ON g.id = gc.business_group_id
  JOIN companies c ON c.id = gc.company_id
  WHERE gc.is_active = 1 AND gc.business_group_id = (SELECT business_group_id FROM business_group_companies WHERE company_id=$ID AND is_active=1 LIMIT 1)
  ORDER BY gc.is_primary DESC, c.id;" 2>/dev/null || echo "  (estrutura Multiempresa não encontrada na cópia)"
