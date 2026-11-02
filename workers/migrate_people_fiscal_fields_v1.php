<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/finance_module.php';

echo "Nexa Próspero - Migração dados fiscais de Pessoas v1.9.2\n";
$result = finance_ensure_schema($pdo);
$required = ['state_registration','municipal_registration','identity_document','identity_issuer','identity_state'];
$ok = true;
foreach ($required as $col) {
  $exists = finance_column_exists($pdo, 'finance_people', $col);
  echo ($exists ? '[OK] ' : '[ERRO] ') . 'finance_people.' . $col . PHP_EOL;
  if (!$exists) $ok = false;
}
foreach ((array)($result['errors'] ?? []) as $error) echo '[AVISO] ' . $error . PHP_EOL;
if (!$ok) { fwrite(STDERR, "Migração incompleta. Verifique os avisos acima.\n"); exit(1); }
echo "Migração concluída com sucesso.\n";
