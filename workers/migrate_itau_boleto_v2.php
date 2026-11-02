<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/billing_module.php';
try {
    billing_ensure_schema($pdo);
    echo "[OK] Estrutura Itaú Boletos V2 preparada.\n";
    echo "[OK] Campos de certificado/webhook e tabela de logs disponíveis.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
