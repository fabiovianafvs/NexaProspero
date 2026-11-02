<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/finance_module.php';

// V8.2: o cadastro de Operações fiscais/CFOPs passa a operar explicitamente
// na empresa selecionada pelo F11. Sem contexto válido, retorna ao COMPANY_ID.
$operationCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_operations')
  : (int)COMPANY_ID;
$operationCrossContext = $operationCompanyId > 0 && $operationCompanyId !== (int)COMPANY_ID;
$operationCompanyName = '';
try {
  $stOperationCompany = $pdo->prepare('SELECT name FROM companies WHERE id = ? LIMIT 1');
  $stOperationCompany->execute([$operationCompanyId]);
  $operationCompanyName = trim((string)($stOperationCompany->fetchColumn() ?: ''));
} catch (Throwable $e) {}
$cid = $operationCompanyId;
fiscal_xml_ensure_schema($pdo);
// Mantém o mesmo comportamento do acesso direto ao tenant: se a empresa alvo nunca
// teve operações, cria somente os quatro padrões fiscais já existentes no Nexa.
try { if (function_exists('fiscal_seed_default_operations')) fiscal_seed_default_operations($pdo, $cid); } catch (Throwable $e) {}
try { finance_ensure_schema($pdo); } catch (Throwable $e) {}
$err = '';

function fiscal_op_selected($a, $b): string { return (string)$a === (string)$b ? 'selected' : ''; }
function fiscal_op_checked($v): string { return !empty($v) ? 'checked' : ''; }
function fiscal_op_options(array $rows, $selected, string $empty = 'Opcional'): void {
  echo '<option value="">' . fiscal_h($empty) . '</option>';
  foreach ($rows as $r) {
    $label = trim((string)($r['code'] ?? '') . ' - ' . (string)($r['name'] ?? ''));
    echo '<option value="' . (int)$r['id'] . '" ' . fiscal_op_selected((string)$selected, (string)$r['id']) . '>' . fiscal_h($label) . '</option>';
  }
}

function fiscal_op_context_url(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
}

