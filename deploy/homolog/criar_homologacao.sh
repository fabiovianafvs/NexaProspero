#!/usr/bin/env bash
# =============================================================================
# Nexa Próspero - Cria ou ATUALIZA o ambiente de homologação.
#
# Uso (na VPS, como root):
#   bash /var/www/nexa/deploy/homolog/criar_homologacao.sh
#
# O que faz:
#   1. Código: clone da branch "homolog" em /var/www/nexa-homolog.
#   2. Arquivos fora do Git: copia vendor, uploads, storage e downloads.
#   3. Banco: recria nexaprospero_homolog como cópia de nexaprospero, acessado
#      por um usuário MySQL próprio (nexa_homolog) que NÃO enxerga produção.
#   4. Trava tudo que fala com o mundo real: notas e bancos em homologação/sandbox,
#      WhatsApp e e-mail desligados, NFS-e em produção bloqueada.
#   5. Liga a empresa de testes (empresa-padrao) em homolog.nexaprospero.com.br.
#   6. Cria o site no Apache (somente na primeira vez).
#
# Pode ser executado de novo sempre que quiser uma cópia fresca do banco.
# Produção NÃO é alterada: o banco de produção só é LIDO (mysqldump).
# =============================================================================
set -euo pipefail

PROD_DIR=/var/www/nexa
HML_DIR=/var/www/nexa-homolog
HML_GITDIR=/root/git/nexa-homolog.git
PROD_DB=nexaprospero
HML_DB=nexaprospero_homolog
HML_DB_USER=nexa_homolog
HML_HOST=homolog.nexaprospero.com.br
TEST_COMPANY_SLUG="${HML_EMPRESA:-empresa-padrao}"  # outra empresa: HML_EMPRESA=slug bash criar_homologacao.sh
REPO=git@github.com:fabiovianafvs/NexaProspero.git
BRANCH=homolog
VHOST_NAME=nexa-homolog  # ordem: depois de nexa-app (site padrão), antes de nexaprospero (curinga *.)
VHOST_FILE=/etc/apache2/sites-available/${VHOST_NAME}.conf
PROD_VHOST=/etc/apache2/sites-enabled/nexaprospero.conf

