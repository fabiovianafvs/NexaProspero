<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/itau_bolecode.php';
require_once __DIR__ . '/../config/itau_cash_query.php';
require_once __DIR__ . '/../config/sicoob_boleto_register.php';
require_once __DIR__ . '/../config/sicoob_bank_status.php';
require_once __DIR__ . '/../config/sicoob_direct_liquidation.php';
require_once __DIR__ . '/../config/sicoob_batch_v7.php';
$billingCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
if (!$billingCrossContext) billing_ensure_schema($pdo);
elseif (!finance_table_exists($pdo, 'finance_boleto_charges')) access_render_denied('V11.10: a estrutura de Cobranças/Boletos não está disponível. Atualize o banco antes de consultar pelo F11.');
$cid=function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
// Sicoob: o registro manual segue condicionado à liberação explícita da empresa.
// O contexto F11 permanece somente com as ações Itaú já homologadas.
$billingSicoobPilot = !$billingCrossContext && sicoob_v15_company_enabled($cid);
$billingSicoobCsrf = '';
if (session_status() === PHP_SESSION_ACTIVE) {
  if (empty($_SESSION['nexa_sicoob_token'])) $_SESSION['nexa_sicoob_token'] = bin2hex(random_bytes(20));
  $billingSicoobCsrf = (string)$_SESSION['nexa_sicoob_token'];
}

// V11.10: a simulação no F11 depende somente das colunas de auditoria já existentes
// no módulo de boletos. Não executamos DDL aqui; se a estrutura estiver incompleta,
// a tela permanece em consulta e informa a pendência ao tentar simular.
$billingSimulationColumns = ['simulation_request_payload','simulation_response_payload','simulation_http_status','simulated_at'];
$billingSimulationSchemaReady = true;
$billingRegistrationColumns = ['api_attempt_count','api_last_action','api_last_attempt_at','api_last_attempt_by_user_id','api_request_hash'];
$billingRegistrationSchemaReady = true;
$billingTrackingSchemaReady = true;
if ($billingCrossContext) {
  foreach ($billingSimulationColumns as $simulationColumn) {
    if (!finance_column_exists($pdo, 'finance_boleto_charges', $simulationColumn)) {
      $billingSimulationSchemaReady = false;
      break;
    }
  }
  foreach ($billingRegistrationColumns as $registrationColumn) {
    if (!finance_column_exists($pdo, 'finance_boleto_charges', $registrationColumn)) {
      $billingRegistrationSchemaReady = false;
      break;
    }
  }
  $billingTrackingSchemaReady = function_exists('itau_cash_query_schema_missing_columns') && !itau_cash_query_schema_missing_columns($pdo);
}
$billingBankCsrf = ($billingCrossContext && function_exists('multi_company_context_csrf_token')) ? multi_company_context_csrf_token() : '';
$billingCrossCancelEnabled = $billingCrossContext && function_exists('finance_multi_company_billing_cancel_enabled') && finance_multi_company_billing_cancel_enabled($pdo);
$billingCrossSendEnabled = $billingCrossContext && function_exists('finance_multi_company_billing_send_enabled') && finance_multi_company_billing_send_enabled($pdo);
$billingCancellationSchemaReady = !$billingCrossContext || (finance_column_exists($pdo, 'finance_boleto_charges', 'canceled_at') && finance_column_exists($pdo, 'finance_boleto_charges', 'cancellation_reason') && finance_column_exists($pdo, 'finance_boleto_charges', 'cancellation_source'));
// V7 - Registro em lote Sicoob: duas etapas com confirmacao unica.
// Somente itens selecionados da empresa principal, enviados em sequencia pelo browser.
// NUNCA retentar automaticamente status ENVIANDO_API/VERIFICAR_API.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && in_array((string)($_POST['action']??''), ['sicoob_batch_start_v7','sicoob_batch_item_v7'], true)) {
  $sendJson=static function(array $payload,int $code=200): never {
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, private');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
  };
  try {
    if($billingCrossContext) throw new RuntimeException('Registro Sicoob em lote nao disponivel no F11. Entre diretamente na empresa.');
    if(!$billingSicoobPilot) throw new RuntimeException('Esta empresa nao esta autorizada para registro Sicoob.');
    if(!function_exists('finance_effective_permission_allowed') || !finance_effective_permission_allowed($pdo,'financeiro_boletos')) {
      throw new RuntimeException('Perfil sem permissao para registrar boletos.');
    }
    $csrf=(string)($_POST['csrf_sicoob']??'');
    if($billingSicoobCsrf==='' || !hash_equals($billingSicoobCsrf,$csrf)) throw new RuntimeException('Sessao expirada. Atualize a lista de boletos.');
    $operator=(int)(function_exists('auth_user_id') ? (auth_user_id()??0) : ($_SESSION['user_id']??0));
    $action=(string)$_POST['action'];
    if($action==='sicoob_batch_start_v7') {
      if((string)($_POST['confirmar_registro_real']??'')!=='REGISTRAR') throw new RuntimeException('Digite REGISTRAR para autorizar o lote.');
      $ids=sicoob_v7_batch_normalize_ids($_POST['charge_ids']??[],200);
      $placeholders=implode(',',array_fill(0,count($ids),'?'));
      $q=$pdo->prepare("SELECT id,bank_account_id,provider,integration_mode,status,nosso_numero,registered_at,api_http_status,request_payload,response_payload,amount FROM finance_boleto_charges WHERE company_id=? AND id IN ($placeholders)");
      $q->execute(array_merge([$cid],$ids));
      $byId=[];foreach(($q->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r)$byId[(int)$r['id']]=$r;
      $total=0.0;
      foreach($ids as $id) {
        $r=$byId[$id]??null;
        if(!$r||!sicoob_v7_batch_eligible($r)) throw new RuntimeException('Boleto #'.$id.' nao esta aguardando primeiro registro Sicoob. Atualize a lista.');
        $cfg=billing_bank_config($pdo,$cid,(int)$r['bank_account_id']);
        if(!$cfg || empty($cfg['enabled']) || strtoupper((string)($cfg['provider']??''))!=='SICOOB' || strtoupper((string)($cfg['integration_mode']??''))!=='API') {
          throw new RuntimeException('Boleto #'.$id.': conta Sicoob/API desabilitada ou divergente.');
        }
        $total+=(float)$r['amount'];
      }
      $token=bin2hex(random_bytes(24));
      $_SESSION['nexa_sicoob_batch_v7']=[
        'token'=>$token,'company_id'=>$cid,'user_id'=>$operator,'created_at'=>time(),
        'pending'=>array_fill_keys($ids,true), 'started'=>[]
      ];
      $sendJson(['ok'=>true,'token'=>$token,'ids'=>$ids,'count'=>count($ids),'total'=>round($total,2)]);
    }
    $batch=(array)($_SESSION['nexa_sicoob_batch_v7']??[]);
    if(!sicoob_v7_batch_check_session($batch,$cid,$operator,(string)($_POST['batch_token']??''))) {
      throw new RuntimeException('Lote expirado ou contexto alterado. Nao reenvie sem conferir o banco.');
    }
    $id=(int)($_POST['charge_id']??0);
    if($id<=0 || empty($batch['pending'][$id])) {
      throw new RuntimeException('Este boleto nao pertence ao lote pendente ou ja teve uma tentativa. Nenhum novo envio realizado.');
    }
    // Consumo irrevogavel do item ANTES da chamada externa: dupla submissao da aba
    // nao consegue gerar um segundo POST. A trava SQL do motor e a segunda barreira.
    unset($_SESSION['nexa_sicoob_batch_v7']['pending'][$id]);
    $_SESSION['nexa_sicoob_batch_v7']['started'][$id]=time();
    if(session_status()===PHP_SESSION_ACTIVE)session_write_close();
    try {
      $r=sicoob_v15_register($pdo,$cid,$id);
      $sendJson(['ok'=>true,'id'=>$id,'status'=>(string)($r['status']??'REGISTRADO'),'nosso_numero'=>(string)($r['nosso_numero']??'')]);
    }catch(Throwable $bankError){
      error_log('[Nexa][Sicoob V7 lote] Empresa '.$cid.' boleto '.$id.': '.$bankError->getMessage());
      $q=$pdo->prepare('SELECT status,error_message FROM finance_boleto_charges WHERE company_id=? AND id=? LIMIT 1');
      $q->execute([$cid,$id]);$state=$q->fetch(PDO::FETCH_ASSOC)?:[];
      $sendJson(['ok'=>false,'id'=>$id,'status'=>(string)($state['status']??'INDEFINIDO'),
        'message'=>$bankError->getMessage().' '.(string)($state['error_message']??'')]);
    }
  } catch(Throwable $e) {
    $sendJson(['ok'=>false,'message'=>$e->getMessage()],400);
  }
}

