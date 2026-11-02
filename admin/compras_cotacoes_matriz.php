<?php
declare(strict_types=1);
/** Matriz de cotacao Nexa - fornecedores por cotacao, escolhas por item e pedidos em rascunho. */
require __DIR__.'/_bootstrap.php';
if(session_status()!==PHP_SESSION_ACTIVE) session_start();
$companyId=(int)COMPANY_ID;
if(function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo,$companyId)) {
  $companyId=function_exists('multi_company_operational_company_id')?(int)multi_company_operational_company_id($pdo,'stock_read'):$companyId;
  if($companyId!==(int)COMPANY_ID && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo,'produtos')){http_response_code(403);exit('Sem permissao para a empresa selecionada.');}
}
$userId=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
if(!$userId){http_response_code(403);exit('Sessao invalida.');}
if(empty($_SESSION['compras_matriz_csrf'])) $_SESSION['compras_matriz_csrf']=bin2hex(random_bytes(24));
$csrf=(string)$_SESSION['compras_matriz_csrf'];
function cm_h($v):string{return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8');}
function cm_id($v):int{$i=filter_var($v,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);return $i?(int)$i:0;}
function cm_decimal($v,string $label,float $min=0,float $max=999999999):string{
 $v=(string)$v;if(!preg_match('/^\d{1,12}(?:\.\d{1,4})?$/',$v)||(float)$v<$min||(float)$v>$max)throw new RuntimeException($label.' invalido; use ponto decimal.');
 return number_format((float)$v,4,'.','');
}
function cm_quote(PDO $pdo,int $id,int $company,bool $lock=false):array {
 $s=$pdo->prepare('SELECT c.*,l.nome lista_nome FROM compras_cotacoes c JOIN compras_listas l ON l.id=c.lista_id AND l.company_id=c.company_id WHERE c.id=? AND c.company_id=?'.($lock?' FOR UPDATE':''));
 $s->execute([$id,$company]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Cotacao nao encontrada nesta empresa.');return $r;
}
function cm_supplier(PDO $pdo,int $cot,int $company,string $name):bool {
 $s=$pdo->prepare('SELECT 1 FROM compras_cotacao_fornecedores WHERE company_id=? AND cotacao_id=? AND nome=?');$s->execute([$company,$cot,$name]);return (bool)$s->fetchColumn();
}
function cm_item(PDO $pdo,int $item,int $company,int $list):array {
 $s=$pdo->prepare('SELECT id,produto_id,quantidade FROM compras_lista_itens WHERE id=? AND company_id=? AND lista_id=?');$s->execute([$item,$company,$list]);$r=$s->fetch(PDO::FETCH_ASSOC);if(!$r)throw new RuntimeException('Produto nao pertence a esta cotacao.');return $r;
}
if (($_GET['lookup']??'')==='suppliers') {
  header('Content-Type: application/json; charset=utf-8');
  header('Cache-Control: no-store');
  try {
    $q=trim((string)($_GET['q']??''));
    if(mb_strlen($q)<1){echo json_encode(['ok'=>true,'items'=>[]]);exit;}
    $like='%'.str_replace(['!','%','_'],['!!','!%','!_'],mb_substr($q,0,100)).'%';
    $check=$pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?');
    $check->execute(['finance_people']);
    $columns=array_flip($check->fetchAll(PDO::FETCH_COLUMN));
    foreach(['id','name','company_id','is_supplier'] as $required) if(!isset($columns[$required])) throw new RuntimeException('Cadastro de fornecedores incompatível: '.$required);
    $fields=['name'];
    foreach(['fantasy_name','document'] as $optional)if(isset($columns[$optional]))$fields[]=$optional;
    $conditions=[];
    foreach($fields as $field)$conditions[]="(CONVERT(`$field` USING utf8mb4) COLLATE utf8mb4_general_ci LIKE (CONVERT(? USING utf8mb4) COLLATE utf8mb4_general_ci) ESCAPE '!')";
    $active=isset($columns['active'])?' AND active=1':'';
    $document=isset($columns['document'])?'document':'NULL AS document';
    $sql="SELECT id,name,$document FROM finance_people WHERE company_id=? AND is_supplier=1 $active AND (".implode(' OR ',$conditions).') ORDER BY name LIMIT 60';
    $st=$pdo->prepare($sql);
    $st->execute(array_merge([$companyId],array_fill(0,count($fields),$like)));
    echo json_encode(['ok'=>true,'items'=>$st->fetchAll(PDO::FETCH_ASSOC)],JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE);
  }catch(Throwable $e){error_log('[compras_cotacoes_matriz][lookup_suppliers] '.$e->getMessage());http_response_code(500);echo json_encode(['ok'=>false,'message'=>'Não foi possível pesquisar fornecedores. Confira o log PHP do Nexa.'],JSON_UNESCAPED_UNICODE);}
  exit;
}
$cot=cm_id($_POST['cotacao_id']??$_GET['id']??null);$error='';$ready=true;
try{foreach(['compras_cotacao_fornecedores','compras_cotacao_escolhas','compras_pedidos','compras_pedido_itens'] as $table)$pdo->query('SELECT id FROM '.$table.' LIMIT 0');}catch(Throwable $e){$ready=false;}
if($_SERVER['REQUEST_METHOD']==='POST'){
 header('Content-Type: application/json; charset=utf-8');
 try{
  if(!$ready)throw new RuntimeException('Aplique a migration Compras 2.0 no Atualizador do Banco.');
  if(!hash_equals($csrf,(string)($_POST['csrf']??'')))throw new RuntimeException('Sessao expirada. Atualize a pagina.');
  $action=(string)($_POST['action']??'');$quote=cm_quote($pdo,$cot,$companyId);
  if($quote['status']!=='rascunho')throw new RuntimeException('Cotacao nao esta em rascunho.');
  if($action==='add_supplier'){
    $supplierId=cm_id($_POST['supplier_id']??null);
    if(!$supplierId)throw new RuntimeException('Selecione um fornecedor cadastrado.');
    $get=$pdo->prepare('SELECT name FROM finance_people WHERE id=? AND company_id=? AND is_supplier=1 AND active=1 LIMIT 1');
    $get->execute([$supplierId,$companyId]);$name=trim((string)$get->fetchColumn());
    if($name===''||mb_strlen($name)>150)throw new RuntimeException('Fornecedor não encontrado no cadastro desta empresa.');
    $s=$pdo->prepare('SELECT COUNT(*) FROM compras_cotacao_fornecedores WHERE company_id=? AND cotacao_id=?');$s->execute([$companyId,$cot]);if((int)$s->fetchColumn()>=20)throw new RuntimeException('Limite de 20 fornecedores por cotacao.');
    $s=$pdo->prepare('INSERT INTO compras_cotacao_fornecedores(company_id,cotacao_id,nome) VALUES (?,?,?)');$s->execute([$companyId,$cot,$name]);
  }elseif($action==='remove_supplier'){
    $name=(string)($_POST['nome']??'');if(!cm_supplier($pdo,$cot,$companyId,$name))throw new RuntimeException('Fornecedor nao encontrado.');
    $pdo->beginTransaction();try{
      $s=$pdo->prepare('DELETE FROM compras_cotacao_escolhas WHERE company_id=? AND cotacao_id=? AND fornecedor_nome=?');$s->execute([$companyId,$cot,$name]);
      $s=$pdo->prepare('DELETE FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND fornecedor_nome=?');$s->execute([$companyId,$cot,$name]);
      $s=$pdo->prepare('DELETE FROM compras_cotacao_fornecedores WHERE company_id=? AND cotacao_id=? AND nome=?');$s->execute([$companyId,$cot,$name]);$pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }elseif($action==='save_prices'){
    $name=(string)($_POST['nome']??'');if(!cm_supplier($pdo,$cot,$companyId,$name)){ $legacy=$pdo->prepare('SELECT 1 FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND fornecedor_nome=? LIMIT 1');$legacy->execute([$companyId,$cot,$name]);if(!$legacy->fetchColumn())throw new RuntimeException('Fornecedor nao cadastrado.'); }
    $prices=$_POST['precos']??[];$discounts=$_POST['descontos']??[];
    if(!is_array($prices)||!is_array($discounts)||count($prices)>1000)throw new RuntimeException('Dados invalidos.');
    $s=$pdo->prepare('SELECT id FROM compras_lista_itens WHERE lista_id=? AND company_id=?');$s->execute([(int)$quote['lista_id'],$companyId]);$allowed=array_fill_keys(array_map('intval',$s->fetchAll(PDO::FETCH_COLUMN)),true);
    $pdo->beginTransaction();try{
      $save=$pdo->prepare('INSERT INTO compras_cotacao_ofertas(company_id,cotacao_id,lista_item_id,fornecedor_nome,preco_unitario,desconto_percentual,created_at,updated_at) VALUES (?,?,?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE preco_unitario=VALUES(preco_unitario),desconto_percentual=VALUES(desconto_percentual),updated_at=NOW()');
      $delete=$pdo->prepare('DELETE FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND lista_item_id=? AND fornecedor_nome=?');
      foreach($prices as $key=>$raw){$item=cm_id($key);if(!$item||!isset($allowed[$item]))throw new RuntimeException('Item nao pertence a lista.');
        $value=trim((string)$raw);if($value===''){$delete->execute([$companyId,$cot,$item,$name]);continue;}
        $price=cm_decimal($value,'Preco',0.0001,999999999);$discount=cm_decimal($discounts[$key]??'0','Desconto',0,100);
        $save->execute([$companyId,$cot,$item,$name,$price,$discount]);
      }
      // Remover escolhas que ficaram sem oferta apos exclusao de preco.
      $s=$pdo->prepare('DELETE e FROM compras_cotacao_escolhas e LEFT JOIN compras_cotacao_ofertas o ON o.cotacao_id=e.cotacao_id AND o.company_id=e.company_id AND o.lista_item_id=e.lista_item_id AND o.fornecedor_nome=e.fornecedor_nome WHERE e.cotacao_id=? AND e.company_id=? AND o.id IS NULL');$s->execute([$cot,$companyId]);
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }elseif($action==='choose'){
    $item=cm_id($_POST['lista_item_id']??null);cm_item($pdo,$item,$companyId,(int)$quote['lista_id']);$name=(string)($_POST['nome']??'');
    if($name===''){$s=$pdo->prepare('DELETE FROM compras_cotacao_escolhas WHERE company_id=? AND cotacao_id=? AND lista_item_id=?');$s->execute([$companyId,$cot,$item]);}
    else{
      $s=$pdo->prepare('SELECT 1 FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND lista_item_id=? AND fornecedor_nome=?');$s->execute([$companyId,$cot,$item,$name]);if(!$s->fetchColumn())throw new RuntimeException('Fornecedor sem preco informado para este produto.');
      $s=$pdo->prepare('INSERT INTO compras_cotacao_escolhas(company_id,cotacao_id,lista_item_id,fornecedor_nome,updated_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE fornecedor_nome=VALUES(fornecedor_nome),updated_at=NOW()');$s->execute([$companyId,$cot,$item,$name]);
    }
  }elseif($action==='generate_orders'){
    $pdo->beginTransaction();try{
      $quote=cm_quote($pdo,$cot,$companyId,true);
      $s=$pdo->prepare('SELECT COUNT(*) FROM compras_pedidos WHERE company_id=? AND cotacao_id=?');$s->execute([$companyId,$cot]);if((int)$s->fetchColumn())throw new RuntimeException('Os pedidos desta cotacao ja foram gerados.');
      // Consolidar sugestoes nao alteradas: para cada item sem escolha persistida, gravar o menor preco liquido.
      $missing=$pdo->prepare('SELECT i.id FROM compras_lista_itens i LEFT JOIN compras_cotacao_escolhas e ON e.lista_item_id=i.id AND e.cotacao_id=? AND e.company_id=? WHERE i.lista_id=? AND i.company_id=? AND e.id IS NULL');
      $missing->execute([$cot,$companyId,(int)$quote['lista_id'],$companyId]);
      $candidate=$pdo->prepare('SELECT fornecedor_nome FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=? AND lista_item_id=? ORDER BY preco_unitario*(1-desconto_percentual/100),fornecedor_nome LIMIT 1');
      $choose=$pdo->prepare('INSERT INTO compras_cotacao_escolhas(company_id,cotacao_id,lista_item_id,fornecedor_nome,updated_at) VALUES(?,?,?,?,NOW())');
      foreach($missing->fetchAll(PDO::FETCH_COLUMN) as $unselected){$candidate->execute([$companyId,$cot,(int)$unselected]);$supplier=$candidate->fetchColumn();if($supplier!==false)$choose->execute([$companyId,$cot,(int)$unselected,(string)$supplier]);}
      $s=$pdo->prepare('SELECT i.id,i.produto_id,i.quantidade,o.fornecedor_nome,o.preco_unitario,o.desconto_percentual FROM compras_lista_itens i LEFT JOIN compras_cotacao_escolhas e ON e.lista_item_id=i.id AND e.cotacao_id=? AND e.company_id=? LEFT JOIN compras_cotacao_ofertas o ON o.lista_item_id=i.id AND o.cotacao_id=? AND o.company_id=? AND o.fornecedor_nome=e.fornecedor_nome WHERE i.lista_id=? AND i.company_id=? ORDER BY i.id');
      $s->execute([$cot,$companyId,$cot,$companyId,(int)$quote['lista_id'],$companyId]);$rows=$s->fetchAll(PDO::FETCH_ASSOC);if(!$rows)throw new RuntimeException('Lista sem produtos.');
      foreach($rows as $r)if($r['fornecedor_nome']===null)throw new RuntimeException('Selecione um fornecedor para todos os produtos antes de gerar pedidos.');
      $groups=[];foreach($rows as $r)$groups[$r['fornecedor_nome']][]=$r;
      $insert=$pdo->prepare("INSERT INTO compras_pedidos(company_id,cotacao_id,fornecedor_nome,status,created_by,created_at) VALUES (?,?,?,'rascunho',?,NOW())");
      $insItem=$pdo->prepare('INSERT INTO compras_pedido_itens(company_id,pedido_id,lista_item_id,produto_id,quantidade,preco_unitario,desconto_percentual) VALUES (?,?,?,?,?,?,?)');
      foreach($groups as $name=>$gr){$insert->execute([$companyId,$cot,$name,$userId]);$pid=(int)$pdo->lastInsertId();foreach($gr as $r)$insItem->execute([$companyId,$pid,$r['id'],$r['produto_id'],$r['quantidade'],$r['preco_unitario'],$r['desconto_percentual']]);}
      $pdo->commit();
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }else throw new RuntimeException('Acao desconhecida.');
  echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE);exit;
 }catch(Throwable $e){error_log('[Nexa Compras Matriz] '.$e->getMessage());http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE);exit;}
}
$quote=null;$items=[];$suppliers=[];$offers=[];$choices=[];$orders=[];
try{if($ready && $cot){$quote=cm_quote($pdo,$cot,$companyId);
 $s=$pdo->prepare('SELECT id,nome FROM compras_cotacao_fornecedores WHERE company_id=? AND cotacao_id=? ORDER BY id');$s->execute([$companyId,$cot]);$suppliers=$s->fetchAll(PDO::FETCH_ASSOC);
 // Cotações existentes de 1.9 continuam disponíveis: fornecedores usados nas ofertas são importados na apresentação.
 $s=$pdo->prepare('SELECT DISTINCT fornecedor_nome FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=?');$s->execute([$companyId,$cot]);$known=array_column($suppliers,'nome');foreach($s->fetchAll(PDO::FETCH_COLUMN) as $name)if(!in_array($name,$known,true)){$suppliers[]=['id'=>0,'nome'=>$name];$known[]=$name;}
 $s=$pdo->prepare('SELECT i.id,i.produto_id,i.quantidade,p.nome produto_nome FROM compras_lista_itens i JOIN produtos p ON p.id=i.produto_id AND p.company_id=i.company_id WHERE i.company_id=? AND i.lista_id=? ORDER BY p.nome');$s->execute([$companyId,(int)$quote['lista_id']]);$items=$s->fetchAll(PDO::FETCH_ASSOC);
 $s=$pdo->prepare('SELECT lista_item_id,fornecedor_nome,preco_unitario,desconto_percentual FROM compras_cotacao_ofertas WHERE company_id=? AND cotacao_id=?');$s->execute([$companyId,$cot]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$offers[(int)$r['lista_item_id']][$r['fornecedor_nome']]=$r;
 $s=$pdo->prepare('SELECT lista_item_id,fornecedor_nome FROM compras_cotacao_escolhas WHERE company_id=? AND cotacao_id=?');$s->execute([$companyId,$cot]);foreach($s->fetchAll(PDO::FETCH_ASSOC) as $r)$choices[(int)$r['lista_item_id']]=$r['fornecedor_nome'];
 $s=$pdo->prepare('SELECT p.id,p.fornecedor_nome,p.status,COUNT(i.id) itens,COALESCE(SUM(i.quantidade*i.preco_unitario*(1-i.desconto_percentual/100)),0) total FROM compras_pedidos p LEFT JOIN compras_pedido_itens i ON i.pedido_id=p.id AND i.company_id=p.company_id WHERE p.company_id=? AND p.cotacao_id=? GROUP BY p.id,p.fornecedor_nome,p.status ORDER BY p.id');$s->execute([$companyId,$cot]);$orders=$s->fetchAll(PDO::FETCH_ASSOC);
 }}catch(Throwable $e){error_log('[Nexa Compras Matriz GET] '.$e->getMessage());$error='Falha ao carregar a cotacao. Consulte o log PHP.';}
// Referências históricas por produto/fornecedor. Consulta somente de leitura e limitada à empresa operacional.
$priceHistory=[];$priceHistoryNotice='';
if($quote && $items){
 try{
  $ids=array_values(array_unique(array_map(static fn($i)=>(int)$i['produto_id'],$items)));
  $marks=implode(',',array_fill(0,count($ids),'?'));
  $sql="SELECT pi.produto_id,p.fornecedor_nome,p.created_at,p.id pedido_id,pi.id item_id,pi.preco_unitario*(1-COALESCE(pi.desconto_percentual,0)/100) preco_liquido
      FROM compras_pedidos p JOIN compras_pedido_itens pi ON pi.pedido_id=p.id AND pi.company_id=p.company_id
      WHERE p.company_id=? AND p.status='aprovado' AND p.created_at>=DATE_SUB(NOW(),INTERVAL 12 MONTH)
      AND pi.produto_id IN ($marks)
      ORDER BY p.created_at DESC,p.id DESC,pi.id DESC LIMIT 10001";
  $q=$pdo->prepare($sql);$q->execute(array_merge([$companyId],$ids));$histRows=$q->fetchAll(PDO::FETCH_ASSOC);
  if(count($histRows)>10000){$priceHistoryNotice='Histórico limitado a 10.000 itens; refine as referências no relatório de Histórico de Preços.';}
  foreach(array_slice($histRows,0,10000) as $r){
   $pid=(int)$r['produto_id'];$n=(float)$r['preco_liquido'];if($n<=0)continue;
   $key=mb_strtolower(trim((string)$r['fornecedor_nome']),'UTF-8');
   $h=&$priceHistory[$pid];if(!isset($h))$h=['min'=>null,'min_date'=>null,'by_supplier'=>[]];
   if($h['min']===null || $n<$h['min']){$h['min']=$n;$h['min_date']=$r['created_at'];}
   if(!isset($h['by_supplier'][$key]))$h['by_supplier'][$key]=['last'=>$n,'date'=>$r['created_at'],'previous'=>null];
   elseif($h['by_supplier'][$key]['previous']===null)$h['by_supplier'][$key]['previous']=$n;
   unset($h);
  }
 }catch(Throwable $e){error_log('[Nexa Compras 3.9 histórico de preços] '.$e->getMessage());$priceHistory=[];$priceHistoryNotice='Referências temporariamente indisponíveis. As propostas continuam funcionando.';}
}
$embedded=($_GET['embed']??'')==='1';
require __DIR__.'/_chrome.php';
if (!$embedded) admin_topbar('Comparativo de Cotações','produtos');
?>
<style>
.cm-page{width:calc(100% - 24px);max-width:none;margin:12px auto;color:#18365b;font-size:13px}.cm-card{background:white;border:1px solid #dbe6f5;border-radius:9px;padding:14px;margin:12px 0}.cm-page h1{font-size:23px;color:#102f69}.cm-page h2{font-size:17px;color:#14366d}.cm-tabs{display:flex;gap:7px;flex-wrap:wrap}.cm-tab{border:1px solid #c4d4e8;background:#f2f6fc;padding:10px 14px;border-radius:7px;cursor:pointer;color:#153a77}.cm-tab[aria-selected=true]{background:#1557ba;color:white}.cm-pane[hidden]{display:none}.cm-grid{overflow:auto;max-height:68vh;border:1px solid #dce6f3;border-radius:8px;isolation:isolate}.cm-page table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.cm-page th{position:sticky;top:0;background:#eaf2fc;color:#183f7b;z-index:3;white-space:nowrap}.cm-page th,.cm-page td{padding:8px 9px;border-bottom:1px solid #e2e8f0;vertical-align:middle}.cm-page input,.cm-page select{border:1px solid #bfcfe3;padding:7px;border-radius:5px;max-width:100%;box-sizing:border-box}.cm-page input[type=number]{width:110px}.cm-page button.cm-action{background:#1759b5;color:white;border:0;border-radius:6px;padding:9px 13px;cursor:pointer}.cm-page button:disabled{opacity:.5}.cm-row{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.cm-muted{color:#667992}.cm-best{background:#e8f6ed}.cm-chosen{outline:2px solid #2b6cd2;outline-offset:-2px}.cm-price{white-space:nowrap;text-align:right}.cm-supplier{min-width:145px}.cm-page a{color:#1759b5}.cm-error{color:#a52c2c}

.cm-supplier-modal[hidden]{display:none}.cm-supplier-modal{position:fixed;inset:0;z-index:50000;background:rgba(20,35,62,.55);display:flex;align-items:center;justify-content:center;padding:20px;overscroll-behavior:contain}.cm-supplier-modal-card{width:min(860px,100%);max-height:85vh;display:flex;flex-direction:column;background:#fff;border-radius:12px;padding:20px;box-shadow:0 18px 48px #10294b55;font-family:inherit}.cm-supplier-results{min-height:100px;overflow:auto;border:1px solid #d3dfed;border-radius:7px;overscroll-behavior:contain}.cm-supplier-choice{display:block;width:100%;text-align:left;padding:11px;background:#fff;border:0;border-bottom:1px solid #e2e8f0;cursor:pointer;font:inherit;color:#203f65;user-select:none}.cm-supplier-choice:hover,.cm-supplier-choice.is-selected{background:#e5effc}
.cm-price-ref{font-size:11px;line-height:1.6;min-width:185px;color:#536b88}.cm-price-ref strong{color:#163c79}.cm-proposal-feedback{font-size:11px;min-width:140px}.cm-proposal-feedback.cm-price-high{color:#ae3c28;font-weight:600}.cm-proposal-feedback.cm-price-low{color:#17784a;font-weight:600}.cm-ref-note{padding:9px 11px;border-radius:7px;background:#f0f5fc;color:#415d7d;margin-bottom:12px}

</style>
<?php if($embedded):?><style>html,body{font-family:system-ui,-apple-system,Segoe UI,Roboto,Ubuntu,Cantarell,Noto Sans,sans-serif;margin:0}.cm-page{margin:0;width:100%}.cm-page>h1,.cm-page>p,.cm-tabs,.cm-page>div.cm-card:first-of-type{display:none!important}.cm-pane{margin-top:0!important}</style><?php endif;?>
<div class="cm-page"><h1>Cotação de Compras — Comparativo</h1><p><a href="compras_cotacoes.php">← Cotações</a> · <a href="compras_listas.php">Listas de Compras</a></p>
<?php if(!$ready):?><div class="cm-card cm-error">Aplique a migration Compras 2.0 pelo Atualizador do Banco.</div>
<?php elseif($error):?><div class="cm-card cm-error"><?=cm_h($error)?></div>
<?php elseif(!$quote):?><div class="cm-card">Selecione uma cotação em <a href="compras_cotacoes.php">Cotações de Compras</a>.</div>
<?php else: $readonly=!empty($orders);?>
<div class="cm-card"><h2>#<?=(int)$quote['id']?> — <?=cm_h($quote['titulo'])?></h2><p class="cm-muted">Origem: <?=cm_h($quote['lista_nome'])?>. Cadastre os fornecedores uma vez, preencha cada proposta e compare lado a lado. O menor preço líquido é sugerido automaticamente; a escolha manual prevalece. Não considera frete nem impostos.</p>
<div class="cm-tabs" role="tablist"><button class="cm-tab" data-pane="suppliers" aria-selected="true">1. Fornecedores</button><button class="cm-tab" data-pane="proposals" aria-selected="false">2. Propostas</button><button class="cm-tab" data-pane="compare" aria-selected="false">3. Comparativo</button><button class="cm-tab" data-pane="orders" aria-selected="false">4. Pedidos</button></div></div>
<section class="cm-card cm-pane" data-pane="suppliers"><div class="cm-row" style="justify-content:space-between"><h2>Fornecedores da cotação</h2><button type="button" class="cm-action" id="cm-supplier-open" <?= $readonly?'disabled':'' ?>>Buscar F8</button></div><form id="cm-add" hidden><input type="hidden" name="supplier_id" id="cm-supplier-selected"></form><p class="cm-muted">Cada fornecedor cadastrado terá sua própria ficha de preços.</p><div class="cm-grid"><table><thead><tr><th>Fornecedor</th><th>Ação</th></tr></thead><tbody><?php foreach($suppliers as $s):?><tr><td><?=cm_h($s['nome'])?></td><td><button type="button" class="cm-action cm-remove" data-name="<?=cm_h($s['nome'])?>" <?=($readonly||!(int)$s['id'])?'disabled':''?>>Remover</button></td></tr><?php endforeach; ?><?php if(!$suppliers):?><tr><td colspan="2">Adicione os fornecedores que participarão da cotação.</td></tr><?php endif;?></tbody></table></div></section>
<div id="cm-supplier-modal" class="cm-supplier-modal" hidden role="dialog" aria-modal="true" aria-labelledby="cm-supplier-modal-title">
  <div class="cm-supplier-modal-card">
    <div class="cm-row" style="justify-content:space-between"><h2 id="cm-supplier-modal-title">Buscar fornecedor</h2><button type="button" id="cm-supplier-close" class="cm-action" aria-label="Fechar pesquisa">Fechar ×</button></div>
    <div class="cm-row" style="margin:12px 0"><input type="search" id="cm-supplier-query" placeholder="Nome, fantasia ou CNPJ — Enter para pesquisar" autocomplete="off" style="flex:1;min-width:200px"><button type="button" class="cm-action" id="cm-supplier-run">Pesquisar</button></div>
    <p class="cm-muted">F8 abre a busca. Enter pesquisa; ↑/↓ navega; Enter seleciona; Esc fecha.</p>
    <p id="cm-supplier-status" role="status" aria-live="polite">Digite para pesquisar fornecedores cadastrados.</p>
    <div id="cm-supplier-results" class="cm-supplier-results" role="listbox" aria-label="Fornecedores encontrados"></div>
  </div>
</div>
<section class="cm-card cm-pane" data-pane="proposals" hidden><h2>Preencher preços por fornecedor</h2>
<p class="cm-ref-note">Referências dos últimos 12 meses de <strong>pedidos aprovados</strong>, na mesma empresa e para o mesmo produto. Valores líquidos por unidade, sem frete ou impostos. Referências não modificam a proposta. <a href="compras_historico_precos.php" target="_blank" rel="noopener">Abrir histórico completo</a>. <?=cm_h($priceHistoryNotice)?></p><?php foreach($suppliers as $s):$name=$s['nome'];?><details class="cm-card" <?=count($suppliers)===1?'open':''?>><summary><strong><?=cm_h($name)?></strong> — abrir ficha de preços</summary><form class="cm-proposal" data-name="<?=cm_h($name)?>"><div class="cm-grid"><table><thead><tr><th>Produto</th><th>Qtd.</th><th>Preço unitário (R$)</th><th>Desconto (%)</th><th>Líquido unit.</th><th>Histórico de preços (R$)</th><th>Comparação atual</th></tr></thead><tbody><?php foreach($items as $it):$id=(int)$it['id'];$o=$offers[$id][$name]??null;?><tr><td><?=cm_h($it['produto_nome'])?></td><td><?=number_format((float)$it['quantidade'],3,',','.')?></td><td><input type="number" name="precos[<?=$id?>]" min="0.0001" step="0.0001" value="<?=cm_h($o['preco_unitario']??'')?>" <?= $readonly?'disabled':'' ?>></td><td><input type="number" name="descontos[<?=$id?>]" min="0" max="100" step="0.001" value="<?=cm_h($o['desconto_percentual']??'0')?>" <?= $readonly?'disabled':'' ?>></td><td class="cm-price"><?php if($o):$net=(float)$o['preco_unitario']*(1-(float)$o['desconto_percentual']/100);echo 'R$ '.number_format($net,4,',','.');else:echo '—';endif;?></td>
<?php $hist=$priceHistory[(int)$it['produto_id']]??null;$sh=$hist['by_supplier'][mb_strtolower(trim((string)$name),'UTF-8')]??null;$last=$sh['last']??null; ?>
<td class="cm-price-ref"><?php if($sh):?><div>Último deste fornecedor: <strong>R$ <?=number_format((float)$sh['last'],4,',','.')?></strong></div><div>Em <?=cm_h(date('d/m/Y',strtotime((string)$sh['date'])))?></div><?php else:?><div>Sem histórico deste fornecedor</div><?php endif;?><?php if($hist):?><div>Menor histórico do produto: <strong>R$ <?=number_format((float)$hist['min'],4,',','.')?></strong></div><?php else:?><div>Produto sem histórico</div><?php endif;?></td>
<td><span class="cm-proposal-feedback" data-last="<?=cm_h($last!==null?(string)$last:'')?>" data-min="<?=cm_h($hist!==null?(string)$hist['min']:'')?>">—</span></td></tr><?php endforeach;?></tbody></table></div><p><button class="cm-action" <?=$readonly?'disabled':''?>>Salvar preços de <?=cm_h($name)?></button></p></form></details><?php endforeach;?><?php if(!$suppliers):?><p>Cadastre fornecedores na primeira aba.</p><?php endif;?></section>
<section class="cm-card cm-pane" data-pane="compare" hidden><h2>Comparativo por produto</h2><p class="cm-muted">Verde = melhor preço líquido. A escolha marcada em cada linha será usada na geração dos pedidos. Se você não alterar, selecione a oferta sugerida para confirmar.</p><div class="cm-grid"><table><thead><tr><th>Produto</th><th>Qtd.</th><?php foreach($suppliers as $s):?><th class="cm-supplier"><?=cm_h($s['nome'])?></th><?php endforeach;?></tr></thead><tbody><?php foreach($items as $it):$id=(int)$it['id'];$bestName='';$bestNet=null;foreach($suppliers as $s){$name=$s['nome'];$o=$offers[$id][$name]??null;if(!$o)continue;$net=(float)$o['preco_unitario']*(1-(float)$o['desconto_percentual']/100);if($bestNet===null||$net<$bestNet){$bestNet=$net;$bestName=$name;}}$chosen=$choices[$id]??$bestName;?><tr><td><strong><?=cm_h($it['produto_nome'])?></strong></td><td><?=number_format((float)$it['quantidade'],3,',','.')?></td><?php foreach($suppliers as $s):$name=$s['nome'];$o=$offers[$id][$name]??null;$net=$o?(float)$o['preco_unitario']*(1-(float)$o['desconto_percentual']/100):null;?><td class="<?=($net!==null&&$bestNet!==null&&abs($net-$bestNet)<0.00001)?'cm-best':''?> <?=($o&&$chosen===$name)?'cm-chosen':''?>"><?php if($o):?><label><input type="radio" class="cm-choose" data-item="<?=$id?>" name="escolha_<?=$id?>" value="<?=cm_h($name)?>" <?= $chosen===$name?'checked':'' ?> <?= $readonly?'disabled':'' ?> > R$ <?=number_format($net,4,',','.')?> <?=($name===$bestName)?'★':''?></label><?php else:?>—<?php endif;?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div><p class="cm-muted">As escolhas automáticas só são gravadas quando você confirma a geração. Se mudar uma opção, ela será salva imediatamente.</p></section>
<section class="cm-card cm-pane" data-pane="orders" hidden><h2>Pedidos de compra</h2><p class="cm-muted">Será criado um pedido em rascunho por fornecedor escolhido, agrupando os respectivos produtos. Nenhum estoque, contas a pagar ou documento fiscal será gerado.</p><p><button class="cm-action" id="cm-generate" <?=($readonly||!$items)?'disabled':''?>>Gerar pedidos em rascunho</button></p><div class="cm-grid"><table><thead><tr><th>Pedido</th><th>Fornecedor</th><th>Itens</th><th>Total (R$)</th><th>Status</th></tr></thead><tbody><?php foreach($orders as $o):?><tr><td>#<?=(int)$o['id']?></td><td><?=cm_h($o['fornecedor_nome'])?></td><td><?=(int)$o['itens']?></td><td><?=number_format((float)$o['total'],2,',','.')?></td><td><?=cm_h($o['status'])?></td></tr><?php endforeach;?><?php if(!$orders):?><tr><td colspan="5">Pedidos ainda não gerados.</td></tr><?php endif;?></tbody></table></div></section>
<?php endif;?></div>
<script>
(()=>{const csrf=<?=json_encode($csrf)?>,cot=<?= (int)$cot?>;
const tabs=[...document.querySelectorAll('.cm-tab')];function show(p){tabs.forEach(b=>b.setAttribute('aria-selected',String(b.dataset.pane===p)));document.querySelectorAll('.cm-pane').forEach(x=>x.hidden=x.dataset.pane!==p);}
tabs.forEach(b=>b.addEventListener('click',()=>show(b.dataset.pane)));
async function send(data){data.set('csrf',csrf);data.set('cotacao_id',String(cot));const r=await fetch('compras_cotacoes_matriz.php',{method:'POST',credentials:'same-origin',body:data});let j;try{j=await r.json()}catch(e){throw Error('Falha no servidor. Consulte o log PHP.')}if(!r.ok||!j.ok)throw Error(j.message||'Erro ao salvar');return j;}
const supplierModal=document.getElementById('cm-supplier-modal'),supplierQuery=document.getElementById('cm-supplier-query'),supplierResults=document.getElementById('cm-supplier-results'),supplierStatus=document.getElementById('cm-supplier-status');
const supplierOpen=document.getElementById('cm-supplier-open');let supplierFound=[],supplierIndex=-1,supplierAbort=null,supplierBusy=false;
function openSupplier(){if(!supplierModal||!supplierOpen||supplierOpen.disabled)return;supplierModal.hidden=false;supplierQuery.value='';supplierResults.replaceChildren();supplierFound=[];supplierIndex=-1;supplierStatus.textContent='Digite para pesquisar fornecedores cadastrados.';supplierQuery.focus();}
function closeSupplier(){if(!supplierModal)return;supplierModal.hidden=true;supplierAbort?.abort();supplierOpen?.focus();}
function moveSupplier(index){if(!supplierFound.length)return;supplierIndex=(index+supplierFound.length)%supplierFound.length;const options=[...supplierResults.querySelectorAll('.cm-supplier-choice')];options.forEach((b,i)=>b.classList.toggle('is-selected',i===supplierIndex));options[supplierIndex]?.scrollIntoView({block:'nearest'});}
async function findSuppliers(){const q=supplierQuery.value.trim();supplierFound=[];supplierIndex=-1;supplierResults.replaceChildren();if(!q){supplierStatus.textContent='Digite o nome ou CNPJ.';return;}supplierAbort?.abort();supplierAbort=new AbortController();supplierStatus.textContent='Pesquisando...';try{const url='compras_cotacoes_matriz.php?lookup=suppliers&q='+encodeURIComponent(q);const r=await fetch(url,{credentials:'same-origin',signal:supplierAbort.signal});const data=await r.json().catch(()=>null);if(!r.ok||!data?.ok)throw Error(data?.message||'Pesquisa indisponível (HTTP '+r.status+').');supplierFound=data.items||[];supplierFound.forEach((person,i)=>{const b=document.createElement('button');b.type='button';b.className='cm-supplier-choice';b.textContent=String(person.name||'')+(person.document?' — '+person.document:'');b.addEventListener('click',()=>{moveSupplier(i);addSupplier()});b.addEventListener('dragstart',e=>e.preventDefault());supplierResults.appendChild(b)});supplierStatus.textContent=supplierFound.length?supplierFound.length+' fornecedor(es) encontrado(s).':'Nenhum fornecedor encontrado.';if(supplierFound.length)moveSupplier(0);}catch(e){if(e.name!=='AbortError')supplierStatus.textContent=e.message;}}
async function addSupplier(){const person=supplierFound[supplierIndex];if(supplierBusy||!person)return;supplierBusy=true;supplierStatus.textContent='Adicionando '+person.name+'...';try{const d=new FormData();d.set('action','add_supplier');d.set('supplier_id',String(person.id));await send(d);location.reload()}catch(e){supplierStatus.textContent=e.message;supplierBusy=false;}}
supplierOpen?.addEventListener('click',openSupplier);document.getElementById('cm-supplier-close')?.addEventListener('click',closeSupplier);document.getElementById('cm-supplier-run')?.addEventListener('click',findSuppliers);
supplierQuery?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();findSuppliers()}else if(e.key==='ArrowDown'&&supplierFound.length){e.preventDefault();moveSupplier(Math.max(0,supplierIndex));supplierResults.querySelectorAll('.cm-supplier-choice')[supplierIndex]?.focus()}});
supplierResults?.addEventListener('keydown',e=>{if(e.key==='ArrowDown'||e.key==='ArrowUp'){e.preventDefault();moveSupplier(supplierIndex+(e.key==='ArrowDown'?1:-1));supplierResults.querySelectorAll('.cm-supplier-choice')[supplierIndex]?.focus()}else if(e.key==='Enter'){e.preventDefault();addSupplier()}});
document.addEventListener('keydown',e=>{if(e.key==='Escape'&&!supplierModal?.hidden){e.preventDefault();closeSupplier();return;}if(e.key==='F8'&&!e.ctrlKey&&!e.altKey&&!e.metaKey&&document.querySelector('.cm-pane[data-pane=suppliers]')?.hidden===false){e.preventDefault();openSupplier();}});
window.addEventListener('message',e=>{if(e.origin===location.origin&&e.data==='nexa-open-supplier-f8')openSupplier()});
document.querySelectorAll('.cm-remove').forEach(b=>b.addEventListener('click',async()=>{if(!confirm('Remover fornecedor, preços e escolhas vinculadas?'))return;const d=new FormData();d.set('action','remove_supplier');d.set('nome',b.dataset.name);try{await send(d);location.reload()}catch(err){alert(err.message)}}));
// Comparação visual sem alterar qualquer valor da ficha.
document.querySelectorAll('.cm-proposal tbody tr').forEach(row=>{
 const price=row.querySelector('input[name^="precos["]'),discount=row.querySelector('input[name^="descontos["]'),label=row.querySelector('.cm-proposal-feedback');
 if(!price||!discount||!label)return;
 const fmt=n=>n.toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
 const update=()=>{
  const value=Number(price.value),pct=Number(discount.value),last=Number(label.dataset.last),min=Number(label.dataset.min);
  label.classList.remove('cm-price-high','cm-price-low');
  if(!price.value||!Number.isFinite(value)||value<=0||!Number.isFinite(pct)||pct<0||pct>100){label.textContent='Informe o preço';return;}
  const net=value*(1-pct/100);
  if(label.dataset.last!==''&&last>0){const diff=(net/last-1)*100;label.textContent=(diff>0?'+':'')+fmt(diff)+'% vs último';if(diff>0.01)label.classList.add('cm-price-high');else if(diff<-.01)label.classList.add('cm-price-low');}
  else if(label.dataset.min!==''&&min>0){const diff=(net/min-1)*100;label.textContent=(diff>0?'+':'')+fmt(diff)+'% vs menor histórico';if(diff>0.01)label.classList.add('cm-price-high');else if(diff<-.01)label.classList.add('cm-price-low');}
  else label.textContent='Sem referência histórica';
 };
 price.addEventListener('input',update);discount.addEventListener('input',update);update();
});
document.querySelectorAll('.cm-proposal').forEach(f=>f.addEventListener('submit',async e=>{e.preventDefault();const d=new FormData(f);d.set('action','save_prices');d.set('nome',f.dataset.name);try{await send(d);location.href=location.pathname+location.search+'#proposals';location.reload()}catch(err){alert(err.message)}}));
document.querySelectorAll('.cm-choose').forEach(x=>x.addEventListener('change',async()=>{const d=new FormData();d.set('action','choose');d.set('lista_item_id',x.dataset.item);d.set('nome',x.value);try{await send(d)}catch(err){alert(err.message);location.reload()}}));
document.querySelector('#cm-generate')?.addEventListener('click',async()=>{const names=[...document.querySelectorAll('.cm-choose:checked')];const ids=[...new Set([...document.querySelectorAll('.cm-choose')].map(x=>x.dataset.item))];if(names.length!==ids.length){alert('Informe propostas e selecione fornecedores para todos os itens.');return;}if(!confirm('Gerar os pedidos de compra em RASCUNHO conforme os fornecedores selecionados?'))return;try{const d=new FormData();d.set('action','generate_orders');await send(d);location.reload()}catch(err){alert(err.message)}});
if(location.hash==='#proposals')show('proposals');
if(window.parent!==window){const tab=location.hash.slice(1);if(['suppliers','proposals','compare','orders'].includes(tab))show(tab);}
})();
</script>
<?php if(!$embedded) admin_footer(); ?>
