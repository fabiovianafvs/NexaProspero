<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/contracts_module.php';
require_once __DIR__ . '/../config/billing_module.php';

function pcl_return(string $raw): string {
  $raw=trim($raw); if($raw===''||str_contains($raw,"\n")||str_contains($raw,"\r")) return 'pedidos.php';
  if(!preg_match('~^pedidos\.php(?:\?.*)?$~',$raw)) return 'pedidos.php'; return $raw;
}
function pcl_redirect(string $to,string $ok,string $err): never {
  $sep=str_contains($to,'?')?'&':'?';
  $q=[]; if($ok!=='')$q['contract_batch_ok']=$ok;if($err!=='')$q['contract_batch_err']=$err;
  header('Location: '.$to.($q?$sep.http_build_query($q):'')); exit;
}

$returnTo=pcl_return((string)($_POST['return_to']??''));
try {
  if($_SERVER['REQUEST_METHOD']!=='POST') throw new RuntimeException('Operação inválida.');
  nfse_emitter_csrf_check((string)($_POST['csrf_token']??''),'contract_nfse_batch');
  $action=(string)($_POST['action']??'');
  if(!in_array($action,['emit_nfse','generate_boleto'],true)) throw new RuntimeException('Ação não reconhecida.');
  $ids=array_values(array_unique(array_filter(array_map('intval',(array)($_POST['order_ids']??[])),fn($v)=>$v>0)));
  if(!$ids) throw new RuntimeException('Selecione pelo menos um pedido de contrato.');
  if(count($ids)>100) throw new RuntimeException('Por segurança, processe no máximo 100 pedidos por lote.');
  nfse_emitter_require_schema($pdo);
  $cid=(int)COMPANY_ID;$uid=contracts_user_id();$ph=implode(',',array_fill(0,count($ids),'?'));
  $st=$pdo->prepare("SELECT g.id generation_id,g.order_id,g.contract_id,g.competence,c.contract_number FROM contract_generations g INNER JOIN contracts c ON c.id=g.contract_id AND c.company_id=g.company_id WHERE g.company_id=? AND g.order_id IN ($ph)");
  $st->execute(array_merge([$cid],$ids));$allowed=[];foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r)$allowed[(int)$r['order_id']]=$r;
  if($action==='generate_boleto'){
    billing_ensure_schema($pdo);$success=0;$skipped=0;$registered=0;$errors=[];
    foreach($ids as $orderId){
      if(!isset($allowed[$orderId])){$errors[]='Pedido #'.$orderId.': não pertence a uma geração de contrato.';continue;}
      $g=$allowed[$orderId];
      try{
        $r=billing_prepare_boleto_for_generation($pdo,$cid,(int)$g['generation_id'],$uid);
        if(!empty($r['created']))$success++;else$skipped++;
        $charge=(array)($r['charge']??[]);$chargeId=(int)($charge['id']??0);
        if($chargeId>0){
          $auto=billing_auto_register_charge($pdo,$cid,$chargeId,$uid);
          if(!empty($auto['ok'])&&empty($auto['already']))$registered++;
          elseif(!empty($auto['attempted'])&&empty($auto['ok']))$errors[]='Pedido #'.$orderId.' (Contrato #'.$g['contract_number'].'): '.((string)($auto['error']??$auto['message']??'Falha no registro automático do boleto.'));
        }
      }
      catch(Throwable $e){$errors[]='Pedido #'.$orderId.' (Contrato #'.$g['contract_number'].'): '.($e->getMessage()?:'Falha ao preparar boleto.');}
    }
    $ok=$success.' boleto'.($success===1?'':'s').' preparado'.($success===1?'':'s').' com sucesso.';if($skipped)$ok.=' '.$skipped.' já existente'.($skipped===1?' foi mantido.':'s foram mantidos.');
    if($registered>0)$ok.=' '.$registered.' boleto'.($registered===1?'':'s').' registrado'.($registered===1?'':'s').' automaticamente no banco.';
    else $ok.=' As cobranças ficam na fila bancária conforme API/CNAB configurado em Caixas e Bancos.';
    pcl_redirect($returnTo,$ok,implode(' | ',$errors));
  }
  $success=0;$skipped=0;$errors=[];
  foreach($ids as $orderId){
    if(!isset($allowed[$orderId])){$errors[]='Pedido #'.$orderId.': não pertence a uma geração de contrato.';continue;}
    $g=$allowed[$orderId];
    try {
      $existing=$pdo->prepare("SELECT d.id,COALESCE(nd.status,d.status) nfse_status,nd.nfse_number FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.fiscal_document_id=d.id AND nd.company_id=d.company_id WHERE d.company_id=? AND d.model='NFSE' AND d.source_type='PEDIDO' AND d.source_id=? ORDER BY d.id DESC LIMIT 1");
      $existing->execute([$cid,$orderId]);$doc=$existing->fetch(PDO::FETCH_ASSOC)?:null;
      if($doc&&strtoupper((string)$doc['nfse_status'])==='AUTORIZADA'){$skipped++;continue;}
      $result=nfse_provider_authorize_order($pdo,$cid,$orderId);
      if(empty($result['ok'])) throw new RuntimeException((string)($result['message']??'NFS-e não autorizada.'));
      $number=(string)($result['nfse_number']??'');$desc='NFS-e'.($number!==''?' #'.$number:'').' autorizada para o Pedido #'.$orderId;
      $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,event_at,created_by) VALUES(?,?,?,'NFSE',?,'NFSE_AUTORIZADA',?,NOW(),?)")->execute([$cid,(int)$g['contract_id'],(int)$g['generation_id'],(int)($result['document']['id']??0),$desc,$uid]);
      $success++;
    } catch(Throwable $e){
      $msg=trim($e->getMessage())?:'Falha não identificada.';$errors[]='Pedido #'.$orderId.' (Contrato #'.$g['contract_number'].'): '.$msg;
      try{$pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,event_at,created_by) VALUES(?,?,?,'PEDIDO',?,'NFSE_ERRO',?,NOW(),?)")->execute([$cid,(int)$g['contract_id'],(int)$g['generation_id'],$orderId,mb_substr('Falha na emissão NFS-e: '.$msg,0,255),$uid]);}catch(Throwable $ignored){}
    }
  }
  $ok=$success.' NFS-e'.($success===1?'':'s').' emitida'.($success===1?'':'s').' com sucesso.';if($skipped)$ok.=' '.$skipped.' já autorizada'.($skipped===1?' foi ignorada.':'s foram ignoradas.');
  pcl_redirect($returnTo,$ok,implode(' | ',$errors));
} catch(Throwable $e){pcl_redirect($returnTo,'',$e->getMessage());}
