<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';

$companyId=(int)COMPANY_ID;
delivery_control_require_enabled($pdo,$companyId);
$userId=dc_user_id();
$msg='';
$err='';

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        dc_csrf_check((string)($_POST['csrf']??''));
        $oid=(int)($_POST['order_id']??0);
        $action=(string)($_POST['action']??'');
        if($action==='deliver'){
            delivery_control_mark_delivered($pdo,$companyId,$oid,$userId);
            $msg='Entrega confirmada com sucesso.';
        }elseif($action==='cancel'){
            delivery_control_cancel_order($pdo,$companyId,$oid,$userId);
            $msg='Ordem de Entrega cancelada e estoque/reserva restaurados.';
        }
    }catch(Throwable $e){$err=$e->getMessage();}
}

$q=trim((string)($_GET['q']??''));
$status=strtoupper(trim((string)($_GET['status']??'')));
$programStart=trim((string)($_GET['program_start']??''));
$programEnd=trim((string)($_GET['program_end']??''));
if($programStart!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$programStart))$programStart='';
if($programEnd!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$programEnd))$programEnd='';

$where=['o.company_id=?'];
$params=[$companyId];
if($q!==''){
    $like=nexa_search_like($q);
    $where[]='(CAST(o.order_number AS CHAR) LIKE ? OR s.customer_name LIKE ? OR CAST(s.pedido_id AS CHAR) LIKE ? OR o.delivery_address LIKE ? OR o.delivery_district LIKE ? OR o.delivery_city LIKE ?)';
    array_push($params,$like,$like,$like,$like,$like,$like);
}
if(in_array($status,['GERADA','EM_ROMANEIO','SEPARADA','SAIU_PARA_ENTREGA','ENTREGUE','CANCELADA'],true)){
    $where[]='o.status=?';$params[]=$status;
}
if($programStart!==''){$where[]='o.scheduled_date>=?';$params[]=$programStart;}
if($programEnd!==''){$where[]='o.scheduled_date<=?';$params[]=$programEnd;}

$sql="SELECT o.*,s.customer_name,s.pedido_id,s.id delivery_sale_id,COALESCE(SUM(oi.quantity),0) total_qty,
      (SELECT COUNT(*) FROM delivery_control_fiscal_queue fq WHERE fq.company_id=o.company_id AND fq.delivery_order_id=o.id AND fq.status='AUTORIZADA') fiscal_authorized
      FROM delivery_control_orders o
      JOIN delivery_control_sales s ON s.company_id=o.company_id AND s.id=o.delivery_sale_id
      LEFT JOIN delivery_control_order_items oi ON oi.company_id=o.company_id AND oi.delivery_order_id=o.id
      WHERE ".implode(' AND ',$where)."
      GROUP BY o.id ORDER BY o.id DESC LIMIT 500";
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

