<?php
declare(strict_types=1);
// NEXA_PATCH_GTIN_CANCELAMENTO_V2_20260805
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_options.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/fiscal_module.php';
require_once __DIR__ . '/../config/stock_module.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/balanca_module.php';
require_once __DIR__ . '/../config/product_menu_code.php';
require_once __DIR__ . '/../config/product_composition.php';
require_once __DIR__ . '/../config/producao_module.php';
require_once __DIR__ . '/../config/product_half_pizza.php';

// V9.1: inclusão/edição básica, Fiscal/Tributação e Estoque básico permanecem
// homologados no contexto F11. O alvo é revalidado pelo backend em toda requisição.
// Acerto manual, saldo inicial e parâmetros básicos de estoque podem gravar na
// empresa contextual; duplicação, conferência, ficha técnica/produção, Entradas,
// PDV e demais movimentações automáticas continuam protegidos.
$productCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'products_write')
  : (int)COMPANY_ID;
$productCrossContext = $productCompanyId !== (int)COMPANY_ID;
$productMultiCompanyEnabled = function_exists('multi_company_feature_enabled')
  ? multi_company_feature_enabled($pdo, (int)COMPANY_ID)
  : false;
$productStockCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_read')
  : (int)COMPANY_ID;
$productStockContextReady = $productStockCompanyId === $productCompanyId;
$productStockWriteCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_write')
  : (int)COMPANY_ID;
$productStockWriteContextReady = $productStockWriteCompanyId === $productCompanyId;
$productFiscalCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'product_fiscal')
  : (int)COMPANY_ID;
$productFiscalContextReady = $productFiscalCompanyId === $productCompanyId;
$productCompanyRow = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $productCompanyId)
  : null;
$productCompanyName = trim((string)($productCompanyRow['name'] ?? '')) ?: ('Empresa #' . $productCompanyId);
// Composição de pedidos pertence a Comandas/Delivery, não ao cadastro geral.
// No F11 usa sempre a empresa operacional selecionada.
$productOrderOptionsEnabled = saas_company_has_module($pdo, (int)$productCompanyId, 'comandas')
  || saas_company_has_module($pdo, (int)$productCompanyId, 'delivery');
if ($productCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'produtos')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para alterar Produtos.');
}
$productCanManageFiscalOperations = !$productCrossContext;
if ($productCrossContext && function_exists('multi_company_context_permission_allowed')) {
  $productCanManageFiscalOperations = multi_company_context_permission_allowed($pdo, 'fiscal_operacoes');
}

$hasCategoryHalfPizza = product_half_pizza_ensure_schema($pdo, !$productCrossContext);

$balancaColumns = [];
company_sequence_ensure_schema($pdo, true);
try { $balancaColumns = balanca_ensure_schema($pdo); } catch (Throwable $e) { $balancaColumns = []; }

$id = (int)($_POST['produto_id'] ?? ($_GET['id'] ?? 0));
$erro = '';
$msg = '';
if (isset($_GET['saved'])) {
  $msg = 'Produto salvo com sucesso.';
}
if (isset($_GET['created'])) {
  $msg = 'Produto salvo com sucesso. Você já pode cadastrar o próximo produto.';
}
if (isset($_GET['duplicated'])) {
  $msg = 'Produto duplicado com sucesso. Confira os dados e ajuste o que for necessário.';
}
if (isset($_GET['stock_adjusted'])) {
  $msg = 'Estoque ajustado com sucesso e movimentação registrada.';
}
$hasCodigoFirebird = false;
$hasCodigoFirebirdMap = false;
$codigoFirebirdInfo = '';
$fiscalEnabled = false;
$fiscalProfiles = [];
$fiscalNcms = [];
$fiscalCests = [];
$fiscalCstCodes = [];
$fiscalIbsCbsClasses = [];
$fiscalNbs = [];
$fiscalServiceCodes = [];
$fiscalProductOperationCfops = [];
$fiscalOperations = [];
$fiscalTax = function_exists('fiscal_blank_product_tax') ? fiscal_blank_product_tax() : [];
$stockColumns = [];
$hasStockControl = false;
$stockWriteEnabled = false;
$stockReservedOrders = 0.0;
$stockAvailable = 0.0;
$gestaoEnabled = false;
$hasCostColumn = false;
$hideProductCost = false;
$hasProdutoPesavel = false;
$hasCodigoBarras = false;
$hasGtinTributavel = false;
$hasCodigoCardapio = false;
$hasServiceProductColumn = false;
$compositionEnabled = false;
$productionEnabled = false;
$technicalConfigEnabled = false;
$compositionRows = [];
$compositionRawMaterials = [];
$compositionStats = ['individual'=>0.0,'calculated'=>0.0,'last_recalc'=>null];

function mysql_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return $st && (bool)$st->fetch();
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}

function produto_form_ensure_order_columns(PDO $pdo): array {
  $cols = [
    'sort_order' => mysql_has_column($pdo, 'produtos', 'sort_order'),
    'category_sort_order' => mysql_has_column($pdo, 'produtos', 'category_sort_order'),
    'grupo_producao' => mysql_has_column($pdo, 'produtos', 'grupo_producao'),
    'grupo_impressao' => mysql_has_column($pdo, 'produtos', 'grupo_impressao'),
    'imprimir_cozinha' => mysql_has_column($pdo, 'produtos', 'imprimir_cozinha'),
    'category_hide_order_catalog' => mysql_has_column($pdo, 'produtos', 'category_hide_order_catalog'),
    'category_hide_product_catalog' => mysql_has_column($pdo, 'produtos', 'category_hide_product_catalog'),
    'hide_order_catalog_product' => mysql_has_column($pdo, 'produtos', 'hide_order_catalog_product'),
  ];

  if (!$pdo->inTransaction()) {
    if (!$cols['sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {}
      $cols['sort_order'] = mysql_has_column($pdo, 'produtos', 'sort_order');
    }
    if (!$cols['category_sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_sort_order INT NOT NULL DEFAULT 0 AFTER sort_order'); } catch (Throwable $e) {}
      $cols['category_sort_order'] = mysql_has_column($pdo, 'produtos', 'category_sort_order');
    }
    if (!$cols['grupo_producao']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN grupo_producao VARCHAR(120) NULL AFTER categoria'); } catch (Throwable $e) {}
      $cols['grupo_producao'] = mysql_has_column($pdo, 'produtos', 'grupo_producao');
    }
    if (!$cols['grupo_impressao']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN grupo_impressao VARCHAR(120) NULL AFTER grupo_producao'); } catch (Throwable $e) {}
      $cols['grupo_impressao'] = mysql_has_column($pdo, 'produtos', 'grupo_impressao');
    }
    if (!$cols['imprimir_cozinha']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN imprimir_cozinha TINYINT(1) NOT NULL DEFAULT 0 AFTER grupo_impressao'); } catch (Throwable $e) {}
      $cols['imprimir_cozinha'] = mysql_has_column($pdo, 'produtos', 'imprimir_cozinha');
    }
    if (!$cols['category_hide_order_catalog']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_order_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER category_sort_order'); } catch (Throwable $e) {}
      $cols['category_hide_order_catalog'] = mysql_has_column($pdo, 'produtos', 'category_hide_order_catalog');
    }
    if (!$cols['category_hide_product_catalog']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_hide_product_catalog TINYINT(1) NOT NULL DEFAULT 0 AFTER category_hide_order_catalog'); } catch (Throwable $e) {}
      $cols['category_hide_product_catalog'] = mysql_has_column($pdo, 'produtos', 'category_hide_product_catalog');
    }
  }

  return $cols;
}

function produto_form_int_order($value): int {
  $n = (int)preg_replace('/[^0-9-]+/', '', (string)$value);
  return max(0, min(999999, $n));
}

function produto_form_ensure_barcode_column(PDO $pdo): bool {
  if (mysql_has_column($pdo, 'produtos', 'codigo_barras')) return true;
  if ($pdo->inTransaction()) return false;
  try { $pdo->exec("ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL DEFAULT 'SEM GTIN' AFTER codigo_firebird"); } catch (Throwable $e) {
    try { $pdo->exec("ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL DEFAULT 'SEM GTIN'"); } catch (Throwable $e2) {}
  }
  return mysql_has_column($pdo, 'produtos', 'codigo_barras');
}

function produto_form_ensure_gtin_tributavel_column(PDO $pdo): bool {
  if (mysql_has_column($pdo, 'produtos', 'gtin_tributavel')) return true;
  if ($pdo->inTransaction()) return false;
  try { $pdo->exec("ALTER TABLE produtos ADD COLUMN gtin_tributavel VARCHAR(20) NULL DEFAULT 'SEM GTIN' AFTER codigo_barras"); } catch (Throwable $e) {
    try { $pdo->exec("ALTER TABLE produtos ADD COLUMN gtin_tributavel VARCHAR(20) NULL DEFAULT 'SEM GTIN'"); } catch (Throwable $e2) {}
  }
  return mysql_has_column($pdo, 'produtos', 'gtin_tributavel');
}

function produto_form_normalize_gtin_input($value): string {
  $raw = strtoupper(trim((string)$value));
  $compact = preg_replace('/[^A-Z0-9]+/', '', $raw) ?? '';
  if ($compact === '' || $compact === 'SEMGTIN') return $compact === 'SEMGTIN' ? 'SEM GTIN' : '';
  return preg_replace('/\D+/', '', $raw) ?? '';
}

