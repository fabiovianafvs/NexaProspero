#!/usr/bin/env bash
# =============================================================================
# Central do Contador Próspero - sincroniza os atalhos do domínio principal.
#
# O endereço nexaprospero.com.br/contadorprospero/ é servido pela pasta do site
# (/var/www/nexa-landing), que fica fora do Git. Lá cada tela da Central do Contador
# é um "atalho" de poucas linhas que chama o arquivo de mesmo nome em
# /var/www/nexa/contadorprospero/. Quando o sistema ganha uma tela nova (ex.:
# xml_download.php), o atalho precisa ser criado na pasta do site, senão o
# navegador mostra "Not Found".
#
# Uso (na VPS, como root), depois do git pull da produção:
#   bash /var/www/nexa/deploy/contador/sincronizar_contador_landing.sh
#
# Só CRIA os atalhos que faltam (copiando o modelo do index.php de lá).
# Não altera nem apaga os que já existem. Pode rodar quantas vezes quiser.
# =============================================================================
set -euo pipefail

ok()  { echo -e "\e[32m[OK]\e[0m $*"; }
die() { echo -e "\e[31m[ERRO]\e[0m $*" >&2; exit 1; }

[ "$(id -u)" -eq 0 ] || die "Execute como root."
APP="${NEXA_APP_DIR:-/var/www/nexa}/contadorprospero"
SITE="${NEXA_LANDING_DIR:-/var/www/nexa-landing}/contadorprospero"
[ -d "$APP" ] || die "Pasta do sistema não encontrada: $APP"
[ -d "$SITE" ] || die "Pasta do site não encontrada: $SITE"
MODELO="$SITE/index.php"
[ -f "$MODELO" ] || die "Modelo não encontrado: $MODELO"
grep -q "/contadorprospero/index.php" "$MODELO" || die "O $MODELO não está no formato de atalho esperado. Nada foi alterado."

criados=0
for origem in "$APP"/*.php; do
  nome="$(basename "$origem")"
  destino="$SITE/$nome"
  [ -e "$destino" ] && continue
  tmp="$(mktemp)"
  sed "s#/contadorprospero/index\.php#/contadorprospero/$nome#g" "$MODELO" > "$tmp"
  if ! grep -q "/contadorprospero/$nome" "$tmp"; then rm -f "$tmp"; die "Não foi possível montar o atalho de $nome."; fi
  if command -v php >/dev/null && ! php -l "$tmp" >/dev/null 2>&1; then rm -f "$tmp"; die "O atalho de $nome ficou com erro. Nada foi gravado."; fi
  install -m 644 -o root -g root "$tmp" "$destino"
  rm -f "$tmp"
  ok "Atalho criado: $destino"
  criados=$((criados + 1))
done
[ "$criados" -eq 0 ] && ok "Nenhum atalho faltando: a Central do Contador do domínio principal já está completa." || ok "$criados atalho(s) criado(s)."
