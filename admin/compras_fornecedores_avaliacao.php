<?php
declare(strict_types=1);
/** Nexa Compras 3.6: avaliação operacional de fornecedores, somente leitura. */
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/access_control.php';
$tenant=(int)COMPANY_ID;
if(!access_has_permission($pdo,$tenant,'compras_fornecedores_avaliacao')) { http_response_code(403); exit('Sem permissão para avaliar fornecedores.'); }
$companyId=$tenant;
if(function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)){
 $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$tenant;
 if($companyId!==$tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) {http_response_code(403);exit('Empresa operacional não autorizada.');}
}
function c36h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function c36fmt($v,int $dec=1):string{return number_format((float)$v,$dec,',','.');}
$start=trim((string)($_GET['de']??date('Y-m-01',strtotime('-5 months'))));
$end=trim((string)($_GET['ate']??date('Y-m-d')));
foreach(['start','end'] as $var){$v=$$var;$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);if(!$d||$d->format('Y-m-d')!==$v) $$var=($var==='start'?date('Y-m-01'):date('Y-m-d'));}
if($start>$end){$tmp=$start;$start=$end;$end=$tmp;}
$q=mb_substr(trim((string)($_GET['fornecedor']??'')),0,100);
$rows=[];$error='';$totals=['pedidos'=>0,'valor'=>0,'atrasados'=>0,'divergentes'=>0];
try{
 // O item é a unidade de conciliação. Recebimentos e NF-e são agregados ANTES dos JOINs.
 $sql="SELECT p.id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega,
  COALESCE(i.qtd_pedida,0) qtd_pedida,COALESCE(i.valor,0) valor,
  COALESCE(i.qtd_recebida,0) qtd_recebida,COALESCE(i.qtd_nfe,0) qtd_nfe,
  COALESCE(i.divergencias,0) divergencias,COALESCE(i.itens_parciais,0) itens_parciais,
  i.ultima_entrega
 FROM compras_pedidos p
 LEFT JOIN (
  SELECT x.company_id,x.pedido_id,
   SUM(x.qtd) qtd_pedida,SUM(x.qtd*x.preco_unitario*(1-x.desconto_percentual/100)) valor,
   SUM(x.recebida) qtd_recebida,SUM(x.nfe) qtd_nfe,
   SUM(CASE WHEN ABS(x.recebida-x.nfe)>0.0005 THEN 1 ELSE 0 END) divergencias,
   SUM(CASE WHEN x.recebida>0.0005 AND x.recebida+0.0005<x.qtd THEN 1 ELSE 0 END) itens_parciais,
   MAX(x.ultimo_recebimento) ultima_entrega
  FROM (
   SELECT pi.company_id,pi.pedido_id,pi.quantidade qtd,pi.preco_unitario,pi.desconto_percentual,
    COALESCE(r.recebida,0) recebida,COALESCE(n.nfe,0) nfe,r.ultimo_recebimento
   FROM compras_pedido_itens pi
   LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) recebida,MAX(recebido_em) ultimo_recebimento FROM compras_pedido_recebimentos WHERE company_id=? GROUP BY company_id,pedido_item_id) r
    ON r.company_id=pi.company_id AND r.pedido_item_id=pi.id
   LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) nfe FROM compras_pedido_nfe_itens WHERE company_id=? GROUP BY company_id,pedido_item_id) n
    ON n.company_id=pi.company_id AND n.pedido_item_id=pi.id
   WHERE pi.company_id=?
  ) x GROUP BY x.company_id,x.pedido_id
 ) i ON i.company_id=p.company_id AND i.pedido_id=p.id
 WHERE p.company_id=? AND p.created_at>=? AND p.created_at<?";
 $params=[$companyId,$companyId,$companyId,$companyId,$start.' 00:00:00',(new DateTimeImmutable($end))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
 if($q!==''){$sql.=" AND CONVERT(p.fornecedor_nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';}
 $sql.=' ORDER BY p.id DESC LIMIT 2000';
 $st=$pdo->prepare($sql);$st->execute($params);
 $today=date('Y-m-d');$map=[];
 foreach($st->fetchAll(PDO::FETCH_ASSOC) as $p){
  $supplier=trim((string)$p['fornecedor_nome']);if($supplier==='')$supplier='Não informado';
  // Agrupamento por nome salvo no pedido; não confundir com identificação cadastral inequívoca.
  $key=mb_strtolower($supplier,'UTF-8');
  if(!isset($map[$key]))$map[$key]=['nome'=>$supplier,'pedidos'=>0,'valor'=>0.0,'com_previsao'=>0,'entregues_prazo'=>0,'atrasados'=>0,'entregas_parciais'=>0,'divergentes'=>0,'recebido'=>0.0,'documentado'=>0.0,'ultima'=>null];
  $m=&$map[$key];$m['pedidos']++;$m['valor']+=(float)$p['valor'];
  $m['recebido']+=(float)$p['qtd_recebida'];$m['documentado']+=(float)$p['qtd_nfe'];
  $completo=(float)$p['qtd_pedida']>0 && (float)$p['qtd_recebida']+0.0005 >= (float)$p['qtd_pedida'];
  if($p['status']==='aprovado' && !empty($p['data_prevista_entrega'])){
   $prev=substr((string)$p['data_prevista_entrega'],0,10);$ultima=$p['ultima_entrega']?substr((string)$p['ultima_entrega'],0,10):null;
   // Apenas entregas concluídas possuem resultado definitivo de pontualidade.
   if($completo){$m['com_previsao']++;if($ultima!==null&&$ultima<=$prev)$m['entregues_prazo']++;else $m['atrasados']++;}
   elseif($prev<$today)$m['atrasados']++;
  }
  if((int)$p['itens_parciais']>0)$m['entregas_parciais']++;
  if((int)$p['divergencias']>0)$m['divergentes']++;
  if(!$m['ultima']||$p['created_at']>$m['ultima'])$m['ultima']=$p['created_at'];
  unset($m);
 }
 $rows=array_values($map);
 usort($rows,static fn($a,$b)=>$b['pedidos']<=>$a['pedidos'] ?: strcmp($a['nome'],$b['nome']));
 foreach($rows as $r){$totals['pedidos']+=$r['pedidos'];$totals['valor']+=$r['valor'];$totals['atrasados']+=$r['atrasados'];$totals['divergentes']+=$r['divergentes'];}
}catch(Throwable $e){error_log('[compras_fornecedores_avaliacao_3_6] '.$e->getMessage());$error='Não foi possível gerar a avaliação. Confira as migrations de recebimentos, NF-e e previsão de entrega e consulte o log PHP.';}
require __DIR__.'/_chrome.php';admin_topbar('Avaliação de Fornecedores','compras');
?>
<style>
.c36{width:calc(100% - 28px);margin:14px auto;font:13px system-ui,sans-serif;color:#1b3c69}.c36 h1{font-size:22px;color:#10346d}.c36-card,.c36-kpi{background:white;border:1px solid #dce7f6;border-radius:9px;padding:15px;margin:10px 0}.c36-form{display:flex;align-items:end;gap:10px;flex-wrap:wrap}.c36-form label{display:flex;flex-direction:column;gap:4px}.c36 input{border:1px solid #c2d0e4;border-radius:6px;padding:8px;max-width:100%}.c36 button{border:0;background:#1759b5;color:white;padding:9px 14px;border-radius:6px;cursor:pointer}.c36-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:10px}.c36-kpi strong{display:block;font-size:23px;padding-top:5px}.c36-grid{overflow:auto;max-height:67vh;border:1px solid #dce7f6;border-radius:7px}.c36 table{width:100%;border-collapse:separate;border-spacing:0;min-width:1130px}.c36 th,.c36 td{padding:10px 9px;border-bottom:1px solid #e5ecf6;white-space:nowrap}.c36 th{background:#eaf2fb;color:#183b70;text-align:left;position:sticky;top:0;z-index:2}.c36 .right{text-align:right;font-variant-numeric:tabular-nums}.c36 .muted{color:#62738b}.c36 a{color:#1759b5}@media print{.c36-no-print,nav,aside,header,.sidebar,.topbar{display:none!important}.c36-grid{max-height:none;overflow:visible}.c36{width:100%}}
</style>
<main class="c36">
<h1>Avaliação de Fornecedores</h1><p class="muted">Indicadores administrativos de pedidos, recebimentos e NF-e por empresa operacional. Não geram movimentação de estoque, fiscal ou financeiro.</p>
<form class="c36-card c36-form c36-no-print" method="get"><label>De<input type="date" name="de" value="<?=c36h($start)?>"></label><label>Até<input type="date" name="ate" value="<?=c36h($end)?>"></label><label>Fornecedor<input name="fornecedor" value="<?=c36h($q)?>" placeholder="Nome do fornecedor"></label><button type="submit">Pesquisar</button><a href="compras_fornecedores_avaliacao.php">Limpar</a><a href="compras_painel.php">Painel de Compras</a><a href="#" onclick="window.print();return false">Imprimir</a></form>
<?php if($error):?><div class="c36-card" role="alert"><?=c36h($error)?></div><?php else:?>
<div class="c36-kpis"><div class="c36-kpi">Fornecedores<strong><?=count($rows)?></strong></div><div class="c36-kpi">Pedidos<strong><?=$totals['pedidos']?></strong></div><div class="c36-kpi">Valor total (R$)<strong><?=c36fmt($totals['valor'],2)?></strong></div><div class="c36-kpi">Pedidos com atraso*<strong><?=$totals['atrasados']?></strong></div><div class="c36-kpi">Pedidos com divergência<strong><?=$totals['divergentes']?></strong></div></div>
<section class="c36-card"><h2>Desempenho por fornecedor</h2><div class="c36-grid"><table><thead><tr><th>Fornecedor</th><th class="right">Pedidos</th><th class="right">Total R$</th><th class="right">Concluídos no prazo</th><th class="right">Concluídos com previsão</th><th class="right">Atrasos*</th><th class="right">Com entrega parcial</th><th class="right">Com divergência</th><th class="right">Qtd. recebida</th><th class="right">Qtd. NF-e</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=c36h($r['nome'])?></td><td class="right"><?=$r['pedidos']?></td><td class="right"><?=c36fmt($r['valor'],2)?></td><td class="right"><?=$r['entregues_prazo']?></td><td class="right"><?=$r['com_previsao']?></td><td class="right"><?=$r['atrasados']?></td><td class="right"><?=$r['entregas_parciais']?></td><td class="right"><?=$r['divergentes']?></td><td class="right"><?=c36fmt($r['recebido'],3)?></td><td class="right"><?=c36fmt($r['documentado'],3)?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="10">Nenhum pedido encontrado no período informado.</td></tr><?php endif;?></tbody></table></div>
<p class="muted">* Atraso: pedido aprovado, com previsão vencida e saldo físico pendente, ou entrega concluída depois da previsão. “Concluídos com previsão” considera somente pedidos integralmente recebidos, com data prevista informada. As quantidades entre produtos diferentes não são comparáveis como unidades homogêneas; são exibidas apenas como referência administrativa. Fornecedores são agrupados pelo nome salvo no pedido, não pelo CNPJ. A consulta considera até 2.000 pedidos recentes do período; para volumes maiores, reduza os filtros.</p></section>
<?php endif;?></main>
