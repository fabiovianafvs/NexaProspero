<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
$financeCrossTitleWrite = function_exists('finance_multi_company_title_write_enabled') && finance_multi_company_title_write_enabled($pdo);
$financeCrossSettlementWrite = function_exists('finance_multi_company_settlement_write_enabled') && finance_multi_company_settlement_write_enabled($pdo);
$financeCrossReversalWrite = function_exists('finance_multi_company_reversal_write_enabled') && finance_multi_company_reversal_write_enabled($pdo);
$financeCrossReadOnly = function_exists('finance_multi_company_readonly') && finance_multi_company_readonly($pdo);
$err = '';
$postAction = '';
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postAction = (string)($_POST['action'] ?? 'save');
    $action = $postAction;
    if ($financeCrossContext) {
      $writeAllowed = $action === 'save' && $financeCrossTitleWrite;
      $settleAllowed = in_array($action, ['settle','bulk_settle'], true) && $financeCrossSettlementWrite;
      $reverseAllowed = $action === 'reverse_settlement' && $financeCrossReversalWrite;
      $cancelAllowed = $action === 'cancel' && $financeCrossReversalWrite;
      if (!$writeAllowed && !$settleAllowed && !$reverseAllowed && !$cancelAllowed) {
        throw new RuntimeException('V11.3: pelo F11 estão liberadas criação/edição, baixas, estorno protegido e cancelamento protegido. Demais gravações continuam bloqueadas.');
      }
    }
    if ($action === 'settle') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_pagar')) throw new RuntimeException('Seu usuário não possui permissão para baixar este título.');
      $originId = (int)($_POST['id'] ?? 0);
      if ($financeCrossContext) finance_multi_company_assert_settlement_safe($pdo, $cid, 'PAGAR', [$originId]);
      finance_settle_title($pdo, $cid, 'PAGAR', $originId, array_merge($_POST, ['_nexa_manual_operator_id'=>(int)(auth_user_id() ?? 0)]));
      finance_redirect('financeiro_contas_pagar.php?ok=' . rawurlencode($financeCrossContext ? 'V11.2: baixa protegida realizada na empresa do F11 e movimento financeiro de débito gerado.' : 'Baixa realizada e movimento financeiro de débito gerado.'));
    }
    if ($action === 'bulk_settle') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_pagar')) throw new RuntimeException('Seu usuário não possui permissão para baixar títulos.');
      $originIds = (array)($_POST['title_ids'] ?? []);
      if ($financeCrossContext) finance_multi_company_assert_settlement_safe($pdo, $cid, 'PAGAR', $originIds);
      $count = finance_settle_titles_bulk($pdo, $cid, 'PAGAR', $originIds, array_merge($_POST, ['_nexa_manual_operator_id'=>(int)(auth_user_id() ?? 0)]));
      finance_redirect('financeiro_contas_pagar.php?ok=' . rawurlencode(($financeCrossContext ? 'V11.2: baixa múltipla protegida realizada na empresa do F11 em ' : 'Baixa múltipla realizada em ') . $count . ' parcela(s).'));
    }
    if ($action === 'reverse_settlement') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_baixar_pagar')) throw new RuntimeException('Seu usuário não possui permissão para estornar esta baixa.');
      $settlementId=(int)($_POST['settlement_id']??0); $reason=trim((string)($_POST['reversal_reason']??''));
      $uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
      finance_reverse_settlement($pdo,$cid,$settlementId,$reason,$uid);
      finance_redirect('financeiro_contas_pagar.php?edit='.(int)($_POST['id']??0).'&ok='.rawurlencode('Baixa estornada com segurança e saldo do título recalculado.'));
    }
    if ($action === 'cancel') {
      if (function_exists('finance_effective_permission_allowed') && !finance_effective_permission_allowed($pdo, 'financeiro_cancelar_titulos')) throw new RuntimeException('Seu usuário não possui permissão para cancelar títulos.');
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Título inválido.');
      if (finance_active_settlement_count($pdo,$cid,'PAGAR',$id)>0) throw new RuntimeException('Este título possui baixa ativa. Estorne a baixa antes de cancelar o título.');
      $fixedExpenseIdForCancel = 0;
      if ($financeCrossContext && finance_table_exists($pdo, 'finance_fixed_expense_titles')) {
        $stFixed = $pdo->prepare('SELECT fixed_expense_id FROM finance_fixed_expense_titles WHERE company_id=? AND payable_id=? LIMIT 1');
        $stFixed->execute([$cid, $id]);
        $fixedExpenseIdForCancel = (int)($stFixed->fetchColumn() ?: 0);
        if ($fixedExpenseIdForCancel > 0) {
          $fixedScopeOk = function_exists('multi_company_scope_is_cross_company')
            && multi_company_scope_is_cross_company($pdo, 'finance_fixed_expenses');
          $fixedPermissionOk = function_exists('multi_company_context_permission_allowed')
            && multi_company_context_permission_allowed($pdo, 'financeiro_despesas_fixas');
          if (!$fixedScopeOk || !$fixedPermissionOk) {
            throw new RuntimeException('V11.5: este título pertence a uma Despesa Fixa. Seu perfil precisa da permissão Despesas fixas para cancelar a provisão pelo F11.');
          }
        }
      }
      if ($financeCrossContext && $fixedExpenseIdForCancel > 0) {
        $startedCancelTransaction = !$pdo->inTransaction();
        if ($startedCancelTransaction) $pdo->beginTransaction();
        try {
          $pdo->prepare("UPDATE finance_payables SET status='CANCELADO', updated_at=NOW() WHERE company_id=? AND id=?")->execute([$cid,$id]);
          finance_fixed_expense_replenish_for_payable($pdo, $cid, $id);
          if ($startedCancelTransaction) $pdo->commit();
        } catch (Throwable $cancelFixedError) {
          if ($startedCancelTransaction && $pdo->inTransaction()) $pdo->rollBack();
          throw $cancelFixedError;
        }
      } else {
        // Compatibilidade legada: mantém exatamente o cancelamento/recomposição tolerante a falha.
        $pdo->prepare("UPDATE finance_payables SET status='CANCELADO', updated_at=NOW() WHERE company_id=? AND id=?")->execute([$cid,$id]);
        try { finance_fixed_expense_replenish_for_payable($pdo, $cid, $id); } catch (Throwable $replenishError) {}
      }
      finance_redirect('financeiro_contas_pagar.php?ok=' . rawurlencode('Título cancelado. Se ele pertencia a uma despesa fixa ativa, uma nova provisão foi criada no final.'));
    }

    $id = (int)($_POST['id'] ?? 0);
    $personId = (int)($_POST['person_id'] ?? 0);
    $chartId = (int)($_POST['chart_account_id'] ?? 0);
    $costId = (int)($_POST['cost_center_id'] ?? 0) ?: null;
    if ($personId <= 0) throw new RuntimeException('Selecione o fornecedor.');
    if ($financeCrossContext && (!function_exists('finance_validate_person_role') || !finance_validate_person_role($pdo, $cid, $personId, 'supplier'))) throw new RuntimeException('O fornecedor selecionado não pertence à empresa ativa no F11 ou não está habilitado como fornecedor.');
    if (!finance_validate_analytic_chart($pdo, $cid, $chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
    if (!finance_validate_analytic_cost($pdo, $cid, $costId)) throw new RuntimeException('Selecione um centro de custo analítico.');
    $currentTitle = null;
    if ($financeCrossContext && $id > 0) {
      $currentTitle = finance_query_one($pdo, 'SELECT * FROM finance_payables WHERE company_id=? AND id=? LIMIT 1', [$cid, $id]);
      if (!$currentTitle) throw new RuntimeException('Título não encontrado na empresa selecionada pelo F11.');
    }
    $amount = finance_money_in($_POST['amount'] ?? 0);
    if ($amount <= 0) throw new RuntimeException('Informe o valor do título.');
    if ($financeCrossContext) {
      if ($currentTitle) {
        $currentStatus = strtoupper(trim((string)($currentTitle['status'] ?? '')));
        if ($currentStatus !== 'ABERTO' || (float)($currentTitle['paid_amount'] ?? 0) > 0.009) {
          throw new RuntimeException('V11.1: somente títulos ainda ABERTOS e sem baixa podem ser editados pelo F11.');
        }
        $stSettlement = $pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id=? AND origin_type='PAGAR' AND origin_id=?");
        $stSettlement->execute([$cid, $id]);
        if ((int)$stSettlement->fetchColumn() > 0) throw new RuntimeException('V11.1: este título já possui histórico de baixa e não pode ser editado pelo F11.');
        if (finance_table_exists($pdo, 'finance_fixed_expense_titles')) {
          $stFixed = $pdo->prepare('SELECT COUNT(*) FROM finance_fixed_expense_titles WHERE company_id=? AND payable_id=?');
          $stFixed->execute([$cid, $id]);
          if ((int)$stFixed->fetchColumn() > 0) throw new RuntimeException('V11.1: este título pertence a uma Despesa Fixa. Edite-o entrando diretamente na empresa.');
        }
        $fine = (float)($currentTitle['fine_amount'] ?? 0);
        $interest = (float)($currentTitle['interest_amount'] ?? 0);
        $discount = (float)($currentTitle['discount_amount'] ?? 0);
        $paid = (float)($currentTitle['paid_amount'] ?? 0);
      } else {
        $fine = 0.0; $interest = 0.0; $discount = 0.0; $paid = 0.0;
      }
      $open = max(0, round($amount + $fine + $interest - $discount - $paid, 2));
      if ($open <= 0.009) throw new RuntimeException('V11.2: o cadastro do título precisa permanecer com valor em aberto. Para quitar ou baixar, use a ação Baixar, que possui validação própria.');
      $statusCalc = 'ABERTO';
    } else {
      $fine = finance_money_in($_POST['fine_amount'] ?? 0);
      $interest = finance_money_in($_POST['interest_amount'] ?? 0);
      $discount = finance_money_in($_POST['discount_amount'] ?? 0);
      $paid = finance_money_in($_POST['paid_amount'] ?? 0);
      $open = max(0, round($amount + $fine + $interest - $discount - $paid, 2));
      $statusCalc = $open <= 0.009 ? 'BAIXADO' : (($paid > 0) ? 'PARCIAL' : 'ABERTO');
    }
    // Parcelamento avulso: somente no cadastro de novos títulos. Todas as parcelas são atômicas.
    $parcelCount = $id === 0 ? (int)($_POST['parcel_count'] ?? 1) : 1;
    if ($parcelCount < 1 || $parcelCount > 120) throw new RuntimeException('Informe entre 1 e 120 parcelas.');
    if ($id === 0 && $parcelCount > 1) {
      $values = $_POST['parcel_amounts'] ?? [];
      $dates = $_POST['parcel_dates'] ?? [];
      if (!is_array($values) || !is_array($dates) || count($values) !== $parcelCount || count($dates) !== $parcelCount) {
        throw new RuntimeException('Confira a grade de parcelas antes de salvar.');
      }
      if ($paid > 0 || $fine > 0 || $interest > 0 || $discount > 0) {
        throw new RuntimeException('No lançamento parcelado, os títulos são criados em aberto. Multa, juros, desconto e baixas devem ser lançados em cada título.');
      }
      $parsed = []; $sumCents = 0;
      foreach (array_values($values) as $i => $rawValue) {
        $v = finance_money_in($rawValue);
        $date = finance_date_or_null($dates[$i] ?? '');
        if ($v <= 0 || !$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || date('Y-m-d', strtotime($date)) !== $date) {
          throw new RuntimeException('Parcela ' . ($i + 1) . ': informe valor positivo e vencimento válido.');
        }
        $cents = (int)round($v * 100); $sumCents += $cents;
        $parsed[] = ['amount' => $cents / 100, 'due' => $date];
      }
      if ($sumCents !== (int)round($amount * 100)) throw new RuntimeException('A soma das parcelas deve corresponder ao valor total informado.');
      $baseDoc = trim((string)($_POST['document_number'] ?? ''));
      $issue = finance_date_or_null($_POST['issue_date'] ?? '') ?: date('Y-m-d');
      $description = trim((string)($_POST['description'] ?? ''));
      $method = trim((string)($_POST['expected_payment_method'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $insert = $pdo->prepare('INSERT INTO finance_payables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $started = !$pdo->inTransaction();
      if ($started) $pdo->beginTransaction();
      try {
        foreach ($parsed as $i => $parcel) {
          $portion = str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$parcelCount, 2, '0', STR_PAD_LEFT);
          $insert->execute([$cid, $personId, $chartId, $costId, $description, $baseDoc, $portion, $issue, $parcel['due'], $method, $parcel['amount'], 0, 0, 0, 0, $parcel['amount'], 'ABERTO', $notes]);
        }
        if ($started) $pdo->commit();
      } catch (Throwable $parcelError) {
        if ($started && $pdo->inTransaction()) $pdo->rollBack();
        throw $parcelError;
      }
      finance_redirect('financeiro_contas_pagar.php?ok=' . rawurlencode($parcelCount . ' parcelas criadas com sucesso.'));
    }
    $params = [$personId,$chartId,$costId,trim((string)($_POST['description'] ?? '')),trim((string)($_POST['document_number'] ?? '')),trim((string)($_POST['installment'] ?? '01/01')),finance_date_or_null($_POST['issue_date'] ?? '') ?: date('Y-m-d'),finance_date_or_null($_POST['due_date'] ?? '') ?: date('Y-m-d'),trim((string)($_POST['expected_payment_method'] ?? '')),$amount,$fine,$interest,$discount,$paid,$open,$statusCalc,trim((string)($_POST['notes'] ?? ''))];
    if ($id > 0) {
      $params[] = $cid; $params[] = $id;
      $pdo->prepare('UPDATE finance_payables SET person_id=?, chart_account_id=?, cost_center_id=?, description=?, document_number=?, installment=?, issue_date=?, due_date=?, expected_payment_method=?, amount=?, fine_amount=?, interest_amount=?, discount_amount=?, paid_amount=?, open_amount=?, status=?, notes=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute($params);
    } else {
      array_unshift($params, $cid);
      $pdo->prepare('INSERT INTO finance_payables (company_id, person_id, chart_account_id, cost_center_id, description, document_number, installment, issue_date, due_date, expected_payment_method, amount, fine_amount, interest_amount, discount_amount, paid_amount, open_amount, status, notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute($params);
    }
    if ($id > 0 && $statusCalc === 'BAIXADO') {
      try { finance_fixed_expense_replenish_for_payable($pdo, $cid, $id); } catch (Throwable $replenishError) {}
    }
    finance_redirect('financeiro_contas_pagar.php?ok=' . rawurlencode('Conta a pagar salva.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$suppliers = finance_people_options($pdo, $cid, 'supplier');
$charts = finance_chart_options($pdo, $cid, 'DEBITO');
$costs = finance_cost_center_options($pdo, $cid);
$banks = function_exists('finance_multi_company_settlement_bank_options')
  ? finance_multi_company_settlement_bank_options($pdo, $cid)
  : finance_bank_options($pdo, $cid);
$editId = (int)($_GET['edit'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'reverse_settlement' && $err !== '') $editId = (int)($_POST['id'] ?? 0);
$settleId = (int)($_GET['settle'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postAction === 'settle') $settleId = (int)($_POST['id'] ?? 0);
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $postAction !== 'settle');
$showSettle = $settleId > 0;
$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_payables WHERE company_id=? AND id=?', [$cid,$editId]) : null;
$editFixedExpenseId = 0;
if ($edit && finance_table_exists($pdo, 'finance_fixed_expense_titles')) {
  $stEditFixed = $pdo->prepare('SELECT fixed_expense_id FROM finance_fixed_expense_titles WHERE company_id=? AND payable_id=? LIMIT 1');
  $stEditFixed->execute([$cid, (int)($edit['id'] ?? 0)]);
  $editFixedExpenseId = (int)($stEditFixed->fetchColumn() ?: 0);
}
$editIsFixedExpense = $editFixedExpenseId > 0;
if ($editId > 0 && !$edit && $_SERVER['REQUEST_METHOD'] !== 'POST') { $showForm = false; if ($err === '') $err = 'Título não encontrado na empresa selecionada.'; }
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $postAction !== 'settle') $edit = $_POST;
$settleTitle = $settleId ? finance_query_one($pdo, 'SELECT r.*, p.name AS person_name, ca.code, ca.name AS account_name FROM finance_payables r LEFT JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=r.company_id AND ca.id=r.chart_account_id WHERE r.company_id=? AND r.id=?', [$cid,$settleId]) : null;
if ($showSettle && !$settleTitle) { $showSettle = false; if ($err === '') $err = 'Título não encontrado para baixa.'; }
if ($showSettle && $settleTitle && $financeCrossContext) {
  try { finance_multi_company_assert_settlement_safe($pdo, $cid, 'PAGAR', [(int)$settleTitle['id']]); }
  catch (Throwable $settleGuardError) { $showSettle = false; if ($err === '') $err = $settleGuardError->getMessage(); }
}
$status = trim((string)($_GET['status'] ?? 'ABERTO'));
$q = trim((string)($_GET['q'] ?? ''));
$personFilter = (int)($_GET['person_id'] ?? 0);
$fixedExpenseId = (int)($_GET['fixed_expense_id'] ?? 0);
$issueIni = finance_date_or_null($_GET['issue_ini'] ?? '');
$issueFim = finance_date_or_null($_GET['issue_fim'] ?? '');
$dueIni = finance_date_or_null($_GET['due_ini'] ?? '');
$dueFim = finance_date_or_null($_GET['due_fim'] ?? '');
$settleIni = finance_date_or_null($_GET['settle_ini'] ?? '');
$settleFim = finance_date_or_null($_GET['settle_fim'] ?? '');
$where = ['r.company_id=?']; $params=[$cid];
if ($status !== '') {
  if ($status === 'ABERTO') {
    // "Em aberto" inclui títulos parcialmente baixados que ainda possuem saldo.
    $where[] = "r.status IN ('ABERTO','PARCIAL') AND COALESCE(r.open_amount,0) > 0.009";
  } else {
    $where[] = 'r.status=?';
    $params[] = $status;
  }
}
if ($personFilter > 0) { $where[] = 'r.person_id=?'; $params[] = $personFilter; }
if ($fixedExpenseId > 0) { $where[] = 'fet.fixed_expense_id=?'; $params[] = $fixedExpenseId; }
if ($q !== '') { $where[] = '(p.name LIKE ? OR r.description LIKE ? OR r.document_number LIKE ?)'; $like=nexa_search_like($q); array_push($params,$like,$like,$like); }
if ($issueIni) { $where[] = 'r.issue_date >= ?'; $params[] = $issueIni; }
if ($issueFim) { $where[] = 'r.issue_date <= ?'; $params[] = $issueFim; }
if ($dueIni) { $where[] = 'r.due_date >= ?'; $params[] = $dueIni; }
if ($dueFim) { $where[] = 'r.due_date <= ?'; $params[] = $dueFim; }
if ($settleIni || $settleFim) {
  $settleWhere = ["fs.company_id=r.company_id", "fs.origin_type='PAGAR'", 'fs.origin_id=r.id', "COALESCE(fs.status,'ATIVO')='ATIVO'"];
  if ($settleIni) { $settleWhere[] = 'fs.settlement_date >= ?'; $params[] = $settleIni; }
  if ($settleFim) { $settleWhere[] = 'fs.settlement_date <= ?'; $params[] = $settleFim; }
  $where[] = 'EXISTS (SELECT 1 FROM finance_settlements fs WHERE ' . implode(' AND ', $settleWhere) . ')';
}
$selectedPersonName = '';
if ($personFilter > 0) foreach ($suppliers as $supplier) if ((int)($supplier['id'] ?? 0) === $personFilter) { $selectedPersonName = (string)($supplier['name'] ?? ''); break; }
$rows = finance_query_all($pdo, 'SELECT r.*, p.name AS person_name, ca.code, ca.name AS account_name, fet.fixed_expense_id, fx.description AS fixed_expense_name FROM finance_payables r LEFT JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=r.company_id AND ca.id=r.chart_account_id LEFT JOIN finance_fixed_expense_titles fet ON fet.company_id=r.company_id AND fet.payable_id=r.id LEFT JOIN finance_fixed_expenses fx ON fx.company_id=fet.company_id AND fx.id=fet.fixed_expense_id WHERE ' . implode(' AND ', $where) . ' ORDER BY r.due_date ASC, r.id DESC LIMIT 500', $params);
$settlementByUserSql = finance_column_exists($pdo,'finance_settlements','settled_by_user_id')
  ? 'LEFT JOIN users su ON su.company_id=s.company_id AND su.id=s.settled_by_user_id'
  : 'LEFT JOIN users su ON 1=0';
$settlements = $edit ? finance_query_all($pdo, 'SELECT s.*, b.name AS bank_name, su.name AS settled_by_name FROM finance_settlements s LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id ' . $settlementByUserSql . ' WHERE s.company_id=? AND s.origin_type="PAGAR" AND s.origin_id=? ORDER BY s.settlement_date DESC, s.id DESC', [$cid,(int)($edit['id'] ?? 0)]) : [];

$canSettleTitles = function_exists('finance_effective_permission_allowed') ? finance_effective_permission_allowed($pdo, 'financeiro_baixar_pagar') : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_baixar_pagar'));
$canCancelTitles = function_exists('finance_effective_permission_allowed') ? finance_effective_permission_allowed($pdo, 'financeiro_cancelar_titulos') : (!function_exists('access_has_permission') || access_has_permission($pdo, $cid, 'financeiro_cancelar_titulos'));
$canManageFixedExpenses = !$financeCrossContext || (
  function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'finance_fixed_expenses')
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'financeiro_despesas_fixas')
);
if ($financeCrossContext) {
  $canSettleTitles = $financeCrossSettlementWrite && $canSettleTitles;
  $canCancelTitles = $financeCrossReversalWrite && $canCancelTitles;
  if (!$financeCrossTitleWrite && !($editIsFixedExpense && $canManageFixedExpenses)) $showForm = false;
}
$canReverseSettlements = $canSettleTitles && (!$financeCrossContext || $financeCrossReversalWrite);
$fixedProtectedView = $financeCrossContext && $editIsFixedExpense;
if (!$canSettleTitles) $showSettle = false;
finance_admin_start('Financeiro - Contas a Pagar', 'fin_pagar');
finance_page_header('Contas a Pagar', 'Lançamentos de fornecedores. A baixa gera movimento financeiro de débito.', [
  ['href'=>'financeiro_despesas_fixas.php','label'=>'Despesas fixas','class'=>'btn'],
  ['href'=>'financeiro_contas_pagar.php?new=1','label'=>'+ Novo pagamento','class'=>'btn btn-primary'],
]);
// V11.51.5: Contas a Pagar ganha mais área útil. As abas gerais do Financeiro
// permanecem nas demais telas, mas não são renderizadas nesta listagem.
finance_flash(); if ($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="finance-card finance-filter-card">
  <form method="get" class="payable-filter-form" data-lembrar-filtros="contas_pagar" data-lembrar-ignorar="edit,new,settle,fixed_expense_id">
    <?php if ($fixedExpenseId > 0): ?><input type="hidden" name="fixed_expense_id" value="<?= $fixedExpenseId ?>"><?php endif; ?>
    <div class="payable-filter-grid">
      <div><label>Emissão inicial</label><input type="date" name="issue_ini" value="<?= finance_h($issueIni ?? '') ?>"></div>
      <div><label>Emissão final</label><input type="date" name="issue_fim" value="<?= finance_h($issueFim ?? '') ?>"></div>
      <div><label>Vencimento inicial</label><input type="date" name="due_ini" value="<?= finance_h($dueIni ?? '') ?>"></div>
      <div><label>Vencimento final</label><input type="date" name="due_fim" value="<?= finance_h($dueFim ?? '') ?>"></div>
      <div><label>Baixa inicial</label><input type="date" name="settle_ini" value="<?= finance_h($settleIni ?? '') ?>"></div>
      <div><label>Baixa final</label><input type="date" name="settle_fim" value="<?= finance_h($settleFim ?? '') ?>"></div>
      <div><label>Situação</label><select name="status">
        <option value="">Todos</option>
        <option value="ABERTO" <?= $status==='ABERTO'?'selected':'' ?>>Em aberto (inclui parciais)</option>
        <option value="PARCIAL" <?= $status==='PARCIAL'?'selected':'' ?>>Somente parciais</option>
        <option value="BAIXADO" <?= $status==='BAIXADO'?'selected':'' ?>>Baixados</option>
        <option value="CANCELADO" <?= $status==='CANCELADO'?'selected':'' ?>>Cancelados</option>
      </select></div>
    </div>
    <div class="payable-filter-second">
      <div class="payable-person-picker">
        <label>Pessoa</label>
        <input type="hidden" name="person_id" id="payablePersonId" value="<?= $personFilter ?>">
        <div class="finance-field-inline">
          <input id="payablePersonName" value="<?= finance_h($selectedPersonName) ?>" placeholder="Todos os fornecedores" readonly>
          <button class="btn" type="button" id="openPersonLookup">Buscar</button>
          <button class="btn" type="button" id="clearPersonLookup" title="Limpar pessoa">Limpar</button>
        </div>
      </div>
      <div class="payable-history payable-history-second"><label>Histórico</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Histórico, documento ou fornecedor"></div>
      <div class="payable-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="financeiro_contas_pagar.php?limpar_filtros=1">Limpar filtros</a></div>
    </div>
  </form><script src="/assets/nexa-lembrar-filtros.js?v=1"></script>
</div>

<?php if ($fixedExpenseId > 0): ?>
<div class="msg ok">Mostrando somente as contas provisionadas pela despesa fixa #<?= $fixedExpenseId ?>. <a href="financeiro_contas_pagar.php">Remover este filtro</a></div>
<?php endif; ?>
<?php
  require_once __DIR__ . '/../config/grid_preferences.php';
  $payGridPrefs = nexa_grid_prefs_load($pdo, (int)COMPANY_ID, 'fin_pagar');
  $payGridToday = date('Y-m-d');
  // Data da última baixa ativa de cada título (uma consulta para a lista toda).
  $paySettleAgg = [];
  if ($rows && finance_table_exists($pdo, 'finance_settlements')) {
    try {
      $payIds = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
      if ($payIds) {
        $payPh = implode(',', array_fill(0, count($payIds), '?'));
        $payStatusSql = finance_column_exists($pdo, 'finance_settlements', 'status') ? " AND COALESCE(status,'ATIVO')='ATIVO'" : '';
        foreach (finance_query_all($pdo, "SELECT origin_id, MAX(settlement_date) AS last_settlement_date, COUNT(*) AS settlement_count FROM finance_settlements WHERE company_id=? AND origin_type='PAGAR' AND origin_id IN ({$payPh}){$payStatusSql} GROUP BY origin_id", array_merge([$cid], $payIds)) as $ps) {
          $paySettleAgg[(int)$ps['origin_id']] = $ps;
        }
      }
    } catch (Throwable $ignored) {}
  }
?>
<link rel="stylesheet" href="assets/nexa_titles_grid.css?v=20261108_2">
<style id="ntgFirstPaint">.ntg-wrap:not(.is-ready){visibility:hidden}</style>
<script>setTimeout(function(){var s=document.getElementById('ntgFirstPaint');if(s&&!document.documentElement.classList.contains('ntg-ready'))s.remove();},4000);</script>
<div class="finance-card">
  <div class="finance-card-head payable-list-head">
    <div><h2 class="finance-subtitle">Títulos cadastrados</h2><div class="ntg-subline"><span class="finance-muted"><?= count($rows) ?> título(s)</span><span class="ntg-legend"><span><i class="lg-overdue"></i>Vencida</span><span><i class="lg-today"></i>Vence hoje</span></span></div></div>
    <div class="payable-head-actions">
      <?php if($canSettleTitles): ?>
        <div class="payable-selected-summary" id="bulkSelectedSummary" hidden>
          <span><b id="bulkSelectedHeaderCount">0</b> parcela(s) selecionada(s)</span>
          <strong>Total a pagar: R$ <span id="bulkSelectedHeaderTotal">0,00</span></strong>
        </div>
        <label class="payable-check-all"><input type="checkbox" id="bulkCheckAll"> Selecionar todos em aberto</label>
        <button class="btn btn-primary" type="button" id="openBulkSettleBtn" disabled>Baixar selecionados</button>
      <?php endif; ?>
      <div class="ntg-toolbar">
        <details class="ntg-picker"><summary class="btn btn-sm">☷ Colunas</summary><div class="ntg-picker-panel"><strong>Colunas visíveis</strong><div data-ntg-columns="fin_pagar"></div><button type="button" class="btn ntg-reset" data-ntg-reset="fin_pagar">Restaurar padrão</button><small>Clique no título para ordenar · arraste ⠿ para mudar de lugar · ajuste a largura pela borda do cabeçalho.</small></div></details>
        <span class="ntg-pref-state" data-ntg-state="fin_pagar" role="status" aria-live="polite"></span>
      </div>
    </div>
  </div>
  <?php if(!$rows): ?><div class="finance-empty">Nenhum título encontrado.</div><?php else: ?>
  <div class="ntg-wrap">
    <table class="ntg-table" data-ntg="fin_pagar" data-scope="<?= (int)COMPANY_ID ?>" data-save-url="grid_preferencias.php" data-csrf="<?= finance_h(nexa_grid_csrf()) ?>"
      data-prefs="<?= finance_h(json_encode($payGridPrefs ?: new stdClass(), JSON_UNESCAPED_UNICODE)) ?>"
      data-defaults='{"id":70,"fornecedor":230,"doc":130,"historico":230,"emissao":92,"vencimento":108,"baixa":122,"situacao":100,"original":110,"aberto":110,"plano":210}'>
      <thead><tr>
        <th class="ntg-sel"></th>
        <th data-col="id" data-type="num">ID</th>
        <th data-col="fornecedor">Fornecedor</th>
        <th data-col="doc">Documento</th>
        <th data-col="historico">Histórico</th>
        <th data-col="emissao">Emissão</th>
        <th data-col="vencimento">Vencimento</th>
        <th data-col="baixa">Data da baixa</th>
        <th data-col="situacao">Situação</th>
        <th data-col="original" data-type="num" class="ntg-num">Original</th>
        <th data-col="aberto" data-type="num" class="ntg-num">Em aberto</th>
        <th data-col="plano">Plano</th>
        <th class="ntg-actions">Ações</th>
      </tr></thead>
      <tbody>
    <?php foreach($rows as $r):
      $rowIsFixedExpense = (int)($r['fixed_expense_id'] ?? 0) > 0;
      $rowFixedExpenseAllowed = !$financeCrossContext || !$rowIsFixedExpense || $canManageFixedExpenses;
      $canSelectRow = $canSettleTitles && in_array((string)$r['status'], ['ABERTO','PARCIAL'], true)
        && $rowFixedExpenseAllowed;
      $rowOriginal = (float)($r['amount'] ?? 0);
      $rowOpen = (float)($r['open_amount'] ?? 0);
      $rowIsOpen = in_array((string)$r['status'], ['ABERTO','PARCIAL'], true) && $rowOpen > 0.009;
      $rowDueIso = substr((string)($r['due_date'] ?? ''), 0, 10);
      $rowClass = !$rowIsOpen || $rowDueIso === '' ? '' : ($rowDueIso < $payGridToday ? 'ntg-overdue' : ($rowDueIso === $payGridToday ? 'ntg-today' : ''));
      $rowDocText = trim((string)($r['document_number'] ?? '') . (!empty($r['installment']) ? ' · Parcela ' . (string)$r['installment'] : ''), ' ·');
      $rowPlanText = trim((string)($r['code'] ?? '') . ' ' . (string)($r['account_name'] ?? ''));
    ?>
      <tr class="<?= $rowClass ?>">
        <td class="ntg-sel"><?php if($canSelectRow): ?><input class="bulk-title-check" type="checkbox" value="<?= (int)$r['id'] ?>"
            data-person="<?= finance_h($r['person_name'] ?? '') ?>"
            data-desc="<?= finance_h($r['description'] ?? '') ?>"
            data-doc="<?= finance_h($r['document_number'] ?? '') ?>"
            data-installment="<?= finance_h($r['installment'] ?? '') ?>"
            data-due="<?= finance_h(finance_date_br($r['due_date'] ?? '')) ?>"
            data-due-iso="<?= finance_h($r['due_date'] ?? '') ?>"
            data-original="<?= number_format($rowOriginal, 2, '.', '') ?>"
            data-open="<?= number_format($rowOpen, 2, '.', '') ?>"><?php endif; ?></td>
        <td data-col="id" data-sort="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></td>
        <td data-col="fornecedor" title="<?= finance_h($r['person_name'] ?? '') ?>"><strong><?= finance_h($r['person_name'] ?? '') ?></strong></td>
        <td data-col="doc" title="<?= finance_h($rowDocText) ?>"><?= $rowDocText !== '' ? finance_h($rowDocText) : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="historico" title="<?= finance_h($r['description'] ?? '') ?>"><?= finance_h($r['description'] ?? '') ?: '<span class="ntg-muted">—</span>' ?><?php if ($rowIsFixedExpense): ?><span class="ntg-badge-fixed">Despesa fixa #<?= (int)$r['fixed_expense_id'] ?></span><?php endif; ?></td>
        <td data-col="emissao" data-sort="<?= finance_h(substr((string)($r['issue_date'] ?? ''), 0, 10)) ?>"><?= finance_date_br($r['issue_date'] ?? '') ?></td>
        <td data-col="vencimento" data-sort="<?= finance_h($rowDueIso) ?>"><?= finance_date_br($r['due_date'] ?? '') ?></td>
        <?php $rowAgg = $paySettleAgg[(int)$r['id']] ?? []; $rowLastSettle = substr((string)($rowAgg['last_settlement_date'] ?? ''), 0, 10); $rowSettleCount = (int)($rowAgg['settlement_count'] ?? 0); ?>
        <td data-col="baixa" data-sort="<?= finance_h($rowLastSettle) ?>"<?= $rowSettleCount > 1 ? ' title="' . $rowSettleCount . ' baixas · última em ' . finance_h(finance_date_br($rowLastSettle)) . '"' : '' ?>><?= $rowLastSettle !== '' ? finance_date_br($rowLastSettle) . ($rowSettleCount > 1 ? ' <span class="ntg-muted">(' . $rowSettleCount . ')</span>' : '') : '<span class="ntg-muted">—</span>' ?></td>
        <td data-col="situacao" data-sort="<?= finance_h((string)$r['status']) ?>"><?= finance_status_badge((string)$r['status']) ?></td>
        <td data-col="original" class="ntg-num" data-sort="<?= number_format($rowOriginal, 2, '.', '') ?>">R$ <?= finance_money_br($rowOriginal) ?></td>
        <td data-col="aberto" class="ntg-num" data-sort="<?= number_format($rowOpen, 2, '.', '') ?>"><strong>R$ <?= finance_money_br($rowOpen) ?></strong></td>
        <td data-col="plano" title="<?= finance_h($rowPlanText) ?>"><span class="code"><?= finance_h($r['code'] ?? '') ?></span> <?= finance_h($r['account_name'] ?? '') ?></td>
        <td class="ntg-actions"><div class="ntg-icons">
            <?php
              $canEditCrossTitle = !$financeCrossContext || ($financeCrossTitleWrite && strtoupper((string)($r['status'] ?? '')) === 'ABERTO' && (float)($r['paid_amount'] ?? 0) <= 0.009 && !$rowIsFixedExpense);
              if ($canEditCrossTitle):
            ?><a class="ntg-icon" href="?edit=<?= (int)$r['id'] ?>" title="Editar título" aria-label="Editar título"><?= nexa_grid_icon('edit') ?></a><?php elseif($financeCrossContext && $rowIsFixedExpense && $rowFixedExpenseAllowed): ?><a class="ntg-icon" href="?edit=<?= (int)$r['id'] ?>" title="Detalhes do título" aria-label="Detalhes do título"><?= nexa_grid_icon('view') ?></a><?php endif; ?>
            <?php if(in_array((string)$r['status'], ['ABERTO','PARCIAL'], true) && $canCancelTitles && $rowFixedExpenseAllowed): ?><form method="post" onsubmit="return confirm('Cancelar este título?')"><input type="hidden" name="action" value="cancel"><input type="hidden" name="id" value="<?= (int)$r['id'] ?>"><button class="ntg-icon ntg-danger" title="Cancelar título" aria-label="Cancelar título"><?= nexa_grid_icon('cancel') ?></button></form><?php endif; ?>
        </div></td>
      </tr>
    <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
</div>
<script src="assets/nexa_titles_grid.js?v=20261108_2" defer></script>

<style>
.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.payable-filter-form label,.payable-person-picker>label{display:block;font-size:12px;font-weight:850;color:#475569;margin-bottom:3px}.payable-filter-grid{display:grid;grid-template-columns:repeat(6,minmax(118px,.78fr)) minmax(145px,.9fr);gap:8px 10px;align-items:end}.payable-filter-grid input,.payable-filter-grid select{min-height:39px}.payable-filter-second{display:grid;grid-template-columns:minmax(300px,420px) minmax(260px,1fr) auto;align-items:end;gap:10px;margin-top:4px}.payable-person-picker,.payable-history-second{min-width:0}.payable-person-picker .finance-field-inline{display:grid;grid-template-columns:minmax(145px,1fr) auto auto;gap:8px}.payable-person-picker .finance-field-inline .btn{white-space:nowrap}.payable-filter-actions{display:flex;gap:8px;margin-left:0;white-space:nowrap}.payable-filter-actions .btn{min-height:39px}.payable-list-head{align-items:center}.payable-head-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap}.payable-selected-summary{display:flex;align-items:center;gap:10px;padding:8px 11px;border:1px solid #86efac;border-radius:12px;background:#f0fdf4;color:#166534;font-size:12px;white-space:nowrap}.payable-selected-summary[hidden]{display:none}.payable-selected-summary span{font-weight:800}.payable-selected-summary strong{font-weight:950}.payable-check-all{display:flex;align-items:center;gap:7px;padding:9px 11px;border:1px solid #dbe4f0;border-radius:12px;background:#f8fbff;font-size:12px;font-weight:850;color:#475569}.payable-help{margin:-5px 0 13px}.payable-list{display:grid;gap:12px}.payable-title-card{border:1px solid #dbe4f0;border-radius:18px;background:#fff;overflow:hidden;box-shadow:0 8px 22px rgba(15,23,42,.035)}.payable-title-main{display:grid;grid-template-columns:34px 62px minmax(175px,1.15fr) minmax(180px,1.25fr) 92px 100px 100px 105px 110px minmax(145px,.9fr);gap:10px;align-items:center;padding:13px 14px}.payable-title-main>div{min-width:0}.payable-title-main span{display:block;font-size:10px;font-weight:850;text-transform:uppercase;letter-spacing:.025em;color:#64748b;margin-bottom:4px}.payable-title-main strong{display:block;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#0f172a;font-size:13px}.payable-title-client small{display:block;margin-top:4px;color:#64748b;font-size:11px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.fixed-expense-badge{display:inline-flex!important;width:max-content;margin-top:5px!important;padding:3px 7px;border-radius:999px;background:#eff6ff;color:#1d4ed8!important;border:1px solid #bfdbfe;font-size:10px!important;font-weight:900}.payable-select-cell{text-align:center}.payable-select-cell input{width:18px;height:18px}.payable-title-actions{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:#f8fbff;border-top:1px solid #e5eaf2}.payable-title-actions>span{font-size:11px;text-transform:uppercase;font-weight:900;color:#64748b}.payable-title-actions>div{display:flex;gap:7px;align-items:center;flex-wrap:wrap}.payable-title-actions form{margin:0}.payable-title-actions .btn{min-height:34px;height:34px;padding:0 11px;border-radius:10px;font-size:12px}.finance-bulk-modal .finance-modal-dialog{width:min(1220px,100%)}.finance-bulk-common{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.finance-bulk-common .wide{grid-column:span 4}.bulk-mode-box{display:flex;gap:10px;align-items:center;flex-wrap:wrap;padding:11px;border:1px solid #dbe4f0;border-radius:15px;background:#f8fbff}.bulk-mode-box label{display:flex;align-items:center;gap:6px;font-weight:850}.bulk-partial-controls{display:flex;align-items:end;gap:8px;flex:1 1 350px}.bulk-partial-controls>div{flex:1}.bulk-partial-controls input{min-height:42px}.finance-bulk-summary{display:flex;gap:10px;flex-wrap:wrap;margin:12px 0}.finance-bulk-total{border:1px solid #bfdbfe;background:#eff6ff;border-radius:14px;padding:9px 12px;font-weight:950;color:#0b1f5b}.bulk-settle-list{display:grid;gap:10px}.bulk-settle-row{border:1px solid #dbe4f0;border-radius:16px;padding:12px;background:#fff}.bulk-settle-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.bulk-settle-head strong{display:block;color:#0b1f5b}.bulk-settle-head small{display:block;color:#64748b;margin-top:3px}.bulk-settle-fields{display:grid;grid-template-columns:repeat(7,minmax(105px,1fr));gap:9px;align-items:end}.bulk-settle-fields label{font-size:10px;text-transform:uppercase;font-weight:850;color:#64748b}.bulk-settle-fields input{min-height:39px;padding:7px 8px}.bulk-readonly{min-height:39px;border:1px solid #dbe4f0;border-radius:12px;padding:9px;background:#f8fbff;font-weight:850;white-space:nowrap}.bulk-balance.partial{color:#b45309}.bulk-balance.paid{color:#047857}.person-lookup-list{display:grid;gap:8px;max-height:55vh;overflow:auto}.person-lookup-item{width:100%;text-align:left;border:1px solid #dbe4f0;background:#fff;border-radius:14px;padding:11px;cursor:pointer}.person-lookup-item:hover{border-color:#60a5fa;background:#eff6ff}.person-lookup-item strong{display:block;color:#0b1f5b}.person-lookup-item small{display:block;color:#64748b;margin-top:3px}
@media(max-width:1320px){.payable-filter-grid{grid-template-columns:repeat(4,minmax(140px,1fr))}.payable-filter-second{grid-template-columns:minmax(300px,390px) minmax(240px,1fr) auto}.payable-title-main{grid-template-columns:34px 60px minmax(170px,1.3fr) minmax(170px,1.4fr) repeat(4,minmax(92px,.7fr));}.payable-title-plan{grid-column:3 / span 2}.bulk-settle-fields{grid-template-columns:repeat(4,minmax(120px,1fr))}}
@media(max-width:980px){.payable-filter-second{grid-template-columns:1fr 1fr}.payable-filter-actions{grid-column:1/-1;justify-content:flex-end}}
@media(max-width:860px){.payable-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.payable-filter-second{grid-template-columns:1fr}.payable-person-picker{max-width:none}.payable-filter-actions{grid-column:auto;margin-top:0;justify-content:flex-end}.payable-title-main{grid-template-columns:34px repeat(2,minmax(0,1fr))}.payable-title-client,.payable-title-history,.payable-title-plan{grid-column:span 2}.bulk-settle-fields{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-bulk-common{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-bulk-common .wide{grid-column:1/-1}}
@media(max-width:560px){.payable-filter-grid{grid-template-columns:1fr}.payable-person-picker .finance-field-inline{grid-template-columns:1fr 1fr}.payable-person-picker .finance-field-inline input{grid-column:1/-1}.payable-filter-actions{display:grid;grid-template-columns:1fr 1fr}.payable-title-main{grid-template-columns:34px 1fr}.payable-title-main>div:not(.payable-select-cell){grid-column:2}.payable-title-actions{align-items:flex-start;flex-direction:column}.payable-title-actions>div{width:100%}.payable-title-actions .btn{flex:1}.bulk-settle-fields,.finance-bulk-common{grid-template-columns:1fr}.bulk-mode-box{align-items:stretch;flex-direction:column}.bulk-partial-controls{display:grid;grid-template-columns:1fr;flex-basis:auto}}
</style>

<div class="finance-modal" id="personLookupModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog">
    <div class="finance-modal-head"><div><h2>Buscar fornecedor</h2><p class="finance-muted">Pesquise por nome ou documento e selecione uma pessoa.</p></div><button type="button" class="finance-modal-close" id="closePersonLookup">×</button></div>
    <div class="finance-form-section"><label>Pesquisar</label><input id="personLookupSearch" placeholder="Nome, CPF ou CNPJ" autocomplete="off"></div>
    <div class="person-lookup-list" id="personLookupList">
      <?php foreach($suppliers as $pessoa): ?><button type="button" class="person-lookup-item" data-id="<?= (int)$pessoa['id'] ?>" data-name="<?= finance_h($pessoa['name'] ?? '') ?>" data-search="<?= finance_h(strtolower(trim((string)($pessoa['name'] ?? '') . ' ' . (string)($pessoa['document'] ?? '')))) ?>"><strong><?= finance_h($pessoa['name'] ?? '') ?></strong><small><?= finance_h($pessoa['document'] ?? '') ?></small></button><?php endforeach; ?>
    </div>
  </div>
</div>

<div class="finance-modal finance-bulk-modal" id="bulkSettleModal" role="dialog" aria-modal="true" hidden>
  <div class="finance-modal-dialog finance-modal-wide">
    <div class="finance-modal-head"><div><h2>Baixa das parcelas selecionadas</h2><p class="finance-muted">Confira o valor original, o valor atualizado e defina uma baixa integral ou parcial.</p></div><button type="button" class="finance-modal-close" id="closeBulkSettleBtn">×</button></div>
    <form id="bulkSettleForm" method="post">
      <input type="hidden" name="action" value="bulk_settle">
      <div class="finance-form-section"><h3>Dados gerais da baixa</h3><div class="finance-bulk-common">
        <div><label>Data baixa</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>"></div>
        <div><label>Conta/Caixa</label><select name="bank_account_id"><?php finance_select_options($banks, '', 'Selecione o caixa/banco'); ?></select></div>
        <div><label>Meio pagamento</label><input name="payment_method" placeholder="Pix, dinheiro, cartão..."></div>
        <div class="finance-bulk-total">Selecionadas: <span id="bulkSelectedCount">0</span></div>
        <div class="wide"><label>Observação da baixa</label><input name="notes" placeholder="Observação opcional"></div>
      </div></div>
      <div class="finance-form-section">
        <h3>Forma de distribuição</h3>
        <div class="bulk-mode-box">
          <label><input type="radio" name="bulk_mode_ui" value="full" checked> Baixar integralmente</label>
          <label><input type="radio" name="bulk_mode_ui" value="partial"> Baixa parcial</label>
          <div class="bulk-partial-controls"><div><label>Valor total pago</label><input id="bulkPartialTotal" value="0,00" disabled></div><button class="btn" type="button" id="distributePartialBtn" disabled>Distribuir valor</button></div>
        </div>
        <div class="finance-bulk-summary"><div class="finance-bulk-total">Total atualizado: R$ <span id="bulkUpdatedTotal">0,00</span></div><div class="finance-bulk-total">Total da baixa: R$ <span id="bulkSettleTotal">0,00</span></div><div class="finance-bulk-total">Saldo restante: R$ <span id="bulkRemainingTotal">0,00</span></div></div>
        <div class="bulk-settle-list" id="bulkSettleRows"></div>
      </div>
      <div class="finance-form-actions"><button type="button" class="btn" id="cancelBulkSettleBtn">Cancelar</button><button class="btn btn-primary">Confirmar baixa</button></div>
    </form>
  </div>
</div>

<script>
(function(){
  const personModal=document.getElementById('personLookupModal'), personOpen=document.getElementById('openPersonLookup'), personClose=document.getElementById('closePersonLookup'), personSearch=document.getElementById('personLookupSearch'), personId=document.getElementById('payablePersonId'), personName=document.getElementById('payablePersonName'), personClear=document.getElementById('clearPersonLookup');
  function showModal(el){if(!el)return;el.hidden=false;document.body.style.overflow='hidden'} function hideModal(el){if(!el)return;el.hidden=true;document.body.style.overflow=''}
  personOpen?.addEventListener('click',()=>{showModal(personModal);setTimeout(()=>personSearch?.focus(),50)});personClose?.addEventListener('click',()=>hideModal(personModal));personModal?.addEventListener('click',e=>{if(e.target===personModal)hideModal(personModal)});personClear?.addEventListener('click',()=>{if(personId)personId.value='';if(personName)personName.value=''});
  personSearch?.addEventListener('input',()=>{const q=(personSearch.value||'').trim().toLocaleLowerCase('pt-BR');document.querySelectorAll('.person-lookup-item').forEach(el=>{el.hidden=q!=='' && !(el.dataset.search||'').includes(q)})});
  document.querySelectorAll('.person-lookup-item').forEach(el=>el.addEventListener('click',()=>{if(personId)personId.value=el.dataset.id||'';if(personName)personName.value=el.dataset.name||'';hideModal(personModal)}));

  const checks=Array.from(document.querySelectorAll('.bulk-title-check')),checkAll=document.getElementById('bulkCheckAll'),openBtn=document.getElementById('openBulkSettleBtn'),selectedSummary=document.getElementById('bulkSelectedSummary'),selectedHeaderCount=document.getElementById('bulkSelectedHeaderCount'),selectedHeaderTotal=document.getElementById('bulkSelectedHeaderTotal'),modal=document.getElementById('bulkSettleModal'),closeBtn=document.getElementById('closeBulkSettleBtn'),cancelBtn=document.getElementById('cancelBulkSettleBtn'),rowsBox=document.getElementById('bulkSettleRows'),countEl=document.getElementById('bulkSelectedCount'),updatedEl=document.getElementById('bulkUpdatedTotal'),totalEl=document.getElementById('bulkSettleTotal'),remainingEl=document.getElementById('bulkRemainingTotal'),form=document.getElementById('bulkSettleForm'),partialTotal=document.getElementById('bulkPartialTotal'),distributeBtn=document.getElementById('distributePartialBtn');
  const draft=new Map();
  function esc(v){return String(v??'').replace(/[&<>'"]/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]))} function money(v){return Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2})} function parse(v){v=String(v||'').trim();if(!v)return 0;if(v.includes(','))v=v.replace(/\./g,'').replace(',','.');return Number(v.replace(/[^0-9.\-]/g,''))||0}
  function selected(){return checks.filter(c=>c.checked).map(c=>({id:c.value,person:c.dataset.person||'',desc:c.dataset.desc||'',doc:c.dataset.doc||'',inst:c.dataset.installment||'',due:c.dataset.due||'',dueIso:c.dataset.dueIso||'',original:Number(c.dataset.original||0),open:Number(c.dataset.open||0)})).sort((a,b)=>(a.dueIso||'9999').localeCompare(b.dueIso||'9999')||Number(a.id)-Number(b.id))}
  function saveDraft(){rowsBox?.querySelectorAll('.bulk-settle-row[data-id]').forEach(row=>{draft.set(row.dataset.id,{fine:row.querySelector('[data-field=fine]')?.value||'0,00',interest:row.querySelector('[data-field=interest]')?.value||'0,00',discount:row.querySelector('[data-field=discount]')?.value||'0,00',paid:row.querySelector('[data-field=paid]')?.value||'0,00'})})}
  function updatedOf(row){return Math.max(0,parse(row.querySelector('[data-field=open]')?.value)+parse(row.querySelector('[data-field=fine]')?.value)+parse(row.querySelector('[data-field=interest]')?.value)-parse(row.querySelector('[data-field=discount]')?.value))}
  function refreshTotals(){let upd=0,paid=0;rowsBox?.querySelectorAll('.bulk-settle-row').forEach(row=>{const u=updatedOf(row),p=Math.max(0,parse(row.querySelector('[data-field=paid]')?.value));upd+=u;paid+=Math.min(p,u);const ue=row.querySelector('[data-role=updated]'),be=row.querySelector('[data-role=balance]');if(ue)ue.textContent='R$ '+money(u);if(be){const bal=Math.max(0,u-p);be.textContent='R$ '+money(bal);be.classList.toggle('paid',bal<.005);be.classList.toggle('partial',bal>=.005&&p>0)}});if(updatedEl)updatedEl.textContent=money(upd);if(totalEl)totalEl.textContent=money(paid);if(remainingEl)remainingEl.textContent=money(Math.max(0,upd-paid));saveDraft()}
  function applyFull(){rowsBox?.querySelectorAll('.bulk-settle-row').forEach(row=>{const input=row.querySelector('[data-field=paid]');if(input)input.value=money(updatedOf(row))});if(partialTotal)partialTotal.value=updatedEl?.textContent||'0,00';refreshTotals()}
  function distributePartial(){let remaining=Math.max(0,parse(partialTotal?.value));const rows=Array.from(rowsBox?.querySelectorAll('.bulk-settle-row')||[]);const max=rows.reduce((sum,row)=>sum+updatedOf(row),0);if(remaining>max+.01){alert('O valor pago é maior que o total atualizado das parcelas. O valor será limitado ao total selecionado.');remaining=max;if(partialTotal)partialTotal.value=money(max)}rows.forEach(row=>{const u=updatedOf(row),pay=Math.min(u,remaining),input=row.querySelector('[data-field=paid]');if(input)input.value=money(pay);remaining=Math.max(0,remaining-pay)});refreshTotals()}
  function currentMode(){return document.querySelector('input[name=bulk_mode_ui]:checked')?.value||'full'}
  function render(){saveDraft();const rows=selected(),selectedOpen=rows.reduce((sum,row)=>sum+Math.max(0,Number(row.open||0)),0);if(countEl)countEl.textContent=String(rows.length);if(selectedHeaderCount)selectedHeaderCount.textContent=String(rows.length);if(selectedHeaderTotal)selectedHeaderTotal.textContent=money(selectedOpen);if(selectedSummary)selectedSummary.hidden=rows.length===0;if(openBtn)openBtn.disabled=rows.length===0;if(checkAll){checkAll.checked=rows.length>0&&rows.length===checks.length;checkAll.indeterminate=rows.length>0&&rows.length<checks.length}if(!rowsBox)return;rowsBox.innerHTML=rows.map(r=>{const d=draft.get(r.id)||{};return `<article class="bulk-settle-row" data-id="${esc(r.id)}"><input type="hidden" name="title_ids[]" value="${esc(r.id)}"><div class="bulk-settle-head"><div><strong>${esc(r.person||'Fornecedor')}</strong><small>#${esc(r.id)} · Doc. ${esc(r.doc||'-')} · Parc. ${esc(r.inst||'-')} · Venc. ${esc(r.due||'-')}<br>${esc(r.desc||'')}</small></div></div><div class="bulk-settle-fields"><div><label>Valor original</label><div class="bulk-readonly">R$ ${money(r.original)}</div></div><div><label>Em aberto</label><div class="bulk-readonly">R$ ${money(r.open)}</div><input type="hidden" data-field="open" value="${r.open.toFixed(2)}"></div><div><label>Multa</label><input data-field="fine" name="bulk_fine_amount[${esc(r.id)}]" value="${esc(d.fine||'0,00')}"></div><div><label>Juros</label><input data-field="interest" name="bulk_interest_amount[${esc(r.id)}]" value="${esc(d.interest||'0,00')}"></div><div><label>Desconto</label><input data-field="discount" name="bulk_discount_amount[${esc(r.id)}]" value="${esc(d.discount||'0,00')}"></div><div><label>Valor atualizado</label><div class="bulk-readonly" data-role="updated">R$ ${money(r.open)}</div></div><div><label>Valor da baixa</label><input data-field="paid" name="bulk_total_paid[${esc(r.id)}]" value="${esc(d.paid||money(r.open))}"><small>Saldo: <b class="bulk-balance" data-role="balance">R$ 0,00</b></small></div></div></article>`}).join('');if(currentMode()==='full')applyFull();else refreshTotals()}
  function openModal(){render();if(!selected().length){alert('Selecione pelo menos uma parcela.');return}showModal(modal)}
  checks.forEach(c=>c.addEventListener('change',render));checkAll?.addEventListener('change',()=>{checks.forEach(c=>c.checked=checkAll.checked);render()});openBtn?.addEventListener('click',openModal);closeBtn?.addEventListener('click',()=>hideModal(modal));cancelBtn?.addEventListener('click',()=>hideModal(modal));modal?.addEventListener('click',e=>{if(e.target===modal)hideModal(modal)});
  document.querySelectorAll('input[name=bulk_mode_ui]').forEach(r=>r.addEventListener('change',()=>{const partial=currentMode()==='partial';if(partialTotal)partialTotal.disabled=!partial;if(distributeBtn)distributeBtn.disabled=!partial;if(partial){partialTotal.value=totalEl?.textContent||'0,00';distributePartial()}else applyFull()}));distributeBtn?.addEventListener('click',distributePartial);partialTotal?.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();distributePartial()}});
  rowsBox?.addEventListener('input',e=>{if(e.target?.matches('[data-field=fine],[data-field=interest],[data-field=discount]')){if(currentMode()==='full')applyFull();else distributePartial()}else refreshTotals()});
  form?.addEventListener('submit',e=>{refreshTotals();if(!selected().length||parse(totalEl?.textContent)<=0){e.preventDefault();alert('Selecione parcelas e informe um valor de baixa maior que zero.');return}if(!confirm('Confirmar a baixa das parcelas selecionadas no valor de R$ '+(totalEl?.textContent||'0,00')+'?'))e.preventDefault()});render();
})();
</script>


<?php if ($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog finance-modal-wide"><div class="finance-modal-head"><div><h2><?= $fixedProtectedView ? 'Detalhes da provisão #' . (int)$editId : ($editId ? 'Editar título #' . (int)$editId : 'Novo contas a pagar') ?></h2><p class="finance-muted"><?= $fixedProtectedView ? 'V11.5: título gerado por Despesa Fixa. O cadastro fica protegido; baixas e estornos permanecem disponíveis conforme as permissões.' : 'Preencha os dados do título. A baixa gera movimento financeiro automaticamente.' ?></p></div><a class="finance-modal-close" href="financeiro_contas_pagar.php">×</a></div>
<?php if ($fixedProtectedView): ?><div class="msg ok" style="margin:14px 18px 0">Esta provisão pertence à Despesa Fixa #<?= (int)$editFixedExpenseId ?>. Para alterar fornecedor, plano, valor recorrente ou calendário, edite a regra em <a href="financeiro_despesas_fixas.php?edit=<?= (int)$editFixedExpenseId ?>">Despesas Fixas</a>.</div><?php endif; ?>
<form method="post" class="finance-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
  <fieldset <?= $fixedProtectedView ? 'disabled' : '' ?> style="border:0;padding:0;margin:0;min-width:0">
  <div class="finance-form-section"><h3>Dados do título</h3><div class="finance-form-grid three">
    <div><label>Fornecedor *</label><select name="person_id" required><?php finance_select_options($suppliers, $edit['person_id'] ?? '', 'Selecione o fornecedor'); ?></select><div class="hint"><a href="<?= $financeCrossContext ? 'financeiro_pessoas.php?new=1&role=supplier&mc_finance=1' : 'financeiro_pessoas.php?new=1' ?>">Cadastrar pessoa</a></div></div>
    <div><label>Plano de contas *</label><select name="chart_account_id" required><?php finance_select_options($charts, $edit['chart_account_id'] ?? '', 'Selecione conta analítica'); ?></select><?php if ($financeCrossContext): ?><div class="hint"><a href="financeiro_plano_contas.php?new=1">Cadastrar conta na empresa do F11</a></div><?php endif; ?></div>
    <div><label>Centro de custo</label><select name="cost_center_id"><?php finance_select_options($costs, $edit['cost_center_id'] ?? '', 'Opcional'); ?></select><?php if ($financeCrossContext): ?><div class="hint"><a href="financeiro_centros_custo.php?new=1">Cadastrar centro na empresa do F11</a></div><?php endif; ?></div>
    <div class="span-3"><label>Histórico</label><input name="description" value="<?= finance_h($edit['description'] ?? '') ?>"></div>
    <div><label>Nº documento</label><input name="document_number" value="<?= finance_h($edit['document_number'] ?? '') ?>"></div>
    <div><label>Parcela</label><input name="installment" value="<?= finance_h($edit['installment'] ?? '01/01') ?>"></div>
    <div><label>Meio previsto</label><input name="expected_payment_method" value="<?= finance_h($edit['expected_payment_method'] ?? '') ?>" placeholder="Pix, boleto, cartão..."></div>
  </div></div>
  <div class="finance-form-section"><h3>Datas e valores</h3><div class="finance-form-grid three">
    <div><label>Data emissão</label><input type="date" name="issue_date" value="<?= finance_h($edit['issue_date'] ?? date('Y-m-d')) ?>"></div>
    <div><label>Vencimento *</label><input type="date" name="due_date" value="<?= finance_h($edit['due_date'] ?? date('Y-m-d')) ?>" required></div>
    <div><label><?= $editId ? 'Valor do título *' : 'Valor total da compra *' ?></label><input name="amount" value="<?= finance_money_br($edit['amount'] ?? 0) ?>" required></div>
    <div><label>Multa</label><input name="fine_amount" value="<?= finance_money_br($edit['fine_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Juros</label><input name="interest_amount" value="<?= finance_money_br($edit['interest_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Desconto</label><input name="discount_amount" value="<?= finance_money_br($edit['discount_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Valor baixado</label><input name="paid_amount" value="<?= finance_money_br($edit['paid_amount'] ?? 0) ?>" <?= $financeCrossContext ? 'readonly' : '' ?>></div>
    <div><label>Valor em aberto</label><input readonly value="<?= finance_money_br($edit['open_amount'] ?? 0) ?>"></div>
  </div></div>
  <?php if (!$editId): ?>
  <div class="finance-form-section" id="nexaParcelSection">
    <h3>Parcelamento do pagamento</h3>
    <div class="finance-form-grid three">
      <div><label>Quantidade de parcelas</label><input name="parcel_count" id="parcelCount" type="number" min="1" max="120" value="<?= (int)($_POST['parcel_count'] ?? 1) ?>"></div>
      <div class="span-2" style="align-self:end"><span class="finance-muted">O valor informado acima corresponde ao total da compra. Você pode ajustar o valor e o vencimento de cada parcela.</span></div>
    </div>
    <div id="parcelGridWrap" style="display:none; margin-top:12px; overflow-x:auto">
      <table class="finance-list-table" style="width:100%"><thead><tr><th>Parcela</th><th>Valor (R$)</th><th>Vencimento</th></tr></thead><tbody id="parcelRows"></tbody></table>
      <p class="finance-muted" id="parcelSummary" aria-live="polite"></p>
    </div>
  </div>
  <?php endif; ?>
  <div class="finance-form-section"><h3>Observações</h3><textarea name="notes" rows="3"><?= finance_h($edit['notes'] ?? '') ?></textarea></div>
  </fieldset>
  <div class="finance-form-actions"><a class="btn" href="financeiro_contas_pagar.php"><?= $fixedProtectedView ? 'Voltar' : 'Cancelar' ?></a><?php if (!$fixedProtectedView): ?><button class="btn btn-primary">Salvar título</button><?php endif; ?></div>
</form>

<?php if ($edit && $canSettleTitles && (!$fixedProtectedView || $canManageFixedExpenses) && in_array((string)$edit['status'], ['ABERTO','PARCIAL'], true)): ?>
<div class="finance-form-section"><h3>Baixar título</h3><form method="post" class="finance-form"><input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$edit['id'] ?>"><div class="finance-form-grid three"><div><label>Data baixa</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>"></div><div><label>Valor baixa</label><input name="amount" value="<?= finance_money_br($edit['open_amount'] ?? 0) ?>"></div><div><label>Conta/Caixa</label><select name="bank_account_id"><?php finance_select_options($banks, '', 'Selecione o caixa/banco'); ?></select></div><div><label>Meio pagamento</label><input name="payment_method" value="<?= finance_h($edit['expected_payment_method'] ?? '') ?>"></div><div><label>Multa</label><input name="fine_amount" value="0,00"></div><div><label>Juros</label><input name="interest_amount" value="0,00"></div><div><label>Desconto</label><input name="discount_amount" value="0,00"></div><div class="span-2"><label>Obs. baixa</label><input name="notes"></div></div><div class="finance-form-actions"><button class="btn btn-primary">Baixar título</button></div></form></div>
<?php endif; ?>

<?php if ($edit): ?><div class="finance-form-section"><h3>Baixas do título</h3><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Data</th><th>Meio</th><th>Conta/Caixa</th><th>Valor</th><th>Status</th><th>Baixado por</th><th>Movimento</th><th>Ações</th></tr></thead><tbody><?php if(!$settlements): ?><tr><td colspan="8" class="finance-empty">Nenhuma baixa registrada.</td></tr><?php endif; ?><?php foreach($settlements as $s): $sStatus=strtoupper((string)($s['status']??'ATIVO')); ?><tr><td data-label="Data"><?= finance_date_br($s['settlement_date'] ?? '') ?></td><td data-label="Meio"><?= finance_h($s['payment_method'] ?? '') ?></td><td data-label="Conta/Caixa"><?= finance_h($s['bank_name'] ?? '') ?></td><td data-label="Valor" class="finance-value neg">R$ <?= finance_money_br((float)$s['amount'] + (float)$s['fine_amount'] + (float)$s['interest_amount'] - (float)$s['discount_amount']) ?></td><td data-label="Status"><strong><?= finance_h($sStatus) ?></strong><?php if($sStatus==='ESTORNADO' && !empty($s['reversal_reason'])):?><div class="finance-muted"><?= finance_h($s['reversal_reason']) ?></div><?php endif;?></td><td data-label="Baixado por"><?= finance_h($s['settled_by_name'] ?? 'Não identificado') ?></td><td data-label="Movimento">#<?= (int)($s['movement_id'] ?? 0) ?></td><td data-label="Ações"><?php if($sStatus==='ATIVO' && $canReverseSettlements): ?><form method="post" onsubmit="var m=this.elements['reversal_reason'].value.trim(); if(!m){alert('Informe o motivo do estorno.');this.elements['reversal_reason'].focus();return false;}return confirm('Confirma estornar esta baixa? O sistema registrará o contra-lançamento financeiro.');"><input type="hidden" name="action" value="reverse_settlement"><input type="hidden" name="id" value="<?= (int)($edit['id']??0) ?>"><input type="hidden" name="settlement_id" value="<?= (int)$s['id'] ?>"><input type="text" name="reversal_reason" aria-label="Motivo do estorno" placeholder="Motivo do estorno" required maxlength="250" style="min-width:150px;max-width:210px;min-height:34px"><button class="btn btn-danger" type="submit">Estornar</button></form><?php else:?><span class="finance-muted">—</span><?php endif;?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
</div></div>
<?php endif; ?>

<?php if ($showSettle && $settleTitle): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog"><div class="finance-modal-head"><div><h2>Baixar título #<?= (int)$settleTitle['id'] ?></h2><p class="finance-muted">Informe apenas os dados da baixa. Para alterar o cadastro do título, use o botão Editar.</p></div><a class="finance-modal-close" href="financeiro_contas_pagar.php">×</a></div>
<div class="finance-form-section"><h3>Resumo do título</h3><div class="finance-form-grid three">
  <div><label>Fornecedor</label><input readonly value="<?= finance_h($settleTitle['person_name'] ?? '') ?>"></div>
  <div><label>Vencimento</label><input readonly value="<?= finance_date_br($settleTitle['due_date'] ?? '') ?>"></div>
  <div><label>Valor em aberto</label><input readonly value="R$ <?= finance_money_br($settleTitle['open_amount'] ?? 0) ?>"></div>
  <div class="span-3"><label>Histórico</label><input readonly value="<?= finance_h($settleTitle['description'] ?? '') ?>"></div>
</div></div>
<?php if (in_array((string)$settleTitle['status'], ['ABERTO','PARCIAL'], true)): ?>
<div class="finance-form-section"><h3>Dados da baixa</h3><form method="post" class="finance-form"><input type="hidden" name="action" value="settle"><input type="hidden" name="id" value="<?= (int)$settleTitle['id'] ?>"><div class="finance-form-grid three"><div><label>Data baixa</label><input type="date" name="settlement_date" value="<?= date('Y-m-d') ?>"></div><div><label>Valor baixa</label><input name="amount" value="<?= finance_money_br($settleTitle['open_amount'] ?? 0) ?>"></div><div><label>Conta/Caixa</label><select name="bank_account_id"><?php finance_select_options($banks, '', 'Selecione o caixa/banco'); ?></select></div><div><label>Meio pagamento</label><input name="payment_method" value="<?= finance_h($settleTitle['expected_payment_method'] ?? '') ?>"></div><div><label>Multa</label><input name="fine_amount" value="0,00"></div><div><label>Juros</label><input name="interest_amount" value="0,00"></div><div><label>Desconto</label><input name="discount_amount" value="0,00"></div><div class="span-2"><label>Obs. baixa</label><input name="notes"></div></div><div class="finance-form-actions"><a class="btn" href="financeiro_contas_pagar.php">Cancelar</a><button class="btn btn-primary">Confirmar baixa</button></div></form></div>
<?php else: ?>
<div class="msg err">Este título não está em aberto para baixa.</div><div class="finance-form-actions"><a class="btn btn-primary" href="financeiro_contas_pagar.php">Voltar</a></div>
<?php endif; ?>
</div></div>
<?php endif; ?>
<?php if ($showForm && !$editId): ?>
<script>
(function(){
 const root=document.getElementById('nexaParcelSection'); if(!root)return;
 const form=root.closest('form'); const count=root.querySelector('#parcelCount'); const wrap=root.querySelector('#parcelGridWrap');
 const rows=root.querySelector('#parcelRows'); const summary=root.querySelector('#parcelSummary');
 const total=form.querySelector('[name="amount"]'); const firstDue=form.querySelector('[name="due_date"]');
 const moneyIn=v=>{let s=String(v||'').trim().replace(/\s/g,'');if(s.includes(','))s=s.replace(/\./g,'').replace(',','.');return Math.round((Number(s)||0)*100)};
 const moneyOut=v=>(v/100).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
 const addMonths=(date,n)=>{if(!/^\d{4}-\d{2}-\d{2}$/.test(date||''))return '';const [y,m,d]=date.split('-').map(Number);const base=new Date(Date.UTC(y,m-1+n,1));const last=new Date(Date.UTC(base.getUTCFullYear(),base.getUTCMonth()+1,0)).getUTCDate();base.setUTCDate(Math.min(d,last));return base.toISOString().slice(0,10)};
 let lastKey='';
 function refresh(){const all=[...rows.querySelectorAll('tr')];const sum=all.reduce((a,r)=>a+moneyIn(r.querySelector('[name="parcel_amounts[]"]').value),0);const expected=moneyIn(total.value);summary.textContent='Total das parcelas: R$ '+moneyOut(sum)+' | Total informado: R$ '+moneyOut(expected)+(sum===expected?' — valores conferidos':' — diferença: R$ '+moneyOut(expected-sum));summary.style.color=sum===expected?'':'#b42318';}
 function build(force=false){const qty=Math.min(120,Math.max(1,parseInt(count.value,10)||1));const cents=moneyIn(total.value);const key=[qty,cents,firstDue.value].join('|');if(!force&&key===lastKey)return;lastKey=key;rows.replaceChildren();wrap.style.display=qty>1?'':'none';if(qty<=1)return;
 const share=Math.floor(cents/qty), remainder=cents-share*qty;
 for(let i=0;i<qty;i++){const tr=document.createElement('tr');const number=document.createElement('td');number.textContent=String(i+1).padStart(2,'0')+'/'+String(qty).padStart(2,'0');const valueCell=document.createElement('td');const val=document.createElement('input');val.name='parcel_amounts[]';val.value=moneyOut(share+(i===qty-1?remainder:0));val.required=true;val.setAttribute('aria-label','Valor da parcela '+(i+1));valueCell.append(val);const dateCell=document.createElement('td');const date=document.createElement('input');date.type='date';date.name='parcel_dates[]';date.value=addMonths(firstDue.value,i);date.required=true;date.setAttribute('aria-label','Vencimento da parcela '+(i+1));dateCell.append(date);tr.append(number,valueCell,dateCell);rows.append(tr);}refresh();}
 count.addEventListener('change',()=>build(true));total.addEventListener('change',()=>build(true));firstDue.addEventListener('change',()=>build(true));rows.addEventListener('input',refresh);
 form.addEventListener('submit',e=>{const qty=parseInt(count.value,10)||1;if(qty<=1)return;const inputs=[...rows.querySelectorAll('[name="parcel_amounts[]"]')];const dates=[...rows.querySelectorAll('[name="parcel_dates[]"]')];const sum=inputs.reduce((a,v)=>a+moneyIn(v.value),0);if(inputs.length!==qty||dates.some(d=>!d.value)||inputs.some(v=>moneyIn(v.value)<=0)||sum!==moneyIn(total.value)){e.preventDefault();refresh();alert('Confira a quantidade, os valores e os vencimentos das parcelas. A soma deve ser igual ao valor total da compra.');}});
 build(true);
})();
</script>
<?php endif; ?>
<?php require_once __DIR__ . '/_buscar_f8.php'; nexa_buscar_f8_assets(); ?>
<script>
(function(){
  if(!window.NexaBuscarF8)return;
  // O formulario de edicao envia sem action=save; localize pelos campos do titulo.
  const form=Array.from(document.querySelectorAll('.finance-modal form.finance-form'))
    .find(f=>f.querySelector('select[name="person_id"]') && f.querySelector('select[name="chart_account_id"]')) || null;
  const f_person_id=form?.querySelector('select[name="person_id"]');
  if(f_person_id)window.NexaBuscarF8.enhanceSelect(f_person_id,{title:'Buscar fornecedor',placeholder:'Selecione o fornecedor',registerUrl:'financeiro_pessoas.php?new=1&role=supplier',registerLabel:'+ Cadastrar'});
  const f_chart_account_id=form?.querySelector('select[name="chart_account_id"]');
  if(f_chart_account_id)window.NexaBuscarF8.enhanceSelect(f_chart_account_id,{title:'Buscar plano de contas',placeholder:'Selecione o plano de contas',registerUrl:'financeiro_plano_contas.php?new=1',registerLabel:'+ Cadastrar'});
  const f_cost_center_id=form?.querySelector('select[name="cost_center_id"]');
  if(f_cost_center_id)window.NexaBuscarF8.enhanceSelect(f_cost_center_id,{title:'Buscar centro de custo',placeholder:'Selecione o centro de custo',registerUrl:'financeiro_centros_custo.php?new=1',registerLabel:'+ Cadastrar'});
  document.querySelectorAll('form.finance-form select[name="bank_account_id"]').forEach(el=>window.NexaBuscarF8.enhanceSelect(el,{title:'Buscar Conta/Caixa',placeholder:'Selecione o caixa ou banco',registerUrl:'financeiro_caixas_bancos.php',registerLabel:'+ Cadastrar'}));
})();
</script>
<?php finance_admin_end(); ?>
