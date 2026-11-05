<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_subscription_bridge.php';
require_once __DIR__ . '/../config/saas_crm_lead.php';

if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (empty($_SESSION['saas_sub_bridge_csrf'])) $_SESSION['saas_sub_bridge_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['saas_sub_bridge_csrf'];

function sb_h(mixed $v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function sb_date(mixed $v): string { $s=trim((string)$v);if($s==='')return '—';try{return (new DateTimeImmutable($s))->format('d/m/Y');}catch(Throwable $e){return '—';} }
function sb_date_input(mixed $v): string { $s=trim((string)$v);if($s==='')return '';try{return (new DateTimeImmutable($s))->format('Y-m-d');}catch(Throwable $e){return '';} }
function sb_money(mixed $v): string { return 'R$ '.number_format((float)$v,2,',','.'); }
function sb_csrf(string $token): void { if(!hash_equals((string)($_SESSION['saas_sub_bridge_csrf']??''),$token)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.'); }

$ready=saas_sub_bridge_schema_ready($pdo);$billingPolicyReady=saas_sub_bridge_billing_policy_ready($pdo);$commercialReady=saas_sub_bridge_commercial_policy_ready($pdo);$adjustmentReady=saas_sub_bridge_adjustment_ready($pdo);$msg='';$err='';
$cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);

$allowedSections=['faturamento','periodos','modulos','contratos'];
$section=(string)($_POST['section']??$_GET['section']??'faturamento');
if(!in_array($section,$allowedSections,true))$section='faturamento';
$companySearch=trim((string)($_GET['q_company']??''));

// V11.52.1 — buscas F8 da migração de clientes existentes.
if(isset($_GET['ajax'])){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if(!$ready)throw new RuntimeException('A migration V11.52 ainda não foi aplicada.');
    if($billingCompanyId<=0)throw new RuntimeException('Configure primeiro a empresa faturadora.');
    $ajax=(string)$_GET['ajax'];$q=trim((string)($_GET['q']??''));
    if($ajax==='people'){
      echo json_encode(['ok'=>true,'rows'=>saas_sub_bridge_people_search($pdo,$billingCompanyId,$q,30)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    if($ajax==='contracts'){
      $personId=max(0,(int)($_GET['person_id']??0));
      echo json_encode(['ok'=>true,'rows'=>saas_sub_bridge_contract_search($pdo,$billingCompanyId,$personId,$q,30)],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;
    }
    throw new RuntimeException('Consulta inválida.');
  }catch(Throwable $e){http_response_code(400);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);exit;}
}

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    sb_csrf((string)($_POST['csrf']??''));
    if(!$ready) throw new RuntimeException('Aplique primeiro a migration V11.52 pelo Atualizador do Banco.');
    $action=(string)($_POST['action']??'');
    if($action==='save_crm_lead'){
      if($billingCompanyId<=0)throw new RuntimeException('Configure primeiro a empresa faturadora.');
      saas_crm_lead_save_config($pdo,$_POST,$billingCompanyId);
      $msg='Configuração do atendimento no CRM salva.';
    }
    if($action==='save_config'){
      $billingCompanyId=max(0,(int)($_POST['billing_company_id']??0));
      if($billingCompanyId<=0) throw new RuntimeException('Selecione a empresa faturadora do Admin.');
      $st=$pdo->prepare('SELECT id,name FROM companies WHERE id=? LIMIT 1');$st->execute([$billingCompanyId]);$company=$st->fetch(PDO::FETCH_ASSOC)?:null;$st->closeCursor();
      if(!$company) throw new RuntimeException('Empresa faturadora não localizada.');
      $currentCfg=saas_sub_bridge_config($pdo);$currentBilling=(int)($currentCfg['billing_company_id']??0);
      if($currentBilling>0 && $currentBilling!==$billingCompanyId){
        $st=$pdo->query('SELECT COUNT(*) FROM saas_subscription_links');$linked=(int)($st?$st->fetchColumn():0);if($st)$st->closeCursor();
        if($linked>0) throw new RuntimeException('A empresa faturadora não pode ser trocada depois que já existem assinaturas vinculadas. Isso protege contratos e financeiro de outra empresa.');
      }
      $enabled=!empty($_POST['enabled'])?1:0;$trial=max(1,min(60,(int)($_POST['trial_days']??7)));$lead=max(0,min(30,(int)($_POST['charge_lead_days']??2)));$grace=max(0,min(30,(int)($_POST['grace_days']??3)));
      $payment=max(0,(int)($_POST['default_payment_method_id']??0));$bank=max(0,(int)($_POST['default_bank_account_id']??0));$chart=max(0,(int)($_POST['default_chart_account_id']??0));$cost=max(0,(int)($_POST['default_cost_center_id']??0));
      $checks=[['finance_payment_methods',$payment,'is_active=1'],['finance_bank_accounts',$bank,'active=1'],['finance_chart_accounts',$chart,'active=1 AND is_analytic=1'],['finance_cost_centers',$cost,'active=1 AND is_analytic=1']];
      foreach($checks as [$table,$id,$extra]){if($id<=0)continue;$st=$pdo->prepare("SELECT id FROM {$table} WHERE company_id=? AND id=? AND {$extra} LIMIT 1");$st->execute([$billingCompanyId,$id]);$ok=(int)$st->fetchColumn();$st->closeCursor();if($ok<=0)throw new RuntimeException('Uma configuração financeira selecionada não pertence à empresa faturadora.');}
      if($enabled===1){
        $st=$pdo->prepare('SELECT COUNT(*) FROM saas_subscription_module_map WHERE billing_company_id=? AND active=1');$st->execute([$billingCompanyId]);$mapCount=(int)$st->fetchColumn();$st->closeCursor();
        if($mapCount<=0) throw new RuntimeException('Antes de ativar, vincule pelo menos um módulo SaaS a um Serviço do Admin.');
      }
      $pdo->prepare('UPDATE saas_subscription_bridge_config SET enabled=?,billing_company_id=?,trial_days=?,charge_lead_days=?,grace_days=?,default_payment_method_id=?,default_bank_account_id=?,default_chart_account_id=?,default_cost_center_id=?,updated_at=NOW() WHERE id=1')->execute([$enabled,$billingCompanyId,$trial,$lead,$grace,$payment?:null,$bank?:null,$chart?:null,$cost?:null]);
      saas_sub_bridge_event($pdo,'BRIDGE_CONFIG:'.date('YmdHis'),'BRIDGE_CONFIG_UPDATED',null,null,$billingCompanyId,'PLATFORM_ADMIN',(int)($_SESSION['user_id']??0),['enabled'=>$enabled,'billing_company_id'=>$billingCompanyId,'trial_days'=>$trial,'charge_lead_days'=>$lead,'grace_days'=>$grace],true,null);
      $msg='Configuração da integração salva. Toda operação automática ficará restrita à empresa “'.(string)$company['name'].'”.';
    }
    if($action==='save_mapping'){
      $cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);if($billingCompanyId<=0)throw new RuntimeException('Salve primeiro a empresa faturadora.');saas_sub_bridge_assert_billing_company($pdo,$billingCompanyId);
      $raw=(array)($_POST['product_map']??[]);$pdo->beginTransaction();
      try{
        foreach(saas_all_modules($pdo,true) as $m){$mid=(int)$m['id'];$pid=max(0,(int)($raw[$mid]??0));
          if($pid<=0){$pdo->prepare('DELETE FROM saas_subscription_module_map WHERE billing_company_id=? AND saas_module_id=?')->execute([$billingCompanyId,$mid]);continue;}
          $st=$pdo->prepare('SELECT id FROM produtos WHERE company_id=? AND id=? AND COALESCE(ativo,1)=1 AND COALESCE(is_service,0)=1 LIMIT 1');$st->execute([$billingCompanyId,$pid]);$valid=(int)$st->fetchColumn();$st->closeCursor();if($valid<=0)throw new RuntimeException('O item selecionado para “'.(string)$m['name'].'” não é um Serviço ativo da empresa faturadora.');
          $pdo->prepare('INSERT INTO saas_subscription_module_map(billing_company_id,saas_module_id,product_id,active,created_at,updated_at) VALUES(?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE product_id=VALUES(product_id),active=1,updated_at=NOW()')->execute([$billingCompanyId,$mid,$pid]);
        }
        $pdo->commit();$msg='Vínculos Módulo SaaS ↔ Serviço atualizados.';
      }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
    if($action==='save_terms'){
      if(!$commercialReady)throw new RuntimeException('Aplique primeiro a migration V11.52.3.');
      $discounts=(array)($_POST['term_discount']??[]);$actives=(array)($_POST['term_active']??[]);$allowed=[1=>'MONTHLY',3=>'QUARTERLY',6=>'SEMIANNUAL',12=>'ANNUAL'];$activeCount=0;$normalized=[];
      foreach($allowed as $months=>$code){$raw=str_replace(',','.',trim((string)($discounts[$months]??'0')));$disc=is_numeric($raw)?(float)$raw:0.0;$disc=max(0.0,min(90.0,$disc));$active=!empty($actives[$months])?1:0;if($active)$activeCount++;$normalized[$months]=[$disc,$active];}
      if($activeCount<=0)throw new RuntimeException('Mantenha pelo menos um período de renovação ativo.');
      $st=$pdo->prepare('UPDATE saas_subscription_term_options SET discount_percent=?,active=?,updated_at=NOW() WHERE term_months=?');foreach($normalized as $months=>[$disc,$active])$st->execute([$disc,$active,$months]);
      saas_sub_bridge_event($pdo,'TERM_OPTIONS:'.date('YmdHis'),'SUBSCRIPTION_TERM_OPTIONS_UPDATED',null,null,$billingCompanyId,'PLATFORM_ADMIN',(int)($_SESSION['user_id']??0),['discounts'=>$discounts,'active_terms'=>array_keys(array_filter($actives))],true,null);
      $msg='Períodos de renovação e descontos salvos. Eles serão usados nas próximas cobranças, sem alterar cobranças já geradas.';
    }
    if($action==='sync_company'){
      $companyId=max(0,(int)($_POST['saas_company_id']??0));if($companyId<=0)throw new RuntimeException('Empresa SaaS inválida.');$r=saas_sub_bridge_sync_company($pdo,$companyId,'central_saas');
      $msg='Sincronização concluída: Pessoa #'.(int)$r['person_id'].' · '.((int)$r['contract_id']>0?'Contrato #'.(int)$r['contract_id']:'sem contrato (teste)').' · '.(int)$r['module_count'].' módulo(s) faturável(is).';
    }
    if($action==='migrate_existing'){
      $activateSub=!empty($_POST['activate_subscription']);
      $r=saas_sub_bridge_migrate_existing($pdo,$_POST,$activateSub?'central_saas_activation':'central_saas_migration');
      if($activateSub){
        // Ativação pedida pela Central SaaS (troca de status) ou pelo CRM: só agora,
        // com o contrato conferido e lançado, a assinatura passa a ATIVA.
        $saasCid=(int)$r['saas_company_id'];$sub=saas_sub_bridge_latest_subscription($pdo,$saasCid);
        if($sub){$pdo->prepare("UPDATE subscriptions SET status='active' WHERE id=? AND company_id=?")->execute([(int)$sub['id'],$saasCid]);}
        $pdo->prepare("UPDATE companies SET status='active' WHERE id=?")->execute([$saasCid]);
        $stTot=$pdo->prepare('SELECT contract_number,total_amount FROM contracts WHERE company_id=? AND id=? LIMIT 1');$stTot->execute([(int)$r['billing_company_id'],(int)$r['contract_id']]);$ct=$stTot->fetch(PDO::FETCH_ASSOC)?:[];
        saas_crm_lead_register_move($pdo,$saasCid,'Contratação aprovada e assinatura ativada. Contrato nº '.(string)($ct['contract_number']??$r['contract_id']).' lançado ('.(int)($r['billable_count']??0).' módulo(s) faturado(s), renovação de '.(int)$r['renewal_term_months'].' mês(es), valor do ciclo R$ '.number_format((float)($ct['total_amount']??0),2,',','.').').',true,(int)($_SESSION['user_id']??0)?:null);
      }
      $billing=$r['billing_state']??[];$extra='';
      if((int)($billing['generation_count']??0)>0 || (int)($billing['open_receivable_count']??0)>0 || (int)($billing['boleto_count']??0)>0){$extra=' Cobranças já existentes foram preservadas para reconhecimento no próximo ciclo de automação.';}
      $msg=($activateSub?'Assinatura ATIVADA. ':'').'Cliente vinculado com segurança: Pessoa #'.(int)$r['person_id'].' · Contrato #'.(int)$r['contract_id'].' · '.(int)($r['billable_count']??$r['module_count']??0).' módulo(s) faturado(s) · '.(int)($r['courtesy_count']??0).' em cortesia · próximo vencimento '.sb_date($r['next_due_date']??'').' · próximo reajuste '.sb_date($r['next_adjustment_date']??'').' · renovação de '.(int)($r['renewal_term_months']??1).' mês(es). Nenhuma cobrança foi gerada.'.$extra;
      $_GET['migrate']='';
    }
  }catch(Throwable $e){$err=$e->getMessage();}
}

$cfg=saas_sub_bridge_config($pdo);$billingCompanyId=(int)($cfg['billing_company_id']??0);$billingCompany=$billingCompanyId>0?saas_sub_bridge_billing_company($pdo,$cfg):null;$termOptions=$commercialReady?saas_sub_bridge_term_options($pdo,false):[];
$companies=$pdo->query("SELECT id,name,slug,status,cnpj,cpf FROM companies ORDER BY name,id")->fetchAll(PDO::FETCH_ASSOC)?:[];
$modules=saas_all_modules($pdo,true);$map=$billingCompanyId>0?saas_sub_bridge_module_map($pdo,$billingCompanyId):[];
$services=[];$payments=[];$banks=[];$charts=[];$costs=[];
if($billingCompanyId>0){
  try{$codeSql=contracts_has_column($pdo,'produtos','numero_cadastro')?",COALESCE(numero_cadastro,'') numero_cadastro":",'' numero_cadastro";$st=$pdo->prepare("SELECT id,nome,preco{$codeSql} FROM produtos WHERE company_id=? AND COALESCE(ativo,1)=1 AND COALESCE(is_service,0)=1 ORDER BY nome,id");$st->execute([$billingCompanyId]);$services=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){}
  try{$st=$pdo->prepare('SELECT id,name,type,COALESCE(auto_register_boleto,0) auto_register_boleto FROM finance_payment_methods WHERE company_id=? AND is_active=1 ORDER BY sort_order,name,id');$st->execute([$billingCompanyId]);$payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){}
  try{$st=$pdo->prepare('SELECT id,name,type FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name,id');$st->execute([$billingCompanyId]);$banks=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){}
  try{$st=$pdo->prepare('SELECT id,code,name FROM finance_chart_accounts WHERE company_id=? AND active=1 AND is_analytic=1 ORDER BY code,name,id');$st->execute([$billingCompanyId]);$charts=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){}
  try{$st=$pdo->prepare('SELECT id,code,name FROM finance_cost_centers WHERE company_id=? AND active=1 AND is_analytic=1 ORDER BY code,name,id');$st->execute([$billingCompanyId]);$costs=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){}
}

$links=[];
if($ready){
  $termCols=$commercialReady?',l.renewal_term_months,l.renewal_discount_percent':',1 renewal_term_months,0 renewal_discount_percent';$adjustmentCol=$adjustmentReady?',l.next_adjustment_date':',NULL next_adjustment_date';
  $where='';$linkParams=[];
  if($companySearch!==''){
    $like='%'.$companySearch.'%';
    $where=' WHERE (c.name LIKE ? OR c.slug LIKE ?';$linkParams=[$like,$like];
    if(ctype_digit($companySearch)){$where.=' OR c.id=?';$linkParams[]=(int)$companySearch;}
    $where.=')';
  }
  $sql="SELECT c.id,c.name,c.slug,c.status AS company_status,s.id subscription_id,s.status subscription_status,s.started_at,s.ends_at,s.next_billing_at,p.name plan_name,l.id link_id,l.lifecycle_status,l.person_id,l.contract_id,l.current_period_start,l.current_period_end,l.next_due_date{$adjustmentCol},l.grace_until{$termCols},l.last_sync_at,l.last_error,ct.contract_number,ct.status contract_status
        FROM companies c
        LEFT JOIN (SELECT s1.* FROM subscriptions s1 INNER JOIN (SELECT company_id,MAX(id) max_id FROM subscriptions GROUP BY company_id)x ON x.max_id=s1.id)s ON s.company_id=c.id
        LEFT JOIN plans p ON p.id=s.plan_id
        LEFT JOIN saas_subscription_links l ON l.saas_company_id=c.id
        LEFT JOIN contracts ct ON ct.company_id=l.billing_company_id AND ct.id=l.contract_id
        {$where}
        ORDER BY c.id DESC LIMIT 150";
  try{$st=$pdo->prepare($sql);$st->execute($linkParams);$links=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st->closeCursor();}catch(Throwable $e){if($err==='')$err=$e->getMessage();}
}

// Painel V11.52.1 — migração controlada de cliente existente.
$personContractCount=0;$suggestedMigContract=false;$migrateId=max(0,(int)($_GET['migrate']??0));$priceReady=saas_sub_bridge_price_ready($pdo);$activateMode=$migrateId>0 && !empty($_GET['activate']);$migrateCompany=null;$migrateSub=null;$suggestedPerson=null;$migrateModules=[];$existingMigLink=null;$existingMigContract=null;$migratePolicy=[];$migrateBillableIds=[];
if($migrateId>0)$section='contratos';
$defaultStart='';$defaultEnd='';$defaultDue='';$defaultAdjustment='';$defaultGrace='';$defaultLifecycle='ACTIVE';$defaultTermMonths=1;
if($migrateId>0 && $ready && $billingCompanyId>0){
  $migrateCompany=saas_sub_bridge_company($pdo,$migrateId);$migrateSub=saas_sub_bridge_latest_subscription($pdo,$migrateId);
  if($migrateCompany && $migrateSub){
    $existingMigLink=saas_sub_bridge_link($pdo,$migrateId);
    if($existingMigLink && (int)($existingMigLink['person_id']??0)>0){$stp=$pdo->prepare('SELECT id,name,document,email,phone,active FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$stp->execute([$billingCompanyId,(int)$existingMigLink['person_id']]);$suggestedPerson=$stp->fetch(PDO::FETCH_ASSOC)?:null;$stp->closeCursor();}
    if(!$suggestedPerson)$suggestedPerson=saas_sub_bridge_find_person_suggestion($pdo,$billingCompanyId,$migrateCompany);
    if($existingMigLink && (int)($existingMigLink['contract_id']??0)>0){$stc=$pdo->prepare("SELECT c.id,c.contract_number,c.status,c.total_amount,c.validity_start,c.validity_end,(SELECT MIN(ci.next_adjustment_date) FROM contract_items ci WHERE ci.company_id=c.company_id AND ci.contract_id=c.id AND ci.status='ATIVO' AND ci.next_adjustment_date IS NOT NULL) next_adjustment_date FROM contracts c WHERE c.company_id=? AND c.id=? LIMIT 1");$stc->execute([$billingCompanyId,(int)$existingMigLink['contract_id']]);$existingMigContract=$stc->fetch(PDO::FETCH_ASSOC)?:null;$stc->closeCursor();}
    // Cliente que já tem contrato na faturadora sem vínculo: sugere o contrato da
    // Pessoa para não criar um segundo contrato por engano.
    if(!$existingMigContract && $suggestedPerson){
      try{$personContracts=array_values(array_filter(saas_sub_bridge_contract_search($pdo,$billingCompanyId,(int)$suggestedPerson['id'],'',30),static fn($c)=>(int)($c['linked_saas_company_id']??0)===0||(int)$c['linked_saas_company_id']===$migrateId));}catch(Throwable $e){$personContracts=[];}
      $personContractCount=count($personContracts);
      if($personContractCount===1){$existingMigContract=$personContracts[0];$suggestedMigContract=true;}
    }
    $migrateModules=saas_sub_bridge_enabled_billable_modules($pdo,$migrateId,$billingCompanyId);
    if($billingPolicyReady && $existingMigLink)$migratePolicy=saas_sub_bridge_billing_policy_map($pdo,(int)$existingMigLink['id']);
    foreach($migrateModules as $mm){$mid=(int)$mm['saas_module_id'];if(strtoupper((string)($migratePolicy[$mid]['billing_mode']??'BILLABLE'))!=='COURTESY')$migrateBillableIds[$mid]=true;}
    $st=strtolower((string)($migrateSub['status']??''));$defaultStart=$existingMigLink?sb_date_input($existingMigLink['current_period_start']??''):($st==='trial'?sb_date_input($migrateSub['started_at']??''):'');$defaultEnd=$existingMigLink?sb_date_input($existingMigLink['current_period_end']??''):sb_date_input($migrateSub['ends_at']??'');$defaultDue=$existingMigLink?sb_date_input($existingMigLink['next_due_date']??''):sb_date_input($migrateSub['next_billing_at']??($migrateSub['ends_at']??''));
    $defaultAdjustment=$adjustmentReady&&$existingMigLink?sb_date_input($existingMigLink['next_adjustment_date']??''):'';
    if($defaultAdjustment==='' && $existingMigContract)$defaultAdjustment=sb_date_input($existingMigContract['next_adjustment_date']??'');
    if($defaultAdjustment==='' && $defaultStart!==''){try{$defaultAdjustment=(new DateTimeImmutable($defaultStart))->modify('+1 year')->format('Y-m-d');}catch(Throwable $e){}}
    if($defaultEnd==='' && $defaultDue!=='')$defaultEnd=$defaultDue;
    if($existingMigLink && !empty($existingMigLink['grace_until']))$defaultGrace=sb_date_input($existingMigLink['grace_until']);elseif($defaultDue!==''){try{$defaultGrace=(new DateTimeImmutable($defaultDue))->modify('+'.max(0,(int)($cfg['grace_days']??3)).' days')->format('Y-m-d');}catch(Throwable $e){}}
    if($existingMigLink && !empty($existingMigLink['lifecycle_status']))$defaultLifecycle=strtoupper((string)$existingMigLink['lifecycle_status']);elseif(in_array($st,['past_due','pending_payment'],true))$defaultLifecycle='PENDING_PAYMENT';
    if(!in_array($defaultLifecycle,['ACTIVE','PENDING_PAYMENT','GRACE_PERIOD'],true))$defaultLifecycle='ACTIVE';
    if($commercialReady && $existingMigLink && !empty($existingMigLink['renewal_term_months']))$defaultTermMonths=(int)$existingMigLink['renewal_term_months'];
  }
}

$sectionTitles=[
  'faturamento'=>'Assinaturas Nexa · Configurações de Faturamento',
  'periodos'=>'Assinaturas Nexa · Períodos de Renovação',
  'modulos'=>'Assinaturas Nexa · Vínculo Módulos/Serviços',
  'contratos'=>'Assinaturas Nexa · Vincular Contratos',
];
$sectionSubtitles=[
  'faturamento'=>'Empresa faturadora exclusiva, parâmetros financeiros e regra operacional da integração.',
  'periodos'=>'Defina os períodos antecipados de renovação e os descontos comerciais.',
  'modulos'=>'Relacione cada módulo SaaS ao Serviço correspondente no Admin da empresa faturadora.',
  'contratos'=>'Pesquise empresas SaaS e prepare a sincronização ou migração segura de Pessoa e Contrato.',
];
saas_layout_start($sectionTitles[$section].' • Central SaaS',$sectionTitles[$section],$sectionSubtitles[$section]);
?>
<style>
.sb-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.sb-span{grid-column:1/-1}.sb-fields{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px}.sb-field label{display:block;font-size:12px;font-weight:900;color:#475569;margin:0 0 6px}.sb-note{padding:12px 14px;border-radius:14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-weight:700}.sb-note.warn2{background:#fff7ed;border-color:#fed7aa;color:#9a3412}.sb-status{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:11px;font-weight:900}.sb-on{background:#dcfce7;color:#166534}.sb-off{background:#fee2e2;color:#991b1b}.sb-table{width:100%;min-width:1100px}.sb-table td,.sb-table th{padding:9px 8px}.sb-map{width:100%;border-collapse:collapse}.sb-map select{padding:9px 10px;border-radius:10px}.sb-actions{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.sb-small{font-size:12px;color:#64748b}.sb-error{color:#b91c1c;font-size:12px;max-width:360px}.sb-ok{color:#166534;font-size:12px}.sb-migrate{border:2px solid #bfdbfe}.sb-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:12px 0}.sb-summary>div{border:1px solid #e2e8f0;border-radius:12px;padding:10px;background:#f8fafc}.sb-summary strong{display:block;font-size:12px;color:#475569;margin-bottom:4px}.sb-inline{display:flex;gap:8px;align-items:center}.sb-inline input{flex:1}.sb-check{display:flex;align-items:flex-start;gap:9px;padding:10px 12px;border:1px solid #e2e8f0;border-radius:12px;background:#fff}.sb-check input{width:auto;margin-top:3px}.sb-modal-bg{position:fixed;inset:0;background:rgba(15,23,42,.42);display:none;align-items:center;justify-content:center;padding:18px;z-index:9999}.sb-modal-bg.open{display:flex}.sb-modal{background:#fff;border-radius:16px;width:min(760px,96vw);max-height:82vh;overflow:auto;border:1px solid #dbe4ee}.sb-modal-head{display:flex;align-items:center;gap:10px;padding:14px;border-bottom:1px solid #e2e8f0}.sb-modal-body{padding:14px}.sb-results{margin-top:10px;border:1px solid #e2e8f0;border-radius:12px;overflow:hidden}.sb-result{padding:10px 12px;border-bottom:1px solid #eef2f7;cursor:pointer}.sb-result:last-child{border-bottom:0}.sb-result.sel,.sb-result:hover{background:#eff6ff}.sb-pill{display:inline-flex;padding:4px 8px;border-radius:999px;background:#eef2ff;color:#3730a3;font-size:11px;font-weight:800;margin:2px 4px 2px 0}.sb-billing-list{border:1px solid #dbe4ee;border-radius:14px;overflow:hidden}.sb-billing-row{display:grid;grid-template-columns:95px minmax(160px,1.05fr) minmax(210px,1.25fr) 82px 115px 115px minmax(170px,1fr);gap:9px;align-items:center;padding:9px 12px;border-bottom:1px solid #eef2f7;background:#fff}.sb-billing-row:last-child{border-bottom:0}.sb-billing-row.is-courtesy{background:#fffbeb}.sb-billing-toggle{display:flex;align-items:center;gap:7px;font-weight:900}.sb-billing-toggle input{width:auto}.sb-courtesy-badge{display:none;color:#92400e;font-size:11px;font-weight:900}.sb-billing-row.is-courtesy .sb-courtesy-badge{display:inline}.sb-courtesy-note{padding:8px 9px!important;min-height:36px}.sb-qty{width:78px!important;text-align:right;padding:8px!important}.sb-money-label{font-size:11px;color:#334155;display:block;font-weight:800}.sb-billing-row,.sb-billing-row>div,.sb-billing-row strong{color:#0f172a}.sb-billing-row .sb-small{color:#475569}.sb-billing-row input{color:#0f172a;background:#fff}.sb-term-box{border:1px solid #cfe0ff;border-radius:14px;padding:12px;background:#f8fbff}.sb-term-summary{display:flex;gap:16px;flex-wrap:wrap;margin-top:8px;font-size:12px;color:#475569}.sb-term-summary strong{color:#0f172a}.sb-term-table{width:100%;border-collapse:collapse}.sb-term-table td,.sb-term-table th{padding:8px;border-bottom:1px solid #eef2f7;text-align:left}.sb-term-table input[type=number]{max-width:120px}.sb-billing-total{display:flex;gap:14px;flex-wrap:wrap;padding:9px 12px;background:#f8fafc;border-top:1px solid #e2e8f0;font-size:12px;font-weight:800;color:#475569}.sb-company-search{display:grid;grid-template-columns:minmax(260px,1fr) auto auto;gap:8px;align-items:end;margin:0 0 14px}.sb-company-search label{display:block;font-size:12px;font-weight:900;color:#475569;margin:0 0 6px}.sb-section-card{width:100%}.sb-section-card h3{margin-bottom:8px}@media(max-width:700px){.sb-company-search{grid-template-columns:1fr}.sb-company-search .btn2{width:100%}}@media(max-width:1000px){.sb-grid{grid-template-columns:1fr}.sb-span{grid-column:auto}.sb-fields,.sb-summary{grid-template-columns:1fr 1fr}}@media(max-width:760px){.sb-billing-row{grid-template-columns:1fr 1fr}.sb-billing-row>div:nth-child(2),.sb-billing-row>div:nth-child(3),.sb-billing-row>div:nth-child(7){grid-column:1/-1}}@media(max-width:650px){.sb-fields,.sb-summary{grid-template-columns:1fr}}
</style>
<div class="top-actions"><a class="btn2 btn2-ghost" href="/saas/empresas.php">Empresas</a><a class="btn2 btn2-ghost" href="/saas/planos.php">Preços e módulos</a></div>
<?php if($msg!==''):?><div class="alert-ok"><?=sb_h($msg)?></div><?php endif;?>
<?php if($err!==''):?><div class="alert-err"><?=sb_h($err)?></div><?php endif;?>
<?php if(!$ready):?><div class="warn"><strong>Banco: V11.52 pendente.</strong> Aplique primeiro a migration “V11.52 — Fundação das Assinaturas SaaS”.</div><?php endif;?>
<?php if($ready && !$billingPolicyReady):?><div class="warn"><strong>Banco: V11.52.2 pendente.</strong> Aplique a migration “V11.52.2 — Módulos faturados e cortesia comercial” para definir módulos em cortesia.</div><?php endif;?>
<?php if($billingPolicyReady && !$commercialReady):?><div class="warn"><strong>Banco: V11.52.3 pendente.</strong> Aplique a migration “V11.52.3 — Quantidades e períodos de renovação SaaS” antes de confirmar vínculos com quantidade/período.</div><?php endif;?>
<?php if($commercialReady && !$priceReady):?><div class="warn"><strong>Banco: atualização pendente.</strong> Aplique “Assinaturas SaaS - valor mensal negociado por módulo” no Atualizador do Banco para poder alterar o valor unitário/mês dos módulos.</div><?php endif;?>
<?php if($commercialReady && !$adjustmentReady):?><div class="warn"><strong>Banco: V11.52.9 pendente.</strong> Aplique a migration “V11.52.9 — Vinculação completa e próximo reajuste” para criar Pessoa/Contrato pela migração e controlar a data anual de reajuste.</div><?php endif;?>

<?php if($section==='contratos' && $migrateId>0):?>
<section class="panel sb-migrate" id="migracaoExistente">
  <div class="sb-actions"><div><h3 style="margin:0">V11.52.9 · Vincular/Criar cliente, contrato e política de renovação</h3><div class="muted">Migração controlada. Não gera Pedido, Contas a Receber, boleto, PIX ou NFS-e.</div></div><span style="flex:1"></span><a class="btn2 btn2-ghost" href="assinaturas_nexa.php?section=contratos">Fechar</a></div>
  <?php if(!$migrateCompany || !$migrateSub):?><div class="warn">Empresa ou assinatura SaaS não localizada.</div>
  <?php elseif(!$migrateModules):?><div class="warn">Nenhum módulo habilitado desta empresa está vinculado a um Serviço do Admin. Configure primeiro o mapa Módulo SaaS ↔ Serviço.</div>
  <?php else:?>
  <div class="sb-summary">
    <div><strong>Empresa SaaS</strong><?=sb_h($migrateCompany['name'])?> · #<?=(int)$migrateCompany['id']?></div>
    <div><strong>Assinatura atual</strong><?=sb_h(strtoupper((string)$migrateSub['status']))?><?=!empty($migrateSub['plan_name'])?' · '.sb_h($migrateSub['plan_name']):''?></div>
    <div><strong>Empresa faturadora</strong><?=sb_h($billingCompany['name']??'')?> · #<?=$billingCompanyId?></div>
    <div><strong>Política padrão</strong><?= (int)($cfg['charge_lead_days']??2) ?> dia(s) antes · <?= (int)($cfg['grace_days']??3) ?> dia(s) de carência</div>
  </div>
  <?php if($activateMode):?><div class="sb-note" style="margin-bottom:10px;background:#ecfdf5;border-color:#a7f3d0;color:#065f46"><strong>Ativação da assinatura:</strong> confira a Pessoa, o contrato, os módulos faturados, as quantidades e o valor unitário/mês de cada módulo. Ao confirmar, o contrato é lançado (ou o contrato de teste vira ATIVO), a assinatura passa para <strong>ativa</strong> e o atendimento do CRM é concluído. Enquanto não confirmar, a assinatura continua como está.</div><?php endif;?>
  <?php if($suggestedMigContract):?><div class="sb-note" style="margin-bottom:10px"><strong>Contrato encontrado:</strong> a Pessoa já tem o contrato nº <?=sb_h($existingMigContract['contract_number']??'')?> (<?=sb_h($existingMigContract['status']??'')?>) sem vínculo com o SaaS. Ele foi pré-selecionado; confira antes de confirmar.</div><?php elseif($personContractCount>1):?><div class="sb-note warn2" style="margin-bottom:10px"><strong>Atenção:</strong> a Pessoa tem <?=$personContractCount?> contratos sem vínculo com o SaaS. Use “Buscar contrato” e escolha o correto, para não criar um contrato duplicado.</div><?php endif;?>
  <div class="sb-note warn2"><strong>Regra de segurança:</strong> a vinculação assume somente os próximos ciclos. Não cria cobrança retroativa e não apaga Pedido, título, boleto ou PIX já existente. Se o contrato tiver outros serviços que não são módulos SaaS, eles permanecem intocados.</div>
  <form method="post" id="migrationForm" style="margin-top:14px">
    <input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="contratos"><input type="hidden" name="action" value="migrate_existing"><input type="hidden" name="saas_company_id" value="<?=$migrateId?>"><?php if($activateMode):?><input type="hidden" name="activate_subscription" value="1"><?php endif;?>
    <div class="sb-fields">
      <div class="sb-field sb-span"><label>Pessoa no Admin da <?=sb_h($billingCompany['name']??'empresa faturadora')?></label><div class="sb-inline"><input type="hidden" name="person_id" id="migPersonId" value="<?=(int)($suggestedPerson['id']??0)?>"><input id="migPersonName" readonly value="<?=sb_h($suggestedPerson?('#'.(int)$suggestedPerson['id'].' · '.$suggestedPerson['name'].' · '.($suggestedPerson['document']??'')):'')?>" placeholder="Pessoa ainda não localizada no Admin"><button type="button" class="btn2 btn2-ghost" id="migPersonSearch">Buscar (F8)</button></div><label class="sb-check" style="margin-top:8px"><input type="checkbox" name="create_person" value="1" id="migCreatePerson" <?=!$suggestedPerson?'checked':''?>><span><strong>Criar Pessoa automaticamente com os dados do SaaS</strong><br><span class="sb-small">Se já existir CNPJ/CPF ou e-mail correspondente, o sistema reaproveita e atualiza esse cadastro em vez de duplicar.</span></span></label><div class="sb-small">Para clientes já cadastrados, você pode buscar e selecionar a Pessoa manualmente.</div></div>
      <div class="sb-field sb-span"><label>Contrato do Admin</label><div class="sb-inline"><input type="hidden" name="contract_id" id="migContractId" value="<?=(int)($existingMigContract['id']??0)?>"><input id="migContractName" readonly value="<?=sb_h($existingMigContract?('Contrato #'.(string)$existingMigContract['contract_number'].' · '.(string)$existingMigContract['status'].' · '.sb_money($existingMigContract['total_amount']??0)):'')?>" placeholder="Selecione um contrato da Pessoa"><button type="button" class="btn2 btn2-ghost" id="migContractSearch">Buscar contrato</button></div><label class="sb-check" style="margin-top:8px"><input type="checkbox" name="create_new_contract" value="1" id="migCreateContract" <?=(!$existingMigContract && $personContractCount===0 && (!$suggestedPerson || $activateMode))?'checked':''?>><span><strong>Criar um novo contrato</strong><br><span class="sb-small">Use somente se não existir contrato adequado. O novo contrato será criado ATIVO, mas nenhuma cobrança será gerada nesta versão.</span></span></label></div>
      <div class="sb-field"><label>Início do período vigente *</label><input type="date" name="current_period_start" value="<?=sb_h($defaultStart)?>" required></div>
      <div class="sb-field"><label>Fim do período vigente *</label><input type="date" name="current_period_end" value="<?=sb_h($defaultEnd)?>" required></div>
      <div class="sb-field"><label>Próximo vencimento *</label><input type="date" name="next_due_date" id="migNextDue" value="<?=sb_h($defaultDue)?>" required></div>
      <div class="sb-field"><label>Próximo reajuste anual *</label><input type="date" name="next_adjustment_date" id="migNextAdjustment" value="<?=sb_h($defaultAdjustment)?>" <?=$adjustmentReady?'required':'disabled'?>><div class="sb-small">Data em que os módulos/serviços poderão receber o próximo reajuste anual. A futura rotina de reajuste usará esta referência.</div></div>
      <div class="sb-field"><label>Carência até *</label><input type="date" name="grace_until" id="migGrace" value="<?=sb_h($defaultGrace)?>" required></div>
      <div class="sb-field"><label>Situação inicial</label><select name="lifecycle_status"><option value="ACTIVE" <?=$defaultLifecycle==='ACTIVE'?'selected':''?>>ATIVO</option><option value="PENDING_PAYMENT" <?=$defaultLifecycle==='PENDING_PAYMENT'?'selected':''?>>Aguardando pagamento</option><option value="GRACE_PERIOD">Em carência</option></select></div>
      <div class="sb-field sb-span"><label>Período da próxima renovação *</label><div class="sb-term-box"><select name="renewal_term_months" id="renewalTerm" <?=$commercialReady?'':'disabled'?> required><?php foreach($termOptions as $to):if(empty($to['active']))continue;$tm=(int)$to['term_months'];?><option value="<?=$tm?>" data-months="<?=$tm?>" data-discount="<?=sb_h((string)$to['discount_percent'])?>" <?=$defaultTermMonths===$tm?'selected':''?>><?=sb_h($to['label'])?> · <?=$tm?> mês(es)<?=((float)$to['discount_percent'])>0?' · desconto '.number_format((float)$to['discount_percent'],2,',','.').'%':''?></option><?php endforeach;?></select><div class="sb-small" style="margin-top:6px">Esta escolha <strong>não gera cobrança agora</strong>. No próximo ciclo será criada uma única cobrança antecipada correspondente ao período escolhido; após o pagamento, o acesso será prorrogado pelo mesmo período.</div><div class="sb-term-summary"><span>Base mensal faturável: <strong id="termMonthlyBase">—</strong></span><span>Desconto do período: <strong id="termDiscountValue">—</strong></span><span>Próxima cobrança estimada: <strong id="termCycleTotal">—</strong></span></div></div></div>
      <div class="sb-field sb-span"><label>Módulos SaaS ativos · escolha quais serão faturados</label>
        <?php if($billingPolicyReady):?><input type="hidden" name="billing_policy_present" value="1"><?php endif;?>
        <div class="sb-billing-list" id="billingModuleList">
          <?php foreach($migrateModules as $m):$mid=(int)$m['saas_module_id'];$checked=isset($migrateBillableIds[$mid]);$note=(string)($migratePolicy[$mid]['courtesy_note']??'');$servicePrice=(float)($m['product_price']??0);$negotiated=(float)($migratePolicy[$mid]['billing_unit_price']??0);$unit=$negotiated>0?$negotiated:$servicePrice;$qty=max(1.0,(float)($migratePolicy[$mid]['billing_quantity']??$m['quantity']??1));$value=$unit*$qty;?>
          <div class="sb-billing-row <?=$checked?'':'is-courtesy'?>" data-module-row data-unit="<?=sb_h((string)$unit)?>">
            <label class="sb-billing-toggle"><input type="checkbox" name="billable_modules[]" value="<?=$mid?>" data-billable-toggle <?=$checked?'checked':''?> <?=$commercialReady?'':'disabled'?>> <span>Faturar</span></label>
            <div><strong><?=sb_h($m['module_name']??$m['module_code']??'Módulo')?></strong><div class="sb-courtesy-badge">CORTESIA</div></div>
            <div class="sb-small">Serviço: <strong><?=sb_h($m['product_name']??'Serviço')?></strong></div>
            <div><span class="sb-money-label">Qtd.</span><input class="sb-qty" type="number" min="1" max="999" step="1" name="billing_quantity[<?=$mid?>]" value="<?=sb_h(rtrim(rtrim(number_format($qty,2,'.',''),'0'),'.'))?>" data-billing-qty <?=$commercialReady?'':'disabled'?>></div>
            <div><span class="sb-money-label">Unitário/mês (R$)</span><?php if($priceReady):?><input class="sb-qty" style="width:110px" type="number" min="0" max="999999.99" step="0.01" name="billing_price[<?=$mid?>]" value="<?=sb_h(number_format($unit,2,'.',''))?>" data-billing-price title="Valor do Serviço: <?=sb_h(sb_money($servicePrice))?>" <?=$commercialReady?'':'disabled'?>><div class="sb-small">Serviço: <?=sb_money($servicePrice)?></div><?php else:?><strong><?=sb_money($unit)?></strong><?php endif;?></div>
            <div><span class="sb-money-label">Linha/mês</span><strong data-line-monthly><?=sb_money($value)?></strong></div>
            <div><input class="sb-courtesy-note" type="text" maxlength="500" name="courtesy_note[<?=$mid?>]" value="<?=sb_h($note)?>" placeholder="Motivo da cortesia (opcional)" <?=$checked?'disabled':''?>></div>
          </div>
          <?php endforeach;?>
          <div class="sb-billing-total"><span>Faturado/mês: <strong id="billingTotalCharged">—</strong></span><span>Cortesia/mês: <strong id="billingTotalCourtesy">—</strong></span></div>
        </div>
        <div class="sb-small" style="margin-top:6px">Desmarcado = módulo continua ativo no SaaS, mas não entra no contrato nem nas próximas cobranças. Cobranças já geradas nunca são reduzidas por esta seleção.</div>
      </div>
      <div class="sb-field sb-span"><input type="hidden" name="sync_modules" value="1"><div class="sb-note"><strong>Sincronização controlada:</strong> ao confirmar, somente os itens do contrato reconhecidos pelo mapa Módulo SaaS ↔ Serviço serão ajustados em quantidade, período e valor do próximo ciclo. Serviços avulsos/externos ao SaaS continuam intocados. Nenhum Pedido ou cobrança é criado nesta ação.</div></div>
    </div>
    <div style="height:14px"></div><button class="btn2 btn2-dark" type="submit" <?=($commercialReady&&$adjustmentReady)?'':'disabled title="Aplique as migrations pendentes da integração SaaS"'?>><?=$activateMode?'Confirmar contrato e ativar assinatura':'Confirmar vinculação segura'?></button>
  </form>
  <?php endif;?>
</section>
<?php endif;?>

<div class="content-grid" style="margin-top:16px">
  <?php if($section==='faturamento'):?>
  <section class="panel sb-section-card">
    <h3>Empresa faturadora exclusiva</h3>
    <p class="muted">A integração usa uma única empresa do Admin. Pessoa, Contrato e futuros Pedidos/Financeiro serão sempre gravados neste company_id; nunca na empresa cliente.</p>
    <?php if($ready):?>
    <form method="post"><input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="faturamento"><input type="hidden" name="action" value="save_config">
      <div class="sb-fields">
        <div class="sb-field sb-span"><label>Empresa faturadora</label><select name="billing_company_id" required><option value="">Selecione...</option><?php foreach($companies as $c):?><option value="<?=(int)$c['id']?>" <?=$billingCompanyId===(int)$c['id']?'selected':''?>><?=sb_h($c['name'])?> · #<?=(int)$c['id']?><?=!empty($c['cnpj'])?' · '.sb_h($c['cnpj']):''?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Trial (dias)</label><input type="number" min="1" max="60" name="trial_days" value="<?=(int)($cfg['trial_days']??7)?>"></div>
        <div class="sb-field"><label>Preparar cobrança antes (dias)</label><input type="number" min="0" max="30" name="charge_lead_days" value="<?=(int)($cfg['charge_lead_days']??2)?>"></div>
        <div class="sb-field"><label>Carência após vencimento (dias)</label><input type="number" min="0" max="30" name="grace_days" value="<?=(int)($cfg['grace_days']??3)?>"></div>
        <div class="sb-field"><label>Ativação</label><label style="display:flex;gap:8px;align-items:center;height:44px"><input style="width:auto" type="checkbox" name="enabled" value="1" <?=!empty($cfg['enabled'])?'checked':''?>> Integração habilitada</label></div>
        <div class="sb-field"><label>Forma de pagamento padrão</label><select name="default_payment_method_id"><option value="">Definir depois</option><?php foreach($payments as $r):?><option value="<?=(int)$r['id']?>" <?=(int)($cfg['default_payment_method_id']??0)===(int)$r['id']?'selected':''?>><?=sb_h($r['name'])?><?=!empty($r['auto_register_boleto'])?' · boleto automático':''?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Caixa/Banco padrão</label><select name="default_bank_account_id"><option value="">Definir depois</option><?php foreach($banks as $r):?><option value="<?=(int)$r['id']?>" <?=(int)($cfg['default_bank_account_id']??0)===(int)$r['id']?'selected':''?>><?=sb_h($r['name'])?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Plano de contas</label><select name="default_chart_account_id"><option value="">Definir depois</option><?php foreach($charts as $r):?><option value="<?=(int)$r['id']?>" <?=(int)($cfg['default_chart_account_id']??0)===(int)$r['id']?'selected':''?>><?=sb_h(($r['code']??'').' · '.$r['name'])?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Centro de custo</label><select name="default_cost_center_id"><option value="">Opcional</option><?php foreach($costs as $r):?><option value="<?=(int)$r['id']?>" <?=(int)($cfg['default_cost_center_id']??0)===(int)$r['id']?'selected':''?>><?=sb_h(($r['code']??'').' · '.$r['name'])?></option><?php endforeach;?></select></div>
      </div>
      <div style="height:14px"></div><button class="btn2 btn2-dark">Salvar configuração</button>
    </form>
    <?php endif;?>
  </section>

  <?php $crmCfg=saas_crm_lead_config($pdo);$crmOpts=saas_crm_lead_options($pdo,$billingCompanyId);?>
  <section class="panel sb-section-card">
    <h3>Atendimento no CRM (novos cadastros)</h3>
    <p class="muted">Cada empresa nova cadastrada no SaaS (direta ou por revenda) abre um atendimento no CRM da empresa faturadora, para acompanhar a negociação.</p>
    <?php if(!saas_crm_lead_ready($pdo)):?><div class="sb-note">O módulo CRM ainda não está instalado na empresa faturadora.</div><?php elseif($billingCompanyId<=0):?><div class="sb-note">Configure primeiro a empresa faturadora.</div><?php else:?>
    <form method="post"><input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="faturamento"><input type="hidden" name="action" value="save_crm_lead">
      <div class="sb-fields">
        <div class="sb-field"><label>Ativação</label><label style="display:flex;gap:8px;align-items:center;height:44px"><input style="width:auto" type="checkbox" name="crm_enabled" value="1" <?=!empty($crmCfg['enabled'])?'checked':''?>> Abrir atendimento</label></div>
        <div class="sb-field"><label>Status inicial (fase)</label><select name="crm_status_id"><option value="0">Primeiro status ativo</option><?php foreach($crmOpts['statuses'] as $r):?><option value="<?=(int)$r['id']?>" <?=(int)$crmCfg['status_id']===(int)$r['id']?'selected':''?>><?=sb_h($r['name'])?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Tipo de atendimento</label><select name="crm_service_type_id"><option value="0">Primeiro tipo ativo</option><?php foreach($crmOpts['types'] as $r):?><option value="<?=(int)$r['id']?>" <?=(int)$crmCfg['service_type_id']===(int)$r['id']?'selected':''?>><?=sb_h($r['name'])?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Responsável</label><select name="crm_responsible_user_id"><option value="0">Administrador da empresa</option><?php foreach($crmOpts['users'] as $r):?><option value="<?=(int)$r['id']?>" <?=(int)$crmCfg['responsible_user_id']===(int)$r['id']?'selected':''?>><?=sb_h($r['name'])?></option><?php endforeach;?></select></div>
        <div class="sb-field"><label>Temperatura do lead (0 a 100)</label><input type="number" min="0" max="100" name="crm_lead_temperature" value="<?=(int)$crmCfg['lead_temperature']?>"></div>
      </div>
      <div style="height:14px"></div><button class="btn2 btn2-dark">Salvar atendimento no CRM</button>
    </form>
    <?php endif;?>
  </section>

  <section class="panel sb-section-card">
    <h3>Regra operacional</h3>
    <div class="sb-note">Novos Trials: Empresa SaaS → Pessoa → atendimento no CRM, <strong>sem contrato</strong>. O contrato só é lançado na ativação (Central SaaS ou CRM, com conferência de módulos, quantidades e valores) ou quando o próprio cliente contrata pela tela de renovação.</div>
    <p><strong>Empresa vinculada:</strong> <?= $billingCompany?sb_h($billingCompany['name']).' (#'.(int)$billingCompany['id'].')':'Não configurada' ?></p>
    <p><strong>Automação:</strong> <span class="sb-status <?=!empty($cfg['enabled'])?'sb-on':'sb-off'?>"><?=!empty($cfg['enabled'])?'ATIVA':'DESATIVADA'?></span></p>
    <p class="muted">A vinculação manual pode ser preparada mesmo com a automação desativada. Confirmar o vínculo não gera Pedido, cobrança, boleto, PIX ou NFS-e; apenas prepara Pessoa, Contrato, módulos e datas para os próximos ciclos.</p>
  </section>
  <?php endif;?>

  <?php if($section==='periodos'):?>
  <?php if($commercialReady):?>
  <section class="panel sb-section-card">
    <h3>Períodos de renovação e descontos</h3>
    <p class="muted">O cliente pagará o período inteiro antecipadamente. O contrato mestre permanece o mesmo; cada pagamento libera exatamente o número de meses escolhido. Os percentuais abaixo serão usados nas próximas cobranças.</p>
    <form method="post"><input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="periodos"><input type="hidden" name="action" value="save_terms">
      <table class="sb-term-table"><thead><tr><th>Período</th><th>Meses liberados</th><th>Desconto</th><th>Disponível</th></tr></thead><tbody>
      <?php foreach($termOptions as $to):$tm=(int)$to['term_months'];?><tr><td><strong><?=sb_h($to['label'])?></strong></td><td><?=$tm?></td><td><input type="number" min="0" max="90" step="0.01" name="term_discount[<?=$tm?>]" value="<?=sb_h(number_format((float)$to['discount_percent'],2,'.',''))?>"> %</td><td><label class="sb-check" style="display:inline-flex;padding:6px 9px"><input type="checkbox" name="term_active[<?=$tm?>]" value="1" <?=!empty($to['active'])?'checked':''?>> Ativo</label></td></tr><?php endforeach;?>
      </tbody></table><div style="margin-top:10px"><button class="btn2 btn2-dark">Salvar períodos e descontos</button></div>
    </form>
  </section>
  <?php else:?><div class="warn">Aplique primeiro a migration V11.52.3 para configurar os períodos de renovação.</div><?php endif;?>
  <?php endif;?>

  <?php if($section==='modulos'):?>
  <section class="panel sb-section-card">
    <h3>Vínculo Módulos SaaS ↔ Serviços do Admin</h3>
    <p class="muted">Mapeie apenas módulos que devem ser cobrados. Módulos operacionais internos podem ficar sem vínculo.</p>
    <?php if(!$billingCompanyId):?><div class="warn">Selecione e salve primeiro a empresa faturadora em <strong>Configurações de Faturamento</strong>.</div><?php elseif(!$services):?><div class="warn">Nenhum produto ativo marcado como <strong>Serviço</strong> foi encontrado na empresa faturadora.</div><?php else:?>
    <form method="post"><input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="modulos"><input type="hidden" name="action" value="save_mapping">
      <div class="table-wrap"><table class="sb-map"><thead><tr><th>Módulo SaaS</th><th>Tipo</th><th>Preço SaaS</th><th>Serviço correspondente no Admin da Próspero</th></tr></thead><tbody>
      <?php foreach($modules as $m):$mapped=$map[(int)$m['id']]??null;?><tr><td><strong><?=sb_h($m['name'])?></strong><div class="sb-small"><?=sb_h($m['code'])?></div></td><td><?=sb_h($m['item_type']??'module')?></td><td><?=sb_money($m['monthly_price']??0)?></td><td><select name="product_map[<?=(int)$m['id']?>]"><option value="0">Não faturar / módulo interno</option><?php foreach($services as $s):?><option value="<?=(int)$s['id']?>" <?=($mapped&&(int)$mapped['product_id']===(int)$s['id'])?'selected':''?>><?=sb_h($s['nome'])?> · <?=sb_money($s['preco'])?><?=trim((string)$s['numero_cadastro'])!==''?' · cód. '.sb_h($s['numero_cadastro']):''?></option><?php endforeach;?></select></td></tr><?php endforeach;?>
      </tbody></table></div><div style="height:14px"></div><button class="btn2 btn2-dark">Salvar vínculos</button>
    </form>
    <?php endif;?>
  </section>
  <?php endif;?>

  <?php if($section==='contratos'):?>
  <section class="panel sb-section-card">
    <h3>Vincular Contratos</h3>
    <p class="muted">Pesquise a empresa e faça a sincronização do Trial ou a vinculação/criação segura da Pessoa e do Contrato no Admin. Nenhuma cobrança é criada nesta tela.</p>
    <form method="get" class="sb-company-search">
      <input type="hidden" name="section" value="contratos">
      <div><label>Pesquisar empresa</label><input type="search" name="q_company" value="<?=sb_h($companySearch)?>" placeholder="Digite nome, slug ou código da empresa" autofocus></div>
      <button class="btn2 btn2-dark" type="submit">Pesquisar</button>
      <a class="btn2 btn2-ghost" href="assinaturas_nexa.php?section=contratos">Limpar</a>
    </form>
    <?php if($companySearch!==''):?><div class="sb-small" style="margin:-4px 0 10px">Resultado da pesquisa por <strong><?=sb_h($companySearch)?></strong>: <?=count($links)?> empresa(s).</div><?php endif;?>
    <div class="table-wrap"><table class="sb-table"><thead><tr><th>Empresa SaaS</th><th>Plano / assinatura</th><th>Vencimento SaaS</th><th>Integração</th><th>Pessoa / Contrato</th><th>Próximo ciclo integrado</th><th>Ação</th></tr></thead><tbody>
    <?php if(!$links):?><tr><td colspan="7" class="muted">Nenhuma empresa encontrada para a pesquisa informada.</td></tr><?php endif;?>
    <?php foreach($links as $r):$subStatus=strtolower((string)($r['subscription_status']??''));?><tr><td><strong><?=sb_h($r['name'])?></strong><div class="sb-small">#<?=(int)$r['id']?> · <?=sb_h($r['slug'])?></div></td><td><?=sb_h($r['plan_name']??'—')?><div class="sb-small"><?=sb_h($r['subscription_status']??'sem assinatura')?></div></td><td><?=sb_date($r['next_billing_at']??($r['ends_at']??null))?></td><td><?php if($r['link_id']):?><span class="sb-status sb-on"><?=sb_h($r['lifecycle_status']??'')?></span><?php else:?><span class="sb-status sb-off">Não vinculada</span><?php endif;?><?php if(!empty($r['last_error'])):?><div class="sb-error"><?=sb_h($r['last_error'])?></div><?php endif;?></td><td><?php if($r['link_id']):?>Pessoa #<?=(int)$r['person_id']?><br><?php if($r['contract_id']):?>Contrato <?=!empty($r['contract_number'])?'#'.sb_h($r['contract_number']):'#'.(int)$r['contract_id']?> · <?=sb_h($r['contract_status']??'')?><?php else:?>Contrato pendente<?php endif;?><?php else:?>—<?php endif;?></td><td><?=!empty($r['next_due_date'])?sb_date($r['next_due_date']):'—'?><?php if(!empty($r['renewal_term_months'])):?><div class="sb-small">renovação: <?=(int)$r['renewal_term_months']?> mês(es)<?=((float)($r['renewal_discount_percent']??0))>0?' · '.number_format((float)$r['renewal_discount_percent'],2,',','.').'% desc.':''?></div><?php endif;?><?php if(!empty($r['next_adjustment_date'])):?><div class="sb-small">reajuste em <?=sb_date($r['next_adjustment_date'])?></div><?php endif;?><?php if(!empty($r['grace_until'])):?><div class="sb-small">carência até <?=sb_date($r['grace_until'])?></div><?php endif;?></td><td><div class="sb-actions"><?php if($subStatus==='trial' && $ready && !empty($cfg['enabled'])):?><form method="post" style="margin:0"><input type="hidden" name="csrf" value="<?=sb_h($csrf)?>"><input type="hidden" name="section" value="contratos"><input type="hidden" name="action" value="sync_company"><input type="hidden" name="saas_company_id" value="<?=(int)$r['id']?>"><button class="mini mini-primary"><?=empty($r['link_id'])?'Sincronizar Trial':'Atualizar Trial'?></button></form><?php endif;?><?php if($ready && $billingCompanyId>0 && $r['subscription_id'] && !in_array($subStatus,['canceled','cancelled'],true)):?><a class="mini" href="assinaturas_nexa.php?section=contratos&amp;migrate=<?=(int)$r['id']?>#migracaoExistente"><?=empty($r['link_id'])?'Vincular / criar no Admin':'Revisar vínculo'?></a><?php endif;?><?php if($subStatus!=='trial' && empty($r['link_id']) && $r['subscription_id']):?><div class="sb-small">Assinatura <?=sb_h($subStatus)?>: não usa Sincronizar Trial.</div><?php endif;?></div></td></tr><?php endforeach;?>
    </tbody></table></div>
  </section>
  <?php endif;?>
</div>

<div id="personModal" class="sb-modal-bg"><div class="sb-modal"><div class="sb-modal-head"><strong>Buscar Pessoa da empresa faturadora</strong><span style="flex:1"></span><button type="button" class="btn2 btn2-ghost" data-close="personModal">Fechar</button></div><div class="sb-modal-body"><div class="sb-inline"><input id="personQ" placeholder="Nome, CNPJ/CPF ou e-mail"><button type="button" class="btn2 btn2-dark" id="personQBtn">Pesquisar</button></div><div class="sb-small">Pesquisa vazia ao abrir. Enter pesquisa; ↑/↓ percorrem; Enter seleciona.</div><div id="personResults" class="sb-results"></div></div></div></div>
<div id="contractModal" class="sb-modal-bg"><div class="sb-modal"><div class="sb-modal-head"><strong>Buscar contrato da Pessoa</strong><span style="flex:1"></span><button type="button" class="btn2 btn2-ghost" data-close="contractModal">Fechar</button></div><div class="sb-modal-body"><div class="sb-inline"><input id="contractQ" placeholder="Número do contrato ou cliente"><button type="button" class="btn2 btn2-dark" id="contractQBtn">Pesquisar</button></div><div class="sb-small">Somente contratos da Pessoa selecionada e da empresa faturadora. Contratos cancelados não são exibidos.</div><div id="contractResults" class="sb-results"></div></div></div></div>
<script>
(()=>{
 const root=document;let rows=[],idx=-1,mode='';
 const esc=s=>String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]));
 function open(id,input){document.getElementById(id)?.classList.add('open');if(input){input.value='';setTimeout(()=>input.focus(),40)}rows=[];idx=-1;const out=document.getElementById(id==='personModal'?'personResults':'contractResults');if(out)out.innerHTML='';}
 function close(id){document.getElementById(id)?.classList.remove('open')}
 document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>close(b.dataset.close)));
 function draw(id,data,type){rows=data;idx=data.length?0:-1;mode=type;document.getElementById(id).innerHTML=data.map((r,i)=>`<div class="sb-result ${i===0?'sel':''}" data-i="${i}"><strong>${type==='person'?esc(r.name):'Contrato #'+esc(r.contract_number)+' · '+esc(r.status)}</strong><div class="sb-small">${type==='person'?esc((r.document||'')+' · '+(r.email||'')):esc((r.contracting_person_name||r.billing_person_name||'')+' · R$ '+Number(r.total_amount||0).toFixed(2).replace('.',','))}</div>${type==='contract'&&Number(r.linked_saas_company_id||0)>0?`<div class="sb-error">Já vinculado à empresa SaaS #${Number(r.linked_saas_company_id)}</div>`:''}</div>`).join('')||'<div class="sb-result sb-small">Nenhum registro encontrado.</div>';document.querySelectorAll('#'+id+' [data-i]').forEach(el=>el.addEventListener('click',()=>choose(Number(el.dataset.i))));}
 function mark(id){document.querySelectorAll('#'+id+' [data-i]').forEach((e,i)=>e.classList.toggle('sel',i===idx));document.querySelector('#'+id+' [data-i="'+idx+'"]')?.scrollIntoView({block:'nearest'});}
 function choose(i){const r=rows[i];if(!r)return;if(mode==='person'){document.getElementById('migPersonId').value=r.id;document.getElementById('migPersonName').value='#'+r.id+' · '+r.name+' · '+(r.document||'');document.getElementById('migContractId').value='';document.getElementById('migContractName').value='';close('personModal');}else{if(Number(r.linked_saas_company_id||0)>0&&Number(r.linked_saas_company_id)!==<?= (int)$migrateId ?>){alert('Este contrato já está vinculado à empresa SaaS #'+r.linked_saas_company_id+'.');return;}document.getElementById('migContractId').value=r.id;document.getElementById('migContractName').value='Contrato #'+r.contract_number+' · '+r.status+' · R$ '+Number(r.total_amount||0).toFixed(2).replace('.',',');const adj=document.getElementById('migNextAdjustment');if(adj&&!adj.value){if(r.next_adjustment_date)adj.value=String(r.next_adjustment_date).slice(0,10);else if(r.validity_start){const d=new Date(String(r.validity_start).slice(0,10)+'T12:00:00');if(!Number.isNaN(d.getTime())){d.setFullYear(d.getFullYear()+1);adj.value=d.toISOString().slice(0,10);}}}close('contractModal');}}
 async function people(){const q=document.getElementById('personQ').value;const j=await fetch('assinaturas_nexa.php?ajax=people&q='+encodeURIComponent(q)).then(r=>r.json());if(!j.ok)throw new Error(j.message||'Falha');draw('personResults',j.rows||[],'person')}
 async function contracts(){const pid=Number(document.getElementById('migPersonId')?.value||0);if(!pid){alert('Selecione primeiro a Pessoa.');return;}const q=document.getElementById('contractQ').value;const j=await fetch('assinaturas_nexa.php?ajax=contracts&person_id='+pid+'&q='+encodeURIComponent(q)).then(r=>r.json());if(!j.ok)throw new Error(j.message||'Falha');draw('contractResults',j.rows||[],'contract')}
 document.getElementById('migPersonSearch')?.addEventListener('click',()=>open('personModal',document.getElementById('personQ')));
 document.getElementById('migContractSearch')?.addEventListener('click',()=>{if(document.getElementById('migCreateContract')?.checked){alert('Desmarque “Criar um novo contrato” para selecionar um contrato existente.');return;}open('contractModal',document.getElementById('contractQ'));});
 document.getElementById('personQBtn')?.addEventListener('click',()=>people().catch(e=>alert(e.message)));document.getElementById('contractQBtn')?.addEventListener('click',()=>contracts().catch(e=>alert(e.message)));
 [['personQ',people,'personResults'],['contractQ',contracts,'contractResults']].forEach(([id,fn,list])=>{const e=document.getElementById(id);if(!e)return;e.addEventListener('keydown',ev=>{if(ev.key==='ArrowDown'&&rows.length){ev.preventDefault();idx=Math.min(rows.length-1,idx+1);mark(list)}else if(ev.key==='ArrowUp'&&rows.length){ev.preventDefault();idx=Math.max(0,idx-1);mark(list)}else if(ev.key==='Enter'){ev.preventDefault();if(rows.length&&idx>=0&&e.dataset.last===e.value)choose(idx);else{e.dataset.last=e.value;fn().catch(er=>alert(er.message))}}});e.addEventListener('input',()=>{e.dataset.last='__dirty__';rows=[];idx=-1})});
 const createPerson=document.getElementById('migCreatePerson'),createContract=document.getElementById('migCreateContract'),personSearch=document.getElementById('migPersonSearch'),contractSearch=document.getElementById('migContractSearch');
 function syncCreateMode(){const cp=!!createPerson?.checked,cc=!!createContract?.checked;if(personSearch)personSearch.disabled=cp;if(cp){document.getElementById('migPersonId').value='';document.getElementById('migPersonName').value='Pessoa será criada/reaproveitada automaticamente a partir do SaaS';if(createContract&&!createContract.checked)createContract.checked=true;}if(contractSearch)contractSearch.disabled=cc||cp;if(cc){document.getElementById('migContractId').value='';document.getElementById('migContractName').value='Novo contrato mestre será criado automaticamente';}else if(!document.getElementById('migContractId').value)document.getElementById('migContractName').value='';}
 createPerson?.addEventListener('change',syncCreateMode);createContract?.addEventListener('change',syncCreateMode);syncCreateMode();
 const due=document.getElementById('migNextDue'),grace=document.getElementById('migGrace'),periodStart=document.querySelector('[name="current_period_start"]'),adj=document.getElementById('migNextAdjustment');due?.addEventListener('change',()=>{if(!due.value||!grace)return;const d=new Date(due.value+'T12:00:00');if(Number.isNaN(d.getTime()))return;d.setDate(d.getDate()+<?=max(0,(int)($cfg['grace_days']??3))?>);grace.value=d.toISOString().slice(0,10)});periodStart?.addEventListener('change',()=>{if(!periodStart.value||!adj||adj.value)return;const d=new Date(periodStart.value+'T12:00:00');if(Number.isNaN(d.getTime()))return;d.setFullYear(d.getFullYear()+1);adj.value=d.toISOString().slice(0,10)});
 function brl(v){return 'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});}
 function updateBillingPolicy(){let charged=0,courtesy=0;document.querySelectorAll('[data-module-row]').forEach(row=>{const cb=row.querySelector('[data-billable-toggle]');const note=row.querySelector('.sb-courtesy-note');const qtyEl=row.querySelector('[data-billing-qty]');const priceEl=row.querySelector('[data-billing-price]');let unit=Number(priceEl?priceEl.value:(row.dataset.unit||0));if(!Number.isFinite(unit)||unit<0)unit=0;let qty=Number(qtyEl?.value||1);if(!Number.isFinite(qty)||qty<1)qty=1;if(qty>999)qty=999;if(qtyEl&&Number(qtyEl.value)!==qty)qtyEl.value=String(qty);const value=unit*qty;const line=row.querySelector('[data-line-monthly]');if(line)line.textContent=brl(value);const on=!!cb?.checked;row.classList.toggle('is-courtesy',!on);if(note)note.disabled=on;if(on)charged+=value;else courtesy+=value;});const a=document.getElementById('billingTotalCharged'),b=document.getElementById('billingTotalCourtesy');if(a)a.textContent=brl(charged);if(b)b.textContent=brl(courtesy);const term=document.getElementById('renewalTerm');const opt=term?.selectedOptions?.[0];const months=Number(opt?.dataset.months||1);const disc=Number(opt?.dataset.discount||0);const gross=charged*months;const discount=gross*(disc/100);const net=Math.max(0,gross-discount);const mb=document.getElementById('termMonthlyBase'),dv=document.getElementById('termDiscountValue'),ct=document.getElementById('termCycleTotal');if(mb)mb.textContent=brl(charged);if(dv)dv.textContent=brl(discount)+(disc>0?' ('+disc.toLocaleString('pt-BR',{maximumFractionDigits:2})+'%)':'');if(ct)ct.textContent=brl(net);}
 document.querySelectorAll('[data-billable-toggle],[data-billing-qty],[data-billing-price]').forEach(el=>el.addEventListener('change',updateBillingPolicy));document.querySelectorAll('[data-billing-qty],[data-billing-price]').forEach(el=>el.addEventListener('input',updateBillingPolicy));document.getElementById('renewalTerm')?.addEventListener('change',updateBillingPolicy);updateBillingPolicy();
 document.getElementById('migrationForm')?.addEventListener('submit',ev=>{const cp=document.getElementById('migCreatePerson')?.checked;const pid=Number(document.getElementById('migPersonId')?.value||0);const create=document.getElementById('migCreateContract')?.checked;const cid=Number(document.getElementById('migContractId')?.value||0);if(!cp&&!pid){ev.preventDefault();alert('Selecione a Pessoa existente ou marque a opção para criá-la automaticamente.');return;}if(!create&&!cid){ev.preventDefault();alert('Selecione o contrato existente ou marque a opção para criar um novo contrato.');return;}if(!confirm('Confirmar vínculo/criação no Admin, quantidades, cortesia, próximo reajuste e período da próxima renovação? Esta ação NÃO gera Pedido, boleto, PIX ou cobrança. Cobranças já existentes permanecem inalteradas.'))ev.preventDefault();});
 document.addEventListener('keydown',ev=>{if(ev.key==='F8'&&document.getElementById('migrationForm')){ev.preventDefault();document.getElementById('migPersonSearch')?.click()}if(ev.key==='Escape'){close('personModal');close('contractModal')}});
})();
</script>
<?php saas_layout_end(); ?>