function fiscal_op_reference_belongs_to_company(PDO $pdo, string $table, int $companyId, int $id): bool {
  if ($id <= 0) return true;
  if (!in_array($table, ['finance_chart_accounts','finance_cost_centers'], true)) return false;
  $st = $pdo->prepare('SELECT id FROM `' . $table . '` WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1');
  $st->execute([$companyId, $id]);
  return (bool)$st->fetchColumn();
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0 || !fiscal_operation($pdo, $cid, $id)) {
        throw new RuntimeException('A operação fiscal informada não pertence à empresa selecionada.');
      }
      $pdo->prepare('UPDATE fiscal_operations SET is_active=0, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid, $id]);
      header('Location: ' . fiscal_op_context_url('fiscal_operacoes.php?ok=' . rawurlencode('Operação inativada.')));
      exit;
    }

    $id = (int)($_POST['id'] ?? 0);
    $code = strtoupper(trim((string)($_POST['code'] ?? '')));
    $name = trim((string)($_POST['name'] ?? ''));
    $direction = strtoupper(trim((string)($_POST['direction'] ?? 'ENTRADA')));
    $operationType = strtoupper(trim((string)($_POST['operation_type'] ?? 'COMPRA')));
    if ($name === '') throw new RuntimeException('Informe o nome da operação.');
    if (!in_array($direction, ['ENTRADA','SAIDA'], true)) $direction = 'ENTRADA';
    $chartAccountId = (int)($_POST['chart_account_id'] ?? 0);
    $costCenterId = (int)($_POST['cost_center_id'] ?? 0);
    if ($chartAccountId > 0 && !fiscal_op_reference_belongs_to_company($pdo, 'finance_chart_accounts', $cid, $chartAccountId)) {
      throw new RuntimeException('O plano de contas informado não pertence à empresa selecionada.');
    }
    if ($costCenterId > 0 && !fiscal_op_reference_belongs_to_company($pdo, 'finance_cost_centers', $cid, $costCenterId)) {
      throw new RuntimeException('O centro de custo informado não pertence à empresa selecionada.');
    }
    $payload = [
      $code !== '' ? $code : null,
      $name,
      $direction,
      $operationType,
      fiscal_xml_digits($_POST['cfop_in_state'] ?? ''),
      fiscal_xml_digits($_POST['cfop_in_interstate'] ?? ''),
      fiscal_xml_digits($_POST['cfop_out_state'] ?? ''),
      fiscal_xml_digits($_POST['cfop_out_interstate'] ?? ''),
      isset($_POST['create_finance']) ? 1 : 0,
      strtoupper(trim((string)($_POST['finance_movement'] ?? 'NENHUM'))),
      $chartAccountId > 0 ? $chartAccountId : null,
      $costCenterId > 0 ? $costCenterId : null,
      isset($_POST['update_stock']) ? 1 : 0,
      isset($_POST['is_active']) ? 1 : 0,
      trim((string)($_POST['notes'] ?? '')),
    ];
    if ($id > 0) {
      if (!fiscal_operation($pdo, $cid, $id)) {
        throw new RuntimeException('A operação fiscal informada não pertence à empresa selecionada.');
      }
      $sql = 'UPDATE fiscal_operations SET code=?, name=?, direction=?, operation_type=?, cfop_in_state=?, cfop_in_interstate=?, cfop_out_state=?, cfop_out_interstate=?, create_finance=?, finance_movement=?, chart_account_id=?, cost_center_id=?, update_stock=?, is_active=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?';
      $pdo->prepare($sql)->execute(array_merge($payload, [$cid, $id]));
    } else {
      $sql = 'INSERT INTO fiscal_operations (company_id, code, name, direction, operation_type, cfop_in_state, cfop_in_interstate, cfop_out_state, cfop_out_interstate, create_finance, finance_movement, chart_account_id, cost_center_id, update_stock, is_active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)';
      $pdo->prepare($sql)->execute(array_merge([$cid], $payload));
    }
    header('Location: ' . fiscal_op_context_url('fiscal_operacoes.php?ok=' . rawurlencode('Operação fiscal salva.')));
    exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$editId = (int)($_GET['id'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST');
$edit = $editId > 0 ? fiscal_operation($pdo, $cid, $editId) : null;
if ($editId > 0 && !$edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $err = 'A operação fiscal informada não foi encontrada na empresa selecionada.';
  $showForm = false;
}
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '') $edit = $_POST;
$ops = fiscal_operations($pdo, $cid, '', false);
$charts = [];
$costs = [];
try { $charts = finance_chart_options($pdo, $cid, ''); } catch (Throwable $e) {}
try { $costs = finance_cost_center_options($pdo, $cid); } catch (Throwable $e) {}

fiscal_admin_start('Fiscal - Operações', 'fiscal_operacoes');
$operationPageTitle = $operationCrossContext && $operationCompanyName !== '' ? ('Operações fiscais · ' . $operationCompanyName) : 'Operações fiscais';
$operationActions = [
  ['label'=>'+ Nova operação', 'href'=>fiscal_op_context_url('fiscal_operacoes.php?new=1'), 'class'=>'btn btn-primary'],
];
if (!$operationCrossContext) $operationActions[] = ['label'=>'RADAR XML', 'href'=>'fiscal_radar_xml.php', 'class'=>'btn'];
fiscal_page_header($operationPageTitle, 'Cadastre as naturezas de operação usadas para entrada, saída, devolução de cliente e devolução para fornecedor.', $operationActions);
fiscal_tabs('fiscal_operacoes');
fiscal_flash();
if ($operationCrossContext): ?>
  <div class="msg ok"><strong>V8.2 · Operações Multiempresa:</strong> listagem e gravação de operações/CFOPs estão operando em <strong><?= fiscal_h($operationCompanyName !== '' ? $operationCompanyName : ('Empresa #' . $operationCompanyId)) ?></strong>. A configuração pode guardar referências financeiras desta empresa, mas movimentações de Estoque, Financeiro, PDV e emissão fiscal continuam no fluxo legado até homologação própria.</div>
<?php endif;
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Operações cadastradas</h2><span class="fiscal-list-count"><?= count($ops) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Código</th><th>Operação</th><th>Direção</th><th>Financeiro</th><th>CFOPs</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php if (!$ops): ?><tr><td colspan="7" style="text-align:center;color:#64748b">Nenhuma operação cadastrada.</td></tr><?php endif; ?>
  <?php foreach ($ops as $op): ?>
    <tr>
      <td data-label="Código"><span class="code"><?= fiscal_h($op['code'] ?? '') ?></span></td>
      <td data-label="Operação" class="primary"><strong><?= fiscal_h($op['name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($op['operation_type'] ?? '') ?></div></td>
      <td data-label="Direção"><?= fiscal_h($op['direction'] ?? '') ?></td>
      <td data-label="Financeiro"><?= !empty($op['create_finance']) ? fiscal_h($op['finance_movement'] ?? '') : '<span class="fiscal-muted">Não lança</span>' ?></td>
      <td data-label="CFOPs"><span class="fiscal-muted">Ent:</span> <?= fiscal_h(trim(($op['cfop_in_state'] ?? '') . '/' . ($op['cfop_in_interstate'] ?? ''), '/')) ?><br><span class="fiscal-muted">Sai:</span> <?= fiscal_h(trim(($op['cfop_out_state'] ?? '') . '/' . ($op['cfop_out_interstate'] ?? ''), '/')) ?></td>
      <td data-label="Status"><span class="fiscal-badge <?= !empty($op['is_active']) ? '' : 'off' ?>"><?= !empty($op['is_active']) ? 'Ativa' : 'Inativa' ?></span></td>
      <td data-label="Ações"><div class="fiscal-inline-actions"><a class="btn btn-sm" href="<?= fiscal_h(fiscal_op_context_url('fiscal_operacoes.php?id=' . (int)$op['id'])) ?>">Editar</a><?php if (!empty($op['is_active'])): ?><form method="post" onsubmit="return confirm('Inativar esta operação?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$op['id'] ?>"><button class="btn btn-sm">Inativar</button></form><?php endif; ?></div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php if ($showForm): ?>
<div class="fiscal-modal" role="dialog" aria-modal="true"><div class="fiscal-modal-dialog fiscal-modal-wide"><div class="fiscal-modal-head"><div><h2><?= $edit ? 'Editar operação' : 'Nova operação fiscal' ?></h2><p class="fiscal-muted">A operação define CFOP, direção e se a entrada deve gerar contas a pagar.</p></div><a class="fiscal-modal-close" href="<?= fiscal_h(fiscal_op_context_url('fiscal_operacoes.php')) ?>">×</a></div>
<form method="post" class="fiscal-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <div class="fiscal-section"><h3>Identificação</h3><div class="fiscal-form-grid">
    <div><label>Código</label><input name="code" value="<?= fiscal_h($edit['code'] ?? '') ?>" placeholder="COMPRA, VENDA..."></div>
    <div class="span-2"><label>Nome *</label><input name="name" required value="<?= fiscal_h($edit['name'] ?? '') ?>" placeholder="Compra / entrada de mercadoria"></div>
    <div><label>Direção</label><select name="direction"><option value="ENTRADA" <?= fiscal_op_selected($edit['direction'] ?? 'ENTRADA', 'ENTRADA') ?>>Entrada</option><option value="SAIDA" <?= fiscal_op_selected($edit['direction'] ?? '', 'SAIDA') ?>>Saída</option></select></div>
    <div><label>Tipo da operação</label><select name="operation_type">
      <?php foreach(['COMPRA'=>'Compra/Entrada','VENDA'=>'Venda/Saída','DEVOLUCAO_CLIENTE'=>'Devolução de cliente','DEVOLUCAO_FORNECEDOR'=>'Devolução para fornecedor','AJUSTE'=>'Ajuste'] as $k=>$v): ?><option value="<?= $k ?>" <?= fiscal_op_selected($edit['operation_type'] ?? 'COMPRA', $k) ?>><?= fiscal_h($v) ?></option><?php endforeach; ?>
    </select></div>
    <label class="fiscal-checkline"><input type="checkbox" name="is_active" <?= !isset($edit['is_active']) || (int)($edit['is_active'] ?? 1) === 1 ? 'checked' : '' ?>> Ativa</label>
  </div></div>
  <div class="fiscal-section"><h3>CFOPs padrão</h3><div class="fiscal-form-grid">
    <div><label>CFOP entrada dentro do estado</label><input name="cfop_in_state" maxlength="4" value="<?= fiscal_h($edit['cfop_in_state'] ?? '') ?>" placeholder="1102"></div>
    <div><label>CFOP entrada fora do estado</label><input name="cfop_in_interstate" maxlength="4" value="<?= fiscal_h($edit['cfop_in_interstate'] ?? '') ?>" placeholder="2102"></div>
    <div><label>CFOP saída dentro do estado</label><input name="cfop_out_state" maxlength="4" value="<?= fiscal_h($edit['cfop_out_state'] ?? '') ?>" placeholder="5102"></div>
    <div><label>CFOP saída fora do estado</label><input name="cfop_out_interstate" maxlength="4" value="<?= fiscal_h($edit['cfop_out_interstate'] ?? '') ?>" placeholder="6102"></div>
    <label class="fiscal-checkline"><input type="checkbox" name="update_stock" <?= fiscal_op_checked($edit['update_stock'] ?? 0) ?>> Movimenta estoque futuramente</label>
  </div></div>
  <div class="fiscal-section"><h3>Integração financeira</h3><div class="fiscal-form-grid">
    <label class="fiscal-checkline"><input type="checkbox" name="create_finance" <?= fiscal_op_checked($edit['create_finance'] ?? 0) ?>> Gerar financeiro</label>
    <div><label>Movimento financeiro</label><select name="finance_movement"><option value="NENHUM" <?= fiscal_op_selected($edit['finance_movement'] ?? 'NENHUM', 'NENHUM') ?>>Nenhum</option><option value="PAGAR" <?= fiscal_op_selected($edit['finance_movement'] ?? '', 'PAGAR') ?>>Contas a pagar</option><option value="RECEBER" <?= fiscal_op_selected($edit['finance_movement'] ?? '', 'RECEBER') ?>>Contas a receber</option></select></div>
    <div><label>Plano de contas padrão</label><select name="chart_account_id"><?php fiscal_op_options($charts, $edit['chart_account_id'] ?? '', 'Selecionar ao gerar'); ?></select></div>
    <div><label>Centro de custo padrão</label><select name="cost_center_id"><?php fiscal_op_options($costs, $edit['cost_center_id'] ?? '', 'Opcional'); ?></select></div>
    <div class="span-3"><label>Observações</label><textarea name="notes"><?= fiscal_h($edit['notes'] ?? '') ?></textarea></div>
  </div></div>
  <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h(fiscal_op_context_url('fiscal_operacoes.php')) ?>">Cancelar</a><button class="btn btn-primary">Salvar operação</button></div>
</form></div></div>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
