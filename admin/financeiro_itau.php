<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/billing_module.php';
require_once __DIR__ . '/../config/itau_boleto.php';
require_once __DIR__ . '/../config/itau_boleto_v3.php';
require_once __DIR__ . '/../config/itau_bolecode.php';
require_once __DIR__ . '/../config/itau_webhook.php';
require_once __DIR__ . '/../config/itau_cash_query.php';
$itauCrossContext = function_exists('finance_multi_company_billing_config_enabled') && finance_multi_company_billing_config_enabled($pdo);
$itauCrossSettlement = function_exists('finance_multi_company_itau_settlement_enabled') && finance_multi_company_itau_settlement_enabled($pdo);
if (!$itauCrossContext) {
  billing_ensure_schema($pdo);
} elseif (!billing_table_exists($pdo,'finance_bank_billing_configs')) {
  throw new RuntimeException('A estrutura de Cobranças/Boletos ainda não está disponível. Aplique as migrations pelo Atualizador do Banco antes de configurar o Itaú pelo F11.');
}
$cashCredentialSchemaReady = billing_has_column($pdo,'finance_bank_billing_configs','itau_cash_client_id') && billing_has_column($pdo,'finance_bank_billing_configs','itau_cash_client_secret');

$cid=function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
if($itauCrossContext && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'financeiro_caixas')){
  throw new RuntimeException('Seu perfil não possui permissão para configurar Caixas/Bancos na empresa selecionada pelo F11.');
}
$bankId=(int)($_GET['bank']??$_POST['bank']??0);
if($bankId<=0){finance_redirect('financeiro_caixas_bancos.php?err='.rawurlencode('Selecione uma conta bancária Itaú.'));}
$bankCheck=$pdo->prepare('SELECT id FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1');
$bankCheck->execute([$cid,$bankId]);
if(!$bankCheck->fetchColumn()){finance_redirect('financeiro_caixas_bancos.php?err='.rawurlencode('A conta bancária não pertence à empresa selecionada.'));}
$cfg=billing_bank_config($pdo,$cid,$bankId);
if(!$cfg || strtoupper((string)($cfg['provider']??''))!=='ITAU' || strtoupper((string)($cfg['integration_mode']??''))!=='API'){
  finance_redirect('financeiro_caixas_bancos.php?err='.rawurlencode('A conta selecionada não está configurada como Itaú / API.'));
}
$st=$pdo->prepare('SELECT name,cidade,estado FROM companies WHERE id=? LIMIT 1');$st->execute([$cid]);$company=$st->fetch(PDO::FETCH_ASSOC)?:[];
$msg='';$err='';$technical='';

