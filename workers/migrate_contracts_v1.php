<?php
declare(strict_types=1);

// Carrega a conexão PDO conforme o padrão já utilizado pelo Nexa Próspero.
$pdo = require __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/contracts_module.php';

try {
    if (!($pdo instanceof PDO)) {
        throw new RuntimeException('Não foi possível inicializar a conexão PDO.');
    }

    contracts_ensure_schema($pdo);
    echo "OK - Contratos Fase 1 instalado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "ERRO: " . $e->getMessage() . "\n");
    exit(1);
}
