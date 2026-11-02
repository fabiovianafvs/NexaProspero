<?php
declare(strict_types=1);

require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/company_sequences.php';
require_once __DIR__ . '/brain_profitability.php';
require_once __DIR__ . '/schema_runtime.php';
require_once __DIR__ . '/commercial_rules.php';
require_once __DIR__ . '/product_composition.php';
require_once __DIR__ . '/billing_module.php';
require_once __DIR__ . '/card_control.php';
require_once __DIR__ . '/cash_operator_guard.php';

if (!function_exists('gestao_h')) {
  function gestao_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('gestao_ensure_schema')) {
  function gestao_ensure_schema(PDO $pdo): array {
    static $done = false;
    if ($done) return ['ok' => true, 'errors' => []];
    // V11.25: no contexto F11 o schema é tratado como pré-requisito e nunca é alterado em runtime.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return ['ok' => true, 'errors' => []];
    $done = true;
    try { commercial_ensure_schema($pdo); } catch (Throwable $e) {}
    $schemaVersion = 2026092801;
    if (nexa_schema_version_ready($pdo, 'gestao_runtime', $schemaVersion)) {
      return ['ok' => true, 'errors' => []];
    }
    $errors = [];
    try { finance_ensure_schema($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    try { company_sequence_ensure_schema($pdo, true); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
    try { $errors = array_merge($errors, brain_profitability_ensure_schema($pdo, true)); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_payment_methods (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        code VARCHAR(50) NOT NULL,
        name VARCHAR(120) NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'OUTRO',
        default_bank_account_id INT NULL,
        default_chart_account_id INT NULL,
        default_cost_center_id INT NULL,
        generate_receivable TINYINT(1) NOT NULL DEFAULT 0,
        auto_register_boleto TINYINT(1) NOT NULL DEFAULT 0,
        pix_auto_qr_enabled TINYINT(1) NOT NULL DEFAULT 0,
        pix_provider VARCHAR(30) NULL,
        pix_key VARCHAR(180) NULL,
        pix_expiration_seconds INT NOT NULL DEFAULT 300,
        pix_api_base_url VARCHAR(500) NULL,
        allow_installments TINYINT(1) NOT NULL DEFAULT 0,
        default_installments INT NOT NULL DEFAULT 1,
        first_due_days INT NOT NULL DEFAULT 30,
        keep_due_day TINYINT(1) NOT NULL DEFAULT 1,
        affects_cash TINYINT(1) NOT NULL DEFAULT 1,
        require_open_cash TINYINT(1) NOT NULL DEFAULT 0,
        allow_change TINYINT(1) NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        sort_order INT NOT NULL DEFAULT 0,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_fin_pay_method_code (company_id, code),
        KEY idx_fin_pay_method_company (company_id, is_active)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    // Garante a coluna de complemento/observação dos itens do pedido.
    // O Delivery já usa esta coluna para adicionais, pizza meio a meio e observações por item.
    if (finance_table_exists($pdo, 'pedido_itens')) {
      finance_add_column_if_missing($pdo, 'pedido_itens', 'complemento', 'TEXT NULL', $errors);
    }

    if (finance_table_exists($pdo, 'pedidos')) {
      foreach ([
        ['zip_code', 'VARCHAR(20) NULL'],
        ['numero', 'VARCHAR(30) NULL'],
        ['complemento', 'VARCHAR(120) NULL'],
        ['referencia', 'VARCHAR(200) NULL'],
        ['cidade', 'VARCHAR(100) NULL'],
        ['uf', 'VARCHAR(2) NULL'],
        ['scheduled_delivery_date', 'DATE NULL'],
        ['scheduled_delivery_time', 'TIME NULL'],
      ] as $c) finance_add_column_if_missing($pdo, 'pedidos', $c[0], $c[1], $errors);
    }

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_cash_sessions (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        user_id INT NULL,
        bank_account_id INT NULL,
        opening_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        closed_at DATETIME NULL,
        closing_amount DECIMAL(15,2) NULL,
        expected_amount DECIMAL(15,2) NULL,
        difference_amount DECIMAL(15,2) NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_gestao_cash_company_status (company_id, status),
        KEY idx_gestao_cash_user_status (company_id, user_id, status)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_cash_operations (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        cash_session_id INT NULL,
        user_id INT NULL,
        type VARCHAR(30) NOT NULL,
        operation_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        bank_account_id INT NULL,
        chart_account_id INT NULL,
        cost_center_id INT NULL,
        movement_id INT NULL,
        order_id INT NULL,
        description VARCHAR(220) NULL,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_gestao_cash_ops_session (company_id, cash_session_id, operation_date),
        KEY idx_gestao_cash_ops_type (company_id, type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_order_payments (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        order_id INT NOT NULL,
        cash_session_id INT NULL,
        payment_method_id INT NULL,
        payment_label VARCHAR(120) NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        received_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        change_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        bank_account_id INT NULL,
        chart_account_id INT NULL,
        cost_center_id INT NULL,
        finance_movement_id INT NULL,
        receivable_id INT NULL,
        receivable_ids TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_gestao_order_payments_order (company_id, order_id),
        KEY idx_gestao_order_payments_cash (company_id, cash_session_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);


    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_pix_charges (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        payment_method_id INT NOT NULL,
        bank_account_id INT NULL,
        user_id INT NULL,
        cash_session_id INT NULL,
        provider VARCHAR(30) NOT NULL,
        status VARCHAR(30) NOT NULL DEFAULT 'CRIANDO',
        provider_status VARCHAR(60) NULL,
        txid VARCHAR(100) NOT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        pix_key VARCHAR(180) NULL,
        pix_copy_paste LONGTEXT NULL,
        qr_image LONGTEXT NULL,
        e2eid VARCHAR(100) NULL,
        order_id BIGINT NULL,
        expires_at DATETIME NULL,
        request_payload LONGTEXT NULL,
        response_payload LONGTEXT NULL,
        error_message VARCHAR(1000) NULL,
        last_check_at DATETIME NULL,
        paid_at DATETIME NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY(id),
        UNIQUE KEY uk_gestao_pix_txid(company_id,provider,txid),
        KEY idx_gestao_pix_company_status(company_id,status,created_at),
        KEY idx_gestao_pix_order(company_id,order_id),
        KEY idx_gestao_pix_method(company_id,payment_method_id,created_at)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS gestao_customer_addresses (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        person_id INT NOT NULL,
        label VARCHAR(80) NULL,
        zip_code VARCHAR(20) NULL,
        address VARCHAR(180) NULL,
        number VARCHAR(30) NULL,
        complement VARCHAR(120) NULL,
        reference VARCHAR(200) NULL,
        district VARCHAR(100) NULL,
        city VARCHAR(100) NULL,
        city_ibge VARCHAR(7) NULL,
        state VARCHAR(2) NULL,
        is_default TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_gestao_addr_person (company_id, person_id, active),
        KEY idx_gestao_addr_default (company_id, person_id, is_default)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    if (finance_table_exists($pdo, 'finance_payment_methods')) {
      foreach ([
        ['code', "VARCHAR(50) NOT NULL"], ['name', "VARCHAR(120) NOT NULL"], ['type', "VARCHAR(30) NOT NULL DEFAULT 'OUTRO'"],
        ['default_bank_account_id', 'INT NULL'], ['default_chart_account_id', 'INT NULL'], ['default_cost_center_id', 'INT NULL'],
        ['generate_receivable', 'TINYINT(1) NOT NULL DEFAULT 0'], ['auto_register_boleto', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['pix_auto_qr_enabled', 'TINYINT(1) NOT NULL DEFAULT 0'], ['pix_provider', 'VARCHAR(30) NULL'], ['pix_key', 'VARCHAR(180) NULL'], ['pix_expiration_seconds', 'INT NOT NULL DEFAULT 300'], ['pix_api_base_url', 'VARCHAR(500) NULL'],
        ['allow_installments', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['default_installments', 'INT NOT NULL DEFAULT 1'], ['first_due_days', 'INT NOT NULL DEFAULT 30'], ['keep_due_day', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['affects_cash', 'TINYINT(1) NOT NULL DEFAULT 1'], ['require_open_cash', 'TINYINT(1) NOT NULL DEFAULT 0'], ['allow_change', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['is_active', 'TINYINT(1) NOT NULL DEFAULT 1'], ['sort_order', 'INT NOT NULL DEFAULT 0'], ['notes', 'TEXT NULL'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'], ['updated_at', 'DATETIME NULL'],
      ] as $c) finance_add_column_if_missing($pdo, 'finance_payment_methods', $c[0], $c[1], $errors);
      finance_try_exec($pdo, 'ALTER TABLE finance_payment_methods ADD UNIQUE KEY uk_fin_pay_method_code (company_id, code)', $errors);
    }

    if (finance_table_exists($pdo, 'gestao_cash_sessions')) {
      foreach ([
        ['company_id', 'INT NOT NULL DEFAULT 0'],
        ['user_id', 'INT NULL'],
        ['bank_account_id', 'INT NULL'],
        ['opening_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['opened_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['closed_at', 'DATETIME NULL'],
        ['closing_amount', 'DECIMAL(15,2) NULL'],
        ['expected_amount', 'DECIMAL(15,2) NULL'],
        ['difference_amount', 'DECIMAL(15,2) NULL'],
        ['status', "VARCHAR(20) NOT NULL DEFAULT 'ABERTO'"],
        ['notes', 'TEXT NULL'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['updated_at', 'DATETIME NULL'],
      ] as $c) finance_add_column_if_missing($pdo, 'gestao_cash_sessions', $c[0], $c[1], $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_cash_sessions ADD KEY idx_gestao_cash_company_status (company_id, status)', $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_cash_sessions ADD KEY idx_gestao_cash_user_status (company_id, user_id, status)', $errors);
    }

    if (finance_table_exists($pdo, 'gestao_cash_operations')) {
      foreach ([
        ['company_id', 'INT NOT NULL DEFAULT 0'],
        ['cash_session_id', 'INT NULL'],
        ['user_id', 'INT NULL'],
        ['type', "VARCHAR(30) NOT NULL DEFAULT 'AJUSTE'"],
        ['operation_date', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['bank_account_id', 'INT NULL'],
        ['chart_account_id', 'INT NULL'],
        ['cost_center_id', 'INT NULL'],
        ['movement_id', 'INT NULL'],
        ['order_id', 'INT NULL'],
        ['description', 'VARCHAR(220) NULL'],
        ['notes', 'TEXT NULL'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      ] as $c) finance_add_column_if_missing($pdo, 'gestao_cash_operations', $c[0], $c[1], $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_cash_operations ADD KEY idx_gestao_cash_ops_session (company_id, cash_session_id, operation_date)', $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_cash_operations ADD KEY idx_gestao_cash_ops_type (company_id, type)', $errors);
    }

    if (finance_table_exists($pdo, 'gestao_order_payments')) {
      foreach ([
        ['company_id', 'INT NOT NULL DEFAULT 0'],
        ['order_id', 'INT NOT NULL DEFAULT 0'],
        ['cash_session_id', 'INT NULL'],
        ['payment_method_id', 'INT NULL'],
        ['payment_label', 'VARCHAR(120) NULL'],
        ['amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['received_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['change_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0'],
        ['bank_account_id', 'INT NULL'],
        ['chart_account_id', 'INT NULL'],
        ['cost_center_id', 'INT NULL'],
        ['finance_movement_id', 'INT NULL'],
        ['receivable_id', 'INT NULL'],
        ['receivable_ids', 'TEXT NULL'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
      ] as $c) finance_add_column_if_missing($pdo, 'gestao_order_payments', $c[0], $c[1], $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_order_payments ADD KEY idx_gestao_order_payments_order (company_id, order_id)', $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_order_payments ADD KEY idx_gestao_order_payments_cash (company_id, cash_session_id)', $errors);
    }


    if (finance_table_exists($pdo, 'gestao_customer_addresses')) {
      foreach ([
        ['company_id', 'INT NOT NULL DEFAULT 0'],
        ['person_id', 'INT NOT NULL DEFAULT 0'],
        ['label', 'VARCHAR(80) NULL'],
        ['zip_code', 'VARCHAR(20) NULL'],
        ['address', 'VARCHAR(180) NULL'],
        ['number', 'VARCHAR(30) NULL'],
        ['complement', 'VARCHAR(120) NULL'],
        ['reference', 'VARCHAR(200) NULL'],
        ['district', 'VARCHAR(100) NULL'],
        ['city', 'VARCHAR(100) NULL'],
        ['city_ibge', 'VARCHAR(7) NULL'],
        ['state', 'VARCHAR(2) NULL'],
        ['is_default', 'TINYINT(1) NOT NULL DEFAULT 0'],
        ['active', 'TINYINT(1) NOT NULL DEFAULT 1'],
        ['created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP'],
        ['updated_at', 'DATETIME NULL'],
      ] as $c) finance_add_column_if_missing($pdo, 'gestao_customer_addresses', $c[0], $c[1], $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_customer_addresses ADD KEY idx_gestao_addr_person (company_id, person_id, active)', $errors);
      finance_try_exec($pdo, 'ALTER TABLE gestao_customer_addresses ADD KEY idx_gestao_addr_default (company_id, person_id, is_default)', $errors);
    }


    if (finance_table_exists($pdo, 'users')) {
      finance_add_column_if_missing($pdo, 'users', 'default_cash_bank_account_id', 'INT NULL', $errors);
      finance_add_column_if_missing($pdo, 'users', 'default_pdv_cash_bank_account_id', 'INT NULL', $errors);
      finance_add_column_if_missing($pdo, 'users', 'default_comanda_cash_bank_account_id', 'INT NULL', $errors);
    }

    if (finance_table_exists($pdo, 'pedidos')) {
      $cols = [
        'finance_person_id' => 'INT NULL',
        'seller_id' => 'INT NULL',
        'payment_method_id' => 'INT NULL',
        'bank_account_id' => 'INT NULL',
        'chart_account_id' => 'INT NULL',
        'cost_center_id' => 'INT NULL',
        'cash_session_id' => 'INT NULL',
        'source' => "VARCHAR(40) NULL DEFAULT 'delivery'",
        'discount_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
        'addition_amount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      ];
      foreach ($cols as $col => $def) finance_add_column_if_missing($pdo, 'pedidos', $col, $def, $errors);
    }

    try { gestao_seed_payment_methods($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    $errors = array_values(array_filter($errors, function($m){
      return stripos((string)$m, 'Duplicate key name') === false && stripos((string)$m, 'Duplicate column name') === false;
    }));
    $ok = finance_table_exists($pdo, 'finance_payment_methods') && finance_table_exists($pdo, 'gestao_cash_sessions');
    if ($ok && !$errors) nexa_schema_version_mark($pdo, 'gestao_runtime', $schemaVersion, 'Gestão preparada para execução sem DDL por requisição');
    return ['ok' => $ok, 'errors' => $errors];
  }
}

if (!function_exists('gestao_default_chart_id')) {
  function gestao_default_chart_id(PDO $pdo, int $companyId, string $movement = 'CREDITO'): int {
    $movement = strtoupper($movement);
    $rows = finance_query_all($pdo, "SELECT id FROM finance_chart_accounts WHERE company_id = ? AND active = 1 AND is_analytic = 1 AND movement_type IN (?, 'AMBOS') ORDER BY code ASC, id ASC LIMIT 1", [$companyId, $movement]);
    return (int)($rows[0]['id'] ?? 0);
  }
}

if (!function_exists('gestao_default_bank_id')) {
  function gestao_default_bank_id(PDO $pdo, int $companyId, string $type = ''): int {
    $params = [$companyId];
    $where = 'company_id = ? AND active = 1';
    if ($type !== '') { $where .= ' AND type = ?'; $params[] = $type; }
    $rows = finance_query_all($pdo, "SELECT id FROM finance_bank_accounts WHERE {$where} ORDER BY id ASC LIMIT 1", $params);
    if (!$rows && $type !== '') return gestao_default_bank_id($pdo, $companyId, '');
    return (int)($rows[0]['id'] ?? 0);
  }
}

if (!function_exists('gestao_seed_payment_methods')) {
  function gestao_seed_payment_methods(PDO $pdo, int $companyId = 0): void {
    if (!finance_table_exists($pdo, 'finance_payment_methods')) return;
    if ($companyId <= 0 && defined('COMPANY_ID')) $companyId = (int)COMPANY_ID;
    if ($companyId <= 0) return;
    $st = $pdo->prepare('SELECT COUNT(*) FROM finance_payment_methods WHERE company_id = ?');
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) return;

    $receita = gestao_default_chart_id($pdo, $companyId, 'CREDITO');
    $caixa = gestao_default_bank_id($pdo, $companyId, 'CAIXA');
    $banco = gestao_default_bank_id($pdo, $companyId, 'BANCO') ?: $caixa;
    $ins = $pdo->prepare('INSERT INTO finance_payment_methods (company_id, code, name, type, default_bank_account_id, default_chart_account_id, generate_receivable, allow_installments, default_installments, first_due_days, keep_due_day, affects_cash, require_open_cash, allow_change, is_active, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
    $ins->execute([$companyId, 'DINHEIRO', 'Dinheiro', 'DINHEIRO', $caixa ?: null, $receita ?: null, 0, 0, 1, 0, 1, 1, 1, 1, 10]);
    $ins->execute([$companyId, 'PIX', 'Pix', 'PIX', $banco ?: null, $receita ?: null, 0, 0, 1, 0, 1, 1, 0, 0, 20]);
    $ins->execute([$companyId, 'CARTAO_DEBITO', 'Cartão de Débito', 'CARTAO', $banco ?: null, $receita ?: null, 0, 0, 1, 0, 1, 1, 0, 0, 30]);
    $ins->execute([$companyId, 'CARTAO_CREDITO', 'Cartão de Crédito', 'CARTAO', $banco ?: null, $receita ?: null, 0, 0, 1, 0, 1, 1, 0, 0, 40]);
    $ins->execute([$companyId, 'A_PRAZO', 'A Prazo / Conta a receber', 'PRAZO', null, $receita ?: null, 1, 1, 1, 30, 1, 0, 0, 0, 50]);
    $ins->execute([$companyId, 'VALE', 'Vale', 'VALE', null, null, 0, 0, 1, 0, 1, 0, 0, 0, 60]);
  }
}

if (!function_exists('gestao_payment_methods')) {
  function gestao_payment_methods(PDO $pdo, int $companyId, bool $activeOnly = true): array {
    if (!finance_table_exists($pdo, 'finance_payment_methods')) gestao_ensure_schema($pdo);
    $where = ['company_id = ?']; $params = [$companyId];
    if ($activeOnly) $where[] = 'is_active = 1';
    $sql = 'SELECT * FROM finance_payment_methods WHERE ' . implode(' AND ', $where) . ' ORDER BY sort_order ASC, name ASC';
    $rows = finance_query_all($pdo, $sql, $params);
    // Empresas novas continuam recebendo os padrões, sem recolocar DDL no caminho normal.
    if (!$rows && $companyId > 0) {
      gestao_seed_payment_methods($pdo, $companyId);
      $rows = finance_query_all($pdo, $sql, $params);
    }
    // V11.45.18.2: compatibilidade com formas de pagamento Vale legadas.
    // Algumas empresas já possuíam código VALE antes da criação do tipo VALE.
    // O backend já reconhece tipo OU código; normalizamos a representação lida
    // para que todos os PDVs disparem o seletor de vales da mesma forma.
    foreach ($rows as &$row) {
      $paymentType = strtoupper(trim((string)($row['type'] ?? '')));
      $paymentCode = strtoupper(trim((string)($row['code'] ?? '')));
      if ($paymentType === 'VALE' || $paymentCode === 'VALE') {
        $row['type'] = 'VALE';
      }
    }
    unset($row);

    $cre = finance_settings($pdo, $companyId);
    if (!empty($cre['cre_rules_enabled'])) {
      foreach ($rows as &$row) {
        if (empty($row['generate_receivable'])) continue;
        $row['first_due_days'] = (int)$cre['cre_first_due_days'];
        $row['keep_due_day'] = !empty($cre['cre_keep_month_due_day']) ? 1 : 0;
        $row['cre_installment_period_days'] = (int)$cre['cre_installment_period_days'];
        $row['cre_fixed_due_day'] = $cre['cre_fixed_due_day'];
        $row['cre_rules_enabled'] = 1;
      }
      unset($row);
    }
    return $rows;
  }
}


if (!function_exists('gestao_customer_rows')) {
  function gestao_customer_rows(PDO $pdo, int $companyId): array {
    return finance_query_all($pdo, "SELECT id, numero_cadastro, name, fantasy_name, document, state_registration, municipal_registration, person_type, email, phone, whatsapp, zip_code, address, number, complement, district, city, city_ibge, state, birth_opening_date, credit_limit FROM finance_people WHERE company_id = ? AND active = 1 AND is_customer = 1 ORDER BY COALESCE(numero_cadastro, id) ASC, id ASC", [$companyId]);
  }
}

if (!function_exists('gestao_customer_addresses')) {
  function gestao_customer_addresses(PDO $pdo, int $companyId, ?int $personId = null): array {
    if (!finance_table_exists($pdo, 'gestao_customer_addresses')) gestao_ensure_schema($pdo);
    $where = ['company_id = ?', 'active = 1'];
    $params = [$companyId];
    if ($personId !== null && $personId > 0) { $where[] = 'person_id = ?'; $params[] = $personId; }
    return finance_query_all($pdo, 'SELECT * FROM gestao_customer_addresses WHERE ' . implode(' AND ', $where) . ' ORDER BY is_default DESC, id ASC', $params);
  }
}

if (!function_exists('gestao_create_customer_address')) {
  function gestao_create_customer_address(PDO $pdo, int $companyId, int $personId, array $data, bool $makeDefault = false): int {
    if ($personId <= 0) throw new RuntimeException('Cliente inválido para cadastrar endereço.');
    if (!finance_table_exists($pdo, 'gestao_customer_addresses')) gestao_ensure_schema($pdo);
    $district = trim((string)($data['district'] ?? ''));
    $address = gestao_clean_address_with_district((string)($data['address'] ?? ''), $district);
    if ($address === '' && $district === '') throw new RuntimeException('Informe o endereço ou bairro.');
    if ($makeDefault) {
      $pdo->prepare('UPDATE gestao_customer_addresses SET is_default = 0, updated_at = NOW() WHERE company_id = ? AND person_id = ?')->execute([$companyId, $personId]);
    }
    $label = trim((string)($data['label'] ?? '')) ?: ($makeDefault ? 'Principal' : 'Entrega');
    $st = $pdo->prepare('INSERT INTO gestao_customer_addresses (company_id, person_id, label, zip_code, address, number, complement, reference, district, city, city_ibge, state, is_default, active) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 1)');
    $st->execute([
      $companyId,
      $personId,
      $label,
      preg_replace('/\D+/', '', (string)($data['zip_code'] ?? '')) ?: null,
      $address ?: null,
      trim((string)($data['number'] ?? '')) ?: null,
      trim((string)($data['complement'] ?? '')) ?: null,
      trim((string)($data['reference'] ?? $data['referencia'] ?? '')) ?: null,
      $district ?: null,
      trim((string)($data['city'] ?? '')) ?: null,
      preg_replace('/\D+/', '', (string)($data['city_ibge'] ?? '')) ?: null,
      strtoupper(substr(trim((string)($data['state'] ?? '')), 0, 2)) ?: null,
      $makeDefault ? 1 : 0,
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('gestao_create_customer')) {
  function gestao_create_customer(PDO $pdo, int $companyId, array $data): array {
    $name = trim((string)($data['name'] ?? ''));
    $document = preg_replace('/\D+/', '', (string)($data['document'] ?? ''));
    $phone = preg_replace('/\D+/', '', (string)($data['phone'] ?? ''));
    $whatsapp = preg_replace('/\D+/', '', (string)($data['whatsapp'] ?? ''));
    $personType = strtoupper(trim((string)($data['person_type'] ?? 'FISICA'))) === 'JURIDICA' ? 'JURIDICA' : 'FISICA';
    $stateRegistrationStatus = strtoupper(trim((string)($data['state_registration_status'] ?? 'AUTO')));
    if (!in_array($stateRegistrationStatus, ['AUTO','CONTRIBUINTE','ISENTO','NAO_CONTRIBUINTE'], true)) $stateRegistrationStatus = 'AUTO';
    $stateRegistrationRaw = strtoupper(trim((string)($data['state_registration'] ?? '')));
    if ($stateRegistrationRaw !== '' && $stateRegistrationRaw !== 'ISENTO') {
      $stateRegistrationRaw = preg_replace('/[^0-9A-Z]+/', '', $stateRegistrationRaw) ?: '';
    }
    if ($stateRegistrationStatus === 'CONTRIBUINTE' && ($stateRegistrationRaw === '' || $stateRegistrationRaw === 'ISENTO')) {
      throw new RuntimeException('Informe a Inscrição Estadual para cliente contribuinte de ICMS.');
    }
    $stateRegistration = $stateRegistrationStatus === 'ISENTO' ? 'ISENTO'
      : ($stateRegistrationStatus === 'NAO_CONTRIBUINTE' ? '' : $stateRegistrationRaw);
    $municipalRegistration = trim((string)($data['municipal_registration'] ?? ''));
    $district = trim((string)($data['district'] ?? ''));
    $address = gestao_clean_address_with_district((string)($data['address'] ?? ''), $district);
    if ($name === '') throw new RuntimeException('Informe o nome do cliente.');
    if ($document !== '') {
      $existing = finance_query_one($pdo, 'SELECT id, state_registration, municipal_registration FROM finance_people WHERE company_id = ? AND document = ? AND active = 1 LIMIT 1', [$companyId, $document]);
      if ($existing) {
        $personId = (int)$existing['id'];
        // O cadastro rápido não apaga IE/IM já existentes quando os campos fiscais
        // não forem informados. Situações explícitas substituem a IE corretamente.
        $stateRegistrationForUpdate = ($stateRegistrationStatus === 'AUTO' && $stateRegistration === '')
          ? (string)($existing['state_registration'] ?? '') : $stateRegistration;
        $municipalRegistrationForUpdate = $municipalRegistration !== ''
          ? $municipalRegistration : (string)($existing['municipal_registration'] ?? '');
        $pdo->prepare('UPDATE finance_people SET is_customer = 1, name = COALESCE(NULLIF(?, \'\'), name), fantasy_name = ?, person_type = ?, state_registration = ?, municipal_registration = ?, birth_opening_date = ?, email = ?, phone = ?, whatsapp = ?, zip_code = ?, address = ?, number = ?, complement = ?, district = ?, city = ?, city_ibge = ?, state = ?, updated_at = NOW() WHERE company_id = ? AND id = ?')->execute([
          $name,
          trim((string)($data['fantasy_name'] ?? '')) ?: null,
          $personType,
          $stateRegistrationForUpdate !== '' ? $stateRegistrationForUpdate : null,
          $municipalRegistrationForUpdate !== '' ? $municipalRegistrationForUpdate : null,
          trim((string)($data['birth_opening_date'] ?? '')) ?: null,
          trim((string)($data['email'] ?? '')) ?: null,
          $phone ?: null,
          $whatsapp ?: $phone ?: null,
          preg_replace('/\D+/', '', (string)($data['zip_code'] ?? '')) ?: null,
          $address ?: null,
          trim((string)($data['number'] ?? '')) ?: null,
          trim((string)($data['complement'] ?? '')) ?: null,
          $district ?: null,
          trim((string)($data['city'] ?? '')) ?: null,
          preg_replace('/\D+/', '', (string)($data['city_ibge'] ?? '')) ?: null,
          strtoupper(substr(trim((string)($data['state'] ?? '')), 0, 2)) ?: null,
          $companyId,
          $personId,
        ]);
      } else {
        $personId = 0;
      }
    } else {
      $personId = 0;
    }
    if ($personId <= 0) {
      if (!$pdo->inTransaction()) company_sequence_ensure_schema($pdo, false);
      $cols = ['company_id'];
      $vals = ['?'];
      $params = [$companyId];
      if (company_sequence_has_column($pdo, 'pessoas')) {
        $cols[] = 'numero_cadastro';
        $vals[] = '?';
        $params[] = company_sequence_next($pdo, $companyId, 'pessoas');
      }
      $insertData = [
        'name' => $name,
        'fantasy_name' => trim((string)($data['fantasy_name'] ?? '')) ?: null,
        'document' => $document ?: null,
        'state_registration' => $stateRegistration !== '' ? $stateRegistration : null,
        'municipal_registration' => $municipalRegistration !== '' ? $municipalRegistration : null,
        'person_type' => $personType,
        'birth_opening_date' => trim((string)($data['birth_opening_date'] ?? '')) ?: null,
        'email' => trim((string)($data['email'] ?? '')) ?: null,
        'phone' => $phone ?: null,
        'whatsapp' => $whatsapp ?: $phone ?: null,
        'zip_code' => preg_replace('/\D+/', '', (string)($data['zip_code'] ?? '')) ?: null,
        'address' => $address ?: null,
        'number' => trim((string)($data['number'] ?? '')) ?: null,
        'complement' => trim((string)($data['complement'] ?? '')) ?: null,
        'district' => $district ?: null,
        'city' => trim((string)($data['city'] ?? '')) ?: null,
        'city_ibge' => preg_replace('/\D+/', '', (string)($data['city_ibge'] ?? '')) ?: null,
        'state' => strtoupper(substr(trim((string)($data['state'] ?? '')), 0, 2)) ?: null,
        'is_customer' => 1,
        'active' => 1,
      ];
      foreach ($insertData as $col => $value) {
        $cols[] = $col;
        $vals[] = '?';
        $params[] = $value;
      }
      $st = $pdo->prepare('INSERT INTO finance_people (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
      $st->execute($params);
      $personId = (int)$pdo->lastInsertId();
    }
    if ($address !== '' || $district !== '') {
      gestao_create_customer_address($pdo, $companyId, $personId, $data + ['label' => 'Principal', 'address' => $address, 'district' => $district], true);
    }
    $customer = finance_query_one($pdo, 'SELECT id, numero_cadastro, name, fantasy_name, document, state_registration, municipal_registration, person_type, email, phone, whatsapp, zip_code, address, number, complement, district, city, city_ibge, state, birth_opening_date FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $personId]);
    return ['customer' => $customer ?: [], 'addresses' => gestao_customer_addresses($pdo, $companyId, $personId)];
  }
}

if (!function_exists('gestao_payment_method')) {
  function gestao_payment_method(PDO $pdo, int $companyId, int $id): ?array {
    $row = finance_query_one($pdo, 'SELECT * FROM finance_payment_methods WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $id]);
    if (!$row) return null;
    $cre = finance_settings($pdo, $companyId);
    if (!empty($cre['cre_rules_enabled']) && !empty($row['generate_receivable'])) {
      $row['first_due_days'] = (int)$cre['cre_first_due_days'];
      $row['keep_due_day'] = !empty($cre['cre_keep_month_due_day']) ? 1 : 0;
      $row['cre_installment_period_days'] = (int)$cre['cre_installment_period_days'];
      $row['cre_fixed_due_day'] = $cre['cre_fixed_due_day'];
      $row['cre_rules_enabled'] = 1;
    }
    return $row;
  }
}

if (!function_exists('gestao_cash_is_multi_company_cross')) {
  function gestao_cash_is_multi_company_cross(PDO $pdo, int $companyId): bool {
    if (!function_exists('multi_company_scope_is_cross_company') || !function_exists('multi_company_operational_company_id')) return false;
    try {
      return multi_company_scope_is_cross_company($pdo, 'sales_cash')
        && (int)multi_company_operational_company_id($pdo, 'sales_cash') === $companyId;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('gestao_cross_cash_preference')) {
  function gestao_cross_cash_preference(int $companyId, ?int $setBankId = null): int {
    if (session_status() !== PHP_SESSION_ACTIVE) { @session_start(); }
    if (!isset($_SESSION['nexa_multi_company_cash_default']) || !is_array($_SESSION['nexa_multi_company_cash_default'])) {
      $_SESSION['nexa_multi_company_cash_default'] = [];
    }
    if ($setBankId !== null) {
      if ($setBankId > 0) $_SESSION['nexa_multi_company_cash_default'][$companyId] = $setBankId;
      else unset($_SESSION['nexa_multi_company_cash_default'][$companyId]);
    }
    return (int)($_SESSION['nexa_multi_company_cash_default'][$companyId] ?? 0);
  }
}

if (!function_exists('gestao_user_default_cash_account')) {
  function gestao_user_default_cash_account(PDO $pdo, int $companyId, int $userId, string $context = 'financeiro'): int {
    if (gestao_cash_is_multi_company_cross($pdo, $companyId)) {
      $preferred = gestao_cross_cash_preference($companyId);
      if ($preferred > 0) {
        $row = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1', [$companyId, $preferred]);
        if ($row) return $preferred;
      }
      $session = $userId > 0 ? gestao_open_cash_session($pdo, $companyId, $userId) : null;
      $sessionBank = (int)($session['bank_account_id'] ?? 0);
      if ($sessionBank > 0) return $sessionBank;
      return gestao_default_bank_id($pdo, $companyId, 'CAIXA') ?: gestao_default_bank_id($pdo, $companyId, '');
    }
    if (function_exists('access_user_default_cash_account')) {
      $id = access_user_default_cash_account($pdo, $companyId, $userId, $context);
      if ($id > 0) return $id;
    }
    if ($userId > 0 && finance_table_exists($pdo, 'users') && finance_column_exists($pdo, 'users', 'default_cash_bank_account_id')) {
      try {
        $st = $pdo->prepare('SELECT default_cash_bank_account_id FROM users WHERE id = ? LIMIT 1');
        $st->execute([$userId]);
        $id = (int)($st->fetchColumn() ?: 0);
        if ($id > 0) {
          $chk = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1', [$companyId, $id]);
          if ($chk && (!function_exists('access_can_use_cash_account') || access_can_use_cash_account($pdo, $companyId, $id, $userId))) return $id;
        }
      } catch (Throwable $e) {}
    }
    $fallback = gestao_default_bank_id($pdo, $companyId, 'CAIXA') ?: gestao_default_bank_id($pdo, $companyId, '');
    return (!function_exists('access_can_use_cash_account') || access_can_use_cash_account($pdo, $companyId, $fallback, $userId)) ? $fallback : 0;
  }
}

if (!function_exists('gestao_pdv_is_multi_company_cross')) {
  function gestao_pdv_is_multi_company_cross(PDO $pdo, int $companyId): bool {
    if (!function_exists('multi_company_scope_is_cross_company') || !function_exists('multi_company_operational_company_id')) return false;
    try {
      return multi_company_scope_is_cross_company($pdo, 'sales_pdv_order')
        && (int)multi_company_operational_company_id($pdo, 'sales_pdv_order') === $companyId;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('gestao_pdv_cash_account')) {
  function gestao_pdv_cash_account(PDO $pdo, int $companyId, int $userId, int $preferredId = 0): int {
    if (gestao_pdv_is_multi_company_cross($pdo, $companyId)) {
      if ($preferredId > 0) {
        $row = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1', [$companyId, $preferredId]);
        if ($row) return $preferredId;
      }
      $session = $userId > 0 ? gestao_open_cash_session($pdo, $companyId, $userId) : null;
      $sessionBank = (int)($session['bank_account_id'] ?? 0);
      if ($sessionBank > 0) return $sessionBank;
      $preferred = gestao_cross_cash_preference($companyId);
      if ($preferred > 0) {
        $row = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1', [$companyId, $preferred]);
        if ($row) return $preferred;
      }
      return gestao_default_bank_id($pdo, $companyId, 'CAIXA') ?: gestao_default_bank_id($pdo, $companyId, '');
    }
    return $preferredId > 0 ? $preferredId : gestao_user_default_cash_account($pdo, $companyId, $userId, 'pdv');
  }
}

if (!function_exists('gestao_pdv_assert_cash_account')) {
  function gestao_pdv_assert_cash_account(PDO $pdo, int $companyId, int $bankId, string $message): void {
    if ($bankId <= 0) throw new RuntimeException('Nenhum Caixa/Banco ativo foi encontrado para esta empresa.');
    if (gestao_pdv_is_multi_company_cross($pdo, $companyId)) {
      $row = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1', [$companyId, $bankId]);
      if (!$row) throw new RuntimeException('Caixa/Banco inválido para a empresa selecionada pelo F11.');
      $operatorId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      if ($operatorId > 0) nexa_cash_assert_operator($pdo,$companyId,$bankId,$operatorId);
      return;
    }
    if (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $companyId, $bankId, $message);
    $operatorId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    if ($operatorId > 0) nexa_cash_assert_operator($pdo,$companyId,$bankId,$operatorId);
  }
}

if (!function_exists('gestao_set_user_default_cash_account')) {
  function gestao_set_user_default_cash_account(PDO $pdo, int $companyId, int $userId, int $bankId): void {
    if ($userId <= 0 || $bankId <= 0) return;
    $chk = finance_query_one($pdo, 'SELECT id FROM finance_bank_accounts WHERE company_id = ? AND id = ? AND active = 1', [$companyId, $bankId]);
    if (!$chk) throw new RuntimeException('Caixa/Banco inválido para esta empresa.');
    if (gestao_cash_is_multi_company_cross($pdo, $companyId)) {
      gestao_cross_cash_preference($companyId, $bankId);
      return;
    }
    if (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $companyId, $bankId);
    if (!finance_column_exists($pdo, 'users', 'default_cash_bank_account_id')) {
      $errors = []; finance_add_column_if_missing($pdo, 'users', 'default_cash_bank_account_id', 'INT NULL', $errors);
      finance_add_column_if_missing($pdo, 'users', 'default_pdv_cash_bank_account_id', 'INT NULL', $errors);
      finance_add_column_if_missing($pdo, 'users', 'default_comanda_cash_bank_account_id', 'INT NULL', $errors);
    }
    $pdo->prepare('UPDATE users SET default_cash_bank_account_id = ? WHERE id = ?')->execute([$bankId, $userId]);
  }
}

if (!function_exists('gestao_open_cash_session')) {
  function gestao_open_cash_session(PDO $pdo, int $companyId, int $userId): ?array {
    return finance_query_one($pdo, 'SELECT * FROM gestao_cash_sessions WHERE company_id = ? AND user_id = ? AND status = \'ABERTO\' ORDER BY id DESC LIMIT 1', [$companyId, $userId]);
  }
}

if (!function_exists('gestao_cash_summary')) {
  function gestao_cash_summary(PDO $pdo, int $companyId, int $sessionId): array {
    $ops = finance_query_all($pdo, 'SELECT type, SUM(amount) total FROM gestao_cash_operations WHERE company_id = ? AND cash_session_id = ? GROUP BY type', [$companyId, $sessionId]);
    $byType = [];
    foreach ($ops as $r) $byType[(string)$r['type']] = (float)$r['total'];
    $payments = finance_query_all($pdo, "SELECT COALESCE(gop.payment_label, pm.name, 'Sem forma') AS name, SUM(gop.amount) AS total FROM gestao_order_payments gop LEFT JOIN finance_payment_methods pm ON pm.id = gop.payment_method_id AND pm.company_id = gop.company_id LEFT JOIN pedidos pe ON pe.company_id=gop.company_id AND pe.id=gop.order_id WHERE gop.company_id = ? AND gop.cash_session_id = ? AND LOWER(COALESCE(pe.status,''))<>'cancelado' GROUP BY gop.payment_label, pm.name ORDER BY name", [$companyId, $sessionId]);
    // Para o quadro de recebimentos POR ORIGEM, nao misturar venda a prazo
    // (que gerou titulo C/R) com pagamento imediato no PDV.
    $cashSalesPayments = finance_query_all($pdo, "SELECT COALESCE(gop.payment_label,pm.name,'Sem forma') AS name, SUM(gop.amount) AS total
      FROM gestao_order_payments gop
      JOIN finance_payment_methods pm ON pm.id=gop.payment_method_id AND pm.company_id=gop.company_id
      JOIN pedidos pe ON pe.company_id=gop.company_id AND pe.id=gop.order_id
      WHERE gop.company_id=? AND gop.cash_session_id=? AND gop.finance_movement_id IS NOT NULL
        AND pm.affects_cash=1
      GROUP BY gop.payment_label,pm.name ORDER BY name", [$companyId, $sessionId]);
    // Fontes efetivadas: vendas diretas, orcamentos convertidos e O.S. faturadas.
    // Orcamento nao convertido e O.S. pendente NAO sao recebimentos de caixa.
    // A classificação usa pedidos efetivados e a forma efetivamente recebida.
    $quotePredicate = finance_table_exists($pdo, 'orcamentos') && finance_column_exists($pdo, 'orcamentos', 'converted_order_id')
      ? 'EXISTS (SELECT 1 FROM orcamentos q WHERE q.company_id=pe.company_id AND q.converted_order_id=pe.id)'
      : '0=1';
    // A categoria e calculada por pedido ANTES da agregacao. No MySQL com
    // ONLY_FULL_GROUP_BY, GROUP BY name pode resolver para pm.name, fazendo
    // pe.source parecer uma coluna nao agrupada (SQLSTATE[42000] / 1055).
    // A subconsulta evita ambiguidade entre o alias e a coluna pm.name.
    // O detalhamento por origem e informativo e nao altera o saldo do caixa.
    $salesByOrigin = [];
    try {
      $salesByOrigin = finance_query_all($pdo, "SELECT classificado.origem AS name,
          SUM(classificado.amount) AS total
        FROM (
          SELECT CASE
              WHEN pe.source IN ('ordem_servico','ordem_servico_lote') THEN 'O.S. faturadas'
              WHEN {$quotePredicate} THEN 'Orçamentos convertidos'
              ELSE 'Pedidos e vendas'
            END AS origem, gop.amount AS amount
          FROM gestao_order_payments gop
          JOIN finance_payment_methods pm ON pm.id=gop.payment_method_id AND pm.company_id=gop.company_id
          JOIN pedidos pe ON pe.company_id=gop.company_id AND pe.id=gop.order_id
          WHERE gop.company_id=? AND gop.cash_session_id=? AND gop.finance_movement_id IS NOT NULL
            AND pm.affects_cash=1
        ) AS classificado
        GROUP BY classificado.origem ORDER BY classificado.origem", [$companyId, $sessionId]);
    } catch (PDOException $e) {
      // Uma falha neste quadro auxiliar nao deve impedir a abertura do caixa.
      error_log('Nexa Caixa: falha na classificacao informativa de origens: ' . $e->getMessage());
    }
    $opening = (float)($byType['ABERTURA'] ?? 0);
    $supp = (float)($byType['SUPRIMENTO'] ?? 0);
    $sangria = (float)($byType['SANGRIA'] ?? 0);
    $sales = (float)($byType['VENDA'] ?? 0);
    $reversals = (float)($byType['ESTORNO'] ?? 0);
    $receiptsCr = (float)($byType['RECEBIMENTO_CR'] ?? 0);
    $reversalsCr = (float)($byType['ESTORNO_CR'] ?? 0);
    $crMethods = [];
    if (function_exists('nexa_cash_cr_schema_ready') && nexa_cash_cr_schema_ready($pdo)) {
      // Cada parcela de baixa aponta para um pagamento unico; movimentos financeiros
      // compartilhados em baixas multiplas nao sao somados duas vezes.
      $crMethods = finance_query_all($pdo, "SELECT COALESCE(NULLIF(sp.payment_method,''),pm.name,'Sem forma') AS name,
          SUM(CASE WHEN o.type='RECEBIMENTO_CR' THEN o.amount ELSE -o.amount END) AS total
        FROM gestao_cash_receivable_links l
        JOIN gestao_cash_operations o ON o.company_id=l.company_id
          AND (o.id=l.cash_operation_id OR o.id=l.reversal_cash_operation_id)
        LEFT JOIN finance_settlement_payments sp ON sp.company_id=l.company_id AND sp.id=l.settlement_payment_id
        LEFT JOIN finance_payment_methods pm ON pm.company_id=sp.company_id AND pm.id=sp.payment_method_id
        WHERE l.company_id=? AND o.cash_session_id=? AND o.type IN ('RECEBIMENTO_CR','ESTORNO_CR')
        GROUP BY COALESCE(NULLIF(sp.payment_method,''),pm.name,'Sem forma') ORDER BY name", [$companyId, $sessionId]);
    }
    $expected = $opening + $supp + $sales + $receiptsCr - $reversalsCr - $sangria - $reversals;
    return ['opening'=>$opening, 'suprimento'=>$supp, 'sangria'=>$sangria,
      'sales'=>$sales, 'estorno'=>$reversals, 'cr_receipts'=>$receiptsCr,
      'cr_reversals'=>$reversalsCr, 'cr_net'=>$receiptsCr-$reversalsCr,
      'cr_payments'=>$crMethods, 'cash_sales_payments'=>$cashSalesPayments, 'sales_by_origin'=>$salesByOrigin,
      'expected'=>$expected, 'payments'=>$payments, 'by_type'=>$byType];
  }
}

if (!function_exists('gestao_create_cash_operation')) {
  function gestao_create_cash_operation(PDO $pdo, int $companyId, int $userId, ?int $sessionId, string $type, float $amount, ?int $bankId, ?int $chartId, ?int $costId, string $description, string $notes = '', ?int $orderId = null): int {
    $type = strtoupper($type);
    $movementId = null;
    if ($amount > 0 && $chartId) {
      $movType = in_array($type, ['SANGRIA'], true) ? 'DEBITO' : 'CREDITO';
      $st = $pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes) VALUES (?, CURDATE(), ?, NULL, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
      $st->execute([$companyId, $movType, $chartId, $costId ?: null, $bankId ?: null, 'CAIXA_' . $type, $sessionId, $description, $orderId ? (string)$orderId : '', $amount, $notes !== '' ? $notes : null]);
      $movementId = (int)$pdo->lastInsertId();
    }
    $st = $pdo->prepare('INSERT INTO gestao_cash_operations (company_id, cash_session_id, user_id, type, amount, bank_account_id, chart_account_id, cost_center_id, movement_id, order_id, description, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([$companyId, $sessionId, $userId ?: null, $type, $amount, $bankId ?: null, $chartId ?: null, $costId ?: null, $movementId, $orderId, $description, $notes !== '' ? $notes : null]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('gestao_pedido_obs_column')) {
  function gestao_pedido_obs_column(PDO $pdo): string {
    foreach (['observacoes','observacao','obs'] as $c) if (finance_column_exists($pdo, 'pedidos', $c)) return $c;
    return 'observacoes';
  }
}

if (!function_exists('gestao_pedido_item_obs_column')) {
  function gestao_pedido_item_obs_column(PDO $pdo): ?string {
    if (!finance_table_exists($pdo, 'pedido_itens')) return null;

    // Detecta o nome real da coluna de forma case-insensitive.
    // Em algumas bases a coluna foi criada como COMPLEMENTO, em outras como complemento.
    $candidates = ['complemento','observacao','observacoes','obs','adicionais','observacao_item'];
    try {
      $st = $pdo->query('SHOW COLUMNS FROM `pedido_itens`');
      foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $field = (string)($row['Field'] ?? '');
        foreach ($candidates as $candidate) {
          if (mb_strtolower($field) === mb_strtolower($candidate)) {
            return $field;
          }
        }
      }
    } catch (Throwable $e) {}

    // Se não existir nenhuma coluna de observação, o F11 não altera schema em runtime.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return null;
    try {
      $pdo->exec('ALTER TABLE `pedido_itens` ADD COLUMN `complemento` TEXT NULL AFTER `total`');
      return 'complemento';
    } catch (Throwable $e) {
      try {
        $st = $pdo->query('SHOW COLUMNS FROM `pedido_itens`');
        foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
          $field = (string)($row['Field'] ?? '');
          if (mb_strtolower($field) === 'complemento') return $field;
        }
      } catch (Throwable $e2) {}
    }

    return null;
  }
}


if (!function_exists('gestao_table_columns')) {
  function gestao_table_columns(PDO $pdo, string $table): array {
    if (!finance_sql_identifier_ok($table)) return [];
    $cols = [];
    try {
      $st = $pdo->query('SHOW COLUMNS FROM `' . $table . '`');
      foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
        $name = (string)($row['Field'] ?? '');
        if ($name !== '') $cols[strtolower($name)] = $name;
      }
    } catch (Throwable $e) {}

    // Fallback para servidores/usuários MySQL em que SHOW COLUMNS pode falhar.
    if (!$cols) {
      try {
        $st = $pdo->query('SELECT * FROM `' . $table . '` LIMIT 0');
        if ($st) {
          $count = $st->columnCount();
          for ($i = 0; $i < $count; $i++) {
            $meta = $st->getColumnMeta($i);
            $name = (string)($meta['name'] ?? '');
            if ($name !== '') $cols[strtolower($name)] = $name;
          }
        }
      } catch (Throwable $e) {}
    }
    return $cols;
  }
}

if (!function_exists('gestao_insert_pedido_dynamic')) {
  function gestao_insert_pedido_dynamic(PDO $pdo, array $data): int {
    if (!finance_table_exists($pdo, 'pedidos')) {
      throw new RuntimeException('Tabela de pedidos não encontrada. Rode o patch/base do Delivery antes de usar o PDV.');
    }

    if (!$pdo->inTransaction()) company_sequence_ensure_schema($pdo, false);
    $available = gestao_table_columns($pdo, 'pedidos');
    $companyId = (int)($data['company_id'] ?? 0);
    if ($companyId > 0 && isset($available['numero_pedido']) && !array_key_exists('numero_pedido', $data)) {
      $data['numero_pedido'] = company_sequence_next($pdo, $companyId, 'pedidos');
    }
    $cols = [];
    $vals = [];

    foreach ($data as $col => $val) {
      $key = strtolower((string)$col);
      if (isset($available[$key])) {
        $cols[] = $available[$key];
        $vals[] = $val;
      }
    }

    // Fallback para bases antigas ou servidores em que a leitura de metadados falhe.
    // Usa a mesma estrutura padrão já utilizada pelo checkout do Delivery.
    if (!$cols) {
      $fallbackCols = ['company_id','nome_cliente','whatsapp','endereco','tipo_entrega','taxa_entrega','forma_pagamento','observacoes','total_produtos','total','status'];
      foreach ($fallbackCols as $col) {
        if (array_key_exists($col, $data)) {
          $cols[] = $col;
          $vals[] = $data[$col];
        }
      }
    }

    if (!$cols) {
      throw new RuntimeException('Estrutura da tabela de pedidos não encontrada. Verifique se a tabela pedidos possui as colunas padrão do Delivery.');
    }

    $quoted = array_map(function($c){ return '`' . str_replace('`', '``', (string)$c) . '`'; }, $cols);
    $ph = implode(',', array_fill(0, count($cols), '?'));

    try {
      $pdo->prepare('INSERT INTO `pedidos` (' . implode(',', $quoted) . ') VALUES (' . $ph . ')')->execute($vals);
    } catch (Throwable $e) {
      // Segunda tentativa: somente colunas essenciais do checkout, para evitar falha por coluna opcional divergente.
      $essential = ['company_id','nome_cliente','whatsapp','endereco','tipo_entrega','taxa_entrega','forma_pagamento','observacoes','total_produtos','total','status'];
      $cols2 = [];
      $vals2 = [];
      foreach ($essential as $col) {
        if (array_key_exists($col, $data)) { $cols2[] = $col; $vals2[] = $data[$col]; }
      }
      if (!$cols2) throw $e;
      $quoted2 = array_map(function($c){ return '`' . str_replace('`', '``', (string)$c) . '`'; }, $cols2);
      $ph2 = implode(',', array_fill(0, count($cols2), '?'));
      $pdo->prepare('INSERT INTO `pedidos` (' . implode(',', $quoted2) . ') VALUES (' . $ph2 . ')')->execute($vals2);
    }

    $newOrderId = (int)$pdo->lastInsertId();
    if ($newOrderId <= 0) {
      throw new RuntimeException('Não foi possível confirmar o ID do pedido gravado.');
    }

    // V11.25.1: fail closed multiempresa. O pedido recém-gravado precisa pertencer
    // exatamente à empresa operacional usada pelo PDV; nunca continuamos o fluxo
    // com um pedido salvo em tenant divergente.
    $savedCompany = finance_query_one($pdo, 'SELECT company_id FROM pedidos WHERE id = ? LIMIT 1', [$newOrderId]);
    if (!$savedCompany || (int)($savedCompany['company_id'] ?? 0) !== $companyId) {
      throw new RuntimeException('Falha de isolamento Multiempresa ao gravar o pedido. A venda foi cancelada por segurança.');
    }

    return $newOrderId;
  }
}


if (!function_exists('gestao_table_column_string_limit')) {
  function gestao_table_column_string_limit(PDO $pdo, string $table, string $column): ?int {
    if (!finance_sql_identifier_ok($table) || !finance_sql_identifier_ok($column)) return null;
    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      $type = strtolower((string)($row['Type'] ?? ''));
      if (preg_match('/^(?:var)?char\((\d+)\)/i', $type, $m)) return max(1, (int)$m[1]);
    } catch (Throwable $e) {}

    try {
      $db = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
      if ($db !== '') {
        $st = $pdo->prepare('SELECT CHARACTER_MAXIMUM_LENGTH FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
        $st->execute([$db, $table, $column]);
        $len = $st->fetchColumn();
        if ($len !== false && $len !== null && (int)$len > 0) return (int)$len;
      }
    } catch (Throwable $e) {}
    return null;
  }
}

if (!function_exists('gestao_text_fit_length')) {
  function gestao_text_fit_length(string $value, ?int $maxLength): string {
    $value = trim($value);
    if (!$maxLength || $maxLength <= 0 || mb_strlen($value, 'UTF-8') <= $maxLength) return $value;
    if ($maxLength <= 3) return mb_substr($value, 0, $maxLength, 'UTF-8');
    return rtrim(mb_substr($value, 0, $maxLength - 3, 'UTF-8')) . '...';
  }
}

if (!function_exists('gestao_payment_short_name')) {
  function gestao_payment_short_name(array $payment): string {
    $type = strtoupper(trim((string)($payment['type'] ?? '')));
    $code = strtoupper(trim((string)($payment['code'] ?? '')));
    $name = trim((string)($payment['name'] ?? ''));
    if ($type === 'DINHEIRO' || $code === 'DINHEIRO') return 'Dinheiro';
    if ($type === 'PIX' || $code === 'PIX') return 'Pix';
    if ($type === 'CARTAO' || str_contains($code, 'CART')) return 'Cartão';
    if ($type === 'PRAZO' || str_contains($code, 'PRAZO')) return 'A prazo';
    return $name !== '' ? $name : 'Pagamento';
  }
}

if (!function_exists('gestao_payment_label_for_pedidos')) {
  function gestao_payment_label_for_pedidos(PDO $pdo, array $payments, string $fullLabel): string {
    $limit = gestao_table_column_string_limit($pdo, 'pedidos', 'forma_pagamento');
    if (!$limit || mb_strlen($fullLabel, 'UTF-8') <= $limit) return $fullLabel;

    $shorts = [];
    foreach ($payments as $line) {
      $payment = is_array($line['payment'] ?? null) ? $line['payment'] : [];
      $short = gestao_payment_short_name($payment);
      if ($short !== '' && !in_array($short, $shorts, true)) $shorts[] = $short;
    }

    $shortLabel = implode('+', $shorts);
    if ($shortLabel !== '' && mb_strlen($shortLabel, 'UTF-8') <= $limit) return $shortLabel;

    $compact = count($payments) > 1 ? 'Múltiplo' : ($shorts[0] ?? $fullLabel);
    return gestao_text_fit_length($compact, $limit);
  }
}

if (!function_exists('gestao_add_months_keep_day')) {
  function gestao_add_months_keep_day(DateTimeImmutable $date, int $months, int $day): DateTimeImmutable {
    $year = (int)$date->format('Y');
    $month = (int)$date->format('n') + $months;
    while ($month > 12) { $month -= 12; $year++; }
    while ($month < 1) { $month += 12; $year--; }
    $lastDay = (int)(new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month)))->format('t');
    $day = max(1, min($day, $lastDay));
    return new DateTimeImmutable(sprintf('%04d-%02d-%02d', $year, $month, $day));
  }
}

if (!function_exists('gestao_default_installment_schedule')) {
  function gestao_default_installment_schedule(array $payment, float $amount, ?string $baseDate = null): array {
    $amount = round($amount, 2);
    $count = max(1, min(120, (int)($payment['default_installments'] ?? 1)));
    if (empty($payment['allow_installments'])) $count = 1;
    $amountCents = (int)round($amount * 100);
    $baseCents = intdiv($amountCents, $count);
    $remainder = $amountCents - ($baseCents * $count);
    $settings = [
      'cre_rules_enabled' => !empty($payment['cre_rules_enabled']) ? 1 : 0,
      'cre_first_due_days' => max(0, min(365, (int)($payment['first_due_days'] ?? 30))),
      'cre_installment_period_days' => max(1, min(365, (int)($payment['cre_installment_period_days'] ?? 30))),
      'cre_keep_month_due_day' => !empty($payment['keep_due_day']) ? 1 : 0,
      'cre_fixed_due_day' => isset($payment['cre_fixed_due_day']) ? $payment['cre_fixed_due_day'] : null,
    ];
    $dates = finance_cre_due_dates($settings, $count, $baseDate ?: date('Y-m-d'));
    $rows = [];
    for ($i = 0; $i < $count; $i++) {
      $cents = $baseCents + ($i === $count - 1 ? $remainder : 0);
      $rows[] = ['due_date' => $dates[$i] ?? date('Y-m-d'), 'amount' => round($cents / 100, 2)];
    }
    return $rows;
  }
}

if (!function_exists('gestao_normalize_installment_schedule')) {
  function gestao_normalize_installment_schedule(array $payment, float $amount, array $post, string $lineKey): array {
    $dueMatrix = $post['installment_due'] ?? [];
    $amountMatrix = $post['installment_amount'] ?? [];
    $dues = is_array($dueMatrix) && isset($dueMatrix[$lineKey]) && is_array($dueMatrix[$lineKey]) ? $dueMatrix[$lineKey] : [];
    $amounts = is_array($amountMatrix) && isset($amountMatrix[$lineKey]) && is_array($amountMatrix[$lineKey]) ? $amountMatrix[$lineKey] : [];
    $rows = [];
    $sum = 0.0;
    foreach ($dues as $idx => $dueRaw) {
      $due = finance_date_or_null($dueRaw);
      $val = finance_money_in($amounts[$idx] ?? 0);
      if (!$due && $val <= 0) continue;
      if (!$due) throw new RuntimeException('Informe o vencimento de todas as parcelas.');
      if ($val <= 0) throw new RuntimeException('Informe valor maior que zero para todas as parcelas.');
      $val = round($val, 2);
      $sum += $val;
      $rows[] = ['due_date' => $due, 'amount' => $val];
    }
    if (!$rows) $rows = gestao_default_installment_schedule($payment, $amount, date('Y-m-d'));
    $sum = round(array_reduce($rows, static fn($carry, $row) => $carry + (float)$row['amount'], 0.0), 2);
    if (abs($sum - round($amount, 2)) > 0.01) {
      throw new RuntimeException('A soma das parcelas deve fechar o valor da forma de pagamento. Total: ' . finance_money_br($amount) . ' / Parcelas: ' . finance_money_br($sum));
    }
    return $rows;
  }
}

if (!function_exists('gestao_payment_lines_from_post')) {
  function gestao_payment_lines_from_post(PDO $pdo, int $companyId, array $post, float $totalOrder): array {
    $idsRaw = $post['payment_method_id'] ?? [];
    $amountsRaw = $post['payment_amount'] ?? [];
    $receivedRaw = $post['payment_received'] ?? [];
    $lineKeysRaw = $post['payment_line_key'] ?? [];

    if (!is_array($idsRaw)) {
      $idsRaw = [(int)$idsRaw];
      $amountsRaw = [$post['payment_amount'] ?? $totalOrder];
      $receivedRaw = [$post['received_amount'] ?? $totalOrder];
      $lineKeysRaw = ['0'];
    }
    if (!is_array($lineKeysRaw)) $lineKeysRaw = [];

    $lines = [];
    $sum = 0.0;
    foreach ($idsRaw as $idx => $idRaw) {
      $paymentId = (int)$idRaw;
      if ($paymentId <= 0) continue;
      $payment = gestao_payment_method($pdo, $companyId, $paymentId);
      if (!$payment || (int)$payment['is_active'] !== 1) throw new RuntimeException('Forma de pagamento inválida.');

      $amount = finance_money_in($amountsRaw[$idx] ?? 0);
      if ($amount <= 0 && count($idsRaw) === 1) $amount = $totalOrder;
      if ($amount <= 0) continue;
      $received = finance_money_in($receivedRaw[$idx] ?? 0);
      if ($received <= 0 || empty($payment['allow_change'])) $received = $amount;
      $change = !empty($payment['allow_change']) ? max(0, round($received - $amount, 2)) : 0.0;
      $lineKey = preg_replace('/[^a-zA-Z0-9_\-]/', '', (string)($lineKeysRaw[$idx] ?? (string)$idx));
      if ($lineKey === '') $lineKey = (string)$idx;
      $voucherAllocations = [];
      $isVoucher = strtoupper((string)($payment['type'] ?? '')) === 'VALE' || strtoupper((string)($payment['code'] ?? '')) === 'VALE';
      if ($isVoucher) {
        require_once __DIR__ . '/voucher_module.php';
        voucher_assert_ready($pdo);
        $customerIdForVoucher = (int)($post['customer_id'] ?? 0);
        if ($customerIdForVoucher <= 0) throw new RuntimeException('Pagamento com Vale exige cliente cadastrado na venda.');
        $voucherAllocations = voucher_parse_allocations($post, $lineKey, $amount);
      }
      $cardPlan = (!$isVoucher && !empty($payment['card_control_enabled']))
        ? card_control_line_plan_from_post($pdo, $companyId, $payment, $post, $lineKey)
        : null;
      // Controle de Cartões possui agenda própria. Não cria Contas a Receber comum.
      $installments = (!$isVoucher && !$cardPlan && !empty($payment['generate_receivable'])) ? gestao_normalize_installment_schedule($payment, $amount, $post, $lineKey) : [];
      $cardMatrix = static function(string $field) use ($post, $lineKey): string {
        $m = $post[$field] ?? [];
        if (is_array($m)) return trim((string)($m[$lineKey] ?? ''));
        return $lineKey === '0' ? trim((string)$m) : '';
      };
      $sum += $amount;
      $lines[] = [
        'payment_id' => $paymentId,
        'payment' => $payment,
        'amount' => round($amount, 2),
        'received' => round($received, 2),
        'change' => $change,
        'installments' => $installments,
        'card_plan' => $cardPlan,
        // Campos reservados para TEF/adquirente. PDVs sem TEF deixam vazio; integrações futuras podem preenchê-los.
        'card_nsu' => $cardMatrix('card_nsu'),
        'card_authorization_code' => $cardMatrix('card_authorization_code'),
        'card_transaction_id' => $cardMatrix('card_transaction_id'),
        'card_terminal_id' => $cardMatrix('card_terminal_id'),
        'card_merchant_id' => $cardMatrix('card_merchant_id'),
        'voucher_allocations' => $voucherAllocations,
        'is_voucher' => $isVoucher,
      ];
    }

    if (!$lines) throw new RuntimeException('Informe pelo menos uma forma de pagamento.');
    if (abs(round($sum - $totalOrder, 2)) > 0.01) {
      throw new RuntimeException('A soma das formas de pagamento deve fechar o total do pedido. Total: ' . finance_money_br($totalOrder) . ' / Pagamentos: ' . finance_money_br($sum));
    }
    return $lines;
  }
}


if (!function_exists('gestao_split_order_address_message')) {
  function gestao_split_order_address_message(string $district, string $addressText): array {
    $district = trim($district);
    $addressText = str_replace(["\r\n", "\r"], "\n", trim($addressText));
    $reference = '';
    $lines = [];
    foreach (preg_split('/\n/u', $addressText) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      if (preg_match('/^Bairro:\s*(.+)$/iu', $line, $m)) {
        if ($district === '') {
          $district = trim((string)preg_replace('/\s+-\s+.*$/u', '', trim((string)$m[1])));
        }
        continue;
      }
      if (preg_match('/^(?:Ref\.?|Refer[eê]ncia):\s*(.+)$/iu', $line, $m)) {
        $reference = trim((string)$m[1]);
        continue;
      }
      $lines[] = $line;
    }
    $addressClean = gestao_clean_address_with_district(trim(implode("\n", $lines)), $district);
    return [$district, $addressClean, $reference];
  }
}

if (!function_exists('gestao_clean_address_line')) {
  function gestao_clean_address_line(string $value): string {
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return trim($value);
  }
}

if (!function_exists('gestao_clean_address_with_district')) {
  function gestao_clean_address_with_district(string $value, string $district = ''): string {
    $value = gestao_clean_address_line($value);
    $district = gestao_clean_address_line($district);
    if ($value === '') return '';
    $streetTypes = '(?:Rua|R\.?|Avenida|Av\.?|Alameda|Travessa|Tv\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
    if ($district !== '') {
      $districtQuoted = preg_quote($district, '/');
      $value = preg_replace('/^Bairro:\s*' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
      $value = preg_replace('/^' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
      if (mb_strtolower(gestao_clean_address_line($value)) === mb_strtolower($district)) return '';
    }
    $value = preg_replace('/^Bairro:\s*.*?(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
    $value = preg_replace('/^Bairro:\s*/iu', '', $value) ?? $value;
    $value = gestao_clean_address_line($value);
    if ($district !== '' && mb_strtolower($value) === mb_strtolower($district)) return '';
    return $value;
  }
}

if (!function_exists('gestao_append_address_part')) {
  function gestao_append_address_part(string $base, string $part, string $separator = ', '): string {
    $base = trim($base);
    $part = trim($part);
    if ($part === '') return $base;
    $baseNorm = mb_strtolower(gestao_clean_address_line($base));
    $partNorm = mb_strtolower(gestao_clean_address_line($part));
    if ($baseNorm !== '' && $partNorm !== '' && str_contains($baseNorm, $partNorm)) return $base;
    return trim($base . ($base !== '' ? $separator : '') . $part);
  }
}

if (!function_exists('gestao_time_or_null')) {
  function gestao_time_or_null($value): ?string {
    $s = trim((string)$value);
    if ($s === '') return null;
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s)) return $s . ':00';
    if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $s)) return $s;
    return null;
  }
}

if (!function_exists('gestao_format_scheduled_delivery')) {
  function gestao_format_scheduled_delivery(string $date, string $time = ''): string {
    $date = trim($date);
    if ($date === '' || $date === '0000-00-00') return '';
    $ts = strtotime($date);
    if ($ts === false) return '';
    $label = date('d/m/Y', $ts);
    $time = trim($time);
    if ($time !== '') $label .= ' ' . substr($time, 0, 5);
    return $label;
  }
}

if (!function_exists('gestao_build_pdv_whatsapp_message')) {
  function gestao_build_pdv_whatsapp_message(int $orderId, string $customerName, string $whatsapp, array $items, float $subtotal, float $discount, float $addition, float $delivery, float $totalOrder, string $tipoEntrega, string $paymentLabel, string $district, string $addressText, string $zipCode = '', string $notes = '', array $biz = [], ?array $waCfg = null, string $scheduledDeliveryDate = '', string $scheduledDeliveryTime = ''): string {
    $loja = trim((string)($biz['brand']['catalog_title'] ?? $biz['site_name'] ?? 'Nossa loja'));
    if ($loja === '') $loja = 'Nossa loja';

    $itemLines = [];
    foreach ($items as $it) {
      $qtd = rtrim(rtrim(number_format((float)($it['qtd'] ?? 1), 3, ',', '.'), '0'), ',');
      if ($qtd === '') $qtd = '1';
      $itemLines[] = '- ' . $qtd . 'x ' . (string)($it['nome'] ?? 'Item') . ' - R$ ' . finance_money_br((float)($it['total'] ?? 0));
      $obsItem = trim((string)($it['obs'] ?? ''));
      if ($obsItem !== '') {
        foreach (preg_split('/\R/u', $obsItem) as $line) {
          $line = trim((string)$line);
          if ($line !== '') $itemLines[] = '  • ' . $line;
        }
      }
    }

    [$district, $addressClean, $reference] = gestao_split_order_address_message($district, $addressText);
    $tipoLabel = ucfirst(str_replace('_', ' ', $tipoEntrega));
    $scheduledLabel = gestao_format_scheduled_delivery($scheduledDeliveryDate, $scheduledDeliveryTime);
    $scheduledCaption = trim((string)($biz['delivery_scheduling']['label'] ?? 'Data/Hora Prevista'));
    if ($scheduledCaption === '') $scheduledCaption = 'Data/Hora Prevista';
    $waCfg = $waCfg ?: ['templates' => whatsapp_default_templates()];
    $template = whatsapp_template_from_config($waCfg, 'order_customer_full');
    $pixLinha = whatsapp_pix_line_for_payment($paymentLabel, $waCfg);
    $pixDados = $pixLinha !== '' ? trim((string)($waCfg['pix']['details'] ?? '')) : '';

    return whatsapp_render_order_template($template, [
      'pedido_id' => (string)$orderId,
      'loja' => $loja,
      'cliente' => trim($customerName) !== '' ? trim($customerName) : 'Consumidor final',
      'whatsapp' => preg_replace('/\D+/', '', $whatsapp),
      'whatsapp_linha' => whatsapp_optional_line('📱 WhatsApp: ', preg_replace('/\D+/', '', $whatsapp)),
      'itens' => implode("\n", $itemLines),
      'subtotal' => 'R$ ' . finance_money_br($subtotal),
      'desconto' => 'R$ ' . finance_money_br($discount),
      'acrescimo' => 'R$ ' . finance_money_br($addition),
      'taxa_entrega' => 'R$ ' . finance_money_br($delivery),
      'taxa_entrega_linha' => $delivery > 0 ? '🛵 Taxa/frete: R$ ' . finance_money_br($delivery) : '',
      'total' => 'R$ ' . finance_money_br($totalOrder),
      'tipo_entrega' => $tipoLabel,
      'pagamento' => $paymentLabel,
      'pix_linha' => $pixLinha,
      'pix_dados' => $pixDados,
      'data_hora_prevista' => $scheduledLabel,
      'data_hora_prevista_linha' => whatsapp_optional_line('📅 ' . $scheduledCaption . ': ', $scheduledLabel),
      'bairro' => $district,
      'bairro_linha' => whatsapp_optional_line('📍 Bairro: ', $district),
      'cep' => preg_replace('/(\d{5})(\d{3})$/', '$1-$2', preg_replace('/\D+/', '', $zipCode)),
      'cep_linha' => whatsapp_optional_line('📮 CEP: ', preg_replace('/(\d{5})(\d{3})$/', '$1-$2', preg_replace('/\D+/', '', $zipCode))),
      'endereco' => trim((string)preg_replace('/\s+/', ' ', $addressClean)),
      'endereco_linha' => whatsapp_optional_line('🏠 Endereço: ', trim((string)preg_replace('/\s+/', ' ', $addressClean))),
      'referencia' => $reference,
      'referencia_linha' => whatsapp_optional_line('📌 Referência: ', $reference),
      'observacoes' => $notes,
      'observacoes_linha' => whatsapp_optional_line('📝 Observações: ', $notes),
    ]);
  }
}

if (!function_exists('gestao_try_send_pdv_whatsapp')) {
  function gestao_try_send_pdv_whatsapp(PDO $pdo, int $companyId, int $orderId, string $customerName, string $whatsapp, array $items, float $subtotal, float $discount, float $addition, float $delivery, float $totalOrder, string $tipoEntrega, string $paymentLabel, string $district, string $addressText, string $zipCode, string $notes, string $scheduledDeliveryDate = '', string $scheduledDeliveryTime = '', int $orderNumber = 0): void {
    try {
      $biz = load_business_config($companyId);
      if (empty($biz['whatsapp_pdv_auto_send'])) return;
      $destino = preg_replace('/\D+/', '', $whatsapp);
      if ($destino === '') return;
      $waCfg = whatsapp_load_config($pdo, $companyId);
      $waCfg['timeout_seconds'] = max(30, (int)($waCfg['timeout_seconds'] ?? 30));
      if (empty($waCfg['enabled'])) return;
      $orderNumberDisplay = $orderNumber > 0 ? $orderNumber : $orderId;
      $msg = gestao_build_pdv_whatsapp_message($orderNumberDisplay, $customerName, $destino, $items, $subtotal, $discount, $addition, $delivery, $totalOrder, $tipoEntrega, $paymentLabel, $district, $addressText, $zipCode, $notes, $biz, $waCfg, $scheduledDeliveryDate, $scheduledDeliveryTime);
      whatsapp_send_message($pdo, $companyId, $destino, $msg, 'pdv_order_customer_full', $orderId, $waCfg);
    } catch (Throwable $e) {
      try { whatsapp_log($pdo, $companyId, $orderId, 'pdv_order_customer_full', $whatsapp, '', 'error', $e->getMessage()); } catch (Throwable $e2) {}
    }
  }
}

if (!function_exists('gestao_schedule_day_key')) {
  function gestao_schedule_day_key(string $date): string {
    $date = trim($date);
    if ($date === '') return '';
    try {
      $dt = new DateTimeImmutable($date);
      $map = ['sun','mon','tue','wed','thu','fri','sat'];
      return $map[(int)$dt->format('w')] ?? '';
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('gestao_schedule_time_minutes')) {
  function gestao_schedule_time_minutes(string $time): ?int {
    $time = trim($time);
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) return null;
    [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
    return ($h * 60) + $m;
  }
}

if (!function_exists('gestao_schedule_hours_label')) {
  function gestao_schedule_hours_label(array $biz, string $date): string {
    $day = gestao_schedule_day_key($date);
    if ($day === '') return '';
    $parts = [];
    foreach ((array)($biz['hours'][$day] ?? []) as $w) {
      $ini = trim((string)($w[0] ?? ''));
      $fim = trim((string)($w[1] ?? ''));
      if (preg_match('/^\d{2}:\d{2}$/', $ini) && preg_match('/^\d{2}:\d{2}$/', $fim)) $parts[] = $ini . ' às ' . $fim;
    }
    return implode(' ou ', $parts);
  }
}

if (!function_exists('gestao_schedule_inside_hours')) {
  function gestao_schedule_inside_hours(array $biz, string $date, string $time): bool {
    $day = gestao_schedule_day_key($date);
    $timeMin = gestao_schedule_time_minutes($time);
    if ($day === '' || $timeMin === null) return false;
    foreach ((array)($biz['hours'][$day] ?? []) as $w) {
      $ini = gestao_schedule_time_minutes((string)($w[0] ?? ''));
      $fim = gestao_schedule_time_minutes((string)($w[1] ?? ''));
      if ($ini === null || $fim === null) continue;
      if ($fim >= $ini) {
        if ($timeMin >= $ini && $timeMin <= $fim) return true;
      } else {
        if ($timeMin >= $ini || $timeMin <= $fim) return true;
      }
    }
    return false;
  }
}

if (!function_exists('gestao_schedule_is_past')) {
  function gestao_schedule_is_past(array $biz, string $date, string $time): bool {
    try {
      $tz = new DateTimeZone((string)($biz['timezone'] ?? 'America/Sao_Paulo'));
      $scheduled = new DateTimeImmutable($date . ' ' . substr($time, 0, 5) . ':00', $tz);
      $now = new DateTimeImmutable('now', $tz);
      return $scheduled < $now;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('gestao_minutes_label')) {
  function gestao_minutes_label(int $minutes): string {
    $minutes = max(0, $minutes);
    if ($minutes === 1) return '1 minuto';
    if ($minutes > 0 && $minutes % 60 === 0) {
      $hours = (int)($minutes / 60);
      return $hours === 1 ? '1 hora' : $hours . ' horas';
    }
    if ($minutes > 60) {
      $hours = intdiv($minutes, 60);
      $rest = $minutes % 60;
      return $hours . 'h' . str_pad((string)$rest, 2, '0', STR_PAD_LEFT) . 'min';
    }
    return $minutes . ' minutos';
  }
}

if (!function_exists('gestao_schedule_respects_minimum')) {
  function gestao_schedule_respects_minimum(array $biz, string $date, string $time, int $minMinutes): bool {
    $minMinutes = max(0, $minMinutes);
    if ($minMinutes <= 0) return true;
    try {
      $tz = new DateTimeZone((string)($biz['timezone'] ?? 'America/Sao_Paulo'));
      $scheduled = new DateTimeImmutable($date . ' ' . substr($time, 0, 5) . ':00', $tz);
      $minimum = (new DateTimeImmutable('now', $tz))->modify('+' . $minMinutes . ' minutes');
      return $scheduled >= $minimum;
    } catch (Throwable $e) {
      return true;
    }
  }
}

if (!function_exists('gestao_pdv_sale_operation')) {
  function gestao_pdv_sale_operation(PDO $pdo, int $companyId): ?array {
    try {
      require_once __DIR__ . '/fiscal_module.php';
      // V11.25: no F11 cruzado não chamamos fiscal_default_sale_operation(), pois essa
      // rotina garante schema/registro padrão. Apenas lemos a operação VENDA já existente.
      if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
        $st = $pdo->prepare("SELECT * FROM fiscal_operations WHERE company_id = ? AND direction = 'SAIDA' AND operation_type = 'VENDA' AND is_active = 1 ORDER BY (code='VENDA') DESC, id ASC LIMIT 1");
        $st->execute([$companyId]);
        $op = $st->fetch(PDO::FETCH_ASSOC);
        return $op ?: ['code'=>'VENDA','name'=>'Venda de mercadoria','direction'=>'SAIDA','operation_type'=>'VENDA','update_stock'=>1,'create_finance'=>1,'finance_movement'=>'RECEBER'];
      }
      if (function_exists('fiscal_default_sale_operation')) return fiscal_default_sale_operation($pdo, $companyId);
    } catch (Throwable $e) {}
    return null;
  }
}

if (!function_exists('gestao_pdv_prepare_stock_out')) {
  function gestao_pdv_prepare_stock_out(PDO $pdo, int $companyId, ?array $saleOperation): bool {
    if (!$saleOperation || (int)($saleOperation['update_stock'] ?? 0) !== 1) return false;
    require_once __DIR__ . '/stock_module.php';
    $noDdl = !empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']);
    $cols = $noDdl ? [
      'estoque_atual' => function_exists('stock_mysql_has_column') && stock_mysql_has_column($pdo, 'produtos', 'estoque_atual')
    ] : (function_exists('stock_ensure_schema') ? stock_ensure_schema($pdo, true) : []);
    if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId)) {
      if ($noDdl && function_exists('product_composition_schema_ready') && !product_composition_schema_ready($pdo)) {
        throw new RuntimeException('A composição de produtos está habilitada, mas a estrutura necessária ainda não está pronta. Atualize o banco antes de vender pelo F11.');
      }
      if (!$noDdl) product_composition_ensure_schema($pdo);
    }
    if (empty($cols['estoque_atual']) && !(function_exists('stock_mysql_has_column') && stock_mysql_has_column($pdo, 'produtos', 'estoque_atual'))) {
      throw new RuntimeException('Não foi possível preparar a baixa de estoque do PDV. Verifique as permissões do banco para atualizar a tabela de produtos.');
    }
    return true;
  }
}

if (!function_exists('gestao_pdv_apply_stock_out')) {
  function gestao_pdv_apply_stock_out(PDO $pdo, int $companyId, int $orderId, string $orderNumber, array $items, ?array $saleOperation): void {
    if (!$saleOperation || (int)($saleOperation['update_stock'] ?? 0) !== 1) return;
    require_once __DIR__ . '/stock_module.php';
    if (!(function_exists('stock_mysql_has_column') && stock_mysql_has_column($pdo, 'produtos', 'estoque_atual'))) return;
    $grouped = [];
    foreach ($items as $it) {
      $pid = (int)($it['produto_id'] ?? 0);
      $qty = (float)($it['qtd'] ?? 0);
      if ($pid <= 0 || $qty <= 0) continue;
      if (!isset($grouped[$pid])) $grouped[$pid] = ['qtd' => 0.0, 'nome' => (string)($it['nome'] ?? '')];
      $grouped[$pid]['qtd'] += $qty;
    }
    if (!$grouped) return;
    $composedIds = [];
    if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
      $productIds = array_map('intval', array_keys($grouped));
      if ($productIds) {
        $phComp = implode(',', array_fill(0, count($productIds), '?'));
        $stComp = $pdo->prepare("SELECT id FROM produtos WHERE company_id=? AND id IN ($phComp) AND COALESCE(possui_composicao,0)=1");
        $stComp->execute(array_merge([$companyId], $productIds));
        foreach (($stComp->fetchAll(PDO::FETCH_COLUMN) ?: []) as $compId) $composedIds[(int)$compId] = true;
      }
    }
    $sel = $pdo->prepare('SELECT estoque_atual, COALESCE(custo_medio,0) AS custo_medio, COALESCE(custo,0) AS custo FROM produtos WHERE company_id = ? AND id = ? FOR UPDATE');
    $upd = $pdo->prepare('UPDATE produtos SET estoque_atual = ? WHERE company_id = ? AND id = ?');
    $mov = null;
    $movWithCosts = brain_profitability_column_exists($pdo, 'estoque_movimentos', 'custo_unitario')
      && brain_profitability_column_exists($pdo, 'estoque_movimentos', 'valor_movimento')
      && brain_profitability_column_exists($pdo, 'estoque_movimentos', 'custo_medio_anterior')
      && brain_profitability_column_exists($pdo, 'estoque_movimentos', 'custo_medio_posterior');
    if (function_exists('finance_table_exists') && finance_table_exists($pdo, 'estoque_movimentos')) {
      try {
        $mov = $movWithCosts
          ? $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, custo_unitario, valor_movimento, custo_medio_anterior, custo_medio_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          : $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
      } catch (Throwable $e) { $mov = null; }
    }
    foreach ($grouped as $productId => $row) {
      $qty = round((float)$row['qtd'], 4);
      if ($qty <= 0) continue;
      // Produto composto trabalha com estoque operacional calculado pelas matérias-primas.
      // Não reduzimos o estoque físico do acabado para evitar dupla contabilização do inventário.
      if (isset($composedIds[(int)$productId])) continue;
      $sel->execute([$companyId, $productId]);
      $current = $sel->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$current) continue;
      $before = (float)($current['estoque_atual'] ?? 0);
      $averageCost = (float)($current['custo_medio'] ?? 0);
      if ($averageCost <= 0) $averageCost = (float)($current['custo'] ?? 0);
      $after = round($before - $qty, 4);
      $upd->execute([$after, $companyId, $productId]);
      if ($mov) {
        $obs = 'Baixa automática PDV pedido #' . $orderNumber;
        if (!empty($saleOperation['name'])) $obs .= ' • operação: ' . (string)$saleOperation['name'];
        if ($movWithCosts) {
          $mov->execute([$companyId, $productId, 'saida', 'PDV', $orderId, $qty, $before, $after, $averageCost, round($qty * $averageCost, 2), $averageCost, $averageCost, $obs]);
        } else {
          $mov->execute([$companyId, $productId, 'saida', 'PDV', $orderId, $qty, $before, $after, $obs]);
        }
      }
    }
    if (function_exists('product_composition_apply_sale') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
      product_composition_apply_sale($pdo, $companyId, $orderId, $orderNumber, $items);
    }
  }
}

if (!function_exists('gestao_quantity_in')) {
  function gestao_quantity_in($value, int $decimals = 3): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (str_contains($s, ',')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }
    $s = preg_replace('/[^0-9.\-]+/', '', $s) ?: '0';
    return round((float)$s, max(0, $decimals));
  }
}

if (!function_exists('gestao_finalize_pdv_order')) {
  function gestao_finalize_pdv_order(PDO $pdo, int $companyId, int $userId, array $post, bool $skipDiscountPolicy = false): int {
    $customerId = (int)($post['customer_id'] ?? 0);
    $sellerId = commercial_resolve_seller($pdo, $companyId, $userId, (int)($post['seller_id'] ?? 0));
    $customer = $customerId > 0 ? finance_query_one($pdo, 'SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $customerId]) : null;

    $products = $post['item_product_id'] ?? [];
    $qtys = $post['item_qty'] ?? [];
    $prices = $post['item_price'] ?? [];
    $obs = $post['item_obs'] ?? [];
    // V11.38.3: flag opcional por item para fluxos que possuem serviços no mesmo pedido.
    // Ausente = comportamento legado (baixa estoque normalmente).
    $stockEffects = $post['item_stock_effect'] ?? [];
    // V11.39: quantidade física opcional por item. Quando ausente, mantém o legado (qtd total).
    // Usado pelo Controle de Entregas para baixar apenas a retirada imediata no pedido comercial.
    $stockQtys = $post['item_stock_qty'] ?? [];
    // Precisão unitária opcional. O PDV legado permanece com 2 casas; a O.S. pode
    // preservar valores unitários com até 6 casas sem alterar os demais fluxos.
    $priceDecimals = $post['item_price_decimals'] ?? [];
    $items = [];
    $subtotal = 0.0;
    $productIds = array_values(array_unique(array_filter(array_map('intval', (array)$products), static fn(int $id): bool => $id > 0)));
    $productMap = [];
    if ($productIds) {
      $ph = implode(',', array_fill(0, count($productIds), '?'));
      $stProducts = $pdo->prepare("SELECT id, nome, categoria, preco, custo, custo_medio FROM produtos WHERE company_id = ? AND id IN ($ph)");
      $stProducts->execute(array_merge([$companyId], $productIds));
      foreach (($stProducts->fetchAll(PDO::FETCH_ASSOC) ?: []) as $productRow) $productMap[(int)$productRow['id']] = $productRow;
    }
    for ($i=0; $i<count((array)$products); $i++) {
      $pid = (int)($products[$i] ?? 0);
      $qtd = function_exists('gestao_quantity_in') ? gestao_quantity_in($qtys[$i] ?? 0, 3) : finance_money_in($qtys[$i] ?? 0);
      $unitDecimals = array_key_exists($i, (array)$priceDecimals) ? max(2, min(6, (int)($priceDecimals[$i] ?? 2))) : 2;
      $price = ($unitDecimals > 2 && function_exists('gestao_quantity_in'))
        ? gestao_quantity_in($prices[$i] ?? 0, $unitDecimals)
        : finance_money_in($prices[$i] ?? 0);
      if ($pid <= 0 || $qtd <= 0 || $price < 0) continue;
      $prod = $productMap[$pid] ?? null;
      if (!$prod) continue;
      $total = round($qtd * $price, 2);
      $subtotal += $total;
      $stockEffect = array_key_exists($i, (array)$stockEffects) ? ((int)($stockEffects[$i] ?? 0) === 1 ? 1 : 0) : 1;
      $stockQty = array_key_exists($i, (array)$stockQtys)
        ? max(0.0, min($qtd, function_exists('gestao_quantity_in') ? gestao_quantity_in($stockQtys[$i] ?? $qtd, 4) : (float)($stockQtys[$i] ?? $qtd)))
        : $qtd;
      $items[] = ['produto_id'=>$pid, 'nome'=>(string)($prod['nome'] ?? 'Item'), 'categoria'=>(string)($prod['categoria'] ?? 'SEM CATEGORIA'), 'custo'=>(float)($prod['custo'] ?? 0), 'custo_medio'=>(float)($prod['custo_medio'] ?? 0), 'qtd'=>$qtd, 'stock_qtd'=>$stockQty, 'price'=>$price, 'total'=>$total, 'obs'=>trim((string)($obs[$i] ?? '')), 'stock_effect'=>$stockEffect];
    }
    if (!$items) throw new RuntimeException('Inclua ao menos um produto no pedido.');

    $discount = finance_money_in($post['discount_amount'] ?? 0);
    if (!$skipDiscountPolicy) commercial_validate_discount($pdo, $companyId, $userId, $subtotal, $discount);
    $addition = finance_money_in($post['addition_amount'] ?? 0);
    $delivery = finance_money_in($post['delivery_fee'] ?? 0);
    $totalOrder = max(0, round($subtotal - $discount + $addition + $delivery, 2));
    if ($totalOrder <= 0) throw new RuntimeException('Total do pedido deve ser maior que zero.');

    $payments = gestao_payment_lines_from_post($pdo, $companyId, $post, $totalOrder);
    $session = gestao_open_cash_session($pdo, $companyId, $userId);

    foreach ($payments as $line) {
      $payment = $line['payment'];
      if (!empty($payment['generate_receivable']) && !$customer) {
        throw new RuntimeException('Pagamento a prazo exige cliente cadastrado.');
      }
      if (!empty($line['is_voucher'])) {
        if (!$customer) throw new RuntimeException('Pagamento com Vale exige cliente cadastrado.');
        require_once __DIR__ . '/voucher_module.php';
        voucher_assert_ready($pdo);
        continue;
      }
      if (!empty($payment['require_open_cash']) && !$session) {
        throw new RuntimeException('Abra o caixa antes de finalizar vendas em ' . (string)$payment['name'] . '.');
      }
      $chartIdLine = (int)($payment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo, $companyId, 'CREDITO');
      if ($chartIdLine <= 0) throw new RuntimeException('Configure o plano de contas da forma de pagamento: ' . (string)$payment['name']);
    }

    $obsCol = gestao_pedido_obs_column($pdo);
    $nomeCliente = $customer ? (string)$customer['name'] : trim((string)($post['customer_name'] ?? 'Consumidor final'));
    if ($nomeCliente === '') $nomeCliente = 'Consumidor final';
    $whats = $customer ? (string)($customer['whatsapp'] ?: $customer['phone']) : trim((string)($post['whatsapp'] ?? ''));

    // Endereço do PDV: usa primeiro os campos ocultos enviados pelo formulário,
    // mas se o operador apenas localizar o cliente e finalizar sem passar pelos campos,
    // recupera automaticamente o endereço selecionado/padrão do cadastro.
    $customerAddressId = (int)($post['customer_address_id'] ?? 0);
    $addr = null;
    if ($customer) {
      if ($customerAddressId > 0) {
        $addr = finance_query_one($pdo, 'SELECT * FROM gestao_customer_addresses WHERE company_id = ? AND person_id = ? AND id = ? AND active = 1 LIMIT 1', [$companyId, $customerId, $customerAddressId]);
      }
      if (!$addr) {
        $addr = finance_query_one($pdo, 'SELECT * FROM gestao_customer_addresses WHERE company_id = ? AND person_id = ? AND active = 1 ORDER BY is_default DESC, id ASC LIMIT 1', [$companyId, $customerId]);
      }
    }

    $districtText = trim((string)($post['district'] ?? ''));
    $addressText = gestao_clean_address_with_district((string)($post['address'] ?? ''), $districtText);
    $addressNumber = trim((string)($post['address_number'] ?? ''));
    $addressComplement = trim((string)($post['address_complement'] ?? ''));
    $addressReference = trim((string)($post['address_reference'] ?? $post['reference'] ?? ''));
    $zipCode = preg_replace('/\D+/', '', (string)($post['zip_code'] ?? $post['cep'] ?? ''));
    $cityText = trim((string)($post['city'] ?? $post['cidade'] ?? ''));
    $stateText = strtoupper(substr(trim((string)($post['state'] ?? $post['uf'] ?? '')), 0, 2));

    if ($addr) {
      $addressText = $addressText ?: gestao_clean_address_with_district((string)($addr['address'] ?? ''), (string)($addr['district'] ?? $districtText));
      $addressNumber = $addressNumber ?: trim((string)($addr['number'] ?? ''));
      $addressComplement = $addressComplement ?: trim((string)($addr['complement'] ?? ''));
      $addressReference = $addressReference ?: trim((string)($addr['reference'] ?? ''));
      $zipCode = $zipCode ?: preg_replace('/\D+/', '', (string)($addr['zip_code'] ?? ''));
      $districtText = $districtText ?: trim((string)($addr['district'] ?? ''));
      $cityText = $cityText ?: trim((string)($addr['city'] ?? ''));
      $stateText = $stateText ?: strtoupper(substr(trim((string)($addr['state'] ?? '')), 0, 2));
    } elseif ($customer) {
      $addressText = $addressText ?: gestao_clean_address_with_district((string)($customer['address'] ?? ''), (string)($customer['district'] ?? $districtText));
      $addressNumber = $addressNumber ?: trim((string)($customer['number'] ?? ''));
      $addressComplement = $addressComplement ?: trim((string)($customer['complement'] ?? ''));
      $zipCode = $zipCode ?: preg_replace('/\D+/', '', (string)($customer['zip_code'] ?? ''));
      $districtText = $districtText ?: trim((string)($customer['district'] ?? ''));
      $cityText = $cityText ?: trim((string)($customer['city'] ?? ''));
      $stateText = $stateText ?: strtoupper(substr(trim((string)($customer['state'] ?? '')), 0, 2));
    }

    $addressText = gestao_clean_address_with_district($addressText, $districtText);
    $addressText = gestao_append_address_part(gestao_clean_address_line($addressText), $addressNumber, ', ');
    $addressText = gestao_append_address_part($addressText, $addressComplement, ' - ');
    if ($addressText !== '' && $addressReference !== '' && !str_contains(mb_strtolower($addressText), mb_strtolower($addressReference))) {
      $addressText = trim($addressText . "
Ref.: " . $addressReference);
    }
    $notes = trim((string)($post['notes'] ?? ''));
    $scheduledDeliveryDate = finance_date_or_null($post['scheduled_delivery_date'] ?? '') ?: '';
    $scheduledDeliveryTime = gestao_time_or_null($post['scheduled_delivery_time'] ?? '') ?: '';
    if ($scheduledDeliveryDate === '') $scheduledDeliveryTime = '';
    $bizPdv = load_business_config($companyId);
    $deliverySchedulingCfg = (array)($bizPdv['delivery_scheduling'] ?? []);
    if (!empty($deliverySchedulingCfg['enabled'])) {
      if (($scheduledDeliveryDate !== '') xor ($scheduledDeliveryTime !== '')) {
        throw new RuntimeException('Informe a data e a hora da previsão de entrega.');
      }
      $minMinutes = max(0, (int)($deliverySchedulingCfg['min_minutes'] ?? 0));
      if ($scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && $minMinutes > 0 && !gestao_schedule_respects_minimum($bizPdv, $scheduledDeliveryDate, $scheduledDeliveryTime, $minMinutes)) {
        throw new RuntimeException('A previsão de entrega precisa respeitar o tempo mínimo de ' . gestao_minutes_label($minMinutes) . ' após o horário do pedido.');
      }
      if (!empty($deliverySchedulingCfg['only_business_hours'])) {
        if ($scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && !gestao_schedule_inside_hours($bizPdv, $scheduledDeliveryDate, $scheduledDeliveryTime)) {
          $hoursLabel = gestao_schedule_hours_label($bizPdv, $scheduledDeliveryDate);
          throw new RuntimeException($hoursLabel !== ''
            ? 'A previsão de entrega precisa estar dentro do horário de funcionamento: ' . $hoursLabel . '.'
            : 'A previsão de entrega precisa estar dentro do horário de funcionamento. A data escolhida está fechada.');
        }
        if ($scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && gestao_schedule_is_past($bizPdv, $scheduledDeliveryDate, $scheduledDeliveryTime)) {
          throw new RuntimeException('A previsão de entrega não pode ser anterior ao horário atual.');
        }
      }
    }
    $tipoEntrega = trim((string)($post['order_type'] ?? 'balcao')) ?: 'balcao';
    $orderSource = mb_strtolower(trim((string)($post['source'] ?? 'gestao_pdv')), 'UTF-8');
    if (!in_array($orderSource, ['gestao_pdv','pedido_detalhado','comanda','api','marketplace','delivery','importacao'], true)) $orderSource = 'gestao_pdv';
    $orderTypesCfg = (array)($bizPdv['order_types'] ?? []);
    $pdvOrderTypesCfg = isset($orderTypesCfg['pdv']) && is_array($orderTypesCfg['pdv']) ? (array)$orderTypesCfg['pdv'] : $orderTypesCfg;
    $allowedOrderTypes = [];
    foreach (['balcao', 'retirada', 'entrega'] as $typeKey) {
      if (!array_key_exists($typeKey, $pdvOrderTypesCfg) || !empty($pdvOrderTypesCfg[$typeKey])) {
        $allowedOrderTypes[] = $typeKey;
      }
    }
    if (!$allowedOrderTypes) $allowedOrderTypes = ['balcao'];
    if (!in_array($tipoEntrega, $allowedOrderTypes, true)) {
      $tipoEntrega = $allowedOrderTypes[0];
    }
    $tipoEntregaStatusKey = strtolower($tipoEntrega);
    $orderStatus = in_array($tipoEntregaStatusKey, ['retirada', 'entrega'], true) ? 'aberto' : 'finalizado';
    $paymentLabel = implode(' + ', array_map(static fn($l) => (string)$l['payment']['name'], $payments));
    $paymentLabelDb = gestao_payment_label_for_pedidos($pdo, $payments, $paymentLabel);
    $firstPayment = $payments[0]['payment'];
    $firstPaymentId = count($payments) === 1 ? (int)$payments[0]['payment_id'] : null;
    $firstIsVoucher = !empty($payments[0]['is_voucher']);
    $firstBankId = $firstIsVoucher ? 0 : gestao_pdv_cash_account($pdo, $companyId, $userId, (int)($firstPayment['default_bank_account_id'] ?? 0));
    if (!$firstIsVoucher) gestao_pdv_assert_cash_account($pdo, $companyId, $firstBankId, 'Este usuário não possui permissão para usar o caixa/banco padrão do PDV.');
    $firstChartId = $firstIsVoucher ? 0 : ((int)($firstPayment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo, $companyId, 'CREDITO'));
    $firstCostId = (int)($firstPayment['default_cost_center_id'] ?? 0);
    $creditPurchaseAmount = 0.0;
    foreach ($payments as $lineCredit) { if (!empty($lineCredit['payment']['generate_receivable'])) $creditPurchaseAmount += (float)$lineCredit['amount']; }
    $commission = commercial_commission_snapshot($pdo, $companyId, $sellerId, $totalOrder);
    $creditInfo = ['limited'=>false,'open'=>0.0,'limit'=>null,'override_user_id'=>0];

    $pdvSaleOperation = gestao_pdv_sale_operation($pdo, $companyId);
    $pdvUpdateStock = gestao_pdv_prepare_stock_out($pdo, $companyId, $pdvSaleOperation);

    // A fila de boletos é preparada antes da transação do Pedido para evitar DDL
    // dentro da transação. Formas que não são Boleto permanecem sem qualquer efeito.
    $billingOrderQueueReady = false;
    $billingAutoChargeIds = [];

    // V11.45.15: os orçamentos usados na venda são travados dentro da mesma
    // transação do Pedido. Isso impede dois caixas de consumirem o mesmo orçamento.
    $sourceQuoteIds = [];
    $legacyQuoteId = (int)($post['orcamento_id'] ?? 0);
    if ($legacyQuoteId > 0) $sourceQuoteIds[] = $legacyQuoteId;
    $checkoutQuoteRaw = json_decode((string)($post['checkout_orcamento_sources'] ?? ''), true);
    if (is_array($checkoutQuoteRaw)) {
      foreach ($checkoutQuoteRaw as $quoteIdRaw) {
        $quoteId = (int)$quoteIdRaw;
        if ($quoteId > 0) $sourceQuoteIds[] = $quoteId;
      }
    }
    $sourceQuoteIds = array_values(array_unique($sourceQuoteIds));
    $quoteConsumeReady = $sourceQuoteIds
      && finance_table_exists($pdo, 'orcamentos')
      && finance_column_exists($pdo, 'orcamentos', 'converted_order_id');
    foreach ($payments as $billingLine) {
      if (!empty($billingLine['payment']['generate_receivable']) && billing_is_boleto_method((array)$billingLine['payment'])) {
        try {
          billing_ensure_schema($pdo);
          $billingOrderQueueReady = true;
        } catch (Throwable $billingSchemaError) {
          error_log('[Nexa][Boleto Pedido] Não foi possível preparar a estrutura de cobrança: ' . $billingSchemaError->getMessage());
        }
        break;
      }
    }

    $pdo->beginTransaction();
    try {
      if ($quoteConsumeReady) {
        $quotePlaceholders = implode(',', array_fill(0, count($sourceQuoteIds), '?'));
        $stQuotes = $pdo->prepare("SELECT id,status,converted_order_id FROM orcamentos WHERE company_id=? AND id IN ($quotePlaceholders) FOR UPDATE");
        $stQuotes->execute(array_merge([$companyId], $sourceQuoteIds));
        $lockedQuotes = $stQuotes->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($lockedQuotes) !== count($sourceQuoteIds)) {
          throw new RuntimeException('Um dos orçamentos importados não está mais disponível. Pesquise e importe novamente.');
        }
        foreach ($lockedQuotes as $lockedQuote) {
          if ((int)($lockedQuote['converted_order_id'] ?? 0) > 0 || strtoupper((string)($lockedQuote['status'] ?? '')) === 'FATURADO') {
            throw new RuntimeException('O orçamento #' . (int)$lockedQuote['id'] . ' já foi utilizado em outra venda e não pode ser lido novamente.');
          }
        }
      }
      if ($creditPurchaseAmount > 0) {
        if ($customerId <= 0) throw new RuntimeException('Pagamento a prazo exige cliente cadastrado.');
        $creditInfo = commercial_credit_check($pdo, $companyId, $customerId, $creditPurchaseAmount, $post);
      }
      $orderId = gestao_insert_pedido_dynamic($pdo, [
        'company_id' => $companyId,
        'nome_cliente' => $nomeCliente,
        'whatsapp' => preg_replace('/\D+/', '', $whats),
        'endereco' => $addressText,
        'bairro' => $districtText,
        'zip_code' => $zipCode !== '' ? $zipCode : null,
        'numero' => $addressNumber !== '' ? $addressNumber : null,
        'complemento' => $addressComplement !== '' ? $addressComplement : null,
        'referencia' => $addressReference !== '' ? $addressReference : null,
        'cidade' => $cityText !== '' ? $cityText : null,
        'uf' => $stateText !== '' ? $stateText : null,
        'city' => $cityText !== '' ? $cityText : null,
        'state' => $stateText !== '' ? $stateText : null,
        'tipo_entrega' => $tipoEntrega,
        'taxa_entrega' => $delivery,
        'forma_pagamento' => $paymentLabelDb,
        $obsCol => $notes,
        'total_produtos' => $subtotal,
        'total' => $totalOrder,
        'status' => $orderStatus,
        'finance_person_id' => $customerId ?: null,
        'seller_id' => $sellerId ?: null,
        'payment_method_id' => $firstPaymentId,
        'bank_account_id' => $firstBankId ?: null,
        'chart_account_id' => $firstChartId ?: null,
        'cost_center_id' => $firstCostId ?: null,
        'cash_session_id' => $session ? (int)$session['id'] : null,
        'source' => $orderSource,
        'discount_amount' => $discount,
        'addition_amount' => $addition,
        'seller_commission_percent' => $commission['percent'],
        'seller_commission_base' => $commission['base'],
        'seller_commission_amount' => $commission['amount'],
        'credit_limit_snapshot' => $creditInfo['limit'],
        'credit_open_before' => $creditInfo['open'],
        'credit_override_user_id' => ((int)$creditInfo['override_user_id']) ?: null,
        'credit_override_at' => !empty($creditInfo['override_user_id']) ? date('Y-m-d H:i:s') : null,
        'scheduled_delivery_date' => $scheduledDeliveryDate !== '' ? $scheduledDeliveryDate : null,
        'scheduled_delivery_time' => $scheduledDeliveryTime !== '' ? $scheduledDeliveryTime : null,
      ]);

      if ($quoteConsumeReady) {
        $quotePlaceholders = implode(',', array_fill(0, count($sourceQuoteIds), '?'));
        $paramsQuoteConsume = [$orderId, $userId ?: null, $userId ?: null, $companyId];
        foreach ($sourceQuoteIds as $sourceQuoteId) $paramsQuoteConsume[] = $sourceQuoteId;
        $pdo->prepare("UPDATE orcamentos SET status='FATURADO',converted_order_id=?,converted_at=NOW(),converted_by=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id IN ($quotePlaceholders) AND converted_order_id IS NULL")
          ->execute($paramsQuoteConsume);
      }

      $pedidoRowNumber = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $orderId]) ?: ['id' => $orderId];
      $orderNumber = company_sequence_display($pedidoRowNumber, 'pedidos');

      $itemObsCol = gestao_pedido_item_obs_column($pdo);
      foreach ($items as $it) {
        if ($itemObsCol) {
          $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, `' . str_replace('`', '``', $itemObsCol) . '`) VALUES (?, ?, ?, ?, ?, ?, ?)')->execute([$companyId, $orderId, $it['produto_id'], $it['qtd'], $it['price'], $it['total'], $it['obs']]);
        } else {
          $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?, ?, ?, ?, ?, ?)')->execute([$companyId, $orderId, $it['produto_id'], $it['qtd'], $it['price'], $it['total']]);
        }
      }

      brain_profitability_recalculate_order($pdo, $companyId, $orderId, true, $orderStatus === 'finalizado' ? 'order.finalized' : 'order.created');

      if ($pdvUpdateStock) {
        $stockOutItems = [];
        foreach ($items as $stockItem) {
          if ((int)($stockItem['stock_effect'] ?? 1) !== 1) continue;
          $physicalQty = (float)($stockItem['stock_qtd'] ?? $stockItem['qtd'] ?? 0);
          if ($physicalQty <= 0) continue;
          $stockItem['qtd'] = $physicalQty;
          $stockOutItems[] = $stockItem;
        }
        if ($stockOutItems) gestao_pdv_apply_stock_out($pdo, $companyId, $orderId, (string)$orderNumber, $stockOutItems, $pdvSaleOperation);
      }

      foreach ($payments as $line) {
        $payment = $line['payment'];
        $amount = (float)$line['amount'];
        $isVoucher = !empty($line['is_voucher']);
        $bankId = $isVoucher ? 0 : gestao_pdv_cash_account($pdo, $companyId, $userId, (int)($payment['default_bank_account_id'] ?? 0));
        if (!$isVoucher) gestao_pdv_assert_cash_account($pdo, $companyId, $bankId, 'Este usuário não possui permissão para usar o caixa/banco da forma de pagamento.');
        $chartId = $isVoucher ? 0 : ((int)($payment['default_chart_account_id'] ?? 0) ?: gestao_default_chart_id($pdo, $companyId, 'CREDITO'));
        $costId = $isVoucher ? 0 : (int)($payment['default_cost_center_id'] ?? 0);
        $movementId = null;
        $receivableId = null;
        $receivableIds = [];

        $isCardControl = !empty($line['card_plan']) && !empty($payment['card_control_enabled']);
        if ($isVoucher) {
          require_once __DIR__ . '/voucher_module.php';
          voucher_apply_order_usage($pdo, $companyId, $customerId, $orderId, (int)$line['payment_id'], (array)($line['voucher_allocations'] ?? []), $userId);
        } elseif ($isCardControl) {
          // Agenda de cartão: o dinheiro ainda não entrou. A parcela será baixada no Controle de Cartões.
          $movementId = null;
          $receivableId = null;
          $receivableIds = [];
        } elseif (!empty($payment['generate_receivable'])) {
          $schedule = $line['installments'] ?? [];
          if (!$schedule) $schedule = gestao_default_installment_schedule($payment, $amount, date('Y-m-d'));
          $totalInstallments = count($schedule);
          foreach ($schedule as $idxParc => $parc) {
            $parcAmount = round((float)($parc['amount'] ?? 0), 2);
            if ($parcAmount <= 0) continue;
            $due = finance_date_or_null($parc['due_date'] ?? '') ?: date('Y-m-d');
            $installmentLabel = str_pad((string)($idxParc + 1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$totalInstallments, 2, '0', STR_PAD_LEFT);
            $pdo->prepare("INSERT INTO finance_receivables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, paid_amount, open_amount, status, notes) VALUES (?, ?, ?, ?, ?, ?, ?, CURDATE(), ?, ?, ?, 0, ?, 'ABERTO', ?)")
              ->execute([$companyId, $customerId, $chartId, $costId ?: null, 'Pedido PDV #' . $orderNumber . ' - ' . (string)$payment['name'], (string)$orderNumber, $installmentLabel, $due, (string)$payment['name'], $parcAmount, $parcAmount, $notes ?: null]);
            $newReceivableId = (int)$pdo->lastInsertId();
            $receivableIds[] = $newReceivableId;

            // Se a forma for Boleto e a conta bancária estiver habilitada para cobrança,
            // cria automaticamente a parcela na fila Financeiro -> Cobranças e Boletos.
            if ($billingOrderQueueReady && billing_is_boleto_method((array)$payment)) {
              try {
                $billingPrepared = billing_prepare_boleto_for_order_receivable(
                  $pdo,
                  $companyId,
                  $orderId,
                  $newReceivableId,
                  $bankId,
                  (int)$line['payment_id'],
                  $userId,
                  (string)$orderNumber
                );
                if (billing_payment_method_auto_register((array)$payment) && !empty($billingPrepared['charge_id'])) {
                  $billingAutoChargeIds[] = (int)$billingPrepared['charge_id'];
                }
              } catch (Throwable $billingQueueError) {
                // A venda/conta a receber não é perdida por indisponibilidade da fila.
                // O erro fica no log da aplicação para diagnóstico.
                error_log('[Nexa][Boleto Pedido #' . $orderNumber . '] ' . $billingQueueError->getMessage());
              }
            }
          }
          $receivableId = $receivableIds[0] ?? null;
        } else {
          $pdo->prepare("INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes) VALUES (?, CURDATE(), 'CREDITO', ?, ?, ?, ?, 'PEDIDO', ?, ?, ?, ?, ?)")
            ->execute([$companyId, $customerId ?: null, $chartId, $costId ?: null, $bankId ?: null, $orderId, 'Venda PDV #' . $orderNumber . ' - ' . (string)$payment['name'], (string)$orderNumber, $amount, $notes ?: null]);
          $movementId = (int)$pdo->lastInsertId();
          if ($session && !empty($payment['affects_cash'])) {
            gestao_create_cash_operation($pdo, $companyId, $userId, (int)$session['id'], 'VENDA', $amount, $bankId ?: null, null, $costId ?: null, 'Venda PDV #' . $orderNumber . ' - ' . (string)$payment['name'], '', $orderId);
          }
        }

        $pdo->prepare('INSERT INTO gestao_order_payments (company_id, order_id, cash_session_id, payment_method_id, payment_label, amount, received_amount, change_amount, bank_account_id, chart_account_id, cost_center_id, finance_movement_id, receivable_id, receivable_ids) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
          ->execute([$companyId, $orderId, $session ? (int)$session['id'] : null, (int)$line['payment_id'], (string)$payment['name'], $amount, (float)$line['received'], (float)$line['change'], $bankId ?: null, $chartId ?: null, $costId ?: null, $movementId, $receivableId, !empty($receivableIds) ? implode(',', $receivableIds) : null]);
        $orderPaymentId = (int)$pdo->lastInsertId();
        if ($isCardControl) {
          card_control_register_order_payment($pdo, $companyId, $orderId, $orderPaymentId, $line, $customerId);
        }
      }

      try { $pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)')->execute([$companyId, $orderId]); } catch (Throwable $e) {}

      $pdo->commit();

      // O registro bancário é feito somente depois do commit do Pedido/Contas a Receber.
      // Assim, uma indisponibilidade do Itaú nunca desfaz a venda já concluída.
      if ($billingAutoChargeIds) {
        foreach (array_values(array_unique($billingAutoChargeIds)) as $autoChargeId) {
          $autoResult = billing_auto_register_charge($pdo, $companyId, (int)$autoChargeId, $userId);
          if (!empty($autoResult['attempted']) && empty($autoResult['ok'])) {
            error_log('[Nexa][Boleto Auto][Pedido #'.$orderNumber.'][Cobrança #'.(int)$autoChargeId.'] '.(string)($autoResult['error'] ?? $autoResult['message'] ?? 'Falha no registro automático.'));
          }
        }
      }

      gestao_try_send_pdv_whatsapp($pdo, $companyId, $orderId, $nomeCliente, $whats, $items, $subtotal, $discount, $addition, $delivery, $totalOrder, $tipoEntrega, $paymentLabel, $districtText, $addressText, $zipCode, $notes, $scheduledDeliveryDate, $scheduledDeliveryTime, $orderNumber);
      return $orderId;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
