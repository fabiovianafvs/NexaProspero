# Hotfix — WhatsApp automático no Checkout do Catálogo

Data: 14/09/2026

## Problema

Ao finalizar um pedido pelo catálogo, o pedido era gravado normalmente, porém a mensagem automática não era enviada ao cliente. O envio manual em **Pedidos > WhatsApp** funcionava.

O `checkout.php` fazia uma checagem rápida da conexão pela rota `/sessions`. Essa rota não existe nas versões do serviço WhatsApp presentes no projeto, gerando HTTP 404. Como consequência, o checkout interpretava a sessão como indisponível e ignorava o envio automático.

O próprio log do checkout registrava sequências como:

- `WhatsApp status rápido: HTTP 404`
- `WhatsApp auto ignorado: sessão desconectada/indisponível.`

## Correção

A função de verificação rápida passou a:

1. Consultar `/admin/sessions` nas versões atuais do serviço, sem inicializar nova sessão do navegador.
2. Se essa rota não existir, usar `/status?company_id=...`, a mesma compatibilidade já utilizada em `Admin > WhatsApp`.
3. Reconhecer os estados `connected` e `ready`.
4. Manter timeout curto na checagem para não travar a finalização do pedido quando o serviço estiver realmente indisponível.

## Arquivo alterado

- `/var/www/nexa/checkout.php`

## Banco de dados

Nenhuma alteração de banco é necessária.

## Validação

- `php -l checkout.php`: sem erros de sintaxe.

## Teste sugerido

1. Confirmar em **Admin > WhatsApp** que a conexão está como **Conectado**.
2. Confirmar que **Ativar automação WhatsApp** e **Enviar pedido completo automaticamente para o cliente** estão marcados.
3. Fazer um pedido pelo catálogo usando um número de WhatsApp de teste.
4. Finalizar o checkout.
5. Confirmar que a mensagem chega automaticamente, sem precisar abrir a lista de pedidos e clicar em WhatsApp.
