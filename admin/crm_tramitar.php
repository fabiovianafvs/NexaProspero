<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_crm.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/crm_commercial.php';
require_once __DIR__ . '/../config/crm_proposal_number.php';

$cid=crm_company_id();
$uid=crm_user_id();
$id=max(0,(int)($_GET['id']??$_POST['id']??0));
$row=crm_attendance($pdo,$cid,$id);
if(!$row){http_response_code(404);exit('Atendimento não encontrado.');}

$fromMobile=((string)($_GET['from']??$_POST['from']??''))==='mobile';
$returnTo=trim((string)($_GET['return_to']??$_POST['return_to']??''));
if($fromMobile){
  if($returnTo===''||!preg_match('#^crm_atendimento_mobile(?:_lista)?\.php(?:\?.*)?$#',$returnTo))$returnTo='crm_atendimento_mobile_lista.php';
}else{$returnTo='crm_atendimentos.php';}
$originQuery=$fromMobile?'&from=mobile&return_to='.rawurlencode($returnTo):'';

$users=crm_users($pdo,$cid,true);
$err='';$ok='';
$movement='';
$pendingReason=(string)($row['pending_reason']??'');
$nextDate=(string)($row['next_contact_date']?:crm_next_contact_default());
$responsible=(int)($row['responsible_user_id']?:$uid);
$temperature=crm_temperature_clamp($row['lead_temperature']??20);
$messageTo=trim((string)($row['phone']??''));
$messageText='';
$messageModelId=0;
$sendAlong=false;

$waState=whatsapp_message_models_load($pdo,$cid);
$crmModels=[];
foreach((array)($waState['items']??[]) as $item){
  if((string)($item['context']??'')==='crm'&&!empty($item['active']))$crmModels[]=$item;
}
$defaultCrmModelId=(int)($waState['defaults']['crm']??0);
if($defaultCrmModelId>0)$messageModelId=$defaultCrmModelId;

$companyName='';
try{$st=$pdo->prepare('SELECT COALESCE(NULLIF(fantasy_name,\'\'),name) FROM companies WHERE id=? LIMIT 1');$st->execute([$cid]);$companyName=trim((string)($st->fetchColumn()?:''));$st->closeCursor();}catch(Throwable $e){}
if($companyName==='')$companyName='Nexa Próspero';

function crm_tramitar_first_name(string $name): string {
  $parts=preg_split('/\s+/u',trim($name),-1,PREG_SPLIT_NO_EMPTY)?:[];
  return (string)($parts[0]??$name);
}
function crm_tramitar_model_by_id(array $models,int $id): ?array {
  foreach($models as $m)if((int)($m['id']??0)===$id)return $m;
  return null;
}

if(isset($_GET['message_sent']))$ok='Mensagem enviada e registrada na tramitação.';
if(isset($_GET['proposal_sent']))$ok='Proposta #'.crm_proposal_number((int)$_GET['proposal_sent']).' enviada por WhatsApp e registrada no histórico.';
if(isset($_GET['proposal_send_error']))$err='Não foi possível enviar a proposta por WhatsApp. Confira o telefone do cliente e a conexão do módulo WhatsApp. Consulte o histórico para detalhes.';
if(isset($_GET['message_error']))$err=trim((string)($_GET['message_error']??''))?:'A tramitação foi salva, mas não foi possível enviar a mensagem.';

