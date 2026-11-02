<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$st = $pdo->prepare('SELECT * FROM companies WHERE id=? AND reseller_company_id=? LIMIT 1');
$st->execute([$id, RESELLER_COMPANY_ID]);
$client = $st->fetch(PDO::FETCH_ASSOC) ?: null;
if (!$client) {
  http_response_code(404);
  exit('Cliente não encontrado.');
}

$msg = '';
$erro = '';

function revenda_cliente_delivery_feedback(array $delivery): string {
  $parts = array_values(array_filter(array_map('trim', (array)($delivery['messages'] ?? []))));
  $errors = array_values(array_filter(array_map('trim', (array)($delivery['errors'] ?? []))));
  $text = $parts ? ' ' . implode(' e ', $parts) . '.' : '';
  if ($errors) $text .= ' Atenção: ' . implode(' | ', $errors);
  return $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'status') {
      $status = (string)($_POST['status'] ?? 'active');
      if (!in_array($status, ['active', 'blocked'], true)) throw new RuntimeException('Status inválido.');
      $pdo->prepare('UPDATE companies SET status=? WHERE id=? AND reseller_company_id=?')->execute([$status, $id, RESELLER_COMPANY_ID]);
      $client['status'] = $status;
      $msg = $status === 'blocked' ? 'Cliente bloqueado.' : 'Cliente reativado.';
      $wa = reseller_whatsapp_send_status($pdo, RESELLER_COMPANY_ID, $id, $status);
      if (!empty($wa['ok'])) $msg .= ' Aviso enviado pelo WhatsApp da revenda.';
      elseif (empty($wa['skipped'])) $erro = 'O status foi atualizado, mas o WhatsApp não foi enviado: ' . (string)($wa['error'] ?? 'falha não identificada.');

    } elseif ($action === 'modules') {
      $ids = array_map('intval', (array)($_POST['module_ids'] ?? []));
      saas_save_company_modules_custom($pdo, $id, $ids, null, 'Módulos administrados pela revenda');
      $msg = 'Módulos atualizados.';

    } elseif ($action === 'charge') {
      $amount = reseller_money_value($_POST['amount'] ?? 0);
      $desc = trim((string)($_POST['description'] ?? ''));
      if ($amount <= 0 || $desc === '') throw new RuntimeException('Informe descrição e valor da cobrança.');
      $due = trim((string)($_POST['due_date'] ?? ''));
      $pdo->prepare("INSERT INTO saas_reseller_customer_charges
        (reseller_company_id,client_company_id,description,amount,due_date,status,notes)
        VALUES (?,?,?,?,?,'open',?)")
        ->execute([
          RESELLER_COMPANY_ID,
          $id,
          $desc,
          $amount,
          $due !== '' ? $due : null,
          trim((string)($_POST['notes'] ?? '')) ?: null,
        ]);
      $chargeId = (int)$pdo->lastInsertId();
      $delivery = reseller_prepare_charge_delivery($pdo, RESELLER_COMPANY_ID, $chargeId, true);
      $msg = 'Cobrança criada para o cliente.' . revenda_cliente_delivery_feedback($delivery);

    } elseif ($action === 'generate_pix' || $action === 'generate_card') {
      $chargeId = (int)($_POST['charge_id'] ?? 0);
      $method = $action === 'generate_card' ? 'card' : 'pix';
      reseller_pagbank_create_charge($pdo, RESELLER_COMPANY_ID, $chargeId, $method);
      $msg = $method === 'card' ? 'Checkout de cartão criado pelo PagBank da revenda.' : 'Cobrança Pix criada pelo PagBank da revenda.';

    } elseif ($action === 'send_whatsapp') {
      $chargeId = (int)($_POST['charge_id'] ?? 0);
      $result = reseller_whatsapp_send_charge($pdo, RESELLER_COMPANY_ID, $chargeId, null);
      if (empty($result['ok'])) throw new RuntimeException((string)($result['error'] ?? 'Não foi possível enviar a cobrança pelo WhatsApp.'));
      $msg = 'Cobrança enviada pelo WhatsApp da revenda.';
    }
  } catch (Throwable $e) {
    $erro = trim($erro . ' ' . $e->getMessage());
  }
}

$modules = saas_all_modules($pdo, true);
$enabled = saas_company_module_codes($pdo, $id);
$chargesSt = $pdo->prepare('SELECT * FROM saas_reseller_customer_charges WHERE reseller_company_id=? AND client_company_id=? ORDER BY id DESC');
$chargesSt->execute([RESELLER_COMPANY_ID, $id]);
$charges = $chargesSt->fetchAll(PDO::FETCH_ASSOC) ?: [];
$pagCfg = reseller_pagbank_load_config($pdo, RESELLER_COMPANY_ID);

revenda_layout_start('Gerenciar cliente', 'clientes', $reseller);
?>
<?php if ($msg): ?><div class="ok"><?= rev_h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="err"><?= rev_h($erro) ?></div><?php endif; ?>

