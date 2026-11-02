<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeFixedCrossContext = function_exists('finance_multi_company_fixed_expense_write_enabled')
  && finance_multi_company_fixed_expense_write_enabled($pdo);
if ($financeFixedCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'financeiro_despesas_fixas')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para Despesas Fixas.');
}
$financeFixedCompanyName = function_exists('multi_company_context_company_row')
  ? trim((string)((multi_company_context_company_row($pdo, $cid)['name'] ?? '')))
  : '';
if ($financeFixedCompanyName === '') $financeFixedCompanyName = 'Empresa #' . $cid;
if ($financeFixedCrossContext) {
  foreach (['finance_fixed_expenses','finance_fixed_expense_titles','finance_payables','finance_people','finance_chart_accounts','finance_cost_centers','finance_bank_accounts'] as $requiredTable) {
    if (!finance_table_exists($pdo, $requiredTable)) {
      access_render_denied('A estrutura do Financeiro necessária para Despesas Fixas ainda não está disponível nesta instalação. Aplique as migrations antes de operar pelo F11.');
    }
  }
}
$err = '';
$postAction = '';

$assertFixedRule = static function(PDO $pdo, int $companyId, int $id): array {
  if ($id <= 0) throw new RuntimeException('Despesa fixa inválida.');
  $row = finance_query_one($pdo, 'SELECT * FROM finance_fixed_expenses WHERE company_id=? AND id=? LIMIT 1', [$companyId, $id]);
  if (!$row) throw new RuntimeException('Despesa fixa não encontrada na empresa selecionada pelo F11.');
  return $row;
};

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = trim((string)($_POST['action'] ?? 'save'));
    if ($financeFixedCrossContext && !in_array($postAction, ['save','toggle','replenish','replenish_all'], true)) {
      throw new RuntimeException('V11.5: esta ação de Despesa Fixa não está homologada para o contexto F11.');
    }

    if ($postAction === 'save') {
      $id = (int)($_POST['id'] ?? 0);
      if ($financeFixedCrossContext && $id > 0) $assertFixedRule($pdo, $cid, $id);
      $personId = (int)($_POST['person_id'] ?? 0);
      $chartId = (int)($_POST['chart_account_id'] ?? 0);
      $costId = (int)($_POST['cost_center_id'] ?? 0) ?: null;
      $bankId = (int)($_POST['bank_account_id'] ?? 0) ?: null;
      $description = trim((string)($_POST['description'] ?? ''));
      $firstDueDate = finance_date_or_null($_POST['first_due_date'] ?? '');
      $dueDay = max(1, min(31, (int)($_POST['due_day'] ?? 1)));
      $amount = finance_money_in($_POST['average_amount'] ?? 0);
      $horizon = max(1, min(36, (int)($_POST['horizon_count'] ?? 12)));
      $active = !empty($_POST['active']) ? 1 : 0;
      $monthsSelected = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['months'] ?? [])), static fn($m) => $m >= 1 && $m <= 12)));
      if (!$monthsSelected) throw new RuntimeException('Selecione pelo menos um mês para a geração das contas.');
      $monthsMask = '';
      for ($month = 1; $month <= 12; $month++) $monthsMask .= in_array($month, $monthsSelected, true) ? '1' : '0';

      if ($personId <= 0) throw new RuntimeException('Selecione o fornecedor da despesa.');
      if ($financeFixedCrossContext && !finance_validate_person_role($pdo, $cid, $personId, 'supplier')) {
        throw new RuntimeException('O fornecedor selecionado não pertence à empresa ativa no F11 ou não está habilitado como fornecedor.');
      }
      if ($description === '') throw new RuntimeException('Informe o histórico da despesa fixa.');
      if (!$firstDueDate) throw new RuntimeException('Informe a data do primeiro vencimento.');
      if ($amount <= 0) throw new RuntimeException('Informe um valor médio maior que zero.');
      if (!finance_validate_analytic_chart($pdo, $cid, $chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
      if (!finance_validate_analytic_cost($pdo, $cid, $costId)) throw new RuntimeException('Selecione um centro de custo analítico.');
      if ($financeFixedCrossContext && !finance_validate_bank_account($pdo, $cid, $bankId)) {
        throw new RuntimeException('A Conta/Caixa preferencial não pertence à empresa selecionada pelo F11 ou está inativa.');
      }

      $params = [
        $personId,
        $chartId,
        $costId,
        $bankId,
        $description,
        trim((string)($_POST['document_prefix'] ?? '')),
        $firstDueDate,
        $dueDay,
        $amount,
        trim((string)($_POST['expected_payment_method'] ?? '')),
        $monthsMask,
        $horizon,
        $active,
        trim((string)($_POST['notes'] ?? '')),
      ];

      if ($id > 0) {
        $params[] = $cid;
        $params[] = $id;
        $st = $pdo->prepare('UPDATE finance_fixed_expenses SET person_id=?, chart_account_id=?, cost_center_id=?, bank_account_id=?, description=?, document_prefix=?, first_due_date=?, due_day=?, average_amount=?, expected_payment_method=?, months_mask=?, horizon_count=?, active=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?');
        $st->execute($params);
      } else {
        array_unshift($params, $cid);
        $st = $pdo->prepare('INSERT INTO finance_fixed_expenses (company_id, person_id, chart_account_id, cost_center_id, bank_account_id, description, document_prefix, first_due_date, due_day, average_amount, expected_payment_method, months_mask, horizon_count, active, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $st->execute($params);
        $id = (int)$pdo->lastInsertId();
      }

      $created = $active ? finance_fixed_expense_ensure_horizon($pdo, $cid, $id) : 0;
      $message = ($financeFixedCrossContext ? 'V11.5: despesa fixa salva na empresa selecionada pelo F11.' : 'Despesa fixa salva.') . ($created > 0 ? ' Foram geradas ' . $created . ' provisão(ões).' : '');
      finance_redirect('financeiro_despesas_fixas.php?ok=' . rawurlencode($message));
    }

    if ($postAction === 'toggle') {
      $id = (int)($_POST['id'] ?? 0);
      if ($financeFixedCrossContext) $assertFixedRule($pdo, $cid, $id);
      $active = !empty($_POST['active']) ? 1 : 0;
      $st = $pdo->prepare('UPDATE finance_fixed_expenses SET active=?, updated_at=NOW() WHERE company_id=? AND id=?');
      $st->execute([$active, $cid, $id]);
      $created = $active ? finance_fixed_expense_ensure_horizon($pdo, $cid, $id) : 0;
      finance_redirect('financeiro_despesas_fixas.php?ok=' . rawurlencode($active ? 'Despesa fixa reativada e provisões recompostas: ' . $created . '.' : 'Despesa fixa inativada. As contas já geradas foram preservadas.'));
    }

    if ($postAction === 'replenish') {
      $id = (int)($_POST['id'] ?? 0);
      if ($financeFixedCrossContext) $assertFixedRule($pdo, $cid, $id);
      $created = finance_fixed_expense_ensure_horizon($pdo, $cid, $id);
      finance_redirect('financeiro_despesas_fixas.php?ok=' . rawurlencode($created > 0 ? $created . ' nova(s) provisão(ões) gerada(s).' : 'O horizonte de provisões já está completo.'));
    }

    if ($postAction === 'replenish_all') {
      if ($financeFixedCrossContext) {
        // No F11, não oculta inconsistências de uma regra: cada uma precisa validar
        // fornecedor/plano/centro/caixa dentro da empresa contextual antes de gerar.
        $created = 0;
        $activeRuleIds = finance_query_all($pdo, 'SELECT id FROM finance_fixed_expenses WHERE company_id=? AND active=1 ORDER BY id', [$cid]);
        foreach ($activeRuleIds as $activeRule) {
          $created += finance_fixed_expense_ensure_horizon($pdo, $cid, (int)($activeRule['id'] ?? 0));
        }
      } else {
        $created = finance_fixed_expense_ensure_all($pdo, $cid);
      }
      finance_redirect('financeiro_despesas_fixas.php?ok=' . rawurlencode($created > 0 ? $created . ' nova(s) provisão(ões) gerada(s).' : 'Todas as despesas fixas já possuem o horizonte configurado.'));
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// Compatibilidade absoluta: fora do F11 mantém a recomposição automática ao abrir a tela.
// No F11, mera navegação é somente leitura; lacunas são recompostas por ações explícitas
// (Salvar/Reativar/Recompor) ou pela baixa protegida de uma provisão.
if (!$financeFixedCrossContext) {
  try { finance_fixed_expense_ensure_all($pdo, $cid); } catch (Throwable $e) {}
}

$suppliers = finance_people_options($pdo, $cid, 'supplier');
$charts = finance_chart_options($pdo, $cid, 'DEBITO');
$costs = finance_cost_center_options($pdo, $cid);
$banks = finance_bank_options($pdo, $cid);
$editId = (int)($_GET['edit'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'save');
$edit = $editId > 0 ? finance_query_one($pdo, 'SELECT * FROM finance_fixed_expenses WHERE company_id=? AND id=?', [$cid, $editId]) : null;
if ($financeFixedCrossContext && $editId > 0 && !$edit && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $showForm = false;
  if ($err === '') $err = 'Despesa fixa não encontrada na empresa selecionada pelo F11.';
}
if (!$edit && $showForm && $_SERVER['REQUEST_METHOD'] === 'POST') $edit = $_POST;

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? 'ATIVAS'));
$dueDayFilter = trim((string)($_GET['due_day'] ?? ''));
if ($dueDayFilter !== '' && (!ctype_digit($dueDayFilter) || (int)$dueDayFilter < 1 || (int)$dueDayFilter > 31)) $dueDayFilter = '';
$where = ['f.company_id=?'];
$params = [$cid];
if ($q !== '') {
  $where[] = '(f.description LIKE ? OR p.name LIKE ? OR f.document_prefix LIKE ?)';
  $like = nexa_search_like($q);
  array_push($params, $like, $like, $like);
}
if ($statusFilter === 'ATIVAS') $where[] = 'f.active=1';
if ($statusFilter === 'INATIVAS') $where[] = 'f.active=0';
if ($dueDayFilter !== '') { $where[] = 'f.due_day=?'; $params[] = (int)$dueDayFilter; }

$rules = finance_query_all($pdo, '
  SELECT f.*, p.name AS person_name, p.document AS person_document,
         ca.code AS account_code, ca.name AS account_name,
         cc.name AS cost_center_name, ba.name AS bank_name,
         (SELECT COUNT(*) FROM finance_fixed_expense_titles ft INNER JOIN finance_payables py ON py.company_id=ft.company_id AND py.id=ft.payable_id WHERE ft.company_id=f.company_id AND ft.fixed_expense_id=f.id) AS total_generated,
         (SELECT COUNT(*) FROM finance_fixed_expense_titles ft INNER JOIN finance_payables py ON py.company_id=ft.company_id AND py.id=ft.payable_id WHERE ft.company_id=f.company_id AND ft.fixed_expense_id=f.id AND py.status IN (\'ABERTO\',\'PARCIAL\') AND COALESCE(py.open_amount,0)>0.009) AS open_count,
         (SELECT MIN(py.due_date) FROM finance_fixed_expense_titles ft INNER JOIN finance_payables py ON py.company_id=ft.company_id AND py.id=ft.payable_id WHERE ft.company_id=f.company_id AND ft.fixed_expense_id=f.id AND py.status IN (\'ABERTO\',\'PARCIAL\') AND COALESCE(py.open_amount,0)>0.009) AS next_due_date,
         (SELECT MAX(ft.reference_month) FROM finance_fixed_expense_titles ft WHERE ft.company_id=f.company_id AND ft.fixed_expense_id=f.id) AS last_reference_month
    FROM finance_fixed_expenses f
    LEFT JOIN finance_people p ON p.company_id=f.company_id AND p.id=f.person_id
    LEFT JOIN finance_chart_accounts ca ON ca.company_id=f.company_id AND ca.id=f.chart_account_id
    LEFT JOIN finance_cost_centers cc ON cc.company_id=f.company_id AND cc.id=f.cost_center_id
    LEFT JOIN finance_bank_accounts ba ON ba.company_id=f.company_id AND ba.id=f.bank_account_id
   WHERE ' . implode(' AND ', $where) . '
   ORDER BY f.active DESC, f.description ASC, f.id DESC', $params);

$activeCount = 0;
$averageTotal = 0.0;
$filteredTotal = 0.0;
foreach ($rules as $rule) {
  $filteredTotal += (float)($rule['average_amount'] ?? 0);
  if (!empty($rule['active'])) {
    $activeCount++;
    $averageTotal += (float)($rule['average_amount'] ?? 0);
  }
}

$monthNames = [1=>'Jan',2=>'Fev',3=>'Mar',4=>'Abr',5=>'Mai',6=>'Jun',7=>'Jul',8=>'Ago',9=>'Set',10=>'Out',11=>'Nov',12=>'Dez'];
$defaultFirstDue = date('Y-m-d', strtotime('+1 month'));
$editMask = (string)($edit['months_mask'] ?? '111111111111');
if (strlen(preg_replace('/[^01]/', '', $editMask)) !== 12) $editMask = '111111111111';

finance_admin_start('Financeiro - Despesas Fixas', 'fin_fixas');
finance_page_header($financeFixedCrossContext ? 'Despesas Fixas · ' . $financeFixedCompanyName : 'Despesas Fixas', $financeFixedCrossContext ? 'V11.5: regras recorrentes e provisões protegidas na empresa selecionada pelo F11.' : 'Cadastre contas recorrentes e mantenha automaticamente um horizonte contínuo de provisões no Contas a Pagar.', [
  ['href'=>'financeiro_despesas_fixas.php?new=1','label'=>'+ Nova despesa fixa','class'=>'btn btn-primary'],
]);
// V11.51.7: barra geral do Financeiro removida desta tela para priorizar os registros.
if ($financeFixedCrossContext): ?><div class="finance-card" style="border-color:#86efac;background:#f0fdf4"><strong style="color:#166534">V11.5 · Despesas Fixas Multiempresa protegidas</strong><div class="finance-muted" style="margin-top:5px;color:#166534">Cadastro, inativação, recomposição do horizonte e geração das provisões usam somente <?= finance_h($financeFixedCompanyName) ?>. Títulos gerados não podem ser editados diretamente pelo F11; baixas, estornos e cancelamentos seguem as permissões protegidas do Contas a Pagar.</div></div><?php endif; ?>
<?php finance_flash();
if ($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="fixed-kpis">
  <div class="finance-kpi"><small>Despesas fixas ativas</small><strong><?= $activeCount ?></strong></div>
  <div class="finance-kpi"><small>Valor médio mensal cadastrado</small><strong class="neg">R$ <?= finance_money_br($averageTotal) ?></strong></div>
  <div class="finance-kpi"><small>Regra de provisionamento</small><strong>Horizonte contínuo</strong><div class="finance-muted">Ao quitar uma conta, uma nova competência é criada no final.</div></div>
</div>

<div class="finance-card finance-filter-card">
  <form method="get" class="fixed-filter">
    <div><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Histórico, fornecedor ou prefixo"></div>
    <div><label>Dia do vencimento</label><select name="due_day"><option value="">Todos</option><?php for ($day=1; $day<=31; $day++): ?><option value="<?= $day ?>" <?= $dueDayFilter===(string)$day ? 'selected' : '' ?>><?= $day ?></option><?php endfor; ?></select></div>
    <div><label>Situação</label><select name="status"><option value="">Todas</option><option value="ATIVAS" <?= $statusFilter==='ATIVAS'?'selected':'' ?>>Ativas</option><option value="INATIVAS" <?= $statusFilter==='INATIVAS'?'selected':'' ?>>Inativas</option></select></div>
    <div class="fixed-filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn" href="financeiro_despesas_fixas.php">Limpar</a></div>
  </form>
</div>

<div class="finance-card">
  <div class="finance-card-head">
    <div><h2 class="finance-subtitle">Despesas cadastradas</h2><span class="finance-muted"><?= count($rules) ?> registro(s) · Total filtrado: <strong>R$ <?= finance_money_br($filteredTotal) ?></strong></span></div>
    <form method="post"><input type="hidden" name="action" value="replenish_all"><button class="btn" type="submit">Recompor todas as provisões</button></form>
  </div>
  <div class="fixed-list">
    <?php if (!$rules): ?><div class="finance-empty">Nenhuma despesa fixa cadastrada.</div><?php endif; ?>
    <?php foreach ($rules as $rule):
      $mask = (string)($rule['months_mask'] ?? '111111111111');
      $months = [];
      foreach ($monthNames as $number=>$name) if (finance_fixed_expense_month_enabled($mask, $number)) $months[] = $name;
    ?>
      <article class="fixed-card <?= empty($rule['active']) ? 'inactive' : '' ?>">
        <div class="fixed-card-main">
          <div class="fixed-title"><span><?= !empty($rule['active']) ? 'Ativa' : 'Inativa' ?></span><h3><?= finance_h($rule['description'] ?? '') ?></h3><p><?= finance_h($rule['person_name'] ?? '') ?><?= !empty($rule['person_document']) ? ' · ' . finance_h($rule['person_document']) : '' ?></p></div>
          <div><span>Valor médio</span><strong>R$ <?= finance_money_br($rule['average_amount'] ?? 0) ?></strong></div>
          <div><span>Vencimento</span><strong>Dia <?= (int)($rule['due_day'] ?? 1) ?></strong><small>Próximo: <?= finance_date_br($rule['next_due_date'] ?? '') ?: '—' ?></small></div>
          <div><span>Provisões abertas</span><strong><?= (int)($rule['open_count'] ?? 0) ?> de <?= (int)($rule['horizon_count'] ?? 12) ?></strong><small>Total gerado: <?= (int)($rule['total_generated'] ?? 0) ?></small></div>
          <div><span>Meses</span><strong><?= finance_h(implode(', ', $months)) ?></strong></div>
          <div class="fixed-account"><span>Plano de contas</span><strong><span class="code"><?= finance_h($rule['account_code'] ?? '') ?></span> <?= finance_h($rule['account_name'] ?? '') ?></strong><small><?= finance_h($rule['cost_center_name'] ?? '') ?></small></div>
        </div>
        <div class="fixed-card-actions">
          <span>Competência mais recente: <?= finance_date_br($rule['last_reference_month'] ?? '') ?: '—' ?></span>
          <div>
            <a class="btn" href="financeiro_contas_pagar.php?fixed_expense_id=<?= (int)$rule['id'] ?>&status=ABERTO">Ver contas</a>
            <a class="btn" href="?edit=<?= (int)$rule['id'] ?>">Editar</a>
            <?php if (!empty($rule['active'])): ?>
              <form method="post"><input type="hidden" name="action" value="replenish"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><button class="btn" type="submit">Recompor</button></form>
              <form method="post" onsubmit="return confirm('Inativar esta despesa fixa? As contas já geradas serão mantidas.')"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="active" value="0"><button class="btn btn-danger" type="submit">Inativar</button></form>
            <?php else: ?>
              <form method="post"><input type="hidden" name="action" value="toggle"><input type="hidden" name="id" value="<?= (int)$rule['id'] ?>"><input type="hidden" name="active" value="1"><button class="btn btn-primary" type="submit">Reativar</button></form>
            <?php endif; ?>
          </div>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
</div>

<style>
.fixed-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:9px}.finance-filter-card{padding-top:9px!important;padding-bottom:9px!important}.fixed-filter{display:grid;grid-template-columns:minmax(180px,340px) minmax(160px,190px) minmax(150px,190px) auto;gap:7px 10px;align-items:end}.fixed-filter label{display:block;font-size:12px;font-weight:850;color:#475569;margin-bottom:3px}.fixed-filter input,.fixed-filter select{min-height:39px;height:39px}.fixed-filter select{width:100%;min-width:0;box-sizing:border-box;padding-right:36px;text-overflow:clip;white-space:nowrap;overflow:visible;background-position:right 10px center}.fixed-filter select[name="due_day"],.fixed-filter select[name="status"]{min-width:100%}.fixed-filter-actions{display:flex;gap:7px}.fixed-filter-actions .btn{min-height:39px;height:39px}.fixed-list{display:grid;gap:8px}.fixed-card{border:1px solid #dbe4f0;border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 22px rgba(15,23,42,.04)}.fixed-card.inactive{opacity:.72}.fixed-card-main{display:grid;grid-template-columns:minmax(220px,1.35fr) minmax(120px,.7fr) minmax(130px,.75fr) minmax(145px,.85fr) minmax(160px,1fr) minmax(210px,1.25fr);gap:10px;align-items:center;padding:11px 12px}.fixed-card-main>div{min-width:0}.fixed-card-main span{display:block;font-size:10px;font-weight:900;text-transform:uppercase;color:#64748b;margin-bottom:4px}.fixed-card-main strong{display:block;color:#0f172a;overflow:hidden;text-overflow:ellipsis}.fixed-card-main small{display:block;color:#64748b;margin-top:4px}.fixed-title span{display:inline-flex;padding:4px 8px;border-radius:999px;background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.inactive .fixed-title span{background:#f8fafc;color:#64748b;border-color:#e2e8f0}.fixed-title h3{margin:6px 0 3px;color:#0b1f5b;font-size:17px}.fixed-title p{margin:0;color:#64748b;font-size:12px}.fixed-card-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:8px 12px;background:#f8fbff;border-top:1px solid #e5eaf2}.fixed-card-actions>span{font-size:11px;color:#64748b;font-weight:800}.fixed-card-actions>div{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.fixed-card-actions form{margin:0}.fixed-card-actions .btn{min-height:34px;height:34px;padding:0 11px;border-radius:10px;font-size:12px}.fixed-months{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px}.fixed-months label{display:flex!important;align-items:center;gap:7px;padding:9px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fbff;margin:0!important}.fixed-months input{width:auto}.fixed-info{padding:12px 14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;color:#1e3a8a;font-size:13px;line-height:1.5}
@media(max-width:1250px){.fixed-card-main{grid-template-columns:repeat(3,minmax(0,1fr))}.fixed-title,.fixed-account{grid-column:span 2}.fixed-kpis{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:820px){.fixed-filter{grid-template-columns:1fr}.fixed-filter-actions{justify-content:flex-end}.fixed-card-main{grid-template-columns:repeat(2,minmax(0,1fr))}.fixed-title,.fixed-account{grid-column:1/-1}.fixed-card-actions{align-items:flex-start;flex-direction:column}.fixed-months{grid-template-columns:repeat(3,minmax(0,1fr))}.fixed-kpis{grid-template-columns:1fr}}
@media(max-width:520px){.fixed-card-main{grid-template-columns:1fr}.fixed-card-main>div{grid-column:1!important}.fixed-card-actions>div{width:100%}.fixed-card-actions .btn{flex:1}.fixed-months{grid-template-columns:repeat(2,minmax(0,1fr))}}
</style>

<?php if ($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true">
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head"><div><h2><?= $editId ? 'Editar despesa fixa #' . $editId : 'Nova despesa fixa' ?></h2><p class="finance-muted">O sistema criará e manterá automaticamente a quantidade configurada de contas futuras.</p></div><a class="finance-modal-close" href="financeiro_despesas_fixas.php">×</a></div>
    <form method="post" class="finance-form">
      <input type="hidden" name="action" value="save"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="finance-form-section"><h3>Identificação</h3><div class="finance-form-grid three">
        <div><label>Fornecedor *</label><select name="person_id" required><?php finance_select_options($suppliers, $edit['person_id'] ?? '', 'Selecione o fornecedor'); ?></select><div class="hint"><a href="<?= finance_h($financeFixedCrossContext ? (function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_pessoas.php?new=1&role=supplier&mc_finance=1') : 'financeiro_pessoas.php?new=1&role=supplier&mc_finance=1') : 'financeiro_pessoas.php?new=1') ?>">Cadastrar pessoa</a></div></div>
        <div class="span-2"><label>Histórico *</label><input name="description" value="<?= finance_h($edit['description'] ?? '') ?>" placeholder="Ex.: Energia elétrica" required></div>
        <div><label>Prefixo do documento</label><input name="document_prefix" value="<?= finance_h($edit['document_prefix'] ?? 'FIXA') ?>" maxlength="40" placeholder="ENERGIA"></div>
        <div><label>Plano de contas *</label><select name="chart_account_id" required><?php finance_select_options($charts, $edit['chart_account_id'] ?? '', 'Selecione conta analítica'); ?></select><?php if($financeFixedCrossContext): ?><div class="hint"><a href="<?= finance_h(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_plano_contas.php?new=1') : 'financeiro_plano_contas.php?new=1') ?>">Cadastrar conta</a></div><?php endif; ?></div>
        <div><label>Centro de custo</label><select name="cost_center_id"><?php finance_select_options($costs, $edit['cost_center_id'] ?? '', 'Opcional'); ?></select><?php if($financeFixedCrossContext): ?><div class="hint"><a href="<?= finance_h(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_centros_custo.php?new=1') : 'financeiro_centros_custo.php?new=1') ?>">Cadastrar centro</a></div><?php endif; ?></div>
      </div></div>

      <div class="finance-form-section"><h3>Recorrência e provisionamento</h3><div class="finance-form-grid three">
        <div><label>Primeiro vencimento *</label><input type="date" name="first_due_date" value="<?= finance_h($edit['first_due_date'] ?? $defaultFirstDue) ?>" required></div>
        <div><label>Dia de vencimento *</label><input type="number" min="1" max="31" name="due_day" value="<?= (int)($edit['due_day'] ?? date('j', strtotime($defaultFirstDue))) ?>" required><div class="hint">Nos meses mais curtos será usado o último dia.</div></div>
        <div><label>Provisões mantidas *</label><input type="number" min="1" max="36" name="horizon_count" value="<?= (int)($edit['horizon_count'] ?? 12) ?>" required><div class="hint">Use 12 para manter sempre doze contas em aberto.</div></div>
        <div><label>Valor médio *</label><input name="average_amount" value="<?= finance_money_br($edit['average_amount'] ?? 0) ?>" required></div>
        <div><label>Meio de pagamento previsto</label><input name="expected_payment_method" value="<?= finance_h($edit['expected_payment_method'] ?? '') ?>" placeholder="Pix, boleto, débito..."></div>
        <div><label>Conta/Caixa preferencial</label><select name="bank_account_id"><?php finance_select_options($banks, $edit['bank_account_id'] ?? '', 'Opcional'); ?></select><?php if($financeFixedCrossContext): ?><div class="hint"><a href="<?= finance_h(function_exists('multi_company_context_url') ? multi_company_context_url('financeiro_caixas_bancos.php?new=1') : 'financeiro_caixas_bancos.php?new=1') ?>">Cadastrar Caixa/Banco</a></div><?php endif; ?></div>
        <div class="span-3"><label>Meses em que a despesa ocorre *</label><div class="fixed-months"><?php foreach ($monthNames as $number=>$name): ?><label><input type="checkbox" name="months[]" value="<?= $number ?>" <?= finance_fixed_expense_month_enabled($editMask, $number) ? 'checked' : '' ?>> <?= $name ?></label><?php endforeach; ?></div></div>
      </div></div>

      <div class="finance-form-section"><h3>Controle</h3><div class="finance-form-grid">
        <div class="span-2 fixed-info"><strong>Como funciona:</strong> ao salvar uma nova despesa, o sistema gera as próximas provisões. Quando uma conta for quitada integralmente no Contas a Pagar, outra competência será criada no final, mantendo o horizonte configurado. <?= $financeFixedCrossContext ? 'No F11, a provisão gerada fica protegida contra edição direta; ajuste valor, fornecedor, plano ou calendário pela regra da Despesa Fixa.' : 'Quando a fatura real chegar, edite apenas o valor daquela conta.' ?></div>
        <div class="span-2"><label>Observações</label><textarea name="notes" rows="3"><?= finance_h($edit['notes'] ?? '') ?></textarea></div>
        <div class="span-2"><label class="checkline"><input type="checkbox" name="active" value="1" <?= !isset($edit['active']) || !empty($edit['active']) ? 'checked' : '' ?>> Despesa fixa ativa e gerando provisões</label></div>
      </div></div>
      <div class="finance-form-actions"><a class="btn" href="financeiro_despesas_fixas.php">Cancelar</a><button class="btn btn-primary">Salvar e provisionar</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>
<script>
(function(){
  if(!window.NexaBuscarF8)return;
  const action=document.querySelector('form.finance-form input[name="action"][value="save"]');
  const form=action?action.closest('form.finance-form'):null;
  if(!form)return;
  const f_person_id=form.querySelector('select[name="person_id"]');
  if(f_person_id)window.NexaBuscarF8.enhanceSelect(f_person_id,{title:'Buscar fornecedor',placeholder:'Selecione o fornecedor',registerUrl:'financeiro_pessoas.php?new=1&role=supplier',registerLabel:'+ Cadastrar'});
  const f_chart_account_id=form.querySelector('select[name="chart_account_id"]');
  if(f_chart_account_id)window.NexaBuscarF8.enhanceSelect(f_chart_account_id,{title:'Buscar plano de contas',placeholder:'Selecione o plano de contas',registerUrl:'financeiro_plano_contas.php?new=1',registerLabel:'+ Cadastrar'});
  const f_cost_center_id=form.querySelector('select[name="cost_center_id"]');
  if(f_cost_center_id)window.NexaBuscarF8.enhanceSelect(f_cost_center_id,{title:'Buscar centro de custo',placeholder:'Selecione o centro de custo',registerUrl:'financeiro_centros_custo.php?new=1',registerLabel:'+ Cadastrar'});
  const f_bank_account_id=form.querySelector('select[name="bank_account_id"]');
  if(f_bank_account_id)window.NexaBuscarF8.enhanceSelect(f_bank_account_id,{title:'Buscar conta/caixa',placeholder:'Selecione a conta ou caixa',registerUrl:'financeiro_caixas_bancos.php?new=1',registerLabel:'+ Cadastrar'});
})();
</script>
<?php finance_admin_end(); ?>
