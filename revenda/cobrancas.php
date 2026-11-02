<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$msg = '';
$erro = '';

function revenda_cobrancas_delivery_summary(array $results): array {
  $createdPix = 0;
  $createdCard = 0;
  $sentWa = 0;
  $errors = [];
  foreach ($results as $result) {
    foreach ((array)($result['messages'] ?? []) as $message) {
      if (stripos((string)$message, 'Pix') !== false) $createdPix++;
      if (stripos((string)$message, 'cartão') !== false) $createdCard++;
      if (stripos((string)$message, 'WhatsApp') !== false) $sentWa++;
    }
    foreach ((array)($result['errors'] ?? []) as $error) $errors[] = (string)$error;
  }
  return compact('createdPix', 'createdCard', 'sentWa', 'errors');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'status');

    if ($action === 'generate') {
      $month = trim((string)($_POST['reference_month'] ?? date('Y-m')));
      $dueDate = trim((string)($_POST['due_date'] ?? ''));
      $result = reseller_generate_customer_charges($pdo, RESELLER_COMPANY_ID, $month, $dueDate !== '' ? $dueDate : null);
      $deliveryResults = [];
      foreach ((array)($result['charge_ids'] ?? []) as $chargeId) {
        $deliveryResults[] = reseller_prepare_charge_delivery($pdo, RESELLER_COMPANY_ID, (int)$chargeId, true);
      }
      $delivery = revenda_cobrancas_delivery_summary($deliveryResults);
      $msg = (int)$result['generated'] . ' cobrança(s) gerada(s), totalizando ' . rev_money((float)$result['amount']) . '.';
      if ($delivery['createdPix'] > 0) $msg .= ' ' . $delivery['createdPix'] . ' Pix criado(s).';
      if ($delivery['createdCard'] > 0) $msg .= ' ' . $delivery['createdCard'] . ' checkout(s) de cartão criado(s).';
      if ($delivery['sentWa'] > 0) $msg .= ' ' . $delivery['sentWa'] . ' mensagem(ns) enviada(s) pelo WhatsApp.';
      if ((int)$result['skipped_paid'] > 0) $msg .= ' ' . (int)$result['skipped_paid'] . ' cobrança(s) já paga(s) foram preservadas.';
      if ($delivery['errors']) $erro = 'Algumas integrações precisam de atenção: ' . implode(' | ', array_unique($delivery['errors']));

    } elseif ($action === 'generate_pix' || $action === 'generate_card') {
      $id = (int)($_POST['id'] ?? 0);
      $method = $action === 'generate_card' ? 'card' : 'pix';
      reseller_pagbank_create_charge($pdo, RESELLER_COMPANY_ID, $id, $method);
      $msg = $method === 'card' ? 'Checkout de cartão criado pelo PagBank da revenda.' : 'Pix criado pelo PagBank da revenda.';

    } elseif ($action === 'send_whatsapp') {
      $id = (int)($_POST['id'] ?? 0);
      $result = reseller_whatsapp_send_charge($pdo, RESELLER_COMPANY_ID, $id, null);
      if (empty($result['ok'])) throw new RuntimeException((string)($result['error'] ?? 'Falha ao enviar pelo WhatsApp.'));
      $msg = 'Cobrança enviada pelo WhatsApp da revenda.';

    } else {
      $id = (int)($_POST['id'] ?? 0);
      $status = (string)($_POST['status'] ?? '');
      if (!in_array($status, ['open', 'paid', 'canceled'], true)) throw new RuntimeException('Status inválido.');
      $st = $pdo->prepare("UPDATE saas_reseller_customer_charges
        SET status=?, paid_at=CASE WHEN ?='paid' THEN NOW() ELSE NULL END, updated_at=NOW()
        WHERE id=? AND reseller_company_id=?");
      $st->execute([$status, $status, $id, RESELLER_COMPANY_ID]);
      $msg = 'Cobrança atualizada.';
    }
  } catch (Throwable $e) {
    $erro = trim($erro . ' ' . $e->getMessage());
  }
}

