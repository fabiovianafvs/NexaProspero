<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCostCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'finance_cost_centers');
if ($financeCostCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'financeiro_centro')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para alterar Centros de Custo.');
}
$financeCostCompanyName = function_exists('multi_company_context_company_row')
  ? trim((string)((multi_company_context_company_row($pdo, $cid)['name'] ?? '')))
  : '';
if ($financeCostCompanyName === '') $financeCostCompanyName = 'Empresa #' . $cid;
$err = '';
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) $pdo->prepare('UPDATE finance_cost_centers SET active=0, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid,$id]);
      finance_redirect('financeiro_centros_custo.php?ok=' . rawurlencode('Centro de custo inativado.'));
    }
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    $code = trim((string)($_POST['code'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe o nome.');
    $analytic = isset($_POST['is_analytic']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    $level = 1;
    if ($parentId) {
      $p = finance_query_one($pdo, 'SELECT level FROM finance_cost_centers WHERE company_id=? AND id=?', [$cid,$parentId]);
      $level = $p ? ((int)$p['level'] + 1) : 1;
    }
    if ($id > 0 && $parentId === $id) throw new RuntimeException('O centro pai não pode ser o próprio centro.');
    if ($id > 0) {
      $pdo->prepare('UPDATE finance_cost_centers SET parent_id=?, code=?, name=?, level=?, is_analytic=?, active=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$parentId,$code,$name,$level,$analytic,$active,$cid,$id]);
    } else {
      $pdo->prepare('INSERT INTO finance_cost_centers (company_id,parent_id,code,name,level,is_analytic,active) VALUES (?,?,?,?,?,?,?)')->execute([$cid,$parentId,$code,$name,$level,$analytic,$active]);
    }
    finance_redirect('financeiro_centros_custo.php?ok=' . rawurlencode('Centro de custo salvo.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_cost_centers WHERE company_id=? AND id=?', [$cid,$editId]) : null;
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') $edit = $_POST;
$q = trim((string)($_GET['q'] ?? ''));
$where = ['company_id=?', 'active=1']; $params=[$cid];
if ($q !== '') { $where[] = '(code LIKE ? OR name LIKE ?)'; $like=nexa_search_like($q); array_push($params,$like,$like); }
$rows = finance_query_all($pdo, 'SELECT * FROM finance_cost_centers WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(code,name), name', $params);
$allRows = finance_query_all($pdo, 'SELECT * FROM finance_cost_centers WHERE company_id=? AND active=1 ORDER BY COALESCE(code,name), name', [$cid]);

finance_admin_start('Financeiro - Centro de Custo', 'fin_centro');
finance_page_header($financeCostCrossContext ? 'Centro de Custo · ' . $financeCostCompanyName : 'Centro de Custo', $financeCostCrossContext ? 'V11.4: cadastro protegido de Centros de Custo na empresa selecionada pelo F11.' : 'Classifique receitas e despesas por área, loja, departamento, projeto ou unidade de negócio.', [
  ['href'=>'financeiro_centros_custo.php?new=1','label'=>'+ Novo centro','class'=>'btn btn-primary'],
]);
// V11.51.6: remove as abas gerais apenas desta tela para ganhar espaço vertical.
if ($financeCostCrossContext): ?><div class="finance-card" style="border-color:#86efac;background:#f0fdf4"><strong style="color:#166534">V11.4 · Centro de Custo Multiempresa</strong><div class="finance-muted" style="margin-top:5px;color:#166534">Todas as operações desta aba ficam restritas à empresa selecionada pelo F11.</div></div><?php endif; ?><?php finance_flash(); ?>
<style>.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.finance-filter-card .finance-filter{gap:8px 10px}.finance-filter-card .finance-filter label{margin-bottom:3px}.finance-filter-card .finance-filter input,.finance-filter-card .finance-filter select{min-height:39px}.finance-filter-card .finance-filter-actions .btn{min-height:39px}</style>
<?php if($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="finance-card finance-filter-card"><form method="get" class="finance-filter"><div class="wide"><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Código ou nome"></div><div class="finance-filter-actions"><button class="btn">Filtrar</button><a class="btn" href="financeiro_centros_custo.php">Limpar</a></div></form></div>

<div class="finance-card"><div class="finance-card-head"><h2 class="finance-subtitle">Centros cadastrados</h2><span class="finance-muted"><?= count($rows) ?> centro(s)</span></div><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Código</th><th>Nome</th><th>Uso</th><th>Ações</th></tr></thead><tbody>
<?php if(!$rows): ?><tr><td colspan="4" class="finance-empty">Nenhum centro encontrado.</td></tr><?php endif; ?>
<?php foreach($rows as $r): ?><tr class="finance-tree-row <?= (int)$r['is_analytic'] ? '' : 'synthetic' ?>"><td data-label="Código" class="code"><?= finance_h($r['code'] ?? '') ?></td><td data-label="Nome" class="finance-primary"><?= finance_tree_indent((int)$r['level']) ?><?= finance_h($r['name']) ?></td><td data-label="Uso"><?= (int)$r['is_analytic'] ? '<span class="finance-badge green">Analítico</span>' : '<span class="finance-badge gray">Sintético</span>' ?></td><td data-label="Ações"><div class="finance-inline-actions"><a class="btn" href="?edit=<?= (int)$r['id'] ?>">Editar</a><form method="post" onsubmit="return confirm('Inativar este centro?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="btn btn-danger">Inativar</button></form></div></td></tr><?php endforeach; ?>
</tbody></table></div></div>

<?php if($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2><?= $editId ? 'Editar centro' : 'Novo centro' ?></h2><p class="finance-muted">Centros sintéticos agrupam; analíticos podem receber lançamentos.</p></div><a class="finance-modal-close" href="financeiro_centros_custo.php">×</a></div><form method="post" class="finance-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>"><div class="finance-form-section"><h3>Dados do centro</h3><div class="finance-form-grid"><div><label>Código</label><input name="code" value="<?= finance_h($edit['code'] ?? '') ?>"></div><div><label>Nome *</label><input name="name" value="<?= finance_h($edit['name'] ?? '') ?>" required></div><div class="span-2"><label>Centro pai</label><select name="parent_id"><?php finance_select_options(array_filter($allRows, fn($a)=>(int)$a['id'] !== (int)($edit['id'] ?? 0)), $edit['parent_id'] ?? '', 'Sem centro pai', false); ?></select></div><label class="checkline"><input type="checkbox" name="is_analytic" <?= !isset($edit['is_analytic']) || (int)$edit['is_analytic']===1 ? 'checked' : '' ?>> Analítico/lancável</label><label class="checkline"><input type="checkbox" name="active" <?= !isset($edit['active']) || (int)$edit['active']===1 ? 'checked' : '' ?>> Ativo</label></div></div><div class="finance-form-actions"><a class="btn" href="financeiro_centros_custo.php">Cancelar</a><button class="btn btn-primary">Salvar centro</button></div></form></div></div>
<?php endif; ?>
<?php finance_admin_end(); ?>
