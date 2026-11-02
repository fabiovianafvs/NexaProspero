<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}

function frd_date(string $value, string $fallback): string {
  $value = trim($value);
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
  return $fallback;
}
function frd_money($value): string { return number_format((float)$value, 2, ',', '.'); }
function frd_qty($value): string { return rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ','); }
function frd_doc_label(string $model): string { return $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'Documento'); }
function frd_status_message(array $doc): string {
  $status = strtoupper(trim((string)($doc['status'] ?? '')));
  $message = trim((string)($doc['sefaz_status_message'] ?? ''));
  if ($status === 'CANCELADA') return 'CANCELADA' . ($message !== '' ? ' - ' . $message : '');
  if ($message !== '') return $message;
  return 'Autorizado o uso da ' . frd_doc_label((string)($doc['model'] ?? ''));
}
function frd_payment_bucket(array $payment): string {
  $type = str_pad(preg_replace('/\D+/', '', (string)($payment['payment_type'] ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
  $desc = function_exists('mb_strtolower') ? mb_strtolower((string)($payment['payment_description'] ?? ''), 'UTF-8') : strtolower((string)($payment['payment_description'] ?? ''));
  $indicator = preg_replace('/\D+/', '', (string)($payment['payment_indicator'] ?? ''));
  if ($type === '01' || strpos($desc, 'dinheiro') !== false) return 'dinheiro';
  if ($type === '17' || strpos($desc, 'pix') !== false) return 'pix';
  if ($type === '02' || strpos($desc, 'cheque') !== false) return 'cheque';
  if (in_array($type, ['03','04'], true) || strpos($desc, 'cart') !== false || strpos($desc, 'crédito') !== false || strpos($desc, 'credito') !== false || strpos($desc, 'débito') !== false || strpos($desc, 'debito') !== false) return 'cartao';
  if ($indicator === '1' || in_array($type, ['05','15'], true) || strpos($desc, 'prazo') !== false || strpos($desc, 'credi') !== false || strpos($desc, 'boleto') !== false || strpos($desc, 'conta') !== false) return 'prazo';
  return 'outros';
}
function frd_allocate_payments(array $groups, array $payments): array {
  if (!$groups) return [];
  $total = 0.0;
  foreach ($groups as $g) $total += max(0.0, (float)($g['total'] ?? 0));
  $keys = array_keys($groups);
  $out = [];
  foreach ($keys as $key) $out[$key] = ['dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0];
  if ($total <= 0) return $out;
  foreach (['dinheiro','pix','cheque','cartao','prazo','outros'] as $bucket) {
    $amount = round((float)($payments[$bucket] ?? 0), 2);
    $allocated = 0.0;
    foreach ($keys as $idx => $key) {
      if ($idx === count($keys) - 1) $part = round($amount - $allocated, 2);
      else {
        $part = round($amount * max(0.0, (float)$groups[$key]['total']) / $total, 2);
        $allocated += $part;
      }
      $out[$key][$bucket] = $part;
    }
  }
  return $out;
}
function frd_add_totals(array &$target, array $source): void {
  foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros','icms_base','icms_amount','st_base','st_amount'] as $field) {
    $target[$field] = (float)($target[$field] ?? 0) + (float)($source[$field] ?? 0);
  }
}

$today = date('Y-m-d');
$ini = frd_date((string)($_GET['ini'] ?? $today), $today);
$fim = frd_date((string)($_GET['fim'] ?? $today), $today);
if ($ini > $fim) { $tmp = $ini; $ini = $fim; $fim = $tmp; }
$report = strtolower((string)($_GET['relatorio'] ?? 'documentos'));
if (!in_array($report, ['documentos','cfop','cfop_pagamento'], true)) $report = 'documentos';
$model = (string)($_GET['tipo'] ?? '');
if (!in_array($model, ['', '55', '65'], true)) $model = '';
$series = trim((string)($_GET['serie'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['situacao'] ?? '')));
if (!in_array($statusFilter, ['', 'AUTORIZADA', 'CANCELADA'], true)) $statusFilter = '';
if ($report !== 'documentos') $statusFilter = 'AUTORIZADA';

$seriesRows = [];
try {
  $stSeries = $pdo->prepare("SELECT DISTINCT series, model FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND model IN ('55','65') AND COALESCE(series,'')<>'' ORDER BY CAST(series AS UNSIGNED), series, model");
  $stSeries->execute([$cid]);
  $seriesRows = $stSeries->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$where = ["fd.company_id=?", "fd.direction='SAIDA'", "fd.model IN ('55','65')", "COALESCE(fd.issue_datetime,fd.created_at)>=?", "COALESCE(fd.issue_datetime,fd.created_at)<?"];
$params = [$cid, $ini . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($fim . ' +1 day'))];
if ($report === 'documentos') {
  if ($statusFilter !== '') { $where[] = "UPPER(COALESCE(fd.status,''))=?"; $params[] = $statusFilter; }
  else $where[] = "UPPER(COALESCE(fd.status,'')) IN ('AUTORIZADA','CANCELADA')";
} else {
  $where[] = "UPPER(COALESCE(fd.status,''))='AUTORIZADA'";
}
if ($model !== '') { $where[] = 'fd.model=?'; $params[] = $model; }
if ($series !== '') { $where[] = 'COALESCE(fd.series,\'\')=?'; $params[] = $series; }

$docs = [];
$loadError = '';
try {
  $sql = "SELECT fd.*,
    (SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_name,
    (SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_document
    FROM fiscal_documents fd WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(fd.issue_datetime,fd.created_at), fd.model, CAST(fd.series AS UNSIGNED), fd.number, fd.id";
  $st = $pdo->prepare($sql); $st->execute($params);
  $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $loadError = $e->getMessage(); }

$docIds = array_values(array_filter(array_map(static fn($d)=>(int)($d['id'] ?? 0), $docs)));
$itemsByDoc = [];
$taxesByItem = [];
$paymentsByDoc = [];
if ($docIds) {
  $ph = implode(',', array_fill(0, count($docIds), '?'));
  try {
    $st = $pdo->prepare("SELECT * FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id IN ($ph) ORDER BY fiscal_document_id,item_number,id");
    $st->execute(array_merge([$cid], $docIds));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $itemsByDoc[(int)$row['fiscal_document_id']][] = $row;
  } catch (Throwable $e) { $loadError = $loadError ?: $e->getMessage(); }
  try {
    $st = $pdo->prepare("SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id IN ($ph) AND UPPER(tax_group) IN ('ICMS','ICMSST') ORDER BY fiscal_document_item_id,id");
    $st->execute(array_merge([$cid], $docIds));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $taxesByItem[(int)$row['fiscal_document_item_id']][] = $row;
  } catch (Throwable $e) {}
  try {
    $st = $pdo->prepare("SELECT * FROM fiscal_document_payments WHERE company_id=? AND fiscal_document_id IN ($ph) ORDER BY fiscal_document_id,id");
    $st->execute(array_merge([$cid], $docIds));
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $docId = (int)$row['fiscal_document_id'];
      $bucket = frd_payment_bucket($row);
      if (!isset($paymentsByDoc[$docId])) $paymentsByDoc[$docId] = ['dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0];
      $paymentsByDoc[$docId][$bucket] += (float)($row['amount'] ?? 0);
    }
  } catch (Throwable $e) {}
}

$docTaxGroups = [];
$docCfopSimple = [];
foreach ($docs as $doc) {
  $docId = (int)$doc['id'];
  $groups = [];
  $simple = [];
  foreach (($itemsByDoc[$docId] ?? []) as $item) {
    $cfop = preg_replace('/\D+/', '', (string)($item['cfop'] ?? '')) ?: 'SEM CFOP';
    $lineTotal = max(0.0, round((float)($item['total_value'] ?? 0) + (float)($item['freight_value'] ?? 0) + (float)($item['insurance_value'] ?? 0) + (float)($item['other_value'] ?? 0) - (float)($item['discount_value'] ?? 0), 2));
    $icms = null; $stTax = null; $stRate = 0.0; $stAmount = 0.0; $stBase = 0.0;
    foreach (($taxesByItem[(int)$item['id']] ?? []) as $tx) {
      $tg = strtoupper((string)($tx['tax_group'] ?? ''));
      if ($tg === 'ICMS' && $icms === null) $icms = $tx;
      if ($tg === 'ICMSST' && $stTax === null) $stTax = $tx;
      $extra = json_decode((string)($tx['extra_json'] ?? ''), true);
      if (is_array($extra)) {
        $stRate = max($stRate, (float)($extra['st_rate'] ?? $extra['pICMSST'] ?? 0));
        $stAmount += (float)($extra['st_amount'] ?? $extra['vICMSST'] ?? 0);
      }
      $stBase += (float)($tx['base_calc_st'] ?? 0);
    }
    if ($stTax) {
      $stRate = max($stRate, (float)($stTax['rate'] ?? 0));
      if ($stAmount == 0.0) $stAmount = (float)($stTax['amount'] ?? 0);
      if ($stBase == 0.0) $stBase = (float)($stTax['base_calc'] ?? 0);
    }
    $cst = trim((string)($icms['cst'] ?? ''));
    $csosn = trim((string)($icms['csosn'] ?? ''));
    $icmsRate = (float)($icms['rate'] ?? 0);
    $groupKey = implode('|', [$cfop,$cst,$csosn,number_format($icmsRate,4,'.',''),number_format($stRate,4,'.','')]);
    if (!isset($groups[$groupKey])) $groups[$groupKey] = [
      'cfop'=>$cfop,'cst'=>$cst,'csosn'=>$csosn,'icms_rate'=>$icmsRate,'st_rate'=>$stRate,
      'total'=>0.0,'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0,
    ];
    $groups[$groupKey]['total'] += $lineTotal;
    $groups[$groupKey]['icms_base'] += (float)($icms['base_calc'] ?? 0);
    $groups[$groupKey]['icms_amount'] += (float)($icms['amount'] ?? 0);
    $groups[$groupKey]['st_base'] += $stBase;
    $groups[$groupKey]['st_amount'] += $stAmount;
    if (!isset($simple[$cfop])) $simple[$cfop] = 0.0;
    $simple[$cfop] += $lineTotal;
  }
  if (!$groups && (float)($doc['total_document'] ?? 0) > 0) {
    $groups['SEM CFOP||||'] = ['cfop'=>'SEM CFOP','cst'=>'','csosn'=>'','icms_rate'=>0.0,'st_rate'=>0.0,'total'=>(float)$doc['total_document'],'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0];
    $simple['SEM CFOP'] = (float)$doc['total_document'];
  }
  $allocation = frd_allocate_payments($groups, $paymentsByDoc[$docId] ?? []);
  foreach ($groups as $key => &$g) {
    foreach (($allocation[$key] ?? []) as $bucket => $value) $g[$bucket] = $value;
  }
  unset($g);
  $docTaxGroups[$docId] = $groups;
  $docCfopSimple[$docId] = $simple;
}

$dayRows = [];
$summaryRows = [];
$dayTotals = [];
$generalTotals = ['total'=>0.0,'dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0,'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0];
foreach ($docs as $doc) {
  $docId = (int)$doc['id'];
  $dateKey = date('Y-m-d', strtotime((string)($doc['issue_datetime'] ?? $doc['created_at'] ?? 'now')));
  $number = (int)($doc['number'] ?? 0);
  foreach (($docTaxGroups[$docId] ?? []) as $key => $g) {
    $rowKey = (string)$doc['model'] . '|' . (string)$doc['series'] . '|' . $key;
    if (!isset($dayRows[$dateKey][$rowKey])) $dayRows[$dateKey][$rowKey] = $g + [
      'model'=>(string)$doc['model'],'series'=>(string)$doc['series'],'number_min'=>$number,'number_max'=>$number,
    ];
    else {
      $dayRows[$dateKey][$rowKey]['number_min'] = min((int)$dayRows[$dateKey][$rowKey]['number_min'], $number);
      $dayRows[$dateKey][$rowKey]['number_max'] = max((int)$dayRows[$dateKey][$rowKey]['number_max'], $number);
      frd_add_totals($dayRows[$dateKey][$rowKey], $g);
    }
    if (!isset($summaryRows[$rowKey])) $summaryRows[$rowKey] = $g + ['model'=>(string)$doc['model'],'series'=>(string)$doc['series']];
    else frd_add_totals($summaryRows[$rowKey], $g);
    if (!isset($dayTotals[$dateKey])) $dayTotals[$dateKey] = ['total'=>0.0,'dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0,'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0];
    frd_add_totals($dayTotals[$dateKey], $g);
    frd_add_totals($generalTotals, $g);
  }
}
ksort($dayRows);
ksort($summaryRows, SORT_NATURAL | SORT_FLAG_CASE);

$cfopGeneral = [];
$totalDocuments = 0.0;
$totalCanceledDocuments = 0.0;
$countAuthorizedDocuments = 0;
$countCanceledDocuments = 0;
foreach ($docs as $doc) {
  $docStatus = strtoupper((string)($doc['status'] ?? ''));
  if ($docStatus === 'CANCELADA') {
    $totalCanceledDocuments += (float)($doc['total_document'] ?? 0);
    $countCanceledDocuments++;
  } else {
    $totalDocuments += (float)($doc['total_document'] ?? 0);
    $countAuthorizedDocuments++;
  }
  foreach (($docCfopSimple[(int)$doc['id']] ?? []) as $cfop => $value) {
    if (!isset($cfopGeneral[$cfop])) $cfopGeneral[$cfop] = 0.0;
    $cfopGeneral[$cfop] += (float)$value;
  }
}
ksort($cfopGeneral, SORT_NATURAL);

$cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $cid) : [];
$companyName = function_exists('fiscal_nfce_company_display_name') ? fiscal_nfce_company_display_name($pdo, $cid, $cfg) : '';
if ($companyName === '') {
  try { $companyName = (string)(finance_query_one($pdo, 'SELECT name FROM companies WHERE id=? LIMIT 1', [$cid])['name'] ?? 'Empresa'); } catch (Throwable $e) { $companyName = 'Empresa'; }
}
$cnpj = fiscal_digits((string)($cfg['company_cnpj'] ?? $cfg['cnpj'] ?? ''));
$ie = (string)($cfg['state_registration'] ?? $cfg['ie'] ?? '');
$city = (string)($cfg['city'] ?? $cfg['municipio'] ?? '');
$uf = (string)($cfg['uf'] ?? '');
$periodLabel = date('d/m/Y', strtotime($ini)) . ' - ' . date('d/m/Y', strtotime($fim));
$typeLabel = $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'NF-e e NFC-e');

fiscal_admin_start('Relatórios de Documentos Fiscais', 'fiscal_relatorios_documentos');
fiscal_page_header('Documentos fiscais por período', 'Relação de documentos autorizados e cancelados. Os agrupamentos fiscais e financeiros consideram somente documentos autorizados.', [
  ['label'=>'Relatório de inutilizações','href'=>'fiscal_relatorio_inutilizacoes.php','class'=>'btn'],
  ['label'=>'Relatórios fiscais','href'=>'fiscal_relatorios.php','class'=>'btn'],
  ['label'=>'Imprimir','href'=>'javascript:window.print()','class'=>'btn btn-primary'],
]);
fiscal_report_tabs('fiscal_relatorios_documentos');
fiscal_flash();
if ($loadError !== '') echo '<div class="msg err">' . fiscal_h($loadError) . '</div>';
?>
<form method="get" class="fiscal-card fiscal-form no-print">
  <div class="frd-filter-grid">
    <div><label>Relatório</label><select name="relatorio">
      <option value="documentos" <?= $report==='documentos'?'selected':'' ?>>Relação de documentos fiscais</option>
      <option value="cfop" <?= $report==='cfop'?'selected':'' ?>>Documentos agrupados por CFOP</option>
      <option value="cfop_pagamento" <?= $report==='cfop_pagamento'?'selected':'' ?>>CFOP e forma de pagamento</option>
    </select></div>
    <div><label>Data inicial</label><input type="date" name="ini" value="<?= fiscal_h($ini) ?>" required></div>
    <div><label>Data final</label><input type="date" name="fim" value="<?= fiscal_h($fim) ?>" required></div>
    <div><label>Tipo</label><select name="tipo">
      <option value="" <?= $model===''?'selected':'' ?>>NF-e e NFC-e</option>
      <option value="65" <?= $model==='65'?'selected':'' ?>>NFC-e</option>
      <option value="55" <?= $model==='55'?'selected':'' ?>>NF-e</option>
    </select></div>
    <div><label>Série</label><select name="serie"><option value="">Todas as séries</option>
      <?php $seenSeries=[]; foreach ($seriesRows as $sr): $sv=(string)($sr['series'] ?? ''); if ($sv==='' || isset($seenSeries[$sv])) continue; $seenSeries[$sv]=true; ?>
        <option value="<?= fiscal_h($sv) ?>" <?= $series===$sv?'selected':'' ?>>Série <?= fiscal_h($sv) ?></option>
      <?php endforeach; ?>
    </select></div>
    <div><label>Situação</label><select name="situacao">
      <option value="" <?= $statusFilter===''?'selected':'' ?>>Autorizadas e canceladas</option>
      <option value="AUTORIZADA" <?= $statusFilter==='AUTORIZADA'?'selected':'' ?>>Somente autorizadas</option>
      <option value="CANCELADA" <?= $statusFilter==='CANCELADA'?'selected':'' ?>>Somente canceladas</option>
    </select></div>
    <div class="fiscal-filter-actions"><button class="btn btn-primary" type="submit">Emitir relatório</button><a class="btn" href="fiscal_relatorio_documentos.php">Hoje</a></div>
  </div>
</form>

<section class="frd-report <?= $report==='cfop_pagamento'?'frd-wide':'' ?>">
  <header class="frd-report-head">
    <div><strong><?= fiscal_h($companyName) ?></strong><span>CNPJ: <?= fiscal_h($cnpj ?: '-') ?> &nbsp; IE: <?= fiscal_h($ie ?: '-') ?></span></div>
    <div class="frd-report-title">
      <?php if ($report === 'documentos'): ?>Relação de Documentos Fiscais (Saídas)
      <?php elseif ($report === 'cfop'): ?>Relação de Documentos Fiscais Agrupados por CFOP (Saídas)
      <?php else: ?>Relatório de <?= fiscal_h($typeLabel) ?> por Período<?php endif; ?>
    </div>
    <div><strong>Período: <?= fiscal_h($periodLabel) ?></strong><span><?= fiscal_h(trim($city . ($uf !== '' ? ' / ' . $uf : ''))) ?></span></div>
  </header>
  <div class="frd-filter-summary">Tipo: <strong><?= fiscal_h($typeLabel) ?></strong> &nbsp; Série: <strong><?= fiscal_h($series !== '' ? $series : 'Todas') ?></strong> &nbsp; Situação: <strong><?= fiscal_h($statusFilter !== '' ? $statusFilter : 'AUTORIZADAS E CANCELADAS') ?></strong> &nbsp; Documentos: <strong><?= count($docs) ?></strong></div>

  <?php if ($report === 'documentos'): ?>
    <table class="frd-table"><thead><tr><th>Data</th><th>Tipo</th><th>Nº documento</th><th>Modelo</th><th>Série</th><th>Cliente</th><th>Situação</th><th class="num">Total NF</th></tr></thead><tbody>
      <?php if (!$docs): ?><tr><td colspan="8" class="empty">Nenhum documento fiscal encontrado.</td></tr><?php endif; ?>
      <?php foreach ($docs as $doc): $isCanceled=strtoupper((string)($doc['status'] ?? ''))==='CANCELADA'; ?><tr class="<?= $isCanceled?'frd-canceled-row':'' ?>">
        <td><?= date('d/m/Y H:i:s', strtotime((string)($doc['issue_datetime'] ?? $doc['created_at']))) ?></td>
        <td><strong><?= fiscal_h(frd_doc_label((string)$doc['model'])) ?></strong></td>
        <td><?= fiscal_h((string)($doc['number'] ?? '')) ?></td><td><?= fiscal_h((string)$doc['model']) ?></td><td><?= fiscal_h((string)($doc['series'] ?? '')) ?></td>
        <td><?= fiscal_h(trim((string)($doc['recipient_name'] ?? '')) ?: 'CONSUMIDOR FINAL') ?></td>
        <td><?= fiscal_h(frd_status_message($doc)) ?></td><td class="num">R$ <?= frd_money($doc['total_document'] ?? 0) ?></td>
      </tr><?php endforeach; ?>
    </tbody><tfoot>
      <tr><th colspan="7" class="num">TOTAL AUTORIZADAS (<?= $countAuthorizedDocuments ?>):</th><th class="num">R$ <?= frd_money($totalDocuments) ?></th></tr>
      <tr><th colspan="7" class="num">TOTAL CANCELADAS — INFORMATIVO (<?= $countCanceledDocuments ?>):</th><th class="num">R$ <?= frd_money($totalCanceledDocuments) ?></th></tr>
    </tfoot></table>

  <?php elseif ($report === 'cfop'): ?>
    <table class="frd-table"><thead><tr><th>Data</th><th>Tipo</th><th>Nº NF</th><th>Modelo</th><th>Série</th><th>Cliente</th><th>Situação</th><th class="num">Total NF</th></tr></thead><tbody>
      <?php if (!$docs): ?><tr><td colspan="8" class="empty">Nenhum documento autorizado encontrado.</td></tr><?php endif; ?>
      <?php foreach ($docs as $doc): ?><tr class="doc-row">
        <td><?= date('d/m/Y H:i:s', strtotime((string)($doc['issue_datetime'] ?? $doc['created_at']))) ?></td><td><strong><?= fiscal_h(frd_doc_label((string)$doc['model'])) ?></strong></td>
        <td><?= fiscal_h((string)($doc['number'] ?? '')) ?></td><td><?= fiscal_h((string)$doc['model']) ?></td><td><?= fiscal_h((string)($doc['series'] ?? '')) ?></td>
        <td><?= fiscal_h(trim((string)($doc['recipient_name'] ?? '')) ?: 'CONSUMIDOR FINAL') ?></td><td><?= fiscal_h(frd_status_message($doc)) ?></td><td class="num"><?= frd_money($doc['total_document'] ?? 0) ?></td>
      </tr>
      <?php foreach (($docCfopSimple[(int)$doc['id']] ?? []) as $cfop => $value): ?><tr class="cfop-row"><td></td><td colspan="2"><strong>CFOP: <?= fiscal_h($cfop) ?></strong></td><td colspan="4">TOTAL:</td><td class="num"><strong><?= frd_money($value) ?></strong></td></tr><?php endforeach; ?>
      <?php endforeach; ?>
    </tbody></table>
    <div class="frd-total-box"><h3>TOTAIS GERAIS</h3><?php foreach ($cfopGeneral as $cfop=>$value): ?><div><span>CFOP: <strong><?= fiscal_h($cfop) ?></strong></span><span>TOTAL: <strong><?= frd_money($value) ?></strong></span></div><?php endforeach; ?><div class="grand"><span>TOTAL DOCUMENTOS:</span><strong>R$ <?= frd_money($totalDocuments) ?></strong></div></div>

  <?php else: ?>
    <?php foreach ($dayRows as $day => $rows): ?>
      <div class="frd-day-title"><?= date('d/m/Y', strtotime($day)) ?></div>
      <table class="frd-table frd-payment-table"><thead><tr><th>Documento</th><th>Série</th><th>CFOP</th><th>CST</th><th>CSOSN</th><th class="num">Total</th><th class="num">Dinheiro</th><th class="num">Pix</th><th class="num">Cheque</th><th class="num">Cartão</th><th class="num">Prazo</th><th class="num">Outros</th><th class="num">% ICMS</th><th class="num">B. ICMS</th><th class="num">Vlr. ICMS</th><th class="num">% ST</th><th class="num">B. ICMS ST</th><th class="num">Vlr. ICMS ST</th></tr></thead><tbody>
        <?php foreach ($rows as $r): $range=(int)$r['number_min']===(int)$r['number_max']?(string)(int)$r['number_min']:(int)$r['number_min'].' - '.(int)$r['number_max']; ?><tr>
          <td><?= fiscal_h(frd_doc_label((string)$r['model']) . ' ' . $range) ?></td><td><?= fiscal_h((string)$r['series']) ?></td><td><?= fiscal_h($r['cfop']) ?></td><td><?= fiscal_h($r['cst']) ?></td><td><?= fiscal_h($r['csosn']) ?></td>
          <?php foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros'] as $f): ?><td class="num"><?= frd_money($r[$f] ?? 0) ?></td><?php endforeach; ?>
          <td class="num"><?= frd_money($r['icms_rate'] ?? 0) ?></td><td class="num"><?= frd_money($r['icms_base'] ?? 0) ?></td><td class="num"><?= frd_money($r['icms_amount'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_rate'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_base'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_amount'] ?? 0) ?></td>
        </tr><?php endforeach; ?>
      </tbody><tfoot><tr><th colspan="5" class="num">Total do Dia ==&gt;&gt;</th><?php foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros'] as $f): ?><th class="num"><?= frd_money($dayTotals[$day][$f] ?? 0) ?></th><?php endforeach; ?><th></th><th class="num"><?= frd_money($dayTotals[$day]['icms_base'] ?? 0) ?></th><th class="num"><?= frd_money($dayTotals[$day]['icms_amount'] ?? 0) ?></th><th></th><th class="num"><?= frd_money($dayTotals[$day]['st_base'] ?? 0) ?></th><th class="num"><?= frd_money($dayTotals[$day]['st_amount'] ?? 0) ?></th></tr></tfoot></table>
    <?php endforeach; ?>
    <?php if (!$dayRows): ?><div class="empty">Nenhum documento autorizado encontrado.</div><?php endif; ?>
    <?php if ($dayRows): ?>
    <table class="frd-table frd-payment-table frd-summary-table"><thead><tr><th colspan="18">Resumo/Total por Período, Tipo, Série e CFOP</th></tr><tr><th>Tipo</th><th>Série</th><th>CFOP</th><th>CST</th><th>CSOSN</th><th class="num">Total</th><th class="num">Dinheiro</th><th class="num">Pix</th><th class="num">Cheque</th><th class="num">Cartão</th><th class="num">Prazo</th><th class="num">Outros</th><th class="num">% ICMS</th><th class="num">B. ICMS</th><th class="num">Vlr. ICMS</th><th class="num">% ST</th><th class="num">B. ICMS ST</th><th class="num">Vlr. ICMS ST</th></tr></thead><tbody>
      <?php foreach ($summaryRows as $r): ?><tr><td><?= fiscal_h(frd_doc_label((string)$r['model'])) ?></td><td><?= fiscal_h((string)$r['series']) ?></td><td><?= fiscal_h($r['cfop']) ?></td><td><?= fiscal_h($r['cst']) ?></td><td><?= fiscal_h($r['csosn']) ?></td><?php foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros'] as $f): ?><td class="num"><?= frd_money($r[$f] ?? 0) ?></td><?php endforeach; ?><td class="num"><?= frd_money($r['icms_rate'] ?? 0) ?></td><td class="num"><?= frd_money($r['icms_base'] ?? 0) ?></td><td class="num"><?= frd_money($r['icms_amount'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_rate'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_base'] ?? 0) ?></td><td class="num"><?= frd_money($r['st_amount'] ?? 0) ?></td></tr><?php endforeach; ?>
    </tbody><tfoot><tr><th colspan="5" class="num">Total Geral ==&gt;&gt;</th><?php foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros'] as $f): ?><th class="num"><?= frd_money($generalTotals[$f] ?? 0) ?></th><?php endforeach; ?><th></th><th class="num"><?= frd_money($generalTotals['icms_base'] ?? 0) ?></th><th class="num"><?= frd_money($generalTotals['icms_amount'] ?? 0) ?></th><th></th><th class="num"><?= frd_money($generalTotals['st_base'] ?? 0) ?></th><th class="num"><?= frd_money($generalTotals['st_amount'] ?? 0) ?></th></tr></tfoot></table>
    <?php endif; ?>
  <?php endif; ?>
  <footer class="frd-footer">Software Próspero - <?= date('d/m/Y H:i:s') ?></footer>
</section>
<style>
.frd-filter-grid{display:grid;grid-template-columns:1.5fr repeat(4,minmax(130px,1fr)) auto;gap:10px;align-items:end}.frd-report{background:#fff;border:1px solid #cbd5e1;border-radius:16px;padding:16px;color:#111;overflow:auto}.frd-report-head{display:grid;grid-template-columns:1fr 1.4fr 1fr;gap:12px;align-items:center;border-top:2px solid #111;border-bottom:2px solid #111;padding:9px 4px}.frd-report-head>div{display:flex;flex-direction:column;gap:3px}.frd-report-head>div:last-child{text-align:right}.frd-report-title{text-align:center;font-size:20px;font-weight:950}.frd-filter-summary{text-align:right;font-size:12px;padding:6px 2px 8px}.frd-table{width:100%;border-collapse:collapse;font-size:12px}.frd-table th,.frd-table td{border:1px solid #777;padding:5px 6px;vertical-align:middle}.frd-table th{background:#e5e7eb;font-weight:950}.frd-table .num{text-align:right;white-space:nowrap}.frd-table .empty,.frd-report>.empty{text-align:center;padding:24px;color:#64748b}.cfop-row td{background:#e5e7eb}.frd-total-box{width:min(480px,100%);margin:10px 0 0 auto}.frd-total-box h3{margin:0;background:#d1d5db;text-align:center;padding:6px}.frd-total-box>div{display:flex;justify-content:space-between;gap:14px;background:#e5e7eb;padding:6px 10px;margin-top:3px}.frd-total-box .grand{background:#fff;font-size:16px}.frd-day-title{font-weight:950;margin:11px 0 4px}.frd-payment-table{min-width:1500px;font-size:10px}.frd-payment-table th,.frd-payment-table td{padding:4px}.frd-summary-table{margin-top:12px}.frd-summary-table thead tr:first-child th{background:#111;color:#fff;text-align:center}.frd-footer{border-bottom:1px solid #111;text-align:right;font-size:9px;font-style:italic;margin-top:28px;padding-bottom:2px}@media(max-width:1150px){.frd-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.frd-filter-actions{grid-column:1/-1}.frd-report-head{grid-template-columns:1fr}.frd-report-head>div,.frd-report-head>div:last-child{text-align:center}}@media print{.admin-topbar,.admin-sidebar,.admin-menu-overlay,.admin-footer,.fiscal-hero,.fiscal-tabs,.no-print{display:none!important}.admin-main,.mg-page,.fiscal-page{margin:0!important;padding:0!important;max-width:none!important}.frd-report{border:0;border-radius:0;padding:0;overflow:visible}.frd-report-head{break-inside:avoid}.frd-table{break-inside:auto}.frd-table tr{break-inside:avoid}.frd-footer{position:relative;margin-top:14px}@page{size:<?= $report==='cfop_pagamento'?'A4 landscape':'A4 portrait' ?>;margin:7mm}.frd-payment-table{min-width:0;font-size:7px}.frd-payment-table th,.frd-payment-table td{padding:2px}.frd-report-title{font-size:16px}.frd-report-head{font-size:10px}.frd-filter-summary{font-size:9px}.frd-table{font-size:9px}}
.frd-canceled-row td{background:#fff1f2!important;color:#991b1b}.frd-canceled-row td strong{color:#991b1b}
</style>
<?php fiscal_admin_end(); ?>
