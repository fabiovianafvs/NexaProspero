<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/fiscal_order_composition.php';
require_once __DIR__ . '/../config/os_pedidos_bridge.php';
require_once __DIR__ . '/../config/nfse_emitter.php';
require_once __DIR__ . '/../config/screen_performance.php';

$ordersCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_orders_read');
// V11.25.1: no acesso direto, ancora explicitamente a Central no tenant autenticado.
// Somente um contexto F11 realmente cruzado pode trocar a empresa operacional.
$ordersCompanyId = $ordersCrossContext && function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_orders_read')
  : (int)COMPANY_ID;
$ordersMcCtx = function_exists('multi_company_context_request_token') ? multi_company_context_request_token() : '';
$ordersMcCsrf = $ordersCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';

// Novo grid é o padrão; a empresa pode voltar ao antigo em Configurações gerais
// ("Utilizar grid antigo na Consulta de Pedidos"). Sem DDL na consulta.
$ordersNewGrid = true;
try {
  $gridFlagSt = $pdo->prepare("SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key='business' LIMIT 1");
  $gridFlagSt->execute([$ordersCompanyId]);
  $gridBusiness = json_decode((string)($gridFlagSt->fetchColumn() ?: ''), true);
  $ordersNewGrid = !(is_array($gridBusiness) && !empty($gridBusiness['orders_grid']['legacy']));
} catch (Throwable $ignored) { $ordersNewGrid = true; }

// Preferências por usuário e empresa, mesmo modelo de persistência do Cadastro de Produtos.
$ordersGridUserId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
$ordersGridPrefsKey = $ordersGridUserId > 0 ? 'ui_orders_grid_user_' . $ordersGridUserId : '';
if (empty($_SESSION['orders_grid_csrf']) || !is_string($_SESSION['orders_grid_csrf'])) {
  $_SESSION['orders_grid_csrf'] = bin2hex(random_bytes(24));
}
$ordersGridCsrf = (string)$_SESSION['orders_grid_csrf'];
$ordersGridAllowedCols = ['numero','cliente','total','entrega','pagamento','status','data','prevista','fiscal','acoes'];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_orders_grid_preferences') {
  header('Content-Type: application/json; charset=utf-8');
  try {
    if (!$ordersNewGrid || $ordersCrossContext || $ordersGridPrefsKey === '') throw new RuntimeException('Operação indisponível.');
    if (!hash_equals($ordersGridCsrf, (string)($_POST['csrf_token'] ?? ''))) throw new RuntimeException('Sessão expirada.');
    $raw = (string)($_POST['preferences'] ?? '');
    if (strlen($raw) > 5000) throw new RuntimeException('Preferências excedem o limite.');
    $input = json_decode($raw, true);
    if (!is_array($input)) throw new RuntimeException('Preferências inválidas.');
    $prefs = ['visible'=>[], 'order'=>[], 'widths'=>[]];
    foreach (['visible','order'] as $part) {
      if (!isset($input[$part]) || !is_array($input[$part])) continue;
      foreach ($input[$part] as $col) {
        if (is_string($col) && in_array($col, $ordersGridAllowedCols, true) && $col !== 'acoes' && !in_array($col, $prefs[$part], true)) $prefs[$part][] = $col;
      }
    }
    foreach ((array)($input['widths'] ?? []) as $col => $width) {
      if (in_array((string)$col, $ordersGridAllowedCols, true) && $col !== 'acoes' && is_numeric($width)) $prefs['widths'][$col] = max(70, min(650, (int)$width));
    }
    $st = $pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');
    $st->execute([$ordersCompanyId, $ordersGridPrefsKey, json_encode($prefs, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    echo json_encode(['ok'=>true]);
  } catch (Throwable $e) {
    http_response_code(422);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  }
  exit;
}
$ordersGridSavedPrefs = [];
if ($ordersNewGrid && $ordersGridPrefsKey !== '') {
  try {
    $st = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
    $st->execute([$ordersCompanyId, $ordersGridPrefsKey]);
    $prefs = json_decode((string)($st->fetchColumn() ?: ''), true);
    if (is_array($prefs)) $ordersGridSavedPrefs = $prefs;
  } catch (Throwable $ignored) {}
}

$nfseQuickCsrfPedidos = nfse_emitter_csrf_token('nfse_emitter');
if ($ordersCrossContext) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
$ordersCompanyName = '';
try {
  $stCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $stCompany->execute([$ordersCompanyId]);
  $ordersCompanyName = trim((string)($stCompany->fetchColumn() ?: ''));
} catch (Throwable $e) {}
if ($ordersCrossContext && function_exists('multi_company_context_permission_allowed')) {
  $canViewOrders = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canViewOrders) {
    http_response_code(403);
    admin_topbar('Central - Pedidos', 'pedidos');
    echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para consultar pedidos na empresa selecionada pelo F11.</div>';
    admin_footer();
    exit;
  }
}

// V11.24: consulta cruzada não executa DDL/backfill automático.
if (!$ordersCrossContext) company_sequence_ensure_schema($pdo, true);

$showKitchenButton = false;
try {
  $showKitchenButton = function_exists('saas_company_has_module')
    && saas_company_has_module($pdo, $ordersCompanyId, 'comandas');
} catch (Throwable $e) {
  $showKitchenButton = false;
}

$showFiscalNfceButton = false;
$canEmitNfcePedidos = false;
$canEmitNfePedidos = false;
$canEmitNfsePedidos = false;
try {
  if (function_exists('saas_company_has_module') && saas_company_has_module($pdo, $ordersCompanyId, 'fiscal')) {
    if (!$ordersCrossContext && function_exists('fiscal_nfce_ensure_schema')) fiscal_nfce_ensure_schema($pdo);
    $fiscalCfgPedidos = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $ordersCompanyId) : [];
    $fiscalEmissionEnabledPedidos = !empty($fiscalCfgPedidos['fiscal_emission_enabled']);
    if ($ordersCrossContext) {
      // V11.46: Central de Pedidos pode emitir/reemitir NFC-e e NF-e somente em HOMOLOGAÇÃO.
      $fiscalDocPermission = function_exists('multi_company_context_permission_allowed')
        && multi_company_context_permission_allowed($pdo, 'fiscal_documentos');
      $homologation = strtoupper(trim((string)($fiscalCfgPedidos['environment'] ?? ''))) === 'HOMOLOGACAO';
      $canEmitNfcePedidos = $fiscalDocPermission && $homologation && $fiscalEmissionEnabledPedidos && !empty($fiscalCfgPedidos['nfce_enabled']);
      $canEmitNfePedidos = $fiscalDocPermission && $homologation && $fiscalEmissionEnabledPedidos && !empty($fiscalCfgPedidos['nfe_enabled']);
      $canEmitNfsePedidos = false;
    } else {
      $canEmitNfcePedidos = $fiscalEmissionEnabledPedidos && !empty($fiscalCfgPedidos['nfce_enabled']);
      $canEmitNfePedidos = $fiscalEmissionEnabledPedidos && !empty($fiscalCfgPedidos['nfe_enabled']);
      $canEmitNfsePedidos = $fiscalEmissionEnabledPedidos && !empty($fiscalCfgPedidos['nfse_enabled']) && !empty($fiscalCfgPedidos['nfse_national_standard']);
    }
    $showFiscalNfceButton = $canEmitNfcePedidos || $canEmitNfePedidos || $canEmitNfsePedidos;
  }
} catch (Throwable $e) {
  $showFiscalNfceButton = false;
}

if (!function_exists('pedidos_h')) {
  function pedidos_h($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('pedidos_nfse_quick_control')) {
  function pedidos_nfse_quick_control(int $pedidoId, ?array $doc, string $csrf, bool $crossContext, string $mcCsrf, string $mcCtx, string $ordersReturn = 'pedidos.php'): string {
    $docStatus = $doc ? strtoupper(trim((string)($doc['nfse_status'] ?? $doc['status'] ?? ''))) : '';
    if ($doc && in_array($docStatus, ['AUTORIZADA','CANCELADA'], true)) {
      $url = 'fiscal_nfse_nacional.php?pedido_id=' . $pedidoId . '&return_to=' . rawurlencode($ordersReturn);
      if ($crossContext && function_exists('multi_company_context_url')) $url = multi_company_context_url($url);
      return '<a class="btn btn-sm btn-primary" href="' . pedidos_h($url) . '">Abrir NFS-e</a>';
    }
    $action = $crossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_acao.php') : 'fiscal_nfse_acao.php';
    $returnTo = 'fiscal_nfse_nacional.php?pedido_id=' . $pedidoId . '&return_to=' . rawurlencode($ordersReturn);
    if ($crossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
    $html = '<form method="post" action="' . pedidos_h($action) . '" style="display:inline">';
    $html .= '<input type="hidden" name="csrf_token" value="' . pedidos_h($csrf) . '">';
    $html .= '<input type="hidden" name="action" value="emit_quick"><input type="hidden" name="pedido_id" value="' . $pedidoId . '">';
    $html .= '<input type="hidden" name="return_to" value="' . pedidos_h($returnTo) . '">';
    if ($crossContext) {
      $html .= '<input type="hidden" name="mc_csrf" value="' . pedidos_h($mcCsrf) . '"><input type="hidden" name="mc_ctx" value="' . pedidos_h($mcCtx) . '">';
    }
    $html .= '<button class="btn btn-sm btn-primary" type="submit">' . ($doc ? 'Reemitir NFS-e' : 'Emitir NFS-e') . '</button></form>';
    return $html;
  }
}

if (!function_exists('pedidos_money_br')) {
  function pedidos_money_br($value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
  }
}

if (!function_exists('pedidos_status_badge_class')) {
  function pedidos_status_badge_class(string $status): string {
    $status = trim($status) !== '' ? trim($status) : 'aberto';
    return 'status-pill status-' . preg_replace('/[^a-z0-9_\-]/i', '', $status);
  }
}

if (!function_exists('pedidos_nfce_status_label')) {
  function pedidos_nfce_status_label(string $status): string {
    $status = strtoupper(trim($status));
    if ($status === '' || $status === 'PENDENTE') return 'Pendente';
    if ($status === 'PRE_NFCE' || $status === 'PRE_NFE') return 'Pré';
    if ($status === 'AUTORIZADA') return 'Autorizada';
    if ($status === 'REJEITADA') return 'Rejeitada';
    if ($status === 'ERRO_AUTORIZACAO') return 'Erro';
    if ($status === 'DUPLICIDADE') return 'Duplicidade';
    if ($status === 'CANCELADA') return 'Cancelada';
    return str_replace('_', ' ', $status);
  }
}

if (!function_exists('pedidos_nfce_badge')) {
  function pedidos_nfce_badge(?array $doc, string $docLabel = 'NFC-e'): string {
    if (!$doc) return '<span class="pedidos-nfce-badge off" title="Documento fiscal ainda não gerado">Fiscal pendente</span>';
    $status = strtoupper(trim((string)($doc['status'] ?? '')));
    $label = pedidos_nfce_status_label($status !== '' ? $status : 'PRE_NFCE');
    $class = 'pedidos-nfce-badge';
    if ($status === 'AUTORIZADA') $class .= ' ok';
    elseif (in_array($status, ['REJEITADA','ERRO_AUTORIZACAO','DUPLICIDADE'], true)) $class .= ' warn';
    else $class .= ' wait';
    $title = trim((string)($doc['sefaz_status_code'] ?? '') . ' ' . (string)($doc['sefaz_status_message'] ?? ''));
    if ($title === '') $title = 'Status fiscal ' . $docLabel . ': ' . $label;
    return '<span class="' . pedidos_h($class) . '" title="' . pedidos_h($title) . '">' . pedidos_h($docLabel . ': ' . $label) . '</span>';
  }
}


if (!function_exists('pedidos_nfse_badge')) {
  function pedidos_nfse_badge(?array $doc, bool $enabled): string {
    if (!$doc) return $enabled ? '<span class="pedidos-nfce-badge off" title="NFS-e ainda não preparada">NFS-e: Pendente</span>' : '';
    $status = strtoupper(trim((string)($doc['nfse_status'] ?? $doc['status'] ?? 'PREPARADA')));
    $labels = ['PREPARADA'=>'Preparada','ASSINADA'=>'Assinada','TRANSMITINDO'=>'Transmitindo','AUTORIZADA'=>'Autorizada','REJEITADA'=>'Rejeitada','ERRO_COMUNICACAO'=>'Comunicação incerta','CANCELADA'=>'Cancelada'];
    $label = $labels[$status] ?? str_replace('_',' ',$status);
    $class = 'pedidos-nfce-badge ' . ($status === 'AUTORIZADA' ? 'ok' : (in_array($status,['REJEITADA','ERRO_COMUNICACAO'],true) ? 'warn' : 'wait'));
    $title = trim((string)($doc['api_error_message'] ?? '')) ?: ('Status NFS-e: ' . $label);
    return '<span class="' . pedidos_h($class) . '" title="' . pedidos_h($title) . '">NFS-e: ' . pedidos_h($label) . '</span>';
  }
}

if (!function_exists('pedidos_fiscal_extra_badge')) {
  function pedidos_fiscal_extra_badge(array $info, string $defaultLabel, string $prefix = ''): string {
    $label = trim((string)($info['label'] ?? $defaultLabel));
    if ($prefix !== '' && $label !== '') $label = $prefix . ': ' . $label;
    $kind = preg_replace('/[^a-z0-9_\-]/i', '', (string)($info['kind'] ?? 'wait'));
    if ($kind === '') $kind = 'wait';
    $title = trim((string)($info['message'] ?? ''));
    if ($title === '') {
      $details = [];
      if (!empty($info['nfe_series']) || !empty($info['nfe_number'])) $details[] = 'NF-e ' . trim((string)($info['nfe_series'] ?? '') . '/' . (string)($info['nfe_number'] ?? ''));
      if (!empty($info['returned_total']) || !empty($info['original_total'])) $details[] = 'Devolvido R$ ' . number_format((float)($info['returned_total'] ?? 0), 2, ',', '.') . ' de R$ ' . number_format((float)($info['original_total'] ?? 0), 2, ',', '.');
      $title = $details ? implode(' · ', $details) : $label;
    }
    return '<span class="pedidos-nfce-badge ' . pedidos_h($kind) . '" title="' . pedidos_h($title) . '">' . pedidos_h($label) . '</span>';
  }
}

if (!function_exists('pedidos_fiscal_badges')) {
  function pedidos_fiscal_badges(?array $nfceDoc, ?array $nfeDoc, bool $canNfce, bool $canNfe, ?array $refInfo = null, ?array $returnInfo = null): string {
    $out = [];
    if ($nfceDoc) $out[] = pedidos_nfce_badge($nfceDoc, 'NFC-e');
    if ($nfeDoc) $out[] = pedidos_nfce_badge($nfeDoc, 'NF-e');
    if ($refInfo) $out[] = pedidos_fiscal_extra_badge($refInfo, 'Referenciada', 'NFC-e');
    if ($returnInfo) $out[] = pedidos_fiscal_extra_badge($returnInfo, 'Devolução');
    if (!$out && ($canNfce || $canNfe)) {
      $label = ($canNfce && $canNfe) ? 'NF-e/NFC-e: Pendente' : ($canNfe ? 'NF-e: Pendente' : 'NFC-e: Pendente');
      $out[] = '<span class="pedidos-nfce-badge off" title="Documento fiscal ainda não gerado">' . pedidos_h($label) . '</span>';
    }
    return implode('<br>', $out);
  }
}

$fQ = trim((string)($_GET['q'] ?? ''));
if (function_exists('mb_substr')) {
  $fQ = mb_substr($fQ, 0, 80, 'UTF-8');
} else {
  $fQ = substr($fQ, 0, 80);
}
$fStatus = trim((string)($_GET['status'] ?? ''));
$ordersPeriodCfg = nexa_screen_performance_get($pdo, $ordersCompanyId);
$ordersInitialPeriod = nexa_screen_performance_period((int)($ordersPeriodCfg['orders_days'] ?? 10));
$ordersHasDateRequest = array_key_exists('dt_ini', $_GET) || array_key_exists('dt_fim', $_GET);
$fIni = $ordersHasDateRequest ? trim((string)($_GET['dt_ini'] ?? '')) : $ordersInitialPeriod['from'];
$fFim = $ordersHasDateRequest ? trim((string)($_GET['dt_fim'] ?? '')) : $ordersInitialPeriod['to'];
$fPrevIni = trim((string)($_GET['prev_ini'] ?? ''));
$fPrevFim = trim((string)($_GET['prev_fim'] ?? ''));

$hasScheduledDate = false;
$hasScheduledTime = false;
$hasSourceColumn = false;
try {
  $colSt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND COLUMN_NAME IN ('scheduled_delivery_date','scheduled_delivery_time','source')");
  $colSt->execute();
  foreach (($colSt->fetchAll(PDO::FETCH_COLUMN) ?: []) as $colName) {
    if ($colName === 'scheduled_delivery_date') $hasScheduledDate = true;
    if ($colName === 'scheduled_delivery_time') $hasScheduledTime = true;
    if ($colName === 'source') $hasSourceColumn = true;
  }
} catch (Throwable $e) {}
$hasNumeroPedido = company_sequence_has_column($pdo, 'pedidos');

$allowedStatus = ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'];
if ($fStatus !== '' && !in_array($fStatus, $allowedStatus, true)) $fStatus = '';

$where = ['company_id = ?'];
$params = [$ordersCompanyId];
if ($hasSourceColumn) $where[] = "(source IS NULL OR source <> 'delivery_control')";

if ($fQ !== '') {
  $qLike = nexa_search_like($fQ);
  $qDigits = preg_replace('/\D+/', '', $fQ);
  $searchParts = [
    'nome_cliente LIKE ?',
    'forma_pagamento LIKE ?',
    'tipo_entrega LIKE ?',
    "REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?"
  ];
  $searchParams = [$qLike, $qLike, $qLike, '%' . ($qDigits !== '' ? $qDigits : $fQ) . '%'];
  if ($qDigits !== '') {
    $searchParts[] = 'id = ?';
    $searchParams[] = (int)$qDigits;
    if ($hasNumeroPedido) {
      $searchParts[] = 'numero_pedido = ?';
      $searchParams[] = (int)$qDigits;
    }
  }
  $where[] = '(' . implode(' OR ', $searchParts) . ')';
  $params = array_merge($params, $searchParams);
}

if ($fStatus !== '') {
  $where[] = 'status = ?';
  $params[] = $fStatus;
}

if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fIni)) {
  // Evita DATE(created_at) para permitir melhor uso de índice em bases grandes.
  $where[] = 'created_at >= ?';
  $params[] = $fIni . ' 00:00:00';
}
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFim)) {
  $endExclusive = (new DateTimeImmutable($fFim . ' 12:00:00'))->modify('+1 day')->format('Y-m-d') . ' 00:00:00';
  $where[] = 'created_at < ?';
  $params[] = $endExclusive;
}