$st = $pdo->prepare("SELECT ch.*, c.name AS client_name
  FROM saas_reseller_customer_charges ch
  INNER JOIN companies c ON c.id=ch.client_company_id
  WHERE ch.reseller_company_id=?
  ORDER BY CASE ch.status WHEN 'open' THEN 0 ELSE 1 END,
           ch.reference_month DESC, ch.due_date DESC, ch.id DESC");
$st->execute([RESELLER_COMPANY_ID]);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pagCfg = reseller_pagbank_load_config($pdo, RESELLER_COMPANY_ID);

$viewId = (int)($_GET['id'] ?? 0);
$items = [];
if ($viewId > 0) {
  $st = $pdo->prepare("SELECT i.*, m.name AS module_name
    FROM saas_reseller_customer_charge_items i
    INNER JOIN saas_reseller_customer_charges ch ON ch.id=i.charge_id
    INNER JOIN saas_modules m ON m.id=i.module_id
    WHERE i.charge_id=? AND ch.reseller_company_id=?
    ORDER BY m.sort_order, i.item_type");
  $st->execute([$viewId, RESELLER_COMPANY_ID]);
  $items = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

revenda_layout_start('Cobranças dos clientes', 'cobrancas', $reseller);
?>
<?php if ($msg): ?><div class="ok"><?= rev_h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="err"><?= rev_h($erro) ?></div><?php endif; ?>

<div class="grid2">
  <div class="panel">
    <h2>Gerar cobranças mensais</h2>
    <p class="muted">Calcula os módulos ativos de cada cliente usando seus preços de venda. A implantação ou serviço inicial aparece apenas na primeira cobrança correspondente.</p>
    <form method="post">
      <input type="hidden" name="action" value="generate">
      <label>Competência<input type="month" name="reference_month" value="<?= rev_h(date('Y-m')) ?>" required></label>
      <label>Vencimento<input type="date" name="due_date" value="<?= rev_h(date('Y-m-d', strtotime('+7 days'))) ?>"></label>
      <div class="actions"><button class="btn btn-primary" type="submit">Gerar cobranças</button></div>
    </form>
  </div>
  <div class="panel">
    <h2>Integrações da revenda</h2>
    <p class="muted">Quando habilitados, o PagBank gera Pix ou checkout de cartão e o WhatsApp envia automaticamente a cobrança usando as credenciais da própria revenda.</p>
    <div class="actions"><a class="btn btn-ghost" href="/revenda/pagbank.php">Configurar PagBank</a><a class="btn btn-ghost" href="/revenda/whatsapp.php">Configurar WhatsApp</a></div>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <div class="table"><table>
    <thead><tr><th>Cliente</th><th>Competência</th><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pagamento</th><th>Ações</th></tr></thead>
    <tbody>
    <?php foreach ($rows as $row): ?>
      <tr>
        <td><a href="/revenda/cliente.php?id=<?= (int)$row['client_company_id'] ?>"><strong><?= rev_h((string)$row['client_name']) ?></strong></a></td>
        <td><?= rev_h((string)($row['reference_month'] ?: 'Avulsa')) ?><br><span class="muted"><?= ((string)($row['origin'] ?? 'manual') === 'automatic') ? 'Automática' : 'Manual' ?></span></td>
        <td><?= rev_h((string)$row['description']) ?></td>
        <td><?= rev_h(rev_date_br($row['due_date'] ?? null)) ?></td>
        <td><strong><?= rev_money((float)$row['amount']) ?></strong></td>
        <td><span class="pill"><?= rev_h((string)$row['status']) ?></span></td>
        <td>
          <?php if (!empty($row['payment_gateway'])): ?>
            <?= rev_h(strtoupper((string)$row['payment_gateway']) . ' / ' . strtoupper((string)($row['payment_method'] ?? ''))) ?><br>
            <span class="muted"><?= rev_h((string)($row['gateway_status'] ?? 'aguardando')) ?></span>
            <?php if (!empty($row['payment_url'])): ?><br><a target="_blank" href="<?= rev_h((string)$row['payment_url']) ?>">Abrir pagamento</a><?php endif; ?>
          <?php else: ?><span class="muted">Manual</span><?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if ((string)($row['origin'] ?? '') === 'automatic'): ?><a class="btn btn-ghost" href="?id=<?= (int)$row['id'] ?>">Itens</a><?php endif; ?>
            <?php if ((string)$row['status'] === 'open' && empty($row['payment_url'])): ?>
              <?php if (!empty($pagCfg['pix_enabled'])): ?><form method="post"><input type="hidden" name="action" value="generate_pix"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-ghost">Gerar Pix</button></form><?php endif; ?>
              <?php if (!empty($pagCfg['card_enabled'])): ?><form method="post"><input type="hidden" name="action" value="generate_card"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-ghost">Gerar cartão</button></form><?php endif; ?>
            <?php endif; ?>
            <?php if ((string)$row['status'] === 'open'): ?>
              <form method="post"><input type="hidden" name="action" value="send_whatsapp"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="btn btn-primary">WhatsApp</button></form>
            <?php endif; ?>
            <form method="post" style="display:flex;gap:6px;flex-wrap:wrap">
              <input type="hidden" name="action" value="status">
              <input type="hidden" name="id" value="<?= (int)$row['id'] ?>">
              <?php if ($row['status'] !== 'paid'): ?><button class="btn btn-primary" name="status" value="paid">Marcar paga</button><?php endif; ?>
              <?php if ($row['status'] !== 'open'): ?><button class="btn btn-ghost" name="status" value="open">Reabrir</button><?php endif; ?>
              <?php if ($row['status'] !== 'canceled'): ?><button class="btn btn-ghost" name="status" value="canceled">Cancelar</button><?php endif; ?>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$rows): ?><tr><td colspan="8" class="muted">Nenhuma cobrança registrada.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>

<?php if ($viewId > 0): ?>
<div class="panel" style="margin-top:16px">
  <h2>Itens da cobrança #<?= $viewId ?></h2>
  <div class="table"><table>
    <thead><tr><th>Módulo</th><th>Tipo</th><th>Descrição</th><th>Valor</th></tr></thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr><td><?= rev_h((string)$item['module_name']) ?></td><td><?= rev_h((string)$item['item_type']) ?></td><td><?= rev_h((string)$item['description']) ?></td><td><?= rev_money((float)$item['total_amount']) ?></td></tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>
<?php endif; ?>

<?php revenda_layout_end(); ?>
