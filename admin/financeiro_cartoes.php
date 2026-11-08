<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/card_control.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$cross = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
$schemaReady = card_control_schema_ready($pdo);
$canSettle = function_exists('finance_effective_permission_allowed')
  ? finance_effective_permission_allowed($pdo, 'financeiro_baixar_receber')
  : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_baixar_receber'));
if ($cross && function_exists('multi_company_context_permission_allowed')) {
  $canSettle = $canSettle && multi_company_context_permission_allowed($pdo, 'financeiro_baixar_receber');
}
$mcCtx = $cross && function_exists('multi_company_context_request_token') ? multi_company_context_request_token() : '';
$mcCsrf = $cross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!$schemaReady) throw new RuntimeException('A estrutura do Controle de Cartões ainda não foi instalada. Execute o Atualizador do Banco.');
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    if ($action !== 'settle') throw new RuntimeException('Ação inválida.');
    if (!$canSettle) throw new RuntimeException('Seu usuário não possui permissão para baixar recebimentos.');
    if ($cross) {
      if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
        throw new RuntimeException('Sessão Multiempresa expirada. Atualize a página e tente novamente.');
      }
    } else {
      card_control_csrf_check((string)($_POST['csrf'] ?? ''));
    }
    $ids = $_POST['installment_ids'] ?? [];
    if (!is_array($ids)) $ids = [];
    $bankId = (int)($_POST['bank_account_id'] ?? 0);
    $paymentDate = (string)($_POST['payment_date'] ?? date('Y-m-d'));
    $notes = trim((string)($_POST['settlement_notes'] ?? ''));
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
    $result = card_control_settle_installments($pdo, $cid, $ids, $bankId, $paymentDate, $uid, $notes);
    $ok = sprintf('%d parcela(s) recebida(s). Bruto R$ %s · Taxas R$ %s · Líquido R$ %s.', (int)$result['count'], finance_money_br($result['gross']), finance_money_br($result['fee']), finance_money_br($result['net']));
    $url = 'financeiro_cartoes.php?ok=' . rawurlencode($ok);
    if ($cross && function_exists('multi_company_context_url')) $url = multi_company_context_url($url);
    header('Location: ' . $url); exit;
  } catch (Throwable $e) { $err = $e->getMessage(); }
}

$ini = finance_date_or_null($_GET['ini'] ?? '') ?: date('Y-m-01');
$fim = finance_date_or_null($_GET['fim'] ?? '') ?: date('Y-m-t');
$status = strtoupper(trim((string)($_GET['status'] ?? 'ABERTO')));
if (!in_array($status, ['ABERTO','RECEBIDO','CANCELADO','TODOS'], true)) $status='ABERTO';
$dateRef = strtoupper(trim((string)($_GET['date_ref'] ?? 'VENCIMENTO')));
if (!in_array($dateRef,['VENCIMENTO','LANCAMENTO','PAGAMENTO'],true)) $dateRef='VENCIMENTO';
$q = trim((string)($_GET['q'] ?? ''));
$paymentMethodId = max(0,(int)($_GET['payment_method_id'] ?? 0));
$orderFilter = max(0,(int)($_GET['f_order'] ?? 0));
$customerFilter = trim((string)($_GET['f_customer'] ?? ''));
$typeFilter = trim((string)($_GET['f_type'] ?? ''));
$nsuFilter = trim((string)($_GET['f_nsu'] ?? ''));
$statusColFilter = strtoupper(trim((string)($_GET['f_status'] ?? '')));
if (!in_array($statusColFilter,['','ABERTO','RECEBIDO','CANCELADO'],true)) $statusColFilter='';

$sort = strtolower(trim((string)($_GET['sort'] ?? 'due')));
$dir = strtolower(trim((string)($_GET['dir'] ?? 'asc'))) === 'desc' ? 'DESC' : 'ASC';
$sortMap = [
  'due'=>'ci.due_date','sale'=>'cs.sale_date','order'=>'cs.order_id','customer'=>'fp.name','gross'=>'ci.gross_amount',
  'fee'=>'ci.fee_amount','net'=>'ci.net_amount','installment'=>'ci.installment_no','paid'=>'ci.payment_date','status'=>'ci.status','type'=>'pm.name','nsu'=>'cs.nsu'
];
$orderSql = $sortMap[$sort] ?? $sortMap['due'];