$msg='';$err='';$technical='';$sicoobDirectPreview=null;

try{
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $action=(string)($_POST['action']??'');$chargeId=(int)($_POST['charge_id']??0);
    if($chargeId<=0)throw new RuntimeException('Cobrança inválida.');

    // V11.21 - defesa em profundidade. O F11 reutiliza o mesmo cancelamento LOCAL
    // pré-registro da empresa principal. Nenhuma tentativa de baixa/cancelamento bancário
    // de um boleto já enviado ao Itaú é permitida por esta ação.
    if($billingCrossContext && !in_array($action,['simulate_itau','register_itau','reprocess_itau','consult_itau','cancel_local'],true)){
      throw new RuntimeException('V11.21: no contexto F11 somente Simular, Registrar, Reprocessar, Consultar e Cancelar localmente cobranças ainda não registradas estão homologados.');
    }

    if(in_array($action,['register_sicoob','consult_sicoob','preview_sicoob_paid','confirm_sicoob_paid'],true)){
      if ($billingCrossContext) throw new RuntimeException('Registro/consulta Sicoob não liberados pelo F11 nesta etapa.');
      if (!$billingSicoobPilot && $action==='register_sicoob') throw new RuntimeException('Registro de boleto Sicoob não habilitado para esta empresa.');
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_boletos')) {
        throw new RuntimeException('Usuário sem permissão para operações com boletos.');
      }
      $csrf=(string)($_POST['csrf_sicoob']??'');
      if ($billingSicoobCsrf==='' || !hash_equals($billingSicoobCsrf,$csrf)) throw new RuntimeException('Sessão de segurança Sicoob expirada. Atualize a página.');
      $st=$pdo->prepare('SELECT bank_account_id,provider,integration_mode,status FROM finance_boleto_charges WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$cid,$chargeId]);
      $charge=$st->fetch(PDO::FETCH_ASSOC) ?: [];
      if (!$charge || strtoupper((string)$charge['provider'])!=='SICOOB' || strtoupper((string)$charge['integration_mode'])!=='API') {
        throw new RuntimeException('Cobrança Sicoob/API não encontrada para a empresa selecionada.');
      }
      $bankCfg=billing_bank_config($pdo,$cid,(int)$charge['bank_account_id']);
      if (!$bankCfg || strtoupper((string)($bankCfg['provider']??''))!=='SICOOB' || strtoupper((string)($bankCfg['integration_mode']??''))!=='API') {
        throw new RuntimeException('Conta de cobrança Sicoob não configurada para esta empresa.');
      }
      if ($action==='register_sicoob') {
        if ((string)($_POST['confirmar_registro_real']??'')!=='REGISTRAR') throw new RuntimeException('Digite REGISTRAR para confirmar o envio bancário real.');
        if (strtoupper((string)$charge['status'])!=='AGUARDANDO_API') throw new RuntimeException('Cobrança não está aguardando registro. Não repetir envio.');
        $res=sicoob_v15_register($pdo,$cid,$chargeId);
        $msg='Boleto Sicoob #'.$chargeId.' registrado. Nosso Número: '.(string)($res['nosso_numero']??'').'.';
      } else {
        $r=sicoob_boleto_consult_charge($pdo,$cid,(int)$charge['bank_account_id'],$chargeId);
        $bankReading=nexa_sicoob_read_bank_status($r);
        if ($action==='consult_sicoob') {
          $msg='Consulta Sicoob #'.$chargeId.' (HTTP '.(int)$r['status'].'): '.$bankReading['detail'].' Nenhum título financeiro foi alterado.';
        } else {
          if (!$billingSicoobPilot) throw new RuntimeException('Baixa assistida Sicoob não habilitada para esta empresa.');
          if (!finance_effective_permission_allowed($pdo,'financeiro_receber') || !finance_effective_permission_allowed($pdo,'financeiro_baixar_receber')) {
            throw new RuntimeException('Usuário sem permissão para baixa de Contas a Receber.');
          }
          foreach(['finance_receivables','finance_settlements','finance_movements','finance_settlement_payments'] as $requiredTable) {
            if (!finance_table_exists($pdo,$requiredTable)) throw new RuntimeException('Atualize a estrutura financeira antes da baixa: '.$requiredTable.'.');
          }
          if ($action==='preview_sicoob_paid') {
            $sicoobDirectPreview=sicoob_direct_validate_context($pdo,$cid,(int)$charge['bank_account_id'],$chargeId,$r);
            if ($sicoobDirectPreview['status']==='APTO') {
              $msg='Sicoob confirmou LIQUIDADO: cobrança #'.$chargeId.' · Nosso Número '.(string)$charge['nosso_numero'].' · R$ '.finance_money_br($sicoobDirectPreview['amount']).'. Confira agora as datas e o crédito líquido do extrato para autorizar a baixa.';
            } else {
              $msg='Cobrança #'.$chargeId.' já baixada ou paga. Nenhum lançamento financeiro foi criado.';
              $sicoobDirectPreview=null;
            }
          } else {
            if ((string)($_POST['confirm_sicoob_payment']??'')!=='BAIXAR' || (string)($_POST['confirmed_bank_credit']??'')!=='1') {
              throw new RuntimeException('Confirme o crédito no extrato e digite BAIXAR para realizar a baixa assistida.');
            }
            $result=sicoob_direct_confirm_settlement($pdo,$cid,(int)$charge['bank_account_id'],$chargeId,$r,
              (string)($_POST['payment_date']??''),(string)($_POST['credit_date']??''),(string)($_POST['credited_amount']??''));
            $msg=($result['status']==='BAIXADO')
              ? 'Baixa Sicoob concluída: cobrança #'.$chargeId.' e Conta a Receber quitadas, R$ '.finance_money_br($result['amount']).'. Movimento bancário #'.(int)$result['movement_id'].'.'
              : 'Esta cobrança já estava paga/baixada. Nenhuma nova baixa executada.';
          }
        }
      }
    }elseif($action==='simulate_itau'){
      if($billingCrossContext){
        if(!$billingSimulationSchemaReady){
          throw new RuntimeException('V11.10: estrutura de auditoria da Simulação Itaú incompleta. Atualize o banco antes de simular pelo F11.');
        }
        $simulationCharge=itau_bolecode_charge_context($pdo,$cid,$chargeId);
        $simulationStatus=strtoupper(trim((string)($simulationCharge['status']??'')));
        if(!in_array($simulationStatus,['AGUARDANDO_API','ERRO'],true)){
          throw new RuntimeException('V11.10: a Simulação Itaú pelo F11 só é permitida para cobranças AGUARDANDO_API ou ERRO. Status atual: '.($simulationStatus?:'SEM STATUS').'.');
        }
      }
      $r=itau_bolecode_simulate_charge($pdo,$cid,$chargeId,$billingCrossContext);
      $msg='Simulação Itaú concluída com sucesso. Nenhum boleto foi efetivado e nenhum Nosso Número foi reservado. Nosso Número previsto: '.(string)$r['preview_nosso_numero'].'.';
      $technical=(string)($r['response']['body']??'');
    }elseif($action==='register_itau' || $action==='reprocess_itau'){
      if($billingCrossContext){
        if(!$billingRegistrationSchemaReady){
          throw new RuntimeException('V11.11: estrutura de auditoria do Registro/Reprocessamento Itaú ainda não foi aplicada. Use o Atualizador do Banco antes de continuar.');
        }
        $csrf=(string)($_POST['csrf']??'');
        if(!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)){
          throw new RuntimeException('V11.11: sessão de segurança expirada. Atualize a página antes de registrar/reprocessar a cobrança.');
        }
        $operation=$action==='register_itau'?'register':'reprocess';
        $r=itau_bolecode_register_charge_multiempresa_protected($pdo,$cid,$chargeId,$operation,(int)(auth_user_id()??0));
        if(!empty($r['processing'])){
          $msg='O Itaú aceitou a solicitação e informou processamento assíncrono (HTTP 202). Não reenvie esta cobrança; o status ficou PROCESSANDO_API para conferência posterior.';
        }else{
          $msg=($operation==='register'?'Boleto + Pix registrado':'Boleto + Pix reprocessado').' no Itaú com sucesso.';
        }
        $technical=(string)($r['response']['body']??'');
      }else{
        // Fluxo legado preservado integralmente fora do F11.
        $r=itau_bolecode_register_charge($pdo,$cid,$chargeId);
        if(!empty($r['processing'])) $msg='O Itaú aceitou a solicitação e informou processamento assíncrono (HTTP 202). Não reenvie esta cobrança; acompanhe pela conciliação.';
        else $msg='Boleto + Pix registrado no Itaú com sucesso.';
        $technical=(string)($r['response']['body']??'');
      }
    }elseif($action==='consult_itau'){
      if(!$billingCrossContext) throw new RuntimeException('V11.12: a consulta protegida de acompanhamento foi habilitada somente no contexto F11 nesta etapa.');
      if(!$billingTrackingSchemaReady) throw new RuntimeException('V11.12: estrutura de acompanhamento Itaú ainda não foi aplicada. Use o Atualizador do Banco antes de consultar.');
      $csrf=(string)($_POST['csrf']??'');
      if(!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)){
        throw new RuntimeException('V11.12: sessão de segurança expirada. Atualize a página antes de consultar o Itaú.');
      }
      $r=itau_cash_query_charge_protected($pdo,$cid,$chargeId,(int)(auth_user_id()??0));
      $msg=(string)($r['message']??'Consulta Itaú concluída.');
      if(!empty($r['found'])) $msg='Consulta Itaú concluída: '.$msg;
      else $msg='Consulta Itaú concluída sem confirmação de registro: '.$msg;
      $technical=(string)($r['response']['body']??'');
    }elseif($action==='cancel_local'){
      if($billingCrossContext){
        if(!$billingCrossCancelEnabled) throw new RuntimeException('V11.21: seu perfil não possui permissão para cancelar esta cobrança na empresa selecionada pelo F11.');
        if(!$billingCancellationSchemaReady) throw new RuntimeException('V11.21: estrutura de auditoria de cancelamento incompleta. Atualize o banco antes de cancelar pelo F11.');
        $csrf=(string)($_POST['csrf']??'');
        if(!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) throw new RuntimeException('V11.21: sessão de segurança expirada. Atualize a página antes de cancelar a cobrança.');
      }
      billing_cancel_unregistered_charge($pdo,$cid,$chargeId,'Cobrança #'.$chargeId.' cancelada manualmente antes do registro bancário.','COBRANCAS_BOLETOS',true,$billingCrossContext);
      $msg='Cobrança cancelada. Como ainda não havia registro bancário, nenhuma chamada de cancelamento ao Itaú foi necessária; a Conta a Receber vinculada também foi cancelada.';
    }
  }
}catch(Throwable $e){$err=$e->getMessage();}

