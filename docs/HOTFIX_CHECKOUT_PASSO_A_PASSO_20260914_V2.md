# Hotfix Checkout Passo a Passo V2 - 14/09/2026

## Objetivo
Melhorar a experiência do checkout do catálogo sem alterar a gravação do pedido nem o fluxo do WhatsApp automático.

## Ajustes
- checkout em modo compacto: remove apenas no checkout o hero/cabeçalho institucional e a faixa de promoção do catálogo;
- mantém o carrinho flutuante e passa a exibir também o valor atualizado do pedido;
- valor do carrinho acompanha a taxa de entrega quando o bairro/tipo de entrega muda;
- campo Data de nascimento/Data de abertura reduzido para evitar extrapolar a tela;
- campos Data e Hora da etapa Previsão/Agendamento reduzidos;
- barra Voltar / Avançar / Confirmar pedido passa a ficar fixa/flutuante no rodapé durante a navegação;
- bordas dos campos ficaram ligeiramente mais fortes para melhorar contraste no fundo branco;
- fluxo passo a passo e envio automático por WhatsApp permanecem preservados.

## Arquivos alterados
- `nexa/checkout.php`
- `nexa/_layout_top.php`

`_layout_top.php` só ativa a apresentação compacta quando o `checkout.php` define `$checkoutCompactLayout = true`; catálogo, carrinho e demais páginas continuam com o layout atual.

## Banco de dados
Nenhuma alteração. Não executar SQL.

## Validação
- `php -l nexa/checkout.php`: OK
- `php -l nexa/_layout_top.php`: OK
- JavaScript principal do wizard validado com `node --check`: OK
