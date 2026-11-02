<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_catalog_admin.php';

try {
  fiscal_catalog_admin_ensure_schema($pdo, true);
  echo "[OK] Estruturas fiscais globais verificadas.\n";
  echo "[OK] Histórico de atualizações da Central SaaS criado/verificado.\n";
  echo "[OK] Nexa Fiscal v2.6.3 concluído.\n";
  exit(0);
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . "\n");
  exit(1);
}
