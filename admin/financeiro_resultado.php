<?php
declare(strict_types=1);

// NEXA_PATCH_PLANO_CLASSIFICACAO_V1_20260805
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCrossReadOnly = function_exists('finance_multi_company_readonly') && finance_multi_company_readonly($pdo);
$ini = finance_date_or_null($_GET['ini'] ?? '') ?: date('Y-m-01');
$fim = finance_date_or_null($_GET['fim'] ?? '') ?: date('Y-m-t');
$rows = finance_result_by_chart($pdo, $cid, $ini, $fim);
$total = 0.0;
foreach ($rows as $r) {
  if ((int)($r['parent_id'] ?? 0) === 0) $total += (float)$r['total'];
}

finance_admin_start('Financeiro - Resultado por Plano', 'fin_resultado');
finance_page_header('Resultado por Plano de Contas', 'Relatório semelhante à DRE gerencial, apurado pelos movimentos financeiros de crédito e débito.', [
  ['href' => 'financeiro_resultado.php?ini=' . date('Y-m-01') . '&fim=' . date('Y-m-t'), 'label' => 'Mês atual', 'class' => 'btn'],
]);
// V11.51.6: remove as abas gerais apenas desta tela e compacta o filtro.
finance_flash();
?>
<style>
.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.finance-filter-card .finance-filter{gap:8px 10px}.finance-filter-card .finance-filter label{margin-bottom:3px}.finance-filter-card .finance-filter input,.finance-filter-card .finance-filter select{min-height:39px}.finance-filter-card .finance-filter-actions .btn{min-height:39px}
</style>
<div class="finance-card finance-filter-card"><form method="get" class="finance-filter"><div><label>Data inicial</label><input type="date" name="ini" value="<?= finance_h($ini) ?>"></div><div><label>Data final</label><input type="date" name="fim" value="<?= finance_h($fim) ?>"></div><div class="finance-filter-actions"><button class="btn btn-primary">Emitir</button><button class="btn" type="button" onclick="window.print()">Imprimir</button></div></form></div>

<div class="finance-card"><div class="finance-card-head"><div><h2 class="finance-subtitle">Resultado de <?= finance_date_br($ini) ?> a <?= finance_date_br($fim) ?></h2><p class="finance-muted">Créditos entram positivos; débitos entram negativos. Contas sintéticas somam todos os níveis inferiores conforme a classificação.</p></div><div class="finance-kpi" style="margin:0;min-width:240px"><small>Resultado final</small><strong class="<?= $total>=0?'pos':'neg' ?>">R$ <?= finance_money_br($total) ?></strong></div></div><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Classificação</th><th>Plano de contas</th><th>Tipo</th><th>Resultado</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="4" class="finance-empty">Nenhuma conta cadastrada.</td></tr><?php endif; ?><?php foreach($rows as $r): $v=(float)$r['total']; ?><tr class="finance-tree-row <?= (int)$r['is_analytic'] ? '' : 'synthetic' ?>"><td data-label="Classificação" class="code"><?= finance_h($r['classification'] ?: $r['code']) ?><?php if (($r['classification'] ?? '') !== '' && ($r['classification'] ?? '') !== ($r['code'] ?? '')): ?><small class="finance-muted" style="display:block;margin-top:3px">Cód. <?= finance_h($r['code']) ?></small><?php endif; ?></td><td data-label="Plano" class="finance-primary"><?= finance_tree_indent((int)$r['level']) ?><?= finance_h($r['name']) ?></td><td data-label="Tipo"><?= (int)$r['is_analytic'] ? '<span class="finance-badge green">Analítica</span>' : '<span class="finance-badge gray">Sintética</span>' ?></td><td data-label="Resultado" class="finance-value <?= $v>=0?'pos':'neg' ?>">R$ <?= finance_money_br($v) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php finance_admin_end(); ?>