$today=date('Y-m-d');
$filterApplied=isset($_GET['filtrar']) || isset($_GET['q']) || isset($_GET['status']) || isset($_GET['bank']) || isset($_GET['due_ini']) || isset($_GET['due_fim']);
$status=trim((string)($_GET['status']??''));
$bank=(int)($_GET['bank']??0);
$q=trim((string)($_GET['q']??''));
// Ao abrir sem filtros, mostrar as cobranças mais recentes de qualquer banco.
// Uma cobrança recém-registrada não pode desaparecer só porque vence em outro dia.
$dueIni=finance_date_or_null($_GET['due_ini']??'');
$dueFim=finance_date_or_null($_GET['due_fim']??'');
$rows=[];
{
  $where=['bc.company_id=?'];$params=[$cid];
  if($status!==''){$where[]='bc.status=?';$params[]=$status;}
  if($bank>0){$where[]='bc.bank_account_id=?';$params[]=$bank;}
  if($q!==''){$where[]='(bc.seu_numero LIKE ? OR bc.nosso_numero LIKE ? OR bc.pix_txid LIKE ? OR c.contract_number LIKE ? OR bc.order_id LIKE ?)';$like=nexa_search_like($q);array_push($params,$like,$like,$like,$like,$like);}
  if($dueIni){$where[]='bc.due_date>=?';$params[]=$dueIni;}
  if($dueFim){$where[]='bc.due_date<=?';$params[]=$dueFim;}
  $orderAndLimit=$filterApplied ? ' ORDER BY bc.due_date ASC,bc.id DESC LIMIT 500' : ' ORDER BY bc.id DESC LIMIT 100';
  $sql='SELECT bc.*,b.name bank_name,c.contract_number,c.billing_person_name,c.contracting_person_name,fp.name AS receivable_person_name,fr.description AS receivable_description FROM finance_boleto_charges bc LEFT JOIN finance_bank_accounts b ON b.company_id=bc.company_id AND b.id=bc.bank_account_id LEFT JOIN contracts c ON c.company_id=bc.company_id AND c.id=bc.contract_id LEFT JOIN finance_receivables fr ON fr.company_id=bc.company_id AND fr.id=bc.receivable_id LEFT JOIN finance_people fp ON fp.company_id=bc.company_id AND fp.id=fr.person_id WHERE '.implode(' AND ',$where).$orderAndLimit;
  $rows=finance_query_all($pdo,$sql,$params);
}
$banks=finance_query_all($pdo,'SELECT id,name FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name',[$cid]);
$statuses=finance_query_all($pdo,'SELECT status,COUNT(*) qtd FROM finance_boleto_charges WHERE company_id=? GROUP BY status ORDER BY status',[$cid]);
finance_admin_start('Financeiro - Boletos','fin_boletos');
$billingHeaderActions=[];
if(!$billingCrossContext)$billingHeaderActions[]=['href'=>'financeiro_caixas_bancos.php','label'=>'Configurar bancos','class'=>'btn'];
if(!$billingCrossContext)$billingHeaderActions[]=['href'=>'pedidos.php','label'=>'Pedidos','class'=>'btn btn-primary'];
finance_page_header('Cobranças e Boletos','Fila bancária de contratos e vendas. API e CNAB são tratados por conta bancária.',$billingHeaderActions);
// V11.51.6: remove as abas gerais apenas desta tela para ampliar a área útil.
finance_flash();
if($msg!==''):?><div class="msg ok"><?=finance_h($msg)?></div><?php endif;if($err!==''):?><div class="msg err"><?=finance_h($err)?></div><?php endif;?>
<?php if(is_array($sicoobDirectPreview) && ($sicoobDirectPreview['status']??'')==='APTO'):
  $pCharge=(array)$sicoobDirectPreview['charge'];
  $pAmount=(float)$sicoobDirectPreview['amount']; ?>
  <section class="finance-card" style="margin-bottom:15px;border:2px solid #f59e0b;background:#fffdf7" aria-label="Confirmar baixa Sicoob liquidado">
    <h3 style="margin:0 0 8px;color:#92400e">Sicoob LIQUIDADO — conferir baixa assistida</h3>
    <p style="margin:4px 0 12px">Cobrança <strong>#<?=(int)$pCharge['id']?></strong> · Nosso Número <strong><?=finance_h((string)$pCharge['nosso_numero'])?></strong> ·
      Conta a Receber <strong>#<?=(int)$pCharge['receivable_id']?></strong> · Valor nominal <strong>R$ <?=finance_money_br($pAmount)?></strong>.</p>
    <p style="color:#92400e">A API confirma o status LIQUIDADO, mas não comprova sozinha a data nem o valor efetivamente creditado na conta. Consulte o extrato bancário antes de autorizar.</p>
    <form method="post" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(165px,1fr));gap:12px;align-items:end" onsubmit="return confirm('CONFIRMAR BAIXA REAL do boleto Sicoob e lançar movimento financeiro no Nexa? Verifique que o crédito consta no extrato e que não houve outra baixa.');">
      <input type="hidden" name="action" value="confirm_sicoob_paid"><input type="hidden" name="charge_id" value="<?=(int)$pCharge['id']?>"><input type="hidden" name="csrf_sicoob" value="<?=finance_h($billingSicoobCsrf)?>">
      <label>Data do pagamento (comprovante)<input type="date" name="payment_date" required max="<?=date('Y-m-d')?>"></label>
      <label>Data do crédito na conta (extrato)<input type="date" name="credit_date" required max="<?=date('Y-m-d')?>"></label>
      <label>Crédito líquido no extrato, R$<input type="number" name="credited_amount" inputmode="decimal" min="0.01" step="0.01" required placeholder="Ex.: <?=number_format($pAmount,2,'.','')?>"></label>
      <label>Digite BAIXAR<input type="text" name="confirm_sicoob_payment" required pattern="BAIXAR" maxlength="6" autocomplete="off" placeholder="BAIXAR"></label>
      <label style="grid-column:1/-1;display:flex;gap:10px;align-items:center"><input type="checkbox" name="confirmed_bank_credit" value="1" required style="width:18px;height:18px;min-width:18px"> Confirmei no extrato que esse valor entrou na conta Sicoob e que ainda não registrei outra baixa.</label>
      <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap"><button type="submit" class="btn btn-primary">Confirmar baixa financeira</button><a class="btn" href="financeiro_boletos.php">Cancelar sem baixar</a></div>
    </form>
  </section>
