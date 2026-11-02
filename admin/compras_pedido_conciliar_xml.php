<?php
declare(strict_types=1);
/** Fase 3.1 — associação manual de itens XML ao pedido; grava apenas vínculo documental confirmado. */
require __DIR__.'/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../config/access_control.php';
$tenant=(int)COMPANY_ID;
if (!access_has_permission($pdo,$tenant,'compras_pedidos')) { http_response_code(403); exit('Sem permissão de consultar pedidos de compra.'); }
$companyId=$tenant;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)) {
    $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$tenant;
    if ($companyId!==$tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) {http_response_code(403);exit('Empresa operacional não autorizada.');}
}
$pedidoId=filter_var($_GET['pedido']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if (!$pedidoId) { http_response_code(400);exit('Informe o pedido.'); }
function pcx_h($s):string{return htmlspecialchars((string)$s,ENT_QUOTES,'UTF-8');}
function pcx_n($s,int $dec=3):string{return number_format((float)$s,$dec,',','.');}
$st=$pdo->prepare('SELECT id,fornecedor_nome,status,created_at FROM compras_pedidos WHERE id=? AND company_id=? LIMIT 1');
$st->execute([$pedidoId,$companyId]);$pedido=$st->fetch(PDO::FETCH_ASSOC);
if (!$pedido) {http_response_code(404);exit('Pedido não encontrado.');}
$st=$pdo->prepare('SELECT pi.id,pi.produto_id,pr.nome,pi.quantidade,pi.preco_unitario,pi.desconto_percentual FROM compras_pedido_itens pi JOIN produtos pr ON pr.id=pi.produto_id AND pr.company_id=pi.company_id WHERE pi.pedido_id=? AND pi.company_id=? ORDER BY pi.id');
$st->execute([$pedidoId,$companyId]);$itens=$st->fetchAll(PDO::FETCH_ASSOC);
if (empty($_SESSION['compras_conciliacao_csrf'])) $_SESSION['compras_conciliacao_csrf']=bin2hex(random_bytes(32));
$csrf=$_SESSION['compras_conciliacao_csrf'];$xmlItens=[];$meta=[];$erro='';$resultado=[];$totalXml=0.0;$manualMap=[];$mappingErrors=[];
$userId=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
$vinculos=[];$vinculoMsg='';$podeVincular=(string)$pedido['status']==='aprovado' && access_has_permission($pdo,$tenant,'compras_pedidos_receber');
try {
 $q=$pdo->prepare('SELECT id,chave_nfe,numero,serie,emitente_nome,emitente_cnpj,vinculado_em,vinculado_por FROM compras_pedido_nfe WHERE company_id=? AND pedido_id=? ORDER BY id DESC');
 $q->execute([$companyId,$pedidoId]);$vinculos=$q->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {$vinculoMsg='Vínculos NF-e indisponíveis. Aplique a migration 3.0 pelo Atualizador de Banco.';}

if ($_SERVER['REQUEST_METHOD']==='POST') {
 try {
    if (!hash_equals((string)$csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Atualize a tela.');
    if (!isset($_FILES['nfe']) || (int)($_FILES['nfe']['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK) throw new RuntimeException('Selecione o arquivo XML da NF-e.');
    $up=$_FILES['nfe'];if ((int)$up['size']>4*1024*1024 || (int)$up['size']<100) throw new RuntimeException('XML fora do limite permitido (4 MB).');
    if (!is_uploaded_file((string)$up['tmp_name'])) throw new RuntimeException('Arquivo inválido.');
    $content=file_get_contents((string)$up['tmp_name']);
    if ($content===false || stripos($content,'<!DOCTYPE')!==false || stripos($content,'<!ENTITY')!==false) throw new RuntimeException('XML inválido ou não permitido.');
    $old=libxml_use_internal_errors(true);
    try { $dom=new DOMDocument();$dom->resolveExternals=false;$dom->substituteEntities=false;if (!$dom->loadXML($content,LIBXML_NONET|LIBXML_NOBLANKS)) throw new RuntimeException('Não foi possível ler o XML da NF-e.'); }
    finally {libxml_clear_errors();libxml_use_internal_errors($old);}
    $xp=new DOMXPath($dom);
    $inf=$xp->query('//*[local-name()="NFe"]/*[local-name()="infNFe"]')->item(0);
    if (!$inf) throw new RuntimeException('Arquivo não contém a estrutura NFe/infNFe.');
    $get=static function(DOMNode $node,string $tag)use($xp):string {return trim((string)$xp->evaluate('string(./*[local-name()="'.$tag.'"])',$node));};
    $ide=$xp->query('./*[local-name()="ide"]',$inf)->item(0);
    $emit=$xp->query('./*[local-name()="emit"]',$inf)->item(0);
    $meta=['numero'=>$ide?$get($ide,'nNF'):'','serie'=>$ide?$get($ide,'serie'):'','emitente'=>$emit?$get($emit,'xNome'):'','cnpj'=>$emit?$get($emit,'CNPJ'):'','chave'=>preg_replace('/^NFe/','',(string)($inf->attributes?->getNamedItem('Id')?->nodeValue??''))];
    $det=$xp->query('./*[local-name()="det"]',$inf);
    foreach ($det as $line) {
       $prod=$xp->query('./*[local-name()="prod"]',$line)->item(0);if (!$prod) continue;
       $q=(float)$get($prod,'qCom');$unit=(float)$get($prod,'vUnCom');$value=(float)$get($prod,'vProd');
       $xmlItens[]=['codigo'=>$get($prod,'cProd'),'ean'=>$get($prod,'cEAN'),'nome'=>$get($prod,'xProd'),'qtd'=>$q,'unit'=>$unit,'total'=>$value];$totalXml+=$value;
    }
    if (!$xmlItens) throw new RuntimeException('NF-e sem itens de produto.');
    // Comparação automática conservadora: somente EAN inequívoco na mesma empresa.
    // A conferência manual deve ser realizada quando não há vínculo inequívoco.
    $produtos=[];$eanCandidates=[];
    $columns=$pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='produtos'")->fetchAll(PDO::FETCH_COLUMN);
    $eanCol=in_array('codigo_barras',$columns,true)?'codigo_barras':(in_array('ean',$columns,true)?'ean':null);
    if ($eanCol) {
       $ids=array_values(array_unique(array_column($itens,'produto_id')));
       if ($ids) {
          $ph=implode(',',array_fill(0,count($ids),'?'));
          $s=$pdo->prepare('SELECT id,`'.$eanCol.'` AS ean FROM produtos WHERE company_id=? AND id IN ('.$ph.')');$s->execute(array_merge([$companyId],$ids));
          foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r) {$ean=trim((string)$r['ean']);if ($ean!=='' && $ean!=='SEM GTIN') $eanCandidates[$ean][]=(int)$r['id'];}
       }
    }
    $matches=[];$ambiguous=[];$matchedXml=[];
    $linking=(($_POST['action']??'')==='vincular');
    $rawMap=is_array($_POST['xml_para_pedido']??null)?$_POST['xml_para_pedido']:[];
    $allowedItems=[];
    foreach ($itens as $pedidoItem) $allowedItems[(int)$pedidoItem['id']]=(int)$pedidoItem['produto_id'];
    foreach ($xmlItens as $k=>$line) {
       $ean=$line['ean'];$ids=$eanCandidates[$ean]??[];
       $autoProduto=(strlen($ean)>=8 && count($ids)===1)?(int)$ids[0]:0;
       $raw=trim((string)($rawMap[$k]??''));
       $itemId=($linking && ctype_digit($raw))?(int)$raw:0;
       if ($linking && $itemId && !isset($allowedItems[$itemId])) throw new RuntimeException('Associação de item XML inválida para este pedido.');
       if (!$linking && $autoProduto) {
           foreach ($itens as $pedidoItem) {
               if ((int)$pedidoItem['produto_id']===$autoProduto) {$itemId=(int)$pedidoItem['id'];break;}
           }
       }
       $manualMap[$k]=$itemId;
       if ($itemId) {$matches[$allowedItems[$itemId]][]=$k;$matchedXml[$k]=true;}
       else $ambiguous[]=$k;
    }
    foreach($itens as $item) {
       $indices=$matches[(int)$item['produto_id']]??[];
       $qty=0.0;$subtotal=0.0;foreach($indices as $idx) {$qty+=$xmlItens[$idx]['qtd'];$subtotal+=$xmlItens[$idx]['total'];}
       $planned=(float)$item['quantidade'];$expected=$planned*(float)$item['preco_unitario']*(1-(float)$item['desconto_percentual']/100);
       $resultado[]=['item'=>$item,'indices'=>$indices,'qtdXml'=>$qty,'totalXml'=>$subtotal,'qtdDif'=>$qty-$planned,'valorDif'=>$subtotal-$expected,'expected'=>$expected];
    }
    // A vinculação é explicitamente confirmada, somente após a conferência conservadora.
    if (($_POST['action']??'')==='vincular') {
       if (!$podeVincular || $userId<1) throw new RuntimeException('Somente usuários com permissão de recebimento podem vincular NF-e de pedidos aprovados.');
       if (empty($_POST['confirmacao'])) throw new RuntimeException('Confirme a conferência antes de vincular.');
       if (!hash_equals((string)($_POST['xml_sha256_conferido']??''),hash('sha256',$content))) throw new RuntimeException('O XML escolhido para vincular não é o mesmo conferido. Refaça a comparação.');
       if (!preg_match('/^[0-9]{44}$/',(string)$meta['chave'])) throw new RuntimeException('Chave de acesso NF-e inválida.');
       if (!$meta['numero'] || !$meta['emitente']) throw new RuntimeException('Identificação de NF-e incompleta.');
       if (count($matchedXml)!==count($xmlItens)) throw new RuntimeException('Associe manualmente todos os itens do XML aos produtos do pedido antes de vincular. Nenhum vínculo foi gravado.');
       $allocation=[];
       foreach ($resultado as $r) {
           $qty=(float)$r['qtdXml'];if ($qty<=0 || $qty > (float)$r['item']['quantidade']+0.0005) throw new RuntimeException('Quantidade do XML excede a quantidade prevista no pedido.');
           if ($qty>0) $allocation[]=['item_id'=>(int)$r['item']['id'],'qty'=>$qty,'total'=>(float)$r['totalXml'],'requested'=>(float)$r['item']['quantidade']];
       }
       if (!$allocation) throw new RuntimeException('XML não contém itens vinculáveis ao pedido.');
       $pdo->beginTransaction();
       try {
           $lk=$pdo->prepare('SELECT status FROM compras_pedidos WHERE id=? AND company_id=? FOR UPDATE');$lk->execute([$pedidoId,$companyId]);
           if ($lk->fetchColumn()!=='aprovado') throw new RuntimeException('O pedido não está mais aprovado.');
           $q=$pdo->prepare('SELECT id FROM compras_pedido_nfe WHERE company_id=? AND chave_nfe=? LIMIT 1');$q->execute([$companyId,$meta['chave']]);
           if ($q->fetchColumn()) throw new RuntimeException('Esta chave de NF-e já está vinculada a um Pedido de Compra nesta empresa.');
           $prior=$pdo->prepare('SELECT COALESCE(SUM(i.quantidade),0) FROM compras_pedido_nfe_itens i JOIN compras_pedido_nfe n ON n.id=i.nfe_vinculo_id AND n.company_id=i.company_id WHERE i.company_id=? AND n.pedido_id=? AND i.pedido_item_id=?');
           foreach($allocation as $a) {$prior->execute([$companyId,$pedidoId,$a['item_id']]);if ((float)$prior->fetchColumn()+$a['qty']>$a['requested']+0.0005) throw new RuntimeException('Saldo do pedido insuficiente para vincular esta NF-e.');}
           $ins=$pdo->prepare('INSERT INTO compras_pedido_nfe (company_id,pedido_id,chave_nfe,numero,serie,emitente_nome,emitente_cnpj,xml_sha256,vinculado_por,observacao) VALUES (?,?,?,?,?,?,?,?,?,?)');
           $ins->execute([$companyId,$pedidoId,$meta['chave'],mb_substr($meta['numero'],0,20),mb_substr($meta['serie'],0,10),mb_substr($meta['emitente'],0,255),$meta['cnpj'],hash('sha256',$content),$userId,mb_substr(trim((string)($_POST['observacao']??'')),0,500)]);
           $id=(int)$pdo->lastInsertId();$insItem=$pdo->prepare('INSERT INTO compras_pedido_nfe_itens (company_id,nfe_vinculo_id,pedido_item_id,quantidade,valor_produtos) VALUES (?,?,?,?,?)');
           foreach($allocation as $a) $insItem->execute([$companyId,$id,$a['item_id'],number_format($a['qty'],3,'.',''),number_format($a['total'],2,'.','')]);
           $pdo->commit();header('Location: compras_pedido_conciliar_xml.php?pedido='.$pedidoId.'&vinculado=1',true,303);exit;
       } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
 } catch(Throwable $e) {$erro=$e instanceof RuntimeException?$e->getMessage():'Não foi possível conferir o XML. Verifique o arquivo ou o log.';error_log('[compras_conciliar_xml] '.$e->getMessage());}
}
require __DIR__.'/_chrome.php';admin_topbar('Conciliação de NF-e — Compras','produtos');
?>
<style>.cx-wrap{margin:16px auto;width:calc(100% - 32px);font:13px system-ui,sans-serif;color:#18365b}.cx-card{background:#fff;border:1px solid #dbe6f5;border-radius:9px;padding:16px;margin:12px 0}.cx-tablewrap{overflow:auto;max-height:65vh;border:1px solid #dce6f3;border-radius:8px}.cx-t{width:100%;border-collapse:separate;border-spacing:0;min-width:840px}.cx-t th,.cx-t td{padding:9px;border-bottom:1px solid #e2e8f0;text-align:left}.cx-t th{position:sticky;top:0;background:#eaf2fc}.cx-t .num{text-align:right;font-variant-numeric:tabular-nums}.cx-btn{background:#1759b5;border:0;border-radius:6px;color:white;padding:9px 14px;cursor:pointer}.cx-alert{background:#fff7e8;border-left:4px solid #ce8822;padding:12px}.cx-link{color:#1759b5}@media print{.cx-hide,nav,aside,header{display:none!important}.cx-tablewrap{max-height:none;overflow:visible}}</style>
<main class="cx-wrap"><h1>Conferir NF-e × Pedido de Compra #<?=(int)$pedidoId?></h1><p><a class="cx-link" href="compras_pedido_conciliacao.php?pedido=<?=(int)$pedidoId?>">Consultar conciliação dos recebimentos com as NF-e vinculadas →</a></p><p class="cx-hide"><a class="cx-link" href="compras_pedidos.php?pedido=<?=(int)$pedidoId?>">← Voltar ao pedido</a></p>
<div class="cx-card"><strong>Fornecedor do pedido:</strong> <?=pcx_h($pedido['fornecedor_nome'])?> · <strong>Situação:</strong> <?=pcx_h($pedido['status'])?><p><strong>Conferência e vínculo documental:</strong> o XML é lido temporariamente e não é gravado, importado ou transmitido. Não registra recebimento e não movimenta estoque, financeiro ou fiscal.</p></div>
<?php if($vinculoMsg):?><div class="cx-card cx-alert"><?=pcx_h($vinculoMsg)?></div><?php endif;?>
<?php if(isset($_GET['vinculado'])):?><div class="cx-card" role="status">NF-e vinculada ao Pedido de Compra com sucesso.</div><?php endif;?>
<div class="cx-card"><h2>NF-e vinculadas ao pedido</h2><div class="cx-tablewrap"><table class="cx-t"><thead><tr><th>Chave NF-e</th><th>Número / Série</th><th>Emitente</th><th>Vinculado em</th><th>Usuário</th></tr></thead><tbody><?php foreach($vinculos as $v):?><tr><td><?=pcx_h($v['chave_nfe'])?></td><td><?=pcx_h($v['numero'].' / '.$v['serie'])?></td><td><?=pcx_h($v['emitente_nome'])?></td><td><?=pcx_h($v['vinculado_em'])?></td><td>#<?=(int)$v['vinculado_por']?></td></tr><?php endforeach;?><?php if(!$vinculos):?><tr><td colspan="5">Nenhuma NF-e vinculada.</td></tr><?php endif;?></tbody></table></div></div>
<div class="cx-card cx-hide"><h2>Selecionar XML da NF-e</h2><form method="post" enctype="multipart/form-data"><input type="hidden" name="csrf" value="<?=pcx_h($csrf)?>"><input type="file" name="nfe" accept=".xml,text/xml,application/xml" required> <button class="cx-btn">Comparar produtos</button></form><p>O vínculo automático é conservador: somente códigos de barras (EAN) inequívocos cadastrados para a empresa. Você poderá associar manualmente cada linha do XML a um produto deste pedido antes de confirmar o vínculo.</p></div>
<?php if($erro):?><div class="cx-card cx-alert" role="alert"><?=pcx_h($erro)?></div><?php endif;?>
<?php if($xmlItens && !$erro):?>
<div class="cx-card"><h2>NF-e nº <?=pcx_h($meta['numero'])?> — Série <?=pcx_h($meta['serie'])?></h2><p><strong>Emitente do XML:</strong> <?=pcx_h($meta['emitente'])?> · <strong>CNPJ:</strong> <?=pcx_h($meta['cnpj'])?></p><p><strong>Chave:</strong> <?=pcx_h($meta['chave'])?></p><p><strong>Atenção:</strong> confira se emitente, unidade comercial, descontos e quantidades correspondem ao pedido. Diferenças de embalagem/unidade podem exigir conversão manual.</p></div>
<div class="cx-card"><h2>Comparativo por produto do pedido</h2><div class="cx-tablewrap"><table class="cx-t"><thead><tr><th>Produto</th><th class="num">Pedido (qtd.)</th><th class="num">NF-e vinculada (qtd.)</th><th class="num">Dif. qtd.</th><th class="num">Valor pedido (R$)</th><th class="num">Valor XML (R$)</th><th class="num">Dif. valor</th><th>Conferência</th></tr></thead><tbody>
<?php foreach($resultado as $row):$item=$row['item'];$found=count($row['indices'])>0;$equalQty=abs($row['qtdDif'])<0.0005;$equalValue=abs($row['valorDif'])<0.015;?><tr><td><?=pcx_h($item['nome'])?> <small>#<?=(int)$item['produto_id']?></small></td><td class="num"><?=pcx_n($item['quantidade'])?></td><td class="num"><?=pcx_n($row['qtdXml'])?></td><td class="num"><?=pcx_n($row['qtdDif'])?></td><td class="num"><?=pcx_n($row['expected'],2)?></td><td class="num"><?=pcx_n($row['totalXml'],2)?></td><td class="num"><?=pcx_n($row['valorDif'],2)?></td><td><?=!$found?'Sem vínculo automático':($equalQty&&$equalValue?'Coincide':'Diferença encontrada')?></td></tr><?php endforeach;?></tbody></table></div></div>
<div class="cx-card"><h2>Itens informados no XML</h2><div class="cx-tablewrap"><table class="cx-t"><thead><tr><th>Linha</th><th>Cód. fornecedor</th><th>EAN</th><th>Descrição da NF-e</th><th class="num">Quantidade</th><th class="num">Unitário</th><th class="num">Total</th><th>Associação</th></tr></thead><tbody><?php foreach($xmlItens as $index=>$item):?><tr><td><?=$index+1?></td><td><?=pcx_h($item['codigo'])?></td><td><?=pcx_h($item['ean'])?></td><td><?=pcx_h($item['nome'])?></td><td class="num"><?=pcx_n($item['qtd'])?></td><td class="num"><?=pcx_n($item['unit'],4)?></td><td class="num"><?=pcx_n($item['total'],2)?></td><td><?=!empty($manualMap[$index])?'Produto identificado':'Conferir manualmente'?></td></tr><?php endforeach;?></tbody></table></div><p><strong>Valor dos produtos no XML:</strong> R$ <?=pcx_n($totalXml,2)?>. Frete, tributos, despesas e descontos globais podem fazer o total da NF-e divergir.</p></div>
<div class="cx-card cx-hide"><h2>Associar itens e vincular esta NF-e ao pedido</h2><p>Escolha, para cada linha da NF-e, o produto correspondente <strong>que já consta neste pedido</strong>. As linhas identificadas por EAN aparecem pré-selecionadas. Confira a unidade e o fator de embalagem: esta versão não converte unidades automaticamente. Se a unidade comercial do XML divergir da unidade pedida, não confirme até regularizar a conferência.</p>
<?php if($podeVincular && preg_match('/^[0-9]{44}$/',(string)($meta['chave']??''))):?>
<form method="post" enctype="multipart/form-data" onsubmit="return confirm('Conferiu TODOS os produtos, quantidades, unidades e fornecedor? Confirmar vínculo documental da NF-e?');">
<input type="hidden" name="csrf" value="<?=pcx_h($csrf)?>"><input type="hidden" name="action" value="vincular"><input type="hidden" name="xml_sha256_conferido" value="<?=pcx_h(hash('sha256',$content))?>">
<div class="cx-tablewrap"><table class="cx-t"><thead><tr><th>Linha XML</th><th>Produto da NF-e</th><th class="num">Qtd. XML</th><th>Associar ao item do Pedido de Compra</th></tr></thead><tbody>
<?php foreach($xmlItens as $index=>$line):?><tr><td><?=$index+1?></td><td><?=pcx_h($line['nome'])?> <small>(EAN <?=pcx_h($line['ean'])?>)</small></td><td class="num"><?=pcx_n($line['qtd'])?></td><td><select name="xml_para_pedido[<?=$index?>]" required style="width:100%;min-width:220px"><option value="">Selecione o produto deste pedido</option><?php foreach($itens as $pedidoItem):?><option value="<?=(int)$pedidoItem['id']?>" <?=((int)($manualMap[$index]??0)===(int)$pedidoItem['id'])?'selected':''?>><?=pcx_h($pedidoItem['nome'])?> — <?=pcx_n($pedidoItem['quantidade'])?> solicitadas</option><?php endforeach;?></select></td></tr><?php endforeach;?></tbody></table></div>
<p><label>Selecione novamente o <strong>mesmo XML</strong> conferido: <input type="file" name="nfe" accept=".xml,text/xml,application/xml" required></label></p>
<p><label>Observação <input type="text" name="observacao" maxlength="500" style="width:400px;max-width:100%"></label></p>
<p><label><input type="checkbox" name="confirmacao" value="1" required> Conferi fornecedor, correspondência de cada item, unidades, quantidades e valores; confirmo o vínculo documental.</label></p>
<button type="submit" class="cx-btn">Vincular NF-e conferida</button>
</form>
<?php else:?><p class="cx-alert">Vinculação indisponível: confira a chave de 44 dígitos, a aprovação do pedido e a permissão de recebimento.</p><?php endif;?></div>
<?php endif;?></main>
