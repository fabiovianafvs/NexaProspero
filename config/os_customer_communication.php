<?php
declare(strict_types=1);

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/load_config.php';

if(!function_exists('os_status_customer_messages_load')){
  function os_status_customer_messages_load(PDO $pdo,int $companyId):array{
    try{$v=config_store_get($pdo,$companyId,'os_status_customer_messages_v1');return is_array($v)?$v:[];}catch(Throwable $e){return [];}
  }
}
if(!function_exists('os_status_customer_message_save')){
  function os_status_customer_message_save(PDO $pdo,int $companyId,int $statusId,string $message):void{
    if($statusId<=0)return;$state=os_status_customer_messages_load($pdo,$companyId);$message=trim($message);
    if($message==='')unset($state[(string)$statusId]);else $state[(string)$statusId]=mb_substr($message,0,5000);
    config_store_set($pdo,$companyId,'os_status_customer_messages_v1',$state);
  }
}
if(!function_exists('os_status_customer_default_template')){
  function os_status_customer_default_template(string $code,string $name):string{
    $code=strtoupper(trim($code));
    if($code==='ABERTA')return 'Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} foi registrada e está com o status {{status}}. {{previsao_linha}}';
    if($code==='EM_ANALISE')return 'Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} está em análise. Assim que houver novidade, entraremos em contato. {{previsao_linha}}';
    if($code==='EM_EXECUCAO')return 'Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} foi autorizada e está em execução. {{previsao_linha}}';
    if($code==='FINALIZADA')return 'Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} foi finalizada. Seu veículo/equipamento já está pronto para retirada. {{previsao_linha}}';
    if($code==='CANCELADA')return 'Olá, {{cliente}}! Informamos que sua Ordem de Serviço #{{os}} foi atualizada para o status {{status}}. Em caso de dúvida, entre em contato conosco.';
    return 'Olá, {{cliente}}! Sua Ordem de Serviço #{{os}} foi atualizada para o status {{status}}. {{previsao_linha}}';
  }
}
if(!function_exists('os_status_customer_message_template')){
  function os_status_customer_message_template(PDO $pdo,int $companyId,int $statusId,string $code='',string $name=''):string{
    $state=os_status_customer_messages_load($pdo,$companyId);$saved=trim((string)($state[(string)$statusId]??''));
    return $saved!==''?$saved:os_status_customer_default_template($code,$name);
  }
}
if(!function_exists('os_status_customer_company_name')){
  function os_status_customer_company_name(int $companyId):string{
    try{$app=load_app_config($companyId);$n=trim((string)($app['site_name']??''));if($n!=='')return $n;}catch(Throwable $e){}
    try{$b=load_business_config($companyId);$n=trim((string)($b['brand']['catalog_title']??''));if($n!=='')return $n;}catch(Throwable $e){}
    return 'Nexa Próspero';
  }
}
if(!function_exists('os_status_customer_vars')){
  function os_status_customer_vars(array $order):array{
    $expected=trim((string)($order['expected_at']??''));$expectedFmt='';
    if($expected!==''){$ts=strtotime($expected);$expectedFmt=$ts?date('d/m/Y \à\s H:i',$ts):$expected;}
    return [
      'empresa'=>(string)($order['company_name']??''),
      'cliente'=>trim((string)($order['person_name_snapshot']??''))?:'cliente',
      'os'=>str_pad((string)(int)($order['os_number']??0),6,'0',STR_PAD_LEFT),
      'status'=>trim((string)($order['status_name']??'')),
      'previsao'=>$expectedFmt,
      'previsao_linha'=>$expectedFmt!==''?'Previsão: '.$expectedFmt.'.':'',
    ];
  }
}
if(!function_exists('os_status_customer_render')){
  function os_status_customer_render(string $template,array $vars):string{
    $replace=[];foreach($vars as $k=>$v)$replace['{{'.$k.'}}']=(string)$v;
    return trim(strtr($template,$replace));
  }
}
if(!function_exists('os_status_customer_contact')){
  function os_status_customer_contact(PDO $pdo,int $companyId,array $order):array{
    $phone=trim((string)($order['contact_phone']??''));if($phone==='')$phone=trim((string)($order['person_phone_snapshot']??''));$email='';
    $personId=(int)($order['person_id']??0);
    if($personId>0){try{$st=$pdo->prepare('SELECT email,whatsapp,phone FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$personId]);$p=$st->fetch(PDO::FETCH_ASSOC)?:[];if($phone==='')$phone=trim((string)($p['whatsapp']??''))?:trim((string)($p['phone']??''));$email=strtolower(trim((string)($p['email']??'')));}catch(Throwable $e){}}
    return ['phone'=>$phone,'email'=>$email];
  }
}

// Habilitação explícita de aviso por mudança de status (por empresa e por status).
if(!function_exists('os_status_notify_flags_load')){
  function os_status_notify_flags_load(PDO $pdo,int $companyId):array{
    try{$state=config_store_get($pdo,$companyId,'os_status_notify_optin_v1');return is_array($state)?$state:[];}
    catch(Throwable $e){error_log('[OS_STATUS_NOTIFY] Falha ao ler preferências: '.$e->getMessage());return [];}
  }
}
if(!function_exists('os_status_notify_enabled')){
  function os_status_notify_enabled(PDO $pdo,int $companyId,int $statusId):bool{
    if($statusId<=0)return false;
    $state=os_status_notify_flags_load($pdo,$companyId);
    return !empty($state[(string)$statusId]);
  }
}
if(!function_exists('os_status_notify_save')){
  function os_status_notify_save(PDO $pdo,int $companyId,int $statusId,bool $enabled):void{
    if($statusId<=0)throw new InvalidArgumentException('Status inválido.');
    $state=os_status_notify_flags_load($pdo,$companyId);
    if($enabled)$state[(string)$statusId]=1;else unset($state[(string)$statusId]);
    config_store_set($pdo,$companyId,'os_status_notify_optin_v1',$state);
  }
}
