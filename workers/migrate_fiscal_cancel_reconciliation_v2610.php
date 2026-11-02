<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';
require_once $root . '/config/order_cancellation.php';

try {
  fiscal_nfce_ensure_schema($pdo);
  nexa_order_cancellation_ensure_schema($pdo);
  echo "[OK] Estrutura fiscal verificada.\n";
  echo "[OK] Colunas request_xml e response_xml dos eventos verificadas.\n";
  echo "[OK] Estrutura do cancelamento integrado verificada.\n";
  echo "[OK] Nexa Fiscal v2.6.10 concluído.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
