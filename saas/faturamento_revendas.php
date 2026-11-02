<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/resellers.php';

reseller_ensure_schema($pdo);
$msg = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'generate');
    if ($action === 'status') {
      $invoiceId = (int)($_POST['invoice_id'] ?? 0);
      $status = (string)($_POST['status'] ?? '');
      if (!in_array($status, ['draft', 'issued', 'paid', 'canceled'], true)) {
        throw new RuntimeException('Status de fatura inválido.');
      }
      $st = $pdo->prepare('UPDATE saas_reseller_invoices SET status=?,updated_at=NOW() WHERE id=?');
      $st->execute([$status, $invoiceId]);
      $msg = 'Status da fatura atualizado.';
    } else {
      $resellerId = (int)($_POST['reseller_company_id'] ?? 0);
      $month = trim((string)($_POST['reference_month'] ?? date('Y-m')));
      $due = trim((string)($_POST['due_date'] ?? ''));
      $id = reseller_generate_invoice($pdo, $resellerId, $month, $due !== '' ? $due : null);
      $msg = 'Fatura consolidada gerada ou recalculada com sucesso. Código interno #' . $id . '.';
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$revendas = $pdo->query("SELECT id,name,subdomain FROM companies WHERE is_reseller=1 AND reseller_status='active' ORDER BY name")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];
$faturas = $pdo->query("SELECT f.*,c.name AS reseller_name,
    (SELECT COUNT(*) FROM saas_reseller_invoice_items i WHERE i.invoice_id=f.id) AS items_count
  FROM saas_reseller_invoices f
  INNER JOIN companies c ON c.id=f.reseller_company_id
  ORDER BY f.reference_month DESC,f.id DESC")
  ->fetchAll(PDO::FETCH_ASSOC) ?: [];

$viewId = (int)($_GET['id'] ?? 0);
$items = [];
if ($viewId > 0) {
  $st = $pdo->prepare("SELECT i.*,c.name AS client_name,m.name AS module_name
    FROM saas_reseller_invoice_items i
    INNER JOIN companies c ON c.id=i.client_company_id
    INNER JOIN saas_modules m ON m.id=i.module_id
    WHERE i.invoice_id=?
    ORDER BY c.name,m.sort_order,i.item_type");
  $st->execute([$viewId]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

saas_layout_start(
  'Faturamento de revendas • Central SaaS',
  'Faturamento consolidado',
  'Gere uma única cobrança mensal por revenda com os módulos ativos de todos os clientes vinculados.'
);
?>
<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/revendas.php">Revendas</a>
  <a class="btn2 btn2-ghost" href="/saas/modulos.php">Custos dos módulos</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="grid-2" style="margin-top:16px">
  <div class="panel">
    <h3>Gerar competência</h3>
    <form method="post">
      <input type="hidden" name="action" value="generate">
      <label>Revenda<select class="field" name="reseller_company_id" required><option value="">Selecione</option><?php foreach ($revendas as $revenda): ?><option value="<?= (int)$revenda['id'] ?>"><?= e((string)$revenda['name']) ?></option><?php endforeach; ?></select></label><br>
      <label>Competência<input class="field" type="month" name="reference_month" value="<?= e(date('Y-m')) ?>" required></label><br>
      <label>Vencimento<input class="field" type="date" name="due_date" value="<?= e(date('Y-m-d', strtotime('+10 days'))) ?>"></label><br>
      <button class="btn2 btn2-dark" type="submit">Gerar cobrança única</button>
    </form>
  </div>
  <div class="panel">
    <h3>Regra aplicada</h3>
    <p class="muted">Para cada cliente da revenda, o sistema soma somente o custo mensal dos módulos habilitados. Nenhum valor de implantação é incluído no faturamento consolidado da revenda.</p>
    <p class="muted">Uma competência em rascunho pode ser recalculada. Depois da conferência, altere para emitida ou paga.</p>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h3>Faturas geradas</h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Competência</th><th>Revenda</th><th>Itens</th><th>Valor</th><th>Status</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($faturas as $fatura): ?>
      <tr>
        <td><?= e((string)$fatura['reference_month']) ?><br><span class="muted">Venc. <?= e(saas_date_br($fatura['due_date'] ?? null)) ?></span></td>
        <td><?= e((string)$fatura['reseller_name']) ?></td>
        <td><?= (int)$fatura['items_count'] ?></td>
        <td><strong><?= money_br((float)$fatura['amount']) ?></strong></td>
        <td><span class="pill"><?= e((string)$fatura['status']) ?></span></td>
        <td>
          <div class="row-actions">
            <a class="mini mini-primary" href="?id=<?= (int)$fatura['id'] ?>">Detalhar</a>
            <form method="post" style="display:flex;gap:6px;flex-wrap:wrap;margin:0">
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="invoice_id" value="<?= (int)$fatura['id'] ?>">
              <?php if ($fatura['status'] === 'draft'): ?>
                <button class="mini" name="status" value="issued">Emitida</button>
                <button class="mini" name="status" value="canceled">Cancelar</button>
              <?php elseif ($fatura['status'] === 'issued'): ?>
                <button class="mini" name="status" value="paid">Paga</button>
                <button class="mini" name="status" value="draft">Rascunho</button>
                <button class="mini" name="status" value="canceled">Cancelar</button>
              <?php elseif ($fatura['status'] === 'canceled'): ?>
                <button class="mini" name="status" value="draft">Reabrir</button>
              <?php endif; ?>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($viewId > 0): ?>
<div class="panel" style="margin-top:16px">
  <h3>Itens da fatura #<?= $viewId ?></h3>
  <div class="table-wrap"><table>
    <thead><tr><th>Cliente</th><th>Módulo</th><th>Tipo</th><th>Descrição</th><th>Valor</th></tr></thead>
    <tbody><?php foreach ($items as $item): ?><tr><td><?= e((string)$item['client_name']) ?></td><td><?= e((string)$item['module_name']) ?></td><td><?= e((string)$item['item_type']) ?></td><td><?= e((string)$item['description']) ?></td><td><?= money_br((float)$item['total_amount']) ?></td></tr><?php endforeach; ?></tbody>
  </table></div>
</div>
<?php endif; ?>

<?php saas_layout_end(); ?>
