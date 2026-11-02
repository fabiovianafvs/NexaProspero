<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
$pdo = require $root . '/config/pdo.php';
require_once $root . '/config/billing_module.php';
require_once $root . '/config/finance_module.php';

try {
    if (!($pdo instanceof PDO)) throw new RuntimeException('Não foi possível inicializar a conexão PDO.');
    billing_ensure_schema($pdo);
    finance_ensure_schema($pdo);
    $ok = billing_has_column($pdo, 'finance_bank_billing_configs', 'itau_bank_fee_chart_account_id');
    echo ($ok ? '[OK] ' : '[FALHA] ') . 'finance_bank_billing_configs.itau_bank_fee_chart_account_id' . PHP_EOL;
    if (!$ok) throw new RuntimeException('Campo de configuração da tarifa bancária não foi criado.');
    echo '[OK] Sincronização de liquidações Itaú preparada.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
