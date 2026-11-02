<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../config/compras_pedido_documento.php';
require_once __DIR__.'/../config/email_service.php';
require_once __DIR__.'/../config/whatsapp_service.php';
$companyId=(int)COMPANY_ID;
$userId=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
if ($userId<=0) {http_response_code(403);exit('Sessão inválida.');}
$pedidoId=filter_var($_GET['pedido']??$_POST['pedido']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
$canal=(string)($_GET['canal']??$_POST['canal']??'');
if (!in_array($canal,['email','whatsapp','pdf'],true)) {http_response_code(400);exit('Canal inválido.');}
function cpe_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
try {$data=compras_doc_load($pdo,$companyId,$pedidoId);}catch(Throwable $e){http_response_code(404);exit('Pedido não encontrado nesta empresa.');}
$back='compras_pedidos.php?pedido='.$pedidoId;
if($canal==='pdf'){
  try{$doc=compras_doc_pdf($data);header('Content-Type: application/pdf');header('Content-Disposition: attachment; filename="'.$doc['filename'].'"');header('X-Content-Type-Options: nosniff');header('Cache-Control: no-store');echo $doc['content'];exit;}
  catch(Throwable $e){error_log('[compras_pedido_pdf] '.$e->getMessage());http_response_code(500);exit('Não foi possível gerar o PDF.');}
}
if(empty($_SESSION['compras_envio_csrf']))$_SESSION['compras_envio_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['compras_envio_csrf'];
// Modelos existentes por empresa e canal. Sem alterar cadastros ou schemas.
$modelList=[];
try {
  $state=$canal==='email' ? email_message_models_load($pdo,$companyId) : whatsapp_message_models_load($pdo,$companyId);
  foreach ((array)($state['items']??[]) as $model) {
    if (empty($model['active']) || (string)($model['context']??'') !== 'pedido_compra') continue;
    $modelList[]=$model;
  }
} catch(Throwable $e) { error_log('[compras_modelos] '.$e->getMessage()); }
$vars=[
  'empresa'=>(string)$data['empresa'],
  'loja'=>(string)$data['empresa'],
  'cliente'=>(string)$data['pedido']['fornecedor_nome'],
  'fornecedor'=>(string)$data['pedido']['fornecedor_nome'],
  'pedido'=>(string)$pedidoId,
  'pedido_id'=>(string)$pedidoId,
  'numero'=>(string)$pedidoId,
  'data'=>date('d/m/Y',strtotime((string)$data['pedido']['created_at'])),
  'total'=>'R$ '.number_format(compras_doc_total($data['itens']),2,',','.'),
  'itens'=>implode("\n",array_map(static fn($i)=>trim((string)$i['nome']).' — '.number_format((float)$i['quantidade'],3,',','.'),(array)$data['itens'])),
];
$defaultSubject='Pedido de Compra #'.$pedidoId.' — '.$data['empresa'];
$defaultText="Prezados,\n\nSegue o Pedido de Compra #{$pedidoId}, referente à empresa {$data['empresa']}.\nFornecedor: ".$data['pedido']['fornecedor_nome']."\nTotal: ".$vars['total']."\n\nDocumento em PDF anexo, sujeito à confirmação do fornecedor.";
$selectedModel=(int)($_POST['modelo_id']??0);
$modelFound=null;
foreach($modelList as $model){if((int)($model['id']??0)===$selectedModel && $selectedModel>0){$modelFound=$model;break;}}
if($_SERVER['REQUEST_METHOD']!=='POST'){
  $defaultId=(int)($state['defaults']['pedido_compra']??0);
  foreach($modelList as $model){if((int)$model['id']===$defaultId){$selectedModel=$defaultId;$modelFound=$model;break;}}
}
$subjectText=$modelFound && $canal==='email' ? email_render_template((string)($modelFound['subject']??''),$vars) : $defaultSubject;
$bodyText=$modelFound ? ($canal==='email' ? email_render_template((string)$modelFound['message'],$vars) : whatsapp_render_template((string)$modelFound['message'],$vars)) : $defaultText;
if($_SERVER['REQUEST_METHOD']==='POST'){
  $subjectText=trim((string)($_POST['assunto']??$subjectText));
  $bodyText=trim((string)($_POST['mensagem']??$bodyText));
}
$recipient=trim((string)($_POST['destino']??''));
$msg='';$success=false;
if($_SERVER['REQUEST_METHOD']==='POST'){
    if(!hash_equals($csrf,(string)($_POST['csrf']??''))){$msg='Sessão de envio expirada. Atualize a página.';}
    else {
      try{
        if($bodyText==='' || mb_strlen($bodyText)>12000) throw new RuntimeException('Informe uma mensagem de até 12.000 caracteres.');
        if($canal==='email'){
          if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Informe um e-mail válido.');
          $doc=compras_doc_pdf($data);
          $subject=$subjectText;
          if($subject==='' || mb_strlen($subject)>255) throw new RuntimeException('Informe um assunto de até 255 caracteres.');
          $body=nl2br(cpe_h($bodyText));
          $r=email_send_for_user($pdo,$companyId,$userId,[['email'=>$recipient,'name'=>(string)$data['pedido']['fornecedor_nome']]],$subject,$body,[$doc],'compra_pedido',$pedidoId);
          $success=!empty($r['ok']);$msg=$success?'E-mail enviado com PDF anexo.':('Falha no e-mail: '.(string)($r['error']??'Confira sua configuração SMTP.'));
        }else{
          require_once __DIR__.'/../config/whatsapp_service.php';
          $phone=whatsapp_digits($recipient);
          if(strlen($phone)<10||strlen($phone)>13)throw new RuntimeException('Informe um telefone válido com DDD.');
          if((string)($_POST['modo']??'api')==='web'){
            $url='https://wa.me/'.rawurlencode(whatsapp_normalize_phone($phone)).'?text='.rawurlencode($bodyText);
            header('Location: '.$url);exit;
          }
          $cfg=whatsapp_load_config($pdo,$companyId);
          $cfg['enabled']=true;
          $doc=compras_doc_pdf($data);
          $r=whatsapp_send_document($pdo,$companyId,$phone,$bodyText,$doc['filename'],$doc['mimetype'],$doc['content'],'compras_pedido_documento',null,$cfg);
          $success=!empty($r['ok']);$msg=$success?'Documento PDF enviado pelo WhatsApp.':('Falha no WhatsApp: '.(string)($r['error']??'Confira a conexão do serviço.'));
        }
      }catch(Throwable $e){error_log('[compras_pedido_enviar] '.$e->getMessage());$msg=$e instanceof RuntimeException?$e->getMessage():'Falha no envio; consulte o log PHP.';}
    }
}
require __DIR__.'/_chrome.php';
admin_topbar('Enviar Pedido de Compra','produtos');
?>
<style>.cpe-wrap{width:min(680px,calc(100% - 32px));margin:20px auto;font-family:inherit;color:#19365a;font-size:13px}.cpe-card{background:#fff;border:1px solid #d8e3f1;border-radius:10px;padding:22px}.cpe-label{display:block;margin:14px 0 5px;font-weight:600}.cpe-input{padding:10px;border:1px solid #bfcfe3;border-radius:6px;width:100%;box-sizing:border-box}.cpe-btn{display:inline-block;background:#1758b6;border:0;color:#fff;border-radius:6px;padding:10px 15px;margin-top:16px;cursor:pointer;font:inherit}.cpe-alert{background:#eff5fc;border:1px solid #c1d7ef;padding:12px;border-radius:6px;margin-top:12px}.cpe-choices{display:flex;gap:14px;margin-top:8px}</style>
<main class="cpe-wrap"><div class="cpe-card"><h1>Enviar Pedido de Compra #<?= $pedidoId ?></h1><p><b>Fornecedor:</b> <?=cpe_h($data['pedido']['fornecedor_nome'])?> · <b>Total:</b> R$ <?=number_format(compras_doc_total($data['itens']),2,',','.')?></p><p>O destino deve ser conferido antes do envio.</p>
<?php if($msg!==''):?><div class="cpe-alert" role="status"><?=cpe_h($msg)?></div><?php endif;?>
<form method="post"><input type="hidden" name="pedido" value="<?=$pedidoId?>"><input type="hidden" name="canal" value="<?=cpe_h($canal)?>"><input type="hidden" name="csrf" value="<?=cpe_h($csrf)?>">
<label class="cpe-label" for="cpe-to"><?= $canal==='email'?'E-mail do fornecedor':'WhatsApp do fornecedor (DDD + número)' ?></label>
<input class="cpe-input" id="cpe-to" name="destino" value="<?=cpe_h($recipient)?>" <?= $canal==='email'?'type="email"':'type="tel"' ?> required autocomplete="off" autofocus>
<label class="cpe-label" for="cpe-modelo">Modelo de mensagem</label>
<select class="cpe-input" id="cpe-modelo" name="modelo_id"><option value="0">Mensagem padrão do Pedido de Compra</option>
<?php foreach($modelList as $model): ?><option value="<?=(int)$model['id']?>" <?= $selectedModel===(int)$model['id']?'selected':'' ?>><?=cpe_h(($model['title']??'Modelo').' · Pedido de Compra')?></option><?php endforeach;?></select>
<?php if(!$modelList): ?><p style="color:#64748b">Não há modelos ativos para <strong>Pedido de Compra</strong> neste canal. Cadastre um em <a href="whatsapp_modelos.php?new=1&amp;channel=<?=cpe_h($canal)?>&amp;context=pedido_compra" target="_blank" rel="noopener">Modelos de Mensagens</a>, escolhendo o contexto Pedido de Compra.</p><?php endif;?>
<?php if($canal==='email'): ?><label class="cpe-label" for="cpe-subject">Assunto</label><input class="cpe-input" id="cpe-subject" name="assunto" maxlength="255" required value="<?=cpe_h($subjectText)?>"><?php endif;?>
<label class="cpe-label" for="cpe-message">Mensagem (pode editar antes de enviar)</label>
<textarea class="cpe-input" id="cpe-message" name="mensagem" rows="7" maxlength="12000" required><?=cpe_h($bodyText)?></textarea>
<script>
const cpeModels=<?=json_encode(array_map(static function($m)use($vars,$canal){return ['id'=>(int)$m['id'],'subject'=>$canal==='email'?email_render_template((string)($m['subject']??''),$vars):'','body'=>$canal==='email'?email_render_template((string)($m['message']??''),$vars):whatsapp_render_template((string)($m['message']??''),$vars)];},$modelList),JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
const cpeDefault=<?=json_encode(['subject'=>$defaultSubject,'body'=>$defaultText],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?>;
document.getElementById('cpe-modelo').addEventListener('change',function(){const m=cpeModels.find(x=>String(x.id)===this.value)||cpeDefault;document.getElementById('cpe-message').value=m.body;const subject=document.getElementById('cpe-subject');if(subject)subject.value=m.subject||cpeDefault.subject;});
</script>
<?php if($canal==='whatsapp'):?><label class="cpe-label">Forma de envio</label><div class="cpe-choices"><label><input type="radio" name="modo" value="api" checked> Enviar PDF pelo WhatsApp conectado</label><label><input type="radio" name="modo" value="web"> Abrir WhatsApp Web (mensagem sem PDF)</label></div><?php endif;?>
<button class="cpe-btn" type="submit">Confirmar envio</button> <a style="margin-left:15px;color:#1758b6" href="<?=cpe_h($back)?>">Voltar ao pedido</a>
</form><p style="margin-top:18px;color:#64748b">O e-mail utiliza a configuração SMTP do usuário. O WhatsApp conectado utiliza o serviço configurado para a empresa. Nenhuma ação altera o pedido, estoque, financeiro ou fiscal.</p></div></main>
