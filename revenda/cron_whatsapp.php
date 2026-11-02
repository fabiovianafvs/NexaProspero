<?php
declare(strict_types=1);
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/reseller_integrations.php';
header('Content-Type: application/json; charset=utf-8');
$resellerId=max(0,(int)($_GET['reseller']??0));
if($resellerId<=0){$hostReseller=reseller_resolve_context($pdo,true);$resellerId=(int)($hostReseller['id']??0);}
if($resellerId<=0){http_response_code(404);echo json_encode(['ok'=>false,'error'=>'Revenda não encontrada.']);exit;}
$cfg=reseller_whatsapp_load_config($pdo,$resellerId);$token=(string)($_GET['token']??'');
if(!hash_equals((string)$cfg['cron_token'],$token)){http_response_code(403);echo json_encode(['ok'=>false,'error'=>'Token inválido.']);exit;}
try{$r=reseller_whatsapp_send_due_notices($pdo,$resellerId);echo json_encode(['ok'=>true]+$r,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}catch(Throwable $e){http_response_code(500);echo json_encode(['ok'=>false,'error'=>$e->getMessage()]);}
