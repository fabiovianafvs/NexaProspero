<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

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

$rows = [];
try {
  $rows = $pdo->query("SELECT l.*, c.name AS company_name FROM platform_logs l LEFT JOIN companies c ON c.id = l.company_id ORDER BY l.id DESC LIMIT 150")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
}

saas_layout_start('Logs da plataforma', 'Logs da plataforma', 'Auditoria de ações automáticas e operacionais da central SaaS.');
?>
<div class="top-actions">
  <a class="btn2 btn2-primary" href="/saas/dashboard.php">Dashboard</a>
  <a class="btn2 btn2-ghost" href="/saas/empresas.php">Empresas</a>
</div>

<div class="panel">
  <h3>Últimos eventos</h3>
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Data</th>
          <th>Ação</th>
          <th>Empresa</th>
          <th>Detalhes</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="4" class="muted">Nenhum log encontrado.</td></tr>
      <?php else: ?>
        <?php foreach ($rows as $r): ?>
        <tr>
          <td><strong><?= e(saas_datetime_br($r['created_at'] ?? null)) ?></strong></td>
          <td><?= e((string)$r['action']) ?></td>
          <td><?= e((string)($r['company_name'] ?? '—')) ?></td>
          <td><pre style="margin:0;white-space:pre-wrap;font:12px/1.45 monospace;color:#334155"><?= e((string)($r['meta_json'] ?? '')) ?></pre></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>
<?php saas_layout_end(); ?>
