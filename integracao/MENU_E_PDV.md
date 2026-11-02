# Integração do módulo Ordem de Serviço

Este pacote contém o módulo completo em arquivos novos. Como `_chrome.php` e `gestao_pdv.php` não foram fornecidos junto com esta solicitação, eles **não foram sobrescritos** para evitar quebrar o Nexa em produção. Faça apenas os encaixes abaixo nos arquivos atuais da VPS.

## 1. Menu principal — antes de Vendas

No início de `nexa/admin/_chrome.php`, depois do `_bootstrap.php`, carregue:

```php
require_once __DIR__ . '/_os_menu_visibility.php';
```

No ponto onde o menu **Vendas** começa, coloque imediatamente antes, usando as mesmas classes HTML/ícones já utilizadas pelo Nexa:

```php
<?php if (!empty($osModuleEnabled)): ?>
    <!-- usar aqui o mesmo componente visual dos outros menus do Nexa -->
    <a href="os_monitor.php">Ordem de Serviço</a>
    <a href="os_monitor.php">Monitor de O.S.</a>
<?php endif; ?>
```

Se o menu atual usa grupo expansível, o primeiro texto é o grupo **Ordem de Serviço** e o segundo é o submenu **Monitor de O.S.**. O bloco inteiro deve ficar antes do grupo **Vendas**.

## 2. Configurações — mostrar apenas se O.S. estiver habilitada

Dentro do grupo **Configurações**, acrescente:

```php
<?php if (!empty($osModuleEnabled)): ?>
    <a href="os_configuracoes.php">Configurações da O.S.</a>
<?php endif; ?>
```

A própria página `os_configuracoes.php` também valida o módulo no servidor; acessar a URL diretamente com o módulo desligado retorna 403.

## 3. Fechamento da O.S. no PDV

No início de `nexa/admin/gestao_pdv.php`, depois do bootstrap:

```php
require_once __DIR__ . '/os_pdv_hook.php';
```

No ponto em que o PDV termina a venda com sucesso (depois de persistir estoque/financeiro/documento fiscal), chame:

```php
nexa_os_pdv_finalize($pdo, $companyId, $pedidoId);
```

Isso localiza pedidos com `source = 'ordem_servico'`, marca a O.S. como `FATURADA` e libera o valor de `produtos.estoque_reservado` referente à reserva da O.S.

Se o PDV tiver uma ação que cancela/descarta um pedido originado da O.S., chame:

```php
nexa_os_pdv_cancel($pdo, $companyId, $pedidoId);
```

Isso desfaz o vínculo com o pedido e libera a reserva para evitar estoque preso.

## 4. Documentos fiscais

`os_faturar.php` grava na O.S. a intenção fiscal:

- produtos: `NFCE` ou `NFE`, escolhida antes de abrir o PDV;
- serviços: `NFSE`;
- O.S. mista: marca as duas necessidades.

O PDV deve usar `os_orders.product_document_type`, `os_orders.service_document_type`, `requires_product_fiscal` e `requires_service_fiscal` para decidir o fluxo de emissão. O módulo não duplica o motor fiscal existente.

## 5. F8 nos botões Buscar

`os_editar.php` já implementa o padrão:

- modal abre com busca vazia;
- digitar + Enter pesquisa;
- setas para cima/baixo percorrem os resultados;
- Enter seleciona;
- depois de digitar novamente, Enter executa nova pesquisa;
- botões **Filtrar/Pesquisar** do Monitor não recebem F8.
