<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/gestao_module.php';
require_once __DIR__ . '/../config/voucher_module.php';

$cid = function_exists('fiscal_v10_2_return_company_id') ? (int)fiscal_v10_2_return_company_id($pdo) : (int)COMPANY_ID;
$returnCrossContext = function_exists('fiscal_v10_2_return_is_cross_context') && fiscal_v10_2_return_is_cross_context($pdo);
$returnCompanyName = function_exists('fiscal_v10_entry_company_name') ? fiscal_v10_entry_company_name($pdo, $cid) : ('Empresa #' . $cid);
$err = '';

if (!function_exists('devolucao_clean_address')) {
  function devolucao_clean_address(string $address, string $district = ''): string {
    $address = trim(preg_replace('/\s+/u', ' ', str_replace(["\r", "\n"], ' ', $address)) ?? '');
    if ($address === '') return '';
    $district = trim($district);
    if (function_exists('gestao_clean_address_with_district')) {
      $address = gestao_clean_address_with_district($address, $district);
    }
    if ($district !== '') {
      $address = preg_replace('/\s*[,;\-]?\s*Bairro\s*:\s*' . preg_quote($district, '/') . '\s*$/iu', '', $address) ?? $address;
    }
    $address = preg_replace('/\s*[,;\-]?\s*Bairro\s*:\s*[^,;]+$/iu', '', $address) ?? $address;
    return trim($address, " \t\n\r\0\x0B,;-");
  }
}

try { fiscal_xml_ensure_schema($pdo); fiscal_ensure_document_reference_schema($pdo); finance_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['direction'] = 'ENTRADA';
    $_POST['source_type'] = 'DEVOLUCAO_CLIENTE';
    if ($returnCrossContext && function_exists('fiscal_v10_2_assert_customer_person')) {
      fiscal_v10_2_assert_customer_person($pdo, $cid, (int)($_POST['person_person_id'] ?? 0));
    }
    $isInternalPost = (int)($_POST['source_order_id'] ?? 0) > 0;
    $docId = fiscal_create_manual_movement_document($pdo, $cid, $_POST);
    if ($returnCrossContext) {
      $message = $isInternalPost
        ? ('Devolução interna do pedido registrada em ' . $returnCompanyName . '. Estoque e devolução financeira/Vale foram tratados sem emissão fiscal.')
        : ('Devolução de cliente registrada em ' . $returnCompanyName . '. O estoque foi tratado conforme a operação selecionada. Emissão da pré-NF-e permanece bloqueada no contexto F11.');
      $url = 'fiscal_devolucoes.php?ok=' . rawurlencode($message);
      if (function_exists('fiscal_v10_2_return_context_url')) $url = fiscal_v10_2_return_context_url($url);
      header('Location: ' . $url);
    } elseif ($isInternalPost) {
      header('Location: fiscal_devolucoes.php?ok=' . rawurlencode('Devolução interna registrada com sucesso. O pedido foi usado apenas como origem de controle, sem nota fiscal de referência e sem transmissão para a SEFAZ.'));
    } else {
      header('Location: fiscal_nfce_editar.php?id=' . $docId . '&ok=' . rawurlencode('Devolução de cliente gerada como pré-NF-e de entrada. Confira os dados e transmita quando estiver correto.'));
    }
    exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$operations = array_values(array_filter(fiscal_operations($pdo, $cid, 'ENTRADA', true), static fn($op) => strtoupper((string)($op['operation_type'] ?? '')) === 'DEVOLUCAO_CLIENTE' || str_contains(strtoupper((string)($op['name'] ?? '')), 'DEVOL')));
if (!$operations) $operations = fiscal_operations($pdo, $cid, 'ENTRADA', true);
$selectedOperationId = (int)($_POST['operation_id'] ?? $_GET['operation_id'] ?? 0);
if ($selectedOperationId <= 0 && $operations) $selectedOperationId = (int)($operations[0]['id'] ?? 0);
$selectedOperation = null;
foreach ($operations as $opRow) {
  if ((int)($opRow['id'] ?? 0) === $selectedOperationId) { $selectedOperation = $opRow; break; }
}
if (!$selectedOperation && $operations) $selectedOperation = $operations[0];
$companyFiscalConfig = fiscal_company_config($pdo, $cid);
$clients = fiscal_finance_people_options($pdo, $cid, 'customer', '', 500);
foreach ($clients as &$clientRow) {
  $clientRow['address'] = devolucao_clean_address((string)($clientRow['address'] ?? ''), (string)($clientRow['district'] ?? ''));
}
unset($clientRow);
$products = fiscal_product_options_for_movement($pdo, $cid, '', 500);

$selectedSourceIds = [];
foreach ((array)($_GET['source_doc_ids'] ?? []) as $sid) {
  $sid = (int)$sid;
  if ($sid > 0) $selectedSourceIds[$sid] = $sid;
}
$selectedSourceIds = array_values($selectedSourceIds);
$selectedSourceOrderId = (int)($_GET['source_order_id'] ?? $_POST['source_order_id'] ?? 0);
if ($selectedSourceOrderId > 0) $selectedSourceIds = [];

$sourceDocs = [];
$sourceOrder = null;
$sourceItems = [];
$docsById = [];
$sourceTaxMap = [];
$prefillRefs = '';
$prefillPerson = [];
$sourceWarning = '';
$recentSourceDocs = [];
$recentReturnMap = [];
$recentSourceOrders = [];
$recentOrderReturnMap = [];
try {
  if (function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'fiscal_documents')) {
    $sqlRecent = "SELECT fd.id, fd.model, fd.series, fd.number, fd.access_key, fd.issue_datetime, fd.total_document, fd.status,
                         p.name AS dest_name, p.document AS dest_document
                    FROM fiscal_documents fd
               LEFT JOIN fiscal_document_participants p ON p.company_id=fd.company_id AND p.fiscal_document_id=fd.id AND p.role='DESTINATARIO'
                   WHERE fd.company_id=? AND fd.model IN ('55','65') AND COALESCE(fd.direction,'SAIDA')='SAIDA'
                     AND UPPER(COALESCE(fd.status,''))='AUTORIZADA'
                ORDER BY fd.issue_datetime DESC, fd.id DESC LIMIT 250";
    $st = $pdo->prepare($sqlRecent);
    $st->execute([$cid]);
    $recentSourceDocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($recentSourceDocs && function_exists('fiscal_devolution_status_for_source_documents')) {
      $recentReturnMap = fiscal_devolution_status_for_source_documents($pdo, $cid, array_map(static fn($r) => (int)($r['id'] ?? 0), $recentSourceDocs));
    }
  }

  if (function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'pedidos') && fiscal_table_exists($pdo, 'pedido_itens')) {
    $sqlOrders = "SELECT p.id, p.numero_pedido, p.nome_cliente, p.finance_person_id, p.total, p.created_at, p.status, p.forma_pagamento,
                         fp.document AS customer_document,
                         COALESCE((SELECT SUM(pi.total) FROM pedido_itens pi WHERE pi.company_id=p.company_id AND pi.pedido_id=p.id),0) AS items_total
                    FROM pedidos p
               LEFT JOIN finance_people fp ON fp.company_id=p.company_id AND fp.id=p.finance_person_id
                   WHERE p.company_id=? AND LOWER(TRIM(COALESCE(p.status,''))) IN ('finalizado','aberto')
                     AND NOT EXISTS (
                       SELECT 1 FROM fiscal_documents fdo
                        WHERE fdo.company_id=p.company_id AND fdo.direction='SAIDA' AND fdo.model IN ('55','65')
                          AND UPPER(COALESCE(fdo.source_type,''))='PEDIDO' AND fdo.source_id=p.id
                          AND UPPER(COALESCE(fdo.status,''))<>'CANCELADA'
                     )
                ORDER BY p.created_at DESC, p.id DESC LIMIT 250";
    $so = $pdo->prepare($sqlOrders);
    $so->execute([$cid]);
    $recentSourceOrders = $so->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($recentSourceOrders) {
      $orderIds = array_values(array_filter(array_map(static fn($r) => (int)($r['id'] ?? 0), $recentSourceOrders)));
      if ($orderIds) {
        $oph = implode(',', array_fill(0, count($orderIds), '?'));
        $rs = $pdo->prepare("SELECT rd.source_id AS order_id, SUM(rdi.total_value) AS returned_total
                               FROM fiscal_documents rd
                               JOIN fiscal_document_items rdi ON rdi.company_id=rd.company_id AND rdi.fiscal_document_id=rd.id
                              WHERE rd.company_id=? AND rd.direction='ENTRADA' AND rd.model='00'
                                AND UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE'
                                AND rd.source_id IN ($oph) AND UPPER(COALESCE(rd.status,''))<>'CANCELADA'
                           GROUP BY rd.source_id");
        $rs->execute(array_merge([$cid], $orderIds));
        foreach (($rs->fetchAll(PDO::FETCH_ASSOC) ?: []) as $rr) {
          $recentOrderReturnMap[(int)$rr['order_id']] = (float)($rr['returned_total'] ?? 0);
        }
      }
    }
  }
} catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