$gridColumns=['oe'=>'O.E.','cliente'=>'Cliente','pedido'=>'Pedido','programacao'=>'Programação','status'=>'Status','fiscal'=>'Fiscal','qtd'=>'Qtd.','acoes'=>'Ações'];
$gridPrefs=dc_grid_preferences_load($pdo,$companyId,'ordens_entrega',array_keys($gridColumns));
dc_page_start('Ordens de Entrega','delivery_orders');
?>
<div class="dc-head"><h1>Ordens de Entrega</h1><div class="dc-spacer"></div><a class="dc-btn" href="delivery_control.php">Controle de Entregas</a><a class="dc-btn primary" href="delivery_manifest.php">Romaneios</a></div>
<?php if($msg):?><div class="dc-alert"><?=dc_e($msg)?></div><?php endif;?><?php if($err):?><div class="dc-alert error"><?=dc_e($err)?></div><?php endif;?>
<div class="dc-card">
  <div class="dc-card-head">Filtros</div>
  <div class="dc-card-body">
    <form method="get" class="dc-order-filter">
      <div class="dc-field"><label>Pesquisar</label><input name="q" value="<?=dc_e($q)?>" placeholder="Nº O.E., pedido, cliente ou endereço"></div>
      <div class="dc-field"><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['GERADA','EM_ROMANEIO','SEPARADA','SAIU_PARA_ENTREGA','ENTREGUE','CANCELADA'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=dc_e(dc_status_label($s))?></option><?php endforeach;?></select></div>
      <div class="dc-field"><label>Programação inicial</label><input type="date" name="program_start" value="<?=dc_e($programStart)?>"></div>
      <div class="dc-field"><label>Programação final</label><input type="date" name="program_end" value="<?=dc_e($programEnd)?>"></div>
      <div class="dc-filter-actions"><button class="dc-btn primary">Filtrar</button><a class="dc-btn" href="delivery_orders.php">Limpar</a></div>
    </form>
  </div>
</div>
<style>.dc-order-filter{display:grid;grid-template-columns:minmax(270px,1.5fr) minmax(150px,.65fr) minmax(145px,.55fr) minmax(145px,.55fr) auto;gap:10px;align-items:end}@media(max-width:1100px){.dc-order-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.dc-order-filter .dc-filter-actions{grid-column:span 2}}@media(max-width:700px){.dc-order-filter{grid-template-columns:1fr}.dc-order-filter .dc-filter-actions{grid-column:auto}}</style>
<div class="dc-card"><div class="dc-card-head with-grid-tools"><span>Lista <span class="dc-muted">• <?=count($rows)?> registro(s)</span></span><?php dc_grid_toolbar('ordens_entrega',$gridColumns,$gridPrefs);?></div><div class="dc-card-body"><div class="dc-advanced-wrap"><table class="dc-data-grid" id="dcGrid-ordens_entrega"><thead><tr>
<th data-col="oe"><div class="dc-grid-th-inner">O.E.</div></th><th data-col="cliente"><div class="dc-grid-th-inner">Cliente</div></th><th data-col="pedido"><div class="dc-grid-th-inner">Pedido</div></th><th data-col="programacao"><div class="dc-grid-th-inner">Programação</div></th><th data-col="status"><div class="dc-grid-th-inner">Status</div></th><th data-col="fiscal"><div class="dc-grid-th-inner">Fiscal</div></th><th data-col="qtd" class="num"><div class="dc-grid-th-inner">Qtd.</div></th><th data-col="acoes" class="dc-actions-col"><div class="dc-grid-th-inner">Ações</div></th>
</tr></thead><tbody>
<?php if(!$rows):?><tr><?php foreach(array_keys($gridColumns) as $c):?><td data-col="<?=dc_e($c)?>" class="<?=$c==='oe'?'dc-empty-grid':''?>"><?=$c==='oe'?'Nenhuma O.E. encontrada.':''?></td><?php endforeach;?></tr><?php endif;?>
<?php foreach($rows as $r):?><tr>
<td data-col="oe"><strong>#<?=str_pad((string)$r['order_number'],6,'0',STR_PAD_LEFT)?></strong></td><td data-col="cliente"><?=dc_e($r['customer_name'])?></td><td data-col="pedido">#<?=(int)$r['pedido_id']?></td><td data-col="programacao"><?=dc_date($r['scheduled_date'])?></td><td data-col="status"><span class="dc-badge <?=dc_status_class((string)$r['status'])?>"><?=dc_e(dc_status_label((string)$r['status']))?></span></td><td data-col="fiscal"><?=$r['fiscal_authorized']?'<span class="dc-badge ok">Autorizado</span>':'<span class="dc-badge info">Pendente/sem doc.</span>'?></td><td data-col="qtd" class="num"><?=dc_qty($r['total_qty'])?></td><td data-col="acoes" class="dc-actions-col"><div class="dc-actions"><a class="dc-btn sm" href="delivery_control_view.php?id=<?=(int)$r['delivery_sale_id']?>">Venda</a><?php if(!in_array((string)$r['status'],['ENTREGUE','CANCELADA'],true)):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="deliver"><button class="dc-btn sm primary" onclick="return confirm('Confirmar que esta O.E. foi entregue ao cliente?')">Entregue</button></form><?php endif;?><?php if(in_array((string)$r['status'],['GERADA','EM_ROMANEIO','SEPARADA'],true)):?><form method="post" style="display:inline"><input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>"><input type="hidden" name="order_id" value="<?=(int)$r['id']?>"><input type="hidden" name="action" value="cancel"><button class="dc-btn sm danger" onclick="return confirm('Cancelar a O.E.? O estoque e a reserva serão restaurados.')">Cancelar</button></form><?php endif;?></div></td>
</tr><?php endforeach;?></tbody></table></div></div></div>
<?php dc_grid_script('ordens_entrega',$gridColumns,$gridPrefs,['oe'=>100,'cliente'=>250,'pedido'=>100,'programacao'=>125,'status'=>145,'fiscal'=>155,'qtd'=>85,'acoes'=>270]); ?>
<?php dc_page_end(); ?>
