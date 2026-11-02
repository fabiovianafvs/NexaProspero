<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/internal_delivery_status.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
if ($printCross && function_exists('multi_company_context_permission_allowed')) {
  $canKitchenPrint = multi_company_context_permission_allowed($pdo, 'gestao_comandas')
    || multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canKitchenPrint) { http_response_code(403); echo 'Sem permissão para impressão de cozinha na empresa selecionada pelo F11.'; exit; }
}
saas_require_company_module($pdo, $companyId, 'comandas', 'Impressão de cozinha');
if (!$printCross) comanda_ensure_schema($pdo);
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
$printApiBase = function_exists('multi_company_context_url') ? multi_company_context_url('gestao_comandas_print_api.php') : 'gestao_comandas_print_api.php';
$printApiMarkUrl = $printApiBase . (str_contains($printApiBase, '?') ? '&' : '?') . 'action=mark';
$printQueueCsrf = $printCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$pedidoId = (int)($_GET['id'] ?? $_GET['pedido_id'] ?? 0);
$comandaId = (int)($_GET['comanda_id'] ?? 0);
$jobId = (int)($_GET['job_id'] ?? 0);
$monitorMode = !empty($_GET['monitor']);
$auto = !empty($_GET['auto']);
$title = 'Impressão de cozinha';
$internalDeliveryLabel = '';
if (!$printCross) { try { internal_delivery_status_ensure_schema($pdo); } catch (Throwable $e) {} }
$subtitle = '';
$rows = [];
$itemIdsFilter = array_values(array_filter(array_map('intval', preg_split('/[^0-9]+/', (string)($_GET['items'] ?? ''), -1, PREG_SPLIT_NO_EMPTY))));

if ($jobId > 0) {
  $job = comanda_print_job($pdo, $companyId, $jobId);
  if (!$job) { http_response_code(404); echo 'Trabalho de impressão não encontrado.'; exit; }
  $jobItems = comanda_print_job_items($pdo, $companyId, $job);
  $title = 'COMANDA ' . trim((string)($job['comanda_codigo'] ?? ''));
  $customerParts = array_values(array_unique(array_filter([
    trim((string)($job['comanda_nome'] ?? '')),
    trim((string)($job['customer_name'] ?? '')),
    trim((string)($job['customer_phone'] ?? '')),
  ])));
  $subtitle = implode(' — ', $customerParts);
  foreach ($jobItems as $it) {
    $group = trim((string)($job['group_name'] ?? $it['grupo_impressao'] ?? '')) ?: 'Cozinha';
    $rows[] = [
      'group' => $group,
      'printer' => trim((string)($job['printer_name'] ?? '')) ?: trim((string)($it['printer_name'] ?? '')) ?: comanda_printer_for_group($pdo, $companyId, $group),
      'name' => (string)($it['nome'] ?? 'Item'),
      'qty' => (float)($it['qtd'] ?? 0),
      'obs' => (string)($it['observacao'] ?? ''),
      'created_at' => (string)($it['created_at'] ?? ''),
    ];
  }
} elseif ($comandaId > 0) {
  $cmd = comanda_find($pdo, $companyId, $comandaId);
  if (!$cmd) { http_response_code(404); echo 'Comanda não encontrada.'; exit; }
  $title = 'COMANDA ' . trim((string)$cmd['codigo']);
  $subtitle = trim((string)($cmd['nome'] ?? ''));
  $allItems = comanda_items($pdo, $companyId, $comandaId, true);
  if ($itemIdsFilter) {
    $allowed = array_fill_keys($itemIdsFilter, true);
    $allItems = array_values(array_filter($allItems, static fn($row) => isset($allowed[(int)($row['id'] ?? 0)])));
  }
  foreach ($allItems as $it) {
    $group = trim((string)($it['grupo_impressao'] ?? '')) ?: comanda_product_print_group($it);
    $rows[] = [
      'group' => $group,
      'printer' => trim((string)($it['printer_name'] ?? '')) ?: comanda_printer_for_group($pdo, $companyId, $group),
      'name' => (string)($it['nome'] ?? 'Item'),
      'qty' => (float)($it['qtd'] ?? 0),
      'obs' => (string)($it['observacao'] ?? ''),
      'created_at' => (string)($it['created_at'] ?? ''),
    ];
  }
} elseif ($pedidoId > 0) {
  $pedido = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $pedidoId]);
  if (!$pedido) { http_response_code(404); echo 'Pedido não encontrado.'; exit; }
  $num = function_exists('company_sequence_display') ? company_sequence_display($pedido, 'pedidos') : $pedidoId;
  $title = 'Cozinha — Pedido #' . $num;
  $subtitle = trim((string)($pedido['nome_cliente'] ?? ''));
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
  $obsCol = function_exists('gestao_pedido_item_obs_column') ? gestao_pedido_item_obs_column($pdo) : 'complemento';
  $obsSelect = $obsCol ? ', pi.`' . str_replace('`','``',$obsCol) . '` AS obs_item' : ', NULL AS obs_item';
  $extra = comanda_column_exists($pdo, 'produtos', 'grupo_impressao') ? ', p.grupo_impressao' : ', NULL AS grupo_impressao';
  $kitchenExtra = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ', COALESCE(p.imprimir_cozinha,1) AS imprimir_cozinha' : ', 1 AS imprimir_cozinha';
  $st = $pdo->prepare('SELECT pi.*, p.nome, p.categoria, p.grupo_producao' . $extra . $kitchenExtra . $obsSelect . ' FROM pedido_itens pi LEFT JOIN produtos p ON p.company_id = pi.company_id AND p.id = pi.produto_id WHERE pi.company_id = ? AND pi.pedido_id = ? ORDER BY pi.id ASC');
  $st->execute([$companyId, $pedidoId]);
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $it) {
    if ((int)($it['imprimir_cozinha'] ?? 1) !== 1) continue;
    $group = comanda_product_print_group($it);
    $rows[] = [
      'group' => $group,
      'printer' => comanda_printer_for_group($pdo, $companyId, $group),
      'name' => (string)($it['nome'] ?? ('Produto #' . (int)($it['produto_id'] ?? 0))),
      'qty' => (float)($it['qtd'] ?? 0),
      'obs' => (string)($it['obs_item'] ?? ''),
      'created_at' => '',
    ];
  }
} else {
  http_response_code(400); echo 'Informe pedido, comanda ou trabalho de impressão.'; exit;
}

