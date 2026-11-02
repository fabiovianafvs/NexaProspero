#!/usr/bin/env bash
set -euo pipefail

# Pacote oficial de Produção Restrita publicado em 27/07/2026.
URL="https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica/producao-restrita/esquemas-nfse-rtc-v1-01-20260727.zip"
DESTINO="${1:-/opt/nexa/nfse/xsd-prodrest-20260727}"
TMP="$(mktemp -d)"
trap 'rm -rf "$TMP"' EXIT

command -v curl >/dev/null || { echo "Instale curl: sudo apt-get install -y curl"; exit 1; }
command -v unzip >/dev/null || { echo "Instale unzip: sudo apt-get install -y unzip"; exit 1; }

sudo mkdir -p "$DESTINO"
curl -fL --retry 3 --connect-timeout 20 "$URL" -o "$TMP/nfse-xsd.zip"
sudo unzip -o "$TMP/nfse-xsd.zip" -d "$DESTINO" >/dev/null
sudo find "$DESTINO" -type f -name '*.xsd' -exec chmod 0644 {} +
sudo find "$DESTINO" -type d -exec chmod 0755 {} +

# Procura o XSD que declara o elemento raiz DPS.
XSD_RAIZ="$(grep -Ril 'name="DPS"' "$DESTINO" --include='*.xsd' | head -n 1 || true)"

if [[ -z "$XSD_RAIZ" ]]; then
  echo "Pacote instalado em: $DESTINO"
  echo "Não foi possível identificar automaticamente o XSD raiz da DPS."
  echo "Liste os arquivos com: find '$DESTINO' -type f -name '*.xsd'"
  exit 2
fi

echo
echo "XSD instalado com sucesso."
echo "XSD raiz da DPS: $XSD_RAIZ"
echo
echo "Para PHP-FPM, adicione à configuração do pool:"
echo "env[NFSE_DPS_XSD_PATH] = $XSD_RAIZ"
echo
echo "Depois reinicie o PHP-FPM e o servidor web. Exemplo:"
echo "sudo systemctl restart php8.3-fpm"
echo "sudo systemctl reload nginx   # ou: sudo systemctl reload apache2"
echo
echo "Após reiniciar o PHP-FPM/Apache, atualize a tela Fiscal - NFS-e Nacional."
echo "O aviso de XSD não configurado deve desaparecer."
