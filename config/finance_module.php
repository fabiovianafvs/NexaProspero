<?php
declare(strict_types=1);

// NEXA_PATCH_PLANO_CLASSIFICACAO_V1_20260805

require_once __DIR__ . '/access_control.php';
require_once __DIR__ . '/commercial_rules.php';
require_once __DIR__ . '/cash_receivables_integration.php';
require_once __DIR__ . '/cash_operator_guard.php';

/**
 * Módulo Financeiro - helpers de estrutura, formatação e relatórios.
 * Mantido sem foreign keys para facilitar instalação em bases já existentes.
 */

if (!function_exists('finance_sql_identifier_ok')) {
  function finance_sql_identifier_ok(string $name): bool {
    return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name);
  }
}

if (!function_exists('finance_table_exists')) {
  function finance_table_exists(PDO $pdo, string $table): bool {
    if (!finance_sql_identifier_ok($table)) return false;
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
      $st->execute([$table]);
      if ((int)$st->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}
    try {
      $pdo->query('SELECT 1 FROM `' . $table . '` LIMIT 1');
      return true;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('finance_column_exists')) {
  function finance_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!finance_sql_identifier_ok($table) || !finance_sql_identifier_ok($column)) return false;
    // V11.6.3: não usar SHOW COLUMNS ... LIKE ? com native prepares.
    // O Nexa usa PDO::ATTR_EMULATE_PREPARES=false e alguns servidores MySQL/MariaDB
    // recusam placeholders em SHOW, fazendo uma coluna existente parecer ausente.
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
      $st->execute([$table, $column]);
      if ((int)$st->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}
    // Fallback sem INFORMATION_SCHEMA: se a coluna existir, a preparação/execução
    // desta consulta é válida; se não existir, o banco lança exceção.
    try {
      $pdo->query('SELECT `' . $column . '` FROM `' . $table . '` LIMIT 0');
      return true;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('finance_index_exists')) {
  function finance_index_exists(PDO $pdo, string $table, string $index): bool {
    if (!finance_sql_identifier_ok($table) || !finance_sql_identifier_ok($index)) return false;
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND INDEX_NAME=?');
      $st->execute([$table, $index]);
      if ((int)$st->fetchColumn() > 0) return true;
    } catch (Throwable $e) {}
    // Fallback para servidores onde INFORMATION_SCHEMA esteja restrito.
    try {
      $rows = $pdo->query('SHOW INDEX FROM `' . $table . '`')->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $row) {
        if (strcasecmp((string)($row['Key_name'] ?? ''), $index) === 0) return true;
      }
    } catch (Throwable $e) {}
    return false;
  }
}

if (!function_exists('finance_try_exec')) {
  function finance_try_exec(PDO $pdo, string $sql, array &$errors = []): void {
    try { $pdo->exec($sql); } catch (Throwable $e) { $errors[] = $e->getMessage(); }
  }
}

if (!function_exists('finance_add_column_if_missing')) {
  function finance_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition, array &$errors = []): void {
    if (!finance_sql_identifier_ok($table) || !finance_sql_identifier_ok($column)) return;
    if (finance_column_exists($pdo, $table, $column)) return;
    finance_try_exec($pdo, 'ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition, $errors);
  }
}