<?php endif;?>
<div class="finance-grid finance-grid-4" style="margin-bottom:14px">
<?php foreach($statuses as $s):?><div class="finance-kpi"><div class="label"><?=finance_h($s['status'])?></div><div class="value"><?=(int)$s['qtd']?></div></div><?php endforeach;?>
</div>
<style>
.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.boleto-filter-grid{display:grid;grid-template-columns:minmax(260px,2.2fr) minmax(150px,.9fr) minmax(150px,.9fr) minmax(160px,1fr) minmax(160px,1fr) auto;gap:8px 10px;align-items:end}.boleto-filter-grid label{margin-bottom:3px}.boleto-filter-grid input,.boleto-filter-grid select{min-height:39px}
.boleto-filter-actions{display:flex;gap:8px;align-items:center;white-space:nowrap}
.boleto-filter-actions .btn{min-height:39px;display:inline-flex;align-items:center;justify-content:center}
@media(max-width:1350px){.boleto-filter-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.boleto-filter-actions{justify-content:flex-end}}
@media(max-width:820px){.boleto-filter-grid{grid-template-columns:1fr}.boleto-filter-actions{justify-content:stretch}.boleto-filter-actions .btn{flex:1}}
</style>
<div class="finance-card finance-filter-card">
  <form method="get" class="boleto-filter-grid">
    <input type="hidden" name="filtrar" value="1">
    <div><label>Pesquisar</label><input name="q" value="<?=finance_h($q)?>" placeholder="Contrato, pedido, nosso número, seu número ou TXID"></div>
    <div><label>Vencimento inicial</label><input type="date" name="due_ini" value="<?=finance_h((string)$dueIni)?>"></div>
    <div><label>Vencimento final</label><input type="date" name="due_fim" value="<?=finance_h((string)$dueFim)?>"></div>
    <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['AGUARDANDO_API','ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API','VERIFICAR_PAGAMENTO','AGUARDANDO_REMESSA','REGISTRADO','PAGO','ERRO','CANCELADO'] as $st):?><option value="<?=$st?>" <?=$status===$st?'selected':''?>><?=$st?></option><?php endforeach;?></select></div>
    <div><label>Banco</label><select name="bank"><option value="">Todos</option><?php foreach($banks as $b):?><option value="<?=(int)$b['id']?>" <?=$bank===(int)$b['id']?'selected':''?>><?=finance_h($b['name'])?></option><?php endforeach;?></select></div>
    <div class="boleto-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="financeiro_boletos.php">Limpar</a></div>
  </form>