if ($hasScheduledDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevIni)) {
  $where[] = 'scheduled_delivery_date >= ?';
  $params[] = $fPrevIni;
}
if ($hasScheduledDate && preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevFim)) {
  $where[] = 'scheduled_delivery_date <= ?';
  $params[] = $fPrevFim;
}

$sqlWhere = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// Paginação exclusiva do novo grid: respeita todos os filtros, empresa e F11.
// O grid tradicional conserva a consulta anterior limitada a 300 registros.
$ordersGridPerPageOptions = [100, 200, 300];
$ordersGridPerPage = $ordersNewGrid && in_array((int)($_GET['por_pagina'] ?? 300), $ordersGridPerPageOptions, true)
  ? (int)($_GET['por_pagina'] ?? 300) : 300;
$ordersGridPage = $ordersNewGrid ? max(1, min(1000000, (int)($_GET['pg'] ?? 1))) : 1;
$ordersGridTotalMatches = 0;
$ordersGridPageCount = 1;
$ordersGridOffset = 0;
if ($ordersNewGrid) {
  $countStmt = $pdo->prepare("SELECT COUNT(*) FROM pedidos $sqlWhere");
  $countStmt->execute($params);
  $ordersGridTotalMatches = (int)$countStmt->fetchColumn();
  $ordersGridPageCount = max(1, (int)ceil($ordersGridTotalMatches / $ordersGridPerPage));
  $ordersGridPage = min($ordersGridPage, $ordersGridPageCount);
  $ordersGridOffset = ($ordersGridPage - 1) * $ordersGridPerPage;
}
$ordersSqlLimit = $ordersNewGrid
  ? 'LIMIT ' . (int)$ordersGridPerPage . ' OFFSET ' . (int)$ordersGridOffset
  : 'LIMIT 300';
// Desempenho: COALESCE(numero_pedido, id) obrigava o MySQL a ordenar todos os
// pedidos da empresa a cada abertura. Quando todos os pedidos têm número (caso
// normal), ordena direto pelo índice (company_id, numero_pedido).
$ordersOrderExpr = 'id';
if ($hasNumeroPedido) {
  $ordersOrderExpr = 'COALESCE(numero_pedido, id)';
  try {
    $stNullNum = $pdo->prepare('SELECT 1 FROM pedidos WHERE company_id = ? AND numero_pedido IS NULL LIMIT 1');
    $stNullNum->execute([$ordersCompanyId]);
    if (!$stNullNum->fetchColumn()) $ordersOrderExpr = 'numero_pedido';
  } catch (Throwable $e) {}
}
$sql = "SELECT *
        FROM pedidos
        $sqlWhere
        ORDER BY " . $ordersOrderExpr . " DESC, id DESC
        $ordersSqlLimit";

$st = $pdo->prepare($sql);
$st->execute($params);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

// Links de navegação preservam filtros e contexto operacional autenticado.
$ordersGridPageUrl = static function (int $page, int $pageSize) use ($fQ, $fStatus, $fIni, $fFim, $fPrevIni, $fPrevFim, $ordersCrossContext): string {
  $qs = http_build_query([
    'q' => $fQ, 'status' => $fStatus, 'dt_ini' => $fIni, 'dt_fim' => $fFim,
    'prev_ini' => $fPrevIni, 'prev_fim' => $fPrevFim,
    'pg' => $page, 'por_pagina' => $pageSize,
  ]);
  $url = 'pedidos.php?' . $qs;
  return $ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$contractOrderMap = [];
try {
  $idsC=array_values(array_filter(array_map('intval',array_column($rows,'id'))));
  if($idsC){$phC=implode(',',array_fill(0,count($idsC),'?'));$sc=$pdo->prepare("SELECT g.order_id,g.id generation_id,g.contract_id,g.competence,g.due_date,c.contract_number,c.chart_account_id FROM contract_generations g INNER JOIN contracts c ON c.id=g.contract_id AND c.company_id=g.company_id WHERE g.company_id=? AND g.order_id IN ({$phC})");$sc->execute(array_merge([$ordersCompanyId],$idsC));foreach(($sc->fetchAll(PDO::FETCH_ASSOC)?:[]) as $x)$contractOrderMap[(int)$x['order_id']]=$x;}
} catch(Throwable $e){$contractOrderMap=[];}

$contractWithoutPlanCount = count(array_filter($contractOrderMap, static fn(array $contract): bool => (int)($contract['chart_account_id'] ?? 0) <= 0));

$orderCompositionMap = fiscal_order_composition_map($pdo, $ordersCompanyId, array_column($rows, 'id'));

$fiscalNfceDocs = [];
$fiscalNfeDocs = [];
$fiscalNfseDocs = [];
$fiscalNfceReferencedMap = [];
$fiscalDevolutionMap = [];
if ($showFiscalNfceButton && $rows) {
  try { if ($canEmitNfcePedidos && function_exists('fiscal_nfce_order_documents_map')) $fiscalNfceDocs = fiscal_nfce_order_documents_map($pdo, $ordersCompanyId, array_column($rows, 'id')); }
  catch (Throwable $e) { $fiscalNfceDocs = []; }
  try { if ($canEmitNfePedidos && function_exists('fiscal_nfe_order_documents_map')) $fiscalNfeDocs = fiscal_nfe_order_documents_map($pdo, $ordersCompanyId, array_column($rows, 'id')); }
  catch (Throwable $e) { $fiscalNfeDocs = []; }
  try { if (function_exists('fiscal_nfe_referenced_nfce_order_map')) $fiscalNfceReferencedMap = fiscal_nfe_referenced_nfce_order_map($pdo, $ordersCompanyId, array_column($rows, 'id')); }
  catch (Throwable $e) { $fiscalNfceReferencedMap = []; }
  try { if (function_exists('fiscal_order_devolution_status_map')) $fiscalDevolutionMap = fiscal_order_devolution_status_map($pdo, $ordersCompanyId, array_column($rows, 'id')); }
  catch (Throwable $e) { $fiscalDevolutionMap = []; }
  if ($canEmitNfsePedidos) {
    try {
      $ids = array_values(array_filter(array_map('intval', array_column($rows, 'id'))));
      if ($ids) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $sqlNfse = "SELECT d.source_id AS pedido_id, d.id, d.status, nd.status AS nfse_status, nd.nfse_number, nd.api_error_message FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.fiscal_document_id=d.id AND nd.company_id=d.company_id WHERE d.company_id=? AND d.model='NFSE' AND d.source_type='PEDIDO' AND d.source_id IN ({$ph}) ORDER BY d.id DESC";
        $stNfse = $pdo->prepare($sqlNfse);
        $stNfse->execute(array_merge([$ordersCompanyId], $ids));
        foreach (($stNfse->fetchAll(PDO::FETCH_ASSOC) ?: []) as $docNfse) {
          $pedidoNfse = (int)($docNfse['pedido_id'] ?? 0);
          if ($pedidoNfse > 0 && !isset($fiscalNfseDocs[$pedidoNfse])) $fiscalNfseDocs[$pedidoNfse] = $docNfse;
        }
      }
    } catch (Throwable $e) { $fiscalNfseDocs = []; }
  }
}

$osPedidosModuleEnabled = false;
$osPedidosBridge = ['orders'=>[],'documents'=>[],'queues'=>[]];
$osFiscalCsrfPedidos = '';
try {
  $osPedidosModuleEnabled = !$ordersCrossContext && function_exists('saas_company_has_module')
    && saas_company_has_module($pdo, $ordersCompanyId, 'ordem_servico');
  if ($osPedidosModuleEnabled && $rows) {
    $osPedidosBridge = os_pedidos_fiscal_bridge($pdo, $ordersCompanyId, array_column($rows, 'id'));
    // Mescla apenas para VISUALIZAÇÃO. O documento continua source_type=ORDEM_SERVICO.
    foreach (($osPedidosBridge['documents'] ?? []) as $pedidoId => $docs) {
      if (!empty($docs['NFCE']) && empty($fiscalNfceDocs[$pedidoId])) $fiscalNfceDocs[$pedidoId] = $docs['NFCE'];
      if (!empty($docs['NFE']) && empty($fiscalNfeDocs[$pedidoId])) $fiscalNfeDocs[$pedidoId] = $docs['NFE'];
      if (!empty($docs['NFSE']) && empty($fiscalNfseDocs[$pedidoId])) $fiscalNfseDocs[$pedidoId] = $docs['NFSE'];
    }
    if (!empty($osPedidosBridge['orders'])) {
      require_once __DIR__ . '/../config/os_fiscal_queue.php';
      $osFiscalCsrfPedidos = function_exists('os_fiscal_csrf_token') ? os_fiscal_csrf_token() : '';
    }
  }
} catch (Throwable $e) {
  // Integração da O.S. nunca deve impedir a abertura da Central de Pedidos.
  $osPedidosModuleEnabled = false;
  $osPedidosBridge = ['orders'=>[],'documents'=>[],'queues'=>[]];
  $osFiscalCsrfPedidos = '';
}