function produto_form_ensure_service_column(PDO $pdo): bool {
  if (mysql_has_column($pdo, 'produtos', 'is_service')) return true;
  if ($pdo->inTransaction()) return false;
  try { $pdo->exec('ALTER TABLE produtos ADD COLUMN is_service TINYINT(1) NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {
    try { $pdo->exec('ALTER TABLE produtos ADD COLUMN is_service TINYINT(1) NOT NULL DEFAULT 0'); } catch (Throwable $e2) {}
  }
  return mysql_has_column($pdo, 'produtos', 'is_service');
}


function produto_form_parse_stock_adjustment($value): ?float {
  $raw = trim((string)$value);
  if ($raw === '') return null;
  $normalized = str_replace([' ', 'R$'], '', $raw);
  if (str_contains($normalized, ',') && str_contains($normalized, '.')) {
    $normalized = str_replace('.', '', $normalized);
  }
  $normalized = str_replace(',', '.', $normalized);
  if (!is_numeric($normalized)) return null;
  $qty = round((float)$normalized, 4);
  if (!is_finite($qty) || abs($qty) > 9999999999.9999) return null;
  return $qty;
}

function produto_form_stock_adjust(PDO $pdo, int $companyId, int $productId, float $newStock, string $observation = ''): array {
  if ($productId <= 0) throw new RuntimeException('Salve o produto antes de fazer o acerto de estoque.');
  stock_ensure_schema($pdo, true);
  if (product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo) && product_composition_product_is_composed($pdo, $companyId, $productId)) {
    throw new RuntimeException('O estoque deste produto é calculado pela composição. Ajuste o estoque das matérias-primas para alterar a disponibilidade do produto composto.');
  }

  $pdo->beginTransaction();
  try {
    $st = $pdo->prepare('SELECT id, nome, COALESCE(estoque_atual,0) AS estoque_atual FROM produtos WHERE company_id = ? AND id = ? LIMIT 1 FOR UPDATE');
    $st->execute([$companyId, $productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC);
    if (!$product) throw new RuntimeException('Produto não encontrado para o acerto de estoque.');

    $before = round((float)($product['estoque_atual'] ?? 0), 4);
    $after = round($newStock, 4);
    $difference = round($after - $before, 4);

    if (abs($difference) > 0.00005) {
      $upd = $pdo->prepare('UPDATE produtos SET estoque_atual = ? WHERE company_id = ? AND id = ?');
      $upd->execute([$after, $companyId, $productId]);

      $obs = trim($observation);
      if ($obs === '') $obs = 'Acerto manual realizado no cadastro de produtos.';
      $ins = $pdo->prepare("INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?, ?, 'ajuste', 'ACERTO_MANUAL', NULL, ?, ?, ?, ?)");
      $ins->execute([$companyId, $productId, $difference, $before, $after, $obs]);
    }

    $pdo->commit();
    if (product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
      try { product_composition_recalculate_all($pdo, $companyId, 'Acerto manual de matéria-prima'); } catch (Throwable $e) {}
    }
    return [
      'before' => $before,
      'after' => $after,
      'difference' => $difference,
      'formatted_before' => stock_qty_br($before),
      'formatted_after' => stock_qty_br($after),
      'formatted_difference' => stock_qty_br($difference),
    ];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function ensure_firebird_map_table(PDO $pdo): bool {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS produtos_firebird_map (
      id INT AUTO_INCREMENT PRIMARY KEY,
      company_id BIGINT NOT NULL,
      produto_id BIGINT NOT NULL,
      codigo_firebird VARCHAR(60) NULL,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY ux_produtos_firebird_map (company_id, produto_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    return true;
  } catch (Throwable $e) {
    return false;
  }
}

function produto_form_copy_options(PDO $pdo, int $companyId, int $sourceId, int $targetId): void {
  if ($sourceId <= 0 || $targetId <= 0) return;
  product_options_ensure_schema($pdo);
  $groups = product_options_load($pdo, $companyId, $sourceId, false);
  if (!$groups) return;

  $stGroup = $pdo->prepare("INSERT INTO produto_opcao_grupos (company_id, produto_id, titulo, tipo, obrigatorio, min_escolhas, max_escolhas, sort_order, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
  $stItem = $pdo->prepare("INSERT INTO produto_opcao_itens (company_id, grupo_id, nome, preco, sort_order, ativo) VALUES (?, ?, ?, ?, ?, ?)");

  foreach ($groups as $group) {
    $stGroup->execute([
      $companyId,
      $targetId,
      (string)($group['titulo'] ?? ''),
      (string)($group['tipo'] ?? 'single'),
      (int)($group['obrigatorio'] ?? 0),
      (int)($group['min_escolhas'] ?? 0),
      (int)($group['max_escolhas'] ?? 1),
      (int)($group['sort_order'] ?? 0),
      (int)($group['ativo'] ?? 1),
    ]);
    $newGroupId = (int)$pdo->lastInsertId();
    foreach ((array)($group['items'] ?? []) as $item) {
      $stItem->execute([
        $companyId,
        $newGroupId,
        (string)($item['nome'] ?? ''),
        (float)($item['preco'] ?? 0),
        (int)($item['sort_order'] ?? 0),
        (int)($item['ativo'] ?? 1),
      ]);
    }
  }
}

function produto_form_copy_fiscal(PDO $pdo, int $companyId, int $sourceId, int $targetId): void {
  if ($sourceId <= 0 || $targetId <= 0) return;
  if (!company_sequence_table_exists($pdo, 'fiscal_product_tax')) return;
  try {
    $st = $pdo->prepare('SELECT * FROM fiscal_product_tax WHERE company_id = ? AND product_id = ? LIMIT 1');
    $st->execute([$companyId, $sourceId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return;

    unset($row['id'], $row['created_at'], $row['updated_at']);
    $row['company_id'] = $companyId;
    $row['product_id'] = $targetId;
    $cols = array_keys($row);
    $ph = implode(',', array_fill(0, count($cols), '?'));
    $sql = 'INSERT INTO fiscal_product_tax (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')';
    $pdo->prepare($sql)->execute(array_values($row));
    if (function_exists('fiscal_ensure_operation_cfop_schema')) {
      fiscal_ensure_operation_cfop_schema($pdo);
      $stOps = $pdo->prepare('SELECT * FROM fiscal_product_operation_cfops WHERE company_id = ? AND product_id = ?');
      $stOps->execute([$companyId, $sourceId]);
      $insOps = $pdo->prepare('INSERT INTO fiscal_product_operation_cfops (company_id, product_id, tax_profile_id, operation_id, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, source_profile_id, inherited, notes) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE cfop_in_state=VALUES(cfop_in_state), cfop_in_interstate=VALUES(cfop_in_interstate), cfop_out_state=VALUES(cfop_out_state), cfop_out_interstate=VALUES(cfop_out_interstate), inherited=VALUES(inherited), notes=VALUES(notes), updated_at=NOW()');
      foreach (($stOps->fetchAll(PDO::FETCH_ASSOC) ?: []) as $opRow) {
        $insOps->execute([$companyId, $targetId, $opRow['tax_profile_id'] ?? null, $opRow['operation_id'] ?? 0, $opRow['cfop_in_state'] ?? null, $opRow['cfop_in_interstate'] ?? null, $opRow['cfop_out_state'] ?? null, $opRow['cfop_out_interstate'] ?? null, $opRow['source_profile_id'] ?? null, $opRow['inherited'] ?? 1, $opRow['notes'] ?? null]);
      }
    }
  } catch (Throwable $e) {}
}

function produto_form_duplicate_product(PDO $pdo, int $companyId, int $sourceId, bool $hasCodigoFirebird, bool $hasCodigoFirebirdMap): int {
  if ($sourceId <= 0) return 0;
  $st = $pdo->prepare('SELECT * FROM produtos WHERE id = ? AND company_id = ? LIMIT 1');
  $st->execute([$sourceId, $companyId]);
  $source = $st->fetch(PDO::FETCH_ASSOC);
  if (!$source) return 0;

  $descricao = trim((string)($source['descricao'] ?? ''));
  $descricao = trim($descricao . ' Duplicado');
  if ($descricao === '') $descricao = 'Duplicado';

  $cols = ['company_id'];
  $vals = [$companyId];
  if (company_sequence_has_column($pdo, 'produtos')) {
    $cols[] = 'numero_cadastro';
    $vals[] = company_sequence_next($pdo, $companyId, 'produtos');
  }

  $copy = [
    'nome' => (string)($source['nome'] ?? ''),
    'descricao' => $descricao,
    'categoria' => (string)($source['categoria'] ?? ''),
    'grupo_producao' => (string)($source['grupo_producao'] ?? ''),
    'grupo_impressao' => (string)($source['grupo_impressao'] ?? ''),
    'produto_pesavel' => (int)($source['produto_pesavel'] ?? 0),
    'preco' => (float)($source['preco'] ?? 0),
    'custo' => (float)($source['custo'] ?? 0),
    'unidade_estoque' => (string)($source['unidade_estoque'] ?? 'UN'),
    'estoque_atual' => 0,
    'estoque_minimo' => (float)($source['estoque_minimo'] ?? 0),
    'estoque_maximo' => (float)($source['estoque_maximo'] ?? 0),
    'estoque_reservado' => 0,
    'imagem' => (string)($source['imagem'] ?? ''),
    'ativo' => (int)($source['ativo'] ?? 1),
    'is_service' => (int)($source['is_service'] ?? 0),
    'sort_order' => (int)($source['sort_order'] ?? 0),
    'category_sort_order' => (int)($source['category_sort_order'] ?? 0),
    'category_hide_order_catalog' => (int)($source['category_hide_order_catalog'] ?? 0),
    'hide_order_catalog_product' => (int)($source['hide_order_catalog_product'] ?? 0),
    'category_hide_product_catalog' => (int)($source['category_hide_product_catalog'] ?? 0),
    'category_allow_half_pizza' => (int)($source['category_allow_half_pizza'] ?? 0),
  ];
  if ($hasCodigoFirebird) $copy['codigo_firebird'] = null;
  if (mysql_has_column($pdo, 'produtos', 'codigo_barras')) $copy['codigo_barras'] = (string)($source['codigo_barras'] ?? '');
  if (mysql_has_column($pdo, 'produtos', 'gtin_tributavel')) $copy['gtin_tributavel'] = (string)($source['gtin_tributavel'] ?? '');
  if (product_menu_code_column_exists($pdo)) $copy['codigo_cardapio'] = null;
  if (mysql_has_column($pdo, 'produtos', 'referencia')) $copy['referencia'] = null;

  foreach ($copy as $col => $val) {
    if (!mysql_has_column($pdo, 'produtos', $col)) continue;
    $cols[] = $col;
    $vals[] = $val;
  }

  $ph = implode(',', array_fill(0, count($cols), '?'));
  $sql = 'INSERT INTO produtos (`' . implode('`,`', $cols) . '`) VALUES (' . $ph . ')';
  $pdo->prepare($sql)->execute($vals);
  $newId = (int)$pdo->lastInsertId();
  if ($newId <= 0) return 0;

  if ($hasCodigoFirebirdMap) {
    $stMap = $pdo->prepare("INSERT INTO produtos_firebird_map (company_id, produto_id, codigo_firebird) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE codigo_firebird = VALUES(codigo_firebird)");
    $stMap->execute([$companyId, $newId, null]);
  }

  produto_form_copy_options($pdo, $companyId, $sourceId, $newId);
  produto_form_copy_fiscal($pdo, $companyId, $sourceId, $newId);

  return $newId;
}

product_options_ensure_schema($pdo);
try {
  $fiscalEnabled = $productFiscalContextReady && saas_company_has_module($pdo, (int)$productFiscalCompanyId, 'fiscal');
  if ($fiscalEnabled) {
    fiscal_ensure_schema($pdo);
    $fiscalProfiles = fiscal_tax_profiles($pdo, (int)$productFiscalCompanyId, true);
    $fiscalNcms = fiscal_search_ncms($pdo, (int)$productFiscalCompanyId, '', 250);
    $fiscalCests = fiscal_search_cests($pdo, (int)$productFiscalCompanyId, '', 250);
    $fiscalCstCodes = fiscal_cst_codes($pdo, (int)$productFiscalCompanyId, '', true);
    $fiscalIbsCbsClasses = fiscal_search_ibs_cbs_classifications($pdo, (int)$productFiscalCompanyId, '', 250);
    $fiscalNbs = fiscal_search_nbs_codes($pdo, (int)$productFiscalCompanyId, '', 250);
    $fiscalServiceCodes = fiscal_search_service_codes($pdo, (int)$productFiscalCompanyId, '', 250);
    $fiscalOperations = function_exists('fiscal_operations') ? fiscal_operations($pdo, (int)$productFiscalCompanyId, '', true) : [];
  }
} catch (Throwable $e) {
  $fiscalEnabled = false;
  $fiscalProfiles = [];
  $fiscalIbsCbsClasses = [];
  $fiscalNbs = [];
  $fiscalServiceCodes = [];
}
try {
  $gestaoEnabled = saas_company_has_module($pdo, (int)$productCompanyId, 'gestao');
  if ($gestaoEnabled && function_exists('fiscal_xml_ensure_product_cost_column')) {
    $hasCostColumn = fiscal_xml_ensure_product_cost_column($pdo);
  }
  $hideProductCost = $productCrossContext
    ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'desativa_preco_custo'))
    : (function_exists('access_product_cost_hidden') ? access_product_cost_hidden($pdo, (int)$productCompanyId) : false);
} catch (Throwable $e) {
  $gestaoEnabled = false;
  $hasCostColumn = false;
}
$hasCodigoFirebird = mysql_has_column($pdo, 'produtos', 'codigo_firebird');
$hasCodigoFirebirdMap = ensure_firebird_map_table($pdo);
$hasCodigoBarras = produto_form_ensure_barcode_column($pdo);
$hasGtinTributavel = produto_form_ensure_gtin_tributavel_column($pdo);
$hasCodigoCardapio = product_menu_code_ensure_schema($pdo, true);
$hasReferencia = mysql_has_column($pdo, 'produtos', 'referencia');
$hasServiceProductColumn = produto_form_ensure_service_column($pdo);
$orderColumns = produto_form_ensure_order_columns($pdo);
try {
  if ($productCrossContext) {
    $stockColumns = [
      'unidade_estoque' => stock_mysql_has_column($pdo, 'produtos', 'unidade_estoque'),
      'estoque_atual' => stock_mysql_has_column($pdo, 'produtos', 'estoque_atual'),
      'estoque_minimo' => stock_mysql_has_column($pdo, 'produtos', 'estoque_minimo'),
      'estoque_maximo' => stock_mysql_has_column($pdo, 'produtos', 'estoque_maximo'),
      'estoque_reservado' => stock_mysql_has_column($pdo, 'produtos', 'estoque_reservado'),
    ];
  } else {
    $stockColumns = stock_ensure_schema($pdo, true);
  }
} catch (Throwable $e) { $stockColumns = []; }
$hasStockControl = $productStockContextReady && !empty($stockColumns['estoque_atual']);
$stockWriteEnabled = $hasStockControl && (!$productCrossContext || $productStockWriteContextReady);
try {
  $compositionEnabled = product_composition_enabled($pdo, (int)$productCompanyId);
  if ($compositionEnabled) product_composition_ensure_schema($pdo);
} catch (Throwable $e) { $compositionEnabled = false; }
try {
  $productionEnabled = production_enabled($pdo, (int)$productCompanyId);
  if ($productionEnabled) production_ensure_schema($pdo);
} catch (Throwable $e) { $productionEnabled = false; }
$technicalConfigEnabled = $compositionEnabled || $productionEnabled;

// V9.1 - em contexto cruzado, Fiscal/Tributação e o estoque básico do cadastro
// de Produto estão homologados. Permanecem desligadas conferência, Entradas,
// ficha técnica/produção e outras rotinas que geram movimentações automáticas.
if ($productCrossContext) {
  $compositionEnabled = false;
  $productionEnabled = false;
  $technicalConfigEnabled = false;
}
$hasProductSortOrder = !empty($orderColumns['sort_order']);
$hasCategorySortOrder = !empty($orderColumns['category_sort_order']);
$hasCategoryHideOrderCatalog = !empty($orderColumns['category_hide_order_catalog']);
$hasCategoryHideProductCatalog = !empty($orderColumns['category_hide_product_catalog']);
$hasHideOrderCatalogProduct = !empty($orderColumns['hide_order_catalog_product']);
$hasCategoryVisibility = $hasCategoryHideOrderCatalog || $hasCategoryHideProductCatalog;
$hasProductionGroup = !empty($orderColumns['grupo_producao']);
$hasPrintGroup = !empty($orderColumns['grupo_impressao']);
$hasKitchenPrintFlag = !empty($orderColumns['imprimir_cozinha']);
$hasProdutoPesavel = !empty($balancaColumns['produto_pesavel']) || mysql_has_column($pdo, 'produtos', 'produto_pesavel');
if (!$hasCodigoFirebird) {
  $codigoFirebirdInfo = $hasCodigoFirebirdMap
    ? 'A coluna codigo_firebird não foi encontrada na tabela produtos. O código será salvo na tabela auxiliar produtos_firebird_map e usado na exportação.'
    : 'Não foi possível detectar a coluna codigo_firebird nem criar a tabela auxiliar produtos_firebird_map. Confira permissões do banco.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'stock_adjust') {
  $adjustProductId = (int)($_POST['produto_id'] ?? 0);
  $adjustQty = produto_form_parse_stock_adjustment($_POST['novo_estoque'] ?? '');
  $adjustObservation = trim((string)($_POST['stock_adjust_observation'] ?? ''));
  $isAjax = (string)($_POST['ajax'] ?? '') === '1';

  try {
    if (!$hasStockControl || !$stockWriteEnabled || (int)$productStockWriteCompanyId !== (int)$productCompanyId) {
      throw new RuntimeException('A gravação de estoque não está autorizada para a empresa selecionada.');
    }
    if ($adjustQty === null) throw new RuntimeException('Informe um estoque válido para realizar o acerto.');
    $result = produto_form_stock_adjust($pdo, (int)$productStockWriteCompanyId, $adjustProductId, $adjustQty, $adjustObservation);
    if ($isAjax) {
      header('Content-Type: application/json; charset=UTF-8');
      echo json_encode(['ok' => true, 'data' => $result], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('produto_form.php?id=' . $adjustProductId . '&stock_adjusted=1') : ('produto_form.php?id=' . $adjustProductId . '&stock_adjusted=1')));
    exit;
  } catch (Throwable $e) {
    if ($isAjax) {
      header('Content-Type: application/json; charset=UTF-8');
      http_response_code(422);
      echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $erro = $e->getMessage();
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'duplicate') {
  if ($productCrossContext) {
    http_response_code(409);
    $erro = 'A duplicação de produtos ainda não foi homologada para troca de empresa pelo F11.';
  } else {
    $sourceId = (int)($_POST['produto_id'] ?? 0);
    $newId = produto_form_duplicate_product($pdo, (int)$productCompanyId, $sourceId, $hasCodigoFirebird, $hasCodigoFirebirdMap);
    if ($newId > 0) {
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('produto_form.php?id=' . $newId . '&duplicated=1') : ('produto_form.php?id=' . $newId . '&duplicated=1')));
      exit;
    }
    $erro = 'Não foi possível duplicar o produto.';
  }
}

$produto = [
  'nome' => '',
  'descricao' => '',
  'categoria' => '',
  'grupo_producao' => '',
  'grupo_impressao' => '',
  'produto_pesavel' => 0,
  'imprimir_cozinha' => 0,
  'preco' => '0.00',
  'custo' => '0.0000',
  'unidade_estoque' => 'UN',
  'estoque_atual' => '0.0000',
  'estoque_minimo' => '0.0000',
  'estoque_maximo' => '0.0000',
  'estoque_reservado' => '0.0000',
  'ativo' => 1,
  'is_service' => 0,
  'is_materia_prima' => 0,
  'possui_composicao' => 0,
  'possui_producao' => 0,
  'composition_weight' => '1.0000',
  'estoque_composicao_individual' => '0.0000',
  'estoque_composicao_calculado' => '0.0000',
  'composition_last_recalc_at' => null,
  'sort_order' => 0,
  'category_sort_order' => 0,
  'category_hide_order_catalog' => 0,
  'hide_order_catalog_product' => 0,
  'category_hide_product_catalog' => 0,
  'category_allow_half_pizza' => 0,
  'imagem' => '',
  'codigo_firebird' => '',
  // Produto novo já inicia sem GTIN. O usuário substitui apenas quando houver código real.
  'codigo_barras' => $id > 0 ? '' : 'SEM GTIN',
  'gtin_tributavel' => $id > 0 ? '' : 'SEM GTIN',
  'codigo_cardapio' => '',
  'referencia' => '',
  'numero_cadastro' => '',
];

// Sugestões de categorias já cadastradas
$cats = [];
$catSettings = [];
try {
  $catOrderSql = $hasCategorySortOrder
    ? "ORDER BY CASE WHEN COALESCE(MIN(category_sort_order),0) <= 0 THEN 999999 ELSE MIN(category_sort_order) END ASC, categoria ASC"
    : "ORDER BY categoria ASC";
  $catSelect = 'categoria'
    . ($hasCategorySortOrder ? ', COALESCE(MIN(category_sort_order),0) AS category_sort_order' : ', 0 AS category_sort_order')
    . ($hasCategoryHideOrderCatalog ? ', COALESCE(MIN(category_hide_order_catalog),0) AS hide_order_catalog' : ', 0 AS hide_order_catalog')
    . ($hasCategoryHideProductCatalog ? ', COALESCE(MIN(category_hide_product_catalog),0) AS hide_product_catalog' : ', 0 AS hide_product_catalog')
    . ($hasCategoryHalfPizza ? ', COALESCE(MAX(category_allow_half_pizza),0) AS allow_half_pizza' : ', 0 AS allow_half_pizza');
  $q = $pdo->prepare("SELECT {$catSelect} FROM produtos WHERE company_id = ? AND categoria <> '' GROUP BY categoria {$catOrderSql}");
  $q->execute([$productCompanyId]);
  $catRows = $q ? ($q->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
  foreach ($catRows as $catRow) {
    $catName = (string)($catRow['categoria'] ?? '');
    if ($catName === '') continue;
    $cats[] = $catName;
    $catSettings[$catName] = [
      'sort_order' => $hasCategorySortOrder ? (int)($catRow['category_sort_order'] ?? 0) : 0,
      'hide_order_catalog' => (int)($catRow['hide_order_catalog'] ?? 0),
      'hide_product_catalog' => (int)($catRow['hide_product_catalog'] ?? 0),
      'allow_half_pizza' => (int)($catRow['allow_half_pizza'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  $cats = [];
  $catSettings = [];
}

$productionGroups = [];
$printGroups = [];
if ($hasProductionGroup) {
  try {
    $qProdGroups = $pdo->prepare("SELECT grupo_producao FROM produtos WHERE company_id = ? AND grupo_producao IS NOT NULL AND grupo_producao <> '' GROUP BY grupo_producao ORDER BY grupo_producao ASC");
    $qProdGroups->execute([$productCompanyId]);
    $productionGroups = $qProdGroups ? $qProdGroups->fetchAll(PDO::FETCH_COLUMN) : [];
  } catch (Throwable $e) {
    $productionGroups = [];
$printGroups = [];
  }
}

if ($id > 0) {
  $st = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND company_id = ? LIMIT 1");
  $st->execute([$id, $productCompanyId]);
  $row = $st->fetch();
  if ($productCrossContext && !$row) {
    http_response_code(404);
    access_render_denied('Produto não encontrado na empresa selecionada pelo F11.');
  }
  if ($row) $produto = array_merge($produto, $row);

  if ($hasCodigoFirebirdMap && trim((string)($produto['codigo_firebird'] ?? '')) === '') {
    try {
      $stMap = $pdo->prepare("SELECT codigo_firebird FROM produtos_firebird_map WHERE company_id = ? AND produto_id = ? LIMIT 1");
      $stMap->execute([$productCompanyId, $id]);
      $codigoMap = (string)($stMap->fetchColumn() ?: '');
      if ($codigoMap !== '') $produto['codigo_firebird'] = $codigoMap;
    } catch (Throwable $e) {
    }
  }
}


// V9 - resumo de estoque somente leitura. Não recalcula nem grava nada em contexto cruzado.
if ($hasStockControl && $id > 0) {
  try {
    if (function_exists('multi_company_table_exists')
        && multi_company_table_exists($pdo, 'pedidos')
        && multi_company_table_exists($pdo, 'pedido_itens')) {
      $openStatuses = stock_open_order_statuses();
      $ph = implode(',', array_fill(0, count($openStatuses), '?'));
      $paramsStock = [(int)$productStockCompanyId, (int)$id];
      foreach ($openStatuses as $status) $paramsStock[] = $status;
      $stReserved = $pdo->prepare("SELECT COALESCE(SUM(pi.qtd),0) FROM pedido_itens pi INNER JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id WHERE pi.company_id=? AND pi.produto_id=? AND pe.status IN ($ph)");
      $stReserved->execute($paramsStock);
      $stockReservedOrders = (float)($stReserved->fetchColumn() ?: 0);
    }
  } catch (Throwable $e) { $stockReservedOrders = 0.0; }

  $manualReserved = (float)($produto['estoque_reservado'] ?? 0);
  $isStoredComposite = !empty($produto['possui_composicao']) && array_key_exists('estoque_composicao_calculado', $produto);
  $stockBase = $isStoredComposite
    ? (float)($produto['estoque_composicao_calculado'] ?? 0)
    : (float)($produto['estoque_atual'] ?? 0);
  $stockAvailable = $isStoredComposite ? $stockBase : ($stockBase - $manualReserved - $stockReservedOrders);
}

if ($compositionEnabled) {
  try {
    if ($id > 0) product_composition_recalculate_all($pdo, (int)$productCompanyId, 'Abertura do cadastro de produto');
    if ($id > 0) {
      $stCompRefresh = $pdo->prepare('SELECT * FROM produtos WHERE id = ? AND company_id = ? LIMIT 1');
      $stCompRefresh->execute([$id, $productCompanyId]);
      $rowCompRefresh = $stCompRefresh->fetch(PDO::FETCH_ASSOC);
      if ($rowCompRefresh) $produto = array_merge($produto, $rowCompRefresh);
      $compositionRows = product_composition_load($pdo, (int)$productCompanyId, $id);
      $compositionStats = product_composition_stats($pdo, (int)$productCompanyId, $id);
    }
    $compositionRawMaterials = product_composition_raw_materials($pdo, (int)$productCompanyId, $id);
  } catch (Throwable $e) {
    $compositionRows = [];
    $compositionRawMaterials = [];
  }
}

if ($fiscalEnabled && $id > 0) {
  try { $fiscalTax = fiscal_product_tax($pdo, (int)$productFiscalCompanyId, $id); } catch (Throwable $e) { $fiscalTax = fiscal_blank_product_tax(); }
}
if ($fiscalEnabled && function_exists('fiscal_product_operation_cfops')) {
  try { $fiscalProductOperationCfops = fiscal_product_operation_cfops($pdo, (int)$productFiscalCompanyId, $id, (int)($fiscalTax['tax_profile_id'] ?? 0), true); } catch (Throwable $e) { $fiscalProductOperationCfops = []; }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save') {
  $nome = trim((string)($_POST['nome'] ?? ''));
  // Sem Comandas/Delivery, não apaga a descrição já gravada quando o campo está oculto.
  $descricao = $productOrderOptionsEnabled
    ? trim((string)($_POST['descricao'] ?? ''))
    : (string)($produto['descricao'] ?? '');
  $categoria = trim((string)($_POST['categoria'] ?? ''));
  $grupoProducao = trim((string)($_POST['grupo_producao'] ?? ''));
  $grupoImpressao = trim((string)($_POST['grupo_impressao'] ?? ''));
  $produtoPesavel = !empty($_POST['produto_pesavel']) ? 1 : 0;
  $imprimirCozinha = !empty($_POST['imprimir_cozinha']) ? 1 : 0;
  $preco = (string)($_POST['preco'] ?? '0');
  $custoProduto = ($gestaoEnabled && $hasCostColumn && !$hideProductCost)
    ? fiscal_xml_parse_decimal($_POST['custo'] ?? ($produto['custo'] ?? 0), 0.0)
    : (float)($produto['custo'] ?? 0);
  $ativo = isset($_POST['ativo']) ? 1 : 0;
  $isService = ($hasServiceProductColumn && !empty($_POST['is_service'])) ? 1 : 0;
  $isMateriaPrima = ($technicalConfigEnabled && !empty($_POST['is_materia_prima'])) ? 1 : 0;
  $possuiComposicao = ($compositionEnabled && !empty($_POST['possui_composicao'])) ? 1 : 0;
  $possuiProducao = ($productionEnabled && !empty($_POST['possui_producao'])) ? 1 : 0;
  $compositionWeight = $compositionEnabled ? max(0.0001, product_composition_decimal($_POST['composition_weight'] ?? ($produto['composition_weight'] ?? 1), 4)) : 1.0;
  $_POST['fiscal_item_type'] = $isService ? 'SERVICE' : 'PRODUCT';
  $sortOrder = produto_form_int_order($_POST['sort_order'] ?? 0);
  $categorySortOrder = produto_form_int_order($_POST['category_sort_order'] ?? 0);
  $hideCategoryOrderCatalog = ($hasCategoryHideOrderCatalog && !empty($_POST['category_hide_order_catalog'])) ? 1 : 0;
  $hideOrderCatalogProduct = ($hasHideOrderCatalogProduct && !empty($_POST['hide_order_catalog_product'])) ? 1 : 0;
  $hideCategoryProductCatalog = ($hasCategoryHideProductCatalog && !empty($_POST['category_hide_product_catalog'])) ? 1 : 0;
  $allowCategoryHalfPizza = ($productOrderOptionsEnabled && $hasCategoryHalfPizza && !empty($_POST['category_allow_half_pizza'])) ? 1 : 0;
  $codigoFirebird = trim((string)($_POST['codigo_firebird'] ?? ''));
  $codigoBarras = produto_form_normalize_gtin_input($_POST['codigo_barras'] ?? '');
  $gtinTributavel = produto_form_normalize_gtin_input($_POST['gtin_tributavel'] ?? '');
  // Em produto novo, campos deixados vazios assumem SEM GTIN automaticamente.
  if ($id <= 0) {
    if ($codigoBarras === '') $codigoBarras = 'SEM GTIN';
    if ($gtinTributavel === '') $gtinTributavel = 'SEM GTIN';
  }
  // Mantém o par coerente quando um dos campos informa explicitamente SEM GTIN.
  if ($codigoBarras === 'SEM GTIN' && $gtinTributavel === '') $gtinTributavel = 'SEM GTIN';
  if ($gtinTributavel === 'SEM GTIN' && $codigoBarras === '') $codigoBarras = 'SEM GTIN';
  $codigoCardapio = $hasCodigoCardapio ? product_menu_code_normalize($_POST['codigo_cardapio'] ?? '') : '';
  $referencia = $hasReferencia ? trim((string)($_POST['referencia'] ?? '')) : '';
  $unidadeEstoque = stock_unit_clean($_POST['unidade_estoque'] ?? ($produto['unidade_estoque'] ?? 'UN'));
  if ($unidadeEstoque === '') $unidadeEstoque = 'UN';
  $estoqueAtual = stock_parse_decimal($_POST['estoque_atual'] ?? ($produto['estoque_atual'] ?? 0));
  $estoqueMinimo = max(0, stock_parse_decimal($_POST['estoque_minimo'] ?? ($produto['estoque_minimo'] ?? 0)));
  $estoqueMaximo = max(0, stock_parse_decimal($_POST['estoque_maximo'] ?? ($produto['estoque_maximo'] ?? 0)));
  $estoqueReservado = max(0, stock_parse_decimal($_POST['estoque_reservado'] ?? ($produto['estoque_reservado'] ?? 0)));

  // aceita 39,90 / 39.90
  $preco = str_replace(['.', ','], ['', '.'], $preco);
  if (!is_numeric($preco)) $preco = '0';

  if ($nome === '') {
    $erro = 'Informe o nome.';
  } elseif ($technicalConfigEnabled && $isService && ($possuiComposicao || $possuiProducao)) {
    $erro = 'Serviços não podem utilizar ficha técnica de matérias-primas nesta versão.';
  } elseif ($possuiComposicao && $possuiProducao) {
    $erro = 'Escolha apenas uma modalidade: Possui composição ou Possui Produção.';
  } elseif ($isMateriaPrima && ($possuiComposicao || $possuiProducao)) {
    $erro = 'Um produto marcado como Matéria-prima não pode ser simultaneamente um produto acabado com ficha técnica.';
  } elseif ($productionEnabled && $id > 0 && !$isMateriaPrima && production_component_used_count($pdo, (int)$productCompanyId, $id) > 0) {
    $erro = 'Este produto está sendo usado como Matéria-prima em uma Ficha Técnica de Produção. Remova-o das fichas antes de desmarcar Matéria-prima.';
  } elseif ($compositionEnabled && ($compositionErrors = product_composition_validate_post($pdo, (int)$productCompanyId, $id, $isMateriaPrima === 1, $possuiComposicao === 1, $_POST))) {
    $erro = implode(' ', $compositionErrors);
  } elseif ($codigoCardapio !== '' && !product_menu_code_available($pdo, (int)$productCompanyId, $codigoCardapio, $id)) {
    $erro = 'O código do cardápio informado já está vinculado a outro produto desta empresa.';
  } elseif ($fiscalEnabled && function_exists('fiscal_validate_product_tax_post')) {
    $fiscalValidationErrors = fiscal_validate_product_tax_post($pdo, (int)$productFiscalCompanyId, $_POST);
    if ($fiscalValidationErrors) $erro = implode(' ', $fiscalValidationErrors);
  }
  if ($erro === '') {
    $imagemNome = (string)($produto['imagem'] ?? '');

    if (
      isset($_FILES['imagem']) &&
      is_array($_FILES['imagem']) &&
      (($_FILES['imagem']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE)
    ) {
      $tmp = $_FILES['imagem']['tmp_name'] ?? '';
      $err = (int)($_FILES['imagem']['error'] ?? 0);

      if ($err === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
        $ext = strtolower(pathinfo((string)$_FILES['imagem']['name'], PATHINFO_EXTENSION));
        $permitidas = ['jpg','jpeg','png','webp'];

        if (!in_array($ext, $permitidas, true)) {
          $erro = 'Formato inválido. Use JPG, PNG ou WEBP.';
        } else {
          $safeBase = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($nome));
          $safeBase = trim((string)$safeBase, '-');
          if ($safeBase === '') $safeBase = 'produto';

          $imagemNome = $safeBase . '-' . time() . '.' . $ext;
          $destDir = __DIR__ . '/../uploads/products/';
          if (!is_dir($destDir)) {
            mkdir($destDir, 0775, true);
          }

          $dest = $destDir . $imagemNome;
          if (!move_uploaded_file($tmp, $dest)) {
            $erro = 'Falha ao salvar a imagem.';
          }
        }
      } elseif ($err !== UPLOAD_ERR_NO_FILE) {
        $erro = 'Falha no upload da imagem.';
      }
    }

    if ($erro === '') {
      if ($id > 0) {
        $updateData = [
          'nome' => $nome,
          'descricao' => $descricao,
          'categoria' => $categoria,
          'preco' => (float)$preco,
          'imagem' => $imagemNome,
          'ativo' => $ativo,
        ];
        if ($gestaoEnabled && $hasCostColumn) $updateData['custo'] = $custoProduto;
        if ($hasProductionGroup) $updateData['grupo_producao'] = $grupoProducao;
        if ($hasPrintGroup) $updateData['grupo_impressao'] = $grupoImpressao;
        if ($hasKitchenPrintFlag) $updateData['imprimir_cozinha'] = $imprimirCozinha;
        if ($hasProdutoPesavel) $updateData['produto_pesavel'] = $produtoPesavel;
        if ($stockWriteEnabled) {
          $updateData['unidade_estoque'] = $unidadeEstoque;
          // Em contexto F11, saldo de produto existente só pode mudar pelo
          // acerto manual, que registra estoque_movimentos. Mantém o legado
          // intacto na empresa de entrada.
          if (!$productCrossContext) $updateData['estoque_atual'] = $estoqueAtual;
          $updateData['estoque_minimo'] = $estoqueMinimo;
          $updateData['estoque_maximo'] = $estoqueMaximo;
          $updateData['estoque_reservado'] = $estoqueReservado;
        }
        if ($hasProductSortOrder) $updateData['sort_order'] = $sortOrder;
        if ($hasCategorySortOrder) $updateData['category_sort_order'] = $categorySortOrder;
        if ($hasCategoryHideOrderCatalog) $updateData['category_hide_order_catalog'] = $hideCategoryOrderCatalog;
        if ($hasHideOrderCatalogProduct) $updateData['hide_order_catalog_product'] = $hideOrderCatalogProduct;
        if ($hasCategoryHideProductCatalog) $updateData['category_hide_product_catalog'] = $hideCategoryProductCatalog;
        if ($productOrderOptionsEnabled && $hasCategoryHalfPizza) $updateData['category_allow_half_pizza'] = $allowCategoryHalfPizza;
        if ($hasCodigoFirebird) $updateData['codigo_firebird'] = $codigoFirebird;
        if ($hasCodigoBarras) $updateData['codigo_barras'] = $codigoBarras !== '' ? $codigoBarras : null;
        if ($hasGtinTributavel) $updateData['gtin_tributavel'] = $gtinTributavel !== '' ? $gtinTributavel : null;
        if ($hasCodigoCardapio) $updateData['codigo_cardapio'] = $codigoCardapio !== '' ? $codigoCardapio : null;
        if ($hasReferencia) $updateData['referencia'] = $referencia !== '' ? mb_substr($referencia, 0, 180) : null;
        if ($hasServiceProductColumn) $updateData['is_service'] = $isService;
        if ($technicalConfigEnabled) $updateData['is_materia_prima'] = $isMateriaPrima;
        if ($compositionEnabled) {
          $updateData['possui_composicao'] = $possuiComposicao;
          $updateData['composition_weight'] = $compositionWeight;
        }
        if ($productionEnabled) $updateData['possui_producao'] = $possuiProducao;

        $set = [];
        $vals = [];
        foreach ($updateData as $col => $val) {
          $set[] = '`' . $col . '` = ?';
          $vals[] = $val;
        }
        $vals[] = $id;
        $vals[] = $productCompanyId;
        $st = $pdo->prepare('UPDATE produtos SET ' . implode(', ', $set) . ' WHERE id = ? AND company_id = ?');
        $st->execute($vals);
        $msg = 'Produto atualizado.';
      } else {
        $insertCols = ['company_id'];
        $insertVals = [$productCompanyId];
        if (company_sequence_has_column($pdo, 'produtos')) {
          $insertCols[] = 'numero_cadastro';
          $insertVals[] = company_sequence_next($pdo, (int)$productCompanyId, 'produtos');
        }
        $insertData = [
          'nome' => $nome,
          'descricao' => $descricao,
          'categoria' => $categoria,
          'preco' => (float)$preco,
          'imagem' => $imagemNome,
          'ativo' => $ativo,
        ];
        if ($gestaoEnabled && $hasCostColumn) $insertData['custo'] = $custoProduto;
        if ($hasProductionGroup) $insertData['grupo_producao'] = $grupoProducao;
        if ($hasPrintGroup) $insertData['grupo_impressao'] = $grupoImpressao;
        if ($hasKitchenPrintFlag) $insertData['imprimir_cozinha'] = $imprimirCozinha;
        if ($hasProdutoPesavel) $insertData['produto_pesavel'] = $produtoPesavel;
        if ($stockWriteEnabled) {
          $insertData['unidade_estoque'] = $unidadeEstoque;
          $insertData['estoque_atual'] = $estoqueAtual;
          $insertData['estoque_minimo'] = $estoqueMinimo;
          $insertData['estoque_maximo'] = $estoqueMaximo;
          $insertData['estoque_reservado'] = $estoqueReservado;
        }
        if ($hasProductSortOrder) $insertData['sort_order'] = $sortOrder;
        if ($hasCategorySortOrder) $insertData['category_sort_order'] = $categorySortOrder;
        if ($hasCategoryHideOrderCatalog) $insertData['category_hide_order_catalog'] = $hideCategoryOrderCatalog;
        if ($hasHideOrderCatalogProduct) $insertData['hide_order_catalog_product'] = $hideOrderCatalogProduct;
        if ($hasCategoryHideProductCatalog) $insertData['category_hide_product_catalog'] = $hideCategoryProductCatalog;
        if ($productOrderOptionsEnabled && $hasCategoryHalfPizza) $insertData['category_allow_half_pizza'] = $allowCategoryHalfPizza;
        foreach ($insertData as $col => $val) {
          $insertCols[] = $col;
          $insertVals[] = $val;
        }
        if ($hasCodigoFirebird) {
          $insertCols[] = 'codigo_firebird';
          $insertVals[] = $codigoFirebird;
        }
        if ($hasServiceProductColumn) {
          $insertCols[] = 'is_service';
          $insertVals[] = $isService;
        }
        if ($technicalConfigEnabled) {
          $insertCols[] = 'is_materia_prima';
          $insertVals[] = $isMateriaPrima;
        }
        if ($compositionEnabled) {
          $insertCols[] = 'possui_composicao';
          $insertVals[] = $possuiComposicao;
          $insertCols[] = 'composition_weight';
          $insertVals[] = $compositionWeight;
        }
        if ($productionEnabled) {
          $insertCols[] = 'possui_producao';
          $insertVals[] = $possuiProducao;
        }
        if ($hasCodigoBarras) {
          $insertCols[] = 'codigo_barras';
          $insertVals[] = $codigoBarras !== '' ? $codigoBarras : null;
        }
        if ($hasGtinTributavel) {
          $insertCols[] = 'gtin_tributavel';
          $insertVals[] = $gtinTributavel !== '' ? $gtinTributavel : null;
        }
        if ($hasCodigoCardapio) {
          $insertCols[] = 'codigo_cardapio';
          $insertVals[] = $codigoCardapio !== '' ? $codigoCardapio : null;
        }
        if ($hasReferencia) {
          $insertCols[] = 'referencia';
          $insertVals[] = $referencia !== '' ? mb_substr($referencia, 0, 180) : null;
        }
        $ph = implode(',', array_fill(0, count($insertCols), '?'));
        $st = $pdo->prepare('INSERT INTO produtos (`' . implode('`,`', $insertCols) . '`) VALUES (' . $ph . ')');
        $st->execute($insertVals);
        $id = (int)$pdo->lastInsertId();
        $msg = 'Produto criado.';
      }

      if ($hasCodigoFirebirdMap && $id > 0) {
        $stMap = $pdo->prepare("INSERT INTO produtos_firebird_map (company_id, produto_id, codigo_firebird) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE codigo_firebird = VALUES(codigo_firebird)");
        $stMap->execute([$productCompanyId, $id, $codigoFirebird !== '' ? $codigoFirebird : null]);
      }

      if ($categoria !== '' && ($hasCategorySortOrder || $hasCategoryHideOrderCatalog || $hasCategoryHideProductCatalog || $hasCategoryHalfPizza)) {
        try {
          $catSet = [];
          $catVals = [];
          if ($hasCategorySortOrder) {
            $catSet[] = 'category_sort_order = ?';
            $catVals[] = $categorySortOrder;
          }
          if ($hasCategoryHideOrderCatalog) {
            $catSet[] = 'category_hide_order_catalog = ?';
            $catVals[] = $hideCategoryOrderCatalog;
          }
          if ($hasCategoryHideProductCatalog) {
            $catSet[] = 'category_hide_product_catalog = ?';
            $catVals[] = $hideCategoryProductCatalog;
          }
          if ($productOrderOptionsEnabled && $hasCategoryHalfPizza) {
            $catSet[] = 'category_allow_half_pizza = ?';
            $catVals[] = $allowCategoryHalfPizza;
          }
          if ($catSet) {
            $catVals[] = $productCompanyId;
            $catVals[] = $categoria;
            $stCatCfg = $pdo->prepare('UPDATE produtos SET ' . implode(', ', $catSet) . ' WHERE company_id = ? AND categoria = ?');
            $stCatCfg->execute($catVals);
          }
        } catch (Throwable $e) {}
      }

      if ($id > 0) {
        if ($productOrderOptionsEnabled) product_options_save_from_post($pdo, $productCompanyId, $id, $_POST);
        if ($fiscalEnabled) {
          fiscal_save_product_tax_from_post($pdo, (int)$productFiscalCompanyId, $id, $_POST);
          if (function_exists('fiscal_save_product_operation_cfops_from_post')) {
            fiscal_save_product_operation_cfops_from_post($pdo, (int)$productFiscalCompanyId, $id, (int)($_POST['fiscal_tax_profile_id'] ?? 0), $_POST);
          }
        }
      }

      if ($compositionEnabled && $id > 0) {
        product_composition_save_from_post($pdo, (int)$productCompanyId, $id, $possuiComposicao === 1, $_POST);
        product_composition_recalculate_all($pdo, (int)$productCompanyId, 'Alteração da ficha técnica do produto #' . $id);
      }

      // Redireciona após salvar para evitar duplicação em refresh/reenvio de formulário.
      $saveAction = (string)($_POST['save_action'] ?? 'list');
      if ($saveAction === 'new') {
        // Opcao explicita para quem deseja continuar incluindo produtos.
        header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('produto_form.php?created=1') : 'produto_form.php?created=1'));
      } else {
        // Salvar (inclusao e edicao): retorna a listagem com a pesquisa pronta.
        // A funcao de contexto preserva a empresa selecionada via F11.
        header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('produtos.php?saved=1') : 'produtos.php?saved=1'));
      }
      exit;
    }
  }
}

$optionGroups = $id > 0 ? product_options_load($pdo, $productCompanyId, $id, false) : [];
$img = trim((string)($produto['imagem'] ?? ''));
$imgUrl = $img !== '' ? ('/uploads/products/' . rawurlencode($img)) : '';
?>

<?php admin_topbar('Central - Produto','produtos'); ?>
  <?php if ($productCrossContext): ?>
    <div class="card" style="margin-top:12px;border-color:#86efac;background:#f0fdf4;">
      <strong style="color:#166534;">V9.1 · Produto + Fiscal + Estoque — <?= htmlspecialchars($productCompanyName, ENT_QUOTES, 'UTF-8') ?></strong>
      <div class="muted" style="margin-top:5px;color:#166534;">Cadastro básico, tributação/fiscal e estoque básico serão gravados nesta empresa. Duplicação, conferência, Entradas, ficha técnica/produção, PDV e demais movimentações automáticas continuam protegidos.</div>
    </div>
  <?php endif; ?>
  <?php if ($erro): ?><div class="msg err" style="margin-top:12px;"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
  <?php if ($msg): ?>
    <div class="msg ok product-save-message" style="margin-top:12px;">
      <span><?= htmlspecialchars($msg) ?></span>
      <a class="btn btn-primary btn-sm" href="produto_form.php">+ Adicionar novo produto</a>
    </div>
  <?php endif; ?>

  <div class="card" style="margin-top:14px">
    <form method="post" enctype="multipart/form-data" autocomplete="off" data-validate>
    <input type="hidden" name="action" value="save">
    <input type="hidden" name="produto_id" value="<?= (int)$id ?>">
    <div class="product-tabbar">
      <a href="#dadosProduto">Dados gerais</a>
      <?php if ($productOrderOptionsEnabled): ?><a href="#adicionaisProduto">Adicionais</a><?php endif; ?>
      <?php if ($technicalConfigEnabled): ?><a href="#abaFichaTecnicaProduto">Ficha Técnica</a><?php endif; ?>
      <?php if ($fiscalEnabled): ?><a href="#abaFiscalProduto">Fiscal</a><?php endif; ?>
    </div>
    <div class="form-grid" id="dadosProduto">
      <div class="product-main-line">
        <div class="row product-number-field">
          <label>Código do produto</label>
          <input class="field" value="<?= (int)$id > 0 ? ('#' . htmlspecialchars((string)company_sequence_display($produto, 'produtos'))) : 'Novo' ?>" readonly>
        </div>
        <div class="row product-barcode-field">
          <label>Código de barras</label>
          <input class="field" name="codigo_barras" value="<?= htmlspecialchars((string)($produto['codigo_barras'] ?? '')) ?>" placeholder="GTIN ou SEM GTIN" inputmode="text" maxlength="80" autocomplete="off">
        </div>
        <div class="row product-name-field">
          <label>Nome do produto</label>
          <input class="field" name="nome" data-required value="<?= htmlspecialchars((string)$produto['nome']) ?>" placeholder="<?= $productOrderOptionsEnabled ? 'Ex.: Pizza 4 queijos' : 'Ex.: Produto ou serviço' ?>">
        </div>
        <div class="row product-unit-field">
          <label>Unidade</label>
          <input class="field" name="unidade_estoque" value="<?= htmlspecialchars((string)($produto['unidade_estoque'] ?? 'UN')) ?>" placeholder="UN, KG, CX" maxlength="20" <?= !$stockWriteEnabled ? 'readonly' : '' ?>>
        </div>
        <?php if ($hasProductSortOrder): ?>
        <div class="row product-order-field">
          <label>Ordem do produto</label>
          <input class="field" type="number" min="0" step="1" name="sort_order" value="<?= (int)($produto['sort_order'] ?? 0) ?>" placeholder="0">
        </div>
        <?php endif; ?>
      </div>

      <div class="product-secondary-line">
        <?php if ($hasServiceProductColumn): ?>
        <div class="row product-service-field">
          <label>Tipo</label>
          <label class="product-inline-check product-service-check" title="Ao marcar, o item será tratado como serviço na emissão fiscal.">
            <input type="checkbox" id="productIsService" name="is_service" value="1" <?= !empty($produto['is_service']) ? 'checked' : '' ?>>
            <span>Serviço</span>
          </label>
        </div>
        <?php endif; ?>

        <div class="row product-category-field">
          <label>Categoria</label>
          <div class="product-category-with-half">
            <div class="product-lookup-field">
              <input id="catInput" class="field" name="categoria" value="<?= htmlspecialchars((string)$produto['categoria']) ?>" placeholder="Digite ou selecione a categoria" autocomplete="off">
              <button class="btn btn-sm product-lookup-button" type="button" id="openCategoryLookup" data-lookup-type="category">Buscar F8</button>
            </div>
            <?php if ($productOrderOptionsEnabled && $hasCategoryHalfPizza): ?>
            <label class="product-inline-check product-half-pizza-check" title="Quando marcado, esta categoria permite montar pizza meio a meio usando somente produtos da mesma categoria.">
              <input type="checkbox" id="categoryAllowHalfPizza" name="category_allow_half_pizza" value="1" <?= !empty($produto['category_allow_half_pizza']) ? 'checked' : '' ?>>
              <span>Meio a Meio</span>
            </label>
            <?php endif; ?>
          </div>
        </div>

        <?php if ($hasCategorySortOrder): ?>
        <div class="row product-category-order-field">
          <label>Ordem da categoria</label>
          <input id="categorySortOrderInput" class="field" type="number" min="0" step="1" name="category_sort_order" value="<?= (int)($produto['category_sort_order'] ?? 0) ?>" placeholder="0">
        </div>
        <?php endif; ?>

        <div class="row product-production-field">
          <label>Grupo de produção</label>
          <?php if ($hasProductionGroup): ?>
          <div class="product-lookup-field">
            <input id="productionGroupInput" class="field" name="grupo_producao" value="<?= htmlspecialchars((string)($produto['grupo_producao'] ?? '')) ?>" placeholder="Digite ou selecione o grupo" autocomplete="off">
            <button class="btn btn-sm product-lookup-button" type="button" id="openProductionLookup" data-lookup-type="production">Buscar F8</button>
          </div>
          <?php else: ?>
            <input class="field" value="Campo indisponível" readonly>
          <?php endif; ?>
        </div>
      </div>

      <div class="product-flags-panel">
        <span class="product-section-label">Visibilidade da categoria e operação</span>
        <div class="product-flags-line">
          <?php if ($hasCategoryHideOrderCatalog): ?>
          <label class="product-flag" title="Oculta a categoria e seus produtos da tela onde o cliente faz o pedido.">
            <input type="checkbox" id="hideOrderCatalog" name="category_hide_order_catalog" value="1" <?= !empty($produto['category_hide_order_catalog']) ? 'checked' : '' ?>>
            <span>Ocultar categoria no catálogo de pedidos</span>
          </label>
          <?php endif; ?>
          <?php if ($hasHideOrderCatalogProduct): ?>
          <label class="product-flag" title="Oculta somente este produto do catálogo público de pedidos, mantendo os demais produtos da categoria e as vendas administrativas.">
            <input type="checkbox" id="hideOrderCatalogProduct" name="hide_order_catalog_product" value="1" <?= !empty($produto['hide_order_catalog_product']) ? 'checked' : '' ?>>
            <span>Ocultar Produto no Catálogo de Pedidos</span>
          </label>
          <?php endif; ?>
          <?php if ($hasCategoryHideProductCatalog): ?>
          <label class="product-flag" title="Oculta a categoria e seus produtos do catálogo somente visualização.">
            <input type="checkbox" id="hideProductCatalog" name="category_hide_product_catalog" value="1" <?= !empty($produto['category_hide_product_catalog']) ? 'checked' : '' ?>>
            <span>Ocultar categoria no catálogo de produtos</span>
          </label>
          <?php endif; ?>
          <?php if ($hasKitchenPrintFlag): ?>
          <label class="product-flag" title="Envia o produto para a impressão de cozinha/produção.">
            <input type="checkbox" name="imprimir_cozinha" value="1" <?= !empty($produto['imprimir_cozinha']) ? 'checked' : '' ?>>
            <span>Impressão na cozinha</span>
          </label>
          <?php endif; ?>
          <?php if ($hasProdutoPesavel): ?>
          <label class="product-flag" title="Usa a balança quando a integração estiver habilitada.">
            <input type="checkbox" name="produto_pesavel" value="1" <?= !empty($produto['produto_pesavel']) ? 'checked' : '' ?>>
            <span>Usar balança no PDV/Comanda</span>
          </label>
          <?php endif; ?>
          <label class="product-flag" title="Desmarque para ocultar o produto sem apagá-lo.">
            <input type="checkbox" name="ativo" <?= ((int)$produto['ativo'] === 1) ? 'checked' : '' ?>>
            <span>Ativo</span>
          </label>
        </div>
      </div>

      <?php if ($hasStockControl): ?>
      <div class="product-stock-panel">
        <div class="product-stock-head">
          <div>
            <strong>Estoque<?= $productCrossContext ? ' · gravação protegida V9.1' : '' ?></strong>
            <?php if ($productCrossContext): ?>
              <span>Saldo e parâmetros pertencem à empresa <?= htmlspecialchars($productCompanyName) ?>. Acertos manuais serão registrados no histórico desta empresa.</span>
            <?php elseif ($compositionEnabled && !empty($produto['possui_composicao'])): ?>
              <span>O produto composto usa o estoque calculado pela ficha técnica. O saldo físico abaixo fica separado para não duplicar o inventário das matérias-primas.</span>
            <?php elseif ((int)$id > 0): ?>
              <span>O estoque atual é alterado pelo botão de acerto para manter o histórico da movimentação.</span>
            <?php else: ?>
              <span>Informe o saldo inicial. Depois de salvar, os acertos ficam registrados no histórico.</span>
            <?php endif; ?>
          </div>
          <div class="actions">
            <?php if ($productMultiCompanyEnabled && (int)$id > 0): ?>
              <a class="btn btn-sm" href="<?= htmlspecialchars(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php?produto_id=' . (int)$id) : ('estoque_consulta.php?produto_id=' . (int)$id)) ?>">Ver movimentações</a>
            <?php endif; ?>
            <?php if ($stockWriteEnabled && (int)$id > 0 && empty($produto['possui_composicao'])): ?>
              <button class="btn btn-sm" type="button" id="openStockAdjustment">Acerto de estoque</button>
            <?php endif; ?>
          </div>
        </div>
        <?php if ($productCrossContext): ?>
          <div style="margin:10px 0 12px;padding:10px 12px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:10px;color:#166534;font-size:13px;">
            <strong>V9.1:</strong> saldo inicial, mínimo/máximo, reserva manual e acerto manual estão liberados para <?= htmlspecialchars($productCompanyName) ?>. Conferência, Entradas, produção/ficha técnica, PDV e baixas automáticas continuam bloqueados.
          </div>
        <?php endif; ?>
        <div class="product-stock-line">
          <div class="row">
            <label><?= ($compositionEnabled && !empty($produto['possui_composicao'])) ? 'Estoque físico' : 'Estoque atual' ?></label>
            <input id="currentStockField" class="field" name="estoque_atual" value="<?= htmlspecialchars(stock_qty_br($produto['estoque_atual'] ?? 0)) ?>" data-stock-raw="<?= htmlspecialchars((string)($produto['estoque_atual'] ?? 0)) ?>" inputmode="decimal" placeholder="0" <?= ((int)$id > 0 || !$stockWriteEnabled) ? 'readonly' : '' ?>>
          </div>
          <div class="row">
            <label>Estoque mínimo</label>
            <input class="field" name="estoque_minimo" value="<?= htmlspecialchars(stock_qty_br($produto['estoque_minimo'] ?? 0)) ?>" inputmode="decimal" placeholder="0" <?= !$stockWriteEnabled ? 'readonly' : '' ?>>
          </div>
          <div class="row">
            <label>Estoque máximo</label>
            <input class="field" name="estoque_maximo" value="<?= htmlspecialchars(stock_qty_br($produto['estoque_maximo'] ?? 0)) ?>" inputmode="decimal" placeholder="0" <?= !$stockWriteEnabled ? 'readonly' : '' ?>>
          </div>
          <div class="row">
            <label>Reserva manual</label>
            <input class="field" name="estoque_reservado" value="<?= htmlspecialchars(stock_qty_br($produto['estoque_reservado'] ?? 0)) ?>" inputmode="decimal" placeholder="0" <?= !$stockWriteEnabled ? 'readonly' : '' ?>>
          </div>
        </div>
        <?php if ($productMultiCompanyEnabled && (int)$id > 0): ?>
          <div style="display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;font-size:13px;">
            <span><strong>Reservado em pedidos:</strong> <?= htmlspecialchars(stock_qty_br($stockReservedOrders)) ?></span>
            <span><strong>Disponível:</strong> <?= htmlspecialchars(stock_qty_br($stockAvailable)) ?> <?= htmlspecialchars((string)($produto['unidade_estoque'] ?? 'UN')) ?></span>
          </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($technicalConfigEnabled):
        $compositionDisplayRows = $compositionEnabled ? $compositionRows : [];
        if ($compositionEnabled && !$compositionDisplayRows) $compositionDisplayRows = [['materia_prima_id'=>0,'quantidade'=>'']];
      ?>
      <div class="product-composition-panel" id="abaFichaTecnicaProduto">
        <div class="product-composition-head">
          <div>
            <strong>Configurações de ficha técnica</strong>
            <span>Defina se este cadastro é uma matéria-prima, um produto de composição automática ou um produto fabricado por Ordem de Produção.</span>
          </div>
          <?php if ($compositionEnabled && (int)$id > 0 && !empty($produto['possui_composicao'])): ?>
            <div class="product-composition-stock-summary">
              <small>Estoque calculado (rateado)</small>
              <b><?= htmlspecialchars(stock_qty_br($compositionStats['calculated'] ?? 0)) ?> <?= htmlspecialchars((string)($produto['unidade_estoque'] ?? 'UN')) ?></b>
              <small>Potencial individual: <?= htmlspecialchars(stock_qty_br($compositionStats['individual'] ?? 0)) ?></small>
            </div>
          <?php endif; ?>
        </div>

        <div class="product-composition-flags">
          <label class="product-flag" title="Marque os insumos que poderão ser usados nas fichas técnicas.">
            <input type="checkbox" id="productIsRawMaterial" name="is_materia_prima" value="1" <?= !empty($produto['is_materia_prima']) ? 'checked' : '' ?>>
            <span>Matéria-prima</span>
          </label>
          <?php if ($compositionEnabled): ?>
          <label class="product-flag" title="O estoque operacional deste produto será calculado pelas matérias-primas disponíveis e consumido automaticamente nas vendas.">
            <input type="checkbox" id="productHasComposition" name="possui_composicao" value="1" <?= !empty($produto['possui_composicao']) ? 'checked' : '' ?>>
            <span>Possui composição</span>
          </label>
          <?php endif; ?>
          <?php if ($productionEnabled): ?>
          <label class="product-flag" title="Este produto será fabricado através do módulo Ordem de Produção e terá estoque físico de produto acabado.">
            <input type="checkbox" id="productHasProduction" name="possui_producao" value="1" <?= !empty($produto['possui_producao']) ? 'checked' : '' ?>>
            <span>Possui Produção</span>
          </label>
          <?php endif; ?>
        </div>

        <?php if ($productionEnabled): ?>
        <div id="productProductionHelp" class="product-composition-explanation" <?= empty($produto['possui_producao']) ? 'hidden' : '' ?>>
          <b>Produção física:</b> depois de salvar o produto, cadastre os insumos em <b>Produção → Ficha Técnica</b>. A entrada do produto acabado será feita por Ordem de Produção.
        </div>
        <?php endif; ?>

        <?php if ($compositionEnabled): ?>
        <div id="productCompositionBuilder" <?= empty($produto['possui_composicao']) ? 'hidden' : '' ?>>
          <div class="product-composition-explanation">
            <b>Como o estoque funciona:</b> o saldo das matérias-primas é físico. O produto composto recebe um estoque operacional calculado. Quando uma matéria-prima é compartilhada por vários produtos, o Nexa rateia a capacidade entre eles pelo <b>peso de rateio</b>.
          </div>
          <div class="product-composition-settings">
            <div class="row">
              <label>Peso de rateio</label>
              <input class="field" name="composition_weight" value="<?= htmlspecialchars(product_composition_qty_label($produto['composition_weight'] ?? 1, 4)) ?>" inputmode="decimal" placeholder="1">
              <div class="help">1 = divisão padrão. Ex.: peso 2 reserva proporcionalmente o dobro da capacidade em relação a um produto de peso 1.</div>
            </div>
          </div>

          <div class="product-composition-table-wrap">
            <table class="product-composition-table">
              <thead><tr><th>Matéria-prima</th><th>Quantidade para 1 <?= htmlspecialchars((string)($produto['unidade_estoque'] ?? 'UN')) ?></th><th>Estoque da matéria-prima</th><th></th></tr></thead>
              <tbody id="compositionRows">
              <?php foreach ($compositionDisplayRows as $compRow): $selectedMaterial=(int)($compRow['materia_prima_id'] ?? 0); ?>
                <tr data-composition-row>
                  <td>
                    <select class="field" name="composition_material_id[]" data-composition-material>
                      <option value="">Selecione...</option>
                      <?php foreach ($compositionRawMaterials as $raw): ?>
                        <option value="<?= (int)$raw['id'] ?>" data-unit="<?= htmlspecialchars((string)$raw['unidade_estoque']) ?>" data-stock="<?= htmlspecialchars((string)$raw['estoque_atual']) ?>" data-meta="<?= !empty($raw['referencia']) ? 'Ref.: ' . htmlspecialchars((string)$raw['referencia']) . ' · ' : '' ?>Estoque: <?= htmlspecialchars(stock_qty_br($raw['estoque_atual'] ?? 0)) ?> <?= htmlspecialchars((string)$raw['unidade_estoque']) ?>" <?= $selectedMaterial===(int)$raw['id']?'selected':'' ?>><?= htmlspecialchars((string)$raw['nome']) ?><?= !empty($raw['referencia']) ? ' · Ref. ' . htmlspecialchars((string)$raw['referencia']) : '' ?><?= empty($raw['ativo'])?' (inativo)':'' ?></option>
                      <?php endforeach; ?>
                    </select>
                  </td>
                  <td><input class="field" name="composition_qty[]" value="<?= htmlspecialchars(product_composition_qty_label($compRow['quantidade'] ?? '', 6)) ?>" inputmode="decimal" placeholder="Ex.: 0,050"></td>
                  <td class="composition-material-stock" data-composition-stock>—</td>
                  <td><button class="btn btn-sm" type="button" data-remove-composition-row>Remover</button></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="product-composition-actions">
            <button class="btn btn-sm" type="button" id="addCompositionRow">+ Adicionar matéria-prima</button>
            <?php if (!$compositionRawMaterials): ?><span class="help">Cadastre ou edite primeiro os insumos e marque a opção <b>Matéria-prima</b>.</span><?php endif; ?>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endif; ?>

      <div class="product-commercial-line">
        <?php if ($gestaoEnabled && $hasCostColumn && !$hideProductCost): ?>
        <div class="row">
          <label>Custo</label>
          <input class="field" id="productCostField" name="custo" value="<?= htmlspecialchars(number_format((float)($produto['custo'] ?? 0), 4, ',', '.')) ?>" inputmode="decimal" placeholder="Ex: 12,5000">
        </div>
        <div class="row">
          <label>% sobre custo</label>
          <input class="field" id="productMarkupField" type="text" inputmode="decimal" autocomplete="off" placeholder="Ex: 35,00" title="Ao informar o percentual, o preço de venda é calculado automaticamente. Ao alterar o preço, o percentual é recalculado.">
        </div>
        <?php endif; ?>
        <div class="row">
          <label>Preço</label>
          <input class="field" id="productPriceField" name="preco" data-required data-mask="money" value="<?= htmlspecialchars((string)$produto['preco']) ?>" inputmode="decimal" placeholder="Ex: 39,90">
        </div>
        <?php if ($hasCodigoCardapio): ?>
        <div class="row product-code-compact">
          <label>Cód. cardápio</label>
          <input class="field" name="codigo_cardapio" value="<?= htmlspecialchars((string)($produto['codigo_cardapio'] ?? '')) ?>" placeholder="Ex.: 10 ou A15" maxlength="60" autocomplete="off">
        </div>
        <?php endif; ?>
        <?php if ($hasReferencia): ?>
        <div class="row product-code-compact">
          <label>Referência</label>
          <input class="field" name="referencia" value="<?= htmlspecialchars((string)($produto['referencia'] ?? '')) ?>" placeholder="Referência do produto" maxlength="180" autocomplete="off">
        </div>
        <?php endif; ?>
        <div class="row product-code-compact">
          <label>Cód. Próspero</label>
          <input class="field" name="codigo_firebird" value="<?= htmlspecialchars((string)($produto['codigo_firebird'] ?? '')) ?>" placeholder="Código legado">
        </div>
        <?php if ($hasGtinTributavel): ?>
        <div class="row">
          <label>GTIN tributável (cEANTrib)</label>
          <input class="field" name="gtin_tributavel" value="<?= htmlspecialchars((string)($produto['gtin_tributavel'] ?? '')) ?>" placeholder="GTIN ou SEM GTIN" inputmode="text" maxlength="20" autocomplete="off">
        </div>
        <?php endif; ?>
      </div>

      <?php if ($fiscalEnabled): ?>
      <div class="row" style="grid-column:1/-1;">
        <div class="product-fiscal-panel" id="abaFiscalProduto">
          <input type="hidden" name="fiscal_item_type" id="fiscalItemType" value="<?= !empty($produto['is_service']) ? 'SERVICE' : 'PRODUCT' ?>">
          <?php if ($productCrossContext): ?>
          <div class="help" style="margin-bottom:10px;padding:9px 11px;border:1px solid #bbf7d0;background:#f0fdf4;border-radius:8px;color:#166534;">V8.2: esta tributação e suas operações fiscais pertencem a <strong><?= htmlspecialchars($productCompanyName, ENT_QUOTES, 'UTF-8') ?></strong>. Emissão e demais tabelas fiscais ainda não homologadas continuam protegidas.</div>
          <?php endif; ?>
          <div class="product-fiscal-head">
            <div>
              <strong>Fiscal — <span id="fiscalItemTypeLabel"><?= !empty($produto['is_service']) ? 'Serviço / NFS-e' : 'Produto / NF-e e NFC-e' ?></span></strong>
              <span id="fiscalItemTypeHelp"><?= !empty($produto['is_service']) ? 'NBS, LC 116, ISS, IBS/CBS, retenções e dados da NFS-e Nacional.' : 'NCM, CEST, ICMS, IPI, PIS/COFINS e CFOPs.' ?></span>
            </div>
            <a class="btn btn-sm" href="fiscal_tributacoes.php" target="_blank">Configurar tributações</a>
            <?php if ($productCrossContext): ?>
              <span class="help" title="V8.2: Tributações e Operações fiscais já respeitam a empresa do F11. Emissão e demais tabelas fiscais continuam protegidas.">Tributações + Operações liberadas · demais tabelas protegidas</span>
            <?php endif; ?>
          </div>

          <div class="form-grid product-fiscal-grid">
            <div class="row span-3 product-fiscal-subsection product-fiscal-profile-block">
              <div class="product-fiscal-subhead">
                <div><strong>Regra de tributação</strong><span>São exibidas apenas regras compatíveis com o tipo marcado no cadastro do item.</span></div>
              </div>
              <div class="product-fiscal-mini-grid">
                <div class="row span-3">
                  <label>Tributação *</label>
                  <select class="field" name="fiscal_tax_profile_id" id="fiscalTaxProfile">
                    <option value="">Selecione a tributação</option>
                    <?php foreach ($fiscalProfiles as $tp): $tpType = fiscal_normalize_item_type((string)($tp['profile_type'] ?? 'PRODUCT')); ?>
                      <option value="<?= (int)$tp['id'] ?>" data-profile-type="<?= $tpType ?>" <?= ((string)($fiscalTax['tax_profile_id'] ?? '') === (string)$tp['id']) ? 'selected' : '' ?>><?= htmlspecialchars(($tpType === 'SERVICE' ? '[SERVIÇO] ' : '[PRODUTO] ') . trim((string)($tp['code'] ?? '') . ' - ' . (string)($tp['name'] ?? '')), ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                  </select>
                  <div class="help">A seleção copia a regra para o produto, preservando um retrato fiscal próprio para a emissão.</div>
                </div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="SERVICE">
              <div class="product-fiscal-subhead">
                <div><strong>Classificação do serviço</strong><span>Identificação do serviço para a DPS e a NFS-e Nacional.</span></div>
              </div>
              <div class="product-fiscal-mini-grid">
                <div class="row"><label>NBS *</label><input class="field" name="fiscal_nbs_code" id="fiscalNbsInput" maxlength="9" inputmode="numeric" data-fiscal="nbs_code" list="fiscalNbsList" value="<?= htmlspecialchars((string)($fiscalTax['nbs_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Código NBS"><div class="help" id="fiscalNbsHelp"></div></div>
                <div class="row span-2"><label>Descrição NBS</label><input class="field" name="fiscal_nbs_description" id="fiscalNbsDescription" data-fiscal="nbs_description" value="<?= htmlspecialchars((string)($fiscalTax['nbs_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Lista de Serviços LC 116/03 *</label><input class="field" name="fiscal_lc116_service_code" data-fiscal="lc116_service_code" list="fiscalServiceCodeList" value="<?= htmlspecialchars((string)($fiscalTax['lc116_service_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 0107"></div>
                <div class="row span-2"><label>Descrição da LC 116/03</label><input class="field" name="fiscal_lc116_service_description" data-fiscal="lc116_service_description" value="<?= htmlspecialchars((string)($fiscalTax['lc116_service_description'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="SERVICE">
              <div class="product-fiscal-subhead">
                <div><strong>ISS e retenções</strong><span>Alíquotas e retenções aplicáveis à prestação do serviço.</span></div>
              </div>
              <div class="product-fiscal-mini-grid">
                <div class="row"><label>ISS %</label><input class="field" name="fiscal_iss_rate" data-fiscal="iss_rate" value="<?= htmlspecialchars((string)($fiscalTax['iss_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>ISS retido</label><label style="display:flex;gap:8px;align-items:center;margin-top:14px"><input type="checkbox" name="fiscal_iss_withheld" data-fiscal="iss_withheld" <?= !empty($fiscalTax['iss_withheld']) ? 'checked' : '' ?>> Retido pelo tomador</label></div>
                <div class="row"><label>Retenção PIS/COFINS/CSLL</label><select class="field" name="fiscal_pis_cofins_retention_type" data-fiscal="pis_cofins_retention_type">
                  <?php foreach ([
                    ''=>'Não informado',
                    '0'=>'0 - PIS/COFINS/CSLL não retidos',
                    '1'=>'1 - PIS/COFINS retidos',
                    '2'=>'2 - PIS/COFINS não retidos',
                    '3'=>'3 - PIS/COFINS/CSLL retidos',
                    '4'=>'4 - PIS/COFINS retidos; CSLL não retida',
                    '5'=>'5 - PIS retido; COFINS/CSLL não retidos',
                    '6'=>'6 - COFINS retida; PIS/CSLL não retidos',
                    '7'=>'7 - PIS não retido; COFINS/CSLL retidos',
                    '8'=>'8 - PIS/COFINS não retidos; CSLL retida',
                    '9'=>'9 - COFINS não retida; PIS/CSLL retidos',
                  ] as $retValue => $retLabel): ?>
                    <option value="<?= htmlspecialchars((string)$retValue, ENT_QUOTES, 'UTF-8') ?>" <?= (string)($fiscalTax['pis_cofins_retention_type'] ?? '')===(string)$retValue?'selected':'' ?>><?= htmlspecialchars($retLabel, ENT_QUOTES, 'UTF-8') ?></option>
                  <?php endforeach; ?>
                </select></div>
                <div class="row"><label>IRRF %</label><input class="field" name="fiscal_irrf_rate" data-fiscal="irrf_rate" value="<?= htmlspecialchars((string)($fiscalTax['irrf_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>CSLL %</label><input class="field" name="fiscal_csll_rate" data-fiscal="csll_rate" value="<?= htmlspecialchars((string)($fiscalTax['csll_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>INSS %</label><input class="field" name="fiscal_inss_rate" data-fiscal="inss_rate" value="<?= htmlspecialchars((string)($fiscalTax['inss_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>Cód. tributação municipal</label><input class="field" name="fiscal_municipal_tax_code" data-fiscal="municipal_tax_code" value="<?= htmlspecialchars((string)($fiscalTax['municipal_tax_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>CNAE / cIntContrib Pronim</label><input class="field" name="fiscal_cnae" data-fiscal="cnae" value="<?= htmlspecialchars((string)($fiscalTax['cnae'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric" placeholder="Ex: 6209100"><small style="display:block;margin-top:4px;color:#64748b">Em Itabira/ISS Digital, este código é enviado como cIntContrib quando o serviço nacional possui mais de uma atividade municipal possível.</small></div>
                <div class="row"><label>Incentivo fiscal</label><label style="display:flex;gap:8px;align-items:center;margin-top:14px"><input type="checkbox" name="fiscal_fiscal_incentive" data-fiscal="fiscal_incentive" <?= !empty($fiscalTax['fiscal_incentive']) ? 'checked' : '' ?>> Sim</label></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="SERVICE">
              <div class="product-fiscal-subhead"><div><strong>NFS-e Nacional</strong><span>Parâmetros usados para formar a DPS no padrão nacional.</span></div></div>
              <div class="product-fiscal-mini-grid">
                <div class="row"><label>Tipo de tributação</label><input class="field" name="fiscal_nfse_national_tax_type" data-fiscal="nfse_national_tax_type" value="<?= htmlspecialchars((string)($fiscalTax['nfse_national_tax_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: Operação tributável"></div>
                <div class="row"><label>Cód. Tributação Nacional *</label><input class="field" name="fiscal_nfse_national_tax_code" maxlength="6" inputmode="numeric" data-fiscal="nfse_national_tax_code" value="<?= htmlspecialchars((string)($fiscalTax['nfse_national_tax_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 010701"></div>
                <div class="row"><label>Indicador da operação *</label><input class="field" name="fiscal_nfse_national_operation_indicator" maxlength="6" inputmode="numeric" data-fiscal="nfse_national_operation_indicator" value="<?= htmlspecialchars((string)($fiscalTax['nfse_national_operation_indicator'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="cIndOp"></div>
                <div class="row"><label>Regime do Simples Nacional</label><input class="field" name="fiscal_nfse_simples_tax_regime" data-fiscal="nfse_simples_tax_regime" value="<?= htmlspecialchars((string)($fiscalTax['nfse_simples_tax_regime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Tipo fornecimento/recebimento</label><input class="field" name="fiscal_nfse_operation_payment_type" data-fiscal="nfse_operation_payment_type" value="<?= htmlspecialchars((string)($fiscalTax['nfse_operation_payment_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Regime especial RTC</label><input class="field" name="fiscal_nfse_rtc_special_regime" data-fiscal="nfse_rtc_special_regime" value="<?= htmlspecialchars((string)($fiscalTax['nfse_rtc_special_regime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="SERVICE">
              <div class="product-fiscal-subhead"><div><strong>NFS-e Municipal e benefício</strong><span>Campos mantidos para municípios que exigem complementos próprios.</span></div></div>
              <div class="product-fiscal-mini-grid">
                <div class="row"><label>Tipo de tributação municipal</label><input class="field" name="fiscal_nfse_municipal_tax_type" data-fiscal="nfse_municipal_tax_type" value="<?= htmlspecialchars((string)($fiscalTax['nfse_municipal_tax_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Natureza de tributação</label><input class="field" name="fiscal_nfse_municipal_tax_nature" data-fiscal="nfse_municipal_tax_nature" value="<?= htmlspecialchars((string)($fiscalTax['nfse_municipal_tax_nature'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Exigibilidade do ISS</label><input class="field" name="fiscal_nfse_municipal_iss_exigibility" data-fiscal="nfse_municipal_iss_exigibility" value="<?= htmlspecialchars((string)($fiscalTax['nfse_municipal_iss_exigibility'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Regime especial municipal</label><input class="field" name="fiscal_nfse_municipal_special_regime" data-fiscal="nfse_municipal_special_regime" value="<?= htmlspecialchars((string)($fiscalTax['nfse_municipal_special_regime'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Tipo de benefício municipal</label><input class="field" name="fiscal_municipal_benefit_type" data-fiscal="municipal_benefit_type" value="<?= htmlspecialchars((string)($fiscalTax['municipal_benefit_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>Alíquota diferenciada %</label><input class="field" name="fiscal_municipal_differentiated_rate" data-fiscal="municipal_differentiated_rate" value="<?= htmlspecialchars((string)($fiscalTax['municipal_differentiated_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>Redução de base %</label><input class="field" name="fiscal_municipal_reduction_base_percent" data-fiscal="municipal_reduction_base_percent" value="<?= htmlspecialchars((string)($fiscalTax['municipal_reduction_base_percent'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>Número do benefício</label><input class="field" name="fiscal_municipal_benefit_number" data-fiscal="municipal_benefit_number" value="<?= htmlspecialchars((string)($fiscalTax['municipal_benefit_number'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="PRODUCT">
              <div class="product-fiscal-subhead"><div><strong>Identificação fiscal do produto</strong><span>Classificação usada em NF-e, NFC-e, entradas e relatórios fiscais.</span></div></div>
              <div class="product-fiscal-mini-grid">
                <div class="row"><label>NCM</label><input class="field" name="fiscal_ncm" id="fiscalNcmInput" list="fiscalNcmList" value="<?= htmlspecialchars((string)($fiscalTax['ncm'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 22011000" inputmode="numeric"><div class="help" id="fiscalNcmHelp"></div></div>
                <div class="row"><label>CEST</label><input class="field" name="fiscal_cest" id="fiscalCestInput" list="fiscalCestList" value="<?= htmlspecialchars((string)($fiscalTax['cest'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 0100100" inputmode="numeric"></div>
                <div class="row"><label>Origem</label><select class="field" name="fiscal_origem"><option value="0" <?= (string)($fiscalTax['origem'] ?? '0')==='0'?'selected':'' ?>>0 - Nacional</option><option value="1" <?= (string)($fiscalTax['origem'] ?? '')==='1'?'selected':'' ?>>1 - Estrangeira importação direta</option><option value="2" <?= (string)($fiscalTax['origem'] ?? '')==='2'?'selected':'' ?>>2 - Estrangeira mercado interno</option><option value="3" <?= (string)($fiscalTax['origem'] ?? '')==='3'?'selected':'' ?>>3 - Nacional com conteúdo importado &gt; 40%</option><option value="4" <?= (string)($fiscalTax['origem'] ?? '')==='4'?'selected':'' ?>>4 - Nacional conforme legislação</option><option value="5" <?= (string)($fiscalTax['origem'] ?? '')==='5'?'selected':'' ?>>5 - Nacional conteúdo importado ≤ 40%</option><option value="6" <?= (string)($fiscalTax['origem'] ?? '')==='6'?'selected':'' ?>>6 - Estrangeira sem similar nacional</option><option value="7" <?= (string)($fiscalTax['origem'] ?? '')==='7'?'selected':'' ?>>7 - Estrangeira mercado interno sem similar</option><option value="8" <?= (string)($fiscalTax['origem'] ?? '')==='8'?'selected':'' ?>>8 - Nacional conteúdo importado &gt; 70%</option></select></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="PRODUCT">
              <div class="product-fiscal-subhead"><div><strong>ICMS / CSOSN e ICMS-ST</strong><span>Dados principais de tributação estadual.</span></div></div>
              <div class="product-fiscal-mini-grid product-fiscal-line-5">
                <div class="row"><label>Tributação</label><select class="field" name="fiscal_tax_mode" data-fiscal="tax_mode"><option value="TRIBUTADO" <?= ($fiscalTax['tax_mode'] ?? '')==='TRIBUTADO'?'selected':'' ?>>Tributado</option><option value="NAO_TRIBUTADO" <?= ($fiscalTax['tax_mode'] ?? '')==='NAO_TRIBUTADO'?'selected':'' ?>>Não tributado</option><option value="SUBSTITUICAO" <?= ($fiscalTax['tax_mode'] ?? '')==='SUBSTITUICAO'?'selected':'' ?>>Substituição</option><option value="ISENTO" <?= ($fiscalTax['tax_mode'] ?? '')==='ISENTO'?'selected':'' ?>>Isento</option><option value="ISS" <?= ($fiscalTax['tax_mode'] ?? '')==='ISS'?'selected':'' ?>>ISS</option></select></div>
                <div class="row"><label>CSOSN</label><input class="field" name="fiscal_icms_csosn" data-fiscal="icms_csosn" value="<?= htmlspecialchars((string)($fiscalTax['icms_csosn'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>CST ICMS</label><input class="field" name="fiscal_icms_cst" data-fiscal="icms_cst" value="<?= htmlspecialchars((string)($fiscalTax['icms_cst'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>ICMS %</label><input class="field" name="fiscal_icms_rate" data-fiscal="icms_rate" value="<?= htmlspecialchars((string)($fiscalTax['icms_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>ICMS ST %</label><input class="field" name="fiscal_icms_st_rate" data-fiscal="icms_st_rate" value="<?= htmlspecialchars((string)($fiscalTax['icms_st_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="PRODUCT">
              <div class="product-fiscal-subhead"><div><strong>IPI, PIS e COFINS</strong><span>Tributos federais de saída do produto.</span></div></div>
              <div class="product-fiscal-mini-grid product-fiscal-line-6">
                <div class="row"><label>IPI %</label><input class="field" name="fiscal_ipi_rate" data-fiscal="ipi_rate" value="<?= htmlspecialchars((string)($fiscalTax['ipi_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>CST IPI</label><input class="field" name="fiscal_ipi_cst" data-fiscal="ipi_cst" list="fiscalIpiCstList" value="<?= htmlspecialchars((string)($fiscalTax['ipi_cst'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>PIS %</label><input class="field" name="fiscal_pis_rate" data-fiscal="pis_out_rate" value="<?= htmlspecialchars((string)($fiscalTax['pis_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>CST PIS</label><input class="field" name="fiscal_pis_cst" data-fiscal="pis_out_cst" value="<?= htmlspecialchars((string)($fiscalTax['pis_cst'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
                <div class="row"><label>COFINS %</label><input class="field" name="fiscal_cofins_rate" data-fiscal="cofins_out_rate" value="<?= htmlspecialchars((string)($fiscalTax['cofins_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>CST COFINS</label><input class="field" name="fiscal_cofins_cst" data-fiscal="cofins_out_cst" value="<?= htmlspecialchars((string)($fiscalTax['cofins_cst'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="PRODUCT">
              <div class="product-fiscal-subhead"><div><strong>CFOPs padrão e acréscimos</strong><span>CFOPs básicos do produto e percentual de frete/acréscimo.</span></div></div>
              <div class="product-fiscal-mini-grid product-fiscal-line-7">
                <div class="row"><label>Frete %</label><input class="field" name="fiscal_freight_rate" value="<?= htmlspecialchars((string)($fiscalTax['freight_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>CFOP Venda dentro do Estado</label><input class="field" name="fiscal_cfop_sale_state" data-fiscal="cfop_sale_state" value="<?= htmlspecialchars((string)($fiscalTax['cfop_sale_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
                <div class="row"><label>CFOP Venda fora do Estado</label><input class="field" name="fiscal_cfop_sale_interstate" data-fiscal="cfop_sale_interstate" value="<?= htmlspecialchars((string)($fiscalTax['cfop_sale_interstate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
                <div class="row"><label>CFOP Devolução dentro do Estado</label><input class="field" name="fiscal_cfop_return_state" data-fiscal="cfop_return_state" value="<?= htmlspecialchars((string)($fiscalTax['cfop_return_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
                <div class="row"><label>CFOP Devolução fora do Estado</label><input class="field" name="fiscal_cfop_return_interstate" data-fiscal="cfop_return_interstate" value="<?= htmlspecialchars((string)($fiscalTax['cfop_return_interstate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
                <div class="row"><label>CFOP Entrada dentro do Estado</label><input class="field" name="fiscal_cfop_entry_state" data-fiscal="cfop_entry_state" value="<?= htmlspecialchars((string)($fiscalTax['cfop_entry_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
                <div class="row"><label>CFOP Entrada fora do Estado</label><input class="field" name="fiscal_cfop_entry_interstate" data-fiscal="cfop_entry_interstate" value="<?= htmlspecialchars((string)($fiscalTax['cfop_entry_interstate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" inputmode="numeric"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection product-fiscal-ibs-cbs-last">
              <div class="product-fiscal-subhead"><div><strong>IBS / CBS</strong><span>Classificação e alíquotas compartilhadas por produtos e serviços.</span></div></div>
              <div class="product-fiscal-mini-grid product-fiscal-ibs-row-1">
                <div class="row"><label>CST IBS/CBS *</label><input class="field" name="fiscal_ibs_cbs_cst" maxlength="3" inputmode="numeric" data-fiscal="ibs_cbs_cst" value="<?= htmlspecialchars((string)($fiscalTax['ibs_cbs_cst'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="Ex: 000"></div>
                <div class="row"><label>Classificação IBS/CBS *</label><input class="field" id="fiscalIbsCbsClassificationCode" name="fiscal_ibs_cbs_classification_code" maxlength="6" inputmode="numeric" data-fiscal="ibs_cbs_classification_code" list="fiscalIbsCbsList" value="<?= htmlspecialchars((string)($fiscalTax['ibs_cbs_classification_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" placeholder="cClassTrib"></div>
                <div class="row"><label>Descrição IBS/CBS</label><input class="field" id="fiscalIbsCbsClassificationName" name="fiscal_ibs_cbs_classification_name" data-fiscal="ibs_cbs_classification_name" value="<?= htmlspecialchars((string)($fiscalTax['ibs_cbs_classification_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" readonly aria-readonly="true" title="Preenchida automaticamente conforme a Classificação IBS/CBS selecionada."></div>
              </div>
              <div class="product-fiscal-mini-grid product-fiscal-ibs-row-2">
                <div class="row"><label>Origem das alíquotas</label><select class="field" name="fiscal_ibs_cbs_rate_mode" data-fiscal="ibs_cbs_rate_mode"><option value="AUTO" <?= strtoupper((string)($fiscalTax['ibs_cbs_rate_mode'] ?? 'AUTO')) === 'AUTO' ? 'selected' : '' ?>>Automática por vigência</option><option value="MANUAL" <?= strtoupper((string)($fiscalTax['ibs_cbs_rate_mode'] ?? 'AUTO')) === 'MANUAL' ? 'selected' : '' ?>>Manual no item</option><option value="DESATIVADO" <?= strtoupper((string)($fiscalTax['ibs_cbs_rate_mode'] ?? 'AUTO')) === 'DESATIVADO' ? 'selected' : '' ?>>Não gerar IBS/CBS</option></select></div>
                <div class="row"><label>CBS %</label><input class="field" name="fiscal_cbs_rate" data-fiscal="cbs_rate" value="<?= htmlspecialchars((string)($fiscalTax['cbs_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>IBS UF %</label><input class="field" name="fiscal_ibs_uf_rate" data-fiscal="ibs_uf_rate" value="<?= htmlspecialchars((string)($fiscalTax['ibs_uf_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
                <div class="row"><label>IBS Município %</label><input class="field" name="fiscal_ibs_mun_rate" data-fiscal="ibs_mun_rate" value="<?= htmlspecialchars((string)($fiscalTax['ibs_mun_rate'] ?? '0'), ENT_QUOTES, 'UTF-8') ?>" inputmode="decimal"></div>
              </div>
            </div>

            <div class="row span-3 product-fiscal-subsection" data-item-kind="PRODUCT">
              <div class="product-fiscal-subhead"><div><strong>Operações e CFOPs do produto</strong><span>Mapeamentos usados nas vendas, entradas, SPED e Sintegra.</span></div><?php if ($productCanManageFiscalOperations): ?><a class="btn btn-sm" href="<?= htmlspecialchars(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_operacoes.php?new=1') : 'fiscal_operacoes.php?new=1', ENT_QUOTES, 'UTF-8') ?>" target="_blank">+ Operação</a><?php else: ?><span class="help">Sem permissão para cadastrar operação</span><?php endif; ?></div>
              <?php if (!$fiscalProductOperationCfops): ?>
                <div class="help">Nenhuma operação fiscal ativa cadastrada.</div>
              <?php else: ?>
                <div class="product-opcfop-list" id="productOpCfopList">
                  <?php foreach ($fiscalProductOperationCfops as $opMap): $opId = (int)($opMap['operation_id'] ?? 0); if ($opId <= 0) continue; ?>
                    <div class="product-opcfop-row" data-product-operation="<?= $opId ?>">
                      <label class="product-opcfop-use">Usar
                        <select class="field" data-operation-use-select="<?= $opId ?>">
                          <option value="1" <?= !empty($opMap['enabled']) ? 'selected' : '' ?>>Sim</option>
                          <option value="0" <?= empty($opMap['enabled']) ? 'selected' : '' ?>>Não</option>
                        </select>
                        <input type="checkbox" hidden data-operation-use-hidden="<?= $opId ?>" name="product_operation_enabled[<?= $opId ?>]" value="1" <?= !empty($opMap['enabled']) ? 'checked' : '' ?>>
                      </label>
                      <div class="product-opcfop-title"><span class="product-opcfop-caption">Operação</span><strong><?= htmlspecialchars(trim((string)($opMap['operation_code'] ?? '') . ' - ' . (string)($opMap['operation_name'] ?? ''), ' -'), ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars(($opMap['direction'] ?? '') . ' · ' . ($opMap['operation_type'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span></div>
                      <label>Entrada UF<input class="field" name="product_cfop_in_state[<?= $opId ?>]" data-op-cfop="cfop_in_state" data-operation-id="<?= $opId ?>" value="<?= htmlspecialchars((string)($opMap['cfop_in_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="4" inputmode="numeric"></label>
                      <label>Entrada fora UF<input class="field" name="product_cfop_in_interstate[<?= $opId ?>]" data-op-cfop="cfop_in_interstate" data-operation-id="<?= $opId ?>" value="<?= htmlspecialchars((string)($opMap['cfop_in_interstate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="4" inputmode="numeric"></label>
                      <label>Saída UF<input class="field" name="product_cfop_out_state[<?= $opId ?>]" data-op-cfop="cfop_out_state" data-operation-id="<?= $opId ?>" value="<?= htmlspecialchars((string)($opMap['cfop_out_state'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="4" inputmode="numeric"></label>
                      <label>Saída fora UF<input class="field" name="product_cfop_out_interstate[<?= $opId ?>]" data-op-cfop="cfop_out_interstate" data-operation-id="<?= $opId ?>" value="<?= htmlspecialchars((string)($opMap['cfop_out_interstate'] ?? ''), ENT_QUOTES, 'UTF-8') ?>" maxlength="4" inputmode="numeric"></label>
                      <label class="product-opcfop-custom"><input type="checkbox" name="product_operation_custom[<?= $opId ?>]" value="1" <?= empty($opMap['inherited']) ? 'checked' : '' ?>> Personalizar no produto</label>
                      <label class="product-opcfop-notes">Obs.<input class="field" name="product_operation_notes[<?= $opId ?>]" data-op-cfop="notes" data-operation-id="<?= $opId ?>" value="<?= htmlspecialchars((string)($opMap['notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"></label>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
            </div>

            <div class="row" data-item-kind="PRODUCT"><label>Produto monofásico</label><label style="display:flex;gap:8px;align-items:center;margin-top:14px"><input type="checkbox" name="fiscal_is_monofasico" <?= !empty($fiscalTax['is_monofasico']) ? 'checked' : '' ?>> Sim</label></div>
            <div class="row span-3"><label>Observações fiscais do item</label><textarea class="field" name="fiscal_notes" rows="2"><?= htmlspecialchars((string)($fiscalTax['fiscal_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></textarea></div>
          </div>
        </div>
      </div>
      <?php endif; ?>
      <?php if ($fiscalEnabled): ?>
      <datalist id="fiscalNbsList">
        <?php foreach (($fiscalNbs ?? []) as $nbs): ?>
          <option value="<?= htmlspecialchars((string)($nbs['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strimwidth((string)($nbs['description'] ?? ''), 0, 140, '...'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="fiscalServiceCodeList">
        <?php foreach (($fiscalServiceCodes ?? []) as $sc): ?>
          <option value="<?= htmlspecialchars((string)($sc['code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strimwidth((string)($sc['description'] ?? ''), 0, 140, '...'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="fiscalNcmList">
        <?php foreach ($fiscalNcms as $n): ?>
          <option value="<?= htmlspecialchars((string)$n['ncm'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strimwidth((string)($n['description'] ?? ''), 0, 120, '...'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="fiscalCestList">
        <?php foreach ($fiscalCests as $c): ?>
          <option value="<?= htmlspecialchars((string)$c['cest'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(trim((string)($c['ncm'] ?? '') . ' ' . mb_strimwidth((string)($c['description'] ?? ''), 0, 100, '...')), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="fiscalIpiCstList">
        <?php foreach (($fiscalCstCodes ?? []) as $c): if (($c['tax_type'] ?? '') !== 'IPI_CST') continue; ?>
          <option value="<?= htmlspecialchars((string)$c['code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$c['description'], ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <datalist id="fiscalIbsCbsList">
        <?php foreach (($fiscalIbsCbsClasses ?? []) as $c): ?>
          <option value="<?= htmlspecialchars((string)$c['classification_code'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars(mb_strimwidth((string)($c['description'] ?? ''), 0, 120, '...'), ENT_QUOTES, 'UTF-8') ?></option>
        <?php endforeach; ?>
      </datalist>
      <?php endif; ?>

      <?php if ($productOrderOptionsEnabled): ?>
      <div class="row" style="grid-column:1/-1;">
        <label>Descrição (aparece no catálogo e no detalhe)</label>
        <textarea class="field" name="descricao" rows="4" placeholder="Ex: Mussarela, provolone, parmesão e catupiry."><?= htmlspecialchars((string)$produto['descricao']) ?></textarea>
      </div>

      <div class="row" style="grid-column:1/-1;" id="adicionaisProduto">
        <label>Adicionais e escolhas do produto</label>
        <div class="help" style="margin-bottom:10px;">
          Use esta área para montar opções como <b>Escolha a carne</b>, <b>Acompanhamentos</b>, <b>Molhos</b> ou qualquer adicional do produto.
          Exemplo: grupo obrigatório de escolha única para carne e grupo de múltipla escolha com limite de 3 acompanhamentos.
        </div>

        <div id="optionsBuilder" class="options-builder">
          <?php if (!$optionGroups): ?>
            <div class="help empty-options-note">Nenhum adicional cadastrado para este produto.</div>
          <?php endif; ?>

          <?php foreach ($optionGroups as $gIndex => $group):
            $gKey = 'g' . (int)$gIndex;
          ?>
            <div class="option-admin-group" data-group>
              <input type="hidden" name="option_group_key[]" value="<?= htmlspecialchars($gKey) ?>">
              <div class="option-admin-head">
                <strong>Grupo de opções</strong>
                <button class="btn btn-sm" type="button" data-remove-group>Remover grupo</button>
              </div>

              <div class="form-grid option-admin-grid">
                <div class="row">
                  <label>Título do grupo</label>
                  <input class="field" name="option_group_title[<?= htmlspecialchars($gKey) ?>]" value="<?= htmlspecialchars((string)$group['titulo']) ?>" placeholder="Ex: Escolha a carne">
                </div>
                <div class="row">
                  <label>Tipo</label>
                  <select class="field" name="option_group_type[<?= htmlspecialchars($gKey) ?>]">
                    <option value="single" <?= ((string)$group['tipo'] === 'single') ? 'selected' : '' ?>>Escolha única</option>
                    <option value="multiple" <?= ((string)$group['tipo'] === 'multiple') ? 'selected' : '' ?>>Múltipla escolha</option>
                  </select>
                </div>
                <div class="row">
                  <label>Obrigatório</label>
                  <select class="field" name="option_group_required[<?= htmlspecialchars($gKey) ?>]">
                    <option value="1" <?= ((int)$group['obrigatorio'] === 1) ? 'selected' : '' ?>>Sim</option>
                    <option value="0" <?= ((int)$group['obrigatorio'] !== 1) ? 'selected' : '' ?>>Não</option>
                  </select>
                </div>
                <div class="row">
                  <label>Mínimo</label>
                  <input class="field" type="number" min="0" name="option_group_min[<?= htmlspecialchars($gKey) ?>]" value="<?= (int)$group['min_escolhas'] ?>">
                </div>
                <div class="row">
                  <label>Máximo</label>
                  <input class="field" type="number" min="0" name="option_group_max[<?= htmlspecialchars($gKey) ?>]" value="<?= (int)$group['max_escolhas'] ?>">
                  <div class="help">Para múltipla escolha, use 3 para permitir até 3 opções.</div>
                </div>
                <div class="row">
                  <label>Ordem</label>
                  <input class="field" type="number" name="option_group_sort[<?= htmlspecialchars($gKey) ?>]" value="<?= (int)$group['sort_order'] ?>">
                </div>
                <div class="row">
                  <label>Ativo</label>
                  <select class="field" name="option_group_active[<?= htmlspecialchars($gKey) ?>]">
                    <option value="1" <?= ((int)$group['ativo'] === 1) ? 'selected' : '' ?>>Sim</option>
                    <option value="0" <?= ((int)$group['ativo'] !== 1) ? 'selected' : '' ?>>Não</option>
                  </select>
                </div>
              </div>

              <div class="option-items-admin" data-items>
                <div class="help" style="margin:4px 0 8px;">Itens desse grupo</div>
                <?php foreach ((array)$group['items'] as $itIndex => $item): ?>
                  <div class="option-admin-item" data-item>
                    <input class="field" name="option_item_name[<?= htmlspecialchars($gKey) ?>][]" value="<?= htmlspecialchars((string)$item['nome']) ?>" placeholder="Nome da opção. Ex: Bife acebolado">
                    <input class="field" name="option_item_price[<?= htmlspecialchars($gKey) ?>][]" value="<?= htmlspecialchars(number_format((float)$item['preco'], 2, ',', '.')) ?>" placeholder="Acréscimo. Ex: 3,00" inputmode="decimal">
                    <input class="field" type="number" name="option_item_sort[<?= htmlspecialchars($gKey) ?>][]" value="<?= (int)$item['sort_order'] ?>" placeholder="Ordem">
                    <select class="field" name="option_item_active[<?= htmlspecialchars($gKey) ?>][]">
                      <option value="1" <?= ((int)$item['ativo'] === 1) ? 'selected' : '' ?>>Ativo</option>
                      <option value="0" <?= ((int)$item['ativo'] !== 1) ? 'selected' : '' ?>>Inativo</option>
                    </select>
                    <button class="btn btn-sm" type="button" data-remove-item>Remover</button>
                  </div>
                <?php endforeach; ?>
              </div>

              <button class="btn btn-sm" type="button" data-add-item>Adicionar opção</button>
            </div>
          <?php endforeach; ?>
        </div>

        <div class="actions" style="margin-top:10px;">
          <button class="btn" type="button" id="addOptionGroup">Adicionar grupo de opções</button>
          <button class="btn" type="button" id="addExecutiveExample">Inserir exemplo Prato Executivo</button>
        </div>
      </div>

      <?php endif; // Comandas ou Delivery: descrição, adicionais e escolhas ?>
      <div class="row" style="grid-column:1/-1;">
        <label>Imagem do produto</label>
        <div class="fileline">
          <img id="imgPreview" class="thumb" src="<?= htmlspecialchars($imgUrl ?: '/assets/no-image.svg') ?>" alt="Imagem">
          <div style="flex:1;min-width:260px;">
            <input class="field" type="file" name="imagem" accept=".jpg,.jpeg,.png,.webp" data-preview="#imgPreview">
            <div class="help" style="margin-top:6px;">Atual: <?= htmlspecialchars($img ?: '—') ?></div>
          </div>
        </div>
      </div>

      <div class="actions right product-form-actions" style="grid-column:1/-1;">
        <div class="actions-right">
          <a class="btn" href="produtos.php">Cancelar</a>
          <?php if ((int)$id > 0 && !$productCrossContext): ?>
            <button class="btn" type="submit" form="duplicateProductForm">Duplicar produto</button>
          <?php endif; ?>
          <a class="btn" href="produto_form.php">+ Adicionar novo produto</a>
          <button class="btn" type="submit" name="save_action" value="new">Salvar e adicionar novo</button>
          <button class="btn btn-primary" type="submit" name="save_action" value="list">Salvar</button>
        </div>
      </div>
    </div>
    </form>
    <?php if ((int)$id > 0 && !$productCrossContext): ?>
      <form id="duplicateProductForm" method="post" onsubmit="return confirm('Duplicar este produto? A descrição receberá o texto Duplicado.');">
        <input type="hidden" name="action" value="duplicate">
        <input type="hidden" name="produto_id" value="<?= (int)$id ?>">
      </form>
    <?php endif; ?>
  </div>

  <div class="product-modal-backdrop" id="productLookupModal" hidden aria-hidden="true">
    <div class="product-modal" role="dialog" aria-modal="true" aria-labelledby="productLookupTitle">
      <div class="product-modal-head">
        <div>
          <strong id="productLookupTitle">Buscar</strong>
          <span id="productLookupSubtitle">Digite para pesquisar e pressione Enter.</span>
        </div>
        <button class="btn btn-sm" type="button" data-close-product-modal="productLookupModal">Fechar</button>
      </div>
      <div class="product-modal-body">
        <input class="field" id="productLookupSearch" autocomplete="off" placeholder="Digite a pesquisa e pressione Enter">
        <div class="product-lookup-count" id="productLookupCount"></div>
        <div class="product-lookup-results" id="productLookupResults" role="listbox" tabindex="0"></div>
      </div>
    </div>
  </div>

  <?php if ($hasStockControl && $stockWriteEnabled && (int)$id > 0 && empty($produto['possui_composicao'])): ?>
  <div class="product-modal-backdrop" id="stockAdjustmentModal" hidden aria-hidden="true">
    <div class="product-modal product-stock-modal" role="dialog" aria-modal="true" aria-labelledby="stockAdjustmentTitle">
      <div class="product-modal-head">
        <div>
          <strong id="stockAdjustmentTitle">Acerto de estoque</strong>
          <span><?= htmlspecialchars((string)($produto['nome'] ?? 'Produto')) ?></span>
        </div>
        <button class="btn btn-sm" type="button" data-close-product-modal="stockAdjustmentModal">Fechar</button>
      </div>
      <form id="stockAdjustmentForm" method="post" class="product-modal-body">
        <input type="hidden" name="action" value="stock_adjust">
        <input type="hidden" name="ajax" value="1">
        <input type="hidden" name="produto_id" value="<?= (int)$id ?>">
        <div class="product-stock-adjust-grid">
          <div class="row">
            <label>Estoque atual</label>
            <input class="field" id="stockAdjustmentCurrent" value="<?= htmlspecialchars(stock_qty_br($produto['estoque_atual'] ?? 0)) ?>" readonly>
          </div>
          <div class="row">
            <label>Novo estoque</label>
            <input class="field" id="stockAdjustmentNew" name="novo_estoque" inputmode="decimal" autocomplete="off" required placeholder="Informe o novo saldo">
          </div>
          <div class="row">
            <label>Diferença</label>
            <input class="field" id="stockAdjustmentDifference" value="0" readonly>
          </div>
        </div>
        <div class="row">
          <label>Observação / motivo do acerto</label>
          <input class="field" name="stock_adjust_observation" maxlength="500" placeholder="Ex: Contagem física, correção de saldo, perda ou entrada manual">
        </div>
        <div class="msg err" id="stockAdjustmentError" hidden></div>
        <div class="actions right">
          <button class="btn" type="button" data-close-product-modal="stockAdjustmentModal">Cancelar</button>
          <button class="btn btn-primary" type="submit" id="stockAdjustmentSubmit">Confirmar acerto</button>
        </div>
      </form>
    </div>
  </div>
  <?php endif; ?>

<?php if ($compositionEnabled): ?>
<template id="compositionRowTemplate">
  <tr data-composition-row>
    <td>
      <select class="field" name="composition_material_id[]" data-composition-material>
        <option value="">Selecione...</option>
        <?php foreach ($compositionRawMaterials as $raw): ?>
          <option value="<?= (int)$raw['id'] ?>" data-unit="<?= htmlspecialchars((string)$raw['unidade_estoque']) ?>" data-stock="<?= htmlspecialchars((string)$raw['estoque_atual']) ?>" data-meta="<?= !empty($raw['referencia']) ? 'Ref.: ' . htmlspecialchars((string)$raw['referencia']) . ' · ' : '' ?>Estoque: <?= htmlspecialchars(stock_qty_br($raw['estoque_atual'] ?? 0)) ?> <?= htmlspecialchars((string)$raw['unidade_estoque']) ?>"><?= htmlspecialchars((string)$raw['nome']) ?><?= !empty($raw['referencia']) ? ' · Ref. ' . htmlspecialchars((string)$raw['referencia']) : '' ?><?= empty($raw['ativo'])?' (inativo)':'' ?></option>
        <?php endforeach; ?>
      </select>
    </td>
    <td><input class="field" name="composition_qty[]" inputmode="decimal" placeholder="Ex.: 0,050"></td>
    <td class="composition-material-stock" data-composition-stock>—</td>
    <td><button class="btn btn-sm" type="button" data-remove-composition-row>Remover</button></td>
  </tr>
</template>
<?php endif; ?>

<style>
.product-composition-panel{grid-column:1/-1;border:1px solid #c7d7f2;border-radius:18px;background:#f8fbff;padding:14px}.product-composition-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:12px}.product-composition-head>div:first-child strong{display:block;color:#0b1f5b;font-size:15px}.product-composition-head>div:first-child span{display:block;color:#64748b;font-size:11px;margin-top:3px;max-width:850px}.product-composition-stock-summary{min-width:210px;border:1px solid #dbeafe;border-radius:14px;background:#fff;padding:9px 12px;display:grid;gap:2px}.product-composition-stock-summary small{font-size:10px;color:#64748b;font-weight:800}.product-composition-stock-summary b{font-size:18px;color:#0b1f5b}.product-composition-flags{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}.product-composition-explanation{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:12px;padding:10px 12px;font-size:12px;line-height:1.5;margin-bottom:12px}.product-composition-settings{display:grid;grid-template-columns:minmax(220px,360px);margin-bottom:12px}.product-composition-table-wrap{overflow:auto;border:1px solid #dbeafe;border-radius:14px;background:#fff}.product-composition-table{width:100%;border-collapse:collapse;min-width:760px}.product-composition-table th,.product-composition-table td{padding:9px;border-bottom:1px solid #eef4ff;text-align:left;vertical-align:middle}.product-composition-table th{background:#f1f5ff;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.03em}.product-composition-table tbody tr:last-child td{border-bottom:0}.product-composition-table th:nth-child(1){width:46%}.product-composition-table th:nth-child(2){width:22%}.product-composition-table th:nth-child(3){width:20%}.composition-material-stock{font-size:12px;font-weight:850;color:#334155}.product-composition-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:10px}@media(max-width:760px){.product-composition-head{display:grid}.product-composition-stock-summary{min-width:0}.product-composition-settings{grid-template-columns:1fr}}
.options-builder{display:grid;gap:14px}
.product-main-line,.product-secondary-line,.product-commercial-line,.product-flags-panel,.product-stock-panel{grid-column:1/-1}
.product-main-line{display:grid;grid-template-columns:minmax(110px,.55fr) minmax(180px,1fr) minmax(280px,2.2fr) minmax(90px,.55fr) minmax(120px,.65fr);gap:10px;align-items:end}
.product-secondary-line{display:grid;grid-template-columns:minmax(110px,.55fr) minmax(260px,1.6fr) minmax(130px,.7fr) minmax(280px,1.6fr);gap:10px;align-items:end}
.product-commercial-line{display:grid;grid-template-columns:repeat(7,minmax(105px,1fr));gap:8px;align-items:end}.product-commercial-line .product-code-compact label{white-space:nowrap}.product-commercial-line .product-code-compact .field{min-width:0}
.product-lookup-field{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;align-items:center}.product-lookup-button{white-space:nowrap;min-height:42px}
.product-category-with-half{display:grid;grid-template-columns:minmax(0,1fr) 132px;gap:8px;align-items:center}.product-half-pizza-check{justify-content:center;white-space:nowrap}.product-half-pizza-check input{margin:0}
.product-inline-check{min-height:42px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fbff;padding:0 12px;display:flex;align-items:center;gap:8px;font-weight:800;color:#0f172a}
.product-flags-panel{border:1px solid #dbe4f0;border-radius:16px;background:#fbfdff;padding:10px 12px}.product-section-label{display:block;font-size:11px;font-weight:900;color:#64748b;text-transform:uppercase;letter-spacing:.03em;margin-bottom:7px}.product-flags-line{display:flex;align-items:center;gap:10px 18px;flex-wrap:wrap}.product-flag{display:inline-flex;gap:7px;align-items:center;font-size:12px;font-weight:800;color:#0f172a;white-space:nowrap}.product-flag input{margin:0}
.product-stock-panel{border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;padding:12px}.product-stock-head{display:flex;justify-content:space-between;gap:12px;align-items:center;margin-bottom:10px}.product-stock-head strong{display:block;color:#0b1f5b;font-size:15px}.product-stock-head span{display:block;color:#64748b;font-size:11px;margin-top:2px}.product-stock-line{display:grid;grid-template-columns:repeat(4,minmax(140px,1fr));gap:10px}.product-stock-line .row{margin:0}
.product-modal-backdrop[hidden]{display:none!important}.product-modal-backdrop{position:fixed;inset:0;z-index:10000;background:rgba(15,23,42,.48);display:flex;align-items:center;justify-content:center;padding:18px}.product-modal{width:min(720px,96vw);max-height:86vh;background:#fff;border-radius:20px;border:1px solid #dbe4f0;box-shadow:0 24px 70px rgba(15,23,42,.24);overflow:hidden;display:flex;flex-direction:column}.product-modal-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid #e5edf6;background:#f8fbff}.product-modal-head strong{display:block;color:#0b1f5b;font-size:18px}.product-modal-head span{display:block;color:#64748b;font-size:12px;margin-top:2px}.product-modal-body{padding:14px 16px;display:grid;gap:10px;overflow:auto}.product-lookup-count{font-size:11px;color:#64748b;min-height:16px}.product-lookup-results{border:1px solid #dbe4f0;border-radius:14px;overflow:auto;max-height:52vh;min-height:180px;background:#fff;outline:none}.product-lookup-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:10px 12px;text-align:left;cursor:pointer;color:#0f172a}.product-lookup-result:last-child{border-bottom:0}.product-lookup-result:hover,.product-lookup-result.is-active{background:#eef5ff}.product-lookup-result strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-lookup-result small{color:#64748b}.product-lookup-empty{padding:22px;text-align:center;color:#64748b}.product-stock-adjust-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
@media(max-width:900px){.product-main-line{grid-template-columns:repeat(3,minmax(0,1fr))}.product-secondary-line{grid-template-columns:repeat(2,minmax(0,1fr))}.product-commercial-line{grid-template-columns:repeat(3,minmax(0,1fr))}.product-category-with-half{grid-template-columns:minmax(0,1fr) 126px}}
@media(max-width:760px){.product-main-line,.product-secondary-line,.product-commercial-line,.product-stock-line,.product-stock-adjust-grid{grid-template-columns:1fr}.product-category-with-half{grid-template-columns:1fr}.product-half-pizza-check{justify-content:flex-start}.product-flags-line{align-items:flex-start;flex-direction:column}.product-flag{white-space:normal}.product-stock-head{align-items:flex-start;flex-direction:column}.product-stock-head .btn{width:100%;justify-content:center}.product-modal-backdrop{padding:8px}.product-modal{width:100%;max-height:94vh}}
.product-save-message{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.product-form-actions .actions-right{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}@media(max-width:680px){.product-save-message .btn,.product-form-actions .btn{width:100%;justify-content:center}}
.option-admin-group{border:1px solid #dbeafe;background:#f8fbff;border-radius:18px;padding:14px;display:grid;gap:12px}
.option-admin-head{display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap}
.option-admin-head strong{color:#0b1f5b}
.option-admin-grid{grid-template-columns:repeat(3,minmax(0,1fr))}
.option-items-admin{display:grid;gap:8px}
.option-admin-item{display:grid;grid-template-columns:1.7fr .8fr .55fr .7fr auto;gap:8px;align-items:center}
.empty-options-note{padding:12px;border:1px dashed #bfdbfe;border-radius:14px;background:#fff}
@media(max-width:980px){.option-admin-grid{grid-template-columns:1fr}.option-admin-item{grid-template-columns:1fr}}

.product-tabbar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px;position:sticky;top:88px;z-index:20;background:#fff;padding:8px 0;border-bottom:1px solid #eef2f7}.product-tabbar a{display:inline-flex;align-items:center;justify-content:center;padding:9px 12px;border:1px solid #dbe4f0;border-radius:999px;background:#fff;text-decoration:none;color:#0f172a;font-weight:850;font-size:13px}.product-tabbar a:hover{background:#f8fbff}
.product-fiscal-panel{border:1px solid #dbeafe;background:#f8fbff;border-radius:20px;padding:14px;display:grid;gap:12px}
.product-fiscal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;border-bottom:1px solid #e5edf6;padding-bottom:10px}
.product-fiscal-head strong{display:block;color:#0b1f5b;font-size:18px}.product-fiscal-head span{display:block;color:#64748b;font-size:13px;margin-top:3px}
.product-fiscal-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.product-fiscal-grid .span-2{grid-column:span 2}.product-fiscal-grid .span-3{grid-column:1/-1}
.product-fiscal-subsection{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:12px}.product-fiscal-profile-block{background:#f8fbff;border-color:#bfdbfe}.product-fiscal-ibs-cbs-last{background:#fbfdff}.product-fiscal-subhead{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px;flex-wrap:wrap}.product-fiscal-subhead strong{display:block;color:#0b1f5b}.product-fiscal-subhead span{display:block;color:#64748b;font-size:12px;margin-top:2px}.product-fiscal-mini-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.product-fiscal-mini-grid .span-2{grid-column:span 2}.product-fiscal-mini-grid .span-3{grid-column:1/-1}.product-fiscal-line-5{grid-template-columns:minmax(140px,1.3fr) repeat(4,minmax(82px,.8fr))}.product-fiscal-line-6{grid-template-columns:repeat(6,minmax(0,1fr))}.product-fiscal-line-7{grid-template-columns:repeat(7,minmax(0,1fr))}.product-fiscal-ibs-row-1{grid-template-columns:minmax(110px,.7fr) minmax(170px,1fr) minmax(260px,2fr)}.product-fiscal-ibs-row-2{grid-template-columns:minmax(180px,1.35fr) repeat(3,minmax(100px,1fr));margin-top:10px}.product-fiscal-ibs-row-1 input[readonly]{background:#f1f5f9;color:#475569;cursor:not-allowed}.product-opcfop-list{display:grid;gap:10px}.product-opcfop-row{display:grid;grid-template-columns:64px minmax(180px,1.55fr) repeat(4,minmax(76px,.58fr)) minmax(148px,.9fr) minmax(130px,1fr);gap:8px;align-items:end;border:1px solid #e5edf6;border-radius:16px;padding:10px;background:#fbfdff}.product-opcfop-row label{font-size:11px;min-width:0}.product-opcfop-use{display:grid;gap:4px;font-weight:850;color:#0f172a}.product-opcfop-custom{display:flex;gap:6px;align-items:center;font-weight:850;color:#0f172a;min-height:42px}.product-opcfop-title{min-width:0}.product-opcfop-title .product-opcfop-caption{display:block;color:#334155;font-size:11px;font-weight:800;margin-bottom:3px}.product-opcfop-title strong{display:block;color:#0b1f5b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-opcfop-title span:not(.product-opcfop-caption){display:block;color:#64748b;font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.product-opcfop-custom,.product-opcfop-notes{grid-column:auto}.product-opcfop-notes{display:grid;gap:4px;min-width:0}
@media(max-width:1180px){.product-commercial-line{grid-template-columns:repeat(4,minmax(0,1fr))}.product-fiscal-line-6,.product-fiscal-line-7{gap:7px}.product-opcfop-row{grid-template-columns:58px minmax(150px,1.35fr) repeat(4,minmax(68px,.55fr)) minmax(130px,.85fr) minmax(110px,.9fr);gap:6px}}
@media(max-width:980px){.product-fiscal-grid,.product-fiscal-mini-grid{grid-template-columns:1fr 1fr}.product-fiscal-grid .span-2,.product-fiscal-grid .span-3,.product-fiscal-mini-grid .span-2,.product-fiscal-mini-grid .span-3{grid-column:auto}.product-fiscal-subsection{overflow-x:auto}.product-fiscal-line-5{grid-template-columns:repeat(5,minmax(110px,1fr));min-width:650px}.product-fiscal-line-6{grid-template-columns:repeat(6,minmax(105px,1fr));min-width:690px}.product-fiscal-line-7{grid-template-columns:repeat(7,minmax(105px,1fr));min-width:790px}.product-fiscal-ibs-row-1{grid-template-columns:minmax(110px,.7fr) minmax(170px,1fr) minmax(260px,2fr);min-width:650px}.product-fiscal-ibs-row-2{grid-template-columns:minmax(180px,1.35fr) repeat(3,minmax(100px,1fr));min-width:650px}.product-opcfop-row{grid-template-columns:58px minmax(160px,1.4fr) repeat(4,minmax(72px,.58fr)) minmax(130px,.9fr) minmax(115px,1fr);min-width:930px}.product-opcfop-title,.product-opcfop-custom,.product-opcfop-notes{grid-column:auto}}
@media(max-width:760px){.product-fiscal-grid,.product-fiscal-mini-grid{grid-template-columns:1fr}.product-fiscal-subsection{overflow-x:visible}.product-fiscal-line-5,.product-fiscal-line-6,.product-fiscal-line-7,.product-fiscal-ibs-row-1,.product-fiscal-ibs-row-2,.product-opcfop-row{grid-template-columns:1fr;min-width:0}.product-opcfop-title,.product-opcfop-custom,.product-opcfop-notes{grid-column:auto}}
</style>

<template id="optionGroupTpl">
  <div class="option-admin-group" data-group>
    <input type="hidden" name="option_group_key[]" value="__KEY__">
    <div class="option-admin-head">
      <strong>Grupo de opções</strong>
      <button class="btn btn-sm" type="button" data-remove-group>Remover grupo</button>
    </div>
    <div class="form-grid option-admin-grid">
      <div class="row"><label>Título do grupo</label><input class="field" name="option_group_title[__KEY__]" placeholder="Ex: Escolha a carne"></div>
      <div class="row"><label>Tipo</label><select class="field" name="option_group_type[__KEY__]"><option value="single">Escolha única</option><option value="multiple">Múltipla escolha</option></select></div>
      <div class="row"><label>Obrigatório</label><select class="field" name="option_group_required[__KEY__]"><option value="1">Sim</option><option value="0">Não</option></select></div>
      <div class="row"><label>Mínimo</label><input class="field" type="number" min="0" name="option_group_min[__KEY__]" value="1"></div>
      <div class="row"><label>Máximo</label><input class="field" type="number" min="0" name="option_group_max[__KEY__]" value="1"><div class="help">Use 3 para permitir até 3 opções.</div></div>
      <div class="row"><label>Ordem</label><input class="field" type="number" name="option_group_sort[__KEY__]" value="1"></div>
      <div class="row"><label>Ativo</label><select class="field" name="option_group_active[__KEY__]"><option value="1">Sim</option><option value="0">Não</option></select></div>
    </div>
    <div class="option-items-admin" data-items><div class="help" style="margin:4px 0 8px;">Itens desse grupo</div></div>
    <button class="btn btn-sm" type="button" data-add-item>Adicionar opção</button>
  </div>
</template>

<template id="optionItemTpl">
  <div class="option-admin-item" data-item>
    <input class="field" name="option_item_name[__KEY__][]" placeholder="Nome da opção. Ex: Bife acebolado">
    <input class="field" name="option_item_price[__KEY__][]" placeholder="Acréscimo. Ex: 3,00" inputmode="decimal" value="0,00">
    <input class="field" type="number" name="option_item_sort[__KEY__][]" value="1" placeholder="Ordem">
    <select class="field" name="option_item_active[__KEY__][]"><option value="1">Ativo</option><option value="0">Inativo</option></select>
    <button class="btn btn-sm" type="button" data-remove-item>Remover</button>
  </div>
</template>

<script>
(function(){
  const root = document.getElementById('optionsBuilder');
  const btnAddGroup = document.getElementById('addOptionGroup');
  const btnExample = document.getElementById('addExecutiveExample');
  const groupTpl = document.getElementById('optionGroupTpl');
  const itemTpl = document.getElementById('optionItemTpl');
  if (!root || !btnAddGroup || !groupTpl || !itemTpl) return;

  let counter = Date.now();

  function clearEmptyNote(){
    root.querySelectorAll('.empty-options-note').forEach(el => el.remove());
  }

  function addItem(group, values){
    const key = group.querySelector('input[name="option_group_key[]"]').value;
    const wrap = group.querySelector('[data-items]');
    const html = itemTpl.innerHTML.replaceAll('__KEY__', key);
    const holder = document.createElement('div');
    holder.innerHTML = html.trim();
    const item = holder.firstElementChild;
    if (values) {
      item.querySelector('input[name^="option_item_name"]').value = values.name || '';
      item.querySelector('input[name^="option_item_price"]').value = values.price || '0,00';
      item.querySelector('input[name^="option_item_sort"]').value = values.sort || '1';
    }
    wrap.appendChild(item);
    return item;
  }

  function addGroup(values){
    clearEmptyNote();
    const key = 'new_' + (++counter);
    const html = groupTpl.innerHTML.replaceAll('__KEY__', key);
    const holder = document.createElement('div');
    holder.innerHTML = html.trim();
    const group = holder.firstElementChild;
    root.appendChild(group);

    if (values) {
      group.querySelector('input[name^="option_group_title"]').value = values.title || '';
      group.querySelector('select[name^="option_group_type"]').value = values.type || 'single';
      group.querySelector('select[name^="option_group_required"]').value = values.required ? '1' : '0';
      group.querySelector('input[name^="option_group_min"]').value = String(values.min ?? (values.required ? 1 : 0));
      group.querySelector('input[name^="option_group_max"]').value = String(values.max ?? 1);
      group.querySelector('input[name^="option_group_sort"]').value = String(values.sort ?? 1);
      (values.items || []).forEach((it, idx) => addItem(group, {...it, sort: idx+1}));
    } else {
      addItem(group);
    }
    return group;
  }

  btnAddGroup.addEventListener('click', () => addGroup());

  if (btnExample) {
    btnExample.addEventListener('click', () => {
      addGroup({
        title:'Escolha a carne',
        type:'single',
        required:true,
        min:1,
        max:1,
        sort:1,
        items:[
          {name:'Bife acebolado', price:'0,00'},
          {name:'Filé de frango', price:'0,00'},
          {name:'Contra filé', price:'5,00'}
        ]
      });
      addGroup({
        title:'Escolha até 3 acompanhamentos',
        type:'multiple',
        required:false,
        min:0,
        max:3,
        sort:2,
        items:[
          {name:'Arroz', price:'0,00'},
          {name:'Feijão', price:'0,00'},
          {name:'Batata frita', price:'4,00'},
          {name:'Salada', price:'0,00'}
        ]
      });
    });
  }

  root.addEventListener('click', (ev) => {
    const target = ev.target;
    if (!(target instanceof HTMLElement)) return;
    if (target.matches('[data-remove-group]')) {
      const group = target.closest('[data-group]');
      if (group && confirm('Remover este grupo de opções?')) group.remove();
    }
    if (target.matches('[data-add-item]')) {
      const group = target.closest('[data-group]');
      if (group) addItem(group);
    }
    if (target.matches('[data-remove-item]')) {
      const item = target.closest('[data-item]');
      if (item) item.remove();
    }
  });
})();
</script>


<?php
$fiscalProfilePayloads = [];
if ($fiscalEnabled) {
  foreach ($fiscalProfiles as $tp) {
    $payload = fiscal_profile_payload($tp);
    if (function_exists('fiscal_tax_profile_operation_cfops')) {
      // Envia todas as operações para o navegador (inclusive as marcadas como "Não usar").
      // Assim, ao trocar a tributação no produto, o quadro de Operações/CFOPs é
      // reconstruído integralmente e não mantém valores da tributação anterior.
      try { $payload['operation_cfops'] = fiscal_tax_profile_operation_cfops($pdo, (int)$productCompanyId, (int)$tp['id'], true); } catch (Throwable $e) { $payload['operation_cfops'] = []; }
    }
    $fiscalProfilePayloads[(string)$tp['id']] = $payload;
  }
}
?>
<script>
(function(){
  const cost = document.getElementById('productCostField');
  const markup = document.getElementById('productMarkupField');
  const price = document.getElementById('productPriceField');
  if (!cost || !markup || !price) return;

  let lastEdited = 'price';
  let internalChange = false;

  function parseBrNumber(value){
    let raw = String(value ?? '').trim().replace(/R\$/gi, '').replace(/\s+/g, '');
    if (!raw) return 0;
    if (raw.includes(',')) raw = raw.replace(/\./g, '').replace(',', '.');
    raw = raw.replace(/[^0-9.+-]/g, '');
    const n = Number(raw);
    return Number.isFinite(n) ? n : 0;
  }
  function formatPercent(value){
    if (!Number.isFinite(value)) return '';
    return value.toFixed(2).replace('.', ',');
  }
  function formatMoney(value){
    if (!Number.isFinite(value)) return '';
    return value.toFixed(2).replace('.', ',');
  }
  function calculateMarkup(){
    const c = parseBrNumber(cost.value);
    const p = parseBrNumber(price.value);
    if (c <= 0) { markup.value = ''; return; }
    markup.value = formatPercent(((p - c) / c) * 100);
  }
  function calculatePrice(){
    const c = parseBrNumber(cost.value);
    const m = parseBrNumber(markup.value);
    if (c < 0) return;
    internalChange = true;
    price.value = formatMoney(c * (1 + (m / 100)));
    price.dispatchEvent(new Event('change', {bubbles:true}));
    internalChange = false;
  }

  markup.addEventListener('input', function(){ lastEdited = 'markup'; calculatePrice(); });
  markup.addEventListener('change', function(){ lastEdited = 'markup'; calculatePrice(); });
  price.addEventListener('input', function(){ if (internalChange) return; lastEdited = 'price'; calculateMarkup(); });
  price.addEventListener('change', function(){ if (internalChange) return; lastEdited = 'price'; calculateMarkup(); });
  cost.addEventListener('input', function(){ lastEdited === 'markup' ? calculatePrice() : calculateMarkup(); });
  cost.addEventListener('change', function(){ lastEdited === 'markup' ? calculatePrice() : calculateMarkup(); });

  calculateMarkup();
})();
</script>

<?php if ($fiscalEnabled): ?>
<script>
(function(){
  const profiles = <?= json_encode($fiscalProfilePayloads, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const select = document.getElementById('fiscalTaxProfile');
  const serviceToggle = document.getElementById('productIsService');
  const itemTypeInput = document.getElementById('fiscalItemType');
  const typeLabel = document.getElementById('fiscalItemTypeLabel');
  const typeHelp = document.getElementById('fiscalItemTypeHelp');
  const ibsCbsClasses = <?= json_encode(array_values($fiscalIbsCbsClasses ?? []), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const ibsCbsCode = document.getElementById('fiscalIbsCbsClassificationCode');
  const ibsCbsName = document.getElementById('fiscalIbsCbsClassificationName');
  if (!select) return;

  function isTruthy(value){
    return value === true || value === 1 || value === '1' || String(value || '').toLowerCase() === 'true';
  }
  function setField(key, value){
    document.querySelectorAll('[data-fiscal="' + key + '"]').forEach(function(el){
      if (el.type === 'checkbox') el.checked = isTruthy(value);
      else el.value = value ?? '';
    });
  }
  function currentType(){ return serviceToggle && serviceToggle.checked ? 'SERVICE' : 'PRODUCT'; }
  function refreshItemType(clearIncompatible){
    const type = currentType();
    if (itemTypeInput) itemTypeInput.value = type;
    if (typeLabel) typeLabel.textContent = type === 'SERVICE' ? 'Serviço / NFS-e' : 'Produto / NF-e e NFC-e';
    if (typeHelp) typeHelp.textContent = type === 'SERVICE' ? 'NBS, LC 116, ISS, IBS/CBS, retenções e dados da NFS-e Nacional.' : 'NCM, CEST, ICMS, IPI, PIS/COFINS e CFOPs.';
    document.querySelectorAll('[data-item-kind]').forEach(function(el){
      el.style.display = el.getAttribute('data-item-kind') === type ? '' : 'none';
    });
    Array.from(select.options).forEach(function(option){
      const optionType = option.getAttribute('data-profile-type');
      option.hidden = !!optionType && optionType !== type;
      option.disabled = !!optionType && optionType !== type;
    });
    const selected = select.options[select.selectedIndex];
    if (selected && selected.getAttribute('data-profile-type') && selected.getAttribute('data-profile-type') !== type && clearIncompatible) {
      select.value = '';
    }
    const taxMode = document.querySelector('[data-fiscal="tax_mode"]');
    if (taxMode) {
      if (type === 'SERVICE') taxMode.value = 'ISS';
      else if (taxMode.value === 'ISS') taxMode.value = 'TRIBUTADO';
    }
  }

  function syncIbsCbsDescription(clearWhenMissing){
    if (!ibsCbsCode || !ibsCbsName) return;
    const code = String(ibsCbsCode.value || '').replace(/\D+/g, '');
    if (!code) { ibsCbsName.value = ''; return; }
    const found = ibsCbsClasses.find(function(item){
      return String(item.classification_code || '').replace(/\D+/g, '') === code;
    });
    if (found) ibsCbsName.value = String(found.description || '');
    else if (clearWhenMissing) ibsCbsName.value = '';
  }
  if (ibsCbsCode) {
    ['input','change','blur'].forEach(function(eventName){
      ibsCbsCode.addEventListener(eventName, function(){ syncIbsCbsDescription(true); });
    });
    syncIbsCbsDescription(false);
  }

  function applyProfile(p){
    if (!p) return;
    const profileType = String(p.profile_type || 'PRODUCT').toUpperCase();
    if (profileType !== currentType()) return;
    [
      'tax_mode','nbs_code','nbs_description','lc116_service_code','lc116_service_description',
      'iss_rate','iss_withheld','pis_cofins_retention_type','irrf_rate','csll_rate','inss_rate','municipal_tax_code','cnae','fiscal_incentive',
      'nfse_municipal_tax_type','nfse_municipal_tax_nature','nfse_municipal_iss_exigibility','nfse_municipal_special_regime',
      'nfse_national_tax_type','nfse_national_tax_code','nfse_national_operation_indicator','nfse_simples_tax_regime','nfse_operation_payment_type','nfse_rtc_special_regime',
      'municipal_benefit_type','municipal_differentiated_rate','municipal_reduction_base_percent','municipal_benefit_number',
      'ibs_cbs_cst','ibs_cbs_rate_mode','cbs_rate','ibs_uf_rate','ibs_mun_rate','ibs_cbs_classification_code','ibs_cbs_classification_name',
      'icms_rate','icms_st_rate','ipi_rate','ipi_cst','icms_cst','icms_csosn','pis_out_rate','pis_out_cst','cofins_out_rate','cofins_out_cst',
      'cfop_sale_state','cfop_sale_interstate','cfop_return_state','cfop_return_interstate','cfop_entry_state','cfop_entry_interstate'
    ].forEach(function(key){
      if (Object.prototype.hasOwnProperty.call(p, key)) setField(key, p[key]);
    });
    if (profileType === 'SERVICE') setField('tax_mode', 'ISS');
    syncIbsCbsDescription(false);
    if (Array.isArray(p.operation_cfops) && profileType === 'PRODUCT') {
      // A tributação selecionada passa a ser a fonte do quadro inteiro de
      // Operações e CFOPs. Isso evita manter CFOPs/flags da regra anterior.
      const byOperation = {};
      p.operation_cfops.forEach(function(op){
        const opId = String(op.operation_id || '');
        if (opId) byOperation[opId] = op;
      });

      document.querySelectorAll('[data-product-operation]').forEach(function(row){
        const opId = String(row.getAttribute('data-product-operation') || '');
        if (!opId) return;
        const op = byOperation[opId] || null;
        const isEnabled = !!(op && isTruthy(op.enabled));

        ['cfop_in_state','cfop_in_interstate','cfop_out_state','cfop_out_interstate','notes'].forEach(function(key){
          const el = row.querySelector('[data-op-cfop="' + key + '"][data-operation-id="' + opId + '"]');
          if (el) el.value = op ? (op[key] || '') : '';
        });

        const enabled = row.querySelector('[data-operation-use-hidden="' + opId + '"]');
        const enabledSelect = row.querySelector('[data-operation-use-select="' + opId + '"]');
        const custom = row.querySelector('input[name="product_operation_custom[' + opId + ']"]');
        if (enabled) enabled.checked = isEnabled;
        if (enabledSelect) enabledSelect.value = isEnabled ? '1' : '0';
        // Ao escolher outra tributação, volta a herdar a configuração da regra.
        if (custom) custom.checked = false;
      });
    }
  }

  select.addEventListener('change', function(){
    const p = profiles[String(this.value || '')];
    if (p) applyProfile(p);
  });
  if (serviceToggle) serviceToggle.addEventListener('change', function(){ refreshItemType(true); });
  refreshItemType(false);

  function productFiscalLookupUrl(params){
    const url = new URL('fiscal_lookup.php', window.location.href);
    const current = new URL(window.location.href);
    const token = current.searchParams.get('mc_ctx');
    if (token) url.searchParams.set('mc_ctx', token);
    Object.keys(params || {}).forEach(function(key){ url.searchParams.set(key, String(params[key] ?? '')); });
    return url.toString();
  }

  const ncmInput = document.getElementById('fiscalNcmInput');
  const cestInput = document.getElementById('fiscalCestInput');
  const cestList = document.getElementById('fiscalCestList');
  const ncmHelp = document.getElementById('fiscalNcmHelp');
  const allCestOptions = cestList ? cestList.innerHTML : '';
  function refreshCestOptions(cests){
    if (!cestList) return;
    if (!Array.isArray(cests) || !cests.length) { cestList.innerHTML = allCestOptions; return; }
    cestList.innerHTML = '';
    cests.forEach(function(c){
      const op = document.createElement('option');
      op.value = c.cest || '';
      op.textContent = ((c.description || '') + (c.segment_name ? ' · ' + c.segment_name : '')).trim();
      cestList.appendChild(op);
    });
    if (cestInput && cests.length === 1 && !cestInput.value) cestInput.value = cests[0].cest || '';
  }
  if (ncmInput) {
    ncmInput.addEventListener('blur', async function(){
      if (currentType() !== 'PRODUCT') return;
      const ncm = (this.value || '').replace(/\D+/g, '');
      if (!ncm) { refreshCestOptions([]); return; }
      try {
        const resp = await fetch(productFiscalLookupUrl({type:'ncm', ncm:ncm}));
        const json = await resp.json();
        if (!json.ok || !json.data) { refreshCestOptions([]); return; }
        const d = json.data;
        refreshCestOptions(d.cests || []);
        if (cestInput && !cestInput.value && d.cest) cestInput.value = d.cest;
        if (ncmHelp) {
          const qtd = Array.isArray(d.cests) && d.cests.length ? ' · ' + d.cests.length + ' CEST vinculado(s)' : '';
          ncmHelp.textContent = (d.description || '') + (d.ibpt_version ? ' · IBPT ' + d.ibpt_version : '') + qtd;
        }
      } catch(e) {}
    });
    if (ncmInput.value && currentType() === 'PRODUCT') ncmInput.dispatchEvent(new Event('blur'));
  }

  const nbsInput = document.getElementById('fiscalNbsInput');
  const nbsDescription = document.getElementById('fiscalNbsDescription');
  const nbsHelp = document.getElementById('fiscalNbsHelp');
  if (nbsInput) {
    nbsInput.addEventListener('blur', async function(){
      if (currentType() !== 'SERVICE') return;
      const code = (this.value || '').trim();
      if (!code) return;
      try {
        const resp = await fetch(productFiscalLookupUrl({type:'nbs', code:code}));
        const json = await resp.json();
        if (!json.ok || !json.data) return;
        if (nbsDescription && !nbsDescription.value) nbsDescription.value = json.data.description || '';
        if (nbsHelp) nbsHelp.textContent = json.data.description || '';
      } catch(e) {}
    });
    if (nbsInput.value && currentType() === 'SERVICE') nbsInput.dispatchEvent(new Event('blur'));
  }
})();
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>

<?php if ($technicalConfigEnabled): ?>
<script>
(function(){
  const compositionRegisterUrl = <?= json_encode(function_exists('multi_company_context_url') ? multi_company_context_url('produto_form.php') : 'produto_form.php', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const isRaw = document.getElementById('productIsRawMaterial');
  const hasComposition = document.getElementById('productHasComposition');
  const hasProduction = document.getElementById('productHasProduction');
  const productionHelp = document.getElementById('productProductionHelp');
  const builder = document.getElementById('productCompositionBuilder');
  const rows = document.getElementById('compositionRows');
  const add = document.getElementById('addCompositionRow');
  const tpl = document.getElementById('compositionRowTemplate');
  function qtyLabel(value){
    const n = Number(value || 0);
    if (!Number.isFinite(n)) return '0';
    return n.toLocaleString('pt-BR',{maximumFractionDigits:4});
  }
  function updateStock(row){
    const sel=row ? row.querySelector('[data-composition-material]') : null;
    const out=row ? row.querySelector('[data-composition-stock]') : null;
    if(!sel||!out)return;
    const opt=sel.options[sel.selectedIndex];
    if(!opt||!opt.value){out.textContent='—';return;}
    out.textContent=qtyLabel(opt.dataset.stock)+' '+String(opt.dataset.unit||'UN');
  }
  function bindRow(row){
    const sel=row.querySelector('[data-composition-material]');
    const rem=row.querySelector('[data-remove-composition-row]');
    if(sel){
      sel.addEventListener('change',function(){updateStock(row);});
      if(window.NexaBuscarF8) window.NexaBuscarF8.enhanceSelect(sel,{title:'Buscar matéria-prima',placeholder:'Selecione a matéria-prima',registerUrl:compositionRegisterUrl,registerLabel:'+ Cadastrar produto'});
      updateStock(row);
    }
    if(rem) rem.addEventListener('click',function(){
      const all=rows ? rows.querySelectorAll('[data-composition-row]') : [];
      if(all.length<=1){row.querySelectorAll('input').forEach(i=>i.value='');if(sel){sel.value='';sel.dispatchEvent(new Event('change',{bubbles:true}));}updateStock(row);return;}
      row.remove();
    });
  }
  function syncFlags(source){
    if(source==='raw' && isRaw && isRaw.checked){if(hasComposition)hasComposition.checked=false;if(hasProduction)hasProduction.checked=false;}
    if(source==='composition' && hasComposition && hasComposition.checked){if(isRaw)isRaw.checked=false;if(hasProduction)hasProduction.checked=false;}
    if(source==='production' && hasProduction && hasProduction.checked){if(isRaw)isRaw.checked=false;if(hasComposition)hasComposition.checked=false;}
    if(builder && hasComposition) builder.hidden=!hasComposition.checked;
    if(productionHelp && hasProduction) productionHelp.hidden=!hasProduction.checked;
  }
  if(isRaw) isRaw.addEventListener('change',function(){syncFlags('raw');});
  if(hasComposition) hasComposition.addEventListener('change',function(){syncFlags('composition');});
  if(hasProduction) hasProduction.addEventListener('change',function(){syncFlags('production');});
  if(add&&rows&&tpl) add.addEventListener('click',function(){
    const frag=tpl.content.cloneNode(true); const row=frag.querySelector('[data-composition-row]'); rows.appendChild(frag); if(row)bindRow(row);
  });
  if(rows) rows.querySelectorAll('[data-composition-row]').forEach(bindRow);
  syncFlags('');
})();
</script>
<?php endif; ?>

<script>
(function(){
  const lookupModal = document.getElementById('productLookupModal');
  const lookupSearch = document.getElementById('productLookupSearch');
  const lookupResults = document.getElementById('productLookupResults');
  const lookupCount = document.getElementById('productLookupCount');
  const lookupTitle = document.getElementById('productLookupTitle');
  const catInput = document.getElementById('catInput');
  const productionInput = document.getElementById('productionGroupInput');
  const catButton = document.getElementById('openCategoryLookup');
  const productionButton = document.getElementById('openProductionLookup');
  const categorySettings = <?= json_encode($catSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const lookupData = {
    category: <?= json_encode(array_values($cats), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    production: <?= json_encode(array_values($productionGroups), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
  let lookupType = 'category';
  let lastLookupType = 'category';
  let visibleItems = [];
  let activeIndex = -1;

  document.querySelectorAll('[data-operation-use-select]').forEach(function(select){
    const opId = select.getAttribute('data-operation-use-select') || '';
    const hidden = document.querySelector('[data-operation-use-hidden="' + opId + '"]');
    const sync = function(){ if (hidden) hidden.checked = select.value === '1'; };
    select.addEventListener('change', sync);
    sync();
  });

  function normalize(value){
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLowerCase().trim();
  }
  function targetInput(){ return lookupType === 'production' ? productionInput : catInput; }
  function closeModal(modal){
    if (!modal) return;
    modal.hidden = true;
    modal.setAttribute('aria-hidden','true');
  }
  function renderLookup(query){
    if (!lookupResults) return;
    const all = Array.isArray(lookupData[lookupType]) ? lookupData[lookupType] : [];
    const q = normalize(query);
    visibleItems = q ? all.filter(item => normalize(item).includes(q)) : all.slice();
    activeIndex = visibleItems.length ? 0 : -1;
    lookupResults.innerHTML = '';
    if (!visibleItems.length) {
      const empty = document.createElement('div');
      empty.className = 'product-lookup-empty';
      empty.textContent = q ? 'Nenhum registro encontrado. Você ainda pode digitar um novo valor diretamente no campo.' : 'Nenhum registro cadastrado.';
      lookupResults.appendChild(empty);
    } else {
      visibleItems.forEach(function(item, index){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'product-lookup-result' + (index === activeIndex ? ' is-active' : '');
        btn.setAttribute('role','option');
        btn.dataset.index = String(index);
        const strong = document.createElement('strong');
        strong.textContent = item;
        btn.appendChild(strong);
        if (lookupType === 'category') {
          const cfg = categorySettings[item] || {};
          const small = document.createElement('small');
          small.textContent = 'Ordem ' + String(cfg.sort_order || 0) + (<?= $productOrderOptionsEnabled ? 'true' : 'false' ?> && String(cfg.allow_half_pizza || 0) === '1' ? ' · Meio a meio' : '');
          btn.appendChild(small);
        }
        lookupResults.appendChild(btn);
      });
    }
    if (lookupCount) lookupCount.textContent = visibleItems.length + ' registro(s) encontrado(s)';
  }
  function updateActive(next){
    if (!visibleItems.length || !lookupResults) return;
    activeIndex = Math.max(0, Math.min(visibleItems.length - 1, next));
    lookupResults.querySelectorAll('.product-lookup-result').forEach(function(el, idx){
      el.classList.toggle('is-active', idx === activeIndex);
      if (idx === activeIndex) el.scrollIntoView({block:'nearest'});
    });
  }
  function selectActive(index){
    const idx = typeof index === 'number' ? index : activeIndex;
    if (idx < 0 || idx >= visibleItems.length) return;
    const input = targetInput();
    if (input) {
      input.value = visibleItems[idx];
      input.dispatchEvent(new Event('change', {bubbles:true}));
      input.focus();
    }
    closeModal(lookupModal);
  }
  function openLookup(type){
    if (!lookupModal || !lookupSearch || !lookupResults) return;
    lookupType = type === 'production' ? 'production' : 'category';
    lastLookupType = lookupType;
    lookupTitle.textContent = lookupType === 'production' ? 'Buscar grupo de produção' : 'Buscar categoria';
    lookupSearch.value = '';
    lookupModal.hidden = false;
    lookupModal.setAttribute('aria-hidden','false');
    renderLookup('');
    requestAnimationFrame(function(){ lookupSearch.focus(); lookupSearch.select(); });
  }

  if (catButton) catButton.addEventListener('click', function(){ openLookup('category'); });
  if (productionButton) productionButton.addEventListener('click', function(){ openLookup('production'); });
  [catInput, catButton].forEach(function(el){ if (el) el.addEventListener('focus', function(){ lastLookupType='category'; }); });
  [productionInput, productionButton].forEach(function(el){ if (el) el.addEventListener('focus', function(){ lastLookupType='production'; }); });

  if (lookupSearch) lookupSearch.addEventListener('keydown', function(ev){
    if (ev.key === 'Enter') {
      ev.preventDefault();
      renderLookup(lookupSearch.value);
      if (visibleItems.length) { lookupResults.focus(); updateActive(0); }
    } else if (ev.key === 'ArrowDown') {
      ev.preventDefault();
      renderLookup(lookupSearch.value);
      if (visibleItems.length) { lookupResults.focus(); updateActive(0); }
    } else if (ev.key === 'Escape') {
      ev.preventDefault(); closeModal(lookupModal);
    }
  });
  if (lookupResults) {
    lookupResults.addEventListener('click', function(ev){
      const btn = ev.target.closest('.product-lookup-result');
      if (!btn) return;
      selectActive(Number(btn.dataset.index || 0));
    });
    lookupResults.addEventListener('keydown', function(ev){
      if (ev.key === 'ArrowDown') { ev.preventDefault(); updateActive(activeIndex + 1); return; }
      if (ev.key === 'ArrowUp') { ev.preventDefault(); updateActive(activeIndex - 1); return; }
      if (ev.key === 'Enter') { ev.preventDefault(); selectActive(); return; }
      if (ev.key === 'Escape') { ev.preventDefault(); closeModal(lookupModal); return; }
      if (ev.key.length === 1 && !ev.ctrlKey && !ev.metaKey && !ev.altKey) {
        ev.preventDefault();
        lookupSearch.value = ev.key;
        lookupSearch.focus();
      }
    });
  }

  document.addEventListener('keydown', function(ev){
    if (ev.key === 'F8') {
      const active = document.activeElement;
      if (active && active.closest && active.closest('.nexa-f8-wrap')) return;
      const sharedLookup = document.getElementById('nexaF8Backdrop');
      if (sharedLookup && !sharedLookup.hidden) return;
      if (active === productionInput || active === productionButton) lastLookupType = 'production';
      if (active === catInput || active === catButton) lastLookupType = 'category';
      ev.preventDefault();
      openLookup(lastLookupType);
    }
    if (ev.key === 'Escape') {
      document.querySelectorAll('.product-modal-backdrop:not([hidden])').forEach(closeModal);
    }
  });

  document.querySelectorAll('[data-close-product-modal]').forEach(function(btn){
    btn.addEventListener('click', function(){ closeModal(document.getElementById(btn.getAttribute('data-close-product-modal'))); });
  });
  document.querySelectorAll('.product-modal-backdrop').forEach(function(backdrop){
    backdrop.addEventListener('mousedown', function(ev){ if (ev.target === backdrop) closeModal(backdrop); });
  });

  const stockModal = document.getElementById('stockAdjustmentModal');
  const stockOpen = document.getElementById('openStockAdjustment');
  const stockForm = document.getElementById('stockAdjustmentForm');
  const stockCurrentField = document.getElementById('currentStockField');
  const stockCurrent = document.getElementById('stockAdjustmentCurrent');
  const stockNew = document.getElementById('stockAdjustmentNew');
  const stockDiff = document.getElementById('stockAdjustmentDifference');
  const stockError = document.getElementById('stockAdjustmentError');
  const stockSubmit = document.getElementById('stockAdjustmentSubmit');

  function parseQty(value){
    let s = String(value || '').trim().replace(/\s+/g,'');
    if (s.includes(',') && s.includes('.')) s = s.replace(/\./g,'');
    s = s.replace(',','.');
    const n = Number(s);
    return Number.isFinite(n) ? n : null;
  }
  function formatQty(value){
    const n = Number(value || 0);
    return n.toLocaleString('pt-BR', {minimumFractionDigits:0, maximumFractionDigits:4});
  }
  function currentRawStock(){
    if (!stockCurrentField) return 0;
    const raw = stockCurrentField.dataset.stockRaw;
    const parsedRaw = Number(raw);
    if (Number.isFinite(parsedRaw)) return parsedRaw;
    return parseQty(stockCurrentField.value) || 0;
  }
  function refreshDifference(){
    if (!stockNew || !stockDiff) return;
    const next = parseQty(stockNew.value);
    stockDiff.value = next === null ? '—' : formatQty(next - currentRawStock());
  }
  if (stockOpen && stockModal) stockOpen.addEventListener('click', function(){
    if (stockError) { stockError.hidden = true; stockError.textContent = ''; }
    const current = currentRawStock();
    if (stockCurrent) stockCurrent.value = formatQty(current);
    if (stockNew) { stockNew.value = formatQty(current); refreshDifference(); }
    stockModal.hidden = false;
    stockModal.setAttribute('aria-hidden','false');
    requestAnimationFrame(function(){ if (stockNew) { stockNew.focus(); stockNew.select(); } });
  });
  if (stockNew) stockNew.addEventListener('input', refreshDifference);
  if (stockForm) stockForm.addEventListener('submit', async function(ev){
    ev.preventDefault();
    if (stockError) { stockError.hidden = true; stockError.textContent = ''; }
    if (stockSubmit) stockSubmit.disabled = true;
    try {
      const stockActionUrl = new URL('produto_form.php', window.location.href);
      const currentUrl = new URL(window.location.href);
      const currentCtx = currentUrl.searchParams.get('mc_ctx');
      if (currentCtx) stockActionUrl.searchParams.set('mc_ctx', currentCtx);
      const response = await fetch(stockActionUrl.toString(), {method:'POST', body:new FormData(stockForm), headers:{'X-Requested-With':'XMLHttpRequest'}});
      const json = await response.json();
      if (!response.ok || !json.ok) throw new Error(json.message || 'Não foi possível realizar o acerto de estoque.');
      const data = json.data || {};
      if (stockCurrentField) {
        stockCurrentField.value = data.formatted_after || formatQty(data.after || 0);
        stockCurrentField.dataset.stockRaw = String(data.after || 0);
      }
      if (stockCurrent) stockCurrent.value = data.formatted_after || formatQty(data.after || 0);
      closeModal(stockModal);
      let msg = document.getElementById('stockAdjustmentSuccessMessage');
      if (!msg) {
        msg = document.createElement('div');
        msg.id = 'stockAdjustmentSuccessMessage';
        msg.className = 'msg ok';
        msg.style.marginTop = '12px';
        const card = document.querySelector('.card');
        if (card && card.parentNode) card.parentNode.insertBefore(msg, card);
      }
      msg.textContent = 'Estoque ajustado com sucesso. Saldo atual: ' + (data.formatted_after || formatQty(data.after || 0)) + '. Movimentação registrada no histórico.';
    } catch (err) {
      if (stockError) { stockError.textContent = err && err.message ? err.message : 'Não foi possível realizar o acerto.'; stockError.hidden = false; }
    } finally {
      if (stockSubmit) stockSubmit.disabled = false;
    }
  });
})();
</script>

<?php if ($hasCategoryVisibility || $hasCategorySortOrder || $hasCategoryHalfPizza): ?>
<script>
(function(){
  const catInput = document.getElementById('catInput');
  const hideOrder = document.getElementById('hideOrderCatalog');
  const hideProduct = document.getElementById('hideProductCatalog');
  const sortOrder = document.getElementById('categorySortOrderInput');
  const allowHalfPizza = document.getElementById('categoryAllowHalfPizza');
  const settings = <?= json_encode($catSettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  if (!catInput || !settings) return;

  function applyCategorySettings(){
    const cat = (catInput.value || '').trim();
    if (!cat || !Object.prototype.hasOwnProperty.call(settings, cat)) {
      if (allowHalfPizza) allowHalfPizza.checked = false;
      return;
    }
    const cfg = settings[cat] || {};
    if (sortOrder) sortOrder.value = String(cfg.sort_order || 0);
    if (hideOrder) hideOrder.checked = String(cfg.hide_order_catalog || 0) === '1';
    if (hideProduct) hideProduct.checked = String(cfg.hide_product_catalog || 0) === '1';
    if (allowHalfPizza) allowHalfPizza.checked = String(cfg.allow_half_pizza || 0) === '1';
  }

  catInput.addEventListener('change', applyCategorySettings);
  catInput.addEventListener('blur', applyCategorySettings);
})();
</script>
<?php endif; ?>

<?php admin_footer(); ?>
