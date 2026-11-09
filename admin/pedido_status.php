<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/brain_profitability.php';
require_once __DIR__ . '/../config/order_cancellation.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';

// Diagnóstico temporário de desempenho: apenas mede, sem alterar o fluxo.
$nexaPerfStarted = microtime(true);
$nexaPerfSchemaStart = microtime(true);
try { brain_profitability_ensure_schema($pdo, true); } catch (Throwable $e) {}
$nexaPerfSchemaMs = round((microtime(true) - $nexaPerfSchemaStart) * 1000);
$nexaPerfWaMs = 0;
$nexaPerfDbMs = 0;
register_shutdown_function(static function () use (&$nexaPerfStarted, &$nexaPerfSchemaMs, &$nexaPerfWaMs, &$nexaPerfDbMs): void {
  $totalMs = round((microtime(true) - $nexaPerfStarted) * 1000);
  if ($totalMs >= 800 || $nexaPerfSchemaMs >= 250 || $nexaPerfWaMs >= 250) {
    error_log('[NEXA_PEDIDO_PERF] total_ms='.$totalMs.' schema_ms='.$nexaPerfSchemaMs.' status_operation_ms='.$nexaPerfDbMs.' whatsapp_ms='.$nexaPerfWaMs);
  }
});

if (!function_exists('pedido_admin_table_exists')) {
  function pedido_admin_table_exists(PDO $pdo, string $table): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('pedido_admin_column_exists')) {
  function pedido_admin_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}


if (!function_exists('pedido_status_authorized_fiscal_documents')) {
  function pedido_status_authorized_fiscal_documents(PDO $pdo, int $companyId, int $orderId): array {
    if (!pedido_admin_table_exists($pdo, 'fiscal_documents')) return [];
    $providerJoin = pedido_admin_table_exists($pdo, 'fiscal_nfse_details')
      ? " LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id "
      : '';
    $providerSelect = $providerJoin !== '' ? ', nd.provider_code' : ", NULL AS provider_code";
    $st = $pdo->prepare("SELECT d.id,d.model,d.status,d.environment,d.access_key,d.number,d.series" . $providerSelect . " FROM fiscal_documents d" . $providerJoin . " WHERE d.company_id=? AND d.source_type='PEDIDO' AND d.source_id=? AND UPPER(COALESCE(d.status,''))='AUTORIZADA' ORDER BY d.id ASC");
    $st->execute([$companyId,$orderId]);
    $found=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Inclui notas da fila exclusiva da O.S. e notas associadas pelo pedido.
    if(pedido_admin_table_exists($pdo,'os_orders') && pedido_admin_table_exists($pdo,'fiscal_nfse_details')){
      $extra=$pdo->prepare("SELECT DISTINCT d.id,d.model,d.status,d.environment,d.access_key,d.number,d.series,nd.provider_code FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id WHERE d.company_id=? AND UPPER(COALESCE(d.status,''))='AUTORIZADA' AND ((d.source_type='ORDEM_SERVICO' AND d.source_id IN (SELECT id FROM os_orders WHERE company_id=? AND pedido_id=?)) OR (nd.pedido_id=?))");
      $extra->execute([$companyId,$companyId,$orderId,$orderId]);
      $seen=array_fill_keys(array_map(static fn($v)=>(int)$v['id'],$found),true);
      foreach($extra->fetchAll(PDO::FETCH_ASSOC)?:[] as $doc){if(empty($seen[(int)$doc['id']])){$seen[(int)$doc['id']]=true;$found[]=$doc;}}
    }
    return $found;
  }
}

if (!function_exists('pedido_status_latest_nfse_document')) {
  function pedido_status_latest_nfse_document(PDO $pdo, int $companyId, int $orderId): ?array {
    if (!pedido_admin_table_exists($pdo, 'fiscal_documents')) return null;
    $st = $pdo->prepare("SELECT id,model,status,environment,access_key,number,series FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? AND model='NFSE' ORDER BY id DESC LIMIT 1");
    $st->execute([$companyId,$orderId]);
    $latest=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if(pedido_admin_table_exists($pdo,'os_orders') && pedido_admin_table_exists($pdo,'fiscal_nfse_details')){
      $extra=$pdo->prepare("SELECT d.id,d.model,d.status,d.environment,d.access_key,d.number,d.series FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id WHERE d.company_id=? AND d.model='NFSE' AND ((d.source_type='ORDEM_SERVICO' AND d.source_id IN (SELECT id FROM os_orders WHERE company_id=? AND pedido_id=?)) OR nd.pedido_id=?) ORDER BY d.id DESC LIMIT 1");
      $extra->execute([$companyId,$companyId,$orderId,$orderId]);$doc=$extra->fetch(PDO::FETCH_ASSOC)?:null;
      if($doc && (!$latest || (int)$doc['id']>(int)$latest['id']))$latest=$doc;
    }
    return $latest;
  }
}

