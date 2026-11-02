<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/commercial_rules.php';

$cid=(int)COMPANY_ID;
commercial_ensure_schema($pdo);
$from=trim((string)($_GET['from'] ?? date('Y-m-01')));
$to=trim((string)($_GET['to'] ?? date('Y-m-d')));
$sellerId=(int)($_GET['seller_id'] ?? 0);
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$from))$from=date('Y-m-01');
if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$to))$to=date('Y-m-d');
$sellers=finance_people_options($pdo,$cid,'seller');
$where=["p.company_id=?","DATE(COALESCE(p.created_at,NOW())) BETWEEN ? AND ?","LOWER(COALESCE(p.status,'')) = 'finalizado'","COALESCE(p.seller_id,0)>0"];
$params=[$cid,$from,$to];
if($sellerId>0){$where[]='p.seller_id=?';$params[]=$sellerId;}
$rows=[];$totals=['orders'=>0,'sales'=>0.0,'base'=>0.0,'commission'=>0.0];
try{
  $sql="SELECT p.seller_id, fp.name seller_name, COUNT(*) orders_count, COALESCE(SUM(p.total),0) sales_total, COALESCE(SUM(p.seller_commission_base),0) commission_base, COALESCE(SUM(p.seller_commission_amount),0) commission_amount, MIN(p.seller_commission_percent) min_pct, MAX(p.seller_commission_percent) max_pct FROM pedidos p LEFT JOIN finance_people fp ON fp.company_id=p.company_id AND fp.id=p.seller_id WHERE ".implode(' AND ',$where)." GROUP BY p.seller_id,fp.name ORDER BY commission_amount DESC,sales_total DESC,seller_name ASC";
  $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
  foreach($rows as $r){$totals['orders']+=(int)$r['orders_count'];$totals['sales']+=(float)$r['sales_total'];$totals['base']+=(float)$r['commission_base'];$totals['commission']+=(float)$r['commission_amount'];}
}catch(Throwable $e){$err=$e->getMessage();}

admin_topbar('Comissão de vendedores','relatorio_comissao_vendedores');
function rcv_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function rcv_money($v):string{return 'R$ '.number_format((float)$v,2,',','.');}
?>
<style>
.rcv-filter{display:grid;grid-template-columns:170px 170px minmax(220px,1fr) auto;gap:10px;align-items:end}.rcv-cards{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin:14px 0}.rcv-card{border:1px solid #dbe4f0;background:#fff;border-radius:18px;padding:14px}.rcv-card span{display:block;color:#64748b;font-size:12px;font-weight:850}.rcv-card strong{display:block;color:#0b1f5b;font-size:22px;margin-top:4px}.rcv-table{width:100%;border-collapse:collapse;min-width:850px}.rcv-table th,.rcv-table td{padding:10px;border-bottom:1px solid #e5edf6}.rcv-table th{background:#f8fbff;text-align:left}.rcv-num{text-align:right}.rcv-muted{color:#64748b}@media(max-width:900px){.rcv-filter,.rcv-cards{grid-template-columns:1fr}.rcv-table{min-width:780px}}
</style>
<div class="mg-card">
  <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap"><div><h1 style="margin:0;color:var(--admin-primary)">Comissão de vendedores</h1><p class="rcv-muted" style="margin:5px 0 0">O relatório usa a comissão gravada na própria venda. Alterar o percentual atual do vendedor não muda vendas antigas.</p></div></div>
  <?php if(!empty($err)): ?><div class="msg" style="margin-top:12px;background:#fef2f2;color:#b91c1c;border:1px solid #fecaca"><?=rcv_h($err)?></div><?php endif; ?>
  <form method="get" class="rcv-filter" style="margin-top:14px">
    <label>De<input type="date" name="from" value="<?=rcv_h($from)?>"></label>
    <label>Até<input type="date" name="to" value="<?=rcv_h($to)?>"></label>
    <label>Vendedor<select name="seller_id"><option value="">Todos</option><?php foreach($sellers as $s): ?><option value="<?=(int)$s['id']?>" <?= $sellerId===(int)$s['id']?'selected':'' ?>><?=rcv_h($s['label'] ?? $s['name'] ?? '')?></option><?php endforeach; ?></select></label>
    <div style="display:flex;gap:8px"><button class="btn btn-primary">Filtrar</button><a class="btn" href="relatorio_comissao_vendedores.php">Limpar</a></div>
  </form>
</div>
<div class="rcv-cards">
  <div class="rcv-card"><span>Vendas</span><strong><?=number_format((int)$totals['orders'],0,',','.')?></strong></div>
  <div class="rcv-card"><span>Total vendido</span><strong><?=rcv_money($totals['sales'])?></strong></div>
  <div class="rcv-card"><span>Base de comissão</span><strong><?=rcv_money($totals['base'])?></strong></div>
  <div class="rcv-card"><span>Comissão</span><strong><?=rcv_money($totals['commission'])?></strong></div>
</div>
<div class="mg-card"><div class="table-wrap"><table class="rcv-table"><thead><tr><th>Vendedor</th><th class="rcv-num">Vendas</th><th class="rcv-num">Total vendido</th><th class="rcv-num">Base</th><th class="rcv-num">Percentual</th><th class="rcv-num">Comissão</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="6" class="rcv-muted">Nenhuma venda com vendedor no período.</td></tr><?php endif; ?>
<?php foreach($rows as $r): $min=$r['min_pct'];$max=$r['max_pct'];$pctLabel=($min===null&&$max===null)?'—':(abs((float)$min-(float)$max)<0.0001?number_format((float)$min,2,',','.').'%':number_format((float)$min,2,',','.').'% a '.number_format((float)$max,2,',','.').'%'); ?>
<tr><td><strong><?=rcv_h($r['seller_name'] ?: ('Vendedor #'.(int)$r['seller_id']))?></strong></td><td class="rcv-num"><?=(int)$r['orders_count']?></td><td class="rcv-num"><?=rcv_money($r['sales_total'])?></td><td class="rcv-num"><?=rcv_money($r['commission_base'])?></td><td class="rcv-num"><?=rcv_h($pctLabel)?></td><td class="rcv-num"><strong><?=rcv_money($r['commission_amount'])?></strong></td></tr>
<?php endforeach; ?></tbody></table></div></div>
<?php admin_footer(); ?>