</div>
<?php if(!$billingCrossContext && $billingSicoobPilot && $billingSicoobCsrf!=='' && finance_effective_permission_allowed($pdo,'financeiro_boletos')): ?>
<div class="finance-card" id="sicoob-batch-v7" style="margin-bottom:14px">
  <div class="finance-card-head"><h2 class="finance-subtitle">Registro em lote no Sicoob</h2></div>
  <p class="finance-muted">Marque os boletos <strong>SICOOB / API / AGUARDANDO_API</strong> desta listagem. O registro e real; exige digitar <strong>REGISTRAR</strong> apenas uma vez por lote. Limite de 200 por operacao. Outros bancos e cobrancas com tentativa anterior nao podem ser selecionados.</p>
  <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap">
    <button type="button" class="btn" id="sicoob-batch-select-all">Marcar todos os disponiveis</button>
    <button type="button" class="btn" id="sicoob-batch-clear">Desmarcar</button>
    <button type="button" class="btn btn-primary" id="sicoob-batch-submit" disabled>Registrar selecionados no Sicoob</button>
    <strong id="sicoob-batch-count">0 selecionados</strong>
  </div>
  <div id="sicoob-batch-progress" role="status" aria-live="polite" style="margin-top:12px;display:none;border:1px solid #dbeafe;border-radius:9px;padding:12px;background:#f8fbff">
    <div id="sicoob-batch-status"></div><progress id="sicoob-batch-bar" max="1" value="0" style="width:100%;height:15px;margin:8px 0"></progress>
    <div id="sicoob-batch-result" style="font-size:12px;white-space:pre-wrap;max-height:210px;overflow:auto"></div>
    <a class="btn" href="financeiro_boletos.php" id="sicoob-batch-refresh" style="display:none;margin-top:9px">Atualizar lista</a>
  </div>
</div>
<?php endif; ?>
<div class="finance-card"><div class="finance-card-head"><h2 class="finance-subtitle">Fila de cobranças</h2><span class="finance-muted"><?=count($rows)?> registro(s)<?= !$filterApplied ? ' · últimas 100 cobranças' : ' · limite de 500 por filtro' ?></span></div><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Sicoob</th><th>ID</th><th>Contrato / cliente</th><th>Pedido</th><th>Banco</th><th>Vencimento</th><th>Valor</th><th>Status</th><th>Identificação</th><th>Ações</th></tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="10" class="finance-empty"><?= $filterApplied ? 'Nenhuma cobrança encontrada para os filtros informados.' : 'Ainda não há cobranças cadastradas nesta empresa.' ?><div class="finance-muted" style="margin-top:6px"><?= $filterApplied ? 'A consulta está limitada a 500 registros por vez.' : 'Utilize os filtros acima para localizar cobranças anteriores.' ?></div></td></tr><?php endif;?>
<?php foreach($rows as $r):
  $rStatus=strtoupper((string)$r['status']);
  $isItau=strtoupper((string)($r['provider']??''))==='ITAU'&&strtoupper((string)($r['integration_mode']??''))==='API';
  $isSicoob=strtoupper((string)($r['provider']??''))==='SICOOB'&&strtoupper((string)($r['integration_mode']??''))==='API';
  $boletoReady=trim((string)($r['linha_digitavel']??''))!==''&&trim((string)($r['barcode']??''))!=='';
  $simulationHttp=(int)($r['simulation_http_status']??0);
  $simulationAt=trim((string)($r['simulated_at']??''));
  $simulationTs=$simulationAt!==''?strtotime($simulationAt):false;
  $simulationFresh=$simulationHttp>=200&&$simulationHttp<300&&$simulationTs!==false&&$simulationTs>=(time()-1800)&&trim((string)($r['simulation_request_payload']??''))!=='';
