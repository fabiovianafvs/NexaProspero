# Hotfix Nexa - Checkout do catálogo passo a passo

Data: 14/09/2026

## Arquivo alterado

- `/var/www/nexa/checkout.php`

## Alterações

- Checkout convertido para fluxo passo a passo sem alterar a gravação do pedido.
- Passo 1: Identificação.
- Passo 2: Entrega.
- Passo 3: Previsão/agendamento, quando o recurso estiver habilitado na empresa.
- Passo 4: Pagamento e observações.
- Quando o agendamento estiver desabilitado, o checkout usa 3 passos e Pagamento passa a ser o passo 3.
- Navegação flutuante/sticky com Voltar e Avançar; no último passo o botão passa a Confirmar pedido.
- Validação por etapa antes de avançar.
- Campos de previsão/agendamento removidos do passo Entrega e isolados no passo próprio.
- Bordas dos campos e cartões reforçadas para melhorar a leitura em fundo branco.
- Layout responsivo para celular.
- Mantida a correção anterior do envio automático de WhatsApp no checkout (`/admin/sessions` com fallback `/status`).

## Banco de dados

Nenhuma alteração de banco e nenhum script SQL necessário.

## Implantação

Copiar a pasta `nexa` deste pacote sobre `/var/www/nexa` e substituir o arquivo existente.

Recomendado testar:

1. Cliente já cadastrado e novo cliente.
2. Entrega e retirada.
3. Agendamento obrigatório e opcional.
4. Validação de horário mínimo/horário de funcionamento.
5. Todas as formas de pagamento.
6. Envio automático do WhatsApp após a confirmação.
7. Navegação no celular e no desktop.
