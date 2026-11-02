<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/log_helper.php';

function money_br(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function badge_color(?string $status): string {
  return match ($status) {
    'active' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
    'trial' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
    'past_due' => 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74;',
    'canceled', 'cancelled', 'suspended' => 'background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;',
    default => 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;',
  };
}

$stats = [
  'empresas' => 0,
  'ativas' => 0,
  'suspensas' => 0,
  'trial' => 0,
  'trial_vencendo' => 0,
  'vencidas' => 0,
  'mrr_real' => 0.0,
  'mrr_potencial' => 0.0,
  'pedidos_mes' => 0,
  'pedidos_hoje' => 0,
  'novas_empresas_mes' => 0,
];
$recentes = [];
$rankingPedidos = [];
$dailyOrders = [];
$growthCompanies = [];
$recentLogs = [];

try {
  $stats['empresas'] = (int)$pdo->query("SELECT COUNT(*) FROM companies")->fetchColumn();
  $stats['ativas'] = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'active'")->fetchColumn();
  $stats['suspensas'] = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE status = 'suspended'")->fetchColumn();

  try {
    $stats['novas_empresas_mes'] = (int)$pdo->query("SELECT COUNT(*) FROM companies WHERE DATE_FORMAT(created_at,'%Y-%m') = DATE_FORMAT(NOW(),'%Y-%m')")->fetchColumn();
  } catch (Throwable $e) {}

  $subs = $pdo->query("SELECT s.*, p.name AS plan_name, p.price FROM subscriptions s INNER JOIN plans p ON p.id = s.plan_id INNER JOIN (SELECT company_id, MAX(id) AS max_id FROM subscriptions GROUP BY company_id) x ON x.max_id = s.id ORDER BY s.id DESC")->fetchAll(PDO::FETCH_ASSOC);
  foreach ($subs as $s) {
    $status = strtolower((string)($s['status'] ?? ''));
    $endsAt = $s['ends_at'] ?? null;
    $price = (float)($s['price'] ?? 0);
    $stats['mrr_potencial'] += $price;
    if ($status === 'active') {
      $stats['mrr_real'] += $price;
    }
    if ($status === 'trial') {
      $stats['trial']++;
      if ($endsAt) {
        $dias = (int)floor((strtotime((string)$endsAt) - time()) / 86400);
        if ($dias >= 0 && $dias <= 3) $stats['trial_vencendo']++;
        if ($dias < 0) $stats['vencidas']++;
      }
    }
    if (in_array($status, ['past_due', 'canceled', 'cancelled'], true)) {
      $stats['vencidas']++;
    }
  }

  try {
    $stats['pedidos_mes'] = (int)$pdo->query("SELECT COUNT(*) FROM pedidos WHERE DATE_FORMAT(created_at, '%Y-%m') = DATE_FORMAT(NOW(), '%Y-%m')")->fetchColumn();
    $stats['pedidos_hoje'] = (int)$pdo->query("SELECT COUNT(*) FROM pedidos WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    $dailyOrders = $pdo->query("SELECT DATE(created_at) AS dia, COUNT(*) AS total FROM pedidos WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 14 DAY) GROUP BY DATE(created_at) ORDER BY dia ASC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $dailyOrders = [];
  }

  try {
    $growthCompanies = $pdo->query("SELECT DATE_FORMAT(created_at, '%Y-%m') AS mes, COUNT(*) AS total FROM companies WHERE created_at >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH) GROUP BY DATE_FORMAT(created_at, '%Y-%m') ORDER BY mes ASC")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $growthCompanies = [];
  }

  $recentes = $pdo->query("SELECT c.id,c.name,c.email,c.subdomain,s.status AS subscription_status,s.ends_at,p.name AS plan_name,p.price FROM companies c LEFT JOIN (SELECT s1.* FROM subscriptions s1 INNER JOIN (SELECT company_id, MAX(id) AS max_id FROM subscriptions GROUP BY company_id) x ON x.max_id = s1.id) s ON s.company_id = c.id LEFT JOIN plans p ON p.id = s.plan_id ORDER BY c.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

  try {
    $rankingPedidos = $pdo->query("SELECT c.id,c.name,COUNT(p.id) AS total_pedidos FROM companies c LEFT JOIN pedidos p ON p.company_id = c.id GROUP BY c.id,c.name ORDER BY total_pedidos DESC, c.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $rankingPedidos = [];
  }

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
    $recentLogs = $pdo->query("SELECT l.*, c.name AS company_name FROM platform_logs l LEFT JOIN companies c ON c.id = l.company_id ORDER BY l.id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);
  } catch (Throwable $e) {
    $recentLogs = [];
  }
} catch (Throwable $e) {
  die('Erro no dashboard SaaS: ' . $e->getMessage());
}

function sparkline_points(array $rows, string $labelKey, string $valueKey, int $w = 440, int $h = 140): string {
  if (!$rows) return '';
  $values = array_map(fn($r) => (int)($r[$valueKey] ?? 0), $rows);
  $max = max(1, max($values));
  $stepX = count($values) > 1 ? ($w - 24) / (count($values) - 1) : 0;
  $pts = [];
  foreach ($values as $i => $v) {
    $x = 12 + ($stepX * $i);
    $y = $h - 12 - (($v / $max) * ($h - 28));
    $pts[] = round($x, 1) . ',' . round($y, 1);
  }
  return implode(' ', $pts);
}

saas_layout_start('Dashboard SaaS', 'Dashboard SaaS', 'Controle contratos, automações, receita recorrente, crescimento e operação da plataforma.');
?>
<div class="top-actions">
  <a class="btn2 btn2-primary" href="/cadastro.php">+ Nova empresa</a>
  <a class="btn2 btn2-ghost" href="/saas/empresas.php">Gerenciar empresas</a>
  <a class="btn2 btn2-ghost" href="/saas/planos.php">Gerenciar planos</a>
  <a class="btn2 btn2-ghost" href="/saas/logs.php">Logs da plataforma</a>
  <a class="btn2 btn2-ghost" href="/saas/cron_saas.php" target="_blank" rel="noopener">Executar cron agora</a>
</div>

<?php if ($stats['trial_vencendo'] > 0): ?>
  <div class="warn">Atenção: existem <strong><?= (int)$stats['trial_vencendo'] ?></strong> trial(s) vencendo nos próximos 3 dias.</div>
<?php endif; ?>

<section class="cards">
  <div class="card"><div class="label">Empresas</div><div class="value"><?= (int)$stats['empresas'] ?></div><div class="sub">Base total cadastrada</div></div>
  <div class="card"><div class="label">Ativas</div><div class="value"><?= (int)$stats['ativas'] ?></div><div class="sub">Operando normalmente</div></div>
  <div class="card"><div class="label">Trials</div><div class="value"><?= (int)$stats['trial'] ?></div><div class="sub">Teste grátis de 7 dias</div></div>
  <div class="card"><div class="label">Pedidos hoje</div><div class="value"><?= (int)$stats['pedidos_hoje'] ?></div><div class="sub">Volume diário</div></div>
  <div class="card"><div class="label">Pedidos no mês</div><div class="value"><?= (int)$stats['pedidos_mes'] ?></div><div class="sub">Volume do mês atual</div></div>
  <div class="card"><div class="label">MRR real</div><div class="value" style="font-size:24px"><?= money_br((float)$stats['mrr_real']) ?></div><div class="sub">Receita recorrente ativa</div></div>
</section>

<section class="grid-2" style="margin-top:16px;">
  <div class="panel">
    <h3>Pedidos últimos 15 dias</h3>
    <?php if ($dailyOrders): ?>
      <svg viewBox="0 0 440 140" style="width:100%;height:auto;background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px;padding:8px;">
        <polyline fill="none" stroke="#147a8a" stroke-width="4" points="<?= e(sparkline_points($dailyOrders, 'dia', 'total')) ?>"></polyline>
      </svg>
      <div style="display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:8px;margin-top:12px;">
        <?php foreach (array_slice($dailyOrders, -5) as $d): ?>
          <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:14px;padding:10px;">
            <div class="muted"><?= e((string)$d['dia']) ?></div>
            <div style="font-weight:800;font-size:22px;"><?= (int)$d['total'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php else: ?>
      <p class="muted">Ainda não há dados suficientes para o gráfico de pedidos.</p>
    <?php endif; ?>
  </div>

  <div class="panel">
    <h3>Crescimento de empresas</h3>
    <?php if ($growthCompanies): ?>
      <div style="display:grid;gap:10px;">
        <?php $maxGrowth = max(1, ...array_map(fn($r)=>(int)$r['total'], $growthCompanies)); ?>
        <?php foreach ($growthCompanies as $g): ?>
          <?php $pct = ((int)$g['total'] / $maxGrowth) * 100; ?>
          <div>
            <div style="display:flex;justify-content:space-between;gap:10px;margin-bottom:6px;"><strong><?= e((string)$g['mes']) ?></strong><span><?= (int)$g['total'] ?> empresa(s)</span></div>
            <div style="height:12px;background:#e2e8f0;border-radius:999px;overflow:hidden;"><div style="height:12px;width:<?= round($pct,1) ?>%;background:linear-gradient(90deg,#0f172a,#147a8a);"></div></div>
          </div>
        <?php endforeach; ?>
      </div>
      <div style="margin-top:14px;color:#64748b;">Novas empresas neste mês: <strong style="color:#0f172a;"><?= (int)$stats['novas_empresas_mes'] ?></strong></div>
    <?php else: ?>
      <p class="muted">Sem dados de crescimento ainda.</p>
    <?php endif; ?>
  </div>
</section>

<section class="grid-main" style="margin-top:16px;">
  <div class="panel">
    <h3>Empresas recentes</h3>
    <div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Plano</th><th>Assinatura</th><th>Vencimento</th></tr></thead><tbody>
      <?php if (!$recentes): ?>
        <tr><td colspan="4" class="muted">Nenhuma empresa cadastrada.</td></tr>
      <?php else: foreach ($recentes as $r): ?>
        <tr>
          <td><strong><?= e((string)$r['name']) ?></strong><br><span class="muted"><?= e((string)$r['email']) ?></span></td>
          <td><?= e((string)($r['plan_name'] ?: '—')) ?><br><span class="muted"><?= money_br((float)($r['price'] ?? 0)) ?></span></td>
          <td><span class="pill" style="<?= badge_color($r['subscription_status'] ?? null) ?>"><?= e((string)($r['subscription_status'] ?: '—')) ?></span></td>
          <td><?= e((string)($r['ends_at'] ?: '—')) ?></td>
        </tr>
      <?php endforeach; endif; ?>
    </tbody></table></div>
  </div>

  <div class="panel">
    <h3>Ranking de pedidos</h3>
    <div class="table-wrap"><table><thead><tr><th>Empresa</th><th>Pedidos</th></tr></thead><tbody>
    <?php if (!$rankingPedidos): ?>
      <tr><td colspan="2" class="muted">Sem dados de pedidos ainda.</td></tr>
    <?php else: foreach ($rankingPedidos as $r): ?>
      <tr><td><?= e((string)$r['name']) ?></td><td><strong><?= (int)$r['total_pedidos'] ?></strong></td></tr>
    <?php endforeach; endif; ?>
    </tbody></table></div>
  </div>
</section>

<section class="grid-2" style="margin-top:16px;">
  <div class="panel">
    <h3>Saúde da operação</h3>
    <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
      <div style="padding:14px;border-radius:16px;background:#eff6ff;border:1px solid #bfdbfe;"><div class="muted">Trials vencendo</div><div style="font-size:28px;font-weight:800;"><?= (int)$stats['trial_vencendo'] ?></div></div>
      <div style="padding:14px;border-radius:16px;background:#fef2f2;border:1px solid #fecaca;"><div class="muted">Vencidas / pendentes</div><div style="font-size:28px;font-weight:800;"><?= (int)$stats['vencidas'] ?></div></div>
      <div style="padding:14px;border-radius:16px;background:#ecfdf5;border:1px solid #a7f3d0;"><div class="muted">MRR potencial</div><div style="font-size:24px;font-weight:800;"><?= money_br((float)$stats['mrr_potencial']) ?></div></div>
      <div style="padding:14px;border-radius:16px;background:#f8fafc;border:1px solid #e2e8f0;"><div class="muted">Suspensas</div><div style="font-size:28px;font-weight:800;"><?= (int)$stats['suspensas'] ?></div></div>
    </div>
  </div>

  <div class="panel">
    <h3>Logs recentes</h3>
    <?php if (!$recentLogs): ?>
      <p class="muted">Sem logs ainda.</p>
    <?php else: ?>
      <div style="display:grid;gap:10px;">
        <?php foreach ($recentLogs as $log): ?>
          <div style="padding:12px 14px;border:1px solid #e2e8f0;border-radius:16px;background:#f8fafc;">
            <strong><?= e((string)$log['action']) ?></strong><br>
            <span class="muted"><?= e((string)($log['company_name'] ?? 'Sem empresa')) ?> • <?= e((string)$log['created_at']) ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php saas_layout_end(); ?>