$groups = [];
foreach ($rows as $r) {
  $g = trim((string)$r['group']) ?: 'Cozinha';
  if (!isset($groups[$g])) $groups[$g] = ['printer'=>(string)($r['printer'] ?? ''), 'items'=>[]];
  if ($groups[$g]['printer'] === '' && !empty($r['printer'])) $groups[$g]['printer'] = (string)$r['printer'];
  $groups[$g]['items'][] = $r;
}
ksort($groups, SORT_NATURAL | SORT_FLAG_CASE);
if ($jobId <= 0 && $comandaId > 0 && $itemIdsFilter) {
  try {
    $idsSql = implode(',', array_fill(0, count($itemIdsFilter), '?'));
    $params = array_merge([$companyId, $comandaId], $itemIdsFilter);
    $pdo->prepare("UPDATE comanda_items SET printed_at = COALESCE(printed_at, NOW()) WHERE company_id = ? AND comanda_id = ? AND id IN ($idsSql)")->execute($params);
  } catch (Throwable $e) {}
}
function pc_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pc_qty($v): string { return rtrim(rtrim(number_format((float)$v, 3, ',', '.'), '0'), ','); }
function pc_fit_print_font(string $text, bool $pack58mm, bool $subtitle = false): string {
  $length = function_exists('mb_strlen') ? mb_strlen($text, 'UTF-8') : strlen($text);
  if ($subtitle) {
    if ($length > 34) return $pack58mm ? '6.6pt' : '7.8pt';
    if ($length > 24) return $pack58mm ? '7.2pt' : '8.6pt';
    if ($length > 16) return $pack58mm ? '8pt' : '9.4pt';
    return $pack58mm ? '8.8pt' : '10.5pt';
  }
  if ($length > 16) return $pack58mm ? '8.2pt' : '9.8pt';
  if ($length > 11) return $pack58mm ? '9.2pt' : '11pt';
  return $pack58mm ? '10.4pt' : '12.4pt';
}
$titlePrintFont = pc_fit_print_font($title, $printPack58mm);
$subtitlePrintFont = pc_fit_print_font($subtitle, $printPack58mm, true);
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title><?= pc_h($title) ?></title>
<style>:root{--ink:#000;--muted:#333;--line:#000;--paper:#fff;--screen:#f8fafc;--kitchen-paper:<?= $printPack58mm ? '58mm' : '72mm' ?>}
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:var(--screen);color:var(--ink);font-weight:800}.toolbar{display:flex;justify-content:space-between;gap:10px;align-items:center;padding:14px 18px;background:#fff;border-bottom:1px solid #e2e8f0;position:sticky;top:0;z-index:10}.btn{display:inline-flex;border:1px solid #0f172a;border-radius:999px;background:#fff;padding:9px 13px;text-decoration:none;color:#0f172a;font-weight:900;cursor:pointer}.print-wrap{width:min(860px,100%);margin:18px auto;padding:0 12px}.ticket{background:var(--paper);border:2px solid var(--line);border-radius:10px;padding:16px;margin-bottom:16px;box-shadow:0 10px 24px rgba(15,23,42,.08)}.ticket-head{text-align:center;border-bottom:3px double var(--line);padding-bottom:10px;margin-bottom:12px}.ticket-head .label{font-size:15px;letter-spacing:2px;font-weight:950;text-transform:uppercase}.ticket-head h1{margin:3px auto 0;font-size:26px;line-height:1.02;text-transform:uppercase;text-align:center;white-space:nowrap;overflow:visible;max-width:100%;letter-spacing:0;display:block;width:100%}.subtitle{margin-top:5px;font-size:17px;font-weight:950;text-transform:uppercase;text-align:center;white-space:nowrap;overflow:visible;max-width:100%;letter-spacing:0}.muted{color:var(--muted);font-weight:900}.ticket-meta{display:flex;justify-content:center;gap:10px;flex-wrap:wrap;margin-top:7px;font-size:13px}.group-title{display:block;border:2px solid var(--line);padding:7px 8px;margin:12px 0 8px;text-align:center}.group-title h2{margin:0;font-size:25px;line-height:1.1;text-transform:uppercase;letter-spacing:1px}.internal-delivery{border:2px solid #000;padding:7px 8px;margin:7px 0;font-size:15px;font-weight:950;text-transform:uppercase;text-align:center}.printer-line{display:block;font-size:11px;margin-top:4px;color:#222;text-align:center;font-weight:900}.item{display:grid;grid-template-columns:82px minmax(0,1fr);gap:10px;border-bottom:2px dashed var(--line);padding:11px 0}.item:last-of-type{border-bottom:0}.qty{font-size:30px;font-weight:950;text-align:center;line-height:1;border:2px solid var(--line);border-radius:8px;padding:8px 4px;align-self:flex-start}.name{font-size:26px;font-weight:950;line-height:1.08;text-transform:uppercase}.obs{margin-top:7px;color:#000;font-weight:950;white-space:pre-wrap;font-size:18px;line-height:1.15;border-left:6px solid #000;padding:6px 0 6px 10px;background:#f5f5f5}.footer{text-align:center;color:#111;margin-top:12px;font-size:12px;font-weight:950;border-top:1px dashed #000;padding-top:8px}@media print{@page{size:<?= $printPack58mm ? '58mm' : '72mm' ?> auto;margin:<?= $printPack58mm ? '1mm' : '2mm' ?>}body{background:#fff;width:var(--kitchen-paper)}.toolbar{display:none}.print-wrap{margin:0;padding:0;width:var(--kitchen-paper)}.ticket{box-shadow:none;border:0;border-radius:0;padding:0 2mm;margin:0;page-break-after:always}.ticket:last-child{page-break-after:auto}.ticket-head{border-bottom:2px double #000;padding-bottom:4px;margin-bottom:5px}.ticket-head .label{font-size:10pt;letter-spacing:1pt}.ticket-head h1{font-size:var(--pc-title-print)}.subtitle{font-size:var(--pc-subtitle-print)}.ticket-meta{font-size:8.5pt;margin-top:3px}.group-title{border:1.5pt solid #000;padding:3px;margin:5px 0}.group-title h2{font-size:14pt}.internal-delivery{border:1.5pt solid #000;padding:2.5mm 1.5mm;margin:2mm 0;font-size:9pt}.printer-line{font-size:7.5pt}.item{grid-template-columns:<?= $printPack58mm ? '13mm' : '18mm' ?> 1fr;gap:<?= $printPack58mm ? '1.5mm' : '2mm' ?>;border-bottom:1.5pt dashed #000;padding:3.5mm 0}.qty{font-size:<?= $printPack58mm ? '13pt' : '17pt' ?>;border:1.5pt solid #000;border-radius:2mm;padding:2mm 1mm}.name{font-size:<?= $printPack58mm ? '11pt' : '14pt' ?>;line-height:1.08}.obs{font-size:<?= $printPack58mm ? '8.8pt' : '10.5pt' ?>;background:#fff;border-left:3pt solid #000;padding:1.5mm 0 1.5mm 2mm;margin-top:2mm}.footer{font-size:7.5pt;margin-top:4mm;padding-top:2mm}}</style></head><body>
<div class="toolbar"><strong><?= pc_h($title) ?> <span style="font-size:12px;color:#64748b">(<?= $printPack58mm ? 'pack 58mm' : 'padrão' ?>)</span></strong><div><button class="btn" onclick="window.print()">Imprimir</button><a class="btn" href="javascript:window.close()">Fechar</a></div></div>
<div class="print-wrap">
  <?php if (!$groups): ?><div class="ticket"><div class="ticket-head"><div class="label">Ordem de preparo</div><h1 style="--pc-title-print:<?= pc_h($titlePrintFont) ?>"><?= pc_h($title) ?></h1><div class="muted">Nenhum item para impressão.</div></div></div><?php endif; ?>
  <?php foreach ($groups as $group => $data): ?>
    <section class="ticket">
      <div class="ticket-head"><div class="label">Ordem de preparo</div><h1 style="--pc-title-print:<?= pc_h($titlePrintFont) ?>"><?= pc_h($title) ?></h1><?php if ($subtitle !== ''): ?><div class="subtitle" style="--pc-subtitle-print:<?= pc_h($subtitlePrintFont) ?>"><?= pc_h($subtitle) ?></div><?php endif; ?><div class="ticket-meta"><span><?= date('d/m/Y H:i') ?></span></div></div>
      <div class="group-title"><h2><?= pc_h($group) ?></h2><span class="printer-line"><?= trim((string)$data['printer']) !== '' ? 'Impressora: ' . pc_h($data['printer']) : 'Sem impressora padrão' ?></span></div>
      <?php if ($internalDeliveryLabel !== ''): ?><div class="internal-delivery">Entrega Interno: <?= pc_h($internalDeliveryLabel) ?></div><?php endif; ?>
      <?php foreach ($data['items'] as $it): ?>
        <div class="item"><div class="qty"><?= pc_qty($it['qty']) ?>x</div><div><div class="name"><?= pc_h($it['name']) ?></div><?php if (trim((string)$it['obs']) !== ''): ?><div class="obs"><?= pc_h($it['obs']) ?></div><?php endif; ?></div></div>
      <?php endforeach; ?>
      <div class="footer">Impresso pelo módulo Comandas / Cozinha<?= $jobId > 0 ? ' — Solicitação #' . (int)$jobId : '' ?></div>
    </section>
  <?php endforeach; ?>
</div>
<?php if ($auto || $monitorMode): ?><script>
(function(){
  var sent=false;
  function notify(ok,error){
    if(sent)return;sent=true;
    var jobId=<?= (int)$jobId ?>;
    if(jobId>0){try{var fd=new FormData();fd.append('job_id',String(jobId));fd.append('status',ok===false?'ERRO':'IMPRESSO');fd.append('error',error||'');var csrf=<?= json_encode($printQueueCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;if(csrf)fd.append('mc_csrf',csrf);fetch(<?= json_encode($printApiMarkUrl, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,{method:'POST',body:fd,credentials:'same-origin'}).catch(function(){});}catch(e){}}
    try{window.parent.postMessage({type:'comanda-print-finished',jobId:jobId,ok:ok!==false,error:error||''},'*');}catch(e){}
  }
  window.addEventListener('afterprint',function(){notify(true,'');});
  window.addEventListener('load',function(){setTimeout(function(){try{window.print();}catch(e){notify(false,e&&e.message?e.message:'Falha ao abrir impressão');}},350);});
})();
</script><?php endif; ?>
</body></html>
