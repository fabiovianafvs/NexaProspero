<?php
declare(strict_types=1);

// Pedido no modelo "Duplicata de Venda Mercantil" (A4).
// Incluído somente por pedido_print.php, depois de autenticação, empresa e permissões.

function pedido_dup_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pedido_dup_num($v, int $dec = 2): string { return number_format((float)$v, $dec, ',', '.'); }
function pedido_dup_qty($v): string { $n = number_format((float)$v, 4, ',', '.'); return rtrim(rtrim($n, '0'), ','); }
function pedido_dup_pick(array $row, array $keys): string {
  foreach ($keys as $k) { $v = trim((string)($row[$k] ?? '')); if ($v !== '') return $v; }
  return '';
}
function pedido_dup_first_number(array $row, array $keys): float {
  foreach ($keys as $k) if (array_key_exists($k, $row) && is_numeric($row[$k])) return (float)$row[$k];
  return 0.0;
}

$dupCid = (int)$orderPrintCompanyId;
$dupCompany = [];
try {
  $st = $pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
  $st->execute([$dupCid]);
  $dupCompany = $st->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}
$dupCompanyName = pedido_dup_pick($dupCompany, ['razao_social','legal_name','name']) ?: $site;
$dupCompanyDoc = pedido_dup_pick($dupCompany, ['cnpj','cpf','document']) ?: pedido_dup_pick($fiscalPrintCfg, ['company_cnpj']);
$dupCompanyIe = pedido_dup_pick($fiscalPrintCfg, ['state_registration']) ?: pedido_dup_pick($dupCompany, ['ie','state_registration','inscricao_estadual']);
$dupCompanyStreet = trim(pedido_dup_pick($dupCompany, ['endereco','address']) . (pedido_dup_pick($dupCompany, ['numero','number']) !== '' ? ', ' . pedido_dup_pick($dupCompany, ['numero','number']) : '') . (pedido_dup_pick($dupCompany, ['bairro','district']) !== '' ? ' - ' . pedido_dup_pick($dupCompany, ['bairro','district']) : ''));
$dupCompanyCity = pedido_dup_pick($dupCompany, ['cidade','city']);
$dupCompanyUf = pedido_dup_pick($dupCompany, ['estado','state','uf']);
$dupCompanyPhones = implode(' / ', array_values(array_unique(array_filter([pedido_dup_pick($dupCompany, ['phone','telefone']), pedido_dup_pick($dupCompany, ['whatsapp'])]))));

// Cliente: pedido + cadastro de Pessoas (RG/IE, telefone, endereço completo).
$dupPerson = [];
$dupPersonId = (int)($pedido['finance_person_id'] ?? 0) ?: (int)($pedido['customer_id'] ?? 0);
if ($dupPersonId > 0) {
  try {
    $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$dupCid, $dupPersonId]);
    $dupPerson = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
}
[$dupBairro, $dupEndereco, $dupRef, $dupCidade, $dupUf, $dupCep] = admin_print_split_order_address($pedido);
$dupCustomer = [
  'name' => trim((string)($pedido['nome_cliente'] ?? '')) ?: (pedido_dup_pick($dupPerson, ['name']) ?: 'CONSUMIDOR FINAL'),
  'address' => $dupEndereco !== '' ? $dupEndereco : trim(pedido_dup_pick($dupPerson, ['address']) . (pedido_dup_pick($dupPerson, ['number']) !== '' ? ', ' . pedido_dup_pick($dupPerson, ['number']) : '') . (pedido_dup_pick($dupPerson, ['complement']) !== '' ? ', ' . pedido_dup_pick($dupPerson, ['complement']) : '')),
  'city' => $dupCidade !== '' ? $dupCidade : pedido_dup_pick($dupPerson, ['city']),
  'uf' => $dupUf !== '' ? $dupUf : pedido_dup_pick($dupPerson, ['state']),
  'district' => $dupBairro !== '' ? $dupBairro : pedido_dup_pick($dupPerson, ['district']),
  'doc' => trim((string)($pedido['customer_document'] ?? '')) ?: pedido_dup_pick($dupPerson, ['document']),
  'phone' => trim((string)($pedido['whatsapp'] ?? '')) ?: pedido_dup_pick($dupPerson, ['phone','whatsapp']),
  'rg_ie' => pedido_dup_pick($pedido, ['customer_state_registration']) ?: pedido_dup_pick($dupPerson, ['state_registration','identity_document']),
];

