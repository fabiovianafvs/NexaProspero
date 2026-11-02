#!/usr/bin/env bash
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/nexa}"
ENV_FILE="${ENV_FILE:-/etc/nexa/nexa-brain.env}"
SERVICE_NAME="nexa-realtime.service"

log(){ printf '%s\n' "$*"; }

if [[ $EUID -ne 0 ]]; then
  echo "Execute como root: sudo bash $APP_DIR/deploy/install-nexa-realtime.sh"
  exit 1
fi

log "== Nexa Realtime V2: instalação/atualização =="
command -v node >/dev/null || { echo "ERRO: Node.js não encontrado."; exit 1; }
command -v redis-cli >/dev/null || { echo "ERRO: redis-cli não encontrado."; exit 1; }
command -v curl >/dev/null || { echo "ERRO: curl não encontrado."; exit 1; }
command -v ss >/dev/null || { echo "ERRO: comando ss não encontrado."; exit 1; }

mkdir -p /etc/nexa
if [[ ! -f "$ENV_FILE" ]]; then
  if [[ -f "$APP_DIR/nexa-brain.env" ]]; then
    cp "$APP_DIR/nexa-brain.env" "$ENV_FILE"
  else
    SECRET="$(openssl rand -hex 32)"
    cat > "$ENV_FILE" <<ENV
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_PASSWORD=
REDIS_DATABASE=0
REDIS_TIMEOUT=1.2
NEXA_BRAIN_REDIS_STREAM=nexa:brain:events
NEXA_BRAIN_STREAM_MAXLEN=20000
NEXA_BRAIN_WS_HOST=127.0.0.1
NEXA_BRAIN_WS_PORT=8081
NEXA_BRAIN_WS_PATH=/brain-ws
NEXA_BRAIN_WS_SECRET=$SECRET
NEXA_BRAIN_WS_URL=auto
NEXA_BRAIN_WS_ROOT_DOMAIN=nexaprospero.com.br
NEXA_BRAIN_ALLOWED_ORIGINS=https://*.nexaprospero.com.br,https://nexaprospero.com.br
ENV
  fi
  chmod 640 "$ENV_FILE"
  chown root:www-data "$ENV_FILE" || true
fi

set_env(){
  local key="$1" value="$2"
  if grep -qE "^${key}=" "$ENV_FILE"; then
    sed -i -E "s|^${key}=.*$|${key}=${value}|" "$ENV_FILE"
  else
    printf '%s=%s\n' "$key" "$value" >> "$ENV_FILE"
  fi
}

set_env NEXA_BRAIN_WS_URL auto
set_env NEXA_BRAIN_WS_ROOT_DOMAIN nexaprospero.com.br
set_env NEXA_BRAIN_ALLOWED_ORIGINS 'https://*.nexaprospero.com.br,https://nexaprospero.com.br'
set_env NEXA_REALTIME_RECENT_WINDOW_MS 600000
set_env NEXA_REALTIME_RECENT_MAX_PER_COMPANY 100
if ! grep -qE '^NEXA_BRAIN_WS_SECRET=.{20,}$' "$ENV_FILE"; then
  set_env NEXA_BRAIN_WS_SECRET "$(openssl rand -hex 32)"
fi

WS_PORT="$(awk -F= '/^NEXA_REALTIME_WS_PORT=/{v=$2} /^NEXA_BRAIN_WS_PORT=/{if(v=="")v=$2} END{gsub(/[[:space:]]/,"",v); print v}' "$ENV_FILE")"
WS_PORT="${WS_PORT:-8081}"

redis-cli ping | grep -q PONG || { echo "ERRO: Redis não respondeu PONG."; exit 1; }
node --check "$APP_DIR/realtime-server/server.js"
cp "$APP_DIR/deploy/systemd/nexa-realtime.service" /etc/systemd/system/nexa-realtime.service
systemctl daemon-reload

# Para a própria unidade antes de migrar qualquer processo antigo, evitando corrida de auto-restart.
systemctl stop "$SERVICE_NAME" >/dev/null 2>&1 || true
systemctl reset-failed "$SERVICE_NAME" >/dev/null 2>&1 || true

# Unidades legadas conhecidas do Nexa Brain/Reatime.
for unit in nexa-brain-websocket.service nexa-brain-realtime.service nexa-brain.service; do
  if systemctl list-unit-files --no-legend 2>/dev/null | awk '{print $1}' | grep -qx "$unit"; then
    log "Desativando serviço legado: $unit"
    systemctl disable --now "$unit" >/dev/null 2>&1 || true
  fi
done

listener_pids(){
  ss -ltnpH "sport = :${WS_PORT}" 2>/dev/null | sed -n 's/.*pid=\([0-9][0-9]*\).*/\1/p' | sort -u
}

