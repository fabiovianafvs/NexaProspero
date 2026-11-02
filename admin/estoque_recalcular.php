<?php
declare(strict_types=1);
require __DIR__.'/_bootstrap.php';
require __DIR__.'/_chrome.php';
require_once __DIR__.'/../config/stock_recalculation.php';

if (function_exists('access_is_company_master') && !access_is_company_master()) {
  http_response_code(403);
  exit('Apenas o administrador principal da empresa pode recalcular o estoque.');
}

$msg=''; $erro=''; $result=null;
try { nexa_stock_recalc_ensure_schema($pdo); } catch (Throwable $e) { $erro=$e->getMessage(); }

if ($_SERVER['REQUEST_METHOD']==='POST') {
  $confirm=strtoupper(trim((string)($_POST['confirmation']??'')));
  if ($confirm!=='RECALCULAR') {
    $erro='Digite RECALCULAR para confirmar a operação.';
  } else {
    try {
      $result=nexa_stock_recalc_apply($pdo,(int)COMPANY_ID,(int)(auth_user_id()??0));
      $msg='Estoque recalculado com segurança.';
    } catch (Throwable $e) { $erro=$e->getMessage(); }
  }
}
$preview=nexa_stock_recalc_preview($pdo,(int)COMPANY_ID);
admin_topbar('Recalcular estoque','params');
?>
<?php if ($erro): ?><div class="msg err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card card-pad">
  <div class="cardhead"><div><div style="font-weight:900;font-size:20px">Recalcular estoque</div><div class="hint">Repara devoluções de pedidos cancelados e sincroniza o saldo atual dos produtos com o histórico de movimentações.</div></div><a class="btn" href="parametros.php">Voltar às configurações</a></div>

  <div class="grid" style="margin-top:14px">
    <div class="subcard"><strong>Pedidos cancelados sem devolução completa</strong><div style="font-size:30px;font-weight:950;margin-top:6px"><?= (int)$preview['canceled_orders_found'] ?></div></div>
    <div class="subcard"><strong>Produtos com saldo divergente</strong><div style="font-size:30px;font-weight:950;margin-top:6px"><?= (int)$preview['discrepancies_found'] ?></div></div>
  </div>

  <?php if ($result): ?>
    <div class="subcard" style="margin-top:14px;border-color:#86efac;background:#f0fdf4">
      <strong>Processamento concluído</strong>
      <div class="hint" style="margin-top:8px">Pedidos corrigidos: <?= (int)$result['canceled_orders_reversed'] ?> · Quantidade devolvida: <?= number_format((float)$result['quantity_reversed'],4,',','.') ?> · Produtos sincronizados: <?= (int)$result['products_rebuilt'] ?></div>
      <?php foreach (($result['warnings']??[]) as $w): ?><div class="hint" style="color:#92400e;margin-top:5px"><?= htmlspecialchars((string)$w) ?></div><?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php if ($preview['missing_cancellations']): ?>
    <div class="subcard" style="margin-top:14px;overflow:auto"><strong>Pedidos que serão corrigidos</strong><table class="table" style="margin-top:10px"><thead><tr><th>Pedido</th><th>Saída original</th><th>Já devolvido</th><th>Diferença</th></tr></thead><tbody><?php foreach ($preview['missing_cancellations'] as $r): ?><tr><td>#<?= htmlspecialchars((string)$r['numero_pedido']) ?></td><td><?= number_format((float)$r['saida_original'],4,',','.') ?></td><td><?= number_format((float)$r['devolvido'],4,',','.') ?></td><td><strong><?= number_format((float)$r['saida_original']-(float)$r['devolvido'],4,',','.') ?></strong></td></tr><?php endforeach; ?></tbody></table></div>
  <?php endif; ?>

  <?php if ($preview['discrepancies']): ?>
    <div class="subcard" style="margin-top:14px;overflow:auto"><strong>Produtos com divergência</strong><table class="table" style="margin-top:10px"><thead><tr><th>Produto</th><th>Saldo atual</th><th>Saldo pelas movimentações</th></tr></thead><tbody><?php foreach (array_slice($preview['discrepancies'],0,100) as $r): ?><tr><td><?= htmlspecialchars((string)$r['nome']) ?> (#<?= (int)$r['produto_id'] ?>)</td><td><?= number_format((float)$r['estoque_atual'],4,',','.') ?></td><td><strong><?= number_format((float)$r['estoque_movimentos'],4,',','.') ?></strong></td></tr><?php endforeach; ?></tbody></table><?php if (count($preview['discrepancies'])>100): ?><div class="hint">Exibindo os primeiros 100 produtos.</div><?php endif; ?></div>
  <?php endif; ?>

  <div class="subcard" style="margin-top:14px;border-color:#fbbf24;background:#fffbeb">
    <strong>Atenção</strong><div class="hint" style="margin-top:6px">Faça backup antes de aplicar. A rotina não inventa movimentações: ela devolve somente saídas de pedidos cancelados que possuem baixa original registrada e ainda não possuem devolução completa.</div>
    <form method="post" style="margin-top:12px" onsubmit="return confirm('Confirma o recálculo do estoque desta empresa?')">
      <label>Digite <strong>RECALCULAR</strong> para confirmar</label>
      <input class="field" name="confirmation" autocomplete="off" placeholder="RECALCULAR" required>
      <button class="btn btn-primary" type="submit" style="margin-top:10px">Recalcular estoque agora</button>
    </form>
  </div>
</div>
<?php admin_footer(); ?>