$rows=[];$totals=['count'=>0,'gross'=>0,'fee'=>0,'net'=>0];$banks=[];$methods=[];
if ($schemaReady) {
  $dateColumn = $dateRef==='LANCAMENTO' ? 'cs.sale_date' : ($dateRef==='PAGAMENTO' ? 'ci.payment_date' : 'ci.due_date');
  $where=['ci.company_id=?'];$params=[$cid];
  if ($dateRef==='PAGAMENTO') $where[]='ci.payment_date IS NOT NULL';
  $where[]="$dateColumn BETWEEN ? AND ?";$params[]=$ini;$params[]=$fim;
  if($status!=='TODOS'){$where[]='ci.status=?';$params[]=$status;}
  if($paymentMethodId>0){$where[]='cs.payment_method_id=?';$params[]=$paymentMethodId;}
  if($orderFilter>0){$where[]='cs.order_id=?';$params[]=$orderFilter;}
  if($customerFilter!==''){$where[]='fp.name LIKE ?';$params[]='%'.$customerFilter.'%';}
  if($typeFilter!==''){$where[]='pm.name LIKE ?';$params[]='%'.$typeFilter.'%';}
  if($nsuFilter!==''){$where[]='(cs.nsu LIKE ? OR cs.authorization_code LIKE ? OR ci.acquirer_nsu LIKE ? OR ci.acquirer_authorization_code LIKE ?)';array_push($params,'%'.$nsuFilter.'%','%'.$nsuFilter.'%','%'.$nsuFilter.'%','%'.$nsuFilter.'%');}
  if($statusColFilter!==''){$where[]='ci.status=?';$params[]=$statusColFilter;}
  if($q!==''){
    $where[]='(fp.name LIKE ? OR fp.document LIKE ? OR CAST(cs.order_id AS CHAR) LIKE ? OR pm.name LIKE ? OR cs.acquirer_name LIKE ? OR cs.nsu LIKE ? OR cs.authorization_code LIKE ?)';
    $like='%'.$q.'%'; array_push($params,$like,$like,$like,$like,$like,$like,$like);
  }
  $sql="SELECT ci.*,cs.order_id,cs.person_id,cs.sale_date,cs.payment_method_id,cs.acquirer_name,cs.card_brand,cs.nsu,cs.authorization_code,cs.provider_transaction_id,
      fp.name person_name,fp.document person_document,pm.name payment_name,
      ba_def.name default_bank_name,ba_recv.name received_bank_name,ca.code chart_code,ca.name chart_name
    FROM finance_card_installments ci
    JOIN finance_card_sales cs ON cs.company_id=ci.company_id AND cs.id=ci.card_sale_id
    LEFT JOIN finance_people fp ON fp.company_id=cs.company_id AND fp.id=cs.person_id
    LEFT JOIN finance_payment_methods pm ON pm.company_id=cs.company_id AND pm.id=cs.payment_method_id
    LEFT JOIN finance_bank_accounts ba_def ON ba_def.company_id=cs.company_id AND ba_def.id=cs.bank_account_id
    LEFT JOIN finance_bank_accounts ba_recv ON ba_recv.company_id=ci.company_id AND ba_recv.id=ci.bank_account_id
    LEFT JOIN finance_chart_accounts ca ON ca.company_id=cs.company_id AND ca.id=cs.chart_account_id
    WHERE ".implode(' AND ',$where)." ORDER BY $orderSql $dir, ci.id ASC LIMIT 3000";
  $rows=finance_query_all($pdo,$sql,$params);
  foreach($rows as $r){$totals['count']++;$totals['gross']+=(float)$r['gross_amount'];$totals['fee']+=(float)$r['fee_amount'];$totals['net']+=(float)$r['net_amount'];}
  $banks=finance_query_all($pdo,'SELECT id,name FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name',[$cid]);
  $methods=finance_query_all($pdo,'SELECT id,name FROM finance_payment_methods WHERE company_id=? AND is_active=1 AND COALESCE(card_control_enabled,0)=1 ORDER BY sort_order,name',[$cid]);
}

function card_sort_url(string $key): string {
  $p=$_GET; $current=strtolower((string)($_GET['sort']??'due')); $curDir=strtolower((string)($_GET['dir']??'asc'));
  $p['sort']=$key; $p['dir']=($current===$key && $curDir!=='desc')?'desc':'asc'; unset($p['ok'],$p['err']);
  $url='financeiro_cartoes.php?'.http_build_query($p);
  if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO&&function_exists('finance_multi_company_cross_context')&&finance_multi_company_cross_context($GLOBALS['pdo'])&&function_exists('multi_company_context_url'))$url=multi_company_context_url($url);
  return $url;
}
function card_query_url(array $patch=[]): string {
  $p=array_merge($_GET,$patch); unset($p['ok'],$p['err']);
  foreach($p as $k=>$v)if($v===''||$v===null)unset($p[$k]);
  $url='financeiro_cartoes.php'.($p?'?'.http_build_query($p):'');
  if(isset($GLOBALS['pdo'])&&$GLOBALS['pdo'] instanceof PDO&&function_exists('finance_multi_company_cross_context')&&finance_multi_company_cross_context($GLOBALS['pdo'])&&function_exists('multi_company_context_url'))$url=multi_company_context_url($url);
  return $url;
}

