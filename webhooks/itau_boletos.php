<?php
declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/itau_webhook.php';

function itau_boleto_webhook_json(int $status, array $data): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
    exit;
}

try {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') itau_boleto_webhook_json(405,['ok'=>false,'message'=>'Método não permitido.']);
    billing_ensure_schema($pdo);
    finance_ensure_schema($pdo);
    $bearer=itau_webhook_find_bearer();
    if($bearer==='') itau_boleto_webhook_json(401,['ok'=>false,'message'=>'Bearer token ausente.']);
    $cfg=itau_webhook_validate_bearer($pdo,$bearer);
    $raw=(string)(file_get_contents('php://input')?:'');
    $payload=json_decode($raw,true);
    if(!is_array($payload)) itau_boleto_webhook_json(400,['ok'=>false,'message'=>'JSON inválido.']);
    $boletos=$payload['boletos']??[];
    if(!is_array($boletos)) $boletos=[];
    $matched=0;$received=0;$processed=0;$errors=0;$pending=0;$results=[];
    foreach($boletos as $item){
        if(!is_array($item)) continue;
        $received++;
        $stored=itau_webhook_store_event($pdo,$cfg,$item,$raw);
        if(!empty($stored['matched']))$matched++;
        $r=itau_webhook_process_event($pdo,$cfg,$item,$stored);
        if(!empty($r['processed']))$processed++;
        elseif(($r['processing_status']??'')==='ERRO')$errors++;
        else$pending++;
        $results[]=[
            'nosso_numero'=>$r['nosso_numero']??'',
            'matched'=>!empty($r['matched']),
            'processed'=>!empty($r['processed']),
            'status'=>$r['processing_status']??'',
        ];
    }
    // O Itaú pode notificar BAIXA_OPERACIONAL e BAIXA_EFETIVA do mesmo título.
    // A baixa financeira é idempotente: uma cobrança já marcada PAGO não gera nova liquidação.
    itau_boleto_webhook_json(200,['ok'=>true,'received'=>$received,'matched'=>$matched,'processed'=>$processed,'pending'=>$pending,'errors'=>$errors,'results'=>$results]);
} catch (Throwable $e) {
    error_log('Nexa Itau boleto webhook: '.$e->getMessage());
    itau_boleto_webhook_json(401,['ok'=>false,'message'=>'Falha de autenticação ou processamento.']);
}
