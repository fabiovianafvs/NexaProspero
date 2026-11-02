<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';

header('Content-Type: application/json; charset=utf-8');
$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_printing')
  : (int)COMPANY_ID;
$printCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_printing');
if ($printCross) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
if ($printCross && function_exists('multi_company_context_permission_allowed')
    && !multi_company_context_permission_allowed($pdo, 'gestao_comandas')) {
  http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Sem permissão para a fila de impressão na empresa selecionada pelo F11.'], JSON_UNESCAPED_UNICODE); exit;
}
saas_require_company_module($pdo, $companyId, 'comandas', 'Fila de impressão de comandas');
if (!$printCross) comanda_ensure_schema($pdo);
$action = (string)($_REQUEST['action'] ?? 'status');
if ($printCross && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  if (!in_array($action, ['mark','retry','rebuild'], true)) { http_response_code(403); echo json_encode(['ok'=>false,'error'=>'Ação não homologada no F11.'], JSON_UNESCAPED_UNICODE); exit; }
  if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) {
    http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Contexto Multiempresa expirado. Atualize o Monitor e tente novamente.'], JSON_UNESCAPED_UNICODE); exit;
  }
}
// V11.31: a contingência pelo navegador retira um trabalho da fila (efeito de escrita).
// No contexto F11, mesmo esta chamada GET exige o token CSRF do contexto Multiempresa.
if ($printCross && $action === 'next') {
  if (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_GET['mc_csrf'] ?? ''))) {
    http_response_code(419); echo json_encode(['ok'=>false,'error'=>'Contexto Multiempresa expirado para retirar trabalho da fila. Atualize o Monitor e tente novamente.'], JSON_UNESCAPED_UNICODE); exit;
  }
}
try {
  // Libera trabalhos que ficaram presos por fechamento do navegador ou queda de conexão.
  // Em contexto F11 não há mutação silenciosa em GET/status: a liberação só ocorre
  // durante a reconstrução explícita da fila (POST + CSRF).
  if (!$printCross || $action === 'rebuild') {
    try {
      $pdo->prepare("UPDATE comanda_print_jobs pj LEFT JOIN comanda_print_agents pa ON pa.company_id=pj.company_id AND pa.id=pj.agent_id SET pj.status='PENDENTE', pj.agent_id=NULL, pj.claimed_by=NULL, pj.claimed_at=NULL, pj.next_attempt_at=NOW(), pj.error_message='Recolocado automaticamente na fila', pj.updated_at=NOW() WHERE pj.company_id=? AND pj.status='PROCESSANDO' AND pj.claimed_at < DATE_SUB(NOW(), INTERVAL 3 MINUTE) AND (pj.agent_id IS NULL OR pa.id IS NULL OR pa.is_active<>1 OR pa.last_seen_at IS NULL OR pa.last_seen_at < DATE_SUB(NOW(), INTERVAL 90 SECOND))")->execute([$companyId]);
    } catch (Throwable $e) {}
  }

  if ($action === 'next') {
    $group = trim((string)($_GET['group'] ?? ''));
    $agentOnline = false;
    foreach (comanda_print_agent_rows($pdo, $companyId) as $agentRow) {
      if (empty($agentRow['is_active']) || empty($agentRow['last_seen_at']) || time() - (int)strtotime((string)$agentRow['last_seen_at']) > 90) continue;
      $allowed = comanda_print_agent_groups($agentRow);
      if ($group === '__all__' || $group === '' || in_array($group, $allowed, true)) { $agentOnline = true; break; }
    }
    if ($agentOnline && empty($_GET['force'])) {
      echo json_encode(['ok'=>true,'job'=>null,'agent_online'=>true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
    }
    $job = comanda_print_job_next($pdo, $companyId, $group === '__all__' ? '' : $group);
    echo json_encode(['ok'=>true,'agent_online'=>$agentOnline,'job'=>$job ? [
      'id'=>(int)$job['id'], 'group'=>(string)$job['group_name'], 'printer'=>(string)($job['printer_name'] ?? ''),
      'comanda'=>(string)$job['comanda_codigo'], 'customer'=>(string)($job['customer_name'] ?? ''),
      'url'=>function_exists('multi_company_context_url') ? multi_company_context_url('pedido_print_cozinha.php?job_id=' . (int)$job['id'] . '&auto=1&monitor=1') : ('pedido_print_cozinha.php?job_id=' . (int)$job['id'] . '&auto=1&monitor=1')
    ] : null], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit;
  }

  if ($action === 'mark') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido.');
    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) throw new RuntimeException('Impressão inválida.');
    $status = (string)($_POST['status'] ?? 'IMPRESSO');
    comanda_print_job_mark($pdo, $companyId, $jobId, $status, (string)($_POST['error'] ?? ''));
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
  }

  if ($action === 'retry') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido.');
    $jobId = (int)($_POST['job_id'] ?? 0);
    if ($jobId <= 0) throw new RuntimeException('Impressão inválida.');
    comanda_print_job_reprint($pdo, $companyId, $jobId);
    echo json_encode(['ok'=>true], JSON_UNESCAPED_UNICODE); exit;
  }

  if ($action === 'rebuild') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') throw new RuntimeException('Método inválido.');
    comanda_print_queue_reconcile_recent($pdo, $companyId, 300);
    $row = finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? AND status='PENDENTE'", [$companyId]);
    echo json_encode(['ok'=>true,'pending'=>(int)($row['qtd'] ?? 0)], JSON_UNESCAPED_UNICODE); exit;
  }

  $group = trim((string)($_GET['group'] ?? ''));
  $today = date('Y-m-d');
  $recentFrom = trim((string)($_GET['recent_from'] ?? $today));
  $recentTo = trim((string)($_GET['recent_to'] ?? $today));
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recentFrom)) $recentFrom = $today;
  if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $recentTo)) $recentTo = $today;
  if ($recentFrom > $recentTo) [$recentFrom, $recentTo] = [$recentTo, $recentFrom];
  $where = 'company_id=?'; $params = [$companyId];
  if ($group !== '' && $group !== '__all__') { $where .= ' AND group_name=?'; $params[] = $group; }
  $st = $pdo->prepare("SELECT status, COUNT(*) AS qtd FROM comanda_print_jobs WHERE $where GROUP BY status");
  $st->execute($params); $counts = ['PENDENTE'=>0,'PROCESSANDO'=>0,'IMPRESSO'=>0,'ERRO'=>0];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $counts[(string)$r['status']] = (int)$r['qtd'];
  $sql = "SELECT pj.*, COALESCE(pj.printed_at,pj.updated_at,pj.created_at) AS event_at, c.codigo AS comanda_codigo, po.customer_name FROM comanda_print_jobs pj JOIN comandas c ON c.company_id=pj.company_id AND c.id=pj.comanda_id LEFT JOIN comanda_public_orders po ON po.company_id=pj.company_id AND po.id=pj.public_order_id WHERE pj.company_id=?";
  $p = [$companyId]; if ($group !== '' && $group !== '__all__') { $sql .= ' AND pj.group_name=?'; $p[]=$group; }
  $recentToExclusive = (new DateTimeImmutable($recentTo))->modify('+1 day')->format('Y-m-d');
  $sql .= ' AND COALESCE(pj.printed_at,pj.updated_at,pj.created_at) >= ? AND COALESCE(pj.printed_at,pj.updated_at,pj.created_at) < ?';
  $p[] = $recentFrom . ' 00:00:00'; $p[] = $recentToExclusive . ' 00:00:00';
  $sql .= ' ORDER BY COALESCE(pj.printed_at,pj.updated_at,pj.created_at) DESC, pj.id DESC LIMIT 200';
  $st2=$pdo->prepare($sql);$st2->execute($p);$recent=$st2->fetchAll(PDO::FETCH_ASSOC)?:[];
  $groupCounts = [];
  try {
    $stGroups = $pdo->prepare("SELECT group_name,status,COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? GROUP BY group_name,status ORDER BY group_name,status");
    $stGroups->execute([$companyId]);
    foreach (($stGroups->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
      $g = (string)($r['group_name'] ?? ''); if ($g === '') continue;
      $groupCounts[$g] ??= ['PENDENTE'=>0,'PROCESSANDO'=>0,'IMPRESSO'=>0,'ERRO'=>0];
      $groupCounts[$g][(string)$r['status']] = (int)$r['qtd'];
    }
  } catch (Throwable $e) {}
  $agents = [];
  foreach (comanda_print_agent_rows($pdo, $companyId) as $a) {
    $last = (string)($a['last_seen_at'] ?? '');
    $agents[] = [
      'id'=>(int)$a['id'], 'name'=>(string)$a['name'], 'groups'=>comanda_print_agent_groups($a),
      'machine'=>(string)($a['machine_name'] ?? ''), 'version'=>(string)($a['agent_version'] ?? ''),
      'last_seen'=>$last, 'online'=>$last !== '' && time() - (int)strtotime($last) <= 90, 'active'=>!empty($a['is_active'])
    ];
  }
  echo json_encode(['ok'=>true,'counts'=>$counts,'group_counts'=>$groupCounts,'recent'=>$recent,'agents'=>$agents], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
  http_response_code(422);
  echo json_encode(['ok'=>false,'error'=>$e->getMessage()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