?>
<tr>
<td data-label="Selecionar Sicoob"><?php if(!$billingCrossContext&&$billingSicoobPilot&&$isSicoob&&sicoob_v7_batch_eligible($r)): ?><input class="sicoob-batch-cb" type="checkbox" value="<?=(int)$r['id']?>" data-amount="<?=finance_h((string)$r['amount'])?>" aria-label="Selecionar boleto <?=(int)$r['id']?> para registro Sicoob" style="width:18px;height:18px;cursor:pointer"><?php else: ?>—<?php endif; ?></td>
<td data-label="ID">#<?=(int)$r['id']?></td>
<td data-label="Contrato / cliente" class="finance-primary"><?php if((int)$r['contract_id']>0):?><strong>Contrato #<?=finance_h($r['contract_number']??'—')?></strong><div class="finance-muted"><?=finance_h($r['billing_person_name']?:$r['contracting_person_name']?:'')?></div><?php else:?><strong><?=finance_h($r['receivable_person_name']?:('Pedido #'.(int)$r['order_id']))?></strong><div class="finance-muted"><?=finance_h($r['receivable_description']??'Cobrança de pedido')?></div><?php endif;?></td>
<td data-label="Pedido"><?= (int)$r['order_id']>0?'#'.(int)$r['order_id']:'—'?></td>
<td data-label="Banco"><?=finance_h($r['bank_name']??'')?><div class="finance-muted"><?=finance_h(($r['provider']??'').' / '.($r['integration_mode']??''))?></div></td>
<td data-label="Vencimento"><?=date('d/m/Y',strtotime($r['due_date']))?></td>
<td data-label="Valor" class="finance-value">R$ <?=finance_money_br($r['amount'])?></td>
<td data-label="Status"><span class="finance-badge <?=in_array($rStatus,['REGISTRADO','PAGO'],true)?'green':'gray'?>"><?=finance_h($r['status'])?></span><?php if($rStatus==='PAGO'&&!empty($r['payment_date'])):?><div class="finance-muted">Pagamento: <?=date('d/m/Y',strtotime((string)$r['payment_date']))?><?php if(!empty($r['paid_amount'])):?> · R$ <?=finance_money_br($r['paid_amount'])?><?php endif;?></div><?php endif;?><?php if($rStatus==='PAGO'&&!empty($r['credit_date'])&&$r['credit_date']!==$r['payment_date']):?><div class="finance-muted">Crédito: <?=date('d/m/Y',strtotime((string)$r['credit_date']))?></div><?php endif;?><?php if($r['error_message']):?><div class="finance-muted"><?=finance_h($r['error_message'])?></div><?php endif;?><?php if($billingCrossContext&&!empty($r['api_last_check_at'])):?><div class="finance-muted">Última consulta Itaú: <?=date('d/m/Y H:i',strtotime((string)$r['api_last_check_at']))?> · <?=finance_h((string)($r['api_last_check_result']??''))?></div><?php endif;?></td>
<td data-label="Identificação"><div><?=finance_h($r['nosso_numero']?:$r['seu_numero']?:'—')?></div><?php if($r['linha_digitavel']):?><div class="finance-muted"><?=finance_h($r['linha_digitavel'])?></div><?php endif;?><?php if($r['pix_txid']):?><div class="finance-muted">PIX: <?=finance_h($r['pix_txid'])?></div><?php endif;?></td>
<td data-label="Ações"><div class="finance-inline-actions" style="flex-wrap:wrap">
<?php if(!$billingCrossContext): ?>
  <?php if($isSicoob&&$billingSicoobPilot&&$billingSicoobCsrf!==''&&$rStatus==='AGUARDANDO_API'): ?>
    <form method="post" onsubmit="return confirm('REGISTRO BANCÁRIO REAL SICOOB: confira o pagador, o valor e o vencimento. Deseja registrar uma única vez?');">
      <input type="hidden" name="action" value="register_sicoob"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf_sicoob" value="<?=finance_h($billingSicoobCsrf)?>">
      <label style="display:block;font-size:12px">Digite REGISTRAR para confirmar<input name="confirmar_registro_real" required autocomplete="off" pattern="REGISTRAR" placeholder="REGISTRAR" maxlength="9" style="width:130px;max-width:100%"></label>
      <button class="btn btn-primary">Registrar Sicoob (real)</button>
    </form>
  <?php endif; ?>
  <?php if($isSicoob&&$billingSicoobCsrf!==''&&trim((string)($r['nosso_numero']??''))!==''): ?>
    <form method="post" onsubmit="return confirm('Consultar este boleto no Sicoob? A consulta não altera títulos nem executa baixa.');">
      <input type="hidden" name="action" value="consult_sicoob"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf_sicoob" value="<?=finance_h($billingSicoobCsrf)?>">
      <button class="btn">Consultar Sicoob</button>
    </form>
  <?php endif; ?>
  <?php if($isSicoob&&$billingSicoobPilot&&$billingSicoobCsrf!==''&&$rStatus==='REGISTRADO'&&      finance_effective_permission_allowed($pdo,'financeiro_receber')&&finance_effective_permission_allowed($pdo,'financeiro_baixar_receber')): ?>
    <form method="post" onsubmit="return confirm('Consultar se o Sicoob confirmou LIQUIDADO para conferir uma possível baixa? Nenhuma baixa será realizada nesta etapa.');">
      <input type="hidden" name="action" value="preview_sicoob_paid"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf_sicoob" value="<?=finance_h($billingSicoobCsrf)?>">
      <button class="btn btn-primary" type="submit">Conferir baixa Sicoob</button>
    </form>
  <?php endif; ?>
  <?php if($isSicoob&&$boletoReady):?><a class="btn" href="financeiro_boleto_imprimir.php?charge_id=<?=(int)$r['id']?>" target="_blank" rel="noopener">Boleto</a><?php endif; ?>
  <?php if($isItau&&in_array($rStatus,['AGUARDANDO_API','ERRO'],true)):?>
    <form method="post"><input type="hidden" name="action" value="simulate_itau"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><button class="btn" title="Valida o payload no Itaú sem efetivar o BoleCode">Simular Itaú</button></form>
    <form method="post" onsubmit="return confirm('Efetivar este Boleto + Pix no Itaú? Esta ação registra a cobrança e reserva o Nosso Número.')"><input type="hidden" name="action" value="register_itau"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><button class="btn btn-primary"><?=$rStatus==='ERRO'?'Reprocessar Itaú':'Registrar Itaú'?></button></form>
  <?php endif;?>
  <?php if(in_array($rStatus,billing_charge_local_cancel_statuses(),true)&&!billing_charge_has_bank_identity($r)):?><form method="post" onsubmit="return confirm('Cancelar esta cobrança antes do registro bancário? A Conta a Receber vinculada também será cancelada.')"><input type="hidden" name="action" value="cancel_local"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><button class="btn btn-danger">Cancelar</button></form><?php endif;?>
  <?php if($isItau&&($r['nosso_numero']||$r['response_payload']||$r['simulation_response_payload'])):?><a class="btn" href="financeiro_boleto_itau.php?id=<?=(int)$r['id']?>">Dados Itaú</a><?php endif;?>
  <?php if((int)$r['contract_id']>0):?><a class="btn" href="contrato_editar.php?id=<?=(int)$r['contract_id']?>&tab=historico">Contrato</a><?php endif;?>
  <?php if((int)$r['order_id']>0):?><a class="btn" href="pedido.php?id=<?=(int)$r['order_id']?>">Pedido</a><?php endif;?>
  <?php if((int)$r['receivable_id']>0):?><a class="btn" href="financeiro_contas_receber.php?edit=<?=(int)$r['receivable_id']?>&tab=cobranca">Receber</a><?php endif;?>