if ($selectedSourceOrderId > 0) {
  try {
    $os = $pdo->prepare("SELECT p.*,
                               COALESCE((SELECT SUM(pi.total) FROM pedido_itens pi WHERE pi.company_id=p.company_id AND pi.pedido_id=p.id),0) AS items_total
                          FROM pedidos p
                         WHERE p.company_id=? AND p.id=? AND LOWER(TRIM(COALESCE(p.status,''))) IN ('finalizado','aberto')
                           AND NOT EXISTS (
                             SELECT 1 FROM fiscal_documents fdo
                              WHERE fdo.company_id=p.company_id AND fdo.direction='SAIDA' AND fdo.model IN ('55','65')
                                AND UPPER(COALESCE(fdo.source_type,''))='PEDIDO' AND fdo.source_id=p.id
                                AND UPPER(COALESCE(fdo.status,''))<>'CANCELADA'
                           )
                         LIMIT 1");
    $os->execute([$cid, $selectedSourceOrderId]);
    $sourceOrder = $os->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$sourceOrder) {
      $selectedSourceOrderId = 0;
      $sourceWarning = 'O pedido não está disponível para devolução interna. Confira se está aberto/finalizado e se não possui NF-e/NFC-e vinculada.';
    } else {
      $personId = (int)($sourceOrder['finance_person_id'] ?? 0);
      if ($personId > 0 && fiscal_table_exists($pdo, 'finance_people')) {
        $ps = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
        $ps->execute([$cid, $personId]);
        $person = $ps->fetch(PDO::FETCH_ASSOC) ?: [];
        if ($person) {
          $prefillPerson = [
            'person_id' => $person['id'] ?? '',
            'name' => $person['name'] ?? '',
            'document' => $person['document'] ?? '',
            'ie' => $person['state_registration'] ?? '',
            'phone' => ($person['whatsapp'] ?? '') ?: ($person['phone'] ?? ''),
            'email' => $person['email'] ?? '',
            'zip' => $person['zip_code'] ?? '',
            'address' => devolucao_clean_address((string)($person['address'] ?? ''), (string)($person['district'] ?? '')),
            'number' => $person['number'] ?? '',
            'complement' => $person['complement'] ?? '',
            'district' => $person['district'] ?? '',
            'city' => $person['city'] ?? '',
            'city_ibge' => $person['city_ibge'] ?? '',
            'state' => $person['state'] ?? '',
          ];
        }
      }
      if (!$prefillPerson) {
        $prefillPerson = [
          'person_id' => '', 'name' => (string)($sourceOrder['nome_cliente'] ?? ''), 'document' => '', 'ie' => '',
          'phone' => (string)($sourceOrder['whatsapp'] ?? ''), 'email' => '', 'zip' => (string)($sourceOrder['zip_code'] ?? ''),
          'address' => devolucao_clean_address((string)($sourceOrder['endereco'] ?? ''), ''), 'number' => (string)($sourceOrder['numero'] ?? ''),
          'complement' => (string)($sourceOrder['complemento'] ?? ''), 'district' => '', 'city' => (string)($sourceOrder['cidade'] ?? ''),
          'city_ibge' => '', 'state' => (string)($sourceOrder['uf'] ?? ''),
        ];
        $sourceWarning = 'Este pedido não possui cliente cadastrado vinculado. Selecione o cliente antes de concluir a devolução para permitir o controle de dinheiro ou Vale.';
      }

      $it = $pdo->prepare("SELECT pi.id AS source_item_id, pi.produto_id AS product_id,
                                  COALESCE(pr.nome,'Produto') AS description,
                                  pi.qtd AS quantity, pi.preco_unit AS unit_value, pi.total AS total_value,
                                  COALESCE(pr.unidade_estoque,'UN') AS unidade,
                                  COALESCE((SELECT SUM(rdi.quantity)
                                              FROM fiscal_document_items rdi
                                              JOIN fiscal_documents rd ON rd.id=rdi.fiscal_document_id AND rd.company_id=rdi.company_id
                                             WHERE rdi.company_id=pi.company_id AND rdi.order_item_id=pi.id
                                               AND rd.direction='ENTRADA' AND rd.model='00'
                                               AND UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE'
                                               AND rd.source_id=? AND UPPER(COALESCE(rd.status,''))<>'CANCELADA'),0) AS returned_quantity
                             FROM pedido_itens pi
                        LEFT JOIN produtos pr ON pr.company_id=pi.company_id AND pr.id=pi.produto_id
                            WHERE pi.company_id=? AND pi.pedido_id=?
                         ORDER BY pi.id ASC");
      $it->execute([$selectedSourceOrderId, $cid, $selectedSourceOrderId]);
      $sourceItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($sourceItems as &$orderItem) {
        $orderItem['fiscal_document_id'] = 0;
        $orderItem['product_code'] = '';
        $orderItem['ncm'] = '';
        $orderItem['cest'] = '';
        $orderItem['cfop'] = '';
      }
      unset($orderItem);

      $selectedProductIds = [];
      foreach ($sourceItems as $si) { if ((int)($si['product_id'] ?? 0) > 0) $selectedProductIds[(int)$si['product_id']] = (int)$si['product_id']; }
      $existingProductIds = [];
      foreach ($products as $pRow) $existingProductIds[(int)$pRow['id']] = true;
      $missingProductIds = array_values(array_filter($selectedProductIds, static fn($pid) => empty($existingProductIds[$pid])));
      if ($missingProductIds) {
        $inProd = implode(',', array_fill(0, count($missingProductIds), '?'));
        $prodParams = array_merge([$cid], $missingProductIds);
        $hasNumber = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
        $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
        $hasBarcode = fiscal_column_exists($pdo, 'produtos', 'codigo_barras');
        $hasUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
        $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
        $codeExpr = $hasNumber ? ($hasCode ? "COALESCE(NULLIF(numero_cadastro,0),NULLIF(codigo_firebird,''),id)" : "COALESCE(NULLIF(numero_cadastro,0),id)") : ($hasCode ? "COALESCE(NULLIF(codigo_firebird,''),id)" : 'id');
        $barcodeExpr = $hasBarcode ? 'COALESCE(codigo_barras,\'\')' : "''";
        $unitExpr = $hasUnit ? "COALESCE(NULLIF(unidade_estoque,''),'UN')" : "'UN'";
        $costExpr = $hasCost ? 'COALESCE(custo,0)' : '0';
        $ps = $pdo->prepare("SELECT id, {$codeExpr} AS code, {$barcodeExpr} AS barcode, nome, preco, {$costExpr} AS custo, {$unitExpr} AS unidade FROM produtos WHERE company_id=? AND id IN ($inProd)");
        $ps->execute($prodParams);
        foreach (($ps->fetchAll(PDO::FETCH_ASSOC) ?: []) as $extraProd) $products[] = $extraProd;
      }
    }
  } catch (Throwable $e) { $err = trim($err . ' ' . $e->getMessage()); }
} elseif ($selectedSourceIds) {
  try {
    $in = implode(',', array_fill(0, count($selectedSourceIds), '?'));
    $params = array_merge([$cid], $selectedSourceIds);
    $st = $pdo->prepare("SELECT fd.*, p.person_id AS dest_person_id, p.document AS dest_document, p.state_registration AS dest_ie,
                                p.name AS dest_name, p.email AS dest_email, p.phone AS dest_phone, p.zip_code AS dest_zip,
                                p.address AS dest_address, p.number AS dest_number, p.complement AS dest_complement,
                                p.district AS dest_district, p.city AS dest_city, p.city_ibge AS dest_city_ibge, p.state AS dest_state
                           FROM fiscal_documents fd
                      LEFT JOIN fiscal_document_participants p ON p.company_id=fd.company_id AND p.fiscal_document_id=fd.id AND p.role='DESTINATARIO'
                          WHERE fd.company_id=? AND fd.id IN ($in) AND fd.model IN ('55','65')
                       ORDER BY fd.issue_datetime ASC, fd.id ASC");
    $st->execute($params);
    $sourceDocs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $keys = [];
    foreach ($sourceDocs as $d) {
      $docsById[(int)$d['id']] = $d;
      $k = fiscal_digits($d['access_key'] ?? '');
      if (strlen($k) === 44) $keys[] = $k;
    }
    $prefillRefs = implode(' ', array_values(array_unique($keys)));
    if ($sourceDocs) {
      $first = $sourceDocs[0];
      $prefillPerson = [
        'person_id' => $first['dest_person_id'] ?? '',
        'name' => $first['dest_name'] ?? '',
        'document' => $first['dest_document'] ?? '',
        'ie' => $first['dest_ie'] ?? '',
        'phone' => $first['dest_phone'] ?? '',
        'email' => $first['dest_email'] ?? '',
        'zip' => $first['dest_zip'] ?? '',
        'address' => devolucao_clean_address((string)($first['dest_address'] ?? ''), (string)($first['dest_district'] ?? '')),
        'number' => $first['dest_number'] ?? '',
        'complement' => $first['dest_complement'] ?? '',
        'district' => $first['dest_district'] ?? '',
        'city' => $first['dest_city'] ?? '',
        'city_ibge' => $first['dest_city_ibge'] ?? '',
        'state' => $first['dest_state'] ?? '',
      ];
      $destDocs = array_values(array_unique(array_filter(array_map(static fn($d) => fiscal_digits($d['dest_document'] ?? ''), $sourceDocs))));
      if (count($destDocs) > 1) $sourceWarning = 'Atenção: as notas selecionadas possuem destinatários diferentes. Confira os dados do cliente antes de gerar a devolução.';
    }
    if ($sourceDocs) {
      $ids = array_keys($docsById);
      $inItems = implode(',', array_fill(0, count($ids), '?'));
      $itemParams = array_merge([$cid], $ids);
      $it = $pdo->prepare("SELECT id AS source_item_id, fiscal_document_id, product_id, product_code, description, ncm, cest, unidade, quantity, unit_value, total_value, cfop
                             FROM fiscal_document_items
                            WHERE company_id=? AND fiscal_document_id IN ($inItems)
                         ORDER BY fiscal_document_id ASC, item_number ASC, id ASC");
      $it->execute($itemParams);
      $sourceItems = $it->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $sourceItemIds = array_values(array_filter(array_map(static fn($r) => (int)($r['source_item_id'] ?? 0), $sourceItems)));
      if ($sourceItemIds && fiscal_table_exists($pdo, 'fiscal_document_item_taxes')) {
        $inTaxes = implode(',', array_fill(0, count($sourceItemIds), '?'));
        $taxParams = array_merge([$cid], $sourceItemIds);
        $tx = $pdo->prepare("SELECT fiscal_document_item_id, tax_group, cst, csosn, rate, amount
                               FROM fiscal_document_item_taxes
                              WHERE company_id=? AND fiscal_document_item_id IN ($inTaxes)
                           ORDER BY fiscal_document_item_id, id");
        $tx->execute($taxParams);
        foreach (($tx->fetchAll(PDO::FETCH_ASSOC) ?: []) as $taxRow) {
          $sourceTaxMap[(int)$taxRow['fiscal_document_item_id']][] = $taxRow;
        }
      }
      $selectedProductIds = [];
      foreach ($sourceItems as $si) { if ((int)($si['product_id'] ?? 0) > 0) $selectedProductIds[(int)$si['product_id']] = (int)$si['product_id']; }
      $existingProductIds = [];
      foreach ($products as $pRow) $existingProductIds[(int)$pRow['id']] = true;
      $missingProductIds = array_values(array_filter($selectedProductIds, static fn($pid) => empty($existingProductIds[$pid])));
      if ($missingProductIds) {
        $inProd = implode(',', array_fill(0, count($missingProductIds), '?'));
        $prodParams = array_merge([$cid], $missingProductIds);
        $hasNumber = fiscal_column_exists($pdo, 'produtos', 'numero_cadastro');
        $hasCode = fiscal_column_exists($pdo, 'produtos', 'codigo_firebird');
        $hasBarcode = fiscal_column_exists($pdo, 'produtos', 'codigo_barras');
        $hasUnit = fiscal_column_exists($pdo, 'produtos', 'unidade_estoque');
        $hasCost = fiscal_column_exists($pdo, 'produtos', 'custo');
        $codeExpr = $hasNumber ? ($hasCode ? "COALESCE(NULLIF(numero_cadastro,0),NULLIF(codigo_firebird,''),id)" : "COALESCE(NULLIF(numero_cadastro,0),id)") : ($hasCode ? "COALESCE(NULLIF(codigo_firebird,''),id)" : 'id');
        $barcodeExpr = $hasBarcode ? 'COALESCE(codigo_barras,\'\')' : "''";
        $unitExpr = $hasUnit ? "COALESCE(NULLIF(unidade_estoque,''),'UN')" : "'UN'";
        $costExpr = $hasCost ? 'COALESCE(custo,0)' : '0';
        $ps = $pdo->prepare("SELECT id, {$codeExpr} AS code, {$barcodeExpr} AS barcode, nome, preco, {$costExpr} AS custo, {$unitExpr} AS unidade FROM produtos WHERE company_id=? AND id IN ($inProd)");
        $ps->execute($prodParams);
        foreach (($ps->fetchAll(PDO::FETCH_ASSOC) ?: []) as $extraProd) $products[] = $extraProd;
      }
    }
  } catch (Throwable $e) { $err = trim($err . ' ' . $e->getMessage()); }
}
$productLabels = [];
foreach ($products as $pRow) {
  $label = trim((string)($pRow['code'] ?? $pRow['id']) . ' · ' . (string)($pRow['nome'] ?? '') . ' · ' . (string)($pRow['barcode'] ?? ''));
  $productLabels[(int)$pRow['id']] = $label;
}
$itemRows = [];
foreach ($sourceItems as $si) {
  if ($selectedSourceOrderId > 0) {
    $availableOrderQty = max(0.0, (float)($si['quantity'] ?? 0) - (float)($si['returned_quantity'] ?? 0));
    if ($availableOrderQty <= 0.0001) continue;
  }
  $pid = (int)($si['product_id'] ?? 0);
  $resolvedCfop = '';
  if ($pid > 0 && $selectedOperation && function_exists('fiscal_resolve_movement_cfop')) {
    try {
      $taxForCfop = fiscal_product_tax($pdo, $cid, $pid);
      $resolvedCfop = fiscal_resolve_movement_cfop($pdo, $cid, $pid, $selectedOperation, 'ENTRADA', $prefillPerson, $taxForCfop, (string)($companyFiscalConfig['uf'] ?? ''));
    } catch (Throwable $e) { $resolvedCfop = ''; }
  }
  $sourceDoc = $docsById[(int)($si['fiscal_document_id'] ?? 0)] ?? [];
  if ($selectedSourceOrderId > 0 && $sourceOrder) {
    $orderNo = (int)($sourceOrder['numero_pedido'] ?? 0) ?: (int)($sourceOrder['id'] ?? $selectedSourceOrderId);
    $sourceLabel = 'Pedido #' . $orderNo . ' · sem nota fiscal';
  } else {
    $sourceLabel = (($sourceDoc['model'] ?? '') === '65' ? 'NFC-e' : 'NF-e') . ' ' . (string)($sourceDoc['series'] ?? '') . '/' . (string)($sourceDoc['number'] ?? '');
  }
  $taxLabels = [];
  foreach (($sourceTaxMap[(int)($si['source_item_id'] ?? 0)] ?? []) as $taxRow) {
    $group = strtoupper((string)($taxRow['tax_group'] ?? ''));
    $code = trim((string)($taxRow['csosn'] ?? '')) ?: trim((string)($taxRow['cst'] ?? ''));
    $rate = (float)($taxRow['rate'] ?? 0);
    $taxLabels[] = trim($group . ($code !== '' ? ' ' . $code : '') . ($rate > 0 ? ' · ' . number_format($rate, 2, ',', '.') . '%' : ''));
  }
  $itemRows[] = [
    'source_item_id' => (int)($si['source_item_id'] ?? 0),
    'product_id' => $pid > 0 ? $pid : '',
    'product_label' => $pid > 0 ? ($productLabels[$pid] ?? (string)($si['description'] ?? '')) : (string)($si['description'] ?? ''),
    'description' => (string)($si['description'] ?? ''),
    'quantity' => '',
    'max_quantity' => $selectedSourceOrderId > 0 ? max(0.0, (float)($si['quantity'] ?? 0) - (float)($si['returned_quantity'] ?? 0)) : (float)($si['quantity'] ?? 0),
    'sold_quantity' => (float)($si['quantity'] ?? 0),
    'returned_quantity' => (float)($si['returned_quantity'] ?? 0),
    'unit_value' => (float)($si['unit_value'] ?? 0),
    'original_total' => (float)($si['total_value'] ?? 0),
    'cfop' => $resolvedCfop,
    'original_cfop' => (string)($si['cfop'] ?? ''),
    'unit' => (string)($si['unidade'] ?? 'UN'),
    'ncm' => (string)($si['ncm'] ?? ''),
    'cest' => (string)($si['cest'] ?? ''),
    'source_label' => trim($sourceLabel),
    'tax_summary' => implode(' · ', array_filter($taxLabels)),
  ];
}
if (!$itemRows) {
  $itemRows[] = ['source_item_id'=>'','product_id'=>'','product_label'=>'','description'=>'','quantity'=>'','max_quantity'=>'','sold_quantity'=>'','returned_quantity'=>'','unit_value'=>'','original_total'=>'','cfop'=>'','original_cfop'=>'','unit'=>'UN','ncm'=>'','cest'=>'','source_label'=>'','tax_summary'=>''];
}

$isInternalOrderReturn = $selectedSourceOrderId > 0 && (bool)$sourceOrder;
$fiscalParticipantRequired = !$isInternalOrderReturn;
$manualEntryMode = !$sourceDocs && !$sourceOrder;
$returnPageTitle = $returnCrossContext ? ('Devolução de cliente · ' . $returnCompanyName) : 'Devolução de cliente';
fiscal_admin_start('Entradas - ' . $returnPageTitle, 'fiscal_devolucao_cliente');
fiscal_page_header($returnPageTitle, 'Registre devoluções por NF-e/NFC-e de origem ou por pedido sem nota. Pedidos sem documento fiscal são tratados somente como controle interno de estoque, dinheiro e Vale.', [
  ['label'=>'Voltar para Entradas', 'href'=>'fiscal_radar_xml.php', 'class'=>'btn'],
  ['label'=>'Operações fiscais', 'href'=>'fiscal_operacoes.php', 'class'=>'btn'],
]);
fiscal_tabs('fiscal_devolucao_cliente');
fiscal_flash();
if ($returnCrossContext) {
  echo '<div class="msg ok"><strong>V10.2 · contexto F11:</strong> cliente, notas de origem, produtos, operação fiscal e eventual retorno de estoque pertencem exclusivamente a ' . fiscal_h($returnCompanyName) . '. A emissão/transmissão da pré-NF-e permanece protegida nesta etapa.</div>';
}
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
if ($sourceWarning) echo '<div class="msg" style="border-color:#fde68a;background:#fffbeb;color:#92400e">' . fiscal_h($sourceWarning) . '</div>';
if ($sourceDocs) echo '<div class="msg ok">' . fiscal_h(count($sourceDocs) . ' nota(s) original(is) carregada(s). Confira cliente, itens e quantidades antes de gerar a devolução.') . '</div>';
if ($sourceOrder) {
  $sourceOrderNo = (int)($sourceOrder['numero_pedido'] ?? 0) ?: (int)$sourceOrder['id'];
  echo '<div class="msg ok"><strong>Devolução interna:</strong> Pedido #' . fiscal_h((string)$sourceOrderNo) . ' carregado sem nota fiscal. Não será criada chave de referência nem haverá transmissão para a SEFAZ.</div>';
}
?>
<style>
  .devolucao-ref-row,.devolucao-client-picker{display:grid;grid-template-columns:1fr auto;gap:8px;align-items:end}.devolucao-ref-row{grid-template-columns:minmax(180px,1fr) auto auto}.devolucao-ref-row .btn{white-space:nowrap}.devolucao-client-picker .btn{min-height:44px}.devolucao-source-list{display:flex;gap:8px;flex-wrap:wrap;margin-top:8px}.devolucao-source-pill{border:1px solid #dbe4f0;background:#f8fbff;border-radius:999px;padding:7px 10px;font-size:12px;font-weight:850;color:#334155}.devolucao-modal-table tbody tr[hidden]{display:none!important}.devolucao-modal-actions{position:sticky;bottom:0;background:#fff;border-top:1px solid #e2e8f0;padding-top:10px;margin-top:12px}.devolucao-search-box{margin-bottom:10px}.devolucao-client-results{display:grid;gap:8px;max-height:430px;overflow:auto}.devolucao-client-result{width:100%;display:flex;justify-content:space-between;gap:12px;align-items:center;text-align:left;border:1px solid #dbe4f0;border-radius:16px;background:#fff;padding:12px;cursor:pointer}.devolucao-client-result:hover{border-color:#2563eb;background:#eff6ff}.devolucao-client-result strong,.devolucao-client-result small{display:block}.devolucao-client-result small{color:#64748b;margin-top:3px}.devolucao-client-result b{color:#1d4ed8}.devolucao-item-details{display:grid;gap:3px;margin-top:6px;color:#64748b;font-size:11px;line-height:1.35}.devolucao-item-details strong{color:#334155}.devolucao-origin-qty{font-weight:900;color:#0f172a;white-space:nowrap}.devolucao-line-total{font-weight:900;color:#047857;white-space:nowrap}.devolucao-qty.invalid{border-color:#dc2626!important;box-shadow:0 0 0 3px rgba(220,38,38,.12)}.devolucao-refund-options{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.devolucao-refund-option{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:16px;padding:13px;background:#fff;cursor:pointer}.devolucao-refund-option:has(input:checked){border-color:#2563eb;background:#eff6ff}.devolucao-refund-option input{margin-top:4px}.devolucao-refund-option strong,.devolucao-refund-option small{display:block}.devolucao-refund-option small{color:#64748b;margin-top:3px}.devolucao-totals{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-top:14px}.devolucao-total-card{border:1px solid #dbe4f0;border-radius:16px;background:#f8fbff;padding:12px}.devolucao-total-card span{display:block;color:#64748b;font-size:12px;font-weight:850}.devolucao-total-card strong{display:block;color:#0b1f5b;font-size:20px;margin-top:4px}@media(max-width:780px){.devolucao-ref-row,.devolucao-client-picker,.devolucao-refund-options{grid-template-columns:1fr}.devolucao-modal-actions{position:static}.devolucao-totals{grid-template-columns:1fr}.devolucao-client-picker .btn{width:100%}}
</style>
<div class="fiscal-card">
  <form method="post" class="fiscal-form" id="movementForm">
    <input type="hidden" name="source_order_id" value="<?= $isInternalOrderReturn ? (int)$selectedSourceOrderId : 0 ?>">
    <div class="fiscal-section">
      <h3><?= $isInternalOrderReturn ? 'Operação e pedido de origem' : 'Operação e referência fiscal' ?></h3>
      <div class="fiscal-form-grid">
        <div><label>Operação fiscal</label><select name="operation_id" id="operation_id" required><?php foreach ($operations as $op): ?><option value="<?= (int)$op['id'] ?>" <?= (int)$op['id'] === $selectedOperationId ? 'selected' : '' ?>><?= fiscal_h(($op['code'] ?? '') . ' - ' . ($op['name'] ?? '')) ?><?= !empty($op['update_stock']) ? ' · estoque' : '' ?><?= !empty($op['create_finance']) ? ' · financeiro' : ' · sem financeiro' ?></option><?php endforeach; ?></select></div>
        <div class="span-2">
          <label><?= $isInternalOrderReturn ? 'Origem da devolução' : 'Chave(s) da NF-e/NFC-e original referenciada' ?></label>
          <div class="devolucao-ref-row">
            <input name="reference_keys" id="reference_keys" <?= $isInternalOrderReturn ? 'readonly' : 'required' ?> value="<?= fiscal_h($prefillRefs) ?>" placeholder="<?= $isInternalOrderReturn ? 'Pedido sem nota fiscal — nenhuma chave de referência será gerada.' : 'Cole a chave de 44 dígitos da nota original. Pode informar mais de uma, separando por espaço.' ?>">
            <button type="button" class="btn" id="openSourceDocs" title="Buscar notas/pedidos (F8)">🔎 Buscar notas/pedidos <small>F8</small></button>
            <button type="button" class="btn" id="openOrdersOnly" title="Devolução interna sem NF-e/NFC-e; não transmite à SEFAZ">↩ Devolver pedido sem nota</button>
          </div>
          <?php if ($sourceDocs): ?><div class="devolucao-source-list"><?php foreach ($sourceDocs as $sd): ?><span class="devolucao-source-pill"><?= fiscal_h((($sd['model'] ?? '') === '65' ? 'NFC-e' : 'NF-e') . ' ' . ($sd['series'] ?? '') . '/' . ($sd['number'] ?? '')) ?></span><?php endforeach; ?></div><?php endif; ?>
          <?php if ($sourceOrder): $sourceOrderNo = (int)($sourceOrder['numero_pedido'] ?? 0) ?: (int)$sourceOrder['id']; ?><div class="devolucao-source-list"><span class="devolucao-source-pill">Pedido #<?= (int)$sourceOrderNo ?> · controle interno sem nota fiscal</span></div><?php endif; ?>
        </div>
        <div class="span-3"><label>Observações</label><textarea name="notes" placeholder="Motivo da devolução, conferência, autorização etc."></textarea></div>
      </div>
    </div>

    <div class="fiscal-section">
      <h3>Dados completos do cliente</h3>
      <div class="fiscal-form-grid">
        <div class="span-2"><label>Buscar cliente cadastrado</label><div class="devolucao-client-picker"><input id="clientPick" value="<?= fiscal_h(trim((string)($prefillPerson['name'] ?? '') . ((string)($prefillPerson['document'] ?? '') !== '' ? ' · ' . (string)$prefillPerson['document'] : ''))) ?>" placeholder="Clique na lupa para localizar o cliente" readonly><button type="button" class="btn" id="openClientSearch" title="Pesquisar cliente">🔎 Pesquisar</button></div></div>
        <div><label>ID cliente</label><input name="person_person_id" id="person_id" value="<?= fiscal_h($prefillPerson['person_id'] ?? '') ?>" placeholder="Selecione um cliente" required readonly></div>
        <div class="span-2"><label>Nome/Razão social</label><input name="person_name" id="person_name" value="<?= fiscal_h($prefillPerson['name'] ?? '') ?>" required></div>
        <div><label>CPF/CNPJ</label><input name="person_document" id="person_document" value="<?= fiscal_h($prefillPerson['document'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>IE</label><input name="person_ie" id="person_ie" value="<?= fiscal_h($prefillPerson['ie'] ?? '') ?>"></div>
        <div><label>Telefone</label><input name="person_phone" id="person_phone" value="<?= fiscal_h($prefillPerson['phone'] ?? '') ?>"></div>
        <div><label>E-mail</label><input name="person_email" id="person_email" value="<?= fiscal_h($prefillPerson['email'] ?? '') ?>"></div>
        <div><label>CEP</label><input name="person_zip" id="person_zip" value="<?= fiscal_h($prefillPerson['zip'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div class="span-2"><label>Endereço</label><input name="person_address" id="person_address" value="<?= fiscal_h($prefillPerson['address'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>Número</label><input name="person_number" id="person_number" value="<?= fiscal_h($prefillPerson['number'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>Complemento</label><input name="person_complement" id="person_complement" value="<?= fiscal_h($prefillPerson['complement'] ?? '') ?>"></div>
        <div><label>Bairro</label><input name="person_district" id="person_district" value="<?= fiscal_h($prefillPerson['district'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>Cidade</label><input name="person_city" id="person_city" value="<?= fiscal_h($prefillPerson['city'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>UF</label><input name="person_state" id="person_state" maxlength="2" value="<?= fiscal_h($prefillPerson['state'] ?? '') ?>" <?= $fiscalParticipantRequired ? 'required' : '' ?>></div>
        <div><label>Cód. IBGE cidade</label><input name="person_city_ibge" id="person_city_ibge" value="<?= fiscal_h($prefillPerson['city_ibge'] ?? '') ?>" placeholder="Opcional, mas recomendado"></div>
      </div>
    </div>

    <div class="fiscal-section">
      <h3>Devolução do valor ao cliente</h3>
      <p class="fiscal-muted">Escolha como o crédito da devolução será entregue. O cliente cadastrado é obrigatório tanto para devolver em dinheiro quanto para gerar Vale.</p>
      <div class="devolucao-refund-options">
        <label class="devolucao-refund-option"><input type="radio" name="refund_mode" value="DINHEIRO" required <?= (($_POST['refund_mode'] ?? '') === 'DINHEIRO') ? 'checked' : '' ?>><span><strong>Devolver em dinheiro</strong><small>Registra débito no movimento financeiro e uma saída/estorno no caixa aberto do usuário.</small></span></label>
        <label class="devolucao-refund-option"><input type="radio" name="refund_mode" value="VALE" required <?= (($_POST['refund_mode'] ?? '') === 'VALE') ? 'checked' : '' ?>><span><strong>Gerar Vale para o cliente</strong><small>Cria crédito com saldo controlado, utilizável total ou parcialmente em vendas futuras.</small></span></label>
      </div>
    </div>

    <div class="fiscal-section">
      <h3>Produtos devolvidos</h3>
      <p class="fiscal-muted"><?= $isInternalOrderReturn ? 'Informe somente a quantidade que será devolvida. O saldo restante do pedido ficará disponível para uma devolução futura. Esta movimentação é interna e não será transmitida à SEFAZ.' : 'Informe quantidade somente nos itens que serão devolvidos. Os demais permanecerão fora da pré-NF-e. A tributação e a CFOP podem ser conferidas antes da transmissão.' ?></p>
      <div class="table-wrap"><table class="fiscal-table" id="itemsTable"><thead><tr><th>Produto e detalhes fiscais</th><th style="width:105px">Qtd. disponível</th><th style="width:125px">Qtd. devolução</th><th style="width:125px">Valor unit.</th><th style="width:125px">Total</th><th style="width:132px">CFOP</th><th style="width:80px"></th></tr></thead><tbody>
        <?php foreach ($itemRows as $i => $row):
          $hasSource = (float)($row['max_quantity'] ?? 0) > 0;
          $detailParts = [];
          if (($row['source_label'] ?? '') !== '') $detailParts[] = 'Origem: ' . $row['source_label'];
          if (($row['unit'] ?? '') !== '') $detailParts[] = 'Un.: ' . $row['unit'];
          if (($row['ncm'] ?? '') !== '') $detailParts[] = 'NCM ' . $row['ncm'];
          if (($row['cest'] ?? '') !== '') $detailParts[] = 'CEST ' . $row['cest'];
          if (($row['original_cfop'] ?? '') !== '') $detailParts[] = 'CFOP original ' . $row['original_cfop'];
          if ((float)($row['returned_quantity'] ?? 0) > 0) $detailParts[] = 'Já devolvido: ' . number_format((float)$row['returned_quantity'], 3, ',', '.');
        ?><tr data-source-row="<?= $hasSource ? '1' : '0' ?>">
          <td data-label="Produto"><input list="productOptions" class="product-pick" value="<?= fiscal_h((string)($row['product_label'] ?? '')) ?>" placeholder="Produto, código ou código de barras"><input type="hidden" name="product_id[]" class="product-id" value="<?= fiscal_h((string)($row['product_id'] ?? '')) ?>"><input type="hidden" name="source_item_id[]" value="<?= fiscal_h((string)($row['source_item_id'] ?? '')) ?>"><div class="devolucao-item-details"><?php if ($detailParts): ?><span><?= fiscal_h(implode(' · ', $detailParts)) ?></span><?php endif; ?><?php if (($row['tax_summary'] ?? '') !== ''): ?><strong>Tributos: <?= fiscal_h((string)$row['tax_summary']) ?></strong><?php elseif (!$hasSource): ?><span>A tributação será carregada do cadastro do produto.</span><?php endif; ?></div></td>
          <td data-label="Qtd. disponível"><span class="devolucao-origin-qty"><?= $hasSource ? fiscal_h(number_format((float)$row['max_quantity'], 3, ',', '.')) : '—' ?></span><input type="hidden" name="max_quantity[]" class="max-quantity" value="<?= $hasSource ? fiscal_h((string)(float)$row['max_quantity']) : '' ?>"></td>
          <td data-label="Qtd. devolução"><input name="quantity[]" class="devolucao-qty" inputmode="decimal" value="<?= fiscal_h((string)($row['quantity'] ?? '')) ?>" <?= $hasSource ? 'placeholder="0"' : '' ?>></td>
          <td data-label="Valor unit."><input name="unit_value[]" class="unit-value" inputmode="decimal" value="<?= ($row['unit_value'] ?? '') !== '' ? fiscal_h(number_format((float)$row['unit_value'], 2, ',', '')) : '' ?>"></td>
          <td data-label="Total"><span class="devolucao-line-total">R$ 0,00</span></td>
          <td data-label="CFOP"><input name="cfop[]" class="fiscal-cfop-input" maxlength="4" inputmode="numeric" value="<?= fiscal_h((string)($row['cfop'] ?? '')) ?>" placeholder="operação"></td>
          <td data-label=""><button type="button" class="btn btn-sm clear-row"><?= $hasSource ? 'Zerar qtd.' : 'Limpar' ?></button></td>
        </tr><?php endforeach; ?>
      </tbody></table></div>
      <?php if ($manualEntryMode): ?><div class="fiscal-form-actions" style="justify-content:flex-start;margin-top:10px"><button type="button" class="btn" id="addReturnItem">+ Adicionar item</button></div><?php endif; ?>
      <div class="devolucao-totals" aria-live="polite">
        <div class="devolucao-total-card"><span>Produtos selecionados</span><strong id="returnSelectedCount">0</strong></div>
        <div class="devolucao-total-card"><span>Quantidade total</span><strong id="returnQuantityTotal">0</strong></div>
        <div class="devolucao-total-card"><span>Total da devolução</span><strong id="returnValueTotal">R$ 0,00</strong></div>
      </div>
    </div>

    <div class="fiscal-form-actions"><a class="btn" href="fiscal_radar_xml.php">Cancelar</a><button class="btn btn-primary"><?= $isInternalOrderReturn ? 'Registrar devolução interna' : 'Gerar pré-NF-e de devolução' ?></button></div>
  </form>
</div>

<div class="fiscal-modal" id="clientSearchModal" role="dialog" aria-modal="true" style="display:none">
  <div class="fiscal-modal-dialog fiscal-modal-wide">
    <div class="fiscal-modal-head"><div><h2>Pesquisar cliente</h2><p class="fiscal-muted" style="margin:5px 0 0">Busque por nome, CPF/CNPJ, telefone ou cidade e selecione o cliente da devolução.</p></div><button type="button" class="fiscal-modal-close" id="closeClientSearch" aria-label="Fechar">×</button></div>
    <div class="devolucao-search-box"><label>Pesquisar cliente</label><input type="search" id="clientSearchInput" placeholder="Nome, CPF/CNPJ, telefone ou cidade" autocomplete="off"></div>
    <div class="devolucao-client-results" id="clientSearchResults"></div>
  </div>
</div>

<datalist id="productOptions">
<?php foreach ($products as $p): $label = trim((string)($p['code'] ?? $p['id']) . ' · ' . (string)($p['nome'] ?? '') . ' · ' . (string)($p['barcode'] ?? '')); ?>
  <option value="<?= fiscal_h($label) ?>" data-id="<?= (int)$p['id'] ?>" data-price="<?= fiscal_h((string)($p['preco'] ?? 0)) ?>" data-cost="<?= fiscal_h((string)($p['custo'] ?? 0)) ?>" data-unit="<?= fiscal_h($p['unidade'] ?? 'UN') ?>"></option>
<?php endforeach; ?>
</datalist>

<div class="fiscal-modal" id="sourceDocsModal" role="dialog" aria-modal="true" style="display:none">
  <div class="fiscal-modal-dialog fiscal-modal-wide">
    <div class="fiscal-modal-head"><div><h2>Buscar nota ou pedido</h2><p class="fiscal-muted" style="margin:5px 0 0">Selecione notas autorizadas para devolução fiscal ou pedidos abertos/finalizados sem nota para devolução interna. Pedido sem nota não gera referência fiscal nem transmissão para a SEFAZ.</p></div><button type="button" class="fiscal-modal-close" id="closeSourceDocs" aria-label="Fechar">×</button></div>
    <p id="sourceDocsModeInfo" class="fiscal-muted" aria-live="polite" style="margin:0 0 10px"></p>
    <form method="get" class="fiscal-form" id="sourceDocsForm">
      <input type="hidden" name="operation_id" id="sourceDocsOperationId" value="<?= (int)$selectedOperationId ?>">
      <div class="devolucao-search-box"><label>Pesquisar nota ou pedido</label><input type="search" id="sourceDocSearch" placeholder="Número da nota, chave, pedido, cliente ou CPF/CNPJ" autocomplete="off"></div>
      <p class="fiscal-muted" id="sourceDocsEmpty" hidden role="status" style="padding:10px">Nenhum documento corresponde à pesquisa.</p>
      <div class="table-wrap" style="max-height:430px;overflow:auto"><table class="fiscal-table devolucao-modal-table"><thead><tr><th style="width:44px"></th><th>Origem</th><th>Cliente</th><th>Data</th><th>Total</th><th>Situação</th><th>Devolução</th></tr></thead><tbody>
        <?php if (!$recentSourceDocs && !$recentSourceOrders): ?><tr><td colspan="7" style="text-align:center;color:#64748b">Nenhuma nota autorizada ou pedido aberto/finalizado sem nota foi localizado.</td></tr><?php endif; ?>
        <?php foreach ($recentSourceDocs as $sd):
          $docId = (int)($sd['id'] ?? 0);
          $docText = (($sd['model'] ?? '') === '65' ? 'NFC-e' : 'NF-e') . ' Série ' . ($sd['series'] ?? '') . ' / Nº ' . ($sd['number'] ?? '');
          $devInfo = $recentReturnMap[$docId] ?? null;
          $devLabel = $devInfo ? (string)($devInfo['label'] ?? 'Devolução') : 'Sem devolução';
          $isFullyReturned = $devInfo && strtoupper((string)($devInfo['status'] ?? '')) === 'TOTAL';
          $searchBase = trim($docText . ' nota ' . ($sd['access_key'] ?? '') . ' ' . ($sd['dest_name'] ?? '') . ' ' . ($sd['dest_document'] ?? '') . ' ' . $devLabel);
          $searchText = function_exists('mb_strtolower') ? mb_strtolower($searchBase) : strtolower($searchBase);
          $checked = in_array($docId, $selectedSourceIds, true) && !$isFullyReturned;
        ?>
          <tr data-search="<?= fiscal_h($searchText) ?>" data-source-kind="nota">
            <td><input type="checkbox" name="source_doc_ids[]" value="<?= $docId ?>" <?= $checked ? 'checked' : '' ?> <?= $isFullyReturned ? 'disabled title="Nota já devolvida totalmente"' : '' ?>></td>
            <td data-label="Origem" class="primary"><strong><?= fiscal_h($docText) ?></strong><div class="fiscal-muted" style="font-size:12px"><?= fiscal_h($sd['access_key'] ?? '') ?></div></td>
            <td data-label="Cliente"><strong><?= fiscal_h($sd['dest_name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($sd['dest_document'] ?? '') ?></div></td>
            <td data-label="Data"><?= fiscal_h(fiscal_date_br(substr((string)($sd['issue_datetime'] ?? ''),0,10))) ?></td>
            <td data-label="Total">R$ <?= number_format((float)($sd['total_document'] ?? 0), 2, ',', '.') ?></td>
            <td data-label="Situação"><span class="fiscal-badge"><?= fiscal_h($sd['status'] ?? '') ?></span></td>
            <td data-label="Devolução"><?php if ($devInfo): ?><span class="fiscal-badge <?= $isFullyReturned ? '' : 'off' ?>" title="<?= fiscal_h('Devolvido R$ ' . number_format((float)($devInfo['returned_total'] ?? 0), 2, ',', '.') . ' de R$ ' . number_format((float)($devInfo['original_total'] ?? 0), 2, ',', '.')) ?>"><?= fiscal_h($devLabel) ?></span><?php else: ?><span class="fiscal-muted">Disponível</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
        <?php foreach ($recentSourceOrders as $so):
          $orderId = (int)($so['id'] ?? 0);
          $orderNo = (int)($so['numero_pedido'] ?? 0) ?: $orderId;
          $orderOriginalTotal = (float)($so['items_total'] ?? $so['total'] ?? 0);
          $orderReturnedTotal = (float)($recentOrderReturnMap[$orderId] ?? 0);
          $orderFullyReturned = $orderOriginalTotal > 0 && $orderReturnedTotal + 0.01 >= $orderOriginalTotal;
          $orderReturnLabel = $orderReturnedTotal <= 0 ? 'Disponível' : ($orderFullyReturned ? 'Total' : 'Parcial');
          $orderSearchBase = trim('pedido #' . $orderNo . ' pedido ' . ($so['nome_cliente'] ?? '') . ' ' . ($so['customer_document'] ?? '') . ' sem nota fiscal ' . ($so['forma_pagamento'] ?? '') . ' ' . $orderReturnLabel);
          $orderSearchText = function_exists('mb_strtolower') ? mb_strtolower($orderSearchBase) : strtolower($orderSearchBase);
          $orderChecked = $selectedSourceOrderId === $orderId && !$orderFullyReturned;
        ?>
          <tr data-search="<?= fiscal_h($orderSearchText) ?>" data-source-kind="pedido">
            <td><input type="radio" name="source_order_id" value="<?= $orderId ?>" data-label="Pedido #<?= $orderNo ?>" <?= $orderChecked ? 'checked' : '' ?> <?= $orderFullyReturned ? 'disabled title="Pedido já devolvido totalmente"' : '' ?>></td>
            <td data-label="Origem" class="primary"><strong>Pedido #<?= $orderNo ?></strong><div class="fiscal-muted" style="font-size:12px">Controle interno · sem nota fiscal</div></td>
            <td data-label="Cliente"><strong><?= fiscal_h($so['nome_cliente'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($so['customer_document'] ?? '') ?></div></td>
            <td data-label="Data"><?= fiscal_h(fiscal_date_br(substr((string)($so['created_at'] ?? ''),0,10))) ?></td>
            <td data-label="Total">R$ <?= number_format($orderOriginalTotal, 2, ',', '.') ?></td>
            <td data-label="Situação"><span class="fiscal-badge off">Sem nota fiscal</span><div class="fiscal-muted" style="font-size:11px"><?= fiscal_h(strtolower(trim((string)($so['status'] ?? ''))) === 'aberto' ? 'Pedido aberto' : 'Finalizado') ?></div></td>
            <td data-label="Devolução"><?php if ($orderReturnedTotal > 0): ?><span class="fiscal-badge <?= $orderFullyReturned ? '' : 'off' ?>" title="<?= fiscal_h('Devolvido R$ ' . number_format($orderReturnedTotal, 2, ',', '.') . ' de R$ ' . number_format($orderOriginalTotal, 2, ',', '.')) ?>"><?= fiscal_h($orderReturnLabel) ?></span><?php else: ?><span class="fiscal-muted">Disponível</span><?php endif; ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody></table></div>
      <div class="devolucao-modal-actions fiscal-form-actions"><span class="fiscal-muted" id="sourceDocsCount">Nenhuma origem selecionada</span><button type="button" class="btn" id="clearSourceDocs">Limpar seleção</button><button class="btn btn-primary">Carregar seleção</button></div>
    </form>
  </div>
</div>
<script>
(function(){
  const clients = <?= json_encode($clients, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?: '[]' ?>;
  const productOptions = Array.from(document.querySelectorAll('#productOptions option'));
  const movementForm = document.getElementById('movementForm');

  function fill(id, val){ const el=document.getElementById(id); if(el) el.value=val ?? ''; }
  function onlyDigits(v){ return String(v || '').replace(/\D+/g, ''); }
  function normalize(v){ return String(v || '').trim().toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g, ''); }
  function escapeHtml(v){ return String(v ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c])); }
  function parseDecimal(v){
    let text = String(v ?? '').trim().replace(/\s+/g, '');
    if(!text) return 0;
    if(text.includes(',')) text = text.replace(/\./g, '').replace(',', '.');
    text = text.replace(/[^0-9.-]/g, '');
    const n = Number(text);
    return Number.isFinite(n) ? n : 0;
  }
  function formatMoney(v){ return 'R$ ' + Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:2, maximumFractionDigits:2}); }
  function formatQty(v){ return Number(v || 0).toLocaleString('pt-BR', {minimumFractionDigits:0, maximumFractionDigits:3}); }
  function cleanAddress(address, district){
    let value = String(address || '').replace(/\s+/g, ' ').trim();
    const d = String(district || '').trim();
    if(d){
      const escaped = d.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
      value = value.replace(new RegExp('\\s*[,;\\-]?\\s*Bairro\\s*:\\s*' + escaped + '\\s*$', 'i'), '');
    }
    value = value.replace(/\s*[,;\-]?\s*Bairro\s*:\s*[^,;]+$/i, '');
    return value.replace(/^[,;\-\s]+|[,;\-\s]+$/g, '').trim();
  }

  // Pesquisa de cliente no mesmo padrão visual do Pedido PDV.
  const clientModal = document.getElementById('clientSearchModal');
  const clientSearchInput = document.getElementById('clientSearchInput');
  const clientResults = document.getElementById('clientSearchResults');
  function clientMatches(c, term){
    const q = normalize(term);
    const digits = onlyDigits(term);
    if(!q && !digits) return true;
    const text = normalize([c.name,c.fantasy_name,c.document,c.phone,c.whatsapp,c.email,c.city,c.state,c.district].join(' '));
    const joinedDigits = onlyDigits([c.document,c.phone,c.whatsapp].join(' '));
    return (q && text.includes(q)) || (digits && joinedDigits.includes(digits));
  }
  function renderClients(term=''){
    if(!clientResults) return;
    const rows = clients.filter(c => clientMatches(c, term)).slice(0, 100);
    if(!rows.length){ clientResults.innerHTML = '<div class="fiscal-empty">Nenhum cliente encontrado.</div>'; return; }
    clientResults.innerHTML = rows.map(c => {
      const contact = c.whatsapp || c.phone || '';
      const detail = [c.document ? 'CPF/CNPJ: ' + (window.nexaMaskDoc||String)(c.document) : '', contact ? 'Contato: ' + contact : '', [c.city || '', c.state || ''].filter(Boolean).join('/')].filter(Boolean).join(' • ');
      return `<button type="button" class="devolucao-client-result" data-client-id="${Number(c.id || 0)}"><span><strong>${escapeHtml(c.name || 'Cliente')}</strong><small>${escapeHtml(detail || 'Cliente cadastrado')}</small></span><b>Selecionar</b></button>`;
    }).join('');
  }
  function openClientModal(){
    if(!clientModal) return;
    clientModal.style.display='flex';
    if(clientSearchInput) clientSearchInput.value='';
    renderClients('');
    setTimeout(() => clientSearchInput?.focus(), 60);
  }
  function closeClientModal(){ if(clientModal) clientModal.style.display='none'; }
  function selectClient(c){
    if(!c) return;
    const district = c.district || '';
    fill('clientPick', (c.name || 'Cliente') + (c.document ? ' · ' + (window.nexaMaskDoc||String)(c.document) : ''));
    fill('person_id', c.id || '');
    fill('person_name', c.name || '');
    fill('person_document', c.document || '');
    fill('person_ie', c.state_registration || c.ie || '');
    fill('person_phone', c.whatsapp || c.phone || '');
    fill('person_email', c.email || '');
    fill('person_zip', c.zip_code || c.cep || '');
    fill('person_address', cleanAddress(c.address || '', district));
    fill('person_number', c.number || '');
    fill('person_complement', c.complement || '');
    fill('person_district', district);
    fill('person_city', c.city || '');
    fill('person_state', c.state || '');
    fill('person_city_ibge', c.city_ibge || '');
    closeClientModal();
  }
  document.getElementById('openClientSearch')?.addEventListener('click', openClientModal);
  document.getElementById('clientPick')?.addEventListener('click', openClientModal);
  document.getElementById('closeClientSearch')?.addEventListener('click', closeClientModal);
  clientModal?.addEventListener('click', e => { if(e.target === clientModal) closeClientModal(); });
  clientSearchInput?.addEventListener('input', () => renderClients(clientSearchInput.value));
  clientResults?.addEventListener('click', e => {
    const btn = e.target.closest('[data-client-id]');
    if(!btn) return;
    selectClient(clients.find(c => Number(c.id) === Number(btn.dataset.clientId)) || null);
  });

  function updateReturnTotals(){
    let selected = 0, qtyTotal = 0, valueTotal = 0;
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const qtyInput = tr.querySelector('.devolucao-qty');
      const qty = Math.max(0, parseDecimal(qtyInput?.value));
      const max = Math.max(0, parseDecimal(tr.querySelector('.max-quantity')?.value));
      const unit = Math.max(0, parseDecimal(tr.querySelector('.unit-value')?.value));
      const productId = Number(tr.querySelector('.product-id')?.value || 0);
      const invalid = max > 0 && qty > max + 0.0001;
      qtyInput?.classList.toggle('invalid', invalid);
      const total = qty * unit;
      const totalEl = tr.querySelector('.devolucao-line-total');
      if(totalEl) totalEl.textContent = formatMoney(total);
      if(qty > 0 && productId > 0){ selected++; qtyTotal += qty; valueTotal += total; }
    });
    const countEl = document.getElementById('returnSelectedCount');
    const qtyEl = document.getElementById('returnQuantityTotal');
    const valueEl = document.getElementById('returnValueTotal');
    if(countEl) countEl.textContent = String(selected);
    if(qtyEl) qtyEl.textContent = formatQty(qtyTotal);
    if(valueEl) valueEl.textContent = formatMoney(valueTotal);
  }

  const itemsTable = document.getElementById('itemsTable');
  const itemsBody = itemsTable?.querySelector('tbody');
  const addReturnItemBtn = document.getElementById('addReturnItem');

  function bindProductToRow(input){
    const op = productOptions.find(o => o.value === input.value);
    const tr = input.closest('tr');
    if(!tr) return;
    if(!op){
      const hidden = tr.querySelector('.product-id');
      if(hidden) hidden.value = '';
      updateReturnTotals();
      return;
    }
    const hidden = tr.querySelector('.product-id');
    if(hidden) hidden.value = op.dataset.id || '';
    const v = tr.querySelector('.unit-value');
    if(v && !v.value) v.value = String(op.dataset.price || op.dataset.cost || '0').replace('.', ',');
    if(tr.dataset.sourceRow !== '1'){
      const details = tr.querySelector('.devolucao-item-details');
      if(details) details.innerHTML = `<span>Un.: ${escapeHtml(op.dataset.unit || 'UN')}</span><span>A tributação será carregada do cadastro do produto.</span>`;
    }
    updateReturnTotals();
  }

  function addManualReturnRow(){
    if(!itemsBody) return;
    const tr = document.createElement('tr');
    tr.dataset.sourceRow = '0';
    tr.dataset.manualAdded = '1';
    tr.innerHTML = `
      <td data-label="Produto"><input list="productOptions" class="product-pick" value="" placeholder="Produto, código ou código de barras"><input type="hidden" name="product_id[]" class="product-id" value=""><input type="hidden" name="source_item_id[]" value=""><div class="devolucao-item-details"><span>A tributação será carregada do cadastro do produto.</span></div></td>
      <td data-label="Qtd. disponível"><span class="devolucao-origin-qty">—</span><input type="hidden" name="max_quantity[]" class="max-quantity" value=""></td>
      <td data-label="Qtd. devolução"><input name="quantity[]" class="devolucao-qty" inputmode="decimal" value=""></td>
      <td data-label="Valor unit."><input name="unit_value[]" class="unit-value" inputmode="decimal" value=""></td>
      <td data-label="Total"><span class="devolucao-line-total">R$ 0,00</span></td>
      <td data-label="CFOP"><input name="cfop[]" class="fiscal-cfop-input" maxlength="4" inputmode="numeric" value="" placeholder="operação"></td>
      <td data-label=""><button type="button" class="btn btn-sm clear-row">Remover</button></td>`;
    itemsBody.appendChild(tr);
    tr.querySelector('.product-pick')?.focus();
  }

  itemsTable?.addEventListener('change', function(e){
    if(e.target instanceof HTMLElement && e.target.classList.contains('product-pick')) bindProductToRow(e.target);
  });
  itemsTable?.addEventListener('input', function(e){
    if(e.target instanceof HTMLElement && (e.target.classList.contains('devolucao-qty') || e.target.classList.contains('unit-value'))) updateReturnTotals();
  });
  itemsTable?.addEventListener('click', function(e){
    const target = e.target instanceof Element ? e.target.closest('.clear-row') : null;
    if(!target) return;
    const tr = target.closest('tr');
    if(!tr) return;
    if(tr.dataset.sourceRow === '1'){
      const qty = tr.querySelector('.devolucao-qty'); if(qty) qty.value='';
    }else if(tr.dataset.manualAdded === '1'){
      tr.remove();
    }else{
      tr.querySelectorAll('input').forEach(i => { if(!i.classList.contains('max-quantity')) i.value=''; });
      const details = tr.querySelector('.devolucao-item-details');
      if(details) details.innerHTML = '<span>A tributação será carregada do cadastro do produto.</span>';
    }
    updateReturnTotals();
  });
  addReturnItemBtn?.addEventListener('click', addManualReturnRow);
  movementForm?.addEventListener('submit', function(e){
    let selected = 0, invalid = false, productMissing = false;
    document.querySelectorAll('#itemsTable tbody tr').forEach(tr => {
      const qty = Math.max(0, parseDecimal(tr.querySelector('.devolucao-qty')?.value));
      const max = Math.max(0, parseDecimal(tr.querySelector('.max-quantity')?.value));
      const productId = Number(tr.querySelector('.product-id')?.value || 0);
      if(qty > 0){ selected++; if(productId <= 0) productMissing = true; }
      if(max > 0 && qty > max + 0.0001) invalid = true;
    });
    if(invalid){ e.preventDefault(); alert('Há uma quantidade devolvida maior que a quantidade disponível na venda original.'); return; }
    if(productMissing){ e.preventDefault(); alert('Selecione o produto nas linhas com quantidade de devolução informada.'); return; }
    if(selected === 0){ e.preventDefault(); alert('Informe a quantidade em pelo menos um produto que será devolvido.'); }
  });
  updateReturnTotals();

  // Modal unificado de notas autorizadas e pedidos abertos/finalizados sem nota.
  const sourceModal = document.getElementById('sourceDocsModal');
  const openSourceBtn = document.getElementById('openSourceDocs');
  const openOrdersOnlyBtn = document.getElementById('openOrdersOnly');
  const sourceModeInfo = document.getElementById('sourceDocsModeInfo');
  const sourceEmpty = document.getElementById('sourceDocsEmpty');
  let onlyOrders = false;
  const closeSourceBtn = document.getElementById('closeSourceDocs');
  const sourceSearch = document.getElementById('sourceDocSearch');
  const sourceCount = document.getElementById('sourceDocsCount');
  const clearSourceBtn = document.getElementById('clearSourceDocs');
  const sourceChecks = Array.from(document.querySelectorAll('#sourceDocsForm input[type="checkbox"][name="source_doc_ids[]"]:not(:disabled)'));
  const sourceOrders = Array.from(document.querySelectorAll('#sourceDocsForm input[type="radio"][name="source_order_id"]:not(:disabled)'));
  function updateSourceCount(){
    if(!sourceCount) return;
    const selectedOrder = sourceOrders.find(r => r.checked);
    if(selectedOrder){ sourceCount.textContent = (selectedOrder.dataset.label || 'Pedido') + ' selecionado para devolução interna'; return; }
    const notes = sourceChecks.filter(c => c.checked).length;
    sourceCount.textContent = notes > 0 ? notes + ' nota(s) selecionada(s)' : 'Nenhuma origem selecionada';
  }
  function filterSourceRows(){
    const q = normalize(sourceSearch?.value || '');
    let visible = 0;
    document.querySelectorAll('.devolucao-modal-table tbody tr[data-search]').forEach(tr => {
      const match = (!onlyOrders || tr.dataset.sourceKind === 'pedido') && (q === '' || normalize(tr.dataset.search || '').includes(q));
      tr.hidden = !match;
      if(match) visible++;
    });
    if(sourceEmpty){
      sourceEmpty.hidden = visible > 0;
      sourceEmpty.textContent = onlyOrders
        ? 'Nenhum pedido aberto/finalizado e sem nota fiscal encontrado. Confira a empresa selecionada e o número do pedido.'
        : 'Nenhuma nota ou pedido corresponde à pesquisa.';
    }
  }
  function resetSourceSearch(){
    if(sourceSearch) sourceSearch.value = '';
    filterSourceRows();
  }
  function openSourceModal(orderMode = false){
    onlyOrders = orderMode === true;
    if(onlyOrders) sourceChecks.forEach(c => c.checked = false);
    if(sourceModeInfo) sourceModeInfo.textContent = onlyOrders
      ? 'Somente pedidos sem NF-e/NFC-e • devolução interna de estoque e dinheiro/vale, sem transmissão à SEFAZ.'
      : 'Notas autorizadas para devolução fiscal e pedidos sem nota para devolução interna.';
    const opSel = document.getElementById('operation_id');
    const opHidden = document.getElementById('sourceDocsOperationId');
    if(opSel && opHidden) opHidden.value = opSel.value || '';
    if(!sourceModal) return;
    resetSourceSearch();
    sourceModal.style.display='flex';
    setTimeout(() => sourceSearch?.focus(), 50);
    updateSourceCount();
  }
  function closeSourceModal(){ if(sourceModal) sourceModal.style.display='none'; }
  openSourceBtn?.addEventListener('click', () => openSourceModal(false));
  openOrdersOnlyBtn?.addEventListener('click', () => openSourceModal(true));
  closeSourceBtn?.addEventListener('click', closeSourceModal);
  sourceModal?.addEventListener('click', e => { if(e.target === sourceModal) closeSourceModal(); });
  sourceChecks.forEach(c => c.addEventListener('change', function(){
    if(this.checked) sourceOrders.forEach(r => r.checked = false);
    updateSourceCount();
  }));
  sourceOrders.forEach(r => r.addEventListener('change', function(){
    if(this.checked) sourceChecks.forEach(c => c.checked = false);
    updateSourceCount();
  }));
  clearSourceBtn?.addEventListener('click', () => {
    sourceChecks.forEach(c => c.checked=false);
    sourceOrders.forEach(r => r.checked=false);
    updateSourceCount();
  });
  sourceSearch?.addEventListener('input', filterSourceRows);
  document.addEventListener('keydown', e => {
    if(e.key === 'F8'){
      if(sourceModal && sourceModal.style.display === 'none'){ e.preventDefault(); openSourceModal(); }
      return;
    }
    if(e.key !== 'Escape') return;
    if(clientModal && clientModal.style.display !== 'none') closeClientModal();
    if(sourceModal && sourceModal.style.display !== 'none') closeSourceModal();
  });
  updateSourceCount();
  <?php if (strtolower(trim((string)($_GET['origem'] ?? ''))) === 'pedido' && !$sourceOrder && !$sourceDocs): ?>
  openSourceModal(true);
  <?php endif; ?>
})();
</script>

<style>
.fiscal-cfop-input{min-width:112px;width:100%;text-align:center;font-weight:800;letter-spacing:.04em}
</style>
<?php fiscal_admin_end(); ?>
