<?php
declare(strict_types=1);

// V11.45.18 - Renderer moderno do Pedido.
// Este arquivo é incluído exclusivamente por pedido_print.php após autenticação, tenant e permissões.
// O modelo legado permanece no próprio pedido_print.php e continua padrão enquanto a empresa não habilitar esta opção.

$model = isset($modernOrderPrintModel) && in_array($modernOrderPrintModel, ['72mm','a4'], true)
  ? $modernOrderPrintModel
  : 'a4';

function pedido_moderno_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pedido_moderno_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function pedido_moderno_qty($v): string {
  $n = number_format((float)$v, 4, ',', '.');
  return rtrim(rtrim($n, '0'), ',');
}
function pedido_moderno_date_time($v): string {
  $ts = $v ? strtotime((string)$v) : false;
  return $ts ? date('d/m/Y H:i', $ts) : '';
}
function pedido_moderno_company_doc(array $c): string {
  foreach (['cnpj','cpf','document'] as $k) {
    $v = trim((string)($c[$k] ?? ''));
    if ($v !== '') return $v;
  }
  return '';
}
function pedido_moderno_company_address(array $c): string {
  $state = trim((string)($c['estado'] ?? $c['state'] ?? $c['uf'] ?? ''));
  $city = trim((string)($c['cidade'] ?? $c['city'] ?? ''));
  if ($city !== '' && $state !== '') $city .= '/' . $state;
  $parts = [
    trim((string)($c['endereco'] ?? $c['address'] ?? '')),
    trim((string)($c['numero'] ?? $c['number'] ?? '')),
    trim((string)($c['bairro'] ?? $c['district'] ?? '')),
    $city,
  ];
  return trim(implode(' - ', array_values(array_filter($parts, static fn($v) => $v !== ''))));
}
function pedido_moderno_first_number(array $row, array $keys): float {
  foreach ($keys as $key) {
    if (array_key_exists($key, $row) && is_numeric($row[$key])) return (float)$row[$key];
  }
  return 0.0;
}

$company = [];
try {
  $stModernCompany = $pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
  $stModernCompany->execute([$orderPrintCompanyId]);
  $company = $stModernCompany->fetch(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$productCodes = [];
try {
  $productIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['produto_id'] ?? 0), $itens), static fn($v) => $v > 0)));
  if ($productIds) {
    $hasNumber = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
    $hasLegacy = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $expr = 'CAST(id AS CHAR)';
    if ($hasNumber && $hasLegacy) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
    elseif ($hasNumber) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),CAST(id AS CHAR))";
    elseif ($hasLegacy) $expr = "COALESCE(NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
    $marks = implode(',', array_fill(0, count($productIds), '?'));
    $stCodes = $pdo->prepare('SELECT id,' . $expr . ' AS display_code FROM produtos WHERE company_id=? AND id IN (' . $marks . ')');
    $stCodes->execute(array_merge([$orderPrintCompanyId], $productIds));
    foreach ($stCodes->fetchAll(PDO::FETCH_ASSOC) ?: [] as $codeRow) $productCodes[(int)$codeRow['id']] = (string)$codeRow['display_code'];
  }
} catch (Throwable $e) {}

[$printBairroModern, $printEnderecoModern, $printReferenciaModern, $printCidadeModern, $printUfModern, $printCepModern] = admin_print_split_order_address($pedido);
$customerNameModern = trim((string)($pedido['nome_cliente'] ?? '')) ?: 'Consumidor final';
$customerDocModern = trim((string)($pedido['customer_document'] ?? ''));
$customerPhoneModern = trim((string)($pedido['whatsapp'] ?? ''));
$customerAddressModern = trim($printEnderecoModern);
if ($printBairroModern !== '') $customerAddressModern .= ($customerAddressModern !== '' ? ' - ' : '') . $printBairroModern;
if ($printCidadeModern !== '') $customerAddressModern .= ($customerAddressModern !== '' ? ' - ' : '') . $printCidadeModern . ($printUfModern !== '' ? '/' . $printUfModern : '');
if ($printCepModern !== '') $customerAddressModern .= ($customerAddressModern !== '' ? ' - ' : '') . 'CEP ' . $printCepModern;

