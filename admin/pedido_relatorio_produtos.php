<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/stock_module.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
if ($printCross && function_exists('multi_company_context_permission_allowed')) {
  $canReport = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canReport) { http_response_code(403); echo 'Sem permissão para o relatório de produção da empresa selecionada pelo F11.'; exit; }
}
if (!$printCross) {
  company_sequence_ensure_schema($pdo, true);
  try { stock_ensure_schema($pdo, true); } catch (Throwable $e) {}
}

if (!function_exists('relprod_h')) {
  function relprod_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('relprod_has_column')) {
  function relprod_has_column(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('relprod_fmt_date_br')) {
  function relprod_fmt_date_br(string $date): string {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return '';
    return date('d/m/Y', strtotime($date));
  }
}

$allowedStatus = [
  'aberto' => 'Aberto',
  'confirmado' => 'Confirmado',
  'em_producao' => 'Em produção',
  'saiu_entrega' => 'Saiu para entrega',
  'todos_abertos' => 'Todos em aberto/andamento',
  'todos' => 'Todos',
];
$groupOptions = [
  'grupo' => 'Grupo de produção',
  'categoria' => 'Categoria',
  'grupo_categoria' => 'Grupo + categoria',
];

$fStatus = trim((string)($_GET['status'] ?? 'aberto'));
if (!array_key_exists($fStatus, $allowedStatus)) $fStatus = 'aberto';
$groupBy = trim((string)($_GET['group_by'] ?? 'grupo'));
if (!array_key_exists($groupBy, $groupOptions)) $groupBy = 'grupo';
$fIni = trim((string)($_GET['dt_ini'] ?? ''));
$fFim = trim((string)($_GET['dt_fim'] ?? ''));
$fPrevIni = trim((string)($_GET['prev_ini'] ?? ''));
$fPrevFim = trim((string)($_GET['prev_fim'] ?? ''));
$fCategoria = trim((string)($_GET['categoria'] ?? ''));
$fGrupo = trim((string)($_GET['grupo_producao'] ?? ''));

$hasScheduledDate = relprod_has_column($pdo, 'pedidos', 'scheduled_delivery_date');
$hasGrupoProducao = relprod_has_column($pdo, 'produtos', 'grupo_producao');
$hasUnidadeEstoque = relprod_has_column($pdo, 'produtos', 'unidade_estoque');
$hasNumeroPedido = company_sequence_has_column($pdo, 'pedidos');
$itemNameCol = '';
foreach (['nome', 'produto_nome', 'descricao_produto', 'descricao'] as $c) {
  if (relprod_has_column($pdo, 'pedido_itens', $c)) { $itemNameCol = $c; break; }
}

$cats = [];
$groups = [];
try {
  $st = $pdo->prepare("SELECT categoria FROM produtos WHERE company_id = ? AND categoria IS NOT NULL AND categoria <> '' GROUP BY categoria ORDER BY categoria ASC");
  $st->execute([$companyId]);
  $cats = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}
if ($hasGrupoProducao) {
  try {
    $st = $pdo->prepare("SELECT grupo_producao FROM produtos WHERE company_id = ? AND grupo_producao IS NOT NULL AND grupo_producao <> '' GROUP BY grupo_producao ORDER BY grupo_producao ASC");
    $st->execute([$companyId]);
    $groups = $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
  } catch (Throwable $e) {}
}

$groupExpr = $hasGrupoProducao
  ? "COALESCE(NULLIF(TRIM(p.grupo_producao), ''), NULLIF(TRIM(p.categoria), ''), 'Sem grupo')"
  : "COALESCE(NULLIF(TRIM(p.categoria), ''), 'Sem grupo')";
$catExpr = "COALESCE(NULLIF(TRIM(p.categoria), ''), 'Sem categoria')";
$itemNameExpr = $itemNameCol !== ''
  ? "COALESCE(NULLIF(TRIM(p.nome), ''), NULLIF(TRIM(pi.`" . str_replace('`', '``', $itemNameCol) . "`), ''), CONCAT('Produto #', COALESCE(pi.produto_id, 0)))"
  : "COALESCE(NULLIF(TRIM(p.nome), ''), CONCAT('Produto #', COALESCE(pi.produto_id, 0)))";
$unitExpr = $hasUnidadeEstoque ? "COALESCE(NULLIF(TRIM(p.unidade_estoque), ''), 'UN')" : "'UN'";
$pedidoNumeroExpr = $hasNumeroPedido ? "COALESCE(pe.numero_pedido, pe.id)" : "pe.id";

if ($groupBy === 'categoria') {
  $selectGroup = $catExpr;
  $selectSub = "''";
} elseif ($groupBy === 'grupo_categoria') {
  $selectGroup = $groupExpr;
  $selectSub = $catExpr;
} else {
  $selectGroup = $groupExpr;
  $selectSub = "''";
}

$where = ['pe.company_id = ?'];
$params = [$companyId];
if ($fStatus === 'todos_abertos') {
  $where[] = "pe.status IN ('aberto','confirmado','em_producao','saiu_entrega')";
} elseif ($fStatus !== 'todos') {
  $where[] = 'pe.status = ?';
  $params[] = $fStatus;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fIni)) {
  $where[] = 'DATE(pe.created_at) >= ?';
  $params[] = $fIni;
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFim)) {
  $where[] = 'DATE(pe.created_at) <= ?';
  $params[] = $fFim;
}
if ($hasScheduledDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevIni)) {
  $where[] = 'pe.scheduled_delivery_date >= ?';
  $params[] = $fPrevIni;
}
if ($hasScheduledDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevFim)) {
  $where[] = 'pe.scheduled_delivery_date <= ?';
  $params[] = $fPrevFim;
}
if ($fCategoria !== '') {
  $where[] = 'p.categoria = ?';
  $params[] = $fCategoria;
}
if ($hasGrupoProducao && $fGrupo !== '') {
  $where[] = 'p.grupo_producao = ?';
  $params[] = $fGrupo;
}

