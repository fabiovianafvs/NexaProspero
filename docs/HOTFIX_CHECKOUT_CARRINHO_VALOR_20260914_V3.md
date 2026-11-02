# Hotfix Checkout/Catálogo V3 — valor no carrinho

Data: 14/09/2026

## Objetivo
Exibir o valor atual do carrinho também nas primeiras telas do catálogo, mantendo o contador de itens.

## Alteração
- O carrinho flutuante passa a mostrar `Carrinho` + `R$ valor` também no catálogo e na tela do produto.
- No celular, o carrinho deixa de ficar apenas como uma bolinha/ícone e mantém o valor visível.
- O botão de carrinho do cabeçalho também exibe o valor atual.
- Produtos simples, pizza meio a meio e produtos configurados com adicionais entram no cálculo.
- No checkout, permanece o cálculo já existente, inclusive com taxa de entrega quando aplicável.

## Arquivo alterado
- `nexa/_layout_top.php`

## Banco de dados
Nenhuma alteração. Não executar SQL.
