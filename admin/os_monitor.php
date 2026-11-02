<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db(); $companyId=os_company_id(); os_require_enabled($pdo,$companyId); os_ensure_defaults($pdo,$companyId);
$photoCleanupCount=0;
try{
    $photoCleanupCount=os_vehicle_damage_cleanup_expired_photos($pdo,$companyId,48,250);
    os_vehicle_damage_cleanup_tmp_photos($companyId,72);
}catch(Throwable $cleanupError){error_log('[Nexa O.S.] Limpeza de fotos de avarias: '.$cleanupError->getMessage());}
$q=trim(isset($_GET['q'])?$_GET['q']:'');
$statusId=(int)(isset($_GET['status_id'])?$_GET['status_id']:0);
$billing=trim(isset($_GET['billing_status'])?$_GET['billing_status']:'');
$dateFrom=trim(isset($_GET['date_from'])?$_GET['date_from']:'');
$dateTo=trim(isset($_GET['date_to'])?$_GET['date_to']:'');
$type=trim(isset($_GET['item_type'])?strtoupper($_GET['item_type']):'');
$where=array('o.company_id=?'); $params=array($companyId);
if($statusId>0){$where[]='o.status_id=?';$params[]=$statusId;}
if($billing!==''){$where[]='o.billing_status=?';$params[]=$billing;}
if($dateFrom!==''){$where[]='DATE(o.opened_at)>=?';$params[]=$dateFrom;}
if($dateTo!==''){$where[]='DATE(o.opened_at)<=?';$params[]=$dateTo;}
if($type==='PRODUTO' || $type==='SERVICO'){$where[]='EXISTS (SELECT 1 FROM os_order_items oi WHERE oi.company_id=o.company_id AND oi.order_id=o.id AND oi.item_type=?)';$params[]=$type;}
if($q!==''){
    $tokens=preg_split('/\s+/u',$q); foreach($tokens as $t){ if($t==='')continue; $where[]="(CAST(o.os_number AS CHAR) LIKE ? OR o.person_name_snapshot LIKE ? OR o.person_document_snapshot LIKE ? OR o.contact_name LIKE ? OR o.contact_phone LIKE ? OR o.reported_problem LIKE ? OR EXISTS (SELECT 1 FROM os_order_field_values fv JOIN os_custom_fields cf ON cf.id=fv.field_id AND cf.company_id=fv.company_id WHERE fv.company_id=o.company_id AND fv.order_id=o.id AND (fv.value_text LIKE ? OR cf.label LIKE ?)))"; for($i=0;$i<8;$i++)$params[]=nexa_search_like($t); }
}
$sql="SELECT o.*,s.name status_name,s.color status_color,
        (SELECT COUNT(*) FROM os_order_items x WHERE x.company_id=o.company_id AND x.order_id=o.id) item_count
      FROM os_orders o LEFT JOIN os_statuses s ON s.id=o.status_id AND s.company_id=o.company_id
      WHERE ".implode(' AND ',$where)." ORDER BY o.opened_at DESC,o.id DESC LIMIT 500";
$stmt=$pdo->prepare($sql);$stmt->execute($params);$rows=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT id,name,color FROM os_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id");$stmt->execute(array($companyId));$statuses=$stmt->fetchAll(PDO::FETCH_ASSOC);