if (!function_exists('pedido_status_fiscal_cancel_reason')) {
  function pedido_status_fiscal_cancel_reason(string $reason, string $orderNumber): string {
    $reason = trim($reason);
    if ($reason === '') $reason = 'Cancelamento do pedido #' . $orderNumber;
    $len = function_exists('mb_strlen') ? mb_strlen($reason,'UTF-8') : strlen($reason);
    if ($len < 15) $reason .= ' - cancelamento do pedido #' . $orderNumber;
    return function_exists('mb_substr') ? mb_substr($reason,0,255,'UTF-8') : substr($reason,0,255);
  }
}

if (!function_exists('pedido_status_assert_local_cancel_preflight')) {
  function pedido_status_assert_local_cancel_preflight(PDO $pdo, int $companyId, int $orderId): void {
    if (function_exists('billing_assert_order_can_cancel_locally')) {
      billing_assert_order_can_cancel_locally($pdo,$companyId,$orderId);
    }

    $deliveryFile = __DIR__ . '/../config/delivery_control.php';
    if (is_file($deliveryFile)) {
      require_once $deliveryFile;
      if (function_exists('delivery_control_schema_ready') && delivery_control_schema_ready($pdo)) {
        $st=$pdo->prepare('SELECT id FROM delivery_control_sales WHERE company_id=? AND pedido_id=? LIMIT 1');
        $st->execute([$companyId,$orderId]);
        $saleId=(int)($st->fetchColumn() ?: 0);
        if ($saleId > 0) {
          $st=$pdo->prepare("SELECT COUNT(*) FROM delivery_control_orders WHERE company_id=? AND delivery_sale_id=? AND status<>'CANCELADA'");
          $st->execute([$companyId,$saleId]);
          if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Este pedido possui Ordem(ns) de Entrega ativa(s). Cancele/regularize todas as O.E. antes de cancelar a venda comercial.');
          try {
            $st=$pdo->prepare("SELECT q.status,fd.status fiscal_status FROM delivery_control_fiscal_queue q LEFT JOIN fiscal_documents fd ON fd.company_id=q.company_id AND fd.id=q.fiscal_document_id WHERE q.company_id=? AND q.delivery_sale_id=?");
            $st->execute([$companyId,$saleId]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) {
              $qs=strtoupper((string)($q['status'] ?? '')); $fs=strtoupper((string)($q['fiscal_status'] ?? ''));
              if ($qs==='PROCESSANDO') throw new RuntimeException('Existe documento fiscal da entrega em processamento. Aguarde/regularize o fiscal antes de cancelar o pedido.');
              if ($qs==='AUTORIZADA' && !in_array($fs,['CANCELADA','CANCELADO'],true)) throw new RuntimeException('Existe documento fiscal autorizado referente a uma saída parcial. Cancele primeiro o documento fiscal antes de cancelar o pedido comercial.');
            }
          } catch (PDOException $e) {
            // Bases sem a estrutura completa serão validadas novamente dentro de nexa_cancel_order().
          }
        }
      }
    }
  }
}

if (!function_exists('pedido_admin_cancel_finance_movements')) {
  function pedido_admin_cancel_finance_movements(PDO $pdo, int $companyId, array $ids, string $reason): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$ids || !pedido_admin_table_exists($pdo, 'finance_movements') || !pedido_admin_column_exists($pdo, 'finance_movements', 'status')) return;
    $set = ["status='CANCELADO'"];
    $params = [];
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'canceled_at')) $set[] = 'canceled_at=COALESCE(canceled_at, NOW())';
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'canceled_reason')) { $set[] = "canceled_reason=COALESCE(NULLIF(canceled_reason,''), ?)"; $params[] = $reason; }
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'updated_at')) $set[] = 'updated_at=NOW()';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params[] = $companyId;
    $params = array_merge($params, $ids);
    $pdo->prepare('UPDATE finance_movements SET ' . implode(', ', $set) . " WHERE company_id=? AND id IN ($ph) AND COALESCE(status,'ATIVO') <> 'CANCELADO'")->execute($params);
  }
}

