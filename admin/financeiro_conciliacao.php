<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/bank_reconciliation.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$reconCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
$reconCrossImport = $reconCrossContext && function_exists('finance_multi_company_reconciliation_import_enabled') && finance_multi_company_reconciliation_import_enabled($pdo);
$reconCrossSettlement = $reconCrossContext && function_exists('finance_multi_company_reconciliation_settlement_enabled') && finance_multi_company_reconciliation_settlement_enabled($pdo);
$reconCrossStatus = $reconCrossContext && function_exists('finance_multi_company_reconciliation_status_enabled') && finance_multi_company_reconciliation_status_enabled($pdo);
$reconCrossCreateTitle = $reconCrossContext && function_exists('finance_multi_company_reconciliation_create_title_enabled') && finance_multi_company_reconciliation_create_title_enabled($pdo);
$reconCrossCreateMovement = $reconCrossContext && function_exists('finance_multi_company_reconciliation_create_movement_enabled') && finance_multi_company_reconciliation_create_movement_enabled($pdo);
$userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
$err = '';
$schemaOk = bank_recon_schema_ok($pdo);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    // V11.19: no contexto F11 ficam homologadas as operações de Conciliação já liberadas,
    // incluindo o lançamento financeiro direto. Rotinas Itaú automáticas continuam separadas.
    if ($reconCrossContext && !in_array($action, ['import','reconcile_title','ignore','reopen','create_title','create_movement'], true)) {
      throw new RuntimeException('V11.19: esta ação ainda não foi homologada para a Conciliação Bancária no contexto F11.');
    }
    if ($reconCrossContext && $action === 'import' && !$reconCrossImport) {
      throw new RuntimeException('V11.14: seu contexto Multiempresa não está autorizado a importar OFX nesta empresa.');
    }
    if ($reconCrossContext && $action === 'reconcile_title' && !$reconCrossSettlement) {
      throw new RuntimeException('V11.16: seu contexto Multiempresa não está autorizado a conciliar títulos nesta empresa.');
    }
    if ($reconCrossContext && in_array($action, ['ignore','reopen'], true) && !$reconCrossStatus) {
      throw new RuntimeException('V11.17: seu contexto Multiempresa não está autorizado a Ignorar/Reabrir movimentos nesta empresa.');
    }
    if ($reconCrossContext && $action === 'create_title' && !$reconCrossCreateTitle) {
      throw new RuntimeException('V11.18: seu contexto Multiempresa não está autorizado a criar título pela Conciliação nesta empresa.');
    }
    if ($reconCrossContext && $action === 'create_movement' && !$reconCrossCreateMovement) {
      throw new RuntimeException('V11.19: seu contexto Multiempresa não está autorizado a criar movimento financeiro pela Conciliação nesta empresa.');
    }
    if (!$schemaOk) throw new RuntimeException('A estrutura da Conciliação Bancária ainda não foi instalada. Aplique o SQL db/20260825_conciliacao_bancaria_ofx_v1.sql.');

    if ($action === 'import') {
      if ($reconCrossContext && function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao')) {
        throw new RuntimeException('Seu perfil não possui permissão para Conciliação Bancária na empresa selecionada pelo F11.');
      }
      if ($reconCrossContext) {
        $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
          throw new RuntimeException('A sessão de segurança do Multiempresa expirou. Recarregue a página e tente importar o OFX novamente.');
        }
      }
      $result = bank_recon_import_ofx($pdo, $cid, (int)($_POST['bank_account_id'] ?? 0), (array)($_FILES['ofx_file'] ?? []), $userId ?: null);
      $msg = ($reconCrossContext ? 'V11.14 · ' : '') . 'OFX importado: ' . (int)$result['inserted'] . ' movimento(s) novo(s)';
      if ((int)$result['duplicates'] > 0) $msg .= ' e ' . (int)$result['duplicates'] . ' duplicado(s) ignorado(s)';
      $msg .= '.';
      finance_redirect('financeiro_conciliacao.php?ok=' . rawurlencode($msg) . '&import_id=' . (int)$result['import_id']);
    }

    if ($action === 'reconcile_title') {
      $itemId = (int)($_POST['item_id'] ?? 0);
      $originType = strtoupper(trim((string)($_POST['origin_type'] ?? '')));
      $originId = (int)($_POST['origin_id'] ?? 0);
      if ($reconCrossContext) {
        if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao')) {
          throw new RuntimeException('Seu perfil não possui permissão para Conciliação Bancária na empresa selecionada pelo F11.');
        }
        $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
          throw new RuntimeException('A sessão de segurança do Multiempresa expirou. Recarregue a página e tente conciliar novamente.');
        }
        $settlePerm = $originType === 'RECEBER' ? 'financeiro_baixar_receber' : ($originType === 'PAGAR' ? 'financeiro_baixar_pagar' : '');
        if ($settlePerm === '' || !function_exists('finance_effective_permission_allowed') || !finance_effective_permission_allowed($pdo, $settlePerm)) {
          throw new RuntimeException('Seu perfil não possui a permissão de baixa necessária para este título na empresa selecionada pelo F11.');
        }
        if (function_exists('finance_multi_company_assert_settlement_safe')) {
          finance_multi_company_assert_settlement_safe($pdo, $cid, $originType, [$originId]);
        }
      }

      // Evita duplo clique/processamento concorrente do mesmo item OFX.
      $lockName = 'nexa_recon_title_' . $cid . '_' . $itemId;
      $lockAcquired = false;
      try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) throw new RuntimeException('Este movimento está sendo conciliado por outro processo. Aguarde alguns segundos e tente novamente.');
        bank_recon_reconcile_title($pdo, $cid, $itemId, $originType, $originId, $_POST, $userId ?: null);
      } finally {
        if ($lockAcquired) {
          try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $lockError) {}
        }
      }
      finance_redirect('financeiro_conciliacao.php?ok=' . rawurlencode(($reconCrossContext ? 'V11.16 · ' : '') . 'Movimento conciliado com o título e baixa financeira realizada.'));
    }

    if ($action === 'create_title') {
      $itemId = (int)($_POST['item_id'] ?? 0);
      if ($reconCrossContext) {
        if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao')) {
          throw new RuntimeException('Seu perfil não possui permissão para Conciliação Bancária na empresa selecionada pelo F11.');
        }
        $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
          throw new RuntimeException('A sessão de segurança do Multiempresa expirou. Recarregue a página e tente criar o título novamente.');
        }
        $itemForPermission = bank_recon_assert_pending($pdo, $cid, $itemId);
        $isCreditForPermission = (string)($itemForPermission['direction'] ?? '') === 'CREDITO';
        $titlePerm = $isCreditForPermission ? 'financeiro_receber' : 'financeiro_pagar';
        $settlePerm = $isCreditForPermission ? 'financeiro_baixar_receber' : 'financeiro_baixar_pagar';
        if (!function_exists('finance_effective_permission_allowed')
            || !finance_effective_permission_allowed($pdo, $titlePerm)
            || !finance_effective_permission_allowed($pdo, $settlePerm)) {
          throw new RuntimeException('Seu perfil não possui as permissões necessárias para criar e baixar este título na empresa selecionada pelo F11.');
        }
      }

      // Compartilha o mesmo lock das demais ações de conciliação para impedir que
      // o mesmo item gere simultaneamente um título e outra baixa/alteração de estado.
      $lockName = 'nexa_recon_title_' . $cid . '_' . $itemId;
      $lockAcquired = false;
      try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) throw new RuntimeException('Este movimento está sendo processado por outro processo. Aguarde alguns segundos e tente novamente.');
        $titleId = bank_recon_create_title_and_settle($pdo, $cid, $itemId, $_POST, $userId ?: null);
      } finally {
        if ($lockAcquired) {
          try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $lockError) {}
        }
      }
      finance_redirect('financeiro_conciliacao.php?ok=' . rawurlencode(($reconCrossContext ? 'V11.18 · ' : '') . 'Título #' . $titleId . ' criado, baixado e conciliado pelo OFX.'));
    }

    if ($action === 'create_movement') {
      $itemId = (int)($_POST['item_id'] ?? 0);
      if ($reconCrossContext) {
        if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao')) {
          throw new RuntimeException('Seu perfil não possui permissão para Conciliação Bancária na empresa selecionada pelo F11.');
        }
        $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
          throw new RuntimeException('A sessão de segurança do Multiempresa expirou. Recarregue a página e tente criar o movimento novamente.');
        }
        if (!function_exists('finance_effective_permission_allowed')
            || !finance_effective_permission_allowed($pdo, 'financeiro_movimentos')
            || !finance_effective_permission_allowed($pdo, 'financeiro_movimento_manual')) {
          throw new RuntimeException('Seu perfil não possui as permissões Movimento Financeiro e Lançar movimento manual na empresa selecionada pelo F11.');
        }
      }

      // Compartilha o lock lógico com título/baixa/ignorar para impedir que o mesmo
      // item OFX gere dois lançamentos ou seja alterado simultaneamente.
      $lockName = 'nexa_recon_title_' . $cid . '_' . $itemId;
      $lockAcquired = false;
      try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) throw new RuntimeException('Este movimento está sendo processado por outro processo. Aguarde alguns segundos e tente novamente.');
        $movementId = bank_recon_create_movement($pdo, $cid, $itemId, $_POST, $userId ?: null);
      } finally {
        if ($lockAcquired) {
          try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $lockError) {}
        }
      }
      finance_redirect('financeiro_conciliacao.php?ok=' . rawurlencode(($reconCrossContext ? 'V11.19 · ' : '') . 'Movimento financeiro #' . $movementId . ' criado e conciliado.'));
    }

    if ($action === 'ignore' || $action === 'reopen') {
      $itemId = (int)($_POST['item_id'] ?? 0);
      if ($reconCrossContext) {
        if (function_exists('multi_company_context_permission_allowed') && !multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao')) {
          throw new RuntimeException('Seu perfil não possui permissão para Conciliação Bancária na empresa selecionada pelo F11.');
        }
        $csrf = trim((string)($_POST['mc_csrf'] ?? ''));
        if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid($csrf)) {
          throw new RuntimeException('A sessão de segurança do Multiempresa expirou. Recarregue a página e tente novamente.');
        }
      }

      // Usa o mesmo lock lógico da conciliação de título para impedir corrida entre
      // Ignorar/Reabrir e uma baixa simultânea sobre o mesmo movimento OFX.
      $lockName = 'nexa_recon_title_' . $cid . '_' . $itemId;
      $lockAcquired = false;
      try {
        $lockStmt = $pdo->prepare('SELECT GET_LOCK(?,5)');
        $lockStmt->execute([$lockName]);
        $lockAcquired = ((int)$lockStmt->fetchColumn() === 1);
        if (!$lockAcquired) throw new RuntimeException('Este movimento está sendo alterado por outro processo. Aguarde alguns segundos e tente novamente.');
        bank_recon_set_ignored($pdo, $cid, $itemId, $action === 'ignore', $userId ?: null);
      } finally {
        if ($lockAcquired) {
          try { $release = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $release->execute([$lockName]); } catch (Throwable $lockError) {}
        }
      }
      $ok = $action === 'ignore' ? 'Movimento marcado como ignorado.' : 'Movimento devolvido para pendentes.';
      finance_redirect('financeiro_conciliacao.php?ok=' . rawurlencode(($reconCrossContext ? 'V11.17 · ' : '') . $ok));
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

