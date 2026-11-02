<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

$companyId = 0;
$documentId = 0;
$documentNumber = 0;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
  if (preg_match('/^--document=(\d+)$/', (string)$arg, $m)) $documentId = (int)$m[1];
  if (preg_match('/^--number=(\d+)$/', (string)$arg, $m)) $documentNumber = (int)$m[1];
}
if ($companyId <= 0 || ($documentId <= 0 && $documentNumber <= 0)) {
  fwrite(STDERR, "Uso: php audit_fiscal_ibpt_document_v267.php --company=1 --document=212\n");
  fwrite(STDERR, " ou: php audit_fiscal_ibpt_document_v267.php --company=1 --number=198\n");
  exit(2);
}

function audit_xml_tag(string $xml, string $tag): string {
  if ($xml === '') return '';
  try {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) return '';
    $xp = new DOMXPath($dom);
    $node = $xp->query('//*[local-name()="' . $tag . '"]')->item(0);
    return $node ? trim((string)$node->textContent) : '';
  } catch (Throwable $e) { return ''; }
}

try {
  fiscal_nfce_ensure_schema($pdo);
  fiscal_ensure_aux_schema($pdo);
  if ($documentId <= 0 && $documentNumber > 0) {
    $find = $pdo->prepare("SELECT id FROM fiscal_documents WHERE company_id=? AND model='65' AND number=? ORDER BY id DESC LIMIT 1");
    $find->execute([$companyId, $documentNumber]);
    $documentId = (int)$find->fetchColumn();
    if ($documentId <= 0) throw new RuntimeException('NFC-e número ' . $documentNumber . ' não encontrada.');
  }
  $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1");
  $st->execute([$companyId, $documentId]);
  $doc = $st->fetch(PDO::FETCH_ASSOC);
  if (!$doc) throw new RuntimeException('Documento não encontrado.');

  $cfg = fiscal_company_config($pdo, $companyId);
  $issueDate = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? date('Y-m-d'));
  $totals = fiscal_nfce_ibpt_document_totals($pdo, $companyId, $documentId, $issueDate, (string)($cfg['uf'] ?? ''));
  $xml = (string)($doc['xml_raw'] ?? '');
  $xmlTotal = audit_xml_tag($xml, 'vTotTrib');
  $infCpl = audit_xml_tag($xml, 'infCpl');

  echo "NEXA FISCAL v2.6.7 - DIAGNOSTICO IBPT DO DOCUMENTO\n";
  echo str_repeat('=', 92) . "\n";
  echo "Empresa: {$companyId}\n";
  echo "Documento: {$documentId} | Modelo: " . ($doc['model'] ?? '') . " | Número: " . ($doc['number'] ?? '') . "\n";
  echo "Emissão: {$issueDate}\n";
  echo "UF IBPT resolvida: " . (($totals['uf'] ?? '') !== '' ? $totals['uf'] : 'NÃO DEFINIDA') . "\n";
  echo "XML possui vTotTrib: " . ($xmlTotal !== '' ? 'SIM (' . $xmlTotal . ')' : 'NÃO') . "\n";
  echo "XML possui texto IBPT no infCpl: " . (stripos($infCpl, 'Tributos aproximados') !== false ? 'SIM' : 'NÃO') . "\n";
  echo "Total armazenado no documento: R$ " . number_format((float)($doc['total_tax_approx'] ?? 0), 2, ',', '.') . "\n\n";

  printf("%-8s %-10s %-9s %-12s %-10s %-10s %-10s %-10s\n", 'Item', 'NCM', 'Catálogo', 'Base', 'Fed.%', 'Est.%', 'Mun.%', 'Total');
  echo str_repeat('-', 92) . "\n";
  foreach ($totals['details'] ?? [] as $d) {
    printf("%-8d %-10s %-9s %-12s %-10s %-10s %-10s %-10s\n",
      (int)($d['item_id'] ?? 0),
      (string)($d['ncm'] ?? '—'),
      !empty($d['matched']) ? 'OK' : 'AUSENTE',
      !empty($d['matched']) ? number_format((float)($d['base'] ?? 0), 2, ',', '.') : '—',
      !empty($d['matched']) ? number_format((float)($d['federal_rate'] ?? 0), 4, ',', '.') : '—',
      !empty($d['matched']) ? number_format((float)($d['state_rate'] ?? 0), 4, ',', '.') : '—',
      !empty($d['matched']) ? number_format((float)($d['municipal_rate'] ?? 0), 4, ',', '.') : '—',
      !empty($d['matched']) ? number_format((float)($d['total'] ?? 0), 2, ',', '.') : '—'
    );
  }

  echo "\nItens com tabela: " . (int)($totals['matched_items'] ?? 0) . "\n";
  echo "Itens sem tabela: " . (int)($totals['missing_items'] ?? 0) . "\n";
  echo "Federal: R$ " . number_format((float)($totals['federal'] ?? 0), 2, ',', '.') . "\n";
  echo "Estadual: R$ " . number_format((float)($totals['state'] ?? 0), 2, ',', '.') . "\n";
  echo "Municipal: R$ " . number_format((float)($totals['municipal'] ?? 0), 2, ',', '.') . "\n";
  echo "Total calculado: R$ " . number_format((float)($totals['total'] ?? 0), 2, ',', '.') . "\n";
  echo "Fonte: " . (($totals['source'] ?? '') !== '' ? $totals['source'] : 'NÃO ENCONTRADA') . "\n";

  if (empty($totals['present'])) {
    echo "\n[ATENÇÃO] Nenhuma alíquota IBPT foi encontrada para os NCMs/UF/data do documento.\n";
  } elseif ($xmlTotal === '') {
    echo "\n[ATENÇÃO] O catálogo permite calcular o IBPT, mas o XML autorizado não contém vTotTrib.\n";
    echo "A impressão v2.6.7 mostrará a informação depois do final do DANFE, sem alterar o XML autorizado.\n";
  } else {
    echo "\n[OK] Documento e catálogo possuem informações IBPT.\n";
  }
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
