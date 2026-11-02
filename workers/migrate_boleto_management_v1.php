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
    $required = [
        'canceled_at',
        'cancellation_reason',
        'cancellation_source',
        'last_due_date_before_change',
        'due_date_updated_at',
        'due_date_updated_by',
    ];
    foreach ($required as $column) {
        $ok = billing_has_column($pdo, 'finance_boleto_charges', $column);
        echo ($ok ? '[OK] ' : '[FALHA] ') . 'finance_boleto_charges.' . $column . PHP_EOL;
        if (!$ok) throw new RuntimeException('Campo não criado: finance_boleto_charges.' . $column);
    }
    echo '[OK] Gestão segura de cancelamento e vencimento de boletos preparada.' . PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
