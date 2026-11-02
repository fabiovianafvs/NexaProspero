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
require_once $root . '/config/os_module.php';

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Nexa Próspero - Migração Ordem de Serviço v1.14\n";
echo "Banco: " . (string)(require $root . '/config/db.php')['name'] . "\n";

try {
    os_ensure_schema($pdo);
    echo "[OK] Tabelas da Ordem de Serviço criadas/verificadas.\n";

    $tables = array(
        'os_settings','os_sequences','os_statuses','os_custom_fields',
        'os_custom_field_options','os_orders','os_order_field_values',
        'os_order_items','os_order_payments','os_status_history','os_vehicle_damage_points'
    );
    $check = $pdo->prepare("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?");
    foreach ($tables as $table) {
        $check->execute(array($table));
        if ((int)$check->fetchColumn() !== 1) {
            throw new RuntimeException('Tabela não encontrada após migração: ' . $table);
        }
        echo "[OK] {$table}\n";
    }

    $columns = array(
        array('os_settings','allow_out_of_stock_reservation'),
        array('os_settings','auto_parts_mode'),
        array('os_order_items','seller_person_id'),
        array('os_order_items','seller_name_snapshot'),
        array('os_order_items','technician_person_id'),
        array('os_order_items','technician_name_snapshot'),
        array('os_orders','contact_name'),
        array('os_orders','contact_phone'),
        array('os_vehicle_damage_points','photo_path'),
        array('os_vehicle_damage_points','photo_created_at'),
        array('os_vehicle_damage_points','photo_deleted_at'),
    );
    $checkCol = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
    foreach ($columns as $col) {
        $checkCol->execute(array($col[0],$col[1]));
        if ((int)$checkCol->fetchColumn() !== 1) throw new RuntimeException('Coluna não encontrada após migração: '.$col[0].'.'.$col[1]);
        echo "[OK] {$col[0]}.{$col[1]}\n";
    }

    $companies = $pdo->query("SELECT id,name FROM companies ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) ?: array();
    $seeded = 0;
    foreach ($companies as $company) {
        $companyId = (int)$company['id'];
        if ($companyId > 0 && os_module_enabled($pdo, $companyId)) {
            os_ensure_defaults($pdo, $companyId);
            $seeded++;
            echo "[OK] Padrões da O.S. preparados para empresa {$companyId} - {$company['name']}\n";
        }
    }

    echo "[OK] Versão de schema: " . os_schema_version() . "\n";
    echo "[OK] Empresas com O.S. preparadas: {$seeded}\n";
    echo "Migração concluída com sucesso.\n";
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
