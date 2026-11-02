<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);os_ensure_defaults($pdo,$companyId);os_fiscal_queue_ensure_schema($pdo);
if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST'){http_response_code(405);exit('Método não permitido.');}

function osfa_return($value){
    $value=trim((string)$value);
    if($value==='' || preg_match('~^(?:https?:)?//~i',$value) || strpos($value,"\n")!==false || strpos($value,"\r")!==false)return 'os_fiscal_documentos.php';
    if(strpos($value,'..')!==false)return 'os_fiscal_documentos.php';
    return $value;
}
$returnTo=osfa_return($_POST['return_to'] ?? 'os_fiscal_documentos.php');
$action=(string)($_POST['action'] ?? '');
try{
    os_fiscal_csrf_check($_POST['csrf'] ?? '');
    if($action==='enqueue'){
        $osId=(int)($_POST['os_id'] ?? 0);$type=(string)($_POST['doc_type'] ?? '');
        $r=os_fiscal_queue_enqueue($pdo,$companyId,$osId,$type,(int)(os_user_id() ?: 0));
        $msg=(string)$r['message'];
        if (!empty($_POST['process_now']) && !empty($r['queue_id'])) {
            header('Location: os_fiscal_processar.php?os_id='.(int)$osId.'&jobs='.(int)$r['queue_id']);
            exit;
        }
    }elseif($action==='retry'){
        $queueId=(int)($_POST['queue_id'] ?? 0);
        os_fiscal_queue_retry($pdo,$companyId,$queueId,(int)(os_user_id() ?: 0));
        $msg='Documento reenviado para a fila fiscal da O.S.';
    }else{
        throw new RuntimeException('Ação fiscal inválida.');
    }
    $sep=strpos($returnTo,'?')!==false?'&':'?';
    header('Location: '.$returnTo.$sep.'ok='.rawurlencode($msg));exit;
}catch(Throwable $e){
    $sep=strpos($returnTo,'?')!==false?'&':'?';
    header('Location: '.$returnTo.$sep.'err='.rawurlencode($e->getMessage()));exit;
}