<?php else: ?>
  <?php if(in_array($rStatus,billing_charge_local_cancel_statuses(),true)&&!billing_charge_has_bank_identity($r)): ?>
    <?php if($billingCrossCancelEnabled&&$billingCancellationSchemaReady): ?>
      <form method="post" onsubmit="return confirm('Cancelar esta cobrança da empresa selecionada antes do registro bancário? A Conta a Receber vinculada também será cancelada.')"><input type="hidden" name="action" value="cancel_local"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf" value="<?=finance_h($billingBankCsrf)?>"><button class="btn btn-danger">Cancelar</button></form>
    <?php elseif(!$billingCancellationSchemaReady): ?><span class="finance-muted" title="Atualize a estrutura de auditoria do cancelamento antes de usar esta ação pelo F11">Cancelamento indisponível</span><?php endif; ?>
  <?php endif; ?>

  <?php if($isItau&&in_array($rStatus,['AGUARDANDO_API','ERRO'],true)&&$billingSimulationSchemaReady): ?>
    <form method="post" onsubmit="return confirm('Executar somente a SIMULAÇÃO desta cobrança no Itaú? Nenhum boleto será efetivado e o Nosso Número não será reservado.')"><input type="hidden" name="action" value="simulate_itau"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><button class="btn" title="V11.10: valida credenciais e payload no Itaú sem efetivar o BoleCode">Simular Itaú</button></form>
  <?php endif; ?>
  <?php if($isItau&&in_array($rStatus,['AGUARDANDO_API','ERRO'],true)&&!$billingSimulationSchemaReady): ?><span class="finance-muted" title="Atualize a estrutura de auditoria da simulação antes de usar esta ação">Simulação indisponível</span><?php endif; ?>

  <?php if($isItau&&in_array($rStatus,['AGUARDANDO_API','ERRO'],true)): ?>
    <?php if(!$billingRegistrationSchemaReady): ?>
      <span class="finance-muted" title="Aplique a migration V11.11 pelo Atualizador do Banco">Registro indisponível · V11.11 pendente</span>
    <?php elseif(!$simulationFresh): ?>
      <span class="finance-muted" title="A V11.11 exige uma simulação 2xx realizada nos últimos 30 minutos antes da operação bancária efetiva">Simule antes de <?=$rStatus==='ERRO'?'reprocessar':'registrar'?></span>
    <?php else: ?>
      <?php $effectiveAction=$rStatus==='ERRO'?'reprocess_itau':'register_itau';$effectiveLabel=$rStatus==='ERRO'?'Reprocessar Itaú':'Registrar Itaú';$effectiveConfirm=$rStatus==='ERRO'?'Reprocessar esta cobrança no Itaú usando o MESMO Nosso Número já reservado? Confirme somente após corrigir o erro e executar nova simulação com sucesso.':'REGISTRAR efetivamente este Boleto + Pix no Itaú? Esta ação reserva o Nosso Número e pode criar a cobrança bancária real.'; ?>
      <form method="post" onsubmit="return confirm(<?=finance_h((string)json_encode($effectiveConfirm,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES))?>)">
        <input type="hidden" name="action" value="<?=finance_h($effectiveAction)?>"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf" value="<?=finance_h($billingBankCsrf)?>"><button class="btn btn-primary"><?=finance_h($effectiveLabel)?></button>
      </form>
    <?php endif; ?>
  <?php endif; ?>

  <?php if($isItau&&in_array($rStatus,['ENVIANDO_API','PROCESSANDO_API','VERIFICAR_API'],true)): ?>
    <?php if($billingTrackingSchemaReady): ?>
      <form method="post" onsubmit="return confirm('Consultar este Nosso Número no Itaú para verificar se o título foi efetivamente registrado? Esta ação NÃO reenvia nem cancela a cobrança.')"><input type="hidden" name="action" value="consult_itau"><input type="hidden" name="charge_id" value="<?=(int)$r['id']?>"><input type="hidden" name="csrf" value="<?=finance_h($billingBankCsrf)?>"><button class="btn" title="V11.12: consulta individual por Beneficiário + Carteira + Nosso Número; nenhum reenvio é realizado">Consultar Itaú</button></form>
    <?php else: ?><span class="finance-muted" title="Aplique a migration V11.12 pelo Atualizador do Banco">Consulta indisponível · V11.12 pendente</span><?php endif; ?>
    <span class="finance-muted" title="O estado atual bloqueia reenvio automático para evitar duplicidade">Reenvio bloqueado</span>
  <?php endif; ?>
  <?php if($boletoReady): ?><?php
    $sendReturn='financeiro_boletos.php';
    $href=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_imprimir.php?charge_id='.(int)$r['id'].'&return_to='.rawurlencode($sendReturn)):'financeiro_boleto_imprimir.php?charge_id='.(int)$r['id'];
    $waHref=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_whatsapp.php?charge_id='.(int)$r['id'].'&return_to='.rawurlencode($sendReturn)):'financeiro_boleto_whatsapp.php?charge_id='.(int)$r['id'];
    $emailHref=function_exists('multi_company_context_url')?multi_company_context_url('email_documento.php?type=boleto&charge_id='.(int)$r['id'].'&return_to='.rawurlencode($sendReturn)):'email_documento.php?type=boleto&charge_id='.(int)$r['id'];
  ?><a class="btn" href="<?=finance_h($href)?>" target="_blank">Boleto</a><?php if($billingCrossSendEnabled): ?><a class="btn" style="background:#25D366;border-color:#1ebe57;color:#fff" href="<?=finance_h($waHref)?>">WhatsApp</a><a class="btn" href="<?=finance_h($emailHref)?>">✉ E-mail</a><?php endif; ?><?php endif; ?>
  <?php if($isItau&&($r['nosso_numero']||$r['response_payload']||$r['simulation_response_payload'])): ?><?php $href=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boleto_itau.php?id='.(int)$r['id']):'financeiro_boleto_itau.php?id='.(int)$r['id']; ?><a class="btn" href="<?=finance_h($href)?>">Dados Itaú</a><?php endif; ?>
  <?php if((int)$r['receivable_id']>0): ?><?php $href=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_contas_receber.php?edit='.(int)$r['receivable_id'].'&tab=cobranca'):'financeiro_contas_receber.php?edit='.(int)$r['receivable_id'].'&tab=cobranca'; ?><a class="btn" href="<?=finance_h($href)?>">Receber</a><?php endif; ?>
  <?php if(!$boletoReady&&!$isItau&&(int)$r['receivable_id']<=0): ?><span class="finance-muted">Somente consulta</span><?php endif; ?>
