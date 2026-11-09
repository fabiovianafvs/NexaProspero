#!/bin/bash
# Nexa Próspero - fotografia da lentidão AGORA (SOMENTE LEITURA: não altera nada).
# Rode no momento em que o sistema estiver lento, como root:
#   bash /var/www/nexa/deploy/diagnostico/lentidao_agora.sh
# Mande o resultado inteiro para o Claude.
DB="${1:-nexaprospero}"
sec() { echo; echo "===== $* ====="; }

sec "Servidor agora ($(date '+%d/%m/%Y %H:%M:%S'))"
echo "Núcleos: $(nproc) | Carga (1/5/15 min): $(cut -d' ' -f1-3 /proc/loadavg)"
free -m | awk '/^Mem:/{print "Memória: "$2" MB total, "$3" MB usada, "$7" MB disponível"} /^Swap:/{print "Swap: "$2" MB total, "$3" MB usada"}'
df -h / | tail -1 | awk '{print "Disco: "$3" usados de "$2" ("$5")"}'
echo "Espera de disco (iowait) e CPU, 3 amostras de 1s:"
vmstat 1 4 | tail -3 | awk '{print "  cpu usuário="$13"% sistema="$14"% ocioso="$15"% espera_disco="$16"%  troca(si/so)="$7"/"$8}'

sec "Processos que mais usam CPU"
ps -eo pid,user,pcpu,pmem,etime,comm --sort=-pcpu | head -12

sec "Processos que mais usam memória"
ps -eo pid,user,pmem,rss,comm --sort=-rss | head -8 | awk 'NR==1{print;next}{printf "%s %s %s%% %d MB %s\n",$1,$2,$3,$4/1024,$5}'

sec "Por serviço (CPU% somada)"
ps -eo pcpu,comm | awk 'NR>1{c[$2]+=$1}END{for(k in c) if(c[k]>1) printf "  %-22s %6.1f%%\n",k,c[k]}' | sort -k2 -nr | head -10
echo "Chrome/Chromium (WhatsApp) abertos: $(pgrep -c -f 'chrome|chromium' 2>/dev/null)"

sec "PHP-FPM"
POOL="$(ls /etc/php/*/fpm/pool.d/www.conf 2>/dev/null | head -1)"
echo "Limite (pm.max_children): $(grep -E '^\s*pm.max_children' "$POOL" 2>/dev/null | awk -F= '{print $2}' | tr -d ' ')"
echo "Processos PHP-FPM agora: $(pgrep -c -f 'php-fpm: pool')"
echo "Avisos de limite do pool (últimas 2 horas):"
journalctl -u 'php*-fpm*' --since '2 hours ago' --no-pager 2>/dev/null | grep -iE "max_children|seems busy" | tail -5 | sed 's/^/  /'
grep -hiE "max_children|seems busy" /var/log/php*-fpm.log 2>/dev/null | tail -3 | sed 's/^/  /'
SLOW="$(grep -hE '^\s*slowlog\s*=' "$POOL" 2>/dev/null | awk -F= '{print $2}' | tr -d ' ')"
if [ -n "$SLOW" ] && [ -f "$SLOW" ]; then
  echo "Scripts PHP lentos mais frequentes (slowlog do FPM):"
  grep -hE '^\[0x|script_filename' "$SLOW" 2>/dev/null | grep -o 'script_filename = .*' | sort | uniq -c | sort -nr | head -8 | sed 's/^/  /'
fi

sec "MySQL: consultas rodando agora (mais de 1 segundo)"
mysql -t -e "SELECT id, user, db, command, time AS segundos, state, LEFT(REPLACE(REPLACE(info,'\n',' '),'  ',' '),180) AS consulta FROM information_schema.processlist WHERE command <> 'Sleep' AND time >= 1 AND id <> CONNECTION_ID() ORDER BY time DESC LIMIT 15" 2>&1
mysql -N -e "SHOW GLOBAL STATUS WHERE Variable_name IN ('Threads_connected','Threads_running','Slow_queries','Innodb_row_lock_current_waits','Innodb_row_lock_waits')" 2>&1 | awk '{print "  "$1": "$2}'
echo "Transações abertas há mais de 30s:"
mysql -t -e "SELECT trx_mysql_thread_id thread, TIMESTAMPDIFF(SECOND, trx_started, NOW()) segundos, trx_rows_locked linhas_travadas, LEFT(trx_query,150) consulta FROM information_schema.innodb_trx WHERE trx_started < NOW() - INTERVAL 30 SECOND ORDER BY trx_started LIMIT 10" 2>&1

sec "MySQL: consultas lentas da última hora (log lento)"
SLOWLOG="$(mysql -N -e 'SELECT @@slow_query_log_file' 2>/dev/null)"
if [ -n "$SLOWLOG" ] && [ -f "$SLOWLOG" ] && command -v mysqldumpslow >/dev/null; then
  echo "Arquivo: $SLOWLOG ($(du -h "$SLOWLOG" | cut -f1))"
  SINCE="$(date -u -d '1 hour ago' '+%Y-%m-%dT%H:%M')"
  awk -v s="$SINCE" '/^# Time: /{keep=(substr($3,1,16)>=s)} keep' "$SLOWLOG" > /tmp/nexa_slow_ultima_hora.log 2>/dev/null
  echo "Consultas lentas na última hora: $(grep -c '^# Query_time' /tmp/nexa_slow_ultima_hora.log 2>/dev/null)"
  mysqldumpslow -s t -t 10 /tmp/nexa_slow_ultima_hora.log 2>/dev/null | cut -c1-400
  rm -f /tmp/nexa_slow_ultima_hora.log
else
  echo "Log lento não encontrado."
fi

sec "Serviços do Nexa"
for s in php8.3-fpm mysql apache2 redis-server nexa-brain-worker nexa-realtime nexa-fiscal-dfe-sync pm2-root; do
  printf "  %-24s %s\n" "$s" "$(systemctl is-active "$s" 2>/dev/null)"
done
echo "Fila de WhatsApp pendente: $(mysql -N -e "SELECT COUNT(*) FROM $DB.whatsapp_outbox WHERE status IN ('PENDENTE','PENDING','ENVIANDO')" 2>/dev/null || echo '?')"

sec "Apache: acessos dos últimos 5 minutos (páginas mais chamadas)"
LOG="$(ls -t /var/log/apache2/*access*.log 2>/dev/null | head -1)"
if [ -n "$LOG" ]; then
  SINCE="$(date -d '5 minutes ago' '+%d/%b/%Y:%H:%M')"
  awk -v s="$SINCE" '{t=substr($4,2,17)} t>=s {split($7,u,"?"); print u[1]}' "$LOG" 2>/dev/null | sort | uniq -c | sort -nr | head -12
  echo "Total de acessos nos últimos 5 min: $(awk -v s="$SINCE" '{t=substr($4,2,17)} t>=s' "$LOG" 2>/dev/null | wc -l)"
else
  echo "Log de acesso não encontrado."
fi

sec "Fim"