$createdModern = pedido_moderno_date_time($pedido['created_at'] ?? '');
$scheduledModern = scheduled_delivery_label_admin($pedido);
$tipoEntregaModern = trim((string)$tipoEntrega);
if ($tipoEntregaModern === 'retirada') $tipoEntregaModern = (string)($app['retirada_label'] ?? 'Retirada');
elseif ($tipoEntregaModern !== '') $tipoEntregaModern = ucfirst(str_replace('_', ' ', $tipoEntregaModern));
$formaPgtoModern = trim((string)$formaPgto);
$paymentLabels = ['pix'=>'Pix','dinheiro'=>'Dinheiro','debito'=>'Cartão Débito','credito'=>'Cartão Crédito'];
if (isset($paymentLabels[$formaPgtoModern])) $formaPgtoModern = $paymentLabels[$formaPgtoModern];
if ($formaPgtoModern === '') $formaPgtoModern = 'Não informado';

$discountModern = pedido_moderno_first_number($pedido, ['desconto','discount','discount_value','valor_desconto']);
$additionModern = pedido_moderno_first_number($pedido, ['acrescimo','addition','addition_value','valor_acrescimo']);
$freightModern = pedido_moderno_first_number($pedido, ['frete','freight','freight_value']);
if ($freightModern <= 0 && $taxaEntrega > 0) $freightModern = $taxaEntrega;

$itemNotesModern = [];
foreach ($itens as $__idx => $it) {
  $note = '';
  if ($itemObsCol) $note = trim((string)($it[$itemObsCol] ?? ''));
  if ($note === '') $note = (string)($fallbackItemObsByRow[$__idx] ?? '');
  $itemNotesModern[$__idx] = normalize_item_complement_admin($note);
}