ok()   { echo -e "\e[32m[OK]\e[0m $*"; }
info() { echo -e "\e[36m[..]\e[0m $*"; }
die()  { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" = "0" ] || die "Execute como root."
[ "$HML_DB" != "$PROD_DB" ] || die "Banco de homologação não pode ser o de produção."
[ "$HML_DIR" != "$PROD_DIR" ] || die "Pasta de homologação não pode ser a de produção."
command -v rsync >/dev/null || { info "Instalando rsync..."; apt-get install -y -q rsync >/dev/null; }

# -----------------------------------------------------------------------------
info "1/6 Código (branch $BRANCH)"
git config --global --add safe.directory "$HML_DIR" 2>/dev/null || true
if [ -e "$HML_DIR/.git" ]; then
  git -C "$HML_DIR" fetch -q origin
  git -C "$HML_DIR" checkout -q "$BRANCH"
  git -C "$HML_DIR" reset -q --hard "origin/$BRANCH"
else
  [ ! -e "$HML_DIR" ] || die "$HML_DIR já existe e não é um repositório Git. Verifique antes de continuar."
  mkdir -p "$(dirname "$HML_GITDIR")"
  git clone -q --branch "$BRANCH" --separate-git-dir="$HML_GITDIR" "$REPO" "$HML_DIR"
fi
ok "Código em $HML_DIR ($(git -C "$HML_DIR" log -1 --format='%h %s'))"

# -----------------------------------------------------------------------------
info "2/6 Arquivos fora do Git (vendor, uploads, storage, downloads)"
for d in vendor uploads storage downloads; do
  if [ -d "$PROD_DIR/$d" ]; then rsync -a --delete "$PROD_DIR/$d/" "$HML_DIR/$d/"; fi
done
mkdir -p "$HML_DIR/tmp"
chown www-data:www-data "$HML_DIR/tmp"
chmod 1777 "$HML_DIR/tmp" 2>/dev/null || true
ok "Arquivos copiados"

# -----------------------------------------------------------------------------
info "3/6 Banco $HML_DB (cópia de $PROD_DB) - pode levar alguns minutos"
# Senha forte: maiúsculas, minúsculas, números e símbolo (exigência do validate_password do MySQL).
HML_DB_PASS="Nx_$(openssl rand -hex 20)_Hm9"
mysql <<SQL
DROP DATABASE IF EXISTS \`$HML_DB\`;
CREATE DATABASE \`$HML_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS '$HML_DB_USER'@'localhost' IDENTIFIED BY '$HML_DB_PASS';
ALTER USER '$HML_DB_USER'@'localhost' IDENTIFIED BY '$HML_DB_PASS';
GRANT ALL PRIVILEGES ON \`$HML_DB\`.* TO '$HML_DB_USER'@'localhost';
FLUSH PRIVILEGES;
SQL
mysqldump --single-transaction --quick --routines --triggers --no-tablespaces "$PROD_DB" \
  | sed -E 's/DEFINER=`[^`]+`@`[^`]+`/DEFINER=CURRENT_USER/g' \
  | mysql "$HML_DB"
ok "Banco copiado ($(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$HML_DB'") tabelas)"

# Credenciais exclusivas da homologação (não vêm do Git, não usam variáveis de ambiente).
cat > "$HML_DIR/config/db.php" <<PHP
<?php
declare(strict_types=1);
// HOMOLOGAÇÃO - gerado por deploy/homolog/criar_homologacao.sh. Não copiar para produção.
return [
  'host' => 'localhost',
  'port' => '3306',
  'name' => '$HML_DB',
  'user' => '$HML_DB_USER',
  'pass' => '$HML_DB_PASS',
  'charset' => 'utf8mb4',
];
PHP
cat > "$HML_DIR/config/database.php" <<'PHP'
<?php
// HOMOLOGAÇÃO - usa as mesmas credenciais isoladas de db.php.
return require __DIR__ . '/db.php';
PHP
chown root:www-data "$HML_DIR/config/db.php" "$HML_DIR/config/database.php"
chmod 640 "$HML_DIR/config/db.php" "$HML_DIR/config/database.php"
ok "Usuário MySQL $HML_DB_USER com acesso somente a $HML_DB"

# -----------------------------------------------------------------------------
info "4/6 Travando integrações externas no banco de homologação"
tbl_exists() { [ "$(mysql -N -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$HML_DB' AND table_name='$1'")" = "1" ]; }

# Configurações de notas fiscais e bancos: vão para o valor padrão da coluna
# (HOMOLOGACAO ou sandbox). Registros que não puderem ser convertidos são apagados.
for t in fiscal_company_config finance_bank_billing_configs finance_bank_credentials finance_bank_services \
         inter_pix_config pagbank_config saas_reseller_pagbank_config; do
  tbl_exists "$t" || continue
  def="$(mysql -N -e "SELECT TRIM(BOTH '''' FROM COLUMN_DEFAULT) FROM information_schema.columns WHERE table_schema='$HML_DB' AND table_name='$t' AND column_name='environment'")"
  [ -n "$def" ] && [ "$def" != "NULL" ] || continue
  mysql "$HML_DB" -e "UPDATE IGNORE \`$t\` SET environment='$def' WHERE UPPER(environment) IN ('PRODUCAO','PRODUCTION','PROD');
                      DELETE FROM \`$t\` WHERE UPPER(environment) IN ('PRODUCAO','PRODUCTION','PROD');"
done

# WhatsApp e e-mail: remove configurações (a homologação nunca envia mensagens reais).
if tbl_exists app_config; then mysql "$HML_DB" -e "DELETE FROM app_config WHERE cfg_key IN ('whatsapp','saas_email');"; fi
for t in saas_whatsapp_config saas_reseller_whatsapp_config user_email_settings; do
  if tbl_exists "$t"; then mysql "$HML_DB" -e "DELETE FROM \`$t\`;"; fi
done

# Empresa de testes respondendo no endereço de homologação.
mysql "$HML_DB" -e "UPDATE companies SET custom_domain=NULL WHERE LOWER(custom_domain)='$HML_HOST';
                    UPDATE companies SET custom_domain='$HML_HOST' WHERE slug='$TEST_COMPANY_SLUG';"

restantes=0
for t in fiscal_company_config finance_bank_billing_configs finance_bank_credentials finance_bank_services \
         inter_pix_config pagbank_config saas_reseller_pagbank_config; do
  tbl_exists "$t" || continue
  n="$(mysql -N "$HML_DB" -e "SELECT COUNT(*) FROM \`$t\` WHERE UPPER(environment) IN ('PRODUCAO','PRODUCTION','PROD')")"
  restantes=$((restantes + n))
done
[ "$restantes" = "0" ] || die "Ainda existem $restantes configurações em produção no banco de homologação. Abortado."
ok "Notas e bancos em homologação/sandbox; WhatsApp e e-mail desligados"

# -----------------------------------------------------------------------------
info "5/6 Site no Apache ($HML_HOST)"
if [ ! -f "$VHOST_FILE" ]; then
  [ -f "$PROD_VHOST" ] || die "Não encontrei $PROD_VHOST para usar como modelo."
  awk 'BEGIN{RS="</VirtualHost>"} /ServerAlias[ \t]+\*\.nexaprospero\.com\.br/{print $0 "</VirtualHost>"; exit}' "$PROD_VHOST" \
    | sed -n '/<VirtualHost/,$p' \
    | sed -E \
        -e "s#^([[:space:]]*)ServerName .*#\1ServerName $HML_HOST\n\
\1\# Ambiente de HOMOLOGAÇÃO: integrações externas desligadas\n\
\1SetEnv NEXA_AMBIENTE homologacao\n\
\1SetEnv NFSE_ENABLE_PRODUCTION 0\n\
\1SetEnv WHATSAPP_SERVICE_URL http://127.0.0.1:9\n\
\1SetEnv SAAS_WHATSAPP_SERVICE_URL http://127.0.0.1:9\n\
\1SetEnv REDIS_DATABASE 9\n\
\1SetEnv NEXA_REALTIME_REDIS_STREAM nexa:homolog:events\n\
\1SetEnv NEXA_BRAIN_REDIS_STREAM nexa:homolog:events#" \
        -e '/^[[:space:]]*ServerAlias/d' \
        -e "s#/var/www/nexa([/\" >]|\$)#$HML_DIR\1#g" \
        -e 's#(\$\{APACHE_LOG_DIR\}/)#\1homolog-#g' \
    > "$VHOST_FILE"
  grep -q "DocumentRoot $HML_DIR" "$VHOST_FILE" || { rm -f "$VHOST_FILE"; die "Não consegui montar o site de homologação a partir do modelo."; }
  a2ensite -q "$VHOST_NAME" >/dev/null
  if ! apache2ctl configtest >/dev/null 2>&1; then
    a2dissite -q "$VHOST_NAME" >/dev/null; rm -f "$VHOST_FILE"
    die "Configuração do Apache inválida. Nada foi alterado em produção."
  fi
  systemctl reload apache2
  ok "Site criado: $VHOST_FILE"
else
  ok "Site já existia: $VHOST_FILE"
fi

# -----------------------------------------------------------------------------
info "6/6 Verificação"
code_login="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HML_HOST" "http://127.0.0.1/admin/login.php")"
code_tools="$(curl -s -o /dev/null -w '%{http_code}' -H "Host: $HML_HOST" "http://127.0.0.1/tools/diagnosticar_comandas.php")"
echo "  admin/login.php -> $code_login (esperado 200)"
echo "  tools/...       -> $code_tools (esperado 403)"
echo
ok "Homologação pronta: https://$HML_HOST/admin/login.php (mesmos usuários e senhas da empresa $TEST_COMPANY_SLUG)"
