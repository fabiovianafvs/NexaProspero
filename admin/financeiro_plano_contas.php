<?php
declare(strict_types=1);

// NEXA_PATCH_PLANO_CLASSIFICACAO_V1_20260805
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$financeChartCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'finance_chart_accounts');
if ($financeChartCrossContext && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'financeiro_plano')) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para alterar o Plano de Contas.');
}
$financeChartCompanyName = function_exists('multi_company_context_company_row')
  ? trim((string)((multi_company_context_company_row($pdo, $cid)['name'] ?? '')))
  : '';
if ($financeChartCompanyName === '') $financeChartCompanyName = 'Empresa #' . $cid;
$err = '';

function finance_chart_model_normalize_header(string $value): string {
  $value = preg_replace('/^\xEF\xBB\xBF/', '', trim($value)) ?? trim($value);
  $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
  $map = ['á'=>'a','à'=>'a','ã'=>'a','â'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c'];
  $value = strtr($value, $map);
  $value = preg_replace('/[^a-z0-9]+/', '_', $value) ?? $value;
  return trim($value, '_');
}

function finance_chart_model_bool($value, int $default = 1): int {
  $v = function_exists('mb_strtolower') ? mb_strtolower(trim((string)$value), 'UTF-8') : strtolower(trim((string)$value));
  if ($v === '') return $default;
  if (in_array($v, ['1','sim','s','yes','y','true','ativo','ativa','analitica','analítica'], true)) return 1;
  if (in_array($v, ['0','nao','não','n','no','false','inativo','inativa','sintetica','sintética'], true)) return 0;
  return $default;
}

function finance_chart_model_unique_code(PDO $pdo, int $companyId, string $requestedCode, int $currentId = 0): array {
  $base = trim($requestedCode);
  if ($base === '') $base = 'CONTA';
  $candidate = mb_substr($base, 0, 40);
  $n = 1;
  while (true) {
    $row = finance_query_one($pdo, 'SELECT id FROM finance_chart_accounts WHERE company_id=? AND code=? AND id<>? LIMIT 1', [$companyId, $candidate, $currentId]);
    if (!$row) return [$candidate, $candidate !== $base];
    $n++;
    $suffix = '-' . $n;
    $candidate = mb_substr($base, 0, max(1, 40 - mb_strlen($suffix))) . $suffix;
  }
}


// V11.51.7: exclusão segura de Plano de Contas.
// Descobre automaticamente referências tenant-safe em colunas que terminam com chart_account_id,
// incluindo históricos financeiros e configurações/padrões de módulos futuros.
function finance_chart_delete_reference_columns(PDO $pdo): array {
  static $cache = null;
  if (is_array($cache)) return $cache;
  $sql = "SELECT c.TABLE_NAME AS table_name, c.COLUMN_NAME AS column_name
          FROM information_schema.COLUMNS c
          INNER JOIN information_schema.TABLES t
             ON t.TABLE_SCHEMA=c.TABLE_SCHEMA AND t.TABLE_NAME=c.TABLE_NAME AND t.TABLE_TYPE='BASE TABLE'
          INNER JOIN information_schema.COLUMNS cc
             ON cc.TABLE_SCHEMA=c.TABLE_SCHEMA AND cc.TABLE_NAME=c.TABLE_NAME AND cc.COLUMN_NAME='company_id'
          WHERE c.TABLE_SCHEMA=DATABASE()
            AND c.COLUMN_NAME LIKE '%chart_account_id'
          ORDER BY c.TABLE_NAME, c.COLUMN_NAME";
  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $row) {
    $table = (string)($row['table_name'] ?? '');
    $column = (string)($row['column_name'] ?? '');
    if ($table === '' || $column === '') continue;
    // Somente identificadores vindos do information_schema; ainda assim validamos o formato.
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) continue;
    $out[] = ['table' => $table, 'column' => $column];
  }
  return $cache = $out;
}

