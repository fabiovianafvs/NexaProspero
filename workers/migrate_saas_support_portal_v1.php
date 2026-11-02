<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente via CLI.\n");
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';

try {
  support_ensure_schema($pdo);
  echo "[OK] Tabela saas_support_users criada/verificada.\n";
  echo "[OK] Portal de consulta do suporte pronto para receber usuários pela Central SaaS.\n";
} catch (Throwable $e) {
  fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
  exit(1);
}
