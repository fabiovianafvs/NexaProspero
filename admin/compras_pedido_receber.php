<?php
declare(strict_types=1);
/** Nexa Compras 2.8: recebimento administrativo, sem estoque/financeiro/fiscal. */
require __DIR__.'/_bootstrap.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();
require_once __DIR__.'/../config/access_control.php';
$tenant = (int) COMPANY_ID;
if (!access_has_permission($pdo, $tenant, 'compras_pedidos')) { http_response_code(403); exit('Sem permissão de consultar pedidos de compra.'); }
$canReceive = access_has_permission($pdo, $tenant, 'compras_pedidos_receber');
$companyId = $tenant;
if (function_exists('multi_company_feature_enabled') && multi_company_feature_enabled($pdo, $tenant)) {
    $companyId = function_exists('multi_company_operational_company_id') ? (int) multi_company_operational_company_id($pdo, 'stock_read') : $tenant;
    if ($companyId !== $tenant && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'produtos')) {
        http_response_code(403); exit('Sem permissão para a empresa selecionada.');
    }
}
$userId = function_exists('auth_user_id') ? (int) (auth_user_id() ?? 0) : (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) { http_response_code(403); exit('Sessão inválida.'); }
$pedidoId = filter_var($_GET['pedido'] ?? null, FILTER_VALIDATE_INT, ['options'=>['min_range'=>1]]) ?: 0;
if (!$pedidoId) { http_response_code(400); exit('Informe um pedido.'); }
if (empty($_SESSION['compras_recebimento_csrf'])) $_SESSION['compras_recebimento_csrf'] = bin2hex(random_bytes(32));
$csrf = (string) $_SESSION['compras_recebimento_csrf'];
function cr_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
function cr_qty($q): string { return number_format((float)$q,3,',','.'); }
$message=''; $error='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
    if (!$canReceive) { http_response_code(403); exit('Perfil sem permissão para registrar recebimentos.'); }
    if (!hash_equals($csrf,(string)($_POST['csrf']??''))) { http_response_code(400); exit('Token de segurança inválido.'); }
    if (($_POST['action']??'')!=='receive') { http_response_code(400); exit('Ação inválida.'); }
    $itemId=filter_var($_POST['item_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]) ?: 0;
    $qtyRaw=trim((string)($_POST['quantidade']??''));
    // Aceita vírgula decimal, nunca arredonda silenciosamente a mais de três casas.
    $qtyRaw=str_replace(',','.',$qtyRaw);
    $notes=trim((string)($_POST['observacao']??''));
    if (!$itemId || !preg_match('/^\d{1,13}(?:\.\d{1,3})?$/D',$qtyRaw) || (float)$qtyRaw <= 0 || mb_strlen($notes,'UTF-8')>500) {
        http_response_code(422); $error='Informe quantidade positiva (até três decimais) e observação de até 500 caracteres.';
    } else {
        try {
            $pdo->beginTransaction();
            $st=$pdo->prepare('SELECT status FROM compras_pedidos WHERE id=? AND company_id=? FOR UPDATE');
            $st->execute([$pedidoId,$companyId]);
            if ($st->fetchColumn()!=='aprovado') throw new RuntimeException('Somente pedidos aprovados podem receber mercadorias.');
            $st=$pdo->prepare('SELECT quantidade FROM compras_pedido_itens WHERE id=? AND pedido_id=? AND company_id=? FOR UPDATE');
            $st->execute([$itemId,$pedidoId,$companyId]);
            $planned=$st->fetchColumn();
            if ($planned === false) throw new RuntimeException('Produto não localizado no pedido.');
            $st=$pdo->prepare('SELECT COALESCE(SUM(quantidade),0) FROM compras_pedido_recebimentos WHERE company_id=? AND pedido_id=? AND pedido_item_id=?');
            $st->execute([$companyId,$pedidoId,$itemId]);
            $received=(string)$st->fetchColumn();
            $toInt=static function($v):int { $p=explode('.',number_format((float)$v,3,'.',''));return (int)$p[0]*1000+(int)$p[1]; };
            if ($toInt($qtyRaw)>$toInt($planned)-$toInt($received)) throw new RuntimeException('A quantidade supera o saldo pendente deste produto.');
            $st=$pdo->prepare('INSERT INTO compras_pedido_recebimentos (company_id,pedido_id,pedido_item_id,quantidade,registrado_por,observacao) VALUES (?,?,?,?,?,?)');
            $st->execute([$companyId,$pedidoId,$itemId,$qtyRaw,$userId,$notes!==''?$notes:null]);
            $pdo->commit();
            header('Location: compras_pedido_receber.php?pedido='.$pedidoId.'&salvo=1',true,303); exit;
        } catch(Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[compras_recebimento] '.$e->getMessage());
            $error = $e instanceof RuntimeException ? $e->getMessage() : 'Falha no registro. Confira se a migration 2.8 foi aplicada.';
        }
    }
}
try {
    $st=$pdo->prepare('SELECT id,fornecedor_nome,status,created_at FROM compras_pedidos WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$pedidoId,$companyId]); $pedido=$st->fetch(PDO::FETCH_ASSOC);
    if (!$pedido) { http_response_code(404); exit('Pedido não encontrado nesta empresa.'); }
    $st=$pdo->prepare('SELECT pi.id, pi.produto_id, p.nome, pi.quantidade, COALESCE(SUM(r.quantidade),0) AS recebida FROM compras_pedido_itens pi JOIN produtos p ON p.id=pi.produto_id AND p.company_id=pi.company_id LEFT JOIN compras_pedido_recebimentos r ON r.pedido_item_id=pi.id AND r.pedido_id=pi.pedido_id AND r.company_id=pi.company_id WHERE pi.company_id=? AND pi.pedido_id=? GROUP BY pi.id,pi.produto_id,p.nome,pi.quantidade ORDER BY pi.id');
    $st->execute([$companyId,$pedidoId]);$items=$st->fetchAll(PDO::FETCH_ASSOC);
    $st=$pdo->prepare('SELECT r.id,r.pedido_item_id,r.quantidade,r.recebido_em,r.registrado_por,r.observacao,p.nome FROM compras_pedido_recebimentos r JOIN compras_pedido_itens pi ON pi.id=r.pedido_item_id AND pi.pedido_id=r.pedido_id AND pi.company_id=r.company_id JOIN produtos p ON p.id=pi.produto_id AND p.company_id=pi.company_id WHERE r.company_id=? AND r.pedido_id=? ORDER BY r.id DESC LIMIT 500');
    $st->execute([$companyId,$pedidoId]);$history=$st->fetchAll(PDO::FETCH_ASSOC);
} catch(Throwable $e) { error_log('[compras_recebimento][view] '.$e->getMessage()); http_response_code(500); exit('Não foi possível consultar os recebimentos. Confira se a migration 2.8 foi aplicada e consulte o log PHP.'); }
require __DIR__.'/_chrome.php';
admin_topbar('Recebimento de Compras','produtos');
?>
<style>.cr-wrap{margin:16px auto;width:calc(100% - 32px);font:13px system-ui,sans-serif;color:#18365b}.cr-card{background:white;border:1px solid #dbe6f5;border-radius:9px;padding:16px;margin:12px 0}.cr-table-wrap{max-height:65vh;overflow:auto;border:1px solid #dbe6f5;border-radius:7px}.cr-table{border-collapse:separate;border-spacing:0;width:100%}.cr-table th,.cr-table td{padding:10px;border-bottom:1px solid #e0e8f2;text-align:left}.cr-table th{position:sticky;top:0;background:#eaf2fc;z-index:2}.cr-btn{background:#1759b5;color:white;border:0;border-radius:6px;padding:9px 12px;cursor:pointer}.cr-btn:disabled{opacity:.4;cursor:not-allowed}.cr-input{padding:8px;border:1px solid #c4d4e8;border-radius:6px;max-width:110px}.cr-link{color:#1759b5}.cr-alert{border-left:4px solid #b45309;padding:10px;background:#fffbeb}.cr-number{text-align:right!important;font-variant-numeric:tabular-nums}@media print{nav,aside,header,.sidebar,.topbar,.cr-no-print{display:none!important}.cr-wrap{width:100%}.cr-table-wrap{max-height:none;overflow:visible}}</style>
<main class="cr-wrap"><h1>Recebimentos — Pedido de Compra #<?=(int)$pedidoId?></h1>
<p class="cr-no-print"><a class="cr-link" href="compras_pedidos.php?pedido=<?=(int)$pedidoId?>">← Voltar ao Pedido de Compra</a></p>
<p class="cr-no-print"><a class="cr-link" href="compras_pedido_conciliacao.php?pedido=<?=(int)$pedidoId?>">Conciliação: recebimentos × NF-e vinculadas →</a></p>
<div class="cr-card"><strong>Fornecedor:</strong> <?=cr_h($pedido['fornecedor_nome'])?> · <strong>Situação do pedido:</strong> <?=cr_h($pedido['status'])?><p>Registro de recebimento <strong>administrativo</strong>. Não gera entrada de estoque, títulos financeiros ou documentos fiscais.</p></div>
<?php if(isset($_GET['salvo'])): ?><div class="cr-card" role="status">Recebimento registrado com sucesso.</div><?php endif; ?>
<?php if($error): ?><div class="cr-card cr-alert" role="alert"><?=cr_h($error)?></div><?php endif; ?>
<div class="cr-card"><h2>Produtos e saldo pendente</h2><div class="cr-table-wrap"><table class="cr-table"><thead><tr><th>Produto</th><th class="cr-number">Solicitada</th><th class="cr-number">Recebida</th><th class="cr-number">Pendente</th><th>Situação</th><?php if($canReceive):?><th class="cr-no-print">Registrar entrega</th><?php endif;?></tr></thead><tbody>
<?php $fully=0; $partly=0; foreach($items as $row): $pending=max(0,round((float)$row['quantidade']-(float)$row['recebida'],3));$state=$pending<=0?'Completo':((float)$row['recebida']>0?'Parcial':'Pendente'); if($pending<=0)$fully++;elseif((float)$row['recebida']>0)$partly++; ?>
<tr><td><?=cr_h($row['nome'])?> <small>#<?=(int)$row['produto_id']?></small></td><td class="cr-number"><?=cr_qty($row['quantidade'])?></td><td class="cr-number"><?=cr_qty($row['recebida'])?></td><td class="cr-number"><?=cr_qty($pending)?></td><td><?=cr_h($state)?></td>
<?php if($canReceive):?><td class="cr-no-print"><?php if($pedido['status']==='aprovado' && $pending>0):?><form method="post" onsubmit="return confirm('Confirma o recebimento administrativo? Não haverá movimentação de estoque.');"><input type="hidden" name="csrf" value="<?=cr_h($csrf)?>"><input type="hidden" name="action" value="receive"><input type="hidden" name="item_id" value="<?=(int)$row['id']?>"><input class="cr-input" name="quantidade" inputmode="decimal" value="<?=number_format($pending,3,'.','')?>" required aria-label="Quantidade recebida"><input class="cr-input" name="observacao" maxlength="500" placeholder="Observação" style="max-width:200px"><button type="submit" class="cr-btn">Registrar</button></form><?php else: ?>—<?php endif;?></td><?php endif;?></tr>
<?php endforeach; ?>
<?php if(!$items): ?><tr><td colspan="6">Nenhum produto no pedido.</td></tr><?php endif;?></tbody></table></div><p><strong>Situação geral:</strong> <?=count($items)>0 && $fully===count($items)?'Recebido integralmente':($fully+$partly>0?'Recebimento parcial':'Aguardando recebimento')?>.</p></div>
<div class="cr-card"><h2>Histórico de recebimentos</h2><div class="cr-table-wrap"><table class="cr-table"><thead><tr><th>Data</th><th>Produto</th><th class="cr-number">Quantidade</th><th>Usuário ID</th><th>Observação</th></tr></thead><tbody><?php foreach($history as $h):?><tr><td><?=cr_h(date('d/m/Y H:i',strtotime($h['recebido_em'])))?></td><td><?=cr_h($h['nome'])?></td><td class="cr-number"><?=cr_qty($h['quantidade'])?></td><td><?=(int)$h['registrado_por']?></td><td><?=cr_h($h['observacao'])?></td></tr><?php endforeach;?><?php if(!$history):?><tr><td colspan="5">Nenhum recebimento registrado.</td></tr><?php endif;?></tbody></table></div></div></main>
