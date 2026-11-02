<?php
declare(strict_types=1);

require_once __DIR__ . '/../lib/PagBank/Logger.php';
require_once __DIR__ . '/../lib/PagBank/Client.php';
require_once __DIR__ . '/../lib/PagBank/Orders.php';
require_once __DIR__ . '/../lib/PagBank/Checkout.php';
require_once __DIR__ . '/../lib/PagBank/Webhook.php';

use Nexa\PagBank\Logger as PagBankLogger;
use Nexa\PagBank\Client as PagBankClient;
use Nexa\PagBank\Orders as PagBankOrders;
use Nexa\PagBank\Checkout as PagBankCheckout;

if (!function_exists('pagbank_ensure_schema')) {
  function pagbank_ensure_schema(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pagbank_config (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      environment VARCHAR(20) NOT NULL DEFAULT 'sandbox', token LONGTEXT NOT NULL,
      api_base_url VARCHAR(255) DEFAULT NULL, webhook_url VARCHAR(255) DEFAULT NULL,
      webhook_token VARCHAR(100) DEFAULT NULL, pix_expiration_minutes INT NOT NULL DEFAULT 1440,
      max_installments INT NOT NULL DEFAULT 12, soft_descriptor VARCHAR(17) DEFAULT 'NEXAPROSPERO',
      pix_enabled TINYINT(1) NOT NULL DEFAULT 1, card_enabled TINYINT(1) NOT NULL DEFAULT 1,
      active TINYINT(1) NOT NULL DEFAULT 1, created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_pagbank_active (active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    new PagBankLogger($pdo);
  }
}
function pagbank_default_api_base(string $environment): string { return strtolower(trim($environment)) === 'production' ? 'https://api.pagseguro.com' : 'https://sandbox.api.pagseguro.com'; }
function pagbank_normalize_config(array $row): array {
  $environment=strtolower(trim((string)($row['environment']??'sandbox'))); if(!in_array($environment,['sandbox','production'],true))$environment='sandbox';
  // Ambiente e endpoint nunca podem divergir. Isso evita usar token/ambiente de
  // produção contra a URL do Sandbox (ou o inverso).
  $base=pagbank_default_api_base($environment);
  return array_merge($row,['environment'=>$environment,'api_base_url'=>rtrim($base,'/'),'token'=>trim((string)($row['token']??'')),'webhook_url'=>trim((string)($row['webhook_url']??'')),'webhook_token'=>trim((string)($row['webhook_token']??'')),'pix_expiration_minutes'=>max(5,min(43200,(int)($row['pix_expiration_minutes']??1440))),'max_installments'=>max(1,min(18,(int)($row['max_installments']??12))),'soft_descriptor'=>substr(preg_replace('/[^A-Za-z0-9 ]/','',(string)($row['soft_descriptor']??'NEXAPROSPERO')),0,17),'pix_enabled'=>!empty($row['pix_enabled'])?1:0,'card_enabled'=>!empty($row['card_enabled'])?1:0,'active'=>!empty($row['active'])?1:0]);
}
function pagbank_get_config(PDO $pdo,bool $onlyActive=false):?array { pagbank_ensure_schema($pdo); $row=$pdo->query('SELECT * FROM pagbank_config'.($onlyActive?' WHERE active=1':'').' ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC); return $row?pagbank_normalize_config($row):null; }
function pagbank_require_config(PDO $pdo):array { $cfg=pagbank_get_config($pdo,true); if(!$cfg)throw new RuntimeException('Configure e ative o PagBank antes de gerar cobranças.'); if(trim((string)$cfg['token'])==='')throw new RuntimeException('O token do PagBank não foi informado.'); return $cfg; }
function pagbank_save_config(PDO $pdo,array $data):int { pagbank_ensure_schema($pdo); $cfg=pagbank_normalize_config($data); $existing=pagbank_get_config($pdo,false); $v=[$cfg['environment'],$cfg['token'],$cfg['api_base_url'],$cfg['webhook_url'],$cfg['webhook_token'],$cfg['pix_expiration_minutes'],$cfg['max_installments'],$cfg['soft_descriptor'],$cfg['pix_enabled'],$cfg['card_enabled'],$cfg['active']]; if($existing){$st=$pdo->prepare('UPDATE pagbank_config SET environment=?,token=?,api_base_url=?,webhook_url=?,webhook_token=?,pix_expiration_minutes=?,max_installments=?,soft_descriptor=?,pix_enabled=?,card_enabled=?,active=? WHERE id=?');$st->execute(array_merge($v,[(int)$existing['id']]));return(int)$existing['id'];}$st=$pdo->prepare('INSERT INTO pagbank_config (environment,token,api_base_url,webhook_url,webhook_token,pix_expiration_minutes,max_installments,soft_descriptor,pix_enabled,card_enabled,active) VALUES (?,?,?,?,?,?,?,?,?,?,?)');$st->execute($v);return(int)$pdo->lastInsertId(); }
function pagbank_mask_secret(string $value):string { $len=strlen($value); if($len<10)return$value===''?'Não informado':str_repeat('•',$len); return substr($value,0,5).str_repeat('•',max(6,$len-10)).substr($value,-5); }
function pagbank_digits(string $value):string { return preg_replace('/\D+/','',$value)?:''; }
function pagbank_customer_payload(array $company):array { $name=trim((string)($company['name']??'Cliente Nexa Próspero')); if(strpos($name,' ')===false)$name.=' Empresa'; $customer=['name'=>substr($name,0,120)]; $email=trim((string)($company['email']??'')); if($email!==''&&filter_var($email,FILTER_VALIDATE_EMAIL))$customer['email']=substr($email,0,60); $tax=pagbank_digits((string)($company['tax_id']??$company['cnpj']??$company['cpf']??'')); if(in_array(strlen($tax),[11,14],true))$customer['tax_id']=$tax; $phone=pagbank_digits((string)($company['phone']??$company['telefone']??'')); if(strlen($phone)>=10){$customer['phones']=[['country'=>'55','area'=>substr($phone,0,2),'number'=>substr($phone,2,9),'type'=>'MOBILE']];} return $customer; }
function pagbank_validate_customer(array $customer,bool $requireEmail=true):void { if(trim((string)($customer['name']??''))==='')throw new RuntimeException('Informe o nome completo.'); $tax=pagbank_digits((string)($customer['tax_id']??'')); if(!in_array(strlen($tax),[11,14],true))throw new RuntimeException('Informe CPF ou CNPJ com 11 ou 14 dígitos.'); if($requireEmail&&!filter_var((string)($customer['email']??''),FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.'); }
function pagbank_client(PDO $pdo,array $cfg):PagBankClient { return new PagBankClient($cfg,new PagBankLogger($pdo)); }
function pagbank_create_pix_order(array $cfg,array $company,array $plan,string $referenceId,string $notificationUrl,string $description=''):array { global $pdo; $customer=pagbank_customer_payload($company); pagbank_validate_customer($customer,true); $item=['reference_id'=>'PLANO-'.(int)($plan['id']??0),'name'=>substr((string)($plan['name']??'Plano Nexa Próspero'),0,100),'description'=>substr($description?:'Assinatura Nexa Próspero',0,255),'quantity'=>1,'unit_amount'=>max(100,(int)round((float)($plan['price']??0)*100))]; return (new PagBankOrders(pagbank_client($pdo,$cfg),$cfg))->createPix($customer,$item,$referenceId,$notificationUrl); }
function pagbank_create_card_checkout(array $cfg,array $company,array $plan,string $referenceId,string $notificationUrl,string $returnUrl,string $description=''):array { global $pdo; $customer=pagbank_customer_payload($company); pagbank_validate_customer($customer,true); $item=['reference_id'=>'PLANO-'.(int)($plan['id']??0),'name'=>substr((string)($plan['name']??'Plano Nexa Próspero'),0,100),'description'=>substr($description?:'Assinatura Nexa Próspero',0,255),'quantity'=>1,'unit_amount'=>max(100,(int)round((float)($plan['price']??0)*100))]; return (new PagBankCheckout(pagbank_client($pdo,$cfg),$cfg))->createCard($customer,$item,$referenceId,$notificationUrl,$returnUrl); }
function pagbank_get_order(array $cfg,string $id):array { global $pdo; return (new PagBankOrders(pagbank_client($pdo,$cfg),$cfg))->get($id); }
function pagbank_get_checkout(array $cfg,string $id):array { global $pdo; return (new PagBankCheckout(pagbank_client($pdo,$cfg),$cfg))->get($id); }
function pagbank_payload_is_paid(array $payload):bool { return PagBankOrders::isPaid($payload); }
function pagbank_extract_payment_data(array $payload):array { $status=PagBankOrders::status($payload); $charge=[]; foreach(($payload['charges']??[]) as $c)if(is_array($c)){ $charge=$c; if(in_array(strtoupper((string)($c['status']??'')),['PAID','CAPTURED','COMPLETED'],true))break; } return ['gateway_status'=>$status,'e2eid'=>(string)($charge['id']??$charge['payment_response']['reference']??''),'amount'=>((float)($charge['amount']['value']??0))/100,'paid_at'=>(string)($charge['paid_at']??$charge['created_at']??date('Y-m-d H:i:s'))]; }
function pagbank_get_last_diagnostic():array { return is_array($GLOBALS['pagbank_last_diagnostic']??null)?$GLOBALS['pagbank_last_diagnostic']:[]; }
function pagbank_set_last_diagnostic(array $data):void { $GLOBALS['pagbank_last_diagnostic']=$data; }
