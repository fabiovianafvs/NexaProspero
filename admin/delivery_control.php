<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';
$companyId=(int)COMPANY_ID; delivery_control_require_enabled($pdo,$companyId);
$q=trim((string)($_GET['q']??''));
$status=strtoupper(trim((string)($_GET['status']??'')));
$saleDate=trim((string)($_GET['sale_date']??''));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
$where=['s.company_id=?'];$params=[$companyId];
if($q!==''){
  foreach(array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[],0,6) as $t){
    $like=nexa_search_like($t);
    $where[]='(CAST(s.pedido_id AS CHAR) LIKE ? OR s.customer_name LIKE ? OR s.customer_document LIKE ? OR EXISTS(SELECT 1 FROM delivery_control_sale_items si2 WHERE si2.company_id=s.company_id AND si2.delivery_sale_id=s.id AND si2.product_name LIKE ?))';
    array_push($params,$like,$like,$like,$like);
  }
}
if(in_array($status,['PENDENTE','PROGRAMADA','ENTREGA_PARCIAL','CONCLUIDA','CANCELADA'],true)){$where[]='s.status=?';$params[]=$status;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$saleDate)){
  $where[]='p.created_at>=? AND p.created_at<?';$params[]=$saleDate.' 00:00:00';$params[]=(new DateTimeImmutable($saleDate))->modify('+1 day')->format('Y-m-d').' 00:00:00';
}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)){$where[]='COALESCE(s.expected_date,DATE(s.created_at))>=?';$params[]=$dateFrom;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)){$where[]='COALESCE(s.expected_date,DATE(s.created_at))<=?';$params[]=$dateTo;}
$sql="SELECT s.*,p.created_at sale_created_at,COALESCE(SUM(si.future_quantity),0) future_qty,COALESCE(SUM(si.ordered_quantity),0) ordered_qty,COALESCE(SUM(si.delivered_quantity),0) delivered_qty
      FROM delivery_control_sales s
      LEFT JOIN pedidos p ON p.company_id=s.company_id AND p.id=s.pedido_id
      LEFT JOIN delivery_control_sale_items si ON si.company_id=s.company_id AND si.delivery_sale_id=s.id
      WHERE ".implode(' AND ',$where)."
      GROUP BY s.id,p.created_at
      ORDER BY CASE WHEN s.status='CONCLUIDA' THEN 1 ELSE 0 END,s.expected_date IS NULL,s.expected_date,s.id DESC LIMIT 500";
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
$kst=$pdo->prepare("SELECT status,COUNT(*) n FROM delivery_control_sales WHERE company_id=? GROUP BY status");$kst->execute([$companyId]);$kc=[];foreach($kst->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$kc[(string)$r['status']]=(int)$r['n'];
$gridColumns=['pedido'=>'Pedido','cliente'=>'Cliente','venda'=>'Data da venda','previsao'=>'Previsão','status'=>'Status','futuro'=>'Futuro','em_oe'=>'Em O.E.','entregue'=>'Entregue','saldo'=>'Saldo p/ O.E.','acoes'=>'Ações'];
$gridPrefs=dc_grid_preferences_load($pdo,$companyId,'controle_entregas',array_keys($gridColumns));
dc_page_start('Controle de Entregas','controle_entregas');
?>
<div class="dc-head"><h1>Controle de Entregas</h1><div class="dc-spacer"></div><a class="dc-btn" href="delivery_orders.php">Ordens de Entrega</a><a class="dc-btn" href="delivery_manifest.php">Romaneios</a><a class="dc-btn" href="delivery_fiscal_queue.php">Fila Fiscal</a></div>
<div class="dc-kpis"><div class="dc-kpi"><span class="dc-muted">Pendentes</span><b><?=($kc['PENDENTE']??0)?></b></div><div class="dc-kpi"><span class="dc-muted">Programadas</span><b><?=($kc['PROGRAMADA']??0)?></b></div><div class="dc-kpi"><span class="dc-muted">Parciais</span><b><?=($kc['ENTREGA_PARCIAL']??0)?></b></div><div class="dc-kpi"><span class="dc-muted">Concluídas</span><b><?=($kc['CONCLUIDA']??0)?></b></div></div>
<div class="dc-card"><div class="dc-card-head">Filtros</div><div class="dc-card-body"><form method="get" class="dc-delivery-filter">
  <div class="dc-field"><label>Pesquisar</label><input name="q" value="<?=dc_e($q)?>" placeholder="Pedido, cliente, documento ou produto"></div>
  <div class="dc-field"><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['PENDENTE','PROGRAMADA','ENTREGA_PARCIAL','CONCLUIDA','CANCELADA'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=dc_e(dc_status_label($s))?></option><?php endforeach;?></select></div>
  <div class="dc-field"><label>Data da venda</label><input type="date" name="sale_date" value="<?=dc_e($saleDate)?>"></div>
  <div class="dc-field"><label>Previsão inicial</label><input type="date" name="date_from" value="<?=dc_e($dateFrom)?>"></div>
  <div class="dc-field"><label>Previsão final</label><input type="date" name="date_to" value="<?=dc_e($dateTo)?>"></div>
  <div class="dc-filter-actions"><button class="dc-btn primary">Filtrar</button><a class="dc-btn" href="delivery_control.php">Limpar</a></div>
</form></div></div>
<div class="dc-card">
  <div class="dc-card-head with-grid-tools"><span>Entregas <span class="dc-muted">• <?=count($rows)?> registro(s)</span></span><?php dc_grid_toolbar('controle_entregas',$gridColumns,$gridPrefs);?></div>
  <div class="dc-card-body"><div class="dc-advanced-wrap"><table class="dc-data-grid" id="dcGrid-controle_entregas"><thead><tr>
    <th data-col="pedido"><div class="dc-grid-th-inner">Pedido</div></th>
    <th data-col="cliente"><div class="dc-grid-th-inner">Cliente</div></th>
    <th data-col="venda"><div class="dc-grid-th-inner">Data da venda</div></th>
    <th data-col="previsao"><div class="dc-grid-th-inner">Previsão</div></th>
    <th data-col="status"><div class="dc-grid-th-inner">Status</div></th>
    <th data-col="futuro" class="num"><div class="dc-grid-th-inner">Futuro</div></th>
    <th data-col="em_oe" class="num"><div class="dc-grid-th-inner">Em O.E.</div></th>
    <th data-col="entregue" class="num"><div class="dc-grid-th-inner">Entregue</div></th>
    <th data-col="saldo" class="num"><div class="dc-grid-th-inner">Saldo p/ O.E.</div></th>
    <th data-col="acoes" class="dc-actions-col"><div class="dc-grid-th-inner">Ações</div></th>
  </tr></thead><tbody>
  <?php if(!$rows):?><tr><?php foreach(array_keys($gridColumns) as $c):?><td data-col="<?=dc_e($c)?>" class="<?=$c==='pedido'?'dc-empty-grid':''?>"><?=$c==='pedido'?'Nenhuma entrega encontrada.':''?></td><?php endforeach;?></tr><?php endif;?>
  <?php foreach($rows as $r):$saldo=max(0,(float)$r['future_qty']-(float)$r['ordered_qty']);?><tr>
    <td data-col="pedido"><strong>#<?=(int)$r['pedido_id']?></strong><div class="dc-muted">Entrega #<?=(int)$r['id']?></div></td>
    <td data-col="cliente"><strong><?=dc_e($r['customer_name'])?></strong><div class="dc-muted"><?=dc_e($r['delivery_city'])?><?=!empty($r['delivery_state'])?'/'.dc_e($r['delivery_state']):''?></div></td>
    <td data-col="venda"><?=dc_datetime($r['sale_created_at']??$r['created_at'])?></td>
    <td data-col="previsao"><?=dc_date($r['expected_date'])?></td>
    <td data-col="status"><span class="dc-badge <?=dc_status_class((string)$r['status'])?>"><?=dc_e(dc_status_label((string)$r['status']))?></span></td>
    <td data-col="futuro" class="num"><?=dc_qty($r['future_qty'])?></td>
    <td data-col="em_oe" class="num"><?=dc_qty($r['ordered_qty'])?></td>
    <td data-col="entregue" class="num"><?=dc_qty($r['delivered_qty'])?></td>
    <td data-col="saldo" class="num"><strong><?=dc_qty($saldo)?></strong></td>
    <td data-col="acoes" class="dc-actions-col"><a class="dc-btn sm primary" href="delivery_control_view.php?id=<?=(int)$r['id']?>">Abrir</a></td>
  </tr><?php endforeach;?>
  </tbody></table></div></div>
</div>
<?php dc_grid_script('controle_entregas',$gridColumns,$gridPrefs,['pedido'=>120,'cliente'=>260,'venda'=>145,'previsao'=>120,'status'=>135,'futuro'=>95,'em_oe'=>95,'entregue'=>95,'saldo'=>115,'acoes'=>105]); ?>
<?php dc_page_end(); ?>
