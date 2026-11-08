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
require_once __DIR__ . '/../config/pix_sales_flow.php';

$pdvMobileMode = !empty($_GET['mobile']) || basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'gestao_pdv_mobile.php';
$pdvScope = $pdvMobileMode ? 'sales_pdv_mobile' : 'sales_pdv_order';
$pdvPermission = $pdvMobileMode ? 'gestao_pdv_mobile' : 'gestao_pdv';
$pdvLabel = $pdvMobileMode ? 'Venda Mobile' : 'Pedido PDV';
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
$saleFinalizationBlocked = $pdvCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'nao_finalizar_venda'))
  : (function_exists('access_sale_finalization_blocked') ? access_sale_finalization_blocked($pdo, $companyId) : false);
$erro = '';
$pdvBaseUrl = $pdvMobileMode ? 'gestao_pdv_mobile.php' : 'gestao_pdv.php';
$pdvContextUrl = static function(string $url) use ($pdvCrossContext): string {
  return $pdvCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$pdvMcCsrf = $pdvCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$pdvMcCtx = $pdvCrossContext && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '') : '';
$pixSalesReady = pix_sales_flow_ready($pdo, !$pdvCrossContext);
pix_sales_flow_handle_ajax($pdo, $companyId, $userId, $pdvCrossContext, $pixSalesReady);
$orcamentoReadEnabled = !$pdvMobileMode && function_exists('orc_bridge_ready') && orc_bridge_ready($pdo, $companyId);
$orcamentoAjaxUrl = $orcamentoReadEnabled ? $pdvContextUrl('orcamento_pedido_ajax.php?scope=sales_pdv_order') : '';

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
  if (function_exists('nexa_progress')) nexa_progress('Conferindo itens, pagamentos e cliente...');
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
      if (!function_exists('fiscal_checkout_option_enabled') || !fiscal_checkout_option_enabled($fiscalCfgPost, $requestedCheckoutOption)) {
        throw new RuntimeException('A opção de finalização selecionada não está habilitada nas Configurações Fiscais.');
      }
    }
    $verifiedPixCharges = pix_sales_flow_validate_before_finalize($pdo, $companyId, $_POST, $pixSalesReady);
    $deliveryControlPrep = ['active'=>false,'requested_doc'=>'none'];
    if (function_exists('delivery_control_prepare_order_post')) $deliveryControlPrep = delivery_control_prepare_order_post($pdo,$companyId,$_POST);
    if (!empty($deliveryControlPrep['active'])) $requestedCheckoutOption = 'none';
    $orderId = gestao_finalize_pdv_order($pdo, $companyId, $userId, $_POST);
    if ($verifiedPixCharges) {
      try { pix_sales_flow_link_order($pdo, $companyId, $orderId, $verifiedPixCharges); }
      catch (Throwable $pixLinkError) { error_log('[Nexa V11.52.6] Pedido #' . $orderId . ' salvo, mas não foi possível vincular a cobrança Pix: ' . $pixLinkError->getMessage()); }
    }
    $orcamentoOrigemId = (int)($_POST['orcamento_id'] ?? 0);
    if ($orcamentoOrigemId > 0 && function_exists('orc_bridge_release_reservations_after_order')) {
      try { orc_bridge_release_reservations_after_order($pdo, $companyId, $orcamentoOrigemId, $userId, $orderId, (string)($deliveryControlPrep['requested_doc'] ?? 'none')); }
      catch (Throwable $orcBridgeError) { error_log('[Nexa V11.36.4] Pedido #' . $orderId . ' salvo, mas não foi possível liberar a reserva do orçamento #' . $orcamentoOrigemId . ': ' . $orcBridgeError->getMessage()); }
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
        header('Location: ' . $pdvContextUrl('fiscal_nfse_nacional.php?pedido_id=' . $orderId . '&source=pdv_order&return_to=' . rawurlencode($pdvReturnUrlPost) . '&ok=' . rawurlencode($nfseMessage)));
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
          header('Location: ' . $pdvContextUrl('fiscal_nfce_imprimir.php?doc_id=' . $docId . '&source=pdv_order&return_to=' . rawurlencode($returnTo)));
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
gestao_admin_start($pdvMobileMode ? 'Venda Mobile' : 'Pedido PDV', $pdvMobileMode ? 'gestao_pdv_mobile' : 'gestao_pdv');
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
<?php if (!$cashSession && nexa_cash_user_terminal_enabled($pdo, $companyId, $userId)): ?>
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

<form method="post" id="pdvForm" class="pdv-wizard-form<?= $pdvMobileMode ? ' pdv-mobile-mode' : '' ?>">
  <input type="hidden" name="action" value="finalize_pdv">
  <?php if ($pdvCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($pdvMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= gestao_h($pdvMcCtx) ?>"><?php endif; ?>
  <input type="hidden" name="credit_override_user_code" id="creditOverrideUserCode" value=""><input type="hidden" name="credit_override_password" id="creditOverridePassword" value="">
  <?php if ($pdvMobileMode): ?><input type="hidden" name="mobile" value="1"><?php endif; ?>
  <input type="hidden" name="emit_doc_after" id="emitDocAfter" value="">
  <input type="hidden" name="orcamento_id" id="orcamentoId" value="">
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
          <img src="<?= gestao_h($pdvMobileMode ? '/assets/logo-nexa-prospero.jpg' : '/assets/logo-nexa-v6-2.png') ?>" alt="Nexa Próspero" class="pdv-brand-logo">
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
          <label>Buscar produto
            <input id="pdvSearch" type="search" placeholder="Digite o produto" autocomplete="off">
          </label>
        </div>
        <button type="button" class="btn" id="pdvProductSearchBtn" title="Pesquisar produtos (F8)">Buscar <small style="font-size:10px;opacity:.72;">F8</small></button>
        <div class="pdv-mini-summary"><span>Cliente</span><strong id="productCustomerView">Consumidor final</strong></div>
      </div>
      <div class="pdv-cats" id="pdvCats">
        <?php foreach ($categories as $idx => $c): ?><button class="pdv-cat <?= $idx===0?'active':'' ?>" type="button" data-cat="<?= gestao_h($c) ?>"><?= gestao_h($c) ?></button><?php endforeach; ?>
      </div>
      <div class="pdv-products pdv-products-wizard" id="pdvProducts"></div>
    </div>
  </section>

  <section class="pdv-step-panel" data-step-panel="carrinho">
    <div class="mg-card pdv-panel-card">
      <div class="pdv-section-head">
        <div><h2>Carrinho</h2><p class="mg-muted">Confira os itens, quantidades, observações, desconto e acréscimos.</p></div>
        <div class="mg-actions"><button type="button" class="btn pdv-prev" data-prev="produtos">Voltar</button><button type="button" class="btn btn-primary pdv-next" data-next="pagamento">Próximo: Pagamento</button></div>
      </div>
      <div class="pdv-cart-step-grid">
        <div>
          <div class="pdv-cart-items pdv-cart-items-step" id="cartItems"><div class="mg-empty">Nenhum produto no carrinho.</div></div>
          <div id="cartHidden"></div>
        </div>
        <aside class="pdv-side-summary">
          <div class="mg-form-grid">
            <label>Desconto<input name="discount_amount" id="discountAmount" inputmode="decimal" value="0,00"><?php if ($maxDiscountPercent !== null): ?><small class="mg-muted">Máx. <?= number_format((float)$maxDiscountPercent,2,',','.') ?>%</small><?php endif; ?></label>
            <label>Acréscimo<input name="addition_amount" id="additionAmount" inputmode="decimal" value="0,00"></label>
            <label class="span-2">Taxa entrega<input name="delivery_fee" id="deliveryFee" inputmode="decimal" value="0,00"></label>
            <label class="span-2">Observações<textarea name="notes" rows="3" placeholder="Observações do pedido"></textarea></label>
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
    <div class="mg-form-actions"><button type="button" class="btn" onclick="document.getElementById('pdvOptionModal').style.display='none'">Cancelar</button><button type="button" class="btn btn-primary" id="optConfirm">Adicionar ao carrinho</button></div>
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
.pdv-manual-consumer-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;margin-bottom:12px}.pdv-brand-head{display:flex;align-items:center;gap:12px}.pdv-brand-logo{width:60px;height:60px;object-fit:contain;border-radius:14px;border:1px solid #dbe4f0;background:#fff;padding:0;box-shadow:0 8px 18px rgba(15,23,42,.08)}.pdv-brand-head h2{margin:0;color:var(--admin-primary)}
.pdv-manual-consumer-head strong{display:block;color:#0b1f5b;font-size:17px}
.pdv-manual-consumer-head small{display:block;margin-top:4px;color:#475569;line-height:1.4}
.pdv-manual-consumer-head span{flex:0 0 auto;display:inline-flex;align-items:center;min-height:30px;padding:5px 10px;border-radius:999px;background:#dcfce7;color:#166534;font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.03em}
.pdv-manual-consumer-card .pdv-final-consumer-grid{margin:0}
.pdv-manual-consumer-card input{background:#fff;border-color:#93c5fd;font-weight:750}
@media(max-width:760px){.pdv-manual-consumer-card{padding:12px;border-radius:18px}.pdv-manual-consumer-head{display:block}.pdv-manual-consumer-head span{margin-top:9px}.pdv-manual-consumer-card .mg-form-grid.two{grid-template-columns:1fr}.pdv-manual-consumer-card input{min-height:50px;font-size:16px}.pdv-brand-head{align-items:flex-start}.pdv-brand-logo{width:50px;height:50px;border-radius:12px}}
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
  let customerAddresses = <?= $customerAddressesJson ?: '[]' ?>;
  const deliveryFees = <?= $deliveryFeesJson ?: '{}' ?>;
  const deliveryDefault = Number(<?= $deliveryDefaultJson ?: '0' ?> || 0);
  const defaultOrderType = <?= json_encode((string)array_key_first($pdvOrderTypes)) ?>;
  const paymentOptionsHtml = `<?php foreach (array_values($payments) as $paymentIndex => $p): ?><option value="<?= (int)$p['id'] ?>" data-payment-type="<?= gestao_h(strtoupper((string)($p['type'] ?? ''))) ?>"><?= (int)($paymentIndex + 1) ?> - <?= gestao_h($p['name']) ?></option><?php endforeach; ?>`;
  const isMobilePdv = <?= $pdvMobileMode ? 'true' : 'false' ?>;

  // TSG820 / PDV Mobile: nao abrir teclado virtual por foco programatico.
  // Ao tocar num campo, o foco nativo do navegador continua permitido.
  // O PDV tradicional mantem integralmente o comportamento de teclado/F8.
  function pdvAutoFocus(element, selectContent=false){
    if(isMobilePdv || !element) return;
    element.focus();
    if(selectContent) element.select?.();
  }
  function pdvBlurOnTouchNavigation(){
    if(!isMobilePdv) return;
    const active = document.activeElement;
    if(active && active.matches?.('input:not([type="hidden"]), textarea, select')) active.blur();
  }

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
  let currentCat = 'Todos';
  let currentProductForOptions = null;
  let selectedCustomer = null;
  let selectedAddressId = '';
  const stepOrder = <?= $pdvMobileMode ? "['cliente','endereco','produtos','carrinho','pagamento']" : "['cliente','produtos','carrinho','pagamento']" ?>;
  const todayIso = <?= json_encode(date('Y-m-d')) ?>;
  let paymentLineSeq = 0;
  let activePaymentLine = null;
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
    setTimeout(() => { pdvAutoFocus(search, true); }, 0);
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
    setTimeout(() => pdvAutoFocus(search), 0);
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
  function openModal(id){ pdvBlurOnTouchNavigation(); const el=document.getElementById(id); if(el) el.style.display='flex'; }
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
    return document.getElementById('pdvForm')?.dataset.currentStep || 'cliente';
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
    pdvBlurOnTouchNavigation();
    document.querySelectorAll('.pdv-step-panel').forEach(p => p.classList.toggle('active', p.dataset.stepPanel === step));
    document.querySelectorAll('.pdv-step-nav').forEach(b => b.classList.toggle('active', b.dataset.step === step));
    const form = document.getElementById('pdvForm');
    if(form) form.dataset.currentStep = step;
    updateTotals();
    updateMobileActionBar(step);
    if(isMobilePdv){ window.scrollTo({top: Math.max(0, form.offsetTop - 66), behavior:'smooth'}); }
    else { window.scrollTo({top: Math.max(0, form.offsetTop - 90), behavior:'smooth'}); }
    if(step === 'cliente') setTimeout(() => { pdvAutoFocus(document.getElementById('customerLookup')); }, 120);
    if(step === 'endereco') setTimeout(() => { pdvAutoFocus(document.getElementById('deliveryDistrict') || document.getElementById('deliveryAddress')); }, 120);
    if(step === 'produtos') setTimeout(() => { pdvAutoFocus(search, true); }, 120);
    if(step === 'pagamento') setTimeout(() => {
      const line = currentPaymentLine();
      const method = line?.querySelector('.pay-method');
      if(method){ setActivePaymentLine(line); pdvAutoFocus(method); }
    }, 120);
  }
  function canEnterStep(step){
    if(['carrinho','pagamento'].includes(step) && cart.size === 0){ alert('Inclua ao menos um produto antes de avançar.'); showStep('produtos'); return false; }
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
      pdvAutoFocus(input);
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
        pdvAutoFocus(input);
      }else{
        pdvAutoFocus(input, true);
      }
    }catch(error){
      if(error?.name === 'AbortError') return;
      modalProducts = [];
      modalProductOptions = {};
      modalPizzas = [];
      results.innerHTML = '<div class="pdv-product-modal-empty">' + escapeHtml(error?.message || 'Falha ao pesquisar produtos.') + '</div>';
    }
  }
  function openProductSearchModal(){
    const modal = document.getElementById('productSearchModal');
    if(!modal) return;
    const modalMode = document.getElementById('productModalSearchMode');
    const modalInput = document.getElementById('productModalSearch');
    if(modalMode) modalMode.value = currentProductSearchMode();
    if(modalInput) modalInput.value = '';
    resetProductSearchModal();
    openModal('productSearchModal');
    setTimeout(() => pdvAutoFocus(modalInput), 80);
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
    openOptions(Number(product.id), pendingProductQuantity);
  }

  function addProduct(id, cfg={}){
    const p = products.find(x => Number(x.id) === Number(id)); if(!p) return;
    const baseKey = cfg.key || String(id);
    // Produto pesável representa uma pesagem física individual. Nunca acumular
    // automaticamente com uma pesagem anterior do mesmo produto.
    const key = p.pesavel ? `${baseKey}__peso_${Date.now()}_${++weightedCartLineSeq}` : baseKey;
    const it = cart.get(key) || {id:p.id, name:cfg.displayName || p.name, qty:0, price:Number(cfg.basePrice ?? p.price) + Number(cfg.extra||0), obs:cfg.note || '', details:cfg.summary || '', splitItems: cfg.splitItems || null};
    it.qty += Number(cfg.qty || 1);
    if (cfg.splitItems) it.splitItems = cfg.splitItems;
    cart.set(key, it);
    renderCart();
    showMobileToast('Item adicionado');
  }
  function formatScaleKg(v){
    return Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:3, maximumFractionDigits:3}) + ' kg';
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
    const initialQty = Math.max(0.001, Number(requestedQty || 1));
    const groups = productOptions[p.id] || [];
    if(p.pesavel && !groups.length && !p.isPizza && !options.forceManual){ await processWeightedProduct(p); return; }
    if(!groups.length && !p.isPizza && !p.pesavel){ addProduct(p.id, {qty:initialQty}); clearProductMultiplier(); return; }
    currentProductForOptions = p;
    document.getElementById('optProductTitle').textContent = p.name + (p.pesavel ? ' (produto pesável)' : '');
    document.getElementById('optQty').value = p.pesavel ? '' : String(initialQty).replace('.', ',');
    document.getElementById('optNote').value = '';
    if(options.manualReason && p.pesavel){
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
    if(p.pesavel && !options.forceManual && window.NexaScale && Number(scaleCfg?.enabled || 0) && window.NexaScale.isSupported()){
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
  function closeOptions(){ const box=document.getElementById('pdvScaleBox'); if(box){ box.innerHTML=''; box.style.display='none'; } closeModal('pdvOptionModal'); currentProductForOptions = null; }
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
      if(checked.length < needed){ alert('Selecione as opções obrigatórias.'); return; }
      if(max > 0 && checked.length > max){ alert('Este grupo permite no máximo ' + max + ' opção(ões).'); return; }
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
      if(!halfFlavor || !halfFlavor.value){ alert('Selecione o segundo sabor da pizza.'); return; }
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
    if(currentProductForOptions.pesavel && qtyRaw <= 0){ alert('Leia o peso da balança ou informe a quantidade em kg.'); return; }
    const qty = Math.max(0.001, qtyRaw || 1);
    const note = document.getElementById('optNote').value.trim();
    const signature = [currentProductForOptions.id, selectedIds.sort().join('-'), note.toLowerCase(), displayName].join('|');
    addProduct(currentProductForOptions.id, {extra, qty, note, summary: lines.join('\n'), basePrice, displayName, splitItems, key: 'cfg_' + btoa(unescape(encodeURIComponent(signature))).replace(/=/g,'').slice(0,24)});
    clearProductMultiplier();
    closeOptions();
  }

  function syncCartHidden(){
    hidden.innerHTML = Array.from(cart.values()).map(it=>{
      const itemObs = (it.details ? it.details + '\n' : '') + (it.obs || '');
      if (it.splitItems && Array.isArray(it.splitItems) && it.splitItems.length) {
        return it.splitItems.map((sp, idx) => {
          const obs = (sp.obs === 'full' || idx === 0) ? itemObs : '';
          const qtd = Number(it.qty || 0) * Number(sp.qtyFactor || 1);
          return `<input type="hidden" name="item_product_id[]" value="${Number(sp.id||it.id)}"><input type="hidden" name="item_qty[]" value="${qtd}"><input type="hidden" name="item_price[]" value="${it.price}"><textarea name="item_obs[]" style="display:none">${escapeHtml(obs)}</textarea>`;
        }).join('');
      }
      return `<input type="hidden" name="item_product_id[]" value="${it.id}"><input type="hidden" name="item_qty[]" value="${it.qty}"><input type="hidden" name="item_price[]" value="${it.price}"><textarea name="item_obs[]" style="display:none">${escapeHtml(itemObs)}</textarea>`;
    }).join('');
  }
  function renderCart(){
    const rows=[];
    cart.forEach((it,key) => rows.push(`<div class="pdv-cart-item"><div class="pdv-cart-line"><strong>${escapeHtml(it.name)}</strong><b>${money(it.qty*it.price)}</b></div>${it.details?`<small class="mg-muted" style="display:block;white-space:pre-line;margin-top:4px;">${escapeHtml(it.details)}</small>`:''}<div class="pdv-qty"><button type="button" data-dec="${escapeHtml(key)}">−</button><input data-qty="${escapeHtml(key)}" value="${String(it.qty).replace('.',',')}"><button type="button" data-inc="${escapeHtml(key)}">+</button><button type="button" data-del="${escapeHtml(key)}" class="pdv-trash-btn" title="Remover item">🗑</button></div><input class="pdv-item-note" data-obs="${escapeHtml(key)}" value="${escapeHtml(it.obs||'')}" placeholder="${escapeHtml(productOptionDisplay.itemNoteInstruction || 'Observação do item')}"></div>`));
    cartBox.innerHTML = rows.join('') || '<div class="mg-empty">Nenhum produto no carrinho.</div>';
    syncCartHidden();
    setText('pdvItemsCount', quantitySummary(cartCount()));
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
    const fee = calcDeliveryFee();
    setText('clientDeliveryFeeView', money(fee));
    const feeInput = document.getElementById('deliveryFee');
    if(forceFee || feeInput.dataset.manual !== '1') feeInput.value = fmtInput(fee);
    updateTotals();
  }
  function orderTotal(){
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
    const discount = parseMoney(document.getElementById('discountAmount').value);
    const addition = parseMoney(document.getElementById('additionAmount').value);
    const delivery = parseMoney(document.getElementById('deliveryFee').value);
    const total = Math.max(0, sub - discount + addition + delivery);
    setText('subtotalView', money(sub));
    setText('discountView', money(discount));
    setText('addsView', money(addition + delivery));
    setText('totalView', money(total));
    setText('wizardTotalView', money(total));
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
      if(amountInput.dataset.autoSync === '1' || parseMoney(amountInput.value) <= 0){ amountInput.value = fmtInput(total); amountInput.dataset.autoSync='1'; }
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
    alert('Para finalizar venda a prazo, selecione um cliente cadastrado. A venda não foi perdida; você será direcionado para a etapa de Cliente.');
    fiscalChoiceConfirmed = !fiscalEmissionEnabled;
    showStep('cliente');
    setTimeout(() => { pdvAutoFocus(document.getElementById('customerLookup')); }, 180);
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
    if(amount) setTimeout(() => { pdvAutoFocus(amount, true); }, 0);
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
      pdvAutoFocus(next.querySelector('.pay-method'));
      updateTotals();
    }
  }
  function addPaymentLine(amount, autoSync=false, focusMethod=false){
    const box = document.getElementById('paymentLines');
    const div = document.createElement('div'); div.className = 'pdv-payment-line';
    const paymentValue = amount ?? remainingPayment();
    const key = 'p' + (++paymentLineSeq);
    div.innerHTML = `<input type="hidden" name="payment_line_key[]" class="pay-line-key" value="${key}"><label>Forma<select name="payment_method_id[]" class="pay-method" required><option value="">Selecione pelo número</option>${paymentOptionsHtml}</select></label><label class="pay-value">Valor<input name="payment_amount[]" class="pay-amount" inputmode="decimal" value="${fmtInput(paymentValue)}" data-auto-sync="${autoSync ? '1':'0'}"></label><label class="pay-received-label">Recebido<input name="payment_received[]" class="pay-received" inputmode="decimal" value="0,00"></label><button type="button" class="btn pay-remove" aria-label="Remover forma de pagamento">×</button><div class="pdv-installments"><div class="pdv-installments-head"><div><strong>Parcelas da conta a receber</strong><small>Edite vencimentos e valores. Diferenças podem ficar na última parcela.</small></div><label>Parcelas<input type="number" min="1" max="120" class="installment-count" value="1"></label><button type="button" class="btn pay-installments-generate">Gerar</button></div><div class="pdv-installments-list"><div class="pdv-installment-row head"><span>Parcela</span><span>Vencimento</span><span>Valor</span></div><div class="pdv-installments-rows"></div></div></div>`;
    box.appendChild(div);
    setActivePaymentLine(div);
    updateTotals();
    if(focusMethod) setTimeout(() => pdvAutoFocus(div.querySelector('.pay-method')), 0);
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
      setTimeout(() => { pdvAutoFocus(input, true); }, 0);
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
    setTimeout(() => pdvAutoFocus(document.querySelector('[data-next="produtos"]')), 0);
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
    setTimeout(() => pdvAutoFocus(input), 80);
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
      setTimeout(() => pdvAutoFocus(search), 0);
    }
  });
  document.getElementById('productModalSearchMode')?.addEventListener('change', event => {
    const mode = String(event.target.value || 'instant');
    if(searchMode && validSearchModes.includes(mode)) searchMode.value = mode;
    try { localStorage.setItem(searchModeStorageKey, mode); } catch(e) {}
    const modalInput = document.getElementById('productModalSearch');
    if(modalInput) modalInput.value = '';
    resetProductSearchModal();
    setTimeout(() => pdvAutoFocus(modalInput), 0);
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
    setTimeout(() => pdvAutoFocus(search), 0);
  });
  cartBox.addEventListener('click', e => { const inc=e.target.closest('[data-inc]'), dec=e.target.closest('[data-dec]'), del=e.target.closest('[data-del]'); const id = inc?.dataset.inc || dec?.dataset.dec || del?.dataset.del; if(!id) return; const it=cart.get(id); if(!it) return; if(inc) it.qty += 1; if(dec) it.qty = Math.max(0, it.qty-1); if(del || it.qty<=0) cart.delete(id); else cart.set(id, it); renderCart(); });
  cartBox.addEventListener('input', e => { const q=e.target.closest('[data-qty]'), o=e.target.closest('[data-obs]'); if(q){ const id=q.dataset.qty; const it=cart.get(id); if(it){it.qty=Math.max(0, parseMoney(q.value)); if(it.qty<=0) cart.delete(id); else cart.set(id,it); renderCart();}} if(o){ const id=o.dataset.obs; const it=cart.get(id); if(it){it.obs=o.value; cart.set(id,it); syncCartHidden();}} });
  ['discountAmount','additionAmount'].forEach(id=>document.getElementById(id).addEventListener('input', updateTotals));
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
      setTimeout(() => pdvAutoFocus(document.getElementById('customerLookup')), 0);
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
  document.querySelectorAll('[data-close-modal]').forEach(b => b.addEventListener('click', () => closeModal(b.dataset.closeModal)));

  document.getElementById('customerQuickForm').addEventListener('submit', async function(e){
    e.preventDefault();
    try{
      const fd = new FormData(this); const r = await fetch(location.href, {method:'POST', body:fd}); const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'Falha ao cadastrar cliente.');
      const c = data.customer; const idx = customers.findIndex(x=>Number(x.id)===Number(c.id)); if(idx >= 0) customers[idx]=c; else customers.push(c);
      customerAddresses = customerAddresses.filter(a => Number(a.person_id) !== Number(c.id)).concat(data.addresses || []);
      closeModal('customerModal'); selectCustomer(c); showStep('cliente');
    }catch(err){ alert(err.message || err); }
  });
  document.getElementById('addressQuickForm').addEventListener('submit', async function(e){
    e.preventDefault(); if(!selectedCustomer) return;
    try{
      const fd = new FormData(this); const r = await fetch(location.href, {method:'POST', body:fd}); const data = await r.json();
      if(!data.ok) throw new Error(data.error || 'Falha ao cadastrar endereço.');
      customerAddresses = customerAddresses.filter(a => Number(a.person_id) !== Number(selectedCustomer.id)).concat(data.addresses || []);
      closeModal('addressModal'); renderAddressSelect();
      if(data.address_id){ document.getElementById('customerAddressSelect').value = String(data.address_id); const a=customerAddresses.find(x=>Number(x.id)===Number(data.address_id)); if(a) applyAddress(a); }
    }catch(err){ alert(err.message || err); }
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
  document.getElementById('paymentLines').addEventListener('input', e => { if(e.target && e.target.classList.contains('pay-amount')){ e.target.dataset.autoSync = '0'; renderInstallments(e.target.closest('.pdv-payment-line'), true); } updateTotals(); });
  document.getElementById('paymentLines').addEventListener('change', e => {
    if(e.target && (e.target.classList.contains('pay-method') || e.target.classList.contains('installment-count'))){
      const line = e.target.closest('.pdv-payment-line');
      if(e.target.classList.contains('pay-method')){
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
    const m=document.getElementById('creditOverrideModal'); if(!m) return; document.getElementById('creditOverrideMessage').textContent=`Limite: ${money(info.limit)} · Em aberto: ${money(info.open)} · Disponível: ${money(info.available)} · Nova compra a prazo: ${money(info.amount)}.`; m.style.display='flex'; setTimeout(()=>pdvAutoFocus(document.getElementById('creditOverrideCodeInput')),60);
  }
  function closeCreditOverride(){const m=document.getElementById('creditOverrideModal');if(m)m.style.display='none';}
  document.getElementById('creditOverrideClose')?.addEventListener('click',closeCreditOverride); document.getElementById('creditOverrideCancel')?.addEventListener('click',closeCreditOverride);
  document.getElementById('creditOverrideConfirm')?.addEventListener('click',async()=>{const c=document.getElementById('creditOverrideCodeInput')?.value.trim()||'',p=document.getElementById('creditOverridePasswordInput')?.value||'';if(!c||!p){alert('Informe o código do usuário e a senha.');return;}const b=document.getElementById('creditOverrideConfirm');if(b){b.disabled=true;b.textContent='Validando...';}try{const fd=new FormData();fd.append('code',c);fd.append('password',p);fd.append('mc_csrf','<?= gestao_h($pdvMcCsrf) ?>');fd.append('mc_ctx','<?= gestao_h($pdvMcCtx) ?>');const r=await fetch('<?= gestao_h($pdvContextUrl('commercial_credit_authorize.php')) ?>',{method:'POST',body:fd,credentials:'same-origin'});const j=await r.json().catch(()=>({}));if(!r.ok||!j.ok)throw new Error(j.error||'Não foi possível validar a autorização.');setVal('creditOverrideUserCode',c);setVal('creditOverridePassword',p);creditOverrideConfirmed=true;closeCreditOverride();document.getElementById('pdvForm')?.requestSubmit();}catch(err){alert(err.message||'Falha ao validar autorização.');}finally{if(b){b.disabled=false;b.textContent='Liberar venda';}}});
  document.getElementById('pdvForm').addEventListener('submit', function(e){
    if(!window.NexaDeliveryFutureConfirmed){const future=Array.from(cart.values()).filter(it=>Number(it.futureQty||0)>0.00001);if(future.length){e.preventDefault();const lines=future.map(it=>{const f=Number(it.futureQty||0),q=Number(it.qty||0);return '• '+String(it.name||'Item')+' — agora '+Math.max(0,q-f).toLocaleString('pt-BR',{maximumFractionDigits:4})+' / futuro '+f.toLocaleString('pt-BR',{maximumFractionDigits:4})});if(confirm('CONFIRMAÇÃO DE ENTREGA\n\n'+lines.join('\n')+'\n\nO saldo futuro permanecerá reservado e será baixado somente ao gerar a Ordem de Entrega.')){window.NexaDeliveryFutureConfirmed=true;this.requestSubmit();}return;}}
    if(saleFinalizationBlocked){ e.preventDefault(); alert('Este perfil não pode finalizar a venda.'); return; }
    if(!selectedCustomer){ setVal('customerName', document.getElementById('finalConsumerNameInput')?.value.trim() || 'Consumidor final'); }
    syncAddressHidden(true);
    if(cart.size === 0){ e.preventDefault(); alert('Inclua ao menos um produto.'); showStep('produtos'); return; }
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
    if(!hasMethod){ e.preventDefault(); alert('Selecione todas as formas de pagamento.'); return; }
    if(!requireCustomerForCreditSale()){ e.preventDefault(); return; }
    if(!installmentOk){ e.preventDefault(); alert('Confira as parcelas: vencimentos obrigatórios e soma dos valores igual ao valor da forma de pagamento.'); return; }
    if(Math.abs(total - sum) > 0.01){ e.preventDefault(); alert('A soma das formas de pagamento precisa fechar o total do pedido.'); return; }
    if(commercialMaxDiscountPercent !== null){const sub=cartSubtotal();const disc=parseMoney(document.getElementById('discountAmount')?.value||'0');const max=sub*Number(commercialMaxDiscountPercent)/100;if(disc>max+0.009){e.preventDefault();alert(`Desconto acima do limite do usuário (${Number(commercialMaxDiscountPercent).toFixed(2).replace('.',',')}%). Máximo: ${money(max)}.`);showStep('carrinho');return;}}
    const creditInfo=creditNeedsAuthorization(); if(creditInfo && !creditOverrideConfirmed){e.preventDefault();showCreditOverride(creditInfo);return;}
    if(document.getElementById('orderType').value === 'entrega' && !document.getElementById('orderDistrict').value.trim()){
      if(!confirm('Pedido de entrega sem bairro informado. Deseja continuar?')){ e.preventDefault(); showStep(stepOrder.includes('endereco') ? 'endereco' : 'cliente'); return; }
    }
    // V11.52.6.1: na Venda Mobile/Pedido PDV, o Pix precisa ser confirmado ANTES
    // da escolha do documento fiscal. O componente compartilhado só é chamado aqui,
    // depois das validações comerciais acima, evitando sobreposição de modais.
    if(typeof this.nexaSalesPixEnsurePaid === 'function' && this.nexaSalesPixEnsurePaid()){
      e.preventDefault();
      return;
    }
    if(fiscalEmissionEnabled && !fiscalChoiceConfirmed){
      e.preventDefault();
      openModal('pdvFiscalModal');
      return;
    }
    if(window.adminShowProcessing){
      const emit = document.getElementById('emitDocAfter')?.value || '';
      window.adminShowProcessing(emit === 'nfe' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Montando XML da NF-e...', 'Assinando documento fiscal...', 'Transmitindo para a SEFAZ...', 'Registrando retorno da autorização...', 'Preparando DANFE para impressão...'] : (emit === 'nfce' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Montando XML da NFC-e...', 'Assinando cupom fiscal eletrônico...', 'Transmitindo para a SEFAZ...', 'Registrando retorno da autorização...', 'Preparando DANFE NFC-e para impressão...'] : (emit === 'nfse' ? ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Gerando e assinando a DPS...', 'Transmitindo a NFS-e...', 'Registrando autorização...', 'Preparando documento para visualização...'] : ['Conferindo dados do pedido...', 'Gravando venda no sistema...', 'Atualizando estoque e financeiro...', 'Finalizando retorno para o PDV...'])));
    }
  });

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
  document.addEventListener('keydown', e => {
    if(pdvFiscalModalOpen()){
      if(e.key === '1' && document.querySelector('[data-pdv-fiscal="nfce"]')){ e.preventDefault(); finishFiscalChoice('nfce'); return; }
      if(e.key === '2' && document.querySelector('[data-pdv-fiscal="nfe"]')){ e.preventDefault(); finishFiscalChoice('nfe'); return; }
      if(e.key === '3' && document.querySelector('[data-pdv-fiscal="nfse"]')){ e.preventDefault(); finishFiscalChoice('nfse'); return; }
      if(e.key === 'Escape' && document.querySelector('[data-pdv-fiscal="none"]')){ e.preventDefault(); finishFiscalChoice('none'); return; }
      return;
    }
    if(e.key === 'F8' && !isMobilePdv){
      e.preventDefault();
      const customerModal = document.getElementById('customerSearchModal');
      const productModal = document.getElementById('productSearchModal');
      if(customerModal && customerModal.style.display !== 'none'){
        pdvAutoFocus(document.getElementById('customerModalSearch'));
      }else if(productModal && productModal.style.display !== 'none'){
        pdvAutoFocus(document.getElementById('productModalSearch'));
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
    if(shortcuts[e.key]){
      e.preventDefault();
      const step = shortcuts[e.key];
      if(canEnterStep(step)) showStep(step);
      return;
    }
    if(e.key === 'F10'){
      e.preventDefault();
      const pdvForm = document.getElementById('pdvForm');
      if(pdvForm?.requestSubmit) pdvForm.requestSubmit(); else pdvForm?.submit();
    }
  });
  document.getElementById('pdvFiscalModal')?.addEventListener('click', e => { if(e.target.id === 'pdvFiscalModal') closeModal('pdvFiscalModal'); });

  window.NexaOrcamentoApply = async function(data){
    const quote = data?.quote || {}, items = Array.isArray(data?.items) ? data.items : [], pays = Array.isArray(data?.payments) ? data.payments : [], customer = data?.customer || {};
    if(!quote.id) throw new Error('Orçamento inválido.');
    const missing = items.filter(it => !Number(it.current_product_id || 0) || Number(it.current_active || 0) !== 1);
    if(missing.length){
      alert('Existem produtos removidos ou inativos no orçamento. Regularize-os antes de carregar o pedido.');
      return false;
    }
    if(cart.size && !confirm('O carrinho atual será substituído pelos itens do orçamento #' + (quote.quote_number || quote.id) + '. Deseja continuar?')) return false;

    setVal('orcamentoId', quote.id);
    cart.clear();
    items.forEach(it => {
      const qty = Math.max(0.0001, Number(it.quantity || 0));
      const total = Number(it.total || 0);
      const unit = qty > 0 ? total / qty : Number(it.unit_price || 0);
      const key = 'orc_' + quote.id + '_' + it.id;
      cart.set(key, {id:Number(it.current_product_id || it.produto_id), code:String(it.current_product_number||it.product_code_snapshot||it.current_product_legacy_code||it.current_product_id||it.produto_id||''), name:String(it.description_snapshot || it.current_product_name || 'Item'), qty:qty, price:unit, obs:String(it.notes || ''), details:'', splitItems:null, futureQty:Number(it.future_quantity||0)});
    });

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
    setTimeout(()=>pdvAutoFocus(search),80);
    return true;
  };

  renderProducts(); renderCart(); addPaymentLine(orderTotal(), true, false); setConsumerFinal(false); syncAddressHidden(true); renderInlineCustomerList(''); showStep('cliente');
})();
</script>
<?php if ($orcamentoReadEnabled) require __DIR__ . '/_orcamento_pedido_reader.php'; ?>
<?php
$pixSalesWidget = [
  'form_id'=>'pdvForm',
  'line_selector'=>'.pdv-payment-line',
  'method_selector'=>'.pay-method',
  'amount_selector'=>'.pay-amount',
  'payments'=>$payments,
  'self_url'=>$pdvContextUrl($pdvBaseUrl),
  'mc_csrf'=>$pdvMcCsrf,
  'mc_ctx'=>$pdvMcCtx,
  'label'=>$pdvLabel . ' · Pagamento Pix',
];
require __DIR__ . '/_pix_sales_payment.php';
require __DIR__ . '/_card_installment_picker.php';
?>
<?php $voucherPaymentScope = $pdvMobileMode ? 'pdv_mobile' : 'pdv'; require __DIR__ . '/_voucher_payment_selector.php'; ?>
<?php gestao_admin_end(); ?>
