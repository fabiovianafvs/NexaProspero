#!/bin/bash
# Nexa Próspero - limite de tamanho dos arquivos enviados pelo painel.
#
# O PHP do Ubuntu vem com limite de 2 MB por arquivo (upload_max_filesize) e 8 MB
# por envio (post_max_size). Arquivos maiores (ex.: .zip/.rar com os XMLs das notas
# no Atendimento do WhatsApp) eram recusados. Este script SÓ AUMENTA: se o servidor
# já tiver um limite maior, ele é mantido.
#
# Valores: 20 MB por arquivo e 24 MB por envio (o WhatsApp do Nexa aceita até 16 MB).
# Uso (como root): bash /var/www/nexa/deploy/desempenho/limite_arquivos.sh
set -euo pipefail
PHPV="$(ls /etc/php | sort -V | tail -1)"
FPM_SVC="php$PHPV-fpm"
CONF="/etc/php/$PHPV/fpm/conf.d/99-nexa-limite-arquivos.ini"
[ -d "/etc/php/$PHPV/fpm/conf.d" ] || { echo "ERRO: PHP-FPM $PHPV não encontrado."; exit 1; }

to_mb() { # 2M -> 2, 1G -> 1024, 8388608 -> 8
  local v="${1^^}"; local n="${v//[!0-9]/}"; [ -z "$n" ] && n=0
  case "$v" in *G) echo $(( n * 1024 ));; *M) echo "$n";; *K) echo $(( n / 1024 ));; *) echo $(( n / 1048576 ));; esac
}
atual() { "php-fpm$PHPV" -i 2>/dev/null | awk -F'=>' -v k="$1" '$1 ~ "^"k" " {gsub(/ /,"",$2); print $2; exit}'; }

UP_ATUAL="$(atual upload_max_filesize)"; POST_ATUAL="$(atual post_max_size)"
UP_MB="$(to_mb "${UP_ATUAL:-2M}")"; POST_MB="$(to_mb "${POST_ATUAL:-8M}")"
echo "Hoje: arquivo até ${UP_ATUAL:-?} e envio até ${POST_ATUAL:-?}."
NOVO_UP=$(( UP_MB > 20 ? UP_MB : 20 )); NOVO_POST=$(( POST_MB > 24 ? POST_MB : 24 ))
[ "$NOVO_POST" -le "$NOVO_UP" ] && NOVO_POST=$(( NOVO_UP + 4 ))
if [ "$NOVO_UP" -eq "$UP_MB" ] && [ "$NOVO_POST" -eq "$POST_MB" ]; then
  echo "Nada a fazer: os limites já são suficientes."
  exit 0
fi
cat > "$CONF" <<EOF
; Nexa Próspero - limite dos arquivos enviados pelo painel (deploy/desempenho/limite_arquivos.sh)
upload_max_filesize = ${NOVO_UP}M
post_max_size = ${NOVO_POST}M
EOF
"php-fpm$PHPV" -t >/dev/null 2>&1 || { rm -f "$CONF"; echo "ERRO: configuração inválida, nada foi alterado."; exit 1; }
systemctl reload "$FPM_SVC"
echo "Pronto: arquivo até ${NOVO_UP} MB e envio até ${NOVO_POST} MB (PHP recarregado sem tirar o sistema do ar)."
