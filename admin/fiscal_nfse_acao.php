<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/nfse_quick_emit.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfse_homologation')
  : (int)COMPANY_ID;
$nfseF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && $cid !== (int)COMPANY_ID;
if ($nfseF11) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;

function nfse_action_safe_return(string $returnTo, string $fallback = 'fiscal_nfse_nacional.php'): string {
  $returnTo = trim($returnTo);
  if ($returnTo === '') return $fallback;
  if (preg_match('~^[a-z][a-z0-9+.-]*://~i', $returnTo) || str_starts_with($returnTo, '//')) return $fallback;
  if (str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) return $fallback;
  $parts = parse_url($returnTo);
  if ($parts === false) return $fallback;
  $path = (string)($parts['path'] ?? '');
  if ($path === '' || str_contains($path, '..') || str_starts_with($path, '/')) return $fallback;
  $allowed = ['fiscal_nfse_nacional.php','pedidos.php','fiscal_documentos.php'];
  if (!in_array(basename($path), $allowed, true)) return $fallback;
  return $returnTo;
}

function nfse_action_redirect(string $returnTo, string $key, string $message): void {
  $returnTo = nfse_action_safe_return($returnTo);
  $sep = str_contains($returnTo, '?') ? '&' : '?';
  header('Location: ' . $returnTo . $sep . $key . '=' . rawurlencode($message));
  exit;
}

function nfse_action_f11_guard(PDO $pdo, int $companyId, bool $isF11, string $action, int $docId, int $pedidoId): void {
  if (!$isF11) return;

  $allowed = ['prepare','emit','emit_quick','recover','query','download_dps','download_nfse','cancel','download_cancel_event'];
  if (!in_array($action, $allowed, true)) {
    throw new RuntimeException('V11.51 Multiempresa: esta ação NFS-e ainda não foi homologada pelo F11.');
  }
  if (!function_exists('multi_company_scope_is_cross_company') || !multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_homologation')) {
    throw new RuntimeException('Contexto Multiempresa inválido para NFS-e. Atualize a página e tente novamente.');
  }
  if (in_array($action, ['cancel','download_cancel_event'], true)) {
    if (!multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_cancel_homologation')) {
      throw new RuntimeException('V11.51 Multiempresa: cancelamento de NFS-e não está homologado neste contexto F11.');
    }
  }
  if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'fiscal_documentos')) {
    throw new RuntimeException('Seu perfil na empresa selecionada não possui permissão para Documentos fiscais.');
  }
  if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'fiscal')) {
    throw new RuntimeException('O módulo Fiscal não está ativo na empresa selecionada.');
  }
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
      throw new RuntimeException('Contexto Multiempresa expirado para operação NFS-e. Atualize a página e tente novamente.');
    }
  }

  $cfg = fiscal_company_config($pdo, $companyId);
  $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
  if ($environment !== 'HOMOLOGACAO') {
    throw new RuntimeException('V11.51 Multiempresa: NFS-e pelo F11 está liberada somente em HOMOLOGAÇÃO. Produção continua bloqueada.');
  }

  if ($docId > 0) {
    $doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado na empresa selecionada.');
    if (strtoupper(trim((string)($doc['environment'] ?? ''))) !== 'HOMOLOGACAO') {
      throw new RuntimeException('V11.51 Multiempresa: este documento NFS-e não pertence ao ambiente de HOMOLOGAÇÃO.');
    }
    if (strtoupper(trim((string)($doc['source_type'] ?? ''))) !== 'PEDIDO' || (int)($doc['source_id'] ?? 0) <= 0) {
      throw new RuntimeException('V11.51 Multiempresa: somente NFS-e originada de PEDIDO está homologada no F11.');
    }
    if ($pedidoId > 0 && (int)($doc['source_id'] ?? 0) !== $pedidoId) {
      throw new RuntimeException('Pedido informado não corresponde ao documento NFS-e.');
    }
    if (in_array($action, ['cancel','download_cancel_event'], true)) {
      if (nfse_provider_document_code($pdo, $companyId, $docId) !== 'PRONIM') {
        throw new RuntimeException('V11.51 Multiempresa: cancelamento de NFS-e está homologado somente para Pronim/GOVBR. Ambiente Nacional permanece bloqueado.');
      }
      $docStatus = strtoupper(trim((string)($doc['status'] ?? '')));
      if ($action === 'cancel' && !in_array($docStatus, ['AUTORIZADA','CANCELADA'], true)) {
        throw new RuntimeException('Somente NFS-e autorizada pode ser cancelada.');
      }
      if ($action === 'download_cancel_event' && $docStatus !== 'CANCELADA') {
        throw new RuntimeException('O evento de cancelamento só pode ser baixado após a NFS-e estar cancelada.');
      }
    }
  }

  if ($pedidoId > 0) {
    $st = $pdo->prepare('SELECT id FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $pedidoId]);
    if (!(int)$st->fetchColumn()) throw new RuntimeException('Pedido não encontrado na empresa selecionada.');
  }
}

