<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/order_message.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/internal_delivery_status.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
if ($printCross && function_exists('multi_company_context_permission_allowed')) {
  $canPrintProduction = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canPrintProduction) { http_response_code(403); echo 'Sem permissão para imprimir produção na empresa selecionada pelo F11.'; exit; }
}
$id = (int)($_GET['id'] ?? 0);
$ordersBackUrl = function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php') : 'pedidos.php';
if ($id <= 0) {
  header('Location: ' . $ordersBackUrl);
  exit;
}
if (!$printCross) { try { internal_delivery_status_ensure_schema($pdo); } catch (Throwable $e) {} }
$data = order_message_load($pdo, $companyId, $id);
if (!$data) {
  http_response_code(404);
  echo 'Pedido não encontrado.';
  exit;
}
$pedido = $data['pedido'];
$internalDeliveryLabel = '';
$internalDeliveryStatusId = (int)($pedido['internal_delivery_status_id'] ?? 0);
if ($internalDeliveryStatusId > 0) {
  if ($printCross) {
    try {
      if (!function_exists('multi_company_table_exists') || multi_company_table_exists($pdo, 'gestao_internal_delivery_statuses')) {
        $stDelivery = $pdo->prepare('SELECT name FROM gestao_internal_delivery_statuses WHERE company_id=? AND id=? LIMIT 1');
        $stDelivery->execute([$companyId, $internalDeliveryStatusId]);
        $internalDeliveryLabel = trim((string)($stDelivery->fetchColumn() ?: ''));
      }
    } catch (Throwable $e) {}
  } else {
    $internalDeliveryLabel = internal_delivery_status_label($pdo, $companyId, $internalDeliveryStatusId);
  }
}
$itens = $data['itens'];
$pedidoNumero = function_exists('company_sequence_display') ? company_sequence_display($pedido, 'pedidos') : (int)($pedido['id'] ?? $id);
$fiscalPrintCfg = [];
if ($printCross) {
  try {
    if (!function_exists('multi_company_table_exists') || multi_company_table_exists($pdo, 'fiscal_company_config')) {
      $stPrintCfg = $pdo->prepare('SELECT print_pack_58mm_enabled FROM fiscal_company_config WHERE company_id=? LIMIT 1');
      $stPrintCfg->execute([$companyId]);
      $fiscalPrintCfg = $stPrintCfg->fetch(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {}
} else {
  $fiscalPrintCfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
}
$printPack58mm = !empty($fiscalPrintCfg['print_pack_58mm_enabled']);
$itemObsCol = order_message_detect_item_obs_col($itens);
$pedidoObsCol = null;
foreach (['observacoes','observacao','obs'] as $c) { if (array_key_exists($c, $pedido)) { $pedidoObsCol = $c; break; } }
[$obsPedido, $fallbackMapBase, $fallbackQueueBase] = order_message_split_order_notes((string)($pedidoObsCol ? ($pedido[$pedidoObsCol] ?? '') : ''));

$gruposProducao = [];
foreach ($itens as $i) {
  $grupo = trim((string)($i['produto_grupo_producao'] ?? ''));
  $cat = trim((string)($i['produto_categoria'] ?? ''));
  if ($grupo === '') $grupo = $cat !== '' ? $cat : 'Sem grupo de produção';
  $gruposProducao[$grupo] = $grupo;
}
ksort($gruposProducao, SORT_NATURAL | SORT_FLAG_CASE);

$selected = $_GET['grupo'] ?? ($_GET['cat'] ?? []);
if (is_string($selected)) $selected = [$selected];
$selected = array_values(array_intersect(array_map('strval', (array)$selected), array_keys($gruposProducao)));
if (!$selected) $selected = array_keys($gruposProducao);
$selectedMap = array_fill_keys($selected, true);
$otherProductionGroups = [];
foreach (array_keys($gruposProducao) as $grupoAtual) {
  $outros = array_values(array_filter(array_keys($gruposProducao), static fn($g) => (string)$g !== (string)$grupoAtual));
  $otherProductionGroups[$grupoAtual] = $outros;
}

$groups = [];
$fallbackMap = $fallbackMapBase;
$fallbackQueue = $fallbackQueueBase;
foreach ($itens as $i) {
  $cat = trim((string)($i['produto_categoria'] ?? ''));
  $grupo = trim((string)($i['produto_grupo_producao'] ?? ''));
  if ($grupo === '') $grupo = $cat !== '' ? $cat : 'Sem grupo de produção';
  $nome = trim((string)($i['nome'] ?? '')) ?: ('#' . (int)($i['produto_id'] ?? 0));
  $obsItem = '';
  if ($itemObsCol) $obsItem = order_message_normalize_item_text((string)($i[$itemObsCol] ?? ''));
  if ($obsItem === '') $obsItem = order_message_dequeue_note($fallbackMap, $fallbackQueue, $nome);
  $i['_nome_print'] = $nome;
  $i['_cat_print'] = $cat;
  $i['_grupo_print'] = $grupo;
  $i['_obs_print'] = order_message_normalize_item_text($obsItem);
  if (isset($selectedMap[$grupo])) $groups[$grupo][] = $i;
}
[$bairro, $endereco, $referencia, $cidade, $uf, $cep] = order_message_split_address($pedido);
$scheduled = order_message_format_scheduled($pedido);
$pedidoCriado = '';
if (!empty($pedido['created_at'])) {
  $tsPedidoCriado = strtotime((string)$pedido['created_at']);
  if ($tsPedidoCriado) $pedidoCriado = date('d/m/Y H:i', $tsPedidoCriado);
}
$clienteWhatsapp = trim((string)($pedido['whatsapp'] ?? ''));
$totalPedidoPrint = order_message_money((float)($pedido['total'] ?? 0));
$formaPagamentoPrint = order_message_label_pgto((string)($pedido['forma_pagamento'] ?? ''));
if ($formaPagamentoPrint === '') $formaPagamentoPrint = 'Não informado';
function prod_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function prod_adicional_qty_from_text(string $text): float {
  $total = 0.0;
  $text = html_entity_decode(strip_tags(str_replace(['<br>', '<br/>', '<br />'], "\n", $text)), ENT_QUOTES | ENT_HTML5, 'UTF-8');
  $text = str_replace(["\r\n", "\r"], "\n", trim($text));
  if ($text === '') return 0.0;

  // Primeiro procura quantidades explícitas em qualquer posição do texto.
  // Ex.: "25 unidades", "50 unidade", "Qtd: 25 un".
  if (preg_match_all('/(?<![\d\/])0*([0-9]{1,4})(?:[,.]([0-9]{1,2}))?\s*(?:unidades?|unds?|und\.?|unid\.?|un\.?)\b/iu', $text, $matches, PREG_SET_ORDER)) {
    foreach ($matches as $m) {
      $value = (string)$m[1] . (isset($m[2]) && $m[2] !== '' ? '.' . (string)$m[2] : '');
      $qty = (float)$value;
      if ($qty > 0) $total += $qty;
    }
    return $total;
  }

  // Fallback: alguns adicionais são salvos começando com a quantidade.
  // Ex.: "02 Bacon", "2x Queijo", "10 - Embalagem".
  $parts = preg_split('/[\n,;|]+/u', $text) ?: [];
  foreach ($parts as $part) {
    $part = trim((string)$part);
    if ($part === '') continue;

    if (preg_match('/^\s*0*([0-9]{1,4})(?:[,.][0-9]+)?\s*(?:x\b|qtd\b|[.)\-–—:]\s*|\s+).+/iu', $part, $m)) {
      $qty = (float)str_replace(',', '.', (string)$m[1]);
      if ($qty > 0) $total += $qty;
      continue;
    }

    // Caso solicitado: os dois primeiros dígitos representam a quantidade.
    if (preg_match('/^\s*0*([0-9]{2})(?=\D).+/u', $part, $m)) {
      $qty = (int)$m[1];
      if ($qty > 0) $total += $qty;
    }
  }
  return $total;
}


?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Produção Pedido #<?= prod_h($pedidoNumero) ?></title>
<style>
  :root{--prod-paper:<?= $printPack58mm ? '58mm' : '68mm' ?>;}
  body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f5f7fb;color:#111827;padding:18px;}
  .wrap{max-width:980px;margin:0 auto;display:grid;gap:14px;}
  .toolbar,.sheet{background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:14px;box-shadow:0 12px 28px rgba(15,23,42,.06);}
  .toolbar h1{margin:0 0 8px;font-size:22px;color:#0B1F5B;}
  .checks{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0;}
  .checks label{display:flex;gap:6px;align-items:center;padding:8px 10px;border:1px solid #dbe4f0;border-radius:999px;background:#f8fbff;font-weight:700;}
  .btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:12px;padding:10px 14px;font-weight:800;cursor:pointer;}
  .btn-primary{background:#0B1F5B;color:#fff;border-color:#0B1F5B;}
  .actions{display:flex;gap:8px;flex-wrap:wrap;}
  .sheet{page-break-after:always;}
  .sheet:last-child{page-break-after:auto;}
  .head{display:flex;justify-content:space-between;gap:12px;border-bottom:2px solid #111827;padding-bottom:8px;margin-bottom:10px;}
  .head h2{margin:0;font-size:26px;}
  .muted{color:#64748b;font-size:13px;}
  .meta{font-size:14px;line-height:1.45;}
  .head .meta{font-size:17px;line-height:1.28;font-weight:900;color:#000;}
  .head .meta strong{font-size:inherit;font-weight:900;color:#000;}
  table{width:100%;border-collapse:collapse;margin-top:10px;}
  th,td{border-bottom:1px solid #e5e7eb;padding:8px;text-align:left;vertical-align:top;}
  th{font-size:12px;text-transform:uppercase;color:#475569;background:#f8fafc;}
  .qtd{width:80px;text-align:center;font-size:18px;font-weight:900;}
  .item-name{font-size:17px;font-weight:900;}
  .item-cat{margin-top:2px;color:#111827;font-size:12px;font-weight:700;}
  .obs{margin-top:7px;white-space:pre-line;color:#000;font-size:15px;font-weight:900;line-height:1.35;padding:6px 8px;border-left:4px solid #111827;background:#f1f5f9;}
  .obs:before{content:'OBS.: ';font-weight:900;}
  .order-obs{font-weight:900;color:#000;}
  .group-alert{margin:8px 0 10px;padding:8px 10px;border:2px solid #111827;border-radius:10px;background:#fff7ed;color:#000;font-size:15px;font-weight:900;text-transform:uppercase;}
  .group-total{margin-top:5px;font-size:15px;font-weight:950;color:#000;}
  .internal-delivery{margin-top:5px;display:block;background:#000;color:#fff;padding:5px 7px;font-size:14px;font-weight:950;text-transform:uppercase;line-height:1.18;}
  .group-subtotal{margin-top:2px;font-size:12px;font-weight:800;color:#334155;}
  .production-calc{margin-top:4px;font-size:13px;font-weight:900;color:#0f172a;}
  @media print{
    @page{size:<?= $printPack58mm ? '58mm' : '68mm' ?> auto;margin:<?= $printPack58mm ? '1mm' : '2mm' ?>;}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    body{background:#fff;padding:0;font-size:12px;color:#000;width:var(--prod-paper);margin:0 auto;}
    .toolbar{display:none!important;}
    .wrap{max-width:none;margin:0;display:block;}
    .sheet{border:0;box-shadow:none;border-radius:0;padding:0;width:100%;page-break-after:always;}
    .head{border-bottom:2px solid #000;margin-bottom:6px;padding-bottom:5px;}
    .head h2{font-size:<?= $printPack58mm ? '15pt' : '18px' ?>;line-height:1.1;}
    .meta{font-size:11px;line-height:1.25;}
    .head .meta{font-size:<?= $printPack58mm ? '10pt' : '14px' ?>;line-height:1.18;font-weight:900;color:#000;}
    .head .meta strong{font-size:inherit;font-weight:900;color:#000;}
    table{margin-top:6px;}
    th,td{padding:4px 0;border-bottom:1px dashed #000;}
    th{font-size:10px;background:#fff;color:#000;}
    .qtd{width:<?= $printPack58mm ? '12mm' : '34px' ?>;font-size:<?= $printPack58mm ? '12pt' : '15px' ?>;}
    .item-name{font-size:<?= $printPack58mm ? '11pt' : '14px' ?>;line-height:1.15;}
    .item-cat{font-size:10px;color:#000;font-weight:700;}
    .obs{background:#fff;color:#000;font-size:<?= $printPack58mm ? '9.5pt' : '13px' ?>;font-weight:900;line-height:1.25;margin-top:4px;padding:3px 0 3px 5px;border-left:3px solid #000;}
    .order-obs{font-weight:900;color:#000;}
    .group-alert{font-size:12px;border:1px solid #000;border-radius:0;background:#fff;padding:4px 0;margin:5px 0 6px;}
    .group-total{font-size:12pt;margin-top:2px;}
    .internal-delivery{background:#000!important;color:#fff!important;padding:2mm 1.5mm;margin-top:1.5mm;font-size:<?= $printPack58mm ? '10pt' : '14px' ?>;font-weight:950;line-height:1.1;}
    .group-subtotal{font-size:9pt;color:#000;margin-top:1px;}
    .production-calc{font-size:9.5pt;color:#000;margin-top:2px;font-weight:900;}
  }
</style>
</head>
<body>
<div class="wrap">
  <form class="toolbar" method="get">
    <?php if ($printCross && function_exists('multi_company_context_request_token')): $mcPrintCtx = multi_company_context_request_token(); if ($mcPrintCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= prod_h($mcPrintCtx) ?>"><?php endif; endif; ?>
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <h1>Impressão de produção — Pedido #<?= prod_h($pedidoNumero) ?></h1>
    <div class="muted">Os itens já saem separados pelo <strong>Grupo de produção</strong> definido no cadastro do produto. Produtos sem grupo usam a categoria como fallback. Perfil: <strong><?= $printPack58mm ? 'Pack 58mm' : 'padrão' ?></strong>.</div>
    <div class="checks">
      <?php foreach ($gruposProducao as $grupo): ?>
        <label><input type="checkbox" name="grupo[]" value="<?= prod_h($grupo) ?>" <?= isset($selectedMap[$grupo]) ? 'checked' : '' ?>> <?= prod_h($grupo) ?></label>
      <?php endforeach; ?>
    </div>
    <div class="actions">
      <button class="btn btn-primary" type="button" onclick="window.print()">Imprimir agora</button>
      <button class="btn" type="submit">Filtrar grupos</button>
      <a class="btn" href="<?= prod_h($ordersBackUrl) ?>">Voltar para pedidos</a>
    </div>
  </form>

  <?php if (!$groups): ?>
    <div class="sheet">Nenhum item encontrado para os grupos selecionados.</div>
  <?php endif; ?>

  <?php foreach ($groups as $cat => $rows): ?>
    <?php
      $totalQtdGrupo = array_reduce($rows, static fn($carry, $row) => $carry + (float)($row['qtd'] ?? 0), 0.0);
      $totalAdicionaisGrupo = 0.0;
      $totalProducaoGrupo = 0.0;
      $temQuantidadeConfiguradaGrupo = false;
      foreach ($rows as $rowGrupo) {
        $qtdPedidoItem = max(0.0, (float)($rowGrupo['qtd'] ?? 0));
        $qtdConfiguradaUnitaria = prod_adicional_qty_from_text((string)($rowGrupo['_obs_print'] ?? ''));
        if ($qtdConfiguradaUnitaria > 0) {
          // A quantidade definida em "Itens desse grupo" é por unidade do produto pai.
          // Ex.: pedido com Qtd 2 e opção "50 unidades" = 100 unidades para produção.
          $qtdProducaoItem = $qtdConfiguradaUnitaria * $qtdPedidoItem;
          $totalAdicionaisGrupo += $qtdProducaoItem;
          $totalProducaoGrupo += $qtdProducaoItem;
          $temQuantidadeConfiguradaGrupo = true;
        } else {
          // Itens sem uma quantidade interna configurada continuam contando pela própria quantidade pedida.
          $totalProducaoGrupo += $qtdPedidoItem;
        }
      }
    ?>
    <section class="sheet">
      <div class="head">
        <div>
          <h2><?= prod_h($cat) ?></h2>
          <div class="muted">Grupo de produção</div>
          <div class="group-total">Total do grupo: <?= prod_h(order_message_qty($totalProducaoGrupo)) ?> un</div>
          <?php if ($internalDeliveryLabel !== ''): ?><div class="internal-delivery"><?= prod_h($internalDeliveryLabel) ?></div><?php endif; ?>
          <?php if ($temQuantidadeConfiguradaGrupo): ?><div class="group-subtotal">Itens do pedido: <?= prod_h(order_message_qty($totalQtdGrupo)) ?> un • quantidades internas multiplicadas pela quantidade pedida</div><?php endif; ?>
        </div>
        <div class="meta" style="text-align:right;">
          <strong>Pedido #<?= prod_h($pedidoNumero) ?></strong><br>
          <?= prod_h((string)($pedido['nome_cliente'] ?? '')) ?><br>
          <?php if ($clienteWhatsapp !== ''): ?>WhatsApp: <?= prod_h($clienteWhatsapp) ?><br><?php endif; ?>
          <strong>Total: <?= prod_h($totalPedidoPrint) ?></strong><br>
          <strong>Pagamento: <?= prod_h($formaPagamentoPrint) ?></strong><br>
          <?= prod_h($scheduled !== '' ? $scheduled : $pedidoCriado) ?>
        </div>
      </div>
      <?php $outrosGrupos = $otherProductionGroups[$cat] ?? []; ?>
      <?php if ($outrosGrupos): ?>
        <div class="group-alert">Este pedido tem <?= prod_h(implode(' e ', array_map(static fn($g) => mb_strtoupper((string)$g, 'UTF-8'), $outrosGrupos))) ?>.</div>
      <?php endif; ?>
      <div class="meta">
        <?php if ($pedidoCriado !== ''): ?><strong>Pedido feito em:</strong> <?= prod_h($pedidoCriado) ?><br><?php endif; ?>
        <strong>Condição de pagamento:</strong> <?= prod_h($formaPagamentoPrint) ?><br>
        <?php if ($obsPedido !== ''): ?><span class="order-obs"><strong>Obs. pedido:</strong> <?= nl2br(prod_h($obsPedido)) ?></span><br><?php endif; ?>
        <?php if ($endereco !== '' || $bairro !== ''): ?><strong>Entrega:</strong> <?= prod_h(trim($endereco . ($bairro !== '' ? ' - ' . $bairro : ''))) ?><?php if ($referencia !== ''): ?> — Ref.: <?= prod_h($referencia) ?><?php endif; ?><br><?php endif; ?>
      </div>
      <table>
        <thead><tr><th>Qtd</th><th>Item</th></tr></thead>
        <tbody>
        <?php foreach ($rows as $i): ?>
          <tr>
            <td class="qtd"><?= prod_h(order_message_qty($i['qtd'] ?? 1)) ?></td>
            <td>
              <div class="item-name"><?= prod_h($i['_nome_print']) ?></div>
              <?php if (trim((string)($i['_cat_print'] ?? '')) !== '' && trim((string)($i['_cat_print'] ?? '')) !== trim((string)($i['_grupo_print'] ?? ''))): ?>
                <div class="item-cat">Categoria: <?= prod_h($i['_cat_print']) ?></div>
              <?php endif; ?>
              <?php if (trim((string)$i['_obs_print']) !== ''): ?><div class="obs"><?= nl2br(prod_h($i['_obs_print'])) ?></div><?php endif; ?>
              <?php
                $qtdPedidoLinha = max(0.0, (float)($i['qtd'] ?? 0));
                $qtdConfiguradaLinha = prod_adicional_qty_from_text((string)($i['_obs_print'] ?? ''));
                $qtdProducaoLinha = $qtdConfiguradaLinha > 0 ? ($qtdConfiguradaLinha * $qtdPedidoLinha) : 0.0;
              ?>
              <?php if ($qtdConfiguradaLinha > 0 && abs($qtdPedidoLinha - 1.0) > 0.000001): ?>
                <div class="production-calc">Produção: <?= prod_h(order_message_qty($qtdConfiguradaLinha)) ?> un × <?= prod_h(order_message_qty($qtdPedidoLinha)) ?> = <strong><?= prod_h(order_message_qty($qtdProducaoLinha)) ?> un</strong></div>
              <?php endif; ?>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </section>
  <?php endforeach; ?>
</div>
<script>
  if (new URLSearchParams(location.search).get('auto') === '1') window.print();
</script>
</body>
</html>
