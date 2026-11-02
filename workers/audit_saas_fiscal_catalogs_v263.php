<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_catalog_admin.php';

try {
  fiscal_catalog_admin_ensure_schema($pdo);
  $status = fiscal_catalog_admin_status($pdo);
  echo "NEXA FISCAL v2.6.3 — AUDITORIA DE CATÁLOGOS GLOBAIS\n";
  echo str_repeat('=', 88) . "\n";
  printf("%-24s %12s %-20s %-24s\n", 'Catálogo', 'Ativos', 'Último status', 'Última atualização');
  echo str_repeat('-', 88) . "\n";
  foreach ($status as $item) {
    $last = $item['last'];
    printf(
      "%-24s %12s %-20s %-24s\n",
      substr((string)$item['label'], 0, 24),
      number_format((int)$item['count'], 0, ',', '.'),
      $last ? (string)$last['status'] : 'SEM HISTÓRICO SAAS',
      $last ? (string)($last['finished_at'] ?? $last['created_at'] ?? '—') : '—'
    );
  }

  echo "\nRegistros locais ativos que podem divergir do catálogo global:\n";
  $checks = [
    'fiscal_ncms' => "SELECT COUNT(*) FROM fiscal_ncms WHERE company_id<>0 AND is_active=1",
    'fiscal_cests' => "SELECT COUNT(*) FROM fiscal_cests WHERE company_id<>0 AND is_active=1",
    'fiscal_ibs_cbs_classifications' => "SELECT COUNT(*) FROM fiscal_ibs_cbs_classifications WHERE company_id<>0 AND is_active=1",
    'fiscal_cbs_rates' => "SELECT COUNT(*) FROM fiscal_cbs_rates WHERE company_id<>0 AND is_active=1",
    'fiscal_ibs_uf_rates' => "SELECT COUNT(*) FROM fiscal_ibs_uf_rates WHERE company_id<>0 AND is_active=1",
    'fiscal_ibs_mun_rates' => "SELECT COUNT(*) FROM fiscal_ibs_mun_rates WHERE company_id<>0 AND is_active=1",
  ];
  foreach ($checks as $table => $sql) {
    $count = 0;
    try { $count = (int)$pdo->query($sql)->fetchColumn(); } catch (Throwable $e) {}
    printf("- %-38s %8d\n", $table, $count);
  }

  $errors = (int)$pdo->query("SELECT COUNT(*) FROM saas_fiscal_catalog_jobs WHERE status='ERRO'")->fetchColumn();
  $running = (int)$pdo->query("SELECT COUNT(*) FROM saas_fiscal_catalog_jobs WHERE status='PROCESSANDO' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)")->fetchColumn();
  echo "\nFalhas registradas na Central SaaS: {$errors}\n";
  echo "Processamentos possivelmente interrompidos (>30 min): {$running}\n";
  echo "\n[OK] Auditoria concluída.\n";
  exit(0);
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . "\n");
  exit(1);
}