<?php endif; ?>
</div></td>
</tr>
<?php endforeach;?>
</tbody></table></div></div>
<?php if($technical!==''):?><div class="finance-card"><details open><summary><strong>Retorno técnico desta operação</strong></summary><textarea style="width:100%;min-height:180px;margin-top:10px;font-family:ui-monospace,monospace;font-size:12px" readonly><?=finance_h($technical)?></textarea></details></div><?php endif;?>
<script>
(()=>{
  const host=document.getElementById('sicoob-batch-v7');if(!host)return;
  const checks=()=>Array.from(document.querySelectorAll('.sicoob-batch-cb'));
  const btn= document.getElementById('sicoob-batch-submit');
  const count=document.getElementById('sicoob-batch-count');
  const progress=document.getElementById('sicoob-batch-progress');
  const status=document.getElementById('sicoob-batch-status');
  const bar=document.getElementById('sicoob-batch-bar');
  const result=document.getElementById('sicoob-batch-result');
  const refresh=document.getElementById('sicoob-batch-refresh');
  const csrf=<?=json_encode($billingSicoobCsrf,JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
  let running=false;
  const selected=()=>checks().filter(c=>c.checked);
  const update=()=>{const rows=selected();const sum=rows.reduce((a,c)=>a+Number(c.dataset.amount||0),0);count.textContent=rows.length+' selecionado(s) · R$ '+sum.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});btn.disabled=running||!rows.length;};
  checks().forEach(c=>c.addEventListener('change',update));
  document.getElementById('sicoob-batch-select-all').addEventListener('click',()=>{checks().forEach(c=>c.checked=true);update();});
  document.getElementById('sicoob-batch-clear').addEventListener('click',()=>{checks().forEach(c=>c.checked=false);update();});
  const post=async data=>{
    const body=new FormData();Object.entries(data).forEach(([k,v])=>{if(Array.isArray(v))v.forEach(item=>body.append(k+'[]',String(item)));else body.append(k,String(v));});
    body.append('csrf_sicoob',csrf);
    const response=await fetch('financeiro_boletos.php',{method:'POST',body,credentials:'same-origin',cache:'no-store'});
    let payload;try{payload=await response.json();}catch(e){throw new Error('Retorno inesperado do servidor; confira no Sicoob antes de qualquer novo envio.');}
    if(!response.ok)throw new Error(payload.message||'Falha ao processar o lote.');
    return payload;
  };
  const log=(message)=>{const line=document.createElement('div');line.textContent=message;result.appendChild(line);result.scrollTop=result.scrollHeight;};
  btn.addEventListener('click',async()=>{
    if(running)return;
    const checked=selected();if(!checked.length)return;
    if(checked.length>200){alert('O limite e 200 boletos por lote. Selecione menos boletos.');return;}
    const total=checked.reduce((a,c)=>a+Number(c.dataset.amount||0),0);
    const confirmation=window.prompt('ATENCAO: serao feitos '+checked.length+' registros bancarios REAIS no Sicoob, totalizando R$ '+total.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})+'.\nConfira no banco se nenhum desses titulos ja foi registrado.\n\nDigite REGISTRAR para autorizar TODO O LOTE:','');
    if(confirmation!=='REGISTRAR')return;
    running=true;update();progress.style.display='block';result.textContent='';refresh.style.display='none';
    let done=0,good=0,failed=0;
    const ids=checked.map(c=>c.value);
    try{
      status.textContent='Validando selecao junto ao servidor...';
      const batch=await post({action:'sicoob_batch_start_v7',confirmar_registro_real:'REGISTRAR',charge_ids:ids});
      if(!batch.ok)throw new Error(batch.message||'Nao foi possivel iniciar.');
      bar.max=batch.count;bar.value=0;
      for(const id of batch.ids){
        status.textContent='Registrando boleto #'+id+' · '+done+' de '+batch.count+' processados. Nao feche esta aba.';
        try{
          const r=await post({action:'sicoob_batch_item_v7',batch_token:batch.token,charge_id:id});
          if(r.ok){good++;log('#'+id+': REGISTRADO. Nosso Numero: '+(r.nosso_numero||'—'));}
          else{failed++;log('#'+id+': '+(r.status||'CONFERIR')+' · '+(r.message||'Nao confirmado; verificar no banco.'));}
        }catch(e){failed++;log('#'+id+': RESPOSTA INCONCLUSIVA: '+e.message+' Nao reenviar sem conferir o banco.');}
        done++;bar.value=done;
      }
      status.textContent='Lote concluido. Registrados: '+good+'. Necessitam conferencia: '+failed+'. Total: '+done+'.';
    }catch(e){status.textContent='Lote nao iniciado ou interrompido: '+e.message;}
    finally{running=false;refresh.style.display='inline-flex';update();}
  });
  refresh.href=window.location.pathname+window.location.search;
  update();
})();
</script>

<?php finance_admin_end(); ?>
