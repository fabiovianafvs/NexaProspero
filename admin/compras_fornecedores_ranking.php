<?php
declare(strict_types=1);
/** Nexa Compras 3.7: ranking administrativo; sem efeitos operacionais. */
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/access_control.php';
$tenant=(int)COMPANY_ID;
if(!access_has_permission($pdo,$tenant,'compras_fornecedores_avaliacao')) {http_response_code(403);exit('Sem permissão de avaliação de fornecedores.');}
$companyId=$tenant;
if(function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)) {
 $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$tenant;
 if($companyId!==$tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) {http_response_code(403);exit('Empresa operacional não autorizada.');}
}
function cr37h($x):string{return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function cr37n($x,int $d=1):string{return number_format((float)$x,$d,',','.');}
function cr37validDate(string $v,string $fallback):string{$d=DateTimeImmutable::createFromFormat('!Y-m-d',$v);return $d&&$d->format('Y-m-d')===$v?$v:$fallback;}
$from=cr37validDate((string)($_GET['de']??date('Y-m-01',strtotime('-5 months'))),date('Y-m-01'));
$to=cr37validDate((string)($_GET['ate']??date('Y-m-d')),date('Y-m-d'));
if($from>$to){[$from,$to]=[$to,$from];}
$filter=mb_substr(trim((string)($_GET['fornecedor']??'')),0,100);
$weights=['pontualidade'=>50,'divergencias'=>30,'parciais'=>20];
$error='';$notice='';$data=[];$limit=2000;
if(empty($_SESSION['compras_ranking_csrf']))$_SESSION['compras_ranking_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['compras_ranking_csrf'];
try {
 $st=$pdo->prepare('SELECT peso_pontualidade,peso_divergencias,peso_parciais FROM compras_ranking_config WHERE company_id=?');$st->execute([$companyId]);
 if($row=$st->fetch(PDO::FETCH_ASSOC)){$weights=['pontualidade'=>(int)$row['peso_pontualidade'],'divergencias'=>(int)$row['peso_divergencias'],'parciais'=>(int)$row['peso_parciais']];}
 if($_SERVER['REQUEST_METHOD']==='POST') {
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada. Atualize a página.');
  if(!access_has_permission($pdo,$tenant,'compras_fornecedores_ranking_config')){http_response_code(403);throw new RuntimeException('Sem permissão para configurar os pesos.');}
  $new=[];foreach(['pontualidade','divergencias','parciais'] as $key){$v=filter_var($_POST['peso_'.$key]??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>100]]);if($v===false)throw new RuntimeException('Pesos devem estar entre 0 e 100.');$new[$key]=$v;}
  if(array_sum($new)!==100)throw new RuntimeException('A soma dos três pesos deve ser exatamente 100%.');
  $st=$pdo->prepare('INSERT INTO compras_ranking_config(company_id,peso_pontualidade,peso_divergencias,peso_parciais) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE peso_pontualidade=VALUES(peso_pontualidade),peso_divergencias=VALUES(peso_divergencias),peso_parciais=VALUES(peso_parciais)');
  $st->execute([$companyId,$new['pontualidade'],$new['divergencias'],$new['parciais']]);$weights=$new;$notice='Pesos atualizados para a empresa operacional.';
 }
 $sql="SELECT p.id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega,
 COALESCE(i.qtd_pedida,0) qtd_pedida,COALESCE(i.qtd_recebida,0) qtd_recebida,
 COALESCE(i.divergencias,0) divergencias,COALESCE(i.parciais,0) parciais,i.ultima_entrega
 FROM compras_pedidos p
 LEFT JOIN (SELECT x.company_id,x.pedido_id,SUM(x.qtd) qtd_pedida,SUM(x.recebida) qtd_recebida,
 SUM(CASE WHEN ABS(x.recebida-x.nfe)>0.0005 THEN 1 ELSE 0 END) divergencias,
 SUM(CASE WHEN x.recebida>0.0005 AND x.recebida+0.0005<x.qtd THEN 1 ELSE 0 END) parciais,
 MAX(x.ultimo_recebimento) ultima_entrega
 FROM (SELECT pi.company_id,pi.pedido_id,pi.quantidade qtd,
 COALESCE(r.recebida,0) recebida,COALESCE(n.nfe,0) nfe,r.ultimo_recebimento
 FROM compras_pedido_itens pi
 LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) recebida,MAX(recebido_em) ultimo_recebimento FROM compras_pedido_recebimentos WHERE company_id=? GROUP BY company_id,pedido_item_id) r ON r.company_id=pi.company_id AND r.pedido_item_id=pi.id
 LEFT JOIN (SELECT company_id,pedido_item_id,SUM(quantidade) nfe FROM compras_pedido_nfe_itens WHERE company_id=? GROUP BY company_id,pedido_item_id) n ON n.company_id=pi.company_id AND n.pedido_item_id=pi.id
 WHERE pi.company_id=?) x GROUP BY x.company_id,x.pedido_id) i ON i.company_id=p.company_id AND i.pedido_id=p.id
 WHERE p.company_id=? AND p.created_at>=? AND p.created_at<?";
 $params=[$companyId,$companyId,$companyId,$companyId,$from.' 00:00:00',(new DateTimeImmutable($to))->modify('+1 day')->format('Y-m-d').' 00:00:00'];
 if($filter!==''){$sql.=" AND CONVERT(p.fornecedor_nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE '!'";$params[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$filter).'%';}
 $sql.=' ORDER BY p.id DESC LIMIT '.$limit;
 $st=$pdo->prepare($sql);$st->execute($params);
 $map=[];$today=date('Y-m-d');$sample=0;
 foreach($st->fetchAll(PDO::FETCH_ASSOC) as $p){$sample++;
  $supplier=trim((string)$p['fornecedor_nome']);if($supplier==='')$supplier='Não informado';$key=mb_strtolower($supplier,'UTF-8');
  if(!isset($map[$key]))$map[$key]=['nome'=>$supplier,'pedidos'=>0,'avaliados'=>0,'pontuais'=>0,'atrasados'=>0,'divergentes'=>0,'parciais'=>0];
  $r=&$map[$key];$r['pedidos']++;
  if($p['status']!=='aprovado'){unset($r);continue;}
  $r['avaliados']++;
  if((int)$p['divergencias']>0)$r['divergentes']++;
  if((int)$p['parciais']>0)$r['parciais']++;
  $full=(float)$p['qtd_pedida']>0 && (float)$p['qtd_recebida']+0.0005>=(float)$p['qtd_pedida'];
  if(!empty($p['data_prevista_entrega'])){
   $prev=substr((string)$p['data_prevista_entrega'],0,10);$last=$p['ultima_entrega']?substr((string)$p['ultima_entrega'],0,10):null;
   if($full){if($last!==null && $last<=$prev)$r['pontuais']++;else $r['atrasados']++;}
   elseif($prev<$today)$r['atrasados']++;
  }
  unset($r);
 }
 foreach($map as &$r){
  $known=$r['pontuais']+$r['atrasados'];
  $r['taxa_prazo']=$known?100*$r['pontuais']/$known:null;
  $r['taxa_div']=$r['avaliados']?100*(1-$r['divergentes']/$r['avaliados']):null;
  $r['taxa_par']=$r['avaliados']?100*(1-$r['parciais']/$r['avaliados']):null;
  $values=['pontualidade'=>$r['taxa_prazo'],'divergencias'=>$r['taxa_div'],'parciais'=>$r['taxa_par']];$sum=0.0;$weightSum=0;
  foreach($values as $metric=>$val){if($val!==null && $weights[$metric]>0){$sum+=$val*$weights[$metric];$weightSum+=$weights[$metric];}}
  $r['score']=$weightSum>0?$sum/$weightSum:null;$r['base_prazo']=$known;
  $r['confianca']=$r['avaliados']>=3 && $known>=3?'Amostra mínima':'Amostra limitada';
 }
 unset($r);$data=array_values($map);
 usort($data,static fn($a,$b)=>($b['score']??-1)<=>($a['score']??-1) ?: $b['avaliados']<=>$a['avaliados'] ?: strcmp($a['nome'],$b['nome']));
}catch(Throwable $e){error_log('[compras_ranking_3_7] '.$e->getMessage());$error=$e->getMessage()==='Sessão expirada. Atualize a página.'||str_starts_with($e->getMessage(),'Pesos devem')||str_starts_with($e->getMessage(),'A soma')||str_starts_with($e->getMessage(),'Sem permissão')?$e->getMessage():'Não foi possível carregar o ranking. Confira a migration 3.7 e as fases anteriores.';}
require __DIR__.'/_chrome.php';admin_topbar('Ranking de Fornecedores','compras');
?>
<style>
.cr37{width:calc(100% - 26px);margin:15px auto;font:13px system-ui,sans-serif;color:#1a3a68}.cr37 h1{font-size:22px}.cr37-card{background:#fff;border:1px solid #dbe6f4;border-radius:9px;padding:15px;margin:10px 0}.cr37-fields{display:flex;align-items:end;flex-wrap:wrap;gap:12px}.cr37 label{display:grid;gap:4px}.cr37 input{border:1px solid #bfcee2;border-radius:6px;padding:9px}.cr37 input[type=number]{width:100px}.cr37 button{background:#1558b4;color:white;border:0;border-radius:7px;padding:10px 15px;cursor:pointer}.cr37 a{color:#1558b4}.cr37-scroll{overflow:auto;max-height:67vh}.cr37 table{border-collapse:separate;border-spacing:0;width:100%;min-width:1000px}.cr37 td,.cr37 th{padding:10px;border-bottom:1px solid #e3eaf3;text-align:left}.cr37 th{position:sticky;top:0;background:#e9f1fc;z-index:2}.cr37 td.num,.cr37 th.num{text-align:right;font-variant-numeric:tabular-nums}.cr37-muted{color:#64748b}.cr37-ok{color:#166534}.cr37-warn{color:#995d07}@media print{.cr37-no-print,nav,aside,header,.sidebar,.topbar{display:none!important}.cr37-scroll{max-height:none;overflow:visible}}
</style>
<main class="cr37">
<h1>Ranking de Fornecedores</h1><p class="cr37-muted">Pontuação administrativa de 0 a 100, por empresa operacional. Não determina automaticamente escolha, pagamento ou bloqueio de fornecedores.</p>
<?php if($error):?><div class="cr37-card" role="alert"><?=cr37h($error)?></div><?php endif;?>
<?php if($notice):?><div class="cr37-card cr37-ok"><?=cr37h($notice)?></div><?php endif;?>
<form method="get" class="cr37-card cr37-fields cr37-no-print"><label>De<input type="date" name="de" value="<?=cr37h($from)?>"></label><label>Até<input type="date" name="ate" value="<?=cr37h($to)?>"></label><label>Fornecedor<input name="fornecedor" value="<?=cr37h($filter)?>" placeholder="Nome ou parte"></label><button type="submit">Pesquisar</button><a href="compras_fornecedores_avaliacao.php">Avaliação detalhada</a><a href="#" onclick="window.print();return false">Imprimir</a></form>
<form method="post" class="cr37-card cr37-fields cr37-no-print"><input type="hidden" name="csrf" value="<?=cr37h($csrf)?>"><strong>Pesos da empresa</strong>
<label>Pontualidade (%)<input type="number" min="0" max="100" name="peso_pontualidade" value="<?=$weights['pontualidade']?>" <?=!access_has_permission($pdo,$tenant,'compras_fornecedores_ranking_config')?'disabled':''?>></label>
<label>Sem divergência (%)<input type="number" min="0" max="100" name="peso_divergencias" value="<?=$weights['divergencias']?>" <?=!access_has_permission($pdo,$tenant,'compras_fornecedores_ranking_config')?'disabled':''?>></label>
<label>Sem entregas parciais (%)<input type="number" min="0" max="100" name="peso_parciais" value="<?=$weights['parciais']?>" <?=!access_has_permission($pdo,$tenant,'compras_fornecedores_ranking_config')?'disabled':''?>></label>
<?php if(access_has_permission($pdo,$tenant,'compras_fornecedores_ranking_config')):?><button type="submit">Salvar pesos</button><?php endif;?>
<span class="cr37-muted">Soma obrigatória: 100%. Salvos por empresa.</span></form>
<?php if(!$error):?><section class="cr37-card"><h2>Classificação no período</h2><div class="cr37-scroll"><table><thead><tr><th>Posição</th><th>Fornecedor</th><th class="num">Nota</th><th class="num">Pedidos aprovados</th><th class="num">Pontualidade*</th><th class="num">Sem divergência</th><th class="num">Sem parciais</th><th>Confiabilidade</th></tr></thead><tbody>
<?php foreach($data as $i=>$r):?><tr><td><?=$i+1?></td><td><?=cr37h($r['nome'])?></td><td class="num"><strong><?=$r['score']===null?'—':cr37n($r['score'])?></strong></td><td class="num"><?=$r['avaliados']?></td><td class="num"><?=$r['taxa_prazo']===null?'N/D':cr37n($r['taxa_prazo']).'%'?></td><td class="num"><?=$r['taxa_div']===null?'N/D':cr37n($r['taxa_div']).'%'?></td><td class="num"><?=$r['taxa_par']===null?'N/D':cr37n($r['taxa_par']).'%'?></td><td class="<?=$r['confianca']==='Amostra limitada'?'cr37-warn':'cr37-ok'?>"><?=cr37h($r['confianca'])?></td></tr><?php endforeach;?>
<?php if(!$data):?><tr><td colspan="8">Nenhum fornecedor encontrado no período.</td></tr><?php endif;?></tbody></table></div>
<p class="cr37-muted">* Pontualidade considera somente entregas concluídas (no prazo ou atrasadas) e pedidos ainda pendentes com previsão vencida. Pedidos sem prazo e ainda em aberto não são classificados. Indicadores sem amostra são omitidos e os pesos disponíveis são renormalizados. “Sem parciais” mede itens parcialmente recebidos no estado atual, não o histórico de múltiplas entregas. A classificação agrupa fornecedores pelo nome do pedido, não pelo CNPJ. A consulta considera no máximo 2.000 pedidos recentes; use período menor se necessário. Classificação com menos de três pedidos aprovados ou três observações de prazo é indicativa.</p></section><?php endif;?></main>
