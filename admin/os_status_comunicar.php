<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_customer_communication.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/email_service.php';

$cid=os_company_id();os_require_enabled($pdo,$cid);
if(function_exists('access_has_permission') && !access_has_permission($pdo,$cid,'os_monitor')) access_render_denied('Seu usuário não possui acesso às Ordens de Serviço.');
$id=max(0,(int)($_GET['id']??$_POST['id']??0));$channel=strtolower(trim((string)($_GET['channel']??$_POST['channel']??'whatsapp')));if(!in_array($channel,['whatsapp','email'],true))$channel='whatsapp';
function osc_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function osc_safe_return(string $v,string $fallback):string{$v=trim($v);if($v===''||preg_match('#^https?://#i',$v)||str_starts_with($v,'//')||str_contains($v,"\n")||str_contains($v,"\r"))return $fallback;return ltrim($v,'/');}
$returnTo=osc_safe_return((string)($_GET['return_to']??$_POST['return_to']??('os_editar.php?id='.$id.'&step=1')),'os_monitor.php');
if($id<=0){http_response_code(400);die('O.S. inválida.');}
$st=$pdo->prepare('SELECT o.*,s.name status_name,s.code status_code FROM os_orders o LEFT JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id WHERE o.company_id=? AND o.id=? LIMIT 1');$st->execute([$cid,$id]);$order=$st->fetch(PDO::FETCH_ASSOC);
if(!$order){http_response_code(404);die('O.S. não encontrada.');}
// Lista de modelos por status desta empresa. Seleção apenas altera o texto exibido.
$statusModels=[];
$stm=$pdo->prepare('SELECT id,name,code FROM os_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id');
$stm->execute([$cid]);
foreach($stm->fetchAll(PDO::FETCH_ASSOC) as $sm){
  $statusModels[]=['id'=>(int)$sm['id'],'name'=>(string)$sm['name'],
    'text'=>os_status_customer_message_template($pdo,$cid,(int)$sm['id'],(string)$sm['code'],(string)$sm['name'])];
}
$order['company_name']=os_status_customer_company_name($cid);$contact=os_status_customer_contact($pdo,$cid,$order);$template=os_status_customer_message_template($pdo,$cid,(int)($order['status_id']??0),(string)($order['status_code']??''),(string)($order['status_name']??''));$message=os_status_customer_render($template,os_status_customer_vars($order));
if($_SERVER['REQUEST_METHOD']==='POST')$message=trim((string)($_POST['message']??$message));
$phone=whatsapp_digits((string)($_POST['phone']??$contact['phone']));$email=strtolower(trim((string)($_POST['email']??$contact['email'])));$uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);$err='';$sent='';
if($_SERVER['REQUEST_METHOD']==='POST'){
  if($message==='')$err='Informe a mensagem.';
  elseif($channel==='whatsapp'){
    if($phone==='')$err='Informe o WhatsApp do cliente.';
    else{
      $mode=(string)($_POST['mode']??'api');
      if($mode==='web'){header('Location: https://wa.me/'.rawurlencode(whatsapp_normalize_phone($phone)).'?text='.rawurlencode($message));exit;}
      $cfg=whatsapp_load_config($pdo,$cid);$cfg['enabled']=true;$cfg['timeout_seconds']=max(45,(int)($cfg['timeout_seconds']??30));
      if(whatsapp_send_message($pdo,$cid,$phone,$message,'os_status_customer',$id,$cfg))$sent='Mensagem de status enviada pelo WhatsApp.';else $err='Não foi possível enviar a mensagem pelo serviço WhatsApp.';
    }
  }else{
    if(!filter_var($email,FILTER_VALIDATE_EMAIL))$err='Informe um e-mail válido do cliente.';
    elseif($uid<=0)$err='Usuário autenticado inválido para envio de e-mail.';
    else{
      try{$subject='Atualização da O.S. #'.str_pad((string)(int)$order['os_number'],6,'0',STR_PAD_LEFT).' — '.trim((string)$order['status_name']);$res=email_send_for_user($pdo,$cid,$uid,[['email'=>$email,'name'=>(string)($order['person_name_snapshot']??'')]],$subject,$message,[],'ordem_servico_status',$id,false);if(!empty($res['ok']))$sent='Mensagem de status enviada por e-mail.';else $err=trim((string)($res['error']??''))?:'Não foi possível enviar o e-mail.';}catch(Throwable $e){$err=$e->getMessage();}
    }
  }
}

