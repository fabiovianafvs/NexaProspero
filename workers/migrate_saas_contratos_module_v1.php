<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/saas_modules.php';

$pdo = $GLOBALS['pdo'] ?? null;
if (!$pdo instanceof PDO) {
    throw new RuntimeException('Conexão PDO não encontrada.');
}

try {
    // Garante a estrutura SaaS e cadastra o módulo Contratos no catálogo.
    saas_ensure_modules_schema($pdo);
    saas_seed_default_modules($pdo);

    $contractModuleId = saas_module_id_by_code($pdo, 'contratos');
    if ($contractModuleId <= 0) {
        throw new RuntimeException('Não foi possível cadastrar o módulo Contratos.');
    }

    // Preserva empresas que já possuem contratos gravados antes desta migração.
    // Elas passam para modo CUSTOM mantendo todos os módulos atualmente efetivos
    // e acrescentando Contratos. Empresas sem contratos NÃO são habilitadas.
    $migrated = 0;
    $hasContractsTable = false;
    try {
        $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'contracts' LIMIT 1");
        $st->execute();
        $hasContractsTable = (bool)$st->fetchColumn();
    } catch (Throwable $e) {}

    if ($hasContractsTable) {
        $companyIds = $pdo->query('SELECT DISTINCT company_id FROM contracts WHERE company_id > 0 ORDER BY company_id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
        foreach ($companyIds as $rawCompanyId) {
            $companyId = (int)$rawCompanyId;
            if ($companyId <= 0) continue;

            $selectedIds = [];
            foreach (saas_company_module_state($pdo, $companyId) as $module) {
                if (!empty($module['enabled'])) $selectedIds[] = (int)$module['id'];
            }
            $selectedIds[] = $contractModuleId;
            $selectedIds = array_values(array_unique(array_filter($selectedIds, static fn($v) => $v > 0)));

            saas_save_company_modules_custom(
                $pdo,
                $companyId,
                $selectedIds,
                null,
                'Migração automática: empresa já possuía contratos antes da criação do módulo SaaS Contratos.'
            );
            $migrated++;
        }
    }

    echo "OK - Módulo SaaS Contratos instalado. Empresas existentes preservadas: {$migrated}.\n";
    echo "Ordem de Serviço permanece liberada somente quando o módulo ordem_servico estiver efetivamente habilitado.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO - ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
