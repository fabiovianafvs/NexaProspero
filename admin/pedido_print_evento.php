<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/order_message.php';
require_once __DIR__ . '/_print_brand.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross && function_exists('multi_company_context_permission_allowed')) {
  $canEventPrint = multi_company_context_permission_allowed($pdo, 'gestao_pdv_mobile')
    || multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canEventPrint) { http_response_code(403); echo 'Sem permissão para esta impressão na empresa selecionada pelo F11.'; exit; }
}
$app = is_array($APP_CFG ?? null) ? $APP_CFG : [];
if ($printCross) {
  try {
    $baseApp = require __DIR__ . '/../config/app.php';
    $app = is_array($baseApp) ? $baseApp : [];
    if (!function_exists('multi_company_table_exists') || multi_company_table_exists($pdo, 'app_config')) {
      $stAppCfg = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
      $stAppCfg->execute([$companyId, 'app']);
      $appOver = json_decode((string)($stAppCfg->fetchColumn() ?: ''), true);
      if (is_array($appOver)) $app = function_exists('config_deep_merge') ? config_deep_merge($app, $appOver) : array_replace_recursive($app, $appOver);
    }
  } catch (Throwable $e) {}
}

function event_print_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function event_print_safe_return(string $value, string $fallback = 'gestao_pdv_mobile.php'): string {
  $value = trim($value);
  if ($value === '' || preg_match('#^(?:https?:)?//#i', $value) || str_contains($value, "\r") || str_contains($value, "\n")) return $fallback;
  $path = parse_url($value, PHP_URL_PATH);
  if (!is_string($path) || $path === '' || str_contains($path, '..')) return $fallback;
  return $value;
}

function event_print_unit_price(array $item): float {
  foreach (['preco_unit','preco_unitario','valor_unitario','unitario','preco','valor'] as $column) {
    if (array_key_exists($column, $item) && is_numeric($item[$column])) return (float)$item[$column];
  }
  $qty = (float)($item['qtd'] ?? 0);
  $total = (float)($item['total'] ?? 0);
  return $qty > 0 ? round($total / $qty, 4) : $total;
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('gestao_pdv_mobile.php') : 'gestao_pdv_mobile.php'));
  exit;
}

$data = order_message_load($pdo, $companyId, $id);
if (!$data) {
  http_response_code(404);
  echo 'Pedido não encontrado.';
  exit;
}

$pedido = (array)$data['pedido'];
$itens = (array)$data['itens'];
$pedidoNumero = function_exists('company_sequence_display') ? company_sequence_display($pedido, 'pedidos') : (int)($pedido['id'] ?? $id);
$returnTo = event_print_safe_return((string)($_GET['return_to'] ?? 'gestao_pdv_mobile.php'));
if ($printCross && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
$autoPrint = (string)($_GET['auto'] ?? '1') !== '0';
$biz = function_exists('load_business_config') ? load_business_config($companyId) : [];
$storeName = trim((string)($biz['brand']['catalog_title'] ?? $app['site_name'] ?? 'Nexa Próspero'));
if ($storeName === '') $storeName = 'Nexa Próspero';
$printLogoUrl = nexa_print_logo_url($companyId);

$customerName = trim((string)($pedido['nome_cliente'] ?? '')) ?: 'Consumidor final';
$customerPhone = trim((string)($pedido['whatsapp'] ?? ''));
$createdLabel = '';
if (!empty($pedido['created_at'])) {
  $ts = strtotime((string)$pedido['created_at']);
  if ($ts) $createdLabel = date('d/m/Y H:i', $ts);
}
$scheduledLabel = order_message_format_scheduled($pedido);
$typeLabel = order_message_label_entrega((string)($pedido['tipo_entrega'] ?? ''), $app ?? []);
$paymentLabel = order_message_label_pgto((string)($pedido['forma_pagamento'] ?? ''));
if ($paymentLabel === '') $paymentLabel = 'Não informado';

$orderObsColumn = null;
foreach (['observacoes','observacao','obs'] as $column) {
  if (array_key_exists($column, $pedido)) { $orderObsColumn = $column; break; }
}
$itemObsColumn = order_message_detect_item_obs_col($itens);
[$orderNotes, $fallbackNotes, $fallbackQueue] = order_message_split_order_notes((string)($orderObsColumn ? ($pedido[$orderObsColumn] ?? '') : ''));

$groups = [];
$customerItems = [];
foreach ($itens as $item) {
  $name = trim((string)($item['nome'] ?? '')) ?: ('#' . (int)($item['produto_id'] ?? 0));
  $category = trim((string)($item['produto_categoria'] ?? ''));
  $group = trim((string)($item['produto_grupo_producao'] ?? ''));
  if ($group === '') $group = $category !== '' ? $category : 'Produção';

  $itemNote = '';
  if ($itemObsColumn) $itemNote = order_message_normalize_item_text((string)($item[$itemObsColumn] ?? ''));
  if ($itemNote === '') $itemNote = order_message_dequeue_note($fallbackNotes, $fallbackQueue, $name);
  $item['_event_name'] = $name;
  $item['_event_group'] = $group;
  $item['_event_note'] = order_message_normalize_item_text($itemNote);
  $groups[$group][] = $item;
  $customerItems[] = $item;
}
ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);

