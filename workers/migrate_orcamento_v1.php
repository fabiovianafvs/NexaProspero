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
require_once $root . '/config/finance_module.php';
require_once $root . '/config/orcamento_module.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Nexa Próspero - Migração Orçamento v1\n";
echo "Banco: " . (string)(require $root . '/config/db.php')['name'] . "\n";

try {
    $saas = saas_ensure_modules_schema($pdo);
    if (empty($saas['ok'])) {
        throw new RuntimeException('Estrutura da Central SaaS não está pronta: ' . implode(' | ', (array)($saas['errors'] ?? [])));
    }
    saas_seed_default_modules($pdo);
    $orcModuleId = saas_module_id_by_code($pdo, 'orcamento');
    if ($orcModuleId <= 0) throw new RuntimeException('Módulo Orçamento não foi inserido na Central SaaS.');
    echo "[OK] Módulo Orçamento disponível na Central SaaS (ID {$orcModuleId}).\n";

    $finance = finance_ensure_schema($pdo);
    if (!empty($finance['errors'])) {
        throw new RuntimeException('Falha ao preparar cadastro compartilhado de Pessoas: ' . implode(' | ', $finance['errors']));
    }
    echo "[OK] Cadastro de Pessoas verificado.\n";

    orc_ensure_schema($pdo);
    echo "[OK] Estrutura do Orçamento criada/verificada.\n";

    $tables = [
        'orcamento_settings','orcamento_sequences','orcamentos',
        'orcamento_items','orcamento_payments','finance_payment_methods','finance_people'
    ];
    $check = $pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
    foreach ($tables as $table) {
        $check->execute([$table]);
        if ((int)$check->fetchColumn() !== 1) throw new RuntimeException('Tabela não encontrada após migração: ' . $table);
        echo "[OK] {$table}\n";
    }

    $columns = [
        ['finance_people','is_professional'],
        ['finance_people','is_carrier'],
        ['orcamento_settings','delivery_enabled'],
        ['orcamento_items','reserve_stock'],
        ['orcamento_items','reserved_quantity'],
        ['orcamentos','professional_person_id'],
        ['orcamentos','carrier_person_id'],
        ['orcamentos','delivery_date'],
        ['orcamentos','delivery_time'],
    ];
    $checkCol = $pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
    foreach ($columns as $col) {
        $checkCol->execute([$col[0], $col[1]]);
        if ((int)$checkCol->fetchColumn() !== 1) throw new RuntimeException('Coluna não encontrada após migração: ' . $col[0] . '.' . $col[1]);
        echo "[OK] {$col[0]}.{$col[1]}\n";
    }

    $companies = $pdo->query('SELECT id,name FROM companies ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $seeded = 0;
    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        if ($companyId > 0 && orc_module_enabled($pdo, $companyId)) {
            orc_ensure_defaults($pdo, $companyId);
            $seeded++;
            echo "[OK] Padrões do Orçamento preparados para empresa {$companyId} - {$company['name']}\n";
        }
    }

    echo "[OK] Versão de schema: " . orc_schema_version() . "\n";
    echo "[OK] Empresas com Orçamento já habilitado: {$seeded}\n";
    echo "Migração concluída com sucesso.\n";
    echo "Próximo passo: habilitar Orçamento na Central SaaS para as empresas desejadas.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
