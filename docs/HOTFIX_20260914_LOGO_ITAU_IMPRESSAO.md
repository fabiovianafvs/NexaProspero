# Hotfix 2026-09-14 - Logo Itaú na impressão HTML

Corrige a visualização/impressão do boleto Itaú quando a imagem da logo não é carregada pelo navegador.

## Alteração
- `admin/financeiro_boleto_imprimir.php`
- A logo Itaú passa a ser incorporada no HTML como `data:image/png;base64` quando o arquivo local existe.
- Mantém `/assets/img/itau-logo.png` como fallback.
- Não altera geração do PDF, WhatsApp, linha digitável, código de barras, QR Code ou regras financeiras.

## Instalação
Copiar a pasta `nexa` sobre `/var/www/nexa` preservando a estrutura.
