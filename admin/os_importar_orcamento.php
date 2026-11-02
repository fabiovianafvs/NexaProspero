<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/orcamento_module.php';
require_once __DIR__ . '/../config/os_orcamento_bridge.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);orc_require_enabled($pdo,$companyId);
if(function_exists('access_has_permission') && (!access_has_permission($pdo,$companyId,'os_monitor') || !access_has_permission($pdo,$companyId,'orcamentos'))){access_render_denied('Seu usuário precisa ter acesso aos Orçamentos e ao Monitor de O.S. para importar um orçamento.');}
os_ensure_defaults($pdo,$companyId);$userId=os_user_id();
$error='';$q=trim((string)($_GET['q']??''));$quoteId=max(0,(int)($_GET['quote_id']??$_POST['quote_id']??0));
if(!os_quote_bridge_schema_ready($pdo)){$error='A V11.40 ainda não foi aplicada no Atualizador do Banco.';}
if($_SERVER['REQUEST_METHOD']==='POST' && !$error){
  try{$newId=os_quote_bridge_create($pdo,$companyId,$quoteId,(int)$userId);header('Location: os_editar.php?id='.$newId.'&step=1&ok='.rawurlencode('Orçamento importado com sucesso para a O.S.'));exit;}catch(Throwable $e){$error=$e->getMessage();}
}
$selected=null;$items=[];$payments=[];$futureQty=0.0;
if($quoteId>0){$selected=orc_fetch($pdo,$companyId,$quoteId);if($selected){$st=$pdo->prepare('SELECT * FROM orcamento_items WHERE company_id=? AND quote_id=? ORDER BY id');$st->execute([$companyId,$quoteId]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$st=$pdo->prepare('SELECT * FROM orcamento_payments WHERE company_id=? AND quote_id=? ORDER BY due_date,id');$st->execute([$companyId,$quoteId]);$payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[];$futureQty=os_quote_bridge_future_quantity($pdo,$companyId,$quoteId);}}
$rows=(!$selected&&!$error)?os_quote_bridge_search($pdo,$companyId,$q,200):[];
os_page_start('Importar Orçamento','nova');
?>
<style>
.osi-search{display:grid;grid-template-columns:minmax(250px,1fr) auto auto;gap:8px;align-items:end}.osi-actions{display:flex;gap:8px;flex-wrap:wrap}.osi-summary{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-bottom:14px}.osi-summary>div{padding:12px;border:1px solid var(--os-border-soft);border-radius:12px;background:#f8fafc}.osi-summary span{display:block;color:#64748b;font-size:11px}.osi-summary b{display:block;color:#0b1f5b;margin-top:4px}.osi-warning{padding:12px 14px;background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:13px;margin-bottom:12px}@media(max-width:760px){.osi-search,.osi-summary{grid-template-columns:1fr}.osi-actions .btn{width:100%}}
</style>
<div class="os-title-row"><h1>Importar Orçamento para O.S.</h1><div class="spacer"></div><a class="btn btn-secondary" href="os_editar.php">Nova O.S. manual</a><a class="btn btn-secondary" href="os_monitor.php">Monitor</a></div>
<?php if($error!==''):?><div class="alert alert-error"><?=os_e($error)?></div><?php endif;?>
<?php if($selected):?>
<div class="card"><div class="card-head">Orçamento #<?=str_pad((string)(int)$selected['quote_number'],6,'0',STR_PAD_LEFT)?></div><div class="card-body">
<div class="osi-summary"><div><span>Cliente</span><b><?=os_e($selected['person_name_snapshot']?:'Não informado')?></b></div><div><span>Data</span><b><?=date('d/m/Y',strtotime((string)$selected['quote_date']))?></b></div><div><span>Status</span><b><?=os_e(orc_status_label((string)$selected['status']))?></b></div><div><span>Total</span><b><?=orc_money($selected['total_general'])?></b></div></div>
<?php if($futureQty>0.00001):?><div class="osi-warning"><b>Entrega futura detectada:</b> este orçamento possui <?=number_format($futureQty,4,',','.')?> unidade(s) marcadas para entrega futura. Para preservar estoque e fiscal, ele deve seguir pelo Pedido/Controle de Entregas e não pode ser convertido diretamente em O.S.</div><?php endif;?>
<div class="table-wrap"><table class="table"><thead><tr><th>Tipo</th><th>Descrição</th><th class="num">Qtd.</th><th>Reserva</th><th class="num">Total</th></tr></thead><tbody><?php foreach($items as $it):?><tr><td><?=os_e($it['item_type'])?></td><td><?=os_e($it['description_snapshot'])?></td><td class="num"><?=number_format((float)$it['quantity'],4,',','.')?></td><td><?=os_e($it['reservation_status'])?></td><td class="num"><?=orc_money($it['total'])?></td></tr><?php endforeach;?></tbody></table></div>
<div class="alert" style="margin-top:14px;background:#eff6ff;border-color:#bfdbfe;color:#1e40af"><b>Reservas:</b> produtos já reservados no orçamento serão transferidos para a O.S. sem reservar o estoque novamente. As formas de pagamento também serão copiadas.</div>
<form method="post" style="margin-top:14px"><input type="hidden" name="quote_id" value="<?=$quoteId?>"><div class="osi-actions"><button class="btn btn-primary" <?=$futureQty>0.00001?'disabled':''?>>Criar O.S. a partir deste orçamento</button><a class="btn btn-secondary" href="os_importar_orcamento.php">Escolher outro orçamento</a><a class="btn" target="_blank" href="orcamento_imprimir.php?id=<?=$quoteId?>">Visualizar orçamento</a></div></form>
</div></div>
<?php else:?>
<div class="card"><div class="card-head">Localizar orçamento</div><div class="card-body"><form method="get" class="osi-search"><div><label>Pesquisa</label><input id="osi_q" name="q" value="<?=os_e($q)?>" placeholder="Nº do orçamento, cliente, documento ou observação" autofocus></div><button class="btn btn-primary" type="submit">Buscar (F8)</button><a class="btn btn-secondary" href="os_importar_orcamento.php">Limpar</a></form></div></div>
<div class="card"><div class="card-head">Orçamentos disponíveis <span class="muted">• <?=count($rows)?> registro(s)</span></div><div class="card-body"><div class="table-wrap"><table class="table"><thead><tr><th>Nº</th><th>Data</th><th>Cliente</th><th>Status</th><th class="num">Total</th><th></th></tr></thead><tbody><?php if(!$rows):?><tr><td colspan="6" class="muted">Nenhum orçamento disponível para importação.</td></tr><?php endif;?><?php foreach($rows as $r):?><tr><td><b>#<?=str_pad((string)(int)$r['quote_number'],6,'0',STR_PAD_LEFT)?></b></td><td><?=date('d/m/Y',strtotime((string)$r['quote_date']))?></td><td><?=os_e($r['person_name_snapshot']?:'Não informado')?></td><td><?=os_e(orc_status_label((string)$r['status']))?></td><td class="num"><b><?=orc_money($r['total_general'])?></b></td><td><a class="btn btn-primary btn-sm" href="os_importar_orcamento.php?quote_id=<?=(int)$r['id']?>">Importar</a></td></tr><?php endforeach;?></tbody></table></div></div></div>
<?php endif;?>
<script>document.addEventListener('keydown',function(e){if(e.key==='F8'){var q=document.getElementById('osi_q');if(q){e.preventDefault();q.focus();q.select();}}});</script>
<?php os_page_end(); ?>
