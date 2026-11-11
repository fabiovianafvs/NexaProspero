<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/commercial_rules.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/itau_boleto_v3.php';
require_once __DIR__ . '/../config/itau_webhook.php';
require_once __DIR__ . '/../config/screen_performance.php';
require_once __DIR__ . '/../config/cash_receivables_integration.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
$financeCrossTitleWrite = function_exists('finance_multi_company_title_write_enabled') && finance_multi_company_title_write_enabled($pdo);
$financeCrossSettlementWrite = function_exists('finance_multi_company_settlement_write_enabled') && finance_multi_company_settlement_write_enabled($pdo);
$financeCrossItauSettlement = function_exists('finance_multi_company_itau_settlement_enabled') && finance_multi_company_itau_settlement_enabled($pdo);
$financeCrossBillingCancel = function_exists('finance_multi_company_billing_cancel_enabled') && finance_multi_company_billing_cancel_enabled($pdo);
$financeCrossBillingSend = function_exists('finance_multi_company_billing_send_enabled') && finance_multi_company_billing_send_enabled($pdo);
$financeCrossReversalWrite = function_exists('finance_multi_company_reversal_write_enabled') && finance_multi_company_reversal_write_enabled($pdo);
$financeCrossReadOnly = function_exists('finance_multi_company_readonly') && finance_multi_company_readonly($pdo);
$cashCrActiveForCompany = nexa_cash_cr_enabled($pdo, $cid);
$cashCrOpenForUser = $cashCrActiveForCompany ? nexa_cash_cr_open_session($pdo, $cid, (int)(auth_user_id() ?? 0)) : null;
if (!$financeCrossContext) {
  try { commercial_ensure_schema($pdo); } catch (Throwable $ignored) {}
  try { billing_ensure_schema($pdo); } catch (Throwable $ignored) {}
}


