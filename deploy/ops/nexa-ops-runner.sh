#!/bin/bash
# Nexa Próspero - agente de manutenção da VPS (roda como root via systemd).
#
# O painel Saúde do Sistema (PHP, usuário www-data) NÃO tem permissão de root.
# Ele só grava um pedido em /var/lib/nexa-ops/queue/<id>.req contendo UMA
# palavra: "resolver" ou "reiniciar_vps". Este script, disparado pelo
# nexa-ops.path, lê o pedido, executa apenas ações fixas e grava o relatório
# em /var/lib/nexa-ops/results/<id>.txt. Nenhum comando vem do navegador.
set -u
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin
QUEUE=/var/lib/nexa-ops/queue
RESULTS=/var/lib/nexa-ops/results
LOG=/var/log/nexa-ops.log
shopt -s nullglob
umask 022

log() { echo "$(date '+%d/%m/%Y %H:%M:%S') $*" | tee -a "$OUT" >> "$LOG"; }

service_exists() { systemctl list-unit-files --type=service --no-legend "$1.service" 2>/dev/null | grep -q .; }

# Serviços que precisam estar sempre rodando.
core_services() {
  local s
  for s in apache2 mysql mariadb php8.3-fpm redis-server; do service_exists "$s" && echo "$s"; done
  # Serviços do Nexa que ficam rodando (ignora os de execução única, ligados a timers).
  systemctl list-unit-files --type=service --state=enabled --no-legend 'nexa-*.service' 2>/dev/null | awk '{print $1}' | sed 's/\.service$//' | while read -r s; do
    [ "$s" = "nexa-ops" ] && continue
    [ "$(systemctl show -p Type --value "$s" 2>/dev/null)" = "oneshot" ] && continue
    echo "$s"
  done
}

acao_resolver() {
  log "== Resolver sem reiniciar a VPS =="
  local s st fixed=0
  for s in $(core_services | sort -u); do
    st=$(systemctl is-active "$s" 2>/dev/null)
    if [ "$st" != "active" ]; then
      log "Serviço $s estava '$st': reiniciando..."
      systemctl reset-failed "$s" >/dev/null 2>&1
      if systemctl restart "$s" >/dev/null 2>&1; then log "  $s: OK ($(systemctl is-active "$s"))"; else log "  $s: FALHOU ao reiniciar (ver: journalctl -u $s -n 50)"; fi
      fixed=$((fixed+1))
    fi
  done
  [ "$fixed" -eq 0 ] && log "Todos os serviços já estavam ativos."
  # Recarga suave: libera processos PHP/Apache travados sem derrubar quem está usando.
  if service_exists php8.3-fpm; then systemctl reload php8.3-fpm >/dev/null 2>&1 && log "PHP-FPM recarregado (suave)." || log "PHP-FPM: recarga falhou."; fi
  if service_exists apache2; then
    if apachectl configtest >/dev/null 2>&1; then systemctl reload apache2 >/dev/null 2>&1 && log "Apache recarregado (suave)."; else log "Apache: configuração com erro, recarga NÃO feita (rode: apachectl configtest)."; fi
  fi
  # Disco cheio: limpa só logs antigos do sistema e cache de pacotes.
  local used
  used=$(df --output=pcent / | tail -1 | tr -dc '0-9')
  if [ "${used:-0}" -ge 90 ]; then
    log "Disco em ${used}%: limpando logs antigos do sistema e cache de pacotes..."
    journalctl --vacuum-size=200M >/dev/null 2>&1
    apt-get clean >/dev/null 2>&1
    log "Disco agora em $(df --output=pcent / | tail -1 | tr -d ' ')."
  else
    log "Disco em ${used}% (sem limpeza)."
  fi
  log "Memória: $(free -m | awk '/^Mem:/{printf "%d MB livres de %d MB", $7, $2}')."
  log "Maiores consumidores de memória:"
  ps -eo rss,comm --sort=-rss | sed -n '2,6p' | awk '{printf "  %s: %d MB\n", $2, $1/1024}' | tee -a "$OUT" >> "$LOG"
  log "Situação final:"
  for s in $(core_services | sort -u); do log "  $s: $(systemctl is-active "$s" 2>/dev/null)"; done
  log "Concluído."
}

acao_reiniciar_vps() {
  local up
  up=$(cut -d. -f1 /proc/uptime)
  if [ "${up:-0}" -lt 600 ]; then
    log "Reinício recusado: a VPS foi reiniciada há menos de 10 minutos (proteção contra repetição)."
    return
  fi
  log "== Reiniciar a VPS == Reinício em 5 segundos. O sistema fica fora do ar por 1 a 3 minutos."
  sync
  systemd-run --on-active=5 --unit="nexa-ops-reboot-$(date +%s)" /bin/systemctl reboot >/dev/null 2>&1 || shutdown -r +1
}

mkdir -p "$QUEUE" "$RESULTS"
for req in "$QUEUE"/*.req; do
  id=$(basename "$req" .req)
  if ! [[ "$id" =~ ^[0-9]{14}-[a-f0-9]{16}$ ]]; then rm -f -- "$req"; continue; fi
  action=$(head -n1 "$req" | head -c 30 | tr -cd 'a-z_')
  who=$(sed -n '2p' "$req" | head -c 120 | tr -cd 'A-Za-z0-9@._ -')
  rm -f -- "$req"
  OUT="$RESULTS/$id.txt"
  : > "$OUT"
  log "Pedido $id por: ${who:-desconhecido}"
  case "$action" in
    resolver) acao_resolver ;;
    reiniciar_vps) acao_reiniciar_vps ;;
    *) log "Ação inválida recusada." ;;
  esac
  chmod 644 "$OUT"
done
# Guarda só os 30 relatórios mais recentes.
ls -1t "$RESULTS"/*.txt 2>/dev/null | tail -n +31 | xargs -r rm -f --
exit 0
