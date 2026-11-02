<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_crm.php';
require_once __DIR__.'/../config/crm_commercial.php';
$cid=crm_company_id();$id=max(0,(int)($_GET['id']??0));
$st=$pdo->prepare('SELECT a.* FROM crm_attachments a JOIN crm_attendances c ON c.company_id=a.company_id AND c.id=a.attendance_id WHERE a.company_id=? AND a.id=? LIMIT 1');
$st->execute([$cid,$id]);$a=$st->fetch(PDO::FETCH_ASSOC);
if(!$a){http_response_code(404);exit('Anexo não encontrado.');}
try{
  $path=crm_commercial_upload_dir($cid).'/'.basename((string)$a['storage_name']);
  if(!is_file($path)||!is_readable($path))throw new RuntimeException('Arquivo indisponível.');
  $mime=(string)$a['mime_type'];
  header('X-Content-Type-Options: nosniff');
  header('Cache-Control: private, no-store');
  header('Content-Type: '.$mime);
  header('Content-Disposition: attachment; filename="anexo-'.(int)$a['id'].'"; filename*=UTF-8\'\''.rawurlencode((string)$a['original_name']));
  header('Content-Length: '.(string)filesize($path));
  readfile($path);
}catch(Throwable $e){http_response_code(404);echo 'Anexo indisponível.';}