// Sicoob V2.3: LIQUI com juros/multa/descontos/abatimentos e tarifa apartada, baixa assistida.
// Os pedidos de movimentação são assíncronos. Reaproveita a solicitação recente para
// não criar outra ao clicar em Sincronizar novamente após uma resposta pendente.
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_sicoob_sync_csrf'])) {
  $_SESSION['nexa_sicoob_sync_csrf'] = bin2hex(random_bytes(20));
}
$sicoobSyncCsrf = session_status() === PHP_SESSION_ACTIVE ? (string)($_SESSION['nexa_sicoob_sync_csrf'] ?? '') : '';
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array((string)($_POST['action'] ?? ''), ['sicoob_sync_preview_v174','sicoob_sync_settle_v180'], true)) {
  $sicoobSettle = (string)($_POST['action'] ?? '') === 'sicoob_sync_settle_v180';
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store, private');
  try {
    if ($financeCrossContext) throw new RuntimeException('A sincronização Sicoob está disponível somente na empresa ativa.');
    if ($sicoobSettle && ((string)($_POST['confirm_settle_sicoob'] ?? '') !== 'CONFIRMAR_BAIXA_SICOOB' || !function_exists('finance_effective_permission_allowed') || !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber'))) {
      throw new RuntimeException('Confirmação e permissão de baixa Sicoob obrigatórias.');
    }
    if ($sicoobSyncCsrf === '' || !hash_equals($sicoobSyncCsrf, (string)($_POST['sicoob_sync_csrf'] ?? ''))) {
      throw new RuntimeException('Sessão expirada. Recarregue a página antes de consultar novamente.');
    }
    if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_boletos')) {
      throw new RuntimeException('Sem permissão para consultar cobranças e boletos.');
    }
    $bankId = (int)($_POST['itau_bank_account_id'] ?? 0);
    if ($bankId <= 0) throw new RuntimeException('Selecione uma conta Sicoob.');
    $b = $pdo->prepare('SELECT id FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1');
    $b->execute([$cid, $bankId]);
    if (!$b->fetchColumn()) throw new RuntimeException('Conta não encontrada ou inativa na empresa selecionada.');
    $cfg = billing_bank_config($pdo, $cid, $bankId);
    if (!$cfg || empty($cfg['enabled']) || strtoupper(trim((string)($cfg['provider'] ?? ''))) !== 'SICOOB'
        || strtoupper(trim((string)($cfg['integration_mode'] ?? ''))) !== 'API'
        || (int)($cfg['company_id'] ?? 0) !== $cid || (int)($cfg['bank_account_id'] ?? 0) !== $bankId) {
      throw new RuntimeException('A conta selecionada não está configurada como Sicoob/API desta empresa.');
    }
    require_once __DIR__ . '/../config/sicoob_liquidation_sync.php';
    $dateStart = trim((string)($_POST['liquidation_date_start'] ?? ''));
    $dateEnd = trim((string)($_POST['liquidation_date_end'] ?? ''));
    sicoob_v16_guard_dates($dateStart, $dateEnd);
    $sessionKey = 'sicoob_mov_'.$cid.'_'.$bankId;
    $previous = (array)($_SESSION[$sessionKey] ?? []);
    $reuse = ctype_digit((string)($previous['codigo'] ?? ''))
        && (string)($previous['inicio'] ?? '') === $dateStart
        && (string)($previous['fim'] ?? '') === $dateEnd
        && time() - (int)($previous['criado_em'] ?? 0) < 7200;
    if (!$reuse && $sicoobSettle) throw new RuntimeException('Prévia Sicoob vencida. Consulte novamente antes de confirmar a baixa.');
    if (!$reuse) {
      if (function_exists('set_time_limit')) @set_time_limit(90);
      $request = sicoob_v16_request($cfg, $pdo, $dateStart, $dateEnd);
      $previous = [
        'codigo'=>(string)$request['codigo_solicitacao'],
        'inicio'=>$dateStart, 'fim'=>$dateEnd, 'ids'=>[], 'criado_em'=>time(),
      ];
      $_SESSION[$sessionKey] = $previous;
    }
    $codigo = (string)$previous['codigo'];
    $status = sicoob_v16_status($cfg, $pdo, $codigo);
    $previous['ids'] = (array)($status['ids'] ?? []);
    $_SESSION[$sessionKey] = $previous;
    $files = count($previous['ids']);
    $canSettle = false;
    $matched = 0;
    $pending = 0;
    $already = 0;
    $lowered = 0;
    $details = [];
    $diagnostics = [];
    $feeRecords = 0; $feeTotal = 0.0; $readyCharges = [];
    if ($files > 0) {
      if (function_exists('set_time_limit')) @set_time_limit(180);
      $records = sicoob_liqui_get_records($cfg,$pdo,$codigo,$previous['ids']);
      $fingerprint=hash('sha256',json_encode($records,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
      $confirmKey='sicoob_liqui_confirmation_'.$cid.'_'.$bankId;
      if ($sicoobSettle) {
        $confirmed=(array)($_SESSION[$confirmKey]??[]);
        if (($confirmed['codigo']??'')!==$codigo || ($confirmed['fingerprint']??'')!==$fingerprint
            || ($confirmed['inicio']??'')!==$dateStart || ($confirmed['fim']??'')!==$dateEnd
            || time()-(int)($confirmed['time']??0)>1800) {
          throw new RuntimeException('O arquivo mudou ou a prévia expirou. Consulte novamente antes de efetuar a baixa.');
        }
        unset($_SESSION[$confirmKey]);
        $feesConfirmed=(string)($_POST['sicoob_fees_separate_confirmed']??'')==='TARIFAS_CONFERIDAS_SEPARADAMENTE';
        $result=sicoob_liqui_settle($pdo,$cid,$bankId,$cfg,$records,$feesConfirmed);
        $lowered=(int)$result['processed'];$already=(int)$result['already'];$pending=(int)$result['pending'];
        $details=(array)($result['errors']??[]);
        $message='Sicoob: '.$lowered.' baixa(s) confirmada(s), '.$already.' já baixada(s) e '.$pending.' pendência(s). Confira o Contas a Receber. '.($details ? 'Pendências: '.implode(' | ',array_slice($details,0,3)) : '');
        $state=$pending>0?'attention':'settled';
      } else {
        $result=sicoob_liqui_preview($pdo,$cid,$bankId,$cfg,$records);
        $matched=(int)$result['counts']['aptos'];$already=(int)$result['counts']['ja_baixados'];$pending=(int)$result['counts']['pendentes'];
        $diagnostics=(array)($result['diagnostics']??[]);
        $feeRecords=(int)($result['fee_records']??0);
        $feeTotal=(float)($result['fee_total']??0);
        $readyCharges=(array)($result['ready_charges']??[]);
        if ($matched>0) {
          $_SESSION[$confirmKey]=['codigo'=>$codigo,'fingerprint'=>$fingerprint,'inicio'=>$dateStart,'fim'=>$dateEnd,'time'=>time()];
          $canSettle=(strtoupper((string)($cfg['environment']??''))==='PRODUCAO')
            && (function_exists('sicoob_v15_company_enabled') && sicoob_v15_company_enabled($cid))
            && (function_exists('finance_effective_permission_allowed') && finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber'));
        } else unset($_SESSION[$confirmKey]);
        $message='Sicoob LIQUI: '.$files.' arquivo(s), '.count($records).' liquidação(ões). '
            .$matched.' apta(s) à baixa, '.$already.' já baixada(s) e '.$pending.' pendência(s). '
            .($canSettle?'Confirme a baixa para registrar somente as liquidações com correspondência exata.':'Nenhuma baixa executada.');
        if ($feeRecords>0) $message.=' '.$feeRecords.' liquidação(ões) possui(em) tarifa bancária informativa (total R$ '.number_format($feeTotal,2,',','.').'). As tarifas NÃO serão lançadas por esta sincronização e exigem conciliação separada.';
        $details=array_values(array_unique(array_filter(array_map(static fn($r)=>$r['status']==='PENDENTE'?(string)$r['motivo']:'',(array)$result['rows']))));
        if ($details) $message.=' Pendências: '.implode(' | ',array_slice($details,0,3));
        $state=$matched>0?'ready':'attention';
      }
    } elseif ($sicoobSettle) {
      throw new RuntimeException('Ainda não há arquivos LIQUI disponíveis. Nenhuma baixa efetuada.');
    } else {
      unset($_SESSION['sicoob_liqui_confirmation_'.$cid.'_'.$bankId]);
      $countRaw=trim((string)($status['quantidade_registros']??''));
      $count=preg_replace('/[^0-9]/','',$countRaw)??'';
      if ($count==='0' && in_array((int)($status['http']??0),[200,204],true)) {
        $message='Sicoob ainda não disponibilizou liquidações para esse período. Confira a data do pagamento, não a data de emissão do boleto. Nenhuma baixa executada.';
        $state='empty';
      } else {
        $message='Solicitação Sicoob em processamento. Tente consultar novamente o mesmo período em alguns instantes; o Nexa reutilizará o código da solicitação. Nenhuma baixa executada.';
        $state='pending';
      }
    }
    echo json_encode(['ok'=>true,'state'=>$state,'message'=>$message,'reused'=>$reuse,
      'title'=>$sicoobSettle?'Resultado das baixas Sicoob':'Conferência de liquidações Sicoob',
      'lowered'=>$lowered,'ready_to_settle'=>$canSettle,'matched'=>$matched,'pending'=>$pending,'already'=>$already,
      'diagnostics'=>array_slice($diagnostics,0,20),
      'ready_charges'=>$readyCharges,'fee_records'=>$feeRecords,'fee_total'=>$feeTotal], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }
  exit;
}

// V11.45.2: busca remota de clientes do filtro. Evita carregar milhares de pessoas
// em HTML toda vez que a tela de Contas a Receber é aberta.
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'person_lookup') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $lookupQ = trim((string)($_GET['q'] ?? ''));
    if ($lookupQ === '') {
      echo json_encode(['ok'=>true,'rows'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }
    if (function_exists('mb_substr')) $lookupQ = mb_substr($lookupQ, 0, 80, 'UTF-8');
    else $lookupQ = substr($lookupQ, 0, 80);
    $tokens = array_slice(preg_split('/\s+/u', $lookupQ, -1, PREG_SPLIT_NO_EMPTY) ?: [], 0, 5);
    $lookupWhere = ['company_id=?','active=1','COALESCE(is_customer,0)=1'];
    $lookupParams = [$cid];
    $hasPersonNumber = finance_column_exists($pdo, 'finance_people', 'numero_cadastro');
    foreach ($tokens as $token) {
      $like = nexa_search_like($token);
      $parts = ['name LIKE ?','fantasy_name LIKE ?','document LIKE ?','phone LIKE ?','whatsapp LIKE ?','email LIKE ?'];
      $tokenParams = array_fill(0, count($parts), $like);
      $digits = preg_replace('/\D+/', '', $token);
      if ($hasPersonNumber && $digits !== '') { $parts[] = 'numero_cadastro=?'; $tokenParams[] = (int)$digits; }
      elseif ($hasPersonNumber) { $parts[] = 'CAST(numero_cadastro AS CHAR) LIKE ?'; $tokenParams[] = $like; }
      $lookupWhere[] = '(' . implode(' OR ', $parts) . ')';
      array_push($lookupParams, ...$tokenParams);
    }
    $numberSelect = $hasPersonNumber ? 'numero_cadastro' : 'NULL AS numero_cadastro';
    $order = $hasPersonNumber ? 'COALESCE(numero_cadastro,id),name' : 'name,id';
    // Código digitado no campo Pessoa: busca exata pelo código do cadastro (ou ID).
    if ((string)($_GET['exact_code'] ?? '') === '1') {
      $codeDigits = preg_replace('/\D+/', '', $lookupQ);
      if ($codeDigits === '' || strlen($codeDigits) > 10) { echo json_encode(['ok'=>true,'rows'=>[]]); exit; }
      $lookupWhere = ['company_id=?','active=1','COALESCE(is_customer,0)=1', $hasPersonNumber ? '(numero_cadastro=? OR (numero_cadastro IS NULL AND id=?))' : 'id=?'];
      $lookupParams = $hasPersonNumber ? [$cid, (int)$codeDigits, (int)$codeDigits] : [$cid, (int)$codeDigits];
    }
    $addrCols = [];
    foreach (['address','number','district','city','state'] as $addrCol) $addrCols[] = finance_column_exists($pdo, 'finance_people', $addrCol) ? $addrCol : ('NULL AS ' . $addrCol);
    $lookupRows = finance_query_all($pdo, 'SELECT id,' . $numberSelect . ',name,document,phone,whatsapp,' . implode(',', $addrCols) . ' FROM finance_people WHERE ' . implode(' AND ', $lookupWhere) . ' ORDER BY ' . $order . ' LIMIT 40', $lookupParams);
    require_once __DIR__ . '/../config/lgpd.php';
    foreach ($lookupRows as &$lookupRow) {
      // LGPD: a busca devolve o documento mascarado e o endereço resumido.
      $lookupRow['document'] = nexa_lgpd_mask_document($lookupRow['document'] ?? '');
      $street = trim((string)($lookupRow['address'] ?? '') . (trim((string)($lookupRow['number'] ?? '')) !== '' ? ', ' . trim((string)$lookupRow['number']) : ''));
      $place = trim((string)($lookupRow['city'] ?? '') . (trim((string)($lookupRow['state'] ?? '')) !== '' ? '/' . trim((string)$lookupRow['state']) : ''));
      $lookupRow['address_text'] = implode(' · ', array_values(array_filter([$street, trim((string)($lookupRow['district'] ?? '')), $place], static fn($v) => $v !== '')));
      unset($lookupRow['address'], $lookupRow['number'], $lookupRow['district'], $lookupRow['city'], $lookupRow['state']);
    }
    unset($lookupRow);
    echo json_encode(['ok'=>true,'rows'=>$lookupRows], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'rows'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

// V11.51.3: consulta rápida dos produtos do pedido que originou a Conta a Receber.
// A operação é somente leitura e sempre respeita a empresa operacional (inclusive no F11).
if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string)($_GET['action'] ?? '') === 'title_products') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    $titleId = (int)($_GET['id'] ?? 0);
    if ($titleId <= 0) throw new RuntimeException('Título inválido.');

    $title = finance_query_one($pdo, 'SELECT * FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1', [$cid, $titleId]);
    if (!$title) throw new RuntimeException('Título não encontrado na empresa selecionada.');

    $orderId = 0;
    $linkSource = '';

    // Cobranças mais novas podem manter o pedido diretamente no título.
    if (function_exists('finance_column_exists') && finance_column_exists($pdo, 'finance_receivables', 'order_id')) {
      $candidateOrderId = (int)($title['order_id'] ?? 0);
      if ($candidateOrderId > 0) {
        $orderId = $candidateOrderId;
        $linkSource = 'finance_receivables.order_id';
      }
    }

    // Fluxo padrão do PDV: a relação título x pedido fica em gestao_order_payments.
    if ($orderId <= 0 && function_exists('finance_table_exists') && finance_table_exists($pdo, 'gestao_order_payments')) {
      if (function_exists('finance_column_exists') && finance_column_exists($pdo, 'gestao_order_payments', 'receivable_id')) {
        $paymentLink = finance_query_one($pdo, 'SELECT order_id FROM gestao_order_payments WHERE company_id=? AND receivable_id=? ORDER BY id DESC LIMIT 1', [$cid, $titleId]);
        if (!empty($paymentLink['order_id'])) {
          $orderId = (int)$paymentLink['order_id'];
          $linkSource = 'gestao_order_payments.receivable_id';
        }
      }

      // V11.51.3: vendas parceladas gravam a lista completa em receivable_ids.
      // Evita FIND_IN_SET/CAST no MySQL porque bases antigas podem ter collations diferentes
      // (utf8mb4_general_ci x utf8mb4_unicode_ci), o que gerava erro 1267 em títulos importados.
      if ($orderId <= 0 && function_exists('finance_column_exists') && finance_column_exists($pdo, 'gestao_order_payments', 'receivable_ids')) {
        $paymentCandidates = finance_query_all($pdo, 'SELECT order_id,receivable_ids FROM gestao_order_payments WHERE company_id=? AND receivable_ids IS NOT NULL AND CHAR_LENGTH(receivable_ids)>0 ORDER BY id DESC LIMIT 5000', [$cid]);
        $needleReceivableId = (string)$titleId;
        foreach ($paymentCandidates as $candidate) {
          $rawIds = trim((string)($candidate['receivable_ids'] ?? ''));
          if ($rawIds === '') continue;
          $candidateIds = preg_split('/\s*,\s*/', $rawIds, -1, PREG_SPLIT_NO_EMPTY) ?: [];
          if (in_array($needleReceivableId, $candidateIds, true) && !empty($candidate['order_id'])) {
            $orderId = (int)$candidate['order_id'];
            $linkSource = 'gestao_order_payments.receivable_ids';
            break;
          }
        }
      }
    }

    // Boleto preparado pelo pedido também mantém o vínculo, servindo como contingência de leitura.
    if ($orderId <= 0 && function_exists('finance_table_exists') && finance_table_exists($pdo, 'finance_boleto_charges')
      && function_exists('finance_column_exists') && finance_column_exists($pdo, 'finance_boleto_charges', 'order_id')) {
      $chargeLink = finance_query_one($pdo, 'SELECT order_id FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND COALESCE(order_id,0)>0 ORDER BY id DESC LIMIT 1', [$cid, $titleId]);
      if (!empty($chargeLink['order_id'])) {
        $orderId = (int)$chargeLink['order_id'];
        $linkSource = 'finance_boleto_charges.order_id';
      }
    }

    if ($orderId <= 0) {
      echo json_encode([
        'ok'=>true,
        'linked'=>false,
        'title_id'=>$titleId,
        'message'=>'Não há pedido vinculado a esta conta a receber. Não será possível mostrar os produtos.',
        'rows'=>[],
      ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      exit;
    }

    $order = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1', [$cid, $orderId]);
    if (!$order) throw new RuntimeException('O pedido vinculado a este título não foi encontrado na empresa selecionada.');
    if (!function_exists('finance_table_exists') || !finance_table_exists($pdo, 'pedido_itens')) throw new RuntimeException('A estrutura de itens de pedido não está disponível.');

    $items = finance_query_all($pdo, "SELECT pi.id,pi.produto_id,pi.qtd,pi.preco_unit,pi.total,COALESCE(NULLIF(TRIM(p.nome),''),CONCAT('Produto #',pi.produto_id)) AS produto_nome FROM pedido_itens pi LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id WHERE pi.company_id=? AND pi.pedido_id=? ORDER BY pi.id", [$cid, $orderId]);

    $orderNumber = (int)($order['numero_pedido'] ?? 0);
    if ($orderNumber <= 0) $orderNumber = $orderId;
    $quantityTotal = 0.0;
    $itemsTotal = 0.0;
    $rowsOut = [];
    foreach ($items as $item) {
      $qty = (float)($item['qtd'] ?? 0);
      $unit = (float)($item['preco_unit'] ?? 0);
      $total = (float)($item['total'] ?? ($qty * $unit));
      $quantityTotal += $qty;
      $itemsTotal += $total;
      $rowsOut[] = [
        'id'=>(int)($item['id'] ?? 0),
        'product_id'=>(int)($item['produto_id'] ?? 0),
        'name'=>(string)($item['produto_nome'] ?? ''),
        'quantity'=>$qty,
        'unit_price'=>$unit,
        'total'=>$total,
      ];
    }

    echo json_encode([
      'ok'=>true,
      'linked'=>true,
      'title_id'=>$titleId,
      'order'=>[
        'id'=>$orderId,
        'number'=>$orderNumber,
        'status'=>(string)($order['status'] ?? ''),
        'total'=>(float)($order['total'] ?? $itemsTotal),
      ],
      'summary'=>[
        'items_count'=>count($rowsOut),
        'quantity_total'=>$quantityTotal,
        'items_total'=>$itemsTotal,
      ],
      'rows'=>$rowsOut,
      'link_source'=>$linkSource,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'rows'=>[]], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}

// Autorização pontual para dispensa de multa/juros. O token fica na sessão e expira em 10 minutos.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'waiver_auth') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')) throw new RuntimeException('Sem permissão para baixar títulos.');
    $type = strtolower(trim((string)($_POST['waiver_type'] ?? '')));
    if (!in_array($type, ['fine','interest'], true)) throw new RuntimeException('Tipo de autorização inválido.');
    $auth = commercial_authorize_waiver_credentials($pdo, $cid, (string)($_POST['user_code'] ?? ''), (string)($_POST['password'] ?? ''));
    $allowed = $type === 'fine' ? !empty($auth['allow_fine']) : !empty($auth['allow_interest']);
    if ((int)($auth['user_id'] ?? 0) <= 0 || !$allowed) throw new RuntimeException('Usuário/senha inválidos ou sem permissão para esta dispensa.');
    $token = commercial_issue_waiver_token($cid, $auth);
    echo json_encode(['ok'=>true,'token'=>$token,'allow_fine'=>!empty($auth['allow_fine'])?1:0,'allow_interest'=>!empty($auth['allow_interest'])?1:0], JSON_UNESCAPED_UNICODE);
  } catch (Throwable $e) { http_response_code(422); echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE); }
  exit;
}

$creSettings = finance_settings($pdo, $cid, !$financeCrossContext);
$creDefaultDue = date('Y-m-d');
if (!empty($creSettings['cre_rules_enabled']) && function_exists('finance_cre_due_dates')) {
  try { $creDefaultDue = finance_cre_due_dates($creSettings, 1, date('Y-m-d'))[0] ?? $creDefaultDue; } catch (Throwable $ignored) {}
}

if (!function_exists('receivable_receipt_batch_create')) {
  function receivable_receipt_batch_create(PDO $pdo, int $companyId, int $afterSettlementId, array $originIds): string {
    $ids = array_values(array_unique(array_filter(array_map('intval', $originIds), static fn($id) => $id > 0)));
    if (!$ids) return '';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId, $afterSettlementId], $ids);
    $rows = finance_query_all($pdo, "SELECT id FROM finance_settlements WHERE company_id=? AND id>? AND origin_type='RECEBER' AND origin_id IN ({$ph}) ORDER BY id", $params);
    $settlementIds = array_values(array_filter(array_map(static fn($r)=>(int)($r['id'] ?? 0), $rows)));
    if (!$settlementIds) return '';
    if (!isset($_SESSION['finance_receipt_batches']) || !is_array($_SESSION['finance_receipt_batches'])) $_SESSION['finance_receipt_batches'] = [];
    $now = time();
    foreach ($_SESSION['finance_receipt_batches'] as $key => $batch) {
      if (!is_array($batch) || $now - (int)($batch['created_at'] ?? 0) > 7200) unset($_SESSION['finance_receipt_batches'][$key]);
    }
    $token = bin2hex(random_bytes(12));
    $_SESSION['finance_receipt_batches'][$token] = [
      'company_id'=>$companyId,
      'settlement_ids'=>$settlementIds,
      'created_at'=>$now,
    ];
    while (count($_SESSION['finance_receipt_batches']) > 12) array_shift($_SESSION['finance_receipt_batches']);
    return $token;
  }
}
$err = '';
$postAction = '';
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? 'save');
    $action = $postAction;
    if ($financeCrossContext) {
      $writeAllowed = $action === 'save' && $financeCrossTitleWrite;
      $settleAllowed = in_array($action, ['settle','bulk_settle'], true) && $financeCrossSettlementWrite;
      $reverseAllowed = $action === 'reverse_settlement' && $financeCrossSettlementWrite;
      $cancelAllowed = $action === 'cancel' && $financeCrossReversalWrite;
      $itauQueryOnly = $action === 'sync_itau_liquidations_period' && !empty($_POST['itau_query_only']);
      $itauSyncAllowed = $action === 'sync_itau_liquidations_period' && ($itauQueryOnly || ($financeCrossSettlementWrite && $financeCrossItauSettlement));
      if (!$writeAllowed && !$settleAllowed && !$reverseAllowed && !$cancelAllowed && !$itauSyncAllowed) {
        throw new RuntimeException('V11.3: pelo F11 estão liberadas criação/edição, baixas, estorno protegido e cancelamento protegido. Demais gravações continuam bloqueadas.');
      }
    }
    if ($action === 'sync_itau_liquidations_period') {
      $queryOnly = !empty($_POST['itau_query_only']);
      if ($financeCrossContext) {
        $mcCsrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($mcCsrf)) throw new RuntimeException('Sessão Multiempresa expirada ou inválida. Atualize a página e tente novamente.');
      }
      if (!$queryOnly && function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')) throw new RuntimeException('Seu usuário não possui permissão para baixar títulos.');
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_boletos')) throw new RuntimeException('Seu usuário não possui permissão para Cobranças/Boletos.');
      if (!$queryOnly && $financeCrossContext && (!$financeCrossSettlementWrite || !$financeCrossItauSettlement)) throw new RuntimeException('A sincronização Itaú com baixa ainda não está habilitada para esta empresa/perfil no F11. Use Somente consultar para diagnóstico.');
      $bankId = (int)($_POST['itau_bank_account_id'] ?? 0);
      $dateStart = trim((string)($_POST['liquidation_date_start'] ?? ''));
      $dateEnd = trim((string)($_POST['liquidation_date_end'] ?? ''));
      if ($bankId <= 0) throw new RuntimeException('Selecione a conta bancária Itaú para consultar.');
      $cfg = billing_bank_config($pdo, $cid, $bankId);
      if (!$cfg || empty($cfg['enabled']) || strtoupper(trim((string)($cfg['provider'] ?? ''))) !== 'ITAU' || strtoupper(trim((string)($cfg['integration_mode'] ?? ''))) !== 'API') {
        throw new RuntimeException('A conta selecionada não está habilitada como Itaú / API para esta empresa.');
      }
      if ((int)($cfg['company_id'] ?? 0) !== $cid || (int)($cfg['bank_account_id'] ?? 0) !== $bankId) throw new RuntimeException('A conta Itaú selecionada não pertence à empresa ativa.');
      if ($financeCrossContext) $cfg['_nexa_skip_schema_ensure'] = 1;
      if (function_exists('set_time_limit')) @set_time_limit(180);
      $syncFilters = $queryOnly ? ['_nexa_query_only'=>1] : [];
      $result = itau_webhook_sync_liquidations_period($pdo, $cfg, $dateStart, $dateEnd, $syncFilters);
      $resultIssues = array_values(array_filter((array)($result['details'] ?? []), static fn($d)=>is_array($d) && in_array((string)($d['status'] ?? ''), ['SEM_CORRESPONDENCIA','SEM_NOSSO_NUMERO','ERRO','PENDENTE'], true)));
      $diagnosticRows = array_values(array_filter((array)($result['diagnostic_rows'] ?? []), 'is_array'));
      $diagnosticTruncated = count($diagnosticRows) > 500;
      $_SESSION['finance_itau_sync_result'] = [
        'company_id'=>$cid,'bank_account_id'=>$bankId,'bank_name'=>(string)($cfg['bank_account_name'] ?? 'Conta Itaú'),'query_only'=>$queryOnly?1:0,
        'date_start'=>$dateStart,'date_end'=>$dateEnd,'days'=>(int)($result['days'] ?? 0),'no_movement_days'=>(int)($result['no_movement_days'] ?? 0),'found'=>(int)($result['found'] ?? 0),
        'matched'=>(int)($result['matched'] ?? 0),'processed'=>(int)($result['processed'] ?? 0),'already'=>(int)($result['already'] ?? 0),
        'fees_found'=>(int)($result['fees_found'] ?? 0),'fee_amount'=>(float)($result['fee_amount'] ?? 0),'gross_amount'=>(float)($result['gross_amount'] ?? 0),'net_amount'=>(float)($result['net_amount'] ?? 0),
        'pending'=>(int)($result['pending'] ?? 0),'errors'=>(int)($result['errors'] ?? 0),'details'=>array_slice($resultIssues,0,100),'day_summaries'=>array_slice((array)($result['day_summaries'] ?? []),0,31),
        'diagnostic_rows'=>array_slice($diagnosticRows,0,500),'diagnostic_truncated'=>$diagnosticTruncated?1:0,'created_at'=>time(),
      ];
      if ($queryOnly) {
        $summary = (int)($result['found'] ?? 0) . ' liquidação(ões) encontrada(s), R$ ' . number_format((float)($result['gross_amount'] ?? 0),2,',','.') . ' bruto, R$ ' . number_format((float)($result['net_amount'] ?? 0),2,',','.') . ' líquido, ' . (int)($result['matched'] ?? 0) . ' localizada(s) no Nexa e nenhuma baixa gerada.';
        finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode('Consulta Itaú concluída: ' . $summary));
      }
      $summary = (int)($result['processed'] ?? 0) . ' baixa(s) realizada(s), ' . (int)($result['already'] ?? 0) . ' já processada(s), ' . (int)($result['fees_found'] ?? 0) . ' tarifa(s) identificada(s) (R$ ' . number_format((float)($result['fee_amount'] ?? 0),2,',','.') . '), ' . (int)($result['no_movement_days'] ?? 0) . ' dia(s) sem movimentação e ' . ((int)($result['pending'] ?? 0) + (int)($result['errors'] ?? 0)) . ' pendência(s).';
      finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode('Sincronização Itaú concluída: ' . $summary));
    }
    if ($action === 'settle') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')) throw new RuntimeException('Seu usuário não possui permissão para baixar este título.');
      $originId = (int)($_POST['id'] ?? 0);
      if ($financeCrossContext) finance_multi_company_assert_settlement_safe($pdo, $cid, 'RECEBER', [$originId]);
      $stBefore = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM finance_settlements WHERE company_id=?");
      $stBefore->execute([$cid]); $beforeSettlementId = (int)$stBefore->fetchColumn();
      $cashCrSettleData = nexa_cash_cr_prepare_manual_data($pdo, $cid, (int)(auth_user_id() ?? 0), array_merge($_POST, ['_nexa_manual_operator_id'=>(int)(auth_user_id() ?? 0)]));
      finance_settle_title($pdo, $cid, 'RECEBER', $originId, $cashCrSettleData);
      $receiptBatch = receivable_receipt_batch_create($pdo, $cid, $beforeSettlementId, [$originId]);
      if ($receiptBatch !== '') finance_redirect('financeiro_recibo_baixa.php?batch=' . rawurlencode($receiptBatch));
      finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode($financeCrossContext ? 'V11.7: baixa protegida realizada na empresa do F11. O recibo não pôde ser montado, mas a baixa foi concluída.' : 'Baixa realizada e movimento financeiro de crédito gerado.'));
    }
    if ($action === 'bulk_settle') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')) throw new RuntimeException('Seu usuário não possui permissão para baixar títulos.');
      $originIds = (array)($_POST['title_ids'] ?? []);
      if ($financeCrossContext) finance_multi_company_assert_settlement_safe($pdo, $cid, 'RECEBER', $originIds);
      $stBefore = $pdo->prepare("SELECT COALESCE(MAX(id),0) FROM finance_settlements WHERE company_id=?");
      $stBefore->execute([$cid]); $beforeSettlementId = (int)$stBefore->fetchColumn();
      $cashCrSettleData = nexa_cash_cr_prepare_manual_data($pdo, $cid, (int)(auth_user_id() ?? 0), array_merge($_POST, ['_nexa_manual_operator_id'=>(int)(auth_user_id() ?? 0)]));
      $count = finance_settle_titles_bulk($pdo, $cid, 'RECEBER', $originIds, $cashCrSettleData);
      $receiptBatch = receivable_receipt_batch_create($pdo, $cid, $beforeSettlementId, $originIds);
      if ($receiptBatch !== '') finance_redirect('financeiro_recibo_baixa.php?batch=' . rawurlencode($receiptBatch));
      finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode(($financeCrossContext ? 'V11.7: baixa múltipla protegida realizada na empresa do F11 em ' : 'Baixa múltipla realizada em ') . $count . ' parcela(s). O recibo não pôde ser montado, mas a baixa foi concluída.'));
    }
    if ($action === 'reverse_settlement') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')) throw new RuntimeException('Seu usuário não possui permissão para estornar esta baixa.');
      $settlementId=(int)($_POST['settlement_id']??0);
      $reason=trim((string)($_POST['reversal_reason']??''));
      $uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
      finance_reverse_settlement($pdo,$cid,$settlementId,$reason,$uid);
      finance_redirect('financeiro_contas_receber.php?edit='.(int)($_POST['id']??0).'&ok='.rawurlencode('Baixa estornada com segurança e saldo do título recalculado.'));
    }
    if ($action === 'cancel') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_cancelar_titulos')) throw new RuntimeException('Seu usuário não possui permissão para cancelar títulos.');
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Título inválido.');
      if (finance_active_settlement_count($pdo,$cid,'RECEBER',$id)>0) throw new RuntimeException('Este título possui baixa ativa. Estorne a baixa antes de cancelar o título.');
      if ($financeCrossContext) {
        $mcCsrf=trim((string)($_POST['mc_csrf']??''));
        if(!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($mcCsrf)) throw new RuntimeException('V11.21: sessão Multiempresa expirada ou inválida. Atualize a página antes de cancelar.');
      }
      if ($financeCrossContext && finance_table_exists($pdo,'finance_boleto_charges')) {
        $q=$pdo->prepare("SELECT * FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND COALESCE(status,'')<>'CANCELADO' ORDER BY id DESC LIMIT 2");$q->execute([$cid,$id]);
        $activeCharges=$q->fetchAll(PDO::FETCH_ASSOC)?:[];
        if(count($activeCharges)>1) throw new RuntimeException('V11.21: foram encontradas múltiplas cobranças ativas para este título. O cancelamento pelo F11 foi bloqueado para conferência.');
        $activeCharge=$activeCharges[0]??[];
        if($activeCharge){
          if(!$financeCrossBillingCancel) throw new RuntimeException('Este título possui cobrança/boleto ativo e seu perfil não possui o escopo protegido de cancelamento de cobrança pelo F11.');
          foreach(['canceled_at','cancellation_reason','cancellation_source'] as $requiredColumn){
            if(!finance_column_exists($pdo,'finance_boleto_charges',$requiredColumn)) throw new RuntimeException('V11.21: estrutura de auditoria de cancelamento incompleta. Atualize o banco antes de cancelar pelo F11.');
          }
          billing_assert_charge_can_cancel_locally($activeCharge);
        }
      }
      billing_cancel_receivable_with_pending_charge($pdo, $cid, $id, 'Conta a Receber #' . $id . ' cancelada pelo Financeiro.', 'CONTAS_RECEBER', $financeCrossContext);
      finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode('Título cancelado com segurança.'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $personId = (int)($_POST['person_id'] ?? 0);
    $chartId = (int)($_POST['chart_account_id'] ?? 0);
    $costId = (int)($_POST['cost_center_id'] ?? 0) ?: null;
    if ($personId <= 0) throw new RuntimeException('Selecione o cliente.');
    if ($financeCrossContext && (!function_exists('finance_validate_person_role') || !finance_validate_person_role($pdo, $cid, $personId, 'customer'))) throw new RuntimeException('O cliente selecionado não pertence à empresa ativa no F11 ou não está habilitado como cliente.');
    if (!finance_validate_analytic_chart($pdo, $cid, $chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
    if (!finance_validate_analytic_cost($pdo, $cid, $costId)) throw new RuntimeException('Selecione um centro de custo analítico.');
    $currentTitle = null;
    if ($financeCrossContext && $id > 0) {
      $currentTitle = finance_query_one($pdo, 'SELECT * FROM finance_receivables WHERE company_id=? AND id=? LIMIT 1', [$cid, $id]);
      if (!$currentTitle) throw new RuntimeException('Título não encontrado na empresa selecionada pelo F11.');
    }
    $amount = finance_money_in($_POST['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Informe o valor do título.');
    if ($financeCrossContext) {
      if ($currentTitle) {
        $currentStatus = strtoupper(trim((string)($currentTitle['status'] ?? '')));
        if ($currentStatus !== 'ABERTO' || (float)($currentTitle['paid_amount'] ?? 0) > 0.009) {
          throw new RuntimeException('V11.1: somente títulos ainda ABERTOS e sem baixa podem ser editados pelo F11.');
        }
        $stSettlement = $pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id=?");
        $stSettlement->execute([$cid, $id]);
        if ((int)$stSettlement->fetchColumn() > 0) throw new RuntimeException('V11.1: este título já possui histórico de baixa e não pode ser editado pelo F11.');
        if (finance_table_exists($pdo, 'finance_boleto_charges')) {
          $stCharge = $pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND COALESCE(status,'')<>'CANCELADO'");
          $stCharge->execute([$cid, $id]);
          if ((int)$stCharge->fetchColumn() > 0) throw new RuntimeException('V11.1: este título possui cobrança bancária vinculada. Edite-o entrando diretamente na empresa.');
        }
        $fine = (float)($currentTitle['fine_amount'] ?? 0);
        $interest = (float)($currentTitle['interest_amount'] ?? 0);
        $discount = (float)($currentTitle['discount_amount'] ?? 0);
        $paid = (float)($currentTitle['paid_amount'] ?? 0);
      } else {
        $fine = 0.0; $interest = 0.0; $discount = 0.0; $paid = 0.0;
      }
      $open = max(0, round($amount + $fine + $interest - $discount - $paid, 2));
      if ($open <= 0.009) throw new RuntimeException('V11.2: o cadastro do título precisa permanecer com valor em aberto. Para quitar ou baixar, use a ação Baixar, que possui validação própria.');
      $statusCalc = 'ABERTO';
    } else {
      $fine = finance_money_in($_POST['fine_amount'] ?? 0);
      $interest = finance_money_in($_POST['interest_amount'] ?? 0);
      $discount = finance_money_in($_POST['discount_amount'] ?? 0);
      $paid = finance_money_in($_POST['paid_amount'] ?? 0);
      $open = max(0, round($amount + $fine + $interest - $discount - $paid, 2));
      $statusCalc = $open <= 0.009 ? 'BAIXADO' : (($paid > 0) ? 'PARCIAL' : 'ABERTO');
    }
    $issueDate = finance_date_or_null($_POST['issue_date'] ?? '') ?: date('Y-m-d');
    $newDueDate = finance_date_or_null($_POST['due_date'] ?? '') ?: date('Y-m-d');
    // Parcelamento avulso: inclusão de novas contas a receber sem baixa ou boleto automático.
    $parcelCount = $id === 0 ? (int)($_POST['parcel_count'] ?? 1) : 1;
    if ($parcelCount < 1 || $parcelCount > 120) throw new RuntimeException('Informe entre 1 e 120 parcelas.');
    if ($id === 0 && $parcelCount > 1) {
      $parcelValues = $_POST['parcel_amounts'] ?? [];
      $parcelDates = $_POST['parcel_dates'] ?? [];
      if (!is_array($parcelValues) || !is_array($parcelDates) || count($parcelValues) !== $parcelCount || count($parcelDates) !== $parcelCount) {
        throw new RuntimeException('Confira a grade de parcelas antes de salvar.');
      }
      if ($fine > 0 || $interest > 0 || $discount > 0 || $paid > 0) {
        throw new RuntimeException('Para parcelar, deixe multa, juros, desconto e valor baixado zerados. Ajuste esses valores individualmente após a inclusão.');
      }
      $parcels = []; $sumCents = 0;
      foreach (array_values($parcelValues) as $i => $rawValue) {
        $value = finance_money_in($rawValue);
        $date = finance_date_or_null($parcelDates[$i] ?? '');
        if ($value <= 0 || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !DateTimeImmutable::createFromFormat('!Y-m-d', $date) || DateTimeImmutable::createFromFormat('!Y-m-d', $date)->format('Y-m-d') !== $date) {
          throw new RuntimeException('Parcela ' . ($i + 1) . ': informe valor positivo e vencimento válido.');
        }
        $cents = (int) round($value * 100);
        $sumCents += $cents;
        $parcels[] = [$cents / 100, $date];
      }
      if ($sumCents !== (int)round($amount * 100)) throw new RuntimeException('A soma das parcelas deve ser igual ao valor total informado.');
      $insert = $pdo->prepare('INSERT INTO finance_receivables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $startedParcel = !$pdo->inTransaction();
      if ($startedParcel) $pdo->beginTransaction();
      try {
        foreach ($parcels as $i => [$value, $date]) {
          $portion = str_pad((string)($i+1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$parcelCount, 2, '0', STR_PAD_LEFT);
          $insert->execute([$cid, $personId, $chartId, $costId, trim((string)($_POST['description'] ?? '')), trim((string)($_POST['document_number'] ?? '')), $portion, $issueDate, $date, trim((string)($_POST['expected_payment_method'] ?? '')), $value, 0, 0, 0, 0, $value, 'ABERTO', trim((string)($_POST['notes'] ?? ''))]);
        }
        if ($startedParcel) $pdo->commit();
      } catch (Throwable $parcelError) {
        if ($startedParcel && $pdo->inTransaction()) $pdo->rollBack();
        throw $parcelError;
      }
      finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode($parcelCount . ' parcelas de Contas a Receber criadas.'));
    }
    $params = [$personId,$chartId,$costId,trim((string)($_POST['description'] ?? '')),trim((string)($_POST['document_number'] ?? '')),trim((string)($_POST['installment'] ?? '01/01')),$issueDate,$newDueDate,trim((string)($_POST['expected_payment_method'] ?? '')),$amount,$fine,$interest,$discount,$paid,$open,$statusCalc,trim((string)($_POST['notes'] ?? ''))];
    if ($id > 0) {
      if (!$financeCrossContext) billing_assert_receivable_due_date_change($pdo, $cid, $id, $newDueDate);
      $startedSave = !$pdo->inTransaction();
      if ($startedSave) $pdo->beginTransaction();
      try {
        $params[] = $cid; $params[] = $id;
        $pdo->prepare('UPDATE finance_receivables SET person_id=?, chart_account_id=?, cost_center_id=?, description=?, document_number=?, installment=?, issue_date=?, due_date=?, expected_payment_method=?, amount=?, fine_amount=?, interest_amount=?, discount_amount=?, paid_amount=?, open_amount=?, status=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute($params);
        if (!$financeCrossContext) billing_sync_receivable_due_date($pdo, $cid, $id, $newDueDate, (int)(auth_user_id() ?? 0));
        if ($startedSave) $pdo->commit();
      } catch (Throwable $saveError) {
        if ($startedSave && $pdo->inTransaction()) $pdo->rollBack();
        throw $saveError;
      }
    } else {
      array_unshift($params, $cid);
      $pdo->prepare('INSERT INTO finance_receivables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($params);
    }
    finance_redirect('financeiro_contas_receber.php?ok=' . rawurlencode('Conta a receber salva.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$customers = []; // carregado somente quando o formulário de inclusão/edição for realmente aberto
$charts = finance_chart_options($pdo, $cid, 'CREDITO');
$costs = finance_cost_center_options($pdo, $cid);
$banks = finance_bank_options($pdo, $cid);
$paymentMethods = [];
try {
  if (finance_table_exists($pdo, 'finance_payment_methods')) {
    if (finance_table_exists($pdo, 'finance_payment_method_defaults')) {
      $paymentMethods = finance_query_all($pdo, 'SELECT pm.id,pm.name,pm.type,COALESCE(fd.bank_account_id,pm.default_bank_account_id) AS default_bank_account_id,pm.affects_cash FROM finance_payment_methods pm LEFT JOIN finance_payment_method_defaults fd ON fd.company_id=pm.company_id AND fd.payment_method_id=pm.id WHERE pm.company_id=? AND pm.is_active=1 ORDER BY pm.sort_order,pm.name,pm.id', [$cid]);
    } else {
      $paymentMethods = finance_query_all($pdo, 'SELECT id,name,type,default_bank_account_id,affects_cash FROM finance_payment_methods WHERE company_id=? AND is_active=1 ORDER BY sort_order,name,id', [$cid]);
    }
  }
} catch (Throwable $ignored) {}
$currentUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
$currentCommercialRules = commercial_user_rules($pdo, $cid, $currentUserId);
$canWaiveFine = commercial_current_user_can_waive($pdo, $cid, 'fine', $currentUserId);
$canWaiveInterest = commercial_current_user_can_waive($pdo, $cid, 'interest', $currentUserId);
$settlementDiscountLimit = commercial_settlement_discount_limit($pdo, $cid, $currentUserId);
$editId = (int)($_GET['edit'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'reverse_settlement' && $err !== '') $editId = (int)($_POST['id'] ?? 0);
$settleId = (int)($_GET['settle'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'settle') $settleId = (int)($_POST['id'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($postAction, ['settle','bulk_settle','reverse_settlement','sync_itau_liquidations_period'], true));
if ($showForm) $customers = finance_people_options($pdo, $cid, 'customer');
$showSettle = $settleId > 0;
$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_receivables WHERE company_id=? AND id=?', [$cid,$editId]) : null;
if ($editId > 0 && !$edit && $_SERVER['REQUEST_METHOD'] !== 'POST') { $showForm = false; if ($err === '') $err = 'Título não encontrado na empresa selecionada.'; }
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && !in_array($postAction, ['settle','bulk_settle','reverse_settlement','sync_itau_liquidations_period'], true)) $edit = $_POST;
$editCre = ($edit && !empty($edit['id'])) ? finance_cre_adjustments($pdo, $cid, $edit, date('Y-m-d'), finance_cre_title_principal_open($pdo, $cid, $edit)) : ['fine_amount'=>0.0,'interest_amount'=>0.0,'discount_amount'=>0.0];
$settleTitle = $settleId ? finance_query_one($pdo, 'SELECT r.*, p.name AS person_name, ca.code, ca.name AS account_name FROM finance_receivables r LEFT JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=r.company_id AND ca.id=r.chart_account_id WHERE r.company_id=? AND r.id=?', [$cid,$settleId]) : null;
if ($showSettle && !$settleTitle) { $showSettle = false; if ($err === '') $err = 'Título não encontrado para baixa.'; }
if ($showSettle && $settleTitle && $financeCrossContext) {
  try { finance_multi_company_assert_settlement_safe($pdo, $cid, 'RECEBER', [(int)$settleTitle['id']]); }
  catch (Throwable $settleGuardError) { $showSettle = false; if ($err === '') $err = $settleGuardError->getMessage(); }
}
$settleCre = $settleTitle ? finance_cre_adjustments($pdo, $cid, $settleTitle, date('Y-m-d'), finance_cre_title_principal_open($pdo, $cid, $settleTitle)) : ['fine_amount'=>0.0,'interest_amount'=>0.0,'discount_amount'=>0.0];
$settlePrincipalOpen = $settleTitle ? finance_cre_title_principal_open($pdo, $cid, $settleTitle) : 0.0;
$editPrincipalOpen = ($edit && !empty($edit['id'])) ? finance_cre_title_principal_open($pdo, $cid, $edit) : 0.0;
$status = trim((string)($_GET['status'] ?? 'ABERTO'));
$q = trim((string)($_GET['q'] ?? ''));
$personFilter = (int)($_GET['person_id'] ?? 0);
$receivablePeriodCfg = nexa_screen_performance_get($pdo, $cid);
$receivableInitialPeriod = nexa_screen_performance_period((int)($receivablePeriodCfg['receivables_days'] ?? 30));
$receivableHasIssueDateRequest = array_key_exists('issue_ini', $_GET) || array_key_exists('issue_fim', $_GET);
// A tela abre sem consultar, então as datas de emissão começam em branco (antes
// vinham do período padrão de "Desempenho das telas").
$issueIni = finance_date_or_null($_GET['issue_ini'] ?? '');
$issueFim = finance_date_or_null($_GET['issue_fim'] ?? '');
$dueIni = finance_date_or_null($_GET['due_ini'] ?? '');
$dueFim = finance_date_or_null($_GET['due_fim'] ?? '');
$settleIni = finance_date_or_null($_GET['settle_ini'] ?? '');
$settleFim = finance_date_or_null($_GET['settle_fim'] ?? '');
$where = ['r.company_id=?']; $params=[$cid];
if ($status !== '') {
  if ($status === 'ABERTO') {
    // "Em aberto" inclui títulos parcialmente baixados que ainda possuem saldo.
    $where[] = "r.status IN ('ABERTO','PARCIAL') AND COALESCE(r.open_amount,0) > 0.009";
  } else {
    $where[] = 'r.status=?';
    $params[] = $status;
  }
}
if ($personFilter > 0) { $where[] = 'r.person_id=?'; $params[] = $personFilter; }
if ($q !== '') { $where[] = '(p.name LIKE ? OR r.description LIKE ? OR r.document_number LIKE ?)'; $like=nexa_search_like($q); array_push($params,$like,$like,$like); }
if ($issueIni) { $where[] = 'r.issue_date >= ?'; $params[] = $issueIni; }
if ($issueFim) { $where[] = 'r.issue_date <= ?'; $params[] = $issueFim; }
if ($dueIni) { $where[] = 'r.due_date >= ?'; $params[] = $dueIni; }
if ($dueFim) { $where[] = 'r.due_date <= ?'; $params[] = $dueFim; }
if ($settleIni || $settleFim) {
  $settleWhere = ["fs.company_id=r.company_id", "fs.origin_type='RECEBER'", 'fs.origin_id=r.id', "COALESCE(fs.status,'ATIVO')='ATIVO'"];
  if ($settleIni) { $settleWhere[] = 'fs.settlement_date >= ?'; $params[] = $settleIni; }
  if ($settleFim) { $settleWhere[] = 'fs.settlement_date <= ?'; $params[] = $settleFim; }
  $where[] = 'EXISTS (SELECT 1 FROM finance_settlements fs WHERE ' . implode(' AND ', $settleWhere) . ')';
}
$selectedPersonName = '';
if ($personFilter > 0) {
  try {
    $selectedPerson = finance_query_one($pdo, 'SELECT name FROM finance_people WHERE company_id=? AND id=? AND active=1 AND COALESCE(is_customer,0)=1 LIMIT 1', [$cid,$personFilter]);
    $selectedPersonName = (string)($selectedPerson['name'] ?? '');
    if ($selectedPersonName === '') $personFilter = 0;
  } catch (Throwable $ignored) { $personFilter = 0; }
}
// A tela abre sem consultar: a lista só é carregada ao clicar em Filtrar (ou ao
// voltar de uma ação com os filtros lembrados). Deixa a entrada leve.
$receivableListRequested = isset($_GET['filtrar']);
// Endereço de volta com a mesma consulta (ex.: depois de enviar o boleto pelo WhatsApp).
$receivableReturnParams = ['filtrar' => '1'];
foreach (['status','q','person_id','issue_ini','issue_fim','due_ini','due_fim','settle_ini','settle_fim'] as $receivableFilterKey) {
  if (array_key_exists($receivableFilterKey, $_GET)) $receivableListRequested = true;
  if (isset($_GET[$receivableFilterKey]) && is_scalar($_GET[$receivableFilterKey]) && (string)$_GET[$receivableFilterKey] !== '') {
    $receivableReturnParams[$receivableFilterKey] = mb_substr((string)$_GET[$receivableFilterKey], 0, 120);
  }
}
$receivableReturnUrl = 'financeiro_contas_receber.php?' . http_build_query($receivableReturnParams);
$rows = $receivableListRequested ? finance_query_all($pdo, 'SELECT r.*, p.name AS person_name, ca.code, ca.name AS account_name FROM finance_receivables r LEFT JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=r.company_id AND ca.id=r.chart_account_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.due_date ASC, r.id DESC LIMIT 500', $params) : [];

// Carrega os boletos vinculados em lote para exibir as ações sem gerar N+1 consultas.
$receivableChargeMap = [];
if ($rows && finance_table_exists($pdo, 'finance_boleto_charges')) {
  try {
    $rowIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
    if ($rowIds) {
      $ph = implode(',', array_fill(0, count($rowIds), '?'));
      $chargeRows = finance_query_all($pdo, "SELECT bc.id,bc.receivable_id,bc.status,bc.provider,bc.integration_mode,bc.linha_digitavel,bc.barcode,bc.boleto_url,bc.nosso_numero,bc.external_id,bc.registered_at FROM finance_boleto_charges bc INNER JOIN (SELECT receivable_id,MAX(id) AS max_id FROM finance_boleto_charges WHERE company_id=? AND receivable_id IN ({$ph}) GROUP BY receivable_id) last_charge ON last_charge.max_id=bc.id WHERE bc.company_id=?", array_merge([$cid], $rowIds, [$cid]));
      foreach ($chargeRows as $chargeRow) $receivableChargeMap[(int)($chargeRow['receivable_id'] ?? 0)] = $chargeRow;
    }
  } catch (Throwable $ignored) {}
}

// Agrega as baixas em uma única consulta para calcular principal aberto/CRE na listagem sem N+1 queries.
$receivableSettlementAgg = [];
if ($rows && finance_table_exists($pdo, 'finance_settlements')) {
  try {
    $rowIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
    if ($rowIds) {
      $ph = implode(',', array_fill(0, count($rowIds), '?'));
      $aggRows = finance_query_all($pdo, "SELECT origin_id, COALESCE(SUM(amount),0) AS settled_principal, COALESCE(SUM(fine_amount),0) AS settled_fine, MAX(settlement_date) AS last_settlement_date, COUNT(*) AS settlement_count, COALESCE(SUM(COALESCE(amount,0)+COALESCE(fine_amount,0)+COALESCE(interest_amount,0)-COALESCE(discount_amount,0)),0) AS settled_total, COALESCE(SUM(interest_amount),0) AS settled_interest, COALESCE(SUM(discount_amount),0) AS settled_discount FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id IN ({$ph}) AND COALESCE(status,'ATIVO')='ATIVO' GROUP BY origin_id", array_merge([$cid], $rowIds));
      foreach ($aggRows as $ar) $receivableSettlementAgg[(int)($ar['origin_id'] ?? 0)] = $ar;
    }
  } catch (Throwable $ignored) {}
}
$receivableSettlementHistoryMap = [];
if ($rows && finance_table_exists($pdo, 'finance_settlements')) {
  try {
    $rowIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
    if ($rowIds) {
      $ph = implode(',', array_fill(0, count($rowIds), '?'));
      $historyRows = finance_query_all($pdo, "SELECT origin_id, COUNT(*) AS history_count FROM finance_settlements WHERE company_id=? AND origin_type='RECEBER' AND origin_id IN ({$ph}) GROUP BY origin_id", array_merge([$cid], $rowIds));
      foreach ($historyRows as $hr) $receivableSettlementHistoryMap[(int)($hr['origin_id'] ?? 0)] = (int)($hr['history_count'] ?? 0);
    }
  } catch (Throwable $ignored) {}
}
$creTodayDate = new DateTimeImmutable(date('Y-m-d'));

$settlementByUserSql = finance_column_exists($pdo,'finance_settlements','settled_by_user_id')
  ? 'LEFT JOIN users su ON su.company_id=s.company_id AND su.id=s.settled_by_user_id'
  : 'LEFT JOIN users su ON 1=0';
$settlements = $edit ? finance_query_all($pdo, 'SELECT s.*, b.name AS bank_name, su.name AS settled_by_name FROM finance_settlements s LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id ' . $settlementByUserSql . ' WHERE s.company_id=? AND s.origin_type="RECEBER" AND s.origin_id=? ORDER BY s.settlement_date DESC, s.id DESC', [$cid,(int)($edit['id'] ?? 0)]) : [];
$settlementPaymentMap = [];
if ($settlements && finance_table_exists($pdo, 'finance_settlement_payments')) {
  try {
    $settlementIds = array_values(array_filter(array_map('intval', array_column($settlements, 'id'))));
    if ($settlementIds) {
      $ph = implode(',', array_fill(0, count($settlementIds), '?'));
      $payRows = finance_query_all($pdo, "SELECT sp.*, b.name AS bank_name FROM finance_settlement_payments sp LEFT JOIN finance_bank_accounts b ON b.company_id=sp.company_id AND b.id=sp.bank_account_id WHERE sp.company_id=? AND sp.settlement_id IN ({$ph}) ORDER BY sp.id", array_merge([$cid], $settlementIds));
      foreach ($payRows as $pr) $settlementPaymentMap[(int)$pr['settlement_id']][] = $pr;
    }
  } catch (Throwable $ignored) {}
}

$editCharge = null;
$canViewBilling = !$financeCrossContext || (function_exists('finance_effective_permission_allowed') && finance_effective_permission_allowed($pdo, 'financeiro_boletos'));
$editTab = strtolower(trim((string)($_GET['tab'] ?? 'dados')));
if (!in_array($editTab, ['dados','cobranca'], true) || ($financeCrossContext && !$canViewBilling)) $editTab = 'dados';
if ($canViewBilling && $edit && !empty($edit['id']) && finance_table_exists($pdo, 'finance_boleto_charges')) {
  try {
    $editCharge = finance_query_one($pdo, 'SELECT bc.*, b.name AS bank_account_name, b.bank_name AS bank_official_name FROM finance_boleto_charges bc LEFT JOIN finance_bank_accounts b ON b.company_id=bc.company_id AND b.id=bc.bank_account_id WHERE bc.company_id=? AND bc.receivable_id=? ORDER BY bc.id DESC LIMIT 1', [$cid,(int)$edit['id']]);
  } catch (Throwable $ignored) { $editCharge = null; }
}
$editChargeStatus = strtoupper(trim((string)($editCharge['status'] ?? '')));
$editDueLocked = $editCharge
  && $editChargeStatus !== 'CANCELADO'
  && (!in_array($editChargeStatus, billing_charge_local_cancel_statuses(), true) || billing_charge_has_bank_identity((array)$editCharge));

$canSettleTitles = function_exists('finance_effective_permission_allowed') ? finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber') : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_baixar_receber'));
$canCancelTitles = function_exists('finance_effective_permission_allowed') ? finance_effective_permission_allowed($pdo, 'financeiro_cancelar_titulos') : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_cancelar_titulos'));
if ($financeCrossContext) {
  $canSettleTitles = $financeCrossSettlementWrite && $canSettleTitles;
  $canCancelTitles = $financeCrossReversalWrite && $canCancelTitles;
  // V11.6.4: mesmo sem permissão de editar cadastro, um título existente pode ser
  // aberto em modo protegido para consultar baixas e executar estorno conforme permissão.
  if (!$financeCrossTitleWrite && $editId <= 0) $showForm = false;
}
$canSyncItauLiquidations = $canSettleTitles && $canViewBilling && (!$financeCrossContext || $financeCrossItauSettlement);
$itauSyncAccounts = [];
$sicoobSyncAccounts = [];
$canConsultSicoobMovements = $canViewBilling && !$financeCrossContext;
if ($canSyncItauLiquidations && finance_table_exists($pdo, 'finance_bank_billing_configs') && finance_table_exists($pdo, 'finance_bank_accounts')) {
  try {
    $itauSyncAccounts = finance_query_all($pdo, "SELECT b.id,b.name,b.bank_name,b.agency,b.account_number,bc.environment,bc.itau_beneficiary_id FROM finance_bank_accounts b INNER JOIN finance_bank_billing_configs bc ON bc.company_id=b.company_id AND bc.bank_account_id=b.id WHERE b.company_id=? AND b.active=1 AND bc.enabled=1 AND UPPER(COALESCE(bc.provider,''))='ITAU' AND UPPER(COALESCE(bc.integration_mode,''))='API' ORDER BY b.name,b.id", [$cid]);
  } catch (Throwable $ignored) { $itauSyncAccounts = []; }
}
if ($canConsultSicoobMovements && finance_table_exists($pdo,'finance_bank_billing_configs') && finance_table_exists($pdo,'finance_bank_accounts')) {
  try {
    $sicoobSyncAccounts = finance_query_all($pdo, "SELECT b.id,b.name,b.bank_name,b.account_number FROM finance_bank_accounts b INNER JOIN finance_bank_billing_configs bc ON bc.company_id=b.company_id AND bc.bank_account_id=b.id WHERE b.company_id=? AND b.active=1 AND bc.enabled=1 AND UPPER(COALESCE(bc.provider,''))='SICOOB' AND UPPER(COALESCE(bc.integration_mode,''))='API' ORDER BY b.name,b.id", [$cid]);
  } catch(Throwable $ignored) {$sicoobSyncAccounts=[];}
}
$canOpenBankSync = $canSyncItauLiquidations || !empty($sicoobSyncAccounts);
$itauSyncFlash = null;
if (isset($_SESSION['finance_itau_sync_result']) && is_array($_SESSION['finance_itau_sync_result'])) {
  $candidate = $_SESSION['finance_itau_sync_result'];
  unset($_SESSION['finance_itau_sync_result']);
  if ((int)($candidate['company_id'] ?? 0) === $cid && time() - (int)($candidate['created_at'] ?? 0) <= 900) $itauSyncFlash = $candidate;
}
$itauSyncMcCsrf = $financeCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$billingCancelMcCsrf = $itauSyncMcCsrf;
$itauSyncDefaultStart = date('Y-m-d', strtotime('-7 days'));
$itauSyncDefaultEnd = date('Y-m-d');
$canReverseSettlements = $canSettleTitles;
$crossProtectedView = $financeCrossContext && $edit && !empty($edit['id']) && (
  !$financeCrossTitleWrite
  || strtoupper(trim((string)($edit['status'] ?? ''))) !== 'ABERTO'
  || (float)($edit['paid_amount'] ?? 0) > 0.009
  || !empty($settlements)
  || ($editCharge && strtoupper(trim((string)($editCharge['status'] ?? ''))) !== 'CANCELADO')
);
if (!$canSettleTitles) $showSettle = false;

if (!function_exists('receivable_payment_editor')) {
  function receivable_payment_editor(array $paymentMethods, array $banks, string $prefix, string $totalSource, string $defaultAmount = '0,00'): void {
    if (!$paymentMethods) {
      echo '<div class="settlement-payment-legacy">';
      echo '<div><label>Meio pagamento</label><input name="payment_method" placeholder="Pix, dinheiro, cartão..."></div>';
      echo '<div><label>Conta/Caixa</label><select name="bank_account_id">'; finance_select_options($banks, '', 'Caixa/Banco padrão'); echo '</select></div>';
      echo '</div>';
      return;
    }
    $map = [];
    foreach (array_values($paymentMethods) as $i => $m) {
      $map[(string)($i + 1)] = [
        'id'=>(int)($m['id'] ?? 0),
        'name'=>(string)($m['name'] ?? ''),
        'bank'=>(int)($m['default_bank_account_id'] ?? 0),
      ];
    }
    echo '<div class="settlement-payment-editor" id="' . finance_h($prefix) . 'PaymentEditor" data-total-source="' . finance_h($totalSource) . '" data-payment-map="' . finance_h(json_encode($map, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)) . '">';
    echo '<div class="settlement-payment-shortcuts"><span><kbd>código</kbd> forma</span><span><kbd>+</kbd> adicionar</span><span><kbd>-</kbd> remover</span><span><kbd>F9</kbd> confirmar</span></div>';
    echo '<div class="settlement-payment-list" data-role="payment-list">';
    echo '<div class="settlement-payment-row" data-payment-row data-auto="1">';
    echo '<div class="payment-code-wrap"><label>Código</label><input data-role="payment-code" inputmode="numeric" autocomplete="off" placeholder="1"></div>';
    echo '<div class="payment-method-wrap"><label>Forma de pagamento</label><select name="payment_method_id[]" data-role="payment-method" required><option value="">Selecione pelo código</option>';
    foreach (array_values($paymentMethods) as $i => $m) {
      echo '<option value="' . (int)$m['id'] . '" data-code="' . (int)($i+1) . '" data-bank="' . (int)($m['default_bank_account_id'] ?? 0) . '">' . (int)($i+1) . ' - ' . finance_h($m['name'] ?? '') . '</option>';
    }
    echo '</select></div>';
    echo '<div class="payment-value-wrap"><label>Valor</label><input name="payment_amount[]" data-role="payment-amount" inputmode="decimal" value="' . finance_h($defaultAmount) . '" required></div>';
    echo '<div class="payment-bank-wrap"><label>Conta/Caixa</label><select name="payment_bank_account_id[]" data-role="payment-bank">'; finance_select_options($banks, '', 'Usar padrão da forma'); echo '</select></div>';
    echo '<button type="button" class="btn settlement-payment-fill" data-role="payment-fill" title="Preencher com o saldo restante">Saldo</button>';
    echo '<button type="button" class="btn btn-danger settlement-payment-remove" data-role="payment-remove" title="Remover forma">×</button>';
    echo '</div></div>';
    echo '<div class="settlement-payment-actions"><button type="button" class="btn" data-role="payment-add">+ Adicionar forma</button><div class="settlement-payment-summary">Informado: <strong>R$ <span data-role="payment-sum">0,00</span></strong> · Restante: <strong>R$ <span data-role="payment-diff">0,00</span></strong></div></div>';
    echo '</div>';
  }
}
finance_admin_start('Financeiro - Contas a Receber', 'fin_receber');
finance_page_header('Contas a Receber', '', [
  ['href'=>'financeiro_contas_receber.php?new=1','label'=>'+ Novo recebimento','class'=>'btn btn-primary'],
]);
// V11.51.4: Contas a Receber ganha mais área útil. As abas gerais do Financeiro
// permanecem nas demais telas, mas não são renderizadas nesta listagem.
finance_flash(); if ($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>
<?php if($itauSyncFlash): $itauQueryOnly=!empty($itauSyncFlash['query_only']); ?>
<div class="finance-card itau-sync-result">
  <div class="finance-card-head"><div><h2 class="finance-subtitle"><?= $itauQueryOnly ? 'Diagnóstico / Reconciliação Itaú' : 'Resultado da sincronização Itaú' ?></h2><span class="finance-muted"><?= finance_h((string)($itauSyncFlash['bank_name'] ?? 'Conta Itaú')) ?> · <?= finance_h(date('d/m/Y', strtotime((string)$itauSyncFlash['date_start']))) ?> a <?= finance_h(date('d/m/Y', strtotime((string)$itauSyncFlash['date_end']))) ?><?= $itauQueryOnly ? ' · SOMENTE CONSULTA — nenhuma baixa gerada' : '' ?></span></div></div>
  <div class="itau-sync-kpis">
    <div><span>Dias consultados</span><strong><?= (int)$itauSyncFlash['days'] ?></strong></div><div><span>Dias sem movimento</span><strong><?= (int)($itauSyncFlash['no_movement_days'] ?? 0) ?></strong></div><div><span>Liquidações</span><strong><?= (int)$itauSyncFlash['found'] ?></strong></div><div><span>Valor bruto</span><strong>R$ <?= finance_money_br((float)($itauSyncFlash['gross_amount'] ?? 0)) ?></strong></div><div><span>Valor líquido</span><strong>R$ <?= finance_money_br((float)($itauSyncFlash['net_amount'] ?? 0)) ?></strong></div><div><span>Tarifas</span><strong><?= (int)($itauSyncFlash['fees_found'] ?? 0) ?> · R$ <?= finance_money_br((float)($itauSyncFlash['fee_amount'] ?? 0)) ?></strong></div><div><span>Localizadas no Nexa</span><strong><?= (int)($itauSyncFlash['matched'] ?? 0) ?></strong></div><?php if(!$itauQueryOnly): ?><div><span>Baixadas agora</span><strong><?= (int)$itauSyncFlash['processed'] ?></strong></div><div><span>Já processadas</span><strong><?= (int)$itauSyncFlash['already'] ?></strong></div><?php endif; ?><div class="<?= ((int)$itauSyncFlash['pending'] + (int)$itauSyncFlash['errors']) > 0 ? 'warning' : '' ?>"><span>Pendências</span><strong><?= (int)$itauSyncFlash['pending'] + (int)$itauSyncFlash['errors'] ?></strong></div>
  </div>
  <?php $daySummaries=array_values(array_filter((array)($itauSyncFlash['day_summaries']??[]),'is_array')); if($daySummaries): ?>
  <details class="itau-reconcile-section" open><summary>Resumo por dia</summary><div class="itau-reconcile-scroll"><table class="itau-reconcile-table"><thead><tr><th>Data Francesinha</th><th>Liquidações</th><th>Bruto</th><th>Líquido</th><th>Tarifas</th><th>Localizadas</th><th>Pendentes</th></tr></thead><tbody><?php foreach($daySummaries as $d): ?><tr><td><?= finance_h(date('d/m/Y',strtotime((string)($d['date']??'')))) ?></td><td><?= (int)($d['found']??0) ?></td><td>R$ <?= finance_money_br((float)($d['gross_amount']??0)) ?></td><td>R$ <?= finance_money_br((float)($d['net_amount']??0)) ?></td><td><?= (int)($d['fees_found']??0) ?> · R$ <?= finance_money_br((float)($d['fee_amount']??0)) ?></td><td><?= (int)($d['matched']??0) ?></td><td><?= (int)($d['pending']??0) ?></td></tr><?php endforeach; ?></tbody></table></div></details>
  <?php endif; ?>
  <?php $diagRows=array_values(array_filter((array)($itauSyncFlash['diagnostic_rows']??[]),'is_array')); if($diagRows): ?>
  <details class="itau-reconcile-section" open><summary>Movimentações individuais retornadas pelo Itaú (<?= count($diagRows) ?><?= !empty($itauSyncFlash['diagnostic_truncated'])?' de 500+':'' ?>)</summary><div class="itau-reconcile-scroll"><table class="itau-reconcile-table"><thead><tr><th>Data Francesinha</th><th>Pagamento</th><th>Crédito no extrato*</th><th>Nosso Número</th><th>Seu Número</th><th>Pagador</th><th>Tipo</th><th>Bruto</th><th>Líquido</th><th>Tarifa</th><th>Nexa</th><th>Título</th></tr></thead><tbody><?php foreach($diagRows as $d): $ls=(string)($d['local_status']??''); ?><tr><td><?= finance_h(($d['date']??'')?date('d/m/Y',strtotime((string)$d['date'])):'—') ?></td><td><?= finance_h(($d['payment_date']??'')?date('d/m/Y',strtotime((string)$d['payment_date'])):'—') ?></td><td><?= finance_h(($d['credit_date']??'')?date('d/m/Y',strtotime((string)$d['credit_date'])):'Não informada') ?></td><td><?= finance_h((string)($d['nosso_numero']??'')) ?></td><td><?= finance_h((string)($d['seu_numero']??'')) ?></td><td><?= finance_h((string)($d['payer']??'')) ?></td><td><?= finance_h((string)($d['tipo_cobranca']??'')) ?></td><td>R$ <?= finance_money_br((float)($d['gross_amount']??0)) ?></td><td>R$ <?= finance_money_br((float)($d['net_amount']??0)) ?></td><td><?= (float)($d['bank_fee_amount']??0)>0.009?'R$ '.finance_money_br((float)$d['bank_fee_amount']):'—' ?></td><td><span class="itau-local-status <?= $ls==='SEM_CORRESPONDENCIA'||$ls==='SEM_NOSSO_NUMERO'?'bad':($ls==='JA_PROCESSADO'?'done':'ok') ?>"><?= finance_h($ls==='SEM_CORRESPONDENCIA'?'Não localizada':($ls==='SEM_NOSSO_NUMERO'?'Sem Nosso Nº':($ls==='JA_PROCESSADO'?'Já processada':'Localizada'))) ?></span><?= (int)($d['charge_id']??0)>0?'<br><small>Cobrança #'.(int)$d['charge_id'].' · '.finance_h((string)($d['charge_status']??'')).'</small>':'' ?></td><td><?= (int)($d['receivable_id']??0)>0?'#'.(int)$d['receivable_id'].' · '.finance_h((string)($d['receivable_status']??'')):'—' ?></td></tr><?php endforeach; ?></tbody></table></div><p class="finance-muted" style="padding:8px 12px">* Data em que o valor líquido foi lançado na conta (data da Francesinha), a mesma do extrato Itaú. É a data usada na baixa e no movimento financeiro. Baixas automáticas já feitas com outra data (ex.: pelo webhook no dia do pagamento) são acertadas nesta sincronização, exceto as já conciliadas.</p></details>
  <?php endif; ?>
  <?php $syncIssues=array_values(array_filter((array)($itauSyncFlash['details'] ?? []),'is_array')); if($syncIssues): ?><details class="itau-sync-issues"><summary>Ver pendências da consulta (<?= count($syncIssues) ?>)</summary><div><?php foreach(array_slice($syncIssues,0,50) as $d): ?><p><strong><?= finance_h((string)($d['date']??'')) ?><?= !empty($d['nosso_numero']) ? ' · Nosso Número '.finance_h((string)$d['nosso_numero']) : '' ?>:</strong> <?= finance_h((string)($d['message']??$d['status']??'Pendente')) ?></p><?php endforeach; ?></div></details><?php endif; ?>
</div>
<?php endif; ?>

<div class="finance-card finance-filter-card">
  <form method="get" class="receivable-filter-form" data-lembrar-filtros="contas_receber" data-lembrar-ignorar="edit,new,settle,action,id" data-lembrar-somente-retorno="1"><input type="hidden" name="filtrar" value="1">
    <div class="receivable-filter-grid">
      <div><label>Emissão inicial</label><input type="date" name="issue_ini" value="<?= finance_h($issueIni ?? '') ?>"></div>
      <div><label>Emissão final</label><input type="date" name="issue_fim" value="<?= finance_h($issueFim ?? '') ?>"></div>
      <div><label>Vencimento inicial</label><input type="date" name="due_ini" value="<?= finance_h($dueIni ?? '') ?>"></div>
      <div><label>Vencimento final</label><input type="date" name="due_fim" value="<?= finance_h($dueFim ?? '') ?>"></div>
      <div><label>Baixa inicial</label><input type="date" name="settle_ini" value="<?= finance_h($settleIni ?? '') ?>"></div>
      <div><label>Baixa final</label><input type="date" name="settle_fim" value="<?= finance_h($settleFim ?? '') ?>"></div>
      <div><label>Situação</label><select name="status">
        <option value="">Todos</option>
        <option value="ABERTO" <?= $status==='ABERTO'?'selected':'' ?>>Em aberto (inclui parciais)</option>
        <option value="PARCIAL" <?= $status==='PARCIAL'?'selected':'' ?>>Somente parciais</option>
        <option value="BAIXADO" <?= $status==='BAIXADO'?'selected':'' ?>>Baixados</option>
        <option value="CANCELADO" <?= $status==='CANCELADO'?'selected':'' ?>>Cancelados</option>
      </select></div>
    </div>
    <div class="receivable-filter-second">
      <div class="receivable-person-picker">
        <label>Pessoa</label>
        <input type="hidden" name="person_id" id="receivablePersonId" value="<?= $personFilter ?>">
        <div class="finance-field-inline">
          <input id="receivablePersonName" value="<?= finance_h($selectedPersonName) ?>" placeholder="Código ou nome do cliente (Enter)" autocomplete="off">
          <button class="btn" type="button" id="openPersonLookup">Buscar</button>
          <button class="btn" type="button" id="clearPersonLookup" title="Limpar pessoa">Limpar</button>
        </div>
      </div>
      <div class="receivable-history receivable-history-second"><label>Histórico</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Histórico, documento ou cliente"></div>
      <div class="receivable-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="financeiro_contas_receber.php?limpar_filtros=1">Limpar filtros</a></div>
    </div>
  </form><script src="/assets/nexa-lembrar-filtros.js?v=2"></script>
</div>

<?php
  require_once __DIR__ . '/../config/grid_preferences.php';
  $recGridPrefs = nexa_grid_prefs_load($pdo, (int)COMPANY_ID, 'fin_receber');
  $recGridToday = $creTodayDate->format('Y-m-d');
?>
<link rel="stylesheet" href="assets/nexa_titles_grid.css?v=20261108_4">
<style id="ntgFirstPaint">.ntg-wrap:not(.is-ready){visibility:hidden}</style>
<script>setTimeout(function(){var s=document.getElementById('ntgFirstPaint');if(s&&!document.documentElement.classList.contains('ntg-ready'))s.remove();},4000);</script>
<div class="finance-card">
  <div class="finance-card-head receivable-list-head">
    <div><h2 class="finance-subtitle">Títulos cadastrados</h2><div class="ntg-subline"><span class="finance-muted"><?= count($rows) ?> título(s)</span><span class="ntg-legend"><span><i class="lg-overdue"></i>Vencida</span><span><i class="lg-today"></i>Vence hoje</span></span></div></div>
    <div class="receivable-head-actions">
      <?php if($canSettleTitles): ?>
        <div class="receivable-selected-summary" id="bulkSelectedSummary" hidden>
          <div class="receivable-selected-count"><b id="bulkSelectedHeaderCount">0</b> parcela(s)</div>
          <div><span>Total</span><strong>R$ <b id="bulkSelectedHeaderTotal">0,00</b></strong></div>
          <div><span>Multa</span><strong>R$ <b id="bulkSelectedHeaderFine">0,00</b></strong></div>
          <div><span>Juros</span><strong>R$ <b id="bulkSelectedHeaderInterest">0,00</b></strong></div>
          <div class="highlight"><span>Total marcado</span><strong>R$ <b id="bulkSelectedHeaderUpdated">0,00</b></strong></div>
        </div>
        <label class="receivable-check-all"><input type="checkbox" id="bulkCheckAll"> Selecionar todos em aberto</label>
        <?php if($canOpenBankSync): ?><button class="btn" type="button" id="openItauLiquidationSyncBtn">Sincronizar liquidações</button><?php endif; ?>
        <button class="btn btn-primary" type="button" id="openBulkSettleBtn" disabled>Baixar selecionados</button>
      <?php endif; ?>
      <div class="ntg-toolbar">
        <details class="ntg-picker"><summary class="btn btn-sm">☷ Colunas</summary><div class="ntg-picker-panel"><strong>Colunas visíveis</strong><div data-ntg-columns="fin_receber"></div><button type="button" class="btn ntg-reset" data-ntg-reset="fin_receber">Restaurar padrão</button><small>Clique no título para ordenar · arraste ⠿ para mudar de lugar · ajuste a largura pela borda do cabeçalho.</small></div></details>
        <span class="ntg-pref-state" data-ntg-state="fin_receber" role="status" aria-live="polite"></span>
      </div>
    </div>
  </div>
  <?php if(!$receivableListRequested): ?><div class="finance-empty">Escolha os filtros acima e clique em <strong>Filtrar</strong> para consultar as contas a receber.</div><?php elseif(!$rows): ?><div class="finance-empty">Nenhum título encontrado.</div><?php else: ?>
  <div class="ntg-wrap">
    <table class="ntg-table" data-ntg="fin_receber" data-scope="<?= (int)COMPANY_ID ?>" data-save-url="grid_preferencias.php" data-csrf="<?= finance_h(nexa_grid_csrf()) ?>"
      data-prefs="<?= finance_h(json_encode($recGridPrefs ?: new stdClass(), JSON_UNESCAPED_UNICODE)) ?>"
      data-defaults='{"id":70,"cliente":230,"doc":130,"historico":200,"atraso":78,"emissao":92,"vencimento":108,"baixa":122,"situacao":100,"original":110,"baixado":128,"aberto":115,"plano":200}'>
      <thead><tr>
        <th class="ntg-sel"></th>
        <th data-col="id" data-type="num">ID</th>
        <th data-col="cliente">Cliente</th>
        <th data-col="doc">Documento</th>
        <th data-col="historico">Histórico</th>
        <th data-col="atraso" data-type="num">Atraso</th>
        <th data-col="emissao">Emissão</th>
        <th data-col="vencimento">Vencimento</th>
        <th data-col="baixa">Data da baixa</th>
        <th data-col="situacao">Situação</th>
        <th data-col="original" data-type="num" class="ntg-num">Original</th>
        <th data-col="baixado" data-type="num" class="ntg-num">Valor baixado</th>
        <th data-col="aberto" data-type="num" class="ntg-num">Em aberto</th>
        <th data-col="plano">Plano</th>
        <th class="ntg-actions">Ações</th>
      </tr></thead>
      <tbody>
    <?php foreach($rows as $r):
      $canSelectRow = $canSettleTitles && in_array((string)$r['status'], ['ABERTO','PARCIAL'], true);
      $rowOriginal = (float)($r['amount'] ?? 0);
      $rowOpen = (float)($r['open_amount'] ?? 0);
      $rowAgg = $receivableSettlementAgg[(int)($r['id'] ?? 0)] ?? [];
      $rowPrincipalOpen = max(0.0, round($rowOriginal - (float)($rowAgg['settled_principal'] ?? 0), 2));
      $rowFineToday = 0.0;
      $rowInterestToday = 0.0;
      $rowDiscountToday = 0.0;
      $rowLateDays = 0;
      $rowIsOpen = in_array((string)$r['status'], ['ABERTO','PARCIAL'], true) && $rowOpen > 0.009;
      if ($rowIsOpen) {
        try {
          $rowDue = new DateTimeImmutable((string)($r['due_date'] ?? date('Y-m-d')));
          $rowLateDays = $creTodayDate > $rowDue ? (int)$rowDue->diff($creTodayDate)->format('%a') : 0;
          if (!empty($creSettings['cre_rules_enabled'])) {
            if ($creTodayDate <= $rowDue && (float)($creSettings['cre_discount_before_due_percent'] ?? 0) > 0) {
              $rowDiscountToday = round($rowPrincipalOpen * ((float)$creSettings['cre_discount_before_due_percent'] / 100), 2);
            }
            if ($rowLateDays > 0) {
              $rowFineAlready = max((float)($r['fine_amount'] ?? 0), (float)($rowAgg['settled_fine'] ?? 0));
              if ($rowFineAlready <= 0.0001 && (float)($creSettings['cre_late_fee_percent'] ?? 0) > 0) {
                $rowFineToday = round($rowOriginal * ((float)$creSettings['cre_late_fee_percent'] / 100), 2);
              }
              $rowGrace = max(0, (int)($creSettings['cre_grace_days'] ?? 0));
              if ($rowLateDays > $rowGrace && (float)($creSettings['cre_daily_interest_percent'] ?? 0) > 0) {
                $rowInterestToday = round($rowPrincipalOpen * ((float)$creSettings['cre_daily_interest_percent'] / 100) * $rowLateDays, 2);
              }
            }
          }
        } catch (Throwable $ignored) { $rowLateDays = 0; }
      }
      $rowUpdatedOpen = max(0.0, round($rowOpen + $rowFineToday + $rowInterestToday - $rowDiscountToday, 2));
      $rowIsOverdue = $rowIsOpen && $rowLateDays > 0;
      $rowCharge = $receivableChargeMap[(int)($r['id'] ?? 0)] ?? null;
      $rowChargeReady = is_array($rowCharge) && trim((string)($rowCharge['linha_digitavel'] ?? '')) !== '' && trim((string)($rowCharge['barcode'] ?? '')) !== '';
      $rowChargeActive = is_array($rowCharge) && strtoupper(trim((string)($rowCharge['status'] ?? ''))) !== 'CANCELADO';
      if ($financeCrossContext && $rowChargeActive) $canSelectRow = false;
      $rowDueIso = substr((string)($r['due_date'] ?? ''), 0, 10);
      $rowClass = $rowIsOverdue ? 'ntg-overdue' : (($rowIsOpen && $rowDueIso === $recGridToday) ? 'ntg-today' : '');
      $rowDocText = trim((string)($r['document_number'] ?? '') . (!empty($r['installment']) ? ' · Parcela ' . (string)$r['installment'] : ''), ' ·');
      $rowPlanText = trim((string)($r['code'] ?? '') . ' ' . (string)($r['account_name'] ?? ''));
      $rowOpenTip = 'Saldo base: R$ ' . finance_money_br($rowOpen) . ' | Multa: R$ ' . finance_money_br($rowFineToday) . ' | Juros: R$ ' . finance_money_br($rowInterestToday) . ($rowDiscountToday > 0.0001 ? ' | Desconto CRE: R$ ' . finance_money_br($rowDiscountToday) : '');
    ?>
      <tr class="<?= $rowClass ?>">
        <td class="ntg-sel"><?php if($canSelectRow): ?><input class="bulk-title-check" type="checkbox" value="<?= (int)$r['id'] ?>"
            data-person="<?= finance_h($r['person_name'] ?? '') ?>"
            data-desc="<?= finance_h($r['description'] ?? '') ?>"
            data-doc="<?= finance_h($r['document_number'] ?? '') ?>"
            data-installment="<?= finance_h($r['installment'] ?? '') ?>"
            data-due="<?= finance_h(finance_date_br($r['due_date'] ?? '')) ?>"
            data-due-iso="<?= finance_h($r['due_date'] ?? '') ?>"
            data-original="<?= number_format($rowOriginal, 2, '.', '') ?>"
            data-open="<?= number_format($rowOpen, 2, '.', '') ?>"
            data-payment="<?= finance_h($r['expected_payment_method'] ?? '') ?>"
            data-fine-charged="<?= ((float)($r['fine_amount'] ?? 0) > 0.0001) ? '1' : '0' ?>"><?php endif; ?></td>
        <td data-col="id" data-sort="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></td>
        <td data-col="cliente" title="<?= finance_h($r['person_name'] ?? '') ?>"><strong><?= finance_h($r['person_name'] ?? '') ?></strong></td>
        <td data-col="doc" title="<?= finance_h($rowDocText) ?>"><?= $rowDocText !== '' ? finance_h($rowDocText) : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="historico" title="<?= finance_h($r['description'] ?? '') ?>"><?= finance_h($r['description'] ?? '') ?: '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="atraso" data-sort="<?= $rowIsOverdue ? (int)$rowLateDays : 0 ?>"><?= $rowIsOverdue ? ($rowLateDays . ' dia' . ($rowLateDays === 1 ? '' : 's')) : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="emissao" data-sort="<?= finance_h(substr((string)($r['issue_date'] ?? ''), 0, 10)) ?>"><?= finance_date_br($r['issue_date'] ?? '') ?></td>
        <td data-col="vencimento" data-sort="<?= finance_h($rowDueIso) ?>"><?= finance_date_br($r['due_date'] ?? '') ?></td>
        <?php $rowLastSettle = substr((string)($rowAgg['last_settlement_date'] ?? ''), 0, 10); $rowSettleCount = (int)($rowAgg['settlement_count'] ?? 0); ?>
        <td data-col="baixa" data-sort="<?= finance_h($rowLastSettle) ?>"<?= $rowSettleCount > 1 ? ' title="' . $rowSettleCount . ' baixas · última em ' . finance_h(finance_date_br($rowLastSettle)) . '"' : '' ?>><?= $rowLastSettle !== '' ? finance_date_br($rowLastSettle) . ($rowSettleCount > 1 ? ' <span class="ntg-muted">(' . $rowSettleCount . ')</span>' : '') : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="situacao" data-sort="<?= finance_h((string)$r['status']) ?>"><?= finance_status_badge((string)$r['status']) ?></td>
        <td data-col="original" class="ntg-num" data-sort="<?= number_format($rowOriginal, 2, '.', '') ?>">R$ <?= finance_money_br($rowOriginal) ?></td>
        <?php $rowSettledTotal = round((float)($rowAgg['settled_total'] ?? 0), 2); $rowSettledTip = 'Principal: R$ ' . finance_money_br((float)($rowAgg['settled_principal'] ?? 0)) . ' | Multa: R$ ' . finance_money_br((float)($rowAgg['settled_fine'] ?? 0)) . ' | Juros: R$ ' . finance_money_br((float)($rowAgg['settled_interest'] ?? 0)) . ' | Desconto: R$ ' . finance_money_br((float)($rowAgg['settled_discount'] ?? 0)); ?>
        <td data-col="baixado" class="ntg-num" data-sort="<?= number_format($rowSettledTotal, 2, '.', '') ?>"<?= $rowSettledTotal > 0.009 ? ' title="' . finance_h($rowSettledTip) . '"' : '' ?>><?= $rowSettledTotal > 0.009 ? 'R$ ' . finance_money_br($rowSettledTotal) : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="aberto" class="ntg-num" data-sort="<?= number_format($rowUpdatedOpen, 2, '.', '') ?>" title="<?= finance_h($rowOpenTip) ?>"><strong>R$ <?= finance_money_br($rowUpdatedOpen) ?></strong></td>
        <td data-col="plano" title="<?= finance_h($rowPlanText) ?>"><span class="code"><?= finance_h($r['code'] ?? '') ?></span> <?= finance_h($r['account_name'] ?? '') ?></td>
        <td class="ntg-actions"><div class="ntg-icons">
            <button type="button" class="ntg-icon" data-view-products data-title-id="<?= (int)$r['id'] ?>" title="Ver produtos do pedido que originou este título" aria-label="Ver produtos"><?= nexa_grid_icon('products') ?></button>
            <?php if($rowChargeReady && !$financeCrossContext): ?>
              <a class="ntg-icon" href="financeiro_boleto_imprimir.php?receivable_id=<?= (int)$r['id'] ?>&return_to=<?= rawurlencode($receivableReturnUrl) ?>" target="_blank" title="Imprimir ou visualizar o boleto" aria-label="Boleto"><?= nexa_grid_icon('boleto') ?></a>
              <a class="ntg-icon ntg-wa" href="financeiro_boleto_whatsapp.php?receivable_id=<?= (int)$r['id'] ?>&return_to=<?= rawurlencode($receivableReturnUrl) ?>" title="Enviar boleto em PDF pelo WhatsApp" aria-label="WhatsApp"><?= nexa_grid_icon('whatsapp') ?></a>
              <a class="ntg-icon" href="email_documento.php?type=boleto&receivable_id=<?= (int)$r['id'] ?>&return_to=<?= rawurlencode($receivableReturnUrl) ?>" title="Enviar boleto em PDF por e-mail" aria-label="E-mail"><?= nexa_grid_icon('mail') ?></a>
            <?php elseif($rowChargeReady && $financeCrossContext && $canViewBilling): ?>
              <?php
                $boletoReturn=$receivableReturnUrl;
                $boletoHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_imprimir.php?receivable_id='.(int)$r['id'].'&return_to='.rawurlencode($boletoReturn)):'financeiro_boleto_imprimir.php?receivable_id='.(int)$r['id'];
                $boletoWaHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_whatsapp.php?receivable_id='.(int)$r['id'].'&return_to='.rawurlencode($boletoReturn)):'financeiro_boleto_whatsapp.php?receivable_id='.(int)$r['id'];
                $boletoEmailHref=function_exists('multi_company_context_url')?multi_company_context_url('email_documento.php?type=boleto&receivable_id='.(int)$r['id'].'&return_to='.rawurlencode($boletoReturn)):'email_documento.php?type=boleto&receivable_id='.(int)$r['id'];
              ?>
              <a class="ntg-icon" href="<?= finance_h($boletoHref) ?>" target="_blank" title="Visualizar ou imprimir boleto em modo protegido" aria-label="Boleto"><?= nexa_grid_icon('boleto') ?></a>
              <?php if($financeCrossBillingSend): ?><a class="ntg-icon ntg-wa" href="<?= finance_h($boletoWaHref) ?>" title="Enviar boleto em PDF pelo WhatsApp da empresa selecionada" aria-label="WhatsApp"><?= nexa_grid_icon('whatsapp') ?></a><a class="ntg-icon" href="<?= finance_h($boletoEmailHref) ?>" title="Enviar boleto em PDF por e-mail usando a empresa selecionada" aria-label="E-mail"><?= nexa_grid_icon('mail') ?></a><?php endif; ?>
            <?php endif; ?>
            <?php
              $canEditCrossTitle = !$financeCrossContext || ($financeCrossTitleWrite && strtoupper((string)($r['status'] ?? '')) === 'ABERTO' && (float)($r['paid_amount'] ?? 0) <= 0.009 && empty($receivableSettlementHistoryMap[(int)($r['id'] ?? 0)]) && !$rowChargeActive);
              if ($canEditCrossTitle):
            ?><a class="ntg-icon" href="?edit=<?= (int)$r['id'] ?>" title="Editar título" aria-label="Editar título"><?= nexa_grid_icon('edit') ?></a><?php elseif($financeCrossContext): ?><a class="ntg-icon" href="?edit=<?= (int)$r['id'] ?>" title="Detalhes do título" aria-label="Detalhes do título"><?= nexa_grid_icon('view') ?></a><?php endif; ?>
            <?php if(in_array((string)$r['status'], ['ABERTO','PARCIAL'], true)): ?>
              <?php
                $rowChargeCanCancelCross = !$rowChargeActive || ($financeCrossBillingCancel && is_array($rowCharge) && in_array(strtoupper(trim((string)($rowCharge['status'] ?? ''))), billing_charge_local_cancel_statuses(), true) && !billing_charge_has_bank_identity($rowCharge));
              ?>
              <?php if($canCancelTitles && (!$financeCrossContext || $rowChargeCanCancelCross)): ?><form method="post" data-confirm-cancel-title><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><?php if($financeCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($billingCancelMcCsrf) ?>"><?php endif; ?><button class="ntg-icon ntg-danger" title="Cancelar título" aria-label="Cancelar título"><?= nexa_grid_icon('cancel') ?></button></form><?php endif; ?>
            <?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script src="assets/nexa_titles_grid.js?v=20261108_4" defer></script>

<style>
.receivable-boleto-whatsapp{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important}.receivable-boleto-whatsapp:hover{background:#1fbd5a!important;border-color:#18a94c!important;color:#fff!important}.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.receivable-filter-form label,.receivable-person-picker>label{display:block;font-size:12px;font-weight:850;color:#475569;margin-bottom:3px}.receivable-filter-grid{display:grid;grid-template-columns:repeat(6,minmax(118px,.78fr)) minmax(145px,.9fr);gap:8px 10px;align-items:end}.receivable-filter-grid input,.receivable-filter-grid select{min-height:39px}.receivable-filter-second{display:grid;grid-template-columns:minmax(300px,420px) minmax(260px,1fr) auto;align-items:end;gap:10px;margin-top:4px}.receivable-person-picker,.receivable-history-second{min-width:0}.receivable-person-picker .finance-field-inline{display:grid;grid-template-columns:minmax(145px,1fr) auto auto;gap:8px}.receivable-person-picker .finance-field-inline .btn{white-space:nowrap}.receivable-filter-actions{display:flex;gap:8px;margin-left:0;white-space:nowrap}.receivable-filter-actions .btn{min-height:39px}.receivable-list-head{align-items:center}.receivable-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.receivable-selected-summary{display:flex;align-items:center;gap:10px;padding:8px 11px;border:1px solid #86efac;border-radius:12px;background:#f0fdf4;color:#166534;font-size:12px;white-space:nowrap}.receivable-selected-summary[hidden]{display:none}.receivable-selected-summary span{font-weight:800}.receivable-selected-summary strong{font-weight:950}.receivable-check-all{display:flex;align-items:center;gap:7px;padding:9px 11px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fbff;font-size:12px;font-weight:850;color:#475569}.receivable-help{margin:-5px 0 13px}.receivable-list{display:grid;gap:12px}.receivable-title-card{border:1px solid #dbe4f0;border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 22px rgba(15,23,42,.035)}.receivable-title-card.is-overdue{border-color:#fca5a5;border-left:5px solid #dc2626;background:#fff7f7;box-shadow:0 8px 22px rgba(185,28,28,.07)}.receivable-title-card.is-overdue .receivable-title-main{background:#fff7f7}.receivable-title-card.is-overdue .receivable-title-actions{background:#fff1f2;border-top-color:#fecaca}.receivable-title-main{display:grid;grid-template-columns:34px 58px minmax(170px,1.18fr) minmax(125px,.82fr) 72px 86px 94px 96px 98px 112px minmax(135px,.86fr);gap:9px;align-items:center;padding:13px 14px}.receivable-title-main>div{min-width:0}.receivable-title-main span{display:block;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.025em;color:#64748b;margin-bottom:4px}.receivable-title-main strong{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0f172a;font-size:13px}.receivable-title-client small{display:block;margin-top:4px;color:#64748b;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.receivable-title-history strong{font-size:12px}.receivable-title-delay{text-align:center}.receivable-title-delay strong{color:#64748b;font-size:12px}.receivable-title-card.is-overdue .receivable-title-delay strong,.receivable-title-card.is-overdue .receivable-open-updated{color:#b91c1c;font-weight:950}.receivable-title-open .receivable-open-updated{color:#047857}.receivable-title-card.is-overdue .receivable-title-open .receivable-open-updated{color:#b91c1c}.receivable-select-cell{text-align:center}.receivable-select-cell input{width:18px;height:18px}.receivable-title-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fbff;border-top:1px solid #e5eaf2}.receivable-title-actions>div{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.receivable-title-actions-left>span{font-size:11px;text-transform:uppercase;font-weight:900;color:#64748b;margin-right:3px}.receivable-title-actions-left .receivable-view-products{font-weight:850;background:#eff6ff;border-color:#bfdbfe;color:#1d4ed8}.receivable-title-actions-left .receivable-view-products:hover{background:#dbeafe;border-color:#93c5fd;color:#1e40af}.receivable-title-actions form{margin:0}.receivable-title-actions .btn{min-height:34px;height:34px;padding:0 11px;border-radius:10px;font-size:12px}.finance-bulk-modal .finance-modal-dialog{width:min(1220px,100%)}.finance-bulk-common{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.finance-bulk-common .wide{grid-column:span 4}.bulk-mode-box{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:11px;border:1px solid #dbe4f0;border-radius:15px;background:#f8fbff}.bulk-mode-box label{display:flex;align-items:center;gap:6px;font-weight:850}.bulk-partial-controls{display:flex;align-items:end;gap:8px;flex:1 1 350px}.bulk-partial-controls>div{flex:1}.bulk-partial-controls input{min-height:42px}.finance-bulk-summary{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.finance-bulk-total{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:9px 12px;font-weight:950;color:#0b1f5b}.bulk-settle-list{display:grid;gap:10px}.bulk-settle-row{border:1px solid #dbe4f0;border-radius:16px;padding:12px;background:#fff}.bulk-settle-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.bulk-settle-head strong{display:block;color:#0b1f5b}.bulk-settle-head small{display:block;color:#64748b;margin-top:3px}.bulk-settle-fields{display:grid;grid-template-columns:repeat(7,minmax(105px,1fr));gap:9px;align-items:end}.bulk-settle-fields label{font-size:10px;text-transform:uppercase;font-weight:850;color:#64748b}.bulk-settle-fields input{min-height:39px;padding:7px 8px}.bulk-readonly{min-height:39px;border:1px solid #dbe4f0;border-radius:12px;padding:9px;background:#f8fbff;font-weight:850;white-space:nowrap}.bulk-balance.partial{color:#b45309}.bulk-balance.paid{color:#047857}.person-lookup-list{display:grid;gap:8px;max-height:55vh;overflow:auto}.person-lookup-item{width:100%;text-align:left;border:1px solid #dbe4f0;background:#fff;border-radius:14px;padding:11px;cursor:pointer}.person-lookup-item:hover{border-color:#60a5fa;background:#eff6ff}.person-lookup-item strong{display:block;color:#0b1f5b}.person-lookup-item small{display:block;color:#64748b;margin-top:3px}
@media(max-width:1320px){.receivable-filter-grid{grid-template-columns:repeat(4,minmax(140px,1fr))}.receivable-filter-second{grid-template-columns:minmax(380px,560px) minmax(200px,320px) auto}.person-lookup-item .person-lookup-address{display:block;color:#64748b;font-size:11.5px;margin-top:2px}.receivable-title-main{grid-template-columns:34px 58px minmax(170px,1.35fr) minmax(125px,.9fr) 76px repeat(4,minmax(88px,.7fr));}.receivable-title-plan{grid-column:3 / span 2}.bulk-settle-fields{grid-template-columns:repeat(4,minmax(120px,1fr))}}
@media(max-width:980px){.receivable-filter-second{grid-template-columns:1fr 1fr}.receivable-filter-actions{grid-column:1/-1;justify-content:flex-end}}
@media(max-width:860px){.receivable-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.receivable-filter-second{grid-template-columns:1fr}.receivable-person-picker{max-width:none}.receivable-filter-actions{grid-column:auto;margin-top:0;justify-content:flex-end}.receivable-title-main{grid-template-columns:34px repeat(2,minmax(0,1fr))}.receivable-title-client,.receivable-title-history,.receivable-title-plan{grid-column:span 2}.bulk-settle-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-bulk-common{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-bulk-common .wide{grid-column:1/-1}}
@media(max-width:560px){.receivable-filter-grid{grid-template-columns:1fr}.receivable-person-picker .finance-field-inline{grid-template-columns:1fr 1fr}.receivable-person-picker .finance-field-inline input{grid-column:1/-1}.receivable-filter-actions{display:grid;grid-template-columns:1fr 1fr}.receivable-title-main{grid-template-columns:34px 1fr}.receivable-title-main>div:not(.receivable-select-cell){grid-column:2}.receivable-title-actions{align-items:flex-start;flex-direction:column}.receivable-title-actions>div{width:100%}.receivable-title-actions .btn{flex:1}.bulk-settle-fields,.finance-bulk-common{grid-template-columns:1fr}.bulk-mode-box{align-items:stretch;flex-direction:column}.bulk-partial-controls{display:grid;grid-template-columns:1fr;flex-basis:auto}}

.receivable-selected-summary{display:grid!important;grid-template-columns:auto repeat(4,auto);align-items:stretch;gap:7px;padding:7px;border:1px solid #86efac;border-radius:16px;background:#f0fdf4;color:#166534;white-space:nowrap}.receivable-selected-summary[hidden]{display:none!important}.receivable-selected-summary>div{display:flex;flex-direction:column;justify-content:center;min-width:92px;padding:5px 9px;border-radius:11px;background:rgba(255,255,255,.72)}.receivable-selected-summary .receivable-selected-count{min-width:78px;font-size:12px;font-weight:900;align-items:center}.receivable-selected-summary span{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#4b7b5e;font-weight:900}.receivable-selected-summary strong{font-size:16px;line-height:1.15;color:#14532d;font-weight:950}.receivable-selected-summary .highlight{background:#166534}.receivable-selected-summary .highlight span,.receivable-selected-summary .highlight strong{color:#fff}.receivable-selected-summary .highlight strong{font-size:19px}.finance-bulk-summary{align-items:stretch}.finance-bulk-total.summary-kpi{display:flex;flex-direction:column;gap:3px;min-width:150px}.finance-bulk-total.summary-kpi span.label{font-size:9px;text-transform:uppercase;letter-spacing:.04em;color:#64748b}.finance-bulk-total.summary-kpi strong{font-size:18px;color:#0b1f5b}.bulk-settle-head{align-items:center}.bulk-waive-actions{display:flex;gap:7px;flex-wrap:wrap;justify-content:flex-end}.bulk-waive-actions .btn{min-height:34px;padding:0 10px;border-radius:10px;font-size:11px}.bulk-waive-actions .waived{background:#ecfdf5;border-color:#86efac;color:#047857}.bulk-settle-fields{grid-template-columns:repeat(7,minmax(100px,1fr))}.bulk-readonly.total-updated{font-size:14px;color:#0b1f5b;background:#eff6ff;border-color:#bfdbfe}.settlement-payment-editor{border:1px solid #dbe4f0;border-radius:17px;padding:12px;background:#f8fbff}.settlement-payment-list{display:grid;gap:9px}.settlement-payment-row{display:grid;grid-template-columns:minmax(180px,1.35fr) minmax(120px,.75fr) minmax(180px,1fr) auto auto;gap:8px;align-items:end;padding:10px;background:#fff;border:1px solid #e2e8f0;border-radius:14px}.settlement-payment-row label{font-size:11px;font-weight:850;color:#475569}.settlement-payment-row select,.settlement-payment-row input{min-height:42px}.settlement-payment-row .settlement-payment-fill,.settlement-payment-row .settlement-payment-remove{min-height:42px;height:42px;white-space:nowrap}.settlement-payment-actions{display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-top:10px}.settlement-payment-summary{font-size:12px;color:#475569}.settlement-payment-summary strong{font-size:14px;color:#0b1f5b}.settlement-payment-summary .diff-bad{color:#b91c1c}.settlement-payment-legacy{display:grid;grid-template-columns:1fr 1fr;gap:10px}.settlement-discount-general{display:flex;gap:8px;align-items:end;flex-wrap:wrap}.settlement-discount-general>div{min-width:180px}.settlement-discount-general .btn{min-height:42px}.single-settle-totals{display:grid;grid-template-columns:repeat(4,minmax(120px,1fr));gap:9px;margin-top:10px}.single-settle-total{border:1px solid #dbe4f0;border-radius:14px;padding:10px;background:#f8fbff}.single-settle-total span{display:block;font-size:9px;text-transform:uppercase;font-weight:900;color:#64748b}.single-settle-total strong{display:block;margin-top:3px;font-size:17px;color:#0b1f5b}.single-settle-total.highlight{background:#0b1f5b;border-color:#0b1f5b}.single-settle-total.highlight span,.single-settle-total.highlight strong{color:#fff}.waiver-inline{display:flex;align-items:end;gap:7px}.waiver-inline>div{flex:1}.waiver-inline .btn{min-height:42px;white-space:nowrap}.waiver-auth-modal .finance-modal-dialog{width:min(520px,100%)}.waiver-auth-grid{display:grid;gap:10px}.waiver-auth-error{display:none;padding:9px 11px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:12px;font-size:12px;font-weight:850}.waiver-auth-error.show{display:block}.discount-limit-note{font-size:11px;color:#64748b;margin-top:5px}.discount-limit-note strong{color:#0b1f5b}@media(max-width:980px){.receivable-selected-summary{grid-template-columns:repeat(2,auto)}.settlement-payment-row{grid-template-columns:1fr 1fr}.settlement-payment-row>div:first-child{grid-column:1/-1}.settlement-payment-row .settlement-payment-fill,.settlement-payment-row .settlement-payment-remove{width:100%}.single-settle-totals{grid-template-columns:repeat(2,1fr)}}@media(max-width:680px){.receivable-selected-summary{grid-template-columns:1fr 1fr;width:100%;white-space:normal}.receivable-selected-summary .highlight{grid-column:1/-1}.settlement-payment-row{grid-template-columns:1fr}.settlement-payment-row>div:first-child{grid-column:auto}.single-settle-totals{grid-template-columns:1fr 1fr}.bulk-settle-fields{grid-template-columns:1fr 1fr}.bulk-waive-actions{justify-content:flex-start}.settlement-payment-legacy{grid-template-columns:1fr}}

/* Compactação da baixa CRE */
.finance-bulk-modal .finance-modal-dialog{width:min(1320px,100%);padding:14px}
.finance-bulk-modal .finance-modal-head{margin-bottom:8px}
.finance-bulk-modal .finance-form-section{padding:10px 12px;margin-bottom:9px}
.finance-bulk-modal .finance-form-section>h3{margin:0 0 8px;font-size:14px}
.finance-bulk-common{grid-template-columns:138px minmax(280px,1fr) 268px 150px;gap:8px}
.finance-bulk-common input{min-height:38px;height:38px;padding:6px 9px}
.bulk-date-field{max-width:138px}.bulk-notes-field{min-width:0}.bulk-notes-field input{width:100%}
.bulk-selected-count-box{min-height:38px;height:38px;padding:0 14px;display:flex;align-items:center;justify-content:center;white-space:nowrap;font-size:13px;font-weight:950;border-radius:11px}.bulk-selected-count-box span{font-size:15px;margin-left:4px}
.settlement-discount-general{display:grid;grid-template-columns:112px 142px;gap:7px;align-items:end;flex-wrap:nowrap}
.settlement-discount-general>div{min-width:0}.settlement-discount-general .btn{min-height:38px;height:38px;width:142px;padding:0 9px;font-size:11px;white-space:nowrap}
.bulk-mode-box{padding:8px 10px;border-radius:12px;gap:12px;min-height:48px}
.bulk-mode-box label{font-size:12px}.bulk-partial-controls{flex:0 1 190px}.bulk-partial-controls>div{max-width:170px}
.bulk-partial-controls input{min-height:38px;height:38px}
.finance-bulk-summary{display:grid;grid-template-columns:repeat(7,minmax(120px,1fr));gap:7px;margin:8px 0}
.finance-bulk-total.summary-kpi{min-width:0;padding:7px 9px;border-radius:11px}.finance-bulk-total.summary-kpi strong{font-size:15px}
.bulk-settle-list{display:grid;gap:6px;max-height:none!important;overflow:visible!important;padding-right:0!important}
.bulk-settle-row.compact{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;border-radius:12px;padding:7px 9px}
.bulk-title-info{min-width:0}.bulk-title-info strong{font-size:13px}.bulk-title-info small{font-size:10px;margin-top:1px}.bulk-history-line{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:100%}
.bulk-cre-meta{display:flex;gap:4px;flex-wrap:wrap;margin-top:4px}.bulk-cre-meta span{display:inline-flex;gap:4px;align-items:center;background:#f8fbff;border:1px solid #e2e8f0;border-radius:7px;padding:2px 5px;font-size:9px;color:#64748b}.bulk-cre-meta b{color:#0b1f5b;font-size:10px}.bulk-cre-meta .bulk-balance.partial{color:#b45309}.bulk-cre-meta .bulk-balance.paid{color:#047857}
.bulk-waive-actions{display:flex;gap:5px;flex-wrap:wrap;margin-top:4px;justify-content:flex-start}.bulk-waive-actions .btn{min-height:26px;height:26px;padding:0 7px;font-size:9px;border-radius:8px}
.bulk-settle-fields.compact-fields{display:grid;grid-template-columns:82px 92px 92px 100px 126px;gap:6px;justify-content:end;align-items:end}
.bulk-settle-fields.compact-fields label{font-size:8px;margin-bottom:2px}.bulk-settle-fields.compact-fields input{min-height:32px;height:32px;padding:4px 7px;border-radius:8px;font-size:12px}.bulk-paid-field input{font-weight:900;color:#0b1f5b}
.bulk-late-days{min-height:32px;height:32px;display:flex;align-items:center;justify-content:center;border:1px solid #fbbf24;background:#fffbeb;color:#92400e;border-radius:8px;padding:0 7px;font-size:11px;font-weight:950;white-space:nowrap}.bulk-late-days-label{font-size:8px!important;margin-bottom:2px!important;text-transform:uppercase;font-weight:850;color:#92400e!important}
.nexa-message-modal{z-index:11050}.nexa-message-modal .finance-modal-dialog{width:min(520px,calc(100vw - 28px));padding:0;overflow:hidden;border-radius:16px}.nexa-message-strip{height:9px;background:#16a34a}.nexa-message-modal.attention .nexa-message-strip{background:#eab308}.nexa-message-body{padding:20px 22px 10px}.nexa-message-body h3{margin:0 0 8px;font-size:19px;color:#0b1f5b}.nexa-message-body p{margin:0;color:#334155;font-size:14px;line-height:1.5}.nexa-message-modal .finance-form-actions{padding:10px 22px 20px;margin:0;justify-content:flex-end}.nexa-message-modal .finance-form-actions .btn{min-width:100px}.nexa-message-modal.confirmation .nexa-message-confirm{background:#15803d;border-color:#15803d;color:#fff}.nexa-message-modal.attention .nexa-message-confirm{background:#ca8a04;border-color:#ca8a04;color:#fff}.nexa-message-modal.success .nexa-message-confirm{background:#15803d;border-color:#15803d;color:#fff}
.compact-payments-section{padding-bottom:9px!important}
.settlement-payment-editor{padding:8px;border-radius:13px}
.settlement-payment-shortcuts{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:7px}.settlement-payment-shortcuts span{display:inline-flex;align-items:center;gap:5px;padding:3px 7px;border:1px dashed #bfdbfe;border-radius:8px;background:#eff6ff;font-size:10px;font-weight:800;color:#475569}.settlement-payment-shortcuts kbd{min-width:22px;text-align:center;background:#fff;border:1px solid #cbd5e1;border-radius:5px;padding:2px 5px;color:#0b1f5b}
.settlement-payment-list{gap:6px}.settlement-payment-row{grid-template-columns:72px minmax(180px,1.2fr) 120px minmax(165px,.9fr) auto auto;gap:7px;padding:7px;border-radius:11px}
.settlement-payment-row label{font-size:10px}.settlement-payment-row select,.settlement-payment-row input{min-height:36px;height:36px;padding:6px 8px}.settlement-payment-row .settlement-payment-fill,.settlement-payment-row .settlement-payment-remove{min-height:36px;height:36px;padding:0 9px}
.payment-code-wrap input{text-align:center;font-weight:950;font-size:15px}
.settlement-payment-actions{margin-top:7px}.settlement-payment-actions .btn{min-height:34px}.settlement-payment-summary{font-size:11px}.settlement-payment-summary strong{font-size:13px}
.finance-bulk-modal .finance-form-actions{padding-top:7px;margin-top:0}.finance-bulk-modal .finance-form-actions .btn{min-height:36px}
@media(max-width:1100px){.finance-bulk-common{grid-template-columns:138px 1fr}.settlement-discount-general{grid-template-columns:112px 142px}.bulk-selected-count-box{justify-self:start;width:150px}.finance-bulk-summary{grid-template-columns:repeat(4,minmax(120px,1fr))}.settlement-payment-row{grid-template-columns:70px 1fr 120px}.payment-bank-wrap{grid-column:2/4}.bulk-settle-row.compact{grid-template-columns:1fr}.bulk-settle-fields.compact-fields{grid-template-columns:repeat(5,minmax(90px,1fr));justify-content:stretch}}
@media(max-width:720px){.finance-bulk-common{grid-template-columns:1fr}.bulk-date-field{max-width:none}.settlement-discount-general{grid-template-columns:1fr 142px}.bulk-selected-count-box{width:100%}.finance-bulk-summary{grid-template-columns:1fr 1fr}.bulk-settle-row.compact{grid-template-columns:1fr}.bulk-settle-fields.compact-fields{grid-template-columns:1fr 1fr}.settlement-payment-row{grid-template-columns:70px 1fr}.payment-value-wrap,.payment-bank-wrap{grid-column:1/-1}.settlement-payment-row .settlement-payment-fill,.settlement-payment-row .settlement-payment-remove{width:100%}}
.itau-sync-modal .finance-modal-dialog{width:min(720px,calc(100vw - 28px))}.itau-sync-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}.itau-sync-grid .wide{grid-column:1/-1}.itau-sync-note{padding:11px 13px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:13px;color:#1e3a8a;font-size:12px;line-height:1.45}.itau-query-only{display:flex;align-items:flex-start;gap:10px;padding:12px 14px;border:1px solid #cbd5e1;border-radius:13px;background:#fff}.itau-query-only input{margin-top:3px}.itau-query-only strong{display:block;color:#0b1f5b}.itau-query-only small{display:block;color:#64748b;margin-top:3px;line-height:1.35}.itau-sync-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(135px,1fr));gap:9px}.itau-sync-kpis>div{border:1px solid #dbe4f0;border-radius:13px;padding:10px;background:#f8fbff}.itau-sync-kpis span{display:block;font-size:10px;text-transform:uppercase;font-weight:850;color:#64748b}.itau-sync-kpis strong{display:block;margin-top:3px;font-size:18px;color:#0b1f5b}.itau-sync-kpis .warning{background:#fffbeb;border-color:#fde68a}.itau-sync-issues,.itau-reconcile-section{margin-top:12px;border-top:1px solid #e2e8f0;padding-top:10px}.itau-sync-issues summary,.itau-reconcile-section summary{cursor:pointer;font-weight:850;color:#0b1f5b}.itau-sync-issues p{margin:7px 0;font-size:12px;color:#475569}.itau-reconcile-scroll{overflow:auto;margin-top:9px;border:1px solid #e2e8f0;border-radius:12px}.itau-reconcile-table{width:100%;border-collapse:collapse;min-width:820px;font-size:11px}.itau-reconcile-table th,.itau-reconcile-table td{padding:8px 9px;border-bottom:1px solid #e2e8f0;text-align:left;white-space:nowrap}.itau-reconcile-table th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:9px;letter-spacing:.03em;position:sticky;top:0}.itau-reconcile-table tbody tr:last-child td{border-bottom:0}.itau-local-status{display:inline-block;padding:3px 7px;border-radius:999px;font-weight:800;background:#eff6ff;color:#1d4ed8}.itau-local-status.ok{background:#ecfdf5;color:#047857}.itau-local-status.done{background:#f1f5f9;color:#475569}.itau-local-status.bad{background:#fff1f2;color:#be123c}@media(max-width:760px){.itau-sync-grid{grid-template-columns:1fr}.itau-sync-grid .wide{grid-column:auto}.itau-sync-kpis{grid-template-columns:1fr 1fr}}

.receivable-products-modal .finance-modal-dialog{width:min(900px,calc(100vw - 28px))}.receivable-products-meta{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}.receivable-products-meta span{display:inline-flex;align-items:center;gap:5px;padding:6px 9px;border:1px solid #dbe4f0;border-radius:10px;background:#f8fbff;font-size:11px;color:#475569}.receivable-products-meta strong{color:#0b1f5b}.receivable-products-table-wrap{overflow:auto;border:1px solid #dbe4f0;border-radius:14px}.receivable-products-table{width:100%;border-collapse:collapse;min-width:650px}.receivable-products-table th,.receivable-products-table td{padding:10px 12px;border-bottom:1px solid #e5eaf2;text-align:left}.receivable-products-table th{background:#f8fafc;color:#475569;text-transform:uppercase;font-size:10px;letter-spacing:.035em}.receivable-products-table td{font-size:13px;color:#334155}.receivable-products-table td.money,.receivable-products-table th.money{text-align:right;white-space:nowrap}.receivable-products-table tbody tr:last-child td{border-bottom:0}.receivable-products-table tfoot td{background:#f8fbff;font-weight:900;color:#0b1f5b;border-top:1px solid #cbd5e1}.receivable-products-empty{padding:22px;border:1px dashed #cbd5e1;border-radius:14px;background:#f8fafc;text-align:center;color:#64748b}.receivable-products-loading{padding:26px;text-align:center;color:#64748b;font-weight:800}.receivable-products-error{padding:12px 14px;border:1px solid #fecaca;border-radius:12px;background:#fef2f2;color:#b91c1c;font-weight:800}

</style>

<div class="finance-modal receivable-products-modal" id="receivableProductsModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog">
    <div class="finance-modal-head">
      <div><h2 id="receivableProductsTitle">Produtos do título</h2><p class="finance-muted" id="receivableProductsSubtitle">Itens do pedido que originou esta Conta a Receber.</p></div>
      <button type="button" class="finance-modal-close" id="closeReceivableProducts">×</button>
    </div>
    <div id="receivableProductsBody"><div class="receivable-products-loading">Carregando produtos...</div></div>
    <div class="finance-form-actions"><button type="button" class="btn btn-primary" id="closeReceivableProductsFooter">Fechar</button></div>
  </div>
</div>

<div class="finance-modal" id="personLookupModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog">
    <div class="finance-modal-head"><div><h2>Buscar cliente</h2><p class="finance-muted">Pesquise por nome ou documento e selecione uma pessoa.</p></div><button type="button" class="finance-modal-close" id="closePersonLookup">×</button></div>
    <div class="finance-form-section"><label>Pesquisar</label><input id="personLookupSearch" placeholder="Nome, CPF ou CNPJ" autocomplete="off"></div>
    <div class="person-lookup-list" id="personLookupList"><div class="finance-empty">Digite a pesquisa para localizar o cliente.</div></div>
  </div>
</div>

<?php if($canOpenBankSync): ?>
<div class="finance-modal itau-sync-modal" id="itauLiquidationSyncModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2>Sincronizar liquidações bancárias</h2><p class="finance-muted">Itaú: sincronização usual. Sicoob: consultar LIQUI, conferir e confirmar baixas sem divergências.</p></div><button type="button" class="finance-modal-close" id="closeItauLiquidationSyncBtn">×</button></div>
    <form method="post" id="itauLiquidationSyncForm"><?php if($financeCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($itauSyncMcCsrf) ?>"><?php endif; ?><input type="hidden" name="action" value="sync_itau_liquidations_period"><input type="hidden" name="sicoob_sync_csrf" value="<?= finance_h($sicoobSyncCsrf) ?>"><div class="itau-sync-grid">
      <div class="wide"><label>Conta bancária</label><select name="itau_bank_account_id" id="bankLiquidationSyncSelect" required>
<?php foreach($itauSyncAccounts as $bank): ?><option value="<?=(int)$bank['id']?>" data-provider="ITAU">Itaú · <?=finance_h((string)($bank['name']??'Conta Itaú'))?></option><?php endforeach; ?>
<?php foreach($sicoobSyncAccounts as $bank): ?><option value="<?=(int)$bank['id']?>" data-provider="SICOOB">Sicoob · <?=finance_h((string)($bank['name']??'Conta Sicoob'))?></option><?php endforeach; ?></select></div>
      <div><label>Pagamento/movimentação inicial na Francesinha</label><input type="date" name="liquidation_date_start" value="<?= finance_h($itauSyncDefaultStart) ?>" max="<?= date('Y-m-d') ?>" required></div><div><label>Pagamento/movimentação final na Francesinha</label><input type="date" name="liquidation_date_end" value="<?= finance_h($itauSyncDefaultEnd) ?>" max="<?= date('Y-m-d') ?>" required></div>
      <label class="wide itau-query-only"><input type="checkbox" name="itau_query_only" value="1" id="itauQueryOnly"><span><strong>Somente consultar — não baixar nem gerar movimento</strong><small>Use para conciliar o extrato: o Nexa mostra cada liquidação, valores, tarifa, Nosso Número e se existe cobrança/título correspondente, sem alterar dados financeiros.</small></span></label>
      <div class="wide itau-sync-note">Informe o período de pagamento/movimentação na Francesinha Itaú. Essa data pode ser anterior ao crédito exibido no extrato por causa do floating. A consulta é diária; dias sem movimento são ignorados. Período máximo: 31 dias.</div>
    </div><div class="finance-form-actions"><button type="button" class="btn" id="cancelItauLiquidationSyncBtn">Cancelar</button><button class="btn btn-primary" type="submit" id="itauSyncSubmitBtn">Sincronizar e baixar</button></div></form>
  </div>
</div>
<?php endif; ?>

<div class="finance-modal finance-bulk-modal" id="bulkSettleModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head"><div><h2>Baixa das parcelas selecionadas</h2></div><button type="button" class="finance-modal-close" id="closeBulkSettleBtn">×</button></div>
    <form id="bulkSettleForm" method="post">
      <input type="hidden" name="action" value="bulk_settle">
      <input type="hidden" name="waiver_auth_token" data-role="waiver-token" value="">
      <div class="finance-form-section bulk-general-section"><h3>Dados gerais da baixa</h3><div class="finance-bulk-common">
        <div class="bulk-date-field"><label>Data baixa</label><input type="date" name="settlement_date" id="bulkSettlementDate" value="<?= date('Y-m-d') ?>"></div>
        <div class="bulk-notes-field"><label>Observação da baixa</label><input name="notes" placeholder="Observação opcional"></div>
        <div class="settlement-discount-general"><div><label>Desconto geral (%)</label><input id="bulkGeneralDiscount" inputmode="decimal" placeholder="0,00"></div><button class="btn" type="button" id="applyBulkGeneralDiscount">Aplicar desconto</button></div>
        <div class="finance-bulk-total bulk-selected-count-box">Selecionadas: <span id="bulkSelectedCount">0</span></div>
      </div><?php if ($settlementDiscountLimit !== null): ?><div class="discount-limit-note">Limite de desconto manual deste usuário: <strong><?= finance_h(number_format((float)$settlementDiscountLimit,2,',','.')) ?>%</strong>. <?= (float)$settlementDiscountLimit <= 0 ? 'Não é permitido desconto manual na baixa.' : '' ?></div><?php else: ?><div class="discount-limit-note">Este usuário está <strong>sem limite de desconto manual</strong> na baixa.</div><?php endif; ?></div>
      <div class="finance-form-section bulk-distribution-section">
        <h3>Forma de distribuição</h3>
        <div class="bulk-mode-box">
          <label><input type="radio" name="bulk_mode_ui" value="full" checked> Baixar integralmente</label>
          <label><input type="radio" name="bulk_mode_ui" value="partial"> Baixa parcial</label>
          <div class="bulk-partial-controls"><div><label>Valor total recebido</label><input id="bulkPartialTotal" value="0,00" disabled inputmode="decimal" autocomplete="off"></div></div>
        </div>
        <div class="finance-bulk-summary">
          <div class="finance-bulk-total summary-kpi"><span class="label">Principal selecionado</span><strong>R$ <span id="bulkPrincipalTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Multa</span><strong>R$ <span id="bulkFineTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Juros</span><strong>R$ <span id="bulkInterestTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Desconto</span><strong>R$ <span id="bulkDiscountTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Total atualizado</span><strong>R$ <span id="bulkUpdatedTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Total da baixa</span><strong>R$ <span id="bulkSettleTotal">0,00</span></strong></div>
          <div class="finance-bulk-total summary-kpi"><span class="label">Saldo restante</span><strong>R$ <span id="bulkRemainingTotal">0,00</span></strong></div>
        </div>
        <div class="bulk-settle-list" id="bulkSettleRows"></div>
      </div>
      <div class="finance-form-section compact-payments-section"><h3>Formas de pagamento</h3><?php receivable_payment_editor($paymentMethods, $banks, 'bulkSettle', '#bulkSettleTotal', '0,00'); ?></div>
      <?php if ($cashCrActiveForCompany): ?><p class="finance-muted" style="margin:8px 0">Caixa C/R: <?= $cashCrOpenForUser ? 'formas que movimentam caixa serão incluídas no caixa aberto deste operador.' : 'não existe caixa aberto para este operador; esta baixa não entrará no caixa operacional.' ?></p><?php endif; ?>
  <div class="finance-form-actions"><button type="button" class="btn" id="cancelBulkSettleBtn">Cancelar</button><button class="btn btn-primary" id="bulkConfirmSettleBtn">Confirmar baixa (F9)</button></div>
    </form>
  </div>
</div>

<div class="finance-modal waiver-auth-modal" id="waiverAuthModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog">
    <div class="finance-modal-head"><div><h2>Autorizar dispensa</h2><p class="finance-muted" id="waiverAuthDescription">Informe usuário e senha com permissão para dispensar o encargo.</p></div><button type="button" class="finance-modal-close" id="closeWaiverAuth">×</button></div>
    <form id="waiverAuthForm">
      <div class="waiver-auth-grid">
        <div><label>Código do usuário ou e-mail</label><input name="user_code" id="waiverUserCode" autocomplete="username" required></div>
        <div><label>Senha</label><input type="password" name="password" id="waiverPassword" autocomplete="current-password" required></div>
        <div class="waiver-auth-error" id="waiverAuthError"></div>
      </div>
      <div class="finance-form-actions"><button type="button" class="btn" id="cancelWaiverAuth">Cancelar</button><button class="btn btn-primary" type="submit">Autorizar</button></div>
    </form>
  </div>
</div>

<div class="finance-modal nexa-message-modal confirmation" id="nexaMessageModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog">
    <div class="nexa-message-strip"></div>
    <div class="nexa-message-body">
      <h3 id="nexaMessageTitle">Confirmar</h3>
      <p id="nexaMessageText"></p>
    </div>
    <div class="finance-form-actions">
      <button type="button" class="btn" id="nexaMessageCancel">Cancelar</button>
      <button type="button" class="btn nexa-message-confirm" id="nexaMessageConfirm">Confirmar</button>
    </div>
  </div>
</div>

<script>
window.addEventListener('DOMContentLoaded', function(){
  const creRules = <?= json_encode([
    'enabled'=>!empty($creSettings['cre_rules_enabled']) ? 1 : 0,
    'daily'=>(float)$creSettings['cre_daily_interest_percent'],
    'fine'=>(float)$creSettings['cre_late_fee_percent'],
    'discount'=>(float)$creSettings['cre_discount_before_due_percent'],
    'grace'=>(int)$creSettings['cre_grace_days'],
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const userRules = <?= json_encode([
    'waiveFine'=>$canWaiveFine ? 1 : 0,
    'waiveInterest'=>$canWaiveInterest ? 1 : 0,
    'discountLimit'=>$settlementDiscountLimit,
    'today'=>date('Y-m-d'),
  ], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
  const esc=v=>String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
  const money=v=>Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
  const parse=v=>{v=String(v??'').trim();if(!v)return 0;if(v.includes(','))v=v.replace(/\./g,'').replace(',','.');return Number(v.replace(/[^0-9.\-]/g,''))||0};
  const showModal=el=>{if(!el)return;el.hidden=false;document.body.style.overflow='hidden'};
  const hideModal=el=>{if(!el)return;el.hidden=true;document.body.style.overflow=''};
  const messageModal=document.getElementById('nexaMessageModal'),messageTitle=document.getElementById('nexaMessageTitle'),messageText=document.getElementById('nexaMessageText'),messageCancel=document.getElementById('nexaMessageCancel'),messageConfirm=document.getElementById('nexaMessageConfirm');
  let messageResolver=null;
  function closeNexaMessage(result){if(!messageModal)return;messageModal.hidden=true;const bulkOpen=document.getElementById('bulkSettleModal')&&!document.getElementById('bulkSettleModal').hidden;const waiverOpen=document.getElementById('waiverAuthModal')&&!document.getElementById('waiverAuthModal').hidden;if(!bulkOpen&&!waiverOpen)document.body.style.overflow='';const resolve=messageResolver;messageResolver=null;if(resolve)resolve(!!result)}
  function nexaMessage(text,{type='attention',title='',confirmText='OK',showCancel=false}={}){return new Promise(resolve=>{if(!messageModal){resolve(window.confirm(String(text||'')));return}messageResolver=resolve;messageModal.classList.toggle('confirmation',type==='confirmation');messageModal.classList.toggle('attention',type!=='confirmation'&&type!=='success');messageModal.classList.toggle('success',type==='success');if(messageTitle)messageTitle.textContent=title||(type==='confirmation'?'Confirmação':'Atenção');if(messageText)messageText.textContent=String(text||'');if(messageConfirm)messageConfirm.textContent=confirmText||'OK';if(messageCancel){messageCancel.hidden=!showCancel;messageCancel.textContent='Cancelar'}messageModal.hidden=false;document.body.style.overflow='hidden';setTimeout(()=>messageConfirm?.focus(),30)})}
  const nexaAttention=(text,title='Atenção')=>nexaMessage(text,{type:'attention',title,confirmText:'OK',showCancel:false});
  const nexaConfirm=(text,title='Confirmar baixa')=>nexaMessage(text,{type:'confirmation',title,confirmText:'Confirmar',showCancel:true});
  messageConfirm?.addEventListener('click',()=>closeNexaMessage(true));messageCancel?.addEventListener('click',()=>closeNexaMessage(false));messageModal?.addEventListener('click',e=>{if(e.target===messageModal)closeNexaMessage(false)});
  document.addEventListener('keydown',e=>{if(messageModal?.hidden!==false)return;if(e.key==='Escape'){e.preventDefault();closeNexaMessage(false)}else if(e.key==='Enter'){e.preventDefault();closeNexaMessage(true)}});
  document.querySelectorAll('[data-confirm-cancel-title]').forEach(form=>form.addEventListener('submit',e=>{if(form.dataset.confirmed==='1'){form.dataset.confirmed='';return}e.preventDefault();nexaConfirm('Confirmar o cancelamento deste título?','Confirmar cancelamento').then(ok=>{if(!ok)return;form.dataset.confirmed='1';form.requestSubmit?.()})}));

  // V11.51.3 - visualização rápida dos produtos do pedido sem sair do Contas a Receber.
  const productsModal=document.getElementById('receivableProductsModal'),productsClose=document.getElementById('closeReceivableProducts'),productsCloseFooter=document.getElementById('closeReceivableProductsFooter'),productsTitle=document.getElementById('receivableProductsTitle'),productsSubtitle=document.getElementById('receivableProductsSubtitle'),productsBody=document.getElementById('receivableProductsBody');
  const qty=v=>Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:0,maximumFractionDigits:3});
  function closeProductsModal(){hideModal(productsModal)}
  function renderTitleProducts(data,titleId){
    if(!productsBody)return;
    if(!data?.linked){
      if(productsTitle)productsTitle.textContent='Produtos do título #'+titleId;
      if(productsSubtitle)productsSubtitle.textContent='Conta a receber sem pedido vinculado.';
      productsBody.innerHTML='<div class="receivable-products-empty">'+esc(data?.message||'Não foi possível localizar produtos vinculados a este título.')+'</div>';
      return;
    }
    const order=data.order||{},rows=Array.isArray(data.rows)?data.rows:[],summary=data.summary||{};
    if(productsTitle)productsTitle.textContent='Produtos do título #'+titleId+' · Pedido #'+esc(order.number||order.id||'');
    if(productsSubtitle)productsSubtitle.textContent='Consulta rápida dos itens vendidos, sem abrir o pedido.';
    if(!rows.length){productsBody.innerHTML='<div class="receivable-products-empty">O pedido vinculado não possui itens cadastrados.</div>';return}
    const body=rows.map((r,i)=>`<tr><td>${i+1}</td><td><strong>${esc(r.name||('Produto #'+(r.product_id||'')))}</strong></td><td class="money">${qty(r.quantity)}</td><td class="money">R$ ${money(r.unit_price)}</td><td class="money"><strong>R$ ${money(r.total)}</strong></td></tr>`).join('');
    productsBody.innerHTML=`<div class="receivable-products-meta"><span>Pedido <strong>#${esc(order.number||order.id||'')}</strong></span><span>Itens <strong>${esc(summary.items_count??rows.length)}</strong></span><span>Situação <strong>${esc(order.status||'-')}</strong></span></div><div class="receivable-products-table-wrap"><table class="receivable-products-table"><thead><tr><th>#</th><th>Produto</th><th class="money">Quantidade</th><th class="money">Valor unitário</th><th class="money">Valor total</th></tr></thead><tbody>${body}</tbody><tfoot><tr><td colspan="4">Total dos produtos</td><td class="money">R$ ${money(summary.items_total??order.total??0)}</td></tr></tfoot></table></div>`;
  }
  document.querySelectorAll('[data-view-products]').forEach(btn=>btn.addEventListener('click',async()=>{
    const titleId=Number(btn.dataset.titleId||0);if(titleId<=0)return;
    if(productsTitle)productsTitle.textContent='Produtos do título #'+titleId;
    if(productsSubtitle)productsSubtitle.textContent='Localizando o pedido e seus itens...';
    if(productsBody)productsBody.innerHTML='<div class="receivable-products-loading">Carregando produtos...</div>';
    showModal(productsModal);
    try{
      const url=new URL(window.location.href);url.searchParams.set('action','title_products');url.searchParams.set('id',String(titleId));url.searchParams.delete('edit');url.searchParams.delete('settle');
      const res=await fetch(url.toString(),{headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Não foi possível carregar os produtos.');renderTitleProducts(data,titleId);
    }catch(err){if(productsSubtitle)productsSubtitle.textContent='Não foi possível consultar os produtos.';if(productsBody)productsBody.innerHTML='<div class="receivable-products-error">'+esc(err?.message||'Falha ao carregar produtos.')+'</div>'}
  }));
  productsClose?.addEventListener('click',closeProductsModal);productsCloseFooter?.addEventListener('click',closeProductsModal);productsModal?.addEventListener('click',e=>{if(e.target===productsModal)closeProductsModal()});
  document.addEventListener('keydown',e=>{if(e.key==='Escape'&&productsModal?.hidden===false){e.preventDefault();closeProductsModal()}});

  // V11.11.1 - sincronização operacional de liquidações Itaú por período.
  const itauSyncModal=document.getElementById('itauLiquidationSyncModal'),itauSyncOpen=document.getElementById('openItauLiquidationSyncBtn'),itauSyncClose=document.getElementById('closeItauLiquidationSyncBtn'),itauSyncCancel=document.getElementById('cancelItauLiquidationSyncBtn'),itauSyncForm=document.getElementById('itauLiquidationSyncForm');
  itauSyncOpen?.addEventListener('click',()=>{showModal(itauSyncModal);setTimeout(()=>itauSyncForm?.querySelector('[name=liquidation_date_start]')?.focus(),50)});itauSyncClose?.addEventListener('click',()=>hideModal(itauSyncModal));itauSyncCancel?.addEventListener('click',()=>hideModal(itauSyncModal));itauSyncModal?.addEventListener('click',e=>{if(e.target===itauSyncModal)hideModal(itauSyncModal)});
  const itauQueryOnly=document.getElementById('itauQueryOnly'),itauSyncSubmit=document.getElementById('itauSyncSubmitBtn');const syncItauButtonLabel=()=>{if(itauSyncSubmit)itauSyncSubmit.textContent=itauQueryOnly?.checked?'Somente consultar':'Sincronizar e baixar'};itauQueryOnly?.addEventListener('change',syncItauButtonLabel);syncItauButtonLabel();
  const bankLiquidationSyncSelect=document.getElementById('bankLiquidationSyncSelect');
  const bankSyncRefreshLabel=()=>{const isSicoob=bankLiquidationSyncSelect?.selectedOptions?.[0]?.dataset.provider==='SICOOB';if(isSicoob){if(itauSyncSubmit)itauSyncSubmit.textContent='Conferir retorno Sicoob';if(itauQueryOnly){itauQueryOnly.checked=true;itauQueryOnly.disabled=true}
    const begin=itauSyncForm?.querySelector('[name=liquidation_date_start]'),end=itauSyncForm?.querySelector('[name=liquidation_date_end]');
    if(begin?.value&&end?.value&&Math.round((new Date(end.value+'T12:00:00')-new Date(begin.value+'T12:00:00'))/86400000)>1){const d=new Date(end.value+'T12:00:00');d.setDate(d.getDate()-1);begin.value=d.getFullYear()+'-'+String(d.getMonth()+1).padStart(2,'0')+'-'+String(d.getDate()).padStart(2,'0')}
  }else{if(itauQueryOnly)itauQueryOnly.disabled=false;syncItauButtonLabel()}};
  bankLiquidationSyncSelect?.addEventListener('change',bankSyncRefreshLabel);bankSyncRefreshLabel();
  // Sicoob V1.9: diagnóstico individual dos registros LIQUI, sem qualquer baixa financeira.
  const sicoobDiagnosticText=(items)=>{
    if(!Array.isArray(items)||!items.length)return '';
    const fmt=v=>v===null||v===undefined?'não informado':Number(v).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
    return items.map(r=>{
      const fields=['Nominal: '+fmt(r.nominal),'Líquido: '+fmt(r.liquido),'Tarifa: '+fmt(r.tarifa),'Mora: '+fmt(r.mora),'Multa conciliada: '+fmt(r.multa),'Juros conciliados: '+fmt(r.juros),'Desconto: '+fmt(r.desconto),'Abatimento: '+fmt(r.abatimento)];
      return '#'+r.seq+' · Nosso Número '+String(r.nosso_numero_parcial||'não informado')+' · '+String(r.data||'data não informada')+'\n'+fields.join('  |  ')+'\nSituação: '+String(r.motivo||r.status||'Pendente');
    }).join('\n\n');
  };
  itauSyncForm?.addEventListener('submit',async e=>{const a=itauSyncForm.querySelector('[name=liquidation_date_start]')?.value||'',b=itauSyncForm.querySelector('[name=liquidation_date_end]')?.value||'',queryOnly=!!itauQueryOnly?.checked;
    if(bankLiquidationSyncSelect?.selectedOptions?.[0]?.dataset.provider==='SICOOB'){
      e.preventDefault();
      if(!a||!b||b<a||Math.round((new Date(b+'T12:00:00')-new Date(a+'T12:00:00'))/86400000)>1){nexaAttention('Para o Sicoob, selecione um período válido de no máximo 2 dias.');return;}
      if(itauSyncForm.dataset.sicoobBusy==='1')return;
      itauSyncForm.dataset.sicoobBusy='1';
      const oldLabel=itauSyncSubmit?.textContent||'Conferir retorno Sicoob';
      if(itauSyncSubmit){itauSyncSubmit.disabled=true;itauSyncSubmit.textContent='Consultando Sicoob...'}
      try{
        const fd=new FormData(itauSyncForm);fd.set('action','sicoob_sync_preview_v174');
        const response=await fetch('financeiro_contas_receber.php',{method:'POST',body:fd,credentials:'same-origin',headers:{'Accept':'application/json'}});
        const ct=response.headers.get('content-type')||'';
        if(!ct.includes('application/json'))throw new Error('Resposta inesperada do servidor. Recarregue o Nexa e tente consultar a mesma solicitação.');
        const result=await response.json();
        if(!response.ok||!result.ok)throw new Error(result.error||'Não foi possível consultar as liquidações do Sicoob.');
        hideModal(itauSyncModal);
        if(result.ready_to_settle&&Number(result.matched||0)>0){
          const fmtBr=v=>Number(v||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});
          const detailed=(Array.isArray(result.ready_charges)?result.ready_charges:[]).map(c=>{
            const parts=['Cobrança #'+c.charge_id,'Nosso Número final '+c.nosso_numero_final,
              'principal '+fmtBr(c.principal),'crédito '+fmtBr(c.amount)];
            if(Number(c.fine||0)>0)parts.push('multa '+fmtBr(c.fine));
            if(Number(c.interest||0)>0)parts.push('juros '+fmtBr(c.interest));
            if(Number(c.discount||0)>0)parts.push('desconto '+fmtBr(c.discount));
            if(Number(c.rebate||0)>0)parts.push('abatimento '+fmtBr(c.rebate));
            if(Number(c.fee||0)>0)parts.push('tarifa à parte '+fmtBr(c.fee));
            return parts.join(' · ');
          }).join('\n');
          const approved=await nexaConfirm((result.message||'')+'\n\nTÍTULOS QUE SERÃO BAIXADOS:\n'+(detailed||'Confira os títulos e os valores na prévia.')+'\n\nConfirmar os '+result.matched+' recebimento(s) no Nexa? Esta ação registra movimentos financeiros reais.','Confirmar baixa bancária Sicoob');
          if(approved){
            let feesSeparateConfirmed=false;
            if(Number(result.fee_records||0)>0){
              feesSeparateConfirmed=await nexaConfirm('Confirme que você CONFERIU NO EXTRATO: os valores totais dos boletos foram creditados integralmente e as '+result.fee_records+' tarifa(s) (total de '+fmtBr(result.fee_total)+') foram debitadas EM SEPARADO.\n\nEsta sincronização registrará somente os créditos dos boletos. NÃO lançará as tarifas como despesas bancárias. Quer prosseguir?','Tarifas bancárias separadas — confirmação obrigatória');
              if(!feesSeparateConfirmed){nexaAttention('Baixa cancelada. Nenhum título financeiro foi alterado.');return;}
            }
            const fd2=new FormData(itauSyncForm);fd2.set('action','sicoob_sync_settle_v180');fd2.set('confirm_settle_sicoob','CONFIRMAR_BAIXA_SICOOB');
            if(feesSeparateConfirmed)fd2.set('sicoob_fees_separate_confirmed','TARIFAS_CONFERIDAS_SEPARADAMENTE');
            const response2=await fetch('financeiro_contas_receber.php',{method:'POST',body:fd2,credentials:'same-origin',headers:{'Accept':'application/json'}});
            const ct2=response2.headers.get('content-type')||'';
            if(!ct2.includes('application/json'))throw new Error('Resposta inesperada ao registrar baixa Sicoob. Confira o Contas a Receber.');
            const result2=await response2.json();
            if(!response2.ok||!result2.ok)throw new Error(result2.error||'Erro ao processar a baixa Sicoob.');
            nexaMessage(result2.message||'Resultado da baixa Sicoob disponível.',{type:result2.pending>0?'attention':'success',title:'Resultado das baixas Sicoob',confirmText:'OK'});
            return;
          }
        }
        const diagnosticText=sicoobDiagnosticText(result.diagnostics);
        const previewText=(result.message||'Consulta realizada, nenhuma baixa financeira executada.')+(diagnosticText?'\n\nDETALHES DA CONFERÊNCIA\n'+diagnosticText:'');
        if(messageText){messageText.style.whiteSpace='pre-line';messageText.style.maxHeight='55vh';messageText.style.overflowY='auto';}
        nexaMessage(previewText,{type:result.state==='ready'?'success':'attention',title:result.title||'Consulta Sicoob',confirmText:'OK'});
      }catch(error){hideModal(itauSyncModal);nexaAttention(error.message||'Falha de comunicação com Sicoob.','Consulta Sicoob não concluída')}
      finally{itauSyncForm.dataset.sicoobBusy='';if(itauSyncSubmit){itauSyncSubmit.disabled=false;itauSyncSubmit.textContent=oldLabel}bankSyncRefreshLabel()}
      return;
    }if(!a||!b){e.preventDefault();nexaAttention('Informe as duas datas do período de liquidação.');return}if(b<a){e.preventDefault();nexaAttention('A data final não pode ser anterior à data inicial.');return}const days=Math.floor((new Date(b+'T12:00:00')-new Date(a+'T12:00:00'))/86400000)+1;if(days>31){e.preventDefault();nexaAttention('O período máximo por sincronização é de 31 dias.');return}if(itauSyncForm.dataset.confirmed==='1'){itauSyncForm.dataset.confirmed='';return}e.preventDefault();const msg=queryOnly?'Consultar o Itaú de '+a.split('-').reverse().join('/')+' até '+b.split('-').reverse().join('/')+' sem gerar baixas ou movimentos?':'Consultar o Itaú de '+a.split('-').reverse().join('/')+' até '+b.split('-').reverse().join('/')+' e baixar automaticamente os títulos liquidados?';nexaConfirm(msg,queryOnly?'Diagnóstico de liquidações':'Sincronizar liquidações').then(ok=>{if(!ok)return;itauSyncForm.dataset.confirmed='1';itauSyncForm.requestSubmit?.()})});

  // V11.45.2: busca remota de clientes dos filtros. Nada de carregar 9 mil pessoas no HTML inicial.
  const personModal=document.getElementById('personLookupModal'), personOpen=document.getElementById('openPersonLookup'), personClose=document.getElementById('closePersonLookup'), personSearch=document.getElementById('personLookupSearch'), personList=document.getElementById('personLookupList'), personId=document.getElementById('receivablePersonId'), personName=document.getElementById('receivablePersonName'), personClear=document.getElementById('clearPersonLookup');
  let personLookupTimer=0,personLookupSeq=0;
  function renderPersonLookup(rows){if(!personList)return;personList.innerHTML=rows.length?rows.map(r=>`<button type="button" class="person-lookup-item" data-id="${esc(r.id)}" data-name="${esc(r.name||'')}"><strong>${esc(r.name||'')}</strong><small>${r.numero_cadastro?('Cód. '+esc(r.numero_cadastro)+' · '):''}${esc(r.document||'')}</small>${r.address_text?`<small class="person-lookup-address">${esc(r.address_text)}</small>`:''}</button>`).join(''):'<div class="finance-empty">Nenhum cliente encontrado.</div>'}
  async function lookupPeople(q,exact){const url=new URL(window.location.href);url.searchParams.set('action','person_lookup');url.searchParams.set('q',q);if(exact)url.searchParams.set('exact_code','1');else url.searchParams.delete('exact_code');const res=await fetch(url.toString(),{headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Falha ao pesquisar clientes.');return Array.isArray(data.rows)?data.rows:[]}
  async function searchPeople(){const q=(personSearch?.value||'').trim();if(!personList)return;if(q===''){personList.innerHTML='<div class="finance-empty">Digite a pesquisa para localizar o cliente.</div>';return}const seq=++personLookupSeq;personList.innerHTML='<div class="finance-empty">Pesquisando...</div>';try{const url=new URL(window.location.href);url.searchParams.set('action','person_lookup');url.searchParams.set('q',q);const res=await fetch(url.toString(),{headers:{'Accept':'application/json'},credentials:'same-origin'});const data=await res.json();if(seq!==personLookupSeq)return;if(!res.ok||!data.ok)throw new Error(data.error||'Falha ao pesquisar clientes.');renderPersonLookup(Array.isArray(data.rows)?data.rows:[])}catch(err){if(seq!==personLookupSeq)return;personList.innerHTML='<div class="finance-empty">'+esc(err?.message||'Não foi possível pesquisar clientes.')+'</div>'}}
  personOpen?.addEventListener('click',()=>{showModal(personModal);setTimeout(()=>personSearch?.focus(),50)});personClose?.addEventListener('click',()=>hideModal(personModal));personModal?.addEventListener('click',e=>{if(e.target===personModal)hideModal(personModal)});personClear?.addEventListener('click',()=>{if(personId)personId.value='';if(personName)personName.value=''});
  personSearch?.addEventListener('input',()=>{clearTimeout(personLookupTimer);personLookupTimer=setTimeout(searchPeople,260)});personSearch?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();clearTimeout(personLookupTimer);searchPeople()}});
  personList?.addEventListener('click',e=>{const el=e.target.closest?.('.person-lookup-item');if(!el)return;if(personId)personId.value=el.dataset.id||'';if(personName)personName.value=el.dataset.name||'';hideModal(personModal)});
  // Campo Pessoa: código + Enter (ou Tab) carrega o cliente; texto abre a busca já pesquisando.
  let personNameSelected=personName?.value||'';
  personList?.addEventListener('click',e=>{const el=e.target.closest?.('.person-lookup-item');if(el)personNameSelected=el.dataset.name||''});
  async function resolvePersonField(){if(!personName)return;const typed=personName.value.trim();if(typed===personNameSelected)return;if(typed===''){if(personId)personId.value='';personNameSelected='';return}
    if(/^\d{1,10}$/.test(typed)){try{const rows=await lookupPeople(typed,true);if(rows.length===1){personId.value=rows[0].id;personName.value=rows[0].name||'';personNameSelected=personName.value;return}}catch(err){}
      nexaAttention('Nenhum cliente encontrado com o código '+typed+'.');personName.value=personNameSelected;return}
    showModal(personModal);if(personSearch){personSearch.value=typed;clearTimeout(personLookupTimer);searchPeople();setTimeout(()=>{personSearch.focus();personSearch.setSelectionRange?.(typed.length,typed.length)},50)}personName.value=personNameSelected}
  personName?.addEventListener('keydown',e=>{if(e.key==='Enter'){if(personName.value.trim()===personNameSelected)return;e.preventDefault();resolvePersonField()}else if(e.key==='F8'){e.preventDefault();personOpen?.click()}});
  personName?.addEventListener('change',()=>resolvePersonField());
  personClear?.addEventListener('click',()=>{personNameSelected=''});

  // Formas de pagamento: mesmo fluxo de teclado do PDV (código -> valor -> + -> próxima forma).
  function editorTotal(editor){const sel=editor?.dataset.totalSource||'';if(!sel)return 0;const el=document.querySelector(sel);return parse(el?.value ?? el?.textContent ?? 0)}
  function paymentRows(editor){return Array.from(editor?.querySelectorAll('[data-payment-row]')||[])}
  function paymentMap(editor){try{return JSON.parse(editor?.dataset.paymentMap||'{}')||{}}catch(_){return {}}}
  function paymentRemaining(editor, exceptRow=null){const total=Math.max(0,editorTotal(editor));let used=0;paymentRows(editor).forEach(r=>{if(r!==exceptRow)used+=Math.max(0,parse(r.querySelector('[data-role=payment-amount]')?.value))});return Math.max(0,Math.round((total-used)*100)/100)}
  function syncPaymentEditor(editor){if(!editor)return true;const total=Math.max(0,editorTotal(editor)),rows=paymentRows(editor);if(rows.length===1&&rows[0].dataset.auto==='1'){const inp=rows[0].querySelector('[data-role=payment-amount]');if(inp)inp.value=money(total)}let sum=0;rows.forEach(r=>sum+=Math.max(0,parse(r.querySelector('[data-role=payment-amount]')?.value)));sum=Math.round(sum*100)/100;const diff=Math.round((total-sum)*100)/100;const se=editor.querySelector('[data-role=payment-sum]'),de=editor.querySelector('[data-role=payment-diff]');if(se)se.textContent=money(sum);if(de){de.textContent=money(Math.max(0,diff));de.classList.toggle('diff-bad',Math.abs(diff)>.01)}return Math.abs(diff)<=.01}
  function applyPaymentCode(editor,row,code){const map=paymentMap(editor),item=map[String(code||'').trim()];if(!item)return false;const method=row?.querySelector('[data-role=payment-method]'),bank=row?.querySelector('[data-role=payment-bank]'),codeInput=row?.querySelector('[data-role=payment-code]');if(!method)return false;method.value=String(item.id);method.dispatchEvent(new Event('change',{bubbles:true}));if(codeInput)codeInput.value=String(code).trim();if(bank&&item.bank&&Array.from(bank.options).some(o=>o.value===String(item.bank)))bank.value=String(item.bank);const amount=row.querySelector('[data-role=payment-amount]');if(amount){amount.focus();amount.select?.()}return true}
  function addPaymentRow(editor, focusCode=true){const list=editor.querySelector('[data-role=payment-list]'),first=paymentRows(editor)[0];if(!list||!first)return null;first.dataset.auto='0';const clone=first.cloneNode(true);clone.dataset.auto='0';const code=clone.querySelector('[data-role=payment-code]'),method=clone.querySelector('[data-role=payment-method]'),amount=clone.querySelector('[data-role=payment-amount]'),bank=clone.querySelector('[data-role=payment-bank]');if(code)code.value='';if(method)method.value='';if(bank)bank.value='';list.appendChild(clone);if(amount)amount.value=money(paymentRemaining(editor,clone));syncPaymentEditor(editor);if(focusCode)setTimeout(()=>code?.focus(),0);return clone}
  function removePaymentRow(editor,row){const rows=paymentRows(editor);if(rows.length<=1){const code=row?.querySelector('[data-role=payment-code]'),method=row?.querySelector('[data-role=payment-method]'),bank=row?.querySelector('[data-role=payment-bank]');if(code)code.value='';if(method)method.value='';if(bank)bank.value='';row.dataset.auto='1';const amount=row?.querySelector('[data-role=payment-amount]');if(amount)amount.value=money(editorTotal(editor));code?.focus()}else{const idx=rows.indexOf(row);row?.remove();const left=paymentRows(editor),target=left[Math.max(0,Math.min(idx,left.length-1))];setTimeout(()=>target?.querySelector('[data-role=payment-code]')?.focus(),0)}syncPaymentEditor(editor)}
  function bindPaymentEditor(editor){if(!editor)return;editor.addEventListener('click',e=>{const add=e.target.closest('[data-role=payment-add]'),rem=e.target.closest('[data-role=payment-remove]'),fill=e.target.closest('[data-role=payment-fill]');if(add){addPaymentRow(editor,true);return}if(rem){removePaymentRow(editor,rem.closest('[data-payment-row]'));return}if(fill){const row=fill.closest('[data-payment-row]'),inp=row?.querySelector('[data-role=payment-amount]');if(inp){inp.value=money(paymentRemaining(editor,row));row.dataset.auto='0';inp.focus();inp.select?.()}syncPaymentEditor(editor)}});editor.addEventListener('input',e=>{const row=e.target.closest?.('[data-payment-row]');if(e.target.matches('[data-role=payment-amount]')){row.dataset.auto='0';syncPaymentEditor(editor);return}if(e.target.matches('[data-role=payment-code]')){clearTimeout(row._paymentCodeTimer);const code=String(e.target.value||'').replace(/\D/g,''),map=paymentMap(editor),keys=Object.keys(map),exact=!!map[code],hasLonger=keys.some(k=>k.startsWith(code)&&k!==code);if(code!==e.target.value)e.target.value=code;if(exact&&!hasLonger){applyPaymentCode(editor,row,code);return}if(exact){row._paymentCodeTimer=setTimeout(()=>applyPaymentCode(editor,row,code),480)}}});editor.addEventListener('change',e=>{if(e.target.matches('[data-role=payment-method]')){const opt=e.target.options[e.target.selectedIndex],bankId=opt?.dataset.bank||'',code=opt?.dataset.code||'',row=e.target.closest('[data-payment-row]'),bank=row?.querySelector('[data-role=payment-bank]'),codeInput=row?.querySelector('[data-role=payment-code]');if(codeInput&&code)codeInput.value=code;if(bank&&bankId&&Array.from(bank.options).some(o=>o.value===bankId))bank.value=bankId;syncPaymentEditor(editor)}});editor.addEventListener('keydown',e=>{const row=e.target.closest?.('[data-payment-row]');if(!row)return;if(e.target.matches('[data-role=payment-code]')&&e.key==='Enter'){e.preventDefault();clearTimeout(row._paymentCodeTimer);if(!applyPaymentCode(editor,row,e.target.value))nexaAttention('Código de forma de pagamento inválido.');return}if(e.target.matches('[data-role=payment-amount]')&&(e.key==='+'||e.code==='NumpadAdd')){e.preventDefault();e.target.closest('[data-payment-row]').dataset.auto='0';syncPaymentEditor(editor);addPaymentRow(editor,true);return}if(e.key==='-'||e.code==='NumpadSubtract'){e.preventDefault();removePaymentRow(editor,row);return}});syncPaymentEditor(editor)}
  document.querySelectorAll('.settlement-payment-editor').forEach(bindPaymentEditor);

  // Autorização de dispensa de multa/juros.
  const waiverModal=document.getElementById('waiverAuthModal'),waiverForm=document.getElementById('waiverAuthForm'),waiverClose=document.getElementById('closeWaiverAuth'),waiverCancel=document.getElementById('cancelWaiverAuth'),waiverError=document.getElementById('waiverAuthError'),waiverDesc=document.getElementById('waiverAuthDescription');
  let pendingWaiver=null, waiverToken='', waiverTokenPerms={fine:false,interest:false};
  function permissionLocal(type){return type==='fine'?Number(userRules.waiveFine)===1:Number(userRules.waiveInterest)===1}
  function tokenAllows(type){return !!waiverTokenPerms[type]}
  function finalizeWaiver(target,type){const form=target.closest('form');if(form){const hidden=form.querySelector('[data-role=waiver-token]');if(hidden&&waiverToken)hidden.value=waiverToken}const row=target.closest('.bulk-settle-row');const input=row?row.querySelector(type==='fine'?'[data-field=fine]':'[data-field=interest]'):form?.querySelector(type==='fine'?'[data-role=single-fine]':'[data-role=single-interest]');if(input)input.value='0,00';target.classList.add('waived');target.textContent=type==='fine'?'Multa dispensada':'Juros dispensados';if(row){if(typeof refreshBulkTotals==='function'){if(currentBulkMode()==='full')applyBulkFull();else distributeBulkPartial()}}else if(form)recalcSingleForm(form)}
  function requestWaiver(target,type){if(permissionLocal(type)||tokenAllows(type)){finalizeWaiver(target,type);return}pendingWaiver={target,type};if(waiverDesc)waiverDesc.textContent=type==='fine'?'Informe usuário e senha com permissão para dispensar multa.':'Informe usuário e senha com permissão para dispensar juros.';if(waiverError){waiverError.textContent='';waiverError.classList.remove('show')}if(waiverForm)waiverForm.reset();showModal(waiverModal);setTimeout(()=>document.getElementById('waiverUserCode')?.focus(),50)}
  waiverClose?.addEventListener('click',()=>{pendingWaiver=null;hideModal(waiverModal)});waiverCancel?.addEventListener('click',()=>{pendingWaiver=null;hideModal(waiverModal)});waiverModal?.addEventListener('click',e=>{if(e.target===waiverModal){pendingWaiver=null;hideModal(waiverModal)}});
  waiverForm?.addEventListener('submit',async e=>{e.preventDefault();if(!pendingWaiver)return;const fd=new FormData(waiverForm);fd.append('action','waiver_auth');fd.append('waiver_type',pendingWaiver.type);try{const res=await fetch('financeiro_contas_receber.php',{method:'POST',body:fd,credentials:'same-origin'});const data=await res.json();if(!res.ok||!data.ok)throw new Error(data.error||'Não foi possível autorizar.');waiverToken=data.token||'';waiverTokenPerms={fine:Number(data.allow_fine||0)===1,interest:Number(data.allow_interest||0)===1};const pending=pendingWaiver;pendingWaiver=null;hideModal(waiverModal);finalizeWaiver(pending.target,pending.type)}catch(err){if(waiverError){waiverError.textContent=err.message||'Falha na autorização.';waiverError.classList.add('show')}}});
  document.addEventListener('click',e=>{const btn=e.target.closest('[data-waive-type]');if(!btn)return;e.preventDefault();requestWaiver(btn,btn.dataset.waiveType)});

  // Baixa múltipla.
  const checks=Array.from(document.querySelectorAll('.bulk-title-check')),checkAll=document.getElementById('bulkCheckAll'),openBtn=document.getElementById('openBulkSettleBtn'),selectedSummary=document.getElementById('bulkSelectedSummary'),selectedHeaderCount=document.getElementById('bulkSelectedHeaderCount'),selectedHeaderTotal=document.getElementById('bulkSelectedHeaderTotal'),selectedHeaderFine=document.getElementById('bulkSelectedHeaderFine'),selectedHeaderInterest=document.getElementById('bulkSelectedHeaderInterest'),selectedHeaderUpdated=document.getElementById('bulkSelectedHeaderUpdated'),bulkModal=document.getElementById('bulkSettleModal'),closeBtn=document.getElementById('closeBulkSettleBtn'),cancelBtn=document.getElementById('cancelBulkSettleBtn'),rowsBox=document.getElementById('bulkSettleRows'),countEl=document.getElementById('bulkSelectedCount'),principalEl=document.getElementById('bulkPrincipalTotal'),fineEl=document.getElementById('bulkFineTotal'),interestEl=document.getElementById('bulkInterestTotal'),discountEl=document.getElementById('bulkDiscountTotal'),updatedEl=document.getElementById('bulkUpdatedTotal'),totalEl=document.getElementById('bulkSettleTotal'),remainingEl=document.getElementById('bulkRemainingTotal'),bulkForm=document.getElementById('bulkSettleForm'),partialTotal=document.getElementById('bulkPartialTotal'),generalDiscount=document.getElementById('bulkGeneralDiscount'),applyGeneralDiscount=document.getElementById('applyBulkGeneralDiscount');
  const bulkDraft=new Map();
  function selectedRows(){return checks.filter(c=>c.checked).map(c=>({id:c.value,person:c.dataset.person||'',desc:c.dataset.desc||'',doc:c.dataset.doc||'',inst:c.dataset.installment||'',due:c.dataset.due||'',dueIso:c.dataset.dueIso||'',payment:c.dataset.payment||'',original:Number(c.dataset.original||0),open:Number(c.dataset.open||0),fineCharged:Number(c.dataset.fineCharged||0)})).sort((a,b)=>(a.dueIso||'9999').localeCompare(b.dueIso||'9999')||Number(a.id)-Number(b.id))}
  function lateDaysAt(r,settleIso){const dueIso=r.dueIso||settleIso;if(!dueIso||!settleIso)return 0;const settle=new Date(settleIso+'T12:00:00'),due=new Date(dueIso+'T12:00:00');return Math.max(0,Math.round((settle-due)/86400000))}
  function creCalcAt(r,settleIso){const late=lateDaysAt(r,settleIso);if(Number(creRules.enabled||0)!==1)return {fine:0,interest:0,discount:0,lateDays:late};const dueIso=r.dueIso||settleIso;const settle=new Date(settleIso+'T12:00:00'),due=new Date(dueIso+'T12:00:00');let fine=0,interest=0,discount=0;if(settle<=due&&Number(creRules.discount||0)>0)discount=r.open*(Number(creRules.discount)/100);if(late>0){if(!r.fineCharged&&Number(creRules.fine||0)>0)fine=r.original*(Number(creRules.fine)/100);if(late>Number(creRules.grace||0)&&Number(creRules.daily||0)>0)interest=r.open*(Number(creRules.daily)/100)*late}return {fine,interest,discount,lateDays:late}}
  function creCalc(r){return creCalcAt(r,document.getElementById('bulkSettlementDate')?.value||userRules.today)}
  function renderSelectedHeader(){const rows=selectedRows(),s=rows.reduce((a,r)=>{const c=creCalcAt(r,userRules.today);a.principal+=Math.max(0,r.open);a.fine+=c.fine;a.interest+=c.interest;a.discount+=c.discount;return a},{principal:0,fine:0,interest:0,discount:0});if(selectedHeaderCount)selectedHeaderCount.textContent=String(rows.length);if(selectedHeaderTotal)selectedHeaderTotal.textContent=money(s.principal);if(selectedHeaderFine)selectedHeaderFine.textContent=money(s.fine);if(selectedHeaderInterest)selectedHeaderInterest.textContent=money(s.interest);if(selectedHeaderUpdated)selectedHeaderUpdated.textContent=money(Math.max(0,s.principal+s.fine+s.interest-s.discount));if(selectedSummary)selectedSummary.hidden=rows.length===0;if(openBtn)openBtn.disabled=rows.length===0;if(checkAll){checkAll.checked=rows.length>0&&rows.length===checks.length;checkAll.indeterminate=rows.length>0&&rows.length<checks.length}}
  function saveBulkDraft(){rowsBox?.querySelectorAll('.bulk-settle-row[data-id]').forEach(row=>{bulkDraft.set(row.dataset.id,{fine:row.querySelector('[data-field=fine]')?.value||'0,00',interest:row.querySelector('[data-field=interest]')?.value||'0,00',discount:row.querySelector('[data-field=discount]')?.value||'0,00',paid:row.querySelector('[data-field=paid]')?.value||'0,00'})})}
  function bulkUpdatedOf(row){return Math.max(0,parse(row.querySelector('[data-field=open]')?.value)+parse(row.querySelector('[data-field=fine]')?.value)+parse(row.querySelector('[data-field=interest]')?.value)-parse(row.querySelector('[data-field=discount]')?.value))}
  window.refreshBulkTotals=function(){let principal=0,fine=0,interest=0,discount=0,upd=0,paid=0;rowsBox?.querySelectorAll('.bulk-settle-row').forEach(row=>{const p=Math.max(0,parse(row.querySelector('[data-field=open]')?.value)),f=Math.max(0,parse(row.querySelector('[data-field=fine]')?.value)),i=Math.max(0,parse(row.querySelector('[data-field=interest]')?.value)),d=Math.max(0,parse(row.querySelector('[data-field=discount]')?.value)),u=Math.max(0,p+f+i-d),pay=Math.max(0,parse(row.querySelector('[data-field=paid]')?.value));principal+=p;fine+=f;interest+=i;discount+=d;upd+=u;paid+=Math.min(pay,u);const ue=row.querySelector('[data-role=updated]'),be=row.querySelector('[data-role=balance]');if(ue)ue.textContent='R$ '+money(u);if(be){const bal=Math.max(0,u-pay);be.textContent='R$ '+money(bal);be.classList.toggle('paid',bal<.005);be.classList.toggle('partial',bal>=.005&&pay>0)}});if(principalEl)principalEl.textContent=money(principal);if(fineEl)fineEl.textContent=money(fine);if(interestEl)interestEl.textContent=money(interest);if(discountEl)discountEl.textContent=money(discount);if(updatedEl)updatedEl.textContent=money(upd);if(totalEl)totalEl.textContent=money(paid);if(remainingEl)remainingEl.textContent=money(Math.max(0,upd-paid));saveBulkDraft();document.querySelectorAll('#bulkSettleForm .settlement-payment-editor').forEach(syncPaymentEditor)}
  window.applyBulkFull=function(){rowsBox?.querySelectorAll('.bulk-settle-row').forEach(row=>{const input=row.querySelector('[data-field=paid]');if(input)input.value=money(bulkUpdatedOf(row))});if(partialTotal)partialTotal.value=updatedEl?.textContent||'0,00';refreshBulkTotals()}
  window.distributeBulkPartial=function(){let remaining=Math.max(0,parse(partialTotal?.value));const rows=Array.from(rowsBox?.querySelectorAll('.bulk-settle-row')||[]),max=rows.reduce((sum,row)=>sum+bulkUpdatedOf(row),0);if(remaining>max+.01){nexaAttention('O valor recebido é maior que o total atualizado. O valor será limitado ao total selecionado.');remaining=max;if(partialTotal)partialTotal.value=money(max)}rows.forEach(row=>{const u=bulkUpdatedOf(row),pay=Math.min(u,remaining),input=row.querySelector('[data-field=paid]');if(input)input.value=money(pay);remaining=Math.max(0,remaining-pay)});refreshBulkTotals()}
  window.currentBulkMode=function(){return document.querySelector('input[name=bulk_mode_ui]:checked')?.value||'full'}
  function renderBulk(){saveBulkDraft();const rows=selectedRows();renderSelectedHeader();if(countEl)countEl.textContent=String(rows.length);if(!rowsBox)return;rowsBox.innerHTML=rows.map(r=>{const auto=creCalc(r),d=bulkDraft.get(r.id)||{fine:money(auto.fine),interest:money(auto.interest),discount:money(auto.discount),paid:money(Math.max(0,r.open+auto.fine+auto.interest-auto.discount))};const fineWaived=auto.fine>.009&&parse(d.fine)<.009,interestWaived=auto.interest>.009&&parse(d.interest)<.009;return `<article class="bulk-settle-row compact" data-id="${esc(r.id)}" data-auto-fine="${auto.fine.toFixed(2)}" data-auto-interest="${auto.interest.toFixed(2)}" data-auto-discount="${auto.discount.toFixed(2)}"><input type="hidden" name="title_ids[]" value="${esc(r.id)}"><input type="hidden" data-field="open" value="${r.open.toFixed(2)}"><div class="bulk-title-info"><strong>${esc(r.person||'Cliente')}</strong><small>#${esc(r.id)} · Doc./Pedido ${esc(r.doc||'-')} · Parc. ${esc(r.inst||'-')} · Venc. ${esc(r.due||'-')} ${r.payment?`· Pgto. ${esc(r.payment)}`:''}</small><small class="bulk-history-line">${esc(r.desc||'')}</small><div class="bulk-cre-meta"><span>Principal <b>R$ ${money(r.open)}</b></span><span>CRE multa <b>R$ ${money(auto.fine)}</b></span><span>CRE juros <b>R$ ${money(auto.interest)}</b></span><span>Atualizado <b data-role="updated">R$ ${money(r.open)}</b></span><span>Saldo <b class="bulk-balance" data-role="balance">R$ 0,00</b></span></div><div class="bulk-waive-actions">${auto.fine>.009?`<button type="button" class="btn ${fineWaived?'waived':''}" data-waive-type="fine">${fineWaived?'Multa dispensada':'Dispensar multa'}</button>`:''}${auto.interest>.009?`<button type="button" class="btn ${interestWaived?'waived':''}" data-waive-type="interest">${interestWaived?'Juros dispensados':'Dispensar juros'}</button>`:''}</div></div><div class="bulk-settle-fields compact-fields">${auto.lateDays>0?`<div class="bulk-late-wrap"><label class="bulk-late-days-label">Atraso</label><div class="bulk-late-days">${auto.lateDays} dia${auto.lateDays===1?'':'s'}</div></div>`:''}<div><label>Multa</label><input data-field="fine" name="bulk_fine_amount[${esc(r.id)}]" value="${esc(d.fine||'0,00')}"></div><div><label>Juros</label><input data-field="interest" name="bulk_interest_amount[${esc(r.id)}]" value="${esc(d.interest||'0,00')}"></div><div><label>Desconto</label><input data-field="discount" name="bulk_discount_amount[${esc(r.id)}]" value="${esc(d.discount||'0,00')}"></div><div class="bulk-paid-field"><label>Valor da baixa</label><input data-field="paid" name="bulk_total_paid[${esc(r.id)}]" value="${esc(d.paid||money(r.open))}"></div></div></article>`}).join('');if(currentBulkMode()==='full')applyBulkFull();else refreshBulkTotals()}
  function openBulk(){renderBulk();if(!selectedRows().length){nexaAttention('Selecione pelo menos uma parcela.');return}showModal(bulkModal);setTimeout(()=>bulkForm?.querySelector('[data-role=payment-code]')?.focus(),80)}
  checks.forEach(c=>c.addEventListener('change',renderSelectedHeader));checkAll?.addEventListener('change',()=>{checks.forEach(c=>c.checked=checkAll.checked);renderSelectedHeader()});openBtn?.addEventListener('click',openBulk);closeBtn?.addEventListener('click',()=>hideModal(bulkModal));cancelBtn?.addEventListener('click',()=>hideModal(bulkModal));bulkModal?.addEventListener('click',e=>{if(e.target===bulkModal)hideModal(bulkModal)});
  document.querySelectorAll('input[name=bulk_mode_ui]').forEach(r=>r.addEventListener('change',()=>{const partial=currentBulkMode()==='partial';if(partialTotal)partialTotal.disabled=!partial;if(partial){partialTotal.value=totalEl?.textContent||'0,00';distributeBulkPartial();setTimeout(()=>partialTotal?.focus(),0)}else applyBulkFull()}));partialTotal?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();distributeBulkPartial();partialTotal.blur()}});partialTotal?.addEventListener('blur',()=>{if(currentBulkMode()==='partial')distributeBulkPartial()});partialTotal?.addEventListener('change',()=>{if(currentBulkMode()==='partial')distributeBulkPartial()});
  rowsBox?.addEventListener('input',e=>{if(e.target?.matches('[data-field=fine],[data-field=interest],[data-field=discount]')){if(currentBulkMode()==='full')applyBulkFull();else distributeBulkPartial()}else refreshBulkTotals()});
  document.getElementById('bulkSettlementDate')?.addEventListener('change',()=>{bulkDraft.clear();renderBulk()});
  applyGeneralDiscount?.addEventListener('click',()=>{const pct=Math.max(0,parse(generalDiscount?.value));if(userRules.discountLimit!==null&&pct>Number(userRules.discountLimit)+.0001){nexaAttention('O desconto informado ultrapassa o limite deste usuário ('+money(userRules.discountLimit)+'%).');return}rowsBox?.querySelectorAll('.bulk-settle-row').forEach(row=>{const open=parse(row.querySelector('[data-field=open]')?.value),auto=parse(row.dataset.autoDiscount||0),input=row.querySelector('[data-field=discount]');if(input)input.value=money(pct>0?open*pct/100:auto)});if(currentBulkMode()==='full')applyBulkFull();else distributeBulkPartial()});
  bulkForm?.addEventListener('submit',e=>{refreshBulkTotals();if(bulkForm.dataset.confirmed==='1'){bulkForm.dataset.confirmed='';return}e.preventDefault();if(!selectedRows().length||parse(totalEl?.textContent)<=0){nexaAttention('Selecione parcelas e informe um valor de baixa maior que zero.');return}const editor=bulkForm.querySelector('.settlement-payment-editor');if(editor&&!syncPaymentEditor(editor)){nexaAttention('A soma das formas de pagamento precisa fechar exatamente o total da baixa.');return}nexaConfirm('Confirmar a baixa das parcelas selecionadas no valor de R$ '+(totalEl?.textContent||'0,00')+'?').then(ok=>{if(!ok)return;bulkForm.dataset.confirmed='1';bulkForm.requestSubmit?.()})});
  document.addEventListener('keydown',e=>{if(e.key!=='F9'||messageModal?.hidden===false)return;const openBulkModal=bulkModal&&!bulkModal.hidden;if(openBulkModal&&bulkForm){e.preventDefault();bulkForm.requestSubmit?.();return}const singleForm=Array.from(document.querySelectorAll('[data-settlement-form]')).find(f=>f.offsetParent!==null);if(singleForm){e.preventDefault();singleForm.requestSubmit?.()}});
  renderSelectedHeader();

  // Baixas individuais abertas pela ação Baixar ou dentro da edição do título.
  window.recalcSingleForm=function(form){if(!form)return;const principal=Math.max(0,parse(form.querySelector('[data-role=single-principal]')?.value)),fine=Math.max(0,parse(form.querySelector('[data-role=single-fine]')?.value)),interest=Math.max(0,parse(form.querySelector('[data-role=single-interest]')?.value)),discount=Math.max(0,parse(form.querySelector('[data-role=single-discount]')?.value)),total=Math.max(0,principal+fine+interest-discount);const map={principal,fine,interest,discount,total};Object.entries(map).forEach(([k,v])=>{const el=form.querySelector(`[data-role=single-total-${k}]`);if(el)el.textContent=money(v)});const hidden=form.querySelector('[data-role=single-grand-total]');if(hidden)hidden.value=money(total);form.querySelectorAll('.settlement-payment-editor').forEach(syncPaymentEditor)}
  document.querySelectorAll('[data-settlement-form]').forEach(form=>{form.addEventListener('input',e=>{if(e.target.matches('[data-role=single-principal],[data-role=single-fine],[data-role=single-interest],[data-role=single-discount]'))recalcSingleForm(form)});form.querySelector('[data-role=apply-general-discount]')?.addEventListener('click',()=>{const pct=Math.max(0,parse(form.querySelector('[data-role=general-discount]')?.value));if(userRules.discountLimit!==null&&pct>Number(userRules.discountLimit)+.0001){nexaAttention('O desconto informado ultrapassa o limite deste usuário ('+money(userRules.discountLimit)+'%).');return}const principal=Math.max(0,parse(form.querySelector('[data-role=single-principal]')?.value)),auto=parse(form.dataset.autoDiscount||0),input=form.querySelector('[data-role=single-discount]');if(input)input.value=money(pct>0?principal*pct/100:auto);recalcSingleForm(form)});form.addEventListener('submit',e=>{recalcSingleForm(form);const editor=form.querySelector('.settlement-payment-editor');if(editor&&!syncPaymentEditor(editor)){e.preventDefault();nexaAttention('A soma das formas de pagamento precisa fechar exatamente o total da baixa.')}});recalcSingleForm(form)});
});
</script>



<style>
.receivable-edit-tabs{display:flex;gap:8px;align-items:center;margin:14px 0 4px;padding-bottom:8px;border-bottom:1px solid #dbe3f1}
.receivable-edit-tab{border:1px solid #d7e0ef;background:#fff;color:#172554;border-radius:10px;padding:9px 14px;font-weight:800;cursor:pointer}
.receivable-edit-tab.is-active{background:#3155c8;border-color:#3155c8;color:#fff}
.receivable-edit-panel[hidden]{display:none!important}
.receivable-bank-summary{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;margin-bottom:12px}
.receivable-bank-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.receivable-bank-field{border:1px solid #dbe3f1;background:#f8faff;border-radius:12px;padding:10px 12px;min-width:0}
.receivable-bank-field span{display:block;color:#64748b;font-size:12px;margin-bottom:4px}
.receivable-bank-field strong,.receivable-bank-field code{display:block;color:#172554;overflow-wrap:anywhere;word-break:break-word}
.receivable-bank-field.span-2{grid-column:span 2}.receivable-bank-field.span-3{grid-column:1/-1}
.receivable-bank-text{width:100%;min-height:90px;resize:vertical;font-family:ui-monospace,SFMono-Regular,Consolas,monospace;font-size:12px}
.receivable-bank-technical{margin-top:12px}.receivable-bank-technical details{border:1px solid #dbe3f1;border-radius:12px;padding:10px 12px;margin-top:8px;background:#fff}
@media(max-width:900px){.receivable-bank-grid{grid-template-columns:1fr}.receivable-bank-field.span-2,.receivable-bank-field.span-3{grid-column:auto}.receivable-edit-tabs{overflow-x:auto}.receivable-edit-tab{white-space:nowrap}}
</style>
<script>
document.addEventListener('DOMContentLoaded',()=>{
  const tabs=[...document.querySelectorAll('[data-receivable-edit-tab]')];
  const panels=[...document.querySelectorAll('[data-receivable-edit-panel]')];
  if(!tabs.length||!panels.length)return;
  const activate=(name)=>{
    tabs.forEach(b=>b.classList.toggle('is-active',b.dataset.receivableEditTab===name));
    panels.forEach(p=>p.hidden=p.dataset.receivableEditPanel!==name);
    try{const u=new URL(window.location.href);u.searchParams.set('tab',name);history.replaceState(null,'',u.toString())}catch(e){}
  };
  tabs.forEach(b=>b.addEventListener('click',()=>activate(b.dataset.receivableEditTab||'dados')));
});
</script>

<?php if ($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog finance-modal-wide"><div class="finance-modal-head"><div><h2><?= $crossProtectedView ? 'Detalhes do título #' . (int)$editId : ($editId ? 'Editar título #' . (int)$editId : 'Novo contas a receber') ?></h2><p class="finance-muted"><?= $crossProtectedView ? 'Título protegido no F11. Os dados cadastrais ficam somente para consulta; baixas e estornos permanecem disponíveis conforme as permissões.' : 'Preencha os dados do título. A baixa gera movimento financeiro automaticamente.' ?></p></div><a class="finance-modal-close" href="financeiro_contas_receber.php">×</a></div>
<?php if ($postAction === 'reverse_settlement' && $err !== ''): ?><div class="msg err" style="margin:14px 18px 0"><strong>Estorno não concluído:</strong> <?= finance_h($err) ?></div><?php endif; ?>
<?php if($edit && !empty($edit['id']) && (!$financeCrossContext || $canViewBilling)): ?>
<div class="receivable-edit-tabs" role="tablist" aria-label="Detalhes da conta a receber">
  <button type="button" class="receivable-edit-tab <?= $editTab==='dados'?'is-active':'' ?>" data-receivable-edit-tab="dados">Dados do título</button>
  <button type="button" class="receivable-edit-tab <?= $editTab==='cobranca'?'is-active':'' ?>" data-receivable-edit-tab="cobranca">Cobrança Bancária<?= $editCharge ? ' · #'.(int)$editCharge['id'] : '' ?></button>
</div>
<div class="receivable-edit-panel" data-receivable-edit-panel="dados" <?= $editTab==='dados'?'':'hidden' ?>>
<?php endif; ?>
<form method="post" class="finance-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <fieldset <?= $crossProtectedView ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="finance-form-section"><h3>Dados do título</h3><div class="finance-form-grid three">
    <div><label>Cliente *</label><select name="person_id" required><?php finance_select_options($customers, $edit['person_id'] ?? '', 'Selecione o cliente'); ?></select><div class="hint"><a href="<?= $financeCrossContext ? 'financeiro_pessoas.php?new=1&role=customer&mc_customer=1' : 'financeiro_pessoas.php?new=1' ?>">Cadastrar pessoa</a></div></div>
    <div><label>Plano de contas *</label><select name="chart_account_id" required><?php finance_select_options($charts, $edit['chart_account_id'] ?? '', 'Selecione conta analítica'); ?></select><?php if ($financeCrossContext): ?><div class="hint"><a href="financeiro_plano_contas.php?new=1">Cadastrar conta na empresa do F11</a></div><?php endif; ?></div>
    <div><label>Centro de custo</label><select name="cost_center_id"><?php finance_select_options($costs, $edit['cost_center_id'] ?? '', 'Opcional'); ?></select><?php if ($financeCrossContext): ?><div class="hint"><a href="financeiro_centros_custo.php?new=1">Cadastrar centro na empresa do F11</a></div><?php endif; ?></div>
    <div class="span-3"><label>Histórico</label><input name="description" value="<?= finance_h($edit['description'] ?? '') ?>"></div>
    <div><label>Nº documento</label><input name="document_number" value="<?= finance_h($edit['document_number'] ?? '') ?>"></div>
    <div><label>Parcela</label><input name="installment" value="<?= finance_h($edit['installment'] ?? '01/01') ?>"></div>
    <div><label>Meio previsto</label><input name="expected_payment_method" value="<?= finance_h($edit['expected_payment_method'] ?? '') ?>" placeholder="Pix, boleto, cartão..."></div>
  </div></div>
  <div class="finance-form-section"><h3>Datas e valores</h3><div class="finance-form-grid three">
    <div><label>Data emissão</label><input type="date" name="issue_date" value="<?= finance_h($edit['issue_date'] ?? date('Y-m-d')) ?>"></div>
    <div><label>Vencimento *</label><input type="date" name="due_date" value="<?= finance_h($edit['due_date'] ?? $creDefaultDue) ?>" required <?= $editDueLocked?'readonly':'' ?>><?php if($editDueLocked): ?><div class="hint">Vencimento bloqueado porque o boleto já possui comunicação/registro bancário (<?= finance_h($editChargeStatus) ?>). Isso evita divergência entre Nexa e Itaú.</div><?php elseif($editCharge && $editChargeStatus!=='CANCELADO'): ?><div class="hint">Enquanto o boleto não estiver registrado, a alteração desta data também atualiza a cobrança pendente.</div><?php endif; ?></div>
    <div><label><?= $editId ? 'Valor do título *' : 'Valor total a receber *' ?></label><input name="amount" value="<?= finance_money_br($edit['amount'] ?? 0) ?>" required></div>
    <div><label>Multa</label><input name="fine_amount" value="<?= finance_money_br($edit['fine_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Juros</label><input name="interest_amount" value="<?= finance_money_br($edit['interest_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Desconto</label><input name="discount_amount" value="<?= finance_money_br($edit['discount_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Valor baixado</label><input name="paid_amount" value="<?= finance_money_br($edit['paid_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Valor em aberto</label><input readonly value="<?= finance_money_br($edit['open_amount'] ?? 0) ?>"></div>
  </div></div>
  <?php if (!$editId): ?>
  <div class="finance-form-section" id="nexaReceivableParcelSection">
    <h3>Parcelamento do recebimento</h3>
    <div class="finance-form-grid three">
      <div><label>Quantidade de parcelas</label><input name="parcel_count" id="receivableParcelCount" type="number" min="1" max="120" value="1"></div>
      <div class="span-2" style="align-self:end"><span class="finance-muted">Divisão mensal automática do valor total. Ajuste valores e vencimentos antes de salvar.</span></div>
    </div>
    <div id="receivableParcelWrap" style="display:none;margin-top:12px;overflow-x:auto">
      <table class="finance-list-table" style="width:100%"><thead><tr><th>Parcela</th><th>Valor (R$)</th><th>Vencimento</th></tr></thead><tbody id="receivableParcelRows"></tbody></table>
      <p class="finance-muted" id="receivableParcelSummary" aria-live="polite"></p>
    </div>
  </div>
  <?php endif; ?>
  <div class="finance-form-section"><h3>Observações</h3><textarea name="notes" rows="3"><?= finance_h($edit['notes'] ?? '') ?></textarea></div>
  </fieldset>
  <div class="finance-form-actions"><a class="btn" href="financeiro_contas_receber.php"><?= $crossProtectedView ? 'Voltar' : 'Cancelar' ?></a><?php if (!$crossProtectedView): ?><button class="btn btn-primary">Salvar título</button><?php endif; ?></div>
</form>

<?php if ($edit && $canSettleTitles && in_array((string)$edit['status'], ['ABERTO','PARCIAL'], true) && (!$financeCrossContext || !$editCharge || strtoupper((string)($editCharge['status']??''))==='CANCELADO')): ?>
<div class="finance-form-section"><h3>Baixar título</h3>
<form method="post" class="finance-form" data-settlement-form data-auto-discount="<?= finance_h(number_format((float)($editCre['discount_amount'] ?? 0),2,'.','')) ?>">
  <input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><input type="hidden" name="waiver_auth_token" data-role="waiver-token" value=""><input type="hidden" id="editSettleGrandTotal" data-role="single-grand-total" value="0,00">
  <div class="finance-form-grid three">
    <div><label>Data baixa</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Valor principal da baixa</label><input name="amount" data-role="single-principal" value="<?= finance_money_br($editPrincipalOpen) ?>"></div>
    <div class="waiver-inline"><div><label>Multa</label><input name="fine_amount" data-role="single-fine" value="<?= finance_money_br($editCre['fine_amount'] ?? 0) ?>"></div><?php if ((float)($editCre['fine_amount'] ?? 0) > 0.009): ?><button type="button" class="btn" data-waive-type="fine">Dispensar multa</button><?php endif; ?></div>
    <div class="waiver-inline"><div><label>Juros</label><input name="interest_amount" data-role="single-interest" value="<?= finance_money_br($editCre['interest_amount'] ?? 0) ?>"></div><?php if ((float)($editCre['interest_amount'] ?? 0) > 0.009): ?><button type="button" class="btn" data-waive-type="interest">Dispensar juros</button><?php endif; ?></div>
    <div><label>Desconto (R$)</label><input name="discount_amount" data-role="single-discount" value="<?= finance_money_br($editCre['discount_amount'] ?? 0) ?>"></div>
    <div class="settlement-discount-general"><div><label>Desconto geral (%)</label><input data-role="general-discount" inputmode="decimal" placeholder="0,00"></div><button type="button" class="btn" data-role="apply-general-discount">Aplicar</button></div>
    <div class="span-3"><label>Obs. baixa</label><input name="notes"></div>
  </div>
  <div class="single-settle-totals">
    <div class="single-settle-total"><span>Principal</span><strong>R$ <b data-role="single-total-principal">0,00</b></strong></div>
    <div class="single-settle-total"><span>Multa</span><strong>R$ <b data-role="single-total-fine">0,00</b></strong></div>
    <div class="single-settle-total"><span>Juros</span><strong>R$ <b data-role="single-total-interest">0,00</b></strong></div>
    <div class="single-settle-total"><span>Desconto</span><strong>R$ <b data-role="single-total-discount">0,00</b></strong></div>
    <div class="single-settle-total highlight"><span>Total da baixa</span><strong>R$ <b data-role="single-total-total">0,00</b></strong></div>
  </div>
  <?php if ($settlementDiscountLimit !== null): ?><div class="discount-limit-note">Limite de desconto manual deste usuário: <strong><?= finance_h(number_format((float)$settlementDiscountLimit,2,',','.')) ?>%</strong>.</div><?php endif; ?>
  <div style="margin-top:12px"><h3>Formas de pagamento</h3><?php receivable_payment_editor($paymentMethods, $banks, 'editSettle', '#editSettleGrandTotal', '0,00'); ?></div>
  <?php if ($cashCrActiveForCompany): ?><p class="finance-muted" style="margin:8px 0">Caixa C/R: <?= $cashCrOpenForUser ? 'formas que movimentam caixa serão incluídas no caixa aberto deste operador.' : 'não existe caixa aberto para este operador; esta baixa não entrará no caixa operacional.' ?></p><?php endif; ?>
  <div class="finance-form-actions"><button class="btn btn-primary">Baixar título (F9)</button></div>
</form></div>
<?php endif; ?>

<?php if ($edit): ?>
<div class="finance-form-section"><h3>Baixas do título</h3><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Data</th><th>Forma(s)</th><th>Valor</th><th>Dispensas</th><th>Status</th><th>Baixado por</th><th>Movimento</th><th>Ações</th></tr></thead><tbody>
<?php if(!$settlements): ?><tr><td colspan="8" class="finance-empty">Nenhuma baixa registrada.</td></tr><?php endif; ?>
<?php foreach($settlements as $s): $spList=$settlementPaymentMap[(int)$s['id']]??[]; $sStatus=strtoupper((string)($s['status']??'ATIVO')); ?>
<tr>
  <td data-label="Data"><?= finance_date_br($s['settlement_date'] ?? '') ?></td>
  <td data-label="Forma(s)"><?php if($spList): ?><?php foreach($spList as $sp): ?><div><strong><?= finance_h($sp['payment_method'] ?? '') ?></strong> · R$ <?= finance_money_br($sp['amount'] ?? 0) ?><?= !empty($sp['bank_name'])?' · '.finance_h($sp['bank_name']):'' ?></div><?php endforeach; ?><?php else: ?><?= finance_h($s['payment_method'] ?? '') ?><?= !empty($s['bank_name'])?' · '.finance_h($s['bank_name']):'' ?><?php endif; ?></td>
  <td data-label="Valor" class="finance-value pos">R$ <?= finance_money_br((float)$s['amount'] + (float)$s['fine_amount'] + (float)$s['interest_amount'] - (float)$s['discount_amount']) ?></td>
  <td data-label="Dispensas"><?php $wf=(float)($s['waived_fine_amount']??0);$wi=(float)($s['waived_interest_amount']??0); ?><?= $wf>0.009?'Multa R$ '.finance_money_br($wf):'' ?><?= ($wf>0.009&&$wi>0.009)?'<br>':'' ?><?= $wi>0.009?'Juros R$ '.finance_money_br($wi):($wf<=0.009?'—':'') ?></td>
  <td data-label="Status"><strong><?= finance_h($sStatus) ?></strong><?php if($sStatus==='ESTORNADO' && !empty($s['reversal_reason'])):?><div class="finance-muted" style="margin-top:4px"><?= finance_h($s['reversal_reason']) ?></div><?php endif;?></td>
  <td data-label="Baixado por"><?= finance_h($s['settled_by_name'] ?? 'Não identificado') ?></td><td data-label="Movimento">#<?= (int)($s['movement_id'] ?? 0) ?></td>
  <td data-label="Ações"><?php if($sStatus==='ATIVO' && $canReverseSettlements): ?><form method="post" data-no-processing onsubmit="return confirm('Confirmar o estorno desta baixa?');" style="display:flex;gap:6px;align-items:center;flex-wrap:wrap;min-width:260px"><input type="hidden" name="action" value="reverse_settlement"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><input type="hidden" name="settlement_id" value="<?= (int)$s['id'] ?>"><input type="text" name="reversal_reason" required minlength="3" maxlength="500" placeholder="Motivo do estorno" style="min-width:165px;flex:1"><button type="submit" class="btn btn-danger">Estornar</button></form><?php else:?><span class="finance-muted">—</span><?php endif;?></td>
</tr>
<?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php if($edit && !empty($edit['id']) && (!$financeCrossContext || $canViewBilling)): ?>
</div>
<div class="receivable-edit-panel" data-receivable-edit-panel="cobranca" <?= $editTab==='cobranca'?'':'hidden' ?>>
  <div class="finance-form-section">
    <div class="receivable-bank-summary"><div><h3 style="margin:0">Cobrança Bancária</h3><p class="finance-muted" style="margin:4px 0 0">Dados do boleto vinculado diretamente a esta Conta a Receber.<?= $financeCrossContext ? ' No F11 esta aba é somente consulta.' : '' ?></p></div><?php if($editCharge): ?><div class="finance-inline-actions" style="flex-wrap:wrap"><?php if(trim((string)($editCharge['linha_digitavel']??''))!=='' && trim((string)($editCharge['barcode']??''))!==''): ?><?php if($financeCrossContext): ?><?php
  $chargeReturn='financeiro_contas_receber.php?edit='.(int)$edit['id'].'&tab=cobranca';
  $boletoHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_imprimir.php?charge_id='.(int)$editCharge['id'].'&return_to='.rawurlencode($chargeReturn)):'financeiro_boleto_imprimir.php?charge_id='.(int)$editCharge['id'];
  $boletoWaHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_whatsapp.php?charge_id='.(int)$editCharge['id'].'&return_to='.rawurlencode($chargeReturn)):'financeiro_boleto_whatsapp.php?charge_id='.(int)$editCharge['id'];
  $boletoEmailHref=function_exists('multi_company_context_url')?multi_company_context_url('email_documento.php?type=boleto&charge_id='.(int)$editCharge['id'].'&return_to='.rawurlencode($chargeReturn)):'email_documento.php?type=boleto&charge_id='.(int)$editCharge['id'];
?><a class="btn" href="<?= finance_h($boletoHref) ?>" target="_blank">Boleto</a><?php if($financeCrossBillingSend): ?><a class="btn receivable-boleto-whatsapp" href="<?= finance_h($boletoWaHref) ?>">WhatsApp</a><a class="btn" href="<?= finance_h($boletoEmailHref) ?>">✉ E-mail</a><?php endif; ?><?php else: ?><a class="btn" href="financeiro_boleto_imprimir.php?charge_id=<?= (int)$editCharge['id'] ?>&return_to=<?= rawurlencode('financeiro_contas_receber.php?edit='.(int)$edit['id'].'&tab=cobranca') ?>" target="_blank">Boleto</a><a class="btn receivable-boleto-whatsapp" href="financeiro_boleto_whatsapp.php?charge_id=<?= (int)$editCharge['id'] ?>&return_to=<?= rawurlencode('financeiro_contas_receber.php?edit='.(int)$edit['id'].'&tab=cobranca') ?>">WhatsApp</a><a class="btn" href="email_documento.php?type=boleto&charge_id=<?= (int)$editCharge['id'] ?>&return_to=<?= rawurlencode('financeiro_contas_receber.php?edit='.(int)$edit['id'].'&tab=cobranca') ?>">✉ E-mail</a><?php endif; ?><?php endif; ?><?php if($financeCrossContext): ?><?php $itauHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_itau.php?id='.(int)$editCharge['id']):'financeiro_boleto_itau.php?id='.(int)$editCharge['id']; ?><a class="btn" href="<?= finance_h($itauHref) ?>">Abrir dados Itaú</a><?php else: ?><a class="btn" href="financeiro_boleto_itau.php?id=<?= (int)$editCharge['id'] ?>">Abrir dados Itaú</a><?php endif; ?></div><?php endif; ?></div>
    <?php if(!$editCharge): ?>
      <div class="finance-empty">Nenhuma cobrança bancária está vinculada a este título.</div>
    <?php else: $chargeStatus=strtoupper((string)($editCharge['status']??'')); ?>
      <div class="receivable-bank-grid">
        <div class="receivable-bank-field"><span>ID da cobrança</span><strong>#<?= (int)$editCharge['id'] ?></strong></div>
        <div class="receivable-bank-field"><span>Status</span><strong><span class="finance-badge <?= in_array($chargeStatus,['REGISTRADO','PAGO'],true)?'green':'gray' ?>"><?= finance_h($editCharge['status']??'—') ?></span></strong></div>
        <div class="receivable-bank-field"><span>Banco / conta</span><strong><?= finance_h($editCharge['bank_account_name']??'—') ?></strong></div>
        <div class="receivable-bank-field"><span>Provedor / integração</span><strong><?= finance_h(trim((string)($editCharge['provider']??'').' / '.(string)($editCharge['integration_mode']??''),' /')) ?: '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Emissão</span><strong><?= finance_date_br($editCharge['issue_date']??'') ?: '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Vencimento</span><strong><?= finance_date_br($editCharge['due_date']??'') ?: '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Valor do boleto</span><strong>R$ <?= finance_money_br($editCharge['amount']??0) ?></strong></div>
        <div class="receivable-bank-field"><span>Nosso Número</span><strong><?= finance_h($editCharge['nosso_numero']??'—') ?></strong></div>
        <div class="receivable-bank-field"><span>Seu Número</span><strong><?= finance_h($editCharge['seu_numero']??'—') ?></strong></div>
        <div class="receivable-bank-field"><span>DAC do título</span><strong><?= finance_h($editCharge['dac_titulo']??'—') ?></strong></div>
        <div class="receivable-bank-field span-2"><span>ID externo / boleto</span><strong><?= finance_h($editCharge['external_id']??'—') ?></strong></div>
        <div class="receivable-bank-field span-3"><span>Linha digitável</span><code><?= finance_h($editCharge['linha_digitavel']??'—') ?></code></div>
        <div class="receivable-bank-field span-3"><span>Código de barras</span><code><?= finance_h($editCharge['barcode']??'—') ?></code></div>
        <div class="receivable-bank-field"><span>TXID Pix</span><strong><?= finance_h($editCharge['pix_txid']??'—') ?></strong></div>
        <div class="receivable-bank-field span-2"><span>Chave Pix</span><strong><?= finance_h($editCharge['pix_key']??'—') ?></strong></div>
        <div class="receivable-bank-field span-3"><span>Pix copia e cola</span><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['pix_emv']??'') ?></textarea></div>
        <div class="receivable-bank-field"><span>Registrado em</span><strong><?= !empty($editCharge['registered_at']) ? date('d/m/Y H:i',strtotime((string)$editCharge['registered_at'])) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Data do pagamento</span><strong><?= finance_date_br($editCharge['payment_date']??'') ?: '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Valor pago</span><strong><?= isset($editCharge['paid_amount']) && $editCharge['paid_amount']!==null ? 'R$ '.finance_money_br($editCharge['paid_amount']) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Data do crédito</span><strong><?= finance_date_br($editCharge['credit_date']??'') ?: '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Valor creditado</span><strong><?= isset($editCharge['credited_amount']) && $editCharge['credited_amount']!==null ? 'R$ '.finance_money_br($editCharge['credited_amount']) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Último webhook</span><strong><?= !empty($editCharge['last_webhook_at']) ? date('d/m/Y H:i',strtotime((string)$editCharge['last_webhook_at'])) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>HTTP API</span><strong><?= finance_h((string)($editCharge['api_http_status']??'—')) ?></strong></div>
        <div class="receivable-bank-field span-2"><span>Correlation ID</span><strong><?= finance_h($editCharge['api_correlation_id']??'—') ?></strong></div>
        <div class="receivable-bank-field span-3"><span>URL do boleto</span><?php if(!empty($editCharge['boleto_url'])): ?><a href="<?= finance_h((string)$editCharge['boleto_url']) ?>" target="_blank" rel="noopener"><?= finance_h((string)$editCharge['boleto_url']) ?></a><?php else: ?><strong>—</strong><?php endif; ?></div>
        <div class="receivable-bank-field span-3"><span>Arquivo de remessa</span><strong><?= finance_h($editCharge['remittance_file']??'—') ?></strong></div>
        <div class="receivable-bank-field span-3"><span>Mensagem / erro</span><strong><?= finance_h($editCharge['error_message']??'—') ?></strong></div>
        <div class="receivable-bank-field"><span>Criado em</span><strong><?= !empty($editCharge['created_at']) ? date('d/m/Y H:i',strtotime((string)$editCharge['created_at'])) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>Atualizado em</span><strong><?= !empty($editCharge['updated_at']) ? date('d/m/Y H:i',strtotime((string)$editCharge['updated_at'])) : '—' ?></strong></div>
        <div class="receivable-bank-field"><span>ID Pedido / Contrato</span><strong><?= (int)($editCharge['order_id']??0)>0?'Pedido #'.(int)$editCharge['order_id']:((int)($editCharge['contract_id']??0)>0?'Contrato #'.(int)$editCharge['contract_id']:'—') ?></strong></div>
      </div>
      <div class="receivable-bank-technical">
        <details><summary><strong>Retorno da API Itaú</strong></summary><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['response_payload']??'') ?></textarea></details>
        <details><summary><strong>Payload enviado ao Itaú</strong></summary><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['request_payload']??'') ?></textarea></details>
        <details><summary><strong>Última notificação Webhook</strong></summary><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['last_webhook_payload']??'') ?></textarea></details>
        <?php if(!empty($editCharge['simulation_response_payload']) || !empty($editCharge['simulation_request_payload'])): ?><details><summary><strong>Dados da simulação</strong></summary><label>Retorno</label><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['simulation_response_payload']??'') ?></textarea><label style="margin-top:8px">Envio</label><textarea class="receivable-bank-text" readonly><?= finance_h($editCharge['simulation_request_payload']??'') ?></textarea></details><?php endif; ?>
      </div>
    <?php endif; ?>
  </div>
</div>
<?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($showSettle && $settleTitle): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog finance-modal-wide"><div class="finance-modal-head"><div><h2>Baixar título #<?= (int)$settleTitle['id'] ?></h2><p class="finance-muted">Confira o valor atualizado, descontos e formas de pagamento antes de confirmar.</p></div><a class="finance-modal-close" href="financeiro_contas_receber.php">×</a></div>
<div class="finance-form-section"><h3>Resumo do título</h3><div class="finance-form-grid three">
  <div><label>Cliente</label><input readonly value="<?= finance_h($settleTitle['person_name'] ?? '') ?>"></div>
  <div><label>Vencimento</label><input readonly value="<?= finance_date_br($settleTitle['due_date'] ?? '') ?>"></div>
  <div><label>Principal em aberto</label><input readonly value="R$ <?= finance_money_br($settlePrincipalOpen) ?>"></div>
  <div><label>Multa CRE hoje</label><input readonly value="R$ <?= finance_money_br($settleCre['fine_amount'] ?? 0) ?>"></div>
  <div><label>Juros CRE hoje</label><input readonly value="R$ <?= finance_money_br($settleCre['interest_amount'] ?? 0) ?>"></div>
  <div><label>Total atualizado hoje</label><input readonly value="R$ <?= finance_money_br(max(0,$settlePrincipalOpen + (float)($settleCre['fine_amount'] ?? 0) + (float)($settleCre['interest_amount'] ?? 0) - (float)($settleCre['discount_amount'] ?? 0))) ?>"></div>
  <div class="span-3"><label>Histórico</label><input readonly value="<?= finance_h($settleTitle['description'] ?? '') ?>"></div>
</div></div>
<?php if (in_array((string)$settleTitle['status'], ['ABERTO','PARCIAL'], true)): ?>
<div class="finance-form-section"><h3>Dados da baixa</h3>
<form method="post" class="finance-form" data-settlement-form data-auto-discount="<?= finance_h(number_format((float)($settleCre['discount_amount'] ?? 0),2,'.','')) ?>">
  <input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$settleTitle['id'] ?>"><input type="hidden" name="waiver_auth_token" data-role="waiver-token" value=""><input type="hidden" id="singleSettleGrandTotal" data-role="single-grand-total" value="0,00">
  <div class="finance-form-grid three">
    <div><label>Data baixa</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>"></div>
    <div><label>Valor principal da baixa</label><input name="amount" data-role="single-principal" value="<?= finance_money_br($settlePrincipalOpen) ?>"></div>
    <div class="waiver-inline"><div><label>Multa</label><input name="fine_amount" data-role="single-fine" value="<?= finance_money_br($settleCre['fine_amount'] ?? 0) ?>"></div><?php if ((float)($settleCre['fine_amount'] ?? 0) > 0.009): ?><button type="button" class="btn" data-waive-type="fine">Dispensar multa</button><?php endif; ?></div>
    <div class="waiver-inline"><div><label>Juros</label><input name="interest_amount" data-role="single-interest" value="<?= finance_money_br($settleCre['interest_amount'] ?? 0) ?>"></div><?php if ((float)($settleCre['interest_amount'] ?? 0) > 0.009): ?><button type="button" class="btn" data-waive-type="interest">Dispensar juros</button><?php endif; ?></div>
    <div><label>Desconto (R$)</label><input name="discount_amount" data-role="single-discount" value="<?= finance_money_br($settleCre['discount_amount'] ?? 0) ?>"></div>
    <div class="settlement-discount-general"><div><label>Desconto geral (%)</label><input data-role="general-discount" inputmode="decimal" placeholder="0,00"></div><button type="button" class="btn" data-role="apply-general-discount">Aplicar</button></div>
    <div class="span-3"><label>Obs. baixa</label><input name="notes"></div>
  </div>
  <div class="single-settle-totals">
    <div class="single-settle-total"><span>Principal</span><strong>R$ <b data-role="single-total-principal">0,00</b></strong></div>
    <div class="single-settle-total"><span>Multa</span><strong>R$ <b data-role="single-total-fine">0,00</b></strong></div>
    <div class="single-settle-total"><span>Juros</span><strong>R$ <b data-role="single-total-interest">0,00</b></strong></div>
    <div class="single-settle-total"><span>Desconto</span><strong>R$ <b data-role="single-total-discount">0,00</b></strong></div>
    <div class="single-settle-total highlight"><span>Total da baixa</span><strong>R$ <b data-role="single-total-total">0,00</b></strong></div>
  </div>
  <?php if ($settlementDiscountLimit !== null): ?><div class="discount-limit-note">Limite de desconto manual deste usuário: <strong><?= finance_h(number_format((float)$settlementDiscountLimit,2,',','.')) ?>%</strong>. <?= (float)$settlementDiscountLimit <= 0 ? 'Não é permitido desconto manual.' : '' ?></div><?php else: ?><div class="discount-limit-note">Este usuário está <strong>sem limite de desconto manual</strong> na baixa.</div><?php endif; ?>
  <div style="margin-top:12px"><h3>Formas de pagamento</h3><p class="finance-muted">Adicione uma ou mais formas. A soma precisa ser igual ao total da baixa.</p><?php receivable_payment_editor($paymentMethods, $banks, 'singleSettle', '#singleSettleGrandTotal', '0,00'); ?></div>
  <?php if ($cashCrActiveForCompany): ?><p class="finance-muted" style="margin:8px 0">Caixa C/R: <?= $cashCrOpenForUser ? 'formas que movimentam caixa serão incluídas no caixa aberto deste operador.' : 'não existe caixa aberto para este operador; esta baixa não entrará no caixa operacional.' ?></p><?php endif; ?>
  <div class="finance-form-actions"><a class="btn" href="financeiro_contas_receber.php">Cancelar</a><button class="btn btn-primary">Confirmar baixa (F9)</button></div>
</form></div>
<?php else: ?>
<div class="msg err">Este título não está em aberto para baixa.</div><div class="finance-form-actions"><a class="btn btn-primary" href="financeiro_contas_receber.php">Voltar</a></div>
<?php endif; ?>
</div></div>
<?php endif; ?>
<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>
<script>
(function(){
  if(!window.NexaBuscarF8)return;
  // A inclusão e a edição não possuem input oculto action=save.
  // Identificar o formulário pelos campos próprios do título, sem atingir os formulários de baixa.
  const form=Array.from(document.querySelectorAll('form.finance-form')).find(f =>
    f.querySelector('select[name="person_id"]') &&
    f.querySelector('select[name="chart_account_id"]') &&
    f.querySelector('select[name="cost_center_id"]')
  ) || null;
  if(!form)return;
  const f_person_id=form.querySelector('select[name="person_id"]');
  if(f_person_id)window.NexaBuscarF8.enhanceSelect(f_person_id,{title:'Buscar cliente',placeholder:'Selecione o cliente',registerUrl:'financeiro_pessoas.php?new=1&role=customer',registerLabel:'+ Cadastrar'});
  const f_chart_account_id=form.querySelector('select[name="chart_account_id"]');
  if(f_chart_account_id)window.NexaBuscarF8.enhanceSelect(f_chart_account_id,{title:'Buscar plano de contas',placeholder:'Selecione o plano de contas',registerUrl:'financeiro_plano_contas.php?new=1',registerLabel:'+ Cadastrar'});
  const f_cost_center_id=form.querySelector('select[name="cost_center_id"]');
  if(f_cost_center_id)window.NexaBuscarF8.enhanceSelect(f_cost_center_id,{title:'Buscar centro de custo',placeholder:'Selecione o centro de custo',registerUrl:'financeiro_centros_custo.php?new=1',registerLabel:'+ Cadastrar'});
})();
</script>
<?php if ($showForm && !$editId): ?>
<script>
(function(){
 const root=document.getElementById('nexaReceivableParcelSection');if(!root)return;
 const form=root.closest('form');const count=root.querySelector('#receivableParcelCount');const wrap=root.querySelector('#receivableParcelWrap');
 const rows=root.querySelector('#receivableParcelRows');const summary=root.querySelector('#receivableParcelSummary');
 const total=form.querySelector('[name="amount"]');const firstDue=form.querySelector('[name="due_date"]');
 const moneyIn=v=>{let s=String(v||'').trim().replace(/\s/g,'');if(s.includes(','))s=s.replace(/\./g,'').replace(',','.');return Math.round((Number(s)||0)*100)};
 const moneyOut=v=>(v/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
 const addMonths=(date,n)=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(date||''))return '';const [y,m,d]=date.split('-').map(Number);const base=new Date(Date.UTC(y,m-1+n,1));const last=new Date(Date.UTC(base.getUTCFullYear(),base.getUTCMonth()+1,0)).getUTCDate();base.setUTCDate(Math.min(d,last));return base.toISOString().slice(0,10)};
 function refresh(){const sum=[...rows.querySelectorAll('[name="parcel_amounts[]"]')].reduce((a,v)=>a+moneyIn(v.value),0);const expected=moneyIn(total.value);summary.textContent='Total das parcelas: R$ '+moneyOut(sum)+' | Total informado: R$ '+moneyOut(expected)+(sum===expected?' — valores conferidos':' — diferença: R$ '+moneyOut(expected-sum));summary.style.color=sum===expected?'':'#b42318'}
 function build(){const qty=Math.min(120,Math.max(1,parseInt(count.value,10)||1));const cents=moneyIn(total.value);rows.replaceChildren();wrap.style.display=qty>1?'':'none';if(qty<=1)return;
 const share=Math.floor(cents/qty),remainder=cents-share*qty;
 for(let i=0;i<qty;i++){const tr=document.createElement('tr');const number=document.createElement('td');number.textContent=String(i+1).padStart(2,'0')+'/'+String(qty).padStart(2,'0');const valueCell=document.createElement('td');const val=document.createElement('input');val.name='parcel_amounts[]';val.value=moneyOut(share+(i===qty-1?remainder:0));val.required=true;val.setAttribute('aria-label','Valor da parcela '+(i+1));valueCell.append(val);const dateCell=document.createElement('td');const date=document.createElement('input');date.type='date';date.name='parcel_dates[]';date.value=addMonths(firstDue.value,i);date.required=true;date.setAttribute('aria-label','Vencimento da parcela '+(i+1));dateCell.append(date);tr.append(number,valueCell,dateCell);rows.append(tr)}refresh()}
 count.addEventListener('change',build);total.addEventListener('change',build);firstDue.addEventListener('change',build);rows.addEventListener('input',refresh);
 form.addEventListener('submit',e=>{const qty=parseInt(count.value,10)||1;if(qty<=1)return;const inputs=[...rows.querySelectorAll('[name="parcel_amounts[]"]')];const dates=[...rows.querySelectorAll('[name="parcel_dates[]"]')];const sum=inputs.reduce((a,v)=>a+moneyIn(v.value),0);if(inputs.length!==qty||dates.some(d=>!d.value)||inputs.some(v=>moneyIn(v.value)<=0)||sum!==moneyIn(total.value)){e.preventDefault();refresh();alert('Confira quantidade, valores e vencimentos. A soma das parcelas deve corresponder ao valor total.')}});
 build();
})();
</script>
<?php endif; ?>

<?php finance_admin_end(); ?>
