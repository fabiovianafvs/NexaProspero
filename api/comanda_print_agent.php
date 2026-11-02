<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';
require_once __DIR__ . '/../config/tenant.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

function cpa_json(array $payload, int $status = 200): never {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function cpa_bearer_token(): string {
  $direct = trim((string)($_SERVER['HTTP_X_AGENT_TOKEN'] ?? ''));
  if ($direct !== '') return $direct;
  $header = trim((string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? ''));
  if ($header === '' && function_exists('getallheaders')) {
    $headers = getallheaders();
    $header = trim((string)($headers['Authorization'] ?? $headers['authorization'] ?? ''));
  }
  if (preg_match('/^Bearer\s+(.+)$/i', $header, $m)) return trim((string)$m[1]);
  return trim((string)($_REQUEST['token'] ?? ''));
}

function cpa_body(): array {
  $raw = file_get_contents('php://input');
  if (is_string($raw) && trim($raw) !== '') {
    $json = json_decode($raw, true);
    if (is_array($json)) return $json;
  }
  return $_POST;
}

function cpa_header(string $name): string {
  $key = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
  return trim((string)($_SERVER[$key] ?? ''));
}

try {
  comanda_ensure_schema($pdo);
  $token = cpa_bearer_token();
  $agent = comanda_print_agent_authenticate($pdo, $token);
  if (!$agent) cpa_json(['ok'=>false,'error'=>'Token do agente inválido ou desativado.'], 401);

  $companyId = (int)$agent['company_id'];
  $companyRow = finance_query_one($pdo, 'SELECT * FROM companies WHERE id=? LIMIT 1', [$companyId]);
  if (!$companyRow) cpa_json(['ok'=>false,'error'=>'Empresa do agente não encontrada.'], 404);
  $accessStatus = tenant_access_status($pdo, $companyRow);
  if (empty($accessStatus['allowed'])) cpa_json(['ok'=>false,'error'=>'A empresa está temporariamente sem acesso ao sistema.'], 403);
  if (!(saas_company_has_module($pdo, $companyId, 'comandas') || saas_company_has_module($pdo, $companyId, 'gestao'))) {
    cpa_json(['ok'=>false,'error'=>'O módulo de comandas não está liberado para esta empresa.'], 403);
  }

  $machine = cpa_header('X-Agent-Machine');
  $version = cpa_header('X-Agent-Version');
  $remoteIp = trim((string)($_SERVER['REMOTE_ADDR'] ?? ''));
  comanda_print_agent_touch($pdo, $agent, $machine, $version, $remoteIp);

  $action = strtolower(trim((string)($_GET['action'] ?? $_POST['action'] ?? 'ping')));
  $allowedGroups = comanda_print_agent_groups($agent);

  if ($action === 'ping') {
    $company = $companyRow;
    cpa_json([
      'ok'=>true,
      'server_time'=>date('c'),
      'company'=>['id'=>$companyId,'name'=>(string)($company['name'] ?? ''),'slug'=>(string)($company['slug'] ?? '')],
      'agent'=>['id'=>(int)$agent['id'],'name'=>(string)$agent['name'],'groups'=>$allowedGroups],
    ]);
  }

  if ($action === 'diagnostics') {
    $rows = [];
    try {
      $stDiag = $pdo->prepare("SELECT group_name, status, COUNT(*) AS qtd FROM comanda_print_jobs WHERE company_id=? GROUP BY group_name,status ORDER BY group_name,status");
      $stDiag->execute([$companyId]);
      $rows = $stDiag->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    cpa_json([
      'ok'=>true,
      'server_time'=>date('c'),
      'company'=>['id'=>$companyId,'name'=>(string)($companyRow['name'] ?? ''),'slug'=>(string)($companyRow['slug'] ?? '')],
      'agent'=>[
        'id'=>(int)$agent['id'],
        'name'=>(string)$agent['name'],
        'groups'=>comanda_print_agent_groups($agent),
        'machine'=>(string)($agent['machine_name'] ?? ''),
        'version'=>(string)($agent['agent_version'] ?? ''),
      ],
      'queue'=>$rows,
    ]);
  }

  if ($action === 'sync_groups') {
    $body = cpa_body();
    $reportedRaw = $body['groups'] ?? ($_GET['groups'] ?? '');
    $reported = is_array($reportedRaw)
      ? $reportedRaw
      : preg_split('/\s*[,;|]\s*/u', trim((string)$reportedRaw), -1, PREG_SPLIT_NO_EMPTY);
    $agent = comanda_print_agent_sync_reported_groups($pdo, $agent, is_array($reported) ? $reported : []);
    // Na inicialização/sincronização do agente, reconstrói eventuais trabalhos
    // ausentes de pedidos com mais de um setor, sem reenviar os já impressos.
    comanda_print_queue_reconcile_recent($pdo, $companyId, 80);
    cpa_json(['ok'=>true,'server_time'=>date('c'),'groups'=>comanda_print_agent_groups($agent)]);
  }

  if ($action === 'next_batch') {
    $reportedRaw = trim((string)($_GET['groups'] ?? ''));
    $reported = $reportedRaw === '' ? [] : preg_split('/\s*[,;|]\s*/u', $reportedRaw, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($reported) && $reported) {
      $agent = comanda_print_agent_sync_reported_groups($pdo, $agent, $reported);
    }
    $limit = max(1, min(50, (int)($_GET['limit'] ?? 50)));
    $jobs = comanda_print_jobs_next_batch_for_agent($pdo, $agent, is_array($reported) ? $reported : [], $limit);
    cpa_json([
      'ok'=>true,
      'server_time'=>date('c'),
      'jobs'=>$jobs,
      // Mantém o primeiro trabalho também em `job` para facilitar diagnóstico
      // e compatibilidade com integrações que ainda esperem um item único.
      'job'=>$jobs[0] ?? null,
      'groups'=>comanda_print_agent_groups($agent),
    ]);
  }

  if ($action === 'next') {
    $reportedRaw = trim((string)($_GET['groups'] ?? ''));
    $reported = $reportedRaw === '' ? [] : preg_split('/\s*[,;|]\s*/u', $reportedRaw, -1, PREG_SPLIT_NO_EMPTY);
    if (is_array($reported) && $reported) {
      $agent = comanda_print_agent_sync_reported_groups($pdo, $agent, $reported);
    }
    // Também reconcilia na rota antiga, para agentes anteriores receberem
    // grupos que possam ter ficado ausentes.
    comanda_print_queue_reconcile_recent($pdo, $companyId, 150);
    $job = comanda_print_job_next_for_agent($pdo, $agent, is_array($reported) ? $reported : []);
    cpa_json(['ok'=>true,'server_time'=>date('c'),'job'=>$job,'groups'=>comanda_print_agent_groups($agent)]);
  }

  if ($action === 'finish') {
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') cpa_json(['ok'=>false,'error'=>'Método inválido.'], 405);
    $body = cpa_body();
    $jobId = (int)($body['job_id'] ?? 0);
    if ($jobId <= 0) throw new RuntimeException('Informe o trabalho de impressão.');
    $okRaw = $body['ok'] ?? false;
    $printed = filter_var($okRaw, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE);
    if ($printed === null) $printed = in_array(strtoupper(trim((string)$okRaw)), ['1','SIM','TRUE','OK','IMPRESSO'], true);
    $result = comanda_print_job_finish_for_agent($pdo, $agent, $jobId, (bool)$printed, (string)($body['error'] ?? ''), (string)($body['printer'] ?? ''));
    cpa_json(['ok'=>true,'result'=>$result]);
  }

  cpa_json(['ok'=>false,'error'=>'Ação não reconhecida.'], 404);
} catch (Throwable $e) {
  cpa_json(['ok'=>false,'error'=>$e->getMessage()], 422);
}
