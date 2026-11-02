<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
$docId = (int)($_GET['doc_id'] ?? $_GET['id'] ?? $_POST['doc_id'] ?? $_POST['id'] ?? 0);
if ($docId <= 0) { header('Location: fiscal_documentos.php'); exit; }
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''));
// Somente destinos locais conhecidos. Evita open redirect pela URL de retorno.
if ($returnTo !== '' && !preg_match('~^fiscal_(?:lote_contingencia|documentos)\.php(?:\?.*)?$~', $returnTo)) $returnTo = '';
$fromBatch = str_starts_with($returnTo, 'fiscal_lote_contingencia.php');
$csrfToken = function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';

function nfce_edit_decimal($value): float {
  $s = trim((string)$value);
  if ($s === '') return 0.0;
  if (str_contains($s, ',')) $s = str_replace('.', '', $s);
  $s = str_replace(',', '.', $s);
  $s = preg_replace('/[^0-9.\-]/', '', $s) ?: '0';
  return (float)$s;
}
function nfce_edit_fmt($v, int $dec = 2): string { return number_format((float)$v, $dec, '.', ''); }
function nfce_edit_digits($value, int $max = 0): string {
  $s = preg_replace('/\D+/', '', (string)$value) ?: '';
  return $max > 0 ? substr($s, 0, $max) : $s;
}
function nfce_edit_tax_for(array $taxes, int $itemId, string $group): array {
  foreach (($taxes[$itemId] ?? []) as $tx) if (strtoupper((string)($tx['tax_group'] ?? '')) === strtoupper($group)) return $tx;
  return [];
}
function nfce_edit_pis_tag(string $cst): string {
  $cst = str_pad(nfce_edit_digits($cst, 2) ?: '01', 2, '0', STR_PAD_LEFT);
  if (in_array($cst, ['01','02'], true)) return 'PISAliq';
  if (in_array($cst, ['04','06','07','08','09'], true)) return 'PISNT';
  return 'PISOutr';
}
function nfce_edit_cofins_tag(string $cst): string {
  $cst = str_pad(nfce_edit_digits($cst, 2) ?: '01', 2, '0', STR_PAD_LEFT);
  if (in_array($cst, ['01','02'], true)) return 'COFINSAliq';
  if (in_array($cst, ['04','06','07','08','09'], true)) return 'COFINSNT';
  return 'COFINSOutr';
}
function nfce_edit_upsert_tax(PDO $pdo, int $companyId, int $docId, int $itemId, string $group, string $taxTag, string $origin, string $cst, string $csosn, float $base, float $rate, float $amount, ?string $extraJson = null): void {
  $st = $pdo->prepare('SELECT id FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? AND fiscal_document_item_id=? AND tax_group=? ORDER BY id ASC LIMIT 1');
  $st->execute([$companyId, $docId, $itemId, $group]);
  $id = (int)($st->fetchColumn() ?: 0);
  if ($id > 0) {
    $pdo->prepare('UPDATE fiscal_document_item_taxes SET tax_tag=?, origin_code=?, cst=?, csosn=?, base_calc=?, rate=?, amount=?, extra_json=? WHERE company_id=? AND id=?')
      ->execute([$taxTag, $origin, $cst, $csosn, $base, $rate, $amount, $extraJson, $companyId, $id]);
  } else {
    $pdo->prepare('INSERT INTO fiscal_document_item_taxes (fiscal_document_item_id, fiscal_document_id, company_id, tax_group, tax_tag, origin_code, cst, csosn, base_calc, rate, amount, extra_json, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
      ->execute([$itemId, $docId, $companyId, $group, $taxTag, $origin, $cst, $csosn, $base, $rate, $amount, $extraJson]);
  }
}
function nfce_edit_delete_tax(PDO $pdo, int $companyId, int $docId, int $itemId, string $group): void {
  $pdo->prepare('DELETE FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id=? AND fiscal_document_item_id=? AND tax_group=?')
    ->execute([$companyId, $docId, $itemId, $group]);
}
function nfce_edit_tax_extra(array $tax): array {
  $raw = trim((string)($tax['extra_json'] ?? ''));
  if ($raw === '') return [];
  $decoded = json_decode($raw, true);
  return is_array($decoded) ? $decoded : [];
}
$err = '';
try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }
$doc = fiscal_nfce_document_by_id($pdo, $cid, $docId);
if (!$doc) { http_response_code(404); echo 'Documento fiscal não encontrado.'; exit; }
$docModel = ((string)($doc['model'] ?? '65') === '55') ? '55' : '65';
$docLabel = $docModel === '55' ? 'NF-e' : 'NFC-e';
$docPreviewStatus = $docModel === '55' ? 'PRE_NFE' : 'PRE_NFCE';
$pedidoId = (($doc['source_type'] ?? '') === 'PEDIDO') ? (int)($doc['source_id'] ?? 0) : 0;
$originalStatus = strtoupper(trim((string)($doc['status'] ?? '')));
$batchStatuses = ['REJEITADA','CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA'];
$fromBatch = $fromBatch && in_array($originalStatus, $batchStatuses, true);
$locked = in_array($originalStatus, ['AUTORIZADA','CANCELADA'], true);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($locked) throw new RuntimeException('Documento autorizado/cancelado não pode ser alterado. Faça ajustes apenas antes da autorização.');
    if ($csrfToken !== '' && function_exists('multi_company_context_csrf_valid') && !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
      throw new RuntimeException('Sessão de segurança expirada. Atualize a página e tente novamente.');
    }

    $defaultOperation = $docModel === '55' ? 'VENDA DE MERCADORIA NF-E' : 'VENDA AO CONSUMIDOR NFC-E';
    $operation = trim((string)($_POST['operation_nature'] ?? $defaultOperation)) ?: $defaultOperation;
    $issueInput = trim((string)($_POST['issue_datetime'] ?? ''));
    // Na fila de contingência preserva dhEmi original. Alterar a data/hora aqui
    // descaracterizaria o documento offline que já foi entregue ao consumidor.
    $issue = trim((string)($doc['issue_datetime'] ?? '')) ?: date('Y-m-d H:i:s');
    if (!$fromBatch && $issueInput !== '') {
      $ts = strtotime($issueInput);
      if ($ts) $issue = date('Y-m-d H:i:s', $ts);
    }

    $pdo->beginTransaction();

    if ($docModel === '55' && function_exists('fiscal_save_document_references')) {
      $postedRefs = fiscal_reference_keys_from_text($_POST['reference_keys'] ?? '');
      $srcType = strtoupper((string)($doc['source_type'] ?? ''));
      $natureForRef = strtoupper($operation);
      if (!$postedRefs && (str_contains($srcType, 'DEVOLUCAO') || str_contains($natureForRef, 'DEVOLUCAO'))) {
        throw new RuntimeException('Informe pelo menos uma chave de NF-e/NFC-e referenciada para a devolução.');
      }
      fiscal_save_document_references($pdo, $cid, $docId, $postedRefs);
    }

    $pdo->prepare('DELETE FROM fiscal_document_participants WHERE company_id=? AND fiscal_document_id=? AND role=?')
      ->execute([$cid, $docId, 'DESTINATARIO']);
    $pdo->prepare('INSERT INTO fiscal_document_participants (fiscal_document_id, company_id, role, document, state_registration, name, phone, zip_code, address, number, complement, district, city, city_ibge, state, country_code, country_name, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())')
      ->execute([
        $docId, $cid, 'DESTINATARIO', nfce_edit_digits($_POST['dest_document'] ?? '', 14), '', trim((string)($_POST['dest_name'] ?? 'CONSUMIDOR FINAL')) ?: 'CONSUMIDOR FINAL',
        trim((string)($_POST['dest_phone'] ?? '')), nfce_edit_digits($_POST['dest_zip_code'] ?? '', 8), trim((string)($_POST['dest_address'] ?? '')), trim((string)($_POST['dest_number'] ?? '')),
        trim((string)($_POST['dest_complement'] ?? '')), trim((string)($_POST['dest_district'] ?? '')), trim((string)($_POST['dest_city'] ?? '')), nfce_edit_digits($_POST['dest_city_ibge'] ?? '', 7), strtoupper(substr(trim((string)($_POST['dest_state'] ?? '')), 0, 2)), '1058', 'BRASIL'
      ]);

    $itemIds = $_POST['item_id'] ?? [];
    $totalProducts = 0.0; $totalDiscount = 0.0; $totalFreight = 0.0; $totalOther = 0.0;
    $totalIcms = 0.0; $totalIcmsSt = 0.0; $totalFcpSt = 0.0; $totalPis = 0.0; $totalCofins = 0.0; $totalIbsUf = 0.0; $totalIbsMun = 0.0; $totalCbs = 0.0;

    foreach ($itemIds as $rawItemId) {
      $itemId = (int)$rawItemId;
      if ($itemId <= 0) continue;
      $description = trim((string)($_POST['description'][$itemId] ?? 'Produto')) ?: 'Produto';
      $productCode = trim((string)($_POST['product_code'][$itemId] ?? ''));
      $ncm = nfce_edit_digits($_POST['ncm'][$itemId] ?? '', 8);
      $cest = nfce_edit_digits($_POST['cest'][$itemId] ?? '', 7);
      $cfop = nfce_edit_digits($_POST['cfop'][$itemId] ?? '5102', 4) ?: '5102';
      // NF-e posterior/vinculada a NFC-e não deve manter CFOP 5.929/6.929,
      // pois estes CFOPs exigem referência própria de Cupom Fiscal/ECF e causam
      // rejeição 701 quando a origem é NFC-e modelo 65.
      if ($docModel === '55' && str_contains(strtoupper((string)($doc['source_type'] ?? '')), 'NFCE_REFERENCIADA') && in_array($cfop, ['5929','6929'], true)) {
        $cfgRef = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $cid) : [];
        $destUfRef = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($_POST['dest_state'] ?? '')), 0, 2));
        $cfop = function_exists('fiscal_nfe_after_nfce_cfop') ? fiscal_nfe_after_nfce_cfop(['uf' => $destUfRef, 'state' => $destUfRef], $cfgRef) : ($cfop === '6929' ? '6949' : '5949');
      }
      $unit = strtoupper(substr(trim((string)($_POST['unidade'][$itemId] ?? 'UN')) ?: 'UN', 0, 6));
      $qty = max(0.000001, nfce_edit_decimal($_POST['quantity'][$itemId] ?? 0));
      $unitValue = max(0.0, nfce_edit_decimal($_POST['unit_value'][$itemId] ?? 0));
      $totalValue = max(0.0, nfce_edit_decimal($_POST['total_value'][$itemId] ?? ($qty * $unitValue)));
      $discount = max(0.0, nfce_edit_decimal($_POST['discount_value'][$itemId] ?? 0));
      // NFC-e: qualquer valor preenchido como frete é convertido para acréscimo/outras despesas.
      // O XML modelo 65 não receberá vFrete nem dados de transporte/transportador.
      $freightTyped = max(0.0, nfce_edit_decimal($_POST['freight_value'][$itemId] ?? 0));
      $freight = 0.0;
      $other = max(0.0, nfce_edit_decimal($_POST['other_value'][$itemId] ?? 0)) + $freightTyped;

      $pdo->prepare('UPDATE fiscal_document_items SET product_code=?, description=?, ncm=?, cest=?, cfop=?, unidade=?, quantity=?, unit_value=?, total_value=?, unidade_trib=?, quantity_trib=?, unit_value_trib=?, discount_value=?, freight_value=?, other_value=?, updated_at=NOW() WHERE company_id=? AND fiscal_document_id=? AND id=?')
        ->execute([$productCode, $description, $ncm, $cest, $cfop, $unit, $qty, $unitValue, $totalValue, $unit, $qty, $unitValue, $discount, $freight, $other, $cid, $docId, $itemId]);

      $taxBaseDefault = max(0.0, round($totalValue + $other - $discount, 2));
      $origin = nfce_edit_digits($_POST['icms_origin'][$itemId] ?? '0', 1) ?: '0';
      $icmsCst = str_pad(nfce_edit_digits($_POST['icms_cst'][$itemId] ?? '', 2), 2, '0', STR_PAD_LEFT);
      $icmsCsosn = nfce_edit_digits($_POST['icms_csosn'][$itemId] ?? '', 3);
      $icmsBase = nfce_edit_decimal($_POST['icms_base'][$itemId] ?? $taxBaseDefault);
      $icmsRate = nfce_edit_decimal($_POST['icms_rate'][$itemId] ?? 0);
      $icmsAmount = nfce_edit_decimal($_POST['icms_amount'][$itemId] ?? 0);
      $icmsStModBc = nfce_edit_digits($_POST['icms_st_modbc'][$itemId] ?? '4', 1) ?: '4';
      $icmsStMva = max(0.0, nfce_edit_decimal($_POST['icms_st_mva'][$itemId] ?? 0));
      $icmsStRed = max(0.0, nfce_edit_decimal($_POST['icms_st_redbc'][$itemId] ?? 0));
      $icmsStBase = max(0.0, nfce_edit_decimal($_POST['icms_st_base'][$itemId] ?? 0));
      $icmsStRate = max(0.0, nfce_edit_decimal($_POST['icms_st_rate'][$itemId] ?? 0));
      $icmsStAmount = max(0.0, nfce_edit_decimal($_POST['icms_st_amount'][$itemId] ?? 0));
      $fcpStBase = max(0.0, nfce_edit_decimal($_POST['fcp_st_base'][$itemId] ?? 0));
      $fcpStRate = max(0.0, nfce_edit_decimal($_POST['fcp_st_rate'][$itemId] ?? 0));
      $fcpStAmount = max(0.0, nfce_edit_decimal($_POST['fcp_st_amount'][$itemId] ?? 0));
      $hasIcmsSt = $icmsStBase > 0 || $icmsStRate > 0 || $icmsStAmount > 0 || $icmsStMva > 0 || $icmsStRed > 0 || $fcpStBase > 0 || $fcpStRate > 0 || $fcpStAmount > 0;
      $icmsExtraJson = $hasIcmsSt ? json_encode([
        'st_modbc' => $icmsStModBc,
        'st_mva' => $icmsStMva,
        'st_reduction_rate' => $icmsStRed,
        'st_base' => $icmsStBase,
        'st_rate' => $icmsStRate,
        'st_amount' => $icmsStAmount,
        'fcp_st_base' => $fcpStBase,
        'fcp_st_rate' => $fcpStRate,
        'fcp_st_amount' => $fcpStAmount,
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;
      $icmsTag = function_exists('fiscal_nfce_icms_xml_tag') ? fiscal_nfce_icms_xml_tag($icmsCsosn !== '' ? '1' : '3', $icmsCst, $icmsCsosn) : ($icmsCsosn !== '' ? ('ICMSSN' . $icmsCsosn) : ('ICMS' . $icmsCst));
      nfce_edit_upsert_tax($pdo, $cid, $docId, $itemId, 'ICMS', $icmsTag, $origin, $icmsCsosn !== '' ? '' : $icmsCst, $icmsCsosn, $icmsBase, $icmsRate, $icmsAmount, $icmsExtraJson);

      $pisCst = str_pad(nfce_edit_digits($_POST['pis_cst'][$itemId] ?? '01', 2) ?: '01', 2, '0', STR_PAD_LEFT);
      $pisBase = max(0.0, nfce_edit_decimal($_POST['pis_base'][$itemId] ?? $taxBaseDefault));
      $pisRate = nfce_edit_decimal($_POST['pis_rate'][$itemId] ?? 0);
      $pisAmount = nfce_edit_decimal($_POST['pis_amount'][$itemId] ?? 0);
      nfce_edit_upsert_tax($pdo, $cid, $docId, $itemId, 'PIS', nfce_edit_pis_tag($pisCst), '', $pisCst, '', $pisBase, $pisRate, $pisAmount);

      $cofinsCst = str_pad(nfce_edit_digits($_POST['cofins_cst'][$itemId] ?? '01', 2) ?: '01', 2, '0', STR_PAD_LEFT);
      $cofinsBase = max(0.0, nfce_edit_decimal($_POST['cofins_base'][$itemId] ?? $taxBaseDefault));
      $cofinsRate = nfce_edit_decimal($_POST['cofins_rate'][$itemId] ?? 0);
      $cofinsAmount = nfce_edit_decimal($_POST['cofins_amount'][$itemId] ?? 0);
      nfce_edit_upsert_tax($pdo, $cid, $docId, $itemId, 'COFINS', nfce_edit_cofins_tag($cofinsCst), '', $cofinsCst, '', $cofinsBase, $cofinsRate, $cofinsAmount);

      $ibsCbsCstRaw = nfce_edit_digits($_POST['ibscbs_cst'][$itemId] ?? '', 3);
      $ibsCbsCst = $ibsCbsCstRaw !== '' ? str_pad($ibsCbsCstRaw, 3, '0', STR_PAD_LEFT) : '000';
      $ibsCbsClass = nfce_edit_digits($_POST['ibscbs_class'][$itemId] ?? '', 6);
      $ibsCbsBase = max(0.0, nfce_edit_decimal($_POST['ibscbs_base'][$itemId] ?? $taxBaseDefault));
      $ibsUfRate = max(0.0, nfce_edit_decimal($_POST['ibs_uf_rate'][$itemId] ?? 0));
      $ibsUfAmount = max(0.0, nfce_edit_decimal($_POST['ibs_uf_amount'][$itemId] ?? 0));
      $ibsMunRate = max(0.0, nfce_edit_decimal($_POST['ibs_mun_rate'][$itemId] ?? 0));
      $ibsMunAmount = max(0.0, nfce_edit_decimal($_POST['ibs_mun_amount'][$itemId] ?? 0));
      $ibsAmountTyped = max(0.0, nfce_edit_decimal($_POST['ibs_amount'][$itemId] ?? 0));
      $ibsAmount = $ibsAmountTyped > 0 ? $ibsAmountTyped : round($ibsUfAmount + $ibsMunAmount, 2);
      $cbsRate = max(0.0, nfce_edit_decimal($_POST['cbs_rate'][$itemId] ?? 0));
      $cbsAmount = max(0.0, nfce_edit_decimal($_POST['cbs_amount'][$itemId] ?? 0));
      $hasIbsCbs = $ibsCbsClass !== '' || $ibsCbsBase > 0 || $ibsUfRate > 0 || $ibsUfAmount > 0 || $ibsMunRate > 0 || $ibsMunAmount > 0 || $ibsAmount > 0 || $cbsRate > 0 || $cbsAmount > 0;
      if ($hasIbsCbs) {
        $ibscbsExtra = json_encode([
          'cClassTrib' => $ibsCbsClass,
          'CST' => $ibsCbsCst,
          'base_calc' => $ibsCbsBase,
          'ibs_uf_rate' => $ibsUfRate,
          'ibs_uf_amount' => $ibsUfAmount,
          'ibs_mun_rate' => $ibsMunRate,
          'ibs_mun_amount' => $ibsMunAmount,
          'ibs_amount' => $ibsAmount,
          'cbs_rate' => $cbsRate,
          'cbs_amount' => $cbsAmount,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        nfce_edit_upsert_tax($pdo, $cid, $docId, $itemId, 'IBSCBS', 'IBSCBS', '', $ibsCbsCst, '', $ibsCbsBase, ($ibsUfRate + $ibsMunRate + $cbsRate), ($ibsAmount + $cbsAmount), $ibscbsExtra);
        $totalIbsUf += $ibsUfAmount;
        $totalIbsMun += $ibsMunAmount;
        $totalCbs += $cbsAmount;
      } else {
        nfce_edit_delete_tax($pdo, $cid, $docId, $itemId, 'IBSCBS');
      }

      $totalProducts += $totalValue; $totalDiscount += $discount; $totalOther += $other;
      $totalIcms += $icmsAmount; $totalIcmsSt += $icmsStAmount; $totalFcpSt += $fcpStAmount; $totalPis += $pisAmount; $totalCofins += $cofinsAmount;
    }

    $totalFreight = 0.0;
    $totalDocument = round($totalProducts + $totalOther + $totalIcmsSt + $totalFcpSt - $totalDiscount, 2);
    $payIds = $_POST['payment_id'] ?? [];
    $totalPay = 0.0;
    foreach ($payIds as $idx => $rawPayId) {
      $payId = (int)$rawPayId;
      if ($payId <= 0) continue;
      $amount = max(0.0, nfce_edit_decimal($_POST['payment_amount'][$payId] ?? 0));
      $totalPay += $amount;
      $pdo->prepare('UPDATE fiscal_document_payments SET payment_type=?, payment_description=?, payment_indicator=?, amount=?, card_brand=?, card_integration=?, card_cnpj=?, authorization_code=? WHERE company_id=? AND fiscal_document_id=? AND id=?')
        ->execute([
          str_pad(nfce_edit_digits($_POST['payment_type'][$payId] ?? '99', 2) ?: '99', 2, '0', STR_PAD_LEFT),
          trim((string)($_POST['payment_description'][$payId] ?? 'OUTROS')) ?: 'OUTROS',
          in_array((string)($_POST['payment_indicator'][$payId] ?? '0'), ['0','1'], true) ? (string)$_POST['payment_indicator'][$payId] : '0',
          $amount,
          nfce_edit_digits($_POST['card_brand'][$payId] ?? '', 2),
          in_array((string)($_POST['card_integration'][$payId] ?? ''), ['1','2'], true) ? (string)$_POST['card_integration'][$payId] : null,
          nfce_edit_digits($_POST['card_cnpj'][$payId] ?? '', 14),
          trim((string)($_POST['authorization_code'][$payId] ?? '')),
          $cid, $docId, $payId
        ]);
    }
    if ($payIds && round($totalPay, 2) <= 0) {
      $firstPay = (int)($payIds[0] ?? 0);
      if ($firstPay > 0) $pdo->prepare('UPDATE fiscal_document_payments SET amount=? WHERE company_id=? AND fiscal_document_id=? AND id=?')->execute([$totalDocument, $cid, $docId, $firstPay]);
    }

    $statusAfterEdit = $fromBatch ? $originalStatus : $docPreviewStatus;
    $pdo->prepare("UPDATE fiscal_documents SET operation_nature=?, issue_datetime=?, exit_entry_datetime=?, total_products=?, total_discount=?, total_freight=?, total_other=?, total_icms=?, total_icms_st=?, total_fcp=?, total_pis=?, total_cofins=?, total_ibs_uf=?, total_ibs_mun=?, total_cbs=?, total_document=?, status=?, sefaz_status_code=NULL, sefaz_status_message=NULL, protocol_number=NULL, updated_at=NOW() WHERE company_id=? AND id=?")
      ->execute([$operation, $issue, $issue, round($totalProducts,2), round($totalDiscount,2), round($totalFreight,2), round($totalOther,2), round($totalIcms,2), round($totalIcmsSt,2), round($totalFcpSt,2), round($totalPis,2), round($totalCofins,2), round($totalIbsUf,2), round($totalIbsMun,2), round($totalCbs,2), $totalDocument, $statusAfterEdit, $cid, $docId]);

    $xml = fiscal_nfce_build_draft_xml($pdo, $cid, $docId);
    $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')
      ->execute([$xml, hash('sha256', $xml), $cid, $docId]);
    $pdo->prepare('INSERT INTO fiscal_document_events (fiscal_document_id, company_id, event_type, event_sequence, event_datetime, status_code, status_message, request_xml, notes, created_at) VALUES (?,?,?,?,NOW(),?,?,?,?,NOW())')
      ->execute([$docId, $cid, $docModel === '55' ? 'EDICAO_NFE' : 'EDICAO_NFCE', 1, 'EDIT', 'Documento fiscal corrigido manualmente antes do reenvio.', $xml, 'Dados fiscais ajustados na tela de edição da ' . $docLabel . '.']);

    if ($pdo->inTransaction()) $pdo->commit();

    if ($fromBatch) {
      $sep = str_contains($returnTo, '?') ? '&' : '?';
      header('Location: ' . $returnTo . $sep . 'ok=' . rawurlencode('Documento fiscal corrigido e XML regenerado. Selecione-o novamente para transmitir.'));
      exit;
    }

    $sourceTypeSaved = strtoupper((string)($doc['source_type'] ?? ''));
    $returnToDevolucoes = ($docModel === '55')
      && (str_contains($sourceTypeSaved, 'DEVOLUCAO')
        || str_contains(strtoupper((string)$operation), 'DEVOLUCAO'));
    $returnToDocs = ($docModel === '55') && str_contains($sourceTypeSaved, 'NFCE_REFERENCIADA');

    if ($returnToDevolucoes) {
      header('Location: fiscal_devolucoes.php?ok=' . rawurlencode('Dados da devolução salvos e XML regenerado. Agora clique em Gerar nota de devolução.'));
    } elseif ($returnToDocs) {
      header('Location: fiscal_documentos.php?ok=' . rawurlencode('Pré-NF-e referenciada conferida e XML regenerado. Agora clique em Reenviar NF-e.'));
    } else {
      header('Location: fiscal_nfce_editar.php?doc_id=' . $docId . '&ok=' . rawurlencode('Documento fiscal corrigido e XML atual regenerado. Agora você pode reenviar a ' . $docLabel . '.'));
    }
    exit;
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $err = $e->getMessage();
}

$doc = fiscal_nfce_document_by_id($pdo, $cid, $docId) ?: $doc;
$docModel = ((string)($doc['model'] ?? '65') === '55') ? '55' : '65';
$docLabel = $docModel === '55' ? 'NF-e' : 'NFC-e';
$docPreviewStatus = $docModel === '55' ? 'PRE_NFE' : 'PRE_NFCE';
$recipient = [];
try { $recipient = fiscal_nfce_document_participant($pdo, $cid, $docId, 'DESTINATARIO'); } catch (Throwable $e) { $recipient = []; }
$items = fiscal_nfce_document_items($pdo, $cid, $docId);
$taxes = fiscal_nfce_document_taxes($pdo, $cid, $docId);
$payments = fiscal_nfce_document_payments($pdo, $cid, $docId);
$referenceRows = [];
$referenceText = '';
try {
  if ($docModel === '55' && function_exists('fiscal_document_reference_rows')) {
    $referenceRows = fiscal_document_reference_rows($pdo, $cid, $docId);
    $referenceText = implode("\n", array_map(static fn($r) => (string)($r['reference_key'] ?? ''), $referenceRows));
  }
} catch (Throwable $e) { $referenceRows = []; $referenceText = ''; }
$issueLocal = '';
if (!empty($doc['issue_datetime'])) {
  $ts = strtotime((string)$doc['issue_datetime']);
  if ($ts) $issueLocal = date('Y-m-d\TH:i', $ts);
}

fiscal_admin_start('Editar ' . $docLabel . ' #' . $docId, 'fiscal_nfce');
fiscal_page_header('Editar dados da ' . $docLabel, 'Corrija dados do destinatário, produtos e tributação antes de consultar, recuperar ou reenviar a ' . $docLabel . '.', [
  ['label'=>'Voltar', 'href'=>$fromBatch ? $returnTo : ($pedidoId > 0 ? ('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId) : 'fiscal_documentos.php'), 'class'=>'btn'],
  ['label'=>'Documentos fiscais', 'href'=>'fiscal_documentos.php', 'class'=>'btn'],
]);
fiscal_tabs('fiscal_documentos');
fiscal_flash();
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>

<style>
  .nfce-edit-note{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:18px;padding:12px 14px;margin-bottom:14px;font-weight:800;line-height:1.45}
  .nfce-edit-locked{border-color:#fecaca;background:#fff7ed;color:#9a3412}
  .nfce-edit-table{min-width:860px}.nfce-edit-table input,.nfce-edit-table select{min-height:36px;border:1px solid #dbe4f0;border-radius:10px;padding:7px 8px;width:100%;box-sizing:border-box;font:inherit}.nfce-edit-table .small{max-width:86px}.nfce-edit-table .money{max-width:115px}.nfce-edit-table .desc{min-width:260px}
  .nfce-product-table{min-width:860px;table-layout:fixed}.nfce-product-table th:nth-child(1){width:13%}.nfce-product-table th:nth-child(2){width:39%}.nfce-product-table th:nth-child(3){width:12%}.nfce-product-table th:nth-child(4){width:13%}.nfce-product-table th:nth-child(5){width:13%}.nfce-product-table th:nth-child(6){width:10%}
  .nfce-product-code{max-width:120px}.nfce-detail-button{min-width:86px;justify-content:center}.nfce-edit-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;flex-wrap:wrap;margin-top:14px;border-top:1px solid #eef2f7;padding-top:14px}
  .nfce-detail-modal{position:fixed;inset:0;z-index:9999;display:none;align-items:flex-start;justify-content:center;background:rgba(15,23,42,.54);padding:26px 12px;overflow:auto}.nfce-detail-modal.active{display:flex}.nfce-detail-card{width:min(1120px,100%);background:#fff;border-radius:22px;box-shadow:0 22px 60px rgba(15,23,42,.22);border:1px solid #e2e8f0;overflow:hidden}.nfce-detail-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;padding:16px 18px;border-bottom:1px solid #e5edf6;background:#f8fbff}.nfce-detail-head h3{margin:0;color:#0f255f;font-size:18px}.nfce-detail-head p{margin:4px 0 0;color:#64748b;font-weight:700}.nfce-detail-close{border:1px solid #dbe4f0;background:#fff;border-radius:12px;min-width:40px;min-height:36px;font-size:22px;line-height:1;cursor:pointer}.nfce-detail-body{padding:16px 18px}.nfce-detail-section{border:1px solid #e5edf6;border-radius:18px;padding:13px;margin-bottom:14px;background:#fff}.nfce-detail-section h4{margin:0 0 10px;color:#0f172a;font-size:14px;font-weight:950}.nfce-modal-grid{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:10px}.nfce-modal-grid label{font-size:12px;font-weight:900;color:#334155}.nfce-modal-grid input,.nfce-modal-grid select{margin-top:4px;min-height:38px;border:1px solid #dbe4f0;border-radius:11px;padding:8px 9px;width:100%;box-sizing:border-box;font:inherit;color:#0f172a}.nfce-detail-foot{display:flex;justify-content:flex-end;gap:10px;padding:14px 18px;border-top:1px solid #e5edf6;background:#f8fbff}.nfce-tax-hint{font-size:12px;color:#64748b;font-weight:750;line-height:1.4;margin-top:-2px;margin-bottom:10px}
  @media(max-width:760px){.nfce-product-table{min-width:0}.nfce-product-table thead{display:none}.nfce-product-table,.nfce-product-table tbody,.nfce-product-table tr,.nfce-product-table td{display:block;width:100%}.nfce-product-table tr{border:1px solid #dbe4f0;border-radius:16px;padding:8px;margin-bottom:10px}.nfce-product-table td{display:flex;justify-content:space-between;gap:10px;border-bottom:1px solid #edf2f7;padding:8px 4px}.nfce-product-table td:last-child{border-bottom:0}.nfce-product-table td::before{content:attr(data-label);font-size:11px;font-weight:950;color:#64748b;text-transform:uppercase}.nfce-product-table td.primary{display:block}.nfce-product-table td.primary::before{display:block;margin-bottom:5px}.nfce-product-table input{max-width:65%}.nfce-product-table .desc{max-width:100%;min-width:0}.nfce-detail-button{width:100%;min-height:40px}.nfce-modal-grid{grid-template-columns:1fr}.nfce-detail-card{border-radius:18px}.nfce-detail-body{padding:12px}.nfce-detail-head,.nfce-detail-foot{padding:12px}}
</style>

<?php if ($locked): ?>
  <div class="nfce-edit-note nfce-edit-locked">Esta <?= fiscal_h($docLabel) ?> está <?= fiscal_h($doc['status'] ?? '') ?>. Por segurança fiscal, esta tela fica somente para consulta. Para corrigir uma rejeição, edite antes da autorização.</div>
<?php else: ?>
  <?php $isDevolucaoEdit = ($docModel === '55') && (str_contains(strtoupper((string)($doc['source_type'] ?? '')), 'DEVOLUCAO') || str_contains(strtoupper((string)($doc['operation_nature'] ?? '')), 'DEVOLUCAO')); ?>
  <div class="nfce-edit-note">
    <?php if ($fromBatch): ?>
      Corrija os dados fiscais necessários. Ao salvar, <b>número, série, chave, data/hora de emissão e contingência serão preservados</b>. O XML será regenerado sem assinatura antiga e você voltará para <b>Notas em lote</b> para transmitir novamente.
    <?php elseif ($isDevolucaoEdit): ?>
      Após salvar, o XML atual será regenerado, o documento voltará para <b><?= fiscal_h($docPreviewStatus) ?></b> e você retornará para a lista de <b>Devolução / Outras Entradas</b> para gerar a nota de devolução.
    <?php else: ?>
      Após salvar, o XML atual será regenerado e o documento voltará para <b><?= fiscal_h($docPreviewStatus) ?></b>. Em seguida, use <b>Reenviar <?= fiscal_h($docLabel) ?></b>.
    <?php endif; ?>
  </div>
<?php endif; ?>

<form method="post" class="fiscal-form">
  <input type="hidden" name="doc_id" value="<?= (int)$docId ?>">
  <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
  <?php if ($csrfToken !== ''): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($csrfToken) ?>"><?php endif; ?>
  <div class="fiscal-card">
    <h2>Dados do documento</h2>
    <div class="fiscal-form-grid">
      <label class="span-2">Natureza da operação<input name="operation_nature" value="<?= fiscal_h($doc['operation_nature'] ?? 'VENDA AO CONSUMIDOR NFC-E') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Emissão<input type="datetime-local" name="issue_datetime" value="<?= fiscal_h($issueLocal) ?>" <?= ($locked || $fromBatch) ? 'disabled' : '' ?>></label>
      <label>Série<input value="<?= fiscal_h($doc['series'] ?? '') ?>" disabled></label>
      <label>Número<input value="<?= fiscal_h($doc['number'] ?? '') ?>" disabled></label>
      <label>Status<input value="<?= fiscal_h($doc['status'] ?? '') ?>" disabled></label>
    </div>
  </div>

  <?php if ($docModel === '55'): ?>
  <div class="fiscal-card">
    <h2>Notas referenciadas</h2>
    <div class="fiscal-mini-help" style="margin-bottom:10px">Informe uma ou mais chaves de acesso, com 44 dígitos, da NF-e/NFC-e original. Para devolução, essa referência é obrigatória.</div>
    <label>Chaves referenciadas
      <textarea name="reference_keys" rows="3" placeholder="Cole aqui a chave de acesso da nota original" <?= $locked ? 'disabled' : '' ?>><?= fiscal_h($referenceText) ?></textarea>
    </label>
  </div>
  <?php endif; ?>

  <div class="fiscal-card">
    <h2>Destinatário / cliente</h2>
    <div class="fiscal-form-grid">
      <label>CPF/CNPJ<input name="dest_document" value="<?= fiscal_h($recipient['document'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label class="span-2">Nome<input name="dest_name" value="<?= fiscal_h($recipient['name'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Telefone<input name="dest_phone" value="<?= fiscal_h($recipient['phone'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>CEP<input name="dest_zip_code" value="<?= fiscal_h($recipient['zip_code'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>UF<input name="dest_state" value="<?= fiscal_h($recipient['state'] ?? '') ?>" maxlength="2" <?= $locked ? 'disabled' : '' ?>></label>
      <label class="span-2">Endereço<input name="dest_address" value="<?= fiscal_h($recipient['address'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Número<input name="dest_number" value="<?= fiscal_h($recipient['number'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Complemento<input name="dest_complement" value="<?= fiscal_h($recipient['complement'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Bairro<input name="dest_district" value="<?= fiscal_h($recipient['district'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Cidade<input name="dest_city" value="<?= fiscal_h($recipient['city'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
      <label>Cód. IBGE cidade<input name="dest_city_ibge" value="<?= fiscal_h($recipient['city_ibge'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
    </div>
  </div>

  <div class="fiscal-card">
    <div class="fiscal-list-head"><h2>Produtos</h2><span class="fiscal-list-count"><?= count($items) ?> item(ns)</span></div>
    <div class="fiscal-mini-help" style="margin-bottom:10px">A grade principal mostra somente os campos de conferência rápida. Use <b>Detalhar</b> para ajustar NCM, CEST, CFOP, ICMS, ICMS-ST, PIS, COFINS, IBS/CBS e demais campos fiscais do item.</div>
    <div class="table-wrap"><table class="fiscal-table nfce-edit-table nfce-product-table">
      <thead><tr><th>Código</th><th>Nome do produto</th><th>Quantidade</th><th>Valor unitário</th><th>Valor total</th><th>Detalhes</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it):
        $iid=(int)$it['id'];
        $icms=nfce_edit_tax_for($taxes,$iid,'ICMS');
        $icmsExtra=nfce_edit_tax_extra($icms);
        $pis=nfce_edit_tax_for($taxes,$iid,'PIS');
        $cof=nfce_edit_tax_for($taxes,$iid,'COFINS');
        $ibscbs=nfce_edit_tax_for($taxes,$iid,'IBSCBS');
        $ibscbsExtra=nfce_edit_tax_extra($ibscbs);
        $itemOtherForNfce=max(0.0, (float)($it['other_value'] ?? 0) + (float)($it['freight_value'] ?? 0));
      $taxBaseDefault=max(0.0, round((float)($it['total_value'] ?? 0) + $itemOtherForNfce - (float)($it['discount_value'] ?? 0), 2));
        $ibsUfRate=$ibscbsExtra['ibs_uf_rate'] ?? 0;
        $ibsUfAmount=$ibscbsExtra['ibs_uf_amount'] ?? 0;
        $ibsMunRate=$ibscbsExtra['ibs_mun_rate'] ?? 0;
        $ibsMunAmount=$ibscbsExtra['ibs_mun_amount'] ?? 0;
        $ibsAmount=$ibscbsExtra['ibs_amount'] ?? ((float)$ibsUfAmount + (float)$ibsMunAmount);
        $cbsRate=$ibscbsExtra['cbs_rate'] ?? 0;
        $cbsAmount=$ibscbsExtra['cbs_amount'] ?? 0;
      ?>
        <tr>
          <td data-label="Código"><input type="hidden" name="item_id[]" value="<?= $iid ?>"><input class="nfce-product-code" name="product_code[<?= $iid ?>]" value="<?= fiscal_h($it['product_code'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td data-label="Nome do produto" class="primary"><input class="desc" name="description[<?= $iid ?>]" value="<?= fiscal_h($it['description'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td data-label="Quantidade"><input class="money" name="quantity[<?= $iid ?>]" value="<?= nfce_edit_fmt($it['quantity'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td data-label="Valor unitário"><input class="money" name="unit_value[<?= $iid ?>]" value="<?= nfce_edit_fmt($it['unit_value'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td data-label="Valor total"><input class="money" name="total_value[<?= $iid ?>]" value="<?= nfce_edit_fmt($it['total_value'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td data-label="Detalhes"><button type="button" class="btn btn-sm nfce-detail-button" data-nfce-modal-open="<?= $iid ?>">Detalhar</button></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <?php foreach ($items as $it):
      $iid=(int)$it['id'];
      $icms=nfce_edit_tax_for($taxes,$iid,'ICMS');
      $icmsExtra=nfce_edit_tax_extra($icms);
      $pis=nfce_edit_tax_for($taxes,$iid,'PIS');
      $cof=nfce_edit_tax_for($taxes,$iid,'COFINS');
      $ibscbs=nfce_edit_tax_for($taxes,$iid,'IBSCBS');
      $ibscbsExtra=nfce_edit_tax_extra($ibscbs);
      $itemOtherForNfce=max(0.0, (float)($it['other_value'] ?? 0) + (float)($it['freight_value'] ?? 0));
      $taxBaseDefault=max(0.0, round((float)($it['total_value'] ?? 0) + $itemOtherForNfce - (float)($it['discount_value'] ?? 0), 2));
      $ibsUfRate=$ibscbsExtra['ibs_uf_rate'] ?? 0;
      $ibsUfAmount=$ibscbsExtra['ibs_uf_amount'] ?? 0;
      $ibsMunRate=$ibscbsExtra['ibs_mun_rate'] ?? 0;
      $ibsMunAmount=$ibscbsExtra['ibs_mun_amount'] ?? 0;
      $ibsAmount=$ibscbsExtra['ibs_amount'] ?? ((float)$ibsUfAmount + (float)$ibsMunAmount);
      $cbsRate=$ibscbsExtra['cbs_rate'] ?? 0;
      $cbsAmount=$ibscbsExtra['cbs_amount'] ?? 0;
    ?>
      <div class="nfce-detail-modal" id="nfce-detail-<?= $iid ?>" aria-hidden="true">
        <div class="nfce-detail-card" role="dialog" aria-modal="true" aria-labelledby="nfce-detail-title-<?= $iid ?>">
          <div class="nfce-detail-head">
            <div>
              <h3 id="nfce-detail-title-<?= $iid ?>">Detalhes fiscais do item <?= (int)$it['item_number'] ?></h3>
              <p><?= fiscal_h($it['description'] ?? '') ?></p>
            </div>
            <button type="button" class="nfce-detail-close" data-nfce-modal-close aria-label="Fechar">×</button>
          </div>
          <div class="nfce-detail-body">
            <div class="nfce-detail-section">
              <h4>Dados do produto</h4>
              <div class="nfce-modal-grid">
                <label>NCM<input name="ncm[<?= $iid ?>]" value="<?= fiscal_h($it['ncm'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>CEST<input name="cest[<?= $iid ?>]" value="<?= fiscal_h($it['cest'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>CFOP<input name="cfop[<?= $iid ?>]" value="<?= fiscal_h($it['cfop'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Unidade<input name="unidade[<?= $iid ?>]" value="<?= fiscal_h($it['unidade'] ?? 'UN') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Desconto<input name="discount_value[<?= $iid ?>]" value="<?= nfce_edit_fmt($it['discount_value'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <input type="hidden" name="freight_value[<?= $iid ?>]" value="0">
                <label>Acréscimo / Entrega<input name="other_value[<?= $iid ?>]" value="<?= nfce_edit_fmt($itemOtherForNfce ?? ((float)($it['other_value'] ?? 0) + (float)($it['freight_value'] ?? 0)), 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>

            <div class="nfce-detail-section">
              <h4>ICMS</h4>
              <div class="nfce-modal-grid">
                <label>Origem<input name="icms_origin[<?= $iid ?>]" value="<?= fiscal_h($icms['origin_code'] ?? '0') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>CST ICMS<input name="icms_cst[<?= $iid ?>]" value="<?= fiscal_h($icms['cst'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>CSOSN<input name="icms_csosn[<?= $iid ?>]" value="<?= fiscal_h($icms['csosn'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base ICMS<input name="icms_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($icms['base_calc'] ?? $taxBaseDefault, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% ICMS<input name="icms_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($icms['rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor ICMS<input name="icms_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($icms['amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>

            <div class="nfce-detail-section">
              <h4>ICMS-ST - Substituição Tributária</h4>
              <div class="nfce-tax-hint">Use estes campos quando o CST/CSOSN do item exigir substituição tributária, como CST 10, 30, 70, 90, 60 ou CSOSN 201, 202, 203, 500 e 900. O FCP-ST é complementar e deve ser preenchido somente quando aplicável.</div>
              <div class="nfce-modal-grid">
                <label>Modalidade BC ST<input name="icms_st_modbc[<?= $iid ?>]" value="<?= fiscal_h($icmsExtra['st_modbc'] ?? '4') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>MVA %<input name="icms_st_mva[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['st_mva'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Redução BC ST %<input name="icms_st_redbc[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['st_reduction_rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base ICMS ST<input name="icms_st_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['st_base'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% ICMS ST<input name="icms_st_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['st_rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor ICMS ST<input name="icms_st_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['st_amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base FCP ST<input name="fcp_st_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['fcp_st_base'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% FCP ST<input name="fcp_st_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['fcp_st_rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor FCP ST<input name="fcp_st_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($icmsExtra['fcp_st_amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>

            <div class="nfce-detail-section">
              <h4>PIS</h4>
              <div class="nfce-modal-grid">
                <label>CST PIS<input name="pis_cst[<?= $iid ?>]" value="<?= fiscal_h($pis['cst'] ?? '01') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base PIS<input name="pis_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($pis['base_calc'] ?? $taxBaseDefault, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% PIS<input name="pis_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($pis['rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor PIS<input name="pis_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($pis['amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>

            <div class="nfce-detail-section">
              <h4>COFINS</h4>
              <div class="nfce-modal-grid">
                <label>CST COFINS<input name="cofins_cst[<?= $iid ?>]" value="<?= fiscal_h($cof['cst'] ?? '01') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base COFINS<input name="cofins_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($cof['base_calc'] ?? $taxBaseDefault, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% COFINS<input name="cofins_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($cof['rate'] ?? 0, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor COFINS<input name="cofins_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($cof['amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>

            <div class="nfce-detail-section">
              <h4>IBS / CBS</h4>
              <div class="nfce-tax-hint">Campos preparados para ajustes da tributação da reforma tributária quando o cadastro fiscal do produto exigir IBS/CBS.</div>
              <div class="nfce-modal-grid">
                <label>CST IBS/CBS<input name="ibscbs_cst[<?= $iid ?>]" value="<?= fiscal_h($ibscbs['cst'] ?? ($ibscbsExtra['CST'] ?? '000')) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>cClassTrib<input name="ibscbs_class[<?= $iid ?>]" value="<?= fiscal_h($ibscbsExtra['cClassTrib'] ?? $ibscbsExtra['class_code'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Base IBS/CBS<input name="ibscbs_base[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibscbs['base_calc'] ?? $ibscbsExtra['base_calc'] ?? $taxBaseDefault, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% IBS UF<input name="ibs_uf_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibsUfRate, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor IBS UF<input name="ibs_uf_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibsUfAmount, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% IBS Município<input name="ibs_mun_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibsMunRate, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor IBS Município<input name="ibs_mun_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibsMunAmount, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor IBS<input name="ibs_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($ibsAmount, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>% CBS<input name="cbs_rate[<?= $iid ?>]" value="<?= nfce_edit_fmt($cbsRate, 4) ?>" <?= $locked ? 'disabled' : '' ?>></label>
                <label>Valor CBS<input name="cbs_amount[<?= $iid ?>]" value="<?= nfce_edit_fmt($cbsAmount, 2) ?>" <?= $locked ? 'disabled' : '' ?>></label>
              </div>
            </div>
          </div>
          <div class="nfce-detail-foot">
            <button type="button" class="btn btn-primary" data-nfce-modal-close>Confirmar detalhes</button>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>

  <div class="fiscal-card">
    <h2>Pagamentos</h2>
    <div class="table-wrap"><table class="fiscal-table nfce-edit-table" style="min-width:880px">
      <thead><tr><th>Tipo tPag</th><th>Descrição</th><th>Indicador</th><th>Valor</th><th>Integração cartão</th><th>Bandeira</th><th>CNPJ credenciadora</th><th>Autorização</th></tr></thead>
      <tbody>
      <?php foreach ($payments as $pay): $pid=(int)$pay['id']; ?>
        <tr>
          <td><input type="hidden" name="payment_id[]" value="<?= $pid ?>"><input class="small" name="payment_type[<?= $pid ?>]" value="<?= fiscal_h($pay['payment_type'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><input name="payment_description[<?= $pid ?>]" value="<?= fiscal_h($pay['payment_description'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><select name="payment_indicator[<?= $pid ?>]" <?= $locked ? 'disabled' : '' ?>><option value="0" <?= (($pay['payment_indicator'] ?? '0')==='0')?'selected':'' ?>>À vista</option><option value="1" <?= (($pay['payment_indicator'] ?? '')==='1')?'selected':'' ?>>A prazo</option></select></td>
          <td><input class="money" name="payment_amount[<?= $pid ?>]" value="<?= nfce_edit_fmt($pay['amount'] ?? 0, 2) ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><select name="card_integration[<?= $pid ?>]" <?= $locked ? 'disabled' : '' ?>><option value="">Não se aplica</option><option value="1" <?= (($pay['card_integration'] ?? '')==='1')?'selected':'' ?>>TEF</option><option value="2" <?= (($pay['card_integration'] ?? '')==='2')?'selected':'' ?>>POS</option></select></td>
          <td><input class="small" name="card_brand[<?= $pid ?>]" value="<?= fiscal_h($pay['card_brand'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><input name="card_cnpj[<?= $pid ?>]" value="<?= fiscal_h($pay['card_cnpj'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
          <td><input name="authorization_code[<?= $pid ?>]" value="<?= fiscal_h($pay['authorization_code'] ?? '') ?>" <?= $locked ? 'disabled' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
  </div>

  <div class="nfce-edit-actions">
    <?php if (!$locked): ?><button class="btn btn-primary" type="submit">Salvar correções e regenerar XML</button><?php endif; ?>
    <a class="btn" href="<?= $fromBatch ? fiscal_h($returnTo) : ($pedidoId > 0 ? ('fiscal_nfce_pedido.php?pedido_id=' . (int)$pedidoId) : 'fiscal_documentos.php') ?>">Voltar</a>
    <a class="btn" data-no-processing href="fiscal_nfce_acao.php?action=download_xml&doc_id=<?= (int)$docId ?>">Baixar XML atual</a>
  </div>
</form>


<script>
(function(){
  function closeModal(modal){
    if (!modal) return;
    modal.classList.remove('active');
    modal.setAttribute('aria-hidden', 'true');
  }
  document.addEventListener('click', function(ev){
    var openBtn = ev.target.closest('[data-nfce-modal-open]');
    if (openBtn) {
      ev.preventDefault();
      var modal = document.getElementById('nfce-detail-' + openBtn.getAttribute('data-nfce-modal-open'));
      if (modal) {
        modal.classList.add('active');
        modal.setAttribute('aria-hidden', 'false');
        var first = modal.querySelector('input:not([disabled]),select:not([disabled]),button');
        if (first) first.focus();
      }
      return;
    }
    var closeBtn = ev.target.closest('[data-nfce-modal-close]');
    if (closeBtn) {
      ev.preventDefault();
      closeModal(closeBtn.closest('.nfce-detail-modal'));
      return;
    }
    if (ev.target.classList && ev.target.classList.contains('nfce-detail-modal')) closeModal(ev.target);
  });
  document.addEventListener('keydown', function(ev){
    if (ev.key === 'Escape') {
      document.querySelectorAll('.nfce-detail-modal.active').forEach(closeModal);
    }
  });
})();
</script>

<?php fiscal_admin_end(); ?>