if($_SERVER['REQUEST_METHOD']==='POST'){
  try{
    crm_csrf_check((string)($_POST['csrf']??''));
    $action=(string)($_POST['action']??'save_pending');
    if(!in_array($action,['save_pending','finish','send_only'],true))throw new RuntimeException('Ação inválida.');

    $movement=trim((string)($_POST['movement_text']??''));
    $pendingReason=trim((string)($_POST['pending_reason']??''));
    $nextDate=trim((string)($_POST['next_contact_date']??''));
    $responsible=max(0,(int)($_POST['responsible_user_id']??0));
    $temperature=crm_temperature_clamp($_POST['lead_temperature']??$temperature);
    $messageModelId=max(0,(int)($_POST['message_model_id']??0));
    $messageTo=trim((string)($_POST['message_to']??$messageTo));
    $messageText=trim((string)($_POST['message_text']??''));
    $sendAlong=isset($_POST['send_whatsapp'])||$action==='send_only';

    if($action!=='send_only'&&$movement==='')throw new RuntimeException('Informe a tramitação.');
    if($action==='send_only'&&$movement==='')$movement='Mensagem enviada ao lead.';
    if($action==='save_pending'&&$pendingReason==='')throw new RuntimeException('Informe o motivo da pendência.');
    if($nextDate!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$nextDate))throw new RuntimeException('Data do próximo contato inválida.');
    $st=$pdo->prepare('SELECT id,name FROM users WHERE '.(function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)').' AND id=? AND is_active=1 LIMIT 1');
    $st->execute(array_merge((function_exists('access_company_users_params') ? access_company_users_params($pdo, $cid) : [$cid]),[$responsible]));$urow=$st->fetch(PDO::FETCH_ASSOC);$st->closeCursor();
    if(!$urow)throw new RuntimeException('Responsável inválido.');

    $selectedModel=crm_tramitar_model_by_id($crmModels,$messageModelId);
    if($sendAlong){
      if($messageTo==='')throw new RuntimeException('Informe o WhatsApp/telefone do lead para enviar a mensagem.');
      if($messageText===''){
        if(!$selectedModel)throw new RuntimeException('Escolha um modelo de mensagem ou escreva a mensagem antes de enviar.');
        $messageText=whatsapp_render_template((string)$selectedModel['message'],[
          'empresa'=>$companyName,
          'lead'=>(string)($row['customer_name']??''),
          'nome'=>(string)($row['customer_name']??''),
          'primeiro_nome'=>crm_tramitar_first_name((string)($row['customer_name']??'')),
          'telefone'=>$messageTo,
          'email'=>(string)($row['email']??''),
          'temperatura'=>crm_temperature_label($temperature),
          'responsavel'=>(string)($urow['name']??''),
          'proximo_contato'=>$nextDate!==''?crm_date($nextDate):'',
          'tramitacao'=>$movement,
        ]);
      }
      if($messageText==='')throw new RuntimeException('A mensagem ficou vazia após processar o modelo.');
    }

    $state=$action==='finish'?'CONCLUIDO':($action==='send_only'?(string)$row['state']:'PENDENTE');
    $pdo->beginTransaction();
    if($action==='send_only'){
      $pdo->prepare('UPDATE crm_attendances SET lead_temperature=?,responsible_user_id=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$temperature,$responsible,$cid,$id]);
    }else{
      $pdo->prepare('UPDATE crm_attendances SET lead_temperature=?,state=?,pending_reason=?,next_contact_date=?,responsible_user_id=?,closed_by=?,closed_at=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$temperature,$state,$state==='PENDENTE'?$pendingReason:null,$nextDate?:null,$responsible,$state==='CONCLUIDO'?$uid:null,$state==='CONCLUIDO'?date('Y-m-d H:i:s'):null,$cid,$id]);
    }
    $messageStatus=$sendAlong?'PENDING':null;
    $stMove=$pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,message_channel,message_model_id,message_to,message_text,message_status,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,NOW())');
    $stMove->execute([$cid,$id,$movement,$temperature,$sendAlong?'WHATSAPP':null,$sendAlong?($messageModelId?:null):null,$sendAlong?$messageTo:null,$sendAlong?$messageText:null,$messageStatus,$state,$state==='PENDENTE'?$pendingReason:null,$nextDate?:null,$responsible,$uid]);
    $moveId=(int)$pdo->lastInsertId();
    // Anexos privados vinculados à tramitação, com MIME e limite de tamanho.
    if(isset($_FILES['crm_attachments'])) crm_commercial_save_uploads($pdo,$cid,$id,$moveId,$uid,$_FILES['crm_attachments']);
    $pdo->commit();

    if($sendAlong){
      $sent=false;$sendError='';
      try{
        $sent=whatsapp_send_message($pdo,$cid,$messageTo,$messageText,'crm_lead_message',null);
        if(!$sent)$sendError='Serviço WhatsApp indisponível, desconectado ou envio recusado.';
      }catch(Throwable $e){$sendError=$e->getMessage();}
      $pdo->prepare('UPDATE crm_attendance_moves SET message_status=?,message_error=?,message_sent_at=? WHERE company_id=? AND id=?')
        ->execute([$sent?'SENT':'ERROR',$sent?null:mb_substr($sendError,0,500,'UTF-8'),$sent?date('Y-m-d H:i:s'):null,$cid,$moveId]);
      if(!$sent){
        $url='crm_tramitar.php?id='.$id.$originQuery.'&message_error='.rawurlencode('A tramitação foi salva, mas a mensagem não foi enviada: '.$sendError);
        header('Location: '.$url);exit;
      }
      if($action==='send_only'){
        header('Location: crm_tramitar.php?id='.$id.$originQuery.'&message_sent=1');exit;
      }
    }

    if($fromMobile){$sep=str_contains($returnTo,'?')?'&':'?';header('Location: '.$returnTo.$sep.'saved=1');}
    else{header('Location: crm_atendimentos.php');}
    exit;
  }catch(Throwable $e){
    if($pdo->inTransaction())$pdo->rollBack();
    $err=$e->getMessage();
  }
}

$renderVars=[
  'empresa'=>$companyName,
  'lead'=>(string)($row['customer_name']??''),
  'nome'=>(string)($row['customer_name']??''),
  'primeiro_nome'=>crm_tramitar_first_name((string)($row['customer_name']??'')),
  'telefone'=>$messageTo,
  'email'=>(string)($row['email']??''),
  'temperatura'=>crm_temperature_label($temperature),
  'responsavel'=>'',
  'proximo_contato'=>$nextDate!==''?crm_date($nextDate):'',
  'tramitacao'=>$movement,
];
$attached=[];$proposals=[];
try {
  $attached=crm_commercial_attachments($pdo,$cid,$id);
  $stmtProposal=$pdo->prepare('SELECT p.*,COALESCE(SUM(i.quantity*i.unit_price),0) AS total_once,COALESCE(SUM(i.quantity*i.monthly_unit_price),0) AS total_month FROM crm_proposals p LEFT JOIN crm_proposal_items i ON i.company_id=p.company_id AND i.proposal_id=p.id WHERE p.company_id=? AND p.attendance_id=? GROUP BY p.id ORDER BY p.id DESC');
  $stmtProposal->execute([$cid,$id]);$proposals=$stmtProposal->fetchAll(PDO::FETCH_ASSOC)?:[];
}catch(Throwable $e){ if($err==='')$err='A atualização do CRM ainda precisa ser aplicada em Configurações → Atualizador do Banco.'; }
$templatesForJs=[];
foreach($crmModels as $m)$templatesForJs[(int)$m['id']]=['title'=>(string)$m['title'],'message'=>(string)$m['message']];

crm_page_start('CRM - Tramitar Atendimento #'.$id,$fromMobile?'crm_atendimento_mobile':'crm_atendimentos');
?>
<div class="crm-head"><a class="crm-btn" href="<?=crm_e($returnTo)?>">← Voltar</a><h1>Tramitar atendimento #<?=$id?></h1><div class="crm-spacer"></div><a class="crm-btn" href="crm_atendimento.php?id=<?=$id?>&view=1<?=$originQuery?>">Visualizar atendimento</a></div>
<?php if($ok!==''):?><div class="crm-alert ok"><?=crm_e($ok)?></div><?php endif;?>
<?php if($err!==''):?><div class="crm-alert error"><?=crm_e($err)?></div><?php endif;?>
<div class="crm-card"><div class="crm-card-head">Resumo <div class="crm-spacer"></div><?=crm_temperature_badge($temperature,true)?></div><div class="crm-card-body"><strong><?=crm_e(trim((string)$row['customer_name'])?:'Sem nome informado')?></strong><div class="crm-muted"><?=crm_e($row['request_text'])?></div></div></div>
<form method="post" enctype="multipart/form-data" id="crmTramitarForm"><input type="hidden" name="csrf" value="<?=crm_e(crm_csrf_token())?>"><input type="hidden" name="id" value="<?=$id?>"><?php if($fromMobile):?><input type="hidden" name="from" value="mobile"><input type="hidden" name="return_to" value="<?=crm_e($returnTo)?>"><?php endif;?>
<div class="crm-card"><div class="crm-card-head">Nova tramitação</div><div class="crm-card-body"><div class="crm-form-grid">
<div class="crm-field crm-span4"><label>Tramitação *</label><textarea id="crmMovementText" name="movement_text" placeholder="Descreva o contato, retorno, proposta, encaminhamento ou próxima ação."><?=crm_e($movement)?></textarea></div>
<div class="crm-field crm-span2"><label>Temperatura do Lead</label><?php crm_temperature_control('lead_temperature',$temperature,'crmLeadTemperatureMove'); ?></div>
<div class="crm-field"><label>Data do próximo contato</label><input type="date" name="next_contact_date" id="crmNextDate" value="<?=crm_e($nextDate)?>"></div>
<div class="crm-field"><label>Responsável</label><select name="responsible_user_id" id="crmResponsible"><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=$responsible===(int)$u['id']?'selected':''?>><?=crm_e($u['name'])?></option><?php endforeach;?></select></div>
<div class="crm-field crm-span4"><label>Motivo da pendência <span class="crm-muted">(obrigatório ao Salvar pendente)</span></label><input name="pending_reason" value="<?=crm_e($pendingReason)?>" autocomplete="off"></div>
</div></div></div>
<div class="crm-card"><div class="crm-card-head">📎 Anexos desta tramitação</div><div class="crm-card-body"><div class="crm-field"><label>Arquivos e propostas recebidas (até 8 arquivos, 12 MB cada)</label><input type="file" name="crm_attachments[]" multiple accept=".pdf,.png,.jpg,.jpeg,.webp,.docx,.xlsx" style="padding:10px;border:1px dashed #9cb8d2;border-radius:10px;width:100%;box-sizing:border-box"><small class="crm-muted">PDF, imagens, Word (DOCX) e Excel (XLSX). O acesso aos anexos exige login e permissão de CRM.</small></div></div></div>
<div class="crm-card"><div class="crm-card-head">📑 Propostas comerciais <div class="crm-spacer"></div><a class="crm-btn sm primary" href="crm_proposta.php?atendimento=<?=$id?>">＋ Criar proposta</a></div><div class="crm-card-body">
<?php if(!$proposals):?><p class="crm-muted">Nenhuma proposta criada neste atendimento.</p><?php endif;?>
<?php foreach($proposals as $p):?><div class="crm-history-item" style="margin-bottom:8px"><div class="crm-actions"><strong>Proposta #<?=crm_proposal_number((int)$p['id'])?> — <?=crm_e($p['title'])?></strong><div class="crm-spacer"></div><a class="crm-btn sm" href="crm_proposta.php?atendimento=<?=$id?>&id=<?=(int)$p['id']?>">Editar</a><button type="submit" class="crm-btn sm success" form="crmProposalWa-<?=(int)$p['id']?>" onclick="return confirm('Enviar o link da proposta pelo WhatsApp conectado? Se necessário, o link público será habilitado para o cliente.')">💬 Enviar WhatsApp</button><a class="crm-btn sm primary" target="_blank" rel="noopener" href="crm_proposta_imprimir.php?atendimento=<?=$id?>&id=<?=(int)$p['id']?>">Imprimir / PDF</a></div><div class="crm-muted">Valor inicial: <?=crm_commercial_money($p['total_once'])?> · Valor mensal: <?=crm_commercial_money($p['total_month'])?> · <?=crm_e(!empty($p['share_enabled'])?'Compartilhamento ativo':'Compartilhamento desabilitado')?></div></div><?php endforeach;?>
</div></div>
<div class="crm-card"><div class="crm-card-head">📂 Arquivos já anexados</div><div class="crm-card-body">
<?php if(!$attached):?><div class="crm-muted">Ainda não há arquivos anexados.</div><?php endif;?>
<?php foreach($attached as $file):?><div class="crm-history-item" style="margin-bottom:7px"><a href="crm_anexo.php?id=<?=(int)$file['id']?>" style="font-weight:800">📎 <?=crm_e($file['original_name'])?></a><div class="crm-muted"><?=crm_e(crm_datetime($file['created_at']))?> · <?=crm_e($file['user_name']?:'Usuário')?> · <?=number_format((int)$file['size_bytes']/1024,1,',','.')?> KB</div></div><?php endforeach;?>
</div></div>

<div class="crm-card"><div class="crm-card-head"><span>Mensagem para o Lead</span><div class="crm-spacer"></div><a class="crm-btn sm" href="whatsapp_modelos.php?new=1&channel=whatsapp&context=crm" target="_blank" rel="noopener">＋ Novo modelo</a></div><div class="crm-card-body"><div class="crm-message-panel">
<div class="crm-message-grid"><div class="crm-field"><label>Modelo de mensagem</label><select name="message_model_id" id="crmMessageModel"><option value="0">Mensagem livre</option><?php foreach($crmModels as $m):?><option value="<?=(int)$m['id']?>" <?=$messageModelId===(int)$m['id']?'selected':''?>><?=crm_e($m['title'])?></option><?php endforeach;?></select></div><div class="crm-field"><label>WhatsApp / Telefone</label><input name="message_to" id="crmMessageTo" value="<?=crm_e($messageTo)?>" placeholder="Ex.: 31999999999"></div></div>
<div class="crm-field" style="margin-top:10px"><label>Mensagem</label><textarea name="message_text" id="crmMessageText" placeholder="Escolha um modelo ou escreva a mensagem."><?=crm_e($messageText)?></textarea></div>
<label class="crm-message-send-check"><input type="checkbox" name="send_whatsapp" value="1" <?=$sendAlong?'checked':''?>> Enviar esta mensagem pelo WhatsApp ao salvar a tramitação</label>
<div class="crm-muted" style="margin-top:7px">O envio fica registrado no histórico da tramitação, inclusive em caso de falha. Modelos do CRM podem usar: {{nome}}, {{primeiro_nome}}, {{empresa}}, {{temperatura}}, {{responsavel}}, {{proximo_contato}} e {{tramitacao}}.</div>
</div></div></div>

<div class="crm-card"><div class="crm-card-body"><div class="crm-actions"><button class="crm-btn primary" name="action" value="save_pending">Salvar pendente</button><button class="crm-btn success" name="action" value="finish">Salvar e finalizar</button><button class="crm-btn" name="action" value="send_only">Enviar mensagem agora</button><a class="crm-btn" href="<?=crm_e($returnTo)?>">Cancelar</a></div></div></div>
</form>
<?php foreach($proposals as $p):?>
<form method="post" action="crm_proposta_whatsapp.php" id="crmProposalWa-<?=(int)$p['id']?>" style="display:none">
  <input type="hidden" name="csrf" value="<?=crm_e(crm_csrf_token())?>">
  <input type="hidden" name="atendimento" value="<?=$id?>">
  <input type="hidden" name="id" value="<?=(int)$p['id']?>">
  <input type="hidden" name="return_to" value="tramitar">
</form>
<?php endforeach;?>
<script>
(()=>{
 const models=<?=json_encode($templatesForJs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const vars=<?=json_encode($renderVars,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;
 const model=document.getElementById('crmMessageModel'), text=document.getElementById('crmMessageText'), movement=document.getElementById('crmMovementText'), next=document.getElementById('crmNextDate'), resp=document.getElementById('crmResponsible');
 const render=(tpl)=>{let out=String(tpl||'');const dynamic=Object.assign({},vars,{tramitacao:movement?.value||'',proximo_contato:next?.value||'',responsavel:resp?.selectedOptions?.[0]?.textContent||'',temperatura:document.querySelector('[data-crm-temp-label]')?.textContent||vars.temperatura});Object.entries(dynamic).forEach(([k,v])=>{out=out.split('{{'+k+'}}').join(String(v??''));});return out.replace(/^\s*\{\{[a-zA-Z0-9_]+\}\}\s*$/gm,'').replace(/\n{3,}/g,'\n\n').trim();};
 model?.addEventListener('change',()=>{const m=models[model.value];if(m)text.value=render(m.message);});
 document.querySelectorAll('[data-crm-temp-range],[data-crm-temp-set]').forEach(el=>el.addEventListener('input',()=>{const m=models[model?.value];if(m&&text?.dataset.auto==='1')text.value=render(m.message);}));
 if(model&&Number(model.value)>0&&text&&text.value.trim()===''){const m=models[model.value];if(m){text.value=render(m.message);text.dataset.auto='1';}}
 text?.addEventListener('input',()=>{text.dataset.auto='0';});
})();
</script>
<?php crm_page_end();
