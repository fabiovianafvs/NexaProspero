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
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Não foi possível inicializar a conexão PDO.');
    }

    billing_ensure_schema($pdo);
    finance_ensure_schema($pdo);

    $checks = [
        ['finance_boleto_charges','paid_amount'],
        ['finance_boleto_charges','payment_date'],
        ['finance_boleto_charges','credit_date'],
        ['finance_boleto_charges','credited_amount'],
        ['finance_boleto_charges','last_webhook_at'],
        ['finance_boleto_charges','last_webhook_payload'],
        ['finance_boleto_webhook_logs','processed'],
        ['finance_boleto_webhook_logs','processing_status'],
        ['finance_boleto_webhook_logs','processing_message'],
        ['finance_boleto_webhook_logs','processed_at'],
    ];

    $failures = 0;
    foreach ($checks as [$table, $column]) {
        $ok = billing_has_column($pdo, $table, $column);
        if (!$ok) {
            $failures++;
        }
        echo ($ok ? '[OK] ' : '[FALHA] ') . $table . '.' . $column . PHP_EOL;
    }

    if ($failures > 0) {
        throw new RuntimeException('A migração terminou com ' . $failures . ' campo(s) ausente(s).');
    }

    echo '[OK] Baixa automática de boletos Itaú preparada.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