$colspan = $hasScheduledDate ? 8 : 7;
$returnTo = 'pedidos.php';
$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($queryString !== '') $returnTo .= '?' . $queryString;
if ($ordersCrossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
$waSent = (string)($_GET['wa_sent'] ?? '') === '1';
$waError = (string)($_GET['wa_error'] ?? '') === '1';
$nfceOk = trim((string)($_GET['ok'] ?? ''));
$nfceErr = trim((string)($_GET['err'] ?? ''));
$contractBatchOk=trim((string)($_GET['contract_batch_ok']??''));$contractBatchErr=trim((string)($_GET['contract_batch_err']??''));
$orderReportsCfg = (array)($ADMIN_BUSINESS_CFG['order_reports'] ?? ['enabled' => true]);
if ($ordersCrossContext && function_exists('load_business_config')) {
  try { $ordersBizCfg = (array)load_business_config($ordersCompanyId); $orderReportsCfg = (array)($ordersBizCfg['order_reports'] ?? $orderReportsCfg); } catch (Throwable $e) {}
}
$reportsEnabled = !array_key_exists('enabled', $orderReportsCfg) || !empty($orderReportsCfg['enabled']);
$reportParams = [];
$reportParams['status'] = $fStatus !== '' ? $fStatus : 'aberto';
$reportParams['group_by'] = 'grupo';
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fIni)) $reportParams['dt_ini'] = $fIni;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fFim)) $reportParams['dt_fim'] = $fFim;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevIni)) $reportParams['prev_ini'] = $fPrevIni;
if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $fPrevFim)) $reportParams['prev_fim'] = $fPrevFim;
$reportsUrl = 'pedido_relatorio_produtos.php?' . http_build_query($reportParams);
if ($ordersCrossContext && function_exists('multi_company_context_url')) $reportsUrl = multi_company_context_url($reportsUrl);
?>
<?php admin_topbar('Central - Pedidos','pedidos'); ?>
<?php if ($ordersNewGrid): ?>
<style id="ordersGridFirstPaint">
/* Abre direto no novo grid: esconde os botões do layout antigo e a tabela até as
   colunas/larguras do usuário serem aplicadas (antes o antigo aparecia e só depois
   virava o novo). */
.pedidos-grid-v1 .pedidos-action-row{display:none!important}
html:not(.orders-grid-ready) .pedidos-grid-v1-wrap{visibility:hidden}
</style>
<script>
// Segurança: se o novo grid não carregar em 5s, mostra os botões antigos.
setTimeout(function(){ if(!document.documentElement.classList.contains('orders-grid-ready')){ var s=document.getElementById('ordersGridFirstPaint'); if(s) s.remove(); } }, 5000);
</script>
<link rel="stylesheet" href="assets/pedidos_grid_v2.css?v=20261022_1645_status">
<link rel="stylesheet" href="assets/pedidos_actions_modal_v1.css?v=20261022_1805">
<?php endif; ?>
<?php if ($ordersCrossContext): ?>
  <div class="msg ok" style="margin:16px 18px 0"><strong>V11.46 · Fiscal Multiempresa em HOMOLOGAÇÃO.</strong> A Central pode emitir, consultar e imprimir NFC-e modelo 65 e NF-e modelo 55 da empresa selecionada. NFS-e, Produção fiscal, cancelamento, CC-e, inutilização e lote continuam bloqueados; faturamento de O.S. permanece na fila fiscal exclusiva da O.S.</div>
<?php endif; ?>