// V11.17.1: em contexto F11, o usuário pertence ao tenant de entrada; por isso
// user_cash_accounts da empresa selecionada não representa a autorização efetiva.
// A lista usa o perfil Multiempresa de Conciliação e continua limitada às contas
// ativas pertencentes ao company_id operacional.
$banks = ($reconCrossContext && function_exists('finance_multi_company_reconciliation_bank_options'))
  ? finance_multi_company_reconciliation_bank_options($pdo, $cid)
  : finance_bank_options($pdo, $cid);
$status = strtoupper(trim((string)($_GET['status'] ?? 'PENDENTE')));
if (!in_array($status, ['PENDENTE','CONCILIADO','IGNORADO',''], true)) $status = 'PENDENTE';
$bankFilter = (int)($_GET['bank_account_id'] ?? 0);
$importFilter = (int)($_GET['import_id'] ?? 0);
$ini = finance_date_or_null($_GET['ini'] ?? '') ?: date('Y-m-01');
$fim = finance_date_or_null($_GET['fim'] ?? '') ?: date('Y-m-t');
$q = trim((string)($_GET['q'] ?? ''));
$titleQ = trim((string)($_GET['title_q'] ?? ''));

$rows = [];
$kpis = ['PENDENTE'=>0,'CONCILIADO'=>0,'IGNORADO'=>0];
$selectedItem = null;
$candidates = [];
$people = [];
$movementPeople = [];
$charts = [];
$costs = [];
if ($schemaOk) {
  $kpiRows = finance_query_all($pdo, "SELECT status,COUNT(*) qtd FROM finance_bank_reconciliation_items WHERE company_id=? AND posted_date BETWEEN ? AND ? GROUP BY status", [$cid,$ini,$fim]);
  foreach ($kpiRows as $kr) if (isset($kpis[(string)$kr['status']])) $kpis[(string)$kr['status']] = (int)$kr['qtd'];

  $where = ['i.company_id=?','i.posted_date BETWEEN ? AND ?'];
  $params = [$cid,$ini,$fim];
  if ($status !== '') { $where[] = 'i.status=?'; $params[] = $status; }
  if ($bankFilter > 0) { $where[] = 'i.bank_account_id=?'; $params[] = $bankFilter; }
  if ($importFilter > 0) { $where[] = 'i.import_id=?'; $params[] = $importFilter; }
  if ($q !== '') {
    $where[] = '(i.name LIKE ? OR i.memo LIKE ? OR i.fitid LIKE ? OR i.refnum LIKE ?)';
    $like = nexa_search_like($q); array_push($params,$like,$like,$like,$like);
  }
  $rows = finance_query_all($pdo, 'SELECT i.*, b.name AS bank_account_name, bi.file_name FROM finance_bank_reconciliation_items i LEFT JOIN finance_bank_accounts b ON b.company_id=i.company_id AND b.id=i.bank_account_id LEFT JOIN finance_bank_reconciliation_imports bi ON bi.company_id=i.company_id AND bi.id=i.import_id WHERE ' . implode(' AND ', $where) . ' ORDER BY i.posted_date DESC, i.id DESC LIMIT 600', $params);

  $selectedId = (int)($_GET['item'] ?? 0);
  if ($selectedId > 0) {
    $selectedItem = bank_recon_get_item($pdo,$cid,$selectedId);
    if ($selectedItem && (string)$selectedItem['status'] === 'PENDENTE') {
      $candidates = bank_recon_title_candidates($pdo,$cid,$selectedItem,20,$titleQ);
      $isCredit = (string)$selectedItem['direction'] === 'CREDITO';
      if (!$reconCrossContext || $reconCrossCreateTitle || $reconCrossCreateMovement) {
        if (!$reconCrossContext || $reconCrossCreateTitle) {
          $people = finance_people_options($pdo,$cid,$isCredit ? 'customer' : 'supplier');
          // Compatibilidade legada: fora do F11 mantém o fallback para pessoa genérica.
          // No Multiempresa V11.18, a pessoa precisa ter o papel correto na empresa selecionada.
          if (!$people && !$reconCrossContext) $people = finance_people_options($pdo,$cid,'');
        }
        if (!$reconCrossContext || $reconCrossCreateMovement) {
          $movementPeople = finance_people_options($pdo,$cid,'');
        }
        $charts = finance_chart_options($pdo,$cid,(string)$selectedItem['direction']);
        $costs = finance_cost_center_options($pdo,$cid);
      }
    }
  }
}

