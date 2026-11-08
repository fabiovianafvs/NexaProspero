<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/stock_module.php';
require_once __DIR__ . '/../config/product_menu_code.php';
require_once __DIR__ . '/../config/product_composition.php';

// V9: Produtos mantém cadastro/fiscal homologados e passa a expor também a
// consulta de saldo/histórico de estoque da empresa selecionada pelo F11.
// Qualquer gravação operacional de estoque continua fora da homologação.
$productsCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'products_write')
  : (int)COMPANY_ID;
$productsCrossContext = $productsCompanyId !== (int)COMPANY_ID;
$productsMultiCompanyEnabled = function_exists('multi_company_feature_enabled')
  ? multi_company_feature_enabled($pdo, (int)COMPANY_ID)
  : false;
$stockReadCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_read')
  : (int)COMPANY_ID;
$stockReadContextReady = $stockReadCompanyId === $productsCompanyId;
$productsCompanyRow = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $productsCompanyId)
  : null;
$productsCompanyName = trim((string)($productsCompanyRow['name'] ?? '')) ?: ('Empresa #' . $productsCompanyId);
$productsHideCost = $productsCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'desativa_preco_custo'))
  : (function_exists('access_product_cost_hidden') ? access_product_cost_hidden($pdo, $productsCompanyId) : false);
if ($productsCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'produtos')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para consultar Produtos.');
}

// V11.45.11: preferências do grid passam a ser persistidas no usuário,
// por empresa, em vez de depender somente do navegador/localStorage.
$productsGridUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
$productsGridPreferenceKey = $productsGridUserId > 0 ? ('ui_products_grid_user_' . $productsGridUserId) : '';

function produtos_admin_grid_csrf_token(): string {
  if (function_exists('auth_start')) auth_start();
  elseif (session_status() !== PHP_SESSION_ACTIVE) session_start();
  if (empty($_SESSION['products_grid_csrf_token']) || !is_string($_SESSION['products_grid_csrf_token'])) {
    $_SESSION['products_grid_csrf_token'] = bin2hex(random_bytes(24));
  }
  return (string)$_SESSION['products_grid_csrf_token'];
}

function produtos_admin_grid_preferences_load(PDO $pdo, int $companyId, string $cfgKey): array {
  if ($companyId <= 0 || $cfgKey === '') return [];
  try {
    $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
    $st->execute([$companyId, $cfgKey]);
    $raw = $st->fetchColumn();
    if (!is_string($raw) || trim($raw) === '') return [];
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : [];
  } catch (Throwable $e) {
    return [];
  }
}

function produtos_admin_grid_preferences_normalize(array $prefs): array {
  $allowed = ['imagem','codigo','produto','categoria','grupo','ordem','preco','custo','estoque','prospero','cardapio','referencia','barras','status'];
  $visible = [];
  if (isset($prefs['visible']) && is_array($prefs['visible'])) {
    foreach ($prefs['visible'] as $col) {
      $col = (string)$col;
      if (in_array($col, $allowed, true) && !in_array($col, $visible, true)) $visible[] = $col;
    }
  }
  $order = [];
  if (isset($prefs['order']) && is_array($prefs['order'])) {
    foreach ($prefs['order'] as $col) {
      $col = (string)$col;
      if (in_array($col, $allowed, true) && !in_array($col, $order, true)) $order[] = $col;
    }
  }
  $widths = [];
  if (isset($prefs['widths']) && is_array($prefs['widths'])) {
    foreach ($prefs['widths'] as $col => $width) {
      $col = (string)$col;
      if (!in_array($col, $allowed, true)) continue;
      $width = (int)$width;
      if ($width >= 50 && $width <= 800) $widths[$col] = $width;
    }
  }
  return ['visible' => $visible, 'order' => $order, 'widths' => $widths];
}

$productsGridCsrf = produtos_admin_grid_csrf_token();

// Endpoint leve de persistência do layout. Não recarrega a listagem de produtos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_grid_preferences') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($productsGridUserId <= 0 || $productsGridPreferenceKey === '') throw new RuntimeException('Usuário não identificado.');
    $csrf = (string)($_POST['csrf_token'] ?? '');
    if ($csrf === '' || !hash_equals($productsGridCsrf, $csrf)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $raw = (string)($_POST['preferences'] ?? '');
    $decoded = json_decode($raw, true);
    if (!is_array($decoded)) throw new RuntimeException('Preferências inválidas.');
    $normalized = produtos_admin_grid_preferences_normalize($decoded);
    $json = json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false) throw new RuntimeException('Não foi possível serializar as preferências.');
    $st = $pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json), updated_at=NOW()');
    $st->execute([$productsCompanyId, $productsGridPreferenceKey, $json]);
    echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

$productsGridUserPreferences = produtos_admin_grid_preferences_normalize(
  produtos_admin_grid_preferences_load($pdo, $productsCompanyId, $productsGridPreferenceKey)
);
$productsGridHasServerPreferences = !empty($productsGridUserPreferences['visible']) || !empty($productsGridUserPreferences['order']) || !empty($productsGridUserPreferences['widths']);

company_sequence_ensure_schema($pdo, true);
$compositionEnabled = false;
try {
  $compositionEnabled = product_composition_enabled($pdo, $productsCompanyId);
  if ($compositionEnabled) {
    product_composition_ensure_schema($pdo);
    // Em consulta cruzada não executa recálculo/escrita. Usa somente os valores já gravados.
    if (!$productsCrossContext) {
      product_composition_recalculate_all($pdo, $productsCompanyId, 'Listagem de produtos');
    }
  }
} catch (Throwable $e) { $compositionEnabled = false; }


$hasCodigoFirebird = false;
$hasCodigoFirebirdMap = false;
try {
  $qCol = $pdo->query("SHOW COLUMNS FROM produtos LIKE 'codigo_firebird'");
  $hasCodigoFirebird = $qCol && (bool)$qCol->fetch();
} catch (Throwable $e) {
  try {
    $qCol = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'produtos' AND COLUMN_NAME = 'codigo_firebird' LIMIT 1");
    $qCol->execute();
    $hasCodigoFirebird = (bool)$qCol->fetchColumn();
  } catch (Throwable $e2) {
    $hasCodigoFirebird = false;
  }
}
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
  $hasCodigoFirebirdMap = true;
} catch (Throwable $e) {
  $hasCodigoFirebirdMap = false;
}

