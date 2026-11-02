<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/fiscal_entry_edit_module.php';

$cid = fiscal_v10_entry_company_id($pdo);
$entryCrossContext = fiscal_v10_entry_is_cross_context($pdo);
$entryCompanyName = fiscal_v10_entry_company_name($pdo, $cid);
$entryAllowFinance = !$entryCrossContext;
$xmlEntryCfg = (array)(($ADMIN_BUSINESS_CFG['fiscal_xml_entry'] ?? []));
$xmlPricingPolicy = strtolower(trim((string)($xmlEntryCfg['pricing_policy'] ?? 'keep_margin')));
if (!in_array($xmlPricingPolicy, ['keep_margin', 'keep_price'], true)) $xmlPricingPolicy = 'keep_margin';
fiscal_xml_ensure_schema($pdo);
try { if ($entryAllowFinance) finance_ensure_schema($pdo); fiscal_entry_edit_ensure_schema($pdo); } catch (Throwable $e) {}
$id = (int)($_GET['id'] ?? ($_POST['id'] ?? 0));
$err = '';
$pricingReviewActive = false;
$pricingReview = [];
$pricingPending = [];
$entryContextBlocked = false;
if ($entryCrossContext && $id > 0) {
  try {
    $entryCheck = fiscal_xml_get($pdo, $cid, $id);
    // V10.1: a validação de destinatário é exclusiva de NF-e/XML. A entrada manual
    // nasce diretamente dentro da empresa selecionada pelo F11 e não possui buyer_doc.
    if ($entryCheck && (int)($entryCheck['is_manual'] ?? 0) !== 1) {
      fiscal_v10_assert_xml_recipient($pdo, $cid, $entryCheck);
    }
  } catch (Throwable $e) {
    $err = $e->getMessage();
    $entryContextBlocked = true;
  }
}

if (!function_exists('fiscal_xml_apply_product_profile_from_confirm')) {
  function fiscal_xml_apply_product_profile_from_confirm(PDO $pdo, int $companyId, int $productId, int $taxProfileId): array {
    if ($productId <= 0 || $taxProfileId <= 0) return [];
    $profile = fiscal_tax_profile($pdo, $companyId, $taxProfileId);
    if (!$profile) throw new RuntimeException('A tributação de saída selecionada não foi encontrada.');
    if (function_exists('fiscal_normalize_item_type') && fiscal_normalize_item_type($profile['profile_type'] ?? 'PRODUCT') !== 'PRODUCT') {
      throw new RuntimeException('Selecione uma tributação do tipo Produto.');
    }
    if (!fiscal_table_exists($pdo, 'fiscal_product_tax')) return [];
    $map = [
      'tax_profile_id' => $taxProfileId, 'item_type' => 'PRODUCT', 'tax_mode' => (string)($profile['tax_mode'] ?? ''),
      'icms_rate' => (float)($profile['icms_rate'] ?? 0), 'icms_st_rate' => (float)($profile['icms_st_rate'] ?? 0),
      'icms_cst' => (string)($profile['icms_cst'] ?? ''), 'icms_csosn' => (string)($profile['icms_csosn'] ?? ''),
      'ipi_rate' => (float)($profile['ipi_rate'] ?? 0), 'ipi_cst' => (string)($profile['ipi_cst'] ?? ''),
      'pis_rate' => (float)($profile['pis_out_rate'] ?? 0), 'pis_cst' => (string)($profile['pis_out_cst'] ?? ''),
      'cofins_rate' => (float)($profile['cofins_out_rate'] ?? 0), 'cofins_cst' => (string)($profile['cofins_out_cst'] ?? ''),
      'cbs_rate' => (float)($profile['cbs_rate'] ?? 0), 'ibs_uf_rate' => (float)($profile['ibs_uf_rate'] ?? 0), 'ibs_mun_rate' => (float)($profile['ibs_mun_rate'] ?? 0),
      'ibs_cbs_rate_mode' => (string)($profile['ibs_cbs_rate_mode'] ?? ''), 'ibs_cbs_cst' => (string)($profile['ibs_cbs_cst'] ?? ''),
      'ibs_cbs_classification_code' => (string)($profile['ibs_cbs_classification_code'] ?? ''), 'ibs_cbs_classification_name' => (string)($profile['ibs_cbs_classification_name'] ?? ''),
      'cfop_sale_state' => (string)($profile['cfop_sale_state'] ?? ''), 'cfop_sale_interstate' => (string)($profile['cfop_sale_interstate'] ?? ''),
      'cfop_return_state' => (string)($profile['cfop_return_state'] ?? ''), 'cfop_return_interstate' => (string)($profile['cfop_return_interstate'] ?? ''),
      'cfop_entry_state' => (string)($profile['cfop_entry_state'] ?? ''), 'cfop_entry_interstate' => (string)($profile['cfop_entry_interstate'] ?? ''),
    ];
    $data = [];
    foreach ($map as $col => $value) if (fiscal_column_exists($pdo, 'fiscal_product_tax', $col)) $data[$col] = $value === '' ? null : $value;
    if (!$data) return $profile;
    $check = $pdo->prepare('SELECT id FROM fiscal_product_tax WHERE company_id=? AND product_id=? LIMIT 1');
    $check->execute([$companyId, $productId]);
    $existingId = (int)($check->fetchColumn() ?: 0);
    if ($existingId > 0) {
      $sets = []; $vals = [];
      foreach ($data as $col => $value) { $sets[] = '`' . str_replace('`','``',$col) . '`=?'; $vals[] = $value; }
      $vals[] = $companyId; $vals[] = $productId;
      $pdo->prepare('UPDATE fiscal_product_tax SET ' . implode(',', $sets) . ' WHERE company_id=? AND product_id=?')->execute($vals);
    } else {
      $cols = ['company_id','product_id']; $vals = [$companyId,$productId];
      foreach ($data as $col => $value) { $cols[] = $col; $vals[] = $value; }
      $pdo->prepare('INSERT INTO fiscal_product_tax (`' . implode('`,`',$cols) . '`) VALUES (' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute($vals);
    }
    return $profile;
  }
}

if ((string)($_GET['ajax'] ?? '') === 'products') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($entryContextBlocked) throw new RuntimeException($err ?: 'Entrada bloqueada pelo contexto Multiempresa.');
    $q = trim((string)($_GET['q'] ?? ''));
    $mode = strtolower(trim((string)($_GET['mode'] ?? 'instant')));
    if (!in_array($mode, ['instant','code','description','barcode'], true)) $mode = 'instant';
    if ($mode === 'instant' || $q === '') {
      $rows = fiscal_xml_products_options($pdo, $cid, $q, 80);
    } else {
      $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
      $hasStock = fiscal_column_exists($pdo, 'produtos', 'estoque_atual');
      $hasNumber = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
      $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
      $hasReference = fiscal_column_exists($pdo, 'produtos', 'referencia');
      $barcodeCols = array_values(array_filter(['codigo_barras','ean','gtin','barcode'], static fn($c) => fiscal_column_exists($pdo, 'produtos', $c)));
      $select = 'p.id, p.nome, p.categoria, p.preco';
      if ($hasNumber) $select .= ', p.numero_cadastro';
      if ($hasCode) $select .= ', p.codigo_firebird';
      if ($hasReference) $select .= ', p.referencia';
      if ($hasCost) $select .= ', p.custo';
      if ($hasStock) $select .= ', p.estoque_atual, p.unidade_estoque';
      foreach ($barcodeCols as $bc) $select .= ', p.`' . str_replace('`','``',$bc) . '`';
      $where = ['p.company_id = ?'];
      $params = [$cid];
      if ($mode === 'description') {
        $where[] = 'p.nome LIKE ?';
        $params[] = nexa_search_like($q);
      } elseif ($mode === 'code') {
        $parts = ['CAST(p.id AS CHAR) LIKE ?'];
        $params[] = $q . '%';
        if ($hasNumber) { $parts[] = 'CAST(p.numero_cadastro AS CHAR) LIKE ?'; $params[] = $q . '%'; }
        if ($hasCode) { $parts[] = 'p.codigo_firebird LIKE ?'; $params[] = $q . '%'; }
        if ($hasReference) { $parts[] = 'p.referencia LIKE ?'; $params[] = $q . '%'; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
      } elseif ($mode === 'barcode') {
        if (!$barcodeCols) {
          $where[] = '1 = 0';
        } else {
          $parts = [];
          foreach ($barcodeCols as $bc) { $parts[] = 'p.`' . str_replace('`','``',$bc) . '` LIKE ?'; $params[] = $q . '%'; }
          $where[] = '(' . implode(' OR ', $parts) . ')';
        }
      }
      $order = $mode === 'description' ? 'p.nome ASC' : 'p.id ASC';
      $sql = 'SELECT ' . $select . ' FROM produtos p WHERE ' . implode(' AND ', $where) . ' ORDER BY ' . $order . ' LIMIT 80';
      $st = $pdo->prepare($sql); $st->execute($params); $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
    $taxByProduct = [];
    if ($rows && fiscal_table_exists($pdo, 'fiscal_product_tax')) {
      $productIds = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $rows)));
      if ($productIds) {
        $ph = implode(',', array_fill(0, count($productIds), '?'));
        $taxSt = $pdo->prepare('SELECT * FROM fiscal_product_tax WHERE company_id=? AND product_id IN (' . $ph . ')');
        $taxSt->execute(array_merge([$cid], $productIds));
        foreach (($taxSt->fetchAll(PDO::FETCH_ASSOC) ?: []) as $taxRow) $taxByProduct[(int)$taxRow['product_id']] = $taxRow;
      }
    }
    $out = [];
    foreach ($rows as $r) {
      $productTax = $taxByProduct[(int)($r['id'] ?? 0)] ?? [];
      $barcode = '';
      foreach (['codigo_barras','ean','gtin','barcode'] as $bc) {
        if (!empty($r[$bc])) { $barcode = (string)$r[$bc]; break; }
      }
      $out[] = [
        'id' => (int)($r['id'] ?? 0),
        'name' => (string)($r['nome'] ?? ''),
        'code' => (($n=trim((string)($r['numero_cadastro'] ?? ''))) !== '' && $n !== '0') ? $n : (trim((string)($r['codigo_firebird'] ?? ''))!=='' ? (string)$r['codigo_firebird'] : (string)($r['id'] ?? '')),
        'barcode' => $barcode,
        'reference' => trim((string)($r['referencia'] ?? '')),
        'category' => (string)($r['categoria'] ?? ''),
        'price' => (float)($r['preco'] ?? 0),
        'cost' => isset($r['custo']) ? (float)$r['custo'] : 0.0,
        'stock' => isset($r['estoque_atual']) ? (float)$r['estoque_atual'] : null,
        'unit' => (string)($r['unidade_estoque'] ?? ''),
        'tax_profile_id' => (int)($productTax['tax_profile_id'] ?? 0),
        'tax_origin' => (string)($productTax['origem'] ?? ''),
        'tax_icms_cst' => (string)($productTax['icms_cst'] ?? ''),
        'tax_icms_csosn' => (string)($productTax['icms_csosn'] ?? ''),
        'tax_ipi_cst' => (string)($productTax['ipi_cst'] ?? ''),
        'tax_pis_cst' => (string)($productTax['pis_cst'] ?? ''),
        'tax_cofins_cst' => (string)($productTax['cofins_cst'] ?? ''),
        'tax_cfop_sale_state' => (string)($productTax['cfop_sale_state'] ?? ''),
        'tax_cfop_sale_interstate' => (string)($productTax['cfop_sale_interstate'] ?? ''),
      ];
    }
    echo json_encode(['ok'=>true,'rows'=>$out], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'confirm_product_link' && (string)($_POST['ajax'] ?? '') === '1') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if ($entryContextBlocked) throw new RuntimeException($err ?: 'Entrada bloqueada pelo contexto Multiempresa.');
    $itemId = (int)($_POST['item_id'] ?? 0);
    $productId = (int)($_POST['product_id'] ?? 0);
    $factor = fiscal_xml_parse_decimal($_POST['conversion_factor'] ?? 1, 1.0);
    $result = fiscal_xml_confirm_product_link($pdo, $cid, $id, $itemId, $productId, $factor, $_POST);
    // O vínculo do item não altera mais custo/preço do cadastro antes da entrada ser gerada.
    // A atualização comercial é concentrada na Tela de Preços para manter o processo auditável.
    $newCost = (float)($result['stock_unit_cost'] ?? 0);
    $newSalePrice = fiscal_xml_parse_decimal($_POST['new_sale_price'] ?? '', -1.0);
    $productTaxProfileId = (int)($_POST['product_tax_profile_id'] ?? 0);
    if ($productTaxProfileId > 0) {
      $appliedProfile = fiscal_xml_apply_product_profile_from_confirm($pdo, $cid, $productId, $productTaxProfileId);
      $result['product_tax_profile_id'] = $productTaxProfileId;
      $result['product_tax_profile_name'] = trim((string)($appliedProfile['code'] ?? '') . ' - ' . (string)($appliedProfile['name'] ?? ''), ' -');
    }
    $result['product_cost_preview'] = max(0, $newCost);
    $result['product_sale_price_preview'] = $newSalePrice >= 0 ? max(0, $newSalePrice) : null;
    echo json_encode(['ok'=>true, 'result'=>$result], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false, 'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  exit;
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($entryContextBlocked) throw new RuntimeException($err ?: 'Entrada bloqueada pelo contexto Multiempresa.');
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'undo_entry') {
      $reason = trim((string)($_POST['undo_reason'] ?? ''));
      fiscal_entry_undo($pdo, $cid, $id, fiscal_entry_edit_user_id(), $reason);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode($entryCrossContext ? 'Entrada desfeita. O estoque da empresa selecionada foi revertido; a nota voltou para conferência.' : 'Entrada desfeita. Estoque e Contas a Pagar foram revertidos; a nota voltou para conferência.') . '#editarEntrada'));
      exit;
    }
    if ($action === 'delete_entry') {
      fiscal_entry_delete($pdo, $cid, $id);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_entrada_notas.php?ok=' . rawurlencode('Nota de entrada excluída.')));
      exit;
    }
    if ($action === 'save_entry_header') {
      fiscal_entry_save_header($pdo, $cid, $id, $_POST, fiscal_entry_edit_user_id());
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Dados gerais da nota atualizados.') . '#editarEntrada'));
      exit;
    }
    if ($action === 'save_entry_items') {
      if ($entryCrossContext) {
        $postedProductIds = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['edit_product_id'] ?? [])), static fn($v) => $v > 0)));
        if ($postedProductIds) {
          $ph = implode(',', array_fill(0, count($postedProductIds), '?'));
          $stValidProducts = $pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND id IN (' . $ph . ')');
          $stValidProducts->execute(array_merge([$cid], $postedProductIds));
          $validProductIds = array_map('intval', $stValidProducts->fetchAll(PDO::FETCH_COLUMN) ?: []);
          if (count($validProductIds) !== count($postedProductIds)) {
            throw new RuntimeException('V10 Multiempresa: foi detectado produto que não pertence à empresa selecionada pelo F11. A gravação foi bloqueada.');
          }
        }
      }
      $currentXml = fiscal_xml_get($pdo, $cid, $id);
      $allowStockFields = $currentXml && (string)($currentXml['status'] ?? '') !== 'ENTRADA_GERADA';
      $saved = fiscal_entry_save_items($pdo, $cid, $id, $_POST, $allowStockFields, fiscal_entry_edit_user_id());
      $message = $allowStockFields ? 'Itens e tributação atualizados.' : 'Tributação dos itens atualizada. Para alterar produto, quantidade, fator ou custo, desfaça a nota primeiro.';
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode($message . ' Linhas: ' . $saved) . '#editarEntrada'));
      exit;
    }
    if ($action === 'save_dates') {
      $issueDate = fiscal_xml_date($_POST['issue_date_br'] ?? '');
      $entryDate = fiscal_xml_date($_POST['entry_date_br'] ?? '');
      if (!$issueDate) throw new RuntimeException('Informe a data de emissão no formato dd-mm-aaaa.');
      if (!$entryDate) throw new RuntimeException('Informe a data de entrada no formato dd-mm-aaaa.');
      fiscal_xml_update_dates($pdo, $cid, $id, $issueDate, $entryDate);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Datas fiscais atualizadas.')));
      exit;
    }
    if ($action === 'save_links') {
      $saved = fiscal_xml_save_item_links($pdo, $cid, $id, $_POST);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Vínculos dos itens salvos. Linhas processadas: ' . $saved) . '#itensXml'));
      exit;
    }
    if ($action === 'quick_create_product') {
      $itemId = (int)($_POST['quick_item_id'] ?? 0);
      $categoryByItem = (array)($_POST['quick_category'] ?? []);
      $factorByItem = (array)($_POST['conversion_factor'] ?? []);
      $factor = fiscal_xml_parse_decimal($factorByItem[$itemId] ?? 1, 1.0);
      $newProductId = fiscal_xml_create_product_from_item($pdo, $cid, $id, $itemId, (string)($categoryByItem[$itemId] ?? ''), $factor);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Produto cadastrado e vinculado ao item do XML: #' . $newProductId) . '#itensXml'));
      exit;
    }
    if ($action === 'modal_create_product') {
      $itemId = (int)($_POST['quick_modal_item_id'] ?? 0);
      $factor = fiscal_xml_parse_decimal($_POST['quick_modal_factor'] ?? 1, 1.0);
      $newProductId = fiscal_xml_create_product_from_item($pdo, $cid, $id, $itemId, (string)($_POST['quick_modal_category'] ?? ''), $factor, [
        'name' => (string)($_POST['quick_modal_name'] ?? ''),
        'description' => (string)($_POST['quick_modal_description'] ?? ''),
        'fiscal_notes' => (string)($_POST['quick_modal_fiscal_notes'] ?? ''),
        'unit' => (string)($_POST['quick_modal_unit'] ?? ''),
        'sale_price' => $_POST['quick_modal_sale_price'] ?? 0,
        'barcode' => (string)($_POST['quick_modal_barcode'] ?? ''),
        'supplier_code' => (string)($_POST['quick_modal_supplier_code'] ?? ''),
        'ncm' => (string)($_POST['quick_modal_ncm'] ?? ''),
        'cest' => (string)($_POST['quick_modal_cest'] ?? ''),
        'entry_cfop' => (string)($_POST['quick_modal_entry_cfop'] ?? ''),
        'entry_origem' => (string)($_POST['quick_modal_entry_origem'] ?? ''),
        'entry_icms_cst' => (string)($_POST['quick_modal_entry_icms_cst'] ?? ''),
        'entry_icms_csosn' => (string)($_POST['quick_modal_entry_icms_csosn'] ?? ''),
        'entry_ipi_cst' => (string)($_POST['quick_modal_entry_ipi_cst'] ?? ''),
        'entry_pis_cst' => (string)($_POST['quick_modal_entry_pis_cst'] ?? ''),
        'entry_cofins_cst' => (string)($_POST['quick_modal_entry_cofins_cst'] ?? ''),
        'product_origem' => (string)($_POST['quick_modal_product_origem'] ?? ''),
        'tax_profile_id' => (int)($_POST['quick_modal_tax_profile_id'] ?? 0),
        'category_hide_order_catalog' => !empty($_POST['quick_modal_category_hide_order_catalog']) ? 1 : 0,
        'category_hide_product_catalog' => !empty($_POST['quick_modal_category_hide_product_catalog']) ? 1 : 0,
        'image_file' => $_FILES['quick_modal_image'] ?? null,
        'cost' => $_POST['quick_modal_cost'] ?? null,
      ]);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode('Produto cadastrado pelo modal e vinculado ao item do XML: #' . $newProductId) . '#itensXml'));
      exit;
    }
    if ($action === 'generate_entry') {
      $operationId = (int)($_POST['operation_id'] ?? 0);
      $opCheck = fiscal_operation($pdo, $cid, $operationId);
      if (!$opCheck || (int)($opCheck['is_active'] ?? 0) !== 1 || (string)($opCheck['direction'] ?? '') !== 'ENTRADA') {
        throw new RuntimeException('Selecione uma operação de entrada válida da empresa atual.');
      }
      if ($entryCrossContext && (int)($opCheck['update_stock'] ?? 0) !== 1) {
        throw new RuntimeException('V10 Multiempresa: no contexto F11, a operação escolhida precisa estar configurada para movimentar estoque.');
      }

      if ((int)($opCheck['update_stock'] ?? 0) === 1) {
        $unlinkedNames = [];
        foreach (fiscal_xml_items($pdo, $cid, $id) as $checkItem) {
          if ((float)($checkItem['quantity'] ?? 0) > 0 && (int)($checkItem['product_id'] ?? 0) <= 0) {
            $unlinkedNames[] = (string)($checkItem['name'] ?? ('Item #' . (int)($checkItem['item_number'] ?? 0)));
          }
        }
        if ($unlinkedNames) {
          throw new RuntimeException('Vincule todos os itens do XML a produtos do cadastro antes de revisar preços e gerar a entrada. Pendente(s): ' . implode(', ', array_slice($unlinkedNames, 0, 6)) . (count($unlinkedNames) > 6 ? '...' : ''));
        }
      }

      $enablePricingScreen = !empty($_POST['enable_pricing_screen']);
      $pricingConfirmed = !empty($_POST['pricing_confirmed']);
      if ($enablePricingScreen && !$pricingConfirmed && (int)($opCheck['update_stock'] ?? 0) === 1) {
        $pricingReview = fiscal_xml_pricing_preview($pdo, $cid, $id, $xmlPricingPolicy);
        if ($pricingReview) {
          $pricingReviewActive = true;
          $pricingPending = [
            'operation_id' => $operationId,
            'chart_account_id' => (int)($_POST['chart_account_id'] ?? 0),
            'cost_center_id' => (int)($_POST['cost_center_id'] ?? 0),
            'fallback_due_date' => fiscal_xml_date($_POST['fallback_due_date'] ?? ''),
          ];
        }
      }

      if (!$pricingReviewActive) {
        $pricingOverrides = [];
        if ($pricingConfirmed) {
          $postedCosts = (array)($_POST['pricing_cost'] ?? []);
          $postedMargins = (array)($_POST['pricing_margin'] ?? []);
          $postedPrices = (array)($_POST['pricing_price'] ?? []);
          foreach ($postedCosts as $productIdRaw => $costRaw) {
            $productId = (int)$productIdRaw;
            if ($productId <= 0) continue;
            $cost = max(0.0, fiscal_xml_parse_decimal($costRaw, 0.0));
            $priceRaw = trim((string)($postedPrices[$productIdRaw] ?? ''));
            $marginRaw = trim((string)($postedMargins[$productIdRaw] ?? ''));
            if ($priceRaw !== '') {
              $price = max(0.0, fiscal_xml_parse_decimal($priceRaw, 0.0));
            } elseif ($marginRaw !== '' && $cost > 0) {
              $margin = fiscal_xml_parse_decimal($marginRaw, 0.0);
              $price = max(0.0, round($cost * (1.0 + ($margin / 100.0)), 2));
            } else {
              $price = 0.0;
            }
            $pricingOverrides[$productId] = ['cost' => $cost, 'price' => $price];
          }
        }

        $created = fiscal_xml_generate_entry(
          $pdo,
          $cid,
          $id,
          $operationId,
          (int)($_POST['chart_account_id'] ?? 0),
          (int)($_POST['cost_center_id'] ?? 0) ?: null,
          fiscal_xml_date($_POST['fallback_due_date'] ?? ''),
          $entryAllowFinance,
          $pricingOverrides,
          $pricingConfirmed
        );
        $priceNote = $pricingConfirmed ? ' Custos e preços revisados foram aplicados.' : ' O custo foi atualizado com os encargos da nota; o preço de venda atual foi preservado.';
        $okMessage = $entryCrossContext
          ? 'Entrada de mercadoria gerada na empresa selecionada pelo F11. Estoque atualizado; Contas a Pagar não foi gerado nesta V10.' . $priceNote
          : 'Entrada gerada. Contas a pagar criado(s): #' . implode(', #', $created) . '.' . $priceNote;
        header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . $id . '&ok=' . rawurlencode($okMessage)));
        exit;
      }
    }
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$xml = $id > 0 ? fiscal_xml_get($pdo, $cid, $id) : null;
if (!$xml) {
  fiscal_admin_start('Fiscal - XML', 'fiscal_entrada_notas');
  fiscal_page_header('XML não encontrado', 'Não localizei o XML selecionado.', [['label'=>'Voltar para Entrada de Notas','href'=>fiscal_v10_entry_context_url('fiscal_entrada_notas.php'),'class'=>'btn btn-primary']]);
  fiscal_tabs('fiscal_entrada_notas');
  echo '<div class="msg err">XML não encontrado.</div>';
  fiscal_admin_end();
  exit;
}
$items = fiscal_xml_items($pdo, $cid, $id);
$itemLinkPreview = [];
foreach ($items as $previewItem) {
  $previewTax = fiscal_xml_item_tax_payload($pdo, $cid, $previewItem);
  $previewIcms = trim((string)($previewItem['icms_cst'] ?? '')) !== '' ? (string)$previewItem['icms_cst'] : (string)($previewItem['icms_csosn'] ?? '');
  $itemLinkPreview[(string)(int)($previewItem['id'] ?? 0)] = [
    'item_id' => (int)($previewItem['id'] ?? 0),
    'name' => (string)($previewItem['name'] ?? ''),
    'product_code' => (string)($previewItem['product_code'] ?? ''),
    'ean' => (string)($previewItem['ean'] ?? ''),
    'unit' => (string)($previewItem['unit'] ?? ''),
    'quantity' => (float)($previewItem['quantity'] ?? 0),
    'total_value' => (float)($previewItem['total_value'] ?? 0),
    'landed_base_value' => fiscal_xml_item_landed_base_value($previewItem),
    'factor' => (float)($previewItem['conversion_factor'] ?? 1),
    'xml_cfop' => (string)($previewItem['cfop'] ?? ''),
    'xml_origem' => (string)($previewItem['origem'] ?? ''),
    'xml_icms' => $previewIcms,
    'xml_ipi' => (string)($previewItem['ipi_cst'] ?? ''),
    'xml_pis' => (string)($previewItem['pis_cst'] ?? ''),
    'xml_cofins' => (string)($previewItem['cofins_cst'] ?? ''),
    'entry_cfop' => (string)($previewTax['entry_cfop'] ?? ''),
    'entry_origem' => (string)($previewTax['entry_origem'] ?? ''),
    'entry_icms_cst' => (string)($previewTax['entry_icms_cst'] ?? ''),
    'entry_icms_csosn' => (string)($previewTax['entry_icms_csosn'] ?? ''),
    'entry_ipi' => (string)($previewTax['entry_ipi_cst'] ?? ''),
    'entry_pis' => (string)($previewTax['entry_pis_cst'] ?? ''),
    'entry_cofins' => (string)($previewTax['entry_cofins_cst'] ?? ''),
    'tax_rule_id' => (int)($previewTax['tax_conversion_id'] ?? 0),
    'tax_rule_name' => (string)($previewTax['tax_conversion_name'] ?? ''),
  ];
}
$gestaoEnabled = fiscal_xml_gestao_enabled($pdo, $cid);
$hasCostColumn = $gestaoEnabled ? fiscal_xml_ensure_product_cost_column($pdo) : false;
$productOptions = fiscal_xml_products_options($pdo, $cid, '', 1200);
$categoryOptions = [];
$categorySettings = [];
try {
  if (function_exists('fiscal_xml_ensure_product_category_visibility_columns')) fiscal_xml_ensure_product_category_visibility_columns($pdo);
  $hasHideOrder = fiscal_column_exists($pdo, 'produtos', 'category_hide_order_catalog');
  $hasHideProduct = fiscal_column_exists($pdo, 'produtos', 'category_hide_product_catalog');
  $catSelect = 'categoria'
    . ($hasHideOrder ? ', COALESCE(MAX(category_hide_order_catalog),0) AS hide_order_catalog' : ', 0 AS hide_order_catalog')
    . ($hasHideProduct ? ', COALESCE(MAX(category_hide_product_catalog),0) AS hide_product_catalog' : ', 0 AS hide_product_catalog');
  $qCats = $pdo->prepare("SELECT {$catSelect} FROM produtos WHERE company_id = ? AND COALESCE(categoria,'') <> '' GROUP BY categoria ORDER BY categoria ASC");
  $qCats->execute([$cid]);
  foreach (($qCats->fetchAll(PDO::FETCH_ASSOC) ?: []) as $catRow) {
    $catName = trim((string)($catRow['categoria'] ?? ''));
    if ($catName === '') continue;
    $categoryOptions[] = $catName;
    $categorySettings[$catName] = [
      'hide_order_catalog' => (int)($catRow['hide_order_catalog'] ?? 0),
      'hide_product_catalog' => (int)($catRow['hide_product_catalog'] ?? 0),
    ];
  }
} catch (Throwable $e) {
  $categoryOptions = [];
  $categorySettings = [];
}
$taxProfiles = [];
try { $taxProfiles = fiscal_tax_profiles($pdo, $cid, true); } catch (Throwable $e) {}
$taxProfilesPreview = [];
foreach ($taxProfiles as $tp) {
  $taxProfilesPreview[(string)(int)($tp['id'] ?? 0)] = [
    'name' => trim((string)($tp['code'] ?? '') . ' - ' . (string)($tp['name'] ?? ''), ' -'),
    'profile_type' => (string)($tp['profile_type'] ?? 'PRODUCT'),
    'tax_mode' => (string)($tp['tax_mode'] ?? ''),
    'icms_rate' => (float)($tp['icms_rate'] ?? 0),
    'icms_st_rate' => (float)($tp['icms_st_rate'] ?? 0),
    'icms_cst' => (string)($tp['icms_cst'] ?? ''),
    'icms_csosn' => (string)($tp['icms_csosn'] ?? ''),
    'ipi_rate' => (float)($tp['ipi_rate'] ?? 0),
    'ipi_cst' => (string)($tp['ipi_cst'] ?? ''),
    'pis_out_rate' => (float)($tp['pis_out_rate'] ?? 0),
    'pis_out_cst' => (string)($tp['pis_out_cst'] ?? ''),
    'cofins_out_rate' => (float)($tp['cofins_out_rate'] ?? 0),
    'cofins_out_cst' => (string)($tp['cofins_out_cst'] ?? ''),
    'cfop_sale_state' => (string)($tp['cfop_sale_state'] ?? ''),
    'cfop_sale_interstate' => (string)($tp['cfop_sale_interstate'] ?? ''),
    'cfop_entry_state' => (string)($tp['cfop_entry_state'] ?? ''),
    'cfop_entry_interstate' => (string)($tp['cfop_entry_interstate'] ?? ''),
  ];
}
$xmlGenerated = (string)($xml['status'] ?? '') === 'ENTRADA_GERADA';
$installments = fiscal_xml_installments($xml);
$entryOps = fiscal_operations($pdo, $cid, 'ENTRADA', true);
$defaultOp = fiscal_default_entry_operation($pdo, $cid);
$charts = [];
$costs = [];
if ($entryAllowFinance) {
  try { $charts = finance_chart_options($pdo, $cid, 'DEBITO'); } catch (Throwable $e) {}
  try { $costs = finance_cost_center_options($pdo, $cid); } catch (Throwable $e) {}
}
$selectedOp = (int)($_POST['operation_id'] ?? ($defaultOp['id'] ?? 0));
$selectedChart = (int)($_POST['chart_account_id'] ?? ($defaultOp['chart_account_id'] ?? 0));
if ($selectedChart <= 0) $selectedChart = fiscal_xml_default_debit_chart($pdo, $cid);
$selectedCost = (int)($_POST['cost_center_id'] ?? ($defaultOp['cost_center_id'] ?? 0));
$payableIds = json_decode((string)($xml['finance_payable_ids'] ?? ''), true);
if (!is_array($payableIds)) $payableIds = [];

