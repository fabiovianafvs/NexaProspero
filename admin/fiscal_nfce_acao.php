<?php
declare(strict_types=1);
// NEXA_PATCH_CANCELAMENTO_EVENTO_MG_V3_20260805
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fiscal.php';

$action = (string)($_POST['action'] ?? $_GET['action'] ?? '');
$nfceActionName = strtolower(trim($action));
$nfceActionScope = $nfceActionName === 'cancel_document'
  ? 'fiscal_cancel_homologation'
  : ($nfceActionName === 'cce_document' ? 'fiscal_cce_homologation' : 'fiscal_nfce_homologation');
$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, $nfceActionScope)
  : (int)COMPANY_ID;
$nfceActionCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, $nfceActionScope);

function nfce_action_safe_return(string $returnTo, string $fallback = 'fiscal_documentos.php'): string {
  $returnTo = trim($returnTo);
  if ($returnTo === '') return $fallback;
  if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $returnTo) || str_starts_with($returnTo, '//')) return $fallback;
  if (str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) return $fallback;
  $parts = parse_url($returnTo);
  if ($parts === false) return $fallback;
  $path = (string)($parts['path'] ?? '');
  if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) return $fallback;
  return $returnTo;
}

function nfce_action_redirect(string $returnTo, string $key, string $message): void {
  $returnTo = nfce_action_safe_return($returnTo);
  $parts = parse_url($returnTo) ?: [];
  $path = (string)($parts['path'] ?? 'fiscal_documentos.php');
  $query = [];
  if (!empty($parts['query'])) parse_str((string)$parts['query'], $query);
  unset($query['ok'], $query['err']);
  $query[$key] = $message;
  $target = $path . ($query ? ('?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986)) : '');
  if (function_exists('multi_company_context_url')) $target = multi_company_context_url($target);
  header('Location: ' . $target);
  exit;
}

function nfce_action_current_doc(PDO $pdo, int $companyId, int $docId, int $pedidoId): ?array {
  $doc = null;
  if ($docId > 0) $doc = fiscal_nfce_document_by_id($pdo, $companyId, $docId);
  elseif ($pedidoId > 0) $doc = fiscal_nfce_order_document($pdo, $companyId, $pedidoId);
  if (!$doc) return null;

  $model = in_array((string)($doc['model'] ?? ''), ['55','65'], true) ? (string)$doc['model'] : '65';
  if (function_exists('fiscal_multi_company_document_homologation_is_cross')
      && fiscal_multi_company_document_homologation_is_cross($pdo, $model)) {
    if (function_exists('fiscal_multi_company_document_homologation_assert')) {
      fiscal_multi_company_document_homologation_assert($pdo, $companyId, $model, 'consultar');
    }
    if (strtoupper((string)($doc['environment'] ?? '')) !== 'HOMOLOGACAO') {
      throw new RuntimeException('V11.46 Multiempresa: documento fiscal fora de HOMOLOGAÇÃO bloqueado no F11.');
    }
  }
  return $doc;
}

function nfce_action_result_message(array $result, string $docLabel): string {
  if (!empty($result['ok']) && !empty($result['contingency'])) {
    if (!empty($result['requires_batch'])) return $docLabel . ' emitida em contingência offline. O DANFE pode ser impresso; a transmissão ficou pendente em Notas em lote.';
    return $docLabel . ' autorizada em contingência ' . str_replace(['SVCAN','SVCRS'], ['SVC-AN','SVC-RS'], (string)($result['contingency_type'] ?? 'SVC')) . '. Protocolo: ' . ($result['protocol'] ?? '-');
  }
  if (!empty($result['ok'])) return $docLabel . ' autorizada. Protocolo: ' . ($result['protocol'] ?? '-');
  return 'Retorno da SEFAZ: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? ($docLabel . ' não autorizada.'));
}

$docId = (int)($_POST['doc_id'] ?? $_GET['doc_id'] ?? 0);
$pedidoId = (int)($_POST['pedido_id'] ?? $_GET['pedido_id'] ?? 0);
$returnTo = nfce_action_safe_return((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''), $pedidoId > 0 ? ('fiscal_nfce_pedido.php?pedido_id=' . $pedidoId) : 'fiscal_documentos.php');
try {
  fiscal_nfce_ensure_schema($pdo);

  if ($action === 'download_xml') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
    $downloadStatus = strtoupper(trim((string)($doc['status'] ?? '')));
    $downloadEmission = strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL')));
    $canRegenerateDownload = !$nfceActionCrossContext
      && !in_array($downloadStatus, ['AUTORIZADA','CANCELADA','PENDENTE_RETORNO','CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE'], true)
      && !in_array($downloadEmission, ['NFC_OFFLINE','OFFLINE','SVCAN','SVCRS','SVC-AN','SVC-RS'], true);
    if ($canRegenerateDownload) {
      $freshXml = fiscal_nfce_build_draft_xml($pdo, $cid, (int)$doc['id']);
      $pdo->prepare('UPDATE fiscal_documents SET xml_raw=?, xml_hash=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$freshXml, hash('sha256', $freshXml), $cid, (int)$doc['id']]);
      $doc['xml_raw'] = $freshXml;
    }
    if (trim((string)($doc['xml_raw'] ?? '')) === '') throw new RuntimeException('XML atual ainda não foi gerado para este documento.');
    $sourceSuffix = $pedidoId > 0 ? ('pedido-' . $pedidoId) : ('doc-' . (int)$doc['id']);
    if (($doc['source_type'] ?? '') === 'PEDIDO') $sourceSuffix = 'pedido-' . (int)$doc['source_id'];
    $fileDoc = ((string)($doc['model'] ?? '65') === '55') ? 'nfe' : 'nfce';
    $prefix = strtoupper((string)($doc['status'] ?? '')) === 'AUTORIZADA' ? ($fileDoc . '-autorizada') : ($fileDoc . '-atual');
    $name = $prefix . '-' . $sourceSuffix . '-doc-' . (int)$doc['id'] . '.xml';
    header('Content-Type: application/xml; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $name . '"');
    echo (string)$doc['xml_raw'];
    exit;
  }

  if ($action === 'emitir_rapido' || $action === 'emitir_nfce_rapido') {
    if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado para emissão rápida da NFC-e.');
    $result = fiscal_nfce_authorize_order($pdo, $cid, $pedidoId);
    $msg = nfce_action_result_message($result, 'NFC-e');
    nfce_action_redirect($returnTo, 'ok', $msg);
  }

  if ($action === 'emitir_nfe_rapido') {
    if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado para emissão rápida da NF-e.');
    $result = fiscal_nfe_authorize_order($pdo, $cid, $pedidoId);
    $msg = nfce_action_result_message($result, 'NF-e');
    nfce_action_redirect($returnTo, 'ok', $msg);
  }

  if ($action === 'create_preview') {
    if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado para gerar a pré-NFC-e.');
    fiscal_nfce_create_or_update_from_order($pdo, $cid, $pedidoId, '65');
    nfce_action_redirect($returnTo, 'ok', 'Pré-NFC-e gerada/atualizada com sucesso.');
  }

  if ($action === 'create_preview_nfe') {
    if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado para gerar a pré-NF-e.');
    fiscal_nfe_create_or_update_from_order($pdo, $cid, $pedidoId);
    nfce_action_redirect($returnTo, 'ok', 'Pré-NF-e gerada/atualizada com sucesso.');
  }

  if ($action === 'authorize_now') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    $docLabel = $doc ? fiscal_doc_model_label((string)($doc['model'] ?? '65')) : 'NFC-e';
    $result = $doc
      ? fiscal_nfce_authorize_document($pdo, $cid, (int)$doc['id'])
      : (($pedidoId > 0) ? fiscal_nfce_authorize_order($pdo, $cid, $pedidoId) : null);
    if (!$result) throw new RuntimeException('Documento fiscal não encontrado para transmitir.');
    $msg = nfce_action_result_message($result, $docLabel);
    nfce_action_redirect($returnTo, 'ok', $msg);
  }

  if ($action === 'consult_status') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    if (!$doc) throw new RuntimeException('Nenhum documento fiscal encontrado para consultar.');
    $result = fiscal_nfce_consult_document_status($pdo, $cid, (int)$doc['id']);
    $msg = 'Consulta SEFAZ: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? 'Consulta concluída.');
    if (!empty($result['protocol'])) $msg .= ' Protocolo: ' . $result['protocol'];
    nfce_action_redirect($returnTo, 'ok', $msg);
  }

  if ($action === 'recover_authorization') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    if (!$doc) throw new RuntimeException('Nenhum documento fiscal encontrado para recuperar.');
    $result = fiscal_nfce_recover_document_authorization($pdo, $cid, (int)$doc['id']);
    $docLabel = fiscal_doc_model_label((string)($doc['model'] ?? '65'));
    nfce_action_redirect($returnTo, 'ok', $docLabel . ' recuperada pela chave de acesso. Protocolo: ' . ($result['protocol'] ?? '-'));
  }


  if ($action === 'cancel_document') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado para cancelamento.');
    if (function_exists('fiscal_multi_company_cancel_homologation_assert')) {
      fiscal_multi_company_cancel_homologation_assert($pdo, $cid, $doc);
    }
    $reason = (string)($_POST['justification'] ?? $_POST['reason'] ?? '');
    $result = fiscal_nfce_cancel_document($pdo, $cid, (int)$doc['id'], $reason);
    $docLabel = fiscal_doc_model_label((string)($doc['model'] ?? '65'));
    $msg = !empty($result['ok'])
      ? ($docLabel . ' cancelada. Protocolo do evento: ' . ($result['protocol'] ?? '-'))
      : ('Cancelamento não confirmado: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? 'não confirmado.'));
    if (($result['code'] ?? '') === '893') {
      $msg .= ' Diagnóstico Nexa v3: o cancelamento em MG usa diretamente o Web Service oficial de Recepção de Evento 110111.';
    }
    if (!empty($result['ok']) && is_array($result['internal_sync'] ?? null)) {
      $sync = $result['internal_sync'];
      $msg .= ' Pedido #' . (int)($sync['order_id'] ?? 0) . ' cancelado; estoque devolvido: ' . number_format((float)($sync['stock_quantity'] ?? 0), 4, ',', '.') . '; estorno financeiro: R$ ' . number_format((float)($sync['finance_amount'] ?? 0), 2, ',', '.') . '; estorno no caixa: R$ ' . number_format((float)($sync['cash_amount'] ?? 0), 2, ',', '.') . '.';
      if (!empty($sync['warning'])) $msg .= ' Atenção: ' . (string)$sync['warning'];
    } elseif (!empty($result['ok']) && !empty($result['internal_error'])) {
      $msg .= ' ATENÇÃO: o documento foi cancelado na SEFAZ, mas a sincronização interna do pedido falhou: ' . (string)$result['internal_error'] . '. Execute a auditoria de cancelamentos.';
    }
    nfce_action_redirect($returnTo, (!empty($result['ok']) && empty($result['internal_error'])) ? 'ok' : 'err', $msg);
  }

  if ($action === 'cce_document') {
    $doc = nfce_action_current_doc($pdo, $cid, $docId, $pedidoId);
    if (!$doc) throw new RuntimeException('Documento fiscal não encontrado para Carta de Correção.');
    if (function_exists('fiscal_multi_company_cce_homologation_assert')) {
      fiscal_multi_company_cce_homologation_assert($pdo, $cid, $doc);
    }
    $correction = (string)($_POST['correction'] ?? '');
    $result = fiscal_nfe_cce_document($pdo, $cid, (int)$doc['id'], $correction);
    $msg = !empty($result['ok'])
      ? ('Carta de Correção registrada. Sequência: ' . ($result['sequence'] ?? '-') . '. Protocolo: ' . ($result['protocol'] ?? '-'))
      : ('Carta de Correção retornou: ' . ($result['code'] ?? '-') . ' - ' . ($result['message'] ?? 'não confirmada.'));
    nfce_action_redirect($returnTo, !empty($result['ok']) ? 'ok' : 'err', $msg);
  }

  if ($action === 'emitir_nfe_referenciada' || $action === 'gerar_nfe_referenciada') {
    $docIds = $_POST['doc_ids'] ?? [];
    if (!is_array($docIds)) $docIds = [$docIds];
    $destination = [
      'person_id'=>(int)($_POST['destination_person_id'] ?? 0),
      'name'=>(string)($_POST['destination_name'] ?? ''),
      'document'=>(string)($_POST['destination_document'] ?? ''),
      'state_registration'=>(string)($_POST['destination_ie'] ?? ''),
      'email'=>(string)($_POST['destination_email'] ?? ''),
      'phone'=>(string)($_POST['destination_phone'] ?? ''),
      'zip_code'=>(string)($_POST['destination_zip'] ?? ''),
      'address'=>(string)($_POST['destination_address'] ?? ''),
      'number'=>(string)($_POST['destination_number'] ?? ''),
      'complement'=>(string)($_POST['destination_complement'] ?? ''),
      'district'=>(string)($_POST['destination_district'] ?? ''),
      'city'=>(string)($_POST['destination_city'] ?? ''),
      'city_ibge'=>(string)($_POST['destination_city_ibge'] ?? ''),
      'state'=>(string)($_POST['destination_state'] ?? ''),
    ];
    $doc = fiscal_nfe_create_from_referenced_nfces($pdo, $cid, $docIds, $destination);
    $newDocId = (int)($doc['id'] ?? 0);
    if ($newDocId <= 0) throw new RuntimeException('Não foi possível localizar a pré-NF-e referenciada gerada.');
    header('Location: fiscal_nfce_editar.php?doc_id=' . $newDocId . '&ok=' . rawurlencode('Pré-NF-e referenciada gerada. Confira cliente, produtos, CFOP e tributação antes de reenviar.'));
    exit;
  }

  if ($action === 'emitir_lote_contingencia' || $action === 'processar_lote_documentos') {
    $docIds = $_POST['doc_ids'] ?? [];
    if (!is_array($docIds)) $docIds = [$docIds];
    $result = fiscal_authorize_batch_documents($pdo, $cid, $docIds);
    $okCount = (int)($result['ok_count'] ?? 0);
    $failCount = (int)($result['fail_count'] ?? 0);
    $msg = 'Lote processado. Autorizadas: ' . $okCount . '. Não autorizadas/pendentes: ' . $failCount . '.';
    $filter = strtoupper(trim((string)($_POST['batch_filter'] ?? 'TODAS')));
    if (!in_array($filter, ['TODAS','REJEITADAS','CONTINGENCIA'], true)) $filter = 'TODAS';
    $batchQ = trim((string)($_POST['batch_q'] ?? ''));
    $batchReturn = 'fiscal_lote_contingencia.php?status=' . rawurlencode($filter) . ($batchQ !== '' ? ('&q=' . rawurlencode($batchQ)) : '');
    nfce_action_redirect($batchReturn, $failCount > 0 ? 'err' : 'ok', $msg);
  }

  throw new RuntimeException('Ação fiscal não reconhecida.');
} catch (Throwable $e) {
  if ($action === 'download_xml') {
    http_response_code(400);
    echo $e->getMessage();
    exit;
  }
  nfce_action_redirect($returnTo, 'err', $e->getMessage());
}
