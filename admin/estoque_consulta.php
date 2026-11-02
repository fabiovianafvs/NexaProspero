<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/stock_module.php';

if (!function_exists('multi_company_feature_enabled') || !multi_company_feature_enabled($pdo, (int)COMPANY_ID)) {
  header('Location: produtos.php');
  exit;
}

require __DIR__ . '/_chrome.php';

$stockCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_read')
  : (int)COMPANY_ID;
$stockCrossContext = $stockCompanyId !== (int)COMPANY_ID;

if ($stockCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'produtos')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para consultar Produtos/Estoque.');
}

$companyRow = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $stockCompanyId)
  : null;
$companyName = trim((string)($companyRow['name'] ?? '')) ?: ('Empresa #' . $stockCompanyId);

function estoque_v9_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function estoque_v9_qty($value): string {
  return function_exists('stock_qty_br') ? stock_qty_br($value) : number_format((float)$value, 4, ',', '.');
}

$hasStock = stock_mysql_has_column($pdo, 'produtos', 'estoque_atual');
$hasUnit = stock_mysql_has_column($pdo, 'produtos', 'unidade_estoque');
$hasMin = stock_mysql_has_column($pdo, 'produtos', 'estoque_minimo');
$hasMax = stock_mysql_has_column($pdo, 'produtos', 'estoque_maximo');
$hasManualReserved = stock_mysql_has_column($pdo, 'produtos', 'estoque_reservado');
$hasReference = stock_mysql_has_column($pdo, 'produtos', 'referencia');
$hasCompositionFlag = stock_mysql_has_column($pdo, 'produtos', 'possui_composicao');
$hasCompositionStock = stock_mysql_has_column($pdo, 'produtos', 'estoque_composicao_calculado');
$hasMovements = function_exists('multi_company_table_exists')
  ? multi_company_table_exists($pdo, 'estoque_movimentos')
  : false;
$hasOrders = function_exists('multi_company_table_exists')
  ? (multi_company_table_exists($pdo, 'pedidos') && multi_company_table_exists($pdo, 'pedido_itens'))
  : false;

$q = trim((string)($_GET['q'] ?? ''));
$status = strtolower(trim((string)($_GET['status'] ?? '')));
if (!in_array($status, ['', 'positivo', 'zerado', 'negativo', 'baixo'], true)) $status = '';
$selectedProductId = max(0, (int)($_GET['produto_id'] ?? 0));

$rows = [];
$selectedProduct = null;
$movements = [];
$summary = ['total'=>0, 'positive'=>0, 'zero'=>0, 'negative'=>0, 'low'=>0];

