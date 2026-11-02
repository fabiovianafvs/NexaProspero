<?php
/**
 * Nexa Próspero - helpers do módulo Ordem de Serviço.
 * PHP 7.2+
 */

require_once __DIR__ . '/schema_runtime.php';


if (!function_exists('os_db')) {
    function os_db()
    {
        foreach (array('pdo', 'db', 'conn') as $name) {
            if (isset($GLOBALS[$name]) && $GLOBALS[$name] instanceof PDO) {
                return $GLOBALS[$name];
            }
        }
        throw new RuntimeException('Conexão PDO não encontrada. Carregue admin/_bootstrap.php antes de config/os_module.php.');
    }
}

if (!function_exists('os_company_id')) {
    function os_company_id()
    {
        // O Admin do Nexa resolve o tenant em _bootstrap_public.php e define
        // COMPANY_ID. Esta é a fonte principal e deve ter prioridade.
        if (defined('COMPANY_ID') && (int)COMPANY_ID > 0) {
            return (int)COMPANY_ID;
        }

        // Compatibilidade com o mecanismo oficial de autenticação do Nexa.
        if (function_exists('auth_company_id')) {
            $authCompanyId = auth_company_id();
            if ((int)$authCompanyId > 0) {
                return (int)$authCompanyId;
            }
        }

        $candidates = array(
            isset($GLOBALS['company_id']) ? $GLOBALS['company_id'] : null,
            isset($GLOBALS['companyId']) ? $GLOBALS['companyId'] : null,
            isset($_SESSION['user_company_id']) ? $_SESSION['user_company_id'] : null,
            isset($_SESSION['company_id']) ? $_SESSION['company_id'] : null,
            isset($_SESSION['user']['company_id']) ? $_SESSION['user']['company_id'] : null,
            isset($_SESSION['auth']['company_id']) ? $_SESSION['auth']['company_id'] : null,
        );
        foreach ($candidates as $value) {
            if ((int)$value > 0) {
                return (int)$value;
            }
        }
        return 0;
    }
}

if (!function_exists('os_user_id')) {
    function os_user_id()
    {
        $candidates = array(
            isset($GLOBALS['user_id']) ? $GLOBALS['user_id'] : null,
            isset($GLOBALS['userId']) ? $GLOBALS['userId'] : null,
            isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null,
            isset($_SESSION['user']['id']) ? $_SESSION['user']['id'] : null,
            isset($_SESSION['auth']['user_id']) ? $_SESSION['auth']['user_id'] : null,
        );
        foreach ($candidates as $value) {
            if ((int)$value > 0) {
                return (int)$value;
            }
        }
        return null;
    }
}

if (!function_exists('os_e')) {
    function os_e($value)
    {
        return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('os_money')) {
    function os_money($value)
    {
        return 'R$ ' . number_format((float)$value, 2, ',', '.');
    }
}

if (!function_exists('os_decimal')) {
    function os_decimal($value)
    {
        if (is_string($value)) {
            $value = trim($value);
            if (strpos($value, ',') !== false) {
                $value = str_replace('.', '', $value);
                $value = str_replace(',', '.', $value);
            }
        }
        return round((float)$value, 4);
    }
}

if (!function_exists('os_module_enabled')) {
    function os_module_enabled(PDO $pdo, $companyId)
    {
        $companyId = (int)$companyId;
        if ($companyId <= 0) {
            return false;
        }

        // Usa exatamente a mesma regra oficial que o SaaS e o menu do Admin.
        // Assim, plano, personalização manual, expiração e status do módulo
        // são avaliados em um único lugar.
        if (function_exists('saas_company_has_module')) {
            return saas_company_has_module($pdo, $companyId, 'ordem_servico');
        }

        // Fallback defensivo para algum uso isolado fora do bootstrap padrão.
        try {
            $sql = "SELECT 1
                      FROM saas_company_modules scm
                      JOIN saas_modules sm ON sm.id = scm.module_id
                     WHERE scm.company_id = ?
                       AND sm.code = 'ordem_servico'
                       AND sm.is_active = 1
                       AND scm.enabled = 1
                       AND (scm.expires_at IS NULL OR scm.expires_at = '0000-00-00 00:00:00' OR scm.expires_at >= NOW())
                     LIMIT 1";
            $stmt = $pdo->prepare($sql);
            $stmt->execute(array($companyId));
            if ($stmt->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
        }

        return false;
    }
}

if (!function_exists('os_require_enabled')) {
    function os_require_enabled(PDO $pdo, $companyId)
    {
        if (!os_module_enabled($pdo, $companyId)) {
            http_response_code(403);
            echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><title>Ordem de Serviço</title></head><body style="font-family:Arial;padding:32px">';
            echo '<h2>Módulo Ordem de Serviço não habilitado</h2>';
            echo '<p>Habilite o módulo no configurador de módulos/planos do Nexa para liberar o menu, as configurações e as telas de O.S.</p>';
            echo '<p><a href="dashboard.php">Voltar ao painel</a></p></body></html>';
            exit;
        }
    }
}

if (!function_exists('os_slug')) {
    function os_slug($text)
    {
        $text = trim((string)$text);
        if ($text === '') return 'campo';
        $map = array(
            'á'=>'a','à'=>'a','ã'=>'a','â'=>'a','ä'=>'a','Á'=>'a','À'=>'a','Ã'=>'a','Â'=>'a','Ä'=>'a',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'e','È'=>'e','Ê'=>'e','Ë'=>'e',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'i','Ì'=>'i','Î'=>'i','Ï'=>'i',
            'ó'=>'o','ò'=>'o','õ'=>'o','ô'=>'o','ö'=>'o','Ó'=>'o','Ò'=>'o','Õ'=>'o','Ô'=>'o','Ö'=>'o',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'u','Ù'=>'u','Û'=>'u','Ü'=>'u','ç'=>'c','Ç'=>'c','ñ'=>'n','Ñ'=>'n'
        );
        $text = strtr($text, $map);
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', '_', $text);
        $text = trim($text, '_');
        return $text !== '' ? substr($text, 0, 80) : 'campo';
    }
}

if (!function_exists('os_vehicle_semantic_roles')) {
    function os_vehicle_semantic_roles()
    {
        return array(
            '' => 'Campo genérico / não identificado',
            'vehicle_plate' => 'Veículo: Placa',
            'vehicle_name' => 'Veículo: Descrição / Veículo',
            'vehicle_brand' => 'Veículo: Marca',
            'vehicle_model' => 'Veículo: Modelo',
            'vehicle_year' => 'Veículo: Ano / Ano modelo',
            'vehicle_color' => 'Veículo: Cor',
            'vehicle_chassis' => 'Veículo: Chassi',
            'vehicle_renavam' => 'Veículo: Renavam',
            'vehicle_fuel' => 'Veículo: Combustível',
            'vehicle_odometer' => 'Veículo: Quilometragem'
        );
    }
}

if (!function_exists('os_vehicle_normalize_plate')) {
    function os_vehicle_normalize_plate($plate)
    {
        $plate = strtoupper((string)$plate);
        $plate = preg_replace('/[^A-Z0-9]/', '', $plate);
        return substr($plate, 0, 10);
    }
}

if (!function_exists('os_schema_has_table')) {
    function os_schema_has_table(PDO $pdo, $table)
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?");
        $stmt->execute(array((string)$table));
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('os_vehicle_feature_ready')) {
    function os_vehicle_feature_ready(PDO $pdo)
    {
        return os_schema_has_column($pdo, 'os_custom_fields', 'semantic_role') && os_schema_has_table($pdo, 'os_vehicles');
    }
}

if (!function_exists('os_vehicle_profile_find')) {
    function os_vehicle_profile_find(PDO $pdo, $companyId, $plate)
    {
        if (!os_vehicle_feature_ready($pdo)) return null;
        $plate = os_vehicle_normalize_plate($plate);
        if ($plate === '') return null;
        $stmt = $pdo->prepare("SELECT * FROM os_vehicles WHERE company_id=? AND plate=? LIMIT 1");
        $stmt->execute(array((int)$companyId,$plate));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $data = array();
        if (!empty($row['data_json'])) {
            $decoded = json_decode((string)$row['data_json'], true);
            if (is_array($decoded)) $data = $decoded;
        }
        $columnMap = array(
            'vehicle_plate'=>'plate','vehicle_name'=>'vehicle_name','vehicle_brand'=>'brand','vehicle_model'=>'model',
            'vehicle_year'=>'year_model','vehicle_color'=>'color','vehicle_chassis'=>'chassis','vehicle_renavam'=>'renavam',
            'vehicle_fuel'=>'fuel','vehicle_odometer'=>'odometer'
        );
        foreach ($columnMap as $role=>$column) {
            if ((!isset($data[$role]) || trim((string)$data[$role])==='') && isset($row[$column]) && trim((string)$row[$column])!=='') {
                $data[$role] = (string)$row[$column];
            }
        }
        $row['semantic_data'] = $data;
        return $row;
    }
}

if (!function_exists('os_vehicle_profile_save')) {
    function os_vehicle_profile_save(PDO $pdo, $companyId, $orderId, $personId, array $semanticValues)
    {
        if (!os_vehicle_feature_ready($pdo)) return 0;
        $plate = os_vehicle_normalize_plate(isset($semanticValues['vehicle_plate']) ? $semanticValues['vehicle_plate'] : '');
        if ($plate === '') return 0;
        $semanticValues['vehicle_plate'] = $plate;
        $get = function($key) use ($semanticValues) {
            $v = isset($semanticValues[$key]) ? trim((string)$semanticValues[$key]) : '';
            return $v !== '' ? $v : null;
        };
        $json = json_encode($semanticValues, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) $json = null;
        $sql = "INSERT INTO os_vehicles
                (company_id,plate,person_id,vehicle_name,brand,model,year_model,color,chassis,renavam,fuel,odometer,data_json,last_order_id,created_at,updated_at)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())
                ON DUPLICATE KEY UPDATE
                  person_id=COALESCE(VALUES(person_id),person_id),
                  vehicle_name=COALESCE(VALUES(vehicle_name),vehicle_name),
                  brand=COALESCE(VALUES(brand),brand),
                  model=COALESCE(VALUES(model),model),
                  year_model=COALESCE(VALUES(year_model),year_model),
                  color=COALESCE(VALUES(color),color),
                  chassis=COALESCE(VALUES(chassis),chassis),
                  renavam=COALESCE(VALUES(renavam),renavam),
                  fuel=COALESCE(VALUES(fuel),fuel),
                  odometer=COALESCE(VALUES(odometer),odometer),
                  data_json=VALUES(data_json),last_order_id=VALUES(last_order_id),updated_at=NOW()";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array(
            (int)$companyId,$plate,(int)$personId>0?(int)$personId:null,$get('vehicle_name'),$get('vehicle_brand'),$get('vehicle_model'),
            $get('vehicle_year'),$get('vehicle_color'),$get('vehicle_chassis'),$get('vehicle_renavam'),$get('vehicle_fuel'),$get('vehicle_odometer'),
            $json,(int)$orderId>0?(int)$orderId:null
        ));
        $found = os_vehicle_profile_find($pdo, $companyId, $plate);
        return $found ? (int)$found['id'] : 0;
    }
}