function money_pt(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

function hstr($v): string {
  return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
}

function produtos_admin_has_column(PDO $pdo, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->execute(['produtos', $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    try {
      $st = $pdo->query("SHOW COLUMNS FROM produtos LIKE " . $pdo->quote($column));
      return $st && (bool)$st->fetch();
    } catch (Throwable $e2) {
      return false;
    }
  }
}


function produtos_admin_barcode_columns(PDO $pdo): array {
  if (!$pdo->inTransaction() && !produtos_admin_has_column($pdo, 'codigo_barras')) {
    try { $pdo->exec('ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL AFTER codigo_firebird'); } catch (Throwable $e) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN codigo_barras VARCHAR(80) NULL'); } catch (Throwable $e2) {}
    }
  }
  $cols = [];
  foreach (['codigo_barras','ean','gtin','barcode'] as $col) {
    if (produtos_admin_has_column($pdo, $col)) $cols[] = $col;
  }
  return array_values(array_unique($cols));
}

function produtos_admin_ensure_order_columns(PDO $pdo): array {
  $cols = [
    'sort_order' => produtos_admin_has_column($pdo, 'sort_order'),
    'category_sort_order' => produtos_admin_has_column($pdo, 'category_sort_order'),
    'grupo_producao' => produtos_admin_has_column($pdo, 'grupo_producao'),
  ];
  if (!$pdo->inTransaction()) {
    if (!$cols['sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {}
      $cols['sort_order'] = produtos_admin_has_column($pdo, 'sort_order');
    }
    if (!$cols['category_sort_order']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_sort_order INT NOT NULL DEFAULT 0 AFTER sort_order'); } catch (Throwable $e) {}
      $cols['category_sort_order'] = produtos_admin_has_column($pdo, 'category_sort_order');
    }
    if (!$cols['grupo_producao']) {
      try { $pdo->exec('ALTER TABLE produtos ADD COLUMN grupo_producao VARCHAR(120) NULL AFTER categoria'); } catch (Throwable $e) {}
      $cols['grupo_producao'] = produtos_admin_has_column($pdo, 'grupo_producao');
    }
  }
  return $cols;
}

$q = trim((string)($_GET['q'] ?? ''));
$categoriaFiltro = trim((string)($_GET['categoria'] ?? ''));
$grupoFiltro = trim((string)($_GET['grupo_producao'] ?? ''));
$statusFiltro = trim((string)($_GET['status'] ?? ''));

// V11.45.11: grade leve, colunas redimensionáveis/reordenáveis e layout persistente por usuário.
// A tela abre sem consultar produtos e só executa
// a pesquisa quando o usuário informar uma busca ou um filtro.
$gridFilters = [
  'codigo' => trim((string)($_GET['f_codigo'] ?? '')),
  'produto' => trim((string)($_GET['f_produto'] ?? '')),
  'categoria' => trim((string)($_GET['f_categoria'] ?? '')),
  'grupo' => trim((string)($_GET['f_grupo'] ?? '')),
  'ordem' => trim((string)($_GET['f_ordem'] ?? '')),
  'preco' => trim((string)($_GET['f_preco'] ?? '')),
  'custo' => trim((string)($_GET['f_custo'] ?? '')),
  'estoque' => trim((string)($_GET['f_estoque'] ?? '')),
  'prospero' => trim((string)($_GET['f_prospero'] ?? '')),
  'cardapio' => trim((string)($_GET['f_cardapio'] ?? '')),
  'referencia' => trim((string)($_GET['f_referencia'] ?? '')),
  'barras' => trim((string)($_GET['f_barras'] ?? '')),
  'status' => trim((string)($_GET['f_status'] ?? '')),
];
$sortKey = trim((string)($_GET['sort'] ?? ''));
$sortDir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'desc' : 'asc';

function produtos_admin_query_url(array $changes = []): string {
  $params = $_GET;
  foreach ($changes as $key => $value) {
    if ($value === null || $value === '') unset($params[$key]);
    else $params[$key] = $value;
  }
  $query = http_build_query($params);
  return 'produtos.php' . ($query !== '' ? ('?' . $query) : '');
}

function produtos_admin_sort_url(string $column, string $currentKey, string $currentDir): string {
  $nextDir = ($currentKey === $column && $currentDir === 'asc') ? 'desc' : 'asc';
  return produtos_admin_query_url(['sort' => $column, 'dir' => $nextDir]);
}

function produtos_admin_sort_mark(string $column, string $currentKey, string $currentDir): string {
  if ($currentKey !== $column) return '↕';
  return $currentDir === 'desc' ? '↓' : '↑';
}

function produtos_admin_grid_filter_menu(string $field, string $label, string $value, string $kind = 'text'): string {
  $active = trim($value) !== '';
  $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
  $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  $valueEsc = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  $activeClass = $active ? ' has-filter' : '';
  $activeTitle = $active ? ' (filtro ativo)' : '';
  $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.3l-3.6 1.8v-7.1L4 5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';

  if ($kind === 'status') {
    $allSel = $value === '' ? ' selected' : '';
    $activeSel = $value === 'ativo' ? ' selected' : '';
    $inactiveSel = $value === 'inativo' ? ' selected' : '';
    $control = '<select form="productsSearchForm" class="grid-filter-input" name="' . $fieldEsc . '">' .
      '<option value=""' . $allSel . '>Todos</option>' .
      '<option value="ativo"' . $activeSel . '>Ativo</option>' .
      '<option value="inativo"' . $inactiveSel . '>Inativo</option>' .
      '</select>';
  } else {
    $inputmode = $kind === 'number' ? ' inputmode="decimal"' : '';
    $control = '<input form="productsSearchForm" class="grid-filter-input" name="' . $fieldEsc . '" value="' . $valueEsc . '" placeholder="Digite para filtrar"' . $inputmode . ' autocomplete="off">';
  }

  return '<details class="grid-filter-menu' . $activeClass . '" data-grid-filter-menu>' .
    '<summary class="grid-filter-trigger" title="Filtrar ' . $labelEsc . $activeTitle . '" aria-label="Filtrar ' . $labelEsc . '">' . $icon . '</summary>' .
    '<div class="grid-filter-popover">' .
      '<div class="grid-filter-title">Filtrar ' . $labelEsc . '</div>' .
      $control .
      '<div class="grid-filter-actions">' .
        '<button class="btn btn-primary grid-filter-apply" type="submit" form="productsSearchForm">Aplicar</button>' .
        '<button class="btn grid-filter-clear" type="button" data-clear-filter="' . $fieldEsc . '">Limpar</button>' .
      '</div>' .
    '</div>' .
  '</details>';
}

$cats = [];
$orderColumns = produtos_admin_ensure_order_columns($pdo);
$barcodeColumns = produtos_admin_barcode_columns($pdo);
$hasCodigoCardapio = product_menu_code_ensure_schema($pdo, true);
$hasServiceProductColumn = produtos_admin_has_column($pdo, 'is_service');
$hasReferencia = produtos_admin_has_column($pdo, 'referencia');
$hasCostColumn = produtos_admin_has_column($pdo, 'custo');
$showProductCost = $hasCostColumn && !$productsHideCost;
try {
  if ($productsCrossContext) {
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
$hasStockControl = $stockReadContextReady && !empty($stockColumns['estoque_atual']);
$hasProductSortOrder = !empty($orderColumns['sort_order']);
$hasCategorySortOrder = !empty($orderColumns['category_sort_order']);
$hasProductionGroup = !empty($orderColumns['grupo_producao']);
try {
  $catOrderSql = $hasCategorySortOrder
    ? "ORDER BY CASE WHEN COALESCE(MIN(category_sort_order),0) <= 0 THEN 999999 ELSE MIN(category_sort_order) END ASC, categoria ASC"
    : "ORDER BY categoria ASC";
  $qCats = $pdo->prepare("SELECT categoria FROM produtos WHERE company_id = ? AND categoria IS NOT NULL AND categoria <> '' GROUP BY categoria {$catOrderSql}");
  $qCats->execute([$productsCompanyId]);
  $cats = $qCats ? $qCats->fetchAll(PDO::FETCH_COLUMN) : [];
} catch (Throwable $e) {
  $cats = [];
}

$productionGroups = [];
if ($hasProductionGroup) {
  try {
    $qGroups = $pdo->prepare("SELECT grupo_producao FROM produtos WHERE company_id = ? AND grupo_producao IS NOT NULL AND grupo_producao <> '' GROUP BY grupo_producao ORDER BY grupo_producao ASC");
    $qGroups->execute([$productsCompanyId]);
    $productionGroups = $qGroups ? $qGroups->fetchAll(PDO::FETCH_COLUMN) : [];
  } catch (Throwable $e) {
    $productionGroups = [];
  }
}

$hasProdutoNumero = company_sequence_has_column($pdo, 'produtos');
$numeroExpr = $hasProdutoNumero ? 'p.numero_cadastro' : 'NULL';

$codigoExpr = "''";
if ($hasCodigoFirebird && $hasCodigoFirebirdMap) {
  $codigoExpr = "COALESCE(NULLIF(TRIM(p.codigo_firebird), ''), m.codigo_firebird, '')";
} elseif ($hasCodigoFirebird) {
  $codigoExpr = "COALESCE(NULLIF(TRIM(p.codigo_firebird), ''), '')";
} elseif ($hasCodigoFirebirdMap) {
  $codigoExpr = "COALESCE(m.codigo_firebird, '')";
}
$barcodeExpr = "''";
if (!empty($barcodeColumns)) {
  $bcParts = [];
  foreach ($barcodeColumns as $bcCol) $bcParts[] = "NULLIF(TRIM(p.`" . str_replace('`', '``', $bcCol) . "`), '')";
  $barcodeExpr = 'COALESCE(' . implode(', ', $bcParts) . ", '')";
}

$sortSelect = ($hasProductSortOrder ? ', COALESCE(p.sort_order,0) AS sort_order' : ', 0 AS sort_order')
  . ($hasCategorySortOrder ? ', COALESCE(p.category_sort_order,0) AS category_sort_order' : ', 0 AS category_sort_order');
$serviceSelect = $hasServiceProductColumn ? ', COALESCE(p.is_service,0) AS is_service' : ', 0 AS is_service';
$grupoSelect = $hasProductionGroup ? 'p.grupo_producao' : "''";
$stockSelect = '';
if ($hasStockControl) {
  $openStatusesSql = "'" . implode("','", array_map('addslashes', stock_open_order_statuses())) . "'";
  $stockSelect = ", COALESCE(p.unidade_estoque,'UN') AS unidade_estoque, COALESCE(p.estoque_atual,0) AS estoque_atual, COALESCE(p.estoque_minimo,0) AS estoque_minimo, COALESCE(p.estoque_maximo,0) AS estoque_maximo, COALESCE(p.estoque_reservado,0) AS estoque_reservado_manual, (SELECT COALESCE(SUM(pi.qtd),0) FROM pedido_itens pi INNER JOIN pedidos pe ON pe.id = pi.pedido_id AND pe.company_id = pi.company_id WHERE pi.company_id = p.company_id AND pi.produto_id = p.id AND pe.status IN ({$openStatusesSql})) AS estoque_reservado_pedidos";
}
$menuCodeSelect = $hasCodigoCardapio ? ", COALESCE(p.codigo_cardapio,'') AS codigo_cardapio" : ", '' AS codigo_cardapio";
$referenceSelect = $hasReferencia ? ", COALESCE(p.referencia,'') AS referencia" : ", '' AS referencia";
$costSelect = $showProductCost ? ", COALESCE(p.custo,0) AS custo" : "";
$compositionSelect = $compositionEnabled
  ? ", COALESCE(p.is_materia_prima,0) AS is_materia_prima, COALESCE(p.possui_composicao,0) AS possui_composicao, COALESCE(p.estoque_composicao_individual,0) AS estoque_composicao_individual, COALESCE(p.estoque_composicao_calculado,0) AS estoque_composicao_calculado"
  : ", 0 AS is_materia_prima, 0 AS possui_composicao, 0 AS estoque_composicao_individual, 0 AS estoque_composicao_calculado";
$select = "SELECT p.id, {$numeroExpr} AS numero_cadastro, p.nome, p.descricao, p.categoria, {$grupoSelect} AS grupo_producao, p.preco, p.ativo, p.imagem, {$codigoExpr} AS codigo_firebird, {$barcodeExpr} AS codigo_barras{$menuCodeSelect}{$referenceSelect}{$costSelect}{$sortSelect}{$serviceSelect}{$stockSelect}{$compositionSelect} FROM produtos p";
$join = $hasCodigoFirebirdMap ? " LEFT JOIN produtos_firebird_map m ON m.company_id = p.company_id AND m.produto_id = p.id" : "";
$where = ["p.company_id = ?"];
$params = [$productsCompanyId];

$searchRequested = $q !== '' || $categoriaFiltro !== '' || $grupoFiltro !== '' || $statusFiltro !== '';
foreach ($gridFilters as $gridFilterValue) {
  if ($gridFilterValue !== '') { $searchRequested = true; break; }
}

if ($q !== '') {
  $like = nexa_search_like($q);
  $parts = [
    "p.nome LIKE ?",
    "p.descricao LIKE ?",
    "p.categoria LIKE ?",
  ];
  array_push($params, $like, $like, $like);
  if ($hasProductionGroup) {
    $parts[] = "p.grupo_producao LIKE ?";
    $params[] = $like;
  }
  if ($hasProdutoNumero) {
    $parts[] = "CAST(p.numero_cadastro AS CHAR) LIKE ?";
    $params[] = $like;
  }
  if ($hasCodigoFirebird || $hasCodigoFirebirdMap) {
    $parts[] = "{$codigoExpr} LIKE ?";
    $params[] = $like;
  }
  if (!empty($barcodeColumns)) {
    $parts[] = "{$barcodeExpr} LIKE ?";
    $params[] = $like;
  }
  if ($hasCodigoCardapio) {
    $parts[] = "p.codigo_cardapio LIKE ?";
    $params[] = $like;
  }
  if ($hasReferencia) {
    $parts[] = "p.referencia LIKE ?";
    $params[] = $like;
  }
  $where[] = '(' . implode(' OR ', $parts) . ')';
}

if ($categoriaFiltro !== '') {
  $where[] = "p.categoria = ?";
  $params[] = $categoriaFiltro;
}
if ($hasProductionGroup && $grupoFiltro !== '') {
  $where[] = "p.grupo_producao = ?";
  $params[] = $grupoFiltro;
}
if ($statusFiltro === 'ativo') {
  $where[] = "p.ativo = 1";
} elseif ($statusFiltro === 'inativo') {
  $where[] = "p.ativo = 0";
}

// Filtros por coluna da grade. São aplicados no banco para evitar carregar
// milhares de registros no navegador.
if ($gridFilters['codigo'] !== '' && $hasProdutoNumero) {
  $where[] = "CAST(p.numero_cadastro AS CHAR) LIKE ?";
  $params[] = nexa_search_like($gridFilters['codigo']);
}
if ($gridFilters['produto'] !== '') {
  $where[] = "(p.nome LIKE ? OR p.descricao LIKE ?)";
  $likeGrid = nexa_search_like($gridFilters['produto']);
  $params[] = $likeGrid;
  $params[] = $likeGrid;
}
if ($gridFilters['categoria'] !== '') {
  $where[] = "p.categoria LIKE ?";
  $params[] = nexa_search_like($gridFilters['categoria']);
}
if ($hasProductionGroup && $gridFilters['grupo'] !== '') {
  $where[] = "p.grupo_producao LIKE ?";
  $params[] = nexa_search_like($gridFilters['grupo']);
}
if ($gridFilters['ordem'] !== '' && ($hasProductSortOrder || $hasCategorySortOrder)) {
  $orderLike = nexa_search_like($gridFilters['ordem']);
  $orderParts = [];
  if ($hasCategorySortOrder) { $orderParts[] = "CAST(COALESCE(p.category_sort_order,0) AS CHAR) LIKE ?"; $params[] = $orderLike; }
  if ($hasProductSortOrder) { $orderParts[] = "CAST(COALESCE(p.sort_order,0) AS CHAR) LIKE ?"; $params[] = $orderLike; }
  if ($orderParts) $where[] = '(' . implode(' OR ', $orderParts) . ')';
}
if ($gridFilters['preco'] !== '') {
  $priceSearch = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $gridFilters['preco']));
  if ($priceSearch !== '') {
    $where[] = "CAST(p.preco AS CHAR) LIKE ?";
    $params[] = nexa_search_like($priceSearch);
  }
}
if ($showProductCost && $gridFilters['custo'] !== '') {
  $costSearch = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $gridFilters['custo']));
  if ($costSearch !== '') {
    $where[] = "CAST(COALESCE(p.custo,0) AS CHAR) LIKE ?";
    $params[] = nexa_search_like($costSearch);
  }
}
if ($gridFilters['estoque'] !== '' && $hasStockControl) {
  $stockSearch = str_replace(',', '.', preg_replace('/[^0-9,.-]/', '', $gridFilters['estoque']));
  if ($stockSearch !== '') {
    $where[] = "CAST(COALESCE(p.estoque_atual,0) AS CHAR) LIKE ?";
    $params[] = nexa_search_like($stockSearch);
  }
}
if ($gridFilters['prospero'] !== '' && ($hasCodigoFirebird || $hasCodigoFirebirdMap)) {
  $where[] = "{$codigoExpr} LIKE ?";
  $params[] = nexa_search_like($gridFilters['prospero']);
}
if ($gridFilters['cardapio'] !== '' && $hasCodigoCardapio) {
  $where[] = "p.codigo_cardapio LIKE ?";
  $params[] = nexa_search_like($gridFilters['cardapio']);
}
if ($gridFilters['referencia'] !== '' && $hasReferencia) {
  $where[] = "p.referencia LIKE ?";
  $params[] = nexa_search_like($gridFilters['referencia']);
}
if ($gridFilters['barras'] !== '' && !empty($barcodeColumns)) {
  $where[] = "{$barcodeExpr} LIKE ?";
  $params[] = nexa_search_like($gridFilters['barras']);
}
if ($gridFilters['status'] === 'ativo') {
  $where[] = "p.ativo = 1";
} elseif ($gridFilters['status'] === 'inativo') {
  $where[] = "p.ativo = 0";
}