$sumP=0;$sumS=0;$sumG=0;foreach($rows as $r){$sumP+=(float)$r['total_products'];$sumS+=(float)$r['total_services'];$sumG+=(float)$r['total_general'];}
os_page_start('Monitor de O.S.','monitor');
echo '<div class="os-title-row"><h1>Monitor de O.S.</h1><div class="spacer"></div><a class="btn btn-primary" href="os_editar.php">+ Nova O.S.</a></div>';
if(isset($_GET['ok']))echo '<div class="alert alert-ok">'.os_e($_GET['ok']).'</div>';
if($photoCleanupCount>0)echo '<div class="alert alert-ok">'.(int)$photoCleanupCount.' foto(s) de avaria de O.S. concluída(s) há mais de 48 horas foram removidas automaticamente. As marcações e observações foram preservadas.</div>';
echo '<div class="card"><div class="card-head">Filtros</div><div class="card-body"><form method="get"><div class="filter-grid">';
echo '<div class="filter-search"><label>Pesquisar</label><input name="q" value="'.os_e($q).'" placeholder="Nº O.S., cliente, documento, problema ou campo personalizado"></div>';
echo '<div><label>Status</label><select name="status_id"><option value="0">Todos</option>';foreach($statuses as $s)echo '<option value="'.(int)$s['id'].'" '.($statusId==(int)$s['id']?'selected':'').'>'.os_e($s['name']).'</option>';echo '</select></div>';
echo '<div><label>Faturamento</label><select name="billing_status"><option value="">Todos</option>';foreach(array('PENDENTE'=>'Pendente','ENVIADA_PDV'=>'Fluxo anterior / PDV','FATURADA'=>'Faturada','CANCELADA'=>'Cancelada') as $k=>$v)echo '<option value="'.$k.'" '.($billing===$k?'selected':'').'>'.$v.'</option>';echo '</select></div>';
echo '<div><label>Contém</label><select name="item_type"><option value="">Produtos/serviços</option><option value="PRODUTO" '.($type==='PRODUTO'?'selected':'').'>Produto</option><option value="SERVICO" '.($type==='SERVICO'?'selected':'').'>Serviço</option></select></div>';
echo '<div><label>Data inicial</label><input type="date" name="date_from" value="'.os_e($dateFrom).'"></div><div><label>Data final</label><input type="date" name="date_to" value="'.os_e($dateTo).'"></div>';
echo '<div class="filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn btn-secondary" href="os_monitor.php">Limpar</a><span class="muted small filter-count">'.count($rows).' O.S. encontrada(s)</span></div></div></form></div></div>';
echo '<div class="totals" style="margin-bottom:18px"><div class="total-box"><span>Total em produtos</span><strong>'.os_money($sumP).'</strong></div><div class="total-box"><span>Total em serviços</span><strong>'.os_money($sumS).'</strong></div><div class="total-box"><span>Total geral</span><strong>'.os_money($sumG).'</strong></div></div>';
echo '<div class="card"><div class="card-head">Ordens de Serviço</div><div class="card-body table-wrap os-monitor-table-wrap"><table class="table os-monitor-table"><thead><tr><th>O.S.</th><th>Abertura</th><th>Cliente</th><th>Status</th><th>Itens</th><th class="num">Produtos</th><th class="num">Serviços</th><th class="num">Total</th><th>Faturamento</th><th>Ações</th></tr></thead><tbody>';
if(!$rows)echo '<tr><td colspan="10" class="muted">Nenhuma O.S. encontrada.</td></tr>';
foreach($rows as $r){
 $rowColor=os_ui_hex_color(isset($r['status_color'])?$r['status_color']:null,'#64748B');
 echo '<tr class="os-status-row" style="--os-row-status:'.os_e($rowColor).'"><td data-label="O.S." class="os-primary-cell"><b>#'.str_pad((string)$r['os_number'],6,'0',STR_PAD_LEFT).'</b></td><td data-label="Abertura">'.os_e(date('d/m/Y H:i',strtotime($r['opened_at']))).'</td><td data-label="Cliente"><b>'.os_e($r['person_name_snapshot']?:'Sem cliente').'</b><div class="small muted">'.os_e($r['person_document_snapshot']).'</div></td><td data-label="Status"><span class="badge" style="background:color-mix(in srgb,'.os_e($rowColor).' 14%,#fff);color:'.os_e($rowColor).';border:1px solid color-mix(in srgb,'.os_e($rowColor).' 35%,#d4dbe4)">'.os_e($r['status_name']?:'Sem status').'</span></td><td data-label="Itens">'.(int)$r['item_count'].'</td><td data-label="Produtos" class="num">'.os_money($r['total_products']).'</td><td data-label="Serviços" class="num">'.os_money($r['total_services']).'</td><td data-label="Total" class="num"><b>'.os_money($r['total_general']).'</b></td><td data-label="Faturamento">'.os_e($r['billing_status']).'</td><td data-label="Ações" class="os-actions-cell"><div class="os-row-actions"><a class="btn btn-outline btn-sm" href="os_editar.php?id='.(int)$r['id'].'">Abrir</a><a class="btn btn-secondary btn-sm" href="os_imprimir.php?id='.(int)$r['id'].'">Imprimir</a>';
 if($r['billing_status']==='PENDENTE' && (float)$r['total_general']>0) echo ' <a class="btn btn-success btn-sm" href="os_faturar.php?id='.(int)$r['id'].'">Faturar</a>';
 if($r['billing_status']==='FATURADA') echo ' <a class="btn btn-outline btn-sm" href="os_faturar.php?id='.(int)$r['id'].'">Fiscal</a>';
 if($r['billing_status']==='ENVIADA_PDV') echo ' <a class="btn btn-danger btn-sm" href="os_faturar.php?id='.(int)$r['id'].'">Regularizar</a>';
 echo '</div></td></tr>';
}
echo '</tbody></table></div></div>';
os_page_end();
