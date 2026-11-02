<?php
/** Consulta explícita da NFS-e da O.S. - sem transmissão / sem reemissão. */
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/os_module.php';
require_once __DIR__.'/../config/os_fiscal_queue.php';
require_once __DIR__.'/../config/os_nfse_consulta.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);
if (($_SERVER['REQUEST_METHOD']??'')!=='POST') {http_response_code(405);exit('Método não permitido.');}
$osId=(int)($_POST['os_id']??0);
// Retorno sempre para uma tela local conhecida, sem redirecionamento aberto.
$target='os_fiscal_documentos.php'.($osId>0?'?os_id='.$osId:'');
try {
    os_fiscal_csrf_check($_POST['csrf']??'');
    $result=os_nfse_consulta_execute($pdo,$companyId,$osId);
    $kind=in_array(($result['kind']??''),['ok','warn','info'],true)?$result['kind']:'info';
    $message=(string)($result['message']??'Consulta concluída.');
} catch(Throwable $e) {
    $kind='warn';$message='Não foi possível concluir a consulta: '.$e->getMessage().'. Nenhuma emissão foi iniciada.';
}
if(function_exists('mb_substr'))$message=mb_substr($message,0,600,'UTF-8');else $message=substr($message,0,600);
header('Location: '.$target.($osId>0?'&':'?').http_build_query(['nfse_result'=>$kind,'nfse_message'=>$message], '', '&', PHP_QUERY_RFC3986));
exit;