$subtotal = (float)($pedido['total_produtos'] ?? 0);
if ($subtotal <= 0) {
  foreach ($customerItems as $item) $subtotal += (float)($item['total'] ?? 0);
}
$discount = (float)($pedido['discount_amount'] ?? $pedido['desconto'] ?? 0);
$addition = (float)($pedido['addition_amount'] ?? $pedido['acrescimo'] ?? 0);
$delivery = (float)($pedido['taxa_entrega'] ?? 0);
$total = (float)($pedido['total'] ?? max(0, $subtotal - $discount + $addition + $delivery));
[$district, $address, $reference, $city, $state, $zip] = order_message_split_address($pedido);
$errorMessage = trim((string)($_GET['err'] ?? ''));
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Venda para Evento — Pedido #<?= event_print_h($pedidoNumero) ?></title>
<style>
  :root{--paper:58mm;--ink:#000;--screen:#eef2f7}
  *{box-sizing:border-box}
  body{margin:0;padding:16px;background:var(--screen);color:var(--ink);font-family:Arial,Helvetica,sans-serif;font-weight:800}
  .toolbar,.notice{width:min(780px,100%);margin:0 auto 12px;background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:12px;box-shadow:0 12px 28px rgba(15,23,42,.07)}
  .toolbar{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}.toolbar strong{color:#0b1f5b}.toolbar-actions{display:flex;gap:8px;flex-wrap:wrap}.btn{border:1px solid #0f172a;border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;padding:9px 13px;font-weight:900;cursor:pointer}.btn-primary{background:#0b1f5b;color:#fff;border-color:#0b1f5b}.notice{background:#fff7ed;border-color:#fdba74;color:#9a3412}
  .print-wrap{width:58mm;margin:0 auto;background:#fff;box-shadow:0 12px 28px rgba(15,23,42,.10)}
  .ticket{width:58mm;padding:2.2mm 2mm;background:#fff;break-after:page;page-break-after:always;border-bottom:1px dashed #aaa}.ticket:last-child{break-after:auto;page-break-after:auto;border-bottom:0}
  .center{text-align:center}.brand{font-size:11pt;font-weight:950;text-transform:uppercase}.label{font-size:9pt;font-weight:950;letter-spacing:1.2px;text-transform:uppercase}.order-number{font-size:19pt;font-weight:950;line-height:1;margin:2mm 0}.customer-ticket .center{position:relative}.customer-ticket .center.has-logo{padding-left:14mm;padding-right:14mm}.customer-logo{position:absolute;left:0;top:0;width:12mm;height:9mm;object-fit:contain}.customer-ticket .order-number{font-size:25pt;border:2px solid #000;padding:2mm 1mm;margin:2mm 0 2.5mm}.group-name{font-size:15pt;font-weight:950;line-height:1.08;text-transform:uppercase;margin-top:1mm}.meta{font-size:8.6pt;line-height:1.25}.meta strong{font-weight:950}.divider{border-top:2px solid #000;margin:2mm 0}.divider.dashed{border-top:1px dashed #000}.contact{border:1.5px solid #000;padding:1.5mm;margin:1.5mm 0;font-size:9pt;line-height:1.25}.contact .client{font-size:11pt;font-weight:950;text-transform:uppercase}.production-item{display:grid;grid-template-columns:12mm minmax(0,1fr);gap:1.5mm;padding:2.2mm 0;border-bottom:1px dashed #000}.production-item:last-child{border-bottom:0}.qty{display:flex;align-items:center;justify-content:center;border:1.5px solid #000;font-size:14pt;font-weight:950;min-height:11mm}.item-name{font-size:11pt;font-weight:950;line-height:1.12;text-transform:uppercase;overflow-wrap:anywhere}.item-note{font-size:8.5pt;line-height:1.18;font-weight:950;margin-top:1mm;padding-left:1.5mm;border-left:3px solid #000;white-space:pre-wrap;overflow-wrap:anywhere}.order-info{font-size:8.4pt;line-height:1.3}.order-info-row{display:flex;justify-content:space-between;gap:2mm;margin:.8mm 0}.order-info-row span{font-size:7pt;text-transform:uppercase}.order-info-row strong{text-align:right}.order-info-block{margin:1mm 0}.order-info-block span{display:block;font-size:7pt;text-transform:uppercase}.order-info-block strong{display:block;margin-top:.5mm;white-space:pre-wrap}.customer-item{padding:1.8mm 0;border-bottom:1px dashed #000}.customer-item:last-child{border-bottom:0}.customer-item .item-name{font-size:9.2pt}.item-values{display:grid;grid-template-columns:auto 1fr auto;gap:1mm;margin-top:1mm;font-size:7.2pt}.item-values span:nth-child(2){text-align:center}.item-values strong{text-align:right}.totals{border-top:2px solid #000;margin-top:2mm;padding-top:1.5mm}.total-row{display:flex;justify-content:space-between;font-size:9pt;margin:.8mm 0}.total-row.final{font-size:15pt;font-weight:950;border-top:1px solid #000;padding-top:1.5mm;margin-top:1.5mm}.footer{text-align:center;font-size:7.3pt;border-top:1px dashed #000;margin-top:2mm;padding-top:1.5mm}
  @media print{
    @page{size:58mm auto;margin:1mm}
    *{-webkit-print-color-adjust:exact;print-color-adjust:exact}
    body{width:58mm;margin:0;padding:0;background:#fff}
    .toolbar,.notice{display:none!important}
    .print-wrap{width:58mm;margin:0;box-shadow:none}
    .ticket{width:58mm;padding:1.2mm 1.4mm;border-bottom:0}
  }
</style>
</head>
<body>
<?php if ($errorMessage !== ''): ?><div class="notice"><?= event_print_h($errorMessage) ?></div><?php endif; ?>
<div class="toolbar">
  <div><strong>Venda para Evento — Pedido #<?= event_print_h($pedidoNumero) ?></strong><div style="font-size:12px;color:#64748b;margin-top:3px">Produção primeiro e comprovante do cliente por último · 58 mm</div></div>
  <div class="toolbar-actions"><button class="btn btn-primary" type="button" onclick="window.print()">Imprimir</button><a class="btn" href="<?= event_print_h($returnTo) ?>">Voltar</a></div>
</div>

<div class="print-wrap">
  <?php foreach ($groups as $groupName => $rows): ?>
    <section class="ticket production-ticket">
      <div class="center">
        <div class="label">Produção</div>
        <div class="group-name"><?= event_print_h($groupName) ?></div>
        <div class="order-number">#<?= event_print_h($pedidoNumero) ?></div>
      </div>
      <div class="contact">
        <div class="client"><?= event_print_h($customerName) ?></div>
        <?php if ($customerPhone !== ''): ?><div>Telefone: <?= event_print_h($customerPhone) ?></div><?php endif; ?>
        <?php if ($createdLabel !== ''): ?><div>Pedido: <?= event_print_h($createdLabel) ?></div><?php endif; ?>
        <?php if ($scheduledLabel !== ''): ?><div>Previsão: <?= event_print_h($scheduledLabel) ?></div><?php endif; ?>
      </div>
      <?php foreach ($rows as $item): ?>
        <div class="production-item">
          <div class="qty"><?= event_print_h(order_message_qty($item['qtd'] ?? 1)) ?></div>
          <div>
            <div class="item-name"><?= event_print_h($item['_event_name']) ?></div>
            <?php if (trim((string)$item['_event_note']) !== ''): ?><div class="item-note"><?= nl2br(event_print_h($item['_event_note'])) ?></div><?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      <?php if ($orderNotes !== ''): ?><div class="divider dashed"></div><div class="meta"><strong>Obs. pedido:</strong> <?= nl2br(event_print_h($orderNotes)) ?></div><?php endif; ?>
    </section>
  <?php endforeach; ?>

  <section class="ticket customer-ticket">
    <div class="center<?= $printLogoUrl !== '' ? ' has-logo' : '' ?>">
      <?php if ($printLogoUrl !== ''): ?><img class="customer-logo" src="<?= event_print_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
      <div class="brand"><?= event_print_h($storeName) ?></div>
      <div class="label" style="margin-top:1mm">Pedido do cliente</div>
      <div class="order-number">#<?= event_print_h($pedidoNumero) ?></div>
      <?php if ($createdLabel !== ''): ?><div class="meta"><?= event_print_h($createdLabel) ?></div><?php endif; ?>
    </div>

    <div class="divider"></div>
    <div class="order-info">
      <div class="order-info-row"><span>Cliente</span><strong><?= event_print_h($customerName) ?></strong></div>
      <?php if ($customerPhone !== ''): ?><div class="order-info-row"><span>Telefone</span><strong><?= event_print_h($customerPhone) ?></strong></div><?php endif; ?>
      <?php if ($typeLabel !== ''): ?><div class="order-info-row"><span>Tipo</span><strong><?= event_print_h($typeLabel) ?></strong></div><?php endif; ?>
      <div class="order-info-row"><span>Pagamento</span><strong><?= event_print_h($paymentLabel) ?></strong></div>
      <?php if ($scheduledLabel !== ''): ?><div class="order-info-row"><span>Previsão</span><strong><?= event_print_h($scheduledLabel) ?></strong></div><?php endif; ?>
      <?php if ($address !== '' || $district !== ''): ?><div class="order-info-block"><span>Endereço</span><strong><?= event_print_h(trim($address . ($district !== '' ? ' - ' . $district : ''))) ?></strong></div><?php endif; ?>
      <?php if ($reference !== ''): ?><div class="order-info-block"><span>Referência</span><strong><?= event_print_h($reference) ?></strong></div><?php endif; ?>
      <?php if ($orderNotes !== ''): ?><div class="order-info-block"><span>Observação</span><strong><?= event_print_h($orderNotes) ?></strong></div><?php endif; ?>
    </div>

    <div class="divider"></div>
    <div class="label">Itens</div>
    <?php foreach ($customerItems as $item): ?>
      <?php $unit = event_print_unit_price($item); ?>
      <div class="customer-item">
        <div class="item-name"><?= event_print_h($item['_event_name']) ?></div>
        <?php if (trim((string)$item['_event_note']) !== ''): ?><div class="item-note"><?= nl2br(event_print_h($item['_event_note'])) ?></div><?php endif; ?>
        <div class="item-values">
          <span>Qtd. <?= event_print_h(order_message_qty($item['qtd'] ?? 1)) ?></span>
          <span><?= event_print_h(order_message_money($unit)) ?></span>
          <strong><?= event_print_h(order_message_money((float)($item['total'] ?? 0))) ?></strong>
        </div>
      </div>
    <?php endforeach; ?>

    <div class="totals">
      <div class="total-row"><span>Subtotal</span><strong><?= event_print_h(order_message_money($subtotal)) ?></strong></div>
      <?php if ($discount > 0): ?><div class="total-row"><span>Desconto</span><strong>- <?= event_print_h(order_message_money($discount)) ?></strong></div><?php endif; ?>
      <?php if ($addition > 0): ?><div class="total-row"><span>Acréscimo</span><strong><?= event_print_h(order_message_money($addition)) ?></strong></div><?php endif; ?>
      <?php if ($delivery > 0): ?><div class="total-row"><span>Taxa/entrega</span><strong><?= event_print_h(order_message_money($delivery)) ?></strong></div><?php endif; ?>
      <div class="total-row final"><span>Total</span><strong><?= event_print_h(order_message_money($total)) ?></strong></div>
    </div>
    <div class="footer">Apresente este número para retirar o pedido.<br>Impresso pelo Nexa Próspero</div>
  </section>
</div>

<?php if ($autoPrint): ?>
<script>
(function(){
  const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let printed = false;
  window.addEventListener('afterprint', function(){
    if (printed) return;
    printed = true;
    window.setTimeout(function(){ window.location.href = returnTo; }, 180);
  });
  window.addEventListener('load', function(){ window.setTimeout(function(){ try{ window.print(); }catch(e){} }, 280); });
})();
</script>
<?php endif; ?>
</body>
</html>
