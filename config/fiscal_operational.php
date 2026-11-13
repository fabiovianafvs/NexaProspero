<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Helpers operacionais do módulo Fiscal.
 * Camada usada para: configuração fiscal, séries, certificado, Radar XML e parser NF-e/NFC-e/NFS-e.
 */

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_order_composition.php';
require_once __DIR__ . '/detailed_order_module.php';
require_once __DIR__ . '/product_composition.php';

if (!function_exists('fiscal_op_tables')) {
  function fiscal_op_tables(): array {
    return [
      'fiscal_company_config' => 'Configuração fiscal por empresa',
      'fiscal_certificates' => 'Certificados digitais',
      'fiscal_document_series' => 'Séries e numeração',
      'fiscal_operation_natures' => 'Naturezas de operação',
      'fiscal_documents' => 'Documentos fiscais',
      'fiscal_document_participants' => 'Participantes do documento',
      'fiscal_document_items' => 'Itens do documento',
      'fiscal_document_item_taxes' => 'Impostos por item',
      'fiscal_document_payments' => 'Pagamentos',
      'fiscal_document_installments' => 'Duplicatas/vencimentos',
      'fiscal_document_events' => 'Eventos fiscais',
      'fiscal_inutilizations' => 'Inutilizações de NF-e/NFC-e',
      'fiscal_emission_queue' => 'Fila ACBr',
      'fiscal_xml_radar' => 'Radar XML',
      'fiscal_xml_radar_items' => 'Itens do Radar XML',
      'fiscal_supplier_product_map' => 'Mapa produto fornecedor x interno',
      'purchase_invoices' => 'Compras importadas',
      'purchase_invoice_items' => 'Itens da compra',
      'purchase_invoice_installments' => 'Parcelas da compra',
      'fiscal_nfe_details' => 'Detalhes NF-e/NFC-e',
      'fiscal_nfse_details' => 'Detalhes NFS-e',
      'fiscal_document_references' => 'Documentos referenciados',
    ];
  }
}

if (!function_exists('fiscal_op_schema_status')) {
  function fiscal_op_schema_status(PDO $pdo): array {
    $rows = [];
    foreach (fiscal_op_tables() as $table => $label) {
      $exists = fiscal_table_exists($pdo, $table);
      $count = null;
      if ($exists) {
        try { $count = (int)$pdo->query('SELECT COUNT(*) FROM `' . $table . '`')->fetchColumn(); } catch (Throwable $e) { $count = null; }
      }
      $rows[] = ['table' => $table, 'label' => $label, 'exists' => $exists, 'count' => $count];
    }
    return $rows;
  }
}

if (!function_exists('fiscal_op_schema_ok')) {
  function fiscal_op_schema_ok(PDO $pdo): bool {
    foreach (fiscal_op_tables() as $table => $_label) if (!fiscal_table_exists($pdo, $table)) return false;
    return true;
  }
}

if (!function_exists('fiscal_op_now')) {
  function fiscal_op_now(): string { return date('Y-m-d H:i:s'); }
}

if (!function_exists('fiscal_digits')) {
  function fiscal_digits($value): string { return preg_replace('/\D+/', '', (string)$value); }
}

if (!function_exists('fiscal_sql_datetime')) {
  function fiscal_sql_datetime($value): ?string {
    $s = trim((string)$value);
    if ($s === '') return null;
    try { return (new DateTimeImmutable($s))->format('Y-m-d H:i:s'); } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('fiscal_sql_date')) {
  function fiscal_sql_date($value): ?string {
    $s = trim((string)$value);
    if ($s === '') return null;
    try { return (new DateTimeImmutable($s))->format('Y-m-d'); } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('fiscal_money_xml')) {
  function fiscal_money_xml($value): float {
    $s = trim((string)$value);
    if ($s === '') return 0.0;
    $s = str_replace(',', '.', $s);
    return round((float)$s, 6);
  }
}

if (!function_exists('fiscal_op_bool')) {
  function fiscal_op_bool(array $data, string $key): int { return !empty($data[$key]) ? 1 : 0; }
}

if (!function_exists('fiscal_normalize_crt')) {
  /**
   * Normaliza valores antigos do CRT, inclusive quando foram gravados como texto
   * (ex.: "Simples Nacional" ou "1 - Simples Nacional").
   */
  function fiscal_normalize_crt($value, string $regimeName = ''): string {
    $raw = trim((string)$value);
    if (preg_match('/^\s*([1-4])(?:\D|$)/', $raw, $m)) return (string)$m[1];

    $label = trim($raw . ' ' . $regimeName);
    if ($label === '') return '';
    $label = function_exists('mb_strtolower') ? mb_strtolower($label, 'UTF-8') : strtolower($label);
    if (function_exists('iconv')) {
      $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $label);
      if (is_string($ascii) && $ascii !== '') $label = strtolower($ascii);
    }

    if (strpos($label, 'mei') !== false) return '4';
    if (strpos($label, 'excesso') !== false || strpos($label, 'sublimite') !== false) return '2';
    if (strpos($label, 'simples') !== false) return '1';
    if (strpos($label, 'regime normal') !== false || strpos($label, 'lucro real') !== false || strpos($label, 'lucro presumido') !== false) return '3';
    return '';
  }
}

if (!function_exists('fiscal_company_default_config')) {
  function fiscal_company_default_config(): array {
    return [
      'environment' => 'HOMOLOGACAO',
      'company_name' => '', 'company_trade_name' => '', 'company_cnpj' => '', 'state_registration' => '', 'municipal_registration' => '',
      'uf' => 'MG', 'city_ibge' => '', 'company_city' => '', 'crt' => '', 'regime_name' => '',
      'company_address' => '', 'company_number' => '', 'company_complement' => '', 'company_district' => '', 'company_zip' => '',
      'company_contact' => '', 'company_phone' => '', 'company_fax' => '',
      'nfe_enabled' => 0, 'nfce_enabled' => 0, 'nfse_enabled' => 0, 'fiscal_emission_enabled' => 1, 'save_order_enabled' => 1,
      'order_auto_print_enabled' => 1, 'order_print_disabled' => 0, 'order_modern_print_enabled' => 0, 'order_modern_print_default' => 'A4', 'print_pack_58mm_enabled' => 0,
      'contingency_enabled' => 0,
      'nfce_offline_contingency_enabled' => 1,
      'nfe_svc_contingency_enabled' => 1,
      'nfe_svc_type' => 'AUTO',
      'contingency_login_alert_enabled' => 1,
      'contingency_status_check_enabled' => 1,
      'contingency_reason' => 'Falha de comunicação com o ambiente autorizador da SEFAZ.',
      'emission_adapter' => 'NFEPHP',
      'acbr_mode' => 'ACBRMONITOR', 'acbr_host' => '127.0.0.1', 'acbr_port' => '3434',
      'xml_out_path' => '', 'xml_in_path' => '', 'danfe_path' => '',
      'nfce_csc_id' => '', 'nfce_csc_token' => '', 'nfce_qrcode_version' => '2.00',
      // CFOP usado na NF-e posterior/vinculada a NFC-e. Não usar 5.929/6.929 por padrão,
      // pois esses CFOPs acionam validações de Cupom Fiscal/ECF e podem rejeitar quando a origem é NFC-e.
      'nfe_nfce_referenced_cfop_internal' => '5949', 'nfe_nfce_referenced_cfop_external' => '6949',
      'nfse_provider' => 'NACIONAL', 'nfse_national_standard' => 1,
      'nfse_pronim_profile' => 'ITABIRA_MG',
      'nfse_pronim_hom_base' => 'https://reformatributaria.govbr.cloud/NFSe.Api.Teste/NotaNacional',
      'nfse_pronim_prod_base' => 'https://webapp1-itabira.cidade360.cloud/NFSe.Api/NotaNacional',
      'notes' => ''
    ];
  }
}

if (!function_exists('fiscal_company_config')) {
  function fiscal_company_config(PDO $pdo, int $companyId): array {
    static $cache = [];
    $default = fiscal_company_default_config();
    $key = (int)$companyId;
    if (array_key_exists($key, $cache)) return $cache[$key];
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    if (!fiscal_table_exists($pdo, 'fiscal_company_config')) return $cache[$key] = $default;
    try {
      $st = $pdo->prepare('SELECT * FROM fiscal_company_config WHERE company_id = ? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      $merged = $row ? array_merge($default, $row) : $default;
      $merged['crt'] = fiscal_normalize_crt($merged['crt'] ?? '', (string)($merged['regime_name'] ?? ''));
      return $cache[$key] = $merged;
    } catch (Throwable $e) {
      return $cache[$key] = $default;
    }
  }
}

if (!function_exists('fiscal_save_company_config')) {
  function fiscal_save_company_config(PDO $pdo, int $companyId, array $data): void {
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    if (!fiscal_table_exists($pdo, 'fiscal_company_config')) {
      throw new RuntimeException('Tabela fiscal_company_config não encontrada. Importe o patch SQL operacional antes de salvar as configurações fiscais.');
    }
    if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_name', 'VARCHAR(180) NULL AFTER environment');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_trade_name', 'VARCHAR(180) NULL AFTER company_name');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_cnpj', 'VARCHAR(14) NULL AFTER company_trade_name');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'state_registration', 'VARCHAR(30) NULL AFTER company_cnpj');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'municipal_registration', 'VARCHAR(30) NULL AFTER state_registration');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_city', 'VARCHAR(120) NULL AFTER city_ibge');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_address', 'VARCHAR(180) NULL AFTER regime_name');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_number', 'VARCHAR(40) NULL AFTER company_address');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_complement', 'VARCHAR(120) NULL AFTER company_number');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_district', 'VARCHAR(120) NULL AFTER company_complement');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_zip', 'VARCHAR(20) NULL AFTER company_district');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_contact', 'VARCHAR(120) NULL AFTER company_zip');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_phone', 'VARCHAR(20) NULL AFTER company_contact');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'company_fax', 'VARCHAR(20) NULL AFTER company_phone');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'fiscal_emission_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER nfse_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'save_order_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER fiscal_emission_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'order_auto_print_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER save_order_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'order_print_disabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER order_auto_print_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'print_pack_58mm_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER order_print_disabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'contingency_enabled', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER print_pack_58mm_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'nfce_offline_contingency_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER contingency_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'nfe_svc_contingency_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER nfce_offline_contingency_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'nfe_svc_type', "VARCHAR(20) NOT NULL DEFAULT 'AUTO' AFTER nfe_svc_contingency_enabled");
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'contingency_login_alert_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER nfe_svc_type');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'contingency_status_check_enabled', 'TINYINT(1) NOT NULL DEFAULT 1 AFTER contingency_login_alert_enabled');
        fiscal_add_column_if_missing($pdo, 'fiscal_company_config', 'contingency_reason', 'VARCHAR(255) NULL AFTER contingency_status_check_enabled');
    }
    $existingConfig = [];
    try {
      $stExisting = $pdo->prepare('SELECT * FROM fiscal_company_config WHERE company_id = ? LIMIT 1');
      $stExisting->execute([$companyId]);
      $existingConfig = $stExisting->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $existingConfig = []; }
    $postedCrt = fiscal_normalize_crt($data['crt'] ?? '', (string)($data['regime_name'] ?? ''));
    if ($postedCrt === '') {
      $postedCrt = fiscal_normalize_crt($existingConfig['crt'] ?? '', (string)($existingConfig['regime_name'] ?? ''));
    }

    $enableNfe = fiscal_op_bool($data, 'nfe_enabled');
    $enableNfce = fiscal_op_bool($data, 'nfce_enabled');
    $enableNfse = fiscal_op_bool($data, 'nfse_enabled');
    $enableSaveOrder = fiscal_op_bool($data, 'save_order_enabled');
    if (!$enableNfe && !$enableNfce && !$enableNfse && !$enableSaveOrder) {
      throw new RuntimeException('Marque ao menos uma opção de finalização: NFC-e, NF-e, NFS-e ou Somente Gravar Pedido.');
    }

    $payload = [
      'environment' => in_array(($data['environment'] ?? ''), ['HOMOLOGACAO','PRODUCAO'], true) ? $data['environment'] : 'HOMOLOGACAO',
      'company_name' => trim((string)($data['company_name'] ?? '')),
      'company_trade_name' => trim((string)($data['company_trade_name'] ?? '')),
      'company_cnpj' => substr(fiscal_digits($data['company_cnpj'] ?? ''), 0, 14),
      'state_registration' => trim((string)($data['state_registration'] ?? '')),
      'municipal_registration' => trim((string)($data['municipal_registration'] ?? '')),
      'uf' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($data['uf'] ?? '')), 0, 2)),
      'city_ibge' => substr(fiscal_digits($data['city_ibge'] ?? ''), 0, 7),
      'company_city' => trim((string)($data['company_city'] ?? '')),
      'crt' => $postedCrt,
      'regime_name' => trim((string)($data['regime_name'] ?? '')),
      'company_address' => trim((string)($data['company_address'] ?? '')),
      'company_number' => trim((string)($data['company_number'] ?? '')),
      'company_complement' => trim((string)($data['company_complement'] ?? '')),
      'company_district' => trim((string)($data['company_district'] ?? '')),
      'company_zip' => substr(fiscal_digits($data['company_zip'] ?? ''), 0, 8),
      'company_contact' => trim((string)($data['company_contact'] ?? '')),
      'company_phone' => substr(fiscal_digits($data['company_phone'] ?? ''), 0, 12),
      'company_fax' => substr(fiscal_digits($data['company_fax'] ?? ''), 0, 10),
      'nfe_enabled' => $enableNfe,
      'nfce_enabled' => $enableNfce,
      'nfse_enabled' => $enableNfse,
      'fiscal_emission_enabled' => ($enableNfe || $enableNfce || $enableNfse) ? 1 : 0,
      'save_order_enabled' => $enableSaveOrder,
      'order_auto_print_enabled' => fiscal_op_bool($data, 'order_auto_print_enabled'),
      'order_print_disabled' => fiscal_op_bool($data, 'order_print_disabled'),
      'print_pack_58mm_enabled' => fiscal_op_bool($data, 'print_pack_58mm_enabled'),
      'contingency_enabled' => fiscal_op_bool($data, 'contingency_enabled'),
      'nfce_offline_contingency_enabled' => fiscal_op_bool($data, 'nfce_offline_contingency_enabled'),
      'nfe_svc_contingency_enabled' => fiscal_op_bool($data, 'nfe_svc_contingency_enabled'),
      'nfe_svc_type' => in_array(strtoupper((string)($data['nfe_svc_type'] ?? 'AUTO')), ['AUTO','SVCAN','SVCRS'], true) ? strtoupper((string)$data['nfe_svc_type']) : 'AUTO',
      'contingency_login_alert_enabled' => fiscal_op_bool($data, 'contingency_login_alert_enabled'),
      'contingency_status_check_enabled' => fiscal_op_bool($data, 'contingency_status_check_enabled'),
      'contingency_reason' => (function() use ($data) { $v = trim((string)($data['contingency_reason'] ?? '')); $len = function_exists('mb_strlen') ? mb_strlen($v) : strlen($v); return $len >= 15 ? (function_exists('mb_substr') ? mb_substr($v, 0, 255) : substr($v, 0, 255)) : 'Falha de comunicação com o ambiente autorizador da SEFAZ.'; })(),
      'emission_adapter' => in_array(($data['emission_adapter'] ?? ''), ['NFEPHP','ACBRMONITOR','ACBRLIB','OUTRO'], true) ? $data['emission_adapter'] : 'NFEPHP',
      'acbr_mode' => in_array(($data['acbr_mode'] ?? ''), ['ACBRMONITOR','ACBRLIB','OUTRO'], true) ? $data['acbr_mode'] : 'ACBRMONITOR',
      'acbr_host' => trim((string)($data['acbr_host'] ?? '')),
      'acbr_port' => (int)($data['acbr_port'] ?? 0) ?: null,
      'xml_out_path' => trim((string)($data['xml_out_path'] ?? '')),
      'xml_in_path' => trim((string)($data['xml_in_path'] ?? '')),
      'danfe_path' => trim((string)($data['danfe_path'] ?? '')),
      'nfce_csc_id' => trim((string)($data['nfce_csc_id'] ?? '')),
      'nfce_csc_token' => trim((string)($data['nfce_csc_token'] ?? '')),
      'nfce_qrcode_version' => trim((string)($data['nfce_qrcode_version'] ?? '')),
      'nfe_nfce_referenced_cfop_internal' => substr(fiscal_digits($data['nfe_nfce_referenced_cfop_internal'] ?? '5949') ?: '5949', 0, 4),
      'nfe_nfce_referenced_cfop_external' => substr(fiscal_digits($data['nfe_nfce_referenced_cfop_external'] ?? '6949') ?: '6949', 0, 4),
      'nfse_provider' => in_array(strtoupper(trim((string)($data['nfse_provider'] ?? 'NACIONAL'))), ['NACIONAL','PRONIM'], true) ? strtoupper(trim((string)($data['nfse_provider'] ?? 'NACIONAL'))) : 'NACIONAL',
      'nfse_national_standard' => fiscal_op_bool($data, 'nfse_national_standard'),
      'nfse_pronim_profile' => strtoupper(substr(trim((string)($data['nfse_pronim_profile'] ?? 'ITABIRA_MG')), 0, 40)),
      'nfse_pronim_hom_base' => rtrim(substr(trim((string)($data['nfse_pronim_hom_base'] ?? 'https://reformatributaria.govbr.cloud/NFSe.Api.Teste/NotaNacional')), 0, 255), '/'),
      'nfse_pronim_prod_base' => rtrim(substr(trim((string)($data['nfse_pronim_prod_base'] ?? 'https://webapp1-itabira.cidade360.cloud/NFSe.Api/NotaNacional')), 0, 255), '/'),
      'notes' => trim((string)($data['notes'] ?? '')),
    ];
    // V11.45.18: os novos modelos do pedido são opt-in e dependem da migration controlada.
    // Antes da migration, salvar outras configurações fiscais continua funcionando sem tentar gravar colunas inexistentes.
    if (fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_enabled')
        && fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_default')) {
      // Seleção única de layout (tela de Configurações fiscais): mapeia para as colunas existentes.
      $layoutChoice = strtolower(trim((string)($data['order_print_layout'] ?? '')));
      $layoutMap = ['legacy'=>[0,'A4'], 'modern_a4'=>[1,'A4'], 'modern_72mm'=>[1,'72MM'], 'duplicata'=>[1,'DUPLICATA']];
      if (isset($layoutMap[$layoutChoice])) {
        $payload['order_modern_print_enabled'] = $layoutMap[$layoutChoice][0];
        $payload['order_modern_print_default'] = $layoutMap[$layoutChoice][1];
      } else {
        $payload['order_modern_print_enabled'] = fiscal_op_bool($data, 'order_modern_print_enabled');
        $orderModernDefault = strtoupper(trim((string)($data['order_modern_print_default'] ?? 'A4')));
        $payload['order_modern_print_default'] = in_array($orderModernDefault, ['A4','72MM','DUPLICATA'], true) ? $orderModernDefault : 'A4';
      }
    }

    $cols = array_keys($payload);
    $updates = implode(', ', array_map(fn($c) => "`$c`=VALUES(`$c`)", $cols)) . ', updated_at=NOW()';
    $sql = 'INSERT INTO fiscal_company_config (company_id, `' . implode('`,`', $cols) . '`, created_at) VALUES (?,' . rtrim(str_repeat('?,', count($cols)), ',') . ',NOW()) ON DUPLICATE KEY UPDATE ' . $updates;
    $pdo->prepare($sql)->execute(array_merge([$companyId], array_values($payload)));
  }
}


if (!function_exists('fiscal_checkout_options')) {
  function fiscal_checkout_options(array $config): array {
    return [
      'nfce' => !empty($config['nfce_enabled']),
      'nfe' => !empty($config['nfe_enabled']),
      'nfse' => !empty($config['nfse_enabled']),
      'none' => !array_key_exists('save_order_enabled', $config) || !empty($config['save_order_enabled']),
    ];
  }
}

if (!function_exists('fiscal_checkout_option_enabled')) {
  function fiscal_checkout_option_enabled(array $config, string $option): bool {
    $options = fiscal_checkout_options($config);
    return !empty($options[$option]);
  }
}

// V11.35/V11.46 - emissão fiscal Multiempresa protegida por modelo e somente em HOMOLOGAÇÃO.
// V11.35 liberou NFC-e (65); V11.46 acrescenta NF-e (55). Produção e eventos fiscais especiais
// permanecem bloqueados no contexto F11 cruzado.
if (!function_exists('fiscal_multi_company_homologation_scope')) {
  function fiscal_multi_company_homologation_scope(string $model): string {
    return $model === '55' ? 'fiscal_nfe_homologation' : 'fiscal_nfce_homologation';
  }
}

if (!function_exists('fiscal_multi_company_document_homologation_is_cross')) {
  function fiscal_multi_company_document_homologation_is_cross(PDO $pdo, string $model): bool {
    $model = $model === '55' ? '55' : '65';
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, fiscal_multi_company_homologation_scope($model));
  }
}

if (!function_exists('fiscal_multi_company_document_homologation_assert')) {
  function fiscal_multi_company_document_homologation_assert(PDO $pdo, int $companyId, string $model, string $operation = 'emitir'): array {
    $model = $model === '55' ? '55' : '65';
    if (!fiscal_multi_company_document_homologation_is_cross($pdo, $model)) {
      return fiscal_company_config($pdo, $companyId);
    }

    $scope = fiscal_multi_company_homologation_scope($model);
    $operationalCompanyId = function_exists('multi_company_operational_company_id')
      ? (int)multi_company_operational_company_id($pdo, $scope)
      : (int)$companyId;
    $docLabel = $model === '55' ? 'NF-e' : 'NFC-e';

    if ($operationalCompanyId <= 0 || $companyId !== $operationalCompanyId) {
      throw new RuntimeException('V11.46 Multiempresa: empresa fiscal operacional inválida para ' . $docLabel . '.');
    }
    // V11.46.3: no fechamento originado pelo Pedido PDV, Checkout ou PDV Resumido, a autorização fiscal deve seguir o
    // mesmo modelo da empresa principal. O usuário precisa ter a permissão efetiva do
    // próprio PDV na empresa selecionada; não exigimos adicionalmente a permissão da
    // Central Fiscal nem o vínculo comercial do módulo Fiscal apenas para concluir a
    // venda. A Central Fiscal continua exigindo fiscal_documentos + módulo Fiscal.
    $salesPermission = trim((string)($GLOBALS['NEXA_MULTI_COMPANY_FISCAL_SALES_PERMISSION'] ?? ''));
    $salesFlowAllowed = false;
    $fiscalPermissionAllowed = false;
    if (function_exists('multi_company_context_permission_allowed')) {
      if (in_array($salesPermission, ['gestao_pdv','gestao_pdv_checkout','gestao_pdv_resumido'], true)) {
        $salesFlowAllowed = multi_company_context_permission_allowed($pdo, $salesPermission);
      }
      $fiscalPermissionAllowed = $salesFlowAllowed
        || multi_company_context_permission_allowed($pdo, 'fiscal_documentos');
    }
    if (!$fiscalPermissionAllowed) {
      throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão para emissão/consulta de ' . $docLabel . '.');
    }
    if (!$salesFlowAllowed && function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'fiscal')) {
      throw new RuntimeException('O módulo Fiscal não está habilitado para a empresa selecionada.');
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    // V12: produção liberada por Grupo Empresarial; sem a liberação, somente HOMOLOGAÇÃO.
    if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, $environment))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a ' . $docLabel) : ('V11.46 Multiempresa: ' . $docLabel . ' pelo F11 está liberada somente em HOMOLOGAÇÃO.'));
    }
    if (empty($cfg['fiscal_emission_enabled'])) {
      throw new RuntimeException('Habilite a emissão fiscal nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '55' && empty($cfg['nfe_enabled'])) {
      throw new RuntimeException('Habilite a NF-e nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '65' && empty($cfg['nfce_enabled'])) {
      throw new RuntimeException('Habilite a NFC-e nas Configurações Fiscais da empresa selecionada.');
    }
    if (strtolower($operation) === 'emitir') {
      $problems = $model === '55'
        ? (function_exists('fiscal_nfe_config_problems') ? fiscal_nfe_config_problems($pdo, $companyId) : [])
        : (function_exists('fiscal_nfce_config_problems') ? fiscal_nfce_config_problems($pdo, $companyId) : []);
      if ($problems) {
        throw new RuntimeException($docLabel . ' ainda não está pronta na empresa selecionada: ' . implode(' ', $problems));
      }
    }
    return $cfg;
  }
}

// V11.47 - cancelamento fiscal Multiempresa: evento 110111 somente em HOMOLOGAÇÃO.
if (!function_exists('fiscal_multi_company_cancel_homologation_is_cross')) {
  function fiscal_multi_company_cancel_homologation_is_cross(PDO $pdo): bool {
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'fiscal_cancel_homologation');
  }
}

if (!function_exists('fiscal_multi_company_cancel_homologation_assert')) {
  function fiscal_multi_company_cancel_homologation_assert(PDO $pdo, int $companyId, array $doc): array {
    if (!fiscal_multi_company_cancel_homologation_is_cross($pdo)) {
      return fiscal_company_config($pdo, $companyId);
    }

    $operationalCompanyId = function_exists('multi_company_operational_company_id')
      ? (int)multi_company_operational_company_id($pdo, 'fiscal_cancel_homologation')
      : (int)$companyId;
    if ($operationalCompanyId <= 0 || $companyId !== $operationalCompanyId) {
      throw new RuntimeException('V11.47 Multiempresa: empresa fiscal operacional inválida para cancelamento.');
    }
    if (!function_exists('multi_company_context_permission_allowed')
        || !multi_company_context_permission_allowed($pdo, 'fiscal_documentos')) {
      throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão fiscal_documentos para cancelar documentos fiscais.');
    }
    if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'fiscal')) {
      throw new RuntimeException('O módulo Fiscal não está habilitado para a empresa selecionada.');
    }

    $model = (string)($doc['model'] ?? '');
    if (!in_array($model, ['55','65'], true)) {
      throw new RuntimeException('V11.47 Multiempresa: cancelamento homologado somente para NF-e/NFC-e.');
    }
    if (strtoupper(trim((string)($doc['status'] ?? ''))) !== 'AUTORIZADA') {
      throw new RuntimeException('V11.47 Multiempresa: somente documento AUTORIZADO pode ser cancelado.');
    }
    if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, (string)($doc['environment'] ?? '')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('o cancelamento de nota') : 'V11.47 Multiempresa: somente documento emitido em HOMOLOGAÇÃO pode ser cancelado pelo F11.');
    }
    if (strtoupper(trim((string)($doc['source_type'] ?? ''))) !== 'PEDIDO' || (int)($doc['source_id'] ?? 0) <= 0) {
      throw new RuntimeException('V11.47 Multiempresa: nesta etapa o cancelamento pelo F11 está homologado somente para documento originado de Pedido. Documentos de O.S. permanecem na fila fiscal exclusiva.');
    }
    $orderId = (int)$doc['source_id'];

    // Pré-voo antes de transmitir o evento à SEFAZ. Evita cancelar o documento fiscal
    // e descobrir somente depois que o Pedido não poderia ser revertido com segurança.
    $otherDocSt = $pdo->prepare("SELECT id,model,number FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? AND id<>? AND UPPER(COALESCE(status,''))='AUTORIZADA' ORDER BY id DESC LIMIT 1");
    $otherDocSt->execute([$companyId, $orderId, (int)($doc['id'] ?? 0)]);
    $otherAuthorized = $otherDocSt->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($otherAuthorized) {
      $otherLabel = ((string)($otherAuthorized['model'] ?? '') === '55') ? 'NF-e' : 'NFC-e';
      throw new RuntimeException('V11.47 Multiempresa: o Pedido possui outro documento fiscal autorizado (' . $otherLabel . ' nº ' . (string)($otherAuthorized['number'] ?? '') . '). Regularize o outro documento antes do cancelamento.');
    }

    require_once __DIR__ . '/billing_module.php';
    if (function_exists('billing_table_exists') && billing_table_exists($pdo, 'finance_boleto_charges')) {
      $charges = function_exists('billing_order_charges') ? billing_order_charges($pdo, $companyId, $orderId, false) : [];
      foreach ($charges as $charge) {
        $status = strtoupper(trim((string)($charge['status'] ?? '')));
        if ($status === 'CANCELADO') continue;
        $localStatuses = function_exists('billing_charge_local_cancel_statuses') ? billing_charge_local_cancel_statuses() : [];
        $hasBankIdentity = function_exists('billing_charge_has_bank_identity') ? billing_charge_has_bank_identity($charge) : true;
        if (!in_array($status, $localStatuses, true) || $hasBankIdentity) {
          $detail = function_exists('billing_charge_local_cancel_message') ? billing_charge_local_cancel_message($charge) : 'Regularize a cobrança bancária antes de cancelar a nota.';
          throw new RuntimeException('V11.47 Multiempresa: o Pedido possui boleto #' . (int)($charge['id'] ?? 0) . ' em situação ' . $status . '. ' . $detail);
        }
      }
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    if (strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO'))) !== strtoupper(trim((string)($doc['environment'] ?? '')))) {
      throw new RuntimeException('Multiempresa (F11): o ambiente atual da empresa (' . strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO'))) . ') é diferente do ambiente em que a nota foi emitida.');
    }
    if (empty($cfg['fiscal_emission_enabled'])) {
      throw new RuntimeException('A emissão fiscal está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '55' && empty($cfg['nfe_enabled'])) {
      throw new RuntimeException('A NF-e está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '65' && empty($cfg['nfce_enabled'])) {
      throw new RuntimeException('A NFC-e está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    return $cfg;
  }
}


// V11.48 - Carta de Correção Eletrônica Multiempresa: evento 110110 somente para NF-e em HOMOLOGAÇÃO.
if (!function_exists('fiscal_multi_company_cce_homologation_is_cross')) {
  function fiscal_multi_company_cce_homologation_is_cross(PDO $pdo): bool {
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'fiscal_cce_homologation');
  }
}

if (!function_exists('fiscal_multi_company_cce_homologation_assert')) {
  function fiscal_multi_company_cce_homologation_assert(PDO $pdo, int $companyId, array $doc): array {
    if (!fiscal_multi_company_cce_homologation_is_cross($pdo)) {
      return fiscal_company_config($pdo, $companyId);
    }

    $operationalCompanyId = function_exists('multi_company_operational_company_id')
      ? (int)multi_company_operational_company_id($pdo, 'fiscal_cce_homologation')
      : (int)$companyId;
    if ($operationalCompanyId <= 0 || $companyId !== $operationalCompanyId) {
      throw new RuntimeException('V11.48 Multiempresa: empresa fiscal operacional inválida para Carta de Correção.');
    }
    if (!function_exists('multi_company_context_permission_allowed')
        || !multi_company_context_permission_allowed($pdo, 'fiscal_documentos')) {
      throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão fiscal_documentos para emitir Carta de Correção.');
    }
    if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'fiscal')) {
      throw new RuntimeException('O módulo Fiscal não está habilitado para a empresa selecionada.');
    }

    if ((string)($doc['model'] ?? '') !== '55') {
      throw new RuntimeException('V11.48 Multiempresa: Carta de Correção pelo F11 está homologada somente para NF-e modelo 55.');
    }
    if (strtoupper(trim((string)($doc['status'] ?? ''))) !== 'AUTORIZADA') {
      throw new RuntimeException('V11.48 Multiempresa: somente NF-e AUTORIZADA pode receber Carta de Correção.');
    }
    if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, (string)($doc['environment'] ?? '')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a Carta de Correção') : 'V11.48 Multiempresa: Carta de Correção pelo F11 está liberada somente em HOMOLOGAÇÃO.');
    }
    if (strtoupper(trim((string)($doc['source_type'] ?? ''))) !== 'PEDIDO' || (int)($doc['source_id'] ?? 0) <= 0) {
      throw new RuntimeException('V11.48 Multiempresa: nesta etapa a Carta de Correção pelo F11 está homologada somente para NF-e originada de Pedido. Documentos de O.S. permanecem na fila fiscal exclusiva.');
    }
    $accessKey = preg_replace('/\\D+/', '', (string)($doc['access_key'] ?? ''));
    if (strlen($accessKey) !== 44) {
      throw new RuntimeException('V11.48 Multiempresa: a NF-e não possui chave de acesso válida para Carta de Correção.');
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    if (strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO'))) !== strtoupper(trim((string)($doc['environment'] ?? '')))) {
      throw new RuntimeException('Multiempresa (F11): o ambiente atual da empresa é diferente do ambiente em que a NF-e foi emitida.');
    }
    if (empty($cfg['fiscal_emission_enabled'])) {
      throw new RuntimeException('A emissão fiscal está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if (empty($cfg['nfe_enabled'])) {
      throw new RuntimeException('A NF-e está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    return $cfg;
  }
}

// V11.49 - Inutilização fiscal Multiempresa: NF-e/NFC-e somente em HOMOLOGAÇÃO.
if (!function_exists('fiscal_multi_company_inutilization_homologation_is_cross')) {
  function fiscal_multi_company_inutilization_homologation_is_cross(PDO $pdo): bool {
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'fiscal_inutilization_homologation');
  }
}

if (!function_exists('fiscal_multi_company_inutilization_homologation_assert')) {
  function fiscal_multi_company_inutilization_homologation_assert(PDO $pdo, int $companyId, string $model): array {
    $model = preg_replace('/\\D+/', '', $model);
    if (!fiscal_multi_company_inutilization_homologation_is_cross($pdo)) {
      return fiscal_company_config($pdo, $companyId);
    }

    $operationalCompanyId = function_exists('multi_company_operational_company_id')
      ? (int)multi_company_operational_company_id($pdo, 'fiscal_inutilization_homologation')
      : (int)$companyId;
    if ($operationalCompanyId <= 0 || $companyId !== $operationalCompanyId) {
      throw new RuntimeException('V11.49 Multiempresa: empresa fiscal operacional inválida para inutilização.');
    }
    if (!function_exists('multi_company_context_permission_allowed')
        || !multi_company_context_permission_allowed($pdo, 'fiscal_inutilizacao')) {
      throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão fiscal_inutilizacao para inutilizar numeração fiscal.');
    }
    if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'fiscal')) {
      throw new RuntimeException('O módulo Fiscal não está habilitado para a empresa selecionada.');
    }
    if (!in_array($model, ['55','65'], true)) {
      throw new RuntimeException('V11.49 Multiempresa: inutilização homologada somente para NF-e modelo 55 ou NFC-e modelo 65.');
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, (string)($cfg['environment'] ?? 'HOMOLOGACAO')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a inutilização') : 'V11.49 Multiempresa: inutilização pelo F11 está liberada somente em HOMOLOGAÇÃO.');
    }
    if (empty($cfg['fiscal_emission_enabled'])) {
      throw new RuntimeException('A emissão fiscal está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '55' && empty($cfg['nfe_enabled'])) {
      throw new RuntimeException('A NF-e está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if ($model === '65' && empty($cfg['nfce_enabled'])) {
      throw new RuntimeException('A NFC-e está desativada nas Configurações Fiscais da empresa selecionada.');
    }
    if (strtoupper(trim((string)($cfg['emission_adapter'] ?? 'NFEPHP'))) !== 'NFEPHP') {
      throw new RuntimeException('V11.49 Multiempresa: a inutilização pelo F11 está homologada somente para o motor NFePHP/sped-nfe.');
    }
    return $cfg;
  }
}

// Wrappers mantidos para compatibilidade com o código V11.35.
if (!function_exists('fiscal_multi_company_nfce_homologation_is_cross')) {
  function fiscal_multi_company_nfce_homologation_is_cross(PDO $pdo): bool {
    return fiscal_multi_company_document_homologation_is_cross($pdo, '65');
  }
}
if (!function_exists('fiscal_multi_company_nfce_homologation_assert')) {
  function fiscal_multi_company_nfce_homologation_assert(PDO $pdo, int $companyId, string $operation = 'emitir'): array {
    return fiscal_multi_company_document_homologation_assert($pdo, $companyId, '65', $operation);
  }
}
if (!function_exists('fiscal_multi_company_nfe_homologation_is_cross')) {
  function fiscal_multi_company_nfe_homologation_is_cross(PDO $pdo): bool {
    return fiscal_multi_company_document_homologation_is_cross($pdo, '55');
  }
}
if (!function_exists('fiscal_multi_company_nfe_homologation_assert')) {
  function fiscal_multi_company_nfe_homologation_assert(PDO $pdo, int $companyId, string $operation = 'emitir'): array {
    return fiscal_multi_company_document_homologation_assert($pdo, $companyId, '55', $operation);
  }
}

if (!function_exists('fiscal_print_pack_58mm_enabled')) {
  function fiscal_print_pack_58mm_enabled(PDO $pdo, int $companyId): bool {
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    return !empty($cfg['print_pack_58mm_enabled']);
  }
}

if (!function_exists('fiscal_order_auto_print_enabled')) {
  function fiscal_order_auto_print_enabled(PDO $pdo, int $companyId): bool {
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    return !array_key_exists('order_auto_print_enabled', $cfg) || !empty($cfg['order_auto_print_enabled']);
  }
}

if (!function_exists('fiscal_order_print_disabled')) {
  function fiscal_order_print_disabled(PDO $pdo, int $companyId): bool {
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    return !empty($cfg['order_print_disabled']);
  }
}

if (!function_exists('fiscal_order_modern_print_enabled')) {
  function fiscal_order_modern_print_enabled(PDO $pdo, int $companyId): bool {
    if (!fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_enabled')) return false;
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    return !empty($cfg['order_modern_print_enabled']);
  }
}

if (!function_exists('fiscal_order_modern_print_default')) {
  function fiscal_order_modern_print_default(PDO $pdo, int $companyId): string {
    if (!fiscal_column_exists($pdo, 'fiscal_company_config', 'order_modern_print_default')) return 'A4';
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    $value = strtoupper(trim((string)($cfg['order_modern_print_default'] ?? 'A4')));
    return in_array($value, ['A4','72MM','DUPLICATA'], true) ? $value : 'A4';
  }
}

if (!function_exists('fiscal_series_rows')) {
  function fiscal_series_rows(PDO $pdo, int $companyId): array {
    if (!fiscal_table_exists($pdo, 'fiscal_document_series')) return [];
    try {
      $st = $pdo->prepare('SELECT * FROM fiscal_document_series WHERE company_id = ? ORDER BY is_active DESC, model ASC, environment ASC, series ASC');
      $st->execute([$companyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('fiscal_save_series')) {
  function fiscal_save_series(PDO $pdo, int $companyId, array $data): void {
    $id = (int)($data['id'] ?? 0);
    $model = strtoupper(trim((string)($data['model'] ?? '')));
    if (!in_array($model, ['55','65','NFSE','DPS','RPS','SS'], true)) throw new RuntimeException('Modelo fiscal inválido.');
    $series = trim((string)($data['series'] ?? ''));
    if ($series === '') throw new RuntimeException('Informe a série.');
    $environment = in_array(($data['environment'] ?? ''), ['HOMOLOGACAO','PRODUCAO'], true) ? $data['environment'] : 'HOMOLOGACAO';
    $payload = [
      $model, $series, $environment,
      max(1, (int)($data['next_number'] ?? 1)),
      max(0, (int)($data['last_number'] ?? 0)),
      trim((string)($data['emission_type'] ?? 'NORMAL')) ?: 'NORMAL',
      !empty($data['is_active']) ? 1 : 0,
      trim((string)($data['notes'] ?? '')),
    ];
    if ($id > 0) {
      $stOwn = $pdo->prepare('SELECT id FROM fiscal_document_series WHERE company_id=? AND id=? LIMIT 1');
      $stOwn->execute([$companyId, $id]);
      if (!(int)($stOwn->fetchColumn() ?: 0)) throw new RuntimeException('Série fiscal não encontrada para esta empresa.');
      $payload[] = $companyId; $payload[] = $id;
      $pdo->prepare('UPDATE fiscal_document_series SET model=?, series=?, environment=?, next_number=?, last_number=?, emission_type=?, is_active=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute($payload);
    } else {
      array_unshift($payload, $companyId);
      $pdo->prepare('INSERT INTO fiscal_document_series (company_id, model, series, environment, next_number, last_number, emission_type, is_active, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,NOW())')->execute($payload);
    }
  }
}

if (!function_exists('fiscal_certificate_rows')) {
  function fiscal_certificate_rows(PDO $pdo, int $companyId): array {
    if (!fiscal_table_exists($pdo, 'fiscal_certificates')) return [];
    try {
      $st = $pdo->prepare('SELECT * FROM fiscal_certificates WHERE company_id = ? ORDER BY is_active DESC, valid_until DESC, id DESC');
      $st->execute([$companyId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('fiscal_cert_subject_to_string')) {
  function fiscal_cert_subject_to_string(array $subject): string {
    $parts = [];
    foreach (['CN','O','OU','L','ST','C','emailAddress'] as $key) {
      if (!array_key_exists($key, $subject)) continue;
      $value = $subject[$key];
      if (is_array($value)) $value = implode(' / ', array_map('strval', $value));
      $value = trim((string)$value);
      if ($value !== '') $parts[] = $key . '=' . $value;
    }
    if (!$parts) {
      foreach ($subject as $key => $value) {
        if (is_array($value)) $value = implode(' / ', array_map('strval', $value));
        $value = trim((string)$value);
        if ($value !== '') $parts[] = (string)$key . '=' . $value;
      }
    }
    return implode('; ', $parts);
  }
}

if (!function_exists('fiscal_cert_safe_unlink')) {
  function fiscal_cert_safe_unlink(string $path, int $companyId): bool {
    $path = trim($path);
    if ($path === '') return true;
    $base = realpath(dirname(__DIR__) . '/uploads/certificados/' . $companyId);
    $real = realpath($path);
    if ($base === false || $real === false || !is_file($real)) return true;
    $basePrefix = rtrim(str_replace('\\', '/', $base), '/') . '/';
    $realNorm = str_replace('\\', '/', $real);
    if (strpos($realNorm, $basePrefix) !== 0) return false;
    return @unlink($real);
  }
}

if (!function_exists('fiscal_pkcs12_run_openssl')) {
  function fiscal_pkcs12_run_openssl(array $args, string $password, string &$stderr = ''): bool {
    $stderr = '';
    if (!function_exists('proc_open')) return false;
    $bin = '';
    foreach (['/usr/bin/openssl','/usr/local/bin/openssl'] as $candidate) {
      if (is_file($candidate) && is_executable($candidate)) { $bin = $candidate; break; }
    }
    if ($bin === '') return false;
    $cmd = array_merge([$bin], $args);
    $desc = [0 => ['pipe','r'], 1 => ['pipe','w'], 2 => ['pipe','w']];
    $env = [];
    foreach (['PATH','HOME','LANG','LC_ALL'] as $key) {
      $value = getenv($key);
      if ($value !== false) $env[$key] = $value;
    }
    $env['NEXA_PFX_PASS'] = $password;
    $proc = @proc_open($cmd, $desc, $pipes, null, $env, ['bypass_shell' => true]);
    if (!is_resource($proc)) return false;
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $stderr = (string)stream_get_contents($pipes[2]); fclose($pipes[2]);
    $code = proc_close($proc);
    unset($env['NEXA_PFX_PASS']);
    return $code === 0;
  }
}

if (!function_exists('fiscal_pkcs12_normalize_legacy')) {
  function fiscal_pkcs12_normalize_legacy(string $absolutePath, string $password): bool {
    if ($absolutePath === '' || !is_file($absolutePath)) return false;
    $tmpBase = rtrim(sys_get_temp_dir(), DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'nexa_cert_' . bin2hex(random_bytes(8));
    if (!@mkdir($tmpBase, 0700, true)) return false;
    $pem = $tmpBase . DIRECTORY_SEPARATOR . 'bundle.pem';
    $normalized = $tmpBase . DIRECTORY_SEPARATOR . 'normalized.pfx';
    try {
      $err = '';
      $ok = fiscal_pkcs12_run_openssl(['pkcs12','-legacy','-in',$absolutePath,'-passin','env:NEXA_PFX_PASS','-nodes','-out',$pem], $password, $err);
      if (!$ok || !is_file($pem) || filesize($pem) < 100) return false;
      @chmod($pem, 0600);
      $err2 = '';
      $ok2 = fiscal_pkcs12_run_openssl(['pkcs12','-export','-in',$pem,'-out',$normalized,'-passout','env:NEXA_PFX_PASS'], $password, $err2);
      if (!$ok2 || !is_file($normalized) || filesize($normalized) < 100) return false;
      @chmod($normalized, 0600);
      $raw = @file_get_contents($normalized);
      if ($raw === false || $raw === '') return false;
      $certs = [];
      if (!@openssl_pkcs12_read($raw, $certs, $password) || empty($certs['cert']) || empty($certs['pkey'])) return false;
      if (!@copy($normalized, $absolutePath)) return false;
      @chmod($absolutePath, 0600);
      return true;
    } finally {
      if (is_file($pem)) @unlink($pem);
      if (is_file($normalized)) @unlink($normalized);
      @rmdir($tmpBase);
    }
  }
}

if (!function_exists('fiscal_extract_certificate_metadata')) {
  function fiscal_extract_certificate_metadata(string $absolutePath, string $password = '', string $extension = ''): array {
    $meta = [];
    if (!function_exists('openssl_x509_parse')) return $meta;
    if (!is_file($absolutePath) || !is_readable($absolutePath)) return $meta;

    $extension = strtolower($extension ?: pathinfo($absolutePath, PATHINFO_EXTENSION));
    $certPem = null;
    $raw = @file_get_contents($absolutePath);
    if ($raw === false || $raw === '') return $meta;

    if (in_array($extension, ['pfx','p12'], true)) {
      if (!function_exists('openssl_pkcs12_read')) return $meta;
      $certs = [];
      $ok = @openssl_pkcs12_read($raw, $certs, $password);
      if (!$ok || empty($certs['cert']) || empty($certs['pkey'])) {
        // OpenSSL 3 pode recusar A1 antigos (RC2/3DES) como se a senha estivesse errada.
        // Quando possível, normalizamos o contêiner PKCS#12 mantendo o mesmo certificado,
        // chave privada e senha. Isso também torna o arquivo compatível com NFePHP.
        $normalized = false;
        if ($password !== '') {
          try { $normalized = fiscal_pkcs12_normalize_legacy($absolutePath, $password); } catch (Throwable $e) { $normalized = false; }
        }
        if ($normalized) {
          $raw = @file_get_contents($absolutePath);
          $certs = [];
          $ok = $raw !== false && $raw !== '' && @openssl_pkcs12_read($raw, $certs, $password);
        }
      }
      if (!$ok || empty($certs['cert']) || empty($certs['pkey'])) {
        if ($password !== '') throw new RuntimeException('Não foi possível abrir o certificado A1. A senha pode estar incorreta, o arquivo pode estar corrompido ou usar um formato PKCS#12 incompatível com o OpenSSL da VPS.');
        return $meta;
      }
      $certPem = (string)$certs['cert'];
    } else {
      $certPem = $raw;
    }

    $parsed = @openssl_x509_parse($certPem);
    if (!is_array($parsed)) return $meta;

    $subject = isset($parsed['subject']) && is_array($parsed['subject']) ? $parsed['subject'] : [];
    $subjectString = $subject ? fiscal_cert_subject_to_string($subject) : '';
    $commonName = '';
    if (isset($subject['CN'])) {
      $commonName = is_array($subject['CN']) ? implode(' / ', array_map('strval', $subject['CN'])) : (string)$subject['CN'];
      $commonName = trim($commonName);
    }

    $serial = '';
    if (!empty($parsed['serialNumberHex'])) $serial = strtoupper((string)$parsed['serialNumberHex']);
    elseif (!empty($parsed['serialNumber'])) $serial = (string)$parsed['serialNumber'];

    if ($serial !== '') $meta['serial_number'] = $serial;
    if ($subjectString !== '') $meta['subject_name'] = $subjectString;
    if ($commonName !== '') $meta['display_name'] = $commonName;
    if (!empty($parsed['validFrom_time_t'])) $meta['valid_from'] = date('Y-m-d', (int)$parsed['validFrom_time_t']);
    if (!empty($parsed['validTo_time_t'])) $meta['valid_until'] = date('Y-m-d', (int)$parsed['validTo_time_t']);
    return $meta;
  }
}

if (!function_exists('fiscal_save_certificate')) {
  function fiscal_save_certificate(PDO $pdo, int $companyId, array $data, ?array $file = null): void {
    if (!fiscal_table_exists($pdo, 'fiscal_certificates')) {
      throw new RuntimeException('Tabela fiscal_certificates não encontrada. Instale as tabelas operacionais fiscais antes de salvar o certificado.');
    }

    $id = (int)($data['id'] ?? 0);
    $type = in_array(($data['certificate_type'] ?? ''), ['A1','A3'], true) ? (string)$data['certificate_type'] : 'A1';
    $filePath = '';
    if ($id > 0) {
      $stOwn = $pdo->prepare('SELECT file_path FROM fiscal_certificates WHERE company_id=? AND id=? LIMIT 1');
      $stOwn->execute([$companyId, $id]);
      $ownedFilePath = $stOwn->fetchColumn();
      if ($ownedFilePath === false) throw new RuntimeException('Certificado não encontrado para esta empresa.');
      $filePath = trim((string)$ownedFilePath);
    }
    $oldFilePath = $filePath;
    $absolutePath = '';
    $ext = '';
    $uploadedNewFile = false;

    if ($filePath !== '') {
      $absolutePath = realpath(__DIR__ . '/../' . $filePath) ?: (__DIR__ . '/../' . $filePath);
      $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
    }

    $password = (string)($data['password_secret'] ?? ''); // senha é literal; não usar trim

    if ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK) {
      $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
      if (!in_array($ext, ['pfx','p12','cer','crt','pem'], true)) throw new RuntimeException('Formato de certificado inválido. Use .pfx, .p12, .cer, .crt ou .pem.');
      if (in_array($ext, ['pfx','p12'], true) && $password === '') throw new RuntimeException('Informe a senha do novo certificado A1 para validar o arquivo.');
      $dir = __DIR__ . '/../uploads/certificados/' . $companyId;
      if (!is_dir($dir) && !mkdir($dir, 0755, true)) throw new RuntimeException('Não foi possível criar a pasta de certificados.');
      $name = 'cert_' . date('Ymd_His') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
      $dest = $dir . '/' . $name;
      if (!move_uploaded_file((string)$file['tmp_name'], $dest)) throw new RuntimeException('Falha ao salvar o arquivo do certificado.');
      @chmod($dest, 0600);
      $filePath = 'uploads/certificados/' . $companyId . '/' . $name;
      $absolutePath = $dest;
      $uploadedNewFile = true;
    } elseif ($file && (int)($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      throw new RuntimeException('Falha no upload do certificado. Código do erro: ' . (int)$file['error']);
    }

    if ($id <= 0 && $type === 'A1' && !$uploadedNewFile && $filePath === '') {
      throw new RuntimeException('Selecione o arquivo .pfx ou .p12 do certificado A1.');
    }

    $metadata = [];
    try {
      // Só relê PFX existente quando a senha foi informada. Ao editar e deixar a senha
      // em branco, preservamos senha/metadados existentes sem tentar abrir o PFX com senha vazia.
      if ($absolutePath !== '' && is_file($absolutePath) && ($uploadedNewFile || $password !== '' || !in_array($ext, ['pfx','p12'], true))) {
        $metadata = fiscal_extract_certificate_metadata($absolutePath, $password, $ext);
      }
    } catch (Throwable $e) {
      if ($uploadedNewFile && $absolutePath !== '') @unlink($absolutePath);
      throw $e;
    }

    $displayName = trim((string)($data['display_name'] ?? ''));
    $serialNumber = trim((string)($data['serial_number'] ?? ''));
    $subjectName = trim((string)($data['subject_name'] ?? ''));
    $validFrom = fiscal_sql_date($data['valid_from'] ?? '');
    $validUntil = fiscal_sql_date($data['valid_until'] ?? '');

    if ($displayName === '' && !empty($metadata['display_name'])) $displayName = (string)$metadata['display_name'];
    if ($serialNumber === '' && !empty($metadata['serial_number'])) $serialNumber = (string)$metadata['serial_number'];
    if ($subjectName === '' && !empty($metadata['subject_name'])) $subjectName = (string)$metadata['subject_name'];
    if ($validFrom === null && !empty($metadata['valid_from'])) $validFrom = (string)$metadata['valid_from'];
    if ($validUntil === null && !empty($metadata['valid_until'])) $validUntil = (string)$metadata['valid_until'];

    if ($displayName === '' && $subjectName !== '') $displayName = function_exists('mb_substr') ? mb_substr($subjectName, 0, 120, 'UTF-8') : substr($subjectName, 0, 120);
    if ($displayName === '') $displayName = 'Certificado digital ' . date('Y');

    $fields = [
      'certificate_type' => $type,
      'display_name' => $displayName,
      'file_path' => $filePath,
      'serial_number' => $serialNumber,
      'subject_name' => $subjectName,
      'valid_from' => $validFrom,
      'valid_until' => $validUntil,
      'is_active' => !empty($data['is_active']) ? 1 : 0,
    ];
    if ($password !== '') $fields['password_secret'] = $password;

    try {
      $pdo->beginTransaction();
      if (!empty($fields['is_active'])) $pdo->prepare('UPDATE fiscal_certificates SET is_active=0 WHERE company_id=?')->execute([$companyId]);
      $cols = array_keys($fields);
      if ($id > 0) {
        $sets = implode(', ', array_map(fn($c) => "`$c`=?", $cols));
        $pdo->prepare('UPDATE fiscal_certificates SET ' . $sets . ', updated_at=NOW() WHERE company_id=? AND id=?')->execute(array_merge(array_values($fields), [$companyId, $id]));
      } else {
        $pdo->prepare('INSERT INTO fiscal_certificates (company_id, `' . implode('`,`', $cols) . '`, created_at) VALUES (?,' . rtrim(str_repeat('?,', count($cols)), ',') . ',NOW())')->execute(array_merge([$companyId], array_values($fields)));
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if ($uploadedNewFile && $absolutePath !== '') @unlink($absolutePath);
      throw $e;
    }

    // Após troca bem-sucedida, elimina o PFX antigo para não deixar certificado obsoleto na VPS.
    if ($uploadedNewFile && $oldFilePath !== '' && $oldFilePath !== $filePath) {
      $oldAbsolute = realpath(__DIR__ . '/../' . $oldFilePath) ?: (__DIR__ . '/../' . $oldFilePath);
      fiscal_cert_safe_unlink($oldAbsolute, $companyId);
    }
  }
}

if (!function_exists('fiscal_delete_certificate')) {
  function fiscal_delete_certificate(PDO $pdo, int $companyId, int $certificateId): array {
    if ($certificateId <= 0) throw new RuntimeException('Certificado inválido.');
    $st = $pdo->prepare('SELECT id, display_name, file_path, is_active FROM fiscal_certificates WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $certificateId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new RuntimeException('Certificado não encontrado para esta empresa.');

    $pdo->beginTransaction();
    try {
      $del = $pdo->prepare('DELETE FROM fiscal_certificates WHERE company_id=? AND id=?');
      $del->execute([$companyId, $certificateId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    $fileRemoved = true;
    $filePath = trim((string)($row['file_path'] ?? ''));
    if ($filePath !== '') {
      $absolute = realpath(__DIR__ . '/../' . $filePath) ?: (__DIR__ . '/../' . $filePath);
      $fileRemoved = fiscal_cert_safe_unlink($absolute, $companyId);
    }
    return [
      'name' => (string)($row['display_name'] ?? ''),
      'was_active' => !empty($row['is_active']),
      'file_removed' => $fileRemoved,
    ];
  }
}

if (!function_exists('fiscal_xml_first_tag')) {
  function fiscal_xml_first_tag(string $xml, string $tag): string {
    $tag = preg_quote($tag, '~');
    if (preg_match('~<([A-Za-z0-9_\-]+:)?' . $tag . '(\s[^>]*)?>(.*?)</([A-Za-z0-9_\-]+:)?' . $tag . '>~si', $xml, $m)) return trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_XML1, 'UTF-8'));
    return '';
  }
}

if (!function_exists('fiscal_xml_first_of')) {
  function fiscal_xml_first_of(string $xml, array $tags): string {
    foreach ($tags as $tag) { $v = fiscal_xml_first_tag($xml, (string)$tag); if ($v !== '') return $v; }
    return '';
  }
}

if (!function_exists('fiscal_xml_first_block')) {
  function fiscal_xml_first_block(string $xml, string $tag): string {
    $tag = preg_quote($tag, '~');
    if (preg_match('~<([A-Za-z0-9_\-]+:)?' . $tag . '\b[^>]*>(.*?)</([A-Za-z0-9_\-]+:)?' . $tag . '>~si', $xml, $m)) return (string)$m[2];
    return '';
  }
}

if (!function_exists('fiscal_xml_blocks')) {
  function fiscal_xml_blocks(string $xml, string $tag): array {
    $tagQ = preg_quote($tag, '~');
    if (!preg_match_all('~<([A-Za-z0-9_\-]+:)?' . $tagQ . '\b([^>]*)>(.*?)</([A-Za-z0-9_\-]+:)?' . $tagQ . '>~si', $xml, $m, PREG_SET_ORDER)) return [];
    $out = [];
    foreach ($m as $row) $out[] = ['attrs' => (string)$row[2], 'inner' => (string)$row[3], 'full' => (string)$row[0]];
    return $out;
  }
}

if (!function_exists('fiscal_xml_attr')) {
  function fiscal_xml_attr(string $attrs, string $name): string {
    $name = preg_quote($name, '~');
    if (preg_match('~\b' . $name . '\s*=\s*(["\'])(.*?)\1~si', $attrs, $m)) return html_entity_decode((string)$m[2], ENT_QUOTES | ENT_XML1, 'UTF-8');
    return '';
  }
}

if (!function_exists('fiscal_parse_xml_regex')) {
  function fiscal_parse_xml_regex(string $xml): array {
    if (preg_match('~<([A-Za-z0-9_\-]+:)?infNFe\b~i', $xml)) return fiscal_parse_nfe_xml_regex($xml);
    if (preg_match('~<([A-Za-z0-9_\-]+:)?infNFSe\b~i', $xml) || preg_match('~<([A-Za-z0-9_\-]+:)?infDPS\b~i', $xml)) return fiscal_parse_nfse_xml_regex($xml);
    throw new RuntimeException('Tipo de XML não identificado. Esperado NF-e, NFC-e ou NFS-e.');
  }
}

if (!function_exists('fiscal_parse_nfe_xml_regex')) {
  function fiscal_parse_nfe_xml_regex(string $xml): array {
    $infRows = fiscal_xml_blocks($xml, 'infNFe');
    if (!$infRows) throw new RuntimeException('Grupo infNFe não encontrado.');
    $inf = $infRows[0]['inner'];
    $accessKey = preg_replace('/^NFe/', '', fiscal_xml_attr($infRows[0]['attrs'], 'Id'));
    $ide = fiscal_xml_first_block($inf, 'ide');
    $emit = fiscal_xml_first_block($inf, 'emit');
    $dest = fiscal_xml_first_block($inf, 'dest');
    $tot = fiscal_xml_first_block(fiscal_xml_first_block($inf, 'total'), 'ICMSTot');
    $model = fiscal_xml_first_tag($ide, 'mod');
    $items = [];
    foreach (fiscal_xml_blocks($inf, 'det') as $det) {
      $prod = fiscal_xml_first_block($det['inner'], 'prod');
      if ($prod === '') continue;
      $items[] = [
        'item_number' => (int)(fiscal_xml_attr($det['attrs'], 'nItem') ?: (count($items) + 1)),
        'supplier_product_code' => fiscal_xml_first_tag($prod, 'cProd'),
        'ean' => fiscal_xml_first_tag($prod, 'cEAN'),
        'description' => fiscal_xml_first_tag($prod, 'xProd'),
        'ncm' => fiscal_xml_first_tag($prod, 'NCM'),
        'cest' => fiscal_xml_first_tag($prod, 'CEST'),
        'cfop' => fiscal_xml_first_tag($prod, 'CFOP'),
        'unidade' => fiscal_xml_first_tag($prod, 'uCom'),
        'quantity' => fiscal_money_xml(fiscal_xml_first_tag($prod, 'qCom')),
        'unit_value' => fiscal_money_xml(fiscal_xml_first_tag($prod, 'vUnCom')),
        'total_value' => fiscal_money_xml(fiscal_xml_first_tag($prod, 'vProd')),
      ];
    }
    $installments = [];
    foreach (fiscal_xml_blocks($xml, 'dup') as $dup) {
      $installments[] = [
        'number' => fiscal_xml_first_tag($dup['inner'], 'nDup'),
        'due_date' => fiscal_sql_date(fiscal_xml_first_tag($dup['inner'], 'dVenc')),
        'amount' => fiscal_money_xml(fiscal_xml_first_tag($dup['inner'], 'vDup')),
      ];
    }
    $payments = [];
    foreach (fiscal_xml_blocks($xml, 'detPag') as $pay) {
      $payments[] = [
        'type' => fiscal_xml_first_tag($pay['inner'], 'tPag'),
        'indicator' => fiscal_xml_first_tag($pay['inner'], 'indPag'),
        'amount' => fiscal_money_xml(fiscal_xml_first_tag($pay['inner'], 'vPag')),
      ];
    }
    $prot = fiscal_xml_first_block($xml, 'protNFe');
    return [
      'kind' => $model === '65' ? 'NFCE' : 'NFE',
      'model' => $model ?: '55',
      'access_key' => $accessKey,
      'series' => fiscal_xml_first_tag($ide, 'serie'),
      'document_number' => (int)fiscal_xml_first_tag($ide, 'nNF'),
      'issue_datetime' => fiscal_sql_datetime(fiscal_xml_first_tag($ide, 'dhEmi')),
      'operation_nature' => fiscal_xml_first_tag($ide, 'natOp'),
      'environment_code' => fiscal_xml_first_tag($ide, 'tpAmb'),
      'issuer_document' => fiscal_digits(fiscal_xml_first_of($emit, ['CNPJ','CPF'])),
      'issuer_name' => fiscal_xml_first_tag($emit, 'xNome'),
      'recipient_document' => fiscal_digits(fiscal_xml_first_of($dest, ['CNPJ','CPF'])),
      'recipient_name' => fiscal_xml_first_tag($dest, 'xNome'),
      'total_products' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vProd')),
      'total_document' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vNF')),
      'total_discount' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vDesc')),
      'total_freight' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vFrete')),
      'total_icms' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vICMS')),
      'total_icms_st' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vST')),
      'total_ipi' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vIPI')),
      'total_pis' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vPIS')),
      'total_cofins' => fiscal_money_xml(fiscal_xml_first_tag($tot, 'vCOFINS')),
      'protocol_number' => fiscal_xml_first_tag($prot, 'nProt'),
      'status_code' => fiscal_xml_first_tag($prot, 'cStat'),
      'status_message' => fiscal_xml_first_tag($prot, 'xMotivo'),
      'items' => $items,
      'installments' => $installments,
      'payments' => $payments,
      'xml_hash' => hash('sha256', $xml),
    ];
  }
}

if (!function_exists('fiscal_parse_nfse_xml_regex')) {
  function fiscal_parse_nfse_xml_regex(string $xml): array {
    $inf = fiscal_xml_first_block($xml, 'infNFSe');
    $dps = fiscal_xml_first_block($xml, 'infDPS');
    if ($inf === '' && $dps === '') throw new RuntimeException('Grupo infNFSe/infDPS não encontrado.');
    $emit = fiscal_xml_first_block($inf, 'emit') ?: fiscal_xml_first_block($dps, 'prest');
    $toma = fiscal_xml_first_block($dps, 'toma');
    $cServ = fiscal_xml_first_block(fiscal_xml_first_block($dps, 'serv'), 'cServ');
    $desc = fiscal_xml_first_tag($cServ, 'xDescServ') ?: fiscal_xml_first_tag($inf, 'xTribMun');
    $vServPrest = fiscal_xml_first_block(fiscal_xml_first_block($dps, 'valores'), 'vServPrest');
    $total = fiscal_money_xml(fiscal_xml_first_tag($vServPrest, 'vServ'));
    if ($total <= 0) $total = fiscal_money_xml(fiscal_xml_first_tag(fiscal_xml_first_block($inf, 'valores'), 'vLiq'));
    $infRows = fiscal_xml_blocks($xml, 'infNFSe');
    $nfseId = $infRows ? fiscal_xml_attr($infRows[0]['attrs'], 'Id') : '';
    return [
      'kind' => 'NFSE',
      'model' => 'NFSE',
      'access_key' => $nfseId,
      'series' => fiscal_xml_first_tag($dps, 'serie'),
      'document_number' => (int)(fiscal_xml_first_tag($inf, 'nNFSe') ?: fiscal_xml_first_tag($dps, 'nDPS')),
      'issue_datetime' => fiscal_sql_datetime(fiscal_xml_first_tag($dps, 'dhEmi') ?: fiscal_xml_first_tag($inf, 'dhProc')),
      'competence_date' => fiscal_sql_date(fiscal_xml_first_tag($dps, 'dCompet')),
      'issuer_document' => fiscal_digits(fiscal_xml_first_of($emit, ['CNPJ','CPF'])),
      'issuer_name' => fiscal_xml_first_tag($emit, 'xNome'),
      'recipient_document' => fiscal_digits(fiscal_xml_first_of($toma, ['CNPJ','CPF'])),
      'recipient_name' => fiscal_xml_first_tag($toma, 'xNome'),
      'total_products' => $total,
      'total_document' => $total,
      'total_iss' => fiscal_money_xml(fiscal_xml_first_tag(fiscal_xml_first_block($inf, 'valores'), 'vISSQN')),
      'iss_rate' => fiscal_money_xml(fiscal_xml_first_tag(fiscal_xml_first_block($inf, 'valores'), 'pAliqAplic') ?: fiscal_xml_first_tag(fiscal_xml_first_block($dps, 'tribMun'), 'pAliq')),
      'status_code' => fiscal_xml_first_tag($inf, 'cStat'),
      'status_message' => '',
      'service_city_ibge' => fiscal_xml_first_tag($dps, 'cLocPrestacao') ?: fiscal_xml_first_tag($inf, 'cLocIncid'),
      'national_service_code' => fiscal_xml_first_tag($cServ, 'cTribNac'),
      'municipal_service_code' => fiscal_xml_first_tag($cServ, 'cTribMun'),
      'nbs_code' => fiscal_xml_first_tag($cServ, 'cNBS'),
      'service_description' => $desc,
      'ibs_cbs_cst' => fiscal_xml_first_tag($dps, 'CST'),
      'ibs_cbs_class_code' => fiscal_xml_first_tag($dps, 'cClassTrib'),
      'operation_indicator' => fiscal_xml_first_tag($dps, 'cIndOp'),
      'items' => [[
        'item_number' => 1,
        'supplier_product_code' => fiscal_xml_first_tag($cServ, 'cTribMun'),
        'ean' => '', 'description' => $desc ?: 'Serviço NFS-e',
        'ncm' => '', 'cest' => '', 'cfop' => '', 'unidade' => 'SV',
        'quantity' => 1, 'unit_value' => $total, 'total_value' => $total,
      ]],
      'installments' => [], 'payments' => [], 'xml_hash' => hash('sha256', $xml),
    ];
  }
}

if (!function_exists('fiscal_xpath_text')) {
  function fiscal_xpath_text(DOMXPath $xp, string $expr, ?DOMNode $context = null): string {
    $nodes = $context ? $xp->query($expr, $context) : $xp->query($expr);
    if (!$nodes || $nodes->length === 0) return '';
    return trim((string)$nodes->item(0)->textContent);
  }
}

if (!function_exists('fiscal_xpath_node')) {
  function fiscal_xpath_node(DOMXPath $xp, string $expr, ?DOMNode $context = null): ?DOMNode {
    $nodes = $context ? $xp->query($expr, $context) : $xp->query($expr);
    return ($nodes && $nodes->length > 0) ? $nodes->item(0) : null;
  }
}

if (!function_exists('fiscal_parse_xml_content')) {
  function fiscal_parse_xml_content(string $xml): array {
    $xml = trim($xml);
    if ($xml === '') throw new RuntimeException('XML vazio.');
    if (!class_exists('DOMDocument') || !class_exists('DOMXPath')) return fiscal_parse_xml_regex($xml);
    libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    if (!$ok) throw new RuntimeException('XML inválido ou malformado.');
    $xp = new DOMXPath($dom);
    if (fiscal_xpath_node($xp, '//*[local-name()="infNFe"]')) return fiscal_parse_nfe_xml($xp, $xml);
    if (fiscal_xpath_node($xp, '//*[local-name()="infNFSe"]') || fiscal_xpath_node($xp, '//*[local-name()="infDPS"]')) return fiscal_parse_nfse_xml($xp, $xml);
    throw new RuntimeException('Tipo de XML não identificado. Esperado NF-e, NFC-e ou NFS-e.');
  }
}

if (!function_exists('fiscal_parse_nfe_xml')) {
  function fiscal_parse_nfe_xml(DOMXPath $xp, string $xml): array {
    $inf = fiscal_xpath_node($xp, '//*[local-name()="infNFe"]');
    if (!$inf) throw new RuntimeException('Grupo infNFe não encontrado.');
    $accessKey = preg_replace('/^NFe/', '', (string)($inf->attributes?->getNamedItem('Id')?->nodeValue ?? ''));
    $model = fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="mod"]', $inf);
    $emit = fiscal_xpath_node($xp, './*[local-name()="emit"]', $inf);
    $dest = fiscal_xpath_node($xp, './*[local-name()="dest"]', $inf);
    $tot = fiscal_xpath_node($xp, './*[local-name()="total"]/*[local-name()="ICMSTot"]', $inf);
    $items = [];
    $detNodes = $xp->query('./*[local-name()="det"]', $inf);
    if ($detNodes) {
      foreach ($detNodes as $det) {
        $prod = fiscal_xpath_node($xp, './*[local-name()="prod"]', $det);
        if (!$prod) continue;
        $items[] = [
          'item_number' => (int)($det->attributes?->getNamedItem('nItem')?->nodeValue ?? (count($items)+1)),
          'supplier_product_code' => fiscal_xpath_text($xp, './*[local-name()="cProd"]', $prod),
          'ean' => fiscal_xpath_text($xp, './*[local-name()="cEAN"]', $prod),
          'description' => fiscal_xpath_text($xp, './*[local-name()="xProd"]', $prod),
          'ncm' => fiscal_xpath_text($xp, './*[local-name()="NCM"]', $prod),
          'cest' => fiscal_xpath_text($xp, './*[local-name()="CEST"]', $prod),
          'cfop' => fiscal_xpath_text($xp, './*[local-name()="CFOP"]', $prod),
          'unidade' => fiscal_xpath_text($xp, './*[local-name()="uCom"]', $prod),
          'quantity' => fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="qCom"]', $prod)),
          'unit_value' => fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vUnCom"]', $prod)),
          'total_value' => fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vProd"]', $prod)),
        ];
      }
    }
    $installments = [];
    $dupNodes = $xp->query('//*[local-name()="cobr"]/*[local-name()="dup"]');
    if ($dupNodes) foreach ($dupNodes as $dup) {
      $installments[] = [
        'number' => fiscal_xpath_text($xp, './*[local-name()="nDup"]', $dup),
        'due_date' => fiscal_sql_date(fiscal_xpath_text($xp, './*[local-name()="dVenc"]', $dup)),
        'amount' => fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vDup"]', $dup)),
      ];
    }
    $payments = [];
    $payNodes = $xp->query('//*[local-name()="pag"]/*[local-name()="detPag"]');
    if ($payNodes) foreach ($payNodes as $pay) {
      $payments[] = [
        'type' => fiscal_xpath_text($xp, './*[local-name()="tPag"]', $pay),
        'indicator' => fiscal_xpath_text($xp, './*[local-name()="indPag"]', $pay),
        'amount' => fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vPag"]', $pay)),
      ];
    }
    return [
      'kind' => $model === '65' ? 'NFCE' : 'NFE',
      'model' => $model ?: '55',
      'access_key' => $accessKey,
      'series' => fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="serie"]', $inf),
      'document_number' => (int)fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="nNF"]', $inf),
      'issue_datetime' => fiscal_sql_datetime(fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="dhEmi"]', $inf)),
      'operation_nature' => fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="natOp"]', $inf),
      'environment_code' => fiscal_xpath_text($xp, './*[local-name()="ide"]/*[local-name()="tpAmb"]', $inf),
      'issuer_document' => $emit ? fiscal_digits(fiscal_xpath_text($xp, './*[local-name()="CNPJ"] | ./*[local-name()="CPF"]', $emit)) : '',
      'issuer_name' => $emit ? fiscal_xpath_text($xp, './*[local-name()="xNome"]', $emit) : '',
      'recipient_document' => $dest ? fiscal_digits(fiscal_xpath_text($xp, './*[local-name()="CNPJ"] | ./*[local-name()="CPF"]', $dest)) : '',
      'recipient_name' => $dest ? fiscal_xpath_text($xp, './*[local-name()="xNome"]', $dest) : '',
      'total_products' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vProd"]', $tot)) : 0,
      'total_document' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vNF"]', $tot)) : 0,
      'total_discount' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vDesc"]', $tot)) : 0,
      'total_freight' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vFrete"]', $tot)) : 0,
      'total_icms' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vICMS"]', $tot)) : 0,
      'total_icms_st' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vST"]', $tot)) : 0,
      'total_ipi' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vIPI"]', $tot)) : 0,
      'total_pis' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vPIS"]', $tot)) : 0,
      'total_cofins' => $tot ? fiscal_money_xml(fiscal_xpath_text($xp, './*[local-name()="vCOFINS"]', $tot)) : 0,
      'protocol_number' => fiscal_xpath_text($xp, '(//*[local-name()="protNFe"]//*[local-name()="nProt"])[1]'),
      'status_code' => fiscal_xpath_text($xp, '(//*[local-name()="protNFe"]//*[local-name()="cStat"])[1]'),
      'status_message' => fiscal_xpath_text($xp, '(//*[local-name()="protNFe"]//*[local-name()="xMotivo"])[1]'),
      'items' => $items,
      'installments' => $installments,
      'payments' => $payments,
      'xml_hash' => hash('sha256', $xml),
    ];
  }
}

if (!function_exists('fiscal_parse_nfse_xml')) {
  function fiscal_parse_nfse_xml(DOMXPath $xp, string $xml): array {
    $inf = fiscal_xpath_node($xp, '//*[local-name()="infNFSe"]');
    $dps = fiscal_xpath_node($xp, '//*[local-name()="infDPS"]');
    if (!$inf && !$dps) throw new RuntimeException('Grupo infNFSe/infDPS não encontrado.');
    $emit = fiscal_xpath_node($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]') ?: fiscal_xpath_node($xp, '//*[local-name()="infDPS"]/*[local-name()="prest"]');
    $toma = fiscal_xpath_node($xp, '//*[local-name()="infDPS"]/*[local-name()="toma"]');
    $serviceDesc = fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="serv"]/*[local-name()="cServ"]/*[local-name()="xDescServ"]');
    $total = fiscal_money_xml(fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="valores"]/*[local-name()="vServPrest"]/*[local-name()="vServ"]'));
    if ($total <= 0) $total = fiscal_money_xml(fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="valores"]/*[local-name()="vLiq"]'));
    $nfseNumber = fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="nNFSe"]');
    $dpsNumber = fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="nDPS"]');
    $nfseId = $inf ? (string)($inf->attributes?->getNamedItem('Id')?->nodeValue ?? '') : '';
    return [
      'kind' => 'NFSE',
      'model' => 'NFSE',
      'access_key' => $nfseId,
      'series' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="serie"]'),
      'document_number' => (int)($nfseNumber ?: $dpsNumber),
      'issue_datetime' => fiscal_sql_datetime(fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="dhEmi"]') ?: fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="dhProc"]')),
      'competence_date' => fiscal_sql_date(fiscal_xpath_text($xp, '//*[local-name()="infDPS"]/*[local-name()="dCompet"]')),
      'issuer_document' => $emit ? fiscal_digits(fiscal_xpath_text($xp, './*[local-name()="CNPJ"] | ./*[local-name()="CPF"]', $emit)) : '',
      'issuer_name' => fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="emit"]/*[local-name()="xNome"]'),
      'recipient_document' => $toma ? fiscal_digits(fiscal_xpath_text($xp, './*[local-name()="CNPJ"] | ./*[local-name()="CPF"]', $toma)) : '',
      'recipient_name' => $toma ? fiscal_xpath_text($xp, './*[local-name()="xNome"]', $toma) : '',
      'total_products' => $total,
      'total_document' => $total,
      'total_iss' => fiscal_money_xml(fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="valores"]/*[local-name()="vISSQN"]')),
      'iss_rate' => fiscal_money_xml(fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="valores"]/*[local-name()="pAliqAplic"]') ?: fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="tribMun"]/*[local-name()="pAliq"]')),
      'status_code' => fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="cStat"]'),
      'status_message' => '',
      'service_city_ibge' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cLocPrestacao"]') ?: fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="cLocIncid"]'),
      'national_service_code' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cTribNac"]'),
      'municipal_service_code' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cTribMun"]'),
      'nbs_code' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cNBS"]'),
      'service_description' => $serviceDesc ?: fiscal_xpath_text($xp, '//*[local-name()="infNFSe"]/*[local-name()="xTribMun"]'),
      'ibs_cbs_cst' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="gIBSCBS"]/*[local-name()="CST"]'),
      'ibs_cbs_class_code' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="gIBSCBS"]/*[local-name()="cClassTrib"]'),
      'operation_indicator' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cIndOp"]'),
      'items' => [[
        'item_number' => 1,
        'supplier_product_code' => fiscal_xpath_text($xp, '//*[local-name()="infDPS"]//*[local-name()="cTribMun"]'),
        'ean' => '',
        'description' => $serviceDesc ?: 'Serviço NFS-e',
        'ncm' => '', 'cest' => '', 'cfop' => '', 'unidade' => 'SV',
        'quantity' => 1, 'unit_value' => $total, 'total_value' => $total,
      ]],
      'installments' => [],
      'payments' => [],
      'xml_hash' => hash('sha256', $xml),
    ];
  }
}

if (!function_exists('fiscal_find_internal_product')) {
  function fiscal_find_internal_product(PDO $pdo, int $companyId, array $item): ?int {
    if (!fiscal_table_exists($pdo, 'produtos')) return null;
    $ean = fiscal_digits($item['ean'] ?? '');
    $code = trim((string)($item['supplier_product_code'] ?? ''));
    $desc = trim((string)($item['description'] ?? ''));
    $columns = [];
    foreach (['codigo_barras','ean','gtin','codigo','codigo_firebird','referencia','sku'] as $col) if (fiscal_column_exists($pdo, 'produtos', $col)) $columns[] = $col;
    if ($ean !== '' && !in_array($ean, ['0000000000000'], true)) {
      foreach ($columns as $col) {
        $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND `' . $col . '`=? LIMIT 1');
        $st->execute([$companyId, $ean]);
        $id = (int)($st->fetchColumn() ?: 0); if ($id > 0) return $id;
      }
    }
    if ($code !== '') {
      foreach ($columns as $col) {
        $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND `' . $col . '`=? LIMIT 1');
        $st->execute([$companyId, $code]);
        $id = (int)($st->fetchColumn() ?: 0); if ($id > 0) return $id;
      }
    }
    if ($desc !== '' && fiscal_column_exists($pdo, 'produtos', 'nome')) {
      $st = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND LOWER(TRIM(nome))=LOWER(TRIM(?)) LIMIT 1');
      $st->execute([$companyId, $desc]);
      $id = (int)($st->fetchColumn() ?: 0); if ($id > 0) return $id;
    }
    return null;
  }
}

if (!function_exists('fiscal_store_radar_xml')) {
  function fiscal_store_radar_xml(PDO $pdo, int $companyId, string $xml, string $fileName = '', string $source = 'UPLOAD'): int {
    $parsed = fiscal_parse_xml_content($xml);
    $hash = (string)$parsed['xml_hash'];
    $filePath = '';
    if ($fileName !== '') {
      $dir = __DIR__ . '/../uploads/fiscal_xml/' . $companyId;
      if (!is_dir($dir)) @mkdir($dir, 0755, true);
      if (is_dir($dir)) {
        $safeName = preg_replace('/[^a-zA-Z0-9._-]+/', '_', basename($fileName));
        $destName = date('Ymd_His') . '_' . substr($hash, 0, 10) . '_' . $safeName;
        file_put_contents($dir . '/' . $destName, $xml);
        $filePath = 'uploads/fiscal_xml/' . $companyId . '/' . $destName;
      }
    }
    $sql = "INSERT INTO fiscal_xml_radar
      (company_id, source, file_name, file_path, xml_hash, access_key, model, direction, document_number, series, issue_datetime, issuer_document, issuer_name, recipient_document, recipient_name, total_document, protocol_number, status_code, status_message, radar_status, xml_raw, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), file_name=VALUES(file_name), file_path=COALESCE(NULLIF(VALUES(file_path),''),file_path), access_key=VALUES(access_key), model=VALUES(model), document_number=VALUES(document_number), series=VALUES(series), issue_datetime=VALUES(issue_datetime), issuer_document=VALUES(issuer_document), issuer_name=VALUES(issuer_name), recipient_document=VALUES(recipient_document), recipient_name=VALUES(recipient_name), total_document=VALUES(total_document), protocol_number=VALUES(protocol_number), status_code=VALUES(status_code), status_message=VALUES(status_message), radar_status='ANALISADO', xml_raw=VALUES(xml_raw), updated_at=NOW()";
    $pdo->prepare($sql)->execute([
      $companyId, $source, $fileName, $filePath, $hash, $parsed['access_key'] ?? '', $parsed['model'] ?? '', 'ENTRADA',
      (int)($parsed['document_number'] ?? 0), $parsed['series'] ?? '', $parsed['issue_datetime'] ?? null,
      $parsed['issuer_document'] ?? '', $parsed['issuer_name'] ?? '', $parsed['recipient_document'] ?? '', $parsed['recipient_name'] ?? '',
      (float)($parsed['total_document'] ?? 0), $parsed['protocol_number'] ?? '', $parsed['status_code'] ?? '', $parsed['status_message'] ?? '',
      'ANALISADO', $xml
    ]);
    $radarId = (int)$pdo->lastInsertId();
    $pdo->prepare('DELETE FROM fiscal_xml_radar_items WHERE radar_id=? AND company_id=?')->execute([$radarId, $companyId]);
    $ins = $pdo->prepare('INSERT INTO fiscal_xml_radar_items (radar_id, company_id, item_number, supplier_product_code, ean, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, matched_product_id, match_status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    foreach (($parsed['items'] ?? []) as $item) {
      $pid = fiscal_find_internal_product($pdo, $companyId, $item);
      $ins->execute([$radarId, $companyId, (int)($item['item_number'] ?? 0), $item['supplier_product_code'] ?? '', $item['ean'] ?? '', $item['description'] ?? '', $item['ncm'] ?? '', $item['cest'] ?? '', $item['cfop'] ?? '', $item['unidade'] ?? '', (float)($item['quantity'] ?? 0), (float)($item['unit_value'] ?? 0), (float)($item['total_value'] ?? 0), $pid, $pid ? 'ENCONTRADO' : 'PENDENTE']);
    }
    return $radarId;
  }
}

if (!function_exists('fiscal_radar_rows')) {
  function fiscal_radar_rows(PDO $pdo, int $companyId, string $status = '', string $q = '', int $limit = 200): array {
    $where = ['company_id=?']; $params = [$companyId];
    if ($status !== '') { $where[] = 'radar_status=?'; $params[] = $status; }
    if ($q !== '') {
      $like = nexa_search_like($q);
      if (fiscal_column_exists($pdo, 'fiscal_xml_radar', 'dfe_nsu')) {
        $where[] = '(access_key LIKE ? OR issuer_name LIKE ? OR issuer_document LIKE ? OR file_name LIKE ? OR dfe_nsu LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
      } else {
        $where[] = '(access_key LIKE ? OR issuer_name LIKE ? OR issuer_document LIKE ? OR file_name LIKE ?)';
        array_push($params, $like, $like, $like, $like);
      }
    }
    $sql = 'SELECT * FROM fiscal_xml_radar WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC, id DESC LIMIT ' . max(1, min(500, $limit));
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_radar_item_rows')) {
  function fiscal_radar_item_rows(PDO $pdo, int $companyId, int $radarId): array {
    $st = $pdo->prepare('SELECT ri.*, p.nome AS product_name FROM fiscal_xml_radar_items ri LEFT JOIN produtos p ON p.id=ri.matched_product_id AND p.company_id=ri.company_id WHERE ri.company_id=? AND ri.radar_id=? ORDER BY ri.item_number ASC');
    $st->execute([$companyId, $radarId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_radar_one')) {
  function fiscal_radar_one(PDO $pdo, int $companyId, int $radarId): ?array {
    $st = $pdo->prepare('SELECT * FROM fiscal_xml_radar WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $radarId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('fiscal_finance_available')) {
  function fiscal_finance_available(PDO $pdo): bool {
    return fiscal_table_exists($pdo, 'finance_people') && fiscal_table_exists($pdo, 'finance_payables') && fiscal_table_exists($pdo, 'finance_chart_accounts');
  }
}

if (!function_exists('fiscal_ensure_supplier_person')) {
  function fiscal_ensure_supplier_person(PDO $pdo, int $companyId, string $document, string $name): ?int {
    if (!fiscal_table_exists($pdo, 'finance_people')) return null;
    $document = fiscal_digits($document);
    $name = trim($name) ?: ($document !== '' ? 'Fornecedor ' . $document : 'Fornecedor XML');
    if ($document !== '') {
      $st = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND document=? LIMIT 1');
      $st->execute([$companyId, $document]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0) {
        $pdo->prepare('UPDATE finance_people SET is_supplier=1, name=IF(TRIM(COALESCE(name,""))="",?,name), updated_at=NOW() WHERE company_id=? AND id=?')->execute([$name, $companyId, $id]);
        return $id;
      }
    }
    $personType = strlen($document) === 11 ? 'FISICA' : 'JURIDICA';
    $pdo->prepare('INSERT INTO finance_people (company_id, name, document, person_type, is_supplier, active, created_at) VALUES (?,?,?,?,1,1,NOW())')->execute([$companyId, $name, $document, $personType]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('fiscal_default_debit_chart_id')) {
  function fiscal_default_debit_chart_id(PDO $pdo, int $companyId): ?int {
    if (!fiscal_table_exists($pdo, 'finance_chart_accounts')) return null;
    $st = $pdo->prepare("SELECT id FROM finance_chart_accounts WHERE company_id=? AND active=1 AND is_analytic=1 AND movement_type IN ('DEBITO','AMBOS') ORDER BY account_type='DESPESA' DESC, code ASC, id ASC LIMIT 1");
    $st->execute([$companyId]);
    $id = (int)($st->fetchColumn() ?: 0);
    return $id > 0 ? $id : null;
  }
}

if (!function_exists('fiscal_generate_purchase_from_radar')) {
  function fiscal_generate_purchase_from_radar(PDO $pdo, int $companyId, int $radarId, bool $generatePayables = true): array {
    $radar = fiscal_radar_one($pdo, $companyId, $radarId);
    if (!$radar) throw new RuntimeException('XML do Radar não encontrado.');
    $xml = (string)($radar['xml_raw'] ?? '');
    if (trim($xml) === '') throw new RuntimeException('O XML não possui conteúdo armazenado para gerar a compra.');
    $parsed = fiscal_parse_xml_content($xml);
    if (($parsed['model'] ?? '') === '65') throw new RuntimeException('NFC-e não deve gerar compra de entrada. Use XML NF-e de fornecedor.');

    $supplierId = fiscal_ensure_supplier_person($pdo, $companyId, (string)($parsed['issuer_document'] ?? ''), (string)($parsed['issuer_name'] ?? ''));
    $accessKey = (string)($parsed['access_key'] ?? '');
    $issueDate = !empty($parsed['issue_datetime']) ? substr((string)$parsed['issue_datetime'], 0, 10) : date('Y-m-d');
    $entryDate = date('Y-m-d');
    $xmlHash = (string)($parsed['xml_hash'] ?? hash('sha256', $xml));

    $pdo->beginTransaction();
    try {
      $existingPurchase = null;
      if ($accessKey !== '') {
        $st = $pdo->prepare('SELECT * FROM purchase_invoices WHERE company_id=? AND access_key=? LIMIT 1');
        $st->execute([$companyId, $accessKey]);
        $existingPurchase = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      $alreadyHadPayables = !empty($existingPurchase['payables_generated']);

      $docSql = "INSERT INTO fiscal_documents
        (company_id, direction, model, series, number, access_key, protocol_number, status, sefaz_status_code, sefaz_status_message, environment, operation_nature, issue_datetime, supplier_person_id, recipient_person_id, total_products, total_discount, total_freight, total_icms, total_icms_st, total_ipi, total_pis, total_cofins, total_iss, total_document, xml_raw, xml_hash, source_type, source_id, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), protocol_number=VALUES(protocol_number), status=VALUES(status), sefaz_status_code=VALUES(sefaz_status_code), sefaz_status_message=VALUES(sefaz_status_message), operation_nature=VALUES(operation_nature), issue_datetime=VALUES(issue_datetime), supplier_person_id=VALUES(supplier_person_id), total_products=VALUES(total_products), total_discount=VALUES(total_discount), total_freight=VALUES(total_freight), total_icms=VALUES(total_icms), total_icms_st=VALUES(total_icms_st), total_ipi=VALUES(total_ipi), total_pis=VALUES(total_pis), total_cofins=VALUES(total_cofins), total_iss=VALUES(total_iss), total_document=VALUES(total_document), xml_raw=VALUES(xml_raw), xml_hash=VALUES(xml_hash), source_type=VALUES(source_type), source_id=VALUES(source_id), updated_at=NOW()";
      $pdo->prepare($docSql)->execute([
        $companyId, 'ENTRADA', $parsed['model'] ?? '55', $parsed['series'] ?? '', (int)($parsed['document_number'] ?? 0), $accessKey,
        $parsed['protocol_number'] ?? '', 'IMPORTADA', $parsed['status_code'] ?? '', $parsed['status_message'] ?? '', (($parsed['environment_code'] ?? '') === '1' ? 'PRODUCAO' : 'HOMOLOGACAO'),
        $parsed['operation_nature'] ?? '', $parsed['issue_datetime'] ?? null, $supplierId, null,
        (float)($parsed['total_products'] ?? 0), (float)($parsed['total_discount'] ?? 0), (float)($parsed['total_freight'] ?? 0),
        (float)($parsed['total_icms'] ?? 0), (float)($parsed['total_icms_st'] ?? 0), (float)($parsed['total_ipi'] ?? 0),
        (float)($parsed['total_pis'] ?? 0), (float)($parsed['total_cofins'] ?? 0), (float)($parsed['total_iss'] ?? 0),
        (float)($parsed['total_document'] ?? 0), $xml, $xmlHash, 'COMPRA_XML', $radarId
      ]);
      $fiscalDocumentId = (int)$pdo->lastInsertId();

      $pdo->prepare('DELETE FROM fiscal_document_participants WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $fiscalDocumentId]);
      $part = $pdo->prepare('INSERT INTO fiscal_document_participants (fiscal_document_id, company_id, role, person_id, document, name, created_at) VALUES (?,?,?,?,?,?,NOW())');
      $part->execute([$fiscalDocumentId, $companyId, 'EMITENTE', $supplierId, $parsed['issuer_document'] ?? '', $parsed['issuer_name'] ?? '']);
      $part->execute([$fiscalDocumentId, $companyId, 'DESTINATARIO', null, $parsed['recipient_document'] ?? '', $parsed['recipient_name'] ?? '']);

      $pdo->prepare('DELETE FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $fiscalDocumentId]);
      $docItems = [];
      $docItemIns = $pdo->prepare('INSERT INTO fiscal_document_items (fiscal_document_id, company_id, item_number, product_id, supplier_product_code, ean, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, unidade_trib, quantity_trib, unit_value_trib, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $radarItems = fiscal_radar_item_rows($pdo, $companyId, $radarId);
      $matchByItem = [];
      foreach ($radarItems as $ri) $matchByItem[(int)$ri['item_number']] = (int)($ri['matched_product_id'] ?? 0);
      foreach (($parsed['items'] ?? []) as $item) {
        $n = (int)($item['item_number'] ?? 0);
        $pid = $matchByItem[$n] ?? null;
        if (!$pid) $pid = fiscal_find_internal_product($pdo, $companyId, $item);
        $docItemIns->execute([$fiscalDocumentId, $companyId, $n, $pid ?: null, $item['supplier_product_code'] ?? '', $item['ean'] ?? '', $item['description'] ?? '', $item['ncm'] ?? '', $item['cest'] ?? '', $item['cfop'] ?? '', $item['unidade'] ?? '', (float)($item['quantity'] ?? 0), (float)($item['unit_value'] ?? 0), (float)($item['total_value'] ?? 0), $item['unidade'] ?? '', (float)($item['quantity'] ?? 0), (float)($item['unit_value'] ?? 0)]);
        $docItems[$n] = (int)$pdo->lastInsertId();
      }

      $purchaseSql = "INSERT INTO purchase_invoices
        (company_id, fiscal_document_id, radar_id, supplier_person_id, supplier_document, supplier_name, model, series, number, access_key, issue_date, entry_date, total_products, total_document, total_discount, total_freight, total_icms, total_icms_st, total_ipi, total_pis, total_cofins, status, generate_payables, payables_generated, created_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
        ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), fiscal_document_id=VALUES(fiscal_document_id), radar_id=VALUES(radar_id), supplier_person_id=VALUES(supplier_person_id), supplier_name=VALUES(supplier_name), issue_date=VALUES(issue_date), entry_date=VALUES(entry_date), total_products=VALUES(total_products), total_document=VALUES(total_document), total_discount=VALUES(total_discount), total_freight=VALUES(total_freight), total_icms=VALUES(total_icms), total_icms_st=VALUES(total_icms_st), total_ipi=VALUES(total_ipi), total_pis=VALUES(total_pis), total_cofins=VALUES(total_cofins), status=VALUES(status), generate_payables=VALUES(generate_payables), updated_at=NOW()";
      $pdo->prepare($purchaseSql)->execute([
        $companyId, $fiscalDocumentId, $radarId, $supplierId, $parsed['issuer_document'] ?? '', $parsed['issuer_name'] ?? '', $parsed['model'] ?? '55', $parsed['series'] ?? '', (int)($parsed['document_number'] ?? 0), $accessKey, $issueDate, $entryDate,
        (float)($parsed['total_products'] ?? 0), (float)($parsed['total_document'] ?? 0), (float)($parsed['total_discount'] ?? 0), (float)($parsed['total_freight'] ?? 0), (float)($parsed['total_icms'] ?? 0), (float)($parsed['total_icms_st'] ?? 0), (float)($parsed['total_ipi'] ?? 0), (float)($parsed['total_pis'] ?? 0), (float)($parsed['total_cofins'] ?? 0), 'CONFERIDA', $generatePayables ? 1 : 0, $alreadyHadPayables ? 1 : 0
      ]);
      $purchaseId = (int)$pdo->lastInsertId();

      $pdo->prepare('DELETE FROM purchase_invoice_items WHERE company_id=? AND purchase_invoice_id=?')->execute([$companyId, $purchaseId]);
      $piItemIns = $pdo->prepare('INSERT INTO purchase_invoice_items (purchase_invoice_id, company_id, fiscal_document_item_id, item_number, product_id, supplier_product_code, ean, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, cost_value, internal_quantity, conversion_factor, match_status, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      foreach (($parsed['items'] ?? []) as $item) {
        $n = (int)($item['item_number'] ?? 0);
        $pid = $matchByItem[$n] ?? null;
        if (!$pid) $pid = fiscal_find_internal_product($pdo, $companyId, $item);
        $qty = (float)($item['quantity'] ?? 0);
        $unit = (float)($item['unit_value'] ?? 0);
        $piItemIns->execute([$purchaseId, $companyId, $docItems[$n] ?? null, $n, $pid ?: null, $item['supplier_product_code'] ?? '', $item['ean'] ?? '', $item['description'] ?? '', $item['ncm'] ?? '', $item['cest'] ?? '', $item['cfop'] ?? '', $item['unidade'] ?? '', $qty, $unit, (float)($item['total_value'] ?? 0), $unit, $qty, 1, $pid ? 'ENCONTRADO' : 'PENDENTE']);
      }

      $installments = $parsed['installments'] ?? [];
      if (!$installments) $installments = [['number' => '001', 'due_date' => $issueDate, 'amount' => (float)($parsed['total_document'] ?? 0)]];
      $pdo->prepare('DELETE FROM purchase_invoice_installments WHERE company_id=? AND purchase_invoice_id=?')->execute([$companyId, $purchaseId]);
      $piInstallIns = $pdo->prepare('INSERT INTO purchase_invoice_installments (purchase_invoice_id, company_id, installment_number, due_date, amount, finance_payable_id, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
      $docInstallIns = $pdo->prepare('INSERT INTO fiscal_document_installments (fiscal_document_id, company_id, installment_number, due_date, amount, finance_payable_id, status, created_at) VALUES (?,?,?,?,?,?,?,NOW())');
      $pdo->prepare('DELETE FROM fiscal_document_installments WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $fiscalDocumentId]);
      $createdPayables = 0;
      $chartId = $generatePayables ? fiscal_default_debit_chart_id($pdo, $companyId) : null;
      foreach ($installments as $idx => $inst) {
        $due = $inst['due_date'] ?: $issueDate;
        $amount = (float)($inst['amount'] ?? 0);
        $num = (string)($inst['number'] ?: str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT));
        $payableId = null;
        if ($generatePayables && !$alreadyHadPayables && fiscal_finance_available($pdo) && $supplierId && $chartId && $amount > 0) {
          $docNumber = trim((string)($parsed['series'] ?? '') . '/' . (string)($parsed['document_number'] ?? '') . '-' . $num, '/-');
          $desc = 'Compra XML NF ' . trim((string)($parsed['series'] ?? '') . '/' . (string)($parsed['document_number'] ?? ''), '/');
          $pdo->prepare('INSERT INTO finance_payables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')->execute([$companyId, $supplierId, $chartId, null, $desc, $docNumber, $num, $issueDate, $due, '', $amount, 0, 0, 0, 0, $amount, 'ABERTO', 'Gerado automaticamente pelo Radar XML #' . $radarId]);
          $payableId = (int)$pdo->lastInsertId();
          $createdPayables++;
        }
        $piInstallIns->execute([$purchaseId, $companyId, $num, $due, $amount, $payableId, 'ABERTO']);
        $docInstallIns->execute([$fiscalDocumentId, $companyId, $num, $due, $amount, $payableId, 'ABERTO']);
      }

      if ($generatePayables && !$alreadyHadPayables && $createdPayables > 0) {
        $pdo->prepare('UPDATE purchase_invoices SET payables_generated=1, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $purchaseId]);
      }
      $pdo->prepare('UPDATE fiscal_xml_radar SET radar_status=?, fiscal_document_id=?, purchase_invoice_id=?, imported_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?')->execute(['IMPORTADO', $fiscalDocumentId, $purchaseId, $companyId, $radarId]);
      $pdo->commit();
      return ['purchase_id' => $purchaseId, 'fiscal_document_id' => $fiscalDocumentId, 'payables_created' => $createdPayables, 'supplier_person_id' => $supplierId];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}


/* -------------------------------------------------------------------------
 * Distribuição DF-e / Radar XML Receita-SEFAZ
 * ------------------------------------------------------------------------- */
if (!function_exists('fiscal_uf_to_cuf')) {
  function fiscal_uf_to_cuf(string $uf): string {
    $map = [
      'RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17',
      'MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27','SE'=>'28','BA'=>'29',
      'MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35','PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53'
    ];
    $uf = strtoupper(trim($uf));
    return $map[$uf] ?? '';
  }
}

if (!function_exists('fiscal_dfe_add_column')) {
  function fiscal_dfe_add_column(PDO $pdo, string $table, string $column, string $definition): void {
    if (function_exists('fiscal_add_column_if_missing')) fiscal_add_column_if_missing($pdo, $table, $column, $definition);
  }
}

if (!function_exists('fiscal_ensure_dfe_distribution_schema')) {
  function fiscal_ensure_dfe_distribution_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_radar (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      source VARCHAR(30) NOT NULL DEFAULT 'UPLOAD',
      file_name VARCHAR(255) NULL,
      file_path VARCHAR(255) NULL,
      xml_hash VARCHAR(64) NOT NULL,
      access_key VARCHAR(60) NULL,
      model VARCHAR(10) NULL,
      direction VARCHAR(10) NOT NULL DEFAULT 'ENTRADA',
      document_number BIGINT NULL,
      series VARCHAR(10) NULL,
      issue_datetime DATETIME NULL,
      issuer_document VARCHAR(30) NULL,
      issuer_name VARCHAR(180) NULL,
      recipient_document VARCHAR(30) NULL,
      recipient_name VARCHAR(180) NULL,
      total_document DECIMAL(15,2) NOT NULL DEFAULT 0,
      protocol_number VARCHAR(60) NULL,
      status_code VARCHAR(10) NULL,
      status_message VARCHAR(255) NULL,
      radar_status VARCHAR(30) NOT NULL DEFAULT 'NOVO',
      fiscal_document_id BIGINT NULL,
      purchase_invoice_id BIGINT NULL,
      xml_raw LONGTEXT NULL,
      error_message TEXT NULL,
      imported_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_xml_radar_hash (company_id, xml_hash),
      KEY idx_fiscal_xml_radar_access (company_id, access_key),
      KEY idx_fiscal_xml_radar_status (company_id, radar_status, issue_datetime),
      KEY idx_fiscal_xml_radar_emit (company_id, issuer_document)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_xml_radar_items (
      id BIGINT NOT NULL AUTO_INCREMENT,
      radar_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      item_number INT NOT NULL,
      supplier_product_code VARCHAR(80) NULL,
      ean VARCHAR(20) NULL,
      description VARCHAR(255) NOT NULL,
      ncm VARCHAR(8) NULL,
      cest VARCHAR(12) NULL,
      cfop VARCHAR(4) NULL,
      unidade VARCHAR(20) NULL,
      quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
      unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
      total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      matched_product_id INT NULL,
      match_status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_xml_radar_items_radar (radar_id),
      KEY idx_fiscal_xml_radar_items_match (company_id, match_status, matched_product_id),
      KEY idx_fiscal_xml_radar_items_ean (company_id, ean)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (fiscal_table_exists($pdo, 'fiscal_company_config')) {
      fiscal_dfe_add_column($pdo, 'fiscal_company_config', 'company_cnpj', 'VARCHAR(14) NULL AFTER environment');
      fiscal_dfe_add_column($pdo, 'fiscal_company_config', 'state_registration', 'VARCHAR(30) NULL AFTER company_cnpj');
    }
    if (fiscal_table_exists($pdo, 'fiscal_xml_radar')) {
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'dfe_nsu', 'VARCHAR(20) NULL AFTER source');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'dfe_schema', 'VARCHAR(80) NULL AFTER dfe_nsu');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'dfe_kind', 'VARCHAR(30) NULL AFTER dfe_schema');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'manifest_required', 'TINYINT(1) NOT NULL DEFAULT 0 AFTER dfe_kind');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'manifest_status', 'VARCHAR(30) NULL AFTER manifest_required');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'manifest_event_type', 'VARCHAR(10) NULL AFTER manifest_status');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'manifested_at', 'DATETIME NULL AFTER manifest_event_type');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'manifest_response', 'LONGTEXT NULL AFTER manifested_at');
      fiscal_dfe_add_column($pdo, 'fiscal_xml_radar', 'dfe_received_at', 'DATETIME NULL AFTER manifest_response');
      try { $pdo->exec('CREATE INDEX idx_fiscal_xml_radar_dfe_nsu ON fiscal_xml_radar (company_id, dfe_nsu)'); } catch (Throwable $e) {}
      try { $pdo->exec('CREATE INDEX idx_fiscal_xml_radar_dfe_kind ON fiscal_xml_radar (company_id, dfe_kind, dfe_received_at)'); } catch (Throwable $e) {}
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_dfe_sync_state (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      cnpj VARCHAR(14) NOT NULL,
      uf CHAR(2) NOT NULL DEFAULT 'MG',
      cuf VARCHAR(2) NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      last_nsu VARCHAR(20) NOT NULL DEFAULT '0',
      max_nsu VARCHAR(20) NULL,
      last_success_at DATETIME NULL,
      last_attempt_at DATETIME NULL,
      next_allowed_at DATETIME NULL,
      last_status_code VARCHAR(10) NULL,
      last_status_message VARCHAR(255) NULL,
      last_documents_count INT NOT NULL DEFAULT 0,
      last_xml_count INT NOT NULL DEFAULT 0,
      auto_check_enabled TINYINT(1) NOT NULL DEFAULT 0,
      minimum_interval_minutes INT NOT NULL DEFAULT 60,
      provider VARCHAR(30) NOT NULL DEFAULT 'NFEPHP_DIRECT',
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_dfe_sync_state_company (company_id, cnpj, environment),
      KEY idx_dfe_sync_next (company_id, next_allowed_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_dfe_sync_logs (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      state_id INT NULL,
      requested_cnpj VARCHAR(14) NULL,
      uf CHAR(2) NULL,
      cuf VARCHAR(2) NULL,
      environment VARCHAR(20) NULL,
      last_nsu_before VARCHAR(20) NULL,
      last_nsu_after VARCHAR(20) NULL,
      max_nsu VARCHAR(20) NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
      status_code VARCHAR(10) NULL,
      status_message VARCHAR(255) NULL,
      documents_found INT NOT NULL DEFAULT 0,
      xmls_stored INT NOT NULL DEFAULT 0,
      summaries_stored INT NOT NULL DEFAULT 0,
      raw_command TEXT NULL,
      raw_response LONGTEXT NULL,
      error_message TEXT NULL,
      started_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      finished_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_dfe_sync_logs_company (company_id, started_at),
      KEY idx_dfe_sync_logs_state (state_id, started_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('fiscal_extract_cnpj_from_certificate')) {
  function fiscal_extract_cnpj_from_certificate(PDO $pdo, int $companyId): string {
    if (!fiscal_table_exists($pdo, 'fiscal_certificates')) return '';
    try {
      $st = $pdo->prepare('SELECT subject_name, display_name FROM fiscal_certificates WHERE company_id=? AND is_active=1 ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $text = (string)($row['subject_name'] ?? '') . ' ' . (string)($row['display_name'] ?? '');
      if (preg_match('/\b(\d{14})\b/', preg_replace('/[^0-9A-Za-z ]+/', ' ', $text), $m)) return $m[1];
      if (preg_match('/:(\d{14})\b/', $text, $m)) return $m[1];
    } catch (Throwable $e) {}
    return '';
  }
}

if (!function_exists('fiscal_dfe_state')) {
  function fiscal_dfe_state(PDO $pdo, int $companyId): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $cnpj = fiscal_digits($cfg['company_cnpj'] ?? '');
    if ($cnpj === '') $cnpj = fiscal_extract_cnpj_from_certificate($pdo, $companyId);
    $uf = strtoupper(trim((string)($cfg['uf'] ?? 'MG'))) ?: 'MG';
    $env = in_array(($cfg['environment'] ?? ''), ['HOMOLOGACAO','PRODUCAO'], true) ? (string)$cfg['environment'] : 'HOMOLOGACAO';
    $default = [
      'id'=>0, 'company_id'=>$companyId, 'cnpj'=>$cnpj, 'uf'=>$uf, 'cuf'=>fiscal_uf_to_cuf($uf), 'environment'=>$env,
      'last_nsu'=>'0', 'max_nsu'=>'0', 'last_success_at'=>null, 'last_attempt_at'=>null, 'next_allowed_at'=>null,
      'last_status_code'=>'', 'last_status_message'=>'', 'last_documents_count'=>0, 'last_xml_count'=>0,
      'auto_check_enabled'=>0, 'minimum_interval_minutes'=>60, 'provider'=>'NFEPHP_DIRECT'
    ];
    if ($cnpj !== '') {
      $st = $pdo->prepare('SELECT * FROM fiscal_dfe_sync_state WHERE company_id=? AND cnpj=? AND environment=? LIMIT 1');
      $st->execute([$companyId, $cnpj, $env]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if ($row) return array_merge($default, $row);
    }
    $st = $pdo->prepare('SELECT * FROM fiscal_dfe_sync_state WHERE company_id=? ORDER BY updated_at DESC, id DESC LIMIT 1');
    $st->execute([$companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ? array_merge($default, $row) : $default;
  }
}

if (!function_exists('fiscal_dfe_save_state')) {
  function fiscal_dfe_save_state(PDO $pdo, int $companyId, array $data): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $cnpj = substr(fiscal_digits($data['cnpj'] ?? ($cfg['company_cnpj'] ?? '')), 0, 14);
    if (strlen($cnpj) !== 14) throw new RuntimeException('Informe o CNPJ da empresa com 14 dígitos para consultar a Distribuição DF-e.');
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($data['uf'] ?? ($cfg['uf'] ?? 'MG'))), 0, 2));
    $cuf = fiscal_uf_to_cuf($uf);
    if ($cuf === '') throw new RuntimeException('UF inválida para consulta DF-e.');
    $env = in_array(($data['environment'] ?? ($cfg['environment'] ?? 'HOMOLOGACAO')), ['HOMOLOGACAO','PRODUCAO'], true) ? (string)($data['environment'] ?? $cfg['environment']) : 'HOMOLOGACAO';
    $interval = max(60, (int)($data['minimum_interval_minutes'] ?? 60));
    $lastNsu = preg_replace('/\D+/', '', (string)($data['last_nsu'] ?? '0')) ?: '0';
    $auto = !empty($data['auto_check_enabled']) ? 1 : 0;
    $provider = in_array(($data['provider'] ?? 'NFEPHP_DIRECT'), ['NFEPHP_DIRECT','ACBRMONITOR','OUTRO'], true) ? (string)$data['provider'] : 'NFEPHP_DIRECT';
    $sql = "INSERT INTO fiscal_dfe_sync_state (company_id, cnpj, uf, cuf, environment, last_nsu, minimum_interval_minutes, auto_check_enabled, provider, created_at)
            VALUES (?,?,?,?,?,?,?,?,?,NOW())
            ON DUPLICATE KEY UPDATE uf=VALUES(uf), cuf=VALUES(cuf), last_nsu=VALUES(last_nsu), minimum_interval_minutes=VALUES(minimum_interval_minutes), auto_check_enabled=VALUES(auto_check_enabled), provider=VALUES(provider), updated_at=NOW()";
    $pdo->prepare($sql)->execute([$companyId, $cnpj, $uf, $cuf, $env, $lastNsu, $interval, $auto, $provider]);
    try {
      if (fiscal_table_exists($pdo, 'fiscal_company_config') && fiscal_column_exists($pdo, 'fiscal_company_config', 'company_cnpj')) {
        $pdo->prepare('UPDATE fiscal_company_config SET company_cnpj=?, uf=?, environment=?, updated_at=NOW() WHERE company_id=?')->execute([$cnpj, $uf, $env, $companyId]);
      }
    } catch (Throwable $e) {}
    return fiscal_dfe_state($pdo, $companyId);
  }
}

if (!function_exists('fiscal_dfe_can_sync')) {
  function fiscal_dfe_can_sync(array $state): bool {
    $next = trim((string)($state['next_allowed_at'] ?? ''));
    if ($next === '' || $next === '0000-00-00 00:00:00') return true;
    return strtotime($next) <= time();
  }
}

if (!function_exists('fiscal_dfe_wait_minutes')) {
  function fiscal_dfe_wait_minutes(array $state): int {
    $next = trim((string)($state['next_allowed_at'] ?? ''));
    if ($next === '' || $next === '0000-00-00 00:00:00') return 0;
    $diff = strtotime($next) - time();
    return max(0, (int)ceil($diff / 60));
  }
}

if (!function_exists('fiscal_acbr_send_tcp')) {
  function fiscal_acbr_send_tcp(string $host, int $port, string $command, int $timeout = 60): string {
    $errno = 0; $errstr = '';
    $fp = @fsockopen($host, $port, $errno, $errstr, 10);
    if (!$fp) throw new RuntimeException('Não foi possível conectar ao ACBrMonitor em ' . $host . ':' . $port . '. ' . trim($errstr));
    stream_set_blocking($fp, false);
    @fwrite($fp, $command . "\r\n");
    $raw = '';
    $start = microtime(true);
    $lastData = $start;
    while ((microtime(true) - $start) < $timeout) {
      $chunk = @fread($fp, 8192);
      if ($chunk !== false && $chunk !== '') {
        $raw .= $chunk;
        $lastData = microtime(true);
      } elseif ($raw !== '' && (microtime(true) - $lastData) > 1.2) {
        break;
      }
      usleep(100000);
    }
    @fclose($fp);
    if (trim($raw) === '') throw new RuntimeException('ACBrMonitor não retornou resposta para o comando de Distribuição DF-e.');
    return $raw;
  }
}

if (!function_exists('fiscal_acbr_send_folder')) {
  function fiscal_acbr_send_folder(string $inDir, string $outDir, string $command, int $timeout = 75): string {
    if (!is_dir($inDir) || !is_writable($inDir)) throw new RuntimeException('Pasta de entrada do ACBrMonitor não encontrada ou sem permissão: ' . $inDir);
    if (!is_dir($outDir) || !is_readable($outDir)) throw new RuntimeException('Pasta de saída do ACBrMonitor não encontrada ou sem permissão: ' . $outDir);
    $base = 'dfe_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.txt';
    $in = rtrim($inDir, '/\\') . DIRECTORY_SEPARATOR . $base;
    $out = rtrim($outDir, '/\\') . DIRECTORY_SEPARATOR . $base;
    file_put_contents($in, $command . PHP_EOL);
    $start = time();
    while ((time() - $start) < $timeout) {
      if (is_file($out)) {
        $raw = (string)file_get_contents($out);
        @unlink($out);
        return $raw;
      }
      usleep(500000);
    }
    throw new RuntimeException('Tempo esgotado aguardando retorno do ACBrMonitor na pasta de saída.');
  }
}

if (!function_exists('fiscal_dfe_send_command')) {
  function fiscal_dfe_send_command(PDO $pdo, int $companyId, string $command): string {
    $cfg = fiscal_company_config($pdo, $companyId);
    $host = trim((string)($cfg['acbr_host'] ?? '')) ?: '127.0.0.1';
    $port = (int)($cfg['acbr_port'] ?? 0);
    if ($port > 0) return fiscal_acbr_send_tcp($host, $port, $command, 75);
    $inDir = trim((string)($cfg['xml_in_path'] ?? ''));
    $outDir = trim((string)($cfg['xml_out_path'] ?? ''));
    if ($inDir !== '' && $outDir !== '') return fiscal_acbr_send_folder($inDir, $outDir, $command, 90);
    throw new RuntimeException('Configure Host/Porta do ACBrMonitor ou as pastas de entrada/saída em Fiscal > Configurações.');
  }
}


if (!function_exists('fiscal_dfe_xml_value')) {
  function fiscal_dfe_xml_value(string $xml, string $localName): string {
    if (trim($xml) === '' || trim($localName) === '') return '';
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) return '';
    $xp = new DOMXPath($dom);
    $nodes = @$xp->query('//*[local-name()="' . str_replace('"', '', $localName) . '"]');
    if (!$nodes || $nodes->length === 0) return '';
    return trim((string)$nodes->item(0)?->textContent);
  }
}

if (!function_exists('fiscal_dfe_response_value')) {
  function fiscal_dfe_response_value(string $raw, array $keys): string {
    foreach ($keys as $key) {
      $value = fiscal_dfe_xml_value($raw, (string)$key);
      if ($value !== '') return $value;
    }
    return fiscal_dfe_ini_value($raw, $keys);
  }
}

if (!function_exists('fiscal_dfe_nfephp_request')) {
  /**
   * Consulta direta ao Ambiente Nacional pelo NFePHP/sped-nfe.
   * O certificado A1 e o CNPJ são isolados por empresa.
   */
  function fiscal_dfe_nfephp_request(
    PDO $pdo,
    int $companyId,
    int $ultNsu = 0,
    int $numNsu = 0,
    ?string $accessKey = null
  ): string {
    $cfg = fiscal_company_config($pdo, $companyId);
    $configuredCnpj = fiscal_digits($cfg['company_cnpj'] ?? '');
    if (strlen($configuredCnpj) !== 14) {
      throw new RuntimeException('Configure o CNPJ da empresa nas Configurações Fiscais antes de usar o RADAR NF-e.');
    }

    $certificateCnpj = fiscal_extract_cnpj_from_certificate($pdo, $companyId);
    if ($certificateCnpj !== '' && substr($certificateCnpj, 0, 8) !== substr($configuredCnpj, 0, 8)) {
      throw new RuntimeException(
        'O CNPJ-base do certificado digital não corresponde ao CNPJ configurado para esta empresa.'
      );
    }

    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, '55');
    $key = $accessKey !== null ? fiscal_digits($accessKey) : null;
    if ($key !== null && strlen($key) !== 44) {
      throw new RuntimeException('Chave de acesso inválida para consulta pontual da NF-e.');
    }
    return (string)$tools->sefazDistDFe(max(0, $ultNsu), max(0, $numNsu), $key, 'AN');
  }
}

if (!function_exists('fiscal_dfe_provider_request')) {
  function fiscal_dfe_provider_request(
    PDO $pdo,
    int $companyId,
    array $state,
    string $mode = 'LAST_NSU',
    string $value = ''
  ): array {
    $provider = strtoupper(trim((string)($state['provider'] ?? 'NFEPHP_DIRECT')));
    $lastNsu = (int)(preg_replace('/\D+/', '', (string)($state['last_nsu'] ?? '0')) ?: '0');

    if ($provider === 'NFEPHP_DIRECT') {
      if ($mode === 'KEY') {
        $raw = fiscal_dfe_nfephp_request($pdo, $companyId, 0, 0, $value);
        return ['provider'=>$provider, 'command'=>'NFePHP::sefazDistDFe(consChNFe)', 'raw'=>$raw];
      }
      if ($mode === 'NSU') {
        $raw = fiscal_dfe_nfephp_request($pdo, $companyId, 0, (int)$value, null);
        return ['provider'=>$provider, 'command'=>'NFePHP::sefazDistDFe(consNSU)', 'raw'=>$raw];
      }
      $raw = fiscal_dfe_nfephp_request($pdo, $companyId, $lastNsu, 0, null);
      return ['provider'=>$provider, 'command'=>'NFePHP::sefazDistDFe(distNSU=' . $lastNsu . ')', 'raw'=>$raw];
    }

    $cnpj = fiscal_digits($state['cnpj'] ?? '');
    $cuf = (string)($state['cuf'] ?? '');
    if ($mode === 'KEY') {
      $command = 'NFe.DistribuicaoDFePorChave(' . $cuf . ',' . $cnpj . ',' . fiscal_digits($value) . ')';
    } elseif ($mode === 'NSU') {
      $command = 'NFe.DistribuicaoDFePorNSU(' . $cuf . ',' . $cnpj . ',' . preg_replace('/\D+/', '', $value) . ')';
    } else {
      $command = 'NFe.DistribuicaoDFePorUltNSU(' . $cuf . ',' . $cnpj . ',' . $lastNsu . ')';
    }
    return ['provider'=>$provider, 'command'=>$command, 'raw'=>fiscal_dfe_send_command($pdo, $companyId, $command)];
  }
}

if (!function_exists('fiscal_dfe_ini_value')) {
  function fiscal_dfe_ini_value(string $raw, array $keys): string {
    foreach ($keys as $key) {
      if (preg_match('/(?:^|\R)\s*' . preg_quote((string)$key, '/') . '\s*=\s*(.*?)(?=\R|$)/i', $raw, $m)) return trim((string)$m[1]);
    }
    return '';
  }
}

if (!function_exists('fiscal_dfe_decode_doczip')) {
  function fiscal_dfe_decode_doczip(string $payload): string {
    $payload = preg_replace('/\s+/', '', $payload);
    $bin = base64_decode($payload, true);
    if ($bin === false) return '';
    foreach ([
      function($v) { return function_exists('gzdecode') ? @gzdecode($v) : false; },
      function($v) { return @gzuncompress($v); },
      function($v) { return @gzinflate($v); },
      function($v) { return strlen($v) > 6 ? @gzinflate(substr($v, 2, -4)) : false; },
    ] as $try) {
      $xml = $try($bin);
      if (is_string($xml) && trim($xml) !== '') return $xml;
    }
    return '';
  }
}

if (!function_exists('fiscal_dfe_kind_from_xml')) {
  function fiscal_dfe_kind_from_xml(string $xml, string $schema = ''): string {
    $s = strtolower($schema . ' ' . substr($xml, 0, 250));
    if (strpos($s, 'resnfe') !== false || preg_match('/<([a-z0-9_\-]+:)?resNFe\b/i', $xml)) return 'RESNFE';
    if (strpos($s, 'procnfe') !== false || preg_match('/<([a-z0-9_\-]+:)?nfeProc\b/i', $xml)) return 'PROCNFE';
    if (preg_match('/<([a-z0-9_\-]+:)?NFe\b/i', $xml)) return 'NFE';
    if (strpos($s, 'procevento') !== false || preg_match('/<([a-z0-9_\-]+:)?procEventoNFe\b/i', $xml)) return 'EVENTO';
    return 'OUTRO';
  }
}

if (!function_exists('fiscal_dfe_extract_docs_from_return')) {
  function fiscal_dfe_extract_docs_from_return(string $raw): array {
    $docs = [];
    if (preg_match_all('~<([A-Za-z0-9_\-]+:)?docZip\b([^>]*)>(.*?)</([A-Za-z0-9_\-]+:)?docZip>~si', $raw, $m, PREG_SET_ORDER)) {
      foreach ($m as $row) {
        $attrs = (string)$row[2];
        $xml = fiscal_dfe_decode_doczip((string)$row[3]);
        if (trim($xml) === '') continue;
        $schema = fiscal_xml_attr($attrs, 'schema');
        $nsu = fiscal_xml_attr($attrs, 'NSU') ?: fiscal_xml_attr($attrs, 'nsu');
        $docs[] = ['xml'=>$xml, 'schema'=>$schema, 'nsu'=>$nsu, 'source'=>'docZip'];
      }
    }
    foreach (['nfeProc','NFe','resNFe','procEventoNFe'] as $tag) {
      if (preg_match_all('~<([A-Za-z0-9_\-]+:)?' . preg_quote($tag, '~') . '\b[^>]*>.*?</([A-Za-z0-9_\-]+:)?' . preg_quote($tag, '~') . '>~si', $raw, $mm)) {
        foreach ($mm[0] as $xml) $docs[] = ['xml'=>(string)$xml, 'schema'=>'', 'nsu'=>'', 'source'=>'raw'];
      }
    }
    if (preg_match_all('/(?:Arquivo|XML|NomeArquivo|Path)\s*=\s*([^
]+?\.xml)/i', $raw, $fm)) {
      foreach ($fm[1] as $path) {
        $path = trim((string)$path, " \t\r\n\"'");
        if (is_file($path) && is_readable($path)) {
          $xml = (string)file_get_contents($path);
          if (trim($xml) !== '') $docs[] = ['xml'=>$xml, 'schema'=>'arquivo', 'nsu'=>'', 'source'=>'file'];
        }
      }
    }
    $unique = [];
    foreach ($docs as $d) {
      $hash = hash('sha256', (string)$d['xml']);
      if (isset($unique[$hash])) continue;
      $unique[$hash] = $d;
    }
    return array_values($unique);
  }
}

if (!function_exists('fiscal_dfe_access_key_parts')) {
  function fiscal_dfe_access_key_parts(string $accessKey): array {
    $ch = fiscal_digits($accessKey);
    if (strlen($ch) !== 44) return ['model'=>'55','series'=>'','number'=>0];
    return [
      'model' => substr($ch, 20, 2),
      'series' => ltrim(substr($ch, 22, 3), '0') ?: '0',
      'number' => (int)ltrim(substr($ch, 25, 9), '0'),
    ];
  }
}

if (!function_exists('fiscal_store_dfe_summary_xml')) {
  function fiscal_store_dfe_summary_xml(PDO $pdo, int $companyId, string $xml, string $schema = '', string $nsu = ''): int {
    if (!fiscal_table_exists($pdo, 'fiscal_xml_radar')) throw new RuntimeException('Tabela fiscal_xml_radar não encontrada.');
    $kind = fiscal_dfe_kind_from_xml($xml, $schema);
    $accessKey = fiscal_xml_first_tag($xml, 'chNFe');
    $parts = fiscal_dfe_access_key_parts($accessKey);
    $issuerDoc = fiscal_xml_first_of($xml, ['CNPJ','CPF']);
    $issuerName = fiscal_xml_first_tag($xml, 'xNome');
    $issue = fiscal_sql_datetime(fiscal_xml_first_tag($xml, 'dhEmi'));
    $total = fiscal_money_xml(fiscal_xml_first_tag($xml, 'vNF'));
    $protocol = fiscal_xml_first_tag($xml, 'nProt');
    $statusMsg = $kind === 'RESNFE' ? 'Resumo da NF-e recebido pela Distribuição DF-e. Para obter XML completo, pode ser necessária manifestação do destinatário.' : 'Evento/registro recebido pela Distribuição DF-e.';
    $hash = hash('sha256', $xml);
    $fileName = 'sefaz_' . ($nsu !== '' ? $nsu . '_' : '') . ($accessKey ?: substr($hash, 0, 12)) . '.xml';
    $dir = __DIR__ . '/../uploads/fiscal_xml/' . $companyId;
    $filePath = '';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    if (is_dir($dir)) {
      $destName = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]+/', '_', $fileName);
      file_put_contents($dir . '/' . $destName, $xml);
      $filePath = 'uploads/fiscal_xml/' . $companyId . '/' . $destName;
    }
    $sql = "INSERT INTO fiscal_xml_radar
      (company_id, source, dfe_nsu, dfe_schema, dfe_kind, manifest_required, manifest_status, dfe_received_at, file_name, file_path, xml_hash, access_key, model, direction, document_number, series, issue_datetime, issuer_document, issuer_name, recipient_document, recipient_name, total_document, protocol_number, status_code, status_message, radar_status, xml_raw, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE id=LAST_INSERT_ID(id), dfe_nsu=COALESCE(NULLIF(VALUES(dfe_nsu),''),dfe_nsu), dfe_schema=VALUES(dfe_schema), dfe_kind=VALUES(dfe_kind), manifest_required=VALUES(manifest_required), manifest_status=VALUES(manifest_status), dfe_received_at=COALESCE(dfe_received_at, VALUES(dfe_received_at)), file_path=COALESCE(NULLIF(VALUES(file_path),''),file_path), access_key=VALUES(access_key), total_document=VALUES(total_document), protocol_number=VALUES(protocol_number), status_message=VALUES(status_message), updated_at=NOW()";
    $pdo->prepare($sql)->execute([
      $companyId, 'SEFAZ_DISTRIBUICAO', $nsu, $schema, $kind, $kind === 'RESNFE' ? 1 : 0, $kind === 'RESNFE' ? 'PENDENTE' : '', fiscal_op_now(),
      $fileName, $filePath, $hash, $accessKey, $parts['model'], 'ENTRADA', $parts['number'], $parts['series'], $issue,
      $issuerDoc, $issuerName, '', '', $total, $protocol, fiscal_xml_first_tag($xml, 'cSitNFe'), $statusMsg, 'NOVO', $xml
    ]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('fiscal_dfe_store_doc')) {
  function fiscal_dfe_store_doc(PDO $pdo, int $companyId, array $doc): array {
    $xml = (string)($doc['xml'] ?? '');
    $schema = (string)($doc['schema'] ?? '');
    $nsu = (string)($doc['nsu'] ?? '');
    $kind = fiscal_dfe_kind_from_xml($xml, $schema);
    if (in_array($kind, ['PROCNFE','NFE'], true)) {
      $accessKey = '';
      try { $parsed = fiscal_parse_xml_content($xml); $accessKey = (string)($parsed['access_key'] ?? ''); } catch (Throwable $e) {}
      $id = fiscal_store_radar_xml($pdo, $companyId, $xml, 'sefaz_' . ($nsu !== '' ? $nsu . '_' : '') . ($accessKey ?: date('Ymd_His')) . '.xml', 'SEFAZ_DISTRIBUICAO');
      try {
        $pdo->prepare('UPDATE fiscal_xml_radar SET dfe_nsu=?, dfe_schema=?, dfe_kind=?, manifest_required=0, manifest_status=CASE WHEN manifest_status IS NULL OR manifest_status="" OR manifest_status="PENDENTE" THEN ? ELSE manifest_status END, dfe_received_at=COALESCE(dfe_received_at,NOW()), updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$nsu, $schema, $kind, 'XML_COMPLETO', $companyId, $id]);

        // Se antes existia apenas o resumo resNFe, consolida tudo em uma única
        // linha por chave, preservando NSU e manifestação.
        if ($accessKey !== '') {
          $stOld = $pdo->prepare('SELECT * FROM fiscal_xml_radar WHERE company_id=? AND access_key=? AND id<>? ORDER BY id DESC LIMIT 1');
          $stOld->execute([$companyId, $accessKey, $id]);
          $old = $stOld->fetch(PDO::FETCH_ASSOC);
          if ($old) {
            $pdo->prepare('UPDATE fiscal_xml_radar SET dfe_nsu=COALESCE(NULLIF(dfe_nsu,""),?), manifest_status=COALESCE(NULLIF(manifest_status,""),?), manifest_event_type=COALESCE(NULLIF(manifest_event_type,""),?), manifested_at=COALESCE(manifested_at,?), manifest_response=COALESCE(manifest_response,?) WHERE company_id=? AND id=?')
              ->execute([
                $old['dfe_nsu'] ?? '',
                $old['manifest_status'] ?? '',
                $old['manifest_event_type'] ?? '',
                $old['manifested_at'] ?? null,
                $old['manifest_response'] ?? null,
                $companyId,
                $id,
              ]);
            $pdo->prepare('DELETE FROM fiscal_xml_radar_items WHERE company_id=? AND radar_id=?')->execute([$companyId, (int)$old['id']]);
            $pdo->prepare('DELETE FROM fiscal_xml_radar WHERE company_id=? AND id=?')->execute([$companyId, (int)$old['id']]);
          }
        }
      } catch (Throwable $e) {}
      return ['id'=>$id, 'kind'=>$kind, 'full'=>1];
    }
    $id = fiscal_store_dfe_summary_xml($pdo, $companyId, $xml, $schema, $nsu);
    return ['id'=>$id, 'kind'=>$kind, 'full'=>0];
  }
}

if (!function_exists('fiscal_dfe_recent_logs')) {
  function fiscal_dfe_recent_logs(PDO $pdo, int $companyId, int $limit = 8): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_dfe_sync_logs WHERE company_id=? ORDER BY started_at DESC, id DESC LIMIT ' . max(1, min(50, $limit)));
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_dfe_download_from_sefaz')) {
  /**
   * Sincroniza a sequência NSU do Ambiente Nacional.
   *
   * - NFEPHP_DIRECT: conexão direta com certificado A1.
   * - ACBRMONITOR: mantém compatibilidade com a preparação anterior.
   *
   * Quando há documentos pendentes, percorre lotes sequenciais sem pular NSU.
   * Ao receber cStat 137 ou 656, respeita a espera mínima de 1 hora.
   */
  function fiscal_dfe_download_from_sefaz(
    PDO $pdo,
    int $companyId,
    bool $force = false,
    int $maxBatches = 10
  ): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $state = fiscal_dfe_state($pdo, $companyId);
    if (strlen(fiscal_digits($state['cnpj'] ?? '')) !== 14) {
      throw new RuntimeException('Configure o CNPJ da empresa antes de baixar XMLs da Receita/SEFAZ.');
    }

    $nextAllowed = trim((string)($state['next_allowed_at'] ?? ''));
    $lastCode = trim((string)($state['last_status_code'] ?? ''));
    if ($lastCode === '656' && $nextAllowed !== '' && strtotime($nextAllowed) > time()) {
      throw new RuntimeException(
        'A SEFAZ bloqueou temporariamente este CNPJ por consumo indevido. '
        . 'Aguarde aproximadamente ' . fiscal_dfe_wait_minutes($state) . ' minuto(s).'
      );
    }
    if (!$force && !fiscal_dfe_can_sync($state)) {
      throw new RuntimeException(
        'A próxima consulta à SEFAZ estará liberada em aproximadamente '
        . fiscal_dfe_wait_minutes($state) . ' minuto(s).'
      );
    }

    $stateId = (int)($state['id'] ?? 0);
    if ($stateId <= 0) $state = fiscal_dfe_save_state($pdo, $companyId, $state);
    $stateId = (int)($state['id'] ?? 0);

    $lastNsuBefore = preg_replace('/\D+/', '', (string)($state['last_nsu'] ?? '0')) ?: '0';
    $provider = strtoupper(trim((string)($state['provider'] ?? 'NFEPHP_DIRECT')));
    $pdo->prepare(
      'INSERT INTO fiscal_dfe_sync_logs '
      . '(company_id, state_id, requested_cnpj, uf, cuf, environment, last_nsu_before, status, raw_command, started_at) '
      . 'VALUES (?,?,?,?,?,?,?,?,?,NOW())'
    )->execute([
      $companyId,
      $stateId,
      fiscal_digits($state['cnpj']),
      $state['uf'],
      $state['cuf'],
      $state['environment'],
      $lastNsuBefore,
      'PROCESSANDO',
      $provider . ' / distNSU',
    ]);
    $logId = (int)$pdo->lastInsertId();

    $intervalMinutes = max(60, (int)($state['minimum_interval_minutes'] ?? 60));
    $maxBatches = max(1, min(30, $maxBatches));
    $documentsFound = 0;
    $full = 0;
    $summary = 0;
    $lastNsuAfter = $lastNsuBefore;
    $maxNsu = preg_replace('/\D+/', '', (string)($state['max_nsu'] ?? '0')) ?: '0';
    $statusCode = '';
    $statusMsg = '';
    $rawResponses = [];
    $commands = [];
    $batches = 0;
    $backlogRemaining = false;

    try {
      for ($batch = 1; $batch <= $maxBatches; $batch++) {
        $batches = $batch;
        $state['last_nsu'] = $lastNsuAfter;
        $previousNsu = $lastNsuAfter;
        $request = fiscal_dfe_provider_request($pdo, $companyId, $state, 'LAST_NSU');
        $raw = (string)($request['raw'] ?? '');
        $commands[] = (string)($request['command'] ?? '');
        $rawResponses[] = $raw;

        $statusCode = fiscal_dfe_response_value($raw, ['cStat','CStat','Status','status']);
        $statusMsg = fiscal_dfe_response_value($raw, ['xMotivo','XMotivo','Motivo','mensagem','Mensagem']);
        $responseLastNsu = fiscal_dfe_response_value($raw, ['ultNSU','UltNSU','nUltNSU','ultimoNSU']);
        $responseMaxNsu = fiscal_dfe_response_value($raw, ['maxNSU','MaxNSU','nMaxNSU']);

        if ($responseLastNsu !== '') {
          $lastNsuAfter = preg_replace('/\D+/', '', $responseLastNsu) ?: $lastNsuAfter;
        }
        if ($responseMaxNsu !== '') {
          $maxNsu = preg_replace('/\D+/', '', $responseMaxNsu) ?: $maxNsu;
        }

        $docs = fiscal_dfe_extract_docs_from_return($raw);
        $batchStored = 0;
        foreach ($docs as $doc) {
          $res = fiscal_dfe_store_doc($pdo, $companyId, $doc);
          $batchStored++;
          $documentsFound++;
          if (!empty($res['full'])) $full++; else $summary++;
        }

        if ($statusCode === '656') {
          throw new RuntimeException(
            trim($statusMsg) !== ''
              ? $statusMsg
              : 'Rejeição 656: consumo indevido na Distribuição DF-e.'
          );
        }

        // 137 = nenhum documento novo. Aguardar no mínimo uma hora.
        if ($statusCode === '137') {
          $backlogRemaining = false;
          break;
        }

        // 138 = documentos localizados. Continue somente em ordem e se houve avanço.
        if ($statusCode === '138') {
          $backlogRemaining = (int)$lastNsuAfter < (int)$maxNsu;
          if (!$backlogRemaining) break;
          if ($batchStored === 0 || $lastNsuAfter === $previousNsu) break;
          continue;
        }

        // Alguns provedores podem omitir cStat, mas devolver documentos.
        if ($statusCode === '' && $batchStored > 0) {
          $backlogRemaining = (int)$lastNsuAfter < (int)$maxNsu;
          if ($backlogRemaining && $lastNsuAfter !== $previousNsu) continue;
        }
        break;
      }

      $nextAllowedAt = $backlogRemaining
        ? date('Y-m-d H:i:s', time() + 60)
        : date('Y-m-d H:i:s', time() + ($intervalMinutes * 60));

      $rawLog = implode("\n\n<!-- LOTE DF-E -->\n\n", $rawResponses);
      if (strlen($rawLog) > 8000000) $rawLog = substr($rawLog, -8000000);

      $pdo->prepare(
        'UPDATE fiscal_dfe_sync_state SET '
        . 'last_nsu=?, max_nsu=?, last_success_at=NOW(), last_attempt_at=NOW(), next_allowed_at=?, '
        . 'last_status_code=?, last_status_message=?, last_documents_count=?, last_xml_count=?, provider=?, updated_at=NOW() '
        . 'WHERE id=? AND company_id=?'
      )->execute([
        $lastNsuAfter,
        $maxNsu,
        $nextAllowedAt,
        $statusCode,
        substr($statusMsg, 0, 250),
        $documentsFound,
        $full,
        $provider,
        $stateId,
        $companyId,
      ]);

      $pdo->prepare(
        'UPDATE fiscal_dfe_sync_logs SET status=?, status_code=?, status_message=?, '
        . 'last_nsu_after=?, max_nsu=?, documents_found=?, xmls_stored=?, summaries_stored=?, '
        . 'raw_command=?, raw_response=?, finished_at=NOW() WHERE id=? AND company_id=?'
      )->execute([
        'CONCLUIDO',
        $statusCode,
        substr($statusMsg, 0, 250),
        $lastNsuAfter,
        $maxNsu,
        $documentsFound,
        $full,
        $summary,
        implode(' | ', $commands),
        $rawLog,
        $logId,
        $companyId,
      ]);

      return [
        'status'=>'CONCLUIDO',
        'provider'=>$provider,
        'status_code'=>$statusCode,
        'status_message'=>$statusMsg,
        'documents_found'=>$documentsFound,
        'xmls_stored'=>$full,
        'summaries_stored'=>$summary,
        'last_nsu'=>$lastNsuAfter,
        'max_nsu'=>$maxNsu,
        'batches'=>$batches,
        'backlog_remaining'=>$backlogRemaining,
        'next_allowed_at'=>$nextAllowedAt,
        'log_id'=>$logId,
      ];
    } catch (Throwable $e) {
      $nextAllowedAt = date('Y-m-d H:i:s', time() + 3600);
      $errorCode = stripos($e->getMessage(), '656') !== false ? '656' : 'ERRO';
      $pdo->prepare(
        'UPDATE fiscal_dfe_sync_state SET last_attempt_at=NOW(), next_allowed_at=?, '
        . 'last_status_code=?, last_status_message=?, updated_at=NOW() WHERE id=? AND company_id=?'
      )->execute([$nextAllowedAt, $errorCode, substr($e->getMessage(), 0, 250), $stateId, $companyId]);
      $pdo->prepare(
        'UPDATE fiscal_dfe_sync_logs SET status=?, status_code=?, error_message=?, raw_command=?, raw_response=?, '
        . 'finished_at=NOW() WHERE id=? AND company_id=?'
      )->execute([
        'ERRO',
        $errorCode,
        $e->getMessage(),
        implode(' | ', $commands),
        implode("\n\n<!-- LOTE DF-E -->\n\n", $rawResponses),
        $logId,
        $companyId,
      ]);
      throw $e;
    }
  }
}



if (!function_exists('fiscal_dfe_manifest_response_summary')) {
  function fiscal_dfe_manifest_response_summary(string $xml): array {
    $out = ['code'=>'', 'message'=>'', 'success'=>false];
    if (trim($xml) === '') return $out;
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) return $out;
    $xp = new DOMXPath($dom);
    $codeNodes = @$xp->query('//*[local-name()="infEvento"]/*[local-name()="cStat"]');
    $msgNodes = @$xp->query('//*[local-name()="infEvento"]/*[local-name()="xMotivo"]');
    if ($codeNodes && $codeNodes->length) $out['code'] = trim((string)$codeNodes->item($codeNodes->length - 1)?->textContent);
    if ($msgNodes && $msgNodes->length) $out['message'] = trim((string)$msgNodes->item($msgNodes->length - 1)?->textContent);
    if ($out['code'] === '') $out['code'] = fiscal_dfe_response_value($xml, ['cStat']);
    if ($out['message'] === '') $out['message'] = fiscal_dfe_response_value($xml, ['xMotivo']);
    $out['success'] = in_array($out['code'], ['128','135','136'], true);
    return $out;
  }
}

if (!function_exists('fiscal_dfe_fetch_by_key')) {
  function fiscal_dfe_fetch_by_key(PDO $pdo, int $companyId, string $accessKey): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $state = fiscal_dfe_state($pdo, $companyId);
    $provider = strtoupper(trim((string)($state['provider'] ?? 'NFEPHP_DIRECT')));
    if ($provider !== 'NFEPHP_DIRECT') {
      throw new RuntimeException('A consulta pontual por chave desta versão está disponível no provedor NFePHP direto.');
    }
    $key = fiscal_digits($accessKey);
    if (strlen($key) !== 44) throw new RuntimeException('Chave de acesso inválida.');
    $request = fiscal_dfe_provider_request($pdo, $companyId, $state, 'KEY', $key);
    $raw = (string)($request['raw'] ?? '');
    $statusCode = fiscal_dfe_response_value($raw, ['cStat']);
    $statusMsg = fiscal_dfe_response_value($raw, ['xMotivo']);
    if ($statusCode === '656') {
      throw new RuntimeException($statusMsg !== '' ? $statusMsg : 'Rejeição 656: consumo indevido.');
    }
    $docs = fiscal_dfe_extract_docs_from_return($raw);
    $full = 0;
    $summary = 0;
    foreach ($docs as $doc) {
      $res = fiscal_dfe_store_doc($pdo, $companyId, $doc);
      if (!empty($res['full'])) $full++; else $summary++;
    }
    return [
      'status_code'=>$statusCode,
      'status_message'=>$statusMsg,
      'documents_found'=>count($docs),
      'xmls_stored'=>$full,
      'summaries_stored'=>$summary,
      'raw'=>$raw,
    ];
  }
}

if (!function_exists('fiscal_dfe_manifest')) {
  /**
   * Registra manifestação do destinatário somente por ação explícita do usuário.
   * Nenhuma manifestação é enviada automaticamente pelo worker.
   */
  function fiscal_dfe_manifest(
    PDO $pdo,
    int $companyId,
    int $radarId,
    int $eventType,
    string $justification = ''
  ): array {
    fiscal_ensure_dfe_distribution_schema($pdo);
    $allowed = [
      210200 => 'CONFIRMACAO',
      210210 => 'CIENCIA',
      210220 => 'DESCONHECIMENTO',
      210240 => 'NAO_REALIZADA',
    ];
    if (!isset($allowed[$eventType])) throw new RuntimeException('Tipo de manifestação inválido.');

    $st = $pdo->prepare('SELECT * FROM fiscal_xml_radar WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $radarId]);
    $radar = $st->fetch(PDO::FETCH_ASSOC);
    if (!$radar) throw new RuntimeException('Documento não encontrado no RADAR NF-e.');

    $key = fiscal_digits($radar['access_key'] ?? '');
    if (strlen($key) !== 44) throw new RuntimeException('A nota não possui uma chave de acesso válida.');
    if ($eventType === 210240) {
      $justification = trim($justification);
      if (mb_strlen($justification) < 15 || mb_strlen($justification) > 255) {
        throw new RuntimeException('Na Operação não Realizada, informe uma justificativa entre 15 e 255 caracteres.');
      }
    } else {
      $justification = '';
    }

    $state = fiscal_dfe_state($pdo, $companyId);
    $provider = strtoupper(trim((string)($state['provider'] ?? 'NFEPHP_DIRECT')));
    if ($provider !== 'NFEPHP_DIRECT') {
      throw new RuntimeException('A manifestação direta desta versão requer o provedor NFePHP direto.');
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, '55');
    $response = (string)$tools->sefazManifesta($key, $eventType, $justification);
    $summary = fiscal_dfe_manifest_response_summary($response);
    if (!$summary['success']) {
      $pdo->prepare(
        'UPDATE fiscal_xml_radar SET manifest_status=?, manifest_event_type=?, manifest_response=?, '
        . 'error_message=?, updated_at=NOW() WHERE company_id=? AND id=?'
      )->execute([
        'ERRO_' . ($summary['code'] ?: 'SEM_RETORNO'),
        (string)$eventType,
        $response,
        $summary['message'],
        $companyId,
        $radarId,
      ]);
      throw new RuntimeException(
        'A manifestação não foi aceita. '
        . trim(($summary['code'] ?? '') . ' ' . ($summary['message'] ?? ''))
      );
    }

    $pdo->prepare(
      'UPDATE fiscal_xml_radar SET manifest_required=0, manifest_status=?, manifest_event_type=?, '
      . 'manifested_at=NOW(), manifest_response=?, error_message=NULL, updated_at=NOW() '
      . 'WHERE company_id=? AND id=?'
    )->execute([
      $allowed[$eventType],
      (string)$eventType,
      $response,
      $companyId,
      $radarId,
    ]);

    $fetch = ['documents_found'=>0,'xmls_stored'=>0,'summaries_stored'=>0,'status_code'=>'','status_message'=>''];
    try {
      $fetch = fiscal_dfe_fetch_by_key($pdo, $companyId, $key);
    } catch (Throwable $e) {
      // A manifestação pode ter sido aceita e o XML completo ainda não estar disponível.
      $fetch['status_message'] = $e->getMessage();
    }

    return [
      'manifest_code'=>$summary['code'],
      'manifest_message'=>$summary['message'],
      'manifest_status'=>$allowed[$eventType],
      'fetch'=>$fetch,
    ];
  }
}

/* -------------------------------------------------------------------------
 * Entradas/Saídas manuais e documentos referenciados
 * ------------------------------------------------------------------------- */
if (!function_exists('fiscal_ensure_document_reference_schema')) {
  function fiscal_ensure_document_reference_schema(PDO $pdo): void {
    try { if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
    // Evita DDL dentro de transações de emissão/lançamento fiscal.
    // CREATE/ALTER TABLE pode provocar commit implícito no MySQL/MariaDB.
    if ($pdo->inTransaction()) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_references (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      reference_type VARCHAR(20) NOT NULL DEFAULT 'NFE',
      reference_key VARCHAR(60) NULL,
      model VARCHAR(10) NULL,
      series VARCHAR(10) NULL,
      number BIGINT NULL,
      issue_month VARCHAR(7) NULL,
      document VARCHAR(30) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_doc_refs_doc (fiscal_document_id),
      KEY idx_fiscal_doc_refs_key (company_id, reference_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
      'reference_type'=>'VARCHAR(20) NOT NULL DEFAULT \'NFE\'',
      'reference_key'=>'VARCHAR(60) NULL',
      'model'=>'VARCHAR(10) NULL',
      'series'=>'VARCHAR(10) NULL',
      'number'=>'BIGINT NULL',
      'issue_month'=>'VARCHAR(7) NULL',
      'document'=>'VARCHAR(30) NULL'
    ] as $c=>$d) {
      if (function_exists('fiscal_add_column_if_missing')) fiscal_add_column_if_missing($pdo, 'fiscal_document_references', $c, $d);
    }
  }
}

if (!function_exists('fiscal_document_reference_rows')) {
  function fiscal_document_reference_rows(PDO $pdo, int $companyId, int $docId): array {
    fiscal_ensure_document_reference_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_document_references WHERE company_id=? AND fiscal_document_id=? ORDER BY id ASC');
    $st->execute([$companyId, $docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_reference_keys_from_text')) {
  function fiscal_reference_keys_from_text($text): array {
    $keys = [];
    foreach (preg_split('/[\s,;|]+/', (string)$text) as $piece) {
      $k = fiscal_digits($piece);
      if (strlen($k) === 44) $keys[$k] = $k;
    }
    return array_values($keys);
  }
}

if (!function_exists('fiscal_save_document_references')) {
  function fiscal_save_document_references(PDO $pdo, int $companyId, int $docId, array $keys): void {
    fiscal_ensure_document_reference_schema($pdo);
    $pdo->prepare('DELETE FROM fiscal_document_references WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $docId]);
    if (!$keys) return;
    $ins = $pdo->prepare('INSERT INTO fiscal_document_references (fiscal_document_id, company_id, reference_type, reference_key, model, series, number, issue_month, created_at) VALUES (?,?,?,?,?,?,?,?,NOW())');
    foreach ($keys as $key) {
      $key = fiscal_digits($key);
      if (strlen($key) !== 44) continue;
      $parts = fiscal_dfe_access_key_parts($key);
      $month = substr($key, 2, 2) . '/' . substr($key, 4, 2);
      $ins->execute([$docId, $companyId, 'NFE', $key, $parts['model'], $parts['series'], $parts['number'], $month]);
    }
  }
}

if (!function_exists('fiscal_finance_people_options')) {
  function fiscal_finance_people_options(PDO $pdo, int $companyId, string $role = 'customer', string $q = '', int $limit = 200): array {
    require_once __DIR__ . '/finance_module.php';
    try { finance_ensure_schema($pdo); } catch (Throwable $e) {}
    if (!fiscal_table_exists($pdo, 'finance_people')) return [];
    $where = ['company_id=?', 'active=1'];
    $params = [$companyId];
    if ($role === 'customer') $where[] = 'is_customer=1';
    elseif ($role === 'supplier') $where[] = 'is_supplier=1';
    $q = trim($q);
    if ($q !== '') {
      $like = nexa_search_like($q);
      $where[] = '(name LIKE ? OR fantasy_name LIKE ? OR document LIKE ?)';
      array_push($params, $like, $like, $like);
    }
    $sql = 'SELECT * FROM finance_people WHERE ' . implode(' AND ', $where) . ' ORDER BY name ASC, id ASC LIMIT ' . max(1, min(500, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_product_options_for_movement')) {
  function fiscal_product_options_for_movement(PDO $pdo, int $companyId, string $q = '', int $limit = 300): array {
    if (!fiscal_table_exists($pdo, 'produtos')) return [];
    $hasNumber = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
    $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasReference = fiscal_column_exists($pdo, 'produtos', 'referencia');
    $hasBarcode = fiscal_column_exists($pdo, 'produtos', 'codigo_barras');
    $hasUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
    $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
    $codeExpr = $hasNumber ? ($hasCode ? "COALESCE(NULLIF(TRIM(numero_cadastro),''),NULLIF(codigo_firebird,''),CAST(id AS CHAR))" : "COALESCE(NULLIF(TRIM(numero_cadastro),''),CAST(id AS CHAR))") : ($hasCode ? "COALESCE(NULLIF(codigo_firebird,''),CAST(id AS CHAR))" : 'CAST(id AS CHAR)');
    $barcodeExpr = $hasBarcode ? 'COALESCE(codigo_barras,\'\')' : "''";
    $unitExpr = $hasUnit ? "COALESCE(NULLIF(unidade_estoque,''),'UN')" : "'UN'";
    $costExpr = $hasCost ? 'COALESCE(custo,0)' : '0';
    $where = ['company_id=?']; $params = [$companyId];
    $q = trim($q);
    if ($q !== '') {
      $like = nexa_search_like($q);
      $parts = ['nome LIKE ?', 'CAST(id AS CHAR) LIKE ?'];
      $params[] = $like; $params[] = $like;
      if ($hasNumber) { $parts[] = 'CAST(numero_cadastro AS CHAR) LIKE ?'; $params[] = $like; }
      if ($hasCode) { $parts[] = 'codigo_firebird LIKE ?'; $params[] = $like; }
      if ($hasReference) { $parts[] = 'referencia LIKE ?'; $params[] = $like; }
      if ($hasBarcode) { $parts[] = 'codigo_barras LIKE ?'; $params[] = $like; }
      if (fiscal_column_exists($pdo, 'produtos', 'categoria')) { $parts[] = 'categoria LIKE ?'; $params[] = $like; }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $referenceExpr = $hasReference ? "COALESCE(referencia,'')" : "''";
    $sql = "SELECT id, {$codeExpr} AS code, {$barcodeExpr} AS barcode, {$referenceExpr} AS referencia, nome, preco, {$costExpr} AS custo, {$unitExpr} AS unidade FROM produtos WHERE " . implode(' AND ', $where) . ' ORDER BY nome ASC LIMIT ' . max(1, min(600, $limit));
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_person_payload_from_post')) {
  function fiscal_person_payload_from_post(array $post, string $prefix): array {
    return [
      'person_id' => (int)($post[$prefix . '_person_id'] ?? 0),
      'name' => trim((string)($post[$prefix . '_name'] ?? '')),
      'document' => fiscal_digits($post[$prefix . '_document'] ?? ''),
      'state_registration' => trim((string)($post[$prefix . '_ie'] ?? '')),
      'email' => trim((string)($post[$prefix . '_email'] ?? '')),
      'phone' => trim((string)($post[$prefix . '_phone'] ?? '')),
      'zip_code' => fiscal_digits($post[$prefix . '_zip'] ?? ''),
      'address' => trim((string)($post[$prefix . '_address'] ?? '')),
      'number' => trim((string)($post[$prefix . '_number'] ?? '')),
      'complement' => trim((string)($post[$prefix . '_complement'] ?? '')),
      'district' => trim((string)($post[$prefix . '_district'] ?? '')),
      'city' => trim((string)($post[$prefix . '_city'] ?? '')),
      'city_ibge' => fiscal_digits($post[$prefix . '_city_ibge'] ?? ''),
      'state' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($post[$prefix . '_state'] ?? '')), 0, 2)),
    ];
  }
}


if (!function_exists('fiscal_resolve_movement_cfop')) {
  /**
   * Resolve o CFOP de um item para movimentos manuais de entrada/saída.
   * Prioridade:
   * 1) CFOP específico do produto por operação (Operações e CFOPs do produto);
   * 2) CFOP herdado do perfil tributário para a operação;
   * 3) CFOP fiscal do cadastro do produto (venda/devolução/entrada);
   * 4) CFOP padrão da operação fiscal;
   * 5) fallback seguro conforme direção/UF.
   */
  function fiscal_resolve_movement_cfop(PDO $pdo, int $companyId, int $productId, array $operation, string $direction, array $participant = [], array $tax = [], string $emitUf = ''): string {
    $direction = strtoupper($direction) === 'SAIDA' ? 'SAIDA' : 'ENTRADA';
    $opId = (int)($operation['id'] ?? 0);
    $emitUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $emitUf), 0, 2));
    if ($emitUf === '' && !$pdo->inTransaction()) {
      try { $cfgCfop = fiscal_company_config($pdo, $companyId); $emitUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfgCfop['uf'] ?? '')), 0, 2)); } catch (Throwable $e) {}
    }
    $partUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($participant['state'] ?? '')), 0, 2));
    $sameUf = ($partUf === '' || $emitUf === '' || $partUf === $emitUf);
    $opField = $direction === 'ENTRADA'
      ? ($sameUf ? 'cfop_in_state' : 'cfop_in_interstate')
      : ($sameUf ? 'cfop_out_state' : 'cfop_out_interstate');
    $cfop = '';

    if ($productId > 0 && $opId > 0 && fiscal_table_exists($pdo, 'fiscal_product_operation_cfops')) {
      try {
        $st = $pdo->prepare("SELECT {$opField} FROM fiscal_product_operation_cfops WHERE company_id=? AND product_id=? AND operation_id=? LIMIT 1");
        $st->execute([$companyId, $productId, $opId]);
        $cfop = fiscal_digits((string)($st->fetchColumn() ?: ''));
      } catch (Throwable $e) {}
    }

    $taxProfileId = (int)($tax['tax_profile_id'] ?? 0);
    if ($cfop === '' && $taxProfileId > 0 && $opId > 0 && fiscal_table_exists($pdo, 'fiscal_tax_profile_operation_cfops')) {
      try {
        $st = $pdo->prepare("SELECT {$opField} FROM fiscal_tax_profile_operation_cfops WHERE company_id=? AND tax_profile_id=? AND operation_id=? LIMIT 1");
        $st->execute([$companyId, $taxProfileId, $opId]);
        $cfop = fiscal_digits((string)($st->fetchColumn() ?: ''));
      } catch (Throwable $e) {}
    }

    if ($cfop === '') {
      $opType = strtoupper(fiscal_sintegra_ascii((string)($operation['operation_type'] ?? '')));
      $opName = strtoupper(fiscal_sintegra_ascii((string)($operation['name'] ?? '')));
      $isReturn = str_contains($opType, 'DEVOL') || str_contains($opName, 'DEVOL');
      if ($isReturn) {
        $taxField = $sameUf ? 'cfop_return_state' : 'cfop_return_interstate';
      } elseif ($direction === 'ENTRADA') {
        $taxField = $sameUf ? 'cfop_entry_state' : 'cfop_entry_interstate';
      } else {
        $taxField = $sameUf ? 'cfop_sale_state' : 'cfop_sale_interstate';
      }
      $cfop = fiscal_digits((string)($tax[$taxField] ?? ''));
    }

    if ($cfop === '') $cfop = fiscal_digits((string)($operation[$opField] ?? ''));
    if ($cfop === '') {
      if ($direction === 'ENTRADA') $cfop = $sameUf ? '1202' : '2202';
      else $cfop = $sameUf ? '5102' : '6102';
    }
    $cfop = fiscal_cfop_normalize_direction($cfop, $direction, $sameUf);
    return substr($cfop, 0, 4);
  }
}

if (!function_exists('fiscal_create_manual_movement_document')) {
  function fiscal_create_manual_movement_document(PDO $pdo, int $companyId, array $post): int {
    fiscal_ensure_document_reference_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $env = in_array(($cfg['environment'] ?? ''), ['HOMOLOGACAO','PRODUCAO'], true) ? (string)$cfg['environment'] : 'HOMOLOGACAO';
    $direction = strtoupper((string)($post['direction'] ?? 'ENTRADA')) === 'SAIDA' ? 'SAIDA' : 'ENTRADA';
    $sourceType = preg_replace('/[^A-Z0-9_]+/', '_', strtoupper((string)($post['source_type'] ?? ($direction === 'ENTRADA' ? 'DEVOLUCAO_CLIENTE' : 'SAIDA_MANUAL'))));
    $internalOrderId = $sourceType === 'DEVOLUCAO_CLIENTE' ? (int)($post['source_order_id'] ?? 0) : 0;
    $isInternalOrderReturn = $internalOrderId > 0;
    $operationId = (int)($post['operation_id'] ?? 0);
    $operation = $operationId > 0 ? fiscal_operation($pdo, $companyId, $operationId) : null;
    if (!$operation) throw new RuntimeException('Selecione uma operação fiscal válida.');
    if (strtoupper((string)($operation['direction'] ?? '')) !== $direction) throw new RuntimeException('A operação selecionada não pertence ao tipo de movimento informado.');

    $participant = fiscal_person_payload_from_post($post, 'person');
    if ($participant['name'] === '') throw new RuntimeException('Informe o nome/razão social do cliente ou fornecedor.');
    if ($isInternalOrderReturn && (int)($participant['person_id'] ?? 0) <= 0) {
      throw new RuntimeException('A devolução interna de pedido exige um cliente cadastrado para controlar dinheiro, vale e histórico.');
    }
    if (!$isInternalOrderReturn && !in_array(strlen($participant['document']), [11,14], true)) throw new RuntimeException('Informe CPF/CNPJ completo do participante.');
    if ($sourceType === 'DEVOLUCAO_CLIENTE' && !$isInternalOrderReturn) {
      foreach (['address','number','district','city','state','zip_code'] as $need) {
        if (trim((string)($participant[$need] ?? '')) === '') throw new RuntimeException('Para devolução de cliente, informe endereço completo do cliente.');
      }
    }

    $productIds = (array)($post['product_id'] ?? []);
    $qtys = (array)($post['quantity'] ?? []);
    $units = (array)($post['unit_value'] ?? []);
    $cfops = (array)($post['cfop'] ?? []);
    $maxQtys = (array)($post['max_quantity'] ?? []);
    $sourceItemIds = (array)($post['source_item_id'] ?? []);
    $taxArray = static fn(string $name): array => (array)($post[$name] ?? []);
    $taxNames = [
      'icms_origin','icms_cst','icms_csosn','icms_base','icms_rate','icms_amount',
      'icms_st_base','icms_st_rate','icms_st_amount','icms_st_mva','icms_st_reduction',
      'fcp_st_base','fcp_st_rate','fcp_st_amount',
      'ipi_cst','ipi_cenq','ipi_base','ipi_rate','ipi_amount',
      'pis_cst','pis_base','pis_rate','pis_amount',
      'cofins_cst','cofins_base','cofins_rate','cofins_amount',
      'ibs_cbs_class','ibs_cbs_cst','ibs_cbs_base','ibs_uf_rate','ibs_mun_rate','cbs_rate',
    ];
    $taxInputs = [];
    foreach ($taxNames as $taxName) $taxInputs[$taxName] = $taxArray($taxName);
    $rawAt = static function(array $values, int $idx): string { return trim((string)($values[$idx] ?? '')); };
    $numAt = static function(array $values, int $idx): ?float {
      $raw = trim((string)($values[$idx] ?? ''));
      if ($raw === '') return null;
      return fiscal_xml_parse_decimal($raw, 0);
    };

    $sourceOriginalMap = [];
    if ($sourceType === 'DEVOLUCAO_CLIENTE') {
      $trustedSourceIds = array_values(array_unique(array_filter(array_map('intval', $sourceItemIds), static fn($v) => $v > 0)));
      if ($trustedSourceIds) {
        $sourcePh = implode(',', array_fill(0, count($trustedSourceIds), '?'));
        if ($isInternalOrderReturn) {
          $sourceSt = $pdo->prepare("SELECT pi.id, pi.produto_id AS product_id, pi.qtd AS quantity, pi.preco_unit AS unit_value, pi.total AS total_value,
                                            COALESCE((SELECT SUM(rdi.quantity)
                                                        FROM fiscal_document_items rdi
                                                        JOIN fiscal_documents rd ON rd.id=rdi.fiscal_document_id AND rd.company_id=rdi.company_id
                                                       WHERE rdi.company_id=? AND rdi.order_item_id=pi.id
                                                         AND rd.direction='ENTRADA' AND rd.model='00'
                                                         AND UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE'
                                                         AND rd.source_id=? AND UPPER(COALESCE(rd.status,''))<>'CANCELADA'),0) AS returned_quantity
                                       FROM pedido_itens pi
                                      WHERE pi.company_id=? AND pi.pedido_id=? AND pi.id IN ($sourcePh)");
          $sourceSt->execute(array_merge([$companyId, $internalOrderId, $companyId, $internalOrderId], $trustedSourceIds));
        } else {
          $sourceSt = $pdo->prepare("SELECT id, product_id, quantity, unit_value, total_value FROM fiscal_document_items WHERE company_id=? AND id IN ($sourcePh)");
          $sourceSt->execute(array_merge([$companyId], $trustedSourceIds));
        }
        foreach (($sourceSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $sourceRow) $sourceOriginalMap[(int)$sourceRow['id']] = $sourceRow;
      }
    }

    $items = [];
    foreach ($productIds as $idx => $pidRaw) {
      $pid = (int)$pidRaw;
      $qty = fiscal_xml_parse_decimal($qtys[$idx] ?? 0, 0);
      $unitValue = fiscal_xml_parse_decimal($units[$idx] ?? 0, 0);
      $maxQty = fiscal_xml_parse_decimal($maxQtys[$idx] ?? 0, 0);
      $sourceItemId = (int)($sourceItemIds[$idx] ?? 0);
      if ($sourceType === 'DEVOLUCAO_CLIENTE' && $qty <= 0) continue;
      if ($pid <= 0 && $qty <= 0 && $unitValue <= 0) continue;
      if ($pid <= 0) throw new RuntimeException('Selecione o produto em todas as linhas informadas.');
      if ($qty <= 0) throw new RuntimeException('Informe quantidade maior que zero.');
      if ($sourceType === 'DEVOLUCAO_CLIENTE' && $sourceItemId > 0) {
        $sourceOriginal = $sourceOriginalMap[$sourceItemId] ?? null;
        if (!$sourceOriginal) throw new RuntimeException('Não foi possível validar um item da venda original. Recarregue a origem e tente novamente.');
        $sourceProductId = (int)($sourceOriginal['product_id'] ?? 0);
        if ($sourceProductId > 0 && $pid !== $sourceProductId) throw new RuntimeException('O produto selecionado não corresponde ao item da venda original.');
        if ($isInternalOrderReturn) {
          $maxQty = max(0.0, (float)($sourceOriginal['quantity'] ?? 0) - (float)($sourceOriginal['returned_quantity'] ?? 0));
          $sourceUnit = (float)($sourceOriginal['unit_value'] ?? 0);
          if ($sourceUnit > 0) $unitValue = $sourceUnit;
          if ($maxQty <= 0) throw new RuntimeException('Este item do pedido já foi devolvido integralmente. Recarregue o pedido e confira os itens disponíveis.');
        } else {
          $maxQty = (float)($sourceOriginal['quantity'] ?? 0);
        }
      }
      if ($maxQty > 0 && $qty > ($maxQty + 0.0001)) throw new RuntimeException('A quantidade informada não pode ser maior que a quantidade disponível na venda original.');
      $taxPayload = [];
      foreach ($taxNames as $taxName) {
        $taxPayload[$taxName] = str_ends_with($taxName, '_cst') || in_array($taxName, ['icms_origin','icms_csosn','ipi_cenq','ibs_cbs_class'], true)
          ? $rawAt($taxInputs[$taxName], $idx)
          : $numAt($taxInputs[$taxName], $idx);
      }
      $items[] = ['product_id'=>$pid, 'quantity'=>$qty, 'unit_value'=>$unitValue, 'cfop'=>fiscal_digits($cfops[$idx] ?? ''), 'tax'=>$taxPayload, 'source_order_item_id'=>$isInternalOrderReturn ? $sourceItemId : 0];
    }
    if (!$items) throw new RuntimeException('Informe pelo menos um produto.');

    $series = null;
    $seriesValue = '';
    $number = 0;
    $issue = date('Y-m-d H:i:s');
    $accessKey = null;
    $docModel = $isInternalOrderReturn ? '00' : '55';
    $docStatus = $isInternalOrderReturn ? 'INTERNA' : 'PRE_NFE';
    if (!$isInternalOrderReturn) {
      $series = fiscal_nfce_active_series($pdo, $companyId, '55', $env);
      if (!$series) throw new RuntimeException('Cadastre uma série ativa para NF-e modelo 55 no ambiente atual.');
      $seriesValue = (string)$series['series'];
      $number = max(1, (int)($series['next_number'] ?? 1));
      $accessKey = fiscal_nfce_make_access_key($cfg, '55', $seriesValue, $number, $issue);
    } else {
      $orderNoSt = $pdo->prepare('SELECT COALESCE(numero_pedido,id) FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $orderNoSt->execute([$companyId, $internalOrderId]);
      $number = max(1, (int)($orderNoSt->fetchColumn() ?: $internalOrderId));
    }
    $nature = trim((string)($operation['name'] ?? '')) ?: ($direction === 'ENTRADA' ? 'ENTRADA DE MERCADORIA' : 'SAÍDA DE MERCADORIA');
    $refs = fiscal_reference_keys_from_text($post['reference_keys'] ?? '');
    if ($sourceType === 'DEVOLUCAO_CLIENTE' && !$isInternalOrderReturn && !$refs) throw new RuntimeException('Informe a chave da NF-e/NFC-e original referenciada na devolução.');
    $shouldUpdateStock = ((int)($operation['update_stock'] ?? 0) === 1);
    if ($shouldUpdateStock) {
      require_once __DIR__ . '/stock_module.php';
      if (function_exists('stock_ensure_schema')) stock_ensure_schema($pdo, true);
      if (!fiscal_column_exists($pdo, 'produtos', 'estoque_atual')) throw new RuntimeException('Não foi possível preparar o controle de estoque para esta operação.');
    }
    if (function_exists('fiscal_ensure_operation_cfop_schema') && !$pdo->inTransaction()) fiscal_ensure_operation_cfop_schema($pdo);

    $pdo->beginTransaction();
    try {
      if ($isInternalOrderReturn) {
        $orderLock = $pdo->prepare("SELECT id,numero_pedido,status,finance_person_id FROM pedidos WHERE company_id=? AND id=? FOR UPDATE");
        $orderLock->execute([$companyId, $internalOrderId]);
        $lockedOrder = $orderLock->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$lockedOrder) throw new RuntimeException('Pedido de origem não encontrado para a devolução interna.');
        if (!in_array(strtolower(trim((string)($lockedOrder['status'] ?? ''))), ['finalizado', 'aberto'], true)) throw new RuntimeException('Somente pedidos abertos ou finalizados podem ser usados em uma devolução interna.');
        $orderPersonId = (int)($lockedOrder['finance_person_id'] ?? 0);
        if ($orderPersonId > 0 && $orderPersonId !== (int)($participant['person_id'] ?? 0)) throw new RuntimeException('O cliente selecionado não corresponde ao cliente do pedido original.');
        $fiscalOriginSt = $pdo->prepare("SELECT id FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND model IN ('55','65') AND UPPER(COALESCE(source_type,''))='PEDIDO' AND source_id=? AND UPPER(COALESCE(status,''))<>'CANCELADA' LIMIT 1");
        $fiscalOriginSt->execute([$companyId, $internalOrderId]);
        if ($fiscalOriginSt->fetchColumn()) throw new RuntimeException('Este pedido já possui documento fiscal vinculado. Para esta venda, faça a devolução pela nota fiscal de origem.');

        $orderItemIds = array_values(array_unique(array_filter(array_map(static fn($it) => (int)($it['source_order_item_id'] ?? 0), $items), static fn($v) => $v > 0)));
        if (!$orderItemIds || count($orderItemIds) !== count($items)) throw new RuntimeException('Recarregue o pedido antes de concluir a devolução interna.');
        $orderItemPh = implode(',', array_fill(0, count($orderItemIds), '?'));
        $orderItemsSt = $pdo->prepare("SELECT id,produto_id AS product_id,qtd AS quantity,preco_unit AS unit_value,total AS total_value FROM pedido_itens WHERE company_id=? AND pedido_id=? AND id IN ($orderItemPh) FOR UPDATE");
        $orderItemsSt->execute(array_merge([$companyId, $internalOrderId], $orderItemIds));
        $lockedItems = [];
        foreach (($orderItemsSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $lockedItem) $lockedItems[(int)$lockedItem['id']] = $lockedItem;
        if (count($lockedItems) !== count($orderItemIds)) throw new RuntimeException('Um item do pedido original não está mais disponível. Recarregue o pedido.');

        $returnedByItem = [];
        $returnedSt = $pdo->prepare("SELECT rdi.order_item_id,SUM(rdi.quantity) AS returned_quantity
                                      FROM fiscal_document_items rdi
                                      JOIN fiscal_documents rd ON rd.id=rdi.fiscal_document_id AND rd.company_id=rdi.company_id
                                     WHERE rdi.company_id=? AND rdi.order_item_id IN ($orderItemPh)
                                       AND rd.direction='ENTRADA' AND rd.model='00'
                                       AND UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE'
                                       AND rd.source_id=? AND UPPER(COALESCE(rd.status,''))<>'CANCELADA'
                                  GROUP BY rdi.order_item_id");
        $returnedSt->execute(array_merge([$companyId], $orderItemIds, [$internalOrderId]));
        foreach (($returnedSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rr) $returnedByItem[(int)$rr['order_item_id']] = (float)($rr['returned_quantity'] ?? 0);
        foreach ($items as &$internalItem) {
          $orderItemId = (int)($internalItem['source_order_item_id'] ?? 0);
          $originItem = $lockedItems[$orderItemId] ?? null;
          if (!$originItem) throw new RuntimeException('Item de origem do pedido não encontrado.');
          if ((int)($originItem['product_id'] ?? 0) !== (int)($internalItem['product_id'] ?? 0)) throw new RuntimeException('O produto devolvido não corresponde ao item do pedido original.');
          $availableQty = max(0.0, (float)($originItem['quantity'] ?? 0) - (float)($returnedByItem[$orderItemId] ?? 0));
          if ((float)$internalItem['quantity'] > $availableQty + 0.0001) throw new RuntimeException('A quantidade devolvida é maior que o saldo ainda disponível no pedido original. Recarregue o pedido.');
          $originUnit = (float)($originItem['unit_value'] ?? 0);
          if ($originUnit > 0) $internalItem['unit_value'] = $originUnit;
        }
        unset($internalItem);
      } elseif ($series) {
        $pdo->prepare('UPDATE fiscal_document_series SET last_number=?, next_number=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$number, $number + 1, $companyId, (int)$series['id']]);
      }
      $participantPersonId = (int)($participant['person_id'] ?? 0);
      $customerPersonId = ($direction === 'ENTRADA' && $participantPersonId > 0) ? $participantPersonId : null;
      $supplierPersonId = ($direction === 'SAIDA' && $participantPersonId > 0) ? $participantPersonId : null;
      $financeGenerated = (int)($operation['create_finance'] ?? 0) ? 0 : 1;
      $stockGenerated = (int)($operation['update_stock'] ?? 0) ? 0 : 1;
      $pdo->prepare("INSERT INTO fiscal_documents (company_id, direction, model, series, number, access_key, status, environment, operation_nature_id, operation_nature, issue_datetime, exit_entry_datetime, competence_date, customer_person_id, supplier_person_id, total_products, total_document, source_type, source_id, finance_generated, stock_generated, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$companyId, $direction, $docModel, $seriesValue !== '' ? $seriesValue : null, $number, $accessKey, $docStatus, $env, $operationId, $nature, $issue, $issue, substr($issue,0,10), $customerPersonId, $supplierPersonId, 0, 0, $sourceType, $isInternalOrderReturn ? $internalOrderId : null, $financeGenerated, $stockGenerated, trim((string)($post['notes'] ?? ''))]);
      $docId = (int)$pdo->lastInsertId();
      $part = $pdo->prepare('INSERT INTO fiscal_document_participants (fiscal_document_id, company_id, role, person_id, document, state_registration, name, email, phone, zip_code, address, number, complement, district, city, city_ibge, state, country_code, country_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $part->execute([$docId, $companyId, 'EMITENTE', null, fiscal_digits($cfg['company_cnpj'] ?? ''), (string)($cfg['state_registration'] ?? ''), fiscal_nfce_company_display_name($pdo, $companyId, $cfg), '', '', '', '', '', '', '', '', fiscal_digits($cfg['city_ibge'] ?? ''), (string)($cfg['uf'] ?? 'MG'), '1058', 'BRASIL']);
      $part->execute([$docId, $companyId, 'DESTINATARIO', $participant['person_id'] ?: null, $participant['document'], $participant['state_registration'], $participant['name'], $participant['email'], $participant['phone'], $participant['zip_code'], $participant['address'], $participant['number'], $participant['complement'], $participant['district'], $participant['city'], $participant['city_ibge'], $participant['state'], '1058', 'BRASIL']);
      $insItem = $pdo->prepare('INSERT INTO fiscal_document_items (fiscal_document_id, company_id, item_number, product_id, product_code, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, unidade_trib, quantity_trib, unit_value_trib, ind_total, order_item_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $insTax = $pdo->prepare('INSERT INTO fiscal_document_item_taxes (fiscal_document_item_id, fiscal_document_id, company_id, tax_group, tax_tag, origin_code, cst, csosn, class_code, base_calc, base_calc_st, rate, reduction_rate, mva_rate, amount, extra_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $totalProducts = 0.0; $n = 0; $totIcms = 0.0; $totIcmsSt = 0.0; $totFcpSt = 0.0; $totIpi = 0.0; $totPis = 0.0; $totCofins = 0.0;
      $totIbsUf = 0.0; $totIbsMun = 0.0; $totCbs = 0.0;
      foreach ($items as $it) {
        $n++; $pid = (int)$it['product_id']; $manual = (array)($it['tax'] ?? []);
        $ps = $pdo->prepare('SELECT * FROM produtos WHERE company_id=? AND id=? LIMIT 1'); $ps->execute([$companyId, $pid]); $prod = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
        if (!$prod) throw new RuntimeException('Produto não encontrado: #' . $pid);
        $tax = fiscal_product_tax($pdo, $companyId, $pid);
        $qty = (float)$it['quantity']; $unit = (float)$it['unit_value']; if ($unit <= 0) $unit = (float)($prod['preco'] ?? $prod['custo'] ?? 0);
        $line = round($qty * $unit, 2); $totalProducts += $line;
        $cfop = fiscal_digits((string)$it['cfop']);
        if ($cfop === '' && function_exists('fiscal_resolve_movement_cfop')) $cfop = fiscal_resolve_movement_cfop($pdo, $companyId, $pid, $operation, $direction, $participant, $tax, (string)($cfg['uf'] ?? ''));
        if ($cfop === '') $cfop = $direction === 'ENTRADA' ? ((string)($operation['cfop_in_state'] ?? '1202') ?: '1202') : ((string)($operation['cfop_out_state'] ?? '5202') ?: '5202');
        $companyUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? '')), 0, 2));
        $participantUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($participant['state'] ?? '')), 0, 2));
        $sameUfMovement = $participantUf === '' || $companyUf === '' || $participantUf === $companyUf;
        $cfop = fiscal_cfop_normalize_direction($cfop, $direction, $sameUfMovement);
        $code = trim((string)($prod['numero_cadastro'] ?? '')); if ($code === '' || $code === '0') $code = trim((string)($prod['codigo_firebird'] ?? '')) ?: (string)$pid; $unitName = trim((string)($prod['unidade_estoque'] ?? 'UN')) ?: 'UN';
        $insItem->execute([$docId, $companyId, $n, $pid, $code, (string)($prod['nome'] ?? 'Produto'), preg_replace('/\D+/', '', (string)($tax['ncm'] ?? '')), preg_replace('/\D+/', '', (string)($tax['cest'] ?? '')), fiscal_digits($cfop), $unitName, $qty, $unit, $line, $unitName, $qty, $unit, '1', $isInternalOrderReturn ? (int)($it['source_order_item_id'] ?? 0) : null]);
        $docItemId = (int)$pdo->lastInsertId();
        $num = static fn(string $key, float $fallback=0.0): float => $manual[$key] === null ? $fallback : max(0.0, (float)$manual[$key]);
        $codeValue = static fn(string $key, string $fallback=''): string => trim((string)($manual[$key] ?? '')) !== '' ? preg_replace('/\D+/', '', (string)$manual[$key]) : $fallback;
        $origin = $codeValue('icms_origin', preg_replace('/\D+/', '', (string)($tax['origem'] ?? '0')) ?: '0');
        $crt = preg_replace('/\D+/', '', (string)($cfg['crt'] ?? '1')) ?: '1';
        $icmsCst = $codeValue('icms_cst', preg_replace('/\D+/', '', (string)($tax['icms_cst'] ?? '00')) ?: '00');
        $csosn = $codeValue('icms_csosn', preg_replace('/\D+/', '', (string)($tax['icms_csosn'] ?? '102')) ?: '102');
        $icmsRate = $num('icms_rate', max(0.0,(float)($tax['icms_rate'] ?? 0))); $icmsBase = $num('icms_base', $icmsRate > 0 ? $line : 0.0); $icmsAmount = $num('icms_amount', round($icmsBase*$icmsRate/100,2));
        $icmsTag = fiscal_nfce_icms_xml_tag($crt, $icmsCst, $crt === '1' ? $csosn : '');
        $allowsOwnIcms = $crt !== '1' || $icmsTag === 'ICMSSN900';
        if (!$allowsOwnIcms) { $icmsBase = 0.0; $icmsRate = 0.0; $icmsAmount = 0.0; }
        $stBase=$num('icms_st_base'); $stRate=$num('icms_st_rate'); $stAmount=$num('icms_st_amount',round($stBase*$stRate/100,2)); $stMva=$num('icms_st_mva'); $stRed=$num('icms_st_reduction');
        $fcpBase=$num('fcp_st_base'); $fcpRate=$num('fcp_st_rate'); $fcpAmount=$num('fcp_st_amount',round($fcpBase*$fcpRate/100,2));
        $extra = array_filter(['st_base'=>$stBase,'st_rate'=>$stRate,'st_amount'=>$stAmount,'st_mva'=>$stMva,'st_reduction_rate'=>$stRed,'fcp_st_base'=>$fcpBase,'fcp_st_rate'=>$fcpRate,'fcp_st_amount'=>$fcpAmount], static fn($v)=>(float)$v>0);
        $totIcms += $icmsAmount; $totIcmsSt += $stAmount; $totFcpSt += $fcpAmount;
        $insTax->execute([$docItemId,$docId,$companyId,'ICMS',$icmsTag,$origin,$crt==='1'?'':$icmsCst,$crt==='1'?$csosn:'','',$icmsBase,$stBase,$icmsRate,$stRed,$stMva,$icmsAmount,$extra?json_encode($extra,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES):null]);
        $ipiCstRaw=$codeValue('ipi_cst',preg_replace('/\D+/', '', (string)($tax['ipi_cst'] ?? ''))); $ipiCst=$ipiCstRaw!==''?str_pad(substr($ipiCstRaw,0,2),2,'0',STR_PAD_LEFT):''; $ipiCenq=substr($codeValue('ipi_cenq','999')?:'999',0,3); $ipiRate=$num('ipi_rate',max(0.0,(float)($tax['ipi_rate']??0))); $ipiBase=$num('ipi_base',$ipiRate>0?$line:0); $ipiAmount=$num('ipi_amount',round($ipiBase*$ipiRate/100,2));
        if ($ipiCst !== '' || $ipiBase>0 || $ipiRate>0 || $ipiAmount>0) {
          if ($ipiCst === '') $ipiCst = $direction === 'SAIDA' ? ($ipiAmount > 0 ? '50' : '53') : ($ipiAmount > 0 ? '00' : '03');
          $ipiTag = fiscal_nfce_ipi_xml_tag($ipiCst);
          if ($ipiTag === 'IPINT') { $ipiBase = 0.0; $ipiRate = 0.0; $ipiAmount = 0.0; }
          $totIpi += $ipiAmount;
          $ipiExtra = json_encode(['cEnq'=>$ipiCenq], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $insTax->execute([$docItemId,$docId,$companyId,'IPI',$ipiTag,'',$ipiCst,'',$ipiCenq,$ipiBase,0,$ipiRate,0,0,$ipiAmount,$ipiExtra]);
        }
        $pisCst=str_pad($codeValue('pis_cst',preg_replace('/\D+/', '', (string)($tax['pis_cst']??'01'))?:'01'),2,'0',STR_PAD_LEFT); $pisRate=$num('pis_rate',max(0.0,(float)($tax['pis_rate']??0))); $pisBase=$num('pis_base',$pisRate>0?$line:0); $pisAmount=$num('pis_amount',round($pisBase*$pisRate/100,2)); $totPis += $pisAmount;
        $insTax->execute([$docItemId,$docId,$companyId,'PIS',in_array($pisCst,['01','02'],true)?'PISAliq':(in_array($pisCst,['04','05','06','07','08','09'],true)?'PISNT':'PISOutr'),'',$pisCst,'','',$pisBase,0,$pisRate,0,0,$pisAmount,null]);
        $cofCst=str_pad($codeValue('cofins_cst',preg_replace('/\D+/', '', (string)($tax['cofins_cst']??'01'))?:'01'),2,'0',STR_PAD_LEFT); $cofRate=$num('cofins_rate',max(0.0,(float)($tax['cofins_rate']??0))); $cofBase=$num('cofins_base',$cofRate>0?$line:0); $cofAmount=$num('cofins_amount',round($cofBase*$cofRate/100,2)); $totCofins += $cofAmount;
        $insTax->execute([$docItemId,$docId,$companyId,'COFINS',in_array($cofCst,['01','02'],true)?'COFINSAliq':(in_array($cofCst,['04','05','06','07','08','09'],true)?'COFINSNT':'COFINSOutr'),'',$cofCst,'','',$cofBase,0,$cofRate,0,0,$cofAmount,null]);
        // IBS/CBS (Reforma Tributária): a NF-e exige o grupo em todos os itens.
        // Usa o que foi informado em "Impostos do item"; o que ficar em branco vem
        // do cadastro fiscal do produto (mesma regra das notas de pedidos).
        if (!$isInternalOrderReturn && function_exists('fiscal_ibs_cbs_resolve')) {
          $itemName = trim((string)($prod['nome'] ?? '')) ?: ('Produto #' . $pid);
          $ibsTax = $tax;
          $manualClass = substr(preg_replace('/\D+/', '', (string)($manual['ibs_cbs_class'] ?? '')) ?: '', 0, 6);
          if ($manualClass !== '') {
            $ibsTax['ibs_cbs_classification_code'] = $manualClass;
            if (fiscal_ibs_cbs_rate_mode($ibsTax) === 'DESATIVADO') $ibsTax['ibs_cbs_rate_mode'] = 'AUTO';
          }
          $manualIbsRates = array_filter(['ibs_uf_rate'=>$manual['ibs_uf_rate'] ?? null, 'ibs_mun_rate'=>$manual['ibs_mun_rate'] ?? null, 'cbs_rate'=>$manual['cbs_rate'] ?? null], static fn($v) => $v !== null);
          if ($manualIbsRates) {
            $ibsTax['ibs_cbs_rate_mode'] = 'MANUAL';
            foreach (['ibs_uf_rate','ibs_mun_rate','cbs_rate'] as $rateKey) $ibsTax[$rateKey] = max(0.0, (float)($manual[$rateKey] ?? 0));
          }
          $ibsOpUf = trim((string)($participant['state'] ?? '')) ?: (string)($cfg['uf'] ?? '');
          $ibsOpCity = fiscal_digits((string)($participant['city_ibge'] ?? '')) ?: fiscal_digits((string)($cfg['city_ibge'] ?? ''));
          $ibsResolved = fiscal_ibs_cbs_resolve($pdo, $companyId, $ibsTax, $cfg, '55', $issue, $ibsOpUf, $ibsOpCity);
          if (empty($ibsResolved['enabled'])) {
            throw new RuntimeException('IBS/CBS do item ' . $n . ' (' . $itemName . '): ' . trim((string)($ibsResolved['reason'] ?? 'configuração incompleta.'))
              . ' Abra "Impostos do item" e informe o cClassTrib (ex.: 000001) ou cadastre o cClassTrib no produto.');
          }
          $manualIbsCst = preg_replace('/\D+/', '', (string)($manual['ibs_cbs_cst'] ?? '')) ?: '';
          if ($manualIbsCst !== '' && str_pad(substr($manualIbsCst, 0, 3), 3, '0', STR_PAD_LEFT) !== (string)$ibsResolved['cst']) {
            throw new RuntimeException('IBS/CBS do item ' . $n . ' (' . $itemName . '): o CST ' . $manualIbsCst . ' não corresponde ao cClassTrib ' . $ibsResolved['class_code']
              . ' (CST ' . $ibsResolved['cst'] . '). Deixe o CST em branco para usar o do cClassTrib.');
          }
          $manualIbsBase = $manual['ibs_cbs_base'] ?? null;
          $ibsCalc = $manualIbsBase !== null
            ? fiscal_ibs_cbs_calculate((float)$manualIbsBase, $ibsResolved, [])
            : fiscal_ibs_cbs_calculate($line, $ibsResolved, ['icms'=>$icmsAmount, 'pis'=>$pisAmount, 'cofins'=>$cofAmount, 'ipi'=>0, 'iss'=>0]);
          $ibsBase = (float)$ibsCalc['base'];
          $totIbsUf += (float)$ibsCalc['ibs_uf_amount'];
          $totIbsMun += (float)$ibsCalc['ibs_mun_amount'];
          $totCbs += (float)$ibsCalc['cbs_amount'];
          $ibsExtra = json_encode([
            'cClassTrib' => (string)$ibsResolved['class_code'],
            'CST' => (string)$ibsResolved['cst'],
            'rate_mode' => (string)$ibsResolved['mode'],
            'rate_source' => (string)$ibsResolved['source'],
            'rate_date' => (string)$ibsResolved['date'],
            'operation_uf' => (string)$ibsResolved['uf'],
            'operation_city_ibge' => (string)$ibsResolved['city_ibge'],
            'base_calc_gross' => $manualIbsBase !== null ? (float)$manualIbsBase : $line,
            'excluded_legacy_taxes' => (float)$ibsCalc['excluded_total'],
            'base_calc' => $ibsBase,
            'ibs_reduction_rate' => (float)$ibsCalc['ibs_reduction_rate'],
            'cbs_reduction_rate' => (float)$ibsCalc['cbs_reduction_rate'],
            'ibs_uf_rate' => (float)$ibsCalc['ibs_uf_rate'],
            'ibs_uf_effective_rate' => (float)$ibsCalc['ibs_uf_effective_rate'],
            'ibs_uf_trib_op' => (float)$ibsCalc['ibs_uf_trib_op'],
            'ibs_uf_amount' => (float)$ibsCalc['ibs_uf_amount'],
            'ibs_mun_rate' => (float)$ibsCalc['ibs_mun_rate'],
            'ibs_mun_effective_rate' => (float)$ibsCalc['ibs_mun_effective_rate'],
            'ibs_mun_trib_op' => (float)$ibsCalc['ibs_mun_trib_op'],
            'ibs_mun_amount' => (float)$ibsCalc['ibs_mun_amount'],
            'ibs_amount' => (float)$ibsCalc['ibs_amount'],
            'cbs_rate' => (float)$ibsCalc['cbs_rate'],
            'cbs_effective_rate' => (float)$ibsCalc['cbs_effective_rate'],
            'cbs_trib_op' => (float)$ibsCalc['cbs_trib_op'],
            'cbs_amount' => (float)$ibsCalc['cbs_amount'],
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $insTax->execute([$docItemId,$docId,$companyId,'IBSCBS','IBSCBS','',(string)$ibsResolved['cst'],'',(string)$ibsResolved['class_code'],$ibsBase,0,
            (float)$ibsCalc['ibs_uf_rate'] + (float)$ibsCalc['ibs_mun_rate'] + (float)$ibsCalc['cbs_rate'],0,0,(float)$ibsCalc['ibs_amount'] + (float)$ibsCalc['cbs_amount'],$ibsExtra]);
        }
      }
      $totalDocument = round($totalProducts + $totIpi + $totIcmsSt + $totFcpSt, 2);
      $pdo->prepare('UPDATE fiscal_documents SET total_products=?, total_document=?, total_icms=?, total_icms_st=?, total_ipi=?, total_pis=?, total_cofins=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([round($totalProducts,2),$totalDocument,round($totIcms,2),round($totIcmsSt,2),round($totIpi,2),round($totPis,2),round($totCofins,2),$companyId,$docId]);
      if (($totIbsUf + $totIbsMun + $totCbs) > 0 && fiscal_column_exists($pdo, 'fiscal_documents', 'total_cbs')) {
        $pdo->prepare('UPDATE fiscal_documents SET total_ibs_uf=?, total_ibs_mun=?, total_cbs=? WHERE company_id=? AND id=?')->execute([round($totIbsUf,2), round($totIbsMun,2), round($totCbs,2), $companyId, $docId]);
      }
      if ($isInternalOrderReturn) {
        $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, status_code, status_message, request_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,NOW())')->execute([$docId, $companyId, 'DEVOLUCAO_INTERNA_PEDIDO', 1, 'INTERNA', 'Devolução interna registrada a partir de pedido sem documento fiscal.', null, 'Controle interno: sem chave de referência e sem transmissão para a SEFAZ.']);
      } else {
        fiscal_save_document_references($pdo, $companyId, $docId, $refs);
        $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
        $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$xml, hash('sha256', $xml), $companyId, $docId]);
        $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, status_code, status_message, request_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,NOW())')->execute([$docId, $companyId, $direction === 'ENTRADA' ? 'PRE_NFE_ENTRADA' : 'PRE_NFE_SAIDA', 1, 'PRE', 'Documento fiscal manual gerado para conferência/emissão.', $xml, 'Lançamento criado pelas telas de entradas/saídas.']);
      }
      if ($shouldUpdateStock) fiscal_stock_apply_document_movement($pdo, $companyId, $docId);
      if ($sourceType === 'DEVOLUCAO_CLIENTE') {
        require_once __DIR__ . '/voucher_module.php';
        $refundMode = strtoupper(trim((string)($post['refund_mode'] ?? '')));
        $refundAmount = round($totalProducts, 2);
        $refundUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
        voucher_settle_customer_return($pdo, $companyId, $docId, $participantPersonId, $refundAmount, $refundMode, $refundUserId, trim((string)($post['notes'] ?? '')));
      }
      if ($pdo->inTransaction()) $pdo->commit();
      return $docId;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('fiscal_stock_apply_document_movement')) {
  function fiscal_stock_apply_document_movement(PDO $pdo, int $companyId, int $docId): void {
    require_once __DIR__ . '/stock_module.php';
    $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? FOR UPDATE');
    $st->execute([$companyId, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado para movimentar estoque.');
    if ((int)($doc['stock_generated'] ?? 0) === 1) return;
    $operationId = (int)($doc['operation_nature_id'] ?? 0);
    $operation = $operationId > 0 ? fiscal_operation($pdo, $companyId, $operationId) : null;
    if (!$operation || (int)($operation['update_stock'] ?? 0) !== 1) {
      $pdo->prepare('UPDATE fiscal_documents SET stock_generated=1, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $docId]);
      return;
    }
    $direction = strtoupper((string)($doc['direction'] ?? 'SAIDA')) === 'ENTRADA' ? 'ENTRADA' : 'SAIDA';
    $items = $pdo->prepare('SELECT id, product_id, quantity, description FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=? ORDER BY item_number, id');
    $items->execute([$companyId, $docId]);
    $rows = $items->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) throw new RuntimeException('Documento fiscal sem itens para movimentar estoque.');
    if (function_exists('stock_ensure_schema')) stock_ensure_schema($pdo, true);
    $selStock = $pdo->prepare('SELECT estoque_atual FROM produtos WHERE company_id=? AND id=? FOR UPDATE');
    $updStock = $pdo->prepare('UPDATE produtos SET estoque_atual=? WHERE company_id=? AND id=?');
    $movStock = null;
    if (fiscal_table_exists($pdo, 'estoque_movimentos')) {
      try { $movStock = $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'); } catch (Throwable $e) { $movStock = null; }
    }
    foreach ($rows as $it) {
      $productId = (int)($it['product_id'] ?? 0);
      $qty = round((float)($it['quantity'] ?? 0), 4);
      if ($productId <= 0 || $qty <= 0) continue;
      $selStock->execute([$companyId, $productId]);
      $current = $selStock->fetchColumn();
      if ($current === false) throw new RuntimeException('Produto não encontrado para movimentar estoque: #' . $productId);
      $before = (float)$current;
      $signedQty = $direction === 'ENTRADA' ? $qty : -$qty;
      $after = round($before + $signedQty, 4);
      $updStock->execute([$after, $companyId, $productId]);
      if ($movStock) {
        $tipo = $direction === 'ENTRADA' ? 'entrada' : 'saida';
        $isInternalReturn = (string)($doc['model'] ?? '') === '00' && strtoupper((string)($doc['source_type'] ?? '')) === 'DEVOLUCAO_CLIENTE';
        $movementLabel = $isInternalReturn ? 'Devolução interna de cliente' : ($direction === 'ENTRADA' ? 'Entrada fiscal' : 'Saída fiscal');
        $obs = $movementLabel . ' - doc #' . $docId . ' - ' . (string)($doc['operation_nature'] ?? '') . ' - item ' . (string)($it['id'] ?? '');
        $movStock->execute([$companyId, $productId, $tipo, 'FISCAL_DOCUMENTO', $docId, abs($signedQty), $before, $after, $obs]);
      }
    }
    if (function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
      product_composition_recalculate_all($pdo, $companyId, 'Movimento fiscal #' . $docId);
    }
    $pdo->prepare('UPDATE fiscal_documents SET stock_generated=1, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $docId]);
  }
}

// -----------------------------------------------------------------------------
// NFC-e/NF-e - estrutura base e pré-emissão em homologação.
// Nesta etapa o sistema gera o documento fiscal interno e o XML preliminar.
// A assinatura/transmissão fica encapsulada para integração com NFePHP/sped-nfe.
// -----------------------------------------------------------------------------

if (!function_exists('fiscal_nfce_ensure_schema')) {
  function fiscal_nfce_ensure_schema(PDO $pdo): void {
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    // Evita DDL durante transações de geração da NFC-e.
    // CREATE/ALTER TABLE pode dar commit implícito e quebrar o fluxo.
    if ($pdo->inTransaction()) return;
    // Desempenho: conferência completa no máximo a cada 15 min (ver schema_runtime.php).
    if (function_exists('nexa_schema_ensure_recent') && nexa_schema_ensure_recent($pdo, 'fiscal_nfce', __FILE__)) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_company_config (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      company_name VARCHAR(180) NULL,
      company_trade_name VARCHAR(180) NULL,
      company_cnpj VARCHAR(14) NULL,
      state_registration VARCHAR(30) NULL,
      municipal_registration VARCHAR(30) NULL,
      uf CHAR(2) NULL,
      city_ibge VARCHAR(7) NULL,
      company_city VARCHAR(120) NULL,
      crt VARCHAR(3) NULL,
      regime_name VARCHAR(80) NULL,
      company_address VARCHAR(180) NULL,
      company_number VARCHAR(40) NULL,
      company_complement VARCHAR(120) NULL,
      company_district VARCHAR(120) NULL,
      company_zip VARCHAR(20) NULL,
      company_contact VARCHAR(120) NULL,
      company_phone VARCHAR(20) NULL,
      company_fax VARCHAR(20) NULL,
      nfe_enabled TINYINT(1) NOT NULL DEFAULT 0,
      nfce_enabled TINYINT(1) NOT NULL DEFAULT 0,
      nfse_enabled TINYINT(1) NOT NULL DEFAULT 0,
      fiscal_emission_enabled TINYINT(1) NOT NULL DEFAULT 1,
      save_order_enabled TINYINT(1) NOT NULL DEFAULT 1,
      order_auto_print_enabled TINYINT(1) NOT NULL DEFAULT 1,
      order_print_disabled TINYINT(1) NOT NULL DEFAULT 0,
      print_pack_58mm_enabled TINYINT(1) NOT NULL DEFAULT 0,
      contingency_enabled TINYINT(1) NOT NULL DEFAULT 0,
      nfce_offline_contingency_enabled TINYINT(1) NOT NULL DEFAULT 1,
      nfe_svc_contingency_enabled TINYINT(1) NOT NULL DEFAULT 1,
      nfe_svc_type VARCHAR(20) NOT NULL DEFAULT 'AUTO',
      contingency_login_alert_enabled TINYINT(1) NOT NULL DEFAULT 1,
      contingency_status_check_enabled TINYINT(1) NOT NULL DEFAULT 1,
      contingency_reason VARCHAR(255) NULL,
      emission_adapter VARCHAR(30) NOT NULL DEFAULT 'NFEPHP',
      acbr_mode VARCHAR(30) NOT NULL DEFAULT 'ACBRMONITOR',
      acbr_host VARCHAR(120) NULL,
      acbr_port INT NULL,
      xml_out_path VARCHAR(255) NULL,
      xml_in_path VARCHAR(255) NULL,
      danfe_path VARCHAR(255) NULL,
      nfce_csc_id VARCHAR(20) NULL,
      nfce_csc_token VARCHAR(255) NULL,
      nfce_qrcode_version VARCHAR(10) NULL,
      nfe_nfce_referenced_cfop_internal VARCHAR(4) NULL,
      nfe_nfce_referenced_cfop_external VARCHAR(4) NULL,
      nfse_provider VARCHAR(80) NULL,
      nfse_national_standard TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_company_config_company (company_id),
      KEY idx_fiscal_company_config_env (company_id, environment)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $cfgCols = [
      'company_name' => 'VARCHAR(180) NULL', 'company_trade_name' => 'VARCHAR(180) NULL', 'company_cnpj' => 'VARCHAR(14) NULL', 'state_registration' => 'VARCHAR(30) NULL', 'municipal_registration' => 'VARCHAR(30) NULL', 'environment' => "VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO'",
      'uf' => 'CHAR(2) NULL', 'city_ibge' => 'VARCHAR(7) NULL', 'company_city' => 'VARCHAR(120) NULL', 'crt' => 'VARCHAR(3) NULL', 'regime_name' => 'VARCHAR(80) NULL',
      'company_address' => 'VARCHAR(180) NULL', 'company_number' => 'VARCHAR(40) NULL', 'company_complement' => 'VARCHAR(120) NULL', 'company_district' => 'VARCHAR(120) NULL', 'company_zip' => 'VARCHAR(20) NULL',
      'company_contact' => 'VARCHAR(120) NULL', 'company_phone' => 'VARCHAR(20) NULL', 'company_fax' => 'VARCHAR(20) NULL',
      'nfe_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'nfce_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'nfse_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'fiscal_emission_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'save_order_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'order_auto_print_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'order_print_disabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'print_pack_58mm_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0',
      'contingency_enabled' => 'TINYINT(1) NOT NULL DEFAULT 0', 'nfce_offline_contingency_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'nfe_svc_contingency_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1',
      'nfe_svc_type' => "VARCHAR(20) NOT NULL DEFAULT 'AUTO'", 'contingency_login_alert_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'contingency_status_check_enabled' => 'TINYINT(1) NOT NULL DEFAULT 1', 'contingency_reason' => 'VARCHAR(255) NULL',
      'emission_adapter' => "VARCHAR(30) NOT NULL DEFAULT 'NFEPHP'", 'acbr_mode' => "VARCHAR(30) NOT NULL DEFAULT 'ACBRMONITOR'", 'acbr_host' => 'VARCHAR(120) NULL', 'acbr_port' => 'INT NULL',
      'xml_out_path' => 'VARCHAR(255) NULL', 'xml_in_path' => 'VARCHAR(255) NULL', 'danfe_path' => 'VARCHAR(255) NULL',
      'nfce_csc_id' => 'VARCHAR(20) NULL', 'nfce_csc_token' => 'VARCHAR(255) NULL', 'nfce_qrcode_version' => 'VARCHAR(10) NULL',
      'nfe_nfce_referenced_cfop_internal' => 'VARCHAR(4) NULL', 'nfe_nfce_referenced_cfop_external' => 'VARCHAR(4) NULL',
      'nfse_provider' => 'VARCHAR(80) NULL', 'nfse_national_standard' => 'TINYINT(1) NOT NULL DEFAULT 1', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($cfgCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_company_config', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_series (
      id INT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      model VARCHAR(10) NOT NULL,
      series VARCHAR(10) NOT NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      next_number BIGINT NOT NULL DEFAULT 1,
      last_number BIGINT NOT NULL DEFAULT 0,
      emission_type VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
      is_active TINYINT(1) NOT NULL DEFAULT 1,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_series_company_model (company_id, model, series, environment),
      KEY idx_fiscal_series_active (company_id, model, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    if (fiscal_table_exists($pdo, 'users')) {
      fiscal_add_column_if_missing($pdo, 'users', 'default_nfe_series_id', 'INT NULL');
      fiscal_add_column_if_missing($pdo, 'users', 'default_nfce_series_id', 'INT NULL');
      fiscal_add_column_if_missing($pdo, 'users', 'default_service_series_id', 'INT NULL');
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_documents (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      direction VARCHAR(10) NOT NULL DEFAULT 'SAIDA',
      model VARCHAR(10) NOT NULL,
      series VARCHAR(10) NULL,
      number BIGINT NULL,
      access_key VARCHAR(60) NULL,
      protocol_number VARCHAR(60) NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'DIGITACAO',
      emission_type VARCHAR(20) NOT NULL DEFAULT 'NORMAL',
      contingency_started_at DATETIME NULL,
      contingency_reason VARCHAR(255) NULL,
      contingency_original_doc_id BIGINT NULL,
      contingency_transmission_attempts INT NOT NULL DEFAULT 0,
      contingency_last_attempt_at DATETIME NULL,
      sefaz_status_code VARCHAR(10) NULL,
      sefaz_status_message VARCHAR(255) NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      operation_nature_id INT NULL,
      operation_nature VARCHAR(180) NULL,
      issue_datetime DATETIME NULL,
      operation_datetime DATETIME NULL,
      authorization_datetime DATETIME NULL,
      exit_entry_datetime DATETIME NULL,
      competence_date DATE NULL,
      issuer_person_id INT NULL,
      recipient_person_id INT NULL,
      supplier_person_id INT NULL,
      customer_person_id INT NULL,
      total_products DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_discount DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_freight DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_other DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_icms DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_icms_st DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_fcp DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_ipi DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_pis DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_cofins DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_iss DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_ibs_uf DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_ibs_mun DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_cbs DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_document DECIMAL(15,2) NOT NULL DEFAULT 0,
      total_tax_approx DECIMAL(15,2) NOT NULL DEFAULT 0,
      xml_path VARCHAR(255) NULL,
      xml_raw LONGTEXT NULL,
      xml_hash VARCHAR(64) NULL,
      pdf_path VARCHAR(255) NULL,
      source_type VARCHAR(30) NULL,
      source_id BIGINT NULL,
      finance_generated TINYINT(1) NOT NULL DEFAULT 0,
      stock_generated TINYINT(1) NOT NULL DEFAULT 0,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_documents_access_key (company_id, access_key),
      KEY idx_fiscal_documents_lookup (company_id, model, series, number),
      KEY idx_fiscal_documents_status (company_id, direction, status, issue_datetime),
      KEY idx_fiscal_documents_source (company_id, source_type, source_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $docCols = [
      'access_key' => 'VARCHAR(60) NULL', 'protocol_number' => 'VARCHAR(60) NULL', 'emission_type' => "VARCHAR(20) NOT NULL DEFAULT 'NORMAL'", 'contingency_started_at' => 'DATETIME NULL', 'contingency_reason' => 'VARCHAR(255) NULL', 'contingency_original_doc_id' => 'BIGINT NULL', 'contingency_transmission_attempts' => 'INT NOT NULL DEFAULT 0', 'contingency_last_attempt_at' => 'DATETIME NULL', 'sefaz_status_code' => 'VARCHAR(10) NULL', 'sefaz_status_message' => 'VARCHAR(255) NULL',
      'operation_nature_id' => 'INT NULL', 'operation_nature' => 'VARCHAR(180) NULL', 'operation_datetime' => 'DATETIME NULL', 'authorization_datetime' => 'DATETIME NULL', 'exit_entry_datetime' => 'DATETIME NULL', 'competence_date' => 'DATE NULL',
      'issuer_person_id' => 'INT NULL', 'recipient_person_id' => 'INT NULL', 'supplier_person_id' => 'INT NULL', 'customer_person_id' => 'INT NULL',
      'total_discount' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_freight' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_other' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'total_icms' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_icms_st' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_fcp' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'total_ipi' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_pis' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_cofins' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_iss' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'total_ibs_uf' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_ibs_mun' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_cbs' => 'DECIMAL(15,2) NOT NULL DEFAULT 0', 'total_tax_approx' => 'DECIMAL(15,2) NOT NULL DEFAULT 0',
      'xml_path' => 'VARCHAR(255) NULL', 'xml_raw' => 'LONGTEXT NULL', 'xml_hash' => 'VARCHAR(64) NULL', 'pdf_path' => 'VARCHAR(255) NULL', 'source_type' => 'VARCHAR(30) NULL', 'source_id' => 'BIGINT NULL',
      'finance_generated' => 'TINYINT(1) NOT NULL DEFAULT 0', 'stock_generated' => 'TINYINT(1) NOT NULL DEFAULT 0', 'notes' => 'TEXT NULL', 'updated_at' => 'DATETIME NULL'
    ];
    foreach ($docCols as $c => $d) fiscal_add_column_if_missing($pdo, 'fiscal_documents', $c, $d);

    // Mantém separadas a data da operação comercial e a data de autorização SEFAZ.
    // Para documentos de pedido antigos, tenta recuperar a data original pelo pedido.
    try {
      if (fiscal_column_exists($pdo, 'fiscal_documents', 'operation_datetime')) {
        if (fiscal_table_exists($pdo, 'pedidos') && fiscal_column_exists($pdo, 'pedidos', 'created_at')) {
          $pdo->exec("UPDATE fiscal_documents fd INNER JOIN pedidos p ON p.id=fd.source_id AND p.company_id=fd.company_id SET fd.operation_datetime=p.created_at WHERE fd.operation_datetime IS NULL AND UPPER(COALESCE(fd.source_type,''))='PEDIDO' AND p.created_at IS NOT NULL");
        }
        $pdo->exec("UPDATE fiscal_documents SET operation_datetime=COALESCE(operation_datetime, issue_datetime, created_at) WHERE operation_datetime IS NULL");
      }
    } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_participants (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      role VARCHAR(20) NOT NULL,
      person_id INT NULL,
      document VARCHAR(30) NULL,
      state_registration VARCHAR(30) NULL,
      municipal_registration VARCHAR(30) NULL,
      name VARCHAR(180) NULL,
      fantasy_name VARCHAR(180) NULL,
      email VARCHAR(160) NULL,
      phone VARCHAR(40) NULL,
      zip_code VARCHAR(20) NULL,
      address VARCHAR(180) NULL,
      number VARCHAR(30) NULL,
      complement VARCHAR(120) NULL,
      district VARCHAR(100) NULL,
      city VARCHAR(120) NULL,
      city_ibge VARCHAR(7) NULL,
      state CHAR(2) NULL,
      country_code VARCHAR(4) NULL,
      country_name VARCHAR(80) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_doc_part_doc (fiscal_document_id, role),
      KEY idx_fiscal_doc_part_document (company_id, document)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_items (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      item_number INT NOT NULL,
      product_id INT NULL,
      supplier_product_code VARCHAR(80) NULL,
      product_code VARCHAR(80) NULL,
      ean VARCHAR(20) NULL,
      ean_trib VARCHAR(20) NULL,
      description VARCHAR(255) NOT NULL,
      ncm VARCHAR(8) NULL,
      cest VARCHAR(12) NULL,
      cfop VARCHAR(4) NULL,
      unidade VARCHAR(20) NULL,
      quantity DECIMAL(18,6) NOT NULL DEFAULT 0,
      unit_value DECIMAL(18,6) NOT NULL DEFAULT 0,
      total_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      unidade_trib VARCHAR(20) NULL,
      quantity_trib DECIMAL(18,6) NOT NULL DEFAULT 0,
      unit_value_trib DECIMAL(18,6) NOT NULL DEFAULT 0,
      discount_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      freight_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      insurance_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      other_value DECIMAL(15,2) NOT NULL DEFAULT 0,
      ind_total VARCHAR(1) NULL,
      order_item_id INT NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      UNIQUE KEY ux_fiscal_doc_item_number (fiscal_document_id, item_number),
      KEY idx_fiscal_doc_items_product (company_id, product_id),
      KEY idx_fiscal_doc_items_ncm_cest (company_id, ncm, cest),
      KEY idx_fiscal_doc_items_ean (company_id, ean)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
      'product_code'=>'VARCHAR(80) NULL','ean_trib'=>'VARCHAR(20) NULL','unidade_trib'=>'VARCHAR(20) NULL','quantity_trib'=>'DECIMAL(18,6) NOT NULL DEFAULT 0','unit_value_trib'=>'DECIMAL(18,6) NOT NULL DEFAULT 0',
      'discount_value'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','freight_value'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','insurance_value'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','other_value'=>'DECIMAL(15,2) NOT NULL DEFAULT 0','ind_total'=>'VARCHAR(1) NULL','order_item_id'=>'INT NULL','notes'=>'TEXT NULL','updated_at'=>'DATETIME NULL'
    ] as $c=>$d) fiscal_add_column_if_missing($pdo, 'fiscal_document_items', $c, $d);

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_item_taxes (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_item_id BIGINT NOT NULL,
      fiscal_document_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      tax_group VARCHAR(30) NOT NULL,
      tax_tag VARCHAR(40) NULL,
      origin_code VARCHAR(2) NULL,
      cst VARCHAR(10) NULL,
      csosn VARCHAR(10) NULL,
      class_code VARCHAR(20) NULL,
      base_calc DECIMAL(15,2) NOT NULL DEFAULT 0,
      base_calc_st DECIMAL(15,2) NOT NULL DEFAULT 0,
      rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      reduction_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      mva_rate DECIMAL(12,4) NOT NULL DEFAULT 0,
      amount DECIMAL(15,2) NOT NULL DEFAULT 0,
      extra_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_item_taxes_item (fiscal_document_item_id, tax_group),
      KEY idx_fiscal_item_taxes_doc (fiscal_document_id, tax_group)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_payments (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_id BIGINT NOT NULL,
      company_id INT NOT NULL,
      payment_method_id INT NULL,
      payment_type VARCHAR(10) NULL,
      payment_description VARCHAR(120) NULL,
      payment_indicator VARCHAR(2) NULL,
      amount DECIMAL(15,2) NOT NULL DEFAULT 0,
      card_brand VARCHAR(20) NULL,
      card_integration VARCHAR(1) NULL,
      card_cnpj VARCHAR(14) NULL,
      authorization_code VARCHAR(80) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_doc_payments_doc (fiscal_document_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    foreach ([
      'payment_method_id'=>'INT NULL',
      'card_integration'=>'VARCHAR(1) NULL',
      'card_cnpj'=>'VARCHAR(14) NULL'
    ] as $c=>$d) fiscal_add_column_if_missing($pdo, 'fiscal_document_payments', $c, $d);

    if (fiscal_table_exists($pdo, 'finance_payment_methods')) {
      foreach ([
        'nfce_payment_type'=>'VARCHAR(2) NULL',
        'nfce_payment_indicator'=>"VARCHAR(1) NOT NULL DEFAULT '0'",
        'nfce_card_integration'=>'VARCHAR(1) NULL',
        'nfce_card_brand'=>'VARCHAR(2) NULL',
        'nfce_card_cnpj'=>'VARCHAR(14) NULL',
        'nfce_notes'=>'TEXT NULL'
      ] as $c=>$d) fiscal_add_column_if_missing($pdo, 'finance_payment_methods', $c, $d);
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_document_events (
      id BIGINT NOT NULL AUTO_INCREMENT,
      fiscal_document_id BIGINT NULL,
      company_id INT NOT NULL,
      event_type VARCHAR(40) NOT NULL,
      event_sequence INT NOT NULL DEFAULT 1,
      event_datetime DATETIME NULL,
      protocol_number VARCHAR(60) NULL,
      status_code VARCHAR(10) NULL,
      status_message VARCHAR(255) NULL,
      request_xml LONGTEXT NULL,
      response_xml LONGTEXT NULL,
      notes TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_fiscal_doc_events_doc (fiscal_document_id, event_type),
      KEY idx_fiscal_doc_events_company (company_id, event_type, event_datetime)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    // Tabelas criadas em versões anteriores podem não possuir os campos de payload.
    fiscal_add_column_if_missing($pdo, 'fiscal_document_events', 'request_xml', 'LONGTEXT NULL');
    fiscal_add_column_if_missing($pdo, 'fiscal_document_events', 'response_xml', 'LONGTEXT NULL');

    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_emission_queue (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      fiscal_document_id BIGINT NULL,
      action VARCHAR(40) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
      priority INT NOT NULL DEFAULT 5,
      attempts INT NOT NULL DEFAULT 0,
      max_attempts INT NOT NULL DEFAULT 3,
      request_payload LONGTEXT NULL,
      response_payload LONGTEXT NULL,
      error_message TEXT NULL,
      scheduled_at DATETIME NULL,
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_queue_status (company_id, status, priority, scheduled_at),
      KEY idx_fiscal_queue_doc (fiscal_document_id, action)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    if (function_exists('fiscal_inutilization_ensure_schema')) fiscal_inutilization_ensure_schema($pdo);
    if (function_exists('nexa_schema_ensure_done')) nexa_schema_ensure_done($pdo, 'fiscal_nfce', __FILE__);
  }
}

if (!function_exists('fiscal_nfce_order_document')) {
  function fiscal_nfce_order_document(PDO $pdo, int $companyId, int $orderId): ?array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND model='65' AND direction='SAIDA' AND source_type='PEDIDO' AND source_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}


if (!function_exists('fiscal_doc_model_label')) {
  function fiscal_doc_model_label(string $model): string {
    $model = preg_replace('/\D+/', '', $model);
    if ($model === '55') return 'NF-e';
    if ($model === '65') return 'NFC-e';
    return 'Documento fiscal';
  }
}

if (!function_exists('fiscal_document_order_document')) {
  function fiscal_document_order_document(PDO $pdo, int $companyId, int $orderId, string $model = '65'): ?array {
    fiscal_nfce_ensure_schema($pdo);
    $model = in_array($model, ['55','65'], true) ? $model : '65';
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND model=? AND direction='SAIDA' AND source_type='PEDIDO' AND source_id=? ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId, $model, $orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('fiscal_nfe_order_document')) {
  function fiscal_nfe_order_document(PDO $pdo, int $companyId, int $orderId): ?array {
    return fiscal_document_order_document($pdo, $companyId, $orderId, '55');
  }
}


if (!function_exists('fiscal_document_order_authorized')) {
  function fiscal_document_order_authorized(PDO $pdo, int $companyId, int $orderId, string $model): ?array {
    $model = in_array($model, ['55','65'], true) ? $model : '65';
    $doc = fiscal_document_order_document($pdo, $companyId, $orderId, $model);
    return ($doc && strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA') ? $doc : null;
  }
}

if (!function_exists('fiscal_nfe_after_nfce_cfop')) {
  function fiscal_nfe_after_nfce_cfop(array $order, array $cfg): string {
    $emitUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? '')), 0, 2));
    $destUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($order['uf'] ?? $order['state'] ?? '')), 0, 2));
    $internal = substr(fiscal_digits($cfg['nfe_nfce_referenced_cfop_internal'] ?? '5949') ?: '5949', 0, 4);
    $external = substr(fiscal_digits($cfg['nfe_nfce_referenced_cfop_external'] ?? '6949') ?: '6949', 0, 4);
    // CFOP 5.929/6.929 é de lançamento relativo a Cupom Fiscal/ECF e aciona validação
    // de documento fiscal referenciado. Como a origem aqui é NFC-e modelo 65, o padrão
    // passa a ser 5.949/6.949, mantendo a vinculação no sistema e no infCpl.
    return ($emitUf !== '' && $destUf !== '' && $destUf !== $emitUf) ? $external : $internal;
  }
}

if (!function_exists('fiscal_nfce_document_by_id')) {
  function fiscal_nfce_document_by_id(PDO $pdo, int $companyId, int $docId): ?array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $docId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('fiscal_nfce_order_documents_map')) {
  function fiscal_nfce_order_documents_map(PDO $pdo, int $companyId, array $orderIds): array {
    fiscal_nfce_ensure_schema($pdo);
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds) return [];
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $params = array_merge([$companyId], $orderIds);
    $sql = "SELECT fd.*
            FROM fiscal_documents fd
            INNER JOIN (
              SELECT source_id, MAX(id) AS max_id
              FROM fiscal_documents
              WHERE company_id=? AND model='65' AND direction='SAIDA' AND source_type='PEDIDO' AND source_id IN ($placeholders)
              GROUP BY source_id
            ) x ON x.max_id = fd.id
            ORDER BY fd.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(int)$row['source_id']] = $row;
    return $out;
  }
}


if (!function_exists('fiscal_document_order_documents_map')) {
  function fiscal_document_order_documents_map(PDO $pdo, int $companyId, array $orderIds, string $model = '65'): array {
    fiscal_nfce_ensure_schema($pdo);
    $model = in_array($model, ['55','65'], true) ? $model : '65';
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds) return [];
    $placeholders = implode(',', array_fill(0, count($orderIds), '?'));
    $params = array_merge([$companyId, $model], $orderIds);
    $sql = "SELECT fd.*
            FROM fiscal_documents fd
            INNER JOIN (
              SELECT source_id, MAX(id) AS max_id
              FROM fiscal_documents
              WHERE company_id=? AND model=? AND direction='SAIDA' AND source_type='PEDIDO' AND source_id IN ($placeholders)
              GROUP BY source_id
            ) x ON x.max_id = fd.id
            ORDER BY fd.id DESC";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $out[(int)$row['source_id']] = $row;
    return $out;
  }
}

if (!function_exists('fiscal_nfe_order_documents_map')) {
  function fiscal_nfe_order_documents_map(PDO $pdo, int $companyId, array $orderIds): array {
    return fiscal_document_order_documents_map($pdo, $companyId, $orderIds, '55');
  }
}

if (!function_exists('fiscal_nfce_documents')) {
  function fiscal_nfce_documents(PDO $pdo, int $companyId, $filters = '', int $limit = 100): array {
    fiscal_nfce_ensure_schema($pdo);
    $data = is_array($filters) ? $filters : ['q' => (string)$filters];
    $q = trim((string)($data['q'] ?? ''));
    $issueFrom = trim((string)($data['issue_from'] ?? ''));
    $issueTo = trim((string)($data['issue_to'] ?? ''));
    $authFrom = trim((string)($data['auth_from'] ?? ''));
    $authTo = trim((string)($data['auth_to'] ?? ''));
    $series = trim((string)($data['series'] ?? ''));
    $status = strtoupper(trim((string)($data['status'] ?? '')));

    $where = ["fd.company_id = ?", "fd.direction='SAIDA'", "fd.model IN ('65','55')"];
    $params = [$companyId];

    if ($issueFrom !== '') {
      $where[] = 'DATE(fd.issue_datetime) >= ?';
      $params[] = $issueFrom;
    }
    if ($issueTo !== '') {
      $where[] = 'DATE(fd.issue_datetime) <= ?';
      $params[] = $issueTo;
    }
    if ($authFrom !== '') {
      $where[] = 'DATE(COALESCE(fd.updated_at, fd.created_at)) >= ?';
      $params[] = $authFrom;
    }
    if ($authTo !== '') {
      $where[] = 'DATE(COALESCE(fd.updated_at, fd.created_at)) <= ?';
      $params[] = $authTo;
    }
    if ($status !== '') {
      $where[] = 'UPPER(fd.status) = ?';
      $params[] = $status;
    }
    if ($series !== '') {
      $where[] = 'fd.series LIKE ?';
      $params[] = nexa_search_like($series);
    }

    if ($q !== '') {
      $digits = preg_replace('/\D+/', '', $q);
      $like = nexa_search_like($q);
      $where[] = "(fd.number = ? OR fd.access_key LIKE ? OR fd.series LIKE ? OR fd.status LIKE ? OR fd.operation_nature LIKE ? OR CAST(COALESCE(p.numero, '') AS CHAR) LIKE ? OR EXISTS (SELECT 1 FROM fiscal_document_participants fp WHERE fp.fiscal_document_id = fd.id AND fp.company_id = fd.company_id AND (fp.name LIKE ? OR fp.document LIKE ?)))";
      $params[] = ($digits !== '' ? $digits : $q);
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
      $params[] = $like;
    }

    $sql = "SELECT fd.*, p.numero AS order_company_number, "
         . "(SELECT fp.name FROM fiscal_document_participants fp WHERE fp.fiscal_document_id = fd.id AND fp.company_id = fd.company_id ORDER BY CASE WHEN fp.role IN ('DESTINATARIO','CLIENTE','TOMADOR') THEN 0 ELSE 1 END, fp.id ASC LIMIT 1) AS person_name "
         . "FROM fiscal_documents fd "
         . "LEFT JOIN pedidos p ON p.id = fd.source_id AND p.company_id = fd.company_id AND fd.source_type = 'PEDIDO' "
         . "WHERE " . implode(' AND ', $where)
         . ' ORDER BY COALESCE(fd.issue_datetime, fd.created_at) DESC, fd.id DESC LIMIT ' . max(10, min(500, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_user_series_column')) {
  function fiscal_user_series_column(string $model): string {
    $m = strtoupper(trim($model));
    if ($m === '55') return 'default_nfe_series_id';
    if ($m === '65') return 'default_nfce_series_id';
    return 'default_service_series_id';
  }
}

if (!function_exists('fiscal_user_company_series_document_type')) {
  function fiscal_user_company_series_document_type(string $model): string {
    $m = strtoupper(trim($model));
    if ($m === '55') return 'NFE';
    if ($m === '65') return 'NFCE';
    return 'SERVICE';
  }
}

if (!function_exists('fiscal_user_company_series_table_ready')) {
  function fiscal_user_company_series_table_ready(PDO $pdo): bool {
    return fiscal_table_exists($pdo, 'user_company_fiscal_series');
  }
}

if (!function_exists('fiscal_user_company_series_preferences')) {
  function fiscal_user_company_series_preferences(PDO $pdo, int $companyId, int $userId, string $environment): array {
    if ($companyId <= 0 || $userId <= 0 || !fiscal_user_company_series_table_ready($pdo)) return [];
    $environment = strtoupper(trim($environment));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';
    try {
      $st = $pdo->prepare('SELECT p.document_type,p.environment,p.fiscal_series_id,fds.* FROM user_company_fiscal_series p JOIN fiscal_document_series fds ON fds.id=p.fiscal_series_id AND fds.company_id=p.company_id WHERE p.user_id=? AND p.company_id=? AND p.environment=? ORDER BY p.document_type ASC');
      $st->execute([$userId, $companyId, $environment]);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
        $type = strtoupper(trim((string)($row['document_type'] ?? '')));
        if ($type !== '') $out[$type] = $row;
      }
      return $out;
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('fiscal_save_user_company_series_preferences')) {
  function fiscal_save_user_company_series_preferences(PDO $pdo, int $companyId, int $userId, array $data): void {
    if ($userId <= 0) throw new RuntimeException('Usuário inválido para configuração fiscal.');
    if ($companyId <= 0) throw new RuntimeException('Empresa fiscal inválida.');
    if (!fiscal_user_company_series_table_ready($pdo)) {
      throw new RuntimeException('A estrutura de série por usuário/empresa ainda não foi aplicada. Entre como platform_admin em Configurações → Atualizador do Banco e aplique a V11.35.1.');
    }

    $environment = strtoupper(trim((string)($data['environment'] ?? '')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) {
      $cfg = fiscal_company_config($pdo, $companyId);
      $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    }
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';

    $defs = [
      'NFE' => ['field' => 'user_nfe_series_id', 'models' => ['55'], 'label' => 'NF-e'],
      'NFCE' => ['field' => 'user_nfce_series_id', 'models' => ['65'], 'label' => 'NFC-e'],
      'SERVICE' => ['field' => 'user_service_series_id', 'models' => ['NFSE','DPS','RPS','SS'], 'label' => 'NFS-e/serviço'],
    ];

    $ownTx = !$pdo->inTransaction();
    if ($ownTx) $pdo->beginTransaction();
    try {
      $del = $pdo->prepare('DELETE FROM user_company_fiscal_series WHERE user_id=? AND company_id=? AND document_type=? AND environment=?');
      $find = $pdo->prepare('SELECT id,company_id,model,environment,is_active FROM fiscal_document_series WHERE id=? AND company_id=? AND environment=? LIMIT 1');
      $upsert = $pdo->prepare('INSERT INTO user_company_fiscal_series (user_id,company_id,document_type,environment,fiscal_series_id,created_at,updated_at) VALUES (?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE fiscal_series_id=VALUES(fiscal_series_id),updated_at=NOW()');

      foreach ($defs as $type => $def) {
        $seriesId = (int)($data[$def['field']] ?? 0);
        if ($seriesId <= 0) {
          $del->execute([$userId, $companyId, $type, $environment]);
          continue;
        }
        $find->execute([$seriesId, $companyId, $environment]);
        $series = $find->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$series || empty($series['is_active']) || !in_array(strtoupper((string)$series['model']), $def['models'], true)) {
          throw new RuntimeException('Série de ' . $def['label'] . ' inválida para a empresa e ambiente atuais.');
        }
        $upsert->execute([$userId, $companyId, $type, $environment, $seriesId]);
      }
      if ($ownTx) $pdo->commit();
    } catch (Throwable $e) {
      if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('fiscal_save_current_user_company_series_preferences')) {
  function fiscal_save_current_user_company_series_preferences(PDO $pdo, int $companyId, array $data): void {
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    if ($uid <= 0) throw new RuntimeException('Usuário autenticado não identificado.');
    fiscal_save_user_company_series_preferences($pdo, $companyId, $uid, $data);
  }
}

if (!function_exists('fiscal_user_default_series')) {
  function fiscal_user_default_series(PDO $pdo, int $companyId, int $userId, string $model, string $environment): ?array {
    if ($userId <= 0) return null;
    $model = strtoupper(trim($model));
    $environment = strtoupper(trim($environment));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';

    // V11.35.1: preferência primária por usuário + empresa ativa + documento + ambiente.
    if (fiscal_user_company_series_table_ready($pdo)) {
      try {
        $type = fiscal_user_company_series_document_type($model);
        $st = $pdo->prepare('SELECT fds.* FROM user_company_fiscal_series p JOIN fiscal_document_series fds ON fds.id=p.fiscal_series_id AND fds.company_id=p.company_id WHERE p.user_id=? AND p.company_id=? AND p.document_type=? AND p.environment=? AND fds.model=? AND fds.environment=? AND fds.is_active=1 LIMIT 1');
        $st->execute([$userId, $companyId, $type, $environment, $model, $environment]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row) return $row;
      } catch (Throwable $e) {}
    }

    // Compatibilidade: mantém o campo legado de users, mas só aceita a série se ela
    // realmente pertencer à empresa ativa. Nunca reaproveita série de outra empresa.
    if (!fiscal_table_exists($pdo, 'users')) return null;
    $col = fiscal_user_series_column($model);
    if (!fiscal_column_exists($pdo, 'users', $col)) return null;
    try {
      $st = $pdo->prepare('SELECT fds.* FROM users u JOIN fiscal_document_series fds ON fds.id = u.`' . $col . '` AND fds.company_id=? WHERE u.id=? AND fds.model=? AND fds.environment=? AND fds.is_active=1 LIMIT 1');
      $st->execute([$companyId, $userId, $model, $environment]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('fiscal_current_user_default_series')) {
  function fiscal_current_user_default_series(PDO $pdo, int $companyId, string $model, string $environment): ?array {
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    return fiscal_user_default_series($pdo, $companyId, $uid, $model, $environment);
  }
}

if (!function_exists('fiscal_nfce_active_series')) {
  function fiscal_nfce_active_series(PDO $pdo, int $companyId, string $model = '65', string $environment = 'HOMOLOGACAO'): ?array {
    fiscal_nfce_ensure_schema($pdo);
    $model = strtoupper(trim($model));
    $userSeries = fiscal_current_user_default_series($pdo, $companyId, $model, $environment);
    if ($userSeries) return $userSeries;
    $st = $pdo->prepare('SELECT * FROM fiscal_document_series WHERE company_id=? AND model=? AND environment=? AND is_active=1 ORDER BY series ASC, id ASC LIMIT 1');
    $st->execute([$companyId, $model, $environment]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('fiscal_nfce_config_problems')) {
  function fiscal_nfce_config_problems(PDO $pdo, int $companyId): array {
    fiscal_nfce_ensure_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $problems = [];
    if (empty($cfg['nfce_enabled'])) $problems[] = 'Habilite a NFC-e nas configurações fiscais.';
    if (strlen(fiscal_digits($cfg['company_cnpj'] ?? '')) !== 14) $problems[] = 'Informe o CNPJ da empresa.';
    if (trim((string)($cfg['uf'] ?? '')) === '') $problems[] = 'Informe a UF da empresa.';
    if (strlen(fiscal_digits($cfg['city_ibge'] ?? '')) !== 7) $problems[] = 'Informe o código IBGE da cidade.';
    if (trim((string)($cfg['crt'] ?? '')) === '') $problems[] = 'Informe o CRT/regime tributário.';
    if (empty($cfg['nfce_csc_id']) || empty($cfg['nfce_csc_token'])) $problems[] = 'Informe o CSC/IdToken da NFC-e para QR Code.';
    if (!fiscal_nfce_active_series($pdo, $companyId, '65', (string)($cfg['environment'] ?? 'HOMOLOGACAO'))) $problems[] = 'Cadastre uma série ativa para NFC-e modelo 65 no ambiente atual.';
    return $problems;
  }
}


if (!function_exists('fiscal_nfe_config_problems')) {
  function fiscal_nfe_config_problems(PDO $pdo, int $companyId): array {
    fiscal_nfce_ensure_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $problems = [];
    if (empty($cfg['nfe_enabled'])) $problems[] = 'Habilite a NF-e nas configurações fiscais.';
    if (strlen(fiscal_digits($cfg['company_cnpj'] ?? '')) !== 14) $problems[] = 'Informe o CNPJ da empresa.';
    if (trim((string)($cfg['uf'] ?? '')) === '') $problems[] = 'Informe a UF da empresa.';
    if (strlen(fiscal_digits($cfg['city_ibge'] ?? '')) !== 7) $problems[] = 'Informe o código IBGE da cidade.';
    if (trim((string)($cfg['crt'] ?? '')) === '') $problems[] = 'Informe o CRT/regime tributário.';
    if (!fiscal_nfce_active_series($pdo, $companyId, '55', (string)($cfg['environment'] ?? 'HOMOLOGACAO'))) $problems[] = 'Cadastre uma série ativa para NF-e modelo 55 no ambiente atual.';
    return $problems;
  }
}

if (!function_exists('fiscal_nfce_payment_map')) {
  function fiscal_nfce_payment_map(string $value): array {
    $s = mb_strtolower(trim($value));

    // Regras específicas devem vir antes das regras genéricas (ex.: PIX automático
    // antes de PIX e crédito em loja antes de cartão de crédito).
    if (str_contains($s, 'sem pagamento') || str_contains($s, 'controle de entregas')) return ['90','Sem pagamento'];
    if (str_contains($s, 'pagamento posterior') || str_contains($s, 'a prazo') || str_contains($s, 'prazo') || str_contains($s, 'fiado')) return ['91','Pagamento posterior'];
    if (str_contains($s, 'duplicata')) return ['14','Duplicata mercantil'];
    if (str_contains($s, 'crédito em loja') || str_contains($s, 'credito em loja')) return ['21','Crédito em loja'];
    if (str_contains($s, 'pix automático') || str_contains($s, 'pix automatico')) return ['23','PIX automático'];
    if (str_contains($s, 'book transfer')) return ['24','TEF - Book Transfer'];
    if (str_contains($s, 'crediário') || str_contains($s, 'crediario') || str_contains($s, 'private label')) return ['05','Cartão da loja / crediário'];

    // Por padrão, Pix genérico do delivery continua tratado como Pix estático (tPag 20).
    if (str_contains($s, 'pix')) return ['20','PIX'];
    if (str_contains($s, 'dinheiro')) return ['01','Dinheiro'];
    if (str_contains($s, 'débito') || str_contains($s, 'debito')) return ['04','Cartão de débito'];
    if (str_contains($s, 'crédito') || str_contains($s, 'credito')) return ['03','Cartão de crédito'];
    if (str_contains($s, 'boleto')) return ['15','Boleto bancário'];
    if (str_contains($s, 'transfer') || str_contains($s, 'ted')) return ['18','TED (Transferência Eletrônica Disponível)'];
    if (str_contains($s, 'vale')) return ['10','Vale alimentação/refeição'];
    return ['99','Outros'];
  }
}

if (!function_exists('fiscal_nfce_order_payment_info')) {
  function fiscal_nfce_order_payment_info(PDO $pdo, int $companyId, array $order): array {
    $name = trim((string)($order['forma_pagamento'] ?? ''));
    [$type, $desc] = fiscal_nfce_payment_map($name);
    $paymentNameLower = mb_strtolower($name);
    $indicator = (str_contains($paymentNameLower, 'prazo') || str_contains($paymentNameLower, 'fiado') || str_contains($paymentNameLower, 'posterior') || str_contains($paymentNameLower, 'crediário') || str_contains($paymentNameLower, 'crediario')) ? '1' : '0';
    $out = [
      'payment_method_id' => (int)($order['payment_method_id'] ?? 0) ?: null,
      'payment_type' => $type,
      'payment_description' => $desc,
      'payment_indicator' => $indicator,
      'card_integration' => '',
      'card_brand' => '',
      'card_cnpj' => '',
      'authorization_code' => '',
    ];

    $methodId = (int)($order['payment_method_id'] ?? 0);
    if ($methodId > 0 && fiscal_table_exists($pdo, 'finance_payment_methods')) {
      try {
        $st = $pdo->prepare('SELECT * FROM finance_payment_methods WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$companyId, $methodId]);
        $m = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($m) {
          $methodName = trim((string)($m['name'] ?? ''));
          $fallback = fiscal_nfce_payment_map($methodName !== '' ? $methodName : (string)($m['type'] ?? ''));
          $nfceType = fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_payment_type') ? preg_replace('/\D+/', '', (string)($m['nfce_payment_type'] ?? '')) : '';
          $nfceType = str_pad(substr($nfceType, 0, 2), 2, '0', STR_PAD_LEFT);
          if ($nfceType === '00') $nfceType = '';
          $out['payment_type'] = $nfceType !== '' ? $nfceType : $fallback[0];
          $out['payment_description'] = $methodName !== '' ? $methodName : $fallback[1];
          if (fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_payment_indicator')) {
            $ind = preg_replace('/\D+/', '', (string)($m['nfce_payment_indicator'] ?? ''));
            $out['payment_indicator'] = in_array($ind, ['0','1'], true) ? $ind : $out['payment_indicator'];
          }
          if (fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_card_integration')) {
            $tp = preg_replace('/\D+/', '', (string)($m['nfce_card_integration'] ?? ''));
            $out['card_integration'] = in_array($tp, ['1','2'], true) ? $tp : '';
          }
          if (fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_card_brand')) {
            $brand = preg_replace('/\D+/', '', (string)($m['nfce_card_brand'] ?? ''));
            $out['card_brand'] = $brand !== '' ? str_pad(substr($brand, 0, 2), 2, '0', STR_PAD_LEFT) : '';
          }
          if (fiscal_column_exists($pdo, 'finance_payment_methods', 'nfce_card_cnpj')) {
            $out['card_cnpj'] = substr(fiscal_digits($m['nfce_card_cnpj'] ?? ''), 0, 14);
          }
        }
      } catch (Throwable $e) {}
    }

    if (in_array((string)$out['payment_type'], ['03','04'], true) && $out['card_integration'] === '') {
      $out['card_integration'] = '2'; // POS não integrado por padrão.
    }
    return $out;
  }
}

if (!function_exists('fiscal_nfce_uf_code')) {
  function fiscal_nfce_uf_code(string $uf): string {
    $map = ['RO'=>'11','AC'=>'12','AM'=>'13','RR'=>'14','PA'=>'15','AP'=>'16','TO'=>'17','MA'=>'21','PI'=>'22','CE'=>'23','RN'=>'24','PB'=>'25','PE'=>'26','AL'=>'27','SE'=>'28','BA'=>'29','MG'=>'31','ES'=>'32','RJ'=>'33','SP'=>'35','PR'=>'41','SC'=>'42','RS'=>'43','MS'=>'50','MT'=>'51','GO'=>'52','DF'=>'53'];
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $uf), 0, 2));
    return $map[$uf] ?? '31';
  }
}

if (!function_exists('fiscal_nfce_mod11')) {
  function fiscal_nfce_mod11(string $base): string {
    $sum = 0; $weight = 2;
    for ($i = strlen($base)-1; $i >= 0; $i--) {
      $sum += ((int)$base[$i]) * $weight;
      $weight = $weight === 9 ? 2 : $weight + 1;
    }
    $mod = $sum % 11;
    $dv = 11 - $mod;
    if ($dv >= 10) $dv = 0;
    return (string)$dv;
  }
}

if (!function_exists('fiscal_nfce_make_access_key')) {
  function fiscal_nfce_make_access_key(array $cfg, string $model, string $series, int $number, string $issueDateTime, string $tpEmis = '1'): string {
    $ufCode = fiscal_nfce_uf_code((string)($cfg['uf'] ?? 'MG'));
    $ym = date('ym', strtotime($issueDateTime) ?: time());
    $cnpj = str_pad(substr(fiscal_digits($cfg['company_cnpj'] ?? ''), 0, 14), 14, '0', STR_PAD_LEFT);
    $model = str_pad(preg_replace('/\D+/', '', $model), 2, '0', STR_PAD_LEFT);
    $series = str_pad(substr(preg_replace('/\D+/', '', $series) ?: '1', 0, 3), 3, '0', STR_PAD_LEFT);
    $number = str_pad((string)max(1, $number), 9, '0', STR_PAD_LEFT);
    $tpEmis = in_array($tpEmis, ['1','4','5','6','7','9'], true) ? $tpEmis : '1';
    $cNF = substr(str_pad((string)abs(crc32($cnpj . $series . $number . $tpEmis . microtime(true))), 8, '0', STR_PAD_LEFT), -8);
    $base = $ufCode . $ym . $cnpj . $model . $series . $number . $tpEmis . $cNF;
    return $base . fiscal_nfce_mod11($base);
  }
}


if (!function_exists('fiscal_nfce_company_display_name')) {
  function fiscal_nfce_company_display_name(PDO $pdo, int $companyId, array $cfg = []): string {
    $name = trim((string)($cfg['company_name'] ?? ''));
    if ($name !== '') return $name;
    try {
      if (fiscal_table_exists($pdo, 'companies') && fiscal_column_exists($pdo, 'companies', 'name')) {
        $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
        $st->execute([$companyId]);
        $name = trim((string)($st->fetchColumn() ?: ''));
        if ($name !== '') return $name;
      }
    } catch (Throwable $e) {}
    return 'EMITENTE';
  }
}


if (!function_exists('fiscal_nfce_document_participant')) {
  function fiscal_nfce_document_participant(PDO $pdo, int $companyId, int $docId, string $role): array {
    $st = $pdo->prepare('SELECT * FROM fiscal_document_participants WHERE company_id=? AND fiscal_document_id=? AND role=? ORDER BY id DESC LIMIT 1');
    $st->execute([$companyId, $docId, strtoupper($role)]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}


if (!function_exists('fiscal_nfce_enrich_person_participant')) {
  /**
   * Completa dados fiscais do participante a partir do cadastro central de Pessoas.
   * Prioriza person_id; quando documentos antigos não possuem vínculo, tenta CPF/CNPJ.
   */
  function fiscal_nfce_enrich_person_participant(PDO $pdo, int $companyId, array $participant, int $fallbackPersonId = 0): array {
    if (!fiscal_table_exists($pdo, 'finance_people')) return $participant;
    $personId = (int)($participant['person_id'] ?? 0) ?: $fallbackPersonId;
    $doc = fiscal_digits($participant['document'] ?? '');
    try {
      if ($personId > 0) {
        $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$companyId, $personId]);
      } elseif (in_array(strlen($doc), [11,14], true)) {
        $st = $pdo->prepare("SELECT * FROM finance_people WHERE company_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(document,''),'.',''),'/',''),'-',''),' ','')=? ORDER BY active DESC,id DESC LIMIT 1");
        $st->execute([$companyId, $doc]);
      } else {
        return $participant;
      }
      $person = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      if (!$person) return $participant;
      $map = [
        'person_id'=>'id', 'document'=>'document', 'state_registration'=>'state_registration',
        'name'=>'name', 'email'=>'email', 'phone'=>'phone', 'zip_code'=>'zip_code',
        'address'=>'address', 'number'=>'number', 'complement'=>'complement', 'district'=>'district',
        'city'=>'city', 'city_ibge'=>'city_ibge', 'state'=>'state'
      ];
      foreach ($map as $target => $source) {
        if (trim((string)($participant[$target] ?? '')) === '' && trim((string)($person[$source] ?? '')) !== '') {
          $participant[$target] = $person[$source];
        }
      }
    } catch (Throwable $e) {}
    return $participant;
  }
}

if (!function_exists('fiscal_nfce_issuer_ie')) {
  /** Resolve a IE do emitente usando Configurações Fiscais e fallback do cadastro da empresa. */
  function fiscal_nfce_issuer_ie(PDO $pdo, int $companyId, array $cfg = []): string {
    $ie = trim((string)($cfg['state_registration'] ?? ''));
    if ($ie === '' && function_exists('fiscal_company_report_identity')) {
      try {
        $identity = fiscal_company_report_identity($pdo, $companyId);
        $ie = trim((string)($identity['ie'] ?? ''));
      } catch (Throwable $e) {}
    }
    $upper = strtoupper($ie);
    if ($upper === 'ISENTO') return 'ISENTO';
    return preg_replace('/[^0-9A-Za-z]+/', '', $ie) ?: '';
  }
}

if (!function_exists('fiscal_nfce_clean_address_parts')) {
  function fiscal_nfce_clean_address_parts(array $participant, array $cfg = []): array {
    $address = trim((string)($participant['address'] ?? ''));
    $number = trim((string)($participant['number'] ?? ''));
    if ($number === '' && preg_match('/,\s*([0-9A-Za-z\-\/]+)\s*$/', $address, $m)) {
      $number = $m[1];
      $address = trim((string)preg_replace('/,\s*([0-9A-Za-z\-\/]+)\s*$/', '', $address));
    }
    $city = trim((string)($participant['city'] ?? ''));
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($participant['state'] ?? '')), 0, 2));
    return [
      'address' => fiscal_nfce_safe_xml_text($address !== '' ? $address : 'NAO INFORMADO', 60),
      'number' => fiscal_nfce_safe_xml_text($number !== '' ? $number : '0', 60),
      'complement' => fiscal_nfce_safe_xml_text($participant['complement'] ?? '', 60),
      'district' => fiscal_nfce_safe_xml_text($participant['district'] ?? 'NAO INFORMADO', 60) ?: 'NAO INFORMADO',
      'city_ibge' => fiscal_digits($participant['city_ibge'] ?? '') ?: fiscal_digits($cfg['city_ibge'] ?? ''),
      'city' => fiscal_nfce_safe_xml_text($city !== '' ? $city : 'NAO INFORMADO', 60),
      'state' => $uf !== '' ? $uf : strtoupper((string)($cfg['uf'] ?? 'MG')),
      'zip_code' => substr(fiscal_digits($participant['zip_code'] ?? ''), 0, 8) ?: '00000000',
    ];
  }
}

if (!function_exists('fiscal_nfce_validate_authorization_rules')) {
  function fiscal_nfce_validate_authorization_rules(PDO $pdo, int $companyId, int $docId): void {
    // Na NFC-e, taxa de entrega/frete do delivery é tratada como acréscimo (vOutro),
    // sem gerar vFrete nem modalidade de frete com transportador no XML.
    // Assim não bloqueamos a autorização por dados de transportador/destinatário apenas
    // porque o pedido possui taxa de entrega.
    return;
  }
}

if (!function_exists('fiscal_nfce_safe_xml_text')) {
  function fiscal_nfce_safe_xml_text($value, int $max = 255): string {
    $s = fiscal_sintegra_ascii((string)$value);
    $s = preg_replace('/\s+/', ' ', trim($s));
    return mb_substr($s, 0, $max);
  }
}

if (!function_exists('fiscal_nfce_order_data')) {
  function fiscal_nfce_order_data(PDO $pdo, int $companyId, int $orderId): array {
    $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $orderId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Pedido não encontrado.');

    // Complementa dados do destinatário a partir do cadastro financeiro/cliente,
    // quando o pedido tiver apenas o nome/telefone do delivery.
    try {
      if (fiscal_table_exists($pdo, 'finance_people')) {
        $personId = (int)($order['customer_id'] ?? 0) ?: (int)($order['finance_person_id'] ?? 0);
        if ($personId > 0) {
          $ps = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
          $ps->execute([$companyId, $personId]);
          $person = $ps->fetch(PDO::FETCH_ASSOC) ?: null;
          if ($person) {
            $map = [
              'customer_document'=>'document', 'nome_cliente'=>'name', 'whatsapp'=>'whatsapp',
              'zip_code'=>'zip_code', 'endereco'=>'address', 'numero'=>'number', 'complemento'=>'complement',
              'bairro'=>'district', 'cidade'=>'city', 'uf'=>'state',
              'customer_state_registration'=>'state_registration', 'customer_city_ibge'=>'city_ibge',
              'customer_email'=>'email'
            ];
            foreach ($map as $orderKey => $personKey) {
              if (trim((string)($order[$orderKey] ?? '')) === '' && trim((string)($person[$personKey] ?? '')) !== '') {
                $order[$orderKey] = $person[$personKey];
              }
            }
            // Mantém o vínculo da Pessoa para que o documento fiscal possa se re-enriquecer
            // caso um dado fiscal seja atualizado depois da criação do pedido.
            $order['customer_person_id'] = $personId;
          }
        }
      }
    } catch (Throwable $e) {}

    $hasProductNumber = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
    $hasProductCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
    $hasProductUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
    $hasProductPrice = fiscal_column_exists($pdo, 'produtos', 'preco');
    $hasProductIsService = fiscal_column_exists($pdo, 'produtos', 'is_service');
    $hasMap = fiscal_table_exists($pdo, 'produtos_firebird_map') && fiscal_column_exists($pdo, 'produtos_firebird_map', 'codigo_firebird');

    $joinMap = $hasMap ? "\n            LEFT JOIN produtos_firebird_map pfm ON pfm.company_id=pi.company_id AND pfm.produto_id=pi.produto_id" : '';
    if ($hasProductCode && $hasMap) {
      $codeSelect = "COALESCE(NULLIF(TRIM(p.codigo_firebird), ''), pfm.codigo_firebird, '') AS codigo_firebird";
    } elseif ($hasProductCode) {
      $codeSelect = "COALESCE(NULLIF(TRIM(p.codigo_firebird), ''), '') AS codigo_firebird";
    } elseif ($hasMap) {
      $codeSelect = "COALESCE(pfm.codigo_firebird, '') AS codigo_firebird";
    } else {
      $codeSelect = "'' AS codigo_firebird";
    }
    $numberSelect = $hasProductNumber ? "p.numero_cadastro AS numero_cadastro" : "NULL AS numero_cadastro";
    $unitSelect = $hasProductUnit ? "COALESCE(NULLIF(TRIM(p.unidade_estoque), ''), 'UN') AS unidade_estoque" : "'UN' AS unidade_estoque";
    $priceSelect = $hasProductPrice ? "p.preco AS product_price" : "0 AS product_price";
    $serviceSelect = $hasProductIsService ? "COALESCE(p.is_service,0) AS is_service" : "0 AS is_service";

    $sql = "SELECT pi.*, p.nome AS product_name, p.descricao AS product_description,
                   {$serviceSelect}, {$numberSelect}, {$codeSelect}, {$unitSelect}, {$priceSelect}
            FROM pedido_itens pi
            LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id{$joinMap}
            WHERE pi.company_id=? AND pi.pedido_id=?
            ORDER BY pi.id ASC";
    $it = $pdo->prepare($sql);
    $it->execute([$companyId, $orderId]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$items) throw new RuntimeException('Pedido sem itens para emissão.');
    return ['order'=>$order, 'items'=>$items];
  }
}


if (!function_exists('fiscal_nfce_allocate_amounts')) {
  /**
   * Distribui frete/desconto/acréscimo entre os itens em centavos, mantendo a soma exata.
   * Isso evita rejeições como a 535, em que vFrete total precisa bater com a soma dos vFrete dos itens.
   */
  function fiscal_nfce_allocate_amounts(float $amount, array $items, string $weightKey = 'total'): array {
    $amount = round($amount, 2);
    $count = count($items);
    if ($count <= 0 || abs($amount) < 0.005) return array_fill(0, max(0, $count), 0.0);
    $weights = [];
    $totalWeight = 0.0;
    foreach ($items as $it) {
      $w = (float)($it[$weightKey] ?? 0);
      if ($w <= 0) {
        $q = (float)($it['qtd'] ?? 0);
        $u = (float)($it['preco_unit'] ?? 0);
        $w = max(0.0, $q * $u);
      }
      $weights[] = $w;
      $totalWeight += $w;
    }
    if ($totalWeight <= 0) {
      $weights = array_fill(0, $count, 1.0);
      $totalWeight = (float)$count;
    }
    $alloc = [];
    $running = 0.0;
    for ($i = 0; $i < $count; $i++) {
      if ($i === $count - 1) {
        $value = round($amount - $running, 2);
      } else {
        $value = round($amount * ($weights[$i] / $totalWeight), 2);
        $running += $value;
      }
      // Evita -0.00 no XML e no banco.
      $alloc[$i] = abs($value) < 0.005 ? 0.0 : $value;
    }
    return $alloc;
  }
}

if (!function_exists('fiscal_nfce_ibs_cbs_cst_from_class')) {
  function fiscal_nfce_ibs_cbs_cst_from_class(PDO $pdo, int $companyId, string $classCode): string {
    $classCode = trim($classCode);
    if ($classCode === '') return '';
    try {
      if (function_exists('fiscal_ibs_cbs_classification_by_code')) {
        $row = fiscal_ibs_cbs_classification_by_code($pdo, $companyId, $classCode);
        if ($row && trim((string)($row['cst_code'] ?? '')) !== '') {
          return str_pad(preg_replace('/\D+/', '', (string)$row['cst_code']), 3, '0', STR_PAD_LEFT);
        }
      }
    } catch (Throwable $e) {}
    // Pela tabela oficial, os três primeiros dígitos do cClassTrib correspondem ao CST.
    $digits = preg_replace('/\D+/', '', $classCode) ?: '';
    return strlen($digits) >= 3 ? substr($digits, 0, 3) : '';
  }
}


if (!function_exists('fiscal_nfce_tax_extra_json')) {
  function fiscal_nfce_tax_extra_json(array $tax): array {
    $raw = trim((string)($tax['extra_json'] ?? ''));
    if ($raw === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
  }
}

if (!function_exists('fiscal_nfce_add_icms_st_xml')) {
  function fiscal_nfce_add_icms_st_xml(Closure $add, DOMElement $node, array $tax, string $cstCode, string $csosnCode, callable $fmt, callable $fmt4): array {
    $extra = fiscal_nfce_tax_extra_json($tax);
    $stBase = max(0.0, (float)($extra['st_base'] ?? $extra['vBCST'] ?? 0));
    $stRate = max(0.0, (float)($extra['st_rate'] ?? $extra['pICMSST'] ?? 0));
    $stValue = max(0.0, (float)($extra['st_amount'] ?? $extra['vICMSST'] ?? 0));
    $stMva = max(0.0, (float)($extra['st_mva'] ?? $extra['pMVAST'] ?? 0));
    $stRed = max(0.0, (float)($extra['st_reduction_rate'] ?? $extra['pRedBCST'] ?? 0));
    $stModBc = preg_replace('/\D+/', '', (string)($extra['st_modbc'] ?? $extra['modBCST'] ?? '4')) ?: '4';
    $fcpStBase = max(0.0, (float)($extra['fcp_st_base'] ?? $extra['vBCFCPST'] ?? 0));
    $fcpStRate = max(0.0, (float)($extra['fcp_st_rate'] ?? $extra['pFCPST'] ?? 0));
    $fcpStValue = max(0.0, (float)($extra['fcp_st_amount'] ?? $extra['vFCPST'] ?? 0));
    $hasSt = $stBase > 0 || $stRate > 0 || $stValue > 0 || $stMva > 0 || $stRed > 0 || $fcpStBase > 0 || $fcpStRate > 0 || $fcpStValue > 0;
    if (!$hasSt) return ['vBCST'=>0.0, 'vST'=>0.0, 'vFCPST'=>0.0];

    $isRetained = in_array($cstCode, ['60'], true) || in_array($csosnCode, ['500'], true);
    if ($isRetained) {
      $add($node, 'vBCSTRet', $fmt($stBase));
      if ($stRate > 0) $add($node, 'pST', $fmt4($stRate));
      $add($node, 'vICMSSubstituto', $fmt(0));
      $add($node, 'vICMSSTRet', $fmt($stValue));
      if ($fcpStBase > 0 || $fcpStRate > 0 || $fcpStValue > 0) {
        $add($node, 'vBCFCPSTRet', $fmt($fcpStBase ?: $stBase));
        $add($node, 'pFCPSTRet', $fmt4($fcpStRate));
        $add($node, 'vFCPSTRet', $fmt($fcpStValue));
      }
      return ['vBCST'=>$stBase, 'vST'=>$stValue, 'vFCPST'=>$fcpStValue];
    }

    $supportsSt = in_array($cstCode, ['10','30','70','90'], true) || in_array($csosnCode, ['201','202','203','900'], true);
    if (!$supportsSt) return ['vBCST'=>0.0, 'vST'=>0.0, 'vFCPST'=>0.0];

    $add($node, 'modBCST', substr($stModBc, 0, 1));
    if ($stMva > 0) $add($node, 'pMVAST', $fmt4($stMva));
    if ($stRed > 0) $add($node, 'pRedBCST', $fmt4($stRed));
    $add($node, 'vBCST', $fmt($stBase));
    $add($node, 'pICMSST', $fmt4($stRate));
    $add($node, 'vICMSST', $fmt($stValue));
    if ($fcpStBase > 0 || $fcpStRate > 0 || $fcpStValue > 0) {
      $add($node, 'vBCFCPST', $fmt($fcpStBase ?: $stBase));
      $add($node, 'pFCPST', $fmt4($fcpStRate));
      $add($node, 'vFCPST', $fmt($fcpStValue));
    }
    if ($csosnCode === '201') {
      $add($node, 'pCredSN', $fmt4((float)($extra['sn_credit_rate'] ?? 0)));
      $add($node, 'vCredICMSSN', $fmt((float)($extra['sn_credit_amount'] ?? 0)));
    } elseif ($csosnCode === '900') {
      $snRate = (float)($extra['sn_credit_rate'] ?? 0);
      $snAmount = (float)($extra['sn_credit_amount'] ?? 0);
      if ($snRate > 0 || $snAmount > 0) {
        $add($node, 'pCredSN', $fmt4($snRate));
        $add($node, 'vCredICMSSN', $fmt($snAmount));
      }
    }
    return ['vBCST'=>$stBase, 'vST'=>$stValue, 'vFCPST'=>$fcpStValue];
  }
}



if (!function_exists('fiscal_nfce_icms_xml_tag')) {
  function fiscal_nfce_icms_xml_tag(string $crt, string $cst = '', string $csosn = ''): string {
    $crt = preg_replace('/\D+/', '', $crt) ?: '1';
    $csosn = preg_replace('/\D+/', '', $csosn) ?: '';
    $cst = preg_replace('/\D+/', '', $cst) ?: '';
    if ($csosn !== '' || $crt === '1') {
      $csosn = str_pad(substr($csosn !== '' ? $csosn : '102', 0, 3), 3, '0', STR_PAD_LEFT);
      $map = [
        '101' => 'ICMSSN101',
        '102' => 'ICMSSN102',
        '103' => 'ICMSSN102',
        '201' => 'ICMSSN201',
        '202' => 'ICMSSN202',
        '203' => 'ICMSSN202',
        '300' => 'ICMSSN102',
        '400' => 'ICMSSN102',
        '500' => 'ICMSSN500',
        '900' => 'ICMSSN900',
      ];
      return $map[$csosn] ?? 'ICMSSN102';
    }
    $cst = str_pad(substr($cst !== '' ? $cst : '00', 0, 2), 2, '0', STR_PAD_LEFT);
    $map = [
      '00' => 'ICMS00',
      '02' => 'ICMS02',
      '10' => 'ICMS10',
      '15' => 'ICMS15',
      '20' => 'ICMS20',
      '30' => 'ICMS30',
      '40' => 'ICMS40',
      '41' => 'ICMS40',
      '50' => 'ICMS40',
      '51' => 'ICMS51',
      '53' => 'ICMS53',
      '60' => 'ICMS60',
      '70' => 'ICMS70',
      '90' => 'ICMS90',
    ];
    return $map[$cst] ?? 'ICMS90';
  }
}

if (!function_exists('fiscal_nfce_ipi_xml_tag')) {
  /**
   * Retorna o grupo correto do IPI conforme a CST prevista no leiaute da NF-e.
   * IPITrib: 00, 49, 50 e 99. IPINT: 01 a 05 e 51 a 55.
   */
  function fiscal_nfce_ipi_xml_tag(string $cst): string {
    $cst = str_pad(substr(preg_replace('/\D+/', '', $cst) ?: '', 0, 2), 2, '0', STR_PAD_LEFT);
    if (in_array($cst, ['00','49','50','99'], true)) return 'IPITrib';
    if (in_array($cst, ['01','02','03','04','05','51','52','53','54','55'], true)) return 'IPINT';
    return 'IPINT';
  }
}

if (!function_exists('fiscal_cfop_normalize_direction')) {
  /**
   * Garante que o CFOP esteja coerente com a direção do documento.
   * Ex.: uma devolução de compra em saída não pode permanecer com 1.411;
   * para destinatário da mesma UF, o código é normalizado para 5.411.
   */
  function fiscal_cfop_normalize_direction(string $cfop, string $direction, bool $sameUf = true): string {
    $cfop = substr(preg_replace('/\D+/', '', $cfop) ?: '', 0, 4);
    if (strlen($cfop) !== 4) return '';
    $direction = strtoupper(trim($direction));
    $prefix = substr($cfop, 0, 1);
    if ($direction === 'SAIDA') {
      if (in_array($prefix, ['1','2','3'], true)) $cfop = ($sameUf ? '5' : '6') . substr($cfop, 1);
    } else {
      if (in_array($prefix, ['5','6','7'], true)) $cfop = ($sameUf ? '1' : '2') . substr($cfop, 1);
    }
    // Dentro x fora do Estado: o CFOP precisa combinar com o destino (idDest),
    // senão a SEFAZ rejeita (733: CFOP interno com idDest diferente de 1;
    // 732: CFOP interestadual com idDest 1). Exterior (3/7) não é alterado.
    return fiscal_cfop_state_equivalent($cfop, $sameUf);
  }
}

if (!function_exists('fiscal_cfop_state_equivalent')) {
  /** CFOP equivalente dentro (5/1) ou fora (6/2) do Estado. */
  function fiscal_cfop_state_equivalent(string $cfop, bool $sameUf): string {
    $cfop = substr(preg_replace('/\D+/', '', $cfop) ?: '', 0, 4);
    if (strlen($cfop) !== 4) return $cfop;
    $toInter = ['5405' => '6404'];
    $toIntra = ['6404' => '5405', '6107' => '5101', '6108' => '5102'];
    $prefix = $cfop[0];
    if (!$sameUf && in_array($prefix, ['5','1'], true)) {
      return $toInter[$cfop] ?? (($prefix === '5' ? '6' : '2') . substr($cfop, 1));
    }
    if ($sameUf && in_array($prefix, ['6','2'], true)) {
      return $toIntra[$cfop] ?? (($prefix === '6' ? '5' : '1') . substr($cfop, 1));
    }
    return $cfop;
  }
}


if (!function_exists('fiscal_nfce_add_ibs_cbs_xml')) {
  /**
   * Adiciona o grupo IBSCBS ao item fiscal.
   * Nesta versão o grupo é obrigatório: CST e cClassTrib ausentes interrompem
   * a geração, evitando XML sem IBS/CBS ou totalizadores desacompanhados dos itens.
   */
  function fiscal_nfce_add_ibs_cbs_xml(DOMDocument $dom, Closure $add, DOMElement $parent, array $tax, callable $fmt, callable $fmt4): bool {
    $extra = fiscal_nfce_tax_extra_json($tax);
    $classCode = substr(preg_replace('/\D+/', '', (string)($extra['cClassTrib'] ?? $extra['class_code'] ?? '')) ?: '', 0, 6);
    $cstDigits = preg_replace('/\D+/', '', (string)($tax['cst'] ?? $extra['CST'] ?? '')) ?: '';
    $cst = $cstDigits !== '' ? str_pad(substr($cstDigits, 0, 3), 3, '0', STR_PAD_LEFT) : ($classCode !== '' ? substr($classCode, 0, 3) : '');
    if ($classCode === '' || strlen($classCode) !== 6 || $cst === '') {
      throw new RuntimeException('IBS/CBS obrigatório: informe um cClassTrib válido com 6 dígitos e o respectivo CST para todos os itens.');
    }
    if (substr($classCode, 0, 3) !== $cst) {
      throw new RuntimeException('IBS/CBS inválido: o CST ' . $cst . ' não corresponde ao cClassTrib ' . $classCode . '.');
    }

    $base = max(0.0, (float)($tax['base_calc'] ?? $extra['base_calc'] ?? 0));
    $ibsUfRate = max(0.0, (float)($extra['ibs_uf_rate'] ?? 0));
    $ibsMunRate = max(0.0, (float)($extra['ibs_mun_rate'] ?? 0));
    $cbsRate = max(0.0, (float)($extra['cbs_rate'] ?? 0));
    $ibsRed = max(0.0, min(100.0, (float)($extra['ibs_reduction_rate'] ?? 0)));
    $cbsRed = max(0.0, min(100.0, (float)($extra['cbs_reduction_rate'] ?? 0)));
    $ibsUfEffective = max(0.0, (float)($extra['ibs_uf_effective_rate'] ?? ($ibsUfRate * (1 - $ibsRed / 100))));
    $ibsMunEffective = max(0.0, (float)($extra['ibs_mun_effective_rate'] ?? ($ibsMunRate * (1 - $ibsRed / 100))));
    $cbsEffective = max(0.0, (float)($extra['cbs_effective_rate'] ?? ($cbsRate * (1 - $cbsRed / 100))));
    $ibsUfValue = max(0.0, (float)($extra['ibs_uf_amount'] ?? round($base * $ibsUfEffective / 100, 2)));
    $ibsMunValue = max(0.0, (float)($extra['ibs_mun_amount'] ?? round($base * $ibsMunEffective / 100, 2)));
    $cbsValue = max(0.0, (float)($extra['cbs_amount'] ?? round($base * $cbsEffective / 100, 2)));
    $ibsValue = max(0.0, (float)($extra['ibs_amount'] ?? ($ibsUfValue + $ibsMunValue)));

    $ibscbs = $add($parent, 'IBSCBS', '');
    $add($ibscbs, 'CST', $cst);
    $add($ibscbs, 'cClassTrib', $classCode);
    $g = $add($ibscbs, 'gIBSCBS', '');
    $add($g, 'vBC', $fmt($base));

    $gUf = $add($g, 'gIBSUF', '');
    $add($gUf, 'pIBSUF', $fmt4($ibsUfRate));
    if ($ibsRed > 0) {
      $gRed = $add($gUf, 'gRed', '');
      $add($gRed, 'pRedAliq', $fmt4($ibsRed));
      $add($gRed, 'pAliqEfet', $fmt4($ibsUfEffective));
    }
    $add($gUf, 'vIBSUF', $fmt($ibsUfValue));

    $gMun = $add($g, 'gIBSMun', '');
    $add($gMun, 'pIBSMun', $fmt4($ibsMunRate));
    if ($ibsRed > 0) {
      $gRed = $add($gMun, 'gRed', '');
      $add($gRed, 'pRedAliq', $fmt4($ibsRed));
      $add($gRed, 'pAliqEfet', $fmt4($ibsMunEffective));
    }
    $add($gMun, 'vIBSMun', $fmt($ibsMunValue));

    $add($g, 'vIBS', $fmt($ibsValue));
    $gCbs = $add($g, 'gCBS', '');
    $add($gCbs, 'pCBS', $fmt4($cbsRate));
    if ($cbsRed > 0) {
      $gRed = $add($gCbs, 'gRed', '');
      $add($gRed, 'pRedAliq', $fmt4($cbsRed));
      $add($gRed, 'pAliqEfet', $fmt4($cbsEffective));
    }
    $add($gCbs, 'vCBS', $fmt($cbsValue));
    return true;
  }
}


if (!function_exists('fiscal_nfce_ibpt_is_imported_origin')) {
  function fiscal_nfce_ibpt_is_imported_origin(string $originCode): bool {
    $originCode = preg_replace('/\D+/', '', $originCode) ?: '0';
    return in_array($originCode, ['1','2','6','7'], true);
  }
}

if (!function_exists('fiscal_nfce_ibpt_resolve_uf')) {
  function fiscal_nfce_ibpt_resolve_uf(PDO $pdo, int $companyId, string $fallbackUf = ''): string {
    $normalize = static function($value): string {
      return strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)$value), 0, 2));
    };
    $uf = '';
    try {
      if (function_exists('fiscal_company_ibpt_uf')) $uf = $normalize(fiscal_company_ibpt_uf($pdo, $companyId));
    } catch (Throwable $e) {}
    if ($uf === '') {
      try {
        if (fiscal_table_exists($pdo, 'fiscal_company_tax_settings')) {
          $st = $pdo->prepare('SELECT ibpt_uf FROM fiscal_company_tax_settings WHERE company_id=? LIMIT 1');
          $st->execute([$companyId]);
          $uf = $normalize($st->fetchColumn());
        }
      } catch (Throwable $e) {}
    }
    if ($uf === '') {
      try {
        if (fiscal_table_exists($pdo, 'fiscal_company_config')) {
          $st = $pdo->prepare('SELECT uf FROM fiscal_company_config WHERE company_id=? LIMIT 1');
          $st->execute([$companyId]);
          $uf = $normalize($st->fetchColumn());
        }
      } catch (Throwable $e) {}
    }
    if ($uf === '') $uf = $normalize($fallbackUf);
    return $uf;
  }
}

if (!function_exists('fiscal_nfce_ibpt_rate_map')) {
  /**
   * Carrega em uma única consulta as alíquotas IBPT vigentes dos NCMs do documento.
   * Usa fiscal_ibpt_rates como catálogo principal e, para instalações legadas,
   * completa códigos ausentes com as alíquotas globais ainda gravadas em fiscal_ncms.
   */
  function fiscal_nfce_ibpt_rate_map(PDO $pdo, int $companyId, array $items, string $issueDate, string $fallbackUf = ''): array {
    $uf = fiscal_nfce_ibpt_resolve_uf($pdo, $companyId, $fallbackUf);
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $issueDate, $m) ? $m[0] : date('Y-m-d');
    $codes = [];
    foreach ($items as $item) {
      $ncm = preg_replace('/\D+/', '', (string)($item['ncm'] ?? '')) ?: '';
      if (strlen($ncm) === 8) $codes[$ncm] = true;
    }
    if ($uf === '' || !$codes) return [];

    $list = array_keys($codes);
    $map = [];

    // V11.44 R6: o IBPT é catálogo GLOBAL administrado pela Central SaaS.
    // Prioriza o helper oficial do catálogo para manter a mesma regra usada nas
    // telas NCM/IBPT e na Central SaaS, sem exigir qualquer importação por empresa.
    if (function_exists('fiscal_lookup_ibpt_rate')) {
      foreach ($list as $code) {
        try {
          $row = fiscal_lookup_ibpt_rate($pdo, $companyId, $code, $date, 'NCM', '');
          if (is_array($row) && $row) $map[$code] = $row;
        } catch (Throwable $e) {
          error_log('[Nexa Fiscal][IBPT] Falha no catálogo global SaaS para NCM ' . $code . ': ' . $e->getMessage());
        }
      }
    }

    // Fallback direto no mesmo catálogo global. Também cobre instalações em que a
    // UF foi resolvida pelo cadastro fiscal da empresa antes de fiscal_company_tax_settings.
    if (count($map) < count($list) && fiscal_table_exists($pdo, 'fiscal_ibpt_rates')) {
      $pending = array_values(array_diff($list, array_keys($map)));
      $ph = implode(',', array_fill(0, count($pending), '?'));
      try {
        $st = $pdo->prepare("SELECT * FROM fiscal_ibpt_rates
          WHERE UPPER(uf)=? AND UPPER(code_type)='NCM' AND code IN ({$ph}) AND COALESCE(NULLIF(TRIM(ex_code),'0'),'')='' AND is_active=1
            AND validity_start<=? AND (validity_end IS NULL OR validity_end>=?)
          ORDER BY code ASC, validity_start DESC, id DESC");
        $st->execute(array_merge([$uf], $pending, [$date, $date]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $code = preg_replace('/\D+/', '', (string)($row['code'] ?? '')) ?: '';
          if ($code !== '' && !isset($map[$code])) $map[$code] = $row;
        }
      } catch (Throwable $e) {
        error_log('[Nexa Fiscal][IBPT] Falha ao consultar fiscal_ibpt_rates: ' . $e->getMessage());
      }
    }

    // Compatibilidade com bases em que o IBPT ainda está incorporado ao catálogo NCM.
    $missing = array_values(array_diff($list, array_keys($map)));
    if ($missing && fiscal_table_exists($pdo, 'fiscal_ncms')) {
      $ph = implode(',', array_fill(0, count($missing), '?'));
      try {
        $st = $pdo->prepare("SELECT ncm AS code, description,
            ibpt_federal_national AS federal_national,
            ibpt_federal_imported AS federal_imported,
            ibpt_state AS state_rate,
            ibpt_municipal AS municipal_rate,
            COALESCE(ibpt_version,'') AS version,
            validity_start, validity_end, COALESCE(source,'IBPT/NCM legado') AS source
          FROM fiscal_ncms
          WHERE company_id=0 AND ncm IN ({$ph}) AND is_active=1
            AND (validity_start IS NULL OR validity_start<=?)
            AND (validity_end IS NULL OR validity_end>=?)
          ORDER BY ncm ASC, id DESC");
        $st->execute(array_merge($missing, [$date, $date]));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $code = preg_replace('/\D+/', '', (string)($row['code'] ?? '')) ?: '';
          if ($code === '' || isset($map[$code])) continue;
          $hasRate = (float)($row['federal_national'] ?? 0) > 0
            || (float)($row['federal_imported'] ?? 0) > 0
            || (float)($row['state_rate'] ?? 0) > 0
            || (float)($row['municipal_rate'] ?? 0) > 0;
          if ($hasRate) $map[$code] = $row;
        }
      } catch (Throwable $e) {
        error_log('[Nexa Fiscal][IBPT] Falha no fallback fiscal_ncms: ' . $e->getMessage());
      }
    }

    return $map;
  }
}

if (!function_exists('fiscal_nfce_ibpt_item_values')) {
  function fiscal_nfce_ibpt_item_values(float $base, array $rate, string $originCode): array {
    $base = max(0.0, $base);
    $federalRate = fiscal_nfce_ibpt_is_imported_origin($originCode)
      ? (float)($rate['federal_imported'] ?? 0)
      : (float)($rate['federal_national'] ?? 0);
    $stateRate = (float)($rate['state_rate'] ?? 0);
    $municipalRate = (float)($rate['municipal_rate'] ?? 0);
    $federal = round($base * max(0.0, $federalRate) / 100, 2);
    $state = round($base * max(0.0, $stateRate) / 100, 2);
    $municipal = round($base * max(0.0, $municipalRate) / 100, 2);
    return [
      'base' => round($base, 2),
      'federal_rate' => $federalRate,
      'state_rate' => $stateRate,
      'municipal_rate' => $municipalRate,
      'federal' => $federal,
      'state' => $state,
      'municipal' => $municipal,
      'total' => round($federal + $state + $municipal, 2),
    ];
  }
}

if (!function_exists('fiscal_nfce_ibpt_text_money')) {
  function fiscal_nfce_ibpt_text_money(float $value): string {
    return number_format($value, 2, ',', '.');
  }
}

if (!function_exists('fiscal_nfce_ibpt_document_totals')) {
  function fiscal_nfce_ibpt_document_totals(PDO $pdo, int $companyId, int $docId, string $issueDate = '', string $fallbackUf = ''): array {
    $out = [
      'present'=>false, 'uf'=>'', 'federal'=>0.0, 'state'=>0.0, 'municipal'=>0.0,
      'total'=>0.0, 'source'=>'', 'matched_items'=>0, 'missing_items'=>0, 'details'=>[],
    ];
    try {
      $items = fiscal_nfce_document_items($pdo, $companyId, $docId);
      if (!$items) return $out;

      // V11.44 R6: alguns documentos antigos podem não ter o NCM snapshotado em
      // fiscal_document_items. Para a informação aproximada da Lei 12.741/2012,
      // recupera apenas o NCM ausente a partir do cadastro fiscal atual do produto.
      // O XML autorizado não é alterado; este fallback é exclusivo da impressão.
      foreach ($items as &$ibptItem) {
        $ncmSnapshot = preg_replace('/\D+/', '', (string)($ibptItem['ncm'] ?? '')) ?: '';
        if (strlen($ncmSnapshot) === 8) continue;
        $productId = (int)($ibptItem['product_id'] ?? 0);
        if ($productId <= 0 || !function_exists('fiscal_product_tax')) continue;
        try {
          $taxCurrent = fiscal_product_tax($pdo, $companyId, $productId);
          $ncmCurrent = preg_replace('/\D+/', '', (string)($taxCurrent['ncm'] ?? '')) ?: '';
          if (strlen($ncmCurrent) === 8) $ibptItem['ncm'] = $ncmCurrent;
        } catch (Throwable $e) {}
      }
      unset($ibptItem);

      $out['uf'] = fiscal_nfce_ibpt_resolve_uf($pdo, $companyId, $fallbackUf);
      $map = fiscal_nfce_ibpt_rate_map($pdo, $companyId, $items, $issueDate !== '' ? $issueDate : date('Y-m-d'), $fallbackUf);

      $origins = [];
      if (fiscal_table_exists($pdo, 'fiscal_document_item_taxes')) {
        $st = $pdo->prepare("SELECT fiscal_document_item_id, origin_code
          FROM fiscal_document_item_taxes
          WHERE company_id=? AND fiscal_document_id=? AND tax_group='ICMS'
          ORDER BY id ASC");
        $st->execute([$companyId, $docId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
          $itemId = (int)($row['fiscal_document_item_id'] ?? 0);
          if ($itemId > 0 && !isset($origins[$itemId])) $origins[$itemId] = (string)($row['origin_code'] ?? '0');
        }
      }

      $sources = [];
      foreach ($items as $item) {
        $itemId = (int)($item['id'] ?? 0);
        $ncm = preg_replace('/\D+/', '', (string)($item['ncm'] ?? '')) ?: '';
        $rate = $map[$ncm] ?? null;
        if (!is_array($rate)) {
          $out['missing_items']++;
          $out['details'][] = ['item_id'=>$itemId, 'ncm'=>$ncm, 'matched'=>false];
          continue;
        }
        $base = max(0.0,
          (float)($item['total_value'] ?? 0)
          - max(0.0, (float)($item['discount_value'] ?? 0))
          + max(0.0, (float)($item['other_value'] ?? 0))
          + max(0.0, (float)($item['freight_value'] ?? 0))
        );
        $values = fiscal_nfce_ibpt_item_values($base, $rate, $origins[$itemId] ?? '0');
        $out['federal'] += $values['federal'];
        $out['state'] += $values['state'];
        $out['municipal'] += $values['municipal'];
        $out['total'] += $values['total'];
        $out['matched_items']++;
        $source = trim((string)($rate['source'] ?? 'IBPT')) ?: 'IBPT';
        $version = trim((string)($rate['version'] ?? ''));
        $sources[$source . ($version !== '' ? ' ' . $version : '')] = true;
        $out['details'][] = [
          'item_id'=>$itemId, 'ncm'=>$ncm, 'matched'=>true, 'base'=>$values['base'],
          'federal_rate'=>$values['federal_rate'], 'state_rate'=>$values['state_rate'],
          'municipal_rate'=>$values['municipal_rate'], 'total'=>$values['total'],
        ];
      }
      foreach (['federal','state','municipal','total'] as $key) $out[$key] = round((float)$out[$key], 2);
      $out['present'] = $out['matched_items'] > 0;
      $out['source'] = implode(', ', array_keys($sources));
      return $out;
    } catch (Throwable $e) {
      error_log('[Nexa Fiscal][IBPT] Falha ao calcular documento #' . $docId . ': ' . $e->getMessage());
      return $out;
    }
  }
}

if (!function_exists('fiscal_nfce_build_draft_xml')) {
  function fiscal_nfce_build_draft_xml(PDO $pdo, int $companyId, int $docId): string {
    $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
    $cfg = fiscal_company_config($pdo, $companyId);
    $model = preg_replace('/\D+/', '', (string)($doc['model'] ?? '65')) ?: '65';
    if (!in_array($model, ['55','65'], true)) $model = '65';
    $isNfce = $model === '65';
    $docLabel = fiscal_doc_model_label($model);
    $items = fiscal_nfce_document_items($pdo, $companyId, $docId);
    $payments = fiscal_nfce_document_payments($pdo, $companyId, $docId);
    $destPart = fiscal_nfce_document_participant($pdo, $companyId, $docId, 'DESTINATARIO');
    $destPart = fiscal_nfce_enrich_person_participant($pdo, $companyId, $destPart, (int)($doc['customer_person_id'] ?? 0));
    $issuerIe = fiscal_nfce_issuer_ie($pdo, $companyId, $cfg);
    $detailedMeta = [];
    if ($model === '55' && strtoupper((string)($doc['source_type'] ?? '')) === 'PEDIDO' && (int)($doc['source_id'] ?? 0) > 0 && function_exists('detailed_order_meta')) {
      try { $detailedMeta = detailed_order_meta($pdo, $companyId, (int)$doc['source_id']); } catch (Throwable $e) { $detailedMeta = []; }
    }
    $detailedFiscalOrderNote = '';
    $detailedFiscalSource = false;
    if ($model === '55' && $detailedMeta) {
      require_once __DIR__ . '/detailed_order_fiscal_notes.php';
      $noteOrder = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $noteOrder->execute([$companyId, (int)$doc['source_id']]);
      $noteSource = $noteOrder->fetch(PDO::FETCH_ASSOC) ?: [];
      if (strtolower(trim((string)($noteSource['source'] ?? ''))) === 'pedido_detalhado') {
        $detailedFiscalSource = true;
        $detailedFiscalOrderNote = detailed_order_fiscal_observation($noteSource, false);
      }
    }
    $useDetailedFreight = $model === '55' && $detailedMeta && in_array((string)($detailedMeta['freight_mode'] ?? '9'), ['0','1','2','3','4'], true);
    // IPI em pedidos de venda fica restrito ao Pedido Detalhado + NF-e modelo 55.
    // NFC-e (modelo 65) nunca pode serializar grupo IPI. Pedidos comuns mantêm o
    // comportamento histórico do Nexa, sem IPI nessa rotina. Documentos manuais
    // modelo 55 continuam podendo usar IPI quando a operação fiscal exigir.
    $isPedidoFiscalDoc = strtoupper((string)($doc['source_type'] ?? '')) === 'PEDIDO';
    $allowIpiXml = $model === '55' && (!$isPedidoFiscalDoc || !empty($detailedMeta));
    $destUfForIde = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($destPart['state'] ?? '')), 0, 2));
    $emitUfForIde = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? 'MG')), 0, 2)) ?: 'MG';
    $idDest = ($model === '55' && $destUfForIde !== '' && $destUfForIde !== $emitUfForIde) ? '2' : '1';
    $accessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
    $infId = $accessKey !== '' ? 'NFe' . $accessKey : 'NFePRE' . str_pad((string)$docId, 12, '0', STR_PAD_LEFT);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;
    $nfe = $dom->createElement('NFe');
    $nfe->setAttribute('xmlns', 'http://www.portalfiscal.inf.br/nfe');
    $dom->appendChild($nfe);
    $inf = $dom->createElement('infNFe');
    $inf->setAttribute('Id', $infId);
    $inf->setAttribute('versao', '4.00');
    $nfe->appendChild($inf);
    $add = function(DOMElement $parent, string $name, $value) use ($dom): DOMElement { $el = $dom->createElement($name); $el->appendChild($dom->createTextNode((string)$value)); $parent->appendChild($el); return $el; };
    $fmt = fn($v) => number_format((float)$v, 2, '.', '');
    $fmt4 = fn($v) => number_format((float)$v, 4, '.', '');
    $cfgEnv = ((string)($doc['environment'] ?? 'HOMOLOGACAO') === 'PRODUCAO') ? '1' : '2';
    $emissionType = strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL')));
    $tpEmisMap = ['NORMAL'=>'1','NFC_OFFLINE'=>'9','OFFLINE'=>'9','SVCAN'=>'6','SVC-AN'=>'6','SVCRS'=>'7','SVC-RS'=>'7','EPEC'=>'4','FS_DA'=>'5','FS-DA'=>'5'];
    $tpEmis = $tpEmisMap[$emissionType] ?? '1';
    $isContingencyXml = $tpEmis !== '1';
    $ide = $add($inf, 'ide', '');
    $accessKeyOk = strlen($accessKey) === 44;
    $add($ide, 'cUF', $accessKeyOk ? substr($accessKey, 0, 2) : fiscal_nfce_uf_code((string)($cfg['uf'] ?? 'MG')));
    // cNF ocupa as posições 36 a 43 da chave de acesso (índice 35, tamanho 8).
    // Não pode ser obtido com substr(-8), pois isso inclui o dígito verificador e gera rejeição 502.
    $add($ide, 'cNF', $accessKeyOk ? substr($accessKey, 35, 8) : substr(str_pad((string)$docId, 8, '0', STR_PAD_LEFT), -8));
    $add($ide, 'natOp', fiscal_nfce_safe_xml_text($doc['operation_nature'] ?? ($model === '55' ? 'VENDA NF-E' : 'VENDA NFC-E'), 60));
    $add($ide, 'mod', $model);
    $add($ide, 'serie', preg_replace('/\D+/', '', (string)($doc['series'] ?? '1')) ?: '1');
    $add($ide, 'nNF', (string)(int)($doc['number'] ?? 0));
    $add($ide, 'dhEmi', date('c', strtotime((string)($doc['issue_datetime'] ?? 'now')) ?: time()));
    $referenceKeys = [];
    if ($model === '55' && function_exists('fiscal_document_reference_rows')) {
      try {
        foreach (fiscal_document_reference_rows($pdo, $companyId, $docId) as $refRow) {
          $rk = preg_replace('/\D+/', '', (string)($refRow['reference_key'] ?? ''));
          if (strlen($rk) === 44) $referenceKeys[$rk] = $rk;
        }
      } catch (Throwable $e) {}
    }
    if ($model === '55' && strtoupper((string)($doc['source_type'] ?? '')) === 'PEDIDO' && (int)($doc['source_id'] ?? 0) > 0) {
      $refNfce = fiscal_document_order_authorized($pdo, $companyId, (int)$doc['source_id'], '65');
      $refKey = $refNfce ? preg_replace('/\D+/', '', (string)($refNfce['access_key'] ?? '')) : '';
      if (strlen($refKey) === 44) $referenceKeys[$refKey] = $refKey;
    }
    // As notas referenciadas (NFref) pertencem ao grupo ide, mas no leiaute 4.00
    // devem ser escritas somente depois de procEmi/verProc. Se forem inseridas logo
    // após dhEmi, o XML fica fora da sequência do XSD e gera erro de validação.
    $isEntradaDoc = strtoupper((string)($doc['direction'] ?? 'SAIDA')) === 'ENTRADA';
    $sourceTypeDoc = strtoupper((string)($doc['source_type'] ?? ''));
    $natureUpper = strtoupper(fiscal_sintegra_ascii((string)($doc['operation_nature'] ?? '')));
    $finNFe = (str_contains($sourceTypeDoc, 'DEVOLUCAO') || str_contains($natureUpper, 'DEVOLUCAO')) ? '4' : '1';
    $add($ide, 'tpNF', $isEntradaDoc ? '0' : '1');
    $add($ide, 'idDest', $idDest);
    $add($ide, 'cMunFG', fiscal_digits($cfg['city_ibge'] ?? ''));
    $add($ide, 'tpImp', $isNfce ? '4' : '1');
    $add($ide, 'tpEmis', $tpEmis);
    $add($ide, 'cDV', $accessKeyOk ? substr($accessKey, 43, 1) : '0');
    $add($ide, 'tpAmb', $cfgEnv);
    $add($ide, 'finNFe', $finNFe);
    $add($ide, 'indFinal', '1');
    // Delivery/NFC-e continua tratando taxa de entrega como vOutro.
    // Pedido Detalhado NF-e pode emitir frete real em vFrete e transportador.
    $nfceFreightAsOther = $useDetailedFreight ? 0.0 : max(0.0, (float)($doc['total_freight'] ?? 0));
    $nfceTotalOtherXml = max(0.0, (float)($doc['total_other'] ?? 0)) + $nfceFreightAsOther;
    $indPres = $isNfce ? '1' : '9';
    $add($ide, 'indPres', $indPres);
    // Rejeição 434: para operações não presenciais (indPres 2, 3, 4 ou 9),
    // o XML precisa informar o indicador de intermediador/marketplace.
    // O delivery/site próprio do sistema é operação sem intermediador.
    if (in_array($indPres, ['2','3','4','9'], true)) {
      $add($ide, 'indIntermed', '0');
    }
    $add($ide, 'procEmi', '0');
    $add($ide, 'verProc', $isNfce ? 'NexaProspero-PreNFCe' : 'NexaProspero-PreNFe');
    if ($isContingencyXml) {
      $contStartedRaw = trim((string)($doc['contingency_started_at'] ?? ''));
      $contStartedTs = $contStartedRaw !== '' ? strtotime($contStartedRaw) : false;
      if ($contStartedTs === false) $contStartedTs = strtotime((string)($doc['issue_datetime'] ?? 'now')) ?: time();
      $contReason = trim((string)($doc['contingency_reason'] ?? $cfg['contingency_reason'] ?? 'Falha de comunicação com o ambiente autorizador da SEFAZ.'));
      if (function_exists('mb_strlen') && mb_strlen($contReason) < 15) $contReason = 'Falha de comunicação com o ambiente autorizador da SEFAZ.';
      $add($ide, 'dhCont', date('c', $contStartedTs));
      $add($ide, 'xJust', fiscal_nfce_safe_xml_text($contReason, 255));
    }
    $referenceInfoLines = [];
    foreach ($referenceKeys as $refKey) {
      $refModel = substr($refKey, 20, 2);
      if ($refModel === '55') {
        $nfref = $add($ide, 'NFref', '');
        $add($nfref, 'refNFe', $refKey);
      } elseif ($refModel === '65') {
        // Rejeição 679: algumas SEFAZ não aceitam chave de NFC-e (modelo 65)
        // no grupo NFref/refNFe da NF-e. Mantemos o vínculo no banco e
        // documentamos a NFC-e em informações complementares.
        $referenceInfoLines[] = 'NFC-e de origem vinculada a esta NF-e: ' . $refKey;
      }
    }

    $emit = $add($inf, 'emit', '');
    $add($emit, 'CNPJ', fiscal_digits($cfg['company_cnpj'] ?? ''));
    $add($emit, 'xNome', fiscal_nfce_safe_xml_text(fiscal_nfce_company_display_name($pdo, $companyId, $cfg), 60));
    $ender = $add($emit, 'enderEmit', '');
    $add($ender, 'xLgr', 'NAO INFORMADO');
    $add($ender, 'nro', '0');
    $add($ender, 'xBairro', 'NAO INFORMADO');
    $add($ender, 'cMun', fiscal_digits($cfg['city_ibge'] ?? ''));
    $add($ender, 'xMun', 'NAO INFORMADO');
    $add($ender, 'UF', strtoupper((string)($cfg['uf'] ?? 'MG')));
    $add($ender, 'CEP', '00000000');
    $add($ender, 'cPais', '1058');
    $add($ender, 'xPais', 'BRASIL');
    $add($emit, 'IE', $issuerIe !== '' ? $issuerIe : 'ISENTO');
    $add($emit, 'CRT', preg_replace('/\D+/', '', (string)($cfg['crt'] ?? '1')) ?: '1');

    // Destinatário na NFC-e só é enviado quando houver CPF/CNPJ informado,
    // sem depender de taxa de entrega. A taxa de entrega permanece como acréscimo.
    $destDoc = fiscal_digits($destPart['document'] ?? '');
    $destName = fiscal_nfce_safe_xml_text($destPart['name'] ?? '', 60);
    if (in_array(strlen($destDoc), [11, 14], true) && $destName !== '') {
      $dest = $add($inf, 'dest', '');
      $add($dest, strlen($destDoc) === 14 ? 'CNPJ' : 'CPF', $destDoc);
      // Em homologação, a SEFAZ exige que o xNome do destinatário seja exatamente
      // a literal abaixo. Mantemos o nome real no banco/tela e alteramos apenas o XML.
      $homologationDestName = 'NF-E EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
      $add($dest, 'xNome', $cfgEnv === '2' ? $homologationDestName : $destName);
      if ($model === '55') {
        $destAddr = fiscal_nfce_clean_address_parts($destPart, $cfg);
        $enderDest = $add($dest, 'enderDest', '');
        $add($enderDest, 'xLgr', $destAddr['address']);
        $add($enderDest, 'nro', $destAddr['number']);
        if ($destAddr['complement'] !== '') $add($enderDest, 'xCpl', $destAddr['complement']);
        $add($enderDest, 'xBairro', $destAddr['district']);
        $add($enderDest, 'cMun', $destAddr['city_ibge']);
        $add($enderDest, 'xMun', $destAddr['city']);
        $add($enderDest, 'UF', $destAddr['state']);
        $add($enderDest, 'CEP', $destAddr['zip_code']);
        $add($enderDest, 'cPais', '1058');
        $add($enderDest, 'xPais', 'BRASIL');
      }
      $destIe = strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', (string)($destPart['state_registration'] ?? '')) ?: '');
      if ($destIe === '' && $model === '55' && $sourceTypeDoc === 'SAIDA_MANUAL_COMPRA' && $referenceKeys && fiscal_table_exists($pdo, 'fiscal_xml_imports')) {
        try {
          $ieLookup = $pdo->prepare('SELECT supplier_ie FROM fiscal_xml_imports WHERE company_id=? AND access_key=? ORDER BY id DESC LIMIT 1');
          foreach ($referenceKeys as $referenceKeyForIe) {
            $ieLookup->execute([$companyId, $referenceKeyForIe]);
            $foundIe = strtoupper(preg_replace('/[^0-9A-Za-z]+/', '', (string)($ieLookup->fetchColumn() ?: '')) ?: '');
            if ($foundIe !== '') { $destIe = $foundIe; break; }
          }
        } catch (Throwable $e) {}
      }
      // NFC-e modelo 65: regra E16a-10 / rejeição 789.
      // O destinatário deve ser informado como Não Contribuinte (indIEDest=9),
      // mesmo quando o cadastro do cliente possuir IE. Na NFC-e não serializamos
      // a tag IE do destinatário. A NF-e modelo 55 preserva a classificação fiscal
      // normal conforme a IE cadastrada.
      if ($model === '65') {
        $add($dest, 'indIEDest', '9');
      } elseif (strlen($destDoc) === 14 && $destIe !== '' && $destIe !== 'ISENTO') {
        $add($dest, 'indIEDest', '1');
        $add($dest, 'IE', $destIe);
      } elseif (strlen($destDoc) === 14 && $destIe === 'ISENTO') {
        $add($dest, 'indIEDest', '2');
      } else {
        $add($dest, 'indIEDest', '9');
      }
    }

    $rtcIssueDate = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? date('Y-m-d'));
    $rtcIssueYear = preg_match('/^(\d{4})/', $rtcIssueDate, $rtcYearMatch) ? (int)$rtcYearMatch[1] : (int)date('Y');
    $rtcTotalItems = 0.0;
    // Quantidade real de itens em que o grupo <IBSCBS> foi serializado.
    // Não usar apenas a quantidade de registros da tabela fiscal, pois registros
    // antigos/incompletos podem não possuir CST ou cClassTrib e são omitidos do item.
    $rtcIbsCbsXmlItemCount = 0;

    // Lei 12.741/2012: estimativa da carga tributária pelo catálogo IBPT vigente.
    // A tabela é global, mas a UF utilizada é a configuração individual da empresa.
    $ibptRateMap = fiscal_nfce_ibpt_rate_map($pdo, $companyId, $items, $rtcIssueDate, (string)($cfg['uf'] ?? ''));
    $ibptTotals = ['federal'=>0.0, 'state'=>0.0, 'municipal'=>0.0, 'total'=>0.0, 'items'=>0];
    $ibptSources = [];

    $i = 0;
    foreach ($items as $row) {
      $i++;
      $det = $add($inf, 'det', ''); $det->setAttribute('nItem', (string)$i);
      $prod = $add($det, 'prod', '');
      $code = fiscal_nfce_safe_xml_text($row['product_code'] ?? $row['product_id'] ?? $i, 60);
      $add($prod, 'cProd', $code !== '' ? $code : (string)$i);
      $add($prod, 'cEAN', 'SEM GTIN');
      // Regra de homologação: quando tpAmb=2, a SEFAZ exige que a descrição do
      // primeiro item seja exatamente este texto. Mantemos a descrição real do
      // produto no banco/tela, alterando apenas o XML enviado.
      $homologationXProd = 'NOTA FISCAL EMITIDA EM AMBIENTE DE HOMOLOGACAO - SEM VALOR FISCAL';
      $xProd = ($cfgEnv === '2' && $i === 1)
        ? $homologationXProd
        : fiscal_nfce_safe_xml_text($row['description'] ?? '', 120);
      $add($prod, 'xProd', $xProd);
      $add($prod, 'NCM', preg_replace('/\D+/', '', (string)($row['ncm'] ?? '')) ?: '00000000');
      $cest = preg_replace('/\D+/', '', (string)($row['cest'] ?? '')); if ($cest !== '') $add($prod, 'CEST', $cest);
      $itemCfop = preg_replace('/\D+/', '', (string)($row['cfop'] ?? '5102')) ?: '5102';
      $itemCfop = fiscal_cfop_normalize_direction($itemCfop, $isEntradaDoc ? 'ENTRADA' : 'SAIDA', $idDest === '1');
      $add($prod, 'CFOP', $itemCfop !== '' ? $itemCfop : ($isEntradaDoc ? ($idDest === '1' ? '1202' : '2202') : ($idDest === '1' ? '5202' : '6202')));
      $add($prod, 'uCom', fiscal_nfce_safe_xml_text($row['unidade'] ?? 'UN', 6) ?: 'UN');
      $add($prod, 'qCom', $fmt4($row['quantity'] ?? 0));
      $add($prod, 'vUnCom', $fmt((float)($row['unit_value'] ?? 0)));
      $add($prod, 'vProd', $fmt($row['total_value'] ?? 0));
      $add($prod, 'cEANTrib', 'SEM GTIN');
      $add($prod, 'uTrib', fiscal_nfce_safe_xml_text($row['unidade_trib'] ?? $row['unidade'] ?? 'UN', 6) ?: 'UN');
      $add($prod, 'qTrib', $fmt4($row['quantity_trib'] ?? $row['quantity'] ?? 0));
      $add($prod, 'vUnTrib', $fmt((float)($row['unit_value_trib'] ?? $row['unit_value'] ?? 0)));
      if ((float)($row['discount_value'] ?? 0) > 0) $add($prod, 'vDesc', $fmt($row['discount_value']));
      if ($useDetailedFreight) {
        $itemFreightXml = max(0.0, (float)($row['freight_value'] ?? 0));
        if ($itemFreightXml > 0) $add($prod, 'vFrete', $fmt($itemFreightXml));
        $itemOtherXml = max(0.0, (float)($row['other_value'] ?? 0));
      } else {
        // Pedido delivery/NFC-e: taxa de entrega/frete permanece como acréscimo.
        $itemOtherXml = max(0.0, (float)($row['other_value'] ?? 0)) + max(0.0, (float)($row['freight_value'] ?? 0));
      }
      if ($itemOtherXml > 0) $add($prod, 'vOutro', $fmt($itemOtherXml));
      $add($prod, 'indTot', '1');

      $imp = $add($det, 'imposto', '');
      // A ordem dos grupos dentro de <imposto> precisa seguir o leiaute da NF-e.
      // Antes era usado ORDER BY tax_group, o que colocava COFINS antes de ICMS/PIS
      // e gerava XML fora do schema. Também, para PISAliq/COFINSAliq, o schema exige
      // vBC, pPIS/pCOFINS e vPIS/vCOFINS mesmo quando os valores forem zero.
      $taxSt = $pdo->prepare('SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_item_id=? ORDER BY id ASC');
      $taxSt->execute([$companyId, (int)$row['id']]);
      $taxRows = $taxSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $taxOrder = ['ICMS'=>10, 'IPI'=>20, 'II'=>30, 'PIS'=>40, 'PISST'=>50, 'COFINS'=>60, 'COFINSST'=>70, 'IBSCBS'=>80];
      usort($taxRows, function(array $a, array $b) use ($taxOrder): int {
        $ga = strtoupper((string)($a['tax_group'] ?? ''));
        $gb = strtoupper((string)($b['tax_group'] ?? ''));
        return ($taxOrder[$ga] ?? 999) <=> ($taxOrder[$gb] ?? 999);
      });

      $ibptOrigin = '0';
      foreach ($taxRows as $ibptTaxRow) {
        if (strtoupper((string)($ibptTaxRow['tax_group'] ?? '')) === 'ICMS') {
          $ibptOrigin = preg_replace('/\D+/', '', (string)($ibptTaxRow['origin_code'] ?? '0')) ?: '0';
          break;
        }
      }
      $ibptNcm = preg_replace('/\D+/', '', (string)($row['ncm'] ?? '')) ?: '';
      $ibptRate = $ibptRateMap[$ibptNcm] ?? null;
      if (is_array($ibptRate)) {
        $ibptBase = max(0.0,
          (float)($row['total_value'] ?? 0)
          - max(0.0, (float)($row['discount_value'] ?? 0))
          + $itemOtherXml
        );
        $ibptItem = fiscal_nfce_ibpt_item_values($ibptBase, $ibptRate, $ibptOrigin);
        $add($imp, 'vTotTrib', $fmt($ibptItem['total']));
        $ibptTotals['federal'] += $ibptItem['federal'];
        $ibptTotals['state'] += $ibptItem['state'];
        $ibptTotals['municipal'] += $ibptItem['municipal'];
        $ibptTotals['total'] += $ibptItem['total'];
        $ibptTotals['items']++;
        $sourceLabel = trim((string)($ibptRate['source'] ?? 'IBPT')) ?: 'IBPT';
        $versionLabel = trim((string)($ibptRate['version'] ?? ''));
        $ibptSources[$sourceLabel . ($versionLabel !== '' ? ' ' . $versionLabel : '')] = true;
      }

      $rtcItemIpi = 0.0;
      $rtcItemSt = 0.0;
      $rtcItemIi = 0.0;
      $rtcItemIs = 0.0;
      $rtcItemIbsCbs = 0.0;
      $rtcItemHasIbsCbs = false;
      $rtcItemIbsCbsGenerated = false;
      foreach ($taxRows as $tax) {
        $group = strtoupper((string)$tax['tax_group']);
        // Proteção contra rascunhos antigos: não serializa IPI fora do cenário
        // permitido, mesmo que exista uma linha IPI antiga no banco.
        if ($group === 'IPI' && !$allowIpiXml) continue;
        if ($group === 'IBSCBS') {
          $rtcItemHasIbsCbs = true;
          $rtcIbsExtra = fiscal_nfce_tax_extra_json($tax);
          $rtcItemIbsCbs += max(0.0, (float)($rtcIbsExtra['ibs_amount'] ?? 0));
          $rtcItemIbsCbs += max(0.0, (float)($rtcIbsExtra['cbs_amount'] ?? 0));
          if (fiscal_nfce_add_ibs_cbs_xml($dom, $add, $imp, $tax, $fmt, $fmt4)) {
            $rtcItemIbsCbsGenerated = true;
            $rtcIbsCbsXmlItemCount++;
          }
          continue;
        }
        if ($group === 'IPI') $rtcItemIpi += max(0.0, (float)($tax['amount'] ?? 0));
        if ($group === 'II') $rtcItemIi += max(0.0, (float)($tax['amount'] ?? 0));
        if ($group === 'IS') $rtcItemIs += max(0.0, (float)($tax['amount'] ?? 0));
        if ($group === 'IPI') {
          $ipiCst = str_pad(substr(preg_replace('/\D+/', '', (string)($tax['cst'] ?? '')) ?: '53', 0, 2), 2, '0', STR_PAD_LEFT);
          $ipiTag = fiscal_nfce_ipi_xml_tag($ipiCst);
          $ipiExtra = fiscal_nfce_tax_extra_json($tax);
          $ipiCenq = substr(preg_replace('/\D+/', '', (string)($tax['class_code'] ?? $ipiExtra['cEnq'] ?? $ipiExtra['cenq'] ?? '999')) ?: '999', 0, 3);
          $ipiGroup = $add($imp, 'IPI', '');
          $add($ipiGroup, 'cEnq', $ipiCenq);
          $ipiNode = $add($ipiGroup, $ipiTag, '');
          $add($ipiNode, 'CST', $ipiCst);
          if ($ipiTag === 'IPITrib') {
            $add($ipiNode, 'vBC', $fmt($tax['base_calc'] ?? 0));
            $add($ipiNode, 'pIPI', $fmt($tax['rate'] ?? 0));
            $add($ipiNode, 'vIPI', $fmt($tax['amount'] ?? 0));
          }
          continue;
        }
        $grp = $add($imp, $group === 'ICMS' ? 'ICMS' : $group, '');
        $tag = fiscal_nfce_safe_xml_text($tax['tax_tag'] ?: $group, 40);
        $csosnCode = '';
        $cstCode = '';
        if ($group === 'ICMS') {
          $csosnCode = trim((string)($tax['csosn'] ?? '')) !== '' ? preg_replace('/\D+/', '', (string)$tax['csosn']) : '';
          $cstCode = $csosnCode === '' ? str_pad(preg_replace('/\D+/', '', (string)($tax['cst'] ?? '00')), 2, '0', STR_PAD_LEFT) : '';
          $tag = fiscal_nfce_icms_xml_tag((string)($cfg['crt'] ?? '1'), $cstCode, $csosnCode);
        }
        $node = $add($grp, $tag, '');
        if ($group === 'ICMS') {
          $stItemExtra = fiscal_nfce_tax_extra_json($tax);
          if ((string)($stItemExtra['calc_version'] ?? '') === 'NEXA_ST_RECIPIENT_V1') {
            $rtcItemSt = (float)($stItemExtra['st_amount'] ?? 0);
          }
          $add($node, 'orig', preg_replace('/\D+/', '', (string)($tax['origin_code'] ?? '0')) ?: '0');
          if ($csosnCode !== '') $add($node, 'CSOSN', $csosnCode);
          else $add($node, 'CST', $cstCode);
          $allowsOwnBase = $csosnCode === '' || $tag === 'ICMSSN900';
          if ($allowsOwnBase && ((float)$tax['base_calc'] > 0 || (float)$tax['rate'] > 0)) {
            $add($node, 'modBC', '3');
            $add($node, 'vBC', $fmt($tax['base_calc']));
            $add($node, 'pICMS', $fmt($tax['rate']));
            $add($node, 'vICMS', $fmt($tax['amount']));
          }
          fiscal_nfce_add_icms_st_xml($add, $node, $tax, $cstCode, $csosnCode, $fmt, $fmt4);
        } elseif ($group === 'PIS') {
          $pisCst = str_pad(preg_replace('/\D+/', '', (string)($tax['cst'] ?? '01')) ?: '01', 2, '0', STR_PAD_LEFT);
          $add($node, 'CST', $pisCst);
          if ($tag === 'PISAliq' || in_array($pisCst, ['01','02'], true)) {
            $add($node, 'vBC', $fmt($tax['base_calc']));
            $add($node, 'pPIS', $fmt($tax['rate']));
            $add($node, 'vPIS', $fmt($tax['amount']));
          } elseif ($tag === 'PISOutr' || in_array($pisCst, ['49','50','51','52','53','54','55','56','60','61','62','63','64','65','66','67','70','71','72','73','74','75','98','99'], true)) {
            $add($node, 'vBC', $fmt($tax['base_calc']));
            $add($node, 'pPIS', $fmt($tax['rate']));
            $add($node, 'vPIS', $fmt($tax['amount']));
          }
        } elseif ($group === 'COFINS') {
          $cofCst = str_pad(preg_replace('/\D+/', '', (string)($tax['cst'] ?? '01')) ?: '01', 2, '0', STR_PAD_LEFT);
          $add($node, 'CST', $cofCst);
          if ($tag === 'COFINSAliq' || in_array($cofCst, ['01','02'], true)) {
            $add($node, 'vBC', $fmt($tax['base_calc']));
            $add($node, 'pCOFINS', $fmt($tax['rate']));
            $add($node, 'vCOFINS', $fmt($tax['amount']));
          } elseif ($tag === 'COFINSOutr' || in_array($cofCst, ['49','50','51','52','53','54','55','56','60','61','62','63','64','65','66','67','70','71','72','73','74','75','98','99'], true)) {
            $add($node, 'vBC', $fmt($tax['base_calc']));
            $add($node, 'pCOFINS', $fmt($tax['rate']));
            $add($node, 'vCOFINS', $fmt($tax['amount']));
          }
        } else {
          if (trim((string)($tax['cst'] ?? '')) !== '') $add($node, 'CST', str_pad(preg_replace('/\D+/', '', (string)$tax['cst']), 2, '0', STR_PAD_LEFT));
          if ((float)$tax['base_calc'] > 0 || (float)$tax['rate'] > 0) { $add($node, 'vBC', $fmt($tax['base_calc'])); $add($node, 'p' . $group, $fmt($tax['rate'])); $add($node, 'v' . $group, $fmt($tax['amount'])); }
        }
      }

      if (!$rtcItemHasIbsCbs || !$rtcItemIbsCbsGenerated) {
        $itemLabel = trim((string)($row['description'] ?? ''));
        if ($itemLabel === '') $itemLabel = trim((string)($row['product_code'] ?? 'Produto'));
        throw new RuntimeException(
          'IBS/CBS obrigatório no item ' . $i . ' (' . $itemLabel . '). '
          . 'Abra Editar e regenerar XML, informe CST, cClassTrib, base e alíquotas do IBS/CBS e salve novamente.'
        );
      }

      // Observação individual do Pedido Detalhado na tag fiscal própria do item.
      // Inserida depois do grupo imposto e antes de vItem, conforme a ordem do XML.
      // O modelo 65 (NFC-e) e as notas de outros módulos permanecem inalterados.
      if ($detailedFiscalSource) {
        $itemFiscalNote = detailed_order_fiscal_normalize_note($row['notes'] ?? '');
        if ($itemFiscalNote !== '') {
          $add($det, 'infAdProd', fiscal_nfce_safe_xml_text($itemFiscalNote, 500));
        }
      }

      // NT 2025.002-RTC: vItem representa a participação do item no total da nota.
      // Em 2025/2026, IBS, CBS e IS não compõem vItem. A partir de 2027, passam a compor.
      $rtcItemTotal = max(0.0,
        max(0.0, (float)($row['total_value'] ?? 0))
        - max(0.0, (float)($row['discount_value'] ?? 0))
        + $itemOtherXml
        + $rtcItemIi
        + $rtcItemIpi
        + $rtcItemSt
      );
      if ($rtcIssueYear > 2026) $rtcItemTotal += $rtcItemIbsCbs + $rtcItemIs;
      $rtcItemTotal = round($rtcItemTotal, 2);
      $add($det, 'vItem', $fmt($rtcItemTotal));
      $rtcTotalItems += $rtcItemTotal;
    }

    $totalIcmsBaseXml = 0.0;
    $totalIcmsValueXml = 0.0;
    $totalIcmsStBaseXml = 0.0;
    $totalIcmsStValueXml = 0.0;
    $totalFcpStXml = 0.0;
    try {
      $taxTotalsSt = $pdo->prepare("SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? AND tax_group='ICMS'");
      $taxTotalsSt->execute([$companyId, $docId]);
      foreach (($taxTotalsSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $txTot) {
        $txCsosn = trim((string)($txTot['csosn'] ?? '')) !== '' ? preg_replace('/\D+/', '', (string)$txTot['csosn']) : '';
        $txCst = $txCsosn === '' ? str_pad(preg_replace('/\D+/', '', (string)($txTot['cst'] ?? '00')), 2, '0', STR_PAD_LEFT) : '';
        $txTag = fiscal_nfce_icms_xml_tag((string)($cfg['crt'] ?? '1'), $txCst, $txCsosn);
        $txAllowsOwnBase = $txCsosn === '' || $txTag === 'ICMSSN900';
        if ($txAllowsOwnBase) {
          $totalIcmsBaseXml += max(0.0, (float)($txTot['base_calc'] ?? 0));
          $totalIcmsValueXml += max(0.0, (float)($txTot['amount'] ?? 0));
        }
        $extraTot = fiscal_nfce_tax_extra_json($txTot);
        $totalIcmsStBaseXml += max(0.0, (float)($extraTot['st_base'] ?? $extraTot['vBCST'] ?? 0));
        $totalIcmsStValueXml += max(0.0, (float)($extraTot['st_amount'] ?? $extraTot['vICMSST'] ?? 0));
        $totalFcpStXml += max(0.0, (float)($extraTot['fcp_st_amount'] ?? $extraTot['vFCPST'] ?? 0));
      }
    } catch (Throwable $e) {}
    $totalIcmsStValueXml = max($totalIcmsStValueXml, (float)($doc['total_icms_st'] ?? 0));

    $total = $add($inf, 'total', ''); $icmstot = $add($total, 'ICMSTot', '');
    $add($icmstot, 'vBC', $fmt($totalIcmsBaseXml)); $add($icmstot, 'vICMS', $fmt($totalIcmsValueXml)); $add($icmstot, 'vICMSDeson', '0.00'); $add($icmstot, 'vFCP', '0.00');
    $add($icmstot, 'vBCST', $fmt($totalIcmsStBaseXml)); $add($icmstot, 'vST', $fmt($totalIcmsStValueXml)); $add($icmstot, 'vFCPST', $fmt($totalFcpStXml)); $add($icmstot, 'vFCPSTRet', '0.00');
    $add($icmstot, 'vProd', $fmt($doc['total_products'] ?? 0)); $add($icmstot, 'vFrete', $fmt($useDetailedFreight ? ($doc['total_freight'] ?? 0) : 0)); $add($icmstot, 'vSeg', '0.00'); $add($icmstot, 'vDesc', $fmt($doc['total_discount'] ?? 0));
    $add($icmstot, 'vII', '0.00'); $add($icmstot, 'vIPI', $fmt($allowIpiXml ? ($doc['total_ipi'] ?? 0) : 0)); $add($icmstot, 'vIPIDevol', '0.00'); $add($icmstot, 'vPIS', $fmt($doc['total_pis'] ?? 0)); $add($icmstot, 'vCOFINS', $fmt($doc['total_cofins'] ?? 0)); $add($icmstot, 'vOutro', $fmt($nfceTotalOtherXml)); $add($icmstot, 'vNF', $fmt($doc['total_document'] ?? 0));
    if ((int)$ibptTotals['items'] > 0) {
      $ibptTotals['federal'] = round((float)$ibptTotals['federal'], 2);
      $ibptTotals['state'] = round((float)$ibptTotals['state'], 2);
      $ibptTotals['municipal'] = round((float)$ibptTotals['municipal'], 2);
      $ibptTotals['total'] = round((float)$ibptTotals['total'], 2);
      $add($icmstot, 'vTotTrib', $fmt($ibptTotals['total']));
      try {
        $upIbptDoc = $pdo->prepare('UPDATE fiscal_documents SET total_tax_approx=?, updated_at=NOW() WHERE company_id=? AND id=?');
        $upIbptDoc->execute([$ibptTotals['total'], $companyId, $docId]);
      } catch (Throwable $e) {}
    }

    $totalIbsUf = (float)($doc['total_ibs_uf'] ?? 0);
    $totalIbsMun = (float)($doc['total_ibs_mun'] ?? 0);
    $totalCbs = (float)($doc['total_cbs'] ?? 0);
    $ibsCbsBase = 0.0;
    $ibsCbsItemCount = 0;
    try {
      $stIbsTot = $pdo->prepare("SELECT COUNT(*) AS qtd, COALESCE(SUM(base_calc),0) AS base_total FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? AND tax_group='IBSCBS'");
      $stIbsTot->execute([$companyId,$docId]);
      $rowIbsTot = $stIbsTot->fetch(PDO::FETCH_ASSOC) ?: [];
      $ibsCbsItemCount = (int)($rowIbsTot['qtd'] ?? 0);
      $ibsCbsBase = max(0.0,(float)($rowIbsTot['base_total'] ?? 0));
    } catch (Throwable $e) {}
    // IBS/CBS obrigatório: todos os itens precisam ter o grupo IBSCBS.
    // O totalizador é gerado somente depois dessa validação item a item.
    if ($rtcIbsCbsXmlItemCount !== count($items)) {
      throw new RuntimeException('IBS/CBS obrigatório: nem todos os itens possuem o grupo IBSCBS válido.');
    }
    if ($rtcIbsCbsXmlItemCount > 0) {
      $ibscbsTot = $add($total, 'IBSCBSTot', '');
      $add($ibscbsTot, 'vBCIBSCBS', $fmt($ibsCbsBase));
      $gIbsTot = $add($ibscbsTot, 'gIBS', '');
      $gIbsUfTot = $add($gIbsTot, 'gIBSUF', '');
      $add($gIbsUfTot, 'vDif', '0.00');
      $add($gIbsUfTot, 'vDevTrib', '0.00');
      $add($gIbsUfTot, 'vIBSUF', $fmt($totalIbsUf));
      $gIbsMunTot = $add($gIbsTot, 'gIBSMun', '');
      $add($gIbsMunTot, 'vDif', '0.00');
      $add($gIbsMunTot, 'vDevTrib', '0.00');
      $add($gIbsMunTot, 'vIBSMun', $fmt($totalIbsMun));
      $add($gIbsTot, 'vIBS', $fmt($totalIbsUf + $totalIbsMun));
      $add($gIbsTot, 'vCredPres', '0.00');
      $add($gIbsTot, 'vCredPresCondSus', '0.00');
      $gCbsTot = $add($ibscbsTot, 'gCBS', '');
      $add($gCbsTot, 'vDif', '0.00');
      $add($gCbsTot, 'vDevTrib', '0.00');
      $add($gCbsTot, 'vCBS', $fmt($totalCbs));
      $add($gCbsTot, 'vCredPres', '0.00');
      $add($gCbsTot, 'vCredPresCondSus', '0.00');
    }
    // Total RTC deve fechar exatamente com a soma dos vItem.
    $add($total, 'vNFTot', $fmt(round($rtcTotalItems, 2)));
    $transp = $add($inf, 'transp', '');
    $freightModeXml = $useDetailedFreight ? (string)($detailedMeta['freight_mode'] ?? '9') : '9';
    $add($transp, 'modFrete', $freightModeXml);
    if ($useDetailedFreight) {
      $carrier = fiscal_nfce_document_participant($pdo, $companyId, $docId, 'TRANSPORTADOR');
      if ($carrier) {
        $transporta = $add($transp, 'transporta', '');
        $carrierDoc = fiscal_digits($carrier['document'] ?? '');
        if (strlen($carrierDoc) === 14) $add($transporta, 'CNPJ', $carrierDoc);
        elseif (strlen($carrierDoc) === 11) $add($transporta, 'CPF', $carrierDoc);
        $carrierName = fiscal_nfce_safe_xml_text($carrier['name'] ?? '', 60);
        if ($carrierName !== '') $add($transporta, 'xNome', $carrierName);
        $carrierIe = trim((string)($carrier['state_registration'] ?? ''));
        if ($carrierIe !== '') $add($transporta, 'IE', fiscal_nfce_safe_xml_text($carrierIe, 14));
        $carrierAddress = fiscal_nfce_safe_xml_text($carrier['address'] ?? '', 60);
        if ($carrierAddress !== '') $add($transporta, 'xEnder', $carrierAddress);
        $carrierCity = fiscal_nfce_safe_xml_text($carrier['city'] ?? '', 60);
        if ($carrierCity !== '') $add($transporta, 'xMun', $carrierCity);
        $carrierUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($carrier['state'] ?? '')), 0, 2));
        if ($carrierUf !== '') $add($transporta, 'UF', $carrierUf);
      }
    }

    // Pagamento no XML:
    // - Em venda normal, preserva as formas de pagamento do documento.
    // - Em NF-e de devolução ou NF-e posterior/referenciada de NFC-e, não há novo
    //   financeiro. Mesmo assim, o leiaute exige vPag dentro de detPag; por isso
    //   geramos tPag=90 com vPag=0.00. Sem vPag, o XML fica inválido no XSD.
    //   Com formas reais da NFC-e original, a SEFAZ pode rejeitar como duplicidade
    //   ou valor de pagamento indevido.
    $paymentsForXml = $payments;
    $isNfeNoFinancialPayment = $model === '55' && (
      $finNFe === '4'
      || str_contains($sourceTypeDoc, 'NFCE_REFERENCIADA')
      || str_contains($sourceTypeDoc, 'NFC_E_REFERENCIADA')
      || str_contains($sourceTypeDoc, 'NFCEREFERENCIADA')
    );
    if ($isNfeNoFinancialPayment) {
      $paymentsForXml = [[
        'payment_type' => '90',
        'payment_indicator' => '0',
        'amount' => 0,
      ]];
    }

    $pag = $add($inf, 'pag', '');
    if (!$paymentsForXml) {
      $paymentsForXml = [[
        'payment_type' => '90',
        'payment_indicator' => '0',
        'amount' => 0,
      ]];
    }
    foreach ($paymentsForXml as $pay) {
      $detPag = $add($pag, 'detPag', '');
      $tPag = preg_replace('/\D+/', '', (string)($pay['payment_type'] ?? '99')) ?: '99';
      $tPag = str_pad(substr($tPag, 0, 2), 2, '0', STR_PAD_LEFT);
      if ($tPag !== '90') {
        $add($detPag, 'indPag', in_array((string)($pay['payment_indicator'] ?? ''), ['0','1'], true) ? (string)$pay['payment_indicator'] : '0');
      }
      $add($detPag, 'tPag', $tPag);
      // NT 2020.006: xPag é obrigatório quando tPag=99 e proibido nos demais códigos.
      if ($tPag === '99') {
        $xPag = fiscal_nfce_safe_xml_text($pay['payment_description'] ?? '', 60);
        if ($xPag === '') $xPag = 'Outros';
        $add($detPag, 'xPag', $xPag);
      }
      // SEFAZ: Sem pagamento (90) e Pagamento posterior (91) devem ser enviados com vPag=0,00.
      $add($detPag, 'vPag', in_array($tPag, ['90','91'], true) ? '0.00' : $fmt($pay['amount'] ?? 0));
      if (in_array($tPag, ['03','04'], true)) {
        $card = $add($detPag, 'card', '');
        $tpIntegra = preg_replace('/\D+/', '', (string)($pay['card_integration'] ?? ''));
        $add($card, 'tpIntegra', in_array($tpIntegra, ['1','2'], true) ? $tpIntegra : '2');
        $cardCnpj = substr(fiscal_digits($pay['card_cnpj'] ?? ''), 0, 14);
        if (strlen($cardCnpj) === 14) $add($card, 'CNPJ', $cardCnpj);
        $brand = preg_replace('/\D+/', '', (string)($pay['card_brand'] ?? ''));
        if ($brand !== '') $add($card, 'tBand', str_pad(substr($brand, 0, 2), 2, '0', STR_PAD_LEFT));
        $auth = fiscal_nfce_safe_xml_text($pay['authorization_code'] ?? '', 20);
        if ($auth !== '') $add($card, 'cAut', $auth);
      }
    }
    $infAdic = $add($inf, 'infAdic', '');
    if ($isContingencyXml) {
      $contLabel = $tpEmis === '9' ? 'contingencia offline' : (($tpEmis === '6' || $tpEmis === '7') ? 'contingencia SVC' : 'contingencia');
      $infCplParts = ['DOCUMENTO EMITIDO EM ' . strtoupper($contLabel) . '. Motivo: ' . trim((string)($doc['contingency_reason'] ?? $cfg['contingency_reason'] ?? 'Falha de comunicação com o ambiente autorizador da SEFAZ.')) . '.'];
    } else {
      $infCplParts = ['XML gerado pelo Nexa Prospero para assinatura e autorização pelo motor fiscal configurado.'];
    }
    if (!empty($referenceInfoLines)) {
      $infCplParts[] = implode(' | ', $referenceInfoLines);
    }
    if ($detailedFiscalSource && $detailedFiscalOrderNote !== '') {
      $infCplParts[] = 'Observação do pedido: ' . $detailedFiscalOrderNote;
    }
    if ((int)$ibptTotals['items'] > 0) {
      $sourceText = implode(', ', array_keys($ibptSources));
      if ($sourceText === '') $sourceText = 'IBPT';
      $infCplParts[] = 'Tributos aproximados Lei 12.741/2012: Federal R$ '
        . fiscal_nfce_ibpt_text_money((float)$ibptTotals['federal'])
        . '; Estadual R$ ' . fiscal_nfce_ibpt_text_money((float)$ibptTotals['state'])
        . '; Municipal R$ ' . fiscal_nfce_ibpt_text_money((float)$ibptTotals['municipal'])
        . '; Total R$ ' . fiscal_nfce_ibpt_text_money((float)$ibptTotals['total'])
        . '. Fonte: ' . $sourceText . '.';
    }
    $add($infAdic, 'infCpl', fiscal_nfce_safe_xml_text(implode(' ', $infCplParts), 5000));
    return (string)$dom->saveXML();
  }
}

if (!function_exists('fiscal_nfce_document_items')) {
  function fiscal_nfce_document_items(PDO $pdo, int $companyId, int $docId): array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=? ORDER BY item_number ASC, id ASC');
    $st->execute([$companyId, $docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_nfce_document_payments')) {
  function fiscal_nfce_document_payments(PDO $pdo, int $companyId, int $docId): array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_document_payments WHERE company_id=? AND fiscal_document_id=? ORDER BY id ASC');
    $st->execute([$companyId, $docId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_nfce_document_taxes')) {
  function fiscal_nfce_document_taxes(PDO $pdo, int $companyId, int $docId): array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? ORDER BY fiscal_document_item_id ASC, tax_group ASC');
    $st->execute([$companyId, $docId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['fiscal_document_item_id']][] = $r;
    return $out;
  }
}

if (!function_exists('fiscal_nfce_create_or_update_from_order')) {
  function fiscal_nfce_create_or_update_from_order(PDO $pdo, int $companyId, int $orderId, string $model = '65'): array {
    $model = in_array($model, ['55','65'], true) ? $model : '65';
    $multiCompanyFiscalCross = fiscal_multi_company_document_homologation_is_cross($pdo, $model);
    if ($multiCompanyFiscalCross) {
      fiscal_multi_company_document_homologation_assert($pdo, $companyId, $model, 'emitir');
      $stSource = $pdo->prepare('SELECT source FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $stSource->execute([$companyId, $orderId]);
      $orderSource = strtolower(trim((string)($stSource->fetchColumn() ?: '')));
      if ($orderSource === 'ordem_servico_lote') {
        throw new RuntimeException('V11.46 Multiempresa: pedidos originados de faturamento de O.S. continuam usando exclusivamente a fila fiscal própria da O.S.');
      }
    }
    // Garante toda a estrutura antes da transação. Fora do F11 preserva o fluxo legado.
    // No contexto Multiempresa o schema deve estar previamente homologado e nenhuma DDL é executada.
    if (!$multiCompanyFiscalCross) fiscal_ensure_schema($pdo);
    fiscal_nfce_ensure_schema($pdo);
    $otherModel = $model === '65' ? '55' : '65';
    $otherDoc = fiscal_document_order_document($pdo, $companyId, $orderId, $otherModel);
    if ($otherDoc) {
      $otherStatus = strtoupper((string)($otherDoc['status'] ?? ''));
      if (in_array($otherStatus, ['AUTORIZADA','CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
        $otherLabel = $otherModel === '55' ? 'NF-e' : 'NFC-e';
        throw new RuntimeException('Este pedido já possui ' . $otherLabel . ' emitida ou com retorno pendente. Concilie/cancele o documento existente antes de emitir outro modelo.');
      }
    }
    $docLabel = fiscal_doc_model_label($model);
    $statusPreview = $model === '55' ? 'PRE_NFE' : 'PRE_NFCE';
    $problems = $model === '55' ? fiscal_nfe_config_problems($pdo, $companyId) : fiscal_nfce_config_problems($pdo, $companyId);
    if ($problems) throw new RuntimeException(implode(' ', $problems));
    $cfg = fiscal_company_config($pdo, $companyId);
    $env = in_array((string)($cfg['environment'] ?? 'HOMOLOGACAO'), ['HOMOLOGACAO','PRODUCAO'], true) ? (string)$cfg['environment'] : 'HOMOLOGACAO';
    $series = fiscal_nfce_active_series($pdo, $companyId, $model, $env);
    if (!$series) throw new RuntimeException('Cadastre uma série ativa para ' . $docLabel . ' modelo ' . $model . '.');
    $data = fiscal_nfce_order_data($pdo, $companyId, $orderId);
    $order = $data['order'];
    $allOrderItems = $data['items'];
    // Nova regra 2026: proteção pelo modelo, ambiente, empresa e destinatário.
    // Sempre desligada se não houver habilitação explícita.
    require_once __DIR__ . '/nexa_st_recipient.php';
    $stCompanyEnabled = nexa_st_enabled($pdo, $companyId, $model, $env);
    $stRecipientEligible = $stCompanyEnabled
      && nexa_st_customer_eligible($pdo, $companyId,
         (int)($order['customer_person_id'] ?? $order['customer_id'] ?? $order['finance_person_id'] ?? 0));
    if ($stRecipientEligible) {
      $stOriginUf = strtoupper(trim((string)($cfg['uf'] ?? '')));
      $stDestUf = strtoupper(trim((string)($order['uf'] ?? '')));
      if ($stOriginUf === '' || $stDestUf === '' || $stOriginUf !== $stDestUf) {
        throw new RuntimeException('ICMS-ST por destinatário: nesta versão são homologadas somente operações internas (origem e destino na mesma UF). Confira o endereço do cliente.');
      }
    }
    $detailedMeta = []; $detailedTaxMap = [];
    if ($model === '55' && function_exists('detailed_order_meta')) {
      try {
        $detailedMeta = detailed_order_meta($pdo, $companyId, $orderId);
        $detailedTaxMap = detailed_order_item_overrides($pdo, $companyId, $orderId);
      } catch (Throwable $e) { $detailedMeta = []; $detailedTaxMap = []; }
    }
    $orderItems = [];
    foreach ($allOrderItems as $sourceItem) {
      $sourceTax = fiscal_product_tax($pdo, $companyId, (int)($sourceItem['produto_id'] ?? 0));
      $isServiceItem = fiscal_order_item_is_service(
        $sourceItem['is_service'] ?? 0,
        $sourceTax['item_type'] ?? 'PRODUCT'
      );
      if (!$isServiceItem) $orderItems[] = $sourceItem;
    }
    if (!$orderItems) {
      throw new RuntimeException('Este pedido não possui mercadorias para emissão de ' . $docLabel . '.');
    }
    $isMixedFiscalOrder = count($orderItems) < count($allOrderItems);
    $existing = fiscal_document_order_document($pdo, $companyId, $orderId, $model);
    if ($existing && strtoupper(trim((string)($existing['status'] ?? ''))) === 'DESCARTADA') {
      throw new RuntimeException('Este documento teve a rejeição descartada localmente com auditoria. A tentativa não pode ser reutilizada; procure a regularização fiscal.');
    }
    $authorizedNfceForNfe = $model === '55' ? fiscal_document_order_authorized($pdo, $companyId, $orderId, '65') : null;
    if ($model === '55' && $authorizedNfceForNfe) {
      throw new RuntimeException('Este pedido já possui NFC-e autorizada. Para emitir a NF-e posterior, use o botão Emitir Notas Referenciadas.');
    }
    $isNfeAfterNfce = false;

    $pdo->beginTransaction();
    try {
      $docId = $existing ? (int)$existing['id'] : 0;
      $number = $existing && (int)($existing['number'] ?? 0) > 0 ? (int)$existing['number'] : 0;
      $seriesValue = $existing && trim((string)($existing['series'] ?? '')) !== '' ? (string)$existing['series'] : (string)$series['series'];
      if ($number <= 0) {
        $number = max(1, (int)($series['next_number'] ?? 1));
        $pdo->prepare('UPDATE fiscal_document_series SET last_number=?, next_number=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$number, $number + 1, $companyId, (int)$series['id']]);
      }
      $issue = date('Y-m-d H:i:s');
      $operationDate = fiscal_sql_datetime($order['created_at'] ?? '') ?: $issue;
      $accessKey = (string)($existing['access_key'] ?? '');
      if (trim($accessKey) === '') $accessKey = fiscal_nfce_make_access_key($cfg, $model, $seriesValue, $number, $issue);
      $selectedGross = 0.0;
      foreach ($orderItems as $selectedItem) {
        $q = (float)($selectedItem['qtd'] ?? 0);
        $u = (float)($selectedItem['preco_unit'] ?? 0);
        $selectedGross += round((float)($selectedItem['total'] ?? ($q * $u)), 2);
      }
      $selectedGross = round($selectedGross, 2);

      $allGross = 0.0;
      foreach ($allOrderItems as $sourceItem) {
        $q = (float)($sourceItem['qtd'] ?? 0);
        $u = (float)($sourceItem['preco_unit'] ?? 0);
        $allGross += round((float)($sourceItem['total'] ?? ($q * $u)), 2);
      }
      $allGross = round($allGross, 2);

      // Em pedido misto, NF-e/NFC-e recebem somente as mercadorias.
      // Desconto global é rateado pelo valor bruto; acréscimos/taxa de entrega
      // permanecem no documento de mercadorias para não duplicar valor na NFS-e.
      $totalProducts = $isMixedFiscalOrder ? $selectedGross : round((float)($order['total_produtos'] ?? $selectedGross), 2);
      $isDetailedNfe = $model === '55' && !empty($detailedMeta);
      $detailedFreight = $isDetailedNfe ? round(max(0.0, (float)($detailedMeta['freight_amount'] ?? $order['taxa_entrega'] ?? 0)), 2) : 0.0;
      $deliveryChargeAsOther = $isDetailedNfe ? 0.0 : round((float)($order['taxa_entrega'] ?? 0), 2);
      $totalFreight = $detailedFreight;
      $orderDiscount = round((float)($order['discount_amount'] ?? $order['desconto'] ?? $order['valor_desconto'] ?? 0), 2);
      $totalDiscount = $orderDiscount;
      if ($isMixedFiscalOrder && $orderDiscount > 0 && $allGross > 0) {
        $totalDiscount = round($orderDiscount * ($selectedGross / $allGross), 2);
      }
      $totalOther = round((float)($order['addition_amount'] ?? 0) + $deliveryChargeAsOther, 2);
      $totalDocument = $isMixedFiscalOrder
        ? round(max(0.0, $totalProducts + $totalFreight + $totalOther - $totalDiscount), 2)
        : round((float)($order['total'] ?? ($totalProducts + $totalOther - $totalDiscount)), 2);
      $operation = $model === '55' ? ($isNfeAfterNfce ? 'NF-E REFERENTE A NFC-E' : 'VENDA DE MERCADORIA NF-E') : 'VENDA AO CONSUMIDOR NFC-E';
      if ($docId > 0) {
        $pdo->prepare("UPDATE fiscal_documents SET series=?, number=?, access_key=?, status=?, environment=?, operation_nature=?, operation_datetime=COALESCE(operation_datetime,?), issue_datetime=?, exit_entry_datetime=?, total_products=?, total_discount=?, total_freight=?, total_other=?, total_document=?, sefaz_status_code=NULL, sefaz_status_message=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$seriesValue, $number, $accessKey, $statusPreview, $env, $operation, $operationDate, $issue, $issue, $totalProducts, $totalDiscount, $totalFreight, $totalOther, $totalDocument, $companyId, $docId]);
      } else {
        $pdo->prepare("INSERT INTO fiscal_documents (company_id, direction, model, series, number, access_key, status, environment, operation_nature, operation_datetime, issue_datetime, exit_entry_datetime, total_products, total_discount, total_freight, total_other, total_document, source_type, source_id, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
          ->execute([$companyId, 'SAIDA', $model, $seriesValue, $number, $accessKey, $statusPreview, $env, $operation, $operationDate, $issue, $issue, $totalProducts, $totalDiscount, $totalFreight, $totalOther, $totalDocument, 'PEDIDO', $orderId, ($isNfeAfterNfce ? 'Pré-NF-e gerada como documento posterior/referenciado à NFC-e autorizada do pedido #' . $orderId . ', sem nova baixa de estoque.' : 'Pré-' . $docLabel . ' gerada a partir do pedido #' . $orderId . ' no ambiente ' . $env . '.')]);
        $docId = (int)$pdo->lastInsertId();
      }
      if ($multiCompanyFiscalCross) {
        // A venda já movimentou estoque/financeiro no motor do PDV. Marca o documento
        // como sincronizado para que nenhuma rotina fiscal futura duplique esses reflexos.
        $pdo->prepare('UPDATE fiscal_documents SET finance_generated=1, stock_generated=1, updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$companyId, $docId]);
      }

      $pdo->prepare('DELETE FROM fiscal_document_participants WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $docId]);
      $part = $pdo->prepare('INSERT INTO fiscal_document_participants (fiscal_document_id, company_id, role, person_id, document, state_registration, name, email, phone, zip_code, address, number, complement, district, city, city_ibge, state, country_code, country_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $issuerIeSnapshot = fiscal_nfce_issuer_ie($pdo, $companyId, $cfg);
      $part->execute([$docId, $companyId, 'EMITENTE', null, fiscal_digits($cfg['company_cnpj'] ?? ''), $issuerIeSnapshot, fiscal_nfce_company_display_name($pdo, $companyId, $cfg), '', '', '', '', '', '', '', '', fiscal_digits($cfg['city_ibge'] ?? ''), (string)($cfg['uf'] ?? 'MG'), '1058', 'BRASIL']);
      $customerPersonId = (int)($order['customer_person_id'] ?? $order['customer_id'] ?? $order['finance_person_id'] ?? 0);
      $part->execute([$docId, $companyId, 'DESTINATARIO', $customerPersonId ?: null, fiscal_digits($order['customer_document'] ?? ''), trim((string)($order['customer_state_registration'] ?? '')), (string)($order['nome_cliente'] ?? 'CONSUMIDOR FINAL'), (string)($order['customer_email'] ?? ''), (string)($order['whatsapp'] ?? ''), (string)($order['zip_code'] ?? ''), (string)($order['endereco'] ?? ''), (string)($order['numero'] ?? ''), (string)($order['complemento'] ?? ''), (string)($order['bairro'] ?? ''), (string)($order['cidade'] ?? ''), fiscal_digits($order['customer_city_ibge'] ?? ''), (string)($order['uf'] ?? ''), '1058', 'BRASIL']);
      if ($model === '55' && $detailedMeta && in_array((string)($detailedMeta['freight_mode'] ?? '9'), ['0','1','2','3','4'], true) && trim((string)($detailedMeta['carrier_name'] ?? '')) !== '') {
        $part->execute([$docId, $companyId, 'TRANSPORTADOR', (int)($detailedMeta['carrier_person_id'] ?? 0) ?: null, fiscal_digits($detailedMeta['carrier_document'] ?? ''), (string)($detailedMeta['carrier_ie'] ?? ''), (string)($detailedMeta['carrier_name'] ?? ''), '', '', '', (string)($detailedMeta['carrier_address'] ?? ''), '', '', '', (string)($detailedMeta['carrier_city'] ?? ''), '', (string)($detailedMeta['carrier_state'] ?? ''), '1058', 'BRASIL']);
      }

      $pdo->prepare('DELETE FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $docId]);
      $pdo->prepare('DELETE FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $docId]);
      $insItem = $pdo->prepare('INSERT INTO fiscal_document_items (fiscal_document_id, company_id, item_number, product_id, product_code, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, unidade_trib, quantity_trib, unit_value_trib, discount_value, freight_value, other_value, ind_total, order_item_id, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $insTax = $pdo->prepare('INSERT INTO fiscal_document_item_taxes (fiscal_document_item_id, fiscal_document_id, company_id, tax_group, tax_tag, origin_code, cst, csosn, base_calc, rate, amount, extra_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $n = 0; $totStRecipient = 0.0; $totIcms = 0.0; $totIpi = 0.0; $totPis = 0.0; $totCofins = 0.0; $totIbsUf = 0.0; $totIbsMun = 0.0; $totCbs = 0.0;
      $freightAlloc = fiscal_nfce_allocate_amounts($totalFreight, $orderItems, 'total');
      $discountAlloc = fiscal_nfce_allocate_amounts($totalDiscount, $orderItems, 'total');
      $otherAlloc = fiscal_nfce_allocate_amounts($totalOther, $orderItems, 'total');
      foreach ($orderItems as $it) {
        $n++;
        $pid = (int)($it['produto_id'] ?? 0);
        $tax = fiscal_product_tax($pdo, $companyId, $pid);
        if ($model === '55' && $detailedTaxMap) {
          $ov = $detailedTaxMap[(int)($it['id'] ?? 0)] ?? [];
          $tax = detailed_order_merge_tax($tax, $ov, (string)($detailedMeta['default_cfop'] ?? ''));
        }
        $qty = round((float)($it['qtd'] ?? 0), 6);
        $unit = round((float)($it['preco_unit'] ?? 0), 6);
        $total = round((float)($it['total'] ?? ($qty * $unit)), 2);
        $itemFreight = round((float)($freightAlloc[$n - 1] ?? 0), 2);
        $itemDiscount = round((float)($discountAlloc[$n - 1] ?? 0), 2);
        $itemOther = round((float)($otherAlloc[$n - 1] ?? 0), 2);
        $taxBase = max(0.0, round($total + $itemFreight + $itemOther - $itemDiscount, 2));
        $unitName = trim((string)($it['unidade_estoque'] ?? 'UN')) ?: 'UN';
        // NF-e para cliente de outro Estado usa o CFOP "Venda fora do Estado"
        // do produto/perfil; sem ele, o equivalente interestadual do CFOP interno.
        $orderEmitUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? '')), 0, 2));
        $orderDestUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($order['uf'] ?? $order['state'] ?? '')), 0, 2));
        $orderInterstate = $model === '55' && $orderEmitUf !== '' && $orderDestUf !== '' && $orderDestUf !== $orderEmitUf;
        $cfopState = preg_replace('/\D+/', '', (string)($tax['cfop_sale_state'] ?? '')) ?: '5102';
        $cfop = $cfopState;
        if ($orderInterstate) {
          $cfopInter = preg_replace('/\D+/', '', (string)($tax['cfop_sale_interstate'] ?? ''));
          $cfop = (strlen($cfopInter) === 4 && $cfopInter !== $cfopState) ? $cfopInter : fiscal_cfop_state_equivalent($cfopState, false);
        }
        if ($isNfeAfterNfce) $cfop = fiscal_nfe_after_nfce_cfop($order, $cfg);
        $ncm = preg_replace('/\D+/', '', (string)($tax['ncm'] ?? ''));
        $cest = preg_replace('/\D+/', '', (string)($tax['cest'] ?? ''));
        // Regra de CFOP/CST/CSOSN da NF-e ST por empresa; NFC-e permanece intocada.
        // Resolver antes de gravar o item para que o CFOP já vá correto ao XML.
        $stNfeProfile = null;
        if ($stRecipientEligible && !$isNfeAfterNfce && (float)($tax['icms_st_rate'] ?? 0) > 0) {
          if (strlen($ncm) !== 8 || strlen($cest) !== 7) {
            throw new RuntimeException('ICMS-ST: item '.$n.' sem NCM (8 dígitos) ou CEST (7 dígitos).');
          }
          $dateForSt = substr((string)$issue, 0, 10);
          $stNfeProfile = nexa_st_nfe_profile($pdo, $companyId, $stDestUf, $ncm, $cest, $dateForSt);
          $stNfeCodes = nexa_st_nfe_validate_codes($stNfeProfile, (string)($cfg['crt'] ?? '1'));
          $cfop = $stNfeCodes['cfop'];
        }
        $productCode = trim((string)($it['numero_cadastro'] ?? '')); if ($productCode === '' || $productCode === '0') $productCode = trim((string)($it['codigo_firebird'] ?? '')) ?: (string)$pid;
        $desc = trim((string)($it['product_name'] ?? 'Produto'));
        // As instalações antigas usam nomes diferentes para a observação do item.
        // A NF-e do Pedido Detalhado recebe o texto correspondente em notes.
        $fiscalItemObservation = (string)($it['complemento'] ?? '');
        if ($model === '55' && $detailedMeta) {
          require_once __DIR__ . '/detailed_order_fiscal_notes.php';
          $fiscalItemObservation = detailed_order_fiscal_observation($it);
        }
        $insItem->execute([$docId, $companyId, $n, $pid ?: null, $productCode, $desc, $ncm, $cest, $cfop, $unitName, $qty, $unit, $total, $unitName, $qty, $unit, $itemDiscount, $itemFreight, $itemOther, '1', (int)($it['id'] ?? 0), $fiscalItemObservation]);
        $docItemId = (int)$pdo->lastInsertId();
        $origin = preg_replace('/\D+/', '', (string)($tax['origem'] ?? '0')) ?: '0';
        $crt = preg_replace('/\D+/', '', (string)($cfg['crt'] ?? '1')) ?: '1';
        $icmsRate = max(0.0, (float)($tax['icms_rate'] ?? 0));
        $icmsBase = $icmsRate > 0 ? $taxBase : 0.0;
        $icmsAmount = round($icmsBase * $icmsRate / 100, 2);
        $icmsCst = preg_replace('/\D+/', '', (string)($tax['icms_cst'] ?? '00')) ?: '00';
        $csosn = preg_replace('/\D+/', '', (string)($tax['icms_csosn'] ?? '102')) ?: '102';
        if ($isNfeAfterNfce) { $icmsRate = 0.0; $icmsBase = 0.0; $icmsAmount = 0.0; $icmsCst = '90'; $csosn = '400'; }
        $stExtraJson = null;
        // Ativação isolada (modelo 55, habilitação da empresa, destinatário PJ LP/LR).
        // Não transforma produtos comuns em ST: exige pICMSST previamente configurado na Tributação e perfil NF-e da empresa.
        if ($stNfeProfile !== null) {
          // Os códigos da NF-e NÃO substituem a tributação da NFC-e nem do produto.
          if ($crt === '1') $csosn = $stNfeCodes['csosn'];
          else $icmsCst = $stNfeCodes['cst'];
          $stOwnRate = nexa_st_own_rate($pdo, $stDestUf, $dateForSt);
          $stValues = nexa_st_calculate($taxBase, (float)$stNfeProfile['mva_rate'], (float)$tax['icms_st_rate'], $stOwnRate);
          $stValues['company_id'] = $companyId;
          $stValues['nfe_cfop'] = $cfop;
          $stValues['nfe_cst'] = $crt === '1' ? '' : $icmsCst;
          $stValues['nfe_csosn'] = $crt === '1' ? $csosn : '';
          $stValues['mva_id'] = (int)$stNfeProfile['mva_id'];
          $stValues['reference_uf'] = $stDestUf;
          $stValues['ncm'] = $ncm;
          $stValues['cest'] = $cest;
          $stExtraJson = json_encode($stValues, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
          $totStRecipient += (float)$stValues['st_amount'];
          // CSOSN 202/203 e CST 30 não destacam ICMS da operação própria:
          // seu valor de referência foi usado exclusivamente para calcular o ST.
          $icmsRate = 0.0; $icmsBase = 0.0; $icmsAmount = 0.0;
        }
        $totIcms += $icmsAmount;
        $taxTag = fiscal_nfce_icms_xml_tag($crt, $icmsCst, $crt === '1' ? $csosn : '');
        $insTax->execute([$docItemId, $docId, $companyId, 'ICMS', $taxTag, $origin, $crt === '1' ? '' : $icmsCst, $crt === '1' ? $csosn : '', $icmsBase, $icmsRate, $icmsAmount, $stExtraJson]);
        // Regra isolada: somente Pedido Detalhado emitido como NF-e modelo 55
        // pode levar a tributação de IPI informada item a item. Isso restaura o
        // comportamento das demais telas de vendas e impede rejeição 742 na NFC-e.
        $ipiAmount = 0.0;
        if ($model === '55' && !empty($detailedMeta)) {
          $ipiRate = max(0.0, (float)($tax['ipi_rate'] ?? 0));
          $ipiCst = str_pad(preg_replace('/\D+/', '', (string)($tax['ipi_cst'] ?? '53')) ?: '53', 2, '0', STR_PAD_LEFT);
          $ipiTag = fiscal_nfce_ipi_xml_tag($ipiCst);
          $ipiBase = ($ipiTag === 'IPITrib' && $ipiRate > 0) ? $taxBase : 0.0;
          $ipiAmount = round($ipiBase * $ipiRate / 100, 2);
          $totIpi += $ipiAmount;
          $insTax->execute([$docItemId, $docId, $companyId, 'IPI', $ipiTag, '', $ipiCst, '', $ipiBase, $ipiRate, $ipiAmount, json_encode(['cEnq'=>'999'], JSON_UNESCAPED_UNICODE)]);
        }
        $pisRate = max(0.0, (float)($tax['pis_rate'] ?? 0));
        $pisBase = $pisRate > 0 ? $taxBase : 0.0;
        $pisAmount = round($pisBase * $pisRate / 100, 2);
        $pisCst = str_pad(preg_replace('/\D+/', '', (string)($tax['pis_cst'] ?? '01')) ?: '01', 2, '0', STR_PAD_LEFT);
        if ($isNfeAfterNfce) { $pisRate = 0.0; $pisBase = 0.0; $pisAmount = 0.0; $pisCst = '06'; }
        $totPis += $pisAmount;
        if (in_array($pisCst, ['01','02'], true)) $pisTag = 'PISAliq';
        elseif (in_array($pisCst, ['04','06','07','08','09'], true)) $pisTag = 'PISNT';
        else $pisTag = 'PISOutr';
        $insTax->execute([$docItemId, $docId, $companyId, 'PIS', $pisTag, '', $pisCst, '', $pisBase, $pisRate, $pisAmount, null]);
        $cofRate = max(0.0, (float)($tax['cofins_rate'] ?? 0));
        $cofBase = $cofRate > 0 ? $taxBase : 0.0;
        $cofAmount = round($cofBase * $cofRate / 100, 2);
        $cofCst = str_pad(preg_replace('/\D+/', '', (string)($tax['cofins_cst'] ?? '01')) ?: '01', 2, '0', STR_PAD_LEFT);
        if ($isNfeAfterNfce) { $cofRate = 0.0; $cofBase = 0.0; $cofAmount = 0.0; $cofCst = '06'; }
        $totCofins += $cofAmount;
        if (in_array($cofCst, ['01','02'], true)) $cofTag = 'COFINSAliq';
        elseif (in_array($cofCst, ['04','06','07','08','09'], true)) $cofTag = 'COFINSNT';
        else $cofTag = 'COFINSOutr';
        $insTax->execute([$docItemId, $docId, $companyId, 'COFINS', $cofTag, '', $cofCst, '', $cofBase, $cofRate, $cofAmount, null]);

        $ibsResolved = function_exists('fiscal_ibs_cbs_resolve')
          ? fiscal_ibs_cbs_resolve(
              $pdo,
              $companyId,
              $tax,
              $cfg,
              $model,
              (string)$issue,
              (string)($order['uf'] ?? $cfg['uf'] ?? ''),
              (string)($order['city_ibge'] ?? $cfg['city_ibge'] ?? '')
            )
          : ['enabled'=>false,'reason'=>'Resolvedor IBS/CBS indisponível.'];
        if (!$isNfeAfterNfce && empty($ibsResolved['enabled'])) {
          $itemLabel = trim((string)($it['product_name'] ?? $desc ?? 'Produto'));
          $reason = trim((string)($ibsResolved['reason'] ?? 'Configuração de IBS/CBS incompleta.'));
          throw new RuntimeException(
            'IBS/CBS obrigatório no item ' . $n . ' (' . $itemLabel . '): ' . $reason
            . ' Configure o cClassTrib do produto antes de gerar o documento fiscal.'
          );
        }
        if (!$isNfeAfterNfce && !empty($ibsResolved['enabled'])) {
          $ibsCalc = fiscal_ibs_cbs_calculate($taxBase, $ibsResolved, [
            'icms' => $icmsAmount,
            'pis' => $pisAmount,
            'cofins' => $cofAmount,
            'ipi' => 0,
            'iss' => 0,
          ]);
          $ibsBase = (float)$ibsCalc['base'];
          $ibsUfRate = (float)$ibsCalc['ibs_uf_rate'];
          $ibsMunRate = (float)$ibsCalc['ibs_mun_rate'];
          $cbsRate = (float)$ibsCalc['cbs_rate'];
          $ibsUfAmount = (float)$ibsCalc['ibs_uf_amount'];
          $ibsMunAmount = (float)$ibsCalc['ibs_mun_amount'];
          $ibsAmount = (float)$ibsCalc['ibs_amount'];
          $cbsAmount = (float)$ibsCalc['cbs_amount'];
          $totIbsUf += $ibsUfAmount;
          $totIbsMun += $ibsMunAmount;
          $totCbs += $cbsAmount;
          $extra = json_encode([
            'cClassTrib' => (string)$ibsResolved['class_code'],
            'CST' => (string)$ibsResolved['cst'],
            'rate_mode' => (string)$ibsResolved['mode'],
            'rate_source' => (string)$ibsResolved['source'],
            'rate_date' => (string)$ibsResolved['date'],
            'operation_uf' => (string)$ibsResolved['uf'],
            'operation_city_ibge' => (string)$ibsResolved['city_ibge'],
            'base_calc_gross' => $taxBase,
            'excluded_legacy_taxes' => (float)$ibsCalc['excluded_total'],
            'base_calc' => $ibsBase,
            'ibs_reduction_rate' => (float)$ibsCalc['ibs_reduction_rate'],
            'cbs_reduction_rate' => (float)$ibsCalc['cbs_reduction_rate'],
            'ibs_uf_rate' => $ibsUfRate,
            'ibs_uf_effective_rate' => (float)$ibsCalc['ibs_uf_effective_rate'],
            'ibs_uf_trib_op' => (float)$ibsCalc['ibs_uf_trib_op'],
            'ibs_uf_amount' => $ibsUfAmount,
            'ibs_mun_rate' => $ibsMunRate,
            'ibs_mun_effective_rate' => (float)$ibsCalc['ibs_mun_effective_rate'],
            'ibs_mun_trib_op' => (float)$ibsCalc['ibs_mun_trib_op'],
            'ibs_mun_amount' => $ibsMunAmount,
            'ibs_amount' => $ibsAmount,
            'cbs_rate' => $cbsRate,
            'cbs_effective_rate' => (float)$ibsCalc['cbs_effective_rate'],
            'cbs_trib_op' => (float)$ibsCalc['cbs_trib_op'],
            'cbs_amount' => $cbsAmount,
          ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
          $insTax->execute([$docItemId, $docId, $companyId, 'IBSCBS', 'IBSCBS', '', (string)$ibsResolved['cst'], '', $ibsBase, ($ibsUfRate + $ibsMunRate + $cbsRate), ($ibsAmount + $cbsAmount), $extra]);
        }
      }
      // Na NF-e detalhada, o IPI destacado compõe o valor fiscal total da NF-e.
      // O total comercial do pedido permanece preservado; o ajuste ocorre apenas
      // no documento fiscal e no pagamento informado no XML.
      $fiscalTotalDocument = ($model === '55' && !empty($detailedMeta))
        ? round($totalDocument + $totIpi + $totStRecipient, 2)
        : round($totalDocument + $totStRecipient, 2);
      $pdo->prepare('DELETE FROM fiscal_document_payments WHERE company_id=? AND fiscal_document_id=?')->execute([$companyId, $docId]);
      $payInfo = fiscal_nfce_order_payment_info($pdo, $companyId, $order);
      // Pedidos técnicos do Controle de Entregas não representam uma nova cobrança:
      // o financeiro já pertence ao pedido comercial original. A NF-e/NFC-e da O.E.
      // registra apenas a saída física/fiscal, portanto usa tPag=90 e vPag=0,00.
      $isDeliveryControlFiscal = strtolower(trim((string)($order['source'] ?? ''))) === 'delivery_control';
      if ($isDeliveryControlFiscal) {
        $payInfo = [
          'payment_method_id' => null,
          'payment_type' => '90',
          'payment_description' => 'Sem pagamento',
          'payment_indicator' => '0',
          'card_brand' => '',
          'card_integration' => '',
          'card_cnpj' => '',
          'authorization_code' => '',
        ];
      }
      $fiscalPaymentAmount = in_array((string)$payInfo['payment_type'], ['90','91'], true) ? 0.0 : $fiscalTotalDocument;
      $pdo->prepare('INSERT INTO fiscal_document_payments (fiscal_document_id, company_id, payment_method_id, payment_type, payment_description, payment_indicator, amount, card_brand, card_integration, card_cnpj, authorization_code, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$docId, $companyId, $payInfo['payment_method_id'], $payInfo['payment_type'], $payInfo['payment_description'], $payInfo['payment_indicator'], $fiscalPaymentAmount, $payInfo['card_brand'], $payInfo['card_integration'], $payInfo['card_cnpj'], $payInfo['authorization_code']]);
      $pdo->prepare('UPDATE fiscal_documents SET total_document=?, total_icms=?, total_ipi=?, total_pis=?, total_cofins=?, total_ibs_uf=?, total_ibs_mun=?, total_cbs=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$fiscalTotalDocument, round($totIcms,2), round($totIpi,2), round($totPis,2), round($totCofins,2), round($totIbsUf,2), round($totIbsMun,2), round($totCbs,2), $companyId, $docId]);
      if ($stRecipientEligible) {
        $pdo->prepare('UPDATE fiscal_documents SET total_icms_st=? WHERE company_id=? AND id=?')
          ->execute([round($totStRecipient, 2), $companyId, $docId]);
      }
      $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
      $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$xml, hash('sha256', $xml), $companyId, $docId]);
      $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, status_code, status_message, request_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,NOW())')
        ->execute([$docId, $companyId, $model === '55' ? 'PRE_NFE' : 'PRE_NFCE', 1, 'PRE', 'Pré-' . $docLabel . ' gerada para o ambiente configurado.', $xml, 'Documento preparado para futura assinatura/autorização.']);
      if ($pdo->inTransaction()) $pdo->commit();
      $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId, $docId]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$docId];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}


if (!function_exists('fiscal_nfe_create_or_update_from_order')) {
  function fiscal_nfe_create_or_update_from_order(PDO $pdo, int $companyId, int $orderId): array {
    return fiscal_nfce_create_or_update_from_order($pdo, $companyId, $orderId, '55');
  }
}



if (!function_exists('fiscal_nfce_nfephp_autoload_path')) {
  function fiscal_nfce_nfephp_autoload_path(): string {
    return dirname(__DIR__) . '/vendor/autoload.php';
  }
}

if (!function_exists('fiscal_nfce_nfephp_rtc_scheme')) {
  /**
   * Localiza automaticamente o pacote de schemas do NFePHP que já contém
   * os campos da Reforma Tributária do Consumo (IBS/CBS, vItem e vNFTot).
   *
   * A versão anterior utilizava PL_009_V4 de forma fixa. Esse pacote é
   * anterior à NT 2025.002 e rejeita corretamente as novas tags RTC.
   */
  function fiscal_nfce_nfephp_rtc_scheme(): string {
    static $resolved = null;
    if (is_string($resolved) && $resolved !== '') return $resolved;

    $schemesRoot = dirname(__DIR__) . '/vendor/nfephp-org/sped-nfe/schemes';
    if (!is_dir($schemesRoot)) {
      throw new RuntimeException('Diretório de schemas do NFePHP não encontrado. Execute composer install na pasta nexa.');
    }

    $isRtcScheme = static function (string $schemeName) use ($schemesRoot): bool {
      $schemeName = trim($schemeName);
      if ($schemeName === '' || basename($schemeName) !== $schemeName) return false;
      $dir = $schemesRoot . '/' . $schemeName;
      $layout = $dir . '/leiauteNFe_v4.00.xsd';
      $envelope = $dir . '/enviNFe_v4.00.xsd';
      if (!is_file($layout) || !is_file($envelope)) return false;
      $xsd = @file_get_contents($layout);
      if (!is_string($xsd) || $xsd === '') return false;
      foreach (['name="IBSCBS"', 'name="vItem"', 'name="IBSCBSTot"', 'name="vNFTot"'] as $marker) {
        if (!str_contains($xsd, $marker)) return false;
      }
      return true;
    };

    // Permite fixar explicitamente um pacote, sem aceitar caminho arbitrário.
    $forced = trim((string)(getenv('NEXA_NFE_SCHEMES') ?: ''));
    if ($forced !== '') {
      if (!$isRtcScheme($forced)) {
        throw new RuntimeException('NEXA_NFE_SCHEMES aponta para um pacote sem suporte completo à RTC: ' . $forced . '.');
      }
      return $resolved = $forced;
    }

    // Prioriza as versões PL_010 mais recentes disponíveis no vendor instalado.
    $dirs = glob($schemesRoot . '/PL_010*', GLOB_ONLYDIR) ?: [];
    usort($dirs, static fn(string $a, string $b): int => strnatcasecmp(basename($b), basename($a)));
    foreach ($dirs as $dir) {
      $name = basename($dir);
      if ($isRtcScheme($name)) return $resolved = $name;
    }

    // Fallback seguro para futuros nomes de pasta: aceita somente XSD que
    // comprovadamente contenha os quatro grupos/campos RTC necessários.
    $allDirs = glob($schemesRoot . '/*', GLOB_ONLYDIR) ?: [];
    usort($allDirs, static fn(string $a, string $b): int => strnatcasecmp(basename($b), basename($a)));
    foreach ($allDirs as $dir) {
      $name = basename($dir);
      if ($isRtcScheme($name)) return $resolved = $name;
    }

    throw new RuntimeException(
      'O NFePHP instalado não possui schema compatível com IBS/CBS. '
      . 'Atualize nfephp-org/sped-nfe e confirme a existência de um pacote PL_010 com vItem, IBSCBSTot e vNFTot.'
    );
  }
}

if (!function_exists('fiscal_certificate_expiration_timestamp')) {
  /**
   * Retorna o instante real de expiração do certificado sempre que possível.
   * Para A1 lê o notAfter diretamente do PKCS#12; quando isso não for possível,
   * usa valid_until como fallback até 23:59:59 do dia cadastrado.
   */
  function fiscal_certificate_expiration_timestamp(array $cert, string $absolutePath = ''): int {
    $absolutePath = trim($absolutePath);
    $password = (string)($cert['password_secret'] ?? '');
    $extension = strtolower(pathinfo($absolutePath !== '' ? $absolutePath : (string)($cert['file_path'] ?? ''), PATHINFO_EXTENSION));
    try {
      if ($absolutePath !== '' && is_file($absolutePath) && is_readable($absolutePath) && function_exists('openssl_x509_parse')) {
        $raw = @file_get_contents($absolutePath);
        if (is_string($raw) && $raw !== '') {
          $pem = '';
          if (in_array($extension, ['pfx','p12'], true) && function_exists('openssl_pkcs12_read') && $password !== '') {
            $bundle = [];
            if (@openssl_pkcs12_read($raw, $bundle, $password) && !empty($bundle['cert'])) {
              $pem = (string)$bundle['cert'];
            }
          } elseif (!in_array($extension, ['pfx','p12'], true)) {
            $pem = $raw;
          }
          if ($pem !== '') {
            $parsed = @openssl_x509_parse($pem);
            if (is_array($parsed) && !empty($parsed['validTo_time_t'])) {
              return max(0, (int)$parsed['validTo_time_t']);
            }
          }
        }
      }
    } catch (Throwable $e) {}

    $dbDate = trim((string)($cert['valid_until'] ?? ''));
    if ($dbDate !== '') {
      $ts = strtotime($dbDate . ' 23:59:59');
      if ($ts !== false) return (int)$ts;
    }
    return 0;
  }
}

if (!function_exists('fiscal_nfce_active_certificate')) {
  function fiscal_nfce_active_certificate(PDO $pdo, int $companyId): array {
    if (!fiscal_table_exists($pdo, 'fiscal_certificates')) {
      throw new RuntimeException('Tabela fiscal_certificates não encontrada. Instale as tabelas operacionais fiscais.');
    }
    $st = $pdo->prepare("SELECT * FROM fiscal_certificates WHERE company_id=? AND is_active=1 ORDER BY valid_until DESC, id DESC LIMIT 1");
    $st->execute([$companyId]);
    $cert = $st->fetch(PDO::FETCH_ASSOC);
    if (!$cert) throw new RuntimeException('Nenhum certificado digital ativo foi encontrado para esta empresa.');
    if (strtoupper((string)($cert['certificate_type'] ?? 'A1')) !== 'A1') {
      throw new RuntimeException('Nesta etapa com NFePHP é necessário certificado A1 (.pfx/.p12).');
    }
    $filePath = trim((string)($cert['file_path'] ?? ''));
    if ($filePath === '') throw new RuntimeException('O certificado ativo não possui arquivo vinculado.');
    $absolute = realpath(dirname(__DIR__) . '/' . $filePath) ?: (dirname(__DIR__) . '/' . $filePath);
    if (!is_file($absolute) || !is_readable($absolute)) {
      throw new RuntimeException('Arquivo do certificado não encontrado ou sem permissão de leitura: ' . $filePath);
    }
    $expiresAt = fiscal_certificate_expiration_timestamp($cert, $absolute);
    if ($expiresAt > 0) {
      $cert['expires_at_timestamp'] = $expiresAt;
      $cert['expires_at_datetime'] = date('Y-m-d H:i:s', $expiresAt);
      if ($expiresAt <= time()) {
        throw new RuntimeException(
          'O certificado digital ativo venceu em ' . date('d/m/Y H:i:s', $expiresAt)
          . '. Cadastre e ative um novo certificado antes de emitir ou retransmitir documentos fiscais.'
        );
      }
    }
    $cert['absolute_path'] = $absolute;
    return $cert;
  }
}

if (!function_exists('fiscal_nfce_installation_status')) {
  function fiscal_nfce_installation_status(PDO $pdo, int $companyId): array {
    $autoload = fiscal_nfce_nfephp_autoload_path();
    $extensions = ['dom','libxml','openssl','soap','simplexml','zlib','json'];
    $missing = [];
    foreach ($extensions as $ext) if (!extension_loaded($ext)) $missing[] = $ext;
    $status = [
      'autoload_path' => $autoload,
      'vendor_installed' => is_file($autoload),
      'rtc_scheme' => '',
      'rtc_scheme_ready' => false,
      'missing_extensions' => $missing,
      'has_active_certificate' => false,
      'certificate_message' => '',
    ];
    if ($status['vendor_installed']) {
      try {
        $status['rtc_scheme'] = fiscal_nfce_nfephp_rtc_scheme();
        $status['rtc_scheme_ready'] = true;
      } catch (Throwable $e) {
        $status['rtc_scheme_error'] = $e->getMessage();
      }
    }
    try { fiscal_nfce_active_certificate($pdo, $companyId); $status['has_active_certificate'] = true; }
    catch (Throwable $e) { $status['certificate_message'] = $e->getMessage(); }
    return $status;
  }
}

if (!function_exists('fiscal_nfce_require_nfephp')) {
  function fiscal_nfce_require_nfephp(PDO $pdo, int $companyId): void {
    $status = fiscal_nfce_installation_status($pdo, $companyId);
    if ($status['missing_extensions']) {
      throw new RuntimeException('Extensões PHP ausentes para NFePHP: ' . implode(', ', $status['missing_extensions']) . '. Instale/habilite essas extensões na VPS antes de autorizar.');
    }
    if (!$status['vendor_installed']) {
      throw new RuntimeException('NFePHP/sped-nfe ainda não está instalado. Acesse a pasta nexa no servidor e execute: composer require nfephp-org/sped-nfe nfephp-org/sped-da');
    }
    require_once $status['autoload_path'];
    if (!class_exists('NFePHP\\NFe\\Tools') || !class_exists('NFePHP\\Common\\Certificate')) {
      throw new RuntimeException('Autoload encontrado, mas as classes do NFePHP não foram carregadas. Rode composer install/update na pasta nexa.');
    }
    // Falha cedo e com mensagem clara se o servidor ainda estiver usando PL_009.
    fiscal_nfce_nfephp_rtc_scheme();
  }
}

if (!function_exists('fiscal_nfce_nfephp_config_json')) {
  function fiscal_nfce_nfephp_config_json(PDO $pdo, int $companyId, array $cfg, string $model = '65'): string {
    $env = (string)($cfg['environment'] ?? 'HOMOLOGACAO') === 'PRODUCAO' ? 1 : 2;
    $cnpj = fiscal_digits($cfg['company_cnpj'] ?? '');
    if (strlen($cnpj) !== 14) throw new RuntimeException('CNPJ da empresa inválido para emissão fiscal.');
    $model = in_array($model, ['55','65'], true) ? $model : '65';
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? 'MG')), 0, 2)) ?: 'MG';
    $csc = trim((string)($cfg['nfce_csc_token'] ?? ''));
    $cscId = trim((string)($cfg['nfce_csc_id'] ?? ''));
    if ($model === '65' && ($csc === '' || $cscId === '')) throw new RuntimeException('Informe CSC e ID CSC nas configurações fiscais para NFC-e.');
    $payload = [
      'atualizacao' => date('Y-m-d H:i:s'),
      'tpAmb' => $env,
      'razaosocial' => fiscal_nfce_company_display_name($pdo, $companyId, $cfg),
      'cnpj' => $cnpj,
      'siglaUF' => $uf,
      'schemes' => fiscal_nfce_nfephp_rtc_scheme(),
      'versao' => '4.00',
      'tokenIBPT' => '',
      'CSC' => $csc,
      'CSCid' => $cscId,
      'proxyConf' => ['proxyIp'=>'','proxyPort'=>'','proxyUser'=>'','proxyPass'=>''],
    ];
    return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
}

if (!function_exists('fiscal_nfce_nfephp_tools')) {
  function fiscal_nfce_nfephp_tools(PDO $pdo, int $companyId, array $cfg, string $model = '65', ?\NFePHP\NFe\Factories\Contingency $contingency = null): object {
    fiscal_nfce_require_nfephp($pdo, $companyId);
    $cert = fiscal_nfce_active_certificate($pdo, $companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o conteúdo do certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva. Edite o certificado ativo e informe a senha.');
    $configJson = fiscal_nfce_nfephp_config_json($pdo, $companyId, $cfg, $model);
    $certificate = \NFePHP\Common\Certificate::readPfx($pfx, $password);
    $tools = new \NFePHP\NFe\Tools($configJson, $certificate, $contingency);
    $tools->model((int)$model);
    return $tools;
  }
}

if (!function_exists('fiscal_nfce_object_get')) {
  function fiscal_nfce_object_get($value, string $key) {
    if (is_object($value) && isset($value->{$key})) return $value->{$key};
    if (is_array($value) && array_key_exists($key, $value)) return $value[$key];
    return null;
  }
}

if (!function_exists('fiscal_nfce_protocol_from_response')) {
  function fiscal_nfce_protocol_from_response($respObj) {
    $prot = fiscal_nfce_object_get($respObj, 'protNFe');
    if (is_array($prot)) $prot = $prot[0] ?? null;
    if ($prot) $prot = fiscal_nfce_object_get($prot, 'infProt') ?: $prot;
    return $prot;
  }
}


if (!function_exists('fiscal_nfce_cancel_event_from_consult_response')) {
  /**
   * A consulta de protocolo pode retornar protNFe=100 e, no mesmo XML,
   * procEventoNFe com o cancelamento 110111 já registrado. O evento deve
   * prevalecer sobre o protocolo original de autorização.
   */
  function fiscal_nfce_cancel_event_from_consult_response(string $xml): array {
    $out = [
      'found'=>false,
      'code'=>'',
      'message'=>'',
      'protocol'=>'',
      'sequence'=>'',
      'event_type'=>'',
      'registered_at'=>'',
    ];
    if (trim($xml) === '') return $out;
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    try {
      if (!@$dom->loadXML($xml)) return $out;
      $xp = new DOMXPath($dom);
      $value = static function(DOMXPath $xp, DOMNode $ctx, string $name): string {
        $nodes = $xp->query('./*[local-name()="' . $name . '"]', $ctx);
        return ($nodes && $nodes->length) ? trim((string)$nodes->item(0)->nodeValue) : '';
      };

      // Cancelamento por evento (modelo atual).
      $events = $xp->query('//*[local-name()="procEventoNFe"]/*[local-name()="retEvento"]/*[local-name()="infEvento"]');
      if ($events) {
        foreach ($events as $event) {
          $tpEvento = $value($xp, $event, 'tpEvento');
          $code = $value($xp, $event, 'cStat');
          $message = $value($xp, $event, 'xMotivo');
          $xEvento = $value($xp, $event, 'xEvento');
          if ($tpEvento === '110111' && (in_array($code, ['101','135','155'], true) || stripos($xEvento . ' ' . $message, 'cancel') !== false)) {
            return [
              'found'=>true,
              'code'=>$code !== '' ? $code : '135',
              'message'=>$message !== '' ? $message : 'Evento de cancelamento localizado na consulta SEFAZ.',
              'protocol'=>$value($xp, $event, 'nProt'),
              'sequence'=>$value($xp, $event, 'nSeqEvento'),
              'event_type'=>$tpEvento,
              'registered_at'=>$value($xp, $event, 'dhRegEvento'),
            ];
          }
        }
      }

      // Compatibilidade com o antigo retorno retCancNFe.
      $legacy = $xp->query('//*[local-name()="retCancNFe"]/*[local-name()="infCanc"]');
      if ($legacy && $legacy->length) {
        $event = $legacy->item(0);
        $code = $value($xp, $event, 'cStat');
        $message = $value($xp, $event, 'xMotivo');
        if (in_array($code, ['101','135','155'], true) || stripos($message, 'cancel') !== false) {
          return [
            'found'=>true,
            'code'=>$code !== '' ? $code : '101',
            'message'=>$message !== '' ? $message : 'Cancelamento localizado na consulta SEFAZ.',
            'protocol'=>$value($xp, $event, 'nProt'),
            'sequence'=>'1',
            'event_type'=>'110111',
            'registered_at'=>$value($xp, $event, 'dhRecbto'),
          ];
        }
      }
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($old);
    }
    return $out;
  }
}

if (!function_exists('fiscal_nfce_apply_internal_cancellation_effects')) {
  /**
   * Aplica pedido/estoque/financeiro/caixa somente após confirmação do
   * cancelamento pela SEFAZ. A rotina nexa_cancel_order é idempotente.
   */
  function fiscal_nfce_apply_internal_cancellation_effects(PDO $pdo, int $companyId, int $docId, array $doc, string $reason): array {
    $result = ['internal_sync'=>null, 'internal_error'=>''];
    if (strtoupper((string)($doc['source_type'] ?? '')) !== 'PEDIDO' || (int)($doc['source_id'] ?? 0) <= 0) {
      return $result;
    }
    try {
      $otherDocSt = $pdo->prepare("SELECT id, model, number FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? AND id<>? AND UPPER(COALESCE(status,''))='AUTORIZADA' ORDER BY id DESC LIMIT 1");
      $otherDocSt->execute([$companyId, (int)$doc['source_id'], $docId]);
      $otherAuthorized = $otherDocSt->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($otherAuthorized) {
        $otherLabel = ((string)($otherAuthorized['model'] ?? '') === '55') ? 'NF-e' : 'NFC-e';
        throw new RuntimeException('O pedido possui outro documento fiscal autorizado (' . $otherLabel . ' nº ' . (string)($otherAuthorized['number'] ?? '') . '). O pedido não foi cancelado automaticamente; cancele ou regularize o outro documento primeiro.');
      }
      require_once __DIR__ . '/order_cancellation.php';
      $cancelUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $previousCrossFiscalCancel = $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_CANCEL'] ?? null;
      $isCrossFiscalCancel = function_exists('fiscal_multi_company_cancel_homologation_is_cross')
        && fiscal_multi_company_cancel_homologation_is_cross($pdo);
      if ($isCrossFiscalCancel) $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_CANCEL'] = true;
      try {
        $result['internal_sync'] = nexa_cancel_order(
          $pdo,
          $companyId,
          (int)$doc['source_id'],
          trim($reason) !== '' ? $reason : 'Cancelamento fiscal confirmado pela SEFAZ.',
          'FISCAL',
          $docId,
          $cancelUserId
        );
      } finally {
        if ($previousCrossFiscalCancel === null) unset($GLOBALS['NEXA_MULTI_COMPANY_FISCAL_CANCEL']);
        else $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_CANCEL'] = $previousCrossFiscalCancel;
      }
    } catch (Throwable $syncError) {
      $result['internal_error'] = $syncError->getMessage();
      try {
        require_once __DIR__ . '/order_cancellation.php';
        if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
          nexa_order_cancellation_ensure_schema($pdo);
        }
        $hasInternalCols = function_exists('nexa_cancel_column_exists')
          && nexa_cancel_column_exists($pdo, 'fiscal_documents', 'internal_cancellation_status')
          && nexa_cancel_column_exists($pdo, 'fiscal_documents', 'internal_cancellation_message')
          && nexa_cancel_column_exists($pdo, 'fiscal_documents', 'internal_cancellation_at');
        if ($hasInternalCols) {
          $pdo->prepare("UPDATE fiscal_documents SET internal_cancellation_status='ERROR', internal_cancellation_message=?, internal_cancellation_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute([$result['internal_error'], $companyId, $docId]);
        }
      } catch (Throwable $ignored) {}
    }
    return $result;
  }
}

if (!function_exists('fiscal_nfce_response_summary')) {
  function fiscal_nfce_response_summary(string $responseXml): array {
    $emptyCancel = ['found'=>false,'code'=>'','message'=>'','protocol'=>'','sequence'=>'','event_type'=>'','registered_at'=>''];
    if (trim($responseXml) === '') return ['code'=>'', 'message'=>'', 'protocol'=>'', 'authorized'=>false, 'cancelled'=>false, 'cancel_event'=>$emptyCancel, 'object'=>null];
    $stdCl = new \NFePHP\NFe\Common\Standardize($responseXml);
    $respObj = $stdCl->toStd();
    $topCode = (string)(fiscal_nfce_object_get($respObj, 'cStat') ?? '');
    $topMsg = (string)(fiscal_nfce_object_get($respObj, 'xMotivo') ?? '');
    $prot = fiscal_nfce_protocol_from_response($respObj);
    $protCode = $prot ? (string)(fiscal_nfce_object_get($prot, 'cStat') ?? '') : '';
    $protMsg = $prot ? (string)(fiscal_nfce_object_get($prot, 'xMotivo') ?? '') : '';
    $protocol = $prot ? (string)(fiscal_nfce_object_get($prot, 'nProt') ?? '') : '';
    $protocolKey = $prot ? preg_replace('/\D+/', '', (string)(fiscal_nfce_object_get($prot, 'chNFe') ?? '')) : '';
    $code = $protCode !== '' ? $protCode : $topCode;
    $message = $protMsg !== '' ? $protMsg : $topMsg;
    $cancelEvent = fiscal_nfce_cancel_event_from_consult_response($responseXml);
    return [
      'code' => $code,
      'message' => $message,
      'protocol' => $protocol,
      'protocol_key' => $protocolKey,
      'authorized' => in_array($code, ['100','150'], true),
      'cancelled' => in_array($code, ['101','135','151','155'], true),
      'cancel_event' => $cancelEvent,
      'object' => $respObj,
      'protocol_object' => $prot,
    ];
  }
}

if (!function_exists('fiscal_nfce_is_duplicate_response')) {
  function fiscal_nfce_is_duplicate_response(string $code, string $message): bool {
    $m = function_exists('mb_strtolower') ? mb_strtolower($message) : strtolower($message);
    return in_array(trim($code), ['204','539'], true) || str_contains($m, 'duplicidade');
  }
}

if (!function_exists('fiscal_nfce_append_event')) {
  function fiscal_nfce_append_event(PDO $pdo, int $companyId, int $docId, string $type, string $code = '', string $message = '', string $protocol = '', string $requestXml = '', string $responseXml = '', string $notes = ''): void {
    try {
      $seqSt = $pdo->prepare('SELECT COALESCE(MAX(event_sequence),0)+1 FROM fiscal_document_events WHERE company_id=? AND fiscal_document_id=? AND event_type=?');
      $seqSt->execute([$companyId, $docId, $type]);
      $seq = max(1, (int)$seqSt->fetchColumn());
      $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, protocol_number, status_code, status_message, request_xml, response_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,?,?,NOW())')
        ->execute([$docId, $companyId, $type, $seq, $protocol !== '' ? $protocol : null, $code !== '' ? $code : null, $message !== '' ? (function_exists('mb_substr') ? mb_substr($message, 0, 255) : substr($message, 0, 255)) : null, $requestXml !== '' ? $requestXml : null, $responseXml !== '' ? $responseXml : null, $notes]);
    } catch (Throwable $ignored) {}
  }
}

if (!function_exists('fiscal_nfce_authorization_datetime_from_xml')) {
  function fiscal_nfce_authorization_datetime_from_xml(string $xml): ?string {
    $xml = trim($xml);
    if ($xml === '') return null;
    if (preg_match('/<dhRecbto>([^<]+)<\/dhRecbto>/i', $xml, $m)) {
      return fiscal_sql_datetime((string)($m[1] ?? ''));
    }
    return null;
  }
}

if (!function_exists('fiscal_nfce_mark_result')) {
  function fiscal_nfce_mark_result(PDO $pdo, int $companyId, int $docId, string $status, string $code = '', string $message = '', string $protocol = '', ?string $xmlRaw = null): void {
    $sql = 'UPDATE fiscal_documents SET status=?, sefaz_status_code=?, sefaz_status_message=?, protocol_number=?, updated_at=NOW()';
    $params = [$status, $code !== '' ? $code : null, $message !== '' ? (function_exists('mb_substr') ? mb_substr($message, 0, 255) : substr($message, 0, 255)) : null, $protocol !== '' ? $protocol : null];
    if ($xmlRaw !== null) { $sql .= ', xml_raw=?, xml_hash=?'; $params[] = $xmlRaw; $params[] = hash('sha256', $xmlRaw); }
    if (strtoupper(trim($status)) === 'AUTORIZADA') {
      $authDt = $xmlRaw !== null ? fiscal_nfce_authorization_datetime_from_xml($xmlRaw) : null;
      if ($authDt !== null) { $sql .= ', authorization_datetime=?'; $params[] = $authDt; }
      else { $sql .= ', authorization_datetime=COALESCE(authorization_datetime,NOW())'; }
    }
    $sql .= " WHERE company_id=? AND id=? AND UPPER(COALESCE(status,'')) <> 'DESCARTADA'";
    $params[] = $companyId; $params[] = $docId;
    $pdo->prepare($sql)->execute($params);
  }
}


if (!function_exists('fiscal_nfe_normalize_nfce_referenced_cfop')) {
  function fiscal_nfe_normalize_nfce_referenced_cfop(PDO $pdo, int $companyId, int $docId): void {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? AND model='55' LIMIT 1");
    $st->execute([$companyId, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc || strtoupper((string)($doc['source_type'] ?? '')) !== 'NFCE_REFERENCIADA') return;
    $cfg = fiscal_company_config($pdo, $companyId);
    $dest = fiscal_nfce_document_participant($pdo, $companyId, $docId, 'DESTINATARIO');
    $destUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($dest['state'] ?? '')), 0, 2));
    $cfop = fiscal_nfe_after_nfce_cfop(['uf' => $destUf, 'state' => $destUf], $cfg);

    $upd = $pdo->prepare("UPDATE fiscal_document_items SET cfop=?, updated_at=NOW() WHERE company_id=? AND fiscal_document_id=? AND cfop IN ('5929','6929')");
    $upd->execute([$cfop, $companyId, $docId]);
    if ($upd->rowCount() > 0 || trim((string)($doc['xml_raw'] ?? '')) === '') {
      $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
      $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$xml, hash('sha256', $xml), $companyId, $docId]);
    }
  }
}

if (!function_exists('fiscal_nfce_extract_access_key')) {
  function fiscal_nfce_extract_access_key(string $xml): string {
    $xml = trim($xml);
    if ($xml === '') return '';
    try {
      $dom = new DOMDocument();
      if (!@$dom->loadXML($xml)) return '';
      $nodes = $dom->getElementsByTagName('infNFe');
      if ($nodes->length < 1) return '';
      $id = (string)$nodes->item(0)->getAttribute('Id');
      $key = preg_replace('/\D+/', '', preg_replace('/^NFe/i', '', $id)) ?: '';
      return strlen($key) === 44 ? $key : '';
    } catch (Throwable $e) { return ''; }
  }
}

if (!function_exists('fiscal_contingency_reason')) {
  function fiscal_contingency_reason(array $cfg, string $fallback = ''): string {
    $reason = trim($fallback !== '' ? $fallback : (string)($cfg['contingency_reason'] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($reason) : strlen($reason);
    if ($length < 15) $reason = 'Falha de comunicação com o ambiente autorizador da SEFAZ.';
    return function_exists('mb_substr') ? mb_substr($reason, 0, 255) : substr($reason, 0, 255);
  }
}

if (!function_exists('fiscal_nfce_service_status')) {
  function fiscal_nfce_service_status(object $tools): array {
    try {
      $xml = $tools->sefazStatus('', null, true);
      $summary = fiscal_nfce_response_summary($xml);
      $code = (string)($summary['code'] ?? '');
      $message = (string)($summary['message'] ?? '');
      return ['available'=>$code === '107', 'code'=>$code, 'message'=>$message, 'response'=>$xml, 'exception'=>''];
    } catch (Throwable $e) {
      return ['available'=>false, 'code'=>'', 'message'=>'Falha ao consultar o status do serviço.', 'response'=>'', 'exception'=>$e->getMessage()];
    }
  }
}

if (!function_exists('fiscal_nfce_prepare_offline_contingency')) {
  function fiscal_nfce_prepare_offline_contingency(PDO $pdo, int $companyId, array $cfg, array $doc, string $reason = ''): array {
    if ((string)($doc['model'] ?? '') !== '65') throw new RuntimeException('A contingência offline automática é exclusiva da NFC-e modelo 65.');
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) throw new RuntimeException('Documento fiscal inválido para contingência.');
    $reason = fiscal_contingency_reason($cfg, $reason);
    $issueDate = (string)($doc['issue_datetime'] ?? date('Y-m-d H:i:s'));
    $series = (string)($doc['series'] ?? '1');
    $number = max(1, (int)($doc['number'] ?? 0));
    $key = fiscal_nfce_make_access_key($cfg, '65', $series, $number, $issueDate, '9');

    $pdo->prepare("UPDATE fiscal_documents
      SET emission_type='NFC_OFFLINE', access_key=?, status='CONTINGENCIA', contingency_started_at=COALESCE(contingency_started_at,NOW()),
          contingency_reason=?, protocol_number=NULL, sefaz_status_code=NULL, sefaz_status_message='NFC-e emitida em contingência offline. Aguardando transmissão.',
          xml_raw=NULL, xml_hash=NULL, updated_at=NOW()
      WHERE company_id=? AND id=?")
      ->execute([$key, $reason, $companyId, $docId]);

    $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, '65');
    $signedXml = $tools->signNFe($xml);
    $pdo->prepare("UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$signedXml, hash('sha256', $signedXml), $companyId, $docId]);
    fiscal_nfce_append_event($pdo, $companyId, $docId, 'CONTINGENCIA_OFFLINE_NFCE', '9', 'NFC-e emitida em contingência offline.', '', $signedXml, '', $reason);

    return [
      'ok'=>true, 'status'=>'CONTINGENCIA', 'code'=>'9',
      'message'=>'NFC-e emitida em contingência offline. Imprima o DANFE e transmita pelo menu Notas em lote assim que a SEFAZ normalizar.',
      'protocol'=>'', 'xml'=>$signedXml, 'response'=>'', 'doc_id'=>$docId,
      'contingency'=>true, 'contingency_type'=>'NFC_OFFLINE', 'requires_batch'=>true,
    ];
  }
}

if (!function_exists('fiscal_nfe_authorize_svc')) {
  function fiscal_nfe_authorize_svc(PDO $pdo, int $companyId, array $cfg, array $doc, string $reason = ''): array {
    if ((string)($doc['model'] ?? '') !== '55') throw new RuntimeException('A SVC é aplicável à NF-e modelo 55.');
    $docId = (int)($doc['id'] ?? 0);
    if ($docId <= 0) throw new RuntimeException('Documento fiscal inválido para contingência SVC.');
    fiscal_nfce_require_nfephp($pdo, $companyId);
    if (!function_exists('mb_convert_encoding')) {
      throw new RuntimeException('A contingência SVC requer a extensão PHP mbstring. Instale php-mbstring e reinicie o Apache/PHP-FPM.');
    }
    $reason = fiscal_contingency_reason($cfg, $reason);
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? 'MG')), 0, 2)) ?: 'MG';
    $svcType = strtoupper(trim((string)($cfg['nfe_svc_type'] ?? 'AUTO')));
    if (!in_array($svcType, ['AUTO','SVCAN','SVCRS'], true)) $svcType = 'AUTO';
    $contingency = new \NFePHP\NFe\Factories\Contingency();
    $contingency->activate($uf, $reason, $svcType === 'AUTO' ? '' : $svcType);
    $resolvedType = strtoupper((string)$contingency->type);

    // Sempre parte de uma chave/XML normal sem assinatura; o NFePHP ajusta tpEmis,
    // chave, dhCont e xJust e assina para a SVC.
    $normalKey = fiscal_nfce_make_access_key(
      $cfg,
      '55',
      (string)($doc['series'] ?? '1'),
      max(1, (int)($doc['number'] ?? 0)),
      (string)($doc['issue_datetime'] ?? date('Y-m-d H:i:s')),
      '1'
    );
    $pdo->prepare("UPDATE fiscal_documents SET emission_type='NORMAL', access_key=?, contingency_reason=?, contingency_started_at=COALESCE(contingency_started_at,NOW()), xml_raw=NULL, xml_hash=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$normalKey, $reason, $companyId, $docId]);
    $unsignedXml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, '55', $contingency);
    $contingencyXmls = [];
    $responseXml = '';
    $signedXml = '';
    $sendStarted = false;
    try {
      $idLote = str_pad((string)max(1, $docId), 15, '0', STR_PAD_LEFT);
      $sendStarted = true;
      $responseXml = $tools->sefazEnviaLote([$unsignedXml], $idLote, 1, false, $contingencyXmls);
      $signedXml = trim((string)($contingencyXmls[0] ?? ''));
      if ($signedXml === '') throw new RuntimeException('O motor fiscal não devolveu o XML ajustado para a SVC.');
      $key = fiscal_nfce_extract_access_key($signedXml);
      if ($key === '') throw new RuntimeException('Não foi possível extrair a nova chave de acesso do XML em contingência SVC.');
      $emissionType = $resolvedType === 'SVCRS' ? 'SVCRS' : 'SVCAN';
      $pdo->prepare("UPDATE fiscal_documents
        SET emission_type=?, access_key=?, status='ASSINADA', contingency_reason=?, contingency_started_at=COALESCE(contingency_started_at,NOW()),
            xml_raw=?, xml_hash=?, updated_at=NOW()
        WHERE company_id=? AND id=?")
        ->execute([$emissionType, $key, $reason, $signedXml, hash('sha256', $signedXml), $companyId, $docId]);

      $summary = fiscal_nfce_response_summary($responseXml);
      $code = (string)($summary['code'] ?? '');
      $message = (string)($summary['message'] ?? '');
      $protocol = (string)($summary['protocol'] ?? '');
      if (!empty($summary['authorized'])) {
        $protocolKey = preg_replace('/\D+/', '', (string)($summary['protocol_key'] ?? ''));
        if ($protocol === '' || ($protocolKey !== '' && $protocolKey !== $key)) {
          $safeMsg = 'A SVC retornou autorização, porém o protocolo não corresponde à chave do XML em contingência. Consulte a situação pela chave antes de imprimir.';
          fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CONSULTADA', $code, $safeMsg, $protocol, $signedXml);
          fiscal_nfce_append_event($pdo, $companyId, $docId, 'AUTORIZACAO_NFE_SVC_INCONSISTENTE', $code, $safeMsg, $protocol, $signedXml, $responseXml, $reason);
          return ['ok'=>false,'status'=>'CONSULTADA','code'=>$code,'message'=>$safeMsg,'protocol'=>$protocol,'response'=>$responseXml,'contingency'=>true,'contingency_type'=>$emissionType,'requires_batch'=>false];
        }
        $authorizedXml = \NFePHP\NFe\Complements::toAuthorize($signedXml, $responseXml);
        fiscal_nfce_mark_result($pdo, $companyId, $docId, 'AUTORIZADA', $code, $message ?: 'NF-e autorizada em contingência SVC.', $protocol, $authorizedXml);
        fiscal_nfce_append_event($pdo, $companyId, $docId, 'AUTORIZACAO_NFE_SVC', $code, $message, $protocol, $signedXml, $responseXml, $reason);
        return ['ok'=>true,'status'=>'AUTORIZADA','code'=>$code,'message'=>$message ?: 'NF-e autorizada em contingência SVC.','protocol'=>$protocol,'xml'=>$authorizedXml,'response'=>$responseXml,'doc_id'=>$docId,'contingency'=>true,'contingency_type'=>$emissionType,'requires_batch'=>false];
      }
      $status = fiscal_nfce_is_duplicate_response($code, $message) ? 'DUPLICIDADE' : 'REJEITADA';
      fiscal_nfce_mark_result($pdo, $companyId, $docId, $status, $code, $message ?: 'NF-e não autorizada pela SVC.', $protocol, $signedXml);
      fiscal_nfce_append_event($pdo, $companyId, $docId, 'AUTORIZACAO_NFE_SVC', $code, $message, $protocol, $signedXml, $responseXml, $reason);
      return ['ok'=>false,'status'=>$status,'code'=>$code,'message'=>$message ?: 'NF-e não autorizada pela SVC.','protocol'=>$protocol,'xml'=>$signedXml,'response'=>$responseXml,'contingency'=>true,'contingency_type'=>$emissionType,'requires_batch'=>false];
    } catch (Throwable $e) {
      $signedXml = $signedXml !== '' ? $signedXml : trim((string)($contingencyXmls[0] ?? ''));
      if ($sendStarted && $signedXml !== '') {
        $key = fiscal_nfce_extract_access_key($signedXml);
        $emissionType = $resolvedType === 'SVCRS' ? 'SVCRS' : 'SVCAN';
        $message = 'Falha de comunicação após iniciar a transmissão para a SVC. Consulte a situação pela chave antes de tentar emissão normal. Detalhe: ' . $e->getMessage();
        $pdo->prepare("UPDATE fiscal_documents SET emission_type=?, access_key=COALESCE(NULLIF(?,''),access_key), status='PENDENTE_RETORNO', contingency_reason=?, contingency_started_at=COALESCE(contingency_started_at,NOW()), sefaz_status_message=?, xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$emissionType, $key, $reason, function_exists('mb_substr') ? mb_substr($message,0,255) : substr($message,0,255), $signedXml, hash('sha256',$signedXml), $companyId, $docId]);
        fiscal_nfce_append_event($pdo, $companyId, $docId, 'PENDENTE_RETORNO_NFE_SVC', 'ERRO', $message, '', $signedXml, $responseXml, $reason);
        throw new RuntimeException($message);
      }
      fiscal_nfce_mark_result($pdo, $companyId, $docId, 'ERRO_AUTORIZACAO', '', $e->getMessage(), '', null);
      fiscal_nfce_append_event($pdo, $companyId, $docId, 'ERRO_AUTORIZACAO_NFE_SVC', 'ERRO', $e->getMessage(), '', $unsignedXml, $responseXml, $reason);
      throw $e;
    }
  }
}

if (!function_exists('fiscal_nfce_authorize_document')) {
  function fiscal_nfce_authorize_document(PDO $pdo, int $companyId, int $docId): array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? AND model IN ('55','65') LIMIT 1");
    $st->execute([$companyId, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
    if (strtoupper(trim((string)($doc['status'] ?? ''))) === 'DESCARTADA') {
      throw new RuntimeException('Documento rejeitado arquivado por descarte local. Emissão, consulta alteradora e cancelamento bloqueados; confira o histórico fiscal.');
    }
    $model = in_array((string)($doc['model'] ?? '65'), ['55','65'], true) ? (string)$doc['model'] : '65';
    $multiCompanyFiscalCross = fiscal_multi_company_document_homologation_is_cross($pdo, $model);
    $cfg = $multiCompanyFiscalCross
      ? fiscal_multi_company_document_homologation_assert($pdo, $companyId, $model, 'emitir')
      : fiscal_company_config($pdo, $companyId);
    $adapter = strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP'));
    if ($adapter !== 'NFEPHP') throw new RuntimeException('A contingência automática desta versão requer NFePHP/sped-nfe como motor de emissão fiscal.');

    if ($multiCompanyFiscalCross && !(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, (string)($doc['environment'] ?? '')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a nota') : 'V11.46 Multiempresa: documento fiscal fora de HOMOLOGAÇÃO permanece bloqueado no F11.');
    }
    $docLabel = fiscal_doc_model_label($model);
    if (strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA') throw new RuntimeException('Esta ' . $docLabel . ' já está autorizada.');

    // V11.43 R7: NFC-e normal rejeitada deve ser reconstruída com dhEmi atual.
    // A SEFAZ trata a NFC-e como documento on-line e rejeita (704) quando a data/hora
    // de emissão está atrasada. Mantemos série/número, mas atualizamos dhEmi e a chave
    // antes de validar, assinar e retransmitir. NF-e modelo 55 não é afetada.
    if ($model === '65'
        && strtoupper(trim((string)($doc['status'] ?? ''))) === 'REJEITADA'
        && in_array(strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL'))) ?: 'NORMAL', ['NORMAL','1'], true)) {
      $issue = date('Y-m-d H:i:s');
      $series = preg_replace('/\D+/', '', (string)($doc['series'] ?? '1')) ?: '1';
      $number = max(1, (int)($doc['number'] ?? 0));
      $oldKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
      $newKey = fiscal_nfce_make_access_key($cfg, '65', $series, $number, $issue, '1');

      $pdo->prepare("UPDATE fiscal_documents
        SET operation_datetime=COALESCE(operation_datetime,issue_datetime,created_at),
            issue_datetime=?, exit_entry_datetime=?, access_key=?, emission_type='NORMAL',
            xml_raw=NULL, xml_hash=NULL, updated_at=NOW()
        WHERE company_id=? AND id=? AND status='REJEITADA'")
        ->execute([$issue, $issue, $newKey, $companyId, $docId]);

      try {
        fiscal_nfce_append_event(
          $pdo, $companyId, $docId, 'PREPARACAO_REENVIO_NFCE', 'INFO',
          'NFC-e rejeitada preparada para novo envio com data/hora de emissão atual.',
          '', '', '',
          'Chave anterior: ' . ($oldKey !== '' ? $oldKey : '-') . ' | Nova chave: ' . $newKey . ' | dhEmi: ' . $issue
        );
      } catch (Throwable $ignored) {}

      $st->execute([$companyId, $docId]);
      $doc = $st->fetch(PDO::FETCH_ASSOC) ?: $doc;
    }

    if ($model === '55' && strtoupper((string)($doc['source_type'] ?? '')) === 'NFCE_REFERENCIADA') {
      fiscal_nfe_normalize_nfce_referenced_cfop($pdo, $companyId, $docId);
      $st->execute([$companyId, $docId]);
      $doc = $st->fetch(PDO::FETCH_ASSOC) ?: $doc;
    }
    fiscal_nfce_validate_authorization_rules($pdo, $companyId, $docId);

    $emissionType = strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL')));
    $statusUpper = strtoupper(trim((string)($doc['status'] ?? '')));
    if ($statusUpper === 'PENDENTE_RETORNO') {
      throw new RuntimeException('Este documento possui transmissão com retorno desconhecido. Use Consultar situação/Recuperar autorização pela chave antes de qualquer novo envio.');
    }
    if ($model === '55' && in_array($emissionType, ['SVCAN','SVCRS','SVC-AN','SVC-RS'], true)) {
      // Só chega aqui após uma resposta explícita sem autorização. Volta à chave normal
      // para uma nova tentativa no autorizador de origem; retornos desconhecidos foram bloqueados acima.
      $normalKey = fiscal_nfce_make_access_key($cfg, '55', (string)($doc['series'] ?? '1'), max(1,(int)($doc['number'] ?? 0)), (string)($doc['issue_datetime'] ?? date('Y-m-d H:i:s')), '1');
      $pdo->prepare("UPDATE fiscal_documents SET emission_type='NORMAL', access_key=?, xml_raw=NULL, xml_hash=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
        ->execute([$normalKey, $companyId, $docId]);
      $st->execute([$companyId, $docId]);
      $doc = $st->fetch(PDO::FETCH_ASSOC) ?: $doc;
      $emissionType = 'NORMAL';
    }
    $isOfflinePending = $model === '65' && (in_array($emissionType, ['NFC_OFFLINE','OFFLINE'], true) || in_array($statusUpper, ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE'], true));
    if ($multiCompanyFiscalCross && $isOfflinePending) {
      throw new RuntimeException('Multiempresa (F11): contingência/offline ainda não foi homologada no F11. Use a emissão normal ou entre diretamente na empresa.');
    }
    // Multiempresa em homologação não entra automaticamente em contingência. Se a SEFAZ
    // de homologação estiver indisponível, mantém o documento pendente para nova tentativa.
    // Para NF-e isso também bloqueia SVC-AN/SVC-RS nesta etapa.
    $contingencyEnabled = $multiCompanyFiscalCross ? false : !empty($cfg['contingency_enabled']);
    $reason = fiscal_contingency_reason($cfg);

    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, $model);
    if (!empty($cfg['contingency_status_check_enabled'])) {
      if (function_exists('nexa_progress')) nexa_progress('Verificando se a SEFAZ está disponível...');
      $service = fiscal_nfce_service_status($tools);
      if (empty($service['available'])) {
        $serviceReason = trim((string)($service['message'] ?? '') . ' ' . (string)($service['exception'] ?? ''));
        $serviceReason = fiscal_contingency_reason($cfg, $serviceReason !== '' ? $serviceReason : $reason);
        if ($isOfflinePending) {
          throw new RuntimeException('O serviço autorizador ainda não está disponível. A NFC-e permanece no lote de contingência. Retorno: ' . trim((string)($service['code'] ?? '') . ' ' . (string)($service['message'] ?? '') . ' ' . (string)($service['exception'] ?? '')));
        }
        if ($contingencyEnabled && $model === '65' && !empty($cfg['nfce_offline_contingency_enabled'])) {
          if (function_exists('nexa_progress')) nexa_progress('SEFAZ indisponível: emitindo a NFC-e em contingência...', 'warn');
          return fiscal_nfce_prepare_offline_contingency($pdo, $companyId, $cfg, $doc, $serviceReason);
        }
        if ($contingencyEnabled && $model === '55' && !empty($cfg['nfe_svc_contingency_enabled'])) {
          return fiscal_nfe_authorize_svc($pdo, $companyId, $cfg, $doc, $serviceReason);
        }
        throw new RuntimeException('Serviço autorizador indisponível e contingência não habilitada para este documento. Retorno: ' . trim((string)($service['code'] ?? '') . ' ' . (string)($service['message'] ?? '') . ' ' . (string)($service['exception'] ?? '')));
      }
    }

    // V11.36.5: qualquer documento ainda não autorizado é reconstruído antes do envio.
    // Para NFC-e offline isso é essencial: o XML armazenado pode estar assinado pelo
    // certificado anterior (inclusive já vencido). Reutilizar aquela <Signature> faria
    // a SEFAZ continuar rejeitando com 291 mesmo após cadastrar um novo A1.
    // A reconstrução preserva número, série, chave, dhEmi, tpEmis=9, dhCont e xJust;
    // somente a assinatura será refeita com o certificado ATIVO atual.
    $previousXml = trim((string)($doc['xml_raw'] ?? ''));
    $hadStoredSignature = $isOfflinePending && $previousXml !== '' && str_contains($previousXml, '<Signature');
    if (function_exists('nexa_progress')) nexa_progress('Montando o XML da ' . $docLabel . '...');
    $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
    $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$xml, hash('sha256', $xml), $companyId, $docId]);
    if ($isOfflinePending && $hadStoredSignature) {
      try {
        fiscal_nfce_append_event(
          $pdo, $companyId, $docId, 'REASSINATURA_NFCE_CONTINGENCIA', 'INFO',
          'XML de contingência reconstruído para nova assinatura com o certificado digital ativo.',
          '', $previousXml, '',
          'A assinatura anterior não foi reutilizada. Número, série, chave, dhEmi e dados de contingência foram preservados.'
        );
      } catch (Throwable $ignored) {}
    }

    $signedXml = '';
    $responseXml = '';
    $sendStarted = false;
    try {
      if ($isOfflinePending) {
        $pdo->prepare('UPDATE fiscal_documents SET contingency_transmission_attempts=contingency_transmission_attempts+1, contingency_last_attempt_at=NOW(), updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $docId]);
      }
      if (function_exists('nexa_progress')) nexa_progress('Assinando com o certificado digital...');
      $signedXml = str_contains($xml, '<Signature') ? $xml : $tools->signNFe($xml);
      $statusMessage = $isOfflinePending ? 'XML de contingência assinado. Transmitindo para a SEFAZ.' : 'XML assinado. Enviando para a SEFAZ.';
      fiscal_nfce_mark_result($pdo, $companyId, $docId, $isOfflinePending ? 'CONTINGENCIA' : 'ASSINADA', '', $statusMessage, '', $signedXml);
      $idLote = str_pad((string)max(1, $docId), 15, '0', STR_PAD_LEFT);
      $sendStarted = true;
      if (function_exists('nexa_progress')) nexa_progress('Transmitindo para a SEFAZ e aguardando a autorização...');
      $responseXml = $tools->sefazEnviaLote([$signedXml], $idLote, 1);
      $summary = fiscal_nfce_response_summary($responseXml);
      $finalCode = (string)$summary['code'];
      $finalMsg = (string)$summary['message'];
      $protocol = (string)$summary['protocol'];

      if (!empty($summary['authorized'])) {
        $protocolKey = preg_replace('/\D+/', '', (string)($summary['protocol_key'] ?? ''));
        $docAccessKey = fiscal_nfce_extract_access_key($signedXml);
        if ($docAccessKey === '') $docAccessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
        if ($protocol === '' || ($protocolKey !== '' && $docAccessKey !== '' && $protocolKey !== $docAccessKey)) {
          $safeMsg = 'A SEFAZ retornou autorização, porém o protocolo não corresponde à chave deste documento. Consulte a situação pela chave antes de imprimir.';
          fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CONSULTADA', $finalCode, $safeMsg, $protocol, $signedXml);
          fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'AUTORIZACAO_NFE_INCONSISTENTE' : 'AUTORIZACAO_NFCE_INCONSISTENTE', $finalCode, $safeMsg, $protocol, $signedXml, $responseXml, 'Resposta de autorização sem protocolo válido ou com chave divergente.');
          return ['ok'=>false, 'status'=>'CONSULTADA', 'code'=>$finalCode, 'message'=>$safeMsg, 'protocol'=>$protocol, 'response'=>$responseXml];
        }
        $authorizedXml = \NFePHP\NFe\Complements::toAuthorize($signedXml, $responseXml);
        fiscal_nfce_mark_result($pdo, $companyId, $docId, 'AUTORIZADA', $finalCode, $finalMsg ?: 'Autorizado o uso da ' . $docLabel . '.', $protocol, $authorizedXml);
        if (function_exists('nexa_progress')) nexa_progress($docLabel . ' autorizada! Protocolo ' . $protocol . '. Abrindo a impressão...', 'ok');
        fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'AUTORIZACAO_NFE' : ($isOfflinePending ? 'AUTORIZACAO_NFCE_CONTINGENCIA' : 'AUTORIZACAO_NFCE'), $finalCode, $finalMsg, $protocol, $signedXml, $responseXml, $isOfflinePending ? 'NFC-e de contingência offline transmitida e autorizada.' : ($docLabel . ' autorizada pelo NFePHP.'));
        return ['ok'=>true, 'status'=>'AUTORIZADA', 'code'=>$finalCode, 'message'=>$finalMsg, 'protocol'=>$protocol, 'xml'=>$authorizedXml, 'response'=>$responseXml, 'contingency'=>$isOfflinePending, 'contingency_type'=>$isOfflinePending ? 'NFC_OFFLINE' : '', 'requires_batch'=>false];
      }
      if (fiscal_nfce_is_duplicate_response($finalCode, $finalMsg)) {
        fiscal_nfce_mark_result($pdo, $companyId, $docId, 'DUPLICIDADE', $finalCode, $finalMsg ?: 'Duplicidade de ' . $docLabel . '. Consulte/recupere a autorização pela chave de acesso.', $protocol, $signedXml);
        fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'DUPLICIDADE_NFE' : 'DUPLICIDADE_NFCE', $finalCode, $finalMsg, $protocol, $signedXml, $responseXml, 'SEFAZ retornou duplicidade. Use Recuperar ' . $docLabel . ' para consultar a chave e anexar o protocolo, quando autorizado.');
        return ['ok'=>false, 'status'=>'DUPLICIDADE', 'code'=>$finalCode, 'message'=>$finalMsg, 'protocol'=>$protocol, 'xml'=>$signedXml, 'response'=>$responseXml];
      }
      if (!$isOfflinePending && $model === '55' && $contingencyEnabled && !empty($cfg['nfe_svc_contingency_enabled']) && in_array($finalCode, ['108','109'], true)) {
        return fiscal_nfe_authorize_svc($pdo, $companyId, $cfg, $doc, $finalMsg ?: $reason);
      }
      if (!$isOfflinePending && $model === '65' && in_array($finalCode, ['108','109'], true)) {
        $msg = 'A tentativa normal retornou serviço indisponível. O documento ficou pendente de consulta para evitar reutilização indevida da numeração. Consulte/recupere pela chave antes de qualquer nova emissão.';
        fiscal_nfce_mark_result($pdo, $companyId, $docId, 'PENDENTE_RETORNO', $finalCode, $msg, $protocol, $signedXml);
        fiscal_nfce_append_event($pdo, $companyId, $docId, 'PENDENTE_RETORNO_NFCE', $finalCode, $msg, $protocol, $signedXml, $responseXml, 'Não convertido automaticamente em offline após tentativa de transmissão.');
        return ['ok'=>false,'status'=>'PENDENTE_RETORNO','code'=>$finalCode,'message'=>$msg,'protocol'=>$protocol,'xml'=>$signedXml,'response'=>$responseXml];
      }
      $status = in_array($finalCode, ['103','104','105'], true) ? 'EM_PROCESSAMENTO' : ($isOfflinePending ? 'CONTINGENCIA' : 'REJEITADA');
      fiscal_nfce_mark_result($pdo, $companyId, $docId, $status, $finalCode, $finalMsg ?: $docLabel . ' não autorizada pela SEFAZ.', $protocol, $signedXml);
      fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'AUTORIZACAO_NFE' : ($isOfflinePending ? 'TRANSMISSAO_NFCE_CONTINGENCIA' : 'AUTORIZACAO_NFCE'), $finalCode, $finalMsg, $protocol, $signedXml, $responseXml, $isOfflinePending ? 'NFC-e permanece no lote de contingência.' : 'Retorno da SEFAZ sem autorização.');
      if (function_exists('nexa_progress')) nexa_progress('A SEFAZ não autorizou: ' . trim($finalCode . ' ' . $finalMsg), 'warn');
      return ['ok'=>false, 'status'=>$status, 'code'=>$finalCode, 'message'=>$finalMsg, 'protocol'=>$protocol, 'xml'=>$signedXml, 'response'=>$responseXml, 'contingency'=>$isOfflinePending, 'contingency_type'=>$isOfflinePending ? 'NFC_OFFLINE' : '', 'requires_batch'=>$isOfflinePending];
    } catch (Throwable $e) {
      if ($isOfflinePending) {
        fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CONTINGENCIA', '', 'Falha ao transmitir a NFC-e de contingência: ' . $e->getMessage(), '', $signedXml !== '' ? $signedXml : null);
        fiscal_nfce_append_event($pdo, $companyId, $docId, 'ERRO_TRANSMISSAO_NFCE_CONTINGENCIA', 'ERRO', $e->getMessage(), '', $signedXml, $responseXml, 'Documento mantido no lote de contingência.');
        throw new RuntimeException('A NFC-e continua em contingência e não foi retirada do lote. Falha na transmissão: ' . $e->getMessage());
      }
      $status = $sendStarted ? 'PENDENTE_RETORNO' : 'ERRO_AUTORIZACAO';
      $message = $sendStarted
        ? 'Falha de comunicação após iniciar a transmissão. Consulte a situação pela chave antes de reenviar ou emitir outro documento com a mesma numeração. Detalhe: ' . $e->getMessage()
        : $e->getMessage();
      fiscal_nfce_mark_result($pdo, $companyId, $docId, $status, '', $message, '', $signedXml !== '' ? $signedXml : null);
      fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'ERRO_AUTORIZACAO_NFE' : 'ERRO_AUTORIZACAO_NFCE', 'ERRO', $message, '', $signedXml, $responseXml, $sendStarted ? 'Transmissão iniciada; situação deve ser conciliada pela chave.' : 'Falha antes da transmissão.');
      throw new RuntimeException($message);
    }
  }
}

if (!function_exists('fiscal_nfce_consult_document_status')) {
  function fiscal_nfce_consult_document_status(PDO $pdo, int $companyId, int $docId): array {
    fiscal_nfce_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? AND model IN ('55','65') LIMIT 1");
    $st->execute([$companyId, $docId]);
    $doc = $st->fetch(PDO::FETCH_ASSOC);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
    if (strtoupper(trim((string)($doc['status'] ?? ''))) === 'DESCARTADA') {
      throw new RuntimeException('Documento rejeitado arquivado por descarte local. Emissão, consulta alteradora e cancelamento bloqueados; confira o histórico fiscal.');
    }
    $model = in_array((string)($doc['model'] ?? '65'), ['55','65'], true) ? (string)$doc['model'] : '65';
    $multiCompanyFiscalCross = fiscal_multi_company_document_homologation_is_cross($pdo, $model);
    $cfg = $multiCompanyFiscalCross
      ? fiscal_multi_company_document_homologation_assert($pdo, $companyId, $model, 'consultar')
      : fiscal_company_config($pdo, $companyId);
    if (strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP')) !== 'NFEPHP') throw new RuntimeException('Consulta disponível nesta etapa para o motor NFePHP/sped-nfe.');
    if ($multiCompanyFiscalCross && !(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $companyId, (string)($doc['environment'] ?? '')))) {
      throw new RuntimeException(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a consulta da nota') : 'V11.46 Multiempresa: consulta de documento fora de HOMOLOGAÇÃO permanece bloqueada no F11.');
    }
    $docLabel = fiscal_doc_model_label($model);
    $accessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
    if (strlen($accessKey) !== 44) throw new RuntimeException('Chave de acesso inválida para consulta. Gere novamente a pré-' . $docLabel . '.');

    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, $model);
    $responseXml = $tools->sefazConsultaChave($accessKey);
    $summary = fiscal_nfce_response_summary($responseXml);
    $code = (string)$summary['code'];
    $message = (string)$summary['message'];
    $protocol = (string)$summary['protocol'];
    $currentXml = (string)($doc['xml_raw'] ?? '');
    $cancelEvent = is_array($summary['cancel_event'] ?? null) ? $summary['cancel_event'] : ['found'=>false];

    // A resposta pode manter protNFe=100 e trazer o cancelamento em procEventoNFe.
    if (!empty($cancelEvent['found'])) {
      $cancelCode = (string)($cancelEvent['code'] ?? '135');
      $cancelMessage = (string)($cancelEvent['message'] ?? ($docLabel . ' cancelada.'));
      $cancelProtocol = (string)($cancelEvent['protocol'] ?? '');
      fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CANCELADA', $cancelCode, $cancelMessage, $cancelProtocol !== '' ? $cancelProtocol : $protocol, null);
      $effects = fiscal_nfce_apply_internal_cancellation_effects($pdo, $companyId, $docId, $doc, 'Cancelamento fiscal confirmado pela consulta SEFAZ.');
      fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'CONSULTA_CHAVE_NFE' : 'CONSULTA_CHAVE_NFCE', $cancelCode, $cancelMessage, $cancelProtocol, '', $responseXml, 'Consulta pela chave localizou o evento 110111 e reconciliou o cancelamento.');
      return [
        'ok'=>true,
        'status'=>'CANCELADA',
        'code'=>$cancelCode,
        'message'=>$cancelMessage,
        'protocol'=>$cancelProtocol,
        'response'=>$responseXml,
        'cancel_event'=>$cancelEvent,
        'internal_sync'=>$effects['internal_sync'] ?? null,
        'internal_error'=>$effects['internal_error'] ?? '',
      ];
    }

    if (!empty($summary['cancelled'])) {
      fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CANCELADA', $code, $message ?: $docLabel . ' cancelada.', $protocol, null);
      $effects = fiscal_nfce_apply_internal_cancellation_effects($pdo, $companyId, $docId, $doc, 'Cancelamento fiscal confirmado pela consulta SEFAZ.');
      fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'CONSULTA_CHAVE_NFE' : 'CONSULTA_CHAVE_NFCE', $code, $message, $protocol, '', $responseXml, 'Consulta pela chave retornou cancelamento.');
      return [
        'ok'=>true,
        'status'=>'CANCELADA',
        'code'=>$code,
        'message'=>$message,
        'protocol'=>$protocol,
        'response'=>$responseXml,
        'internal_sync'=>$effects['internal_sync'] ?? null,
        'internal_error'=>$effects['internal_error'] ?? '',
      ];
    }

    if (!empty($summary['authorized'])) {
      $xmlToSave = $currentXml;
      try { if (class_exists('NFePHP\\NFe\\Complements') && trim($currentXml) !== '') $xmlToSave = \NFePHP\NFe\Complements::toAuthorize($currentXml, $responseXml); }
      catch (Throwable $ignored) {}
      fiscal_nfce_mark_result($pdo, $companyId, $docId, 'AUTORIZADA', $code, $message ?: 'Autorizado o uso da ' . $docLabel . '.', $protocol, $xmlToSave !== '' ? $xmlToSave : null);
      fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'CONSULTA_CHAVE_NFE' : 'CONSULTA_CHAVE_NFCE', $code, $message, $protocol, '', $responseXml, 'Consulta pela chave confirmou ' . $docLabel . ' autorizada e não retornou evento de cancelamento.');
      return ['ok'=>true, 'status'=>'AUTORIZADA', 'code'=>$code, 'message'=>$message, 'protocol'=>$protocol, 'response'=>$responseXml];
    }

    $status = fiscal_nfce_is_duplicate_response($code, $message) ? 'DUPLICIDADE' : 'CONSULTADA';
    $consultMessage = $message ?: 'Consulta realizada na SEFAZ.';
    if (in_array($code, ['217','656'], true) || stripos($consultMessage, 'inexistente') !== false) {
      $status = 'CONSULTADA';
      $protocol = '';
    }
    fiscal_nfce_mark_result($pdo, $companyId, $docId, $status, $code, $consultMessage, $protocol, null);
    fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'CONSULTA_CHAVE_NFE' : 'CONSULTA_CHAVE_NFCE', $code, $message, $protocol, '', $responseXml, 'Consulta de situação da ' . $docLabel . ' pela chave de acesso.');
    return ['ok'=>false, 'status'=>$status, 'code'=>$code, 'message'=>$message, 'protocol'=>$protocol, 'response'=>$responseXml];
  }
}

if (!function_exists('fiscal_nfce_recover_document_authorization')) {
  function fiscal_nfce_recover_document_authorization(PDO $pdo, int $companyId, int $docId): array {
    $result = fiscal_nfce_consult_document_status($pdo, $companyId, $docId);
    if (!empty($result['ok']) && ($result['status'] ?? '') === 'AUTORIZADA') {
      return $result + ['recovered'=>true];
    }
    throw new RuntimeException('A consulta foi realizada, mas a SEFAZ não retornou autorização para recuperar. Retorno: ' . (($result['code'] ?? '-') . ' - ' . ($result['message'] ?? '-')));
  }
}


if (!function_exists('fiscal_nfce_event_response_summary')) {
  function fiscal_nfce_event_response_summary(string $xml): array {
    $out = ['code'=>'', 'message'=>'', 'protocol'=>'', 'seq'=>'', 'raw'=>''];
    $out['raw'] = $xml;
    if (trim($xml) === '') return $out;
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    try {
      if (@$dom->loadXML($xml)) {
        $xp = new DOMXPath($dom);
        $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
        $get = function(array $paths) use ($xp): string {
          foreach ($paths as $path) {
            $n = $xp->query($path);
            if ($n && $n->length) {
              $v = trim((string)$n->item(0)->nodeValue);
              if ($v !== '') return $v;
            }
          }
          return '';
        };
        $out['code'] = $get(['//*[local-name()="retEvento"]/*[local-name()="infEvento"]/*[local-name()="cStat"]','//*[local-name()="infEvento"]/*[local-name()="cStat"]','//*[local-name()="cStat"]']);
        $out['message'] = $get(['//*[local-name()="retEvento"]/*[local-name()="infEvento"]/*[local-name()="xMotivo"]','//*[local-name()="infEvento"]/*[local-name()="xMotivo"]','//*[local-name()="xMotivo"]']);
        $out['protocol'] = $get(['//*[local-name()="retEvento"]/*[local-name()="infEvento"]/*[local-name()="nProt"]','//*[local-name()="infEvento"]/*[local-name()="nProt"]','//*[local-name()="nProt"]']);
        $out['seq'] = $get(['//*[local-name()="retEvento"]/*[local-name()="infEvento"]/*[local-name()="nSeqEvento"]','//*[local-name()="infEvento"]/*[local-name()="nSeqEvento"]']);
      }
    } finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    return $out;
  }
}

if (!function_exists('fiscal_nfce_send_cancel_event_seq_one')) {
  /**
   * Envia o evento oficial 110111 de cancelamento forçando nSeqEvento=1.
   * O cancelamento de NF-e/NFC-e admite somente a sequência 1.
   */
  function fiscal_nfce_send_cancel_event_seq_one(object $tools, string $uf, string $accessKey, string $reason, string $protocol): array {
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $uf), 0, 2));
    if (!preg_match('/^[A-Z]{2}$/', $uf)) throw new RuntimeException('UF inválida para o evento de cancelamento.');
    $accessKey = preg_replace('/\D+/', '', $accessKey);
    $protocol = preg_replace('/\D+/', '', $protocol);
    if (strlen($accessKey) !== 44) throw new RuntimeException('Chave de acesso inválida para o evento de cancelamento.');
    if ($protocol === '') throw new RuntimeException('Protocolo de autorização inválido para o evento de cancelamento.');

    $cleanReason = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', ' ', trim($reason));
    $cleanReason = preg_replace('/\s+/u', ' ', (string)$cleanReason);
    $escapeFlags = ENT_QUOTES | (defined('ENT_XML1') ? ENT_XML1 : ENT_COMPAT);
    $tagAdic = '<nProt>' . htmlspecialchars($protocol, $escapeFlags, 'UTF-8') . '</nProt>'
      . '<xJust>' . htmlspecialchars((string)$cleanReason, $escapeFlags, 'UTF-8') . '</xJust>';

    if (method_exists($tools, 'sefazCancela')) {
      // Método oficial da biblioteca; internamente utiliza tpEvento=110111 e nSeqEvento=1.
      $responseXml = $tools->sefazCancela($accessKey, (string)$cleanReason, $protocol);
    } elseif (method_exists($tools, 'sefazEvento')) {
      // Compatibilidade com implementações antigas que exponham somente o método genérico.
      $responseXml = $tools->sefazEvento($uf, $accessKey, 110111, 1, $tagAdic);
    } else {
      throw new RuntimeException('A versão do NFePHP instalada não possui método para evento de cancelamento.');
    }

    $requestXml = '';
    try { $requestXml = isset($tools->lastRequest) ? (string)$tools->lastRequest : ''; } catch (Throwable $ignored) {}
    $sentSequence = '';
    if ($requestXml !== '' && preg_match('/<nSeqEvento>\s*([0-9]+)\s*<\/nSeqEvento>/i', $requestXml, $m)) {
      $sentSequence = (string)((int)$m[1]);
    }
    return [
      'response' => (string)$responseXml,
      'request' => $requestXml,
      'sequence' => $sentSequence,
    ];
  }
}

if (!function_exists('fiscal_nfce_cancel_document')) {
  function fiscal_nfce_cancel_document(PDO $pdo, int $companyId, int $docId, string $reason): array {
    fiscal_nfce_ensure_schema($pdo);
    $doc = fiscal_nfce_document_by_id($pdo, $companyId, $docId);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado para cancelamento.');
    if (strtoupper(trim((string)($doc['status'] ?? ''))) === 'DESCARTADA') {
      throw new RuntimeException('Documento rejeitado arquivado por descarte local. Emissão, consulta alteradora e cancelamento bloqueados; confira o histórico fiscal.');
    }
    $model = in_array((string)($doc['model'] ?? ''), ['55','65'], true) ? (string)$doc['model'] : '65';
    $docLabel = fiscal_doc_model_label($model);
    if (function_exists('fiscal_multi_company_cancel_homologation_assert')) {
      fiscal_multi_company_cancel_homologation_assert($pdo, $companyId, $doc);
    }
    if (strtoupper((string)($doc['status'] ?? '')) !== 'AUTORIZADA') throw new RuntimeException('Somente ' . $docLabel . ' autorizada pode ser cancelada.');
    $accessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
    if (strlen($accessKey) !== 44) throw new RuntimeException('Chave de acesso inválida para cancelamento.');
    $protocol = trim((string)($doc['protocol_number'] ?? ''));
    if ($protocol === '') throw new RuntimeException('Protocolo de autorização não encontrado. Consulte/recupere o documento antes de cancelar.');
    $reason = trim($reason);
    if (function_exists('mb_strlen')) {
      if (mb_strlen($reason, 'UTF-8') < 15) throw new RuntimeException('Informe uma justificativa de cancelamento com pelo menos 15 caracteres.');
      $reason = mb_substr($reason, 0, 255, 'UTF-8');
    } else {
      if (strlen($reason) < 15) throw new RuntimeException('Informe uma justificativa de cancelamento com pelo menos 15 caracteres.');
      $reason = substr($reason, 0, 255);
    }
    $cfg = fiscal_company_config($pdo, $companyId);
    if (strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP')) !== 'NFEPHP') throw new RuntimeException('Cancelamento disponível nesta etapa para o motor NFePHP/sped-nfe.');
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, $model);
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? '')), 0, 2));
    $cancelExchange = fiscal_nfce_send_cancel_event_seq_one($tools, $uf, $accessKey, $reason, $protocol);
    $responseXml = (string)($cancelExchange['response'] ?? '');
    $requestXml = (string)($cancelExchange['request'] ?? '');
    $requestSequence = (string)($cancelExchange['sequence'] ?? '');
    $summary = fiscal_nfce_event_response_summary((string)$responseXml);
    $code = (string)$summary['code'];
    $msg = (string)$summary['message'];
    $eventProt = (string)$summary['protocol'];
    $ok = in_array($code, ['101','135','155'], true) || stripos($msg, 'cancelamento') !== false || stripos($msg, 'evento registrado') !== false;
    if (!$ok && $code === '594') {
      $seqText = $requestSequence !== '' ? $requestSequence : 'não identificado';
      $msg = trim($msg) . ' (nSeqEvento enviado pelo Nexa: ' . $seqText . ').';
    }
    $internalSync = null;
    $internalError = '';
    if ($ok) {
      fiscal_nfce_mark_result($pdo, $companyId, $docId, 'CANCELADA', $code, $msg ?: $docLabel . ' cancelada.', $eventProt !== '' ? $eventProt : $protocol, null);
      $effects = fiscal_nfce_apply_internal_cancellation_effects($pdo, $companyId, $docId, $doc, $reason);
      $internalSync = $effects['internal_sync'] ?? null;
      $internalError = (string)($effects['internal_error'] ?? '');
    } elseif ($code === '594' && $requestSequence === '1') {
      // A sequência 1 rejeitada pode indicar que o evento 110111 já existe.
      // Consulte a chave e procure procEventoNFe antes de permitir nova tentativa.
      try {
        $consult = fiscal_nfce_consult_document_status($pdo, $companyId, $docId);
        if (($consult['status'] ?? '') === 'CANCELADA') {
          $ok = true;
          $code = (string)($consult['code'] ?? '135');
          $msg = 'Cancelamento já registrado na SEFAZ e localizado pela consulta da chave. ' . (string)($consult['message'] ?? '');
          $eventProt = (string)($consult['protocol'] ?? '');
          $internalSync = $consult['internal_sync'] ?? null;
          $internalError = (string)($consult['internal_error'] ?? '');
        } else {
          $msg .= ' A consulta da chave ainda não retornou o evento 110111. Não repita o cancelamento até consultar novamente ou verificar a chave no portal da SEFAZ.';
        }
      } catch (Throwable $consultError) {
        $msg .= ' Não foi possível reconciliar pela consulta: ' . $consultError->getMessage();
      }
    }
    fiscal_nfce_append_event($pdo, $companyId, $docId, $model === '55' ? 'CANCELAMENTO_NFE' : 'CANCELAMENTO_NFCE', $code, $msg, $eventProt, $requestXml, (string)$responseXml, $reason);
    return [
      'ok'=>$ok,
      'status'=>$ok ? 'CANCELADA' : (string)($doc['status'] ?? ''),
      'code'=>$code,
      'message'=>$msg,
      'protocol'=>$eventProt,
      'response'=>(string)$responseXml,
      'request_sequence'=>$requestSequence,
      'internal_sync'=>$internalSync,
      'internal_error'=>$internalError,
    ];
  }
}

if (!function_exists('fiscal_nfe_cce_document')) {
  function fiscal_nfe_cce_document(PDO $pdo, int $companyId, int $docId, string $correction): array {
    fiscal_nfce_ensure_schema($pdo);
    $doc = fiscal_nfce_document_by_id($pdo, $companyId, $docId);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado para Carta de Correção.');
    if (function_exists('fiscal_multi_company_cce_homologation_assert')) {
      fiscal_multi_company_cce_homologation_assert($pdo, $companyId, $doc);
    }
    if ((string)($doc['model'] ?? '') !== '55') throw new RuntimeException('Carta de Correção eletrônica foi habilitada nesta etapa somente para NF-e modelo 55.');
    if (strtoupper((string)($doc['status'] ?? '')) !== 'AUTORIZADA') throw new RuntimeException('Somente NF-e autorizada pode receber Carta de Correção.');
    $accessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
    if (strlen($accessKey) !== 44) throw new RuntimeException('Chave de acesso inválida para Carta de Correção.');
    $correction = trim($correction);
    if (function_exists('mb_strlen')) {
      if (mb_strlen($correction, 'UTF-8') < 15) throw new RuntimeException('Informe a correção com pelo menos 15 caracteres.');
      $correction = mb_substr($correction, 0, 1000, 'UTF-8');
    } else {
      if (strlen($correction) < 15) throw new RuntimeException('Informe a correção com pelo menos 15 caracteres.');
      $correction = substr($correction, 0, 1000);
    }
    $seq = 1;
    try {
      $stSeq = $pdo->prepare("SELECT COALESCE(MAX(event_sequence),0)+1 FROM fiscal_document_events WHERE company_id=? AND fiscal_document_id=? AND event_type='CCE_NFE'");
      $stSeq->execute([$companyId, $docId]);
      $seq = max(1, (int)$stSeq->fetchColumn());
    } catch (Throwable $e) { $seq = 1; }
    $cfg = fiscal_company_config($pdo, $companyId);
    if (strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP')) !== 'NFEPHP') throw new RuntimeException('Carta de Correção disponível nesta etapa para o motor NFePHP/sped-nfe.');
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, '55');
    if (!method_exists($tools, 'sefazCCe')) throw new RuntimeException('A versão do NFePHP instalada não possui o método sefazCCe. Atualize as dependências do Composer.');
    $responseXml = $tools->sefazCCe($accessKey, $correction, $seq);
    $summary = fiscal_nfce_event_response_summary((string)$responseXml);
    $code = (string)$summary['code'];
    $msg = (string)$summary['message'];
    $prot = (string)$summary['protocol'];
    $ok = in_array($code, ['135','136'], true) || stripos($msg, 'evento registrado') !== false;
    $pdo->prepare('UPDATE fiscal_documents SET sefaz_status_code=?, sefaz_status_message=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$code, $msg ?: 'Carta de Correção processada.', $companyId, $docId]);
    fiscal_nfce_append_event($pdo, $companyId, $docId, 'CCE_NFE', $code, $msg, $prot, '', (string)$responseXml, $correction);
    return ['ok'=>$ok, 'status'=>'AUTORIZADA', 'code'=>$code, 'message'=>$msg, 'protocol'=>$prot, 'sequence'=>$seq, 'response'=>(string)$responseXml];
  }
}

if (!function_exists('fiscal_nfe_referenced_nfce_candidates')) {
  function fiscal_nfe_referenced_nfce_candidates(PDO $pdo, int $companyId, string $q = '', string $dateIni = '', string $dateFim = '', int $limit = 200): array {
    fiscal_nfce_ensure_schema($pdo);
    $where = ["fd.company_id=?", "fd.direction='SAIDA'", "fd.model='65'", "fd.status='AUTORIZADA'"];
    $params = [$companyId];
    $q = trim($q);
    if ($q !== '') {
      $like = nexa_search_like($q);
      $digits = preg_replace('/\D+/', '', $q);
      $where[] = "(fd.number=? OR fd.access_key LIKE ? OR fd.source_id=? OR p.name LIKE ? OR p.document LIKE ? OR p.phone LIKE ?)";
      array_push($params, (int)$digits, $like, (int)$digits, $like, $like, $like);
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIni)) { $where[] = 'fd.issue_datetime >= ?'; $params[] = $dateIni . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFim)) {
      $nextDay = (new DateTimeImmutable($dateFim))->modify('+1 day')->format('Y-m-d');
      $where[] = 'fd.issue_datetime < ?'; $params[] = $nextDay . ' 00:00:00';
    }
    $sql = "SELECT fd.*, p.person_id AS participant_person_id, p.name AS participant_name, p.document AS participant_document,
                   p.state_registration AS participant_ie, p.email AS participant_email, p.phone AS participant_phone,
                   p.zip_code AS participant_zip, p.address AS participant_address, p.number AS participant_number,
                   p.complement AS participant_complement, p.district AS participant_district, p.city AS participant_city,
                   p.city_ibge AS participant_city_ibge, p.state AS participant_state
            FROM fiscal_documents fd
            LEFT JOIN fiscal_document_participants p ON p.company_id=fd.company_id AND p.fiscal_document_id=fd.id AND p.role='DESTINATARIO'
            WHERE " . implode(' AND ', $where) . "
            ORDER BY fd.issue_datetime DESC, fd.id DESC LIMIT " . max(10, min(500, $limit));
    $st = $pdo->prepare($sql); $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_nfe_create_from_referenced_nfces')) {
  function fiscal_nfe_create_from_referenced_nfces(PDO $pdo, int $companyId, array $sourceDocIds, array $destinationOverride = []): array {
    fiscal_ensure_schema($pdo);
    fiscal_nfce_ensure_schema($pdo);
    fiscal_ensure_document_reference_schema($pdo);
    $sourceDocIds = array_values(array_unique(array_filter(array_map('intval', $sourceDocIds), static fn($v) => $v > 0)));
    if (!$sourceDocIds) throw new RuntimeException('Selecione ao menos uma NFC-e autorizada para referenciar.');
    $problems = fiscal_nfe_config_problems($pdo, $companyId);
    if ($problems) throw new RuntimeException(implode(' ', $problems));
    $cfg = fiscal_company_config($pdo, $companyId);
    $env = (string)($cfg['environment'] ?? 'HOMOLOGACAO');
    if ($env !== 'HOMOLOGACAO') throw new RuntimeException('Nesta etapa inicial a NF-e referenciada só é permitida em homologação.');
    $series = fiscal_nfce_active_series($pdo, $companyId, '55', $env);
    if (!$series) throw new RuntimeException('Cadastre uma série ativa para NF-e modelo 55 no ambiente atual.');

    $ph = implode(',', array_fill(0, count($sourceDocIds), '?'));
    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id IN ($ph) AND model='65' AND direction='SAIDA' AND status='AUTORIZADA' ORDER BY issue_datetime ASC, id ASC");
    $st->execute(array_merge([$companyId], $sourceDocIds));
    $sources = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($sources) !== count($sourceDocIds)) throw new RuntimeException('Uma ou mais NFC-e selecionadas não foram encontradas ou ainda não estão autorizadas.');
    $refKeys = [];
    foreach ($sources as $sd) {
      $key = preg_replace('/\D+/', '', (string)($sd['access_key'] ?? ''));
      if (strlen($key) !== 44) throw new RuntimeException('A NFC-e #' . (int)$sd['id'] . ' não possui chave de acesso válida.');
      $refKeys[] = $key;
    }
    $first = $sources[0];
    $dest = fiscal_nfce_document_participant($pdo, $companyId, (int)$first['id'], 'DESTINATARIO');
    $destinationOverride = is_array($destinationOverride) ? $destinationOverride : [];
    $overrideMap = [
      'person_id'=>'person_id', 'document'=>'document', 'state_registration'=>'state_registration', 'name'=>'name',
      'email'=>'email', 'phone'=>'phone', 'zip_code'=>'zip_code', 'address'=>'address', 'number'=>'number',
      'complement'=>'complement', 'district'=>'district', 'city'=>'city', 'city_ibge'=>'city_ibge', 'state'=>'state'
    ];
    foreach ($overrideMap as $inputKey => $destKey) {
      if (!array_key_exists($inputKey, $destinationOverride)) continue;
      $value = trim((string)$destinationOverride[$inputKey]);
      if ($value !== '') $dest[$destKey] = $value;
    }
    $dest['document'] = fiscal_digits($dest['document'] ?? '');
    $dest['zip_code'] = fiscal_digits($dest['zip_code'] ?? '');
    $dest['city_ibge'] = fiscal_digits($dest['city_ibge'] ?? '');
    $dest['state'] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($dest['state'] ?? '')), 0, 2));
    $destDoc = (string)$dest['document'];
    if (!in_array(strlen($destDoc), [11,14], true)) {
      throw new RuntimeException('Informe o CPF/CNPJ do destinatário na conferência antes de gerar a NF-e referenciada.');
    }
    if (trim((string)($dest['name'] ?? '')) === '') throw new RuntimeException('Informe o nome/razão social do destinatário.');
    $requiredDestination = [
      'zip_code'=>'CEP', 'address'=>'endereço', 'number'=>'número', 'district'=>'bairro',
      'city'=>'cidade', 'city_ibge'=>'código IBGE da cidade', 'state'=>'UF'
    ];
    foreach ($requiredDestination as $field => $label) {
      if (trim((string)($dest[$field] ?? '')) === '') throw new RuntimeException('Informe ' . $label . ' do destinatário antes de gerar a NF-e referenciada.');
    }
    if (strlen((string)$dest['zip_code']) !== 8) throw new RuntimeException('Informe um CEP válido do destinatário.');
    if (strlen((string)$dest['city_ibge']) !== 7) throw new RuntimeException('Informe o código IBGE completo da cidade do destinatário.');
    if (strlen((string)$dest['state']) !== 2) throw new RuntimeException('Informe a UF do destinatário.');
    foreach ($sources as $sd) {
      $d = fiscal_nfce_document_participant($pdo, $companyId, (int)$sd['id'], 'DESTINATARIO');
      $dDoc = fiscal_digits($d['document'] ?? '');
      if ($dDoc !== '' && $dDoc !== $destDoc) throw new RuntimeException('Selecione NFC-e do mesmo cliente/destinatário para emitir uma única NF-e referenciada.');
    }

    $totalProducts = 0.0; $totalDiscount = 0.0; $totalFreight = 0.0; $totalOther = 0.0; $totalDocument = 0.0;
    foreach ($sources as $sd) {
      $totalProducts += (float)($sd['total_products'] ?? 0);
      $totalDiscount += (float)($sd['total_discount'] ?? 0);
      $totalOther += (float)($sd['total_other'] ?? 0) + (float)($sd['total_freight'] ?? 0);
      $totalDocument += (float)($sd['total_document'] ?? 0);
    }
    $issue = date('Y-m-d H:i:s');
    $number = max(1, (int)($series['next_number'] ?? 1));
    $seriesValue = (string)$series['series'];
    $accessKey = fiscal_nfce_make_access_key($cfg, '55', $seriesValue, $number, $issue);
    $emitUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($cfg['uf'] ?? '')), 0, 2));
    $destUf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($dest['state'] ?? '')), 0, 2));
    $cfopRef = fiscal_nfe_after_nfce_cfop(['uf' => $destUf, 'state' => $destUf], $cfg);

    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE fiscal_document_series SET last_number=?, next_number=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$number, $number + 1, $companyId, (int)$series['id']]);
      $pdo->prepare("INSERT INTO fiscal_documents (company_id, direction, model, series, number, access_key, status, environment, operation_nature, issue_datetime, exit_entry_datetime, total_products, total_discount, total_freight, total_other, total_document, source_type, source_id, finance_generated, stock_generated, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())")
        ->execute([$companyId, 'SAIDA', '55', $seriesValue, $number, $accessKey, 'PRE_NFE', $env, 'NF-E REFERENTE A NFC-E', $issue, $issue, round($totalProducts,2), round($totalDiscount,2), $totalFreight, round($totalOther,2), round($totalDocument,2), 'NFCE_REFERENCIADA', (int)$first['id'], 0, 0, 'Pré-NF-e vinculada a NFC-e(s) autorizada(s): ' . implode(', ', array_map(static fn($r)=>(string)(int)$r['id'], $sources)) . '. Sem nova baixa de estoque e sem CFOP 5.929/6.929.']);
      $docId = (int)$pdo->lastInsertId();
      fiscal_save_document_references($pdo, $companyId, $docId, $refKeys);
      $part = $pdo->prepare('INSERT INTO fiscal_document_participants (fiscal_document_id, company_id, role, document, state_registration, name, email, phone, zip_code, address, number, complement, district, city, city_ibge, state, country_code, country_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $part->execute([$docId, $companyId, 'EMITENTE', fiscal_digits($cfg['company_cnpj'] ?? ''), (string)($cfg['state_registration'] ?? ''), fiscal_nfce_company_display_name($pdo, $companyId, $cfg), '', '', '', '', '', '', '', '', fiscal_digits($cfg['city_ibge'] ?? ''), (string)($cfg['uf'] ?? 'MG'), '1058', 'BRASIL']);
      $part->execute([$docId, $companyId, 'DESTINATARIO', $destDoc, (string)($dest['state_registration'] ?? ''), (string)($dest['name'] ?? 'CONSUMIDOR FINAL'), (string)($dest['email'] ?? ''), (string)($dest['phone'] ?? ''), (string)($dest['zip_code'] ?? ''), (string)($dest['address'] ?? ''), (string)($dest['number'] ?? ''), (string)($dest['complement'] ?? ''), (string)($dest['district'] ?? ''), (string)($dest['city'] ?? ''), (string)($dest['city_ibge'] ?? ''), (string)($dest['state'] ?? ''), '1058', 'BRASIL']);
      $insItem = $pdo->prepare('INSERT INTO fiscal_document_items (fiscal_document_id, company_id, item_number, product_id, product_code, ean, ean_trib, description, ncm, cest, cfop, unidade, quantity, unit_value, total_value, unidade_trib, quantity_trib, unit_value_trib, discount_value, freight_value, insurance_value, other_value, ind_total, order_item_id, notes, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $insTax = $pdo->prepare('INSERT INTO fiscal_document_item_taxes (fiscal_document_item_id, fiscal_document_id, company_id, tax_group, tax_tag, origin_code, cst, csosn, class_code, base_calc, base_calc_st, rate, reduction_rate, mva_rate, amount, extra_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
      $itemNo = 0;
      foreach ($sources as $sd) {
        $items = fiscal_nfce_document_items($pdo, $companyId, (int)$sd['id']);
        $taxMap = fiscal_nfce_document_taxes($pdo, $companyId, (int)$sd['id']);
        foreach ($items as $it) {
          $itemNo++;
          $insItem->execute([$docId, $companyId, $itemNo, $it['product_id'] ?? null, $it['product_code'] ?? '', $it['ean'] ?? '', $it['ean_trib'] ?? '', $it['description'] ?? 'Produto', $it['ncm'] ?? '', $it['cest'] ?? '', $cfopRef, $it['unidade'] ?? 'UN', $it['quantity'] ?? 0, $it['unit_value'] ?? 0, $it['total_value'] ?? 0, $it['unidade_trib'] ?? ($it['unidade'] ?? 'UN'), $it['quantity_trib'] ?? ($it['quantity'] ?? 0), $it['unit_value_trib'] ?? ($it['unit_value'] ?? 0), $it['discount_value'] ?? 0, 0, $it['insurance_value'] ?? 0, $it['other_value'] ?? 0, '1', null, 'Item originado da NFC-e ' . ($sd['series'] ?? '') . '/' . ($sd['number'] ?? '')]);
          $newItemId = (int)$pdo->lastInsertId();
          $hasIcms = false; $origin = '0'; $crt = preg_replace('/\D+/', '', (string)($cfg['crt'] ?? '1')) ?: '1';
          foreach (($taxMap[(int)$it['id']] ?? []) as $tx) {
            if ((string)($tx['tax_group'] ?? '') === 'ICMS') { $hasIcms = true; $origin = preg_replace('/\D+/', '', (string)($tx['origin_code'] ?? '0')) ?: '0'; break; }
          }
          $icmsTag = fiscal_nfce_icms_xml_tag($crt, '90', $crt === '1' ? '400' : '');
          $insTax->execute([$newItemId, $docId, $companyId, 'ICMS', $icmsTag, $origin, $crt === '1' ? '' : '90', $crt === '1' ? '400' : '', '', 0, 0, 0, 0, 0, 0, null]);
          $insTax->execute([$newItemId, $docId, $companyId, 'PIS', 'PISNT', '', '06', '', '', 0, 0, 0, 0, 0, 0, null]);
          $insTax->execute([$newItemId, $docId, $companyId, 'COFINS', 'COFINSNT', '', '06', '', '', 0, 0, 0, 0, 0, 0, null]);
        }
      }
      $pdo->prepare('INSERT INTO fiscal_document_payments (fiscal_document_id, company_id, payment_type, payment_description, payment_indicator, amount, created_at) VALUES (?,?,?,?,?,?,NOW())')
        ->execute([$docId, $companyId, '90', 'Sem pagamento', '0', 0]);
      $xml = fiscal_nfce_build_draft_xml($pdo, $companyId, $docId);
      $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$xml, hash('sha256', $xml), $companyId, $docId]);
      $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, status_code, status_message, request_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,NOW())')
        ->execute([$docId, $companyId, 'PRE_NFE_REFERENCIADA', 1, 'PRE', 'Pré-NF-e referenciada gerada.', $xml, 'NFC-e(s) referenciada(s): ' . implode(', ', $refKeys)]);
      if ($pdo->inTransaction()) $pdo->commit();
      $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId, $docId]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: ['id'=>$docId];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('fiscal_nfe_authorize_referenced_nfces')) {
  function fiscal_nfe_authorize_referenced_nfces(PDO $pdo, int $companyId, array $sourceDocIds): array {
    $doc = fiscal_nfe_create_from_referenced_nfces($pdo, $companyId, $sourceDocIds);
    $result = fiscal_nfce_authorize_document($pdo, $companyId, (int)$doc['id']);
    $result['doc_id'] = (int)$doc['id'];
    return $result;
  }
}


/* -------------------------------------------------------------------------
 * Status auxiliares: NFC-e referenciada e devolução total/parcial
 * ------------------------------------------------------------------------- */
if (!function_exists('fiscal_nfe_referenced_nfce_status_map')) {
  function fiscal_nfe_referenced_nfce_status_map(PDO $pdo, int $companyId, array $sourceDocIds): array {
    fiscal_nfce_ensure_schema($pdo);
    try { fiscal_ensure_document_reference_schema($pdo); } catch (Throwable $e) {}
    $sourceDocIds = array_values(array_unique(array_filter(array_map('intval', $sourceDocIds), static fn($v) => $v > 0)));
    if (!$sourceDocIds) return [];
    $ph = implode(',', array_fill(0, count($sourceDocIds), '?'));
    $params = array_merge([$companyId], $sourceDocIds);
    $sql = "SELECT src.id AS source_doc_id,
                   nfe.id AS nfe_doc_id, nfe.status AS nfe_status, nfe.series AS nfe_series,
                   nfe.number AS nfe_number, nfe.access_key AS nfe_access_key,
                   nfe.sefaz_status_code, nfe.sefaz_status_message,
                   nfe.created_at, nfe.issue_datetime
              FROM fiscal_documents src
              JOIN fiscal_document_references fr
                ON fr.company_id=src.company_id AND fr.reference_key=src.access_key
              JOIN fiscal_documents nfe
                ON nfe.company_id=fr.company_id AND nfe.id=fr.fiscal_document_id
             WHERE src.company_id=? AND src.id IN ($ph)
               AND src.model='65' AND src.direction='SAIDA'
               AND nfe.model='55' AND nfe.direction='SAIDA'
               AND UPPER(COALESCE(nfe.source_type,''))='NFCE_REFERENCIADA'
               AND UPPER(COALESCE(nfe.status,'')) <> 'CANCELADA'
          ORDER BY nfe.id DESC";
    $out = [];
    try {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $sid = (int)($row['source_doc_id'] ?? 0);
        if ($sid <= 0 || isset($out[$sid])) continue;
        $status = strtoupper((string)($row['nfe_status'] ?? ''));
        $label = 'Referenciada';
        $kind = 'ok';
        if ($status !== 'AUTORIZADA') {
          if (in_array($status, ['REJEITADA','ERRO_AUTORIZACAO','DUPLICIDADE'], true)) { $label = 'Ref. com erro'; $kind = 'warn'; }
          else { $label = 'Ref. em conferência'; $kind = 'wait'; }
        }
        $out[$sid] = [
          'status' => $status,
          'label' => $label,
          'kind' => $kind,
          'nfe_doc_id' => (int)($row['nfe_doc_id'] ?? 0),
          'nfe_series' => (string)($row['nfe_series'] ?? ''),
          'nfe_number' => (string)($row['nfe_number'] ?? ''),
          'message' => trim((string)($row['sefaz_status_code'] ?? '') . ' ' . (string)($row['sefaz_status_message'] ?? '')),
        ];
      }
    } catch (Throwable $e) {}

    // Compatibilidade: a primeira NFC-e referenciada também pode estar em source_id da NF-e posterior.
    $missing = array_values(array_filter($sourceDocIds, static fn($id) => !isset($out[$id])));
    if ($missing) {
      $ph2 = implode(',', array_fill(0, count($missing), '?'));
      $params2 = array_merge([$companyId], $missing);
      try {
        $st2 = $pdo->prepare("SELECT source_id AS source_doc_id, id AS nfe_doc_id, status AS nfe_status, series AS nfe_series, number AS nfe_number, access_key AS nfe_access_key, sefaz_status_code, sefaz_status_message, created_at, issue_datetime FROM fiscal_documents WHERE company_id=? AND source_id IN ($ph2) AND model='55' AND direction='SAIDA' AND UPPER(COALESCE(source_type,''))='NFCE_REFERENCIADA' AND UPPER(COALESCE(status,'')) <> 'CANCELADA' ORDER BY id DESC");
        $st2->execute($params2);
        foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
          $sid = (int)($row['source_doc_id'] ?? 0);
          if ($sid <= 0 || isset($out[$sid])) continue;
          $status = strtoupper((string)($row['nfe_status'] ?? ''));
          $label = $status === 'AUTORIZADA' ? 'Referenciada' : (in_array($status, ['REJEITADA','ERRO_AUTORIZACAO','DUPLICIDADE'], true) ? 'Ref. com erro' : 'Ref. em conferência');
          $kind = $status === 'AUTORIZADA' ? 'ok' : (in_array($status, ['REJEITADA','ERRO_AUTORIZACAO','DUPLICIDADE'], true) ? 'warn' : 'wait');
          $out[$sid] = [
            'status' => $status,
            'label' => $label,
            'kind' => $kind,
            'nfe_doc_id' => (int)($row['nfe_doc_id'] ?? 0),
            'nfe_series' => (string)($row['nfe_series'] ?? ''),
            'nfe_number' => (string)($row['nfe_number'] ?? ''),
            'message' => trim((string)($row['sefaz_status_code'] ?? '') . ' ' . (string)($row['sefaz_status_message'] ?? '')),
          ];
        }
      } catch (Throwable $e) {}
    }
    return $out;
  }
}

if (!function_exists('fiscal_nfe_referenced_nfce_order_map')) {
  function fiscal_nfe_referenced_nfce_order_map(PDO $pdo, int $companyId, array $orderIds): array {
    fiscal_nfce_ensure_schema($pdo);
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds) return [];
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $st = $pdo->prepare("SELECT id, source_id AS order_id FROM fiscal_documents WHERE company_id=? AND model='65' AND direction='SAIDA' AND source_type='PEDIDO' AND source_id IN ($ph) AND UPPER(COALESCE(status,''))='AUTORIZADA'");
    $st->execute(array_merge([$companyId], $orderIds));
    $docToOrder = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $docToOrder[(int)$r['id']] = (int)$r['order_id'];
    if (!$docToOrder) return [];
    $docMap = fiscal_nfe_referenced_nfce_status_map($pdo, $companyId, array_keys($docToOrder));
    $out = [];
    foreach ($docMap as $docId => $info) {
      $oid = $docToOrder[(int)$docId] ?? 0;
      if ($oid > 0 && !isset($out[$oid])) $out[$oid] = $info;
    }
    return $out;
  }
}

if (!function_exists('fiscal_devolution_status_for_source_documents')) {
  function fiscal_devolution_status_for_source_documents(PDO $pdo, int $companyId, array $sourceDocIds): array {
    fiscal_nfce_ensure_schema($pdo);
    try { fiscal_ensure_document_reference_schema($pdo); } catch (Throwable $e) {}
    $sourceDocIds = array_values(array_unique(array_filter(array_map('intval', $sourceDocIds), static fn($v) => $v > 0)));
    if (!$sourceDocIds) return [];
    $ph = implode(',', array_fill(0, count($sourceDocIds), '?'));
    $st = $pdo->prepare("SELECT id, access_key, total_document FROM fiscal_documents WHERE company_id=? AND id IN ($ph) AND model IN ('55','65') AND direction='SAIDA'");
    $st->execute(array_merge([$companyId], $sourceDocIds));
    $sources = [];
    $keyToDoc = [];
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
      $id = (int)$row['id'];
      $key = fiscal_digits($row['access_key'] ?? '');
      if ($id <= 0 || strlen($key) !== 44) continue;
      $sources[$id] = ['id'=>$id, 'key'=>$key, 'total'=>(float)($row['total_document'] ?? 0)];
      $keyToDoc[$key] = $id;
    }
    if (!$sources) return [];
    $keys = array_keys($keyToDoc);
    $phKeys = implode(',', array_fill(0, count($keys), '?'));
    $params = array_merge([$companyId], $keys);
    $sql = "SELECT fr.reference_key, rd.id AS return_doc_id, rd.status AS return_status, rd.total_document AS return_total, rd.issue_datetime
              FROM fiscal_document_references fr
              JOIN fiscal_documents rd ON rd.company_id=fr.company_id AND rd.id=fr.fiscal_document_id
             WHERE fr.company_id=? AND fr.reference_key IN ($phKeys)
               AND rd.direction='ENTRADA'
               AND UPPER(COALESCE(rd.status,'')) <> 'CANCELADA'
               AND (UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE' OR UPPER(COALESCE(rd.operation_nature,'')) LIKE '%DEVOL%')
          ORDER BY rd.id ASC";
    $returnDocs = [];
    try {
      $rs = $pdo->prepare($sql);
      $rs->execute($params);
      foreach (($rs->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $rid = (int)($row['return_doc_id'] ?? 0);
        $key = fiscal_digits($row['reference_key'] ?? '');
        if ($rid <= 0 || !isset($keyToDoc[$key])) continue;
        if (!isset($returnDocs[$rid])) {
          $returnDocs[$rid] = [
            'id' => $rid,
            'status' => strtoupper((string)($row['return_status'] ?? '')),
            'total' => (float)($row['return_total'] ?? 0),
            'keys' => [],
          ];
        }
        $returnDocs[$rid]['keys'][$key] = $key;
      }
    } catch (Throwable $e) { return []; }

    $acc = [];
    foreach ($returnDocs as $ret) {
      $refs = array_values($ret['keys']);
      if (!$refs) continue;
      $groupOriginal = 0.0;
      foreach ($refs as $key) {
        $sid = $keyToDoc[$key] ?? 0;
        if ($sid > 0) $groupOriginal += max(0.0, (float)($sources[$sid]['total'] ?? 0));
      }
      $shareFallback = count($refs) > 0 ? ((float)$ret['total'] / count($refs)) : 0.0;
      foreach ($refs as $key) {
        $sid = $keyToDoc[$key] ?? 0;
        if ($sid <= 0) continue;
        $orig = max(0.0, (float)($sources[$sid]['total'] ?? 0));
        $share = $groupOriginal > 0.0 ? ((float)$ret['total'] * ($orig / $groupOriginal)) : $shareFallback;
        if (!isset($acc[$sid])) $acc[$sid] = ['original_total'=>$orig, 'returned_total'=>0.0, 'documents_count'=>0, 'return_doc_ids'=>[], 'last_status'=>''];
        $acc[$sid]['returned_total'] += max(0.0, $share);
        $acc[$sid]['documents_count']++;
        $acc[$sid]['return_doc_ids'][] = (int)$ret['id'];
        $acc[$sid]['last_status'] = (string)$ret['status'];
      }
    }

    $out = [];
    foreach ($acc as $sid => $info) {
      $original = (float)($info['original_total'] ?? 0);
      $returned = (float)($info['returned_total'] ?? 0);
      $isTotal = $original > 0 ? ($returned + 0.01 >= $original) : ($returned > 0);
      $out[(int)$sid] = [
        'status' => $isTotal ? 'TOTAL' : 'PARCIAL',
        'label' => $isTotal ? 'Devolução total' : 'Devolução parcial',
        'kind' => $isTotal ? 'ok' : 'warn',
        'original_total' => $original,
        'returned_total' => $returned,
        'documents_count' => (int)($info['documents_count'] ?? 0),
        'return_doc_ids' => array_values(array_unique(array_map('intval', $info['return_doc_ids'] ?? []))),
        'last_status' => (string)($info['last_status'] ?? ''),
      ];
    }
    return $out;
  }
}

if (!function_exists('fiscal_order_devolution_status_map')) {
  function fiscal_order_devolution_status_map(PDO $pdo, int $companyId, array $orderIds): array {
    fiscal_nfce_ensure_schema($pdo);
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds) return [];
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    $docToOrder = [];
    try {
      $st = $pdo->prepare("SELECT id, source_id AS order_id FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND model IN ('55','65') AND source_type='PEDIDO' AND source_id IN ($ph) AND UPPER(COALESCE(status,''))='AUTORIZADA'");
      $st->execute(array_merge([$companyId], $orderIds));
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $docToOrder[(int)$r['id']] = (int)$r['order_id'];

      // Inclui NF-e posterior/referenciada, vinculada à NFC-e original do pedido.
      if ($docToOrder) {
        $srcDocIds = array_keys($docToOrder);
        $phDocs = implode(',', array_fill(0, count($srcDocIds), '?'));
        $st2 = $pdo->prepare("SELECT id, source_id FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND model='55' AND UPPER(COALESCE(source_type,''))='NFCE_REFERENCIADA' AND source_id IN ($phDocs) AND UPPER(COALESCE(status,''))='AUTORIZADA'");
        $st2->execute(array_merge([$companyId], $srcDocIds));
        foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
          $parentDoc = (int)($r['source_id'] ?? 0);
          $orderId = $docToOrder[$parentDoc] ?? 0;
          if ($orderId > 0) $docToOrder[(int)$r['id']] = $orderId;
        }
      }
    } catch (Throwable $e) { return []; }
    if (!$docToOrder) return [];
    $docStatus = fiscal_devolution_status_for_source_documents($pdo, $companyId, array_keys($docToOrder));
    $out = [];
    foreach ($docStatus as $docId => $info) {
      $oid = $docToOrder[(int)$docId] ?? 0;
      if ($oid <= 0) continue;
      if (!isset($out[$oid])) {
        $out[$oid] = $info;
      } else {
        $out[$oid]['returned_total'] = (float)($out[$oid]['returned_total'] ?? 0) + (float)($info['returned_total'] ?? 0);
        $out[$oid]['original_total'] = (float)($out[$oid]['original_total'] ?? 0) + (float)($info['original_total'] ?? 0);
        $out[$oid]['documents_count'] = (int)($out[$oid]['documents_count'] ?? 0) + (int)($info['documents_count'] ?? 0);
        if (($info['status'] ?? '') === 'PARCIAL') {
          $out[$oid]['status'] = 'PARCIAL';
          $out[$oid]['label'] = 'Devolução parcial';
          $out[$oid]['kind'] = 'warn';
        }
      }
    }
    return $out;
  }
}

if (!function_exists('fiscal_contingency_documents')) {
  function fiscal_contingency_documents(PDO $pdo, int $companyId, string $q = '', int $limit = 200): array {
    fiscal_nfce_ensure_schema($pdo);
    $statuses = ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA'];
    $ph = implode(',', array_fill(0, count($statuses), '?'));
    $where = ["company_id=?", "direction='SAIDA'", "model IN ('55','65')", "UPPER(status) IN ($ph)"];
    $params = array_merge([$companyId], $statuses);
    $q = trim($q);
    if ($q !== '') {
      $like = nexa_search_like($q); $digits = preg_replace('/\D+/', '', $q);
      $where[] = '(number=? OR access_key LIKE ? OR source_id=? OR operation_nature LIKE ?)';
      array_push($params, (int)$digits, $like, (int)$digits, $like);
    }
    $st = $pdo->prepare('SELECT * FROM fiscal_documents WHERE ' . implode(' AND ', $where) . ' ORDER BY issue_datetime ASC, id ASC LIMIT ' . max(10, min(500, $limit)));
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}


if (!function_exists('fiscal_batch_documents')) {
  function fiscal_batch_documents(PDO $pdo, int $companyId, string $filter = 'TODAS', string $q = '', int $limit = 250): array {
    fiscal_nfce_ensure_schema($pdo);
    $filter = strtoupper(trim($filter));
    if (!in_array($filter, ['TODAS','REJEITADAS','CONTINGENCIA'], true)) $filter = 'TODAS';
    $contingencyStatuses = ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA'];
    $where = ["company_id=?", "direction='SAIDA'", "model IN ('55','65')"];
    $params = [$companyId];
    if ($filter === 'REJEITADAS') {
      $where[] = "UPPER(COALESCE(status,''))='REJEITADA'";
    } elseif ($filter === 'CONTINGENCIA') {
      $ph = implode(',', array_fill(0, count($contingencyStatuses), '?'));
      $where[] = "UPPER(COALESCE(status,'')) IN ($ph)";
      array_push($params, ...$contingencyStatuses);
    } else {
      $ph = implode(',', array_fill(0, count($contingencyStatuses), '?'));
      $where[] = "(UPPER(COALESCE(status,''))='REJEITADA' OR UPPER(COALESCE(status,'')) IN ($ph))";
      array_push($params, ...$contingencyStatuses);
    }
    $q = trim($q);
    if ($q !== '') {
      $like = nexa_search_like($q);
      $digits = preg_replace('/\D+/', '', $q);
      $where[] = '(number=? OR access_key LIKE ? OR source_id=? OR operation_nature LIKE ? OR sefaz_status_code LIKE ? OR sefaz_status_message LIKE ?)';
      array_push($params, (int)$digits, $like, (int)$digits, $like, $like, $like);
    }
    $sql = 'SELECT * FROM fiscal_documents WHERE ' . implode(' AND ', $where)
      . ' ORDER BY CASE WHEN UPPER(COALESCE(status,\'\'))=\'REJEITADA\' THEN 0 ELSE 1 END, issue_datetime ASC, id ASC LIMIT '
      . max(10, min(500, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('fiscal_prepare_rejected_nfce_batch_resend')) {
  /**
   * NFC-e normal rejeitada precisa ser reenviada com dhEmi atual.
   * A SEFAZ valida a NFC-e on-line com tolerância curta; por isso uma rejeição
   * antiga (ex.: 742) não pode simplesmente reutilizar a data/hora original.
   * Esta preparação é exclusiva do reprocessamento em lote e do modelo 65.
   */
  function fiscal_prepare_rejected_nfce_batch_resend(PDO $pdo, int $companyId, array $doc): array {
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    $model = preg_replace('/\D+/', '', (string)($doc['model'] ?? ''));
    $emissionType = strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL'))) ?: 'NORMAL';
    if ($status !== 'REJEITADA' || $model !== '65' || !in_array($emissionType, ['NORMAL','1'], true)) return $doc;

    $cfg = fiscal_company_config($pdo, $companyId);
    $issue = date('Y-m-d H:i:s');
    $series = preg_replace('/\D+/', '', (string)($doc['series'] ?? '1')) ?: '1';
    $number = max(1, (int)($doc['number'] ?? 0));
    $oldKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
    $newKey = fiscal_nfce_make_access_key($cfg, '65', $series, $number, $issue, '1');

    $up = $pdo->prepare("UPDATE fiscal_documents SET operation_datetime=COALESCE(operation_datetime,issue_datetime,created_at), issue_datetime=?, exit_entry_datetime=?, access_key=?, emission_type='NORMAL', xml_raw=NULL, xml_hash=NULL, updated_at=NOW() WHERE company_id=? AND id=? AND status='REJEITADA'");
    $up->execute([$issue, $issue, $newKey, $companyId, (int)$doc['id']]);

    try {
      fiscal_nfce_append_event(
        $pdo, $companyId, (int)$doc['id'], 'PREPARACAO_REENVIO_LOTE_NFCE', 'INFO',
        'NFC-e rejeitada preparada para novo envio em lote com data/hora atual e nova chave de acesso.',
        '', '', '',
        'Chave anterior: ' . ($oldKey !== '' ? $oldKey : '-') . ' | Nova chave: ' . $newKey . ' | dhEmi: ' . $issue
      );
    } catch (Throwable $e) {}

    $st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1");
    $st->execute([$companyId, (int)$doc['id']]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: $doc;
  }
}

if (!function_exists('fiscal_authorize_batch_documents')) {
  function fiscal_authorize_batch_documents(PDO $pdo, int $companyId, array $docIds): array {
    fiscal_nfce_ensure_schema($pdo);
    $docIds = array_values(array_unique(array_filter(array_map('intval', $docIds), static fn($v) => $v > 0)));
    if (!$docIds) throw new RuntimeException('Selecione ao menos uma nota para processar em lote.');
    $allowedContingency = ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA'];
    $ok = 0; $fail = 0; $messages = [];
    foreach ($docIds as $docId) {
      try {
        $st = $pdo->prepare("SELECT id, model, number, series, status, sefaz_status_code, sefaz_status_message FROM fiscal_documents WHERE company_id=? AND id=? AND direction='SAIDA' AND model IN ('55','65') LIMIT 1");
        $st->execute([$companyId, $docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
        $status = strtoupper(trim((string)($doc['status'] ?? '')));
        if ($status !== 'REJEITADA' && !in_array($status, $allowedContingency, true)) {
          throw new RuntimeException('Situação não permitida para reprocessamento em lote: ' . ($status !== '' ? $status : '-'));
        }
        // NFC-e normal rejeitada é reemitida com dhEmi atual antes da nova tentativa.
        // Isso evita rejeição 704 ao reprocessar documentos rejeitados há mais de alguns minutos.
        if ($status === 'REJEITADA' && (string)($doc['model'] ?? '') === '65') {
          $fullSt = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1");
          $fullSt->execute([$companyId, $docId]);
          $fullDoc = $fullSt->fetch(PDO::FETCH_ASSOC) ?: $doc;
          $doc = fiscal_prepare_rejected_nfce_batch_resend($pdo, $companyId, $fullDoc);
        }
        $res = fiscal_nfce_authorize_document($pdo, $companyId, $docId);
        if (!empty($res['ok'])) $ok++; else $fail++;
        $label = ((string)($doc['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e') . ' ' . ($doc['series'] ?? '') . '/' . ($doc['number'] ?? '');
        $messages[] = $label . ': ' . (($res['code'] ?? '-') . ' - ' . ($res['message'] ?? 'processado'));
      } catch (Throwable $e) {
        $fail++;
        $messages[] = '#' . $docId . ': ' . $e->getMessage();
      }
    }
    return ['ok_count'=>$ok, 'fail_count'=>$fail, 'messages'=>$messages];
  }
}

if (!function_exists('fiscal_authorize_contingency_batch')) {
  function fiscal_authorize_contingency_batch(PDO $pdo, int $companyId, array $docIds): array {
    return fiscal_authorize_batch_documents($pdo, $companyId, $docIds);
  }
}

if (!function_exists('fiscal_nfce_authorize_order')) {
  function fiscal_nfce_authorize_order(PDO $pdo, int $companyId, int $orderId): array {
    $cfgEnabled = fiscal_multi_company_nfce_homologation_is_cross($pdo)
      ? fiscal_multi_company_nfce_homologation_assert($pdo, $companyId, 'emitir')
      : fiscal_company_config($pdo, $companyId);
    if (empty($cfgEnabled['fiscal_emission_enabled'])) throw new RuntimeException('A emissão de documentos fiscais está desabilitada nas configurações fiscais. O pedido será mantido sem emissão.');
    if (fiscal_multi_company_nfce_homologation_is_cross($pdo)) {
      $stSource = $pdo->prepare('SELECT source FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $stSource->execute([$companyId, $orderId]);
      if (strtolower(trim((string)($stSource->fetchColumn() ?: ''))) === 'ordem_servico_lote') {
        throw new RuntimeException('V11.35 Multiempresa: faturamento de O.S. continua usando somente a fila fiscal exclusiva da O.S.');
      }
    }
    if (fiscal_document_order_authorized($pdo, $companyId, $orderId, '55')) {
      throw new RuntimeException('Este pedido já possui NF-e autorizada. Não é permitido emitir NFC-e depois da NF-e.');
    }
    $existing = fiscal_document_order_document($pdo, $companyId, $orderId, '65');
    if ($existing) {
      $existingStatus = strtoupper((string)($existing['status'] ?? ''));
      if ($existingStatus === 'AUTORIZADA') throw new RuntimeException('Este pedido já possui NFC-e autorizada.');
      if (in_array($existingStatus, ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE'], true)) {
        $result = fiscal_nfce_authorize_document($pdo, $companyId, (int)$existing['id']);
        $result['doc_id'] = (int)$existing['id'];
        return $result;
      }
      if (in_array($existingStatus, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
        throw new RuntimeException('A NFC-e deste pedido possui retorno pendente ou inconclusivo. Use Consultar situação/Recuperar autorização antes de gerar ou transmitir novamente.');
      }
    }
    if (function_exists('nexa_progress')) nexa_progress('Preparando a NFC-e (impostos e numeração)...');
    $doc = fiscal_nfce_create_or_update_from_order($pdo, $companyId, $orderId, '65');
    $result = fiscal_nfce_authorize_document($pdo, $companyId, (int)$doc['id']);
    $result['doc_id'] = (int)$doc['id'];
    return $result;
  }
}

if (!function_exists('fiscal_nfe_authorize_order')) {
  function fiscal_nfe_authorize_order(PDO $pdo, int $companyId, int $orderId): array {
    $nfeCross = fiscal_multi_company_nfe_homologation_is_cross($pdo);
    $cfgEnabled = $nfeCross
      ? fiscal_multi_company_nfe_homologation_assert($pdo, $companyId, 'emitir')
      : fiscal_company_config($pdo, $companyId);
    if (empty($cfgEnabled['fiscal_emission_enabled'])) throw new RuntimeException('A emissão de documentos fiscais está desabilitada nas configurações fiscais. O pedido será mantido sem emissão.');
    if ($nfeCross) {
      $stSource = $pdo->prepare('SELECT source FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $stSource->execute([$companyId, $orderId]);
      if (strtolower(trim((string)($stSource->fetchColumn() ?: ''))) === 'ordem_servico_lote') {
        throw new RuntimeException('V11.46 Multiempresa: faturamento de O.S. continua usando somente a fila fiscal exclusiva da O.S.');
      }
    }
    $existing = fiscal_document_order_document($pdo, $companyId, $orderId, '55');
    if ($existing) {
      $existingStatus = strtoupper((string)($existing['status'] ?? ''));
      if ($existingStatus === 'AUTORIZADA') throw new RuntimeException('Este pedido já possui NF-e autorizada.');
      if (in_array($existingStatus, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
        throw new RuntimeException('A NF-e deste pedido possui retorno pendente ou inconclusivo. Use Consultar situação/Recuperar autorização antes de gerar ou transmitir novamente.');
      }
    }
    if (function_exists('nexa_progress')) nexa_progress('Preparando a NF-e (impostos e numeração)...');
    $doc = fiscal_nfce_create_or_update_from_order($pdo, $companyId, $orderId, '55');
    $result = fiscal_nfce_authorize_document($pdo, $companyId, (int)$doc['id']);
    $result['doc_id'] = (int)$doc['id'];
    return $result;
  }
}

if (!function_exists('fiscal_nfce_queue_authorization')) {
  function fiscal_nfce_queue_authorization(PDO $pdo, int $companyId, int $docId): int {
    fiscal_nfce_ensure_schema($pdo);
    $cfg = fiscal_company_config($pdo, $companyId);
    $adapter = strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP'));
    if ($adapter !== 'NFEPHP') {
      throw new RuntimeException('Esta etapa foi preparada para NFePHP/sped-nfe. Altere o motor para NFePHP ou implemente o conector ACBr.');
    }
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) {
      throw new RuntimeException('NFePHP/sped-nfe ainda não está instalado. Execute Composer no projeto antes de autorizar: composer require nfephp-org/sped-nfe nfephp-org/sped-da.');
    }
    $payload = json_encode(['adapter'=>'NFEPHP','step'=>'AUTHORIZE_NFCE','note'=>'Fila preparada. O worker de autorização será implementado no próximo passo.'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $pdo->prepare('INSERT INTO fiscal_emission_queue (company_id, fiscal_document_id, action, status, priority, request_payload, scheduled_at, created_at) VALUES (?,?,?, ?, 3, ?, NOW(), NOW())')
      ->execute([$companyId, $docId, 'AUTORIZAR_NFCE', 'PENDENTE', $payload]);
    return (int)$pdo->lastInsertId();
  }
}

/* -------------------------------------------------------------------------
 * Inutilização de numeração de NF-e / NFC-e
 * ------------------------------------------------------------------------- */
if (!function_exists('fiscal_inutilization_ensure_schema')) {
  function fiscal_inutilization_ensure_schema(PDO $pdo): void {
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
      if (!fiscal_table_exists($pdo, 'fiscal_inutilizations')) {
        throw new RuntimeException('A estrutura fiscal_inutilizations não está instalada. Aplique as migrations fiscais antes de usar a inutilização pelo F11.');
      }
      return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS fiscal_inutilizations (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      model VARCHAR(2) NOT NULL,
      series INT NOT NULL,
      start_number BIGINT NOT NULL,
      end_number BIGINT NOT NULL,
      fiscal_year CHAR(2) NOT NULL,
      environment VARCHAR(20) NOT NULL DEFAULT 'HOMOLOGACAO',
      justification VARCHAR(255) NOT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
      status_code VARCHAR(10) NULL,
      status_message VARCHAR(255) NULL,
      protocol_number VARCHAR(60) NULL,
      event_datetime DATETIME NULL,
      request_xml LONGTEXT NULL,
      response_xml LONGTEXT NULL,
      created_by BIGINT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (id),
      KEY idx_fiscal_inut_company_date (company_id, event_datetime, created_at),
      KEY idx_fiscal_inut_range (company_id, model, series, fiscal_year, start_number, end_number),
      KEY idx_fiscal_inut_status (company_id, status)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('fiscal_inutilization_response_summary')) {
  function fiscal_inutilization_response_summary(string $xml): array {
    $out = ['code'=>'', 'message'=>'', 'protocol'=>'', 'received_at'=>''];
    if (trim($xml) === '' || !class_exists('DOMDocument')) return $out;
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    try {
      if (!@$dom->loadXML($xml)) return $out;
      $xp = new DOMXPath($dom);
      $get = static function(array $paths) use ($xp): string {
        foreach ($paths as $path) {
          $nodes = $xp->query($path);
          if ($nodes && $nodes->length > 0) {
            $value = trim((string)$nodes->item(0)->nodeValue);
            if ($value !== '') return $value;
          }
        }
        return '';
      };
      $out['code'] = $get([
        '//*[local-name()="retInutNFe"]/*[local-name()="infInut"]/*[local-name()="cStat"]',
        '//*[local-name()="infInut"]/*[local-name()="cStat"]',
        '//*[local-name()="cStat"]'
      ]);
      $out['message'] = $get([
        '//*[local-name()="retInutNFe"]/*[local-name()="infInut"]/*[local-name()="xMotivo"]',
        '//*[local-name()="infInut"]/*[local-name()="xMotivo"]',
        '//*[local-name()="xMotivo"]'
      ]);
      $out['protocol'] = $get([
        '//*[local-name()="retInutNFe"]/*[local-name()="infInut"]/*[local-name()="nProt"]',
        '//*[local-name()="infInut"]/*[local-name()="nProt"]',
        '//*[local-name()="nProt"]'
      ]);
      $out['received_at'] = $get([
        '//*[local-name()="retInutNFe"]/*[local-name()="infInut"]/*[local-name()="dhRecbto"]',
        '//*[local-name()="infInut"]/*[local-name()="dhRecbto"]',
        '//*[local-name()="dhRecbto"]'
      ]);
    } finally {
      libxml_clear_errors();
      libxml_use_internal_errors($old);
    }
    return $out;
  }
}

if (!function_exists('fiscal_inutilize_number_range')) {
  function fiscal_inutilize_number_range(PDO $pdo, int $companyId, array $data, ?int $userId = null): array {
    fiscal_nfce_ensure_schema($pdo);

    $model = preg_replace('/\D+/', '', (string)($data['model'] ?? ''));
    if (!in_array($model, ['55','65'], true)) throw new RuntimeException('Selecione NF-e modelo 55 ou NFC-e modelo 65.');
    if (function_exists('fiscal_multi_company_inutilization_homologation_assert')) {
      fiscal_multi_company_inutilization_homologation_assert($pdo, $companyId, $model);
    }
    fiscal_inutilization_ensure_schema($pdo);
    $series = (int)($data['series'] ?? -1);
    if ($series < 0 || $series > 999) throw new RuntimeException('Informe uma série válida entre 0 e 999.');
    $start = (int)($data['start_number'] ?? 0);
    $end = (int)($data['end_number'] ?? $start);
    if ($start <= 0 || $end <= 0) throw new RuntimeException('Informe o número inicial e o número final da faixa.');
    if ($end < $start) throw new RuntimeException('O número final não pode ser menor que o número inicial.');
    if ($end > 999999999) throw new RuntimeException('O número final ultrapassa o limite permitido pela NF-e/NFC-e.');
    if (($end - $start) > 10000) throw new RuntimeException('Por segurança, inutilize no máximo 10.001 números por solicitação.');

    $year = preg_replace('/\D+/', '', (string)($data['fiscal_year'] ?? date('y')));
    if (strlen($year) === 4) $year = substr($year, -2);
    if (strlen($year) !== 2) throw new RuntimeException('Informe o ano fiscal com dois ou quatro dígitos.');

    $justification = trim((string)($data['justification'] ?? ''));
    $length = function_exists('mb_strlen') ? mb_strlen($justification, 'UTF-8') : strlen($justification);
    if ($length < 15) throw new RuntimeException('Informe uma justificativa com pelo menos 15 caracteres.');
    if (function_exists('mb_substr')) $justification = mb_substr($justification, 0, 255, 'UTF-8');
    else $justification = substr($justification, 0, 255);

    $dup = $pdo->prepare("SELECT * FROM fiscal_inutilizations WHERE company_id=? AND model=? AND series=? AND fiscal_year=? AND start_number<=? AND end_number>=? AND UPPER(status)='HOMOLOGADA' ORDER BY id DESC LIMIT 1");
    $dup->execute([$companyId, $model, $series, $year, $end, $start]);
    $existing = $dup->fetch(PDO::FETCH_ASSOC);
    if ($existing) {
      throw new RuntimeException('A faixa informada se sobrepõe a uma inutilização já homologada (' . (int)$existing['start_number'] . ' a ' . (int)$existing['end_number'] . '). Protocolo: ' . ((string)($existing['protocol_number'] ?? '') ?: '-'));
    }

    $used = $pdo->prepare("SELECT number,status FROM fiscal_documents WHERE company_id=? AND model=? AND CAST(COALESCE(series,'0') AS UNSIGNED)=? AND number BETWEEN ? AND ? AND UPPER(COALESCE(status,'')) IN ('AUTORIZADA','CANCELADA') ORDER BY number ASC LIMIT 12");
    $used->execute([$companyId, $model, $series, $start, $end]);
    $usedRows = $used->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($usedRows) {
      $numbers = implode(', ', array_map(static fn($r) => (string)($r['number'] ?? ''), $usedRows));
      throw new RuntimeException('A faixa contém número(s) já utilizado(s): ' . $numbers . '. Ajuste a faixa antes de enviar à SEFAZ.');
    }

    $cfg = fiscal_company_config($pdo, $companyId);
    if (strtoupper((string)($cfg['emission_adapter'] ?? 'NFEPHP')) !== 'NFEPHP') {
      throw new RuntimeException('A inutilização está disponível para o motor NFePHP/sped-nfe.');
    }
    $environment = strtoupper((string)($cfg['environment'] ?? 'HOMOLOGACAO')) === 'PRODUCAO' ? 'PRODUCAO' : 'HOMOLOGACAO';
    $seriesCheck = $pdo->prepare("SELECT id,is_active FROM fiscal_document_series WHERE company_id=? AND model=? AND CAST(series AS UNSIGNED)=? AND environment=? LIMIT 1");
    $seriesCheck->execute([$companyId, $model, $series, $environment]);
    $seriesRow = $seriesCheck->fetch(PDO::FETCH_ASSOC);
    if (!$seriesRow) throw new RuntimeException('A série informada não está cadastrada para o modelo e o ambiente fiscal atuais.');
    if ((int)($seriesRow['is_active'] ?? 0) !== 1) throw new RuntimeException('A série informada está inativa nas configurações fiscais.');
    $tpAmb = $environment === 'PRODUCAO' ? 1 : 2;
    $tools = fiscal_nfce_nfephp_tools($pdo, $companyId, $cfg, $model);
    if (!method_exists($tools, 'sefazInutiliza')) throw new RuntimeException('A versão instalada do NFePHP não possui o método sefazInutiliza.');

    $responseXml = '';
    $requestXml = '';
    $summary = ['code'=>'', 'message'=>'', 'protocol'=>'', 'received_at'=>''];
    $status = 'PENDENTE';
    $eventDate = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO fiscal_inutilizations (company_id,model,series,start_number,end_number,fiscal_year,environment,justification,status,event_datetime,created_by,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW(),NOW())")
      ->execute([$companyId,$model,$series,$start,$end,$year,$environment,$justification,$status,$eventDate,$userId]);
    $id = (int)$pdo->lastInsertId();

    try {
      $responseXml = (string)$tools->sefazInutiliza($series, $start, $end, $justification, $tpAmb, $year);
      $requestXml = property_exists($tools, 'lastRequest') ? (string)$tools->lastRequest : '';
      $summary = fiscal_inutilization_response_summary($responseXml);
      $messageNormalized = function_exists('mb_strtolower') ? mb_strtolower((string)$summary['message'], 'UTF-8') : strtolower((string)$summary['message']);
      $ok = ((string)$summary['code'] === '102') || (str_contains($messageNormalized, 'inutiliza') && str_contains($messageNormalized, 'homolog'));
      $status = $ok ? 'HOMOLOGADA' : 'REJEITADA';
    } catch (Throwable $e) {
      $requestXml = property_exists($tools, 'lastRequest') ? (string)$tools->lastRequest : $requestXml;
      $summary['message'] = $e->getMessage();
      $status = 'ERRO';
    }

    if (!empty($summary['received_at'])) {
      $ts = strtotime((string)$summary['received_at']);
      if ($ts) $eventDate = date('Y-m-d H:i:s', $ts);
    }
    $pdo->prepare("UPDATE fiscal_inutilizations SET status=?,status_code=?,status_message=?,protocol_number=?,event_datetime=?,request_xml=?,response_xml=?,updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$status,(string)$summary['code'],(string)$summary['message'],(string)$summary['protocol'],$eventDate,$requestXml,$responseXml,$companyId,$id]);

    $eventType = $model === '55' ? 'INUTILIZACAO_NFE' : 'INUTILIZACAO_NFCE';
    $notes = 'Série ' . $series . ', faixa ' . $start . ' a ' . $end . ', ano ' . $year . '. Justificativa: ' . $justification;
    try {
      $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id,company_id,event_type,event_sequence,event_datetime,protocol_number,status_code,status_message,request_xml,response_xml,notes,created_at) VALUES (NULL,?,?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$companyId,$eventType,1,$eventDate,(string)$summary['protocol'],(string)$summary['code'],(string)$summary['message'],$requestXml,$responseXml,$notes]);
    } catch (Throwable $e) {}

    if ($status === 'HOMOLOGADA') {
      // Mantem a NF-e descartada como historico; a inutilizacao homologada
      // fica registrada separadamente com protocolo em fiscal_inutilizations.
      $pdo->prepare("UPDATE fiscal_documents SET status='INUTILIZADA', sefaz_status_code=?, sefaz_status_message=?, protocol_number=?, updated_at=NOW() WHERE company_id=? AND model=? AND CAST(COALESCE(series,'0') AS UNSIGNED)=? AND number BETWEEN ? AND ? AND UPPER(COALESCE(status,'')) NOT IN ('AUTORIZADA','CANCELADA','DESCARTADA')")
        ->execute([(string)$summary['code'],(string)$summary['message'],(string)$summary['protocol'],$companyId,$model,$series,$start,$end]);
      $pdo->prepare("UPDATE fiscal_document_series SET next_number=GREATEST(next_number,?), last_number=GREATEST(last_number,?), updated_at=NOW() WHERE company_id=? AND model=? AND CAST(series AS UNSIGNED)=? AND environment=?")
        ->execute([$end + 1,$end,$companyId,$model,$series,$environment]);
    }

    if ($status !== 'HOMOLOGADA') {
      $message = trim((string)$summary['message']) ?: 'A SEFAZ não confirmou a inutilização.';
      throw new RuntimeException('Inutilização não homologada. Retorno ' . ((string)$summary['code'] ?: '-') . ': ' . $message);
    }

    return [
      'ok'=>true,
      'id'=>$id,
      'status'=>$status,
      'code'=>(string)$summary['code'],
      'message'=>(string)$summary['message'],
      'protocol'=>(string)$summary['protocol'],
      'model'=>$model,
      'series'=>$series,
      'start_number'=>$start,
      'end_number'=>$end,
      'fiscal_year'=>$year,
      'environment'=>$environment,
    ];
  }
}

if (!function_exists('fiscal_inutilization_rows')) {
  function fiscal_inutilization_rows(PDO $pdo, int $companyId, array $filters = [], int $limit = 500): array {
    fiscal_inutilization_ensure_schema($pdo);
    $where = ['company_id=?'];
    $params = [$companyId];
    $ini = trim((string)($filters['ini'] ?? ''));
    $fim = trim((string)($filters['fim'] ?? ''));
    $model = preg_replace('/\D+/', '', (string)($filters['model'] ?? ''));
    $series = trim((string)($filters['series'] ?? ''));
    $status = trim((string)($filters['status'] ?? ''));
    $q = trim((string)($filters['q'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $ini)) { $where[] = 'COALESCE(event_datetime,created_at)>=?'; $params[] = $ini . ' 00:00:00'; }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fim)) { $where[] = 'COALESCE(event_datetime,created_at)<?'; $params[] = date('Y-m-d 00:00:00', strtotime($fim . ' +1 day')); }
    if (in_array($model, ['55','65'], true)) { $where[] = 'model=?'; $params[] = $model; }
    if ($series !== '' && ctype_digit($series)) { $where[] = 'series=?'; $params[] = (int)$series; }
    if ($status !== '') { $where[] = 'UPPER(status)=?'; $params[] = strtoupper($status); }
    if ($q !== '') {
      $like = nexa_search_like($q);
      $where[] = '(justification LIKE ? OR status_message LIKE ? OR protocol_number LIKE ? OR CAST(start_number AS CHAR) LIKE ? OR CAST(end_number AS CHAR) LIKE ?)';
      array_push($params,$like,$like,$like,$like,$like);
    }
    $sql = 'SELECT * FROM fiscal_inutilizations WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(event_datetime,created_at) DESC,id DESC LIMIT ' . max(1,min(2000,$limit));
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

/* -------------------------------------------------------------------------
 * V11.43 R3 - Composição fiscal de Pedidos
 * ------------------------------------------------------------------------- */
if (!function_exists('fiscal_order_composition_map')) {
  function fiscal_order_composition_map(PDO $pdo, int $companyId, array $orderIds): array {
    $ids = [];
    foreach ($orderIds as $v) { $v = (int)$v; if ($v > 0) $ids[$v] = $v; }
    $ids = array_values($ids);
    if (!$ids) return [];
    $out = [];
    foreach ($ids as $oid) $out[$oid] = ['item_count'=>0,'service_count'=>0,'product_count'=>0];
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $hasService = fiscal_column_exists($pdo, 'produtos', 'is_service');
      $serviceExpr = $hasService ? 'COALESCE(p.is_service,0)' : '0';
      $sql = "SELECT pi.pedido_id,
                     COUNT(*) AS item_count,
                     SUM(CASE WHEN $serviceExpr=1 THEN 1 ELSE 0 END) AS service_count,
                     SUM(CASE WHEN $serviceExpr=1 THEN 0 ELSE 1 END) AS product_count
                FROM pedido_itens pi
                LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id
               WHERE pi.company_id=? AND pi.pedido_id IN ($ph)
               GROUP BY pi.pedido_id";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$companyId], $ids));
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['pedido_id'] ?? 0);
        if ($oid <= 0) continue;
        $out[$oid] = [
          'item_count'=>(int)($r['item_count'] ?? 0),
          'service_count'=>(int)($r['service_count'] ?? 0),
          'product_count'=>(int)($r['product_count'] ?? 0),
        ];
      }
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('fiscal_order_composition')) {
  function fiscal_order_composition(PDO $pdo, int $companyId, int $orderId): array {
    if ($orderId <= 0) return ['item_count'=>0,'service_count'=>0,'product_count'=>0];
    $map = fiscal_order_composition_map($pdo, $companyId, [$orderId]);
    return $map[$orderId] ?? ['item_count'=>0,'service_count'=>0,'product_count'=>0];
  }
}
