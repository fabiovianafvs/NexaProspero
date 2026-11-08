<?php
declare(strict_types=1);
/**
 * Nexa Próspero — Relatório de estoque.
 * Abre sem consultar; ao Filtrar lista os produtos com quantidade, custo
 * (custo ou custo médio), venda, totais e margens. Também imprime a folha de
 * contagem (código, produto, estoque e linha para anotar).
 * Somente leitura, sempre na empresa de login (sem F11).
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = (int)COMPANY_ID;
if (defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
    && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid) {
  access_render_denied('O Relatório de estoque não está disponível no contexto Multiempresa (F11). Entre diretamente na empresa.');
  exit;
}
if (!function_exists('access_has_permission') || !access_has_permission($pdo, $cid, 'relatorio_estoque')) {
  access_render_denied('Seu perfil não possui autorização para o Relatório de estoque.');
  exit;
}

function rest_col(PDO $pdo, string $col): bool {
  static $cache = [];
  if (!isset($cache[$col])) {
    try {
      $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'produtos' AND column_name = ?");
      $st->execute([$col]);
      $cache[$col] = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) { $cache[$col] = false; }
  }
  return $cache[$col];
}
function rest_qty($v): string {
  $v = (float)$v;
  return number_format($v, abs($v - round($v)) > 0.0001 ? 3 : 0, ',', '.');
}
function rest_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function rest_pct(?float $v): string { return $v === null ? '—' : number_format($v, 1, ',', '.') . '%'; }

$hideCost = function_exists('access_product_cost_hidden') && access_product_cost_hidden($pdo, $cid);
$hasStock = rest_col($pdo, 'estoque_atual');
$hasCost = rest_col($pdo, 'custo') && !$hideCost;
$hasAvgCost = rest_col($pdo, 'custo_medio') && !$hideCost;
$hasNumber = rest_col($pdo, 'numero_cadastro');
$hasUnit = rest_col($pdo, 'unidade_estoque');
$hasService = rest_col($pdo, 'is_service');

$requested = isset($_GET['filtrar']) || isset($_GET['print']);
$q = trim((string)($_GET['q'] ?? ''));
$stockFilter = in_array((string)($_GET['estoque'] ?? ''), ['todos','positivos','negativos','zerados','positivos_zerados'], true) ? (string)$_GET['estoque'] : 'todos';
$statusFilter = in_array((string)($_GET['situacao'] ?? ''), ['ativos','inativos','todos'], true) ? (string)$_GET['situacao'] : 'ativos';
$costMode = ((string)($_GET['custo'] ?? '') === 'medio' && $hasAvgCost) ? 'medio' : 'custo';
$selectedCats = array_values(array_unique(array_filter(array_map(static fn($c) => trim((string)$c), (array)($_GET['cat'] ?? [])), static fn($c) => $c !== '')));

$cats = [];
try {
  $st = $pdo->prepare("SELECT categoria FROM produtos WHERE company_id = ? AND categoria IS NOT NULL AND categoria <> '' GROUP BY categoria ORDER BY categoria");
  $st->execute([$cid]);
  $cats = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

$rows = [];
$summary = ['itens' => 0, 'qtd' => 0.0, 'custo' => 0.0, 'venda' => 0.0];
$limit = 5000;
$err = '';
if ($requested && !$hasStock) $err = 'O controle de estoque não está ativo nesta empresa (coluna de estoque ausente).';
if ($requested && $hasStock) {
  $where = ['p.company_id = ?'];
  $params = [$cid];
  if ($statusFilter === 'ativos') $where[] = 'p.ativo = 1';
  elseif ($statusFilter === 'inativos') $where[] = 'p.ativo = 0';
  if ($hasService) $where[] = 'COALESCE(p.is_service,0) = 0';
  $stockExpr = 'COALESCE(p.estoque_atual,0)';
  if ($stockFilter === 'positivos') $where[] = "{$stockExpr} > 0";
  elseif ($stockFilter === 'negativos') $where[] = "{$stockExpr} < 0";
  elseif ($stockFilter === 'zerados') $where[] = "{$stockExpr} = 0";
  elseif ($stockFilter === 'positivos_zerados') $where[] = "{$stockExpr} >= 0";
  if ($selectedCats) {
    $where[] = 'p.categoria IN (' . implode(',', array_fill(0, count($selectedCats), '?')) . ')';
    array_push($params, ...$selectedCats);
  }
  if ($q !== '') {
    $like = '%' . $q . '%';
    $parts = ['p.nome LIKE ?', 'CAST(p.id AS CHAR) = ?'];
    $params[] = $like; $params[] = $q;
    if ($hasNumber) { $parts[] = 'p.numero_cadastro = ?'; $params[] = $q; }
    if (rest_col($pdo, 'codigo_barras')) { $parts[] = 'p.codigo_barras = ?'; $params[] = $q; }
    if (rest_col($pdo, 'referencia')) { $parts[] = 'p.referencia LIKE ?'; $params[] = $like; }
    $where[] = '(' . implode(' OR ', $parts) . ')';
  }
  $costExpr = '0';
  if ($costMode === 'medio') $costExpr = $hasCost ? 'CASE WHEN COALESCE(p.custo_medio,0) > 0 THEN p.custo_medio ELSE COALESCE(p.custo,0) END' : 'COALESCE(p.custo_medio,0)';
  elseif ($hasCost) $costExpr = 'COALESCE(p.custo,0)';
  $avgFallback = ($costMode === 'medio' && $hasCost) ? 'CASE WHEN COALESCE(p.custo_medio,0) > 0 THEN 0 ELSE 1 END' : '0';
  $whereSql = implode(' AND ', $where);
  try {
    $st = $pdo->prepare("SELECT COUNT(*) itens, COALESCE(SUM({$stockExpr}),0) qtd, COALESCE(SUM({$stockExpr} * ({$costExpr})),0) custo, COALESCE(SUM({$stockExpr} * COALESCE(p.preco,0)),0) venda FROM produtos p WHERE {$whereSql}");
    $st->execute($params);
    $summary = array_map('floatval', $st->fetch(PDO::FETCH_ASSOC) ?: $summary);
    $sql = "SELECT p.id, " . ($hasNumber ? 'p.numero_cadastro' : 'NULL') . " AS numero_cadastro, p.nome, p.categoria, " . ($hasUnit ? 'p.unidade_estoque' : "'UN'") . " AS unidade,
        {$stockExpr} AS qtd, ({$costExpr}) AS custo_unit, COALESCE(p.preco,0) AS preco, {$avgFallback} AS custo_fallback
      FROM produtos p WHERE {$whereSql} ORDER BY p.nome ASC LIMIT " . ($limit + 1);
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  } catch (Throwable $e) {
    error_log('[Nexa Relatório de estoque] ' . $e->getMessage());
    $err = 'Não foi possível consultar o estoque. Tente novamente.';
    $rows = [];
  }
}
$truncated = count($rows) > $limit;
if ($truncated) $rows = array_slice($rows, 0, $limit);
$code = static fn(array $r): string => trim((string)($r['numero_cadastro'] ?? '')) !== '' && (string)$r['numero_cadastro'] !== '0' ? (string)$r['numero_cadastro'] : (string)(int)$r['id'];

$query = static function (array $extra = []) use ($q, $stockFilter, $statusFilter, $costMode, $selectedCats): string {
  $p = ['filtrar' => 1, 'q' => $q, 'estoque' => $stockFilter, 'situacao' => $statusFilter, 'custo' => $costMode];
  if ($selectedCats) $p['cat'] = $selectedCats;
  return 'relatorio_estoque.php?' . http_build_query(array_merge($p, $extra));
};

// Folha de contagem para impressão.
if ((string)($_GET['print'] ?? '') === 'contagem') {
  $company = '';
  try { $st = $pdo->prepare('SELECT name FROM companies WHERE id = ?'); $st->execute([$cid]); $company = (string)($st->fetchColumn() ?: ''); } catch (Throwable $e) {}
  $filterText = ['Estoque: ' . ['todos'=>'todos','positivos'=>'somente positivos','negativos'=>'somente negativos','zerados'=>'somente zerados','positivos_zerados'=>'positivos e zerados'][$stockFilter]];
  if ($selectedCats) $filterText[] = 'Categorias: ' . implode(', ', $selectedCats);
  if ($q !== '') $filterText[] = 'Busca: ' . $q;
  ?><!doctype html><html lang="pt-BR"><head><meta charset="utf-8"><title>Contagem de estoque</title>
  <style>
    *{box-sizing:border-box}body{font-family:Arial,Helvetica,sans-serif;color:#111;margin:18px;font-size:12px}
    h1{font-size:18px;margin:0 0 2px}.meta{color:#444;margin-bottom:10px;font-size:11px}
    table{width:100%;border-collapse:collapse}th{text-align:left;border-bottom:2px solid #111;padding:6px 4px;font-size:11px;text-transform:uppercase}
    td{padding:8px 4px;border-bottom:1px solid #bbb;vertical-align:bottom}td.num,th.num{text-align:right}
    td.line{width:150px}td.line span{display:block;border-bottom:1px solid #111;height:16px}
    tr{page-break-inside:avoid}.foot{margin-top:24px;display:flex;gap:40px;font-size:11px}.foot div{flex:1;border-top:1px solid #111;padding-top:4px;text-align:center}
    @media print{body{margin:8mm}.noprint{display:none}}
  </style></head><body>
  <div class="noprint" style="margin-bottom:10px"><button onclick="window.print()">Imprimir</button></div>
  <h1>Contagem de estoque</h1>
  <div class="meta"><?= finance_h($company) ?> · <?= date('d/m/Y H:i') ?> · <?= count($rows) ?> produto(s) · <?= finance_h(implode(' · ', $filterText)) ?><?= $truncated ? ' · mostrando os primeiros ' . $limit : '' ?></div>
  <table><thead><tr><th style="width:90px">Código</th><th>Produto</th><th style="width:50px">Un.</th><th class="num" style="width:90px">Estoque</th><th style="width:150px">Contagem</th></tr></thead><tbody>
  <?php foreach ($rows as $r): ?><tr><td><?= finance_h($code($r)) ?></td><td><?= finance_h($r['nome']) ?></td><td><?= finance_h($r['unidade']) ?></td><td class="num"><?= rest_qty($r['qtd']) ?></td><td class="line"><span></span></td></tr><?php endforeach; ?>
  </tbody></table>
  <div class="foot"><div>Contado por</div><div>Conferido por</div><div>Data</div></div>
  <script>window.addEventListener('load',function(){setTimeout(function(){window.print()},300)});</script>
  </body></html><?php
  exit;
}

$totalMarginCost = $summary['custo'] > 0.009 ? ($summary['venda'] - $summary['custo']) / $summary['custo'] * 100 : null;
$totalMarginSale = $summary['venda'] > 0.009 ? ($summary['venda'] - $summary['custo']) / $summary['venda'] * 100 : null;

finance_admin_start('Relatório de estoque', 'relatorio_estoque');
?>
<style>
.rest-filter{display:grid;grid-template-columns:minmax(170px,1.2fr) 170px minmax(160px,1fr) 140px 120px max-content;gap:10px;align-items:end}
.rest-filter label,.rest-filter .rest-field{display:grid;gap:4px;margin:0!important;font-size:12px;font-weight:800;color:#334155;min-width:0}
.rest-filter input:not([type=checkbox]),.rest-filter select,.rest-cats>summary{height:44px!important;min-height:44px!important;margin:0!important;box-sizing:border-box}
.rest-actions{display:flex;gap:8px;align-items:flex-end;flex-wrap:nowrap}
.rest-actions .btn{height:44px!important;min-height:44px!important;display:inline-flex;align-items:center;margin:0!important;font-size:13px!important;font-weight:800;white-space:nowrap}
.rest-cats{position:relative}
.rest-cats>summary{list-style:none;display:flex;align-items:center;justify-content:space-between;gap:8px;border:1px solid #cbd5e1;border-radius:10px;padding:0 12px;background:#fff;cursor:pointer;font-weight:700;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.rest-cats>summary::-webkit-details-marker{display:none}
.rest-cats-panel{position:absolute;z-index:60;top:calc(100% + 4px);left:0;min-width:260px;max-width:360px;max-height:320px;overflow:auto;background:#fff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 14px 38px #0f172a2b;padding:8px}
.rest-cats-panel label{display:flex!important;gap:8px;align-items:center;padding:4px 2px;font-weight:700;font-size:12.5px;cursor:pointer}
.rest-cats-panel input{width:auto;margin:0}
.rest-cats-tools{display:flex;gap:6px;justify-content:space-between;border-bottom:1px solid #e2e8f0;padding-bottom:6px;margin-bottom:6px}
.rest-cats-tools input{flex:1;height:30px;min-height:30px;padding:2px 8px;font-size:12px}
.rest-kpis{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-bottom:10px}
.rest-kpi{background:#fff;border:1px solid #dbe4f0;border-radius:14px;padding:8px 14px;display:flex;align-items:baseline;justify-content:space-between;gap:10px;flex-wrap:wrap;min-width:0}
.rest-kpi small{color:#64748b;font-weight:800;font-size:12px}
.rest-kpi strong{font-size:clamp(16px,1.5vw,20px);color:#0f172a;white-space:nowrap}
.rest-table td.rest-neg{color:#b91c1c;font-weight:800}
.rest-table td.rest-zero{color:#94a3b8}
.rest-note{font-size:11.5px;color:#64748b;margin:6px 0 0}
@media(max-width:1200px){.rest-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.rest-kpis{grid-template-columns:repeat(3,minmax(0,1fr))}}
</style>
<?php finance_page_header('Relatório de estoque', ''); ?>
<?php if ($err !== ''): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="finance-card">
  <form method="get" class="rest-filter" id="restFilter">
    <input type="hidden" name="filtrar" value="1">
    <label>Produto<input name="q" value="<?= finance_h($q) ?>" placeholder="Código, nome, referência ou código de barras"></label>
    <label>Estoque<select name="estoque">
      <?php foreach (['todos'=>'Todos','positivos'=>'Somente positivos','negativos'=>'Somente negativos','zerados'=>'Somente zerados','positivos_zerados'=>'Positivos e zerados'] as $v => $l): ?><option value="<?= $v ?>" <?= $stockFilter === $v ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?>
    </select></label>
    <div class="rest-field"><span>Categorias</span><details class="rest-cats"><summary><span id="restCatsLabel"><?= $selectedCats ? finance_h(count($selectedCats) === 1 ? $selectedCats[0] : count($selectedCats) . ' categorias') : 'Todas as categorias' ?></span><span>▾</span></summary>
      <div class="rest-cats-panel">
        <div class="rest-cats-tools"><input type="search" placeholder="Procurar categoria" id="restCatsSearch"><button type="button" class="btn btn-sm" id="restCatsClear">Limpar</button></div>
        <?php if (!$cats): ?><div class="rest-note">Nenhuma categoria cadastrada.</div><?php endif; ?>
        <?php foreach ($cats as $c): ?><label data-cat="<?= finance_h(mb_strtolower((string)$c)) ?>"><input type="checkbox" name="cat[]" value="<?= finance_h($c) ?>" <?= in_array((string)$c, $selectedCats, true) ? 'checked' : '' ?>><?= finance_h($c) ?></label><?php endforeach; ?>
      </div>
    </details></div>
    <label>Custo<select name="custo"><option value="custo" <?= $costMode === 'custo' ? 'selected' : '' ?>>Custo</option><?php if ($hasAvgCost): ?><option value="medio" <?= $costMode === 'medio' ? 'selected' : '' ?>>Custo médio</option><?php endif; ?></select></label>
    <label>Situação<select name="situacao"><option value="ativos" <?= $statusFilter === 'ativos' ? 'selected' : '' ?>>Ativos</option><option value="inativos" <?= $statusFilter === 'inativos' ? 'selected' : '' ?>>Inativos</option><option value="todos" <?= $statusFilter === 'todos' ? 'selected' : '' ?>>Todos</option></select></label>
    <div class="rest-actions">
      <button class="btn btn-primary" type="submit">Filtrar</button>
      <?php if ($requested && $rows): ?><a class="btn" href="<?= finance_h($query(['print' => 'contagem'])) ?>" target="_blank" title="Folha com código, produto, estoque e linha para anotar a contagem">🖨️ Imprimir contagem</a><?php endif; ?>
      <a class="btn" href="relatorio_estoque.php">Limpar</a>
    </div>
  </form>
  <?php if ($hideCost): ?><p class="rest-note">Seu perfil não visualiza preço de custo: custos e margens não são exibidos.</p><?php endif; ?>
</div>

<?php if (!$requested): ?>
  <div class="finance-card"><div class="finance-empty">Escolha os filtros e clique em <strong>Filtrar</strong> para consultar o estoque.</div></div>
<?php elseif ($err === ''): ?>
  <div class="rest-kpis">
    <div class="rest-kpi"><small>Produtos</small><strong><?= number_format($summary['itens'], 0, ',', '.') ?></strong></div>
    <div class="rest-kpi"><small>Quantidade</small><strong><?= rest_qty($summary['qtd']) ?></strong></div>
    <?php if (!$hideCost): ?><div class="rest-kpi"><small>Total custo</small><strong><?= rest_money($summary['custo']) ?></strong></div><?php endif; ?>
    <div class="rest-kpi"><small>Total venda</small><strong><?= rest_money($summary['venda']) ?></strong></div>
    <?php if (!$hideCost): ?>
    <div class="rest-kpi"><small>Margem s/ custo</small><strong><?= rest_pct($totalMarginCost) ?></strong></div>
    <div class="rest-kpi"><small>Margem s/ venda</small><strong><?= rest_pct($totalMarginSale) ?></strong></div>
    <?php endif; ?>
  </div>
  <div class="finance-card">
    <?php if ($truncated): ?><div class="msg err">Mostrando os primeiros <?= $limit ?> produtos. Os totais acima consideram todos os <?= number_format($summary['itens'], 0, ',', '.') ?>. Use os filtros para reduzir a lista.</div><?php endif; ?>
    <?php require_once __DIR__ . '/../config/grid_preferences.php'; echo nexa_grid_assets(); ?>
    <table class="rest-table"<?= nexa_grid_auto_attrs($pdo, 'auto_relatorio_estoque') ?>>
      <thead><tr><th>Código</th><th>Produto</th><th>Categoria</th><th>Un.</th><th class="num">Quantidade</th><?php if (!$hideCost): ?><th class="num">Custo unitário</th><th class="num">Total custo</th><?php endif; ?><th class="num">Valor de venda</th><th class="num">Total venda</th><?php if (!$hideCost): ?><th class="num">Margem s/ custo</th><th class="num">Margem s/ venda</th><?php endif; ?></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="<?= $hideCost ? 7 : 11 ?>">Nenhum produto encontrado com esses filtros.</td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
        $qty = (float)$r['qtd']; $cost = (float)$r['custo_unit']; $price = (float)$r['preco'];
        $mCost = $cost > 0.0001 ? ($price - $cost) / $cost * 100 : null;
        $mSale = $price > 0.0001 ? ($price - $cost) / $price * 100 : null;
        $fallback = (int)$r['custo_fallback'] === 1;
      ?>
        <tr>
          <td data-sort="<?= finance_h(str_pad($code($r), 12, '0', STR_PAD_LEFT)) ?>"><a href="produto_form.php?id=<?= (int)$r['id'] ?>">#<?= finance_h($code($r)) ?></a></td>
          <td title="<?= finance_h($r['nome']) ?>"><?= finance_h($r['nome']) ?></td>
          <td><?= finance_h($r['categoria'] ?? '') ?></td>
          <td><?= finance_h($r['unidade'] ?? '') ?></td>
          <td class="num <?= $qty < 0 ? 'rest-neg' : ($qty == 0.0 ? 'rest-zero' : '') ?>" data-sort="<?= $qty ?>"><?= rest_qty($qty) ?></td>
          <?php if (!$hideCost): ?>
          <td class="num" data-sort="<?= $cost ?>"<?= $fallback ? ' title="Sem custo médio: usado o custo do cadastro"' : '' ?>><?= rest_money($cost) ?><?= $fallback ? '*' : '' ?></td>
          <td class="num" data-sort="<?= $qty * $cost ?>"><?= rest_money($qty * $cost) ?></td>
          <?php endif; ?>
          <td class="num" data-sort="<?= $price ?>"><?= rest_money($price) ?></td>
          <td class="num" data-sort="<?= $qty * $price ?>"><?= rest_money($qty * $price) ?></td>
          <?php if (!$hideCost): ?>
          <td class="num <?= $mCost !== null && $mCost < 0 ? 'rest-neg' : '' ?>" data-sort="<?= $mCost ?? -99999 ?>"><?= rest_pct($mCost) ?></td>
          <td class="num <?= $mSale !== null && $mSale < 0 ? 'rest-neg' : '' ?>" data-sort="<?= $mSale ?? -99999 ?>"><?= rest_pct($mSale) ?></td>
          <?php endif; ?>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
    <p class="rest-note">Margem s/ custo = (venda − custo) ÷ custo. Margem s/ venda = (venda − custo) ÷ venda. Serviços não entram.<?= $costMode === 'medio' ? ' * Produto sem custo médio: usado o custo do cadastro.' : '' ?></p>
  </div>
<?php endif; ?>
<script>
(function(){
  var box=document.querySelector('.rest-cats');if(!box)return;
  var label=document.getElementById('restCatsLabel'),search=document.getElementById('restCatsSearch'),clear=document.getElementById('restCatsClear');
  function refresh(){var c=Array.from(box.querySelectorAll('input[name="cat[]"]:checked')).map(function(i){return i.value});label.textContent=c.length?(c.length===1?c[0]:c.length+' categorias'):'Todas as categorias'}
  box.addEventListener('change',refresh);
  search&&search.addEventListener('input',function(){var t=search.value.trim().toLowerCase();box.querySelectorAll('label[data-cat]').forEach(function(l){l.style.display=!t||l.dataset.cat.indexOf(t)>-1?'':'none'})});
  clear&&clear.addEventListener('click',function(){box.querySelectorAll('input[name="cat[]"]').forEach(function(i){i.checked=false});refresh()});
  document.addEventListener('click',function(e){if(box.open&&!box.contains(e.target))box.open=false});
})();
</script>
<?php finance_admin_end(); ?>