$catOrderExpr = $hasCategorySortOrder ? "CASE WHEN COALESCE(p.category_sort_order,0) <= 0 THEN 999999 ELSE p.category_sort_order END" : "999999";
$prodOrderExpr = $hasProductSortOrder ? "CASE WHEN COALESCE(p.sort_order,0) <= 0 THEN 999999 ELSE p.sort_order END" : "999999";
$sortMap = [
  'codigo' => $hasProdutoNumero ? 'p.numero_cadastro' : 'p.nome',
  'produto' => 'p.nome',
  'categoria' => 'p.categoria',
  'grupo' => $hasProductionGroup ? 'p.grupo_producao' : 'p.nome',
  'ordem' => $hasProductSortOrder ? 'p.sort_order' : ($hasCategorySortOrder ? 'p.category_sort_order' : 'p.nome'),
  'preco' => 'p.preco',
  'custo' => $showProductCost ? 'p.custo' : 'p.nome',
  'estoque' => $hasStockControl ? 'p.estoque_atual' : 'p.nome',
  'prospero' => ($hasCodigoFirebird || $hasCodigoFirebirdMap) ? $codigoExpr : 'p.nome',
  'cardapio' => $hasCodigoCardapio ? 'p.codigo_cardapio' : 'p.nome',
  'referencia' => $hasReferencia ? 'p.referencia' : 'p.nome',
  'barras' => !empty($barcodeColumns) ? $barcodeExpr : 'p.nome',
  'status' => 'p.ativo',
];