admin_topbar('Avisar cliente sobre a O.S.','os_monitor');
?>
<style>
.osc-wrap{max-width:800px;margin:16px auto}.osc-card{background:#fff;border:1px solid #dbe4f0;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.osc-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.osc-head h1{margin:0;color:#0b1f5b;font-size:25px}.osc-muted{color:#64748b;line-height:1.5}.osc-form{display:grid;gap:12px;margin-top:16px}.osc-form label{display:grid;gap:6px;font-weight:850}.osc-form input,.osc-form select,.osc-form textarea{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;font:inherit}.osc-form textarea{min-height:160px;line-height:1.45;resize:vertical}.osc-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #eef2f7;padding-top:14px}.osc-wa{background:#25D366!important;color:#fff!important;border-color:#1ebe57!important}.osc-note{padding:11px 13px;border-radius:14px;background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}.osc-ok{padding:11px 13px;border-radius:14px;background:#ecfdf5;border:1px solid #a7f3d0;color:#047857;margin-top:12px}.osc-err{padding:11px 13px;border-radius:14px;background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;margin-top:12px}
</style><div class="osc-wrap"><section class="osc-card"><div class="osc-head"><div><h1>Atualização da O.S. #<?=str_pad((string)(int)$order['os_number'],6,'0',STR_PAD_LEFT)?></h1><div class="osc-muted">Status atual: <b><?=osc_h($order['status_name']??'')?></b> · Cliente: <b><?=osc_h($order['person_name_snapshot']??'')?></b></div></div><a class="btn" href="<?=osc_h($returnTo)?>">Voltar</a></div>
<?php if($sent!==''):?><div class="osc-ok"><?=osc_h($sent)?></div><?php endif;?><?php if($err!==''):?><div class="osc-err"><?=osc_h($err)?></div><?php endif;?>
<div class="osc-note" style="margin-top:14px">A mensagem padrão deste status pode ser personalizada em <b>Configurações da O.S. → Status</b>.</div>
<form method="post" class="osc-form"><input type="hidden" name="id" value="<?=$id?>"><input type="hidden" name="channel" value="<?=osc_h($channel)?>"><input type="hidden" name="return_to" value="<?=osc_h($returnTo)?>">
<?php if($channel==='whatsapp'):?><label>WhatsApp do cliente<input name="phone" value="<?=osc_h($phone)?>" inputmode="numeric" placeholder="Ex.: 31999999999"></label><?php else:?><label>E-mail do cliente<input type="email" name="email" value="<?=osc_h($email)?>" placeholder="cliente@exemplo.com"></label><?php endif;?>
<label>Modelo de mensagem
<select id="osc-modelo">
<option value="">Personalizado</option>
<?php foreach($statusModels as $sm): ?><option value="<?= (int)$sm['id'] ?>" <?= (int)$sm['id']===(int)$order['status_id']?'selected':'' ?>><?=osc_h($sm['name'])?></option><?php endforeach; ?>
</select></label>
<label>Mensagem<textarea id="osc-message" name="message" maxlength="5000"><?=osc_h($message)?></textarea></label>
<script>
(function(){
  var models=<?=json_encode($statusModels,JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  var vars=<?=json_encode(os_status_customer_vars($order),JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_AMP|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
  var sel=document.getElementById('osc-modelo'),txt=document.getElementById('osc-message');
  if(!sel||!txt)return;
  sel.addEventListener('change',function(){
    var m=models.find(function(x){return String(x.id)===sel.value;});
    if(!m)return;
    txt.value=m.text.replace(/\{\{([a-z_]+)\}\}/gi,function(all,key){return Object.prototype.hasOwnProperty.call(vars,key)?String(vars[key]):all;}).trim();
  });
  txt.addEventListener('input',function(){sel.value='';});
})();
</script>
<div class="osc-actions"><?php if($channel==='whatsapp'):?><button class="btn osc-wa" name="mode" value="api">Enviar pelo WhatsApp</button><button class="btn" name="mode" value="web">Abrir WhatsApp</button><a class="btn" href="os_status_comunicar.php?id=<?=$id?>&channel=email&return_to=<?=rawurlencode($returnTo)?>">✉ Usar e-mail</a><?php else:?><button class="btn btn-primary">✉ Enviar e-mail</button><a class="btn" href="os_status_comunicar.php?id=<?=$id?>&channel=whatsapp&return_to=<?=rawurlencode($returnTo)?>">Usar WhatsApp</a><?php endif;?><a class="btn" href="<?=osc_h($returnTo)?>">Agora não</a></div></form></section></div>
<?php admin_footer(); ?>
