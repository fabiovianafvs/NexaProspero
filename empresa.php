<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

function money_br(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

function random_pass(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  $out = '';
  for ($i = 0; $i < $len; $i++) {
    $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  }
  return $out;
}

function badge_color(?string $status): string {
  return match ($status) {
    'active' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
    'trial' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
    'past_due' => 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74;',
    'canceled' => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
    default => 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;',
  };
}

function has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->prepare("SELECT 1
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME = ?
      LIMIT 1");
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  die('Empresa inválida.');
}

$company = null;
$desiredPlan = null;
$subscription = null;
$adminUser = null;
$plans = [];
$totalPedidos = 0;
$msg = '';
$erro = '';
$newPassword = '';

try {
  $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
  $stmt->execute([$id]);
  $company = $stmt->fetch(PDO::FETCH_ASSOC);
  if (!$company) {
    die('Empresa não encontrada.');
  }

  if (has_column($pdo, 'companies', 'desired_plan_id') && (int)($company['desired_plan_id'] ?? 0) > 0) {
    $stPlan = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
    $stPlan->execute([(int)$company['desired_plan_id']]);
    $desiredPlan = $stPlan->fetch(PDO::FETCH_ASSOC) ?: null;
  }

  $stmt = $pdo->prepare("
    SELECT s.*, p.name AS plan_name, p.price
    FROM subscriptions s
    INNER JOIN plans p ON p.id = s.plan_id
    WHERE s.company_id = ?
    ORDER BY s.id DESC
    LIMIT 1
  ");
  $stmt->execute([$id]);
  $subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  $stmt = $pdo->prepare("
    SELECT *
    FROM users
    WHERE company_id = ?
      AND role = 'company_admin'
    ORDER BY id ASC
    LIMIT 1
  ");
  $stmt->execute([$id]);
  $adminUser = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

  $orderExpr = 'price ASC, id ASC';
  if (has_column($pdo, 'plans', 'sort_order')) {
    $orderExpr = 'COALESCE(sort_order,0) ASC, price ASC, id ASC';
  }
  $plans = $pdo->query("
    SELECT *
    FROM plans
    WHERE is_active = 1
    ORDER BY {$orderExpr}
  ")->fetchAll(PDO::FETCH_ASSOC);

  $stmt = $pdo->prepare("SELECT COUNT(*) FROM pedidos WHERE company_id = ?");
  $stmt->execute([$id]);
  $totalPedidos = (int)$stmt->fetchColumn();

} catch (Throwable $e) {
  http_response_code(500);
  die('Erro ao carregar empresa: ' . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8'));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');

  try {
    if ($action === 'reset_password') {
      if (!$adminUser) {
        throw new RuntimeException('Admin da empresa não encontrado.');
      }

      $newPassword = random_pass(10);
      $hash = password_hash($newPassword, PASSWORD_DEFAULT);

      $stmt = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $stmt->execute([$hash, (int)$adminUser['id']]);

      $msg = 'Senha do admin redefinida com sucesso.';
    }

    if ($action === 'change_plan') {
      $planId = (int)($_POST['plan_id'] ?? 0);
      if ($planId <= 0) {
        throw new RuntimeException('Selecione um plano válido.');
      }

      $stmt = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
      $stmt->execute([$planId]);
      $plan = $stmt->fetch(PDO::FETCH_ASSOC);
      if (!$plan) {
        throw new RuntimeException('Plano não encontrado.');
      }

      $isTrialPlan = ((int)($plan['trial_days'] ?? 0) > 0)
        || in_array(strtolower((string)($plan['slug'] ?? '')), ['trial','start-free'], true)
        || stripos((string)$plan['name'], 'trial') !== false
        || stripos((string)$plan['name'], 'start free') !== false;

      $windowDays = $isTrialPlan ? max(1, (int)($plan['trial_days'] ?? 7)) : max(1, (int)($plan['billing_days'] ?? 30));
      $subStatus = $isTrialPlan ? 'trial' : 'active';
      $now = date('Y-m-d H:i:s');
      $end = date('Y-m-d H:i:s', strtotime("+{$windowDays} days"));

      $stmt = $pdo->prepare("
        INSERT INTO subscriptions
        (company_id, plan_id, status, started_at, ends_at, next_billing_at)
        VALUES (?, ?, ?, ?, ?, ?)
      ");
      $stmt->execute([$id, $planId, $subStatus, $now, $end, $end]);

      if (has_column($pdo, 'companies', 'desired_plan_id')) {
        $stmt = $pdo->prepare("UPDATE companies SET desired_plan_id = ? WHERE id = ?");
        $stmt->execute([$planId, $id]);
      }

      $msg = 'Plano alterado com sucesso.';
    }

    if ($action === 'suspend_company') {
      $statusToUse = has_column($pdo, 'companies', 'status') ? 'blocked' : 'blocked';
      $stmt = $pdo->prepare("UPDATE companies SET status = ? WHERE id = ?");
      $stmt->execute([$statusToUse, $id]);
      $msg = 'Cliente suspenso com sucesso.';
    }

    if ($action === 'reactivate_company') {
      $stmt = $pdo->prepare("UPDATE companies SET status = 'active' WHERE id = ?");
      $stmt->execute([$id]);
      $msg = 'Cliente reativado com sucesso.';
    }

    $stmt = $pdo->prepare("SELECT * FROM companies WHERE id = ? LIMIT 1");
    $stmt->execute([$id]);
    $company = $stmt->fetch(PDO::FETCH_ASSOC);

    $stmt = $pdo->prepare("
      SELECT s.*, p.name AS plan_name, p.price
      FROM subscriptions s
      INNER JOIN plans p ON p.id = s.plan_id
      WHERE s.company_id = ?
      ORDER BY s.id DESC
      LIMIT 1
    ");
    $stmt->execute([$id]);
    $subscription = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;

    if (has_column($pdo, 'companies', 'desired_plan_id') && (int)($company['desired_plan_id'] ?? 0) > 0) {
      $stPlan = $pdo->prepare("SELECT * FROM plans WHERE id = ? LIMIT 1");
      $stPlan->execute([(int)$company['desired_plan_id']]);
      $desiredPlan = $stPlan->fetch(PDO::FETCH_ASSOC) ?: null;
    }

  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$catalogUrl = 'https://' . ($company['subdomain'] ?? '') . '.nexaprospero.com.br';
$adminUrl = $catalogUrl . '/admin';

saas_layout_start(
  'Empresa - Central SaaS',
  e((string)$company['name']),
  e((string)$company['subdomain']) . '.nexaprospero.com.br'
);
?>

<div class="top-actions">
  <a class="btn2 btn2-primary" href="<?= e($catalogUrl) ?>" target="_blank" rel="noopener">Abrir catálogo</a>
  <a class="btn2 btn2-ghost" href="<?= e($adminUrl) ?>" target="_blank" rel="noopener">Abrir admin</a>
  <a class="btn2 btn2-ghost" href="/saas/empresas.php">Voltar</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="grid-2">
  <div class="panel">
    <h3>Dados da empresa</h3>
    <p><strong>Empresa:</strong> <?= e((string)$company['name']) ?></p>
    <p><strong>E-mail:</strong> <?= e((string)($company['email'] ?? '-')) ?></p>
    <p><strong>WhatsApp:</strong> <?= e((string)($company['whatsapp'] ?? '-')) ?></p>
    <?php if ($desiredPlan): ?><p><strong>Plano de interesse:</strong> <?= e((string)$desiredPlan['name']) ?></p><?php endif; ?>
    <p><strong>Status:</strong> <?= e((string)($company['status'] ?? '-')) ?></p>
    <p><strong>Subdomínio:</strong> <?= e((string)($company['subdomain'] ?? '-')) ?></p>
    <p><strong>Total de pedidos:</strong> <?= (int)$totalPedidos ?></p>

    <div class="row-actions" style="margin-top:14px">
      <?php if (!in_array((string)($company['status'] ?? ''), ['blocked','suspended'], true)): ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="suspend_company">
          <button class="btn2 btn2-danger" type="submit">Suspender cliente</button>
        </form>
      <?php else: ?>
        <form method="post" style="margin:0">
          <input type="hidden" name="action" value="reactivate_company">
          <button class="btn2 btn2-dark" type="submit">Reativar cliente</button>
        </form>
      <?php endif; ?>
    </div>
  </div>

  <div class="panel">
    <h3>Assinatura atual</h3>
    <?php if ($subscription): ?>
      <p><strong>Plano:</strong> <?= e((string)$subscription['plan_name']) ?> (<?= money_br((float)$subscription['price']) ?>)</p>
      <p>
        <strong>Status:</strong>
        <span class="pill" style="<?= badge_color($subscription['status'] ?? null) ?>">
          <?= e((string)$subscription['status']) ?>
        </span>
      </p>
      <p><strong>Início:</strong> <?= e((string)($subscription['started_at'] ?? '-')) ?></p>
      <p><strong>Vencimento:</strong> <?= e((string)($subscription['ends_at'] ?? '-')) ?></p>
      <p><strong>Próx. cobrança:</strong> <?= e((string)($subscription['next_billing_at'] ?? '-')) ?></p>
    <?php else: ?>
      <p class="muted">Nenhuma assinatura encontrada.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Reset de senha do admin</h3>
    <?php if ($adminUser): ?>
      <p><strong>Admin:</strong> <?= e((string)$adminUser['name']) ?></p>
      <p><strong>E-mail:</strong> <?= e((string)$adminUser['email']) ?></p>

      <form method="post">
        <input type="hidden" name="action" value="reset_password">
        <button class="btn2 btn2-dark" type="submit">Gerar nova senha</button>
      </form>

      <?php if ($newPassword): ?>
        <div style="margin-top:14px">
          <div class="muted" style="margin-bottom:8px">Nova senha gerada</div>
          <code class="box"><?= e($newPassword) ?></code>
        </div>
      <?php endif; ?>
    <?php else: ?>
      <p class="muted">Admin da empresa não encontrado.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Troca manual de plano</h3>
    <form method="post">
      <input type="hidden" name="action" value="change_plan">
      <label>Selecione o plano</label><br><br>
      <select name="plan_id" class="field" required>
        <option value="">Selecione...</option>
        <?php foreach ($plans as $p): ?>
          <option value="<?= (int)$p['id'] ?>">
            <?= e((string)$p['name']) ?> - <?= money_br((float)$p['price']) ?>
            <?php if (!empty($p['max_orders'])): ?>
              <?= ' • até ' . (int)$p['max_orders'] . ' pedidos' ?>
            <?php elseif ((int)($p['trial_days'] ?? 0) > 0): ?>
              <?= ' • ' . (int)($p['trial_days'] ?? 0) . ' dias grátis' ?>
            <?php endif; ?>
          </option>
        <?php endforeach; ?>
      </select>
      <div style="height:14px"></div>
      <button class="btn2 btn2-dark" type="submit">Alterar plano</button>
    </form>
  </div>
</div>

<?php saas_layout_end(); ?>