function nfse_action_xml_download(array $doc, string $kind): void {
  $docId = (int)($doc['id'] ?? 0);
  $pedidoId = (int)($doc['source_id'] ?? $doc['pedido_id'] ?? 0);
  if ($kind === 'dps') {
    $xml = trim((string)($doc['signed_dps_xml'] ?? $doc['dps_xml'] ?? ''));
    $prefix = 'dps-assinada';
  } else {
    $xml = trim((string)($doc['response_xml'] ?? $doc['xml_raw'] ?? ''));
    $prefix = 'nfse-autorizada';
  }
  if ($xml === '') throw new RuntimeException($kind === 'dps' ? 'A DPS ainda não foi gerada.' : 'O XML autorizado da NFS-e ainda não está disponível.');
  $name = $prefix . '-pedido-' . $pedidoId . '-doc-' . $docId . '.xml';
  header('Content-Type: application/xml; charset=UTF-8');
  header('X-Content-Type-Options: nosniff');
  header('Content-Disposition: attachment; filename="' . $name . '"');
  echo $xml;
  exit;
}

$action = strtolower(trim((string)($_POST['action'] ?? $_GET['action'] ?? '')));
$docId = max(0, (int)($_POST['doc_id'] ?? $_GET['doc_id'] ?? 0));
$pedidoId = max(0, (int)($_POST['pedido_id'] ?? $_GET['pedido_id'] ?? 0));
$fallback = $pedidoId > 0 ? ('fiscal_nfse_nacional.php?pedido_id=' . $pedidoId) : 'fiscal_nfse_nacional.php';
$returnTo = nfse_action_safe_return((string)($_POST['return_to'] ?? $_GET['return_to'] ?? ''), $fallback);

