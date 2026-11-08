<?php
declare(strict_types=1);
/** Nexa Compras 2.4: consulta e impressão de pedidos, sem mutações. */
require __DIR__ . '/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
$companyId = (int)COMPANY_ID;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo, $companyId)) {
    $companyId = function_exists('multi_company_operational_company_id')
        ? (int)multi_company_operational_company_id($pdo, 'stock_read') : $companyId;
    if ($companyId !== (int)COMPANY_ID && function_exists('multi_company_context_permission_allowed')
        && !multi_company_context_permission_allowed($pdo, 'produtos')) {
        http_response_code(403); exit('Sem permissão para a empresa selecionada.');
    }
}
$userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { http_response_code(403); exit('Sessão inválida.'); }
require_once __DIR__.'/../config/access_control.php';
if (!access_has_permission($pdo, (int)COMPANY_ID, 'compras_pedidos')) { http_response_code(403); exit('Acesso não autorizado aos Pedidos de Compra.'); }
$canApprove = access_has_permission($pdo, (int)COMPANY_ID, 'compras_pedidos_aprovar');
if (empty($_SESSION['compras_approve_csrf'])) $_SESSION['compras_approve_csrf'] = bin2hex(random_bytes(32));
$approveCsrf = (string)$_SESSION['compras_approve_csrf'];
$approvalMessage = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'schedule_delivery') {
  if (!$canApprove) { http_response_code(403); exit('Sem permissão para definir previsão de entrega.'); }
  if (!hash_equals($approveCsrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token de segurança inválido.'); }
  $targetId = filter_var($_POST['pedido_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
  $planned = trim((string)($_POST['data_prevista_entrega'] ?? ''));
  $dateValid = $planned === '' || (preg_match('/^\d{4}-\d{2}-\d{2}$/D', $planned) && ($dt=DateTimeImmutable::createFromFormat('!Y-m-d', $planned)) && $dt->format('Y-m-d') === $planned);
  if (!$targetId || !$dateValid) { http_response_code(400); exit('Pedido ou data inválidos.'); }
  try {
    $update = $pdo->prepare("UPDATE compras_pedidos SET data_prevista_entrega=? WHERE company_id=? AND id=? AND status='aprovado'");
    $update->execute([$planned === '' ? null : $planned, $companyId, $targetId]);
    $check = $pdo->prepare("SELECT id FROM compras_pedidos WHERE company_id=? AND id=? AND status='aprovado'");
    $check->execute([$companyId, $targetId]);
    if (!$check->fetchColumn()) throw new RuntimeException('Somente pedidos aprovados podem receber previsão.');
    header('Location: compras_pedidos.php?pedido='.$targetId.'&previsao=1',true,303); exit;
  } catch (Throwable $e) {
    error_log('[compras_pedidos][schedule_delivery] '.$e->getMessage());
    $approvalMessage = 'Não foi possível salvar a previsão. Confirme a atualização de banco da Fase 3.5.';
  }
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'approve') {
  if (!$canApprove) { http_response_code(403); exit('Sem permissão para aprovar pedidos de compra.'); }
  if (!hash_equals($approveCsrf, (string)($_POST['csrf'] ?? ''))) { http_response_code(400); exit('Token de segurança inválido. Atualize a página.'); }
  $toApprove = filter_var($_POST['pedido_id'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
  if (!$toApprove || ($_POST['action'] ?? '') !== 'approve') { http_response_code(400); exit('Solicitação inválida.'); }
  $observation = trim((string)($_POST['observacao'] ?? ''));
  if (mb_strlen($observation, 'UTF-8') > 500) { http_response_code(400); exit('Observação acima de 500 caracteres.'); }
  try {
    $pdo->beginTransaction();
    $q=$pdo->prepare('SELECT status FROM compras_pedidos WHERE id=? AND company_id=? FOR UPDATE');
    $q->execute([$toApprove,$companyId]);$old=$q->fetchColumn();
    if ($old !== 'rascunho') throw new RuntimeException('Pedido não encontrado ou já aprovado.');
    $q=$pdo->prepare('SELECT COUNT(*) FROM compras_pedido_itens WHERE pedido_id=? AND company_id=?');
    $q->execute([$toApprove,$companyId]);
    if ((int)$q->fetchColumn() < 1) throw new RuntimeException('Não é possível aprovar um pedido sem produtos.');
    // Falha proposital se migration ainda não aplicada: nenhuma aprovação sem auditoria.
    $q=$pdo->prepare('INSERT INTO compras_pedidos_aprovacoes (company_id,pedido_id,user_id,status_anterior,status_novo,aprovado_em,observacao) VALUES (?,?,?,\'rascunho\',\'aprovado\',NOW(),?)');
    $q->execute([$companyId,$toApprove,$userId,$observation === '' ? null : $observation]);
    $q=$pdo->prepare("UPDATE compras_pedidos SET status='aprovado' WHERE id=? AND company_id=? AND status='rascunho'");
    $q->execute([$toApprove,$companyId]);
    if ($q->rowCount()!==1) throw new RuntimeException('O pedido foi alterado durante a aprovação.');
    $pdo->commit();
    header('Location: compras_pedidos.php?pedido='.$toApprove.'&aprovado=1',true,303);exit;
  } catch(Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[compras_pedidos][approve] '.$e->getMessage());
    $approvalMessage = 'Não foi possível aprovar. Confira a situação do pedido e se a migration 2.7 foi aplicada.';
  }
}

function cp_h($v):string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function cp_date($s):string { return $s ? date('d/m/Y H:i', strtotime((string)$s)) : '—'; }
$pedidoId = filter_var($_GET['pedido'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$cotacaoId = filter_var($_GET['cotacao'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
$nome = trim((string)($_GET['fornecedor'] ?? ''));
$nome = mb_substr($nome, 0, 120);
$de = trim((string)($_GET['de'] ?? ''));
$ate = trim((string)($_GET['ate'] ?? ''));
if ($de !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $de)) $de='';
if ($ate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $ate)) $ate='';
$status = (string)($_GET['status'] ?? '');
if (!in_array($status, ['', 'rascunho', 'aprovado'], true)) $status='';
$erro = null; $pedido = null; $itens = []; $lista=[]; $approval = null;
try {
    if ($pedidoId) {
        $s=$pdo->prepare('SELECT p.id,p.company_id,p.cotacao_id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega,c.lista_id,l.nome lista_nome FROM compras_pedidos p JOIN compras_cotacoes c ON c.id=p.cotacao_id AND c.company_id=p.company_id JOIN compras_listas l ON l.id=c.lista_id AND l.company_id=p.company_id WHERE p.id=? AND p.company_id=? LIMIT 1');
        $s->execute([$pedidoId,$companyId]); $pedido=$s->fetch(PDO::FETCH_ASSOC);
        if (!$pedido) { http_response_code(404); $erro='Pedido não encontrado para esta empresa.'; }
        else {
            $s=$pdo->prepare('SELECT i.produto_id,pr.nome, i.quantidade,i.preco_unitario,i.desconto_percentual FROM compras_pedido_itens i JOIN produtos pr ON pr.id=i.produto_id AND pr.company_id=i.company_id WHERE i.pedido_id=? AND i.company_id=? ORDER BY i.id');
            $s->execute([$pedidoId,$companyId]);$itens=$s->fetchAll(PDO::FETCH_ASSOC);
            try { $a=$pdo->prepare('SELECT user_id,aprovado_em,observacao FROM compras_pedidos_aprovacoes WHERE company_id=? AND pedido_id=? LIMIT 1'); $a->execute([$companyId,$pedidoId]); $approval=$a->fetch(PDO::FETCH_ASSOC) ?: null; } catch(Throwable $ignored) {}

        }
    } else {
        $sql='SELECT p.id,p.cotacao_id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega,COUNT(i.id) qtd_itens,COALESCE(SUM(i.quantidade*i.preco_unitario*(1-i.desconto_percentual/100)),0) total FROM compras_pedidos p LEFT JOIN compras_pedido_itens i ON i.pedido_id=p.id AND i.company_id=p.company_id WHERE p.company_id=?';
        $par=[$companyId];
        if ($cotacaoId) {$sql.=' AND p.cotacao_id=?';$par[]=$cotacaoId;}
        if ($nome!=='') {$sql.=' AND CONVERT(p.fornecedor_nome USING utf8mb4) COLLATE utf8mb4_general_ci LIKE ?';$par[]='%'.str_replace(['!','%','_'],['!!','!%','!_'],$nome).'%';}
        if ($status!=='') {$sql.=' AND p.status=?';$par[]=$status;}
        if ($de!=='') {$sql.=' AND p.created_at>=?';$par[]=$de.' 00:00:00';}
        if ($ate!=='') {$sql.=' AND p.created_at<?';$par[]=date('Y-m-d', strtotime($ate.' +1 day')).' 00:00:00';}
        $sql.=' GROUP BY p.id,p.cotacao_id,p.fornecedor_nome,p.status,p.created_at,p.data_prevista_entrega ORDER BY p.id DESC LIMIT 300';
        $s=$pdo->prepare($sql);$s->execute($par);$lista=$s->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) { error_log('[compras_pedidos] '.$e->getMessage());$erro='Não foi possível consultar os pedidos. Verifique o log PHP.'; }
require __DIR__.'/_chrome.php';
admin_topbar('Pedidos de Compra','produtos');
?>
<style>
.cp-wrap{width:calc(100% - 32px);margin:16px auto;color:#19365a;font-size:13px;font-family:inherit}.cp-card{border:1px solid #d8e3f1;background:#fff;border-radius:9px;padding:16px;margin:12px 0}.cp-row{display:flex;flex-wrap:wrap;gap:10px;align-items:end}.cp-field{display:flex;flex-direction:column;gap:4px}.cp-field input,.cp-field select{padding:7px 9px;border:1px solid #becde1;border-radius:6px}.cp-send{display:flex;flex-wrap:wrap;gap:8px;align-items:center}.cp-btn{display:inline-block;background:#1758b6;border:0;color:#fff;padding:9px 12px;border-radius:6px;text-decoration:none;cursor:pointer;font:inherit}.cp-link{color:#1758b6}.cp-scroll{max-height:68vh;overflow:auto;border:1px solid #dae4f0;border-radius:8px}.cp-table{width:100%;border-collapse:separate;border-spacing:0;font-size:12px}.cp-table th,.cp-table td{padding:9px;border-bottom:1px solid #e7edf5;text-align:left}.cp-table th{position:sticky;top:0;background:#ebf2fc;z-index:2}.cp-num{text-align:right!important;font-variant-numeric:tabular-nums}.cp-total{font-size:17px;text-align:right;font-weight:700}.cp-muted{color:#64748b}.cp-title{font-size:20px;margin:0 0 8px;color:#12376e}@media print{.cp-no-print,nav,aside,header,.sidebar,.topbar{display:none!important}.cp-wrap{width:100%;margin:0}.cp-card{border:0;padding:0}.cp-scroll{max-height:none;overflow:visible;border:0}.cp-table th{position:static}body{background:#fff!important}}
</style>
<main class="cp-wrap">
<h1 class="cp-title">Pedidos de Compra<?= $pedido ? ' #'.(int)$pedido['id'] : '' ?></h1>
<p class="cp-no-print"><a class="cp-link" href="compras_listas.php">← Listas de Compras</a> · <a class="cp-link" href="compras_pedidos.php">Consultar pedidos</a></p>
<?php if($erro): ?><div class="cp-card" role="alert"><?=cp_h($erro)?></div><?php endif; ?>
<?php if($approvalMessage): ?><div class="cp-card" role="alert"><?=cp_h($approvalMessage)?></div><?php endif; ?>
<?php if(isset($_GET['aprovado']) && $_GET['aprovado']==='1'): ?><div class="cp-card" role="status">Pedido aprovado e registrado no histórico.</div><?php endif; ?>
<?php if($pedido): ?>
<div class="cp-card"><div class="cp-row" style="justify-content:space-between"><div><strong>Fornecedor:</strong> <?=cp_h($pedido['fornecedor_nome'])?><br><strong>Lista:</strong> <?=cp_h($pedido['lista_nome'])?><br><strong>Cotação:</strong> #<?=(int)$pedido['cotacao_id']?><br><strong>Emissão:</strong> <?=cp_date($pedido['created_at'])?><br><strong>Previsão de entrega:</strong> <?=!empty($pedido['data_prevista_entrega'])?date('d/m/Y',strtotime($pedido['data_prevista_entrega'])):'Não informada'?><br><strong>Situação:</strong> <?=cp_h($pedido['status'])?></div><div class="cp-no-print"><div class="cp-send"><a class="cp-btn" href="compras_pedido_receber.php?pedido=<?=(int)$pedido['id']?>">📦 Recebimentos</a><a class="cp-btn" href="compras_pedido_enviar.php?pedido=<?=(int)$pedido['id']?>&amp;canal=whatsapp">💬 WhatsApp</a><a class="cp-btn" href="compras_pedido_enviar.php?pedido=<?=(int)$pedido['id']?>&amp;canal=email">✉ E-mail</a><a class="cp-btn" href="compras_pedido_enviar.php?pedido=<?=(int)$pedido['id']?>&amp;canal=pdf">⬇ PDF</a><button class="cp-btn" type="button" onclick="window.print()">🖨 Imprimir</button> <a class="cp-link" href="compras_lista_editar.php?id=<?=(int)$pedido['lista_id']?>">Voltar à lista</a></div></div></div></div>
<?php if ($canApprove && $pedido['status'] === 'aprovado'): ?>
<form method="post" class="cp-card cp-no-print cp-row" onsubmit="return confirm('Salvar a previsão de entrega deste pedido?');">
  <input type="hidden" name="action" value="schedule_delivery"><input type="hidden" name="pedido_id" value="<?=(int)$pedido['id']?>"><input type="hidden" name="csrf" value="<?=cp_h($approveCsrf)?>">
  <label class="cp-field"><strong>Previsão de entrega</strong><input type="date" name="data_prevista_entrega" value="<?=cp_h($pedido['data_prevista_entrega'] ?? '')?>"></label>
  <button class="cp-btn" type="submit">Salvar previsão</button><span class="cp-muted">Deixe em branco para remover a previsão.</span>
</form>
<?php endif; ?>
<?php if ($approval): ?><div class="cp-card"><strong>Aprovação:</strong> <?=cp_date($approval['aprovado_em'])?> · <strong>Usuário ID:</strong> <?=(int)$approval['user_id']?><?php if(!empty($approval['observacao'])): ?><p><?=cp_h($approval['observacao'])?></p><?php endif;?></div><?php endif;?>
<?php if ($canApprove && $pedido['status']==='rascunho'): ?>
<form method="post" class="cp-card cp-no-print" onsubmit="return confirm('Confirma a aprovação deste Pedido de Compra? Esta ação será registrada e não movimenta estoque ou financeiro.');">
<strong>Aprovação do Pedido de Compra</strong><p class="cp-muted">Somente pedidos em rascunho. Após a aprovação não será possível aprovar novamente.</p>
<input type="hidden" name="action" value="approve"><input type="hidden" name="pedido_id" value="<?=(int)$pedido['id']?>"><input type="hidden" name="csrf" value="<?=cp_h($approveCsrf)?>">
<label class="cp-field" style="margin:10px 0">Observação (opcional)<input type="text" name="observacao" maxlength="500" placeholder="Observação da aprovação"></label>
<button type="submit" class="cp-btn">✓ Aprovar Pedido</button>
</form>
<?php endif; ?>
<div class="cp-card"><div class="cp-scroll"><table class="cp-table"><thead><tr><th>Produto</th><th class="cp-num">Qtd.</th><th class="cp-num">Preço unit.</th><th class="cp-num">Desconto %</th><th class="cp-num">Total R$</th></tr></thead><tbody>
<?php $total=0.0;foreach($itens as $r):$parcial=(float)$r['quantidade']*(float)$r['preco_unitario']*(1-(float)$r['desconto_percentual']/100);$total+=$parcial;?><tr><td><?=cp_h($r['nome'])?> <span class="cp-muted">#<?=(int)$r['produto_id']?></span></td><td class="cp-num"><?=number_format((float)$r['quantidade'],3,',','.')?></td><td class="cp-num"><?=number_format((float)$r['preco_unitario'],4,',','.')?></td><td class="cp-num"><?=number_format((float)$r['desconto_percentual'],2,',','.')?></td><td class="cp-num"><?=number_format($parcial,2,',','.')?></td></tr><?php endforeach;?></tbody></table></div><p class="cp-total">Total: R$ <?=number_format($total,2,',','.')?></p><p class="cp-muted">Documento interno de compras. Não é documento fiscal nem comprovação de recebimento.</p></div>
<?php elseif(!$pedidoId): ?>
<form class="cp-card cp-row cp-no-print" method="get"><label class="cp-field">Data inicial<input type="date" name="de" value="<?=cp_h($de)?>"></label><label class="cp-field">Data final<input type="date" name="ate" value="<?=cp_h($ate)?>"></label><label class="cp-field">Fornecedor<input type="text" name="fornecedor" value="<?=cp_h($nome)?>" placeholder="Nome do fornecedor"></label><label class="cp-field">Situação<select name="status"><option value="">Todas</option><option value="rascunho" <?=$status==='rascunho'?'selected':''?>>Rascunho</option><option value="aprovado" <?=$status==='aprovado'?'selected':''?>>Aprovado</option></select></label><button class="cp-btn">Filtrar</button></form>
<?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="cp-card"><div class="cp-scroll"><table class="cp-table"<?=nexa_grid_auto_attrs($pdo,'auto_compras_pedidos')?>><thead><tr><th>Pedido</th><th>Cotação</th><th>Fornecedor</th><th>Data</th><th>Prev. entrega</th><th>Situação</th><th class="cp-num">Itens</th><th class="cp-num">Total R$</th><th>Ação</th></tr></thead><tbody><?php foreach($lista as $r):?><tr><td>#<?=(int)$r['id']?></td><td>#<?=(int)$r['cotacao_id']?></td><td><?=cp_h($r['fornecedor_nome'])?></td><td><?=cp_date($r['created_at'])?></td><td><?=empty($r['data_prevista_entrega'])?'—':date('d/m/Y',strtotime($r['data_prevista_entrega']))?></td><td><?=cp_h($r['status'])?></td><td class="cp-num"><?=(int)$r['qtd_itens']?></td><td class="cp-num"><?=number_format((float)$r['total'],2,',','.')?></td><td><a class="cp-link" href="compras_pedidos.php?pedido=<?=(int)$r['id']?>">Visualizar / Imprimir</a></td></tr><?php endforeach;?><?php if(!$lista):?><tr><td colspan="9">Nenhum pedido encontrado.</td></tr><?php endif;?></tbody></table></div></div>
<?php endif;?></main>
