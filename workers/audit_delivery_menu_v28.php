<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit;
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/saas_modules.php';

$options = getopt('', ['company::']);
$companyId = max(0, (int)($options['company'] ?? 0));
$ids = $companyId > 0
    ? [$companyId]
    : array_map('intval', $pdo->query('SELECT id FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: []);

echo "Empresa | Delivery | Gestão | Fiscal | Financeiro | Menu Pedidos | Menu Vendas | Consulta em Vendas\n";
echo str_repeat('-', 120) . PHP_EOL;
foreach ($ids as $cid) {
    $delivery = saas_company_has_module($pdo, $cid, 'delivery');
    $gestao = saas_company_has_module($pdo, $cid, 'gestao');
    $fiscal = saas_company_has_module($pdo, $cid, 'fiscal');
    $financeiro = saas_company_has_module($pdo, $cid, 'financeiro');
    echo implode(' | ', [
        (string)$cid,
        $delivery ? 'SIM' : 'NAO',
        $gestao ? 'SIM' : 'NAO',
        $fiscal ? 'SIM' : 'NAO',
        $financeiro ? 'SIM' : 'NAO',
        saas_admin_nav_allowed($pdo, $cid, 'pedidos') ? 'SIM' : 'NAO',
        saas_admin_nav_allowed($pdo, $cid, 'gestao') ? 'SIM' : 'NAO',
        saas_admin_nav_allowed($pdo, $cid, 'gestao_pedidos') ? 'SIM' : 'NAO',
    ]) . PHP_EOL;
}
