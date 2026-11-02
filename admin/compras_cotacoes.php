<?php
declare(strict_types=1);
/** Nexa Compras 1.9 — cotações em rascunho, sem integração fiscal/estoque. */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$companyId=(int)COMPANY_ID;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$companyId)) {
    $companyId=function_exists('multi_company_operational_company_id') ? (int)multi_company_operational_company_id($pdo,'stock_read') : $companyId;
    if ($companyId !== (int)COMPANY_ID && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')) {
        http_response_code(403); exit('Sem permissão para a empresa selecionada.');
    }
}
$userId=function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id']??0);
if (!$userId) { http_response_code(403);exit('Sessão inválida.'); }
if (empty($_SESSION['compras_cotacoes_csrf'])) $_SESSION['compras_cotacoes_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['compras_cotacoes_csrf'];
function cc_h($x):string{return htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');}
function cc_positive_id($x):int{ $id=filter_var($x,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return $id?(int)$id:0; }
function cc_decimal($x,string $label,float $min,float $max):string{
    if (!is_scalar($x) || !preg_match('/^\d{1,12}(?:\.\d{1,4})?$/',(string)$x)) throw new RuntimeException("$label inválido(a). Use ponto decimal.");
    $v=(float)$x;if ($v<$min||$v>$max)throw new RuntimeException("$label fora do limite.");return number_format($v,4,'.','');
}
function cc_tables_exist(PDO $pdo):bool{
    try{$pdo->query('SELECT id FROM compras_cotacoes LIMIT 0');$pdo->query('SELECT id FROM compras_cotacao_ofertas LIMIT 0');return true;}catch(Throwable $e){return false;}
}
$ready=cc_tables_exist($pdo);
if ($_SERVER['REQUEST_METHOD']==='POST'){
    header('Content-Type: application/json; charset=utf-8');
    try{
        if (!$ready)throw new RuntimeException('Aplique a migration Compras 1.9 no Atualizador do Banco.');
        if (!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sessão expirada; atualize a página.');
        $action=(string)($_POST['action']??'');
        if($action==='create'){
            $lista=cc_positive_id($_POST['lista_id']??null);
            $stmt=$pdo->prepare("SELECT id,nome FROM compras_listas WHERE id=? AND company_id=? AND status='rascunho'");$stmt->execute([$lista,$companyId]);$l=$stmt->fetch(PDO::FETCH_ASSOC);
            if(!$l)throw new RuntimeException('Lista indisponível para cotação.');
            $s=$pdo->prepare('SELECT COUNT(*) FROM compras_lista_itens WHERE lista_id=? AND company_id=?');$s->execute([$lista,$companyId]);
            if(!(int)$s->fetchColumn())throw new RuntimeException('A lista precisa conter produtos.');
            $title=mb_substr(trim((string)($_POST['titulo']??'')),0,150);
            if($title==='')$title='Cotação — '.(string)$l['nome'];
            $s=$pdo->prepare("INSERT INTO compras_cotacoes(company_id,lista_id,titulo,status,created_by,created_at,updated_at) VALUES (?,?,?,'rascunho',?,NOW(),NOW())");
            $s->execute([$companyId,$lista,$title,$userId]);
            echo json_encode(['ok'=>true,'id'=>(int)$pdo->lastInsertId()],JSON_UNESCAPED_UNICODE);exit;
        }
        if($action==='save_offer'||$action==='delete_offer'){
            $cot=cc_positive_id($_POST['cotacao_id']??null);$item=cc_positive_id($_POST['lista_item_id']??null);
            // Bloqueia edicoes pelo endpoint legado apos gerar pedidos de compra.
            try { $check=$pdo->prepare('SELECT 1 FROM compras_pedidos WHERE company_id=? AND cotacao_id=? LIMIT 1');$check->execute([$companyId,$cot]);if($check->fetchColumn())throw new RuntimeException('Cotacao bloqueada: pedidos de compra ja gerados.'); }
            catch (PDOException $e) { if (($e->errorInfo[1]??0)!==1146) throw $e; }
            $s=$pdo->prepare("SELECT c.lista_id FROM compras_cotacoes c WHERE c.id=? AND c.company_id=? AND c.status='rascunho'");$s->execute([$cot,$companyId]);$listaId=(int)$s->fetchColumn();
            if(!$listaId)throw new RuntimeException('Cotação indisponível para edição.');
            $s=$pdo->prepare('SELECT id FROM compras_lista_itens WHERE id=? AND lista_id=? AND company_id=?');$s->execute([$item,$listaId,$companyId]);
            if(!$s->fetchColumn())throw new RuntimeException('Item não pertence à lista desta cotação.');
            $supplier=mb_substr(trim((string)($_POST['fornecedor_nome']??'')),0,150);
            if($supplier==='')throw new RuntimeException('Informe o fornecedor.');
            if($action==='delete_offer'){
                $s=$pdo->prepare('DELETE FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND lista_item_id=? AND fornecedor_nome=?');
                $s->execute([$companyId,$cot,$item,$supplier]);
            }else{
                $price=cc_decimal($_POST['preco_unitario']??null,'Preço unitário',0.0001,999999999999.0);
                $discount=cc_decimal($_POST['desconto_percentual']??'0','Desconto',0,100);
                $days=trim((string)($_POST['prazo_dias']??''));
                if($days!=='' && (!ctype_digit($days)||(int)$days>3650))throw new RuntimeException('Prazo inválido.');
                $days=$days===''?null:(int)$days;
                $notes=mb_substr(trim((string)($_POST['observacoes']??'')),0,500);
                $s=$pdo->prepare('INSERT INTO compras_cotacao_ofertas(company_id,cotacao_id,lista_item_id,fornecedor_nome,preco_unitario,desconto_percentual,prazo_dias,observacoes,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE preco_unitario=VALUES(preco_unitario),desconto_percentual=VALUES(desconto_percentual),prazo_dias=VALUES(prazo_dias),observacoes=VALUES(observacoes),updated_at=NOW()');
                $s->execute([$companyId,$cot,$item,$supplier,$price,$discount,$days,$notes]);
            }
            echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
        }
        throw new RuntimeException('Operação desconhecida.');
    }catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
}
if (cc_positive_id($_GET['id']??null)) { header('Location: compras_cotacoes_matriz.php?id='.cc_positive_id($_GET['id'])); exit; }
$listaFilter=cc_positive_id($_GET['lista_id']??null);$selectedId=cc_positive_id($_GET['id']??null);
$lists=[];$quotes=[];$quote=null;$items=[];$offers=[];$error='';
try{
 if($ready){
    $s=$pdo->prepare("SELECT id,nome,status,created_at FROM compras_listas WHERE company_id=? ORDER BY id DESC LIMIT 150");$s->execute([$companyId]);$lists=$s->fetchAll(PDO::FETCH_ASSOC);
    $s=$pdo->prepare('SELECT c.id,c.lista_id,c.titulo,c.status,c.created_at,l.nome lista_nome FROM compras_cotacoes c JOIN compras_listas l ON l.id=c.lista_id AND l.company_id=c.company_id WHERE c.company_id=? ORDER BY c.id DESC LIMIT 150');$s->execute([$companyId]);$quotes=$s->fetchAll(PDO::FETCH_ASSOC);
    if($selectedId){
        $s=$pdo->prepare('SELECT c.id,c.lista_id,c.titulo,c.status,l.nome lista_nome FROM compras_cotacoes c JOIN compras_listas l ON l.id=c.lista_id AND l.company_id=c.company_id WHERE c.id=? AND c.company_id=?');$s->execute([$selectedId,$companyId]);$quote=$s->fetch(PDO::FETCH_ASSOC);
        if(!$quote){http_response_code(404);$error='Cotação não encontrada nesta empresa.';}
        else{
          $s=$pdo->prepare('SELECT i.id,i.produto_id,i.quantidade,p.nome produto_nome FROM compras_lista_itens i JOIN produtos p ON p.id=i.produto_id AND p.company_id=i.company_id WHERE i.lista_id=? AND i.company_id=? ORDER BY p.nome');$s->execute([(int)$quote['lista_id'],$companyId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
          $s=$pdo->prepare('SELECT o.id,o.lista_item_id,o.fornecedor_nome,o.preco_unitario,o.desconto_percentual,o.prazo_dias,o.observacoes FROM compras_cotacao_ofertas o WHERE o.cotacao_id=? AND o.company_id=? ORDER BY o.lista_item_id,o.fornecedor_nome');$s->execute([$selectedId,$companyId]);
          foreach($s->fetchAll(PDO::FETCH_ASSOC) as $offer){$offers[(int)$offer['lista_item_id']][]=$offer;}
        }
    }
 }
}catch(Throwable $e){error_log('[Nexa Compras 1.9] '.$e->getMessage());$error='Não foi possível consultar as cotações. Consulte o log PHP da aplicação.';}
admin_topbar('Cotações de Compras','produtos');
?>
<style>
.cc-wrap{width:calc(100% - 32px);max-width:none;margin:18px auto;color:#223957}.cc-box{background:#fff;border:1px solid #dce6f3;border-radius:10px;padding:15px;margin:12px 0}.cc-wrap h1{color:#09245d;font-size:24px}.cc-wrap h2{font-size:18px;margin:5px 0 12px}.cc-table-wrap{overflow:auto;max-height:65vh;border:1px solid #dce6f3;border-radius:7px}.cc-wrap table{width:100%;border-collapse:separate;border-spacing:0;font-size:13px}.cc-wrap th{position:sticky;top:0;z-index:2;background:#edf4fd;color:#193c76;text-align:left;white-space:nowrap}.cc-wrap th,.cc-wrap td{padding:9px 10px;border-bottom:1px solid #e2e8f0}.cc-wrap tr.best{background:#e9f7ee}.cc-wrap input,.cc-wrap select{border:1px solid #c4d3e8;border-radius:6px;padding:7px;max-width:100%;box-sizing:border-box}.cc-wrap a{color:#1254b5}.cc-btn{background:#1557ba;color:#fff;border:0;padding:9px 13px;border-radius:6px;cursor:pointer}.cc-danger{background:#a53434}.cc-muted{color:#667c98;font-size:13px}.cc-inline{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.cc-inline label{display:flex;align-items:center;gap:5px}.cc-alert{color:#aa2929}.cc-number{text-align:right;white-space:nowrap}
</style>
<div class="cc-wrap"><h1>Cotações de Compras</h1><p class="cc-muted">Propostas por fornecedor e comparação de menor preço líquido por item. Rascunho: não cria pedido, financeiro ou movimentação de estoque.</p><p><a href="compras_listas.php">← Listas de compras</a> · <a href="compras_assistente.php">Assistente de Compras</a></p>
<?php if(!$ready):?><div class="cc-box cc-alert">Estrutura pendente. Aplique Compras 1.9 no Atualizador do Banco.</div><?php elseif($error):?><div class="cc-box cc-alert"><?=cc_h($error)?></div><?php else:?>
<div class="cc-box"><h2>Nova cotação</h2><form id="cc-create" class="cc-inline"><label>Lista <select name="lista_id" required><option value="">Selecione</option><?php foreach($lists as $l):?><option value="<?=(int)$l['id']?>" <?= (int)$l['id']===$listaFilter?'selected':'' ?>>#<?=(int)$l['id']?> — <?=cc_h($l['nome'])?></option><?php endforeach;?></select></label><label>Título <input name="titulo" maxlength="150" placeholder="Cotação de compras"></label><button class="cc-btn">Criar cotação</button></form></div>
<div class="cc-box"><h2>Cotações registradas</h2><div class="cc-table-wrap"><table><thead><tr><th>Nº</th><th>Lista</th><th>Título</th><th>Status</th><th>Data</th><th>Abrir</th></tr></thead><tbody><?php foreach($quotes as $q):?><tr><td><?=(int)$q['id']?></td><td><?=cc_h($q['lista_nome'])?></td><td><?=cc_h($q['titulo'])?></td><td><?=cc_h($q['status'])?></td><td><?=cc_h($q['created_at'])?></td><td><a href="?id=<?=(int)$q['id']?>">Visualizar</a></td></tr><?php endforeach;?><?php if(!$quotes):?><tr><td colspan="6">Nenhuma cotação cadastrada.</td></tr><?php endif;?></tbody></table></div></div>
<?php if($quote):?><div class="cc-box"><h2>Cotação #<?=(int)$quote['id']?> — <?=cc_h($quote['titulo'])?></h2><p class="cc-muted">Lista: <?=cc_h($quote['lista_nome'])?>. Compare o preço líquido (preço menos desconto). Frete, tributos e demais condições ainda não entram na classificação.</p>
<div class="cc-table-wrap"><table><thead><tr><th>Produto</th><th>Qtd.</th><th>Fornecedor</th><th>Unitário</th><th>Desc. %</th><th>Líquido unit.</th><th>Total líquido</th><th>Prazo dias</th><th>Observações</th><th>Ações</th></tr></thead><tbody>
<?php foreach($items as $item): $iid=(int)$item['id'];$qty=(float)$item['quantidade'];$all=$offers[$iid]??[];$minimum=null;foreach($all as $o){$n=(float)$o['preco_unitario']*(1-(float)$o['desconto_percentual']/100);if($minimum===null||$n<$minimum)$minimum=$n;}?>
<tr><td><strong><?=cc_h($item['produto_nome'])?></strong></td><td><?=number_format($qty,3,',','.')?></td><td colspan="8" class="cc-muted">Ofertas registradas para este produto:</td></tr>
<?php foreach($all as $offer): $net=(float)$offer['preco_unitario']*(1-(float)$offer['desconto_percentual']/100);$best=$minimum!==null&&abs($net-$minimum)<0.00001;?>
<tr class="<?=$best?'best':''?>"><td></td><td></td><td><?=cc_h($offer['fornecedor_nome'])?> <?=$best?'✓ Menor preço':''?></td><td class="cc-number">R$ <?=number_format((float)$offer['preco_unitario'],4,',','.')?></td><td class="cc-number"><?=number_format((float)$offer['desconto_percentual'],2,',','.')?></td><td class="cc-number">R$ <?=number_format($net,4,',','.')?></td><td class="cc-number">R$ <?=number_format($net*$qty,2,',','.')?></td><td><?=cc_h($offer['prazo_dias']??'')?></td><td><?=cc_h($offer['observacoes']??'')?></td><td><button type="button" class="cc-btn cc-danger cc-delete" data-item="<?=$iid?>" data-supplier="<?=cc_h($offer['fornecedor_nome'])?>">Remover</button></td></tr>
<?php endforeach;?>
<?php if($quote['status']==='rascunho'):?><tr><td></td><td></td><td colspan="8"><form class="cc-inline cc-offer" data-item="<?=$iid?>"><label>Fornecedor <input name="fornecedor_nome" maxlength="150" required placeholder="Nome do fornecedor" style="width:210px"></label><label>Unitário <input name="preco_unitario" type="number" step="0.0001" min="0.0001" required style="width:110px"></label><label>Desc. % <input name="desconto_percentual" type="number" step="0.001" min="0" max="100" value="0" style="width:80px"></label><label>Prazo (dias) <input name="prazo_dias" type="number" min="0" max="3650" style="width:80px"></label><label>Obs. <input name="observacoes" maxlength="500" style="width:180px"></label><button class="cc-btn">Salvar proposta</button></form></td></tr><?php endif;?>
<?php endforeach;?><?php if(!$items):?><tr><td colspan="10">Lista sem itens.</td></tr><?php endif;?></tbody></table></div></div><?php endif;?>
<?php endif;?></div>
<script>
(()=>{const csrf=<?=json_encode($csrf)?>,quoteId=<?= (int)$selectedId?>;
async function submit(data){data.set('csrf',csrf);let r=await fetch('compras_cotacoes.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data});let j=await r.json();if(!r.ok||!j.ok)throw new Error(j.message||'Falha ao salvar');return j;}
document.querySelector('#cc-create')?.addEventListener('submit',async e=>{e.preventDefault();const b=e.currentTarget.querySelector('button');b.disabled=true;try{const d=new URLSearchParams(new FormData(e.currentTarget));d.set('action','create');const j=await submit(d);location.href='compras_cotacoes_matriz.php?id='+encodeURIComponent(j.id);}catch(err){alert(err.message);b.disabled=false;}});
document.querySelectorAll('.cc-offer').forEach(f=>f.addEventListener('submit',async e=>{e.preventDefault();const b=f.querySelector('button');b.disabled=true;try{const d=new URLSearchParams(new FormData(f));d.set('action','save_offer');d.set('cotacao_id',quoteId);d.set('lista_item_id',f.dataset.item);await submit(d);location.reload();}catch(err){alert(err.message);b.disabled=false;}}));
document.querySelectorAll('.cc-delete').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Excluir esta proposta?'))return;b.disabled=true;try{const d=new URLSearchParams({action:'delete_offer',cotacao_id:String(quoteId),lista_item_id:b.dataset.item,fornecedor_nome:b.dataset.supplier});await submit(d);location.reload();}catch(err){alert(err.message);b.disabled=false;}}));
})();
</script>
<?php admin_footer(); ?>
