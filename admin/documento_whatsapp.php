<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/customer_document_pdf.php';
require_once __DIR__ . '/../config/os_document_share_options.php';
require_once __DIR__ . '/../config/orcamento_module.php';
require_once __DIR__ . '/../config/order_message.php';
require_once __DIR__ . '/../config/order_email_pdf.php';
require_once __DIR__ . '/../config/load_config.php';

$cid=(int)COMPANY_ID;
$type=strtolower(trim((string)($_GET['type']??$_POST['type']??'')));
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$model=strtolower(trim((string)($_GET['modelo']??$_POST['modelo']??'')));
$osShareOptions=nexa_os_share_options($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
function ndw_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function ndw_money($v):string{return 'R$ '.number_format((float)$v,2,',','.');}
function ndw_safe_return(string $v,string $fallback):string{$v=trim($v);if($v===''||preg_match('#^https?://#i',$v)||str_starts_with($v,'//')||str_contains($v,"\n")||str_contains($v,"\r"))return $fallback;return ltrim($v,'/');}
function ndw_date($v,bool $time=false):string{$t=$v?strtotime((string)$v):false;return $t?date($time?'d/m/Y H:i':'d/m/Y',$t):'';}
if($id<=0){http_response_code(400);die('Documento inválido.');}
if(function_exists('access_has_permission')){
  if($type==='orcamento' && !access_has_permission($pdo,$cid,'orcamentos')) access_render_denied('Seu usuário não possui acesso aos Orçamentos.');
  if($type==='os' && !access_has_permission($pdo,$cid,'os_monitor')) access_render_denied('Seu usuário não possui acesso às Ordens de Serviço.');
}

$payload=[];$context='';$pageTitle='Enviar por WhatsApp';$docLabel='Documento';$customer='cliente';$phone='';$message='';$filenameHint='PDF';$returnFallback='';$nav='';
$messageFallback='';$messageVars=[];$pedidoBaseWaCfg=null;
try{
  if($type==='pedido'){
    $loaded=order_message_load($pdo,$cid,$id);if(!$loaded)throw new RuntimeException('Pedido não encontrado.');$o=(array)$loaded['pedido'];$items=(array)$loaded['itens'];
    if(!in_array($model,['72mm','a4'],true))$model='a4';
    $number=function_exists('company_sequence_display')?company_sequence_display($o,'pedidos'):(int)($o['id']??$id);$customer=trim((string)($o['nome_cliente']??''))?:'cliente';
    $phone=trim((string)($o['whatsapp']??''));
    $appCfg=load_app_config($cid);try{$biz=load_business_config($cid);$catalogTitle=trim((string)($biz['brand']['catalog_title']??''));if($catalogTitle!==''){$appCfg['site_name']=$catalogTitle;if(!isset($appCfg['brand'])||!is_array($appCfg['brand']))$appCfg['brand']=[];$appCfg['brand']['catalog_title']=$catalogTitle;}}catch(Throwable $e){}
    $pedidoBaseWaCfg=whatsapp_load_config($pdo,$cid,false);$messageFallback=order_message_build_full($o,$items,$appCfg,$pedidoBaseWaCfg);
    $companyName=trim((string)($appCfg['site_name']??$appCfg['brand']['catalog_title']??''))?:'Nexa Próspero';
    $payload=['order'=>$o,'items'=>$items,'company_name'=>$companyName,'app_cfg'=>$appCfg];$context='pedido';$pageTitle='Enviar Pedido #'.$number.' por WhatsApp';$docLabel='Pedido #'.$number;$filenameHint='Pedido em PDF';$returnFallback='pedido_print.php?id='.$id.'&modelo='.$model.'&autoprint=0';$nav='pedidos';
  }elseif($type==='orcamento'){
    $payload=nexa_quote_document_load($pdo,$cid,$id);$q=(array)$payload['quote'];$p=(array)$payload['person'];
    if(!in_array($model,['72mm','a4'],true))$model='a4';
    $number=str_pad((string)(int)$q['quote_number'],6,'0',STR_PAD_LEFT);$customer=trim((string)($q['person_name_snapshot']??''))?:'cliente';
    $phone=trim((string)($p['whatsapp']??''))?:trim((string)($p['phone']??''));if($phone==='')$phone=trim((string)($q['person_phone_snapshot']??''));
    $context='orcamento';$pageTitle='Enviar Orçamento #'.$number.' por WhatsApp';$docLabel='Orçamento #'.$number;$filenameHint='Orçamento em PDF';$returnFallback='orcamento_imprimir.php?id='.$id.'&modelo='.$model;$nav='orcamentos';
    $messageFallback="Olá, {$customer}!\n\nSegue em anexo o Orçamento #{$number}.\nData: ".ndw_date($q['quote_date']??'')."\nTotal: ".ndw_money($q['total_general']??0)."\n\nFicamos à disposição.";
    $messageVars=['empresa'=>trim((string)($payload['company']['name']??''))?:'Nexa Próspero','cliente'=>$customer,'orcamento'=>$number,'data'=>ndw_date($q['quote_date']??''),'total'=>ndw_money($q['total_general']??0),'status'=>orc_status_label((string)($q['status']??''))];
  }elseif($type==='os'){
    $payload=nexa_os_document_load($pdo,$cid,$id);$o=(array)$payload['order'];$p=(array)$payload['person'];
    if(!in_array($model,['72mm','a4_half','a4_full','a4_landscape_dual'],true))$model='a4_half';
    $number=str_pad((string)(int)$o['os_number'],6,'0',STR_PAD_LEFT);$customer=trim((string)($o['person_name_snapshot']??''))?:'cliente';
    $phone=trim((string)($o['contact_phone']??''));if($phone==='')$phone=trim((string)($p['whatsapp']??''))?:trim((string)($p['phone']??''));if($phone==='')$phone=trim((string)($o['person_phone_snapshot']??''));
    $context='ordem_servico';$pageTitle='Enviar O.S. #'.$number.' por WhatsApp';$docLabel='Ordem de Serviço #'.$number;$filenameHint='O.S. em PDF';$returnFallback='os_imprimir.php?id='.$id.'&modelo='.$model.nexa_os_share_query($osShareOptions);$nav='os_monitor';
    $status=trim((string)($o['status_name']??''));$amountText=$osShareOptions['conteudo']=='completa'?"\nTotal: ".ndw_money($o['total_general']??0):'';$messageFallback="Olá, {$customer}!\n\nSegue em anexo a Ordem de Serviço #{$number}.\nStatus: {$status}{$amountText}\n\nFicamos à disposição.";
    $messageVars=['empresa'=>trim((string)($payload['company']['name']??''))?:'Nexa Próspero','cliente'=>$customer,'os'=>$number,'data'=>ndw_date($o['opened_at']??'',true),'status'=>$status,'previsao'=>ndw_date($o['expected_at']??'',true),'total'=>$osShareOptions['conteudo']==='completa'?ndw_money($o['total_general']??0):''];
  }else throw new RuntimeException('Tipo de documento não suportado.');
}catch(Throwable $e){admin_topbar('Enviar por WhatsApp','');echo '<div style="max-width:820px;margin:18px auto;padding:18px;background:#fff;border:1px solid #fecaca;border-radius:18px;color:#991b1b;font-weight:800">'.ndw_h($e->getMessage()).'</div>';admin_footer();exit;}

/* Seleção de modelo segue o mesmo conceito usado no envio por e-mail. */
$messageState=whatsapp_message_models_load($pdo,$cid);
$messageModels=whatsapp_message_models_for_context($pdo,$cid,$context);
$defaultMessageModel=max(0,(int)($messageState['defaults'][$context]??0));
$requestedMessageModel=max(0,(int)($_POST['model_id']??$_GET['model_id']??$defaultMessageModel));
$validMessageIds=[0=>true];foreach($messageModels as $m)$validMessageIds[(int)$m['id']]=true;
if(empty($validMessageIds[$requestedMessageModel]))$requestedMessageModel=!empty($validMessageIds[$defaultMessageModel])?$defaultMessageModel:0;
$messageRendered=[0=>$messageFallback];
if($context==='pedido'){
  foreach($messageModels as $m){$mid=(int)$m['id'];$cfg=is_array($pedidoBaseWaCfg)?$pedidoBaseWaCfg:whatsapp_load_config($pdo,$cid,false);$cfg['templates']['order_customer_full']=(string)$m['message'];$messageRendered[$mid]=order_message_build_full((array)$payload['order'],(array)$payload['items'],(array)$payload['app_cfg'],$cfg);}
}else{
  foreach($messageModels as $m){$mid=(int)$m['id'];$messageRendered[$mid]=whatsapp_message_model_render($pdo,$cid,$context,$messageVars,$messageFallback,$mid);}
}
$message=(string)($messageRendered[$requestedMessageModel]??$messageFallback);

$returnTo=ndw_safe_return((string)($_GET['return_to']??$_POST['return_to']??$returnFallback),$returnFallback);
$phone=whatsapp_digits((string)($_POST['phone']??$_GET['phone']??$phone));
if($_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['message']))$message=trim((string)$_POST['message']);
$err='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if($phone==='')$err='Informe o número do WhatsApp para envio.';
  elseif($message==='')$err='Informe a mensagem que acompanhará o documento.';
  else{
    $mode=(string)($_POST['mode']??'api');
    if($mode==='api'){
      try{
        $pdf=$type==='pedido'?order_email_pdf_build((array)$payload['order'],(array)$payload['items'],(string)$payload['company_name'],$model,$pdo,$cid):($type==='orcamento'?nexa_quote_document_pdf_build($payload,$model):nexa_os_document_pdf_build($payload,$model,$osShareOptions['conteudo'],$osShareOptions['papel'],$osShareOptions['perfil_impressao']));
        $cfg=whatsapp_load_config($pdo,$cid);$cfg['enabled']=true;$cfg['timeout_seconds']=max(60,(int)($cfg['timeout_seconds']??30));
        $send=whatsapp_send_document($pdo,$cid,$phone,$message,(string)$pdf['filename'],(string)$pdf['mimetype'],(string)$pdf['content'],$type.'_document_admin',$id,$cfg);
        if(!empty($send['ok'])){$sep=str_contains($returnTo,'?')?'&':'?';header('Location: '.$returnTo.$sep.'ok='.rawurlencode($docLabel.' enviado em PDF pelo WhatsApp.'));exit;}
        $err=trim((string)($send['error']??''))?:'Não foi possível enviar o PDF pelo serviço WhatsApp.';
      }catch(Throwable $e){$err=$e->getMessage();}
    }else{
      $to=whatsapp_normalize_phone($phone);header('Location: https://wa.me/'.rawurlencode($to).'?text='.rawurlencode($message."\n\nAnexe o PDF manualmente nesta conversa."));exit;
    }
  }
}