function card_sort_mark(string $key): string {
  $current = strtolower(trim((string)($_GET['sort'] ?? 'due')));
  $currentDir = strtolower(trim((string)($_GET['dir'] ?? 'asc')));
  if ($current !== $key) return '↕';
  return $currentDir === 'desc' ? '↓' : '↑';
}

function card_grid_filter_menu(string $field, string $label, string $value, string $kind='text'): string {
  $active = trim($value) !== '';
  $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
  $labelEsc = htmlspecialchars($label, ENT_QUOTES, 'UTF-8');
  $valueEsc = htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
  $activeClass = $active ? ' has-filter' : '';
  $activeTitle = $active ? ' (filtro ativo)' : '';
  $icon = '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.3l-3.6 1.8v-7.1L4 5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
  if ($kind === 'status') {
    $control = '<select form="cardColumnFilterForm" class="grid-filter-input" name="'.$fieldEsc.'">'
      .'<option value=""'.($value===''?' selected':'').'>Todos</option>'
      .'<option value="ABERTO"'.($value==='ABERTO'?' selected':'').'>Aberto</option>'
      .'<option value="RECEBIDO"'.($value==='RECEBIDO'?' selected':'').'>Recebido</option>'
      .'<option value="CANCELADO"'.($value==='CANCELADO'?' selected':'').'>Cancelado</option>'
      .'</select>';
  } else {
    $inputmode = $kind === 'number' ? ' inputmode="numeric"' : '';
    $control = '<input form="cardColumnFilterForm" class="grid-filter-input" name="'.$fieldEsc.'" value="'.$valueEsc.'" placeholder="Digite para filtrar"'.$inputmode.' autocomplete="off">';
  }
  return '<details class="grid-filter-menu'.$activeClass.'" data-grid-filter-menu>'
    .'<summary class="grid-filter-trigger" title="Filtrar '.$labelEsc.$activeTitle.'" aria-label="Filtrar '.$labelEsc.'">'.$icon.'</summary>'
    .'<div class="grid-filter-popover">'
      .'<div class="grid-filter-title">Filtrar '.$labelEsc.'</div>'
      .$control
      .'<div class="grid-filter-actions">'
        .'<button class="btn btn-primary grid-filter-apply" type="submit" form="cardColumnFilterForm">Aplicar</button>'
        .'<button class="btn grid-filter-clear" type="button" data-clear-filter="'.$fieldEsc.'">Limpar</button>'
      .'</div>'
    .'</div>'
  .'</details>';
}

$headerActions = [['href'=>'gestao_formas_pagamento.php','label'=>'Configurar cartões','class'=>'btn btn-primary']];
finance_admin_start('Financeiro - Controle de Cartões', 'fin_cartoes');
finance_page_header('Controle de Cartões','', $headerActions);
finance_flash();
if($err!==''): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>
<?php if(!$schemaReady): ?><div class="msg err"><strong>Estrutura pendente.</strong> Aplique a migration <b>V11.52.7 — Controle de Cartões</b> pelo Atualizador do Banco antes de usar esta tela.</div><?php endif; ?>

