<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

// V11.49: relatório isolado pela empresa operacional do F11.
$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_inutilization_homologation')
  : (int)COMPANY_ID;
$isCrossF11 = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_inutilization_homologation');
$ctxUrl = static function(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$mcToken = trim((string)($_GET['mc_ctx'] ?? ''));

$today = date('Y-m-d');
$ini = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['ini'] ?? '')) ? (string)$_GET['ini'] : date('Y-m-01');
$fim = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['fim'] ?? '')) ? (string)$_GET['fim'] : date('Y-m-t');
$model = in_array((string)($_GET['model'] ?? ''), ['55','65'], true) ? (string)$_GET['model'] : '';
$series = trim((string)($_GET['series'] ?? ''));
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
$err = '';
$rows = [];
try {
  fiscal_inutilization_ensure_schema($pdo);
  $rows = fiscal_inutilization_rows($pdo, $cid, compact('ini','fim','model','series','status','q'), 2000);
} catch (Throwable $e) { $err = $e->getMessage(); }

if (($_GET['export'] ?? '') === 'csv') {
  $filename = 'relatorio-inutilizacoes-' . $ini . '-a-' . $fim . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  echo "\xEF\xBB\xBF";
  $out = fopen('php://output', 'w');
  fputcsv($out, ['Data','Documento','Série','Número inicial','Número final','Ano','Ambiente','Status','Código SEFAZ','Mensagem','Protocolo','Justificativa'], ';');
  foreach ($rows as $row) {
    fputcsv($out, [
      fiscal_date_br(substr((string)($row['event_datetime'] ?? $row['created_at'] ?? ''),0,10)),
      ($row['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e',
      (string)($row['series'] ?? ''),
      (string)($row['start_number'] ?? ''),
      (string)($row['end_number'] ?? ''),
      (string)($row['fiscal_year'] ?? ''),
      (string)($row['environment'] ?? ''),
      (string)($row['status'] ?? ''),
      (string)($row['status_code'] ?? ''),
      (string)($row['status_message'] ?? ''),
      (string)($row['protocol_number'] ?? ''),
      (string)($row['justification'] ?? ''),
    ], ';');
  }
  fclose($out);
  exit;
}

$totalRanges = count($rows);
$totalNumbers = 0;
$homologated = 0;
foreach ($rows as $row) {
  $totalNumbers += max(0, ((int)($row['end_number'] ?? 0) - (int)($row['start_number'] ?? 0)) + 1);
  if (strtoupper((string)($row['status'] ?? '')) === 'HOMOLOGADA') $homologated++;
}
$query = $_GET;
$query['export'] = 'csv';
$csvUrl = $ctxUrl('fiscal_relatorio_inutilizacoes.php?' . http_build_query($query));

fiscal_admin_start('Relatório de Inutilizações', 'fiscal_relatorio_inutilizacoes');
fiscal_page_header('Relatório de Inutilizações', 'Consulte as faixas de NF-e e NFC-e inutilizadas, os retornos da SEFAZ, protocolos e justificativas.', [
  ['label'=>'+ Nova inutilização', 'href'=>$ctxUrl('fiscal_inutilizacao.php'), 'class'=>'btn btn-primary'],
  ['label'=>'Exportar CSV', 'href'=>$csvUrl, 'class'=>'btn'],
  ['label'=>'Documentos fiscais', 'href'=>$ctxUrl('fiscal_documentos.php'), 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_relatorios_documentos');
fiscal_flash();
if ($isCrossF11) echo '<div class="msg ok"><b>V11.49 · Relatório Multiempresa.</b> Os registros abaixo pertencem exclusivamente à empresa selecionada pelo F11.</div>';
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-grid-3">
  <div class="fiscal-kpi"><span>Solicitações encontradas</span><strong><?= (int)$totalRanges ?></strong></div>
  <div class="fiscal-kpi"><span>Números abrangidos</span><strong><?= (int)$totalNumbers ?></strong></div>
  <div class="fiscal-kpi"><span>Homologadas</span><strong><?= (int)$homologated ?></strong></div>
</div>
<div class="fiscal-card" style="margin-top:14px">
  <form method="get" action="fiscal_relatorio_inutilizacoes.php" class="fiscal-form fiscal-form-grid" style="align-items:end">
    <?php if ($mcToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= fiscal_h($mcToken) ?>"><?php endif; ?>
    <div><label>De</label><input type="date" name="ini" value="<?= fiscal_h($ini) ?>"></div>
    <div><label>Até</label><input type="date" name="fim" value="<?= fiscal_h($fim) ?>"></div>
    <div><label>Documento</label><select name="model"><option value="">Todos</option><option value="55" <?= $model==='55'?'selected':'' ?>>NF-e</option><option value="65" <?= $model==='65'?'selected':'' ?>>NFC-e</option></select></div>
    <div><label>Série</label><input name="series" value="<?= fiscal_h($series) ?>" inputmode="numeric" placeholder="Todas"></div>
    <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach (['PENDENTE','HOMOLOGADA','REJEITADA','ERRO'] as $s): ?><option value="<?= $s ?>" <?= $status===$s?'selected':'' ?>><?= $s ?></option><?php endforeach; ?></select></div>
    <div class="span-2"><label>Pesquisar</label><input name="q" value="<?= fiscal_h($q) ?>" placeholder="Número, protocolo, justificativa ou mensagem SEFAZ"></div>
    <div class="fiscal-filter-actions"><button class="btn btn-primary">Pesquisar</button><a class="btn" href="<?= fiscal_h($ctxUrl('fiscal_relatorio_inutilizacoes.php')) ?>">Limpar</a></div>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Inutilizações</h2><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Data</th><th>Documento</th><th>Faixa</th><th>Ano/Ambiente</th><th>Status SEFAZ</th><th>Protocolo</th><th>Justificativa</th></tr></thead><tbody>
    <?php if (!$rows): ?><tr><td colspan="7"><div class="fiscal-empty-state">Nenhuma inutilização encontrada para os filtros informados.</div></td></tr><?php endif; ?>
    <?php foreach ($rows as $row): $rowStatus=strtoupper((string)($row['status'] ?? '')); ?>
      <tr>
        <td data-label="Data"><?= fiscal_h(fiscal_date_br(substr((string)($row['event_datetime'] ?? $row['created_at'] ?? ''),0,10))) ?></td>
        <td data-label="Documento" class="primary"><strong><?= ($row['model'] ?? '')==='55'?'NF-e':'NFC-e' ?></strong><div class="fiscal-muted">Série <?= (int)$row['series'] ?></div></td>
        <td data-label="Faixa"><strong><?= (int)$row['start_number'] ?> a <?= (int)$row['end_number'] ?></strong><div class="fiscal-muted"><?= max(0,((int)$row['end_number']-(int)$row['start_number'])+1) ?> número(s)</div></td>
        <td data-label="Ano/Ambiente"><?= fiscal_h((string)$row['fiscal_year']) ?> · <?= fiscal_h((string)$row['environment']) ?></td>
        <td data-label="Status SEFAZ"><span class="fiscal-badge <?= $rowStatus==='HOMOLOGADA'?'':'off' ?>"><?= fiscal_h($rowStatus ?: '-') ?></span><div class="fiscal-muted" style="margin-top:4px"><?= fiscal_h(trim((string)($row['status_code'] ?? '') . ' - ' . (string)($row['status_message'] ?? ''))) ?></div></td>
        <td data-label="Protocolo"><?= fiscal_h((string)($row['protocol_number'] ?? '-')) ?></td>
        <td data-label="Justificativa"><?= fiscal_h((string)($row['justification'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php fiscal_admin_end(); ?>