// Códigos dos produtos e unidade.
$dupProducts = [];
try {
  $ids = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['produto_id'] ?? 0), $itens))));
  if ($ids) {
    $hasNumber = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
    $hasLegacy = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasUnit = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
    $expr = 'CAST(id AS CHAR)';
    if ($hasNumber && $hasLegacy) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
    elseif ($hasNumber) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),CAST(id AS CHAR))";
    elseif ($hasLegacy) $expr = "COALESCE(NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
    $marks = implode(',', array_fill(0, count($ids), '?'));
    $st = $pdo->prepare('SELECT id,' . $expr . ' AS code,' . ($hasUnit ? "COALESCE(NULLIF(unidade_estoque,''),'UN')" : "'UN'") . ' AS un FROM produtos WHERE company_id=? AND id IN (' . $marks . ')');
    $st->execute(array_merge([$dupCid], $ids));
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $dupProducts[(int)$r['id']] = $r;
  }
} catch (Throwable $e) {}

// Itens: desconto por item = (qtd x unitário) - total do item.
$dupRows = [];
$dupGross = 0.0;
$dupItemDiscount = 0.0;
foreach ($itens as $idx => $it) {
  $pid = (int)($it['produto_id'] ?? 0);
  $qty = (float)($it['qtd'] ?? 0);
  $unit = item_unit_price_admin($it);
  $net = round((float)($it['total'] ?? ($qty * $unit)), 2);
  $gross = round($qty * $unit, 2);
  if ($gross < $net) $gross = $net;
  $disc = round($gross - $net, 2);
  $note = '';
  if (!empty($itemObsCol)) $note = trim((string)($it[$itemObsCol] ?? ''));
  if ($note === '') $note = (string)($fallbackItemObsByRow[$idx] ?? '');
  $note = normalize_item_complement_admin($note);
  $dupRows[] = [
    'code' => (string)($dupProducts[$pid]['code'] ?? ($pid > 0 ? $pid : '')),
    'un' => (string)($dupProducts[$pid]['un'] ?? 'UN'),
    'desc' => (string)($it['nome'] ?? ('#' . $pid)) . ($note !== '' ? ' - ' . preg_replace('/\s+/u', ' ', $note) : ''),
    'qty' => $qty, 'unit' => $unit, 'gross' => $gross, 'disc' => $disc,
    'pct' => $gross > 0 ? round($disc / $gross * 100, 2) : 0.0, 'net' => $net,
  ];
  $dupGross += $gross;
  $dupItemDiscount += $disc;
}
$dupOrderDiscount = pedido_dup_first_number($pedido, ['desconto','discount','discount_value','valor_desconto']);
$dupAddition = pedido_dup_first_number($pedido, ['acrescimo','addition','addition_value','valor_acrescimo']);
$dupFreight = pedido_dup_first_number($pedido, ['frete','freight','freight_value']);
if ($dupFreight <= 0 && $taxaEntrega > 0) $dupFreight = (float)$taxaEntrega;
$dupDiscountTotal = round($dupItemDiscount + $dupOrderDiscount, 2);

