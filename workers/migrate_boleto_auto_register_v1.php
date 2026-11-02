<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
$pdo = require $root . '/config/pdo.php';
require_once $root . '/config/billing_module.php';

try {
    if (!($pdo instanceof PDO)) throw new RuntimeException('Não foi possível inicializar a conexão PDO.');
    billing_ensure_schema($pdo);
    if (!billing_table_exists($pdo, 'finance_payment_methods')) {
        throw new RuntimeException('Tabela finance_payment_methods não encontrada.');
    }
    $ok = billing_has_column($pdo, 'finance_payment_methods', 'auto_register_boleto');
    echo ($ok ? '[OK] ' : '[FALHA] ') . 'finance_payment_methods.auto_register_boleto' . PHP_EOL;
    if (!$ok) throw new RuntimeException('Campo de registro automático de boleto não foi criado.');
    echo '[OK] Registro automático de boletos preparado.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
