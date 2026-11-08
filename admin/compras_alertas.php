<?php
declare(strict_types=1);
/** Nexa Compras 3.4 - Alertas gerenciais, somente leitura. */
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/access_control.php';
$tenant = (int) COMPANY_ID;
if (!access_has_permission($pdo, $tenant, 'compras_alertas')) { http_response_code(403); exit('Sem permissão para acessar Alertas de Compras.'); }
$companyId=$tenant;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)) {
  $companyId=function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo,'stock_read') : $tenant;
  if ($companyId !== $tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) { http_response_code(403); exit('Empresa operacional não autorizada.'); }
}
function c33h($v):string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }
function c33n($v):string { return number_format((float)$v,2,',','.'); }
function c33date($v):string { return $v ? date('d/m/Y',strtotime((string)$v)) : '—'; }
$de=(string)($_GET['de']??''); $ate=(string)($_GET['ate']??'');
if ($de!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$de)) $de='';
if ($ate!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/D',$ate)) $ate='';
$fornecedor=mb_substr(trim((string)($_GET['fornecedor']??'')),0,100);
$situacao=(string)($_GET['situacao']??'');
$allowed=['','aguardando','parcial','divergente','atrasado'];
if (!in_array($situacao,$allowed,true)) $situacao='';
$dados=[];$erro='';
try {
  // Cada subconsulta agrega na granularidade do pedido; o JOIN nunca multiplica recebimentos por NF-e.
  $sql="SELECT p.id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega,
    COALESCE(i.itens,0) itens,COALESCE(i.solicitada,0) solicitada,
    COALESCE(i.valor,0) valor,
    COALESCE(r.recebida,0) recebida,COALESCE(n.documentada,0) documentada,
    COALESCE(c.total_itens,0) itens_completos,
    COALESCE(c.divergentes,0) itens_divergentes,
    COALESCE(c.parciais,0) itens_parciais
   FROM compras_pedidos p
   LEFT JOIN (
    SELECT company_id,pedido_id,COUNT(*) itens,SUM(quantidade) solicitada,
     SUM(quantidade*preco_unitario*(1-desconto_percentual/100)) valor
    FROM compras_pedido_itens WHERE company_id=? GROUP BY company_id,pedido_id
   ) i ON i.company_id=p.company_id AND i.pedido_id=p.id
   LEFT JOIN (
    SELECT company_id,pedido_id,SUM(quantidade) recebida
    FROM compras_pedido_recebimentos WHERE company_id=? GROUP BY company_id,pedido_id
   ) r ON r.company_id=p.company_id AND r.pedido_id=p.id
   LEFT JOIN (
    SELECT ni.company_id,n.pedido_id,SUM(ni.quantidade) documentada
    FROM compras_pedido_nfe n JOIN compras_pedido_nfe_itens ni ON ni.nfe_vinculo_id=n.id AND ni.company_id=n.company_id
    WHERE n.company_id=? GROUP BY ni.company_id,n.pedido_id
   ) n ON n.company_id=p.company_id AND n.pedido_id=p.id
   LEFT JOIN (
    SELECT t.company_id,t.pedido_id,
      SUM(CASE WHEN ABS(t.rec-t.nfe)>0.0005 THEN 1 ELSE 0 END) divergentes,
      SUM(CASE WHEN t.rec+0.0005 >=t.qtd AND t.nfe+0.0005 >=t.qtd AND ABS(t.rec-t.nfe)<=0.0005 THEN 1 ELSE 0 END) total_itens,
      SUM(CASE WHEN t.rec>0.0005 OR t.nfe>0.0005 THEN 1 ELSE 0 END) parciais
    FROM (
      SELECT pi.company_id,pi.pedido_id,pi.id,pi.quantidade qtd,
      COALESCE(rx.rec,0) rec,COALESCE(nx.nfe,0) nfe
      FROM compras_pedido_itens pi
      LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) rec FROM compras_pedido_recebimentos WHERE company_id=? GROUP BY company_id,pedido_item_id) rx ON rx.company_id=pi.company_id AND rx.pedido_item_id=pi.id
      LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) nfe FROM compras_pedido_nfe_itens WHERE company_id=? GROUP BY company_id,pedido_item_id) nx ON nx.company_id=pi.company_id AND nx.pedido_item_id=pi.id
      WHERE pi.company_id=?
    ) t GROUP BY t.company_id,t.pedido_id
   ) c ON c.company_id=p.company_id AND c.pedido_id=p.id
   WHERE p.company_id=?";
  $params=array_fill(0,7,$companyId);
  if($de!==''){$sql.=' AND p.created_at >= ?';$params[]=$de.' 00:00:00';}
  if($ate!==''){$sql.=' AND p.created_at < ?';$params[]=date('Y-m-d',strtotime($ate.' +1 day')).' 00:00:00';}
  if($fornecedor!=='') {
    $sql.=' AND CONVERT(p.fornecedor_nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE \'!\'';
    $params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$fornecedor).'%';
  }
  $sql.=' ORDER BY p.id DESC LIMIT 500';
  $st=$pdo->prepare($sql);$st->execute($params);$dados=$st->fetchAll(PDO::FETCH_ASSOC);
  foreach($dados as &$d){
    $q=(int)$d['itens'];$dif=(int)$d['itens_divergentes'];$com=(int)$d['itens_completos'];$par=(int)$d['itens_parciais'];
    if ($d['status']==='rascunho') $d['situacao']='rascunho';
    elseif ($dif>0) $d['situacao']='divergente';
    elseif ($q>0 && $com===$q) $d['situacao']='conciliado';
    elseif ($par>0) $d['situacao']='parcial';
    else $d['situacao']='aguardando';
    $d['atrasado'] = $d['status'] === 'aprovado' && !empty($d['data_prevista_entrega']) && $d['data_prevista_entrega'] < date('Y-m-d') && (float)$d['recebida'] + 0.0005 < (float)$d['solicitada'];
  } unset($d);
  $dados=array_values(array_filter($dados,static function($d) use ($situacao){ if($d['situacao']==='rascunho' || $d['situacao']==='conciliado') return false; return $situacao==='' || ($situacao==='atrasado' ? $d['atrasado'] : $d['situacao']===$situacao); }));
} catch(Throwable $e){error_log('[compras_painel_3_3] '.$e->getMessage());$erro='Não foi possível carregar os alertas. Confirme as migrations 2.8 e 3.0 e consulte os logs do PHP.';}
$counts=array_fill_keys(['rascunho','aguardando','parcial','divergente','conciliado','atrasado'],0);
foreach($dados as $d) { $counts[$d['situacao']]++; if($d['atrasado']) $counts['atrasado']++; }
$labels=['aguardando'=>'Sem recebimento ou NF-e','parcial'=>'Registros parciais','divergente'=>'Quantidade divergente','atrasado'=>'Entrega atrasada'];
require __DIR__.'/_chrome.php';admin_topbar('Alertas de Compras','compras');
?>
<style>
.c33{width:calc(100% - 30px);margin:16px auto;color:#18365b;font:13px system-ui,sans-serif}.c33 h1{font-size:22px;color:#10336d}.c33-card{background:#fff;border:1px solid #dbe5f3;border-radius:9px;padding:15px;margin:12px 0}.c33-row{display:flex;gap:10px;flex-wrap:wrap;align-items:end}.c33-row label{display:flex;flex-direction:column;gap:4px}.c33 input,.c33 select{padding:8px;border:1px solid #becde1;border-radius:6px;max-width:100%}.c33 button,.c33-btn{background:#1758b6;color:#fff;padding:9px 13px;border:0;border-radius:6px;text-decoration:none;cursor:pointer}.c33-kpis{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:10px}.c33-kpi{border:1px solid #dbe5f3;border-radius:9px;background:#fff;padding:16px}.c33-kpi strong{display:block;font-size:24px;margin-top:7px}.c33-scroll{overflow:auto;max-height:68vh;border:1px solid #dbe5f3;border-radius:8px}.c33 table{width:100%;border-collapse:separate;border-spacing:0;min-width:960px}.c33 th,.c33 td{padding:10px;border-bottom:1px solid #e6edf6;white-space:nowrap}.c33 th{background:#eaf2fc;position:sticky;top:0;text-align:left;z-index:2}.c33 .num{text-align:right;font-variant-numeric:tabular-nums}.c33 a{color:#1758b6}.c33-tag{border-radius:20px;padding:4px 8px;background:#edf2f9}.c33-tag.divergente{color:#9c341f;background:#fff0e8}.c33-tag.conciliado{color:#146b3b;background:#e8f7ed}.c33-note{color:#64748b}.c33-empty{text-align:center;padding:20px}@media print{nav,aside,header,.sidebar,.topbar,.c33-hide{display:none!important}.c33-scroll{overflow:visible;max-height:none}.c33{width:100%}}
</style>
<main class="c33"><h1>Alertas de Acompanhamento de Compras</h1>
<div class="c33-row c33-hide"><a href="compras_pedidos.php">← Pedidos de Compra</a><a href="compras_listas.php">Listas de Compras</a><a href="compras_painel.php">Painel de Compras</a><a href="#" onclick="window.print();return false">Imprimir painel</a></div>
<form method="get" class="c33-card c33-row c33-hide"><label>De<input type="date" name="de" value="<?=c33h($de)?>"></label><label>Até<input type="date" name="ate" value="<?=c33h($ate)?>"></label><label>Fornecedor<input name="fornecedor" value="<?=c33h($fornecedor)?>" placeholder="Pesquisar fornecedor"></label><label>Situação<select name="situacao"><option value="">Todas</option><?php foreach($labels as $k=>$v):?><option value="<?=c33h($k)?>" <?=$situacao===$k?'selected':''?>><?=c33h($v)?></option><?php endforeach;?></select></label><button type="submit">Filtrar</button><a href="compras_alertas.php">Limpar</a></form>
<?php if($erro):?><div class="c33-card" role="alert"><?=c33h($erro)?></div><?php else:?>
<style>.c33-kpi{padding:8px 14px!important;border-radius:12px!important;display:flex!important;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;margin:0!important}.c33-kpi strong{display:inline!important;margin:0!important;padding:0!important;font-size:clamp(17px,1.7vw,21px)!important}.c33-kpi span{margin:0!important;font-size:12px}</style><section class="c33-kpis"><?php foreach($labels as $k=>$v):?><div class="c33-kpi"><span><?=c33h($v)?></span><strong><?=(int)$counts[$k]?></strong></div><?php endforeach;?></section>
<section class="c33-card"><h2>Pedidos que exigem acompanhamento</h2><p class="c33-note">Até 500 pedidos mais recentes, classificados por diferença entre recebimento e NF-e; somente leitura. Atraso depende de uma previsão de entrega cadastrada no pedido.</p><?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="c33-scroll"><table<?=nexa_grid_auto_attrs($pdo,'auto_compras_alertas')?>><thead><tr><th>Pedido</th><th>Emissão</th><th>Previsão</th><th>Dias desde emissão</th><th>Fornecedor</th><th class="num">Itens</th><th class="num">Valor R$</th><th class="num">Qtd. recebida</th><th class="num">Qtd. NF-e</th><th>Situação</th><th>Ações</th></tr></thead><tbody>
<?php foreach($dados as $d):?><tr><td><a href="compras_pedidos.php?pedido=<?=(int)$d['id']?>">#<?=(int)$d['id']?></a></td><td><?=c33date($d['created_at'])?></td><td><?=c33date($d['data_prevista_entrega'])?><?= $d['atrasado'] ? ' · ATRASADO' : '' ?></td><td><?=max(0,(int)floor((time()-strtotime((string)$d['created_at']))/86400))?></td><td><?=c33h($d['fornecedor_nome'])?></td><td class="num"><?=(int)$d['itens']?></td><td class="num"><?=c33n($d['valor'])?></td><td class="num"><?=number_format((float)$d['recebida'],3,',','.')?></td><td class="num"><?=number_format((float)$d['documentada'],3,',','.')?></td><td><span class="c33-tag <?=c33h($d['situacao'])?>"><?=c33h($labels[$d['situacao']])?></span></td><td><a href="compras_pedido_conciliacao.php?pedido=<?=(int)$d['id']?>">Conciliar</a> · <a href="compras_pedido_receber.php?pedido=<?=(int)$d['id']?>">Recebimentos</a></td></tr><?php endforeach;?>
<?php if(!$dados):?><tr><td colspan="11" class="c33-empty">Nenhum pedido encontrado para os filtros informados.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?></main>