<style>
/* V11.52.7.2: Controle de Cartões alinhado ao padrão do Cadastro de Produtos. */
.card-kpis{gap:10px;margin:4px 0 12px}.card-kpi{padding:8px 14px;border-radius:14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap}.card-kpi small{margin:0;font-size:12px}.card-kpi strong{display:block;color:#07184d;font-size:clamp(17px,1.7vw,21px);margin-top:0}
.card-filter{display:grid;grid-template-columns:minmax(270px,1.8fr) minmax(140px,.85fr) 145px 145px minmax(118px,.65fr) minmax(185px,1fr) auto;gap:14px;align-items:end}.card-filter label{font-size:11px;font-weight:850;color:#52627d;margin-bottom:4px}.card-filter input,.card-filter select{min-height:40px}.card-filter-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;padding-bottom:1px}.card-filter-actions .btn{min-height:40px;min-width:78px}
.card-batch{display:grid;grid-template-columns:minmax(190px,1fr) 165px minmax(220px,1.5fr) auto;gap:8px;align-items:end;padding:12px 14px;border-top:1px solid #e4ebf5;background:#f8fbff}.card-batch label{font-size:11px;font-weight:850;color:#52627d}
.card-grid-wrap{overflow-x:auto;overflow-y:visible;border-top:1px solid #e5edf8;background:#fff}.card-grid{width:100%;min-width:1920px;border-collapse:separate;border-spacing:0;table-layout:fixed;font-size:12px}.card-grid th,.card-grid td{padding:9px 10px;border-bottom:1px solid #e7edf6;border-right:1px solid #f0f4f9;vertical-align:middle}.card-grid thead th{position:sticky;top:0;z-index:15;background:#f7faff;color:#243b70;font-size:11px;text-transform:uppercase;letter-spacing:.025em;font-weight:900;white-space:nowrap}.card-grid tbody tr:nth-child(odd) td{background:#fff}.card-grid tbody tr:nth-child(even) td{background:#dfe8f4}.card-grid tbody tr:hover td{background:#cbdaf0}.card-grid .num{text-align:right;font-variant-numeric:tabular-nums}.card-grid .center{text-align:center}.card-grid .nowrap{white-space:nowrap}.card-grid th[data-col]{position:relative;user-select:none}.card-grid thead th.filter-open{z-index:95}
.card-grid .col-head{display:flex;gap:5px;align-items:center;min-width:0;min-height:22px}.card-grid .drag{display:inline-flex;align-items:center;justify-content:center;flex:0 0 auto;width:14px;height:22px;color:#94a3b8;cursor:grab;border-radius:4px;font-size:11px}.card-grid .drag:hover{color:#3558c9;background:#e8efff}.card-grid .sort{display:flex;min-width:0;flex:1;gap:5px;align-items:center;justify-content:space-between;color:inherit;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.card-grid .sort .sort-mark{font-size:12px;color:#64748b}.card-grid .resizer{position:absolute;right:-3px;top:0;width:7px;height:100%;cursor:col-resize;z-index:110;touch-action:none}.card-grid .resizer:after{content:'';position:absolute;left:3px;top:20%;bottom:20%;width:1px;background:#c8d4e8;opacity:.55}.card-grid .resizer:hover:after{background:#3558c9;opacity:1;width:2px}
.grid-filter-menu{position:relative;flex:0 0 auto}.grid-filter-menu>summary{list-style:none}.grid-filter-menu>summary::-webkit-details-marker{display:none}.grid-filter-trigger{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;color:#71809c;cursor:pointer;background:transparent}.grid-filter-trigger:hover{background:#eaf1ff;color:#2448b8;border-color:#cfdbf5}.grid-filter-trigger svg{width:14px;height:14px}.grid-filter-menu.has-filter .grid-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3;box-shadow:inset 0 -2px 0 #3558c9}.grid-filter-menu[open] .grid-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3}.grid-filter-popover{position:absolute;top:calc(100% + 7px);right:0;z-index:120;width:240px;padding:12px;background:#fff;border:1px solid #d9e3f2;border-radius:12px;box-shadow:0 16px 38px rgba(15,35,80,.22);text-transform:none;letter-spacing:normal;font-weight:600;white-space:normal}.grid-filter-title{font-size:12px;font-weight:900;color:#18336f;margin-bottom:8px}.grid-filter-input{width:100%;height:36px;border:1px solid #ccd8ea;border-radius:8px;padding:6px 9px;background:#fff;color:#0f172a;font-size:12px;outline:none}.grid-filter-input:focus{border-color:#4c6fff;box-shadow:0 0 0 2px rgba(76,111,255,.10)}.grid-filter-actions{display:flex;gap:7px;margin-top:9px}.grid-filter-actions .btn{min-height:32px;padding:6px 10px;font-size:11px;flex:1;justify-content:center}
.card-list-card{padding:0}.card-list-head{padding:15px 16px 10px;margin:0}.card-list-actions{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;margin-left:auto}.card-check-all{display:inline-flex;align-items:center;gap:7px;font-size:12px;font-weight:850;color:#334155;white-space:nowrap}.card-check-all input{width:16px;height:16px}.card-columns{position:relative}.card-columns summary{list-style:none;cursor:pointer}.card-columns summary::-webkit-details-marker{display:none}.card-columns-panel{position:absolute;z-index:140;right:0;left:auto;top:calc(100% + 7px);width:250px;max-height:360px;overflow:auto;background:#fff;border:1px solid #d9e3f1;border-radius:12px;box-shadow:0 14px 36px rgba(15,35,80,.18);padding:10px}.card-columns-panel label{display:flex;align-items:center;gap:8px;padding:5px;font-size:12px}.card-columns-note{border-top:1px solid #eef4ff;margin-top:8px;padding-top:8px;font-size:11px;color:#64748b}.card-grid .hidden-col{display:none!important}
.card-status{display:inline-flex;border-radius:999px;padding:3px 7px;font-weight:900;border:1px solid;white-space:nowrap}.card-status.ABERTO{color:#1d4ed8;background:#eff6ff;border-color:#bfdbfe}.card-status.RECEBIDO{color:#047857;background:#ecfdf5;border-color:#a7f3d0}.card-status.CANCELADO{color:#b91c1c;background:#fef2f2;border-color:#fecaca}.selected-summary{font-size:12px;font-weight:850;color:#0f766e;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;padding:7px 10px}.selected-summary strong{font-size:14px}.grid-empty{text-align:center!important;padding:28px!important;color:#64748b}.card-check{width:16px;height:16px}.card-note{font-size:10px;color:#64748b;display:block;margin-top:2px}
@media(max-width:1200px){.card-filter{grid-template-columns:repeat(3,minmax(0,1fr))}.card-filter-actions{justify-content:flex-start}}
@media(max-width:900px){.card-kpis{grid-template-columns:repeat(2,1fr)}.card-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.card-filter-actions{grid-column:1/-1}.card-batch{grid-template-columns:1fr 1fr}.card-batch .notes,.card-batch .actions{grid-column:1/-1}.card-list-head{display:grid;gap:10px}.card-list-actions{justify-content:flex-start;margin-left:0}.card-columns-panel{left:0;right:auto}}
@media(max-width:520px){.card-kpis,.card-filter,.card-batch{grid-template-columns:1fr}.card-batch .notes,.card-batch .actions{grid-column:auto}.card-filter-actions{display:grid;grid-template-columns:1fr 1fr}.card-filter-actions .btn{width:100%;justify-content:center}}

</style>

<?php if($schemaReady): ?>
<div class="finance-grid-4 card-kpis">
  <div class="finance-kpi card-kpi"><small>Parcelas no filtro</small><strong><?= (int)$totals['count'] ?></strong></div>
  <div class="finance-kpi card-kpi"><small>Valor bruto</small><strong>R$ <?= finance_money_br($totals['gross']) ?></strong></div>
  <div class="finance-kpi card-kpi"><small>Taxas previstas</small><strong>R$ <?= finance_money_br($totals['fee']) ?></strong></div>
  <div class="finance-kpi card-kpi"><small>Valor líquido</small><strong>R$ <?= finance_money_br($totals['net']) ?></strong></div>
</div>
<div class="finance-card finance-filter-card"><form method="get" class="card-filter" id="cardSearchForm">
  <?php if($cross && $mcCtx!==''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($mcCtx) ?>"><?php endif; ?>
  <div><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Cliente, pedido, operadora, NSU ou autorização"></div>
  <div><label>Data referência</label><select name="date_ref"><option value="VENCIMENTO" <?= $dateRef==='VENCIMENTO'?'selected':'' ?>>Vencimento</option><option value="LANCAMENTO" <?= $dateRef==='LANCAMENTO'?'selected':'' ?>>Lançamento</option><option value="PAGAMENTO" <?= $dateRef==='PAGAMENTO'?'selected':'' ?>>Pagamento</option></select></div>
  <div><label>De</label><input type="date" name="ini" value="<?= finance_h($ini) ?>"></div>
  <div><label>Até</label><input type="date" name="fim" value="<?= finance_h($fim) ?>"></div>
  <div><label>Situação</label><select name="status"><option value="ABERTO" <?= $status==='ABERTO'?'selected':'' ?>>Abertas</option><option value="RECEBIDO" <?= $status==='RECEBIDO'?'selected':'' ?>>Recebidas</option><option value="CANCELADO" <?= $status==='CANCELADO'?'selected':'' ?>>Canceladas</option><option value="TODOS" <?= $status==='TODOS'?'selected':'' ?>>Todas</option></select></div>
  <div><label>Forma / cartão</label><select name="payment_method_id"><option value="0">Todas</option><?php foreach($methods as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $paymentMethodId===(int)$m['id']?'selected':'' ?>><?= finance_h($m['name']) ?></option><?php endforeach; ?></select></div>
  <div class="card-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?= finance_h(card_query_url(['ini'=>null,'fim'=>null,'status'=>null,'date_ref'=>null,'payment_method_id'=>null,'q'=>null,'f_order'=>null,'f_customer'=>null,'f_type'=>null,'f_nsu'=>null,'f_status'=>null,'sort'=>null,'dir'=>null])) ?>">Limpar</a></div>
</form></div>

<div class="finance-card card-list-card" style="margin-top:9px">
  <div class="finance-card-head card-list-head">
    <div><h2 class="finance-subtitle">Recebimentos de cartões</h2><span class="finance-muted"><?= (int)$totals['count'] ?> parcela(s) no filtro atual</span></div>
    <div class="card-list-actions">
      <?php if($canSettle): ?><label class="card-check-all"><input type="checkbox" id="cardCheckAll"> Selecionar todos em aberto</label><?php endif; ?>
      <div class="selected-summary">Selecionados: <strong id="cardSelectedCount">0</strong> · Líquido: <strong id="cardSelectedValue">R$ 0,00</strong></div>
      <details class="card-columns" id="cardColumnsPicker"><summary class="btn">Colunas</summary><div class="card-columns-panel" id="cardColumnsPanel"></div></details>
    </div>
  </div>
  <form method="get" id="cardColumnFilterForm"><?php if($cross && $mcCtx!==''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($mcCtx) ?>"><?php endif; ?><input type="hidden" name="ini" value="<?= finance_h($ini) ?>"><input type="hidden" name="fim" value="<?= finance_h($fim) ?>"><input type="hidden" name="date_ref" value="<?= finance_h($dateRef) ?>"><input type="hidden" name="status" value="<?= finance_h($status) ?>"><input type="hidden" name="payment_method_id" value="<?= (int)$paymentMethodId ?>"><input type="hidden" name="q" value="<?= finance_h($q) ?>"><input type="hidden" name="sort" value="<?= finance_h($sort) ?>"><input type="hidden" name="dir" value="<?= finance_h(strtolower($dir)) ?>"></form>
  <form method="post" id="cardSettlementForm">
    <input type="hidden" name="action" value="settle"><?php if($cross): ?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php if($mcCtx!==''): ?><input type="hidden" name="mc_ctx" value="<?= finance_h($mcCtx) ?>"><?php endif; ?><?php else: ?><input type="hidden" name="csrf" value="<?= finance_h(card_control_csrf_token()) ?>"><?php endif; ?>
    <div class="card-grid-wrap"><table class="card-grid" id="cardGrid">
      <thead><tr>
<?php
$columns=[
 'select'=>'Selecionar','due'=>'Vencimento','sale'=>'Lançamento','order'=>'Pedido','customer_code'=>'Cód. cliente','customer'=>'Cliente','default_bank'=>'Caixa previsto','gross'=>'Valor original','fee_pct'=>'Taxa %','fee'=>'Valor taxa','net'=>'Valor a receber','installment'=>'Nº parcela','total_installments'=>'Total parcelas','paid'=>'Data pagamento','received_bank'=>'Caixa recebido','parcel'=>'Parcela','status'=>'Situação','chart'=>'Plano de contas','type'=>'Tipo','nsu'=>'NSU / autorização'
];
$sortKeys=['due'=>'due','sale'=>'sale','order'=>'order','customer'=>'customer','gross'=>'gross','fee'=>'fee','net'=>'net','installment'=>'installment','paid'=>'paid','status'=>'status','type'=>'type','nsu'=>'nsu'];
$columnFilters=[
  'order'=>['f_order','Pedido',$orderFilter>0?(string)$orderFilter:'','number'],
  'customer'=>['f_customer','Cliente',$customerFilter,'text'],
  'status'=>['f_status','Situação',$statusColFilter,'status'],
  'type'=>['f_type','Tipo',$typeFilter,'text'],
  'nsu'=>['f_nsu','NSU / autorização',$nsuFilter,'text'],
];
foreach($columns as $key=>$label): ?>
        <th data-col="<?= finance_h($key) ?>"><div class="col-head"><?php if($key!=='select'): ?><span class="drag" title="Arrastar coluna">⠿</span><?php endif; ?><?php if(isset($sortKeys[$key])): ?><a class="sort" href="<?= finance_h(card_sort_url($sortKeys[$key])) ?>"><span><?= finance_h($label) ?></span><span class="sort-mark"><?= finance_h(card_sort_mark($sortKeys[$key])) ?></span></a><?php else: ?><span class="sort"><?= finance_h($label) ?></span><?php endif; ?><?php if(isset($columnFilters[$key])): $fc=$columnFilters[$key]; echo card_grid_filter_menu($fc[0],$fc[1],$fc[2],$fc[3]); endif; ?><span class="resizer"></span></div></th>
<?php endforeach; ?></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="<?= count($columns) ?>" class="grid-empty">Nenhuma parcela de cartão encontrada para os filtros informados.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $open=(string)$r['status']==='ABERTO'; ?>
<tr>
  <td data-col="select" class="center"><?php if($open && $canSettle): ?><input class="card-check card-row-check" type="checkbox" name="installment_ids[]" value="<?= (int)$r['id'] ?>" data-net="<?= finance_h(number_format((float)$r['net_amount'],2,'.','')) ?>"><?php endif; ?></td>
  <td data-col="due" class="nowrap"><?= finance_date_br($r['due_date']) ?></td>
  <td data-col="sale" class="nowrap"><?= finance_date_br($r['sale_date']) ?></td>
  <td data-col="order" class="center"><strong>#<?= (int)$r['order_id'] ?></strong></td>
  <td data-col="customer_code" class="center"><?= (int)($r['person_id']??0) ?></td>
  <td data-col="customer"><strong><?= finance_h($r['person_name'] ?: 'Consumidor / não informado') ?></strong><?php if(trim((string)$r['person_document'])!==''): ?><span class="card-note"><?= finance_h($r['person_document']) ?></span><?php endif; ?></td>
  <td data-col="default_bank"><?= finance_h($r['default_bank_name'] ?: '—') ?></td>
  <td data-col="gross" class="num">R$ <?= finance_money_br($r['gross_amount']) ?></td>
  <td data-col="fee_pct" class="num"><?= finance_h(rtrim(rtrim(number_format((float)$r['fee_percent'],4,',','.'),'0'),',')) ?>%</td>
  <td data-col="fee" class="num">R$ <?= finance_money_br($r['fee_amount']) ?></td>
  <td data-col="net" class="num"><strong>R$ <?= finance_money_br($r['net_amount']) ?></strong></td>
  <td data-col="installment" class="center"><?= (int)$r['installment_no'] ?></td>
  <td data-col="total_installments" class="center"><?= (int)$r['total_installments'] ?></td>
  <td data-col="paid" class="nowrap"><?= $r['payment_date'] ? finance_date_br($r['payment_date']) : '—' ?></td>
  <td data-col="received_bank"><?= finance_h($r['received_bank_name'] ?: '—') ?></td>
  <td data-col="parcel" class="center"><?= (int)$r['installment_no'] ?>/<?= (int)$r['total_installments'] ?></td>
  <td data-col="status"><span class="card-status <?= finance_h($r['status']) ?>"><?= finance_h($r['status']) ?></span></td>
  <td data-col="chart"><?= finance_h(trim((string)$r['chart_code'].' '.(string)$r['chart_name'])) ?></td>
  <td data-col="type"><strong><?= finance_h($r['payment_name'] ?: 'Cartão') ?></strong><?php if(trim((string)$r['acquirer_name'])!==''): ?><span class="card-note"><?= finance_h($r['acquirer_name']) ?></span><?php endif; ?></td>
  <td data-col="nsu"><?= finance_h($r['nsu'] ?: '—') ?><?php if(trim((string)$r['authorization_code'])!==''): ?><span class="card-note">Aut.: <?= finance_h($r['authorization_code']) ?></span><?php endif; ?></td>
</tr><?php endforeach; ?>
      </tbody></table></div>
<?php if($canSettle): ?><div class="card-batch">
  <div><label>Caixa/Banco do recebimento *</label><select name="bank_account_id" required><option value="">Selecione</option><?php foreach($banks as $b): ?><option value="<?= (int)$b['id'] ?>"><?= finance_h($b['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Data do recebimento *</label><input type="date" name="payment_date" value="<?= date('Y-m-d') ?>" required></div>
  <div class="notes"><label>Observação da baixa</label><input name="settlement_notes" maxlength="500" placeholder="Opcional: lote, extrato, observação da adquirente..."></div>
  <div class="actions"><button class="btn btn-primary" id="cardSettleButton" disabled>Baixar selecionadas</button></div>
</div><?php endif; ?>
  </form>
</div>
<script>
(()=>{
 const grid=document.getElementById('cardGrid'), panel=document.getElementById('cardColumnsPanel'), storageKey='nexa_card_grid_v115272_<?= (int)$cid ?>'; if(!grid||!panel)return;
 const defs=<?= json_encode($columns,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
 const defaultWidths={select:82,due:108,sale:108,order:90,customer_code:95,customer:220,default_bank:145,gross:115,fee_pct:82,fee:105,net:120,installment:92,total_installments:100,paid:112,received_bank:145,parcel:78,status:108,chart:220,type:170,nsu:155};
 let pref={hidden:[],order:Object.keys(defs),widths:{...defaultWidths}}; try{const saved=JSON.parse(localStorage.getItem(storageKey)||'{}');pref={...pref,...saved,widths:{...defaultWidths,...(saved.widths||{})}}}catch(e){}
 const cols=()=>[...grid.querySelectorAll('thead tr:first-child th[data-col]')].map(x=>x.dataset.col);
 function save(){try{localStorage.setItem(storageKey,JSON.stringify(pref))}catch(e){}}
 function moveCol(col,beforeCol=null){ grid.querySelectorAll('tr').forEach(row=>{const cell=row.querySelector(`[data-col="${col}"]`); if(!cell)return; const before=beforeCol?row.querySelector(`[data-col="${beforeCol}"]`):null; if(before)row.insertBefore(cell,before); else row.appendChild(cell);}); }
 function apply(){ const valid=pref.order.filter(c=>defs[c]); Object.keys(defs).forEach(c=>{if(!valid.includes(c))valid.push(c)}); pref.order=valid; valid.forEach(c=>moveCol(c)); grid.querySelectorAll('[data-col]').forEach(el=>el.classList.toggle('hidden-col',pref.hidden.includes(el.dataset.col))); Object.entries(pref.widths||{}).forEach(([c,w])=>grid.querySelectorAll(`[data-col="${c}"]`).forEach(el=>{el.style.width=w+'px';el.style.minWidth=w+'px'})); }
 function buildPanel(){panel.innerHTML='';Object.entries(defs).forEach(([c,l])=>{const lab=document.createElement('label'),cb=document.createElement('input');cb.type='checkbox';cb.checked=!pref.hidden.includes(c);cb.disabled=c==='select';cb.addEventListener('change',()=>{pref.hidden=cb.checked?pref.hidden.filter(x=>x!==c):[...new Set([...pref.hidden,c])];apply();save()});lab.append(cb,document.createTextNode(l));panel.appendChild(lab)});const note=document.createElement('div');note.className='card-columns-note';note.textContent='Arraste as colunas pelo marcador e puxe a divisória do cabeçalho para ajustar a largura.';panel.appendChild(note)}
 let dragging=''; grid.querySelectorAll('thead tr:first-child th[data-col]').forEach(th=>{ if(th.dataset.col!=='select'){th.draggable=true;th.addEventListener('dragstart',e=>{dragging=th.dataset.col;e.dataTransfer.effectAllowed='move'});th.addEventListener('dragover',e=>{if(dragging&&dragging!==th.dataset.col)e.preventDefault()});th.addEventListener('drop',e=>{e.preventDefault();const target=th.dataset.col;if(!dragging||dragging===target)return;const order=cols().filter(c=>c!==dragging),idx=order.indexOf(target);order.splice(idx,0,dragging);pref.order=order;apply();save();dragging=''})}
   const rz=th.querySelector('.resizer'); if(rz){rz.addEventListener('pointerdown',e=>{e.preventDefault();const c=th.dataset.col,start=e.clientX,startW=th.getBoundingClientRect().width;rz.setPointerCapture(e.pointerId);const move=ev=>{const w=Math.max(55,Math.round(startW+ev.clientX-start));pref.widths[c]=w;grid.querySelectorAll(`[data-col="${c}"]`).forEach(x=>{x.style.width=w+'px';x.style.minWidth=w+'px'})};const up=ev=>{rz.removeEventListener('pointermove',move);rz.removeEventListener('pointerup',up);save()};rz.addEventListener('pointermove',move);rz.addEventListener('pointerup',up)})}}
 );
 apply();buildPanel();
 const checks=[...document.querySelectorAll('.card-row-check')],count=document.getElementById('cardSelectedCount'),value=document.getElementById('cardSelectedValue'),btn=document.getElementById('cardSettleButton');
 const all=document.getElementById('cardCheckAll'); const upd=()=>{const on=checks.filter(c=>c.checked),sum=on.reduce((a,c)=>a+Number(c.dataset.net||0),0);count.textContent=on.length;value.textContent=sum.toLocaleString('pt-BR',{style:'currency',currency:'BRL'});if(btn)btn.disabled=on.length===0;if(all){all.checked=checks.length>0&&on.length===checks.length;all.indeterminate=on.length>0&&on.length<checks.length}};checks.forEach(c=>c.addEventListener('change',upd));all?.addEventListener('change',()=>{checks.forEach(c=>c.checked=all.checked);upd()});upd();
 document.getElementById('cardSettlementForm')?.addEventListener('submit',e=>{if(!checks.some(c=>c.checked)){e.preventDefault();alert('Selecione ao menos uma parcela para baixar.')}});
 const filterForm=document.getElementById('cardColumnFilterForm');
 const filterMenus=[...grid.querySelectorAll('[data-grid-filter-menu]')];
 filterMenus.forEach(menu=>menu.addEventListener('toggle',()=>{const th=menu.closest('th');if(th)th.classList.toggle('filter-open',menu.open);if(menu.open){filterMenus.forEach(other=>{if(other!==menu)other.removeAttribute('open')});setTimeout(()=>menu.querySelector('.grid-filter-input')?.focus(),0)}}));
 document.querySelectorAll('[data-clear-filter]').forEach(btn=>btn.addEventListener('click',()=>{if(!filterForm)return;const field=filterForm.elements.namedItem(btn.dataset.clearFilter||'');if(field){field.value='';filterForm.submit()}}));
 filterForm?.querySelectorAll('.grid-filter-input').forEach(inp=>inp.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();filterForm.submit()}}));
 document.addEventListener('click',e=>{filterMenus.forEach(menu=>{if(menu.open&&!menu.contains(e.target))menu.removeAttribute('open')})});
})();
</script>
<?php endif; ?>
<?php finance_admin_end(); ?>
