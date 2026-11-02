<?php
declare(strict_types=1);
/** Nexa Compras 1.8 — listas em rascunho, isoladas do fiscal, financeiro e estoque. */
require __DIR__ . '/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo, (int)COMPANY_ID)) {
    $companyId = function_exists('multi_company_operational_company_id')
        ? (int) multi_company_operational_company_id($pdo, 'stock_read') : (int) COMPANY_ID;
    if ($companyId !== (int) COMPANY_ID && function_exists('multi_company_context_permission_allowed')
        && !multi_company_context_permission_allowed($pdo, 'produtos')) {
        http_response_code(403); exit('Sem permissão para a empresa selecionada.');
    }
} else $companyId=(int) COMPANY_ID;
$userId=function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id']??0);
if (!$userId) { http_response_code(403); exit('Sessão inválida.'); }
if (empty($_SESSION['compras_listas_csrf'])) $_SESSION['compras_listas_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['compras_listas_csrf'];
function cl_h($v): string {return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cl_ready(PDO $pdo): bool {
    try {$pdo->query('SELECT id FROM compras_listas LIMIT 0');$pdo->query('SELECT id FROM compras_lista_itens LIMIT 0');return true;}
    catch(Throwable $e){return false;}
}
$ready=cl_ready($pdo);
if (($_GET['lookup']??'')==='products') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        $q=trim((string)($_GET['q']??''));
        if (mb_strlen($q)<1) { echo json_encode(['ok'=>true,'items'=>[]]); exit; }
        $q=mb_substr($q,0,100);
        // A pesquisa é sempre limitada à empresa operacional da sessão.
        $checkCol=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
        $hasCol=static function(string $tbl,string $col)use($checkCol):bool {$checkCol->execute([$tbl,$col]);return (bool)$checkCol->fetchColumn();};
        $codeParts=[]; $join='';
        if($hasCol('produtos','codigo_firebird'))$codeParts[]="NULLIF(TRIM(p.codigo_firebird),'')";
        if($hasCol('produtos_firebird_map','codigo_firebird')&&$hasCol('produtos_firebird_map','company_id')&&$hasCol('produtos_firebird_map','produto_id')) {
            $join=' LEFT JOIN produtos_firebird_map pm ON pm.produto_id=p.id AND pm.company_id=p.company_id ';
            $codeParts[]="NULLIF(TRIM(pm.codigo_firebird),'')";
        }
        if($hasCol('produtos','numero_cadastro'))$codeParts[]='CAST(p.numero_cadastro AS CHAR)';
        $codeParts[]='CAST(p.id AS CHAR)';
        // Normalize cada campo ANTES de COALESCE para evitar mistura de collations
        // (ex.: codigo_firebird utf8mb4_bin e nome utf8mb4_general_ci).
        $codeParts=array_map(static function(string $expr): string {
            return "(CONVERT(($expr) USING utf8mb4) COLLATE utf8mb4_general_ci)";
        },$codeParts);
        $codeExpr='COALESCE('.implode(',',$codeParts).')';
        $nameExpr='CONVERT(p.nome USING utf8mb4) COLLATE utf8mb4_general_ci';
        $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],$q).'%';
        // COLLATE explícito somente na consulta; nenhuma alteração de banco.
        $sql="SELECT p.id,p.nome,$codeExpr AS codigo FROM produtos p $join WHERE p.company_id=? AND ($nameExpr LIKE ? ESCAPE '!' OR $codeExpr LIKE ? ESCAPE '!') ORDER BY p.nome,p.id LIMIT 60";
        $st=$pdo->prepare($sql);
        $st->execute([$companyId,$like,$like]);
        echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
    } catch(Throwable $e) {
        error_log('[compras_lista_editar][lookup_products] '.$e->getMessage());
        http_response_code(500);
        echo json_encode(['ok'=>false,'message'=>'Não foi possível consultar os produtos. Verifique o log PHP do Nexa.'],JSON_UNESCAPED_UNICODE);
    }
    exit;
}
if ($_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        if (!$ready) throw new RuntimeException('A migration de Compras 1.8 ainda não foi aplicada.');
        if (!hash_equals($csrf,(string)($_POST['csrf']??''))) throw new RuntimeException('Sessão expirada. Atualize a página.');
        $action=(string)($_POST['action']??'');
        if ($action==='create') {
            $name=mb_substr(trim((string)($_POST['nome']??'Lista de compras')),0,120);
            if ($name==='') $name='Lista de compras';
            $raw=json_decode((string)($_POST['itens']??'[]'),true);
            if (!is_array($raw)||!count($raw)||count($raw)>1000) throw new RuntimeException('Selecione entre 1 e 1.000 itens.');
            $items=[];
            foreach ($raw as $it) {
                if (!is_array($it)) throw new RuntimeException('Item inválido.');
                $pid=filter_var($it['produto_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                $q=$it['quantidade']??null;
                if (!$pid || !is_scalar($q) || !preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',(string)$q)
                  || (float)$q<=0 || (float)$q>999999999) throw new RuntimeException('Produto ou quantidade inválida.');
                if (isset($items[(int)$pid])) throw new RuntimeException('Há produtos repetidos na seleção.');
                $items[(int)$pid]=number_format((float)$q,3,'.','');
            }
            $ids=array_keys($items);$placeholders=implode(',',array_fill(0,count($ids),'?'));
            // Verifica TODOS os produtos na empresa operacional; nomes/códigos enviados pelo navegador nunca são confiáveis.
            $s=$pdo->prepare("SELECT id FROM produtos WHERE company_id=? AND id IN ($placeholders)");
            $s->execute(array_merge([$companyId],$ids));
            if (count($s->fetchAll(PDO::FETCH_COLUMN))!==count($ids)) throw new RuntimeException('Produto não encontrado nesta empresa.');
            $pdo->beginTransaction();
            try {
                $s=$pdo->prepare("INSERT INTO compras_listas (company_id,nome,status,created_by,created_at,updated_at) VALUES (?,?,'rascunho',?,NOW(),NOW())");
                $s->execute([$companyId,$name,$userId]);$listId=(int)$pdo->lastInsertId();
                $s=$pdo->prepare('INSERT INTO compras_lista_itens (lista_id,company_id,produto_id,quantidade,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
                foreach ($items as $pid=>$q) $s->execute([$listId,$companyId,$pid,$q]);
                $pdo->commit();
            } catch(Throwable $e) {if ($pdo->inTransaction())$pdo->rollBack();throw $e;}
            echo json_encode(['ok'=>true,'id'=>$listId],JSON_UNESCAPED_UNICODE);exit;
        }
        if ($action==='ensure_quote') {
            $listId=filter_var($_POST['lista_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$listId) throw new RuntimeException('Lista inválida.');
            $pdo->beginTransaction();
            try {
                $s=$pdo->prepare('SELECT id,nome,status FROM compras_listas WHERE id=? AND company_id=? FOR UPDATE');
                $s->execute([$listId,$companyId]);$list=$s->fetch(PDO::FETCH_ASSOC);
                if (!$list) throw new RuntimeException('Lista não pertence à empresa selecionada.');
                $s=$pdo->prepare('SELECT id FROM compras_cotacoes WHERE lista_id=? AND company_id=? ORDER BY id DESC LIMIT 1');
                $s->execute([$listId,$companyId]);$quoteId=(int)$s->fetchColumn();
                if (!$quoteId) {
                    if ((string)$list['status'] !== 'rascunho') throw new RuntimeException('A lista não está disponível para iniciar cotação.');
                    $s=$pdo->prepare('SELECT COUNT(*) FROM compras_lista_itens WHERE lista_id=? AND company_id=?');
                    $s->execute([$listId,$companyId]);
                    if (!(int)$s->fetchColumn()) throw new RuntimeException('Inclua produtos antes de cotar.');
                    $title=mb_substr('Cotação — '.(string)$list['nome'],0,150);
                    $s=$pdo->prepare("INSERT INTO compras_cotacoes(company_id,lista_id,titulo,status,created_by,created_at,updated_at) VALUES (?,?,?,'rascunho',?,NOW(),NOW())");
                    $s->execute([$companyId,$listId,$title,$userId]);$quoteId=(int)$pdo->lastInsertId();
                }
                $pdo->commit();
            } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            echo json_encode(['ok'=>true,'id'=>$quoteId],JSON_UNESCAPED_UNICODE);exit;
        }
        if ($action==='add_item') {
            $listId=filter_var($_POST['lista_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $pid=filter_var($_POST['produto_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $q=trim((string)($_POST['quantidade']??''));
            if(!$listId||!$pid||!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$q)||(float)$q<=0)throw new RuntimeException('Produto ou quantidade inválida.');
            $pdo->beginTransaction();
            try {
                $c=$pdo->prepare("SELECT id FROM compras_listas WHERE id=? AND company_id=? AND status='rascunho' FOR UPDATE");
                $c->execute([$listId,$companyId]);if(!$c->fetchColumn())throw new RuntimeException('Lista indisponível para inclusão.');
                $c=$pdo->prepare('SELECT id FROM produtos WHERE id=? AND company_id=?');$c->execute([$pid,$companyId]);
                if(!$c->fetchColumn())throw new RuntimeException('Produto não encontrado na empresa.');
                $c=$pdo->prepare('SELECT id FROM compras_lista_itens WHERE lista_id=? AND company_id=? AND produto_id=?');
                $c->execute([$listId,$companyId,$pid]);if($c->fetchColumn())throw new RuntimeException('Produto já está nesta lista. Edite sua quantidade.');
                $c=$pdo->prepare('SELECT COUNT(*) FROM compras_pedidos WHERE company_id=? AND cotacao_id IN (SELECT id FROM compras_cotacoes WHERE company_id=? AND lista_id=?)');
                $c->execute([$companyId,$companyId,$listId]);if((int)$c->fetchColumn())throw new RuntimeException('A lista já gerou pedidos; não é possível incluir produtos.');
                $c=$pdo->prepare('INSERT INTO compras_lista_itens(lista_id,company_id,produto_id,quantidade,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW())');
                $c->execute([$listId,$companyId,$pid,number_format((float)$q,3,'.','')]);
                $pdo->commit();
            }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
            echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
        }
        if ($action==='update_quantity' || $action==='remove_item') {
            $listId=filter_var($_POST['lista_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            $pid=filter_var($_POST['produto_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if (!$listId||!$pid) throw new RuntimeException('Identificação inválida.');
            $s=$pdo->prepare("SELECT id FROM compras_listas WHERE id=? AND company_id=? AND status='rascunho'");$s->execute([$listId,$companyId]);
            if (!$s->fetchColumn()) throw new RuntimeException('Lista indisponível para edição.');
            if ($action==='remove_item') {
                $s=$pdo->prepare('DELETE FROM compras_lista_itens WHERE lista_id=? AND company_id=? AND produto_id=?');$s->execute([$listId,$companyId,$pid]);
            } else {
                $q=(string)($_POST['quantidade']??'');
                if (!preg_match('/^\d{1,9}(?:\.\d{1,3})?$/',$q)||(float)$q<=0)throw new RuntimeException('Quantidade inválida.');
                $s=$pdo->prepare('UPDATE compras_lista_itens SET quantidade=?,updated_at=NOW() WHERE lista_id=? AND company_id=? AND produto_id=?');
                $s->execute([number_format((float)$q,3,'.',''),$listId,$companyId,$pid]);
            }
            echo json_encode(['ok'=>true]);exit;
        }
        throw new RuntimeException('Operação não permitida.');
    } catch(Throwable $e) {http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
}
$lists=[];$items=[];$selectedId=max(0,(int)($_GET['id']??0));
if (!$selectedId && $_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: compras_listas.php', true, 302); exit; }
if ($ready) {
    if ($selectedId) {
        $check=$pdo->prepare('SELECT id FROM compras_listas WHERE id=? AND company_id=?');
        $check->execute([$selectedId,$companyId]);
        if (!$check->fetchColumn()) {http_response_code(404);exit('Lista não encontrada nesta empresa.');}
        // A estrutura legada de produtos varia entre empresas/instalações.
        // Resolver código por empresa somente usando colunas comprovadamente existentes.
        $hasColumn = static function (string $table, string $column) use ($pdo): bool {
            $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
            $q->execute([$table,$column]);
            return (bool)$q->fetchColumn();
        };
        $hasProductCode=$hasColumn('produtos','codigo_firebird');
        $hasRegistration=$hasColumn('produtos','numero_cadastro');
        $hasMap=$hasColumn('produtos_firebird_map','codigo_firebird')
            && $hasColumn('produtos_firebird_map','produto_id')
            && $hasColumn('produtos_firebird_map','company_id');
        $mapJoin=$hasMap?' LEFT JOIN produtos_firebird_map m ON m.company_id=p.company_id AND m.produto_id=p.id ':'';
        $codeParts=[];
        if ($hasProductCode) $codeParts[]="NULLIF(TRIM(p.codigo_firebird),'')";
        if ($hasMap) $codeParts[]="NULLIF(TRIM(m.codigo_firebird),'')";
        if ($hasRegistration) $codeParts[]='CAST(p.numero_cadastro AS CHAR)';
        $codeParts[]='CAST(p.id AS CHAR)';
        $codeExpr='COALESCE('.implode(',',$codeParts).')';
        $s=$pdo->prepare("SELECT i.produto_id,i.quantidade,p.nome,$codeExpr codigo_empresa FROM compras_lista_itens i JOIN compras_listas l ON l.id=i.lista_id AND l.company_id=i.company_id JOIN produtos p ON p.id=i.produto_id AND p.company_id=i.company_id $mapJoin WHERE l.id=? AND l.company_id=? ORDER BY p.nome");
        $s->execute([$selectedId,$companyId]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
    }
}
$quoteId=0;$quoteReady=false;
if ($selectedId && $ready) {
    try {
        $pdo->query('SELECT id FROM compras_cotacoes LIMIT 0');
        $pdo->query('SELECT id FROM compras_cotacao_fornecedores LIMIT 0');
        $s=$pdo->prepare('SELECT id FROM compras_cotacoes WHERE lista_id=? AND company_id=? ORDER BY id DESC LIMIT 1');
        $s->execute([$selectedId,$companyId]);$quoteId=(int)$s->fetchColumn();$quoteReady=true;
    }catch(Throwable $e){$quoteReady=false;}
}
require __DIR__ . '/_chrome.php';
admin_topbar('Lista de Compras','produtos');
?>
<style>
.cl-wrap{font-family:inherit;font-size:inherit;margin:18px auto;width:calc(100% - 32px);max-width:none;color:#1d355b}.cl-wrap h1{color:#09245d}.cl-box{background:#fff;border:1px solid #dce6f3;border-radius:10px;padding:16px;margin:14px 0}.cl-wrap table{border-collapse:collapse;width:100%;font-size:13px}.cl-wrap th{background:#edf4fd;color:#193c76;text-align:left}.cl-wrap td,.cl-wrap th{padding:10px;border-bottom:1px solid #e2e8f0}.cl-wrap a{color:#1254b5}.cl-btn{border:0;background:#1557ba;color:white;border-radius:7px;padding:9px 14px;cursor:pointer}.cl-wrap input[type=number]{max-width:130px;padding:7px;border:1px solid #c4d3e8;border-radius:6px}.cl-steps{display:flex;gap:6px;flex-wrap:wrap;margin:12px 0}.cl-step{border:1px solid #c8d7eb;border-radius:7px;background:#f0f5fc;padding:10px 14px;color:#174278;cursor:pointer;font-weight:600}.cl-step[aria-selected=true]{background:#1557ba;color:white}.cl-view[hidden]{display:none}.cl-embed{display:block;width:100%;min-height:70vh;height:76vh;border:0;background:white;border-radius:8px}.cl-status{padding:12px;color:#516681}.cl-products-header{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.cl-products-header h2{margin:0 0 10px}.cl-modal[hidden]{display:none}.cl-modal{position:fixed;inset:0;z-index:20000;background:rgba(15,33,61,.55);display:flex;align-items:center;justify-content:center;padding:24px}.cl-modal-card{background:white;width:min(880px,100%);max-height:85vh;display:flex;flex-direction:column;border-radius:12px;padding:20px;box-shadow:0 18px 45px #10294b55;font-family:inherit}.cl-modal-title,.cl-modal-controls{display:flex;gap:10px;align-items:center;justify-content:space-between}.cl-modal-title h2{margin:0;color:#09245d}.cl-modal-x{background:none;border:0;font-size:28px;cursor:pointer;color:#385473}.cl-modal-controls{justify-content:flex-start;margin-top:14px;flex-wrap:wrap}.cl-modal-controls input{flex:1;min-width:220px;padding:10px;border:1px solid #b9c9df;border-radius:7px;font:inherit}.cl-btn-alt{background:#e5edf8!important;color:#174278!important;text-decoration:none}.cl-modal-help{font-size:12px;color:#526985;margin-top:12px}.cl-modal-message{font-size:13px;margin:12px 0 6px;color:#365579}.cl-modal-results{overflow-y:auto;min-height:100px;border:1px solid #cfdaea;border-radius:7px}.cl-modal-option{display:block;width:100%;text-align:left;border:0;border-bottom:1px solid #e6ebf3;background:white;color:#203e69;padding:11px;font:inherit;cursor:pointer}.cl-modal-option:hover,.cl-modal-option.is-selected{background:#deebfb}.cl-modal-active{overflow:hidden}.cl-modal{overscroll-behavior:contain}.cl-modal-results{overscroll-behavior:contain;user-select:none;-webkit-user-select:none}.cl-modal-option{user-select:none;-webkit-user-select:none;touch-action:pan-y}.cl-modal-card{overscroll-behavior:contain}
</style>
<div class="cl-wrap"><h1>Lista de Compras #<?= $selectedId ?></h1><p>Gerencie os produtos, fornecedores, propostas, comparativo e pedidos nesta lista.</p>
<p><a href="compras_listas.php">← Voltar às Listas de Compras</a> &nbsp;·&nbsp; <a href="compras_assistente.php">Assistente de Compras</a></p>
<?php if(!$ready):?><div class="cl-box" role="alert">A estrutura do banco ainda não foi atualizada. Solicite ao administrador da plataforma a aplicação da migration Compras 1.8 no Atualizador do Banco.</div><?php else:?>
<?php if($selectedId):?>
<div class="cl-box"><h2>Lista de Compras #<?= $selectedId ?></h2><p>Conclua todo o processo nesta lista: confira produtos, escolha fornecedores, registre propostas, compare preços e gere pedidos em rascunho.</p>
<div class="cl-steps" role="tablist" aria-label="Etapas da compra">
<button class="cl-step" data-step="products" aria-selected="true">1. Produtos</button>
<button class="cl-step" data-step="suppliers" aria-selected="false">2. Fornecedores</button>
<button class="cl-step" data-step="proposals" aria-selected="false">3. Propostas</button>
<button class="cl-step" data-step="compare" aria-selected="false">4. Comparativo</button>
<button class="cl-step" data-step="orders" aria-selected="false">5. Pedidos</button>
</div></div>
<div class="cl-view" data-step="products"><div class="cl-box"><div class="cl-products-header"><h2>Produtos da lista #<?= $selectedId?></h2><button type="button" class="cl-btn" id="cl-add-open" aria-haspopup="dialog" aria-controls="cl-product-modal">Buscar F8</button></div><table><thead><tr><th>Produto</th><th>Descrição</th><th>Quantidade</th><th>Ações</th></tr></thead><tbody><?php foreach($items as $it):?><tr data-produto="<?= (int)$it['produto_id']?>"><td><?=cl_h($it['codigo_empresa']??$it['produto_id'])?></td><td><?=cl_h($it['nome'])?></td><td><input type="number" min="0.001" step="0.001" value="<?=cl_h($it['quantidade'])?>" class="cl-qty" aria-label="Quantidade de <?=cl_h($it['nome'])?>"></td><td><button type="button" class="cl-btn cl-save">Salvar</button> <button type="button" class="cl-btn cl-remove">Remover</button></td></tr><?php endforeach;?><?php if(!$items):?><tr><td colspan="4">Lista sem itens.</td></tr><?php endif;?></tbody></table></div></div>
<div id="cl-product-modal" class="cl-modal" role="dialog" aria-modal="true" aria-labelledby="cl-modal-title" hidden>
  <div class="cl-modal-card">
    <div class="cl-modal-title"><h2 id="cl-modal-title">Buscar produto</h2><button type="button" id="cl-modal-close" class="cl-modal-x" aria-label="Fechar">×</button></div>
    <div class="cl-modal-controls"><input type="search" id="cl-product-search" placeholder="Código ou descrição — Enter para pesquisar" autocomplete="off" aria-label="Pesquisar produtos"><button type="button" class="cl-btn" id="cl-modal-search">Pesquisar</button><a class="cl-btn cl-btn-alt" href="produto_form.php" target="_blank" rel="noopener">Cadastrar</a></div>
    <div class="cl-modal-help">Digite e pressione Enter. Use ↑/↓ para navegar e Enter para incluir. A quantidade será editada no grid.</div>
    <div id="cl-modal-message" class="cl-modal-message" role="status" aria-live="polite">Digite o código ou a descrição e pressione Enter.</div>
    <div id="cl-product-results" class="cl-modal-results" role="listbox" aria-label="Produtos encontrados"></div>
  </div>
</div>
<div class="cl-view" data-step="quote" hidden>
<?php if(!$quoteReady):?><div class="cl-box" role="alert">Aplique as migrations Compras 1.9 e 2.0 no Atualizador do Banco antes de continuar.</div>
<?php else:?>
<div id="cl-quote-loading" class="cl-box cl-status">Preparando cotação desta lista...</div>
<iframe id="cl-quote-frame" class="cl-embed" title="Cotação integrada à lista" data-existing="<?= (int)$quoteId ?>" data-list="<?= (int)$selectedId ?>" loading="lazy" hidden></iframe>
<?php endif; ?>
</div>
<?php endif;?>
<?php endif;?></div>
<script>
(()=>{const csrf=<?=json_encode($csrf)?>;document.querySelectorAll('tr[data-produto]').forEach(tr=>{async function send(action){const body=new URLSearchParams({action,csrf,lista_id:<?= (int)$selectedId ?>,produto_id:tr.dataset.produto,quantidade:tr.querySelector('.cl-qty').value});const r=await fetch('compras_lista_editar.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body});const d=await r.json();if(!r.ok||!d.ok)throw Error(d.message||'Falha ao salvar');location.reload()}tr.querySelector('.cl-save').onclick=()=>send('update_quantity').catch(e=>alert(e.message));tr.querySelector('.cl-remove').onclick=()=>{if(confirm('Remover este item?'))send('remove_item').catch(e=>alert(e.message))}});
const modal=document.getElementById('cl-product-modal'),open=document.getElementById('cl-add-open'),search=document.getElementById('cl-product-search'),results=document.getElementById('cl-product-results'),message=document.getElementById('cl-modal-message');
let found=[],selectedIndex=-1,previousFocus=null,searchController=null,busy=false;
function modalOpen(){if(!modal)return;previousFocus=document.activeElement;modal.hidden=false;search.value='';found=[];selectedIndex=-1;results.replaceChildren();message.textContent='Digite o código ou a descrição e pressione Enter.';document.body.classList.add('cl-modal-active');search.focus();}
function modalClose(){if(!modal)return;modal.hidden=true;document.body.classList.remove('cl-modal-active');searchController?.abort();(previousFocus||open)?.focus();}
function chooseIndex(i){if(!found.length)return;selectedIndex=(i+found.length)%found.length;const options=[...results.querySelectorAll('[role=option]')];options.forEach((o,j)=>{o.setAttribute('aria-selected',String(j===selectedIndex));o.classList.toggle('is-selected',j===selectedIndex)});options[selectedIndex]?.scrollIntoView({block:'nearest'});}
async function doSearch(){const query=search.value.trim();found=[];selectedIndex=-1;results.replaceChildren();if(query.length<1){message.textContent='Digite o código ou a descrição.';return;}searchController?.abort();searchController=new AbortController();message.textContent='Pesquisando...';try{const r=await fetch('compras_lista_editar.php?lookup=products&q='+encodeURIComponent(query),{credentials:'same-origin',signal:searchController.signal});const data=await r.json().catch(()=>null);if(!r.ok||!data?.ok)throw Error(data?.message||'Falha ao pesquisar produtos (HTTP '+r.status+').');if(!Array.isArray(data.items))throw Error('Resultado inválido');found=data.items;for(const [i,p] of found.entries()){const option=document.createElement('button');option.type='button';option.className='cl-modal-option';option.setAttribute('role','option');option.setAttribute('aria-selected','false');option.textContent=String(p.codigo)+' — '+String(p.nome);option.addEventListener('click',()=>{chooseIndex(i);selectProduct()});option.addEventListener('dragstart',e=>e.preventDefault());results.appendChild(option)}message.textContent=found.length?found.length+' produto(s) encontrado(s).':'Nenhum produto encontrado.';if(found.length)chooseIndex(0);search.focus()}catch(e){if(e.name!=='AbortError')message.textContent=e.message;}}
async function selectProduct(){if(busy||selectedIndex<0||!found[selectedIndex])return;busy=true;const p=found[selectedIndex];message.textContent='Incluindo '+p.nome+'...';const data=new URLSearchParams({action:'add_item',csrf,lista_id:String(<?= (int)$selectedId ?>),produto_id:String(p.id),quantidade:'1'});try{const r=await fetch('compras_lista_editar.php',{method:'POST',credentials:'same-origin',body:data});const j=await r.json();if(!r.ok||!j.ok)throw Error(j.message||'Falha ao incluir produto');sessionStorage.setItem('cl-focus-qty',String(p.id));location.reload()}catch(e){message.textContent=e.message;busy=false;search.focus()}}
open?.addEventListener('click',modalOpen);
document.getElementById('cl-modal-close')?.addEventListener('click',modalClose);
document.getElementById('cl-modal-search')?.addEventListener('click',doSearch);
// Fecha somente pelo botão X ou tecla Escape; arrastar/selecionar não fecha o modal.
search?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();doSearch()}else if(e.key==='ArrowDown'&&found.length){e.preventDefault();results.querySelector('[role=option]')?.focus();chooseIndex(selectedIndex<0?0:selectedIndex)}});
results?.addEventListener('dragstart',e=>e.preventDefault());
results?.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();chooseIndex(selectedIndex+(e.key==='ArrowDown'?1:-1));results.querySelectorAll('[role=option]')[selectedIndex]?.focus()}else if(e.key==='Enter'){e.preventDefault();selectProduct()}else if(e.key.length===1&&!e.ctrlKey&&!e.metaKey&&!e.altKey){search.focus();search.value=e.key;found=[];results.replaceChildren();message.textContent='Pressione Enter para pesquisar.';e.preventDefault()}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!modal.hidden){e.preventDefault();modalClose();return;}if(e.key==='F8'&&!e.ctrlKey&&!e.altKey&&!e.metaKey){const productView=document.querySelector('.cl-view[data-step=products]');if(!productView?.hidden){e.preventDefault();if(modal.hidden)modalOpen();else search.focus();}else if(document.querySelector('.cl-step[data-step=suppliers]')?.getAttribute('aria-selected')==='true'){e.preventDefault();frame?.contentWindow?.postMessage('nexa-open-supplier-f8',location.origin)}}});
const focusId=sessionStorage.getItem('cl-focus-qty');if(focusId){sessionStorage.removeItem('cl-focus-qty');const row=[...document.querySelectorAll('tr[data-produto]')].find(r=>r.dataset.produto===focusId);if(row){const input=row.querySelector('.cl-qty');input?.focus();input?.select();row.scrollIntoView({block:'center'})}}
const buttons=[...document.querySelectorAll('.cl-step')];
const frame=document.getElementById('cl-quote-frame');
const quoteView=document.querySelector('.cl-view[data-step=quote]');
let quoteId=frame?Number(frame.dataset.existing||0):0;
let loadingQuote=null;
async function ensureQuote(){
 if(quoteId)return quoteId;
 if(loadingQuote)return loadingQuote;
 loadingQuote=(async()=>{
  const data=new URLSearchParams({action:'ensure_quote',csrf,lista_id:String(<?= (int)$selectedId ?>)});
  const response=await fetch('compras_lista_editar.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:data});
  const json=await response.json();if(!response.ok||!json.ok)throw Error(json.message||'Erro ao preparar cotação');
  quoteId=Number(json.id);return quoteId;
 })();
 try{return await loadingQuote}finally{loadingQuote=null}
}
async function displayStep(step){
 buttons.forEach(b=>b.setAttribute('aria-selected',String(b.dataset.step===step)));
 document.querySelectorAll('.cl-view').forEach(view=>view.hidden=view.dataset.step!==(step==='products'?'products':'quote'));
 if(step!=='products'){
   if(!frame)return;
   const info=document.getElementById('cl-quote-loading');
   try{
    const id=await ensureQuote();
    const path='compras_cotacoes_matriz.php?id='+encodeURIComponent(id)+'&embed=1';
    if(!frame.src || !frame.src.includes('id='+id+'&embed=1')){
      frame.onload=()=>{frame.hidden=false;if(info)info.hidden=true;selectEmbeddedStep(step);try{const content=frame.contentDocument;if(!content?.querySelector('.cm-page')){if(info){info.hidden=false;info.textContent='A página de cotações não carregou corretamente. Confira o log do Apache e a migration de Compras 2.0.'}}}catch(e){if(info){info.hidden=false;info.textContent='Não foi possível verificar a tela de cotação.'}}};
      frame.src=path;
    } else {frame.hidden=false;if(info)info.hidden=true;selectEmbeddedStep(step)}
   }catch(e){if(info){info.hidden=false;info.textContent=e.message;}frame.hidden=true;}
 }
 history.replaceState(null,'','#'+step);
}
function selectEmbeddedStep(step){
 try{const doc=frame.contentDocument;if(!doc)return;
   const button=doc.querySelector('.cm-tab[data-pane="'+step+'"]');if(button)button.click();
 }catch(e){/* iframe pode ainda estar carregando */}
}
buttons.forEach(b=>b.addEventListener('click',()=>displayStep(b.dataset.step)));
if(buttons.length){const initial=location.hash.substring(1);displayStep(buttons.some(b=>b.dataset.step===initial)?initial:'products')}
})();
</script>
<?php admin_footer(); ?>
