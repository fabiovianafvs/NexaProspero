<?php
declare(strict_types=1);
/** Nexa Compras 1.8 — listas em rascunho, isoladas do fiscal, financeiro e estoque. */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
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
$selectedId=max(0,(int)($_GET['id']??0));
// Compatibilidade com links existentes do Assistente e de versões anteriores.
if ($selectedId > 0) {
    header('Location: compras_lista_editar.php?id='.$selectedId, true, 302);
    exit;
}
$nomeFiltro=trim((string)($_GET['nome']??''));
if (mb_strlen($nomeFiltro)>120) $nomeFiltro=mb_substr($nomeFiltro,0,120);
$inicio=(string)($_GET['inicio']??'');
$fim=(string)($_GET['fim']??'');
$validDate=static function(string $d):bool {
    if ($d==='') return true;
    $dt=DateTimeImmutable::createFromFormat('!Y-m-d',$d);
    return $dt !== false && $dt->format('Y-m-d')===$d;
};
if (!$validDate($inicio)) $inicio='';
if (!$validDate($fim)) $fim='';
if ($inicio!=='' && $fim!=='' && $inicio>$fim) { $tmp=$inicio; $inicio=$fim; $fim=$tmp; }
$lists=[];
if ($ready) {
    $where=['l.company_id=?'];$args=[$companyId];
    if ($inicio!=='') {$where[]='l.created_at >= ?';$args[]=$inicio.' 00:00:00';}
    if ($fim!=='') {$where[]='l.created_at < DATE_ADD(?, INTERVAL 1 DAY)';$args[]=$fim;}
    if ($nomeFiltro!=='') {
        $where[]="l.nome LIKE ? ESCAPE '!'";
        $args[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$nomeFiltro).'%';
    }
    $sql='SELECT l.id,l.nome,l.status,l.created_at,l.updated_at,COUNT(i.id) itens
          FROM compras_listas l LEFT JOIN compras_lista_itens i ON i.lista_id=l.id AND i.company_id=l.company_id
          WHERE '.implode(' AND ',$where).'
          GROUP BY l.id,l.nome,l.status,l.created_at,l.updated_at
          ORDER BY l.id DESC LIMIT 200';
    $stmt=$pdo->prepare($sql);$stmt->execute($args);$lists=$stmt->fetchAll(PDO::FETCH_ASSOC);
}
admin_topbar('Listas de Compras','produtos');
?>
<style>
.cl-wrap{font-family:inherit;font-size:inherit;margin:18px auto;width:calc(100% - 32px);max-width:none;color:#1d355b}
.cl-wrap h1{color:#09245d}.cl-box{background:#fff;border:1px solid #dce6f3;border-radius:10px;padding:16px;margin:14px 0}
.cl-filters{display:flex;align-items:end;flex-wrap:wrap;gap:12px}.cl-field{display:flex;flex-direction:column;gap:5px}
.cl-field label{font-size:13px;color:#294974;font-weight:600}.cl-field input{font:inherit;border:1px solid #c4d3e8;border-radius:6px;padding:8px 10px;min-height:38px}
.cl-btn{font:inherit;border:0;background:#1557ba;color:#fff;border-radius:7px;padding:9px 16px;cursor:pointer;min-height:38px}
.cl-secondary{color:#1557ba;text-decoration:none;padding:8px}.cl-scroll{width:100%;overflow:auto}
.cl-wrap table{border-collapse:collapse;width:100%;font-size:13px}.cl-wrap th{background:#edf4fd;color:#193c76;text-align:left}
.cl-wrap td,.cl-wrap th{padding:10px;border-bottom:1px solid #e2e8f0}.cl-wrap a{color:#1254b5}
.cl-actions{white-space:nowrap}
</style>
<div class="cl-wrap">
<h1>Listas de Compras</h1>
<p><a href="compras_assistente.php">← Assistente de Compras / Nova lista</a></p>
<?php if(!$ready):?>
<div class="cl-box" role="alert">A estrutura do banco ainda não foi atualizada. Aplique a migration Compras 1.8 no Atualizador do Banco.</div>
<?php else: ?>
<div class="cl-box">
<form method="get" class="cl-filters" action="compras_listas.php">
 <div class="cl-field"><label for="cl-inicio">Data inicial</label><input type="date" id="cl-inicio" name="inicio" value="<?=cl_h($inicio)?>"></div>
 <div class="cl-field"><label for="cl-fim">Data final</label><input type="date" id="cl-fim" name="fim" value="<?=cl_h($fim)?>"></div>
 <div class="cl-field" style="flex:1;min-width:220px"><label for="cl-nome">Nome da lista</label><input type="search" id="cl-nome" name="nome" placeholder="Pesquisar pelo nome" value="<?=cl_h($nomeFiltro)?>"></div>
 <button class="cl-btn" type="submit">Filtrar</button><a class="cl-secondary" href="compras_listas.php">Limpar</a>
</form>
</div>
<div class="cl-box"><h2>Listas existentes</h2>
<?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="cl-scroll"><table<?=nexa_grid_auto_attrs($pdo,'auto_compras_listas')?>><thead><tr><th>Nº</th><th>Nome</th><th>Status</th><th>Itens</th><th>Criação</th><th>Ações</th></tr></thead><tbody>
<?php foreach($lists as $l):?><tr>
<td><?= (int)$l['id']?></td><td><?=cl_h($l['nome'])?></td><td><?=cl_h($l['status'])?></td><td><?= (int)$l['itens']?></td>
<td><?=cl_h($l['created_at'])?></td><td class="cl-actions"><a href="compras_lista_editar.php?id=<?= (int)$l['id']?>">Visualizar</a></td>
</tr><?php endforeach;?>
<?php if(!$lists):?><tr><td colspan="6">Nenhuma lista encontrada para os filtros informados.</td></tr><?php endif;?></tbody></table></div>
</div>
<?php endif;?></div>
<?php admin_footer(); ?>
