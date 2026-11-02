<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__) . '/config/fiscal_document_reports.php';
require_once dirname(__DIR__) . '/config/fiscal_pdf_reports.php';

$accountantId = accountant_require_login();
$companyId = (int)($_GET['company'] ?? $_SESSION['accountant_company_id'] ?? 0);
$allowed = [];
foreach (accounting_linked_companies($pdo, $accountantId) as $linkedCompany) {
  $allowed[(int)$linkedCompany['id']] = $linkedCompany;
}
if ($companyId <= 0 || !isset($allowed[$companyId])) {
  http_response_code(403);
  exit('Você não possui acesso a esta empresa.');
}
$_SESSION['accountant_company_id'] = $companyId;
$company = $allowed[$companyId];

$ini = accounting_validate_date((string)($_GET['ini'] ?? date('Y-m-01')), date('Y-m-01'));
$fim = accounting_validate_date((string)($_GET['fim'] ?? date('Y-m-d')), date('Y-m-d'));
if ($ini > $fim) [$ini, $fim] = [$fim, $ini];
$model = trim((string)($_GET['model'] ?? ''));
if (!in_array($model, ['', '55', '65'], true)) $model = '';
$series = trim((string)($_GET['series'] ?? ''));
$accessKey = accounting_digits((string)($_GET['access_key'] ?? ''));
$number = trim((string)($_GET['number'] ?? ''));
if ($number !== '' && !preg_match('/^\d+$/', $number)) $number = '';
$status = strtoupper(trim((string)($_GET['status'] ?? '')));
$movement = strtoupper(trim((string)($_GET['movement'] ?? 'TODOS')));
if (!isset(accounting_document_movement_options()[$movement])) $movement = 'TODOS';
$format = strtolower(trim((string)($_GET['format'] ?? 'print')));
if (!in_array($format, ['pdf','print'], true)) $format = 'print';

$filters = [
  'ini'=>$ini,
  'fim'=>$fim,
  'model'=>$model,
  'series'=>$series,
  'access_key'=>$accessKey,
  'number'=>$number,
  'status'=>$status,
  'movement'=>$movement,
];

$filterDetailParts = [];
if ($number !== '') $filterDetailParts[] = 'Número: ' . $number;
if ($status !== '') $filterDetailParts[] = 'Status: ' . $status;
if ($accessKey !== '') $filterDetailParts[] = 'Chave contém: ' . $accessKey;
$filterDetailLine = implode(' - ', $filterDetailParts);

try {
  $documents = accounting_export_documents($pdo, $companyId, $filters, 50000);
  $totals = accounting_document_totals($pdo, $companyId, $filters);
  $identity = fiscal_doc_report_identity($pdo, $companyId);
} catch (Throwable $e) {
  http_response_code(500);
  exit(accountant_e($e->getMessage()));
}

if ($format === 'pdf') {
  try {
    $pdf = accounting_pdf_documents_report([
      'identity'=>$identity,
      'ini'=>$ini,
      'fim'=>$fim,
      'model'=>$model,
      'series'=>$series,
      'movement_label'=>accounting_document_movement_label($movement),
      'filter_line'=>$filterDetailLine,
      'documents'=>$documents,
      'totals'=>$totals,
    ]);
    $filename = 'RELATORIO_DOCUMENTOS_FISCAIS_' . str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim) . '.pdf';
    while (ob_get_level() > 0) @ob_end_clean();
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store, max-age=0');
    echo $pdf;
    exit;
  } catch (Throwable $e) {
    http_response_code(500);
    exit(accountant_e($e->getMessage()));
  }
}

