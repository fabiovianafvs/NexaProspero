<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/log_helper.php';

function money_br(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function h(string $v): string { return htmlspecialchars($v, ENT_QUOTES, 'UTF-8'); }

$msg = '';
$erro = '';
$editing = null;

try {
  $pdo->exec("CREATE TABLE IF NOT EXISTS platform_logs (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    company_id BIGINT NULL,
    user_id BIGINT NULL,
    action VARCHAR(190) NOT NULL,
    meta_json LONGTEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    KEY idx_platform_logs_company (company_id),
    KEY idx_platform_logs_user (user_id),
    KEY idx_platform_logs_created (created_at)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'save_plan') {
      $id = (int)($_POST['id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $slug = trim((string)($_POST['slug'] ?? ''));
      $headline = trim((string)($_POST['headline'] ?? ''));
      $summary = trim((string)($_POST['summary'] ?? ''));
      $price = (float)str_replace(',', '.', (string)($_POST['price'] ?? '0'));
      $originalPriceRaw = trim((string)($_POST['original_price'] ?? ''));
      $originalPrice = $originalPriceRaw !== '' ? (float)str_replace(',', '.', $originalPriceRaw) : null;
      $maxOrdersRaw = trim((string)($_POST['max_orders'] ?? ''));
      $maxOrders = $maxOrdersRaw !== '' ? (int)$maxOrdersRaw : null;
      $trialDays = max(0, (int)($_POST['trial_days'] ?? 0));
      $billingDays = max(1, (int)($_POST['billing_days'] ?? 30));
      $badgeText = trim((string)($_POST['badge_text'] ?? ''));
      $buttonLabel = trim((string)($_POST['button_label'] ?? ''));
      $sortOrder = (int)($_POST['sort_order'] ?? 0);
      $isFeatured = isset($_POST['is_featured']) ? 1 : 0;
      $isActive = isset($_POST['is_active']) ? 1 : 0;

      if ($name === '') throw new RuntimeException('Informe o nome do plano.');
      if ($slug === '') {
        $slug = preg_replace('/[^a-z0-9]+/i', '-', strtolower($name)) ?: 'plano';
        $slug = trim((string)$slug, '-');
      }
      if ($slug === '') throw new RuntimeException('Slug inválido.');

      if ($id > 0) {
        $stmt = $pdo->prepare("UPDATE plans SET name=?, slug=?, headline=?, summary=?, price=?, original_price=?, max_orders=?, trial_days=?, billing_days=?, badge_text=?, button_label=?, sort_order=?, is_featured=?, is_active=? WHERE id=?");
        $stmt->execute([$name, $slug, $headline !== '' ? $headline : null, $summary !== '' ? $summary : null, $price, $originalPrice, $maxOrders, $trialDays, $billingDays, $badgeText !== '' ? $badgeText : null, $buttonLabel !== '' ? $buttonLabel : null, $sortOrder, $isFeatured, $isActive, $id]);
        platform_log($pdo, null, null, 'Atualizou plano SaaS', ['plan_id' => $id, 'name' => $name]);
        $msg = 'Plano atualizado com sucesso.';
      } else {
        $stmt = $pdo->prepare("INSERT INTO plans (name, slug, headline, summary, price, original_price, max_orders, trial_days, billing_days, badge_text, button_label, sort_order, is_featured, is_active) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        $stmt->execute([$name, $slug, $headline !== '' ? $headline : null, $summary !== '' ? $summary : null, $price, $originalPrice, $maxOrders, $trialDays, $billingDays, $badgeText !== '' ? $badgeText : null, $buttonLabel !== '' ? $buttonLabel : null, $sortOrder, $isFeatured, $isActive]);
        $newId = (int)$pdo->lastInsertId();
        platform_log($pdo, null, null, 'Criou plano SaaS', ['plan_id' => $newId, 'name' => $name]);
        $msg = 'Plano criado com sucesso.';
      }
    }

    if ($action === 'toggle_status') {
      $id = (int)($_POST['id'] ?? 0);
      $stmt = $pdo->prepare("UPDATE plans SET is_active = IF(is_active=1,0,1) WHERE id=?");
      $stmt->execute([$id]);
      platform_log($pdo, null, null, 'Alternou status de plano', ['plan_id' => $id]);
      $msg = 'Status do plano atualizado.';
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
  $st = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
  $st->execute([$editId]);
  $editing = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

$plans = $pdo->query("SELECT * FROM plans ORDER BY sort_order ASC, price ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

saas_layout_start('Planos SaaS', 'Planos SaaS', 'Cadastre, edite e mantenha os planos sincronizados entre a landing page e a Central SaaS.');
?>
<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/dashboard.php">Voltar ao dashboard</a>
  <a class="btn2 btn2-ghost" href="/escolher_plano.php">Abrir cadastro público</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= h($erro) ?></div><?php endif; ?>

<div class="grid-main">
  <div class="panel">
    <h3><?= $editing ? 'Editar plano' : 'Novo plano' ?></h3>
    <form method="post" class="grid-2">
      <input type="hidden" name="action" value="save_plan">
      <input type="hidden" name="id" value="<?= (int)($editing['id'] ?? 0) ?>">
      <div>
        <label>Nome</label>
        <input class="field" name="name" value="<?= h((string)($editing['name'] ?? '')) ?>" placeholder="Ex: Essencial">
      </div>
      <div>
        <label>Slug</label>
        <input class="field" name="slug" value="<?= h((string)($editing['slug'] ?? '')) ?>" placeholder="ex: essencial">
      </div>
      <div>
        <label>Selo</label>
        <input class="field" name="badge_text" value="<?= h((string)($editing['badge_text'] ?? '')) ?>" placeholder="Mais vendido">
      </div>
      <div>
        <label>Headline</label>
        <input class="field" name="headline" value="<?= h((string)($editing['headline'] ?? '')) ?>" placeholder="Teste / Escala / Premium">
      </div>
      <div style="grid-column:1/-1;">
        <label>Resumo</label>
        <textarea class="field" name="summary" rows="3" placeholder="Resumo do plano na landing."><?= h((string)($editing['summary'] ?? '')) ?></textarea>
      </div>
      <div>
        <label>Preço atual</label>
        <input class="field" name="price" value="<?= h(isset($editing['price']) ? number_format((float)$editing['price'], 2, '.', '') : '') ?>">
      </div>
      <div>
        <label>Preço original</label>
        <input class="field" name="original_price" value="<?= h(isset($editing['original_price']) && $editing['original_price'] !== null ? number_format((float)$editing['original_price'], 2, '.', '') : '') ?>">
      </div>
      <div>
        <label>Máximo de pedidos</label>
        <input class="field" name="max_orders" value="<?= h((string)($editing['max_orders'] ?? '')) ?>" placeholder="Ex: 100">
      </div>
      <div>
        <label>Dias de trial</label>
        <input class="field" name="trial_days" value="<?= h((string)($editing['trial_days'] ?? '0')) ?>">
      </div>
      <div>
        <label>Ciclo de cobrança (dias)</label>
        <input class="field" name="billing_days" value="<?= h((string)($editing['billing_days'] ?? '30')) ?>">
      </div>
      <div>
        <label>Texto do botão</label>
        <input class="field" name="button_label" value="<?= h((string)($editing['button_label'] ?? '')) ?>" placeholder="Quero este plano">
      </div>
      <div>
        <label>Ordem</label>
        <input class="field" name="sort_order" value="<?= h((string)($editing['sort_order'] ?? '0')) ?>">
      </div>
      <div style="display:flex;gap:18px;align-items:center;flex-wrap:wrap;">
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_featured" <?= !empty($editing['is_featured']) ? 'checked' : '' ?>> Destacar</label>
        <label style="display:flex;align-items:center;gap:8px;"><input type="checkbox" name="is_active" <?= !isset($editing['is_active']) || !empty($editing['is_active']) ? 'checked' : '' ?>> Ativo</label>
      </div>
      <div style="grid-column:1/-1;display:flex;gap:10px;flex-wrap:wrap;">
        <button class="btn2 btn2-dark" type="submit"><?= $editing ? 'Salvar alterações' : 'Criar plano' ?></button>
        <?php if ($editing): ?><a class="btn2 btn2-ghost" href="/saas/planos.php">Cancelar edição</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="panel">
    <h3>Planos cadastrados</h3>
    <div style="display:grid;gap:14px;">
      <?php foreach ($plans as $plan): ?>
        <div style="border:1px solid #e2e8f0;border-radius:18px;padding:16px;background:#fff;">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;">
            <div>
              <div style="font-size:22px;font-weight:800;"><?= h((string)$plan['name']) ?></div>
              <div class="muted">Slug: <?= h((string)($plan['slug'] ?? '')) ?> • <?= money_br((float)$plan['price']) ?><?php if (($plan['original_price'] ?? null) !== null): ?> <span style="text-decoration:line-through;color:#94a3b8;"><?= money_br((float)$plan['original_price']) ?></span><?php endif; ?></div>
              <?php if (!empty($plan['summary'])): ?><div style="margin-top:8px;color:#475569;line-height:1.6;"><?= h((string)$plan['summary']) ?></div><?php endif; ?>
              <div style="display:flex;gap:8px;flex-wrap:wrap;margin-top:10px;">
                <?php if (!empty($plan['badge_text'])): ?><span class="badge" style="background:#eff6ff;color:#1d4ed8;"><?= h((string)$plan['badge_text']) ?></span><?php endif; ?>
                <?php if (!empty($plan['max_orders'])): ?><span class="badge"><?= (int)$plan['max_orders'] ?> pedidos</span><?php elseif ((int)($plan['trial_days'] ?? 0) > 0): ?><span class="badge"><?= (int)$plan['trial_days'] ?> dias grátis</span><?php else: ?><span class="badge">Sem limite</span><?php endif; ?>
                <span class="badge" style="<?= !empty($plan['is_active']) ? 'background:#ecfdf5;color:#065f46;' : 'background:#fef2f2;color:#991b1b;' ?>"><?= !empty($plan['is_active']) ? 'Ativo' : 'Inativo' ?></span>
              </div>
            </div>
            <div style="display:flex;flex-direction:column;gap:10px;min-width:160px;">
              <a class="btn2 btn2-dark" href="/saas/planos.php?edit=<?= (int)$plan['id'] ?>">Editar</a>
              <form method="post">
                <input type="hidden" name="action" value="toggle_status">
                <input type="hidden" name="id" value="<?= (int)$plan['id'] ?>">
                <button class="btn2 <?= !empty($plan['is_active']) ? 'btn2-danger' : 'btn2-primary' ?>" type="submit"><?= !empty($plan['is_active']) ? 'Desativar' : 'Ativar' ?></button>
              </form>
            </div>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php saas_layout_end(); ?>
