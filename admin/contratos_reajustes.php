<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/contracts_module.php';
$pdo=contracts_db(); $cid=contracts_company_id(); $uid=contracts_user_id();
// Proteção direta: a página de reajustes exige o módulo Contratos contratado.
saas_require_company_module($pdo, $cid, 'contratos', 'Reajustes de Contratos');
contracts_require_schema($pdo);
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
if(empty($_SESSION['contract_adjust_csrf'])) $_SESSION['contract_adjust_csrf']=bin2hex(random_bytes(24));
$token=$_SESSION['contract_adjust_csrf'];
$from=(string)($_REQUEST['from']??date('Y-m-01'));$to=(string)($_REQUEST['to']??date('Y-m-t'));
function adj_valid(string $d):bool { $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d);return $dt!==false&&$dt->format('Y-m-d')===$d; }
function adj_next(string $d):string { $dt=new DateTimeImmutable($d);$year=(int)$dt->format('Y')+1;$month=(int)$dt->format('m');$day=min((int)$dt->format('d'),cal_days_in_month(CAL_GREGORIAN,$month,$year));return sprintf('%04d-%02d-%02d',$year,$month,$day); }
function adj_amount(array $r,float $pct):array {
 $factor=1+$pct/100;$unit=round((float)$r['unit_price']*$factor,4);$gross=(float)$r['quantity']*$unit;
 // Preserva percentuais de desconto/acréscimo e recalcula valores derivados proporcionalmente.
 $disc=round((float)$r['discount_amount']*$factor,2);$add=round((float)$r['addition_amount']*$factor,2);
 $total=max(0,round($gross-$disc+$add,2));return [$unit,$disc,$add,$total];
}
$error='';$success='';$preview=[];
$ready=false;
try{$chk=$pdo->query("SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='contract_item_adjustments' LIMIT 1");$ready=(bool)$chk->fetchColumn();}catch(Throwable $e){}
if(!adj_valid($from)||!adj_valid($to)||$from>$to){$error='Informe um intervalo de datas válido.';}
if($_SERVER['REQUEST_METHOD']==='POST'&&$error===''){
 try{
  if(!$ready)throw new RuntimeException('Histórico de reajustes não instalado. Aplique primeiro a migration SQL pela administração do banco.');
  if(!hash_equals($token,(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Atualize a página.');
  $selected=$_POST['selected']??[];$rates=$_POST['rate']??[];
  if(!is_array($selected)||!is_array($rates)||count($selected)>500)throw new RuntimeException('Seleção inválida.');
  $ids=array_values(array_unique(array_filter(array_map('intval',$selected),fn($v)=>$v>0)));if(!$ids)throw new RuntimeException('Selecione pelo menos um item.');
  if(count($ids)>500)throw new RuntimeException('Limite de 500 itens por lote.');
  $pdo->beginTransaction();$changes=[];$affected=[];
  $get=$pdo->prepare("SELECT i.*,c.contract_number,c.contracting_person_name,c.status AS contract_status FROM contract_items i INNER JOIN contracts c ON c.id=i.contract_id AND c.company_id=i.company_id WHERE i.company_id=? AND i.id=? FOR UPDATE");
  $update=$pdo->prepare("UPDATE contract_items SET unit_price=?,discount_amount=?,addition_amount=?,total=?,next_adjustment_date=?,updated_at=NOW() WHERE company_id=? AND id=? AND next_adjustment_date=? AND status='ATIVO'");
  $hist=$pdo->prepare('INSERT INTO contract_item_adjustments(company_id,contract_id,contract_item_id,old_adjustment_date,new_adjustment_date,percent_adjusted,old_unit_price,new_unit_price,old_total,new_total,created_by) VALUES(?,?,?,?,?,?,?,?,?,?,?)');
  foreach($ids as $id){
   $get->execute([$cid,$id]);$r=$get->fetch(PDO::FETCH_ASSOC);
   if(!$r||$r['status']!=='ATIVO'||$r['contract_status']!=='ATIVO'||!$r['next_adjustment_date']||$r['next_adjustment_date']<$from||$r['next_adjustment_date']>$to)throw new RuntimeException('Item #'.$id.' não está mais elegível. Atualize a consulta e tente novamente.');
   $raw=$rates[$id]??null;if(!is_scalar($raw))throw new RuntimeException('Percentual não informado no item #'.$id);
   $s=trim((string)$raw);if(!preg_match('/^-?\d{1,3}(?:[,.]\d{1,4})?$/',$s))throw new RuntimeException('Percentual inválido no item #'.$id);
   $pct=(float)str_replace(',','.',$s);if($pct < -100||$pct>300)throw new RuntimeException('Percentual fora do limite (-100% a 300%) no item #'.$id);
   [$unit,$disc,$add,$total]=adj_amount($r,$pct);$next=adj_next($r['next_adjustment_date']);
   $update->execute([$unit,$disc,$add,$total,$next,$cid,$id,$r['next_adjustment_date']]);if($update->rowCount()!==1)throw new RuntimeException('Item alterado por outra sessão. Nenhuma alteração deste lote foi concluída.');
   $hist->execute([$cid,$r['contract_id'],$id,$r['next_adjustment_date'],$next,$pct,$r['unit_price'],$unit,$r['total'],$total,$uid]);
   $affected[(int)$r['contract_id']]=true;$changes[]=$id;
  }
  foreach(array_keys($affected) as $contractId)contracts_recalc($pdo,$cid,(int)$contractId);
  $pdo->commit();$success=count($changes).' item(ns) reajustado(s) com histórico. Próximas datas avançadas em um ano.';
 }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$error=$e->getMessage();}
}
$rows=[];
if($error===''||$_SERVER['REQUEST_METHOD']!=='POST'){
 try{
  if(adj_valid($from)&&adj_valid($to)&&$from<=$to){
   $st=$pdo->prepare("SELECT i.*,c.contract_number,c.contracting_person_name FROM contract_items i JOIN contracts c ON c.id=i.contract_id AND c.company_id=i.company_id WHERE i.company_id=? AND c.status='ATIVO' AND i.status='ATIVO' AND i.next_adjustment_date BETWEEN ? AND ? ORDER BY i.next_adjustment_date,c.contract_number,i.id LIMIT 501");
   $st->execute([$cid,$from,$to]);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
   if(count($rows)>500){$rows=[];$error='Mais de 500 itens encontrados. Reduza o período antes de continuar.';}
  }
 }catch(Throwable $e){$error='Não foi possível consultar os contratos: '.$e->getMessage();}
}
admin_topbar('Reajustes de Contratos','contratos_reajustes');
?>
<style>
.ad-wrap{max-width:1460px;margin:24px auto;padding:0 18px}.ad-card{border:1px solid #dbe4ee;background:white;border-radius:14px;padding:17px;margin:12px 0}.ad-row{display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end}.ad-row>.ad-field{margin:0}.ad-row>.ad-btn{display:inline-flex;align-items:center;justify-content:center;align-self:flex-end;height:39px;box-sizing:border-box;margin:0;padding:0 15px;white-space:nowrap}.ad-field{display:flex;flex-direction:column;gap:5px;font-size:12px;font-weight:700;color:#475569}.ad-field input{height:39px;border:1px solid #aebbd0;border-radius:8px;padding:7px 10px;box-sizing:border-box}.ad-btn{border:1px solid #b6c3d7;background:white;border-radius:8px;padding:10px 15px;cursor:pointer;font-weight:700;color:#102452;text-decoration:none}.ad-btn.blue{background:#2454d6;color:white;border-color:#2454d6}.ad-btn:disabled{opacity:.55;cursor:default}.ad-scroll{max-width:100%;overflow:auto}.ad-table{border-collapse:collapse;width:100%;min-width:980px}.ad-table th,.ad-table td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:left;font-size:12px}.ad-table th{background:#f1f5f9}.ad-table td.num{text-align:right;white-space:nowrap}.ad-table input.rate{width:90px;height:34px;border:1px solid #aebbd0;border-radius:6px;padding:6px}.ad-msg{padding:12px;border-radius:8px;background:#fef2f2;color:#991b1b}.ad-ok{background:#ecfdf5;color:#166534}.ad-muted{color:#64748b;font-size:12px}.ad-sum{display:flex;gap:24px;flex-wrap:wrap;justify-content:flex-end;font-size:13px}.ad-sum strong{color:#0b1f5b}.ad-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:15px}
</style>
<div class="ad-wrap"><h1 style="color:#0b1f5b;margin-bottom:5px">Reajuste de contratos</h1><p class="ad-muted">Reajuste anual por item, com intervalo de datas inclusive e histórico de valores.</p>
<?php if($error!==''):?><div class="ad-msg"><?=contracts_e($error)?></div><?php endif;?><?php if($success!==''):?><div class="ad-msg ad-ok"><?=contracts_e($success)?></div><?php endif;?>
<div class="ad-card"><form method="get" class="ad-row"><label class="ad-field">Data inicial<input type="date" name="from" required value="<?=contracts_e($from)?>"></label><label class="ad-field">Data final<input type="date" name="to" required value="<?=contracts_e($to)?>"></label><button class="ad-btn blue">Filtrar</button><a class="ad-btn" href="contratos_reajustes.php">Limpar</a></form></div>
<?php if(!$ready):?><div class="ad-msg">O histórico ainda não está instalado. Aplique a migration incluída no pacote antes de realizar reajustes.</div><?php endif;?>
<form method="post" id="adjustForm" class="ad-card"><input type="hidden" name="csrf" value="<?=contracts_e($token)?>"><input type="hidden" name="from" value="<?=contracts_e($from)?>"><input type="hidden" name="to" value="<?=contracts_e($to)?>">
<div class="ad-row" style="margin-bottom:14px"><label class="ad-field">Percentual geral (%)<input id="generalRate" inputmode="decimal" value="6,00" style="width:135px"></label><button type="button" class="ad-btn" id="applyGeneral">Aplicar a todos</button><span class="ad-muted">Você pode alterar o percentual de cada item antes de confirmar.</span></div>
<div class="ad-scroll"><table class="ad-table" id="adjTable"><thead><tr><th><input type="checkbox" id="selectAll" title="Selecionar todos"></th><th>Contrato</th><th>Cliente</th><th>Item</th><th>Próximo reajuste</th><th>Valor atual</th><th>Percentual (%)</th><th>Novo valor estimado</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr data-base="<?=contracts_e($r['total'])?>" data-unit="<?=contracts_e($r['unit_price'])?>" data-qty="<?=contracts_e($r['quantity'])?>" data-disc="<?=contracts_e($r['discount_amount'])?>" data-add="<?=contracts_e($r['addition_amount'])?>"><td><input class="pick" type="checkbox" name="selected[]" value="<?=(int)$r['id']?>" checked></td><td><a href="contrato_editar.php?id=<?=(int)$r['contract_id']?>&tab=itens">#<?=contracts_e($r['contract_number'])?></a></td><td><?=contracts_e($r['contracting_person_name'])?></td><td><?=contracts_e($r['description_snapshot'])?></td><td><?=date('d/m/Y',strtotime($r['next_adjustment_date']))?></td><td class="num"><?=contracts_money($r['total'])?></td><td><input class="rate" name="rate[<?=(int)$r['id']?>]" value="6,00" inputmode="decimal" aria-label="Percentual de reajuste"></td><td class="num new">—</td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8" class="ad-muted">Nenhum item ativo com reajuste previsto no período.</td></tr><?php endif;?></tbody></table></div>
<div class="ad-sum" style="margin-top:15px"><span>Selecionados: <strong id="selectedCount">0</strong></span><span>Valor atual: <strong id="oldTotal">R$ 0,00</strong></span><span>Valor reajustado: <strong id="newTotal">R$ 0,00</strong></span></div>
<div class="ad-actions"><button type="submit" class="ad-btn blue" <?=(!$ready||!$rows)?'disabled':''?>>Confirmar reajuste dos selecionados</button></div>
<p class="ad-muted">Os novos valores serão usados apenas nas futuras gerações. Pedidos, recebíveis e boletos existentes não serão alterados. A data do próximo reajuste avança 1 ano por item.</p></form></div>
<script>
(()=>{const form=document.getElementById('adjustForm'),table=document.getElementById('adjTable'),lines=[...table.querySelectorAll('tbody tr[data-base]')],brl=n=>n.toLocaleString('pt-BR',{style:'currency',currency:'BRL'}),num=s=>Number(String(s).replace(',','.'));
const refresh=()=>{let count=0,old=0,now=0;lines.forEach(tr=>{const rate=num(tr.querySelector('.rate').value),valid=Number.isFinite(rate)&&rate>=-100&&rate<=300,picked=tr.querySelector('.pick').checked,base=Number(tr.dataset.base),factor=1+rate/100,unit=Math.round((Number(tr.dataset.unit)*factor+Number.EPSILON)*10000)/10000,gross=Number(tr.dataset.qty)*unit,disc=Math.round(Number(tr.dataset.disc)*factor*100)/100,add=Math.round(Number(tr.dataset.add)*factor*100)/100,amount=Math.max(0,Math.round((gross-disc+add)*100)/100);tr.querySelector('.new').textContent=valid?brl(amount):'Percentual inválido';if(picked){count++;old+=base;if(valid)now+=amount;}});document.getElementById('selectedCount').textContent=count;document.getElementById('oldTotal').textContent=brl(old);document.getElementById('newTotal').textContent=brl(now);};
document.getElementById('applyGeneral').onclick=()=>{const v=document.getElementById('generalRate').value;lines.forEach(tr=>tr.querySelector('.rate').value=v);refresh();};document.getElementById('selectAll').onchange=e=>{lines.forEach(tr=>tr.querySelector('.pick').checked=e.target.checked);refresh();};table.addEventListener('input',refresh);table.addEventListener('change',refresh);
form.addEventListener('submit',e=>{const chosen=lines.filter(tr=>tr.querySelector('.pick').checked);if(!chosen.length){alert('Selecione pelo menos um item.');e.preventDefault();return;}if(chosen.some(tr=>{const x=num(tr.querySelector('.rate').value);return !Number.isFinite(x)||x< -100||x>300})){alert('Corrija os percentuais informados.');e.preventDefault();return;}if(!confirm('Confirmar reajuste de '+chosen.length+' item(ns)? Esta operação alterará os valores dos contratos e avançará as datas em 1 ano.'))e.preventDefault();});refresh();})();
</script>
<?php admin_footer(); ?>