function finance_chart_delete_usage(PDO $pdo, int $companyId, int $accountId): array {
  $labels = [
    'finance_receivables' => 'Contas a Receber',
    'finance_payables' => 'Contas a Pagar',
    'finance_movements' => 'Movimentos Financeiros',
    'finance_fixed_expenses' => 'Despesas Fixas',
    'finance_payment_methods' => 'Formas de Pagamento',
    'finance_bank_billing_configs' => 'Configurações Bancárias/Boletos',
    'gestao_order_payments' => 'Pagamentos de Pedidos',
    'gestao_cash_operations' => 'Operações de Caixa',
    'contracts' => 'Contratos',
    'fiscal_operations' => 'Operações Fiscais',
  ];
  $items = [];
  $total = 0;
  foreach (finance_chart_delete_reference_columns($pdo) as $ref) {
    $table = $ref['table'];
    $column = $ref['column'];
    $st = $pdo->prepare("SELECT COUNT(*) FROM `{$table}` WHERE company_id=? AND `{$column}`=?");
    $st->execute([$companyId, $accountId]);
    $count = (int)$st->fetchColumn();
    $st->closeCursor();
    if ($count <= 0) continue;
    $total += $count;
    $label = $labels[$table] ?? ucwords(str_replace('_', ' ', $table));
    if ($column !== 'chart_account_id') $label .= ' · ' . str_replace('_', ' ', $column);
    $items[] = ['table' => $table, 'column' => $column, 'label' => $label, 'count' => $count];
  }
  return ['total' => $total, 'items' => $items];
}

