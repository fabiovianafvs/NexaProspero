<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

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

/** @return never */
function accountant_xml_error(string $message, int $status = 404): never {
  http_response_code($status);
  header('Content-Type: text/plain; charset=UTF-8');
  echo $message;
  exit;
}

function accountant_xml_filename(array $row, string $fallbackPrefix = 'NFe'): string {
  $key = preg_replace('/\D+/', '', (string)($row['access_key'] ?? '')) ?: '';
  if (strlen($key) === 44) return $key . '-procNFe.xml';
  $model = (string)($row['model'] ?? '55');
  $prefix = $model === '65' ? 'NFCe' : $fallbackPrefix;
  $series = fiscal_xml_export_safe_part((string)($row['series'] ?? ''), '0');
  $number = fiscal_xml_export_safe_part((string)($row['number'] ?? ''), (string)($row['id'] ?? '0'));
  return $prefix . '_serie_' . $series . '_numero_' . $number . '.xml';
}

/** @return never */
function accountant_send_xml(string $filename, string $xml): never {
  if (trim($xml) === '') accountant_xml_error('O XML desta nota não está armazenado no sistema.');
  $filename = fiscal_xml_export_safe_part($filename, 'nota.xml');
  if (!str_ends_with(strtolower($filename), '.xml')) $filename .= '.xml';
  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: application/xml; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Content-Length: ' . (string)strlen($xml));
  header('Cache-Control: private, no-store, max-age=0');
  echo $xml;
  exit;
}

$mode = strtolower(trim((string)($_GET['mode'] ?? 'single')));

if ($mode === 'single') {
  $documentId = (int)($_GET['id'] ?? 0);
  $source = strtolower(trim((string)($_GET['source'] ?? 'document')));
  if ($documentId <= 0) accountant_xml_error('Documento fiscal inválido.', 400);

  if ($source === 'xml') {
    if (!fiscal_table_exists($pdo, 'fiscal_xml_imports')) accountant_xml_error('XML de entrada não encontrado.');
    $st = $pdo->prepare("SELECT id,model,series,number,access_key,xml_filename,xml_content FROM fiscal_xml_imports WHERE company_id=? AND id=? LIMIT 1");
    $st->execute([$companyId, $documentId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) accountant_xml_error('XML de entrada não encontrado.');
    $xml = trim((string)($row['xml_content'] ?? ''));
    $filename = trim((string)($row['xml_filename'] ?? ''));
    if ($filename === '') $filename = accountant_xml_filename($row, 'NFe_Entrada');
    accountant_send_xml($filename, $xml);
  }

  $st = $pdo->prepare("SELECT fd.id,fd.model,fd.series,fd.number,fd.access_key,fd.xml_raw,fd.xml_path FROM fiscal_documents fd WHERE fd.company_id=? AND fd.id=? AND fd.model IN ('55','65') AND "
    . accounting_exclude_locally_discarded_sql('fd') . ' LIMIT 1' );
  $st->execute([$companyId, $documentId]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  if (!$row) accountant_xml_error('Documento fiscal não encontrado.');
  $xml = fiscal_xml_export_document_xml($row);
  accountant_send_xml(accountant_xml_filename($row), $xml);
}

if ($mode !== 'all') accountant_xml_error('Operação de download inválida.', 400);

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
$filters = ['ini'=>$ini,'fim'=>$fim,'model'=>$model,'series'=>$series,'access_key'=>$accessKey,'number'=>$number,'status'=>$status,'movement'=>$movement];

$entries = [];
$matched = 0;
$withoutXml = 0;

$fdParts = accounting_document_search_parts($companyId, $filters, false);
$fdWhere = implode(' AND ', $fdParts['where']);
$st = $pdo->prepare("SELECT fd.id,fd.model,fd.series,fd.number,fd.access_key,fd.direction,fd.xml_raw,fd.xml_path FROM fiscal_documents fd WHERE {$fdWhere} ORDER BY COALESCE(fd.issue_datetime,fd.created_at),fd.id");
$st->execute($fdParts['params']);
foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
  $matched++;
  $xml = fiscal_xml_export_document_xml($row);
  if (trim($xml) === '') { $withoutXml++; continue; }
  $direction = strtoupper((string)($row['direction'] ?? '')) === 'ENTRADA' ? 'Entradas' : 'Saidas';
  $modelDir = (string)($row['model'] ?? '') === '65' ? 'NFCe' : 'NFe';
  fiscal_xml_export_add_entry($entries, $direction . '/' . $modelDir . '/' . accountant_xml_filename($row), $xml);
}

if (fiscal_table_exists($pdo, 'fiscal_xml_imports')) {
  $xmlParts = accounting_xml_document_search_parts($companyId, $filters, false);
  if ($xmlParts['enabled']) {
    $xmlWhere = implode(' AND ', $xmlParts['where']);
    $st = $pdo->prepare("SELECT x.id,x.model,x.series,x.number,x.access_key,x.xml_filename,x.xml_content FROM fiscal_xml_imports x LEFT JOIN fiscal_operations op ON op.company_id=x.company_id AND op.id=x.operation_id WHERE {$xmlWhere} ORDER BY COALESCE(x.entry_date,x.issue_date,DATE(x.created_at)),x.id");
    $st->execute($xmlParts['params']);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $matched++;
      $xml = trim((string)($row['xml_content'] ?? ''));
      if ($xml === '') { $withoutXml++; continue; }
      $modelDir = (string)($row['model'] ?? '') === '65' ? 'NFCe' : 'NFe';
      $filename = trim((string)($row['xml_filename'] ?? ''));
      if ($filename === '') $filename = accountant_xml_filename($row, 'NFe_Entrada');
      fiscal_xml_export_add_entry($entries, 'Entradas/' . $modelDir . '/' . $filename, $xml);
    }
  }
}

if (!$entries) {
  accountant_xml_error($matched > 0
    ? 'Os documentos foram encontrados, mas nenhum deles possui XML armazenado no sistema.'
    : 'Nenhum documento fiscal foi encontrado com os filtros selecionados.');
}

if ($withoutXml > 0) {
  $entries['LEIA-ME.txt'] = "Central do Contador - download de XMLs\r\n"
    . "Período: {$ini} a {$fim}\r\n"
    . "Movimento: " . accounting_document_movement_label($movement) . "\r\n"
    . "Documentos encontrados: {$matched}\r\n"
    . "XMLs incluídos no ZIP: " . count(array_filter(array_keys($entries), static fn(string $n): bool => strtolower(substr($n, -4)) === '.xml')) . "\r\n"
    . "Documentos sem XML armazenado: {$withoutXml}\r\n";
}

$companyName = fiscal_xml_export_safe_part((string)($allowed[$companyId]['display_name'] ?? $allowed[$companyId]['name'] ?? 'empresa'), 'empresa');
$filename = 'XMLs_' . $companyName . '_' . str_replace('-', '', $ini) . '_a_' . str_replace('-', '', $fim) . '.zip';
fiscal_xml_export_download($filename, $entries);