if ($hasStock) {
  $unitExpr = $hasUnit ? "COALESCE(NULLIF(p.unidade_estoque,''),'UN')" : "'UN'";
  $minExpr = $hasMin ? 'COALESCE(p.estoque_minimo,0)' : '0';
  $maxExpr = $hasMax ? 'COALESCE(p.estoque_maximo,0)' : '0';
  $manualReservedExpr = $hasManualReserved ? 'COALESCE(p.estoque_reservado,0)' : '0';
  $compositionFlagExpr = $hasCompositionFlag ? 'COALESCE(p.possui_composicao,0)' : '0';
  $compositionStockExpr = $hasCompositionStock ? 'COALESCE(p.estoque_composicao_calculado,0)' : '0';
  $reservedOrdersExpr = '0';
  if ($hasOrders) {
    $statuses = stock_open_order_statuses();
    $quoted = array_map(static fn(string $v): string => $pdo->quote($v), $statuses);
    $statusSql = implode(',', $quoted);
    $reservedOrdersExpr = "(SELECT COALESCE(SUM(pi.qtd),0) FROM pedido_itens pi INNER JOIN pedidos pe ON pe.id=pi.pedido_id AND pe.company_id=pi.company_id WHERE pi.company_id=p.company_id AND pi.produto_id=p.id AND pe.status IN ({$statusSql}))";
  }

  $sql = "SELECT p.id,p.nome,COALESCE(p.categoria,'') categoria,COALESCE(p.ativo,1) ativo,{$unitExpr} unidade_estoque,COALESCE(p.estoque_atual,0) estoque_atual,{$minExpr} estoque_minimo,{$maxExpr} estoque_maximo,{$manualReservedExpr} estoque_reservado_manual,{$reservedOrdersExpr} estoque_reservado_pedidos,{$compositionFlagExpr} possui_composicao,{$compositionStockExpr} estoque_composicao_calculado FROM produtos p WHERE p.company_id=?";
  $params = [$stockCompanyId];
  if ($q !== '') {
    $like = nexa_search_like($q);
    $searchParts = ["p.nome LIKE ?", "COALESCE(p.categoria,'') LIKE ?", "CAST(p.id AS CHAR) LIKE ?"];
    array_push($params, $like, $like, $like);
    if ($hasReference) { $searchParts[] = 'p.referencia LIKE ?'; $params[] = $like; }
    $sql .= ' AND (' . implode(' OR ', $searchParts) . ')';
  }
  $sql .= ' ORDER BY p.nome ASC,p.id ASC LIMIT 1200';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rawRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  foreach ($rawRows as $row) {
    $isComposite = (int)($row['possui_composicao'] ?? 0) === 1 && $hasCompositionStock;
    $current = (float)($row['estoque_atual'] ?? 0);
    $manual = (float)($row['estoque_reservado_manual'] ?? 0);
    $orders = (float)($row['estoque_reservado_pedidos'] ?? 0);
    $minimum = (float)($row['estoque_minimo'] ?? 0);
    $available = $isComposite
      ? (float)($row['estoque_composicao_calculado'] ?? 0)
      : ($current - $manual - $orders);
    $row['estoque_disponivel'] = $available;
    $row['estoque_reservado_total'] = $manual + $orders;
    $row['estoque_baixo'] = $minimum > 0 && $available <= $minimum;
    $row['estoque_tipo'] = $isComposite ? 'calculado' : 'fisico';

    $summary['total']++;
    if ($available < -0.00005) $summary['negative']++;
    elseif (abs($available) <= 0.00005) $summary['zero']++;
    else $summary['positive']++;
    if ($row['estoque_baixo']) $summary['low']++;

    $include = match ($status) {
      'positivo' => $available > 0.00005,
      'zerado' => abs($available) <= 0.00005,
      'negativo' => $available < -0.00005,
      'baixo' => (bool)$row['estoque_baixo'],
      default => true,
    };
    if ($include) $rows[] = $row;
    if ($selectedProductId > 0 && (int)$row['id'] === $selectedProductId) $selectedProduct = $row;
  }

  // Se o produto selecionado ficou fora dos filtros, carrega-o separadamente apenas
  // para a consulta de movimentos, sempre validando a empresa contextual.
  if ($selectedProductId > 0 && !$selectedProduct) {
    $stSel = $pdo->prepare("SELECT p.id,p.nome,COALESCE(p.categoria,'') categoria,COALESCE(p.ativo,1) ativo,{$unitExpr} unidade_estoque,COALESCE(p.estoque_atual,0) estoque_atual,{$minExpr} estoque_minimo,{$maxExpr} estoque_maximo,{$manualReservedExpr} estoque_reservado_manual,{$reservedOrdersExpr} estoque_reservado_pedidos,{$compositionFlagExpr} possui_composicao,{$compositionStockExpr} estoque_composicao_calculado FROM produtos p WHERE p.company_id=? AND p.id=? LIMIT 1");
    $stSel->execute([$stockCompanyId, $selectedProductId]);
    $selectedProduct = $stSel->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($selectedProduct) {
      $isComposite = (int)($selectedProduct['possui_composicao'] ?? 0) === 1 && $hasCompositionStock;
      $current = (float)($selectedProduct['estoque_atual'] ?? 0);
      $manual = (float)($selectedProduct['estoque_reservado_manual'] ?? 0);
      $orders = (float)($selectedProduct['estoque_reservado_pedidos'] ?? 0);
      $selectedProduct['estoque_reservado_total'] = $manual + $orders;
      $selectedProduct['estoque_disponivel'] = $isComposite ? (float)($selectedProduct['estoque_composicao_calculado'] ?? 0) : ($current - $manual - $orders);
      $selectedProduct['estoque_tipo'] = $isComposite ? 'calculado' : 'fisico';
    }
  }

  if ($selectedProduct && $hasMovements) {
    $stMov = $pdo->prepare('SELECT id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao,created_at FROM estoque_movimentos WHERE company_id=? AND produto_id=? ORDER BY created_at DESC,id DESC LIMIT 200');
    $stMov->execute([$stockCompanyId, (int)$selectedProduct['id']]);
    $movements = $stMov->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

$ctx = is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? $GLOBALS['MULTI_COMPANY_CONTEXT'] : [];
$ctxToken = !empty($ctx['valid']) ? trim((string)($ctx['token'] ?? '')) : '';
$productsUrl = function_exists('multi_company_context_url') ? multi_company_context_url('produtos.php') : 'produtos.php';

admin_topbar('Estoque - Consulta', 'produtos');
?>

<div class="page-head-products">
  <div>
    <h1>Estoque · <?= estoque_v9_h($companyName) ?></h1>
    <p>Consulta de saldos e histórico por empresa. Acertos manuais e parâmetros básicos são feitos pelo cadastro do produto; as vendas homologadas também registram a baixa automática no estoque desta empresa.</p>
  </div>
  <div class="actions">
    <a class="btn" href="<?= estoque_v9_h($productsUrl) ?>">Voltar para produtos</a>
  </div>
</div>

<?php if ($stockCrossContext): ?>
  <div class="card" style="margin-bottom:14px;border-color:#0ea5e9;background:#f0f9ff;">
    <strong>V11.34.1 · Estoque Multiempresa — consulta + movimentação de vendas homologada</strong>
    <div class="muted" style="margin-top:5px;color:#075985;">Os dados abaixo pertencem a <strong><?= estoque_v9_h($companyName) ?></strong>. Use o botão <strong>Produto</strong> para alterar mínimo/máximo/reserva ou realizar acerto manual. As vendas por Pedido PDV, Venda Mobile, PDV Resumido, Comandas e Pedido Detalhado já efetuam baixa automática e registram o histórico nesta empresa. Produção/ficha técnica permanece protegida até homologação própria.</div>
  </div>
<?php endif; ?>

<?php if (!$hasStock): ?>
  <div class="card card-pad"><strong>Controle de estoque indisponível.</strong><div class="hint" style="margin-top:6px">A coluna de saldo ainda não existe nesta instalação.</div></div>
<?php else: ?>
  <div class="grid" style="grid-template-columns:repeat(5,minmax(150px,1fr));gap:10px;margin-bottom:14px">
    <div class="subcard"><small>Total de produtos</small><div style="font-size:25px;font-weight:900"><?= (int)$summary['total'] ?></div></div>
    <div class="subcard"><small>Saldo positivo</small><div style="font-size:25px;font-weight:900"><?= (int)$summary['positive'] ?></div></div>
    <div class="subcard"><small>Zerados</small><div style="font-size:25px;font-weight:900"><?= (int)$summary['zero'] ?></div></div>
    <div class="subcard"><small>Negativos</small><div style="font-size:25px;font-weight:900"><?= (int)$summary['negative'] ?></div></div>
    <div class="subcard"><small>No mínimo/abaixo</small><div style="font-size:25px;font-weight:900"><?= (int)$summary['low'] ?></div></div>
  </div>

  <div class="card card-pad" style="margin-bottom:14px">
    <form method="get" style="display:grid;grid-template-columns:minmax(260px,2fr) minmax(180px,1fr) auto;gap:10px;align-items:end">
      <?php if ($ctxToken !== ''): ?><input type="hidden" name="mc_ctx" value="<?= estoque_v9_h($ctxToken) ?>"><?php endif; ?>
      <div class="row"><label>Pesquisar produto</label><input class="field" name="q" value="<?= estoque_v9_h($q) ?>" placeholder="Nome, referência, categoria ou ID"></div>
      <div class="row"><label>Situação do saldo</label><select class="field" name="status">
        <option value="" <?= $status===''?'selected':'' ?>>Todos</option>
        <option value="positivo" <?= $status==='positivo'?'selected':'' ?>>Positivo</option>
        <option value="zerado" <?= $status==='zerado'?'selected':'' ?>>Zerado</option>
        <option value="negativo" <?= $status==='negativo'?'selected':'' ?>>Negativo</option>
        <option value="baixo" <?= $status==='baixo'?'selected':'' ?>>No mínimo/abaixo</option>
      </select></div>
      <div class="actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?= estoque_v9_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php') : 'estoque_consulta.php') ?>">Limpar</a></div>
    </form>
  </div>

  <div class="card card-pad" style="overflow:auto;margin-bottom:14px">
    <div class="cardhead"><div><strong>Saldos por produto</strong><div class="hint"><?= count($rows) ?> registro(s) exibido(s)</div></div></div>
    <table class="table" style="min-width:980px;margin-top:10px">
      <thead><tr><th>Produto</th><th>Categoria</th><th>Un.</th><th style="text-align:right">Atual</th><th style="text-align:right">Reservado</th><th style="text-align:right">Disponível</th><th style="text-align:right">Mín.</th><th style="text-align:right">Máx.</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="10" class="hint">Nenhum produto encontrado.</td></tr><?php endif; ?>
      <?php foreach ($rows as $row):
        $available=(float)$row['estoque_disponivel'];
        $minimum=(float)$row['estoque_minimo'];
        $state = $available < -0.00005 ? 'Negativo' : (abs($available)<=0.00005 ? 'Zerado' : (!empty($row['estoque_baixo']) ? 'Baixo' : 'Normal'));
        $detailUrl = function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php?produto_id='.(int)$row['id']) : ('estoque_consulta.php?produto_id='.(int)$row['id']);
        $editUrl = function_exists('multi_company_context_url') ? multi_company_context_url('produto_form.php?id='.(int)$row['id']) : ('produto_form.php?id='.(int)$row['id']);
      ?>
        <tr>
          <td><strong><?= estoque_v9_h($row['nome']) ?></strong><div class="hint">#<?= (int)$row['id'] ?><?= $row['estoque_tipo']==='calculado'?' · composição':'' ?></div></td>
          <td><?= estoque_v9_h($row['categoria']) ?></td>
          <td><?= estoque_v9_h($row['unidade_estoque']) ?></td>
          <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($row['estoque_atual'])) ?></td>
          <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($row['estoque_reservado_total'])) ?></td>
          <td style="text-align:right"><strong><?= estoque_v9_h(estoque_v9_qty($available)) ?></strong></td>
          <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($minimum)) ?></td>
          <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($row['estoque_maximo'])) ?></td>
          <td><?= estoque_v9_h($state) ?></td>
          <td><div class="actions"><a class="btn btn-sm" href="<?= estoque_v9_h($detailUrl) ?>">Movimentos</a><a class="btn btn-sm" href="<?= estoque_v9_h($editUrl) ?>">Produto</a></div></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($selectedProduct): ?>
    <div class="card card-pad" style="overflow:auto">
      <div class="cardhead">
        <div><strong>Histórico · <?= estoque_v9_h($selectedProduct['nome']) ?></strong><div class="hint">Saldo disponível atual: <?= estoque_v9_h(estoque_v9_qty($selectedProduct['estoque_disponivel'])) ?> <?= estoque_v9_h($selectedProduct['unidade_estoque']) ?> · últimos 200 movimentos</div></div>
        <a class="btn" href="<?= estoque_v9_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_consulta.php') : 'estoque_consulta.php') ?>">Fechar histórico</a>
      </div>
      <?php if (!$hasMovements): ?>
        <div class="hint" style="margin-top:12px">A tabela de movimentações de estoque ainda não está disponível nesta instalação.</div>
      <?php else: ?>
        <table class="table" style="min-width:900px;margin-top:10px">
          <thead><tr><th>Data</th><th>Tipo</th><th>Origem</th><th style="text-align:right">Qtd.</th><th style="text-align:right">Saldo anterior</th><th style="text-align:right">Saldo posterior</th><th>Observação</th></tr></thead>
          <tbody>
          <?php if (!$movements): ?><tr><td colspan="7" class="hint">Nenhuma movimentação registrada para este produto.</td></tr><?php endif; ?>
          <?php foreach ($movements as $mov): ?>
            <tr>
              <td><?= estoque_v9_h($mov['created_at'] ?? '') ?></td>
              <td><?= estoque_v9_h(strtoupper((string)($mov['tipo'] ?? ''))) ?></td>
              <td><?= estoque_v9_h((string)($mov['origem_tipo'] ?? '')) ?><?= !empty($mov['origem_id']) ? ' #' . (int)$mov['origem_id'] : '' ?></td>
              <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($mov['qtd'] ?? 0)) ?></td>
              <td style="text-align:right"><?= estoque_v9_h(estoque_v9_qty($mov['saldo_anterior'] ?? 0)) ?></td>
              <td style="text-align:right"><strong><?= estoque_v9_h(estoque_v9_qty($mov['saldo_posterior'] ?? 0)) ?></strong></td>
              <td><?= estoque_v9_h($mov['observacao'] ?? '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<style>
.page-head-products{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin:14px 0}
.page-head-products h1{margin:0;color:#001e60;font-size:clamp(28px,4vw,38px);line-height:1.1}
.page-head-products p{margin:8px 0 0;color:#52627d}
@media (max-width: 900px){
  .grid{grid-template-columns:repeat(2,minmax(130px,1fr))!important}
  form[method="get"]{grid-template-columns:1fr!important}
}
</style>

<?php admin_footer(); ?>