try{
  if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    $action=(string)($_POST['action']??'save');
    if($itauCrossContext){
      $csrf=trim((string)($_POST['mc_csrf']??''));
      if(!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) throw new RuntimeException('Sessão Multiempresa expirada ou inválida. Atualize a página e tente novamente.');
      $crossAllowed=['save_environment','save','enroll_certificate','test_token','prepare_webhook','register_webhook','consult_webhook','save_bank_fee_chart','consult_francesa','consult_movements','consult_movements_summary'];
      if($itauCrossSettlement){$crossAllowed[]='reprocess_webhook_logs';$crossAllowed[]='sync_liquidations';}
      if(!in_array($action,$crossAllowed,true)) throw new RuntimeException('V11.20: esta operação Itaú ainda não está homologada para execução pelo F11.');
      if(in_array($action,['reprocess_webhook_logs','sync_liquidations'],true)){
        if(!$itauCrossSettlement) throw new RuntimeException('Seu perfil não possui autorização para processar liquidações Itaú na empresa selecionada pelo F11.');
        if(function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_baixar_receber')) throw new RuntimeException('Seu usuário não possui permissão para baixar Contas a Receber nesta empresa.');
        if(function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo,'financeiro_boletos')) throw new RuntimeException('Seu usuário não possui permissão para Cobranças/Boletos nesta empresa.');
      }
    }
    if($action==='save_environment'){
      $environment=strtoupper(trim((string)($_POST['billing_environment']??'')));
      if(!in_array($environment,['HOMOLOGACAO','PRODUCAO'],true)) throw new RuntimeException('Ambiente Itaú inválido.');
      $pdo->prepare('UPDATE finance_bank_billing_configs SET environment=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')->execute([$environment,$cid,$bankId]);
      $verify=$pdo->prepare('SELECT environment FROM finance_bank_billing_configs WHERE company_id=? AND bank_account_id=? LIMIT 1');
      $verify->execute([$cid,$bankId]);
      $saved=strtoupper((string)($verify->fetchColumn()?:''));
      if($saved!==$environment) throw new RuntimeException('Não foi possível confirmar a gravação do ambiente Itaú.');
      $msg='Ambiente Itaú alterado para '.($environment==='PRODUCAO'?'Produção':'Homologação').'.';
    }elseif($action==='save'){
      $clientId=trim((string)($_POST['api_client_id']??($cfg['api_client_id']??'')));
      $cashClientId=$cashCredentialSchemaReady?trim((string)($_POST['itau_cash_client_id']??($cfg['itau_cash_client_id']??''))):'';
      $cashSecret='';
      if($cashCredentialSchemaReady){
        $cashSecret=trim((string)($_POST['itau_cash_client_secret']??''));
        if($cashSecret==='')$cashSecret=(string)($cfg['itau_cash_client_secret']??'');
      }
      $oauthUrl=trim((string)($_POST['oauth_url']??($cfg['oauth_url']??'')));
      $apiBase=trim((string)($_POST['api_base_url']??($cfg['api_base_url']??'')));
      $beneficiary=preg_replace('/\D+/','',(string)($_POST['itau_beneficiary_id']??($cfg['itau_beneficiary_id']??'')))?:'';
      $wallet=preg_replace('/\D+/','',(string)($_POST['carteira']??($cfg['carteira']??'')))?:'';
      $species=preg_replace('/\D+/','',(string)($_POST['itau_species_code']??($cfg['itau_species_code']??'01')))?:'01';
      $nextNosso=array_key_exists('itau_nosso_numero_next',$_POST)?(int)$_POST['itau_nosso_numero_next']:(int)($cfg['itau_nosso_numero_next']??0);
      $whEnabled=array_key_exists('itau_webhook_enabled',$_POST)?(!empty($_POST['itau_webhook_enabled'])?1:0):(int)($cfg['itau_webhook_enabled']??0);
      $whClientId=trim((string)($_POST['itau_webhook_client_id']??($cfg['itau_webhook_client_id']??'')));
      $whSecret=trim((string)($_POST['itau_webhook_client_secret']??''));
      if($whSecret==='')$whSecret=(string)($cfg['itau_webhook_client_secret']??'');
      $whUrl=trim((string)($_POST['itau_webhook_url']??($cfg['itau_webhook_url']??'')));
      $whOauth=trim((string)($_POST['itau_webhook_oauth_url']??($cfg['itau_webhook_oauth_url']??'')));
      $whScope=trim((string)($_POST['itau_webhook_oauth_scope']??($cfg['itau_webhook_oauth_scope']??'')));
      $types=array_key_exists('notify_efetiva',$_POST)||array_key_exists('notify_operacional',$_POST)?[]:array_values(array_filter(explode(',',(string)($cfg['itau_webhook_notifications']??''))));
      // R8: ao habilitar o Webhook, prepara automaticamente as credenciais internas
      // necessárias para o Itaú autenticar no callback. Isso elimina a dependência
      // de clicar antes em "Preparar credenciais".
      if($whEnabled){
        if($whClientId==='')$whClientId=itau_webhook_uuid();
        if($whSecret==='')$whSecret=bin2hex(random_bytes(32));
        $whDefaults=itau_webhook_default_urls();
        if($whUrl==='')$whUrl=(string)$whDefaults['webhook_url'];
        if($whOauth==='')$whOauth=(string)$whDefaults['webhook_oauth_url'];
      }
      if(array_key_exists('notify_efetiva',$_POST)||array_key_exists('notify_operacional',$_POST)){
        if(!empty($_POST['notify_efetiva']))$types[]='BAIXA_EFETIVA';if(!empty($_POST['notify_operacional']))$types[]='BAIXA_OPERACIONAL';
      }
      if($wallet!==''&&!preg_match('/^\d{3}$/',$wallet))throw new RuntimeException('Carteira Itaú deve possuir 3 dígitos.');
      if(!preg_match('/^\d{2}$/',$species))throw new RuntimeException('Código da espécie deve possuir 2 dígitos.');
      if($nextNosso<0||$nextNosso>99999999)throw new RuntimeException('Próximo Nosso Número deve estar entre 1 e 99999999.');
      $pdo->prepare('UPDATE finance_bank_billing_configs SET api_client_id=?,oauth_url=?,api_base_url=?,itau_beneficiary_id=?,carteira=?,itau_species_code=?,itau_nosso_numero_next=?,itau_webhook_enabled=?,itau_webhook_client_id=?,itau_webhook_client_secret=?,itau_webhook_url=?,itau_webhook_oauth_url=?,itau_webhook_oauth_scope=?,itau_webhook_notifications=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')
          ->execute([$clientId?:null,$oauthUrl?:null,$apiBase?:null,$beneficiary?:null,$wallet?:null,$species,$nextNosso>0?$nextNosso:null,$whEnabled,$whClientId?:null,$whSecret?:null,$whUrl?:null,$whOauth?:null,$whScope?:null,implode(',',$types)?:null,$cid,$bankId]);
      if($cashCredentialSchemaReady){
        $pdo->prepare('UPDATE finance_bank_billing_configs SET itau_cash_client_id=?,itau_cash_client_secret=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')
            ->execute([$cashClientId?:null,$cashSecret?:null,$cid,$bankId]);
      }
      $msg='Configurações Itaú salvas.';
    }elseif($action==='enroll_certificate'){
      $clientId=trim((string)($_POST['api_client_id']??$cfg['api_client_id']??''));
      $ou=trim((string)($_POST['csr_ou']??''));$city=trim((string)($_POST['csr_city']??''));$uf=trim((string)($_POST['csr_state']??''));$temporary=trim((string)($_POST['temporary_token']??''));
      if($clientId==='')throw new RuntimeException('Informe o Client ID antes de solicitar o certificado.');
      // O token temporário é usado apenas nesta requisição e nunca é persistido.
      $result=itau_boleto_enroll_certificate($cid,$bankId,$clientId,$ou,$city,$uf,$temporary);
      $pdo->prepare('UPDATE finance_bank_billing_configs SET api_client_id=?,api_client_secret=?,cert_path=?,key_path=?,oauth_url=COALESCE(NULLIF(oauth_url,\'\'),?),updated_at=NOW() WHERE company_id=? AND bank_account_id=?')
          ->execute([$clientId,(string)$result['client_secret'],(string)$result['cert_path'],(string)$result['key_path'],itau_boleto_default_oauth_url(),$cid,$bankId]);
      $days=$result['certificate']['days_remaining']??null;
      $msg='Certificado Itaú emitido e salvo com segurança. Client Secret recebido e armazenado.'.(is_int($days)?' Validade aproximada: '.$days.' dia(s).':'');
    }elseif($action==='test_token'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);$r=itau_boleto_test_token($cfg);
      $msg='OAuth/mTLS validado com sucesso. Token de acesso recebido por '.(int)$r['expires_in'].' segundo(s).';
    }elseif($action==='prepare_webhook'){
      $cfg=itau_webhook_prepare_credentials($pdo,$cid,$bankId);
      $msg='Credenciais e URLs do Webhook Itaú preparadas. Revise, habilite e salve antes de registrar no banco.';
    }elseif($action==='register_webhook'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);
      if(empty($cfg['itau_webhook_enabled']))throw new RuntimeException('Habilite o Webhook Itaú e clique em Salvar Webhook antes de registrar.');
      if(trim((string)($cfg['itau_beneficiary_id']??''))==='')throw new RuntimeException('Informe o ID Beneficiário Itaú e salve antes de registrar o Webhook.');
      // R8: se a conta veio de uma versão anterior sem Client ID/Secret do callback,
      // prepara e persiste automaticamente antes do POST no Itaú.
      if(trim((string)($cfg['itau_webhook_client_id']??''))==='' || trim((string)($cfg['itau_webhook_client_secret']??''))==='' || trim((string)($cfg['itau_webhook_url']??''))==='' || trim((string)($cfg['itau_webhook_oauth_url']??''))===''){
        $cfg=itau_webhook_prepare_credentials($pdo,$cid,$bankId);
      }
      $types=array_values(array_filter(explode(',',(string)($cfg['itau_webhook_notifications']??''))));
      if(!$types){
        $types=['BAIXA_EFETIVA','BAIXA_OPERACIONAL'];
        $pdo->prepare('UPDATE finance_bank_billing_configs SET itau_webhook_notifications=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')->execute([implode(',',$types),$cid,$bankId]);
        $cfg=billing_bank_config($pdo,$cid,$bankId);
      }
      $r=itau_v3_register_webhook($cfg,[
        'id_beneficiario'=>(string)($cfg['itau_beneficiary_id']??''),
        'webhook_url'=>(string)($cfg['itau_webhook_url']??''),
        'webhook_client_id'=>(string)($cfg['itau_webhook_client_id']??''),
        'webhook_client_secret'=>(string)($cfg['itau_webhook_client_secret']??''),
        'webhook_oauth_url'=>(string)($cfg['itau_webhook_oauth_url']??''),
        'webhook_oauth_scope'=>(string)($cfg['itau_webhook_oauth_scope']??''),
        'tipos_notificacoes'=>$types,
      ]);
      $pdo->prepare('UPDATE finance_bank_billing_configs SET itau_webhook_registered_at=NOW(),updated_at=NOW() WHERE company_id=? AND bank_account_id=?')->execute([$cid,$bankId]);
      $technical=(string)($r['body']??'');$msg='Webhook de cobrança registrado no Itaú com sucesso.';
    }elseif($action==='consult_webhook'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);$r=itau_v3_get_webhooks($cfg,(string)($cfg['itau_beneficiary_id']??''));$technical=(string)($r['body']??'');$msg=((int)($r['status']??200)===404)?'Nenhum Webhook de cobrança está cadastrado no Itaú para este beneficiário. Prepare as credenciais, habilite os eventos, salve e registre o Webhook.':'Consulta de Webhook concluída.';
    }elseif($action==='reprocess_webhook_logs'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);
      if($itauCrossContext)$cfg['_nexa_skip_schema_ensure']=1;
      $r=itau_webhook_reprocess_pending_logs($pdo,$cfg,200);
      if((int)($r['found']??0)<=0){
        $msg='Não há notificações Itaú pendentes de processamento para esta conta.';
      }else{
        $msg=(int)($r['processed']??0).' notificação(ões) processada(s), '.(int)($r['pending']??0).' pendente(s) e '.(int)($r['errors']??0).' com erro.';
        if((int)($r['errors']??0)>0)$err='Há retorno(s) Itaú que não puderam ser baixados automaticamente. Consulte a fila de boletos e os logs antes de repetir a operação.';
      }
    }elseif($action==='save_bank_fee_chart'){
      $chartId=(int)($_POST['itau_bank_fee_chart_account_id']??0);
      if($chartId>0){
        $st=$pdo->prepare("SELECT id FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND is_analytic=1 AND movement_type IN ('DEBITO','AMBOS') LIMIT 1");
        $st->execute([$cid,$chartId]);if(!(int)($st->fetchColumn()?:0))throw new RuntimeException('Selecione um plano analítico que aceite movimento de débito para a tarifa bancária.');
      }
      $pdo->prepare('UPDATE finance_bank_billing_configs SET itau_bank_fee_chart_account_id=?,updated_at=NOW() WHERE company_id=? AND bank_account_id=?')->execute([$chartId?:null,$cid,$bankId]);
      $msg='Plano de contas para tarifas bancárias salvo.';
    }elseif($action==='sync_liquidations'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);if($itauCrossContext)$cfg['_nexa_skip_schema_ensure']=1;$date=trim((string)($_POST['data_movimentacao']??''));
      $r=itau_webhook_sync_liquidations($pdo,$cfg,$date,[
        'tipo_cobranca'=>trim((string)($_POST['tipo_cobranca']??'')),
        'nosso_numero'=>trim((string)($_POST['nosso_numero']??'')),
        'seu_numero'=>trim((string)($_POST['seu_numero']??'')),
        'numero_carteira'=>trim((string)($_POST['numero_carteira']??'')),
        'nome_pagador'=>trim((string)($_POST['nome_pagador']??'')),
      ]);
      $technical=(string)($r['raw']??'');
      $msg=(int)($r['found']??0).' liquidação(ões) encontrada(s); '.(int)($r['processed']??0).' baixada(s) no Nexa; '.(int)($r['already']??0).' já processada(s).';
      if((int)($r['pending']??0)>0||(int)($r['errors']??0)>0){
        $parts=[];foreach((array)($r['details']??[]) as $d){if(!in_array((string)($d['status']??''),['LIQUIDADO','JA_PAGO'],true))$parts[]='Nosso Número '.(string)($d['nosso_numero']??'').': '.((string)($d['message']??'')?: (string)($d['status']??'PENDENTE'));}
        if($parts)$err=implode(' | ',$parts);
      }
    }elseif($action==='consult_francesa'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);
      $month=trim((string)($_POST['mes_referencia']??''));
      $r=itau_v3_get_francesas($cfg,(string)($cfg['itau_beneficiary_id']??''),$month);
      $technical=(string)($r['body']??'');$msg='Consulta de posições/Francesa concluída.';
    }elseif($action==='consult_movements'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);
      $date=trim((string)($_POST['data_movimentacao']??''));
      $r=itau_v3_get_movements($cfg,(string)($cfg['itau_beneficiary_id']??''),$date,[
        'tipo_cobranca'=>trim((string)($_POST['tipo_cobranca']??'')),
        'tipo_movimentacao'=>trim((string)($_POST['tipo_movimentacao']??'')),
        'nosso_numero'=>trim((string)($_POST['nosso_numero']??'')),
        'seu_numero'=>trim((string)($_POST['seu_numero']??'')),
        'numero_carteira'=>trim((string)($_POST['numero_carteira']??'')),
        'nome_pagador'=>trim((string)($_POST['nome_pagador']??'')),
      ]);
      $technical=(string)($r['body']??'');$msg='Consulta de movimentações concluída.';
    }elseif($action==='consult_movements_summary'){
      $cfg=billing_bank_config($pdo,$cid,$bankId);
      $date=trim((string)($_POST['data_movimentacao']??''));
      $r=itau_v3_get_movements_summary($cfg,(string)($cfg['itau_beneficiary_id']??''),$date);
      $technical=(string)($r['body']??'');$msg='Consulta resumida de movimentações concluída.';
    }
  }
}catch(Throwable $e){$err=$e->getMessage();}
$cfg=billing_bank_config($pdo,$cid,$bankId);
$diag=itau_boleto_validate_config($cfg,false);
$cert=$diag['certificate']??[];
$defaults=itau_webhook_default_urls();
$whTypes=array_values(array_filter(explode(',',(string)($cfg['itau_webhook_notifications']??''))));
$csrOu=itau_boleto_ascii_subject((string)($company['name']??'Nexa Prospero'));
$csrCity=itau_boleto_ascii_subject((string)($company['cidade']??''));
$csrUf=strtoupper((string)($company['estado']??''));
$whScope=(string)($cfg['itau_webhook_oauth_scope']??'');
$whPending=0;$whProcessingErrors=0;
try{
  $st=$pdo->prepare("SELECT SUM(CASE WHEN matched=1 AND processed=0 THEN 1 ELSE 0 END) AS pending_count, SUM(CASE WHEN processing_status='ERRO' THEN 1 ELSE 0 END) AS error_count FROM finance_boleto_webhook_logs WHERE company_id=? AND bank_account_id=?");
  $st->execute([$cid,$bankId]);$whStats=$st->fetch(PDO::FETCH_ASSOC)?:[];
  $whPending=(int)($whStats['pending_count']??0);$whProcessingErrors=(int)($whStats['error_count']??0);
}catch(Throwable $ignored){}
$debitCharts=[];
try{$debitCharts=finance_query_all($pdo,"SELECT id,code,name FROM finance_chart_accounts WHERE company_id=? AND active=1 AND is_analytic=1 AND movement_type IN ('DEBITO','AMBOS') ORDER BY code,name",[$cid]);}catch(Throwable $ignored){}
$concMonth=trim((string)($_POST['mes_referencia']??date('mY')));
$concDate=trim((string)($_POST['data_movimentacao']??date('Y-m-d')));
$mcCsrf=$itauCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';

