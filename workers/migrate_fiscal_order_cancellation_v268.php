<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';
require_once $root . '/config/order_cancellation.php';

try {
  fiscal_nfce_ensure_schema($pdo);
  nexa_order_cancellation_ensure_schema($pdo);
  echo "[OK] Estrutura de cancelamento integrado criada/verificada.\n";
  echo "[OK] Colunas de rastreabilidade em pedidos, pagamentos, recebíveis e documentos fiscais verificadas.\n";
  echo "[OK] Nexa Fiscal v2.6.8 concluído.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
