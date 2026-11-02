<?php
declare(strict_types=1);
/** Nexa Compras — Fase 3.2. Conciliação administrativa SOMENTE LEITURA. */
require __DIR__.'/_bootstrap.php';
require_once __DIR__.'/../config/access_control.php';
$tenant=(int)COMPANY_ID;
if (!access_has_permission($pdo,$tenant,'compras_pedidos')) {http_response_code(403);exit('Sem permissão para consultar pedidos de compra.');}
$companyId=$tenant;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$tenant)) {
    $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$tenant;
    if ($companyId!==$tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) {http_response_code(403);exit('Empresa operacional não autorizada.');}
}
$pedidoId=filter_var($_GET['pedido']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])?:0;
if (!$pedidoId) {http_response_code(400);exit('Informe o pedido de compra.');}
function c32_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function c32_n($v):string{return number_format((float)$v,3,',','.');}
try {
    $s=$pdo->prepare('SELECT id,fornecedor_nome,status,created_at FROM compras_pedidos WHERE id=? AND company_id=? LIMIT 1');
    $s->execute([$pedidoId,$companyId]);$pedido=$s->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) {http_response_code(404);exit('Pedido de compra não localizado nesta empresa.');}
    // Subconsultas agregadas evitam multiplicar quantidades ao cruzar várias NF-e e entregas.
    $sql='SELECT i.id,i.produto_id,p.nome,i.quantidade AS solicitada,
      COALESCE(r.recebida,0) AS recebida,COALESCE(n.documentada,0) AS documentada,
      COALESCE(n.valor_documentado,0) AS valor_documentado
      FROM compras_pedido_itens i
      JOIN produtos p ON p.id=i.produto_id AND p.company_id=i.company_id
      LEFT JOIN (SELECT company_id,pedido_id,pedido_item_id,SUM(quantidade) AS recebida
        FROM compras_pedido_recebimentos WHERE company_id=? AND pedido_id=? GROUP BY company_id,pedido_id,pedido_item_id) r
        ON r.company_id=i.company_id AND r.pedido_id=i.pedido_id AND r.pedido_item_id=i.id
      LEFT JOIN (SELECT ni.company_id,n.pedido_id,ni.pedido_item_id,SUM(ni.quantidade) AS documentada,SUM(ni.valor_produtos) AS valor_documentado
        FROM compras_pedido_nfe_itens ni JOIN compras_pedido_nfe n ON n.id=ni.nfe_vinculo_id AND n.company_id=ni.company_id
        WHERE ni.company_id=? AND n.pedido_id=? GROUP BY ni.company_id,n.pedido_id,ni.pedido_item_id) n
        ON n.company_id=i.company_id AND n.pedido_id=i.pedido_id AND n.pedido_item_id=i.id
      WHERE i.company_id=? AND i.pedido_id=? ORDER BY i.id';
    $s=$pdo->prepare($sql);$s->execute([$companyId,$pedidoId,$companyId,$pedidoId,$companyId,$pedidoId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT n.id,n.numero,n.serie,n.chave_nfe,n.emitente_nome,n.vinculado_em,COUNT(i.id) AS itens,COALESCE(SUM(i.quantidade),0) AS qtd_documentada FROM compras_pedido_nfe n LEFT JOIN compras_pedido_nfe_itens i ON i.nfe_vinculo_id=n.id AND i.company_id=n.company_id WHERE n.company_id=? AND n.pedido_id=? GROUP BY n.id,n.numero,n.serie,n.chave_nfe,n.emitente_nome,n.vinculado_em ORDER BY n.id DESC');
    $s->execute([$companyId,$pedidoId]);$notas=$s->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) {
    error_log('[compras_conciliacao_3_2] '.$e->getMessage());
    http_response_code(500);exit('Não foi possível montar a conciliação. Verifique se as migrations 2.8 e 3.0 foram aplicadas e consulte o log PHP.');
}
$tot=['solicitada'=>0.0,'recebida'=>0.0,'documentada'=>0.0,'valor_documentado'=>0.0];$divergentes=0;
foreach($items as &$i){
    foreach($tot as $k=>$_) $tot[$k]+=(float)$i[$k];
    $i['pendente_receber']=round(max(0,(float)$i['solicitada']-(float)$i['recebida']),3);
    $i['pendente_nfe']=round(max(0,(float)$i['solicitada']-(float)$i['documentada']),3);
    $i['dif_fisico_nfe']=round((float)$i['recebida']-(float)$i['documentada'],3);
    if (abs($i['dif_fisico_nfe'])>.0005) {$i['situacao']='Divergência';$divergentes++;}
    elseif ((float)$i['solicitada']<=(float)$i['recebida']+.0005 && (float)$i['solicitada']<=(float)$i['documentada']+.0005) $i['situacao']='Conciliado';
    elseif ((float)$i['recebida']<.0005 && (float)$i['documentada']<.0005) $i['situacao']='Aguardando registros';
    else $i['situacao']='Parcial conciliado';
}
unset($i);
require __DIR__.'/_chrome.php';admin_topbar('Conciliação de Compras','produtos');
?>
<style>
.c32-wrap{margin:16px auto;width:calc(100% - 32px);font:13px system-ui,sans-serif;color:#18365b}
.c32-card{background:#fff;border:1px solid #dbe6f5;border-radius:9px;padding:16px;margin:12px 0}
.c32-table-wrap{max-height:65vh;overflow:auto;border:1px solid #dce6f3;border-radius:8px}
.c32-table{width:100%;border-collapse:separate;border-spacing:0;min-width:990px}
.c32-table th,.c32-table td{padding:10px 9px;border-bottom:1px solid #e2e8f0;white-space:nowrap}
.c32-table th{position:sticky;top:0;background:#eaf2fc;z-index:2;text-align:left}
.c32-table .num{text-align:right;font-variant-numeric:tabular-nums}.c32-table tfoot td{background:#f3f7fd;font-weight:700}
.c32-links{display:flex;gap:14px;flex-wrap:wrap}.c32-links a{color:#1759b5}.c32-warning{color:#985600;font-weight:600}.c32-ok{color:#16713a;font-weight:600}.c32-muted{color:#62768c}
@media print{nav,header,aside,.sidebar,.topbar,.c32-links{display:none!important}.c32-wrap{width:100%}.c32-table-wrap{max-height:none;overflow:visible}.c32-table{min-width:0;font-size:10px}}
</style>
<main class="c32-wrap">
<h1>Conciliação do Pedido de Compra #<?=(int)$pedidoId?></h1>
<p class="c32-links"><a href="compras_pedidos.php?pedido=<?=(int)$pedidoId?>">← Voltar ao pedido</a><a href="compras_pedido_receber.php?pedido=<?=(int)$pedidoId?>">Recebimentos administrativos</a><a href="compras_pedido_conciliar_xml.php?pedido=<?=(int)$pedidoId?>">Conferência e vínculo de NF-e</a><a href="#" onclick="window.print();return false">Imprimir conferência</a></p>
<div class="c32-card"><strong>Fornecedor:</strong> <?=c32_h($pedido['fornecedor_nome'])?> · <strong>Situação:</strong> <?=c32_h($pedido['status'])?><p class="c32-muted">Conferência informativa: compara a quantidade solicitada, os recebimentos administrativos e as quantidades documentadas nas NF-e vinculadas. Não comprova entrada fiscal nem movimentação de estoque. Diferenças de unidades/embalagens exigem verificação manual.</p></div>
<div class="c32-card"><h2>Resumo da conciliação</h2><p><strong>Solicitada:</strong> <?=c32_n($tot['solicitada'])?> · <strong>Recebida:</strong> <?=c32_n($tot['recebida'])?> · <strong>Documentada NF-e:</strong> <?=c32_n($tot['documentada'])?> · <strong>Produtos com diferenças entre recebimento e NF-e:</strong> <?=$divergentes?> de <?=count($items)?></p><p class="c32-muted">Os totais de unidades são apresentados apenas como referência; produtos e unidades de medida diferentes não devem ser somados para conferência física.</p></div>
<div class="c32-card"><h2>Conferência por produto</h2><div class="c32-table-wrap"><table class="c32-table"><thead><tr><th>Produto</th><th class="num">Solicitada</th><th class="num">Recebida</th><th class="num">NF-e vinculada</th><th class="num">Pendente receber</th><th class="num">Pendente NF-e</th><th class="num">Recebida − NF-e</th><th>Situação</th></tr></thead><tbody>
<?php foreach($items as $i):?><tr><td><?=c32_h($i['nome'])?> <small>#<?=(int)$i['produto_id']?></small></td><td class="num"><?=c32_n($i['solicitada'])?></td><td class="num"><?=c32_n($i['recebida'])?></td><td class="num"><?=c32_n($i['documentada'])?></td><td class="num"><?=c32_n($i['pendente_receber'])?></td><td class="num"><?=c32_n($i['pendente_nfe'])?></td><td class="num"><?=c32_n($i['dif_fisico_nfe'])?></td><td class="<?=($i['situacao']==='Divergência'?'c32-warning':($i['situacao']==='Conciliado'?'c32-ok':''))?>"><?=c32_h($i['situacao'])?></td></tr><?php endforeach;?>
<?php if (!$items):?><tr><td colspan="8">Nenhum item cadastrado neste pedido.</td></tr><?php endif;?></tbody></table></div></div>
<div class="c32-card"><h2>NF-e vinculadas</h2><div class="c32-table-wrap"><table class="c32-table"><thead><tr><th>Número / Série</th><th>Emitente</th><th>Chave NF-e</th><th class="num">Itens</th><th>Vinculada em</th></tr></thead><tbody><?php foreach($notas as $n):?><tr><td><?=c32_h($n['numero'].' / '.$n['serie'])?></td><td><?=c32_h($n['emitente_nome'])?></td><td><?=c32_h($n['chave_nfe'])?></td><td class="num"><?=(int)$n['itens']?></td><td><?=c32_h($n['vinculado_em'])?></td></tr><?php endforeach;?><?php if (!$notas):?><tr><td colspan="5">Nenhuma NF-e vinculada a este pedido.</td></tr><?php endif;?></tbody></table></div></div>
</main>