$sql = "SELECT
          {$selectGroup} AS grupo_relatorio,
          {$selectSub} AS subgrupo_relatorio,
          COALESCE(pi.produto_id, 0) AS produto_id,
          {$itemNameExpr} AS produto_nome,
          {$unitExpr} AS unidade,
          SUM(COALESCE(pi.qtd, 0)) AS qtd_total,
          COUNT(DISTINCT pe.id) AS pedidos_total,
          GROUP_CONCAT(DISTINCT {$pedidoNumeroExpr} ORDER BY {$pedidoNumeroExpr} ASC SEPARATOR ', ') AS pedidos_numeros
        FROM pedido_itens pi
        INNER JOIN pedidos pe ON pe.id = pi.pedido_id AND pe.company_id = pi.company_id
        LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id
        WHERE " . implode(' AND ', $where) . "
        GROUP BY grupo_relatorio, subgrupo_relatorio, produto_id, produto_nome, unidade
        HAVING qtd_total <> 0
        ORDER BY grupo_relatorio ASC, subgrupo_relatorio ASC, produto_nome ASC";
$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$grouped = [];
$totalPedidos = [];
foreach ($rows as $r) {
  $g = trim((string)($r['grupo_relatorio'] ?? ''));
  if ($g === '') $g = 'Sem grupo';
  $sub = trim((string)($r['subgrupo_relatorio'] ?? ''));
  $key = $g . "\n" . $sub;
  if (!isset($grouped[$key])) $grouped[$key] = ['grupo' => $g, 'subgrupo' => $sub, 'itens' => []];
  $grouped[$key]['itens'][] = $r;
  $nums = array_filter(array_map('trim', explode(',', (string)($r['pedidos_numeros'] ?? ''))));
  foreach ($nums as $n) $totalPedidos[$n] = true;
}

$periodoTxt = [];
if ($fIni !== '') $periodoTxt[] = 'De ' . relprod_fmt_date_br($fIni);
if ($fFim !== '') $periodoTxt[] = 'Até ' . relprod_fmt_date_br($fFim);
if (!$periodoTxt) $periodoTxt[] = 'Todos os períodos';
$prevTxt = [];
if ($hasScheduledDate && $fPrevIni !== '') $prevTxt[] = 'Prev. de ' . relprod_fmt_date_br($fPrevIni);
if ($hasScheduledDate && $fPrevFim !== '') $prevTxt[] = 'Prev. até ' . relprod_fmt_date_br($fPrevFim);

$reportTitle = 'Relatório de produtos por pedidos';
$ordersBackUrl = function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php') : 'pedidos.php';
$reportSelfUrl = function_exists('multi_company_context_url') ? multi_company_context_url('pedido_relatorio_produtos.php') : 'pedido_relatorio_produtos.php';
?>
<?php admin_topbar('Central - Relatórios de Pedidos','pedidos'); ?>

