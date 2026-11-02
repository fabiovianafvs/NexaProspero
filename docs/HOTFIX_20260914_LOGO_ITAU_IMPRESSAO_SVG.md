# Hotfix 2026-09-14 - Logo Itaú na impressão HTML (SVG inline)

## Motivo
A tentativa anterior ainda utilizava um elemento `<img>`. Mesmo com Base64, o navegador/servidor pode bloquear a origem `data:` ou falhar no carregamento da imagem.

## Correção
- Arquivo alterado: `admin/financeiro_boleto_imprimir.php`
- Remove o `<img>` da marca Itaú na impressão HTML.
- Desenha a marca com SVG inline, sem arquivo externo e sem Base64.
- Não altera PDF, WhatsApp, integração Itaú, QR Code, código de barras ou dados do boleto.

## Instalação
Copiar a pasta `nexa` sobre `/var/www/nexa` preservando a estrutura e recarregar o boleto com Ctrl+F5.
