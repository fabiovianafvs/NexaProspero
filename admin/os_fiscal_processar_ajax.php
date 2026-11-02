<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_processor.php';

header('Content-Type: application/json; charset=utf-8');
@set_time_limit(180);
@ignore_user_abort(true);
$pdo=os_db();
$companyId=os_company_id();

function osfp_json($data,$status=200){
    http_response_code((int)$status);
    echo json_encode($data,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try{
    if(($_SERVER['REQUEST_METHOD'] ?? '')!=='POST')osfp_json(array('ok'=>false,'message'=>'Método inválido.'),405);
    if($companyId<=0 || !os_module_enabled($pdo,$companyId))osfp_json(array('ok'=>false,'message'=>'Módulo Ordem de Serviço não habilitado.'),403);
    os_ensure_defaults($pdo,$companyId);
    os_fiscal_csrf_check($_POST['csrf'] ?? '');
    $queueId=(int)($_POST['queue_id'] ?? 0);
    if($queueId<=0)throw new RuntimeException('Documento fiscal inválido.');
    // O clique de faturamento/processamento fiscal autenticado e protegido por CSRF
    // confirma esta requisição apenas, sem habilitar emissão por worker ou cron.
    $check=$pdo->prepare('SELECT document_type,status FROM os_fiscal_queue WHERE company_id=? AND id=? LIMIT 1');
    $check->execute(array($companyId,$queueId));
    $fiscalJob=$check->fetch(PDO::FETCH_ASSOC);
    if (!$fiscalJob) throw new RuntimeException('Item fiscal não encontrado nesta empresa.');
    if (os_fiscal_type($fiscalJob['document_type'])==='NFSE' && strtoupper((string)$fiscalJob['status'])==='PENDENTE'
        && nfse_provider_company_code($pdo,$companyId)==='PRONIM'
        && strtoupper((string)(fiscal_company_config($pdo,$companyId)['environment']??''))==='PRODUCAO'
        && !defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) {
        define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED',true);
    }
    $result=os_fiscal_process_queue_job($pdo,$companyId,$queueId);
    osfp_json($result,!empty($result['busy'])?409:200);
}catch(Throwable $e){
    osfp_json(array('ok'=>false,'message'=>$e->getMessage()),500);
}