<style>
  .relprod-page-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap;margin:10px 0 14px;}
  .relprod-page-head h1{margin:0;color:var(--admin-primary);font-size:26px;letter-spacing:-.03em;}
  .relprod-page-head p{margin:5px 0 0;color:#64748b;font-size:13px;}
  .relprod-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .relprod-filter-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin-bottom:14px;box-shadow:0 12px 30px rgba(15,23,42,.04);}
  .relprod-filters{display:grid;grid-template-columns:repeat(4,minmax(150px,1fr)) auto;gap:10px;align-items:end;}
  .relprod-filters label{font-size:12px;font-weight:900;color:#334155;}
  .relprod-filters input,.relprod-filters select{width:100%;min-height:40px;border-radius:14px;margin-top:5px;}
  .relprod-filter-actions{display:flex;gap:8px;align-items:center;justify-content:flex-end;}
  .relprod-print-area{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:18px;box-shadow:0 12px 30px rgba(15,23,42,.04);}
  .relprod-ticket{font-family:"Courier New",monospace;color:#0f172a;max-width:72ch;margin:0 auto;background:#fff;}
  .relprod-ticket h2{font-size:18px;text-align:center;margin:0 0 4px;text-transform:uppercase;}
  .relprod-ticket .meta{font-size:12px;line-height:1.35;text-align:center;margin-bottom:8px;}
  .relprod-line{border-top:1px dashed #111;margin:7px 0;}
  .relprod-group-title{font-weight:900;font-size:14px;text-transform:uppercase;background:#f1f5f9;padding:4px 0;margin-top:6px;}
  .relprod-sub-title{font-weight:900;font-size:12px;margin-top:4px;text-transform:uppercase;}
  .relprod-row{display:grid;grid-template-columns:minmax(0,1fr) 16ch;gap:8px;padding:3px 0;border-bottom:1px dotted #cbd5e1;align-items:start;}
  .relprod-row .name{font-weight:800;word-break:break-word;}
  .relprod-row .qty{text-align:right;font-weight:950;white-space:nowrap;}
  .relprod-row .orders{grid-column:1/-1;color:#64748b;font-size:11px;line-height:1.25;margin-top:-1px;}
  .relprod-empty{text-align:center;color:#64748b;font-weight:800;padding:24px;border:1px dashed #cbd5e1;border-radius:14px;}
  .relprod-summary{font-size:12px;font-weight:900;margin-top:8px;text-align:center;}
  @media(max-width:980px){.relprod-filters{grid-template-columns:1fr 1fr}.relprod-filter-actions{grid-column:1/-1;justify-content:flex-start}.relprod-filter-actions .btn{flex:1}}
  @media(max-width:620px){.relprod-filters{grid-template-columns:1fr}.relprod-actions,.relprod-actions .btn{width:100%;justify-content:center}.relprod-filter-actions{display:grid;grid-template-columns:1fr 1fr}.relprod-filter-actions .btn{width:100%;justify-content:center}.relprod-print-area{padding:12px}.relprod-row{grid-template-columns:1fr}.relprod-row .qty{text-align:left}}
  @media print{
    @page{size:80mm auto;margin:3mm;}
    body{background:#fff!important;color:#000!important;}
    body *{visibility:hidden;}
    .relprod-print-area,.relprod-print-area *{visibility:visible;}
    .relprod-print-area{position:absolute;left:0;top:0;width:72ch;max-width:72ch;border:0!important;box-shadow:none!important;padding:0!important;margin:0!important;}
    .relprod-ticket{width:72ch;max-width:72ch;margin:0!important;color:#000!important;font-size:13px;line-height:1.22;}
    .relprod-ticket h2{font-size:15px;margin-bottom:2px;}
    .relprod-ticket .meta{font-size:11px;margin-bottom:4px;}
    .relprod-group-title{font-size:13px;background:#fff!important;border-top:1px solid #000;border-bottom:1px solid #000;padding:3px 0;margin-top:5px;}
    .relprod-sub-title{font-size:12px;margin-top:3px;}
    .relprod-row{grid-template-columns:minmax(0,1fr) 17ch;gap:5px;padding:4px 0;border-bottom:1px dotted #000;page-break-inside:avoid;}
    .relprod-row .name{font-size:14px;font-weight:950;line-height:1.18;}
    .relprod-row .qty{font-size:15px;font-weight:950;line-height:1.18;}
    .relprod-row .orders{font-size:10px;color:#000!important;}
    .relprod-line{border-top:1px dashed #000;margin:4px 0;}
    .relprod-summary{font-size:12px;}
  }
</style>

<div class="relprod-page-head no-print">
  <div>
    <h1>Relatórios de pedidos</h1>
    <p>Totalize produtos de pedidos em aberto por grupo de produção e/ou categoria para compras e produção.</p>
  </div>
  <div class="relprod-actions">
    <a class="btn" href="<?= relprod_h($ordersBackUrl) ?>">Voltar aos pedidos</a>
    <button class="btn btn-primary" type="button" onclick="window.print()">Imprimir 72 colunas</button>
  </div>
</div>

<form method="get" class="relprod-filter-card relprod-filters no-print">
  <?php if ($printCross && function_exists('multi_company_context_request_token')): $mcReportCtx = multi_company_context_request_token(); if ($mcReportCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= relprod_h($mcReportCtx) ?>"><?php endif; endif; ?>
  <label>Status
    <select name="status">
      <?php foreach ($allowedStatus as $value => $label): ?>
        <option value="<?= relprod_h($value) ?>" <?= $fStatus === $value ? 'selected' : '' ?>><?= relprod_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Separar por
    <select name="group_by">
      <?php foreach ($groupOptions as $value => $label): ?>
        <option value="<?= relprod_h($value) ?>" <?= $groupBy === $value ? 'selected' : '' ?>><?= relprod_h($label) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>Categoria
    <select name="categoria">
      <option value="">Todas</option>
      <?php foreach ($cats as $c): ?>
        <option value="<?= relprod_h($c) ?>" <?= $fCategoria === (string)$c ? 'selected' : '' ?>><?= relprod_h($c) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <?php if ($hasGrupoProducao): ?>
    <label>Grupo produção
      <select name="grupo_producao">
        <option value="">Todos</option>
        <?php foreach ($groups as $g): ?>
          <option value="<?= relprod_h($g) ?>" <?= $fGrupo === (string)$g ? 'selected' : '' ?>><?= relprod_h($g) ?></option>
        <?php endforeach; ?>
      </select>
    </label>
  <?php endif; ?>
  <label>Pedido de
    <input type="date" name="dt_ini" value="<?= relprod_h($fIni) ?>">
  </label>
  <label>Pedido até
    <input type="date" name="dt_fim" value="<?= relprod_h($fFim) ?>">
  </label>
  <?php if ($hasScheduledDate): ?>
    <label>Prevista de
      <input type="date" name="prev_ini" value="<?= relprod_h($fPrevIni) ?>">
    </label>
    <label>Prevista até
      <input type="date" name="prev_fim" value="<?= relprod_h($fPrevFim) ?>">
    </label>
  <?php endif; ?>
  <div class="relprod-filter-actions">
    <button class="btn btn-primary" type="submit">Gerar</button>
    <a class="btn" href="<?= relprod_h($reportSelfUrl) ?>">Limpar</a>
  </div>
</form>

<div class="relprod-print-area">
  <div class="relprod-ticket">
    <h2><?= relprod_h($reportTitle) ?></h2>
    <div class="meta">
      Status: <?= relprod_h($allowedStatus[$fStatus] ?? $fStatus) ?><br>
      Separação: <?= relprod_h($groupOptions[$groupBy] ?? $groupBy) ?><br>
      <?= relprod_h(implode(' | ', $periodoTxt)) ?><?= $prevTxt ? '<br>' . relprod_h(implode(' | ', $prevTxt)) : '' ?><br>
      Emitido em <?= date('d/m/Y H:i') ?>
    </div>
    <div class="relprod-line"></div>

    <?php if (!$grouped): ?>
      <div class="relprod-empty">Nenhum item encontrado para os filtros informados.</div>
    <?php endif; ?>

    <?php foreach ($grouped as $grp): ?>
      <div class="relprod-group-title"><?= relprod_h($grp['grupo']) ?></div>
      <?php if (trim((string)$grp['subgrupo']) !== ''): ?>
        <div class="relprod-sub-title"><?= relprod_h($grp['subgrupo']) ?></div>
      <?php endif; ?>
      <?php foreach ($grp['itens'] as $item): ?>
        <?php
          $unit = stock_unit_clean($item['unidade'] ?? 'UN');
          if ($unit === '') $unit = 'UN';
          $qtd = stock_qty_br($item['qtd_total'] ?? 0);
          $pedidosNums = trim((string)($item['pedidos_numeros'] ?? ''));
          $pedCount = (int)($item['pedidos_total'] ?? 0);
        ?>
        <div class="relprod-row">
          <div class="name"><?= relprod_h($item['produto_nome'] ?? '') ?></div>
          <div class="qty"><?= relprod_h($qtd . ' ' . $unit) ?></div>
          <div class="orders">Pedidos: <?= $pedCount ?><?= $pedidosNums !== '' ? ' (#' . relprod_h(str_replace(', ', ', #', $pedidosNums)) . ')' : '' ?></div>
        </div>
      <?php endforeach; ?>
    <?php endforeach; ?>

    <div class="relprod-line"></div>
    <div class="relprod-summary">
      <?= count($rows) ?> produto(s) totalizado(s) em <?= count($totalPedidos) ?> pedido(s).
    </div>
  </div>
</div>

<?php admin_footer(); ?>
