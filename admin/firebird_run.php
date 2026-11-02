<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/firebird_runner.php';

$result = firebird_export_pending($pdo, COMPANY_ID, null, 20);
$log = (array)($result['log'] ?? []);
$err = (string)($result['error'] ?? '');

admin_topbar('Central - Exportação', 'export');
?>

<div class="card card-pad" style="max-width:980px;margin:18px auto;">
  <div class="cardhead">
    <div>
      <div style="font-weight:900;">Resultado da exportação Firebird</div>
      <div class="hint">Processamento manual dos pedidos pendentes de exportação.</div>
    </div>
    <div class="actions">
      <a class="btn" href="parametros.php#firebird-settings">Configurações</a>
      <a class="btn" href="exportacao.php">Fila de exportação</a>
    </div>
  </div>

  <?php if ($err): ?>
    <div class="msg err"><?= h($err) ?></div>
  <?php endif; ?>

  <div class="table-wrap" style="margin-top:12px;">
    <table style="min-width:680px;">
      <thead><tr><th>Mensagem</th></tr></thead>
      <tbody>
        <?php if (!$log): ?>
          <tr><td class="muted">Sem mensagens.</td></tr>
        <?php else: foreach ($log as $l): ?>
          <tr><td><?= h((string)$l) ?></td></tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <div class="hint" style="margin-top:12px;">Revise o mapeamento em Configurações se algum campo do Próspero tiver nome diferente.</div>
</div>

<?php admin_footer(); ?>
