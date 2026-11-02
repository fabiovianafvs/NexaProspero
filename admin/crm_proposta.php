<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_crm.php';
require_once __DIR__.'/../config/crm_commercial.php';
require_once __DIR__.'/../config/crm_proposal_number.php';
$cid=crm_company_id();$uid=crm_user_id();$attendanceId=(int)($_GET['atendimento']??$_POST['atendimento']??0);$proposalId=(int)($_GET['id']??$_POST['id']??0);
$att=crm_attendance($pdo,$cid,$attendanceId);if(!$att){http_response_code(404);exit('Atendimento não encontrado.');}
$proposal=$proposalId?crm_commercial_proposal($pdo,$cid,$attendanceId,$proposalId):[];
if($proposalId&&!$proposal){http_response_code(404);exit('Proposta não encontrada para esta empresa.');}
$items=$proposalId?crm_commercial_items($pdo,$cid,$proposalId):[];
$companyName='Nexa Próspero';try{$q=$pdo->prepare('SELECT COALESCE(NULLIF(fantasy_name,\'\'),name) FROM companies WHERE id=?');$q->execute([$cid]);$companyName=(string)($q->fetchColumn()?:$companyName);}catch(Throwable $e){}
$err='';$ok=isset($_GET['saved'])?'Proposta salva. Já é possível imprimir ou compartilhar.':'';
if(isset($_GET['proposal_sent']))$ok='Proposta enviada pelo WhatsApp e registrada no histórico do CRM.';
if(isset($_GET['proposal_send_error']))$err='Não foi possível enviar a proposta. Verifique o telefone do cliente e o serviço WhatsApp conectado.';
$defaults=['title'=>'Solução de gestão para '.trim((string)$att['customer_name']),'intro'=>'Apresentamos uma solução pensada para tornar a operação mais ágil, segura e organizada, com informações confiáveis para a tomada de decisões.','benefits'=>"• Mais controle e visibilidade da operação\n• Redução de retrabalho e erros\n• Apoio ao crescimento com tecnologia e atendimento especializado",'scope_text'=>"Implantação e configuração da solução conforme os itens contratados.\nTreinamento e orientação inicial da equipe.",'terms_text'=>'Valores apresentados em reais. Condições de pagamento, implantação e recorrência conforme contratação.','notes'=>'Estamos à disposição para esclarecer dúvidas e personalizar esta proposta.','valid_until'=>date('Y-m-d',strtotime('+15 days')),'share_enabled'=>0];
$form=array_merge($defaults,$proposal);
if(!$items)$items=[['description'=>'','quantity'=>'1','unit_price'=>'0.00','monthly_unit_price'=>'0.00']];
if($_SERVER['REQUEST_METHOD']==='POST'){
  $form=array_merge($form,$_POST);$items=is_array($_POST['items']??null)?$_POST['items']:[];
  try{
    crm_csrf_check((string)($_POST['csrf']??''));
    $title=trim((string)($_POST['title']??''));if($title===''||mb_strlen($title)>200)throw new RuntimeException('Informe um título de até 200 caracteres.');
    $date=trim((string)($_POST['valid_until']??''));if($date!=='' && (!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date)||date('Y-m-d',strtotime($date))!==$date))throw new RuntimeException('Data de validade inválida.');
    $fields=[];foreach(['intro','benefits','scope_text','terms_text','notes'] as $f){$fields[$f]=trim((string)($_POST[$f]??''));if(mb_strlen($fields[$f])>8000)throw new RuntimeException('Texto extenso demais em '.$f.'.');}
    if(count($items)<1 || count($items)>60)throw new RuntimeException('Informe de 1 a 60 itens.');
    $validItems=[];
    foreach($items as $i=>$it){$desc=trim((string)($it['description']??''));if($desc==='')continue;if(mb_strlen($desc)>500)throw new RuntimeException('Descrição de item limitada a 500 caracteres.');
      $qty=crm_commercial_decimal($it['quantity']??'1');$unit=crm_commercial_decimal($it['unit_price']??0);$monthly=crm_commercial_decimal($it['monthly_unit_price']??0);
      if($qty<=0||$qty>100000)throw new RuntimeException('Quantidade deve ser positiva e não exceder 100.000.');
      if($qty*$unit>100000000||$qty*$monthly>100000000)throw new RuntimeException('Total do item excede o limite.');
      $validItems[]=['description'=>$desc,'quantity'=>$qty,'unit_price'=>$unit,'monthly_unit_price'=>$monthly];
    }
    if(!$validItems)throw new RuntimeException('Informe pelo menos um item com descrição.');
    $share=isset($_POST['share_enabled'])?1:0;
    $pdo->beginTransaction();
    if($proposalId){
      $pdo->prepare('UPDATE crm_proposals SET title=?,intro=?,benefits=?,scope_text=?,terms_text=?,notes=?,valid_until=?,share_enabled=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND attendance_id=? AND id=?')
        ->execute([$title,$fields['intro'],$fields['benefits'],$fields['scope_text'],$fields['terms_text'],$fields['notes'],$date?:null,$share,$uid,$cid,$attendanceId,$proposalId]);
      $pdo->prepare('DELETE FROM crm_proposal_items WHERE company_id=? AND proposal_id=?')->execute([$cid,$proposalId]);
    }else{
      $token=bin2hex(random_bytes(32));
      $pdo->prepare('INSERT INTO crm_proposals(company_id,attendance_id,title,intro,benefits,scope_text,terms_text,notes,valid_until,status,share_enabled,share_token,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,\'RASCUNHO\',?,?,?,NOW())')
        ->execute([$cid,$attendanceId,$title,$fields['intro'],$fields['benefits'],$fields['scope_text'],$fields['terms_text'],$fields['notes'],$date?:null,$share,$token,$uid]);
      $proposalId=(int)$pdo->lastInsertId();
    }
    $stmt=$pdo->prepare('INSERT INTO crm_proposal_items(company_id,proposal_id,sort_order,description,quantity,unit_price,monthly_unit_price) VALUES(?,?,?,?,?,?,?)');
    foreach($validItems as $i=>$it)$stmt->execute([$cid,$proposalId,($i+1)*10,$it['description'],number_format($it['quantity'],3,'.',''),number_format($it['unit_price'],2,'.',''),number_format($it['monthly_unit_price'],2,'.','')]);
    $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
      ->execute([$cid,$attendanceId,($proposal?'Proposta atualizada: ':'Proposta criada: ').'#'.crm_proposal_number($proposalId).' — '.$title,(int)($att['lead_temperature']??20),(string)$att['state'],$att['next_contact_date'],$att['responsible_user_id'],$uid]);
    $pdo->commit();header('Location: crm_proposta.php?atendimento='.$attendanceId.'&id='.$proposalId.'&saved=1');exit;
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$err=$e->getMessage();}
}
$publicUrl='';if(!empty($proposal['share_enabled'])){
  $https=(!empty($_SERVER['HTTPS'])&&$_SERVER['HTTPS']!=='off') || (($_SERVER['HTTP_X_FORWARDED_PROTO']??'')==='https');
  $host=(string)($_SERVER['HTTP_HOST']??'');
  if(preg_match('/^[a-zA-Z0-9.-]+(?::\d{2,5})?$/',$host))$publicUrl=($https?'https':'http').'://'.$host.'/crm_proposta_publica.php?token='.rawurlencode((string)$proposal['share_token']);
}
$phone=crm_commercial_phone((string)($att['phone']??''));
$waText="Olá, ".trim((string)($att['customer_name']??''))."! Preparamos sua proposta comercial: ".$publicUrl;
$waLink='https://wa.me/'.($phone?:'').'?text='.rawurlencode($waText);
crm_page_start('CRM - '.($proposalId > 0 ? 'Proposta Comercial #'.crm_proposal_number($proposalId) : 'Nova Proposta'),'crm_atendimentos');
?>
<style>
.crm-proposal-head{background:linear-gradient(115deg,#12477a,#227ebc);color:#fff;border-radius:16px;padding:22px 26px;margin-bottom:15px}.crm-proposal-head h1{margin:0;font-size:24px;color:white}.crm-proposal-head p{opacity:.9;margin:5px 0}.crm-proposal-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.crm-proposal-grid .crm-field.full{grid-column:1/-1}.crm-proposal-items{width:100%;border-collapse:collapse;min-width:680px}.crm-proposal-items th{text-align:left;font-size:12px;color:#355d83;background:#eff6fd}.crm-proposal-items td,.crm-proposal-items th{padding:8px;border-bottom:1px solid #e2e8f0}.crm-proposal-items input{width:100%;min-width:0;box-sizing:border-box;border:1px solid #c4d1df;padding:10px;border-radius:8px}.crm-proposal-items td:first-child{width:38%}.crm-proposal-items .remove{border:0;background:#fff0f0;color:#a52a2a;font-weight:bold;padding:9px;border-radius:7px;cursor:pointer}.crm-proposal-totals{display:flex;justify-content:end;gap:12px;flex-wrap:wrap;margin-top:13px}.crm-proposal-total{border:1px solid #bddaf0;background:#f1f7fd;border-radius:12px;padding:12px 18px;min-width:190px}.crm-proposal-total small{display:block;color:#4d6983;font-weight:700}.crm-proposal-total strong{font-size:22px;color:#115286}.crm-proposal-info{color:#5b7187;font-size:12px}.crm-proposal-actions{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.crm-share-box{border:1px solid #cce1ef;background:#f1f8ff;padding:12px;border-radius:10px;word-break:break-all}@media(max-width:720px){.crm-proposal-grid{grid-template-columns:1fr}.crm-proposal-head h1{font-size:20px}}
</style>
<div class="crm-proposal-head"><h1><?=$proposalId > 0 ? 'Proposta comercial #'.crm_proposal_number($proposalId) : 'Nova proposta'?></h1><p><?=crm_e($companyName)?> · <?=crm_e($att['customer_name']?:'Cliente não informado')?> · Atendimento #<?=$attendanceId?></p></div>
<div class="crm-head"><a class="crm-btn" href="crm_tramitar.php?id=<?=$attendanceId?>">← Voltar à tramitação</a><div class="crm-spacer"></div><?php if($proposalId):?><a class="crm-btn primary" href="crm_proposta_imprimir.php?atendimento=<?=$attendanceId?>&id=<?=$proposalId?>" target="_blank" rel="noopener">🖨 Visualizar / Imprimir / PDF</a><button type="submit" form="crmProposalWaSend" class="crm-btn success" onclick="return confirm('Enviar o link da proposta pelo WhatsApp conectado? Se necessário, o link público será habilitado para o cliente.')">💬 Enviar WhatsApp</button><?php endif;?></div>
<?php if($ok):?><div class="crm-alert ok"><?=crm_e($ok)?></div><?php endif;?><?php if($err):?><div class="crm-alert error"><?=crm_e($err)?></div><?php endif;?>
<form method="post" id="proposalForm"><input type="hidden" name="csrf" value="<?=crm_e(crm_csrf_token())?>"><input type="hidden" name="atendimento" value="<?=$attendanceId?>"><input type="hidden" name="id" value="<?=$proposalId?>">
<div class="crm-card"><div class="crm-card-head">Apresentação da solução</div><div class="crm-card-body"><div class="crm-proposal-grid"><div class="crm-field full"><label>Título *</label><input required maxlength="200" name="title" value="<?=crm_e($form['title'])?>" placeholder="Ex.: Proposta de implantação do ERP"></div>
<div class="crm-field"><label>Validade</label><input type="date" name="valid_until" value="<?=crm_e($form['valid_until']??'')?>"></div>
<div class="crm-field" style="align-self:end"><label style="display:flex;align-items:center;gap:9px"><input type="checkbox" name="share_enabled" value="1" <?=!empty($form['share_enabled'])?'checked':''?> style="width:auto"> Permitir consulta pelo link público da proposta</label></div>
<?php foreach(['intro'=>'Apresentação e objetivo','benefits'=>'Benefícios e diferenciais','scope_text'=>'Escopo / entregas','terms_text'=>'Condições comerciais','notes'=>'Informações complementares'] as $field=>$label):?><div class="crm-field full"><label><?=$label?></label><textarea name="<?=$field?>" rows="3" maxlength="8000"><?=crm_e($form[$field]??'')?></textarea></div><?php endforeach;?></div></div></div>
<div class="crm-card"><div class="crm-card-head">Itens da proposta <div class="crm-spacer"></div><button class="crm-btn sm primary" type="button" id="addItem">＋ Adicionar item</button></div><div class="crm-card-body"><div style="overflow-x:auto"><table class="crm-proposal-items"><thead><tr><th>Produto / serviço</th><th>Quantidade</th><th>Valor unitário</th><th>Mensalidade unitária</th><th></th></tr></thead><tbody id="proposalItems"><?php foreach($items as $idx=>$it):?><tr><td><input name="items[<?=$idx?>][description]" maxlength="500" placeholder="Descrição e valor entregue" value="<?=crm_e($it['description']??'')?>"></td><td><input class="valqty" type="number" min="0.001" step="0.001" name="items[<?=$idx?>][quantity]" value="<?=crm_e($it['quantity']??1)?>"></td><td><input class="valone" type="number" min="0" step="0.01" name="items[<?=$idx?>][unit_price]" value="<?=crm_e($it['unit_price']??0)?>"></td><td><input class="valmonth" type="number" min="0" step="0.01" name="items[<?=$idx?>][monthly_unit_price]" value="<?=crm_e($it['monthly_unit_price']??0)?>"></td><td><button type="button" class="remove" aria-label="Remover item">×</button></td></tr><?php endforeach;?></tbody></table></div>
<div class="crm-proposal-totals"><div class="crm-proposal-total"><small>Total único / inicial</small><strong id="totalOne">R$ 0,00</strong></div><div class="crm-proposal-total"><small>Total mensal</small><strong id="totalMonth">R$ 0,00</strong></div></div><p class="crm-proposal-info">O valor inicial e o mensal são independentes, ambos calculados multiplicando quantidade × valor unitário.</p></div></div>
<div class="crm-card"><div class="crm-card-body"><div class="crm-proposal-actions"><button class="crm-btn primary" type="submit">💾 Salvar proposta</button><a class="crm-btn" href="crm_tramitar.php?id=<?=$attendanceId?>">Cancelar</a></div></div></div>
</form>
<?php if($proposalId):?>
<form method="post" action="crm_proposta_whatsapp.php" id="crmProposalWaSend" style="display:none">
  <input type="hidden" name="csrf" value="<?=crm_e(crm_csrf_token())?>">
  <input type="hidden" name="atendimento" value="<?=$attendanceId?>">
  <input type="hidden" name="id" value="<?=$proposalId?>">
  <input type="hidden" name="return_to" value="proposta">
</form>
<?php endif;?>
<?php if($proposalId):?><div class="crm-card"><div class="crm-card-head">Enviar ao cliente</div><div class="crm-card-body">
<?php if($publicUrl!==''):?><div class="crm-share-box"><strong>Link de visualização:</strong> <a href="<?=crm_e($publicUrl)?>" target="_blank" rel="noopener noreferrer"><?=crm_e($publicUrl)?></a></div><div class="crm-proposal-actions" style="margin-top:12px"><a class="crm-btn success" target="_blank" rel="noopener noreferrer" href="<?=crm_e($waLink)?>">💬 Compartilhar pelo WhatsApp</a><button type="button" class="crm-btn" onclick="navigator.clipboard?.writeText(<?=crm_e(json_encode($publicUrl,JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE))?>)">Copiar link</button></div><p class="crm-proposal-info">O botão abre o WhatsApp com uma mensagem e o link; o envio só ocorre após sua confirmação no WhatsApp. O destinatário consegue visualizar a proposta sem entrar no Nexa enquanto o compartilhamento estiver habilitado.</p>
<?php else:?><p>Para enviar um link pelo WhatsApp, marque <strong>Permitir consulta pelo link público</strong> e salve. A impressão/PDF permanece disponível mesmo sem habilitar compartilhamento.</p><?php endif;?></div></div><?php endif;?>
<script>
(()=>{const list=document.getElementById('proposalItems'),add=document.getElementById('addItem');let index=list.querySelectorAll('tr').length;const money=n=>(Number(n)||0).toLocaleString('pt-BR',{style:'currency',currency:'BRL'});function calc(){let a=0,m=0;list.querySelectorAll('tr').forEach(r=>{const q=Number(r.querySelector('.valqty')?.value)||0;const one=Number(r.querySelector('.valone')?.value)||0;const month=Number(r.querySelector('.valmonth')?.value)||0;a+=q*one;m+=q*month});document.getElementById('totalOne').textContent=money(a);document.getElementById('totalMonth').textContent=money(m)}add.addEventListener('click',()=>{if(list.querySelectorAll('tr').length>=60)return;const i=index++;const tr=document.createElement('tr');tr.innerHTML=`<td><input name="items[${i}][description]" maxlength="500" placeholder="Produto ou serviço"></td><td><input class="valqty" type="number" name="items[${i}][quantity]" value="1" min="0.001" step="0.001"></td><td><input class="valone" type="number" name="items[${i}][unit_price]" min="0" step="0.01" value="0"></td><td><input class="valmonth" type="number" name="items[${i}][monthly_unit_price]" min="0" step="0.01" value="0"></td><td><button type="button" class="remove" aria-label="Remover item">×</button></td>`;list.appendChild(tr);tr.querySelector('input').focus();calc()});list.addEventListener('click',e=>{if(e.target.closest('.remove')){e.target.closest('tr').remove();calc()}});list.addEventListener('input',calc);calc()})();
</script>
<?php crm_page_end();