// Recebimento (formas de pagamento) e parcelamento (Contas a Receber do pedido).
$dupPayments = [];
$dupReceivableIds = [];
try {
  $st = $pdo->prepare('SELECT p.*, pm.name AS method_name FROM gestao_order_payments p LEFT JOIN finance_payment_methods pm ON pm.company_id=p.company_id AND pm.id=p.payment_method_id WHERE p.company_id=? AND p.order_id=? ORDER BY p.id');
  $st->execute([$dupCid, (int)$id]);
  foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
    $label = trim((string)($p['payment_label'] ?? '')) ?: trim((string)($p['method_name'] ?? '')) ?: 'Pagamento';
    $dupPayments[] = ['label' => $label, 'amount' => (float)($p['amount'] ?? 0)];
    if ((int)($p['receivable_id'] ?? 0) > 0) $dupReceivableIds[] = (int)$p['receivable_id'];
    preg_match_all('/\d+/', (string)($p['receivable_ids'] ?? ''), $m);
    foreach ($m[0] as $rid) if ((int)$rid > 0) $dupReceivableIds[] = (int)$rid;
  }
} catch (Throwable $e) {}
if (!$dupPayments && trim((string)$formaPgto) !== '') $dupPayments[] = ['label' => (string)$formaPgto, 'amount' => (float)$totalGeral];
$dupInstallments = [];
$dupReceivableIds = array_values(array_unique($dupReceivableIds));
if ($dupReceivableIds) {
  try {
    $marks = implode(',', array_fill(0, count($dupReceivableIds), '?'));
    $st = $pdo->prepare("SELECT document_number, installment, due_date, amount FROM finance_receivables WHERE company_id=? AND id IN ($marks) AND UPPER(COALESCE(status,'')) <> 'CANCELADO' ORDER BY due_date, id");
    $st->execute(array_merge([$dupCid], $dupReceivableIds));
    $dupInstallments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {}
}
$dupInstallmentCount = count($dupInstallments);

$dupTerms = '';
try {
  if (!function_exists('config_store_get')) require_once __DIR__ . '/../config/config_store.php';
  $dupCfg = config_store_get($pdo, $dupCid, 'pedido_duplicata') ?: [];
  $dupTerms = trim((string)($dupCfg['terms'] ?? ''));
} catch (Throwable $e) {}

$dupCreatedTs = !empty($pedido['created_at']) ? strtotime((string)$pedido['created_at']) : false;
$dupDocNumber = (string)$pedidoNumero;

$baseQuery = 'id=' . (int)$id . '&return_to=' . rawurlencode($returnTo) . '&autoprint=0';
$dupUrl72 = 'pedido_print.php?' . $baseQuery . '&modelo=72mm';
$dupUrlA4 = 'pedido_print.php?' . $baseQuery . '&modelo=a4';
$dupUrlDup = 'pedido_print.php?' . $baseQuery . '&modelo=duplicata';
$dupUrlLegacy = 'pedido_print.php?' . $baseQuery . '&legacy=1';
$dupWaUrl = 'documento_whatsapp.php?type=pedido&id=' . (int)$id . '&modelo=a4&return_to=' . rawurlencode($returnTo);
$dupEmailUrl = 'email_documento.php?type=pedido&id=' . (int)$id . '&return_to=' . rawurlencode($returnTo);
if (function_exists('multi_company_context_url')) {
  foreach (['dupUrl72','dupUrlA4','dupUrlDup','dupUrlLegacy'] as $__v) $$__v = multi_company_context_url($$__v);
  if (!$orderPrintCross) { $dupWaUrl = multi_company_context_url($dupWaUrl); $dupEmailUrl = multi_company_context_url($dupEmailUrl); }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Duplicata - Pedido #<?= pedido_dup_h($dupDocNumber) ?></title>
<link rel="stylesheet" href="../assets/nexa-print-actions.css?v=1145197">
<style>
*{box-sizing:border-box;-webkit-print-color-adjust:exact;print-color-adjust:exact;color-adjust:exact}
body{margin:0;background:#eef2f7;color:#000;font-family:Arial,Helvetica,sans-serif}
@page{size:A4 portrait;margin:8mm}
.sheet{width:194mm;margin:0 auto;background:#fff;padding:4mm;box-shadow:0 12px 30px rgba(15,23,42,.12);font-size:8.6pt}
table{border-collapse:collapse;width:100%}
.box{border:1px solid #000}
.head td{border:1px solid #000;vertical-align:top}
.head .logo-cell{width:34mm;text-align:center;vertical-align:middle;padding:1.5mm}
.head .logo-cell img{max-width:30mm;max-height:16mm;object-fit:contain}
.head .title{font-family:"Times New Roman",Times,serif;font-size:13pt;font-weight:700;text-align:center;padding:2mm}
.head .meta td{border:0;border-left:1px solid #000;padding:.6mm 1.5mm;vertical-align:top}
.head .meta td:first-child{border-left:0}
.meta small{display:block;font-size:6.5pt}
.meta b{font-size:11pt}
.warning{font-family:"Times New Roman",Times,serif;font-size:12.5pt;font-weight:700;text-align:center;border:1px solid #000;border-top:0;padding:.6mm}
.parties{border:1px solid #000;border-top:0}
.parties>tbody>tr>td{width:50%;vertical-align:top;padding:0}
.parties>tbody>tr>td+td{border-left:1px solid #000}
.bar{background:#bfbfbf;font-weight:700;padding:.3mm 1.5mm;font-family:"Times New Roman",Times,serif;font-size:8.5pt}
.party{padding:.6mm 1.5mm;font-family:"Times New Roman",Times,serif;font-size:8.2pt;line-height:1.18}
.party>div{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.party .row{display:flex;gap:1.5mm;min-width:0}.party .row b{min-width:15mm;flex:none}.party .row span{min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.party .row .half{display:flex;gap:1.5mm;flex:1 1 50%;min-width:0}
.items{border:1px solid #000;border-top:0}
.items th{font-family:"Times New Roman",Times,serif;font-size:8.5pt;text-align:left;border-bottom:1px solid #000;padding:.4mm 1mm}
.items td{font-size:7.8pt;padding:.4mm 1mm;border-bottom:1px solid #000;vertical-align:top}
.r{text-align:right!important;white-space:nowrap}.c{text-align:center!important}
.bottom{margin-top:1.5mm;display:grid;grid-template-columns:76mm 1fr;gap:4mm;align-items:start}
.totals{border:1px solid #000;padding:1mm 2mm;font-family:"Times New Roman",Times,serif}
.totals div{display:flex;justify-content:space-between;font-size:8.8pt}
.totals .i{font-style:italic}.totals .b{font-weight:700}
.receipts{margin-top:2mm;width:60mm}.receipts th{font-family:"Times New Roman",Times,serif;font-size:8.5pt;text-align:left;padding:0 1mm}.receipts td{font-size:7.8pt;padding:0 1mm}
.inst-title{font-family:"Times New Roman",Times,serif;font-weight:700;font-size:8.5pt}
.inst{border-collapse:collapse}.inst thead th{border:1px solid #000;font-family:"Times New Roman",Times,serif;font-size:8.5pt;padding:.4mm 1.5mm}.inst td{font-size:7.8pt;padding:.4mm 1.5mm}
.declare{margin-top:6mm;font-size:8pt;max-width:100%}
.sign{margin-top:9mm;width:68mm;max-width:100%;border-top:1px solid #000;font-family:"Times New Roman",Times,serif;font-size:9pt;padding-top:.5mm}
.sign b{display:block}
.terms{margin-top:3mm;font-size:8pt;font-weight:700;white-space:pre-wrap;line-height:1.3}
.terms h4{margin:0 0 1mm;font-size:8.5pt}
.obs{margin-top:2mm;font-size:8pt;white-space:pre-wrap}
@media print{body{background:#fff}.nexa-print-toolbar{display:none!important}.sheet{box-shadow:none;width:auto;padding:0}}
@media(max-width:760px){.sheet{width:100%;padding:8px;overflow-x:auto}}
</style>
</head>
<body>
<div class="nexa-print-toolbar">
  <div class="nexa-print-toolbar-copy">
    <strong class="nexa-print-toolbar-title">Pedido #<?= pedido_dup_h($dupDocNumber) ?> · Duplicata</strong>
    <div class="nexa-print-toolbar-hint">Escolha o formato ou a ação.</div>
  </div>
  <div class="nexa-print-actions">
    <a class="nexa-print-action" href="<?= pedido_dup_h($dupUrl72) ?>"><span class="nexa-print-action-icon" aria-hidden="true">🧾</span><span class="nexa-print-action-label">70 mm</span></a>
    <a class="nexa-print-action" href="<?= pedido_dup_h($dupUrlA4) ?>"><span class="nexa-print-action-icon" aria-hidden="true">📄</span><span class="nexa-print-action-label">A4</span></a>
    <a class="nexa-print-action is-active" href="<?= pedido_dup_h($dupUrlDup) ?>"><span class="nexa-print-action-icon" aria-hidden="true">📑</span><span class="nexa-print-action-label">Duplicata</span></a>
    <button class="nexa-print-action is-primary" type="button" onclick="window.print()"><span class="nexa-print-action-icon" aria-hidden="true">🖨</span><span class="nexa-print-action-label">Imprimir</span></button>
    <?php if (!$orderPrintCross): ?>
      <a class="nexa-print-action is-whatsapp" href="<?= pedido_dup_h($dupWaUrl) ?>"><span class="nexa-print-action-icon" aria-hidden="true">💬</span><span class="nexa-print-action-label">WhatsApp</span></a>
      <a class="nexa-print-action" href="<?= pedido_dup_h($dupEmailUrl) ?>"><span class="nexa-print-action-icon" aria-hidden="true">✉</span><span class="nexa-print-action-label">E-mail</span></a>
    <?php endif; ?>
    <a class="nexa-print-action is-muted" href="<?= pedido_dup_h($dupUrlLegacy) ?>"><span class="nexa-print-action-icon" aria-hidden="true">↶</span><span class="nexa-print-action-label">Anterior</span></a>
    <a class="nexa-print-action" href="<?= pedido_dup_h($returnTo) ?>" onclick="event.preventDefault();nexaPrintBack(this.href)"><span class="nexa-print-action-icon" aria-hidden="true">↩</span><span class="nexa-print-action-label">Voltar</span></a>
  </div>
</div>
<main class="sheet">
  <table class="head"><tr>
    <td class="logo-cell" rowspan="2"><?php if ($printLogoUrl !== ''): ?><img src="<?= pedido_dup_h($printLogoUrl) ?>" alt="Logo"><?php else: ?><b><?= pedido_dup_h($dupCompanyName) ?></b><?php endif; ?></td>
    <td class="title">DUPLICATA DE VENDA MERCANTIL</td>
  </tr><tr><td style="padding:0">
    <table class="meta"><tr>
      <td style="width:20%"><small>Documento:</small><b><?= pedido_dup_h($dupDocNumber) ?></b></td>
      <td style="width:20%"><small>Emissão:</small><b><?= $dupCreatedTs ? date('d/m/Y', $dupCreatedTs) : '' ?></b></td>
      <td style="width:15%"><small>Hora:</small><span style="font-family:'Times New Roman',serif;font-size:11pt"><?= $dupCreatedTs ? date('H:i:s', $dupCreatedTs) : '' ?></span></td>
      <td style="width:22%"><small>Valor:</small><b style="display:block;text-align:right"><?= pedido_dup_num($totalGeral) ?></b></td>
      <td><small>Consultor:</small><b><?= pedido_dup_h(mb_strtoupper((string)$orderSellerName, 'UTF-8')) ?></b></td>
    </tr></table>
  </td></tr></table>
  <div class="warning">NÃO É DOCUMENTO FISCAL. NÃO É VÁLIDO COMO GARANTIA DE MERCADORIA</div>

  <table class="parties"><tr>
    <td>
      <div class="bar">Empresa:</div>
      <div class="party">
        <b><?= pedido_dup_h(mb_strtoupper($dupCompanyName, 'UTF-8')) ?></b>
        <?php if ($dupCompanyDoc !== ''): ?><div>CNPJ: <?= pedido_dup_h($dupCompanyDoc) ?></div><?php endif; ?>
        <?php if ($dupCompanyIe !== ''): ?><div>I.E.: <?= pedido_dup_h($dupCompanyIe) ?></div><?php endif; ?>
        <?php if ($dupCompanyStreet !== ''): ?><div><?= pedido_dup_h(mb_strtoupper($dupCompanyStreet, 'UTF-8')) ?></div><?php endif; ?>
        <?php if ($dupCompanyCity !== ''): ?><div><?= pedido_dup_h(mb_strtoupper($dupCompanyCity . ($dupCompanyUf !== '' ? '/' . $dupCompanyUf : ''), 'UTF-8')) ?></div><?php endif; ?>
        <?php if ($dupCompanyPhones !== ''): ?><div><?= pedido_dup_h($dupCompanyPhones) ?></div><?php endif; ?>
      </div>
    </td>
    <td>
      <div class="bar">Cliente:</div>
      <div class="party">
        <div class="row"><b>Cliente:</b><span><?= pedido_dup_h(mb_strtoupper($dupCustomer['name'], 'UTF-8')) ?></span></div>
        <div class="row"><b>Endereço:</b><span><?= pedido_dup_h(mb_strtoupper($dupCustomer['address'], 'UTF-8')) ?></span></div>
        <div class="row"><b>Cidade:</b><span style="flex:1"><?= pedido_dup_h(mb_strtoupper($dupCustomer['city'], 'UTF-8')) ?></span><b style="min-width:0">UF</b><span style="min-width:10mm"><?= pedido_dup_h(mb_strtoupper($dupCustomer['uf'], 'UTF-8')) ?></span></div>
        <div class="row"><div class="half"><b>CPF/CNPJ:</b><span><?= pedido_dup_h($dupCustomer['doc']) ?></span></div><div class="half"><b style="min-width:0">RG/I.E.:</b><span><?= pedido_dup_h($dupCustomer['rg_ie']) ?></span></div></div>
        <div class="row"><div class="half"><b>Bairro:</b><span><?= pedido_dup_h(mb_strtoupper($dupCustomer['district'], 'UTF-8')) ?></span></div><div class="half"><b style="min-width:0">Telefone:</b><span><?= pedido_dup_h($dupCustomer['phone']) ?></span></div></div>
      </div>
    </td>
  </tr></table>

  <table class="items">
    <thead>
      <tr><th colspan="9" class="bar" style="border-bottom:1px solid #000">Produtos/Serviços</th></tr>
      <tr><th style="width:15mm">Código</th><th style="width:8mm">Un</th><th>Descrição/Complemento</th><th class="c" style="width:17mm">Quantidade</th><th class="r" style="width:16mm">Vlr. Unit</th><th class="r" style="width:17mm">Total s/ desc</th><th class="r" style="width:13mm">% desc</th><th class="r" style="width:15mm">Vlr Desc</th><th class="r" style="width:18mm">Total c/ desc</th></tr>
    </thead>
    <tbody>
    <?php foreach ($dupRows as $r): ?>
      <tr><td><?= pedido_dup_h($r['code']) ?></td><td><?= pedido_dup_h($r['un']) ?></td><td><?= pedido_dup_h(mb_strtoupper($r['desc'], 'UTF-8')) ?></td><td class="c"><?= pedido_dup_h(pedido_dup_qty($r['qty'])) ?></td><td class="r"><?= pedido_dup_num($r['unit']) ?></td><td class="r"><?= pedido_dup_num($r['gross']) ?></td><td class="r"><?= pedido_dup_num($r['pct']) ?></td><td class="r"><?= pedido_dup_num($r['disc']) ?></td><td class="r"><?= pedido_dup_num($r['net']) ?></td></tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <div class="bottom">
    <div>
      <div class="totals">
        <div class="b"><span>Valor total bruto:</span><span><?= pedido_dup_num($dupGross) ?></span></div>
        <div class="i"><span>Total de Descontos:</span><span><b><?= pedido_dup_num($dupDiscountTotal) ?></b></span></div>
        <div class="i"><span>Total de Acréscimos:</span><span><?= pedido_dup_num($dupAddition) ?></span></div>
        <div class="i"><span>Frete:</span><span><?= pedido_dup_num($dupFreight) ?></span></div>
        <div class="b"><span>Valor a pagar:</span><span><?= pedido_dup_num($totalGeral) ?></span></div>
      </div>
      <?php if ($dupPayments): ?>
      <table class="receipts"><thead><tr><th>Recebimento</th><th class="r">Valor</th></tr></thead><tbody>
        <?php foreach ($dupPayments as $p): ?><tr><td><?= pedido_dup_h(mb_strtoupper($p['label'], 'UTF-8')) ?></td><td class="r"><?= pedido_dup_num($p['amount']) ?></td></tr><?php endforeach; ?>
      </tbody></table>
      <?php endif; ?>
    </div>
    <div>
      <?php if ($dupInstallments): ?>
        <div class="inst-title">Parcelamento</div>
        <table class="inst"><thead><tr><th>Nº do documento</th><th>Parcela</th><th>Vencimento</th><th class="r">Valor</th></tr></thead><tbody>
          <?php foreach ($dupInstallments as $n => $inst):
            $instNumber = trim((string)($inst['installment'] ?? ''));
            if ($instNumber === '') $instNumber = str_pad((string)($n + 1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$dupInstallmentCount, 2, '0', STR_PAD_LEFT);
            $instDoc = trim((string)($inst['document_number'] ?? '')) ?: str_pad($dupDocNumber, 6, '0', STR_PAD_LEFT);
            $instDue = !empty($inst['due_date']) ? date('d/m/Y', strtotime((string)$inst['due_date'])) : ''; ?>
            <tr><td class="c"><?= pedido_dup_h($instDoc) ?></td><td class="c"><?= pedido_dup_h($instNumber) ?></td><td class="c"><b><?= pedido_dup_h($instDue) ?></b></td><td class="r"><b>R$ <?= pedido_dup_num($inst['amount'] ?? 0) ?></b></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      <?php endif; ?>
    </div>
  </div>

  <div class="bottom" style="margin-top:4mm">
    <div>
      <div class="declare">Reconhe(cemos) a exatidão desta duplicata de Venda Mercantil na importância, praça e vencimento indicados.</div>
      <div class="sign"><?= pedido_dup_h(mb_strtoupper($dupCustomer['name'], 'UTF-8')) ?><b>(ASS POR EXTENSO)</b></div>
      <?php if ($obs !== ''): ?><div class="obs"><b>Observações:</b> <?= pedido_dup_h($obs) ?></div><?php endif; ?>
    </div>
    <div>
      <?php if ($dupTerms !== ''): ?><div class="terms"><h4>GARANTIA E ORIENTAÇÕES</h4><?= pedido_dup_h($dupTerms) ?></div><?php endif; ?>
    </div>
  </div>
</main>
<?php if ($autoPrint): ?><script>window.addEventListener('load',function(){setTimeout(function(){try{window.print();}catch(e){}},250);});</script><?php endif; ?>
<script>
function nexaPrintBack(url){try{if(window.opener||window.history.length<=1){window.close();}}catch(e){}setTimeout(function(){window.location.href=url;},250);}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){e.preventDefault();nexaPrintBack(<?= json_encode($returnTo, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>);}});
</script>
</body>
</html>
