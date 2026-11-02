<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';

$mods = function_exists('saas_company_module_state') ? saas_company_module_state($pdo, (int)COMPANY_ID) : [];
$codes = function_exists('saas_company_module_codes') ? saas_company_module_codes($pdo, (int)COMPANY_ID) : [];

admin_topbar('Central - Módulos', 'params');
?>
<div class="card">
  <div class="cardhead"><strong>Módulos liberados para esta empresa</strong></div>
  <p><strong>Empresa ID:</strong> <?= (int)COMPANY_ID ?></p>
  <p><strong>Códigos ativos:</strong> <?= htmlspecialchars(implode(', ', $codes) ?: 'nenhum', ENT_QUOTES, 'UTF-8') ?></p>
  <div style="overflow:auto">
    <table class="table">
      <thead><tr><th>Módulo</th><th>Código</th><th>Origem</th><th>Status</th><th>Validade</th></tr></thead>
      <tbody>
      <?php foreach ($mods as $m): ?>
        <tr>
          <td><?= htmlspecialchars((string)$m['name'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= htmlspecialchars((string)$m['code'], ENT_QUOTES, 'UTF-8') ?></td>
          <td><?= $m['override'] === null ? (!empty($m['from_plan']) ? 'Plano' : 'Plano não libera') : ((int)$m['override'] === 1 ? 'Manual' : 'Bloqueado manual') ?></td>
          <td><?= !empty($m['enabled']) ? 'Liberado' : 'Bloqueado' ?></td>
          <td><?= htmlspecialchars((string)($m['expires_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php admin_footer(); ?>
