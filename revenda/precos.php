<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$msg = '';
$erro = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    reseller_save_plan_prices($pdo, RESELLER_COMPANY_ID, (array)($_POST['plan_prices'] ?? []));
    reseller_save_prices($pdo, RESELLER_COMPANY_ID, (array)($_POST['module_prices'] ?? []));
    $msg = 'Preços atualizados. O configurador da revenda já utiliza os novos valores.';
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$deliveryPlans = reseller_plan_catalog($pdo, RESELLER_COMPANY_ID, true);
$modules = array_values(array_filter(
  reseller_module_catalog($pdo, RESELLER_COMPANY_ID, true),
  static fn(array $module): bool => (string)($module['code'] ?? '') !== 'delivery'
));

revenda_layout_start('Preços da revenda', 'precos', $reseller);
?>
<style>
  .pricing-help{margin:0 0 16px;padding:14px 16px;border:1px solid #cfe0ff;background:#f4f8ff;border-radius:16px;color:#334155;line-height:1.55}
  .pricing-section{margin-top:18px}
  .pricing-head{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;margin-bottom:12px}
  .pricing-head h2{margin:0 0 5px}.pricing-head p{margin:0}
  .pricing-table{min-width:920px}
  .pricing-table input[type="text"]{min-width:120px}
  .pricing-table .price-active{width:24px;height:24px}
  .pricing-cost strong{display:block}.pricing-cost span{display:block;margin-top:4px}
</style>

<?php if ($msg): ?><div class="ok"><?= rev_h($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="err"><?= rev_h($erro) ?></div><?php endif; ?>

<div class="panel">
  <div class="pricing-help">
    A Nexa define o <strong>custo da revenda</strong> na Central SaaS. Aqui a revenda informa somente o
    <strong>preço mensal de venda</strong>, o <strong>valor da implantação</strong> e quais itens aparecerão em sua landing.
    Quando a Central habilitar quantidade em um módulo ou serviço, o configurador calculará automaticamente o valor por unidade.
  </div>

  <form method="post">
    <section class="pricing-section">
      <div class="pricing-head">
        <div><h2>Faixas do Delivery</h2><p class="muted">Até 100, de 101 a 500 e de 501 a 1000 pedidos.</p></div>
      </div>
      <div class="table"><table class="pricing-table">
        <thead><tr><th>Faixa</th><th>Custo Nexa</th><th>Venda mensal</th><th>Implantação</th><th>Exibir</th></tr></thead>
        <tbody>
        <?php foreach ($deliveryPlans as $plan): ?>
          <tr>
            <td><strong><?= rev_h((string)$plan['name']) ?></strong><br><span class="muted"><?= rev_h((string)($plan['summary'] ?? '')) ?></span></td>
            <td class="pricing-cost"><strong><?= rev_money((float)($plan['monthly_cost'] ?? 0)) ?>/mês</strong><span class="muted">Impl. <?= rev_money((float)($plan['setup_cost'] ?? 0)) ?></span></td>
            <td><input type="text" name="plan_prices[<?= (int)$plan['id'] ?>][sale_price]" value="<?= rev_h(number_format((float)$plan['effective_sale_price'], 2, ',', '.')) ?>"></td>
            <td><input type="text" name="plan_prices[<?= (int)$plan['id'] ?>][setup_price]" value="<?= rev_h(number_format((float)$plan['effective_setup_price'], 2, ',', '.')) ?>"></td>
            <td><input class="price-active" type="checkbox" name="plan_prices[<?= (int)$plan['id'] ?>][is_active]" value="1" <?= !empty($plan['reseller_price_active']) ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$deliveryPlans): ?><tr><td colspan="5" class="muted">As faixas do Delivery ainda não foram cadastradas pela Central Nexa.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>

    <section class="pricing-section">
      <div class="pricing-head">
        <div><h2>Módulos e serviços adicionais</h2><p class="muted">Todos os itens públicos cadastrados pela Central Nexa aparecem nesta lista.</p></div>
      </div>
      <div class="table"><table class="pricing-table">
        <thead><tr><th>Item</th><th>Quantidade</th><th>Custo Nexa</th><th>Venda mensal</th><th>Implantação/serviço</th><th>Exibir</th></tr></thead>
        <tbody>
        <?php foreach ($modules as $module): ?>
          <tr>
            <td><strong><?= rev_h((string)$module['name']) ?></strong> <span class="pill"><?= (($module['item_type'] ?? 'module') === 'service') ? 'Serviço' : 'Módulo' ?></span><br><span class="muted"><?= rev_h((string)($module['description'] ?? '')) ?></span></td>
            <td><?php if (!empty($module['allow_quantity'])): ?><strong>Por <?= rev_h((string)($module['quantity_label'] ?: 'unidade')) ?></strong><br><span class="muted">Mín. <?= (int)($module['quantity_min'] ?? 1) ?> · padrão <?= (int)($module['quantity_default'] ?? 1) ?> · máx. <?= (int)($module['quantity_max'] ?? 99) ?></span><?php else: ?><span class="muted">Quantidade fixa</span><?php endif; ?></td>
            <td class="pricing-cost"><strong><?= rev_money((float)$module['monthly_cost']) ?>/mês</strong><span class="muted">Impl. <?= rev_money((float)$module['setup_cost']) ?></span></td>
            <td><input type="text" name="module_prices[<?= (int)$module['id'] ?>][sale_price]" value="<?= rev_h(number_format((float)$module['effective_sale_price'], 2, ',', '.')) ?>"></td>
            <td><input type="text" name="module_prices[<?= (int)$module['id'] ?>][setup_price]" value="<?= rev_h(number_format((float)$module['effective_setup_price'], 2, ',', '.')) ?>"></td>
            <td><input class="price-active" type="checkbox" name="module_prices[<?= (int)$module['id'] ?>][is_active]" value="1" <?= !empty($module['reseller_price_active']) ? 'checked' : '' ?>></td>
          </tr>
        <?php endforeach; ?>
        <?php if (!$modules): ?><tr><td colspan="6" class="muted">Nenhum módulo ou serviço adicional foi liberado para venda.</td></tr><?php endif; ?>
        </tbody>
      </table></div>
    </section>

    <div class="actions">
      <button class="btn btn-primary" type="submit">Salvar todos os preços</button>
      <a class="btn btn-ghost" target="_blank" href="/montesuaproposta">Ver configurador da revenda</a>
    </div>
  </form>
</div>
<?php revenda_layout_end(); ?>
