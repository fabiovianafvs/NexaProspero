<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/cash_financial_report.php';
require_once __DIR__ . '/../config/cash_operator_guard.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_cash')
  : (int)COMPANY_ID;
$cashCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_cash');
if ($cashCrossContext) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if (!function_exists('multi_company_context_permission_allowed')
      || !multi_company_context_permission_allowed($pdo, 'gestao_caixa')) {
    http_response_code(403);
    admin_topbar('Caixa', 'gestao');
    echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para operar o Caixa na empresa selecionada pelo F11.</div>';
    admin_footer();
    exit;
  }
}
$userId = (int)(auth_user_id() ?? 0);
$cashCrSchemaReady = function_exists('nexa_cash_cr_schema_ready') && nexa_cash_cr_schema_ready($pdo);

$cashUrl = static function(string $url) use ($cashCrossContext): string {
  return $cashCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$cashMcCsrf = $cashCrossContext && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$erro = '';
$schema = ['ok' => true, 'errors' => []];
if (!$cashCrossContext) {
  try { $schema = gestao_ensure_schema($pdo); } catch (Throwable $e) { $schema = ['ok' => false, 'errors' => [$e->getMessage()]]; }
} else {
  foreach (['gestao_cash_sessions','gestao_cash_operations','gestao_order_payments'] as $requiredTable) {
    if (!finance_table_exists($pdo, $requiredTable)) {
      $schema['ok'] = false;
      $schema['errors'][] = 'Tabela obrigatória ausente: ' . $requiredTable . '. Atualize a estrutura pela empresa principal antes de operar o Caixa Multiempresa.';
    }
  }
}

$cashValidateBank = static function(int $bankId) use ($pdo, $companyId, $cashCrossContext): void {
  if ($bankId <= 0 || !finance_validate_bank_account($pdo, $companyId, $bankId)) {
    throw new RuntimeException('Selecione um Caixa/Banco ativo da empresa operacional.');
  }
  if (!$cashCrossContext && function_exists('access_assert_cash_account')) {
    access_assert_cash_account($pdo, $companyId, $bankId);
  }
};
$cashValidateChart = static function(?int $chartId, string $movement) use ($pdo, $companyId): void {
  $chartId = (int)($chartId ?? 0);
  if ($chartId <= 0) return;
  $row = finance_query_one($pdo, 'SELECT id,is_analytic,movement_type FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1', [$companyId, $chartId]);
  if (!$row || (int)($row['is_analytic'] ?? 0) !== 1) throw new RuntimeException('Plano de contas inválido para a empresa selecionada.');
  $type = strtoupper((string)($row['movement_type'] ?? ''));
  if ($movement !== '' && !in_array($type, [$movement, 'AMBOS'], true)) throw new RuntimeException('O plano de contas selecionado não aceita este tipo de movimento.');
};
$cashValidateCost = static function(?int $costId) use ($pdo, $companyId): void {
  $costId = (int)($costId ?? 0);
  if ($costId <= 0) return;
  if (!finance_validate_analytic_cost($pdo, $companyId, $costId)) throw new RuntimeException('Centro de custo inválido para a empresa selecionada.');
};
$cashRedirect = static function(string $message, array $extra = []) use ($cashUrl): void {
  $query = array_merge(['ok' => $message], $extra);
  header('Location: ' . $cashUrl('gestao_caixa.php?' . http_build_query($query)));
  exit;
};

$banks = $chartsCredito = $chartsDebito = $costs = [];
try {
  $banks = $cashCrossContext
    ? finance_query_all($pdo, 'SELECT id,name,type FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name ASC', [$companyId])
    : finance_bank_options($pdo, $companyId);
  $chartsCredito = finance_chart_options($pdo, $companyId, 'CREDITO');
  $chartsDebito = finance_chart_options($pdo, $companyId, 'DEBITO');
  $costs = finance_cost_center_options($pdo, $companyId);
} catch (Throwable $e) {
  $erro = 'Não foi possível carregar as tabelas auxiliares do caixa: ' . $e->getMessage();
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($cashCrossContext && (!function_exists('multi_company_context_csrf_valid')
        || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize a página do Caixa e tente novamente.');
    }
    $action = (string)($_POST['action'] ?? '');
    if ($cashCrossContext && !in_array($action, ['default_cash','open_cash','cash_operation','close_cash'], true)) {
      throw new RuntimeException('Ação não homologada para o Caixa Multiempresa.');
    }
    if ($action === 'default_cash') {
      $bankId = (int)($_POST['default_cash_bank_account_id'] ?? 0);
      $cashValidateBank($bankId);
      nexa_cash_assert_operator($pdo, $companyId, $bankId, $userId);
      gestao_set_user_default_cash_account($pdo, $companyId, $userId, $bankId);
      $cashRedirect($cashCrossContext ? 'Caixa padrão desta sessão Multiempresa atualizado.' : 'Caixa padrão atualizado.');
    }
    if ($action === 'open_cash') {
      if (gestao_open_cash_session($pdo, $companyId, $userId)) throw new RuntimeException('Já existe um caixa aberto para este usuário nesta empresa.');
      $bankId = (int)($_POST['bank_account_id'] ?? 0) ?: gestao_user_default_cash_account($pdo, $companyId, $userId);
      $cashValidateBank($bankId);
      $amount = finance_money_in($_POST['opening_amount'] ?? 0);
      if ($amount < 0) throw new RuntimeException('O valor de abertura não pode ser negativo.');
      $chartId = (int)($_POST['chart_account_id'] ?? 0) ?: null;
      $cashValidateChart($chartId, 'CREDITO');
      $notes = trim((string)($_POST['notes'] ?? ''));
      $pdo->beginTransaction();
      // SERIALIZA pelo registro da conta: dois operadores simultâneos não conseguem abrir o mesmo caixa.
      $lockCash=$pdo->prepare('SELECT id FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 FOR UPDATE');
      $lockCash->execute([$companyId,$bankId]);
      if (!$lockCash->fetchColumn()) throw new RuntimeException('Caixa/Banco inativo.');
      if (gestao_open_cash_session($pdo, $companyId, $userId)) throw new RuntimeException('Já existe um caixa aberto para este usuário nesta empresa.');
      nexa_cash_assert_operator($pdo,$companyId,$bankId,$userId);
      $pdo->prepare('INSERT INTO gestao_cash_sessions (company_id, user_id, bank_account_id, opening_amount, status, notes) VALUES (?, ?, ?, ?, \'ABERTO\', ?)')
        ->execute([$companyId, $userId ?: null, $bankId, $amount, $notes ?: null]);
      $sessionId = (int)$pdo->lastInsertId();
      gestao_create_cash_operation($pdo, $companyId, $userId, $sessionId, 'ABERTURA', $amount, $bankId, $chartId, null, 'Abertura do caixa', $notes);
      if ($cashCrossContext) gestao_cross_cash_preference($companyId, $bankId);
      $pdo->commit();
      $cashRedirect('Caixa aberto com sucesso.');
    }
    if ($action === 'cash_operation') {
      $session = gestao_open_cash_session($pdo, $companyId, $userId);
      if (!$session) throw new RuntimeException('Abra o caixa antes de registrar a operação.');
      $type = strtoupper((string)($_POST['type'] ?? ''));
      if (!in_array($type, ['SUPRIMENTO','SANGRIA'], true)) throw new RuntimeException('Operação inválida.');
      $bankId = (int)($session['bank_account_id'] ?? 0);
      $cashValidateBank($bankId);
      $amount = finance_money_in($_POST['amount'] ?? 0);
      if ($amount <= 0) throw new RuntimeException('Informe um valor maior que zero.');
      $chartId = (int)($_POST['chart_account_id'] ?? 0) ?: null;
      $costId = (int)($_POST['cost_center_id'] ?? 0) ?: null;
      $cashValidateChart($chartId, $type === 'SANGRIA' ? 'DEBITO' : 'CREDITO');
      $cashValidateCost($costId);
      $desc = trim((string)($_POST['description'] ?? '')) ?: ($type === 'SANGRIA' ? 'Retirada / sangria' : 'Reforço / suprimento');
      $notes = trim((string)($_POST['notes'] ?? ''));
      gestao_create_cash_operation($pdo, $companyId, $userId, (int)$session['id'], $type, $amount, $bankId, $chartId, $costId, $desc, $notes);
      $cashRedirect('Operação registrada.');
    }
    if ($action === 'close_cash') {
      // Quando a integracao C/R estiver ativa, serializar o fechamento com baixas
      // que travam a mesma linha de sessao e registram a operacao na transacao.
      $cashCrCloseProtected = nexa_cash_cr_enabled($pdo, $companyId);
      if ($cashCrCloseProtected) $pdo->beginTransaction();
      $session = $cashCrCloseProtected
        ? nexa_cash_cr_open_session($pdo, $companyId, $userId, true)
        : gestao_open_cash_session($pdo, $companyId, $userId);
      if (!$session) throw new RuntimeException('Não existe caixa aberto para fechar.');
      $closedSessionId = (int)$session['id'];
      $summary = gestao_cash_summary($pdo, $companyId, $closedSessionId);
      $closing = finance_money_in($_POST['closing_amount'] ?? 0);
      if ($closing < 0) throw new RuntimeException('O valor informado no fechamento não pode ser negativo.');
      $diff = round($closing - (float)$summary['expected'], 2);
      $notes = trim((string)($_POST['notes'] ?? ''));
      $stClose = $pdo->prepare('UPDATE gestao_cash_sessions SET status=\'FECHADO\', closed_at=NOW(), closing_amount=?, expected_amount=?, difference_amount=?, notes=CONCAT(COALESCE(notes,\'\'), ?, ?), updated_at=NOW() WHERE company_id=? AND id=? AND user_id=? AND status=\'ABERTO\'');
      $stClose->execute([$closing, $summary['expected'], $diff, $notes !== '' ? "\nFechamento: " : '', $notes, $companyId, $closedSessionId, $userId]);
      if ($stClose->rowCount() !== 1) throw new RuntimeException('O caixa já foi fechado ou alterado em outra operação. Atualize a página.');
      gestao_create_cash_operation($pdo, $companyId, $userId, $closedSessionId, 'FECHAMENTO', $closing, (int)($session['bank_account_id'] ?? 0) ?: null, null, null, 'Fechamento do caixa', $notes);
      if ($cashCrCloseProtected) $pdo->commit();
      $cashRedirect('Caixa fechado. Diferença: ' . gestao_money($diff), ['ask_print' => $closedSessionId]);
    }
  }
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  $erro = $e->getMessage();
}

// Impressão térmica do Resumo do Caixa (72 mm). Usa a própria página para manter
// as mesmas permissões já aplicadas ao Caixa do Operador.
$printSessionId = (int)($_GET['print_summary'] ?? 0);
if ($printSessionId > 0) {
  $printSession = finance_query_one($pdo, '
    SELECT s.*, b.name AS bank_name, u.name AS user_name
      FROM gestao_cash_sessions s
      LEFT JOIN finance_bank_accounts b ON b.company_id=s.company_id AND b.id=s.bank_account_id
      LEFT JOIN users u ON u.id=s.user_id
     WHERE s.company_id=? AND s.id=? AND s.user_id=?
     LIMIT 1', [$companyId, $printSessionId, $userId]);
  if (!$printSession) {
    http_response_code(404);
    echo '<!doctype html><html lang="pt-BR"><meta charset="utf-8"><title>Resumo do caixa</title><body style="font-family:Arial,sans-serif;padding:24px">Resumo do caixa não encontrado ou sem permissão para este usuário.</body></html>';
    exit;
  }

  $printSummary = gestao_cash_summary($pdo, $companyId, $printSessionId);
  $company = finance_query_one($pdo, 'SELECT name FROM companies WHERE id=? LIMIT 1', [$companyId]) ?: [];
  $companyName = trim((string)($company['name'] ?? '')) ?: 'Empresa';
  $statusPrint = strtoupper((string)($printSession['status'] ?? 'ABERTO')) === 'FECHADO' ? 'FECHADO' : 'ABERTO';
  $autoPrint = !empty($_GET['autoprint']);
  ?><!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Resumo do Caixa #<?= (int)$printSessionId ?></title>
<style>
  @page{size:72mm auto;margin:2mm}
  *{box-sizing:border-box}
  html,body{margin:0;padding:0;background:#fff;color:#000}
  body{font-family:Arial,Helvetica,sans-serif;font-size:10pt;line-height:1.25}
  .receipt{width:68mm;margin:0 auto;padding:1mm 0}
  .center{text-align:center}.strong{font-weight:700}.muted{font-size:8.5pt}
  .title{font-size:12pt;font-weight:800;margin:2mm 0 1mm}
  .sep{border-top:1px dashed #000;margin:2mm 0}
  .info{display:flex;justify-content:space-between;gap:2mm;margin:.8mm 0;font-size:8.7pt}
  .info span:first-child{white-space:nowrap}.info strong{text-align:right;overflow-wrap:anywhere}
  .line{display:flex;justify-content:space-between;align-items:flex-start;gap:3mm;padding:1.2mm 0;border-bottom:1px dotted #777}
  .line span{max-width:43mm}.line strong{white-space:nowrap;text-align:right;font-variant-numeric:tabular-nums}
  .total{font-size:10.5pt;font-weight:800;border-top:1px solid #000;border-bottom:1px solid #000;margin-top:1mm;padding:1.8mm 0}
  .closing{margin-top:1.5mm}
  .actions{width:68mm;margin:4mm auto;display:flex;gap:8px;justify-content:center;font-family:Arial,sans-serif}
  .actions button,.actions a{border:1px solid #333;background:#fff;color:#000;padding:8px 12px;border-radius:6px;text-decoration:none;cursor:pointer;font-size:13px}
  .actions button{background:#111;color:#fff}
  @media print{.no-print{display:none!important}.receipt{width:68mm;margin:0}.actions{display:none!important}}
</style>
<?= nexa_cash_financial_report_css() ?>
</head>
<body>
<div class="receipt">
  <div class="center strong"><?= gestao_h($companyName) ?></div>
  <?= nexa_cash_financial_report_html((array)$printSummary, (array)$printSession, (string)($printSession['user_name'] ?? 'Operador'), (string)($printSession['bank_name'] ?? '')) ?>
  <div class="center muted" style="margin-top:3mm">Impresso em <?= gestao_h(date('d/m/Y H:i')) ?></div>
</div>
<div class="actions no-print">
  <button type="button" onclick="window.print()">Imprimir</button>
  <a href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>" onclick="if(window.opener){window.close();return false;}">Voltar</a>
</div>
<?php if ($autoPrint): ?>
<script>
window.addEventListener('load', function(){
  setTimeout(function(){ window.print(); }, 120);
});
window.addEventListener('afterprint', function(){ if (window.opener) window.close(); });
</script>
<?php endif; ?>
</body></html><?php
  exit;
}

if (!function_exists('gestao_cash_chart_data')) {
  function gestao_cash_chart_data(array $payments): array {
    $colors = ['#0f3a8a','#1d4ed8','#0891b2','#047857','#f59e0b','#dc2626','#7c3aed','#64748b'];
    $total = 0.0;
    foreach ($payments as $p) $total += (float)($p['total'] ?? 0);
    $segments = [];
    $legend = [];
    $start = 0.0;
    foreach (array_values($payments) as $idx => $p) {
      $value = (float)($p['total'] ?? 0);
      if ($value <= 0 || $total <= 0) continue;
      $pct = round(($value / $total) * 100, 2);
      $end = min(100, $start + $pct);
      $color = $colors[$idx % count($colors)];
      $segments[] = $color . ' ' . $start . '% ' . $end . '%';
      $legend[] = ['name' => (string)($p['name'] ?? 'Sem forma'), 'total' => $value, 'pct' => $pct, 'color' => $color];
      $start = $end;
    }
    if (!$segments) $segments[] = '#e2e8f0 0% 100%';
    return ['css' => implode(', ', $segments), 'legend' => $legend, 'total' => $total];
  }
}

$defaultBank = 0;
$session = null;
$summary = null;
$ops = [];
try {
  $defaultBank = gestao_user_default_cash_account($pdo, $companyId, $userId);
  $session = gestao_open_cash_session($pdo, $companyId, $userId);
  $summary = $session ? gestao_cash_summary($pdo, $companyId, (int)$session['id']) : null;
  if ($session) {
    $ops = finance_query_all($pdo, '
      SELECT o.*, p.numero_pedido, p.source AS order_source, b.name AS bank_name, ca.name AS chart_name, cc.name AS cost_center_name
        FROM gestao_cash_operations o
        LEFT JOIN pedidos p ON p.company_id = o.company_id AND p.id = o.order_id
        LEFT JOIN finance_bank_accounts b ON b.company_id = o.company_id AND b.id = o.bank_account_id
        LEFT JOIN finance_chart_accounts ca ON ca.company_id = o.company_id AND ca.id = o.chart_account_id
        LEFT JOIN finance_cost_centers cc ON cc.company_id = o.company_id AND cc.id = o.cost_center_id
       WHERE o.company_id=? AND o.cash_session_id=?
       ORDER BY o.id DESC
       LIMIT 200', [$companyId, (int)$session['id']]);
  }
} catch (Throwable $e) {
  $erro = $erro ?: 'Não foi possível carregar o caixa: ' . $e->getMessage();
}
$modal = (string)($_GET['modal'] ?? '');
$askPrintId = (int)($_GET['ask_print'] ?? 0);
$askPrintSession = null;
if ($askPrintId > 0) {
  try {
    $askPrintSession = finance_query_one($pdo, "SELECT id,status FROM gestao_cash_sessions WHERE company_id=? AND id=? AND user_id=? AND status='FECHADO' LIMIT 1", [$companyId, $askPrintId, $userId]);
  } catch (Throwable $e) { $askPrintSession = null; }
}
$sessionOperator = 'Operador';
$sessionBankName = '';
if ($session) {
  try {
    $cashPerson = finance_query_one($pdo, 'SELECT name FROM users WHERE id=? LIMIT 1', [(int)$session['user_id']]);
    $sessionOperator = trim((string)($cashPerson['name'] ?? '')) ?: 'Operador';
    if ((int)($session['bank_account_id'] ?? 0) > 0) {
      $cashBankRow = finance_query_one($pdo, 'SELECT name FROM finance_bank_accounts WHERE company_id=? AND id=? LIMIT 1', [$companyId, (int)$session['bank_account_id']]);
      $sessionBankName = (string)($cashBankRow['name'] ?? '');
    }
  } catch (Throwable $e) {}
}
$payments = (array)($summary['cash_sales_payments'] ?? []);
$chart = gestao_cash_chart_data($payments);
$cashCrEnabled = $cashCrSchemaReady && nexa_cash_cr_enabled($pdo, $companyId);
$cashCrPayments = (array)($summary['cr_payments'] ?? []);
$cashCrNet = (float)($summary['cr_net'] ?? 0);
$cashCrTotal = (float)($summary['cr_receipts'] ?? 0);
$cashCrReversals = (float)($summary['cr_reversals'] ?? 0);
$cashCrByMethod = [];
foreach ($cashCrPayments as $payment) {
  $label = trim((string)($payment['name'] ?? 'Sem forma'));
  $cashCrByMethod[$label] = ($cashCrByMethod[$label] ?? 0) + (float)($payment['total'] ?? 0);
}
$salesByMethod = [];
foreach ((array)($summary['cash_sales_payments'] ?? []) as $payment) {
  $label = trim((string)($payment['name'] ?? 'Sem forma'));
  $salesByMethod[$label] = ($salesByMethod[$label] ?? 0) + (float)($payment['total'] ?? 0);
}
$allCashMethods = array_unique(array_merge(array_keys($salesByMethod),array_keys($cashCrByMethod)));
sort($allCashMethods, SORT_NATURAL | SORT_FLAG_CASE);
$totalDinheiro = 0.0;
foreach ($payments as $p) {
  if (stripos((string)($p['name'] ?? ''), 'dinheiro') !== false) $totalDinheiro += (float)($p['total'] ?? 0);
}
foreach ($cashCrPayments as $p) {
  if (stripos((string)($p['name'] ?? ''), 'dinheiro') !== false) $totalDinheiro += (float)($p['total'] ?? 0);
}

$tipoTitulo = ['ABERTURA'=>'Abertura / suprimento', 'SUPRIMENTO'=>'Reforço / suprimento', 'SANGRIA'=>'Sangria / retirada', 'VENDA'=>'Venda PDV', 'ESTORNO'=>'Estorno de venda', 'RECEBIMENTO_CR'=>'Recebimento C/R', 'ESTORNO_CR'=>'Estorno C/R', 'FECHAMENTO'=>'Fechamento'];

gestao_admin_start('Caixa', 'gestao_caixa');
gestao_page_header('Caixa', 'Abertura, reforço, sangria, resumo financeiro e fechamento do caixa do operador.', [
  ['label'=>'Pedido PDV', 'href'=>$cashUrl('gestao_pdv.php'), 'class'=>'btn btn-primary'],
  ['label'=>'Formas de pagamento', 'href'=>$cashUrl('gestao_formas_pagamento.php'), 'class'=>'btn'],
]);
gestao_tabs('gestao_caixa');
if ($erro) echo '<div class="msg err">' . gestao_h($erro) . '</div>';
if (!empty($schema['errors'])) {
  $schemaErrors = array_values(array_filter((array)$schema['errors'], static function($m){
    return stripos((string)$m, 'Duplicate') === false && stripos((string)$m, 'duplicado') === false;
  }));
  if ($schemaErrors) echo '<div class="msg err">Estrutura do caixa pendente: ' . gestao_h(implode(' | ', array_slice($schemaErrors, 0, 3))) . '</div>';
}
gestao_flash();
?>
<?php if (nexa_cash_user_control_disabled($pdo, $companyId)): ?>
  <div class="msg" style="background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;"><strong>Caixa por usuário desabilitado.</strong> Nesta empresa, vendas, baixas e pagamentos não exigem caixa aberto e o mesmo caixa pode ser usado por vários usuários. Abrir e fechar o caixa continua opcional. Transferências, sangrias e aportes também podem ser lançados em Financeiro &gt; Movimento financeiro.</div>
<?php endif; ?>
<?php if ($cashCrossContext): ?>
  <div class="msg ok"><strong>V11.26 · Caixa Multiempresa.</strong> Abertura, suprimento, sangria, fechamento e vendas vinculadas ao caixa pertencem exclusivamente à empresa selecionada pelo F11. A emissão fiscal continua bloqueada nesta etapa.</div>
<?php endif; ?>
<style>
  .cash-page-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;align-items:start}.cash-full{grid-column:1/-1}.cash-card-soft{background:linear-gradient(180deg,#fff,#f8fbff);border:1px solid #dbe4f0;border-radius:24px;padding:18px;box-shadow:0 18px 45px rgba(11,31,91,.08)}
  .cash-summary-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}.cash-summary-head h2{margin:0}.cash-print-btn{white-space:nowrap}.cash-summary-lines{display:grid;gap:8px}.cash-summary-line{display:flex;justify-content:space-between;gap:12px;border-bottom:1px dashed #cbd5e1;padding:8px 0;font-weight:850}.cash-summary-line.total{border:1px solid #dbe4f0;border-radius:14px;padding:10px 12px;background:#f8fbff}.cash-summary-line strong{font-variant-numeric:tabular-nums}.cash-summary-line small{display:block;color:#64748b;font-weight:750;margin-top:2px}.cash-chart-wrap{display:grid;grid-template-columns:190px minmax(0,1fr);gap:18px;align-items:center}.cash-donut{width:180px;height:180px;border-radius:50%;background:conic-gradient(var(--cash-chart));position:relative;margin:auto;box-shadow:inset 0 0 0 1px #dbe4f0}.cash-donut:after{content:'';position:absolute;inset:45px;background:#fff;border-radius:50%;box-shadow:inset 0 0 0 1px #e2e8f0}.cash-donut-center{position:absolute;inset:0;display:flex;align-items:center;justify-content:center;text-align:center;z-index:2;font-weight:950;color:#0f255f}.cash-donut-center span{display:block;font-size:12px;color:#64748b;font-weight:850}.cash-legend{display:grid;gap:8px}.cash-legend-row{display:grid;grid-template-columns:16px minmax(0,1fr) auto;gap:9px;align-items:center}.cash-dot{width:12px;height:12px;border-radius:50%;display:inline-block}.cash-modal-backdrop{position:fixed;inset:0;background:rgba(15,23,42,.45);z-index:500;display:flex;align-items:center;justify-content:center;padding:18px}.cash-modal-box{width:min(720px,100%);max-height:calc(100vh - 36px);overflow:auto;background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 30px 80px rgba(2,6,23,.28);padding:20px}.cash-modal-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:10px}.cash-modal-head h2{margin:0;color:var(--admin-primary)}.cash-close{display:inline-flex;align-items:center;justify-content:center;width:40px;height:40px;border-radius:14px;border:1px solid #dbe4f0;background:#fff;color:#0f172a;text-decoration:none;font-weight:950}.cash-action-modal{cursor:pointer}.cash-ops-table td .small-muted{display:block;color:#64748b;font-size:12px;margin-top:3px}.cash-ops-table .amount-credit{color:#047857}.cash-ops-table .amount-debit{color:#b91c1c}
  @media(max-width:1100px){.cash-page-grid{grid-template-columns:1fr}.cash-chart-wrap{grid-template-columns:1fr}.cash-donut{width:170px;height:170px}.cash-donut:after{inset:42px}}
  @media(max-width:680px){.cash-card-soft{border-radius:20px;padding:15px}.cash-summary-head{align-items:flex-start;flex-direction:column}.cash-print-btn{width:100%;text-align:center}.cash-summary-line{font-size:14px}.cash-modal-backdrop{align-items:flex-end;padding:0}.cash-modal-box{border-radius:24px 24px 0 0;max-height:92vh}.cash-donut{width:150px;height:150px}.cash-donut:after{inset:38px}}
</style>

<div class="cash-page-grid">
  <div class="mg-card">
    <h2>Status do caixa</h2>
    <?php if ($session): ?>
      <div class="mg-grid-4" style="grid-template-columns:repeat(auto-fit,minmax(140px,1fr))">
        <div class="mg-kpi"><span>Situação</span><strong>Aberto</strong></div>
        <div class="mg-kpi"><span>Saldo previsto</span><strong><?= gestao_money($summary['expected'] ?? 0) ?></strong></div>
        <div class="mg-kpi"><span>Vendas PDV</span><strong><?= gestao_money($summary['sales'] ?? 0) ?></strong></div>
        <div class="mg-kpi"><span>Recebimentos C/R</span><strong><?= gestao_money($cashCrNet) ?></strong></div>
        <div class="mg-kpi red"><span>Estornos</span><strong><?= gestao_money($summary['estorno'] ?? 0) ?></strong></div>
      </div>
      <p class="mg-muted">Aberto em <?= gestao_h(date('d/m/Y H:i', strtotime((string)$session['opened_at']))) ?>.</p>
      <div class="cash-menu" style="margin-top:12px;">
        <a class="cash-action cash-action-modal" href="<?= gestao_h($cashUrl('gestao_caixa.php?modal=suprimento')) ?>"><span>Reforço de caixa</span><span class="cash-key">F4</span></a>
        <a class="cash-action cash-action-modal" href="<?= gestao_h($cashUrl('gestao_caixa.php?modal=sangria')) ?>"><span>Sangria / retirada</span><span class="cash-key">F5</span></a>
        <a class="cash-action cash-action-modal" href="<?= gestao_h($cashUrl('gestao_caixa.php?modal=fechar')) ?>"><span>Fechamento do caixa</span><span class="cash-key">F8</span></a>
        <a class="cash-action" href="<?= gestao_h($cashUrl('gestao_pdv.php')) ?>"><span>Nova venda PDV</span><span class="cash-key">F2</span></a>
      </div>
    <?php else: ?>
      <div class="mg-kpi red"><span>Situação</span><strong>Fechado</strong></div>
      <form method="post" class="mg-form-grid" style="margin-top:12px;">
        <input type="hidden" name="action" value="open_cash"><?php if ($cashCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($cashMcCsrf) ?>"><?php endif; ?>
        <label>Caixa/Banco
          <select name="bank_account_id"><?php gestao_select_options(array_values(array_filter($banks, static fn($b)=>!nexa_cash_operator_conflict($pdo,$companyId,(int)$b['id'],$userId))), $defaultBank, 'Selecione', false); ?></select>
        </label>
        <label>Valor de abertura<input name="opening_amount" inputmode="decimal" value="0,00"></label>
        <label class="span-2">Plano de contas para abertura/suprimento
          <select name="chart_account_id"><?php gestao_select_options($chartsCredito, null, 'Opcional', true); ?></select>
        </label>
        <label class="span-2">Histórico<textarea name="notes" rows="2" placeholder="Ex.: abertura do dia"></textarea></label>
        <div class="span-2 mg-form-actions"><button class="btn btn-primary" type="submit">Abrir caixa</button></div>
      </form>
    <?php endif; ?>
  </div>

  <div class="mg-card">
    <h2><?= $cashCrossContext ? 'Caixa preferencial da sessão F11' : 'Caixa padrão do usuário' ?></h2>
    <p class="mg-muted"><?= $cashCrossContext ? 'Usado nesta sessão Multiempresa quando a forma de pagamento não define um Caixa/Banco. Não altera o padrão da Empresa 1.' : 'Usado automaticamente no PDV e nas formas de pagamento sem banco definido.' ?></p>
    <form method="post" class="mg-form-grid">
      <input type="hidden" name="action" value="default_cash"><?php if ($cashCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($cashMcCsrf) ?>"><?php endif; ?>
      <label class="span-2">Caixa/Banco padrão
        <select name="default_cash_bank_account_id"><?php gestao_select_options($banks, $defaultBank, 'Selecione', false); ?></select>
      </label>
      <div class="span-2 mg-form-actions"><button class="btn btn-primary" type="submit">Salvar padrão</button></div>
    </form>
  </div>

  <?php if ($session): ?>
  <div class="mg-card">
    <h2>Formas de pagamento — vendas PDV</h2>
    <div class="cash-chart-wrap">
      <div class="cash-donut" style="--cash-chart:<?= gestao_h($chart['css']) ?>;"><div class="cash-donut-center"><div><span>Total</span><?= gestao_money($chart['total']) ?></div></div></div>
      <div class="cash-legend">
        <?php foreach ($chart['legend'] as $l): ?>
          <div class="cash-legend-row"><span class="cash-dot" style="background:<?= gestao_h($l['color']) ?>"></span><span><?= gestao_h($l['name']) ?><small class="mg-muted"> <?= number_format((float)$l['pct'], 1, ',', '.') ?>%</small></span><strong><?= gestao_money($l['total']) ?></strong></div>
        <?php endforeach; ?>
        <?php if (empty($chart['legend'])): ?><div class="mg-empty">Nenhuma venda neste caixa.</div><?php endif; ?>
      </div>
    </div>
  </div>

  <div class="mg-card cash-full">
    <div class="cash-summary-head">
      <h2>Resumo financeiro do Caixa</h2>
      <a class="btn cash-print-btn" href="<?= gestao_h($cashUrl('gestao_caixa.php?print_summary=' . (int)$session['id'] . '&autoprint=1')) ?>" target="_blank">🖨️ Imprimir resumo</a>
    </div>
    <?= nexa_cash_financial_report_css() ?>
    <?= nexa_cash_financial_report_html((array)$summary, (array)$session, $sessionOperator, $sessionBankName) ?>
  </div>

  <div class="mg-card cash-full">
    <h2>Recebimentos por origem e forma de pagamento</h2>
    <p class="mg-muted">Valores do caixa atual. Contas a Receber apresenta valor líquido de estornos. Apenas formas configuradas para movimentar o caixa são consideradas na origem C/R.</p>
    <div class="table-wrap"><table class="mg-table"><thead><tr><th>Forma de pagamento</th><th>Vendas PDV</th><th>Contas a Receber</th><th>Total</th></tr></thead><tbody>
      <?php foreach ($allCashMethods as $method): $saleVal=(float)($salesByMethod[$method]??0); $crVal=(float)($cashCrByMethod[$method]??0); ?>
        <tr><td><?= gestao_h($method) ?></td><td><?= gestao_money($saleVal) ?></td><td><?= gestao_money($crVal) ?></td><td><strong><?= gestao_money($saleVal+$crVal) ?></strong></td></tr>
      <?php endforeach; ?>
      <?php if (!$allCashMethods): ?><tr><td colspan="4" class="mg-empty">Nenhum recebimento lançado neste caixa.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>

  <div class="mg-card cash-full">
    <h2>Movimentos do caixa</h2>
    <div class="table-wrap"><table class="mg-table cash-ops-table"><thead><tr><th>Data</th><th>Tipo</th><th>Histórico</th><th>Caixa/Banco</th><th>Plano/Centro</th><th>Valor</th></tr></thead><tbody>
    <?php foreach ($ops as $op):
      $type = (string)$op['type'];
      $isDebit = in_array($type, ['SANGRIA','ESTORNO','ESTORNO_CR'], true);
      $label = $tipoTitulo[$type] ?? $type;
      $extra = [];
      if (!empty($op['order_id'])) $extra[] = 'Pedido #' . (int)company_sequence_display($op, 'pedidos', 'order_id');
      if (in_array((string)($op['order_source'] ?? ''), ['ordem_servico','ordem_servico_lote'], true)) $extra[] = 'Origem: O.S. faturada';
      if (!empty($op['notes'])) $extra[] = (string)$op['notes'];
    ?>
      <tr>
        <td data-label="Data"><?= gestao_h(date('d/m/Y H:i', strtotime((string)$op['operation_date']))) ?></td>
        <td data-label="Tipo"><span class="mg-badge <?= $isDebit ? 'red' : 'green' ?>"><?= gestao_h($label) ?></span></td>
        <td data-label="Histórico" class="primary"><?= gestao_h($op['description']) ?><?php if ($extra): ?><span class="small-muted"><?= gestao_h(implode(' • ', $extra)) ?></span><?php endif; ?></td>
        <td data-label="Caixa/Banco"><?= gestao_h((string)($op['bank_name'] ?? '-')) ?></td>
        <td data-label="Plano/Centro"><?= gestao_h((string)($op['chart_name'] ?? '-')) ?><?php if (!empty($op['cost_center_name'])): ?><span class="small-muted"><?= gestao_h((string)$op['cost_center_name']) ?></span><?php endif; ?></td>
        <td data-label="Valor" class="mg-value <?= $isDebit ? 'amount-debit' : 'amount-credit' ?>"><?= $isDebit ? '- ' : '' ?><?= gestao_money($op['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$ops): ?><tr><td colspan="6" class="mg-empty">Nenhum movimento.</td></tr><?php endif; ?>
    </tbody></table></div>
  </div>
  <?php endif; ?>
</div>

<?php if ($session && in_array($modal, ['suprimento','sangria'], true)): $isSangria = $modal === 'sangria'; ?>
<div class="cash-modal-backdrop" role="dialog" aria-modal="true">
  <div class="cash-modal-box">
    <div class="cash-modal-head"><div><h2><?= $isSangria ? 'Retirada / Sangria' : 'Reforço de Caixa / Suprimento' ?></h2><p class="mg-muted">Registre a operação do caixa sem sair da tela.</p></div><a class="cash-close" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">×</a></div>
    <form method="post" class="mg-form-grid">
      <input type="hidden" name="action" value="cash_operation"><?php if ($cashCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($cashMcCsrf) ?>"><?php endif; ?><input type="hidden" name="type" value="<?= $isSangria ? 'SANGRIA' : 'SUPRIMENTO' ?>">
      <label>Valor<input name="amount" inputmode="decimal" required autofocus></label>
      <label>Plano de contas
        <?php $cashCfgDefaults = nexa_cash_settings($pdo, $companyId); $cashDefaultChart = (int)($cashCfgDefaults[$isSangria ? 'chart_sangria' : 'chart_aporte'] ?? 0) ?: null; ?>
        <select name="chart_account_id"><?php gestao_select_options($isSangria ? $chartsDebito : $chartsCredito, $cashDefaultChart, 'Opcional', true); ?></select>
      </label>
      <label class="span-2">Centro de custo
        <select name="cost_center_id"><?php gestao_select_options($costs, null, 'Sem centro', true); ?></select>
      </label>
      <label class="span-2">Histórico<input name="description" value="<?= $isSangria ? 'Retirada / sangria' : 'Reforço / suprimento' ?>"></label>
      <label class="span-2">Observações<textarea name="notes" rows="2"></textarea></label>
      <div class="span-2 mg-form-actions"><a class="btn" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">Cancelar</a><button class="btn btn-primary" type="submit">Confirmar</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($session && $modal === 'fechar'): ?>
<div class="cash-modal-backdrop" role="dialog" aria-modal="true">
  <div class="cash-modal-box">
    <div class="cash-modal-head"><div><h2>Fechamento do Caixa</h2><p class="mg-muted">Confira o previsto e informe o valor contado no caixa.</p></div><a class="cash-close" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">×</a></div>
    <form method="post" class="mg-form-grid">
      <input type="hidden" name="action" value="close_cash"><?php if ($cashCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($cashMcCsrf) ?>"><?php endif; ?>
      <label>Saldo previsto<input readonly value="<?= gestao_money($summary['expected'] ?? 0) ?>"></label>
      <label>Valor informado no fechamento<input name="closing_amount" inputmode="decimal" required value="<?= number_format((float)($summary['expected'] ?? 0), 2, ',', '.') ?>"></label>
      <label class="span-2">Observações<textarea name="notes" rows="2"></textarea></label>
      <div class="span-2 mg-form-actions"><a class="btn" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">Cancelar</a><button class="btn btn-primary" type="submit">Fechar caixa</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($askPrintSession): ?>
<div class="cash-modal-backdrop" role="dialog" aria-modal="true" aria-labelledby="cash-print-confirm-title">
  <div class="cash-modal-box" style="max-width:520px;">
    <div class="cash-modal-head">
      <div>
        <h2 id="cash-print-confirm-title">Caixa fechado</h2>
        <p class="mg-muted">Deseja imprimir o Resumo de Caixa em 72 mm?</p>
      </div>
      <a class="cash-close" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">×</a>
    </div>
    <div class="mg-form-actions" style="margin-top:18px;">
      <a class="btn" href="<?= gestao_h($cashUrl('gestao_caixa.php')) ?>">Não</a>
      <a class="btn btn-primary" href="<?= gestao_h($cashUrl('gestao_caixa.php?print_summary=' . (int)$askPrintId . '&autoprint=1')) ?>" target="_blank" onclick="setTimeout(function(){window.location.href='<?= gestao_h($cashUrl('gestao_caixa.php')) ?>';},250);">🖨️ Sim, imprimir</a>
    </div>
  </div>
</div>
<?php endif; ?>
<?php gestao_admin_end(); ?>