<div class="grid2">
  <div class="panel">
    <h2><?= rev_h((string)$client['name']) ?></h2>
    <p><?= rev_h((string)($client['email'] ?? '')) ?><br><?= rev_h((string)($client['whatsapp'] ?? '')) ?></p>
    <p><strong>Endereço do sistema:</strong><br><a target="_blank" href="<?= rev_h(company_catalog_url($client)) ?>"><?= rev_h(company_catalog_url($client)) ?></a></p>
    <form method="post">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="status">
      <input type="hidden" name="status" value="<?= $client['status'] === 'active' ? 'blocked' : 'active' ?>">
      <button class="btn <?= $client['status'] === 'active' ? 'btn-danger' : 'btn-primary' ?>" type="submit"><?= $client['status'] === 'active' ? 'Bloquear cliente' : 'Reativar cliente' ?></button>
    </form>
    <p class="muted" style="margin-top:10px">Quando habilitado em Configurações → WhatsApp, o cliente recebe a comunicação de bloqueio ou reativação pelo número da revenda.</p>
  </div>

  <div class="panel">
    <h2>Nova cobrança</h2>
    <form method="post">
      <input type="hidden" name="id" value="<?= $id ?>">
      <input type="hidden" name="action" value="charge">
      <label>Descrição<input name="description" value="Mensalidade Nexa Próspero"></label>
      <label>Valor<input name="amount" placeholder="0,00"></label>
      <label>Vencimento<input type="date" name="due_date" value="<?= rev_h(date('Y-m-d', strtotime('+7 days'))) ?>"></label>
      <label>Observações<textarea name="notes" rows="2"></textarea></label>
      <div class="actions"><button class="btn btn-primary" type="submit">Criar cobrança</button></div>
    </form>
    <p class="muted">Com o PagBank ativo, o sistema pode gerar Pix ou checkout de cartão. Com o WhatsApp ativo, a cobrança é enviada pelo número da revenda.</p>
  </div>
</div>

<div class="panel" style="margin-top:16px">
  <h2>Módulos habilitados</h2>
  <form method="post">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="modules">
    <div class="grid2">
      <?php foreach ($modules as $m): ?>
        <label style="border:1px solid var(--line);padding:12px;border-radius:14px">
          <input style="width:auto" type="checkbox" name="module_ids[]" value="<?= (int)$m['id'] ?>" <?= in_array((string)$m['code'], $enabled, true) ? 'checked' : '' ?>>
          <?= rev_h((string)$m['name']) ?><br><span class="muted"><?= rev_h((string)$m['description']) ?></span>
        </label>
      <?php endforeach; ?>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Salvar módulos</button></div>
  </form>
</div>

<div class="panel" style="margin-top:16px">
  <h2>Cobranças do cliente</h2>
  <div class="table"><table>
    <thead><tr><th>Descrição</th><th>Vencimento</th><th>Valor</th><th>Status</th><th>Pagamento</th><th>Ações</th></tr></thead>
    <tbody>
      <?php foreach ($charges as $ch): ?>
      <tr>
        <td><?= rev_h((string)$ch['description']) ?></td>
        <td><?= rev_h(rev_date_br($ch['due_date'] ?? null)) ?></td>
        <td><?= rev_money((float)$ch['amount']) ?></td>
        <td><span class="pill"><?= rev_h((string)$ch['status']) ?></span></td>
        <td>
          <?= !empty($ch['payment_gateway']) ? rev_h(strtoupper((string)$ch['payment_gateway']) . ' / ' . strtoupper((string)($ch['payment_method'] ?? ''))) : '<span class="muted">Manual</span>' ?>
          <?php if (!empty($ch['payment_url'])): ?><br><a target="_blank" href="<?= rev_h((string)$ch['payment_url']) ?>">Abrir cobrança</a><?php endif; ?>
        </td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap">
            <?php if ((string)$ch['status'] === 'open' && empty($ch['payment_url'])): ?>
              <?php if (!empty($pagCfg['pix_enabled'])): ?><form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="generate_pix"><input type="hidden" name="charge_id" value="<?= (int)$ch['id'] ?>"><button class="btn btn-ghost">Gerar Pix</button></form><?php endif; ?>
              <?php if (!empty($pagCfg['card_enabled'])): ?><form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="generate_card"><input type="hidden" name="charge_id" value="<?= (int)$ch['id'] ?>"><button class="btn btn-ghost">Gerar cartão</button></form><?php endif; ?>
            <?php endif; ?>
            <?php if ((string)$ch['status'] === 'open'): ?>
            <form method="post"><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="send_whatsapp"><input type="hidden" name="charge_id" value="<?= (int)$ch['id'] ?>"><button class="btn btn-primary">Enviar WhatsApp</button></form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      <?php if (!$charges): ?><tr><td colspan="6" class="muted">Nenhuma cobrança registrada.</td></tr><?php endif; ?>
    </tbody>
  </table></div>
</div>
<?php revenda_layout_end(); ?>