finance_admin_start('Financeiro - Integração Itaú','fin_caixas');
finance_page_header('Integração Itaú - Boletos','Configuração segura de certificado, OAuth/mTLS e Webhook da conta '.(string)($cfg['bank_account_name']??'').($itauCrossContext?' · empresa selecionada pelo F11: '.finance_operational_company_name($pdo):''),[
 ['href'=>'financeiro_caixas_bancos.php?edit='.$bankId,'label'=>'Voltar à conta','class'=>'btn'],
 ['href'=>'financeiro_boletos.php','label'=>'Cobranças/Boletos','class'=>'btn btn-primary'],
]);
finance_tabs('fin_caixas');finance_flash();
if($msg!==''):?><div class="msg ok"><?=finance_h($msg)?></div><?php endif;
if($err!==''):?><div class="msg err"><?=finance_h($err)?></div><?php endif;
?>

<div class="finance-card">
 <div class="finance-card-head"><h2 class="finance-subtitle">Ambiente da integração Itaú</h2><span class="finance-muted">Define os endpoints utilizados nos testes e emissões</span></div>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?>
  <input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="save_environment">
  <div class="finance-form-grid">
   <div><label>Ambiente</label><select name="billing_environment"><option value="HOMOLOGACAO" <?=strtoupper((string)($cfg['environment']??'HOMOLOGACAO'))==='HOMOLOGACAO'?'selected':''?>>Homologação</option><option value="PRODUCAO" <?=strtoupper((string)($cfg['environment']??''))==='PRODUCAO'?'selected':''?>>Produção</option></select><div class="hint">Durante a homologação do BoleCode, mantenha em Homologação. Produção deve ser habilitada somente após os testes.</div></div>
   <div><label>Endpoint BoleCode atual</label><input value="<?=finance_h(itau_bolecode_endpoint($cfg))?>" readonly></div>
  </div>
  <div class="finance-form-actions"><button class="btn btn-primary">Salvar ambiente</button></div>
 </form>