$e = static fn($value): string => accountant_e($value);
$money = static fn($value): string => 'R$ ' . number_format((float)$value, 2, ',', '.');
$modelLabel = $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'NF-e e NFC-e');
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Relatório de documentos fiscais</title>
<style>
:root{--p:#0b1f5b;--line:#cbd5e1;--muted:#64748b}*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,sans-serif}.toolbar{position:sticky;top:0;z-index:10;display:flex;justify-content:flex-end;gap:8px;padding:12px;background:#fff;border-bottom:1px solid var(--line)}.btn{border:1px solid var(--line);border-radius:10px;padding:9px 13px;background:#fff;color:var(--p);font-weight:800;text-decoration:none;cursor:pointer}.btn.primary{background:var(--p);color:#fff;border-color:var(--p)}.report{max-width:1180px;margin:18px auto;background:#fff;padding:24px;border:1px solid var(--line);box-shadow:0 8px 30px rgba(15,23,42,.08)}h1{margin:0;text-align:center;color:var(--p);font-size:24px}.company{text-align:center;margin:6px 0 2px;font-weight:700}.filters{text-align:center;color:#475569;font-size:12px;margin-bottom:18px}.summary{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;margin-bottom:14px}.summary div{border:1px solid var(--line);padding:10px;text-align:center}.summary span{display:block;color:var(--muted);font-size:10px;text-transform:uppercase;font-weight:700}.summary strong{display:block;margin-top:4px;font-size:15px}table{width:100%;border-collapse:collapse;font-size:10px}th,td{border:1px solid #94a3b8;padding:5px;vertical-align:top}th{background:#e8eef7;color:#0f255f;text-transform:uppercase;font-size:9px}.right{text-align:right}.center{text-align:center}.key{font-family:monospace;font-size:8px;word-break:break-all}.footer{margin-top:12px;display:flex;justify-content:space-between;color:#64748b;font-size:9px}
@media print{@page{size:A4 landscape;margin:7mm}body{background:#fff}.toolbar{display:none}.report{max-width:none;margin:0;padding:0;border:0;box-shadow:none}.summary{break-inside:avoid}thead{display:table-header-group}tr{break-inside:avoid}.footer{position:fixed;bottom:0;left:0;right:0}}
</style>
</head>
<body>
<div class="toolbar"><a class="btn" href="javascript:window.close()">Fechar</a><button class="btn primary" type="button" onclick="window.print()">Imprimir</button></div>
<main class="report">
  <h1>RELATÓRIO DE DOCUMENTOS FISCAIS</h1>
  <div class="company"><?= $e((string)($identity['name'] ?? $company['name'] ?? 'Empresa')) ?></div>
  <div class="filters">Período: <?= date('d/m/Y',strtotime($ini)) ?> a <?= date('d/m/Y',strtotime($fim)) ?> · Movimento: <?= $e(accounting_document_movement_label($movement)) ?> · Documento: <?= $e($modelLabel) ?> · Série: <?= $e($series !== '' ? $series : 'Todas') ?><?php if ($filterDetailLine !== ''): ?><br><?= $e($filterDetailLine) ?><?php endif; ?></div>
  <section class="summary">
    <div><span>Documentos</span><strong><?= count($documents) ?></strong></div>
    <div><span>Total fiscal válido</span><strong><?= $money($totals['total_document'] ?? 0) ?></strong></div>
    <div><span>ICMS</span><strong><?= $money($totals['total_icms'] ?? 0) ?></strong></div>
    <div><span>PIS</span><strong><?= $money($totals['total_pis'] ?? 0) ?></strong></div>
    <div><span>COFINS</span><strong><?= $money($totals['total_cofins'] ?? 0) ?></strong></div>
  </section>
  <table>
    <thead><tr><th>Data</th><th>Movimento</th><th>Tipo</th><th>Número</th><th>Série</th><th>Participante</th><th>CPF/CNPJ</th><th>Natureza da operação</th><th>Status</th><th>Chave de acesso</th><th class="right">Total</th></tr></thead>
    <tbody>
    <?php if (!$documents): ?><tr><td colspan="11" class="center">Nenhum documento encontrado com os filtros informados.</td></tr><?php endif; ?>
    <?php foreach ($documents as $doc):
      $dt=(string)($doc['issue_datetime'] ?? $doc['created_at'] ?? '');
      $direction=strtoupper((string)($doc['direction'] ?? ''));
      $movementLabel=!empty($doc['_is_devolution']) ? ('Devolução ' . ($direction==='ENTRADA'?'de entrada':'de saída')) : ($direction==='ENTRADA'?'Entrada':'Saída');
    ?>
      <tr>
        <td class="center"><?= $dt!==''?date('d/m/Y',strtotime($dt)):'' ?></td>
        <td><?= $e($movementLabel) ?></td>
        <td class="center"><?= (string)($doc['model'] ?? '')==='55'?'NF-e':'NFC-e' ?></td>
        <td class="right"><strong><?= $e((string)($doc['number'] ?? '')) ?></strong></td>
        <td class="center"><?= $e((string)($doc['series'] ?? '')) ?></td>
        <td><?= $e((string)($doc['recipient_name'] ?? 'CONSUMIDOR FINAL')) ?></td>
        <td><?= $e((string)($doc['recipient_document'] ?? '')) ?></td>
        <td><?= $e((string)($doc['operation_nature'] ?? '')) ?></td>
        <td class="center"><?= $e((string)($doc['status'] ?? '')) ?></td>
        <td class="key"><?= $e((string)($doc['access_key'] ?? '')) ?></td>
        <td class="right"><strong><?= $money($doc['total_document'] ?? 0) ?></strong></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>
  <div class="footer"><span>Relatório gerado pela Central do Contador Próspero.</span><span>Emitido em <?= date('d/m/Y H:i') ?></span></div>
</main>
</body>
</html>
