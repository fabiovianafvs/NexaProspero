<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';

$errors = [];
try {
  company_sequence_ensure_schema($pdo, true);
} catch (Throwable $e) {
  $errors[] = $e->getMessage();
}

$checks = [];
foreach (company_sequence_entities() as $entity => $cfg) {
  $table = (string)$cfg['table'];
  $column = (string)$cfg['column'];
  $checks[] = [
    'entity' => $entity,
    'table' => $table,
    'column' => $column,
    'ok' => company_sequence_table_exists($pdo, $table) && company_sequence_column_exists($pdo, $table, $column),
  ];
}

admin_topbar('Central - Sequências por empresa', 'config');
?>
<div style="padding:22px;max-width:980px;">
  <div class="card" style="padding:18px;border-radius:18px;">
    <h1 style="margin-top:0;color:#0b1f5b;">Sequências por empresa/catálogo</h1>
    <p class="muted">Esta rotina cria as colunas de número visível e preenche os cadastros antigos a partir do número 1 em cada empresa.</p>
    <?php if ($errors): ?>
      <div class="msg err"><b>Erro:</b> <?= h(implode(' | ', $errors)) ?></div>
    <?php else: ?>
      <div class="msg ok">Sequências verificadas e cadastros antigos preenchidos com sucesso.</div>
    <?php endif; ?>
    <div style="overflow:auto;margin-top:14px;">
      <table class="table" style="width:100%;border-collapse:collapse;">
        <thead><tr><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Cadastro</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Tabela</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Coluna</th><th style="text-align:left;padding:8px;border-bottom:1px solid #e5e7eb;">Status</th></tr></thead>
        <tbody>
          <?php foreach ($checks as $c): ?>
            <tr>
              <td style="padding:8px;border-bottom:1px solid #eef2f7;"><?= h($c['entity']) ?></td>
              <td style="padding:8px;border-bottom:1px solid #eef2f7;"><code><?= h($c['table']) ?></code></td>
              <td style="padding:8px;border-bottom:1px solid #eef2f7;"><code><?= h($c['column']) ?></code></td>
              <td style="padding:8px;border-bottom:1px solid #eef2f7;"><?= $c['ok'] ? '<span class="badge on">OK</span>' : '<span class="badge off">Pendente</span>' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div style="margin-top:16px;display:flex;gap:10px;flex-wrap:wrap;">
      <a class="btn btn-primary" href="produtos.php">Produtos</a>
      <a class="btn" href="financeiro_pessoas.php">Pessoas</a>
      <a class="btn" href="pedidos.php">Pedidos</a>
    </div>
  </div>
</div>
<?php admin_footer(); ?>