$baseQuery = 'id=' . (int)$id . '&return_to=' . rawurlencode($returnTo) . '&autoprint=0';
$url72 = 'pedido_print.php?' . $baseQuery . '&modelo=72mm';
$urlA4 = 'pedido_print.php?' . $baseQuery . '&modelo=a4';
$urlLegacy = 'pedido_print.php?' . $baseQuery . '&legacy=1';
$urlDuplicata = 'pedido_print.php?' . $baseQuery . '&modelo=duplicata';
$waUrl = 'documento_whatsapp.php?type=pedido&id=' . (int)$id . '&modelo=' . rawurlencode($model) . '&return_to=' . rawurlencode($returnTo);
$emailUrl = 'email_documento.php?type=pedido&id=' . (int)$id . '&return_to=' . rawurlencode($returnTo);
if (function_exists('multi_company_context_url')) {
  $url72 = multi_company_context_url($url72);
  $urlA4 = multi_company_context_url($urlA4);
  $urlLegacy = multi_company_context_url($urlLegacy);
  $urlDuplicata = multi_company_context_url($urlDuplicata);
  if (!$orderPrintCross) {
    $waUrl = multi_company_context_url($waUrl);
    $emailUrl = multi_company_context_url($emailUrl);
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Pedido #<?= pedido_moderno_h($pedidoNumero) ?></title>
<link rel="stylesheet" href="../assets/nexa-print-actions.css?v=1145197">
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif}.toolbar{width:min(900px,100%);margin:0 auto 12px;padding:12px;display:flex;justify-content:space-between;gap:10px;align-items:center;background:#fff;border:1px solid #dbe4ee;border-radius:16px}.actions{display:flex;gap:7px;flex-wrap:wrap}.btn{display:inline-flex;align-items:center;justify-content:center;padding:8px 12px;border:1px solid #cbd5e1;border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#0b1f5b;color:#fff;border-color:#0b1f5b}.sheet{background:#fff;margin:0 auto;box-shadow:0 12px 30px rgba(15,23,42,.12)}.header{display:flex;justify-content:space-between;gap:10px;border-bottom:2px solid #0b1f5b;padding-bottom:6px}.company-head{display:flex;gap:7px;align-items:flex-start;min-width:0}.logo{object-fit:contain;flex:none}.company-data{min-width:0}.company-name{font-weight:950;color:#0b1f5b}.muted{color:#64748b}.doc{text-align:right}.doc strong{display:block;color:#0b1f5b}.section{margin-top:8px}.title{font-weight:900;text-transform:uppercase;color:#0b1f5b;border-bottom:1px solid #cbd5e1;padding-bottom:3px;margin-bottom:5px}.grid{display:grid;gap:4px 12px}.field b{display:block;color:#526173;font-size:.82em}.items{width:100%;border-collapse:collapse}.items th,.items td{border-bottom:1px solid #dbe4ee;text-align:left;vertical-align:top}.items th{background:#f8fafc;color:#475569;text-transform:uppercase}.num{text-align:right!important;white-space:nowrap}.item-note{color:#64748b;margin-top:2px}.totals{margin-left:auto}.totals>div{display:flex;justify-content:space-between;gap:12px}.totals .grand{border-top:2px solid #0b1f5b;font-weight:950;color:#0b1f5b}.notes{white-space:pre-wrap}.footer{text-align:center;color:#64748b;border-top:1px dashed #94a3b8}.status{display:inline-block;border:1px solid #cbd5e1;border-radius:999px;padding:2px 7px;font-weight:900}.legacy-note{font-size:11px;color:#64748b;font-weight:750;margin-top:3px}
<?php if($model==='72mm'): ?>
@page{size:70mm auto;margin:0}.sheet{width:70mm;padding:2mm 4.5mm 2mm 2mm;font-size:10pt}.logo{width:15mm;height:11mm}.company-name{font-size:11.5pt}.header{flex-direction:column;gap:2mm}.doc{text-align:left;font-size:9.5pt;border-top:1px dashed #cbd5e1;padding-top:1.5mm}.doc strong{font-size:15pt;word-break:break-word}.title{font-size:9.5pt}.grid{grid-template-columns:1fr}.items{table-layout:fixed}.items th,.items td{padding:1mm .4mm;font-size:9pt}.items th{font-size:7.5pt}.items th:nth-child(1){width:13mm}.items th:nth-child(2){width:9mm}.items th:nth-child(3){width:18mm}.items td{overflow-wrap:anywhere}.items tr.it-desc td{font-size:9.5pt}.item-note{font-size:8pt}.totals{width:100%;margin-top:2mm;font-size:10pt}.totals>div{padding:.6mm 0}.totals .grand{font-size:13pt;padding-top:1mm;margin-top:1mm}.footer{font-size:7.5pt;margin-top:3mm;padding-top:2mm}.toolbar{max-width:70mm}.hide72{display:none}.signature{margin-top:12mm}.signature .sig-line{width:90%}.totals,.pay-row{padding-right:2.5mm}.totals .grand{font-size:12.5pt}
<?php else: ?>
@page{size:A4 portrait;margin:10mm}.sheet{width:190mm;min-height:277mm;padding:8mm;font-size:10pt}.logo{width:24mm;height:18mm}.company-name{font-size:15pt}.doc strong{font-size:20pt}.doc{font-size:9pt}.title{font-size:9pt}.grid{grid-template-columns:1fr 1fr}.items th,.items td{padding:2mm 1.5mm;font-size:8.5pt}.items th:nth-child(1){width:auto}.items th:nth-child(2){width:24mm}.items th:nth-child(3){width:30mm}.items th:nth-child(4){width:30mm}.totals{width:75mm;margin-top:4mm}.totals>div{padding:1mm 0}.totals .grand{font-size:13pt;padding-top:2mm;margin-top:1mm}.footer{font-size:8pt;margin-top:8mm;padding-top:3mm}
<?php endif; ?>
.items tbody.it tr.it-desc td{border-bottom:0;padding-bottom:0}.items tbody.it tr.it-vals td{color:#334155}.items tbody.it{break-inside:avoid;page-break-inside:avoid}.items .num{text-align:right}.signature{margin-top:16mm;text-align:center;break-inside:avoid;page-break-inside:avoid}.signature .sig-line{width:70%;margin:0 auto 1.5mm;border-top:1px solid #111}.signature .sig-label{font-weight:800}.signature .sig-name{color:#64748b;font-size:.9em}@media print{body{background:#fff}.toolbar{display:none!important}.sheet{box-shadow:none;margin:0 auto}.items thead{display:table-header-group}.items tr{break-inside:avoid;page-break-inside:avoid}}@media(max-width:760px){.toolbar{border-radius:0;margin:0}.sheet{width:100%;min-height:0;padding:14px}.grid{grid-template-columns:1fr}}
</style>
</head>
<body>
<div class="nexa-print-toolbar <?= $model==='72mm'?'nexa-print-toolbar--compact':'' ?>">
  <div class="nexa-print-toolbar-copy">
    <strong class="nexa-print-toolbar-title">Pedido #<?= pedido_moderno_h($pedidoNumero) ?></strong>
    <div class="nexa-print-toolbar-hint">Escolha o formato ou a ação. O modelo anterior continua disponível.</div>
  </div>
  <div class="nexa-print-actions">
    <a class="nexa-print-action <?= $model==='72mm'?'is-active':'' ?>" title="Visualizar pedido em 70 mm" aria-label="Visualizar pedido em 70 mm" href="<?= pedido_moderno_h($url72) ?>"><span class="nexa-print-action-icon" aria-hidden="true">🧾</span><span class="nexa-print-action-label">70 mm</span></a>
    <a class="nexa-print-action <?= $model==='a4'?'is-active':'' ?>" title="Visualizar pedido em A4" aria-label="Visualizar pedido em A4" href="<?= pedido_moderno_h($urlA4) ?>"><span class="nexa-print-action-icon" aria-hidden="true">📄</span><span class="nexa-print-action-label">A4</span></a>
    <a class="nexa-print-action" title="Visualizar pedido no modelo Duplicata de Venda Mercantil" aria-label="Duplicata" href="<?= pedido_moderno_h($urlDuplicata) ?>"><span class="nexa-print-action-icon" aria-hidden="true">📑</span><span class="nexa-print-action-label">Duplicata</span></a>
    <button class="nexa-print-action is-primary" type="button" title="Imprimir pedido" aria-label="Imprimir pedido" onclick="window.print()"><span class="nexa-print-action-icon" aria-hidden="true">🖨</span><span class="nexa-print-action-label">Imprimir</span></button>
    <?php if (!$orderPrintCross): ?>
      <a class="nexa-print-action is-whatsapp" title="Enviar pedido por WhatsApp" aria-label="Enviar pedido por WhatsApp" href="<?= pedido_moderno_h($waUrl) ?>"><span class="nexa-print-action-icon" aria-hidden="true">💬</span><span class="nexa-print-action-label">WhatsApp</span></a>
      <a class="nexa-print-action" title="Enviar pedido por e-mail" aria-label="Enviar pedido por e-mail" href="<?= pedido_moderno_h($emailUrl) ?>"><span class="nexa-print-action-icon" aria-hidden="true">✉</span><span class="nexa-print-action-label">E-mail</span></a>
    <?php endif; ?>
    <a class="nexa-print-action is-muted" title="Abrir modelo anterior do pedido" aria-label="Abrir modelo anterior do pedido" href="<?= pedido_moderno_h($urlLegacy) ?>"><span class="nexa-print-action-icon" aria-hidden="true">↶</span><span class="nexa-print-action-label">Anterior</span></a>
    <a class="nexa-print-action" title="Voltar" aria-label="Voltar" href="<?= pedido_moderno_h($returnTo) ?>" onclick="event.preventDefault();nexaPrintBack(this.href)"><span class="nexa-print-action-icon" aria-hidden="true">↩</span><span class="nexa-print-action-label">Voltar</span></a>
  </div>
</div>
<main class="sheet">
  <header class="header">
    <div class="company-head">
      <?php if($printLogoUrl!==''):?><img class="logo" src="<?= pedido_moderno_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif;?>
      <div class="company-data">
        <div class="company-name"><?= pedido_moderno_h(trim((string)($company['name'] ?? '')) ?: $site) ?></div>
        <?php if(pedido_moderno_company_doc($company)!==''):?><div><?= pedido_moderno_h(pedido_moderno_company_doc($company)) ?></div><?php endif;?>
        <?php if(pedido_moderno_company_address($company)!==''):?><div class="muted"><?= pedido_moderno_h(pedido_moderno_company_address($company)) ?></div><?php endif;?>
        <?php if(trim((string)($company['whatsapp']??''))!==''):?><div class="muted"><?= pedido_moderno_h($company['whatsapp']) ?></div><?php endif;?>
      </div>
    </div>
    <div class="doc"><strong>PEDIDO #<?= pedido_moderno_h($pedidoNumero) ?></strong><?php if($createdModern!==''):?><div><?= pedido_moderno_h($createdModern) ?></div><?php endif;?><div class="status"><?= pedido_moderno_h(trim((string)($pedido['status'] ?? 'PEDIDO'))) ?></div></div>
  </header>

  <section class="section"><div class="title">Cliente</div><div class="grid">
    <div class="field"><b>Nome / Razão social</b><?= pedido_moderno_h($customerNameModern) ?></div>
    <?php if($customerDocModern!==''):?><div class="field"><b>CPF/CNPJ</b><?= pedido_moderno_h($customerDocModern) ?></div><?php endif;?>
    <?php if($customerPhoneModern!==''):?><div class="field"><b>Telefone / WhatsApp</b><?= pedido_moderno_h($customerPhoneModern) ?></div><?php endif;?>
    <?php if($customerAddressModern!==''):?><div class="field"><b>Endereço</b><?= pedido_moderno_h($customerAddressModern) ?></div><?php endif;?>
    <?php if($printReferenciaModern!==''):?><div class="field"><b>Referência</b><?= pedido_moderno_h($printReferenciaModern) ?></div><?php endif;?>
  </div></section>

  <section class="section"><div class="title">Atendimento / Entrega</div><div class="grid">
    <?php if($orderSellerName!==''):?><div class="field"><b>Vendedor</b><?= pedido_moderno_h($orderSellerName) ?></div><?php endif;?>
    <?php if($tipoEntregaModern!==''):?><div class="field"><b>Tipo</b><?= pedido_moderno_h($tipoEntregaModern) ?></div><?php endif;?>
    <?php if($internalDeliveryLabel!==''):?><div class="field"><b>Entrega interno</b><?= pedido_moderno_h($internalDeliveryLabel) ?></div><?php endif;?>
    <div class="field"><b>Pagamento</b><?= pedido_moderno_h($formaPgtoModern) ?></div>
    <?php if($scheduledModern!==''):?><div class="field"><b>Previsão</b><?= pedido_moderno_h($scheduledModern) ?></div><?php endif;?>
  </div></section>

  <section class="section"><div class="title">Produtos / Serviços</div><table class="items"><thead><tr><th>Código</th><th class="num">Qtd.</th><th class="num">Vl. unit.</th><th class="num">Total</th></tr></thead>
  <?php foreach($itens as $__idx=>$it): $pid=(int)($it['produto_id']??0); $code=$productCodes[$pid]??($pid>0?(string)$pid:''); $name=(string)($it['nome']??('#'.$pid)); $unit=item_unit_price_admin($it); $total=(float)($it['total']??0); $note=(string)($itemNotesModern[$__idx]??''); ?>
    <tbody class="it"><tr class="it-desc"><td colspan="4"><strong><?= pedido_moderno_h($name) ?></strong><?php if($note!==''):?><div class="item-note"><?= nl2br(pedido_moderno_h($note)) ?></div><?php endif;?></td></tr>
    <tr class="it-vals"><td><?= pedido_moderno_h($code) ?></td><td class="num"><?= pedido_moderno_h(pedido_moderno_qty($it['qtd']??0)) ?></td><td class="num"><?= pedido_moderno_h($model==='72mm' ? str_replace('R$ ', '', pedido_moderno_money($unit)) : pedido_moderno_money($unit)) ?></td><td class="num"><strong><?= pedido_moderno_h($model==='72mm' ? str_replace('R$ ', '', pedido_moderno_money($total)) : pedido_moderno_money($total)) ?></strong></td></tr></tbody>
  <?php endforeach; ?>
  </table></section>

  <div class="totals">
    <div><span>Produtos</span><b><?= pedido_moderno_h(pedido_moderno_money($totalProdutos)) ?></b></div>
    <?php if($discountModern>0):?><div><span>Desconto</span><b>- <?= pedido_moderno_h(pedido_moderno_money($discountModern)) ?></b></div><?php endif;?>
    <?php if($additionModern>0):?><div><span>Acréscimo</span><b>+ <?= pedido_moderno_h(pedido_moderno_money($additionModern)) ?></b></div><?php endif;?>
    <?php if($freightModern>0):?><div><span>Frete / entrega</span><b>+ <?= pedido_moderno_h(pedido_moderno_money($freightModern)) ?></b></div><?php endif;?>
    <div class="grand"><span>Total</span><span><?= pedido_moderno_h(pedido_moderno_money($totalGeral)) ?></span></div>
  </div>

  <?php if($obs!==''):?><section class="section"><div class="title">Observações</div><div class="notes"><?= nl2br(pedido_moderno_h($obs)) ?></div></section><?php endif;?>
  <section class="signature"><div class="sig-line"></div><div class="sig-label">Assinatura do cliente</div><?php if($customerNameModern!==''):?><div class="sig-name"><?= pedido_moderno_h($customerNameModern) ?></div><?php endif;?></section>
  <div class="footer">Pedido emitido pelo Nexa Próspero.</div>
</main>
<?php if($autoPrint):?><script>window.addEventListener('load',function(){setTimeout(function(){try{window.print();}catch(e){}},250);});</script><?php endif;?>
<script>
// Voltar: se a impressão abriu numa aba nova, fecha a aba (não deixa outra tela do sistema aberta).
function nexaPrintBack(url){try{if(window.opener||window.history.length<=1){window.close();}}catch(e){}setTimeout(function(){window.location.href=url;},250);}
document.addEventListener('keydown',function(e){if(e.key==='Escape'){e.preventDefault();nexaPrintBack(<?= json_encode($returnTo, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE) ?>);}});
</script>
</body>
</html>
