<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/product_options.php';
require_once __DIR__ . '/../config/balanca_module.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/nfse_quick_emit.php';
require_once __DIR__ . '/../config/product_menu_code.php';
require_once __DIR__ . '/../config/product_composition.php';
require_once __DIR__ . '/../config/product_half_pizza.php';
require_once __DIR__ . '/../config/orcamento_pedido_bridge.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/../config/pix_checkout.php';
require_once __DIR__ . '/_print_brand.php';

$pdvCheckoutMode = true;
$pdvMobileMode = false;
// O PDV Checkout reutiliza o motor homologado do Pedido PDV, mas possui
// permissão própria para que o perfil possa liberar o checkout sem liberar o Pedido PDV.
$pdvScope = 'sales_pdv_order';
$pdvPermission = 'gestao_pdv_checkout';
$pdvLabel = 'PDV Checkout';
$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, $pdvScope)
  : (int)COMPANY_ID;
$pdvCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, $pdvScope);
if ($pdvCrossContext) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if (function_exists('multi_company_context_permission_allowed')
      && !multi_company_context_permission_allowed($pdo, $pdvPermission)) {
    http_response_code(403);
    admin_topbar($pdvLabel, 'gestao');
    echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para operar ' . gestao_h($pdvLabel) . ' na empresa selecionada pelo F11.</div>';
    admin_footer();
    exit;
  }
  // V11.46.1: emissão fiscal iniciada pelo PDV usa a própria permissão do fluxo,
  // preservando o comportamento já existente na empresa principal.
  if (!$pdvMobileMode) $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_SALES_PERMISSION'] = $pdvPermission;
}
$pdvHalfPizzaReady = product_half_pizza_ensure_schema($pdo, !$pdvCrossContext);
if (!$pdvCrossContext && function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId)) {
  product_composition_ensure_schema($pdo);
}
$userId = (int)(auth_user_id() ?? 0);
$pixCheckoutReady = false;
try {
  $pixCheckoutReady = pix_checkout_ensure_schema($pdo, !$pdvCrossContext);
} catch (Throwable $pixSchemaError) {
  error_log('[Nexa V11.38.0] Não foi possível preparar o Pix Checkout: ' . $pixSchemaError->getMessage());
}
$saleFinalizationBlocked = $pdvCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'nao_finalizar_venda'))
  : (function_exists('access_sale_finalization_blocked') ? access_sale_finalization_blocked($pdo, $companyId) : false);
$erro = '';
$pdvBaseUrl = 'gestao_pdv_checkout.php';
$pdvContextUrl = static function(string $url) use ($pdvCrossContext): string {
  return $pdvCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$pdvMcCsrf = $pdvCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$pdvMcCtx = $pdvCrossContext && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '') : '';
$orcamentoReadEnabled = !$pdvMobileMode && function_exists('orc_bridge_ready') && orc_bridge_ready($pdo, $companyId);
$orcamentoAjaxUrl = $orcamentoReadEnabled ? $pdvContextUrl('orcamento_pedido_ajax.php?scope=sales_pdv_order') : '';
$comandaReadEnabled = !$pdvMobileMode
  && function_exists('saas_company_has_module')
  && saas_company_has_module($pdo, $companyId, 'comandas')
  && ($pdvCrossContext
    ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'gestao_comandas'))
    : (!function_exists('access_has_permission') || access_has_permission($pdo, $companyId, 'gestao_comandas')));
$osReadEnabled = !$pdvMobileMode
  && !$pdvCrossContext
  && function_exists('os_module_enabled')
  && os_module_enabled($pdo, $companyId)
  && (!function_exists('access_has_permission') || access_has_permission($pdo, $companyId, 'os_monitor'));
$osFiscalOptions = $osReadEnabled && function_exists('os_fiscal_company_options')
  ? os_fiscal_company_options($pdo, $companyId)
  : ['enabled'=>false,'NFE'=>false,'NFCE'=>false,'NFSE'=>false,'problems'=>[]];
$osFiscalPermissionAllowed = $osReadEnabled && (!function_exists('access_has_permission') || access_has_permission($pdo, $companyId, 'os_fiscal_documentos'));
if (!$osFiscalPermissionAllowed) {
  $osFiscalOptions['NFE'] = false;
  $osFiscalOptions['NFCE'] = false;
  $osFiscalOptions['NFSE'] = false;
  $osFiscalOptions['problems'][] = 'Seu perfil não possui permissão para emitir documentos fiscais da O.S.';
}

// V11.35/V11.46: o Pedido PDV padrão pode emitir NFC-e 65 e NF-e 55 no contexto F11
// exclusivamente quando a empresa selecionada estiver em HOMOLOGAÇÃO. Venda Mobile
// permanece sem emissão fiscal nesta etapa.
$pdvFiscalCfgF11 = [];
$pdvNfceHomologationF11 = false;
$pdvNfeHomologationF11 = false;
$pdvFiscalStatusF11 = '';
if ($pdvCrossContext && !$pdvMobileMode) {
  try {
    $pdvFiscalCfgF11 = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    // V11.46.2: o fechamento cruzado segue o mesmo critério visual da empresa principal:
    // as opções dependem da configuração fiscal da empresa operacional. A permissão do
    // próprio PDV já foi revalidada acima pelo contexto F11. A única restrição adicional
    // desta etapa é HOMOLOGAÇÃO; produção continua deliberadamente bloqueada.
    $pdvFiscalEnvironmentF11 = strtoupper(trim((string)($pdvFiscalCfgF11['environment'] ?? '')));
    $pdvFiscalEmissionEnabledF11 = !empty($pdvFiscalCfgF11['fiscal_emission_enabled']);
    $pdvFiscalHomologationAllowedF11 = $pdvFiscalEnvironmentF11 === 'HOMOLOGACAO'
      && $pdvFiscalEmissionEnabledF11;
    $pdvNfceHomologationF11 = $pdvFiscalHomologationAllowedF11 && !empty($pdvFiscalCfgF11['nfce_enabled']);
    $pdvNfeHomologationF11 = $pdvFiscalHomologationAllowedF11 && !empty($pdvFiscalCfgF11['nfe_enabled']);
    $pdvFiscalStatusF11 = 'Empresa #' . $companyId
      . ' · Ambiente: ' . ($pdvFiscalEnvironmentF11 !== '' ? $pdvFiscalEnvironmentF11 : 'NÃO INFORMADO')
      . ' · Emissão fiscal: ' . ($pdvFiscalEmissionEnabledF11 ? 'ATIVA' : 'INATIVA')
      . ' · NFC-e: ' . (!empty($pdvFiscalCfgF11['nfce_enabled']) ? 'HABILITADA' : 'DESABILITADA')
      . ' · NF-e: ' . (!empty($pdvFiscalCfgF11['nfe_enabled']) ? 'HABILITADA' : 'DESABILITADA');
  } catch (Throwable $e) {
    $pdvFiscalCfgF11 = [];
    $pdvNfceHomologationF11 = false;
    $pdvNfeHomologationF11 = false;
    $pdvFiscalStatusF11 = 'Não foi possível ler a configuração fiscal da empresa #' . $companyId . ': ' . $e->getMessage();
  }
}


function pdv_product_sort_columns(PDO $pdo): array {
  if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) {
    try { $pdo->exec('ALTER TABLE produtos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {}
    try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_sort_order INT NOT NULL DEFAULT 0 AFTER sort_order'); } catch (Throwable $e) {}
  }
  $hasProductSort = false;
  $hasCategorySort = false;
  try {
    $st = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME IN (?, ?)');
    $st->execute(['produtos', 'sort_order', 'category_sort_order']);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    $hasProductSort = in_array('sort_order', $cols, true);
    $hasCategorySort = in_array('category_sort_order', $cols, true);
  } catch (Throwable $e) {}
  return [$hasProductSort, $hasCategorySort];
}

function pdv_product_barcode_columns(PDO $pdo): array {
  $out = [];
  foreach (['codigo_barras','ean','gtin','barcode'] as $col) {
    try { if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', $col)) $out[] = $col; } catch (Throwable $e) {}
  }
  return array_values(array_unique($out));
}

function pdv_product_category_list(PDO $pdo, int $companyId): array {
  [$hasProductSort, $hasCategorySort] = pdv_product_sort_columns($pdo);
  $catOrder = $hasCategorySort ? 'MIN(CASE WHEN COALESCE(category_sort_order,0) <= 0 THEN 999999 ELSE category_sort_order END)' : '999999';
  try {
    $st = $pdo->prepare("SELECT COALESCE(categoria,'') AS categoria FROM produtos WHERE company_id = ? AND COALESCE(ativo,1) = 1 AND COALESCE(categoria,'') <> '' GROUP BY categoria ORDER BY {$catOrder} ASC, categoria ASC LIMIT 300");
    $st->execute([$companyId]);
    $cats = ['Todos'];
    foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $c) {
      $c = trim((string)$c);
      if ($c !== '' && !in_array($c, $cats, true)) $cats[] = $c;
    }
    return $cats;
  } catch (Throwable $e) { return ['Todos']; }
}

function pdv_product_payload(PDO $pdo, int $companyId, array $balancaColumns, string $q = '', string $category = '', string $mode = 'instant', int $limit = 80): array {
  $q = trim($q);
  $category = trim($category);
  $mode = in_array($mode, ['instant','menu','description','barcode'], true) ? $mode : 'instant';
  product_menu_code_ensure_schema($pdo, empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']));
  $halfPizzaReady = product_half_pizza_ensure_schema($pdo, empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL']));
  if ($category === 'Todos') $category = '';
  $limit = max(10, min(120, $limit));
  if ($q === '' && $category === '') return ['products'=>[], 'options'=>[], 'pizzas'=>[]];

  [$hasProductSort, $hasCategorySort] = pdv_product_sort_columns($pdo);
  $barcodeCols = pdv_product_barcode_columns($pdo);
  $scaleSelect = !empty($balancaColumns['produto_pesavel']) ? ', COALESCE(produto_pesavel,0) AS produto_pesavel' : ', 0 AS produto_pesavel';
  $sortSelect = ($hasProductSort ? ', COALESCE(sort_order,0) AS sort_order' : ', 0 AS sort_order') . ($hasCategorySort ? ', COALESCE(category_sort_order,0) AS category_sort_order' : ', 0 AS category_sort_order') . $scaleSelect;
  $extraSelect = '';
  foreach ($barcodeCols as $bc) $extraSelect .= ', `' . str_replace('`', '``', $bc) . '`';
  $hasMenuCode = product_menu_code_column_exists($pdo);
  $menuCodeSelect = $hasMenuCode ? ', codigo_cardapio' : ', NULL AS codigo_cardapio';
  $hasReference = function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'referencia');
  $referenceSelect = $hasReference ? ', referencia' : ', NULL AS referencia';
  $descriptionSelect = (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'descricao')) ? ', descricao' : ", '' AS descricao";
  $stockSelect = (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'estoque_atual')) ? ', ' . product_composition_effective_stock_sql($pdo, $companyId) . ' AS estoque_atual' : ', 0 AS estoque_atual';
  $stockUnitSelect = (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'unidade_estoque')) ? ", COALESCE(NULLIF(unidade_estoque,''),'UN') AS unidade_estoque" : ", 'UN' AS unidade_estoque";
  $where = ['company_id = ?', 'COALESCE(ativo,1) = 1'];
  $params = [$companyId];
  if ($category !== '') { $where[] = 'categoria = ?'; $params[] = $category; }
  if ($q !== '') {
    $like = nexa_search_like($q);
    $parts = [];
    if ($mode === 'menu') {
      if ($hasMenuCode) { $parts[] = 'codigo_cardapio LIKE ?'; $params[] = $like; }
    } elseif ($mode === 'description') {
      $parts[] = 'nome LIKE ?'; $params[] = $like;
      if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'descricao')) { $parts[] = 'descricao LIKE ?'; $params[] = $like; }
    } elseif ($mode === 'barcode') {
      // Leitura/digitação de código de barras deve ser exata e rápida. Não usa ID interno.
      foreach ($barcodeCols as $bc) { $parts[] = '`' . str_replace('`', '``', $bc) . '` = ?'; $params[] = $q; }
    } else {
      // Pesquisa instantânea: código digitado consulta somente o código sequencial da empresa
      // (numero_cadastro) e códigos de barras. O ID interno do banco nunca participa da busca.
      $digitsOnly = preg_match('/^\d+$/', $q) === 1;
      if ($digitsOnly) {
        if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'numero_cadastro')) {
          $parts[] = 'numero_cadastro = ?';
          $params[] = $q;
        }
        if ($hasReference) { $parts[] = 'referencia = ?'; $params[] = $q; }
        foreach ($barcodeCols as $bc) { $parts[] = '`' . str_replace('`', '``', $bc) . '` = ?'; $params[] = $q; }
      } else {
        $parts = ['nome LIKE ?', 'categoria LIKE ?'];
        $params[] = $like; $params[] = $like;
        if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'descricao')) { $parts[] = 'descricao LIKE ?'; $params[] = $like; }
        if ($hasMenuCode) { $parts[] = 'codigo_cardapio LIKE ?'; $params[] = $like; }
        if ($hasReference) { $parts[] = 'referencia LIKE ?'; $params[] = $like; }
        // Alguns leitores/cadastros aceitam barras alfanuméricas: compara de forma exata.
        foreach ($barcodeCols as $bc) { $parts[] = '`' . str_replace('`', '``', $bc) . '` = ?'; $params[] = $q; }
      }
    }
    $where[] = $parts ? '(' . implode(' OR ', $parts) . ')' : '1 = 0';
  }
  $catOrder = $hasCategorySort ? 'CASE WHEN COALESCE(category_sort_order,0) <= 0 THEN 999999 ELSE category_sort_order END' : '999999';
  $prodOrder = $hasProductSort ? 'CASE WHEN COALESCE(sort_order,0) <= 0 THEN 999999 ELSE sort_order END' : '999999';
  $rows = [];
  try {
    $sql = "SELECT id, nome, categoria, preco, imagem, ativo{$sortSelect}{$extraSelect}{$menuCodeSelect}{$referenceSelect}{$descriptionSelect}{$stockSelect}{$stockUnitSelect}" . (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'numero_cadastro') ? ', numero_cadastro' : ', NULL AS numero_cadastro') . (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'codigo_firebird') ? ', codigo_firebird' : ', NULL AS codigo_firebird') . " FROM produtos WHERE " . implode(' AND ', $where) . " ORDER BY {$catOrder} ASC, categoria ASC, {$prodOrder} ASC, nome ASC LIMIT {$limit}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) { $rows = []; }

  $optionMap = [];
  foreach ($rows as $r) {
    $pid = (int)($r['id'] ?? 0);
    if ($pid <= 0) continue;
    try {
      $groups = product_options_load($pdo, $companyId, $pid, true);
      if (!$groups) continue;
      $optionMap[$pid] = array_map(static function(array $g): array {
        return [
          'id' => (int)$g['id'],
          'title' => (string)$g['titulo'],
          'type' => (string)($g['tipo'] ?? 'single'),
          'required' => (int)($g['obrigatorio'] ?? 0),
          'min' => max(0, (int)($g['min_escolhas'] ?? 0)),
          'max' => max(0, (int)($g['max_escolhas'] ?? 0)),
          'items' => array_map(static function(array $it): array {
            return ['id'=>(int)$it['id'], 'name'=>(string)$it['nome'], 'price'=>(float)($it['preco'] ?? 0)];
          }, (array)($g['items'] ?? [])),
        ];
      }, $groups);
    } catch (Throwable $e) {}
  }

  $halfPizzaCategories = $halfPizzaReady ? product_half_pizza_enabled_categories($pdo, $companyId) : [];
  $products = array_map(function($r) use ($optionMap, $barcodeCols, $halfPizzaCategories): array {
    $img = trim((string)($r['imagem'] ?? ''));
    $pid = (int)$r['id'];
    $categoryName = (string)($r['categoria'] ?? '');
    $isPizza = product_half_pizza_category_enabled($halfPizzaCategories, $categoryName);
    $barcode = '';
    foreach ($barcodeCols as $bc) { $barcode = trim((string)($r[$bc] ?? '')); if ($barcode !== '') break; }
    return [
      'id'=>$pid,
      'name'=>(string)$r['nome'],
      'category'=>(string)($r['categoria'] ?? ''),
      'price'=>(float)$r['preco'],
      'image'=>$img !== '' ? '/uploads/products/' . rawurlencode($img) : '/assets/no-image.svg',
      'hasOptions'=>!empty($optionMap[$pid]),
      'isPizza'=>$isPizza,
      'pesavel'=>((int)($r['produto_pesavel'] ?? 0) === 1),
      // Código exibido/pesquisável no PDV é exclusivamente o sequencial da empresa.
      // O ID interno e o código legado não são expostos como código operacional.
      'code'=>trim((string)($r['numero_cadastro'] ?? '')),
      'menuCode'=>trim((string)($r['codigo_cardapio'] ?? '')),
      'reference'=>trim((string)($r['referencia'] ?? '')),
      'description'=>(string)($r['descricao'] ?? ''),
      'barcode'=>$barcode,
      'stock'=>(float)($r['estoque_atual'] ?? 0),
      'stockUnit'=>(string)($r['unidade_estoque'] ?? 'UN'),
    ];
  }, $rows);
  $pizzaCategoriesInResult = [];
  foreach ($products as $productRow) {
    if (empty($productRow['isPizza'])) continue;
    $catName = trim((string)($productRow['category'] ?? ''));
    if ($catName !== '') $pizzaCategoriesInResult[] = $catName;
  }
  $pizzas = $halfPizzaReady ? product_half_pizza_candidates($pdo, $companyId, $pizzaCategoriesInResult) : [];
  return ['products'=>$products, 'options'=>$optionMap, 'pizzas'=>$pizzas];
}


function pdv_checkout_source_comandas_from_post(array $post): array {
  $raw = json_decode((string)($post['checkout_comanda_sources'] ?? ''), true);
  if (!is_array($raw)) return [];
  $out = [];
  foreach ($raw as $comandaIdRaw => $itemIdsRaw) {
    $comandaId = (int)$comandaIdRaw;
    if ($comandaId <= 0 || !is_array($itemIdsRaw)) continue;
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIdsRaw), static fn($id) => $id > 0)));
    if ($ids) $out[$comandaId] = $ids;
  }
  return $out;
}

function pdv_checkout_source_orcamentos_from_post(array $post): array {
  $raw = json_decode((string)($post['checkout_orcamento_sources'] ?? ''), true);
  if (!is_array($raw)) return [];
  return array_values(array_unique(array_filter(array_map('intval', $raw), static fn($id) => $id > 0)));
}


function pdv_checkout_source_os_from_post(array $post): array {
  $raw = json_decode((string)($post['checkout_os_sources'] ?? ''), true);
  if (!is_array($raw)) return [];
  $out = [];
  foreach ($raw as $osIdRaw => $itemIdsRaw) {
    $osId = (int)$osIdRaw;
    if ($osId <= 0 || !is_array($itemIdsRaw)) continue;
    $ids = array_values(array_unique(array_filter(array_map('intval', $itemIdsRaw), static fn($id) => $id > 0)));
    if ($ids) $out[$osId] = $ids;
  }
  return $out;
}

function pdv_checkout_number_in($value, int $decimals = 4): float {
  if (function_exists('gestao_quantity_in')) return (float)gestao_quantity_in($value, $decimals);
  if (is_string($value)) {
    $value = trim($value);
    if (str_contains($value, ',') && str_contains($value, '.')) $value = str_replace('.', '', $value);
    $value = str_replace(',', '.', $value);
  }
  return round((float)$value, $decimals);
}

function pdv_checkout_validate_os_sources(PDO $pdo, int $companyId, array $sources, array $post): array {
  if (!$sources) return ['orders'=>[], 'customer_id'=>0, 'requires_product'=>false, 'requires_service'=>false, 'discount'=>0.0, 'addition'=>0.0, 'total'=>0.0];
  $allExpected = [];
  $orders = [];
  $customerKeys = [];
  $discount = 0.0; $addition = 0.0; $total = 0.0;
  $requiresProduct = false; $requiresService = false;
  $hasBatchColumn = function_exists('os_schema_has_column') ? os_schema_has_column($pdo, 'os_orders', 'batch_invoice_id') : false;
  foreach ($sources as $osId => $sourceItemIds) {
    $sql = "SELECT o.*, COALESCE(s.name,'') AS status_name" . ($hasBatchColumn ? ", COALESCE(o.batch_invoice_id,0) AS batch_invoice_id" : ", 0 AS batch_invoice_id") . " FROM os_orders o LEFT JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id WHERE o.company_id=? AND o.id=? LIMIT 1";
    $st = $pdo->prepare($sql); $st->execute([$companyId, (int)$osId]);
    $order = $st->fetch(PDO::FETCH_ASSOC);
    if (!$order) throw new RuntimeException('Uma Ordem de Serviço importada não foi encontrada. Releia as O.S. antes de finalizar.');
    $label = '#' . str_pad((string)($order['os_number'] ?? $osId), 6, '0', STR_PAD_LEFT);
    if (strtoupper(trim((string)($order['billing_status'] ?? 'PENDENTE'))) !== 'PENDENTE' || (int)($order['pedido_id'] ?? 0) > 0) {
      throw new RuntimeException('A O.S. ' . $label . ' não está mais pendente para faturamento. Releia a O.S. antes de finalizar.');
    }
    if ((int)($order['batch_invoice_id'] ?? 0) > 0) throw new RuntimeException('A O.S. ' . $label . ' já está vinculada a um faturamento em lote e não pode ser importada no Checkout.');
    if ((float)($order['total_general'] ?? 0) <= 0) throw new RuntimeException('A O.S. ' . $label . ' não possui valor para faturamento.');
    $it = $pdo->prepare("SELECT id,produto_id,item_type,description_snapshot,unidade_snapshot,quantity,unit_price,total,notes FROM os_order_items WHERE company_id=? AND order_id=? ORDER BY id");
    $it->execute([$companyId, (int)$osId]);
    $items = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$items) throw new RuntimeException('A O.S. ' . $label . ' não possui itens para faturamento.');
    $dbIds = array_map(static fn(array $r): int => (int)$r['id'], $items); sort($dbIds);
    $postedIds = array_values(array_unique(array_map('intval', $sourceItemIds))); sort($postedIds);
    if ($dbIds !== $postedIds) throw new RuntimeException('Os itens da O.S. ' . $label . ' foram alterados. Releia a O.S. antes de finalizar.');
    foreach ($items as $row) {
      $type = strtoupper(trim((string)($row['item_type'] ?? 'PRODUTO')));
      $stockEffect = $type === 'SERVICO' ? 0 : 1;
      $allExpected[] = [
        'product'=>(int)$row['produto_id'],
        'qty'=>round((float)$row['quantity'],4),
        'price'=>round((float)$row['unit_price'],4),
        'stock'=>$stockEffect,
      ];
      if ($type === 'SERVICO') $requiresService = true; else $requiresProduct = true;
    }
    $customerKeys[] = (int)($order['person_id'] ?? 0);
    $discount += (float)($order['discount_amount'] ?? 0);
    $addition += (float)($order['addition_amount'] ?? 0);
    $total += (float)($order['total_general'] ?? 0);
    $orders[(int)$osId] = $order;
  }
  $customerKeys = array_values(array_unique($customerKeys));
  if (count($customerKeys) > 1) throw new RuntimeException('Para faturar várias Ordens de Serviço juntas no Checkout, todas devem pertencer ao mesmo cliente.');
  $customerId = (int)($customerKeys[0] ?? 0);
  if ((int)($post['customer_id'] ?? 0) !== $customerId) throw new RuntimeException('O cliente do Checkout não corresponde ao cliente das Ordens de Serviço importadas. Reimporte as O.S.');

  $pids=(array)($post['item_product_id'] ?? []); $qtys=(array)($post['item_qty'] ?? []); $prices=(array)($post['item_price'] ?? []); $stocks=(array)($post['item_stock_effect'] ?? []);
  $posted=[];
  $count=max(count($pids),count($qtys),count($prices));
  for($i=0;$i<$count;$i++){
    $pid=(int)($pids[$i] ?? 0); $qty=pdv_checkout_number_in($qtys[$i] ?? 0,4); $price=pdv_checkout_number_in($prices[$i] ?? 0,6);
    if($pid<=0 || $qty<=0) continue;
    $posted[]=['product'=>$pid,'qty'=>round($qty,4),'price'=>round($price,4),'stock'=>array_key_exists($i,$stocks)?((int)$stocks[$i]===1?1:0):1];
  }
  $norm = static function(array $r): string { return sprintf('%010d|%015.4f|%015.4f|%d',(int)$r['product'],(float)$r['qty'],(float)$r['price'],(int)$r['stock']); };
  $expectedKeys=array_map($norm,$allExpected); $postedKeys=array_map($norm,$posted); sort($expectedKeys); sort($postedKeys);
  if ($expectedKeys !== $postedKeys) throw new RuntimeException('Os itens do carrinho não correspondem integralmente às Ordens de Serviço importadas. Remova e releia as O.S. antes de faturar.');
  $postDiscount = function_exists('finance_money_in') ? finance_money_in($post['discount_amount'] ?? 0) : pdv_checkout_number_in($post['discount_amount'] ?? 0,2);
  $postAddition = function_exists('finance_money_in') ? finance_money_in($post['addition_amount'] ?? 0) : pdv_checkout_number_in($post['addition_amount'] ?? 0,2);
  $postFreight = function_exists('finance_money_in') ? finance_money_in($post['delivery_fee'] ?? 0) : pdv_checkout_number_in($post['delivery_fee'] ?? 0,2);
  if (abs($postDiscount-round($discount,2))>0.01 || abs($postAddition-round($addition,2))>0.01 || abs($postFreight)>0.01) {
    throw new RuntimeException('Desconto, acréscimo ou frete foram alterados após importar a Ordem de Serviço. Reimporte a O.S. para continuar.');
  }
  return ['orders'=>$orders,'customer_id'=>$customerId,'requires_product'=>$requiresProduct,'requires_service'=>$requiresService,'discount'=>round($discount,2),'addition'=>round($addition,2),'total'=>round($total,2)];
}

function pdv_checkout_finalize_os_sources_after_order(PDO $pdo, int $companyId, int $pedidoId, int $userId, array $sources, string $productDocument, string $serviceDocument): array {
  if (!$sources) return ['queue_ids'=>[], 'warnings'=>[]];
  $productDocument = strtoupper(trim($productDocument));
  $serviceDocument = strtoupper(trim($serviceDocument));
  if (!in_array($productDocument, ['', 'NFE','NFCE'], true)) throw new RuntimeException('Documento de produtos inválido para as Ordens de Serviço.');
  if (!in_array($serviceDocument, ['', 'NFSE'], true)) throw new RuntimeException('Documento de serviços inválido para as Ordens de Serviço.');
  $pdo->beginTransaction();
  try {
    foreach ($sources as $osId => $itemIds) {
      $lock=$pdo->prepare("SELECT id,os_number,billing_status,pedido_id,requires_product_fiscal,requires_service_fiscal FROM os_orders WHERE company_id=? AND id=? FOR UPDATE");
      $lock->execute([$companyId,(int)$osId]); $o=$lock->fetch(PDO::FETCH_ASSOC);
      if(!$o) throw new RuntimeException('O.S. não encontrada ao vincular o pedido.');
      if(strtoupper((string)$o['billing_status'])!=='PENDENTE' || (int)$o['pedido_id']>0) throw new RuntimeException('A O.S. #'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).' mudou de situação antes do vínculo com o pedido.');
      os_release_order_reservations($pdo,$companyId,(int)$osId);
      $pdo->prepare("UPDATE os_orders SET billing_status='FATURADA',pedido_id=?,billed_at=NOW(),product_document_type=?,service_document_type=?,updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$pedidoId,$productDocument!==''?$productDocument:null,$serviceDocument!==''?$serviceDocument:null,$companyId,(int)$osId]);
    }
    $pdo->commit();
  } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
  $queueIds=[]; $warnings=[];
  foreach(array_keys($sources) as $osId){
    try{
      $st=$pdo->prepare("SELECT requires_product_fiscal,requires_service_fiscal,os_number FROM os_orders WHERE company_id=? AND id=? LIMIT 1");$st->execute([$companyId,(int)$osId]);$o=$st->fetch(PDO::FETCH_ASSOC)?:[];
      if($productDocument!=='' && !empty($o['requires_product_fiscal'])){ $q=os_fiscal_queue_enqueue($pdo,$companyId,(int)$osId,$productDocument,$userId); if((int)($q['queue_id']??0)>0)$queueIds[]=(int)$q['queue_id']; }
      if($serviceDocument!=='' && !empty($o['requires_service_fiscal'])){ $q=os_fiscal_queue_enqueue($pdo,$companyId,(int)$osId,'NFSE',$userId); if((int)($q['queue_id']??0)>0)$queueIds[]=(int)$q['queue_id']; }
    }catch(Throwable $e){ $warnings[]='O.S. #'.str_pad((string)($o['os_number']??$osId),6,'0',STR_PAD_LEFT).': '.$e->getMessage(); error_log('[Nexa V11.38.3] Pedido #'.$pedidoId.' salvo; falha ao enfileirar fiscal da O.S. #'.$osId.': '.$e->getMessage()); }
  }
  return ['queue_ids'=>array_values(array_unique($queueIds)),'warnings'=>$warnings];
}

function pdv_checkout_validate_comanda_sources(PDO $pdo, int $companyId, array $sources): void {
  foreach ($sources as $comandaId => $itemIds) {
    if (!$itemIds) continue;
    $ph = implode(',', array_fill(0, count($itemIds), '?'));
    $params = array_merge([$companyId, (int)$comandaId], $itemIds);
    $st = $pdo->prepare("SELECT id FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO' AND id IN ($ph)");
    $st->execute($params);
    $openIds = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    sort($openIds); $expected = array_values($itemIds); sort($expected);
    if ($openIds !== $expected) {
      $cmd = comanda_find($pdo, $companyId, (int)$comandaId);
      $label = trim((string)($cmd['codigo'] ?? $comandaId));
      throw new RuntimeException('A comanda ' . $label . ' foi alterada em outro terminal. Releia a comanda antes de finalizar.');
    }
  }
}

function pdv_checkout_close_comanda_sources(PDO $pdo, int $companyId, int $orderId, array $sources): void {
  if (!$sources) return;
  $ownTx = !$pdo->inTransaction();
  if ($ownTx) $pdo->beginTransaction();
  try {
    foreach ($sources as $comandaId => $itemIds) {
      if (!$itemIds) continue;
      $ph = implode(',', array_fill(0, count($itemIds), '?'));
      $params = array_merge([$orderId, $companyId, (int)$comandaId], $itemIds);
      $pdo->prepare("UPDATE comanda_items SET status='FECHADO', pedido_id=?, updated_at=NOW() WHERE company_id=? AND comanda_id=? AND status='ABERTO' AND id IN ($ph)")->execute($params);
      $remaining = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, (int)$comandaId])['qtd'] ?? 0);
      if ($remaining <= 0) {
        $settings = comanda_settings($pdo, $companyId);
        $clearName = !empty($settings['prompt_name_first_launch']);
        $sql = $clearName
          ? "UPDATE comandas SET status='LIVRE', nome=NULL, cliente_id=NULL, opened_at=NULL, closed_at=NOW(), last_order_id=?, public_session_version=COALESCE(public_session_version,1)+1, updated_at=NOW() WHERE company_id=? AND id=?"
          : "UPDATE comandas SET status='LIVRE', cliente_id=NULL, opened_at=NULL, closed_at=NOW(), last_order_id=?, public_session_version=COALESCE(public_session_version,1)+1, updated_at=NOW() WHERE company_id=? AND id=?";
        $pdo->prepare($sql)->execute([$orderId, $companyId, (int)$comandaId]);
      } else {
        $pdo->prepare("UPDATE comandas SET status='OCUPADA', last_order_id=?, updated_at=NOW() WHERE company_id=? AND id=?")->execute([$orderId, $companyId, (int)$comandaId]);
      }
    }
    if ($ownTx) $pdo->commit();
  } catch (Throwable $e) {
    if ($ownTx && $pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array((string)($_GET['action'] ?? ''), ['pdv_search_comandas','pdv_load_comandas'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (!$comandaReadEnabled) throw new RuntimeException('Leitura de comandas não está disponível para este usuário/empresa.');
    $action = (string)($_GET['action'] ?? '');
    if ($action === 'pdv_search_comandas') {
      $q = trim((string)($_GET['q'] ?? ''));
      $rows = comanda_rows($pdo, $companyId, true);
      $tokens = array_values(array_filter(preg_split('/\s+/u', mb_strtolower($q, 'UTF-8')) ?: []));
      $rows = array_values(array_filter($rows, static function(array $row) use ($tokens): bool {
        if ((int)($row['itens_abertos'] ?? 0) <= 0) return false;
        if (!$tokens) return true;
        $hay = mb_strtolower(implode(' ', [
          (string)($row['codigo'] ?? ''), (string)($row['matricula'] ?? ''), (string)($row['nome'] ?? ''), (string)($row['cliente_nome'] ?? '')
        ]), 'UTF-8');
        foreach ($tokens as $token) if (!str_contains($hay, $token)) return false;
        return true;
      }));
      $rows = array_slice($rows, 0, 120);
      echo json_encode(['ok'=>true,'rows'=>array_map(static fn(array $r): array => [
        'id'=>(int)$r['id'], 'codigo'=>(string)($r['codigo'] ?? ''), 'matricula'=>(string)($r['matricula'] ?? ''),
        'nome'=>(string)($r['nome'] ?? ''), 'cliente_id'=>(int)($r['cliente_id'] ?? 0), 'cliente_nome'=>(string)($r['cliente_nome'] ?? ''),
        'total'=>(float)($r['total_aberto'] ?? 0), 'itens'=>(int)($r['itens_abertos'] ?? 0), 'last_activity_at'=>(string)($r['last_activity_at'] ?? '')
      ], $rows)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', preg_split('/\s*,\s*/', (string)($_GET['ids'] ?? '')) ?: []), static fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('Selecione ao menos uma comanda.');
    if (count($ids) > 30) throw new RuntimeException('Selecione no máximo 30 comandas por leitura.');
    $out = [];
    foreach ($ids as $id) {
      $cmd = comanda_find($pdo, $companyId, $id);
      if (!$cmd) continue;
      $items = comanda_items($pdo, $companyId, $id, true);
      if (!$items) continue;
      $out[] = [
        'comanda'=>['id'=>$id,'codigo'=>(string)($cmd['codigo'] ?? ''),'matricula'=>(string)($cmd['matricula'] ?? ''),'nome'=>(string)($cmd['nome'] ?? ''),'cliente_id'=>(int)($cmd['cliente_id'] ?? 0)],
        'items'=>array_map(static fn(array $it): array => [
          'id'=>(int)$it['id'],'produto_id'=>(int)$it['produto_id'],'codigo_produto'=>(($n=trim((string)($it['numero_cadastro'] ?? '')))!==''&&$n!=='0'?$n:(trim((string)($it['codigo_firebird'] ?? ''))!==''?(string)$it['codigo_firebird']:(string)$it['produto_id'])),'nome'=>(string)($it['nome'] ?? 'Item'),'qtd'=>(float)($it['qtd'] ?? 0),
          'preco_unit'=>(float)($it['preco_unit'] ?? 0),'total'=>(float)($it['total'] ?? 0),'observacao'=>(string)($it['observacao'] ?? '')
        ], $items)
      ];
    }
    echo json_encode(['ok'=>true,'comandas'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  exit;
}


if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array((string)($_GET['action'] ?? ''), ['pdv_search_os','pdv_load_os'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (!$osReadEnabled) throw new RuntimeException('Leitura de Ordem de Serviço não está disponível para este usuário/empresa.');
    $action=(string)($_GET['action'] ?? '');
    $hasBatchColumn=function_exists('os_schema_has_column') ? os_schema_has_column($pdo,'os_orders','batch_invoice_id') : false;
    $batchWhere=$hasBatchColumn ? ' AND COALESCE(o.batch_invoice_id,0)=0' : '';
    if($action==='pdv_search_os'){
      $q=trim((string)($_GET['q'] ?? ''));
      $where="o.company_id=? AND UPPER(COALESCE(o.billing_status,'PENDENTE'))='PENDENTE' AND COALESCE(o.pedido_id,0)=0 AND COALESCE(o.total_general,0)>0".$batchWhere;
      $params=[$companyId];
      if($q!==''){
        $like=nexa_search_like($q);
        $where.=" AND (CAST(o.os_number AS CHAR) LIKE ? OR COALESCE(o.person_name_snapshot,'') LIKE ? OR COALESCE(o.person_document_snapshot,'') LIKE ? OR COALESCE(o.title,'') LIKE ? OR COALESCE(o.reported_problem,'') LIKE ?)";
        array_push($params,$like,$like,$like,$like,$like);
      }
      $sql="SELECT o.id,o.os_number,o.person_id,o.person_name_snapshot,o.person_document_snapshot,o.total_products,o.total_services,o.discount_amount,o.addition_amount,o.total_general,o.requires_product_fiscal,o.requires_service_fiscal,o.opened_at,COALESCE(s.name,'') AS status_name,(SELECT COUNT(*) FROM os_order_items oi WHERE oi.company_id=o.company_id AND oi.order_id=o.id) AS item_count FROM os_orders o LEFT JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id WHERE $where ORDER BY o.opened_at DESC,o.id DESC LIMIT 120";
      $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
      echo json_encode(['ok'=>true,'rows'=>array_map(static fn(array $r):array=>[
        'id'=>(int)$r['id'],'os_number'=>(int)$r['os_number'],'person_id'=>(int)($r['person_id']??0),'person_name_snapshot'=>(string)($r['person_name_snapshot']??''),'person_document_snapshot'=>(string)($r['person_document_snapshot']??''),'status_name'=>(string)($r['status_name']??''),'item_count'=>(int)($r['item_count']??0),'total_products'=>(float)($r['total_products']??0),'total_services'=>(float)($r['total_services']??0),'discount_amount'=>(float)($r['discount_amount']??0),'addition_amount'=>(float)($r['addition_amount']??0),'total_general'=>(float)($r['total_general']??0),'requires_product'=>(int)($r['requires_product_fiscal']??0),'requires_service'=>(int)($r['requires_service_fiscal']??0),'opened_at'=>(string)($r['opened_at']??'')
      ],$rows)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    $ids=array_values(array_unique(array_filter(array_map('intval',preg_split('/\s*,\s*/',(string)($_GET['ids']??''))?:[]),static fn($id)=>$id>0)));
    if(!$ids) throw new RuntimeException('Selecione ao menos uma Ordem de Serviço.');
    if(count($ids)>20) throw new RuntimeException('Selecione no máximo 20 Ordens de Serviço por faturamento.');
    $out=[];
    foreach($ids as $id){
      $sql="SELECT o.*".($hasBatchColumn?",COALESCE(o.batch_invoice_id,0) AS batch_invoice_id":",0 AS batch_invoice_id")." FROM os_orders o WHERE o.company_id=? AND o.id=? LIMIT 1";
      $st=$pdo->prepare($sql);$st->execute([$companyId,$id]);$o=$st->fetch(PDO::FETCH_ASSOC);
      if(!$o) throw new RuntimeException('O.S. não encontrada.');
      $label='#'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT);
      if(strtoupper((string)$o['billing_status'])!=='PENDENTE' || (int)($o['pedido_id']??0)>0) throw new RuntimeException('A O.S. '.$label.' não está disponível para faturamento.');
      if((int)($o['batch_invoice_id']??0)>0) throw new RuntimeException('A O.S. '.$label.' já pertence a um faturamento em lote.');
      $osProductCodeSelect = (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'numero_cadastro')) ? 'p.numero_cadastro' : 'NULL AS numero_cadastro';
      $osFirebirdCodeSelect = (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'produtos', 'codigo_firebird')) ? 'p.codigo_firebird' : 'NULL AS codigo_firebird';
      $it=$pdo->prepare("SELECT oi.id,oi.produto_id,oi.item_type,oi.description_snapshot,oi.unidade_snapshot,oi.quantity,oi.unit_price,oi.total,oi.notes,{$osProductCodeSelect},{$osFirebirdCodeSelect} FROM os_order_items oi LEFT JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id WHERE oi.company_id=? AND oi.order_id=? ORDER BY oi.id");$it->execute([$companyId,$id]);$items=$it->fetchAll(PDO::FETCH_ASSOC)?:[];
      foreach($items as &$osItem){$n=trim((string)($osItem['numero_cadastro']??''));$osItem['codigo_produto']=($n!==''&&$n!=='0')?$n:(trim((string)($osItem['codigo_firebird']??''))!==''?(string)$osItem['codigo_firebird']:(string)$osItem['produto_id']);}unset($osItem);
      if(!$items) throw new RuntimeException('A O.S. '.$label.' não possui itens.');
      $customer=null;
      if((int)($o['person_id']??0)>0){$cs=$pdo->prepare("SELECT id,name,fantasy_name,document,email,phone,whatsapp,zip_code,address,number,complement,district,city,state FROM finance_people WHERE company_id=? AND id=? LIMIT 1");$cs->execute([$companyId,(int)$o['person_id']]);$customer=$cs->fetch(PDO::FETCH_ASSOC)?:null;}
      $out[]=['order'=>['id'=>$id,'os_number'=>(int)$o['os_number'],'person_id'=>(int)($o['person_id']??0),'person_name_snapshot'=>(string)($o['person_name_snapshot']??''),'person_document_snapshot'=>(string)($o['person_document_snapshot']??''),'discount_amount'=>(float)($o['discount_amount']??0),'addition_amount'=>(float)($o['addition_amount']??0),'total_products'=>(float)($o['total_products']??0),'total_services'=>(float)($o['total_services']??0),'total_general'=>(float)($o['total_general']??0),'requires_product'=>(int)($o['requires_product_fiscal']??0),'requires_service'=>(int)($o['requires_service_fiscal']??0)],'items'=>array_map(static fn(array $i):array=>['id'=>(int)$i['id'],'produto_id'=>(int)$i['produto_id'],'item_type'=>(string)$i['item_type'],'description_snapshot'=>(string)$i['description_snapshot'],'unidade_snapshot'=>(string)($i['unidade_snapshot']??'UN'),'quantity'=>(float)$i['quantity'],'unit_price'=>(float)$i['unit_price'],'total'=>(float)$i['total'],'notes'=>(string)($i['notes']??'')],$items),'customer'=>$customer];
    }
    echo json_encode(['ok'=>true,'orders'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'error'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);} exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['create_customer_pdv', 'create_customer_address_pdv'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($pdvCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize o PDV e tente novamente.');
    }
    if (($_POST['action'] ?? '') === 'create_customer_pdv') {
      $result = gestao_create_customer($pdo, $companyId, $_POST);
      echo json_encode(['ok' => true] + $result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    $personId = (int)($_POST['person_id'] ?? 0);
    $makeDefault = !empty($_POST['is_default']);
    $addrId = gestao_create_customer_address($pdo, $companyId, $personId, $_POST, $makeDefault);
    echo json_encode(['ok' => true, 'address_id' => $addrId, 'addresses' => gestao_customer_addresses($pdo, $companyId, $personId)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok' => false, 'error' => $e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array(($_POST['action'] ?? ''), ['pix_checkout_create','pix_checkout_status'], true)) {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  try {
    if ($pdvCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize o PDV e tente novamente.');
    }
    if (!$pixCheckoutReady) {
      throw new RuntimeException('O módulo Pix automático ainda não está preparado nesta empresa. Abra a empresa principal e salve a configuração da forma de pagamento Pix.');
    }
    $pixAction = (string)($_POST['action'] ?? '');
    if ($pixAction === 'pix_checkout_create') {
      $paymentMethodId = (int)($_POST['payment_method_id'] ?? 0);
      $amount = pix_checkout_parse_money($_POST['amount'] ?? '0');
      if ($paymentMethodId <= 0) throw new RuntimeException('Selecione a forma de pagamento Pix.');
      if ($amount <= 0) throw new RuntimeException('Informe um valor válido para o Pix.');
      $openCash = gestao_open_cash_session($pdo, $companyId, $userId);
      $charge = pix_checkout_create_charge($pdo, $companyId, $userId, $paymentMethodId, $amount, (int)($openCash['id'] ?? 0) ?: null);
      echo json_encode(['ok'=>true,'charge'=>pix_checkout_public_charge($charge)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
      exit;
    }
    $chargeId = (int)($_POST['charge_id'] ?? 0);
    if ($chargeId <= 0) throw new RuntimeException('Cobrança Pix não informada.');
    $charge = pix_checkout_sync_charge($pdo, $companyId, $chargeId);
    echo json_encode(['ok'=>true,'charge'=>pix_checkout_public_charge($charge)], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && ($_GET['action'] ?? '') === 'pdv_search_products') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    try { $pdvBalancaColumns = $pdvCrossContext ? ['produto_pesavel' => (function_exists('balanca_column_exists') && balanca_column_exists($pdo, 'produtos', 'produto_pesavel'))] : balanca_ensure_schema($pdo); } catch (Throwable $ignoreScale) { $pdvBalancaColumns = ['produto_pesavel' => false]; }
    $payload = pdv_product_payload($pdo, $companyId, $pdvBalancaColumns, (string)($_GET['q'] ?? ''), (string)($_GET['category'] ?? ''), (string)($_GET['mode'] ?? 'instant'), 80);
    echo json_encode(['ok'=>true] + $payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'error'=>$e->getMessage(), 'products'=>[], 'options'=>[], 'pizzas'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'finalize_pdv') {
  // Segurança adicional para a Venda Mobile: mesmo que algum script do navegador
  // não sincronize os campos visíveis com os campos ocultos, preserva os dados
  // digitados do consumidor não cadastrado antes de gravar o pedido.
  if ((int)($_POST['customer_id'] ?? 0) <= 0) {
    $manualConsumerName = trim((string)($_POST['final_consumer_name'] ?? ''));
    $manualConsumerPhone = preg_replace('/\D+/', '', (string)($_POST['final_consumer_phone'] ?? ''));
    if ($manualConsumerName !== '') $_POST['customer_name'] = $manualConsumerName;
    if ($manualConsumerPhone !== '') $_POST['whatsapp'] = $manualConsumerPhone;
  }
  try {
    if ($pdvCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize o PDV e tente novamente.');
    }
    if ($saleFinalizationBlocked) {
      throw new RuntimeException('Este perfil está configurado para não permitir finalizar a venda.');
    }
    $checkoutOsSources = pdv_checkout_source_os_from_post($_POST);
    $checkoutOsProductDocument = strtoupper(trim((string)($_POST['checkout_os_product_document'] ?? '')));
    $checkoutOsServiceDocument = strtoupper(trim((string)($_POST['checkout_os_service_document'] ?? '')));
    $checkoutOsMeta = [];
    if ($checkoutOsSources) {
      if (!$osReadEnabled) throw new RuntimeException('A origem por Ordem de Serviço não está liberada para este usuário/empresa.');
      $checkoutOsMeta = pdv_checkout_validate_os_sources($pdo, $companyId, $checkoutOsSources, $_POST);
      if (!in_array($checkoutOsProductDocument, ['', 'NFE','NFCE'], true)) throw new RuntimeException('Documento de produtos inválido para a Ordem de Serviço.');
      if (!in_array($checkoutOsServiceDocument, ['', 'NFSE'], true)) throw new RuntimeException('Documento de serviços inválido para a Ordem de Serviço.');
      if ($checkoutOsProductDocument !== '' && empty($osFiscalOptions[$checkoutOsProductDocument])) throw new RuntimeException($checkoutOsProductDocument . ' não está habilitada nas Configurações Fiscais da empresa.');
      if ($checkoutOsServiceDocument !== '' && empty($osFiscalOptions['NFSE'])) throw new RuntimeException('NFS-e não está habilitada nas Configurações Fiscais da empresa.');
      if (empty($checkoutOsMeta['requires_product']) && $checkoutOsProductDocument !== '') throw new RuntimeException('As O.S. importadas não possuem produtos para o documento fiscal selecionado.');
      if (empty($checkoutOsMeta['requires_service']) && $checkoutOsServiceDocument !== '') throw new RuntimeException('As O.S. importadas não possuem serviços para NFS-e.');
      $_POST['emit_doc_after'] = 'none';
    }
    $requestedCheckoutOption = strtolower(trim((string)($_POST['emit_doc_after'] ?? 'none')));
    if ($pdvCrossContext) {
      if ($requestedCheckoutOption === '') $requestedCheckoutOption = 'none';
      if (!in_array($requestedCheckoutOption, ['none','nfce','nfe'], true)) {
        throw new RuntimeException('V11.46 Multiempresa: no F11 somente NFC-e/NF-e em HOMOLOGAÇÃO ou Somente gravar pedido estão liberados. NFS-e continua bloqueada.');
      }
      if ($requestedCheckoutOption === 'nfce' && !$pdvNfceHomologationF11) {
        if (function_exists('fiscal_multi_company_nfce_homologation_assert')) {
          fiscal_multi_company_nfce_homologation_assert($pdo, $companyId, 'emitir');
        }
        throw new RuntimeException('V11.46 Multiempresa: a NFC-e da empresa selecionada não está habilitada em HOMOLOGAÇÃO ou o perfil não possui permissão fiscal.');
      }
      if ($requestedCheckoutOption === 'nfe' && !$pdvNfeHomologationF11) {
        if (function_exists('fiscal_multi_company_nfe_homologation_assert')) {
          fiscal_multi_company_nfe_homologation_assert($pdo, $companyId, 'emitir');
        }
        throw new RuntimeException('V11.46 Multiempresa: a NF-e da empresa selecionada não está habilitada em HOMOLOGAÇÃO ou o perfil não possui permissão fiscal.');
      }
      $_POST['emit_doc_after'] = $requestedCheckoutOption;
      $fiscalCfgPost = $pdvFiscalCfgF11 ?: ['order_auto_print_enabled'=>1, 'order_print_disabled'=>0];
    } else {
      $fiscalCfgPost = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
      if (!$checkoutOsSources && (!function_exists('fiscal_checkout_option_enabled') || !fiscal_checkout_option_enabled($fiscalCfgPost, $requestedCheckoutOption))) {
        throw new RuntimeException('A opção de finalização selecionada não está habilitada nas Configurações Fiscais.');
      }
    }
    $checkoutComandaSources = pdv_checkout_source_comandas_from_post($_POST);
    if ($checkoutComandaSources) {
      if (!$comandaReadEnabled) throw new RuntimeException('A origem por comanda não está liberada para este usuário/empresa.');
      pdv_checkout_validate_comanda_sources($pdo, $companyId, $checkoutComandaSources);
    }
    $checkoutOrcamentoSources = pdv_checkout_source_orcamentos_from_post($_POST);
    $legacyOrcamentoId = (int)($_POST['orcamento_id'] ?? 0);
    if ($legacyOrcamentoId > 0) $checkoutOrcamentoSources[] = $legacyOrcamentoId;
    $checkoutOrcamentoSources = array_values(array_unique(array_filter(array_map('intval', $checkoutOrcamentoSources), static fn($id) => $id > 0)));
    if ($checkoutOsSources && ($checkoutComandaSources || $checkoutOrcamentoSources)) {
      throw new RuntimeException('Ordens de Serviço devem ser faturadas em uma venda exclusiva. Remova comandas/orçamentos e releia as O.S.');
    }

    $verifiedPixCharges = [];
    $postUsesAutoPix = false;
    if (pix_checkout_column_exists($pdo, 'finance_payment_methods', 'pix_auto_qr_enabled')) {
      foreach ((array)($_POST['payment_method_id'] ?? []) as $postedPaymentMethodId) {
        $postedPixMethod = pix_checkout_method_config($pdo, $companyId, (int)$postedPaymentMethodId);
        if ($postedPixMethod && !empty($postedPixMethod['pix_auto_qr_enabled'])) { $postUsesAutoPix = true; break; }
      }
    }
    if ($postUsesAutoPix) {
      if (!$pixCheckoutReady) throw new RuntimeException('O Pix automático está habilitado, mas a estrutura de confirmação bancária não está disponível nesta empresa.');
      $verifiedPixCharges = pix_checkout_validate_post_payments($pdo, $companyId, $_POST);
    }

    $deliveryControlPrep = ['active'=>false,'requested_doc'=>'none'];
    if (!$checkoutOsSources && $checkoutOrcamentoSources && function_exists('delivery_control_prepare_order_post')) {
      if (count($checkoutOrcamentoSources) === 1) {
        $_POST['orcamento_id'] = (int)$checkoutOrcamentoSources[0];
        $deliveryControlPrep = delivery_control_prepare_order_post($pdo,$companyId,$_POST);
      } else {
        foreach ($checkoutOrcamentoSources as $futureQuoteId) {
          if (function_exists('delivery_control_quote_future_rows') && delivery_control_quote_future_rows($pdo,$companyId,(int)$futureQuoteId)) {
            throw new RuntimeException('Orçamentos com entrega futura devem ser finalizados individualmente. Importe apenas um orçamento por pedido.');
          }
        }
      }
    }
    if (!empty($deliveryControlPrep['active'])) $requestedCheckoutOption = 'none';
    $orderId = gestao_finalize_pdv_order($pdo, $companyId, $userId, $_POST, (bool)$checkoutOsSources);
    if ($verifiedPixCharges) {
      try { pix_checkout_link_order($pdo, $companyId, $orderId, $verifiedPixCharges); }
      catch (Throwable $pixLinkError) { error_log('[Nexa V11.38.0] Pedido #' . $orderId . ' salvo, mas não foi possível vincular a cobrança Pix: ' . $pixLinkError->getMessage()); }
    }
    if ($checkoutComandaSources) {
      try { pdv_checkout_close_comanda_sources($pdo, $companyId, $orderId, $checkoutComandaSources); }
      catch (Throwable $cmdCloseError) { error_log('[Nexa V11.37.7] Pedido #' . $orderId . ' salvo, mas não foi possível fechar as comandas importadas: ' . $cmdCloseError->getMessage()); }
    }
    if ($checkoutOrcamentoSources && function_exists('orc_bridge_release_reservations_after_order')) {
      foreach ($checkoutOrcamentoSources as $orcamentoOrigemId) {
        try { orc_bridge_release_reservations_after_order($pdo, $companyId, $orcamentoOrigemId, $userId, $orderId, (string)($deliveryControlPrep['requested_doc'] ?? 'none')); }
        catch (Throwable $orcBridgeError) { error_log('[Nexa V11.37.7] Pedido #' . $orderId . ' salvo, mas não foi possível liberar a reserva do orçamento #' . $orcamentoOrigemId . ': ' . $orcBridgeError->getMessage()); }
      }
    }
    $checkoutOsFinalizeResult = ['queue_ids'=>[], 'warnings'=>[]];
    $checkoutOsFinalizeError = '';
    if ($checkoutOsSources) {
      try {
        $checkoutOsFinalizeResult = pdv_checkout_finalize_os_sources_after_order($pdo, $companyId, $orderId, $userId, $checkoutOsSources, $checkoutOsProductDocument, $checkoutOsServiceDocument);
      } catch (Throwable $osLinkError) {
        $checkoutOsFinalizeError = $osLinkError->getMessage();
        error_log('[Nexa V11.38.3] Pedido #' . $orderId . ' salvo, mas não foi possível concluir o vínculo das O.S.: ' . $osLinkError->getMessage());
      }
    }
    $orderRow = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $orderId]) ?: ['id' => $orderId];
    $orderNumber = company_sequence_display($orderRow, 'pedidos');
    $autoPrintPedidoPost = (!array_key_exists('order_auto_print_enabled', $fiscalCfgPost) || !empty($fiscalCfgPost['order_auto_print_enabled'])) ? '1' : '0';
    $orderPrintDisabledPost = !empty($fiscalCfgPost['order_print_disabled']);
    $bizPost = function_exists('load_business_config') ? load_business_config($companyId) : [];
    $eventSalesEnabledPost = $pdvMobileMode && !empty($bizPost['event_sales']['enabled']);
    $pdvReturnUrlPost = $pdvContextUrl($pdvBaseUrl);
    $pedidoPrintUrlPost = $pdvContextUrl('pedido_print.php?id=' . $orderId . '&return_to=' . rawurlencode($pdvReturnUrlPost) . '&autoprint=' . $autoPrintPedidoPost);
    $eventPrintUrlPost = $pdvContextUrl('pedido_print_evento.php?id=' . $orderId . '&return_to=' . rawurlencode($pdvReturnUrlPost) . '&auto=1');
    $pedidoDoneUrlPost = $pdvContextUrl($pdvBaseUrl . '?ok=' . rawurlencode('Pedido #' . $orderNumber . ' salvo com sucesso.'));
    $finalPrintUrlPost = $eventSalesEnabledPost
      ? $eventPrintUrlPost
      : ($orderPrintDisabledPost ? $pedidoDoneUrlPost : $pedidoPrintUrlPost);
    if ($checkoutOsSources) {
      if ($checkoutOsFinalizeError !== '') {
        header('Location: ' . $pdvContextUrl($pdvBaseUrl . '?err=' . rawurlencode('Pedido #' . $orderNumber . ' foi salvo, mas o vínculo com a O.S. precisa ser conferido: ' . $checkoutOsFinalizeError)));
        exit;
      }
      $osQueueIds = array_values(array_unique(array_filter(array_map('intval', (array)($checkoutOsFinalizeResult['queue_ids'] ?? [])), static fn($id) => $id > 0)));
      $osWarnings = array_values(array_filter(array_map('strval', (array)($checkoutOsFinalizeResult['warnings'] ?? []))));
      if ($osQueueIds) {
        $url = 'os_fiscal_processar_checkout.php?jobs=' . rawurlencode(implode(',', $osQueueIds)) . '&pedido_id=' . $orderId . '&return_to=' . rawurlencode($pdvReturnUrlPost);
        if ($osWarnings) $url .= '&warn=' . rawurlencode(implode(' | ', $osWarnings));
        header('Location: ' . $pdvContextUrl($url));
        exit;
      }
      if ($osWarnings) {
        header('Location: ' . $pdvContextUrl($pdvBaseUrl . '?err=' . rawurlencode('Pedido #' . $orderNumber . ' e O.S. faturados, porém houve pendência fiscal: ' . implode(' | ', $osWarnings))));
        exit;
      }
      header('Location: ' . $finalPrintUrlPost);
      exit;
    }
    $emitAfter = $pdvCrossContext
      ? (($requestedCheckoutOption === 'nfce' && $pdvNfceHomologationF11)
          ? 'nfce'
          : (($requestedCheckoutOption === 'nfe' && $pdvNfeHomologationF11) ? 'nfe' : ''))
      : (in_array($requestedCheckoutOption, ['nfce','nfe','nfse'], true) ? $requestedCheckoutOption : '');
    if ($emitAfter === 'nfse') {
      try {
        $nfseResult = nfse_quick_authorize_order($pdo, $companyId, $orderId);
        $nfseDocId = nfse_quick_document_id($pdo, $companyId, $orderId, $nfseResult);
        if ($nfseDocId <= 0) throw new RuntimeException('A NFS-e foi processada, mas o documento fiscal não pôde ser localizado.');
        $nfseNumber = trim((string)($nfseResult['nfse_number'] ?? $nfseResult['document']['nfse_number'] ?? ''));
        $nfseMessage = 'Pedido #' . $orderNumber . ' salvo e NFS-e autorizada' . ($nfseNumber !== '' ? ' nº ' . $nfseNumber : '') . '.';
        header('Location: ' . $pdvContextUrl('fiscal_nfse_nacional.php?pedido_id=' . $orderId . '&source=pdv_checkout&return_to=' . rawurlencode($pdvReturnUrlPost) . '&ok=' . rawurlencode($nfseMessage)));
        exit;
      } catch (Throwable $fiscalError) {
        $fiscalMessage = 'Pedido #' . $orderNumber . ' salvo, mas a emissão da NFS-e retornou: ' . $fiscalError->getMessage();
        $sep = str_contains($finalPrintUrlPost, '?') ? '&' : '?';
        header('Location: ' . $finalPrintUrlPost . $sep . 'err=' . rawurlencode($fiscalMessage));
        exit;
      }
    }
    if ($emitAfter !== '') {
      try {
        $result = $emitAfter === 'nfe'
          ? fiscal_nfe_authorize_order($pdo, $companyId, $orderId)
          : fiscal_nfce_authorize_order($pdo, $companyId, $orderId);
        $docId = (int)($result['doc_id'] ?? 0);
        if ($docId <= 0) {
          $doc = $emitAfter === 'nfe'
            ? fiscal_document_order_document($pdo, $companyId, $orderId, '55')
            : fiscal_document_order_document($pdo, $companyId, $orderId, '65');
          $docId = (int)($doc['id'] ?? 0);
        }
        if ($docId > 0) {
          $returnTo = $pdvReturnUrlPost;
          header('Location: ' . $pdvContextUrl('fiscal_nfce_imprimir.php?doc_id=' . $docId . '&source=pdv_checkout&return_to=' . rawurlencode($returnTo)));
          exit;
        }
        header('Location: ' . $finalPrintUrlPost);
        exit;
      } catch (Throwable $fiscalError) {
        $fiscalMessage = 'Pedido #' . $orderNumber . ' salvo, mas a emissão fiscal retornou: ' . $fiscalError->getMessage();
        if ($eventSalesEnabledPost) {
          header('Location: ' . $eventPrintUrlPost . '&err=' . rawurlencode($fiscalMessage));
        } elseif ($orderPrintDisabledPost) {
          header('Location: ' . $pdvContextUrl($pdvBaseUrl . '?err=' . rawurlencode($fiscalMessage)));
        } else {
          header('Location: ' . $pedidoPrintUrlPost . '&err=' . rawurlencode($fiscalError->getMessage()));
        }
        exit;
      }
    }
    header('Location: ' . $finalPrintUrlPost);
    exit;
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}

$customerRows = gestao_customer_rows($pdo, $companyId);
$customerAddressesRows = gestao_customer_addresses($pdo, $companyId);
$sellers = finance_people_options($pdo, $companyId, 'seller');
$commercialUserRules = commercial_user_rules($pdo, $companyId, $userId);
$defaultSellerId = (int)($commercialUserRules['default_seller_id'] ?? 0);
$allowSellerChange = !empty($commercialUserRules['allow_seller_change']);
$maxDiscountPercent = $commercialUserRules['max_discount_percent'];
$creditOpenMap = commercial_credit_open_map($pdo, $companyId, array_column($customerRows, 'id'));
$banks = finance_bank_options($pdo, $companyId);
$charts = finance_chart_options($pdo, $companyId, 'CREDITO');
$costs = finance_cost_center_options($pdo, $companyId);
$payments = gestao_payment_methods($pdo, $companyId, true);
$paymentMap = [];
foreach ($payments as $p) {
  $paymentMap[(int)$p['id']] = [
    'name'=>(string)$p['name'],
    'type'=>(string)$p['type'],
    'bank'=>(int)($p['default_bank_account_id'] ?? 0),
    'chart'=>(int)($p['default_chart_account_id'] ?? 0),
    'cost'=>(int)($p['default_cost_center_id'] ?? 0),
    'receivable'=>(!empty($p['card_control_enabled']) ? 0 : (int)($p['generate_receivable'] ?? 0)),
    'allowInstallments'=>(int)($p['allow_installments'] ?? 0),
    'defaultInstallments'=>(int)($p['default_installments'] ?? 1),
    'firstDueDays'=>(int)($p['first_due_days'] ?? 30),
    'keepDueDay'=>(int)($p['keep_due_day'] ?? 1),
    'creRulesEnabled'=>(int)($p['cre_rules_enabled'] ?? 0),
    'periodDays'=>(int)($p['cre_installment_period_days'] ?? 30),
    'fixedDueDay'=>isset($p['cre_fixed_due_day']) ? (int)$p['cre_fixed_due_day'] : 0,
    'requiresCash'=>(int)($p['require_open_cash'] ?? 0),
    'allowChange'=>(int)($p['allow_change'] ?? 0),
    'pixAutoQr'=>(int)($p['pix_auto_qr_enabled'] ?? 0),
    'pixProvider'=>(string)($p['pix_provider'] ?? ''),
    'pixExpiration'=>(int)($p['pix_expiration_seconds'] ?? 300),
  ];
}
$paymentShortcuts = [];
foreach (array_values($payments) as $paymentIndex => $paymentRow) {
  $paymentShortcuts[] = [
    'key' => (string)($paymentIndex + 1),
    'id' => (int)$paymentRow['id'],
    'name' => (string)$paymentRow['name'],
  ];
}
$cashSession = gestao_open_cash_session($pdo, $companyId, $userId);
$biz = load_business_config($companyId);
$checkoutLogoUrl = '';
$checkoutLogoCfg = (array)($biz['pdv_checkout']['logo'] ?? []);
$checkoutLogoFile = basename(trim((string)($checkoutLogoCfg['file'] ?? '')));
if ($checkoutLogoFile !== '' && (!array_key_exists('enabled', $checkoutLogoCfg) || !empty($checkoutLogoCfg['enabled']))) {
  $checkoutLogoPath = __DIR__ . '/../uploads/logos/' . $checkoutLogoFile;
  if (is_file($checkoutLogoPath)) $checkoutLogoUrl = '/uploads/logos/' . rawurlencode($checkoutLogoFile);
}
if ($checkoutLogoUrl === '' && function_exists('nexa_print_logo_url')) $checkoutLogoUrl = nexa_print_logo_url($companyId);
$checkoutOperatorName = 'Operador';
try {
  $checkoutUser = finance_query_one($pdo, 'SELECT name, email FROM users WHERE id = ? LIMIT 1', [$userId]);
  if ($checkoutUser) {
    $checkoutOperatorName = trim((string)($checkoutUser['name'] ?? '')) ?: trim((string)($checkoutUser['email'] ?? '')) ?: 'Operador';
  }
} catch (Throwable $e) {}
$checkoutCashNumber = $cashSession ? (int)($cashSession['id'] ?? 0) : 0;
$checkoutCashOpenedAt = $cashSession ? trim((string)($cashSession['opened_at'] ?? '')) : '';

try { $balancaColumns = $pdvCrossContext ? ['produto_pesavel' => (function_exists('balanca_column_exists') && balanca_column_exists($pdo, 'produtos', 'produto_pesavel'))] : balanca_ensure_schema($pdo); } catch (Throwable $e) { $balancaColumns = ['produto_pesavel' => false]; }
$scaleCfg = balanca_config($pdo, $companyId);
$deliveryFees = (array)($biz['delivery_fees']['bairros'] ?? []);
$deliveryFeeDefault = (float)($biz['delivery_fees']['default'] ?? 0);
$deliveryScheduling = (array)($biz['delivery_scheduling'] ?? []);
$deliverySchedulingEnabled = !empty($deliveryScheduling['enabled']);
$deliverySchedulingLabel = trim((string)($deliveryScheduling['label'] ?? 'Data/Hora Prevista'));
if ($deliverySchedulingLabel === '') $deliverySchedulingLabel = 'Data/Hora Prevista';
$fiscalCfgPdv = $pdvCrossContext
  ? $pdvFiscalCfgF11
  : (function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : []);
$pdvCheckoutOptions = $pdvCrossContext
  ? [
      'nfce'=>(!$pdvMobileMode && $pdvNfceHomologationF11),
      'nfe'=>(!$pdvMobileMode && $pdvNfeHomologationF11),
      'nfse'=>false,
      'none'=>true,
    ]
  : (function_exists('fiscal_checkout_options') ? fiscal_checkout_options($fiscalCfgPdv) : ['nfce'=>false,'nfe'=>false,'nfse'=>false,'none'=>true]);
$pdvCanEmitNfce = !empty($pdvCheckoutOptions['nfce']);
$pdvCanEmitNfe = !empty($pdvCheckoutOptions['nfe']);
$pdvCanEmitNfse = !empty($pdvCheckoutOptions['nfse']);
$pdvCanSaveOrder = !empty($pdvCheckoutOptions['none']);
$pdvFiscalChoiceCount = (int)$pdvCanEmitNfce + (int)$pdvCanEmitNfe + (int)$pdvCanEmitNfse + (int)$pdvCanSaveOrder;
$pdvFiscalChoiceCount = max(1, min(4, $pdvFiscalChoiceCount));
$pdvFiscalModalDescription = $pdvCrossContext
  ? ($pdvMobileMode
      ? 'V11.46 Multiempresa: Venda Mobile continua somente como Pedido nesta etapa; os testes de NFC-e/NF-e em HOMOLOGAÇÃO são feitos pelo Pedido PDV padrão.'
      : (($pdvNfceHomologationF11 || $pdvNfeHomologationF11)
          ? 'V11.46 Multiempresa: os documentos fiscais habilitados (NFC-e e/ou NF-e) estão liberados somente em HOMOLOGAÇÃO. NFS-e e produção continuam bloqueadas.'
          : 'Multiempresa: nenhuma opção fiscal foi liberada. Confira abaixo o diagnóstico da empresa selecionada; no F11 esta etapa aceita somente HOMOLOGAÇÃO.'))
  : ($pdvCanSaveOrder
  ? 'Escolha se deseja emitir agora ou apenas gravar o pedido.'
  : ($pdvFiscalChoiceCount > 1 ? 'Escolha o documento fiscal que deseja emitir.' : 'Confirme o documento fiscal que deseja emitir.'));
$fiscalEmissionEnabled = !$saleFinalizationBlocked && ($pdvCanEmitNfce || $pdvCanEmitNfe || $pdvCanEmitNfse || $pdvCanSaveOrder);
$deliverySchedulingMinMinutes = max(0, (int)($deliveryScheduling['min_minutes'] ?? 0));
$productOptionsCfg = (array)($biz['product_options'] ?? []);
$showAdditionalUnitTotal = !empty($productOptionsCfg['show_additional_unit_total']);
$showItemTotalWithAdditionals = !empty($productOptionsCfg['show_item_total_with_additionals']);
$itemNoteInstruction = trim((string)($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...'));
$orderTypesCfg = (array)($biz['order_types'] ?? []);
$pdvOrderTypesCfg = isset($orderTypesCfg['pdv']) && is_array($orderTypesCfg['pdv']) ? (array)$orderTypesCfg['pdv'] : $orderTypesCfg;
$pdvOrderTypes = [];
$pdvOrderTypeLabels = ['balcao' => 'Balcão', 'retirada' => 'Retirada', 'entrega' => 'Entrega'];
foreach ($pdvOrderTypeLabels as $key => $label) {
  if (!array_key_exists($key, $pdvOrderTypesCfg) || !empty($pdvOrderTypesCfg[$key])) {
    $pdvOrderTypes[$key] = $label;
  }
}
if (!$pdvOrderTypes) {
  $pdvOrderTypes = ['balcao' => 'Balcão'];
}

$products = [];
$categories = pdv_product_category_list($pdo, $companyId);
$productJson = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pizzaJson = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$productOptionsJson = json_encode([], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$scaleJson = json_encode(balanca_public_config($scaleCfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$paymentJson = json_encode($paymentMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$paymentShortcutsJson = json_encode($paymentShortcuts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customerJson = json_encode(array_map(static function(array $c) use ($creditOpenMap): array {
  return [
    'id' => (int)$c['id'],
    'name' => (string)$c['name'],
    'fantasy_name' => (string)($c['fantasy_name'] ?? ''),
    'document' => preg_replace('/\D+/', '', (string)($c['document'] ?? '')),
    'person_type' => (string)($c['person_type'] ?? 'FISICA'),
    'email' => (string)($c['email'] ?? ''),
    'phone' => preg_replace('/\D+/', '', (string)($c['phone'] ?? '')),
    'whatsapp' => preg_replace('/\D+/', '', (string)($c['whatsapp'] ?? '')),
    'zip_code' => (string)($c['zip_code'] ?? ''),
    'address' => (string)($c['address'] ?? ''),
    'number' => (string)($c['number'] ?? ''),
    'complement' => (string)($c['complement'] ?? ''),
    'district' => (string)($c['district'] ?? ''),
    'city' => (string)($c['city'] ?? ''),
    'state' => (string)($c['state'] ?? ''),
    'credit_limit' => $c['credit_limit'] === null ? null : (float)$c['credit_limit'],
    'credit_open' => (float)($creditOpenMap[(int)$c['id']] ?? 0),
  ];
}, $customerRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$customerAddressesJson = json_encode(array_map(static function(array $a): array {
  return [
    'id' => (int)$a['id'],
    'person_id' => (int)$a['person_id'],
    'label' => (string)($a['label'] ?? 'Entrega'),
    'zip_code' => (string)($a['zip_code'] ?? ''),
    'address' => (string)($a['address'] ?? ''),
    'number' => (string)($a['number'] ?? ''),
    'complement' => (string)($a['complement'] ?? ''),
    'reference' => (string)($a['reference'] ?? ''),
    'district' => (string)($a['district'] ?? ''),
    'city' => (string)($a['city'] ?? ''),
    'state' => (string)($a['state'] ?? ''),
    'is_default' => (int)($a['is_default'] ?? 0),
  ];
}, $customerAddressesRows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$deliveryFeesJson = json_encode($deliveryFees, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$deliveryDefaultJson = json_encode($deliveryFeeDefault, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// Venda Mobile e Pedido PDV usam o menu compacto em qualquer tamanho de tela.
// Assim o operador sempre tem o botão ☰ para abrir/fechar o menu do sistema,
// tanto no celular quanto no computador.
$GLOBALS['admin_compact_mobile_mode'] = true;
$GLOBALS['admin_compact_mobile_show_menu'] = true;
gestao_admin_start('PDV Checkout', 'gestao_pdv_checkout');
echo '<style>.topbar,.admin-footer{display:none!important}body .container,body.admin-side-menu-enabled .container,body.admin-compact-mobile .container,body.admin-compact-side-menu .container{max-width:none!important;width:100%!important;margin:0!important;padding:8px 14px 24px!important}body.admin-compact-mobile-menu .admin-compact-menu-toggle{left:10px!important;right:auto!important}body.admin-compact-side-menu .pdv-wizard-shell{padding-left:54px}@media(max-width:760px){body.admin-compact-side-menu .pdv-wizard-shell{padding-left:0;padding-top:52px}}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-search-advanced{grid-template-columns:195px minmax(440px,1fr) auto 170px}
.pdv-search-mode-label{display:grid;gap:6px;font-size:12px;font-weight:900;color:#475569}
.pdv-search-mode-label select{min-height:46px}
.pdv-product-entry{display:flex;gap:8px;align-items:end;min-width:0}
.pdv-product-entry label{display:grid;gap:6px;min-width:0;flex:1}
.pdv-prepared-qty{min-width:72px;height:46px;border:0;border-radius:14px;background:#0b1f5b;color:#fff;font-weight:950;font-size:17px;cursor:pointer;box-shadow:0 8px 20px rgba(11,31,91,.18)}
.pdv-prepared-qty[hidden]{display:none!important}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-search-advanced{align-items:end}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-client-search{align-items:end}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-client-search>label{display:grid;gap:6px;min-width:0;margin:0}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-client-search input,
.pdv-wizard-form:not(.pdv-mobile-mode) #customerSearchBtn,
.pdv-wizard-form:not(.pdv-mobile-mode) #openCustomerModalBtn{height:46px;min-height:46px;box-sizing:border-box;margin:0;align-self:end}
.pdv-wizard-form:not(.pdv-mobile-mode) #customerSearchBtn,
.pdv-wizard-form:not(.pdv-mobile-mode) #openCustomerModalBtn{display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;padding-inline:18px}
.pdv-wizard-form:not(.pdv-mobile-mode) .pdv-product-entry{align-items:flex-end}
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvProductSearchMode,
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvSearch,
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvPreparedQty,
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvProductSearchBtn{height:46px;min-height:46px;box-sizing:border-box;margin:0}
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvPreparedQty,
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvProductSearchBtn{position:relative;top:-6px}
.pdv-wizard-form:not(.pdv-mobile-mode) #pdvProductSearchBtn{align-self:end;padding-inline:18px;white-space:nowrap}
.pdv-product-modal-searchbar{display:grid;grid-template-columns:210px minmax(0,1fr) auto;gap:10px;align-items:end;margin-bottom:14px}
.pdv-product-modal-searchbar label{display:grid;gap:6px;color:#475569;font-size:12px;font-weight:900;min-width:0}
.pdv-product-modal-searchbar select,.pdv-product-modal-searchbar input,.pdv-product-modal-searchbar button{height:46px;min-height:46px;box-sizing:border-box}
.pdv-product-modal-results{border:1px solid #dbe4f0;border-radius:18px;overflow:auto;max-height:55vh;background:#fff}
.pdv-product-modal-table{width:100%;border-collapse:collapse;min-width:760px}
.pdv-product-modal-table th{position:sticky;top:0;z-index:1;background:#f8fafc;color:#475569;font-size:12px;text-align:left;padding:11px 12px;border-bottom:1px solid #dbe4f0}
.pdv-product-modal-table td{padding:11px 12px;border-bottom:1px solid #edf2f7;color:#1e293b;vertical-align:middle}
.pdv-product-modal-table tr:last-child td{border-bottom:0}
.pdv-product-modal-table tbody tr{cursor:pointer;transition:background .12s ease,box-shadow .12s ease}
.pdv-product-modal-table tbody tr:hover{background:#f8fbff}
.pdv-product-modal-table tbody tr.is-active{background:#eaf2ff;box-shadow:inset 4px 0 0 #2563eb}
.pdv-product-modal-table tbody tr.is-active td{color:#0b1f5b}
.pdv-product-modal-code{font-weight:900;color:#0b1f5b;white-space:nowrap}
.pdv-product-modal-code small,.pdv-product-modal-name small{display:block;color:#64748b;font-size:11px;font-weight:700;margin-top:3px}
.pdv-product-modal-name strong{display:block;color:#0b1f5b}
.pdv-product-modal-stock{font-weight:900;white-space:nowrap}
.pdv-product-modal-price{font-weight:950;color:#047857;white-space:nowrap}
.pdv-product-modal-select{white-space:nowrap}
.pdv-product-modal-empty{padding:30px 16px;text-align:center;color:#64748b}
@media(max-width:760px){.pdv-product-modal-searchbar{grid-template-columns:1fr}.pdv-product-modal-results{max-height:48vh}}
.pdv-mobile-mode .pdv-search-advanced{grid-template-columns:1fr!important}
.pdv-mobile-mode .pdv-search-mode-label{font-size:11px}
.pdv-mobile-mode .pdv-search-mode-label select{height:52px;border-radius:18px}
.pdv-mobile-mode .pdv-product-entry{align-items:stretch}
.pdv-mobile-mode .pdv-prepared-qty{height:56px;min-width:74px;border-radius:18px}
@media(max-width:980px){.pdv-search-advanced{grid-template-columns:1fr}.pdv-search-advanced .pdv-mini-summary{display:none}}

</style>';
if ($erro) echo '<div class="msg err">' . gestao_h($erro) . '</div>';
gestao_flash();
?>
<?php if ($pdvCrossContext): ?>
  <?php if (!$pdvMobileMode && ($pdvNfceHomologationF11 || $pdvNfeHomologationF11)): ?>
    <div class="msg ok"><strong>V11.46 · Fiscal Multiempresa em HOMOLOGAÇÃO.</strong> O Pedido PDV pode gravar a venda e transmitir NFC-e modelo 65 ou NF-e modelo 55 usando exclusivamente configuração, série e certificado da empresa selecionada pelo F11. <strong>Produção e NFS-e continuam bloqueadas.</strong> O estoque já é baixado na venda e não será baixado novamente pela emissão fiscal.</div>
    <?php if ($pdvFiscalStatusF11 !== ''): ?><div class="msg" style="background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;"><strong>Diagnóstico fiscal F11:</strong> <?= gestao_h($pdvFiscalStatusF11) ?></div><?php endif; ?>
  <?php elseif ($pdvMobileMode): ?>
    <div class="msg ok"><strong>V11.27/V11.46 · Venda Mobile Multiempresa.</strong> A Venda Mobile continua operacional na empresa selecionada, mas nesta etapa fiscal grava somente o Pedido. Para testar NFC-e ou NF-e em HOMOLOGAÇÃO, use o Pedido PDV padrão.</div>
  <?php else: ?>
    <div class="msg ok"><strong>Pedido PDV Multiempresa.</strong> Esta venda será gravada na empresa selecionada pelo F11. Para liberar emissão fiscal nesta etapa, a empresa selecionada precisa estar em HOMOLOGAÇÃO, com emissão fiscal ativa e NFC-e e/ou NF-e habilitada.</div>
    <?php if ($pdvFiscalStatusF11 !== ''): ?><div class="msg" style="background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;"><strong>Diagnóstico fiscal F11:</strong> <?= gestao_h($pdvFiscalStatusF11) ?></div><?php endif; ?>
  <?php endif; ?>
<?php endif; ?>
<?php if (!$cashSession && !nexa_cash_user_control_disabled($pdo, $companyId)): ?>
  <div class="msg err">Caixa do usuário fechado. <?= $pdvCrossContext ? 'Para usar formas que exigem caixa, <a href="' . gestao_h($pdvContextUrl('gestao_caixa.php')) . '">abra o Caixa da empresa selecionada</a>.' : 'Vendas em dinheiro exigem abertura do caixa. <a href="gestao_caixa.php">Abrir caixa</a>' ?></div>
<?php endif; ?>
<?php if ($saleFinalizationBlocked): ?>
  <div class="msg" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;">Este perfil está configurado com <strong>Não permitir finalizar a venda</strong>. A montagem e conferência do pedido continuam disponíveis, mas o fechamento está bloqueado.</div>
<?php endif; ?>


<style id="nexa-admin-flow-theme">
/* Cores independentes para o passo a passo das telas PDV. */
.pdv-step-nav span{
  background:color-mix(in srgb,var(--admin-flow-primary) 11%,#fff)!important;
  color:var(--admin-flow-primary)!important;
}
.pdv-step-nav.active{
  border-color:var(--admin-flow-secondary)!important;
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
  box-shadow:0 18px 42px color-mix(in srgb,var(--admin-flow-secondary) 24%,transparent)!important;
}
.pdv-step-nav.active span{background:rgba(255,255,255,.18)!important;color:var(--admin-flow-text)!important;}
.pdv-step-nav.active strong,.pdv-step-nav.active small{color:var(--admin-flow-text)!important;}
.pdv-mobile-mode .pdv-stepper{
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
}
.pdv-mobile-mode .pdv-step-nav,
.pdv-mobile-mode .pdv-step-nav span,
.pdv-mobile-mode .pdv-step-nav strong{color:var(--admin-flow-text)!important;}
.pdv-mobile-mode .pdv-step-nav.active span{
  background:linear-gradient(135deg,var(--admin-flow-primary),var(--admin-flow-secondary))!important;
  color:var(--admin-flow-text)!important;
}
.pdv-mobile-mode .pdv-step-nav.active strong{color:var(--admin-flow-primary)!important;}
</style>

<form method="post" id="pdvForm" class="pdv-wizard-form pdv-checkout-mode">
  <input type="hidden" name="action" value="finalize_pdv">
  <?php if ($pdvCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($pdvMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= gestao_h($pdvMcCtx) ?>"><?php endif; ?>
  <input type="hidden" name="credit_override_user_code" id="creditOverrideUserCode" value=""><input type="hidden" name="credit_override_password" id="creditOverridePassword" value="">
  <?php if ($pdvMobileMode): ?><input type="hidden" name="mobile" value="1"><?php endif; ?>
  <input type="hidden" name="emit_doc_after" id="emitDocAfter" value="">
  <input type="hidden" name="orcamento_id" id="orcamentoId" value="">
  <input type="hidden" name="checkout_comanda_sources" id="checkoutComandaSources" value="{}">
  <input type="hidden" name="checkout_orcamento_sources" id="checkoutOrcamentoSources" value="[]">
  <input type="hidden" name="checkout_os_sources" id="checkoutOsSources" value="{}">
  <input type="hidden" name="checkout_os_product_document" id="checkoutOsProductDocument" value="">
  <input type="hidden" name="checkout_os_service_document" id="checkoutOsServiceDocument" value="">
  <input type="hidden" name="customer_id" id="customerId" value="">
  <input type="hidden" name="customer_name" id="customerName" value="Consumidor final">
  <input type="hidden" name="whatsapp" id="customerWhatsapp" value="">
  <input type="hidden" name="address" id="orderAddress" value="">
  <input type="hidden" name="zip_code" id="orderZipCode" value="">
  <input type="hidden" name="customer_address_id" id="orderCustomerAddressId" value="">
  <input type="hidden" name="address_number" id="orderAddressNumber" value="">
  <input type="hidden" name="address_complement" id="orderAddressComplement" value="">
  <input type="hidden" name="district" id="orderDistrict" value="">
  <input type="hidden" name="address_reference" id="orderAddressReference" value="">
  <input type="hidden" name="city" id="orderCity" value="">
  <input type="hidden" name="state" id="orderState" value="">

  <header class="checkout-topbar" aria-label="Cabeçalho do PDV Checkout">
    <div class="checkout-title"><span class="checkout-cart-icon" aria-hidden="true">🛒</span><strong>PDV | VENDA</strong></div>
    <div class="checkout-session-meta">
      <span>Caixa: <b><?= $checkoutCashNumber > 0 ? str_pad((string)$checkoutCashNumber, 3, '0', STR_PAD_LEFT) : 'FECHADO' ?></b></span>
      <i></i>
      <span>Abertura: <b><?= $checkoutCashOpenedAt !== '' ? gestao_h(date('d/m/Y H:i', strtotime($checkoutCashOpenedAt))) : '--' ?></b></span>
      <i></i>
      <span>Operador: <b><?= gestao_h($checkoutOperatorName) ?></b></span>
      <i></i>
      <span class="checkout-seller-meta" title="Vendedor selecionado para a venda">Vendedor: <b id="checkoutSellerHeaderName">Não informado</b></span>
      <i></i>
      <span id="checkoutClock"><?= gestao_h(date('d/m/Y H:i')) ?></span>
    </div>
    <div class="checkout-nexa-brand"><img src="/assets/logo-nexa-v6-1.png" alt="Nexa Próspero"></div>
  </header>

  <div class="pdv-wizard-shell">
    <div class="pdv-stepper" role="tablist" aria-label="Etapas do pedido PDV">
      <button type="button" class="pdv-step-nav active" data-step="cliente"><span>1</span><strong>Cliente</strong><small>F2 · Pesquisa</small></button>
      <?php if ($pdvMobileMode): ?>
        <button type="button" class="pdv-step-nav" data-step="endereco"><span>2</span><strong>Endereço</strong><small>Entrega</small></button>
        <button type="button" class="pdv-step-nav" data-step="produtos"><span>3</span><strong>Produtos</strong><small>Itens</small></button>
        <button type="button" class="pdv-step-nav" data-step="carrinho"><span>4</span><strong>Carrinho</strong><small>Conferir</small></button>
        <button type="button" class="pdv-step-nav" data-step="pagamento"><span>5</span><strong><?= $saleFinalizationBlocked ? 'Conferência' : 'Pagamento' ?></strong><small><?= $saleFinalizationBlocked ? 'Sem fechamento' : 'F5 · Finalizar' ?></small></button>
      <?php else: ?>
        <button type="button" class="pdv-step-nav" data-step="produtos"><span>2</span><strong>Produtos</strong><small>F3 · Adicione os itens</small></button>
        <button type="button" class="pdv-step-nav" data-step="carrinho"><span>3</span><strong>Carrinho</strong><small>F4 · Revise valores</small></button>
        <button type="button" class="pdv-step-nav" data-step="pagamento"><span>4</span><strong><?= $saleFinalizationBlocked ? 'Conferência' : 'Pagamento' ?></strong><small><?= $saleFinalizationBlocked ? 'Sem fechamento' : 'F5 · Finalizar' ?></small></button>
      <?php endif; ?>
    </div>

    <div class="pdv-quick-summary mg-card">
      <div><span>Cliente</span><strong id="quickCustomerView">Consumidor final</strong></div>
      <div><span>Itens</span><strong id="pdvItemsCount">0</strong></div>
      <div><span>Total</span><strong id="wizardTotalView">R$ 0,00</strong></div>
      <button type="button" class="btn btn-primary pdv-next" data-next="<?= $pdvMobileMode ? 'endereco' : 'produtos' ?>"><?= $pdvMobileMode ? 'Continuar' : 'Produtos' ?></button>
    </div>
  </div>

  <section class="pdv-step-panel active" data-step-panel="cliente">
    <div class="mg-card pdv-panel-card">
      <div class="pdv-section-head">
        <div class="pdv-brand-head">
          <img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Próspero" class="pdv-brand-logo">
          <div><h2>PDV Nexa Próspero</h2><p class="pdv-mobile-brand-subtitle">Venda rápida e inteligente</p></div>
        </div>
        <div class="mg-actions"><?php if ($orcamentoReadEnabled): ?><button type="button" class="btn" onclick="openOrcamentoReader()">Ler Orçamento</button><?php endif; ?><button type="button" class="btn" id="useFinalConsumerBtn">Consumidor final</button><button type="button" class="btn btn-primary pdv-next" data-next="<?= $pdvMobileMode ? 'endereco' : 'produtos' ?>"><?= $pdvMobileMode ? 'Próximo: Endereço' : 'Próximo: Produtos' ?></button></div>
      </div>

      <?php if (!$pdvMobileMode): ?>
      <div class="pdv-manual-consumer-card">
        <div class="mg-form-grid two pdv-final-consumer-grid">
          <label>Nome do consumidor não cadastrado
            <input name="final_consumer_name" id="finalConsumerNameInput" placeholder="Ex.: Maria, João da Silva" maxlength="120" autocomplete="name">
          </label>
          <label>Telefone/WhatsApp do consumidor não cadastrado
            <input name="final_consumer_phone" id="customerWhatsappInput" placeholder="Ex.: 31999999999" maxlength="30" inputmode="tel" autocomplete="tel">
          </label>
        </div>
      </div>
      <?php endif; ?>

      <div class="pdv-client-grid">
        <div class="pdv-client-main">
          <div class="pdv-client-search">
            <label>Cliente
              <input type="search" id="customerLookup" placeholder="Pesquise CPF, telefone ou nome" inputmode="search" autocomplete="off">
            </label>
            <button type="button" class="btn" id="customerSearchBtn" title="Pesquisar clientes (F8)">Buscar <small style="font-size:10px;opacity:.72;">F8</small></button>
            <button type="button" class="btn btn-primary" id="openCustomerModalBtn">+ Cadastrar cliente</button>
          </div>

          <?php if ($pdvMobileMode): ?>
            <div class="mg-form-grid two pdv-mobile-manual-consumer-fields">
              <label>Nome do consumidor não cadastrado
                <input name="final_consumer_name" id="finalConsumerNameInput" placeholder="Digite o nome" maxlength="120" autocomplete="name">
              </label>
              <label>Telefone/WhatsApp do consumidor não cadastrado
                <input name="final_consumer_phone" id="customerWhatsappInput" placeholder="Digite o telefone/WhatsApp" maxlength="30" inputmode="tel" autocomplete="tel">
              </label>
            </div>
            <div class="pdv-customer-card" id="customerResult" hidden></div>
          <?php else: ?>
            <div class="pdv-customer-card" id="customerResult">
              <div class="pdv-customer-icon">CF</div>
              <div>
                <strong>Consumidor final</strong>
                <small>Não será gravado cadastro de pessoa. Informe um CPF/telefone apenas se quiser localizar ou cadastrar cliente.</small>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($pdvMobileMode): ?>
            <div class="pdv-mobile-list-head">
              <strong>Clientes encontrados</strong>
              <small>Toque para selecionar. A busca aceita nome, telefone, WhatsApp, CPF ou CNPJ.</small>
            </div>
            <div class="pdv-mobile-client-list" id="mobileCustomerList"></div>
          <?php endif; ?>

          <div class="mg-form-grid two pdv-mobile-order-fields" style="margin-top:14px;">
            <label>Tipo do pedido
              <select name="order_type" id="orderType">
                <?php foreach ($pdvOrderTypes as $typeKey => $typeLabel): ?>
                  <option value="<?= gestao_h($typeKey) ?>"><?= gestao_h($typeLabel) ?></option>
                <?php endforeach; ?>
              </select>
            </label>
            <label>Vendedor
              <?php if (!$allowSellerChange): ?><input type="hidden" name="seller_id" value="<?= (int)$defaultSellerId ?>"><?php endif; ?>
              <select name="seller_id" <?= !$allowSellerChange ? 'disabled' : '' ?>>
                <?php gestao_select_options($sellers, $defaultSellerId ?: null, 'Sem vendedor', false); ?>
              </select>
              <?php if (!$allowSellerChange): ?><small class="mg-muted">Vendedor definido pelo usuário.</small><?php endif; ?>
            </label>
            <div id="checkoutImportedSellerInfo" hidden role="status" style="grid-column:1/-1;padding:6px 10px;border:1px solid #c9d7e9;border-radius:6px;font-size:13px;color:#234b75;"></div>
            <?php if ($deliverySchedulingEnabled): ?>
              <label><?= gestao_h($deliverySchedulingLabel) ?> - Data
                <input type="date" name="scheduled_delivery_date" id="scheduledDeliveryDate">
              </label>
              <label><?= gestao_h($deliverySchedulingLabel) ?> - Hora
                <input type="time" name="scheduled_delivery_time" id="scheduledDeliveryTime">
              </label>
              <div class="mg-muted" style="align-self:end;font-size:12px;"><?= $deliverySchedulingMinMinutes > 0 ? 'Mínimo: ' . gestao_h(gestao_minutes_label($deliverySchedulingMinMinutes)) . ' após o pedido.' : '' ?></div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$pdvMobileMode): ?>
        <aside class="pdv-address-box">
          <div class="pdv-address-head">
            <div><strong>Endereço de entrega</strong><small>Usado para calcular frete/taxa por bairro.</small></div>
            <button type="button" class="btn" id="openAddressModalBtn" disabled>+ Endereço</button>
          </div>
          <label>Endereço cadastrado
            <select id="customerAddressSelect" disabled>
              <option value="">Consumidor final / endereço avulso</option>
            </select>
          </label>
          <div class="mg-form-grid pdv-address-grid" style="margin-top:10px;">
            <label>CEP<input id="deliveryZip" placeholder="CEP" inputmode="numeric" autocomplete="postal-code"></label>
            <label>Bairro<input id="deliveryDistrict" list="deliveryDistrictList" placeholder="Bairro" autocomplete="address-level2"></label>
            <label class="span-2">Endereço<input id="deliveryAddress" placeholder="Rua/Avenida" autocomplete="address-line1"></label>
            <label>Número<input id="deliveryNumber" placeholder="Nº" autocomplete="address-line2"></label>
            <label>Complemento<input id="deliveryComplement" placeholder="Apto, sala, bloco..."></label>
            <label class="span-2">Referência<input id="deliveryReference" placeholder="Ponto de referência para entrega"></label>
          </div>
          <datalist id="deliveryDistrictList">
            <?php foreach (array_keys($deliveryFees) as $bairroNome): ?>
              <option value="<?= gestao_h((string)$bairroNome) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <div class="pdv-fee-card">
            <span>Taxa/frete calculado</span>
            <strong id="clientDeliveryFeeView">R$ 0,00</strong>
          </div>
        </aside>
        <?php endif; ?>
      </div>
    </div>
  </section>


  <?php if ($pdvMobileMode): ?>
  <section class="pdv-step-panel" data-step-panel="endereco">
    <div class="mg-card pdv-panel-card pdv-address-step-card">
      <div class="pdv-section-head">
        <div><h2>Confirmar endereço</h2><p class="mg-muted">Confira endereço, bairro e taxa de entrega antes de adicionar os produtos.</p></div>
        <div class="mg-actions"><button type="button" class="btn pdv-prev" data-prev="cliente">Voltar</button><button type="button" class="btn btn-primary pdv-next" data-next="produtos">Próximo: Produtos</button></div>
      </div>
      <div class="pdv-address-box">
          <div class="pdv-address-head">
            <div><strong>Endereço de entrega</strong><small>Usado para calcular frete/taxa por bairro.</small></div>
            <button type="button" class="btn" id="openAddressModalBtn" disabled>+ Endereço</button>
          </div>
          <label>Endereço cadastrado
            <select id="customerAddressSelect" disabled>
              <option value="">Consumidor final / endereço avulso</option>
            </select>
          </label>
          <div class="mg-form-grid pdv-address-grid" style="margin-top:10px;">
            <label>CEP<input id="deliveryZip" placeholder="CEP" inputmode="numeric" autocomplete="postal-code"></label>
            <label>Bairro<input id="deliveryDistrict" list="deliveryDistrictList" placeholder="Bairro" autocomplete="address-level2"></label>
            <label class="span-2">Endereço<input id="deliveryAddress" placeholder="Rua/Avenida" autocomplete="address-line1"></label>
            <label>Número<input id="deliveryNumber" placeholder="Nº" autocomplete="address-line2"></label>
            <label>Complemento<input id="deliveryComplement" placeholder="Apto, sala, bloco..."></label>
            <label class="span-2">Referência<input id="deliveryReference" placeholder="Ponto de referência para entrega"></label>
          </div>
          <datalist id="deliveryDistrictList">
            <?php foreach (array_keys($deliveryFees) as $bairroNome): ?>
              <option value="<?= gestao_h((string)$bairroNome) ?>"></option>
            <?php endforeach; ?>
          </datalist>
          <div class="pdv-fee-card">
            <span>Taxa/frete calculado</span>
            <strong id="clientDeliveryFeeView">R$ 0,00</strong>
          </div>
      </div>
    </div>
  </section>
  <?php endif; ?>

  <section class="pdv-step-panel" data-step-panel="produtos">
    <div class="mg-card pdv-panel-card">
      <div class="pdv-section-head">
        <div><h2>Produtos</h2><p class="mg-muted">Busque, filtre por categoria e toque no produto para adicionar ao pedido.</p></div>
        <div class="mg-actions"><button type="button" class="btn pdv-prev" data-prev="<?= $pdvMobileMode ? 'endereco' : 'cliente' ?>">Voltar</button><button type="button" class="btn btn-primary pdv-next" data-next="carrinho">Próximo: Carrinho</button></div>
      </div>
      <div class="checkout-logo-panel">
        <?php if ($checkoutLogoUrl !== ''): ?>
          <img src="<?= gestao_h($checkoutLogoUrl) ?>" alt="Logo da empresa">
        <?php else: ?>
          <div class="checkout-logo-placeholder"><strong>Logo da empresa</strong><small>Configure a Logo do PDV Checkout em Parâmetros.</small></div>
        <?php endif; ?>
      </div>
      <div class="pdv-search pdv-search-advanced">
        <label class="pdv-search-mode-label">Pesquisar por
          <select id="pdvProductSearchMode" class="field">
            <option value="instant">Pesquisa instantânea</option>
            <option value="menu">Código do cardápio</option>
            <option value="description">Descrição</option>
            <option value="barcode">Código de barras</option>
          </select>
        </label>
        <div class="pdv-product-entry">
          <button type="button" id="pdvPreparedQty" class="pdv-prepared-qty" hidden title="Clique para voltar à quantidade 1">1 ×</button>
          <label>CÓDIGO DO PRODUTO (F8)
            <input id="pdvSearch" type="search" placeholder="Passe o código de barras, digite o código ou pesquise" autocomplete="off">
          </label>
        </div>
        <button type="button" class="btn" id="pdvProductSearchBtn" title="Pesquisar produtos (F8)">Buscar <small style="font-size:10px;opacity:.72;">F8</small></button>
        <div class="pdv-mini-summary"><span>Cliente</span><strong id="productCustomerView">Consumidor final</strong></div>
      </div>
      <div class="checkout-current-item" id="checkoutCurrentItem">
        <strong id="checkoutLastItemName">Aguardando produto...</strong>
        <span id="checkoutLastItemCalc">Digite, leia o código de barras ou pressione F8.</span>
      </div>
      <div class="checkout-metrics">
        <div class="checkout-metric checkout-metric-items"><span>QTD. ITENS</span><strong id="checkoutItemsCount">0</strong></div>
        <div class="checkout-metric checkout-metric-total"><span>SUBTOTAL</span><strong id="checkoutTotalView">R$ 0,00</strong></div>
      </div>
      <div class="checkout-customer-strip"><span>Cliente</span><strong id="checkoutCustomerView">Consumidor final</strong></div>
      <div class="pdv-cats" id="pdvCats">
        <?php foreach ($categories as $idx => $c): ?><button class="pdv-cat <?= $idx===0?'active':'' ?>" type="button" data-cat="<?= gestao_h($c) ?>"><?= gestao_h($c) ?></button><?php endforeach; ?>
      </div>
      <div class="pdv-products pdv-products-wizard" id="pdvProducts"></div>
    </div>
  </section>

  <section class="pdv-step-panel" data-step-panel="carrinho">
    <div class="mg-card pdv-panel-card">
      <div class="pdv-section-head">
        <div><h2>Carrinho</h2><p class="mg-muted">Confira os itens, desconto e acréscimos.</p></div>
        <div class="mg-actions"><button type="button" class="btn pdv-prev" data-prev="produtos">Voltar</button><button type="button" class="btn btn-primary pdv-next" data-next="pagamento">Próximo: Pagamento</button></div>
      </div>
      <div class="pdv-cart-step-grid">
        <div>
          <div class="checkout-sale-head"><span>CÓDIGO</span><span>DESCRIÇÃO</span><span>QTD. X VALOR</span><span>TOTAL</span><span></span></div>
          <div class="pdv-cart-items pdv-cart-items-step" id="cartItems"><div class="mg-empty">Nenhum produto no carrinho.</div></div>
          <div id="cartHidden"></div>
        </div>
        <aside class="pdv-side-summary">
          <div class="mg-form-grid checkout-adjustment-grid">
            <label>% Desconto<input id="discountPercent" inputmode="decimal" value="0,00"><?php if ($maxDiscountPercent !== null): ?><small class="mg-muted">Máx. <?= number_format((float)$maxDiscountPercent,2,',','.') ?>%</small><?php endif; ?></label>
            <label>Valor desconto<input name="discount_amount" id="discountAmount" inputmode="decimal" value="0,00"></label>
            <label>% Acréscimo<input id="additionPercent" inputmode="decimal" value="0,00"></label>
            <label>Valor acréscimo<input name="addition_amount" id="additionAmount" inputmode="decimal" value="0,00"></label>
            <label>Frete<input name="delivery_fee" id="deliveryFee" inputmode="decimal" value="0,00"></label>
          </div>
          <div class="pdv-totals-card">
            <div class="pdv-total-row"><span>Subtotal</span><strong id="subtotalView">R$ 0,00</strong></div>
            <div class="pdv-total-row"><span>Desconto</span><strong id="discountView">R$ 0,00</strong></div>
            <div class="pdv-total-row"><span>Acréscimos/taxa</span><strong id="addsView">R$ 0,00</strong></div>
            <div class="pdv-total-row final"><span>Total</span><strong id="totalView">R$ 0,00</strong></div>
          </div>
        </aside>
      </div>
    </div>
  </section>

  <section class="pdv-step-panel" data-step-panel="pagamento">
    <div class="mg-card pdv-panel-card">
      <div class="pdv-section-head pdv-payment-compact-head">
        <div class="mg-actions"><button type="button" class="btn pdv-prev" data-prev="carrinho">Voltar</button></div>
      </div>
      <div class="pdv-payment-step-grid">
        <div class="pdv-payments-box">
          <div class="pdv-payments-head pdv-payments-head-compact">
            <div>
              <strong>Formas de pagamento</strong>
              <small>Use o número da forma, digite o valor, pressione + para adicionar e - para remover.</small>
            </div>
            <button class="btn" type="button" id="addPaymentBtn">+ Pagamento</button>
          </div>
          <div class="pdv-payment-shortcuts" id="paymentShortcuts" aria-label="Atalhos das formas de pagamento">
            <?php foreach ($paymentShortcuts as $paymentShortcut): ?>
              <button type="button" class="pdv-payment-shortcut" data-payment-shortcut="<?= (int)$paymentShortcut['id'] ?>">
                <kbd><?= gestao_h($paymentShortcut['key']) ?></kbd><span><?= gestao_h($paymentShortcut['name']) ?></span>
              </button>
            <?php endforeach; ?>
            <span class="pdv-payment-operation"><kbd>+</kbd> Adicionar</span>
            <span class="pdv-payment-operation"><kbd>-</kbd> Remover</span>
            <span class="pdv-payment-operation"><kbd>F10</kbd> Finalizar</span>
          </div>
          <div id="paymentLines" class="pdv-payment-lines"></div>
          <label class="span-2" id="dueDateWrap" style="display:none;margin-top:10px;">Vencimento da conta a receber<input type="date" name="due_date" value="<?= gestao_h(date('Y-m-d')) ?>"></label>
        </div>
        <aside class="pdv-side-summary pdv-payment-summary">
          <div class="pdv-customer-summary"><span>Cliente</span><strong id="paymentCustomerView">Consumidor final</strong></div>
          <div class="pdv-total-row"><span>Total do pedido</span><strong id="paymentOrderTotalView">R$ 0,00</strong></div>
          <div class="pdv-total-row"><span>Total informado</span><strong id="paymentsTotalView">R$ 0,00</strong></div>
          <div class="pdv-total-row"><span>Diferença</span><strong id="paymentsDiffView">R$ 0,00</strong></div>
          <div class="pdv-total-row" id="changeRow" style="display:none;"><span>Troco</span><strong id="changeView">R$ 0,00</strong></div>
          <div class="pdv-total-row final"><span>Total</span><strong id="paymentFinalTotalView">R$ 0,00</strong></div>
          <?php if ($saleFinalizationBlocked): ?><button class="btn" type="button" disabled title="Perfil sem permissão para finalizar">Finalização bloqueada</button><?php else: ?><button class="btn btn-primary pdv-finish-button" type="submit">Finalizar pedido <small>F10</small></button><?php endif; ?>
        </aside>
      </div>
    </div>
  </section>

  <div class="checkout-actionbar" aria-label="Atalhos do PDV Checkout">
    <button type="button" id="checkoutClientBtn" class="checkout-action checkout-action-blue"><kbd>F2</kbd><span>Cliente</span></button>
    <button type="button" id="checkoutValueProductBtn" class="checkout-action checkout-action-amber"><kbd>F4</kbd><span>Produto por valor</span></button>
    <button type="button" id="checkoutProductsBtn" class="checkout-action checkout-action-primary"><kbd>F8</kbd><span>Produtos</span></button>
    <button type="button" id="checkoutComandasBtn" class="checkout-action checkout-action-cyan" <?= $comandaReadEnabled ? '' : 'disabled title="Comandas não disponíveis para esta empresa/perfil"' ?>><kbd>F9</kbd><span>Ler Comandas</span></button>
    <button type="button" id="checkoutSellerBtn" class="checkout-action checkout-action-blue" title="Selecionar vendedor (Ctrl+F10)"><kbd>Ctrl+F10</kbd><span>Vendedor</span></button>
    <button type="button" id="checkoutOrcamentosBtn" class="checkout-action checkout-action-violet" <?= $orcamentoReadEnabled ? '' : 'disabled title="Orçamentos não disponíveis para esta empresa/perfil"' ?>><kbd>F11</kbd><span>Ler Orçamento</span></button>
    <button type="button" id="checkoutOsBtn" class="checkout-action checkout-action-os" <?= $osReadEnabled ? '' : 'disabled title="Ordens de Serviço não disponíveis para esta empresa/perfil"' ?>><kbd>F6</kbd><span>Ordem de Serviço</span></button>
    <button type="button" id="checkoutCancelItemBtn" class="checkout-action checkout-action-red"><kbd>F3</kbd><span>Cancela item</span></button>
    <button type="button" id="checkoutPaymentBtn" class="checkout-action checkout-action-green"><kbd>F5</kbd><span>Pagamento</span></button>
    <button type="button" id="checkoutCancelSaleBtn" class="checkout-action checkout-action-red"><kbd>F7</kbd><span>Cancela venda</span></button>
    <button type="button" id="checkoutExitBtn" class="checkout-action checkout-action-exit"><kbd>↩</kbd><span>Sair</span></button>
  </div>

  <div class="finance-modal checkout-center-modal" id="checkoutSellerModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="checkoutSellerTitle">
    <div class="finance-modal-dialog" style="max-width:560px;width:calc(100% - 24px)">
      <div class="finance-modal-head"><h2 id="checkoutSellerTitle">Vendedor da venda</h2><button type="button" class="finance-modal-close" data-close-modal="checkoutSellerModal" aria-label="Fechar">×</button></div>
      <div style="padding:16px"><label for="checkoutSellerPicker">Selecione o vendedor</label>
        <select id="checkoutSellerPicker" style="width:100%;padding:10px;margin-top:6px" <?= !$allowSellerChange ? 'disabled' : '' ?>>
          <?php gestao_select_options($sellers, $defaultSellerId ?: null, 'Sem vendedor', false); ?>
        </select>
        <?php if (!$allowSellerChange): ?><p class="mg-muted">Vendedor fixado pelas permissões comerciais deste usuário.</p><?php endif; ?>
        <div style="display:flex;gap:8px;justify-content:flex-end;margin-top:16px"><button type="button" class="btn" data-close-modal="checkoutSellerModal">Voltar</button><button type="button" class="btn btn-primary" id="checkoutSellerApplyBtn" <?= !$allowSellerChange ? 'disabled' : '' ?>>Aplicar vendedor</button></div>
      </div>
    </div>
  </div>

  <?php if ($pdvMobileMode): ?>
    <div class="pdv-mobile-floating-total" id="pdvMobileFloatingTotal">
      <div class="pdv-mobile-floating-info">
        <span id="pdvMobileFloatingLabel">Carrinho</span>
        <strong id="pdvMobileFloatingTotalValue">R$ 0,00</strong>
      </div>
      <button type="button" class="pdv-mobile-floating-next" id="pdvMobileFloatingNext">Avançar</button>
    </div>
    <div class="pdv-mobile-payment-footer" id="pdvMobilePaymentFooter">
      <div class="pdv-mobile-floating-info">
        <span>Total</span>
        <strong id="pdvMobilePaymentFooterTotal">R$ 0,00</strong>
      </div>
      <?php if ($saleFinalizationBlocked): ?><button type="button" class="pdv-mobile-finish-floating" disabled>Finalização bloqueada</button><?php else: ?><button type="submit" class="pdv-mobile-finish-floating">Finalizar</button><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="pdv-mobile-toast" id="pdvMobileToast" aria-live="polite">Item adicionado</div>
</form>

<div class="checkout-action-modal" id="checkoutActionModal" style="display:none;" aria-hidden="true">
  <div class="checkout-action-dialog" role="dialog" aria-modal="true" aria-labelledby="checkoutActionTitle" aria-describedby="checkoutActionMessage">
    <div class="checkout-action-icon" id="checkoutActionIcon">!</div>
    <h3 id="checkoutActionTitle">Atenção</h3>
    <p id="checkoutActionMessage">Confirme a operação para continuar.</p>
    <div class="checkout-action-dialog-buttons">
      <button type="button" class="btn checkout-action-dialog-cancel" id="checkoutActionCancel">Voltar</button>
      <button type="button" class="btn btn-primary checkout-action-dialog-confirm" id="checkoutActionConfirm">Confirmar</button>
    </div>
  </div>
</div>

<div class="finance-modal checkout-center-modal checkout-pix-modal" id="checkoutPixModal" style="display:none;" aria-hidden="true">
  <div class="finance-modal-dialog checkout-pix-dialog" role="dialog" aria-modal="true" aria-labelledby="checkoutPixTitle">
    <div class="finance-modal-head checkout-pix-head">
      <div><h2 id="checkoutPixTitle">Pagamento Pix</h2><p class="mg-muted" style="margin:4px 0 0;">Leia o QR Code no aplicativo do banco. A venda continuará automaticamente após a confirmação.</p></div>
      <button type="button" class="finance-modal-close" id="checkoutPixClose" aria-label="Fechar">×</button>
    </div>
    <div class="checkout-pix-body">
      <div class="checkout-pix-amount"><span>Valor desta cobrança</span><strong id="checkoutPixAmount">R$ 0,00</strong></div>
      <div class="checkout-pix-qr-wrap"><img id="checkoutPixQr" alt="QR Code Pix" hidden><div class="checkout-pix-qr-placeholder" id="checkoutPixQrPlaceholder">Gerando QR Code...</div></div>
      <div class="checkout-pix-status is-waiting" id="checkoutPixStatus"><span class="checkout-pix-pulse"></span><div><strong>Aguardando pagamento</strong><small id="checkoutPixStatusDetail">Consultando confirmação no banco...</small></div></div>
      <label class="checkout-pix-copy-label">Pix Copia e Cola<textarea id="checkoutPixCopyPaste" rows="3" readonly placeholder="O código Pix aparecerá aqui."></textarea></label>
      <div class="checkout-pix-meta"><span id="checkoutPixTxid"></span><span id="checkoutPixExpires"></span></div>
    </div>
    <div class="checkout-pix-actions">
      <button type="button" class="btn" id="checkoutPixCancel">Fechar</button>
      <button type="button" class="btn" id="checkoutPixCopy">Copiar código</button>
      <button type="button" class="btn btn-primary" id="checkoutPixVerify">Verificar agora</button>
    </div>
  </div>
</div>

<div class="finance-modal checkout-center-modal" id="checkoutValueProductModal" style="display:none;">
  <div class="finance-modal-dialog checkout-reader-dialog checkout-value-dialog">
    <div class="finance-modal-head">
      <div><h2>Produto por valor</h2><p class="mg-muted" style="margin:4px 0 0;">Informe o valor total. Depois selecione o produto; a quantidade será calculada automaticamente pelo valor unitário.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="checkoutValueProductModal">×</button>
    </div>
    <div class="checkout-value-entry">
      <label>Valor total (R$)<input type="text" inputmode="decimal" id="checkoutValueAmount" placeholder="0,00" autocomplete="off"></label>
      <button type="button" class="btn btn-primary" id="checkoutValueContinue">Selecionar produto</button>
    </div>
  </div>
</div>

<?php if ($comandaReadEnabled): ?>
<div class="finance-modal checkout-center-modal" id="checkoutComandaReaderModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide checkout-reader-dialog">
    <div class="finance-modal-head">
      <div><h2>Ler Comandas</h2><p class="mg-muted" style="margin:4px 0 0;">Leia ou pesquise uma ou mais comandas ocupadas. As comandas selecionadas serão importadas para esta venda.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="checkoutComandaReaderModal">×</button>
    </div>
    <div class="checkout-reader-search">
      <input type="search" id="checkoutComandaQuery" placeholder="Número, matrícula, nome ou cliente" autocomplete="off">
      <button type="button" class="btn btn-primary" id="checkoutComandaSearchBtn">Buscar</button>
    </div>
    <div class="checkout-reader-selected"><strong id="checkoutComandaSelectedCount">0 comanda(s) selecionada(s)</strong><button type="button" class="btn" id="checkoutComandaClearBtn">Limpar seleção</button></div>
    <div class="checkout-reader-results" id="checkoutComandaResults"><div class="checkout-reader-empty">Leia uma comanda ou clique em Buscar para listar as comandas ocupadas.</div></div>
    <div class="checkout-reader-footer"><button type="button" class="btn" data-close-modal="checkoutComandaReaderModal">Cancelar</button><button type="button" class="btn btn-primary" id="checkoutComandaImportBtn">Importar selecionadas (F9)</button></div>
  </div>
</div>
<?php endif; ?>

<?php if ($orcamentoReadEnabled): ?>
<div class="finance-modal checkout-center-modal" id="checkoutOrcamentoReaderModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide checkout-reader-dialog">
    <div class="finance-modal-head">
      <div><h2>Ler Orçamento</h2><p class="mg-muted" style="margin:4px 0 0;">Pesquise e selecione um ou mais orçamentos abertos/aprovados para importar os itens para o PDV Checkout.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="checkoutOrcamentoReaderModal">×</button>
    </div>
    <div class="checkout-reader-search">
      <input type="search" id="checkoutOrcamentoQuery" placeholder="Número do orçamento, cliente, documento, telefone ou vendedor" autocomplete="off">
      <button type="button" class="btn btn-primary" id="checkoutOrcamentoSearchBtn">Buscar</button>
    </div>
    <div class="checkout-reader-selected"><strong id="checkoutOrcamentoSelectedCount">0 orçamento(s) selecionado(s)</strong><button type="button" class="btn" id="checkoutOrcamentoClearBtn">Limpar seleção</button></div>
    <div class="checkout-reader-results" id="checkoutOrcamentoResults"><div class="checkout-reader-empty">Digite um termo ou clique em Buscar para listar os orçamentos disponíveis.</div></div>
    <div class="checkout-reader-footer"><button type="button" class="btn" data-close-modal="checkoutOrcamentoReaderModal">Cancelar</button><button type="button" class="btn btn-primary" id="checkoutOrcamentoImportBtn">Importar selecionados (F11)</button></div>
  </div>
</div>
<?php endif; ?>

<?php if ($osReadEnabled): ?>
<div class="finance-modal checkout-center-modal" id="checkoutOsReaderModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide checkout-reader-dialog">
    <div class="finance-modal-head">
      <div><h2>Ler Ordem de Serviço</h2><p class="mg-muted" style="margin:4px 0 0;">Selecione uma ou mais O.S. pendentes do mesmo cliente. Produtos e serviços serão mantidos separados para a emissão fiscal.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="checkoutOsReaderModal">×</button>
    </div>
    <div class="checkout-reader-search">
      <input type="search" id="checkoutOsQuery" placeholder="Número da O.S., cliente, documento, título ou problema" autocomplete="off">
      <button type="button" class="btn btn-primary" id="checkoutOsSearchBtn">Buscar</button>
    </div>
    <div class="checkout-reader-selected"><strong id="checkoutOsSelectedCount">0 O.S. selecionada(s)</strong><button type="button" class="btn" id="checkoutOsClearBtn">Limpar seleção</button></div>
    <div class="checkout-reader-results" id="checkoutOsResults"><div class="checkout-reader-empty">Digite um termo ou clique em Buscar para listar O.S. pendentes.</div></div>
    <div class="checkout-reader-footer"><button type="button" class="btn" data-close-modal="checkoutOsReaderModal">Cancelar</button><button type="button" class="btn btn-primary" id="checkoutOsImportBtn">Importar selecionadas (F6)</button></div>
  </div>
</div>

<div class="finance-modal checkout-center-modal" id="checkoutOsFiscalModal" style="display:none;">
  <div class="finance-modal-dialog checkout-reader-dialog" style="width:min(720px,100%)">
    <div class="finance-modal-head">
      <div><h2>Documentos fiscais da Ordem de Serviço</h2><p class="mg-muted" style="margin:4px 0 0;">Produtos e serviços são emitidos separadamente. Escolha o documento de cada parte antes de faturar.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="checkoutOsFiscalModal">×</button>
    </div>
    <div id="checkoutOsFiscalSummary" class="checkout-os-fiscal-summary"></div>
    <div class="checkout-os-fiscal-grid">
      <label id="checkoutOsProductFiscalGroup"><strong>Produtos</strong><small>NF-e/NFC-e conterá somente os produtos da O.S.</small><select id="checkoutOsProductFiscalSelect"><option value="">Não emitir agora</option><?php if (!empty($osFiscalOptions['NFE'])): ?><option value="NFE">Emitir NF-e</option><?php endif; ?><?php if (!empty($osFiscalOptions['NFCE'])): ?><option value="NFCE">Emitir NFC-e</option><?php endif; ?></select></label>
      <label id="checkoutOsServiceFiscalGroup"><strong>Serviços</strong><small>NFS-e conterá somente os serviços da O.S.</small><select id="checkoutOsServiceFiscalSelect"><option value="">Não emitir agora</option><?php if (!empty($osFiscalOptions['NFSE'])): ?><option value="NFSE">Emitir NFS-e</option><?php endif; ?></select></label>
    </div>
    <div class="checkout-reader-footer"><button type="button" class="btn" data-close-modal="checkoutOsFiscalModal">Voltar</button><button type="button" class="btn btn-primary" id="checkoutOsFiscalConfirm">Faturar e continuar</button></div>
  </div>
</div>
<?php endif; ?>

<?php if ($fiscalEmissionEnabled): ?>
<div class="finance-modal" id="creditOverrideModal" style="display:none;">
  <div class="finance-modal-dialog" style="width:min(560px,100%)">
    <div class="finance-modal-head"><div><h2>Limite de crédito excedido</h2><p class="mg-muted" id="creditOverrideMessage" style="margin:5px 0 0"></p></div><button type="button" class="finance-modal-close" id="creditOverrideClose">×</button></div>
    <div class="pdv-form-grid" style="grid-template-columns:1fr 1fr">
      <label>Código do usuário<input id="creditOverrideCodeInput" autocomplete="username" placeholder="Código ou e-mail"></label>
      <label>Senha<input id="creditOverridePasswordInput" type="password" autocomplete="current-password"></label>
    </div>
    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:14px"><button type="button" class="btn" id="creditOverrideCancel">Cancelar</button><button type="button" class="btn btn-primary" id="creditOverrideConfirm">Liberar venda</button></div>
  </div>
</div>

<div class="finance-modal" id="pdvFiscalModal" style="display:none;">
  <div class="finance-modal-dialog pdv-fiscal-dialog pdv-fiscal-count-<?= $pdvFiscalChoiceCount ?>">
    <div class="finance-modal-head">
      <div><h2>Documento fiscal</h2><p class="mg-muted" style="margin:4px 0 0;"><?= gestao_h($pdvFiscalModalDescription) ?></p></div>
      <button type="button" class="finance-modal-close" data-close-modal="pdvFiscalModal">×</button>
    </div>
    <div class="pdv-fiscal-choice-grid pdv-fiscal-count-<?= $pdvFiscalChoiceCount ?>">
      <?php if ($pdvCanEmitNfce): ?><button type="button" class="pdv-fiscal-choice" data-pdv-fiscal="nfce"><strong>1 · Emitir NFC-e</strong><small>Cupom fiscal ao consumidor.</small></button><?php endif; ?>
      <?php if ($pdvCanEmitNfe): ?><button type="button" class="pdv-fiscal-choice" data-pdv-fiscal="nfe"><strong>2 · Emitir NF-e</strong><small>Nota fiscal modelo 55.</small></button><?php endif; ?>
      <?php if ($pdvCanEmitNfse): ?><button type="button" class="pdv-fiscal-choice" data-pdv-fiscal="nfse"><strong>3 · Emitir NFS-e</strong><small>Salva o pedido, transmite a NFS-e e abre o documento autorizado.</small></button><?php endif; ?>
      <?php if ($pdvCanSaveOrder): ?><button type="button" class="pdv-fiscal-choice" data-pdv-fiscal="none"><strong>Somente gravar pedido</strong><small>Grava sem emitir documento fiscal.</small></button><?php endif; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="finance-modal" id="pdvOptionModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2 id="optProductTitle">Produto</h2><p class="mg-muted" style="margin:4px 0 0;">Escolha carnes, adicionais, acompanhamentos e observações do item.</p></div>
      <button type="button" class="finance-modal-close" id="optCancel">×</button>
    </div>
    <div id="optHalfBox" class="pdv-half-box" style="display:none;"></div>
    <div id="optGroups" class="pdv-options-modal-body"></div>
    <div class="mg-form-grid" style="margin-top:12px;">
      <label>Quantidade<input id="optQty" inputmode="decimal" value="1"></label>
      <label>Adicionais selecionados<input id="optTotal" readonly value="+ R$ 0,00"></label>
      <label class="span-2">Observação do item<textarea id="optNote" rows="2" placeholder="<?= gestao_h($itemNoteInstruction) ?>"></textarea></label>
    </div>
    <div id="pdvScaleBox" class="scale-control-box" style="display:none;margin-top:10px;"></div>
    <div class="mg-form-actions"><button type="button" class="btn" id="optCancelBottom">Cancelar</button><button type="button" class="btn btn-primary" id="optConfirm">Adicionar ao carrinho</button></div>
  </div>
</div>


<?php if (!$pdvMobileMode): ?>
<div class="finance-modal" id="productSearchModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Pesquisar produto</h2><p class="mg-muted" style="margin:4px 0 0;">Pesquise e selecione o produto para adicioná-lo ao pedido.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="productSearchModal">×</button>
    </div>
    <div class="pdv-product-modal-searchbar">
      <label>Pesquisar por
        <select id="productModalSearchMode" class="field">
          <option value="instant">Pesquisa instantânea</option>
          <option value="menu">Código do cardápio</option>
          <option value="description">Descrição</option>
          <option value="barcode">Código de barras</option>
        </select>
      </label>
      <label>Produto
        <input type="search" id="productModalSearch" placeholder="Digite código, referência, nome ou código de barras" autocomplete="off">
      </label>
      <button type="button" class="btn btn-primary" id="productModalSearchBtn">Pesquisar</button>
    </div>
    <div class="pdv-product-modal-results" id="productModalResults">
      <div class="pdv-product-modal-empty">Digite um termo para pesquisar. A lista começa vazia.</div>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="finance-modal" id="customerSearchModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Pesquisar cliente</h2><p class="mg-muted" style="margin:4px 0 0;">Busque por nome, CPF/CNPJ, telefone ou WhatsApp e selecione o cliente para esta venda.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="customerSearchModal">×</button>
    </div>
    <div class="pdv-modal-searchbar">
      <input type="search" id="customerModalSearch" placeholder="Digite nome, CPF/CNPJ, telefone ou WhatsApp" autocomplete="off">
      <button type="button" class="btn btn-primary" id="customerModalSearchBtn">Pesquisar</button>
      <button type="button" class="btn" id="customerModalNewBtn">+ Cadastrar</button>
    </div>
    <div class="pdv-client-results" id="customerModalResults"></div>
  </div>
</div>

<div class="finance-modal" id="customerModal" style="display:none;">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head">
      <div><h2>Novo cliente</h2><p class="mg-muted" style="margin:4px 0 0;">Cadastro rápido para usar no pedido atual.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="customerModal">×</button>
    </div>
    <form id="customerQuickForm">
      <?php if ($pdvCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($pdvMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= gestao_h($pdvMcCtx) ?>"><?php endif; ?>
      <input type="hidden" name="action" value="create_customer_pdv">
      <div class="mg-form-grid three">
        <label>Tipo de pessoa<select name="person_type" id="quickPersonType"><option value="FISICA">Pessoa Física</option><option value="JURIDICA">Pessoa Jurídica</option></select></label>
        <label id="quickDocumentLabel">CPF<input name="document" id="quickDocument" inputmode="numeric"></label>
        <label>Situação da IE<select name="state_registration_status" id="quickIeStatus"><option value="AUTO">Automática</option><option value="CONTRIBUINTE">Contribuinte de ICMS</option><option value="ISENTO">Isento</option><option value="NAO_CONTRIBUINTE">Não contribuinte</option></select></label>
        <label>Inscrição Estadual<input name="state_registration" id="quickStateRegistration" maxlength="30" autocomplete="off" placeholder="Número da IE"></label>
        <label>Inscrição Municipal<input name="municipal_registration" id="quickMunicipalRegistration" maxlength="30" autocomplete="off" placeholder="Inscrição municipal"></label>
        <label>WhatsApp<input name="whatsapp" id="quickWhatsapp" inputmode="numeric"></label>
        <label class="span-2">Nome/Razão social<input name="name" id="quickName" required></label>
        <label>Nome fantasia<input name="fantasy_name" id="quickFantasy"></label>
        <label>E-mail<input name="email" id="quickEmail" type="email"></label>
        <label>Telefone<input name="phone" id="quickPhone" inputmode="numeric"></label>
        <label>CEP<input name="zip_code" id="quickZip" inputmode="numeric"></label>
        <label class="span-2">Endereço<input name="address" id="quickAddress"></label>
        <label>Número<input name="number" id="quickNumber"></label>
        <label>Bairro<input name="district" id="quickDistrict" list="deliveryDistrictList"></label>
        <label>Cidade<input name="city" id="quickCity"></label>
        <label>Código IBGE<input name="city_ibge" id="quickCityIbge" inputmode="numeric" maxlength="7" placeholder="7 dígitos"></label>
        <label>UF<input name="state" id="quickState" maxlength="2"></label>
        <label class="span-2">Complemento<input name="complement" id="quickComplement"></label>
        <label>Referência<input name="reference" id="quickReference" placeholder="Ponto de referência"></label>
      </div>
      <div class="mg-form-actions"><button type="button" class="btn" data-close-modal="customerModal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar e usar no pedido</button></div>
    </form>
  </div>
</div>

<div class="finance-modal" id="addressModal" style="display:none;">
  <div class="finance-modal-dialog">
    <div class="finance-modal-head">
      <div><h2>Novo endereço</h2><p class="mg-muted" style="margin:4px 0 0;">Cadastre outro endereço para o cliente selecionado.</p></div>
      <button type="button" class="finance-modal-close" data-close-modal="addressModal">×</button>
    </div>
    <form id="addressQuickForm">
      <?php if ($pdvCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($pdvMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= gestao_h($pdvMcCtx) ?>"><?php endif; ?>
      <input type="hidden" name="action" value="create_customer_address_pdv">
      <input type="hidden" name="person_id" id="addressPersonId">
      <div class="mg-form-grid">
        <label>Apelido<input name="label" placeholder="Casa, trabalho, entrega..."></label>
        <label>CEP<input name="zip_code" id="addrZip" inputmode="numeric"></label>
        <label class="span-2">Endereço<input name="address" id="addrAddress"></label>
        <label>Número<input name="number" id="addrNumber"></label>
        <label>Bairro<input name="district" id="addrDistrict" list="deliveryDistrictList"></label>
        <label>Cidade<input name="city" id="addrCity"></label>
        <label>UF<input name="state" id="addrState" maxlength="2"></label>
        <label>Complemento<input name="complement" id="addrComplement"></label>
        <label>Referência<input name="reference" id="addrReference" placeholder="Ponto de referência"></label>
        <label class="span-2" style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="is_default" value="1"> Tornar endereço padrão</label>
      </div>
      <div class="mg-form-actions"><button type="button" class="btn" data-close-modal="addressModal">Cancelar</button><button type="submit" class="btn btn-primary">Salvar endereço</button></div>
    </form>
  </div>
</div>

<style>
.pdv-manual-consumer-card{margin:0 0 14px;padding:14px;border:2px solid #bfdbfe;border-radius:20px;background:linear-gradient(135deg,#eff6ff,#f8fbff);box-shadow:0 10px 28px rgba(37,99,235,.08)}
.pdv-manual-consumer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}.pdv-brand-head{display:flex;align-items:center;gap:12px}.pdv-brand-logo{width:52px;height:52px;object-fit:cover;border-radius:14px;border:1px solid #dbe4f0;box-shadow:0 8px 18px rgba(15,23,42,.08)}.pdv-brand-head h2{margin:0;color:var(--admin-primary)}
.pdv-manual-consumer-head strong{display:block;color:#0b1f5b;font-size:17px}
.pdv-manual-consumer-head small{display:block;margin-top:4px;color:#475569;line-height:1.4}
.pdv-manual-consumer-head span{flex:0 0 auto;display:inline-flex;align-items:center;min-height:30px;padding:5px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.03em}
.pdv-manual-consumer-card .pdv-final-consumer-grid{margin:0}
.pdv-manual-consumer-card input{background:#fff;border-color:#93c5fd;font-weight:750}
@media(max-width:760px){.pdv-manual-consumer-card{padding:12px;border-radius:18px}.pdv-manual-consumer-head{display:block}.pdv-manual-consumer-head span{margin-top:9px}.pdv-manual-consumer-card .mg-form-grid.two{grid-template-columns:1fr}.pdv-manual-consumer-card input{min-height:50px;font-size:16px}.pdv-brand-head{align-items:flex-start}.pdv-brand-logo{width:44px;height:44px;border-radius:12px}}
.finance-modal{position:fixed;inset:0;z-index:300;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);overflow:auto}.finance-modal-dialog{width:min(980px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px}.finance-modal-wide{width:min(1120px,100%)}.finance-modal-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.finance-modal-head h2{margin:0;color:var(--admin-primary);font-size:21px}.finance-modal-close{display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;border-radius:14px;border:1px solid #dbe4f0;background:#fff;text-decoration:none;color:#0f172a;font-size:22px;font-weight:900;cursor:pointer}.pdv-wizard-form{display:block}.pdv-wizard-shell{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:14px;margin-bottom:14px;align-items:stretch}.pdv-stepper{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pdv-step-nav{border:1px solid #dbe4f0;background:#fff;border-radius:20px;padding:12px;text-align:left;cursor:pointer;box-shadow:0 10px 26px rgba(15,23,42,.04);display:grid;grid-template-columns:42px 1fr;gap:8px;align-items:center}.pdv-step-nav span{grid-row:span 2;width:42px;height:42px;border-radius:15px;display:flex;align-items:center;justify-content:center;background:#eff6ff;color:#1d4ed8;font-weight:950}.pdv-step-nav strong{color:#0b1f5b;font-size:15px;line-height:1.1}.pdv-step-nav small{color:#64748b;font-size:12px}.pdv-step-nav.active{border-color:#2563eb;background:linear-gradient(135deg,#143a9c,#2563eb);box-shadow:0 18px 42px rgba(37,99,235,.22)}.pdv-step-nav.active span{background:rgba(255,255,255,.18);color:#fff}.pdv-step-nav.active strong,.pdv-step-nav.active small{color:#fff}.pdv-step-panel{display:none}.pdv-step-panel.active{display:block}.pdv-panel-card{min-height:520px}.pdv-section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:14px}.pdv-section-head h2{font-size:26px;color:var(--admin-primary);margin:0 0 4px}.pdv-section-head p{margin:0}.pdv-quick-summary{padding:14px;display:grid;grid-template-columns:1fr;gap:8px}.pdv-quick-summary div{display:flex;justify-content:space-between;gap:10px;align-items:center}.pdv-quick-summary span,.pdv-mini-summary span{font-size:12px;color:#64748b;font-weight:850}.pdv-quick-summary strong,.pdv-mini-summary strong{font-size:16px;color:#0b1f5b}.pdv-client-grid{display:grid;grid-template-columns:minmax(0,1fr) 430px;gap:14px;align-items:start}.pdv-client-search{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;align-items:end}.pdv-mobile-manual-consumer-fields{margin-top:12px;padding:12px;border:1px solid #bfdbfe;border-radius:18px;background:#f8fbff}.pdv-mobile-manual-consumer-fields input{background:#fff;border-color:#93c5fd;font-weight:750}.pdv-customer-card{margin-top:12px;border:1px solid #dbe4f0;border-radius:22px;background:#f8fbff;padding:14px;display:flex;gap:12px;align-items:flex-start}.pdv-customer-card.found{border-color:#86efac;background:#f0fdf4}.pdv-customer-card.not-found{border-color:#fecaca;background:#fef2f2}.pdv-customer-icon{width:48px;height:48px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:#e0f2fe;color:#075985;font-weight:950}.pdv-customer-card strong{display:block;color:#0b1f5b;font-size:18px}.pdv-customer-card small{display:block;color:#64748b;margin-top:4px;line-height:1.35}.pdv-address-box{border:1px solid #dbe4f0;border-radius:22px;background:#fff;padding:16px;box-shadow:0 10px 26px rgba(15,23,42,.04)}.pdv-address-grid input,.pdv-address-grid select{width:100%;min-width:0;box-sizing:border-box}.pdv-address-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:12px}.pdv-address-head strong{display:block;color:#0b1f5b;font-size:17px}.pdv-address-head small{display:block;color:#64748b;margin-top:2px}.pdv-fee-card{margin-top:12px;border-radius:18px;background:linear-gradient(135deg,#eff6ff,#f8fbff);border:1px solid #bfdbfe;padding:13px;display:flex;justify-content:space-between;gap:10px;align-items:center}.pdv-fee-card span{color:#64748b;font-weight:850}.pdv-fee-card strong{font-size:20px;color:#047857}.pdv-search{display:grid;grid-template-columns:minmax(0,1fr) auto 230px;gap:10px;margin-bottom:12px;align-items:end}.pdv-mini-summary{border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;padding:10px 12px;display:grid;align-content:center}.pdv-cats{display:flex;gap:8px;overflow:auto;padding-bottom:8px;margin-bottom:10px}.pdv-cat{border:1px solid #dbe4f0;border-radius:999px;background:#fff;padding:8px 11px;font-weight:850;white-space:nowrap;cursor:pointer}.pdv-cat.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.pdv-products-wizard{max-height:none;overflow:visible;grid-template-columns:repeat(auto-fill,minmax(230px,1fr))}.pdv-cart-step-grid,.pdv-payment-step-grid{display:grid;grid-template-columns:minmax(0,1fr) 380px;gap:14px;align-items:start}.pdv-cart-items-step{max-height:none;overflow:visible;padding-right:0}.pdv-side-summary{border:1px solid #dbe4f0;border-radius:22px;background:#f8fbff;padding:16px;position:sticky;top:88px}.pdv-cart-item{background:#fff;border-color:#dbeafe;box-shadow:0 10px 24px rgba(11,31,91,.06)}.pdv-cart-line strong{font-size:14px;line-height:1.25}.pdv-cart-line b{white-space:nowrap;color:#047857}.pdv-qty{display:grid;grid-template-columns:38px minmax(72px,1fr) 38px 42px;gap:7px}.pdv-qty button{width:100%;height:38px}.pdv-trash-btn{color:#b91c1c!important;font-size:16px}.pdv-qty input{width:100%;min-width:0}.pdv-item-note{width:100%;margin-top:8px}.pdv-totals-card{margin-top:12px;border-top:1px solid #e2e8f0;padding-top:8px}.pdv-totals-inline{margin-top:18px;border:1px solid #dbe4f0;border-radius:20px;background:#f8fbff;padding:16px;display:flex;justify-content:space-between;gap:14px;align-items:center}.pdv-payments-box{border:1px solid #dbe4f0;border-radius:20px;background:#f8fbff;padding:13px;overflow:hidden}.pdv-payments-head{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-bottom:10px}.pdv-payments-head strong{display:block;color:var(--admin-primary);font-size:18px}.pdv-payments-head small{display:block;color:#64748b;margin-top:2px;line-height:1.35}.pdv-payment-shortcuts{display:flex;flex-wrap:wrap;gap:7px;margin:0 0 11px;padding:9px;border:1px dashed #bfdbfe;border-radius:15px;background:#eff6ff}.pdv-payment-shortcut,.pdv-payment-operation{display:inline-flex;align-items:center;gap:6px;min-height:34px;padding:5px 9px;border:1px solid #dbe4f0;border-radius:11px;background:#fff;color:#334155;font-size:12px;font-weight:850}.pdv-payment-shortcut{cursor:pointer}.pdv-payment-shortcut:hover{border-color:#2563eb;background:#f8fbff}.pdv-payment-shortcut kbd,.pdv-payment-operation kbd{display:inline-flex;align-items:center;justify-content:center;min-width:24px;height:24px;padding:0 6px;border-radius:7px;background:#0b1f5b;color:#fff;font:900 12px/1 system-ui}.pdv-payment-line.is-active{border-color:#2563eb;box-shadow:0 0 0 3px rgba(37,99,235,.10)}.pdv-payment-lines{display:grid;gap:9px}.pdv-payment-line{display:grid;grid-template-columns:minmax(220px,1.2fr) minmax(140px,.8fr) 42px;gap:8px;align-items:end;border:1px solid #e5edf6;background:#fff;border-radius:18px;padding:10px}.pdv-payment-line label{min-width:0}.pdv-payment-line select,.pdv-payment-line input{width:100%;min-width:0}.pdv-payment-line .pay-received-label{grid-column:1 / -2}.pdv-payment-line.show-received{grid-template-columns:minmax(210px,1.1fr) minmax(130px,.7fr) minmax(130px,.7fr) 42px}.pdv-payment-line.show-received .pay-received-label{grid-column:auto}.pdv-payment-line .pay-remove{min-height:42px;color:#b91c1c}.pdv-customer-summary{border:1px solid #dbe4f0;border-radius:16px;background:#fff;padding:11px;margin-bottom:8px;display:flex;justify-content:space-between;gap:10px}.pdv-customer-summary span{color:#64748b;font-weight:850}.pdv-customer-summary strong{color:#0b1f5b;text-align:right}.pdv-half-box{border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;padding:14px;margin:0 0 12px}.pdv-half-box strong{display:block;color:var(--admin-primary);margin-bottom:6px}.pdv-half-box .mg-form-grid{margin-top:8px}.pdv-opt-group{border:1px solid #dbeafe;border-radius:18px;padding:14px;margin:0 0 12px;background:#fff}.pdv-opt-group legend{display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;color:var(--admin-primary);font-weight:950}.pdv-opt-group legend small{color:#64748b}.pdv-opt-items{display:grid;gap:8px;margin-top:10px}.pdv-opt-item{display:flex;gap:10px;align-items:flex-start;border:1px solid #e5edf6;border-radius:15px;padding:11px;cursor:pointer}.pdv-opt-item:hover{border-color:#60a5fa;background:#f8fbff}.pdv-opt-item span{display:flex;justify-content:space-between;gap:10px;width:100%}.pdv-opt-item small{white-space:nowrap;color:#1d4ed8;font-weight:850}.pdv-opt-price{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2}.pdv-opt-final{font-size:11px;color:#64748b;font-style:normal;font-weight:750;margin-top:2px}.pdv-payment-summary .pdv-total-row.final{font-size:24px}.pdv-finish-button{width:100%;min-height:56px;font-size:17px;margin-top:14px}.pdv-fiscal-dialog.pdv-fiscal-count-1{width:min(430px,100%)}.pdv-fiscal-dialog.pdv-fiscal-count-2{width:min(620px,100%)}.pdv-fiscal-dialog.pdv-fiscal-count-3,.pdv-fiscal-dialog.pdv-fiscal-count-4{width:min(780px,100%)}.pdv-fiscal-choice-grid{display:grid;gap:10px}.pdv-fiscal-choice-grid.pdv-fiscal-count-1{grid-template-columns:minmax(0,1fr)}.pdv-fiscal-choice-grid.pdv-fiscal-count-2{grid-template-columns:repeat(2,minmax(0,1fr))}.pdv-fiscal-choice-grid.pdv-fiscal-count-3{grid-template-columns:repeat(3,minmax(0,1fr))}.pdv-fiscal-choice-grid.pdv-fiscal-count-4{grid-template-columns:repeat(2,minmax(0,1fr))}.pdv-fiscal-choice{border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:16px;text-align:left;cursor:pointer;min-height:102px}.pdv-fiscal-choice:hover{border-color:#2563eb;background:#eff6ff}.pdv-fiscal-choice strong{display:block;color:var(--admin-primary);font-size:16px}.pdv-fiscal-choice small{display:block;color:#64748b;margin-top:6px;line-height:1.35}.pdv-finish-button{width:100%;min-height:56px;font-size:17px;margin-top:14px}.scale-control-box{border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;padding:13px;display:grid;gap:8px}.scale-control-box strong{display:block;color:var(--admin-primary);font-weight:950}.scale-control-box small{display:block;color:#64748b;font-weight:750}.scale-actions,.scale-control-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.scale-actions .scale-status,.scale-control-actions .scale-status{font-size:12px;color:#475569;font-weight:850}.scale-actions button,.scale-control-actions button{min-height:38px}.scale-status.ok{color:#047857}.scale-status.err{color:#b91c1c}.pdv-installments{grid-column:1 / -1;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;padding:10px;display:none;max-width:100%;box-sizing:border-box;overflow:hidden}.pdv-installments.show{display:block}.pdv-installments-head{display:flex;gap:8px;align-items:flex-end;flex-wrap:wrap;justify-content:space-between}.pdv-installments-head>div{min-width:180px;flex:1}.pdv-installments-head label{max-width:130px}.pdv-installments small{color:#64748b;font-weight:750}.pdv-installments input{width:100%;min-width:0;box-sizing:border-box}.pdv-installments-list{display:grid;gap:6px;margin-top:8px}.pdv-installment-row{display:grid;grid-template-columns:74px minmax(130px,1fr) minmax(110px,1fr);gap:8px;align-items:center;border-bottom:1px solid #e2e8f0;padding:6px 0}.pdv-installment-row.head{font-size:12px;text-transform:uppercase;color:#475569;font-weight:950;background:#f8fafc;border-bottom:1px solid #cbd5e1}.pdv-installment-row input{height:38px;padding:7px 8px}.pdv-installment-label{font-weight:900;color:#0b1f5b}@media(max-width:760px){.pdv-installments-head{display:grid;grid-template-columns:1fr}.pdv-installments-head label{max-width:none}.pdv-installments-head .btn{width:100%}.pdv-installment-row{grid-template-columns:1fr}.pdv-installment-row.head{display:none}.pdv-installment-row .pdv-installment-label:before{content:'Parcela: ';color:#64748b}.pdv-installment-row .pdv-installment-due-wrap:before{content:'Vencimento';display:block;font-size:12px;font-weight:900;color:#64748b;margin-bottom:3px}.pdv-installment-row .pdv-installment-amount-wrap:before{content:'Valor';display:block;font-size:12px;font-weight:900;color:#64748b;margin-bottom:3px}} 
.pdv-modal-searchbar{display:grid;grid-template-columns:minmax(0,1fr) auto auto;gap:10px;margin-bottom:12px;align-items:end}.pdv-client-results{display:grid;gap:8px;max-height:58vh;overflow:auto}.pdv-client-result{border:1px solid #dbe4f0;background:#fff;border-radius:16px;padding:12px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;text-align:left;cursor:pointer;transition:background .12s ease,border-color .12s ease,box-shadow .12s ease}.pdv-client-result:hover{border-color:#93c5fd;background:#f8fbff}.pdv-client-result.is-active{border-color:#2563eb;background:#eaf2ff;box-shadow:inset 4px 0 0 #2563eb}.pdv-client-result.is-active strong,.pdv-client-result.is-active b{color:#0b1f5b}.pdv-client-result strong{color:#0b1f5b}.pdv-client-result small{display:block;color:#64748b;margin-top:3px}.pdv-mobile-mode .pdv-wizard-shell{display:block}.pdv-mobile-mode .pdv-stepper{display:grid;grid-template-columns:repeat(5,minmax(74px,1fr));gap:7px;overflow:auto;padding-bottom:7px}.pdv-mobile-mode .pdv-step-nav{min-width:86px;grid-template-columns:1fr;text-align:center;padding:8px;border-radius:16px}.pdv-mobile-mode .pdv-step-nav span{margin:0 auto;width:34px;height:34px;border-radius:12px}.pdv-mobile-mode .pdv-step-nav small{display:none}.pdv-mobile-mode .pdv-quick-summary{margin-top:8px}.pdv-mobile-mode .pdv-client-grid,.pdv-mobile-mode .pdv-cart-step-grid,.pdv-mobile-mode .pdv-payment-step-grid{grid-template-columns:1fr}.pdv-mobile-mode .pdv-panel-card{min-height:auto}.pdv-mobile-mode .pdv-section-head h2{font-size:22px}.pdv-mobile-mode .pdv-search,.pdv-mobile-mode .pdv-client-search{grid-template-columns:1fr}.pdv-mobile-mode .pdv-products-wizard{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.pdv-mobile-mode .pdv-product{min-height:150px;border-radius:22px;padding:10px;display:flex;flex-direction:column;justify-content:space-between;text-align:left}.pdv-mobile-mode .pdv-product .pdv-img{width:100%;height:72px;object-fit:cover;border-radius:16px}.pdv-mobile-mode .pdv-product span{display:block}.pdv-mobile-mode .pdv-product strong{font-size:13px;line-height:1.22}.pdv-mobile-mode .pdv-product small{font-size:10px;line-height:1.25;max-height:28px;overflow:hidden}.pdv-mobile-mode .pdv-product b{font-size:16px;color:#047857;margin-top:5px}.pdv-mobile-mode .mg-actions{display:grid;grid-template-columns:1fr;gap:8px}.pdv-mobile-mode .btn{min-height:42px}.pdv-mobile-mode .pdv-side-summary{position:static}.pdv-mobile-mode .pdv-cats{padding-bottom:10px}.pdv-mobile-mode .pdv-cat{padding:10px 14px}

.pdv-mobile-list-head{margin-top:14px;display:flex;justify-content:space-between;gap:10px;align-items:flex-end}.pdv-mobile-list-head strong{color:#0b1f5b;font-size:16px}.pdv-mobile-list-head small{color:#64748b;font-weight:750;text-align:right}.pdv-mobile-client-list{display:grid;gap:9px;margin-top:9px;max-height:360px;overflow:auto;padding-right:3px}.pdv-mobile-client-card{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:12px;display:grid;grid-template-columns:1fr auto;gap:10px;align-items:center;text-align:left;box-shadow:0 8px 20px rgba(15,23,42,.05);cursor:pointer}.pdv-mobile-client-card strong{display:block;color:#0b1f5b;font-size:15px}.pdv-mobile-client-card small{display:block;color:#64748b;margin-top:4px;line-height:1.3}.pdv-mobile-client-card em{font-style:normal;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:7px 9px;font-size:12px;font-weight:950}.pdv-address-step-card .pdv-address-box{max-width:820px}.pdv-payment-hero{border-radius:28px;background:linear-gradient(135deg,#0b1f5b,#1d4ed8);color:#fff;padding:18px;margin:0 0 14px;box-shadow:0 18px 44px rgba(29,78,216,.28)}.pdv-payment-hero span{display:block;font-size:13px;font-weight:900;letter-spacing:.04em;text-transform:uppercase;opacity:.78}.pdv-payment-hero strong{display:block;font-size:38px;line-height:1.05;margin-top:4px}.pdv-payment-hero small{display:block;margin-top:7px;color:#dbeafe;font-weight:750}.pdv-mobile-mode .pdv-payment-summary{background:#0f172a;color:#fff;border-color:#1e293b;box-shadow:0 16px 40px rgba(2,6,23,.25)}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row span,.pdv-mobile-mode .pdv-payment-summary .pdv-customer-summary span{color:#cbd5e1}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row strong,.pdv-mobile-mode .pdv-payment-summary .pdv-customer-summary strong{color:#fff}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row.final{border-top:1px solid rgba(255,255,255,.2);margin-top:8px;padding-top:10px}.pdv-mobile-mode .pdv-payments-box{background:#eef6ff;border-color:#bfdbfe;box-shadow:0 14px 34px rgba(29,78,216,.12)}.pdv-mobile-mode .pdv-payment-line{background:#fff;border:2px solid #dbeafe;box-shadow:0 8px 22px rgba(11,31,91,.08)}.pdv-mobile-mode .pdv-payment-line select,.pdv-mobile-mode .pdv-payment-line input{font-size:17px;font-weight:800;color:#0b1f5b}.pdv-mobile-mode .pdv-finish-button{border-radius:20px;min-height:62px;font-size:18px;text-transform:uppercase;letter-spacing:.02em}


/* Venda Mobile redesenhada em estilo aplicativo */
.pdv-mobile-mode{--pdv-app-blue:#143a9c;--pdv-app-blue2:#2563eb;--pdv-app-dark:#071326;--pdv-app-muted:#64748b;--pdv-app-border:#dbe8f8;--pdv-app-bg:#f3f7ff;padding-bottom:92px}.pdv-mobile-mode .pdv-wizard-shell{margin:0 0 8px}.pdv-mobile-mode .pdv-stepper{position:sticky;top:0;z-index:45;background:linear-gradient(135deg,#0b1f5b,#2563eb);margin:-14px -14px 12px;padding:10px 12px;border-radius:0 0 22px 22px;grid-template-columns:repeat(5,minmax(44px,1fr));gap:6px;box-shadow:0 10px 26px rgba(15,23,42,.18);overflow:visible}.pdv-mobile-mode .pdv-step-nav{min-width:0;background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);box-shadow:none;padding:7px 4px;border-radius:16px;color:#fff}.pdv-mobile-mode .pdv-step-nav span{width:27px;height:27px;border-radius:10px;background:rgba(255,255,255,.2);font-size:13px}.pdv-mobile-mode .pdv-step-nav strong{font-size:10px;color:#eaf2ff;text-transform:none;letter-spacing:0}.pdv-mobile-mode .pdv-step-nav small{display:none}.pdv-mobile-mode .pdv-step-nav.active{background:#fff;border-color:#fff;box-shadow:0 8px 18px rgba(2,6,23,.16)}.pdv-mobile-mode .pdv-step-nav.active span{background:linear-gradient(135deg,var(--pdv-app-blue),var(--pdv-app-blue2));color:#fff}.pdv-mobile-mode .pdv-step-nav.active strong{color:#0b1f5b}.pdv-mobile-mode .pdv-quick-summary{display:none}.pdv-mobile-mode .pdv-panel-card{min-height:calc(100dvh - 190px);padding:14px;border-radius:28px;background:rgba(255,255,255,.96);box-shadow:0 18px 45px rgba(15,23,42,.08)}.pdv-mobile-mode .pdv-section-head{margin-bottom:10px}.pdv-mobile-mode .pdv-section-head h2{font-size:19px;margin:0;color:#0b1f5b}.pdv-mobile-mode .pdv-section-head p,.pdv-mobile-mode .pdv-mobile-list-head small,.pdv-mobile-mode .pdv-address-head small,.pdv-mobile-mode .pdv-payments-head small,.pdv-mobile-mode .pdv-payment-hero small,.pdv-mobile-mode .pdv-fiscal-choice small{display:none!important}.pdv-mobile-mode .pdv-section-head .mg-actions{display:grid;grid-template-columns:1fr 1fr;gap:8px}.pdv-mobile-mode .pdv-section-head .mg-actions .pdv-prev{order:1}.pdv-mobile-mode .pdv-section-head .mg-actions .pdv-next{order:2}.pdv-mobile-mode .pdv-client-search{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.pdv-mobile-mode .pdv-client-search label,.pdv-mobile-mode .pdv-search label{font-size:0}.pdv-mobile-mode input[type="search"],.pdv-mobile-mode .pdv-client-search input,.pdv-mobile-mode .pdv-search input{height:56px;border-radius:20px;border:2px solid var(--pdv-app-border);font-size:16px;font-weight:750;color:#0f172a;padding:0 16px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.05)}.pdv-mobile-mode input[type="search"]:focus,.pdv-mobile-mode .pdv-client-search input:focus,.pdv-mobile-mode .pdv-search input:focus{border-color:#2563eb;box-shadow:0 0 0 4px rgba(37,99,235,.12)}.pdv-mobile-mode #customerSearchBtn{height:56px;border-radius:18px;min-width:54px;font-size:0}.pdv-mobile-mode #customerSearchBtn:before{content:'🔎';font-size:18px}.pdv-mobile-mode #customerSearchBtn small{display:none}.pdv-mobile-mode #openCustomerModalBtn{grid-column:1 / -1;min-height:44px;border-radius:17px}.pdv-mobile-mode #useFinalConsumerBtn,.pdv-mobile-mode .pdv-final-consumer-grid{display:none!important}.pdv-mobile-mode .pdv-customer-card{margin-top:10px;border-radius:20px;padding:11px;background:#eef6ff}.pdv-mobile-mode .pdv-customer-card:not(.found):not(.not-found){display:none}.pdv-mobile-mode .pdv-customer-card strong{font-size:15px}.pdv-mobile-mode .pdv-customer-card small{font-size:12px}.pdv-mobile-mode .pdv-mobile-list-head{margin:10px 0 0}.pdv-mobile-mode .pdv-mobile-list-head strong{font-size:13px;color:#64748b}.pdv-mobile-mode .pdv-mobile-client-list{max-height:calc(100dvh - 330px);overflow:auto;padding:2px 2px 90px;margin-top:8px}.pdv-mobile-mode .pdv-mobile-client-card{border-radius:21px;padding:13px 14px;grid-template-columns:1fr;box-shadow:0 10px 24px rgba(15,23,42,.06)}.pdv-mobile-mode .pdv-mobile-client-card strong{font-size:16px}.pdv-mobile-mode .pdv-mobile-client-card em{display:none}.pdv-mobile-mode .pdv-address-box{padding:13px;border-radius:24px;box-shadow:none;background:#fff}.pdv-mobile-mode .pdv-address-head{margin-bottom:8px}.pdv-mobile-mode .pdv-address-head strong{font-size:17px}.pdv-mobile-mode .pdv-address-grid{gap:9px}.pdv-mobile-mode .pdv-address-grid label,.pdv-mobile-mode .mg-form-grid label{font-size:12px;color:#64748b;font-weight:850}.pdv-mobile-mode .pdv-address-grid input,.pdv-mobile-mode .pdv-address-grid select,.pdv-mobile-mode .mg-form-grid input,.pdv-mobile-mode .mg-form-grid select,.pdv-mobile-mode .mg-form-grid textarea{border-radius:18px;min-height:48px}.pdv-mobile-mode .pdv-fee-card{padding:11px;border-radius:18px}.pdv-mobile-mode .pdv-search{grid-template-columns:1fr;gap:8px;margin-bottom:8px}.pdv-mobile-mode #pdvProductSearchBtn,.pdv-mobile-mode .pdv-mini-summary{display:none!important}.pdv-mobile-mode .pdv-cats{gap:8px;margin:0 -2px 10px;padding:2px 2px 8px}.pdv-mobile-mode .pdv-cat{min-height:44px;border-radius:999px;padding:10px 14px;font-size:12px;font-weight:950;text-transform:uppercase;color:#1685d9;border-color:#dbeafe}.pdv-mobile-mode .pdv-cat.active{background:linear-gradient(135deg,#0b1f5b,#2563eb);color:#fff}.pdv-mobile-mode .pdv-products-wizard{grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;max-height:calc(100dvh - 360px);overflow:auto;padding:2px 2px 92px}.pdv-mobile-mode .pdv-products-wizard .mg-empty{grid-column:1/-1;min-height:160px;display:flex;align-items:center;justify-content:center;text-align:center;color:#94a3b8;background:#fff;border:1px dashed #dbe4f0;border-radius:24px;padding:18px}.pdv-mobile-mode .pdv-product{min-height:142px;border-radius:24px;padding:10px;background:#fff;border:1px solid #dbeafe;box-shadow:0 10px 24px rgba(15,23,42,.07);display:grid;gap:7px}.pdv-mobile-mode .pdv-product .pdv-img{height:68px;border-radius:18px;background:#f1f5f9}.pdv-mobile-mode .pdv-product strong{font-size:13px;line-height:1.15;text-transform:uppercase}.pdv-mobile-mode .pdv-product small{display:none}.pdv-mobile-mode .pdv-product b{font-size:16px;color:#047857}.pdv-mobile-mode .pdv-cart-step-grid{display:block}.pdv-mobile-mode .pdv-cart-items-step{max-height:calc(100dvh - 420px);overflow:auto;padding:2px 2px 90px}.pdv-mobile-mode .pdv-cart-item{border-radius:24px;padding:13px}.pdv-mobile-mode .pdv-cart-line strong{font-size:15px}.pdv-mobile-mode .pdv-qty{grid-template-columns:44px minmax(0,1fr) 44px 44px;align-items:center}.pdv-mobile-mode .pdv-qty button{height:44px;border-radius:16px;font-size:18px;font-weight:950}.pdv-mobile-mode .pdv-qty input{height:44px;text-align:center;border-radius:16px;font-size:17px;font-weight:850}.pdv-mobile-mode .pdv-item-note{height:48px;border-radius:16px}.pdv-mobile-mode .pdv-side-summary:not(.pdv-payment-summary){margin-top:10px;padding:12px;border-radius:22px;background:#f8fbff}.pdv-mobile-mode .pdv-side-summary:not(.pdv-payment-summary) .pdv-totals-card{display:none}.pdv-mobile-mode [data-step-panel="pagamento"] .pdv-panel-card{background:transparent;border:0;box-shadow:none;padding:0}.pdv-mobile-mode [data-step-panel="pagamento"] .pdv-section-head{display:none}.pdv-mobile-mode .pdv-payment-hero{border-radius:0 0 30px 30px;margin:-14px -14px 14px;padding:20px 20px 24px;box-shadow:0 18px 44px rgba(29,78,216,.28)}.pdv-mobile-mode .pdv-payment-hero span{font-size:11px}.pdv-mobile-mode .pdv-payment-hero strong{font-size:42px}.pdv-mobile-mode .pdv-payment-step-grid{display:block}.pdv-mobile-mode .pdv-payments-box{background:#f8fbff;border:1px solid #dbeafe;border-radius:26px;padding:13px;margin-bottom:12px}.pdv-mobile-mode .pdv-payments-head{margin-bottom:9px}.pdv-mobile-mode .pdv-payments-head strong{font-size:20px;color:#0b1f5b}.pdv-mobile-mode #addPaymentBtn{width:100%;height:52px;border-radius:18px;background:#fff}.pdv-mobile-mode .pdv-payment-shortcuts{padding:8px;gap:6px}.pdv-mobile-mode .pdv-payment-shortcut,.pdv-mobile-mode .pdv-payment-operation{font-size:11px;padding:5px 7px}.pdv-mobile-mode .pdv-payment-line,.pdv-mobile-mode .pdv-payment-line.show-received{grid-template-columns:1fr 46px;gap:9px;border-radius:23px;padding:12px;background:#fff;border:1px solid #dbeafe}.pdv-mobile-mode .pdv-payment-line label,.pdv-mobile-mode .pdv-payment-line .pay-value,.pdv-mobile-mode .pdv-payment-line .pay-received-label{grid-column:1 / -1}.pdv-mobile-mode .pdv-payment-line .pay-remove{grid-column:2;height:46px;border-radius:16px}.pdv-mobile-mode .pdv-payment-line select,.pdv-mobile-mode .pdv-payment-line input{height:54px;border-radius:18px;font-size:18px;font-weight:900}.pdv-mobile-mode .pdv-payment-summary{border-radius:28px;background:#0f172a;color:#fff;padding:17px;box-shadow:0 16px 40px rgba(2,6,23,.25)}.pdv-mobile-mode .pdv-payment-summary .pdv-customer-summary{background:#fff;border:0;border-radius:18px;margin-bottom:12px}.pdv-mobile-mode .pdv-payment-summary .pdv-customer-summary span{display:none}.pdv-mobile-mode .pdv-payment-summary .pdv-customer-summary strong{color:#0b1f5b;text-align:left}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row{font-size:15px}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row.final{font-size:24px;border-top:1px solid rgba(255,255,255,.16);padding-top:12px}.pdv-mobile-mode .pdv-finish-button{border-radius:22px;min-height:64px;font-size:18px;text-transform:uppercase;letter-spacing:.02em;background:linear-gradient(135deg,#143a9c,#2563eb)}.pdv-mobile-floating-total{position:fixed;left:14px;right:14px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:160;background:#071326;color:#fff;border-radius:25px;padding:12px 12px 12px 16px;display:none;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 20px 50px rgba(2,6,23,.35)}.pdv-mobile-mode .pdv-mobile-floating-total{display:flex}.pdv-mobile-mode.is-payment-step .pdv-mobile-floating-total{display:none}.pdv-mobile-mode.is-payment-step .pdv-mobile-payment-footer{display:flex}.pdv-mobile-mode.is-payment-step [data-step-panel="pagamento"] .pdv-payment-step-grid{padding-bottom:96px}.pdv-mobile-mode .pdv-payment-summary .pdv-total-row.final,.pdv-mobile-mode .pdv-payment-summary .pdv-finish-button{display:none!important}.pdv-mobile-floating-info{display:grid;gap:2px}.pdv-mobile-floating-info span{font-size:11px;font-weight:850;color:#cbd5e1;text-transform:uppercase;letter-spacing:.05em}.pdv-mobile-floating-info strong{font-size:22px;line-height:1;color:#fff}.pdv-mobile-floating-next{border:0;border-radius:18px;background:linear-gradient(135deg,#143a9c,#2563eb);color:#fff;min-height:52px;padding:0 20px;font-weight:950;font-size:15px;box-shadow:0 10px 28px rgba(37,99,235,.35)}.pdv-mobile-payment-footer{position:fixed;left:14px;right:14px;bottom:calc(12px + env(safe-area-inset-bottom));z-index:170;background:#071326;color:#fff;border-radius:25px;padding:12px 12px 12px 16px;display:none;align-items:center;justify-content:space-between;gap:12px;box-shadow:0 20px 50px rgba(2,6,23,.38)}.pdv-mobile-finish-floating{border:0;border-radius:18px;background:linear-gradient(135deg,#16a34a,#22c55e);color:#fff;min-height:52px;padding:0 18px;font-weight:950;font-size:15px;text-transform:uppercase;box-shadow:0 10px 28px rgba(34,197,94,.28)}.pdv-mobile-toast{position:fixed;left:50%;bottom:28px;transform:translate(-50%,20px);z-index:420;background:#047857;color:#fff;border-radius:999px;padding:11px 17px;font-weight:950;box-shadow:0 14px 36px rgba(4,120,87,.25);opacity:0;pointer-events:none;transition:.22s ease;white-space:nowrap}.pdv-mobile-toast.show{opacity:1;transform:translate(-50%,0)}.pdv-mobile-mode .pdv-mobile-toast{bottom:calc(92px + env(safe-area-inset-bottom))}@media(max-width:760px){body:has(.pdv-mobile-mode){background:#f3f7ff}.pdv-mobile-mode .pdv-panel-card{margin-left:-2px;margin-right:-2px}.pdv-mobile-mode .pdv-section-head .mg-actions{grid-template-columns:1fr 1fr}.pdv-mobile-mode .pdv-section-head .mg-actions .btn{margin-top:0}.pdv-mobile-mode .finance-modal{align-items:flex-end;padding:0}.pdv-mobile-mode .finance-modal-dialog{border-radius:28px 28px 0 0;max-height:88dvh;overflow:auto}.pdv-mobile-mode .pdv-fiscal-choice-grid{grid-template-columns:1fr}.pdv-mobile-mode .pdv-opt-items{max-height:45dvh;overflow:auto}.pdv-mobile-mode .pdv-products-wizard{grid-template-columns:repeat(2,minmax(0,1fr))}}

@media(max-width:1180px){.pdv-wizard-shell{grid-template-columns:1fr}.pdv-stepper{grid-template-columns:repeat(2,minmax(0,1fr))}.pdv-quick-summary{grid-template-columns:1fr 1fr 1fr auto}.pdv-client-grid,.pdv-cart-step-grid,.pdv-payment-step-grid{grid-template-columns:1fr}.pdv-side-summary{position:static}.pdv-panel-card{min-height:auto}}@media(max-width:760px){.pdv-mobile-manual-consumer-fields{grid-template-columns:1fr!important}.pdv-mobile-manual-consumer-fields input{min-height:50px;font-size:16px}.pdv-stepper{grid-template-columns:1fr}.pdv-step-nav{grid-template-columns:38px 1fr;padding:10px;border-radius:17px}.pdv-step-nav span{width:38px;height:38px}.pdv-quick-summary{grid-template-columns:1fr}.pdv-section-head{display:block}.pdv-section-head .btn,.pdv-section-head .mg-actions{width:100%;margin-top:10px}.pdv-section-head .mg-actions{display:grid;grid-template-columns:1fr}.pdv-products-wizard{grid-template-columns:1fr}.pdv-client-search,.pdv-search{grid-template-columns:1fr}.pdv-address-head{display:block}.pdv-address-head .btn{width:100%;margin-top:8px}.pdv-payment-line,.pdv-payment-line.show-received{grid-template-columns:1fr 42px}.pdv-payment-line label:first-child,.pdv-payment-line .pay-value,.pdv-payment-line .pay-received-label{grid-column:1 / -1}.pdv-payment-line .pay-remove{grid-column:2}.pdv-payments-head{display:block}.pdv-payments-head .btn{width:100%;margin-top:8px}.pdv-side-summary{padding:13px;border-radius:18px}.pdv-panel-card{padding:14px}.finance-modal{padding:10px}.finance-modal-dialog{border-radius:20px;padding:14px}.pdv-opt-item span{display:block}.pdv-opt-item small{display:block;margin-top:4px}}

/* PDV compacto: reduz aproximadamente pela metade a altura do passo a passo. */
.pdv-wizard-shell{align-items:start;gap:8px;margin-bottom:8px}
.pdv-stepper{gap:6px;align-self:start}
.pdv-step-nav{min-height:46px;border-radius:12px;padding:5px 7px;grid-template-columns:28px 1fr;gap:4px;box-shadow:0 5px 14px rgba(15,23,42,.04)}
.pdv-step-nav span{width:28px;height:28px;border-radius:9px;font-size:12px}
.pdv-step-nav strong{font-size:12px}
.pdv-step-nav small{font-size:10px;line-height:1.05}
.pdv-step-nav.active{box-shadow:0 8px 22px rgba(37,99,235,.18)}
.pdv-quick-summary{padding:7px 9px;grid-template-columns:1fr 1fr;gap:4px;border-radius:14px}
.pdv-quick-summary div{min-height:22px}
.pdv-quick-summary span{font-size:10px}.pdv-quick-summary strong{font-size:12px}
.pdv-quick-summary .btn{grid-column:1/-1;min-height:30px;padding:5px 10px;font-size:11px}
.pdv-finish-button{display:flex;align-items:center;justify-content:center;gap:10px}.pdv-finish-button small{font-size:11px;opacity:.82}
@media(max-width:1180px){.pdv-quick-summary{grid-template-columns:repeat(3,minmax(0,1fr)) auto}.pdv-quick-summary .btn{grid-column:auto}}
@media(max-width:760px){.pdv-stepper{grid-template-columns:repeat(2,minmax(0,1fr))}.pdv-step-nav{grid-template-columns:28px 1fr;padding:5px 7px;border-radius:12px}.pdv-step-nav span{width:28px;height:28px}.pdv-quick-summary{grid-template-columns:1fr 1fr}.pdv-quick-summary .btn{grid-column:1/-1}}


/* ==========================================================================
   Venda Mobile - refinamento visual claro (17/08/2026)
   Referência: layout aprovado com fundo claro e bordas mais nítidas.
   Regras restritas ao .pdv-mobile-mode para preservar o PDV desktop.
   ========================================================================== */
.pdv-mobile-brand-subtitle{display:none}

@media(max-width:760px){
  body:has(.pdv-mobile-mode){background:linear-gradient(180deg,#f8fbff 0%,#f3f7fc 100%)!important}
  body:has(.pdv-mobile-mode) .container,body.admin-side-menu-enabled:has(.pdv-mobile-mode) .container,body.admin-compact-mobile:has(.pdv-mobile-mode) .container,body.admin-compact-side-menu:has(.pdv-mobile-mode) .container{padding:8px 12px 110px!important}
  .pdv-mobile-mode{--pdv-mobile-blue:#1557e8;--pdv-mobile-blue-dark:#0b2d75;--pdv-mobile-border:#9aa9c1;--pdv-mobile-border-soft:#c8d3e2;--pdv-mobile-muted:#65748b;--pdv-mobile-surface:#fff;--pdv-mobile-soft:#f8fbff}

  .pdv-mobile-mode .pdv-wizard-shell{display:block;margin:0 0 12px;padding:0!important}
  .pdv-mobile-mode .pdv-stepper{display:grid!important;grid-template-columns:repeat(5,minmax(0,1fr))!important;gap:4px!important;padding:8px 6px!important;border:1px solid #cad7ea!important;border-radius:24px!important;background:rgba(255,255,255,.96)!important;box-shadow:0 10px 28px rgba(15,42,91,.08)!important;overflow:hidden}
  .pdv-mobile-mode .pdv-step-nav{min-width:0!important;min-height:76px!important;padding:7px 3px!important;border:1px solid transparent!important;border-radius:20px!important;background:transparent!important;box-shadow:none!important;display:flex!important;flex-direction:column!important;align-items:center!important;justify-content:center!important;gap:5px!important;text-align:center!important}
  .pdv-mobile-mode .pdv-step-nav span{width:34px!important;height:34px!important;border-radius:50%!important;border:1px solid #c8d5e8!important;background:#fff!important;color:#1557e8!important;font-size:14px!important;font-weight:950!important;box-shadow:0 4px 10px rgba(23,74,157,.06)!important}
  .pdv-mobile-mode .pdv-step-nav strong{width:100%;color:#34445e!important;font-size:10.5px!important;line-height:1.05!important;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .pdv-mobile-mode .pdv-step-nav small{display:none!important}
  .pdv-mobile-mode .pdv-step-nav.active{border-color:#1765f2!important;background:linear-gradient(145deg,#0d4fdf,#246df5)!important;box-shadow:0 8px 20px rgba(21,87,232,.25)!important}
  .pdv-mobile-mode .pdv-step-nav.active span{border-color:rgba(255,255,255,.38)!important;background:rgba(255,255,255,.14)!important;color:#fff!important}
  .pdv-mobile-mode .pdv-step-nav.active strong{color:#fff!important}
  .pdv-mobile-mode .pdv-quick-summary{display:none!important}

  .pdv-mobile-mode .pdv-panel-card{margin:0!important;padding:18px 14px 116px!important;border:1px solid #cfdaea!important;border-radius:28px!important;background:#fff!important;box-shadow:0 18px 42px rgba(29,55,98,.09)!important}
  .pdv-mobile-mode .pdv-section-head{display:flex!important;align-items:flex-start!important;justify-content:space-between!important;gap:10px!important;margin-bottom:15px!important}
  .pdv-mobile-mode .pdv-brand-head{display:flex!important;align-items:center!important;gap:11px!important;min-width:0}
  .pdv-mobile-mode .pdv-brand-logo{width:54px!important;height:54px!important;border-radius:14px!important;border:1px solid #d8e1ed!important;box-shadow:0 6px 16px rgba(15,42,91,.08)!important}
  .pdv-mobile-mode .pdv-section-head h2{margin:0!important;color:#0b2d75!important;font-size:21px!important;line-height:1.08!important;letter-spacing:-.02em}
  .pdv-mobile-mode .pdv-mobile-brand-subtitle{display:block;margin:5px 0 0;color:#728096;font-size:12px;line-height:1.15}
  .pdv-mobile-mode .pdv-section-head .mg-actions{width:auto!important;margin:0!important;display:block!important}
  .pdv-mobile-mode #useFinalConsumerBtn{display:none!important}
  .pdv-mobile-mode .pdv-section-head .pdv-next{min-height:38px!important;padding:0 12px!important;margin:0!important;border:0!important;border-radius:999px!important;background:#edf4ff!important;color:#1557e8!important;box-shadow:none!important;font-size:11px!important;font-weight:900!important;white-space:nowrap}

  .pdv-mobile-mode .pdv-client-grid{display:block!important}
  .pdv-mobile-mode .pdv-client-search{display:grid!important;grid-template-columns:minmax(0,1fr) 58px!important;gap:8px!important;align-items:stretch!important}
  .pdv-mobile-mode .pdv-client-search>label{display:block!important;font-size:0!important;min-width:0!important;margin:0!important}
  .pdv-mobile-mode #customerLookup{width:100%!important;height:58px!important;min-height:58px!important;padding:0 16px!important;border:1.7px solid #8798b4!important;border-radius:19px!important;background:#fff!important;color:#17223a!important;font-size:16px!important;font-weight:700!important;box-shadow:0 7px 18px rgba(23,46,82,.06)!important;outline:none!important}
  .pdv-mobile-mode #customerLookup::placeholder{color:#7b8493!important;opacity:.78}
  .pdv-mobile-mode #customerLookup:focus{border-color:#1557e8!important;box-shadow:0 0 0 4px rgba(21,87,232,.11)!important}
  .pdv-mobile-mode #customerSearchBtn{width:58px!important;min-width:58px!important;height:58px!important;padding:0!important;border:0!important;border-radius:18px!important;background:linear-gradient(145deg,#0e52e4,#256df2)!important;color:#fff!important;box-shadow:0 8px 20px rgba(21,87,232,.23)!important}
  .pdv-mobile-mode #customerSearchBtn:before{font-size:20px!important;filter:grayscale(1) brightness(0) invert(1)}
  .pdv-mobile-mode #openCustomerModalBtn{grid-column:1/-1!important;min-height:52px!important;margin-top:2px!important;border:0!important;border-radius:18px!important;background:linear-gradient(100deg,#0957e8 0%,#295eea 58%,#7b25ea 100%)!important;color:#fff!important;box-shadow:0 9px 22px rgba(64,73,203,.20)!important;font-size:15px!important;font-weight:900!important}

  .pdv-mobile-mode .pdv-mobile-manual-consumer-fields{margin-top:12px!important;padding:12px!important;gap:11px!important;border:1.4px solid #a7b6cc!important;border-radius:22px!important;background:#f9fbfe!important;box-shadow:inset 0 1px 0 rgba(255,255,255,.8)!important}
  .pdv-mobile-mode .pdv-mobile-manual-consumer-fields label{color:#586a84!important;font-size:12px!important;font-weight:850!important}
  .pdv-mobile-mode .pdv-mobile-manual-consumer-fields input{min-height:52px!important;margin-top:6px!important;padding:0 14px!important;border:1.5px solid #8798b4!important;border-radius:17px!important;background:#fff!important;color:#15213a!important;font-size:15px!important;font-weight:700!important;box-shadow:0 5px 14px rgba(23,46,82,.05)!important}
  .pdv-mobile-mode .pdv-mobile-manual-consumer-fields input:focus{border-color:#1557e8!important;box-shadow:0 0 0 3px rgba(21,87,232,.10)!important}

  .pdv-mobile-mode .pdv-mobile-list-head{margin:14px 0 7px!important;padding:0 2px!important}
  .pdv-mobile-mode .pdv-mobile-list-head strong{display:block!important;color:#159447!important;font-size:13px!important;font-weight:950!important;text-transform:uppercase;letter-spacing:.02em}
  .pdv-mobile-mode .pdv-mobile-list-head small{display:none!important}
  .pdv-mobile-mode .pdv-mobile-client-list{max-height:210px!important;min-height:82px!important;overflow:auto!important;margin:0 0 12px!important;padding:8px!important;border:1px solid #c9d6e6!important;border-radius:20px!important;background:linear-gradient(180deg,#fbfdff,#f5f9ff)!important}
  .pdv-mobile-mode .pdv-mobile-client-list:empty:before{content:'Pesquise acima para encontrar clientes cadastrados';min-height:64px;display:flex;align-items:center;justify-content:center;padding:0 20px;color:#7c8aa0;font-size:12px;line-height:1.35;text-align:center}
  .pdv-mobile-mode .pdv-mobile-client-card{margin:0 0 7px!important;padding:12px!important;border:1.2px solid #a8b7cb!important;border-radius:16px!important;background:#fff!important;box-shadow:0 4px 12px rgba(22,49,86,.05)!important}

  .pdv-mobile-mode .pdv-mobile-order-fields{display:grid!important;grid-template-columns:1fr!important;gap:12px!important;margin-top:8px!important}
  .pdv-mobile-mode .pdv-mobile-order-fields>label{color:#6b2be9!important;font-size:12px!important;font-weight:950!important;text-transform:uppercase;letter-spacing:.02em}
  .pdv-mobile-mode .pdv-mobile-order-fields>label:nth-child(2){color:#f07a16!important}
  .pdv-mobile-mode .pdv-mobile-order-fields select,.pdv-mobile-mode .pdv-mobile-order-fields input{width:100%!important;min-height:52px!important;margin-top:6px!important;padding:0 14px!important;border:1.5px solid #8798b4!important;border-radius:17px!important;background:#fff!important;color:#18243c!important;font-size:15px!important;font-weight:700!important;box-shadow:0 5px 14px rgba(23,46,82,.05)!important}

  .pdv-mobile-mode .pdv-address-grid input,.pdv-mobile-mode .pdv-address-grid select,.pdv-mobile-mode .mg-form-grid input,.pdv-mobile-mode .mg-form-grid select,.pdv-mobile-mode .mg-form-grid textarea,.pdv-mobile-mode .pdv-payment-line select,.pdv-mobile-mode .pdv-payment-line input,.pdv-mobile-mode .pdv-item-note{border-color:#93a3ba!important}

  .pdv-mobile-mode .pdv-mobile-floating-total{left:12px!important;right:12px!important;bottom:calc(10px + env(safe-area-inset-bottom))!important;min-height:74px!important;padding:11px 11px 11px 16px!important;border:1px solid rgba(255,255,255,.28)!important;border-radius:26px!important;background:linear-gradient(105deg,#0d54e7,#1261f1 58%,#1557e8)!important;box-shadow:0 18px 38px rgba(12,66,172,.27)!important}
  .pdv-mobile-mode .pdv-mobile-floating-info span{color:#d9e7ff!important;font-size:10.5px!important}
  .pdv-mobile-mode .pdv-mobile-floating-info strong{font-size:24px!important}
  .pdv-mobile-mode .pdv-mobile-floating-next{min-width:116px!important;min-height:54px!important;padding:0 17px!important;border:0!important;border-radius:18px!important;background:#fff!important;color:#1557e8!important;box-shadow:0 7px 18px rgba(7,38,107,.16)!important;font-size:16px!important;font-weight:950!important}
}

</style>
<style id="nexa-pdv-checkout-theme">
/* PDV Checkout — layout isolado inspirado em checkout de supermercado.
   O motor funcional permanece o mesmo do Pedido PDV. */
body:has(.pdv-checkout-mode){background:#edf3fb!important;overflow:hidden!important}
body:has(.pdv-checkout-mode) .container{width:100%!important;max-width:none!important;margin:0!important;padding:0!important;background:#edf3fb!important}
.pdv-checkout-mode{display:grid!important;grid-template-columns:minmax(420px,.98fr) minmax(560px,1.02fr);grid-template-rows:auto minmax(0,1fr) auto;gap:10px;height:100vh;max-height:100vh;min-height:100vh;width:100%;max-width:none;margin:0;padding:0 10px 10px;background:#edf3fb;position:relative;overflow:hidden;box-sizing:border-box}
.pdv-checkout-mode .checkout-topbar{grid-column:1/-1;margin:0 -10px 0;min-height:68px;display:grid;grid-template-columns:minmax(260px,.75fr) minmax(560px,1.7fr) minmax(150px,.55fr);align-items:center;gap:18px;padding:8px 24px;background:linear-gradient(110deg,#073d86,#045bb9 58%,#063f88);color:#fff;box-shadow:0 5px 18px rgba(3,61,133,.22);z-index:5}
.checkout-title{display:flex;align-items:center;gap:14px;font-size:25px;letter-spacing:.01em}.checkout-title strong{white-space:nowrap}.checkout-cart-icon{font-size:30px;filter:grayscale(1) brightness(0) invert(1)}
.checkout-session-meta{display:flex;align-items:center;justify-content:center;gap:16px;font-size:14px;white-space:nowrap}.checkout-session-meta span{opacity:.96}.checkout-session-meta b{font-weight:950}.checkout-session-meta i{width:1px;height:24px;background:rgba(255,255,255,.42)}
.checkout-nexa-brand{display:flex;justify-content:flex-end;align-items:center}.checkout-nexa-brand img{width:192px;height:60px;object-fit:contain;border-radius:11px;background:#fff;padding:1px 2px;border:1px solid rgba(255,255,255,.72);box-shadow:0 7px 18px rgba(2,24,65,.22)}
.pdv-checkout-mode>.pdv-wizard-shell,.pdv-checkout-mode>[data-step-panel="cliente"]{display:none!important}
.pdv-checkout-mode>[data-step-panel="produtos"]{grid-column:1;grid-row:2;display:block!important;min-width:0;min-height:0;height:100%;overflow:hidden}
.pdv-checkout-mode>[data-step-panel="carrinho"]{grid-column:2;grid-row:2;display:block!important;min-width:0;min-height:0;height:100%;overflow:hidden}
.pdv-checkout-mode>[data-step-panel="pagamento"]{display:none!important;grid-column:1/-1;grid-row:2;min-width:0}
.pdv-checkout-mode[data-current-step="pagamento"]>[data-step-panel="produtos"],.pdv-checkout-mode[data-current-step="pagamento"]>[data-step-panel="carrinho"]{display:none!important}
.pdv-checkout-mode[data-current-step="pagamento"]>[data-step-panel="pagamento"]{display:block!important}
.pdv-checkout-mode .pdv-panel-card{height:100%;max-height:100%;min-height:0!important;border:1px solid #c9d8eb;border-radius:13px!important;padding:12px!important;background:#fff;box-shadow:0 7px 22px rgba(15,54,104,.08);overflow:hidden}
.pdv-checkout-mode [data-step-panel="produtos"] .pdv-panel-card{display:grid;grid-template-rows:minmax(180px,1fr) auto auto auto auto;gap:11px;background:linear-gradient(150deg,#f9fcff,#eef6ff);overflow:hidden}
.pdv-checkout-mode [data-step-panel="produtos"] .pdv-section-head,.pdv-checkout-mode [data-step-panel="carrinho"] .pdv-section-head{display:none!important}
.checkout-logo-panel{min-height:180px;height:100%;display:flex;align-items:center;justify-content:center;border:2px dashed #a9c3e3;border-radius:13px;background:radial-gradient(circle at 50% 40%,#fff 0,#f7fbff 62%,#eef5fc 100%);overflow:hidden;padding:20px}
.checkout-logo-panel>img{width:8cm;height:8cm;max-width:min(8cm,92%);max-height:min(8cm,92%);object-fit:contain;display:block}.checkout-logo-placeholder{text-align:center;color:#5278a8}.checkout-logo-placeholder strong{display:block;font-size:32px;font-weight:500}.checkout-logo-placeholder small{display:block;margin-top:8px;font-size:13px}
.pdv-checkout-mode .pdv-search-advanced{display:grid!important;grid-template-columns:minmax(0,1fr) 112px!important;gap:8px!important;margin:0!important;align-items:end!important;flex:0 0 auto}.pdv-checkout-mode .pdv-search-mode-label,.pdv-checkout-mode .pdv-mini-summary{display:none!important}.pdv-checkout-mode .pdv-product-entry{display:flex!important;align-items:flex-end!important;gap:8px!important;min-width:0;width:100%}.pdv-checkout-mode .pdv-product-entry label{display:flex!important;flex-direction:column!important;justify-content:flex-end!important;gap:6px!important;margin:0!important;min-width:0;flex:1 1 auto;width:calc(100% - 90px);font-size:13px;font-weight:950;color:#0b4f97;letter-spacing:.01em;line-height:1.15}.pdv-checkout-mode .pdv-product-entry label input{margin-top:0!important;width:100%!important}.pdv-checkout-mode #pdvSearch{width:100%!important;height:58px!important;min-height:58px!important;border:2px solid #1684e8!important;border-radius:10px!important;background:#fff!important;font-size:21px!important;font-weight:800!important;color:#0d2550!important;padding:0 15px!important;box-shadow:0 0 0 4px rgba(22,132,232,.08)!important}.pdv-checkout-mode #pdvProductSearchBtn{height:58px!important;min-height:58px!important;align-self:end!important;top:0!important;border-radius:10px!important;background:#0b65bd!important;border-color:#0b65bd!important;color:#fff!important;font-weight:950!important;margin:0!important}.pdv-checkout-mode #pdvPreparedQty{width:82px!important;flex:0 0 82px!important;height:58px!important;min-height:58px!important;align-self:end!important;top:0!important;border-radius:10px!important;margin:0!important}
.checkout-current-item{min-height:103px;border:1px solid #c9d8eb;border-radius:11px;background:linear-gradient(120deg,#f6fbff,#e9f4ff);padding:16px 18px;display:flex;flex-direction:column;justify-content:center;gap:8px;overflow:hidden;flex:0 0 auto}.checkout-current-item strong{font-size:25px;line-height:1.15;color:#07184d;text-transform:uppercase;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.checkout-current-item span{font-size:20px;font-weight:800;color:#1b5189;letter-spacing:.02em;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.checkout-metrics{display:grid;grid-template-columns:.72fr 1.28fr;gap:10px}.checkout-metric{min-height:105px;border:1px solid #b9cde4;border-radius:11px;background:linear-gradient(135deg,#eff8ff,#dfeffc);padding:14px 17px;display:flex;align-items:center;justify-content:space-between;gap:12px}.checkout-metric span{font-size:14px;font-weight:950;color:#174f8b}.checkout-metric strong{font-size:42px;line-height:1;color:#07184d}.checkout-metric-total{background:linear-gradient(135deg,#e8ffd8,#c9ff9d);border-color:#a4e279}.checkout-metric-total span{color:#195f20}.checkout-metric-total strong{font-size:44px;color:#075e0e}
.checkout-customer-strip{display:flex;justify-content:space-between;align-items:center;gap:12px;min-height:42px;padding:8px 12px;border:1px solid #c9d8eb;border-radius:10px;background:#fff;flex:0 0 auto}.checkout-customer-strip span{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase}.checkout-customer-strip strong{font-size:14px;color:#0b1f5b;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.pdv-checkout-mode #pdvCats,.pdv-checkout-mode #pdvProducts{display:none!important}
.pdv-checkout-mode [data-step-panel="carrinho"] .pdv-panel-card{display:grid;grid-template-rows:minmax(0,1fr);overflow:hidden}.pdv-checkout-mode .pdv-cart-step-grid{display:grid!important;grid-template-columns:1fr!important;grid-template-rows:minmax(0,1fr) auto!important;gap:9px;height:100%;min-height:0;overflow:hidden}.pdv-checkout-mode .pdv-cart-step-grid>div:first-child{display:grid;grid-template-rows:auto minmax(0,1fr) auto;min-height:0;height:100%;overflow:hidden;width:100%}.checkout-sale-head{display:grid;grid-template-columns:88px minmax(280px,1fr) 170px 120px 34px;gap:8px;align-items:center;min-height:42px;padding:0 11px;border-radius:10px 10px 0 0;background:linear-gradient(180deg,#e8f2ff,#d8e7f8);color:#174f8b;font-size:12px;font-weight:950;text-transform:uppercase;border:1px solid #c9d8eb;border-bottom:0;flex:0 0 auto;width:100%}.checkout-sale-head span:nth-child(4){text-align:right}
.pdv-checkout-mode #cartItems{height:100%;min-height:0;max-height:none!important;overflow:auto!important;overflow-x:auto!important;overflow-y:auto!important;padding:0!important;border:1px solid #c9d8eb;border-radius:0 0 10px 10px;background:#fff;display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;scrollbar-gutter:stable both-edges;width:100%;box-sizing:border-box}.checkout-sale-row{display:grid;grid-template-columns:88px minmax(280px,1fr) 170px 120px 34px;gap:6px 8px;align-items:center;padding:8px 11px;border-bottom:1px solid #dbe5f0;cursor:pointer;transition:.13s ease;background:#fff;min-height:62px;flex:0 0 auto}.checkout-sale-row:last-child{border-bottom:0}.checkout-sale-row:hover{background:#f5faff}.checkout-sale-row.is-selected{background:linear-gradient(90deg,#caff9d,#e5ffd0);box-shadow:inset 4px 0 0 #56bf19}.checkout-sale-code{font-size:17px;font-weight:950;color:#07184d}.checkout-sale-desc{min-width:0;overflow:hidden}.checkout-sale-desc strong{display:block;font-size:15px;color:#07184d;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}.checkout-sale-desc small{display:block;margin-top:2px;color:#45617f;font-size:11px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.checkout-sale-calc{font-size:13px;font-weight:850;color:#174f8b;white-space:nowrap}.checkout-sale-total{text-align:right;font-size:18px;font-weight:950;color:#07184d;white-space:nowrap}.checkout-sale-action{width:31px;height:31px;border:0;border-radius:8px;background:#fff0f0;color:#cf2020;font-size:18px;font-weight:950;cursor:pointer}.checkout-sale-row .pdv-item-note,.checkout-sale-qty-controls{display:none!important}
.pdv-checkout-mode .pdv-side-summary{position:static!important;top:auto!important;border-radius:10px!important;padding:7px 10px!important;background:#f7fbff!important;flex:0 0 auto;width:100%;box-sizing:border-box}.pdv-checkout-mode .pdv-side-summary .mg-form-grid{grid-template-columns:.55fr .85fr .55fr .85fr 1.2fr!important;gap:7px!important;align-items:end!important}.pdv-checkout-mode .pdv-side-summary .mg-form-grid label{font-size:10px!important;min-width:0!important}.pdv-checkout-mode .pdv-side-summary .mg-form-grid label small{display:block!important;font-size:8px!important;line-height:1.1!important;margin-top:2px!important;white-space:nowrap}.pdv-checkout-mode .pdv-side-summary .mg-form-grid .span-2{grid-column:span 1!important}.pdv-checkout-mode .pdv-side-summary input{width:100%!important;min-width:0!important;min-height:32px!important;padding:5px 7px!important;font-size:12px!important;box-sizing:border-box}.pdv-checkout-mode .pdv-side-summary textarea{display:none!important}.pdv-checkout-mode .pdv-totals-card{display:none!important}
.pdv-checkout-mode[data-current-step="pagamento"] .pdv-panel-card{min-height:calc(100vh - 175px)!important}.pdv-checkout-mode [data-step-panel="pagamento"] .pdv-section-head{margin-bottom:8px}.pdv-checkout-mode [data-step-panel="pagamento"] .pdv-payment-step-grid{grid-template-columns:minmax(0,1fr) 390px!important}.pdv-checkout-mode .pdv-payment-summary{position:sticky!important;top:10px!important}.pdv-checkout-mode .pdv-finish-button{min-height:58px!important;font-size:18px!important;background:linear-gradient(135deg,#078a32,#18b64c)!important;border-color:#078a32!important}
.checkout-actionbar{grid-column:1/-1;grid-row:3;display:grid;grid-template-columns:repeat(11,minmax(0,1fr));gap:5px;position:sticky;bottom:0;z-index:40;padding-top:2px;background:#edf3fb;flex:0 0 auto}.checkout-action{min-height:74px;min-width:0;padding:8px 7px;border:2px solid #6aa6df;border-radius:10px;background:linear-gradient(180deg,#f5fbff,#e8f4ff);display:flex;align-items:center;justify-content:center;gap:8px;cursor:pointer;font-weight:950;box-shadow:0 5px 14px rgba(20,69,120,.08)}.checkout-action kbd{font:950 21px/1 system-ui;background:transparent;color:#0b2e70;padding:0;white-space:nowrap}.checkout-action span{font-size:13px;line-height:1.12;color:#0b2e70;text-align:center}.checkout-action-primary{background:linear-gradient(180deg,#0875cf,#0058b3);border-color:#0058b3}.checkout-action-primary kbd,.checkout-action-primary span{color:#fff}.checkout-action-green{background:linear-gradient(180deg,#ebfff0,#d8ffe1);border-color:#43bf70}.checkout-action-green kbd,.checkout-action-green span{color:#09742d}.checkout-action-red{background:linear-gradient(180deg,#fff8f8,#ffecec);border-color:#f08c8c}.checkout-action-red kbd,.checkout-action-red span{color:#c80b13}.checkout-action-amber{background:linear-gradient(180deg,#fffaf0,#fff1cf);border-color:#e7ad36}.checkout-action-amber kbd,.checkout-action-amber span{color:#8a5600}.checkout-action-cyan{background:linear-gradient(180deg,#effcff,#dff8ff);border-color:#42a9c9}.checkout-action-cyan kbd,.checkout-action-cyan span{color:#08647f}.checkout-action-violet{background:linear-gradient(180deg,#f8f5ff,#eee8ff);border-color:#8c73dc}.checkout-action-violet kbd,.checkout-action-violet span{color:#5230a8}.checkout-action-os{background:linear-gradient(180deg,#fff8ee,#ffe8c2);border-color:#d7922f}.checkout-action-os kbd,.checkout-action-os span{color:#7b4700}.checkout-action-exit{background:linear-gradient(180deg,#f5f7fa,#e7ebf0);border-color:#8996a5}.checkout-action-exit kbd,.checkout-action-exit span{color:#334155}.checkout-os-fiscal-summary{margin-bottom:12px;padding:11px 13px;border:1px solid #bfdbfe;border-radius:13px;background:#eff6ff;color:#1e3a8a;font-weight:800}.checkout-os-fiscal-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.checkout-os-fiscal-grid label{display:grid;gap:7px;border:1px solid #dbe4f0;border-radius:15px;padding:13px;background:#f8fbff}.checkout-os-fiscal-grid label strong{color:#0b1f5b}.checkout-os-fiscal-grid label small{color:#64748b;line-height:1.3}.checkout-os-fiscal-grid select{min-height:46px;border:1px solid #cbd5e1;border-radius:10px;padding:8px 10px;background:#fff}/* Vendedor no cabeçalho; 11 ações numa linha no Checkout desktop. */
.pdv-checkout-mode .checkout-session-meta{gap:9px;min-width:0;font-size:clamp(10px,.88vw,13px)}
.pdv-checkout-mode .checkout-session-meta i{flex:0 0 1px}
.pdv-checkout-mode .checkout-seller-meta{min-width:0;max-width:200px;overflow:hidden;text-overflow:ellipsis}
.pdv-checkout-mode .checkout-seller-meta b{display:inline;overflow:hidden;text-overflow:ellipsis}
.pdv-checkout-mode .checkout-actionbar .checkout-action{min-width:0;padding:7px 3px;gap:4px;flex-direction:column;min-height:66px}
.pdv-checkout-mode .checkout-actionbar .checkout-action kbd{font-size:clamp(12px,1.1vw,17px)}
.pdv-checkout-mode .checkout-actionbar .checkout-action span{font-size:clamp(10px,.85vw,12px);overflow-wrap:anywhere}
@media (min-width:981px) and (max-width:1280px){.pdv-checkout-mode .checkout-topbar{grid-template-columns:minmax(170px,.6fr) minmax(0,2fr) minmax(115px,.4fr);gap:8px;padding:8px 12px}.pdv-checkout-mode .checkout-nexa-brand img{width:125px}.pdv-checkout-mode .checkout-title{font-size:19px}.pdv-checkout-mode .checkout-session-meta{gap:5px}}
.checkout-action:disabled{opacity:.45;cursor:not-allowed;filter:grayscale(.25)}.checkout-action:focus-visible{outline:4px solid rgba(22,132,232,.28);outline-offset:2px}
.finance-modal.checkout-center-modal{align-items:center!important}.checkout-reader-dialog{width:min(980px,96vw)!important;max-height:88vh;display:flex;flex-direction:column;overflow:hidden}.checkout-value-dialog{width:min(560px,96vw)!important}.checkout-value-entry{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}.checkout-value-entry label{font-weight:900;color:#0b1f5b}.checkout-value-entry input{width:100%;min-height:52px;margin-top:6px;border:2px solid #1684e8;border-radius:12px;padding:0 14px;font-size:25px;font-weight:900}.checkout-reader-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:9px;padding:4px 0 12px}.checkout-reader-search input{min-height:46px;border:1px solid #b9cbe0;border-radius:12px;padding:0 13px;font-size:15px}.checkout-reader-selected{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:9px 11px;border:1px solid #dbe5f0;border-radius:12px;background:#f7fbff;margin-bottom:9px}.checkout-reader-selected strong{color:#0b1f5b}.checkout-reader-results{flex:1;min-height:260px;overflow:auto;border:1px solid #dbe5f0;border-radius:14px;background:#fff;padding:6px}.checkout-reader-empty{padding:28px;text-align:center;color:#64748b}.checkout-reader-row{display:grid;grid-template-columns:38px 115px minmax(180px,1fr) 100px 118px;gap:9px;align-items:center;width:100%;min-height:58px;padding:7px 10px;border:0;border-bottom:1px solid #e6edf5;background:#fff;text-align:left}.checkout-reader-row:last-child{border-bottom:0}.checkout-reader-row:hover{background:#f5faff}.checkout-reader-row.is-selected{background:#eaffda}.checkout-reader-row input[type=checkbox]{width:20px;height:20px}.checkout-reader-code{font-weight:950;color:#0b1f5b}.checkout-reader-code small,.checkout-reader-name small{display:block;margin-top:2px;color:#64748b;font-size:11px}.checkout-reader-name{min-width:0}.checkout-reader-name strong{display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.checkout-reader-meta{text-align:center;color:#31577f;font-size:12px}.checkout-reader-total{text-align:right;font-weight:950;color:#0b1f5b}.checkout-reader-footer{display:flex;justify-content:flex-end;gap:8px;padding-top:11px}.checkout-reader-loading{opacity:.6;pointer-events:none}
.pdv-checkout-mode .pdv-mobile-toast{bottom:94px}
.checkout-action-modal{position:fixed;inset:0;z-index:650;display:flex;align-items:center;justify-content:center;padding:24px;background:rgba(7,24,77,.45);backdrop-filter:blur(5px)}.checkout-action-dialog{width:min(460px,calc(100vw - 36px));border:1px solid #d7e2f0;border-radius:24px;background:#fff;padding:26px 26px 22px;text-align:center;box-shadow:0 28px 80px rgba(15,23,42,.30);animation:checkoutDialogIn .16s ease-out}.checkout-action-icon{width:62px;height:62px;margin:0 auto 14px;border-radius:20px;display:flex;align-items:center;justify-content:center;background:#eaf3ff;color:#0b65bd;font-size:32px;font-weight:950}.checkout-action-dialog.is-danger .checkout-action-icon{background:#fff0f0;color:#c81e1e}.checkout-action-dialog.is-warning .checkout-action-icon{background:#fff7df;color:#b76a00}.checkout-action-dialog.is-success .checkout-action-icon{background:#e9fff0;color:#078a32}.checkout-action-dialog h3{margin:0 0 9px;color:#07184d;font-size:23px;line-height:1.2}.checkout-action-dialog p{margin:0;color:#556987;font-size:15px;line-height:1.55;white-space:pre-line}.checkout-action-dialog-buttons{display:flex;justify-content:center;gap:10px;margin-top:22px}.checkout-action-dialog-buttons .btn{min-width:125px;min-height:44px;border-radius:12px;font-weight:900}.checkout-action-dialog.is-danger .checkout-action-dialog-confirm{background:#c81e1e!important;border-color:#c81e1e!important}.checkout-action-dialog.is-warning .checkout-action-dialog-confirm{background:#d98700!important;border-color:#d98700!important}.checkout-action-dialog-buttons.is-single .checkout-action-dialog-cancel{display:none}@keyframes checkoutDialogIn{from{opacity:0;transform:translateY(10px) scale(.97)}to{opacity:1;transform:translateY(0) scale(1)}}
.checkout-pix-modal{z-index:640!important;background:rgba(7,24,77,.52)!important;backdrop-filter:blur(6px)}.checkout-pix-dialog{width:min(540px,calc(100vw - 32px))!important;max-height:94vh;overflow:auto;border-radius:24px!important;padding:18px!important;box-shadow:0 30px 90px rgba(7,24,77,.32)!important}.checkout-pix-head{margin-bottom:10px}.checkout-pix-head h2{font-size:24px!important;color:#07184d!important}.checkout-pix-body{display:grid;gap:12px}.checkout-pix-amount{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:12px 15px;border:1px solid #cfe0f4;border-radius:14px;background:linear-gradient(135deg,#f6fbff,#eaf4ff)}.checkout-pix-amount span{font-size:13px;font-weight:850;color:#55708e}.checkout-pix-amount strong{font-size:25px;color:#075e0e}.checkout-pix-qr-wrap{width:min(280px,72vw);height:min(280px,72vw);margin:0 auto;display:flex;align-items:center;justify-content:center;border:1px solid #d7e2f0;border-radius:18px;background:#fff;padding:10px;box-shadow:inset 0 0 0 6px #f7fbff}.checkout-pix-qr-wrap img{width:100%;height:100%;object-fit:contain}.checkout-pix-qr-placeholder{color:#6b7f99;font-weight:850;text-align:center;padding:24px}.checkout-pix-status{display:flex;align-items:center;gap:11px;padding:12px 14px;border-radius:14px;border:1px solid #bfdbfe;background:#eff6ff;color:#174f8b}.checkout-pix-status strong,.checkout-pix-status small{display:block}.checkout-pix-status small{margin-top:2px;font-size:12px;font-weight:650;color:#55708e}.checkout-pix-status.is-paid{border-color:#86efac;background:#f0fdf4;color:#08752d}.checkout-pix-status.is-error{border-color:#fecaca;background:#fef2f2;color:#b91c1c}.checkout-pix-status.is-expired{border-color:#fed7aa;background:#fff7ed;color:#b45309}.checkout-pix-pulse{width:13px;height:13px;border-radius:50%;background:#1684e8;box-shadow:0 0 0 0 rgba(22,132,232,.38);animation:checkoutPixPulse 1.3s infinite}.checkout-pix-status.is-paid .checkout-pix-pulse{background:#16a34a;animation:none}.checkout-pix-status.is-error .checkout-pix-pulse,.checkout-pix-status.is-expired .checkout-pix-pulse{background:currentColor;animation:none}.checkout-pix-copy-label{display:grid;gap:6px;font-size:12px;font-weight:900;color:#174f8b}.checkout-pix-copy-label textarea{width:100%;resize:none;border:1px solid #c5d5e8;border-radius:12px;padding:10px 12px;background:#fbfdff;color:#334155;font:700 12px/1.35 ui-monospace,SFMono-Regular,Menlo,monospace}.checkout-pix-meta{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;color:#64748b;font-size:11px}.checkout-pix-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:14px}.checkout-pix-actions .btn{min-height:44px;border-radius:12px;font-weight:900}@keyframes checkoutPixPulse{0%{box-shadow:0 0 0 0 rgba(22,132,232,.38)}70%{box-shadow:0 0 0 9px rgba(22,132,232,0)}100%{box-shadow:0 0 0 0 rgba(22,132,232,0)}}
@media(max-width:1280px){.pdv-checkout-mode{grid-template-columns:minmax(360px,.9fr) minmax(520px,1.1fr)}.checkout-session-meta{gap:9px;font-size:12px}.checkout-sale-head,.checkout-sale-row{grid-template-columns:72px minmax(210px,1fr) 124px 100px 30px}.checkout-metric strong{font-size:35px}.checkout-metric-total strong{font-size:37px}}
@media(max-width:980px){body:has(.pdv-checkout-mode){overflow:auto}.pdv-checkout-mode{grid-template-columns:1fr;grid-template-rows:auto auto auto auto;min-height:auto;height:auto;max-height:none;overflow:visible}.pdv-checkout-mode .checkout-topbar{grid-template-columns:1fr auto}.checkout-session-meta{grid-column:1/-1;grid-row:2;justify-content:flex-start;overflow:auto;padding-bottom:3px}.pdv-checkout-mode>[data-step-panel="produtos"],.pdv-checkout-mode>[data-step-panel="carrinho"]{grid-column:1;grid-row:auto;height:auto;overflow:visible}.pdv-checkout-mode>[data-step-panel="pagamento"]{grid-column:1;grid-row:auto}.checkout-actionbar{grid-column:1;grid-row:auto;grid-template-columns:repeat(2,minmax(0,1fr));position:static}.checkout-action{min-height:62px}.checkout-reader-row{grid-template-columns:32px 90px minmax(140px,1fr) 86px}.checkout-reader-row .checkout-reader-meta{display:none}.checkout-value-entry{grid-template-columns:1fr}.checkout-os-fiscal-grid{grid-template-columns:1fr}.pdv-checkout-mode .pdv-product-entry{display:flex!important}.pdv-checkout-mode #pdvPreparedQty{width:74px!important;flex-basis:74px!important}.checkout-sale-head,.checkout-sale-row{grid-template-columns:70px minmax(170px,1fr) 118px 96px 30px}.pdv-checkout-mode #cartItems{min-height:320px}.pdv-checkout-mode .pdv-side-summary .mg-form-grid{grid-template-columns:.55fr .85fr .55fr .85fr 1.2fr!important}.pdv-checkout-mode [data-step-panel="pagamento"] .pdv-payment-step-grid{grid-template-columns:1fr!important}.pdv-checkout-mode .pdv-payment-summary{position:static!important}}
</style>

<script defer src="/assets/balanca.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/balanca.js') ?>"></script>
<script>
(function(){
  let products = <?= $productJson ?: '[]' ?>;
  let pizzas = <?= $pizzaJson ?: '[]' ?>;
  let productOptions = <?= $productOptionsJson ?: '{}' ?>;
  const scaleCfg = <?= $scaleJson ?: '{}' ?>;
  const productOptionDisplay = {
    showAdditionalUnitTotal: <?= $showAdditionalUnitTotal ? 'true' : 'false' ?>,
    showItemTotalWithAdditionals: <?= $showItemTotalWithAdditionals ? 'true' : 'false' ?>,
    itemNoteInstruction: <?= json_encode($itemNoteInstruction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
  const paymentMap = <?= $paymentJson ?: '{}' ?> || {};
  const paymentShortcuts = <?= $paymentShortcutsJson ?: '[]' ?> || [];
  const paymentShortcutByKey = Object.fromEntries(paymentShortcuts.map(item => [String(item.key), String(item.id)]));
  const customers = <?= $customerJson ?: '[]' ?>;
  const commercialMaxDiscountPercent = <?= $maxDiscountPercent === null ? 'null' : json_encode((float)$maxDiscountPercent) ?>;
  let creditOverrideConfirmed = false;
  let checkoutDeliveryNoDistrictConfirmed = false;
  let customerAddresses = <?= $customerAddressesJson ?: '[]' ?>;
  const deliveryFees = <?= $deliveryFeesJson ?: '{}' ?>;
  const deliveryDefault = Number(<?= $deliveryDefaultJson ?: '0' ?> || 0);
  const defaultOrderType = <?= json_encode((string)array_key_first($pdvOrderTypes)) ?>;
  const paymentOptionsHtml = `<?php foreach (array_values($payments) as $paymentIndex => $p): ?><option value="<?= (int)$p['id'] ?>" data-payment-type="<?= gestao_h(strtoupper((string)($p['type'] ?? ''))) ?>"><?= (int)($paymentIndex + 1) ?> - <?= gestao_h($p['name']) ?></option><?php endforeach; ?>`;
  const isMobilePdv = <?= $pdvMobileMode ? 'true' : 'false' ?>;
  const isCheckoutPdv = true;

  const list = document.getElementById('pdvProducts');
  const search = document.getElementById('pdvSearch');
  const searchMode = document.getElementById('pdvProductSearchMode');
  const preparedQty = document.getElementById('pdvPreparedQty');
  const searchModeStorageKey = 'nexa:product-search-mode:<?= (int)$companyId ?>:<?= (int)$userId ?>';
  const validSearchModes = ['instant','menu','description','barcode'];
  const cats = document.getElementById('pdvCats');
  const cartBox = document.getElementById('cartItems');
  const hidden = document.getElementById('cartHidden');
  const cart = new Map();
  let weightedCartLineSeq = 0;
  let checkoutSelectedCartKey = null;
  let checkoutLastCartKey = null;
  let checkoutCartScrollToEnd = false;
  let discountAdjustmentMode = 'amount';
  let additionAdjustmentMode = 'amount';
  let currentCat = 'Todos';
  let currentProductForOptions = null;
  let selectedCustomer = null;
  let selectedAddressId = '';
  const stepOrder = <?= $pdvMobileMode ? "['cliente','endereco','produtos','carrinho','pagamento']" : "['cliente','produtos','carrinho','pagamento']" ?>;
  const todayIso = <?= json_encode(date('Y-m-d')) ?>;
  let paymentLineSeq = 0;
  let activePaymentLine = null;
  let checkoutPixActiveLine = null;
  let checkoutPixActiveChargeId = 0;
  let checkoutPixPollTimer = null;
  let checkoutPixPolling = false;
  let checkoutPixStarting = false;
  let checkoutPixCompleting = false;
  let paymentShortcutBuffer = '';
  let paymentShortcutTimer = null;
  let productsLoaded = false;
  let loadingProducts = false;
  let productSearchTimer = null;
  let productSearchRequestSeq = 0;
  let pendingProductQuantity = 1;
  let modalProducts = [];
  let modalProductOptions = {};
  let modalPizzas = [];
  let modalProductSearchController = null;
  let modalProductActiveIndex = -1;
  let modalProductLastQuery = '';
  let modalCustomers = [];
  let modalCustomerActiveIndex = -1;
  let modalCustomerLastQuery = '';
  let checkoutProductValueTarget = 0;
  const checkoutComandaEnabled = <?= $comandaReadEnabled ? 'true' : 'false' ?>;
  const checkoutOrcamentoEnabled = <?= $orcamentoReadEnabled ? 'true' : 'false' ?>;
  const checkoutOsEnabled = <?= $osReadEnabled ? 'true' : 'false' ?>;
  const checkoutOsFiscalOptions = <?= json_encode($osFiscalOptions, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutOrcamentoAjaxUrl = <?= json_encode($orcamentoAjaxUrl, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutSelfUrl = <?= json_encode($pdvContextUrl('gestao_pdv_checkout.php'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutExitUrl = <?= json_encode($pdvContextUrl('gestao.php'), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutMcCsrf = <?= json_encode($pdvMcCsrf, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutMcCtx = <?= json_encode($pdvMcCtx, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const checkoutComandaSelected = new Set();
  const checkoutOrcamentoSelected = new Set();
  const checkoutOsSelected = new Set();
  const checkoutOsMeta = new Map();
  let checkoutOsFinancialLocked = false;
  let checkoutOsFiscalChoiceConfirmed = false;
  const fiscalEmissionEnabled = <?= $fiscalEmissionEnabled ? 'true' : 'false' ?>;
  let fiscalChoiceConfirmed = !fiscalEmissionEnabled;

  function onlyDigits(v){ return String(v || '').replace(/\D+/g,''); }
  function normalize(v){ return String(v || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,''); }
  function escapeHtml(s){ return String(s ?? '').replace(/[&<>"]/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[m])); }
  function money(v){ return 'R$ ' + (Number(v||0).toFixed(2)).replace('.', ','); }
  function stockQty(v){
    const n = Number(v || 0);
    const decimals = Math.abs(n - Math.round(n)) < 0.000001 ? 0 : 3;
    return n.toLocaleString('pt-BR', {minimumFractionDigits:decimals, maximumFractionDigits:decimals});
  }
  function quantitySummary(v){
    const n = Number(v || 0);
    return n.toLocaleString('pt-BR', {minimumFractionDigits:0, maximumFractionDigits:3});
  }
  function fmtInput(v){ return (Number(v||0).toFixed(2)).replace('.', ','); }
  function parseMoney(v){ v=String(v||'0').replace(/\./g,'').replace(',', '.').replace(/[^0-9.-]/g,''); const n=Number(v); return isNaN(n)?0:n; }
  function checkoutValueCalculation(targetValue, referenceUnitPrice){
    const target = Math.max(0, Math.round(Number(targetValue || 0) * 100) / 100);
    const reference = Number(referenceUnitPrice || 0);
    if(!(target > 0) || !(reference > 0)) return null;
    // O Pedido/estoque do fluxo comercial trabalha com quantidade em 3 casas.
    // Para venda por valor, arredondamos a quantidade nessa mesma precisão e,
    // quando necessário, ajustamos somente o preço unitário técnico da linha
    // para que o total fiscal/financeiro feche exatamente no valor digitado.
    const rawQty = target / reference;
    const qty = Math.max(0.001, Math.round(rawQty * 1000) / 1000);
    const totalAtReference = Math.round((qty * reference + Number.EPSILON) * 100) / 100;
    let effectivePrice = reference;
    let priceDecimals = 2;
    let adjusted = Math.abs(totalAtReference - target) > 0.0001;
    if(adjusted){
      effectivePrice = Math.round((target / qty) * 1000000) / 1000000;
      priceDecimals = 6;
    }
    const finalTotal = Math.round((qty * effectivePrice + Number.EPSILON) * 100) / 100;
    if(Math.abs(finalTotal - target) > 0.0001){
      // proteção adicional contra ponto flutuante em valores muito pequenos
      effectivePrice = target / qty;
      priceDecimals = 6;
    }
    return {target, referencePrice:reference, qty, price:effectivePrice, priceDecimals, adjusted};
  }
  function checkoutPixMethod(line){
    const methodId = String(line?.querySelector('.pay-method')?.value || '');
    const method = paymentMap[methodId] || {};
    return {methodId, method};
  }
  function checkoutPixResetLine(line){
    if(!line) return;
    const charge = line.querySelector('.pay-pix-charge-id');
    if(charge){ charge.value=''; delete charge.dataset.amount; delete charge.dataset.method; delete charge.dataset.paid; }
  }
  function checkoutPixPendingLine(){
    for(const line of Array.from(document.querySelectorAll('.pdv-payment-line'))){
      const {methodId, method} = checkoutPixMethod(line);
      if(Number(method.pixAutoQr || 0) !== 1) continue;
      const amount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
      if(amount <= 0) continue;
      const charge = line.querySelector('.pay-pix-charge-id');
      if(charge?.value){
        const sameMethod = String(charge.dataset.method || '') === methodId;
        const sameAmount = Math.abs(Number(charge.dataset.amount || 0) - amount) <= 0.009;
        if(sameMethod && sameAmount && charge.dataset.paid === '1') continue;
        if(!sameMethod || !sameAmount) checkoutPixResetLine(line);
      }
      return line;
    }
    return null;
  }
  function checkoutPixStopPolling(){
    if(checkoutPixPollTimer){ clearInterval(checkoutPixPollTimer); checkoutPixPollTimer = null; }
    checkoutPixPolling = false;
  }
  function checkoutPixCloseModal(){
    checkoutPixStopPolling();
    const modal = document.getElementById('checkoutPixModal');
    if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }
    checkoutPixActiveLine = null;
    checkoutPixActiveChargeId = 0;
    checkoutPixStarting = false;
    checkoutPixCompleting = false;
  }
  function checkoutPixStatusUi(kind, title, detail){
    const box = document.getElementById('checkoutPixStatus');
    if(!box) return;
    box.classList.remove('is-waiting','is-paid','is-error','is-expired');
    box.classList.add(kind === 'paid' ? 'is-paid' : kind === 'error' ? 'is-error' : kind === 'expired' ? 'is-expired' : 'is-waiting');
    const strong = box.querySelector('strong');
    const small = document.getElementById('checkoutPixStatusDetail');
    if(strong) strong.textContent = title || 'Aguardando pagamento';
    if(small) small.textContent = detail || '';
  }
  function checkoutPixRenderCharge(charge){
    if(!charge) return;
    const amountEl = document.getElementById('checkoutPixAmount');
    const qr = document.getElementById('checkoutPixQr');
    const placeholder = document.getElementById('checkoutPixQrPlaceholder');
    const copy = document.getElementById('checkoutPixCopyPaste');
    const txid = document.getElementById('checkoutPixTxid');
    const expires = document.getElementById('checkoutPixExpires');
    if(amountEl) amountEl.textContent = money(charge.amount || 0);
    if(copy) copy.value = String(charge.copy_paste || '');
    if(txid) txid.textContent = charge.txid ? 'TXID: ' + String(charge.txid) : '';
    if(expires){
      let label = '';
      if(charge.expires_at){
        const d = new Date(String(charge.expires_at).replace(' ', 'T'));
        if(!Number.isNaN(d.getTime())) label = 'Expira às ' + d.toLocaleTimeString('pt-BR',{hour:'2-digit',minute:'2-digit',second:'2-digit'});
      }
      expires.textContent = label;
    }
    if(qr && charge.qr_image){ qr.src = String(charge.qr_image); qr.hidden = false; if(placeholder) placeholder.hidden = true; }
    else if(qr){ qr.hidden = true; if(placeholder){ placeholder.hidden=false; placeholder.textContent = charge.copy_paste ? 'Use o código Pix Copia e Cola abaixo.' : 'Aguardando QR Code do banco...'; } }
    if(charge.paid){
      checkoutPixStatusUi('paid','Pagamento confirmado','Confirmação recebida do banco. Preparando a finalização da venda...');
    }else if(charge.expired){
      checkoutPixStatusUi('expired','QR Code expirado','Feche esta janela e finalize novamente para gerar uma nova cobrança.');
    }else if(String(charge.status || '').toUpperCase() === 'ERRO'){
      checkoutPixStatusUi('error','Falha na cobrança',charge.message || 'Não foi possível confirmar esta cobrança Pix.');
    }else{
      checkoutPixStatusUi('waiting','Aguardando pagamento',charge.provider_status ? 'Status no banco: ' + String(charge.provider_status) : 'A confirmação será verificada automaticamente.');
    }
  }
  async function checkoutPixPost(action, payload={}){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('mc_csrf', checkoutMcCsrf || '');
    fd.append('mc_ctx', checkoutMcCtx || '');
    Object.entries(payload).forEach(([key,value]) => fd.append(key, String(value ?? '')));
    const response = await fetch(checkoutSelfUrl, {method:'POST',body:fd,credentials:'same-origin',cache:'no-store'});
    const data = await response.json().catch(() => ({}));
    if(!response.ok || !data.ok) throw new Error(data.error || 'Não foi possível comunicar com o serviço Pix.');
    return data;
  }
  function checkoutPixComplete(charge){
    if(checkoutPixCompleting || !checkoutPixActiveLine || !charge?.paid) return;
    checkoutPixCompleting = true;
    const hiddenCharge = checkoutPixActiveLine.querySelector('.pay-pix-charge-id');
    const {methodId} = checkoutPixMethod(checkoutPixActiveLine);
    const amount = parseMoney(checkoutPixActiveLine.querySelector('.pay-amount')?.value || '0');
    if(hiddenCharge){
      hiddenCharge.value = String(charge.id || '');
      hiddenCharge.dataset.amount = String(amount.toFixed(2));
      hiddenCharge.dataset.method = methodId;
      hiddenCharge.dataset.paid = '1';
    }
    checkoutPixStopPolling();
    checkoutPixRenderCharge(charge);
    setTimeout(() => {
      checkoutPixCloseModal();
      const form = document.getElementById('pdvForm');
      if(form?.requestSubmit) form.requestSubmit(); else form?.submit();
    }, 650);
  }
  async function checkoutPixPoll(showError=false){
    if(!checkoutPixActiveChargeId || checkoutPixPolling) return;
    checkoutPixPolling = true;
    try{
      const data = await checkoutPixPost('pix_checkout_status', {charge_id:checkoutPixActiveChargeId});
      const charge = data.charge || {};
      checkoutPixRenderCharge(charge);
      if(charge.paid){ checkoutPixComplete(charge); return; }
      if(charge.expired || String(charge.status || '').toUpperCase() === 'ERRO') checkoutPixStopPolling();
    }catch(err){
      if(showError) checkoutPixStatusUi('error','Não foi possível consultar agora',String(err?.message || err || 'Falha de comunicação.'));
      else checkoutPixStatusUi('waiting','Aguardando pagamento','Não foi possível consultar nesta tentativa. O sistema tentará novamente.');
    }finally{ checkoutPixPolling = false; }
  }
  async function checkoutPixStart(line){
    if(checkoutPixStarting || !line) return;
    checkoutPixStarting = true;
    checkoutPixCompleting = false;
    checkoutPixStopPolling();
    checkoutPixActiveLine = line;
    const {methodId} = checkoutPixMethod(line);
    const amount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
    const hiddenCharge = line.querySelector('.pay-pix-charge-id');
    const existingChargeId = Number(hiddenCharge?.value || 0);
    const existingMatches = existingChargeId > 0
      && String(hiddenCharge?.dataset.method || '') === methodId
      && Math.abs(Number(hiddenCharge?.dataset.amount || 0) - amount) <= 0.009;
    if(!existingMatches) checkoutPixResetLine(line);
    checkoutPixActiveChargeId = existingMatches ? existingChargeId : 0;

    const modal = document.getElementById('checkoutPixModal');
    if(modal){ modal.style.display='flex'; modal.setAttribute('aria-hidden','false'); }
    const qr = document.getElementById('checkoutPixQr'); if(qr){ qr.hidden=true; qr.removeAttribute('src'); }
    const placeholder = document.getElementById('checkoutPixQrPlaceholder'); if(placeholder){ placeholder.hidden=false; placeholder.textContent=existingMatches ? 'Recuperando QR Code...' : 'Gerando QR Code...'; }
    const copy = document.getElementById('checkoutPixCopyPaste'); if(copy) copy.value='';
    const amountEl = document.getElementById('checkoutPixAmount'); if(amountEl) amountEl.textContent = money(amount);
    checkoutPixStatusUi('waiting', existingMatches ? 'Consultando cobrança' : 'Gerando cobrança', 'Conectando com o banco...');

    try{
      if(existingMatches){
        const data = await checkoutPixPost('pix_checkout_status', {charge_id:existingChargeId});
        const charge = data.charge || {};
        checkoutPixRenderCharge(charge);
        if(charge.paid){ checkoutPixComplete(charge); return; }
        if(charge.expired || String(charge.status || '').toUpperCase() === 'ERRO'){
          checkoutPixResetLine(line);
          checkoutPixActiveChargeId = 0;
          checkoutPixStopPolling();
          return;
        }
      }else{
        const data = await checkoutPixPost('pix_checkout_create', {payment_method_id:methodId,amount:fmtInput(amount)});
        const charge = data.charge || {};
        checkoutPixActiveChargeId = Number(charge.id || 0);
        if(!checkoutPixActiveChargeId) throw new Error('O banco não retornou o identificador da cobrança Pix.');
        if(hiddenCharge){
          hiddenCharge.value = String(checkoutPixActiveChargeId);
          hiddenCharge.dataset.amount = String(amount.toFixed(2));
          hiddenCharge.dataset.method = methodId;
          hiddenCharge.dataset.paid = charge.paid ? '1' : '0';
        }
        checkoutPixRenderCharge(charge);
        if(charge.paid){ checkoutPixComplete(charge); return; }
      }
      checkoutPixPollTimer = setInterval(() => checkoutPixPoll(false), 2500);
      setTimeout(() => checkoutPixPoll(false), 450);
    }catch(err){
      checkoutPixStatusUi('error', existingMatches ? 'Não foi possível consultar o Pix' : 'Não foi possível gerar o Pix', String(err?.message || err || 'Falha na comunicação bancária.'));
      checkoutPixStopPolling();
    }finally{ checkoutPixStarting = false; }
  }
  async function checkoutPixCopyCode(){
    const code = document.getElementById('checkoutPixCopyPaste')?.value || '';
    if(!code){ checkoutPixStatusUi('error','Código ainda indisponível','Aguarde o banco retornar o Pix Copia e Cola.'); return; }
    try{
      if(navigator.clipboard?.writeText) await navigator.clipboard.writeText(code);
      else { const el=document.getElementById('checkoutPixCopyPaste'); el?.focus(); el?.select(); document.execCommand('copy'); }
      checkoutPixStatusUi('waiting','Código copiado','Cole o código no aplicativo do banco para concluir o pagamento.');
    }catch(err){ checkoutPixStatusUi('error','Não foi possível copiar','Selecione o código manualmente e copie.'); }
  }
  function parseProductSearch(v){
    const raw = String(v || '').trim();
    const match = raw.match(/^([0-9]+(?:[.,][0-9]+)?)\s*\*\s*(.*)$/);
    if(!match) return {term:raw, qty:pendingProductQuantity, hasMultiplier:false};
    const parsedQty = Number(String(match[1]).replace(',', '.'));
    return {term:String(match[2] || '').trim(), qty:Number.isFinite(parsedQty) && parsedQty > 0 ? parsedQty : 1, hasMultiplier:true};
  }
  function updatePreparedQuantity(){
    if(!preparedQty) return;
    const active = Math.abs(Number(pendingProductQuantity || 1) - 1) > 0.000001;
    preparedQty.hidden = !active;
    preparedQty.textContent = String(Number(pendingProductQuantity || 1)).replace('.', ',') + ' ×';
  }
  function clearProductMultiplier(){
    if(!search) return;
    search.value = '';
    pendingProductQuantity = 1;
    updatePreparedQuantity();
    setTimeout(() => { search.focus(); search.select?.(); }, 0);
  }
  function armProductMultiplier(){
    if(!search) return false;
    const raw = String(search.value || '').trim();
    const match = raw.match(/^([0-9]+(?:[.,][0-9]+)?)\s*\*$/);
    if(!match) return false;
    const qty = Number(String(match[1]).replace(',', '.'));
    pendingProductQuantity = Number.isFinite(qty) && qty > 0 ? qty : 1;
    search.value = '';
    updatePreparedQuantity();
    showMobileToast('Quantidade ' + String(pendingProductQuantity).replace('.', ',') + '× preparada');
    setTimeout(() => search.focus(), 0);
    return true;
  }
  function currentProductSearchMode(){
    const mode = String(searchMode?.value || 'instant');
    return validSearchModes.includes(mode) ? mode : 'instant';
  }
  function updateProductSearchPlaceholder(){
    if(!search) return;
    const placeholders = {
      instant:'Nome, código da empresa, referência, cardápio ou barras',
      menu:'Digite o código do cardápio',
      description:'Digite a descrição do produto',
      barcode:'Passe ou digite o código de barras'
    };
    search.placeholder = placeholders[currentProductSearchMode()] || placeholders.instant;
  }
  function productMatchesSearch(p, term, mode=currentProductSearchMode()){
    const q = normalize(term);
    if(!q) return true;
    if(mode === 'menu') return normalize(p.menuCode || '').includes(q);
    if(mode === 'description') return normalize([p.name,p.description||''].join(' ')).includes(q);
    if(mode === 'barcode'){
      const d = onlyDigits(term);
      const bd = onlyDigits(p.barcode || '');
      return d ? bd.includes(d) : normalize(p.barcode || '').includes(q);
    }
    return normalize([p.name,p.description||'',p.category,p.code,p.reference,p.menuCode,p.barcode].join(' ')).includes(q);
  }
  function isoAddDays(dateStr, days){ const d = new Date(String(dateStr || todayIso) + 'T12:00:00'); d.setDate(d.getDate() + Number(days || 0)); return d.toISOString().slice(0,10); }
  function isoAddMonthsKeepDay(dateStr, months, day){ const base = new Date(String(dateStr || todayIso) + 'T12:00:00'); const y = base.getFullYear(); const m = base.getMonth() + Number(months || 0); const first = new Date(y, m, 1, 12, 0, 0); const last = new Date(first.getFullYear(), first.getMonth()+1, 0).getDate(); first.setDate(Math.min(Math.max(1, Number(day || base.getDate())), last)); return first.toISOString().slice(0,10); }
  function isoNextFixedDay(dateStr, day){ const base=new Date(String(dateStr||todayIso)+'T12:00:00'); const wanted=Math.max(1,Math.min(31,Number(day||1))); for(let i=0;i<24;i++){ const first=new Date(base.getFullYear(),base.getMonth()+i,1,12,0,0); const last=new Date(first.getFullYear(),first.getMonth()+1,0).getDate(); first.setDate(Math.min(wanted,last)); if(first>base) return first.toISOString().slice(0,10); } return isoAddDays(dateStr,30); }
  function splitInstallmentAmounts(total, count){ const cents = Math.round(Number(total || 0) * 100); count = Math.max(1, Number(count || 1)); const base = Math.floor(cents / count); const rem = cents - (base * count); return Array.from({length:count}, (_,i) => (base + (i === count-1 ? rem : 0)) / 100); }
  function setText(id, value){ const el=document.getElementById(id); if(el) el.textContent=value; }
  function setVal(id, value){ const el=document.getElementById(id); if(el) el.value=value ?? ''; }
  function openModal(id){ const el=document.getElementById(id); if(el) el.style.display='flex'; }
  function closeModal(id){ const el=document.getElementById(id); if(el) el.style.display='none'; }

  function showMobileToast(message){
    const el = document.getElementById('pdvMobileToast');
    if(!el) return;
    el.textContent = message || 'Item adicionado';
    el.classList.add('show');
    clearTimeout(window.__pdvMobileToastTimer);
    window.__pdvMobileToastTimer = setTimeout(() => el.classList.remove('show'), 1350);
  }
  function currentStepName(){
    return document.getElementById('pdvForm')?.dataset.currentStep || (isCheckoutPdv ? 'produtos' : 'cliente');
  }
  function nextStepFor(step){
    const idx = stepOrder.indexOf(step);
    if(idx < 0) return stepOrder[0] || 'cliente';
    return stepOrder[Math.min(stepOrder.length - 1, idx + 1)] || step;
  }
  function updateMobileActionBar(step){
    if(!isMobilePdv) return;
    step = step || currentStepName();
    const form = document.getElementById('pdvForm');
    const bar = document.getElementById('pdvMobileFloatingTotal');
    const totalEl = document.getElementById('pdvMobileFloatingTotalValue');
    const labelEl = document.getElementById('pdvMobileFloatingLabel');
    const btn = document.getElementById('pdvMobileFloatingNext');
    if(form) form.classList.toggle('is-payment-step', step === 'pagamento');
    if(!bar || !btn) return;
    if(totalEl) totalEl.textContent = money(orderTotal());
    if(labelEl) labelEl.textContent = cart.size ? `${quantitySummary(cartCount())} item(ns)` : 'Carrinho vazio';
    const next = nextStepFor(step);
    if(step === 'cliente') btn.textContent = 'Avançar';
    else if(step === 'endereco') btn.textContent = 'Produtos';
    else if(step === 'produtos') btn.textContent = cart.size ? 'Conferir' : 'Avançar';
    else if(step === 'carrinho') btn.textContent = 'Pagamento';
    btn.dataset.next = next;
    btn.disabled = false;
  }

  function prepareScaleControls(product, inputId, boxId){
    const box = document.getElementById(boxId);
    const input = document.getElementById(inputId);
    if(!box || !input) return;
    box.innerHTML = '';
    box.style.display = 'none';
    if(!product || !product.pesavel) return;
    box.style.display = '';
    if(window.NexaScale && Number(scaleCfg?.enabled || 0)){
      window.NexaScale.buildControls(scaleCfg, input, {label:'Produto pesável: leia o peso da balança'}).forEach(el => box.appendChild(el));
    }else{
      box.innerHTML = '<strong>Produto pesável</strong><small>Integração com balança desativada. Informe o peso manualmente em kg.</small>';
    }
  }

  function customerDisplayName(){ const avulso = document.getElementById('finalConsumerNameInput')?.value.trim() || ''; return selectedCustomer ? selectedCustomer.name : (avulso || 'Consumidor final'); }
  function cartCount(){ let n=0; cart.forEach(it=>n += Number(it.qty)||0); return n; }
  function showStep(step){
    if(!stepOrder.includes(step)) return;
    if(isCheckoutPdv && ['cliente','produtos','carrinho'].includes(step)) step = 'produtos';
    document.querySelectorAll('.pdv-step-panel').forEach(p => p.classList.toggle('active', p.dataset.stepPanel === step));
    document.querySelectorAll('.pdv-step-nav').forEach(b => b.classList.toggle('active', b.dataset.step === step));
    const form = document.getElementById('pdvForm');
    if(form) form.dataset.currentStep = step;
    updateTotals();
    updateMobileActionBar(step);
    if(!isCheckoutPdv){
      if(isMobilePdv){ window.scrollTo({top: Math.max(0, form.offsetTop - 66), behavior:'smooth'}); }
      else { window.scrollTo({top: Math.max(0, form.offsetTop - 90), behavior:'smooth'}); }
    }
    if(step === 'cliente') setTimeout(() => { document.getElementById('customerLookup')?.focus(); }, 120);
    if(step === 'endereco') setTimeout(() => { (document.getElementById('deliveryDistrict') || document.getElementById('deliveryAddress'))?.focus(); }, 120);
    if(step === 'produtos') setTimeout(() => { search?.focus(); search?.select?.(); }, 80);
    if(step === 'pagamento') setTimeout(() => {
      const line = currentPaymentLine();
      const method = line?.querySelector('.pay-method');
      if(method){ setActivePaymentLine(line); method.focus(); }
    }, 80);
  }
  function canEnterStep(step){
    if(['carrinho','pagamento'].includes(step) && cart.size === 0){ checkoutNotice('Inclua ao menos um produto antes de avançar.','Carrinho','warning'); showStep('produtos'); return false; }
    return true;
  }

  function exactProductForTerm(term){
    const wanted = normalize(String(term || '').trim());
    if(!wanted) return null;
    const mode = currentProductSearchMode();
    let matches = [];
    if(mode === 'menu') matches = products.filter(p => normalize(String(p.menuCode || '').trim()) === wanted);
    else if(mode === 'barcode') matches = products.filter(p => normalize(String(p.barcode || '').trim()) === wanted || (onlyDigits(term) && onlyDigits(p.barcode || '') === onlyDigits(term)));
    else if(mode === 'description') matches = products.filter(p => normalize(String(p.name || '').trim()) === wanted || normalize(String(p.description || '').trim()) === wanted);
    else matches = products.filter(p => {
      const raw = String(term || '').trim();
      const code = String(p.code ?? '').trim();
      const directCode = normalize(code) === wanted;
      const numericCode = /^\d+$/.test(raw) && /^\d+$/.test(code)
        && String(parseInt(raw, 10)) === String(parseInt(code, 10));
      const reference = normalize(String(p.reference || '').trim()) === wanted;
      const menuCode = normalize(String(p.menuCode || '').trim()) === wanted;
      const barcode = normalize(String(p.barcode || '').trim()) === wanted
        || (onlyDigits(raw) && onlyDigits(p.barcode || '') === onlyDigits(raw));
      return directCode || numericCode || reference || menuCode || barcode;
    });
    if(matches.length === 1) return matches[0];
    const partial = products.filter(p => productMatchesSearch(p, term, mode));
    return partial.length === 1 ? partial[0] : null;
  }

  function renderProducts(){
    if(loadingProducts){ list.innerHTML = '<div class="mg-empty">Buscando produtos...</div>'; return; }
    if(!productsLoaded){ list.innerHTML = '<div class="mg-empty">Produtos aparecerão aqui.</div>'; return; }
    const q = normalize(parseProductSearch(search?.value || '').term);
    const html = products.filter(p => {
      const catOk = currentCat === 'Todos' || String(p.category || '') === currentCat;
      return catOk && (!q || productMatchesSearch(p, parseProductSearch(search?.value || '').term));
    }).map(p => `<button type="button" class="pdv-product" data-id="${p.id}"><img class="pdv-img" src="${escapeHtml(p.image)}" alt=""><span><strong>${escapeHtml(p.name)}</strong><small>${escapeHtml(p.category||'Sem categoria')}${p.reference?' • ref. '+escapeHtml(p.reference):''}${p.menuCode?' • cardápio '+escapeHtml(p.menuCode):''}${p.code?' • cód. '+escapeHtml(p.code):''}${p.barcode?' • barras '+escapeHtml(p.barcode):''}${p.hasOptions?' • opções':''}${p.isPizza?' • pizza':''}${p.pesavel?' • pesável':''}</small><b>${money(p.price)}</b></span></button>`).join('');
    list.innerHTML = html || '<div class="mg-empty">Nenhum produto encontrado.</div>';
  }

  async function loadProducts(opts={}){
    const parsedSearch = parseProductSearch(opts.q ?? (search?.value || ''));
    const q = parsedSearch.term;
    if(parsedSearch.hasMultiplier) {
      pendingProductQuantity = parsedSearch.qty;
      updatePreparedQuantity();
    }
    const cat = String(opts.openFirst && q ? 'Todos' : (opts.cat ?? (currentCat || 'Todos')));
    if(q === '' && (!cat || cat === 'Todos')){
      if(parsedSearch.hasMultiplier && products.length){ renderProducts(); return; }
      products = []; pizzas = []; productOptions = {}; productsLoaded = false; renderProducts(); return;
    }
    const requestSeq = ++productSearchRequestSeq;
    loadingProducts = true; productsLoaded = true; renderProducts();
    try{
      const url = new URL(window.location.href);
      url.search = '';
      const mcCtx = <?= json_encode($pdvMcCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      if(mcCtx) url.searchParams.set('mc_ctx', mcCtx);
      url.searchParams.set('action', 'pdv_search_products');
      url.searchParams.set('mode', currentProductSearchMode());
      if(q) url.searchParams.set('q', q);
      if(cat && cat !== 'Todos') url.searchParams.set('category', cat);
      const resp = await fetch(url.toString(), {headers:{'Accept':'application/json'}});
      const data = await resp.json();
      if(requestSeq !== productSearchRequestSeq) return;
      if(!data.ok) throw new Error(data.error || 'Falha ao buscar produtos.');
      products = Array.isArray(data.products) ? data.products : [];
      pizzas = Array.isArray(data.pizzas) ? data.pizzas : [];
      productOptions = data.options || {};
      productsLoaded = true;
      if(opts.openFirst){
        const exactProduct = exactProductForTerm(q);
        if(exactProduct) openOptions(Number(exactProduct.id), pendingProductQuantity);
        else if(products.length === 1) openOptions(Number(products[0].id), pendingProductQuantity);
      }
    }catch(err){
      if(requestSeq !== productSearchRequestSeq) return;
      products = []; pizzas = []; productOptions = {}; productsLoaded = true;
      list.innerHTML = '<div class="mg-empty">' + escapeHtml(err.message || 'Falha ao buscar produtos.') + '</div>';
      return;
    }finally{
      if(requestSeq === productSearchRequestSeq) loadingProducts = false;
    }
    if(requestSeq === productSearchRequestSeq) renderProducts();
  }
  function productModalMode(){
    const modalMode = document.getElementById('productModalSearchMode');
    const mode = String(modalMode?.value || currentProductSearchMode());
    return validSearchModes.includes(mode) ? mode : 'instant';
  }
  function resetProductSearchModal(){
    modalProducts = [];
    modalProductOptions = {};
    modalPizzas = [];
    modalProductActiveIndex = -1;
    modalProductLastQuery = '';
    const results = document.getElementById('productModalResults');
    if(results) results.innerHTML = '<div class="pdv-product-modal-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
  }
  function renderProductModalResults(){
    const results = document.getElementById('productModalResults');
    if(!results) return;
    if(!modalProducts.length){
      results.innerHTML = '<div class="pdv-product-modal-empty">Nenhum produto encontrado.</div>';
      return;
    }
    results.innerHTML = `<table class="pdv-product-modal-table">
      <thead><tr><th>Código</th><th>Nome</th><th>Estoque</th><th>Preço de venda</th><th>Ação</th></tr></thead>
      <tbody>${modalProducts.map((p, index) => {
        const code = String(p.code || p.id || '');
        const codeDetails = [p.reference ? 'Referência: ' + p.reference : '', p.menuCode ? 'Cardápio: ' + p.menuCode : '', p.barcode ? 'Barras: ' + p.barcode : ''].filter(Boolean).join(' · ');
        const nameDetails = String(p.category || '').trim();
        const activeClass = index === modalProductActiveIndex ? ' class="is-active"' : '';
        return `<tr${activeClass} data-product-modal-row="${index}" data-product-modal-id="${Number(p.id)}">
          <td class="pdv-product-modal-code">${escapeHtml(code)}${codeDetails ? `<small>${escapeHtml(codeDetails)}</small>` : ''}</td>
          <td class="pdv-product-modal-name"><strong>${escapeHtml(p.name || '')}</strong>${nameDetails ? `<small>${escapeHtml(nameDetails)}</small>` : ''}</td>
          <td class="pdv-product-modal-stock">${escapeHtml(stockQty(p.stock))} ${escapeHtml(p.stockUnit || 'UN')}</td>
          <td class="pdv-product-modal-price">${money(p.price)}</td>
          <td class="pdv-product-modal-select"><button type="button" class="btn btn-primary" data-product-modal-id="${Number(p.id)}">Selecionar</button></td>
        </tr>`;
      }).join('')}</tbody>
    </table>`;
  }
  function updateProductModalActiveRow(nextIndex, options={}){
    const total = modalProducts.length;
    if(!total){ modalProductActiveIndex = -1; return; }
    const wrap = options.wrap !== false;
    let index = Number(nextIndex);
    if(!Number.isFinite(index)) index = 0;
    if(wrap) index = (index % total + total) % total;
    else index = Math.max(0, Math.min(total - 1, index));
    modalProductActiveIndex = index;
    const rows = document.querySelectorAll('#productModalResults [data-product-modal-row]');
    rows.forEach((row, rowIndex) => row.classList.toggle('is-active', rowIndex === index));
    const active = rows[index];
    if(active && options.scroll !== false) active.scrollIntoView({block:'nearest'});
  }
  function selectActiveProductFromModal(){
    if(modalProductActiveIndex < 0 || !modalProducts[modalProductActiveIndex]) return false;
    selectProductFromModal(Number(modalProducts[modalProductActiveIndex].id));
    return true;
  }
  async function searchProductsInModal(){
    const input = document.getElementById('productModalSearch');
    const results = document.getElementById('productModalResults');
    const q = String(input?.value || '').trim();
    if(!results) return;
    if(q === ''){
      resetProductSearchModal();
      input?.focus();
      return;
    }
    modalProductActiveIndex = -1;
    modalProductLastQuery = q;
    if(modalProductSearchController) modalProductSearchController.abort();
    modalProductSearchController = new AbortController();
    results.innerHTML = '<div class="pdv-product-modal-empty">Pesquisando produtos...</div>';
    try{
      const url = new URL(window.location.href);
      url.search = '';
      const mcCtx = <?= json_encode($pdvMcCtx, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
      if(mcCtx) url.searchParams.set('mc_ctx', mcCtx);
      url.searchParams.set('action', 'pdv_search_products');
      url.searchParams.set('mode', productModalMode());
      url.searchParams.set('q', q);
      const response = await fetch(url.toString(), {headers:{'Accept':'application/json'}, signal:modalProductSearchController.signal});
      const data = await response.json();
      if(!response.ok || !data.ok) throw new Error(data.error || 'Falha ao pesquisar produtos.');
      modalProducts = Array.isArray(data.products) ? data.products : [];
      modalProductOptions = data.options || {};
      modalPizzas = Array.isArray(data.pizzas) ? data.pizzas : [];
      modalProductActiveIndex = modalProducts.length ? 0 : -1;
      renderProductModalResults();
      if(modalProducts.length){
        updateProductModalActiveRow(0, {scroll:false, wrap:false});
        input?.focus();
      }else{
        input?.focus();
        input?.select();
      }
    }catch(error){
      if(error?.name === 'AbortError') return;
      modalProducts = [];
      modalProductOptions = {};
      modalPizzas = [];
      results.innerHTML = '<div class="pdv-product-modal-empty">' + escapeHtml(error?.message || 'Falha ao pesquisar produtos.') + '</div>';
    }
  }
  function openProductSearchModal(preserveValueTarget=false){
    const modal = document.getElementById('productSearchModal');
    if(!modal) return;
    if(!preserveValueTarget) checkoutProductValueTarget = 0;
    const modalMode = document.getElementById('productModalSearchMode');
    const modalInput = document.getElementById('productModalSearch');
    const title = modal.querySelector('.finance-modal-head h2');
    const subtitle = modal.querySelector('.finance-modal-head p');
    if(title) title.textContent = checkoutProductValueTarget > 0 ? 'Produto por valor' : 'Pesquisar produto';
    if(subtitle) subtitle.textContent = checkoutProductValueTarget > 0
      ? ('Selecione o produto. O sistema calculará a quantidade para totalizar ' + money(checkoutProductValueTarget) + '.')
      : 'Pesquise e selecione o produto para adicioná-lo ao pedido.';
    if(modalMode) modalMode.value = currentProductSearchMode();
    if(modalInput) modalInput.value = '';
    resetProductSearchModal();
    openModal('productSearchModal');
    setTimeout(() => modalInput?.focus(), 80);
  }
  function selectProductFromModal(productId){
    const product = modalProducts.find(item => Number(item.id) === Number(productId));
    if(!product) return;
    products = [product, ...products.filter(item => Number(item.id) !== Number(product.id))];
    if(modalProductOptions && Object.prototype.hasOwnProperty.call(modalProductOptions, product.id)) {
      productOptions[product.id] = modalProductOptions[product.id];
    }
    modalPizzas.forEach(pizza => {
      if(!pizzas.some(item => Number(item.id) === Number(pizza.id))) pizzas.push(pizza);
    });
    const modalMode = document.getElementById('productModalSearchMode');
    if(searchMode && modalMode && validSearchModes.includes(modalMode.value)){
      searchMode.value = modalMode.value;
      try { localStorage.setItem(searchModeStorageKey, modalMode.value); } catch(e) {}
      updateProductSearchPlaceholder();
    }
    closeModal('productSearchModal');
    if(checkoutProductValueTarget > 0){
      const basePrice = Number(product.price || 0);
      if(basePrice <= 0){
        checkoutNotice('O produto selecionado não possui valor unitário válido para o cálculo por valor.','Produto por valor','warning');
        checkoutProductValueTarget = 0;
        checkoutFocusSearch();
        return;
      }
      const groups = productOptions[product.id] || [];
      if(groups.length || product.isPizza){
        const initialQty = Math.max(0.001, checkoutProductValueTarget / basePrice);
        openOptions(Number(product.id), initialQty, {targetValue:checkoutProductValueTarget});
      }else{
        const valueCalc = checkoutValueCalculation(checkoutProductValueTarget, basePrice);
        if(!valueCalc){
          checkoutNotice('Não foi possível calcular a quantidade para o valor informado.','Produto por valor','warning');
          checkoutProductValueTarget = 0;
          checkoutFocusSearch();
          return;
        }
        const valueDetail = valueCalc.adjusted
          ? ('Venda por valor ' + money(valueCalc.target) + ' • preço ref. ' + money(valueCalc.referencePrice))
          : ('Venda por valor ' + money(valueCalc.target));
        addProduct(Number(product.id), {
          qty:valueCalc.qty,
          priceOverride:valueCalc.price,
          priceDecimals:valueCalc.priceDecimals,
          valueTarget:valueCalc.target,
          referencePrice:valueCalc.referencePrice,
          summary:valueDetail,
          key:'value_' + Number(product.id) + '_' + Date.now()
        });
        showMobileToast('Produto por valor: ' + money(valueCalc.target));
        checkoutProductValueTarget = 0;
        clearProductMultiplier();
        checkoutFocusSearch();
      }
      return;
    }
    openOptions(Number(product.id), pendingProductQuantity);
  }

  function addProduct(id, cfg={}){
    if(isCheckoutPdv && checkoutHasOsItems()){
      checkoutNotice('A venda contém Ordem de Serviço. Para preservar o faturamento fiscal separado de produtos e serviços, finalize ou remova as O.S. antes de incluir produtos avulsos.','Ordem de Serviço','warning');
      return;
    }
    const p = products.find(x => Number(x.id) === Number(id)); if(!p) return;
    const baseKey = cfg.key || String(id);
    // No PDV Checkout cada leitura deve abrir uma linha própria, imitando o fluxo
    // de checkout de supermercado. Produto pesável também continua sempre individual.
    const uniqueKey = `${baseKey}__${Date.now()}_${++weightedCartLineSeq}`;
    const key = (isCheckoutPdv || p.pesavel) ? uniqueKey : baseKey;
    const calculatedPrice = cfg.priceOverride !== undefined && cfg.priceOverride !== null
      ? Number(cfg.priceOverride || 0)
      : (Number(cfg.basePrice ?? p.price) + Number(cfg.extra||0));
    const it = cart.get(key) || {
      id:p.id,
      code:String(p.code || p.menuCode || p.barcode || p.id),
      unit:String(p.stockUnit || (p.pesavel ? 'KG' : 'UN')),
      name:cfg.displayName || p.name,
      qty:0,
      price:calculatedPrice,
      priceDecimals:Math.max(2, Math.min(6, Number(cfg.priceDecimals || 2))),
      valueTarget:Number(cfg.valueTarget || 0),
      referencePrice:Number(cfg.referencePrice || 0),
      obs:cfg.note || '',
      details:cfg.summary || '',
      splitItems: cfg.splitItems || null
    };
    it.qty += Number(cfg.qty || 1);
    if (cfg.splitItems) it.splitItems = cfg.splitItems;
    if (cfg.priceOverride !== undefined && cfg.priceOverride !== null) it.price = calculatedPrice;
    if (cfg.priceDecimals) it.priceDecimals = Math.max(2, Math.min(6, Number(cfg.priceDecimals || 2)));
    if (cfg.valueTarget) it.valueTarget = Number(cfg.valueTarget || 0);
    if (cfg.referencePrice) it.referencePrice = Number(cfg.referencePrice || 0);
    cart.set(key, it);
    checkoutLastCartKey = key;
    checkoutSelectedCartKey = key;
    checkoutCartScrollToEnd = true;
    renderCart();
    showMobileToast('Item adicionado');
  }
  function formatScaleKg(v){
    return Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:3, maximumFractionDigits:3}) + ' kg';
  }
  function adjustCheckoutCartViewport(){
    if(!isCheckoutPdv || !cartBox) return;
    const grid = cartBox.closest('.pdv-cart-step-grid');
    const panel = cartBox.closest('.pdv-panel-card');
    const listWrap = cartBox.parentElement;
    const header = listWrap ? listWrap.querySelector('.checkout-sale-head') : null;
    const summary = grid ? grid.querySelector('.pdv-side-summary') : null;
    if(!grid || !panel || !listWrap || !header || !summary) return;
    const sectionHead = panel.querySelector('.pdv-section-head');
    const gridStyle = window.getComputedStyle(grid);
    const rowGap = parseFloat(gridStyle.rowGap || gridStyle.gap || '0') || 0;
    const availableHeight = panel.clientHeight - (sectionHead ? sectionHead.offsetHeight : 0) - summary.offsetHeight - rowGap - 4;
    if(availableHeight <= 140) return;
    listWrap.style.height = availableHeight + 'px';
    listWrap.style.maxHeight = availableHeight + 'px';
    listWrap.style.minHeight = availableHeight + 'px';
    const cartViewportHeight = Math.max(96, availableHeight - header.offsetHeight);
    cartBox.style.height = cartViewportHeight + 'px';
    cartBox.style.maxHeight = cartViewportHeight + 'px';
    cartBox.style.minHeight = cartViewportHeight + 'px';
  }
  async function processWeightedProduct(product){
    try{
      if(!window.NexaScale || !Number(scaleCfg?.enabled || 0) || !window.NexaScale.isSupported()){
        throw new Error('Sem comunicação automática com a balança. Informe o peso manualmente em kg.');
      }
      showMobileToast('Lendo peso da balança...');
      const weight = await window.NexaScale.readWeight(scaleCfg, {fresh:true});
      if(Number(weight || 0) <= 0){
        throw new Error('A balança não retornou um peso válido. Informe o peso manualmente em kg.');
      }
      addProduct(product.id, {qty:Number(weight)});
      clearProductMultiplier();
      showMobileToast('Adicionado: ' + formatScaleKg(weight));
      return true;
    }catch(e){
      await openOptions(product.id, 1, {forceManual:true, manualReason:(e && e.message) ? e.message : 'Não foi possível ler a balança. Informe o peso manualmente em kg.'});
      return false;
    }
  }
  async function openOptions(id, requestedQty=pendingProductQuantity, options={}){
    const p = products.find(x => Number(x.id) === Number(id)); if(!p) return;
    if(Number(options.targetValue || 0) > 0) checkoutProductValueTarget = Number(options.targetValue || 0);
    const initialQty = Math.max(0.001, Number(requestedQty || 1));
    const groups = productOptions[p.id] || [];
    if(p.pesavel && !groups.length && !p.isPizza && !options.forceManual && !(checkoutProductValueTarget > 0)){ await processWeightedProduct(p); return; }
    if(!groups.length && !p.isPizza && !p.pesavel){ addProduct(p.id, {qty:initialQty}); clearProductMultiplier(); return; }
    currentProductForOptions = p;
    document.getElementById('optProductTitle').textContent = p.name + (p.pesavel ? ' (produto pesável)' : '');
    document.getElementById('optQty').value = (p.pesavel && !(checkoutProductValueTarget > 0)) ? '' : String(initialQty).replace('.', ',');
    document.getElementById('optNote').value = '';
    if(checkoutProductValueTarget > 0){
      const box = document.getElementById('pdvScaleBox');
      if(box){ box.style.display='none'; box.innerHTML=''; }
    }else if(options.manualReason && p.pesavel){
      const box = document.getElementById('pdvScaleBox');
      if(box){
        box.style.display = '';
        box.innerHTML = '<strong>Informar peso manualmente</strong><small>' + escapeHtml(options.manualReason) + '</small>';
      }
    }else{
      prepareScaleControls(p, 'optQty', 'pdvScaleBox');
    }

    const half = document.getElementById('optHalfBox');
    const sameCategoryPizzas = pizzas.filter(x => String(x.category || '').trim().toLocaleLowerCase('pt-BR') === String(p.category || '').trim().toLocaleLowerCase('pt-BR'));
    if(p.isPizza && sameCategoryPizzas.length > 1){
      half.style.display = '';
      half.innerHTML = `<strong>Pizza meio a meio</strong><label style="display:flex;gap:8px;align-items:center;margin:6px 0 10px;"><input type="checkbox" id="halfEnabled"> Montar meio a meio</label><div class="mg-form-grid"><label>1º sabor<input readonly value="${escapeHtml(p.name)}"></label><label>2º sabor<select id="halfFlavor"><option value="">Selecione o segundo sabor</option>${sameCategoryPizzas.filter(x=>Number(x.id)!==Number(p.id)).map(x=>`<option value="${x.id}" data-price="${x.price}">${escapeHtml(x.name)} - ${money(x.price)}</option>`).join('')}</select></label></div><small class="mg-muted">Somente produtos da mesma categoria. Regra de preço: prevalece o maior valor entre os dois sabores.</small>`;
    } else { half.style.display = 'none'; half.innerHTML = ''; }

    document.getElementById('optGroups').innerHTML = groups.map(g => {
      const inputType = (g.type === 'multiple' || Number(g.max) > 1) ? 'checkbox' : 'radio';
      return `<fieldset class="pdv-opt-group" data-group="${g.id}" data-required="${g.required}" data-min="${g.min||0}" data-max="${g.max||0}"><legend><span>${escapeHtml(g.title)}</span><small>${g.required?'Obrigatório':'Opcional'}${g.max?` • até ${g.max}`:''}</small></legend><div class="pdv-opt-items">${(g.items||[]).map(it=>`<label class="pdv-opt-item"><input type="${inputType}" name="opt_group_${g.id}" value="${it.id}" data-name="${escapeHtml(it.name)}" data-price="${Number(it.price||0)}"><span><b>${escapeHtml(it.name)}</b><small class="pdv-opt-price"><span>${Number(it.price||0)>0?('+'+money(it.price)):'sem acréscimo'}</span><em class="pdv-opt-final" hidden></em></small></span></label>`).join('')}</div></fieldset>`;
    }).join('');
    updateOptionsTotal();
    openModal('pdvOptionModal');
    if(p.pesavel && !options.forceManual && !(checkoutProductValueTarget > 0) && window.NexaScale && Number(scaleCfg?.enabled || 0) && window.NexaScale.isSupported()){
      const qtyInput = document.getElementById('optQty');
      const scaleBox = document.getElementById('pdvScaleBox');
      const status = scaleBox?.querySelector('[data-scale-status]');
      try{
        if(status) status.textContent = 'Lendo peso automaticamente...';
        const weight = await window.NexaScale.readWeight(scaleCfg, {fresh:true});
        if(Number(weight || 0) <= 0) throw new Error('Peso inválido. Informe manualmente.');
        window.NexaScale.applyToInput(qtyInput, weight);
        if(status) status.textContent = 'Peso aplicado: ' + formatScaleKg(weight);
      }catch(e){
        if(status) status.textContent = e && e.message ? e.message : 'Não foi possível ler a balança. Informe manualmente.';
      }
    }
  }
  function closeOptions(){ const box=document.getElementById('pdvScaleBox'); if(box){ box.innerHTML=''; box.style.display='none'; } closeModal('pdvOptionModal'); currentProductForOptions = null; checkoutProductValueTarget = 0; }
  function updateOptionsTotal(){
    if(!currentProductForOptions) return;
    let extra = 0;
    let base = Number(currentProductForOptions.price || 0);
    document.querySelectorAll('#optGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.pdv-opt-item')?.querySelector('.pdv-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + money(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
      if(i.checked) extra += price;
    });
    const halfEnabled = document.getElementById('halfEnabled');
    const halfFlavor = document.getElementById('halfFlavor');
    if(halfEnabled && halfEnabled.checked && halfFlavor && halfFlavor.value){
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      base = Math.max(base, Number(opt?.dataset.price || 0));
    }
    document.querySelectorAll('#optGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.pdv-opt-item')?.querySelector('.pdv-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + money(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
    });
    document.getElementById('optTotal').value = productOptionDisplay.showItemTotalWithAdditionals ? ('+ ' + money(extra) + ' | Item: ' + money(base + extra)) : ('+ ' + money(extra));
  }
  function confirmOptions(){
    if(!currentProductForOptions) return;
    const groups = Array.from(document.querySelectorAll('#optGroups .pdv-opt-group'));
    let extra = 0, lines = [], selectedIds = [];
    for(const g of groups){
      const checked = Array.from(g.querySelectorAll('input:checked'));
      const required = Number(g.dataset.required || 0) === 1;
      const min = Number(g.dataset.min || 0);
      const max = Number(g.dataset.max || 0);
      const needed = min || (required ? 1 : 0);
      if(checked.length < needed){ checkoutNotice('Selecione as opções obrigatórias.','Opções do produto','warning'); return; }
      if(max > 0 && checked.length > max){ checkoutNotice('Este grupo permite no máximo ' + max + ' opção(ões).','Opções do produto','warning'); return; }
      const groupTitleEl = g.querySelector('legend span');
      const groupTitle = groupTitleEl ? groupTitleEl.textContent.trim() : '';
      const groupLines = [];
      checked.forEach(i => {
        extra += Number(i.dataset.price || 0);
        selectedIds.push(i.value);
        groupLines.push(`${i.dataset.name}${Number(i.dataset.price||0)>0 ? ' +' + money(i.dataset.price) : ''}`);
      });
      if(groupLines.length){
        lines.push((groupTitle ? groupTitle + ': ' : '') + groupLines.join(', '));
      }
    }
    let basePrice = Number(currentProductForOptions.price || 0);
    let displayName = currentProductForOptions.name;
    const halfEnabled = document.getElementById('halfEnabled');
    const halfFlavor = document.getElementById('halfFlavor');
    let splitItems = null;
    if(halfEnabled && halfEnabled.checked){
      if(!halfFlavor || !halfFlavor.value){ checkoutNotice('Selecione o segundo sabor da pizza.','Pizza meio a meio','warning'); return; }
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      const secondFlavorId = Number(halfFlavor.value || 0);
      basePrice = Math.max(basePrice, Number(opt?.dataset.price || 0));
      const secondFlavorName = (opt.textContent || '').replace(/ - R\$.*/, '').trim();
      displayName = 'Pizza meio a meio';
      // Mantém o carrinho visualmente simples, mas grava no banco como dois itens de 0,5
      // para manter o mesmo padrão do catálogo e facilitar o controle de estoque.
      lines.unshift('1/2 ' + currentProductForOptions.name + ' +');
      lines.unshift('Pizza meio a meio:');
      lines.splice(2, 0, '1/2 ' + secondFlavorName);
      splitItems = [
        {id: Number(currentProductForOptions.id), qtyFactor: 0.5, obs: 'full'},
        {id: secondFlavorId, qtyFactor: 0.5, obs: ''}
      ];
      selectedIds.push('half_'+halfFlavor.value);
    }
    const qtyRaw = parseMoney(document.getElementById('optQty').value || '0');
    const finalUnitPrice = Number(basePrice || 0) + Number(extra || 0);
    let qty = Math.max(0.001, qtyRaw || 1);
    let valueCalc = null;
    if(checkoutProductValueTarget > 0){
      if(finalUnitPrice <= 0){ checkoutNotice('Não foi possível calcular a quantidade: valor unitário inválido.','Produto por valor','warning'); return; }
      valueCalc = checkoutValueCalculation(checkoutProductValueTarget, finalUnitPrice);
      if(!valueCalc){ checkoutNotice('Não foi possível calcular a quantidade para o valor informado.','Produto por valor','warning'); return; }
      qty = valueCalc.qty;
      lines.push(valueCalc.adjusted
        ? ('Venda por valor ' + money(valueCalc.target) + ' • preço ref. ' + money(valueCalc.referencePrice))
        : ('Venda por valor ' + money(valueCalc.target)));
    }else if(currentProductForOptions.pesavel && qtyRaw <= 0){ checkoutNotice('Leia o peso da balança ou informe a quantidade em kg.','Produto pesável','warning'); return; }
    const note = document.getElementById('optNote').value.trim();
    const signature = [currentProductForOptions.id, selectedIds.sort().join('-'), note.toLowerCase(), displayName].join('|');
    addProduct(currentProductForOptions.id, {
      extra,
      qty,
      note,
      summary:lines.join('\n'),
      basePrice,
      displayName,
      splitItems,
      priceOverride:valueCalc ? valueCalc.price : null,
      priceDecimals:valueCalc ? valueCalc.priceDecimals : 2,
      valueTarget:valueCalc ? valueCalc.target : 0,
      referencePrice:valueCalc ? valueCalc.referencePrice : 0,
      key:'cfg_' + btoa(unescape(encodeURIComponent(signature))).replace(/=/g,'').slice(0,24)
    });
    clearProductMultiplier();
    closeOptions();
  }

  function checkoutHasOsItems(){
    return Array.from(cart.values()).some(it => it.sourceType === 'os');
  }
  function checkoutHasNonOsItems(){
    return Array.from(cart.values()).some(it => it.sourceType !== 'os');
  }
  function checkoutOsCurrentIds(){
    return Array.from(new Set(Array.from(cart.values()).filter(it=>it.sourceType==='os').map(it=>Number(it.sourceId||0)).filter(id=>id>0)));
  }
  function checkoutSetOsFinancialLock(locked){
    ['discountPercent','discountAmount','additionPercent','additionAmount','deliveryFee'].forEach(id=>{ const el=document.getElementById(id); if(el) el.readOnly=!!locked; });
    checkoutOsFinancialLocked=!!locked;
  }
  function checkoutApplyOsFinancials(){
    const ids=checkoutOsCurrentIds();
    if(!ids.length){
      if(checkoutOsFinancialLocked){
        ['discountPercent','discountAmount','additionPercent','additionAmount','deliveryFee'].forEach(id=>{const el=document.getElementById(id);if(el)el.value='0,00';});
        discountAdjustmentMode='amount'; additionAdjustmentMode='amount';
      }
      checkoutSetOsFinancialLock(false);
      checkoutOsFiscalChoiceConfirmed=false;
      setVal('checkoutOsProductDocument',''); setVal('checkoutOsServiceDocument','');
      return;
    }
    let discount=0,addition=0;
    ids.forEach(id=>{const m=checkoutOsMeta.get(Number(id)); if(m){discount+=Number(m.discount||0);addition+=Number(m.addition||0);}});
    const d=document.getElementById('discountAmount'),a=document.getElementById('additionAmount'),f=document.getElementById('deliveryFee');
    if(d)d.value=fmtInput(discount); if(a)a.value=fmtInput(addition); if(f){f.value='0,00';f.dataset.manual='1';}
    discountAdjustmentMode='amount'; additionAdjustmentMode='amount';
    syncCheckoutAdjustmentPair('discount','amount'); syncCheckoutAdjustmentPair('addition','amount');
    checkoutSetOsFinancialLock(true);
  }
  async function checkoutRemoveOsSource(osId, ask=true){
    osId=Number(osId||0); if(osId<=0)return false;
    const meta=checkoutOsMeta.get(osId)||{};
    if(ask && !await checkoutConfirm('Remover toda a O.S. '+(meta.osNumber?('#'+String(meta.osNumber).padStart(6,'0')):('#'+osId))+' desta venda? Todos os itens dessa O.S. serão removidos.','Remover Ordem de Serviço','danger','Remover O.S.')) return false;
    for(const [key,it] of Array.from(cart.entries())) if(it.sourceType==='os' && Number(it.sourceId||0)===osId) cart.delete(key);
    checkoutOsMeta.delete(osId); checkoutOsSelected.delete(osId); checkoutOsFiscalChoiceConfirmed=false;
    setVal('checkoutOsProductDocument',''); setVal('checkoutOsServiceDocument','');
    const keys=Array.from(cart.keys()); checkoutLastCartKey=keys.length?keys[keys.length-1]:null; checkoutSelectedCartKey=checkoutLastCartKey;
    checkoutApplyOsFinancials(); renderCart();
    return true;
  }

  function syncCheckoutSourceHidden(){
    const comandas = {};
    const orcamentos = new Set();
    const osSources = {};
    cart.forEach(it => {
      if(it.sourceType === 'comanda' && Number(it.sourceId || 0) > 0 && Number(it.sourceItemId || 0) > 0){
        const cid = String(Number(it.sourceId));
        if(!comandas[cid]) comandas[cid] = [];
        if(!comandas[cid].includes(Number(it.sourceItemId))) comandas[cid].push(Number(it.sourceItemId));
      }
      if(it.sourceType === 'orcamento' && Number(it.sourceId || 0) > 0) orcamentos.add(Number(it.sourceId));
      if(it.sourceType === 'os' && Number(it.sourceId || 0) > 0 && Number(it.sourceItemId || 0) > 0){
        const oid=String(Number(it.sourceId));
        if(!osSources[oid]) osSources[oid]=[];
        if(!osSources[oid].includes(Number(it.sourceItemId))) osSources[oid].push(Number(it.sourceItemId));
      }
    });
    const cmdEl = document.getElementById('checkoutComandaSources');
    const orcEl = document.getElementById('checkoutOrcamentoSources');
    const osEl = document.getElementById('checkoutOsSources');
    if(cmdEl) cmdEl.value = JSON.stringify(comandas);
    if(orcEl) orcEl.value = JSON.stringify(Array.from(orcamentos));
    if(osEl) osEl.value = JSON.stringify(osSources);
    const legacyOrc = document.getElementById('orcamentoId');
    if(legacyOrc) legacyOrc.value = Array.from(orcamentos)[0] ? String(Array.from(orcamentos)[0]) : '';
  }
  function syncCartHidden(){
    hidden.innerHTML = Array.from(cart.values()).map(it=>{
      const itemObs = (it.details ? it.details + '\n' : '') + (it.obs || '');
      const priceDecimals = Math.max(2, Math.min(6, Number(it.priceDecimals || 2)));
      if (it.splitItems && Array.isArray(it.splitItems) && it.splitItems.length) {
        return it.splitItems.map((sp, idx) => {
          const obs = (sp.obs === 'full' || idx === 0) ? itemObs : '';
          const qtd = Number(it.qty || 0) * Number(sp.qtyFactor || 1);
          return `<input type="hidden" name="item_product_id[]" value="${Number(sp.id||it.id)}"><input type="hidden" name="item_qty[]" value="${qtd}"><input type="hidden" name="item_price[]" value="${it.price}"><input type="hidden" name="item_stock_effect[]" value="1"><input type="hidden" name="item_price_decimals[]" value="${priceDecimals}"><textarea name="item_obs[]" style="display:none">${escapeHtml(obs)}</textarea>`;
        }).join('');
      }
      const stockEffect = Number(it.stockEffect ?? 1) === 1 ? 1 : 0;
      return `<input type="hidden" name="item_product_id[]" value="${it.id}"><input type="hidden" name="item_qty[]" value="${it.qty}"><input type="hidden" name="item_price[]" value="${it.price}"><input type="hidden" name="item_stock_effect[]" value="${stockEffect}"><input type="hidden" name="item_price_decimals[]" value="${priceDecimals}"><textarea name="item_obs[]" style="display:none">${escapeHtml(itemObs)}</textarea>`;
    }).join('');
    syncCheckoutSourceHidden();
  }
  function renderCart(){
    const rows=[];
    if(isCheckoutPdv){
      if(checkoutSelectedCartKey && !cart.has(checkoutSelectedCartKey)) checkoutSelectedCartKey = null;
      if(checkoutLastCartKey && !cart.has(checkoutLastCartKey)) checkoutLastCartKey = null;
      if(!checkoutSelectedCartKey && cart.size) checkoutSelectedCartKey = Array.from(cart.keys()).at(-1) || null;
      if(!checkoutLastCartKey && cart.size) checkoutLastCartKey = Array.from(cart.keys()).at(-1) || null;
      cart.forEach((it,key) => {
        const selected = String(key) === String(checkoutSelectedCartKey);
        const qtyText = quantitySummary(it.qty);
        const unit = String(it.unit || 'UN').toUpperCase();
        const detailLine = it.details ? `<small>${escapeHtml(String(it.details).replace(/\s+/g,' ').trim())}</small>` : '';
        rows.push(`<div class="checkout-sale-row${selected?' is-selected':''}" data-checkout-cart-key="${escapeHtml(key)}" tabindex="0"><div class="checkout-sale-code">${escapeHtml(it.code || it.id)}</div><div class="checkout-sale-desc"><strong>${escapeHtml(it.name)}</strong>${detailLine}</div><div class="checkout-sale-calc">${qtyText} ${escapeHtml(unit)} × ${money(it.price)}</div><div class="checkout-sale-total">${money(it.qty*it.price)}</div><button type="button" data-del="${escapeHtml(key)}" class="checkout-sale-action" title="Cancelar item">×</button></div>`);
      });
      cartBox.innerHTML = rows.join('') || '<div class="mg-empty" style="padding:34px">Nenhum produto lançado. Digite ou leia um código para iniciar a venda.</div>';
      adjustCheckoutCartViewport();
      if(checkoutCartScrollToEnd && cartBox){
        const keepLastAtBottom = () => {
          const maxScroll = Math.max(0, cartBox.scrollHeight - cartBox.clientHeight);
          cartBox.scrollTop = maxScroll;
          const lastRow = cartBox.querySelector('.checkout-sale-row:last-of-type');
          if(lastRow && typeof lastRow.scrollIntoView === 'function'){
            lastRow.scrollIntoView({block:'end', inline:'nearest', behavior:'auto'});
          }
          cartBox.scrollTop = Math.max(0, cartBox.scrollHeight - cartBox.clientHeight);
        };
        requestAnimationFrame(() => {
          keepLastAtBottom();
          requestAnimationFrame(keepLastAtBottom);
        });
        setTimeout(keepLastAtBottom, 40);
        checkoutCartScrollToEnd = false;
      }
      const last = checkoutLastCartKey ? cart.get(checkoutLastCartKey) : null;
      setText('checkoutLastItemName', last ? last.name : 'Aguardando produto...');
      setText('checkoutLastItemCalc', last ? `${quantitySummary(last.qty)} ${String(last.unit || 'UN').toUpperCase()} × ${money(last.price)} = ${money(last.qty*last.price)}` : 'Digite, leia o código de barras ou pressione F8.');
    }else{
      cart.forEach((it,key) => rows.push(`<div class="pdv-cart-item"><div class="pdv-cart-line"><strong>${escapeHtml(it.name)}</strong><b>${money(it.qty*it.price)}</b></div>${it.details?`<small class="mg-muted" style="display:block;white-space:pre-line;margin-top:4px;">${escapeHtml(it.details)}</small>`:''}<div class="pdv-qty"><button type="button" data-dec="${escapeHtml(key)}">−</button><input data-qty="${escapeHtml(key)}" value="${String(it.qty).replace('.',',')}"><button type="button" data-inc="${escapeHtml(key)}">+</button><button type="button" data-del="${escapeHtml(key)}" class="pdv-trash-btn" title="Remover item">🗑</button></div><input class="pdv-item-note" data-obs="${escapeHtml(key)}" value="${escapeHtml(it.obs||'')}" placeholder="${escapeHtml(productOptionDisplay.itemNoteInstruction || 'Observação do item')}"></div>`));
      cartBox.innerHTML = rows.join('') || '<div class="mg-empty">Nenhum produto no carrinho.</div>';
    }
    syncCartHidden();
    if(isCheckoutPdv) checkoutApplyOsFinancials();
    setText('pdvItemsCount', quantitySummary(cartCount()));
    setText('checkoutItemsCount', quantitySummary(cartCount()));
    updateTotals();
  }

  function subtotal(){ let v=0; cart.forEach(it => v += Number(it.qty||0)*Number(it.price||0)); return v; }
  function calcDeliveryFee(){
    const type = document.getElementById('orderType').value || defaultOrderType || 'retirada';
    if(type !== 'entrega') return 0;
    const district = normalize(document.getElementById('deliveryDistrict').value || document.getElementById('orderDistrict').value || '');
    if(!district) return deliveryDefault;
    for(const k in deliveryFees){ if(normalize(k) === district) return Number(deliveryFees[k] || 0); }
    return deliveryDefault;
  }
  function syncAddressHidden(forceFee=true){
    const src = selectedAddressSource();
    const srcZip = src ? onlyDigits(src.zip_code || src.cep || '') : '';
    const srcDistrict = src ? String(src.district || src.bairro || '') : '';
    const srcAddress = src ? addressStreet(src) : '';
    const srcNumber = src ? addressNumber(src) : '';
    const srcComplement = src ? addressComplement(src) : '';
    const srcReference = src ? addressReference(src) : '';
    const srcCity = src ? String(src.city || src.cidade || '') : '';
    const srcState = src ? String(src.state || src.uf || '') : '';

    // Quando o endereço foi carregado automaticamente pelo cadastro, os campos ocultos
    // precisam receber os valores mesmo que o operador não clique/passe pelos campos.
    fillIfEmpty('deliveryZip', srcZip);
    fillIfEmpty('deliveryDistrict', srcDistrict);
    fillIfEmpty('deliveryAddress', srcAddress);
    fillIfEmpty('deliveryNumber', srcNumber);
    fillIfEmpty('deliveryComplement', srcComplement);
    fillIfEmpty('deliveryReference', srcReference);

    const zip = onlyDigits(document.getElementById('deliveryZip')?.value || srcZip);
    const district = (document.getElementById('deliveryDistrict')?.value || srcDistrict).trim();
    let address = cleanStreetWithDistrict((document.getElementById('deliveryAddress')?.value || srcAddress), district || srcDistrict);
    if(document.getElementById('deliveryAddress') && document.getElementById('deliveryAddress').value.trim() !== address && address) document.getElementById('deliveryAddress').value = address;
    const number = document.getElementById('deliveryNumber') ? (document.getElementById('deliveryNumber').value || srcNumber).trim() : srcNumber;
    const complement = document.getElementById('deliveryComplement') ? (document.getElementById('deliveryComplement').value || srcComplement).trim() : srcComplement;
    const reference = document.getElementById('deliveryReference') ? (document.getElementById('deliveryReference').value || srcReference).trim() : srcReference;
    const city = (document.getElementById('orderCity')?.value || srcCity).trim();
    const state = (document.getElementById('orderState')?.value || srcState).trim();

    setVal('orderZipCode', zip);
    setVal('orderDistrict', district);
    setVal('orderAddressNumber', number);
    setVal('orderAddressComplement', complement);
    // Envia logradouro separado de número/complemento.
    // O servidor monta o endereço final e evita duplicidade.
    setVal('orderAddress', address);
    setVal('orderAddressReference', reference);
    setVal('orderCity', city);
    setVal('orderState', state);
    const osSale = isCheckoutPdv && checkoutHasOsItems();
    const fee = osSale ? 0 : calcDeliveryFee();
    setText('clientDeliveryFeeView', money(fee));
    const feeInput = document.getElementById('deliveryFee');
    if(osSale){ feeInput.value='0,00'; feeInput.dataset.manual='1'; }
    else if(forceFee || feeInput.dataset.manual !== '1') feeInput.value = fmtInput(fee);
    updateTotals();
  }
  function formatPercentInput(value){
    const n = Number(value || 0);
    if(!Number.isFinite(n)) return '0,00';
    return n.toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:4});
  }
  function syncCheckoutAdjustmentPair(kind, source=null){
    const isDiscount = kind === 'discount';
    const percentEl = document.getElementById(isDiscount ? 'discountPercent' : 'additionPercent');
    const amountEl = document.getElementById(isDiscount ? 'discountAmount' : 'additionAmount');
    if(!percentEl || !amountEl) return;
    if(source){
      if(isDiscount) discountAdjustmentMode = source;
      else additionAdjustmentMode = source;
    }
    const mode = isDiscount ? discountAdjustmentMode : additionAdjustmentMode;
    const sub = subtotal();
    if(mode === 'percent'){
      const pct = Math.max(0, parseMoney(percentEl.value));
      amountEl.value = fmtInput(sub * pct / 100);
    }else{
      const amount = Math.max(0, parseMoney(amountEl.value));
      const pct = sub > 0 ? (amount / sub) * 100 : 0;
      percentEl.value = formatPercentInput(pct);
    }
  }
  function syncCheckoutAdjustments(){
    syncCheckoutAdjustmentPair('discount');
    syncCheckoutAdjustmentPair('addition');
  }
  function orderTotal(){
    syncCheckoutAdjustments();
    const discount = parseMoney(document.getElementById('discountAmount').value);
    const addition = parseMoney(document.getElementById('additionAmount').value);
    const delivery = parseMoney(document.getElementById('deliveryFee').value);
    return Math.max(0, subtotal() - discount + addition + delivery);
  }
  function renderInstallments(line, force=false){
    const method = line.querySelector('.pay-method')?.value || '';
    const p = paymentMap[method] || {};
    const box = line.querySelector('.pdv-installments');
    if(!box) return;
    const rowsBox = box.querySelector('.pdv-installments-rows');
    if(p.receivable != 1){ box.classList.remove('show'); if(rowsBox) rowsBox.innerHTML=''; return; }
    box.classList.add('show');
    if(!rowsBox) return;
    const amount = parseMoney(line.querySelector('.pay-amount')?.value || '0');
    const countInput = box.querySelector('.installment-count');
    countInput.disabled = p.allowInstallments != 1;
    const defaultCount = Math.max(1, Number(p.defaultInstallments || 1));
    if(!countInput.value || Number(countInput.value) < 1) countInput.value = String(p.allowInstallments == 1 ? defaultCount : 1);
    const desiredCount = p.allowInstallments == 1 ? Math.max(1, Math.min(120, Number(countInput.value || defaultCount))) : 1;
    const existing = rowsBox.querySelectorAll('.pdv-installment-row').length;
    const amountKey = fmtInput(amount);
    if(!force && existing === desiredCount && rowsBox.querySelectorAll('input').length && box.dataset.amount === amountKey) return;
    const fixedDueDay = Number(p.fixedDueDay || 0);
    const firstDue = (Number(p.creRulesEnabled||0)===1 && p.keepDueDay == 1 && fixedDueDay>=1) ? isoNextFixedDay(todayIso, fixedDueDay) : isoAddDays(todayIso, Number(p.firstDueDays || 30));
    const dueDay = fixedDueDay>=1 ? fixedDueDay : Number(firstDue.slice(8,10));
    const periodDays = Math.max(1, Number(p.periodDays || 30));
    const values = splitInstallmentAmounts(amount, desiredCount);
    const key = line.querySelector('.pay-line-key')?.value || '0';
    rowsBox.innerHTML = '';
    box.dataset.amount = amountKey;
    for(let i=0;i<desiredCount;i++){
      const due = i === 0 ? firstDue : (p.keepDueDay == 1 ? isoAddMonthsKeepDay(firstDue, i, dueDay) : isoAddDays(firstDue, periodDays * i));
      const row = document.createElement('div');
      row.className = 'pdv-installment-row';
      row.innerHTML = `<div class="pdv-installment-label">${String(i+1).padStart(2,'0')}/${String(desiredCount).padStart(2,'0')}</div><div class="pdv-installment-due-wrap"><input class="installment-due" type="date" name="installment_due[${key}][]" value="${due}" required></div><div class="pdv-installment-amount-wrap"><input class="installment-amount" name="installment_amount[${key}][]" inputmode="decimal" value="${fmtInput(values[i])}" required></div>`;
      rowsBox.appendChild(row);
    }
  }

  function updateTotals(){
    const sub = subtotal();
    syncCheckoutAdjustments();
    const discount = parseMoney(document.getElementById('discountAmount').value);
    const addition = parseMoney(document.getElementById('additionAmount').value);
    const delivery = parseMoney(document.getElementById('deliveryFee').value);
    const total = Math.max(0, sub - discount + addition + delivery);
    setText('subtotalView', money(sub));
    setText('discountView', money(discount));
    setText('addsView', money(addition + delivery));
    setText('totalView', money(total));
    setText('wizardTotalView', money(total));
    setText('checkoutTotalView', money(total));
    setText('checkoutCustomerView', customerDisplayName());
    setText('paymentOrderTotalView', money(total));
    setText('paymentFinalTotalView', money(total));
    setText('mobilePaymentBigTotalView', money(total));
    setText('pdvMobilePaymentFooterTotal', money(total));
    setText('quickCustomerView', customerDisplayName());
    setText('productCustomerView', customerDisplayName());
    setText('paymentCustomerView', customerDisplayName());
    const payLines = Array.from(document.querySelectorAll('.pdv-payment-line'));
    if(payLines.length === 1){
      const amountInput = payLines[0].querySelector('.pay-amount');
      if(amountInput.dataset.autoSync === '1' || parseMoney(amountInput.value) <= 0){
        const nextAmount = fmtInput(total);
        if(Math.abs(parseMoney(amountInput.value) - parseMoney(nextAmount)) > 0.009) checkoutPixResetLine(payLines[0]);
        amountInput.value = nextAmount; amountInput.dataset.autoSync='1';
      }
    }
    let paySum=0, change=0, hasReceivable=false;
    payLines.forEach(line => {
      const method = line.querySelector('.pay-method').value;
      const p = paymentMap[method] || {};
      renderInstallments(line, false);
      const amount = parseMoney(line.querySelector('.pay-amount').value); paySum += amount;
      const receivedInp = line.querySelector('.pay-received');
      receivedInp.closest('label').style.display = p.allowChange == 1 ? '' : 'none';
      line.classList.toggle('show-received', p.allowChange == 1);
      if(p.allowChange == 1) change += Math.max(0, parseMoney(receivedInp.value) - amount);
      if(p.receivable == 1) hasReceivable = true;
    });
    const diff = total - paySum;
    setText('paymentsTotalView', money(paySum));
    setText('paymentsDiffView', money(diff));
    document.getElementById('paymentsDiffView').style.color = Math.abs(diff) <= 0.01 ? '#047857' : '#b91c1c';
    document.getElementById('changeRow').style.display = change > 0 ? '' : 'none';
    setText('changeView', money(change));
    document.getElementById('dueDateWrap').style.display = 'none';
    updateMobileActionBar();
  }
  function remainingPayment(){ const total=orderTotal(); let sum=0; document.querySelectorAll('.pay-amount').forEach(i=>sum += parseMoney(i.value)); return Math.max(0,total-sum); }
  function paymentMethodRequiresCustomer(methodId){
    const p = paymentMap[methodId] || {};
    const text = normalize([p.name, p.type].filter(Boolean).join(' '));
    return Number(p.receivable || 0) === 1 || text.includes('prazo') || text.includes('crediario') || text.includes('fiado') || text.includes('conta cliente');
  }
  function hasCreditSalePayment(){
    return Array.from(document.querySelectorAll('.pay-method')).some(sel => sel.value && paymentMethodRequiresCustomer(sel.value));
  }
  function requireCustomerForCreditSale(){
    if(!hasCreditSalePayment() || selectedCustomer) return true;
    const card = document.getElementById('customerResult');
    if(card){
      card.hidden = false;
      card.className = 'pdv-customer-card not-found';
      card.innerHTML = '<div class="pdv-customer-icon">!</div><div><strong>Cliente obrigatório para venda a prazo</strong><small>Selecione ou cadastre um cliente para finalizar com esta forma de pagamento. Sua venda continua no carrinho.</small></div>';
    }
    checkoutNotice('Para finalizar venda a prazo, selecione um cliente cadastrado. A venda não foi perdida; você será direcionado para a etapa de Cliente.','Cliente obrigatório','warning');
    fiscalChoiceConfirmed = !fiscalEmissionEnabled;
    showStep('cliente');
    if(isCheckoutPdv){ openCustomerSearchModal(); }
    else setTimeout(() => { document.getElementById('customerLookup')?.focus(); }, 180);
    return false;
  }
  function setActivePaymentLine(line){
    document.querySelectorAll('.pdv-payment-line.is-active').forEach(item => item.classList.remove('is-active'));
    activePaymentLine = line && document.body.contains(line) ? line : null;
    if(activePaymentLine) activePaymentLine.classList.add('is-active');
    return activePaymentLine;
  }
  function currentPaymentLine(){
    const focused = document.activeElement?.closest?.('.pdv-payment-line');
    if(focused) return setActivePaymentLine(focused);
    if(activePaymentLine && document.body.contains(activePaymentLine)) return activePaymentLine;
    const lines = Array.from(document.querySelectorAll('.pdv-payment-line'));
    return lines.length ? setActivePaymentLine(lines[lines.length - 1]) : null;
  }
  function focusPaymentAmount(line){
    if(!line) return;
    setActivePaymentLine(line);
    const amount = line.querySelector('.pay-amount');
    if(amount) setTimeout(() => { amount.focus(); amount.select?.(); }, 0);
  }
  function selectPaymentMethod(methodId, line=null){
    line = line || currentPaymentLine();
    if(!line) line = addPaymentLine(undefined, false, false);
    const select = line?.querySelector('.pay-method');
    if(!select || !paymentMap[String(methodId)]) return false;
    select.value = String(methodId);
    select.dispatchEvent(new Event('change', {bubbles:true}));
    focusPaymentAmount(line);
    return true;
  }
  function removePaymentLine(line=null){
    line = line || currentPaymentLine();
    if(!line) return;
    if(line === checkoutPixActiveLine) checkoutPixCloseModal();
    const lines = Array.from(document.querySelectorAll('.pdv-payment-line'));
    const index = lines.indexOf(line);
    line.remove();
    const remaining = Array.from(document.querySelectorAll('.pdv-payment-line'));
    if(!remaining.length){
      const created = addPaymentLine(orderTotal(), true, true);
      setActivePaymentLine(created);
    }else{
      const next = remaining[Math.max(0, Math.min(index, remaining.length - 1))];
      setActivePaymentLine(next);
      next.querySelector('.pay-method')?.focus();
      updateTotals();
    }
  }
  function addPaymentLine(amount, autoSync=false, focusMethod=false){
    const box = document.getElementById('paymentLines');
    const div = document.createElement('div'); div.className = 'pdv-payment-line';
    const paymentValue = amount ?? remainingPayment();
    const key = 'p' + (++paymentLineSeq);
    div.innerHTML = `<input type="hidden" name="payment_line_key[]" class="pay-line-key" value="${key}"><input type="hidden" name="payment_pix_charge_id[]" class="pay-pix-charge-id" value=""><label>Forma<select name="payment_method_id[]" class="pay-method" required><option value="">Selecione pelo número</option>${paymentOptionsHtml}</select></label><label class="pay-value">Valor<input name="payment_amount[]" class="pay-amount" inputmode="decimal" value="${fmtInput(paymentValue)}" data-auto-sync="${autoSync ? '1':'0'}"></label><label class="pay-received-label">Recebido<input name="payment_received[]" class="pay-received" inputmode="decimal" value="0,00"></label><button type="button" class="btn pay-remove" aria-label="Remover forma de pagamento">×</button><div class="pdv-installments"><div class="pdv-installments-head"><div><strong>Parcelas da conta a receber</strong><small>Edite vencimentos e valores. Diferenças podem ficar na última parcela.</small></div><label>Parcelas<input type="number" min="1" max="120" class="installment-count" value="1"></label><button type="button" class="btn pay-installments-generate">Gerar</button></div><div class="pdv-installments-list"><div class="pdv-installment-row head"><span>Parcela</span><span>Vencimento</span><span>Valor</span></div><div class="pdv-installments-rows"></div></div></div>`;
    box.appendChild(div);
    setActivePaymentLine(div);
    updateTotals();
    if(focusMethod) setTimeout(() => div.querySelector('.pay-method')?.focus(), 0);
    return div;
  }
  function choosePaymentShortcutDigit(digit){
    clearTimeout(paymentShortcutTimer);
    const attempt = paymentShortcutBuffer + String(digit);
    const keys = Object.keys(paymentShortcutByKey);
    const exact = paymentShortcutByKey[attempt] || '';
    const hasPrefix = keys.some(key => key.startsWith(attempt));
    const hasLonger = keys.some(key => key.startsWith(attempt) && key !== attempt);
    if(!hasPrefix){
      paymentShortcutBuffer = String(digit);
      const single = paymentShortcutByKey[paymentShortcutBuffer] || '';
      if(single){ selectPaymentMethod(single); paymentShortcutBuffer = ''; }
      else paymentShortcutBuffer = '';
      return;
    }
    paymentShortcutBuffer = attempt;
    if(exact && !hasLonger){
      selectPaymentMethod(exact);
      paymentShortcutBuffer = '';
      return;
    }
    paymentShortcutTimer = setTimeout(() => {
      const id = paymentShortcutByKey[paymentShortcutBuffer] || '';
      if(id) selectPaymentMethod(id);
      paymentShortcutBuffer = '';
    }, 520);
  }

  function addressesForCustomer(id){ return customerAddresses.filter(a => Number(a.person_id) === Number(id)); }
  function normalizeSpacesText(v){ return String(v || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim(); }
  function cleanStreetWithDistrict(value, district=''){
    let street = normalizeSpacesText(value);
    const bairro = normalizeSpacesText(district);
    if(!street) return '';
    const streetTypes = '(Rua|R\\.?|Avenida|Av\\.?|Alameda|Travessa|Tv\\.?|Praça|Praca|Rodovia|Estrada|Viela|Largo|Quadra|Condomínio|Condominio|Conjunto|Residencial)';
    if(bairro){
      const escBairro = bairro.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      street = street.replace(new RegExp('^Bairro:\\s*' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
      street = street.replace(new RegExp('^' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
      if(normalize(street) === normalize(bairro)) return '';
    }
    street = street.replace(new RegExp('^Bairro:\\s*.*?(?=' + streetTypes + '\\b)', 'iu'), '');
    street = street.replace(/^Bairro:\s*/iu, '');
    if(bairro && normalize(street) === normalize(bairro)) return '';
    return normalizeSpacesText(street);
  }
  function addressStreet(a){ return (a && a.address) ? cleanStreetWithDistrict(a.address, a.district || a.bairro || '') : ''; }
  function addressNumber(a){ return (a && a.number) ? String(a.number) : ''; }
  function addressComplement(a){ return (a && a.complement) ? String(a.complement) : ''; }
  function formatAddress(a){
    const streetLine = [addressStreet(a), addressNumber(a), addressComplement(a)].filter(Boolean).join(', ');
    const cityLine = [a?.district, a?.city, a?.state].filter(Boolean).join(' - ');
    return [streetLine, cityLine].filter(Boolean).join(' | ');
  }
  function addressReference(a){ return (a && (a.reference || a.referencia)) ? String(a.reference || a.referencia) : ''; }
  function selectedAddressSource(){
    if(selectedAddressId){
      const bySelected = customerAddresses.find(x => String(x.id) === String(selectedAddressId));
      if(bySelected) return bySelected;
    }
    if(selectedCustomer){
      const addrs = addressesForCustomer(selectedCustomer.id);
      return addrs.find(a => Number(a.is_default) === 1) || addrs[0] || selectedCustomer;
    }
    return null;
  }
  function fillIfEmpty(id, value){
    const el = document.getElementById(id);
    if(el && !String(el.value || '').trim() && String(value || '').trim()) el.value = value;
  }
  function setConsumerFinal(clearLookup=false){
    selectedCustomer = null; selectedAddressId = '';
    setVal('customerId',''); setVal('customerName','Consumidor final'); setVal('customerWhatsapp',''); setVal('orderZipCode',''); setVal('orderCustomerAddressId',''); setVal('orderAddress',''); setVal('orderAddressNumber',''); setVal('orderAddressComplement',''); setVal('orderDistrict',''); setVal('orderAddressReference',''); setVal('orderCity',''); setVal('orderState','');
    if(clearLookup) setVal('customerLookup','');
    if(clearLookup && document.getElementById('finalConsumerNameInput')) document.getElementById('finalConsumerNameInput').value = '';
    document.getElementById('customerWhatsappInput').value = '';
    if(document.getElementById('deliveryZip')) document.getElementById('deliveryZip').value = '';
    if(document.getElementById('deliveryDistrict')) document.getElementById('deliveryDistrict').value = '';
    if(document.getElementById('deliveryAddress')) document.getElementById('deliveryAddress').value = '';
    if(document.getElementById('deliveryNumber')) document.getElementById('deliveryNumber').value = '';
    if(document.getElementById('deliveryComplement')) document.getElementById('deliveryComplement').value = '';
    if(document.getElementById('deliveryReference')) document.getElementById('deliveryReference').value = '';
    const card = document.getElementById('customerResult');
    if(card){
      card.className = 'pdv-customer-card';
      if(isMobilePdv){
        card.hidden = true;
        card.innerHTML = '';
      } else {
        card.hidden = false;
        card.innerHTML = `<div class="pdv-customer-icon">CF</div><div><strong>Consumidor final</strong><small>Não será gravado cadastro de pessoa. Informe CPF/telefone apenas se quiser localizar ou cadastrar cliente.</small></div>`;
      }
    }
    const sel = document.getElementById('customerAddressSelect');
    sel.innerHTML = '<option value="">Consumidor final / endereço avulso</option>'; sel.disabled = true;
    document.getElementById('openAddressModalBtn').disabled = true;
    updateTotals();
    renderInlineCustomerList(document.getElementById('customerLookup')?.value || '');
  }
  function selectCustomer(c){
    selectedCustomer = c; setVal('customerId', c.id); setVal('customerName', c.name); setVal('customerWhatsapp', c.whatsapp || c.phone || ''); setVal('orderCustomerAddressId',''); if(document.getElementById('finalConsumerNameInput')) document.getElementById('finalConsumerNameInput').value = '';
    document.getElementById('customerWhatsappInput').value = c.whatsapp || c.phone || '';
    const card = document.getElementById('customerResult');
    if(card){
      card.hidden = false;
      card.className = 'pdv-customer-card found';
      card.innerHTML = `<div class="pdv-customer-icon">OK</div><div><strong>${escapeHtml(c.name)}</strong><small>${c.document?('Documento: '+escapeHtml(c.document)+' • '):''}${c.whatsapp||c.phone?('Contato: '+escapeHtml(c.whatsapp||c.phone)):'Cliente cadastrado'}</small></div>`;
    }
    renderAddressSelect();
    syncAddressHidden(true);
    updateTotals();
    renderInlineCustomerList(document.getElementById('customerLookup')?.value || '');
  }
  function renderAddressSelect(){
    const sel = document.getElementById('customerAddressSelect');
    sel.innerHTML = '<option value="">Endereço avulso / editar manualmente</option>';
    if(!selectedCustomer){ sel.disabled=true; setVal('orderCustomerAddressId',''); return; }
    const addrs = addressesForCustomer(selectedCustomer.id);
    addrs.forEach(a => {
      const opt = document.createElement('option'); opt.value = String(a.id); opt.textContent = `${a.is_default == 1 ? '★ ' : ''}${a.label || 'Entrega'} - ${formatAddress(a) || a.district || 'Endereço'}`; sel.appendChild(opt);
    });
    sel.disabled = false; document.getElementById('openAddressModalBtn').disabled = false; document.getElementById('addressPersonId').value = selectedCustomer.id;
    const def = addrs.find(a => Number(a.is_default) === 1) || addrs[0];
    if(def){ sel.value = String(def.id); applyAddress(def); }
    else {
      document.getElementById('deliveryZip').value = selectedCustomer.zip_code || '';
      document.getElementById('deliveryDistrict').value = selectedCustomer.district || '';
      document.getElementById('deliveryAddress').value = addressStreet(selectedCustomer);
      if(document.getElementById('deliveryNumber')) document.getElementById('deliveryNumber').value = addressNumber(selectedCustomer);
      if(document.getElementById('deliveryComplement')) document.getElementById('deliveryComplement').value = addressComplement(selectedCustomer);
      if(document.getElementById('deliveryReference')) document.getElementById('deliveryReference').value = addressReference(selectedCustomer);
      setVal('orderCity', selectedCustomer.city || selectedCustomer.cidade || '');
      setVal('orderState', selectedCustomer.state || selectedCustomer.uf || '');
      syncAddressHidden(true);
    }
  }
  function applyAddress(a){
    selectedAddressId = a ? String(a.id || '') : '';
    setVal('orderCustomerAddressId', selectedAddressId);
    document.getElementById('deliveryZip').value = a?.zip_code || '';
    document.getElementById('deliveryDistrict').value = a?.district || '';
    document.getElementById('deliveryAddress').value = a ? addressStreet(a) : '';
    if(document.getElementById('deliveryNumber')) document.getElementById('deliveryNumber').value = a ? addressNumber(a) : '';
    if(document.getElementById('deliveryComplement')) document.getElementById('deliveryComplement').value = a ? addressComplement(a) : '';
    if(document.getElementById('deliveryReference')) document.getElementById('deliveryReference').value = a ? addressReference(a) : '';
    setVal('orderCity', a?.city || a?.cidade || '');
    setVal('orderState', a?.state || a?.uf || '');
    syncAddressHidden(true);
  }
  function findCustomerByLookup(term){
    const raw = String(term || '').trim();
    const d = onlyDigits(raw);
    const txt = normalize(raw);
    if(d){
      const byDigits = customers.find(c => c.document === d || c.whatsapp === d || c.phone === d || (d.length >= 8 && ((c.whatsapp||'').endsWith(d) || (c.phone||'').endsWith(d)))) || null;
      if(byDigits) return byDigits;
    }
    if(txt.length >= 2){
      return customers.find(c => normalize(c.name || '').includes(txt) || normalize(c.fantasy_name || '').includes(txt) || normalize((c.document || '') + ' ' + (c.phone || '') + ' ' + (c.whatsapp || '')).includes(txt)) || null;
    }
    return null;
  }
  function customerMatchesTerm(c, term){
    const raw = String(term || '').trim();
    const d = onlyDigits(raw);
    const txt = normalize(raw);
    if(!d && txt.length < 2) return true;
    if(d){
      const joinedDigits = onlyDigits([c.document || '', c.whatsapp || '', c.phone || ''].join(' '));
      if(joinedDigits.includes(d)) return true;
      if(String(c.document || '') === d || String(c.whatsapp || '') === d || String(c.phone || '') === d) return true;
    }
    if(txt.length >= 2){
      const joined = normalize([c.name || '', c.fantasy_name || '', c.document || '', c.phone || '', c.whatsapp || '', c.email || ''].join(' '));
      return joined.includes(txt);
    }
    return false;
  }
  function customerSearchRows(term){
    const rows = customers.filter(c => customerMatchesTerm(c, term));
    rows.sort((a,b) => String(a.name || '').localeCompare(String(b.name || ''), 'pt-BR'));
    return rows.slice(0, 80);
  }
  function resetCustomerSearchModal(){
    modalCustomers = [];
    modalCustomerActiveIndex = -1;
    modalCustomerLastQuery = '';
    const box = document.getElementById('customerModalResults');
    if(box) box.innerHTML = '<div class="mg-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
  }
  function renderCustomerSearchModal(term){
    const box = document.getElementById('customerModalResults');
    const input = document.getElementById('customerModalSearch');
    if(!box) return;
    const query = String(term || '').trim();
    modalCustomerLastQuery = query;
    modalCustomers = query ? customerSearchRows(query) : [];
    modalCustomerActiveIndex = modalCustomers.length ? 0 : -1;
    if(!query){
      box.innerHTML = '<div class="mg-empty">Digite um termo para pesquisar. A lista começa vazia.</div>';
      return;
    }
    if(!modalCustomers.length){
      box.innerHTML = '<div class="mg-empty">Nenhum cliente encontrado. Digite outra pesquisa ou cadastre um novo cliente.</div>';
      setTimeout(() => { input?.focus(); input?.select?.(); }, 0);
      return;
    }
    box.innerHTML = modalCustomers.map((c, index) => {
      const doc = c.document ? 'Documento: ' + escapeHtml(c.document) : '';
      const phone = (c.whatsapp || c.phone) ? 'Contato: ' + escapeHtml(c.whatsapp || c.phone) : '';
      const city = (c.city || c.state) ? escapeHtml([c.city || '', c.state || ''].filter(Boolean).join('/')) : '';
      const activeClass = index === modalCustomerActiveIndex ? ' is-active' : '';
      return `<button type="button" class="pdv-client-result${activeClass}" data-customer-modal-row="${index}" data-customer-id="${Number(c.id)}"><span><strong>${escapeHtml(c.name || 'Cliente')}</strong><small>${[doc, phone, city].filter(Boolean).join(' • ') || 'Cliente cadastrado'}</small></span><b>Selecionar</b></button>`;
    }).join('');
  }
  function updateCustomerModalActiveRow(nextIndex, {scroll=true, wrap=true}={}){
    const total = modalCustomers.length;
    if(!total){ modalCustomerActiveIndex = -1; return; }
    let index = Number(nextIndex);
    if(wrap) index = ((index % total) + total) % total;
    else index = Math.max(0, Math.min(total - 1, index));
    modalCustomerActiveIndex = index;
    const rows = document.querySelectorAll('#customerModalResults [data-customer-modal-row]');
    rows.forEach((row, rowIndex) => row.classList.toggle('is-active', rowIndex === index));
    if(scroll) rows[index]?.scrollIntoView({block:'nearest'});
  }
  function selectCustomerFromSearchModal(customerId){
    const c = modalCustomers.find(item => Number(item.id) === Number(customerId)) || customers.find(item => Number(item.id) === Number(customerId));
    if(!c) return false;
    closeModal('customerSearchModal');
    selectCustomer(c);
    showStep('cliente');
    setTimeout(() => document.querySelector('[data-next="produtos"]')?.focus(), 0);
    return true;
  }
  function selectActiveCustomerFromModal(){
    if(modalCustomerActiveIndex < 0 || !modalCustomers[modalCustomerActiveIndex]) return false;
    return selectCustomerFromSearchModal(modalCustomers[modalCustomerActiveIndex].id);
  }
  function renderInlineCustomerList(term){
    const box = document.getElementById('mobileCustomerList');
    if(!box) return;
    const raw = String(term || '').trim();
    if(!raw){
      box.innerHTML = '';
      return;
    }
    const rows = customerSearchRows(raw).slice(0, 20);
    if(!rows.length){
      box.innerHTML = '<div class="mg-empty">Nenhum cliente encontrado.</div>';
      return;
    }
    box.innerHTML = rows.map(c => {
      const doc = c.document ? 'CPF/CNPJ: ' + escapeHtml(c.document) : '';
      const phone = (c.whatsapp || c.phone) ? 'Contato: ' + escapeHtml(c.whatsapp || c.phone) : '';
      const district = c.district ? 'Bairro: ' + escapeHtml(c.district) : '';
      return `<button type="button" class="pdv-mobile-client-card" data-customer-id="${Number(c.id)}"><span><strong>${escapeHtml(c.name || 'Cliente')}</strong><small>${[doc, phone, district].filter(Boolean).join(' • ') || 'Cliente cadastrado'}</small></span><em>Selecionar</em></button>`;
    }).join('');
  }

  function openCustomerSearchModal(){
    const input = document.getElementById('customerModalSearch');
    if(input) input.value = '';
    resetCustomerSearchModal();
    openModal('customerSearchModal');
    setTimeout(() => input?.focus(), 80);
  }
  function doCustomerSearch(){
    const term = document.getElementById('customerLookup')?.value || '';
    if(isMobilePdv){ renderInlineCustomerList(term); return; }
    openCustomerSearchModal();
  }
  function prepareCustomerModal(){
    const d = onlyDigits(document.getElementById('customerLookup').value);
    document.getElementById('customerQuickForm').reset();
    document.getElementById('quickPersonType').value = d.length > 11 ? 'JURIDICA' : 'FISICA';
    document.getElementById('quickDocumentLabel').childNodes[0].nodeValue = d.length > 11 ? 'CNPJ' : 'CPF';
    setVal('quickIeStatus', d.length > 11 ? 'AUTO' : 'NAO_CONTRIBUINTE');
    if(d.length >= 11) document.getElementById('quickDocument').value = d; else document.getElementById('quickWhatsapp').value = d;
    syncQuickIeFields();
    openModal('customerModal');
  }
  async function fillCep(prefix){
    const zip = onlyDigits(document.getElementById(prefix+'Zip')?.value || ''); if(zip.length !== 8) return;
    try{
      const r = await fetch('https://viacep.com.br/ws/'+zip+'/json/'); const data = await r.json(); if(data.erro) return;
      setVal(prefix+'Address', data.logradouro || ''); setVal(prefix+'District', data.bairro || ''); setVal(prefix+'City', data.localidade || ''); setVal(prefix+'State', data.uf || '');
      if(prefix === 'quick') setVal('quickCityIbge', onlyDigits(data.ibge || '').slice(0, 7));
      if(prefix === 'delivery') { setVal('orderCity', data.localidade || ''); setVal('orderState', data.uf || ''); syncAddressHidden(true); }
    }catch(e){}
  }
  function syncQuickIeFields(){
    const status = document.getElementById('quickIeStatus');
    const ie = document.getElementById('quickStateRegistration');
    if(!status || !ie) return;
    if(status.value === 'ISENTO'){ ie.value = 'ISENTO'; ie.disabled = true; }
    else if(status.value === 'NAO_CONTRIBUINTE'){ ie.value = ''; ie.disabled = true; }
    else { ie.disabled = false; if(ie.value.toUpperCase() === 'ISENTO') ie.value = ''; }
  }
  async function fillCnpj(){
    const doc = onlyDigits(document.getElementById('quickDocument').value); if(doc.length !== 14) return;
    try{
      const r = await fetch('pessoa_cnpj_lookup.php?cnpj='+encodeURIComponent(doc), {headers:{'Accept':'application/json'}});
      const payload = await r.json(); if(!r.ok || !payload.ok) return; const data = payload.data || {};
      setVal('quickName', data.razao_social || ''); setVal('quickFantasy', data.nome_fantasia || ''); setVal('quickEmail', data.email || ''); setVal('quickPhone', onlyDigits(data.telefone || ''));
      setVal('quickZip', onlyDigits(data.cep || '')); setVal('quickAddress', data.logradouro || ''); setVal('quickNumber', data.numero || ''); setVal('quickComplement', data.complemento || ''); setVal('quickDistrict', data.bairro || ''); setVal('quickCity', data.municipio || ''); setVal('quickState', data.uf || '');
      setVal('quickMunicipalRegistration', data.municipal_registration || '');
      const ie = String(data.state_registration || '').trim();
      if(ie){ setVal('quickStateRegistration', ie); setVal('quickIeStatus', 'CONTRIBUINTE'); }
      const cnpjIbge = onlyDigits(data.city_ibge || '').slice(0, 7);
      if(cnpjIbge) setVal('quickCityIbge', cnpjIbge); else if(onlyDigits(data.cep || '').length === 8) await fillCep('quick');
      syncQuickIeFields();
    }catch(e){}
  }

  if(searchMode){
    try {
      const storedMode = localStorage.getItem(searchModeStorageKey);
      if(storedMode && validSearchModes.includes(storedMode)) searchMode.value = storedMode;
    } catch(e) {}
  }
  updateProductSearchPlaceholder();
  updatePreparedQuantity();

  list.addEventListener('click', e => { const b=e.target.closest('[data-id]'); if(b) openOptions(Number(b.dataset.id), pendingProductQuantity); });
  cats.addEventListener('click', e => { const b=e.target.closest('.pdv-cat'); if(!b) return; cats.querySelectorAll('.pdv-cat').forEach(x=>x.classList.remove('active')); b.classList.add('active'); currentCat = b.dataset.cat; loadProducts({cat: currentCat, q: search?.value || ''}); });
  search.addEventListener('input', () => {
    if(armProductMultiplier()){ renderProducts(); return; }
    clearTimeout(productSearchTimer);
    productSearchTimer = setTimeout(() => loadProducts({q: search.value, cat: currentCat}), 260);
  });
  search.addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); if(armProductMultiplier()) return; clearTimeout(productSearchTimer); loadProducts({q: search.value, cat: currentCat, openFirst:true}); } });
  document.getElementById('pdvProductSearchBtn')?.addEventListener('click', () => {
    if(isMobilePdv) loadProducts({q: search?.value || '', cat: currentCat, openFirst:true});
    else openProductSearchModal();
  });
  document.getElementById('productModalSearchBtn')?.addEventListener('click', searchProductsInModal);
  document.getElementById('productModalSearch')?.addEventListener('input', () => {
    modalProductActiveIndex = -1;
    document.querySelectorAll('#productModalResults [data-product-modal-row]').forEach(row => row.classList.remove('is-active'));
  });
  document.getElementById('productModalSearch')?.addEventListener('keydown', event => {
    if(event.key === 'ArrowDown' && modalProducts.length){
      event.preventDefault();
      updateProductModalActiveRow(modalProductActiveIndex < 0 ? 0 : modalProductActiveIndex + 1);
      return;
    }
    if(event.key === 'ArrowUp' && modalProducts.length){
      event.preventDefault();
      updateProductModalActiveRow(modalProductActiveIndex < 0 ? modalProducts.length - 1 : modalProductActiveIndex - 1);
      return;
    }
    if(event.key === 'Enter'){
      event.preventDefault();
      const currentQuery = String(event.currentTarget.value || '').trim();
      if(modalProducts.length && modalProductActiveIndex >= 0 && currentQuery === modalProductLastQuery){
        selectActiveProductFromModal();
      }else{
        searchProductsInModal();
      }
      return;
    }
    if(event.key === 'Escape'){
      event.preventDefault();
      closeModal('productSearchModal');
      setTimeout(() => search?.focus(), 0);
    }
  });
  document.getElementById('productModalSearchMode')?.addEventListener('change', event => {
    const mode = String(event.target.value || 'instant');
    if(searchMode && validSearchModes.includes(mode)) searchMode.value = mode;
    try { localStorage.setItem(searchModeStorageKey, mode); } catch(e) {}
    const modalInput = document.getElementById('productModalSearch');
    if(modalInput) modalInput.value = '';
    resetProductSearchModal();
    setTimeout(() => modalInput?.focus(), 0);
  });
  document.getElementById('productModalResults')?.addEventListener('mousemove', event => {
    const row = event.target.closest('[data-product-modal-row]');
    if(row) updateProductModalActiveRow(Number(row.dataset.productModalRow), {scroll:false, wrap:false});
  });
  document.getElementById('productModalResults')?.addEventListener('click', event => {
    const target = event.target.closest('[data-product-modal-id]');
    if(target) selectProductFromModal(Number(target.dataset.productModalId));
  });
  preparedQty?.addEventListener('click', clearProductMultiplier);
  searchMode?.addEventListener('change', () => {
    try { localStorage.setItem(searchModeStorageKey, currentProductSearchMode()); } catch(e) {}
    updateProductSearchPlaceholder();
    search.value = '';
    products = []; pizzas = []; productOptions = {}; productsLoaded = false;
    renderProducts();
    setTimeout(() => search.focus(), 0);
  });
  cartBox.addEventListener('click', async e => {
    const row = e.target.closest('[data-checkout-cart-key]');
    if(row && isCheckoutPdv){ checkoutSelectedCartKey = row.dataset.checkoutCartKey || null; }
    const inc=e.target.closest('[data-inc]'), dec=e.target.closest('[data-dec]'), del=e.target.closest('[data-del]');
    const id = inc?.dataset.inc || dec?.dataset.dec || del?.dataset.del;
    if(!id){ if(row && isCheckoutPdv) renderCart(); return; }
    const it=cart.get(id); if(!it) return;
    checkoutSelectedCartKey = id;
    if(del && isCheckoutPdv && it.sourceType==='os'){
      e.preventDefault();
      await checkoutRemoveOsSource(Number(it.sourceId||0), true);
      checkoutFocusSearch();
      return;
    }
    if(inc) it.qty += 1;
    if(dec) it.qty = Math.max(0, it.qty-1);
    if(del || it.qty<=0){ cart.delete(id); if(checkoutLastCartKey===id) checkoutLastCartKey=null; }
    else cart.set(id, it);
    renderCart();
  });
  cartBox.addEventListener('input', e => { const q=e.target.closest('[data-qty]'), o=e.target.closest('[data-obs]'); if(q){ const id=q.dataset.qty; const it=cart.get(id); if(it){it.qty=Math.max(0, parseMoney(q.value)); if(it.qty<=0) cart.delete(id); else cart.set(id,it); renderCart();}} if(o){ const id=o.dataset.obs; const it=cart.get(id); if(it){it.obs=o.value; cart.set(id,it); syncCartHidden();}} });
  document.getElementById('discountPercent')?.addEventListener('input', () => { syncCheckoutAdjustmentPair('discount','percent'); updateTotals(); });
  document.getElementById('discountAmount')?.addEventListener('input', () => { syncCheckoutAdjustmentPair('discount','amount'); updateTotals(); });
  document.getElementById('additionPercent')?.addEventListener('input', () => { syncCheckoutAdjustmentPair('addition','percent'); updateTotals(); });
  document.getElementById('additionAmount')?.addEventListener('input', () => { syncCheckoutAdjustmentPair('addition','amount'); updateTotals(); });
  document.getElementById('deliveryFee').addEventListener('input', e => { e.target.dataset.manual='1'; updateTotals(); });
  document.getElementById('orderType').addEventListener('change', () => syncAddressHidden(true));
  ['deliveryZip','deliveryDistrict','deliveryAddress','deliveryNumber','deliveryComplement','deliveryReference'].forEach(id=>{ const el=document.getElementById(id); if(el) { el.addEventListener('input', () => syncAddressHidden(true)); el.addEventListener('change', () => syncAddressHidden(true)); } });
  document.getElementById('deliveryZip').addEventListener('blur', () => fillCep('delivery'));
  document.getElementById('customerSearchBtn').addEventListener('click', doCustomerSearch);
  document.getElementById('customerLookup').addEventListener('keydown', e => { if(e.key === 'Enter'){ e.preventDefault(); doCustomerSearch(); } });
  document.getElementById('customerLookup').addEventListener('input', () => { const lookup=document.getElementById('customerLookup'); renderInlineCustomerList(lookup.value); if(isMobilePdv) return; const d=onlyDigits(lookup.value); const txt=normalize(lookup.value); if(d.length === 0 && txt.length < 2) return; const c=findCustomerByLookup(lookup.value); if(c && (d.length >= 8 || txt.length >= 4)) selectCustomer(c); });

  document.getElementById('customerModalSearchBtn')?.addEventListener('click', () => renderCustomerSearchModal(document.getElementById('customerModalSearch')?.value || ''));
  document.getElementById('customerModalSearch')?.addEventListener('input', () => {
    modalCustomerActiveIndex = -1;
    document.querySelectorAll('#customerModalResults [data-customer-modal-row]').forEach(row => row.classList.remove('is-active'));
  });
  document.getElementById('customerModalSearch')?.addEventListener('keydown', event => {
    const currentQuery = String(event.currentTarget.value || '').trim();
    const sameSearch = currentQuery === modalCustomerLastQuery;
    if(event.key === 'ArrowDown' && modalCustomers.length && sameSearch){
      event.preventDefault();
      updateCustomerModalActiveRow(modalCustomerActiveIndex < 0 ? 0 : modalCustomerActiveIndex + 1);
      return;
    }
    if(event.key === 'ArrowUp' && modalCustomers.length && sameSearch){
      event.preventDefault();
      updateCustomerModalActiveRow(modalCustomerActiveIndex < 0 ? modalCustomers.length - 1 : modalCustomerActiveIndex - 1);
      return;
    }
    if(event.key === 'Enter'){
      event.preventDefault();
      if(modalCustomers.length && modalCustomerActiveIndex >= 0 && sameSearch){
        selectActiveCustomerFromModal();
      }else{
        renderCustomerSearchModal(currentQuery);
      }
      return;
    }
    if(event.key === 'Escape'){
      event.preventDefault();
      closeModal('customerSearchModal');
      setTimeout(() => document.getElementById('customerLookup')?.focus(), 0);
    }
  });
  document.getElementById('customerModalResults')?.addEventListener('mousemove', event => {
    const row = event.target.closest('[data-customer-modal-row]');
    if(row) updateCustomerModalActiveRow(Number(row.dataset.customerModalRow), {scroll:false, wrap:false});
  });
  document.getElementById('customerModalResults')?.addEventListener('click', event => {
    const target = event.target.closest('[data-customer-id]');
    if(target) selectCustomerFromSearchModal(Number(target.dataset.customerId));
  });
  document.getElementById('mobileCustomerList')?.addEventListener('click', e => { const b=e.target.closest('[data-customer-id]'); if(!b) return; const c=customers.find(x=>Number(x.id)===Number(b.dataset.customerId)); if(c){ selectCustomer(c); } });
  document.getElementById('customerModalNewBtn')?.addEventListener('click', () => { const term=document.getElementById('customerModalSearch')?.value || ''; closeModal('customerSearchModal'); if(document.getElementById('customerLookup')) document.getElementById('customerLookup').value=term; prepareCustomerModal(); });
  document.getElementById('useFinalConsumerBtn').addEventListener('click', () => setConsumerFinal(true));
  document.getElementById('openCustomerModalBtn').addEventListener('click', prepareCustomerModal);
  document.getElementById('openAddressModalBtn').addEventListener('click', () => { if(!selectedCustomer) return; document.getElementById('addressQuickForm').reset(); document.getElementById('addressPersonId').value = selectedCustomer.id; openModal('addressModal'); });
  document.getElementById('customerAddressSelect').addEventListener('change', e => { const id=e.target.value; const a=customerAddresses.find(x=>String(x.id)===String(id)); if(a) applyAddress(a); else { setVal('orderCustomerAddressId',''); syncAddressHidden(true); } });
  document.getElementById('customerWhatsappInput').addEventListener('input', e => setVal('customerWhatsapp', onlyDigits(e.target.value)));
  document.getElementById('finalConsumerNameInput').addEventListener('input', e => { if(!selectedCustomer){ setVal('customerName', e.target.value.trim() || 'Consumidor final'); updateTotals(); }});
  document.getElementById('quickPersonType').addEventListener('change', e => {
    document.getElementById('quickDocumentLabel').childNodes[0].nodeValue = e.target.value === 'JURIDICA' ? 'CNPJ' : 'CPF';
    const st=document.getElementById('quickIeStatus'), ie=document.getElementById('quickStateRegistration');
    if(st && ie && !ie.value){ st.value=e.target.value === 'JURIDICA' ? 'AUTO' : 'NAO_CONTRIBUINTE'; syncQuickIeFields(); }
  });
  document.getElementById('quickIeStatus')?.addEventListener('change', syncQuickIeFields);
  document.getElementById('quickDocument').addEventListener('blur', fillCnpj);
  document.getElementById('quickZip').addEventListener('blur', () => fillCep('quick'));
  document.getElementById('addrZip').addEventListener('blur', () => fillCep('addr'));
  document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', () => {
    const id = b.dataset.closeModal;
    closeModal(id);
    if(id === 'productSearchModal' || id === 'checkoutValueProductModal') checkoutProductValueTarget = 0;
  }));
  document.getElementById('checkoutValueContinue')?.addEventListener('click', checkoutContinueValueProduct);
  document.getElementById('checkoutValueAmount')?.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); checkoutContinueValueProduct(); } });
  document.getElementById('checkoutComandaSearchBtn')?.addEventListener('click', () => checkoutSearchComandas(document.getElementById('checkoutComandaQuery')?.value || ''));
  document.getElementById('checkoutComandaQuery')?.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); checkoutSearchComandas(e.currentTarget.value || ''); } else if(e.key==='Escape'){ e.preventDefault(); closeModal('checkoutComandaReaderModal'); } });
  document.getElementById('checkoutComandaResults')?.addEventListener('change', e => { const cb=e.target.closest('[data-checkout-comanda-id]'); if(!cb) return; const id=Number(cb.dataset.checkoutComandaId||0); if(cb.checked) checkoutComandaSelected.add(id); else checkoutComandaSelected.delete(id); cb.closest('.checkout-reader-row')?.classList.toggle('is-selected', cb.checked); checkoutUpdateComandaSelectedCount(); });
  document.getElementById('checkoutComandaClearBtn')?.addEventListener('click', () => { checkoutComandaSelected.clear(); checkoutUpdateComandaSelectedCount(); document.querySelectorAll('[data-checkout-comanda-id]').forEach(cb=>{cb.checked=false;cb.closest('.checkout-reader-row')?.classList.remove('is-selected');}); document.getElementById('checkoutComandaQuery')?.focus(); });
  document.getElementById('checkoutComandaImportBtn')?.addEventListener('click', checkoutImportComandas);
  document.getElementById('checkoutOrcamentoSearchBtn')?.addEventListener('click', () => checkoutSearchOrcamentos(document.getElementById('checkoutOrcamentoQuery')?.value || ''));
  document.getElementById('checkoutOrcamentoQuery')?.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); checkoutSearchOrcamentos(e.currentTarget.value || ''); } else if(e.key==='Escape'){ e.preventDefault(); closeModal('checkoutOrcamentoReaderModal'); } });
  document.getElementById('checkoutOrcamentoResults')?.addEventListener('change', e => { const cb=e.target.closest('[data-checkout-orcamento-id]'); if(!cb) return; const id=Number(cb.dataset.checkoutOrcamentoId||0); if(cb.checked) checkoutOrcamentoSelected.add(id); else checkoutOrcamentoSelected.delete(id); cb.closest('.checkout-reader-row')?.classList.toggle('is-selected', cb.checked); checkoutUpdateOrcamentoSelectedCount(); });
  document.getElementById('checkoutOrcamentoClearBtn')?.addEventListener('click', () => { checkoutOrcamentoSelected.clear(); checkoutUpdateOrcamentoSelectedCount(); document.querySelectorAll('[data-checkout-orcamento-id]').forEach(cb=>{cb.checked=false;cb.closest('.checkout-reader-row')?.classList.remove('is-selected');}); document.getElementById('checkoutOrcamentoQuery')?.focus(); });
  document.getElementById('checkoutOrcamentoImportBtn')?.addEventListener('click', checkoutImportOrcamentos);
  document.getElementById('checkoutOsSearchBtn')?.addEventListener('click', () => checkoutSearchOs(document.getElementById('checkoutOsQuery')?.value || ''));
  document.getElementById('checkoutOsQuery')?.addEventListener('keydown', e => { if(e.key==='Enter'){ e.preventDefault(); checkoutSearchOs(e.currentTarget.value || ''); } else if(e.key==='Escape'){ e.preventDefault(); closeModal('checkoutOsReaderModal'); } });
  document.getElementById('checkoutOsResults')?.addEventListener('change', e => { const cb=e.target.closest('[data-checkout-os-id]'); if(!cb)return; const id=Number(cb.dataset.checkoutOsId||0); if(cb.checked)checkoutOsSelected.add(id);else checkoutOsSelected.delete(id);cb.closest('.checkout-reader-row')?.classList.toggle('is-selected',cb.checked);checkoutUpdateOsSelectedCount(); });
  document.getElementById('checkoutOsClearBtn')?.addEventListener('click', () => {checkoutOsSelected.clear();checkoutUpdateOsSelectedCount();document.querySelectorAll('[data-checkout-os-id]').forEach(cb=>{cb.checked=false;cb.closest('.checkout-reader-row')?.classList.remove('is-selected');});document.getElementById('checkoutOsQuery')?.focus();});
  document.getElementById('checkoutOsImportBtn')?.addEventListener('click', checkoutImportOs);

  document.getElementById('customerQuickForm').addEventListener('submit', async function(e){
    e.preventDefault();
    try{
      const fd = new FormData(this); const r = await fetch(location.href, {method:'POST', body:fd}); const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'Falha ao cadastrar cliente.');
      const c = data.customer; const idx = customers.findIndex(x=>Number(x.id)===Number(c.id)); if(idx >= 0) customers[idx]=c; else customers.push(c);
      customerAddresses = customerAddresses.filter(a => Number(a.person_id) !== Number(c.id)).concat(data.addresses || []);
      closeModal('customerModal'); selectCustomer(c); showStep('cliente');
    }catch(err){ checkoutNotice(String(err?.message || err || 'Não foi possível concluir a operação.'),'Atenção','danger'); }
  });
  document.getElementById('addressQuickForm').addEventListener('submit', async function(e){
    e.preventDefault(); if(!selectedCustomer) return;
    try{
      const fd = new FormData(this); const r = await fetch(location.href, {method:'POST', body:fd}); const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'Falha ao cadastrar endereço.');
      customerAddresses = customerAddresses.filter(a => Number(a.person_id) !== Number(selectedCustomer.id)).concat(data.addresses || []);
      closeModal('addressModal'); renderAddressSelect();
      if(data.address_id){ document.getElementById('customerAddressSelect').value = String(data.address_id); const a=customerAddresses.find(x=>Number(x.id)===Number(data.address_id)); if(a) applyAddress(a); }
    }catch(err){ checkoutNotice(String(err?.message || err || 'Não foi possível concluir a operação.'),'Atenção','danger'); }
  });

  document.getElementById('addPaymentBtn').addEventListener('click', () => addPaymentLine(undefined, false, true));
  document.getElementById('paymentShortcuts')?.addEventListener('click', e => {
    const btn = e.target.closest('[data-payment-shortcut]');
    if(btn) selectPaymentMethod(btn.dataset.paymentShortcut || '');
  });
  document.getElementById('paymentLines').addEventListener('focusin', e => {
    const line = e.target.closest('.pdv-payment-line');
    if(line) setActivePaymentLine(line);
  });
  document.getElementById('paymentLines').addEventListener('click', e => { const gen=e.target.closest('.pay-installments-generate'); if(gen){ e.preventDefault(); const line = gen.closest('.pdv-payment-line'); if(line){ renderInstallments(line, true); updateTotals(); } return; } const b=e.target.closest('.pay-remove'); if(b){ e.preventDefault(); removePaymentLine(b.closest('.pdv-payment-line')); }});
  document.getElementById('paymentLines').addEventListener('input', e => { if(e.target && e.target.classList.contains('pay-amount')){ const line=e.target.closest('.pdv-payment-line'); checkoutPixResetLine(line); e.target.dataset.autoSync = '0'; renderInstallments(line, true); } updateTotals(); });
  document.getElementById('paymentLines').addEventListener('change', e => {
    if(e.target && (e.target.classList.contains('pay-method') || e.target.classList.contains('installment-count'))){
      const line = e.target.closest('.pdv-payment-line');
      if(e.target.classList.contains('pay-method')){
        checkoutPixResetLine(line);
        const cnt = line?.querySelector('.installment-count');
        if(cnt) cnt.value = '';
        if(e.target.value) focusPaymentAmount(line);
      }
      renderInstallments(line, true);
    }
    updateTotals();
  });
  document.getElementById('optGroups').addEventListener('change', updateOptionsTotal);
  document.getElementById('optHalfBox').addEventListener('change', updateOptionsTotal);
  document.getElementById('optCancel').addEventListener('click', closeOptions);
  document.getElementById('optCancelBottom')?.addEventListener('click', closeOptions);
  document.getElementById('optConfirm').addEventListener('click', confirmOptions);
  document.getElementById('pdvOptionModal').addEventListener('click', e => { if(e.target.id === 'pdvOptionModal') closeOptions(); });
  document.getElementById('customerModal').addEventListener('click', e => { if(e.target.id === 'customerModal') closeModal('customerModal'); });
  document.getElementById('addressModal').addEventListener('click', e => { if(e.target.id === 'addressModal') closeModal('addressModal'); });
  document.querySelectorAll('.pdv-step-nav').forEach(btn => btn.addEventListener('click', () => { const step = btn.dataset.step; if(canEnterStep(step)) showStep(step); }));
  document.querySelectorAll('.pdv-next').forEach(btn => btn.addEventListener('click', () => { const step = btn.dataset.next; if(canEnterStep(step)) showStep(step); }));
  document.querySelectorAll('.pdv-prev').forEach(btn => btn.addEventListener('click', () => showStep(btn.dataset.prev)));
  document.getElementById('pdvMobileFloatingNext')?.addEventListener('click', () => { const step = document.getElementById('pdvMobileFloatingNext')?.dataset.next || nextStepFor(currentStepName()); if(canEnterStep(step)) showStep(step); });
  const saleFinalizationBlocked = <?= $saleFinalizationBlocked ? 'true' : 'false' ?>;
  function creditSaleAmount(){ let total=0; document.querySelectorAll('.pdv-payment-line').forEach(line=>{const id=Number(line.querySelector('.pay-method')?.value||0); if(id && paymentMap[id]?.receivable) total += parseMoney(line.querySelector('.pay-amount')?.value||'0');}); return Math.round(total*100)/100; }
  function creditNeedsAuthorization(){
    if(!selectedCustomer) return null; const amount=creditSaleAmount(); if(amount<=0) return null;
    const limit=selectedCustomer.credit_limit; if(limit===null || typeof limit==='undefined') return null;
    const open=Number(selectedCustomer.credit_open||0); const projected=open+amount; if(projected<=Number(limit)+0.009) return null;
    return {limit:Number(limit),open,amount,available:Math.max(0,Number(limit)-open)};
  }
  function showCreditOverride(info){
    const m=document.getElementById('creditOverrideModal'); if(!m) return; document.getElementById('creditOverrideMessage').textContent=`Limite: ${money(info.limit)} · Em aberto: ${money(info.open)} · Disponível: ${money(info.available)} · Nova compra a prazo: ${money(info.amount)}.`; m.style.display='flex'; setTimeout(()=>document.getElementById('creditOverrideCodeInput')?.focus(),60);
  }
  function closeCreditOverride(){const m=document.getElementById('creditOverrideModal');if(m)m.style.display='none';}
  document.getElementById('creditOverrideClose')?.addEventListener('click',closeCreditOverride); document.getElementById('creditOverrideCancel')?.addEventListener('click',closeCreditOverride);
  document.getElementById('creditOverrideConfirm')?.addEventListener('click',async()=>{const c=document.getElementById('creditOverrideCodeInput')?.value.trim()||'',p=document.getElementById('creditOverridePasswordInput')?.value||'';if(!c||!p){checkoutNotice('Informe o código do usuário e a senha.','Autorização de crédito','warning');return;}const b=document.getElementById('creditOverrideConfirm');if(b){b.disabled=true;b.textContent='Validando...';}try{const fd=new FormData();fd.append('code',c);fd.append('password',p);fd.append('mc_csrf','<?= gestao_h($pdvMcCsrf) ?>');fd.append('mc_ctx','<?= gestao_h($pdvMcCtx) ?>');const r=await fetch('<?= gestao_h($pdvContextUrl('commercial_credit_authorize.php')) ?>',{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw new Error(j.error||'Não foi possível validar a autorização.');setVal('creditOverrideUserCode',c);setVal('creditOverridePassword',p);creditOverrideConfirmed=true;closeCreditOverride();document.getElementById('pdvForm')?.requestSubmit();}catch(err){checkoutNotice(err.message||'Falha ao validar autorização.','Autorização de crédito','danger');}finally{if(b){b.disabled=false;b.textContent='Liberar venda';}}});
  document.getElementById('pdvForm').addEventListener('submit', function(e){
    if(!window.NexaDeliveryFutureConfirmed){const future=Array.from(cart.values()).filter(it=>Number(it.futureQty||0)>0.00001);if(future.length){e.preventDefault();const lines=future.map(it=>{const f=Number(it.futureQty||0),q=Number(it.qty||0);return '• '+String(it.name||'Item')+' — agora '+Math.max(0,q-f).toLocaleString('pt-BR',{maximumFractionDigits:4})+' / futuro '+f.toLocaleString('pt-BR',{maximumFractionDigits:4})});checkoutConfirm('Itens para retirada imediata e entrega futura\n\n'+lines.join('\n')+'\n\nO saldo futuro continuará reservado. Confirma a finalização?','Confirmar Controle de Entregas','warning','Confirmar').then(ok=>{if(ok){window.NexaDeliveryFutureConfirmed=true;document.getElementById('pdvForm')?.requestSubmit();}});return;}}
    if(saleFinalizationBlocked){ e.preventDefault(); checkoutNotice('Este perfil não pode finalizar a venda.','Finalização bloqueada','warning'); return; }
    if(!selectedCustomer){ setVal('customerName', document.getElementById('finalConsumerNameInput')?.value.trim() || 'Consumidor final'); }
    // V11.45.19.17.1: no fechamento preserve frete informado/importado manualmente.
    // syncAddressHidden(false) ainda recalcula a taxa automática por bairro quando
    // o campo não está marcado como manual, mas não sobrescreve o frete vindo do
    // Orçamento. Para NFC-e, o motor fiscal converte taxa_entrega para vOutro
    // (acréscimo/outras despesas), mantendo vFrete=0 no modelo 65.
    syncAddressHidden(false);
    if(cart.size === 0){ e.preventDefault(); checkoutNotice('Inclua ao menos um produto.','Carrinho vazio','warning'); showStep('produtos'); return; }
    const total = orderTotal(); let sum = 0; let hasMethod = true;
    let installmentOk = true;
    document.querySelectorAll('.pdv-payment-line').forEach(line => {
      if(!line.querySelector('.pay-method').value) hasMethod = false;
      const lineAmount = parseMoney(line.querySelector('.pay-amount').value);
      sum += lineAmount;
      if(line.querySelector('.pdv-installments.show')){
        let parcSum = 0; let missingDue = false;
        line.querySelectorAll('.pdv-installments .pdv-installment-row:not(.head)').forEach(tr => { const due = tr.querySelector('.installment-due')?.value || ''; const val = parseMoney(tr.querySelector('.installment-amount')?.value || '0'); if(!due) missingDue = true; parcSum += val; });
        if(missingDue || Math.abs(parcSum - lineAmount) > 0.01) installmentOk = false;
      }
    });
    if(!hasMethod){ e.preventDefault(); checkoutNotice('Selecione todas as formas de pagamento.','Pagamento','warning'); return; }
    if(!requireCustomerForCreditSale()){ e.preventDefault(); return; }
    if(!installmentOk){ e.preventDefault(); checkoutNotice('Confira as parcelas: vencimentos obrigatórios e soma dos valores igual ao valor da forma de pagamento.','Parcelamento','warning'); return; }
    if(Math.abs(total - sum) > 0.01){ e.preventDefault(); checkoutNotice('A soma das formas de pagamento precisa fechar o total do pedido.','Pagamento','warning'); return; }
    if(commercialMaxDiscountPercent !== null){const sub=cartSubtotal();const disc=parseMoney(document.getElementById('discountAmount')?.value||'0');const max=sub*Number(commercialMaxDiscountPercent)/100;if(disc>max+0.009){e.preventDefault();checkoutNotice(`Desconto acima do limite do usuário (${Number(commercialMaxDiscountPercent).toFixed(2).replace('.',',')}%). Máximo: ${money(max)}.`,'Limite de desconto','warning');showStep('carrinho');return;}}
    const creditInfo=creditNeedsAuthorization(); if(creditInfo && !creditOverrideConfirmed){e.preventDefault();showCreditOverride(creditInfo);return;}
    if(document.getElementById('orderType').value === 'entrega' && !document.getElementById('orderDistrict').value.trim()){
      if(!checkoutDeliveryNoDistrictConfirmed){
        e.preventDefault();
        checkoutConfirm('O pedido está marcado como entrega, mas o bairro não foi informado. Deseja continuar mesmo assim?','Entrega sem bairro','warning','Continuar').then(ok => {
          if(ok){ checkoutDeliveryNoDistrictConfirmed = true; document.getElementById('pdvForm')?.requestSubmit(); }
          else showStep(stepOrder.includes('endereco') ? 'endereco' : 'cliente');
        });
        return;
      }
      checkoutDeliveryNoDistrictConfirmed = false;
    }
    const pendingPixLine = checkoutPixPendingLine();
    if(pendingPixLine){
      e.preventDefault();
      checkoutPixStart(pendingPixLine);
      return;
    }
    if(checkoutHasOsItems()){
      setVal('emitDocAfter','none');
      if(!checkoutOsFiscalChoiceConfirmed){
        e.preventDefault();
        checkoutOpenOsFiscalChoice();
        return;
      }
    }else if(fiscalEmissionEnabled && !fiscalChoiceConfirmed){
      e.preventDefault();
      openModal('pdvFiscalModal');
      return;
    }
    if(window.adminShowProcessing){
      const emit = document.getElementById('emitDocAfter')?.value || '';
      window.adminShowProcessing(emit === 'nfe' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Montando XML da NF-e...', 'Assinando documento fiscal...', 'Transmitindo para a SEFAZ...', 'Registrando retorno da autorização...', 'Preparando DANFE para impressão...'] : (emit === 'nfce' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Montando XML da NFC-e...', 'Assinando cupom fiscal eletrônico...', 'Transmitindo para a SEFAZ...', 'Registrando retorno da autorização...', 'Preparando DANFE NFC-e para impressão...'] : (emit === 'nfse' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Gerando e assinando a DPS...', 'Transmitindo a NFS-e...', 'Registrando autorização...', 'Preparando documento para visualização...'] : ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Atualizando estoque e financeiro...', 'Finalizando retorno para o PDV...'])));
    }
  });

  function checkoutFocusSearch(){
    showStep('produtos');
    setTimeout(() => { search?.focus(); search?.select?.(); }, 40);
  }
  let checkoutActionResolver = null;
  let checkoutActionReturnFocus = null;
  function checkoutActionDialogOpen(){
    const modal = document.getElementById('checkoutActionModal');
    return !!(modal && modal.style.display !== 'none');
  }
  function checkoutActionDialogClose(result=false){
    const modal = document.getElementById('checkoutActionModal');
    if(modal){ modal.style.display='none'; modal.setAttribute('aria-hidden','true'); }
    const resolve = checkoutActionResolver;
    const returnFocus = checkoutActionReturnFocus;
    checkoutActionResolver = null;
    checkoutActionReturnFocus = null;
    if(resolve) resolve(!!result);
    setTimeout(() => {
      if(returnFocus && document.contains(returnFocus) && typeof returnFocus.focus === 'function') returnFocus.focus();
      else if(currentStepName() === 'produtos' && !checkoutLookupModalOpen()) search?.focus();
    }, 30);
  }
  function checkoutDialog(options={}){
    const modal = document.getElementById('checkoutActionModal');
    const dialog = modal?.querySelector('.checkout-action-dialog');
    if(!modal || !dialog) return Promise.resolve(true);
    if(checkoutActionResolver){ const previous=checkoutActionResolver; checkoutActionResolver=null; previous(false); }
    checkoutActionReturnFocus = document.activeElement instanceof HTMLElement ? document.activeElement : null;
    const type = String(options.type || 'info');
    dialog.classList.remove('is-danger','is-warning','is-success');
    if(['danger','warning','success'].includes(type)) dialog.classList.add('is-' + type);
    const icon = document.getElementById('checkoutActionIcon');
    const title = document.getElementById('checkoutActionTitle');
    const message = document.getElementById('checkoutActionMessage');
    const cancel = document.getElementById('checkoutActionCancel');
    const confirm = document.getElementById('checkoutActionConfirm');
    const buttons = dialog.querySelector('.checkout-action-dialog-buttons');
    const showCancel = !!options.showCancel;
    if(icon) icon.textContent = options.icon || (type==='danger'?'!':type==='warning'?'!':type==='success'?'✓':'i');
    if(title) title.textContent = options.title || (type==='danger'?'Confirmar cancelamento':'Atenção');
    if(message) message.textContent = options.message || '';
    if(cancel) cancel.textContent = options.cancelText || 'Voltar';
    if(confirm) confirm.textContent = options.confirmText || (showCancel ? 'Confirmar' : 'OK');
    buttons?.classList.toggle('is-single', !showCancel);
    modal.style.display='flex';
    modal.setAttribute('aria-hidden','false');
    setTimeout(() => (showCancel ? cancel : confirm)?.focus(), 35);
    return new Promise(resolve => { checkoutActionResolver = resolve; });
  }
  function checkoutNotice(message, title='Atenção', type='info'){
    return checkoutDialog({title,message,type,showCancel:false,confirmText:'OK'});
  }
  function checkoutConfirm(message, title='Confirmar operação', type='warning', confirmText='Confirmar'){
    return checkoutDialog({title,message,type,showCancel:true,confirmText,cancelText:'Voltar'});
  }
  document.getElementById('checkoutActionCancel')?.addEventListener('click', () => checkoutActionDialogClose(false));
  document.getElementById('checkoutActionConfirm')?.addEventListener('click', () => checkoutActionDialogClose(true));
  document.getElementById('checkoutActionModal')?.addEventListener('click', e => { if(e.target.id === 'checkoutActionModal') checkoutActionDialogClose(false); });
  document.getElementById('checkoutPixClose')?.addEventListener('click', checkoutPixCloseModal);
  document.getElementById('checkoutPixCancel')?.addEventListener('click', checkoutPixCloseModal);
  document.getElementById('checkoutPixCopy')?.addEventListener('click', checkoutPixCopyCode);
  document.getElementById('checkoutPixVerify')?.addEventListener('click', () => checkoutPixPoll(true));
  document.getElementById('checkoutPixModal')?.addEventListener('click', e => { if(e.target.id === 'checkoutPixModal') checkoutPixCloseModal(); });

  function checkoutOpenCustomer(){
    if(checkoutHasOsItems()){ checkoutNotice('O cliente é definido pela Ordem de Serviço importada. Remova as O.S. para trocar o cliente.','Ordem de Serviço','warning'); return; }
    showStep('produtos');
    openCustomerSearchModal();
  }
  function checkoutOpenProducts(){
    if(checkoutHasOsItems()){ checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de incluir produtos avulsos.','Ordem de Serviço','warning'); return; }
    showStep('produtos');
    openProductSearchModal();
  }
  async function checkoutGetJson(url){
    const response = await fetch(url, {credentials:'same-origin', headers:{'X-Requested-With':'XMLHttpRequest'}});
    const data = await response.json().catch(() => ({}));
    if(!response.ok || data.ok === false) throw new Error(data.error || data.message || 'Não foi possível concluir a consulta.');
    return data;
  }
  function checkoutOpenValueProduct(){
    if(checkoutHasOsItems()){ checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de usar Produto por valor.','Ordem de Serviço','warning'); return; }
    checkoutProductValueTarget = 0;
    const input = document.getElementById('checkoutValueAmount');
    if(input) input.value = '';
    openModal('checkoutValueProductModal');
    setTimeout(() => input?.focus(), 60);
  }
  function checkoutContinueValueProduct(){
    const input = document.getElementById('checkoutValueAmount');
    const value = parseMoney(input?.value || '0');
    if(value <= 0){ checkoutNotice('Informe um valor maior que zero.','Produto por valor','warning').then(()=>input?.focus()); return false; }
    checkoutProductValueTarget = value;
    closeModal('checkoutValueProductModal');
    openProductSearchModal(true);
    return true;
  }
  function checkoutReaderMoney(v){ return money(Number(v || 0)); }
  function checkoutUpdateComandaSelectedCount(){
    const el = document.getElementById('checkoutComandaSelectedCount');
    if(el) el.textContent = checkoutComandaSelected.size + ' comanda(s) selecionada(s)';
  }
  function checkoutUpdateOrcamentoSelectedCount(){
    const el = document.getElementById('checkoutOrcamentoSelectedCount');
    if(el) el.textContent = checkoutOrcamentoSelected.size + ' orçamento(s) selecionado(s)';
  }
  function checkoutComandaRowHtml(r){
    const id = Number(r.id || 0), checked = checkoutComandaSelected.has(id);
    return `<label class="checkout-reader-row${checked?' is-selected':''}" data-checkout-comanda-row="${id}"><input type="checkbox" data-checkout-comanda-id="${id}" ${checked?'checked':''}><div class="checkout-reader-code">${escapeHtml(r.codigo || id)}<small>${escapeHtml(r.matricula || '')}</small></div><div class="checkout-reader-name"><strong>${escapeHtml(r.nome || r.cliente_nome || ('Comanda '+(r.codigo||id)))}</strong><small>${escapeHtml(r.cliente_nome || '')}</small></div><div class="checkout-reader-meta">${Number(r.itens||0)} item(ns)</div><div class="checkout-reader-total">${checkoutReaderMoney(r.total)}</div></label>`;
  }
  async function checkoutSearchComandas(q=''){
    if(!checkoutComandaEnabled) return;
    const box = document.getElementById('checkoutComandaResults');
    if(!box) return;
    box.classList.add('checkout-reader-loading');
    box.innerHTML = '<div class="checkout-reader-empty">Consultando comandas...</div>';
    try{
      const sep = checkoutSelfUrl.includes('?') ? '&' : '?';
      const data = await checkoutGetJson(checkoutSelfUrl + sep + 'action=pdv_search_comandas&q=' + encodeURIComponent(q));
      const rows = Array.isArray(data.rows) ? data.rows : [];
      const normalized = String(q || '').trim().toLowerCase();
      const exact = normalized ? rows.find(r => String(r.codigo||'').trim().toLowerCase() === normalized || String(r.matricula||'').trim().toLowerCase() === normalized) : null;
      if(exact){
        checkoutComandaSelected.add(Number(exact.id));
        checkoutUpdateComandaSelectedCount();
        const input = document.getElementById('checkoutComandaQuery');
        if(input){ input.value=''; input.focus(); }
        showMobileToast('Comanda ' + (exact.codigo || exact.id) + ' selecionada');
        const all = await checkoutGetJson(checkoutSelfUrl + sep + 'action=pdv_search_comandas&q=');
        box.innerHTML = (all.rows || []).map(checkoutComandaRowHtml).join('') || '<div class="checkout-reader-empty">Nenhuma comanda ocupada.</div>';
        return;
      }
      box.innerHTML = rows.map(checkoutComandaRowHtml).join('') || '<div class="checkout-reader-empty">Nenhuma comanda ocupada encontrada.</div>';
    }catch(e){ box.innerHTML = '<div class="checkout-reader-empty">' + escapeHtml(e.message || 'Falha ao consultar comandas.') + '</div>'; }
    finally{ box.classList.remove('checkout-reader-loading'); }
  }
  function checkoutOpenComandas(){
    if(checkoutHasOsItems()){ checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de importar comandas.','Ler Comandas','warning'); return; }
    if(!checkoutComandaEnabled){ checkoutNotice('A leitura de comandas não está disponível para esta empresa/perfil.','Ler Comandas','warning'); return; }
    openModal('checkoutComandaReaderModal');
    const input = document.getElementById('checkoutComandaQuery');
    if(input) input.value='';
    checkoutUpdateComandaSelectedCount();
    checkoutSearchComandas('');
    setTimeout(() => input?.focus(), 70);
  }
  async function checkoutImportComandas(){
    if(checkoutHasOsItems()){ await checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de importar comandas.','Ler Comandas','warning'); return false; }
    if(!checkoutComandaSelected.size){ await checkoutNotice('Selecione ao menos uma comanda.','Ler Comandas','warning'); return false; }
    const ids = Array.from(checkoutComandaSelected);
    const btn = document.getElementById('checkoutComandaImportBtn');
    if(btn){ btn.disabled=true; btn.textContent='Importando...'; }
    try{
      const sep = checkoutSelfUrl.includes('?') ? '&' : '?';
      const data = await checkoutGetJson(checkoutSelfUrl + sep + 'action=pdv_load_comandas&ids=' + encodeURIComponent(ids.join(',')));
      const groups = Array.isArray(data.comandas) ? data.comandas : [];
      let added = 0;
      const customerIds = [];
      groups.forEach(group => {
        const cmd = group.comanda || {};
        if(Number(cmd.cliente_id || 0) > 0) customerIds.push(Number(cmd.cliente_id));
        (group.items || []).forEach(it => {
          const duplicate = Array.from(cart.values()).some(c => c.sourceType==='comanda' && Number(c.sourceItemId||0)===Number(it.id||0));
          if(duplicate) return;
          const key = 'cmd_' + Number(cmd.id||0) + '_' + Number(it.id||0);
          cart.set(key, {id:Number(it.produto_id||0),code:String(it.codigo_produto||it.produto_id||''),unit:'UN',name:String(it.nome||'Item'),qty:Number(it.qtd||0),price:Number(it.preco_unit||0),obs:String(it.observacao||''),details:'Comanda ' + String(cmd.codigo||cmd.id||''),splitItems:null,sourceType:'comanda',sourceId:Number(cmd.id||0),sourceItemId:Number(it.id||0)});
          checkoutLastCartKey = key; checkoutSelectedCartKey = key; added++;
        });
      });
      const uniqueCustomers = Array.from(new Set(customerIds.filter(id => id > 0)));
      if(uniqueCustomers.length === 1 && !selectedCustomer){
        const c = customers.find(x => Number(x.id) === uniqueCustomers[0]);
        if(c) selectCustomer(c);
      }
      checkoutCartScrollToEnd = true;
      renderCart();
      closeModal('checkoutComandaReaderModal');
      checkoutComandaSelected.clear(); checkoutUpdateComandaSelectedCount();
      showMobileToast(added + ' item(ns) de comanda importado(s)');
      checkoutFocusSearch();
      return true;
    }catch(e){ await checkoutNotice(e.message || 'Falha ao importar comandas.','Ler Comandas','danger'); return false; }
    finally{ if(btn){ btn.disabled=false; btn.textContent='Importar selecionadas (F9)'; } }
  }
  function checkoutOrcamentoRowHtml(r){
    const id = Number(r.id || 0), checked = checkoutOrcamentoSelected.has(id);
    const qn = r.quote_number || id;
    return `<label class="checkout-reader-row${checked?' is-selected':''}" data-checkout-orcamento-row="${id}"><input type="checkbox" data-checkout-orcamento-id="${id}" ${checked?'checked':''}><div class="checkout-reader-code">#${escapeHtml(qn)}<small>${escapeHtml(r.status || '')}</small></div><div class="checkout-reader-name"><strong>${escapeHtml(r.person_name_snapshot || 'Consumidor / cliente não informado')}</strong><small>${escapeHtml(r.person_document_snapshot || r.person_phone_snapshot || '')} · Vendedor: ${escapeHtml(r.seller_name_snapshot || 'Não informado')}</small></div><div class="checkout-reader-meta">${Number(r.item_count||0)} item(ns)</div><div class="checkout-reader-total">${checkoutReaderMoney(r.total_general)}</div></label>`;
  }
  async function checkoutSearchOrcamentos(q=''){
    if(!checkoutOrcamentoEnabled || !checkoutOrcamentoAjaxUrl) return;
    const box = document.getElementById('checkoutOrcamentoResults');
    if(!box) return;
    box.classList.add('checkout-reader-loading'); box.innerHTML='<div class="checkout-reader-empty">Consultando orçamentos...</div>';
    try{
      const sep=checkoutOrcamentoAjaxUrl.includes('?')?'&':'?';
      const data=await checkoutGetJson(checkoutOrcamentoAjaxUrl+sep+'action=list&q='+encodeURIComponent(q));
      const rows=Array.isArray(data.rows)?data.rows:[];
      const normalized=String(q||'').trim().replace(/^#/,'').toLowerCase();
      const exact=normalized?rows.find(r=>String(r.quote_number||r.id||'').trim().toLowerCase()===normalized):null;
      if(exact){
        checkoutOrcamentoSelected.add(Number(exact.id)); checkoutUpdateOrcamentoSelectedCount();
        const input=document.getElementById('checkoutOrcamentoQuery'); if(input){input.value='';input.focus();}
        showMobileToast('Orçamento #' + (exact.quote_number||exact.id) + ' selecionado');
        const all=await checkoutGetJson(checkoutOrcamentoAjaxUrl+sep+'action=list&q=');
        box.innerHTML=(all.rows||[]).map(checkoutOrcamentoRowHtml).join('')||'<div class="checkout-reader-empty">Nenhum orçamento disponível.</div>';
        return;
      }
      box.innerHTML=rows.map(checkoutOrcamentoRowHtml).join('')||'<div class="checkout-reader-empty">Nenhum orçamento aberto/aprovado encontrado.</div>';
    }catch(e){box.innerHTML='<div class="checkout-reader-empty">'+escapeHtml(e.message||'Falha ao consultar orçamentos.')+'</div>';}
    finally{box.classList.remove('checkout-reader-loading');}
  }
  function checkoutOpenOrcamentos(){
    if(checkoutHasOsItems()){ checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de importar orçamentos.','Ler Orçamento','warning'); return; }
    if(!checkoutOrcamentoEnabled){ checkoutNotice('A leitura de orçamentos não está disponível para esta empresa/perfil.','Ler Orçamento','warning'); return; }
    openModal('checkoutOrcamentoReaderModal');
    const input=document.getElementById('checkoutOrcamentoQuery'); if(input) input.value='';
    checkoutUpdateOrcamentoSelectedCount(); checkoutSearchOrcamentos('');
    setTimeout(()=>input?.focus(),70);
  }
  function checkoutApplyImportedQuoteAdjustments(datasets, replaceCurrent=false){
    const rows=(Array.isArray(datasets)?datasets:[]).filter(data=>Number(data?.quote?.id||0)>0);
    if(!rows.length) return;
    const discountEl=document.getElementById('discountAmount'), additionEl=document.getElementById('additionAmount'), freightEl=document.getElementById('deliveryFee');
    const discountPctEl=document.getElementById('discountPercent'), additionPctEl=document.getElementById('additionPercent');
    if(!discountEl || !additionEl || !freightEl) return;

    if(replaceCurrent && rows.length===1){
      const q=rows[0].quote||{};
      discountAdjustmentMode=String(q.discount_mode||'AMOUNT').toUpperCase()==='PERCENT'?'percent':'amount';
      additionAdjustmentMode=String(q.addition_mode||'AMOUNT').toUpperCase()==='PERCENT'?'percent':'amount';
      if(discountPctEl) discountPctEl.value=fmtInput(Number(q.discount_percent||0));
      if(additionPctEl) additionPctEl.value=fmtInput(Number(q.addition_percent||0));
      discountEl.value=fmtInput(Number(q.discount_amount||0));
      additionEl.value=fmtInput(Number(q.addition_amount||0));
      freightEl.value=fmtInput(Number(q.freight_amount||0));
      freightEl.dataset.manual='1';
      return;
    }

    const imported=rows.reduce((acc,data)=>{
      const q=data.quote||{};
      acc.discount+=Math.max(0,Number(q.discount_amount||0));
      acc.addition+=Math.max(0,Number(q.addition_amount||0));
      acc.freight+=Math.max(0,Number(q.freight_amount||0));
      return acc;
    },{discount:0,addition:0,freight:0});
    const baseDiscount=replaceCurrent?0:Math.max(0,parseMoney(discountEl.value||'0'));
    const baseAddition=replaceCurrent?0:Math.max(0,parseMoney(additionEl.value||'0'));
    const baseFreight=replaceCurrent?0:Math.max(0,parseMoney(freightEl.value||'0'));
    discountAdjustmentMode='amount'; additionAdjustmentMode='amount';
    discountEl.value=fmtInput(baseDiscount+imported.discount);
    additionEl.value=fmtInput(baseAddition+imported.addition);
    freightEl.value=fmtInput(baseFreight+imported.freight);
    freightEl.dataset.manual='1';
    syncCheckoutAdjustments();
  }
  // Preenche os pagamentos salvos no(s) orçamento(s) apenas quando o caixa está
  // começando uma venda vazia. Não sobrescreve pagamentos já digitados no Checkout.
  function checkoutApplyQuotePayments(datasets, cartWasEmpty){
    const paymentBox=document.getElementById('paymentLines');
    if(!paymentBox || !cartWasEmpty) return;
    const saved=(Array.isArray(datasets)?datasets:[]).flatMap(d=>Array.isArray(d.payments)?d.payments:[]);
    if(!saved.length) return;
    const invalid=saved.filter(p=>{
      const id=String(p.resolved_payment_method_id||p.payment_method_id||'');
      return !id || !paymentMap[id];
    });
    // O seletor em um pagamento recém criado contém as mesmas opções usadas no PDV.
    if(invalid.length){ checkoutNotice('Existem formas de pagamento do orçamento indisponíveis no Checkout. Informe-as manualmente antes de finalizar.','Pagamento do orçamento','warning'); return; }
    const current=Array.from(paymentBox.querySelectorAll('.pdv-payment-line'));
    // Segurança: não descartar linhas que o operador já preencheu.
    if(current.some(line=>String(line.querySelector('.pay-method')?.value||'')!=='')) return;
    const grouped=[];
    for(const pay of saved){
      const id=String(pay.resolved_payment_method_id||pay.payment_method_id||'');
      const amount=Number(pay.amount||0);
      if(!Number.isFinite(amount)||amount<=0) continue;
      const pm=paymentMap[id]||{};
      if(Number(pm.receivable||0)===1){
        let group=grouped.find(g=>g.id===id&&g.receivable);
        if(!group){group={id,receivable:true,rows:[]};grouped.push(group);}
        group.rows.push(pay);
      }else grouped.push({id,receivable:false,rows:[pay]});
    }
    if(!grouped.length) return;
    paymentBox.innerHTML=''; activePaymentLine=null;
    grouped.forEach(g=>{
      const amount=g.rows.reduce((v,p)=>v+Number(p.amount||0),0);
      const line=addPaymentLine(amount,false,false);
      const method=line.querySelector('.pay-method');
      method.value=g.id; method.dispatchEvent(new Event('change',{bubbles:true}));
      line.querySelector('.pay-amount').value=fmtInput(amount);
      line.querySelector('.pay-amount').dataset.autoSync='0';
      line.querySelector('.pay-received').value=fmtInput(amount);
      if(g.receivable){
        const count=line.querySelector('.installment-count'); if(count) count.value=String(g.rows.length);
        renderInstallments(line,true);
        const dues=line.querySelectorAll('.installment-due'), values=line.querySelectorAll('.installment-amount');
        g.rows.forEach((p,i)=>{if(dues[i]&&p.due_date)dues[i].value=String(p.due_date).slice(0,10);if(values[i])values[i].value=fmtInput(Number(p.amount||0));});
      }
    });
    updateTotals();
  }
  function checkoutShowImportedSeller(datasets){
    const sellerNames=Array.from(new Set(datasets.map(d=>String((d.quote||{}).seller_name_snapshot||'').trim()).filter(Boolean)));
    const sellerSelect=document.querySelector('#pdvForm select[name="seller_id"]');
    const selectedName=sellerSelect?.selectedOptions?.[0]?.textContent?.trim()||'Sem vendedor';
    const origin=sellerNames.length===1?sellerNames[0]:(sellerNames.length>1?sellerNames.join(', '):'Não informado');
    const panel=document.getElementById('checkoutImportedSellerInfo');
    if(panel){panel.hidden=false;panel.textContent='Vendedor do orçamento: '+origin+' | Vendedor da venda: '+selectedName;}
  }
  async function checkoutImportOrcamentos(){
    if(checkoutHasOsItems()){ await checkoutNotice('A venda contém Ordem de Serviço. Finalize ou remova as O.S. antes de importar orçamentos.','Ler Orçamento','warning'); return false; }
    if(!checkoutOrcamentoSelected.size){ await checkoutNotice('Selecione ao menos um orçamento.','Ler Orçamento','warning'); return false; }
    const ids=Array.from(checkoutOrcamentoSelected);
    const cartWasEmpty=cart.size===0;
    const btn=document.getElementById('checkoutOrcamentoImportBtn'); if(btn){btn.disabled=true;btn.textContent='Importando...';}
    try{
      const sep=checkoutOrcamentoAjaxUrl.includes('?')?'&':'?';
      const datasets=[];
      for(const id of ids){ const d=await checkoutGetJson(checkoutOrcamentoAjaxUrl+sep+'action=load&id='+encodeURIComponent(id)); datasets.push(d.data||d); }
      const warnings=[];
      datasets.forEach(d=>(d.warnings||[]).forEach(w=>warnings.push(w)));
      if(warnings.length && !await checkoutConfirm('• '+warnings.join('\n• ')+'\n\nDeseja continuar?','Atenção ao importar orçamento','warning','Continuar')) return false;
      let added=0; const customerIds=[], adjustmentDatasets=[];
      datasets.forEach(data=>{
        const quote=data.quote||{}, customer=data.customer||{}, items=Array.isArray(data.items)?data.items:[];
        if(Number(customer.id||0)>0) customerIds.push(Number(customer.id));
        const missing=items.filter(it=>!Number(it.current_product_id||0)||Number(it.current_active||0)!==1);
        if(missing.length) throw new Error('O orçamento #' + (quote.quote_number||quote.id) + ' possui produto removido ou inativo e não pode ser importado.');
        let quoteAdded=0;
        items.forEach(it=>{
          const sourceItemId=Number(it.id||0), sourceId=Number(quote.id||0);
          const duplicate=Array.from(cart.values()).some(c=>c.sourceType==='orcamento'&&Number(c.sourceId||0)===sourceId&&Number(c.sourceItemId||0)===sourceItemId);
          if(duplicate) return;
          const qty=Math.max(0.0001,Number(it.quantity||0)); const total=Number(it.total||0); const unit=qty>0?total/qty:Number(it.unit_price||0);
          const key='orc_'+sourceId+'_'+sourceItemId;
          cart.set(key,{id:Number(it.current_product_id||it.produto_id),code:String(it.current_product_number||it.product_code_snapshot||it.current_product_legacy_code||it.current_product_id||it.produto_id||''),unit:String(it.unidade_snapshot||'UN'),name:String(it.description_snapshot||it.current_product_name||'Item'),qty,price:unit,obs:String(it.notes||''),details:'Orçamento #'+String(quote.quote_number||quote.id),splitItems:null,sourceType:'orcamento',sourceId,sourceItemId,futureQty:Number(it.future_quantity||0)});
          checkoutLastCartKey=key; checkoutSelectedCartKey=key; added++; quoteAdded++;
        });
        if(quoteAdded>0) adjustmentDatasets.push(data);
      });
      // Preserve o vendedor do orçamento no fluxo F11, não apenas no carregamento individual.
      // Se os orçamentos têm vendedores distintos, não é correto atribuir tudo ao primeiro.
      const quoteSellerIds=Array.from(new Set(datasets.map(d=>Number((d.quote||{}).seller_person_id||0)).filter(id=>id>0)));
      const sellerSelect=document.querySelector('#pdvForm select[name="seller_id"]');
      if(quoteSellerIds.length===1 && sellerSelect && !sellerSelect.disabled){
        const sellerId=String(quoteSellerIds[0]);
        if(Array.from(sellerSelect.options).some(o=>o.value===sellerId)){
          sellerSelect.value=sellerId;
          sellerSelect.dispatchEvent(new Event('change',{bubbles:true}));
        } else {
          await checkoutNotice('O vendedor do orçamento não está disponível como vendedor ativo nesta empresa. Confira o vendedor antes de finalizar.','Vendedor do orçamento','warning');
        }
      } else if(quoteSellerIds.length>1){
        await checkoutNotice('Os orçamentos selecionados possuem vendedores diferentes. Esta venda admite um único vendedor. Confira o vendedor no PDV antes de finalizar.','Vendedores diferentes','warning');
      }
      const uniqueCustomers=Array.from(new Set(customerIds.filter(id=>id>0)));
      if(uniqueCustomers.length===1 && !selectedCustomer){ const c=customers.find(x=>Number(x.id)===uniqueCustomers[0]); if(c) selectCustomer(c); }
      if(adjustmentDatasets.length) checkoutApplyImportedQuoteAdjustments(adjustmentDatasets,cartWasEmpty);
      checkoutApplyQuotePayments(adjustmentDatasets,cartWasEmpty);
      checkoutShowImportedSeller(datasets);
      checkoutSyncSellerIndicator();
      checkoutCartScrollToEnd=true; renderCart();
      closeModal('checkoutOrcamentoReaderModal'); checkoutOrcamentoSelected.clear(); checkoutUpdateOrcamentoSelectedCount();
      showMobileToast(added+' item(ns) de orçamento importado(s)'+(adjustmentDatasets.length?' • ajustes aplicados':'')); checkoutFocusSearch();
      return true;
    }catch(e){await checkoutNotice(e.message||'Falha ao importar orçamentos.','Ler Orçamento','danger');return false;}
    finally{if(btn){btn.disabled=false;btn.textContent='Importar selecionados (F11)';}}
  }
  function checkoutUpdateOsSelectedCount(){
    const el=document.getElementById('checkoutOsSelectedCount');
    if(el) el.textContent=checkoutOsSelected.size+' O.S. selecionada(s)';
  }
  function checkoutOsRowHtml(r){
    const id=Number(r.id||0),checked=checkoutOsSelected.has(id),n=String(r.os_number||id).padStart(6,'0');
    const kinds=[]; if(Number(r.requires_product||0)===1)kinds.push('Produtos'); if(Number(r.requires_service||0)===1)kinds.push('Serviços');
    return `<label class="checkout-reader-row${checked?' is-selected':''}" data-checkout-os-row="${id}"><input type="checkbox" data-checkout-os-id="${id}" ${checked?'checked':''}><div class="checkout-reader-code">#${escapeHtml(n)}<small>${escapeHtml(r.status_name||'Pendente')}</small></div><div class="checkout-reader-name"><strong>${escapeHtml(r.person_name_snapshot||'Consumidor final')}</strong><small>${escapeHtml(r.person_document_snapshot||'')} ${kinds.length?'• '+escapeHtml(kinds.join(' + ')):''}</small></div><div class="checkout-reader-meta">${Number(r.item_count||0)} item(ns)</div><div class="checkout-reader-total">${checkoutReaderMoney(r.total_general)}</div></label>`;
  }
  async function checkoutSearchOs(q=''){
    if(!checkoutOsEnabled)return;
    const box=document.getElementById('checkoutOsResults');if(!box)return;
    box.classList.add('checkout-reader-loading');box.innerHTML='<div class="checkout-reader-empty">Consultando Ordens de Serviço...</div>';
    try{
      const sep=checkoutSelfUrl.includes('?')?'&':'?';
      const data=await checkoutGetJson(checkoutSelfUrl+sep+'action=pdv_search_os&q='+encodeURIComponent(q));
      const rows=Array.isArray(data.rows)?data.rows:[];
      const normalized=String(q||'').trim().replace(/^#/,'').replace(/^0+/,'');
      const exact=normalized?rows.find(r=>String(Number(r.os_number||0))===String(Number(normalized||0))):null;
      if(exact){
        checkoutOsSelected.add(Number(exact.id));checkoutUpdateOsSelectedCount();
        const input=document.getElementById('checkoutOsQuery');if(input){input.value='';input.focus();}
        showMobileToast('O.S. #'+String(exact.os_number||exact.id).padStart(6,'0')+' selecionada');
        const all=await checkoutGetJson(checkoutSelfUrl+sep+'action=pdv_search_os&q=');
        box.innerHTML=(all.rows||[]).map(checkoutOsRowHtml).join('')||'<div class="checkout-reader-empty">Nenhuma O.S. pendente.</div>';
        return;
      }
      box.innerHTML=rows.map(checkoutOsRowHtml).join('')||'<div class="checkout-reader-empty">Nenhuma Ordem de Serviço pendente encontrada.</div>';
    }catch(e){box.innerHTML='<div class="checkout-reader-empty">'+escapeHtml(e.message||'Falha ao consultar O.S.')+'</div>';}
    finally{box.classList.remove('checkout-reader-loading');}
  }
  function checkoutOpenOs(){
    if(!checkoutOsEnabled){checkoutNotice('A leitura de Ordem de Serviço não está disponível para esta empresa/perfil.','Ordem de Serviço','warning');return;}
    if(checkoutHasNonOsItems()){checkoutNotice('Para preservar a emissão separada de produtos e serviços, a Ordem de Serviço deve ser faturada em uma venda exclusiva. Cancele/finalize os itens atuais antes de importar a O.S.','Ordem de Serviço','warning');return;}
    openModal('checkoutOsReaderModal');const input=document.getElementById('checkoutOsQuery');if(input)input.value='';checkoutUpdateOsSelectedCount();checkoutSearchOs('');setTimeout(()=>input?.focus(),70);
  }
  async function checkoutImportOs(){
    if(!checkoutOsSelected.size){await checkoutNotice('Selecione ao menos uma Ordem de Serviço.','Ordem de Serviço','warning');return false;}
    if(checkoutHasNonOsItems()){await checkoutNotice('A Ordem de Serviço deve ser faturada em uma venda exclusiva. Remova os itens atuais antes de importar.','Ordem de Serviço','warning');return false;}
    const ids=Array.from(checkoutOsSelected),btn=document.getElementById('checkoutOsImportBtn');if(btn){btn.disabled=true;btn.textContent='Importando...';}
    try{
      const sep=checkoutSelfUrl.includes('?')?'&':'?';
      const data=await checkoutGetJson(checkoutSelfUrl+sep+'action=pdv_load_os&ids='+encodeURIComponent(ids.join(',')));
      const datasets=Array.isArray(data.orders)?data.orders:[];if(!datasets.length)throw new Error('Nenhuma Ordem de Serviço válida foi encontrada.');
      const customerIds=[];
      checkoutOsCurrentIds().forEach(id=>{const m=checkoutOsMeta.get(id);customerIds.push(Number(m?.customerId||0));});
      datasets.forEach(d=>customerIds.push(Number(d.order?.person_id||0)));
      const uniqueCustomers=Array.from(new Set(customerIds));
      if(uniqueCustomers.length>1)throw new Error('As Ordens de Serviço selecionadas precisam pertencer ao mesmo cliente.');
      const expectedCustomerId=Number(uniqueCustomers[0]||0);
      if(selectedCustomer && Number(selectedCustomer.id||0)!==expectedCustomerId)throw new Error('O cliente das O.S. é diferente do cliente já selecionado no Checkout.');
      if(!selectedCustomer && expectedCustomerId>0){const provided=datasets.map(d=>d.customer).find(c=>Number(c?.id||0)===expectedCustomerId);if(provided)selectCustomer(provided);else{const c=customers.find(x=>Number(x.id)===expectedCustomerId);if(c)selectCustomer(c);}}
      if(expectedCustomerId===0 && selectedCustomer)throw new Error('A O.S. está como consumidor final. Remova o cliente selecionado antes de importar.');
      let added=0;
      datasets.forEach(d=>{
        const o=d.order||{},osId=Number(o.id||0),osNumber=Number(o.os_number||osId),items=Array.isArray(d.items)?d.items:[];
        if(osId<=0)return;
        checkoutOsMeta.set(osId,{osNumber,customerId:Number(o.person_id||0),discount:Number(o.discount_amount||0),addition:Number(o.addition_amount||0),total:Number(o.total_general||0),requiresProduct:Number(o.requires_product||0)===1,requiresService:Number(o.requires_service||0)===1});
        items.forEach(it=>{
          const sourceItemId=Number(it.id||0);if(sourceItemId<=0)return;
          const duplicate=Array.from(cart.values()).some(c=>c.sourceType==='os'&&Number(c.sourceId||0)===osId&&Number(c.sourceItemId||0)===sourceItemId);if(duplicate)return;
          const type=String(it.item_type||'PRODUTO').toUpperCase(),key='os_'+osId+'_'+sourceItemId;
          cart.set(key,{id:Number(it.produto_id||0),code:String(it.codigo_produto||it.produto_id||''),unit:String(it.unidade_snapshot||'UN'),name:String(it.description_snapshot||'Item O.S.'),qty:Number(it.quantity||0),price:Number(it.unit_price||0),obs:String(it.notes||''),details:'O.S. #'+String(osNumber).padStart(6,'0')+' • '+(type==='SERVICO'?'Serviço':'Produto'),splitItems:null,sourceType:'os',sourceId:osId,sourceItemId,sourceItemType:type,stockEffect:type==='SERVICO'?0:1,priceDecimals:4});
          checkoutLastCartKey=key;checkoutSelectedCartKey=key;added++;
        });
      });
      checkoutOsFiscalChoiceConfirmed=false;setVal('checkoutOsProductDocument','');setVal('checkoutOsServiceDocument','');
      checkoutCartScrollToEnd=true;checkoutApplyOsFinancials();renderCart();
      closeModal('checkoutOsReaderModal');checkoutOsSelected.clear();checkoutUpdateOsSelectedCount();
      showMobileToast(added+' item(ns) de O.S. importado(s)');checkoutFocusSearch();return true;
    }catch(e){await checkoutNotice(e.message||'Falha ao importar Ordem de Serviço.','Ordem de Serviço','danger');return false;}
    finally{if(btn){btn.disabled=false;btn.textContent='Importar selecionadas (F6)';}}
  }

  function checkoutOpenPayment(){
    if(cart.size === 0){
      checkoutNotice('Inclua ao menos um produto antes de abrir o pagamento.','Pagamento','warning');
      checkoutFocusSearch();
      return false;
    }
    showStep('pagamento');
    return true;
  }
  function checkoutCurrentCartKey(){
    if(checkoutSelectedCartKey && cart.has(checkoutSelectedCartKey)) return checkoutSelectedCartKey;
    if(checkoutLastCartKey && cart.has(checkoutLastCartKey)) return checkoutLastCartKey;
    const keys = Array.from(cart.keys());
    return keys.length ? keys[keys.length - 1] : null;
  }
  async function checkoutCancelSelectedItem(){
    const key = checkoutCurrentCartKey();
    if(!key){
      await checkoutNotice('Não há item selecionado para cancelar.','Cancelar item','warning');
      checkoutFocusSearch();
      return false;
    }
    const item = cart.get(key);
    if(!item) return false;
    if(item.sourceType==='os'){
      const removed=await checkoutRemoveOsSource(Number(item.sourceId||0), true);
      if(removed){showMobileToast('O.S. removida');checkoutFocusSearch();}
      return removed;
    }
    if(!await checkoutConfirm('Cancelar o item \"' + item.name + '\"?','Cancelar item','danger','Cancelar item')) return false;
    cart.delete(key);
    const keys = Array.from(cart.keys());
    checkoutLastCartKey = keys.length ? keys[keys.length - 1] : null;
    checkoutSelectedCartKey = checkoutLastCartKey;
    renderCart();
    showMobileToast('Item cancelado');
    checkoutFocusSearch();
    return true;
  }
  async function checkoutResetSale(){
    if(cart.size && !await checkoutConfirm('Cancelar toda a venda atual?\nTodos os itens, pagamentos e ajustes informados serão descartados.','Cancelar venda','danger','Cancelar venda')) return false;
    checkoutPixCloseModal();
    const form = document.getElementById('pdvForm');
    form?.reset();
    discountAdjustmentMode = 'amount';
    additionAdjustmentMode = 'amount';
    const discountPercentReset = document.getElementById('discountPercent'); if(discountPercentReset) discountPercentReset.value = '0,00';
    const additionPercentReset = document.getElementById('additionPercent'); if(additionPercentReset) additionPercentReset.value = '0,00';
    cart.clear();
    checkoutSelectedCartKey = null;
    checkoutLastCartKey = null;
    checkoutProductValueTarget = 0;
    checkoutComandaSelected.clear();
    checkoutOrcamentoSelected.clear();
    checkoutOsSelected.clear();
    checkoutOsMeta.clear();
    checkoutUpdateComandaSelectedCount();
    checkoutUpdateOrcamentoSelectedCount();
    checkoutUpdateOsSelectedCount();
    checkoutOsFiscalChoiceConfirmed=false;
    setVal('checkoutOsProductDocument',''); setVal('checkoutOsServiceDocument','');
    checkoutSetOsFinancialLock(false);
    clearProductMultiplier();
    products = [];
    pizzas = [];
    productOptions = {};
    productsLoaded = false;
    currentCat = 'Todos';
    document.querySelectorAll('.pdv-cat').forEach((btn, idx) => btn.classList.toggle('active', idx === 0));
    setVal('orcamentoId','');
    setVal('emitDocAfter','');
    setVal('creditOverrideUserCode','');
    setVal('creditOverridePassword','');
    creditOverrideConfirmed = false;
    fiscalChoiceConfirmed = !fiscalEmissionEnabled;
    const feeInput = document.getElementById('deliveryFee');
    if(feeInput){ feeInput.value = '0,00'; delete feeInput.dataset.manual; }
    setVal('scheduledDeliveryDate','');
    setVal('scheduledDeliveryTime','');
    const notes = document.querySelector('#pdvForm textarea[name="notes"]');
    if(notes) notes.value = '';
    setConsumerFinal(true);
    const payBox = document.getElementById('paymentLines');
    if(payBox) payBox.innerHTML = '';
    activePaymentLine = null;
    paymentShortcutBuffer = '';
    clearTimeout(paymentShortcutTimer);
    addPaymentLine(0, true, false);
    syncAddressHidden(true);
    renderProducts();
    renderCart();
    showStep('produtos');
    showMobileToast('Venda cancelada');
    setTimeout(() => { search?.focus(); search?.select?.(); }, 60);
    return true;
  }
  function checkoutLookupModalOpen(){
    const ids = ['checkoutActionModal','checkoutPixModal','customerSearchModal','productSearchModal','checkoutValueProductModal','checkoutComandaReaderModal','checkoutOrcamentoReaderModal','checkoutSellerModal','checkoutOsReaderModal','checkoutOsFiscalModal','pdvOptionModal','customerModal','addressModal'];
    return ids.some(id => { const el=document.getElementById(id); return !!(el && el.style.display !== 'none'); });
  }
  function updateCheckoutClock(){
    const el = document.getElementById('checkoutClock');
    if(!el) return;
    const now = new Date();
    const two = n => String(n).padStart(2,'0');
    el.textContent = `${two(now.getDate())}/${two(now.getMonth()+1)}/${now.getFullYear()} ${two(now.getHours())}:${two(now.getMinutes())}`;
  }

  function checkoutOsFiscalRequirements(){
    let product=false,service=false;
    checkoutOsCurrentIds().forEach(id=>{const m=checkoutOsMeta.get(Number(id));if(m){product=product||!!m.requiresProduct;service=service||!!m.requiresService;}});
    return {product,service};
  }
  function checkoutOpenOsFiscalChoice(){
    const req=checkoutOsFiscalRequirements();
    const modal=document.getElementById('checkoutOsFiscalModal');
    if(!modal){checkoutOsFiscalChoiceConfirmed=true;document.getElementById('pdvForm')?.requestSubmit();return;}
    const pg=document.getElementById('checkoutOsProductFiscalGroup'),sg=document.getElementById('checkoutOsServiceFiscalGroup');
    if(pg)pg.style.display=req.product?'grid':'none';if(sg)sg.style.display=req.service?'grid':'none';
    const ps=document.getElementById('checkoutOsProductFiscalSelect'),ss=document.getElementById('checkoutOsServiceFiscalSelect');
    if(ps)ps.value=document.getElementById('checkoutOsProductDocument')?.value||'';
    if(ss)ss.value=document.getElementById('checkoutOsServiceDocument')?.value||'';
    const parts=[];if(req.product)parts.push('produtos → NF-e/NFC-e ou não emitir agora');if(req.service)parts.push('serviços → NFS-e ou não emitir agora');
    const summary=document.getElementById('checkoutOsFiscalSummary');if(summary)summary.textContent=(checkoutOsCurrentIds().length+' O.S. importada(s). ')+(parts.length?parts.join(' • '):'Nenhuma parte fiscal identificada.');
    openModal('checkoutOsFiscalModal');
    setTimeout(()=>{(req.product?ps:ss)?.focus();},60);
  }
  function finishCheckoutOsFiscalChoice(){
    const req=checkoutOsFiscalRequirements();
    const product=req.product?String(document.getElementById('checkoutOsProductFiscalSelect')?.value||''):'';
    const service=req.service?String(document.getElementById('checkoutOsServiceFiscalSelect')?.value||''):'';
    setVal('checkoutOsProductDocument',product);setVal('checkoutOsServiceDocument',service);setVal('emitDocAfter','none');
    checkoutOsFiscalChoiceConfirmed=true;closeModal('checkoutOsFiscalModal');
    const form=document.getElementById('pdvForm');if(form?.requestSubmit)form.requestSubmit();else form?.submit();
  }
  function checkoutOsFiscalModalOpen(){const modal=document.getElementById('checkoutOsFiscalModal');return !!(modal&&modal.style.display!=='none');}
  document.getElementById('checkoutOsFiscalConfirm')?.addEventListener('click',finishCheckoutOsFiscalChoice);

  function finishFiscalChoice(choice){
    const form = document.getElementById('pdvForm');
    document.getElementById('emitDocAfter').value = ['nfce','nfe','nfse'].includes(choice) ? choice : 'none';
    fiscalChoiceConfirmed = true;
    closeModal('pdvFiscalModal');
    if(form.requestSubmit) form.requestSubmit(); else form.submit();
  }
  document.querySelectorAll('[data-pdv-fiscal]').forEach(btn => btn.addEventListener('click', () => finishFiscalChoice(btn.dataset.pdvFiscal || 'none')));
  function pdvFiscalModalOpen(){
    const modal = document.getElementById('pdvFiscalModal');
    return !!(modal && modal.style.display !== 'none');
  }
  async function checkoutExit(){
    if(cart.size && !await checkoutConfirm('Sair do PDV Checkout e descartar a venda atual?','Sair do PDV Checkout','warning','Sair')) return false;
    window.location.href=checkoutExitUrl;return true;
  }
  document.getElementById('checkoutClientBtn')?.addEventListener('click', checkoutOpenCustomer);
  document.getElementById('checkoutValueProductBtn')?.addEventListener('click', checkoutOpenValueProduct);
  document.getElementById('checkoutProductsBtn')?.addEventListener('click', checkoutOpenProducts);
  document.getElementById('checkoutComandasBtn')?.addEventListener('click', checkoutOpenComandas);
  function checkoutSyncSellerIndicator(){
    const select=document.querySelector('#pdvForm select[name="seller_id"]');
    const label=document.getElementById('checkoutSellerHeaderName');
    if(!label || !select) return;
    const text=select.value ? (select.selectedOptions?.[0]?.textContent||'Não informado').trim() : 'Não informado';
    label.textContent=text;
    label.title=text;
  }
  function checkoutOpenSeller(){
    const current=document.querySelector('#pdvForm select[name="seller_id"]');
    const picker=document.getElementById('checkoutSellerPicker');
    if(!current || !picker) return;
    picker.value=current.value;
    openModal('checkoutSellerModal');
    if(!picker.disabled) {picker.focus();}
  }
  document.querySelector('#pdvForm select[name="seller_id"]')?.addEventListener('change', checkoutSyncSellerIndicator);
  document.getElementById('checkoutSellerBtn')?.addEventListener('click', checkoutOpenSeller);
  document.getElementById('checkoutSellerApplyBtn')?.addEventListener('click', ()=>{
    const current=document.querySelector('#pdvForm select[name="seller_id"]');
    const picker=document.getElementById('checkoutSellerPicker');
    if(!current || !picker || picker.disabled || current.disabled) return;
    current.value=picker.value;
    current.dispatchEvent(new Event('change',{bubbles:true}));
    closeModal('checkoutSellerModal');
  });
  if(isCheckoutPdv) window.addEventListener('keydown',e=>{
    if(e.key==='F10' && e.ctrlKey){
      e.preventDefault();e.stopImmediatePropagation();
      const modal=document.getElementById('checkoutSellerModal');
      if(modal && modal.style.display!=='none') document.getElementById('checkoutSellerApplyBtn')?.click();
      else if(!checkoutLookupModalOpen()) checkoutOpenSeller();
    }
  },true);
  checkoutSyncSellerIndicator();
  document.getElementById('checkoutOrcamentosBtn')?.addEventListener('click', checkoutOpenOrcamentos);
  document.getElementById('checkoutOsBtn')?.addEventListener('click', checkoutOpenOs);
  document.getElementById('checkoutCancelItemBtn')?.addEventListener('click', checkoutCancelSelectedItem);
  document.getElementById('checkoutPaymentBtn')?.addEventListener('click', checkoutOpenPayment);
  document.getElementById('checkoutCancelSaleBtn')?.addEventListener('click', checkoutResetSale);
  document.getElementById('checkoutExitBtn')?.addEventListener('click', checkoutExit);
  if(isCheckoutPdv && checkoutOrcamentoEnabled){
    window.addEventListener('keydown', e => {
      if(e.key !== 'F11') return;
      e.preventDefault();
      e.stopImmediatePropagation();
      const modal=document.getElementById('checkoutOrcamentoReaderModal');
      if(modal && modal.style.display !== 'none') checkoutImportOrcamentos();
      else if(!checkoutLookupModalOpen()) checkoutOpenOrcamentos();
    }, true);
  }
  updateCheckoutClock();
  window.setInterval(updateCheckoutClock, 30000);

  document.addEventListener('keydown', e => {
    if(checkoutActionDialogOpen()){
      if(e.key === 'Escape'){ e.preventDefault(); checkoutActionDialogClose(false); return; }
      if(e.key === 'Enter'){ e.preventDefault(); checkoutActionDialogClose(true); return; }
      return;
    }
    if(checkoutOsFiscalModalOpen()){
      if(e.key === 'Enter'){e.preventDefault();finishCheckoutOsFiscalChoice();return;}
      if(e.key === 'Escape'){e.preventDefault();closeModal('checkoutOsFiscalModal');return;}
      return;
    }
    if(pdvFiscalModalOpen()){
      if(e.key === '1' && document.querySelector('[data-pdv-fiscal="nfce"]')){ e.preventDefault(); finishFiscalChoice('nfce'); return; }
      if(e.key === '2' && document.querySelector('[data-pdv-fiscal="nfe"]')){ e.preventDefault(); finishFiscalChoice('nfe'); return; }
      if(e.key === '3' && document.querySelector('[data-pdv-fiscal="nfse"]')){ e.preventDefault(); finishFiscalChoice('nfse'); return; }
      if(e.key === 'Escape' && document.querySelector('[data-pdv-fiscal="none"]')){ e.preventDefault(); finishFiscalChoice('none'); return; }
      return;
    }
    if(isCheckoutPdv){
      const customerModal = document.getElementById('customerSearchModal');
      const productModal = document.getElementById('productSearchModal');
      const customerModalOpen = !!(customerModal && customerModal.style.display !== 'none');
      const productModalOpen = !!(productModal && productModal.style.display !== 'none');
      const anyCheckoutModalOpen = checkoutLookupModalOpen();
      if(e.key === 'F8'){
        e.preventDefault();
        if(customerModalOpen) document.getElementById('customerModalSearch')?.focus();
        else if(productModalOpen) document.getElementById('productModalSearch')?.focus();
        else if(!anyCheckoutModalOpen) checkoutOpenProducts();
        return;
      }
      if(e.key === 'F2'){
        e.preventDefault();
        if(customerModalOpen) document.getElementById('customerModalSearch')?.focus();
        else if(!anyCheckoutModalOpen) checkoutOpenCustomer();
        return;
      }
      if(e.key === 'F4'){
        e.preventDefault();
        const valueModal=document.getElementById('checkoutValueProductModal');
        if(valueModal && valueModal.style.display !== 'none') document.getElementById('checkoutValueAmount')?.focus();
        else if(!anyCheckoutModalOpen) checkoutOpenValueProduct();
        return;
      }
      if(e.key === 'F9'){
        e.preventDefault();
        const cmdModal=document.getElementById('checkoutComandaReaderModal');
        if(cmdModal && cmdModal.style.display !== 'none') checkoutImportComandas();
        else if(!anyCheckoutModalOpen) checkoutOpenComandas();
        return;
      }
      if(e.key === 'F6'){
        e.preventDefault();
        const osModal=document.getElementById('checkoutOsReaderModal');
        if(osModal && osModal.style.display !== 'none') checkoutImportOs();
        else if(!anyCheckoutModalOpen) checkoutOpenOs();
        return;
      }
      if(!checkoutLookupModalOpen() && e.key === 'F3'){
        e.preventDefault();
        checkoutCancelSelectedItem();
        return;
      }
      if(!checkoutLookupModalOpen() && e.key === 'F5'){
        e.preventDefault();
        checkoutOpenPayment();
        return;
      }
      if(!checkoutLookupModalOpen() && e.key === 'F7'){
        e.preventDefault();
        checkoutResetSale();
        return;
      }
      if(!checkoutLookupModalOpen() && e.key === 'Escape' && currentStepName() === 'pagamento'){
        e.preventDefault();
        checkoutFocusSearch();
        return;
      }
    }else if(e.key === 'F8' && !isMobilePdv){
      e.preventDefault();
      const customerModal = document.getElementById('customerSearchModal');
      const productModal = document.getElementById('productSearchModal');
      if(customerModal && customerModal.style.display !== 'none'){
        document.getElementById('customerModalSearch')?.focus();
      }else if(productModal && productModal.style.display !== 'none'){
        document.getElementById('productModalSearch')?.focus();
      }else if(currentStepName() === 'cliente'){
        openCustomerSearchModal();
      }else if(currentStepName() === 'produtos'){
        openProductSearchModal();
      }
      return;
    }
    if(currentStepName() === 'pagamento'){
      const target = e.target;
      const isValueEntry = !!target?.matches?.('input, textarea, [contenteditable="true"]');
      if(e.key === '+' || e.code === 'NumpadAdd'){
        e.preventDefault();
        addPaymentLine(undefined, false, true);
        return;
      }
      if(e.key === '-' || e.code === 'NumpadSubtract'){
        e.preventDefault();
        removePaymentLine();
        return;
      }
      if(/^\d$/.test(e.key) && !isValueEntry){
        e.preventDefault();
        choosePaymentShortcutDigit(e.key);
        return;
      }
    }
    const shortcuts = {F2:'cliente', F3:'produtos', F4:'carrinho', F5:'pagamento'};
    if(!isCheckoutPdv && shortcuts[e.key]){
      e.preventDefault();
      const step = shortcuts[e.key];
      if(canEnterStep(step)) showStep(step);
      return;
    }
    if(e.key === 'F10' && !e.ctrlKey && !e.shiftKey && !e.altKey){
      e.preventDefault();
      const pdvForm = document.getElementById('pdvForm');
      if(pdvForm?.requestSubmit) pdvForm.requestSubmit(); else pdvForm?.submit();
    }
  });
  document.getElementById('pdvFiscalModal')?.addEventListener('click', e => { if(e.target.id === 'pdvFiscalModal') closeModal('pdvFiscalModal'); });

  window.openOrcamentoReader = checkoutOpenOrcamentos;
  window.NexaOrcamentoApply = async function(data){
    const quote = data?.quote || {}, items = Array.isArray(data?.items) ? data.items : [], pays = Array.isArray(data?.payments) ? data.payments : [], customer = data?.customer || {};
    if(!quote.id) throw new Error('Orçamento inválido.');
    const missing = items.filter(it => !Number(it.current_product_id || 0) || Number(it.current_active || 0) !== 1);
    if(missing.length){
      checkoutNotice('Existem produtos removidos ou inativos no orçamento. Regularize-os antes de carregar o pedido.','Ler Orçamento','warning');
      return false;
    }
    if(cart.size && !await checkoutConfirm('O carrinho atual será substituído pelos itens do orçamento #' + (quote.quote_number || quote.id) + '. Deseja continuar?','Substituir carrinho','warning','Continuar')) return false;

    setVal('orcamentoId', quote.id);
    cart.clear();
    items.forEach(it => {
      const qty = Math.max(0.0001, Number(it.quantity || 0));
      const total = Number(it.total || 0);
      const unit = qty > 0 ? total / qty : Number(it.unit_price || 0);
      const key = 'orc_' + quote.id + '_' + it.id;
      cart.set(key, {id:Number(it.current_product_id || it.produto_id), code:String(it.current_product_number||it.product_code_snapshot||it.current_product_legacy_code||it.current_product_id||it.produto_id||''), name:String(it.description_snapshot || it.current_product_name || 'Item'), qty:qty, price:unit, obs:String(it.notes || ''), details:'', splitItems:null});
    });
    checkoutCartScrollToEnd = true;

    if(Number(customer.id || 0) > 0){
      let c = customers.find(x => Number(x.id) === Number(customer.id));
      if(!c){
        c = {id:Number(customer.id),name:String(customer.name||customer.fantasy_name||''),fantasy_name:String(customer.fantasy_name||''),document:String(customer.document||''),phone:String(customer.phone||''),whatsapp:String(customer.whatsapp||customer.phone||''),zip_code:String(customer.zip_code||''),address:String(customer.address||''),number:String(customer.number||''),complement:String(customer.complement||''),district:String(customer.district||''),city:String(customer.city||''),state:String(customer.state||''),credit_limit:null,credit_open:0};
        customers.push(c);
      }
      selectCustomer(c);
    }else{
      setConsumerFinal(false);
      const nm=String(customer.name || quote.person_name_snapshot || '').trim(), ph=String(customer.whatsapp || customer.phone || quote.person_phone_snapshot || '').trim();
      if(document.getElementById('finalConsumerNameInput')) document.getElementById('finalConsumerNameInput').value=nm;
      if(document.getElementById('customerWhatsappInput')) document.getElementById('customerWhatsappInput').value=ph;
      setVal('customerName', nm || 'Consumidor final'); setVal('customerWhatsapp', onlyDigits(ph));
    }

    const setField=(id,v)=>{const e=document.getElementById(id);if(e && v!==null && typeof v!=='undefined' && String(v)!=='')e.value=String(v)};
    setField('deliveryZip', quote.delivery_zip_code); setField('deliveryDistrict', quote.delivery_district); setField('deliveryAddress', quote.delivery_address); setField('deliveryNumber', quote.delivery_number); setField('deliveryComplement', quote.delivery_complement); setField('deliveryReference', quote.delivery_reference);
    setVal('orderCity', quote.delivery_city || customer.city || ''); setVal('orderState', quote.delivery_state || customer.state || '');
    if((quote.delivery_address || quote.delivery_district || quote.delivery_date) && document.getElementById('orderType')){ const o=document.querySelector('#orderType option[value="entrega"]'); if(o) document.getElementById('orderType').value='entrega'; }
    setField('scheduledDeliveryDate', quote.delivery_date); setField('scheduledDeliveryTime', quote.delivery_time ? String(quote.delivery_time).slice(0,5) : '');
    const sellerSel=document.querySelector('select[name="seller_id"]'); if(sellerSel && !sellerSel.disabled && Number(quote.seller_person_id||0)>0 && sellerSel.querySelector('option[value="'+Number(quote.seller_person_id)+'"]')) sellerSel.value=String(Number(quote.seller_person_id));
    const notes=document.querySelector('#pdvForm textarea[name="notes"]'); if(notes){ const origin='Origem: Orçamento #' + (quote.quote_number || quote.id); notes.value=[String(quote.notes||'').trim(),origin].filter(Boolean).join('\n'); }
    discountAdjustmentMode=String(quote.discount_mode||'AMOUNT').toUpperCase()==='PERCENT'?'percent':'amount'; additionAdjustmentMode=String(quote.addition_mode||'AMOUNT').toUpperCase()==='PERCENT'?'percent':'amount';
    if(document.getElementById('discountPercent')) document.getElementById('discountPercent').value=Number(quote.discount_percent||0).toFixed(2).replace('.',',');
    if(document.getElementById('additionPercent')) document.getElementById('additionPercent').value=Number(quote.addition_percent||0).toFixed(2).replace('.',',');
    document.getElementById('discountAmount').value=Number(quote.discount_amount||0).toFixed(2).replace('.',','); document.getElementById('additionAmount').value=Number(quote.addition_amount||0).toFixed(2).replace('.',','); document.getElementById('deliveryFee').value=Number(quote.freight_amount||0).toFixed(2).replace('.',','); document.getElementById('deliveryFee').dataset.manual='1';
    syncAddressHidden(false); renderCart();

    const payBox=document.getElementById('paymentLines'); payBox.innerHTML=''; activePaymentLine=null;
    if(pays.length){
      const used=new Set();
      pays.forEach((p,idx)=>{
        if(used.has(idx)) return;
        const methodId=String(p.payment_method_id||''), pm=paymentMap[methodId]||{};
        let group=[p]; used.add(idx);
        if(Number(pm.receivable||0)===1){
          group=[]; pays.forEach((x,j)=>{if(!used.has(j) && String(x.payment_method_id||'')===methodId){group.push(x);used.add(j)}}); if(!group.length) group=[p];
        }
        const amount=group.reduce((a,x)=>a+Number(x.amount||0),0), line=addPaymentLine(amount,false,false); line.querySelector('.pay-amount').dataset.autoSync='0'; line.querySelector('.pay-received').value=fmtInput(amount);
        if(methodId && line.querySelector('.pay-method option[value="'+methodId+'"]')){
          line.querySelector('.pay-method').value=methodId;
          line.querySelector('.pay-method').dispatchEvent(new Event('change',{bubbles:true}));
          if(Number(pm.receivable||0)===1){
            const cnt=line.querySelector('.installment-count'); if(cnt){cnt.value=String(Math.max(1,group.length));}
            renderInstallments(line,true);
            const dueInputs=line.querySelectorAll('.installment-due'), valInputs=line.querySelectorAll('.installment-amount');
            group.forEach((x,i)=>{if(dueInputs[i] && x.due_date) dueInputs[i].value=String(x.due_date).slice(0,10); if(valInputs[i]) valInputs[i].value=fmtInput(Number(x.amount||0));});
          }
        }
      });
    }else addPaymentLine(orderTotal(),true,false);
    updateTotals(); syncCartHidden(); fiscalChoiceConfirmed=!fiscalEmissionEnabled; showStep('produtos');
    setTimeout(()=>search?.focus(),80);
    return true;
  };

  renderProducts(); renderCart(); adjustCheckoutCartViewport(); addPaymentLine(orderTotal(), true, false); setConsumerFinal(false); syncAddressHidden(true); renderInlineCustomerList(''); showStep(isCheckoutPdv ? 'produtos' : 'cliente');
  window.addEventListener('resize', () => {
    adjustCheckoutCartViewport();
    if(isCheckoutPdv && cartBox){
      cartBox.scrollTop = Math.max(0, cartBox.scrollHeight - cartBox.clientHeight);
    }
  });
})();
</script>
<?php require __DIR__ . '/_card_installment_picker.php'; ?>
<?php $voucherPaymentScope = 'pdv_checkout'; require __DIR__ . '/_voucher_payment_selector.php'; ?>
<?php gestao_admin_end(); ?>
