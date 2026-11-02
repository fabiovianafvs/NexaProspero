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

$companyFilter = 0;
foreach ($argv as $arg) {
    if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) {
        $companyFilter = (int)$m[1];
    }
}

$sql = 'SELECT id, name FROM companies';
$params = [];
if ($companyFilter > 0) {
    $sql .= ' WHERE id = ?';
    $params[] = $companyFilter;
}
$sql .= ' ORDER BY id ASC';

$st = $pdo->prepare($sql);
$st->execute($params);
$companies = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$companies) {
    fwrite(STDERR, "Nenhuma empresa encontrada.\n");
    exit(1);
}

echo "NEXA V2.7 - AUDITORIA DE BRAIN, TABELAS E ACESSOS\n";
echo str_repeat('=', 118) . "\n";
printf("%-6s %-28s %-22s %-10s %-44s %-9s\n", 'ID', 'Empresa', 'Plano', 'Pedidos', 'Módulos ativos', 'Liberado');
echo str_repeat('-', 118) . "\n";

foreach ($companies as $company) {
    $companyId = (int)$company['id'];
    $plan = saas_company_latest_plan_info($pdo, $companyId);
    $planName = trim((string)($plan['plan_name'] ?? '')) ?: 'Não identificado';
    $maxOrders = array_key_exists('max_orders', $plan) && $plan['max_orders'] !== null
        ? (string)(int)$plan['max_orders']
        : '-';
    $modules = saas_company_module_codes($pdo, $companyId);
    $allowed = saas_company_advanced_admin_allowed($pdo, $companyId);

    printf(
        "%-6d %-28s %-22s %-10s %-44s %-9s\n",
        $companyId,
        mb_strimwidth((string)$company['name'], 0, 28, '...', 'UTF-8'),
        mb_strimwidth($planName, 0, 22, '...', 'UTF-8'),
        $maxOrders,
        mb_strimwidth(implode(',', $modules) ?: '-', 0, 44, '...', 'UTF-8'),
        $allowed ? 'SIM' : 'NÃO'
    );
}

echo str_repeat('=', 118) . "\n";
echo "Liberado = Brain, menu Tabelas, Usuários e Perfis de acesso.\n";
echo "Regra: Delivery até 100 pedidos = NÃO; Delivery acima de 100 ou módulo empresarial completo = SIM.\n";
echo "[OK] Auditoria concluída.\n";