stop_legacy_listener(){
  local pid="$1" cmd unit parent pm2name
  [[ -r "/proc/$pid/cmdline" ]] || return 0
  cmd="$(tr '\0' ' ' < "/proc/$pid/cmdline" 2>/dev/null || true)"

  if [[ "$cmd" != *"realtime-server/server.js"* ]]; then
    echo "ERRO: a porta $WS_PORT está ocupada por um processo que não reconheci como Nexa Realtime:"
    echo "PID=$pid CMD=$cmd"
    echo "Não vou encerrá-lo automaticamente."
    exit 1
  fi

  unit="$(sed -n 's#.*system.slice/\([^/]*\.service\).*#\1#p' "/proc/$pid/cgroup" 2>/dev/null | head -n1 || true)"
  if [[ -n "$unit" && "$unit" != "$SERVICE_NAME" ]]; then
    log "Parando instância antiga gerenciada por systemd: $unit (PID $pid)"
    systemctl disable --now "$unit" >/dev/null 2>&1 || systemctl stop "$unit" >/dev/null 2>&1 || true
    return 0
  fi

  # Caso a instância antiga esteja sob PM2, remove somente o app dono deste PID.
  if command -v pm2 >/dev/null 2>&1; then
    pm2name="$(pm2 jlist 2>/dev/null | node -e '
      let d=""; process.stdin.on("data",c=>d+=c); process.stdin.on("end",()=>{try{const a=JSON.parse(d); const p=Number(process.argv[1]); const x=a.find(v=>Number(v.pid)===p); if(x) process.stdout.write(String(x.name||""));}catch(e){}});' "$pid" || true)"
    if [[ -n "$pm2name" ]]; then
      log "Removendo instância antiga do PM2: $pm2name (PID $pid)"
      pm2 delete "$pm2name" >/dev/null 2>&1 || true
      return 0
    fi
  fi

  log "Encerrando instância antiga/manual do Nexa Realtime (PID $pid)"
  kill -TERM "$pid" 2>/dev/null || true
}

mapfile -t old_pids < <(listener_pids)
for pid in "${old_pids[@]:-}"; do
  [[ -n "$pid" ]] && stop_legacy_listener "$pid"
done

# Aguarda a porta ficar realmente livre. Se algum supervisor desconhecido recriar o processo, aborta com diagnóstico.
for _ in $(seq 1 30); do
  if [[ -z "$(listener_pids)" ]]; then break; fi
  sleep 0.2
done
if [[ -n "$(listener_pids)" ]]; then
  echo "ERRO: a porta $WS_PORT continua ocupada após a migração."
  ss -ltnp "sport = :${WS_PORT}" || true
  exit 1
fi

# Apache: preserva proxy existente se já aponta /brain-ws; caso contrário instala o padrão.
a2enmod proxy proxy_http proxy_wstunnel headers >/dev/null
if grep -RqsE 'ProxyPass[[:space:]]+"?/brain-ws|ProxyPass[[:space:]]+/brain-ws' /etc/apache2/sites-enabled /etc/apache2/conf-enabled 2>/dev/null; then
  log "Proxy /brain-ws já existe no Apache; configuração atual será preservada."
else
  cp "$APP_DIR/deploy/apache/nexa-realtime-websocket.conf" /etc/apache2/conf-available/nexa-realtime-websocket.conf
  command -v a2enconf >/dev/null && a2enconf nexa-realtime-websocket >/dev/null || true
fi
apache2ctl configtest
systemctl reload apache2

systemctl enable "$SERVICE_NAME" >/dev/null
systemctl restart "$SERVICE_NAME"
sleep 1

if ! systemctl is-active --quiet "$SERVICE_NAME"; then
  echo "ERRO: $SERVICE_NAME não permaneceu ativo."
  systemctl --no-pager --full status "$SERVICE_NAME" || true
  journalctl -u "$SERVICE_NAME" -n 80 --no-pager || true
  exit 1
fi

health="$(curl -fsS "http://127.0.0.1:${WS_PORT}/health" 2>/dev/null || true)"
if [[ -z "$health" || "$health" != *'"service":"nexa-realtime"'* || "$health" != *'"version":"2.0.0"'* ]]; then
  echo "ERRO: a porta respondeu, mas não confirmou a instância Nexa Realtime V2."
  echo "Health recebido: $health"
  ss -ltnp "sport = :${WS_PORT}" || true
  exit 1
fi

log "Serviço ativo: $(systemctl is-active "$SERVICE_NAME")"
log "Health OK: $health"
log "Porta $WS_PORT:"
ss -ltnp "sport = :${WS_PORT}" || true

cat <<'TXT'

Instalação concluída.

O Realtime V2 agora:
- valida se a própria unidade systemd ficou ativa (não aceita health de processo antigo);
- migra com segurança instâncias antigas do Brain/Realtime que estejam usando a porta;
- mantém 10 minutos de eventos recentes no gateway para avisar pedidos feitos pouco antes do Admin conectar;
- continua sem polling no MySQL.

Teste recomendado:
1. Abra o Admin e confirme o selo temporário "Tempo real conectado".
2. Mantenha o Admin aberto e faça um pedido pelo catálogo em outra aba/dispositivo.
3. O aviso deve chegar imediatamente.
TXT
