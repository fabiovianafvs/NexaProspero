#!/bin/bash
# Nexa Próspero - Fase 1 de desempenho: configuração do servidor.
#
#  1) PHP-FPM: atendia só 5 pedidos de tela ao mesmo tempo (pm.max_children=5).
#     Uma nota esperando a SEFAZ ou uma tela pesada travava a fila de todos.
#     Passa a atender até 30 ao mesmo tempo.
#  2) OPcache: guarda o código PHP já compilado na memória (o sistema tem ~4 MB
#     de código só em config/; sem isso ele é relido a cada clique).
#  3) MySQL: memória do banco de 128 MB para 4 GB (o banco tem ~1 GB e vai
#     crescer; hoje 12% das leituras vão ao disco).
#  4) Registro de consultas lentas (acima de 1 segundo) para a Fase 3.
#
# Sem tirar o sistema do ar: o PHP é recarregado de forma suave e a memória do
# MySQL é aumentada com o banco ligado. Backup de tudo antes de mudar.
# Uso (como root): bash /var/www/nexa/deploy/desempenho/ajustar_servidor.sh
set -euo pipefail
STAMP="$(date +%Y%m%d-%H%M%S)"
PHPV="$(ls /etc/php | sort -V | tail -1)"
POOL="/etc/php/$PHPV/fpm/pool.d/www.conf"
FPM_SVC="php$PHPV-fpm"
[ -f "$POOL" ] || { echo "ERRO: $POOL não encontrado."; exit 1; }

MEM_MB="$(awk '/MemTotal/{print int($2/1024)}' /proc/meminfo)"
# MySQL: 25% da memória, entre 1 e 6 GB.
BP_MB=$(( MEM_MB / 4 )); [ "$BP_MB" -lt 1024 ] && BP_MB=1024; [ "$BP_MB" -gt 6144 ] && BP_MB=6144
BP_MB=$(( BP_MB / 128 * 128 ))
# PHP: ~80 MB por processo, até 15% da memória, entre 10 e 40 processos.
CHILDREN=$(( MEM_MB * 15 / 100 / 80 )); [ "$CHILDREN" -lt 10 ] && CHILDREN=10; [ "$CHILDREN" -gt 40 ] && CHILDREN=40

echo "== Backup =="
mkdir -p /root/backup-config-nexa
cp -a "$POOL" "/root/backup-config-nexa/www.conf.$STAMP"
echo "  $POOL -> /root/backup-config-nexa/www.conf.$STAMP"

echo "== PHP-FPM: até $CHILDREN telas ao mesmo tempo (antes: $(grep -E '^\s*pm.max_children' "$POOL" | awk -F= '{print $2}' | tr -d ' ')) =="
setpool() { # chave valor
  if grep -qE "^\s*;?\s*$1\s*=" "$POOL"; then sed -i -E "s|^\s*;?\s*$1\s*=.*|$1 = $2|" "$POOL"; else echo "$1 = $2" >> "$POOL"; fi
}
setpool pm dynamic
setpool pm.max_children "$CHILDREN"
setpool pm.start_servers 6
setpool pm.min_spare_servers 4
setpool pm.max_spare_servers 12
setpool pm.max_requests 1000
setpool request_slowlog_timeout 8s
setpool slowlog "/var/log/$FPM_SVC-lento.log"

echo "== PHP: OPcache e cache de arquivos =="
cat > "/etc/php/$PHPV/fpm/conf.d/99-nexa-desempenho.ini" <<INI
; Nexa Próspero - desempenho (deploy/desempenho/ajustar_servidor.sh)
opcache.enable=1
opcache.memory_consumption=256
opcache.interned_strings_buffer=32
opcache.max_accelerated_files=40000
; Continua conferindo se o arquivo mudou (a cada 2s), para o git pull valer sem reiniciar.
opcache.validate_timestamps=1
opcache.revalidate_freq=2
realpath_cache_size=4096K
realpath_cache_ttl=600
memory_limit=256M
INI
"php-fpm$PHPV" -t
systemctl reload "$FPM_SVC"
echo "  PHP-FPM recarregado (suave)."

echo "== MySQL: memória ${BP_MB} MB e registro de consultas lentas =="
MYCNF_DIR="/etc/mysql/mysql.conf.d"; [ -d "$MYCNF_DIR" ] || MYCNF_DIR="/etc/mysql/conf.d"
cat > "$MYCNF_DIR/99-nexa-desempenho.cnf" <<CNF
# Nexa Próspero - desempenho (deploy/desempenho/ajustar_servidor.sh)
[mysqld]
innodb_buffer_pool_size = ${BP_MB}M
innodb_redo_log_capacity = 1G
tmp_table_size = 64M
max_heap_table_size = 64M
slow_query_log = 1
slow_query_log_file = /var/log/mysql/mysql-lento.log
long_query_time = 1
CNF
# Aplica agora, com o banco ligado (as mesmas opções valem após reinício pelo arquivo acima).
mysql -e "SET GLOBAL innodb_buffer_pool_size = ${BP_MB} * 1024 * 1024;
          SET GLOBAL innodb_redo_log_capacity = 1073741824;
          SET GLOBAL tmp_table_size = 67108864; SET GLOBAL max_heap_table_size = 67108864;
          SET GLOBAL slow_query_log_file = '/var/log/mysql/mysql-lento.log';
          SET GLOBAL long_query_time = 1; SET GLOBAL slow_query_log = 1;"
sleep 2
echo
echo "Pronto. Situação atual:"
mysql -N -e "SELECT CONCAT('  MySQL memória: ', ROUND(@@innodb_buffer_pool_size/1024/1024), ' MB | consultas lentas registradas acima de ', @@long_query_time, 's')"
echo "  PHP-FPM: $(grep -E '^\s*pm.max_children' "$POOL") | $(systemctl is-active "$FPM_SVC")"
echo "  OPcache: $(php -d opcache.enable_cli=1 -r 'echo ini_get("opcache.enable") ? "ligado" : "desligado";' 2>/dev/null) (CLI) - arquivo 99-nexa-desempenho.ini"
echo
echo "Para desfazer: cp /root/backup-config-nexa/www.conf.$STAMP $POOL; rm /etc/php/$PHPV/fpm/conf.d/99-nexa-desempenho.ini $MYCNF_DIR/99-nexa-desempenho.cnf; systemctl reload $FPM_SVC"
