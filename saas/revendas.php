<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/resellers.php';

$schema = reseller_ensure_schema($pdo);
$msg = '';
$erro = '';
$editId = (int)($_GET['id'] ?? $_POST['company_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');
    $companyId = (int)($_POST['company_id'] ?? 0);
    if ($action === 'activate') {
      reseller_activate($pdo, $companyId, [
        'brand_name' => $_POST['brand_name'] ?? '',
        'whatsapp' => $_POST['whatsapp'] ?? '',
        'pix_key' => $_POST['pix_key'] ?? '',
        'notes' => $_POST['notes'] ?? '',
      ]);
      $msg = 'Empresa habilitada como revenda. Todos os módulos foram liberados sem cobrança direta.';
      $editId = $companyId;
    } elseif ($action === 'deactivate') {
      reseller_deactivate($pdo, $companyId);
      $msg = 'Revenda desativada. Os clientes já vinculados foram preservados para auditoria.';
      $editId = 0;
    } elseif ($action === 'save_prices') {
      reseller_save_prices($pdo, $companyId, (array)($_POST['prices'] ?? []));
      $msg = 'Preços personalizados da revenda atualizados.';
      $editId = $companyId;
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$companies = [];
try {
  $companies = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM companies cli WHERE cli.reseller_company_id=c.id) AS clients_count FROM companies c ORDER BY c.is_reseller DESC,c.name ASC")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { $erro = $erro ?: $e->getMessage(); }

$edit = $editId > 0 ? reseller_company_by_id($pdo, $editId) : null;
$catalog = $edit ? reseller_module_catalog($pdo, (int)$edit['id'], false) : [];

saas_layout_start('Revendas • Central SaaS', 'Revendas', 'Habilite parceiros, defina identidade comercial, preços próprios e acompanhe a carteira vinculada.');
?>
<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/empresas.php">Empresas</a>
  <a class="btn2 btn2-ghost" href="/saas/faturamento_revendas.php">Faturamento consolidado</a>
  <a class="btn2 btn2-ghost" href="/saas/modulos.php">Custos e preços dos módulos</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<?php if (!$schema['ok']): ?><div class="warn">A estrutura de revendas não foi totalmente confirmada. <?= e(implode(' | ', array_slice($schema['errors'], 0, 3))) ?></div><?php endif; ?>

<div class="grid-main" style="margin-top:16px">
  <div class="panel">
    <h3>Empresas e revendas</h3>
    <div class="table-wrap">
      <table>
        <thead><tr><th>Empresa</th><th>Subdomínio</th><th>Tipo</th><th>Clientes</th><th>Ações</th></tr></thead>
        <tbody>
        <?php foreach ($companies as $row): ?>
          <tr>
            <td><strong><?= e((string)$row['name']) ?></strong><br><span class="muted"><?= e((string)($row['email'] ?? '')) ?></span></td>
            <td><code><?= e((string)($row['subdomain'] ?? $row['slug'] ?? '')) ?></code></td>
            <td><?= !empty($row['is_reseller']) ? '<span class="pill" style="background:#ecfdf5;color:#166534">Revenda ativa</span>' : '<span class="muted">Cliente</span>' ?></td>
            <td><?= (int)($row['clients_count'] ?? 0) ?></td>
            <td><a class="mini mini-primary" href="/saas/revendas.php?id=<?= (int)$row['id'] ?>"><?= !empty($row['is_reseller']) ? 'Gerenciar' : 'Habilitar' ?></a></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="panel">
    <?php if (!$edit): ?>
      <h3>Selecione uma empresa</h3>
      <p class="muted">Use “Habilitar” para transformar uma empresa já cadastrada em revenda. Ela continuará sendo uma empresa do SaaS, receberá todos os módulos sem cobrança e poderá administrar os próprios clientes.</p>
    <?php else: ?>
      <h3><?= !empty($edit['is_reseller']) ? 'Configurar revenda' : 'Habilitar como revenda' ?></h3>
      <form method="post">
        <input type="hidden" name="company_id" value="<?= (int)$edit['id'] ?>">
        <input type="hidden" name="action" value="activate">
        <label>Nome comercial da landing<input class="field" name="brand_name" value="<?= e((string)($edit['reseller_brand_name'] ?? $edit['name'])) ?>"></label><br>
        <label>WhatsApp comercial<input class="field" name="whatsapp" value="<?= e((string)($edit['reseller_whatsapp'] ?? $edit['whatsapp'] ?? '')) ?>"></label><br>
        <label>Chave Pix da revenda<input class="field" name="pix_key" value="<?= e((string)($edit['reseller_pix_key'] ?? '')) ?>" placeholder="CPF, CNPJ, e-mail, telefone ou chave aleatória"></label><br>
        <label>Observações<textarea class="field" name="notes" rows="3"><?= e((string)($edit['reseller_notes'] ?? '')) ?></textarea></label><br>
        <button class="btn2 btn2-dark" type="submit"><?= !empty($edit['is_reseller']) ? 'Salvar configuração' : 'Habilitar revenda' ?></button>
      </form>
      <?php if (!empty($edit['is_reseller'])): ?>
        <hr style="border:0;border-top:1px solid #e5edf8;margin:22px 0">
        <p><strong>Landing:</strong><br><code class="box"><?= e(company_catalog_url($edit, 'montesuaproposta')) ?></code></p>
        <p><strong>Painel da revenda:</strong><br><code class="box"><?= e(company_catalog_url($edit, 'revenda/')) ?></code></p>
        <form method="post" onsubmit="return confirm('Desativar esta revenda?')">
          <input type="hidden" name="company_id" value="<?= (int)$edit['id'] ?>">
          <input type="hidden" name="action" value="deactivate">
          <button class="btn2 btn2-danger" type="submit">Desativar revenda</button>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<?php if ($edit && !empty($edit['is_reseller'])): ?>
<div class="panel" style="margin-top:16px">
  <h3>Preços específicos desta revenda</h3>
  <p class="muted">Esses valores substituem o preço padrão na landing da revenda. O custo continua sendo controlado somente pela Nexa no cadastro do módulo.</p>
  <form method="post">
    <input type="hidden" name="action" value="save_prices">
    <input type="hidden" name="company_id" value="<?= (int)$edit['id'] ?>">
    <div class="table-wrap"><table>
      <thead><tr><th>Módulo</th><th>Custo Nexa</th><th>Venda mensal</th><th>Implantação</th><th>Exibir</th></tr></thead>
      <tbody>
      <?php foreach ($catalog as $m): ?>
        <tr>
          <td><strong><?= e((string)$m['name']) ?></strong><br><span class="muted"><?= e((string)$m['code']) ?></span></td>
          <td><?= money_br((float)($m['monthly_cost'] ?? 0)) ?><br><span class="muted">Impl.: <?= money_br((float)($m['setup_cost'] ?? 0)) ?></span></td>
          <td><input class="field" name="prices[<?= (int)$m['id'] ?>][sale_price]" value="<?= e(number_format((float)$m['effective_sale_price'],2,',','.')) ?>"></td>
          <td><input class="field" name="prices[<?= (int)$m['id'] ?>][setup_price]" value="<?= e(number_format((float)$m['effective_setup_price'],2,',','.')) ?>"></td>
          <td><input type="checkbox" name="prices[<?= (int)$m['id'] ?>][is_active]" value="1" <?= !empty($m['reseller_price_active']) ? 'checked' : '' ?>></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="row-actions" style="margin-top:14px"><button class="btn2 btn2-dark" type="submit">Salvar preços da revenda</button></div>
  </form>
</div>
<?php endif; ?>

<?php saas_layout_end(); ?>
