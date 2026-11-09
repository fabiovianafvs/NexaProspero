<?php
/** Cancelamento fiscal de NFS-e de O.S., com estorno do pedido vinculado. */
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/../config/os_nfse_cancel_bridge.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/order_cancellation.php';

$pdo=os_db();$cid=os_company_id();$osId=max(0,(int)($_POST['os_id']??0));
$invoiceId=max(0,(int)($_POST['invoice_id']??0));
$returnTo=$invoiceId>0?'os_faturas.php?id='.$invoiceId:'os_fiscal_documentos.php'.($osId>0?'?os_id='.$osId:'');
$redirect=static function(string $key,string $message)use($returnTo):never {
  header('Location: '.$returnTo.(str_contains($returnTo,'?')?'&':'?').$key.'='.rawurlencode($message));exit;
};
try{
  if(($_SERVER['REQUEST_METHOD']??'')!=='POST')throw new RuntimeException('Operação permitida somente via formulário.');
  os_require_enabled($pdo,$cid);
  os_fiscal_csrf_check((string)($_POST['csrf']??''));
  if(trim((string)($_POST['confirmacao']??''))!=='CANCELAR')throw new RuntimeException('Digite CANCELAR para confirmar o cancelamento fiscal e comercial.');
  $docId=max(0,(int)($_POST['doc_id']??0));
  if($docId<1||($osId<1 && $invoiceId<1))throw new RuntimeException('Documento ou vínculo de O.S. inválido.');
  $reason=trim((string)($_POST['motivo']??''));
  $reasonLength=function_exists('mb_strlen')?mb_strlen($reason,'UTF-8'):strlen($reason);
  if($reasonLength<15||$reasonLength>255)throw new RuntimeException('Informe um motivo entre 15 e 255 caracteres.');
  $reasonCode=in_array((string)($_POST['motivo_codigo']??'9'),['1','2','9'],true)?(string)$_POST['motivo_codigo']:'9';
  $s=$pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? AND model='NFSE' AND direction='SAIDA' LIMIT 1");
  $s->execute([$cid,$docId]);$doc=$s->fetch(PDO::FETCH_ASSOC)?:null;
  if(!$doc)throw new RuntimeException('NFS-e não encontrada nesta empresa.');
  $pedidoId=0;
  if($invoiceId>0){
    $s=$pdo->prepare("SELECT pedido_id FROM os_batch_invoices WHERE company_id=? AND id=? AND status='FATURADA' LIMIT 1");
    $s->execute([$cid,$invoiceId]);$pedidoId=(int)$s->fetchColumn();
    if($pedidoId<=0)throw new RuntimeException('Fatura não está faturada ou não possui pedido vinculado.');
  }else{
    $s=$pdo->prepare("SELECT pedido_id FROM os_orders WHERE company_id=? AND id=? AND billing_status='FATURADA' LIMIT 1");
    $s->execute([$cid,$osId]);$pedidoId=(int)$s->fetchColumn();
    if($pedidoId<=0)throw new RuntimeException('O.S. não está faturada ou não possui pedido vinculado.');
  }
  $s=$pdo->prepare("SELECT id,status FROM pedidos WHERE company_id=? AND id=? LIMIT 1");$s->execute([$cid,$pedidoId]);$pedido=$s->fetch(PDO::FETCH_ASSOC)?:null;
  if(!$pedido)throw new RuntimeException('Pedido comercial não encontrado.');
  // Revalida o vínculo do documento: evita cancelamento de NFS-e alheia ao pedido.
  $related=os_nfse_related_authorized_documents($pdo,$cid,$pedidoId);
  $hasLink=($doc['source_type']==='PEDIDO'&&(int)$doc['source_id']===$pedidoId);
  if(!$hasLink && $doc['source_type']==='ORDEM_SERVICO'){
    $s=$pdo->prepare('SELECT id FROM os_orders WHERE company_id=? AND pedido_id=? AND id=? LIMIT 1');
    $s->execute([$cid,$pedidoId,(int)$doc['source_id']]);$hasLink=(bool)$s->fetchColumn();
  }
  if(!$hasLink){$s=$pdo->prepare('SELECT 1 FROM fiscal_nfse_details WHERE company_id=? AND fiscal_document_id=? AND pedido_id=? LIMIT 1');$s->execute([$cid,$docId,$pedidoId]);$hasLink=(bool)$s->fetchColumn();}
  if(!$hasLink)throw new RuntimeException('Documento fiscal não pertence ao pedido faturado desta O.S.');
  foreach($related as $r){
    if((int)$r['id']===$docId)continue;
    $model=(string)$r['model'];
    throw new RuntimeException('Outro documento fiscal autorizado ('.$model.' #'.$r['id'].') está associado ao pedido. Cancele ou regularize esse documento antes de cancelar a NFS-e e liberar a O.S.');
  }
  if(function_exists('billing_assert_order_can_cancel_locally'))billing_assert_order_can_cancel_locally($pdo,$cid,$pedidoId);
  // Só libera a O.S. após cancelamento confirmado no provedor e estorno comercial concluído.
  $status=strtoupper(trim((string)$doc['status']));
  if($status==='AUTORIZADA'){
    if(strtoupper((string)$doc['environment'])==='PRODUCAO' && !defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED'))define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED',true);
    $result=nfse_provider_cancel_document($pdo,$cid,$docId,$reasonCode,$reason);
    if(empty($result['ok']))throw new RuntimeException((string)($result['message']??'Provedor fiscal não confirmou o cancelamento. Consulte a situação antes de repetir.'));
  }elseif($status!=='CANCELADA'){
    throw new RuntimeException('Somente NFS-e autorizada ou cancelada pode ser processada.');
  }
  $result=nexa_cancel_order($pdo,$cid,$pedidoId,$reason,'MANUAL',$docId,(int)(auth_user_id()??0));
  if(strtoupper((string)($result['status']??'COMPLETED'))!=='COMPLETED' && empty($result['already_processed']))throw new RuntimeException('Cancelamento fiscal concluído; estorno comercial pendente. Verifique os lançamentos antes de refaturar.');
  $redirect('ok','NFS-e cancelada e pedido estornado. A(s) O.S. vinculada(s) foram liberadas para novo faturamento.');
}catch(Throwable $e){error_log('[Nexa OS cancelar NFS-e] '.$e->getMessage());$redirect('err',$e->getMessage());}
