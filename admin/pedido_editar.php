<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/product_options.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/brain_profitability.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/fiscal_order_composition.php';
require_once __DIR__ . '/../config/nfse_emitter.php';
require_once __DIR__ . '/../config/internal_delivery_status.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/contracts_module.php';
require_once __DIR__ . '/../config/product_half_pizza.php';
require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/voucher_module.php';
require_once __DIR__ . '/../config/card_control.php';
require_once __DIR__ . '/../config/fiscal_module.php';

company_sequence_ensure_schema($pdo, true);
brain_profitability_ensure_schema($pdo, true);
internal_delivery_status_ensure_schema($pdo);
$pedidoHalfPizzaReady = product_half_pizza_ensure_schema($pdo, true);

$showKitchenButton = false;
try {
  $showKitchenButton = function_exists('saas_company_has_module')
    && saas_company_has_module($pdo, (int)COMPANY_ID, 'comandas');
} catch (Throwable $e) {
  $showKitchenButton = false;
}

$showFiscalNfceButton = false;
$canEmitNfcePedido = false;
$canEmitNfePedido = false;
$canEmitNfsePedido = false;
$fiscalNfceDoc = null;
$fiscalNfeDoc = null;
$fiscalNfseDoc = null;
$nfseQuickCsrfEdit = nfse_emitter_csrf_token('nfse_emitter');
try {
  if (function_exists('saas_company_has_module') && saas_company_has_module($pdo, (int)COMPANY_ID, 'fiscal')) {
    if (function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo);
    $fiscalCfgPedido = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, (int)COMPANY_ID) : [];
    $canEmitNfcePedido = !empty($fiscalCfgPedido['nfce_enabled']);
    $canEmitNfePedido = true; // Exibe a opção NF-e; a emissão valida se a NF-e está habilitada/configurada.
    $fiscalEmissionEnabledPedido = !array_key_exists('fiscal_emission_enabled', $fiscalCfgPedido) || !empty($fiscalCfgPedido['fiscal_emission_enabled']);
    $canEmitNfsePedido = $fiscalEmissionEnabledPedido && !empty($fiscalCfgPedido['nfse_enabled']) && !empty($fiscalCfgPedido['nfse_national_standard']);
    $showFiscalNfceButton = $canEmitNfcePedido || $canEmitNfePedido || $canEmitNfsePedido;
  }
} catch (Throwable $e) {
  $showFiscalNfceButton = false;
}

function admin_edit_column_name(PDO $pdo, string $table, array $candidates, bool $createComplemento = false): ?string {
  static $cache = [];
  $key = $table . '.' . implode('|', $candidates) . '.' . ($createComplemento ? '1' : '0');
  if (array_key_exists($key, $cache)) return $cache[$key];

  try {
    $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
    $st->execute([$table]);
    $cols = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
    foreach ($cols as $col) {
      foreach ($candidates as $candidate) {
        if (mb_strtolower((string)$col) === mb_strtolower((string)$candidate)) {
          return $cache[$key] = (string)$col;
        }
      }
    }
  } catch (Throwable $e) {}

  try {
    $st = $pdo->query('SHOW COLUMNS FROM `' . str_replace('`', '``', $table) . '`');
    foreach (($st ? $st->fetchAll(PDO::FETCH_ASSOC) : []) as $row) {
      $col = (string)($row['Field'] ?? '');
      foreach ($candidates as $candidate) {
        if (mb_strtolower($col) === mb_strtolower((string)$candidate)) {
          return $cache[$key] = $col;
        }
      }
    }
  } catch (Throwable $e) {}

  if ($createComplemento && $table === 'pedido_itens') {
    try {
      $pdo->exec('ALTER TABLE `pedido_itens` ADD COLUMN `complemento` TEXT NULL AFTER `total`');
      return $cache[$key] = 'complemento';
    } catch (Throwable $e) {}
  }

  return $cache[$key] = null;
}

function admin_edit_has_column(PDO $pdo, string $table, string $column): bool {
  return admin_edit_column_name($pdo, $table, [$column]) !== null;
}

function admin_edit_quote_column(string $column): string { return '`' . str_replace('`', '``', $column) . '`'; }

function admin_edit_money(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function admin_edit_qty(float $v): string { return rtrim(rtrim(number_format($v, 3, ',', '.'), '0'), ','); }
function admin_edit_date_br($v): string {
  $s = trim((string)$v); if ($s === '') return '—';
  $ts = strtotime($s); return $ts ? date('d/m/Y', $ts) : $s;
}
function admin_edit_datetime_br($v): string {
  $s = trim((string)$v); if ($s === '') return '—';
  $ts = strtotime($s); return $ts ? date('d/m/Y H:i', $ts) : $s;
}
function admin_edit_id_list($value): array {
  if (is_array($value)) $parts = $value; else $parts = preg_split('/[^0-9]+/', (string)$value) ?: [];
  $ids = [];
  foreach ($parts as $part) { $n=(int)$part; if ($n>0) $ids[$n]=$n; }
  return array_values($ids);
}
function admin_edit_table_exists(PDO $pdo, string $table): bool {
  try { $st=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1'); $st->execute([$table]); return (bool)$st->fetchColumn(); } catch(Throwable $e){ return false; }
}
function admin_edit_payment_trace(PDO $pdo, int $companyId, int $orderId): array {
  $out = [];
  if (!admin_edit_table_exists($pdo, 'gestao_order_payments')) return $out;
  $cardSaleCol = admin_edit_has_column($pdo, 'gestao_order_payments', 'card_sale_id');
  $sql = 'SELECT op.*';
  if (admin_edit_table_exists($pdo,'finance_payment_methods')) $sql .= ', pm.type AS method_type, pm.code AS method_code, pm.name AS method_name';
  $sql .= ' FROM gestao_order_payments op';
  if (admin_edit_table_exists($pdo,'finance_payment_methods')) $sql .= ' LEFT JOIN finance_payment_methods pm ON pm.id=op.payment_method_id AND pm.company_id=op.company_id';
  $sql .= ' WHERE op.company_id=? AND op.order_id=? ORDER BY op.id';
  try { $st=$pdo->prepare($sql); $st->execute([$companyId,$orderId]); $payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[]; } catch(Throwable $e){ $payments=[]; }
  foreach ($payments as $pay) {
    $details=[]; $links=[];
    $label=trim((string)($pay['method_name']??$pay['payment_label']??'Pagamento')) ?: 'Pagamento';
    $type=strtoupper(trim((string)($pay['method_type']??$pay['method_code']??'')));
    $recIds = admin_edit_id_list($pay['receivable_ids'] ?? '');
    if ((int)($pay['receivable_id']??0)>0) $recIds[]=(int)$pay['receivable_id'];
    $recIds=array_values(array_unique(array_filter($recIds)));
    if ($recIds && admin_edit_table_exists($pdo,'finance_receivables')) {
      $ph=implode(',',array_fill(0,count($recIds),'?'));
      try {
        $st=$pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND id IN ('.$ph.') ORDER BY due_date,id');
        $st->execute(array_merge([$companyId],$recIds));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $r) {
          $sett=[];
          if (admin_edit_table_exists($pdo,'finance_settlements')) {
            $ss=$pdo->prepare("SELECT * FROM finance_settlements WHERE company_id=? AND origin_type='RECEIVABLE' AND origin_id=? ORDER BY settlement_date,id");
            $ss->execute([$companyId,(int)$r['id']]); $sett=$ss->fetchAll(PDO::FETCH_ASSOC)?:[];
          }
          $details[]=['kind'=>'receivable','title'=>'Contas a Receber #'.(int)$r['id'].' · '.((string)($r['installment']??'') ?: 'parcela'), 'status'=>(string)$r['status'], 'value'=>admin_edit_money((float)$r['amount']), 'date'=>'Venc. '.admin_edit_date_br($r['due_date']??''), 'extra'=>'Pago '.admin_edit_money((float)($r['paid_amount']??0)).' · Aberto '.admin_edit_money((float)($r['open_amount']??0)), 'settlements'=>array_map(static fn($x)=>['date'=>admin_edit_date_br($x['settlement_date']??''),'value'=>admin_edit_money((float)($x['amount']??0)),'method'=>(string)($x['payment_method']??'')],$sett)];
        }
        $links[]=['label'=>'Abrir Contas a Receber','href'=>'financeiro_contas_receber.php?edit='.(int)$recIds[0]];
      } catch(Throwable $e){}
    }
    if (admin_edit_table_exists($pdo,'finance_card_sales')) {
      try {
        $st=$pdo->prepare('SELECT * FROM finance_card_sales WHERE company_id=? AND order_id=? AND order_payment_id=? ORDER BY id');
        $st->execute([$companyId,$orderId,(int)$pay['id']]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $cs) {
          $inst=[];
          if(admin_edit_table_exists($pdo,'finance_card_installments')) { $si=$pdo->prepare('SELECT * FROM finance_card_installments WHERE company_id=? AND card_sale_id=? ORDER BY installment_no'); $si->execute([$companyId,(int)$cs['id']]); $inst=$si->fetchAll(PDO::FETCH_ASSOC)?:[]; }
          $details[]=['kind'=>'card','title'=>'Controle de Cartões · '.(int)($cs['installments']??1).'x','status'=>(string)($cs['status']??'ABERTO'),'value'=>admin_edit_money((float)($cs['gross_amount']??0)),'date'=>'Criado '.admin_edit_datetime_br($cs['created_at']??''),'extra'=>'Taxa '.number_format((float)($cs['fee_percent']??0),2,',','.').'% · Líquido '.admin_edit_money((float)($cs['net_amount']??0)).(trim((string)($cs['acquirer_name']??''))!==''?' · '.(string)$cs['acquirer_name']:''),'installments'=>array_map(static fn($x)=>['no'=>(int)($x['installment_no']??0),'due'=>admin_edit_date_br($x['due_date']??''),'status'=>(string)($x['status']??''),'gross'=>admin_edit_money((float)($x['gross_amount']??0)),'net'=>admin_edit_money((float)($x['net_amount']??0)),'paid'=>admin_edit_date_br($x['payment_date']??'')],$inst)];
          $links[]=['label'=>'Abrir Controle de Cartões','href'=>'financeiro_cartoes.php?f_order='.$orderId];
        }
      } catch(Throwable $e){}
    }
    if (admin_edit_table_exists($pdo,'finance_voucher_usages') && admin_edit_table_exists($pdo,'finance_vouchers')) {
      try { $st=$pdo->prepare('SELECT u.*,v.code,v.original_amount,v.used_amount,v.balance_amount,v.status AS voucher_status FROM finance_voucher_usages u JOIN finance_vouchers v ON v.company_id=u.company_id AND v.id=u.voucher_id WHERE u.company_id=? AND u.order_id=? AND (u.payment_method_id=? OR ?=0) ORDER BY u.id'); $pm=(int)($pay['payment_method_id']??0); $st->execute([$companyId,$orderId,$pm,$pm]); foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $v){ $details[]=['kind'=>'voucher','title'=>'Vale '.(string)$v['code'],'status'=>(string)$v['status'],'value'=>admin_edit_money((float)$v['amount']),'date'=>'Utilizado '.admin_edit_datetime_br($v['created_at']??''),'extra'=>'Saldo atual '.admin_edit_money((float)($v['balance_amount']??0))]; $links[]=['label'=>'Abrir Vales','href'=>'financeiro_vales.php?q='.rawurlencode((string)$v['code'])]; }} catch(Throwable $e){}
    }
    if (admin_edit_table_exists($pdo,'gestao_pix_charges')) {
      try { $st=$pdo->prepare('SELECT * FROM gestao_pix_charges WHERE company_id=? AND order_id=? AND (payment_method_id=? OR ?=0) ORDER BY id DESC'); $pm=(int)($pay['payment_method_id']??0); $st->execute([$companyId,$orderId,$pm,$pm]); foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $px){ $details[]=['kind'=>'pix','title'=>'PIX '.((string)($px['txid']??'') ?: '#'.(int)$px['id']),'status'=>(string)$px['status'],'value'=>admin_edit_money((float)$px['amount']),'date'=>!empty($px['paid_at'])?'Pago '.admin_edit_datetime_br($px['paid_at']):'Criado '.admin_edit_datetime_br($px['created_at']??''),'extra'=>(!empty($px['e2eid'])?'E2E '.(string)$px['e2eid']:'').(!empty($px['provider_status'])?' · Banco: '.(string)$px['provider_status']:'')]; }} catch(Throwable $e){}
    }
    if ((int)($pay['finance_movement_id']??0)>0 && admin_edit_table_exists($pdo,'finance_movements')) {
      try { $st=$pdo->prepare('SELECT m.*,b.name AS bank_name FROM finance_movements m LEFT JOIN finance_bank_accounts b ON b.company_id=m.company_id AND b.id=m.bank_account_id WHERE m.company_id=? AND m.id=? LIMIT 1'); $st->execute([$companyId,(int)$pay['finance_movement_id']]); if($m=$st->fetch(PDO::FETCH_ASSOC)) { $details[]=['kind'=>'movement','title'=>'Movimento financeiro #'.(int)$m['id'],'status'=>(string)$m['status'],'value'=>admin_edit_money((float)$m['amount']),'date'=>admin_edit_date_br($m['movement_date']??''),'extra'=>((string)($m['bank_name']??'')) . (((string)($m['description']??''))!==''?' · '.(string)$m['description']:'')]; $links[]=['label'=>'Abrir Movimento Financeiro','href'=>'financeiro_movimentos.php']; }} catch(Throwable $e){}
    }
    if (admin_edit_table_exists($pdo,'gestao_cash_operations')) {
      try { $st=$pdo->prepare('SELECT co.*,b.name AS bank_name FROM gestao_cash_operations co LEFT JOIN finance_bank_accounts b ON b.company_id=co.company_id AND b.id=co.bank_account_id WHERE co.company_id=? AND co.order_id=? AND (co.movement_id=? OR ?=0) ORDER BY co.id'); $mov=(int)($pay['finance_movement_id']??0); $st->execute([$companyId,$orderId,$mov,$mov]); foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $co){ $details[]=['kind'=>'cash','title'=>'Caixa/Banco · '.((string)($co['bank_name']??'') ?: '#'.(int)($co['bank_account_id']??0)),'status'=>'REGISTRADO','value'=>admin_edit_money((float)$co['amount']),'date'=>admin_edit_datetime_br($co['created_at']??$co['operation_date']??''),'extra'=>(string)($co['description']??'')]; }} catch(Throwable $e){}
    }
    $summary='';
    if ($recIds) $summary=count($recIds).' parcela(s) no Contas a Receber';
    elseif (stripos($type,'CARD')!==false || stripos($label,'cart')!==false) $summary='Cartão'.($details?' · rastreado':'');
    elseif (stripos($type,'VALE')!==false || stripos($label,'vale')!==false) $summary='Vale utilizado';
    elseif (stripos($type,'PIX')!==false || stripos($label,'pix')!==false) $summary='PIX'.($details?' · rastreado':'');
    else $summary=$details?'Movimento registrado':'Pagamento do pedido';
    $out[]=['id'=>(int)$pay['id'],'label'=>$label,'type'=>$type,'amount'=>(float)$pay['amount'],'received'=>(float)($pay['received_amount']??0),'change'=>(float)($pay['change_amount']??0),'created_at'=>(string)($pay['created_at']??''),'summary'=>$summary,'details'=>$details,'links'=>$links];
  }
  return $out;
}
// Consulta somente financeira: exibe parcelas do pedido mesmo sem gestao_order_payments.
function admin_edit_order_receivables(PDO $pdo, int $companyId, int $orderId): array {
  if (!admin_edit_table_exists($pdo, 'finance_receivables') || !admin_edit_has_column($pdo, 'finance_receivables', 'order_id')) return [];
  try {
    $st = $pdo->prepare('SELECT * FROM finance_receivables WHERE company_id=? AND order_id=? ORDER BY due_date,id');
    $st->execute([$companyId,$orderId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('Nexa: falha ao consultar parcelas do pedido #'.$orderId.': '.$e->getMessage());
    return [];
  }
}
function admin_edit_nfce_status_label(string $status): string {
  $status = strtoupper(trim($status));
  if ($status === '' || $status === 'PENDENTE') return 'Pendente';
  if ($status === 'PRE_NFCE' || $status === 'PRE_NFE') return 'Pré';
  if ($status === 'AUTORIZADA') return 'Autorizada';
  if ($status === 'REJEITADA') return 'Rejeitada';
  if ($status === 'ERRO_AUTORIZACAO') return 'Erro';
  if ($status === 'DUPLICIDADE') return 'Duplicidade';
  if ($status === 'CANCELADA') return 'Cancelada';
  return str_replace('_', ' ', $status);
}
function admin_edit_date_or_null($value): ?string {
  $s = trim((string)$value);
  return preg_match('/^\d{4}-\d{2}-\d{2}$/', $s) ? $s : null;
}
function admin_edit_time_or_null($value): ?string {
  $s = trim((string)$value);
  if ($s === '') return null;
  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $s)) return $s . ':00';
  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $s)) return $s;
  return null;
}

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) { header('Location: pedidos.php'); exit; }

