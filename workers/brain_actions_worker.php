<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/brain.php';

$options = getopt('', ['company::','help']);
if (isset($options['help'])) {
  echo "Nexa Brain Actions Worker\n";
  echo "php workers/brain_actions_worker.php [--company=1]\n";
  exit(0);
}

$companyId = max(0,(int)($options['company'] ?? 0));
$params = [];
$sql = "SELECT id,name FROM companies WHERE COALESCE(status,'active') IN ('active','trial')";
if ($companyId > 0) { $sql .= ' AND id=?'; $params[] = $companyId; }
$sql .= ' ORDER BY id';
$st = $pdo->prepare($sql);
$st->execute($params);
$companies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$companies) {
  fwrite(STDERR,"Nenhuma empresa ativa encontrada.\n");
  exit(1);
}

$exit = 0;
foreach ($companies as $company) {
  $cid = (int)$company['id'];
  try {
    $result = brain_actions_evaluate_company($pdo,$cid,'periodic_worker');
    try { brain_dashboard_rebuild_cache($pdo,$cid); } catch (Throwable $cacheError) {
      $result['errors'][] = 'Cache: ' . $cacheError->getMessage();
    }
    echo '[' . date('Y-m-d H:i:s') . '] Empresa ' . $cid . ' - ' . (string)$company['name']
      . ': regras=' . (int)$result['rules_evaluated']
      . ', detectados=' . (int)$result['alerts_detected']
      . ', criados=' . (int)$result['created']
      . ', atualizados=' . ((int)$result['updated'] + (int)$result['reopened'])
      . ', resolvidos=' . (int)$result['resolved'] . PHP_EOL;
    if (!empty($result['errors'])) {
      fwrite(STDERR,'  Avisos: ' . implode(' | ',(array)$result['errors']) . PHP_EOL);
      $exit = 1;
    }
  } catch (Throwable $e) {
    fwrite(STDERR,'[ERRO] Empresa ' . $cid . ': ' . $e->getMessage() . PHP_EOL);
    $exit = 1;
  }
}
exit($exit);