try {
  $providerCode = nfse_provider_company_code($pdo, $cid);
  if ($providerCode === 'PRONIM') nfse_pronim_require_schema($pdo); else nfse_emitter_require_schema($pdo);
  nfse_action_f11_guard($pdo, $cid, $nfseF11, $action, $docId, $pedidoId);

  if (in_array($action, ['download_dps','download_nfse'], true)) {
    if ($docId <= 0) throw new RuntimeException('Documento NFS-e não informado.');
    $doc = nfse_emitter_document_by_id($pdo, $cid, $docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado nesta empresa.');
    nfse_action_xml_download($doc, $action === 'download_dps' ? 'dps' : 'nfse');
  }

  if ($action === 'download_cancel_event') {
    if ($docId <= 0) throw new RuntimeException('Documento NFS-e não informado.');
    $st = $pdo->prepare("SELECT response_xml FROM fiscal_document_events WHERE company_id=? AND fiscal_document_id=? AND event_type='CANCELAMENTO_NFSE' AND COALESCE(response_xml,'')<>'' ORDER BY id DESC LIMIT 1");
    $st->execute([$cid,$docId]);
    $xml = trim((string)$st->fetchColumn());
    if ($xml === '') throw new RuntimeException('XML do evento de cancelamento ainda não está disponível.');
    header('Content-Type: application/xml; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    header('Content-Disposition: attachment; filename="evento-cancelamento-nfse-doc-' . $docId . '.xml"');
    echo $xml;
    exit;
  }

  if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Ação inválida.');
  nfse_emitter_csrf_check((string)($_POST['csrf_token'] ?? ''), 'nfse_emitter');

  if ($action === 'prepare') {
    if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado.');
    $doc = nfse_provider_prepare_order($pdo, $cid, $pedidoId);
    nfse_action_redirect($returnTo, 'ok', 'DPS #' . (int)($doc['dps_number'] ?? $doc['number'] ?? 0) . ' gerada, validada e assinada. Confira os dados e transmita para homologação.');
  }

  if (in_array($action, ['emit','emit_quick'], true)) {
    // O fluxo manual mantém a confirmação textual em produção. No emit_quick,
    // o clique explícito em "Emitir NFS-e" é a confirmação desta requisição.
    if ($action === 'emit_quick') {
      if ($pedidoId <= 0) throw new RuntimeException('Pedido não informado para emissão direta da NFS-e.');
      $result = nfse_quick_authorize_order($pdo, $cid, $pedidoId);
      $docIdQuick = nfse_quick_document_id($pdo, $cid, $pedidoId, $result);
      $number = trim((string)($result['nfse_number'] ?? $result['document']['nfse_number'] ?? ''));
      $message = 'NFS-e autorizada';
      if ($number !== '') $message .= ' sob o número ' . $number;
      $message .= '. O documento está disponível para impressão e envio.';
      $target = 'fiscal_nfse_nacional.php?pedido_id=' . $pedidoId;
      if ($nfseF11 && function_exists('multi_company_context_url')) $target = multi_company_context_url($target);
      nfse_action_redirect($target, 'ok', $message);
    }

    // Produção Pronim/GOVBR exige confirmação explícita em cada transmissão.
    // A liberação vale somente para esta requisição PHP e não altera o emissor Nacional.
    $emitProvider = $docId > 0 ? nfse_provider_document_code($pdo, $cid, $docId) : nfse_provider_company_code($pdo, $cid);
    $emitEnvironment = '';
    if ($docId > 0) {
      $docForConfirmation = nfse_emitter_document_by_id($pdo, $cid, $docId);
      if (!$docForConfirmation) throw new RuntimeException('Documento NFS-e não encontrado para confirmação de produção.');
      $emitEnvironment = strtoupper(trim((string)($docForConfirmation['environment'] ?? '')));
    } elseif ($pedidoId > 0) {
      $cfgForConfirmation = fiscal_company_config($pdo, $cid);
      $emitEnvironment = strtoupper(trim((string)($cfgForConfirmation['environment'] ?? 'HOMOLOGACAO')));
    }
    if ($emitProvider === 'PRONIM' && $emitEnvironment === 'PRODUCAO') {
      // A ação POST autenticada e protegida por CSRF, confirmada no botão de emissão,
      // substitui a digitação manual de EMITIR. A liberação vale só nesta requisição.
      if (!defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) {
        define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);
      }
    }

    // Em emissão de um documento já existente, conferir o vínculo real com o
    // pedido faturado. Para O.S., fiscal_documents.source_id é o ID da O.S.,
    // enquanto fiscal_nfse_details.pedido_id identifica o pedido comercial.
    if ($docId > 0) {
      $docToEmit = nfse_emitter_document_by_id($pdo, $cid, $docId);
      if (!$docToEmit) throw new RuntimeException('Documento fiscal não encontrado na empresa atual.');
      $linkedOrderId = (int)($docToEmit['pedido_id'] ?? 0);
      $sourceType = strtoupper(trim((string)($docToEmit['source_type'] ?? '')));
      if ($sourceType === 'ORDEM_SERVICO' && $linkedOrderId <= 0) {
        throw new RuntimeException('Documento de O.S. sem vínculo ao pedido faturado. Emissão bloqueada.');
      }
      if ($sourceType === 'PEDIDO' && $linkedOrderId <= 0) {
        $linkedOrderId = (int)($docToEmit['source_id'] ?? 0);
      }
      if ($linkedOrderId <= 0 || ($pedidoId > 0 && $pedidoId !== $linkedOrderId)) {
        throw new RuntimeException('Pedido informado não corresponde ao vínculo fiscal do documento. Emissão bloqueada.');
      }
    }
    $result = $docId > 0
      ? nfse_provider_authorize_document($pdo, $cid, $docId)
      : (($pedidoId > 0) ? nfse_provider_authorize_order($pdo, $cid, $pedidoId) : null);
    if (!is_array($result)) throw new RuntimeException('Pedido ou documento NFS-e não informado.');
    if (!empty($result['ok'])) {
      $number = trim((string)($result['nfse_number'] ?? $result['document']['nfse_number'] ?? ''));
      $message = 'NFS-e autorizada no ' . nfse_provider_label(nfse_provider_document_code($pdo, $cid, (int)($result['document']['id'] ?? $docId))) . '';
      if ($number !== '') $message .= ' sob o número ' . $number;
      $message .= '. O XML foi armazenado e vinculado ao pedido.';
      nfse_action_redirect($returnTo, 'ok', $message);
    }
    $message = (string)($result['message'] ?? 'A DPS não foi autorizada.');
    if (!empty($result['ambiguous'])) $message .= ' Não gere outra DPS: utilize Recuperar por DPS.';
    nfse_action_redirect($returnTo, 'err', $message);
  }

  if ($action === 'cancel') {
    if ($docId <= 0) throw new RuntimeException('Documento NFS-e não informado para cancelamento.');
    $doc = nfse_emitter_document_by_id($pdo, $cid, $docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
    $cancelProvider = nfse_provider_document_code($pdo,$cid,$docId);
    $env = strtoupper(trim((string)($doc['environment'] ?? '')));
    if ($env === 'PRODUCAO') {
      $confirmation = strtoupper(trim((string)($_POST['cancel_confirmation'] ?? '')));
      if ($confirmation !== 'CANCELAR') throw new RuntimeException('Confirmação inválida. Digite exatamente CANCELAR para registrar o evento fiscal em produção.');
      if (!defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);
    }
    $reasonCode = trim((string)($_POST['cancel_reason_code'] ?? ''));
    $reason = trim((string)($_POST['cancel_reason'] ?? ''));
    $result = nfse_provider_cancel_document($pdo,$cid,$docId,$reasonCode,$reason);
    if (!empty($result['ok'])) nfse_action_redirect($returnTo,'ok',($cancelProvider === 'PRONIM' ? 'NFS-e cancelada com sucesso no Pronim / GOVBR - ISS Digital.' : 'NFS-e cancelada com sucesso no Ambiente Nacional.') . ' O XML do evento foi armazenado no histórico fiscal.');
    $message = (string)($result['message'] ?? 'O cancelamento não foi confirmado.');
    if (!empty($result['ambiguous'])) $message .= ' Não repita o cancelamento sem consultar a situação no provedor/portal.';
    nfse_action_redirect($returnTo,'err',$message);
  }

  if ($action === 'recover') {
    if ($docId <= 0) throw new RuntimeException('Documento NFS-e não informado para recuperação.');
    $result = nfse_provider_recover_by_dps($pdo, $cid, $docId);
    if (!empty($result['ok'])) {
      nfse_action_redirect($returnTo, 'ok', 'NFS-e localizada pelo identificador da DPS e sincronizada com o Nexa.');
    }
    nfse_action_redirect($returnTo, 'err', (string)($result['message'] ?? 'A NFS-e ainda não foi localizada para esta DPS.'));
  }

  if ($action === 'query') {
    if ($docId <= 0) throw new RuntimeException('Documento NFS-e não informado para consulta.');
    $doc = nfse_emitter_document_by_id($pdo, $cid, $docId);
    if (!$doc) throw new RuntimeException('Documento NFS-e não encontrado.');
    $key = (string)($doc['access_key'] ?? '');
    if ($key === '') throw new RuntimeException('A chave de acesso ainda não está disponível. Use Recuperar por DPS.');
    $result = nfse_provider_query_by_key($pdo, $cid, $docId, $key);
    nfse_action_redirect($returnTo, !empty($result['ok']) ? 'ok' : 'err', !empty($result['ok']) ? 'Consulta concluída. O XML autorizado foi atualizado.' : (string)($result['message'] ?? 'A consulta não foi concluída.'));
  }

  throw new RuntimeException('Ação da NFS-e não reconhecida.');
} catch (Throwable $e) {
  if (in_array($action, ['download_dps','download_nfse','download_cancel_event'], true)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=UTF-8');
    echo $e->getMessage();
    exit;
  }
  nfse_action_redirect($returnTo, 'err', $e->getMessage());
}
