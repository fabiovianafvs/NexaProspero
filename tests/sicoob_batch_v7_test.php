<?php
declare(strict_types=1);
require dirname(__DIR__).'/config/sicoob_batch_v7.php';
function check(bool $v,string $msg):void {if(!$v)throw new RuntimeException('FALHOU: '.$msg);}
$ids=sicoob_v7_batch_normalize_ids(['11','12','11']);check($ids===[11,12],'IDs sem duplicidade');
foreach([['11','abc'],array_fill(0,201,'1')] as $bad){try{sicoob_v7_batch_normalize_ids($bad);throw new RuntimeException('ACEITOU INVÁLIDO');}catch(RuntimeException $e){check($e->getMessage()!=='ACEITOU INVÁLIDO','lista inválida rejeitada');}}
$eligible=['provider'=>'SICOOB','integration_mode'=>'API','status'=>'AGUARDANDO_API','nosso_numero'=>null,'registered_at'=>null,'api_http_status'=>null];
check(sicoob_v7_batch_eligible($eligible),'Sicoob elegivel');
foreach(['REGISTRADO','VERIFICAR_API','ENVIANDO_API','PAGO','ERRO'] as $status){$invalid=$eligible;$invalid['status']=$status;check(!sicoob_v7_batch_eligible($invalid),'Bloqueio '.$status);}
$invalid=$eligible;$invalid['api_http_status']=400;check(!sicoob_v7_batch_eligible($invalid),'Bloqueio tentativa anterior');
$invalid=$eligible;$invalid['provider']='ITAU';check(!sicoob_v7_batch_eligible($invalid),'Bloqueio outro banco');
$batch=['token'=>'abc123','company_id'=>5,'user_id'=>20,'created_at'=>time()];
check(sicoob_v7_batch_check_session($batch,5,20,'abc123'),'Token válido');
check(!sicoob_v7_batch_check_session($batch,6,20,'abc123'),'Isolamento por empresa');
check(!sicoob_v7_batch_check_session($batch,5,20,'outro'),'Token incorreto');
$batch['created_at']=time()-8000;check(!sicoob_v7_batch_check_session($batch,5,20,'abc123'),'Lote expirado');
echo "OK - testes locais de lote Sicoob V7\n";
