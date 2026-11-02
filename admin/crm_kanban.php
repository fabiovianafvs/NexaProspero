<?php
declare(strict_types=1);
require_once __DIR__.'/_bootstrap.php';
require_once __DIR__.'/_crm.php';
$cid=crm_company_id();$uid=crm_user_id();
if($_SERVER['REQUEST_METHOD']==='POST'){
  header('Content-Type: application/json; charset=utf-8');header('Cache-Control: no-store');
  try{
    crm_csrf_check((string)($_POST['csrf']??''));
    $id=(int)($_POST['id']??0);$status=(int)($_POST['status_id']??0);
    if($id<1||$status<1)throw new RuntimeException('Seleção inválida.');
    $pdo->beginTransaction();
    $st=$pdo->prepare('SELECT a.status_id,a.state,a.lead_temperature,a.next_contact_date,a.responsible_user_id,s.name old_name FROM crm_attendances a LEFT JOIN crm_statuses s ON s.company_id=a.company_id AND s.id=a.status_id WHERE a.company_id=? AND a.id=? FOR UPDATE');
    $st->execute([$cid,$id]);$old=$st->fetch(PDO::FETCH_ASSOC);
    if(!$old)throw new RuntimeException('Atendimento não localizado.');
    $st=$pdo->prepare('SELECT name FROM crm_statuses WHERE company_id=? AND id=? AND active=1');$st->execute([$cid,$status]);$name=$st->fetchColumn();
    if(!$name)throw new RuntimeException('Status inexistente ou inativo.');
    $changed=(int)($old['status_id']??0)!==$status;
    if($changed){
      $pdo->prepare('UPDATE crm_attendances SET status_id=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$status,$cid,$id]);
      $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,NOW())')
        ->execute([$cid,$id,'Kanban: mudança de status de '.((string)($old['old_name']??'Sem status')).' para '.$name.'.',(int)($old['lead_temperature']??20),(string)$old['state'],$old['next_contact_date'],$old['responsible_user_id'],$uid]);
    }
    $pdo->commit();echo json_encode(['ok'=>true,'name'=>$name,'changed'=>$changed]);
  }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()]);}
  exit;
}
$statuses=crm_statuses($pdo,$cid,true);
$q=trim((string)($_GET['q']??''));$responsible=(int)($_GET['responsible']??0);$users=crm_users($pdo,$cid,true);
$where=['a.company_id=?'];$params=[$cid];
if($q!==''){$where[]='(a.customer_name LIKE ? OR a.request_text LIKE ? OR a.phone LIKE ?)';$v='%'.str_replace(['%','_'],['\\%','\\_'],$q).'%';array_push($params,$v,$v,$v);}
if($responsible>0){$where[]='a.responsible_user_id=?';$params[]=$responsible;}
$stmt=$pdo->prepare('SELECT a.id,a.customer_name,a.phone,a.request_text,a.status_id,a.lead_temperature,a.state,a.next_contact_date,a.created_at,u.name responsible_name FROM crm_attendances a LEFT JOIN users u ON u.company_id=a.company_id AND u.id=a.responsible_user_id WHERE '.implode(' AND ',$where).' ORDER BY CASE WHEN a.state="PENDENTE" THEN 0 ELSE 1 END,a.next_contact_date ASC,a.id DESC LIMIT 1000');
$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC)?:[];
$groups=[];foreach($statuses as $s)$groups[(int)$s['id']]=[];$groups[0]=[];
foreach($rows as $r){$statusId=(int)($r['status_id']??0);$groups[isset($groups[$statusId])?$statusId:0][]=$r;}
crm_page_start('CRM - Kanban','crm_atendimentos');
?>
<style>
.crm-kanban-toolbar{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.crm-kanban-toolbar .crm-field{min-width:185px;flex:1}.crm-kanban-board{display:flex;gap:15px;align-items:flex-start;overflow-x:auto;padding:8px 1px 22px;scroll-snap-type:x proximity}.crm-k-column{--line:#2563eb;flex:0 0 298px;max-width:330px;min-height:180px;border:1px solid #dbe5f0;border-top:4px solid var(--line);border-radius:15px;background:#f5f8fd;scroll-snap-align:start}.crm-k-header{display:flex;align-items:center;gap:7px;padding:13px;font-weight:900;color:#152c49}.crm-k-header .count{margin-left:auto;padding:3px 9px;background:#e2eaf5;border-radius:999px;font-size:12px}.crm-k-list{display:grid;gap:10px;padding:10px;min-height:95px}.crm-k-list.dragover{background:#dbeafe;outline:2px dashed #2563eb;outline-offset:-5px;border-radius:8px}.crm-k-card{background:white;border:1px solid #dce5f1;border-radius:12px;padding:13px;box-shadow:0 4px 11px #16335f0b;cursor:grab;transition:box-shadow .12s,transform .12s}.crm-k-card:hover{box-shadow:0 9px 18px #173b6b20;transform:translateY(-1px)}.crm-k-card.dragging{opacity:.38}.crm-k-card a{font-weight:900;color:#164d88;text-decoration:none}.crm-k-card p{font-size:12px;color:#53657e;line-height:1.4;margin:8px 0}.crm-k-meta{display:flex;justify-content:space-between;gap:8px;flex-wrap:wrap;font-size:11px;color:#64748b}.crm-k-card select{font-size:12px;border-radius:7px;border:1px solid #cfdaea;max-width:100%;margin-top:9px;width:100%;padding:6px;background:white}.crm-k-note{color:#64748b;font-size:12px}.crm-k-drop-empty{text-align:center;color:#94a3b8;padding:14px;font-size:12px}
@media(max-width:650px){.crm-k-column{flex-basis:85vw}.crm-kanban-toolbar .crm-field{min-width:100%}}
</style>
<div class="crm-head"><h1>Kanban de oportunidades</h1><div class="crm-spacer"></div><a class="crm-btn" href="crm_atendimentos.php">☷ Lista</a><a class="crm-btn primary" href="crm_atendimento.php">＋ Novo atendimento</a></div>
<div class="crm-card"><div class="crm-card-body"><form class="crm-kanban-toolbar" method="get"><div class="crm-field"><label>Pesquisar por lead, telefone ou solicitação</label><input name="q" value="<?=crm_e($q)?>" placeholder="Digite para localizar"></div><div class="crm-field"><label>Responsável</label><select name="responsible"><option value="0">Todos</option><?php foreach($users as $u):?><option value="<?=(int)$u['id']?>" <?=$responsible===(int)$u['id']?'selected':''?>><?=crm_e($u['name'])?></option><?php endforeach;?></select></div><button class="crm-btn primary">Filtrar</button><a class="crm-btn" href="crm_kanban.php">Limpar</a></form><p class="crm-k-note">Arraste para mudar o status; no celular, use o seletor em cada cartão. Clique no nome para abrir. Situação pendente/concluído continua sendo controlada no atendimento.</p></div></div>
<div id="kanbanMessage" role="status" aria-live="polite"></div>
<div class="crm-kanban-board">
<?php $boardStatuses=array_merge($statuses,[['id'=>0,'name'=>'Sem status / inativo','color'=>'#64748b']]);foreach($boardStatuses as $s):$sid=(int)$s['id'];if($sid===0&&!count($groups[0]))continue;$cards=$groups[$sid]??[]; ?>
<section class="crm-k-column" style="--line:<?=crm_e(crm_clean_color((string)$s['color']))?>"><div class="crm-k-header"><span class="crm-status-dot" style="background:var(--line)"></span><?=crm_e($s['name'])?><span class="count" data-count><?=count($cards)?></span></div><div class="crm-k-list" data-status="<?=$sid?>">
<?php foreach($cards as $r):?><article class="crm-k-card" draggable="true" data-id="<?=(int)$r['id']?>"><div class="crm-k-meta"><span>#<?=(int)$r['id']?></span><?=crm_temperature_badge($r['lead_temperature'])?></div><a href="crm_atendimento.php?id=<?=(int)$r['id']?>&view=1"><?=crm_e($r['customer_name']?:'Sem nome')?></a><p><?=crm_e(mb_strimwidth((string)$r['request_text'],0,125,'…','UTF-8'))?></p><div class="crm-k-meta"><span>📅 <?=crm_e(crm_date($r['next_contact_date']))?></span><span><?=crm_e($r['responsible_name']?:'—')?></span></div><select class="k-change" aria-label="Mudar status do atendimento <?=(int)$r['id']?>"><option value="0">Mover para…</option><?php foreach($statuses as $opt):?><option value="<?=(int)$opt['id']?>" <?=$sid===(int)$opt['id']?'selected':''?>><?=crm_e($opt['name'])?></option><?php endforeach;?></select></article><?php endforeach;?>
</div></section><?php endforeach;?></div>
<script>
(()=>{const csrf=<?=json_encode(crm_csrf_token())?>;let dragged=null;const msg=document.getElementById('kanbanMessage');
function updateCount(){document.querySelectorAll('.crm-k-column').forEach(c=>c.querySelector('[data-count]').textContent=c.querySelectorAll('.crm-k-card').length)}
async function move(card,dest){if(!card||!dest||Number(dest.dataset.status)<1)return;const oldList=card.closest('.crm-k-list');if(oldList===dest)return;const oldStatus=oldList?.dataset.status||'0';card.style.opacity='.6';try{const body=new URLSearchParams({csrf,id:card.dataset.id,status_id:dest.dataset.status});const response=await fetch('crm_kanban.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body,credentials:'same-origin'});const result=await response.json();if(!response.ok||!result.ok)throw Error(result.message||'Falha ao atualizar.');dest.appendChild(card);card.querySelector('select').value=dest.dataset.status;updateCount();msg.className='crm-alert ok';msg.textContent='Status atualizado para '+result.name+'.';}catch(e){msg.className='crm-alert error';msg.textContent=e.message||'Falha ao mover o cartão.';card.querySelector('select').value=oldStatus;}finally{card.style.opacity='';}}
document.querySelectorAll('.crm-k-card').forEach(card=>{card.addEventListener('dragstart',e=>{if(e.target.closest('select'))return;dragged=card;card.classList.add('dragging');e.dataTransfer.effectAllowed='move';e.dataTransfer.setData('text/plain',card.dataset.id)});card.addEventListener('dragend',()=>{card.classList.remove('dragging');dragged=null;document.querySelectorAll('.crm-k-list').forEach(x=>x.classList.remove('dragover'))});card.querySelector('select').addEventListener('change',e=>{const list=document.querySelector('.crm-k-list[data-status="'+e.target.value+'"]');if(list)move(card,list)})});
document.querySelectorAll('.crm-k-list').forEach(list=>{list.addEventListener('dragover',e=>{if(!dragged||Number(list.dataset.status)<1)return;e.preventDefault();e.dataTransfer.dropEffect='move';list.classList.add('dragover')});list.addEventListener('dragleave',()=>list.classList.remove('dragover'));list.addEventListener('drop',e=>{e.preventDefault();list.classList.remove('dragover');if(dragged)move(dragged,list)})});
})();
</script>
<?php crm_page_end();