if (!function_exists('os_schema_version')) {
    function os_schema_version()
    {
        return 2026081302;
    }
}

if (!function_exists('os_schema_has_column')) {
    function os_schema_has_column(PDO $pdo, $table, $column)
    {
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?");
        $stmt->execute(array((string)$table,(string)$column));
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('os_ensure_schema')) {
    /**
     * Garante a estrutura do módulo de O.S. de forma idempotente.
     * A verificação normal é apenas um SELECT em nexa_schema_versions.
     * DDL só é executada quando a versão ainda não foi aplicada.
     */
    function os_ensure_schema(PDO $pdo)
    {
        static $ready = false;
        if ($ready) return true;

        $version = os_schema_version();
        if (nexa_schema_version_ready($pdo, 'ordem_servico', $version)) {
            $ready = true;
            return true;
        }

        $ddl = array(
            "CREATE TABLE IF NOT EXISTS os_settings (
              company_id INT NOT NULL,
              default_reserve_product TINYINT(1) NOT NULL DEFAULT 0,
              allow_out_of_stock_reservation TINYINT(1) NOT NULL DEFAULT 0,
              allow_payment_plan TINYINT(1) NOT NULL DEFAULT 1,
              auto_parts_mode TINYINT(1) NOT NULL DEFAULT 0,
              default_print_model VARCHAR(20) NOT NULL DEFAULT 'a4_half',
              print_customer_signature TINYINT(1) NOT NULL DEFAULT 1,
              print_responsible_signature TINYINT(1) NOT NULL DEFAULT 1,
              terms_text TEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_sequences (
              company_id INT NOT NULL,
              last_number BIGINT UNSIGNED NOT NULL DEFAULT 0,
              PRIMARY KEY (company_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_statuses (
              id INT NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              name VARCHAR(100) NOT NULL,
              code VARCHAR(50) NOT NULL,
              color VARCHAR(20) NOT NULL DEFAULT '#64748b',
              sort_order INT NOT NULL DEFAULT 0,
              is_initial TINYINT(1) NOT NULL DEFAULT 0,
              is_final TINYINT(1) NOT NULL DEFAULT 0,
              active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_status_company_code (company_id, code),
              KEY idx_os_status_company_active (company_id, active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_custom_fields (
              id INT NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              label VARCHAR(120) NOT NULL,
              field_key VARCHAR(80) NOT NULL,
              input_type VARCHAR(20) NOT NULL DEFAULT 'text',
              semantic_role VARCHAR(40) NULL,
              placeholder VARCHAR(160) NULL,
              required TINYINT(1) NOT NULL DEFAULT 0,
              allow_quick_add TINYINT(1) NOT NULL DEFAULT 0,
              show_on_print TINYINT(1) NOT NULL DEFAULT 1,
              sort_order INT NOT NULL DEFAULT 0,
              active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_custom_field_key (company_id, field_key),
              KEY idx_os_custom_fields_company (company_id, active, sort_order),
              KEY idx_os_custom_fields_semantic (company_id, semantic_role, active)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_custom_field_options (
              id INT NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              field_id INT NOT NULL,
              label VARCHAR(160) NOT NULL,
              option_value VARCHAR(160) NOT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              active TINYINT(1) NOT NULL DEFAULT 1,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_field_option (company_id, field_id, option_value),
              KEY idx_os_field_options (company_id, field_id, active, sort_order)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_vehicles (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              plate VARCHAR(10) NOT NULL,
              person_id INT NULL,
              vehicle_name VARCHAR(160) NULL,
              brand VARCHAR(100) NULL,
              model VARCHAR(120) NULL,
              year_model VARCHAR(20) NULL,
              color VARCHAR(60) NULL,
              chassis VARCHAR(60) NULL,
              renavam VARCHAR(30) NULL,
              fuel VARCHAR(50) NULL,
              odometer VARCHAR(30) NULL,
              data_json LONGTEXT NULL,
              last_order_id BIGINT UNSIGNED NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_vehicle_plate (company_id, plate),
              KEY idx_os_vehicle_person (company_id, person_id),
              KEY idx_os_vehicle_last_order (company_id, last_order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_orders (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              os_number BIGINT UNSIGNED NOT NULL,
              person_id INT NULL,
              person_name_snapshot VARCHAR(180) NULL,
              person_document_snapshot VARCHAR(30) NULL,
              person_phone_snapshot VARCHAR(40) NULL,
              contact_name VARCHAR(180) NULL,
              contact_phone VARCHAR(40) NULL,
              status_id INT NULL,
              opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              expected_at DATETIME NULL,
              closed_at DATETIME NULL,
              title VARCHAR(180) NULL,
              reported_problem TEXT NULL,
              diagnosis TEXT NULL,
              technical_notes TEXT NULL,
              customer_notes TEXT NULL,
              total_products DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              total_services DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              addition_amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              total_general DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              billing_status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
              pedido_id BIGINT UNSIGNED NULL,
              requires_product_fiscal TINYINT(1) NOT NULL DEFAULT 0,
              requires_service_fiscal TINYINT(1) NOT NULL DEFAULT 0,
              product_document_type VARCHAR(10) NULL,
              service_document_type VARCHAR(10) NULL,
              billed_at DATETIME NULL,
              created_by INT NULL,
              updated_by INT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_company_number (company_id, os_number),
              KEY idx_os_company_status_opened (company_id, status_id, opened_at, id),
              KEY idx_os_company_person (company_id, person_id, opened_at),
              KEY idx_os_company_billing (company_id, billing_status, id),
              KEY idx_os_pedido (company_id, pedido_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_order_field_values (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              field_id INT NOT NULL,
              value_text LONGTEXT NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              UNIQUE KEY uk_os_order_field (company_id, order_id, field_id),
              KEY idx_os_order_field_order (company_id, order_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_vehicle_damage_points (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              sort_order INT NOT NULL DEFAULT 0,
              position_x DECIMAL(7,4) NOT NULL,
              position_y DECIMAL(7,4) NOT NULL,
              damage_type VARCHAR(30) NOT NULL DEFAULT 'AVARIA',
              description VARCHAR(255) NULL,
              photo_path VARCHAR(255) NULL,
              photo_created_at DATETIME NULL,
              photo_deleted_at DATETIME NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_os_vehicle_damage_order (company_id, order_id, sort_order, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_order_items (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              produto_id INT NOT NULL,
              item_type VARCHAR(20) NOT NULL DEFAULT 'PRODUTO',
              description_snapshot VARCHAR(180) NOT NULL,
              unidade_snapshot VARCHAR(20) NULL,
              quantity DECIMAL(15,4) NOT NULL DEFAULT 1.0000,
              unit_price DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
              total DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              reserve_stock TINYINT(1) NOT NULL DEFAULT 0,
              reserved_quantity DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
              reservation_status VARCHAR(20) NOT NULL DEFAULT 'NAO_RESERVADO',
              seller_person_id INT NULL,
              seller_name_snapshot VARCHAR(180) NULL,
              technician_person_id INT NULL,
              technician_name_snapshot VARCHAR(180) NULL,
              notes VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_os_items_order (company_id, order_id, item_type, id),
              KEY idx_os_items_product (company_id, produto_id, reservation_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_order_payments (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              payment_method_id INT NULL,
              payment_label VARCHAR(120) NULL,
              amount DECIMAL(15,2) NOT NULL DEFAULT 0.00,
              due_date DATE NULL,
              notes VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at DATETIME NULL,
              PRIMARY KEY (id),
              KEY idx_os_payments_order (company_id, order_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

            "CREATE TABLE IF NOT EXISTS os_status_history (
              id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
              company_id INT NOT NULL,
              order_id BIGINT UNSIGNED NOT NULL,
              from_status_id INT NULL,
              to_status_id INT NULL,
              user_id INT NULL,
              notes VARCHAR(255) NULL,
              created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
              PRIMARY KEY (id),
              KEY idx_os_status_history_order (company_id, order_id, id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
        );

        try {
            foreach ($ddl as $sql) {
                $pdo->exec($sql);
            }

            // Migrações incrementais v1.6/v1.7: estoque e responsável único Vendedor/Técnico.
            if (!os_schema_has_column($pdo, 'os_settings', 'allow_out_of_stock_reservation')) {
                $pdo->exec("ALTER TABLE os_settings ADD COLUMN allow_out_of_stock_reservation TINYINT(1) NOT NULL DEFAULT 0 AFTER default_reserve_product");
            }
            // v1.13: modo Auto Peças / Oficina com mapa de avarias do veículo.
            if (!os_schema_has_column($pdo, 'os_settings', 'auto_parts_mode')) {
                $pdo->exec("ALTER TABLE os_settings ADD COLUMN auto_parts_mode TINYINT(1) NOT NULL DEFAULT 0 AFTER allow_payment_plan");
            }
            // v1.14: fotos opcionais por ponto de avaria. Os arquivos ficam no storage;
            // o banco guarda somente a referência para evitar sobrecarga.
            if (!os_schema_has_column($pdo, 'os_vehicle_damage_points', 'photo_path')) {
                $pdo->exec("ALTER TABLE os_vehicle_damage_points ADD COLUMN photo_path VARCHAR(255) NULL AFTER description");
            }
            if (!os_schema_has_column($pdo, 'os_vehicle_damage_points', 'photo_created_at')) {
                $pdo->exec("ALTER TABLE os_vehicle_damage_points ADD COLUMN photo_created_at DATETIME NULL AFTER photo_path");
            }
            if (!os_schema_has_column($pdo, 'os_vehicle_damage_points', 'photo_deleted_at')) {
                $pdo->exec("ALTER TABLE os_vehicle_damage_points ADD COLUMN photo_deleted_at DATETIME NULL AFTER photo_created_at");
            }
            if (!os_schema_has_column($pdo, 'os_order_items', 'seller_person_id')) {
                $pdo->exec("ALTER TABLE os_order_items ADD COLUMN seller_person_id INT NULL AFTER reservation_status");
            }
            if (!os_schema_has_column($pdo, 'os_order_items', 'seller_name_snapshot')) {
                $pdo->exec("ALTER TABLE os_order_items ADD COLUMN seller_name_snapshot VARCHAR(180) NULL AFTER seller_person_id");
            }
            if (!os_schema_has_column($pdo, 'os_order_items', 'technician_person_id')) {
                $pdo->exec("ALTER TABLE os_order_items ADD COLUMN technician_person_id INT NULL AFTER seller_name_snapshot");
            }
            if (!os_schema_has_column($pdo, 'os_order_items', 'technician_name_snapshot')) {
                $pdo->exec("ALTER TABLE os_order_items ADD COLUMN technician_name_snapshot VARCHAR(180) NULL AFTER technician_person_id");
            }
            // v1.10: contato operacional da O.S. separado do cadastro fiscal do cliente.
            // Mantém title por compatibilidade/histórico, mas a interface deixa de usá-lo.
            if (!os_schema_has_column($pdo, 'os_orders', 'contact_name')) {
                $pdo->exec("ALTER TABLE os_orders ADD COLUMN contact_name VARCHAR(180) NULL AFTER person_phone_snapshot");
            }
            if (!os_schema_has_column($pdo, 'os_orders', 'contact_phone')) {
                $pdo->exec("ALTER TABLE os_orders ADD COLUMN contact_phone VARCHAR(40) NULL AFTER contact_name");
            }

            // v1.7: o cadastro passa a usar um único responsável por item.
            // Para preservar históricos da v1.6, migra Técnico para Vendedor/Técnico
            // somente quando ainda não houver vendedor informado. As colunas antigas
            // permanecem por compatibilidade e deixam de ser preenchidas em novos lançamentos.
            $pdo->exec("UPDATE os_order_items
                          SET seller_person_id = COALESCE(seller_person_id, technician_person_id),
                              seller_name_snapshot = COALESCE(NULLIF(seller_name_snapshot,''), technician_name_snapshot)
                        WHERE (seller_person_id IS NULL OR seller_person_id = 0 OR seller_name_snapshot IS NULL OR seller_name_snapshot = '')
                          AND (technician_person_id IS NOT NULL OR technician_name_snapshot IS NOT NULL)");

            nexa_schema_version_mark(
                $pdo,
                'ordem_servico',
                $version,
                'Ordem de Serviço v1.14 - Fotos das avarias e limpeza automática após 48 horas da conclusão'
            );
            if (!nexa_schema_version_ready($pdo, 'ordem_servico', $version)) {
                throw new RuntimeException('A estrutura foi criada, mas a versão da O.S. não pôde ser registrada.');
            }
            $ready = true;
            return true;
        } catch (Throwable $e) {
            error_log('[Nexa O.S.] Falha ao instalar/verificar schema: ' . $e->getMessage());
            throw new RuntimeException('Não foi possível preparar o banco da Ordem de Serviço: ' . $e->getMessage(), 0, $e);
        }
    }
}

if (!function_exists('os_ensure_defaults')) {
    function os_ensure_defaults(PDO $pdo, $companyId)
    {
        os_ensure_schema($pdo);
        $companyId = (int)$companyId;
        $stmt = $pdo->prepare("INSERT IGNORE INTO os_settings (company_id) VALUES (?)");
        $stmt->execute(array($companyId));
        $stmt = $pdo->prepare("INSERT IGNORE INTO os_sequences (company_id, last_number) VALUES (?,0)");
        $stmt->execute(array($companyId));

        $stmt = $pdo->prepare("SELECT COUNT(*) FROM os_statuses WHERE company_id = ?");
        $stmt->execute(array($companyId));
        if ((int)$stmt->fetchColumn() === 0) {
            $rows = array(
                array('ABERTA','Aberta','#0ea5e9',10,1,0),
                array('EM_ANALISE','Em análise','#8b5cf6',20,0,0),
                array('EM_EXECUCAO','Em execução','#f59e0b',30,0,0),
                array('FINALIZADA','Finalizada','#16a34a',40,0,1),
                array('CANCELADA','Cancelada','#dc2626',90,0,1),
            );
            $ins = $pdo->prepare("INSERT INTO os_statuses (company_id, code, name, color, sort_order, is_initial, is_final) VALUES (?,?,?,?,?,?,?)");
            foreach ($rows as $r) {
                $ins->execute(array($companyId,$r[0],$r[1],$r[2],$r[3],$r[4],$r[5]));
            }
        }
    }
}

if (!function_exists('os_next_number')) {
    function os_next_number(PDO $pdo, $companyId)
    {
        $companyId = (int)$companyId;
        $stmt = $pdo->prepare("INSERT IGNORE INTO os_sequences (company_id,last_number) VALUES (?,0)");
        $stmt->execute(array($companyId));
        $stmt = $pdo->prepare("SELECT last_number FROM os_sequences WHERE company_id = ? FOR UPDATE");
        $stmt->execute(array($companyId));
        $next = ((int)$stmt->fetchColumn()) + 1;
        $stmt = $pdo->prepare("UPDATE os_sequences SET last_number = ? WHERE company_id = ?");
        $stmt->execute(array($next,$companyId));
        return $next;
    }
}

if (!function_exists('os_initial_status_id')) {
    function os_initial_status_id(PDO $pdo, $companyId)
    {
        $stmt = $pdo->prepare("SELECT id FROM os_statuses WHERE company_id=? AND active=1 ORDER BY is_initial DESC, sort_order ASC, id ASC LIMIT 1");
        $stmt->execute(array((int)$companyId));
        return (int)$stmt->fetchColumn();
    }
}

if (!function_exists('os_recalculate_totals')) {
    function os_recalculate_totals(PDO $pdo, $companyId, $orderId)
    {
        $stmt = $pdo->prepare("SELECT
              COALESCE(SUM(CASE WHEN item_type='PRODUTO' THEN total ELSE 0 END),0) total_products,
              COALESCE(SUM(CASE WHEN item_type='SERVICO' THEN total ELSE 0 END),0) total_services,
              SUM(CASE WHEN item_type='PRODUTO' THEN 1 ELSE 0 END) product_count,
              SUM(CASE WHEN item_type='SERVICO' THEN 1 ELSE 0 END) service_count
            FROM os_order_items WHERE company_id=? AND order_id=?");
        $stmt->execute(array((int)$companyId,(int)$orderId));
        $tot = $stmt->fetch(PDO::FETCH_ASSOC);

        $stmt = $pdo->prepare("SELECT discount_amount, addition_amount FROM os_orders WHERE company_id=? AND id=? LIMIT 1");
        $stmt->execute(array((int)$companyId,(int)$orderId));
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        $discount = $order ? (float)$order['discount_amount'] : 0;
        $addition = $order ? (float)$order['addition_amount'] : 0;
        $products = (float)$tot['total_products'];
        $services = (float)$tot['total_services'];
        $general = max(0, $products + $services - $discount + $addition);

        $stmt = $pdo->prepare("UPDATE os_orders SET total_products=?, total_services=?, total_general=?, requires_product_fiscal=?, requires_service_fiscal=?, updated_at=NOW() WHERE company_id=? AND id=?");
        $stmt->execute(array(
            $products,$services,$general,
            ((int)$tot['product_count'] > 0 ? 1 : 0),
            ((int)$tot['service_count'] > 0 ? 1 : 0),
            (int)$companyId,(int)$orderId
        ));
        return array('products'=>$products,'services'=>$services,'general'=>$general);
    }
}

if (!function_exists('os_reserve_product')) {
    function os_reserve_product(PDO $pdo, $companyId, $productId, $quantity, $allowOutOfStock = false)
    {
        $quantity = os_decimal($quantity);
        if ($quantity <= 0) return;
        $stmt = $pdo->prepare("SELECT estoque_atual, estoque_reservado, is_service FROM produtos WHERE company_id=? AND id=? FOR UPDATE");
        $stmt->execute(array((int)$companyId,(int)$productId));
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Produto não encontrado.');
        if ((int)$p['is_service'] === 1) throw new RuntimeException('Serviços não podem reservar estoque.');
        $available = (float)$p['estoque_atual'] - (float)$p['estoque_reservado'];
        if (!$allowOutOfStock && $quantity > $available + 0.00001) {
            throw new RuntimeException('Estoque disponível insuficiente para reservar. Disponível: ' . number_format($available, 4, ',', '.') . '. Para permitir a reserva, habilite esta opção nas Configurações da O.S.');
        }
        $stmt = $pdo->prepare("UPDATE produtos SET estoque_reservado = estoque_reservado + ? WHERE company_id=? AND id=?");
        $stmt->execute(array($quantity,(int)$companyId,(int)$productId));
    }
}

if (!function_exists('os_unreserve_product')) {
    function os_unreserve_product(PDO $pdo, $companyId, $productId, $quantity)
    {
        $quantity = os_decimal($quantity);
        if ($quantity <= 0) return;
        $stmt = $pdo->prepare("UPDATE produtos SET estoque_reservado = GREATEST(0, estoque_reservado - ?) WHERE company_id=? AND id=?");
        $stmt->execute(array($quantity,(int)$companyId,(int)$productId));
    }
}

if (!function_exists('os_release_order_reservations')) {
    function os_release_order_reservations(PDO $pdo, $companyId, $orderId)
    {
        $stmt = $pdo->prepare("SELECT id, produto_id, reserved_quantity FROM os_order_items WHERE company_id=? AND order_id=? AND reservation_status='RESERVADO' AND reserved_quantity>0 FOR UPDATE");
        $stmt->execute(array((int)$companyId,(int)$orderId));
        $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($items as $item) {
            os_unreserve_product($pdo, $companyId, $item['produto_id'], $item['reserved_quantity']);
            $u = $pdo->prepare("UPDATE os_order_items SET reserved_quantity=0, reservation_status='LIBERADO', updated_at=NOW() WHERE company_id=? AND id=?");
            $u->execute(array((int)$companyId,(int)$item['id']));
        }
    }
}

if (!function_exists('os_mark_billed_by_pedido')) {
    function os_mark_billed_by_pedido(PDO $pdo, $companyId, $pedidoId)
    {
        $pdo->beginTransaction();
        try {
            $stmt = $pdo->prepare("SELECT id FROM os_orders WHERE company_id=? AND pedido_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute(array((int)$companyId,(int)$pedidoId));
            $orderId = (int)$stmt->fetchColumn();
            if ($orderId > 0) {
                os_release_order_reservations($pdo, $companyId, $orderId);
                $u = $pdo->prepare("UPDATE os_orders SET billing_status='FATURADA', billed_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?");
                $u->execute(array((int)$companyId,$orderId));
            }
            $pdo->commit();
            return $orderId;
        } catch (Exception $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
    }
}

if (!function_exists('os_build_order_snapshot')) {
    function os_build_order_snapshot(PDO $pdo, $companyId, $personId)
    {
        if ((int)$personId <= 0) return array(null,null,null,null);
        $stmt = $pdo->prepare("SELECT id,name,document,COALESCE(NULLIF(whatsapp,''),phone) AS phone FROM finance_people WHERE company_id=? AND id=? LIMIT 1");
        $stmt->execute(array((int)$companyId,(int)$personId));
        $p = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$p) throw new RuntimeException('Pessoa/cliente não encontrado.');
        return array((int)$p['id'],$p['name'],$p['document'],$p['phone']);
    }
}


if (!function_exists('os_item_responsible_snapshot')) {
    /**
     * Retorna o responsável único do item. Para a O.S., Vendedor/Técnico é uma
     * única pessoa, escolhida entre vendedores ou colaboradores ativos.
     */
    function os_item_responsible_snapshot(PDO $pdo, $companyId, $personId)
    {
        if ((int)$personId <= 0) return array(null, null);
        $stmt = $pdo->prepare("SELECT id,name FROM finance_people
                              WHERE company_id=? AND id=? AND active=1
                                AND (COALESCE(is_seller,0)=1 OR COALESCE(is_employee,0)=1)
                              LIMIT 1");
        $stmt->execute(array((int)$companyId,(int)$personId));
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) throw new RuntimeException('Vendedor/Técnico inválido ou inativo.');
        return array((int)$row['id'], $row['name']);
    }
}

if (!function_exists('os_item_responsible_people')) {
    function os_item_responsible_people(PDO $pdo, $companyId)
    {
        $stmt = $pdo->prepare("SELECT id,name FROM finance_people
                              WHERE company_id=? AND active=1
                                AND (COALESCE(is_seller,0)=1 OR COALESCE(is_employee,0)=1)
                              ORDER BY name,id");
        $stmt->execute(array((int)$companyId));
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

if (!function_exists('os_item_responsible_id')) {
    function os_item_responsible_id(array $item)
    {
        $seller = isset($item['seller_person_id']) ? (int)$item['seller_person_id'] : 0;
        if ($seller > 0) return $seller;
        return isset($item['technician_person_id']) ? (int)$item['technician_person_id'] : 0;
    }
}

if (!function_exists('os_item_responsible_name')) {
    function os_item_responsible_name(array $item)
    {
        $seller = isset($item['seller_name_snapshot']) ? trim((string)$item['seller_name_snapshot']) : '';
        if ($seller !== '') return $seller;
        return isset($item['technician_name_snapshot']) ? trim((string)$item['technician_name_snapshot']) : '';
    }
}


if (!function_exists('os_vehicle_damage_profiles')) {
    function os_vehicle_damage_profiles()
    {
        return array(
            'none' => array(
                'label' => 'Desabilitado',
                'title' => 'Mapa de avarias / inspeção',
                'alt'   => 'Mapa de avarias desabilitado',
                'asset' => 'os_vehicle_damage_map.png'
            ),
            'car' => array(
                'label' => 'Auto Peças / Oficina (Carro)',
                'title' => 'Mapa de avarias do veículo',
                'alt'   => 'Vistas do carro para marcação de avarias',
                'asset' => 'os_vehicle_damage_map.png'
            ),
            'motorcycle' => array(
                'label' => 'Moto Peças / Oficina (Moto)',
                'title' => 'Mapa de avarias da motocicleta',
                'alt'   => 'Vistas da motocicleta para marcação de avarias',
                'asset' => 'os_vehicle_damage_map_motorcycle.png'
            ),
            'computer' => array(
                'label' => 'Assistência Técnica de Informática (Computador)',
                'title' => 'Mapa de avarias do equipamento',
                'alt'   => 'Vistas do computador para marcação de avarias',
                'asset' => 'os_vehicle_damage_map_computer.png'
            ),
        );
    }
}

if (!function_exists('os_vehicle_damage_mode_normalize')) {
    function os_vehicle_damage_mode_normalize($value)
    {
        $value = strtolower(trim((string)$value));
        if ($value === 'moto') $value = 'motorcycle';
        if ($value === 'pc' || $value === 'notebook' || $value === 'informatica') $value = 'computer';
        if (preg_match('/^custom:[0-9]+$/', $value)) return $value;
        $profiles = os_vehicle_damage_profiles();
        return isset($profiles[$value]) ? $value : 'none';
    }
}

if (!function_exists('os_vehicle_damage_mode_from_settings')) {
    function os_vehicle_damage_mode_from_settings(array $settings)
    {
        $mode = os_vehicle_damage_mode_normalize(isset($settings['damage_map_type']) ? $settings['damage_map_type'] : '');
        if ($mode !== 'none') return $mode;
        return !empty($settings['auto_parts_mode']) ? 'car' : 'none';
    }
}

if (!function_exists('os_vehicle_damage_enabled')) {
    function os_vehicle_damage_enabled(array $settings)
    {
        return os_vehicle_damage_mode_from_settings($settings) !== 'none';
    }
}

if (!function_exists('os_vehicle_damage_profile')) {
    function os_vehicle_damage_profile(array $settings, PDO $pdo = null, $companyId = 0)
    {
        $mode = os_vehicle_damage_mode_from_settings($settings);
        if ($pdo === null && function_exists('os_db')) {
            try { $pdo = os_db(); } catch (Throwable $e) { $pdo = null; }
        }
        if ((int)$companyId <= 0) {
            if (!empty($settings['company_id'])) $companyId = (int)$settings['company_id'];
            elseif (function_exists('os_company_id')) $companyId = (int)os_company_id();
        }
        return os_vehicle_damage_profile_resolve($mode, $pdo, $companyId);
    }
}

if (!function_exists('os_vehicle_damage_asset_web_path')) {
    function os_vehicle_damage_asset_web_path(array $settings, PDO $pdo = null, $companyId = 0)
    {
        $profile = os_vehicle_damage_profile($settings, $pdo, $companyId);
        if (($profile['kind'] ?? 'builtin') === 'custom') return (string)$profile['asset'];
        return 'assets/' . $profile['asset'];
    }
}

if (!function_exists('os_vehicle_damage_asset_file_path')) {
    function os_vehicle_damage_asset_file_path(array $settings, PDO $pdo = null, $companyId = 0)
    {
        $profile = os_vehicle_damage_profile($settings, $pdo, $companyId);
        if (($profile['kind'] ?? 'builtin') === 'custom') return (string)($profile['file'] ?? '');
        return dirname(__DIR__) . '/admin/assets/' . $profile['asset'];
    }
}

if (!function_exists('os_vehicle_damage_title')) {
    function os_vehicle_damage_title(array $settings, PDO $pdo = null, $companyId = 0)
    {
        $profile = os_vehicle_damage_profile($settings, $pdo, $companyId);
        return (string)$profile['title'];
    }
}

if (!function_exists('os_vehicle_damage_alt')) {
    function os_vehicle_damage_alt(array $settings, PDO $pdo = null, $companyId = 0)
    {
        $profile = os_vehicle_damage_profile($settings, $pdo, $companyId);
        return (string)$profile['alt'];
    }
}


if (!function_exists('os_vehicle_damage_template_storage_root')) {
    function os_vehicle_damage_template_storage_root()
    {
        return dirname(__DIR__) . '/storage/os_damage_maps';
    }
}

if (!function_exists('os_vehicle_damage_template_ensure_dir')) {
    function os_vehicle_damage_template_ensure_dir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta dos modelos de avaria.');
        }
        return $dir;
    }
}

if (!function_exists('os_vehicle_damage_template_relative_valid')) {
    function os_vehicle_damage_template_relative_valid($relative, $companyId = 0)
    {
        $relative = str_replace('\\', '/', trim((string)$relative));
        if ($relative === '' || strpos($relative, '..') !== false || $relative[0] === '/') return false;
        if (!preg_match('~^company_([0-9]+)/templates/([a-z0-9_-]+)/([a-f0-9]{32})\.(png|jpg|jpeg|webp)$~i', $relative, $m)) return false;
        if ((int)$companyId > 0 && (int)$m[1] !== (int)$companyId) return false;
        return true;
    }
}

if (!function_exists('os_vehicle_damage_template_absolute')) {
    function os_vehicle_damage_template_absolute($relative, $companyId = 0)
    {
        if (!os_vehicle_damage_template_relative_valid($relative, $companyId)) return '';
        return os_vehicle_damage_template_storage_root() . '/' . str_replace('\\', '/', (string)$relative);
    }
}

if (!function_exists('os_vehicle_damage_template_delete_relative')) {
    function os_vehicle_damage_template_delete_relative($relative, $companyId = 0)
    {
        $path = os_vehicle_damage_template_absolute($relative, $companyId);
        if ($path === '') return false;
        if (!is_file($path)) return true;
        return @unlink($path);
    }
}

if (!function_exists('os_vehicle_damage_template_code')) {
    function os_vehicle_damage_template_code($label)
    {
        $slug = os_slug((string)$label);
        $slug = strtolower(trim((string)$slug));
        $slug = preg_replace('/[^a-z0-9_\-]+/', '-', $slug);
        $slug = trim((string)$slug, '-_');
        return $slug !== '' ? $slug : 'modelo';
    }
}

if (!function_exists('os_vehicle_damage_template_store_upload')) {
    function os_vehicle_damage_template_store_upload($companyId, $templateCode, array $file)
    {
        if ((int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Selecione uma imagem do modelo de avaria.');
        }
        if ((int)($file['size'] ?? 0) <= 0 || (int)($file['size'] ?? 0) > 10 * 1024 * 1024) {
            throw new RuntimeException('A imagem do modelo deve ter no máximo 10 MB.');
        }
        if (!is_uploaded_file((string)($file['tmp_name'] ?? ''))) {
            throw new RuntimeException('Upload da imagem do modelo inválido.');
        }
        $info = @getimagesize($file['tmp_name']);
        if (!$info || empty($info[0]) || empty($info[1])) throw new RuntimeException('O arquivo enviado não é uma imagem válida.');
        if ((int)$info[0] > 5000 || (int)$info[1] > 5000 || (int)$info[0] * (int)$info[1] > 20000000) throw new RuntimeException('Imagem muito grande. Limite: 5000 × 5000 pixels e 20 megapixels.');
        $mime = strtolower((string)($info['mime'] ?? ''));
        if (!in_array($mime, array('image/png','image/jpeg','image/webp'), true)) {
            throw new RuntimeException('Formato não aceito. Use PNG, JPG ou WEBP.');
        }
        $ext = $mime === 'image/png' ? 'png' : ($mime === 'image/webp' ? 'webp' : 'jpg');
        $base = bin2hex(random_bytes(16));
        $relative = 'company_' . (int)$companyId . '/templates/' . os_vehicle_damage_template_code($templateCode) . '/' . $base . '.' . $ext;
        $dest = os_vehicle_damage_template_storage_root() . '/' . $relative;
        os_vehicle_damage_template_ensure_dir(dirname($dest));
        if ($mime === 'image/webp') {
            // FPDF do Nexa não aceita WEBP diretamente. Convertemos em JPG para
            // manter impressão e compartilhamento consistentes em todos os formatos.
            if (!function_exists('imagecreatefromwebp') || !function_exists('imagejpeg')) {
                throw new RuntimeException('Para usar WEBP, habilite a extensão GD com suporte a WEBP no PHP ou envie PNG/JPG.');
            }
            $src = @imagecreatefromwebp($file['tmp_name']);
            if (!$src) throw new RuntimeException('Imagem WEBP inválida.');
            $dest = substr($dest, 0, -5) . '.jpg';
            $relative = substr($relative, 0, -5) . '.jpg';
            $success = @imagejpeg($src, $dest, 90);
            imagedestroy($src);
            if (!$success) throw new RuntimeException('Não foi possível converter a imagem WEBP.');
        } elseif (!@move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Não foi possível salvar a imagem do modelo de avaria.');
        }
        @chmod($dest, 0660);
        return $relative;
    }
}

if (!function_exists('os_vehicle_damage_template_clone')) {
    function os_vehicle_damage_template_clone(PDO $pdo, $companyId, $sourceMode)
    {
        $companyId = (int)$companyId;
        $sourceMode = os_vehicle_damage_mode_normalize($sourceMode);
        if ($companyId <= 0 || $sourceMode === 'none') throw new RuntimeException('Modelo de origem inválido.');
        $source = os_vehicle_damage_profile_resolve($sourceMode, $pdo, $companyId);
        if (($source['label'] ?? '') === 'Desabilitado') throw new RuntimeException('Modelo de origem não encontrado.');
        $from = ($source['kind'] ?? 'builtin') === 'custom'
            ? (string)($source['file'] ?? '')
            : dirname(__DIR__) . '/admin/assets/' . basename((string)$source['asset']);
        if ($from === '' || !is_file($from)) throw new RuntimeException('Imagem original não encontrada.');
        $name = function_exists('mb_substr') ? mb_substr((string)$source['label'] . ' (cópia)', 0, 120, 'UTF-8') : substr((string)$source['label'] . ' (cópia)', 0, 120);
        $code = 'copia-' . os_vehicle_damage_template_code($source['code'] ?? $sourceMode) . '-' . bin2hex(random_bytes(3));
        $ext = strtolower(pathinfo($from, PATHINFO_EXTENSION));
        if (!in_array($ext, array('png','jpg','jpeg','webp'), true)) throw new RuntimeException('Formato inválido para duplicação.');
        $relative = 'company_' . $companyId . '/templates/' . os_vehicle_damage_template_code($code) . '/' . bin2hex(random_bytes(16)) . '.' . $ext;
        $dest = os_vehicle_damage_template_storage_root() . '/' . $relative;
        os_vehicle_damage_template_ensure_dir(dirname($dest));
        if (!@copy($from, $dest)) throw new RuntimeException('Não foi possível copiar a imagem do modelo.');
        try {
            $stmt = $pdo->prepare('INSERT INTO os_damage_templates (company_id,code,name,category,image_ref,sort_order,active) VALUES (?,?,?,?,?,?,1)');
            $category = (string)($source['category'] ?? array('car'=>'Automóveis','motorcycle'=>'Motocicletas','computer'=>'Notebooks e Computadores')[$sourceMode] ?? 'Outros');
            $stmt->execute(array($companyId,$code,$name,$category,$relative,(int)($source['sort_order'] ?? 0) + 1));
            return (int)$pdo->lastInsertId();
        } catch (Throwable $e) {
            @unlink($dest);
            throw $e;
        }
    }
}

if (!function_exists('os_vehicle_damage_custom_templates')) {
    function os_vehicle_damage_custom_templates(PDO $pdo, $companyId, $onlyActive = true)
    {
        if (!os_schema_has_table($pdo, 'os_damage_templates')) return array();
        $sql = "SELECT id,company_id,code,name,category,image_ref,sort_order,active FROM os_damage_templates WHERE company_id=?" . ($onlyActive ? " AND active=1" : "") . " ORDER BY sort_order,name,id";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array((int)$companyId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $profiles = array();
        foreach ($rows as $row) {
            $key = 'custom:' . (int)$row['id'];
            $profiles[$key] = array(
                'kind'  => 'custom',
                'id'    => (int)$row['id'],
                'code'  => (string)$row['code'],
                'category' => (string)($row['category'] ?? 'Outros'),
                'label' => (string)$row['name'],
                'title' => 'Mapa de avarias / inspeção - ' . (string)$row['name'],
                'alt'   => 'Modelo personalizado de avarias: ' . (string)$row['name'],
                'asset' => 'os_damage_template_asset.php?id=' . (int)$row['id'],
                'file'  => os_vehicle_damage_template_absolute((string)$row['image_ref'], (int)$companyId),
                'active'=> (int)$row['active'] === 1,
                'sort_order' => (int)$row['sort_order'],
            );
        }
        return $profiles;
    }
}

if (!function_exists('os_vehicle_damage_profiles_all')) {
    function os_vehicle_damage_profiles_all(PDO $pdo = null, $companyId = 0, $includeInactive = false)
    {
        $profiles = os_vehicle_damage_profiles();
        if ($pdo instanceof PDO && (int)$companyId > 0) {
            $custom = os_vehicle_damage_custom_templates($pdo, $companyId, !$includeInactive);
            foreach ($custom as $key => $meta) $profiles[$key] = $meta;
        }
        return $profiles;
    }
}

if (!function_exists('os_vehicle_damage_profile_resolve')) {
    function os_vehicle_damage_profile_resolve($mode, PDO $pdo = null, $companyId = 0)
    {
        $profiles = os_vehicle_damage_profiles_all($pdo, $companyId, true);
        $mode = os_vehicle_damage_mode_normalize($mode);
        return isset($profiles[$mode]) && (!isset($profiles[$mode]['active']) || $profiles[$mode]['active']) ? $profiles[$mode] : $profiles['none'];
    }
}

if (!function_exists('os_vehicle_damage_types')) {
    function os_vehicle_damage_types()
    {
        return array(
            'AVARIA' => 'Avaria',
            'RISCO' => 'Risco / arranhão',
            'AMASSADO' => 'Amassado',
            'QUEBRADO' => 'Quebrado / trincado',
            'PINTURA' => 'Pintura',
            'OUTRO' => 'Outro'
        );
    }
}

if (!function_exists('os_vehicle_damage_photo_storage_root')) {
    function os_vehicle_damage_photo_storage_root()
    {
        return dirname(__DIR__) . '/storage/os_damage_photos';
    }
}

if (!function_exists('os_vehicle_damage_photo_ensure_dir')) {
    function os_vehicle_damage_photo_ensure_dir($dir)
    {
        if (!is_dir($dir) && !@mkdir($dir, 0770, true) && !is_dir($dir)) {
            throw new RuntimeException('Não foi possível criar a pasta de fotos das avarias.');
        }
        return $dir;
    }
}

if (!function_exists('os_vehicle_damage_photo_relative_valid')) {
    function os_vehicle_damage_photo_relative_valid($relative, $companyId = 0, $orderId = 0)
    {
        $relative = str_replace('\\', '/', trim((string)$relative));
        if ($relative === '' || strpos($relative, '..') !== false || $relative[0] === '/') return false;
        if (!preg_match('~^company_([0-9]+)/order_([0-9]+)/([a-f0-9]{32})\.(jpg|jpeg|png|webp)$~i', $relative, $m)) return false;
        if ((int)$companyId > 0 && (int)$m[1] !== (int)$companyId) return false;
        if ((int)$orderId > 0 && (int)$m[2] !== (int)$orderId) return false;
        return true;
    }
}

if (!function_exists('os_vehicle_damage_photo_absolute')) {
    function os_vehicle_damage_photo_absolute($relative, $companyId = 0, $orderId = 0)
    {
        if (!os_vehicle_damage_photo_relative_valid($relative, $companyId, $orderId)) return '';
        return os_vehicle_damage_photo_storage_root() . '/' . str_replace('\\', '/', (string)$relative);
    }
}

if (!function_exists('os_vehicle_damage_photo_delete_relative')) {
    function os_vehicle_damage_photo_delete_relative($relative, $companyId = 0, $orderId = 0)
    {
        $path = os_vehicle_damage_photo_absolute($relative, $companyId, $orderId);
        if ($path === '') return false;
        if (!is_file($path)) return true;
        return @unlink($path);
    }
}

if (!function_exists('os_vehicle_damage_tmp_photo_absolute')) {
    function os_vehicle_damage_tmp_photo_absolute($companyId, $token, $filename)
    {
        $token = strtolower(trim((string)$token));
        $filename = strtolower(trim((string)$filename));
        if (!preg_match('/^[a-f0-9]{32,64}$/', $token)) return '';
        if (!preg_match('/^[a-f0-9]{32}\.(jpg|jpeg|png|webp)$/', $filename)) return '';
        return os_vehicle_damage_photo_storage_root() . '/tmp/company_' . (int)$companyId . '/' . $token . '/' . $filename;
    }
}

if (!function_exists('os_vehicle_damage_promote_tmp_photo')) {
    function os_vehicle_damage_promote_tmp_photo($companyId, $orderId, $photoRef)
    {
        $photoRef = trim((string)$photoRef);
        if (!preg_match('/^tmp:([a-f0-9]{32,64}):([a-f0-9]{32}\.(?:jpg|jpeg|png|webp))$/i', $photoRef, $m)) return '';
        $src = os_vehicle_damage_tmp_photo_absolute($companyId, $m[1], $m[2]);
        if ($src === '' || !is_file($src)) return '';
        $ext = strtolower(pathinfo($src, PATHINFO_EXTENSION));
        if (!in_array($ext, array('jpg','jpeg','png','webp'), true)) $ext = 'jpg';
        $filename = bin2hex(random_bytes(16)) . '.' . $ext;
        $relative = 'company_' . (int)$companyId . '/order_' . (int)$orderId . '/' . $filename;
        $dest = os_vehicle_damage_photo_storage_root() . '/' . $relative;
        os_vehicle_damage_photo_ensure_dir(dirname($dest));
        if (!@rename($src, $dest)) {
            if (!@copy($src, $dest)) return '';
            @unlink($src);
        }
        return $relative;
    }
}

if (!function_exists('os_vehicle_damage_load')) {
    function os_vehicle_damage_load(PDO $pdo, $companyId, $orderId)
    {
        if ((int)$companyId <= 0 || (int)$orderId <= 0) return array();
        $stmt = $pdo->prepare("SELECT id,sort_order,position_x,position_y,damage_type,description,photo_path,photo_created_at,photo_deleted_at
                              FROM os_vehicle_damage_points
                              WHERE company_id=? AND order_id=?
                              ORDER BY sort_order,id");
        $stmt->execute(array((int)$companyId,(int)$orderId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        foreach ($rows as &$row) {
            $row['position_x'] = (float)$row['position_x'];
            $row['position_y'] = (float)$row['position_y'];
            $row['sort_order'] = (int)$row['sort_order'];
            $row['photo_ref'] = !empty($row['photo_path']) ? 'perm:' . $row['photo_path'] : '';
            $row['photo_url'] = !empty($row['photo_path']) ? 'os_damage_photo.php?point_id=' . (int)$row['id'] : '';
        }
        unset($row);
        return $rows;
    }
}

if (!function_exists('os_vehicle_damage_replace')) {
    /**
     * Substitui as marcações do mapa de avarias da O.S. usando coordenadas
     * percentuais e promove eventuais fotos temporárias para a pasta definitiva.
     * Deve ser chamado dentro da mesma transação do salvamento da O.S.
     */
    function os_vehicle_damage_replace(PDO $pdo, $companyId, $orderId, $payload)
    {
        $companyId = (int)$companyId;
        $orderId = (int)$orderId;
        if ($companyId <= 0 || $orderId <= 0) throw new RuntimeException('O.S. inválida para salvar avarias.');

        if (is_string($payload)) {
            $payload = trim($payload);
            $points = $payload === '' ? array() : json_decode($payload, true);
            if ($payload !== '' && !is_array($points)) throw new RuntimeException('As marcações de avaria estão em formato inválido.');
        } elseif (is_array($payload)) {
            $points = $payload;
        } else {
            $points = array();
        }

        if (count($points) > 100) throw new RuntimeException('O mapa de avarias aceita no máximo 100 marcações por O.S.');
        $types = os_vehicle_damage_types();

        $oldStmt = $pdo->prepare("SELECT photo_path,photo_created_at FROM os_vehicle_damage_points WHERE company_id=? AND order_id=? AND photo_path IS NOT NULL AND photo_path<>''");
        $oldStmt->execute(array($companyId,$orderId));
        $oldPhotos = array();
        foreach ($oldStmt->fetchAll(PDO::FETCH_ASSOC) ?: array() as $old) {
            $oldPhotos[(string)$old['photo_path']] = (string)$old['photo_created_at'];
        }

        $clean = array();
        $usedPhotos = array();
        foreach ($points as $point) {
            if (!is_array($point)) continue;
            $x = isset($point['x']) ? (float)$point['x'] : (isset($point['position_x']) ? (float)$point['position_x'] : -1);
            $y = isset($point['y']) ? (float)$point['y'] : (isset($point['position_y']) ? (float)$point['position_y'] : -1);
            if ($x < 0 || $x > 100 || $y < 0 || $y > 100) continue;
            $type = strtoupper(trim((string)(isset($point['type']) ? $point['type'] : (isset($point['damage_type']) ? $point['damage_type'] : 'AVARIA'))));
            if (!isset($types[$type])) $type = 'AVARIA';
            $description = trim((string)(isset($point['description']) ? $point['description'] : ''));
            if (function_exists('mb_substr')) $description = mb_substr($description, 0, 255, 'UTF-8');
            else $description = substr($description, 0, 255);

            $photoPath = '';
            $photoCreatedAt = null;
            $photoRef = trim((string)(isset($point['photo_ref']) ? $point['photo_ref'] : ''));
            if (strpos($photoRef, 'perm:') === 0) {
                $candidate = substr($photoRef, 5);
                if (isset($oldPhotos[$candidate]) && os_vehicle_damage_photo_relative_valid($candidate, $companyId, $orderId)) {
                    $photoPath = $candidate;
                    $photoCreatedAt = $oldPhotos[$candidate] !== '' ? $oldPhotos[$candidate] : null;
                }
            } elseif (strpos($photoRef, 'tmp:') === 0) {
                $candidate = os_vehicle_damage_promote_tmp_photo($companyId, $orderId, $photoRef);
                if ($candidate !== '') {
                    $photoPath = $candidate;
                    $photoCreatedAt = date('Y-m-d H:i:s');
                }
            }
            if ($photoPath !== '') $usedPhotos[$photoPath] = true;
            $clean[] = array(
                'x'=>round($x,4),'y'=>round($y,4),'type'=>$type,'description'=>$description,
                'photo_path'=>$photoPath,'photo_created_at'=>$photoCreatedAt
            );
        }

        $del = $pdo->prepare("DELETE FROM os_vehicle_damage_points WHERE company_id=? AND order_id=?");
        $del->execute(array($companyId,$orderId));

        if ($clean) {
            $ins = $pdo->prepare("INSERT INTO os_vehicle_damage_points
                (company_id,order_id,sort_order,position_x,position_y,damage_type,description,photo_path,photo_created_at,created_at)
                VALUES (?,?,?,?,?,?,?,?,?,NOW())");
            foreach ($clean as $idx => $point) {
                $ins->execute(array(
                    $companyId,$orderId,$idx+1,$point['x'],$point['y'],$point['type'],
                    $point['description']!==''?$point['description']:null,
                    $point['photo_path']!==''?$point['photo_path']:null,
                    $point['photo_created_at']
                ));
            }
        }

        // Fotos de pontos removidos/substituídos são eliminadas imediatamente.
        foreach ($oldPhotos as $oldPath => $unusedCreatedAt) {
            if (!isset($usedPhotos[$oldPath])) os_vehicle_damage_queue_photo_delete($oldPath, $companyId, $orderId);
        }
        return count($clean);
    }
}

if (!function_exists('os_vehicle_damage_queue_photo_delete')) {
    function os_vehicle_damage_queue_photo_delete($relative, $companyId, $orderId)
    {
        if (!isset($GLOBALS['__os_damage_photo_delete_queue']) || !is_array($GLOBALS['__os_damage_photo_delete_queue'])) {
            $GLOBALS['__os_damage_photo_delete_queue'] = array();
        }
        $GLOBALS['__os_damage_photo_delete_queue'][] = array((string)$relative,(int)$companyId,(int)$orderId);
    }
}

if (!function_exists('os_vehicle_damage_flush_photo_deletes')) {
    function os_vehicle_damage_flush_photo_deletes()
    {
        $queue = isset($GLOBALS['__os_damage_photo_delete_queue']) && is_array($GLOBALS['__os_damage_photo_delete_queue']) ? $GLOBALS['__os_damage_photo_delete_queue'] : array();
        $GLOBALS['__os_damage_photo_delete_queue'] = array();
        $n = 0;
        foreach ($queue as $item) {
            if (os_vehicle_damage_photo_delete_relative($item[0], $item[1], $item[2])) $n++;
        }
        return $n;
    }
}

if (!function_exists('os_vehicle_damage_clear_photo_delete_queue')) {
    function os_vehicle_damage_clear_photo_delete_queue()
    {
        $GLOBALS['__os_damage_photo_delete_queue'] = array();
    }
}

if (!function_exists('os_vehicle_damage_cleanup_expired_photos')) {
    /**
     * Exclui somente as imagens de avarias de O.S. concluídas há mais de N horas.
     * Pontos, tipos, observações e histórico permanecem no banco.
     * Cancelamentos não são tratados como conclusão para esta limpeza.
     */
    function os_vehicle_damage_cleanup_expired_photos(PDO $pdo, $companyId, $hours = 48, $limit = 250)
    {
        $companyId = (int)$companyId;
        $hours = max(1, min(8760, (int)$hours));
        $limit = max(1, min(1000, (int)$limit));
        if ($companyId <= 0) return 0;
        $sql = "SELECT dp.id,dp.order_id,dp.photo_path
                  FROM os_vehicle_damage_points dp
                  JOIN os_orders o ON o.id=dp.order_id AND o.company_id=dp.company_id
                  JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id
                 WHERE dp.company_id=?
                   AND dp.photo_path IS NOT NULL AND dp.photo_path<>''
                   AND o.closed_at IS NOT NULL
                   AND o.closed_at <= DATE_SUB(NOW(), INTERVAL {$hours} HOUR)
                   AND s.is_final=1 AND UPPER(COALESCE(s.code,''))<>'CANCELADA'
                 ORDER BY o.closed_at ASC,dp.id ASC
                 LIMIT {$limit}";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array($companyId));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: array();
        if (!$rows) return 0;
        $upd = $pdo->prepare("UPDATE os_vehicle_damage_points SET photo_path=NULL,photo_deleted_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?");
        $deleted = 0;
        foreach ($rows as $row) {
            $path = (string)$row['photo_path'];
            // Arquivo ausente também é considerado limpo para não ficar referência órfã.
            if (os_vehicle_damage_photo_delete_relative($path, $companyId, (int)$row['order_id'])) {
                $upd->execute(array($companyId,(int)$row['id']));
                $deleted++;
            }
        }
        return $deleted;
    }
}

if (!function_exists('os_vehicle_damage_cleanup_tmp_photos')) {
    function os_vehicle_damage_cleanup_tmp_photos($companyId, $hours = 72)
    {
        $companyId = (int)$companyId;
        $hours = max(24, min(720, (int)$hours));
        $root = os_vehicle_damage_photo_storage_root() . '/tmp/company_' . $companyId;
        if (!is_dir($root)) return 0;
        $deadline = time() - ($hours * 3600);
        $deleted = 0;
        $dirs = @scandir($root);
        if (!is_array($dirs)) return 0;
        foreach ($dirs as $token) {
            if ($token==='.' || $token==='..' || !preg_match('/^[a-f0-9]{32,64}$/i',$token)) continue;
            $dir = $root . '/' . $token;
            if (!is_dir($dir)) continue;
            $files = @scandir($dir);
            if (!is_array($files)) continue;
            foreach ($files as $file) {
                if ($file==='.' || $file==='..') continue;
                $path=$dir.'/'.$file;
                if (is_file($path) && @filemtime($path) < $deadline && @unlink($path)) $deleted++;
            }
            $remaining=@scandir($dir);
            if (is_array($remaining) && count($remaining)<=2) @rmdir($dir);
        }
        return $deleted;
    }
}

