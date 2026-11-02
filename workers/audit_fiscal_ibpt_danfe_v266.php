<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

$companyId = 0;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
}

try {
  fiscal_nfce_ensure_schema($pdo);
  fiscal_ensure_aux_schema($pdo);
  echo "NEXA FISCAL v2.6.6 — AUDITORIA IBPT / DANFE NFC-e\n";
  echo str_repeat('=', 108) . "\n";

  $where = $companyId > 0 ? 'WHERE c.company_id=' . $companyId : '';
  $sql = "SELECT c.company_id,
                 COALESCE(cp.name, CONCAT('Empresa ', c.company_id)) AS company_name,
                 COALESCE(NULLIF(ts.ibpt_uf,''), c.uf, '') AS ibpt_uf
          FROM fiscal_company_config c
          LEFT JOIN companies cp ON cp.id=c.company_id
          LEFT JOIN fiscal_company_tax_settings ts ON ts.company_id=c.company_id
          {$where}
          ORDER BY c.company_id";
  $companies = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];

  printf("%-6s %-30s %-4s %-13s %-16s %-16s %-16s\n", 'ID', 'Empresa', 'UF', 'IBPT vigente', 'NFC-e recentes', 'Com vTotTrib', 'Sem vTotTrib');
  echo str_repeat('-', 108) . "\n";
  foreach ($companies as $company) {
    $cid = (int)$company['company_id'];
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)$company['ibpt_uf']), 0, 2));
    $rates = 0;
    if ($uf !== '') {
      $st = $pdo->prepare("SELECT COUNT(*) FROM fiscal_ibpt_rates
        WHERE uf=? AND code_type='NCM' AND is_active=1
          AND validity_start<=CURDATE() AND (validity_end IS NULL OR validity_end>=CURDATE())");
      $st->execute([$uf]);
      $rates = (int)$st->fetchColumn();
    }
    $st = $pdo->prepare("SELECT
        COUNT(*) AS total_docs,
        SUM(CASE WHEN COALESCE(xml_raw,'') LIKE '%<vTotTrib>%' THEN 1 ELSE 0 END) AS with_ibpt,
        SUM(CASE WHEN COALESCE(xml_raw,'') NOT LIKE '%<vTotTrib>%' THEN 1 ELSE 0 END) AS without_ibpt
      FROM fiscal_documents
      WHERE company_id=? AND model='65' AND direction='SAIDA'
        AND issue_datetime>=DATE_SUB(NOW(), INTERVAL 30 DAY)");
    $st->execute([$cid]);
    $docs = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    printf("%-6d %-30s %-4s %-13d %-16d %-16d %-16d\n",
      $cid,
      substr((string)$company['company_name'], 0, 30),
      $uf !== '' ? $uf : '—',
      $rates,
      (int)($docs['total_docs'] ?? 0),
      (int)($docs['with_ibpt'] ?? 0),
      (int)($docs['without_ibpt'] ?? 0)
    );
  }

  echo "\nObservações:\n";
  echo "- NFC-e autorizada não deve ter o XML alterado. A coluna 'Sem vTotTrib' inclui documentos antigos.\n";
  echo "- Para novas emissões, confira se a UF da empresa possui tabela IBPT vigente no catálogo global.\n";
  echo "- O DANFE imprime os valores que foram gravados no XML (vTotTrib e infCpl).\n";
  echo "\n[OK] Auditoria concluída.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
