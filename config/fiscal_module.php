<?php
/**
 * Base do Módulo Fiscal.
 * Esta camada prepara cadastros e informações fiscais para futuras emissões NFC-e/NF-e.
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

if (!function_exists('fiscal_h')) {
  function fiscal_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fiscal_ident_ok')) {
  function fiscal_ident_ok(string $name): bool { return (bool)preg_match('/^[a-zA-Z0-9_]+$/', $name); }
}

if (!function_exists('fiscal_table_exists')) {
  function fiscal_table_exists(PDO $pdo, string $table): bool {
    if (!fiscal_ident_ok($table)) return false;
    try {
      $st = $pdo->prepare('SHOW TABLES LIKE ?');
      $st->execute([$table]);
      if ($st->fetchColumn() !== false) return true;
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('fiscal_column_exists')) {
  function fiscal_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!fiscal_ident_ok($table) || !fiscal_ident_ok($column)) return false;
    try {
      $st = $pdo->prepare('SHOW COLUMNS FROM `' . $table . '` LIKE ?');
      $st->execute([$column]);
      if ($st->fetch(PDO::FETCH_ASSOC)) return true;
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('fiscal_add_column_if_missing')) {
  function fiscal_add_column_if_missing(PDO $pdo, string $table, string $column, string $definition): void {
    if (!fiscal_ident_ok($table) || !fiscal_ident_ok($column)) return;
    if (fiscal_column_exists($pdo, $table, $column)) return;
    try { $pdo->exec('ALTER TABLE `' . $table . '` ADD COLUMN `' . $column . '` ' . $definition); } catch (Throwable $e) {}
  }
}

if (!function_exists('fiscal_ensure_schema')) {
  function fiscal_ensure_schema(PDO $pdo): void {
    // Multiempresa: schemas são globais e devem estar preparados por migration/empresa principal.
    // Nunca executa CREATE/ALTER silenciosamente quando a aba está operando outra empresa pelo F11.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    static $done = false;
    if ($done) return;
    // Evita executar DDL dentro de transações de operação fiscal.
    // Em MySQL/MariaDB, CREATE/ALTER TABLE pode efetivar commit implícito,
    // fazendo o commit final disparar "There is no active transaction".
    if ($pdo->inTransaction()) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_tax_profiles (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      code VARCHAR(30) NULL,
      name VARCHAR(150) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      tax_mode VARCHAR(30) NOT NULL DEFAULT 'TRIBUTADO',
      profile_type VARCHAR(12) NOT NULL DEFAULT 'PRODUCT',
      nbs_code VARCHAR(20) NULL,
      nbs_description VARCHAR(255) NULL,
      lc116_service_code VARCHAR(10) NULL,
      lc116_service_description VARCHAR(255) NULL,
      iss_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      iss_withheld TINYINT(1) NOT NULL DEFAULT 0,
      pis_cofins_retention_type VARCHAR(10) NULL,
      irrf_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      csll_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      inss_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_tax_code VARCHAR(30) NULL,
      cnae VARCHAR(20) NULL,
      fiscal_incentive TINYINT(1) NOT NULL DEFAULT 0,
      nfse_municipal_tax_type VARCHAR(80) NULL,
      nfse_municipal_tax_nature VARCHAR(80) NULL,
      nfse_municipal_iss_exigibility VARCHAR(80) NULL,
      nfse_municipal_special_regime VARCHAR(120) NULL,
      nfse_national_tax_type VARCHAR(80) NULL,
      nfse_national_tax_code VARCHAR(30) NULL,
      nfse_national_operation_indicator VARCHAR(30) NULL,
      nfse_simples_tax_regime VARCHAR(120) NULL,
      nfse_operation_payment_type VARCHAR(120) NULL,
      nfse_rtc_special_regime VARCHAR(120) NULL,
      municipal_benefit_type VARCHAR(80) NULL,
      municipal_differentiated_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_reduction_base_percent DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_benefit_number VARCHAR(60) NULL,
      ibs_cbs_cst VARCHAR(10) NULL,
      ibs_cbs_classification_code VARCHAR(20) NULL,
      ibs_cbs_classification_name VARCHAR(180) NULL,
      icms_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      icms_reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      icms_cst VARCHAR(5) NULL,
      icms_csosn VARCHAR(5) NULL,
      icms_st_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      pis_in_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      pis_in_cst VARCHAR(5) NULL,
      cofins_in_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      cofins_in_cst VARCHAR(5) NULL,
      pis_out_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      pis_out_cst VARCHAR(5) NULL,
      cofins_out_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      cofins_out_cst VARCHAR(5) NULL,
      cbs_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_uf_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_mun_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_cbs_rate_mode VARCHAR(12) NOT NULL DEFAULT 'AUTO',
      cfop_sale_state VARCHAR(4) NULL,
      cfop_sale_state_desc VARCHAR(180) NULL,
      cfop_sale_interstate VARCHAR(4) NULL,
      cfop_sale_interstate_desc VARCHAR(180) NULL,
      cfop_return_state VARCHAR(4) NULL,
      cfop_return_state_desc VARCHAR(180) NULL,
      cfop_return_interstate VARCHAR(4) NULL,
      cfop_return_interstate_desc VARCHAR(180) NULL,
      cfop_entry_state VARCHAR(4) NULL,
      cfop_entry_state_desc VARCHAR(180) NULL,
      cfop_entry_interstate VARCHAR(4) NULL,
      cfop_entry_interstate_desc VARCHAR(180) NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_tax_profiles_company (company_id),
      KEY idx_fiscal_tax_profiles_active (company_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $profileColumns = [
      'code' => 'VARCHAR(30) NULL', 'name' => 'VARCHAR(150) NOT NULL', 'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'tax_mode' => "VARCHAR(30) NOT NULL DEFAULT 'TRIBUTADO'",
      'profile_type' => "VARCHAR(12) NOT NULL DEFAULT 'PRODUCT'", 'nbs_code' => 'VARCHAR(20) NULL', 'nbs_description' => 'VARCHAR(255) NULL',
      'lc116_service_code' => 'VARCHAR(10) NULL', 'lc116_service_description' => 'VARCHAR(255) NULL',
      'iss_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'iss_withheld' => 'TINYINT(1) NOT NULL DEFAULT 0', 'pis_cofins_retention_type' => 'VARCHAR(10) NULL', 'irrf_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'csll_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'inss_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
      'municipal_tax_code' => 'VARCHAR(30) NULL', 'cnae' => 'VARCHAR(20) NULL', 'fiscal_incentive' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'nfse_municipal_tax_type' => 'VARCHAR(80) NULL', 'nfse_municipal_tax_nature' => 'VARCHAR(80) NULL', 'nfse_municipal_iss_exigibility' => 'VARCHAR(80) NULL', 'nfse_municipal_special_regime' => 'VARCHAR(120) NULL',
      'nfse_national_tax_type' => 'VARCHAR(80) NULL', 'nfse_national_tax_code' => 'VARCHAR(30) NULL', 'nfse_national_operation_indicator' => 'VARCHAR(30) NULL', 'nfse_simples_tax_regime' => 'VARCHAR(120) NULL',
      'nfse_operation_payment_type' => 'VARCHAR(120) NULL', 'nfse_rtc_special_regime' => 'VARCHAR(120) NULL', 'municipal_benefit_type' => 'VARCHAR(80) NULL',
      'municipal_differentiated_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'municipal_reduction_base_percent' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'municipal_benefit_number' => 'VARCHAR(60) NULL',
      'ibs_cbs_cst' => 'VARCHAR(10) NULL', 'ibs_cbs_classification_code' => 'VARCHAR(20) NULL', 'ibs_cbs_classification_name' => 'VARCHAR(180) NULL',
      'icms_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'icms_reduction_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'icms_cst' => 'VARCHAR(5) NULL', 'icms_csosn' => 'VARCHAR(5) NULL', 'icms_st_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0',
      'pis_in_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'pis_in_cst' => 'VARCHAR(5) NULL', 'cofins_in_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'cofins_in_cst' => 'VARCHAR(5) NULL',
      'pis_out_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'pis_out_cst' => 'VARCHAR(5) NULL', 'cofins_out_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'cofins_out_cst' => 'VARCHAR(5) NULL',
      'cbs_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_uf_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_mun_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_cbs_rate_mode' => "VARCHAR(12) NOT NULL DEFAULT 'AUTO'",
      'cfop_sale_state' => 'VARCHAR(4) NULL', 'cfop_sale_state_desc' => 'VARCHAR(180) NULL', 'cfop_sale_interstate' => 'VARCHAR(4) NULL', 'cfop_sale_interstate_desc' => 'VARCHAR(180) NULL',
      'cfop_return_state' => 'VARCHAR(4) NULL', 'cfop_return_state_desc' => 'VARCHAR(180) NULL', 'cfop_return_interstate' => 'VARCHAR(4) NULL', 'cfop_return_interstate_desc' => 'VARCHAR(180) NULL',
      'cfop_entry_state' => 'VARCHAR(4) NULL', 'cfop_entry_state_desc' => 'VARCHAR(180) NULL', 'cfop_entry_interstate' => 'VARCHAR(4) NULL', 'cfop_entry_interstate_desc' => 'VARCHAR(180) NULL',
      'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($profileColumns as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_product_tax (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      product_id BIGINT NOT NULL,
      tax_profile_id INT NULL,
      item_type VARCHAR(12) NOT NULL DEFAULT 'PRODUCT',
      nbs_code VARCHAR(20) NULL,
      nbs_description VARCHAR(255) NULL,
      lc116_service_code VARCHAR(10) NULL,
      lc116_service_description VARCHAR(255) NULL,
      iss_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      iss_withheld TINYINT(1) NOT NULL DEFAULT 0,
      pis_cofins_retention_type VARCHAR(10) NULL,
      irrf_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      csll_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      inss_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_tax_code VARCHAR(30) NULL,
      cnae VARCHAR(20) NULL,
      fiscal_incentive TINYINT(1) NOT NULL DEFAULT 0,
      nfse_municipal_tax_type VARCHAR(80) NULL,
      nfse_municipal_tax_nature VARCHAR(80) NULL,
      nfse_municipal_iss_exigibility VARCHAR(80) NULL,
      nfse_municipal_special_regime VARCHAR(120) NULL,
      nfse_national_tax_type VARCHAR(80) NULL,
      nfse_national_tax_code VARCHAR(30) NULL,
      nfse_national_operation_indicator VARCHAR(30) NULL,
      nfse_simples_tax_regime VARCHAR(120) NULL,
      nfse_operation_payment_type VARCHAR(120) NULL,
      nfse_rtc_special_regime VARCHAR(120) NULL,
      municipal_benefit_type VARCHAR(80) NULL,
      municipal_differentiated_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_reduction_base_percent DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_benefit_number VARCHAR(60) NULL,
      ibs_cbs_cst VARCHAR(10) NULL,
      ncm VARCHAR(12) NULL,
      cest VARCHAR(12) NULL,
      origem VARCHAR(2) NULL,
      tax_mode VARCHAR(30) NULL,
      icms_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      icms_st_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      icms_cst VARCHAR(5) NULL,
      icms_csosn VARCHAR(5) NULL,
      ipi_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      freight_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      pis_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      pis_cst VARCHAR(5) NULL,
      cofins_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      cofins_cst VARCHAR(5) NULL,
      cbs_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_uf_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_mun_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibs_cbs_rate_mode VARCHAR(12) NOT NULL DEFAULT 'AUTO',
      ibs_cbs_classification_code VARCHAR(20) NULL,
      ibs_cbs_classification_name VARCHAR(180) NULL,
      cfop_sale_state VARCHAR(4) NULL,
      cfop_sale_interstate VARCHAR(4) NULL,
      cfop_return_state VARCHAR(4) NULL,
      cfop_return_interstate VARCHAR(4) NULL,
      cfop_entry_state VARCHAR(4) NULL,
      cfop_entry_interstate VARCHAR(4) NULL,
      is_monofasico TINYINT(1) NOT NULL DEFAULT 0,
      fiscal_notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_product_tax (company_id, product_id),
      KEY idx_fiscal_product_tax_profile (tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $prodCols = [
      'tax_profile_id' => 'INT NULL', 'item_type' => "VARCHAR(12) NOT NULL DEFAULT 'PRODUCT'", 'nbs_code' => 'VARCHAR(20) NULL', 'nbs_description' => 'VARCHAR(255) NULL',
      'lc116_service_code' => 'VARCHAR(10) NULL', 'lc116_service_description' => 'VARCHAR(255) NULL', 'iss_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'iss_withheld' => 'TINYINT(1) NOT NULL DEFAULT 0', 'pis_cofins_retention_type' => 'VARCHAR(10) NULL',
      'irrf_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'csll_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'inss_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'municipal_tax_code' => 'VARCHAR(30) NULL', 'cnae' => 'VARCHAR(20) NULL', 'fiscal_incentive' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'nfse_municipal_tax_type' => 'VARCHAR(80) NULL', 'nfse_municipal_tax_nature' => 'VARCHAR(80) NULL', 'nfse_municipal_iss_exigibility' => 'VARCHAR(80) NULL', 'nfse_municipal_special_regime' => 'VARCHAR(120) NULL',
      'nfse_national_tax_type' => 'VARCHAR(80) NULL', 'nfse_national_tax_code' => 'VARCHAR(30) NULL', 'nfse_national_operation_indicator' => 'VARCHAR(30) NULL', 'nfse_simples_tax_regime' => 'VARCHAR(120) NULL', 'nfse_operation_payment_type' => 'VARCHAR(120) NULL', 'nfse_rtc_special_regime' => 'VARCHAR(120) NULL',
      'municipal_benefit_type' => 'VARCHAR(80) NULL', 'municipal_differentiated_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'municipal_reduction_base_percent' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'municipal_benefit_number' => 'VARCHAR(60) NULL', 'ibs_cbs_cst' => 'VARCHAR(10) NULL',
      'ncm' => 'VARCHAR(12) NULL', 'cest' => 'VARCHAR(12) NULL', 'origem' => 'VARCHAR(2) NULL', 'tax_mode' => 'VARCHAR(30) NULL',
      'icms_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'icms_st_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'icms_cst' => 'VARCHAR(5) NULL', 'icms_csosn' => 'VARCHAR(5) NULL',
      'ipi_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ipi_cst' => 'VARCHAR(5) NULL', 'freight_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'pis_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'pis_cst' => 'VARCHAR(5) NULL', 'cofins_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'cofins_cst' => 'VARCHAR(5) NULL',
      'cbs_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_uf_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_mun_rate' => 'DECIMAL(12,4) NOT NULL DEFAULT 0', 'ibs_cbs_rate_mode' => "VARCHAR(12) NOT NULL DEFAULT 'AUTO'",
      'ibs_cbs_classification_code' => 'VARCHAR(20) NULL', 'ibs_cbs_classification_name' => 'VARCHAR(180) NULL',
      'cfop_sale_state' => 'VARCHAR(4) NULL', 'cfop_sale_interstate' => 'VARCHAR(4) NULL', 'cfop_return_state' => 'VARCHAR(4) NULL', 'cfop_return_interstate' => 'VARCHAR(4) NULL', 'cfop_entry_state' => 'VARCHAR(4) NULL', 'cfop_entry_interstate' => 'VARCHAR(4) NULL',
      'is_monofasico' => 'TINYINT(1) NOT NULL DEFAULT 0', 'fiscal_notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($prodCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', $c, $d);

    fiscal_seed_default_tax_profiles($pdo, defined('COMPANY_ID') ? (int)COMPANY_ID : 0);
    fiscal_ensure_aux_schema($pdo);
    fiscal_ensure_operation_cfop_schema($pdo);
  }
}

if (!function_exists('fiscal_decimal')) {
  function fiscal_decimal($value): float {
    $value = trim((string)$value);
    if ($value === '') return 0.0;
    $value = str_replace(['%', ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : 0.0;
  }
}

if (!function_exists('fiscal_seed_default_tax_profiles')) {
  function fiscal_seed_default_tax_profiles(PDO $pdo, int $companyId): void {
    if ($companyId <= 0) return;
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_tax_profiles WHERE company_id = ?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() > 0) return;
      $ins = $pdo->prepare("INSERT INTO fiscal_tax_profiles
        (company_id, code, name, is_active, tax_mode, ibs_cbs_classification_code, ibs_cbs_classification_name, icms_rate, icms_cst, icms_csosn, pis_in_rate, pis_in_cst, cofins_in_rate, cofins_in_cst, pis_out_rate, pis_out_cst, cofins_out_rate, cofins_out_cst, cfop_sale_state, cfop_sale_state_desc, cfop_sale_interstate, cfop_sale_interstate_desc, cfop_return_state, cfop_return_state_desc, cfop_return_interstate, cfop_return_interstate_desc, cfop_entry_state, cfop_entry_state_desc, cfop_entry_interstate, cfop_entry_interstate_desc)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $ins->execute([$companyId, '1', 'TRIBUTAÇÃO NORMAL', 1, 'TRIBUTADO', '', '', 0, '00', '102', 0, '50', 0, '50', 0, '01', 0, '01', '5102', 'VENDA DE MERCADORIA ADQUIRIDA', '6102', 'VENDA DE MERCADORIA ADQUIRIDA OU RECEBIDA DE TERCEIROS', '1202', 'DEVOLUÇÃO DE VENDA DE MERCADORIA', '2202', 'DEVOLUÇÃO DE VENDA DE MERCADORIA', '1102', 'COMPRA PARA COMERCIALIZAÇÃO', '2102', 'COMPRA PARA COMERCIALIZAÇÃO']);
      $ins->execute([$companyId, '2', 'SUBSTITUIÇÃO TRIBUTÁRIA', 1, 'SUBSTITUICAO', '', '', 0, '60', '500', 0, '50', 0, '50', 0, '01', 0, '01', '5405', 'VENDA DE MERCADORIA COM ST', '6404', 'VENDA DE MERCADORIA COM ST', '1411', 'DEVOLUÇÃO DE VENDA COM ST', '2411', 'DEVOLUÇÃO DE VENDA COM ST', '1403', 'COMPRA COM ST', '2403', 'COMPRA COM ST']);
      $ins->execute([$companyId, '3', 'TRIBUTAÇÃO ISENTA', 1, 'ISENTO', '', '', 0, '40', '400', 0, '50', 0, '50', 0, '01', 0, '01', '5102', 'VENDA DE MERCADORIA ADQUIRIDA', '6102', 'VENDA DE MERCADORIA ADQUIRIDA OU RECEBIDA DE TERCEIROS', '1202', 'DEVOLUÇÃO DE VENDA DE MERCADORIA', '2202', 'DEVOLUÇÃO DE VENDA DE MERCADORIA', '1102', 'COMPRA PARA COMERCIALIZAÇÃO', '2102', 'COMPRA PARA COMERCIALIZAÇÃO']);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('fiscal_normalize_item_type')) {
  function fiscal_normalize_item_type($value): string {
    $value = strtoupper(trim((string)$value));
    return in_array($value, ['SERVICE','SERVICO','SERVIÇO'], true) ? 'SERVICE' : 'PRODUCT';
  }
}

if (!function_exists('fiscal_tax_profiles')) {
  function fiscal_tax_profiles(PDO $pdo, int $companyId, bool $activeOnly = true, string $profileType = ''): array {
    fiscal_ensure_schema($pdo);
    $params = [$companyId];
    $sql = 'SELECT * FROM fiscal_tax_profiles WHERE company_id = ?' . ($activeOnly ? ' AND is_active = 1' : '');
    if (trim($profileType) !== '') {
      $sql .= ' AND profile_type = ?';
      $params[] = fiscal_normalize_item_type($profileType);
    }
    $sql .= ' ORDER BY is_active DESC, profile_type ASC, name ASC, id ASC';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_tax_profile')) {
  function fiscal_tax_profile(PDO $pdo, int $companyId, int $id): ?array {
    fiscal_ensure_schema($pdo);
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM fiscal_tax_profiles WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('fiscal_search_service_codes')) {
  function fiscal_search_service_codes(PDO $pdo, int $companyId, string $q = '', int $limit = 250): array {
    fiscal_ensure_schema($pdo);
    $q = trim($q);
    $limit = max(1, min(500, $limit));
    $params = [0, $companyId];
    $where = 'company_id IN (?, ?) AND is_active = 1';
    if ($q !== '') {
      $where .= ' AND (code LIKE ? OR description LIKE ?)';
      $like = nexa_search_like($q);
      $params[] = $like; $params[] = $like;
    }
    $sql = "SELECT * FROM fiscal_service_codes WHERE {$where} ORDER BY company_id DESC, code ASC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_search_nbs_codes')) {
  function fiscal_search_nbs_codes(PDO $pdo, int $companyId, string $q = '', int $limit = 250): array {
    fiscal_ensure_aux_schema($pdo);
    $q = trim($q);
    $limit = max(1, min(500, $limit));
    $params = [0, $companyId];
    $where = 'company_id IN (?, ?) AND is_active = 1';
    if ($q !== '') {
      $where .= ' AND (code LIKE ? OR description LIKE ?)';
      $like = nexa_search_like($q);
      $params[] = $like; $params[] = $like;
    }
    $st = $pdo->prepare("SELECT code, description, company_id FROM fiscal_nbs_codes WHERE {$where} ORDER BY company_id DESC, code ASC LIMIT {$limit}");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($rows) return fiscal_unique_rows_by_key($rows, fn($r) => (string)($r['code'] ?? ''), $limit);

    // Compatibilidade: a tabela IBPT também pode conter linhas do tipo NBS.
    $params = [];
    $where = "code_type='NBS' AND is_active=1";
    if ($q !== '') { $where .= ' AND (code LIKE ? OR description LIKE ?)'; $like=nexa_search_like($q); $params=[$like,$like]; }
    $st = $pdo->prepare("SELECT code, description, 0 AS company_id FROM fiscal_ibpt_rates WHERE {$where} ORDER BY code ASC LIMIT {$limit}");
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_profile_payload')) {
  function fiscal_profile_payload(array $r): array {
    $keys = [
      'id','code','name','profile_type','tax_mode','nbs_code','nbs_description','lc116_service_code','lc116_service_description',
      'iss_rate','iss_withheld','pis_cofins_retention_type','irrf_rate','csll_rate','inss_rate','municipal_tax_code','cnae','fiscal_incentive',
      'nfse_municipal_tax_type','nfse_municipal_tax_nature','nfse_municipal_iss_exigibility','nfse_municipal_special_regime',
      'nfse_national_tax_type','nfse_national_tax_code','nfse_national_operation_indicator','nfse_simples_tax_regime','nfse_operation_payment_type','nfse_rtc_special_regime',
      'municipal_benefit_type','municipal_differentiated_rate','municipal_reduction_base_percent','municipal_benefit_number',
      'ibs_cbs_cst','ibs_cbs_classification_code','ibs_cbs_classification_name','ipi_rate','ipi_cst','icms_rate','icms_cst','icms_csosn','icms_st_rate',
      'pis_out_rate','pis_out_cst','cofins_out_rate','cofins_out_cst','cbs_rate','ibs_uf_rate','ibs_mun_rate','ibs_cbs_rate_mode',
      'cfop_sale_state','cfop_sale_interstate','cfop_return_state','cfop_return_interstate','cfop_entry_state','cfop_entry_interstate'
    ];
    $out = [];
    foreach ($keys as $k) $out[$k] = $r[$k] ?? '';
    return $out;
  }
}

if (!function_exists('fiscal_blank_product_tax')) {
  function fiscal_blank_product_tax(): array {
    return [
      'tax_profile_id' => '', 'item_type' => 'PRODUCT', 'nbs_code' => '', 'nbs_description' => '',
      'lc116_service_code' => '', 'lc116_service_description' => '', 'iss_rate' => '0', 'iss_withheld' => '0', 'pis_cofins_retention_type' => '',
      'irrf_rate' => '0', 'csll_rate' => '0', 'inss_rate' => '0', 'municipal_tax_code' => '', 'cnae' => '', 'fiscal_incentive' => '0',
      'nfse_municipal_tax_type' => '', 'nfse_municipal_tax_nature' => '', 'nfse_municipal_iss_exigibility' => '', 'nfse_municipal_special_regime' => '',
      'nfse_national_tax_type' => '', 'nfse_national_tax_code' => '', 'nfse_national_operation_indicator' => '', 'nfse_simples_tax_regime' => '', 'nfse_operation_payment_type' => '', 'nfse_rtc_special_regime' => '',
      'municipal_benefit_type' => '', 'municipal_differentiated_rate' => '0', 'municipal_reduction_base_percent' => '0', 'municipal_benefit_number' => '', 'ibs_cbs_cst' => '',
      'ncm' => '', 'cest' => '', 'origem' => '0', 'tax_mode' => '',
      'icms_rate' => '0', 'icms_st_rate' => '0', 'icms_cst' => '00', 'icms_csosn' => '102', 'ipi_rate' => '0', 'ipi_cst' => '', 'freight_rate' => '0',
      'pis_rate' => '0', 'pis_cst' => '01', 'cofins_rate' => '0', 'cofins_cst' => '01', 'cbs_rate' => '0', 'ibs_uf_rate' => '0', 'ibs_mun_rate' => '0', 'ibs_cbs_rate_mode' => 'AUTO',
      'ibs_cbs_classification_code' => '', 'ibs_cbs_classification_name' => '', 'cfop_sale_state' => '5102', 'cfop_sale_interstate' => '6102', 'cfop_return_state' => '1202', 'cfop_return_interstate' => '2202', 'cfop_entry_state' => '1102', 'cfop_entry_interstate' => '2102',
      'is_monofasico' => '0', 'fiscal_notes' => ''
    ];
  }
}

if (!function_exists('fiscal_product_tax')) {
  function fiscal_product_tax(PDO $pdo, int $companyId, int $productId): array {
    fiscal_ensure_schema($pdo);
    $blank = fiscal_blank_product_tax();
    if ($productId <= 0) return $blank;
    $st = $pdo->prepare('SELECT * FROM fiscal_product_tax WHERE company_id = ? AND product_id = ? LIMIT 1');
    $st->execute([$companyId, $productId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ? array_merge($blank, $r) : $blank;
  }
}

if (!function_exists('fiscal_validate_product_tax_post')) {
  function fiscal_validate_product_tax_post(PDO $pdo, int $companyId, array $post): array {
    fiscal_ensure_schema($pdo);
    $errors = [];
    $itemType = fiscal_normalize_item_type($post['fiscal_item_type'] ?? (!empty($post['is_service']) ? 'SERVICE' : 'PRODUCT'));
    $profileId = (int)($post['fiscal_tax_profile_id'] ?? 0);
    if ($profileId > 0) {
      $profile = fiscal_tax_profile($pdo, $companyId, $profileId);
      if (!$profile) $errors[] = 'A regra de tributação selecionada não foi encontrada.';
      elseif (fiscal_normalize_item_type($profile['profile_type'] ?? 'PRODUCT') !== $itemType) {
        $errors[] = $itemType === 'SERVICE' ? 'Selecione uma regra de tributação do tipo Serviço.' : 'Selecione uma regra de tributação do tipo Produto.';
      }
    }
    if ($itemType === 'SERVICE') {
      if ($profileId <= 0) $errors[] = 'Selecione a regra de tributação do serviço.';
      $nbs = preg_replace('/\D+/', '', (string)($post['fiscal_nbs_code'] ?? '')) ?: '';
      $nationalCode = preg_replace('/\D+/', '', (string)($post['fiscal_nfse_national_tax_code'] ?? '')) ?: '';
      $operationIndicator = preg_replace('/\D+/', '', (string)($post['fiscal_nfse_national_operation_indicator'] ?? '')) ?: '';
      $ibsCbsCst = preg_replace('/\D+/', '', (string)($post['fiscal_ibs_cbs_cst'] ?? '')) ?: '';
      $classCode = preg_replace('/\D+/', '', (string)($post['fiscal_ibs_cbs_classification_code'] ?? '')) ?: '';
      if (strlen($nbs) !== 9) $errors[] = 'O código NBS do serviço deve possuir 9 dígitos.';
      if (trim((string)($post['fiscal_lc116_service_code'] ?? '')) === '') $errors[] = 'Informe o código da Lista de Serviços da LC 116/03.';
      if (strlen($nationalCode) !== 6) $errors[] = 'O Código de Tributação Nacional da NFS-e deve possuir 6 dígitos.';
      if (strlen($operationIndicator) !== 6) $errors[] = 'O indicador da operação da NFS-e Nacional deve possuir 6 dígitos.';
      if (strlen($ibsCbsCst) !== 3) $errors[] = 'O CST IBS/CBS do serviço deve possuir 3 dígitos.';
      if (strlen($classCode) !== 6) $errors[] = 'A classificação tributária IBS/CBS do serviço deve possuir 6 dígitos.';
    }
    return $errors;
  }
}

if (!function_exists('fiscal_save_product_tax_from_post')) {
  function fiscal_save_product_tax_from_post(PDO $pdo, int $companyId, int $productId, array $post): void {
    fiscal_ensure_schema($pdo);
    if ($productId <= 0) return;
    $itemType = fiscal_normalize_item_type($post['fiscal_item_type'] ?? (!empty($post['is_service']) ? 'SERVICE' : 'PRODUCT'));
    $fields = [
      'tax_profile_id' => (int)($post['fiscal_tax_profile_id'] ?? 0) ?: null,
      'item_type' => $itemType,
      'nbs_code' => preg_replace('/\D+/', '', (string)($post['fiscal_nbs_code'] ?? '')),
      'nbs_description' => trim((string)($post['fiscal_nbs_description'] ?? '')),
      'lc116_service_code' => preg_replace('/[^0-9.]+/', '', (string)($post['fiscal_lc116_service_code'] ?? '')),
      'lc116_service_description' => trim((string)($post['fiscal_lc116_service_description'] ?? '')),
      'iss_rate' => fiscal_decimal($post['fiscal_iss_rate'] ?? 0),
      'iss_withheld' => isset($post['fiscal_iss_withheld']) ? 1 : 0,
      'pis_cofins_retention_type' => trim((string)($post['fiscal_pis_cofins_retention_type'] ?? '')),
      'irrf_rate' => fiscal_decimal($post['fiscal_irrf_rate'] ?? 0),
      'csll_rate' => fiscal_decimal($post['fiscal_csll_rate'] ?? 0),
      'inss_rate' => fiscal_decimal($post['fiscal_inss_rate'] ?? 0),
      'municipal_tax_code' => trim((string)($post['fiscal_municipal_tax_code'] ?? '')),
      'cnae' => preg_replace('/\D+/', '', (string)($post['fiscal_cnae'] ?? '')),
      'fiscal_incentive' => isset($post['fiscal_fiscal_incentive']) ? 1 : 0,
      'nfse_municipal_tax_type' => trim((string)($post['fiscal_nfse_municipal_tax_type'] ?? '')),
      'nfse_municipal_tax_nature' => trim((string)($post['fiscal_nfse_municipal_tax_nature'] ?? '')),
      'nfse_municipal_iss_exigibility' => trim((string)($post['fiscal_nfse_municipal_iss_exigibility'] ?? '')),
      'nfse_municipal_special_regime' => trim((string)($post['fiscal_nfse_municipal_special_regime'] ?? '')),
      'nfse_national_tax_type' => trim((string)($post['fiscal_nfse_national_tax_type'] ?? '')),
      'nfse_national_tax_code' => preg_replace('/\D+/', '', (string)($post['fiscal_nfse_national_tax_code'] ?? '')),
      'nfse_national_operation_indicator' => preg_replace('/\D+/', '', (string)($post['fiscal_nfse_national_operation_indicator'] ?? '')),
      'nfse_simples_tax_regime' => trim((string)($post['fiscal_nfse_simples_tax_regime'] ?? '')),
      'nfse_operation_payment_type' => trim((string)($post['fiscal_nfse_operation_payment_type'] ?? '')),
      'nfse_rtc_special_regime' => trim((string)($post['fiscal_nfse_rtc_special_regime'] ?? '')),
      'municipal_benefit_type' => trim((string)($post['fiscal_municipal_benefit_type'] ?? '')),
      'municipal_differentiated_rate' => fiscal_decimal($post['fiscal_municipal_differentiated_rate'] ?? 0),
      'municipal_reduction_base_percent' => fiscal_decimal($post['fiscal_municipal_reduction_base_percent'] ?? 0),
      'municipal_benefit_number' => trim((string)($post['fiscal_municipal_benefit_number'] ?? '')),
      'ibs_cbs_cst' => preg_replace('/\D+/', '', (string)($post['fiscal_ibs_cbs_cst'] ?? '')),
      'ncm' => preg_replace('/\D+/', '', (string)($post['fiscal_ncm'] ?? '')),
      'cest' => preg_replace('/\D+/', '', (string)($post['fiscal_cest'] ?? '')),
      'origem' => trim((string)($post['fiscal_origem'] ?? '0')),
      'tax_mode' => trim((string)($post['fiscal_tax_mode'] ?? '')),
      'icms_rate' => fiscal_decimal($post['fiscal_icms_rate'] ?? 0),
      'icms_st_rate' => fiscal_decimal($post['fiscal_icms_st_rate'] ?? 0),
      'icms_cst' => trim((string)($post['fiscal_icms_cst'] ?? '')),
      'icms_csosn' => trim((string)($post['fiscal_icms_csosn'] ?? '')),
      'ipi_rate' => fiscal_decimal($post['fiscal_ipi_rate'] ?? 0),
      'ipi_cst' => trim((string)($post['fiscal_ipi_cst'] ?? '')),
      'freight_rate' => fiscal_decimal($post['fiscal_freight_rate'] ?? 0),
      'pis_rate' => fiscal_decimal($post['fiscal_pis_rate'] ?? 0),
      'pis_cst' => trim((string)($post['fiscal_pis_cst'] ?? '')),
      'cofins_rate' => fiscal_decimal($post['fiscal_cofins_rate'] ?? 0),
      'cofins_cst' => trim((string)($post['fiscal_cofins_cst'] ?? '')),
      'cbs_rate' => fiscal_decimal($post['fiscal_cbs_rate'] ?? 0),
      'ibs_uf_rate' => fiscal_decimal($post['fiscal_ibs_uf_rate'] ?? 0),
      'ibs_mun_rate' => fiscal_decimal($post['fiscal_ibs_mun_rate'] ?? 0),
      'ibs_cbs_rate_mode' => in_array(strtoupper(trim((string)($post['fiscal_ibs_cbs_rate_mode'] ?? 'AUTO'))), ['AUTO','MANUAL','DESATIVADO'], true) ? strtoupper(trim((string)($post['fiscal_ibs_cbs_rate_mode'] ?? 'AUTO'))) : 'AUTO',
      'ibs_cbs_classification_code' => preg_replace('/\D+/', '', (string)($post['fiscal_ibs_cbs_classification_code'] ?? '')),
      'ibs_cbs_classification_name' => trim((string)($post['fiscal_ibs_cbs_classification_name'] ?? '')),
      'cfop_sale_state' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_sale_state'] ?? '')),
      'cfop_sale_interstate' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_sale_interstate'] ?? '')),
      'cfop_return_state' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_return_state'] ?? '')),
      'cfop_return_interstate' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_return_interstate'] ?? '')),
      'cfop_entry_state' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_entry_state'] ?? '')),
      'cfop_entry_interstate' => preg_replace('/\D+/', '', (string)($post['fiscal_cfop_entry_interstate'] ?? '')),
      'is_monofasico' => isset($post['fiscal_is_monofasico']) ? 1 : 0,
      'fiscal_notes' => trim((string)($post['fiscal_notes'] ?? '')),
    ];
    $cols = array_keys($fields);
    $insertCols = array_merge(['company_id','product_id'], $cols);
    $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
    $updates = implode(', ', array_map(fn($c) => "`{$c}` = VALUES(`{$c}`)", $cols)) . ', updated_at = NOW()';
    $sql = 'INSERT INTO fiscal_product_tax (`' . implode('`,`', $insertCols) . '`) VALUES (' . $placeholders . ') ON DUPLICATE KEY UPDATE ' . $updates;
    $st = $pdo->prepare($sql);
    $st->execute(array_merge([$companyId, $productId], array_values($fields)));
  }
}

if (!function_exists('fiscal_ensure_aux_schema')) {
  function fiscal_ensure_aux_schema(PDO $pdo): void {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_service_codes (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      code VARCHAR(10) NOT NULL,
      description VARCHAR(255) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_service_codes_company_code (company_id, code),
      KEY idx_fiscal_service_codes_lookup (company_id, is_active, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_nbs_codes (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      code VARCHAR(20) NOT NULL,
      description VARCHAR(255) NOT NULL,
      national_service_code VARCHAR(10) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      source VARCHAR(120) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_nbs_codes_company_code (company_id, code),
      KEY idx_fiscal_nbs_codes_lookup (company_id, is_active, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $nbsColumns = [
      'company_id' => 'BIGINT NOT NULL DEFAULT 0',
      'code' => 'VARCHAR(20) NOT NULL',
      'description' => 'VARCHAR(255) NOT NULL',
      'national_service_code' => 'VARCHAR(10) NULL',
      'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'source' => 'VARCHAR(120) NULL',
      'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
      'updated_at' => 'DATETIME NULL',
    ];
    foreach ($nbsColumns as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_nbs_codes', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_cfops (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      code VARCHAR(4) NOT NULL,
      description VARCHAR(220) NOT NULL,
      operation_type VARCHAR(30) NULL,
      direction VARCHAR(15) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_cfops_company_code (company_id, code),
      KEY idx_fiscal_cfops_lookup (company_id, is_active, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ncms (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      ncm VARCHAR(8) NOT NULL,
      description TEXT NULL,
      cest VARCHAR(12) NULL,
      segment_code VARCHAR(20) NULL,
      segment_name VARCHAR(180) NULL,
      ibpt_federal_national DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibpt_federal_imported DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibpt_state DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibpt_municipal DECIMAL(12,4) NOT NULL DEFAULT 0,
      ibpt_version VARCHAR(40) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      source VARCHAR(80) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_ncms_company_ncm (company_id, ncm),
      KEY idx_fiscal_ncms_lookup (company_id, is_active, ncm)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_cests (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      cest VARCHAR(12) NOT NULL,
      ncm VARCHAR(8) NULL,
      description TEXT NULL,
      segment_code VARCHAR(20) NULL,
      segment_name VARCHAR(180) NULL,
      mva_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      icms_reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      validity_start DATE NULL,
      validity_end DATE NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_cests_company_cest_ncm (company_id, cest, ncm),
      KEY idx_fiscal_cests_lookup (company_id, is_active, cest)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_cst_codes (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      tax_type VARCHAR(20) NOT NULL,
      code VARCHAR(10) NOT NULL,
      description VARCHAR(220) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_cst_company_type_code (company_id, tax_type, code),
      KEY idx_fiscal_cst_lookup (company_id, tax_type, is_active, code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ibpt_imports (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      filename VARCHAR(190) NULL,
      version VARCHAR(40) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      rows_total INT NOT NULL DEFAULT 0,
      rows_imported INT NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'CONCLUIDO',
      notes TEXT NULL,
      imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_ibpt_imports_company (company_id, imported_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Catálogos globais v2.6. NCM, CEST, IBS/CBS, CBS e IBS usam
    // company_id=0 como catálogo mestre compartilhado. O IBPT é separado
    // por UF, versão e vigência, mas também é global entre empresas.
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ibpt_rates (
      id BIGINT NOT NULL AUTO_INCREMENT,
      uf CHAR(2) NOT NULL,
      code_type VARCHAR(10) NOT NULL DEFAULT 'NCM',
      code VARCHAR(12) NOT NULL,
      ex_code VARCHAR(10) NOT NULL DEFAULT '',
      description TEXT NULL,
      federal_national DECIMAL(12,4) NOT NULL DEFAULT 0,
      federal_imported DECIMAL(12,4) NOT NULL DEFAULT 0,
      state_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      municipal_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      version VARCHAR(40) NOT NULL DEFAULT '',
      validity_start DATE NOT NULL DEFAULT '1900-01-01',
      validity_end DATE NULL,
      source VARCHAR(100) NULL,
      source_hash VARCHAR(64) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_ibpt_global (uf, code_type, code, ex_code, version, validity_start),
      KEY idx_fiscal_ibpt_lookup (uf, code_type, code, is_active, validity_start, validity_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_company_tax_settings (
      company_id BIGINT NOT NULL,
      ibpt_uf CHAR(2) NULL,
      strict_global_catalogs TINYINT(1) NOT NULL DEFAULT 1,
      allow_manual_ibs_cbs_rates TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id),
      KEY idx_fiscal_company_tax_settings_uf (ibpt_uf)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_catalog_imports (
      id BIGINT NOT NULL AUTO_INCREMENT,
      catalog_type VARCHAR(40) NOT NULL,
      uf CHAR(2) NULL,
      version VARCHAR(80) NULL,
      filename VARCHAR(190) NULL,
      source VARCHAR(255) NULL,
      source_hash VARCHAR(64) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      rows_total INT NOT NULL DEFAULT 0,
      rows_imported INT NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'CONCLUIDO',
      notes TEXT NULL,
      imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_catalog_imports_type (catalog_type, imported_at),
      KEY idx_fiscal_catalog_imports_uf (uf, imported_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_catalog_legacy_backup (
      id BIGINT NOT NULL AUTO_INCREMENT,
      table_name VARCHAR(80) NOT NULL,
      company_id BIGINT NOT NULL,
      record_key VARCHAR(190) NULL,
      payload_json LONGTEXT NOT NULL,
      archived_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_catalog_legacy_company (company_id, table_name),
      KEY idx_fiscal_catalog_legacy_key (table_name, record_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    fiscal_add_column_if_missing($pdo, 'fiscal_ibpt_imports', 'uf', 'CHAR(2) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_ibpt_imports', 'source_hash', 'VARCHAR(64) NULL');


    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_cities (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      ibge_code VARCHAR(7) NOT NULL,
      name VARCHAR(120) NOT NULL,
      uf CHAR(2) NOT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_cities_company_ibge (company_id, ibge_code),
      KEY idx_fiscal_cities_lookup (company_id, uf, is_active, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");



    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_states (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      ibge_code VARCHAR(2) NOT NULL,
      name VARCHAR(120) NOT NULL,
      uf CHAR(2) NOT NULL,
      timezone_normal VARCHAR(20) NULL,
      timezone_dst VARCHAR(30) NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_states_company_uf (company_id, uf),
      UNIQUE KEY ux_fiscal_states_company_ibge (company_id, ibge_code),
      KEY idx_fiscal_states_lookup (company_id, is_active, uf, name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_cbs_rates (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      code VARCHAR(30) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_cbs_rates_company_vig (company_id, code, validity_start, validity_end),
      KEY idx_fiscal_cbs_rates_lookup (company_id, is_active, validity_start, validity_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ibs_uf_rates (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      uf CHAR(2) NOT NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_ibs_uf_rates_company_vig (company_id, uf, validity_start, validity_end),
      KEY idx_fiscal_ibs_uf_rates_lookup (company_id, uf, is_active, validity_start, validity_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ibs_mun_rates (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      ibge_code VARCHAR(7) NOT NULL,
      city_name VARCHAR(120) NULL,
      uf CHAR(2) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_ibs_mun_rates_company_vig (company_id, ibge_code, validity_start, validity_end),
      KEY idx_fiscal_ibs_mun_rates_lookup (company_id, ibge_code, is_active, validity_start, validity_end)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_ibs_cbs_classifications (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL DEFAULT 0,
      classification_code VARCHAR(20) NOT NULL,
      description TEXT NOT NULL,
      cst_code VARCHAR(10) NULL,
      rate_type VARCHAR(30) NULL,
      ibs_reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      cbs_reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      validity_start DATE NULL,
      validity_end DATE NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_ibs_cbs_class_company_code (company_id, classification_code),
      KEY idx_fiscal_ibs_cbs_class_lookup (company_id, is_active, classification_code)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'profile_type', "VARCHAR(12) NOT NULL DEFAULT 'PRODUCT'");
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nbs_code', 'VARCHAR(20) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nbs_description', 'VARCHAR(255) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'iss_withheld', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'pis_cofins_retention_type', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'ibs_cbs_cst', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'item_type', "VARCHAR(12) NOT NULL DEFAULT 'PRODUCT'");
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nbs_code', 'VARCHAR(20) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nbs_description', 'VARCHAR(255) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'lc116_service_code', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'lc116_service_description', 'VARCHAR(255) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'iss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'iss_withheld', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'pis_cofins_retention_type', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'irrf_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'csll_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'inss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'municipal_tax_code', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'cnae', 'VARCHAR(20) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'fiscal_incentive', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_municipal_tax_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_municipal_tax_nature', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_municipal_iss_exigibility', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_municipal_special_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_national_tax_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_national_tax_code', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_national_operation_indicator', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_simples_tax_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_operation_payment_type', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'nfse_rtc_special_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'municipal_benefit_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'municipal_differentiated_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'municipal_reduction_base_percent', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'municipal_benefit_number', 'VARCHAR(60) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'ibs_cbs_cst', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'lc116_service_code', 'VARCHAR(10) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'lc116_service_description', 'VARCHAR(255) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'iss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'irrf_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'csll_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'inss_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'municipal_tax_code', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'cnae', 'VARCHAR(20) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'fiscal_incentive', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_municipal_tax_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_municipal_tax_nature', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_municipal_iss_exigibility', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_municipal_special_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_national_tax_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_national_tax_code', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_national_operation_indicator', 'VARCHAR(30) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_simples_tax_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_operation_payment_type', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'nfse_rtc_special_regime', 'VARCHAR(120) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'municipal_benefit_type', 'VARCHAR(80) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'municipal_differentiated_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'municipal_reduction_base_percent', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'municipal_benefit_number', 'VARCHAR(60) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'ipi_rate', 'DECIMAL(12,4) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_tax_profiles', 'ipi_cst', 'VARCHAR(5) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_product_tax', 'ipi_cst', 'VARCHAR(5) NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_ibs_cbs_classifications', 'can_nfse', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_ibs_cbs_classifications', 'can_nfe', 'TINYINT(1) NOT NULL DEFAULT 0');
    fiscal_add_column_if_missing($pdo, 'fiscal_ibs_cbs_classifications', 'can_nfce', 'TINYINT(1) NOT NULL DEFAULT 0');

    fiscal_seed_aux_defaults($pdo);
  }
}

if (!function_exists('fiscal_seed_aux_defaults')) {
  function fiscal_seed_aux_defaults(PDO $pdo): void {
    try {
      $svc = $pdo->prepare("INSERT IGNORE INTO fiscal_service_codes (company_id, code, description, is_active) VALUES (0,?,?,1)");
      foreach ([
        ['0107','Suporte técnico em informática, inclusive instalação, configuração e manutenção de programas de computação e bancos de dados.'],
        ['0103','Processamento, armazenamento ou hospedagem de dados, textos, imagens, vídeos, páginas eletrônicas, aplicativos e sistemas de informação.'],
        ['0105','Licenciamento ou cessão de direito de uso de programas de computação.'],
      ] as $r) $svc->execute($r);

      $cfops = [
        ['5102','VENDA DE MERCADORIA ADQUIRIDA OU RECEBIDA DE TERCEIROS','VENDA','SAIDA'],
        ['6102','VENDA DE MERCADORIA ADQUIRIDA OU RECEBIDA DE TERCEIROS','VENDA','SAIDA'],
        ['5405','VENDA DE MERCADORIA COM SUBSTITUIÇÃO TRIBUTÁRIA','VENDA','SAIDA'],
        ['6404','VENDA DE MERCADORIA COM SUBSTITUIÇÃO TRIBUTÁRIA','VENDA','SAIDA'],
        ['1202','DEVOLUÇÃO DE VENDA DE MERCADORIA','DEVOLUCAO','ENTRADA'],
        ['2202','DEVOLUÇÃO DE VENDA DE MERCADORIA','DEVOLUCAO','ENTRADA'],
        ['1102','COMPRA PARA COMERCIALIZAÇÃO','COMPRA','ENTRADA'],
        ['2102','COMPRA PARA COMERCIALIZAÇÃO','COMPRA','ENTRADA'],
      ];
      $st = $pdo->prepare("INSERT IGNORE INTO fiscal_cfops (company_id, code, description, operation_type, direction, is_active) VALUES (0,?,?,?,?,1)");
      foreach ($cfops as $r) $st->execute($r);

      $codes = [
        ['ORIGEM','0','Nacional, exceto as indicadas nos códigos 3, 4, 5 e 8'],
        ['ORIGEM','1','Estrangeira - Importação direta'],
        ['ORIGEM','2','Estrangeira - Adquirida no mercado interno'],
        ['ICMS_CST','00','Tributada integralmente'],
        ['ICMS_CST','10','Tributada e com cobrança do ICMS por substituição tributária'],
        ['ICMS_CST','20','Com redução de base de cálculo'],
        ['ICMS_CST','40','Isenta'],
        ['ICMS_CST','41','Não tributada'],
        ['ICMS_CST','60','ICMS cobrado anteriormente por substituição tributária'],
        ['CSOSN','101','Tributada pelo Simples Nacional com permissão de crédito'],
        ['CSOSN','102','Tributada pelo Simples Nacional sem permissão de crédito'],
        ['CSOSN','300','Imune'],
        ['CSOSN','400','Não tributada pelo Simples Nacional'],
        ['CSOSN','500','ICMS cobrado anteriormente por substituição tributária'],
        ['IPI_CST','50','Saída tributada'],
        ['IPI_CST','51','Saída tributável com alíquota zero'],
        ['IPI_CST','52','Saída isenta'],
        ['IPI_CST','53','Saída não-tributada'],
        ['PIS_CST','01','Operação tributável com alíquota básica'],
        ['PIS_CST','04','Operação tributável monofásica - revenda a alíquota zero'],
        ['PIS_CST','06','Operação tributável a alíquota zero'],
        ['PIS_CST','49','Outras operações de saída'],
        ['PIS_CST','50','Operação com direito a crédito'],
        ['COFINS_CST','01','Operação tributável com alíquota básica'],
        ['COFINS_CST','04','Operação tributável monofásica - revenda a alíquota zero'],
        ['COFINS_CST','06','Operação tributável a alíquota zero'],
        ['COFINS_CST','49','Outras operações de saída'],
        ['COFINS_CST','50','Operação com direito a crédito'],
        ['IBS_CBS','000001','Classificação IBS/CBS padrão - ajustar conforme tabela oficial'],
      ];
      $st2 = $pdo->prepare("INSERT IGNORE INTO fiscal_cst_codes (company_id, tax_type, code, description, is_active) VALUES (0,?,?,?,1)");
      foreach ($codes as $r) $st2->execute($r);

      $pdo->exec("INSERT INTO fiscal_cities (company_id, ibge_code, name, uf, is_active) SELECT 0,'3131703','Itabira','MG',1 WHERE NOT EXISTS (SELECT 1 FROM fiscal_cities WHERE company_id=0 AND ibge_code='3131703')");
      $pdo->exec("INSERT INTO fiscal_cbs_rates (company_id, code, validity_start, validity_end, rate, is_active, notes) SELECT 0,'PADRAO','2025-12-28','2026-12-31',0.9000,1,'Alíquota padrão CBS inicial. Ajuste conforme tabela oficial vigente.' WHERE NOT EXISTS (SELECT 1 FROM fiscal_cbs_rates WHERE company_id=0 AND code='PADRAO' AND validity_start='2025-12-28')");
      $pdo->exec("INSERT INTO fiscal_ibs_uf_rates (company_id, uf, validity_start, validity_end, rate, is_active, notes) SELECT 0,'MG','2025-12-28','2026-12-31',0.1000,1,'Alíquota padrão IBS UF inicial. Ajuste conforme tabela oficial vigente.' WHERE NOT EXISTS (SELECT 1 FROM fiscal_ibs_uf_rates WHERE company_id=0 AND uf='MG' AND validity_start='2025-12-28')");
      $pdo->exec("INSERT INTO fiscal_ibs_mun_rates (company_id, ibge_code, city_name, uf, validity_start, validity_end, rate, is_active, notes) SELECT 0,'3131703','Itabira','MG','2025-12-28','2028-12-31',0.0000,1,'Alíquota padrão IBS município inicial. Ajuste conforme tabela oficial vigente.' WHERE NOT EXISTS (SELECT 1 FROM fiscal_ibs_mun_rates WHERE company_id=0 AND ibge_code='3131703' AND validity_start='2025-12-28')");
      $pdo->exec("INSERT INTO fiscal_ibs_cbs_classifications (company_id, classification_code, description, cst_code, rate_type, ibs_reduction_rate, cbs_reduction_rate, is_active, notes) SELECT 0,'000001','Classificação IBS/CBS padrão - ajustar conforme tabela oficial vigente',NULL,NULL,0,0,1,'Registro inicial apenas para validação da estrutura.' WHERE NOT EXISTS (SELECT 1 FROM fiscal_ibs_cbs_classifications WHERE company_id=0 AND classification_code='000001')");
    } catch (Throwable $e) {}
  }
}


if (!function_exists('fiscal_unique_rows_by_key')) {
  function fiscal_unique_rows_by_key(array $rows, callable $keyFn, int $limit = 500): array {
    $out = [];
    $seen = [];
    foreach ($rows as $row) {
      $key = (string)$keyFn($row);
      if ($key === '') $key = 'id:' . (string)($row['id'] ?? count($out));
      if (isset($seen[$key])) continue;
      $seen[$key] = true;
      $out[] = $row;
      if (count($out) >= $limit) break;
    }
    return $out;
  }
}

if (!function_exists('fiscal_global_catalog_company_id')) {
  function fiscal_global_catalog_company_id(): int { return 0; }
}

if (!function_exists('fiscal_company_tax_settings')) {
  function fiscal_company_tax_settings(PDO $pdo, int $companyId): array {
    static $cache = [];
    if (isset($cache[$companyId])) return $cache[$companyId];
    fiscal_ensure_aux_schema($pdo);
    $defaults = [
      'company_id'=>$companyId,
      'ibpt_uf'=>'',
      'strict_global_catalogs'=>1,
      'allow_manual_ibs_cbs_rates'=>1,
      'notes'=>'',
    ];
    if ($companyId <= 0) return $cache[$companyId] = $defaults;
    try {
      $st = $pdo->prepare('SELECT * FROM fiscal_company_tax_settings WHERE company_id=? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $cache[$companyId] = array_merge($defaults, $row);
    } catch (Throwable $e) {}
    $uf = '';
    try {
      if (fiscal_table_exists($pdo, 'fiscal_company_config')) {
        $st = $pdo->prepare('SELECT uf FROM fiscal_company_config WHERE company_id=? LIMIT 1');
        $st->execute([$companyId]);
        $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)$st->fetchColumn()), 0, 2));
      }
    } catch (Throwable $e) {}
    try {
      $st = $pdo->prepare("INSERT INTO fiscal_company_tax_settings
        (company_id,ibpt_uf,strict_global_catalogs,allow_manual_ibs_cbs_rates,updated_at)
        VALUES (?,?,1,1,NOW())
        ON DUPLICATE KEY UPDATE ibpt_uf=COALESCE(NULLIF(ibpt_uf,''),VALUES(ibpt_uf)),updated_at=NOW()");
      $st->execute([$companyId, $uf !== '' ? $uf : null]);
    } catch (Throwable $e) {}
    $defaults['ibpt_uf'] = $uf;
    return $cache[$companyId] = $defaults;
  }
}

if (!function_exists('fiscal_company_ibpt_uf')) {
  function fiscal_company_ibpt_uf(PDO $pdo, int $companyId): string {
    $settings = fiscal_company_tax_settings($pdo, $companyId);
    return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($settings['ibpt_uf'] ?? '')), 0, 2));
  }
}

if (!function_exists('fiscal_catalog_context_company_id')) {
  /**
   * V11.36: catálogos fiscais oficiais continuam compartilhados. Quando uma tela
   * de catálogo é aberta por F11, o companyId serve apenas para resolver contexto
   * auxiliar (UF do IBPT e eventuais overrides legados de CFOP/CST), nunca para
   * duplicar NCM/CEST/IBS-CBS por empresa.
   */
  function fiscal_catalog_context_company_id(PDO $pdo, int $fallbackCompanyId): int {
    if (function_exists('multi_company_operational_company_id')) {
      try {
        $ctx = (int)multi_company_operational_company_id($pdo, 'fiscal_catalogs_global');
        if ($ctx > 0) return $ctx;
      } catch (Throwable $e) {}
    }
    return $fallbackCompanyId;
  }
}

if (!function_exists('fiscal_lookup_ibpt_rate')) {
  function fiscal_lookup_ibpt_rate(PDO $pdo, int $companyId, string $code, ?string $date = null, string $codeType = 'NCM', string $exCode = ''): ?array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $uf = fiscal_company_ibpt_uf($pdo, $companyId);
    $code = preg_replace('/\D+/', '', $code) ?? '';
    $codeType = strtoupper(trim($codeType));
    $exCode = trim($exCode);
    $date = $date ?: date('Y-m-d');
    if ($uf === '' || $code === '') return null;
    try {
      $st = $pdo->prepare("SELECT * FROM fiscal_ibpt_rates
        WHERE uf=? AND code_type=? AND code=?
          AND ((?='' AND COALESCE(NULLIF(TRIM(ex_code),'0'),'')='') OR ex_code=?)
          AND is_active=1
          AND validity_start<=? AND (validity_end IS NULL OR validity_end>=?)
        ORDER BY validity_start DESC, id DESC LIMIT 1");
      $st->execute([$uf, $codeType, $code, $exCode, $exCode, $date, $date]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row && $exCode !== '') {
        $st = $pdo->prepare("SELECT * FROM fiscal_ibpt_rates
          WHERE uf=? AND code_type=? AND code=? AND COALESCE(NULLIF(TRIM(ex_code),'0'),'')='' AND is_active=1
            AND validity_start<=? AND (validity_end IS NULL OR validity_end>=?)
          ORDER BY validity_start DESC, id DESC LIMIT 1");
        $st->execute([$uf, $codeType, $code, $date, $date]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
      }
      return $row ?: null;
    } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('fiscal_search_cfops')) {
  function fiscal_search_cfops(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $args = [$companyId, 0];
    $where = 'WHERE company_id IN (?,?)';
    if ($q !== '') { $where .= ' AND (code LIKE ? OR description LIKE ? OR operation_type LIKE ?)'; $like=nexa_search_like($q); array_push($args,$like,$like,$like); }
    $st = $pdo->prepare("SELECT * FROM fiscal_cfops {$where} ORDER BY company_id DESC, code ASC LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return fiscal_unique_rows_by_key($rows, fn($r) => (string)($r['code'] ?? ''), $limit);
  }
}

if (!function_exists('fiscal_search_ncms')) {
  function fiscal_search_ncms(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $args = [];
    $where = 'WHERE n.company_id=0 AND n.is_active=1';
    if ($q !== '') {
      $where .= ' AND (n.ncm LIKE ? OR n.description LIKE ? OR n.cest LIKE ?)';
      $like=nexa_search_like($q);
      array_push($args,$like,$like,$like);
    }
    $st = $pdo->prepare("SELECT n.* FROM fiscal_ncms n {$where} ORDER BY n.ncm ASC LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $uf = fiscal_company_ibpt_uf($pdo, $companyId);
    if ($rows && $uf !== '') {
      $codes = array_values(array_unique(array_filter(array_map(fn($r)=>(string)($r['ncm']??''),$rows))));
      if ($codes) {
        $ph = implode(',', array_fill(0,count($codes),'?'));
        $date=date('Y-m-d');
        $sql="SELECT * FROM fiscal_ibpt_rates WHERE uf=? AND code_type='NCM' AND code IN ({$ph}) AND COALESCE(NULLIF(TRIM(ex_code),'0'),'')='' AND is_active=1 AND validity_start<=? AND (validity_end IS NULL OR validity_end>=?) ORDER BY code ASC,validity_start DESC,id DESC";
        $stIbpt=$pdo->prepare($sql);
        $stIbpt->execute(array_merge([$uf],$codes,[$date,$date]));
        $map=[];
        foreach($stIbpt->fetchAll(PDO::FETCH_ASSOC)?:[] as $ibpt){
          $code=(string)$ibpt['code'];
          if(!isset($map[$code]))$map[$code]=$ibpt;
        }
        foreach($rows as &$row){
          $ibpt=$map[(string)($row['ncm']??'')]??null;
          if(!$ibpt)continue;
          $row['ibpt_federal_national']=$ibpt['federal_national'];
          $row['ibpt_federal_imported']=$ibpt['federal_imported'];
          $row['ibpt_state']=$ibpt['state_rate'];
          $row['ibpt_municipal']=$ibpt['municipal_rate'];
          $row['ibpt_version']=$ibpt['version'];
          $row['validity_start']=$ibpt['validity_start'];
          $row['validity_end']=$ibpt['validity_end'];
          $row['source']=$ibpt['source'];
        }
        unset($row);
      }
    }
    return $rows;
  }
}

if (!function_exists('fiscal_search_cests')) {
  function fiscal_search_cests(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $args = [];
    $where = 'WHERE company_id=0 AND is_active=1';
    if ($q !== '') {
      $where .= ' AND (cest LIKE ? OR ncm LIKE ? OR description LIKE ? OR segment_name LIKE ?)';
      $like=nexa_search_like($q);
      array_push($args,$like,$like,$like,$like);
    }
    $st = $pdo->prepare("SELECT * FROM fiscal_cests {$where} ORDER BY cest ASC, ncm ASC LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_cst_codes')) {
  function fiscal_cst_codes(PDO $pdo, int $companyId, string $taxType = '', bool $activeOnly = true): array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $args = [$companyId, 0];
    $where = 'WHERE company_id IN (?,?)';
    if ($taxType !== '') { $where .= ' AND tax_type = ?'; $args[] = $taxType; }
    if ($activeOnly) $where .= ' AND is_active = 1';
    $st = $pdo->prepare("SELECT * FROM fiscal_cst_codes {$where} ORDER BY tax_type ASC, code ASC");
    $st->execute($args);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return fiscal_unique_rows_by_key($rows, fn($r) => (string)($r['tax_type'] ?? '') . '|' . (string)($r['code'] ?? ''));
  }
}

if (!function_exists('fiscal_lookup_ncm')) {
  function fiscal_lookup_ncm(PDO $pdo, int $companyId, string $ncm): ?array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = fiscal_catalog_context_company_id($pdo, $companyId);
    $ncm = preg_replace('/\D+/', '', $ncm);
    if ($ncm === '') return null;
    $st = $pdo->prepare('SELECT * FROM fiscal_ncms WHERE company_id=0 AND ncm=? AND is_active=1 LIMIT 1');
    $st->execute([$ncm]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $ibpt = fiscal_lookup_ibpt_rate($pdo, $companyId, $ncm, date('Y-m-d'), 'NCM', '');
    if ($ibpt) {
      $r['ibpt_federal_national']=$ibpt['federal_national'];
      $r['ibpt_federal_imported']=$ibpt['federal_imported'];
      $r['ibpt_state']=$ibpt['state_rate'];
      $r['ibpt_municipal']=$ibpt['municipal_rate'];
      $r['ibpt_version']=$ibpt['version'];
      $r['validity_start']=$ibpt['validity_start'];
      $r['validity_end']=$ibpt['validity_end'];
      $r['source']=$ibpt['source'];
    }
    return $r;
  }
}

if (!function_exists('fiscal_date_from_br')) {
  function fiscal_date_from_br($value): ?string {
    $value = trim((string)$value);
    if ($value === '') return null;
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
    if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) return $m[3].'-'.$m[2].'-'.$m[1];
    return null;
  }
}


if (!function_exists('fiscal_clean_utf8')) {
  function fiscal_clean_utf8($value): string {
    if ($value === null) return '';
    $s = (string)$value;
    if ($s === '') return '';
    $s = str_replace("\xEF\xBB\xBF", '', $s);
    if (!preg_match('//u', $s)) {
      if (function_exists('mb_convert_encoding')) {
        $converted = @mb_convert_encoding($s, 'UTF-8', 'Windows-1252,ISO-8859-1,UTF-8');
        if (is_string($converted) && $converted !== '') $s = $converted;
      } else {
        $converted = @iconv('Windows-1252', 'UTF-8//IGNORE', $s);
        if ($converted === false || $converted === '') $converted = @iconv('ISO-8859-1', 'UTF-8//IGNORE', $s);
        if (is_string($converted) && $converted !== '') $s = $converted;
      }
    }
    if (!preg_match('//u', $s)) {
      $s = @iconv('UTF-8', 'UTF-8//IGNORE', $s) ?: '';
    }
    $s = str_replace(["\xC2\x85", "\u{2026}"], '...', $s);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', $s) ?? $s;
    $s = preg_replace('/[ \t]+/u', ' ', $s) ?? $s;
    return trim($s);
  }
}

if (!function_exists('fiscal_clean_csv_row')) {
  function fiscal_clean_csv_row(array $row): array {
    foreach ($row as $k => $v) {
      if (is_string($v)) $row[$k] = fiscal_clean_utf8($v);
    }
    return $row;
  }
}

if (!function_exists('fiscal_import_ncm_csv_file')) {
  function fiscal_import_ncm_csv_file(PDO $pdo, string $tmpPath, string $filename): array {
    fiscal_ensure_aux_schema($pdo);
    $fh=fopen($tmpPath,'rb');
    if(!$fh) return ['ok'=>false,'message'=>'Não foi possível abrir o arquivo de NCM.','total'=>0,'imported'=>0];
    $first=fgets($fh);
    if($first===false) return ['ok'=>false,'message'=>'Arquivo NCM vazio.','total'=>0,'imported'=>0];
    $delim=substr_count($first,';')>=substr_count($first,',')?';':',';
    $headers=fiscal_clean_csv_row(str_getcsv(trim($first),$delim));
    $norm=static function($v){$v=fiscal_clean_utf8($v);return strtolower(preg_replace('/[^a-z0-9]+/i','',iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$v)?:$v));};
    $map=[];foreach($headers as $i=>$h)$map[$norm($h)]=$i;
    $idx=static function(array $m,array $names){foreach($names as $n)if(isset($m[$n]))return $m[$n];return null;};
    $iCode=$idx($map,['ncm','codigo','codigoncm']);
    $iDesc=$idx($map,['descricao','descricaoncm','texto']);
    if($iCode===null) return ['ok'=>false,'message'=>'A planilha NCM precisa da coluna NCM/Código.','total'=>0,'imported'=>0];
    $up=$pdo->prepare("INSERT INTO fiscal_ncms (company_id,ncm,description,is_active,source,updated_at)
      VALUES (0,?,?,1,'NCM_OFICIAL',NOW())
      ON DUPLICATE KEY UPDATE description=VALUES(description),is_active=1,source='NCM_OFICIAL',updated_at=NOW()");
    $total=0;$imported=0;
    while(($row=fgetcsv($fh,0,$delim))!==false){
      $row=fiscal_clean_csv_row($row);$total++;
      $code=substr(preg_replace('/\D+/','',(string)($row[$iCode]??''))?:'',0,8);
      if(strlen($code)!==8)continue;
      $desc=$iDesc!==null?fiscal_clean_utf8($row[$iDesc]??''):'';
      $up->execute([$code,$desc]);$imported++;
    }
    fclose($fh);
    $hash=@hash_file('sha256',$tmpPath)?:null;
    $log=$pdo->prepare("INSERT INTO fiscal_catalog_imports
      (catalog_type,filename,source,source_hash,rows_total,rows_imported,status,notes)
      VALUES ('NCM',?,?,?,?,?,'CONCLUIDO','Catálogo global NCM')");
    $log->execute([fiscal_clean_utf8($filename),'Portal Siscomex',$hash,$total,$imported]);
    return ['ok'=>true,'message'=>"NCM global atualizado: {$imported} registro(s).",'total'=>$total,'imported'=>$imported];
  }
}

if (!function_exists('fiscal_import_ibpt_file')) {
  function fiscal_import_ibpt_file(PDO $pdo, int $companyId, string $tmpPath, string $filename, string $uf = ''): array {
    fiscal_ensure_aux_schema($pdo);
    $uf=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$uf!==''?$uf:fiscal_company_ibpt_uf($pdo,$companyId)),0,2));
    if($uf==='') return ['ok'=>false,'message'=>'Informe a UF da tabela IBPT (ex.: MG).','total'=>0,'imported'=>0];
    $fh = fopen($tmpPath, 'rb');
    if (!$fh) return ['ok'=>false,'message'=>'Não foi possível abrir o arquivo.','total'=>0,'imported'=>0];
    $first = fgets($fh);
    if ($first === false) return ['ok'=>false,'message'=>'Arquivo vazio.','total'=>0,'imported'=>0];
    $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    $headers = fiscal_clean_csv_row(str_getcsv(trim($first), $delim));
    $norm = static function($s){ $s = fiscal_clean_utf8($s); return strtolower(preg_replace('/[^a-z0-9]+/i','', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s) ?: $s)); };
    $map = []; foreach ($headers as $i=>$h) $map[$norm($h)] = $i;
    $idx = static function(array $map, array $names){ foreach ($names as $n) if (isset($map[$n])) return $map[$n]; return null; };
    $iCodigo=$idx($map,['codigo','ncm','codigoncm']); $iDesc=$idx($map,['descricao','descricaoncm']);
    $iNac=$idx($map,['aliqnac','aliquotanacional','nacionalfederal','federalnacional']);
    $iImp=$idx($map,['aliqimp','aliquotaimportado','importadosfederal','federalimportado']);
    $iEst=$idx($map,['aliqestadual','estadual']); $iMun=$idx($map,['aliqmunicipal','municipal']);
    $iIni=$idx($map,['vigenciainicio','iniciovigencia','inicio']); $iFim=$idx($map,['vigenciafim','fimvigencia','fim']);
    $iVersao=$idx($map,['versao','versaoibpt']); $iFonte=$idx($map,['fonte','origem']);
    $iTipo=$idx($map,['tipo','tipocodigo']); $iEx=$idx($map,['ex','excecao','excode']);
    if ($iCodigo===null) return ['ok'=>false,'message'=>'Não encontrei a coluna Código/NCM no arquivo IBPT.','total'=>0,'imported'=>0];
    $up=$pdo->prepare("INSERT INTO fiscal_ibpt_rates
      (uf,code_type,code,ex_code,description,federal_national,federal_imported,state_rate,municipal_rate,version,validity_start,validity_end,source,source_hash,is_active,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())
      ON DUPLICATE KEY UPDATE description=VALUES(description),federal_national=VALUES(federal_national),federal_imported=VALUES(federal_imported),state_rate=VALUES(state_rate),municipal_rate=VALUES(municipal_rate),validity_end=VALUES(validity_end),source=VALUES(source),source_hash=VALUES(source_hash),is_active=1,updated_at=NOW()");
    $upNcm=$pdo->prepare("INSERT INTO fiscal_ncms (company_id,ncm,description,is_active,source,updated_at)
      VALUES (0,?,?,1,'IBPT',NOW())
      ON DUPLICATE KEY UPDATE description=IF(COALESCE(description,'')='',VALUES(description),description),is_active=1,updated_at=NOW()");
    $total=0;$imported=0;$version='';$vstart=null;$vend=null;$hash=@hash_file('sha256',$tmpPath)?:null;
    while(($row=fgetcsv($fh,0,$delim))!==false){
      $row=fiscal_clean_csv_row($row);$total++;
      $code=preg_replace('/\D+/','',(string)($row[$iCodigo]??''))?:'';
      if($code==='')continue;
      $type=$iTipo!==null?strtoupper(trim((string)($row[$iTipo]??''))):(strlen($code)===8?'NCM':'NBS');
      if(!in_array($type,['NCM','NBS'],true))$type=strlen($code)===8?'NCM':'NBS';
      $desc=$iDesc!==null?fiscal_clean_utf8($row[$iDesc]??''):'';
      $nac=$iNac!==null?fiscal_decimal($row[$iNac]??0):0; $imp=$iImp!==null?fiscal_decimal($row[$iImp]??0):0;
      $est=$iEst!==null?fiscal_decimal($row[$iEst]??0):0; $mun=$iMun!==null?fiscal_decimal($row[$iMun]??0):0;
      $ini=$iIni!==null?fiscal_date_from_br($row[$iIni]??''):null; $fim=$iFim!==null?fiscal_date_from_br($row[$iFim]??''):null;
      $ver=$iVersao!==null?fiscal_clean_utf8($row[$iVersao]??''):''; $fonte=$iFonte!==null?fiscal_clean_utf8($row[$iFonte]??''):'IBPT';
      $ex=$iEx!==null?trim((string)($row[$iEx]??'')):'';
      if($version===''&&$ver!=='')$version=$ver;if($vstart===null&&$ini)$vstart=$ini;if($vend===null&&$fim)$vend=$fim;
      $up->execute([$uf,$type,$code,$ex,$desc,$nac,$imp,$est,$mun,$ver,$ini?:'1900-01-01',$fim,$fonte?:'IBPT',$hash]);
      if($type==='NCM'&&strlen($code)===8)$upNcm->execute([$code,$desc]);
      $imported++;
    }
    fclose($fh);
    $log=$pdo->prepare("INSERT INTO fiscal_ibpt_imports
      (company_id,uf,filename,version,validity_start,validity_end,rows_total,rows_imported,status,notes,source_hash)
      VALUES (0,?,?,?,?,?,?,?,'CONCLUIDO','Catálogo global IBPT por UF',?)");
    $log->execute([$uf,fiscal_clean_utf8($filename),$version,$vstart,$vend,$total,$imported,$hash]);
    $log2=$pdo->prepare("INSERT INTO fiscal_catalog_imports
      (catalog_type,uf,version,filename,source,source_hash,validity_start,validity_end,rows_total,rows_imported,status,notes)
      VALUES ('IBPT',?,?,?,?,?,?,?,?,?,'CONCLUIDO','Catálogo global por UF')");
    $log2->execute([$uf,$version,fiscal_clean_utf8($filename),'IBPT',$hash,$vstart,$vend,$total,$imported]);
    return ['ok'=>true,'message'=>"IBPT global da UF {$uf} atualizado: {$imported} registro(s).",'total'=>$total,'imported'=>$imported,'uf'=>$uf];
  }
}

if (!function_exists('fiscal_read_json_file')) {
  function fiscal_read_json_file(string $tmpPath): array {
    $raw = file_get_contents($tmpPath);
    if ($raw === false || trim($raw) === '') throw new RuntimeException('Arquivo JSON vazio ou inválido.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('Não foi possível ler o JSON. Verifique o formato do arquivo.');
    return $data;
  }
}

if (!function_exists('fiscal_import_ibs_cbs_json_file')) {
  function fiscal_import_ibs_cbs_json_file(PDO $pdo, string $tmpPath, string $filename, int $targetCompanyId = 0): array {
    fiscal_ensure_aux_schema($pdo);
    $targetCompanyId = 0; // catálogo oficial global
    $data = fiscal_read_json_file($tmpPath);
    $rows = $data['classificacaotributariaibscbs'] ?? $data['classificacoes'] ?? $data['TabelaClassificacaoTributaria'] ?? $data;
    if (!is_array($rows)) return ['ok'=>false,'message'=>'JSON de classificação IBS/CBS não possui a lista esperada.','total'=>0,'imported'=>0];
    $sql = "INSERT INTO fiscal_ibs_cbs_classifications
      (company_id, classification_code, description, cst_code, rate_type, ibs_reduction_rate, cbs_reduction_rate, validity_start, validity_end, can_nfse, can_nfe, can_nfce, is_active, updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,1,NOW())
      ON DUPLICATE KEY UPDATE description=VALUES(description), cst_code=VALUES(cst_code), rate_type=VALUES(rate_type), ibs_reduction_rate=VALUES(ibs_reduction_rate), cbs_reduction_rate=VALUES(cbs_reduction_rate), validity_start=VALUES(validity_start), validity_end=VALUES(validity_end), can_nfse=VALUES(can_nfse), can_nfe=VALUES(can_nfe), can_nfce=VALUES(can_nfce), is_active=1, updated_at=NOW()";
    $st = $pdo->prepare($sql);
    $pick = static function(array $row, array $keys, $default = '') {
      foreach ($keys as $key) if (array_key_exists($key, $row) && $row[$key] !== null && $row[$key] !== '') return $row[$key];
      return $default;
    };
    $flag = static function($value): int {
      if (is_bool($value)) return $value ? 1 : 0;
      if (is_numeric($value)) return (float)$value != 0.0 ? 1 : 0;
      return in_array(strtoupper(trim((string)$value)), ['1','S','SIM','Y','YES','TRUE','X'], true) ? 1 : 0;
    };
    $dateValue = static function($value): ?string {
      $text = trim((string)$value);
      if ($text === '') return null;
      if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $text, $m)) return $m[1];
      if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/', $text, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
      return null;
    };
    $total=0; $imported=0;
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      $total++;
      $code = substr(preg_replace('/\D+/', '', (string)$pick($r, ['cClassTrib','classificacao','classification_code','codigo'])) ?: '', 0, 6);
      $desc = trim((string)$pick($r, ['Nome cClassTrib','nomeClassTrib','nome','description','descricao','Descrição cClassTrib']));
      $detail = trim((string)$pick($r, ['Descricao cClassTrib','descricaoClassTrib','descricaoCompleta','Descrição cClassTrib']));
      if ($detail !== '' && $detail !== $desc) $desc = $desc !== '' ? ($desc . ' - ' . $detail) : $detail;
      if ($code === '' || $desc === '') continue;
      $st->execute([
        $targetCompanyId,
        $code,
        $desc,
        (string)$pick($r, ['CST-IBS/CBS','CST','cst','cst_code'], substr($code,0,3)),
        (string)$pick($r, ['Tipo de Alíquota','TipoAliquota','tipodealiquota','rate_type']),
        fiscal_decimal($pick($r, ['pRedIBS','aliquotareducaoibs','ibs_reduction_rate'], 0)),
        fiscal_decimal($pick($r, ['pRedCBS','aliquotareducaocbs','cbs_reduction_rate'], 0)),
        $dateValue($pick($r, ['dIniVig','validity_start','vigenciaInicial'])),
        $dateValue($pick($r, ['dFimVig','validity_end','vigenciaFinal'])),
        $flag($pick($r, ['indNFSe','podenfse','can_nfse'], 0)),
        $flag($pick($r, ['indNFe','podenfe','can_nfe'], 0)),
        $flag($pick($r, ['indNFCe','podenfce','can_nfce'], 0)),
      ]);
      $imported++;
    }
    return ['ok'=>true,'message'=>"Classificações IBS/CBS importadas: {$imported} registro(s).",'total'=>$total,'imported'=>$imported];
  }
}

if (!function_exists('fiscal_import_cities_json_file')) {
  function fiscal_import_cities_json_file(PDO $pdo, string $tmpPath, string $filename, int $targetCompanyId = 0): array {
    fiscal_ensure_aux_schema($pdo);
    $targetCompanyId = 0; // catálogo oficial global
    $data = fiscal_read_json_file($tmpPath);
    $rows = $data['municipios'] ?? $data['cities'] ?? $data;
    if (!is_array($rows)) return ['ok'=>false,'message'=>'JSON de municípios não possui a lista esperada.','total'=>0,'imported'=>0];
    $st = $pdo->prepare("INSERT INTO fiscal_cities (company_id, ibge_code, name, uf, is_active, updated_at) VALUES (?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name), uf=VALUES(uf), is_active=1, updated_at=NOW()");
    $total=0; $imported=0;
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      $total++;
      $ibge = preg_replace('/\D+/', '', (string)($r['codigoibge'] ?? $r['ibge_code'] ?? $r['codigo'] ?? ''));
      $name = trim((string)($r['nomemunicipio'] ?? $r['name'] ?? $r['municipio'] ?? ''));
      $uf = strtoupper(substr(trim((string)($r['sigla'] ?? $r['uf'] ?? '')),0,2));
      if ($ibge === '' || $name === '' || $uf === '') continue;
      $st->execute([$targetCompanyId,$ibge,$name,$uf]);
      $imported++;
    }
    return ['ok'=>true,'message'=>"Municípios/IBGE importados: {$imported} registro(s).",'total'=>$total,'imported'=>$imported];
  }
}

if (!function_exists('fiscal_import_states_json_file')) {
  function fiscal_import_states_json_file(PDO $pdo, string $tmpPath, string $filename, int $targetCompanyId = 0): array {
    fiscal_ensure_aux_schema($pdo);
    $targetCompanyId = 0; // catálogo oficial global
    $data = fiscal_read_json_file($tmpPath);
    $rows = $data['ufestados'] ?? $data['estados'] ?? $data['states'] ?? $data;
    if (!is_array($rows)) return ['ok'=>false,'message'=>'JSON de UF/Estados não possui a lista esperada.','total'=>0,'imported'=>0];
    $st = $pdo->prepare("INSERT INTO fiscal_states (company_id, ibge_code, name, uf, timezone_normal, timezone_dst, is_active, updated_at) VALUES (?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE ibge_code=VALUES(ibge_code), name=VALUES(name), timezone_normal=VALUES(timezone_normal), timezone_dst=VALUES(timezone_dst), is_active=1, updated_at=NOW()");
    $total=0; $imported=0;
    foreach ($rows as $r) {
      if (!is_array($r)) continue;
      $total++;
      $ibge = preg_replace('/\D+/', '', (string)($r['codigoibge'] ?? $r['ibge_code'] ?? ''));
      $name = trim((string)($r['descricao'] ?? $r['name'] ?? $r['nome'] ?? ''));
      $uf = strtoupper(substr(trim((string)($r['sigla'] ?? $r['uf'] ?? '')),0,2));
      if ($ibge === '' || $name === '' || $uf === '') continue;
      $st->execute([$targetCompanyId,$ibge,$name,$uf,(string)($r['fusohorarionormal'] ?? ''),(string)($r['fusohorarioverao'] ?? '')]);
      $imported++;
    }
    return ['ok'=>true,'message'=>"UF/Estados importados: {$imported} registro(s).",'total'=>$total,'imported'=>$imported];
  }
}

if (!function_exists('fiscal_import_cests_csv_file')) {
  function fiscal_import_cests_csv_file(PDO $pdo, int $companyId, string $tmpPath, string $filename): array {
    fiscal_ensure_aux_schema($pdo);
    $companyId = 0; // catálogo CEST global
    $fh = fopen($tmpPath, 'rb');
    if (!$fh) return ['ok'=>false,'message'=>'Não foi possível abrir o arquivo de CEST.','total'=>0,'imported'=>0];
    $first = fgets($fh);
    if ($first === false) return ['ok'=>false,'message'=>'Arquivo vazio.','total'=>0,'imported'=>0];
    $delim = substr_count($first, ';') >= substr_count($first, ',') ? ';' : ',';
    $headers = fiscal_clean_csv_row(str_getcsv(trim($first), $delim));
    $norm = static function($s){ $s = fiscal_clean_utf8($s); return strtolower(preg_replace('/[^a-z0-9]+/i','', iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$s) ?: $s)); };
    $map=[]; foreach($headers as $i=>$h) $map[$norm($h)]=$i;
    $idx = static function(array $map, array $names){ foreach($names as $n) if(isset($map[$n])) return $map[$n]; return null; };
    $iCest=$idx($map,['cest','codigo','codigocest']);
    $iNcm=$idx($map,['ncm','codigoncm']);
    $iDesc=$idx($map,['descricao','descricaocest','nome']);
    $iSeg=$idx($map,['segmento','segmentonome','nomesegmento']);
    $iSegCod=$idx($map,['codigosegmento','segmentocode','segmentocodigo']);
    if($iCest===null || $iNcm===null) return ['ok'=>false,'message'=>'A planilha de CEST precisa ter pelo menos as colunas CEST e NCM.','total'=>0,'imported'=>0];
    $up=$pdo->prepare("INSERT INTO fiscal_cests (company_id,cest,ncm,description,segment_code,segment_name,is_active,updated_at) VALUES (?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description), segment_code=VALUES(segment_code), segment_name=VALUES(segment_name), is_active=1, updated_at=NOW()");
    $total=0; $imported=0;
    while(($row=fgetcsv($fh,0,$delim))!==false){
      $total++;
      $cest=preg_replace('/\D+/','',(string)($row[$iCest]??''));
      $ncm=preg_replace('/\D+/','',(string)($row[$iNcm]??''));
      if($cest==='' || $ncm==='') continue;
      $desc=$iDesc!==null?trim((string)($row[$iDesc]??'')):'';
      $seg=$iSeg!==null?trim((string)($row[$iSeg]??'')):'';
      $segcod=$iSegCod!==null?trim((string)($row[$iSegCod]??'')):'';
      $up->execute([$companyId,$cest,$ncm,$desc,$segcod,$seg]);
      $imported++;
    }
    fclose($fh);
    return ['ok'=>true,'message'=>"CEST x NCM importados: {$imported} vínculo(s).",'total'=>$total,'imported'=>$imported];
  }
}

if (!function_exists('fiscal_cests_by_ncm')) {
  function fiscal_cests_by_ncm(PDO $pdo, int $companyId, string $ncm, int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $ncm = preg_replace('/\D+/', '', $ncm);
    if ($ncm === '') return [];
    $st = $pdo->prepare("SELECT * FROM fiscal_cests WHERE company_id=0 AND is_active=1 AND ncm=? ORDER BY cest ASC LIMIT " . max(1, min(500, $limit)));
    $st->execute([$ncm]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_search_cities')) {
  function fiscal_search_cities(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $args=[]; $where='WHERE company_id=0 AND is_active=1';
    if($q!==''){ $where.=' AND (ibge_code LIKE ? OR name LIKE ? OR uf LIKE ?)';$like=nexa_search_like($q);array_push($args,$like,$like,$like); }
    $st=$pdo->prepare("SELECT * FROM fiscal_cities {$where} ORDER BY uf ASC,name ASC LIMIT ".max(1,min(500,$limit)));
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('fiscal_search_ibs_cbs_classifications')) {
  function fiscal_search_ibs_cbs_classifications(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_ensure_aux_schema($pdo);
    $args=[];$where='WHERE company_id=0 AND is_active=1';
    if($q!==''){ $where.=' AND (classification_code LIKE ? OR description LIKE ? OR cst_code LIKE ?)';$like=nexa_search_like($q);array_push($args,$like,$like,$like); }
    $st=$pdo->prepare("SELECT * FROM fiscal_ibs_cbs_classifications {$where} ORDER BY classification_code ASC LIMIT ".max(1,min(500,$limit)));
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('fiscal_ibs_cbs_classification_by_code')) {
  function fiscal_ibs_cbs_classification_by_code(PDO $pdo, int $companyId, string $code): ?array {
    fiscal_ensure_aux_schema($pdo);
    $code=substr(preg_replace('/\D+/','',$code)?:'',0,6);
    if($code==='')return null;
    $st=$pdo->prepare('SELECT * FROM fiscal_ibs_cbs_classifications WHERE company_id=0 AND classification_code=? AND is_active=1 LIMIT 1');
    $st->execute([$code]);
    $r=$st->fetch(PDO::FETCH_ASSOC);
    return $r?:null;
  }
}

if (!function_exists('fiscal_current_cbs_rate')) {
  function fiscal_current_cbs_rate(PDO $pdo, int $companyId, ?string $date = null): ?array {
    fiscal_ensure_aux_schema($pdo);$date=$date?:date('Y-m-d');
    $st=$pdo->prepare("SELECT * FROM fiscal_cbs_rates WHERE company_id=0 AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY validity_start DESC,id DESC LIMIT 1");
    $st->execute([$date,$date]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;
  }
}

if (!function_exists('fiscal_current_ibs_uf_rate')) {
  function fiscal_current_ibs_uf_rate(PDO $pdo, int $companyId, string $uf, ?string $date = null): ?array {
    fiscal_ensure_aux_schema($pdo);$date=$date?:date('Y-m-d');$uf=strtoupper(substr(preg_replace('/[^A-Za-z]/','',$uf),0,2));if($uf==='')return null;
    $st=$pdo->prepare("SELECT * FROM fiscal_ibs_uf_rates WHERE company_id=0 AND uf=? AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY validity_start DESC,id DESC LIMIT 1");
    $st->execute([$uf,$date,$date]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;
  }
}

if (!function_exists('fiscal_current_ibs_mun_rate')) {
  function fiscal_current_ibs_mun_rate(PDO $pdo, int $companyId, string $ibgeCode, ?string $date = null): ?array {
    fiscal_ensure_aux_schema($pdo);$date=$date?:date('Y-m-d');$ibgeCode=substr(preg_replace('/\D+/','',$ibgeCode)?:'',0,7);if($ibgeCode==='')return null;
    $st=$pdo->prepare("SELECT * FROM fiscal_ibs_mun_rates WHERE company_id=0 AND ibge_code IN (?,'0000000') AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY (ibge_code=?) DESC,validity_start DESC,id DESC LIMIT 1");
    $st->execute([$ibgeCode,$date,$date,$ibgeCode]);$r=$st->fetch(PDO::FETCH_ASSOC);return $r?:null;
  }
}

if (!function_exists('fiscal_ibs_cbs_rate_mode')) {
  function fiscal_ibs_cbs_rate_mode(array $tax): string {
    $mode = strtoupper(trim((string)($tax['ibs_cbs_rate_mode'] ?? 'AUTO')));
    return in_array($mode, ['AUTO','MANUAL','DESATIVADO'], true) ? $mode : 'AUTO';
  }
}

if (!function_exists('fiscal_ibs_cbs_clamp_percent')) {
  function fiscal_ibs_cbs_clamp_percent($value): float {
    return max(0.0, min(100.0, (float)$value));
  }
}

if (!function_exists('fiscal_ibs_cbs_classification_direct')) {
  function fiscal_ibs_cbs_classification_direct(PDO $pdo, int $companyId, string $classCode, string $date = ''): ?array {
    $classCode=substr(preg_replace('/\D+/','',$classCode)?:'',0,6);if($classCode==='')return null;
    try{
      if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)){
        $st=$pdo->prepare('SELECT * FROM fiscal_ibs_cbs_classifications WHERE company_id=0 AND classification_code=? AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY validity_start DESC,id DESC LIMIT 1');
        $st->execute([$classCode,$date,$date]);
      }else{
        $st=$pdo->prepare('SELECT * FROM fiscal_ibs_cbs_classifications WHERE company_id=0 AND classification_code=? AND is_active=1 ORDER BY validity_start DESC,id DESC LIMIT 1');
        $st->execute([$classCode]);
      }
      $row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }catch(Throwable $e){return null;}
  }
}

if (!function_exists('fiscal_ibs_cbs_rate_row')) {
  function fiscal_ibs_cbs_rate_row(PDO $pdo, string $table, int $companyId, string $date, ?string $field = null, ?string $value = null): ?array {
    $allowed=['fiscal_cbs_rates','fiscal_ibs_uf_rates','fiscal_ibs_mun_rates'];if(!in_array($table,$allowed,true))return null;
    try{
      if($table==='fiscal_ibs_mun_rates'){
        $digits=substr(preg_replace('/\D+/','',(string)$value)?:'',0,7);if($digits==='')$digits='0000000';
        $st=$pdo->prepare("SELECT * FROM fiscal_ibs_mun_rates WHERE company_id=0 AND ibge_code IN (?,'0000000') AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY (ibge_code=?) DESC,validity_start DESC,id DESC LIMIT 1");
        $st->execute([$digits,$date,$date,$digits]);
      }elseif($table==='fiscal_ibs_uf_rates'){
        $uf=strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)$value),0,2));if($uf==='')return null;
        $st=$pdo->prepare("SELECT * FROM fiscal_ibs_uf_rates WHERE company_id=0 AND uf=? AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY validity_start DESC,id DESC LIMIT 1");
        $st->execute([$uf,$date,$date]);
      }else{
        $st=$pdo->prepare("SELECT * FROM fiscal_cbs_rates WHERE company_id=0 AND is_active=1 AND (validity_start IS NULL OR validity_start<=?) AND (validity_end IS NULL OR validity_end>=?) ORDER BY validity_start DESC,id DESC LIMIT 1");
        $st->execute([$date,$date]);
      }
      $row=$st->fetch(PDO::FETCH_ASSOC);return $row?:null;
    }catch(Throwable $e){return null;}
  }
}

if (!function_exists('fiscal_ibs_cbs_resolve')) {
  /**
   * Resolve a tributação IBS/CBS do item.
   * AUTO: usa tabelas por vigência; em 2026, se a tabela não tiver linha, aplica
   * as alíquotas-padrão publicadas (IBS UF 0,1%; IBS Mun 0%; CBS 0,9%).
   * MANUAL: usa as alíquotas do produto, inclusive zero.
   * DESATIVADO: não gera o grupo.
   */
  function fiscal_ibs_cbs_resolve(PDO $pdo, int $companyId, array $tax, array $cfg, string $model, string $issueDate, string $operationUf = '', string $operationCityIbge = ''): array {
    $mode = fiscal_ibs_cbs_rate_mode($tax);
    $classCode = substr(preg_replace('/\D+/', '', (string)($tax['ibs_cbs_classification_code'] ?? '')) ?: '', 0, 6);
    $crt = substr(preg_replace('/\D+/', '', (string)($cfg['crt'] ?? '')) ?: '', 0, 3);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $issueDate, $m) ? $m[0] : date('Y-m-d');
    $year = (int)substr($date, 0, 4);
    $classRow = fiscal_ibs_cbs_classification_direct($pdo, $companyId, $classCode, $date);
    $cstDigits = preg_replace('/\D+/', '', (string)($classRow['cst_code'] ?? '')) ?: '';
    $cst = $cstDigits !== '' ? str_pad(substr($cstDigits, 0, 3), 3, '0', STR_PAD_LEFT) : ($classCode !== '' ? substr($classCode, 0, 3) : '');
    $base = [
      'enabled'=>false,'mode'=>$mode,'class_code'=>$classCode,'cst'=>$cst,'class_row'=>$classRow,
      'reason'=>'','source'=>'','cbs_rate'=>0.0,'ibs_uf_rate'=>0.0,'ibs_mun_rate'=>0.0,
      'ibs_reduction_rate'=>fiscal_ibs_cbs_clamp_percent($classRow['ibs_reduction_rate'] ?? 0),
      'cbs_reduction_rate'=>fiscal_ibs_cbs_clamp_percent($classRow['cbs_reduction_rate'] ?? 0),
      'date'=>$date,'uf'=>'','city_ibge'=>'','crt'=>$crt,
    ];
    if ($mode === 'DESATIVADO') { $base['reason']='IBS/CBS desativado no produto.'; return $base; }
    if ($classCode === '') { $base['reason']='Produto sem cClassTrib IBS/CBS.'; return $base; }
    if (!$classRow) {
      $base['reason']='cClassTrib ' . $classCode . ' não encontrado na tabela de classificações vigente.';
      return $base;
    }
    $classStart = trim((string)($classRow['validity_start'] ?? ''));
    $classEnd = trim((string)($classRow['validity_end'] ?? ''));
    if (($classStart !== '' && $classStart > $date) || ($classEnd !== '' && $classEnd < $date)) {
      $base['reason']='cClassTrib ' . $classCode . ' fora da vigência para ' . $date . '.';
      return $base;
    }
    if ($model === '55' && array_key_exists('can_nfe', $classRow) && (int)$classRow['can_nfe'] !== 1) {
      $base['reason']='cClassTrib ' . $classCode . ' não permitido para NF-e modelo 55.';
      return $base;
    }
    if ($model === '65' && array_key_exists('can_nfce', $classRow) && (int)$classRow['can_nfce'] !== 1) {
      $base['reason']='cClassTrib ' . $classCode . ' não permitido para NFC-e modelo 65.';
      return $base;
    }
    if (!in_array($cst, ['000','200'], true)) {
      $base['reason']='CST IBS/CBS ' . $cst . ' exige grupo especial ainda não automatizado nesta versão.';
      return $base;
    }
    // Não bloquear o destaque de IBS/CBS apenas pelo CRT. A aplicabilidade
    // deve ser determinada pelo cClassTrib/CST vigente, modelo do documento,
    // data de emissão e configuração fiscal. Há NFC-e modelo 65, CRT=1,
    // autorizada em homologação com CST 000 e cClassTrib 000001.
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $operationUf !== '' ? $operationUf : (string)($cfg['uf'] ?? '')), 0, 2));
    $city = substr(preg_replace('/\D+/', '', $operationCityIbge !== '' ? $operationCityIbge : (string)($cfg['city_ibge'] ?? '')) ?: '', 0, 7);
    $base['uf']=$uf; $base['city_ibge']=$city;
    if ($mode === 'MANUAL') {
      $base['cbs_rate']=max(0.0,(float)($tax['cbs_rate'] ?? 0));
      $base['ibs_uf_rate']=max(0.0,(float)($tax['ibs_uf_rate'] ?? 0));
      $base['ibs_mun_rate']=max(0.0,(float)($tax['ibs_mun_rate'] ?? 0));
      $base['source']='PRODUTO_MANUAL';
    } else {
      $cbsRow=fiscal_ibs_cbs_rate_row($pdo,'fiscal_cbs_rates',$companyId,$date);
      $ufRow=fiscal_ibs_cbs_rate_row($pdo,'fiscal_ibs_uf_rates',$companyId,$date,'uf',$uf);
      $munRow=fiscal_ibs_cbs_rate_row($pdo,'fiscal_ibs_mun_rates',$companyId,$date,'ibge_code',$city);
      if ($year === 2026) {
        $base['cbs_rate']=$cbsRow ? max(0.0,(float)$cbsRow['rate']) : 0.9;
        $base['ibs_uf_rate']=$ufRow ? max(0.0,(float)$ufRow['rate']) : 0.1;
        $base['ibs_mun_rate']=$munRow ? max(0.0,(float)$munRow['rate']) : 0.0;
        $base['source']=($cbsRow || $ufRow || $munRow) ? 'TABELA_VIGENCIA_COM_FALLBACK_2026' : 'PADRAO_OFICIAL_2026';
      } else {
        if (!$cbsRow || !$ufRow || !$munRow) {
          $base['reason']='Não há alíquotas completas cadastradas para ' . $date . '. Atualize CBS, IBS UF e IBS Município antes de emitir.';
          return $base;
        }
        $base['cbs_rate']=max(0.0,(float)$cbsRow['rate']);
        $base['ibs_uf_rate']=max(0.0,(float)$ufRow['rate']);
        $base['ibs_mun_rate']=max(0.0,(float)$munRow['rate']);
        $base['source']='TABELA_VIGENCIA';
      }
    }
    $base['enabled']=true;
    return $base;
  }
}

if (!function_exists('fiscal_ibs_cbs_calculate')) {
  function fiscal_ibs_cbs_calculate(float $grossBase, array $resolved, array $excludedTaxes = []): array {
    $excluded = 0.0;
    foreach ($excludedTaxes as $value) $excluded += max(0.0, (float)$value);
    $base = max(0.0, round($grossBase - $excluded, 2));
    $ibsRed = fiscal_ibs_cbs_clamp_percent($resolved['ibs_reduction_rate'] ?? 0);
    $cbsRed = fiscal_ibs_cbs_clamp_percent($resolved['cbs_reduction_rate'] ?? 0);
    $ibsUfStandard=max(0.0,(float)($resolved['ibs_uf_rate'] ?? 0));
    $ibsMunStandard=max(0.0,(float)($resolved['ibs_mun_rate'] ?? 0));
    $cbsStandard=max(0.0,(float)($resolved['cbs_rate'] ?? 0));
    $ibsUfEffective=round($ibsUfStandard*(1-$ibsRed/100),4);
    $ibsMunEffective=round($ibsMunStandard*(1-$ibsRed/100),4);
    $cbsEffective=round($cbsStandard*(1-$cbsRed/100),4);
    $ibsUfTribOp=round($base*$ibsUfStandard/100,2);
    $ibsMunTribOp=round($base*$ibsMunStandard/100,2);
    $cbsTribOp=round($base*$cbsStandard/100,2);
    $ibsUfAmount=round($base*$ibsUfEffective/100,2);
    $ibsMunAmount=round($base*$ibsMunEffective/100,2);
    $cbsAmount=round($base*$cbsEffective/100,2);
    return [
      'base'=>$base,'excluded_total'=>round($excluded,2),
      'ibs_uf_rate'=>$ibsUfStandard,'ibs_uf_effective_rate'=>$ibsUfEffective,'ibs_uf_trib_op'=>$ibsUfTribOp,'ibs_uf_amount'=>$ibsUfAmount,
      'ibs_mun_rate'=>$ibsMunStandard,'ibs_mun_effective_rate'=>$ibsMunEffective,'ibs_mun_trib_op'=>$ibsMunTribOp,'ibs_mun_amount'=>$ibsMunAmount,
      'ibs_amount'=>round($ibsUfAmount+$ibsMunAmount,2),
      'cbs_rate'=>$cbsStandard,'cbs_effective_rate'=>$cbsEffective,'cbs_trib_op'=>$cbsTribOp,'cbs_amount'=>$cbsAmount,
      'ibs_reduction_rate'=>$ibsRed,'cbs_reduction_rate'=>$cbsRed,
    ];
  }
}

if (!function_exists('fiscal_xml_money')) {
  function fiscal_xml_money($value): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace('.', '', $s);
    $s = str_replace(',', '.', $s);
    return is_numeric($s) ? round((float)$s, 4) : 0.0;
  }
}

if (!function_exists('fiscal_xml_date')) {
  function fiscal_xml_date($value): ?string {
    $s = trim((string)$value);
    if ($s === '') return null;
    if (preg_match('/^(\d{4}-\d{2}-\d{2})/', $s, $m)) return $m[1];
    if (preg_match('/^(\d{2})[\/-](\d{2})[\/-](\d{4})$/', $s, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    return null;
  }
}

if (!function_exists('fiscal_date_br')) {
  function fiscal_date_br($value, string $sep = '-'): string {
    $d = fiscal_xml_date($value);
    if (!$d) return '';
    [$y, $m, $day] = explode('-', $d);
    return $day . $sep . $m . $sep . $y;
  }
}

if (!function_exists('fiscal_xml_digits')) {
  function fiscal_xml_digits($value): string {
    return preg_replace('/\D+/', '', (string)$value) ?? '';
  }
}

if (!function_exists('fiscal_xml_text')) {
  function fiscal_xml_text(SimpleXMLElement $node, string $xpath): string {
    try {
      $res = $node->xpath($xpath);
      if (is_array($res) && isset($res[0])) return trim((string)$res[0]);
    } catch (Throwable $e) {}
    return '';
  }
}

if (!function_exists('fiscal_xml_first')) {
  function fiscal_xml_first(SimpleXMLElement $node, string $xpath): ?SimpleXMLElement {
    try {
      $res = $node->xpath($xpath);
      if (is_array($res) && isset($res[0]) && $res[0] instanceof SimpleXMLElement) return $res[0];
    } catch (Throwable $e) {}
    return null;
  }
}

if (!function_exists('fiscal_xml_ensure_schema')) {
  function fiscal_xml_ensure_schema(PDO $pdo): void {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $done = true;
    fiscal_ensure_aux_schema($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_operations (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      code VARCHAR(30) NULL,
      name VARCHAR(160) NOT NULL,
      direction VARCHAR(20) NOT NULL DEFAULT 'ENTRADA',
      operation_type VARCHAR(40) NOT NULL DEFAULT 'COMPRA',
      cfop_in_state VARCHAR(4) NULL,
      cfop_in_interstate VARCHAR(4) NULL,
      cfop_out_state VARCHAR(4) NULL,
      cfop_out_interstate VARCHAR(4) NULL,
      create_finance TINYINT(1) NOT NULL DEFAULT 0,
      finance_movement VARCHAR(20) NOT NULL DEFAULT 'NENHUM',
      chart_account_id INT NULL,
      cost_center_id INT NULL,
      update_stock TINYINT(1) NOT NULL DEFAULT 0,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_operations_company_code (company_id, code),
      KEY idx_fiscal_operations_company_active (company_id, is_active, direction)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $opCols = [
      'code' => 'VARCHAR(30) NULL', 'name' => 'VARCHAR(160) NOT NULL', 'direction' => "VARCHAR(20) NOT NULL DEFAULT 'ENTRADA'",
      'operation_type' => "VARCHAR(40) NOT NULL DEFAULT 'COMPRA'", 'cfop_in_state' => 'VARCHAR(4) NULL', 'cfop_in_interstate' => 'VARCHAR(4) NULL',
      'cfop_out_state' => 'VARCHAR(4) NULL', 'cfop_out_interstate' => 'VARCHAR(4) NULL', 'create_finance' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'finance_movement' => "VARCHAR(20) NOT NULL DEFAULT 'NENHUM'", 'chart_account_id' => 'INT NULL', 'cost_center_id' => 'INT NULL',
      'update_stock' => 'TINYINT(1) NOT NULL DEFAULT 0', 'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($opCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_operations', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_imports (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      access_key VARCHAR(44) NOT NULL,
      model VARCHAR(5) NULL,
      number VARCHAR(20) NULL,
      series VARCHAR(10) NULL,
      issue_date DATE NULL,
      entry_date DATE NULL,
      is_manual TINYINT(1) NOT NULL DEFAULT 0,
      include_fiscal_reports TINYINT(1) NOT NULL DEFAULT 1,
      supplier_doc VARCHAR(30) NULL,
      supplier_name VARCHAR(180) NULL,
      supplier_ie VARCHAR(30) NULL,
      supplier_uf CHAR(2) NULL,
      supplier_city VARCHAR(120) NULL,
      supplier_city_ibge VARCHAR(7) NULL,
      supplier_address VARCHAR(180) NULL,
      supplier_number VARCHAR(40) NULL,
      supplier_district VARCHAR(120) NULL,
      supplier_zip VARCHAR(20) NULL,
      buyer_doc VARCHAR(30) NULL,
      buyer_name VARCHAR(180) NULL,
      total_products DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_invoice DECIMAL(15,2) NOT NULL DEFAULT 0,
      freight_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      other_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      xml_filename VARCHAR(190) NULL,
      xml_hash VARCHAR(64) NULL,
      xml_content MEDIUMTEXT NULL,
      payment_json MEDIUMTEXT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'IMPORTADO',
      operation_id INT NULL,
      finance_payable_ids TEXT NULL,
      generated_at DATETIME NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_xml_company_key (company_id, access_key),
      KEY idx_fiscal_xml_company_date (company_id, issue_date),
      KEY idx_fiscal_xml_company_status (company_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $xmlCols = [
      'model' => 'VARCHAR(5) NULL', 'number' => 'VARCHAR(20) NULL', 'series' => 'VARCHAR(10) NULL', 'issue_date' => 'DATE NULL',
      'entry_date' => 'DATE NULL', 'is_manual' => 'TINYINT(1) NOT NULL DEFAULT 0', 'include_fiscal_reports' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'supplier_doc' => 'VARCHAR(30) NULL', 'supplier_name' => 'VARCHAR(180) NULL', 'supplier_ie' => 'VARCHAR(30) NULL', 'supplier_uf' => 'CHAR(2) NULL',
      'supplier_city' => 'VARCHAR(120) NULL', 'supplier_city_ibge' => 'VARCHAR(7) NULL', 'supplier_address' => 'VARCHAR(180) NULL', 'supplier_number' => 'VARCHAR(40) NULL', 'supplier_district' => 'VARCHAR(120) NULL', 'supplier_zip' => 'VARCHAR(20) NULL',
      'buyer_doc' => 'VARCHAR(30) NULL', 'buyer_name' => 'VARCHAR(180) NULL', 'total_products' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_invoice' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'freight_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'discount_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'other_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'xml_filename' => 'VARCHAR(190) NULL', 'xml_hash' => 'VARCHAR(64) NULL', 'xml_content' => 'MEDIUMTEXT NULL', 'payment_json' => 'MEDIUMTEXT NULL',
      'status' => "VARCHAR(30) NOT NULL DEFAULT 'IMPORTADO'", 'operation_id' => 'INT NULL', 'finance_payable_ids' => 'TEXT NULL', 'generated_at' => 'DATETIME NULL', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($xmlCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_xml_imports', $c, $d);

    if (fiscal_table_exists($pdo, 'produtos') && !fiscal_column_exists($pdo, 'produtos', 'codigo_barras') && !$pdo->inTransaction()) {
      try { fiscal_add_column_if_missing($pdo, 'produtos', 'codigo_barras', 'VARCHAR(80) NULL'); } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_items (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      xml_import_id INT NOT NULL,
      item_number INT NOT NULL DEFAULT 0,
      product_code VARCHAR(80) NULL,
      ean VARCHAR(30) NULL,
      name VARCHAR(220) NOT NULL,
      ncm VARCHAR(12) NULL,
      cest VARCHAR(12) NULL,
      cfop VARCHAR(4) NULL,
      origem VARCHAR(2) NULL,
      icms_cst VARCHAR(5) NULL,
      icms_csosn VARCHAR(5) NULL,
      ipi_cst VARCHAR(5) NULL,
      pis_cst VARCHAR(5) NULL,
      cofins_cst VARCHAR(5) NULL,
      entry_cfop VARCHAR(4) NULL,
      entry_origem VARCHAR(2) NULL,
      entry_icms_cst VARCHAR(5) NULL,
      entry_icms_csosn VARCHAR(5) NULL,
      entry_ipi_cst VARCHAR(5) NULL,
      entry_pis_cst VARCHAR(5) NULL,
      entry_cofins_cst VARCHAR(5) NULL,
      tax_conversion_id INT NULL,
      tax_profile_id INT NULL,
      unit VARCHAR(20) NULL,
      quantity DECIMAL(15,4) NOT NULL DEFAULT 0,
      unit_value DECIMAL(15,4) NOT NULL DEFAULT 0,
      total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      freight_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      insurance_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      other_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      ipi_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      icms_st_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      tax_json MEDIUMTEXT NULL,
      product_id BIGINT NULL,
      conversion_factor DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
      stock_quantity DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
      stock_unit_cost DECIMAL(15,4) NOT NULL DEFAULT 0.0000,
      linked_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_xml_items_xml (company_id, xml_import_id),
      KEY idx_fiscal_xml_items_product (company_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    fiscal_add_column_if_missing($pdo, 'fiscal_xml_items', 'tax_json', 'MEDIUMTEXT NULL');

    $itemCols = [
      'item_number' => 'INT NOT NULL DEFAULT 0', 'product_code' => 'VARCHAR(80) NULL', 'ean' => 'VARCHAR(30) NULL', 'name' => 'VARCHAR(220) NOT NULL',
      'ncm' => 'VARCHAR(12) NULL', 'cest' => 'VARCHAR(12) NULL', 'cfop' => 'VARCHAR(4) NULL',
      'origem' => 'VARCHAR(2) NULL', 'icms_cst' => 'VARCHAR(5) NULL', 'icms_csosn' => 'VARCHAR(5) NULL', 'ipi_cst' => 'VARCHAR(5) NULL', 'pis_cst' => 'VARCHAR(5) NULL', 'cofins_cst' => 'VARCHAR(5) NULL',
      'entry_cfop' => 'VARCHAR(4) NULL', 'entry_origem' => 'VARCHAR(2) NULL', 'entry_icms_cst' => 'VARCHAR(5) NULL', 'entry_icms_csosn' => 'VARCHAR(5) NULL', 'entry_ipi_cst' => 'VARCHAR(5) NULL', 'entry_pis_cst' => 'VARCHAR(5) NULL', 'entry_cofins_cst' => 'VARCHAR(5) NULL',
      'tax_conversion_id' => 'INT NULL', 'tax_profile_id' => 'INT NULL', 'unit' => 'VARCHAR(20) NULL',
      'quantity' => 'DECIMAL(15,4) NOT NULL DEFAULT 0', 'unit_value' => 'DECIMAL(15,4) NOT NULL DEFAULT 0', 'total_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'freight_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'insurance_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'discount_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'other_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'ipi_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'icms_st_value' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'product_id' => 'BIGINT NULL',
      'conversion_factor' => 'DECIMAL(15,6) NOT NULL DEFAULT 1.000000', 'stock_quantity' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'stock_unit_cost' => 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000', 'linked_at' => 'DATETIME NULL'
    ];
    foreach ($itemCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_xml_items', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_tax_conversions (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      name VARCHAR(160) NOT NULL,
      input_cfop VARCHAR(4) NULL,
      input_icms_cst VARCHAR(5) NULL,
      input_icms_csosn VARCHAR(5) NULL,
      input_ipi_cst VARCHAR(5) NULL,
      input_pis_cst VARCHAR(5) NULL,
      input_cofins_cst VARCHAR(5) NULL,
      output_cfop VARCHAR(4) NULL,
      output_icms_cst VARCHAR(5) NULL,
      output_icms_csosn VARCHAR(5) NULL,
      output_ipi_cst VARCHAR(5) NULL,
      output_pis_cst VARCHAR(5) NULL,
      output_cofins_cst VARCHAR(5) NULL,
      tax_profile_id INT NULL,
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_xml_tax_conv_lookup (company_id, is_active, input_cfop),
      KEY idx_fiscal_xml_tax_conv_profile (company_id, tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $convCols = [
      'name' => 'VARCHAR(160) NOT NULL', 'input_cfop' => 'VARCHAR(4) NULL', 'input_icms_cst' => 'VARCHAR(5) NULL', 'input_icms_csosn' => 'VARCHAR(5) NULL',
      'input_ipi_cst' => 'VARCHAR(5) NULL', 'input_pis_cst' => 'VARCHAR(5) NULL', 'input_cofins_cst' => 'VARCHAR(5) NULL',
      'output_cfop' => 'VARCHAR(4) NULL', 'output_icms_cst' => 'VARCHAR(5) NULL', 'output_icms_csosn' => 'VARCHAR(5) NULL',
      'output_ipi_cst' => 'VARCHAR(5) NULL', 'output_pis_cst' => 'VARCHAR(5) NULL', 'output_cofins_cst' => 'VARCHAR(5) NULL',
      'tax_profile_id' => 'INT NULL', 'is_active' => 'TINYINT(1) NOT NULL DEFAULT 1', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($convCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_xml_tax_conversions', $c, $d);

    // Vínculo persistente entre o item recebido no XML do fornecedor e o produto do sistema.
    // O objetivo é reaproveitar automaticamente o mesmo relacionamento nas próximas NF-e.
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_product_links (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      supplier_doc VARCHAR(20) NOT NULL DEFAULT '',
      supplier_product_code VARCHAR(80) NULL,
      supplier_ean VARCHAR(30) NULL,
      product_id BIGINT NOT NULL,
      conversion_factor DECIMAL(15,6) NOT NULL DEFAULT 1.000000,
      last_xml_import_id INT NULL,
      last_xml_item_id INT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_xml_product_link_code (company_id, supplier_doc, supplier_product_code),
      KEY idx_fiscal_xml_product_link_ean (company_id, supplier_doc, supplier_ean),
      KEY idx_fiscal_xml_product_link_product (company_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    fiscal_seed_default_operations($pdo, defined('COMPANY_ID') ? (int)COMPANY_ID : 0);
  }
}

if (!function_exists('fiscal_seed_default_operations')) {
  function fiscal_seed_default_operations(PDO $pdo, int $companyId): void {
    if ($companyId <= 0) return;
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM fiscal_operations WHERE company_id = ?');
      $st->execute([$companyId]);
      if ((int)$st->fetchColumn() > 0) return;
      $ins = $pdo->prepare("INSERT INTO fiscal_operations (company_id, code, name, direction, operation_type, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, create_finance, finance_movement, update_stock, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $rows = [
        ['COMPRA', 'Compra / entrada de mercadoria', 'ENTRADA', 'COMPRA', '1102', '2102', '', '', 1, 'PAGAR', 1, 1, 'Operação padrão para gerar entrada por XML e contas a pagar.'],
        ['VENDA', 'Venda de mercadoria', 'SAIDA', 'VENDA', '', '', '5102', '6102', 1, 'RECEBER', 1, 1, 'Operação padrão de saída/venda com baixa de estoque no PDV.'],
        ['DEV_CLIENTE', 'Devolução de cliente', 'ENTRADA', 'DEVOLUCAO_CLIENTE', '1202', '2202', '', '', 0, 'NENHUM', 1, 1, 'Entrada originada de devolução do cliente.'],
        ['DEV_FORNECEDOR', 'Devolução para fornecedor', 'SAIDA', 'DEVOLUCAO_FORNECEDOR', '', '', '5202', '6202', 0, 'NENHUM', 1, 1, 'Saída originada de devolução para fornecedor.'],
      ];
      foreach ($rows as $r) $ins->execute(array_merge([$companyId], $r));
    } catch (Throwable $e) {}
  }
}

if (!function_exists('fiscal_operations')) {
  function fiscal_operations(PDO $pdo, int $companyId, string $direction = '', bool $activeOnly = true): array {
    fiscal_xml_ensure_schema($pdo);
    $where = ['company_id = ?'];
    $params = [$companyId];
    if ($direction !== '') { $where[] = 'direction = ?'; $params[] = strtoupper($direction); }
    if ($activeOnly) $where[] = 'is_active = 1';
    $st = $pdo->prepare('SELECT * FROM fiscal_operations WHERE ' . implode(' AND ', $where) . ' ORDER BY is_active DESC, direction ASC, name ASC, id ASC');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_operation')) {
  function fiscal_operation(PDO $pdo, int $companyId, int $id): ?array {
    fiscal_xml_ensure_schema($pdo);
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM fiscal_operations WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}


if (!function_exists('fiscal_ensure_operation_cfop_schema')) {
  function fiscal_ensure_operation_cfop_schema(PDO $pdo): void {
    static $done = false;
    if ($done || $pdo->inTransaction()) return;
    $done = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_tax_profile_operation_cfops (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      tax_profile_id INT NOT NULL,
      operation_id INT NOT NULL,
      cfop_in_state VARCHAR(4) NULL,
      cfop_in_interstate VARCHAR(4) NULL,
      cfop_out_state VARCHAR(4) NULL,
      cfop_out_interstate VARCHAR(4) NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 1,
      notes VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_profile_op_cfop (company_id, tax_profile_id, operation_id),
      KEY idx_fiscal_profile_op_lookup (company_id, tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_product_operation_cfops (
      id INT NOT NULL AUTO_INCREMENT,
      company_id BIGINT NOT NULL,
      product_id BIGINT NOT NULL,
      tax_profile_id INT NULL,
      operation_id INT NOT NULL,
      cfop_in_state VARCHAR(4) NULL,
      cfop_in_interstate VARCHAR(4) NULL,
      cfop_out_state VARCHAR(4) NULL,
      cfop_out_interstate VARCHAR(4) NULL,
      source_profile_id INT NULL,
      inherited TINYINT(1) NOT NULL DEFAULT 1,
      notes VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_product_op_cfop (company_id, product_id, operation_id),
      KEY idx_fiscal_product_op_lookup (company_id, product_id),
      KEY idx_fiscal_product_op_profile (company_id, tax_profile_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
      'cfop_in_state' => 'VARCHAR(4) NULL', 'cfop_in_interstate' => 'VARCHAR(4) NULL',
      'cfop_out_state' => 'VARCHAR(4) NULL', 'cfop_out_interstate' => 'VARCHAR(4) NULL',
      'enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'notes' => 'VARCHAR(255) NULL', 'updated_at' => 'DATETIME NULL'
    ] as $c => $d) {
      fiscal_add_column_if_missing($pdo, 'fiscal_tax_profile_operation_cfops', $c, $d);
    }
    foreach ([
      'tax_profile_id' => 'INT NULL', 'cfop_in_state' => 'VARCHAR(4) NULL', 'cfop_in_interstate' => 'VARCHAR(4) NULL',
      'cfop_out_state' => 'VARCHAR(4) NULL', 'cfop_out_interstate' => 'VARCHAR(4) NULL', 'source_profile_id' => 'INT NULL',
      'inherited' => 'TINYINT(1) NOT NULL DEFAULT 1', 'notes' => 'VARCHAR(255) NULL', 'updated_at' => 'DATETIME NULL'
    ] as $c => $d) {
      fiscal_add_column_if_missing($pdo, 'fiscal_product_operation_cfops', $c, $d);
    }
  }
}

if (!function_exists('fiscal_cfop_digits')) {
  function fiscal_cfop_digits($value): string {
    $v = preg_replace('/\D+/', '', (string)$value);
    return substr($v, 0, 4);
  }
}

if (!function_exists('fiscal_operation_cfop_base_from_operation')) {
  function fiscal_operation_cfop_base_from_operation(array $op): array {
    return [
      'operation_id' => (int)($op['id'] ?? 0),
      'operation_code' => (string)($op['code'] ?? ''),
      'operation_name' => (string)($op['name'] ?? ''),
      'direction' => (string)($op['direction'] ?? ''),
      'operation_type' => (string)($op['operation_type'] ?? ''),
      'cfop_in_state' => (string)($op['cfop_in_state'] ?? ''),
      'cfop_in_interstate' => (string)($op['cfop_in_interstate'] ?? ''),
      'cfop_out_state' => (string)($op['cfop_out_state'] ?? ''),
      'cfop_out_interstate' => (string)($op['cfop_out_interstate'] ?? ''),
      'notes' => '',
      'enabled' => 0,
      'inherited' => 1,
    ];
  }
}

if (!function_exists('fiscal_tax_profile_operation_cfops')) {
  function fiscal_tax_profile_operation_cfops(PDO $pdo, int $companyId, int $taxProfileId, bool $includeAllOperations = true): array {
    fiscal_xml_ensure_schema($pdo);
    fiscal_ensure_operation_cfop_schema($pdo);
    $out = [];
    if ($includeAllOperations) {
      foreach (fiscal_operations($pdo, $companyId, '', true) as $op) {
        $out[(int)$op['id']] = fiscal_operation_cfop_base_from_operation($op);
      }
    }
    if ($taxProfileId > 0) {
      $sql = 'SELECT t.*, o.code AS operation_code, o.name AS operation_name, o.direction, o.operation_type,
          o.cfop_in_state AS op_cfop_in_state, o.cfop_in_interstate AS op_cfop_in_interstate,
          o.cfop_out_state AS op_cfop_out_state, o.cfop_out_interstate AS op_cfop_out_interstate
        FROM fiscal_tax_profile_operation_cfops t
        INNER JOIN fiscal_operations o ON o.company_id = t.company_id AND o.id = t.operation_id
        WHERE t.company_id = ? AND t.tax_profile_id = ?';
      if (!$includeAllOperations) $sql .= ' AND t.enabled = 1';
      $sql .= ' ORDER BY o.direction ASC, o.name ASC, o.id ASC';
      $st = $pdo->prepare($sql);
      $st->execute([$companyId, $taxProfileId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $opId = (int)$r['operation_id'];
        $out[$opId] = array_merge($out[$opId] ?? [], [
          'operation_id' => $opId,
          'operation_code' => (string)($r['operation_code'] ?? ''),
          'operation_name' => (string)($r['operation_name'] ?? ''),
          'direction' => (string)($r['direction'] ?? ''),
          'operation_type' => (string)($r['operation_type'] ?? ''),
          'cfop_in_state' => (string)($r['cfop_in_state'] ?? ''),
          'cfop_in_interstate' => (string)($r['cfop_in_interstate'] ?? ''),
          'cfop_out_state' => (string)($r['cfop_out_state'] ?? ''),
          'cfop_out_interstate' => (string)($r['cfop_out_interstate'] ?? ''),
          'notes' => (string)($r['notes'] ?? ''),
          'enabled' => (int)($r['enabled'] ?? 1),
          'inherited' => 1,
        ]);
      }
    }
    return array_values($out);
  }
}

if (!function_exists('fiscal_save_tax_profile_operation_cfops_from_post')) {
  function fiscal_save_tax_profile_operation_cfops_from_post(PDO $pdo, int $companyId, int $taxProfileId, array $post): void {
    fiscal_ensure_operation_cfop_schema($pdo);
    if ($companyId <= 0 || $taxProfileId <= 0) return;

    $enabledMap = (array)($post['profile_operation_enabled'] ?? []);
    $presentMap = (array)($post['profile_operation_present'] ?? []);
    $cfopInState = (array)($post['profile_cfop_in_state'] ?? []);
    $cfopInInter = (array)($post['profile_cfop_in_interstate'] ?? []);
    $cfopOutState = (array)($post['profile_cfop_out_state'] ?? []);
    $cfopOutInter = (array)($post['profile_cfop_out_interstate'] ?? []);
    $notes = (array)($post['profile_operation_notes'] ?? []);

    // Compatibilidade com formulários anteriores: se o marcador de presença não veio,
    // usa a união dos índices dos demais campos para não perder informações digitadas.
    $opIds = [];
    foreach ([$presentMap, $enabledMap, $cfopInState, $cfopInInter, $cfopOutState, $cfopOutInter, $notes] as $source) {
      foreach (array_keys($source) as $key) {
        $opId = (int)$key;
        if ($opId > 0) $opIds[$opId] = $opId;
      }
    }
    if (!$opIds) return;
    ksort($opIds, SORT_NUMERIC);

    $ins = $pdo->prepare('INSERT INTO fiscal_tax_profile_operation_cfops
      (company_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, enabled, notes, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
      ON DUPLICATE KEY UPDATE
        cfop_in_state = VALUES(cfop_in_state),
        cfop_in_interstate = VALUES(cfop_in_interstate),
        cfop_out_state = VALUES(cfop_out_state),
        cfop_out_interstate = VALUES(cfop_out_interstate),
        enabled = VALUES(enabled),
        notes = VALUES(notes),
        updated_at = NOW()');

    foreach ($opIds as $opId) {
      $ins->execute([
        $companyId,
        $taxProfileId,
        $opId,
        fiscal_cfop_digits($cfopInState[$opId] ?? ''),
        fiscal_cfop_digits($cfopInInter[$opId] ?? ''),
        fiscal_cfop_digits($cfopOutState[$opId] ?? ''),
        fiscal_cfop_digits($cfopOutInter[$opId] ?? ''),
        !empty($enabledMap[$opId]) ? 1 : 0,
        trim((string)($notes[$opId] ?? '')) ?: null,
      ]);
    }

    // Remove registros antigos de operações que não são mais exibidas no formulário.
    $ids = array_values($opIds);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $del = $pdo->prepare('DELETE FROM fiscal_tax_profile_operation_cfops WHERE company_id = ? AND tax_profile_id = ? AND operation_id NOT IN (' . $ph . ')');
    $del->execute(array_merge([$companyId, $taxProfileId], $ids));
  }
}

if (!function_exists('fiscal_sync_profile_operation_cfops_to_products')) {
  function fiscal_sync_profile_operation_cfops_to_products(PDO $pdo, int $companyId, int $taxProfileId): int {
    fiscal_ensure_operation_cfop_schema($pdo);
    if ($companyId <= 0 || $taxProfileId <= 0) return 0;
    $maps = fiscal_tax_profile_operation_cfops($pdo, $companyId, $taxProfileId, false);
    $productSt = $pdo->prepare('SELECT product_id FROM fiscal_product_tax WHERE company_id = ? AND tax_profile_id = ?');
    $productSt->execute([$companyId, $taxProfileId]);
    $products = array_values(array_filter(array_map('intval', $productSt->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn($v) => $v > 0));
    if (!$products) return 0;

    // Ao desmarcar uma operação na tributação, remove somente o vínculo herdado.
    // Personalizações feitas diretamente no produto (inherited = 0) são preservadas.
    $enabledOpIds = array_values(array_filter(array_map(static fn($m) => (int)($m['operation_id'] ?? 0), $maps), static fn($v) => $v > 0));
    $productPh = implode(',', array_fill(0, count($products), '?'));
    $params = array_merge([$companyId, $taxProfileId], $products);
    $sqlDelete = 'DELETE FROM fiscal_product_operation_cfops
      WHERE company_id = ? AND source_profile_id = ? AND inherited = 1 AND product_id IN (' . $productPh . ')';
    if ($enabledOpIds) {
      $opPh = implode(',', array_fill(0, count($enabledOpIds), '?'));
      $sqlDelete .= ' AND operation_id NOT IN (' . $opPh . ')';
      $params = array_merge($params, $enabledOpIds);
    }
    $pdo->prepare($sqlDelete)->execute($params);

    if (!$maps) return 0;
    $ins = $pdo->prepare('INSERT INTO fiscal_product_operation_cfops
      (company_id, product_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, source_profile_id, inherited, notes, updated_at)
      VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW())
      ON DUPLICATE KEY UPDATE
        tax_profile_id = IF(inherited = 1, VALUES(tax_profile_id), tax_profile_id),
        cfop_in_state = IF(inherited = 1, VALUES(cfop_in_state), cfop_in_state),
        cfop_in_interstate = IF(inherited = 1, VALUES(cfop_in_interstate), cfop_in_interstate),
        cfop_out_state = IF(inherited = 1, VALUES(cfop_out_state), cfop_out_state),
        cfop_out_interstate = IF(inherited = 1, VALUES(cfop_out_interstate), cfop_out_interstate),
        source_profile_id = IF(inherited = 1, VALUES(source_profile_id), source_profile_id),
        notes = IF(inherited = 1, VALUES(notes), notes),
        updated_at = NOW()');
    $count = 0;
    foreach ($products as $productId) {
      foreach ($maps as $m) {
        $opId = (int)($m['operation_id'] ?? 0);
        if ($opId <= 0) continue;
        $ins->execute([$companyId, $productId, $taxProfileId, $opId, $m['cfop_in_state'] ?? null, $m['cfop_in_interstate'] ?? null, $m['cfop_out_state'] ?? null, $m['cfop_out_interstate'] ?? null, $taxProfileId, $m['notes'] ?? null]);
        $count++;
      }
    }
    return $count;
  }
}

if (!function_exists('fiscal_product_operation_cfops')) {
  function fiscal_product_operation_cfops(PDO $pdo, int $companyId, int $productId, int $taxProfileId = 0, bool $includeAllOperations = true): array {
    fiscal_xml_ensure_schema($pdo);
    fiscal_ensure_operation_cfop_schema($pdo);
    $out = [];
    if ($includeAllOperations) {
      foreach (fiscal_operations($pdo, $companyId, '', true) as $op) {
        $out[(int)$op['id']] = fiscal_operation_cfop_base_from_operation($op);
      }
    }
    if ($taxProfileId > 0) {
      foreach (fiscal_tax_profile_operation_cfops($pdo, $companyId, $taxProfileId, false) as $r) {
        $opId = (int)$r['operation_id'];
        $out[$opId] = array_merge($out[$opId] ?? [], $r, ['enabled' => 1, 'inherited' => 1]);
      }
    }
    if ($productId > 0) {
      $st = $pdo->prepare('SELECT p.*, o.code AS operation_code, o.name AS operation_name, o.direction, o.operation_type
        FROM fiscal_product_operation_cfops p
        INNER JOIN fiscal_operations o ON o.company_id = p.company_id AND o.id = p.operation_id
        WHERE p.company_id = ? AND p.product_id = ?
        ORDER BY o.direction ASC, o.name ASC, o.id ASC');
      $st->execute([$companyId, $productId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $opId = (int)$r['operation_id'];
        $out[$opId] = array_merge($out[$opId] ?? [], [
          'operation_id' => $opId,
          'operation_code' => (string)($r['operation_code'] ?? ''),
          'operation_name' => (string)($r['operation_name'] ?? ''),
          'direction' => (string)($r['direction'] ?? ''),
          'operation_type' => (string)($r['operation_type'] ?? ''),
          'cfop_in_state' => (string)($r['cfop_in_state'] ?? ''),
          'cfop_in_interstate' => (string)($r['cfop_in_interstate'] ?? ''),
          'cfop_out_state' => (string)($r['cfop_out_state'] ?? ''),
          'cfop_out_interstate' => (string)($r['cfop_out_interstate'] ?? ''),
          'notes' => (string)($r['notes'] ?? ''),
          'enabled' => 1,
          'inherited' => (int)($r['inherited'] ?? 1),
        ]);
      }
    }
    return array_values($out);
  }
}

if (!function_exists('fiscal_save_product_operation_cfops_from_post')) {
  function fiscal_save_product_operation_cfops_from_post(PDO $pdo, int $companyId, int $productId, int $taxProfileId, array $post): void {
    fiscal_ensure_operation_cfop_schema($pdo);
    if ($companyId <= 0 || $productId <= 0) return;
    $enabled = array_map('intval', array_keys((array)($post['product_operation_enabled'] ?? [])));
    $cfopInState = (array)($post['product_cfop_in_state'] ?? []);
    $cfopInInter = (array)($post['product_cfop_in_interstate'] ?? []);
    $cfopOutState = (array)($post['product_cfop_out_state'] ?? []);
    $cfopOutInter = (array)($post['product_cfop_out_interstate'] ?? []);
    $notes = (array)($post['product_operation_notes'] ?? []);
    $custom = (array)($post['product_operation_custom'] ?? []);

    $pdo->prepare('DELETE FROM fiscal_product_operation_cfops WHERE company_id = ? AND product_id = ?')->execute([$companyId, $productId]);
    if (!$enabled) return;
    $ins = $pdo->prepare('INSERT INTO fiscal_product_operation_cfops (company_id, product_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, source_profile_id, inherited, notes, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())');
    foreach ($enabled as $opId) {
      if ($opId <= 0) continue;
      $isCustom = !empty($custom[$opId]);
      $ins->execute([
        $companyId,
        $productId,
        $taxProfileId > 0 ? $taxProfileId : null,
        $opId,
        fiscal_cfop_digits($cfopInState[$opId] ?? ''),
        fiscal_cfop_digits($cfopInInter[$opId] ?? ''),
        fiscal_cfop_digits($cfopOutState[$opId] ?? ''),
        fiscal_cfop_digits($cfopOutInter[$opId] ?? ''),
        $isCustom ? null : ($taxProfileId > 0 ? $taxProfileId : null),
        $isCustom ? 0 : 1,
        trim((string)($notes[$opId] ?? '')) ?: null,
      ]);
    }
  }
}

if (!function_exists('fiscal_default_entry_operation')) {
  function fiscal_default_entry_operation(PDO $pdo, int $companyId): ?array {
    $ops = fiscal_operations($pdo, $companyId, 'ENTRADA', true);
    foreach ($ops as $op) if (($op['operation_type'] ?? '') === 'COMPRA') return $op;
    return $ops[0] ?? null;
  }
}

if (!function_exists('fiscal_default_sale_operation')) {
  function fiscal_default_sale_operation(PDO $pdo, int $companyId): ?array {
    fiscal_xml_ensure_schema($pdo);
    if ($companyId <= 0) return null;
    try {
      $ensure = $pdo->prepare("INSERT INTO fiscal_operations (company_id, code, name, direction, operation_type, cfop_out_state, cfop_out_interstate, create_finance, finance_movement, update_stock, is_active, notes) VALUES (?, 'VENDA', 'Venda de mercadoria', 'SAIDA', 'VENDA', '5102', '6102', 1, 'RECEBER', 1, 1, 'Operação padrão de venda usada pelo PDV para baixar estoque.') ON DUPLICATE KEY UPDATE direction=VALUES(direction), operation_type=VALUES(operation_type), update_stock=1, is_active=1, updated_at=NOW()");
      $ensure->execute([$companyId]);
      $st = $pdo->prepare("SELECT * FROM fiscal_operations WHERE company_id = ? AND code = 'VENDA' LIMIT 1");
      $st->execute([$companyId]);
      $op = $st->fetch(PDO::FETCH_ASSOC);
      if ($op) return $op;
      $fallback = $pdo->prepare("SELECT * FROM fiscal_operations WHERE company_id = ? AND direction = 'SAIDA' AND operation_type = 'VENDA' AND is_active = 1 ORDER BY id ASC LIMIT 1");
      $fallback->execute([$companyId]);
      $op = $fallback->fetch(PDO::FETCH_ASSOC);
      return $op ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('fiscal_xml_parse_file')) {
  function fiscal_xml_parse_file(string $tmpPath, string $filename = ''): array {
    if (!is_file($tmpPath)) throw new RuntimeException('Arquivo XML não encontrado.');
    $raw = file_get_contents($tmpPath);
    if ($raw === false || trim($raw) === '') throw new RuntimeException('Arquivo XML vazio.');

    $block = static function(string $source, string $tag): string {
      $name = preg_quote($tag, '/');
      if (preg_match('/<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?' . $name . ')\b[^>]*>(.*?)<\/\1>/is', $source, $m)) return (string)$m[2];
      return '';
    };
    $text = static function(string $source, string $tag) use ($block): string {
      $b = $block($source, $tag);
      if ($b === '') return '';
      $b = preg_replace('/<!\[CDATA\[(.*?)\]\]>/s', '$1', $b) ?? $b;
      $b = strip_tags($b);
      return trim(html_entity_decode($b, ENT_QUOTES | ENT_XML1, 'UTF-8'));
    };
    $attr = static function(string $source, string $tag, string $attrName): string {
      $name = preg_quote($tag, '/');
      $attrQ = preg_quote($attrName, '/');
      if (preg_match('/<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?' . $name . ')\b[^>]*\b' . $attrQ . '\s*=\s*(["\'])(.*?)\2[^>]*>/is', $source, $m)) return trim((string)$m[3]);
      return '';
    };
    $allBlocks = static function(string $source, string $tag): array {
      $name = preg_quote($tag, '/');
      if (!preg_match_all('/<((?:[A-Za-z_][A-Za-z0-9_.-]*:)?' . $name . ')\b([^>]*)>(.*?)<\/\1>/is', $source, $m, PREG_SET_ORDER)) return [];
      return $m;
    };
    $attrFromOpen = static function(string $openAttrs, string $attrName): string {
      $attrQ = preg_quote($attrName, '/');
      if (preg_match('/\b' . $attrQ . '\s*=\s*(["\'])(.*?)\1/is', $openAttrs, $m)) return trim((string)$m[2]);
      return '';
    };

    $infBlock = $block($raw, 'infNFe');
    if ($infBlock === '') throw new RuntimeException('Não encontrei a tag infNFe. Envie um XML de NF-e autorizado.');
    $idAttr = $attr($raw, 'infNFe', 'Id');
    $accessKey = fiscal_xml_digits(str_replace('NFe', '', $idAttr));
    if ($accessKey === '') $accessKey = fiscal_xml_digits($text($raw, 'chNFe'));
    if (strlen($accessKey) !== 44) throw new RuntimeException('Não foi possível identificar a chave de acesso da NF-e.');

    $ide = $block($infBlock, 'ide');
    $emit = $block($infBlock, 'emit');
    $dest = $block($infBlock, 'dest');
    $ender = $block($emit, 'enderEmit');
    $total = $block($block($infBlock, 'total'), 'ICMSTot');

    $items = [];
    foreach ($allBlocks($infBlock, 'det') as $detMatch) {
      $detAttrs = (string)($detMatch[2] ?? '');
      $detBlock = (string)($detMatch[3] ?? '');
      $prod = $block($detBlock, 'prod');
      if ($prod === '') continue;
      $imposto = $block($detBlock, 'imposto');
      $icms = $block($imposto, 'ICMS');
      $ipi = $block($imposto, 'IPI');
      $pis = $block($imposto, 'PIS');
      $cofins = $block($imposto, 'COFINS');
      $items[] = [
        'item_number' => (int)($attrFromOpen($detAttrs, 'nItem') ?: (count($items) + 1)),
        'product_code' => $text($prod, 'cProd'),
        'ean' => $text($prod, 'cEAN'),
        'name' => $text($prod, 'xProd'),
        'ncm' => fiscal_xml_digits($text($prod, 'NCM')),
        'cest' => fiscal_xml_digits($text($prod, 'CEST')),
        'cfop' => fiscal_xml_digits($text($prod, 'CFOP')),
        'origem' => fiscal_xml_tax_code($text($icms, 'orig'), 2),
        'icms_cst' => fiscal_xml_tax_code($text($icms, 'CST'), 5),
        'icms_csosn' => fiscal_xml_tax_code($text($icms, 'CSOSN'), 5),
        'ipi_cst' => fiscal_xml_tax_code($text($ipi, 'CST'), 5),
        'pis_cst' => fiscal_xml_tax_code($text($pis, 'CST'), 5),
        'cofins_cst' => fiscal_xml_tax_code($text($cofins, 'CST'), 5),
        'unit' => $text($prod, 'uCom'),
        'quantity' => fiscal_xml_money($text($prod, 'qCom')),
        'unit_value' => fiscal_xml_money($text($prod, 'vUnCom')),
        'total_value' => fiscal_xml_money($text($prod, 'vProd')),
        'freight_value' => fiscal_xml_money($text($prod, 'vFrete')),
        'insurance_value' => fiscal_xml_money($text($prod, 'vSeg')),
        'discount_value' => fiscal_xml_money($text($prod, 'vDesc')),
        'other_value' => fiscal_xml_money($text($prod, 'vOutro')),
        'ipi_value' => fiscal_xml_money($text($ipi, 'vIPI')),
        'icms_st_value' => fiscal_xml_money($text($icms, 'vICMSST')),
        'tax_json' => json_encode([
          'icms_origin' => fiscal_xml_tax_code($text($icms, 'orig'), 2),
          'icms_cst' => fiscal_xml_tax_code($text($icms, 'CST'), 5),
          'icms_csosn' => fiscal_xml_tax_code($text($icms, 'CSOSN'), 5),
          'icms_base' => fiscal_xml_money($text($icms, 'vBC')),
          'icms_rate' => fiscal_xml_money($text($icms, 'pICMS')),
          'icms_amount' => fiscal_xml_money($text($icms, 'vICMS')),
          'icms_st_base' => fiscal_xml_money($text($icms, 'vBCST')),
          'icms_st_rate' => fiscal_xml_money($text($icms, 'pICMSST')),
          'icms_st_amount' => fiscal_xml_money($text($icms, 'vICMSST')),
          'icms_st_mva' => fiscal_xml_money($text($icms, 'pMVAST')),
          'icms_st_reduction' => fiscal_xml_money($text($icms, 'pRedBCST')),
          'fcp_st_base' => fiscal_xml_money($text($icms, 'vBCFCPST')),
          'fcp_st_rate' => fiscal_xml_money($text($icms, 'pFCPST')),
          'fcp_st_amount' => fiscal_xml_money($text($icms, 'vFCPST')),
          'ipi_cst' => fiscal_xml_tax_code($text($ipi, 'CST'), 5),
          'ipi_base' => fiscal_xml_money($text($ipi, 'vBC')),
          'ipi_rate' => fiscal_xml_money($text($ipi, 'pIPI')),
          'ipi_amount' => fiscal_xml_money($text($ipi, 'vIPI')),
          'pis_cst' => fiscal_xml_tax_code($text($pis, 'CST'), 5),
          'pis_base' => fiscal_xml_money($text($pis, 'vBC')),
          'pis_rate' => fiscal_xml_money($text($pis, 'pPIS')),
          'pis_amount' => fiscal_xml_money($text($pis, 'vPIS')),
          'cofins_cst' => fiscal_xml_tax_code($text($cofins, 'CST'), 5),
          'cofins_base' => fiscal_xml_money($text($cofins, 'vBC')),
          'cofins_rate' => fiscal_xml_money($text($cofins, 'pCOFINS')),
          'cofins_amount' => fiscal_xml_money($text($cofins, 'vCOFINS')),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      ];
    }

    $installments = [];
    $cobr = $block($infBlock, 'cobr');
    foreach ($allBlocks($cobr, 'dup') as $dupMatch) {
      $dup = (string)($dupMatch[3] ?? '');
      $value = fiscal_xml_money($text($dup, 'vDup'));
      if ($value <= 0) continue;
      $installments[] = [
        'number' => $text($dup, 'nDup') ?: str_pad((string)(count($installments) + 1), 3, '0', STR_PAD_LEFT),
        'due_date' => fiscal_xml_date($text($dup, 'dVenc')),
        'amount' => round($value, 2),
      ];
    }

    $issueDate = fiscal_xml_date($text($ide, 'dhEmi')) ?: fiscal_xml_date($text($ide, 'dEmi'));
    $invoiceTotal = fiscal_xml_money($text($total, 'vNF'));
    if (!$installments && $invoiceTotal > 0) {
      $installments[] = ['number' => '001', 'due_date' => $issueDate ?: date('Y-m-d'), 'amount' => round($invoiceTotal, 2)];
    }

    return [
      'access_key' => $accessKey,
      'model' => $text($ide, 'mod'),
      'number' => $text($ide, 'nNF'),
      'series' => $text($ide, 'serie'),
      'issue_date' => $issueDate,
      'supplier_doc' => fiscal_xml_digits($text($emit, 'CNPJ') ?: $text($emit, 'CPF')),
      'supplier_name' => $text($emit, 'xNome'),
      'supplier_ie' => $text($emit, 'IE'),
      'supplier_uf' => strtoupper(substr($text($ender, 'UF'), 0, 2)),
      'supplier_city' => $text($ender, 'xMun'),
      'supplier_city_ibge' => fiscal_xml_digits($text($ender, 'cMun')),
      'supplier_address' => $text($ender, 'xLgr'),
      'supplier_number' => $text($ender, 'nro'),
      'supplier_district' => $text($ender, 'xBairro'),
      'supplier_zip' => fiscal_xml_digits($text($ender, 'CEP')),
      'buyer_doc' => fiscal_xml_digits($text($dest, 'CNPJ') ?: $text($dest, 'CPF')),
      'buyer_name' => $text($dest, 'xNome'),
      'total_products' => round(fiscal_xml_money($text($total, 'vProd')), 2),
      'total_invoice' => round($invoiceTotal, 2),
      'freight_value' => round(fiscal_xml_money($text($total, 'vFrete')), 2),
      'discount_value' => round(fiscal_xml_money($text($total, 'vDesc')), 2),
      'other_value' => round(fiscal_xml_money($text($total, 'vOutro')), 2),
      'xml_filename' => $filename,
      'xml_hash' => hash('sha256', $raw),
      'xml_content' => $raw,
      'installments' => $installments,
      'items' => $items,
    ];
  }
}

if (!function_exists('fiscal_xml_import_data')) {
  function fiscal_xml_import_data(PDO $pdo, int $companyId, array $data): int {
    fiscal_xml_ensure_schema($pdo);
    $paymentJson = json_encode($data['installments'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $issueDate = fiscal_xml_date($data['issue_date'] ?? '') ?: null;
    $includeReports = trim((string)($data['number'] ?? '')) === '0' ? 0 : 1;
    $sql = "INSERT INTO fiscal_xml_imports
      (company_id, access_key, model, number, series, issue_date, entry_date, is_manual, include_fiscal_reports, supplier_doc, supplier_name, supplier_ie, supplier_uf, supplier_city, supplier_city_ibge, supplier_address, supplier_number, supplier_district, supplier_zip, buyer_doc, buyer_name, total_products, total_invoice, freight_value, discount_value, other_value, xml_filename, xml_hash, xml_content, payment_json, status, updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'IMPORTADO',NOW())
      ON DUPLICATE KEY UPDATE
        model=VALUES(model), number=VALUES(number), series=VALUES(series), issue_date=VALUES(issue_date), entry_date=COALESCE(entry_date, VALUES(entry_date)), is_manual=0, include_fiscal_reports=VALUES(include_fiscal_reports), supplier_doc=VALUES(supplier_doc), supplier_name=VALUES(supplier_name), supplier_ie=VALUES(supplier_ie), supplier_uf=VALUES(supplier_uf), supplier_city=VALUES(supplier_city), supplier_city_ibge=VALUES(supplier_city_ibge), supplier_address=VALUES(supplier_address), supplier_number=VALUES(supplier_number), supplier_district=VALUES(supplier_district), supplier_zip=VALUES(supplier_zip), buyer_doc=VALUES(buyer_doc), buyer_name=VALUES(buyer_name), total_products=VALUES(total_products), total_invoice=VALUES(total_invoice), freight_value=VALUES(freight_value), discount_value=VALUES(discount_value), other_value=VALUES(other_value), xml_filename=VALUES(xml_filename), xml_hash=VALUES(xml_hash), xml_content=VALUES(xml_content), payment_json=VALUES(payment_json), status=IF(status='ENTRADA_GERADA', status, VALUES(status)), updated_at=NOW()";
    $params = [
      $companyId, $data['access_key'], $data['model'] ?? '', $data['number'] ?? '', $data['series'] ?? '', $issueDate, $issueDate, 0, $includeReports,
      $data['supplier_doc'] ?? '', $data['supplier_name'] ?? '', $data['supplier_ie'] ?? '', $data['supplier_uf'] ?? '', $data['supplier_city'] ?? '', $data['supplier_city_ibge'] ?? '', $data['supplier_address'] ?? '', $data['supplier_number'] ?? '', $data['supplier_district'] ?? '', $data['supplier_zip'] ?? '',
      $data['buyer_doc'] ?? '', $data['buyer_name'] ?? '', (float)($data['total_products'] ?? 0), (float)($data['total_invoice'] ?? 0), (float)($data['freight_value'] ?? 0), (float)($data['discount_value'] ?? 0), (float)($data['other_value'] ?? 0),
      $data['xml_filename'] ?? '', $data['xml_hash'] ?? '', $data['xml_content'] ?? '', $paymentJson,
    ];
    $pdo->prepare($sql)->execute($params);
    $st = $pdo->prepare('SELECT id FROM fiscal_xml_imports WHERE company_id = ? AND access_key = ? LIMIT 1');
    $st->execute([$companyId, $data['access_key']]);
    $xmlId = (int)$st->fetchColumn();
    if ($xmlId <= 0) throw new RuntimeException('Não foi possível gravar o XML.');

    // Antes de reimportar a mesma chave, preserva vínculos antigos na tabela persistente.
    try {
      $oldLinks = $pdo->prepare('SELECT id, product_id, product_code, ean, conversion_factor FROM fiscal_xml_items WHERE company_id=? AND xml_import_id=? AND product_id IS NOT NULL AND product_id>0');
      $oldLinks->execute([$companyId, $xmlId]);
      foreach (($oldLinks->fetchAll(PDO::FETCH_ASSOC) ?: []) as $oldLink) {
        fiscal_xml_save_product_link($pdo, $companyId, (string)($data['supplier_doc'] ?? ''), (string)($oldLink['product_code'] ?? ''), (string)($oldLink['ean'] ?? ''), (int)$oldLink['product_id'], fiscal_xml_parse_decimal($oldLink['conversion_factor'] ?? 1, 1.0), $xmlId, (int)$oldLink['id']);
      }
    } catch (Throwable $e) {}

    $pdo->prepare('DELETE FROM fiscal_xml_items WHERE company_id = ? AND xml_import_id = ?')->execute([$companyId, $xmlId]);
    $itemSql = 'INSERT INTO fiscal_xml_items (company_id, xml_import_id, item_number, product_code, ean, name, ncm, cest, cfop, origem, icms_cst, icms_csosn, ipi_cst, pis_cst, cofins_cst, entry_cfop, entry_origem, entry_icms_cst, entry_icms_csosn, entry_ipi_cst, entry_pis_cst, entry_cofins_cst, tax_conversion_id, tax_profile_id, unit, quantity, unit_value, total_value, freight_value, insurance_value, discount_value, other_value, ipi_value, icms_st_value, tax_json, product_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
    $itemSt = $pdo->prepare($itemSql);
    foreach ((array)($data['items'] ?? []) as $it) {
      $taxPayload = fiscal_xml_item_tax_payload($pdo, $companyId, (array)$it);
      $savedLink = fiscal_xml_find_saved_product_link(
        $pdo,
        $companyId,
        (string)($data['supplier_doc'] ?? ''),
        (string)($it['product_code'] ?? ''),
        (string)($it['ean'] ?? '')
      );
      $resolvedProductId = !empty($savedLink['product_id']) ? (int)$savedLink['product_id'] : (!empty($it['product_id']) ? (int)$it['product_id'] : 0);
      $resolvedFactor = $savedLink ? fiscal_xml_parse_decimal($savedLink['conversion_factor'] ?? 1, 1.0) : 1.0;
      if ($resolvedFactor <= 0) $resolvedFactor = 1.0;
      $itemSt->execute([
        $companyId, $xmlId, (int)($it['item_number'] ?? 0), (string)($it['product_code'] ?? ''), (string)($it['ean'] ?? ''), (string)($it['name'] ?? ''), (string)($it['ncm'] ?? ''), (string)($it['cest'] ?? ''), fiscal_cfop_digits($it['cfop'] ?? ''),
        fiscal_xml_tax_code($it['origem'] ?? '', 2), fiscal_xml_tax_code($it['icms_cst'] ?? ''), fiscal_xml_tax_code($it['icms_csosn'] ?? ''), fiscal_xml_tax_code($it['ipi_cst'] ?? ''), fiscal_xml_tax_code($it['pis_cst'] ?? ''), fiscal_xml_tax_code($it['cofins_cst'] ?? ''),
        $taxPayload['entry_cfop'] ?: null, $taxPayload['entry_origem'] ?: null, $taxPayload['entry_icms_cst'] ?: null, $taxPayload['entry_icms_csosn'] ?: null, $taxPayload['entry_ipi_cst'] ?: null, $taxPayload['entry_pis_cst'] ?: null, $taxPayload['entry_cofins_cst'] ?: null,
        !empty($taxPayload['tax_conversion_id']) ? (int)$taxPayload['tax_conversion_id'] : null, !empty($taxPayload['tax_profile_id']) ? (int)$taxPayload['tax_profile_id'] : null, (string)($it['unit'] ?? ''),
        (float)($it['quantity'] ?? 0), (float)($it['unit_value'] ?? 0), (float)($it['total_value'] ?? 0),
        (float)($it['freight_value'] ?? 0), (float)($it['insurance_value'] ?? 0), (float)($it['discount_value'] ?? 0), (float)($it['other_value'] ?? 0), (float)($it['ipi_value'] ?? 0), (float)($it['icms_st_value'] ?? 0), (string)($it['tax_json'] ?? ''),
        $resolvedProductId > 0 ? $resolvedProductId : null,
      ]);
      $newItemId = (int)$pdo->lastInsertId();
      if ($savedLink && $resolvedProductId > 0 && $newItemId > 0) {
        $stockQty = (float)($it['quantity'] ?? 0) * $resolvedFactor;
        $stockUnitCost = $stockQty > 0 ? round(fiscal_xml_item_landed_base_value((array)$it) / $stockQty, 4) : 0.0;
        $pdo->prepare('UPDATE fiscal_xml_items SET conversion_factor=?, stock_quantity=?, stock_unit_cost=?, linked_at=NOW() WHERE company_id=? AND xml_import_id=? AND id=?')
          ->execute([$resolvedFactor, $stockQty, $stockUnitCost, $companyId, $xmlId, $newItemId]);
        try {
          fiscal_xml_save_product_link($pdo, $companyId, (string)($data['supplier_doc'] ?? ''), (string)($it['product_code'] ?? ''), (string)($it['ean'] ?? ''), $resolvedProductId, $resolvedFactor, $xmlId, $newItemId);
        } catch (Throwable $e) {}
      }
    }
    return $xmlId;
  }
}

if (!function_exists('fiscal_xml_import_file')) {
  function fiscal_xml_import_file(PDO $pdo, int $companyId, string $tmpPath, string $filename): array {
    $data = fiscal_xml_parse_file($tmpPath, $filename);
    $id = fiscal_xml_import_data($pdo, $companyId, $data);
    return ['ok' => true, 'id' => $id, 'access_key' => $data['access_key'], 'number' => $data['number'] ?? '', 'supplier_name' => $data['supplier_name'] ?? '', 'total_invoice' => $data['total_invoice'] ?? 0];
  }
}

if (!function_exists('fiscal_xml_get')) {
  function fiscal_xml_get(PDO $pdo, int $companyId, int $id): ?array {
    fiscal_xml_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT x.*, o.name AS operation_name FROM fiscal_xml_imports x LEFT JOIN fiscal_operations o ON o.id=x.operation_id AND o.company_id=x.company_id WHERE x.company_id = ? AND x.id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}


if (!function_exists('fiscal_xml_update_dates')) {
  function fiscal_xml_update_dates(PDO $pdo, int $companyId, int $xmlId, ?string $issueDate, ?string $entryDate): void {
    fiscal_xml_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota não encontrada.');
    $entryDate = $entryDate ?: $issueDate;
    $pdo->prepare('UPDATE fiscal_xml_imports SET issue_date = ?, entry_date = ?, updated_at = NOW() WHERE company_id = ? AND id = ?')
      ->execute([$issueDate, $entryDate, $companyId, $xmlId]);
  }
}

if (!function_exists('fiscal_xml_manual_key')) {
  function fiscal_xml_manual_key(int $companyId): string {
    return 'MANUAL' . substr(sha1($companyId . '|' . microtime(true) . '|' . random_int(1000, 999999)), 0, 38);
  }
}

if (!function_exists('fiscal_xml_create_manual_entry')) {
  function fiscal_xml_create_manual_entry(PDO $pdo, int $companyId, array $post): int {
    fiscal_xml_ensure_schema($pdo);
    $number = trim((string)($post['number'] ?? ''));
    if ($number === '') throw new RuntimeException('Informe o número da nota/pedido.');
    $series = trim((string)($post['series'] ?? '')) ?: 'M';
    $issueDate = fiscal_xml_date($post['issue_date'] ?? '') ?: date('Y-m-d');
    $entryDate = fiscal_xml_date($post['entry_date'] ?? '') ?: $issueDate;
    $supplierName = trim((string)($post['supplier_name'] ?? ''));
    if ($supplierName === '') throw new RuntimeException('Selecione ou cadastre o fornecedor.');
    $supplierDoc = fiscal_xml_digits($post['supplier_doc'] ?? '');
    $supplierIe = trim((string)($post['supplier_ie'] ?? ''));
    $supplierUf = strtoupper(substr(trim((string)($post['supplier_uf'] ?? '')), 0, 2));
    $supplierCity = trim((string)($post['supplier_city'] ?? ''));
    $supplierCityIbge = fiscal_xml_digits($post['supplier_city_ibge'] ?? '');
    $supplierAddress = trim((string)($post['supplier_address'] ?? ''));
    $supplierNumber = trim((string)($post['supplier_number'] ?? ''));
    $supplierDistrict = trim((string)($post['supplier_district'] ?? ''));
    $supplierZip = fiscal_xml_digits($post['supplier_zip'] ?? '');
    $notes = trim((string)($post['notes'] ?? ''));

    $names=(array)($post['item_name']??[]); $productIds=(array)($post['item_product_id']??[]); $codes=(array)($post['item_code']??[]);
    $ncm=(array)($post['item_ncm']??[]); $cest=(array)($post['item_cest']??[]); $cfop=(array)($post['item_cfop']??[]); $origem=(array)($post['item_origem']??[]);
    $icmsCst=(array)($post['item_icms_cst']??[]); $icmsCsosn=(array)($post['item_icms_csosn']??[]); $ipiCst=(array)($post['item_ipi_cst']??[]); $pisCst=(array)($post['item_pis_cst']??[]); $cofinsCst=(array)($post['item_cofins_cst']??[]);
    $icmsBase=(array)($post['item_icms_base']??[]); $icmsRate=(array)($post['item_icms_rate']??[]); $icmsAmount=(array)($post['item_icms_amount']??[]);
    $stBase=(array)($post['item_icms_st_base']??[]); $stRate=(array)($post['item_icms_st_rate']??[]); $stAmount=(array)($post['item_icms_st_amount']??[]);
    $ipiBase=(array)($post['item_ipi_base']??[]); $ipiRate=(array)($post['item_ipi_rate']??[]); $ipiAmount=(array)($post['item_ipi_amount']??[]);
    $pisBase=(array)($post['item_pis_base']??[]); $pisRate=(array)($post['item_pis_rate']??[]); $pisAmount=(array)($post['item_pis_amount']??[]);
    $cofinsBase=(array)($post['item_cofins_base']??[]); $cofinsRate=(array)($post['item_cofins_rate']??[]); $cofinsAmount=(array)($post['item_cofins_amount']??[]);
    $qtys=(array)($post['item_quantity']??[]); $units=(array)($post['item_unit']??[]); $unitValues=(array)($post['item_unit_value']??[]);
    $items=[]; $total=0.0;
    foreach($names as $idx=>$rawName){
      $name=trim((string)$rawName); if($name==='') continue;
      $qty=fiscal_xml_money($qtys[$idx]??0); if($qty<=0)$qty=1;
      $unitValue=fiscal_xml_money($unitValues[$idx]??0); $lineTotal=round($qty*$unitValue,2); $total+=$lineTotal;
      $tax=[
        'icms_origin'=>fiscal_xml_tax_code($origem[$idx]??'',2),'icms_cst'=>fiscal_xml_tax_code($icmsCst[$idx]??''),'icms_csosn'=>fiscal_xml_tax_code($icmsCsosn[$idx]??''),
        'icms_base'=>round(max(0,fiscal_xml_parse_decimal($icmsBase[$idx]??0)),2),'icms_rate'=>round(max(0,fiscal_xml_parse_decimal($icmsRate[$idx]??0)),4),'icms_amount'=>round(max(0,fiscal_xml_parse_decimal($icmsAmount[$idx]??0)),2),
        'icms_st_base'=>round(max(0,fiscal_xml_parse_decimal($stBase[$idx]??0)),2),'icms_st_rate'=>round(max(0,fiscal_xml_parse_decimal($stRate[$idx]??0)),4),'icms_st_amount'=>round(max(0,fiscal_xml_parse_decimal($stAmount[$idx]??0)),2),
        'ipi_cst'=>fiscal_xml_tax_code($ipiCst[$idx]??''),'ipi_base'=>round(max(0,fiscal_xml_parse_decimal($ipiBase[$idx]??0)),2),'ipi_rate'=>round(max(0,fiscal_xml_parse_decimal($ipiRate[$idx]??0)),4),'ipi_amount'=>round(max(0,fiscal_xml_parse_decimal($ipiAmount[$idx]??0)),2),
        'pis_cst'=>fiscal_xml_tax_code($pisCst[$idx]??''),'pis_base'=>round(max(0,fiscal_xml_parse_decimal($pisBase[$idx]??0)),2),'pis_rate'=>round(max(0,fiscal_xml_parse_decimal($pisRate[$idx]??0)),4),'pis_amount'=>round(max(0,fiscal_xml_parse_decimal($pisAmount[$idx]??0)),2),
        'cofins_cst'=>fiscal_xml_tax_code($cofinsCst[$idx]??''),'cofins_base'=>round(max(0,fiscal_xml_parse_decimal($cofinsBase[$idx]??0)),2),'cofins_rate'=>round(max(0,fiscal_xml_parse_decimal($cofinsRate[$idx]??0)),4),'cofins_amount'=>round(max(0,fiscal_xml_parse_decimal($cofinsAmount[$idx]??0)),2),
      ];
      $items[]=['item_number'=>count($items)+1,'product_id'=>max(0,(int)($productIds[$idx]??0)),'product_code'=>trim((string)($codes[$idx]??'')),'name'=>$name,'ncm'=>fiscal_xml_digits($ncm[$idx]??''),'cest'=>fiscal_xml_digits($cest[$idx]??''),'cfop'=>fiscal_cfop_digits($cfop[$idx]??''),'origem'=>$tax['icms_origin'],'icms_cst'=>$tax['icms_cst'],'icms_csosn'=>$tax['icms_csosn'],'ipi_cst'=>$tax['ipi_cst'],'pis_cst'=>$tax['pis_cst'],'cofins_cst'=>$tax['cofins_cst'],'unit'=>trim((string)($units[$idx]??'UN'))?:'UN','quantity'=>$qty,'unit_value'=>$unitValue,'total_value'=>$lineTotal,'tax'=>$tax];
    }
    if(!$items) throw new RuntimeException('Informe pelo menos um item para o lançamento manual.');

    $installmentNumbers=(array)($post['installment_number']??[]); $installmentDates=(array)($post['installment_due_date']??[]); $installmentAmounts=(array)($post['installment_amount']??[]); $payments=[]; $paymentsTotal=0.0;
    foreach($installmentAmounts as $idx=>$rawAmount){
      $amount=round(max(0,fiscal_xml_money($rawAmount)),2); if($amount<=0) continue;
      $due=fiscal_xml_date($installmentDates[$idx]??'')?:$entryDate; $num=trim((string)($installmentNumbers[$idx]??'')); if($num==='')$num=str_pad((string)(count($payments)+1),3,'0',STR_PAD_LEFT);
      $payments[]=['number'=>$num,'due_date'=>$due,'amount'=>$amount]; $paymentsTotal+=$amount;
    }
    if(!$payments) $payments=[['number'=>'001','due_date'=>$entryDate,'amount'=>$total]];
    elseif(abs($paymentsTotal-$total)>0.02) throw new RuntimeException('O total das parcelas (R$ '.number_format($paymentsTotal,2,',','.').') precisa fechar com o total dos itens (R$ '.number_format($total,2,',','.').').');

    $includeReports=$number==='0'?0:1;
    $pdo->beginTransaction();
    try{
      $ins=$pdo->prepare("INSERT INTO fiscal_xml_imports (company_id,access_key,model,number,series,issue_date,entry_date,is_manual,include_fiscal_reports,supplier_doc,supplier_name,supplier_ie,supplier_uf,supplier_city,supplier_city_ibge,supplier_address,supplier_number,supplier_district,supplier_zip,total_products,total_invoice,status,notes,xml_filename,payment_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
      $ins->execute([$companyId,fiscal_xml_manual_key($companyId),'MANUAL',$number,$series,$issueDate,$entryDate,1,$includeReports,$supplierDoc?:null,$supplierName,$supplierIe?:null,$supplierUf?:null,$supplierCity?:null,$supplierCityIbge?:null,$supplierAddress?:null,$supplierNumber?:null,$supplierDistrict?:null,$supplierZip?:null,$total,$total,'IMPORTADO',$notes!==''?$notes:'Lançamento manual criado no módulo fiscal.','manual',json_encode($payments,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
      $id=(int)$pdo->lastInsertId();
      $itemIns=$pdo->prepare('INSERT INTO fiscal_xml_items (company_id,xml_import_id,item_number,product_id,product_code,name,ncm,cest,cfop,origem,icms_cst,icms_csosn,ipi_cst,pis_cst,cofins_cst,entry_cfop,entry_origem,entry_icms_cst,entry_icms_csosn,entry_ipi_cst,entry_pis_cst,entry_cofins_cst,unit,quantity,unit_value,total_value,conversion_factor,stock_quantity,stock_unit_cost,ipi_value,icms_st_value,tax_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      foreach($items as $it){
        $stockQty=(float)$it['quantity']; $tax=$it['tax']; $manualCostTotal=(float)$it['total_value']+(float)($tax['ipi_amount']??0)+(float)($tax['icms_st_amount']??0)+(float)($tax['fcp_st_amount']??0); $stockCost=$stockQty>0?round(max(0,$manualCostTotal)/$stockQty,4):0;
        $itemIns->execute([$companyId,$id,$it['item_number'],$it['product_id']>0?$it['product_id']:null,$it['product_code']?:null,$it['name'],$it['ncm']?:null,$it['cest']?:null,$it['cfop']?:null,$it['origem']?:null,$it['icms_cst']?:null,$it['icms_csosn']?:null,$it['ipi_cst']?:null,$it['pis_cst']?:null,$it['cofins_cst']?:null,$it['cfop']?:null,$it['origem']?:null,$it['icms_cst']?:null,$it['icms_csosn']?:null,$it['ipi_cst']?:null,$it['pis_cst']?:null,$it['cofins_cst']?:null,$it['unit'],$it['quantity'],$it['unit_value'],$it['total_value'],1,$stockQty,$stockCost,(float)($tax['ipi_amount']??0),(float)($tax['icms_st_amount']??0),json_encode($tax,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)]);
      }
      $pdo->commit(); return $id;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('fiscal_xml_items')) {
  function fiscal_xml_items(PDO $pdo, int $companyId, int $xmlId): array {
    fiscal_xml_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT xi.*, p.nome AS product_name FROM fiscal_xml_items xi LEFT JOIN produtos p ON p.id = xi.product_id AND p.company_id = xi.company_id WHERE xi.company_id = ? AND xi.xml_import_id = ? ORDER BY xi.item_number ASC, xi.id ASC');
    $st->execute([$companyId, $xmlId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}


if (!function_exists('fiscal_xml_gestao_enabled')) {
  function fiscal_xml_gestao_enabled(PDO $pdo, int $companyId): bool {
    try {
      require_once __DIR__ . '/saas_modules.php';
      return function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'gestao');
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('fiscal_xml_ensure_product_cost_column')) {
  function fiscal_xml_ensure_product_cost_column(PDO $pdo): bool {
    if (fiscal_column_exists($pdo, 'produtos', 'custo')) return true;
    if ($pdo->inTransaction()) return false;
    try { fiscal_add_column_if_missing($pdo, 'produtos', 'custo', 'DECIMAL(15,4) NOT NULL DEFAULT 0.0000'); } catch (Throwable $e) {}
    return fiscal_column_exists($pdo, 'produtos', 'custo');
  }
}

if (!function_exists('fiscal_xml_parse_decimal')) {
  function fiscal_xml_parse_decimal($value, float $default = 0.0): float {
    $value = trim((string)$value);
    if ($value === '') return $default;
    $value = str_replace(['R$', '%', ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
    return is_numeric($value) ? (float)$value : $default;
  }
}

if (!function_exists('fiscal_xml_products_options')) {
  function fiscal_xml_products_options(PDO $pdo, int $companyId, string $q = '', int $limit = 800): array {
    $limit = max(10, min(2000, $limit));
    $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
    $hasStock = fiscal_column_exists($pdo, 'produtos', 'estoque_atual');
    $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasReference = fiscal_column_exists($pdo, 'produtos', 'referencia');
    $hasTax = fiscal_table_exists($pdo, 'fiscal_product_tax');
    $barcodeCols = array_values(array_filter(['codigo_barras','ean','gtin','barcode'], static fn($c) => fiscal_column_exists($pdo, 'produtos', $c)));
    $select = 'p.id, p.nome, p.categoria, p.preco';
    if ($hasCode) $select .= ', p.codigo_firebird';
    if ($hasReference) $select .= ', p.referencia';
    if ($hasCost) $select .= ', p.custo';
    if ($hasStock) $select .= ', p.estoque_atual, p.unidade_estoque';
    foreach ($barcodeCols as $bc) $select .= ', p.`' . str_replace('`', '``', $bc) . '`';
    if ($hasTax) $select .= ', pt.ncm, pt.cest, pt.pis_cst, pt.cofins_cst, pt.icms_cst, pt.icms_csosn';
    $where = ['p.company_id = ?'];
    $params = [$companyId];
    $q = trim($q);
    if ($q !== '') {
      $searchParts = ['p.nome LIKE ?', 'p.categoria LIKE ?', 'CAST(p.id AS CHAR) LIKE ?'];
      $like = nexa_search_like($q);
      $params[] = $like; $params[] = $like; $params[] = $like;
      if ($hasCode) { $searchParts[] = 'p.codigo_firebird LIKE ?'; $params[] = $like; }
      if ($hasReference) { $searchParts[] = 'p.referencia LIKE ?'; $params[] = $like; }
      foreach ($barcodeCols as $bc) { $searchParts[] = 'p.`' . str_replace('`', '``', $bc) . '` LIKE ?'; $params[] = $like; }
      $where[] = '(' . implode(' OR ', $searchParts) . ')';
    }
    $join = $hasTax ? ' LEFT JOIN fiscal_product_tax pt ON pt.company_id = p.company_id AND pt.product_id = p.id' : '';
    $sql = 'SELECT ' . $select . ' FROM produtos p' . $join . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY p.nome ASC LIMIT ' . $limit;
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}



if (!function_exists('fiscal_xml_product_link_key')) {
  function fiscal_xml_product_link_key($value, int $max = 80): string {
    $v = trim((string)$value);
    if ($v === '') return '';
    $v = preg_replace('/\s+/u', ' ', $v) ?? $v;
    return mb_substr($v, 0, $max);
  }
}

if (!function_exists('fiscal_xml_find_saved_product_link')) {
  function fiscal_xml_find_saved_product_link(PDO $pdo, int $companyId, string $supplierDoc, string $supplierProductCode, string $ean): ?array {
    fiscal_xml_ensure_schema($pdo);
    $supplierDoc = fiscal_xml_digits($supplierDoc);
    $supplierProductCode = fiscal_xml_product_link_key($supplierProductCode, 80);
    $ean = fiscal_xml_digits($ean);
    if ($supplierProductCode === '' && $ean === '') return null;

    $base = 'SELECT l.*, p.nome AS product_name FROM fiscal_xml_product_links l INNER JOIN produtos p ON p.company_id=l.company_id AND p.id=l.product_id WHERE l.company_id=? AND l.supplier_doc=?';
    if ($supplierProductCode !== '') {
      $st = $pdo->prepare($base . " AND COALESCE(l.supplier_product_code,'')=? ORDER BY COALESCE(l.updated_at,l.created_at) DESC, l.id DESC LIMIT 1");
      $st->execute([$companyId, $supplierDoc, $supplierProductCode]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
    }
    if ($ean !== '') {
      $st = $pdo->prepare($base . " AND COALESCE(l.supplier_ean,'')=? ORDER BY COALESCE(l.updated_at,l.created_at) DESC, l.id DESC LIMIT 1");
      $st->execute([$companyId, $supplierDoc, $ean]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return $row;
    }

    // Compatibilidade com vínculos feitos antes da criação da tabela persistente.
    // Ao encontrar um relacionamento histórico válido, ele passa a ser reutilizado automaticamente.
    $where = [];
    $params = [$companyId, $supplierDoc];
    if ($supplierProductCode !== '') { $where[] = "COALESCE(xi.product_code,'')=?"; $params[] = $supplierProductCode; }
    if ($ean !== '') { $where[] = "COALESCE(xi.ean,'')=?"; $params[] = $ean; }
    if ($where) {
      $sql = "SELECT xi.product_id, xi.conversion_factor, xi.xml_import_id, xi.id AS xml_item_id, p.nome AS product_name
              FROM fiscal_xml_items xi
              INNER JOIN fiscal_xml_imports x ON x.company_id=xi.company_id AND x.id=xi.xml_import_id
              INNER JOIN produtos p ON p.company_id=xi.company_id AND p.id=xi.product_id
              WHERE xi.company_id=? AND COALESCE(x.supplier_doc,'')=? AND xi.product_id IS NOT NULL AND xi.product_id>0
                AND (" . implode(' OR ', $where) . ")
              ORDER BY COALESCE(xi.linked_at,xi.created_at) DESC, xi.id DESC LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute($params);
      $hist = $st->fetch(PDO::FETCH_ASSOC);
      if ($hist) {
        $factor = fiscal_xml_parse_decimal($hist['conversion_factor'] ?? 1, 1.0);
        if ($factor <= 0) $factor = 1.0;
        try {
          fiscal_xml_save_product_link($pdo, $companyId, $supplierDoc, $supplierProductCode, $ean, (int)$hist['product_id'], $factor, (int)($hist['xml_import_id'] ?? 0), (int)($hist['xml_item_id'] ?? 0));
        } catch (Throwable $e) {}
        return [
          'id' => 0,
          'company_id' => $companyId,
          'supplier_doc' => $supplierDoc,
          'supplier_product_code' => $supplierProductCode,
          'supplier_ean' => $ean,
          'product_id' => (int)$hist['product_id'],
          'conversion_factor' => $factor,
          'product_name' => (string)($hist['product_name'] ?? ''),
          'source' => 'history',
        ];
      }
    }
    return null;
  }
}

if (!function_exists('fiscal_xml_save_product_link')) {
  function fiscal_xml_save_product_link(PDO $pdo, int $companyId, string $supplierDoc, string $supplierProductCode, string $ean, int $productId, float $factor = 1.0, int $xmlImportId = 0, int $xmlItemId = 0): int {
    fiscal_xml_ensure_schema($pdo);
    if ($companyId <= 0 || $productId <= 0) return 0;
    $supplierDoc = fiscal_xml_digits($supplierDoc);
    $supplierProductCode = fiscal_xml_product_link_key($supplierProductCode, 80);
    $ean = fiscal_xml_digits($ean);
    if ($supplierProductCode === '' && $ean === '') return 0;
    if ($factor <= 0) $factor = 1.0;

    $checkProduct = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $checkProduct->execute([$companyId, $productId]);
    if (!(int)$checkProduct->fetchColumn()) return 0;

    $id = 0;
    if ($supplierProductCode !== '') {
      $st = $pdo->prepare("SELECT id FROM fiscal_xml_product_links WHERE company_id=? AND supplier_doc=? AND COALESCE(supplier_product_code,'')=? ORDER BY id DESC LIMIT 1");
      $st->execute([$companyId, $supplierDoc, $supplierProductCode]);
      $id = (int)$st->fetchColumn();
    }
    if ($id <= 0 && $ean !== '') {
      $st = $pdo->prepare("SELECT id FROM fiscal_xml_product_links WHERE company_id=? AND supplier_doc=? AND COALESCE(supplier_ean,'')=? ORDER BY id DESC LIMIT 1");
      $st->execute([$companyId, $supplierDoc, $ean]);
      $id = (int)$st->fetchColumn();
    }

    if ($id > 0) {
      $pdo->prepare('UPDATE fiscal_xml_product_links SET supplier_product_code=?, supplier_ean=?, product_id=?, conversion_factor=?, last_xml_import_id=?, last_xml_item_id=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$supplierProductCode !== '' ? $supplierProductCode : null, $ean !== '' ? $ean : null, $productId, $factor, $xmlImportId > 0 ? $xmlImportId : null, $xmlItemId > 0 ? $xmlItemId : null, $companyId, $id]);
      return $id;
    }

    $pdo->prepare('INSERT INTO fiscal_xml_product_links (company_id,supplier_doc,supplier_product_code,supplier_ean,product_id,conversion_factor,last_xml_import_id,last_xml_item_id,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW())')
      ->execute([$companyId, $supplierDoc, $supplierProductCode !== '' ? $supplierProductCode : null, $ean !== '' ? $ean : null, $productId, $factor, $xmlImportId > 0 ? $xmlImportId : null, $xmlItemId > 0 ? $xmlItemId : null]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('fiscal_xml_tax_code')) {
  function fiscal_xml_tax_code($value, int $max = 5): string {
    $v = strtoupper(trim((string)$value));
    $v = preg_replace('/[^0-9A-Z]/', '', $v) ?? '';
    return $max > 0 ? substr($v, 0, $max) : $v;
  }
}

if (!function_exists('fiscal_xml_tax_conversion_blank')) {
  function fiscal_xml_tax_conversion_blank(): array {
    return [
      'id' => 0, 'name' => '', 'input_cfop' => '', 'input_icms_cst' => '', 'input_icms_csosn' => '', 'input_ipi_cst' => '', 'input_pis_cst' => '', 'input_cofins_cst' => '',
      'output_cfop' => '', 'output_icms_cst' => '', 'output_icms_csosn' => '', 'output_ipi_cst' => '', 'output_pis_cst' => '', 'output_cofins_cst' => '',
      'tax_profile_id' => '', 'is_active' => 1, 'notes' => ''
    ];
  }
}

if (!function_exists('fiscal_xml_tax_conversions')) {
  function fiscal_xml_tax_conversions(PDO $pdo, int $companyId, bool $activeOnly = false): array {
    fiscal_xml_ensure_schema($pdo);
    $sql = 'SELECT c.*, p.code AS tax_profile_code, p.name AS tax_profile_name FROM fiscal_xml_tax_conversions c LEFT JOIN fiscal_tax_profiles p ON p.company_id = c.company_id AND p.id = c.tax_profile_id WHERE c.company_id = ?';
    if ($activeOnly) $sql .= ' AND c.is_active = 1';
    $sql .= ' ORDER BY c.is_active DESC, c.name ASC, c.id DESC';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_xml_tax_conversion')) {
  function fiscal_xml_tax_conversion(PDO $pdo, int $companyId, int $id): ?array {
    fiscal_xml_ensure_schema($pdo);
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM fiscal_xml_tax_conversions WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('fiscal_xml_save_tax_conversion_from_post')) {
  function fiscal_xml_save_tax_conversion_from_post(PDO $pdo, int $companyId, array $post): int {
    fiscal_xml_ensure_schema($pdo);
    $id = (int)($post['id'] ?? 0);
    $name = trim((string)($post['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome da regra de conversão.');
    $taxProfileId = (int)($post['tax_profile_id'] ?? 0);
    if ($taxProfileId > 0 && !fiscal_tax_profile($pdo, $companyId, $taxProfileId)) $taxProfileId = 0;
    $fields = [
      'name' => $name,
      'input_cfop' => fiscal_cfop_digits($post['input_cfop'] ?? ''),
      'input_icms_cst' => fiscal_xml_tax_code($post['input_icms_cst'] ?? ''),
      'input_icms_csosn' => fiscal_xml_tax_code($post['input_icms_csosn'] ?? ''),
      'input_ipi_cst' => fiscal_xml_tax_code($post['input_ipi_cst'] ?? ''),
      'input_pis_cst' => fiscal_xml_tax_code($post['input_pis_cst'] ?? ''),
      'input_cofins_cst' => fiscal_xml_tax_code($post['input_cofins_cst'] ?? ''),
      'output_cfop' => fiscal_cfop_digits($post['output_cfop'] ?? ''),
      'output_icms_cst' => fiscal_xml_tax_code($post['output_icms_cst'] ?? ''),
      'output_icms_csosn' => fiscal_xml_tax_code($post['output_icms_csosn'] ?? ''),
      'output_ipi_cst' => fiscal_xml_tax_code($post['output_ipi_cst'] ?? ''),
      'output_pis_cst' => fiscal_xml_tax_code($post['output_pis_cst'] ?? ''),
      'output_cofins_cst' => fiscal_xml_tax_code($post['output_cofins_cst'] ?? ''),
      'tax_profile_id' => $taxProfileId > 0 ? $taxProfileId : null,
      'is_active' => isset($post['is_active']) ? 1 : 0,
      'notes' => trim((string)($post['notes'] ?? '')) ?: null,
    ];
    if ($id > 0) {
      $set = [];
      $vals = [];
      foreach ($fields as $c => $v) { $set[] = "`{$c}` = ?"; $vals[] = $v; }
      $vals[] = $companyId; $vals[] = $id;
      $pdo->prepare('UPDATE fiscal_xml_tax_conversions SET ' . implode(', ', $set) . ', updated_at = NOW() WHERE company_id = ? AND id = ?')->execute($vals);
      return $id;
    }
    $cols = array_keys($fields);
    $ph = implode(',', array_fill(0, count($cols) + 1, '?'));
    $pdo->prepare('INSERT INTO fiscal_xml_tax_conversions (company_id, `' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')')->execute(array_merge([$companyId], array_values($fields)));
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('fiscal_xml_match_tax_conversion')) {
  function fiscal_xml_match_tax_conversion(PDO $pdo, int $companyId, array $item): ?array {
    fiscal_xml_ensure_schema($pdo);
    $cfop = fiscal_cfop_digits($item['cfop'] ?? '');
    $icmsCst = fiscal_xml_tax_code($item['icms_cst'] ?? '');
    $icmsCsosn = fiscal_xml_tax_code($item['icms_csosn'] ?? '');
    $ipiCst = fiscal_xml_tax_code($item['ipi_cst'] ?? '');
    $pisCst = fiscal_xml_tax_code($item['pis_cst'] ?? '');
    $cofinsCst = fiscal_xml_tax_code($item['cofins_cst'] ?? '');
    $sql = "SELECT *,
      ((input_cfop IS NOT NULL AND input_cfop <> '') + (input_icms_cst IS NOT NULL AND input_icms_cst <> '') + (input_icms_csosn IS NOT NULL AND input_icms_csosn <> '') + (input_ipi_cst IS NOT NULL AND input_ipi_cst <> '') + (input_pis_cst IS NOT NULL AND input_pis_cst <> '') + (input_cofins_cst IS NOT NULL AND input_cofins_cst <> '')) AS specificity
      FROM fiscal_xml_tax_conversions
      WHERE company_id = ? AND is_active = 1
        AND (input_cfop IS NULL OR input_cfop = '' OR input_cfop = ?)
        AND (input_icms_cst IS NULL OR input_icms_cst = '' OR input_icms_cst = ?)
        AND (input_icms_csosn IS NULL OR input_icms_csosn = '' OR input_icms_csosn = ?)
        AND (input_ipi_cst IS NULL OR input_ipi_cst = '' OR input_ipi_cst = ?)
        AND (input_pis_cst IS NULL OR input_pis_cst = '' OR input_pis_cst = ?)
        AND (input_cofins_cst IS NULL OR input_cofins_cst = '' OR input_cofins_cst = ?)
      ORDER BY specificity DESC, id ASC LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $cfop, $icmsCst, $icmsCsosn, $ipiCst, $pisCst, $cofinsCst]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r || (int)($r['specificity'] ?? 0) <= 0) return null;
    return $r;
  }
}

if (!function_exists('fiscal_entry_auto_tax_conversion')) {
  function fiscal_entry_auto_tax_conversion(PDO $pdo, int $companyId, array $sourceItem, array $entryTax): int {
    fiscal_xml_ensure_schema($pdo);
    try {
      $match = fiscal_xml_match_tax_conversion($pdo, $companyId, $sourceItem);
      if ($match && (int)($match['id'] ?? 0) > 0) return (int)$match['id'];
      $input = [
        'input_cfop' => fiscal_cfop_digits($sourceItem['cfop'] ?? ''),
        'input_icms_cst' => fiscal_xml_tax_code($sourceItem['icms_cst'] ?? ''),
        'input_icms_csosn' => fiscal_xml_tax_code($sourceItem['icms_csosn'] ?? ''),
        'input_ipi_cst' => fiscal_xml_tax_code($sourceItem['ipi_cst'] ?? ''),
        'input_pis_cst' => fiscal_xml_tax_code($sourceItem['pis_cst'] ?? ''),
        'input_cofins_cst' => fiscal_xml_tax_code($sourceItem['cofins_cst'] ?? ''),
      ];
      $output = [
        'output_cfop' => fiscal_cfop_digits($entryTax['entry_cfop'] ?? ''),
        'output_icms_cst' => fiscal_xml_tax_code($entryTax['entry_icms_cst'] ?? ''),
        'output_icms_csosn' => fiscal_xml_tax_code($entryTax['entry_icms_csosn'] ?? ''),
        'output_ipi_cst' => fiscal_xml_tax_code($entryTax['entry_ipi_cst'] ?? ''),
        'output_pis_cst' => fiscal_xml_tax_code($entryTax['entry_pis_cst'] ?? ''),
        'output_cofins_cst' => fiscal_xml_tax_code($entryTax['entry_cofins_cst'] ?? ''),
      ];
      if (!array_filter($output, static fn($v) => trim((string)$v) !== '')) return 0;
      $st = $pdo->prepare("SELECT id FROM fiscal_xml_tax_conversions WHERE company_id=? AND is_active=1
        AND COALESCE(input_cfop,'')=? AND COALESCE(input_icms_cst,'')=? AND COALESCE(input_icms_csosn,'')=?
        AND COALESCE(input_ipi_cst,'')=? AND COALESCE(input_pis_cst,'')=? AND COALESCE(input_cofins_cst,'')=?
        AND COALESCE(output_cfop,'')=? AND COALESCE(output_icms_cst,'')=? AND COALESCE(output_icms_csosn,'')=?
        AND COALESCE(output_ipi_cst,'')=? AND COALESCE(output_pis_cst,'')=? AND COALESCE(output_cofins_cst,'')=? LIMIT 1");
      $st->execute([$companyId,$input['input_cfop'],$input['input_icms_cst'],$input['input_icms_csosn'],$input['input_ipi_cst'],$input['input_pis_cst'],$input['input_cofins_cst'],$output['output_cfop'],$output['output_icms_cst'],$output['output_icms_csosn'],$output['output_ipi_cst'],$output['output_pis_cst'],$output['output_cofins_cst']]);
      $found=(int)$st->fetchColumn(); if($found>0) return $found;
      $icmsIn=$input['input_icms_cst']!==''?$input['input_icms_cst']:$input['input_icms_csosn'];
      $icmsOut=$output['output_icms_cst']!==''?$output['output_icms_cst']:$output['output_icms_csosn'];
      $parts=[]; if($input['input_cfop']!=='')$parts[]='CFOP '.$input['input_cfop']; if($icmsIn!=='')$parts[]='ICMS '.$icmsIn;
      $name='Auto XML '.($parts?implode(' / ',$parts):date('d/m/Y H:i')); if($output['output_cfop']!=='')$name.=' → '.$output['output_cfop']; if($icmsOut!==''&&$icmsOut!==$icmsIn)$name.=' / '.$icmsOut; $name=mb_substr($name,0,120);
      $ins=$pdo->prepare("INSERT INTO fiscal_xml_tax_conversions (company_id,name,input_cfop,input_icms_cst,input_icms_csosn,input_ipi_cst,input_pis_cst,input_cofins_cst,output_cfop,output_icms_cst,output_icms_csosn,output_ipi_cst,output_pis_cst,output_cofins_cst,is_active,notes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW(),NOW())");
      $ins->execute([$companyId,$name,$input['input_cfop']?:null,$input['input_icms_cst']?:null,$input['input_icms_csosn']?:null,$input['input_ipi_cst']?:null,$input['input_pis_cst']?:null,$input['input_cofins_cst']?:null,$output['output_cfop']?:null,$output['output_icms_cst']?:null,$output['output_icms_csosn']?:null,$output['output_ipi_cst']?:null,$output['output_pis_cst']?:null,$output['output_cofins_cst']?:null,'Criada automaticamente durante a conferência de uma entrada XML.']);
      return (int)$pdo->lastInsertId();
    } catch (Throwable $e) { return 0; }
  }
}

if (!function_exists('fiscal_xml_resolve_entry_tax_code')) {
  function fiscal_xml_resolve_entry_tax_code($stored, $source, $converted, int $max = 5): string {
    $storedCode = fiscal_xml_tax_code($stored ?? '', $max);
    $sourceCode = fiscal_xml_tax_code($source ?? '', $max);
    $convertedCode = fiscal_xml_tax_code($converted ?? '', $max);
    // A importação antiga podia gravar nos campos de entrada os mesmos códigos do XML.
    // Quando uma regra passar a existir depois, esses valores "espelho" não podem bloquear a conversão.
    if ($convertedCode !== '' && ($storedCode === '' || $storedCode === $sourceCode)) return $convertedCode;
    return $storedCode !== '' ? $storedCode : ($convertedCode !== '' ? $convertedCode : $sourceCode);
  }
}

if (!function_exists('fiscal_xml_resolve_entry_cfop')) {
  function fiscal_xml_resolve_entry_cfop($stored, $source, $converted): string {
    $storedCode = fiscal_cfop_digits($stored ?? '');
    $sourceCode = fiscal_cfop_digits($source ?? '');
    $convertedCode = fiscal_cfop_digits($converted ?? '');
    if ($convertedCode !== '' && ($storedCode === '' || $storedCode === $sourceCode)) return $convertedCode;
    return $storedCode !== '' ? $storedCode : ($convertedCode !== '' ? $convertedCode : $sourceCode);
  }
}

if (!function_exists('fiscal_xml_item_tax_payload')) {
  function fiscal_xml_item_tax_payload(PDO $pdo, int $companyId, array $item): array {
    $match = fiscal_xml_match_tax_conversion($pdo, $companyId, $item);
    $entryCfop = fiscal_xml_resolve_entry_cfop($item['entry_cfop'] ?? '', $item['cfop'] ?? '', $match['output_cfop'] ?? '');
    $entryOrigem = fiscal_xml_tax_code($item['entry_origem'] ?? ($item['origem'] ?? ''), 2);
    $entryIcmsCst = fiscal_xml_resolve_entry_tax_code($item['entry_icms_cst'] ?? '', $item['icms_cst'] ?? '', $match['output_icms_cst'] ?? '');
    $entryIcmsCsosn = fiscal_xml_resolve_entry_tax_code($item['entry_icms_csosn'] ?? '', $item['icms_csosn'] ?? '', $match['output_icms_csosn'] ?? '');
    $entryIpiCst = fiscal_xml_resolve_entry_tax_code($item['entry_ipi_cst'] ?? '', $item['ipi_cst'] ?? '', $match['output_ipi_cst'] ?? '');
    $entryPisCst = fiscal_xml_resolve_entry_tax_code($item['entry_pis_cst'] ?? '', $item['pis_cst'] ?? '', $match['output_pis_cst'] ?? '');
    $entryCofinsCst = fiscal_xml_resolve_entry_tax_code($item['entry_cofins_cst'] ?? '', $item['cofins_cst'] ?? '', $match['output_cofins_cst'] ?? '');
    $storedProfileId = (int)($item['tax_profile_id'] ?? 0);
    $matchedProfileId = $match ? (int)($match['tax_profile_id'] ?? 0) : 0;
    return [
      'tax_conversion_id' => $match ? (int)($match['id'] ?? 0) : (int)($item['tax_conversion_id'] ?? 0),
      'tax_conversion_name' => $match ? (string)($match['name'] ?? '') : '',
      'tax_profile_id' => $storedProfileId > 0 ? $storedProfileId : $matchedProfileId,
      'entry_cfop' => $entryCfop,
      'entry_origem' => $entryOrigem,
      'entry_icms_cst' => $entryIcmsCst,
      'entry_icms_csosn' => $entryIcmsCsosn,
      'entry_ipi_cst' => $entryIpiCst,
      'entry_pis_cst' => $entryPisCst,
      'entry_cofins_cst' => $entryCofinsCst,
    ];
  }
}

if (!function_exists('fiscal_xml_upload_product_image')) {
  function fiscal_xml_upload_product_image($file, string $productName): string {
    if (!is_array($file) || (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE)) return '';
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $tmp = (string)($file['tmp_name'] ?? '');
    if ($err !== UPLOAD_ERR_OK || $tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('Falha no upload da imagem do produto.');
    $ext = strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg','jpeg','png','webp'], true)) throw new RuntimeException('Formato de imagem inválido. Use JPG, PNG ou WEBP.');
    $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($productName)) ?? 'produto';
    $safeBase = trim($safeBase, '-');
    if ($safeBase === '') $safeBase = 'produto';
    $destDir = __DIR__ . '/../uploads/products/';
    if (!is_dir($destDir)) mkdir($destDir, 0775, true);
    $name = $safeBase . '-' . date('YmdHis') . '-' . bin2hex(random_bytes(3)) . '.' . $ext;
    if (!move_uploaded_file($tmp, $destDir . $name)) throw new RuntimeException('Falha ao salvar a imagem do produto.');
    return $name;
  }
}

if (!function_exists('fiscal_xml_sync_product_operation_cfops_from_profile')) {
  function fiscal_xml_sync_product_operation_cfops_from_profile(PDO $pdo, int $companyId, int $productId, int $taxProfileId): void {
    if ($companyId <= 0 || $productId <= 0 || $taxProfileId <= 0) return;
    fiscal_ensure_operation_cfop_schema($pdo);
    $maps = fiscal_tax_profile_operation_cfops($pdo, $companyId, $taxProfileId, false);
    if (!$maps) return;
    $ins = $pdo->prepare('INSERT INTO fiscal_product_operation_cfops (company_id, product_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, source_profile_id, inherited, notes, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, ?, NOW()) ON DUPLICATE KEY UPDATE tax_profile_id=VALUES(tax_profile_id), cfop_in_state=VALUES(cfop_in_state), cfop_in_interstate=VALUES(cfop_in_interstate), cfop_out_state=VALUES(cfop_out_state), cfop_out_interstate=VALUES(cfop_out_interstate), source_profile_id=VALUES(source_profile_id), inherited=1, notes=VALUES(notes), updated_at=NOW()');
    foreach ($maps as $m) {
      $opId = (int)($m['operation_id'] ?? 0);
      if ($opId <= 0) continue;
      $ins->execute([$companyId, $productId, $taxProfileId, $opId, $m['cfop_in_state'] ?? null, $m['cfop_in_interstate'] ?? null, $m['cfop_out_state'] ?? null, $m['cfop_out_interstate'] ?? null, $taxProfileId, $m['notes'] ?? null]);
    }
  }
}

if (!function_exists('fiscal_xml_save_item_links')) {
  function fiscal_xml_save_item_links(PDO $pdo, int $companyId, int $xmlId, array $post): int {
    fiscal_xml_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota XML não encontrada.');
    $productIds = (array)($post['item_product_id'] ?? []);
    $productSearch = (array)($post['item_product_search'] ?? []);
    $factors = (array)($post['conversion_factor'] ?? []);
    $saved = 0;
    $stCheck = $pdo->prepare('SELECT * FROM fiscal_xml_items WHERE company_id = ? AND xml_import_id = ? AND id = ? LIMIT 1');
    $stProduct = $pdo->prepare('SELECT id FROM produtos WHERE company_id = ? AND id = ? LIMIT 1');
    $stUpdate = $pdo->prepare('UPDATE fiscal_xml_items SET product_id = ?, conversion_factor = ?, stock_quantity=?, stock_unit_cost=?, linked_at = NOW() WHERE company_id = ? AND xml_import_id = ? AND id = ?');
    foreach ($factors as $itemIdRaw => $factorRaw) {
      $itemId = (int)$itemIdRaw;
      if ($itemId <= 0) continue;
      $stCheck->execute([$companyId, $xmlId, $itemId]);
      $item = $stCheck->fetch(PDO::FETCH_ASSOC);
      if (!$item) continue;
      $productId = (int)($productIds[$itemId] ?? 0);
      if ($productId <= 0) {
        $txt = (string)($productSearch[$itemId] ?? '');
        if (preg_match('/#(\d+)/', $txt, $m)) $productId = (int)$m[1];
      }
      if ($productId > 0) {
        $stProduct->execute([$companyId, $productId]);
        if (!(int)$stProduct->fetchColumn()) $productId = 0;
      }
      $factor = fiscal_xml_parse_decimal($factorRaw, 1.0);
      if ($factor <= 0) $factor = 1.0;
      $stockQty = (float)($item['quantity'] ?? 0) * $factor;
      $stockUnitCost = $stockQty > 0 ? round(fiscal_xml_item_landed_base_value((array)$item) / $stockQty, 4) : 0.0;
      $stUpdate->execute([$productId > 0 ? $productId : null, $factor, $stockQty, $stockUnitCost, $companyId, $xmlId, $itemId]);
      if ($productId > 0) {
        try {
          fiscal_xml_save_product_link($pdo, $companyId, (string)($xml['supplier_doc'] ?? ''), (string)($item['product_code'] ?? ''), (string)($item['ean'] ?? ''), $productId, $factor, $xmlId, $itemId);
        } catch (Throwable $e) {}
      }
      $saved++;
    }
    return $saved;
  }
}



if (!function_exists('fiscal_xml_item_fcp_st_cost_value')) {
  function fiscal_xml_item_fcp_st_cost_value(array $item): float {
    $raw = trim((string)($item['tax_json'] ?? ''));
    if ($raw === '') return 0.0;
    $data = json_decode($raw, true);
    if (!is_array($data)) return 0.0;
    foreach (['fcp_st_amount','vFCPST'] as $key) {
      if (isset($data[$key]) && is_numeric($data[$key])) return max(0.0, (float)$data[$key]);
    }
    return 0.0;
  }
}

if (!function_exists('fiscal_xml_item_landed_base_value')) {
  function fiscal_xml_item_landed_base_value(array $item): float {
    $product = (float)($item['total_value'] ?? 0);
    if ($product <= 0) {
      $qty = (float)($item['quantity'] ?? 0);
      $unit = (float)($item['unit_value'] ?? 0);
      if ($qty > 0 && $unit > 0) $product = $qty * $unit;
    }
    $value = $product
      + (float)($item['freight_value'] ?? 0)
      + (float)($item['insurance_value'] ?? 0)
      + (float)($item['other_value'] ?? 0)
      + (float)($item['ipi_value'] ?? 0)
      + (float)($item['icms_st_value'] ?? 0)
      + fiscal_xml_item_fcp_st_cost_value($item)
      - (float)($item['discount_value'] ?? 0);
    return round(max(0.0, $value), 4);
  }
}

if (!function_exists('fiscal_xml_entry_landed_value_map')) {
  function fiscal_xml_entry_landed_value_map(array $xml, array $items): array {
    $map = [];
    $baseSum = 0.0;
    $weights = [];
    $weightSum = 0.0;
    foreach ($items as $item) {
      $itemId = (int)($item['id'] ?? 0);
      if ($itemId <= 0) continue;
      $base = fiscal_xml_item_landed_base_value($item);
      $map[$itemId] = $base;
      $baseSum += $base;
      $weight = max(0.0, (float)($item['total_value'] ?? 0));
      if ($weight <= 0) $weight = max(0.0, $base);
      $weights[$itemId] = $weight;
      $weightSum += $weight;
    }
    // Em NF-e importada, vNF é a referência final para capturar também encargos
    // que alguns emissores informam apenas no total da nota. Em lançamento manual,
    // o total_invoice atual é formado somente pelos produtos; portanto não o usamos
    // para retirar IPI/ST informados item a item.
    if ((int)($xml['is_manual'] ?? 0) === 1) return $map;
    $target = round((float)($xml['total_invoice'] ?? 0), 4);
    if ($target <= 0 || !$map) return $map;

    $diff = round($target - $baseSum, 4);
    if (abs($diff) < 0.005 || $weightSum <= 0) return $map;

    $ids = array_keys($map);
    $allocated = 0.0;
    $lastId = end($ids);
    foreach ($ids as $itemId) {
      if ($itemId === $lastId) {
        $share = round($diff - $allocated, 4);
      } else {
        $share = round($diff * (($weights[$itemId] ?? 0.0) / $weightSum), 4);
        $allocated += $share;
      }
      $map[$itemId] = round(max(0.0, $map[$itemId] + $share), 4);
    }

    // Corrige eventual resíduo de arredondamento sem criar custo negativo.
    $sum = round(array_sum($map), 4);
    $residual = round($target - $sum, 4);
    if (abs($residual) >= 0.0001 && $lastId !== false && isset($map[$lastId])) {
      $map[$lastId] = round(max(0.0, $map[$lastId] + $residual), 4);
    }
    return $map;
  }
}

if (!function_exists('fiscal_xml_pricing_preview')) {
  function fiscal_xml_pricing_preview(PDO $pdo, int $companyId, int $xmlId, string $pricingPolicy = 'keep_margin'): array {
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) return [];
    $items = fiscal_xml_items($pdo, $companyId, $xmlId);
    if (!$items) return [];

    $landedMap = fiscal_xml_entry_landed_value_map($xml, $items);
    $groups = [];
    foreach ($items as $item) {
      $productId = (int)($item['product_id'] ?? 0);
      if ($productId <= 0) continue;
      $factor = fiscal_xml_parse_decimal($item['conversion_factor'] ?? 1, 1.0);
      if ($factor <= 0) $factor = 1.0;
      $stockQty = round((float)($item['quantity'] ?? 0) * $factor, 4);
      if ($stockQty <= 0) continue;
      if (!isset($groups[$productId])) $groups[$productId] = ['qty'=>0.0,'landed'=>0.0,'items'=>0];
      $groups[$productId]['qty'] += $stockQty;
      $groups[$productId]['landed'] += (float)($landedMap[(int)($item['id'] ?? 0)] ?? fiscal_xml_item_landed_base_value($item));
      $groups[$productId]['items']++;
    }
    if (!$groups) return [];

    $ids = array_keys($groups);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $select = ['id','nome','COALESCE(preco,0) AS preco'];
    $select[] = fiscal_column_exists($pdo, 'produtos', 'custo') ? 'COALESCE(custo,0) AS custo' : '0 AS custo';
    $select[] = fiscal_column_exists($pdo, 'produtos', 'estoque_atual') ? 'COALESCE(estoque_atual,0) AS estoque_atual' : '0 AS estoque_atual';
    if (fiscal_column_exists($pdo, 'produtos', 'numero_cadastro')) $select[] = 'numero_cadastro';
    if (fiscal_column_exists($pdo, 'produtos', 'codigo_firebird')) $select[] = 'codigo_firebird';
    if (fiscal_column_exists($pdo, 'produtos', 'referencia')) $select[] = 'referencia';
    $st = $pdo->prepare('SELECT ' . implode(',', $select) . ' FROM produtos WHERE company_id=? AND id IN (' . $ph . ')');
    $st->execute(array_merge([$companyId], $ids));
    $products = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $products[(int)$row['id']] = $row;

    $pricingPolicy = strtolower(trim($pricingPolicy));
    if (!in_array($pricingPolicy, ['keep_margin','keep_price'], true)) $pricingPolicy = 'keep_margin';

    $out = [];
    foreach ($groups as $productId => $group) {
      $p = $products[(int)$productId] ?? null;
      if (!$p) continue;
      $qty = max(0.0, (float)$group['qty']);
      $newCost = $qty > 0 ? round((float)$group['landed'] / $qty, 4) : 0.0;
      $oldCost = max(0.0, (float)($p['custo'] ?? 0));
      $oldPrice = max(0.0, (float)($p['preco'] ?? 0));
      $oldMargin = $oldCost > 0 ? (($oldPrice / $oldCost) - 1.0) * 100.0 : 0.0;

      if ($pricingPolicy === 'keep_price' || $oldCost <= 0) {
        $newPrice = $oldPrice;
        $newMargin = $newCost > 0 ? (($newPrice / $newCost) - 1.0) * 100.0 : 0.0;
      } else {
        $newMargin = $oldMargin;
        $newPrice = round($newCost * (1.0 + ($newMargin / 100.0)), 2);
      }

      $code = trim((string)($p['numero_cadastro'] ?? ''));
      if ($code === '' || $code === '0') $code = trim((string)($p['codigo_firebird'] ?? ''));
      if ($code === '') $code = (string)$productId;

      $out[] = [
        'product_id' => (int)$productId,
        'code' => $code,
        'name' => (string)($p['nome'] ?? ('Produto #' . $productId)),
        'previous_quantity' => (float)($p['estoque_atual'] ?? 0),
        'entry_quantity' => $qty,
        'old_cost' => $oldCost,
        'old_margin' => round($oldMargin, 4),
        'old_price' => $oldPrice,
        'new_cost' => $newCost,
        'new_margin' => round($newMargin, 4),
        'new_price' => round($newPrice, 2),
        'landed_total' => round((float)$group['landed'], 4),
      ];
    }
    usort($out, static function(array $a, array $b): int {
      $ca = (string)($a['code'] ?? '');
      $cb = (string)($b['code'] ?? '');
      if (is_numeric($ca) && is_numeric($cb)) return ((float)$ca <=> (float)$cb);
      return strnatcasecmp($ca, $cb);
    });
    return $out;
  }
}


if (!function_exists('fiscal_xml_confirm_product_link')) {
  function fiscal_xml_confirm_product_link(PDO $pdo, int $companyId, int $xmlId, int $itemId, int $productId, float $factor, array $post = []): array {
    fiscal_xml_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota XML não encontrada.');
    if ((string)($xml['status'] ?? '') === 'ENTRADA_GERADA') throw new RuntimeException('A entrada já foi gerada. Desfaça a nota antes de alterar o vínculo do produto.');

    $st = $pdo->prepare('SELECT * FROM fiscal_xml_items WHERE company_id=? AND xml_import_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $xmlId, $itemId]);
    $item = $st->fetch(PDO::FETCH_ASSOC);
    if (!$item) throw new RuntimeException('Item do XML não encontrado.');

    $st = $pdo->prepare('SELECT id, nome FROM produtos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new RuntimeException('Produto selecionado não foi encontrado.');

    if ($factor <= 0) $factor = 1.0;
    $existingRule = fiscal_xml_match_tax_conversion($pdo, $companyId, $item);
    $payload = fiscal_xml_item_tax_payload($pdo, $companyId, $item);

    if ($existingRule) {
      // Regra já homologada/cadastrada: apenas confirma sua aplicação.
      $entryTax = [
        'entry_cfop' => (string)($payload['entry_cfop'] ?? ''),
        'entry_origem' => (string)($payload['entry_origem'] ?? ''),
        'entry_icms_cst' => (string)($payload['entry_icms_cst'] ?? ''),
        'entry_icms_csosn' => (string)($payload['entry_icms_csosn'] ?? ''),
        'entry_ipi_cst' => (string)($payload['entry_ipi_cst'] ?? ''),
        'entry_pis_cst' => (string)($payload['entry_pis_cst'] ?? ''),
        'entry_cofins_cst' => (string)($payload['entry_cofins_cst'] ?? ''),
      ];
      $taxConversionId = (int)($existingRule['id'] ?? 0);
      $taxConversionCreated = false;
      $taxProfileId = (int)($payload['tax_profile_id'] ?? 0);
    } else {
      // Sem regra específica: os valores confirmados viram uma regra automática para reutilização.
      $entryTax = fiscal_xml_entry_tax_from_overrides($item, [
        'entry_cfop' => $post['entry_cfop'] ?? ($payload['entry_cfop'] ?? ''),
        'entry_origem' => $post['entry_origem'] ?? ($payload['entry_origem'] ?? ''),
        'entry_icms_cst' => $post['entry_icms_cst'] ?? ($payload['entry_icms_cst'] ?? ''),
        'entry_icms_csosn' => $post['entry_icms_csosn'] ?? ($payload['entry_icms_csosn'] ?? ''),
        'entry_ipi_cst' => $post['entry_ipi_cst'] ?? ($payload['entry_ipi_cst'] ?? ''),
        'entry_pis_cst' => $post['entry_pis_cst'] ?? ($payload['entry_pis_cst'] ?? ''),
        'entry_cofins_cst' => $post['entry_cofins_cst'] ?? ($payload['entry_cofins_cst'] ?? ''),
      ]);
      $taxConversionId = fiscal_entry_auto_tax_conversion($pdo, $companyId, $item, $entryTax);
      $taxConversionCreated = $taxConversionId > 0;
      $taxProfileId = (int)($payload['tax_profile_id'] ?? 0);
    }

    $stockQty = (float)($item['quantity'] ?? 0) * $factor;
    $stockUnitCost = $stockQty > 0 ? round(fiscal_xml_item_landed_base_value((array)$item) / $stockQty, 4) : 0.0;
    $pdo->prepare('UPDATE fiscal_xml_items SET product_id=?, conversion_factor=?, stock_quantity=?, stock_unit_cost=?, entry_cfop=?, entry_origem=?, entry_icms_cst=?, entry_icms_csosn=?, entry_ipi_cst=?, entry_pis_cst=?, entry_cofins_cst=?, tax_profile_id=?, tax_conversion_id=?, linked_at=NOW() WHERE company_id=? AND xml_import_id=? AND id=?')
      ->execute([
        $productId, $factor, $stockQty, $stockUnitCost,
        $entryTax['entry_cfop'] ?: null, $entryTax['entry_origem'] ?: null, $entryTax['entry_icms_cst'] ?: null, $entryTax['entry_icms_csosn'] ?: null,
        $entryTax['entry_ipi_cst'] ?: null, $entryTax['entry_pis_cst'] ?: null, $entryTax['entry_cofins_cst'] ?: null,
        $taxProfileId > 0 ? $taxProfileId : null, $taxConversionId > 0 ? $taxConversionId : null,
        $companyId, $xmlId, $itemId
      ]);

    $linkId = fiscal_xml_save_product_link(
      $pdo, $companyId, (string)($xml['supplier_doc'] ?? ''), (string)($item['product_code'] ?? ''), (string)($item['ean'] ?? ''),
      $productId, $factor, $xmlId, $itemId
    );

    $ruleName = '';
    if ($taxConversionId > 0) {
      $rule = fiscal_xml_tax_conversion($pdo, $companyId, $taxConversionId);
      $ruleName = trim((string)($rule['name'] ?? ''));
    }

    return [
      'item_id' => $itemId,
      'product_id' => $productId,
      'product_name' => (string)($product['nome'] ?? ''),
      'factor' => $factor,
      'stock_quantity' => $stockQty,
      'stock_unit_cost' => $stockUnitCost,
      'product_link_id' => $linkId,
      'tax_conversion_id' => $taxConversionId,
      'tax_conversion_name' => $ruleName,
      'tax_conversion_created' => $taxConversionCreated,
      'entry_tax' => $entryTax,
    ];
  }
}



if (!function_exists('fiscal_xml_entry_tax_from_overrides')) {
  function fiscal_xml_entry_tax_from_overrides(array $it, array $overrides = []): array {
    return [
      'entry_cfop' => fiscal_cfop_digits($overrides['entry_cfop'] ?? ($it['entry_cfop'] ?? ($it['cfop'] ?? ''))),
      'entry_origem' => fiscal_xml_tax_code($overrides['entry_origem'] ?? ($it['entry_origem'] ?? ($it['origem'] ?? '')), 2),
      'entry_icms_cst' => fiscal_xml_tax_code($overrides['entry_icms_cst'] ?? ($it['entry_icms_cst'] ?? ($it['icms_cst'] ?? ''))),
      'entry_icms_csosn' => fiscal_xml_tax_code($overrides['entry_icms_csosn'] ?? ($it['entry_icms_csosn'] ?? ($it['icms_csosn'] ?? ''))),
      'entry_ipi_cst' => fiscal_xml_tax_code($overrides['entry_ipi_cst'] ?? ($it['entry_ipi_cst'] ?? ($it['ipi_cst'] ?? ''))),
      'entry_pis_cst' => fiscal_xml_tax_code($overrides['entry_pis_cst'] ?? ($it['entry_pis_cst'] ?? ($it['pis_cst'] ?? ''))),
      'entry_cofins_cst' => fiscal_xml_tax_code($overrides['entry_cofins_cst'] ?? ($it['entry_cofins_cst'] ?? ($it['cofins_cst'] ?? ''))),
    ];
  }
}

if (!function_exists('fiscal_xml_ensure_product_category_visibility_columns')) {
  function fiscal_xml_ensure_product_category_visibility_columns(PDO $pdo): array {
    $cols = [
      'category_hide_order_catalog' => fiscal_column_exists($pdo, 'produtos', 'category_hide_order_catalog'),
      'category_hide_product_catalog' => fiscal_column_exists($pdo, 'produtos', 'category_hide_product_catalog'),
    ];
    if (!$pdo->inTransaction()) {
      if (!$cols['category_hide_order_catalog']) {
        fiscal_add_column_if_missing($pdo, 'produtos', 'category_hide_order_catalog', 'TINYINT(1) NOT NULL DEFAULT 0');
        $cols['category_hide_order_catalog'] = fiscal_column_exists($pdo, 'produtos', 'category_hide_order_catalog');
      }
      if (!$cols['category_hide_product_catalog']) {
        fiscal_add_column_if_missing($pdo, 'produtos', 'category_hide_product_catalog', 'TINYINT(1) NOT NULL DEFAULT 0');
        $cols['category_hide_product_catalog'] = fiscal_column_exists($pdo, 'produtos', 'category_hide_product_catalog');
      }
    }
    return $cols;
  }
}

if (!function_exists('fiscal_xml_category_visibility_for_category')) {
  function fiscal_xml_category_visibility_for_category(PDO $pdo, int $companyId, string $category): array {
    $cols = fiscal_xml_ensure_product_category_visibility_columns($pdo);
    $out = ['category_hide_order_catalog' => 0, 'category_hide_product_catalog' => 0];
    $category = trim($category);
    if ($category === '') return $out;
    try {
      $select = '';
      $select .= $cols['category_hide_order_catalog'] ? 'COALESCE(MAX(category_hide_order_catalog),0) AS hide_order' : '0 AS hide_order';
      $select .= $cols['category_hide_product_catalog'] ? ', COALESCE(MAX(category_hide_product_catalog),0) AS hide_product' : ', 0 AS hide_product';
      $st = $pdo->prepare('SELECT ' . $select . ' FROM produtos WHERE company_id = ? AND categoria = ?');
      $st->execute([$companyId, $category]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $out['category_hide_order_catalog'] = (int)($row['hide_order'] ?? 0);
      $out['category_hide_product_catalog'] = (int)($row['hide_product'] ?? 0);
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('fiscal_xml_create_product_from_item')) {
  function fiscal_xml_create_product_from_item(PDO $pdo, int $companyId, int $xmlId, int $itemId, string $category = '', float $factor = 1.0, array $overrides = []): int {
    fiscal_xml_ensure_schema($pdo);
    require_once __DIR__ . '/stock_module.php';
    try { stock_ensure_schema($pdo, true); } catch (Throwable $e) {}
    $st = $pdo->prepare('SELECT * FROM fiscal_xml_items WHERE company_id = ? AND xml_import_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $xmlId, $itemId]);
    $it = $st->fetch(PDO::FETCH_ASSOC);
    if (!$it) throw new RuntimeException('Item do XML não encontrado para cadastrar produto.');
    $name = trim((string)($overrides['name'] ?? '')) ?: (trim((string)($it['name'] ?? '')) ?: 'Produto XML');
    $category = trim($category) !== '' ? trim($category) : (trim((string)($overrides['category'] ?? '')) ?: 'XML / Compras');
    $categoryVisibilityCols = fiscal_xml_ensure_product_category_visibility_columns($pdo);
    $existingCategoryVisibility = fiscal_xml_category_visibility_for_category($pdo, $companyId, $category);
    $hideCategoryOrderCatalog = array_key_exists('category_hide_order_catalog', $overrides)
      ? (!empty($overrides['category_hide_order_catalog']) ? 1 : 0)
      : (int)($existingCategoryVisibility['category_hide_order_catalog'] ?? 0);
    $hideCategoryProductCatalog = array_key_exists('category_hide_product_catalog', $overrides)
      ? (!empty($overrides['category_hide_product_catalog']) ? 1 : 0)
      : (int)($existingCategoryVisibility['category_hide_product_catalog'] ?? 0);
    $unit = strtoupper(trim((string)($overrides['unit'] ?? ($it['unit'] ?? 'UN')))) ?: 'UN';
    $barcode = preg_replace('/\D+/', '', (string)($overrides['barcode'] ?? ($it['ean'] ?? ''))) ?? '';
    if ($barcode !== '' && !fiscal_column_exists($pdo, 'produtos', 'codigo_barras') && !$pdo->inTransaction()) {
      try { fiscal_add_column_if_missing($pdo, 'produtos', 'codigo_barras', 'VARCHAR(80) NULL'); } catch (Throwable $e) {}
    }
    $supplierCode = trim((string)($overrides['supplier_code'] ?? ($it['product_code'] ?? '')));
    // A descrição do produto aparece no catálogo para o cliente.
    // Portanto, dados técnicos do XML ficam nas observações fiscais/internas, não neste campo público.
    $description = trim((string)($overrides['description'] ?? ''));
    $salePrice = fiscal_xml_parse_decimal($overrides['sale_price'] ?? 0, 0.0);
    $cols = ['company_id'];
    $vals = [$companyId];
    $data = [
      'nome' => $name,
      'descricao' => $description,
      'categoria' => $category,
      'preco' => max(0, $salePrice),
      'ativo' => 1,
      'unidade_estoque' => $unit,
      'estoque_atual' => 0,
    ];
    if (!empty($categoryVisibilityCols['category_hide_order_catalog'])) $data['category_hide_order_catalog'] = $hideCategoryOrderCatalog;
    if (!empty($categoryVisibilityCols['category_hide_product_catalog'])) $data['category_hide_product_catalog'] = $hideCategoryProductCatalog;
    if (!empty($overrides['image_file']) && fiscal_column_exists($pdo, 'produtos', 'imagem')) {
      $imageName = fiscal_xml_upload_product_image($overrides['image_file'], $name);
      if ($imageName !== '') $data['imagem'] = $imageName;
    }
    foreach (['codigo_barras','ean','gtin','barcode'] as $barcodeCol) {
      if ($barcode !== '' && fiscal_column_exists($pdo, 'produtos', $barcodeCol)) $data[$barcodeCol] = $barcode;
    }
    foreach (['codigo_fornecedor','supplier_code','referencia_fornecedor'] as $supplierCol) {
      if ($supplierCode !== '' && fiscal_column_exists($pdo, 'produtos', $supplierCol)) $data[$supplierCol] = $supplierCode;
    }
    if (fiscal_xml_gestao_enabled($pdo, $companyId) && fiscal_xml_ensure_product_cost_column($pdo)) {
      $factor = $factor > 0 ? $factor : fiscal_xml_parse_decimal($it['conversion_factor'] ?? 1, 1.0);
      if ($factor <= 0) $factor = 1.0;
      $stockQty = (float)($it['quantity'] ?? 0) * $factor;
      $calculatedCost = $stockQty > 0 ? round(fiscal_xml_item_landed_base_value((array)$it) / $stockQty, 4) : (float)($it['unit_value'] ?? 0);
      $manualCost = array_key_exists('cost', $overrides) && $overrides['cost'] !== null ? fiscal_xml_parse_decimal($overrides['cost'], $calculatedCost) : $calculatedCost;
      $data['custo'] = max(0, $manualCost);
    }
    foreach ($data as $col => $val) {
      if (!fiscal_column_exists($pdo, 'produtos', $col)) continue;
      $cols[] = $col;
      $vals[] = $val;
    }
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $pdo->prepare('INSERT INTO produtos (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')')->execute($vals);
    $productId = (int)$pdo->lastInsertId();
    if ($productId <= 0) throw new RuntimeException('Não foi possível cadastrar o produto.');

    if ($category !== '' && (!empty($categoryVisibilityCols['category_hide_order_catalog']) || !empty($categoryVisibilityCols['category_hide_product_catalog']))) {
      try {
        $catSet = [];
        $catVals = [];
        if (!empty($categoryVisibilityCols['category_hide_order_catalog'])) {
          $catSet[] = 'category_hide_order_catalog = ?';
          $catVals[] = $hideCategoryOrderCatalog;
        }
        if (!empty($categoryVisibilityCols['category_hide_product_catalog'])) {
          $catSet[] = 'category_hide_product_catalog = ?';
          $catVals[] = $hideCategoryProductCatalog;
        }
        if ($catSet) {
          $catVals[] = $companyId;
          $catVals[] = $category;
          $pdo->prepare('UPDATE produtos SET ' . implode(', ', $catSet) . ' WHERE company_id = ? AND categoria = ?')->execute($catVals);
        }
      } catch (Throwable $e) {}
    }

    $entryTax = fiscal_xml_entry_tax_from_overrides($it, $overrides);

    if (fiscal_table_exists($pdo, 'fiscal_product_tax')) {
      try {
        $taxProfileId = (int)($overrides['tax_profile_id'] ?? ($it['tax_profile_id'] ?? 0));
        $taxProfile = $taxProfileId > 0 ? fiscal_tax_profile($pdo, $companyId, $taxProfileId) : null;
        if (!$taxProfile) { $taxProfileId = 0; $taxProfile = []; }
        $xmlInternalNotes = [];
        $xmlInternalNotes[] = 'Cadastrado pelo RADAR XML.';
        $xmlCode = trim((string)($it['product_code'] ?? ''));
        if ($xmlCode !== '') $xmlInternalNotes[] = 'Código XML: ' . $xmlCode . '.';
        if ($barcode !== '') $xmlInternalNotes[] = 'EAN: ' . $barcode . '.';
        $xmlInternalNotes[] = 'XML fornecedor: CFOP ' . ((string)($it['cfop'] ?? '') ?: '—') . ', origem ' . ((string)($it['origem'] ?? '') ?: '—') . ', ICMS ' . (((string)($it['icms_cst'] ?? '') ?: (string)($it['icms_csosn'] ?? '')) ?: '—') . ', IPI ' . ((string)($it['ipi_cst'] ?? '') ?: '—') . ', PIS ' . ((string)($it['pis_cst'] ?? '') ?: '—') . ', COFINS ' . ((string)($it['cofins_cst'] ?? '') ?: '—') . '.';
        $xmlInternalNotes[] = 'Entrada aplicada: CFOP ' . ($entryTax['entry_cfop'] ?: '—') . ', origem ' . ($entryTax['entry_origem'] ?: '—') . ', ICMS ' . (($entryTax['entry_icms_cst'] ?: $entryTax['entry_icms_csosn']) ?: '—') . ', IPI ' . ($entryTax['entry_ipi_cst'] ?: '—') . ', PIS ' . ($entryTax['entry_pis_cst'] ?: '—') . ', COFINS ' . ($entryTax['entry_cofins_cst'] ?: '—') . '.';
        if (!empty($it['tax_conversion_id'])) $xmlInternalNotes[] = 'Conversão fiscal #' . (int)$it['tax_conversion_id'] . '.';
        $manualFiscalNotes = trim((string)($overrides['fiscal_notes'] ?? ''));
        if ($manualFiscalNotes !== '') $xmlInternalNotes[] = $manualFiscalNotes;

        $taxData = [
          'tax_profile_id' => $taxProfileId > 0 ? $taxProfileId : null,
          'ncm' => preg_replace('/\D+/', '', (string)($overrides['ncm'] ?? ($it['ncm'] ?? ''))),
          'cest' => preg_replace('/\D+/', '', (string)($overrides['cest'] ?? ($it['cest'] ?? ''))),
          'origem' => fiscal_xml_tax_code($overrides['product_origem'] ?? ($it['origem'] ?? ''), 2),
          'tax_mode' => (string)($taxProfile['tax_mode'] ?? ''),
          'icms_rate' => (float)($taxProfile['icms_rate'] ?? 0),
          'icms_st_rate' => (float)($taxProfile['icms_st_rate'] ?? 0),
          'icms_cst' => fiscal_xml_tax_code($taxProfile['icms_cst'] ?? ($it['icms_cst'] ?? '')),
          'icms_csosn' => fiscal_xml_tax_code($taxProfile['icms_csosn'] ?? ($it['icms_csosn'] ?? '')),
          'ipi_rate' => (float)($taxProfile['ipi_rate'] ?? 0),
          'ipi_cst' => fiscal_xml_tax_code($taxProfile['ipi_cst'] ?? ($it['ipi_cst'] ?? '')),
          'pis_rate' => (float)($taxProfile['pis_out_rate'] ?? 0),
          'pis_cst' => fiscal_xml_tax_code($taxProfile['pis_out_cst'] ?? ($it['pis_cst'] ?? '')),
          'cofins_rate' => (float)($taxProfile['cofins_out_rate'] ?? 0),
          'cofins_cst' => fiscal_xml_tax_code($taxProfile['cofins_out_cst'] ?? ($it['cofins_cst'] ?? '')),
          'cfop_entry_state' => (string)($taxProfile['cfop_entry_state'] ?? ''),
          'cfop_entry_interstate' => (string)($taxProfile['cfop_entry_interstate'] ?? ''),
          'cfop_sale_state' => (string)($taxProfile['cfop_sale_state'] ?? ''),
          'cfop_sale_interstate' => (string)($taxProfile['cfop_sale_interstate'] ?? ''),
          'cfop_return_state' => (string)($taxProfile['cfop_return_state'] ?? ''),
          'cfop_return_interstate' => (string)($taxProfile['cfop_return_interstate'] ?? ''),
          'fiscal_notes' => trim(implode(' ', $xmlInternalNotes)),
        ];
        $taxCols = ['company_id','product_id'];
        $taxVals = [$companyId, $productId];
        foreach ($taxData as $col => $val) {
          if (!fiscal_column_exists($pdo, 'fiscal_product_tax', $col)) continue;
          if ($val === '') $val = null;
          $taxCols[] = $col;
          $taxVals[] = $val;
        }
        $taxPh = implode(',', array_fill(0, count($taxCols), '?'));
        $pdo->prepare('INSERT INTO fiscal_product_tax (`' . implode('`,`', $taxCols) . '`) VALUES (' . $taxPh . ')')->execute($taxVals);
        if ($taxProfileId > 0) fiscal_xml_sync_product_operation_cfops_from_profile($pdo, $companyId, $productId, $taxProfileId);
      } catch (Throwable $e) {}
    }

    $selectedTaxProfileId = (int)($overrides['tax_profile_id'] ?? ($it['tax_profile_id'] ?? 0));
    $taxConversionId = (int)($it['tax_conversion_id'] ?? 0);
    if ($taxConversionId <= 0) $taxConversionId = fiscal_entry_auto_tax_conversion($pdo, $companyId, $it, $entryTax);
    $factor = $factor > 0 ? $factor : fiscal_xml_parse_decimal($it['conversion_factor'] ?? 1, 1.0);
    if ($factor <= 0) $factor = 1.0;
    $stockQty = (float)($it['quantity'] ?? 0) * $factor;
    $stockUnitCost = $stockQty > 0 ? round(fiscal_xml_item_landed_base_value((array)$it) / $stockQty, 4) : 0.0;
    $pdo->prepare('UPDATE fiscal_xml_items SET product_id = ?, conversion_factor = ?, stock_quantity=?, stock_unit_cost=?, entry_cfop = ?, entry_origem = ?, entry_icms_cst = ?, entry_icms_csosn = ?, entry_ipi_cst = ?, entry_pis_cst = ?, entry_cofins_cst = ?, tax_profile_id = ?, tax_conversion_id = ?, linked_at = NOW() WHERE company_id = ? AND xml_import_id = ? AND id = ?')
      ->execute([$productId, $factor, $stockQty, $stockUnitCost, $entryTax['entry_cfop'] ?: null, $entryTax['entry_origem'] ?: null, $entryTax['entry_icms_cst'] ?: null, $entryTax['entry_icms_csosn'] ?: null, $entryTax['entry_ipi_cst'] ?: null, $entryTax['entry_pis_cst'] ?: null, $entryTax['entry_cofins_cst'] ?: null, $selectedTaxProfileId > 0 ? $selectedTaxProfileId : null, $taxConversionId > 0 ? $taxConversionId : null, $companyId, $xmlId, $itemId]);
    try {
      $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
      fiscal_xml_save_product_link($pdo, $companyId, (string)($xml['supplier_doc'] ?? ''), (string)($it['product_code'] ?? ''), (string)($it['ean'] ?? ''), $productId, $factor, $xmlId, $itemId);
    } catch (Throwable $e) {}
    return $productId;
  }
}

if (!function_exists('fiscal_xml_installments')) {
  function fiscal_xml_installments(array $xml): array {
    $rows = json_decode((string)($xml['payment_json'] ?? ''), true);
    if (!is_array($rows)) $rows = [];
    if (!$rows && (float)($xml['total_invoice'] ?? 0) > 0) {
      $rows[] = ['number' => '001', 'due_date' => $xml['issue_date'] ?? date('Y-m-d'), 'amount' => (float)($xml['total_invoice'] ?? 0)];
    }
    return $rows;
  }
}

if (!function_exists('fiscal_xml_find_or_create_supplier')) {
  function fiscal_xml_find_or_create_supplier(PDO $pdo, int $companyId, array $xml): int {
    require_once __DIR__ . '/finance_module.php';
    finance_ensure_schema($pdo);
    $doc = fiscal_xml_digits($xml['supplier_doc'] ?? '');
    $name = trim((string)($xml['supplier_name'] ?? '')) ?: 'Fornecedor da NF-e';
    if ($doc !== '') {
      $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id = ? AND document = ? LIMIT 1');
      $st->execute([$companyId, $doc]);
      $id = (int)$st->fetchColumn();
      if ($id > 0) {
        $pdo->prepare('UPDATE finance_people SET name=IF(name="" OR name IS NULL, ?, name), city_ibge=COALESCE(NULLIF(city_ibge,\'\'),?), is_supplier=1, active=1, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$name, fiscal_xml_digits($xml['supplier_city_ibge'] ?? '') ?: null, $companyId, $id]);
        return $id;
      }
    }
    $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id = ? AND name = ? AND is_supplier = 1 LIMIT 1');
    $st->execute([$companyId, $name]);
    $id = (int)$st->fetchColumn();
    if ($id > 0) return $id;

    $personType = strlen($doc) === 11 ? 'FISICA' : 'JURIDICA';
    $ins = $pdo->prepare('INSERT INTO finance_people (company_id, name, document, person_type, zip_code, address, number, district, city, city_ibge, state, is_supplier, active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $ins->execute([
      $companyId, $name, $doc !== '' ? $doc : null, $personType, $xml['supplier_zip'] ?? null, $xml['supplier_address'] ?? null, $xml['supplier_number'] ?? null, $xml['supplier_district'] ?? null, $xml['supplier_city'] ?? null, fiscal_xml_digits($xml['supplier_city_ibge'] ?? '') ?: null, $xml['supplier_uf'] ?? null, 1, 1,
      'Fornecedor cadastrado automaticamente pela importação XML da NF-e ' . (string)($xml['number'] ?? '')
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('fiscal_xml_default_debit_chart')) {
  function fiscal_xml_default_debit_chart(PDO $pdo, int $companyId): int {
    require_once __DIR__ . '/finance_module.php';
    finance_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT id FROM finance_chart_accounts WHERE company_id = ? AND active = 1 AND is_analytic = 1 AND movement_type IN ('DEBITO','AMBOS') ORDER BY CASE WHEN account_type IN ('DESPESA','CUSTO','COMPRA') THEN 0 ELSE 1 END, code ASC, id ASC LIMIT 1");
    $st->execute([$companyId]);
    return (int)($st->fetchColumn() ?: 0);
  }
}

if (!function_exists('fiscal_xml_generate_entry')) {
  function fiscal_xml_generate_entry(PDO $pdo, int $companyId, int $xmlId, int $operationId, int $chartAccountId = 0, ?int $costCenterId = null, ?string $fallbackDueDate = null, bool $allowFinance = true, array $pricingOverrides = [], bool $updateSalePrices = false): array {
    require_once __DIR__ . '/finance_module.php';
    fiscal_xml_ensure_schema($pdo);
    if ($allowFinance) finance_ensure_schema($pdo);

    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('XML não encontrado.');
    if ((string)($xml['status'] ?? '') === 'ENTRADA_GERADA') throw new RuntimeException('Esta entrada já foi gerada para este XML.');
    $operation = fiscal_operation($pdo, $companyId, $operationId);
    if (!$operation) throw new RuntimeException('Selecione uma operação fiscal válida.');
    if ((string)($operation['direction'] ?? '') !== 'ENTRADA') throw new RuntimeException('Para gerar entrada por XML, selecione uma operação de entrada.');

    $installments = [];
    $personId = 0;
    if ($allowFinance) {
      $chartAccountId = $chartAccountId > 0 ? $chartAccountId : (int)($operation['chart_account_id'] ?? 0);
      if ($chartAccountId <= 0) $chartAccountId = fiscal_xml_default_debit_chart($pdo, $companyId);
      if ($chartAccountId <= 0 || !finance_validate_analytic_chart($pdo, $companyId, $chartAccountId)) throw new RuntimeException('Selecione uma conta analítica de débito no plano de contas para lançar o contas a pagar.');
      $costCenterId = $costCenterId ?: ((int)($operation['cost_center_id'] ?? 0) ?: null);
      if (!finance_validate_analytic_cost($pdo, $companyId, $costCenterId)) throw new RuntimeException('Selecione um centro de custo analítico.');

      $installments = fiscal_xml_installments($xml);
      if (!$installments) throw new RuntimeException('Não encontrei valor financeiro para gerar contas a pagar.');
      $personId = fiscal_xml_find_or_create_supplier($pdo, $companyId, $xml);
      if ($personId <= 0) throw new RuntimeException('Não foi possível identificar/cadastrar o fornecedor.');
    }

    $itemsForStock = fiscal_xml_items($pdo, $companyId, $xmlId);
    $landedValueMap = fiscal_xml_entry_landed_value_map($xml, $itemsForStock);
    $defaultProductCosts = [];
    foreach ($itemsForStock as $costItem) {
      $costProductId = (int)($costItem['product_id'] ?? 0);
      if ($costProductId <= 0) continue;
      $costFactor = fiscal_xml_parse_decimal($costItem['conversion_factor'] ?? 1, 1.0);
      if ($costFactor <= 0) $costFactor = 1.0;
      $costQty = round((float)($costItem['quantity'] ?? 0) * $costFactor, 4);
      if ($costQty <= 0) continue;
      if (!isset($defaultProductCosts[$costProductId])) $defaultProductCosts[$costProductId] = ['qty'=>0.0,'value'=>0.0];
      $defaultProductCosts[$costProductId]['qty'] += $costQty;
      $defaultProductCosts[$costProductId]['value'] += (float)($landedValueMap[(int)($costItem['id'] ?? 0)] ?? fiscal_xml_item_landed_base_value((array)$costItem));
    }
    foreach ($defaultProductCosts as $costProductId => $costGroup) {
      $qty = (float)($costGroup['qty'] ?? 0);
      $defaultProductCosts[$costProductId] = $qty > 0 ? round((float)($costGroup['value'] ?? 0) / $qty, 4) : 0.0;
    }
    $updateStock = (int)($operation['update_stock'] ?? 0) === 1;
    $gestaoCostEnabled = fiscal_xml_gestao_enabled($pdo, $companyId);
    $hasCostColumn = $gestaoCostEnabled ? fiscal_xml_ensure_product_cost_column($pdo) : false;
    if ($updateStock) {
      require_once __DIR__ . '/stock_module.php';
      $stockCols = function_exists('stock_ensure_schema') ? stock_ensure_schema($pdo, true) : [];
      if (empty($stockCols['estoque_atual']) && !fiscal_column_exists($pdo, 'produtos', 'estoque_atual')) {
        throw new RuntimeException('Não foi possível preparar o controle de estoque dos produtos.');
      }
      $unlinked = [];
      foreach ($itemsForStock as $stockItem) {
        if ((float)($stockItem['quantity'] ?? 0) <= 0) continue;
        if ((int)($stockItem['product_id'] ?? 0) <= 0) {
          $unlinked[] = (string)($stockItem['name'] ?? ('Item #' . (int)($stockItem['item_number'] ?? 0)));
        }
      }
      if ($unlinked) {
        throw new RuntimeException('Vincule todos os itens do XML a produtos do cadastro antes de gerar entrada com estoque. Pendente(s): ' . implode(', ', array_slice($unlinked, 0, 6)) . (count($unlinked) > 6 ? '...' : ''));
      }
    }

    $created = [];
    $pdo->beginTransaction();
    try {
      if ($allowFinance) {
        $ins = $pdo->prepare('INSERT INTO finance_payables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $totalInst = count($installments);
        foreach ($installments as $i => $dup) {
          $amount = round((float)($dup['amount'] ?? 0), 2);
          if ($amount <= 0) continue;
          $due = fiscal_xml_date($dup['due_date'] ?? '') ?: ($fallbackDueDate ?: ($xml['issue_date'] ?: date('Y-m-d')));
          $inst = trim((string)($dup['number'] ?? ''));
          if ($inst === '') $inst = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$totalInst, 2, '0', STR_PAD_LEFT);
          $docNumber = 'NFe ' . (string)($xml['number'] ?? '') . '/' . (string)($xml['series'] ?? '') . ' ' . $inst;
          $description = 'Entrada XML NF-e ' . (string)($xml['number'] ?? '') . ' - ' . (string)($xml['supplier_name'] ?? '');
          $notes = 'Gerado automaticamente pelo RADAR XML. Chave: ' . (string)($xml['access_key'] ?? '');
          $entryIssueDate = $xml['entry_date'] ?: ($xml['issue_date'] ?: date('Y-m-d'));
          $ins->execute([$companyId, $personId, $chartAccountId, $costCenterId, $description, $docNumber, $inst, $entryIssueDate, $due, '', $amount, 0, 0, 0, 0, $amount, 'ABERTO', $notes]);
          $created[] = (int)$pdo->lastInsertId();
        }
        if (!$created) throw new RuntimeException('Nenhuma parcela válida foi encontrada para gerar contas a pagar.');
      }

      if ($updateStock) {
        $hasAverageCost = fiscal_column_exists($pdo, 'produtos', 'custo_medio');
        $hasLastPurchaseCost = fiscal_column_exists($pdo, 'produtos', 'custo_ultima_compra');
        $hasLastPurchaseDate = fiscal_column_exists($pdo, 'produtos', 'data_ultima_compra');
        $hasSalePrice = $updateSalePrices && fiscal_column_exists($pdo, 'produtos', 'preco');
        $selectCostParts = ['estoque_atual'];
        $selectCostParts[] = $hasCostColumn ? 'COALESCE(custo,0) AS custo' : '0 AS custo';
        $selectCostParts[] = $hasAverageCost ? 'COALESCE(custo_medio,0) AS custo_medio' : '0 AS custo_medio';
        $selectCostParts[] = $hasSalePrice ? 'COALESCE(preco,0) AS preco' : '0 AS preco';
        $selStock = $pdo->prepare('SELECT ' . implode(',', $selectCostParts) . ' FROM produtos WHERE company_id = ? AND id = ? FOR UPDATE');
        $setParts = ['estoque_atual = ?'];
        if ($hasCostColumn) $setParts[] = 'custo = ?';
        if ($hasAverageCost) $setParts[] = 'custo_medio = ?';
        if ($hasLastPurchaseCost) $setParts[] = 'custo_ultima_compra = ?';
        if ($hasLastPurchaseDate) $setParts[] = 'data_ultima_compra = ?';
        if ($hasSalePrice) $setParts[] = 'preco = ?';
        $updStock = $pdo->prepare('UPDATE produtos SET ' . implode(',', $setParts) . ' WHERE company_id = ? AND id = ?');
        $updItemStock = $pdo->prepare('UPDATE fiscal_xml_items SET stock_quantity = ?, stock_unit_cost = ?, conversion_factor = ? WHERE company_id = ? AND xml_import_id = ? AND id = ?');
        $movStock = null;
        $movWithCosts = fiscal_table_exists($pdo, 'estoque_movimentos')
          && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_unitario')
          && fiscal_column_exists($pdo, 'estoque_movimentos', 'valor_movimento')
          && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_medio_anterior')
          && fiscal_column_exists($pdo, 'estoque_movimentos', 'custo_medio_posterior');
        if (fiscal_table_exists($pdo, 'estoque_movimentos')) {
          try {
            $movStock = $movWithCosts
              ? $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, custo_unitario, valor_movimento, custo_medio_anterior, custo_medio_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)')
              : $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)');
          } catch (Throwable $e) { $movStock = null; }
        }
        foreach ($itemsForStock as $stockItem) {
          $productId = (int)($stockItem['product_id'] ?? 0);
          $qtyXml = (float)($stockItem['quantity'] ?? 0);
          if ($productId <= 0 || $qtyXml <= 0) continue;
          $factor = fiscal_xml_parse_decimal($stockItem['conversion_factor'] ?? 1, 1.0);
          if ($factor <= 0) $factor = 1.0;
          $stockQty = round($qtyXml * $factor, 4);
          if ($stockQty <= 0) continue;
          $unitCost = (float)($defaultProductCosts[$productId] ?? 0.0);
          if (isset($pricingOverrides[$productId]) && array_key_exists('cost', (array)$pricingOverrides[$productId])) {
            $unitCost = max(0.0, round((float)$pricingOverrides[$productId]['cost'], 4));
          }
          $selStock->execute([$companyId, $productId]);
          $current = $selStock->fetch(PDO::FETCH_ASSOC);
          if (!$current) throw new RuntimeException('Produto vinculado não encontrado para lançar estoque: #' . $productId);
          $before = (float)($current['estoque_atual'] ?? 0);
          $oldAverage = (float)($current['custo_medio'] ?? 0);
          if ($oldAverage <= 0) $oldAverage = (float)($current['custo'] ?? 0);
          if ($oldAverage <= 0) $oldAverage = $unitCost;
          $after = round($before + $stockQty, 4);
          $baseQty = max(0.0, $before);
          $newAverage = ($baseQty + $stockQty) > 0
            ? round((($baseQty * $oldAverage) + ($stockQty * $unitCost)) / ($baseQty + $stockQty), 4)
            : $unitCost;
          $updateValues = [$after];
          if ($hasCostColumn) $updateValues[] = $unitCost;
          if ($hasAverageCost) $updateValues[] = $newAverage;
          if ($hasLastPurchaseCost) $updateValues[] = $unitCost;
          if ($hasLastPurchaseDate) $updateValues[] = ($xml['entry_date'] ?: ($xml['issue_date'] ?: date('Y-m-d'))) . ' 00:00:00';
          if ($hasSalePrice) {
            $salePrice = (float)($current['preco'] ?? 0);
            if (isset($pricingOverrides[$productId]) && array_key_exists('price', (array)$pricingOverrides[$productId])) {
              $salePrice = max(0.0, round((float)$pricingOverrides[$productId]['price'], 2));
            }
            $updateValues[] = $salePrice;
          }
          $updateValues[] = $companyId;
          $updateValues[] = $productId;
          $updStock->execute($updateValues);
          $updItemStock->execute([$stockQty, $unitCost, $factor, $companyId, $xmlId, (int)$stockItem['id']]);
          if ($movStock) {
            $obsStock = 'Entrada por XML NF-e ' . (string)($xml['number'] ?? '') . ' - item ' . (string)($stockItem['item_number'] ?? '') . ' - fator ' . rtrim(rtrim(number_format($factor, 6, '.', ''), '0'), '.');
            if ($movWithCosts) {
              $movStock->execute([$companyId, $productId, 'entrada', 'XML_NFE', $xmlId, $stockQty, $before, $after, $unitCost, round($stockQty*$unitCost,2), $oldAverage, $newAverage, $obsStock]);
            } else {
              $movStock->execute([$companyId, $productId, 'entrada', 'XML_NFE', $xmlId, $stockQty, $before, $after, $obsStock]);
            }
          }
        }
      }

      $up = $pdo->prepare("UPDATE fiscal_xml_imports SET status='ENTRADA_GERADA', operation_id=?, finance_payable_ids=?, generated_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?");
      $up->execute([$operationId, json_encode($created), $companyId, $xmlId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return $created;
  }
}


if (!function_exists('fiscal_company_report_identity')) {
  function fiscal_company_report_identity(PDO $pdo, int $companyId): array {
    $out = [
      'name' => 'EMPRESA', 'document' => '', 'ie' => '', 'uf' => '', 'city' => '',
      'address' => '', 'number' => '', 'complement' => '', 'district' => '', 'zip' => '',
      'contact' => '', 'phone' => '', 'fax' => ''
    ];

    // 1) Primeiro lê a configuração fiscal da empresa. É essa configuração que deve
    // alimentar Registro 10/11, emissão fiscal, DF-e e demais rotinas que dependem do CNPJ.
    try {
      if (function_exists('fiscal_company_config')) {
        $cfg = fiscal_company_config($pdo, $companyId);
        $out['name'] = (string)(trim((string)($cfg['company_name'] ?? '')) ?: ($cfg['company_trade_name'] ?? $out['name']));
        $out['document'] = fiscal_xml_digits($cfg['company_cnpj'] ?? '');
        $out['ie'] = (string)($cfg['state_registration'] ?? '');
        $out['uf'] = strtoupper((string)($cfg['uf'] ?? ''));
        $out['city'] = (string)($cfg['company_city'] ?? '');
        $out['address'] = (string)($cfg['company_address'] ?? '');
        $out['number'] = (string)($cfg['company_number'] ?? '0');
        $out['complement'] = (string)($cfg['company_complement'] ?? '');
        $out['district'] = (string)($cfg['company_district'] ?? '');
        $out['zip'] = fiscal_xml_digits($cfg['company_zip'] ?? '');
        $out['contact'] = (string)($cfg['company_contact'] ?? $out['name']);
        $out['phone'] = fiscal_xml_digits($cfg['company_phone'] ?? '');
        $out['fax'] = fiscal_xml_digits($cfg['company_fax'] ?? '');
      }
    } catch (Throwable $e) {}

    // 2) Completa campos vazios com a tabela SaaS/landing page, preservando os dados fiscais
    // informados explicitamente em Configurações Fiscais.
    try {
      if (fiscal_table_exists($pdo, 'companies')) {
        $wanted = [
          'name','razao_social','fantasy_name','cnpj','document','ie','inscricao_estadual','state','uf','city','cidade',
          'address','endereco','logradouro','number','numero','complement','complemento','district','bairro','zip','cep',
          'phone','telefone','whatsapp','contact','contato','fax'
        ];
        $cols = [];
        foreach ($wanted as $c) if (fiscal_column_exists($pdo, 'companies', $c)) $cols[] = $c;
        if ($cols) {
          $st = $pdo->prepare('SELECT `' . implode('`,`', $cols) . '` FROM companies WHERE id = ? LIMIT 1');
          $st->execute([$companyId]);
          $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
          if (trim((string)$out['name']) === '' || $out['name'] === 'EMPRESA') $out['name'] = (string)($r['razao_social'] ?? $r['name'] ?? $r['fantasy_name'] ?? $out['name']);
          if ($out['document'] === '') $out['document'] = fiscal_xml_digits($r['cnpj'] ?? $r['document'] ?? '');
          if (trim((string)$out['ie']) === '') $out['ie'] = (string)($r['ie'] ?? $r['inscricao_estadual'] ?? '');
          if (trim((string)$out['uf']) === '') $out['uf'] = strtoupper((string)($r['uf'] ?? $r['state'] ?? ''));
          if (trim((string)$out['city']) === '') $out['city'] = (string)($r['city'] ?? $r['cidade'] ?? '');
          if (trim((string)$out['address']) === '') $out['address'] = (string)($r['address'] ?? $r['endereco'] ?? $r['logradouro'] ?? '');
          if (trim((string)$out['number']) === '' || $out['number'] === '0') $out['number'] = (string)($r['number'] ?? $r['numero'] ?? '0');
          if (trim((string)$out['complement']) === '') $out['complement'] = (string)($r['complement'] ?? $r['complemento'] ?? '');
          if (trim((string)$out['district']) === '') $out['district'] = (string)($r['district'] ?? $r['bairro'] ?? '');
          if ($out['zip'] === '') $out['zip'] = fiscal_xml_digits($r['zip'] ?? $r['cep'] ?? '');
          if (trim((string)$out['contact']) === '') $out['contact'] = (string)($r['contact'] ?? $r['contato'] ?? $out['name']);
          if ($out['phone'] === '') $out['phone'] = fiscal_xml_digits($r['phone'] ?? $r['telefone'] ?? $r['whatsapp'] ?? '');
          if ($out['fax'] === '') $out['fax'] = fiscal_xml_digits($r['fax'] ?? '');
        }
      }
    } catch (Throwable $e) {}
    if (trim((string)$out['number']) === '') $out['number'] = '0';
    if (trim((string)$out['contact']) === '') $out['contact'] = (string)$out['name'];
    return $out;
  }
}

if (!function_exists('fiscal_report_period')) {
  function fiscal_report_period(array $post): array {
    $month = trim((string)($post['period_month'] ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})$/', $month, $m)) {
      $ini = $m[1] . '-' . $m[2] . '-01';
      $fim = date('Y-m-t', strtotime($ini));
      return [$ini, $fim, $m[2] . '/' . $m[1]];
    }
    $ini = fiscal_xml_date($post['ini'] ?? '') ?: date('Y-m-01');
    $fim = fiscal_xml_date($post['fim'] ?? '') ?: date('Y-m-t');
    return [$ini, $fim, fiscal_date_br($ini, '/') . ' a ' . fiscal_date_br($fim, '/')];
  }
}

if (!function_exists('fiscal_report_entries')) {
  function fiscal_report_entries(PDO $pdo, int $companyId, string $ini, string $fim): array {
    fiscal_xml_ensure_schema($pdo);
    $sql = "SELECT x.*, COALESCE(x.entry_date, x.issue_date) AS report_date
      FROM fiscal_xml_imports x
      WHERE x.company_id = ?
        AND COALESCE(x.entry_date, x.issue_date) BETWEEN ? AND ?
        AND COALESCE(x.include_fiscal_reports,1) = 1
        AND COALESCE(x.number,'') <> '0'
      ORDER BY report_date ASC, x.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $ini, $fim]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_report_items')) {
  function fiscal_report_items(PDO $pdo, int $companyId, int $xmlId): array {
    fiscal_xml_ensure_schema($pdo);
    fiscal_ensure_schema($pdo);

    $select = ['i.*'];
    $joins = [];

    if (fiscal_table_exists($pdo, 'produtos')) {
      $select[] = 'p.id AS linked_product_id';
      $select[] = fiscal_column_exists($pdo, 'produtos', 'nome') ? 'p.nome AS linked_product_name' : 'NULL AS linked_product_name';
      $select[] = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque') ? 'p.unidade_estoque AS linked_product_unit' : 'NULL AS linked_product_unit';
      $select[] = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird') ? 'p.codigo_firebird AS linked_product_code' : 'NULL AS linked_product_code';
      $joins[] = 'LEFT JOIN produtos p ON p.company_id = i.company_id AND p.id = i.product_id';
    } else {
      $select[] = 'NULL AS linked_product_id';
      $select[] = 'NULL AS linked_product_name';
      $select[] = 'NULL AS linked_product_unit';
      $select[] = 'NULL AS linked_product_code';
    }

    if (fiscal_table_exists($pdo, 'fiscal_product_tax')) {
      $select[] = 'pt.ncm AS linked_product_ncm';
      $select[] = 'pt.cest AS linked_product_cest';
      $select[] = 'pt.origem AS linked_product_origem';
      $select[] = 'pt.icms_cst AS linked_product_icms_cst';
      $select[] = 'pt.icms_csosn AS linked_product_icms_csosn';
      $select[] = 'pt.ipi_cst AS linked_product_ipi_cst';
      $select[] = 'pt.pis_cst AS linked_product_pis_cst';
      $select[] = 'pt.cofins_cst AS linked_product_cofins_cst';
      $select[] = 'pt.icms_rate AS linked_product_icms_rate';
      $select[] = 'pt.ipi_rate AS linked_product_ipi_rate';
      $joins[] = 'LEFT JOIN fiscal_product_tax pt ON pt.company_id = i.company_id AND pt.product_id = i.product_id';
    } else {
      foreach (['linked_product_ncm','linked_product_cest','linked_product_origem','linked_product_icms_cst','linked_product_icms_csosn','linked_product_ipi_cst','linked_product_pis_cst','linked_product_cofins_cst','linked_product_icms_rate','linked_product_ipi_rate'] as $alias) {
        $select[] = 'NULL AS ' . $alias;
      }
    }

    $sql = 'SELECT ' . implode(', ', $select) . ' FROM fiscal_xml_items i ' . implode(' ', $joins) . ' WHERE i.company_id = ? AND i.xml_import_id = ? ORDER BY i.item_number ASC, i.id ASC';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $xmlId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_report_item_system_payload')) {
  function fiscal_report_item_system_payload(array $item): array {
    $linkedId = (int)($item['linked_product_id'] ?? ($item['product_id'] ?? 0));

    $code = trim((string)($item['linked_product_code'] ?? ''));
    if ($code === '' || $code === '0') $code = $linkedId > 0 ? (string)$linkedId : '';
    if ($code === '') $code = trim((string)($item['product_code'] ?? ''));
    if ($code === '' || $code === '0') $code = (string)($item['id'] ?? '');

    $name = trim((string)($item['linked_product_name'] ?? ''));
    if ($name === '') $name = trim((string)($item['name'] ?? ''));

    $unit = strtoupper(trim((string)($item['linked_product_unit'] ?? '')));
    if ($unit === '') $unit = strtoupper(trim((string)($item['unit'] ?? '')));
    if ($unit === '') $unit = 'UN';

    $ncm = fiscal_xml_digits($item['linked_product_ncm'] ?? '');
    if ($ncm === '') $ncm = fiscal_xml_digits($item['ncm'] ?? '');

    $cest = fiscal_xml_digits($item['linked_product_cest'] ?? '');
    if ($cest === '') $cest = fiscal_xml_digits($item['cest'] ?? '');

    return [
      'product_id' => $linkedId,
      'code' => $code,
      'name' => $name,
      'unit' => $unit,
      'ncm' => $ncm,
      'cest' => $cest,
      'icms_cst_out' => fiscal_xml_tax_code($item['linked_product_icms_cst'] ?? ''),
      'icms_csosn_out' => fiscal_xml_tax_code($item['linked_product_icms_csosn'] ?? ''),
      'origem_out' => fiscal_xml_tax_code($item['linked_product_origem'] ?? '', 2),
      'ipi_rate' => (float)($item['linked_product_ipi_rate'] ?? 0),
      'icms_rate' => (float)($item['linked_product_icms_rate'] ?? 0),
    ];
  }
}


if (!function_exists('fiscal_report_item_product_value')) {
  function fiscal_report_item_product_value(array $item): float {
    $value = (float)($item['total_value'] ?? 0);
    if ($value <= 0) {
      $qty = (float)($item['quantity'] ?? 0);
      $unit = (float)($item['unit_value'] ?? 0);
      if ($qty > 0 && $unit > 0) $value = $qty * $unit;
    }
    return round($value, 2);
  }
}

if (!function_exists('fiscal_report_item_accessory_value')) {
  function fiscal_report_item_accessory_value(array $item): float {
    $freight = (float)($item['freight_value'] ?? 0);
    $insurance = (float)($item['insurance_value'] ?? 0);
    $other = (float)($item['other_value'] ?? 0);
    $discount = (float)($item['discount_value'] ?? 0);
    return round($freight + $insurance + $other - $discount, 2);
  }
}

if (!function_exists('fiscal_report_item_fcp_st_value')) {
  function fiscal_report_item_fcp_st_value(array $item): float {
    $raw = trim((string)($item['tax_json'] ?? ''));
    if ($raw === '') return 0.0;
    $data = json_decode($raw, true);
    if (!is_array($data)) return 0.0;
    foreach (['fcp_st_amount','vFCPST'] as $key) {
      if (isset($data[$key]) && is_numeric($data[$key])) return round(max(0.0, (float)$data[$key]), 2);
    }
    return 0.0;
  }
}

if (!function_exists('fiscal_report_item_tax_addition_value')) {
  function fiscal_report_item_tax_addition_value(array $item): float {
    return round(
      (float)($item['ipi_value'] ?? 0)
      + (float)($item['icms_st_value'] ?? 0)
      + fiscal_report_item_fcp_st_value($item),
      2
    );
  }
}

if (!function_exists('fiscal_report_item_document_value')) {
  function fiscal_report_item_document_value(array $item): float {
    $value = fiscal_report_item_product_value($item)
      + fiscal_report_item_accessory_value($item)
      + fiscal_report_item_tax_addition_value($item);
    return round(max(0, $value), 2);
  }
}

if (!function_exists('fiscal_report_adjust_sintegra_groups_to_invoice')) {
  function fiscal_report_adjust_sintegra_groups_to_invoice(array &$groups50, array $entry): void {
    $invoiceTotal = round((float)($entry['total_invoice'] ?? 0), 2);
    if ($invoiceTotal <= 0 || !$groups50) return;

    $groupsTotal = 0.0;
    foreach ($groups50 as $g) $groupsTotal += (float)($g['total'] ?? 0);
    $groupsTotal = round($groupsTotal, 2);
    $diff = round($invoiceTotal - $groupsTotal, 2);
    if (abs($diff) < 0.01) return;

    $targetKey = array_key_first($groups50);
    foreach ($groups50 as $key => $g) {
      if (abs((float)($g['total'] ?? 0)) > abs((float)($groups50[$targetKey]['total'] ?? 0))) $targetKey = $key;
    }

    $groups50[$targetKey]['total'] = round((float)($groups50[$targetKey]['total'] ?? 0) + $diff, 2);
    $bucket = ((float)($groups50[$targetKey]['isenta'] ?? 0) > 0 && (float)($groups50[$targetKey]['outras'] ?? 0) <= 0) ? 'isenta' : 'outras';
    $groups50[$targetKey][$bucket] = round(max(0, (float)($groups50[$targetKey][$bucket] ?? 0) + $diff), 2);
  }
}


if (!function_exists('fiscal_report_xml_money_tag')) {
  function fiscal_report_xml_money_tag(string $raw, string $tag): float {
    if ($raw === '') return 0.0;
    $tagQ = preg_quote($tag, '/');
    if (preg_match('/<(?:[A-Za-z0-9_]+:)?' . $tagQ . '\b[^>]*>([^<]*)<\/(?:[A-Za-z0-9_]+:)?' . $tagQ . '>/i', $raw, $m)) return fiscal_xml_money($m[1] ?? '0');
    return 0.0;
  }
}

if (!function_exists('fiscal_report_entry_xml_totals')) {
  function fiscal_report_entry_xml_totals(array $entry): array {
    $raw = (string)($entry['xml_content'] ?? '');
    if ($raw === '') {
      return ['product'=>0.0,'invoice'=>0.0,'ipi'=>0.0,'icms_st_base'=>0.0,'icms_st_value'=>0.0,'fcp_st_value'=>0.0,'freight'=>0.0,'insurance'=>0.0,'other'=>0.0,'discount'=>0.0,'ibs_uf_value'=>0.0,'ibs_mun_value'=>0.0,'cbs_value'=>0.0];
    }
    $total = '';
    $ibsCbsTotal = '';
    if (preg_match('/<(?:[A-Za-z0-9_]+:)?ICMSTot\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?ICMSTot>/is', $raw, $m)) $total = (string)($m[1] ?? '');
    if (preg_match('/<(?:[A-Za-z0-9_]+:)?IBSCBSTot\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?IBSCBSTot>/is', $raw, $m)) $ibsCbsTotal = (string)($m[1] ?? '');
    return [
      'product' => fiscal_report_xml_money_tag($total, 'vProd'),
      'invoice' => fiscal_report_xml_money_tag($total, 'vNF'),
      'ipi' => fiscal_report_xml_money_tag($total, 'vIPI'),
      'icms_st_base' => fiscal_report_xml_money_tag($total, 'vBCST'),
      'icms_st_value' => fiscal_report_xml_money_tag($total, 'vST'),
      'fcp_st_value' => fiscal_report_xml_money_tag($total, 'vFCPST'),
      'freight' => fiscal_report_xml_money_tag($total, 'vFrete'),
      'insurance' => fiscal_report_xml_money_tag($total, 'vSeg'),
      'other' => fiscal_report_xml_money_tag($total, 'vOutro'),
      'discount' => fiscal_report_xml_money_tag($total, 'vDesc'),
      'ibs_uf_value' => fiscal_report_xml_money_tag($ibsCbsTotal, 'vIBSUF'),
      'ibs_mun_value' => fiscal_report_xml_money_tag($ibsCbsTotal, 'vIBSMun'),
      'cbs_value' => fiscal_report_xml_money_tag($ibsCbsTotal, 'vCBS'),
    ];
  }
}

if (!function_exists('fiscal_report_entry_xml_item_st_map')) {
  function fiscal_report_entry_xml_item_st_map(array $entry): array {
    $raw = (string)($entry['xml_content'] ?? '');
    $out = [];
    if ($raw === '') return $out;
    if (!preg_match_all('/<(?:[A-Za-z0-9_]+:)?det\b([^>]*)>(.*?)<\/(?:[A-Za-z0-9_]+:)?det>/is', $raw, $matches, PREG_SET_ORDER)) return $out;
    foreach ($matches as $idx => $m) {
      $attrs = (string)($m[1] ?? '');
      $body = (string)($m[2] ?? '');
      $itemNo = $idx + 1;
      if (preg_match('/\bnItem\s*=\s*(["\'])(.*?)\1/i', $attrs, $am)) $itemNo = max(1, (int)($am[2] ?? $itemNo));
      $icms = '';
      if (preg_match('/<(?:[A-Za-z0-9_]+:)?ICMS\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?ICMS>/is', $body, $im)) $icms = (string)($im[1] ?? '');
      $out[$itemNo] = [
        'icms_st_base' => fiscal_report_xml_money_tag($icms, 'vBCST'),
        'icms_st_value' => fiscal_report_xml_money_tag($icms, 'vICMSST'),
        'fcp_st_value' => fiscal_report_xml_money_tag($icms, 'vFCPST'),
      ];
    }
    return $out;
  }
}

if (!function_exists('fiscal_sintegra_record_with_situacao_126')) {
  function fiscal_sintegra_record_with_situacao_126(string $body, string $situacao = 'N'): string {
    $situacao = strtoupper(substr(trim($situacao), 0, 1));
    if ($situacao === '') $situacao = 'N';
    // Em alguns registros complementares o campo Situação fica na posição 126.
    // O corpo é preenchido com brancos até a posição 125 e a situação é gravada como último caractere.
    if (strlen($body) > 125) $body = substr($body, 0, 125);
    return str_pad($body, 125, ' ', STR_PAD_RIGHT) . $situacao;
  }
}

if (!function_exists('fiscal_sintegra_record51')) {
  function fiscal_sintegra_record51(string $doc, string $ie, string $date, string $uf, string $model, string $series, string $number, string $cfop, float $total, float $ipi, string $situacao = 'N'): string {
    // Registro 51 não possui campo Modelo no leiaute do SINTEGRA.
    // Ordem: 51 + CNPJ/CPF + IE + Data + UF + Série + Número + CFOP
    // + Valor total + Valor IPI + Isentas/Não tributadas IPI + Outras IPI + brancos + Situação na posição 126.
    $body = '51' . $doc . $ie . $date . $uf . $series . $number . fiscal_sintegra_n($cfop, 4)
      . fiscal_sintegra_n($total, 13, 2)
      . fiscal_sintegra_n($ipi, 13, 2)
      . fiscal_sintegra_n(0, 13, 2)
      . fiscal_sintegra_n(0, 13, 2);
    return fiscal_sintegra_record_with_situacao_126($body, $situacao);
  }
}

if (!function_exists('fiscal_sintegra_record53')) {
  function fiscal_sintegra_record53(string $doc, string $ie, string $date, string $uf, string $model, string $series, string $number, string $cfop, float $baseSt, float $icmsSt, float $despAcessorias = 0.0, string $emitente = 'T', string $situacao = 'N'): string {
    $emitente = strtoupper(substr(trim($emitente), 0, 1));
    if (!in_array($emitente, ['P', 'T'], true)) $emitente = 'T';
    $situacao = strtoupper(substr(trim($situacao), 0, 1));
    if ($situacao === '' || !in_array($situacao, ['S','N','E','X','2','4'], true)) $situacao = 'N';
    // Registro 53: a Situação fica na posição 96, não na 126.
    // Ordem: 53 + CNPJ/CPF + IE + Data + UF + Modelo + Série + Número + CFOP
    // + Emitente + Base ST + ICMS retido + Despesas acessórias + Situação + brancos.
    $body = '53' . $doc . $ie . $date . $uf . fiscal_sintegra_n($model, 2) . $series . $number . fiscal_sintegra_n($cfop, 4)
      . $emitente
      . fiscal_sintegra_n($baseSt, 13, 2)
      . fiscal_sintegra_n($icmsSt, 13, 2)
      . fiscal_sintegra_n($despAcessorias, 13, 2)
      . $situacao;
    if (strlen($body) > 126) $body = substr($body, 0, 126);
    return str_pad($body, 126, ' ', STR_PAD_RIGHT);
  }
}

if (!function_exists('fiscal_report_txt_download')) {
  function fiscal_report_txt_download(string $filename, string $content): void {
    header('Content-Type: text/plain; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="' . str_replace('"', '', $filename) . '"');
    echo mb_convert_encoding($content, 'ISO-8859-1', 'UTF-8');
    exit;
  }
}

if (!function_exists('fiscal_sintegra_ascii')) {
  function fiscal_sintegra_ascii($value): string {
    $s = (string)$value;
    $s = str_replace(["\r", "\n", "\t"], ' ', $s);
    if (function_exists('iconv')) {
      $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s);
      if ($converted !== false) $s = $converted;
    }
    $s = preg_replace('/[^\x20-\x7E]/', ' ', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
  }
}

if (!function_exists('fiscal_sintegra_x')) {
  function fiscal_sintegra_x($value, int $len): string {
    $s = fiscal_sintegra_ascii($value);
    if (strlen($s) > $len) $s = substr($s, 0, $len);
    return str_pad($s, $len, ' ', STR_PAD_RIGHT);
  }
}

if (!function_exists('fiscal_sintegra_ie')) {
  /**
   * Formata a Inscrição Estadual do participante para os registros SINTEGRA.
   * Quando o destinatário/remetente não possui IE (por exemplo, pessoa física
   * identificada por CPF), o Manual do SINTEGRA determina a expressão ISENTO,
   * alinhada à esquerda e completada com espaços no campo de 14 posições.
   */
  function fiscal_sintegra_ie($value): string {
    $ie = strtoupper(trim(fiscal_sintegra_ascii($value)));
    if ($ie === '') $ie = 'ISENTO';
    return fiscal_sintegra_x($ie, 14);
  }
}

if (!function_exists('fiscal_sintegra_n')) {
  function fiscal_sintegra_n($value, int $len, int $decimals = 0): string {
    if ($decimals > 0) {
      $num = is_numeric($value) ? (float)$value : fiscal_xml_money($value);
      $digits = (string)(int)round($num * (10 ** $decimals));
    } else {
      $digits = fiscal_xml_digits($value);
    }
    if ($digits === '') $digits = '0';
    if (strlen($digits) > $len) $digits = substr($digits, -$len);
    return str_pad($digits, $len, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('fiscal_sintegra_date')) {
  function fiscal_sintegra_date($value): string {
    $d = fiscal_xml_date($value);
    return $d ? str_replace('-', '', $d) : str_repeat('0', 8);
  }
}

if (!function_exists('fiscal_sintegra_record')) {
  function fiscal_sintegra_record(array $parts): string {
    $line = implode('', $parts);
    if (strlen($line) > 126) return substr($line, 0, 126);
    return str_pad($line, 126, ' ', STR_PAD_RIGHT);
  }
}

if (!function_exists('fiscal_sintegra_model')) {
  function fiscal_sintegra_model($model): string {
    $m = fiscal_xml_digits($model);
    if ($m === '') return '01';
    if (strlen($m) === 1) $m = '0' . $m;
    return substr($m, -2);
  }
}

if (!function_exists('fiscal_sintegra_series')) {
  function fiscal_sintegra_series($series): string {
    $s = fiscal_sintegra_ascii($series);
    if (strtoupper($s) === 'MANUAL' || $s === '') $s = 'U';
    // Séries numéricas devem sair com 3 posições numéricas. Ex.: série 1 => 001.
    // Isso evita rejeição de série em validadores que não aceitam preenchimento com espaços.
    if (preg_match('/^\d+$/', $s)) return fiscal_sintegra_n($s, 3);
    return fiscal_sintegra_x($s, 3);
  }
}

if (!function_exists('fiscal_sintegra_record61_series')) {
  function fiscal_sintegra_record61_series($series): string {
    $s = strtoupper(trim(fiscal_sintegra_ascii($series)));
    // No Registro 61 o validador aceita somente série em branco, D, U ou DU.
    // Para NFC-e/série numérica, usa-se U (série única) para evitar advertência de série inválida.
    if ($s === 'D' || $s === 'U' || $s === 'DU') return fiscal_sintegra_x($s, 3);
    if ($s === '') return fiscal_sintegra_x('', 3);
    if (preg_match('/^0*1$/', $s) || preg_match('/^\d+$/', $s)) return fiscal_sintegra_x('U', 3);
    return fiscal_sintegra_x('', 3);
  }
}

if (!function_exists('fiscal_sintegra_number')) {
  function fiscal_sintegra_number($number): string {
    return fiscal_sintegra_n($number, 6);
  }
}

if (!function_exists('fiscal_sintegra_item_cst')) {
  function fiscal_sintegra_item_cst(array $item, array $tax): string {
    $origem = fiscal_xml_digits($tax['entry_origem'] ?? ($item['origem'] ?? ''));
    if ($origem === '') $origem = '0';
    $origem = substr($origem, -1);
    $cst = fiscal_xml_digits($tax['entry_icms_cst'] ?? '');
    if ($cst === '') $cst = fiscal_xml_digits($item['icms_cst'] ?? '');
    if ($cst === '') $cst = fiscal_xml_digits($item['icms_csosn'] ?? '');
    if ($cst === '') $cst = '00';
    if (strlen($cst) >= 3) return substr($cst, -3);
    return $origem . str_pad($cst, 2, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('fiscal_sintegra_sort_lines')) {
  /**
   * Ordena os registros do arquivo conforme o item 8.1 do Manual de Orientação
   * do Convênio ICMS 57/95. O Registro 10 permanece em primeiro, o 11 em
   * segundo e o Registro 90 é acrescentado somente depois desta ordenação.
   *
   * Tipos atualmente gerados pelo Nexa: 10, 11, 50, 51, 53, 54, 61, 61R e 75.
   * A função também possui chaves para outros tipos usuais, evitando que uma
   * futura ampliação volte a misturar conjuntos de registros.
   *
   * @param array<int,string> $lines Linhas de 126 posições, ainda sem o tipo 90.
   * @return array<int,string>
   */
  function fiscal_sintegra_sort_lines(array $lines): array {
    $records10 = [];
    $records11 = [];
    $data = [];

    foreach (array_values($lines) as $seq => $line) {
      $line = (string)$line;
      $type = substr($line, 0, 2);
      if ($type === '10') {
        $records10[] = $line;
        continue;
      }
      if ($type === '11') {
        $records11[] = $line;
        continue;
      }
      $data[] = ['line' => $line, 'seq' => (int)$seq];
    }

    $rank = static function(string $line): int {
      $type = substr($line, 0, 2);
      if (in_array($type, ['50', '51', '53'], true)) return 10;
      if (in_array($type, ['54', '56'], true)) return 20;
      if ($type === '55') return 30;
      if ($type === '57') return 40;
      if ($type === '60') return 50;
      if ($type === '61') return 60;
      if (in_array($type, ['70', '71'], true)) return 70;
      if ($type === '74') return 80;
      if ($type === '75') return 90;
      if (in_array($type, ['76', '77'], true)) return 100;
      if (in_array($type, ['85', '86'], true)) return 110;
      return 900;
    };

    $key = static function(string $line): string {
      $type = substr($line, 0, 2);

      // Tipos 50, 51 e 53: tipo do registro e data (posições 31 a 38).
      if (in_array($type, ['50', '51', '53'], true)) {
        return $type . '|' . substr($line, 30, 8);
      }

      // Tipos 54 e 56: CNPJ, série, número do documento e número do item.
      if (in_array($type, ['54', '56'], true)) {
        return substr($line, 2, 14) . '|'
          . substr($line, 18, 3) . '|'
          . substr($line, 21, 6) . '|'
          . substr($line, 34, 3) . '|'
          . $type;
      }

      if ($type === '55') return substr($line, 30, 8);

      if ($type === '57') {
        return substr($line, 2, 14) . '|'
          . substr($line, 32, 3) . '|'
          . substr($line, 35, 6) . '|'
          . substr($line, 48, 3);
      }

      if ($type === '60') {
        $subtype = substr($line, 2, 1);
        if ($subtype === 'R') {
          return '1|R|' . substr($line, 3, 6) . '|' . substr($line, 9, 14);
        }
        // M, A, D e I: data, série de fabricação e ordem do subtipo.
        $order = ['M' => '0', 'A' => '1', 'D' => '2', 'I' => '3'];
        return '0|' . substr($line, 3, 8) . '|'
          . substr($line, 11, 20) . '|'
          . ($order[$subtype] ?? '9');
      }

      if ($type === '61') {
        // 61 normal vem antes de 61R. No 61 normal a data ocupa 31 a 38;
        // no 61R o código do produto ocupa 10 a 23.
        if (substr($line, 0, 3) === '61R') {
          return '1|' . substr($line, 9, 14);
        }
        return '0|' . substr($line, 30, 8);
      }

      if (in_array($type, ['70', '71'], true)) {
        return $type . '|' . substr($line, 30, 8);
      }

      if ($type === '74') return substr($line, 2, 8) . '|' . substr($line, 10, 14);
      if ($type === '75') return substr($line, 18, 14);

      if ($type === '76') {
        return $type . '|' . substr($line, 51, 8) . '|' . substr($line, 36, 10);
      }

      if ($type === '77') {
        return substr($line, 2, 14) . '|'
          . substr($line, 18, 2) . '|'
          . substr($line, 20, 2) . '|'
          . substr($line, 22, 10) . '|'
          . substr($line, 37, 3);
      }

      if ($type === '85') {
        return $type . '|' . substr($line, 13, 8) . '|'
          . substr($line, 2, 11) . '|' . substr($line, 94, 8);
      }

      if ($type === '86') {
        return $type . '|' . substr($line, 14, 8) . '|'
          . substr($line, 2, 12) . '|' . substr($line, 58, 8);
      }

      return $type . '|' . $line;
    };

    usort($data, static function(array $a, array $b) use ($rank, $key): int {
      $lineA = (string)$a['line'];
      $lineB = (string)$b['line'];
      $rankCompare = $rank($lineA) <=> $rank($lineB);
      if ($rankCompare !== 0) return $rankCompare;

      $keyCompare = strcmp($key($lineA), $key($lineB));
      if ($keyCompare !== 0) return $keyCompare;

      // Desempate estável: mantém a sequência original para chaves iguais.
      return ((int)$a['seq']) <=> ((int)$b['seq']);
    });

    return array_merge(
      $records10,
      $records11,
      array_map(static fn(array $row): string => (string)$row['line'], $data)
    );
  }
}

if (!function_exists('fiscal_sintegra_record90')) {
  function fiscal_sintegra_record90(array $company, array $counts, int $totalRecords, int $records90 = 1): string {
    $body = '90' . fiscal_sintegra_n($company['document'] ?: '0', 14) . fiscal_sintegra_x($company['ie'] ?? '', 14);
    ksort($counts, SORT_STRING);
    foreach ($counts as $type => $count) {
      if ((int)$count <= 0) continue;
      $body .= fiscal_sintegra_n($type, 2) . fiscal_sintegra_n($count, 8);
    }
    $body .= fiscal_sintegra_n('99', 2) . fiscal_sintegra_n($totalRecords, 8);
    if (strlen($body) > 125) $body = substr($body, 0, 125);
    return str_pad($body, 125, ' ', STR_PAD_RIGHT) . fiscal_sintegra_n($records90, 1);
  }
}

if (!function_exists('fiscal_report_nfce_documents')) {
  function fiscal_report_nfce_documents(PDO $pdo, int $companyId, string $ini, string $fim, bool $includeCancelled = true): array {
    if (!fiscal_table_exists($pdo, 'fiscal_documents')) return [];
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    $statuses = $includeCancelled
      ? "('AUTORIZADA','CANCELADA','CANCELADO')"
      : "('AUTORIZADA')";
    $statusCodes = $includeCancelled
      ? "('100','150','101','151','135','155')"
      : "('100','150')";
    // Evita carregar xml_raw (LONGTEXT) em relatórios/SINTEGRA/SPED. Esses fluxos
    // utilizam somente os campos fiscais resumidos abaixo.
    $sql = "SELECT fd.id,fd.company_id,fd.direction,fd.model,fd.series,fd.number,fd.access_key,
                    fd.status,fd.sefaz_status_code,fd.issue_datetime,fd.competence_date,fd.created_at,
                    fd.total_products,fd.total_discount,fd.total_other,fd.total_document,
                    DATE(COALESCE(fd.issue_datetime, fd.competence_date, fd.created_at)) AS report_date
              FROM fiscal_documents fd
             WHERE fd.company_id = ?
               AND UPPER(COALESCE(fd.direction,'')) = 'SAIDA'
               AND fd.model = '65'
               AND COALESCE(fd.number,0) > 0
               AND DATE(COALESCE(fd.issue_datetime, fd.competence_date, fd.created_at)) BETWEEN ? AND ?
               AND (UPPER(COALESCE(fd.status,'')) IN $statuses OR COALESCE(fd.sefaz_status_code,'') IN $statusCodes)
             ORDER BY report_date ASC, fd.series ASC, fd.number ASC, fd.id ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId, $ini, $fim]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_report_nfe_is_own_issued_document')) {
  /**
   * Identifica NF-e modelo 55 emitida pela própria empresa que deve integrar o SINTEGRA.
   *
   * As vendas e devoluções para fornecedor normalmente ficam como SAIDA. Já a devolução
   * de cliente é uma NF-e própria de ENTRADA (tpNF=0 / finNFe=4), por isso não pode ser
   * descartada apenas pelo campo direction.
   */
  function fiscal_report_nfe_is_own_issued_document(array $doc): bool {
    $direction = strtoupper(trim((string)($doc['direction'] ?? '')));
    if ($direction === 'SAIDA') return true;
    if ($direction !== 'ENTRADA') return false;

    $sourceType = strtoupper(trim((string)($doc['source_type'] ?? '')));
    $nature = strtoupper(fiscal_sintegra_ascii((string)($doc['operation_nature'] ?? '')));
    $xml = (string)($doc['xml_raw'] ?? '');

    if (str_contains($sourceType, 'DEVOLUCAO') || $sourceType === 'DEV_CLIENTE') return true;
    if (str_contains($nature, 'DEVOLUCAO')) return true;

    // Fallback para documentos antigos em que a origem/natureza não foi gravada corretamente.
    return $xml !== '' && (bool)preg_match(
      '~<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?finNFe\b[^>]*>\s*4\s*</(?:[A-Za-z_][A-Za-z0-9_.-]*:)?finNFe>~i',
      $xml
    );
  }
}

if (!function_exists('fiscal_report_nfe_documents')) {
  /**
   * NF-e próprias usadas no SINTEGRA: vendas, devoluções para fornecedor e devoluções
   * de cliente emitidas como NF-e de entrada. Somente documentos autorizados entram
   * quando $includeCancelled=false.
   */
  function fiscal_report_nfe_documents(PDO $pdo, int $companyId, string $ini, string $fim, bool $includeCancelled = true): array {
    if (!fiscal_table_exists($pdo, 'fiscal_documents')) return [];
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    $statuses = $includeCancelled
      ? "('AUTORIZADA','AUTORIZADO','CANCELADA','CANCELADO')"
      : "('AUTORIZADA','AUTORIZADO')";
    $statusCodes = $includeCancelled
      ? "('100','150','101','151','135','155')"
      : "('100','150')";
    $sql = "SELECT fd.id,fd.direction,fd.series,fd.number,fd.issue_datetime,fd.competence_date,fd.created_at,
      fd.total_document,fd.source_type,fd.operation_nature,
      CASE WHEN UPPER(COALESCE(fd.direction,''))='ENTRADA' THEN fd.xml_raw ELSE NULL END AS xml_raw,
      DATE(COALESCE(fd.issue_datetime,fd.competence_date,fd.created_at)) AS report_date,
      (SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_doc,
      (SELECT fp.state_registration FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_ie,
      (SELECT fp.state FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_uf,
      (SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_name
      FROM fiscal_documents fd
      WHERE fd.company_id=?
        AND UPPER(COALESCE(fd.direction,'')) IN ('ENTRADA','SAIDA')
        AND fd.model='55'
        AND COALESCE(fd.number,0)>0
        AND DATE(COALESCE(fd.issue_datetime,fd.competence_date,fd.created_at)) BETWEEN ? AND ?
        AND (UPPER(COALESCE(fd.status,'')) IN {$statuses} OR COALESCE(fd.sefaz_status_code,'') IN {$statusCodes})
      ORDER BY report_date,CAST(fd.series AS UNSIGNED),fd.series,fd.number,fd.id";
    $st=$pdo->prepare($sql); $st->execute([$companyId,$ini,$fim]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return array_values(array_filter($rows, 'fiscal_report_nfe_is_own_issued_document'));
  }
}

if (!function_exists('fiscal_report_nfce_is_cancelled')) {
  function fiscal_report_nfce_is_cancelled(array $doc): bool {
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    $code = trim((string)($doc['sefaz_status_code'] ?? ''));
    return in_array($status, ['CANCELADA','CANCELADO'], true) || in_array($code, ['101','151','135','155'], true);
  }
}

if (!function_exists('fiscal_report_nfce_items')) {
  function fiscal_report_nfce_items(PDO $pdo, int $companyId, int $docId): array {
    if (!fiscal_table_exists($pdo, 'fiscal_document_items')) return [];
    $st = $pdo->prepare('SELECT * FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=? ORDER BY item_number ASC, id ASC');
    $st->execute([$companyId, $docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_report_document_taxes_map')) {
  function fiscal_report_document_taxes_map(PDO $pdo, int $companyId, int $docId): array {
    $out = [];
    if (!fiscal_table_exists($pdo, 'fiscal_document_item_taxes')) return $out;
    $st = $pdo->prepare('SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? ORDER BY id ASC');
    $st->execute([$companyId, $docId]);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $itemId = (int)($r['fiscal_document_item_id'] ?? 0);
      $group = strtoupper(trim((string)($r['tax_group'] ?? '')));
      if ($itemId <= 0 || $group === '') continue;
      $out[$itemId][$group] = $r;
    }
    return $out;
  }
}

if (!function_exists('fiscal_report_doc_tax')) {
  function fiscal_report_doc_tax(array $taxesMap, int $itemId, string $group): array {
    $group = strtoupper($group);
    return isset($taxesMap[$itemId][$group]) && is_array($taxesMap[$itemId][$group]) ? $taxesMap[$itemId][$group] : [];
  }
}

if (!function_exists('fiscal_report_nfce_item_product_code')) {
  function fiscal_report_nfce_item_product_code(array $item): string {
    $code = trim((string)($item['product_code'] ?? ''));
    if ($code === '') $code = trim((string)($item['supplier_product_code'] ?? ''));
    if ($code === '') $code = (string)((int)($item['product_id'] ?? 0));
    if ($code === '' || $code === '0') $code = (string)((int)($item['id'] ?? 0));
    return $code;
  }
}

if (!function_exists('fiscal_report_nfce_item_unit')) {
  function fiscal_report_nfce_item_unit(array $item): string {
    $unit = strtoupper(trim((string)($item['unidade'] ?? '')));
    return $unit !== '' ? $unit : 'UN';
  }
}

if (!function_exists('fiscal_report_nfce_item_value')) {
  function fiscal_report_nfce_item_value(array $item, bool $includeIpiSt = true): float {
    $value = fiscal_report_item_product_value($item)
      + fiscal_report_item_accessory_value($item);
    if ($includeIpiSt) $value += fiscal_report_item_tax_addition_value($item);
    return round(max(0, $value), 2);
  }
}

if (!function_exists('fiscal_report_nfce_icms_cst')) {
  function fiscal_report_nfce_icms_cst(array $item, array $icmsTax): string {
    $origin = fiscal_xml_digits($icmsTax['origin_code'] ?? '');
    if ($origin === '') $origin = fiscal_xml_digits($item['origem'] ?? '');
    if ($origin === '') $origin = '0';
    $origin = substr($origin, -1);
    $cst = fiscal_xml_digits($icmsTax['cst'] ?? '');
    if ($cst === '') $cst = fiscal_xml_digits($icmsTax['csosn'] ?? '');
    if ($cst === '') $cst = fiscal_xml_digits($item['icms_cst'] ?? '');
    if ($cst === '') $cst = fiscal_xml_digits($item['icms_csosn'] ?? '');
    if ($cst === '') $cst = '00';
    if (strlen($cst) >= 3) return substr($cst, -3);
    return $origin . str_pad($cst, 2, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('fiscal_report_nfce_item_summary')) {
  function fiscal_report_nfce_item_summary(array $item, array $taxesMap): array {
    $itemId = (int)($item['id'] ?? 0);
    $icms = fiscal_report_doc_tax($taxesMap, $itemId, 'ICMS');
    $pis = fiscal_report_doc_tax($taxesMap, $itemId, 'PIS');
    $cofins = fiscal_report_doc_tax($taxesMap, $itemId, 'COFINS');

    $cfop = fiscal_cfop_digits($item['cfop'] ?? '') ?: '5102';
    $icmsCst = fiscal_report_nfce_icms_cst($item, $icms);
    $icmsRate = round((float)($icms['rate'] ?? 0), 4);
    $icmsBase = round((float)($icms['base_calc'] ?? 0), 2);
    $icmsAmount = round((float)($icms['amount'] ?? 0), 2);
    $itemValueForIcms = fiscal_report_nfce_item_value($item);
    // Fallback para NFC-e importada/lançada sem base/valor de ICMS gravado.
    // Quando há alíquota informada, o Registro 61 não aceita base zerada.
    if ($icmsRate > 0 && $itemValueForIcms > 0) {
      if ($icmsBase <= 0) $icmsBase = $itemValueForIcms;
      if ($icmsAmount <= 0) $icmsAmount = round($icmsBase * ($icmsRate / 100), 2);
    }

    $pisCst = str_pad(fiscal_xml_digits($pis['cst'] ?? '') ?: '49', 2, '0', STR_PAD_LEFT);
    $pisRate = round((float)($pis['rate'] ?? 0), 4);
    $pisBase = round((float)($pis['base_calc'] ?? 0), 2);
    $pisAmount = round((float)($pis['amount'] ?? 0), 2);

    $cofinsCst = str_pad(fiscal_xml_digits($cofins['cst'] ?? '') ?: '49', 2, '0', STR_PAD_LEFT);
    $cofinsRate = round((float)($cofins['rate'] ?? 0), 4);
    $cofinsBase = round((float)($cofins['base_calc'] ?? 0), 2);
    $cofinsAmount = round((float)($cofins['amount'] ?? 0), 2);

    return [
      'code' => fiscal_report_nfce_item_product_code($item),
      'name' => trim((string)($item['description'] ?? 'Produto')) ?: 'Produto',
      'unit' => fiscal_report_nfce_item_unit($item),
      'ncm' => fiscal_xml_digits($item['ncm'] ?? ''),
      'cest' => fiscal_xml_digits($item['cest'] ?? ''),
      'cfop' => $cfop,
      'quantity' => (float)($item['quantity'] ?? 0),
      'value' => $itemValueForIcms,
      'product_value' => fiscal_report_item_product_value($item),
      'discount' => round((float)($item['discount_value'] ?? 0), 2),
      'icms_cst' => $icmsCst,
      'icms_rate' => $icmsRate,
      'icms_base' => $icmsBase,
      'icms_amount' => $icmsAmount,
      'pis_cst' => $pisCst,
      'pis_rate' => $pisRate,
      'pis_base' => $pisBase,
      'pis_amount' => $pisAmount,
      'cofins_cst' => $cofinsCst,
      'cofins_rate' => $cofinsRate,
      'cofins_base' => $cofinsBase,
      'cofins_amount' => $cofinsAmount,
      'ipi_value' => round((float)($item['ipi_value'] ?? 0), 2),
      'icms_st_value' => round((float)($item['icms_st_value'] ?? 0), 2),
    ];
  }
}

if (!function_exists('fiscal_sped_num')) {
  function fiscal_sped_num($value, int $decimals = 2, bool $blankZero = false): string {
    $num = is_numeric($value) ? (float)$value : fiscal_xml_money($value);
    if ($blankZero && abs($num) < 0.000001) return '';
    return number_format($num, $decimals, ',', '');
  }
}

if (!function_exists('fiscal_sped_txt')) {
  function fiscal_sped_txt($value): string {
    $s = trim((string)$value);
    $s = str_replace(["\r", "\n", "|"], [' ', ' ', ' '], $s);
    return preg_replace('/\s+/', ' ', $s) ?? '';
  }
}

if (!function_exists('fiscal_sped_line')) {
  function fiscal_sped_line(array $fields): string {
    return '|' . implode('|', array_map('fiscal_sped_txt', $fields)) . '|';
  }
}

if (!function_exists('fiscal_sped_reg')) {
  function fiscal_sped_reg(string $line): string {
    if (preg_match('/^\|([^|]+)\|/', $line, $m)) return $m[1];
    return '';
  }
}

if (!function_exists('fiscal_sped_append_block9')) {
  function fiscal_sped_append_block9(array $lines): array {
    $lines[] = fiscal_sped_line(['9001', '0']);
    $counts = [];
    foreach ($lines as $line) {
      $reg = fiscal_sped_reg($line);
      if ($reg !== '') $counts[$reg] = ($counts[$reg] ?? 0) + 1;
    }
    $allRegs = array_keys($counts);
    foreach (['9900','9990','9999'] as $r) if (!in_array($r, $allRegs, true)) $allRegs[] = $r;
    sort($allRegs, SORT_STRING);
    $counts['9900'] = count($allRegs);
    $counts['9990'] = 1;
    $counts['9999'] = 1;
    foreach ($allRegs as $reg) $lines[] = fiscal_sped_line(['9900', $reg, (string)($counts[$reg] ?? 0)]);
    $lines[] = fiscal_sped_line(['9990', (string)(count($allRegs) + 2)]);
    $lines[] = fiscal_sped_line(['9999', (string)(count($lines) + 1)]);
    return $lines;
  }
}

if (!function_exists('fiscal_sintegra_generate_content')) {
  function fiscal_sintegra_generate_content(PDO $pdo, int $companyId, string $ini, string $fim, array $options = []): string {
    $company = fiscal_company_report_identity($pdo, $companyId);
    $entries = fiscal_report_entries($pdo, $companyId, $ini, $fim);
    $nfes = fiscal_report_nfe_documents($pdo, $companyId, $ini, $fim, false);
    $includeNfce = (string)($options['include_nfce'] ?? '1') !== '0';
    $nfces = $includeNfce ? fiscal_report_nfce_documents($pdo, $companyId, $ini, $fim, false) : [];
    $modelFilter = trim((string)($options['model'] ?? ''));
    if (!in_array($modelFilter, ['55','65'], true)) $modelFilter = '';
    $seriesFilter = trim((string)($options['series'] ?? ''));
    if ($modelFilter !== '' || $seriesFilter !== '') {
      $entries = array_values(array_filter($entries, static function(array $row) use ($modelFilter,$seriesFilter): bool {
        $rowModel = trim((string)($row['model'] ?? ''));
        $rowSeries = trim((string)($row['series'] ?? ''));
        if ($modelFilter !== '' && $rowModel !== $modelFilter) return false;
        if ($seriesFilter !== '' && $rowSeries !== $seriesFilter) return false;
        return true;
      }));
      $nfes = array_values(array_filter($nfes, static function(array $row) use ($modelFilter,$seriesFilter): bool {
        if ($modelFilter !== '' && $modelFilter !== '55') return false;
        if ($seriesFilter !== '' && trim((string)($row['series'] ?? '')) !== $seriesFilter) return false;
        return true;
      }));
      $nfces = array_values(array_filter($nfces, static function(array $row) use ($modelFilter,$seriesFilter): bool {
        if ($modelFilter !== '' && $modelFilter !== '65') return false;
        if ($seriesFilter !== '' && trim((string)($row['series'] ?? '')) !== $seriesFilter) return false;
        return true;
      }));
    }
    $finalidadeOpt = (string)($options['finalidade'] ?? '1');
    $finalidade = in_array($finalidadeOpt, ['1','2','3','5'], true) ? $finalidadeOpt : ($finalidadeOpt === 'retificador' ? '2' : '1');
    $natureza = (string)($options['natureza'] ?? '3');
    if (!in_array($natureza, ['1','2','3'], true)) $natureza = '3';
    $estrutura = (string)($options['estrutura'] ?? '3');
    if (!in_array($estrutura, ['1','2','3'], true)) $estrutura = '3';
    // Para fatos geradores a partir de 01/01/2004, o Validador SINTEGRA exige código 3 no Registro 10.
    if (($fim ?: $ini) >= '2004-01-01') $estrutura = '3';

    $lines = [];
    $lines[] = fiscal_sintegra_record([
      '10', fiscal_sintegra_n($company['document'] ?: '0', 14), fiscal_sintegra_x($company['ie'] ?? '', 14), fiscal_sintegra_x($company['name'] ?? '', 35),
      fiscal_sintegra_x($company['city'] ?? '', 30), fiscal_sintegra_x($company['uf'] ?? '', 2), fiscal_sintegra_n($company['fax'] ?? '', 10),
      fiscal_sintegra_date($ini), fiscal_sintegra_date($fim), fiscal_sintegra_x($estrutura, 1), fiscal_sintegra_x($natureza, 1), fiscal_sintegra_x($finalidade, 1),
    ]);
    $lines[] = fiscal_sintegra_record([
      '11', fiscal_sintegra_x($company['address'] ?? '', 34), fiscal_sintegra_n($company['number'] ?? '0', 5), fiscal_sintegra_x($company['complement'] ?? '', 22),
      fiscal_sintegra_x($company['district'] ?? '', 15), fiscal_sintegra_n($company['zip'] ?? '', 8), fiscal_sintegra_x($company['contact'] ?? $company['name'] ?? '', 28), fiscal_sintegra_n($company['phone'] ?? '', 12),
    ]);

    $products75 = [];
    foreach ($entries as $x) {
      $items = fiscal_report_items($pdo, $companyId, (int)$x['id']);
      if (!$items) continue;
      $docModel = fiscal_sintegra_model((string)($x['model'] ?? '') === 'MANUAL' ? '01' : ($x['model'] ?? '55'));
      $docSeries = fiscal_sintegra_series($x['series'] ?? '');
      $docNumber = fiscal_sintegra_number($x['number'] ?? '');
      $docDate = fiscal_sintegra_date($x['report_date'] ?? $x['entry_date'] ?? $x['issue_date'] ?? '');
      $supplierDoc = fiscal_sintegra_n($x['supplier_doc'] ?? '0', 14);
      $supplierIe = fiscal_sintegra_ie($x['supplier_ie'] ?? '');
      $supplierUf = fiscal_sintegra_x(strtoupper((string)($x['supplier_uf'] ?? '')), 2);

      $groups50 = [];
      $xmlItemStMap = fiscal_report_entry_xml_item_st_map($x);
      foreach ($items as $it) {
        $tax = fiscal_xml_item_tax_payload($pdo, $companyId, $it);
        $cfop = fiscal_cfop_digits($tax['entry_cfop'] ?: ($it['cfop'] ?? '')) ?: '0000';
        $aliq = '0000';
        $key = $cfop . '|' . $aliq;
        if (!isset($groups50[$key])) $groups50[$key] = ['cfop'=>$cfop,'total'=>0.0,'base'=>0.0,'icms'=>0.0,'isenta'=>0.0,'outras'=>0.0,'aliq'=>$aliq,'ipi'=>0.0,'st_base'=>0.0,'st_value'=>0.0,'fcp_st'=>0.0];
        $value = fiscal_report_item_document_value($it);
        $groups50[$key]['total'] += $value;
        $cst = substr(fiscal_sintegra_item_cst($it, $tax), -2);
        if (in_array($cst, ['40','41','50'], true)) $groups50[$key]['isenta'] += $value; else $groups50[$key]['outras'] += $value;
        $itemNo = (int)($it['item_number'] ?? 0);
        $stPayload = $xmlItemStMap[$itemNo] ?? [];
        $groups50[$key]['ipi'] += max(0.0, (float)($it['ipi_value'] ?? 0));
        $groups50[$key]['st_base'] += max(0.0, (float)($stPayload['icms_st_base'] ?? 0));
        $groups50[$key]['st_value'] += max(0.0, (float)($stPayload['icms_st_value'] ?? ($it['icms_st_value'] ?? 0)));
        $groups50[$key]['fcp_st'] += max(0.0, (float)($stPayload['fcp_st_value'] ?? 0));
      }
      fiscal_report_adjust_sintegra_groups_to_invoice($groups50, $x);
      foreach ($groups50 as $g) {
        $lines[] = fiscal_sintegra_record([
          '50', $supplierDoc, $supplierIe, $docDate, $supplierUf, fiscal_sintegra_n($docModel, 2), $docSeries, $docNumber,
          fiscal_sintegra_n($g['cfop'], 4), 'T', fiscal_sintegra_n($g['total'], 13, 2), fiscal_sintegra_n($g['base'], 13, 2), fiscal_sintegra_n($g['icms'], 13, 2),
          fiscal_sintegra_n($g['isenta'], 13, 2), fiscal_sintegra_n($g['outras'], 13, 2), fiscal_sintegra_n($g['aliq'], 4, 2), 'N'
        ]);
      }
      foreach ($groups50 as $g) {
        if (round((float)($g['ipi'] ?? 0), 2) > 0.0) {
          $lines[] = fiscal_sintegra_record51($supplierDoc, $supplierIe, $docDate, $supplierUf, (string)$docModel, $docSeries, $docNumber, (string)$g['cfop'], (float)$g['total'], (float)$g['ipi']);
        }
        if (round((float)($g['st_value'] ?? 0), 2) > 0.0 || round((float)($g['st_base'] ?? 0), 2) > 0.0) {
          $lines[] = fiscal_sintegra_record53($supplierDoc, $supplierIe, $docDate, $supplierUf, (string)$docModel, $docSeries, $docNumber, (string)$g['cfop'], (float)($g['st_base'] ?? 0), (float)($g['st_value'] ?? 0), (float)($g['fcp_st'] ?? 0));
        }
      }

      foreach ($items as $it) {
        $tax = fiscal_xml_item_tax_payload($pdo, $companyId, $it);
        $sys = fiscal_report_item_system_payload($it);
        $cfop = fiscal_cfop_digits($tax['entry_cfop'] ?: ($it['entry_cfop'] ?? ($it['cfop'] ?? ''))) ?: '0000';
        $cst = fiscal_sintegra_item_cst($it, $tax);
        $prodCodeRaw = (string)$sys['code'];
        $prodCode = fiscal_sintegra_x($prodCodeRaw, 14);
        $itemNumber = fiscal_sintegra_n($it['item_number'] ?? '0', 3);
        $qtyReport = (float)($it['stock_quantity'] ?? 0);
        if ($qtyReport <= 0) $qtyReport = (float)($it['quantity'] ?? 0);
        $lines[] = fiscal_sintegra_record([
          '54', $supplierDoc, fiscal_sintegra_n($docModel, 2), $docSeries, $docNumber, fiscal_sintegra_n($cfop, 4), fiscal_sintegra_n($cst, 3),
          $itemNumber, $prodCode, fiscal_sintegra_n($qtyReport, 11, 3), fiscal_sintegra_n(fiscal_report_item_product_value($it), 12, 2),
          fiscal_sintegra_n(abs(fiscal_report_item_accessory_value($it)), 12, 2), fiscal_sintegra_n(0, 12, 2), fiscal_sintegra_n(0, 12, 2), fiscal_sintegra_n($it['ipi_value'] ?? 0, 12, 2), fiscal_sintegra_n(0, 4)
        ]);
        $prodKey = trim($prodCode);
        if ($prodKey !== '' && !isset($products75[$prodKey])) {
          $cst75 = $cst;
          $cstOut = fiscal_xml_digits($sys['icms_cst_out'] ?? '');
          $origemOut = fiscal_xml_digits($sys['origem_out'] ?? '');
          if ($cstOut !== '') {
            if ($origemOut === '') $origemOut = substr(fiscal_xml_digits($tax['entry_origem'] ?? '0') ?: '0', -1);
            $cst75 = strlen($cstOut) >= 3 ? substr($cstOut, -3) : substr($origemOut, -1) . str_pad($cstOut, 2, '0', STR_PAD_LEFT);
          }
          $products75[$prodKey] = ['code'=>$prodCodeRaw,'ncm'=>(string)$sys['ncm'],'name'=>(string)$sys['name'],'unit'=>(string)$sys['unit'],'cst'=>$cst75,'ipi_rate'=>(float)($sys['ipi_rate'] ?? 0),'icms_rate'=>(float)($sys['icms_rate'] ?? 0)];
        }
      }
    }

    // NF-e próprias autorizadas: vendas, devoluções para fornecedor e devoluções de cliente (entrada) - registros 50, 51, 53 e 54.
    foreach ($nfes as $doc) {
      $docId=(int)($doc['id'] ?? 0);
      $items=fiscal_report_nfce_items($pdo,$companyId,$docId);
      if (!$items) continue;
      $taxesMap=fiscal_report_document_taxes_map($pdo,$companyId,$docId);
      $docModel='55';
      $docSeries=fiscal_sintegra_series($doc['series'] ?? '');
      $docNumber=fiscal_sintegra_number($doc['number'] ?? '');
      $docDate=fiscal_sintegra_date($doc['report_date'] ?? $doc['issue_datetime'] ?? '');
      $recipientDoc=fiscal_sintegra_n($doc['recipient_doc'] ?? '0',14);
      $recipientIe=fiscal_sintegra_ie($doc['recipient_ie'] ?? '');
      $recipientUf=fiscal_sintegra_x(strtoupper((string)($doc['recipient_uf'] ?? '')),2);

      $groups50=[];
      foreach ($items as $it) {
        $summary=fiscal_report_nfce_item_summary($it,$taxesMap);
        $itemId=(int)($it['id'] ?? 0);
        $icms=fiscal_report_doc_tax($taxesMap,$itemId,'ICMS');
        $ipi=fiscal_report_doc_tax($taxesMap,$itemId,'IPI');
        $icmsSt=fiscal_report_doc_tax($taxesMap,$itemId,'ICMSST');
        $extra=[];
        foreach ([$icmsSt,$icms] as $taxRow) {
          $raw=trim((string)($taxRow['extra_json'] ?? ''));
          if ($raw==='') continue;
          $decoded=json_decode($raw,true);
          if (is_array($decoded)) $extra=array_merge($extra,$decoded);
        }
        $stBase=(float)($icmsSt['base_calc'] ?? 0);
        if ($stBase<=0) $stBase=(float)($icms['base_calc_st'] ?? 0);
        if ($stBase<=0) $stBase=(float)($extra['st_base'] ?? $extra['vBCST'] ?? 0);
        $stAmount=(float)($icmsSt['amount'] ?? 0);
        if ($stAmount<=0) $stAmount=(float)($extra['st_amount'] ?? $extra['vICMSST'] ?? 0);
        $fcpSt=(float)($extra['fcp_st_amount'] ?? $extra['vFCPST'] ?? 0);
        $ipiAmount=(float)($ipi['amount'] ?? 0);

        $cfop=(string)$summary['cfop'];
        $rate=(float)$summary['icms_rate'];
        $key=$cfop . '|' . number_format($rate,2,'.','');
        if (!isset($groups50[$key])) $groups50[$key]=[
          'cfop'=>$cfop,'total'=>0.0,'base'=>0.0,'icms'=>0.0,'isenta'=>0.0,'outras'=>0.0,
          'aliq'=>$rate,'ipi'=>0.0,'st_base'=>0.0,'st_value'=>0.0,'fcp_st'=>0.0,
        ];
        $groups50[$key]['total']+=(float)$summary['value']+$ipiAmount+$stAmount;
        $groups50[$key]['base']+=(float)$summary['icms_base'];
        $groups50[$key]['icms']+=(float)$summary['icms_amount'];
        $groups50[$key]['ipi']+=$ipiAmount;
        $groups50[$key]['st_base']+=$stBase;
        $groups50[$key]['st_value']+=$stAmount;
        $groups50[$key]['fcp_st']+=$fcpSt;
        $cst2=substr((string)$summary['icms_cst'],-2);
        if (in_array($cst2,['40','41','50'],true)) $groups50[$key]['isenta']+=(float)$summary['value'];
        elseif ((float)$summary['icms_base']<=0.0 && (float)$summary['icms_amount']<=0.0) $groups50[$key]['outras']+=(float)$summary['value'];
      }
      fiscal_report_adjust_sintegra_groups_to_invoice($groups50,['total_invoice'=>(float)($doc['total_document'] ?? 0)]);

      foreach ($groups50 as $g) {
        $lines[]=fiscal_sintegra_record([
          '50',$recipientDoc,$recipientIe,$docDate,$recipientUf,fiscal_sintegra_n($docModel,2),$docSeries,$docNumber,
          fiscal_sintegra_n($g['cfop'],4),'P',fiscal_sintegra_n($g['total'],13,2),fiscal_sintegra_n($g['base'],13,2),fiscal_sintegra_n($g['icms'],13,2),
          fiscal_sintegra_n($g['isenta'],13,2),fiscal_sintegra_n($g['outras'],13,2),fiscal_sintegra_n($g['aliq'],4,2),'N'
        ]);
        if (round((float)$g['ipi'],2)>0.0) {
          $lines[]=fiscal_sintegra_record51($recipientDoc,$recipientIe,$docDate,$recipientUf,$docModel,$docSeries,$docNumber,(string)$g['cfop'],(float)$g['total'],(float)$g['ipi']);
        }
        if (round((float)$g['st_base'],2)>0.0 || round((float)$g['st_value'],2)>0.0) {
          $lines[]=fiscal_sintegra_record53($recipientDoc,$recipientIe,$docDate,$recipientUf,$docModel,$docSeries,$docNumber,(string)$g['cfop'],(float)$g['st_base'],(float)$g['st_value'],(float)$g['fcp_st'],'P','N');
        }
      }

      foreach ($items as $it) {
        $summary=fiscal_report_nfce_item_summary($it,$taxesMap);
        $itemId=(int)($it['id'] ?? 0);
        $ipi=fiscal_report_doc_tax($taxesMap,$itemId,'IPI');
        $prodCodeRaw=(string)$summary['code'];
        $prodCode=fiscal_sintegra_x($prodCodeRaw,14);
        $lines[]=fiscal_sintegra_record([
          '54',$recipientDoc,fiscal_sintegra_n($docModel,2),$docSeries,$docNumber,fiscal_sintegra_n($summary['cfop'],4),fiscal_sintegra_n($summary['icms_cst'],3),
          fiscal_sintegra_n($it['item_number'] ?? 0,3),$prodCode,fiscal_sintegra_n($summary['quantity'],11,3),fiscal_sintegra_n(fiscal_report_item_product_value($it),12,2),
          fiscal_sintegra_n(abs(fiscal_report_item_accessory_value($it)),12,2),fiscal_sintegra_n(0,12,2),fiscal_sintegra_n(0,12,2),fiscal_sintegra_n($ipi['amount'] ?? 0,12,2),fiscal_sintegra_n(0,4)
        ]);
        $prodKey=trim($prodCode);
        if ($prodKey!=='' && !isset($products75[$prodKey])) {
          $products75[$prodKey]=[
            'code'=>$prodCodeRaw,'ncm'=>(string)$summary['ncm'],'name'=>(string)$summary['name'],'unit'=>(string)$summary['unit'],
            'cst'=>(string)$summary['icms_cst'],'ipi_rate'=>(float)($ipi['rate'] ?? 0),'icms_rate'=>(float)$summary['icms_rate'],
          ];
        }
      }
    }

    $groups61 = [];
    $groups61r = [];
    foreach ($nfces as $doc) {
      $items = fiscal_report_nfce_items($pdo, $companyId, (int)$doc['id']);
      if (!$items) continue;
      $taxesMap = fiscal_report_document_taxes_map($pdo, $companyId, (int)$doc['id']);
      $date = fiscal_sintegra_date($doc['report_date'] ?? $doc['issue_datetime'] ?? '');
      $seriesRaw = $doc['series'] ?? '';
      $series = fiscal_sintegra_record61_series($seriesRaw);
      $number = (int)($doc['number'] ?? 0);
      foreach ($items as $it) {
        $s = fiscal_report_nfce_item_summary($it, $taxesMap);
        $aliq = $s['icms_rate'];
        $key = $date . '|65|' . trim($series) . '|' . number_format($aliq, 2, '.', '');
        if (!isset($groups61[$key])) $groups61[$key] = ['date'=>$date,'model'=>'65','series'=>$series,'subseries'=>'  ','first'=>$number,'last'=>$number,'total'=>0.0,'base'=>0.0,'icms'=>0.0,'isenta'=>0.0,'outras'=>0.0,'aliq'=>$aliq];
        $groups61[$key]['first'] = min((int)$groups61[$key]['first'], $number);
        $groups61[$key]['last'] = max((int)$groups61[$key]['last'], $number);
        $groups61[$key]['total'] += $s['value'];
        $groups61[$key]['base'] += $s['icms_base'];
        $groups61[$key]['icms'] += $s['icms_amount'];
        $cst2 = substr($s['icms_cst'], -2);
        if (in_array($cst2, ['40','41','50'], true)) $groups61[$key]['isenta'] += $s['value'];
        elseif ($s['icms_base'] <= 0.0 && $s['icms_amount'] <= 0.0) $groups61[$key]['outras'] += $s['value'];

        $month = substr($date, 4, 2) . substr($date, 0, 4);
        $prodCodeRaw = $s['code'];
        $prodCode = fiscal_sintegra_x($prodCodeRaw, 14);
        $prodKey = trim($prodCode);
        $rKey = $month . '|' . $prodKey . '|' . number_format($aliq, 2, '.', '');
        if (!isset($groups61r[$rKey])) $groups61r[$rKey] = ['month'=>$month,'code'=>$prodCodeRaw,'quantity'=>0.0,'value'=>0.0,'base'=>0.0,'aliq'=>$aliq];
        $groups61r[$rKey]['quantity'] += max(0.0, (float)$s['quantity']);
        $groups61r[$rKey]['value'] += $s['product_value'];
        $groups61r[$rKey]['base'] += $s['icms_base'];
        if ($prodKey !== '' && !isset($products75[$prodKey])) {
          $products75[$prodKey] = ['code'=>$prodCodeRaw,'ncm'=>$s['ncm'],'name'=>$s['name'],'unit'=>$s['unit'],'cst'=>$s['icms_cst'],'ipi_rate'=>0,'icms_rate'=>$aliq];
        }
      }
    }
    ksort($groups61, SORT_STRING);
    foreach ($groups61 as $g) {
      $total61 = round(max(0.0, (float)($g['total'] ?? 0)), 2);
      $base61 = round(max(0.0, (float)($g['base'] ?? 0)), 2);
      $icms61 = round(max(0.0, (float)($g['icms'] ?? 0)), 2);
      $aliq61 = round(max(0.0, (float)($g['aliq'] ?? 0)), 2);
      $isenta61 = round(max(0.0, (float)($g['isenta'] ?? 0)), 2);
      $outras61 = round(max(0.0, (float)($g['outras'] ?? 0)), 2);
      if ($aliq61 <= 0.0) {
        $base61 = 0.0;
        $icms61 = 0.0;
        if ($isenta61 <= 0.0 && $outras61 <= 0.0) $outras61 = $total61;
      } elseif ($base61 > $total61) {
        $base61 = $total61;
      }
      $lines[] = fiscal_sintegra_record([
        '61', fiscal_sintegra_x('', 14), fiscal_sintegra_x('', 14), $g['date'], fiscal_sintegra_n($g['model'], 2), $g['series'], fiscal_sintegra_x('', 2),
        fiscal_sintegra_number($g['first']), fiscal_sintegra_number($g['last']), fiscal_sintegra_n($total61, 13, 2), fiscal_sintegra_n($base61, 13, 2), fiscal_sintegra_n($icms61, 12, 2),
        fiscal_sintegra_n($isenta61, 13, 2), fiscal_sintegra_n($outras61, 13, 2), fiscal_sintegra_n($aliq61, 4, 2), ' '
      ]);
    }
    ksort($groups61r, SORT_STRING);
    foreach ($groups61r as $g) {
      $lines[] = fiscal_sintegra_record([
        '61', 'R', fiscal_sintegra_n($g['month'], 6), fiscal_sintegra_x($g['code'], 14), fiscal_sintegra_n($g['quantity'], 13, 3),
        fiscal_sintegra_n($g['value'], 16, 2), fiscal_sintegra_n($g['base'], 16, 2), fiscal_sintegra_n($g['aliq'], 4, 2), fiscal_sintegra_x('', 54)
      ]);
    }

    ksort($products75, SORT_NATURAL | SORT_FLAG_CASE);
    foreach ($products75 as $p) {
      $lines[] = fiscal_sintegra_record([
        '75', fiscal_sintegra_date($ini), fiscal_sintegra_date($fim), fiscal_sintegra_x($p['code'], 14), fiscal_sintegra_x($p['ncm'], 8), fiscal_sintegra_x($p['name'], 53), fiscal_sintegra_x($p['unit'], 6),
        fiscal_sintegra_n($p['cst'], 3), fiscal_sintegra_n($p['ipi_rate'] ?? 0, 4, 2), fiscal_sintegra_n($p['icms_rate'] ?? 0, 4, 2), fiscal_sintegra_n(0, 4), fiscal_sintegra_n(0, 12, 2)
      ]);
    }

    // O Manual exige os conjuntos em ordem própria. Sem esta etapa, uma NF-e
    // de saída acrescentada após os itens de entrada criava registros 50 depois
    // de registros 54, sendo rejeitada pelo Validador como "Registro fora de ordem".
    $lines = fiscal_sintegra_sort_lines($lines);

    $counts = [];
    foreach ($lines as $ln) {
      $type = substr($ln, 0, 2);
      if (!in_array($type, ['10','11','90'], true)) $counts[$type] = ($counts[$type] ?? 0) + 1;
    }
    $lines[] = fiscal_sintegra_record90($company, $counts, count($lines) + 1, 1);
    return implode("\r\n", $lines) . "\r\n";
  }
}

if (!function_exists('fiscal_sped_icms_generate_content')) {
  function fiscal_sped_icms_generate_content(PDO $pdo, int $companyId, string $ini, string $fim, array $options = []): string {
    $company = fiscal_company_report_identity($pdo, $companyId);
    $entries = fiscal_report_entries($pdo, $companyId, $ini, $fim);
    $includeNfce = (string)($options['include_nfce'] ?? '1') !== '0';
    $nfces = $includeNfce ? fiscal_report_nfce_documents($pdo, $companyId, $ini, $fim, true) : [];
    $finalidade = (string)($options['finalidade'] ?? '0');
    $layout = (string)($options['layout'] ?? '020');
    $tipoItemDefault = (string)($options['tipo_item'] ?? '00');
    $lines = [];
    $lines[] = fiscal_sped_line(['0000', $layout, $finalidade, fiscal_date_br($ini, ''), fiscal_date_br($fim, ''), $company['name'], ($company['document'] ?: '00000000000000'), '', $company['uf'], $company['ie'] ?? '', '', '', '', 'B', '1']);
    $lines[] = fiscal_sped_line(['0001', '0']);
    $lines[] = fiscal_sped_line(['0005', $company['name'], $company['zip'] ?? '', $company['address'] ?? '', $company['number'] ?? '0', $company['complement'] ?? '', $company['district'] ?? '', $company['phone'] ?? '', $company['fax'] ?? '', '']);
    $lines[] = fiscal_sped_line(['0990', '4']);
    $lines[] = fiscal_sped_line(['C001', '0']);

    foreach ($entries as $x) {
      $lines[] = fiscal_sped_line(['C100','0','1',fiscal_xml_digits($x['supplier_doc'] ?? ''),'55','00',($x['series'] ?? ''),($x['number'] ?? ''),fiscal_xml_digits($x['access_key'] ?? ''),fiscal_date_br($x['issue_date'] ?? '', ''),fiscal_date_br($x['report_date'] ?? $x['entry_date'] ?? '', ''),fiscal_sped_num($x['total_invoice'] ?? 0),'0','','','', '', '', '', '', '', '', '', '', '', '', '', '', '']);
      foreach (fiscal_report_items($pdo, $companyId, (int)$x['id']) as $it) {
        $tax = fiscal_xml_item_tax_payload($pdo, $companyId, $it);
        $sys = fiscal_report_item_system_payload($it);
        $qtyReport = (float)($it['stock_quantity'] ?? 0);
        if ($qtyReport <= 0) $qtyReport = (float)($it['quantity'] ?? 0);
        $entryCfop = fiscal_cfop_digits($tax['entry_cfop'] ?: ($it['entry_cfop'] ?? ($it['cfop'] ?? '')));
        $entryCst = fiscal_sintegra_item_cst($it, $tax);
        $lines[] = fiscal_sped_line(['C170',(int)($it['item_number'] ?? 0),$sys['code'],$sys['name'],fiscal_sped_num($qtyReport, 4),$sys['unit'],fiscal_sped_num((float)($it['total_value'] ?? 0)),'0',$entryCfop,$entryCst]);
      }
    }

    foreach ($nfces as $doc) {
      $items = fiscal_report_nfce_items($pdo, $companyId, (int)$doc['id']);
      $taxesMap = fiscal_report_document_taxes_map($pdo, $companyId, (int)$doc['id']);
      $cancelled = fiscal_report_nfce_is_cancelled($doc);
      $c190 = [];
      $totBaseIcms = 0.0; $totIcms = 0.0; $totPis = 0.0; $totCofins = 0.0;
      if (!$cancelled) {
        foreach ($items as $it) {
          $s = fiscal_report_nfce_item_summary($it, $taxesMap);
          $key = $s['icms_cst'] . '|' . $s['cfop'] . '|' . number_format($s['icms_rate'], 4, '.', '');
          if (!isset($c190[$key])) $c190[$key] = ['cst'=>$s['icms_cst'],'cfop'=>$s['cfop'],'aliq'=>$s['icms_rate'],'vl_opr'=>0.0,'vl_bc'=>0.0,'vl_icms'=>0.0,'vl_ipi'=>0.0];
          $c190[$key]['vl_opr'] += $s['value'];
          $c190[$key]['vl_bc'] += $s['icms_base'];
          $c190[$key]['vl_icms'] += $s['icms_amount'];
          $c190[$key]['vl_ipi'] += $s['ipi_value'];
          $totBaseIcms += $s['icms_base']; $totIcms += $s['icms_amount']; $totPis += $s['pis_amount']; $totCofins += $s['cofins_amount'];
        }
      }
      $totalDoc = $cancelled ? 0 : (float)($doc['total_document'] ?? 0);
      $lines[] = fiscal_sped_line([
        'C100','1','0','', '65', $cancelled ? '02' : '00', ($doc['series'] ?? ''), ($doc['number'] ?? ''), fiscal_xml_digits($doc['access_key'] ?? ''),
        fiscal_date_br($doc['issue_datetime'] ?? $doc['report_date'] ?? '', ''), fiscal_date_br($doc['issue_datetime'] ?? $doc['report_date'] ?? '', ''), fiscal_sped_num($totalDoc), '0',
        fiscal_sped_num($cancelled ? 0 : ($doc['total_discount'] ?? 0)), '0', fiscal_sped_num($cancelled ? 0 : ($doc['total_products'] ?? 0)), '9', '0', '0', fiscal_sped_num($cancelled ? 0 : ($doc['total_other'] ?? 0)),
        fiscal_sped_num($totBaseIcms), fiscal_sped_num($totIcms), '0', '0', '0', fiscal_sped_num($totPis), fiscal_sped_num($totCofins), '0', '0'
      ]);
      ksort($c190, SORT_STRING);
      foreach ($c190 as $g) {
        $lines[] = fiscal_sped_line(['C190',$g['cst'],$g['cfop'],fiscal_sped_num($g['aliq'], 2),fiscal_sped_num($g['vl_opr']),fiscal_sped_num($g['vl_bc']),fiscal_sped_num($g['vl_icms']),'0','0','0',fiscal_sped_num($g['vl_ipi']),'']);
      }
    }

    $cCount = 1;
    foreach ($lines as $ln) if (str_starts_with($ln, '|C') && !str_starts_with($ln, '|C990|')) $cCount++;
    $lines[] = fiscal_sped_line(['C990', (string)$cCount]);
    $lines = fiscal_sped_append_block9($lines);
    $lines[] = '';
    return implode("\r\n", $lines);
  }
}

if (!function_exists('fiscal_sped_contrib_generate_content')) {
  function fiscal_sped_contrib_generate_content(PDO $pdo, int $companyId, string $ini, string $fim, array $options = []): string {
    $company = fiscal_company_report_identity($pdo, $companyId);
    $entries = fiscal_report_entries($pdo, $companyId, $ini, $fim);
    $includeNfce = (string)($options['include_nfce'] ?? '1') !== '0';
    $nfces = $includeNfce ? fiscal_report_nfce_documents($pdo, $companyId, $ini, $fim, true) : [];
    $layout = (string)($options['layout'] ?? '006');
    $tipoEscrituracao = (string)($options['tipo_escrituracao'] ?? '0');
    $activity = (string)($options['activity'] ?? '1');
    $lines = [];
    $lines[] = fiscal_sped_line(['0000', $layout, $tipoEscrituracao, '', '', fiscal_date_br($ini, ''), fiscal_date_br($fim, ''), $company['name'], ($company['document'] ?: '00000000000000'), $company['uf'], '', '', '00', $activity]);
    $lines[] = fiscal_sped_line(['0001','0']);
    $lines[] = fiscal_sped_line(['0990','3']);
    $lines[] = fiscal_sped_line(['C001','0']);
    foreach ($entries as $x) {
      $lines[] = fiscal_sped_line(['C100','0','1',fiscal_xml_digits($x['supplier_doc'] ?? ''),'55','00',($x['series'] ?? ''),($x['number'] ?? ''),fiscal_xml_digits($x['access_key'] ?? ''),fiscal_date_br($x['issue_date'] ?? '', ''),fiscal_date_br($x['report_date'] ?? $x['entry_date'] ?? '', ''),fiscal_sped_num((float)($x['total_invoice'] ?? 0))]);
      foreach (fiscal_report_items($pdo, $companyId, (int)$x['id']) as $it) {
        $tax = fiscal_xml_item_tax_payload($pdo, $companyId, $it);
        $sys = fiscal_report_item_system_payload($it);
        $qtyReport = (float)($it['stock_quantity'] ?? 0);
        if ($qtyReport <= 0) $qtyReport = (float)($it['quantity'] ?? 0);
        $lines[] = fiscal_sped_line(['C170',(int)($it['item_number'] ?? 0),$sys['code'],$sys['name'],fiscal_sped_num($qtyReport, 4),$sys['unit'],fiscal_sped_num((float)($it['total_value'] ?? 0)),($tax['entry_pis_cst'] ?: $it['pis_cst']),($tax['entry_cofins_cst'] ?: $it['cofins_cst'])]);
      }
    }

    foreach ($nfces as $doc) {
      $items = fiscal_report_nfce_items($pdo, $companyId, (int)$doc['id']);
      $taxesMap = fiscal_report_document_taxes_map($pdo, $companyId, (int)$doc['id']);
      $cancelled = fiscal_report_nfce_is_cancelled($doc);
      $c175 = [];
      $totPis = 0.0; $totCofins = 0.0;
      if (!$cancelled) {
        foreach ($items as $it) {
          $s = fiscal_report_nfce_item_summary($it, $taxesMap);
          $key = $s['cfop'] . '|' . $s['pis_cst'] . '|' . number_format($s['pis_rate'], 4, '.', '') . '|' . $s['cofins_cst'] . '|' . number_format($s['cofins_rate'], 4, '.', '');
          if (!isset($c175[$key])) $c175[$key] = ['cfop'=>$s['cfop'],'vl_opr'=>0.0,'vl_desc'=>0.0,'pis_cst'=>$s['pis_cst'],'pis_base'=>0.0,'pis_rate'=>$s['pis_rate'],'pis_amount'=>0.0,'cofins_cst'=>$s['cofins_cst'],'cofins_base'=>0.0,'cofins_rate'=>$s['cofins_rate'],'cofins_amount'=>0.0];
          $c175[$key]['vl_opr'] += $s['product_value'];
          $c175[$key]['vl_desc'] += $s['discount'];
          $c175[$key]['pis_base'] += $s['pis_base'];
          $c175[$key]['pis_amount'] += $s['pis_amount'];
          $c175[$key]['cofins_base'] += $s['cofins_base'];
          $c175[$key]['cofins_amount'] += $s['cofins_amount'];
          $totPis += $s['pis_amount']; $totCofins += $s['cofins_amount'];
        }
      }
      $totalDoc = $cancelled ? 0 : (float)($doc['total_document'] ?? 0);
      $lines[] = fiscal_sped_line([
        'C100','1','0','', '65', $cancelled ? '02' : '00', ($doc['series'] ?? ''), ($doc['number'] ?? ''), fiscal_xml_digits($doc['access_key'] ?? ''),
        fiscal_date_br($doc['issue_datetime'] ?? $doc['report_date'] ?? '', ''), fiscal_date_br($doc['issue_datetime'] ?? $doc['report_date'] ?? '', ''), fiscal_sped_num($totalDoc), '0',
        fiscal_sped_num($cancelled ? 0 : ($doc['total_discount'] ?? 0)), '0', fiscal_sped_num($cancelled ? 0 : ($doc['total_products'] ?? 0)), '9', '0', '0', fiscal_sped_num($cancelled ? 0 : ($doc['total_other'] ?? 0)),
        '0', '0', '0', '0', '0', fiscal_sped_num($totPis), fiscal_sped_num($totCofins), '0', '0'
      ]);
      ksort($c175, SORT_STRING);
      foreach ($c175 as $g) {
        $lines[] = fiscal_sped_line(['C175',$g['cfop'],fiscal_sped_num($g['vl_opr']),fiscal_sped_num($g['vl_desc'], 2, true),$g['pis_cst'],fiscal_sped_num($g['pis_base'], 2, true),fiscal_sped_num($g['pis_rate'], 4, true),'','',fiscal_sped_num($g['pis_amount'], 2, true),$g['cofins_cst'],fiscal_sped_num($g['cofins_base'], 2, true),fiscal_sped_num($g['cofins_rate'], 4, true),'','',fiscal_sped_num($g['cofins_amount'], 2, true),'','']);
      }
    }

    $cCount = 1;
    foreach ($lines as $ln) if (str_starts_with($ln, '|C') && !str_starts_with($ln, '|C990|')) $cCount++;
    $lines[] = fiscal_sped_line(['C990', (string)$cCount]);
    $lines = fiscal_sped_append_block9($lines);
    $lines[] = '';
    return implode("\r\n", $lines);
  }
}