if (!function_exists('finance_ensure_schema')) {
  function finance_ensure_schema(PDO $pdo): array {
    $errors = [];

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_people (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        name VARCHAR(160) NOT NULL,
        fantasy_name VARCHAR(160) NULL,
        document VARCHAR(30) NULL,
        state_registration VARCHAR(30) NULL,
        municipal_registration VARCHAR(30) NULL,
        identity_document VARCHAR(40) NULL,
        identity_issuer VARCHAR(40) NULL,
        identity_state VARCHAR(2) NULL,
        person_type VARCHAR(20) NOT NULL DEFAULT 'JURIDICA',
        birth_opening_date DATE NULL,
        email VARCHAR(160) NULL,
        phone VARCHAR(40) NULL,
        whatsapp VARCHAR(40) NULL,
        zip_code VARCHAR(20) NULL,
        address VARCHAR(160) NULL,
        number VARCHAR(30) NULL,
        complement VARCHAR(120) NULL,
        district VARCHAR(100) NULL,
        city VARCHAR(100) NULL,
        city_ibge VARCHAR(7) NULL,
        state VARCHAR(2) NULL,
        is_customer TINYINT(1) NOT NULL DEFAULT 0,
        is_supplier TINYINT(1) NOT NULL DEFAULT 0,
        is_seller TINYINT(1) NOT NULL DEFAULT 0,
        is_employee TINYINT(1) NOT NULL DEFAULT 0,
        is_professional TINYINT(1) NOT NULL DEFAULT 0,
        is_carrier TINYINT(1) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_people_company_name (company_id, name),
        KEY idx_fin_people_company_doc (company_id, document)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_add_column_if_missing($pdo, 'finance_people', 'is_professional', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_employee', $errors);
    finance_add_column_if_missing($pdo, 'finance_people', 'is_carrier', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER is_professional', $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_chart_accounts (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        parent_id INT NULL,
        code VARCHAR(40) NOT NULL,
        classification VARCHAR(80) NULL,
        name VARCHAR(180) NOT NULL,
        account_type VARCHAR(30) NOT NULL DEFAULT 'OUTRA',
        movement_type VARCHAR(20) NOT NULL DEFAULT 'AMBOS',
        level INT NOT NULL DEFAULT 1,
        is_analytic TINYINT(1) NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_fin_chart_company_code (company_id, code),
        KEY idx_fin_chart_parent (company_id, parent_id),
        KEY idx_fin_chart_type (company_id, account_type)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_cost_centers (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        parent_id INT NULL,
        code VARCHAR(40) NULL,
        name VARCHAR(180) NOT NULL,
        level INT NOT NULL DEFAULT 1,
        is_analytic TINYINT(1) NOT NULL DEFAULT 1,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_cost_company_name (company_id, name),
        KEY idx_fin_cost_parent (company_id, parent_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_bank_accounts (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        name VARCHAR(120) NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'BANCO',
        bank_name VARCHAR(120) NULL,
        agency VARCHAR(30) NULL,
        account_number VARCHAR(50) NULL,
        initial_balance DECIMAL(15,2) NOT NULL DEFAULT 0,
        active TINYINT(1) NOT NULL DEFAULT 1,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_bank_company_name (company_id, name)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_receivables (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        person_id INT NOT NULL,
        chart_account_id INT NOT NULL,
        cost_center_id INT NULL,
        description VARCHAR(220) NULL,
        document_number VARCHAR(60) NULL,
        installment VARCHAR(20) NULL,
        issue_date DATE NULL,
        due_date DATE NULL,
        expected_payment_method VARCHAR(80) NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        fine_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        open_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_rec_company_due (company_id, due_date),
        KEY idx_fin_rec_company_status (company_id, status),
        KEY idx_fin_rec_person (company_id, person_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_payables (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        person_id INT NOT NULL,
        chart_account_id INT NOT NULL,
        cost_center_id INT NULL,
        description VARCHAR(220) NULL,
        document_number VARCHAR(60) NULL,
        installment VARCHAR(20) NULL,
        issue_date DATE NULL,
        due_date DATE NULL,
        expected_payment_method VARCHAR(80) NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        fine_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        paid_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        open_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        status VARCHAR(20) NOT NULL DEFAULT 'ABERTO',
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_pay_company_due (company_id, due_date),
        KEY idx_fin_pay_company_status (company_id, status),
        KEY idx_fin_pay_person (company_id, person_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_settlements (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        origin_type VARCHAR(20) NOT NULL,
        origin_id INT NOT NULL,
        settlement_date DATE NOT NULL,
        payment_method VARCHAR(80) NULL,
        bank_account_id INT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        fine_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        discount_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        waived_fine_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        waived_interest_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        waiver_user_id INT NULL,
        notes TEXT NULL,
        movement_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_fin_set_origin (company_id, origin_type, origin_id),
        KEY idx_fin_set_date (company_id, settlement_date)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_settlement_payments (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        settlement_id INT NOT NULL,
        payment_method_id INT NULL,
        payment_method VARCHAR(120) NULL,
        bank_account_id INT NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        movement_id INT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_setpay_settlement (company_id, settlement_id),
        KEY idx_fin_setpay_method (company_id, payment_method_id),
        KEY idx_fin_setpay_movement (company_id, movement_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_movements (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        movement_date DATE NOT NULL,
        type VARCHAR(20) NOT NULL,
        person_id INT NULL,
        chart_account_id INT NOT NULL,
        cost_center_id INT NULL,
        bank_account_id INT NULL,
        origin_type VARCHAR(30) NOT NULL DEFAULT 'MANUAL',
        origin_id INT NULL,
        description VARCHAR(220) NULL,
        document_number VARCHAR(60) NULL,
        amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        notes TEXT NULL,
        status VARCHAR(20) NOT NULL DEFAULT 'ATIVO',
        canceled_at DATETIME NULL,
        canceled_reason VARCHAR(255) NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (id),
        KEY idx_fin_mov_company_date (company_id, movement_date),
        KEY idx_fin_mov_chart (company_id, chart_account_id),
        KEY idx_fin_mov_origin (company_id, origin_type, origin_id),
        KEY idx_fin_mov_bank (company_id, bank_account_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);


    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_fixed_expenses (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        person_id INT NOT NULL,
        chart_account_id INT NOT NULL,
        cost_center_id INT NULL,
        bank_account_id INT NULL,
        description VARCHAR(220) NOT NULL,
        document_prefix VARCHAR(40) NULL,
        first_due_date DATE NOT NULL,
        due_day TINYINT UNSIGNED NOT NULL DEFAULT 1,
        average_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        expected_payment_method VARCHAR(80) NULL,
        months_mask CHAR(12) NOT NULL DEFAULT '111111111111',
        horizon_count TINYINT UNSIGNED NOT NULL DEFAULT 12,
        active TINYINT(1) NOT NULL DEFAULT 1,
        notes TEXT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        KEY idx_fin_fixed_company_active (company_id, active),
        KEY idx_fin_fixed_person (company_id, person_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_fixed_expense_titles (
        id INT NOT NULL AUTO_INCREMENT,
        company_id INT NOT NULL,
        fixed_expense_id INT NOT NULL,
        payable_id INT NOT NULL,
        reference_month DATE NOT NULL,
        generated_amount DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (id),
        UNIQUE KEY uk_fin_fixed_reference (company_id, fixed_expense_id, reference_month),
        UNIQUE KEY uk_fin_fixed_payable (company_id, payable_id),
        KEY idx_fin_fixed_title_rule (company_id, fixed_expense_id),
        KEY idx_fin_fixed_title_payable (company_id, payable_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    finance_try_exec($pdo, "
      CREATE TABLE IF NOT EXISTS finance_settings (
        company_id INT NOT NULL,
        settle_titles_individually TINYINT(1) NOT NULL DEFAULT 0,
        cre_rules_enabled TINYINT(1) NOT NULL DEFAULT 0,
        cre_daily_interest_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        cre_late_fee_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        cre_installment_period_days INT NOT NULL DEFAULT 30,
        cre_discount_before_due_percent DECIMAL(9,4) NOT NULL DEFAULT 0,
        cre_grace_days INT NOT NULL DEFAULT 0,
        cre_first_due_days INT NOT NULL DEFAULT 30,
        cre_keep_month_due_day TINYINT(1) NOT NULL DEFAULT 0,
        cre_fixed_due_day TINYINT UNSIGNED NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NULL,
        PRIMARY KEY (company_id)
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ", $errors);

    // Colunas defensivas para bases que já tenham parte da estrutura.
    $tables = ['finance_people','finance_chart_accounts','finance_cost_centers','finance_bank_accounts','finance_receivables','finance_payables','finance_settlements','finance_settlement_payments','finance_movements','finance_fixed_expenses','finance_fixed_expense_titles','finance_settings'];
    foreach ($tables as $table) {
      if (finance_table_exists($pdo, $table)) {
        finance_add_column_if_missing($pdo, $table, 'company_id', 'INT NOT NULL DEFAULT 0', $errors);
        finance_add_column_if_missing($pdo, $table, 'created_at', 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP', $errors);
        finance_add_column_if_missing($pdo, $table, 'updated_at', 'DATETIME NULL', $errors);
        if ($table === 'finance_people') {
          finance_add_column_if_missing($pdo, $table, 'birth_opening_date', 'DATE NULL', $errors);
          finance_add_column_if_missing($pdo, $table, 'state_registration', 'VARCHAR(30) NULL AFTER `document`', $errors);
          finance_add_column_if_missing($pdo, $table, 'municipal_registration', 'VARCHAR(30) NULL AFTER `state_registration`', $errors);
          finance_add_column_if_missing($pdo, $table, 'identity_document', 'VARCHAR(40) NULL AFTER `municipal_registration`', $errors);
          finance_add_column_if_missing($pdo, $table, 'identity_issuer', 'VARCHAR(40) NULL AFTER `identity_document`', $errors);
          finance_add_column_if_missing($pdo, $table, 'identity_state', 'VARCHAR(2) NULL AFTER `identity_issuer`', $errors);
          finance_add_column_if_missing($pdo, $table, 'city_ibge', 'VARCHAR(7) NULL', $errors);
        }
        if ($table === 'finance_chart_accounts') {
          finance_add_column_if_missing($pdo, $table, 'classification', 'VARCHAR(80) NULL AFTER `code`', $errors);
        }
        if ($table === 'finance_settlements') {
          finance_add_column_if_missing($pdo, $table, 'waived_fine_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'waived_interest_amount', 'DECIMAL(15,2) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'waiver_user_id', 'INT NULL', $errors);
        }
        if ($table === 'finance_movements') {
          finance_add_column_if_missing($pdo, $table, 'status', "VARCHAR(20) NOT NULL DEFAULT 'ATIVO'", $errors);
          finance_add_column_if_missing($pdo, $table, 'canceled_at', 'DATETIME NULL', $errors);
          finance_add_column_if_missing($pdo, $table, 'canceled_reason', 'VARCHAR(255) NULL', $errors);
        }
        if ($table === 'finance_fixed_expenses') {
          finance_add_column_if_missing($pdo, $table, 'bank_account_id', 'INT NULL', $errors);
          finance_add_column_if_missing($pdo, $table, 'document_prefix', 'VARCHAR(40) NULL', $errors);
          finance_add_column_if_missing($pdo, $table, 'months_mask', "CHAR(12) NOT NULL DEFAULT '111111111111'", $errors);
          finance_add_column_if_missing($pdo, $table, 'horizon_count', 'TINYINT UNSIGNED NOT NULL DEFAULT 12', $errors);
          finance_add_column_if_missing($pdo, $table, 'active', 'TINYINT(1) NOT NULL DEFAULT 1', $errors);
        }
        if ($table === 'finance_settings') {
          finance_add_column_if_missing($pdo, $table, 'settle_titles_individually', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_rules_enabled', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_daily_interest_percent', 'DECIMAL(9,4) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_late_fee_percent', 'DECIMAL(9,4) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_installment_period_days', 'INT NOT NULL DEFAULT 30', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_discount_before_due_percent', 'DECIMAL(9,4) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_grace_days', 'INT NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_first_due_days', 'INT NOT NULL DEFAULT 30', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_keep_month_due_day', 'TINYINT(1) NOT NULL DEFAULT 0', $errors);
          finance_add_column_if_missing($pdo, $table, 'cre_fixed_due_day', 'TINYINT UNSIGNED NULL', $errors);
        }
      }
    }

    if (finance_table_exists($pdo, 'finance_chart_accounts') && finance_column_exists($pdo, 'finance_chart_accounts', 'classification')) {
      finance_try_exec($pdo, "UPDATE finance_chart_accounts SET classification = code WHERE classification IS NULL OR TRIM(classification) = ''", $errors);
      if (!finance_index_exists($pdo, 'finance_chart_accounts', 'idx_fin_chart_classification')) {
        finance_try_exec($pdo, "CREATE INDEX idx_fin_chart_classification ON finance_chart_accounts (company_id, classification)", $errors);
      }
    }

    try { finance_seed_defaults($pdo); } catch (Throwable $e) { $errors[] = $e->getMessage(); }

    $errors = array_values(array_filter($errors, function ($msg) {
      return stripos((string)$msg, 'Duplicate key name') === false
        && stripos((string)$msg, 'Duplicate column name') === false
        && stripos((string)$msg, 'Multiple primary key') === false;
    }));

    return ['ok' => finance_table_exists($pdo, 'finance_people') && finance_table_exists($pdo, 'finance_chart_accounts'), 'errors' => $errors];
  }
}

if (!function_exists('finance_schema_ok')) {
  function finance_schema_ok(PDO $pdo): bool {
    return finance_table_exists($pdo, 'finance_people')
      && finance_table_exists($pdo, 'finance_chart_accounts')
      && finance_table_exists($pdo, 'finance_cost_centers')
      && finance_table_exists($pdo, 'finance_receivables')
      && finance_table_exists($pdo, 'finance_payables')
      && finance_table_exists($pdo, 'finance_movements');
  }
}

if (!function_exists('finance_seed_defaults')) {
  function finance_seed_defaults(PDO $pdo): void {
    if (!defined('COMPANY_ID')) return;
    $companyId = (int)COMPANY_ID;
    if ($companyId <= 0) return;

    if (finance_table_exists($pdo, 'finance_chart_accounts')) {
      $st = $pdo->prepare('SELECT COUNT(*) FROM finance_chart_accounts WHERE company_id = ?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO finance_chart_accounts (company_id, parent_id, code, classification, name, account_type, movement_type, level, is_analytic, active, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)');
        $ins->execute([$companyId, null, '000001', '1', 'RESULTADO FINAL', 'RESULTADO', 'AMBOS', 1, 0, 1]);
        $root = (int)$pdo->lastInsertId();
        $ins->execute([$companyId, $root, '000002', '1.01', 'RECEITAS', 'RECEITA', 'CREDITO', 2, 0, 2]);
        $receitas = (int)$pdo->lastInsertId();
        $ins->execute([$companyId, $receitas, '000003', '1.01.001', 'RECEITAS SOBRE VENDAS/SERVIÇOS', 'RECEITA', 'CREDITO', 3, 1, 3]);
        $ins->execute([$companyId, $root, '000006', '1.02', 'DESPESAS', 'DESPESA', 'DEBITO', 2, 0, 6]);
        $despesas = (int)$pdo->lastInsertId();
        $ins->execute([$companyId, $despesas, '000011', '1.02.03', 'DESPESAS OPERACIONAIS', 'DESPESA', 'DEBITO', 3, 0, 11]);
        $oper = (int)$pdo->lastInsertId();
        $ins->execute([$companyId, $oper, '000012', '1.02.03.001', 'ENERGIA', 'DESPESA', 'DEBITO', 4, 1, 12]);
        $ins->execute([$companyId, $oper, '000013', '1.02.03.002', 'TELEFONE/INTERNET', 'DESPESA', 'DEBITO', 4, 1, 13]);
        $ins->execute([$companyId, $oper, '000014', '1.02.03.003', 'CONTABILIDADE', 'DESPESA', 'DEBITO', 4, 1, 14]);
      }
    }

    if (finance_table_exists($pdo, 'finance_cost_centers')) {
      $st = $pdo->prepare('SELECT COUNT(*) FROM finance_cost_centers WHERE company_id = ?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO finance_cost_centers (company_id, parent_id, code, name, level, is_analytic, active) VALUES (?, NULL, ?, ?, 1, 1, 1)');
        $ins->execute([$companyId, '001', 'Administrativo']);
        $ins->execute([$companyId, '002', 'Comercial']);
      }
    }

    if (finance_table_exists($pdo, 'finance_bank_accounts')) {
      $st = $pdo->prepare('SELECT COUNT(*) FROM finance_bank_accounts WHERE company_id = ?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() === 0) {
        $ins = $pdo->prepare('INSERT INTO finance_bank_accounts (company_id, name, type, initial_balance, active) VALUES (?, ?, ?, 0, 1)');
        $ins->execute([$companyId, 'Caixa Interno', 'CAIXA']);
        $ins->execute([$companyId, 'Banco Principal', 'BANCO']);
      }
    }
  }
}

if (!function_exists('finance_money_in')) {
  function finance_money_in($value): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (str_contains($s, ',')) {
      $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
    }
    return round((float)$s, 2);
  }
}

if (!function_exists('finance_date_or_null')) {
  function finance_date_or_null($value): ?string {
    $s = trim((string)$value);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
  }
}

if (!function_exists('finance_money_br')) {
  function finance_money_br($value): string {
    return number_format((float)$value, 2, ',', '.');
  }
}

if (!function_exists('finance_date_br')) {
  function finance_date_br($value): string {
    $s = trim((string)$value);
    if ($s === '' || $s === '0000-00-00') return '';
    $ts = strtotime($s);
    return $ts ? date('d/m/Y', $ts) : $s;
  }
}

if (!function_exists('finance_redirect')) {
  function finance_redirect(string $url): never {
    if (function_exists('multi_company_context_url')) {
      try { $url = multi_company_context_url($url); } catch (Throwable $e) {}
    }
    header('Location: ' . $url);
    exit;
  }
}

if (!function_exists('finance_query_all')) {
  function finance_query_all(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('finance_query_one')) {
  function finance_query_one(PDO $pdo, string $sql, array $params = []): ?array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('finance_chart_normalize_classification')) {
  function finance_chart_normalize_classification(string $value): string {
    $value = trim($value);
    $value = preg_replace('/\s+/u', '', $value) ?? $value;
    $value = preg_replace('/[^0-9A-Za-z._-]/u', '', $value) ?? $value;
    $value = preg_replace('/\.+/', '.', $value) ?? $value;
    return trim($value, '.');
  }
}

if (!function_exists('finance_chart_parent_classification')) {
  function finance_chart_parent_classification(string $classification): string {
    $classification = finance_chart_normalize_classification($classification);
    if ($classification === '' || !str_contains($classification, '.')) return '';
    $parts = explode('.', $classification);
    array_pop($parts);
    return implode('.', $parts);
  }
}

if (!function_exists('finance_chart_compare_rows')) {
  function finance_chart_compare_rows(array $a, array $b): int {
    $rawA = trim((string)($a['classification'] ?? ''));
    $rawB = trim((string)($b['classification'] ?? ''));
    if ($rawA === '') $rawA = (string)($a['code'] ?? '');
    if ($rawB === '') $rawB = (string)($b['code'] ?? '');
    $ca = finance_chart_normalize_classification($rawA);
    $cb = finance_chart_normalize_classification($rawB);
    $cmp = strnatcasecmp($ca, $cb);
    if ($cmp !== 0) return $cmp;
    $oa = (int)($a['display_order'] ?? 0);
    $ob = (int)($b['display_order'] ?? 0);
    if ($oa !== $ob) return $oa <=> $ob;
    $cmp = strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? ''));
    if ($cmp !== 0) return $cmp;
    return ((int)($a['id'] ?? 0)) <=> ((int)($b['id'] ?? 0));
  }
}

if (!function_exists('finance_chart_sort_rows')) {
  function finance_chart_sort_rows(array $rows): array {
    usort($rows, 'finance_chart_compare_rows');
    return $rows;
  }
}

if (!function_exists('finance_chart_find_parent_id_by_classification')) {
  function finance_chart_find_parent_id_by_classification(PDO $pdo, int $companyId, string $classification, int $excludeId = 0): int {
    $parentClass = finance_chart_parent_classification($classification);
    while ($parentClass !== '') {
      $sql = "SELECT id FROM finance_chart_accounts WHERE company_id=? AND active=1 AND id<>? AND TRIM(COALESCE(NULLIF(classification,''), code))=? ORDER BY id LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([$companyId, $excludeId, $parentClass]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) return $id;
      $parentClass = finance_chart_parent_classification($parentClass);
    }
    return 0;
  }
}

if (!function_exists('finance_chart_rebuild_hierarchy')) {
  function finance_chart_rebuild_hierarchy(PDO $pdo, int $companyId): void {
    $rows = finance_query_all($pdo, 'SELECT id, parent_id, code, classification, level FROM finance_chart_accounts WHERE company_id=? AND active=1 ORDER BY id', [$companyId]);
    if (!$rows) return;
    $byId = [];
    $classToId = [];
    foreach ($rows as $r) {
      $id = (int)$r['id'];
      $r['_original_classification'] = trim((string)($r['classification'] ?? ''));
      $rawClassification = $r['_original_classification'] !== '' ? $r['_original_classification'] : (string)($r['code'] ?? '');
      $classification = finance_chart_normalize_classification($rawClassification);
      $r['classification'] = $classification;
      $byId[$id] = $r;
      if ($classification !== '' && !isset($classToId[$classification])) $classToId[$classification] = $id;
    }
    $parents = [];
    foreach ($byId as $id => $r) {
      $classParent = finance_chart_parent_classification((string)$r['classification']);
      $inferred = 0;
      while ($classParent !== '') {
        if (isset($classToId[$classParent]) && (int)$classToId[$classParent] !== $id) {
          $inferred = (int)$classToId[$classParent];
          break;
        }
        $classParent = finance_chart_parent_classification($classParent);
      }
      $explicit = (int)($r['parent_id'] ?? 0);
      $parents[$id] = $inferred > 0 ? $inferred : (($explicit > 0 && isset($byId[$explicit]) && $explicit !== $id) ? $explicit : 0);
    }
    $levels = [];
    $visiting = [];
    $levelOf = function (int $id) use (&$levelOf, &$levels, &$visiting, &$parents): int {
      if (isset($levels[$id])) return $levels[$id];
      if (isset($visiting[$id])) return 1;
      $visiting[$id] = true;
      $pid = (int)($parents[$id] ?? 0);
      $level = $pid > 0 ? ($levelOf($pid) + 1) : 1;
      unset($visiting[$id]);
      return $levels[$id] = max(1, $level);
    };
    $up = $pdo->prepare('UPDATE finance_chart_accounts SET parent_id=?, classification=?, level=?, updated_at=NOW() WHERE company_id=? AND id=?');
    foreach ($byId as $id => $r) {
      $pid = (int)($parents[$id] ?? 0);
      $level = $levelOf((int)$id);
      $currentPid = (int)($r['parent_id'] ?? 0);
      $currentLevel = (int)($r['level'] ?? 1);
      $currentClassification = trim((string)($r['_original_classification'] ?? ''));
      if ($currentPid !== $pid || $currentLevel !== $level || $currentClassification !== (string)$r['classification']) {
        $up->execute([$pid > 0 ? $pid : null, (string)$r['classification'], $level, $companyId, $id]);
      }
    }
  }
}

if (!function_exists('finance_people_options')) {
  function finance_people_options(PDO $pdo, int $companyId, string $role = ''): array {
    $where = ['company_id = ?', 'active = 1'];
    $params = [$companyId];
    if ($role === 'customer') $where[] = 'is_customer = 1';
    if ($role === 'supplier') $where[] = 'is_supplier = 1';
    if ($role === 'seller') $where[] = 'is_seller = 1';
    return finance_query_all($pdo, 'SELECT id, name, document FROM finance_people WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC', $params);
  }
}

if (!function_exists('finance_validate_person_role')) {
  function finance_validate_person_role(PDO $pdo, int $companyId, int $personId, string $role = ''): bool {
    if ($companyId <= 0 || $personId <= 0) return false;
    $roleColumn = match (strtolower(trim($role))) {
      'customer' => 'is_customer',
      'supplier' => 'is_supplier',
      'seller' => 'is_seller',
      default => '',
    };
    $sql = 'SELECT 1 FROM finance_people WHERE company_id=? AND id=? AND active=1';
    if ($roleColumn !== '') $sql .= ' AND ' . $roleColumn . '=1';
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $personId]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('finance_chart_options')) {
  function finance_chart_options(PDO $pdo, int $companyId, string $movement = ''): array {
    $where = ['company_id = ?', 'active = 1'];
    $params = [$companyId];
    if ($movement === 'CREDITO') $where[] = "movement_type IN ('CREDITO','AMBOS')";
    if ($movement === 'DEBITO') $where[] = "movement_type IN ('DEBITO','AMBOS')";
    $rows = finance_query_all($pdo, 'SELECT id, code, classification, name, level, is_analytic, movement_type, display_order FROM finance_chart_accounts WHERE ' . implode(' AND ', $where), $params);
    return finance_chart_sort_rows($rows);
  }
}

if (!function_exists('finance_cost_center_options')) {
  function finance_cost_center_options(PDO $pdo, int $companyId): array {
    return finance_query_all($pdo, 'SELECT id, code, name, level, is_analytic FROM finance_cost_centers WHERE company_id = ? AND active = 1 ORDER BY COALESCE(code, name), name', [$companyId]);
  }
}

if (!function_exists('finance_bank_options')) {
  function finance_bank_options(PDO $pdo, int $companyId): array {
    $rows = finance_query_all($pdo, 'SELECT id, name, type FROM finance_bank_accounts WHERE company_id = ? AND active = 1 ORDER BY name ASC', [$companyId]);
    return function_exists('access_filter_cash_accounts') ? access_filter_cash_accounts($pdo, $companyId, $rows) : $rows;
  }
}

if (!function_exists('finance_validate_bank_account')) {
  function finance_validate_bank_account(PDO $pdo, int $companyId, ?int $bankId): bool {
    $bankId = (int)($bankId ?? 0);
    if ($bankId <= 0) return true;
    $st = $pdo->prepare('SELECT COUNT(*) FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1');
    $st->execute([$companyId, $bankId]);
    return (int)$st->fetchColumn() > 0;
  }
}

if (!function_exists('finance_validate_analytic_chart')) {
  function finance_validate_analytic_chart(PDO $pdo, int $companyId, int $chartId): bool {
    if ($chartId <= 0) return false;
    $st = $pdo->prepare('SELECT is_analytic FROM finance_chart_accounts WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1');
    $st->execute([$companyId, $chartId]);
    return (int)($st->fetchColumn() ?: 0) === 1;
  }
}

if (!function_exists('finance_validate_analytic_cost')) {
  function finance_validate_analytic_cost(PDO $pdo, int $companyId, ?int $costId): bool {
    if (!$costId) return true;
    $st = $pdo->prepare('SELECT is_analytic FROM finance_cost_centers WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1');
    $st->execute([$companyId, $costId]);
    return (int)($st->fetchColumn() ?: 0) === 1;
  }
}

if (!function_exists('finance_settings')) {
  function finance_settings(PDO $pdo, int $companyId, bool $createIfMissing = true): array {
    $defaults = [
      'settle_titles_individually' => 0,
      'cre_rules_enabled' => 0,
      'cre_daily_interest_percent' => 0.0,
      'cre_late_fee_percent' => 0.0,
      'cre_installment_period_days' => 30,
      'cre_discount_before_due_percent' => 0.0,
      'cre_grace_days' => 0,
      'cre_first_due_days' => 30,
      'cre_keep_month_due_day' => 0,
      'cre_fixed_due_day' => null,
    ];
    if ($companyId <= 0 || !finance_table_exists($pdo, 'finance_settings')) return $defaults;
    $row = finance_query_one($pdo, 'SELECT * FROM finance_settings WHERE company_id = ? LIMIT 1', [$companyId]);
    if (!$row) {
      if ($createIfMissing) {
        try {
          $st = $pdo->prepare('INSERT INTO finance_settings (company_id, settle_titles_individually) VALUES (?, 0)');
          $st->execute([$companyId]);
        } catch (Throwable $e) {}
      }
      return $defaults;
    }
    $out = $defaults;
    $out['settle_titles_individually'] = !empty($row['settle_titles_individually']) ? 1 : 0;
    $out['cre_rules_enabled'] = !empty($row['cre_rules_enabled']) ? 1 : 0;
    $out['cre_daily_interest_percent'] = max(0, (float)($row['cre_daily_interest_percent'] ?? 0));
    $out['cre_late_fee_percent'] = max(0, (float)($row['cre_late_fee_percent'] ?? 0));
    $out['cre_installment_period_days'] = max(1, min(365, (int)($row['cre_installment_period_days'] ?? 30)));
    $out['cre_discount_before_due_percent'] = max(0, min(100, (float)($row['cre_discount_before_due_percent'] ?? 0)));
    $out['cre_grace_days'] = max(0, min(365, (int)($row['cre_grace_days'] ?? 0)));
    $out['cre_first_due_days'] = max(0, min(365, (int)($row['cre_first_due_days'] ?? 30)));
    $out['cre_keep_month_due_day'] = !empty($row['cre_keep_month_due_day']) ? 1 : 0;
    $fixed = isset($row['cre_fixed_due_day']) ? (int)$row['cre_fixed_due_day'] : 0;
    $out['cre_fixed_due_day'] = ($fixed >= 1 && $fixed <= 31) ? $fixed : null;
    return $out;
  }
}

if (!function_exists('finance_save_settings')) {
  function finance_save_settings(PDO $pdo, int $companyId, array $data): void {
    if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
    finance_ensure_schema($pdo);
    $settleIndividually = !empty($data['settle_titles_individually']) ? 1 : 0;
    $creEnabled = array_key_exists('cre_rules_enabled', $data) ? (!empty($data['cre_rules_enabled']) ? 1 : 0) : 0;
    $dailyInterest = max(0, min(100, finance_money_in($data['cre_daily_interest_percent'] ?? 0)));
    $lateFee = max(0, min(100, finance_money_in($data['cre_late_fee_percent'] ?? 0)));
    $period = max(1, min(365, (int)($data['cre_installment_period_days'] ?? 30)));
    $discount = max(0, min(100, finance_money_in($data['cre_discount_before_due_percent'] ?? 0)));
    $grace = max(0, min(365, (int)($data['cre_grace_days'] ?? 0)));
    $firstDue = max(0, min(365, (int)($data['cre_first_due_days'] ?? 30)));
    $keepDay = !empty($data['cre_keep_month_due_day']) ? 1 : 0;
    $fixedRaw = trim((string)($data['cre_fixed_due_day'] ?? ''));
    $fixed = $fixedRaw === '' ? null : max(1, min(31, (int)$fixedRaw));
    $sql = 'INSERT INTO finance_settings (company_id, settle_titles_individually, cre_rules_enabled, cre_daily_interest_percent, cre_late_fee_percent, cre_installment_period_days, cre_discount_before_due_percent, cre_grace_days, cre_first_due_days, cre_keep_month_due_day, cre_fixed_due_day, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW()) ON DUPLICATE KEY UPDATE settle_titles_individually=VALUES(settle_titles_individually), cre_rules_enabled=VALUES(cre_rules_enabled), cre_daily_interest_percent=VALUES(cre_daily_interest_percent), cre_late_fee_percent=VALUES(cre_late_fee_percent), cre_installment_period_days=VALUES(cre_installment_period_days), cre_discount_before_due_percent=VALUES(cre_discount_before_due_percent), cre_grace_days=VALUES(cre_grace_days), cre_first_due_days=VALUES(cre_first_due_days), cre_keep_month_due_day=VALUES(cre_keep_month_due_day), cre_fixed_due_day=VALUES(cre_fixed_due_day), updated_at=NOW()';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $settleIndividually, $creEnabled, $dailyInterest, $lateFee, $period, $discount, $grace, $firstDue, $keepDay, $fixed]);
  }
}

if (!function_exists('finance_cre_next_fixed_day')) {
  function finance_cre_next_fixed_day(DateTimeImmutable $base, int $day): DateTimeImmutable {
    $day = max(1, min(31, $day));
    $year = (int)$base->format('Y');
    $month = (int)$base->format('n');
    for ($i = 0; $i < 24; $i++) {
      $first = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, $month));
      $last = (int)$first->format('t');
      $candidate = $first->setDate($year, $month, min($day, $last));
      if ($candidate > $base) return $candidate;
      $month++;
      if ($month > 12) { $month = 1; $year++; }
    }
    return $base->modify('+1 month');
  }
}

if (!function_exists('finance_cre_add_month_keep_day')) {
  function finance_cre_add_month_keep_day(DateTimeImmutable $date, int $months, int $day): DateTimeImmutable {
    $anchor = $date->modify('first day of this month')->modify(($months >= 0 ? '+' : '') . $months . ' months');
    $last = (int)$anchor->format('t');
    return $anchor->setDate((int)$anchor->format('Y'), (int)$anchor->format('n'), min(max(1, $day), $last));
  }
}

if (!function_exists('finance_cre_due_dates')) {
  function finance_cre_due_dates(array $settings, int $count, ?string $issueDate = null, ?string $firstDueOverride = null): array {
    $count = max(1, min(120, $count));
    $issue = new DateTimeImmutable($issueDate ?: date('Y-m-d'));
    $enabled = !empty($settings['cre_rules_enabled']);
    $period = max(1, min(365, (int)($settings['cre_installment_period_days'] ?? 30)));
    $firstDays = max(0, min(365, (int)($settings['cre_first_due_days'] ?? 30)));
    $keepDay = $enabled && !empty($settings['cre_keep_month_due_day']);
    $fixedDay = $enabled ? (int)($settings['cre_fixed_due_day'] ?? 0) : 0;

    if ($firstDueOverride) {
      try { $first = new DateTimeImmutable($firstDueOverride); } catch (Throwable $e) { $first = $issue->modify('+' . $firstDays . ' days'); }
    } elseif ($keepDay && $fixedDay >= 1 && $fixedDay <= 31) {
      $first = finance_cre_next_fixed_day($issue, $fixedDay);
    } else {
      $first = $issue->modify('+' . $firstDays . ' days');
    }

    $day = ($fixedDay >= 1 && $fixedDay <= 31) ? $fixedDay : (int)$first->format('j');
    $dates = [];
    for ($i = 0; $i < $count; $i++) {
      if ($i === 0) $due = $first;
      elseif ($keepDay) $due = finance_cre_add_month_keep_day($first, $i, $day);
      else $due = $first->modify('+' . ($period * $i) . ' days');
      $dates[] = $due->format('Y-m-d');
    }
    return $dates;
  }
}

if (!function_exists('finance_cre_title_principal_open')) {
  function finance_cre_title_principal_open(PDO $pdo, int $companyId, array $title): float {
    $amount = max(0, (float)($title['amount'] ?? 0));
    $id = (int)($title['id'] ?? 0);
    if ($id <= 0 || !finance_table_exists($pdo, 'finance_settlements')) return $amount;
    try {
      $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO'");
      $st->execute([$companyId, $id]);
      return max(0, round($amount - (float)($st->fetchColumn() ?: 0), 2));
    } catch (Throwable $e) { return $amount; }
  }
}

if (!function_exists('finance_cre_adjustments')) {
  function finance_cre_adjustments(PDO $pdo, int $companyId, array $title, ?string $settlementDate = null, ?float $principalAmount = null): array {
    $settings = finance_settings($pdo, $companyId);
    $settlement = new DateTimeImmutable($settlementDate ?: date('Y-m-d'));
    try { $due = new DateTimeImmutable((string)($title['due_date'] ?? date('Y-m-d'))); } catch (Throwable $e) { $due = $settlement; }
    $principalOpen = finance_cre_title_principal_open($pdo, $companyId, $title);
    $principal = $principalAmount === null ? $principalOpen : max(0, min($principalOpen, round($principalAmount, 2)));
    $lateDays = $settlement > $due ? (int)$due->diff($settlement)->format('%a') : 0;
    $fine = 0.0; $interest = 0.0; $discount = 0.0;
    if (!empty($settings['cre_rules_enabled'])) {
      if ($settlement <= $due && (float)$settings['cre_discount_before_due_percent'] > 0) {
        $discount = round($principal * ((float)$settings['cre_discount_before_due_percent'] / 100), 2);
      }
      if ($lateDays > 0) {
        $fineAlready = max(0.0, (float)($title['fine_amount'] ?? 0));
        $id = (int)($title['id'] ?? 0);
        if ($id > 0) {
          try {
            $st = $pdo->prepare("SELECT COALESCE(SUM(fine_amount),0) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO'");
            $st->execute([$companyId, $id]);
            $fineAlready = max($fineAlready, (float)($st->fetchColumn() ?: 0));
          } catch (Throwable $e) {}
        }
        if ($fineAlready <= 0.0001 && (float)$settings['cre_late_fee_percent'] > 0) {
          $fine = round(max(0, (float)($title['amount'] ?? 0)) * ((float)$settings['cre_late_fee_percent'] / 100), 2);
        }
        $grace = max(0, (int)$settings['cre_grace_days']);
        if ($lateDays > $grace && (float)$settings['cre_daily_interest_percent'] > 0) {
          // Passada a carência, cobra o período inteiro em atraso, conforme regra CRE.
          $interest = round($principal * ((float)$settings['cre_daily_interest_percent'] / 100) * $lateDays, 2);
        }
      }
    }
    return [
      'fine_amount' => $fine,
      'interest_amount' => $interest,
      'discount_amount' => $discount,
      'late_days' => $lateDays,
      'principal_open' => $principalOpen,
      'principal_amount' => $principal,
      'settings' => $settings,
    ];
  }
}

if (!function_exists('finance_doc_total')) {
  function finance_doc_total(array $r): float {
    return round((float)($r['amount'] ?? 0) + (float)($r['fine_amount'] ?? 0) + (float)($r['interest_amount'] ?? 0) - (float)($r['discount_amount'] ?? 0), 2);
  }
}

if (!function_exists('finance_update_title_status')) {
  function finance_update_title_status(PDO $pdo, string $table, int $companyId, int $id): void {
    if (!in_array($table, ['finance_receivables', 'finance_payables'], true)) return;
    $row = finance_query_one($pdo, "SELECT amount, fine_amount, interest_amount, discount_amount, paid_amount FROM {$table} WHERE company_id = ? AND id = ?", [$companyId, $id]);
    if (!$row) return;
    $total = finance_doc_total($row);
    $paid = round((float)$row['paid_amount'], 2);
    $open = max(0, round($total - $paid, 2));
    $status = $paid <= 0 ? 'ABERTO' : ($open <= 0.009 ? 'BAIXADO' : 'PARCIAL');
    $st = $pdo->prepare("UPDATE {$table} SET open_amount = ?, status = ?, updated_at = NOW() WHERE company_id = ? AND id = ?");
    $st->execute([$open, $status, $companyId, $id]);
  }
}

if (!function_exists('finance_create_movement_from_settlement')) {
  function finance_create_movement_from_settlement(PDO $pdo, int $companyId, string $originType, int $originId, array $settlement, array $title): int {
    $type = $originType === 'RECEBER' ? 'CREDITO' : 'DEBITO';
    $net = round((float)($settlement['amount'] ?? 0) + (float)($settlement['fine_amount'] ?? 0) + (float)($settlement['interest_amount'] ?? 0) - (float)($settlement['discount_amount'] ?? 0), 2);
    if ($net <= 0) return 0;
    $descPrefix = $originType === 'RECEBER' ? 'Baixa de contas a receber' : 'Baixa de contas a pagar';
    $st = $pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
      $companyId,
      $settlement['settlement_date'],
      $type,
      (int)($title['person_id'] ?? 0) ?: null,
      (int)($title['chart_account_id'] ?? 0),
      (int)($title['cost_center_id'] ?? 0) ?: null,
      (int)($settlement['bank_account_id'] ?? 0) ?: null,
      $originType,
      $originId,
      $descPrefix . ' #' . $originId . ' - ' . (string)($title['description'] ?? ''),
      (string)($title['document_number'] ?? ''),
      $net,
      (string)($settlement['notes'] ?? '')
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('finance_settlement_review')) {
  function finance_settlement_review(PDO $pdo, int $companyId, array $title, string $settlementDate, float $principal, float $fine, float $interest, float $discount, array $data): array {
    $auto = finance_cre_adjustments($pdo, $companyId, $title, $settlementDate, $principal);
    $expectedFine = max(0.0, round((float)($auto['fine_amount'] ?? 0), 2));
    $expectedInterest = max(0.0, round((float)($auto['interest_amount'] ?? 0), 2));
    $expectedDiscount = max(0.0, round((float)($auto['discount_amount'] ?? 0), 2));
    $waivedFine = max(0.0, round($expectedFine - $fine, 2));
    $waivedInterest = max(0.0, round($expectedInterest - $interest, 2));
    $waiverUserId = 0;
    if ($waivedFine > 0.009 || $waivedInterest > 0.009) {
      if (!function_exists('commercial_assert_settlement_waiver')) throw new RuntimeException('Permissões de dispensa não estão disponíveis.');
      $waiverUserId = commercial_assert_settlement_waiver(
        $pdo,
        $companyId,
        $waivedFine > 0.009,
        $waivedInterest > 0.009,
        trim((string)($data['waiver_auth_token'] ?? ''))
      );
    }
    $manualDiscount = max(0.0, round($discount - $expectedDiscount, 2));
    if (function_exists('commercial_validate_settlement_discount')) {
      commercial_validate_settlement_discount($pdo, $companyId, $principal, $manualDiscount);
    }
    return [
      'waived_fine_amount' => $waivedFine,
      'waived_interest_amount' => $waivedInterest,
      'waiver_user_id' => $waiverUserId,
      'automatic_fine_amount' => $expectedFine,
      'automatic_interest_amount' => $expectedInterest,
      'automatic_discount_amount' => $expectedDiscount,
      'manual_discount_amount' => $manualDiscount,
    ];
  }
}

if (!function_exists('finance_payment_splits_from_data')) {
  function finance_payment_splits_from_data(PDO $pdo, int $companyId, array $data, float $expectedTotal): array {
    $expectedTotal = round(max(0, $expectedTotal), 2);
    $methodIds = $data['payment_method_id'] ?? [];
    $amounts = $data['payment_amount'] ?? [];
    $bankIds = $data['payment_bank_account_id'] ?? [];
    if (!is_array($methodIds)) $methodIds = [$methodIds];
    if (!is_array($amounts)) $amounts = [$amounts];
    if (!is_array($bankIds)) $bankIds = [$bankIds];

    $requested = [];
    foreach ($methodIds as $i => $rawId) {
      $methodId = (int)$rawId;
      $amount = finance_money_in($amounts[$i] ?? 0);
      if ($methodId <= 0 && $amount <= 0) continue;
      if ($methodId <= 0) throw new RuntimeException('Selecione a forma de pagamento de todas as linhas informadas.');
      if ($amount <= 0) throw new RuntimeException('Informe um valor maior que zero para cada forma de pagamento.');
      $requested[] = ['method_id'=>$methodId, 'amount'=>round($amount,2), 'bank_account_id'=>(int)($bankIds[$i] ?? 0) ?: null];
    }

    if ($requested) {
      if (!finance_table_exists($pdo, 'finance_payment_methods')) throw new RuntimeException('Cadastro de formas de pagamento não encontrado.');
      $ids = array_values(array_unique(array_map(static fn($r)=>(int)$r['method_id'], $requested)));
      $ph = implode(',', array_fill(0, count($ids), '?'));
      if (finance_table_exists($pdo, 'finance_payment_method_defaults')) {
        $rows = finance_query_all($pdo, "SELECT pm.id,pm.name,COALESCE(fd.bank_account_id,pm.default_bank_account_id) AS default_bank_account_id,pm.affects_cash,pm.is_active FROM finance_payment_methods pm LEFT JOIN finance_payment_method_defaults fd ON fd.company_id=pm.company_id AND fd.payment_method_id=pm.id WHERE pm.company_id=? AND pm.id IN ({$ph}) AND pm.is_active=1", array_merge([$companyId], $ids));
      } else {
        $rows = finance_query_all($pdo, "SELECT id,name,default_bank_account_id,affects_cash,is_active FROM finance_payment_methods WHERE company_id=? AND id IN ({$ph}) AND is_active=1", array_merge([$companyId], $ids));
      }
      $map = [];
      foreach ($rows as $r) $map[(int)$r['id']] = $r;
      $splits = [];
      $sum = 0.0;
      foreach ($requested as $r) {
        $methodId = (int)$r['method_id'];
        if (empty($map[$methodId])) throw new RuntimeException('Forma de pagamento inválida ou inativa.');
        $m = $map[$methodId];
        $bankId = (int)($r['bank_account_id'] ?? 0) ?: ((int)($m['default_bank_account_id'] ?? 0) ?: null);
        if ($bankId && !finance_validate_bank_account($pdo, $companyId, $bankId)) throw new RuntimeException('Caixa/banco inválido ou pertencente a outra empresa.');
        if ($bankId && function_exists('finance_assert_cash_account_effective')) finance_assert_cash_account_effective($pdo, $companyId, $bankId, 'Este usuário não possui permissão para usar um dos caixas/bancos informados na baixa.');
        elseif ($bankId && function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $companyId, $bankId, 'Este usuário não possui permissão para usar um dos caixas/bancos informados na baixa.');
        $nexaOperator=(int)($data['_nexa_manual_operator_id']??0);
        if ($bankId && $nexaOperator>0 && function_exists('nexa_cash_assert_operator')) nexa_cash_assert_operator($pdo,$companyId,(int)$bankId,$nexaOperator);
        $amount = round((float)$r['amount'], 2);
        $sum += $amount;
        $splits[] = [
          'payment_method_id' => $methodId,
          'payment_method' => trim((string)($m['name'] ?? '')),
          'bank_account_id' => $bankId,
          'amount' => $amount,
        ];
      }
      $sum = round($sum, 2);
      if (abs($sum - $expectedTotal) > 0.01) {
        throw new RuntimeException('A soma das formas de pagamento (R$ ' . finance_money_br($sum) . ') precisa ser igual ao total da baixa (R$ ' . finance_money_br($expectedTotal) . ').');
      }
      return $splits;
    }

    // Compatibilidade com telas antigas e com Contas a Pagar.
    $legacyMethod = trim((string)($data['payment_method'] ?? ''));
    $legacyBank = (int)($data['bank_account_id'] ?? 0) ?: null;
    if ($legacyBank && !finance_validate_bank_account($pdo, $companyId, $legacyBank)) throw new RuntimeException('Caixa/banco inválido ou pertencente a outra empresa.');
    if ($legacyBank && function_exists('finance_assert_cash_account_effective')) finance_assert_cash_account_effective($pdo, $companyId, $legacyBank, 'Este usuário não possui permissão para baixar usando este caixa/banco.');
    elseif ($legacyBank && function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $companyId, $legacyBank, 'Este usuário não possui permissão para baixar usando este caixa/banco.');
    $nexaOperator=(int)($data['_nexa_manual_operator_id']??0);
    if ($legacyBank && $nexaOperator>0 && function_exists('nexa_cash_assert_operator')) nexa_cash_assert_operator($pdo,$companyId,(int)$legacyBank,$nexaOperator);
    return [[
      'payment_method_id' => null,
      'payment_method' => $legacyMethod,
      'bank_account_id' => $legacyBank,
      'amount' => $expectedTotal,
    ]];
  }
}

if (!function_exists('finance_create_split_movement')) {
  function finance_create_split_movement(PDO $pdo, int $companyId, string $originType, ?int $originId, string $settlementDate, array $title, array $split, float $amount, string $description = '', string $document = '', string $notes = ''): int {
    $amount = round(max(0, $amount), 2);
    if ($amount <= 0) return 0;
    $type = strtoupper($originType) === 'RECEBER' ? 'CREDITO' : 'DEBITO';
    $label = trim((string)($split['payment_method'] ?? ''));
    if ($description === '') {
      $description = (strtoupper($originType) === 'RECEBER' ? 'Baixa de contas a receber' : 'Baixa de contas a pagar')
        . ($originId ? ' #' . $originId : '') . ' - ' . (string)($title['description'] ?? '');
    }
    if ($label !== '') $description .= ' · ' . $label;
    $st = $pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
      $companyId,
      $settlementDate,
      $type,
      (int)($title['person_id'] ?? 0) ?: null,
      (int)($title['chart_account_id'] ?? 0),
      (int)($title['cost_center_id'] ?? 0) ?: null,
      (int)($split['bank_account_id'] ?? 0) ?: null,
      strtoupper($originType),
      $originId,
      $description,
      $document !== '' ? $document : (string)($title['document_number'] ?? ''),
      $amount,
      $notes,
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('finance_insert_settlement_payment')) {
  function finance_insert_settlement_payment(PDO $pdo, int $companyId, int $settlementId, array $split, float $amount, ?int $movementId): void {
    if ($settlementId <= 0 || $amount <= 0 || !finance_table_exists($pdo, 'finance_settlement_payments')) return;
    $st = $pdo->prepare('INSERT INTO finance_settlement_payments (company_id, settlement_id, payment_method_id, payment_method, bank_account_id, amount, movement_id, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $st->execute([
      $companyId,
      $settlementId,
      (int)($split['payment_method_id'] ?? 0) ?: null,
      trim((string)($split['payment_method'] ?? '')) ?: null,
      (int)($split['bank_account_id'] ?? 0) ?: null,
      round($amount,2),
      $movementId ?: null,
    ]);
  }
}

if (!function_exists('finance_settle_title')) {
  function finance_settle_title(PDO $pdo, int $companyId, string $originType, int $originId, array $data): void {
    $originType = strtoupper($originType);
    $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';
    $title = finance_query_one($pdo, "SELECT * FROM {$table} WHERE company_id = ? AND id = ?", [$companyId, $originId]);
    if (!$title) throw new RuntimeException('Título não encontrado.');

    $settlementDate = finance_date_or_null($data['settlement_date'] ?? '') ?: date('Y-m-d');
    $principalOpen = $originType === 'RECEBER' ? finance_cre_title_principal_open($pdo, $companyId, $title) : max(0, round((float)($title['open_amount'] ?? 0), 2));
    $principal = finance_money_in($data['amount'] ?? 0);
    $fine = max(0.0, finance_money_in($data['fine_amount'] ?? 0));
    $interest = max(0.0, finance_money_in($data['interest_amount'] ?? 0));
    $discount = max(0.0, finance_money_in($data['discount_amount'] ?? 0));
    $net = round($principal + $fine + $interest - $discount, 2);
    if ($net <= 0) throw new RuntimeException('Informe um valor de baixa maior que zero.');
    if ($principal <= 0) throw new RuntimeException('O valor principal da baixa deve ser maior que zero.');
    if ($principal - $principalOpen > 0.01) throw new RuntimeException('O valor principal da baixa não pode ser maior que o saldo principal em aberto.');
    $maxUpdated = round($principalOpen + $fine + $interest - $discount, 2);
    if ($net - $maxUpdated > 0.01) throw new RuntimeException('O total da baixa não pode ultrapassar o valor atualizado do título.');

    $review = ['waived_fine_amount'=>0.0,'waived_interest_amount'=>0.0,'waiver_user_id'=>0];
    if ($originType === 'RECEBER') {
      $review = finance_settlement_review($pdo, $companyId, $title, $settlementDate, $principal, $fine, $interest, $discount, $data);
    }
    $splits = finance_payment_splits_from_data($pdo, $companyId, $data, $net);
    $singleSplit = count($splits) === 1 ? $splits[0] : null;
    $settlementMethod = $singleSplit ? trim((string)$singleSplit['payment_method']) : 'Múltiplas formas';
    $settlementBank = $singleSplit ? ((int)($singleSplit['bank_account_id'] ?? 0) ?: null) : null;
    $notes = trim((string)($data['notes'] ?? ''));
    $settledByUserId = (int)($data['_nexa_manual_operator_id'] ?? 0);
    $settledByColumn = finance_column_exists($pdo,'finance_settlements','settled_by_user_id');

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('INSERT INTO finance_settlements (company_id, origin_type, origin_id, settlement_date, payment_method, bank_account_id, amount, fine_amount, interest_amount, discount_amount, waived_fine_amount, waived_interest_amount, waiver_user_id, notes, movement_id' . ($settledByColumn ? ', settled_by_user_id' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL' . ($settledByColumn ? ', ?' : '') . ')');
      $st->execute([
        $companyId, $originType, $originId, $settlementDate,
        $settlementMethod !== '' ? $settlementMethod : null,
        $settlementBank,
        $principal, $fine, $interest, $discount,
        (float)($review['waived_fine_amount'] ?? 0),
        (float)($review['waived_interest_amount'] ?? 0),
        (int)($review['waiver_user_id'] ?? 0) ?: null,
        $notes !== '' ? $notes : null,
        ...($settledByColumn ? [$settledByUserId > 0 ? $settledByUserId : null] : []),
      ]);
      $settlementId = (int)$pdo->lastInsertId();

      $firstMovementId = 0;
      foreach ($splits as $split) {
        $movementId = finance_create_split_movement($pdo, $companyId, $originType, $originId, $settlementDate, $title, $split, (float)$split['amount'], '', '', $notes);
        if ($firstMovementId <= 0 && $movementId > 0) $firstMovementId = $movementId;
        finance_insert_settlement_payment($pdo, $companyId, $settlementId, $split, (float)$split['amount'], $movementId);
        if ($originType === 'RECEBER' && function_exists('nexa_cash_cr_register_payment')) {
          nexa_cash_cr_register_payment($pdo, $companyId, $settlementId, (int)$pdo->lastInsertId(), $movementId, $originId, $title, $split, (float)$split['amount'], $data);
        }
      }
      if ($firstMovementId > 0) $pdo->prepare('UPDATE finance_settlements SET movement_id=? WHERE company_id=? AND id=?')->execute([$firstMovementId,$companyId,$settlementId]);

      $st = $pdo->prepare("UPDATE {$table} SET paid_amount = paid_amount + ?, fine_amount = fine_amount + ?, interest_amount = interest_amount + ?, discount_amount = discount_amount + ?, updated_at = NOW() WHERE company_id = ? AND id = ?");
      $st->execute([$net, $fine, $interest, $discount, $companyId, $originId]);
      finance_update_title_status($pdo, $table, $companyId, $originId);
      $pdo->commit();
      if ($originType === 'PAGAR' && function_exists('finance_fixed_expense_replenish_for_payable')) {
        try { finance_fixed_expense_replenish_for_payable($pdo, $companyId, $originId); }
        catch (Throwable $replenishError) { error_log('Falha ao recompor despesa fixa: ' . $replenishError->getMessage()); }
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('finance_settle_titles_bulk')) {
  function finance_settle_titles_bulk(PDO $pdo, int $companyId, string $originType, array $originIds, array $data): int {
    $originType = strtoupper($originType);
    if (!in_array($originType, ['RECEBER', 'PAGAR'], true)) throw new RuntimeException('Tipo de título inválido.');
    $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';
    $personLabel = $originType === 'RECEBER' ? 'Cliente' : 'Fornecedor';
    $ids = array_values(array_unique(array_filter(array_map('intval', $originIds), static fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('Selecione pelo menos uma parcela para baixar.');

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    $titles = finance_query_all($pdo, "SELECT t.*, p.name AS person_name FROM {$table} t LEFT JOIN finance_people p ON p.company_id = t.company_id AND p.id = t.person_id WHERE t.company_id = ? AND t.id IN ({$placeholders}) AND t.status IN ('ABERTO','PARCIAL') ORDER BY t.due_date ASC, t.id ASC", $params);
    if (!$titles) throw new RuntimeException('Nenhuma parcela em aberto foi encontrada para baixar.');

    // A baixa não deve criar/configurar finance_settings implicitamente. Usa defaults se ausente.
    $settings = finance_settings($pdo, $companyId, false);
    $separate = !empty($settings['settle_titles_individually']);
    $settlementDate = finance_date_or_null($data['settlement_date'] ?? '') ?: date('Y-m-d');
    $userNotes = trim((string)($data['notes'] ?? ''));

    $amountInputs = is_array($data['bulk_amount'] ?? null) ? (array)$data['bulk_amount'] : [];
    $fineInputs = is_array($data['bulk_fine_amount'] ?? null) ? (array)$data['bulk_fine_amount'] : [];
    $interestInputs = is_array($data['bulk_interest_amount'] ?? null) ? (array)$data['bulk_interest_amount'] : [];
    $discountInputs = is_array($data['bulk_discount_amount'] ?? null) ? (array)$data['bulk_discount_amount'] : [];
    $totalPaidInputs = is_array($data['bulk_total_paid'] ?? null) ? (array)$data['bulk_total_paid'] : [];

    $settlements = [];
    foreach ($titles as $title) {
      $id = (int)$title['id'];
      $principalOpen = $originType === 'RECEBER' ? finance_cre_title_principal_open($pdo, $companyId, $title) : max(0, round((float)($title['open_amount'] ?? 0), 2));
      if ($principalOpen <= 0) continue;

      $fine = max(0.0, finance_money_in($fineInputs[$id] ?? $fineInputs[(string)$id] ?? 0));
      $interest = max(0.0, finance_money_in($interestInputs[$id] ?? $interestInputs[(string)$id] ?? 0));
      $discount = max(0.0, finance_money_in($discountInputs[$id] ?? $discountInputs[(string)$id] ?? 0));
      $hasTotalPaid = array_key_exists((string)$id, $totalPaidInputs) || array_key_exists($id, $totalPaidInputs);
      if ($hasTotalPaid) {
        $net = finance_money_in($totalPaidInputs[$id] ?? $totalPaidInputs[(string)$id] ?? 0);
        $principal = round($net - $fine - $interest + $discount, 2);
      } else {
        $principal = array_key_exists((string)$id, $amountInputs) || array_key_exists($id, $amountInputs)
          ? finance_money_in($amountInputs[$id] ?? $amountInputs[(string)$id] ?? 0)
          : $principalOpen;
        $net = round($principal + $fine + $interest - $discount, 2);
      }
      if ($net <= 0) continue;
      if ($principal <= 0) throw new RuntimeException('O valor principal da baixa da parcela #' . $id . ' deve ser maior que zero.');
      if ($principal - $principalOpen > 0.01) throw new RuntimeException('A baixa principal da parcela #' . $id . ' não pode ser maior que o saldo principal em aberto.');
      $maxUpdated = round($principalOpen + $fine + $interest - $discount, 2);
      if ($net - $maxUpdated > 0.01) throw new RuntimeException('O total recebido da parcela #' . $id . ' não pode ultrapassar o valor atualizado.');

      $review = ['waived_fine_amount'=>0.0,'waived_interest_amount'=>0.0,'waiver_user_id'=>0];
      if ($originType === 'RECEBER') $review = finance_settlement_review($pdo, $companyId, $title, $settlementDate, $principal, $fine, $interest, $discount, $data);
      $settlements[] = [
        'title'=>$title,
        'amount'=>round($principal,2),
        'fine_amount'=>round($fine,2),
        'interest_amount'=>round($interest,2),
        'discount_amount'=>round($discount,2),
        'waived_fine_amount'=>round((float)($review['waived_fine_amount'] ?? 0),2),
        'waived_interest_amount'=>round((float)($review['waived_interest_amount'] ?? 0),2),
        'waiver_user_id'=>(int)($review['waiver_user_id'] ?? 0),
        'net'=>round($net,2),
      ];
    }
    if (!$settlements) throw new RuntimeException('Informe pelo menos um valor de baixa maior que zero.');

    $total = round(array_sum(array_map(static fn($r)=>(float)$r['net'],$settlements)),2);
    if ($total <= 0) throw new RuntimeException('As parcelas selecionadas não possuem saldo em aberto.');
    $splits = finance_payment_splits_from_data($pdo, $companyId, $data, $total);
    $singleSplit = count($splits) === 1 ? $splits[0] : null;
    $settlementMethod = $singleSplit ? trim((string)$singleSplit['payment_method']) : 'Múltiplas formas';
    $settlementBank = $singleSplit ? ((int)($singleSplit['bank_account_id'] ?? 0) ?: null) : null;

    $personIds = []; $costIds = []; $obsLines = [];
    foreach ($settlements as $row) {
      $title = $row['title'];
      $personIds[(int)($title['person_id'] ?? 0)] = true;
      $costIds[(int)($title['cost_center_id'] ?? 0)] = true;
      $due = !empty($title['due_date']) ? date('d/m/Y', strtotime((string)$title['due_date'])) : 'sem vencimento';
      $obsLines[] = $personLabel . ': ' . trim((string)($title['person_name'] ?? ''))
        . ' | Documento: ' . trim((string)($title['document_number'] ?? ''))
        . ' | Parcela: ' . trim((string)($title['installment'] ?? ''))
        . ' | Venc.: ' . $due
        . ' | Baixa: R$ ' . finance_money_br((float)$row['net']);
    }
    $first = $settlements[0]['title'];
    $validPersonIds = array_values(array_filter(array_keys($personIds), static fn($id) => (int)$id > 0));
    $validCostIds = array_values(array_filter(array_keys($costIds), static fn($id) => (int)$id > 0));
    $samePerson = count($validPersonIds) === 1 ? (int)$validPersonIds[0] : 0;
    $sameCost = count($validCostIds) === 1 ? (int)$validCostIds[0] : 0;
    $chartId = (int)($first['chart_account_id'] ?? 0);
    $personName = trim((string)($first['person_name'] ?? ''));
    $descPrefix = $originType === 'RECEBER' ? 'Baixa múltipla de contas a receber' : 'Baixa múltipla de contas a pagar';
    $description = $descPrefix . ($samePerson && $personName !== '' ? ' - ' . $personName : '');
    $notes = trim($userNotes . ($userNotes !== '' ? "\n" : '') . implode("\n", $obsLines));

    $settledByUserId = (int)($data['_nexa_manual_operator_id'] ?? 0);
    $settledByColumn = finance_column_exists($pdo,'finance_settlements','settled_by_user_id');
    $pdo->beginTransaction();
    try {
      $stSettle = $pdo->prepare('INSERT INTO finance_settlements (company_id, origin_type, origin_id, settlement_date, payment_method, bank_account_id, amount, fine_amount, interest_amount, discount_amount, waived_fine_amount, waived_interest_amount, waiver_user_id, notes, movement_id' . ($settledByColumn ? ', settled_by_user_id' : '') . ') VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NULL' . ($settledByColumn ? ', ?' : '') . ')');
      $stPaid = $pdo->prepare("UPDATE {$table} SET paid_amount = paid_amount + ?, fine_amount = fine_amount + ?, interest_amount = interest_amount + ?, discount_amount = discount_amount + ?, updated_at = NOW() WHERE company_id = ? AND id = ?");
      $inserted = [];
      foreach ($settlements as $row) {
        $title = $row['title']; $id = (int)$title['id'];
        $stSettle->execute([
          $companyId,$originType,$id,$settlementDate,
          $settlementMethod !== '' ? $settlementMethod : null,$settlementBank,
          (float)$row['amount'],(float)$row['fine_amount'],(float)$row['interest_amount'],(float)$row['discount_amount'],
          (float)$row['waived_fine_amount'],(float)$row['waived_interest_amount'],(int)$row['waiver_user_id'] ?: null,
          $userNotes !== '' ? $userNotes : null,
          ...($settledByColumn ? [$settledByUserId > 0 ? $settledByUserId : null] : []),
        ]);
        $settlementId=(int)$pdo->lastInsertId();
        $stPaid->execute([(float)$row['net'],(float)$row['fine_amount'],(float)$row['interest_amount'],(float)$row['discount_amount'],$companyId,$id]);
        finance_update_title_status($pdo,$table,$companyId,$id);
        $row['settlement_id']=$settlementId;$row['first_movement_id']=0;$inserted[]=$row;
      }

      // Distribui as formas de pagamento sobre os títulos, preservando a soma exata.
      $splitRemain = [];
      foreach ($splits as $i=>$split) $splitRemain[$i]=round((float)$split['amount'],2);
      $allocations = [];
      foreach ($inserted as $ri=>$row) {
        $remaining=round((float)$row['net'],2);
        foreach ($splits as $si=>$split) {
          if($remaining<=0.009) break;
          $available=round((float)($splitRemain[$si]??0),2); if($available<=0.009) continue;
          $chunk=round(min($remaining,$available),2); if($chunk<=0) continue;
          $allocations[]=['row_index'=>$ri,'split_index'=>$si,'amount'=>$chunk];
          $splitRemain[$si]=round($available-$chunk,2);$remaining=round($remaining-$chunk,2);
        }
        if($remaining>0.01) throw new RuntimeException('Não foi possível distribuir as formas de pagamento entre as parcelas selecionadas.');
      }

      if ($separate) {
        foreach ($allocations as $a) {
          $ri=(int)$a['row_index'];$si=(int)$a['split_index'];$row=$inserted[$ri];$split=$splits[$si];$title=$row['title'];
          $movementId=finance_create_split_movement($pdo,$companyId,$originType,(int)$title['id'],$settlementDate,$title,$split,(float)$a['amount'],'','',$userNotes);
          finance_insert_settlement_payment($pdo,$companyId,(int)$row['settlement_id'],$split,(float)$a['amount'],$movementId);
          if ($originType === 'RECEBER' && function_exists('nexa_cash_cr_register_payment')) {
            nexa_cash_cr_register_payment($pdo, $companyId, (int)$row['settlement_id'], (int)$pdo->lastInsertId(), $movementId, (int)$row['title']['id'], $row['title'], $split, (float)$a['amount'], $data);
          }
          if((int)$inserted[$ri]['first_movement_id']<=0 && $movementId>0)$inserted[$ri]['first_movement_id']=$movementId;
        }
      } else {
        $movementIds=[];
        foreach ($splits as $si=>$split) {
          $type = $originType === 'RECEBER' ? 'CREDITO' : 'DEBITO';
          $desc=$description . (trim((string)$split['payment_method'])!==''?' · '.trim((string)$split['payment_method']):'');
          $st=$pdo->prepare('INSERT INTO finance_movements (company_id, movement_date, type, person_id, chart_account_id, cost_center_id, bank_account_id, origin_type, origin_id, description, document_number, amount, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, NULL, ?, ?, ?, ?)');
          $st->execute([$companyId,$settlementDate,$type,$samePerson?:null,$chartId,$sameCost?:null,(int)($split['bank_account_id']??0)?:null,$originType.'_MULTI',$desc,'MULTI-'.count($settlements),(float)$split['amount'],$notes!==''?$notes:null]);
          $movementIds[$si]=(int)$pdo->lastInsertId();
        }
        foreach ($allocations as $a) {
          $ri=(int)$a['row_index'];$si=(int)$a['split_index'];$row=$inserted[$ri];$split=$splits[$si];$movementId=(int)($movementIds[$si]??0);
          finance_insert_settlement_payment($pdo,$companyId,(int)$row['settlement_id'],$split,(float)$a['amount'],$movementId);
          if ($originType === 'RECEBER' && function_exists('nexa_cash_cr_register_payment')) {
            nexa_cash_cr_register_payment($pdo, $companyId, (int)$row['settlement_id'], (int)$pdo->lastInsertId(), $movementId, (int)$row['title']['id'], $row['title'], $split, (float)$a['amount'], $data);
          }
          if((int)$inserted[$ri]['first_movement_id']<=0 && $movementId>0)$inserted[$ri]['first_movement_id']=$movementId;
        }
      }
      $upMovement=$pdo->prepare('UPDATE finance_settlements SET movement_id=? WHERE company_id=? AND id=?');
      foreach($inserted as $row) if((int)$row['first_movement_id']>0)$upMovement->execute([(int)$row['first_movement_id'],$companyId,(int)$row['settlement_id']]);

      $pdo->commit();
      if ($originType === 'PAGAR' && function_exists('finance_fixed_expense_replenish_for_payable')) {
        $processedRules=[];
        foreach($settlements as $settledRow){$payableId=(int)($settledRow['title']['id']??0);if($payableId<=0)continue;try{$ruleId=finance_fixed_expense_id_for_payable($pdo,$companyId,$payableId);if($ruleId>0&&empty($processedRules[$ruleId])){finance_fixed_expense_ensure_horizon($pdo,$companyId,$ruleId);$processedRules[$ruleId]=true;}}catch(Throwable $replenishError){error_log('Falha ao recompor despesa fixa: '.$replenishError->getMessage());}}
      }
      return count($settlements);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}


if (!function_exists('finance_active_settlement_count')) {
  function finance_active_settlement_count(PDO $pdo, int $companyId, string $originType, int $originId): int {
    if ($companyId <= 0 || $originId <= 0 || !finance_table_exists($pdo, 'finance_settlements')) return 0;
    $whereStatus = finance_column_exists($pdo, 'finance_settlements', 'status') ? " AND COALESCE(status,'ATIVO')='ATIVO'" : '';
    $st = $pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type=? AND origin_id=?" . $whereStatus);
    $st->execute([$companyId, strtoupper($originType), $originId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('finance_reversal_schema_requirements')) {
  function finance_reversal_schema_requirements(PDO $pdo): array {
    $required = ['status','reversed_at','reversed_by_user_id','reversal_reason','reversal_movement_id'];
    $missing = [];
    if (!finance_table_exists($pdo, 'finance_settlements')) return ['finance_settlements'];
    foreach ($required as $column) {
      if (!finance_column_exists($pdo, 'finance_settlements', $column)) $missing[] = 'finance_settlements.' . $column;
    }
    return $missing;
  }
}

if (!function_exists('finance_reverse_settlement')) {
  function finance_reverse_settlement(PDO $pdo, int $companyId, int $settlementId, string $reason, int $userId = 0): void {
    $reason = trim($reason);
    if ($companyId <= 0 || $settlementId <= 0) throw new RuntimeException('Baixa inválida para estorno.');
    if ($reason === '') throw new RuntimeException('Informe o motivo do estorno.');

    $missingSchema = finance_reversal_schema_requirements($pdo);
    if ($missingSchema) {
      throw new RuntimeException('Estrutura do estorno incompleta no banco. Execute a migration V11.6.1. Ausente: ' . implode(', ', $missingSchema) . '.');
    }

    $stage = 'início';
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
      $stage = 'localizar baixa';
      $st = $pdo->prepare('SELECT * FROM finance_settlements WHERE company_id=? AND id=? FOR UPDATE');
      $st->execute([$companyId,$settlementId]);
      $settlement = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$settlement) throw new RuntimeException('Baixa não encontrada nesta empresa.');
      if (strtoupper((string)($settlement['status'] ?? 'ATIVO')) !== 'ATIVO') throw new RuntimeException('Esta baixa já foi estornada.');

      $originType = strtoupper(trim((string)($settlement['origin_type'] ?? '')));
      if (!in_array($originType, ['RECEBER','PAGAR'], true)) throw new RuntimeException('A origem desta baixa não permite estorno protegido.');
      $originId = (int)($settlement['origin_id'] ?? 0);
      $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';

      $stage = 'localizar título';
      $title = finance_query_one($pdo, "SELECT * FROM {$table} WHERE company_id=? AND id=? FOR UPDATE", [$companyId,$originId]);
      if (!$title) throw new RuntimeException('Título vinculado à baixa não encontrado.');

      // Módulos externos ao estorno financeiro continuam protegidos e não são alterados aqui.
      if ($originType === 'RECEBER' && finance_table_exists($pdo, 'finance_boleto_charges')) {
        $stage = 'validar cobrança/boleto';
        $q=$pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND COALESCE(status,'')<>'CANCELADO'");
        $q->execute([$companyId,$originId]);
        if ((int)$q->fetchColumn()>0) throw new RuntimeException('Esta baixa pertence a título com cobrança/boleto ativo. Trate a cobrança antes do estorno.');
      }

      $fixedExpenseId = 0;
      $fixedExpenseCrossContext = false;
      if ($originType === 'PAGAR' && finance_table_exists($pdo, 'finance_fixed_expense_titles')) {
        $stage = 'validar despesa fixa';
        $q=$pdo->prepare('SELECT fixed_expense_id FROM finance_fixed_expense_titles WHERE company_id=? AND payable_id=? LIMIT 1');
        $q->execute([$companyId,$originId]);
        $fixedExpenseId=(int)($q->fetchColumn()?:0);
        if ($fixedExpenseId>0) {
          $fixedExpenseCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
          if (!$fixedExpenseCrossContext) {
            throw new RuntimeException('Esta baixa pertence a uma Despesa Fixa. O estorno deste vínculo está habilitado somente pelo fluxo multiempresa protegido.');
          }
          $scopeOk=function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo,'finance_fixed_expenses');
          $permissionOk=function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo,'financeiro_despesas_fixas');
          if(!$scopeOk || !$permissionOk) throw new RuntimeException('Esta baixa pertence a uma Despesa Fixa. Seu perfil precisa da permissão Despesas fixas para estornar este vínculo pelo F11.');
        }
      }

      $stage = 'carregar distribuição da baixa';
      $payments = [];
      if (finance_table_exists($pdo, 'finance_settlement_payments')) {
        $payments = finance_query_all($pdo, 'SELECT * FROM finance_settlement_payments WHERE company_id=? AND settlement_id=? ORDER BY id', [$companyId,$settlementId]);
      }
      $movementParts = [];
      foreach ($payments as $pay) {
        $mid=(int)($pay['movement_id']??0);
        $amount=round((float)($pay['amount']??0),2);
        if($mid>0 && $amount>0) $movementParts[$mid]=round(($movementParts[$mid]??0)+$amount,2);
      }
      $net=round((float)($settlement['amount']??0)+(float)($settlement['fine_amount']??0)+(float)($settlement['interest_amount']??0)-(float)($settlement['discount_amount']??0),2);
      if (!$movementParts) {
        $mid=(int)($settlement['movement_id']??0);
        if($mid>0 && $net>0) $movementParts[$mid]=$net;
      }
      if (!$movementParts) throw new RuntimeException('A baixa não possui distribuição financeira suficiente para um estorno seguro.');
      $partsTotal=round(array_sum($movementParts),2);
      if (abs($partsTotal-$net)>0.01) {
        throw new RuntimeException('A distribuição financeira da baixa está inconsistente: baixa R$ ' . number_format($net,2,',','.') . ' x movimentos R$ ' . number_format($partsTotal,2,',','.') . '.');
      }

      // V11.6.1: o estorno não cancela mais o movimento original. Sempre gera
      // contra-lançamento pelo valor exato da baixa. Isso preserva o razão/auditoria
      // e funciona tanto para movimento exclusivo quanto compartilhado.
      $reversalIds=[];
      $cashCrReverseMovementMap=[];
      foreach ($movementParts as $movementId=>$partAmount) {
        $stage = 'validar movimento #' . (int)$movementId;
        $mov=finance_query_one($pdo,'SELECT * FROM finance_movements WHERE company_id=? AND id=? FOR UPDATE',[$companyId,(int)$movementId]);
        if(!$mov) throw new RuntimeException('Movimento financeiro #' . (int)$movementId . ' vinculado à baixa não foi encontrado.');
        if(strtoupper((string)($mov['status']??'ATIVO'))==='CANCELADO') throw new RuntimeException('Movimento financeiro #' . (int)$movementId . ' já está cancelado; revise o histórico antes de estornar.');
        if ($partAmount<=0 || $partAmount-(float)($mov['amount']??0)>0.01) {
          throw new RuntimeException('Distribuição do movimento #' . (int)$movementId . ' está inconsistente com a baixa.');
        }

        if (finance_table_exists($pdo,'finance_bank_reconciliation_items')) {
          $stage = 'validar conciliação do movimento #' . (int)$movementId;
          $hasSettlementCol=finance_column_exists($pdo,'finance_bank_reconciliation_items','settlement_id');
          $hasMovementCol=finance_column_exists($pdo,'finance_bank_reconciliation_items','movement_id');
          if ($hasSettlementCol || $hasMovementCol) {
            $parts=[];$params=[$companyId];
            if($hasSettlementCol){$parts[]='settlement_id=?';$params[]=$settlementId;}
            if($hasMovementCol){$parts[]='movement_id=?';$params[]=(int)$movementId;}
            $qr=$pdo->prepare("SELECT COUNT(*) FROM finance_bank_reconciliation_items WHERE company_id=? AND status='CONCILIADO' AND (".implode(' OR ',$parts).")");
            $qr->execute($params);
            if((int)$qr->fetchColumn()>0) throw new RuntimeException('A baixa possui item já conciliado. Desfaça a conciliação antes de estornar.');
          }
        }

        $stage = 'gerar contra-lançamento do movimento #' . (int)$movementId;
        $reverseType=strtoupper((string)($mov['type']??''))==='CREDITO'?'DEBITO':'CREDITO';
        $stRev=$pdo->prepare('INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $stRev->execute([
          $companyId,
          date('Y-m-d'),
          $reverseType,
          (int)($mov['person_id']??0)?:null,
          (int)($mov['chart_account_id']??0),
          (int)($mov['cost_center_id']??0)?:null,
          (int)($mov['bank_account_id']??0)?:null,
          'ESTORNO_BAIXA',
          $settlementId,
          'Estorno da baixa #' . $settlementId . ' - ' . (string)($mov['description']??''),
          (string)($mov['document_number']??''),
          $partAmount,
          $reason . ' | movimento original #' . (int)$movementId,
        ]);
        $reversalIds[]=(int)$pdo->lastInsertId();
        $cashCrReverseMovementMap[(int)$movementId]=(int)$pdo->lastInsertId();
      }

      $stage = 'reabrir saldo do título';
      $pdo->prepare("UPDATE {$table} SET paid_amount=GREATEST(0,paid_amount-?), fine_amount=GREATEST(0,fine_amount-?), interest_amount=GREATEST(0,interest_amount-?), discount_amount=GREATEST(0,discount_amount-?), updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$net,(float)($settlement['fine_amount']??0),(float)($settlement['interest_amount']??0),(float)($settlement['discount_amount']??0),$companyId,$originId]);
      if (strtoupper((string)($title['status']??'')) !== 'CANCELADO') finance_update_title_status($pdo,$table,$companyId,$originId);

      if ($fixedExpenseId > 0 && $fixedExpenseCrossContext && function_exists('finance_fixed_expense_trim_horizon_after_reversal')) {
        $stage = 'ajustar horizonte da despesa fixa';
        finance_fixed_expense_trim_horizon_after_reversal($pdo, $companyId, $fixedExpenseId, $originId, $settlementId);
      }

      $stage = 'registrar auditoria do estorno';
      $firstReversal=$reversalIds ? (int)$reversalIds[0] : null;
      $up=$pdo->prepare("UPDATE finance_settlements SET status='ESTORNADO', reversed_at=NOW(), reversed_by_user_id=?, reversal_reason=?, reversal_movement_id=? WHERE company_id=? AND id=? AND COALESCE(status,'ATIVO')='ATIVO'");
      $up->execute([$userId>0?$userId:null,$reason,$firstReversal,$companyId,$settlementId]);
      if ($up->rowCount() !== 1) throw new RuntimeException('A baixa mudou de status durante a operação. Recarregue o título e confira o histórico.');
      if ($originType === 'RECEBER' && function_exists('nexa_cash_cr_reverse_settlement')) {
        $stage = 'estornar movimento do Caixa ligado ao C/R';
        nexa_cash_cr_reverse_settlement($pdo, $companyId, $settlementId, $userId, $reason, $cashCrReverseMovementMap);
      }

      if ($startedTransaction) $pdo->commit();
    } catch (Throwable $e) {
      if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
      $msg='Falha no estorno [etapa: ' . $stage . ']: ' . $e->getMessage();
      error_log('[NEXA V11.6.1 ESTORNO] empresa=' . $companyId . ' baixa=' . $settlementId . ' ' . $msg);
      throw new RuntimeException($msg, 0, $e);
    }
  }
}


if (!function_exists('finance_fixed_expense_month_enabled')) {
  function finance_fixed_expense_month_enabled(string $mask, int $month): bool {
    $mask = preg_replace('/[^01]/', '', $mask) ?: '';
    if (strlen($mask) !== 12) $mask = '111111111111';
    return $month >= 1 && $month <= 12 && substr($mask, $month - 1, 1) === '1';
  }
}

if (!function_exists('finance_fixed_expense_next_reference')) {
  function finance_fixed_expense_next_reference(array $rule, ?string $afterReference = null): string {
    $firstDue = finance_date_or_null($rule['first_due_date'] ?? '') ?: date('Y-m-d');
    $firstRef = new DateTimeImmutable(date('Y-m-01', strtotime($firstDue)));
    $cursor = $afterReference
      ? (new DateTimeImmutable(date('Y-m-01', strtotime($afterReference))))->modify('+1 month')
      : $firstRef;
    if ($cursor < $firstRef) $cursor = $firstRef;
    $mask = (string)($rule['months_mask'] ?? '111111111111');
    for ($i = 0; $i < 240; $i++) {
      if (finance_fixed_expense_month_enabled($mask, (int)$cursor->format('n'))) return $cursor->format('Y-m-01');
      $cursor = $cursor->modify('+1 month');
    }
    throw new RuntimeException('Não foi possível localizar um mês habilitado para a despesa fixa.');
  }
}

if (!function_exists('finance_fixed_expense_due_date')) {
  function finance_fixed_expense_due_date(array $rule, string $referenceMonth): string {
    $ref = new DateTimeImmutable(date('Y-m-01', strtotime($referenceMonth)));
    $day = max(1, min(31, (int)($rule['due_day'] ?? 1)));
    $day = min($day, (int)$ref->format('t'));
    return $ref->setDate((int)$ref->format('Y'), (int)$ref->format('m'), $day)->format('Y-m-d');
  }
}

if (!function_exists('finance_fixed_expense_id_for_payable')) {
  function finance_fixed_expense_id_for_payable(PDO $pdo, int $companyId, int $payableId): int {
    if ($companyId <= 0 || $payableId <= 0 || !finance_table_exists($pdo, 'finance_fixed_expense_titles')) return 0;
    $st = $pdo->prepare('SELECT fixed_expense_id FROM finance_fixed_expense_titles WHERE company_id = ? AND payable_id = ? LIMIT 1');
    $st->execute([$companyId, $payableId]);
    return (int)($st->fetchColumn() ?: 0);
  }
}

if (!function_exists('finance_fixed_expense_ensure_horizon')) {
  function finance_fixed_expense_ensure_horizon(PDO $pdo, int $companyId, int $fixedExpenseId): int {
    if ($companyId <= 0 || $fixedExpenseId <= 0) return 0;
    $crossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
    if ($crossContext) {
      foreach (['finance_fixed_expenses','finance_fixed_expense_titles','finance_payables'] as $requiredTable) {
        if (!finance_table_exists($pdo, $requiredTable)) {
          throw new RuntimeException('Estrutura de Despesas Fixas não está disponível. Aplique as migrations do Financeiro antes de operar pelo F11.');
        }
      }
    } else {
      // Compatibilidade legada: fora do F11 mantém exatamente o comportamento anterior.
      finance_ensure_schema($pdo);
    }
    $startedTransaction = !$pdo->inTransaction();
    if ($startedTransaction) $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('SELECT * FROM finance_fixed_expenses WHERE company_id = ? AND id = ? LIMIT 1 FOR UPDATE');
      $st->execute([$companyId, $fixedExpenseId]);
      $rule = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$rule || empty($rule['active'])) {
        if ($startedTransaction) $pdo->commit();
        return 0;
      }
      if ($crossContext) {
        if (!finance_validate_person_role($pdo, $companyId, (int)($rule['person_id'] ?? 0), 'supplier')) {
          throw new RuntimeException('A Despesa Fixa referencia um fornecedor inválido ou de outra empresa.');
        }
        if (!finance_validate_analytic_chart($pdo, $companyId, (int)($rule['chart_account_id'] ?? 0))) {
          throw new RuntimeException('A Despesa Fixa referencia uma conta analítica inválida ou de outra empresa.');
        }
        $ruleCostId=(int)($rule['cost_center_id'] ?? 0) ?: null;
        if (!finance_validate_analytic_cost($pdo, $companyId, $ruleCostId)) {
          throw new RuntimeException('A Despesa Fixa referencia um centro de custo inválido ou de outra empresa.');
        }
        $ruleBankId=(int)($rule['bank_account_id'] ?? 0) ?: null;
        if (!finance_validate_bank_account($pdo, $companyId, $ruleBankId)) {
          throw new RuntimeException('A Despesa Fixa referencia um Caixa/Banco inválido ou de outra empresa.');
        }
      }
      $horizon = max(1, min(36, (int)($rule['horizon_count'] ?? 12)));
      $st = $pdo->prepare("SELECT COUNT(*) FROM finance_fixed_expense_titles ft INNER JOIN finance_payables p ON p.company_id=ft.company_id AND p.id=ft.payable_id WHERE ft.company_id=? AND ft.fixed_expense_id=? AND p.status IN ('ABERTO','PARCIAL') AND COALESCE(p.open_amount,0)>0.009");
      $st->execute([$companyId, $fixedExpenseId]);
      $openCount = (int)$st->fetchColumn();
      $st = $pdo->prepare('SELECT MAX(reference_month) FROM finance_fixed_expense_titles WHERE company_id=? AND fixed_expense_id=?');
      $st->execute([$companyId, $fixedExpenseId]);
      $lastReference = (string)($st->fetchColumn() ?: '');
      $created = 0;
      $attempts = 0;
      while ($openCount < $horizon && $attempts < 120) {
        $attempts++;
        $reference = finance_fixed_expense_next_reference($rule, $lastReference !== '' ? $lastReference : null);
        $dueDate = finance_fixed_expense_due_date($rule, $reference);
        $firstDueDate = finance_date_or_null($rule['first_due_date'] ?? '') ?: $dueDate;
        if ($lastReference === '' && $dueDate < $firstDueDate) {
          $lastReference = $reference;
          continue;
        }
        $stExists = $pdo->prepare('SELECT 1 FROM finance_fixed_expense_titles WHERE company_id=? AND fixed_expense_id=? AND reference_month=? LIMIT 1');
        $stExists->execute([$companyId, $fixedExpenseId, $reference]);
        if ($stExists->fetchColumn()) {
          $lastReference = $reference;
          continue;
        }
        $amount = round((float)($rule['average_amount'] ?? 0), 2);
        if ($amount <= 0) throw new RuntimeException('A despesa fixa precisa possuir um valor médio maior que zero.');
        $prefix = preg_replace('/[^A-Za-z0-9_-]+/', '', (string)($rule['document_prefix'] ?? '')) ?: 'FIXA';
        $document = substr($prefix . '-' . $fixedExpenseId . '-' . date('Ym', strtotime($reference)), 0, 60);
        $installment = date('m/Y', strtotime($reference));
        $note = trim((string)($rule['notes'] ?? ''));
        $autoNote = 'Provisão automática da despesa fixa #' . $fixedExpenseId . ' - competência ' . $installment . '.';
        $notes = trim($autoNote . ($note !== '' ? "\n" . $note : ''));
        $ins = $pdo->prepare('INSERT INTO finance_payables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $ins->execute([
          $companyId,
          (int)$rule['person_id'],
          (int)$rule['chart_account_id'],
          (int)($rule['cost_center_id'] ?? 0) ?: null,
          (string)$rule['description'],
          $document,
          $installment,
          $reference,
          $dueDate,
          trim((string)($rule['expected_payment_method'] ?? '')) ?: null,
          $amount, 0, 0, 0, 0, $amount, 'ABERTO', $notes
        ]);
        $payableId = (int)$pdo->lastInsertId();
        $map = $pdo->prepare('INSERT INTO finance_fixed_expense_titles (company_id, fixed_expense_id, payable_id, reference_month, generated_amount) VALUES (?,?,?,?,?)');
        $map->execute([$companyId, $fixedExpenseId, $payableId, $reference, $amount]);
        $created++;
        $openCount++;
        $lastReference = $reference;
      }
      if ($startedTransaction) $pdo->commit();
      return $created;
    } catch (Throwable $e) {
      if ($startedTransaction && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('finance_fixed_expense_trim_horizon_after_reversal')) {
  /**
   * V11.5 - ao estornar pelo F11 uma quitação de Despesa Fixa, a provisão original
   * volta a ficar aberta. A quitação pode ter criado uma nova competência no fim do
   * horizonte; para não deixar provisões excedentes, cancela somente competências
   * futuras comprovadamente intocadas. Nunca exclui registros e nunca atua fora do F11.
   */
  function finance_fixed_expense_trim_horizon_after_reversal(PDO $pdo, int $companyId, int $fixedExpenseId, int $restoredPayableId, int $settlementId = 0): int {
    if ($companyId <= 0 || $fixedExpenseId <= 0 || $restoredPayableId <= 0) return 0;
    if (!function_exists('finance_multi_company_cross_context') || !finance_multi_company_cross_context($pdo)) return 0;
    if (!finance_table_exists($pdo, 'finance_fixed_expenses') || !finance_table_exists($pdo, 'finance_fixed_expense_titles') || !finance_table_exists($pdo, 'finance_payables')) return 0;

    $rule = finance_query_one($pdo, 'SELECT id, horizon_count, active FROM finance_fixed_expenses WHERE company_id=? AND id=? LIMIT 1', [$companyId, $fixedExpenseId]);
    if (!$rule || empty($rule['active'])) return 0;
    $horizon = max(1, min(36, (int)($rule['horizon_count'] ?? 12)));

    $st = $pdo->prepare("SELECT COUNT(*) FROM finance_fixed_expense_titles ft INNER JOIN finance_payables p ON p.company_id=ft.company_id AND p.id=ft.payable_id WHERE ft.company_id=? AND ft.fixed_expense_id=? AND p.status IN ('ABERTO','PARCIAL') AND COALESCE(p.open_amount,0)>0.009");
    $st->execute([$companyId, $fixedExpenseId]);
    $excess = (int)$st->fetchColumn() - $horizon;
    if ($excess <= 0) return 0;
    // Uma única baixa estornada pode devolver, no máximo, uma provisão ao horizonte.
    // Mesmo que já existam excessos históricos, esta rotina não corrige nada além do
    // efeito diretamente provocado pelo estorno atual.
    $trimRemaining = 1;

    $candidates = finance_query_all($pdo, "SELECT ft.payable_id, ft.reference_month, ft.generated_amount, p.amount, p.fine_amount, p.interest_amount, p.discount_amount, p.paid_amount, p.open_amount, p.status, p.updated_at FROM finance_fixed_expense_titles ft INNER JOIN finance_payables p ON p.company_id=ft.company_id AND p.id=ft.payable_id WHERE ft.company_id=? AND ft.fixed_expense_id=? AND p.status='ABERTO' AND COALESCE(p.open_amount,0)>0.009 ORDER BY ft.reference_month DESC, ft.id DESC", [$companyId, $fixedExpenseId]);

    $cancelled = 0;
    foreach ($candidates as $candidate) {
      if ($trimRemaining <= 0) break;
      $payableId = (int)($candidate['payable_id'] ?? 0);
      if ($payableId <= 0 || $payableId === $restoredPayableId) continue;
      // Somente uma provisão automática nunca tocada pode ser recolhida do horizonte.
      if (!empty($candidate['updated_at'])) continue;
      if ((float)($candidate['paid_amount'] ?? 0) > 0.009) continue;
      if (abs((float)($candidate['fine_amount'] ?? 0)) > 0.009 || abs((float)($candidate['interest_amount'] ?? 0)) > 0.009 || abs((float)($candidate['discount_amount'] ?? 0)) > 0.009) continue;
      if (abs((float)($candidate['amount'] ?? 0) - (float)($candidate['generated_amount'] ?? 0)) > 0.01) continue;
      if (abs((float)($candidate['open_amount'] ?? 0) - (float)($candidate['generated_amount'] ?? 0)) > 0.01) continue;

      if (finance_table_exists($pdo, 'finance_settlements')) {
        $q = $pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='PAGAR' AND origin_id=?");
        $q->execute([$companyId, $payableId]);
        if ((int)$q->fetchColumn() > 0) continue;
      }

      $reason = 'V11.5: provisão futura cancelada automaticamente ao estornar a baixa #' . $settlementId . ' da Despesa Fixa #' . $fixedExpenseId . '.';
      $up = $pdo->prepare("UPDATE finance_payables SET status='CANCELADO', notes=TRIM(CONCAT(COALESCE(notes,''), CASE WHEN COALESCE(notes,'')<>'' THEN '\n' ELSE '' END, ?)), updated_at=NOW() WHERE company_id=? AND id=? AND status='ABERTO' AND COALESCE(paid_amount,0)<=0.009");
      $up->execute([$reason, $companyId, $payableId]);
      if ($up->rowCount() > 0) {
        $cancelled++;
        $trimRemaining--;
      }
    }
    if ($trimRemaining > 0) {
      error_log('V11.5: estorno da Despesa Fixa #' . $fixedExpenseId . ' não recolheu a provisão excedente porque nenhuma competência futura estava comprovadamente intocada.');
    }
    return $cancelled;
  }
}

if (!function_exists('finance_fixed_expense_replenish_for_payable')) {
  function finance_fixed_expense_replenish_for_payable(PDO $pdo, int $companyId, int $payableId): int {
    $ruleId = finance_fixed_expense_id_for_payable($pdo, $companyId, $payableId);
    return $ruleId > 0 ? finance_fixed_expense_ensure_horizon($pdo, $companyId, $ruleId) : 0;
  }
}

if (!function_exists('finance_fixed_expense_ensure_all')) {
  function finance_fixed_expense_ensure_all(PDO $pdo, int $companyId): int {
    if ($companyId <= 0 || !finance_table_exists($pdo, 'finance_fixed_expenses')) return 0;
    $st = $pdo->prepare('SELECT id FROM finance_fixed_expenses WHERE company_id=? AND active=1 ORDER BY id');
    $st->execute([$companyId]);
    $created = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $id) {
      try { $created += finance_fixed_expense_ensure_horizon($pdo, $companyId, (int)$id); }
      catch (Throwable $e) { error_log('Falha ao manter provisões da despesa fixa #' . (int)$id . ': ' . $e->getMessage()); }
    }
    return $created;
  }
}

if (!function_exists('finance_tree_indent')) {
  function finance_tree_indent(int $level): string {
    return str_repeat('&nbsp;&nbsp;&nbsp;&nbsp;', max(0, $level - 1));
  }
}

if (!function_exists('finance_result_by_chart')) {
  function finance_result_by_chart(PDO $pdo, int $companyId, string $dateFrom, string $dateTo): array {
    finance_chart_rebuild_hierarchy($pdo, $companyId);
    $accounts = finance_query_all($pdo, 'SELECT id, parent_id, code, classification, name, level, is_analytic, account_type, movement_type, display_order FROM finance_chart_accounts WHERE company_id = ? AND active = 1', [$companyId]);
    $accounts = finance_chart_sort_rows($accounts);
    $totals = [];
    $st = $pdo->prepare("SELECT chart_account_id, SUM(CASE WHEN type = 'CREDITO' THEN amount WHEN type = 'DEBITO' THEN -amount ELSE 0 END) AS total FROM finance_movements WHERE company_id = ? AND movement_date BETWEEN ? AND ? AND (status IS NULL OR status='' OR status='ATIVO') GROUP BY chart_account_id");
    $st->execute([$companyId, $dateFrom, $dateTo]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $totals[(int)$r['chart_account_id']] = (float)$r['total'];
    }

    $byId = [];
    $children = [];
    foreach ($accounts as $a) {
      $id = (int)$a['id'];
      $pid = (int)($a['parent_id'] ?? 0);
      $a['own_total'] = $totals[$id] ?? 0.0;
      $a['total'] = $a['own_total'];
      $byId[$id] = $a;
      if ($pid > 0 && $pid !== $id) $children[$pid][] = $id;
    }

    $state = [];
    $calc = function (int $id) use (&$calc, &$byId, &$children, &$state): float {
      if (($state[$id] ?? 0) === 2) return (float)($byId[$id]['total'] ?? 0);
      if (($state[$id] ?? 0) === 1) return (float)($byId[$id]['own_total'] ?? 0);
      $state[$id] = 1;
      $sum = (float)($byId[$id]['own_total'] ?? 0);
      foreach (($children[$id] ?? []) as $childId) $sum += $calc((int)$childId);
      $byId[$id]['total'] = $sum;
      $state[$id] = 2;
      return $sum;
    };
    foreach (array_keys($byId) as $id) $calc((int)$id);

    $out = [];
    foreach ($accounts as $a) {
      $id = (int)$a['id'];
      if (isset($byId[$id])) $out[] = $byId[$id];
    }
    return finance_chart_sort_rows($out);
  }
}