if (!function_exists('pedido_admin_cancel_finance_movements_by_order')) {
  function pedido_admin_cancel_finance_movements_by_order(PDO $pdo, int $companyId, int $orderId, string $reason): void {
    if (!pedido_admin_table_exists($pdo, 'finance_movements') || !pedido_admin_column_exists($pdo, 'finance_movements', 'status')) return;
    $set = ["status='CANCELADO'"];
    $params = [];
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'canceled_at')) $set[] = 'canceled_at=COALESCE(canceled_at, NOW())';
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'canceled_reason')) { $set[] = "canceled_reason=COALESCE(NULLIF(canceled_reason,''), ?)"; $params[] = $reason; }
    if (pedido_admin_column_exists($pdo, 'finance_movements', 'updated_at')) $set[] = 'updated_at=NOW()';
    array_push($params, $companyId, $orderId);
    $pdo->prepare('UPDATE finance_movements SET ' . implode(', ', $set) . " WHERE company_id=? AND origin_type='PEDIDO' AND origin_id=? AND COALESCE(status,'ATIVO') <> 'CANCELADO'")->execute($params);
  }
}

if (!function_exists('pedido_admin_cancel_finance_receivables')) {
  function pedido_admin_cancel_finance_receivables(PDO $pdo, int $companyId, array $ids): void {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn($v) => $v > 0)));
    if (!$ids || !pedido_admin_table_exists($pdo, 'finance_receivables') || !pedido_admin_column_exists($pdo, 'finance_receivables', 'status')) return;
    $set = ["status='CANCELADO'"];
    if (pedido_admin_column_exists($pdo, 'finance_receivables', 'updated_at')) $set[] = 'updated_at=NOW()';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    $pdo->prepare('UPDATE finance_receivables SET ' . implode(', ', $set) . " WHERE company_id=? AND id IN ($ph) AND COALESCE(status,'') <> 'CANCELADO'")->execute($params);
  }
}