finance_admin_start('Financeiro - Conciliação Bancária', 'fin_conciliacao');
finance_page_header('Conciliação Bancária', 'Importe extratos OFX, localize títulos em aberto e transforme cada movimento bancário em baixa ou lançamento financeiro auditável.');
// V11.51.6: remove as abas gerais apenas desta tela para ganhar altura útil.
finance_flash();
if ($err !== ''): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<style>
.recon-kpis{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-bottom:10px}.recon-kpi{padding:10px 14px;border:1px solid #dbe4f0;border-radius:18px;background:#fff}.recon-kpi small{display:block;color:#64748b;font-weight:800}.recon-kpi strong{display:block;margin-top:3px;font-size:21px;color:#0f172a}.recon-upload{display:grid;grid-template-columns:minmax(220px,1fr) minmax(280px,2fr) auto;gap:12px;align-items:end}.recon-desc{max-width:430px;white-space:normal;line-height:1.3}.recon-score{display:inline-flex;padding:3px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-weight:900;font-size:12px}.recon-credit{color:#047857;font-weight:950}.recon-debit{color:#b91c1c;font-weight:950}.recon-detail-head{display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap;align-items:flex-start}.recon-detail-amount{font-size:26px;font-weight:950}.recon-candidate{border:1px solid #e2e8f0;border-radius:16px;padding:12px;margin-bottom:10px}.recon-candidate.best{border-color:#93c5fd;background:#f8fbff}.recon-candidate-grid{display:grid;grid-template-columns:1.2fr 1.6fr .8fr .8fr auto;gap:10px;align-items:center}.recon-action-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.recon-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.recon-form-grid .span-2{grid-column:span 2}.recon-mini{font-size:12px;color:#64748b}.recon-link{font-weight:850;text-decoration:none}.recon-method{border-top:1px solid #eef2f7;margin-top:12px;padding-top:12px}.recon-status-PENDENTE{color:#b45309}.recon-status-CONCILIADO{color:#047857}.recon-status-IGNORADO{color:#64748b}@media(max-width:900px){.recon-upload,.recon-action-grid,.recon-candidate-grid{grid-template-columns:1fr}.recon-kpis{grid-template-columns:1fr}.recon-form-grid{grid-template-columns:1fr}.recon-form-grid .span-2{grid-column:auto}}
.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.finance-filter-card .finance-filter{gap:8px 10px}.finance-filter-card .finance-filter label{margin-bottom:3px}.finance-filter-card .finance-filter input,.finance-filter-card .finance-filter select{min-height:39px}.finance-filter-card .finance-filter-actions .btn{min-height:39px}
</style>

<?php if (!$schemaOk): ?>
<div class="finance-card"><h3 class="finance-subtitle">Instalação necessária</h3><p>A página já está pronta, mas as tabelas da conciliação não existem neste banco. Aplique <code>db/20260825_conciliacao_bancaria_ofx_v1.sql</code> e recarregue.</p></div>
<?php else: ?>

<div class="recon-kpis">
  <div class="recon-kpi"><small>Pendentes no período</small><strong><?= (int)$kpis['PENDENTE'] ?></strong></div>
  <div class="recon-kpi"><small>Conciliados no período</small><strong><?= (int)$kpis['CONCILIADO'] ?></strong></div>
  <div class="recon-kpi"><small>Ignorados no período</small><strong><?= (int)$kpis['IGNORADO'] ?></strong></div>
</div>

<?php if (!$reconCrossContext || $reconCrossImport): ?>
<div class="finance-card">
  <div class="finance-card-head"><div><h3 class="finance-subtitle"><?= $reconCrossContext ? 'Importar extrato OFX · Multiempresa' : 'Importar extrato OFX' ?></h3><div class="finance-muted"><?= $reconCrossContext ? 'O arquivo será gravado exclusivamente na empresa selecionada pelo F11. Selecione uma conta bancária desta empresa. Importação OFX, conciliação com título existente, Ignorar/Reabrir, criação de título com baixa e movimento financeiro direto já estão homologados para esta empresa.' : 'Selecione a conta do Nexa correspondente ao extrato. Transações já importadas são bloqueadas por identificador bancário.' ?></div></div></div>
  <form method="post" enctype="multipart/form-data" class="recon-upload">
    <input type="hidden" name="action" value="import">
    <?php if ($reconCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><?php endif; ?>
    <div><label>Conta bancária *</label><select name="bank_account_id" required><?php finance_select_options($banks, null, 'Selecione a conta', false); ?></select></div>
    <div><label>Arquivo OFX *</label><input type="file" name="ofx_file" accept=".ofx,application/x-ofx,text/ofx,application/xml,text/xml" required></div>
    <div><button class="btn btn-primary"><?= $reconCrossContext ? 'Importar OFX na empresa selecionada' : 'Importar OFX' ?></button></div>
  </form>
</div>
<?php else: ?>
<div class="finance-card">
  <div class="finance-card-head"><div><h3 class="finance-subtitle">Extratos OFX · somente consulta</h3><div class="finance-muted">O contexto Multiempresa atual não está autorizado a importar novos arquivos OFX. Os extratos existentes continuam disponíveis para consulta.</div></div></div>
</div>
<?php endif; ?>

<?php if ($selectedItem): ?>
<div class="finance-card" id="conciliar">
  <div class="recon-detail-head">
    <div><h3 class="finance-subtitle"><?= ($reconCrossContext && !$reconCrossSettlement) ? 'Analisar' : 'Conciliar' ?> movimento #<?= (int)$selectedItem['id'] ?></h3><div class="finance-muted"><?= finance_h($selectedItem['bank_account_name'] ?? '') ?> · <?= finance_date_br($selectedItem['posted_date'] ?? '') ?> · <?= finance_h($selectedItem['trn_type'] ?? '') ?></div></div>
    <div class="recon-detail-amount <?= (float)$selectedItem['amount'] >= 0 ? 'recon-credit' : 'recon-debit' ?>"><?= (float)$selectedItem['amount'] >= 0 ? '+' : '-' ?> R$ <?= finance_money_br(abs((float)$selectedItem['amount'])) ?></div>
  </div>
  <p><strong><?= finance_h($selectedItem['name'] ?? '') ?></strong><br><span class="finance-muted"><?= finance_h($selectedItem['memo'] ?? '') ?></span></p>

  <?php if ((string)$selectedItem['status'] === 'PENDENTE'): ?>
    <div class="finance-card-head"><div><h4 style="margin:0"><?= $titleQ !== '' ? 'Resultado da pesquisa de títulos' : 'Sugestões encontradas' ?></h4><div class="finance-muted">A sugestão automática prioriza valor, proximidade de data e nome da pessoa. Se necessário, pesquise qualquer título em aberto.</div></div></div>
    <form method="get" class="recon-upload" style="grid-template-columns:minmax(260px,1fr) auto auto;margin-bottom:12px">
      <input type="hidden" name="item" value="<?= (int)$selectedItem['id'] ?>"><input type="hidden" name="ini" value="<?= finance_h($ini) ?>"><input type="hidden" name="fim" value="<?= finance_h($fim) ?>"><input type="hidden" name="status" value="<?= finance_h($status) ?>">
      <div><label>Pesquisar título em aberto</label><input name="title_q" value="<?= finance_h($titleQ) ?>" placeholder="Pessoa, descrição ou documento"></div>
      <div><button class="btn">Pesquisar</button></div>
      <div><?php if ($titleQ !== ''): ?><a class="btn" href="?item=<?= (int)$selectedItem['id'] ?>&ini=<?= rawurlencode($ini) ?>&fim=<?= rawurlencode($fim) ?>&status=<?= rawurlencode($status) ?>#conciliar">Voltar às sugestões</a><?php endif; ?></div>
    </form>
    <?php if (!$candidates): ?><p class="finance-muted"><?= $titleQ !== '' ? 'Nenhum título em aberto corresponde à pesquisa.' : 'Nenhum título compatível foi encontrado automaticamente.' ?><?= $reconCrossContext ? '' : ' Você pode criar um novo título já baixado ou lançar diretamente no Movimento Financeiro.' ?></p><?php endif; ?>
    <?php foreach ($candidates as $idx => $candidate):
      $bankAmount = abs((float)$selectedItem['amount']);
      $openAmount = (float)($candidate['open_amount'] ?? 0);
      $originType = (string)$candidate['origin_type'];
    ?>
      <div class="recon-candidate <?= $idx===0 ? 'best' : '' ?>">
        <div class="recon-candidate-grid">
          <div><strong><?= finance_h($candidate['person_name'] ?? '') ?></strong><div class="recon-mini"><?= finance_h($originType) ?> #<?= (int)$candidate['id'] ?></div></div>
          <div><?= finance_h($candidate['description'] ?? '') ?><div class="recon-mini">Doc. <?= finance_h($candidate['document_number'] ?? '') ?> · venc. <?= finance_date_br($candidate['due_date'] ?? '') ?></div></div>
          <div><strong>R$ <?= finance_money_br($openAmount) ?></strong><div class="recon-mini">saldo aberto</div></div>
          <div><span class="recon-score"><?= (int)$candidate['match_score'] ?>% compatível</span></div>
          <div><?php $candidateSettlePerm = $originType === 'RECEBER' ? 'financeiro_baixar_receber' : 'financeiro_baixar_pagar'; $candidateCanSettle = !$reconCrossContext || ($reconCrossSettlement && function_exists('finance_effective_permission_allowed') && finance_effective_permission_allowed($pdo, $candidateSettlePerm)); ?><?php if ($candidateCanSettle): ?><button type="button" class="btn" onclick="document.getElementById('recon-form-<?= (int)$candidate['id'] ?>').hidden=false">Usar título</button><?php else: ?><span class="finance-muted"><?= $reconCrossSettlement ? 'Sem permissão de baixa' : 'Somente consulta' ?></span><?php endif; ?></div>
        </div>
        <?php if ($candidateCanSettle): ?>
        <form method="post" id="recon-form-<?= (int)$candidate['id'] ?>" class="recon-form-grid recon-method" hidden>
          <input type="hidden" name="action" value="reconcile_title"><input type="hidden" name="item_id" value="<?= (int)$selectedItem['id'] ?>"><input type="hidden" name="origin_type" value="<?= finance_h($originType) ?>"><input type="hidden" name="origin_id" value="<?= (int)$candidate['id'] ?>"><?php if ($reconCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><?php endif; ?>
          <div><label>Principal</label><input name="amount" value="<?= finance_money_br(min($openAmount,$bankAmount)) ?>" required></div>
          <div><label>Multa</label><input name="fine_amount" value="0,00"></div>
          <div><label>Juros</label><input name="interest_amount" value="<?= $bankAmount > $openAmount ? finance_money_br($bankAmount-$openAmount) : '0,00' ?>"></div>
          <div><label>Desconto</label><input name="discount_amount" value="<?= $openAmount > $bankAmount ? finance_money_br($openAmount-$bankAmount) : '0,00' ?>"></div>
          <div class="span-2"><label>Forma da baixa</label><input name="payment_method" value="CONCILIAÇÃO OFX"></div>
          <div class="span-2 finance-form-actions"><button class="btn btn-primary">Confirmar baixa e conciliar</button></div>
        </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <?php if (!$reconCrossContext || $reconCrossCreateTitle || $reconCrossCreateMovement): ?>
    <div class="recon-action-grid"<?= $reconCrossContext ? ' style="grid-template-columns:1fr"' : '' ?>>
      <?php if (!$reconCrossContext || $reconCrossCreateTitle): ?>
      <div class="finance-form-section">
        <h3>Criar título e baixar automaticamente</h3>
        <p class="finance-muted">Use quando a entrada/saída existe no banco, mas ainda não existe no Contas a Receber/Pagar.</p>
        <form method="post" class="recon-form-grid">
          <input type="hidden" name="action" value="create_title"><input type="hidden" name="item_id" value="<?= (int)$selectedItem['id'] ?>"><?php if ($reconCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><?php endif; ?>
          <div class="span-2"><label><?= (string)$selectedItem['direction']==='CREDITO' ? 'Cliente/Pessoa' : 'Fornecedor/Pessoa' ?> *</label><select name="person_id" required><?php finance_select_options($people,null,'Selecione a pessoa',false); ?></select></div>
          <div class="span-2"><label>Plano de Contas *</label><select name="chart_account_id" required><?php finance_select_options($charts,null,'Selecione a conta'); ?></select></div>
          <div class="span-2"><label>Centro de Custo</label><select name="cost_center_id"><?php finance_select_options($costs,null,'Sem centro de custo'); ?></select></div>
          <div class="span-2"><label>Descrição</label><input name="description" value="<?= finance_h(trim((string)($selectedItem['memo'] ?: $selectedItem['name']))) ?>"></div>
          <div class="span-2"><label>Documento</label><input name="document_number" value="<?= finance_h($selectedItem['fitid'] ?: $selectedItem['refnum']) ?>"></div>
          <div class="span-2 finance-form-actions"><button class="btn btn-primary">Criar título + baixar + conciliar</button></div>
        </form>
      </div>
      <?php endif; ?>

      <?php if (!$reconCrossContext || $reconCrossCreateMovement): ?>
      <div class="finance-form-section">
        <h3>Lançar somente no Movimento Financeiro</h3>
        <p class="finance-muted">Use para tarifa bancária, rendimento, transferência ou outro evento que não precisa gerar título.</p>
        <form method="post" class="recon-form-grid">
          <input type="hidden" name="action" value="create_movement"><input type="hidden" name="item_id" value="<?= (int)$selectedItem['id'] ?>"><?php if ($reconCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><?php endif; ?>
          <div class="span-2"><label>Pessoa</label><select name="person_id"><?php finance_select_options($movementPeople,null,'Sem pessoa',false); ?></select></div>
          <div class="span-2"><label>Plano de Contas *</label><select name="chart_account_id" required><?php finance_select_options($charts,null,'Selecione a conta'); ?></select></div>
          <div class="span-2"><label>Centro de Custo</label><select name="cost_center_id"><?php finance_select_options($costs,null,'Sem centro de custo'); ?></select></div>
          <div class="span-2"><label>Descrição</label><input name="description" value="<?= finance_h(trim((string)($selectedItem['memo'] ?: $selectedItem['name']))) ?>"></div>
          <div class="span-2"><label>Documento</label><input name="document_number" value="<?= finance_h($selectedItem['fitid'] ?: $selectedItem['refnum']) ?>"></div>
          <div class="span-2 finance-form-actions"><button class="btn">Criar movimento e conciliar</button></div>
        </form>
      </div>
      <?php endif; ?>
    </div>
    <?php endif; ?>
    <?php if ($reconCrossContext): ?>
      <div class="msg ok" style="margin-top:12px"><strong>V11.23:</strong> auditoria final concluída para a Conciliação: importação OFX, conciliação com título existente, Ignorar/Reabrir, Criar título + baixar + conciliar e Criar movimento financeiro direto usam a empresa selecionada pelo F11.</div>
    <?php endif; ?>
  <?php else: ?>
    <p><strong>Status:</strong> <?= finance_h($selectedItem['status']) ?> · <strong>Modo:</strong> <?= finance_h($selectedItem['reconciliation_mode'] ?? '') ?></p>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="finance-card finance-filter-card">
<form method="get" class="finance-filter">
  <div><label>Período inicial</label><input type="date" name="ini" value="<?= finance_h($ini) ?>"></div>
  <div><label>Período final</label><input type="date" name="fim" value="<?= finance_h($fim) ?>"></div>
  <div><label>Conta bancária</label><select name="bank_account_id"><?php finance_select_options($banks,$bankFilter,'Todas as contas',false); ?></select></div>
  <div><label>Situação</label><select name="status"><option value="" <?= $status===''?'selected':'' ?>>Todas</option><option value="PENDENTE" <?= $status==='PENDENTE'?'selected':'' ?>>Pendentes</option><option value="CONCILIADO" <?= $status==='CONCILIADO'?'selected':'' ?>>Conciliados</option><option value="IGNORADO" <?= $status==='IGNORADO'?'selected':'' ?>>Ignorados</option></select></div>
  <div class="wide"><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Histórico, memo, FITID ou referência"></div>
  <div class="finance-filter-actions"><button class="btn">Filtrar</button><a class="btn" href="financeiro_conciliacao.php">Limpar</a></div>
</form>
</div>

<div class="finance-card">
  <div class="finance-card-head"><h3 class="finance-subtitle">Movimentos importados</h3><div class="finance-muted"><?= count($rows) ?> registro(s) exibido(s)</div></div>
  <div style="overflow:auto"><table><thead><tr><th>Data</th><th>Conta</th><th>Histórico</th><th>Identificador</th><th>Sugestão</th><th>Situação</th><th class="finance-value">Valor</th><th>Ações</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="8" class="finance-muted">Nenhum movimento encontrado no período/filtro.</td></tr><?php endif; ?>
  <?php foreach ($rows as $row): ?>
    <tr>
      <td><?= finance_date_br($row['posted_date']) ?></td>
      <td><?= finance_h($row['bank_account_name'] ?? '') ?><div class="recon-mini"><?= finance_h($row['file_name'] ?? '') ?></div></td>
      <td class="recon-desc"><strong><?= finance_h($row['name'] ?? '') ?></strong><div class="recon-mini"><?= finance_h($row['memo'] ?? '') ?></div></td>
      <td><span class="recon-mini"><?= finance_h($row['fitid'] ?: $row['refnum']) ?></span></td>
      <td><?php if (!empty($row['suggested_origin_id']) && (string)$row['status']==='PENDENTE'): ?><span class="recon-score"><?= (int)$row['suggested_score'] ?>%</span><div class="recon-mini"><?= finance_h($row['suggested_origin_type']) ?> #<?= (int)$row['suggested_origin_id'] ?></div><?php else: ?><span class="finance-muted">—</span><?php endif; ?></td>
      <td><strong class="recon-status-<?= finance_h($row['status']) ?>"><?= finance_h($row['status']) ?></strong><?php if ((string)$row['status']==='CONCILIADO'): ?><div class="recon-mini"><?= finance_h($row['reconciliation_mode'] ?? '') ?></div><?php endif; ?></td>
      <td class="finance-value <?= (float)$row['amount'] >= 0 ? 'recon-credit' : 'recon-debit' ?>"><?= (float)$row['amount'] >= 0 ? '+' : '-' ?> R$ <?= finance_money_br(abs((float)$row['amount'])) ?></td>
      <td><div class="finance-inline-actions">
        <?php if ($reconCrossContext): ?>
          <a class="btn<?= ((string)$row['status']==='PENDENTE' && $reconCrossSettlement) ? ' btn-primary' : '' ?>" href="?item=<?= (int)$row['id'] ?>&status=<?= rawurlencode($status) ?>&ini=<?= rawurlencode($ini) ?>&fim=<?= rawurlencode($fim) ?>#conciliar"><?= ((string)$row['status']==='PENDENTE' && $reconCrossSettlement) ? 'Conciliar' : 'Ver' ?></a>
          <?php if ($reconCrossStatus && (string)$row['status']==='PENDENTE'): ?><form method="post" onsubmit="return confirm('Ignorar este movimento do extrato na empresa selecionada?')"><input type="hidden" name="action" value="ignore"><input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><button class="btn">Ignorar</button></form><?php endif; ?>
          <?php if ($reconCrossStatus && (string)$row['status']==='IGNORADO'): ?><form method="post" onsubmit="return confirm('Reabrir este movimento e devolvê-lo para pendentes na empresa selecionada?')"><input type="hidden" name="action" value="reopen"><input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>"><input type="hidden" name="mc_csrf" value="<?= finance_h(function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '') ?>"><button class="btn">Reabrir</button></form><?php endif; ?>
        <?php elseif ((string)$row['status']==='PENDENTE'): ?><a class="btn btn-primary" href="?item=<?= (int)$row['id'] ?>&status=<?= rawurlencode($status) ?>&ini=<?= rawurlencode($ini) ?>&fim=<?= rawurlencode($fim) ?>#conciliar">Conciliar</a><form method="post" onsubmit="return confirm('Ignorar este movimento do extrato?')"><input type="hidden" name="action" value="ignore"><input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>"><button class="btn">Ignorar</button></form>
        <?php elseif ((string)$row['status']==='IGNORADO'): ?><form method="post"><input type="hidden" name="action" value="reopen"><input type="hidden" name="item_id" value="<?= (int)$row['id'] ?>"><button class="btn">Reabrir</button></form>
        <?php else: ?><a class="btn" href="?item=<?= (int)$row['id'] ?>&status=<?= rawurlencode($status) ?>&ini=<?= rawurlencode($ini) ?>&fim=<?= rawurlencode($fim) ?>#conciliar">Ver</a><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php endif; ?>
<?php finance_admin_end(); ?>
