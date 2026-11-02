<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/orcamento_module.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Nexa Próspero - Migração Orçamento v1.1\n";
echo "Banco: " . (string)(require $root . '/config/db.php')['name'] . "\n";

try {
    orc_ensure_schema($pdo);

    $columns = [
        'product_code_snapshot',
        'gross_total',
        'addition_percent',
        'addition_amount',
        'discount_percent',
        'discount_amount',
        'total',
    ];
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=\'orcamento_items\' AND COLUMN_NAME=?');
    foreach ($columns as $column) {
        $check->execute([$column]);
        if ((int)$check->fetchColumn() !== 1) {
            throw new RuntimeException('Coluna não encontrada após a migração: orcamento_items.' . $column);
        }
        echo "[OK] orcamento_items.{$column}\n";
    }

    echo "[OK] Versão de schema: " . orc_schema_version() . "\n";
    echo "[OK] Orçamentos existentes preservados; o total anterior foi mantido como total líquido e valor bruto inicial.\n";
    echo "Migração concluída com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