$rows = [];
$hasMoreRows = false;
if ($searchRequested) {
  if ($sortKey !== '' && isset($sortMap[$sortKey])) {
    $orderSql = $sortMap[$sortKey] . ' ' . strtoupper($sortDir) . ', p.nome ASC, p.id ASC';
  } else {
    $orderSql = "{$catOrderExpr} ASC, p.categoria ASC, {$prodOrderExpr} ASC, p.nome ASC, p.id ASC";
  }
  $sql = $select . $join . " WHERE " . implode(' AND ', $where) . " ORDER BY {$orderSql} LIMIT 501";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st ? $st->fetchAll() : [];
  if (count($rows) > 500) {
    $hasMoreRows = true;
    $rows = array_slice($rows, 0, 500);
  }
}
$totalRows = count($rows);
?>
<?php admin_topbar('Central - Produtos','produtos'); ?>

<?php if (isset($_GET['saved']) && $_GET['saved'] === '1'): ?>
  <div class="msg ok" role="status" style="margin-bottom:12px;">Produto salvo com sucesso. Você já pode pesquisar outro produto.</div>
<?php endif; ?>

<div class="page-head-products">
  <div>
    <h1>Produtos</h1>
    <p>Gerencie os itens do catálogo, preços, categorias, imagens e adicionais.</p>
    <?php if ($hasCodigoFirebird || $hasCodigoFirebirdMap): ?>
      <p class="muted">O código do Próspero aparece na listagem e pode ser pesquisado ou editado no cadastro do produto.</p>
    <?php endif; ?>
  </div>
  <div class="actions page-actions-products">
    <?php if ($hasStockControl && $productsMultiCompanyEnabled): ?><a class="btn" href="<?= hstr(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php') : 'estoque_consulta.php') ?>">Consulta de estoque</a><?php endif; ?>
    <a class="btn btn-primary" href="produto_form.php">+ Novo produto</a>
  </div>
</div>

<?php if ($productsCrossContext): ?>
  <div class="card" style="margin-bottom:14px;border-color:#f59e0b;background:#fffbeb;">
    <strong>V9.1 · Produtos e Estoque Multiempresa — <?= hstr($productsCompanyName) ?></strong>
    <div class="muted" style="margin-top:5px;color:#92400e;">Cadastro básico, fiscal, estoque básico e Conferência de Estoque operam na empresa selecionada pelo F11. Saldo, histórico, mínimo/máximo, reserva manual, saldo inicial, acerto manual e conferência estão homologados; Entradas, produção/ficha técnica, PDV e baixas automáticas continuam protegidos.</div>
  </div>
<?php endif; ?>

<?php
$gridColumnLabels = [
  'imagem' => 'Imagem',
  'codigo' => 'Código',
  'produto' => 'Produto',
  'categoria' => 'Categoria',
  'grupo' => 'Grupo produção',
  'ordem' => 'Ordem',
  'preco' => 'Preço',
  'custo' => 'Custo',
  'estoque' => 'Estoque',
  'prospero' => 'Cód. Próspero',
  'cardapio' => 'Cód. cardápio',
  'referencia' => 'Referência',
  'barras' => 'Código barras',
  'status' => 'Status',
  'acoes' => 'Ações',
];
if (!$hasProductionGroup) unset($gridColumnLabels['grupo']);
if (!$hasProductSortOrder && !$hasCategorySortOrder) unset($gridColumnLabels['ordem']);
if (!$showProductCost) unset($gridColumnLabels['custo']);
if (!$hasStockControl) unset($gridColumnLabels['estoque']);
if (!$hasCodigoFirebird && !$hasCodigoFirebirdMap) unset($gridColumnLabels['prospero']);
if (!$hasCodigoCardapio) unset($gridColumnLabels['cardapio']);
if (!$hasReferencia) unset($gridColumnLabels['referencia']);
if (empty($barcodeColumns)) unset($gridColumnLabels['barras']);
?>

<div class="card products-filter-card">
  <form method="get" class="products-filter-grid" id="productsSearchForm" autocomplete="off">
    <input type="hidden" name="sort" value="<?= hstr($sortKey) ?>">
    <input type="hidden" name="dir" value="<?= hstr($sortDir) ?>">
    <div class="row search-row">
      <label>Pesquisar</label>
      <input class="field" name="q" id="productsSearchInput" value="<?= hstr($q) ?>" placeholder="Código, nome, referência, cardápio, barras, descrição, categoria ou grupo" autocomplete="off" autofocus>
    </div>
    <div class="row">
      <label>Categoria</label>
      <select class="field" name="categoria">
        <option value="">Todas</option>
        <?php foreach ($cats as $c): ?>
          <option value="<?= hstr($c) ?>" <?= $categoriaFiltro === (string)$c ? 'selected' : '' ?>><?= hstr($c) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($hasProductionGroup): ?>
      <div class="row">
        <label>Grupo produção</label>
        <select class="field" name="grupo_producao">
          <option value="">Todos</option>
          <?php foreach ($productionGroups as $g): ?>
            <option value="<?= hstr($g) ?>" <?= $grupoFiltro === (string)$g ? 'selected' : '' ?>><?= hstr($g) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <div class="row">
      <label>Status</label>
      <select class="field" name="status">
        <option value="" <?= $statusFiltro === '' ? 'selected' : '' ?>>Todos</option>
        <option value="ativo" <?= $statusFiltro === 'ativo' ? 'selected' : '' ?>>Ativos</option>
        <option value="inativo" <?= $statusFiltro === 'inativo' ? 'selected' : '' ?>>Inativos</option>
      </select>
    </div>
    <div class="filter-actions-products">
      <button class="btn btn-primary" type="submit">Pesquisar</button>
      <a class="btn" href="produtos.php">Limpar</a>
    </div>
  </form>
</div>

