<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/finance_module.php';

$cid = (int)COMPANY_ID;
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { header('Location: pedidos.php'); exit; }
try { company_sequence_ensure_schema($pdo, true); } catch (Throwable $e) {}

function pf_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pf_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function pf_dt($v): string { $ts = strtotime((string)$v); return $ts ? date('d/m/Y H:i', $ts) : '-'; }
function pf_safe_return(string $value, string $fallback = 'gestao_pdv.php'): string {
  $value = trim($value);
  if ($value === '' || preg_match('/^https?:\/\//i', $value) || str_contains($value, "\n") || str_contains($value, "\r")) return $fallback;
  return ltrim($value, '/');
}

$returnTo = pf_safe_return((string)($_GET['return_to'] ?? ''), 'gestao_pdv.php');
$order = null;
try { $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1'); $st->execute([$cid, $id]); $order = $st->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) {}
if (!$order) { http_response_code(404); echo 'Pedido não encontrado.'; exit; }
$orderNumber = function_exists('company_sequence_display') ? company_sequence_display($order, 'pedidos') : (string)$id;
$items = [];
try {
  $st = $pdo->prepare('SELECT pi.*, COALESCE(p.nome, CONCAT("Produto #", pi.produto_id)) AS produto_nome FROM pedido_itens pi LEFT JOIN produtos p ON p.company_id = pi.company_id AND p.id = pi.produto_id WHERE pi.company_id = ? AND pi.pedido_id = ? ORDER BY pi.id ASC');
  $st->execute([$cid, $id]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
  try { $st = $pdo->prepare('SELECT * FROM pedido_itens WHERE company_id = ? AND pedido_id = ? ORDER BY id ASC'); $st->execute([$cid, $id]); $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $ignore) {}
}
$whatsapp = preg_replace('/\D+/', '', (string)($order['whatsapp'] ?? ''));

admin_topbar('Pedido finalizado', 'gestao_pdv');
?>
<style>
.pf-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 15px 40px rgba(11,31,91,.08);margin-bottom:14px}.pf-hero{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:16px;align-items:center}.pf-ok{display:inline-flex;align-items:center;justify-content:center;width:54px;height:54px;border-radius:18px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0;font-size:30px;font-weight:950}.pf-hero h1{margin:0;color:var(--admin-primary);font-size:28px}.pf-muted{color:#64748b;line-height:1.45}.pf-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.pf-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}.pf-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;border-color:transparent}.pf-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px}.pf-info{border:1px solid #e5edf6;border-radius:18px;background:#f8fbff;padding:12px}.pf-info span{display:block;font-size:12px;color:#64748b;font-weight:850}.pf-info strong{display:block;margin-top:4px;color:#0b1f5b;overflow-wrap:anywhere}.pf-table{width:100%;border-collapse:separate;border-spacing:0}.pf-table th,.pf-table td{padding:10px;border-bottom:1px solid #e5edf6;text-align:left}.pf-table th{font-size:12px;text-transform:uppercase;color:#334155;background:#f8fbff}.pf-total{text-align:right;font-size:24px;color:#047857;font-weight:950}.pf-msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.pf-msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:900px){.pf-hero{grid-template-columns:1fr}.pf-actions{justify-content:flex-start}.pf-grid{grid-template-columns:1fr 1fr}.pf-table,.pf-table thead,.pf-table tbody,.pf-table th,.pf-table td,.pf-table tr{display:block}.pf-table thead{display:none}.pf-table tr{border:1px solid #e5edf6;border-radius:16px;margin-bottom:8px;padding:8px}.pf-table td{border-bottom:0}.pf-table td:before{content:attr(data-label);display:block;font-size:11px;text-transform:uppercase;color:#64748b;font-weight:900}}@media(max-width:560px){.pf-grid{grid-template-columns:1fr}}
</style>
<?php if (!empty($_GET['err'])): ?><div class="pf-msg err">Pedido gravado, mas a emissão fiscal retornou: <?= pf_h($_GET['err']) ?></div><?php endif; ?>
<div class="pf-card pf-hero">
  <div style="display:flex;gap:14px;align-items:center;min-width:0"><div class="pf-ok">✓</div><div><h1>Pedido #<?= pf_h($orderNumber) ?> salvo com sucesso</h1><p class="pf-muted" style="margin:5px 0 0">Confira o pedido abaixo. Use os botões de impressão e WhatsApp para entregar o comprovante ao cliente.</p></div></div>
  <div class="pf-actions">
    <?php if ($whatsapp): ?>
      <form method="post" action="pedido_whatsapp.php" style="display:inline"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="mode" value="api"><input type="hidden" name="return_to" value="<?= pf_h('pedido_finalizado.php?id=' . $id . '&return_to=' . rawurlencode($returnTo)) ?>"><button class="pf-btn" type="submit">WhatsApp</button></form>
    <?php endif; ?>
    <a class="pf-btn" href="email_documento.php?type=pedido&id=<?= $id ?>&return_to=<?= rawurlencode('pedido_finalizado.php?id=' . $id . '&return_to=' . rawurlencode($returnTo)) ?>">✉ E-mail</a>
    <a class="pf-btn primary" target="_blank" rel="noopener" href="pedido_print.php?id=<?= $id ?>">Imprimir</a>
    <a class="pf-btn" href="<?= pf_h($returnTo) ?>">Sair</a>
  </div>
</div>

<div class="pf-card">
  <div class="pf-grid">
    <div class="pf-info"><span>Cliente</span><strong><?= pf_h($order['nome_cliente'] ?? 'Consumidor final') ?></strong></div>
    <div class="pf-info"><span>WhatsApp</span><strong><?= pf_h($order['whatsapp'] ?? '-') ?></strong></div>
    <div class="pf-info"><span>Pagamento</span><strong><?= pf_h($order['forma_pagamento'] ?? '-') ?></strong></div>
    <div class="pf-info"><span>Status</span><strong><?= pf_h($order['status'] ?? '-') ?></strong></div>
    <div class="pf-info"><span>Tipo</span><strong><?= pf_h($order['tipo_entrega'] ?? '-') ?></strong></div>
    <div class="pf-info"><span>Data</span><strong><?= pf_h(pf_dt($order['created_at'] ?? '')) ?></strong></div>
    <div class="pf-info"><span>Endereço</span><strong><?= pf_h(($order['endereco'] ?? '') ?: '-') ?></strong></div>
    <div class="pf-info"><span>Total</span><strong><?= pf_h(pf_money($order['total'] ?? 0)) ?></strong></div>
  </div>
</div>

<div class="pf-card">
  <h2 style="margin:0 0 10px;color:var(--admin-primary)">Itens do pedido</h2>
  <table class="pf-table"><thead><tr><th>Produto</th><th>Qtd.</th><th>Unitário</th><th>Total</th></tr></thead><tbody>
    <?php foreach ($items as $it): ?>
      <tr><td data-label="Produto"><?= pf_h($it['produto_nome'] ?? $it['nome'] ?? ('Produto #' . ($it['produto_id'] ?? ''))) ?></td><td data-label="Qtd."><?= pf_h(number_format((float)($it['qtd'] ?? 0), 3, ',', '.')) ?></td><td data-label="Unitário"><?= pf_h(pf_money($it['preco_unit'] ?? 0)) ?></td><td data-label="Total"><?= pf_h(pf_money($it['total'] ?? 0)) ?></td></tr>
    <?php endforeach; ?>
    <?php if (!$items): ?><tr><td colspan="4" class="pf-muted">Nenhum item localizado para este pedido.</td></tr><?php endif; ?>
  </tbody></table>
  <div class="pf-total">Total: <?= pf_h(pf_money($order['total'] ?? 0)) ?></div>
</div>
<div class="pf-actions" style="margin-bottom:20px"><a class="pf-btn" href="gestao_pdv.php">Novo Pedido PDV</a><a class="pf-btn" href="pedidos.php">Consultar pedidos</a><?php if (strpos($returnTo, 'gestao_comandas.php') !== false): ?><a class="pf-btn" href="gestao_comandas.php">Voltar para comandas</a><?php endif; ?></div>
<?php admin_footer(); ?>