function finance_chart_delete_permanently(PDO $pdo, int $companyId, int $sourceId, int $replacementId = 0): array {
  $source = finance_query_one($pdo, 'SELECT id,code,classification,name,is_analytic FROM finance_chart_accounts WHERE company_id=? AND id=? LIMIT 1', [$companyId, $sourceId]);
  if (!$source) throw new RuntimeException('Plano de contas não encontrado nesta empresa.');

  $childRow = finance_query_one($pdo, 'SELECT COUNT(*) AS qtd FROM finance_chart_accounts WHERE company_id=? AND parent_id=?', [$companyId, $sourceId]);
  $children = (int)($childRow['qtd'] ?? 0);
  if ($children > 0) {
    throw new RuntimeException('Este plano possui ' . $children . ' conta(s) filha(s). Exclua ou reorganize primeiro as contas inferiores para preservar a hierarquia.');
  }

  $usage = finance_chart_delete_usage($pdo, $companyId, $sourceId);
  if ((int)$usage['total'] > 0) {
    if ($replacementId <= 0) throw new RuntimeException('Este plano já foi utilizado. Selecione outro plano de contas para receber todos os vínculos antes da exclusão.');
    if ($replacementId === $sourceId) throw new RuntimeException('O plano de destino deve ser diferente do plano que será excluído.');
    $target = finance_query_one($pdo, 'SELECT id,code,name,is_analytic,active FROM finance_chart_accounts WHERE company_id=? AND id=? LIMIT 1', [$companyId, $replacementId]);
    if (!$target || empty($target['active']) || empty($target['is_analytic'])) {
      throw new RuntimeException('Selecione como destino uma conta analítica e ativa da mesma empresa.');
    }
  }

  $pdo->beginTransaction();
  try {
    $moved = 0;
    if ((int)$usage['total'] > 0) {
      foreach (finance_chart_delete_reference_columns($pdo) as $ref) {
        $table = $ref['table'];
        $column = $ref['column'];
        $st = $pdo->prepare("UPDATE `{$table}` SET `{$column}`=? WHERE company_id=? AND `{$column}`=?");
        $st->execute([$replacementId, $companyId, $sourceId]);
        $moved += $st->rowCount();
      }
      $remaining = finance_chart_delete_usage($pdo, $companyId, $sourceId);
      if ((int)$remaining['total'] > 0) {
        throw new RuntimeException('Ainda existem vínculos com o plano antigo. A exclusão foi cancelada para proteger os dados.');
      }
    }

    $del = $pdo->prepare('DELETE FROM finance_chart_accounts WHERE company_id=? AND id=?');
    $del->execute([$companyId, $sourceId]);
    if ($del->rowCount() !== 1) throw new RuntimeException('Não foi possível excluir o plano de contas.');
    finance_chart_rebuild_hierarchy($pdo, $companyId);
    $pdo->commit();
    return ['moved' => $moved, 'usage' => (int)$usage['total']];
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function finance_chart_model_export(PDO $pdo, int $companyId): never {
  finance_chart_rebuild_hierarchy($pdo, $companyId);
  $rows = finance_chart_sort_rows(finance_query_all($pdo, 'SELECT code, classification, name, account_type, movement_type, is_analytic, active, display_order FROM finance_chart_accounts WHERE company_id=?', [$companyId]));
  $filename = 'plano_contas_empresa_' . $companyId . '_' . date('Ymd_His') . '.csv';
  header('Content-Type: text/csv; charset=UTF-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  header('Cache-Control: no-store, no-cache, must-revalidate');
  $out = fopen('php://output', 'wb');
  if ($out === false) throw new RuntimeException('Não foi possível iniciar a exportação.');
  fwrite($out, "\xEF\xBB\xBF");
  fputcsv($out, ['classificacao','codigo','nome','tipo','movimento','analitica','ativa','ordem'], ';', '"', '\\');
  foreach ($rows as $r) {
    fputcsv($out, [
      (string)($r['classification'] ?? ''),
      (string)($r['code'] ?? ''),
      (string)($r['name'] ?? ''),
      (string)($r['account_type'] ?? 'OUTRA'),
      (string)($r['movement_type'] ?? 'AMBOS'),
      (int)($r['is_analytic'] ?? 1),
      (int)($r['active'] ?? 1),
      (int)($r['display_order'] ?? 0),
    ], ';', '"', '\\');
  }
  fclose($out);
  exit;
}

function finance_chart_model_import(PDO $pdo, int $companyId, array $upload): array {
  if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo CSV válido para importar.');
  $tmp = (string)($upload['tmp_name'] ?? '');
  if ($tmp === '' || !is_uploaded_file($tmp)) throw new RuntimeException('O arquivo enviado não pôde ser validado.');
  if ((int)($upload['size'] ?? 0) > 5 * 1024 * 1024) throw new RuntimeException('O arquivo ultrapassa o limite de 5 MB.');

  $fh = fopen($tmp, 'rb');
  if ($fh === false) throw new RuntimeException('Não foi possível abrir o arquivo enviado.');
  $firstLine = fgets($fh);
  if ($firstLine === false) { fclose($fh); throw new RuntimeException('O arquivo está vazio.'); }
  $delimiter = substr_count($firstLine, ';') >= substr_count($firstLine, ',') ? ';' : ',';
  if (substr_count($firstLine, "\t") > max(substr_count($firstLine, ';'), substr_count($firstLine, ','))) $delimiter = "\t";
  rewind($fh);
  $header = fgetcsv($fh, 0, $delimiter, '"', '\\');
  if (!is_array($header)) { fclose($fh); throw new RuntimeException('Não foi possível ler o cabeçalho do arquivo.'); }
  $indexes = [];
  foreach ($header as $i => $h) $indexes[finance_chart_model_normalize_header((string)$h)] = (int)$i;
  $aliases = [
    'classification' => ['classificacao','classification'],
    'code' => ['codigo','code','codigo_interno'],
    'name' => ['nome','name','plano_de_contas','conta'],
    'type' => ['tipo','account_type','tipo_conta'],
    'movement' => ['movimento','movement_type','tipo_movimento'],
    'analytic' => ['analitica','analítico','analitico','is_analytic'],
    'active' => ['ativa','ativo','active'],
    'order' => ['ordem','display_order','ordem_auxiliar'],
  ];
  $col = [];
  foreach ($aliases as $key => $names) {
    foreach ($names as $name) {
      $norm = finance_chart_model_normalize_header($name);
      if (array_key_exists($norm, $indexes)) { $col[$key] = $indexes[$norm]; break; }
    }
  }
  if (!isset($col['classification']) || !isset($col['name'])) {
    fclose($fh);
    throw new RuntimeException('O CSV precisa conter, no mínimo, as colunas classificacao e nome. Use o arquivo gerado por Exportar modelo.');
  }

  $inserted = 0; $updated = 0; $ignored = 0; $renamedCodes = 0; $lineNo = 1;
  $seenClassifications = [];
  $pdo->beginTransaction();
  try {
    while (($row = fgetcsv($fh, 0, $delimiter, '"', '\\')) !== false) {
      $lineNo++;
      $classification = finance_chart_normalize_classification((string)($row[$col['classification']] ?? ''));
      $name = trim((string)($row[$col['name']] ?? ''));
      if ($classification === '' && $name === '') continue;
      if ($classification === '' || $name === '') { $ignored++; continue; }
      if (isset($seenClassifications[$classification])) { $ignored++; continue; }
      $seenClassifications[$classification] = true;

      $requestedCode = trim((string)($row[$col['code'] ?? -1] ?? ''));
      if ($requestedCode === '') $requestedCode = $classification;
      $type = strtoupper(trim((string)($row[$col['type'] ?? -1] ?? 'OUTRA')));
      if (!in_array($type, ['RESULTADO','RECEITA','DESPESA','OUTRA'], true)) $type = 'OUTRA';
      $movement = strtoupper(trim((string)($row[$col['movement'] ?? -1] ?? 'AMBOS')));
      if (!in_array($movement, ['CREDITO','DEBITO','AMBOS'], true)) $movement = 'AMBOS';
      $analytic = finance_chart_model_bool($row[$col['analytic'] ?? -1] ?? 1, 1);
      $active = finance_chart_model_bool($row[$col['active'] ?? -1] ?? 1, 1);
      $order = (int)($row[$col['order'] ?? -1] ?? 0);

      $existing = finance_query_one($pdo, "SELECT id, code FROM finance_chart_accounts WHERE company_id=? AND TRIM(COALESCE(NULLIF(classification,''), code))=? LIMIT 1", [$companyId, $classification]);
      $existingId = (int)($existing['id'] ?? 0);
      if ($existingId > 0) {
        $codeConflict = finance_query_one($pdo, 'SELECT id FROM finance_chart_accounts WHERE company_id=? AND code=? AND id<>? LIMIT 1', [$companyId, $requestedCode, $existingId]);
        if ($codeConflict) {
          $code = (string)($existing['code'] ?? $requestedCode);
          $renamedCodes++;
        } else {
          $code = mb_substr($requestedCode, 0, 40);
        }
      } else {
        [$code, $wasRenamed] = finance_chart_model_unique_code($pdo, $companyId, $requestedCode, 0);
        if ($wasRenamed) $renamedCodes++;
      }
      if ($existingId > 0) {
        $pdo->prepare('UPDATE finance_chart_accounts SET code=?, classification=?, name=?, account_type=?, movement_type=?, is_analytic=?, active=?, display_order=?, updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$code,$classification,$name,$type,$movement,$analytic,$active,$order,$companyId,$existingId]);
        $updated++;
      } else {
        $pdo->prepare('INSERT INTO finance_chart_accounts (company_id,parent_id,code,classification,name,account_type,movement_type,level,is_analytic,active,display_order) VALUES (?,NULL,?,?,?,?,?,1,?,?,?)')
          ->execute([$companyId,$code,$classification,$name,$type,$movement,$analytic,$active,$order]);
        $inserted++;
      }
    }
    fclose($fh);
    $fh = null;
    finance_chart_rebuild_hierarchy($pdo, $companyId);
    $pdo->commit();
  } catch (Throwable $e) {
    if (is_resource($fh)) fclose($fh);
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw new RuntimeException('Falha na linha ' . $lineNo . ': ' . $e->getMessage(), 0, $e);
  }
  return compact('inserted','updated','ignored','renamedCodes');
}

if (($_GET['action'] ?? '') === 'export_model') finance_chart_model_export($pdo, $cid);
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? 'save');
    if ($action === 'import_model') {
      $result = finance_chart_model_import($pdo, $cid, (array)($_FILES['chart_model'] ?? []));
      $message = 'Modelo importado: ' . (int)$result['inserted'] . ' conta(s) incluída(s), ' . (int)$result['updated'] . ' atualizada(s)';
      if ((int)$result['ignored'] > 0) $message .= ', ' . (int)$result['ignored'] . ' linha(s) ignorada(s)';
      if ((int)$result['renamedCodes'] > 0) $message .= ', ' . (int)$result['renamedCodes'] . ' código(s) interno(s) ajustado(s) por conflito';
      $message .= '. A estrutura hierárquica foi recalculada.';
      finance_redirect('financeiro_plano_contas.php?ok=' . rawurlencode($message));
    }
    if ($action === 'hard_delete') {
      $id = (int)($_POST['id'] ?? 0);
      $replacementId = (int)($_POST['replacement_id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Plano de contas inválido.');
      $result = finance_chart_delete_permanently($pdo, $cid, $id, $replacementId);
      $message = (int)$result['usage'] > 0
        ? 'Plano excluído. ' . (int)$result['moved'] . ' vínculo(s) foram transferidos para o plano selecionado.'
        : 'Plano de contas excluído definitivamente.';
      finance_redirect('financeiro_plano_contas.php?ok=' . rawurlencode($message));
    }
    if ($action === 'delete') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id > 0) $pdo->prepare('UPDATE finance_chart_accounts SET active=0, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$cid,$id]);
      finance_redirect('financeiro_plano_contas.php?ok=' . rawurlencode('Conta inativada.'));
    }
    $id = (int)($_POST['id'] ?? 0);
    $parentId = (int)($_POST['parent_id'] ?? 0) ?: null;
    $code = trim((string)($_POST['code'] ?? ''));
    $classification = finance_chart_normalize_classification((string)($_POST['classification'] ?? ''));
    $name = trim((string)($_POST['name'] ?? ''));
    if ($code === '' || $classification === '' || $name === '') throw new RuntimeException('Informe código, classificação e nome.');
    $dup = finance_query_one($pdo, "SELECT id FROM finance_chart_accounts WHERE company_id=? AND id<>? AND TRIM(COALESCE(NULLIF(classification,''), code))=? LIMIT 1", [$cid, $id, $classification]);
    if ($dup) throw new RuntimeException('Já existe uma conta com esta classificação.');
    $type = in_array((string)($_POST['account_type'] ?? ''), ['RESULTADO','RECEITA','DESPESA','OUTRA'], true) ? (string)$_POST['account_type'] : 'OUTRA';
    $movement = in_array((string)($_POST['movement_type'] ?? ''), ['CREDITO','DEBITO','AMBOS'], true) ? (string)$_POST['movement_type'] : 'AMBOS';
    $analytic = isset($_POST['is_analytic']) ? 1 : 0;
    $active = isset($_POST['active']) ? 1 : 0;
    $order = (int)($_POST['display_order'] ?? 0);
    $inferredParentId = finance_chart_find_parent_id_by_classification($pdo, $cid, $classification, $id);
    if ($inferredParentId > 0) $parentId = $inferredParentId;
    if ($id > 0 && $parentId === $id) throw new RuntimeException('A conta pai não pode ser a própria conta.');
    if ($id > 0 && $parentId) {
      $cursor = $parentId;
      $guard = 0;
      while ($cursor > 0 && $guard++ < 100) {
        if ($cursor === $id) throw new RuntimeException('A conta pai escolhida cria um ciclo na estrutura.');
        $rowParent = finance_query_one($pdo, 'SELECT parent_id FROM finance_chart_accounts WHERE company_id=? AND id=?', [$cid, $cursor]);
        $cursor = (int)($rowParent['parent_id'] ?? 0);
      }
    }
    $level = 1;
    if ($parentId) {
      $p = finance_query_one($pdo, 'SELECT level FROM finance_chart_accounts WHERE company_id=? AND id=?', [$cid, $parentId]);
      $level = $p ? ((int)$p['level'] + 1) : 1;
    }
    if ($id > 0) {
      $pdo->prepare('UPDATE finance_chart_accounts SET parent_id=?, code=?, classification=?, name=?, account_type=?, movement_type=?, level=?, is_analytic=?, active=?, display_order=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$parentId,$code,$classification,$name,$type,$movement,$level,$analytic,$active,$order,$cid,$id]);
    } else {
      $pdo->prepare('INSERT INTO finance_chart_accounts (company_id,parent_id,code,classification,name,account_type,movement_type,level,is_analytic,active,display_order) VALUES (?,?,?,?,?,?,?,?,?,?,?)')->execute([$cid,$parentId,$code,$classification,$name,$type,$movement,$level,$analytic,$active,$order]);
    }
    finance_chart_rebuild_hierarchy($pdo, $cid);
    finance_redirect('financeiro_plano_contas.php?ok=' . rawurlencode('Plano de contas salvo e hierarquia recalculada.'));
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$editId = (int)($_GET['edit'] ?? 0);
$postAction = (string)($_POST['action'] ?? '');
$showImport = isset($_GET['import']) || ($err !== '' && $postAction === 'import_model');
$showForm = isset($_GET['new']) || $editId > 0 || ($err !== '' && $_SERVER['REQUEST_METHOD'] === 'POST' && !in_array($postAction, ['import_model','hard_delete'], true));
$edit = $editId ? finance_query_one($pdo, 'SELECT * FROM finance_chart_accounts WHERE company_id=? AND id=?', [$cid,$editId]) : null;
if (!$edit && $_SERVER['REQUEST_METHOD'] === 'POST' && $err !== '' && $showForm) $edit = $_POST;

$deleteId = (int)($_GET['delete'] ?? (($postAction === 'hard_delete' && $err !== '') ? ($_POST['id'] ?? 0) : 0));
$deleteAccount = $deleteId > 0 ? finance_query_one($pdo, 'SELECT * FROM finance_chart_accounts WHERE company_id=? AND id=?', [$cid, $deleteId]) : null;
$deleteUsage = $deleteAccount ? finance_chart_delete_usage($pdo, $cid, $deleteId) : ['total'=>0,'items'=>[]];
$deleteChildRow = $deleteAccount ? finance_query_one($pdo, 'SELECT COUNT(*) AS qtd FROM finance_chart_accounts WHERE company_id=? AND parent_id=?', [$cid, $deleteId]) : null;
$deleteChildren = (int)($deleteChildRow['qtd'] ?? 0);
$deleteTargets = $deleteAccount ? finance_chart_sort_rows(finance_query_all($pdo, 'SELECT * FROM finance_chart_accounts WHERE company_id=? AND id<>? AND active=1 AND is_analytic=1', [$cid, $deleteId])) : [];
$q = trim((string)($_GET['q'] ?? ''));
$typeFilter = trim((string)($_GET['type'] ?? ''));
$where = ['company_id=?', 'active=1']; $params = [$cid];
if ($q !== '') { $where[] = '(code LIKE ? OR classification LIKE ? OR name LIKE ?)'; $like=nexa_search_like($q); array_push($params,$like,$like,$like); }
if (in_array($typeFilter, ['RESULTADO','RECEITA','DESPESA','OUTRA'], true)) { $where[] = 'account_type=?'; $params[] = $typeFilter; }
finance_chart_rebuild_hierarchy($pdo, $cid);
$accounts = finance_chart_sort_rows(finance_query_all($pdo, 'SELECT * FROM finance_chart_accounts WHERE ' . implode(' AND ', $where), $params));
$allAccounts = finance_chart_sort_rows(finance_query_all($pdo, 'SELECT * FROM finance_chart_accounts WHERE company_id=? AND active=1', [$cid]));

finance_admin_start('Financeiro - Plano de Contas', 'fin_plano');
finance_page_header('Plano de Contas', 'Organize pela classificação hierárquica. Contas sintéticas agrupam todos os níveis inferiores e contas analíticas recebem os lançamentos.', [
  ['href' => 'financeiro_plano_contas.php?action=export_model', 'label' => 'Exportar modelo', 'class' => 'btn'],
  ['href' => 'financeiro_plano_contas.php?import=1', 'label' => 'Importar modelo', 'class' => 'btn'],
  ['href' => 'financeiro_plano_contas.php?new=1', 'label' => '+ Nova conta', 'class' => 'btn btn-primary'],
]);
// V11.51.6: remove as abas gerais apenas desta tela para priorizar a estrutura do plano.
if ($financeChartCrossContext): ?><div class="finance-card" style="border-color:#86efac;background:#f0fdf4"><strong style="color:#166534">V11.4 · Plano de Contas Multiempresa</strong><div class="finance-muted" style="margin-top:5px;color:#166534">Inclusões, alterações, importação, exportação e inativação nesta aba utilizam somente a empresa selecionada pelo F11. A empresa de entrada não é alterada.</div></div><?php endif; ?><?php finance_flash(); ?>
<style>.finance-filter-card{padding-top:10px!important;padding-bottom:10px!important}.finance-filter-card .finance-filter{gap:8px 10px}.finance-filter-card .finance-filter label{margin-bottom:3px}.finance-filter-card .finance-filter input,.finance-filter-card .finance-filter select{min-height:39px}.finance-filter-card .finance-filter-actions .btn{min-height:39px}</style>
<?php if ($err): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>

<div class="finance-card finance-filter-card"><form method="get" class="finance-filter">
  <div class="wide"><label>Pesquisar</label><input name="q" value="<?= finance_h($q) ?>" placeholder="Classificação, código ou nome"></div>
  <div><label>Tipo</label><select name="type"><option value="">Todos</option><?php foreach(['RESULTADO'=>'Resultado','RECEITA'=>'Receita','DESPESA'=>'Despesa','OUTRA'=>'Outra'] as $k=>$v): ?><option value="<?= $k ?>" <?= $typeFilter===$k?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
  <div class="finance-filter-actions"><button class="btn">Filtrar</button><a class="btn" href="financeiro_plano_contas.php">Limpar</a></div>
</form></div>

<div class="finance-card"><div class="finance-card-head"><h2 class="finance-subtitle">Estrutura atual</h2><span class="finance-muted"><?= count($accounts) ?> conta(s)</span></div>
  <div class="table-wrap"><table class="finance-list-table">
    <thead><tr><th>Classificação</th><th>Nome</th><th>Código interno</th><th>Tipo</th><th>Movimento</th><th>Uso</th><th>Ações</th></tr></thead><tbody>
    <?php if (!$accounts): ?><tr><td colspan="7" class="finance-empty">Nenhuma conta encontrada.</td></tr><?php endif; ?>
    <?php foreach ($accounts as $a): ?>
    <tr class="finance-tree-row <?= (int)$a['is_analytic'] ? '' : 'synthetic' ?>">
      <td data-label="Classificação" class="code"><?= finance_h($a['classification'] ?: $a['code']) ?></td>
      <td data-label="Nome" class="finance-primary"><?= finance_tree_indent((int)$a['level']) ?><?= finance_h($a['name']) ?></td>
      <td data-label="Código interno" class="code"><?= finance_h($a['code']) ?></td>
      <td data-label="Tipo"><?= finance_h($a['account_type']) ?></td>
      <td data-label="Movimento"><?= finance_h($a['movement_type']) ?></td>
      <td data-label="Uso"><?= (int)$a['is_analytic'] ? '<span class="finance-badge green">Analítica</span>' : '<span class="finance-badge gray">Sintética</span>' ?></td>
      <td data-label="Ações"><div class="finance-inline-actions"><a class="btn" href="?edit=<?= (int)$a['id'] ?>">Editar</a><form method="post" onsubmit="return confirm('Inativar esta conta?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$a['id'] ?>"><button class="btn">Inativar</button></form><a class="btn btn-danger" href="?delete=<?= (int)$a['id'] ?>">Excluir</a></div></td>
    </tr>
    <?php endforeach; ?>
    </tbody>
  </table></div>
</div>

<?php if ($deleteAccount): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog" style="width:min(760px,100%);">
  <div class="finance-modal-head"><div><h2>Excluir plano de contas</h2><p class="finance-muted">A exclusão é definitiva. Quando houver uso, transfira primeiro todos os vínculos para outro plano.</p></div><a class="finance-modal-close" href="financeiro_plano_contas.php">×</a></div>
  <?php if ($postAction === 'hard_delete' && $err !== ''): ?><div class="msg err"><?= finance_h($err) ?></div><?php endif; ?>
  <div class="finance-form">
    <div class="finance-form-section">
      <h3><?= finance_h((string)($deleteAccount['classification'] ?: $deleteAccount['code'])) ?> · <?= finance_h((string)$deleteAccount['name']) ?></h3>
      <?php if ($deleteChildren > 0): ?>
        <div class="msg err">Este plano possui <strong><?= $deleteChildren ?></strong> conta(s) filha(s). Para preservar a hierarquia, reorganize ou exclua primeiro as contas inferiores.</div>
      <?php elseif ((int)$deleteUsage['total'] === 0): ?>
        <div style="border:1px solid #bbf7d0;background:#ecfdf5;color:#166534;border-radius:14px;padding:12px 14px;line-height:1.45;"><strong>Plano nunca utilizado.</strong><br>Não foram encontrados lançamentos nem configurações vinculadas. A exclusão pode ser feita diretamente.</div>
      <?php else: ?>
        <div style="border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;border-radius:14px;padding:12px 14px;line-height:1.45;"><strong>Este plano já foi utilizado em <?= (int)$deleteUsage['total'] ?> vínculo(s).</strong><br>Escolha abaixo outro plano analítico. O Nexa transferirá todos os registros e configurações da empresa atual para o destino e somente depois excluirá o plano antigo.</div>
        <div style="margin-top:10px;display:flex;gap:6px;flex-wrap:wrap;">
          <?php foreach ($deleteUsage['items'] as $usageItem): ?><span class="finance-badge gray"><?= finance_h((string)$usageItem['label']) ?>: <?= (int)$usageItem['count'] ?></span><?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>
    <?php if ($deleteChildren === 0): ?>
    <form method="post" class="finance-form-section" onsubmit="return confirm('Confirma a exclusão definitiva deste plano de contas? Esta operação não poderá ser desfeita.');">
      <input type="hidden" name="action" value="hard_delete"><input type="hidden" name="id" value="<?= (int)$deleteAccount['id'] ?>">
      <?php if ((int)$deleteUsage['total'] > 0): ?>
        <div class="finance-form-grid"><div class="span-2"><label>Transferir todos os vínculos para *</label><select name="replacement_id" required><option value="">Selecione o plano de destino</option><?php foreach ($deleteTargets as $target): ?><option value="<?= (int)$target['id'] ?>" <?= (int)($_POST['replacement_id'] ?? 0)===(int)$target['id']?'selected':'' ?>><?= finance_h(trim((string)($target['classification'] ?: $target['code']) . ' - ' . (string)$target['name'])) ?></option><?php endforeach; ?></select><span class="hint">Somente contas analíticas e ativas da mesma empresa são aceitas.</span></div></div>
      <?php endif; ?>
      <div class="finance-form-actions"><a class="btn" href="financeiro_plano_contas.php">Cancelar</a><button class="btn btn-danger" type="submit"><?= (int)$deleteUsage['total'] > 0 ? 'Transferir e excluir' : 'Excluir definitivamente' ?></button></div>
    </form>
    <?php else: ?>
      <div class="finance-form-actions"><a class="btn" href="financeiro_plano_contas.php">Fechar</a></div>
    <?php endif; ?>
  </div>
</div></div>
<?php endif; ?>

<?php if ($showImport): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog" style="width:min(720px,100%);">
  <div class="finance-modal-head"><div><h2>Importar modelo do plano de contas</h2><p class="finance-muted">Use um CSV gerado pelo botão Exportar modelo em outra empresa.</p></div><a class="finance-modal-close" href="financeiro_plano_contas.php">×</a></div>
  <form method="post" enctype="multipart/form-data" class="finance-form">
    <input type="hidden" name="action" value="import_model">
    <div class="finance-form-section"><h3>Arquivo do modelo</h3>
      <div class="finance-form-grid">
        <div class="span-2"><label>Arquivo CSV *</label><input type="file" name="chart_model" accept=".csv,text/csv" required><span class="hint">Limite de 5 MB. O sistema identifica arquivos separados por ponto e vírgula, vírgula ou tabulação.</span></div>
      </div>
      <div style="margin-top:14px;border:1px solid #dbe4f0;background:#f8fbff;border-radius:16px;padding:13px;color:#334155;line-height:1.45;">
        <strong>Como a importação funciona</strong><br>
        Contas com a mesma classificação são atualizadas e as classificações inexistentes são incluídas. Nenhuma conta que já existe na empresa de destino é excluída. Os IDs internos não são copiados, preservando os vínculos financeiros da empresa. Ao final, contas pai e níveis são reconstruídos pela classificação.
      </div>
    </div>
    <div class="finance-form-actions"><a class="btn" href="financeiro_plano_contas.php">Cancelar</a><button class="btn btn-primary" type="submit">Importar modelo</button></div>
  </form>
</div></div>
<?php endif; ?>

<?php if ($showForm): ?>
<div class="finance-modal" role="dialog" aria-modal="true"><div class="finance-modal-dialog">
  <div class="finance-modal-head"><div><h2><?= $editId ? 'Editar conta' : 'Nova conta' ?></h2><p class="finance-muted">Somente contas analíticas aparecem nos lançamentos financeiros.</p></div><a class="finance-modal-close" href="financeiro_plano_contas.php">×</a></div>
  <form method="post" class="finance-form"><input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
    <div class="finance-form-section"><h3>Dados da conta</h3><div class="finance-form-grid three">
      <div><label>Classificação *</label><input name="classification" value="<?= finance_h($edit['classification'] ?? $edit['code'] ?? '') ?>" placeholder="Ex.: 1.02.01.001" required></div>
      <div><label>Código interno *</label><input name="code" value="<?= finance_h($edit['code'] ?? '') ?>" placeholder="Ex.: 000125" required></div>
      <div><label>Ordem auxiliar</label><input name="display_order" type="number" value="<?= (int)($edit['display_order'] ?? 0) ?>"><span class="hint">Usada somente quando duas contas têm a mesma classificação.</span></div>
      <div class="span-3"><label>Nome *</label><input name="name" value="<?= finance_h($edit['name'] ?? '') ?>" required></div>
      <div class="span-3"><label>Conta pai</label><select name="parent_id"><?php finance_select_options(array_filter($allAccounts, fn($a) => (int)$a['id'] !== (int)($edit['id'] ?? 0) && ((int)($a['is_analytic'] ?? 1) === 0 || (int)$a['id'] === (int)($edit['parent_id'] ?? 0))), $edit['parent_id'] ?? '', 'Automática pela classificação / sem conta pai', false); ?></select><span class="hint">Quando existir a classificação superior, ela será usada automaticamente. Ex.: 1.02.01.001 será vinculada a 1.02.01.</span></div>
      <div><label>Tipo</label><select name="account_type"><?php foreach(['RESULTADO'=>'Resultado','RECEITA'=>'Receita','DESPESA'=>'Despesa','OUTRA'=>'Outra'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($edit['account_type'] ?? 'OUTRA')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <div><label>Movimento</label><select name="movement_type"><?php foreach(['AMBOS'=>'Ambos','CREDITO'=>'Crédito','DEBITO'=>'Débito'] as $k=>$v): ?><option value="<?= $k ?>" <?= (($edit['movement_type'] ?? 'AMBOS')===$k)?'selected':'' ?>><?= $v ?></option><?php endforeach; ?></select></div>
      <label class="checkline"><input type="checkbox" name="is_analytic" <?= !isset($edit['is_analytic']) || (int)$edit['is_analytic']===1 ? 'checked' : '' ?>> Conta analítica/lancável</label>
      <label class="checkline"><input type="checkbox" name="active" <?= !isset($edit['active']) || (int)$edit['active']===1 ? 'checked' : '' ?>> Ativa</label>
    </div></div>
    <div class="finance-form-actions"><a class="btn" href="financeiro_plano_contas.php">Cancelar</a><button class="btn btn-primary">Salvar conta</button></div>
  </form>
</div></div>
<?php endif; ?>
<?php finance_admin_end(); ?>
