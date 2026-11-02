<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/contracts_module.php';
try {
  contracts_ensure_schema($pdo);
  echo "OK - Contratos atualizado com data do próximo reajuste por item.\n";
} catch (Throwable $e) {
  fwrite(STDERR, 'ERRO: ' . $e->getMessage() . "\n");
  exit(1);
}