<div class="card products-list-card">
  <div class="cardhead products-list-head">
    <div>
      <h2>Produtos cadastrados</h2>
      <?php if (!$searchRequested): ?>
        <div class="muted">Digite uma pesquisa ou use o filtro no cabeçalho de uma coluna.</div>
      <?php elseif ($hasMoreRows): ?>
        <div class="muted">500+ resultado(s). Refine a pesquisa para reduzir a lista.</div>
      <?php else: ?>
        <div class="muted"><?= (int)$totalRows ?> produto(s) encontrado(s)</div>
      <?php endif; ?>
    </div>
    <div class="actions products-grid-toolbar">
      <details class="columns-picker" id="productColumnsPicker">
        <summary class="btn">Colunas</summary>
        <div class="columns-picker-menu">
          <strong>Colunas visíveis</strong>
          <?php foreach ($gridColumnLabels as $colKey => $colLabel): ?>
            <?php if ($colKey === 'acoes') continue; ?>
            <label><input type="checkbox" data-column-toggle value="<?= hstr($colKey) ?>" checked> <?= hstr($colLabel) ?></label>
          <?php endforeach; ?>
          <div class="columns-picker-note">A preferência fica salva no seu usuário, separada por empresa. Arraste as colunas pelo marcador e puxe a divisória do cabeçalho para ajustar a largura. <span id="gridPreferenceStatus" class="grid-pref-status"></span></div>
        </div>
      </details>
      <?php if ($hasStockControl && $productsMultiCompanyEnabled): ?><a class="btn" href="<?= hstr(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php') : 'estoque_consulta.php') ?>">Estoque</a><?php endif; ?>
      <a class="btn btn-primary btn-mobile-wide" href="produto_form.php">+ Novo produto</a>
    </div>
  </div>

  <style>
  /* Mostra o grid só depois das colunas/larguras do usuário (sem "pular" na tela). */
  .products-grid-wrap:not(.is-ready){visibility:hidden}
  </style>
  <noscript><style>.products-grid-wrap{visibility:visible!important}</style></noscript>
  <script>setTimeout(function(){var w=document.querySelector('.products-grid-wrap');if(w)w.classList.add('is-ready');},4000);</script>
  <div class="products-grid-wrap">
    <table class="products-grid" id="productsGrid">
      <thead>
        <tr class="products-grid-head">
          <th data-col="imagem">Imagem</th>
          <th data-col="codigo"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('codigo', $sortKey, $sortDir)) ?>">Código <span><?= hstr(produtos_admin_sort_mark('codigo', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_codigo', 'Código', $gridFilters['codigo']) ?></div></th>
          <th data-col="produto"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('produto', $sortKey, $sortDir)) ?>">Produto <span><?= hstr(produtos_admin_sort_mark('produto', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_produto', 'Produto', $gridFilters['produto']) ?></div></th>
          <th data-col="categoria"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('categoria', $sortKey, $sortDir)) ?>">Categoria <span><?= hstr(produtos_admin_sort_mark('categoria', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_categoria', 'Categoria', $gridFilters['categoria']) ?></div></th>
          <?php if ($hasProductionGroup): ?><th data-col="grupo"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('grupo', $sortKey, $sortDir)) ?>">Grupo produção <span><?= hstr(produtos_admin_sort_mark('grupo', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_grupo', 'Grupo produção', $gridFilters['grupo']) ?></div></th><?php endif; ?>
          <?php if ($hasProductSortOrder || $hasCategorySortOrder): ?><th data-col="ordem"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('ordem', $sortKey, $sortDir)) ?>">Ordem <span><?= hstr(produtos_admin_sort_mark('ordem', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_ordem', 'Ordem', $gridFilters['ordem']) ?></div></th><?php endif; ?>
          <th data-col="preco"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('preco', $sortKey, $sortDir)) ?>">Preço <span><?= hstr(produtos_admin_sort_mark('preco', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_preco', 'Preço', $gridFilters['preco'], 'number') ?></div></th>
          <?php if ($showProductCost): ?><th data-col="custo"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('custo', $sortKey, $sortDir)) ?>">Custo <span><?= hstr(produtos_admin_sort_mark('custo', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_custo', 'Custo', $gridFilters['custo'], 'number') ?></div></th><?php endif; ?>
          <?php if ($hasStockControl): ?><th data-col="estoque"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('estoque', $sortKey, $sortDir)) ?>">Estoque <span><?= hstr(produtos_admin_sort_mark('estoque', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_estoque', 'Estoque', $gridFilters['estoque'], 'number') ?></div></th><?php endif; ?>
          <?php if ($hasCodigoFirebird || $hasCodigoFirebirdMap): ?><th data-col="prospero"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('prospero', $sortKey, $sortDir)) ?>">Cód. Próspero <span><?= hstr(produtos_admin_sort_mark('prospero', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_prospero', 'Cód. Próspero', $gridFilters['prospero']) ?></div></th><?php endif; ?>
          <?php if ($hasCodigoCardapio): ?><th data-col="cardapio"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('cardapio', $sortKey, $sortDir)) ?>">Cód. cardápio <span><?= hstr(produtos_admin_sort_mark('cardapio', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_cardapio', 'Cód. cardápio', $gridFilters['cardapio']) ?></div></th><?php endif; ?>
          <?php if ($hasReferencia): ?><th data-col="referencia"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('referencia', $sortKey, $sortDir)) ?>">Referência <span><?= hstr(produtos_admin_sort_mark('referencia', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_referencia', 'Referência', $gridFilters['referencia']) ?></div></th><?php endif; ?>
          <?php if (!empty($barcodeColumns)): ?><th data-col="barras"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('barras', $sortKey, $sortDir)) ?>">Código barras <span><?= hstr(produtos_admin_sort_mark('barras', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_barras', 'Código barras', $gridFilters['barras']) ?></div></th><?php endif; ?>
          <th data-col="status"><div class="grid-th-inner"><a class="sort-link" href="<?= hstr(produtos_admin_sort_url('status', $sortKey, $sortDir)) ?>">Status <span><?= hstr(produtos_admin_sort_mark('status', $sortKey, $sortDir)) ?></span></a><?= produtos_admin_grid_filter_menu('f_status', 'Status', $gridFilters['status'], 'status') ?></div></th>
          <th data-col="acoes" class="actions-col">Ações</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$searchRequested): ?>
          <tr><td colspan="<?= count($gridColumnLabels) ?>" class="empty-products-grid">A lista fica vazia para manter a tela leve. Digite uma pesquisa acima ou use o filtro no cabeçalho de uma coluna.</td></tr>
        <?php elseif (!$rows): ?>
          <tr><td colspan="<?= count($gridColumnLabels) ?>" class="empty-products-grid">Nenhum produto encontrado para os filtros informados.</td></tr>
        <?php endif; ?>

        <?php foreach ($rows as $r):
          $id = (int)($r['id'] ?? 0);
          $numeroProduto = company_sequence_display($r, 'produtos');
          $img = trim((string)($r['imagem'] ?? ''));
          $imgUrl = $img !== '' ? ('/uploads/products/' . rawurlencode($img)) : '/assets/no-image.svg';
          $ativo = (int)($r['ativo'] ?? 0) === 1;
          $isService = (int)($r['is_service'] ?? 0) === 1;
          $grupoProd = trim((string)($r['grupo_producao'] ?? ''));
          $categoriaProd = trim((string)($r['categoria'] ?? ''));
          $codigoProspero = trim((string)($r['codigo_firebird'] ?? ''));
          if ($codigoProspero !== '' && $codigoProspero === trim((string)$numeroProduto)) $codigoProspero = '';
          $codigoBarras = trim((string)($r['codigo_barras'] ?? ''));
          $codigoCardapio = trim((string)($r['codigo_cardapio'] ?? ''));
          $referenciaProduto = trim((string)($r['referencia'] ?? ''));
          $unidadeEstoque = stock_unit_clean($r['unidade_estoque'] ?? 'UN');
          if ($unidadeEstoque === '') $unidadeEstoque = 'UN';
          $isRawMaterial = $compositionEnabled && (int)($r['is_materia_prima'] ?? 0) === 1;
          $isComposite = $compositionEnabled && (int)($r['possui_composicao'] ?? 0) === 1;
          $compositionCalculated = (float)($r['estoque_composicao_calculado'] ?? 0);
          $compositionIndividual = (float)($r['estoque_composicao_individual'] ?? 0);
          $estoqueAtual = (float)($r['estoque_atual'] ?? 0);
          $estoqueMinimo = (float)($r['estoque_minimo'] ?? 0);
          $estoqueMaximo = (float)($r['estoque_maximo'] ?? 0);
          $estoqueReservado = (float)($r['estoque_reservado_manual'] ?? 0) + (float)($r['estoque_reservado_pedidos'] ?? 0);
          $estoqueDisponivel = $isComposite ? $compositionCalculated : ($estoqueAtual - $estoqueReservado);
          $estoqueBaixo = $hasStockControl && $estoqueMinimo > 0 && $estoqueDisponivel <= $estoqueMinimo;
        ?>
          <tr>
            <td data-col="imagem" class="image-cell"><img class="grid-thumb" src="<?= hstr($imgUrl) ?>" alt=""></td>
            <td data-col="codigo" class="code-cell"><a class="idlink" href="produto_form.php?id=<?= $id ?>">#<?= hstr($numeroProduto) ?></a></td>
            <td data-col="produto" class="product-cell">
              <a class="product-name-grid" href="produto_form.php?id=<?= $id ?>"><?= hstr($r['nome'] ?? '') ?></a>
              <div class="product-badges-grid"><?php if ($isService): ?><span class="badge service-badge">Serviço</span><?php endif; ?><?php if ($isRawMaterial): ?><span class="badge composition-raw-badge">Matéria-prima</span><?php endif; ?><?php if ($isComposite): ?><span class="badge composition-product-badge">Composto</span><?php endif; ?></div>
              <?php if (trim((string)($r['descricao'] ?? '')) !== ''): ?><div class="muted product-desc-grid"><?= hstr($r['descricao'] ?? '') ?></div><?php endif; ?>
            </td>
            <td data-col="categoria"><?= $categoriaProd !== '' ? hstr($categoriaProd) : '<span class="muted">—</span>' ?></td>
            <?php if ($hasProductionGroup): ?><td data-col="grupo"><?= $grupoProd !== '' ? hstr($grupoProd) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <?php if ($hasProductSortOrder || $hasCategorySortOrder): ?><td data-col="ordem" class="nowrap"><span class="muted">Cat:</span> <?= (int)($r['category_sort_order'] ?? 0) ?> <span class="muted">Prod:</span> <?= (int)($r['sort_order'] ?? 0) ?></td><?php endif; ?>
            <td data-col="preco" class="nowrap"><strong><?= money_pt((float)($r['preco'] ?? 0)) ?></strong></td>
            <?php if ($showProductCost): ?><td data-col="custo" class="nowrap"><?= money_pt((float)($r['custo'] ?? 0)) ?></td><?php endif; ?>
            <?php if ($hasStockControl): ?>
              <td data-col="estoque" class="stock-grid-cell <?= $estoqueBaixo ? 'stock-low-grid' : '' ?>">
                <?php if ($isComposite): ?>
                  <strong><?= hstr(stock_qty_br($compositionCalculated)) ?> <?= hstr($unidadeEstoque) ?></strong>
                  <small>Composição · Físico <?= hstr(stock_qty_br($estoqueAtual)) ?></small>
                <?php else: ?>
                  <strong><?= hstr(stock_qty_br($estoqueAtual)) ?> <?= hstr($unidadeEstoque) ?></strong>
                  <small>Reserv. <?= hstr(stock_qty_br($estoqueReservado)) ?> · Disp. <?= hstr(stock_qty_br($estoqueDisponivel)) ?></small>
                <?php endif; ?>
              </td>
            <?php endif; ?>
            <?php if ($hasCodigoFirebird || $hasCodigoFirebirdMap): ?><td data-col="prospero"><?= $codigoProspero !== '' ? hstr($codigoProspero) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <?php if ($hasCodigoCardapio): ?><td data-col="cardapio"><?= $codigoCardapio !== '' ? hstr($codigoCardapio) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <?php if ($hasReferencia): ?><td data-col="referencia"><?= $referenciaProduto !== '' ? hstr($referenciaProduto) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <?php if (!empty($barcodeColumns)): ?><td data-col="barras"><?= $codigoBarras !== '' ? hstr($codigoBarras) : '<span class="muted">—</span>' ?></td><?php endif; ?>
            <td data-col="status"><?php if ($ativo): ?><span class="badge on">Ativo</span><?php else: ?><span class="badge off">Inativo</span><?php endif; ?></td>
            <td data-col="acoes" class="actions-col">
              <div class="grid-row-actions">
                <a class="btn btn-sm btn-primary" href="produto_form.php?id=<?= $id ?>">Editar</a>
                <?php if ($productsCrossContext): ?>
                  <span class="btn btn-sm btn-disabled" aria-disabled="true" title="Duplicação cruzada ainda não homologada">Duplicar</span>
                <?php else: ?>
                  <form method="post" action="produto_form.php" onsubmit="return confirm('Duplicar este produto? A descrição receberá o texto Duplicado.');">
                    <input type="hidden" name="action" value="duplicate">
                    <input type="hidden" name="produto_id" value="<?= $id ?>">
                    <button class="btn btn-sm" type="submit">Duplicar</button>
                  </form>
                <?php endif; ?>
              </div>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<style>