admin_topbar($pageTitle,$nav);
?>
<style>
.ndw-wrap{max-width:820px;margin:16px auto 28px}.ndw-card{background:#fff;border:1px solid #dbe4f0;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.ndw-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.ndw-head h1{margin:0;color:#0b1f5b;font-size:25px}.ndw-muted{color:#64748b;line-height:1.5}.ndw-file{display:flex;gap:12px;align-items:center;border:1px solid #dbe4f0;background:#f8fafc;border-radius:15px;padding:12px;margin:14px 0}.ndw-icon{width:46px;height:46px;border-radius:13px;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;font-weight:950}.ndw-form{display:grid;gap:12px}.ndw-form label{display:grid;gap:6px;font-weight:850}.ndw-form input,.ndw-form textarea,.ndw-form select{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;font:inherit;background:#fff}.ndw-form textarea{min-height:185px;resize:vertical;line-height:1.45}.ndw-actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end;border-top:1px solid #eef2f7;padding-top:14px}.ndw-wa{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important}.ndw-note{padding:11px 13px;border-radius:14px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.ndw-err{padding:11px 13px;border-radius:14px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;margin-bottom:12px}@media(max-width:620px){.ndw-actions .btn{width:100%}}
</style>
<div class="ndw-wrap"><section class="ndw-card">
<div class="ndw-head"><div><h1><?=ndw_h($pageTitle)?></h1><div class="ndw-muted" style="margin-top:5px">Cliente: <b><?=ndw_h($customer)?></b></div></div><a class="btn" href="<?=ndw_h($returnTo)?>">Voltar</a></div>
<div class="ndw-file"><div class="ndw-icon">PDF</div><div><strong><?=ndw_h($docLabel)?></strong><div class="ndw-muted"><?=ndw_h($filenameHint)?> · <?=ndw_h($type==='os'?nexa_os_share_label($osShareOptions['conteudo']):'modelo '.strtoupper($model))?></div></div></div>
<?php if($err!==''):?><div class="ndw-err"><?=ndw_h($err)?></div><?php endif;?>
<form method="post" class="ndw-form"><input type="hidden" name="type" value="<?=ndw_h($type)?>"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="modelo" value="<?=ndw_h($model)?>"><?php if($type==='os'): ?><input type="hidden" name="conteudo" value="<?=ndw_h($osShareOptions['conteudo'])?>"><input type="hidden" name="papel" value="<?=ndw_h($osShareOptions['papel'])?>"><input type="hidden" name="perfil_impressao" value="<?=ndw_h($osShareOptions['perfil_impressao'])?>"><?php endif; ?><input type="hidden" name="return_to" value="<?=ndw_h($returnTo)?>">
<label>Número do WhatsApp<input name="phone" value="<?=ndw_h($phone)?>" placeholder="Ex.: 31999999999" inputmode="numeric" autofocus></label>
<label>Modelo da mensagem<select name="model_id" id="ndw-model"><option value="0" <?=$requestedMessageModel===0?'selected':''?>>Mensagem padrão do sistema</option><?php foreach($messageModels as $m):$mid=(int)$m['id'];?><option value="<?=$mid?>" <?=$requestedMessageModel===$mid?'selected':''?>><?=ndw_h((string)$m['title'])?><?=$defaultMessageModel===$mid?' — padrão':''?></option><?php endforeach;?></select></label>
<label>Mensagem que acompanhará o PDF<textarea id="ndw-message" name="message" maxlength="12000"><?=ndw_h($message)?></textarea></label>
<div class="ndw-note"><b>Modelo selecionável:</b> escolha o texto desejado antes do envio. Os modelos são cadastrados em <b>WhatsApp → Modelos de mensagens</b>, no contexto <?=ndw_h($type==='pedido'?'Pedido':($type==='orcamento'?'Orçamento':'Ordem de Serviço'))?>.</div>
<div class="ndw-actions"><button class="btn ndw-wa" name="mode" value="api">Enviar PDF pelo WhatsApp</button><button class="btn" name="mode" value="web">Abrir WhatsApp sem anexo</button><a class="btn" href="email_documento.php?type=<?=rawurlencode($type)?>&id=<?=$id?>&modelo=<?=rawurlencode($model)?><?= $type==='os'?ndw_h(nexa_os_share_query($osShareOptions)):'' ?>&return_to=<?=rawurlencode($returnTo)?>">✉ E-mail</a><a class="btn" href="<?=ndw_h($returnTo)?>">Cancelar</a></div>
</form></section></div>
<script>
(function(){const sel=document.getElementById('ndw-model'),box=document.getElementById('ndw-message');if(!sel||!box)return;const rendered=<?=json_encode($messageRendered,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;sel.addEventListener('change',function(){const key=String(this.value);if(Object.prototype.hasOwnProperty.call(rendered,key))box.value=rendered[key];});})();
</script>
<?php admin_footer(); ?>
