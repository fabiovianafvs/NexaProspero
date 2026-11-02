<?php
declare(strict_types=1);
$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$expectedDb = (string)($argv[2] ?? 'nexaprospero');
$errors = [];
foreach ([
  'config/fiscal_module.php', 'admin/produto_form.php', 'admin/produtos.php',
  'admin/fiscal_tributacoes.php', 'admin/pedidos.php', 'admin/pedido_editar.php',
  'config/nfse_emitter.php', 'admin/fiscal_nfse_nacional.php'
] as $rel) {
  $file = $root . '/' . $rel;
  if (!is_file($file)) $errors[] = 'Arquivo ausente: ' . $rel;
}
if ($errors) { foreach ($errors as $e) echo "[ERRO] $e\n"; exit(1); }
$pdo = require $root . '/config/pdo.php';
$db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
echo 'Banco: ' . $db . "\n";
if ($db !== $expectedDb) $errors[] = 'Banco diferente do esperado: ' . $expectedDb;
$checks = [
 ['produtos','is_service'], ['fiscal_tax_profiles','profile_type'], ['fiscal_tax_profiles','nbs_code'],
 ['fiscal_tax_profiles','iss_withheld'], ['fiscal_product_tax','item_type'], ['fiscal_product_tax','nbs_code'],
 ['fiscal_product_tax','iss_rate'], ['fiscal_product_tax','ibs_cbs_cst'],
];
$st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
foreach ($checks as [$table,$column]) {
  $st->execute([$table,$column]);
  $ok=(bool)$st->fetchColumn();
  echo ($ok?'[OK] ':'[ERRO] ') . $table . '.' . $column . "\n";
  if (!$ok) $errors[] = $table . '.' . $column . ' ausente';
}
$serviceCount=(int)$pdo->query('SELECT COUNT(*) FROM produtos WHERE COALESCE(is_service,0)=1')->fetchColumn();
$profileCount=(int)$pdo->query("SELECT COUNT(*) FROM fiscal_tax_profiles WHERE is_active=1 AND UPPER(COALESCE(profile_type,'PRODUCT'))='SERVICE'")->fetchColumn();
echo "Serviços cadastrados: $serviceCount\n";
echo "Regras de serviço ativas: $profileCount\n";
if (!function_exists('fiscal_normalize_item_type')) require_once $root . '/config/fiscal_module.php';
$normalizedService = fiscal_normalize_item_type('Serviço');
echo 'Normalização SERVICE: ' . $normalizedService . "\n";
if ($normalizedService !== 'SERVICE') $errors[] = 'Normalização do tipo Serviço falhou.';
if ($errors) { echo "\nVALIDAÇÃO COM PENDÊNCIAS.\n"; exit(1); }
echo "\nINTERFACE DE SERVIÇOS E INTEGRAÇÃO DE PEDIDOS APROVADAS.\n";