</div>
<style>
.itau-steps{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:12px;margin-bottom:14px}.itau-step{border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:14px}.itau-step strong{display:block;color:var(--admin-primary);margin-bottom:5px}.itau-step small{color:#64748b;line-height:1.35}.itau-check{padding:9px 11px;border-radius:12px;margin:6px 0;background:#f8fafc;border:1px solid #e2e8f0}.itau-check.ok{background:#ecfdf5;border-color:#a7f3d0}.itau-check.error{background:#fef2f2;border-color:#fecaca}.itau-check.warning{background:#fffbeb;border-color:#fde68a}.itau-secret{font-family:ui-monospace,monospace}.itau-tech{width:100%;min-height:150px;font-family:ui-monospace,monospace;font-size:12px}@media(max-width:900px){.itau-steps{grid-template-columns:1fr 1fr}}@media(max-width:600px){.itau-steps{grid-template-columns:1fr}}
</style>
<div class="itau-steps">
 <div class="itau-step"><strong>1. Certificado</strong><small>Gerar CSR/chave RSA 2048 SHA-512 e trocar pelo CRT + Client Secret.</small></div>
 <div class="itau-step"><strong>2. OAuth/mTLS</strong><small>Validar Client ID, Secret, certificado e chave obtendo access_token.</small></div>
 <div class="itau-step"><strong>3. Webhook</strong><small>API Boletos v3: cadastrar e consultar notificações de baixa.</small></div>
 <div class="itau-step"><strong>4. Conciliação</strong><small>API Boletos v3: consultar Francesa e movimentações por data/título.</small></div>
 <div class="itau-step"><strong>5. Emissão</strong><small>BoleCode: simular e efetivar Boleto + Pix pelo endpoint oficial /boletos-pix.</small></div>
</div>

<div class="finance-card">
 <div class="finance-card-head"><h2 class="finance-subtitle">Diagnóstico atual</h2><span class="finance-muted">Banco 341 / <?=finance_h((string)($cfg['environment']??''))?></span></div>
 <?php foreach($diag['checks'] as $c):?><div class="itau-check <?=finance_h((string)($c['level']??''))?>"><strong><?=finance_h((string)($c['label']??''))?>:</strong> <?=finance_h((string)($c['message']??''))?></div><?php endforeach;?>
</div>

<div class="finance-card"><h2 class="finance-subtitle">Etapa 1 - Certificado dinâmico Itaú</h2>
 <p class="finance-muted">O token temporário recebido por e-mail é usado somente nesta ação e <strong>não é salvo no banco</strong>. O Nexa gera a chave privada na VPS, cria o CSR e envia para o endpoint oficial de solicitação.</p>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="enroll_certificate">
  <div class="finance-form-grid three">
   <div><label>Client ID</label><input name="api_client_id" value="<?=finance_h((string)($cfg['api_client_id']??''))?>" required></div>
   <div><label>Nome da empresa / APP (OU)</label><input name="csr_ou" value="<?=finance_h($csrOu)?>" required></div>
   <div><label>Cidade (sem acentos)</label><input name="csr_city" value="<?=finance_h($csrCity)?>" required></div>
   <div><label>UF</label><input name="csr_state" maxlength="2" value="<?=finance_h($csrUf)?>" required></div>
   <div class="span-2"><label>Token temporário do Itaú</label><input type="password" autocomplete="off" name="temporary_token" value="" required placeholder="Cole somente no momento da emissão"><div class="hint">Não será persistido nem exibido em logs da aplicação.</div></div>
  </div>
  <div class="finance-form-actions"><button class="btn btn-primary" onclick="return confirm('Gerar nova chave privada/CSR e solicitar o certificado ao Itaú?')">Gerar CSR e solicitar certificado</button></div>
 </form>
 <?php if(!empty($cfg['cert_path'])):?><p class="finance-muted"><strong>Certificado:</strong> <span class="code"><?=finance_h((string)$cfg['cert_path'])?></span><br><strong>Chave:</strong> <span class="code"><?=finance_h((string)$cfg['key_path'])?></span><?php if($cert):?><br><strong>Validade:</strong> <?=finance_h((string)($cert['valid_to']??''))?><?php endif;?></p><?php endif;?>
</div>

<div class="finance-card"><h2 class="finance-subtitle">Etapa 2 - OAuth / mTLS</h2>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="save">
  <div class="finance-form-grid">
   <div><label>Client ID</label><input name="api_client_id" value="<?=finance_h((string)($cfg['api_client_id']??''))?>"></div>
   <div><label>Client Secret</label><input class="itau-secret" value="<?=finance_h(itau_boleto_mask_secret((string)($cfg['api_client_secret']??'')))?>" readonly></div>
   <div><label>OAuth URL</label><input name="oauth_url" value="<?=finance_h((string)($cfg['oauth_url']??''))?>" placeholder="<?=finance_h(itau_boleto_default_oauth_url())?>"></div>
   <div><label>Endpoint BoleCode</label><input value="<?=finance_h(itau_bolecode_endpoint($cfg))?>" readonly><div class="hint">Definido automaticamente pelo ambiente da conta. Homologação e Produção usam os hosts oficiais do OpenAPI Boleto com Pix v1.0.7.</div></div><input type="hidden" name="api_base_url" value="<?=finance_h((string)($cfg['api_base_url']??''))?>">
  </div>
  <input type="hidden" name="itau_beneficiary_id" value="<?=finance_h((string)($cfg['itau_beneficiary_id']??''))?>"><input type="hidden" name="itau_webhook_client_id" value="<?=finance_h((string)($cfg['itau_webhook_client_id']??''))?>"><input type="hidden" name="itau_webhook_url" value="<?=finance_h((string)($cfg['itau_webhook_url']??''))?>"><input type="hidden" name="itau_webhook_oauth_url" value="<?=finance_h((string)($cfg['itau_webhook_oauth_url']??''))?>"><input type="hidden" name="itau_webhook_oauth_scope" value="<?=finance_h((string)($cfg['itau_webhook_oauth_scope']??''))?>"><input type="hidden" name="itau_webhook_enabled" value="<?=!empty($cfg['itau_webhook_enabled'])?'1':''?>"><?php foreach($whTypes as $t):?><input type="hidden" name="<?= $t==='BAIXA_EFETIVA'?'notify_efetiva':'notify_operacional' ?>" value="1"><?php endforeach;?>
  <div class="finance-form-actions"><button class="btn">Salvar URLs</button></div>
 </form>
 <form method="post" style="margin-top:10px"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="test_token"><button class="btn btn-primary" <?=empty($diag['ready_for_token'])?'disabled':''?>>Testar access_token Itaú</button></form>
</div>

<div class="finance-card"><h2 class="finance-subtitle">Etapa 3 - Webhook de Cobrança e baixa automática</h2>
 <p class="finance-muted">O Itaú exige uma URL HTTPS de callback e outra URL OAuth2 para obter permissão antes de notificar. Quando o retorno de pagamento é localizado pelo Nosso Número, o Nexa passa a <strong>baixar automaticamente a Conta a Receber</strong> e gerar o respectivo <strong>movimento financeiro de crédito</strong>. Eventos repetidos são auditados sem gerar baixa duplicada.</p>
 <div class="itau-check <?=$whPending>0?'warning':'ok'?>"><strong>Retornos pendentes:</strong> <?=$whPending?><?php if($whProcessingErrors>0):?> · <strong>Com erro:</strong> <?=$whProcessingErrors?><?php endif;?><?php if($whPending<=0):?><br>Não há retorno identificado aguardando processamento nesta conta.<?php endif;?></div>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="save">
  <input type="hidden" name="api_client_id" value="<?=finance_h((string)($cfg['api_client_id']??''))?>"><input type="hidden" name="oauth_url" value="<?=finance_h((string)($cfg['oauth_url']??''))?>"><input type="hidden" name="api_base_url" value="<?=finance_h((string)($cfg['api_base_url']??''))?>">
  <div class="finance-form-grid">
   <div><label>ID Beneficiário Itaú</label><input name="itau_beneficiary_id" inputmode="numeric" value="<?=finance_h((string)($cfg['itau_beneficiary_id']??''))?>" placeholder="Agência(4)+Conta(7)+DAC(1)"><div class="hint">OpenAPI 1.34.1: 12 dígitos. Exemplo: 150000154711.</div></div>
   <label class="checkline"><input type="checkbox" name="itau_webhook_enabled" <?=!empty($cfg['itau_webhook_enabled'])?'checked':''?>> Habilitar Webhook Itaú nesta conta</label>
   <div><label>Webhook URL</label><input name="itau_webhook_url" value="<?=finance_h((string)($cfg['itau_webhook_url']??$defaults['webhook_url']))?>"></div>
   <div><label>OAuth URL do Webhook</label><input name="itau_webhook_oauth_url" value="<?=finance_h((string)($cfg['itau_webhook_oauth_url']??$defaults['webhook_oauth_url']))?>"></div>
   <div><label>OAuth Scope do Webhook</label><input name="itau_webhook_oauth_scope" value="<?=finance_h($whScope)?>" placeholder="Opcional"><div class="hint">O OpenAPI v3 aceita scope no cadastro do callback.</div></div>
   <div><label>Client ID do Webhook</label><input name="itau_webhook_client_id" value="<?=finance_h((string)($cfg['itau_webhook_client_id']??''))?>" readonly></div>
   <div><label>Client Secret do Webhook</label><input type="password" name="itau_webhook_client_secret" value="" placeholder="Deixe vazio para manter o atual"><div class="hint">Atual: <?=finance_h(itau_boleto_mask_secret((string)($cfg['itau_webhook_client_secret']??'')))?></div></div>
   <label class="checkline"><input type="checkbox" name="notify_efetiva" <?=in_array('BAIXA_EFETIVA',$whTypes,true)?'checked':''?>> BAIXA_EFETIVA</label>
   <label class="checkline"><input type="checkbox" name="notify_operacional" <?=in_array('BAIXA_OPERACIONAL',$whTypes,true)?'checked':''?>> BAIXA_OPERACIONAL</label>
  </div>
  <div class="finance-form-actions"><button class="btn">Salvar Webhook</button></div>
  <div class="hint" style="margin-top:8px">Ao habilitar e salvar, o Nexa gera automaticamente o Client ID e o Client Secret internos do callback caso ainda não existam. O botão Preparar credenciais permanece apenas como opção de manutenção.</div>
 </form>
 <div class="finance-inline-actions" style="justify-content:flex-start;margin-top:12px;flex-wrap:wrap">
  <form method="post"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="prepare_webhook"><button class="btn">Preparar credenciais</button></form>
  <form method="post" onsubmit="return confirm('Cadastrar/atualizar o Webhook de Cobrança no Itaú? As credenciais internas ausentes serão preparadas automaticamente.')"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="register_webhook"><button class="btn btn-primary">Registrar Webhook no Itaú</button></form>
  <form method="post"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="consult_webhook"><button class="btn">Consultar Webhook no Itaú</button></form>
  <?php if(!$itauCrossContext || $itauCrossSettlement): ?><form method="post" onsubmit="return confirm('Processar as notificações Itaú já recebidas e ainda pendentes nesta empresa/conta?')"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="reprocess_webhook_logs"><button class="btn" <?=$whPending<=0?'disabled':''?>>Processar retornos pendentes<?=$whPending>0?' ('.$whPending.')':''?></button></form><?php else: ?><span class="finance-muted">Processar retornos: sem permissão de baixa Itaú no contexto F11.</span><?php endif; ?>
 </div>
 <?php if(!empty($cfg['itau_webhook_registered_at'])):?><p class="finance-muted">Último registro solicitado em <?=finance_h((string)$cfg['itau_webhook_registered_at'])?>.</p><?php endif;?>
</div>

<div class="finance-card"><h2 class="finance-subtitle">Etapa 4 - Consulta / Conciliação - API Boletos v3</h2>
 <p class="finance-muted">A consulta continua disponível somente para conferência. O botão <strong>Sincronizar liquidações no Nexa</strong> usa a mesma API como contingência do Webhook, localiza o boleto pelo Nosso Número e executa a baixa de forma idempotente.</p>
 <form method="post" class="finance-form" style="margin-bottom:14px"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="save_bank_fee_chart">
  <div class="finance-form-grid three"><div class="span-2"><label>Plano de contas para tarifas bancárias</label><select name="itau_bank_fee_chart_account_id"><option value="">Selecione quando o Itaú descontar tarifa da liquidação</option><?php foreach($debitCharts as $x):?><option value="<?=(int)$x['id']?>" <?= (int)($cfg['itau_bank_fee_chart_account_id']??0)===(int)$x['id']?'selected':'' ?>><?=finance_h((string)$x['code'].' - '.(string)$x['name'])?></option><?php endforeach;?></select><div class="hint">Quando o retorno trouxer tarifa bancária, o Nexa baixa o valor integral do cliente, gera o crédito do recebimento e registra a tarifa em débito separado para que o saldo líquido do banco permaneça correto.</div></div></div>
  <div class="finance-form-actions"><button class="btn">Salvar plano de tarifa</button></div>
 </form>
 <form method="post" class="finance-form" style="margin-bottom:14px"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="consult_francesa">
  <div class="finance-form-grid three"><div><label>Mês de referência (MMYYYY)</label><input name="mes_referencia" inputmode="numeric" maxlength="6" value="<?=finance_h($concMonth)?>" placeholder="092026"></div></div>
  <div class="finance-form-actions"><button class="btn">Consultar posições disponíveis</button></div>
 </form>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="consult_movements">
  <div class="finance-form-grid three">
   <div><label>Data da movimentação</label><input type="date" name="data_movimentacao" value="<?=finance_h($concDate)?>" required></div>
   <div><label>Tipo cobrança</label><select name="tipo_cobranca"><option value="">Todos</option><option value="boleto">Boleto</option><option value="bolecode">BoleCode</option><option value="cobranca_digital">Cobrança digital</option></select></div>
   <div><label>Tipo movimentação</label><select name="tipo_movimentacao"><option value="">Todas</option><option value="entradas">Entradas</option><option value="liquidacoes">Liquidações</option><option value="baixas">Baixas</option></select></div>
   <div><label>Nosso Número</label><input name="nosso_numero" value="<?=finance_h((string)($_POST['nosso_numero']??''))?>"></div>
   <div><label>Seu Número</label><input name="seu_numero" maxlength="10" value="<?=finance_h((string)($_POST['seu_numero']??''))?>"></div>
   <div><label>Carteira</label><input name="numero_carteira" inputmode="numeric" value="<?=finance_h((string)($_POST['numero_carteira']??($cfg['carteira']??'')))?>"></div>
   <div class="span-2"><label>Nome do pagador</label><input name="nome_pagador" value="<?=finance_h((string)($_POST['nome_pagador']??''))?>"></div>
  </div>
  <div class="finance-inline-actions" style="justify-content:flex-start;margin-top:12px;flex-wrap:wrap"><button class="btn btn-primary">Consultar movimentações</button><button class="btn" name="action" value="consult_movements_summary">Consultar resumo do dia</button><?php if(!$itauCrossContext || $itauCrossSettlement): ?><button class="btn" name="action" value="sync_liquidations" onclick="return confirm('Sincronizar as liquidações retornadas pelo Itaú e baixar automaticamente os títulos correspondentes nesta empresa?')">Sincronizar liquidações no Nexa</button><?php else: ?><span class="finance-muted">Sincronizar liquidações: sem permissão de baixa Itaú no contexto F11.</span><?php endif; ?></div>
 </form>
</div>

<?php $boleCheck=itau_bolecode_validate_bank_config($cfg); ?>
<div class="finance-card"><h2 class="finance-subtitle">Etapa 5 - Emissão Boleto + Pix (BoleCode)</h2>
 <p class="finance-muted">OpenAPI oficial <strong>Boleto com Pix v1.0.7</strong>. A fila permite <strong>Simulação</strong> (valida sem gerar) e <strong>Efetivação</strong> (registra o BoleCode). O token OAuth continua temporário e nunca é gravado.</p>
 <div class="itau-check <?=!empty($boleCheck['ok'])?'ok':'warning'?>"><strong>Endpoint:</strong> <?=finance_h((string)$boleCheck['endpoint'])?><br><strong>Situação:</strong> <?=!empty($boleCheck['ok'])?'Configuração pronta para simular/emitir.':finance_h(implode(' ',(array)$boleCheck['errors']))?></div>
 <form method="post" class="finance-form"><?php if($itauCrossContext):?><input type="hidden" name="mc_csrf" value="<?= finance_h($mcCsrf) ?>"><?php endif;?><input type="hidden" name="bank" value="<?=$bankId?>"><input type="hidden" name="action" value="save">
  <input type="hidden" name="api_client_id" value="<?=finance_h((string)($cfg['api_client_id']??''))?>"><input type="hidden" name="oauth_url" value="<?=finance_h((string)($cfg['oauth_url']??''))?>"><input type="hidden" name="api_base_url" value="<?=finance_h((string)($cfg['api_base_url']??''))?>"><input type="hidden" name="itau_beneficiary_id" value="<?=finance_h((string)($cfg['itau_beneficiary_id']??''))?>">
  <div class="finance-form-grid three">
   <div><label>Carteira Itaú</label><input name="carteira" inputmode="numeric" maxlength="3" value="<?=finance_h((string)($cfg['carteira']??''))?>" placeholder="109"><div class="hint">Use a carteira contratada no Itaú. O OpenAPI exemplifica a carteira 109.</div></div>
   <div><label>Código da espécie</label><input name="itau_species_code" inputmode="numeric" maxlength="2" value="<?=finance_h((string)($cfg['itau_species_code']??'01'))?>" placeholder="01"><div class="hint">01 = Duplicata de Venda Mercantil. Ajuste conforme a operação contratada.</div></div>
   <div><label>Próximo Nosso Número</label><input name="itau_nosso_numero_next" type="number" min="1" max="99999999" value="<?=finance_h((string)($cfg['itau_nosso_numero_next']??''))?>" placeholder="1"><div class="hint">Informe o próximo número livre da carteira. O Nexa reserva e incrementa automaticamente por conta bancária.</div></div>
  </div>
  <?php if($cashCredentialSchemaReady): ?><div class="finance-form-grid three" style="margin-top:12px">
   <div><label>Client ID · Consulta Cash Management</label><input name="itau_cash_client_id" value="<?=finance_h((string)($cfg['itau_cash_client_id']??''))?>" placeholder="Opcional · vazio usa o Client ID da emissão"><div class="hint">Algumas credenciais Itaú usam Client ID específico para consulta de títulos. Se o mesmo Client ID da emissão tiver o escopo de consulta, deixe vazio.</div></div>
   <div><label>Client Secret · Consulta Cash Management</label><input type="password" name="itau_cash_client_secret" value="" placeholder="Deixe vazio para manter o atual"><div class="hint">Atual: <?=finance_h(itau_boleto_mask_secret((string)($cfg['itau_cash_client_secret']??'')))?> · se não houver credencial dedicada, o Nexa usa o Secret da emissão.</div></div>
   <div><label>Endpoint de consulta</label><input value="<?=finance_h(itau_cash_query_endpoint($cfg))?>" readonly><div class="hint">V11.12 · consulta individual por Beneficiário + Carteira + Nosso Número. Nunca reenvia a cobrança.</div></div>
  </div><?php else: ?><div class="itau-check warning" style="margin-top:12px"><strong>V11.12 pendente:</strong> aplique a migration pelo Atualizador do Banco para habilitar credenciais dedicadas e auditoria da consulta de situação.</div><?php endif; ?>
  <input type="hidden" name="itau_webhook_client_id" value="<?=finance_h((string)($cfg['itau_webhook_client_id']??''))?>"><input type="hidden" name="itau_webhook_url" value="<?=finance_h((string)($cfg['itau_webhook_url']??''))?>"><input type="hidden" name="itau_webhook_oauth_url" value="<?=finance_h((string)($cfg['itau_webhook_oauth_url']??''))?>"><input type="hidden" name="itau_webhook_oauth_scope" value="<?=finance_h((string)($cfg['itau_webhook_oauth_scope']??''))?>"><input type="hidden" name="itau_webhook_enabled" value="<?=!empty($cfg['itau_webhook_enabled'])?'1':''?>"><?php foreach($whTypes as $t):?><input type="hidden" name="<?= $t==='BAIXA_EFETIVA'?'notify_efetiva':'notify_operacional' ?>" value="1"><?php endforeach;?>
  <?php $queueUrl=function_exists('multi_company_context_url')?multi_company_context_url('financeiro_boletos.php?status=AGUARDANDO_API&bank='.(int)$bankId):('financeiro_boletos.php?status=AGUARDANDO_API&bank='.(int)$bankId); ?><div class="finance-form-actions"><a class="btn" href="<?= finance_h($queueUrl) ?>">Abrir fila de cobranças</a><button class="btn btn-primary">Salvar configuração de emissão</button></div>
 </form>
 <div class="itau-check warning"><strong>Segurança contra duplicidade:</strong> na efetivação o Nosso Número é reservado antes da chamada. Se houver falha de comunicação sem resposta do banco, a cobrança ficará em <span class="code">VERIFICAR_API</span> e não será reenviada automaticamente. Na V11.12, esses estados podem ser consultados na fila pelo Nosso Número sem reenvio.</div>
</div>

<?php if($technical!==''):?><div class="finance-card"><h2 class="finance-subtitle">Retorno técnico Itaú</h2><textarea class="itau-tech" readonly><?=finance_h($technical)?></textarea></div><?php endif;?>
<?php finance_admin_end(); ?>
