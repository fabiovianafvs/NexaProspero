<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/saas_modules.php';
require_once $root . '/config/access_control.php';
require_once $root . '/config/resellers.php';

$errors = [];

try {
    $result = saas_ensure_modules_schema($pdo);
    foreach ((array)($result['errors'] ?? []) as $error) $errors[] = 'SaaS: ' . $error;
    echo '[OK] Estrutura de módulos SaaS verificada.' . PHP_EOL;
} catch (Throwable $e) {
    $errors[] = 'SaaS: ' . $e->getMessage();
}

try {
    $result = access_ensure_schema($pdo);
    foreach ((array)($result['errors'] ?? []) as $error) $errors[] = 'Acesso: ' . $error;
    echo '[OK] Estrutura de perfis e permissões verificada.' . PHP_EOL;
} catch (Throwable $e) {
    $errors[] = 'Acesso: ' . $e->getMessage();
}

try {
    if (function_exists('reseller_ensure_schema')) {
        reseller_ensure_schema($pdo);
        echo '[OK] Estrutura de revendas verificada.' . PHP_EOL;
    }
} catch (Throwable $e) {
    $errors[] = 'Revendas: ' . $e->getMessage();
}

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (
        module_name VARCHAR(80) NOT NULL,
        version_number BIGINT NOT NULL,
        applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        notes VARCHAR(255) NULL,
        PRIMARY KEY (module_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $st = $pdo->prepare("INSERT INTO nexa_schema_versions (module_name,version_number,applied_at,notes)
        VALUES ('runtime_performance',2026072601,NOW(),'Nexa v2.3 - cache de módulos e remoção de DDL por requisição')
        ON DUPLICATE KEY UPDATE version_number=VALUES(version_number),applied_at=NOW(),notes=VALUES(notes)");
    $st->execute();
} catch (Throwable $e) {
    $errors[] = 'Versão: ' . $e->getMessage();
}

if ($errors) {
    fwrite(STDERR, "A migração terminou com avisos/erros:\n- " . implode("\n- ", $errors) . "\n");
    exit(1);
}

echo '[OK] Migração de runtime v2.3 concluída.' . PHP_EOL;