.page-head-products{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin:14px 0 14px}
.page-head-products h1{margin:0;color:#001e60;font-size:clamp(28px,4vw,38px);line-height:1.1}
.page-head-products p{margin:8px 0 0;color:#52627d}
.page-actions-products{margin-top:2px;flex-shrink:0}.btn-disabled{opacity:.55;cursor:not-allowed;pointer-events:none}.products-filter-card{margin-bottom:16px}.products-filter-grid{display:grid;grid-template-columns:minmax(280px,2fr) minmax(160px,1fr) minmax(160px,1fr) minmax(140px,.7fr) auto;gap:14px;align-items:end}.filter-actions-products{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding-bottom:1px}.products-list-head h2{margin:0 0 4px;color:#001e60}.btn-mobile-wide{display:none}
.products-grid-toolbar{align-items:center;flex-wrap:wrap}.columns-picker{position:relative}.columns-picker>summary{list-style:none;cursor:pointer}.columns-picker>summary::-webkit-details-marker{display:none}.columns-picker-menu{position:absolute;right:0;top:calc(100% + 7px);z-index:40;min-width:220px;max-height:380px;overflow:auto;background:#fff;border:1px solid #dbeafe;border-radius:14px;box-shadow:0 18px 40px rgba(15,35,80,.18);padding:12px}.columns-picker-menu strong{display:block;margin-bottom:8px;color:#0b1f5b}.columns-picker-menu label{display:flex;gap:8px;align-items:center;padding:6px 4px;font-size:13px;font-weight:800;color:#334155}.columns-picker-note{border-top:1px solid #eef4ff;margin-top:8px;padding-top:8px;font-size:11px;color:#64748b}
.products-grid-wrap{overflow-x:auto;overflow-y:visible;border-top:1px solid #e5edf8;max-height:none;height:auto}.products-grid{width:100%;border-collapse:separate;border-spacing:0;min-width:1320px;font-size:12px;table-layout:fixed}.products-grid th,.products-grid td{padding:9px 10px;border-bottom:1px solid #e9eff8;border-right:1px solid #f1f5f9;vertical-align:middle;text-align:left}.products-grid th:last-child,.products-grid td:last-child{border-right:0}.products-grid thead th{position:sticky;top:0;z-index:15;background:#f7faff;color:#243b70;font-size:11px;text-transform:uppercase;letter-spacing:.025em;font-weight:900}.products-grid tbody tr:nth-child(odd) td{background:#fff}.products-grid tbody tr:nth-child(even) td{background:#dfe8f4}.products-grid tbody tr:hover td{background:#cbdaf0}.products-grid thead th.filter-open{z-index:95}.products-grid thead th[data-col]{user-select:none}.col-drag-handle{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:14px;height:22px;margin-right:1px;color:#94a3b8;cursor:grab;border-radius:4px;font-size:12px;line-height:1;letter-spacing:-2px}.col-drag-handle:hover{color:#3558c9;background:#e8efff}.col-drag-handle:active{cursor:grabbing}.col-resizer{position:absolute;top:0;right:-3px;z-index:110;width:7px;height:100%;cursor:col-resize;touch-action:none}.col-resizer:after{content:"";position:absolute;top:20%;bottom:20%;left:3px;width:1px;background:#c8d4e8;opacity:.55}.col-resizer:hover:after,.products-grid.resizing-columns .col-resizer:after{background:#3558c9;opacity:1;width:2px}.products-grid.resizing-columns,.products-grid.resizing-columns *{cursor:col-resize!important}.products-grid thead th.col-drop-before{box-shadow:inset 3px 0 0 #3558c9}.products-grid thead th.col-drop-after{box-shadow:inset -3px 0 0 #3558c9}.products-grid thead th.col-dragging{opacity:.58}.grid-layout-hint{font-size:11px;color:#64748b}.grid-th-inner{display:flex;align-items:center;gap:5px;min-height:22px}.sort-link{display:flex;min-width:0;flex:1;gap:5px;align-items:center;justify-content:space-between;color:inherit;text-decoration:none;white-space:nowrap}.sort-link span{font-size:12px;color:#64748b}.grid-filter-menu{position:relative;flex:0 0 auto}.grid-filter-menu>summary{list-style:none}.grid-filter-menu>summary::-webkit-details-marker{display:none}.grid-filter-trigger{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;color:#71809c;cursor:pointer;background:transparent}.grid-filter-trigger:hover{background:#eaf1ff;color:#2448b8;border-color:#cfdbf5}.grid-filter-trigger svg{width:14px;height:14px}.grid-filter-menu.has-filter .grid-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3;box-shadow:inset 0 -2px 0 #3558c9}.grid-filter-menu[open] .grid-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3}.grid-filter-popover{position:absolute;top:calc(100% + 7px);right:0;z-index:80;width:240px;padding:12px;background:#fff;border:1px solid #d9e3f2;border-radius:12px;box-shadow:0 16px 38px rgba(15,35,80,.22);text-transform:none;letter-spacing:normal;font-weight:600}.grid-filter-title{font-size:12px;font-weight:900;color:#18336f;margin-bottom:8px}.grid-filter-input{width:100%;height:36px;border:1px solid #ccd8ea;border-radius:8px;padding:6px 9px;background:#fff;color:#0f172a;font-size:12px;outline:none}.grid-filter-input:focus{border-color:#4c6fff;box-shadow:0 0 0 2px rgba(76,111,255,.10)}.grid-filter-actions{display:flex;gap:7px;margin-top:9px}.grid-filter-actions .btn{min-height:32px;padding:6px 10px;font-size:11px;flex:1;justify-content:center}.grid-thumb{width:42px;height:42px;object-fit:cover;border-radius:10px;border:1px solid #dbeafe;background:#f8fbff}.image-cell{width:62px;text-align:center!important}.code-cell{white-space:nowrap}.product-cell{min-width:250px;max-width:390px}.product-name-grid{display:block;color:#0b1f5b;font-size:13px;font-weight:900;text-decoration:none}.product-badges-grid{display:flex;gap:5px;flex-wrap:wrap;margin-top:3px}.product-desc-grid{max-width:360px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;margin-top:3px}.service-badge{background:#eef2ff!important;color:#3730a3!important;border:1px solid #c7d2fe!important;font-size:9px!important;padding:2px 5px!important}.composition-raw-badge{background:#ecfdf5!important;color:#047857!important;border:1px solid #a7f3d0!important;font-size:9px!important;padding:2px 5px!important}.composition-product-badge{background:#eff6ff!important;color:#1d4ed8!important;border:1px solid #bfdbfe!important;font-size:9px!important;padding:2px 5px!important}.stock-grid-cell{min-width:150px}.stock-grid-cell strong{display:block}.stock-grid-cell small{display:block;color:#64748b;margin-top:2px}.stock-low-grid{background:#fff7ed!important}.nowrap{white-space:nowrap}.actions-col{min-width:175px;position:sticky;right:0;box-shadow:-6px 0 10px rgba(15,35,80,.03)}.products-grid thead .actions-col{background:#f7faff!important}.products-grid tbody tr:nth-child(odd) .actions-col{background:#fff!important}.products-grid tbody tr:nth-child(even) .actions-col{background:#dfe8f4!important}.products-grid tbody tr:hover .actions-col{background:#cbdaf0!important}.grid-row-actions{display:flex;gap:6px;align-items:center;justify-content:flex-end}.grid-row-actions form{margin:0}.grid-row-actions .btn{min-width:72px;justify-content:center}.empty-products-grid{text-align:center!important;color:#52627d;padding:34px!important;background:#fbfdff!important;font-size:13px}.col-hidden{display:none!important}.grid-pref-status{display:inline-block;margin-left:5px;font-weight:800;color:#64748b}.grid-pref-status.saving{color:#2563eb}.grid-pref-status.saved{color:#047857}.grid-pref-status.error{color:#b91c1c}
@media(max-width:1100px){.products-filter-grid{grid-template-columns:1fr 1fr}.filter-actions-products{justify-content:flex-start}.page-actions-products{display:none}.btn-mobile-wide{display:inline-flex}.products-grid-wrap{max-height:none;height:auto}.columns-picker-menu{left:0;right:auto}}
@media(max-width:680px){.products-filter-grid{grid-template-columns:1fr}.filter-actions-products{display:grid;grid-template-columns:1fr 1fr}.filter-actions-products .btn{width:100%;justify-content:center}.products-list-head{display:grid;gap:10px}.products-grid-toolbar{justify-content:flex-start}.btn-mobile-wide{width:100%;justify-content:center}.page-head-products{margin-top:8px}.products-grid-wrap{max-height:none;height:auto}.products-grid{min-width:1180px}}
</style>

<script>
(function(){
  const grid = document.getElementById('productsGrid');
  if (!grid) return;

  const companyId = '<?= (int)$productsCompanyId ?>';
  const storageKey = 'nexa.products.grid.columns.company.' + companyId;
  const orderKey = 'nexa.products.grid.order.company.' + companyId;
  const widthKey = 'nexa.products.grid.widths.company.' + companyId;
  const preferenceCsrf = <?= json_encode($productsGridCsrf, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const serverPreferences = <?= json_encode($productsGridUserPreferences, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?> || {};
  const hasServerPreferences = <?= $productsGridHasServerPreferences ? 'true' : 'false' ?>;
  const preferenceStatus = document.getElementById('gridPreferenceStatus');
  const toggles = Array.from(document.querySelectorAll('[data-column-toggle]'));
  const available = toggles.map(cb => cb.value);
  const wrap = grid.closest('.products-grid-wrap');

  const defaultWidths = {
    imagem:72, codigo:105, produto:290, categoria:150, grupo:160, ordem:120,
    preco:105, custo:105, estoque:175, prospero:125, cardapio:125, referencia:135,
    barras:150, status:95, acoes:175
  };
  // Larguras mínimas pequenas: o usuário pode estreitar a coluna além do título
  // (ex.: "Código" com números curtos). O título é cortado com reticências.
  const minWidths = {
    imagem:40, codigo:40, produto:90, categoria:50, grupo:50, ordem:40,
    preco:50, custo:50, estoque:60, prospero:50, cardapio:50, referencia:50,
    barras:60, status:44, acoes:165
  };

  let legacyPreferenceFound = false;
  let visible = available.slice();
  if (hasServerPreferences && Array.isArray(serverPreferences.visible)) {
    visible = available.filter(c => serverPreferences.visible.includes(c));
  } else {
    try {
      const saved = JSON.parse(localStorage.getItem(storageKey) || 'null');
      if (Array.isArray(saved)) {
        visible = available.filter(c => saved.includes(c));
        legacyPreferenceFound = true;
      }
    } catch (e) {}
  }

  const headerCols = Array.from(grid.querySelectorAll('thead th[data-col]')).map(th => th.dataset.col).filter(Boolean);
  const draggableCols = headerCols.filter(c => c !== 'acoes');
  let columnOrder = draggableCols.slice();
  if (hasServerPreferences && Array.isArray(serverPreferences.order)) {
    const valid = serverPreferences.order.filter(c => draggableCols.includes(c));
    columnOrder = valid.concat(draggableCols.filter(c => !valid.includes(c)));
  } else {
    try {
      const savedOrder = JSON.parse(localStorage.getItem(orderKey) || 'null');
      if (Array.isArray(savedOrder)) {
        const valid = savedOrder.filter(c => draggableCols.includes(c));
        columnOrder = valid.concat(draggableCols.filter(c => !valid.includes(c)));
        legacyPreferenceFound = true;
      }
    } catch (e) {}
  }

  let widths = {};
  if (hasServerPreferences && serverPreferences.widths && typeof serverPreferences.widths === 'object' && !Array.isArray(serverPreferences.widths)) {
    widths = Object.assign({}, serverPreferences.widths);
  } else {
    try {
      const savedWidths = JSON.parse(localStorage.getItem(widthKey) || '{}');
      if (savedWidths && typeof savedWidths === 'object' && !Array.isArray(savedWidths) && Object.keys(savedWidths).length) {
        widths = savedWidths;
        legacyPreferenceFound = true;
      }
    } catch (e) {}
  }

  let preferenceSaveTimer = null;
  let preferenceSaveSeq = 0;

  function setPreferenceStatus(text, className){
    if (!preferenceStatus) return;
    preferenceStatus.textContent = text || '';
    preferenceStatus.className = 'grid-pref-status' + (className ? (' ' + className) : '');
  }

  function currentPreferences(){
    const cleanWidths = {};
    Object.keys(widths || {}).forEach(col => {
      if (!available.includes(col)) return;
      const n = Number(widths[col]);
      if (Number.isFinite(n) && n > 0) cleanWidths[col] = Math.round(n);
    });
    return {
      visible: visible.filter(c => available.includes(c)),
      order: columnOrder.filter(c => draggableCols.includes(c)),
      widths: cleanWidths
    };
  }

  function persistLocalFallback(){
    try { localStorage.setItem(storageKey, JSON.stringify(visible)); } catch (e) {}
    try { localStorage.setItem(orderKey, JSON.stringify(columnOrder)); } catch (e) {}
    try { localStorage.setItem(widthKey, JSON.stringify(widths)); } catch (e) {}
  }

  async function saveUserPreferences(){
    persistLocalFallback();
    const seq = ++preferenceSaveSeq;
    setPreferenceStatus('Salvando…', 'saving');
    try {
      const body = new URLSearchParams();
      body.set('action', 'save_grid_preferences');
      body.set('csrf_token', preferenceCsrf);
      body.set('preferences', JSON.stringify(currentPreferences()));
      const response = await fetch('produtos.php', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},
        body: body.toString()
      });
      const data = await response.json().catch(() => ({}));
      if (!response.ok || !data.ok) throw new Error(data.message || 'Falha ao salvar');
      if (seq === preferenceSaveSeq) {
        setPreferenceStatus('Salvo no usuário', 'saved');
        window.setTimeout(() => {
          if (seq === preferenceSaveSeq) setPreferenceStatus('', '');
        }, 1800);
      }
    } catch (e) {
      if (seq === preferenceSaveSeq) setPreferenceStatus('Não foi possível salvar no usuário', 'error');
    }
  }

  function scheduleUserPreferenceSave(delay){
    if (preferenceSaveTimer) window.clearTimeout(preferenceSaveTimer);
    preferenceSaveTimer = window.setTimeout(saveUserPreferences, typeof delay === 'number' ? delay : 180);
  }

  function saveOrder(){
    persistLocalFallback();
    scheduleUserPreferenceSave(120);
  }
  function saveWidths(){
    persistLocalFallback();
    scheduleUserPreferenceSave(180);
  }

  function orderedColumns(){
    return columnOrder.concat(headerCols.includes('acoes') ? ['acoes'] : []);
  }

  function applyOrder(){
    const order = orderedColumns();
    grid.querySelectorAll('tr').forEach(row => {
      const byCol = {};
      Array.from(row.children).forEach(cell => {
        const col = cell.getAttribute && cell.getAttribute('data-col');
        if (col) byCol[col] = cell;
      });
      if (!Object.keys(byCol).length) return;
      order.forEach(col => { if (byCol[col]) row.appendChild(byCol[col]); });
    });
  }

  function widthFor(col){
    const n = Number(widths[col]);
    return Number.isFinite(n) && n > 0 ? n : (defaultWidths[col] || 120);
  }

  function applyWidth(col, px, persist){
    const min = minWidths[col] || 40;
    const max = col === 'produto' ? 650 : 480;
    const value = Math.max(min, Math.min(max, Math.round(px)));
    widths[col] = value; // valor ao vivo (a sobra de espaço é calculada com ele)
    if (persist) saveWidths();
    grid.querySelectorAll('[data-col="' + CSS.escape(col) + '"]').forEach(cell => {
      cell.style.width = value + 'px';
      cell.style.minWidth = value + 'px';
      cell.style.maxWidth = value + 'px';
    });
    refreshGridWidth();
  }

  function refreshGridWidth(){
    if (!wrap) return;
    let total = 0;
    orderedColumns().forEach(col => {
      if (col !== 'acoes' && !visible.includes(col)) return;
      total += widthFor(col);
    });
    // Sobra de espaço vai só para a coluna Produto: estreitar uma coluna deixa
    // ela realmente estreita, em vez de o espaço ser redistribuído entre todas.
    const extra = Math.max(0, (wrap.clientWidth || 0) - total);
    const flexCol = visible.includes('produto') ? 'produto' : null;
    if (flexCol) {
      const px = Math.round(widthFor(flexCol) + extra);
      grid.querySelectorAll('[data-col="produto"]').forEach(cell => { cell.style.width = px + 'px'; cell.style.minWidth = px + 'px'; cell.style.maxWidth = px + 'px'; });
    }
    grid.style.minWidth = '0';
    grid.style.width = (total + (flexCol ? extra : 0) > 0 ? Math.max(total + (flexCol ? extra : 0), flexCol ? 0 : (wrap.clientWidth || 0)) : (wrap.clientWidth || 0)) + 'px';
  }

  function applyAllWidths(){
    orderedColumns().forEach(col => applyWidth(col, widthFor(col), false));
    refreshGridWidth();
  }

  function applyColumns(){
    toggles.forEach(cb => { cb.checked = visible.includes(cb.value); });
    available.forEach(col => {
      grid.querySelectorAll('[data-col="' + CSS.escape(col) + '"]').forEach(el => {
        el.classList.toggle('col-hidden', !visible.includes(col));
      });
    });
    refreshGridWidth();
  }

  toggles.forEach(cb => cb.addEventListener('change', function(){
    if (this.checked) {
      if (!visible.includes(this.value)) visible.push(this.value);
    } else {
      visible = visible.filter(v => v !== this.value);
    }
    persistLocalFallback();
    applyColumns();
    scheduleUserPreferenceSave(120);
  }));

  // Ordenação visual das colunas por arrastar. Ações permanece fixa à direita.
  let draggingCol = null;
  let dropTarget = null;
  let dropAfter = false;

  function clearDropMarks(){
    grid.querySelectorAll('thead th').forEach(th => th.classList.remove('col-drop-before','col-drop-after','col-dragging'));
    dropTarget = null;
  }

  draggableCols.forEach(col => {
    const th = grid.querySelector('thead th[data-col="' + CSS.escape(col) + '"]');
    if (!th) return;
    const host = th.querySelector('.grid-th-inner') || th;
    const handle = document.createElement('span');
    handle.className = 'col-drag-handle';
    handle.textContent = '⋮⋮';
    handle.title = 'Arrastar coluna';
    handle.setAttribute('aria-label', 'Arrastar coluna');
    handle.draggable = true;
    host.insertBefore(handle, host.firstChild);

    handle.addEventListener('dragstart', function(ev){
      draggingCol = col;
      th.classList.add('col-dragging');
      try { ev.dataTransfer.setData('text/plain', col); } catch (e) {}
      if (ev.dataTransfer) ev.dataTransfer.effectAllowed = 'move';
    });
    handle.addEventListener('dragend', function(){
      draggingCol = null;
      clearDropMarks();
    });
  });

  grid.querySelectorAll('thead th[data-col]').forEach(th => {
    const targetCol = th.dataset.col || '';
    if (!targetCol || targetCol === 'acoes') return;
    th.addEventListener('dragover', function(ev){
      if (!draggingCol || draggingCol === targetCol) return;
      ev.preventDefault();
      const rect = th.getBoundingClientRect();
      dropAfter = ev.clientX > rect.left + rect.width / 2;
      clearDropMarks();
      dropTarget = targetCol;
      th.classList.add(dropAfter ? 'col-drop-after' : 'col-drop-before');
      const source = grid.querySelector('thead th[data-col="' + CSS.escape(draggingCol) + '"]');
      if (source) source.classList.add('col-dragging');
    });
    th.addEventListener('drop', function(ev){
      if (!draggingCol || draggingCol === targetCol) return;
      ev.preventDefault();
      const next = columnOrder.filter(c => c !== draggingCol);
      let idx = next.indexOf(targetCol);
      if (idx < 0) idx = next.length;
      if (dropAfter) idx += 1;
      next.splice(idx, 0, draggingCol);
      columnOrder = next;
      saveOrder();
      applyOrder();
      applyAllWidths();
      draggingCol = null;
      clearDropMarks();
    });
  });

  // Redimensionamento das colunas pelo divisor do cabeçalho.
  draggableCols.forEach(col => {
    const th = grid.querySelector('thead th[data-col="' + CSS.escape(col) + '"]');
    if (!th) return;
    const resizer = document.createElement('span');
    resizer.className = 'col-resizer';
    resizer.title = 'Arraste para ajustar a largura';
    resizer.setAttribute('aria-hidden', 'true');
    th.appendChild(resizer);

    resizer.addEventListener('pointerdown', function(ev){
      if (ev.button !== 0) return;
      ev.preventDefault();
      ev.stopPropagation();
      const startX = ev.clientX;
      const startWidth = widthFor(col);
      grid.classList.add('resizing-columns');
      document.body.style.userSelect = 'none';

      const move = function(moveEv){
        applyWidth(col, startWidth + (moveEv.clientX - startX), false);
      };
      const up = function(upEv){
        document.removeEventListener('pointermove', move);
        document.removeEventListener('pointerup', up);
        grid.classList.remove('resizing-columns');
        document.body.style.userSelect = '';
        const finalWidth = startWidth + (upEv.clientX - startX);
        applyWidth(col, finalWidth, true);
      };
      document.addEventListener('pointermove', move);
      document.addEventListener('pointerup', up, {once:true});
    });
  });

  applyOrder();
  applyColumns();
  applyAllWidths();
  if (wrap) wrap.classList.add('is-ready');
  window.addEventListener('resize', refreshGridWidth);

  // Se o usuário já personalizou o grid em uma versão anterior, migra uma vez
  // a preferência local para o perfil do usuário no servidor.
  if (!hasServerPreferences && legacyPreferenceFound) {
    scheduleUserPreferenceSave(350);
  }

  const searchForm = document.getElementById('productsSearchForm');
  const filterMenus = Array.from(grid.querySelectorAll('[data-grid-filter-menu]'));

  // Comportamento semelhante ao cabeçalho do Excel: um menu de filtro por vez.
  filterMenus.forEach(menu => {
    menu.addEventListener('toggle', function(){
      const th = this.closest('th');
      if (th) th.classList.toggle('filter-open', this.open);
      if (!this.open) return;
      filterMenus.forEach(other => {
        if (other !== this) {
          other.open = false;
          const otherTh = other.closest('th');
          if (otherTh) otherTh.classList.remove('filter-open');
        }
      });
      window.setTimeout(() => {
        const input = this.querySelector('.grid-filter-input');
        if (input) input.focus();
      }, 20);
    });
  });

  document.addEventListener('click', function(ev){
    filterMenus.forEach(menu => {
      if (menu.open && !menu.contains(ev.target)) {
        menu.open = false;
        const th = menu.closest('th');
        if (th) th.classList.remove('filter-open');
      }
    });
  });

  grid.querySelectorAll('.grid-filter-input').forEach(el => {
    el.addEventListener('keydown', function(ev){
      if (ev.key === 'Enter') {
        ev.preventDefault();
        if (searchForm) searchForm.requestSubmit();
      } else if (ev.key === 'Escape') {
        const menu = this.closest('[data-grid-filter-menu]');
        if (menu) menu.open = false;
      }
    });
  });

  grid.querySelectorAll('[data-clear-filter]').forEach(btn => {
    btn.addEventListener('click', function(){
      if (!searchForm) return;
      const fieldName = this.getAttribute('data-clear-filter') || '';
      const field = searchForm.querySelector('[name="' + fieldName.replace(/"/g, '') + '"]');
      if (field) field.value = '';
      searchForm.requestSubmit();
    });
  });
})();
</script>

<?php admin_footer(); ?>
