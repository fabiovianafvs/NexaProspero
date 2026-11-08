<?php
declare(strict_types=1);
/** Nexa Compras 3.8 — histórico de preços confirmados em pedidos de compra. Só leitura. */
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/access_control.php';
$tenant=(int)COMPANY_ID;
if(!access_has_permission($pdo,$tenant,'compras_historico_precos')){http_response_code(403);exit('Sem permissão para Histórico de Preços.');}
$companyId=$tenant;
if(function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)){
 $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$tenant;
 if($companyId!==$tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')){http_response_code(403);exit('Empresa operacional não autorizada.');}
}
function ch38(string|int|float|null $v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cn38(float $v,int $dec=4):string{return number_format($v,$dec,',','.');}
function cd38(string $v,string $fallback):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:$fallback;}
$from=cd38((string)($_GET['de']??date('Y-m-01',strtotime('-11 months'))),date('Y-m-01'));
$to=cd38((string)($_GET['ate']??date('Y-m-d')),date('Y-m-d'));
if($from>$to){[$from,$to]=[$to,$from];}
$product=mb_substr(trim((string)($_GET['produto']??'')),0,100);
$supplier=mb_substr(trim((string)($_GET['fornecedor']??'')),0,100);
$onlyProduct=filter_var($_GET['produto_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: null;
$rows=[];$summary=[];$error='';$limit=1500;$truncated=false;
try {
 $sql='SELECT p.id pedido_id,p.created_at,p.fornecedor_nome,p.status,pi.produto_id,pr.nome produto_nome,pi.quantidade,pi.preco_unitario,pi.desconto_percentual, (pi.preco_unitario*(1-pi.desconto_percentual/100)) preco_liquido FROM compras_pedidos p JOIN compras_pedido_itens pi ON pi.pedido_id=p.id AND pi.company_id=p.company_id JOIN produtos pr ON pr.id=pi.produto_id AND pr.company_id=pi.company_id WHERE p.company_id=? AND p.created_at>=? AND p.created_at<? AND p.status=?';
 $args=[$companyId,$from.' 00:00:00',(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00','aprovado'];
 if($onlyProduct!==null){$sql.=' AND pi.produto_id=?';$args[]=$onlyProduct;}
 if($product!==''){$sql.=" AND CONVERT(pr.nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$product).'%';}
 if($supplier!==''){$sql.=" AND CONVERT(p.fornecedor_nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE '!'";$args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$supplier).'%';}
 $sql.=' ORDER BY p.created_at DESC,p.id DESC,pi.id DESC LIMIT '.($limit+1);
 $st=$pdo->prepare($sql);$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC);
 $truncated=count($rows)>$limit;if($truncated)array_pop($rows);
 foreach($rows as &$r){
  $r['preco_liquido']=(float)$r['preco_liquido'];$r['quantidade']=(float)$r['quantidade'];
  $key=(int)$r['produto_id'].'|'.mb_strtolower(trim((string)$r['fornecedor_nome']),'UTF-8');
  if(!isset($summary[$key]))$summary[$key]=['produto_id'=>(int)$r['produto_id'],'produto'=>(string)$r['produto_nome'],'fornecedor'=>(string)$r['fornecedor_nome'],'amostras'=>0,'min'=>INF,'max'=>-INF,'soma'=>0.0,'quantidade'=>0.0,'ultimo'=>null,'anterior'=>null,'data'=>''];
  $s=&$summary[$key];$n=$r['preco_liquido'];$s['amostras']++;$s['min']=min($s['min'],$n);$s['max']=max($s['max'],$n);$s['soma']+=$n*$r['quantidade'];$s['quantidade']+=$r['quantidade'];
  if($s['ultimo']===null){$s['ultimo']=$n;$s['data']=$r['created_at'];}
  elseif($s['anterior']===null){$s['anterior']=$n;}
  unset($s);
 }
 unset($r);
 $summary=array_values($summary);
 usort($summary,static fn($a,$b)=>strcmp($a['produto'],$b['produto']) ?: ($a['ultimo']<=>$b['ultimo']) ?: strcmp($a['fornecedor'],$b['fornecedor']));
}catch(Throwable $e){error_log('[compras_historico_precos_3_8] '.$e->getMessage());$error='Não foi possível consultar o histórico. Verifique o log PHP e as permissões do módulo.';}
require __DIR__.'/_chrome.php';admin_topbar('Histórico de Preços','compras');
?>
<style>
.ch38{width:calc(100% - 26px);margin:14px auto;color:#193b68;font:13px system-ui,sans-serif}.ch38 h1{font-size:23px;color:#10336d}.ch38 h2{font-size:17px}.ch38-card{background:white;border:1px solid #dbe5f3;border-radius:9px;padding:14px;margin:12px 0}.ch38-filter{display:flex;align-items:end;gap:12px;flex-wrap:wrap}.ch38 label{display:flex;flex-direction:column;gap:5px}.ch38 input{padding:8px;border:1px solid #bfcede;border-radius:6px;max-width:100%}.ch38 button{background:#1759b5;border:0;border-radius:6px;color:white;padding:9px 15px;cursor:pointer}.ch38 a{color:#1759b5}.ch38-scroll{max-height:60vh;overflow:auto;border:1px solid #dce6f2;border-radius:7px}.ch38 table{border-collapse:separate;border-spacing:0;width:100%;min-width:900px}.ch38 th,.ch38 td{border-bottom:1px solid #e5edf6;padding:9px 10px;white-space:nowrap}.ch38 th{position:sticky;top:0;background:#eaf2fc;color:#173d75;z-index:2;text-align:left}.ch38 .num{text-align:right;font-variant-numeric:tabular-nums}.ch38 .muted{color:#667b92}.ch38 .bad{color:#a3382c}.ch38 .good{color:#1b8150}.ch38 .note{padding:9px;background:#f3f7ff;border-radius:7px}@media print{.ch38-no-print,nav,aside,header,.sidebar,.topbar{display:none!important}.ch38-scroll{max-height:none;overflow:visible}.ch38{width:100%}}
</style>
<main class="ch38">
<h1>Histórico e Comparativo de Preços</h1>

<form class="ch38-card ch38-filter ch38-no-print" method="get">
<label>De<input type="date" name="de" value="<?=ch38($from)?>"></label><label>Até<input type="date" name="ate" value="<?=ch38($to)?>"></label>
<label>Produto<input type="search" name="produto" placeholder="Descrição do produto" value="<?=ch38($product)?>"></label>
<label>Fornecedor<input type="search" name="fornecedor" placeholder="Nome do fornecedor" value="<?=ch38($supplier)?>"></label>
<label>ID do produto<input type="number" name="produto_id" min="1" value="<?=$onlyProduct? (int)$onlyProduct : ''?>" style="width:100px"></label>
<button type="submit">Pesquisar</button><a href="compras_historico_precos.php">Limpar</a><a href="#" onclick="window.print();return false">Imprimir</a>
</form>
<?php if($error):?><div class="ch38-card" role="alert"><?=ch38($error)?></div><?php else:?>
<div class="ch38-card note"><?=count($rows)?> itens de pedidos encontrados · <?=count($summary)?> combinações de produto/fornecedor. <?php if($truncated):?><strong class="bad">Limite de <?=$limit?> itens atingido: refine o período para evitar resultados parciais.</strong><?php endif;?></div>
<section class="ch38-card"><h2>Comparativo por produto e fornecedor</h2><?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="ch38-scroll"><table<?=nexa_grid_auto_attrs($pdo,'auto_compras_precos_resumo')?>><thead><tr><th>Produto</th><th>Fornecedor</th><th class="num">Compras</th><th class="num">Último preço R$</th><th class="num">Preço anterior R$</th><th class="num">Variação</th><th class="num">Menor R$</th><th class="num">Maior R$</th><th class="num">Média ponderada R$</th></tr></thead><tbody>
<?php foreach($summary as $s):$delta=$s['anterior']!==null&&$s['anterior']>0?100*($s['ultimo']-$s['anterior'])/$s['anterior']:null;?><tr><td><?=ch38($s['produto'])?> <span class="muted">#<?=$s['produto_id']?></span></td><td><?=ch38($s['fornecedor'])?></td><td class="num"><?=$s['amostras']?></td><td class="num"><?=cn38($s['ultimo'])?></td><td class="num"><?=$s['anterior']!==null?cn38($s['anterior']):'—'?></td><td class="num <?=$delta!==null?($delta>0.00001?'bad':($delta<-.00001?'good':'')):''?>"><?=$delta!==null?cn38($delta,2).'%':'—'?></td><td class="num"><?=cn38($s['min'])?></td><td class="num"><?=cn38($s['max'])?></td><td class="num"><?=$s['quantidade']>0?cn38($s['soma']/$s['quantidade']):'—'?></td></tr><?php endforeach;?>
<?php if(!$summary):?><tr><td colspan="9">Nenhuma compra aprovada encontrada com estes filtros.</td></tr><?php endif;?></tbody></table></div><p class="muted">A média ponderada utiliza a quantidade comprada. A variação compara as duas ocorrências mais recentes para o mesmo produto e fornecedor. Os pedidos em rascunho não entram nos indicadores.</p></section>
<section class="ch38-card"><h2>Histórico de compras</h2><div class="ch38-scroll"><table<?=nexa_grid_auto_attrs($pdo,'auto_compras_precos_hist')?>><thead><tr><th>Data</th><th>Pedido</th><th>Produto</th><th>Fornecedor</th><th class="num">Quantidade</th><th class="num">Preço unitário R$</th><th class="num">Desconto %</th><th class="num">Preço líquido R$</th></tr></thead><tbody>
<?php foreach($rows as $r):?><tr><td><?=ch38(date('d/m/Y',strtotime((string)$r['created_at'])))?></td><td><a href="compras_pedidos.php?pedido=<?=(int)$r['pedido_id']?>">#<?=(int)$r['pedido_id']?></a></td><td><?=ch38($r['produto_nome'])?> <span class="muted">#<?=(int)$r['produto_id']?></span></td><td><?=ch38($r['fornecedor_nome'])?></td><td class="num"><?=cn38($r['quantidade'],3)?></td><td class="num"><?=cn38((float)$r['preco_unitario'])?></td><td class="num"><?=cn38((float)$r['desconto_percentual'],2)?></td><td class="num"><?=cn38($r['preco_liquido'])?></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="8">Nenhum registro encontrado.</td></tr><?php endif;?></tbody></table></div></section>
<p class="muted">Documento gerencial de compras. Não inclui frete, tributos nem conversão de unidades. Fornecedores são agrupados pelo nome gravado no pedido. Nenhuma movimentação de estoque, financeiro ou fiscal é realizada.</p>
<?php endif;?></main>