<style>
  .pedidos-page-title{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:6px 0 14px;}
  .pedidos-page-title h1{margin:0;color:var(--admin-primary);font-size:26px;line-height:1.1;letter-spacing:-.03em;}
  .pedidos-page-title p{margin:4px 0 0;color:#64748b;font-size:13px;}
  .pedidos-wa-mini{display:inline-flex;align-items:center;gap:5px;margin-top:5px;font-size:10px;font-weight:850;color:#64748b;line-height:1.2}.pedidos-wa-mini::before{content:'';width:7px;height:7px;border-radius:50%;background:#94a3b8;box-shadow:0 0 0 2px rgba(148,163,184,.14)}.pedidos-wa-mini.connected{color:#15803d}.pedidos-wa-mini.connected::before{background:#22c55e;box-shadow:0 0 0 2px rgba(34,197,94,.15)}.pedidos-wa-mini.disconnected{color:#b91c1c}.pedidos-wa-mini.disconnected::before{background:#ef4444;box-shadow:0 0 0 2px rgba(239,68,68,.12)}.pedidos-wa-mini.unavailable{color:#a16207}.pedidos-wa-mini.unavailable::before{background:#f59e0b;box-shadow:0 0 0 2px rgba(245,158,11,.14)}
  .pedidos-page-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap;}
  .contract-batch-bar{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:#eef4ff;border:1px solid #bfd2ff;border-radius:14px;padding:12px 14px;margin:0 0 14px}.contract-batch-bar strong{color:#0b1f5b}.contract-batch-bar .hint{color:#64748b;font-size:12px;margin-right:auto}.contract-plan-warning{display:inline-flex;align-items:center;margin-top:4px;border-radius:6px;border:1px solid #fecaca;background:#fff1f2;color:#991b1b;font-size:10px;font-weight:800;padding:3px 7px;white-space:normal;line-height:1.3}.contract-plan-summary{font-size:11px;font-weight:800;color:#991b1b}.contract-origin{display:inline-flex;border-radius:999px;background:#eef4ff;color:#1557d5;padding:4px 8px;font-size:10px;font-weight:900}.contract-select{width:18px;height:18px;accent-color:#1557d5}.contract-batch-alert{border-radius:12px;padding:11px 14px;margin:0 0 12px;font-weight:800}.contract-batch-alert.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.contract-batch-alert.err{background:#fff1f2;color:#9f1239;border:1px solid #fecdd3}

  .pedidos-filter-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin:10px 0 14px;box-shadow:0 12px 30px rgba(15,23,42,.04);}
  .pedidos-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;max-width:100%;overflow:visible;}
  .pedidos-filters .pedidos-search-field{flex:2 1 250px;min-width:220px;}
  .pedidos-filters label{margin:0;font-size:12px;font-weight:800;color:#334155;flex:1 1 135px;min-width:130px;}
  .pedidos-filters input,.pedidos-filters select{min-height:42px;width:100%;margin-top:6px;border-radius:14px;box-sizing:border-box;}
  .pedidos-filters input[type="search"]{padding-right:14px;}
  .pedidos-filter-actions{display:flex;gap:10px;align-items:end;justify-content:flex-end;flex:0 0 auto;margin-left:auto;}
  .pedidos-filters .btn{min-height:42px;min-width:86px;display:inline-flex;align-items:center;justify-content:center;}

  .pedidos-alert{border-radius:16px;padding:11px 14px;margin:8px 0 14px;font-size:13px;font-weight:850;border:1px solid transparent;}
  .pedidos-alert-success{background:#ecfdf5;color:#047857;border-color:#bbf7d0;}
  .pedidos-alert-error{background:#fff1f2;color:#be123c;border-color:#fecdd3;}

  .pedidos-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;box-shadow:0 12px 30px rgba(15,23,42,.04);}
  .pedidos-card-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:12px;}
  .pedidos-card-head h2{margin:0;font-size:22px;color:var(--admin-primary);letter-spacing:-.03em;}
  .pedidos-count{color:#64748b;font-size:13px;font-weight:800;}
  .pedidos-count-wrap{display:flex;align-items:center;justify-content:flex-end;gap:12px;flex-wrap:wrap;}
  .pedidos-selected-total{color:#0f255f;font-size:13px;font-weight:800;white-space:nowrap;}

  .pedidos-table-wrap{overflow-x:auto;border:1px solid #dbe4f0;border-radius:16px;background:#fff;}
  .pedidos-orders-table{width:100%;min-width:0;table-layout:fixed;border-collapse:collapse;}
  .pedidos-orders-table th,.pedidos-orders-table td{vertical-align:middle;border-bottom:1px solid #e5edf6;}
  .pedidos-orders-table th{padding:10px 12px;background:#f8fbff;color:#0f255f;font-size:12px;font-weight:950;}
  .pedidos-orders-table td{padding:12px;color:#0f172a;}
  .pedidos-orders-table th:nth-child(1),.pedidos-orders-table td:nth-child(1){width:7%;}
  .pedidos-orders-table th:nth-child(2),.pedidos-orders-table td:nth-child(2){width:26%;}
  .pedidos-orders-table th:nth-child(3),.pedidos-orders-table td:nth-child(3){width:10%;}
  .pedidos-orders-table th:nth-child(4),.pedidos-orders-table td:nth-child(4){width:10%;}
  .pedidos-orders-table th:nth-child(5),.pedidos-orders-table td:nth-child(5){width:13%;}
  .pedidos-orders-table th:nth-child(6),.pedidos-orders-table td:nth-child(6){width:14%;}
  .pedidos-orders-table th:nth-child(7),.pedidos-orders-table td:nth-child(7){width:20%;}
  .pedidos-orders-table.has-scheduled th:nth-child(1),.pedidos-orders-table.has-scheduled td:nth-child(1){width:6%;}
  .pedidos-orders-table.has-scheduled th:nth-child(2),.pedidos-orders-table.has-scheduled td:nth-child(2){width:23%;}
  .pedidos-orders-table.has-scheduled th:nth-child(3),.pedidos-orders-table.has-scheduled td:nth-child(3){width:9%;}
  .pedidos-orders-table.has-scheduled th:nth-child(4),.pedidos-orders-table.has-scheduled td:nth-child(4){width:9%;}
  .pedidos-orders-table.has-scheduled th:nth-child(5),.pedidos-orders-table.has-scheduled td:nth-child(5){width:11%;}
  .pedidos-orders-table.has-scheduled th:nth-child(6),.pedidos-orders-table.has-scheduled td:nth-child(6){width:13%;}
  .pedidos-orders-table.has-scheduled th:nth-child(7),.pedidos-orders-table.has-scheduled td:nth-child(7){width:14%;}
  .pedidos-orders-table.has-scheduled th:nth-child(8),.pedidos-orders-table.has-scheduled td:nth-child(8){width:15%;}
  .pedidos-orders-table .idlink{font-weight:950;color:var(--admin-primary);}
  .pedidos-nfce-badge{display:inline-flex;align-items:center;gap:4px;margin-top:2px;padding:4px 8px;border-radius:999px;border:1px solid #dbe4f0;background:#f8fafc;color:#64748b;font-size:10px;font-weight:950;line-height:1.15;white-space:nowrap;max-width:none;overflow:visible;text-overflow:clip}.pedidos-nfce-badge.ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.pedidos-nfce-badge.warn{background:#fff7ed;color:#9a3412;border-color:#fed7aa}.pedidos-nfce-badge.wait{background:#eff6ff;color:#1e3a8a;border-color:#bfdbfe}.pedidos-nfce-badge.off{background:#f8fafc;color:#94a3b8;border-color:#e2e8f0}
  .pedidos-client strong{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%;}
  .pedidos-client .muted{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:13px;margin-top:3px;}
  .pedidos-orders-table td:not(.pedidos-client):not(.pedidos-status-cell):not(.pedidos-action-cell){overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
  .pedidos-status-cell{overflow:visible!important;white-space:nowrap;}
  .pedidos-status-cell .status-pill{display:inline-flex;max-width:none;white-space:nowrap;}
  .pedidos-order-row td{border-bottom:0;}
  .pedidos-action-row td{padding:0 12px 14px!important;border-bottom:1px solid #e5edf6;}
  .pedidos-action-panel{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:9px 10px;}
  .pedidos-action-title{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-right:auto;}
  .pedidos-fiscal-summary{flex:1 1 100%;display:flex;align-items:flex-start;gap:10px;min-width:0;background:#fff;border:1px solid #e2e8f0;border-radius:12px;padding:8px 10px;}
  .pedidos-fiscal-summary-label{flex:0 0 auto;font-size:10px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.05em;line-height:24px;}
  .pedidos-fiscal-summary-values{display:flex;align-items:center;gap:6px;flex-wrap:wrap;min-width:0;}
  .pedidos-fiscal-summary-values br{display:none;}
  .pedidos-os-fiscal-actions{display:inline-flex;gap:7px;align-items:center;flex-wrap:wrap;}
  .pedidos-os-fiscal-actions form{margin:0;display:inline-flex;}
  .pedidos-action-buttons{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap;}
  .pedidos-whatsapp-form{margin:0;display:inline-flex;}
  .pedidos-action-buttons .btn,.pedidos-status-form .btn{min-width:86px;min-height:34px;padding:7px 11px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-size:12px;font-weight:900;line-height:1;white-space:nowrap;}
  .pedidos-fiscal-menu{position:relative;display:inline-flex;align-items:center;}
  .pedidos-fiscal-menu-list{display:none;position:absolute;right:0;top:calc(100% + 6px);z-index:30;min-width:165px;background:#fff;border:1px solid #dbe4f0;border-radius:14px;box-shadow:0 18px 40px rgba(15,23,42,.16);padding:7px;gap:6px;}
  .pedidos-fiscal-menu:hover .pedidos-fiscal-menu-list,.pedidos-fiscal-menu:focus-within .pedidos-fiscal-menu-list{display:grid;}
  .pedidos-fiscal-menu-list form{margin:0;display:block;width:100%;}
  .pedidos-fiscal-menu-list .btn{width:100%;justify-content:flex-start;text-align:left;min-width:0;}
  .pedidos-status-form{display:flex;gap:8px;align-items:center;margin:0;flex-wrap:nowrap;}
  .pedidos-status-form select{min-height:34px;border:1px solid #dbe4f0;border-radius:12px;padding:6px 9px;background:#fff;min-width:170px;max-width:220px;font-size:12px;font-weight:850;color:#0f172a;}
  .pedidos-status-form .btn-primary{min-width:118px;}
  .pedidos-empty{padding:22px!important;text-align:center;color:#64748b;font-weight:800;}


  /* Mobile real: em celular, não tentamos adaptar a tabela. Escondemos a tabela e mostramos cards próprios. */
  .pedidos-mobile-list{display:none;}
  @media(max-width:900px){
    .pedidos-page-title{align-items:flex-start;}
    .pedidos-page-actions{width:100%;}
    .pedidos-page-actions .btn{width:100%;justify-content:center;}
    .pedidos-filter-card{padding:12px;border-radius:18px;}
    .pedidos-filters{display:grid;grid-template-columns:1fr;gap:11px;}
    .pedidos-filters label,.pedidos-filters .pedidos-search-field{min-width:0;width:100%;flex:none;}
    .pedidos-filters input,.pedidos-filters select{font-size:16px;min-width:0;max-width:100%;}
    .pedidos-filter-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;margin-left:0;}
    .pedidos-filter-actions .btn{width:100%;min-width:0;}
    .pedidos-card{padding:12px;border-radius:18px;}
    .pedidos-card-head{align-items:flex-start;gap:6px;flex-direction:column;margin-bottom:10px;}
    .pedidos-card-head h2{font-size:20px;}
    .pedidos-table-wrap{display:none!important;}
    .pedidos-mobile-list{display:grid!important;gap:12px;}
    .pedidos-mobile-card{border:1px solid #dbe4f0;border-radius:20px;background:#fff;box-shadow:0 10px 26px rgba(15,23,42,.06);overflow:hidden;}
    .pedidos-mobile-head{display:flex;align-items:flex-start;justify-content:space-between;gap:10px;padding:14px 14px 10px;background:linear-gradient(180deg,#f8fbff,#fff);border-bottom:1px solid #eef2f7;}
    .pedidos-mobile-label,.pedidos-mobile-grid span{display:block;font-size:9px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.05em;line-height:1.15;}
    .pedidos-mobile-head strong{display:block;margin-top:3px;font-size:19px;color:#0f172a;line-height:1.1;}
    .pedidos-mobile-head .status-pill{flex:0 0 auto;margin-top:2px;white-space:nowrap;}
    .pedidos-mobile-client{padding:12px 14px 2px;}
    .pedidos-mobile-client strong{display:block;font-size:16px;line-height:1.25;color:#0f172a;word-break:break-word;}
    .pedidos-mobile-client .muted{display:block;margin-top:3px;color:#64748b;font-size:13px;word-break:break-word;}
    .pedidos-mobile-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;padding:12px 14px;}
    .pedidos-mobile-grid .item{min-width:0;background:#f8fafc;border:1px solid #eef2f7;border-radius:14px;padding:10px;}
    .pedidos-mobile-grid strong{display:block;margin-top:5px;font-size:14px;color:#0f172a;line-height:1.25;word-break:break-word;}
    .pedidos-mobile-grid .total strong{font-size:18px;color:#047857;white-space:nowrap;}
    .pedidos-mobile-grid .wide{grid-column:1 / -1;}
    .pedidos-mobile-fiscal .pedidos-fiscal-summary-values{margin-top:6px;display:flex;gap:6px;flex-wrap:wrap;}
    .pedidos-mobile-fiscal .pedidos-nfce-badge{font-size:10px;white-space:normal;text-align:left;}
    .pedidos-mobile-actions{border-top:1px solid #eef2f7;background:#f8fbff;padding:12px 14px 14px;display:grid;gap:10px;}
    .pedidos-mobile-actions-title{font-size:11px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.05em;}
    .pedidos-mobile-buttons{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;width:100%;}
    .pedidos-mobile-buttons .btn,.pedidos-mobile-buttons button{width:100%;min-width:0;min-height:40px;padding:8px 7px;border-radius:12px;font-size:12px;display:inline-flex;align-items:center;justify-content:center;text-align:center;}
    .pedidos-mobile-buttons form{width:100%;margin:0;}
    .pedidos-mobile-buttons .pedidos-fiscal-menu{width:100%;}
    .pedidos-mobile-buttons .pedidos-fiscal-menu-list{left:0;right:auto;min-width:100%;}
    .pedidos-mobile-card .pedidos-status-form{display:grid!important;grid-template-columns:1fr;gap:8px;width:100%;margin:0;}
    .pedidos-mobile-card .pedidos-status-form select,.pedidos-mobile-card .pedidos-status-form .btn-primary{width:100%;min-width:0;max-width:none;min-height:42px;border-radius:13px;font-size:14px;}
    .pedidos-mobile-empty{border:1px dashed #cbd5e1;border-radius:18px;padding:16px;text-align:center;color:#64748b;background:#f8fafc;font-weight:850;}
  }
  @media(max-width:380px){
    .pedidos-filter-actions{grid-template-columns:1fr;}
    .pedidos-mobile-grid{grid-template-columns:1fr;}
    .pedidos-mobile-buttons{grid-template-columns:1fr;}
  }

  @media(max-width:980px){
    .pedidos-filters label{flex:1 1 calc(50% - 10px);min-width:220px;}
    .pedidos-filters .pedidos-search-field{flex:1 1 100%;}
    .pedidos-filter-actions{flex:1 1 100%;justify-content:flex-end;}
    .pedidos-orders-table{min-width:820px;table-layout:auto;}
  }
  @media(max-width:680px){
    .pedidos-page-title h1{font-size:22px;}
    .pedidos-filters label,.pedidos-filters .pedidos-search-field{flex:1 1 100%;min-width:0;}
    .pedidos-filter-actions{width:100%;justify-content:stretch;margin-left:0;}
    .pedidos-filter-actions .btn{flex:1 1 0;width:100%;}
    .pedidos-orders-table{min-width:0;}
    .pedidos-orders-table thead{display:none;}
    .pedidos-orders-table,.pedidos-orders-table tbody,.pedidos-orders-table tr,.pedidos-orders-table td{display:block;width:100%;}
    .pedidos-order-row{border:1px solid #dbe4f0;border-bottom:0;border-radius:18px 18px 0 0;background:#fff;margin-top:10px;}
    .pedidos-order-row td{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f7;padding:9px 12px;white-space:normal!important;overflow:visible!important;}
    .pedidos-order-row td::before{content:attr(data-label);font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;}
    .pedidos-client{display:block!important;}
    .pedidos-client::before{display:block;margin-bottom:5px;}
    .pedidos-action-row{display:block;border:1px solid #dbe4f0;border-top:0;border-radius:0 0 18px 18px;background:#fff;margin-bottom:10px;}
    .pedidos-action-row td{display:block!important;border:0!important;padding:0 10px 10px!important;}
    .pedidos-action-panel{border-radius:14px;justify-content:flex-start;}
    .pedidos-action-title{flex:1 0 100%;margin:0;}
    .pedidos-action-buttons,.pedidos-status-form{width:100%;}
    .pedidos-whatsapp-form{width:100%;}
    .pedidos-whatsapp-form .btn{width:100%;}
    .pedidos-action-buttons .btn,.pedidos-status-form .btn,.pedidos-status-form select{flex:1 1 100%;max-width:none;}
    .pedidos-status-form{flex-wrap:wrap;}
  }
</style>
<?php if ($ordersNewGrid): ?>
<style id="pedidosGridFlowFix20261022">
/* Corrige o fluxo mesmo se um cache servir uma versão antiga do CSS. Escopo: novo grid. */
.pedidos-grid-compact-card .pedidos-grid-compact-head {
  display:flex!important; flex-direction:row!important; align-items:center!important;
  justify-content:space-between!important; flex-wrap:nowrap!important;
  gap:12px!important; margin-bottom:8px!important; min-height:32px!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head h2 {
  margin:0!important; font-size:16px!important; line-height:1.2!important;
  white-space:nowrap!important; flex:0 0 auto!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-count-wrap {
  display:flex!important; flex:1 1 auto!important; min-width:0!important;
  flex-direction:row!important; align-items:center!important;
  justify-content:flex-end!important; flex-wrap:nowrap!important; gap:12px!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-count-wrap > * {
  flex-shrink:0!important; white-space:nowrap!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-toolbar {
  display:flex!important; flex:0 0 auto!important; flex-direction:row!important;
  flex-wrap:nowrap!important; align-items:center!important; gap:8px!important; margin:0!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-pagesize {
  display:inline-flex!important; flex-direction:row!important; align-items:center!important;
  flex-wrap:nowrap!important; gap:6px!important; margin:0!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-pagesize label {
  display:inline!important; width:auto!important; min-width:0!important; margin:0!important;
  font-size:11px!important; line-height:1!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-pagesize select {
  display:inline-block!important; flex:none!important; width:64px!important;
  height:30px!important; min-height:30px!important; margin:0!important;
}
.pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-picker > summary {
  display:inline-flex!important; align-items:center!important; justify-content:center!important;
  height:30px!important; min-height:30px!important; margin:0!important;
}
.pedidos-grid-compact-card .pedidos-grid-v1-wrap {
  display:block!important; max-height:none!important; min-height:0!important;
  height:auto!important; overflow-x:auto!important; overflow-y:hidden!important;
  scrollbar-gutter:auto!important; overscroll-behavior-y:auto!important;
}
/* Evita que o layout tradicional seja alterado: somente o grid piloto possui as classes acima. */
@media(max-width:930px) {
  .pedidos-grid-compact-card .pedidos-grid-compact-head {gap:7px!important;}
  .pedidos-grid-compact-card .pedidos-grid-compact-head h2 {font-size:13px!important;}
  .pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-count-wrap {gap:6px!important;}
  .pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-count,
  .pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-selected-total,
  .pedidos-grid-compact-card .pedidos-grid-compact-head .orders-grid-visible-count {font-size:10px!important;}
}
@media(max-width:700px) {
  /* No celular, o fluxo usa cartões móveis já existentes, não o grid desktop. */
  .pedidos-grid-compact-card .pedidos-grid-compact-head {flex-wrap:wrap!important;}
  .pedidos-grid-compact-card .pedidos-grid-compact-head .pedidos-count-wrap {flex-wrap:wrap!important;}
}
</style>
<link rel="stylesheet" href="assets/pedidos_mobile_cards_v2.css?v=20261022_1753">
<?php endif; ?>

<div class="pedidos-page-title">
  <div>
    <h1>Pedidos</h1>
    <p><?= $ordersCrossContext ? 'V11.46 · Consulta dos pedidos da empresa selecionada pelo F11, com emissão fiscal protegida de NFC-e/NF-e em HOMOLOGAÇÃO.' : 'Consulte, edite, imprima e altere o status dos pedidos sem sair do módulo Central.' ?></p>
    <?php if (!$ordersCrossContext): ?><span class="pedidos-wa-mini" id="pedidosWhatsappMini" title="Status do serviço WhatsApp desta empresa">WhatsApp: verificando...</span><?php endif; ?>
  </div>
  <div class="pedidos-page-actions">
    <?php if ($showFiscalNfceButton && !$ordersCrossContext): ?>
      <a class="btn btn-primary" href="fiscal_notas_referenciadas.php">Emitir Notas Referenciadas</a>
      <a class="btn" href="fiscal_emissao_lote.php">Emissão em lote</a>
      <a class="btn" href="fiscal_lote_contingencia.php">Reprocessamento</a>
    <?php endif; ?>
    <?php if ($reportsEnabled): ?>
      <a class="btn" href="<?= pedidos_h($reportsUrl) ?>">Relatórios</a>
    <?php endif; ?>
  </div>
</div>

<?php if ($ordersCrossContext): ?>
  <div class="pedidos-alert pedidos-alert-success"><strong>V11.46 · Vendas Multiempresa.</strong> Esta tela está consultando <strong><?= pedidos_h($ordersCompanyName !== '' ? $ordersCompanyName : ('Empresa #' . $ordersCompanyId)) ?></strong>. NFC-e/NF-e em HOMOLOGAÇÃO podem ser emitidas pelos fluxos protegidos. Edição, alteração de status, WhatsApp/E-mail, lotes e demais operações não homologadas continuam bloqueadas pelo F11; as impressões operacionais já homologadas permanecem disponíveis.</div>
<?php endif; ?>

<script>
(() => {
  const el = document.getElementById('pedidosWhatsappMini');
  if (!el) return;
  let running = false;
  async function refreshWhatsappMini() {
    if (running || document.hidden) return;
    running = true;
    const ctrl = new AbortController();
    const timer = setTimeout(() => ctrl.abort(), 4500);
    try {
      const response = await fetch('whatsapp_api.php?action=peek_status', {
        cache: 'no-store',
        credentials: 'same-origin',
        signal: ctrl.signal,
        headers: {'Accept': 'application/json'}
      });
      const data = await response.json();
      if (!response.ok || !data || data.ok !== true) {
        throw new Error(String(data?.error || data?.message || 'Falha ao consultar o serviço WhatsApp.'));
      }
      const connected = Boolean(data.connected);
      el.classList.remove('unavailable');
      el.classList.toggle('connected', connected);
      el.classList.toggle('disconnected', !connected);
      el.textContent = connected ? 'WhatsApp: conectado' : 'WhatsApp: desconectado';
      const detail = String(data?.technical_status || '');
      const err = String(data?.last_error || '');
      el.title = connected ? 'WhatsApp conectado e pronto para envio.' : ('WhatsApp desconectado' + (detail ? ' (' + detail + ')' : '') + (err ? ': ' + err : '.'));
    } catch (e) {
      // Falha de comunicação não significa que o WhatsApp esteja desconectado.
      // Mostra estado neutro para não gerar falso diagnóstico ao operador.
      el.classList.remove('connected', 'disconnected');
      el.classList.add('unavailable');
      el.textContent = 'WhatsApp: status indisponível';
      el.title = String(e?.message || 'Não foi possível consultar o serviço WhatsApp.');
    } finally {
      clearTimeout(timer);
      running = false;
    }
  }
  // Consulta leve apenas uma vez ao abrir a tela; sem polling periódico.
  refreshWhatsappMini();
})();
</script>

<?php if ($waSent): ?>
  <div class="pedidos-alert pedidos-alert-success">Pedido enviado pelo WhatsApp com sucesso.</div>
<?php elseif ($waError): ?>
  <div class="pedidos-alert pedidos-alert-error">Não foi possível enviar o pedido pelo WhatsApp. Verifique se a conexão está como Conectado e tente novamente.</div>
<?php endif; ?>
<?php if ($nfceOk !== ''): ?>
  <div class="pedidos-alert pedidos-alert-success"><?= pedidos_h($nfceOk) ?></div>
<?php endif; ?>
<?php if ($nfceErr !== ''): ?>
  <div class="pedidos-alert pedidos-alert-error"><?= pedidos_h($nfceErr) ?></div>
<?php endif; ?>

<form method="get" class="pedidos-filter-card pedidos-filters" data-lembrar-filtros="pedidos" data-lembrar-extra="pg,por_pagina">
  <?php if ($ordersCrossContext && $ordersMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
  <?php if ($ordersNewGrid): ?><input type="hidden" name="por_pagina" value="<?= (int)$ordersGridPerPage ?>"><?php endif; ?>
  <label class="pedidos-search-field">Histórico / Cliente / número
    <input type="search" name="q" value="<?= pedidos_h($fQ) ?>" placeholder="Cliente, WhatsApp, pagamento ou nº" autocomplete="off">
  </label>
  <label>Status
    <select name="status">
      <option value="" <?= $fStatus===''?'selected':'' ?>>Todos</option>
      <?php foreach ($allowedStatus as $opt): ?>
        <option value="<?= pedidos_h($opt) ?>" <?= $fStatus===$opt?'selected':'' ?>><?= pedidos_h(fmt_status($opt)) ?></option>
      <?php endforeach; ?>
    </select>
  </label>
  <label>De
    <input type="date" name="dt_ini" value="<?= pedidos_h($fIni) ?>">
  </label>
  <label>Até
    <input type="date" name="dt_fim" value="<?= pedidos_h($fFim) ?>">
  </label>
  <?php if ($hasScheduledDate): ?>
    <label>Prevista de
      <input type="date" name="prev_ini" value="<?= pedidos_h($fPrevIni) ?>">
    </label>
    <label>Prevista até
      <input type="date" name="prev_fim" value="<?= pedidos_h($fPrevFim) ?>">
    </label>
  <?php endif; ?>
  <div class="pedidos-filter-actions">
    <button type="submit" class="btn btn-primary">Filtrar</button>
    <a class="btn" href="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('pedidos.php?limpar_filtros=1') : 'pedidos.php?limpar_filtros=1') ?>">Limpar</a>
  </div>
</form><script src="/assets/nexa-lembrar-filtros.js?v=1"></script>

<?php if($contractBatchOk!==''):?><div class="contract-batch-alert ok"><?=pedidos_h($contractBatchOk)?></div><?php endif;?><?php if($contractBatchErr!==''):?><div class="contract-batch-alert err"><?=pedidos_h($contractBatchErr)?></div><?php endif;?>
<?php if($contractOrderMap && !$ordersCrossContext):?><form id="contractBatchForm" method="post" action="pedidos_contratos_lote.php" class="contract-batch-bar"><input type="hidden" name="csrf_token" value="<?=pedidos_h(nfse_emitter_csrf_token('contract_nfse_batch'))?>"><input type="hidden" name="return_to" value="<?=pedidos_h($returnTo)?>"><strong>Pedidos de contratos</strong><span class="hint">Selecione os pedidos recorrentes para boleto; a NFS-e usa a Central de Emissão em Lote.</span><button type="button" class="btn" id="contractSelectAll">Marcar todos</button><button type="button" class="btn" id="contractClearAll">Desmarcar</button><?php if($canEmitNfsePedidos):?><a class="btn btn-primary" href="fiscal_emissao_lote.php?tipo=NFSE&amp;origem=CONTRATOS">NFS-e em lote</a><?php endif;?><button type="submit" name="action" value="generate_boleto" class="btn" onclick="return confirm('Preparar boletos para os pedidos de contratos selecionados?')">Gerar boletos em lote</button></form><?php endif;?>
<div class="pedidos-card <?= $ordersNewGrid ? 'pedidos-grid-compact-card' : '' ?>">
  <div class="pedidos-card-head <?= $ordersNewGrid ? 'pedidos-grid-compact-head' : '' ?>">
    <h2>Pedidos encontrados</h2>
    <div class="pedidos-count-wrap">
      <span class="pedidos-count"><?= $ordersNewGrid ? (int)$ordersGridTotalMatches : count($rows) ?> pedido(s)</span>
      <?php if($contractOrderMap && !$ordersCrossContext):?><span class="pedidos-selected-total" id="pedidosSelectedTotal" aria-live="polite">Selecionados: 0 · R$ 0,00</span><?php endif;?>
      <?php if($contractWithoutPlanCount>0):?><span class="contract-plan-summary" title="Quantidade de pedidos de contratos sem plano de contas atualmente cadastrados">⚠ <?= (int)$contractWithoutPlanCount ?> sem plano de contas</span><?php endif;?>
      <?php if ($ordersNewGrid): ?>
        <span class="orders-grid-visible-count" id="ordersGridFilteredCount" aria-live="polite"><?= count($rows) ?> visíveis</span>
        <div class="orders-grid-toolbar" id="ordersGridToolbar">
          <form method="get" class="orders-grid-pagesize" title="Quantidade máxima de pedidos por página">
            <?php if ($ordersCrossContext && $ordersMcCtx !== ''): ?><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
            <input type="hidden" name="q" value="<?= pedidos_h($fQ) ?>"><input type="hidden" name="status" value="<?= pedidos_h($fStatus) ?>">
            <input type="hidden" name="dt_ini" value="<?= pedidos_h($fIni) ?>"><input type="hidden" name="dt_fim" value="<?= pedidos_h($fFim) ?>">
            <input type="hidden" name="prev_ini" value="<?= pedidos_h($fPrevIni) ?>"><input type="hidden" name="prev_fim" value="<?= pedidos_h($fPrevFim) ?>">
            <label for="ordersGridPageSize">Exibir</label>
            <select id="ordersGridPageSize" name="por_pagina" onchange="this.form.submit()" aria-label="Pedidos por página">
              <?php foreach ($ordersGridPerPageOptions as $pageSize): ?><option value="<?= $pageSize ?>" <?= $ordersGridPerPage === $pageSize ? 'selected' : '' ?>><?= $pageSize ?></option><?php endforeach; ?>
            </select>
          </form>
          <details class="orders-grid-picker"><summary class="btn btn-sm">☷ Colunas</summary><div class="orders-grid-picker-panel" id="ordersGridColPicker"><strong>Colunas visíveis</strong><div id="ordersGridColumns"></div><small>Arraste as colunas pelo marcador · ajuste a largura pela borda do cabeçalho. Ações sempre fixas.</small></div></details>
          <span id="ordersGridPrefState" role="status" aria-live="polite"></span>
        </div>
      <?php endif; ?>
    </div>
  </div>
  <div class="pedidos-table-wrap <?= $ordersNewGrid ? 'pedidos-grid-v1-wrap' : '' ?>">
    <table <?= $ordersNewGrid ? 'id="ordersGridV1"' : '' ?> class="pedidos-orders-table <?= $hasScheduledDate ? 'has-scheduled' : '' ?> <?= $ordersNewGrid ? 'pedidos-grid-v1' : '' ?>">
      <thead>
        <tr>
          <th data-col="numero">Nº</th>
          <th data-col="cliente">Cliente</th>
          <th data-col="total">Total</th>
          <th data-col="entrega">Entrega</th>
          <th data-col="pagamento">Pagamento</th>
          <th data-col="status">Status</th>
          <th data-col="data">Data</th>
          <?php if ($hasScheduledDate): ?><th data-col="prevista">Data/Hora Prevista</th><?php endif; ?>
          <?php if ($ordersNewGrid && $showFiscalNfceButton): ?><th data-col="fiscal">Fiscal</th><?php endif; ?>
          <?php if ($ordersNewGrid): ?><th data-col="acoes" class="orders-grid-actions-col">Ações</th><?php endif; ?>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?>
        <tr><td colspan="<?= $colspan + ($ordersNewGrid ? (1 + ($showFiscalNfceButton ? 1 : 0)) : 0) ?>" class="pedidos-empty">Nenhum pedido encontrado para os filtros informados.</td></tr>
      <?php endif; ?>
      <?php foreach ($rows as $r): ?>
        <?php
          $id = (int)$r['id'];
          $contractOrigin=$contractOrderMap[$id]??null;$isContractOrder=(bool)$contractOrigin;
          $numeroPedido = company_sequence_display($r, 'pedidos');
          $statusAtual = (string)($r['status'] ?? 'aberto');
          $whatsapp = preg_replace('/\D+/', '', (string)($r['whatsapp'] ?? ''));
          $wlink = $whatsapp ? ('pedido_whatsapp.php?id=' . $id) : '';
          $nfceDoc = $showFiscalNfceButton ? ($fiscalNfceDocs[$id] ?? null) : null;
          $nfeDoc = $showFiscalNfceButton ? ($fiscalNfeDocs[$id] ?? null) : null;
          $nfseDoc = $showFiscalNfceButton ? ($fiscalNfseDocs[$id] ?? null) : null;
          $composition = $orderCompositionMap[$id] ?? [];
          $orderOnlyServices = !empty($composition['only_services']);
          $orderOnlyProducts = !empty($composition['only_products']);
          $orderMixed = !empty($composition['mixed']);
          $isOsBatchOrder = strtolower(trim((string)($r['source'] ?? ''))) === 'ordem_servico_lote';
          $orderCanEmitNfce = $canEmitNfcePedidos && ($orderOnlyProducts || $orderMixed);
          $orderCanEmitNfe = $canEmitNfePedidos && ($orderOnlyProducts || $orderMixed);
          if ($ordersCrossContext && $isOsBatchOrder) { $orderCanEmitNfce = false; $orderCanEmitNfe = false; }
          $orderCanEmitNfse = $canEmitNfsePedidos && ($orderOnlyServices || $orderMixed);
          $showOrderNfse = $orderCanEmitNfse || (bool)$nfseDoc;
          $nfceAutorizada = $nfceDoc && strtoupper((string)($nfceDoc['status'] ?? '')) === 'AUTORIZADA';
          $nfeAutorizada = $nfeDoc && strtoupper((string)($nfeDoc['status'] ?? '')) === 'AUTORIZADA';
          $nfeStatus = $nfeDoc ? strtoupper(trim((string)($nfeDoc['status'] ?? ''))) : '';
          $nfeNeedsConsult = $nfeDoc && in_array($nfeStatus, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true);
          $nfceRefInfo = $showFiscalNfceButton ? ($fiscalNfceReferencedMap[$id] ?? null) : null;
          $devolutionInfo = $showFiscalNfceButton ? ($fiscalDevolutionMap[$id] ?? null) : null;
          $osFiscalOrder = $osPedidosBridge['orders'][$id] ?? null;
          $isOsFiscalOrder = (bool)$osFiscalOrder;
          $osFiscalQueues = $osPedidosBridge['queues'][$id] ?? [];
          $osNfeQueue = $osFiscalQueues['NFE'] ?? null;
          $osNfceQueue = $osFiscalQueues['NFCE'] ?? null;
          $osNfseQueue = $osFiscalQueues['NFSE'] ?? null;
          $osRequiresProduct = $isOsFiscalOrder && (int)($osFiscalOrder['requires_product_fiscal'] ?? 0) === 1;
          $osRequiresService = $isOsFiscalOrder && (int)($osFiscalOrder['requires_service_fiscal'] ?? 0) === 1;
          if ($isOsFiscalOrder) {
            $orderCanEmitNfce = $canEmitNfcePedidos && $osRequiresProduct;
            $orderCanEmitNfe = $canEmitNfePedidos && $osRequiresProduct;
            $orderCanEmitNfse = $canEmitNfsePedidos && $osRequiresService;
            $showOrderNfse = $osRequiresService || (bool)$nfseDoc;
          }
          if ($ordersCrossContext && $isOsBatchOrder) {
            $orderCanEmitNfce = false;
            $orderCanEmitNfe = false;
            $orderCanEmitNfse = false;
            $showOrderNfse = (bool)$nfseDoc;
          }
          $osCanStartProductFiscal = $isOsFiscalOrder && $osRequiresProduct && !$nfeDoc && !$nfceDoc
            && !os_pedidos_queue_blocks_new_generation($osNfeQueue) && !os_pedidos_queue_blocks_new_generation($osNfceQueue);
          $osCanStartNfse = $isOsFiscalOrder && $osRequiresService && !$nfseDoc
            && !os_pedidos_queue_blocks_new_generation($osNfseQueue);
          $osHasAnyFiscalRecord = $isOsFiscalOrder && (bool)($nfeDoc || $nfceDoc || $nfseDoc || $osNfeQueue || $osNfceQueue || $osNfseQueue);
          $osAllRequiredAuthorized = $isOsFiscalOrder
            && (!$osRequiresProduct || $nfceAutorizada || $nfeAutorizada)
            && (!$osRequiresService || ($nfseDoc && strtoupper((string)($nfseDoc['nfse_status'] ?? $nfseDoc['status'] ?? '')) === 'AUTORIZADA'));
          $prevista = '';
          if ($hasScheduledDate && !empty($r['scheduled_delivery_date'])) {
            $prevista = date('d/m/Y', strtotime((string)$r['scheduled_delivery_date']));
            if ($hasScheduledTime && !empty($r['scheduled_delivery_time'])) $prevista .= ' ' . substr((string)$r['scheduled_delivery_time'], 0, 5);
          }
        ?>
        <tr class="pedidos-order-row" data-order-id="<?= $id ?>">
          <td data-col="numero" data-label="Nº"><?php if($isContractOrder && !$ordersCrossContext):?><input class="contract-select" type="checkbox" name="order_ids[]" value="<?=$id?>" data-total-cents="<?= (int)round(((float)($r['total'] ?? 0)) * 100) ?>" form="contractBatchForm"> <?php endif;?><a class="idlink" href="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo)) : ('pedido_editar.php?id=' . $id)) ?>">#<?=$numeroPedido?></a><?php if($isContractOrder):?><div><span class="contract-origin">Contrato #<?=pedidos_h($contractOrigin['contract_number'])?></span></div><?php if((int)($contractOrigin['chart_account_id']??0)<=0):?><div><span class="contract-plan-warning" title="O contrato associado está atualmente sem plano de contas.">Sem plano de contas</span></div><?php endif;?><?php endif;?><?php if($isOsBatchOrder):?><div><span class="contract-origin">Fatura de O.S.</span></div><?php endif;?></td>
          <td data-col="cliente" data-label="Cliente" class="pedidos-client" title="<?= pedidos_h($r['nome_cliente'] ?? '') ?>">
            <strong><?= pedidos_h($r['nome_cliente'] ?? '') ?></strong>
            <span class="muted"><?= pedidos_h($r['whatsapp'] ?? '') ?></span>
          </td>
          <td data-col="total" data-label="Total"><?= pedidos_money_br($r['total'] ?? 0) ?></td>
          <td data-col="entrega" data-label="Entrega" title="<?= pedidos_h($r['tipo_entrega'] ?? '') ?>"><?= pedidos_h($r['tipo_entrega'] ?? '') ?></td>
          <td data-col="pagamento" data-label="Pagamento" title="<?= pedidos_h($r['forma_pagamento'] ?? '') ?>"><?= pedidos_h($r['forma_pagamento'] ?? '') ?></td>
          <td data-col="status" data-label="Status" class="pedidos-status-cell"><span class="<?= pedidos_h(pedidos_status_badge_class($statusAtual)) ?>"><?= pedidos_h(fmt_status($statusAtual)) ?></span></td>
          <td data-col="data" data-label="Data"><?= pedidos_h(fmt_dt_br((string)($r['created_at'] ?? ''))) ?></td>
          <?php if ($hasScheduledDate): ?><td data-col="prevista" data-label="Data/Hora Prevista"><?= pedidos_h($prevista) ?></td><?php endif; ?>
          <?php if ($ordersNewGrid && $showFiscalNfceButton): ?><td data-col="fiscal" class="orders-grid-fiscal-cell" data-label="Fiscal"></td><?php endif; ?>
          <?php if ($ordersNewGrid): ?><td data-col="acoes" class="orders-grid-actions-col" data-label="Ações"><div class="orders-grid-icons" data-actions-for="<?= $id ?>"></div></td><?php endif; ?>
        </tr>
        <tr class="pedidos-action-row">
          <td colspan="<?= $colspan + ($ordersNewGrid ? (1 + ($showFiscalNfceButton ? 1 : 0)) : 0) ?>" class="pedidos-action-cell">
            <div class="pedidos-action-panel">
              <?php if ($showFiscalNfceButton): ?>
                <div class="pedidos-fiscal-summary">
                  <span class="pedidos-fiscal-summary-label">Fiscal</span>
                  <div class="pedidos-fiscal-summary-values">
                    <?= pedidos_fiscal_badges($nfceDoc, $nfeDoc, $orderCanEmitNfce, $orderCanEmitNfe, $nfceRefInfo, $devolutionInfo) ?>
                    <?php if ($showOrderNfse): ?><?= pedidos_nfse_badge($nfseDoc, $orderCanEmitNfse) ?><?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
              <span class="pedidos-action-title">Ações</span>
              <div class="pedidos-action-buttons">
                <?php if ($ordersCrossContext): ?>
                  <a class="btn btn-sm btn-primary"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo)) : ('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo))) ?>">Visualizar / Imprimir</a>
                  <a class="btn btn-sm" target="_blank" rel="noopener" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print_producao.php?id=' . $id . '&auto=1') : ('pedido_print_producao.php?id=' . $id . '&auto=1')) ?>">Produção</a>
                  <?php if ($showKitchenButton): ?><a class="btn btn-sm" target="_blank" rel="noopener" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print_cozinha.php?id=' . $id . '&auto=1') : ('pedido_print_cozinha.php?id=' . $id . '&auto=1')) ?>">Cozinha</a><?php endif; ?>
                  <?php if ($isOsBatchOrder): ?>
                    <span class="pedidos-nfce-badge wait" title="O faturamento de O.S. continua usando a fila fiscal exclusiva da O.S.">Fiscal O.S. protegido</span>
                  <?php elseif ($showFiscalNfceButton): ?>
                    <?php if ($nfceDoc): ?>
                      <a class="btn btn-sm" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_pedido.php?pedido_id=' . $id) : ('fiscal_nfce_pedido.php?pedido_id=' . $id)) ?>">Gerenciar NFC-e</a>
                    <?php endif; ?>
                    <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                      <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>" style="display:inline-flex;margin:0">
                        <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfce_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm btn-primary" type="submit"><?= $nfceDoc ? 'Reenviar NFC-e' : 'Emitir NFC-e' ?> · Homologação</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($nfceAutorizada && $nfceDoc): ?>
                      <a class="btn btn-sm"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfceDoc['id'] . '&return_to=' . rawurlencode($returnTo)) : ('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfceDoc['id'] . '&return_to=' . rawurlencode($returnTo))) ?>">Imprimir NFC-e</a>
                    <?php endif; ?>
                    <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada && !$nfeNeedsConsult): ?>
                      <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>" style="display:inline-flex;margin:0">
                        <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfe_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm btn-primary" type="submit"><?= $nfeDoc ? 'Reenviar NF-e' : 'Emitir NF-e' ?> · Homologação</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($nfeNeedsConsult && $nfeDoc): ?>
                      <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>" style="display:inline-flex;margin:0">
                        <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                        <input type="hidden" name="doc_id" value="<?= (int)$nfeDoc['id'] ?>"><input type="hidden" name="action" value="consult_status"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Consultar NF-e</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($nfeAutorizada && $nfeDoc): ?>
                      <a class="btn btn-sm"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfeDoc['id'] . '&return_to=' . rawurlencode($returnTo)) : ('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfeDoc['id'] . '&return_to=' . rawurlencode($returnTo))) ?>">Imprimir NF-e</a>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="pedidos-nfce-badge wait">Fiscal F11 somente em Homologação</span>
                  <?php endif; ?>
                <?php else: ?>
                <a class="btn btn-sm btn-primary" href="pedido_editar.php?id=<?= $id ?>">Editar</a>
                <a class="btn btn-sm"  href="pedido_print.php?id=<?= $id ?>&amp;return_to=<?= rawurlencode($returnTo) ?>">Imprimir</a>
                <a class="btn btn-sm" target="_blank" rel="noopener" href="pedido_print_producao.php?id=<?= $id ?>&auto=1">Produção</a>
                <?php if ($showKitchenButton): ?>
                  <a class="btn btn-sm" target="_blank" rel="noopener" href="pedido_print_cozinha.php?id=<?= $id ?>&auto=1">Cozinha</a>
                <?php endif; ?>
                <?php if ($showFiscalNfceButton): ?>
                  <?php if ($isOsFiscalOrder): ?>
                    <div class="pedidos-os-fiscal-actions">
                      <?php if ($osCanStartProductFiscal): ?>
                        <?php if (($orderCanEmitNfce ? 1 : 0) + ($orderCanEmitNfe ? 1 : 0) > 1): ?>
                          <div class="pedidos-fiscal-menu">
                            <button class="btn btn-sm btn-primary" type="button">Emitir mercadorias ▾</button>
                            <div class="pedidos-fiscal-menu-list">
                              <?php if ($orderCanEmitNfce): ?>
                                <form method="post" action="os_fiscal_acao.php">
                                  <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                                  <input type="hidden" name="action" value="enqueue">
                                  <input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                                  <input type="hidden" name="doc_type" value="NFCE">
                                  <input type="hidden" name="process_now" value="1">
                                  <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                                  <button class="btn btn-sm" type="submit">Gerar NFC-e</button>
                                </form>
                              <?php endif; ?>
                              <?php if ($orderCanEmitNfe): ?>
                                <form method="post" action="os_fiscal_acao.php">
                                  <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                                  <input type="hidden" name="action" value="enqueue">
                                  <input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                                  <input type="hidden" name="doc_type" value="NFE">
                                  <input type="hidden" name="process_now" value="1">
                                  <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                                  <button class="btn btn-sm" type="submit">Gerar NF-e</button>
                                </form>
                              <?php endif; ?>
                            </div>
                          </div>
                        <?php elseif ($orderCanEmitNfce || $orderCanEmitNfe): ?>
                          <form method="post" action="os_fiscal_acao.php">
                            <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                            <input type="hidden" name="action" value="enqueue">
                            <input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                            <input type="hidden" name="doc_type" value="<?= $orderCanEmitNfce ? 'NFCE' : 'NFE' ?>">
                            <input type="hidden" name="process_now" value="1">
                            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                            <button class="btn btn-sm btn-primary" type="submit">Gerar <?= $orderCanEmitNfce ? 'NFC-e' : 'NF-e' ?></button>
                          </form>
                        <?php endif; ?>
                      <?php endif; ?>
                      <?php if ($osCanStartNfse && $orderCanEmitNfse): ?>
                        <form method="post" action="os_fiscal_acao.php">
                          <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                          <input type="hidden" name="action" value="enqueue">
                          <input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                          <input type="hidden" name="doc_type" value="NFSE">
                          <input type="hidden" name="process_now" value="1">
                          <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                          <button class="btn btn-sm btn-primary" type="submit">Gerar NFS-e</button>
                        </form>
                      <?php endif; ?>
                      <?php if ($osHasAnyFiscalRecord && !$osAllRequiredAuthorized): ?>
                        <a class="btn btn-sm" href="os_faturar.php?id=<?= (int)$osFiscalOrder['os_order_id'] ?>">Gerenciar fiscal da O.S.</a>
                      <?php elseif ($osAllRequiredAuthorized): ?>
                        <a class="btn btn-sm" href="os_fiscal_documentos.php?os_id=<?= (int)$osFiscalOrder['os_order_id'] ?>">Ver documentos</a>
                      <?php endif; ?>
                    </div>
                  <?php elseif ($orderOnlyServices && $showOrderNfse): ?>
                    <?= pedidos_nfse_quick_control($id, $nfseDoc, $nfseQuickCsrfPedidos, $ordersCrossContext, $ordersMcCsrf, $ordersMcCtx, $returnTo) ?>
                  <?php elseif ($orderOnlyProducts && (($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada) || ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada))): ?>
                    <div class="pedidos-fiscal-menu">
                      <button class="btn btn-sm btn-primary" type="button">Emitir Doc Fiscal ▾</button>
                      <div class="pedidos-fiscal-menu-list">
                        <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                          <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                            <input type="hidden" name="pedido_id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="emitir_nfce_rapido">
                            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                            <button class="btn btn-sm" type="submit">Emitir NFC-e</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada): ?>
                          <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                            <input type="hidden" name="pedido_id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="emitir_nfe_rapido">
                            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                            <button class="btn btn-sm" type="submit">Emitir NF-e</button>
                          </form>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php elseif ($orderMixed): ?>
                    <div class="pedidos-fiscal-menu">
                      <button class="btn btn-sm btn-primary" type="button">Emitir Doc Fiscal ▾</button>
                      <div class="pedidos-fiscal-menu-list">
                        <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                          <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                            <input type="hidden" name="pedido_id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="emitir_nfce_rapido">
                            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                            <button class="btn btn-sm" type="submit">Emitir NFC-e</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada): ?>
                          <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                            <input type="hidden" name="pedido_id" value="<?= $id ?>">
                            <input type="hidden" name="action" value="emitir_nfe_rapido">
                            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                            <button class="btn btn-sm" type="submit">Emitir NF-e</button>
                          </form>
                        <?php endif; ?>
                        <?php if ($showOrderNfse): ?>
                          <?= pedidos_nfse_quick_control($id, $nfseDoc, $nfseQuickCsrfPedidos, $ordersCrossContext, $ordersMcCsrf, $ordersMcCtx, $returnTo) ?>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($wlink): ?>
                  <?php if (!$ordersCrossContext): ?>
                  <a class="btn btn-sm" href="pedido_whatsapp.php?id=<?= $id ?>&documentos=1&return_to=<?= rawurlencode($returnTo) ?>" title="Enviar pedido, boleto e nota fiscal pelo WhatsApp">WhatsApp</a>
                  <?php else: ?>
                  <form method="post" action="pedido_whatsapp.php" class="pedidos-whatsapp-form">
                    <input type="hidden" name="id" value="<?= $id ?>">
                    <input type="hidden" name="mode" value="api">
                    <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                    <button class="btn btn-sm" type="submit">WhatsApp</button>
                  </form>
                  <?php endif; ?>
                <?php endif; ?>
                <a class="btn btn-sm" href="email_documento.php?type=pedido&id=<?= $id ?>&return_to=<?= rawurlencode($returnTo) ?>" title="Enviar pedido por e-mail">✉ E-mail</a>
                <?php endif; ?>
              </div>
              <?php if (!$ordersCrossContext): ?>
              <form method="post" action="pedido_status.php" class="pedidos-status-form" data-order-status-form data-current-status="<?= pedidos_h($statusAtual) ?>">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="cancel_reason" value="">
                <input type="hidden" name="auto_cancel_nfse" value="0">
                <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                <select name="status" class="status-select status-<?= pedidos_h($statusAtual) ?>" data-status aria-label="Novo status do pedido #<?= $numeroPedido ?>">
                  <?php foreach ($allowedStatus as $opt): ?>
                    <option value="<?= pedidos_h($opt) ?>" <?= $statusAtual===$opt?'selected':'' ?>><?= pedidos_h(fmt_status($opt)) ?></option>
                  <?php endforeach; ?>
                </select>
                <button class="btn btn-sm btn-primary" type="submit">Trocar status</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <div class="pedidos-mobile-list<?= $ordersNewGrid ? ' pedidos-mobile-list-v2' : '' ?>"<?= $ordersNewGrid ? ' id="ordersMobileCardsV2"' : '' ?>>
    <?php if (!$rows): ?>
      <div class="pedidos-mobile-empty">Nenhum pedido encontrado para os filtros informados.</div>
    <?php endif; ?>
    <?php foreach ($rows as $r): ?>
      <?php
        $id = (int)$r['id'];
        $contractOrigin=$contractOrderMap[$id]??null;$isContractOrder=(bool)$contractOrigin;
        $numeroPedido = company_sequence_display($r, 'pedidos');
        $statusAtual = (string)($r['status'] ?? 'aberto');
        $whatsapp = preg_replace('/\D+/', '', (string)($r['whatsapp'] ?? ''));
        $wlink = $whatsapp ? ('pedido_whatsapp.php?id=' . $id) : '';
        $nfceDoc = $showFiscalNfceButton ? ($fiscalNfceDocs[$id] ?? null) : null;
        $nfeDoc = $showFiscalNfceButton ? ($fiscalNfeDocs[$id] ?? null) : null;
        $nfseDoc = $showFiscalNfceButton ? ($fiscalNfseDocs[$id] ?? null) : null;
        $composition = $orderCompositionMap[$id] ?? [];
        $orderOnlyServices = !empty($composition['only_services']);
        $orderOnlyProducts = !empty($composition['only_products']);
        $orderMixed = !empty($composition['mixed']);
        $isOsBatchOrder = strtolower(trim((string)($r['source'] ?? ''))) === 'ordem_servico_lote';
        $orderCanEmitNfce = $canEmitNfcePedidos && ($orderOnlyProducts || $orderMixed);
        $orderCanEmitNfe = $canEmitNfePedidos && ($orderOnlyProducts || $orderMixed);
        if ($ordersCrossContext && $isOsBatchOrder) { $orderCanEmitNfce = false; $orderCanEmitNfe = false; }
        $orderCanEmitNfse = $canEmitNfsePedidos && ($orderOnlyServices || $orderMixed);
        $showOrderNfse = $orderCanEmitNfse || (bool)$nfseDoc;
        $nfceAutorizada = $nfceDoc && strtoupper((string)($nfceDoc['status'] ?? '')) === 'AUTORIZADA';
        $nfeAutorizada = $nfeDoc && strtoupper((string)($nfeDoc['status'] ?? '')) === 'AUTORIZADA';
        $nfeStatus = $nfeDoc ? strtoupper(trim((string)($nfeDoc['status'] ?? ''))) : '';
        $nfeNeedsConsult = $nfeDoc && in_array($nfeStatus, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true);
        $nfceRefInfo = $showFiscalNfceButton ? ($fiscalNfceReferencedMap[$id] ?? null) : null;
        $devolutionInfo = $showFiscalNfceButton ? ($fiscalDevolutionMap[$id] ?? null) : null;
        $osFiscalOrder = $osPedidosBridge['orders'][$id] ?? null;
        $isOsFiscalOrder = (bool)$osFiscalOrder;
        $osFiscalQueues = $osPedidosBridge['queues'][$id] ?? [];
        $osNfeQueue = $osFiscalQueues['NFE'] ?? null;
        $osNfceQueue = $osFiscalQueues['NFCE'] ?? null;
        $osNfseQueue = $osFiscalQueues['NFSE'] ?? null;
        $osRequiresProduct = $isOsFiscalOrder && (int)($osFiscalOrder['requires_product_fiscal'] ?? 0) === 1;
        $osRequiresService = $isOsFiscalOrder && (int)($osFiscalOrder['requires_service_fiscal'] ?? 0) === 1;
        if ($isOsFiscalOrder) {
          $orderCanEmitNfce = $canEmitNfcePedidos && $osRequiresProduct;
          $orderCanEmitNfe = $canEmitNfePedidos && $osRequiresProduct;
          $orderCanEmitNfse = $canEmitNfsePedidos && $osRequiresService;
          $showOrderNfse = $osRequiresService || (bool)$nfseDoc;
        }
        if ($ordersCrossContext && $isOsBatchOrder) {
          $orderCanEmitNfce = false;
          $orderCanEmitNfe = false;
          $orderCanEmitNfse = false;
          $showOrderNfse = (bool)$nfseDoc;
        }
        $osCanStartProductFiscal = $isOsFiscalOrder && $osRequiresProduct && !$nfeDoc && !$nfceDoc
          && !os_pedidos_queue_blocks_new_generation($osNfeQueue) && !os_pedidos_queue_blocks_new_generation($osNfceQueue);
        $osCanStartNfse = $isOsFiscalOrder && $osRequiresService && !$nfseDoc
          && !os_pedidos_queue_blocks_new_generation($osNfseQueue);
        $osHasAnyFiscalRecord = $isOsFiscalOrder && (bool)($nfeDoc || $nfceDoc || $nfseDoc || $osNfeQueue || $osNfceQueue || $osNfseQueue);
        $osAllRequiredAuthorized = $isOsFiscalOrder
          && (!$osRequiresProduct || $nfceAutorizada || $nfeAutorizada)
          && (!$osRequiresService || ($nfseDoc && strtoupper((string)($nfseDoc['nfse_status'] ?? $nfseDoc['status'] ?? '')) === 'AUTORIZADA'));
        $prevista = '';
        if ($hasScheduledDate && !empty($r['scheduled_delivery_date'])) {
          $prevista = date('d/m/Y', strtotime((string)$r['scheduled_delivery_date']));
          if ($hasScheduledTime && !empty($r['scheduled_delivery_time'])) $prevista .= ' ' . substr((string)$r['scheduled_delivery_time'], 0, 5);
        }
      ?>
      <article class="pedidos-mobile-card<?= $ordersNewGrid ? ' pedidos-mobile-card-v2' : '' ?>"<?= $ordersNewGrid ? (' data-mobile-order-id="' . $id . '" role="link" tabindex="0"') : '' ?>>
        <div class="pedidos-mobile-head">
          <div><span class="pedidos-mobile-label">Nº</span><strong><?php if($isContractOrder && !$ordersCrossContext):?><input class="contract-select" type="checkbox" name="order_ids[]" value="<?=$id?>" data-total-cents="<?= (int)round(((float)($r['total'] ?? 0)) * 100) ?>" form="contractBatchForm"> <?php endif;?><a class="idlink" href="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo)) : ('pedido_editar.php?id=' . $id)) ?>">#<?=$numeroPedido?></a></strong><?php if($isContractOrder):?><span class="contract-origin">Contrato #<?=pedidos_h($contractOrigin['contract_number'])?></span><?php if((int)($contractOrigin['chart_account_id']??0)<=0):?><span class="contract-plan-warning" title="O contrato associado não possui plano de contas cadastrado atualmente.">Sem plano de contas</span><?php endif;?><?php endif;?><?php if($isOsBatchOrder):?><span class="contract-origin">Fatura de O.S.</span><?php endif;?></div>
          <span class="<?= pedidos_h(pedidos_status_badge_class($statusAtual)) ?>"><?= pedidos_h(fmt_status($statusAtual)) ?></span>
        </div>
        <div class="pedidos-mobile-client">
          <span class="pedidos-mobile-label">Cliente</span>
          <strong><?= pedidos_h($r['nome_cliente'] ?? '') ?></strong>
          <span class="muted"><?= pedidos_h($r['whatsapp'] ?? '') ?></span>
        </div>
        <div class="pedidos-mobile-grid">
          <div class="item total"><span>Total</span><strong><?= pedidos_money_br($r['total'] ?? 0) ?></strong></div>
          <div class="item"><span>Entrega</span><strong><?= pedidos_h($r['tipo_entrega'] ?? '') ?></strong></div>
          <div class="item"><span>Pagamento</span><strong><?= pedidos_h($r['forma_pagamento'] ?? '') ?></strong></div>
          <div class="item"><span>Data</span><strong><?= pedidos_h(fmt_dt_br((string)($r['created_at'] ?? ''))) ?></strong></div>
          <?php if ($showFiscalNfceButton): ?>
            <div class="item wide pedidos-mobile-fiscal">
              <span>Fiscal</span>
              <div class="pedidos-fiscal-summary-values">
                <?= pedidos_fiscal_badges($nfceDoc, $nfeDoc, $orderCanEmitNfce, $orderCanEmitNfe, $nfceRefInfo, $devolutionInfo) ?>
                <?php if ($showOrderNfse): ?><?= pedidos_nfse_badge($nfseDoc, $orderCanEmitNfse) ?><?php endif; ?>
              </div>
            </div>
          <?php endif; ?>
          <?php if ($hasScheduledDate): ?>
            <div class="item wide"><span>Data/Hora prevista</span><strong><?= pedidos_h($prevista !== '' ? $prevista : '-') ?></strong></div>
          <?php endif; ?>
        </div>
        <div class="pedidos-mobile-actions">
          <span class="pedidos-mobile-actions-title">Ações</span>
          <?php if ($ordersNewGrid): ?>
            <div class="pedidos-mobile-icon-host" data-mobile-actions-for="<?= $id ?>" aria-label="Ações do pedido #<?= pedidos_h((string)$numeroPedido) ?>"></div>
          <?php endif; ?>
          <div class="pedidos-mobile-buttons">
            <?php if ($ordersCrossContext): ?>
              <a class="btn btn-sm btn-primary"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo)) : ('pedido_print.php?id=' . $id . '&autoprint=0&return_to=' . rawurlencode($returnTo))) ?>">Visualizar / Imprimir</a>
              <a class="btn btn-sm" target="_blank" rel="noopener" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print_producao.php?id=' . $id . '&auto=1') : ('pedido_print_producao.php?id=' . $id . '&auto=1')) ?>">Produção</a>
              <?php if ($showKitchenButton): ?><a class="btn btn-sm" target="_blank" rel="noopener" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print_cozinha.php?id=' . $id . '&auto=1') : ('pedido_print_cozinha.php?id=' . $id . '&auto=1')) ?>">Cozinha</a><?php endif; ?>
              <?php if ($isOsBatchOrder): ?>
                <span class="pedidos-nfce-badge wait">Fiscal O.S. protegido</span>
              <?php elseif ($showFiscalNfceButton): ?>
                <?php if ($nfceDoc): ?><a class="btn btn-sm" href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_pedido.php?pedido_id=' . $id) : ('fiscal_nfce_pedido.php?pedido_id=' . $id)) ?>">Gerenciar NFC-e</a><?php endif; ?>
                <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                  <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                    <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                    <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfce_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                    <button class="btn btn-sm btn-primary" type="submit"><?= $nfceDoc ? 'Reenviar NFC-e' : 'Emitir NFC-e' ?> · Homologação</button>
                  </form>
                <?php endif; ?>
                <?php if ($nfceAutorizada && $nfceDoc): ?><a class="btn btn-sm"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfceDoc['id'] . '&return_to=' . rawurlencode($returnTo)) : ('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfceDoc['id'] . '&return_to=' . rawurlencode($returnTo))) ?>">Imprimir NFC-e</a><?php endif; ?>
                <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada && !$nfeNeedsConsult): ?>
                  <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                    <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                    <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfe_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                    <button class="btn btn-sm btn-primary" type="submit"><?= $nfeDoc ? 'Reenviar NF-e' : 'Emitir NF-e' ?> · Homologação</button>
                  </form>
                <?php endif; ?>
                <?php if ($nfeNeedsConsult && $nfeDoc): ?>
                  <form method="post" action="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                    <input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>">
                    <input type="hidden" name="doc_id" value="<?= (int)$nfeDoc['id'] ?>"><input type="hidden" name="action" value="consult_status"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                    <button class="btn btn-sm" type="submit">Consultar NF-e</button>
                  </form>
                <?php endif; ?>
                <?php if ($nfeAutorizada && $nfeDoc): ?><a class="btn btn-sm"  href="<?= pedidos_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfeDoc['id'] . '&return_to=' . rawurlencode($returnTo)) : ('fiscal_nfce_imprimir.php?doc_id=' . (int)$nfeDoc['id'] . '&return_to=' . rawurlencode($returnTo))) ?>">Imprimir NF-e</a><?php endif; ?>
              <?php else: ?>
                <span class="pedidos-nfce-badge wait">Fiscal F11 somente em Homologação</span>
              <?php endif; ?>
            <?php else: ?>
            <a class="btn btn-sm btn-primary" href="pedido_editar.php?id=<?= $id ?>">Editar</a>
            <a class="btn btn-sm"  href="pedido_print.php?id=<?= $id ?>&amp;return_to=<?= rawurlencode($returnTo) ?>">Imprimir</a>
            <a class="btn btn-sm" target="_blank" rel="noopener" href="pedido_print_producao.php?id=<?= $id ?>&auto=1">Produção</a>
                <?php if ($showKitchenButton): ?>
                  <a class="btn btn-sm" target="_blank" rel="noopener" href="pedido_print_cozinha.php?id=<?= $id ?>&auto=1">Cozinha</a>
                <?php endif; ?>
            <?php if ($showFiscalNfceButton): ?>
              <?php if ($isOsFiscalOrder): ?>
                <?php if ($osCanStartProductFiscal): ?>
                  <?php if ($orderCanEmitNfce): ?>
                    <form method="post" action="os_fiscal_acao.php">
                      <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                      <input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                      <input type="hidden" name="doc_type" value="NFCE"><input type="hidden" name="process_now" value="1"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                      <button class="btn btn-sm btn-primary" type="submit">Gerar NFC-e</button>
                    </form>
                  <?php endif; ?>
                  <?php if ($orderCanEmitNfe): ?>
                    <form method="post" action="os_fiscal_acao.php">
                      <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                      <input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                      <input type="hidden" name="doc_type" value="NFE"><input type="hidden" name="process_now" value="1"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                      <button class="btn btn-sm btn-primary" type="submit">Gerar NF-e</button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
                <?php if ($osCanStartNfse && $orderCanEmitNfse): ?>
                  <form method="post" action="os_fiscal_acao.php">
                    <input type="hidden" name="csrf" value="<?= pedidos_h($osFiscalCsrfPedidos) ?>">
                    <input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="<?= (int)$osFiscalOrder['os_order_id'] ?>">
                    <input type="hidden" name="doc_type" value="NFSE"><input type="hidden" name="process_now" value="1"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                    <button class="btn btn-sm btn-primary" type="submit">Gerar NFS-e</button>
                  </form>
                <?php endif; ?>
                <?php if ($osHasAnyFiscalRecord && !$osAllRequiredAuthorized): ?>
                  <a class="btn btn-sm" href="os_faturar.php?id=<?= (int)$osFiscalOrder['os_order_id'] ?>">Gerenciar fiscal O.S.</a>
                <?php elseif ($osAllRequiredAuthorized): ?>
                  <a class="btn btn-sm" href="os_fiscal_documentos.php?os_id=<?= (int)$osFiscalOrder['os_order_id'] ?>">Ver documentos</a>
                <?php endif; ?>
              <?php elseif ($orderOnlyServices && $showOrderNfse): ?>
                <?= pedidos_nfse_quick_control($id, $nfseDoc, $nfseQuickCsrfPedidos, $ordersCrossContext, $ordersMcCsrf, $ordersMcCtx, $returnTo) ?>
              <?php elseif ($orderOnlyProducts && (($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada) || ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada))): ?>
                <div class="pedidos-fiscal-menu">
                  <button class="btn btn-sm btn-primary" type="button">Emitir Doc Fiscal ▾</button>
                  <div class="pedidos-fiscal-menu-list">
                    <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                      <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfce_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Emitir NFC-e</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada): ?>
                      <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfe_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Emitir NF-e</button>
                      </form>
                    <?php endif; ?>
                  </div>
                </div>
              <?php elseif ($orderMixed): ?>
                <div class="pedidos-fiscal-menu">
                  <button class="btn btn-sm btn-primary" type="button">Emitir Doc Fiscal ▾</button>
                  <div class="pedidos-fiscal-menu-list">
                    <?php if ($orderCanEmitNfce && !$nfceAutorizada && !$nfeAutorizada): ?>
                      <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfce_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Emitir NFC-e</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($orderCanEmitNfe && !$nfeAutorizada && !$nfceAutorizada): ?>
                      <form method="post" action="<?= pedidos_h($ordersCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                            <?php if ($ordersCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= pedidos_h($ordersMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= pedidos_h($ordersMcCtx) ?>"><?php endif; ?>
                        <input type="hidden" name="pedido_id" value="<?= $id ?>"><input type="hidden" name="action" value="emitir_nfe_rapido"><input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Emitir NF-e</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($showOrderNfse): ?>
                      <?= pedidos_nfse_quick_control($id, $nfseDoc, $nfseQuickCsrfPedidos, $ordersCrossContext, $ordersMcCsrf, $ordersMcCtx, $returnTo) ?>
                    <?php endif; ?>
                  </div>
                </div>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($wlink): ?>
              <?php if (!$ordersCrossContext): ?>
              <a class="btn btn-sm" href="pedido_whatsapp.php?id=<?= $id ?>&documentos=1&return_to=<?= rawurlencode($returnTo) ?>" title="Enviar pedido, boleto e nota fiscal pelo WhatsApp">WhatsApp</a>
              <?php else: ?>
              <form method="post" action="pedido_whatsapp.php">
                <input type="hidden" name="id" value="<?= $id ?>">
                <input type="hidden" name="mode" value="api">
                <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
                <button class="btn btn-sm" type="submit">WhatsApp</button>
              </form>
              <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn-sm" href="email_documento.php?type=pedido&id=<?= $id ?>&return_to=<?= rawurlencode($returnTo) ?>" title="Enviar pedido por e-mail">✉ E-mail</a>
            <?php endif; ?>
          </div>
          <?php if (!$ordersCrossContext): ?>
          <form method="post" action="pedido_status.php" class="pedidos-status-form" data-order-status-form data-current-status="<?= pedidos_h($statusAtual) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <input type="hidden" name="cancel_reason" value="">
            <input type="hidden" name="auto_cancel_nfse" value="0">
            <input type="hidden" name="return_to" value="<?= pedidos_h($returnTo) ?>">
            <select name="status" class="status-select status-<?= pedidos_h($statusAtual) ?>" data-status aria-label="Novo status do pedido #<?= $numeroPedido ?>">
              <?php foreach ($allowedStatus as $opt): ?>
                <option value="<?= pedidos_h($opt) ?>" <?= $statusAtual===$opt?'selected':'' ?>><?= pedidos_h(fmt_status($opt)) ?></option>
              <?php endforeach; ?>
            </select>
            <button class="btn btn-sm btn-primary" type="submit">Trocar status</button>
          </form>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
  <?php if ($ordersNewGrid && $ordersGridPageCount > 1): ?>
  <nav class="orders-grid-pagination" aria-label="Páginas da consulta de pedidos">
    <span>Página <?= (int)$ordersGridPage ?> de <?= (int)$ordersGridPageCount ?> ·
      <?= number_format($ordersGridOffset + 1, 0, ',', '.') ?>–<?= number_format($ordersGridOffset + count($rows), 0, ',', '.') ?> de <?= number_format($ordersGridTotalMatches, 0, ',', '.') ?> pedidos
    </span>
    <?php if ($ordersGridPage > 1): ?><a class="btn btn-sm" href="<?= pedidos_h($ordersGridPageUrl($ordersGridPage - 1, $ordersGridPerPage)) ?>">← Anterior</a><?php endif; ?>
    <?php for ($pageLink = max(1, $ordersGridPage - 2); $pageLink <= min($ordersGridPageCount, $ordersGridPage + 2); $pageLink++): ?>
      <a class="btn btn-sm <?= $pageLink === $ordersGridPage ? 'btn-primary' : '' ?>" href="<?= pedidos_h($ordersGridPageUrl($pageLink, $ordersGridPerPage)) ?>" <?= $pageLink === $ordersGridPage ? 'aria-current="page"' : '' ?>><?= (int)$pageLink ?></a>
    <?php endfor; ?>
    <?php if ($ordersGridPage < $ordersGridPageCount): ?><a class="btn btn-sm" href="<?= pedidos_h($ordersGridPageUrl($ordersGridPage + 1, $ordersGridPerPage)) ?>">Próxima →</a><?php endif; ?>
  </nav>
  <?php endif; ?>
</div>

<?php if ($ordersNewGrid): ?>
<script>
window.nexaOrdersGridConfig = <?= json_encode([
  'company' => $ordersCompanyId,
  'csrf' => $ordersGridCsrf,
  'cross' => $ordersCrossContext,
  'preferences' => $ordersGridSavedPrefs,
], JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/pedidos_actions_modal_v1.js?v=20261022_1805" defer></script>
<script src="assets/pedidos_grid_v2.js?v=20261108_min40" defer></script>
<script src="assets/pedidos_mobile_cards_v2.js?v=20261022_1805_modal" defer></script>
<?php endif; ?>
<script>
  (function(){
    function sync(sel){
      if(!sel) return;
      var v = sel.value || 'aberto';
      sel.className = 'status-select status-' + v;
    }
    var cc = function(){ return Array.prototype.slice.call(document.querySelectorAll('.contract-select')); };
    var totalEl = document.getElementById('pedidosSelectedTotal');
    var brl = new Intl.NumberFormat('pt-BR', {style:'currency',currency:'BRL'});
    function updateContractSelection(){
      // A tabela e os cartões mobile possuem checkbox para o mesmo pedido.
      // Contabiliza cada pedido somente uma vez, independente do layout visível.
      var unique = new Map();
      cc().forEach(function(c){
        if(c.checked && !unique.has(c.value)) unique.set(c.value, Number(c.dataset.totalCents || 0));
      });
      var cents = 0;
      unique.forEach(function(v){ cents += v; });
      if(totalEl) totalEl.textContent = 'Selecionados: ' + unique.size + ' · ' + brl.format(cents / 100);
      return unique.size;
    }
    cc().forEach(function(c){
      c.addEventListener('change', function(){
        cc().forEach(function(other){ if(other.value === c.value && other !== c) other.checked = c.checked; });
        updateContractSelection();
      });
    });
    var sa = document.getElementById('contractSelectAll'), ca = document.getElementById('contractClearAll');
    if(sa) sa.onclick = function(){ cc().forEach(function(c){ c.checked = true; }); updateContractSelection(); };
    if(ca) ca.onclick = function(){ cc().forEach(function(c){ c.checked = false; }); updateContractSelection(); };
    var bf = document.getElementById('contractBatchForm');
    if(bf) bf.addEventListener('submit', function(e){
      if(!updateContractSelection()){ e.preventDefault(); alert('Selecione pelo menos um pedido de contrato.'); }
    });
    updateContractSelection();
    document.querySelectorAll('select[data-status]').forEach(function(sel){
      sync(sel);
      sel.addEventListener('change', function(){ sync(sel); });
    });

    document.querySelectorAll('[data-order-status-form]').forEach(function(form){
      form.addEventListener('submit', function(ev){
        var sel = form.querySelector('select[name="status"]');
        var current = form.getAttribute('data-current-status') || '';
        if(!sel || sel.value !== 'cancelado' || current === 'cancelado') return;
        var motivo = window.prompt('Informe o motivo do cancelamento do pedido. Se houver NFS-e Pronim/GOVBR autorizada, ela será cancelada fiscalmente antes do pedido:', 'Cancelado pela troca de status');
        if(motivo === null || !motivo.trim()){
          ev.preventDefault();
          alert('Para cancelar o pedido, informe o motivo do cancelamento.');
          return;
        }
        if(!window.confirm('Confirma o cancelamento do pedido? Se existir NFS-e Pronim/GOVBR AUTORIZADA, o Nexa enviará primeiro o evento fiscal de cancelamento e só cancelará o pedido se a Prefeitura confirmar.')){
          ev.preventDefault();
          return;
        }
        var hidden = form.querySelector('input[name="cancel_reason"]');
        if(hidden) hidden.value = motivo.trim();
        var fiscalConfirm = form.querySelector('input[name="auto_cancel_nfse"]');
        if(fiscalConfirm) fiscalConfirm.value = '1';
      });
    });
  })();
</script>

<?php admin_footer(); ?>