function fiscal_xml_select_options(array $rows, $selected, string $empty = 'Selecione'): void {
  echo '<option value="">' . fiscal_h($empty) . '</option>';
  foreach ($rows as $r) {
    $label = trim((string)($r['code'] ?? '') . ' - ' . (string)($r['name'] ?? ''));
    echo '<option value="' . (int)$r['id'] . '" ' . ((string)$selected === (string)$r['id'] ? 'selected' : '') . '>' . fiscal_h($label) . '</option>';
  }
}

fiscal_admin_start('Fiscal - Gerar Entrada XML' . ($entryCrossContext ? ' · ' . $entryCompanyName : ''), 'fiscal_entrada_notas');
fiscal_page_header('XML NF-e / Gerar entrada' . ($entryCrossContext ? ' · ' . $entryCompanyName : ''), '');
fiscal_flash();
if ($entryCrossContext) echo '<div class="msg ok"><strong>V10 Multiempresa:</strong> esta entrada está vinculada a <strong>' . fiscal_h($entryCompanyName) . '</strong>. Produtos, tributação e estoque são da empresa selecionada pelo F11. O Contas a Pagar continua bloqueado nesta etapa.</div>';
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<style>
.entry-edit-card{margin-top:14px}.entry-edit-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.entry-edit-actions{display:flex;gap:8px;flex-wrap:wrap}.entry-edit-note{border:1px solid #fde68a;background:#fffbeb;color:#854d0e;border-radius:14px;padding:10px 12px;font-size:13px;font-weight:800;margin:10px 0}.entry-edit-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}.entry-edit-items{display:grid;gap:8px}.entry-edit-item{border:1px solid #dbe4f0;border-radius:16px;background:#fff;overflow:hidden}.entry-edit-item>summary{cursor:pointer;list-style:none;padding:10px 12px;background:#f8fbff;display:grid;grid-template-columns:minmax(220px,2fr) repeat(4,minmax(90px,.7fr));gap:10px;align-items:center}.entry-edit-item>summary::-webkit-details-marker{display:none}.entry-edit-item>summary strong{color:#0b1f5b}.entry-edit-item>summary span{font-size:12px;color:#64748b}.entry-edit-body{padding:12px;border-top:1px solid #e5edf6}.entry-edit-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:9px}.entry-edit-grid label{display:grid;gap:4px;font-size:11px;font-weight:900;color:#475569}.entry-edit-grid input,.entry-edit-grid select{width:100%;min-width:0;min-height:36px;border:1px solid #dbe4f0;border-radius:10px;padding:7px 8px;box-sizing:border-box}.entry-edit-grid .span-2{grid-column:span 2}.entry-tax-title{grid-column:1/-1;font-size:12px;font-weight:950;color:#0b1f5b;border-top:1px solid #e5edf6;padding-top:9px;margin-top:2px}.entry-readonly{background:#f1f5f9!important;color:#64748b}.entry-delete{border-color:#fecaca!important;color:#991b1b!important;background:#fff!important}
@media(max-width:1000px){.entry-edit-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.entry-edit-item>summary{grid-template-columns:1fr 1fr 1fr}.entry-edit-item>summary .wide{grid-column:1/-1}}@media(max-width:640px){.entry-edit-grid{grid-template-columns:1fr 1fr}.entry-edit-grid .span-2{grid-column:1/-1}.entry-edit-item>summary{grid-template-columns:1fr 1fr}}

.xml-head-card{margin-top:10px;padding:12px 14px}
.xml-head-card h2{margin:0 0 10px}
.xml-head-row{display:grid;grid-template-columns:repeat(18,minmax(0,1fr));gap:8px;align-items:end}
.xml-head-row label{display:grid;gap:4px;font-size:11px;font-weight:900;color:#475569}
.xml-head-row input{width:100%;min-width:0;box-sizing:border-box}
.xml-head-nfe{grid-column:span 2}.xml-head-date{grid-column:span 2}.xml-head-status{grid-column:span 3}.xml-head-supplier{grid-column:span 3}.xml-head-doc{grid-column:span 3}.xml-head-uf{grid-column:span 1}.xml-head-total{grid-column:span 2}
.xml-head-key{grid-column:span 7}.xml-head-city{grid-column:span 2}.xml-head-address{grid-column:span 5}.xml-head-reports{grid-column:span 2}.xml-head-actions{grid-column:span 2}
.xml-head-status input,.xml-head-doc input{font-size:13px;padding-left:8px;padding-right:8px}
.xml-head-actions{display:grid;gap:4px;align-self:end}
.xml-head-actions::before{content:"\00a0";font-size:11px;line-height:1.2;visibility:hidden}
.xml-head-actions .btn{width:100%;min-height:40px;margin:0}
.xml-installments{display:flex;gap:8px;flex-wrap:wrap}
.xml-installment{min-width:190px;flex:1 1 210px;max-width:300px;border:1px solid #dbe4f0;border-radius:14px;padding:9px 11px;background:#fff;display:grid;grid-template-columns:auto 1fr;gap:4px 10px;align-items:center}
.xml-installment strong{color:#0b1f5b}
.xml-installment .amt{grid-column:2;text-align:right;font-weight:950;color:#047857}
.xml-installment .due{font-size:12px;color:#64748b}
.xml-generated-links{margin-top:8px;display:flex;gap:6px;align-items:center;flex-wrap:wrap}
@media(max-width:1250px){.xml-head-row{grid-template-columns:repeat(6,minmax(0,1fr))}.xml-head-nfe,.xml-head-date,.xml-head-status,.xml-head-doc,.xml-head-total,.xml-head-city,.xml-head-reports,.xml-head-actions{grid-column:span 2}.xml-head-supplier,.xml-head-key,.xml-head-address{grid-column:span 4}.xml-head-uf{grid-column:span 1}}
@media(max-width:760px){.xml-head-row{grid-template-columns:1fr 1fr}.xml-head-nfe,.xml-head-date,.xml-head-status,.xml-head-supplier,.xml-head-doc,.xml-head-uf,.xml-head-total,.xml-head-key,.xml-head-city,.xml-head-address,.xml-head-reports,.xml-head-actions{grid-column:auto}.xml-head-supplier,.xml-head-key,.xml-head-address{grid-column:1/-1}}
@media(max-width:500px){.xml-head-row{grid-template-columns:1fr}.xml-head-nfe,.xml-head-date,.xml-head-status,.xml-head-supplier,.xml-head-doc,.xml-head-uf,.xml-head-total,.xml-head-key,.xml-head-city,.xml-head-address,.xml-head-reports,.xml-head-actions{grid-column:auto}.xml-installment{max-width:none}}
</style>

<div class="fiscal-card xml-head-card" id="dadosNfe">
  <h2>Dados da NF-e</h2>
  <form method="post" class="xml-head-row">
    <input type="hidden" name="action" value="save_dates">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <label class="xml-head-nfe"><span>NF-e</span><input readonly value="<?= fiscal_h(($xml['series'] ?? '') . '/' . ($xml['number'] ?? '')) ?>"></label>
    <label class="xml-head-date"><span>Emissão</span><input name="issue_date_br" value="<?= fiscal_h(fiscal_date_br($xml['issue_date'] ?? '')) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}" title="Use dd-mm-aaaa"></label>
    <label class="xml-head-date"><span>Entrada</span><input name="entry_date_br" value="<?= fiscal_h(fiscal_date_br($xml['entry_date'] ?? ($xml['issue_date'] ?? ''))) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}" title="Use dd-mm-aaaa"></label>
    <label class="xml-head-status"><span>Status</span><input readonly value="<?= fiscal_h($xml['status'] ?? '') ?>"></label>
    <label class="xml-head-supplier"><span>Fornecedor</span><input readonly value="<?= fiscal_h($xml['supplier_name'] ?? '') ?>"></label>
    <label class="xml-head-doc"><span>CNPJ/CPF</span><input readonly value="<?= fiscal_h($xml['supplier_doc'] ?? '') ?>"></label>
    <label class="xml-head-uf"><span>UF</span><input readonly value="<?= fiscal_h($xml['supplier_uf'] ?? '') ?>"></label>
    <label class="xml-head-total"><span>Total</span><input readonly value="R$ <?= number_format((float)($xml['total_invoice'] ?? 0), 2, ',', '.') ?>"></label>
    <label class="xml-head-key"><span>Chave de acesso</span><input readonly value="<?= fiscal_h($xml['access_key'] ?? '') ?>"></label>
    <label class="xml-head-city"><span>Cidade</span><input readonly value="<?= fiscal_h($xml['supplier_city'] ?? '') ?>"></label>
    <label class="xml-head-address"><span>Endereço fornecedor</span><input readonly value="<?= fiscal_h(trim(($xml['supplier_address'] ?? '') . ', ' . ($xml['supplier_number'] ?? '') . ' - ' . ($xml['supplier_district'] ?? ''), ' ,-')) ?>"></label>
    <label class="xml-head-reports"><span>Relatórios fiscais</span><input readonly value="<?= ((int)($xml['include_fiscal_reports'] ?? 1) === 1 && (string)($xml['number'] ?? '') !== '0') ? 'Gerar' : 'Não gerar' ?>"></label>
    <div class="xml-head-actions"><button class="btn btn-primary" type="submit">Salvar datas</button></div>
  </form>
</div>

<div class="fiscal-card" id="itensXml">
  <div class="fiscal-list-head">
    <div>
      <h2>Itens da NF-e / vínculo com produtos</h2>
      <div class="fiscal-muted">Pesquise um produto já cadastrado, informe o fator de conversão e salve os vínculos antes de gerar a entrada com estoque.</div>
    </div>
    <span class="fiscal-list-count"><?= count($items) ?> item(ns)</span>
  </div>
  <datalist id="xmlProdutoOptions">
    <?php foreach ($productOptions as $pOpt): ?>
      <?php
        $prodLabel = '#' . (int)$pOpt['id'] . ' - ' . (string)($pOpt['nome'] ?? '');
        $prodBarcode = '';
        foreach (['codigo_barras','ean','gtin','barcode'] as $bcCol) { if (!empty($pOpt[$bcCol])) { $prodBarcode = (string)$pOpt[$bcCol]; break; } }
        if ($prodBarcode !== '') $prodLabel .= ' • EAN ' . $prodBarcode;
        if (trim((string)($pOpt['categoria'] ?? '')) !== '') $prodLabel .= ' (' . (string)$pOpt['categoria'] . ')';
      ?>
      <option value="<?= fiscal_h($prodLabel) ?>" data-product-id="<?= (int)$pOpt['id'] ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <datalist id="xmlCategoryOptions">
    <?php foreach ($categoryOptions as $catOpt): ?>
      <option value="<?= fiscal_h($catOpt) ?>"></option>
    <?php endforeach; ?>
  </datalist>
  <?php if (!$items): ?>
    <div class="msg err">Nenhum item encontrado no XML.</div>
  <?php else: ?>
    <form method="post" class="fiscal-form" id="xmlItensForm">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="table-wrap xml-items-wrap"><table class="fiscal-table xml-items-table"><thead><tr>
        <th style="width:44px">#</th><th>Produto XML</th><th>Produto do sistema</th><th style="width:96px">Fator</th><th style="width:110px">Qtd. estoque</th><?php if ($gestaoEnabled): ?><th style="width:115px">Custo unit.</th><?php endif; ?><th style="width:145px">Cadastrar</th>
      </tr></thead><tbody>
      <?php foreach ($items as $it): ?>
        <?php
          $itemId = (int)($it['id'] ?? 0);
          $pid = (int)($it['product_id'] ?? 0);
          $currentProduct = '';
          if ($pid > 0) {
            $currentProduct = '#' . $pid . ' - ' . trim((string)($it['product_name'] ?? ''));
          }
          $factor = fiscal_xml_parse_decimal($it['conversion_factor'] ?? 1, 1.0);
          if ($factor <= 0) $factor = 1.0;
          $qtyXml = (float)($it['quantity'] ?? 0);
          $stockQty = (float)($it['stock_quantity'] ?? 0);
          if ($stockQty <= 0) $stockQty = $qtyXml * $factor;
          $unitCost = (float)($it['stock_unit_cost'] ?? 0);
          if ($unitCost <= 0 && $stockQty > 0) $unitCost = (float)($it['total_value'] ?? 0) / $stockQty;
          $taxPayload = fiscal_xml_item_tax_payload($pdo, $cid, $it);
          $taxProfileId = (int)($taxPayload['tax_profile_id'] ?? 0);
          $taxRuleName = trim((string)($taxPayload['tax_conversion_name'] ?? ''));
          $xmlIcms = trim((string)($it['icms_cst'] ?? '')) !== '' ? (string)$it['icms_cst'] : (string)($it['icms_csosn'] ?? '');
          $entryOrigem = (string)($taxPayload['entry_origem'] ?: ($it['origem'] ?? ''));
          $entryIcms = trim((string)($taxPayload['entry_icms_cst'] ?? '')) !== '' ? (string)$taxPayload['entry_icms_cst'] : (string)($taxPayload['entry_icms_csosn'] ?? '');
        ?>
        <tr>
          <td data-label="#"><?= (int)($it['item_number'] ?? 0) ?></td>
          <td data-label="Produto XML" class="primary">
            <strong><?= fiscal_h($it['name'] ?? '') ?></strong>
            <div class="fiscal-muted">Cód.: <?= fiscal_h($it['product_code'] ?? '') ?><?= !empty($it['ean']) ? ' • EAN: ' . fiscal_h($it['ean']) : '' ?></div>
            <div class="fiscal-muted">NCM <?= fiscal_h($it['ncm'] ?? '') ?> • CFOP <?= fiscal_h($it['cfop'] ?? '') ?> • Qtd XML <?= number_format($qtyXml, 4, ',', '.') ?> <?= fiscal_h($it['unit'] ?? '') ?> • Total R$ <?= number_format((float)($it['total_value'] ?? 0), 2, ',', '.') ?></div>
            <details class="xml-tax-details">
              <summary>Mais informações fiscais</summary>
              <div class="xml-tax-detail-grid">
                <div>
                  <b>Tributação do XML / fornecedor</b>
                  <span>CFOP <?= fiscal_h($it['cfop'] ?? '—') ?> • Origem <?= fiscal_h($it['origem'] ?? '—') ?> • ICMS <?= fiscal_h($xmlIcms ?: '—') ?> • IPI <?= fiscal_h($it['ipi_cst'] ?? '—') ?> • PIS <?= fiscal_h($it['pis_cst'] ?? '—') ?> • COFINS <?= fiscal_h($it['cofins_cst'] ?? '—') ?></span>
                </div>
                <div>
                  <b>Conversão aplicada para entrada / SPED</b>
                  <span>CFOP <?= fiscal_h($taxPayload['entry_cfop'] ?: '—') ?> • Origem <?= fiscal_h($entryOrigem ?: '—') ?> • ICMS <?= fiscal_h($entryIcms ?: '—') ?> • IPI <?= fiscal_h($taxPayload['entry_ipi_cst'] ?: '—') ?> • PIS <?= fiscal_h($taxPayload['entry_pis_cst'] ?: '—') ?> • COFINS <?= fiscal_h($taxPayload['entry_cofins_cst'] ?: '—') ?></span>
                  <?= $taxRuleName !== '' ? '<em>Regra: ' . fiscal_h($taxRuleName) . '</em>' : '<em>Sem regra automática cadastrada. Ao preencher/salvar a tributação de entrada, o Nexa cria a conversão para reutilizar nos próximos XMLs.</em>' ?>
                </div>
              </div>
            </details>
          </td>
          <td data-label="Produto do sistema">
            <div class="xml-product-link-row">
              <input name="item_product_search[<?= $itemId ?>]" value="<?= fiscal_h($currentProduct) ?>" placeholder="Código, referência, barras ou descrição" data-product-search="<?= $itemId ?>" <?= $xmlGenerated ? 'readonly' : '' ?>>
              <button class="btn btn-sm xml-search-product-btn" type="button" data-search-product-item="<?= $itemId ?>" title="<?= $xmlGenerated ? 'Entrada já gerada. Desfaça a nota para alterar o vínculo.' : 'Atalho F8' ?>" <?= $xmlGenerated ? 'disabled' : '' ?>>Buscar F8</button>
            </div>
            <input type="hidden" name="item_product_id[<?= $itemId ?>]" value="<?= $pid > 0 ? $pid : '' ?>" data-product-id="<?= $itemId ?>">
            <div class="fiscal-muted" data-product-link-status="<?= $itemId ?>"><?php if ($pid > 0): ?>Vinculado ao produto #<?= $pid ?>. <a href="produto_form.php?id=<?= $pid ?>" target="_blank">abrir cadastro</a><?php else: ?>Ainda não vinculado.<?php endif; ?></div>
          </td>
          <td data-label="Fator"><input name="conversion_factor[<?= $itemId ?>]" value="<?= fiscal_h(number_format($factor, 6, ',', '.')) ?>" inputmode="decimal" data-factor="<?= $itemId ?>" <?= $xmlGenerated ? 'readonly' : '' ?>><div class="fiscal-muted">Ex.: CX com 12 un. = 12</div></td>
          <td data-label="Qtd. estoque"><strong data-stock-qty="<?= $itemId ?>"><?= number_format($stockQty, 4, ',', '.') ?></strong></td>
          <?php if ($gestaoEnabled): ?><td data-label="Custo unit."><strong data-unit-cost="<?= $itemId ?>">R$ <?= number_format($unitCost, 4, ',', '.') ?></strong></td><?php endif; ?>
          <td data-label="Cadastrar">
            <?php if ($xmlGenerated): ?>
              <span class="fiscal-badge">Entrada gerada</span>
            <?php else: ?>
              <button
                class="btn btn-sm open-xml-product-modal"
                type="button"
                data-item-id="<?= $itemId ?>"
                data-name="<?= fiscal_h($it['name'] ?? '') ?>"
                data-code="<?= fiscal_h($it['product_code'] ?? '') ?>"
                data-ean="<?= fiscal_h($it['ean'] ?? '') ?>"
                data-ncm="<?= fiscal_h($it['ncm'] ?? '') ?>"
                data-cest="<?= fiscal_h($it['cest'] ?? '') ?>"
                data-xml-cfop="<?= fiscal_h($it['cfop'] ?? '') ?>"
                data-xml-origem="<?= fiscal_h($it['origem'] ?? '') ?>"
                data-xml-icms-cst="<?= fiscal_h($it['icms_cst'] ?? '') ?>"
                data-xml-icms-csosn="<?= fiscal_h($it['icms_csosn'] ?? '') ?>"
                data-xml-ipi-cst="<?= fiscal_h($it['ipi_cst'] ?? '') ?>"
                data-xml-pis-cst="<?= fiscal_h($it['pis_cst'] ?? '') ?>"
                data-xml-cofins-cst="<?= fiscal_h($it['cofins_cst'] ?? '') ?>"
                data-cfop="<?= fiscal_h($taxPayload['entry_cfop'] ?: ($it['cfop'] ?? '')) ?>"
                data-entry-origem="<?= fiscal_h($entryOrigem ?: ($it['origem'] ?? '')) ?>"
                data-product-origem="<?= fiscal_h($it['origem'] ?? '') ?>"
                data-icms-cst="<?= fiscal_h($taxPayload['entry_icms_cst'] ?: ($it['icms_cst'] ?? '')) ?>"
                data-icms-csosn="<?= fiscal_h($taxPayload['entry_icms_csosn'] ?: ($it['icms_csosn'] ?? '')) ?>"
                data-ipi-cst="<?= fiscal_h($taxPayload['entry_ipi_cst'] ?: ($it['ipi_cst'] ?? '')) ?>"
                data-pis-cst="<?= fiscal_h($taxPayload['entry_pis_cst'] ?: ($it['pis_cst'] ?? '')) ?>"
                data-cofins-cst="<?= fiscal_h($taxPayload['entry_cofins_cst'] ?: ($it['cofins_cst'] ?? '')) ?>"
                data-tax-profile-id="<?= (int)$taxProfileId ?>"
                data-tax-rule-name="<?= fiscal_h($taxRuleName) ?>"
                data-unit="<?= fiscal_h($it['unit'] ?? '') ?>"
                data-qty="<?= fiscal_h((string)$qtyXml) ?>"
                data-total="<?= fiscal_h((string)($it['total_value'] ?? 0)) ?>"
                data-unit-value="<?= fiscal_h((string)($it['unit_value'] ?? 0)) ?>"
              >Cadastrar produto</button>
              <div class="fiscal-muted" style="margin-top:6px">Abre um cadastro rápido já preenchido com os dados da NF-e.</div>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody></table></div>
      <input type="hidden" name="quick_item_id" value="">
      <?php if (!$xmlGenerated): ?>
        <div class="fiscal-form-actions xml-sticky-actions">
          <button class="btn btn-primary" type="submit" name="action" value="save_links">Salvar vínculos e fatores</button>
        </div>
      <?php endif; ?>
    </form>
  <?php endif; ?>
</div>

<div class="fiscal-card" id="parcelasXml" style="margin-top:14px">
  <div class="fiscal-list-head"><h2>Parcelas encontradas no XML</h2><span class="fiscal-list-count"><?= count($installments) ?> parcela(s)</span></div>
  <?php if (!$installments): ?>
    <div class="fiscal-muted">O XML não trouxe parcelas. O vencimento fallback poderá ser utilizado na geração.</div>
  <?php else: ?>
    <div class="xml-installments">
      <?php foreach ($installments as $p): ?>
        <div class="xml-installment">
          <strong>Parcela <?= fiscal_h($p['number'] ?? '') ?></strong>
          <span class="amt">R$ <?= number_format((float)($p['amount'] ?? 0), 2, ',', '.') ?></span>
          <span class="due">Vencimento</span>
          <span class="due" style="text-align:right;font-weight:800"><?= fiscal_h(fiscal_date_br($p['due_date'] ?? '')) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
  <?php if ($payableIds): ?><div class="msg ok xml-generated-links"><span>Contas a pagar gerado(s):</span><?php foreach($payableIds as $pid): ?><a class="btn btn-sm" href="financeiro_contas_pagar.php?edit=<?= (int)$pid ?>">#<?= (int)$pid ?></a><?php endforeach; ?></div><?php endif; ?>
</div>

<style>
.xml-product-link-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:6px;align-items:center}.xml-product-link-row .btn{white-space:nowrap;min-height:40px}.xml-items-wrap{overflow-x:auto;overflow-y:visible}.xml-items-table{table-layout:fixed;width:100%;min-width:980px}.xml-items-table th,.xml-items-table td{min-width:0;vertical-align:top;overflow:hidden}.xml-items-table input{width:100%;min-width:0;box-sizing:border-box}.xml-items-table .primary{min-width:0}.xml-items-table .primary strong{display:block;max-width:100%;overflow:hidden;text-overflow:ellipsis}.xml-items-table .primary .fiscal-muted{overflow-wrap:anywhere;word-break:normal}.xml-items-table .btn{white-space:normal;line-height:1.1}.xml-tax-details{display:block;width:100%;max-width:100%;min-width:0;margin-top:7px;box-sizing:border-box}.xml-tax-details[open]{border:1px solid #dbe4f0;background:#fff;border-radius:14px;padding:8px}.xml-tax-details summary{display:inline-flex;align-items:center;width:max-content;max-width:100%;border:1px solid #c7d2fe;background:#eef2ff;color:#1e3a8a;border-radius:999px;padding:5px 10px;font-size:12px;font-weight:900;cursor:pointer;list-style:none;box-sizing:border-box}.xml-tax-details summary::-webkit-details-marker{display:none}.xml-tax-detail-grid{display:grid;grid-template-columns:1fr;gap:7px;width:100%;max-width:100%;min-width:0;margin-top:8px;box-sizing:border-box}.xml-tax-detail-grid>div{min-width:0;max-width:100%;overflow:hidden;border:1px solid #dbe4f0;background:#f8fafc;border-radius:12px;padding:8px;box-sizing:border-box}.xml-tax-detail-grid b,.xml-tax-detail-grid span,.xml-tax-detail-grid em{display:block;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.xml-tax-detail-grid b{font-size:12px;color:#0b1f5b;line-height:1.25}.xml-tax-detail-grid span{font-size:12px;color:#475569;margin-top:3px;line-height:1.35}.xml-tax-detail-grid em{font-size:12px;color:#166534;margin-top:4px;font-style:normal;font-weight:850;line-height:1.3}.xml-sticky-actions{position:sticky;bottom:0;z-index:25;background:linear-gradient(180deg,rgba(255,255,255,.82),#fff 40%);border-top:1px solid #e5edf6;margin-top:10px;padding-top:12px}
@media(max-width:980px){.xml-items-table{table-layout:auto;min-width:900px}.xml-sticky-actions .btn{width:100%}}
@media(max-width:680px){.xml-items-wrap{overflow:visible}.xml-items-table{min-width:0}.xml-items-table input{min-height:42px}.xml-items-table .fiscal-muted{font-size:12px}.xml-items-table .btn{width:100%;justify-content:center}.xml-tax-details[open]{padding:7px}.xml-tax-detail-grid{grid-template-columns:1fr}}
</style>
<?php if (!$xmlGenerated && $items): ?>
<style>
.xml-product-search-modal{position:fixed;inset:0;z-index:520;display:none;align-items:flex-start;justify-content:center;padding:6vh 18px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);overflow:auto}.xml-product-search-modal.open{display:flex}.xml-product-search-dialog{width:min(920px,100%);background:#fff;border-radius:22px;border:1px solid #dbe4f0;box-shadow:0 28px 90px rgba(2,6,23,.35);padding:16px}.xml-product-search-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px}.xml-product-search-head h2{margin:0;color:#0b1f5b}.xml-product-search-controls{display:flex;gap:9px;align-items:flex-start;flex-wrap:wrap;margin-top:12px}.xml-product-search-field{display:grid;gap:5px;min-width:0}.xml-product-search-field span{font-size:11px;font-weight:900;color:#334155;line-height:1.2;min-height:14px}.xml-product-search-field-mode{flex:0 0 180px}.xml-product-search-field-query{flex:1 1 360px}.xml-product-search-action{display:flex;align-items:flex-start;padding-top:28px}.xml-product-search-action .btn{height:46px;min-height:46px;display:inline-flex;align-items:center;justify-content:center;white-space:nowrap;padding-top:0;padding-bottom:0;margin:0}.xml-product-search-select,.xml-product-search-input{width:100%;box-sizing:border-box}.xml-product-search-input{margin-top:0}.xml-product-search-results{display:grid;gap:6px;margin-top:10px;max-height:52vh;overflow:auto}.xml-product-search-result{border:1px solid #dbe4f0;border-radius:13px;padding:10px 12px;background:#fff;cursor:pointer;outline:none}.xml-product-search-result:hover,.xml-product-search-result.active{border-color:#60a5fa;background:#eff6ff}.xml-product-search-result strong{display:block;color:#0b1f5b}.xml-product-search-result small{display:block;color:#64748b;margin-top:3px}.xml-product-search-empty{border:1px dashed #cbd5e1;border-radius:14px;padding:18px;text-align:center;color:#64748b}.xml-product-search-actions{display:flex;justify-content:space-between;gap:10px;align-items:center;margin-top:12px;border-top:1px solid #e5edf6;padding-top:12px}@media(max-width:860px){.xml-product-search-field-mode{flex:1 1 220px}.xml-product-search-field-query{flex:1 1 100%}.xml-product-search-action{flex:1 1 calc(50% - 5px);padding-top:0}.xml-product-search-action .btn{width:100%}}@media(max-width:680px){.xml-product-link-row{grid-template-columns:1fr}.xml-product-search-field-mode,.xml-product-search-field-query,.xml-product-search-action{flex:1 1 100%}.xml-product-search-actions{flex-direction:column;align-items:stretch}.xml-product-search-actions .btn{width:100%}}

.xml-link-confirm-modal{position:fixed;inset:0;z-index:540;display:none;align-items:flex-start;justify-content:center;padding:5vh 18px;background:rgba(15,23,42,.62);backdrop-filter:blur(3px);overflow:auto}.xml-link-confirm-modal.open{display:flex}.xml-link-confirm-dialog{width:min(1120px,100%);background:#fff;border-radius:22px;border:1px solid #dbe4f0;box-shadow:0 30px 95px rgba(2,6,23,.38);padding:16px}.xml-link-confirm-products{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:14px}.xml-link-confirm-products>div{border:1px solid #dbe4f0;border-radius:16px;background:#f8fafc;padding:12px;display:grid;gap:4px}.xml-link-confirm-products span{font-size:11px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;color:#64748b}.xml-link-confirm-products strong{font-size:15px;color:#0b1f5b}.xml-link-confirm-products small{color:#64748b}.xml-link-confirm-factor{display:grid;grid-template-columns:220px minmax(0,1fr);gap:10px;align-items:end;margin-top:10px}.xml-link-confirm-factor label{display:grid;gap:5px;font-size:12px;font-weight:900;color:#334155}.xml-link-confirm-factor input{width:100%;min-height:44px;border:1px solid #dbe4f0;border-radius:14px;padding:10px 12px;box-sizing:border-box}.xml-link-confirm-summary{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:14px;padding:11px 12px;font-weight:850;min-height:44px;box-sizing:border-box;display:flex;align-items:center}.xml-link-confirm-commercial{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:10px}.xml-link-confirm-commercial label{display:grid;gap:4px;min-width:0;font-size:11px;font-weight:900;color:#334155}.xml-link-confirm-commercial input{width:100%;min-width:0;height:40px;border:1px solid #dbe4f0;border-radius:11px;padding:8px 9px;box-sizing:border-box;font:inherit}.xml-link-confirm-commercial input[readonly]{background:#f8fafc;color:#64748b}.xml-link-confirm-commercial .xml-editable{border-color:#93c5fd;background:#fff}.xml-link-product-tax{margin-top:10px;border:1px solid #dbe4f0;border-radius:14px;background:#fbfdff;padding:10px 12px}.xml-link-product-tax label{display:grid;gap:5px;font-size:12px;font-weight:900;color:#334155}.xml-link-product-tax select{width:100%;min-height:40px;border:1px solid #dbe4f0;border-radius:11px;padding:8px 10px;background:#fff;box-sizing:border-box;font:inherit}.xml-link-product-tax-preview{font-size:10px;line-height:1.35;color:#64748b;margin-top:5px;min-height:14px}.xml-link-confirm-tax{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-top:12px}.xml-link-confirm-tax input{width:100%;min-height:38px;border:1px solid #dbe4f0;border-radius:10px;padding:8px 9px;box-sizing:border-box}.xml-link-confirm-tax input[readonly]{background:#f8fafc;color:#64748b}.xml-link-confirm-message{display:none;margin-top:10px;border-radius:12px;padding:9px 11px;font-weight:850}.xml-link-confirm-message.ok{display:block;border:1px solid #bbf7d0;background:#f0fdf4;color:#166534}.xml-link-confirm-message.err{display:block;border:1px solid #fecaca;background:#fef2f2;color:#991b1b}.xml-link-confirm-buttons{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}@media(max-width:980px){.xml-link-confirm-commercial{grid-template-columns:repeat(3,minmax(0,1fr))}}@media(max-width:760px){.xml-link-confirm-modal{padding:10px}.xml-link-confirm-dialog{border-radius:18px}.xml-link-confirm-products,.xml-link-confirm-tax,.xml-link-confirm-factor{grid-template-columns:1fr}.xml-link-confirm-commercial{grid-template-columns:repeat(2,minmax(0,1fr))}.xml-link-confirm-buttons{width:100%}.xml-link-confirm-buttons .btn{width:100%}}@media(max-width:480px){.xml-link-confirm-commercial{grid-template-columns:1fr}}
</style>
<div class="xml-product-search-modal" id="xmlProductSearchModal" aria-hidden="true">
  <div class="xml-product-search-dialog">
    <div class="xml-product-search-head">
      <div><h2>Buscar produto</h2><div class="fiscal-muted">Digite a pesquisa e pressione Enter ou F8. Depois use ↑/↓ e Enter para selecionar.</div></div>
      <button type="button" class="xml-product-close" id="xmlProductSearchClose" aria-label="Fechar">×</button>
    </div>
    <div class="xml-product-search-controls">
      <label class="xml-product-search-field xml-product-search-field-mode"><span>Pesquisar por</span><select class="xml-product-search-select" id="xmlProductSearchMode"><option value="instant">Pesquisa geral</option><option value="code">Código do cadastro</option><option value="description">Descrição</option><option value="barcode">Código de barras</option></select></label>
      <label class="xml-product-search-field xml-product-search-field-query"><span>Pesquisa</span><input class="xml-product-search-input" id="xmlProductSearchInput" placeholder="Digite código, barras ou descrição" autocomplete="off"></label>
      <div class="xml-product-search-action"><button type="button" class="btn btn-primary" id="xmlProductSearchGo" title="Atalho F8">Buscar F8</button></div>
      <div class="xml-product-search-action"><button type="button" class="btn" id="xmlProductSearchCreate">+ Cadastrar</button></div>
    </div>
    <div class="xml-product-search-results" id="xmlProductSearchResults"><div class="xml-product-search-empty">Digite a pesquisa e pressione Enter ou Buscar F8.</div></div>
    <div class="xml-product-search-actions">
      <span class="fiscal-muted">Ao selecionar um produto, o Nexa abrirá a confirmação do vínculo e das conversões.</span>
      <button type="button" class="btn" id="xmlProductSearchCancel">Fechar</button>
    </div>
  </div>
</div>

<div class="xml-link-confirm-modal" id="xmlLinkConfirmModal" aria-hidden="true">
  <div class="xml-link-confirm-dialog">
    <div class="xml-product-search-head">
      <div>
        <h2>Confirmar vínculo e conversões</h2>
        <div class="fiscal-muted">Confira o produto escolhido, o fator de conversão e a regra fiscal do XML antes de confirmar.</div>
      </div>
      <button type="button" class="xml-product-close" id="xmlLinkConfirmClose" aria-label="Fechar">×</button>
    </div>
    <div class="xml-link-confirm-products">
      <div><span>Produto no XML</span><strong id="xmlLinkConfirmXmlProduct">—</strong><small id="xmlLinkConfirmXmlMeta">—</small></div>
      <div><span>Produto no sistema</span><strong id="xmlLinkConfirmSystemProduct">—</strong><small id="xmlLinkConfirmSystemMeta">—</small></div>
    </div>
    <div class="xml-link-confirm-factor">
      <label>Fator de conversão<input id="xmlLinkConfirmFactor" inputmode="decimal" value="1,000000"></label>
      <div class="xml-link-confirm-summary" id="xmlLinkConfirmStockSummary">—</div>
    </div>
    <div class="xml-link-confirm-commercial">
      <label>Último custo<input id="xmlLinkOldCost" readonly></label>
      <label>Margem<input id="xmlLinkOldMargin" readonly></label>
      <label>Pr. Venda anterior<input id="xmlLinkOldPrice" readonly></label>
      <label>Novo custo previsto<input id="xmlLinkNewCost" readonly></label>
      <label>Margem prevista<input id="xmlLinkNewMargin" readonly></label>
      <label>Pr. Venda previsto<input id="xmlLinkNewPrice" readonly></label>
    </div>
    <div class="fiscal-muted" style="margin-top:6px">A alteração definitiva de custo, margem e preço é feita na <b>Tela de Preços</b> antes de gerar a entrada.</div>
    <div class="xml-link-product-tax">
      <label>Tributação de saída do produto no cadastro
        <select id="xmlLinkProductTaxProfile">
          <option value="">Manter tributação atual / sem perfil</option>
          <?php foreach ($taxProfiles as $tp): if (function_exists('fiscal_normalize_item_type') && fiscal_normalize_item_type($tp['profile_type'] ?? 'PRODUCT') !== 'PRODUCT') continue; ?>
            <option value="<?= (int)$tp['id'] ?>"><?= fiscal_h(trim((string)($tp['code'] ?? '') . ' - ' . (string)($tp['name'] ?? ''), ' -')) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <div class="xml-link-product-tax-preview" id="xmlLinkProductTaxPreview">Confira aqui os CSTs e CFOPs do cadastro do produto.</div>
    </div>
    <div class="xml-link-confirm-tax">
      <div class="xml-tax-box">
        <h3>Tributação recebida no XML</h3>
        <div class="xml-tax-fields">
          <label>CFOP<input id="xmlLinkXmlCfop" readonly></label>
          <label>Origem<input id="xmlLinkXmlOrigem" readonly></label>
          <label>ICMS<input id="xmlLinkXmlIcms" readonly></label>
          <label>IPI<input id="xmlLinkXmlIpi" readonly></label>
          <label>PIS<input id="xmlLinkXmlPis" readonly></label>
          <label>COFINS<input id="xmlLinkXmlCofins" readonly></label>
        </div>
      </div>
      <div class="xml-tax-box xml-tax-dest">
        <h3>Conversão para entrada</h3>
        <div class="xml-tax-rule-badge off" id="xmlLinkRuleBadge">Sem regra específica</div>
        <div class="xml-tax-fields">
          <label>CFOP entrada<input id="xmlLinkEntryCfop" inputmode="numeric" maxlength="4"></label>
          <label>Origem<input id="xmlLinkEntryOrigem" inputmode="numeric" maxlength="2"></label>
          <label>CST ICMS<input id="xmlLinkEntryIcmsCst" maxlength="5"></label>
          <label>CSOSN ICMS<input id="xmlLinkEntryIcmsCsosn" maxlength="5"></label>
          <label>CST IPI<input id="xmlLinkEntryIpi" maxlength="5"></label>
          <label>CST PIS<input id="xmlLinkEntryPis" maxlength="5"></label>
          <label>CST COFINS<input id="xmlLinkEntryCofins" maxlength="5"></label>
        </div>
      </div>
    </div>
    <div class="xml-link-confirm-message" id="xmlLinkConfirmMessage" aria-live="polite"></div>
    <div class="xml-product-search-actions">
      <span class="fiscal-muted">O vínculo produto/fornecedor e o fator serão reutilizados automaticamente nas próximas notas do mesmo item.</span>
      <div class="xml-link-confirm-buttons">
        <button type="button" class="btn" id="xmlLinkConfirmCancel">Cancelar</button>
        <button type="button" class="btn btn-primary" id="xmlLinkConfirmSave">Confirmar vínculo e conversões</button>
      </div>
    </div>
  </div>
</div>
<style>
.xml-product-modal{position:fixed;inset:0;z-index:500;display:none;align-items:flex-start;justify-content:center;padding:24px;background:rgba(15,23,42,.58);backdrop-filter:blur(3px);overflow:auto}.xml-product-modal.open{display:flex}.xml-product-dialog{width:min(1100px,100%);background:#fff;border-radius:24px;border:1px solid #dbe4f0;box-shadow:0 28px 90px rgba(2,6,23,.35);padding:18px}.xml-product-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;border-bottom:1px solid #e5edf6;padding-bottom:12px;margin-bottom:14px}.xml-product-head h2{margin:0;color:#0b1f5b;font-size:22px}.xml-product-close{width:42px;height:42px;border:1px solid #dbe4f0;background:#fff;border-radius:14px;font-size:24px;font-weight:900;cursor:pointer}.xml-product-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.xml-product-grid .span-2{grid-column:span 2}.xml-product-grid .span-3{grid-column:1/-1}.xml-product-grid label{display:grid;gap:5px;font-weight:850;color:#334155}.xml-product-grid input,.xml-product-grid select,.xml-product-grid textarea{width:100%;min-width:0;border:1px solid #dbe4f0;border-radius:12px;padding:10px 11px;font:inherit;box-sizing:border-box}.xml-product-grid input[readonly]{background:#f8fafc;color:#64748b}.xml-product-grid-field{display:grid;gap:5px;font-weight:850;color:#334155}.xml-product-top-description,.xml-product-top-category{align-self:start}.xml-product-top-description{grid-template-rows:18px 42px}.xml-product-top-description>span,.xml-product-top-category>label{display:flex;align-items:center;min-height:18px;line-height:18px;margin:0}.xml-product-top-description>input{height:42px;margin:0}.xml-product-top-category{grid-template-rows:18px 42px}.xml-category-lookup-field{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:stretch;height:42px}.xml-category-lookup-field input,.xml-category-lookup-field .btn{height:42px;min-height:42px;box-sizing:border-box;margin:0;white-space:nowrap}.xml-product-commercial-row{grid-column:1/-1;display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;align-items:end}.xml-product-commercial-row label{min-width:0;font-size:12px}.xml-product-commercial-row input{height:42px}.xml-tax-profile-field,.xml-product-origin-field{align-self:start}.xml-product-subtitle{grid-column:1/-1;border-top:1px solid #e5edf6;margin-top:2px;padding-top:12px;font-weight:950;color:#0b1f5b}.xml-product-file{display:flex;gap:10px;align-items:center}.xml-product-file img{width:58px;height:58px;object-fit:cover;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc}.xml-category-visibility{border:1px solid #dbe4f0;background:#f8fafc;border-radius:16px;padding:12px;display:grid;gap:10px}.xml-category-visibility strong{display:block;color:#0b1f5b;font-size:14px;font-weight:950}.xml-category-visibility span{display:block;color:#64748b;font-size:12px;line-height:1.35}.xml-category-flags{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:10px 18px;align-items:center}.xml-category-visibility label{display:flex;align-items:center;gap:8px;font-size:13px;color:#334155;font-weight:850;min-width:0}.xml-category-visibility input[type=checkbox]{width:16px;height:16px;min-width:16px;margin:0;flex:0 0 16px}.xml-category-flags label span{display:inline;color:#334155;font-size:13px;line-height:1.25}.xml-category-visibility-head{display:flex;justify-content:space-between;align-items:flex-start;gap:10px;flex-wrap:wrap}.xml-category-current{border:1px solid #cbd5e1;background:#fff;border-radius:999px;padding:5px 9px;color:#475569;font-size:12px;font-weight:850;line-height:1.25}.xml-category-status{display:flex;gap:8px;align-items:center;flex-wrap:wrap;border:1px dashed #cbd5e1;background:#fff;border-radius:14px;padding:8px}.xml-category-status .status-text{flex-basis:100%;color:#475569;font-size:12px;font-weight:800;line-height:1.3}.xml-category-status .status-chip{display:inline-flex;align-items:center;border-radius:999px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;padding:4px 8px;font-size:12px;font-weight:950}.xml-category-status .status-chip.hidden-cat{border-color:#fecaca;background:#fee2e2;color:#991b1b}.xml-product-summary{border:1px solid #bfdbfe;background:#eff6ff;border-radius:16px;padding:12px;margin:12px 0;color:#1e3a8a;font-weight:850}.xml-profile-preview{margin-top:7px;border:1px dashed #cbd5e1;background:#f8fafc;border-radius:12px;padding:8px 10px;color:#475569;font-size:12px;font-weight:800;line-height:1.35}.xml-product-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px;flex-wrap:wrap}.xml-tax-compare{grid-column:1/-1;display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:14px}.xml-tax-box{border:1px solid #dbe4f0;background:#f8fafc;border-radius:18px;padding:12px}.xml-tax-box.xml-tax-dest{background:#f0fdf4;border-color:#bbf7d0}.xml-tax-box h3{margin:0 0 4px;color:#0b1f5b;font-size:16px}.xml-tax-box p{margin:0 0 10px;color:#64748b;font-size:12px}.xml-tax-fields{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.xml-tax-fields label{font-size:12px}.xml-tax-rule-badge{display:inline-flex;align-items:center;width:max-content;max-width:100%;border-radius:999px;border:1px solid #bbf7d0;background:#dcfce7;color:#166534;font-size:12px;font-weight:900;padding:5px 9px;margin:4px 0 10px}.xml-tax-rule-badge.off{border-color:#e5e7eb;background:#f8fafc;color:#64748b}.xml-category-lookup-modal{position:fixed;inset:0;z-index:565;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.55);backdrop-filter:blur(2px)}.xml-category-lookup-modal.open{display:flex}.xml-category-lookup-dialog{width:min(720px,96vw);max-height:86vh;background:#fff;border-radius:20px;border:1px solid #dbe4f0;box-shadow:0 24px 70px rgba(15,23,42,.26);overflow:hidden;display:flex;flex-direction:column}.xml-category-lookup-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;padding:14px 16px;border-bottom:1px solid #e5edf6;background:#f8fbff}.xml-category-lookup-head strong{display:block;color:#0b1f5b;font-size:18px}.xml-category-lookup-head span{display:block;color:#64748b;font-size:12px;margin-top:2px}.xml-category-lookup-body{padding:14px 16px;display:grid;gap:10px;overflow:auto}.xml-category-lookup-search{width:100%;border:1px solid #dbe4f0;border-radius:12px;padding:10px 11px;font:inherit;box-sizing:border-box}.xml-category-lookup-count{font-size:11px;color:#64748b;min-height:16px}.xml-category-lookup-results{border:1px solid #dbe4f0;border-radius:14px;overflow:auto;max-height:52vh;min-height:180px;background:#fff;outline:none}.xml-category-lookup-result{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:12px;align-items:center;width:100%;border:0;border-bottom:1px solid #eef2f7;background:#fff;padding:10px 12px;text-align:left;cursor:pointer;color:#0f172a}.xml-category-lookup-result:last-child{border-bottom:0}.xml-category-lookup-result:hover,.xml-category-lookup-result.is-active{background:#eef5ff}.xml-category-lookup-result strong{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.xml-category-lookup-result small{color:#64748b}.xml-category-lookup-empty{padding:22px;text-align:center;color:#64748b}@media(max-width:760px){.xml-product-modal{padding:10px}.xml-product-dialog{border-radius:20px;padding:14px}.xml-product-grid{grid-template-columns:1fr}.xml-product-grid .span-2,.xml-product-grid .span-3,.xml-product-subtitle,.xml-tax-compare{grid-column:auto}.xml-product-actions .btn{width:100%}.xml-tax-compare{grid-template-columns:1fr}.xml-tax-fields{grid-template-columns:1fr}.xml-category-flags{grid-template-columns:1fr}.xml-category-lookup-field{grid-template-columns:minmax(0,1fr) auto}.xml-product-commercial-row{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:480px){.xml-product-commercial-row{grid-template-columns:1fr}}
</style>
<div class="xml-product-modal" id="xmlProductModal" aria-hidden="true">
  <form method="post" enctype="multipart/form-data" class="xml-product-dialog fiscal-form" id="xmlProductModalForm">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="action" value="modal_create_product">
    <input type="hidden" name="quick_modal_item_id" id="modalItemId" value="">
    <div class="xml-product-head">
      <div>
        <h2>Cadastrar produto pelo XML</h2>
        <div class="fiscal-muted">Revise os dados da NF-e, ajuste o cadastro se necessário e salve para vincular automaticamente ao item da nota.</div>
      </div>
      <button type="button" class="xml-product-close" data-close-product-modal aria-label="Fechar">×</button>
    </div>
    <div class="xml-product-grid">
      <label class="span-2 xml-product-top-description"><span>Descrição do produto</span><input name="quick_modal_name" id="modalName" required maxlength="220"></label>
      <div class="xml-product-grid-field xml-product-top-category">
        <label for="modalCategory">Categoria</label>
        <div class="xml-category-lookup-field">
          <input name="quick_modal_category" id="modalCategory" value="" maxlength="120" placeholder="Digite nova categoria ou use Buscar F8" autocomplete="off">
          <button class="btn btn-sm" type="button" id="modalCategoryLookupButton" title="Buscar categoria cadastrada - F8">Buscar F8</button>
        </div>
      </div>
      <div class="xml-category-visibility span-3">
        <div class="xml-category-visibility-head">
          <div>
            <strong>Configurações de visibilidade da categoria</strong>
            <span>Ao selecionar uma categoria já cadastrada, o sistema mostra a configuração atual. Ao salvar, estas opções são aplicadas para todos os produtos desta categoria.</span>
          </div>
          <div class="xml-category-current" id="modalCategoryVisibilityCurrent">Informe ou selecione uma categoria</div>
        </div>
        <div class="xml-category-status" id="modalCategoryVisibilityStatus" aria-live="polite"></div>
        <div class="xml-category-flags">
          <label><input type="checkbox" name="quick_modal_category_hide_order_catalog" id="modalHideOrderCatalog" value="1"><span>Ocultar esta categoria no catálogo de pedidos</span></label>
          <label><input type="checkbox" name="quick_modal_category_hide_product_catalog" id="modalHideProductCatalog" value="1"><span>Ocultar esta categoria no catálogo de produtos</span></label>
        </div>
      </div>
      <label>Código de barras / EAN<input name="quick_modal_barcode" id="modalBarcode" maxlength="30"></label>
      <label>Código do fornecedor<input name="quick_modal_supplier_code" id="modalSupplierCode" maxlength="80"></label>
      <label>Unidade<input name="quick_modal_unit" id="modalUnit" maxlength="20" placeholder="UN, CX, KG"></label>
      <div class="xml-product-commercial-row">
        <label>Fator de conversão<input name="quick_modal_factor" id="modalFactor" inputmode="decimal" value="1"></label>
        <?php if ($gestaoEnabled): ?><label>Custo Unitário (Total XML / quant. convertida)<input name="quick_modal_cost" id="modalCost" inputmode="decimal" value="0,0000" readonly></label><?php endif; ?>
        <label>Margem s/ custo<input id="modalMarkup" inputmode="decimal" placeholder="0,00"></label>
        <label>Preço de venda<input name="quick_modal_sale_price" id="modalSalePrice" inputmode="decimal" value="0,00"></label>
      </div>
      <label class="span-2">Foto do produto
        <div class="xml-product-file">
          <img id="modalImagePreview" src="../assets/no-image.svg" alt="Preview">
          <input type="file" name="quick_modal_image" id="modalImage" accept=".jpg,.jpeg,.png,.webp">
        </div>
      </label>
      <div class="xml-product-subtitle">Tributação do cadastro do produto x tributação da entrada da NF-e</div>
      <label class="span-2 xml-tax-profile-field">Tributação de saída do produto no cadastro
        <select name="quick_modal_tax_profile_id" id="modalTaxProfileId">
          <option value="">Selecione a tributação</option>
          <?php foreach ($taxProfiles as $tp): ?>
            <option value="<?= (int)$tp['id'] ?>"><?= fiscal_h(trim((string)($tp['code'] ?? '') . ' - ' . (string)($tp['name'] ?? ''), ' -')) ?></option>
          <?php endforeach; ?>
        </select>
        <div class="xml-profile-preview" id="modalProductTaxPreview">Selecione uma tributação para conferir os CSTs de saída que serão gravados no produto.</div>
      </label>
      <label class="xml-product-origin-field">Origem do produto<input name="quick_modal_product_origem" id="modalProductOrigem" inputmode="numeric" maxlength="2"><span class="fiscal-muted">Usada no cadastro fiscal do produto.</span></label>
      <label>NCM<input name="quick_modal_ncm" id="modalNcm" inputmode="numeric" maxlength="12"></label>
      <label>CEST<input name="quick_modal_cest" id="modalCest" inputmode="numeric" maxlength="12"></label>
      <div class="xml-tax-compare">
        <div class="xml-tax-box">
          <h3>Tributação que veio no XML</h3>
          <p>Origem do fornecedor, apenas para conferência.</p>
          <div class="xml-tax-fields">
            <label>CFOP XML<input id="modalXmlCfop" readonly></label>
            <label>Origem XML<input id="modalXmlOrigem" readonly></label>
            <label>CST ICMS XML<input id="modalXmlIcmsCst" readonly></label>
            <label>CSOSN ICMS XML<input id="modalXmlIcmsCsosn" readonly></label>
            <label>CST IPI XML<input id="modalXmlIpiCst" readonly></label>
            <label>CST PIS XML<input id="modalXmlPisCst" readonly></label>
            <label>CST COFINS XML<input id="modalXmlCofinsCst" readonly></label>
          </div>
        </div>
        <div class="xml-tax-box xml-tax-dest">
          <h3>Conversão para entrada</h3>
          <p>Valores da entrada da NF-e. Estes campos serão guardados no item do XML/entrada para conferência fiscal, SPED e Sintegra, sem alterar os CSTs de saída do cadastro do produto.</p>
          <div class="xml-tax-rule-badge off" id="modalTaxRuleBadge">Sem regra automática — ajuste manualmente</div>
          <div class="xml-tax-fields">
            <label>CFOP entrada<input name="quick_modal_entry_cfop" id="modalCfop" inputmode="numeric" maxlength="4"></label>
            <label>Origem<input name="quick_modal_entry_origem" id="modalOrigem" inputmode="numeric" maxlength="2"></label>
            <label>CST ICMS<input name="quick_modal_entry_icms_cst" id="modalIcmsCst" maxlength="5"></label>
            <label>CSOSN ICMS<input name="quick_modal_entry_icms_csosn" id="modalIcmsCsosn" maxlength="5"></label>
            <label>CST IPI<input name="quick_modal_entry_ipi_cst" id="modalIpiCst" maxlength="5"></label>
            <label>CST PIS<input name="quick_modal_entry_pis_cst" id="modalPisCst" maxlength="5"></label>
            <label>CST COFINS<input name="quick_modal_entry_cofins_cst" id="modalCofinsCst" maxlength="5"></label>
          </div>
        </div>
      </div>
      <label class="span-3">Descrição do catálogo <span class="fiscal-muted">(opcional — aparece para o cliente)</span><textarea name="quick_modal_description" id="modalDescription" rows="3" placeholder="Informe somente uma descrição comercial do produto, se desejar."></textarea></label>
      <label class="span-3">Observação fiscal / interna <span class="fiscal-muted">(não aparece no catálogo)</span><textarea name="quick_modal_fiscal_notes" id="modalFiscalNotes" rows="3"></textarea></label>
    </div>
    <div class="xml-product-summary" id="modalStockSummary">Quantidade convertida e custo serão calculados ao abrir o item.</div>
    <div class="xml-product-actions">
      <button type="button" class="btn" data-close-product-modal>Cancelar</button>
      <button class="btn btn-primary" type="submit">Cadastrar e vincular produto</button>
    </div>
  </form>
</div>
<?php endif; ?>
<div class="xml-category-lookup-modal" id="modalCategoryLookup" aria-hidden="true">
  <div class="xml-category-lookup-dialog" role="dialog" aria-modal="true" aria-labelledby="modalCategoryLookupTitle">
    <div class="xml-category-lookup-head">
      <div>
        <strong id="modalCategoryLookupTitle">Buscar categoria</strong>
        <span>Digite a pesquisa e pressione Enter. Use ↑/↓ e Enter para selecionar.</span>
      </div>
      <button class="btn btn-sm" type="button" id="modalCategoryLookupClose">Fechar</button>
    </div>
    <div class="xml-category-lookup-body">
      <input class="xml-category-lookup-search" id="modalCategoryLookupSearch" autocomplete="off" placeholder="Digite a pesquisa e pressione Enter">
      <div class="xml-category-lookup-count" id="modalCategoryLookupCount"></div>
      <div class="xml-category-lookup-results" id="modalCategoryLookupResults" role="listbox" tabindex="0"></div>
    </div>
  </div>
</div>
<script>
(function(){
  const optionMap = {};
  document.querySelectorAll('#xmlProdutoOptions option').forEach(function(opt){ optionMap[opt.value] = opt.dataset.productId || ''; });
  document.querySelectorAll('[data-product-search]').forEach(function(input){
    const id = input.dataset.productSearch;
    const hidden = document.querySelector('[data-product-id="' + id + '"]');
    const sync = function(){ if (hidden && optionMap[input.value]) hidden.value = optionMap[input.value]; else if (hidden && !/^#\d+\s*-/.test(input.value)) hidden.value = ''; };
    input.addEventListener('change', sync);
    input.addEventListener('input', sync);
  });

  const itemLinkData = <?= json_encode($itemLinkPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const xmlPricingPolicy = <?= json_encode($xmlPricingPolicy, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const searchModal = document.getElementById('xmlProductSearchModal');
  const searchInput = document.getElementById('xmlProductSearchInput');
  const searchMode = document.getElementById('xmlProductSearchMode');
  const searchResults = document.getElementById('xmlProductSearchResults');
  const searchGo = document.getElementById('xmlProductSearchGo');
  const linkConfirmModal = document.getElementById('xmlLinkConfirmModal');
  const linkConfirmSave = document.getElementById('xmlLinkConfirmSave');
  let searchItemId = '';
  let searchRows = [];
  let searchActive = -1;
  let linkConfirmItemId = '';
  let linkConfirmProduct = null;
  let linkPricingLastEdited = xmlPricingPolicy === 'keep_price' ? 'price' : 'margin';
  let linkPricingInternal = false;

  function escHtml(v){ return String(v == null ? '' : v).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c];}); }
  function setText(id, value){ const el=document.getElementById(id); if(el) el.textContent=String(value == null ? '' : value); }
  function setValue(id, value){ const el=document.getElementById(id); if(el) el.value=String(value == null ? '' : value); }

  function openSearchModal(itemId){
    searchItemId = String(itemId || ''); searchRows=[]; searchActive=-1;
    if (!searchModal) return;
    searchModal.classList.add('open'); searchModal.setAttribute('aria-hidden','false');
    if (searchInput) { searchInput.value = ''; setTimeout(function(){searchInput.focus();},50); }
    renderSearchRows([],'Digite a pesquisa e pressione Enter ou Buscar F8.');
  }
  function closeSearchModal(){ if(searchModal){searchModal.classList.remove('open');searchModal.setAttribute('aria-hidden','true');} searchRows=[];searchActive=-1; }
  function renderSearchRows(rows, emptyText){
    searchRows = Array.isArray(rows) ? rows : []; searchActive=-1;
    if (!searchResults) return; searchResults.innerHTML='';
    if (!searchRows.length) { searchResults.innerHTML='<div class="xml-product-search-empty">'+escHtml(emptyText||'Nenhum produto encontrado.')+'</div>'; return; }
    searchRows.forEach(function(r,idx){
      const el=document.createElement('div'); el.className='xml-product-search-result'; el.tabIndex=0; el.dataset.index=String(idx);
      let meta='Código '+escHtml(r.code||r.id); if(r.barcode) meta+=' • Barras '+escHtml(r.barcode); if(r.category) meta+=' • '+escHtml(r.category); if(r.unit) meta+=' • '+escHtml(r.unit);
      el.innerHTML='<strong>'+escHtml(r.name||'Produto')+'</strong><small>'+meta+'</small>';
      el.addEventListener('click',function(){selectSearchRow(idx);});
      el.addEventListener('keydown',function(e){
        if(e.key==='ArrowDown'){e.preventDefault();focusSearchRow(idx+1);}
        else if(e.key==='ArrowUp'){e.preventDefault();if(idx===0){if(searchInput)searchInput.focus();searchActive=-1;}else focusSearchRow(idx-1);}
        else if(e.key==='Enter'){e.preventDefault();selectSearchRow(idx);}
        else if(e.key==='F8'){e.preventDefault();runProductSearch();}
        else if(e.key.length===1 && !e.ctrlKey && !e.altKey && !e.metaKey){e.preventDefault();if(searchInput){searchInput.value=e.key;searchInput.focus();renderSearchRows([],'Digite a nova pesquisa e pressione Enter ou Buscar F8.');}}
      });
      searchResults.appendChild(el);
    });
  }
  async function runProductSearch(){
    if(!searchInput)return;
    const q=searchInput.value.trim();
    if(q===''){renderSearchRows([],'Digite a pesquisa e pressione Enter ou Buscar F8.');searchInput.focus();return;}
    const mode=searchMode?searchMode.value:'instant';
    renderSearchRows([],'Pesquisando...');
    try{
      const r=await fetch(<?= json_encode(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$id), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>+'&ajax=products&mode='+encodeURIComponent(mode)+'&q='+encodeURIComponent(q),{headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d=await r.json(); if(!d.ok)throw new Error(d.message||'Falha na pesquisa.');
      renderSearchRows(d.rows||[],'Nenhum produto encontrado. Use “+ Cadastrar”.');
      if ((d.rows||[]).length && searchInput) searchInput.focus();
    }catch(e){renderSearchRows([],e.message||'Falha na pesquisa.');}
  }
  function focusSearchRow(idx){const els=Array.from(document.querySelectorAll('.xml-product-search-result'));if(!els.length)return;idx=Math.max(0,Math.min(idx,els.length-1));searchActive=idx;els.forEach(function(el,i){el.classList.toggle('active',i===idx)});els[idx].focus();els[idx].scrollIntoView({block:'nearest'});}
  function selectSearchRow(idx){const r=searchRows[idx];if(!r||!searchItemId)return;const itemId=searchItemId;closeSearchModal();openLinkConfirm(itemId,r);}

  document.querySelectorAll('[data-search-product-item]').forEach(function(btn){btn.addEventListener('click',function(){if(btn.disabled)return;openSearchModal(btn.dataset.searchProductItem||'');});});
  document.querySelectorAll('[data-product-search]').forEach(function(input){input.addEventListener('keydown',function(e){if(input.readOnly)return;if(e.key==='F8'||e.key==='Enter'){e.preventDefault();openSearchModal(input.dataset.productSearch||'');}});});
  if(searchMode){searchMode.addEventListener('change',function(){renderSearchRows([],'Digite a pesquisa e pressione Enter ou Buscar F8.');if(searchInput)searchInput.focus();});}
  if(searchInput){
    searchInput.addEventListener('input',function(){if(searchRows.length)renderSearchRows([],'Pressione Enter ou Buscar F8 para pesquisar.');});
    searchInput.addEventListener('keydown',function(e){
      if(e.key==='ArrowDown'){e.preventDefault();focusSearchRow(0);}
      else if(e.key==='Enter'||e.key==='F8'){e.preventDefault();runProductSearch();}
      else if(e.key==='Escape'){e.preventDefault();closeSearchModal();}
    });
  }
  if(searchGo) searchGo.addEventListener('click',runProductSearch);
  const searchClose=document.getElementById('xmlProductSearchClose'),searchCancel=document.getElementById('xmlProductSearchCancel'),searchCreate=document.getElementById('xmlProductSearchCreate');
  if(searchClose)searchClose.addEventListener('click',closeSearchModal);if(searchCancel)searchCancel.addEventListener('click',closeSearchModal);if(searchModal)searchModal.addEventListener('click',function(e){if(e.target===searchModal)closeSearchModal();});
  if(searchCreate)searchCreate.addEventListener('click',function(){const itemId=searchItemId;closeSearchModal();const createBtn=document.querySelector('.open-xml-product-modal[data-item-id="'+itemId+'"]');if(createBtn)createBtn.click();});

  function closeLinkConfirm(){
    if(linkConfirmModal){linkConfirmModal.classList.remove('open');linkConfirmModal.setAttribute('aria-hidden','true');}
    const msg=document.getElementById('xmlLinkConfirmMessage'); if(msg){msg.className='xml-link-confirm-message';msg.textContent='';}
    linkConfirmProduct=null; linkConfirmItemId='';
  }
  function setLinkTaxReadonly(readonly){
    ['xmlLinkEntryCfop','xmlLinkEntryOrigem','xmlLinkEntryIcmsCst','xmlLinkEntryIcmsCsosn','xmlLinkEntryIpi','xmlLinkEntryPis','xmlLinkEntryCofins'].forEach(function(id){const el=document.getElementById(id);if(el)el.readOnly=!!readonly;});
  }
  function calcMargin(cost, price){ return cost>0 ? ((price-cost)/cost)*100 : 0; }
  function renderLinkProductTaxPreview(){
    const select=document.getElementById('xmlLinkProductTaxProfile');
    const preview=document.getElementById('xmlLinkProductTaxPreview');
    if(!preview)return;
    const selectedId=select?String(select.value||''):'';
    const tp=selectedId?taxProfiles[selectedId]||null:null;
    if(tp){
      preview.textContent='ICMS CST '+(tp.icms_cst||'—')+' • CSOSN '+(tp.icms_csosn||'—')+' • IPI '+(tp.ipi_cst||'—')+' • PIS '+(tp.pis_out_cst||'—')+' • COFINS '+(tp.cofins_out_cst||'—')+' • CFOP venda '+(tp.cfop_sale_state||'—')+'/'+(tp.cfop_sale_interstate||'—');
      return;
    }
    const p=linkConfirmProduct||{};
    preview.textContent='Cadastro atual: ICMS CST '+(p.tax_icms_cst||'—')+' • CSOSN '+(p.tax_icms_csosn||'—')+' • IPI '+(p.tax_ipi_cst||'—')+' • PIS '+(p.tax_pis_cst||'—')+' • COFINS '+(p.tax_cofins_cst||'—')+' • CFOP venda '+(p.tax_cfop_sale_state||'—')+'/'+(p.tax_cfop_sale_interstate||'—');
  }
  function updateLinkNewPriceFromMargin(unitCost){
    const marginEl=document.getElementById('xmlLinkNewMargin'),priceEl=document.getElementById('xmlLinkNewPrice');
    if(!marginEl||!priceEl)return;
    const margin=parseBr(marginEl.value);
    linkPricingInternal=true;
    priceEl.value=fmtBr(Math.max(0,unitCost*(1+(margin/100))),2);
    linkPricingInternal=false;
  }
  function updateLinkNewMarginFromPrice(unitCost){
    const marginEl=document.getElementById('xmlLinkNewMargin'),priceEl=document.getElementById('xmlLinkNewPrice');
    if(!marginEl||!priceEl)return;
    const price=parseBr(priceEl.value);
    linkPricingInternal=true;
    marginEl.value=unitCost>0?fmtBr(calcMargin(unitCost,price),2):'';
    linkPricingInternal=false;
  }
  function recalcLinkConfirm(mode){
    const data=itemLinkData[linkConfirmItemId]||{};
    let factor=parseBr((document.getElementById('xmlLinkConfirmFactor')||{}).value||'1'); if(!factor||factor<=0)factor=1;
    const qty=Number(data.quantity||0), total=Number(data.landed_base_value||data.total_value||0), stockQty=qty*factor, unitCost=stockQty>0?total/stockQty:0;
    setText('xmlLinkConfirmStockSummary','Qtd. XML: '+fmtBr(qty,4)+' '+(data.unit||'')+' × fator '+fmtBr(factor,6)+' = estoque '+fmtBr(stockQty,4)+' • custo preliminar c/ encargos R$ '+fmtBr(unitCost,4));
    setValue('xmlLinkNewCost',fmtBr(unitCost,4));
    let calcMode=mode||((xmlPricingPolicy==='keep_price')?'price':'margin');
    if(calcMode==='margin'){
      const marginEl=document.getElementById('xmlLinkNewMargin');
      if(!marginEl||String(marginEl.value||'').trim()==='') calcMode='price';
    }
    if(calcMode==='price') updateLinkNewMarginFromPrice(unitCost); else updateLinkNewPriceFromMargin(unitCost);
  }
  function openLinkConfirm(itemId, product){
    linkConfirmItemId=String(itemId||''); linkConfirmProduct=product||null;
    const data=itemLinkData[linkConfirmItemId]||{};
    if(!linkConfirmModal||!linkConfirmProduct)return;
    setText('xmlLinkConfirmXmlProduct',data.name||'Produto do XML');
    setText('xmlLinkConfirmXmlMeta','Cód. fornecedor: '+(data.product_code||'—')+(data.ean?' • EAN '+data.ean:'')+' • Qtd. '+fmtBr(Number(data.quantity||0),4)+' '+(data.unit||''));
    setText('xmlLinkConfirmSystemProduct','#'+linkConfirmProduct.id+' - '+(linkConfirmProduct.name||'Produto'));
    setText('xmlLinkConfirmSystemMeta','Código '+(linkConfirmProduct.code||linkConfirmProduct.id)+(linkConfirmProduct.barcode?' • Barras '+linkConfirmProduct.barcode:'')+(linkConfirmProduct.category?' • '+linkConfirmProduct.category:''));
    const factorInput=document.querySelector('[data-factor="'+linkConfirmItemId+'"]');
    setValue('xmlLinkConfirmFactor',factorInput&&factorInput.value?factorInput.value:fmtBr(Number(data.factor||1),6));
    const oldCost=Number(linkConfirmProduct.cost||0), oldPrice=Number(linkConfirmProduct.price||0);
    const oldMargin=oldCost>0?calcMargin(oldCost,oldPrice):0;
    setValue('xmlLinkOldCost',fmtBr(oldCost,4));
    setValue('xmlLinkOldMargin',oldCost>0?fmtBr(oldMargin,2):'');
    setValue('xmlLinkOldPrice',fmtBr(oldPrice,2));
    if(xmlPricingPolicy==='keep_price' || oldCost<=0){
      linkPricingLastEdited='price';
      setValue('xmlLinkNewPrice',fmtBr(oldPrice,2));
      setValue('xmlLinkNewMargin','');
    }else{
      linkPricingLastEdited='margin';
      setValue('xmlLinkNewMargin',fmtBr(oldMargin,2));
      setValue('xmlLinkNewPrice',fmtBr(oldPrice,2));
    }
    const productTaxSelect=document.getElementById('xmlLinkProductTaxProfile');
    if(productTaxSelect) productTaxSelect.value=String(linkConfirmProduct.tax_profile_id||'');
    renderLinkProductTaxPreview();
    setValue('xmlLinkXmlCfop',data.xml_cfop||''); setValue('xmlLinkXmlOrigem',data.xml_origem||''); setValue('xmlLinkXmlIcms',data.xml_icms||''); setValue('xmlLinkXmlIpi',data.xml_ipi||''); setValue('xmlLinkXmlPis',data.xml_pis||''); setValue('xmlLinkXmlCofins',data.xml_cofins||'');
    setValue('xmlLinkEntryCfop',data.entry_cfop||data.xml_cfop||''); setValue('xmlLinkEntryOrigem',data.entry_origem||data.xml_origem||''); setValue('xmlLinkEntryIcmsCst',data.entry_icms_cst||''); setValue('xmlLinkEntryIcmsCsosn',data.entry_icms_csosn||''); setValue('xmlLinkEntryIpi',data.entry_ipi||data.xml_ipi||''); setValue('xmlLinkEntryPis',data.entry_pis||data.xml_pis||''); setValue('xmlLinkEntryCofins',data.entry_cofins||data.xml_cofins||'');
    const hasRule=Number(data.tax_rule_id||0)>0 && String(data.tax_rule_name||'').trim()!=='';
    const badge=document.getElementById('xmlLinkRuleBadge');
    if(badge){badge.textContent=hasRule?'Regra cadastrada: '+data.tax_rule_name:'Sem regra específica — será cadastrada automaticamente ao confirmar';badge.classList.toggle('off',!hasRule);}
    setLinkTaxReadonly(hasRule);
    recalcLinkConfirm();
    linkConfirmModal.classList.add('open'); linkConfirmModal.setAttribute('aria-hidden','false');
    setTimeout(function(){const f=document.getElementById('xmlLinkConfirmFactor');if(f){f.focus();f.select();}},60);
  }
  async function saveLinkConfirm(){
    if(!linkConfirmProduct||!linkConfirmItemId||!linkConfirmSave)return;
    const msg=document.getElementById('xmlLinkConfirmMessage');
    if(msg){msg.className='xml-link-confirm-message';msg.textContent='';}
    const fd=new FormData();
    fd.append('action','confirm_product_link');fd.append('ajax','1');fd.append('id','<?= (int)$id ?>');fd.append('item_id',linkConfirmItemId);fd.append('product_id',String(linkConfirmProduct.id||0));
    fd.append('conversion_factor',(document.getElementById('xmlLinkConfirmFactor')||{}).value||'1');
    fd.append('new_sale_price',(document.getElementById('xmlLinkNewPrice')||{}).value||'0');
    fd.append('new_margin',(document.getElementById('xmlLinkNewMargin')||{}).value||'');
    fd.append('product_tax_profile_id',(document.getElementById('xmlLinkProductTaxProfile')||{}).value||'');
    fd.append('entry_cfop',(document.getElementById('xmlLinkEntryCfop')||{}).value||'');fd.append('entry_origem',(document.getElementById('xmlLinkEntryOrigem')||{}).value||'');fd.append('entry_icms_cst',(document.getElementById('xmlLinkEntryIcmsCst')||{}).value||'');fd.append('entry_icms_csosn',(document.getElementById('xmlLinkEntryIcmsCsosn')||{}).value||'');fd.append('entry_ipi_cst',(document.getElementById('xmlLinkEntryIpi')||{}).value||'');fd.append('entry_pis_cst',(document.getElementById('xmlLinkEntryPis')||{}).value||'');fd.append('entry_cofins_cst',(document.getElementById('xmlLinkEntryCofins')||{}).value||'');
    const originalText=linkConfirmSave.textContent;linkConfirmSave.disabled=true;linkConfirmSave.textContent='Salvando...';
    try{
      const r=await fetch(<?= json_encode(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$id), JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>,{method:'POST',body:fd,headers:{'X-Requested-With':'XMLHttpRequest'}});
      const d=await r.json(); if(!d.ok)throw new Error(d.message||'Não foi possível salvar o vínculo.');
      const out=d.result||{}, itemId=String(out.item_id||linkConfirmItemId), productId=Number(out.product_id||linkConfirmProduct.id||0);
      const visible=document.querySelector('[data-product-search="'+itemId+'"]'), hidden=document.querySelector('[data-product-id="'+itemId+'"]'), factor=document.querySelector('[data-factor="'+itemId+'"]');
      if(visible)visible.value='#'+productId+' - '+(out.product_name||linkConfirmProduct.name||'Produto');
      if(hidden)hidden.value=String(productId);
      if(factor)factor.value=fmtBr(Number(out.factor||1),6);
      const qtyEl=document.querySelector('[data-stock-qty="'+itemId+'"]'),costEl=document.querySelector('[data-unit-cost="'+itemId+'"]');
      if(qtyEl)qtyEl.textContent=fmtBr(Number(out.stock_quantity||0),4);if(costEl)costEl.textContent='R$ '+fmtBr(Number(out.stock_unit_cost||0),4);
      const status=document.querySelector('[data-product-link-status="'+itemId+'"]');if(status)status.innerHTML='Vinculado ao produto #'+productId+'. <a href="produto_form.php?id='+productId+'" target="_blank">abrir cadastro</a> • vínculo automático salvo';
      if(itemLinkData[itemId]){
        itemLinkData[itemId].factor=Number(out.factor||1);
        itemLinkData[itemId].tax_rule_id=Number(out.tax_conversion_id||0);
        itemLinkData[itemId].tax_rule_name=out.tax_conversion_name||'';
        if(Number(out.tax_conversion_id||0)>0){
          const src=itemLinkData[itemId];
          Object.keys(itemLinkData).forEach(function(k){const d=itemLinkData[k]||{};if((d.xml_cfop||'')===(src.xml_cfop||'')&&(d.xml_origem||'')===(src.xml_origem||'')&&(d.xml_icms||'')===(src.xml_icms||'')&&(d.xml_ipi||'')===(src.xml_ipi||'')&&(d.xml_pis||'')===(src.xml_pis||'')&&(d.xml_cofins||'')===(src.xml_cofins||'')){d.tax_rule_id=Number(out.tax_conversion_id||0);d.tax_rule_name=out.tax_conversion_name||'';}});
        }
      }
      if(msg){msg.className='xml-link-confirm-message ok';msg.textContent=out.tax_conversion_created?'Vínculo salvo e nova regra de conversão XML cadastrada automaticamente.':'Vínculo e conversões confirmados. O relacionamento será reutilizado nas próximas notas.';}
      const currentItem=itemId;
      setTimeout(function(){closeLinkConfirm();const inputs=Array.from(document.querySelectorAll('[data-product-search]'));const idx=inputs.findIndex(function(el){return el.dataset.productSearch===currentItem;});if(idx>=0&&idx+1<inputs.length&&!inputs[idx+1].readOnly)inputs[idx+1].focus();},500);
    }catch(e){if(msg){msg.className='xml-link-confirm-message err';msg.textContent=e.message||'Não foi possível salvar o vínculo.';}}
    finally{linkConfirmSave.disabled=false;linkConfirmSave.textContent=originalText;}
  }
  const linkFactor=document.getElementById('xmlLinkConfirmFactor');if(linkFactor)linkFactor.addEventListener('input',function(){recalcLinkConfirm();});
  const linkNewMargin=document.getElementById('xmlLinkNewMargin');
  const linkNewPrice=document.getElementById('xmlLinkNewPrice');
  const linkProductTaxProfile=document.getElementById('xmlLinkProductTaxProfile');if(linkProductTaxProfile)linkProductTaxProfile.addEventListener('change',renderLinkProductTaxPreview);
  if(linkConfirmSave)linkConfirmSave.addEventListener('click',saveLinkConfirm);
  const linkClose=document.getElementById('xmlLinkConfirmClose'),linkCancel=document.getElementById('xmlLinkConfirmCancel');if(linkClose)linkClose.addEventListener('click',closeLinkConfirm);if(linkCancel)linkCancel.addEventListener('click',closeLinkConfirm);if(linkConfirmModal)linkConfirmModal.addEventListener('click',function(e){if(e.target===linkConfirmModal)closeLinkConfirm();});
  document.addEventListener('keydown',function(e){
    if(e.key==='Escape'&&linkConfirmModal&&linkConfirmModal.classList.contains('open')){e.preventDefault();closeLinkConfirm();return;}
    if(e.key==='Escape'&&searchModal&&searchModal.classList.contains('open')){e.preventDefault();closeSearchModal();return;}
    if(e.key==='F8'&&searchModal&&searchModal.classList.contains('open')){e.preventDefault();runProductSearch();}
  });
  function parseBr(v){
    v = String(v || '').replace(/R\$|\s/g,'');
    if (v.indexOf(',') >= 0 && v.indexOf('.') >= 0) v = v.replace(/\./g,'');
    v = v.replace(',', '.');
    const n = parseFloat(v);
    return isFinite(n) ? n : 0;
  }
  function fmtBr(n, dec){ return Number(n || 0).toLocaleString('pt-BR', {minimumFractionDigits: dec, maximumFractionDigits: dec}); }
  const taxProfiles = <?= json_encode($taxProfilesPreview, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const categorySettings = <?= json_encode($categorySettings, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  const categorySettingsByKey = {};
  function categoryKey(name){ return String(name || '').trim().toLocaleLowerCase('pt-BR'); }
  Object.keys(categorySettings || {}).forEach(function(name){ categorySettingsByKey[categoryKey(name)] = categorySettings[name]; });
  const itemBase = {
    <?php foreach ($items as $it): ?>
    "<?= (int)($it['id'] ?? 0) ?>": {qty: <?= json_encode((float)($it['quantity'] ?? 0)) ?>, total: <?= json_encode((float)($it['total_value'] ?? 0)) ?>},
    <?php endforeach; ?>
  };
  function selectAllFactorValue(input){
    if (!input || input.readOnly || input.disabled) return;
    window.setTimeout(function(){
      try { input.select(); } catch (e) {}
    }, 0);
  }
  document.querySelectorAll('[data-factor], #xmlLinkConfirmFactor, #modalFactor, input[name^="edit_conversion_factor["]').forEach(function(input){
    input.addEventListener('focus', function(){ selectAllFactorValue(input); });
    input.addEventListener('click', function(){ selectAllFactorValue(input); });
  });

  document.querySelectorAll('[data-factor]').forEach(function(input){
    const id = input.dataset.factor;
    const recalc = function(){
      const base = itemBase[id] || {qty:0,total:0};
      let factor = parseBr(input.value);
      if (!factor || factor <= 0) factor = 1;
      const stockQty = base.qty * factor;
      const unitCost = stockQty > 0 ? base.total / stockQty : 0;
      const qtyEl = document.querySelector('[data-stock-qty="' + id + '"]');
      const costEl = document.querySelector('[data-unit-cost="' + id + '"]');
      if (qtyEl) qtyEl.textContent = fmtBr(stockQty, 4);
      if (costEl) costEl.textContent = 'R$ ' + fmtBr(unitCost, 4);
    };
    input.addEventListener('input', recalc);
  });

  const modal = document.getElementById('xmlProductModal');
  const modalForm = document.getElementById('xmlProductModalForm');
  let modalPricingLastEdited = 'price';
  let modalPricingInternal = false;
  function setModalValue(id, value){ const el = document.getElementById(id); if (el) el.value = value == null ? '' : String(value); }
  function getModalValue(id){ const el = document.getElementById(id); return el ? el.value : ''; }
  function openModal(){ if (modal) { modal.classList.add('open'); modal.setAttribute('aria-hidden', 'false'); } }
  function closeModal(){ if (modal) { modal.classList.remove('open'); modal.setAttribute('aria-hidden', 'true'); } }
  function recalcModalPricing(cost, mode){
    const markup=document.getElementById('modalMarkup'),price=document.getElementById('modalSalePrice');
    if(!markup||!price)return;
    let calcMode=mode||((xmlPricingPolicy==='keep_price')?'price':'markup');
    if(calcMode==='markup'){
      const raw=String(markup.value||'').trim();
      if(raw==='') return;
      const m=parseBr(raw);
      modalPricingInternal=true;
      price.value=fmtBr(Math.max(0,cost*(1+(m/100))),2);
      modalPricingInternal=false;
    }else{
      const p=parseBr(price.value);
      modalPricingInternal=true;
      markup.value=(cost>0&&p>0)?fmtBr(((p-cost)/cost)*100,2):'';
      modalPricingInternal=false;
    }
  }
  function recalcModal(mode){
    const itemId = getModalValue('modalItemId');
    const base = itemBase[itemId] || {qty:0,total:0};
    let factor = parseBr(getModalValue('modalFactor'));
    if (!factor || factor <= 0) factor = 1;
    const converted = base.qty * factor;
    const cost = converted > 0 ? base.total / converted : 0;
    const costInput = document.getElementById('modalCost');
    if (costInput) costInput.value = fmtBr(cost, 4);
    recalcModalPricing(cost, mode);
    const summary = document.getElementById('modalStockSummary');
    if (summary) summary.textContent = 'Qtd. XML: ' + fmtBr(base.qty, 4) + ' × fator ' + fmtBr(factor, 4) + ' = estoque ' + fmtBr(converted, 4) + ' • custo unitário calculado: R$ ' + fmtBr(cost, 4);
  }
  function updateCategoryVisibilityStatus(foundSavedConfig){
    const cat = getModalValue('modalCategory').trim();
    const hideOrder = document.getElementById('modalHideOrderCatalog');
    const hideProduct = document.getElementById('modalHideProductCatalog');
    const status = document.getElementById('modalCategoryVisibilityStatus');
    const current = document.getElementById('modalCategoryVisibilityCurrent');
    if (!status) return;
    status.textContent = '';
    const statusText = document.createElement('div');
    statusText.className = 'status-text';
    if (!cat) {
      statusText.textContent = 'Informe uma categoria para visualizar ou alterar a visibilidade dela.';
      if (current) current.textContent = 'Sem categoria informada';
    } else if (foundSavedConfig) {
      statusText.textContent = 'Configuração atual carregada para: ' + cat + '. Você pode alterar antes de cadastrar o produto.';
      if (current) current.textContent = 'Categoria existente';
    } else {
      statusText.textContent = 'Categoria nova ou ainda sem produtos cadastrados. Defina abaixo como ela aparecerá nos catálogos.';
      if (current) current.textContent = 'Nova categoria';
    }
    status.appendChild(statusText);
    const chipOrder = document.createElement('span');
    chipOrder.className = 'status-chip' + (hideOrder && hideOrder.checked ? ' hidden-cat' : '');
    chipOrder.textContent = 'Catálogo de pedidos: ' + (hideOrder && hideOrder.checked ? 'oculto' : 'visível');
    const chipProduct = document.createElement('span');
    chipProduct.className = 'status-chip' + (hideProduct && hideProduct.checked ? ' hidden-cat' : '');
    chipProduct.textContent = 'Catálogo de produtos: ' + (hideProduct && hideProduct.checked ? 'oculto' : 'visível');
    status.appendChild(chipOrder);
    status.appendChild(chipProduct);
  }
  function syncCategoryVisibilityFromName(){
    const cat = getModalValue('modalCategory').trim();
    const cfg = categorySettings[cat] || categorySettingsByKey[categoryKey(cat)] || null;
    const hideOrder = document.getElementById('modalHideOrderCatalog');
    const hideProduct = document.getElementById('modalHideProductCatalog');
    if (cfg) {
      if (hideOrder) hideOrder.checked = String(cfg.hide_order_catalog || 0) === '1';
      if (hideProduct) hideProduct.checked = String(cfg.hide_product_catalog || 0) === '1';
      updateCategoryVisibilityStatus(true);
    } else {
      if (hideOrder) hideOrder.checked = false;
      if (hideProduct) hideProduct.checked = false;
      updateCategoryVisibilityStatus(false);
    }
  }
  const modalCategoryLookup = document.getElementById('modalCategoryLookup');
  const modalCategoryLookupButton = document.getElementById('modalCategoryLookupButton');
  const modalCategoryLookupSearch = document.getElementById('modalCategoryLookupSearch');
  const modalCategoryLookupResults = document.getElementById('modalCategoryLookupResults');
  const modalCategoryLookupCount = document.getElementById('modalCategoryLookupCount');
  const modalCategoryLookupClose = document.getElementById('modalCategoryLookupClose');
  const modalCategoryOptions = <?= json_encode(array_values($categoryOptions), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  let modalCategoryVisible = [];
  let modalCategoryActive = -1;

  function normalizeCategoryLookup(value){
    return String(value || '').normalize('NFD').replace(/[\u0300-\u036f]/g, '').toLocaleLowerCase('pt-BR').trim();
  }
  function renderCategoryLookup(query){
    if (!modalCategoryLookupResults) return;
    const q = normalizeCategoryLookup(query);
    modalCategoryVisible = q ? modalCategoryOptions.filter(function(item){ return normalizeCategoryLookup(item).includes(q); }) : modalCategoryOptions.slice();
    modalCategoryActive = modalCategoryVisible.length ? 0 : -1;
    modalCategoryLookupResults.innerHTML = '';
    if (!modalCategoryVisible.length) {
      const empty = document.createElement('div');
      empty.className = 'xml-category-lookup-empty';
      empty.textContent = q ? 'Nenhuma categoria encontrada. Você pode fechar e digitar uma nova categoria diretamente no campo.' : 'Nenhuma categoria cadastrada.';
      modalCategoryLookupResults.appendChild(empty);
    } else {
      modalCategoryVisible.forEach(function(item, index){
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'xml-category-lookup-result' + (index === modalCategoryActive ? ' is-active' : '');
        btn.setAttribute('role','option');
        btn.dataset.index = String(index);
        const strong = document.createElement('strong');
        strong.textContent = item;
        btn.appendChild(strong);
        const cfg = categorySettings[item] || categorySettingsByKey[categoryKey(item)] || {};
        const small = document.createElement('small');
        const hidden = [];
        if (String(cfg.hide_order_catalog || 0) === '1') hidden.push('Pedidos');
        if (String(cfg.hide_product_catalog || 0) === '1') hidden.push('Produtos');
        small.textContent = hidden.length ? ('Oculta em: ' + hidden.join(' e ')) : 'Visível nos catálogos';
        btn.appendChild(small);
        modalCategoryLookupResults.appendChild(btn);
      });
    }
    if (modalCategoryLookupCount) modalCategoryLookupCount.textContent = modalCategoryVisible.length + ' categoria(s) encontrada(s)';
  }
  function updateCategoryLookupActive(next){
    if (!modalCategoryVisible.length || !modalCategoryLookupResults) return;
    modalCategoryActive = Math.max(0, Math.min(modalCategoryVisible.length - 1, next));
    modalCategoryLookupResults.querySelectorAll('.xml-category-lookup-result').forEach(function(el, idx){
      el.classList.toggle('is-active', idx === modalCategoryActive);
      if (idx === modalCategoryActive) el.scrollIntoView({block:'nearest'});
    });
  }
  function closeCategoryLookup(){
    if (!modalCategoryLookup) return;
    modalCategoryLookup.classList.remove('open');
    modalCategoryLookup.setAttribute('aria-hidden','true');
    if (modalCategory) modalCategory.focus();
  }
  function selectCategoryLookup(index){
    const idx = typeof index === 'number' ? index : modalCategoryActive;
    if (idx < 0 || idx >= modalCategoryVisible.length) return;
    setModalValue('modalCategory', modalCategoryVisible[idx]);
    syncCategoryVisibilityFromName();
    closeCategoryLookup();
  }
  function openCategoryLookup(){
    if (!modalCategoryLookup || !modalCategoryLookupSearch || !modalCategoryLookupResults) return;
    modalCategoryLookupSearch.value = '';
    modalCategoryLookup.classList.add('open');
    modalCategoryLookup.setAttribute('aria-hidden','false');
    renderCategoryLookup('');
    requestAnimationFrame(function(){ modalCategoryLookupSearch.focus(); modalCategoryLookupSearch.select(); });
  }
  function updateTaxProfilePreview(){
    const preview = document.getElementById('modalProductTaxPreview');
    if (!preview) return;
    const tp = taxProfiles[getModalValue('modalTaxProfileId')] || null;
    if (!tp) {
      preview.textContent = 'Selecione uma tributação para conferir os CSTs de saída que serão gravados no produto.';
      return;
    }
    const icms = tp.icms_cst || tp.icms_csosn || '—';
    preview.textContent = 'Cadastro do produto: ICMS ' + icms + ' • IPI ' + (tp.ipi_cst || '—') + ' • PIS saída ' + (tp.pis_out_cst || '—') + ' • COFINS saída ' + (tp.cofins_out_cst || '—') + ' • CFOP venda ' + (tp.cfop_sale_state || '—') + '/' + (tp.cfop_sale_interstate || '—') + ' • CFOP entrada padrão do perfil ' + (tp.cfop_entry_state || '—') + '/' + (tp.cfop_entry_interstate || '—');
  }
  document.querySelectorAll('.open-xml-product-modal').forEach(function(btn){
    btn.addEventListener('click', function(){
      const itemId = btn.dataset.itemId || '';
      const factorInput = document.querySelector('[data-factor="' + itemId + '"]');
      const factor = factorInput ? factorInput.value : '1';
      setModalValue('modalItemId', itemId);
      setModalValue('modalName', btn.dataset.name || '');
      setModalValue('modalCategory', '');
      syncCategoryVisibilityFromName();
      setModalValue('modalBarcode', btn.dataset.ean || '');
      setModalValue('modalSupplierCode', btn.dataset.code || '');
      setModalValue('modalUnit', btn.dataset.unit || 'UN');
      setModalValue('modalFactor', factor || '1');
      setModalValue('modalSalePrice', '0,00');
      setModalValue('modalMarkup', '');
      modalPricingLastEdited = xmlPricingPolicy === 'keep_price' ? 'price' : 'markup';
      setModalValue('modalNcm', btn.dataset.ncm || '');
      setModalValue('modalCest', btn.dataset.cest || '');
      setModalValue('modalXmlOrigem', btn.dataset.xmlOrigem || '');
      setModalValue('modalXmlCfop', btn.dataset.xmlCfop || '');
      setModalValue('modalXmlIcmsCst', btn.dataset.xmlIcmsCst || '');
      setModalValue('modalXmlIcmsCsosn', btn.dataset.xmlIcmsCsosn || '');
      setModalValue('modalXmlIpiCst', btn.dataset.xmlIpiCst || '');
      setModalValue('modalXmlPisCst', btn.dataset.xmlPisCst || '');
      setModalValue('modalXmlCofinsCst', btn.dataset.xmlCofinsCst || '');
      setModalValue('modalProductOrigem', btn.dataset.productOrigem || btn.dataset.xmlOrigem || '');
      setModalValue('modalOrigem', btn.dataset.entryOrigem || btn.dataset.xmlOrigem || '');
      setModalValue('modalCfop', btn.dataset.cfop || btn.dataset.xmlCfop || '');
      setModalValue('modalIcmsCst', btn.dataset.icmsCst || btn.dataset.xmlIcmsCst || '');
      setModalValue('modalIcmsCsosn', btn.dataset.icmsCsosn || btn.dataset.xmlIcmsCsosn || '');
      setModalValue('modalIpiCst', btn.dataset.ipiCst || btn.dataset.xmlIpiCst || '');
      setModalValue('modalPisCst', btn.dataset.pisCst || btn.dataset.xmlPisCst || '');
      setModalValue('modalCofinsCst', btn.dataset.cofinsCst || btn.dataset.xmlCofinsCst || '');
      setModalValue('modalTaxProfileId', btn.dataset.taxProfileId || '');
      updateTaxProfilePreview();
      const badge = document.getElementById('modalTaxRuleBadge');
      if (badge) {
        const rule = btn.dataset.taxRuleName || '';
        badge.textContent = rule ? ('Regra aplicada: ' + rule) : 'Sem regra automática — ajuste manualmente';
        badge.classList.toggle('off', !rule);
      }
      const imageInput = document.getElementById('modalImage');
      const imagePreview = document.getElementById('modalImagePreview');
      if (imageInput) imageInput.value = '';
      if (imagePreview) imagePreview.src = '../assets/no-image.svg';
      let fiscalInfo = '';
      if (btn.dataset.taxRuleName) fiscalInfo = ' • Regra fiscal aplicada: ' + btn.dataset.taxRuleName;
      setModalValue('modalDescription', '');
      setModalValue('modalFiscalNotes', 'Cadastrado pelo RADAR XML. Código XML: ' + (btn.dataset.code || '') + (btn.dataset.ean ? ' • EAN: ' + btn.dataset.ean : '') + fiscalInfo);
      recalcModal();
      openModal();
      setTimeout(function(){ const name = document.getElementById('modalName'); if (name) name.focus(); }, 60);
    });
  });
  document.querySelectorAll('[data-close-product-modal]').forEach(function(btn){ btn.addEventListener('click', closeModal); });
  document.addEventListener('keydown', function(e){ if (e.key === 'Escape') { if (modalCategoryLookup && modalCategoryLookup.classList.contains('open')) { closeCategoryLookup(); return; } closeModal(); } });
  const modalImage = document.getElementById('modalImage');
  if (modalImage) modalImage.addEventListener('change', function(){
    const preview = document.getElementById('modalImagePreview');
    const file = modalImage.files && modalImage.files[0];
    if (preview && file) preview.src = URL.createObjectURL(file);
  });
  const modalFactor = document.getElementById('modalFactor');
  if (modalFactor) modalFactor.addEventListener('input', function(){ recalcModal(); });
  const modalMarkup = document.getElementById('modalMarkup');
  if (modalMarkup) modalMarkup.addEventListener('input', function(){ if(modalPricingInternal)return; modalPricingLastEdited='markup'; recalcModal('markup'); });
  const modalSalePrice = document.getElementById('modalSalePrice');
  if (modalSalePrice) modalSalePrice.addEventListener('input', function(){ if(modalPricingInternal)return; modalPricingLastEdited='price'; recalcModal('price'); });
  const modalCategory = document.getElementById('modalCategory');
  if (modalCategory) {
    modalCategory.addEventListener('input', syncCategoryVisibilityFromName);
    modalCategory.addEventListener('change', syncCategoryVisibilityFromName);
    modalCategory.addEventListener('blur', syncCategoryVisibilityFromName);
    modalCategory.addEventListener('keydown', function(e){
      if (e.key === 'F8') { e.preventDefault(); e.stopPropagation(); openCategoryLookup(); }
    });
  }
  if (modalCategoryLookupButton) {
    modalCategoryLookupButton.addEventListener('click', openCategoryLookup);
    modalCategoryLookupButton.addEventListener('keydown', function(e){ if (e.key === 'F8') { e.preventDefault(); e.stopPropagation(); openCategoryLookup(); } });
  }
  if (modalCategoryLookupClose) modalCategoryLookupClose.addEventListener('click', closeCategoryLookup);
  if (modalCategoryLookup) modalCategoryLookup.addEventListener('click', function(e){ if (e.target === modalCategoryLookup) closeCategoryLookup(); });
  if (modalCategoryLookupSearch) modalCategoryLookupSearch.addEventListener('keydown', function(e){
    if (e.key === 'Enter') {
      e.preventDefault();
      renderCategoryLookup(modalCategoryLookupSearch.value);
      if (modalCategoryVisible.length) { modalCategoryLookupResults.focus(); updateCategoryLookupActive(0); }
    } else if (e.key === 'ArrowDown') {
      e.preventDefault();
      renderCategoryLookup(modalCategoryLookupSearch.value);
      if (modalCategoryVisible.length) { modalCategoryLookupResults.focus(); updateCategoryLookupActive(0); }
    } else if (e.key === 'F8') {
      e.preventDefault(); renderCategoryLookup(modalCategoryLookupSearch.value);
    } else if (e.key === 'Escape') {
      e.preventDefault(); closeCategoryLookup();
    }
  });
  if (modalCategoryLookupResults) {
    modalCategoryLookupResults.addEventListener('click', function(e){
      const btn = e.target.closest('.xml-category-lookup-result');
      if (!btn) return;
      selectCategoryLookup(Number(btn.dataset.index || 0));
    });
    modalCategoryLookupResults.addEventListener('keydown', function(e){
      if (e.key === 'ArrowDown') { e.preventDefault(); updateCategoryLookupActive(modalCategoryActive + 1); return; }
      if (e.key === 'ArrowUp') { e.preventDefault(); updateCategoryLookupActive(modalCategoryActive - 1); return; }
      if (e.key === 'Enter') { e.preventDefault(); selectCategoryLookup(); return; }
      if (e.key === 'Escape') { e.preventDefault(); closeCategoryLookup(); return; }
      if (e.key.length === 1 && !e.ctrlKey && !e.metaKey && !e.altKey) {
        e.preventDefault();
        modalCategoryLookupSearch.value = e.key;
        modalCategoryLookupSearch.focus();
      }
    });
  }
  ['modalHideOrderCatalog','modalHideProductCatalog'].forEach(function(id){
    const checkbox = document.getElementById(id);
    if (checkbox) checkbox.addEventListener('change', function(){
      const cat = getModalValue('modalCategory').trim();
      updateCategoryVisibilityStatus(!!(categorySettings[cat] || categorySettingsByKey[categoryKey(cat)]));
    });
  });
  const modalTaxProfile = document.getElementById('modalTaxProfileId');
  if (modalTaxProfile) modalTaxProfile.addEventListener('change', updateTaxProfilePreview);
  if (modalForm) modalForm.addEventListener('submit', function(e){
    if (!getModalValue('modalName').trim()) { e.preventDefault(); alert('Informe a descrição do produto.'); return; }
    if (!confirm('Cadastrar este produto e vincular automaticamente ao item do XML?')) e.preventDefault();
  });
})();
</script>
<script>
(function(){
  function maskDate(el){
    el.addEventListener('input', function(){
      let v = el.value.replace(/\D/g, '').slice(0, 8);
      if (v.length > 4) v = v.slice(0,2) + '-' + v.slice(2,4) + '-' + v.slice(4);
      else if (v.length > 2) v = v.slice(0,2) + '-' + v.slice(2);
      el.value = v;
    });
  }
  document.querySelectorAll('input[name="issue_date_br"], input[name="entry_date_br"]').forEach(maskDate);
})();
</script>


<style>
.pricing-review-card{border:1px solid #dbe7f4;background:#fff}
.pricing-review-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
.pricing-review-head h2{margin:0 0 4px}
.pricing-policy-badge{display:inline-flex;align-items:center;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900}
.pricing-toolbar{display:flex;justify-content:flex-end;align-items:center;gap:8px;margin:0 0 8px;position:relative}
.pricing-columns{position:relative}
.pricing-columns>button{white-space:nowrap}
.pricing-columns-menu{display:none;position:absolute;right:0;top:calc(100% + 5px);z-index:35;min-width:210px;padding:8px;background:#fff;border:1px solid #dbe4f0;border-radius:12px;box-shadow:0 12px 30px rgba(15,23,42,.15)}
.pricing-columns.open .pricing-columns-menu{display:grid;gap:5px}
.pricing-columns-menu label{display:flex;gap:7px;align-items:center;font-size:12px;font-weight:750;color:#334155}
.pricing-grid-shell{width:100%;max-width:100%;overflow:visible}
.pricing-grid{width:100%;table-layout:fixed;border-collapse:separate;border-spacing:0;font-size:11px}
.pricing-grid th,.pricing-grid td{border-right:1px solid #e5edf5;border-bottom:1px solid #e5edf5;padding:5px 5px;vertical-align:middle;box-sizing:border-box}
.pricing-grid th:first-child,.pricing-grid td:first-child{border-left:1px solid #e5edf5}
.pricing-grid thead th{position:relative;background:#f4f8fc;color:#334155;font-weight:900;border-top:1px solid #dbe4f0;user-select:none}
.pricing-grid thead th:first-child{border-top-left-radius:10px}.pricing-grid thead th:last-child{border-top-right-radius:10px}
.pricing-th-inner{display:flex;align-items:center;gap:3px;min-width:0}
.pricing-drag{cursor:grab;color:#94a3b8;font-size:10px;flex:0 0 auto}
.pricing-sort-label{cursor:pointer;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;flex:1}
.pricing-filter-button{border:0;background:transparent;padding:0 2px;cursor:pointer;color:#64748b;font-size:11px;line-height:1}
.pricing-filter-button.active{color:#1d4ed8}
.pricing-filter-popover{display:none;position:absolute;left:4px;right:4px;top:calc(100% + 3px);z-index:25;padding:5px;background:#fff;border:1px solid #cbd5e1;border-radius:8px;box-shadow:0 8px 20px rgba(15,23,42,.15)}
.pricing-filter-popover.open{display:block}
.pricing-filter-popover input{width:100%;box-sizing:border-box;border:1px solid #cbd5e1;border-radius:6px;padding:5px;font-size:11px}
.pricing-resizer{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize;z-index:5}
.pricing-grid td[data-col="produto"]{font-weight:750;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pricing-grid td[data-col="codigo"],.pricing-grid td[data-col="quantidade"],.pricing-grid td[data-col^="custo"],.pricing-grid td[data-col^="margem"],.pricing-grid td[data-col^="preco"]{white-space:nowrap}
.pricing-number{text-align:right}
.pricing-edit{width:100%;min-width:0;height:30px;box-sizing:border-box;border:1px solid #93c5fd;border-radius:7px;padding:4px 6px;text-align:right;background:#fff;font:inherit;font-weight:850;color:#0f172a}
.pricing-edit:focus{outline:2px solid #bfdbfe;border-color:#2563eb}
.pricing-new-cell{background:#fbfdff}
.pricing-hidden{display:none!important}
.pricing-review-actions{display:flex;justify-content:flex-end;gap:8px;align-items:center;margin-top:12px;flex-wrap:wrap}
.pricing-review-note{margin-right:auto;font-size:11px;color:#64748b;max-width:720px}
.pricing-enable{display:flex;align-items:center;gap:8px;border:1px solid #dbe4f0;background:#f8fafc;border-radius:10px;padding:9px 11px;font-weight:850;color:#334155}
.pricing-enable input{width:17px;height:17px;margin:0}
.pricing-no-results{display:none;padding:14px;text-align:center;color:#64748b;border:1px solid #e5edf5;border-top:0}
@media(max-width:1100px){.pricing-grid{font-size:10px}.pricing-grid th,.pricing-grid td{padding:4px 3px}.pricing-edit{padding:3px 4px}}
</style>

<div class="fiscal-card <?= $pricingReviewActive ? 'pricing-review-card' : '' ?>" id="gerarEntrada">
  <?php if (($xml['status'] ?? '') === 'ENTRADA_GERADA'): ?>
    <div class="fiscal-list-head"><h2>Gerar entrada</h2><span class="fiscal-badge"><?= fiscal_h($xml['status'] ?? '') ?></span></div>
    <p class="fiscal-muted">Esta nota já teve entrada gerada em <?= fiscal_h($xml['generated_at'] ?? '') ?> usando a operação <?= fiscal_h($xml['operation_name'] ?? '') ?>.</p>
  <?php elseif ($pricingReviewActive): ?>
    <div class="pricing-review-head">
      <div>
        <h2>Tela de preços — revisar antes de gerar a entrada</h2>
        <div class="fiscal-muted">O novo custo já considera os encargos da NF-e. Você pode ajustar custo, margem ou preço antes de confirmar.</div>
      </div>
      <span class="pricing-policy-badge">Configuração: <?= $xmlPricingPolicy === 'keep_price' ? 'manter preço de venda' : 'manter margem' ?></span>
    </div>
    <form method="post" id="pricingReviewForm">
      <input type="hidden" name="action" value="generate_entry">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="pricing_confirmed" value="1">
      <input type="hidden" name="enable_pricing_screen" value="1">
      <input type="hidden" name="operation_id" value="<?= (int)($pricingPending['operation_id'] ?? 0) ?>">
      <input type="hidden" name="chart_account_id" value="<?= (int)($pricingPending['chart_account_id'] ?? 0) ?>">
      <input type="hidden" name="cost_center_id" value="<?= (int)($pricingPending['cost_center_id'] ?? 0) ?>">
      <input type="hidden" name="fallback_due_date" value="<?= fiscal_h((string)($pricingPending['fallback_due_date'] ?? '')) ?>">

      <div class="pricing-toolbar">
        <div class="fiscal-muted" style="margin-right:auto">Clique no título para ordenar; use o filtro de cada coluna. Arraste o cabeçalho para reposicionar.</div>
        <div class="pricing-columns" id="pricingColumns">
          <button type="button" class="btn" id="pricingColumnsButton">Colunas</button>
          <div class="pricing-columns-menu" id="pricingColumnsMenu">
            <?php
              $pricingColumnLabels = [
                'codigo'=>'Código','produto'=>'Produto/Serviço','quantidade'=>'Qtd. anterior','custo_anterior'=>'Custo anterior',
                'margem_anterior'=>'Margem anterior','preco_anterior'=>'Preço anterior','novo_custo'=>'Novo custo',
                'nova_margem'=>'Nova margem','novo_preco'=>'Novo preço'
              ];
              foreach ($pricingColumnLabels as $pricingColKey=>$pricingColLabel):
            ?>
              <label><input type="checkbox" data-pricing-column-toggle="<?= fiscal_h($pricingColKey) ?>" checked> <?= fiscal_h($pricingColLabel) ?></label>
            <?php endforeach; ?>
          </div>
        </div>
        <button type="button" class="btn" id="pricingResetGrid">Restaurar grade</button>
      </div>

      <div class="pricing-grid-shell">
        <table class="pricing-grid" id="pricingGrid">
          <colgroup>
            <col data-col="codigo" style="width:7%">
            <col data-col="produto" style="width:23%">
            <col data-col="quantidade" style="width:8%">
            <col data-col="custo_anterior" style="width:10%">
            <col data-col="margem_anterior" style="width:9%">
            <col data-col="preco_anterior" style="width:11%">
            <col data-col="novo_custo" style="width:10%">
            <col data-col="nova_margem" style="width:9%">
            <col data-col="novo_preco" style="width:13%">
          </colgroup>
          <thead><tr>
          <?php foreach ($pricingColumnLabels as $pricingColKey=>$pricingColLabel): ?>
            <th data-col="<?= fiscal_h($pricingColKey) ?>" draggable="true">
              <div class="pricing-th-inner"><span class="pricing-drag" title="Arrastar coluna">⋮⋮</span><span class="pricing-sort-label"><?= fiscal_h($pricingColLabel) ?></span><button type="button" class="pricing-filter-button" title="Filtrar coluna">⌕</button></div>
              <div class="pricing-filter-popover"><input autocomplete="off" data-pricing-filter="<?= fiscal_h($pricingColKey) ?>" placeholder="Filtrar..."></div>
              <span class="pricing-resizer" title="Ajustar largura"></span>
            </th>
          <?php endforeach; ?>
          </tr></thead>
          <tbody>
          <?php foreach ($pricingReview as $row):
            $rowMode = ($xmlPricingPolicy === 'keep_price' || (float)($row['old_cost'] ?? 0) <= 0) ? 'price' : 'margin';
          ?>
            <tr data-pricing-row data-product-id="<?= (int)$row['product_id'] ?>" data-mode="<?= fiscal_h($rowMode) ?>">
              <td data-col="codigo" data-value="<?= fiscal_h((string)$row['code']) ?>"><?= fiscal_h((string)$row['code']) ?></td>
              <td data-col="produto" data-value="<?= fiscal_h((string)$row['name']) ?>" title="<?= fiscal_h((string)$row['name']) ?>"><?= fiscal_h((string)$row['name']) ?></td>
              <td data-col="quantidade" class="pricing-number" data-value="<?= fiscal_h((string)$row['previous_quantity']) ?>"><?= number_format((float)$row['previous_quantity'], 4, ',', '.') ?></td>
              <td data-col="custo_anterior" class="pricing-number" data-value="<?= fiscal_h((string)$row['old_cost']) ?>">R$ <?= number_format((float)$row['old_cost'], 4, ',', '.') ?></td>
              <td data-col="margem_anterior" class="pricing-number" data-value="<?= fiscal_h((string)$row['old_margin']) ?>"><?= number_format((float)$row['old_margin'], 2, ',', '.') ?>%</td>
              <td data-col="preco_anterior" class="pricing-number" data-value="<?= fiscal_h((string)$row['old_price']) ?>">R$ <?= number_format((float)$row['old_price'], 2, ',', '.') ?></td>
              <td data-col="novo_custo" class="pricing-new-cell"><input class="pricing-edit" data-pricing-cost name="pricing_cost[<?= (int)$row['product_id'] ?>]" inputmode="decimal" value="<?= number_format((float)$row['new_cost'], 4, ',', '') ?>"></td>
              <td data-col="nova_margem" class="pricing-new-cell"><input class="pricing-edit" data-pricing-margin name="pricing_margin[<?= (int)$row['product_id'] ?>]" inputmode="decimal" value="<?= number_format((float)$row['new_margin'], 2, ',', '') ?>"></td>
              <td data-col="novo_preco" class="pricing-new-cell"><input class="pricing-edit" data-pricing-price name="pricing_price[<?= (int)$row['product_id'] ?>]" inputmode="decimal" value="<?= number_format((float)$row['new_price'], 2, ',', '') ?>"></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
        <div class="pricing-no-results" id="pricingNoResults">Nenhum produto corresponde aos filtros da grade.</div>
      </div>
      <div class="pricing-review-actions">
        <div class="pricing-review-note">Ao confirmar, o <b>último custo</b> e o <b>custo da última compra</b> usarão o custo completo desta entrada. O custo médio continuará sendo recalculado pelo saldo anterior + entrada.</div>
        <a class="btn" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$id . '#gerarEntrada')) ?>">Voltar sem gerar</a>
        <button type="submit" class="btn btn-primary">Confirmar preços e gerar entrada</button>
      </div>
    </form>
  <?php else: ?>
    <div class="fiscal-list-head"><h2>Gerar entrada</h2><span class="fiscal-badge off"><?= fiscal_h($xml['status'] ?? '') ?></span></div>
    <form method="post" class="fiscal-form" id="generateEntryForm"><input type="hidden" name="action" value="generate_entry"><input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="fiscal-form-grid">
        <div><label>Operação de entrada *</label><select name="operation_id" required><?php fiscal_xml_select_options($entryOps, $selectedOp, 'Selecione a operação'); ?></select></div>
        <?php if ($entryAllowFinance): ?>
        <div><label>Plano de contas *</label><select name="chart_account_id"><?php fiscal_xml_select_options($charts, $selectedChart, 'Usar padrão/selecionar'); ?></select></div>
        <div><label>Centro de custo</label><select name="cost_center_id"><?php fiscal_xml_select_options($costs, $selectedCost, 'Opcional'); ?></select></div>
        <div><label>Vencimento fallback</label><input type="date" name="fallback_due_date" value="<?= fiscal_h($installments[0]['due_date'] ?? ($xml['entry_date'] ?? ($xml['issue_date'] ?? date('Y-m-d')))) ?>"><div class="fiscal-muted">Usado apenas se o XML não trouxer vencimento.</div></div>
        <div class="span-2"><label>Resumo</label><input readonly value="Será gerado contas a pagar para <?= count($installments) ?> parcela(s), total R$ <?= number_format((float)($xml['total_invoice'] ?? 0), 2, ',', '.') ?>"></div>
        <?php else: ?>
        <div class="span-2"><label>V10 Multiempresa</label><input readonly value="Será movimentado somente o estoque de <?= fiscal_h($entryCompanyName) ?>. O Contas a Pagar não será criado pelo F11 nesta etapa."></div>
        <?php endif; ?>
        <div class="span-2">
          <label class="pricing-enable"><input type="checkbox" id="enablePricingScreen" name="enable_pricing_screen" value="1" checked> Habilita tela de preços <span class="fiscal-muted">(padrão: <?= $xmlPricingPolicy === 'keep_price' ? 'manter preço' : 'manter margem' ?>)</span></label>
          <div class="fiscal-muted" style="margin-top:5px">Marcado: antes de gerar a entrada, o Nexa abre a revisão dos produtos. Desmarcado: atualiza o custo completo da nota e mantém o preço de venda atual.</div>
        </div>
      </div>
      <div class="fiscal-form-actions"><button class="btn btn-primary" id="generateEntryButton"><?= $entryAllowFinance ? 'Continuar / gerar entrada' : 'Continuar / gerar entrada de mercadoria' ?></button></div>
    </form>
  <?php endif; ?>
</div>

<script>
(function(){
  const generateForm=document.getElementById('generateEntryForm');
  if(generateForm){
    generateForm.addEventListener('submit',function(e){
      const enabled=document.getElementById('enablePricingScreen');
      if(enabled&&enabled.checked)return;
      const message=<?= json_encode($entryAllowFinance ? 'Gerar a entrada agora, atualizar o custo completo da nota e manter o preço de venda atual?' : 'Gerar a entrada de mercadoria agora, atualizar o custo completo da nota e manter o preço de venda atual?', JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
      if(!window.confirm(message))e.preventDefault();
    });
  }

  const grid=document.getElementById('pricingGrid');
  if(!grid)return;
  const storageKey='nexa.fiscalEntryPricingGrid.v1.<?= (int)$cid ?>';
  const defaultOrder=['codigo','produto','quantidade','custo_anterior','margem_anterior','preco_anterior','novo_custo','nova_margem','novo_preco'];
  const defaultWidths={codigo:7,produto:23,quantidade:8,custo_anterior:10,margem_anterior:9,preco_anterior:11,novo_custo:10,nova_margem:9,novo_preco:13};
  let state={order:defaultOrder.slice(),hidden:[],widths:Object.assign({},defaultWidths)};
  try{const saved=JSON.parse(localStorage.getItem(storageKey)||'null');if(saved&&typeof saved==='object'){if(Array.isArray(saved.order))state.order=saved.order.filter(k=>defaultOrder.includes(k)).concat(defaultOrder.filter(k=>!saved.order.includes(k)));if(Array.isArray(saved.hidden))state.hidden=saved.hidden.filter(k=>defaultOrder.includes(k));if(saved.widths)state.widths=Object.assign({},defaultWidths,saved.widths);}}catch(e){}
  const save=()=>{try{localStorage.setItem(storageKey,JSON.stringify(state));}catch(e){}};
  const rows=()=>Array.from(grid.querySelectorAll('tbody tr[data-pricing-row]'));
  function applyOrder(){
    const head=grid.querySelector('thead tr'),cg=grid.querySelector('colgroup');
    state.order.forEach(k=>{
      const th=head.querySelector('th[data-col="'+k+'"]');if(th)head.appendChild(th);
      const col=cg.querySelector('col[data-col="'+k+'"]');if(col)cg.appendChild(col);
      rows().forEach(r=>{const td=r.querySelector('td[data-col="'+k+'"]');if(td)r.appendChild(td);});
    });
  }
  function normalizeWidths(){
    const visible=defaultOrder.filter(k=>!state.hidden.includes(k));
    let total=visible.reduce((s,k)=>s+Math.max(3,Number(state.widths[k]||defaultWidths[k])),0);
    if(total<=0)return;
    visible.forEach(k=>{const col=grid.querySelector('col[data-col="'+k+'"]');if(col)col.style.width=(Math.max(3,Number(state.widths[k]||defaultWidths[k]))/total*100).toFixed(3)+'%';});
  }
  function applyHidden(){
    defaultOrder.forEach(k=>{
      const hidden=state.hidden.includes(k);
      grid.querySelectorAll('[data-col="'+k+'"]').forEach(el=>el.classList.toggle('pricing-hidden',hidden));
      const cb=document.querySelector('[data-pricing-column-toggle="'+k+'"]');if(cb)cb.checked=!hidden;
    });
    normalizeWidths();
  }
  applyOrder();applyHidden();

  document.querySelectorAll('[data-pricing-column-toggle]').forEach(cb=>cb.addEventListener('change',function(){
    const k=this.dataset.pricingColumnToggle;
    if(this.checked)state.hidden=state.hidden.filter(x=>x!==k);else if(!state.hidden.includes(k)&&defaultOrder.length-state.hidden.length>1)state.hidden.push(k);
    applyHidden();save();
  }));
  const columnsBox=document.getElementById('pricingColumns'),columnsButton=document.getElementById('pricingColumnsButton');
  if(columnsButton)columnsButton.addEventListener('click',e=>{e.stopPropagation();columnsBox.classList.toggle('open');});
  document.addEventListener('click',e=>{if(columnsBox&&!columnsBox.contains(e.target))columnsBox.classList.remove('open');});
  const reset=document.getElementById('pricingResetGrid');
  if(reset)reset.addEventListener('click',()=>{
    state={order:defaultOrder.slice(),hidden:[],widths:Object.assign({},defaultWidths)};
    grid.querySelectorAll('[data-pricing-filter]').forEach(inp=>{inp.value='';const b=inp.closest('th').querySelector('.pricing-filter-button');if(b)b.classList.remove('active');});
    applyOrder();applyHidden();applyFilters();save();
  });

  function parseBr(v){v=String(v||'').replace(/R\$|%|\s/g,'');if(v.includes(',')&&v.includes('.'))v=v.replace(/\./g,'');v=v.replace(',','.');const n=parseFloat(v);return Number.isFinite(n)?n:0;}
  function fmt(n,d){return Number(n||0).toLocaleString('pt-BR',{minimumFractionDigits:d,maximumFractionDigits:d,useGrouping:false});}
  rows().forEach(row=>{
    const cost=row.querySelector('[data-pricing-cost]'),margin=row.querySelector('[data-pricing-margin]'),price=row.querySelector('[data-pricing-price]');
    [cost,margin,price].forEach(el=>{if(el)el.addEventListener('focus',()=>setTimeout(()=>{try{el.select();}catch(e){}},0));});
    const byMargin=()=>{const c=parseBr(cost.value),m=parseBr(margin.value);price.value=fmt(Math.max(0,c*(1+m/100)),2);};
    const byPrice=()=>{const c=parseBr(cost.value),pr=parseBr(price.value);margin.value=c>0?fmt(((pr/c)-1)*100,2):fmt(0,2);};
    if(margin)margin.addEventListener('input',()=>{row.dataset.mode='margin';byMargin();});
    if(price)price.addEventListener('input',()=>{row.dataset.mode='price';byPrice();});
    if(cost)cost.addEventListener('input',()=>{if(row.dataset.mode==='price')byPrice();else byMargin();});
  });

  let sortState={key:'',dir:1};
  function cellComparable(row,key){
    const cell=row.querySelector('td[data-col="'+key+'"]');if(!cell)return '';
    const input=cell.querySelector('input');const raw=input?input.value:(cell.dataset.value||cell.textContent||'');
    if(['quantidade','custo_anterior','margem_anterior','preco_anterior','novo_custo','nova_margem','novo_preco'].includes(key))return parseBr(raw);
    return String(raw).trim().toLocaleLowerCase('pt-BR');
  }
  grid.querySelectorAll('.pricing-sort-label').forEach(label=>label.addEventListener('click',()=>{
    const key=label.closest('th').dataset.col;sortState.dir=sortState.key===key?-sortState.dir:1;sortState.key=key;
    const body=grid.tBodies[0];rows().sort((a,b)=>{const av=cellComparable(a,key),bv=cellComparable(b,key);return (typeof av==='number'&&typeof bv==='number'?(av-bv):String(av).localeCompare(String(bv),'pt-BR',{numeric:true}))*sortState.dir;}).forEach(r=>body.appendChild(r));
  }));

  function applyFilters(){
    const filters={};grid.querySelectorAll('[data-pricing-filter]').forEach(inp=>{const v=String(inp.value||'').trim().toLocaleLowerCase('pt-BR');if(v)filters[inp.dataset.pricingFilter]=v;});
    let visible=0;rows().forEach(row=>{let ok=true;Object.keys(filters).forEach(k=>{if(!ok)return;const val=String(cellComparable(row,k)).toLocaleLowerCase('pt-BR');if(!val.includes(filters[k]))ok=false;});row.style.display=ok?'':'none';if(ok)visible++;});
    const no=document.getElementById('pricingNoResults');if(no)no.style.display=visible?'none':'block';
  }
  grid.querySelectorAll('.pricing-filter-button').forEach(btn=>btn.addEventListener('click',e=>{
    e.stopPropagation();const th=btn.closest('th'),pop=th.querySelector('.pricing-filter-popover');grid.querySelectorAll('.pricing-filter-popover.open').forEach(p=>{if(p!==pop)p.classList.remove('open');});pop.classList.toggle('open');if(pop.classList.contains('open'))setTimeout(()=>pop.querySelector('input').focus(),0);
  }));
  grid.querySelectorAll('[data-pricing-filter]').forEach(inp=>inp.addEventListener('input',()=>{inp.closest('th').querySelector('.pricing-filter-button').classList.toggle('active',String(inp.value||'').trim()!=='');applyFilters();}));
  document.addEventListener('click',e=>{if(!e.target.closest('.pricing-filter-popover')&&!e.target.closest('.pricing-filter-button'))grid.querySelectorAll('.pricing-filter-popover.open').forEach(p=>p.classList.remove('open'));});

  let dragged=null;
  grid.querySelectorAll('thead th[data-col]').forEach(th=>{
    th.addEventListener('dragstart',e=>{if(e.target.closest('.pricing-filter-button')||e.target.closest('.pricing-filter-popover')){e.preventDefault();return;}dragged=th.dataset.col;e.dataTransfer.effectAllowed='move';});
    th.addEventListener('dragover',e=>{if(dragged&&dragged!==th.dataset.col)e.preventDefault();});
    th.addEventListener('drop',e=>{e.preventDefault();const target=th.dataset.col;if(!dragged||dragged===target)return;const order=state.order.filter(k=>k!==dragged),idx=order.indexOf(target);order.splice(idx,0,dragged);state.order=order;applyOrder();applyHidden();save();dragged=null;});
    th.addEventListener('dragend',()=>{dragged=null;});
  });

  grid.querySelectorAll('.pricing-resizer').forEach(handle=>handle.addEventListener('pointerdown',e=>{
    e.preventDefault();e.stopPropagation();const th=handle.closest('th'),key=th.dataset.col,visible=state.order.filter(k=>!state.hidden.includes(k)),idx=visible.indexOf(key),nextKey=visible[idx+1];if(!nextKey)return;
    const startX=e.clientX,startA=th.getBoundingClientRect().width,nextTh=grid.querySelector('th[data-col="'+nextKey+'"]'),startB=nextTh.getBoundingClientRect().width,total=grid.getBoundingClientRect().width;
    const move=ev=>{let delta=ev.clientX-startX;let a=Math.max(45,startA+delta),b=Math.max(45,startB-delta);if(a+b!==startA+startB){const sum=startA+startB;if(a===45)b=sum-a;if(b===45)a=sum-b;}state.widths[key]=a/total*100;state.widths[nextKey]=b/total*100;normalizeWidths();};
    const up=()=>{document.removeEventListener('pointermove',move);document.removeEventListener('pointerup',up);save();};
    document.addEventListener('pointermove',move);document.addEventListener('pointerup',up);
  }));

  const reviewForm=document.getElementById('pricingReviewForm');
  if(reviewForm)reviewForm.addEventListener('submit',e=>{if(!window.confirm('Confirmar os custos e preços revisados e gerar definitivamente esta entrada?'))e.preventDefault();});
})();
</script>

<?php if ($xmlGenerated): ?>
<div class="fiscal-card entry-edit-card" id="editarEntrada">
  <div class="entry-edit-head">
    <div>
      <h2 style="margin:0">Editar nota de entrada</h2>
      <div class="fiscal-muted">Altere os dados internos usados pelo Nexa. O XML original permanece preservado para auditoria.</div>
    </div>
    <div class="entry-edit-actions">
      <?php if ($xmlGenerated): ?>
        <a class="btn" href="#desfazerEntrada">Desfazer Nota</a>
      <?php else: ?>
        <form method="post" onsubmit="return confirm('Excluir definitivamente esta nota e os itens da conferência?');"><input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="<?= (int)$id ?>"><button type="submit" class="btn entry-delete">Excluir nota</button></form>
      <?php endif; ?>
    </div>
  </div>
  <?php if ($xmlGenerated): ?>
    <div class="entry-edit-note"><?= $entryCrossContext ? 'A entrada já movimentou o estoque desta empresa. O financeiro não foi gerado pelo F11 na V10. Para trocar produto, quantidade, valor, fator de conversão ou custo de estoque, use <b>Desfazer Nota</b> primeiro.' : 'A entrada já movimentou estoque/financeiro. Você pode corrigir a tributação normalmente. Para trocar produto, quantidade, valor, fator de conversão ou custo de estoque, use <b>Desfazer Nota</b> primeiro.' ?></div>
  <?php else: ?>
    <div class="entry-edit-note entry-edit-ok">A nota está em conferência. Produto, quantidade, valores, conversão, custo e tributação podem ser alterados antes de gerar a entrada.</div>
  <?php endif; ?>

  <form method="post" class="fiscal-form" style="margin-bottom:14px">
    <input type="hidden" name="action" value="save_entry_header"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="fiscal-form-grid">
      <div class="span-2"><label>Fornecedor / razão social</label><input name="edit_supplier_name" value="<?= fiscal_h($xml['supplier_name'] ?? '') ?>" required></div>
      <div><label>CNPJ/CPF</label><input name="edit_supplier_doc" value="<?= fiscal_h($xml['supplier_doc'] ?? '') ?>"></div>
      <div><label>Inscrição Estadual</label><input name="edit_supplier_ie" value="<?= fiscal_h($xml['supplier_ie'] ?? '') ?>"></div>
      <div><label>UF</label><input name="edit_supplier_uf" maxlength="2" value="<?= fiscal_h($xml['supplier_uf'] ?? '') ?>"></div>
      <div><label>Emissão</label><input type="date" name="edit_issue_date" value="<?= fiscal_h($xml['issue_date'] ?? '') ?>"></div>
      <div><label>Entrada</label><input type="date" name="edit_entry_date" value="<?= fiscal_h($xml['entry_date'] ?? ($xml['issue_date'] ?? '')) ?>"></div>
      <div><label>Frete</label><input type="number" step="0.01" name="edit_freight_value" value="<?= fiscal_h(number_format((float)($xml['freight_value'] ?? 0),2,'.','')) ?>"></div>
      <div><label>Desconto</label><input type="number" step="0.01" name="edit_discount_value" value="<?= fiscal_h(number_format((float)($xml['discount_value'] ?? 0),2,'.','')) ?>"></div>
      <div><label>Outras despesas</label><input type="number" step="0.01" name="edit_other_value" value="<?= fiscal_h(number_format((float)($xml['other_value'] ?? 0),2,'.','')) ?>"></div>
      <div><label>Total da nota</label><input type="number" step="0.01" name="edit_total_invoice" value="<?= fiscal_h(number_format((float)($xml['total_invoice'] ?? 0),2,'.','')) ?>"></div>
      <div class="span-3"><label>Observações internas</label><input name="edit_notes" value="<?= fiscal_h($xml['notes'] ?? '') ?>"></div>
    </div>
    <div class="fiscal-form-actions"><button class="btn btn-primary" type="submit">Salvar dados gerais</button></div>
  </form>

  <form method="post" class="fiscal-form">
    <input type="hidden" name="action" value="save_entry_items"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="entry-edit-items">
      <?php foreach ($items as $it): ?>
        <?php $iid=(int)$it['id']; $tj=fiscal_entry_tax_json($it); $locked=$xmlGenerated; $entryPayloadEdit=fiscal_xml_item_tax_payload($pdo,$cid,$it); ?>
        <input type="hidden" name="edit_item_id[]" value="<?= $iid ?>">
        <details class="entry-edit-item">
          <summary>
            <div class="wide"><strong>#<?= (int)($it['item_number'] ?? 0) ?> — <?= fiscal_h($it['name'] ?? '') ?></strong><span style="display:block">Produto sistema: <?= fiscal_h($it['product_name'] ?? 'Não vinculado') ?></span></div>
            <div><span>Qtd.</span><strong style="display:block"><?= number_format((float)($it['quantity'] ?? 0),4,',','.') ?></strong></div>
            <div><span>Valor</span><strong style="display:block">R$ <?= number_format((float)($it['total_value'] ?? 0),2,',','.') ?></strong></div>
            <div><span>CFOP entrada</span><strong style="display:block"><?= fiscal_h($entryPayloadEdit['entry_cfop'] ?: ($it['cfop'] ?? '—')) ?></strong></div>
            <div><span>NCM</span><strong style="display:block"><?= fiscal_h($it['ncm'] ?? '—') ?></strong></div>
          </summary>
          <div class="entry-edit-body">
            <div class="entry-edit-grid">
              <label class="span-2">Produto do sistema
                <select name="edit_product_id[<?= $iid ?>]" <?= $locked?'disabled':'' ?> class="<?= $locked?'entry-readonly':'' ?>"><option value="">Não vinculado</option><?php foreach($productOptions as $po): ?><option value="<?= (int)$po['id'] ?>" <?= (int)($it['product_id']??0)===(int)$po['id']?'selected':'' ?>>#<?= (int)$po['id'] ?> - <?= fiscal_h($po['nome'] ?? '') ?></option><?php endforeach; ?></select>
              </label>
              <label>Quantidade<input type="number" step="0.0001" name="edit_quantity[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['quantity']??0),4,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <label>Valor unitário<input type="number" step="0.0001" name="edit_unit_value[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['unit_value']??0),4,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <label>Total item<input type="number" step="0.01" name="edit_total_value[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['total_value']??0),2,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <label>Fator conversão<input type="number" step="0.000001" name="edit_conversion_factor[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['conversion_factor']??1),6,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <label>Qtd. estoque<input type="number" step="0.0001" name="edit_stock_quantity[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['stock_quantity']??0),4,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <label>Custo estoque<input type="number" step="0.0001" name="edit_stock_unit_cost[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($it['stock_unit_cost']??0),4,'.','')) ?>" <?= $locked?'readonly':'' ?> class="<?= $locked?'entry-readonly':'' ?>"></label>
              <div class="entry-tax-title">Tributação de entrada — editável mesmo com a nota já gerada</div>
              <label>NCM<input name="edit_ncm[<?= $iid ?>]" value="<?= fiscal_h($it['ncm'] ?? '') ?>"></label>
              <label>CEST<input name="edit_cest[<?= $iid ?>]" value="<?= fiscal_h($it['cest'] ?? '') ?>"></label>
              <label>CFOP<input name="edit_entry_cfop[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_cfop'] ?: ($it['cfop'] ?? '')) ?>"></label>
              <label>Origem<input name="edit_entry_origem[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_origem'] ?: ($it['origem'] ?? '')) ?>"></label>
              <label>ICMS CST<input name="edit_entry_icms_cst[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_icms_cst'] ?? '') ?>"></label>
              <label>ICMS CSOSN<input name="edit_entry_icms_csosn[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_icms_csosn'] ?? '') ?>"></label>
              <label>Base ICMS<input type="number" step="0.01" name="edit_icms_base[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_base']??0),2,'.','')) ?>"></label>
              <label>Alíq. ICMS %<input type="number" step="0.0001" name="edit_icms_rate[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_rate']??0),4,'.','')) ?>"></label>
              <label>Valor ICMS<input type="number" step="0.01" name="edit_icms_amount[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_amount']??0),2,'.','')) ?>"></label>
              <label>Base ICMS ST<input type="number" step="0.01" name="edit_icms_st_base[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_st_base']??0),2,'.','')) ?>"></label>
              <label>Alíq. ICMS ST %<input type="number" step="0.0001" name="edit_icms_st_rate[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_st_rate']??0),4,'.','')) ?>"></label>
              <label>Valor ICMS ST<input type="number" step="0.01" name="edit_icms_st_amount[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['icms_st_amount']??$it['icms_st_value']??0),2,'.','')) ?>"></label>
              <label>IPI CST<input name="edit_entry_ipi_cst[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_ipi_cst'] ?? '') ?>"></label>
              <label>Base IPI<input type="number" step="0.01" name="edit_ipi_base[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['ipi_base']??0),2,'.','')) ?>"></label>
              <label>Alíq. IPI %<input type="number" step="0.0001" name="edit_ipi_rate[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['ipi_rate']??0),4,'.','')) ?>"></label>
              <label>Valor IPI<input type="number" step="0.01" name="edit_ipi_amount[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['ipi_amount']??$it['ipi_value']??0),2,'.','')) ?>"></label>
              <label>PIS CST<input name="edit_entry_pis_cst[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_pis_cst'] ?? '') ?>"></label>
              <label>Base PIS<input type="number" step="0.01" name="edit_pis_base[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['pis_base']??0),2,'.','')) ?>"></label>
              <label>Alíq. PIS %<input type="number" step="0.0001" name="edit_pis_rate[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['pis_rate']??0),4,'.','')) ?>"></label>
              <label>Valor PIS<input type="number" step="0.01" name="edit_pis_amount[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['pis_amount']??0),2,'.','')) ?>"></label>
              <label>COFINS CST<input name="edit_entry_cofins_cst[<?= $iid ?>]" value="<?= fiscal_h($entryPayloadEdit['entry_cofins_cst'] ?? '') ?>"></label>
              <label>Base COFINS<input type="number" step="0.01" name="edit_cofins_base[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['cofins_base']??0),2,'.','')) ?>"></label>
              <label>Alíq. COFINS %<input type="number" step="0.0001" name="edit_cofins_rate[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['cofins_rate']??0),4,'.','')) ?>"></label>
              <label>Valor COFINS<input type="number" step="0.01" name="edit_cofins_amount[<?= $iid ?>]" value="<?= fiscal_h(number_format((float)($tj['cofins_amount']??0),2,'.','')) ?>"></label>
            </div>
          </div>
        </details>
      <?php endforeach; ?>
    </div>
    <div class="fiscal-form-actions" style="margin-top:10px"><button class="btn btn-primary" type="submit"><?= $xmlGenerated ? 'Salvar tributação' : 'Salvar itens e tributação' ?></button></div>
  </form>
</div>
<?php endif; ?>

<?php if ($xmlGenerated): ?>
<div class="fiscal-card" id="desfazerEntrada" style="border-color:#fed7aa;background:#fffaf5">
  <h2>Desfazer Nota</h2>
  <p class="fiscal-muted">Reverte o estoque lançado e remove o Contas a Pagar criado por esta entrada. Se alguma parcela já possuir baixa financeira, o Nexa bloqueará a operação até que a baixa seja estornada.</p>
  <form method="post" class="fiscal-form" onsubmit="return confirm('Confirmar o desfazimento desta nota? O estoque lançado e o Contas a Pagar ainda não baixado serão revertidos.');">
    <input type="hidden" name="action" value="undo_entry"><input type="hidden" name="id" value="<?= (int)$id ?>">
    <div class="fiscal-form-grid"><div class="span-3"><label>Motivo / observação</label><input name="undo_reason" placeholder="Ex.: corrigir produto, quantidade ou tributação da entrada"></div></div>
    <div class="fiscal-form-actions"><button class="btn" type="submit">Desfazer Nota e voltar para conferência</button></div>
  </form>
</div>
<?php endif; ?>


<?php fiscal_admin_end(); ?>