$obsCol = admin_edit_column_name($pdo, 'pedidos', ['observacoes','observacao','obs']) ?: 'observacoes';
$itemObsCol = admin_edit_column_name($pdo, 'pedido_itens', ['complemento','observacao','obs','observacoes','adicionais','observacao_item'], true);
$hasBairro = admin_edit_has_column($pdo, 'pedidos', 'bairro');
try { $pdo->exec('ALTER TABLE `pedidos` ADD COLUMN `scheduled_delivery_date` DATE NULL'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE `pedidos` ADD COLUMN `scheduled_delivery_time` TIME NULL'); } catch (Throwable $e) {}
$hasScheduledDate = admin_edit_has_column($pdo, 'pedidos', 'scheduled_delivery_date');
$hasScheduledTime = admin_edit_has_column($pdo, 'pedidos', 'scheduled_delivery_time');
$biz = load_business_config((int)COMPANY_ID);
$advancedOrderEdit = !empty(($biz['order_edit'] ?? [])['advanced_enabled']);
// Vendedor do pedido: carregar somente na tela avançada, sem afetar a edição legada.
$editSellerAvailable = $advancedOrderEdit && admin_edit_has_column($pdo, 'pedidos', 'seller_id');
$editSellers = $editSellerAvailable ? finance_people_options($pdo, (int)COMPANY_ID, 'seller') : [];
$editSellerRules = $editSellerAvailable ? commercial_user_rules($pdo, (int)COMPANY_ID, (int)($_SESSION['user_id'] ?? 0)) : [];
$editSellerCanChange = !empty($editSellerRules['allow_seller_change']);
$deliveryScheduling = (array)($biz['delivery_scheduling'] ?? []);
$deliverySchedulingEnabled = !empty($deliveryScheduling['enabled']);
$deliverySchedulingLabel = trim((string)($deliveryScheduling['label'] ?? 'Data/Hora Prevista'));
if ($deliverySchedulingLabel === '') $deliverySchedulingLabel = 'Data/Hora Prevista';
$productOptionsCfg = (array)($biz['product_options'] ?? []);
$showAdditionalUnitTotal = !empty($productOptionsCfg['show_additional_unit_total']);
$showItemTotalWithAdditionals = !empty($productOptionsCfg['show_item_total_with_additionals']);
$itemNoteInstruction = trim((string)($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...'));
$orderTypesCfg = (array)($biz['order_types'] ?? []);
$editOrderTypes = [];
$editOrderTypeLabels = ['balcao' => 'Balcão', 'retirada' => 'Retirada', 'entrega' => 'Entrega'];
foreach ($editOrderTypeLabels as $typeKey => $typeLabel) {
  if (!array_key_exists($typeKey, $orderTypesCfg) || !empty($orderTypesCfg[$typeKey])) {
    $editOrderTypes[$typeKey] = $typeLabel;
  }
}
if (!$editOrderTypes) $editOrderTypes = ['retirada' => 'Retirada'];

$ok = trim((string)($_GET['ok'] ?? ''));
if ($ok === '' && (string)($_GET['edit_ok'] ?? '') === '1') $ok = 'Pedido salvo com sucesso.';
$erro = trim((string)($_GET['err'] ?? ''));
// Token dedicado à regularização financeira; nunca altera produtos ou o pedido finalizado.
if (empty($_SESSION['nexa_contract_repair_csrf'])) {
  $_SESSION['nexa_contract_repair_csrf'] = bin2hex(random_bytes(32));
}
$repairInfo = null;
try {
  if (admin_edit_table_exists($pdo, 'contract_generations')) {
    $q = $pdo->prepare('SELECT g.id AS generation_id, g.receivable_id, g.amount, g.due_date, g.contract_id, c.chart_account_id, c.contract_number, c.payment_method_id, c.bank_account_id, c.billing_person_id, c.contracting_person_id FROM contract_generations g JOIN contracts c ON c.id=g.contract_id AND c.company_id=g.company_id WHERE g.company_id=? AND g.order_id=? ORDER BY g.id DESC LIMIT 1');
    $q->execute([(int)COMPANY_ID,$id]);
    $repairInfo = $q->fetch(PDO::FETCH_ASSOC) ?: null;
  }
} catch (Throwable $e) { error_log('Nexa: falha ao consultar regularização financeira: '.$e->getMessage()); }



try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save_order');
    if ($action === 'regularize_contract_finance') {
      if (!hash_equals((string)($_SESSION['nexa_contract_repair_csrf'] ?? ''), (string)($_POST['repair_csrf'] ?? ''))) throw new RuntimeException('Sessão de segurança inválida. Reabra o pedido.');
      if (!$repairInfo) throw new RuntimeException('Este pedido não possui geração de contrato identificada.');
      if ((int)($repairInfo['chart_account_id'] ?? 0) <= 0) throw new RuntimeException('O contrato ainda não possui plano de contas.');
      $pdo->beginTransaction();
      // Impede regularização de pedido cancelado ou que não pertence à empresa.
      $check=$pdo->prepare('SELECT status FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');
      $check->execute([(int)COMPANY_ID,$id]);
      $statusOrder=strtolower(trim((string)$check->fetchColumn()));
      if (!in_array($statusOrder,['finalizado','concluido'],true)) throw new RuntimeException('Somente pedidos finalizados podem ter o financeiro regularizado.');
      $dup=$pdo->prepare('SELECT id FROM finance_receivables WHERE company_id=? AND order_id=? LIMIT 1 FOR UPDATE');
      $dup->execute([(int)COMPANY_ID,$id]);
      if ($dup->fetchColumn()) throw new RuntimeException('O pedido já possui Contas a Receber. Atualize a página antes de tentar novamente.');
      $generationId=(int)$repairInfo['generation_id'];
      // Cobrança bancária preexistente requer análise antes de qualquer alteração financeira.
      if (admin_edit_table_exists($pdo,'finance_boleto_charges')) {
        $bq=$pdo->prepare('SELECT id,receivable_id,status FROM finance_boleto_charges WHERE company_id=? AND generation_id=? FOR UPDATE');
        $bq->execute([(int)COMPANY_ID,$generationId]);
        foreach ($bq->fetchAll(PDO::FETCH_ASSOC) as $existingCharge) {
          if ((int)($existingCharge['receivable_id']??0)>0 || !in_array(strtoupper((string)$existingCharge['status']),['ERRO','CANCELADO','AGUARDANDO_API','AGUARDANDO_REMESSA'],true))
            throw new RuntimeException('Existe cobrança bancária para esta geração. Confira o boleto antes de regularizar para evitar duplicidade.');
        }
      }
      $receivableId=contracts_ensure_generation_receivable($pdo,(int)COMPANY_ID,$generationId,(int)($_SESSION['user_id']??0)?:null);
      $pdo->commit();
      $bankMessage='';
      try {
        $methodResolved=billing_resolve_contract_method($pdo,(int)COMPANY_ID,$repairInfo);
        $method=(array)($methodResolved['method']??[]);
        if ($method && billing_payment_method_auto_register($method)) {
          $prepared=billing_prepare_boleto_for_generation($pdo,(int)COMPANY_ID,$generationId,(int)($_SESSION['user_id']??0)?:null);
          $chargeId=(int)(($prepared['charge']??[])['id']??0);
          if ($chargeId>0) {
            $bankResult=billing_auto_register_charge($pdo,(int)COMPANY_ID,$chargeId,(int)($_SESSION['user_id']??0)?:null);
            if (empty($bankResult['ok'])) $bankMessage=' Recebível criado, porém o registro bancário precisa de conferência: '.(string)($bankResult['error']??$bankResult['message']??'retorno não confirmado');
          } else $bankMessage=' Recebível criado; nenhuma cobrança bancária foi preparada. Verifique a configuração do boleto.';
        }
      } catch (Throwable $bankError) {
        $bankMessage=' Recebível criado; registro bancário pendente: '.$bankError->getMessage();
      }
      header('Location: pedido_editar.php?id='.$id.'&ok='.rawurlencode('Contas a Receber #'.$receivableId.' regularizado.'.$bankMessage));
      exit;
    }
    if ($action === 'save_order') {
      // V11.51.7: pedido FINALIZADO é somente leitura. A trava é repetida no backend
      // para impedir alterações mesmo por POST manual ou JavaScript externo.
      $oldStatusSt = $pdo->prepare('SELECT status,finance_person_id FROM pedidos WHERE id=? AND company_id=? LIMIT 1');
      $oldStatusSt->execute([$id, COMPANY_ID]);
      $oldOrderState = $oldStatusSt->fetch(PDO::FETCH_ASSOC) ?: [];
      $oldStatus = strtolower(trim((string)($oldOrderState['status'] ?? '')));
      $oldFinancePersonId = (int)($oldOrderState['finance_person_id'] ?? 0);
      if ($oldStatus === 'finalizado') {
        throw new RuntimeException('Pedido finalizado: este pedido está disponível somente para consulta e não pode mais ser alterado.');
      }

      // Verificar vendedor no backend: nunca aceitar ID de outra empresa/inativo.
      // Perfis sem permissão não podem alterar o vínculo do vendedor por POST manual.
      $newSellerId = null;
      if ($editSellerAvailable) {
        $sSeller = $pdo->prepare('SELECT seller_id FROM pedidos WHERE id=? AND company_id=? LIMIT 1');
        $sSeller->execute([$id, COMPANY_ID]);
        $storedSellerId = (int)($sSeller->fetchColumn() ?: 0);
        $newSellerId = $editSellerCanChange ? (int)($_POST['seller_id'] ?? $storedSellerId) : $storedSellerId;
        if ($newSellerId > 0) {
          $sellerCheck = $pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_seller=1 LIMIT 1');
          $sellerCheck->execute([COMPANY_ID, $newSellerId]);
          if (!$sellerCheck->fetchColumn()) throw new RuntimeException('Vendedor inválido ou inativo para esta empresa.');
        }
      }
      $financePersonId = $advancedOrderEdit ? (int)($_POST['finance_person_id'] ?? $oldFinancePersonId) : $oldFinancePersonId;
      if ($advancedOrderEdit && $financePersonId > 0 && admin_edit_table_exists($pdo, 'finance_people')) {
        $pst = $pdo->prepare('SELECT id,name FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_customer=1 LIMIT 1');
        $pst->execute([COMPANY_ID,$financePersonId]);
        if (!$pst->fetch(PDO::FETCH_ASSOC)) throw new RuntimeException('O cliente selecionado não pertence a esta empresa ou está inativo.');
      }
      $nome = trim((string)($_POST['nome_cliente'] ?? ''));
      $whatsapp = preg_replace('/\D+/', '', (string)($_POST['whatsapp'] ?? ''));
      $endereco = trim((string)($_POST['endereco'] ?? ''));
      $bairro = trim((string)($_POST['bairro'] ?? ''));
      $tipoEntrega = trim((string)($_POST['tipo_entrega'] ?? ''));
      if (!array_key_exists($tipoEntrega, $editOrderTypes)) {
        $tipoEntrega = (string)array_key_first($editOrderTypes);
      }
      $formaPagamento = trim((string)($_POST['forma_pagamento'] ?? ''));
      $status = trim((string)($_POST['status'] ?? 'aberto'));
      $internalDeliveryStatusId = (int)($_POST['internal_delivery_status_id'] ?? 0);
      if ($internalDeliveryStatusId > 0 && !internal_delivery_status_find($pdo, (int)COMPANY_ID, $internalDeliveryStatusId)) {
        throw new RuntimeException('Status de Entrega Interno inválido.');
      }
      $observacoes = trim((string)($_POST['observacoes'] ?? ''));
      $scheduledDeliveryDate = admin_edit_date_or_null($_POST['scheduled_delivery_date'] ?? '') ?: null;
      $scheduledDeliveryTime = admin_edit_time_or_null($_POST['scheduled_delivery_time'] ?? '') ?: null;
      if ($scheduledDeliveryDate === null) $scheduledDeliveryTime = null;
      $taxaEntrega = (float)str_replace(',', '.', (string)($_POST['taxa_entrega'] ?? '0'));
      $taxaEntrega = max(0, $taxaEntrega);
      if ($nome === '') throw new RuntimeException('Informe o nome do cliente.');
      if (!in_array($status, ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'], true)) $status = 'aberto';

      $itemIds = $_POST['item_id'] ?? [];
      $produtoIds = $_POST['produto_id'] ?? [];
      $qtds = $_POST['qtd'] ?? [];
      $precos = $_POST['preco_unit'] ?? [];
      $itemObs = $_POST['item_obs'] ?? [];
      $remove = $_POST['remove_item'] ?? [];

      $updates = [];
      $totalProdutos = 0.0;
      for ($i = 0; $i < count($produtoIds); $i++) {
        $itemId = (int)($itemIds[$i] ?? 0);
        $produtoId = (int)($produtoIds[$i] ?? 0);
        $qtd = (float)str_replace(',', '.', (string)($qtds[$i] ?? '0'));
        $preco = (float)str_replace(',', '.', (string)($precos[$i] ?? '0'));
        $obsItem = trim((string)($itemObs[$i] ?? ''));
        $remover = !empty($remove[$itemId]) && $itemId > 0;

        if ($remover) {
          $updates[] = ['mode'=>'delete','item_id'=>$itemId];
          continue;
        }
        if ($produtoId <= 0 || $qtd <= 0 || $preco < 0) continue;
        $total = $qtd * $preco;
        $totalProdutos += $total;
        $updates[] = [
          'mode' => $itemId > 0 ? 'update' : 'insert',
          'item_id' => $itemId,
          'produto_id' => $produtoId,
          'qtd' => $qtd,
          'preco' => $preco,
          'total' => $total,
          'obs' => $obsItem,
        ];
      }
      if (!$updates) throw new RuntimeException('Informe ao menos um item no pedido.');
      $totalPedido = $totalProdutos + $taxaEntrega;

      $isNewCancellation = $status === 'cancelado' && $oldStatus !== 'cancelado';
      if ($isNewCancellation) {
        billing_assert_order_can_cancel_locally($pdo, (int)COMPANY_ID, $id);
      }

      $pdo->beginTransaction();
      $set = ['nome_cliente = ?', 'whatsapp = ?', 'endereco = ?', 'tipo_entrega = ?', 'taxa_entrega = ?', 'forma_pagamento = ?', $obsCol . ' = ?', 'total_produtos = ?', 'total = ?', 'status = ?'];
      $vals = [$nome, $whatsapp, $endereco, $tipoEntrega, $taxaEntrega, $formaPagamento, $observacoes, $totalProdutos, $totalPedido, $status];
      if ($hasBairro) { $set[] = 'bairro = ?'; $vals[] = $bairro; }
      if ($hasScheduledDate) { $set[] = 'scheduled_delivery_date = ?'; $vals[] = $scheduledDeliveryDate; }
      if ($hasScheduledTime) { $set[] = 'scheduled_delivery_time = ?'; $vals[] = $scheduledDeliveryTime; }
      $set[] = 'internal_delivery_status_id = ?';
      $vals[] = $internalDeliveryStatusId > 0 ? $internalDeliveryStatusId : null;
      if ($advancedOrderEdit && admin_edit_has_column($pdo,'pedidos','finance_person_id')) { $set[]='finance_person_id = ?'; $vals[]=$financePersonId>0?$financePersonId:null; }
      if ($editSellerAvailable) { $set[]='seller_id = ?'; $vals[]=$newSellerId>0?$newSellerId:null; }
      $vals[] = $id;
      $vals[] = COMPANY_ID;
      $pdo->prepare('UPDATE pedidos SET ' . implode(', ', $set) . ' WHERE id = ? AND company_id = ?')->execute($vals);

      foreach ($updates as $u) {
        if ($u['mode'] === 'delete') {
          $pdo->prepare('DELETE FROM pedido_itens WHERE id = ? AND pedido_id = ? AND company_id = ?')->execute([(int)$u['item_id'], $id, COMPANY_ID]);
        } elseif ($u['mode'] === 'update') {
          $setItem = ['produto_id = ?', 'qtd = ?', 'preco_unit = ?', 'total = ?'];
          $valsItem = [(int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total']];
          if ($itemObsCol) { $setItem[] = admin_edit_quote_column($itemObsCol) . ' = ?'; $valsItem[] = (string)$u['obs']; }
          $valsItem[] = (int)$u['item_id'];
          $valsItem[] = $id;
          $valsItem[] = COMPANY_ID;
          $pdo->prepare('UPDATE pedido_itens SET ' . implode(', ', $setItem) . ' WHERE id = ? AND pedido_id = ? AND company_id = ?')->execute($valsItem);
        } elseif ($u['mode'] === 'insert') {
          if ($itemObsCol) {
            $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, `' . str_replace('`', '``', $itemObsCol) . '`) VALUES (?, ?, ?, ?, ?, ?, ?)')
              ->execute([COMPANY_ID, $id, (int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total'], (string)$u['obs']]);
          } else {
            $pdo->prepare('INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?, ?, ?, ?, ?, ?)')
              ->execute([COMPANY_ID, $id, (int)$u['produto_id'], (float)$u['qtd'], (float)$u['preco'], (float)$u['total']]);
          }
        }
      }

      if ($isNewCancellation) {
        billing_cancel_order_unregistered_charges($pdo, (int)COMPANY_ID, $id, 'Pedido #' . $id . ' cancelado pela edição do pedido.', 'PEDIDO_EDITAR');
      }

      brain_profitability_recalculate_order($pdo, (int)COMPANY_ID, $id, false, $status === 'finalizado' ? 'order.finalized' : ($status === 'cancelado' ? 'order.cancelled' : 'order.updated'));

      try {
        $pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)')->execute([COMPANY_ID, $id]);
      } catch (Throwable $e) {}

      $pdo->commit();
      header('Location: pedido_editar.php?id=' . $id . '&edit_ok=1');
      exit;
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $erro = $e->getMessage();
}

$st = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND company_id = ?');
$st->execute([$id, COMPANY_ID]);
$pedido = $st->fetch(PDO::FETCH_ASSOC);
if (!$pedido) { http_response_code(404); echo 'Pedido não encontrado'; exit; }
$pedidoFinalizadoReadOnly = strtolower(trim((string)($pedido['status'] ?? ''))) === 'finalizado';

// V11.52.11: busca F8 de clientes reaproveitando a pessoa financeira da empresa.
if ($advancedOrderEdit && (string)($_GET['ajax'] ?? '') === 'people') {
  header('Content-Type: application/json; charset=utf-8');
  $q = trim((string)($_GET['q'] ?? ''));
  $rows = [];
  if (admin_edit_table_exists($pdo,'finance_people')) {
    $where = 'company_id=? AND active=1 AND is_customer=1';
    $params = [(int)COMPANY_ID];
    if ($q !== '') {
      $like = '%' . $q . '%';
      $digits = preg_replace('/\D+/','',$q) ?: '';
      $where .= ' AND (name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR email LIKE ? OR phone LIKE ? OR whatsapp LIKE ?)';
      array_push($params,$like,$like,$digits!==''?'%'.$digits.'%':$like,$like,$like,$like);
    }
    try {
      $stp=$pdo->prepare('SELECT id,name,fantasy_name,document,email,phone,whatsapp,zip_code,address,number,complement,district,city,state FROM finance_people WHERE '.$where.' ORDER BY name LIMIT 60');
      $stp->execute($params); $rows=$stp->fetchAll(PDO::FETCH_ASSOC)?:[];
    } catch(Throwable $e){}
  }
  echo json_encode(['ok'=>true,'rows'=>$rows], JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
$internalDeliveryStatuses = internal_delivery_status_options($pdo, (int)COMPANY_ID, true);
$pedidoNumero = company_sequence_display($pedido, 'pedidos');
try { if ($showFiscalNfceButton && $canEmitNfcePedido && function_exists('fiscal_nfce_order_document')) $fiscalNfceDoc = fiscal_nfce_order_document($pdo, (int)COMPANY_ID, (int)$id); } catch (Throwable $e) {}
try { if ($showFiscalNfceButton && $canEmitNfePedido && function_exists('fiscal_nfe_order_document')) $fiscalNfeDoc = fiscal_nfe_order_document($pdo, (int)COMPANY_ID, (int)$id); } catch (Throwable $e) {}
try { if ($canEmitNfsePedido && function_exists('nfse_emitter_existing_document')) $fiscalNfseDoc = nfse_emitter_existing_document($pdo, (int)COMPANY_ID, (int)$id); } catch (Throwable $e) {}
$w = preg_replace('/\D+/', '', (string)($pedido['whatsapp'] ?? ''));
$showDeliveryScheduling = $hasScheduledDate && ($deliverySchedulingEnabled || trim((string)($pedido['scheduled_delivery_date'] ?? '')) !== '');
$scheduledDateValue = $hasScheduledDate ? (string)($pedido['scheduled_delivery_date'] ?? '') : '';
$scheduledTimeValue = $hasScheduledTime ? substr((string)($pedido['scheduled_delivery_time'] ?? ''), 0, 5) : '';

$sti = $pdo->prepare('SELECT pi.*, p.nome FROM pedido_itens pi LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id WHERE pi.pedido_id = ? AND pi.company_id = ? ORDER BY pi.id ASC');
$sti->execute([$id, COMPANY_ID]);
$itens = $sti->fetchAll(PDO::FETCH_ASSOC);

$orderComposition = fiscal_order_composition($pdo, (int)COMPANY_ID, (int)$id);
$orderOnlyServices = !empty($orderComposition['only_services']);
$orderOnlyProducts = !empty($orderComposition['only_products']);
$orderMixed = !empty($orderComposition['mixed']);
$orderCanEmitNfce = $canEmitNfcePedido && ($orderOnlyProducts || $orderMixed);
$orderCanEmitNfe = $canEmitNfePedido && ($orderOnlyProducts || $orderMixed);
$orderCanEmitNfse = $canEmitNfsePedido && ($orderOnlyServices || $orderMixed);
$showOrderNfse = $orderCanEmitNfse || (bool)$fiscalNfseDoc;

$prodHasCategory = admin_edit_has_column($pdo, 'produtos', 'categoria');
$prodHasImage = admin_edit_has_column($pdo, 'produtos', 'imagem');
$prodHasActive = admin_edit_has_column($pdo, 'produtos', 'ativo');
$prodHasNumber = admin_edit_has_column($pdo, 'produtos', 'numero_cadastro');
$prodHasLegacyCode = admin_edit_has_column($pdo, 'produtos', 'codigo_firebird');
$prodHasReference = admin_edit_has_column($pdo, 'produtos', 'referencia');
$prodBarcodeCols = array_values(array_filter(['codigo_barras','ean','gtin','barcode'], static fn($c) => admin_edit_has_column($pdo, 'produtos', $c)));
try { $pdo->exec('ALTER TABLE produtos ADD COLUMN sort_order INT NOT NULL DEFAULT 0 AFTER ativo'); } catch (Throwable $e) {}
try { $pdo->exec('ALTER TABLE produtos ADD COLUMN category_sort_order INT NOT NULL DEFAULT 0 AFTER sort_order'); } catch (Throwable $e) {}
$prodHasSortOrder = admin_edit_has_column($pdo, 'produtos', 'sort_order');
$prodHasCategorySortOrder = admin_edit_has_column($pdo, 'produtos', 'category_sort_order');
$selectProductCols = ['id', 'nome', 'preco'];
$selectProductCols[] = $prodHasNumber ? 'numero_cadastro' : 'NULL AS numero_cadastro';
$selectProductCols[] = $prodHasLegacyCode ? 'codigo_firebird' : 'NULL AS codigo_firebird';
$selectProductCols[] = $prodHasReference ? 'referencia' : 'NULL AS referencia';
$selectProductCols[] = $prodHasCategory ? 'categoria' : "'' AS categoria";
$selectProductCols[] = $prodHasImage ? 'imagem' : "'' AS imagem";
foreach ($prodBarcodeCols as $bcCol) $selectProductCols[] = '`' . str_replace('`', '``', $bcCol) . '`';
if ($prodHasSortOrder) $selectProductCols[] = 'COALESCE(sort_order,0) AS sort_order';
if ($prodHasCategorySortOrder) $selectProductCols[] = 'COALESCE(category_sort_order,0) AS category_sort_order';
$whereProduct = 'company_id = ?';
if ($prodHasActive) $whereProduct .= ' AND COALESCE(ativo,1) = 1';
$orderCategoryProduct = $prodHasCategory ? 'categoria ASC, ' : '';
if ($prodHasCategorySortOrder) $orderCategoryProduct = 'CASE WHEN COALESCE(category_sort_order,0) <= 0 THEN 999999 ELSE category_sort_order END ASC, ' . $orderCategoryProduct;
$orderProduct = $orderCategoryProduct . ($prodHasSortOrder ? 'CASE WHEN COALESCE(sort_order,0) <= 0 THEN 999999 ELSE sort_order END ASC, ' : '') . 'nome ASC';
$produtosRows = [];
try {
  $produtos = $pdo->prepare('SELECT ' . implode(', ', $selectProductCols) . ' FROM produtos WHERE ' . $whereProduct . ' ORDER BY ' . $orderProduct . ' LIMIT 1500');
  $produtos->execute([COMPANY_ID]);
  $produtosRows = $produtos->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  $produtos = $pdo->prepare('SELECT id, nome, preco FROM produtos WHERE company_id = ? ORDER BY nome ASC LIMIT 1500');
  $produtos->execute([COMPANY_ID]);
  $produtosRows = array_map(static function(array $p): array { $p['categoria'] = ''; $p['imagem'] = ''; $p['codigo_barras'] = ''; return $p; }, $produtos->fetchAll(PDO::FETCH_ASSOC) ?: []);
}

$productOptionMap = [];
foreach ($produtosRows as $prodOpt) {
  $pidOpt = (int)($prodOpt['id'] ?? 0);
  if ($pidOpt <= 0) continue;
  try {
    $groupsOpt = product_options_load($pdo, COMPANY_ID, $pidOpt, true);
    if (!$groupsOpt) continue;
    $productOptionMap[$pidOpt] = array_map(static function(array $g): array {
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
    }, $groupsOpt);
  } catch (Throwable $e) {}
}

$halfPizzaCategories = $pedidoHalfPizzaReady ? product_half_pizza_enabled_categories($pdo, (int)COMPANY_ID) : [];
$produtosPayload = array_map(static function(array $p) use ($productOptionMap, $prodBarcodeCols, $halfPizzaCategories): array {
  $pid = (int)($p['id'] ?? 0);
  $name = (string)($p['nome'] ?? '');
  $categoryName = (string)($p['categoria'] ?? '');
  $isPizza = product_half_pizza_category_enabled($halfPizzaCategories, $categoryName);
  $img = trim((string)($p['imagem'] ?? ''));
  $barcode = '';
  foreach ($prodBarcodeCols as $bcCol) { $barcode = trim((string)($p[$bcCol] ?? '')); if ($barcode !== '') break; }
  return [
    'id' => $pid,
    'nome' => $name,
    'name' => $name,
    'categoria' => (string)($p['categoria'] ?? ''),
    'category' => (string)($p['categoria'] ?? ''),
    'preco' => (float)($p['preco'] ?? 0),
    'price' => (float)($p['preco'] ?? 0),
    'code' => (($n=trim((string)($p['numero_cadastro'] ?? ''))) !== '' && $n !== '0') ? $n : (trim((string)($p['codigo_firebird'] ?? ''))!=='' ? (string)$p['codigo_firebird'] : (string)$pid),
    'image' => $img !== '' ? '/uploads/products/' . rawurlencode($img) : '/assets/no-image.svg',
    'barcode' => $barcode,
    'reference' => trim((string)($p['referencia'] ?? '')),
    'hasOptions' => !empty($productOptionMap[$pid]),
    'isPizza' => $isPizza,
  ];
}, $produtosRows);
$productCodeById = [];
foreach ($produtosPayload as $pp) $productCodeById[(int)$pp['id']] = (string)($pp['code'] ?? $pp['id']);
$produtosJson = json_encode($produtosPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$productOptionsJson = json_encode($productOptionMap, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$pizzaJson = json_encode(array_values(array_filter(array_map(static function(array $p) use ($halfPizzaCategories): ?array {
  $name = (string)($p['nome'] ?? '');
  $categoryName = (string)($p['categoria'] ?? '');
  if (!product_half_pizza_category_enabled($halfPizzaCategories, $categoryName)) return null;
  return ['id'=>(int)$p['id'], 'name'=>$name, 'category'=>$categoryName, 'price'=>(float)($p['preco'] ?? 0)];
}, $produtosRows))), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$categoryNames = ['Todos'];
foreach ($produtosRows as $p) {
  $cat = trim((string)($p['categoria'] ?? ''));
  if ($cat !== '' && !in_array($cat, $categoryNames, true)) $categoryNames[] = $cat;
}
$categoriesJson = json_encode($categoryNames, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

// V11.52.11: dados complementares do modo avançado.
$returnedQtyByItem = [];
$itemFiscalDetails = [];
$paymentTrace = [];
$orderReceivables = [];
if ($advancedOrderEdit) {
  try {
    if (admin_edit_table_exists($pdo,'fiscal_document_items') && admin_edit_table_exists($pdo,'fiscal_documents')) {
      $stRet=$pdo->prepare("SELECT rdi.order_item_id,SUM(rdi.quantity) qty FROM fiscal_document_items rdi JOIN fiscal_documents rd ON rd.id=rdi.fiscal_document_id AND rd.company_id=rdi.company_id WHERE rdi.company_id=? AND rd.direction='ENTRADA' AND rd.model='00' AND UPPER(COALESCE(rd.source_type,''))='DEVOLUCAO_CLIENTE' AND rd.source_id=? AND UPPER(COALESCE(rd.status,''))<>'CANCELADA' AND rdi.order_item_id IS NOT NULL GROUP BY rdi.order_item_id");
      $stRet->execute([COMPANY_ID,$id]);
      foreach($stRet->fetchAll(PDO::FETCH_ASSOC)?:[] as $rr) $returnedQtyByItem[(int)$rr['order_item_id']]=(float)$rr['qty'];
    }
  } catch(Throwable $e){}
  foreach ($itens as $it) {
    $itemId=(int)($it['id']??0); $productId=(int)($it['produto_id']??0);
    $detail=['source'=>'Cadastro do produto','document'=>'','ncm'=>'','cest'=>'','cfop'=>'','origin'=>'','icms'=>'','pis'=>'','cofins'=>'','ipi'=>'','ibs_cbs'=>'','notes'=>'','taxes'=>[]];
    try {
      if (admin_edit_table_exists($pdo,'fiscal_document_items') && admin_edit_table_exists($pdo,'fiscal_documents')) {
        $sd=$pdo->prepare("SELECT fdi.*,fd.model,fd.series,fd.number,fd.status AS document_status,fd.access_key FROM fiscal_document_items fdi JOIN fiscal_documents fd ON fd.id=fdi.fiscal_document_id AND fd.company_id=fdi.company_id WHERE fdi.company_id=? AND fdi.order_item_id=? AND fd.direction='SAIDA' ORDER BY CASE WHEN UPPER(fd.status)='AUTORIZADA' THEN 0 ELSE 1 END,fd.id DESC LIMIT 1");
        $sd->execute([COMPANY_ID,$itemId]); $snap=$sd->fetch(PDO::FETCH_ASSOC);
        if ($snap) {
          $detail['source']='Documento fiscal'; $detail['document']='Modelo '.(string)$snap['model'].' · Série '.(string)($snap['series']??'').' · Nº '.(string)($snap['number']??'').' · '.(string)($snap['document_status']??'');
          $detail['ncm']=(string)($snap['ncm']??''); $detail['cest']=(string)($snap['cest']??''); $detail['cfop']=(string)($snap['cfop']??''); $detail['notes']=(string)($snap['notes']??'');
          if (admin_edit_table_exists($pdo,'fiscal_document_item_taxes')) {
            $stx=$pdo->prepare('SELECT tax_group,tax_tag,origin_code,cst,csosn,class_code,base_calc,base_calc_st,rate,reduction_rate,mva_rate,amount FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_item_id=? ORDER BY id');
            $stx->execute([COMPANY_ID,(int)$snap['id']]); $detail['taxes']=$stx->fetchAll(PDO::FETCH_ASSOC)?:[];
          }
        }
      }
      if ($detail['source'] !== 'Documento fiscal' && admin_edit_table_exists($pdo,'fiscal_product_tax')) {
        $stt=$pdo->prepare('SELECT * FROM fiscal_product_tax WHERE company_id=? AND product_id=? LIMIT 1'); $stt->execute([COMPANY_ID,$productId]); $tax=$stt->fetch(PDO::FETCH_ASSOC)?:[];
        $detail['ncm']=(string)($tax['ncm']??''); $detail['cest']=(string)($tax['cest']??''); $detail['cfop']=(string)($tax['cfop_sale_state']??''); $detail['origin']=(string)($tax['origem']??'');
        $detail['icms']='CST '.(string)($tax['icms_cst']??'').' / CSOSN '.(string)($tax['icms_csosn']??'').' · '.number_format((float)($tax['icms_rate']??0),2,',','.').'%';
        $detail['pis']='CST '.(string)($tax['pis_cst']??'').' · '.number_format((float)($tax['pis_rate']??0),2,',','.').'%';
        $detail['cofins']='CST '.(string)($tax['cofins_cst']??'').' · '.number_format((float)($tax['cofins_rate']??0),2,',','.').'%';
        $detail['ipi']='CST '.(string)($tax['ipi_cst']??'').' · '.number_format((float)($tax['ipi_rate']??0),2,',','.').'%';
        $detail['ibs_cbs']='CBS '.number_format((float)($tax['cbs_rate']??0),2,',','.').'% · IBS UF '.number_format((float)($tax['ibs_uf_rate']??0),2,',','.').'% · IBS Mun. '.number_format((float)($tax['ibs_mun_rate']??0),2,',','.').'%';
        $detail['notes']=(string)($tax['fiscal_notes']??'');
      }
    } catch(Throwable $e){}
    $itemFiscalDetails[$itemId]=$detail;
  }
  $paymentTrace = admin_edit_payment_trace($pdo,(int)COMPANY_ID,(int)$id);
  $orderReceivables = admin_edit_order_receivables($pdo,(int)COMPANY_ID,(int)$id);
}
$itemFiscalDetailsJson = json_encode($itemFiscalDetails,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
$paymentTraceJson = json_encode($paymentTrace,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);

admin_topbar('Editar pedido #' . $pedidoNumero, 'pedidos');
?>
<div class="edit-order-shell">
  <div class="actions right edit-order-actions">
    <a class="btn" href="pedidos.php">Lista de pedidos</a>
    <?php if ($pedidoFinalizadoReadOnly): ?><a class="btn" href="pedido_regularizacao.php?id=<?= (int)$id ?>">Verificar correção / situação fiscal</a><?php endif; ?>
    <a class="btn" href="pedido_print.php?id=<?= (int)$id ?>&amp;return_to=<?= rawurlencode('pedido_editar.php?id=' . (int)$id) ?>">🖨️ Imprimir</a>
    <a class="btn" target="_blank" rel="noopener" href="pedido_print_producao.php?id=<?= (int)$id ?>&auto=1">🧾 Produção</a>
    <?php if ($showKitchenButton): ?>
      <a class="btn" target="_blank" rel="noopener" href="pedido_print_cozinha.php?id=<?= (int)$id ?>&auto=1">🍳 Cozinha</a>
    <?php endif; ?>
    <?php if ($showFiscalNfceButton): ?>
      <?php if ($fiscalNfceDoc && strtoupper((string)($fiscalNfceDoc['status'] ?? '')) !== 'AUTORIZADA'): ?>
        <a class="btn" href="fiscal_nfce_editar.php?doc_id=<?= (int)$fiscalNfceDoc['id'] ?>">✏️ Editar dados fiscais</a>
      <?php endif; ?>
      <?php if ($fiscalNfeDoc && strtoupper((string)($fiscalNfeDoc['status'] ?? '')) !== 'AUTORIZADA'): ?>
        <a class="btn" href="fiscal_nfce_editar.php?doc_id=<?= (int)$fiscalNfeDoc['id'] ?>">✏️ Editar NF-e</a>
      <?php endif; ?>
      <?php
        $nfceAutorizadaEdit = $fiscalNfceDoc && strtoupper((string)($fiscalNfceDoc['status'] ?? '')) === 'AUTORIZADA';
        $nfeAutorizadaEdit = $fiscalNfeDoc && strtoupper((string)($fiscalNfeDoc['status'] ?? '')) === 'AUTORIZADA';
        $nfseFinalEdit = $fiscalNfseDoc && in_array(strtoupper((string)($fiscalNfseDoc['nfse_status'] ?? $fiscalNfseDoc['status'] ?? '')), ['AUTORIZADA','CANCELADA'], true);
      ?>
      <?php if ($orderOnlyServices && $showOrderNfse): ?>
        <?php if ($nfseFinalEdit): ?>
        <a class="btn btn-primary" href="fiscal_nfse_nacional.php?pedido_id=<?= (int)$id ?>">Abrir NFS-e</a>
        <?php else: ?>
        <form method="post" action="fiscal_nfse_acao.php" style="display:inline">
          <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($nfseQuickCsrfEdit, ENT_QUOTES, 'UTF-8') ?>">
          <input type="hidden" name="action" value="emit_quick"><input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
          <input type="hidden" name="return_to" value="fiscal_nfse_nacional.php?pedido_id=<?= (int)$id ?>">
          <button class="btn btn-primary" type="submit"><?= $fiscalNfseDoc ? 'Reemitir NFS-e' : 'Emitir NFS-e' ?></button>
        </form>
        <?php endif; ?>
      <?php elseif ($orderOnlyProducts && (($orderCanEmitNfce && !$nfceAutorizadaEdit && !$nfeAutorizadaEdit) || ($orderCanEmitNfe && !$nfeAutorizadaEdit))): ?>
        <div class="edit-fiscal-menu">
          <button class="btn btn-primary" type="button">⚡ Emitir Doc Fiscal ▾</button>
          <div class="edit-fiscal-menu-list">
            <?php if ($orderCanEmitNfce && !$nfceAutorizadaEdit && !$nfeAutorizadaEdit): ?>
              <form method="post" action="fiscal_nfce_acao.php">
                <input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="emitir_nfce_rapido">
                <input type="hidden" name="return_to" value="pedido_editar.php?id=<?= (int)$id ?>">
                <button class="btn" type="submit">Emitir NFC-e</button>
              </form>
            <?php endif; ?>
            <?php if ($orderCanEmitNfe && !$nfeAutorizadaEdit): ?>
              <form method="post" action="fiscal_nfce_acao.php">
                <input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="emitir_nfe_rapido">
                <input type="hidden" name="return_to" value="pedido_editar.php?id=<?= (int)$id ?>">
                <button class="btn" type="submit">Emitir NF-e</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      <?php elseif ($orderMixed): ?>
        <div class="edit-fiscal-menu">
          <button class="btn btn-primary" type="button">⚡ Emitir Doc Fiscal ▾</button>
          <div class="edit-fiscal-menu-list">
            <?php if ($orderCanEmitNfce && !$nfceAutorizadaEdit && !$nfeAutorizadaEdit): ?>
              <form method="post" action="fiscal_nfce_acao.php">
                <input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="emitir_nfce_rapido">
                <input type="hidden" name="return_to" value="pedido_editar.php?id=<?= (int)$id ?>">
                <button class="btn" type="submit">Emitir NFC-e</button>
              </form>
            <?php endif; ?>
            <?php if ($orderCanEmitNfe && !$nfeAutorizadaEdit && !$nfceAutorizadaEdit): ?>
              <form method="post" action="fiscal_nfce_acao.php">
                <input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
                <input type="hidden" name="action" value="emitir_nfe_rapido">
                <input type="hidden" name="return_to" value="pedido_editar.php?id=<?= (int)$id ?>">
                <button class="btn" type="submit">Emitir NF-e</button>
              </form>
            <?php endif; ?>
            <?php if ($showOrderNfse): ?>
              <?php if ($nfseFinalEdit): ?>
              <a class="btn" href="fiscal_nfse_nacional.php?pedido_id=<?= (int)$id ?>">Abrir NFS-e</a>
              <?php else: ?>
              <form method="post" action="fiscal_nfse_acao.php">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($nfseQuickCsrfEdit, ENT_QUOTES, 'UTF-8') ?>">
                <input type="hidden" name="action" value="emit_quick"><input type="hidden" name="pedido_id" value="<?= (int)$id ?>">
                <input type="hidden" name="return_to" value="fiscal_nfse_nacional.php?pedido_id=<?= (int)$id ?>">
                <button class="btn" type="submit"><?= $fiscalNfseDoc ? 'Reemitir NFS-e' : 'Emitir NFS-e' ?></button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
    <?php endif; ?>
    <?php if ($w): ?>
      <form method="post" action="pedido_whatsapp.php" class="edit-top-whatsapp-form">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="mode" value="api">
        <input type="hidden" name="return_to" value="pedido_editar.php?id=<?= (int)$id ?>">
        <button class="btn" type="submit">💬 WhatsApp</button>
      </form>
    <?php endif; ?>
    <a class="btn" href="email_documento.php?type=pedido&id=<?= (int)$id ?>&return_to=<?= rawurlencode('pedido_editar.php?id='.(int)$id) ?>">✉ E-mail</a>
  </div>
  <?php if ($showFiscalNfceButton): ?>
    <?php
      $fiscalStatusChips = [];
      foreach ([['NFC-e', $fiscalNfceDoc], ['NF-e', $fiscalNfeDoc]] as $pair) {
        [$labelDoc, $docFiscalPedido] = $pair;
        if (!$docFiscalPedido) continue;
        $stDoc = strtoupper((string)($docFiscalPedido['status'] ?? ($labelDoc === 'NF-e' ? 'PRE_NFE' : 'PRE_NFCE')));
        $stLabel = admin_edit_nfce_status_label($stDoc);
        $retDoc = trim((string)($docFiscalPedido['sefaz_status_code'] ?? '') . ' - ' . (string)($docFiscalPedido['sefaz_status_message'] ?? ''));
        $bgDoc = $stDoc === 'AUTORIZADA' ? '#ecfdf5' : '#fff7ed';
        $fgDoc = $stDoc === 'AUTORIZADA' ? '#047857' : '#9a3412';
        $bdDoc = $stDoc === 'AUTORIZADA' ? '#a7f3d0' : '#fed7aa';
        $fiscalStatusChips[] = '<b>' . h($labelDoc) . ':</b> <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:' . $bgDoc . ';color:' . $fgDoc . ';border:1px solid ' . $bdDoc . ';font-size:10px;font-weight:950">' . h($stLabel) . '</span>' . (($retDoc !== ' - ' && $retDoc !== '') ? '<span style="color:#64748b;margin-left:6px">' . h($retDoc) . '</span>' : '');
      }
      if ($fiscalNfseDoc) {
        $nfseStatus = strtoupper((string)($fiscalNfseDoc['nfse_status'] ?? $fiscalNfseDoc['status'] ?? 'PREPARADA'));
        $nfseLabels = ['PREPARADA'=>'Preparada','ASSINADA'=>'Assinada','TRANSMITINDO'=>'Transmitindo','AUTORIZADA'=>'Autorizada','REJEITADA'=>'Rejeitada','ERRO_COMUNICACAO'=>'Comunicação incerta','CANCELADA'=>'Cancelada'];
        $nfseLabel = $nfseLabels[$nfseStatus] ?? str_replace('_',' ',$nfseStatus);
        $nfseBg = $nfseStatus === 'AUTORIZADA' ? '#ecfdf5' : '#fff7ed';
        $nfseFg = $nfseStatus === 'AUTORIZADA' ? '#047857' : '#9a3412';
        $nfseBd = $nfseStatus === 'AUTORIZADA' ? '#a7f3d0' : '#fed7aa';
        $fiscalStatusChips[] = '<b>NFS-e:</b> <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:' . $nfseBg . ';color:' . $nfseFg . ';border:1px solid ' . $nfseBd . ';font-size:10px;font-weight:950">' . h($nfseLabel) . '</span>';
      }
      if (!$fiscalStatusChips) $fiscalStatusChips[] = '<b>Doc fiscal:</b> <span style="display:inline-flex;padding:4px 9px;border-radius:999px;background:#f8fafc;color:#64748b;border:1px solid #e2e8f0;font-size:10px;font-weight:950">Pendente</span>';
    ?>
    <div class="card" style="border-radius:16px;padding:11px 14px;margin:8px 0 14px;background:#f8fbff;border:1px solid #dbe4f0;color:#0f172a;font-weight:850;display:flex;gap:12px;flex-wrap:wrap">
      <?= implode('', array_map(static fn($x) => '<span>' . $x . '</span>', $fiscalStatusChips)) ?>
    </div>
  <?php endif; ?>
  <?php if ($ok): ?><div class="card edit-alert-success"><?= h($ok) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="card edit-alert-error"><?= h($erro) ?></div><?php endif; ?>
  <?php if ($pedidoFinalizadoReadOnly): ?>
    <div class="card edit-readonly-alert"><strong>🔒 Pedido finalizado · somente leitura</strong><span>Você pode consultar os dados e os itens, porém nenhuma alteração é permitida neste pedido.</span></div>
  <?php endif; ?>

  <form method="post" class="card edit-order-card" id="orderEditForm">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="action" value="save_order">
    <fieldset class="edit-order-fieldset<?= $pedidoFinalizadoReadOnly ? ' is-readonly' : '' ?>" <?= $pedidoFinalizadoReadOnly ? 'disabled' : '' ?>>

    <div class="edit-section-head">
      <div>
        <h3>Dados do cliente</h3>
        <p>Revise os dados principais do pedido antes de salvar.</p>
      </div>
    </div>
    <div class="edit-customer-grid<?= $advancedOrderEdit ? ' is-advanced' : '' ?>">
      <?php if ($advancedOrderEdit): ?>
        <input type="hidden" name="finance_person_id" id="financePersonId" value="<?= (int)($pedido['finance_person_id'] ?? 0) ?>">
        <label class="edit-customer-main">Cliente
          <div class="edit-inline-action"><input name="nome_cliente" id="customerNameInput" required value="<?= h((string)($pedido['nome_cliente'] ?? '')) ?>"><button type="button" class="btn" id="customerSearchBtn">Buscar F8</button></div>
        </label>
      <?php else: ?>
        <label>Cliente<input name="nome_cliente" required value="<?= h((string)($pedido['nome_cliente'] ?? '')) ?>"></label>
      <?php endif; ?>
      <?php if ($editSellerAvailable): ?>
        <label>Vendedor
          <select name="seller_id" id="orderSellerSelect" <?= $editSellerCanChange ? '' : 'disabled' ?>>
            <option value="0">Sem vendedor</option>
            <?php foreach ($editSellers as $seller): ?>
              <option value="<?= (int)$seller['id'] ?>" <?= (int)($pedido['seller_id'] ?? 0)===(int)$seller['id'] ? 'selected' : '' ?>><?= h((string)$seller['name']) ?></option>
            <?php endforeach; ?>
          </select>
          <?php if (!$editSellerCanChange): ?><small class="edit-muted-mini">A troca de vendedor está bloqueada para seu usuário.</small><?php endif; ?>
        </label>
      <?php endif; ?>
      <label>WhatsApp<input name="whatsapp" id="customerWhatsappInput" value="<?= h((string)($pedido['whatsapp'] ?? '')) ?>"></label>
      <?php if ($hasBairro): ?><label>Bairro<input name="bairro" id="customerDistrictInput" value="<?= h((string)($pedido['bairro'] ?? '')) ?>"></label><?php endif; ?>
      <label>Tipo entrega
        <select name="tipo_entrega">
          <?php $currentTipoEntrega = (string)($pedido['tipo_entrega'] ?? ''); ?>
          <?php if ($currentTipoEntrega !== '' && !array_key_exists($currentTipoEntrega, $editOrderTypes)): ?>
            <option value="<?= h($currentTipoEntrega) ?>" selected><?= h($currentTipoEntrega) ?></option>
          <?php endif; ?>
          <?php foreach ($editOrderTypes as $typeKey => $typeLabel): ?>
            <option value="<?= h($typeKey) ?>" <?= ($currentTipoEntrega === $typeKey) ? 'selected' : '' ?>><?= h($typeLabel) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <?php if ($showDeliveryScheduling): ?>
        <label><?= h($deliverySchedulingLabel) ?> - Data<input type="date" name="scheduled_delivery_date" value="<?= h($scheduledDateValue) ?>"></label>
        <?php if ($hasScheduledTime): ?><label><?= h($deliverySchedulingLabel) ?> - Hora<input type="time" name="scheduled_delivery_time" value="<?= h($scheduledTimeValue) ?>"></label><?php endif; ?>
      <?php endif; ?>
      <label>Forma pagamento<input name="forma_pagamento" value="<?= h((string)($pedido['forma_pagamento'] ?? '')) ?>"></label>
      <label>Status
        <select name="status">
          <?php foreach (['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'] as $s): ?>
            <option value="<?= h($s) ?>" <?= ((string)($pedido['status'] ?? '') === $s) ? 'selected' : '' ?>><?= h(fmt_status($s)) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Entrega Interno
        <select name="internal_delivery_status_id">
          <option value="">Não informado</option>
          <?php foreach ($internalDeliveryStatuses as $internalStatus): ?>
            <option value="<?= (int)$internalStatus['id'] ?>" <?= (int)($pedido['internal_delivery_status_id'] ?? 0) === (int)$internalStatus['id'] ? 'selected' : '' ?>><?= h((string)$internalStatus['name']) ?><?= empty($internalStatus['active']) ? ' (inativo)' : '' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
    </div>
    <label>Endereço<textarea name="endereco" id="customerAddressInput" rows="2"><?= h((string)($pedido['endereco'] ?? '')) ?></textarea></label>
    <label>Observações<textarea name="observacoes" rows="3"><?= h((string)($pedido[$obsCol] ?? '')) ?></textarea></label>

    <div class="edit-section-head edit-items-head">
      <div>
        <h3>Itens do pedido</h3>
        <p>Use <b>Adicionar item</b> para escolher produtos com meia pizza e adicionais configurados no cadastro do produto.</p>
      </div>
      <button type="button" class="btn btn-primary" id="addItemBtn">+ Adicionar item</button>
    </div>

    <div class="table-wrap edit-table-wrap"><table id="itemsTable" class="edit-items-table">
      <thead><tr><?php if ($advancedOrderEdit): ?><th style="width:120px;">Código</th><?php endif; ?><th>Produto</th><th style="width:90px;">Qtd</th><th style="width:130px;">Unitário</th><th style="width:130px;">Total</th><?php if ($advancedOrderEdit): ?><th style="width:120px;">Devolução</th><?php endif; ?><th>Obs/adicionais</th><th style="width:<?= $advancedOrderEdit ? '210' : '116' ?>px;">Ações</th></tr></thead>
      <tbody>
      <?php foreach ($itens as $idx => $it): ?>
        <?php
          $obsItem = $itemObsCol ? (string)($it[$itemObsCol] ?? '') : '';
          $pedidoProdutoIdPre = (int)($it['produto_id'] ?? 0);
          $pedidoProdutoCodigoPre = (string)($productCodeById[$pedidoProdutoIdPre] ?? $pedidoProdutoIdPre);
          $returnedQty = (float)($returnedQtyByItem[(int)($it['id'] ?? 0)] ?? 0);
        ?>
        <tr data-order-item-id="<?= (int)$it['id'] ?>">
          <?php if ($advancedOrderEdit): ?>
            <td><div class="edit-code-search"><input class="product-code" readonly value="<?= h($pedidoProdutoCodigoPre) ?>"><button type="button" class="btn btn-sm product-search-btn" title="Buscar produto (F8)">F8</button></div></td>
          <?php endif; ?>
          <td>
            <input type="hidden" name="item_id[]" value="<?= (int)$it['id'] ?>">
            <?php
              // O item gravado no pedido precisa sempre existir no <select>, mesmo quando
              // o produto foi inativado ou ficou fora do LIMIT da lista de produtos ativos.
              // Sem isso, o navegador seleciona silenciosamente o primeiro produto da lista
              // e uma simples abertura/salvamento da edição pode trocar o item do pedido.
              $pedidoProdutoId = (int)($it['produto_id'] ?? 0);
              $pedidoProdutoNome = trim((string)($it['produto_nome_snapshot'] ?? ''));
              if ($pedidoProdutoNome === '') $pedidoProdutoNome = trim((string)($it['nome'] ?? ''));
              if ($pedidoProdutoNome === '') $pedidoProdutoNome = 'Produto #' . $pedidoProdutoId;
            ?>
            <?php if ($advancedOrderEdit): ?>
              <?php $pedidoProdutoCodigo = (string)($productCodeById[$pedidoProdutoId] ?? $pedidoProdutoId); ?>
            <?php endif; ?>
            <select name="produto_id[]" class="produto-select" required>
              <option value="<?= $pedidoProdutoId ?>" data-preco="<?= h((string)($it['preco_unit'] ?? 0)) ?>" selected><?= h($pedidoProdutoNome) ?></option>
              <?php foreach ($produtosRows as $p): ?>
                <?php if ((int)$p['id'] === $pedidoProdutoId) continue; ?>
                <option value="<?= (int)$p['id'] ?>" data-preco="<?= h((string)$p['preco']) ?>"><?= h((string)$p['nome']) ?></option>
              <?php endforeach; ?>
            </select>
          </td>
          <td><input class="qtd" name="qtd[]" value="<?= h((string)$it['qtd']) ?>"></td>
          <td><input class="preco" name="preco_unit[]" value="<?= number_format((float)$it['preco_unit'], 2, '.', '') ?>"></td>
          <td class="line-total"><?= admin_edit_money((float)$it['total']) ?></td>
          <?php if ($advancedOrderEdit): ?>
            <td><?php if ($returnedQty > 0): ?><span class="edit-return-badge">↩ Devolvido <?= h(admin_edit_qty($returnedQty)) ?></span><?php else: ?><span class="edit-muted-mini">—</span><?php endif; ?></td>
          <?php endif; ?>
          <td><textarea class="item-obs" name="item_obs[]" rows="2" placeholder="<?= h($itemNoteInstruction) ?>"><?= h($obsItem) ?></textarea></td>
          <td>
            <div class="edit-row-actions">
              <input type="hidden" class="remove-flag" name="remove_item[<?= (int)$it['id'] ?>]" value="0">
              <?php if ($advancedOrderEdit): ?><a href="#" class="btn btn-sm item-details-btn readonly-allowed" data-item-id="<?= (int)$it['id'] ?>" title="Detalhes fiscais e tributários">Detalhes</a><?php endif; ?>
              <button type="button" class="btn btn-sm configure-item-btn" title="Configurar adicionais/meia pizza">⚙️</button>
              <button type="button" class="btn btn-sm btn-danger remove-item-btn" title="Remover item">🗑️</button>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>

    <div class="edit-totals-grid">
      <label>Taxa entrega<input class="money-field" name="taxa_entrega" id="taxaEntrega" value="<?= number_format((float)($pedido['taxa_entrega'] ?? 0), 2, '.', '') ?>"></label>
      <div class="edit-total-box"><span>Subtotal</span><strong id="subtotalView"><?= admin_edit_money((float)($pedido['total_produtos'] ?? 0)) ?></strong></div>
      <div class="edit-total-box final"><span>Total</span><strong id="totalView"><?= admin_edit_money((float)($pedido['total'] ?? 0)) ?></strong></div>
    </div>
    <div class="actions right">
      <?php if (!$pedidoFinalizadoReadOnly): ?>
        <button class="btn btn-primary" type="submit">Salvar alterações</button>
      <?php else: ?>
        <span class="edit-readonly-badge">🔒 Finalizado · alterações bloqueadas</span>
      <?php endif; ?>
    </div>
    </fieldset>
  </form>

  <?php if ($advancedOrderEdit): ?>
<style>
.edit-payment-summary{display:flex;gap:12px;flex-wrap:wrap}.edit-payment-summary>div{flex:1 1 190px;padding:12px;border:1px solid #dbe4f0;background:#f8fbff;border-radius:12px}.edit-payment-summary small,.edit-payment-summary strong{display:block}.edit-payment-summary small{font-size:11px;color:#64748b;margin-bottom:4px}.edit-payment-summary strong{color:#0b1f5b}.edit-payment-installments-title{display:flex;justify-content:space-between;gap:10px;align-items:center}.edit-payment-installments-title small{color:#64748b}.edit-payment-installments-scroll{overflow-x:auto}.edit-payment-installments{border-collapse:collapse;width:100%;min-width:700px}.edit-payment-installments th,.edit-payment-installments td{padding:9px;border-bottom:1px solid #dbe4f0;text-align:left;font-size:12px}.edit-payment-installments th{color:#475569}.edit-payment-installments tfoot{background:#eff6ff}.edit-payment-status{background:#eff6ff;color:#1d4ed8;border-radius:8px;padding:4px 7px;font-weight:700}
</style>
    <div class="card edit-payment-card">
      <div class="edit-section-head">
        <div><h3>Pagamento e rastreamento</h3><p>Veja como cada pagamento do pedido foi integrado ao financeiro, cartões, vales, PIX ou caixa/banco.</p></div>
      </div>
      <?php if ($paymentTrace): ?>
        <div class="edit-payment-list">
          <?php foreach ($paymentTrace as $pt): ?>
            <div class="edit-payment-row">
              <div><strong><?= h((string)$pt['label']) ?></strong><small><?= h((string)$pt['summary']) ?> · <?= h(admin_edit_datetime_br($pt['created_at'] ?? '')) ?></small></div>
              <div class="edit-payment-value"><?= admin_edit_money((float)$pt['amount']) ?></div>
              <button type="button" class="btn payment-integration-btn" data-payment-id="<?= (int)$pt['id'] ?>">Integração</button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
      <div class="edit-payment-summary">
        <div><small>Forma de pagamento do pedido</small><strong><?= h((string)($pedido['forma_pagamento'] ?: 'Não informado')) ?></strong></div>
        <div><small>Total do pedido</small><strong><?= admin_edit_money((float)($pedido['total'] ?? 0)) ?></strong></div>
      </div>
      <?php if ($orderReceivables): ?>
        <div class="edit-payment-installments-title"><strong>Parcelas do Contas a Receber</strong><small>Vinculadas a este pedido</small></div>
        <div class="edit-payment-installments-scroll"><table class="edit-payment-installments">
          <thead><tr><th>Parcela</th><th>Vencimento</th><th>Forma prevista</th><th>Valor</th><th>Em aberto</th><th>Situação</th><th>Financeiro</th></tr></thead>
          <tbody><?php foreach ($orderReceivables as $rec): ?>
          <tr>
            <td><?= h((string)($rec['installment'] ?: '1/1')) ?></td>
            <td><?= h(admin_edit_date_br($rec['due_date'] ?? '')) ?></td>
            <td><?= h((string)($rec['expected_payment_method'] ?: ($pedido['forma_pagamento'] ?? 'Não informado'))) ?></td>
            <td><?= admin_edit_money((float)($rec['amount'] ?? 0)) ?></td>
            <td><?= admin_edit_money((float)($rec['open_amount'] ?? 0)) ?></td>
            <td><span class="edit-payment-status"><?= h((string)($rec['status'] ?? '')) ?></span></td>
            <td><a class="btn btn-sm" href="financeiro_contas_receber.php?edit=<?= (int)$rec['id'] ?>">Abrir</a></td>
          </tr>
          <?php endforeach; ?></tbody>
          <tfoot><tr><th colspan="3">Total das parcelas</th><th><?= admin_edit_money(array_sum(array_map(static fn($r)=>(float)($r['amount']??0),$orderReceivables))) ?></th><th><?= admin_edit_money(array_sum(array_map(static fn($r)=>(float)($r['open_amount']??0),$orderReceivables))) ?></th><th colspan="2"></th></tr></tfoot>
        </table></div>
      <?php elseif (!$paymentTrace): ?>
        <div class="edit-empty-state">Nenhuma parcela de Contas a Receber vinculada a este pedido foi encontrada. Confira a geração financeira antes de realizar a cobrança.</div>
      <?php endif; ?>
      <?php if (!$orderReceivables && $repairInfo && $pedidoFinalizadoReadOnly): ?>
        <?php if ((int)($repairInfo['chart_account_id']??0)>0): ?>
          <form method="post" action="pedido_editar.php?id=<?= (int)$id ?>" style="margin-top:12px" onsubmit="return confirm('Confirmar regularização financeira deste pedido? O sistema verificará duplicidade antes de gerar a parcela e tentará registrar o boleto quando configurado.');">
            <input type="hidden" name="id" value="<?= (int)$id ?>">
            <input type="hidden" name="action" value="regularize_contract_finance">
            <input type="hidden" name="repair_csrf" value="<?= h((string)$_SESSION['nexa_contract_repair_csrf']) ?>">
            <button class="btn btn-primary" type="submit">Regularizar financeiro</button>
            <small style="display:block;margin-top:7px;color:#64748b">Somente para pedido originado de contrato. Confirme o vencimento e o status do boleto após a operação.</small>
          </form>
        <?php else: ?><div class="edit-empty-state">Regularização indisponível: informe o plano de contas no contrato.</div><?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
</div>

<?php if ($advancedOrderEdit): ?>
<div class="edit-modal" id="customerPickerModal" hidden>
  <div class="edit-modal-dialog">
    <div class="edit-modal-head"><div><h2>Buscar cliente</h2><p>Digite e pressione Enter. Use ↑/↓ para navegar e Enter para selecionar.</p></div><button type="button" class="edit-modal-close" data-close-edit-modal="customerPickerModal">×</button></div>
    <div class="edit-picker-toolbar edit-customer-picker-tools"><input id="customerPickerSearch" placeholder="Nome, CNPJ/CPF, telefone ou e-mail"><div class="actions"><button type="button" class="btn btn-primary" id="customerPickerDoSearch">Pesquisar</button><a class="btn" href="financeiro_pessoas.php?new=1&role=customer">Cadastrar</a></div></div>
    <div class="edit-customer-results" id="customerPickerResults"></div>
  </div>
</div>

<div class="edit-modal" id="itemDetailsModal" hidden>
  <div class="edit-modal-dialog edit-modal-wide">
    <div class="edit-modal-head"><div><h2>Detalhes do item</h2><p id="itemDetailsSource">Tributação e dados fiscais do produto.</p></div><button type="button" class="edit-modal-close" data-close-edit-modal="itemDetailsModal">×</button></div>
    <div id="itemDetailsBody"></div>
  </div>
</div>

<div class="edit-modal" id="paymentIntegrationModal" hidden>
  <div class="edit-modal-dialog edit-modal-wide">
    <div class="edit-modal-head"><div><h2>Rastreamento da integração</h2><p id="paymentIntegrationSubtitle">Origem, destino, datas e situação financeira.</p></div><button type="button" class="edit-modal-close" data-close-edit-modal="paymentIntegrationModal">×</button></div>
    <div id="paymentIntegrationBody"></div>
  </div>
</div>
<?php endif; ?>

<div class="edit-modal" id="productPickerModal" hidden>
  <div class="edit-modal-dialog edit-modal-wide">
    <div class="edit-modal-head">
      <div>
        <h2>Adicionar item</h2>
        <p>Escolha o produto. Se ele tiver adicionais ou for pizza, a configuração será aberta automaticamente.</p>
      </div>
      <button type="button" class="edit-modal-close" data-close-edit-modal="productPickerModal">×</button>
    </div>
    <div class="edit-picker-toolbar">
      <input id="productPickerSearch" placeholder="Pesquisar produto...">
      <select id="productPickerCategory"></select>
      <?php if ($advancedOrderEdit): ?><div class="actions edit-product-picker-actions"><button type="button" class="btn btn-primary" id="productPickerDoSearch">Pesquisar</button><a class="btn" href="produto_form.php">Cadastrar</a></div><?php endif; ?>
    </div>
    <div class="edit-product-grid" id="productPickerGrid"></div>
  </div>
</div>

<div class="edit-modal" id="itemConfigModal" hidden>
  <div class="edit-modal-dialog">
    <div class="edit-modal-head">
      <div>
        <h2 id="optProductTitle">Configurar item</h2>
        <p>Escolha meia pizza, adicionais e observações do item.</p>
      </div>
      <button type="button" class="edit-modal-close" data-close-edit-modal="itemConfigModal">×</button>
    </div>
    <div class="edit-config-grid">
      <label>Quantidade<input id="optQty" inputmode="decimal" value="1"></label>
      <label>Adicionais selecionados<input id="optTotal" readonly value="R$ 0,00"></label>
    </div>
    <div id="optHalfBox" class="edit-half-box" style="display:none;"></div>
    <div id="optGroups" class="edit-options-body"></div>
    <label>Observação do item<textarea id="optNote" rows="2" placeholder="<?= h($itemNoteInstruction) ?>"></textarea></label>
    <div class="actions right edit-modal-actions">
      <button type="button" class="btn" id="optCancel">Cancelar</button>
      <button type="button" class="btn btn-primary" id="optConfirm">Adicionar/Aplicar</button>
    </div>
  </div>
</div>

<style>
  .edit-order-shell{margin-top:14px;max-width:1440px;display:grid;gap:14px}.edit-order-card{display:grid;gap:16px}.edit-order-actions{margin-top:4px}.edit-fiscal-menu{position:relative;display:inline-flex}.edit-fiscal-menu-list{display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:40;min-width:170px;background:#fff;border:1px solid #dbe4f0;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.16);padding:7px;gap:6px}.edit-fiscal-menu:hover .edit-fiscal-menu-list,.edit-fiscal-menu:focus-within .edit-fiscal-menu-list{display:grid}.edit-fiscal-menu-list form{margin:0}.edit-fiscal-menu-list .btn{width:100%;justify-content:flex-start}.edit-alert-error{border-color:#fecaca!important;background:#fef2f2!important;color:#991b1b!important}.edit-alert-success{border-color:#bbf7d0!important;background:#ecfdf5!important;color:#047857!important;font-weight:850}.edit-readonly-alert{border-color:#bfdbfe!important;background:#eff6ff!important;color:#1e3a8a!important;display:flex;gap:8px 14px;align-items:center;flex-wrap:wrap}.edit-readonly-alert span{color:#475569;font-weight:750}.edit-order-fieldset{border:0;padding:0;margin:0;min-width:0;display:grid;gap:16px}.edit-order-fieldset.is-readonly input,.edit-order-fieldset.is-readonly select,.edit-order-fieldset.is-readonly textarea{opacity:1!important;color:#334155!important;background:#f8fafc!important;-webkit-text-fill-color:#334155!important;cursor:default}.edit-order-fieldset.is-readonly button{cursor:not-allowed}.edit-readonly-badge{display:inline-flex;align-items:center;min-height:38px;padding:0 12px;border-radius:10px;border:1px solid #bfdbfe;background:#eff6ff;color:#1e40af;font-size:12px;font-weight:900}.edit-top-whatsapp-form{display:inline-flex;margin:0}.edit-top-whatsapp-form .btn{height:100%}.edit-section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.edit-section-head h3{margin:0;color:var(--admin-primary,#0b1f5b);font-size:22px}.edit-section-head p{margin:4px 0 0;color:#64748b}.edit-customer-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.edit-items-head{align-items:center}.edit-table-wrap{border-radius:20px}.edit-items-table th{white-space:nowrap}.edit-items-table td{vertical-align:top}.edit-items-table select,.edit-items-table input,.edit-items-table textarea{width:100%;min-width:0;box-sizing:border-box}.edit-items-table textarea{resize:vertical;min-height:44px}.edit-items-table .line-total{text-align:right;font-weight:900;color:#0b1f5b;white-space:nowrap;padding-top:18px}.edit-row-actions{display:flex;gap:6px;justify-content:center;align-items:center}.edit-row-actions .btn{min-width:42px}.edit-row-actions .configure-item-btn{border-color:#bfdbfe;background:#eff6ff;color:#1d4ed8}.edit-row-actions .remove-item-btn{color:#b91c1c;border-color:#fecaca;background:#fff}.edit-row-actions .remove-item-btn:hover{background:#fef2f2}.edit-items-table .row-removed{display:none}.edit-totals-grid{display:grid;grid-template-columns:minmax(200px,1fr) minmax(170px,.7fr) minmax(170px,.7fr);gap:12px;align-items:end}.edit-total-box{border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;padding:13px;display:grid;gap:4px}.edit-total-box span{color:#64748b;font-weight:850}.edit-total-box strong{font-size:20px;color:#0b1f5b}.edit-total-box.final{background:linear-gradient(135deg,#eff6ff,#f8fbff);border-color:#bfdbfe}.edit-total-box.final strong{color:#047857;font-size:23px}.edit-modal[hidden]{display:none!important}.edit-modal{position:fixed;inset:0;z-index:500;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);overflow:auto}.edit-modal-dialog{width:min(980px,100%);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px}.edit-modal-wide{width:min(1120px,100%)}.edit-modal-head{display:flex;gap:12px;justify-content:space-between;align-items:flex-start;margin-bottom:14px}.edit-modal-head h2{margin:0;color:var(--admin-primary,#0b1f5b);font-size:22px}.edit-modal-head p{margin:4px 0 0;color:#64748b}.edit-modal-close{width:42px;height:42px;border-radius:14px;border:1px solid #dbe4f0;background:#fff;font-size:24px;font-weight:900;cursor:pointer}.edit-picker-toolbar{display:grid;grid-template-columns:minmax(0,1fr) 240px;gap:10px;margin-bottom:12px}.edit-product-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px;max-height:62vh;overflow:auto;padding-right:3px}.edit-product-card{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:12px;display:grid;grid-template-columns:48px 1fr;gap:10px;text-align:left;cursor:pointer;box-shadow:0 10px 24px rgba(11,31,91,.05)}.edit-product-card:hover,.edit-product-card.is-active{border-color:#60a5fa;background:#eff6ff;box-shadow:0 0 0 2px rgba(59,130,246,.12)}.edit-product-thumb{width:48px;height:48px;border-radius:14px;background:#eff6ff;object-fit:cover}.edit-product-card strong{display:block;color:#0b1f5b;line-height:1.2}.edit-product-card small{display:block;color:#64748b;margin-top:3px}.edit-product-card b{display:block;color:#047857;margin-top:5px}.edit-tags{display:flex;gap:5px;flex-wrap:wrap;margin-top:6px}.edit-tag{font-size:11px;font-weight:850;color:#1d4ed8;background:#eff6ff;border:1px solid #bfdbfe;border-radius:999px;padding:2px 7px}.edit-config-grid{display:grid;grid-template-columns:160px minmax(0,1fr);gap:10px;margin-bottom:12px}.edit-half-box{border:1px solid #bfdbfe;border-radius:18px;background:#eff6ff;padding:14px;margin:0 0 12px}.edit-half-box strong{display:block;color:var(--admin-primary,#0b1f5b);margin-bottom:6px}.edit-half-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}.edit-opt-group{border:1px solid #dbeafe;border-radius:18px;padding:14px;margin:0 0 12px;background:#fff}.edit-opt-group legend{display:flex;gap:10px;justify-content:space-between;flex-wrap:wrap;color:var(--admin-primary,#0b1f5b);font-weight:950}.edit-opt-group legend small{color:#64748b}.edit-opt-items{display:grid;gap:8px;margin-top:10px}.edit-opt-item{display:flex;gap:10px;align-items:flex-start;border:1px solid #e5edf6;border-radius:15px;padding:11px;cursor:pointer}.edit-opt-item:hover{border-color:#60a5fa;background:#f8fbff}.edit-opt-item span{display:flex;justify-content:space-between;gap:10px;width:100%}.edit-opt-item small{white-space:nowrap;color:#1d4ed8;font-weight:850}.edit-opt-price{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2}.edit-opt-final{font-size:11px;color:#64748b;font-style:normal;font-weight:750;margin-top:2px}.edit-modal-actions{margin-top:14px}@media(max-width:860px){.edit-customer-grid,.edit-totals-grid,.edit-picker-toolbar,.edit-config-grid,.edit-half-grid{grid-template-columns:1fr}.edit-section-head{display:block}.edit-section-head .btn{width:100%;margin-top:10px}.edit-product-grid{grid-template-columns:1fr}.edit-modal{padding:10px}.edit-modal-dialog{border-radius:20px;padding:14px}.edit-items-table select,.edit-items-table input,.edit-items-table textarea{min-width:160px}.edit-row-actions{min-width:96px}}

  .edit-customer-grid.is-advanced{grid-template-columns:minmax(300px,2fr) minmax(180px,1fr) minmax(160px,1fr) minmax(180px,1fr);gap:8px 10px}.edit-customer-grid.is-advanced label{margin:0}.edit-customer-main{grid-column:span 2}.edit-inline-action{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:7px;align-items:center}.edit-inline-action .btn{min-height:42px;white-space:nowrap}.edit-code-search{display:grid;grid-template-columns:minmax(58px,1fr) 42px;gap:5px;align-items:center}.edit-code-search .product-code{min-width:62px;background:#f8fafc}.edit-return-badge{display:inline-flex;align-items:center;padding:4px 8px;border-radius:999px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;font-size:11px;font-weight:900;white-space:nowrap}.edit-muted-mini{color:#94a3b8;font-size:12px}.item-details-btn{border-color:#c7d2fe!important;background:#eef2ff!important;color:#3730a3!important;text-decoration:none}.edit-payment-card{display:grid;gap:12px}.edit-payment-list{display:grid;gap:8px}.edit-payment-row{display:grid;grid-template-columns:minmax(0,1fr) 140px 120px;gap:12px;align-items:center;border:1px solid #dbe4f0;border-radius:15px;padding:11px 12px;background:#fff}.edit-payment-row strong{display:block;color:#0b1f5b}.edit-payment-row small{display:block;color:#64748b;margin-top:3px}.edit-payment-value{text-align:right;font-weight:950;color:#047857;font-size:16px}.edit-empty-state{padding:14px;border-radius:14px;background:#f8fafc;border:1px dashed #cbd5e1;color:#64748b}.edit-detail-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:9px}.edit-detail-box{border:1px solid #dbe4f0;border-radius:14px;padding:11px;background:#f8fbff;min-width:0}.edit-detail-box span{display:block;color:#64748b;font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.03em}.edit-detail-box strong{display:block;color:#0f172a;margin-top:3px;word-break:break-word}.edit-detail-section{margin-top:13px}.edit-detail-section h4{margin:0 0 8px;color:#0b1f5b}.edit-trace-list{display:grid;gap:8px}.edit-trace-item{border:1px solid #dbe4f0;border-radius:15px;padding:12px;background:#fff}.edit-trace-item-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.edit-trace-item strong{color:#0b1f5b}.edit-trace-item small{display:block;color:#64748b;margin-top:4px}.edit-trace-status{font-size:11px;font-weight:900;border-radius:999px;padding:4px 8px;background:#eff6ff;color:#1d4ed8;white-space:nowrap}.edit-trace-sub{margin-top:8px;padding:8px 10px;border-left:3px solid #bfdbfe;background:#f8fbff;font-size:12px}.edit-customer-results{display:grid;gap:6px;max-height:58vh;overflow:auto}.edit-customer-result{width:100%;border:1px solid #dbe4f0;border-radius:14px;background:#fff;text-align:left;padding:11px;cursor:pointer}.edit-customer-result.is-active,.edit-customer-result:hover{border-color:#3b82f6;background:#eff6ff}.edit-customer-result strong{display:block;color:#0b1f5b}.edit-customer-result small{display:block;color:#64748b;margin-top:2px}.edit-customer-picker-tools{grid-template-columns:minmax(0,1fr) auto}.edit-product-picker-actions{grid-column:1/-1;justify-content:flex-end}.edit-product-picker-actions .btn{min-width:110px}.edit-integration-links{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px}.edit-integration-links a{text-decoration:none}
  @media(max-width:1050px){.edit-customer-grid.is-advanced{grid-template-columns:repeat(2,minmax(0,1fr))}.edit-customer-main{grid-column:span 2}.edit-detail-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
  @media(max-width:700px){.edit-customer-grid.is-advanced,.edit-detail-grid,.edit-payment-row,.edit-customer-picker-tools{grid-template-columns:1fr}.edit-customer-main{grid-column:auto}.edit-payment-value{text-align:left}.edit-inline-action{grid-template-columns:1fr}.edit-inline-action .btn{width:100%}}
</style>
<script>
(function(){
  const produtos = <?= $produtosJson ?: '[]' ?>;
  const advancedOrderEdit = <?= $advancedOrderEdit ? 'true' : 'false' ?>;
  const orderReadOnly = <?= $pedidoFinalizadoReadOnly ? 'true' : 'false' ?>;
  const itemFiscalDetails = <?= $itemFiscalDetailsJson ?: '{}' ?>;
  const paymentTrace = <?= $paymentTraceJson ?: '[]' ?>;
  const productOptions = <?= $productOptionsJson ?: '{}' ?>;
  const productOptionDisplay = {
    showAdditionalUnitTotal: <?= $showAdditionalUnitTotal ? 'true' : 'false' ?>,
    showItemTotalWithAdditionals: <?= $showItemTotalWithAdditionals ? 'true' : 'false' ?>,
    itemNoteInstruction: <?= json_encode($itemNoteInstruction, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
  };
  const pizzas = <?= $pizzaJson ?: '[]' ?>;
  const categories = <?= $categoriesJson ?: '["Todos"]' ?>;
  const tbody = document.querySelector('#itemsTable tbody');
  const pickerModal = document.getElementById('productPickerModal');
  const configModal = document.getElementById('itemConfigModal');
  const pickerGrid = document.getElementById('productPickerGrid');
  const pickerSearch = document.getElementById('productPickerSearch');
  const pickerCategory = document.getElementById('productPickerCategory');
  let currentProduct = null;
  let currentTargetRow = null;
  let currentMode = 'append';
  let pickerTargetRow = null;
  let pickerRows = [];
  let pickerActiveIndex = -1;
  let customerRows = [];
  let customerActiveIndex = -1;

  function escapeHtml(v){ return String(v ?? '').replace(/[&<>"']/g, m => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m])); }
  function normalize(v){ return String(v || '').normalize('NFD').replace(/[\u0300-\u036f]/g,'').toLowerCase(); }
  function parseMoney(v){
    let s = String(v || '0').trim().replace(/[^0-9,.-]/g, '');
    if(s.includes(',') && s.includes('.')) s = s.replace(/\./g, '').replace(',', '.');
    else if(s.includes(',')) s = s.replace(',', '.');
    return parseFloat(s) || 0;
  }
  function br(v){ return Number(v || 0).toLocaleString('pt-BR', {style:'currency', currency:'BRL'}); }
  function inputNumber(v){ return Number(v || 0).toFixed(2); }
  function produtoById(id){ return produtos.find(p => Number(p.id) === Number(id)) || null; }
  function productPrice(id){ const p = produtoById(id); return p ? Number(p.price ?? p.preco ?? 0) : 0; }
  function optionHtml(selected){
    return produtos.map(p => '<option value="'+Number(p.id)+'" data-preco="'+Number(p.price ?? p.preco ?? 0)+'" '+(Number(selected)===Number(p.id)?'selected':'')+'>'+escapeHtml(p.name ?? p.nome)+'</option>').join('');
  }
  function openModal(id){ document.getElementById(id).hidden = false; document.body.style.overflow = 'hidden'; }
  function closeModal(id){ document.getElementById(id).hidden = true; if(!document.querySelector('.edit-modal:not([hidden])')) document.body.style.overflow = ''; }

  function calc(){
    let subtotal = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const remove = tr.querySelector('.remove-flag');
      const qtd = parseMoney(tr.querySelector('.qtd')?.value);
      const preco = parseMoney(tr.querySelector('.preco')?.value);
      const total = (remove && remove.value === '1') ? 0 : qtd * preco;
      subtotal += total;
      const cell = tr.querySelector('.line-total');
      if(cell) cell.textContent = br(total);
    });
    const taxa = parseMoney(document.getElementById('taxaEntrega')?.value);
    document.getElementById('subtotalView').textContent = br(subtotal);
    document.getElementById('totalView').textContent = br(subtotal + taxa);
  }

  function rowHtml(cfg){
    const pid = Number(cfg.produtoId || cfg.id || (produtos[0]?.id || 0));
    const prod = produtoById(pid);
    const preco = cfg.preco !== undefined ? Number(cfg.preco || 0) : productPrice(pid);
    const qtd = cfg.qtd !== undefined ? String(cfg.qtd).replace('.', ',') : '1';
    const codeCell = advancedOrderEdit ? '<td><div class="edit-code-search"><input class="product-code" readonly value="'+escapeHtml(prod?.code || pid)+'"><button type="button" class="btn btn-sm product-search-btn" title="Buscar produto (F8)">F8</button></div></td>' : '';
    const returnedCell = advancedOrderEdit ? '<td><span class="edit-muted-mini">—</span></td>' : '';
    const detailAction = advancedOrderEdit ? '<span class="btn btn-sm" style="opacity:.55" title="Salve o item para habilitar os detalhes fiscais">Detalhes</span>' : '';
    return '<tr data-order-item-id="0">'+
      codeCell+
      '<td><input type="hidden" name="item_id[]" value="0"><select name="produto_id[]" class="produto-select" required>'+optionHtml(pid)+'</select></td>'+
      '<td><input class="qtd" name="qtd[]" value="'+escapeHtml(qtd)+'"></td>'+
      '<td><input class="preco" name="preco_unit[]" value="'+inputNumber(preco)+'"></td>'+
      '<td class="line-total">R$ 0,00</td>'+returnedCell+
      '<td><textarea class="item-obs" name="item_obs[]" rows="2" placeholder="'+escapeHtml(productOptionDisplay.itemNoteInstruction || 'Observação do item')+'">'+escapeHtml(cfg.obs || '')+'</textarea></td>'+
      '<td><div class="edit-row-actions"><input type="hidden" class="remove-flag" value="0">'+detailAction+'<button type="button" class="btn btn-sm configure-item-btn" title="Configurar adicionais/meia pizza">⚙️</button><button type="button" class="btn btn-sm btn-danger remove-item-btn" title="Remover item">🗑️</button></div></td>'+
    '</tr>';
  }
  function appendRow(cfg){
    const tmp = document.createElement('tbody');
    tmp.innerHTML = rowHtml(cfg || {});
    const tr = tmp.firstElementChild;
    tbody.appendChild(tr);
    calc();
    return tr;
  }
  function applyRow(tr, cfg){
    if(!tr) tr = appendRow({});
    const pid = Number(cfg.produtoId || cfg.id || 0);
    const sel = tr.querySelector('.produto-select');
    if(sel && pid){ sel.value = String(pid); }
    const prod = produtoById(pid);
    const code = tr.querySelector('.product-code');
    if(code) code.value = prod?.code || pid || '';
    if(cfg.qtd !== undefined) tr.querySelector('.qtd').value = String(cfg.qtd).replace('.', ',');
    if(cfg.preco !== undefined) tr.querySelector('.preco').value = inputNumber(cfg.preco);
    if(cfg.obs !== undefined) tr.querySelector('.item-obs').value = cfg.obs;
    calc();
    return tr;
  }

  function renderPicker(){
    const q = normalize(pickerSearch.value || '');
    const cat = pickerCategory.value || 'Todos';
    const rows = produtos.filter(p => {
      const catOk = cat === 'Todos' || String(p.category || p.categoria || '') === cat;
      const text = normalize([p.name || p.nome, p.category || p.categoria, p.code || p.id, p.reference || '', p.barcode || ''].join(' '));
      return catOk && (!q || text.includes(q));
    });
    pickerRows = rows;
    if(pickerActiveIndex >= rows.length) pickerActiveIndex = rows.length ? rows.length - 1 : -1;
    pickerGrid.innerHTML = rows.map((p,idx) => {
      const tags = [];
      if(p.hasOptions) tags.push('<span class="edit-tag">adicionais</span>');
      if(p.isPizza) tags.push('<span class="edit-tag">meia pizza</span>');
      return '<button type="button" class="edit-product-card '+(idx===pickerActiveIndex?'is-active':'')+'" data-product-id="'+Number(p.id)+'"><img class="edit-product-thumb" src="'+escapeHtml(p.image || '/assets/no-image.svg')+'" alt=""><span><strong>'+escapeHtml(p.name || p.nome)+'</strong><small>'+escapeHtml('Cód. ' + (p.code || p.id) + (p.reference ? ' • Ref. ' + p.reference : '') + ' • ' + (p.category || p.categoria || 'Sem categoria') + (p.barcode ? ' • EAN ' + p.barcode : ''))+'</small><b>'+br(p.price ?? p.preco ?? 0)+'</b><span class="edit-tags">'+tags.join('')+'</span></span></button>';
    }).join('') || '<div class="card">Nenhum produto encontrado.</div>';
  }
  function openPicker(targetRow=null){
    pickerTargetRow = targetRow || null;
    pickerActiveIndex = -1;
    pickerSearch.value = '';
    pickerCategory.innerHTML = categories.map(c => '<option value="'+escapeHtml(c)+'">'+escapeHtml(c)+'</option>').join('');
    renderPicker();
    openModal('productPickerModal');
    setTimeout(() => pickerSearch.focus(), 80);
  }

  function checkedExtra(){
    let extra = 0;
    document.querySelectorAll('#optGroups input:checked').forEach(i => extra += Number(i.dataset.price || 0));
    return extra;
  }
  function updateOptionsTotal(){
    if(!currentProduct) return;
    let base = Number(currentProduct.price ?? currentProduct.preco ?? 0);
    document.querySelectorAll('#optGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.edit-opt-item')?.querySelector('.edit-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + br(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
    });
    const halfEnabled = document.getElementById('halfEnabled');
    const halfFlavor = document.getElementById('halfFlavor');
    if(halfEnabled && halfEnabled.checked && halfFlavor && halfFlavor.value){
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      base = Math.max(base, Number(opt?.dataset.price || 0));
    }
    document.querySelectorAll('#optGroups input').forEach(i => {
      const price = Number(i.dataset.price || 0);
      const finalEl = i.closest('.edit-opt-item')?.querySelector('.edit-opt-final');
      if(finalEl){
        finalEl.textContent = 'Total: ' + br(base + price);
        finalEl.hidden = !(productOptionDisplay.showAdditionalUnitTotal && i.checked);
      }
    });
    const extra = checkedExtra();
    const qty = Math.max(0.001, parseMoney(document.getElementById('optQty').value || '1'));
    document.getElementById('optTotal').value = productOptionDisplay.showItemTotalWithAdditionals ? (br(extra * qty) + ' | Item: ' + br((base + extra) * qty)) : br(extra * qty);
  }
  function openOptionsForProduct(productId, targetRow){
    const p = produtoById(productId); if(!p) return;
    currentProduct = p;
    currentTargetRow = targetRow || null;
    currentMode = targetRow ? 'apply' : 'append';
    const groups = productOptions[p.id] || [];
    document.getElementById('optProductTitle').textContent = (currentMode === 'apply' ? 'Configurar: ' : 'Adicionar: ') + (p.name || p.nome || 'Produto');
    document.getElementById('optQty').value = targetRow ? (targetRow.querySelector('.qtd')?.value || '1') : '1';
    document.getElementById('optNote').value = targetRow ? (targetRow.querySelector('.item-obs')?.value || '') : '';
    const half = document.getElementById('optHalfBox');
    const sameCategoryPizzas = pizzas.filter(x => String(x.category || '').trim().toLocaleLowerCase('pt-BR') === String(p.category || p.categoria || '').trim().toLocaleLowerCase('pt-BR'));
    if(p.isPizza && sameCategoryPizzas.length > 1){
      half.style.display = '';
      half.innerHTML = '<strong>Pizza meio a meio</strong><label style="display:flex;gap:8px;align-items:center;margin:6px 0 10px;"><input type="checkbox" id="halfEnabled"> Montar meio a meio</label><div class="edit-half-grid"><label>1º sabor<input readonly value="'+escapeHtml(p.name || p.nome)+'"></label><label>2º sabor<select id="halfFlavor"><option value="">Selecione o segundo sabor</option>'+sameCategoryPizzas.filter(x=>Number(x.id)!==Number(p.id)).map(x=>'<option value="'+Number(x.id)+'" data-price="'+Number(x.price||0)+'">'+escapeHtml(x.name)+' - '+br(x.price)+'</option>').join('')+'</select></label></div><small style="color:#64748b;display:block;margin-top:8px;">Somente produtos da mesma categoria. Regra de preço: prevalece o maior valor entre os dois sabores. Ao salvar, a meia pizza será gravada em duas linhas de 0,5.</small>';
    } else {
      half.style.display = 'none';
      half.innerHTML = '';
    }
    document.getElementById('optGroups').innerHTML = groups.map(g => {
      const inputType = (g.type === 'multiple' || Number(g.max) > 1) ? 'checkbox' : 'radio';
      return '<fieldset class="edit-opt-group" data-group="'+Number(g.id)+'" data-required="'+Number(g.required||0)+'" data-min="'+Number(g.min||0)+'" data-max="'+Number(g.max||0)+'"><legend><span>'+escapeHtml(g.title)+'</span><small>'+(Number(g.required||0)===1?'Obrigatório':'Opcional')+(Number(g.max||0)>0?' • até '+Number(g.max):'')+'</small></legend><div class="edit-opt-items">'+(g.items||[]).map(it=>'<label class="edit-opt-item"><input type="'+inputType+'" name="opt_group_'+Number(g.id)+'" value="'+Number(it.id)+'" data-name="'+escapeHtml(it.name)+'" data-price="'+Number(it.price||0)+'"><span><b>'+escapeHtml(it.name)+'</b><small class="edit-opt-price"><span>'+(Number(it.price||0)>0?('+'+br(it.price)):'sem acréscimo')+'</span><em class="edit-opt-final" hidden></em></small></span></label>').join('')+'</div></fieldset>';
    }).join('') || '<div class="card" style="background:#f8fbff;border-color:#dbe4f0;color:#64748b;">Este produto não possui adicionais cadastrados. Você ainda pode informar observação ou montar meia pizza quando disponível.</div>';
    updateOptionsTotal();
    openModal('itemConfigModal');
  }
  function confirmOptions(){
    if(!currentProduct) return;
    const groups = Array.from(document.querySelectorAll('#optGroups .edit-opt-group'));
    let extra = 0, lines = [], selectedIds = [];
    for(const g of groups){
      const checked = Array.from(g.querySelectorAll('input:checked'));
      const required = Number(g.dataset.required || 0) === 1;
      const min = Number(g.dataset.min || 0);
      const max = Number(g.dataset.max || 0);
      const needed = min || (required ? 1 : 0);
      if(checked.length < needed){ alert('Selecione as opções obrigatórias.'); return; }
      if(max > 0 && checked.length > max){ alert('Este grupo permite no máximo ' + max + ' opção(ões).'); return; }
      const groupTitle = g.querySelector('legend span')?.textContent.trim() || '';
      const groupLines = [];
      checked.forEach(i => {
        extra += Number(i.dataset.price || 0);
        selectedIds.push(i.value);
        groupLines.push(String(i.dataset.name || '') + (Number(i.dataset.price||0)>0 ? ' +' + br(i.dataset.price) : ''));
      });
      if(groupLines.length) lines.push((groupTitle ? groupTitle + ': ' : '') + groupLines.join(', '));
    }
    let basePrice = Number(currentProduct.price ?? currentProduct.preco ?? 0);
    const halfEnabled = document.getElementById('halfEnabled');
    const halfFlavor = document.getElementById('halfFlavor');
    let secondFlavorId = 0;
    let secondFlavorName = '';
    if(halfEnabled && halfEnabled.checked){
      if(!halfFlavor || !halfFlavor.value){ alert('Selecione o segundo sabor da pizza.'); return; }
      const opt = halfFlavor.options[halfFlavor.selectedIndex];
      secondFlavorId = Number(halfFlavor.value || 0);
      basePrice = Math.max(basePrice, Number(opt?.dataset.price || 0));
      secondFlavorName = (opt.textContent || '').replace(/ - R\$.*/, '').trim();
      lines.unshift('1/2 ' + (currentProduct.name || currentProduct.nome) + ' +');
      lines.unshift('Pizza meio a meio:');
      lines.splice(2, 0, '1/2 ' + secondFlavorName);
      selectedIds.push('half_'+secondFlavorId);
    }
    const qty = Math.max(0.001, parseMoney(document.getElementById('optQty').value || '1'));
    const note = document.getElementById('optNote').value.trim();
    if(note) lines.push(note);
    const finalPrice = basePrice + extra;
    const obs = lines.join('\n');
    if(halfEnabled && halfEnabled.checked){
      const firstQty = qty * 0.5;
      const secondQty = qty * 0.5;
      const baseRow = currentTargetRow || appendRow({produtoId: currentProduct.id});
      applyRow(baseRow, {produtoId: currentProduct.id, qtd: firstQty, preco: finalPrice, obs: obs});
      appendRow({produtoId: secondFlavorId, qtd: secondQty, preco: finalPrice, obs: ''});
    } else {
      const row = currentTargetRow || appendRow({produtoId: currentProduct.id});
      applyRow(row, {produtoId: currentProduct.id, qtd: qty, preco: finalPrice, obs: obs});
    }
    closeModal('itemConfigModal');
    currentProduct = null;
    currentTargetRow = null;
    calc();
  }

  function detailBox(label, value){ return '<div class="edit-detail-box"><span>'+escapeHtml(label)+'</span><strong>'+escapeHtml(value || '—')+'</strong></div>'; }
  function openItemDetails(itemId){
    const d = itemFiscalDetails[String(itemId)] || itemFiscalDetails[itemId] || {};
    document.getElementById('itemDetailsSource').textContent = (d.source || 'Cadastro do produto') + (d.document ? ' · ' + d.document : '');
    let html = '<div class="edit-detail-grid">'+detailBox('NCM',d.ncm)+detailBox('CEST',d.cest)+detailBox('CFOP',d.cfop)+detailBox('Origem',d.origin)+'</div>';
    if(d.icms || d.pis || d.cofins || d.ipi || d.ibs_cbs) html += '<div class="edit-detail-section"><h4>Tributação do cadastro</h4><div class="edit-detail-grid">'+detailBox('ICMS',d.icms)+detailBox('PIS',d.pis)+detailBox('COFINS',d.cofins)+detailBox('IPI',d.ipi)+detailBox('IBS / CBS',d.ibs_cbs)+'</div></div>';
    if(Array.isArray(d.taxes) && d.taxes.length){
      html += '<div class="edit-detail-section"><h4>Snapshot do documento fiscal</h4><div class="edit-trace-list">'+d.taxes.map(t=>'<div class="edit-trace-item"><div class="edit-trace-item-head"><strong>'+escapeHtml(t.tax_group || t.tax_tag || 'Tributo')+'</strong><span class="edit-trace-status">'+escapeHtml(t.csosn ? 'CSOSN '+t.csosn : (t.cst ? 'CST '+t.cst : ''))+'</span></div><small>Base '+br(t.base_calc||0)+' · Alíquota '+Number(t.rate||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:4})+'% · Valor '+br(t.amount||0)+(t.origin_code?' · Origem '+escapeHtml(t.origin_code):'')+'</small></div>').join('')+'</div></div>';
    }
    if(d.notes) html += '<div class="edit-detail-section"><h4>Observação fiscal</h4><div class="edit-empty-state">'+escapeHtml(d.notes)+'</div></div>';
    document.getElementById('itemDetailsBody').innerHTML = html;
    openModal('itemDetailsModal');
  }
  function openPaymentIntegration(paymentId){
    const p = paymentTrace.find(x=>Number(x.id)===Number(paymentId)); if(!p) return;
    document.getElementById('paymentIntegrationSubtitle').textContent = (p.label || 'Pagamento') + ' · ' + br(p.amount || 0) + ' · registrado em ' + (p.created_at || '');
    let html = '<div class="edit-trace-list">';
    const details = Array.isArray(p.details) ? p.details : [];
    if(!details.length) html += '<div class="edit-empty-state">Não foi encontrado um registro financeiro integrado para esta forma de pagamento. Isso pode ocorrer em pedidos antigos.</div>';
    details.forEach(d=>{
      html += '<div class="edit-trace-item"><div class="edit-trace-item-head"><div><strong>'+escapeHtml(d.title||'Integração')+'</strong><small>'+escapeHtml(d.date||'')+(d.extra?' · '+escapeHtml(d.extra):'')+'</small></div><div><span class="edit-trace-status">'+escapeHtml(d.status||'')+'</span><strong style="display:block;text-align:right;margin-top:5px">'+escapeHtml(d.value||'')+'</strong></div></div>';
      if(Array.isArray(d.settlements)) d.settlements.forEach(x=> html += '<div class="edit-trace-sub">Baixa: '+escapeHtml(x.date)+' · '+escapeHtml(x.value)+(x.method?' · '+escapeHtml(x.method):'')+'</div>');
      if(Array.isArray(d.installments)) d.installments.forEach(x=> html += '<div class="edit-trace-sub">Parcela '+Number(x.no||0)+' · venc. '+escapeHtml(x.due||'')+' · '+escapeHtml(x.gross||'')+' bruto / '+escapeHtml(x.net||'')+' líquido · '+escapeHtml(x.status||'')+(x.paid && x.paid!=='—'?' · recebida '+escapeHtml(x.paid):'')+'</div>');
      html += '</div>';
    });
    html += '</div>';
    if(Array.isArray(p.links) && p.links.length) html += '<div class="edit-integration-links">'+p.links.map(l=>'<a class="btn" href="'+escapeHtml(l.href)+'">'+escapeHtml(l.label)+'</a>').join('')+'</div>';
    document.getElementById('paymentIntegrationBody').innerHTML=html;
    openModal('paymentIntegrationModal');
  }
  function renderCustomerRows(){
    const box=document.getElementById('customerPickerResults'); if(!box) return;
    box.innerHTML = customerRows.map((r,i)=>'<button type="button" class="edit-customer-result '+(i===customerActiveIndex?'is-active':'')+'" data-customer-index="'+i+'"><strong>'+escapeHtml(r.name||'Cliente')+'</strong><small>'+escapeHtml([(window.nexaMaskDoc||String)(r.document||''),r.whatsapp||r.phone,r.email,r.city].filter(Boolean).join(' · '))+'</small></button>').join('') || '<div class="edit-empty-state">Nenhum cliente encontrado.</div>';
    box.querySelector('.is-active')?.scrollIntoView({block:'nearest'});
  }
  async function searchCustomers(){
    const input=document.getElementById('customerPickerSearch'); if(!input) return;
    const box=document.getElementById('customerPickerResults'); box.innerHTML='<div class="edit-empty-state">Pesquisando...</div>';
    try { const res=await fetch('pedido_editar.php?id=<?= (int)$id ?>&ajax=people&q='+encodeURIComponent(input.value||''),{credentials:'same-origin'}); const data=await res.json(); customerRows=Array.isArray(data.rows)?data.rows:[]; customerActiveIndex=customerRows.length?0:-1; renderCustomerRows(); }
    catch(err){ box.innerHTML='<div class="edit-empty-state">Não foi possível pesquisar clientes.</div>'; }
  }
  function selectCustomer(index){
    const r=customerRows[index]; if(!r) return;
    document.getElementById('financePersonId').value=String(r.id||'');
    document.getElementById('customerNameInput').value=r.name||'';
    const w=document.getElementById('customerWhatsappInput'); if(w) w.value=r.whatsapp||r.phone||'';
    const b=document.getElementById('customerDistrictInput'); if(b) b.value=r.district||'';
    const a=document.getElementById('customerAddressInput'); if(a) a.value=[r.address,r.number,r.complement].filter(Boolean).join(', ');
    closeModal('customerPickerModal');
  }
  function openCustomerPicker(){
    if(!advancedOrderEdit || orderReadOnly) return;
    const input=document.getElementById('customerPickerSearch'); if(!input) return;
    input.value=''; customerRows=[]; customerActiveIndex=-1; renderCustomerRows(); openModal('customerPickerModal'); setTimeout(()=>input.focus(),60);
  }

  tbody.addEventListener('input', calc);
  tbody.addEventListener('change', function(e){
    if(e.target.classList.contains('produto-select')){
      const opt = e.target.selectedOptions[0];
      const preco = opt ? opt.getAttribute('data-preco') : '0';
      const tr = e.target.closest('tr');
      if(tr && tr.querySelector('.preco')) tr.querySelector('.preco').value = inputNumber(preco || 0);
      const p=produtoById(e.target.value); const code=tr?.querySelector('.product-code'); if(code) code.value=p?.code || e.target.value || '';
    }
    calc();
  });
  tbody.addEventListener('click', function(e){
    const detailBtn=e.target.closest('.item-details-btn');
    if(detailBtn){ e.preventDefault(); openItemDetails(detailBtn.dataset.itemId); return; }
    const searchBtn=e.target.closest('.product-search-btn');
    if(searchBtn){ openPicker(searchBtn.closest('tr')); return; }
    const configBtn = e.target.closest('.configure-item-btn');
    if(configBtn){
      const tr = configBtn.closest('tr');
      const productId = tr?.querySelector('.produto-select')?.value;
      if(productId) openOptionsForProduct(productId, tr);
      return;
    }
    const btn = e.target.closest('.remove-item-btn');
    if(!btn) return;
    const tr = btn.closest('tr');
    if(!tr) return;
    const itemId = parseInt(tr.querySelector('input[name="item_id[]"]')?.value || '0', 10);
    if(itemId > 0){
      if(!confirm('Remover este item do pedido?')) return;
      const flag = tr.querySelector('.remove-flag');
      if(flag) flag.value = '1';
      tr.classList.add('row-removed');
    } else {
      tr.remove();
    }
    calc();
  });
  document.getElementById('taxaEntrega').addEventListener('input', calc);
  document.getElementById('addItemBtn').addEventListener('click', () => openPicker(null));
  pickerSearch.addEventListener('input', () => { pickerActiveIndex=-1; renderPicker(); });
  pickerCategory.addEventListener('change', renderPicker);
  pickerGrid.addEventListener('click', function(e){
    const card = e.target.closest('[data-product-id]');
    if(!card) return;
    const productId = Number(card.dataset.productId || 0);
    const p = produtoById(productId); if(!p) return;
    closeModal('productPickerModal');
    const target=pickerTargetRow; pickerTargetRow=null;
    if(p.hasOptions || p.isPizza) openOptionsForProduct(productId, target);
    else if(target) applyRow(target,{produtoId: productId, preco: productPrice(productId)});
    else appendRow({produtoId: productId, qtd: 1, preco: productPrice(productId), obs: ''});
  });
  if(advancedOrderEdit){
    document.querySelectorAll('.payment-integration-btn').forEach(b=>b.addEventListener('click',()=>openPaymentIntegration(b.dataset.paymentId)));
    const cs=document.getElementById('customerSearchBtn'); if(cs) cs.addEventListener('click',openCustomerPicker);
    const doSearch=document.getElementById('customerPickerDoSearch'); if(doSearch) doSearch.addEventListener('click',searchCustomers);
    const cbox=document.getElementById('customerPickerResults'); if(cbox) cbox.addEventListener('click',e=>{ const b=e.target.closest('[data-customer-index]'); if(b) selectCustomer(Number(b.dataset.customerIndex)); });
    const ci=document.getElementById('customerPickerSearch'); if(ci) ci.addEventListener('keydown',e=>{
      if(e.key==='ArrowDown'){ e.preventDefault(); if(customerRows.length){ customerActiveIndex=Math.min(customerRows.length-1,customerActiveIndex+1); renderCustomerRows(); } }
      else if(e.key==='ArrowUp'){ e.preventDefault(); if(customerRows.length){ customerActiveIndex=Math.max(0,customerActiveIndex-1); renderCustomerRows(); } }
      else if(e.key==='Enter'){ e.preventDefault(); if(customerRows.length && customerActiveIndex>=0) selectCustomer(customerActiveIndex); else searchCustomers(); }
    });
    const pSearch=document.getElementById('productPickerDoSearch'); if(pSearch) pSearch.addEventListener('click',renderPicker);
    pickerSearch.addEventListener('keydown',e=>{
      if(e.key==='ArrowDown'){ e.preventDefault(); if(pickerRows.length){ pickerActiveIndex=Math.min(pickerRows.length-1,pickerActiveIndex+1); renderPicker(); } }
      else if(e.key==='ArrowUp'){ e.preventDefault(); if(pickerRows.length){ pickerActiveIndex=Math.max(0,pickerActiveIndex<0?0:pickerActiveIndex-1); renderPicker(); } }
      else if(e.key==='Enter'){ e.preventDefault(); if(pickerActiveIndex>=0 && pickerRows[pickerActiveIndex]){ const card=pickerGrid.querySelector('[data-product-id="'+Number(pickerRows[pickerActiveIndex].id)+'"]'); if(card) card.click(); } else { renderPicker(); if(pickerRows.length){ pickerActiveIndex=0; renderPicker(); } } }
    });
    document.addEventListener('keydown',e=>{
      if(e.key!=='F8' || e.ctrlKey || e.altKey || e.metaKey || orderReadOnly) return;
      if(document.querySelector('.edit-modal:not([hidden])')) return;
      const active=document.activeElement;
      if(active && (active.id==='customerNameInput' || active.closest?.('.edit-customer-main'))){ e.preventDefault(); openCustomerPicker(); return; }
      const tr=active?.closest?.('#itemsTable tbody tr'); if(tr){ e.preventDefault(); openPicker(tr); }
    });
  }
  document.getElementById('optGroups').addEventListener('change', updateOptionsTotal);
  document.getElementById('optHalfBox').addEventListener('change', updateOptionsTotal);
  document.getElementById('optQty').addEventListener('input', updateOptionsTotal);
  document.getElementById('optCancel').addEventListener('click', () => { closeModal('itemConfigModal'); currentProduct=null; currentTargetRow=null; });
  document.getElementById('optConfirm').addEventListener('click', confirmOptions);
  document.querySelectorAll('[data-close-edit-modal]').forEach(btn => btn.addEventListener('click', () => closeModal(btn.dataset.closeEditModal)));
  document.querySelectorAll('.edit-modal').forEach(modal => modal.addEventListener('click', e => { if(e.target === modal) closeModal(modal.id); }));
  document.addEventListener('keydown', e => { if(e.key === 'Escape'){ document.querySelectorAll('.edit-modal:not([hidden])').forEach(m => closeModal(m.id)); }});
  document.getElementById('orderEditForm').addEventListener('submit', function(e){
    const visibleRows = Array.from(tbody.querySelectorAll('tr')).filter(tr => !(tr.querySelector('.remove-flag') && tr.querySelector('.remove-flag').value === '1'));
    if(!visibleRows.length){ e.preventDefault(); alert('Informe ao menos um item no pedido.'); return; }
  });
  calc();
})();
</script>
<?php admin_footer(); ?>
