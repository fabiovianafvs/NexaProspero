<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';
$companyId=(int)COMPANY_ID;delivery_control_require_enabled($pdo,$companyId);$userId=dc_user_id();$msg='';$err='';
if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
 try{dc_csrf_check((string)($_POST['csrf']??''));$qid=(int)($_POST['queue_id']??0);$action=(string)($_POST['action']??'');if($action==='authorize'){$res=delivery_control_fiscal_authorize($pdo,$companyId,$qid,$userId);$msg='Documento fiscal processado. '.(!empty($res['doc_id'])?'Documento #'.(int)$res['doc_id'].'.':'');}elseif($action==='prepare'){$shadow=delivery_control_fiscal_prepare_shadow($pdo,$companyId,$qid,$userId);$msg='Pedido fiscal isolado #'.$shadow.' preparado, sem nova baixa de estoque/financeiro.';}}catch(Throwable $e){$err=$e->getMessage();}
}
$status=strtoupper(trim((string)($_GET['status']??'')));$where=['q.company_id=?'];$params=[$companyId];if(in_array($status,['PENDENTE','PREPARADA','PROCESSANDO','AUTORIZADA','ERRO','CANCELADA'],true)){$where[]='q.status=?';$params[]=$status;}
$sql="SELECT q.*,s.pedido_id,s.customer_name,o.order_number FROM delivery_control_fiscal_queue q JOIN delivery_control_sales s ON s.company_id=q.company_id AND s.id=q.delivery_sale_id LEFT JOIN delivery_control_orders o ON o.company_id=q.company_id AND o.id=q.delivery_order_id WHERE ".implode(' AND ',$where)." ORDER BY q.id DESC LIMIT 500";$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
$gridColumns=['id'=>'ID','origem'=>'Origem','cliente'=>'Cliente','documento'=>'Documento','status'=>'Status','pedido_fiscal'=>'Pedido fiscal','erro'=>'Erro','acoes'=>'Ações'];
$gridPrefs=dc_grid_preferences_load($pdo,$companyId,'fila_fiscal_entregas',array_keys($gridColumns));
dc_page_start('Fila Fiscal de Entregas','delivery_fiscal_queue');
?>
<div class="dc-head"><h1>Fila Fiscal de Entregas</h1><div class="dc-spacer"></div><a class="dc-btn" href="delivery_control.php">Controle de Entregas</a><a class="dc-btn" href="fiscal_documentos.php">Documentos Fiscais</a></div>
<div class="dc-alert"><strong>Fila isolada:</strong> os pedidos fiscais gerados aqui são técnicos, não criam nova venda, financeiro ou baixa de estoque. A autorização reutiliza o emissor fiscal existente do Nexa.</div>
<?php if($msg):?><div class="dc-alert"><?=dc_e($msg)?></div><?php endif;?><?php if($err):?><div class="dc-alert error"><?=dc_e($err)?></div><?php endif;?>
<div class="dc-card"><div class="dc-card-head">Filtro</div><div class="dc-card-body"><form method="get" class="dc-grid three"><div class="dc-field"><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['PENDENTE','PREPARADA','PROCESSANDO','AUTORIZADA','ERRO','CANCELADA'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=dc_e(dc_status_label($s))?></option><?php endforeach;?></select></div><div class="dc-actions" style="align-items:end"><button class="dc-btn primary">Filtrar</button><a class="dc-btn" href="delivery_fiscal_queue.php">Limpar</a></div></form></div></div>
<div class="dc-card"><div class="dc-card-head with-grid-tools"><span>Itens da fila <span class="dc-muted">• <?=count($rows)?> registro(s)</span></span><?php dc_grid_toolbar('fila_fiscal_entregas',$gridColumns,$gridPrefs);?></div><div class="dc-card-body"><div class="dc-advanced-wrap"><table class="dc-data-grid" id="dcGrid-fila_fiscal_entregas"><thead><tr>
<th data-col="id"><div class="dc-grid-th-inner">ID</div></th><th data-col="origem"><div class="dc-grid-th-inner">Origem</div></th><th data-col="cliente"><div class="dc-grid-th-inner">Cliente</div></th><th data-col="documento"><div class="dc-grid-th-inner">Documento</div></th><th data-col="status"><div class="dc-grid-th-inner">Status</div></th><th data-col="pedido_fiscal"><div class="dc-grid-th-inner">Pedido fiscal</div></th><th data-col="erro"><div class="dc-grid-th-inner">Erro</div></th><th data-col="acoes" class="dc-actions-col"><div class="dc-grid-th-inner">Ações</div></th>
</tr></thead><tbody>
<?php if(!$rows):?><tr><?php foreach(array_keys($gridColumns) as $c):?><td data-col="<?=dc_e($c)?>" class="<?=$c==='id'?'dc-empty-grid':''?>"><?=$c==='id'?'Fila fiscal vazia.':''?></td><?php endforeach;?></tr><?php endif;?>
<?php foreach($rows as $r):?><tr>
<td data-col="id">#<?=(int)$r['id']?></td>
<td data-col="origem"><?=dc_e($r['origin_type']==='IMEDIATA'?'Saída imediata':'Ordem de Entrega')?><?php if(!empty($r['order_number'])):?><div class="dc-muted">O.E. #<?=str_pad((string)$r['order_number'],6,'0',STR_PAD_LEFT)?></div><?php endif;?><div class="dc-muted">Pedido comercial #<?=(int)$r['pedido_id']?></div></td>
<td data-col="cliente"><?=dc_e($r['customer_name'])?></td>
<td data-col="documento"><strong><?=dc_e($r['document_type'])?></strong></td>
<td data-col="status"><span class="dc-badge <?=dc_status_class((string)$r['status'])?>"><?=dc_e(dc_status_label((string)$r['status']))?></span><?php if(!empty($r['fiscal_document_id'])):?><div class="dc-muted">Doc. #<?=(int)$r['fiscal_document_id']?></div><?php endif;?></td>
<td data-col="pedido_fiscal"><?php if(!empty($r['shadow_pedido_id'])):?><strong>#<?=(int)$r['shadow_pedido_id']?></strong><?php else:?>—<?php endif;?></td>
<td data-col="erro"><span class="dc-muted"><?=dc_e($r['last_error']??'')?></span></td>
<td data-col="acoes" class="dc-actions-col"><div class="dc-actions"><?php if(in_array((string)$r['status'],['PENDENTE','ERRO'],true)):?><form method="post"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="queue_id" value="<?=(int)$r['id']?>"><button name="action" value="prepare" class="dc-btn sm">Preparar</button><button name="action" value="authorize" class="dc-btn sm primary" onclick="return confirm('Autorizar <?=dc_e($r['document_type'])?> desta saída? O emissor fiscal configurado para a empresa será acionado.')">Autorizar</button></form><?php elseif((string)$r['status']==='PREPARADA'):?><form method="post"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="queue_id" value="<?=(int)$r['id']?>"><button name="action" value="authorize" class="dc-btn sm primary" onclick="return confirm('Autorizar <?=dc_e($r['document_type'])?> desta saída?')">Autorizar</button></form><?php endif;?><?php if(!empty($r['shadow_pedido_id']) && $r['document_type']==='NFCE'):?><a class="dc-btn sm" href="fiscal_nfce_pedido.php?pedido_id=<?=(int)$r['shadow_pedido_id']?>">Gerenciar NFC-e</a><?php elseif(!empty($r['fiscal_document_id'])):?><a class="dc-btn sm" href="fiscal_documentos.php">Documentos</a><?php endif;?></div></td>
</tr><?php endforeach;?></tbody></table></div></div></div>
<?php dc_grid_script('fila_fiscal_entregas',$gridColumns,$gridPrefs,['id'=>80,'origem'=>230,'cliente'=>230,'documento'=>110,'status'=>145,'pedido_fiscal'=>115,'erro'=>330,'acoes'=>290]); ?>
<?php dc_page_end(); ?>