if (!function_exists('pedido_admin_cancel_related_finance')) {
  /**
   * Cancela os vínculos financeiros gerados pelo PDV quando o pedido é cancelado.
   * A rotina é defensiva para bases antigas: se alguma tabela/coluna não existir,
   * apenas ignora aquela parte e mantém o cancelamento do pedido.
   */
  function pedido_admin_cancel_related_finance(PDO $pdo, int $companyId, int $orderId, string $reason = ''): void {
    if ($companyId <= 0 || $orderId <= 0) return;
    $reason = trim($reason) !== '' ? trim($reason) : ('Pedido #' . $orderId . ' cancelado pela troca de status.');

    try {
      if (!pedido_admin_table_exists($pdo, 'gestao_order_payments')) return;

      $movementIds = [];
      $receivableIds = [];

      $receivableIdsSelect = pedido_admin_column_exists($pdo, 'gestao_order_payments', 'receivable_ids') ? ', receivable_ids' : '';
      $st = $pdo->prepare('SELECT finance_movement_id, receivable_id' . $receivableIdsSelect . ' FROM gestao_order_payments WHERE company_id = ? AND order_id = ?');
      $st->execute([$companyId, $orderId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
        $mid = (int)($row['finance_movement_id'] ?? 0);
        $rid = (int)($row['receivable_id'] ?? 0);
        if ($mid > 0) $movementIds[$mid] = $mid;
        if ($rid > 0) $receivableIds[$rid] = $rid;
        if (!empty($row['receivable_ids'])) {
          foreach (preg_split('/\D+/', (string)$row['receivable_ids']) ?: [] as $multiRid) {
            $multiRid = (int)$multiRid;
            if ($multiRid > 0) $receivableIds[$multiRid] = $multiRid;
          }
        }
      }

      if (pedido_admin_table_exists($pdo, 'finance_movements')) {
        // Movimentos de venda imediata vinculados diretamente ao pedido.
        pedido_admin_cancel_finance_movements($pdo, $companyId, array_values($movementIds), $reason);

        // Garantia extra para movimentos de origem PEDIDO, mesmo se não houver vínculo em gestao_order_payments.
        pedido_admin_cancel_finance_movements_by_order($pdo, $companyId, $orderId, $reason);
      }

      if ($receivableIds && pedido_admin_table_exists($pdo, 'finance_receivables')) {
        pedido_admin_cancel_finance_receivables($pdo, $companyId, array_values($receivableIds));

        // Se o título já tinha baixa, cancela também os movimentos gerados por essas baixas.
        $ph = implode(',', array_fill(0, count($receivableIds), '?'));
        if (pedido_admin_table_exists($pdo, 'finance_settlements') && pedido_admin_table_exists($pdo, 'finance_movements')) {
          $stSet = $pdo->prepare("SELECT movement_id FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id IN ($ph) AND COALESCE(status,'ATIVO')='ATIVO' AND movement_id IS NOT NULL");
          $stSet->execute(array_merge([$companyId], array_values($receivableIds)));
          $settlementMovementIds = [];
          foreach (($stSet->fetchAll(PDO::FETCH_COLUMN) ?: []) as $mid) {
            $mid = (int)$mid;
            if ($mid > 0) $settlementMovementIds[$mid] = $mid;
          }
          pedido_admin_cancel_finance_movements($pdo, $companyId, array_values($settlementMovementIds), $reason);
        }
      }
    } catch (Throwable $e) {
      // Não impede a troca de status do pedido; apenas evita quebrar bases sem financeiro completo.
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
  header('Location: pedidos.php');
  exit;
}

$id = (int)($_POST['id'] ?? 0);
$status = (string)($_POST['status'] ?? '');
$cancelReason = trim((string)($_POST['cancel_reason'] ?? ''));
$autoCancelFiscalConfirmed = (string)($_POST['auto_cancel_nfse'] ?? '') === '1';
$returnTo = trim((string)($_POST['return_to'] ?? ''));

$allowed = ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'];
if ($id <= 0 || !in_array($status, $allowed, true)) {
  header('Location: pedidos.php');
  exit;
}

$stOld = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND company_id = ? LIMIT 1');
$stOld->execute([$id, COMPANY_ID]);
$pedidoAntes = $stOld->fetch(PDO::FETCH_ASSOC) ?: null;
$statusAnterior = (string)($pedidoAntes['status'] ?? '');
$statusResultMessage = '';

$nexaPerfOperationStart = microtime(true);
try {
  if ($status === 'cancelado') {
    $pedidoNumeroCancelado = $pedidoAntes ? company_sequence_display($pedidoAntes, 'pedidos') : (string)$id;
    if ($cancelReason === '') $cancelReason = 'Pedido #' . $pedidoNumeroCancelado . ' cancelado pela troca de status.';

    // Antes de chamar uma API fiscal externa, valida bloqueios locais que sabemos antecipadamente.
    pedido_status_assert_local_cancel_preflight($pdo,(int)COMPANY_ID,$id);

    // Se houver documento fiscal autorizado que ainda não possui cancelamento automático neste fluxo,
    // bloqueia ANTES de cancelar a NFS-e para evitar uma situação fiscal parcialmente cancelada.
    $authorizedFiscalDocs = pedido_status_authorized_fiscal_documents($pdo,(int)COMPANY_ID,$id);
    foreach ($authorizedFiscalDocs as $fiscalDoc) {
      $model = strtoupper(trim((string)($fiscalDoc['model'] ?? '')));
      if ($model === 'NFSE') continue;
      $label = $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'documento fiscal');
      throw new RuntimeException('O pedido possui ' . $label . ' autorizada. Cancele esse documento fiscal antes de cancelar o pedido.');
    }

    $canceledNfseDocId = null;
    foreach ($authorizedFiscalDocs as $fiscalDoc) {
      if (strtoupper(trim((string)($fiscalDoc['model'] ?? ''))) !== 'NFSE') continue;
      $docId = (int)($fiscalDoc['id'] ?? 0);
      if ($docId <= 0) continue;
      if (!$autoCancelFiscalConfirmed) {
        throw new RuntimeException('Confirme o cancelamento fiscal automático da NFS-e antes de cancelar o pedido.');
      }
      if (strtoupper(trim((string)($fiscalDoc['environment'] ?? ''))) === 'PRODUCAO' && !defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) {
        define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);
      }
      $fiscalReason = pedido_status_fiscal_cancel_reason($cancelReason,$pedidoNumeroCancelado);
      $result = nfse_provider_cancel_document($pdo,(int)COMPANY_ID,$docId,'9',$fiscalReason);
      if (empty($result['ok'])) {
        $message = trim((string)($result['message'] ?? 'O ISS Digital/Pronim não confirmou o cancelamento da NFS-e.'));
        if (!empty($result['ambiguous'])) $message .= ' O pedido não foi cancelado; consulte a situação fiscal antes de repetir.';
        throw new RuntimeException($message);
      }
      $canceledNfseDocId = $docId;
    }

    // Se a NFS-e já havia sido cancelada manualmente, mantém o vínculo no histórico do pedido.
    if (!$canceledNfseDocId) {
      $latestNfse = pedido_status_latest_nfse_document($pdo,(int)COMPANY_ID,$id);
      if ($latestNfse && strtoupper(trim((string)($latestNfse['status'] ?? ''))) === 'CANCELADA') $canceledNfseDocId = (int)$latestNfse['id'];
    }

    // Neste ponto qualquer NFS-e Pronim autorizada já foi cancelada. O cancelamento comercial
    // continua usando o motor existente, incluindo estoque, financeiro, caixa, boletos e entrega futura.
    nexa_cancel_order($pdo, (int)COMPANY_ID, $id, $cancelReason, 'MANUAL', $canceledNfseDocId, (int)(auth_user_id() ?? 0));
    $statusResultMessage = $canceledNfseDocId
      ? 'Pedido cancelado com sucesso. A NFS-e Pronim/GOVBR vinculada foi cancelada fiscalmente antes do estorno do pedido.'
      : 'Pedido cancelado com sucesso.';
  } else {
    $pdo->beginTransaction();
    $st = $pdo->prepare('UPDATE pedidos SET status = ? WHERE id = ? AND company_id = ?');
    $st->execute([$status, $id, COMPANY_ID]);
    if ($pedidoAntes) brain_profitability_sync_order_status($pdo, (int)COMPANY_ID, $id, $status, $statusAnterior);
    $pdo->commit();
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  error_log('[Pedido status] ' . $e->getMessage());
  $errorReturn = $returnTo !== '' ? $returnTo : 'pedidos.php';
  $sep = str_contains($errorReturn, '?') ? '&' : '?';
  header('Location: ' . $errorReturn . $sep . 'err=' . rawurlencode($e->getMessage()));
  exit;
}

$nexaPerfDbMs = round((microtime(true) - $nexaPerfOperationStart) * 1000);
$nexaPerfWaStart = microtime(true);
if ($pedidoAntes && $status !== $statusAnterior) {
  try {
    $pedidoAntes['status'] = $status;
    $waCfg = whatsapp_load_config($pdo, COMPANY_ID);
    $waCfg['timeout_seconds'] = max(30, (int)($waCfg['timeout_seconds'] ?? 30));
    if (!empty($waCfg['enabled']) && !empty($waCfg['send_status_to_customer'])) {
      $appWa = is_array($APP_CFG ?? null) ? $APP_CFG : [];
      try {
        $bizWa = load_business_config((int)COMPANY_ID);
        $catalogTitle = trim((string)($bizWa['brand']['catalog_title'] ?? ''));
        if ($catalogTitle !== '') {
          $appWa['site_name'] = $catalogTitle;
          if (!isset($appWa['brand']) || !is_array($appWa['brand'])) $appWa['brand'] = [];
          $appWa['brand']['catalog_title'] = $catalogTitle;
        }
      } catch (Throwable $eBiz) {}
      $msg = whatsapp_build_status_message($pedidoAntes, $status, $appWa, $waCfg);
      // Fila: a troca de status termina na hora; o envio é feito em segundo plano.
      whatsapp_queue_message($pdo, COMPANY_ID, (string)($pedidoAntes['whatsapp'] ?? ''), $msg, 'status_customer', $id, $waCfg);
    }
  } catch (Throwable $e) {
    try {
      whatsapp_log($pdo, COMPANY_ID, $id, 'status_customer', (string)($pedidoAntes['whatsapp'] ?? ''), '', 'error', $e->getMessage());
    } catch (Throwable $e2) {}
  }
}

$nexaPerfWaMs = round((microtime(true) - $nexaPerfWaStart) * 1000);
if ($returnTo !== '') {
  $returnPath = parse_url($returnTo, PHP_URL_PATH) ?: $returnTo;
  $returnPath = ltrim((string)$returnPath, '/');
  if (preg_match('/^(pedidos\.php|gestao_pedidos\.php|pedido\.php)(\?.*)?$/', $returnTo) || in_array($returnPath, ['pedidos.php','gestao_pedidos.php','pedido.php'], true)) {
    if ($statusResultMessage !== '' && (str_starts_with($returnTo, 'pedidos.php') || $returnPath === 'pedidos.php')) {
      $sep = str_contains($returnTo, '?') ? '&' : '?';
      $returnTo .= $sep . 'ok=' . rawurlencode($statusResultMessage);
    }
    header('Location: ' . $returnTo);
    exit;
  }
}

$back = (string)($_SERVER['HTTP_REFERER'] ?? '');
if ($back) {
  $path = parse_url($back, PHP_URL_PATH) ?: '';
  if (str_ends_with($path, '/pedido.php') || str_ends_with($path, '/gestao_pedidos.php') || str_ends_with($path, '/pedidos.php')) {
    header('Location: ' . $back);
    exit;
  }
}

header('Location: pedidos.php?status_ok=1' . ($statusResultMessage !== '' ? ('&ok=' . rawurlencode($statusResultMessage)) : ''));
exit;
