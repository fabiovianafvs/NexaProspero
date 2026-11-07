#!/bin/bash
# Nexa Próspero - diagnóstico de desempenho (SOMENTE LEITURA: não altera nada).
# Uso (como root): bash /var/www/nexa/deploy/diagnostico/diagnostico_desempenho.sh
# Mande o resultado inteiro para o Claude.
DB="${1:-nexaprospero}"
sec() { echo; echo "===== $* ====="; }

sec "Servidor"
echo "Núcleos: $(nproc) | Memória: $(free -m | awk '/^Mem:/{print $2" MB total, "$7" MB disponível"}') | Carga: $(cut -d' ' -f1-3 /proc/loadavg)"
df -h / | tail -1

sec "PHP (versão e OPcache: guarda o código compilado na memória)"
php -v | head -1
FPM_INI="$(ls /etc/php/*/fpm/php.ini 2>/dev/null | head -1)"
echo "php.ini do FPM: ${FPM_INI:-não encontrado}"
for k in opcache.enable opcache.memory_consumption opcache.max_accelerated_files opcache.validate_timestamps opcache.revalidate_freq opcache.interned_strings_buffer realpath_cache_size memory_limit max_execution_time; do
  v="$(grep -hE "^\s*${k}\s*=" "$FPM_INI" /etc/php/*/fpm/conf.d/*.ini 2>/dev/null | tail -1)"
  echo "  $k: ${v:-(padrão)}"
done
POOL="$(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1)"
echo "Pool PHP-FPM: ${POOL:-não encontrado}"
grep -E "^\s*pm(\.|\s*=)|request_slowlog_timeout|request_terminate_timeout" "$POOL" 2>/dev/null | sed 's/^/  /'
echo "Processos PHP-FPM agora: $(pgrep -c php-fpm)"
echo "Avisos de limite do pool (últimos 7 dias):"
journalctl -u 'php*-fpm*' --since '7 days ago' --no-pager 2>/dev/null | grep -iE "max_children|seems busy" | tail -5 | sed 's/^/  /'
grep -hiE "max_children|seems busy" /var/log/php*-fpm.log 2>/dev/null | tail -5 | sed 's/^/  /'

sec "MySQL (memória e lentidão)"
mysql -N -e "SELECT @@version, CONCAT('buffer_pool=', ROUND(@@innodb_buffer_pool_size/1024/1024),'MB'), CONCAT('max_connections=', @@max_connections), CONCAT('slow_log=', @@slow_query_log, ' long_query_time=', @@long_query_time), CONCAT('tmp_table=', ROUND(@@tmp_table_size/1024/1024),'MB')" 2>&1
mysql -N -e "SELECT CONCAT('Dados+índices do banco: ', ROUND(SUM(data_length+index_length)/1024/1024),' MB') FROM information_schema.tables WHERE table_schema='$DB'"
mysql -N -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Uptime','Slow_queries','Questions','Created_tmp_disk_tables','Innodb_buffer_pool_reads','Innodb_buffer_pool_read_requests','Threads_connected','Max_used_connections')" 2>&1 | sed 's/^/  /'

sec "Maiores tabelas"
mysql -t -e "SELECT table_name tabela, table_rows linhas, ROUND(data_length/1024/1024) dados_mb, ROUND(index_length/1024/1024) indices_mb FROM information_schema.tables WHERE table_schema='$DB' ORDER BY data_length+index_length DESC LIMIT 15"

sec "Índices das tabelas principais"
for t in pedidos pedido_itens fiscal_documents fiscal_nfse_details finance_receivables finance_movements estoque_movimentos produtos whatsapp_logs; do
  mysql -N -e "SELECT CONCAT('$t: ', GROUP_CONCAT(DISTINCT CONCAT(index_name,'(',cols,')') SEPARATOR '  ')) FROM (SELECT index_name, GROUP_CONCAT(column_name ORDER BY seq_in_index) cols FROM information_schema.statistics WHERE table_schema='$DB' AND table_name='$t' GROUP BY index_name) x" 2>/dev/null
done

sec "Empresas com mais pedidos"
mysql -t -e "SELECT company_id empresa, COUNT(*) pedidos FROM $DB.pedidos GROUP BY company_id ORDER BY 2 DESC LIMIT 5"

sec "Plano da consulta da tela de pedidos (empresa com mais pedidos)"
CID="$(mysql -N -e "SELECT company_id FROM $DB.pedidos GROUP BY company_id ORDER BY COUNT(*) DESC LIMIT 1")"
mysql -t -e "EXPLAIN SELECT * FROM $DB.pedidos WHERE company_id=$CID AND (source IS NULL OR source <> 'delivery_control') ORDER BY COALESCE(numero_pedido, id) DESC, id DESC LIMIT 300" 2>&1
mysql -t -e "EXPLAIN SELECT * FROM $DB.fiscal_documents WHERE company_id=$CID AND source_type='PEDIDO' AND model='65' ORDER BY id DESC LIMIT 300" 2>&1

sec "Fim do diagnóstico"
