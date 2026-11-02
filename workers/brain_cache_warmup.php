<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/brain.php';
$options = getopt('', ['company::']);
$companyId = (int)($options['company'] ?? 0);
if ($companyId > 0) {
  $data = brain_dashboard_rebuild_cache($pdo, $companyId);
  echo 'Cache atualizado para empresa ' . $companyId . ' em ' . ($data['generatedAt'] ?? date(DATE_ATOM)) . PHP_EOL;
  exit;
}
$rows = $pdo->query('SELECT id FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
foreach ($rows as $id) {
  try {
    $data = brain_dashboard_rebuild_cache($pdo, (int)$id);
    echo '[OK] Empresa ' . (int)$id . ' - ' . ($data['generatedAt'] ?? '') . PHP_EOL;
  } catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] Empresa ' . (int)$id . ' - ' . $e->getMessage() . PHP_EOL);
  }
}
