<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/brain.php';
require_once $root . '/config/brain_realtime.php';

$options = getopt('', ['once', 'batch::', 'sleep::', 'max-attempts::', 'help']);
if (isset($options['help'])) {
  echo "Nexa Brain Outbox Worker\n";
  echo "php workers/brain_outbox_worker.php [--once] [--batch=50] [--sleep=2] [--max-attempts=10]\n";
  exit(0);
}

$once = array_key_exists('once', $options);
$batchSize = max(1, min(500, (int)($options['batch'] ?? 50)));
$sleepSeconds = max(1, min(30, (int)($options['sleep'] ?? 2)));
$maxAttempts = max(1, min(100, (int)($options['max-attempts'] ?? 10)));
$workerId = gethostname() . ':' . getmypid() . ':' . substr(bin2hex(random_bytes(8)), 0, 12);
$running = true;

if (function_exists('pcntl_async_signals')) {
  pcntl_async_signals(true);
  pcntl_signal(SIGTERM, static function() use (&$running): void { $running = false; });
  pcntl_signal(SIGINT, static function() use (&$running): void { $running = false; });
}

function brain_worker_log(string $message, array $context = []): void {
  $line = '[' . date('Y-m-d H:i:s') . '] ' . $message;
  if ($context) $line .= ' ' . (json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
  fwrite(STDOUT, $line . PHP_EOL);
}

function brain_worker_heartbeat(PDO $pdo, string $workerId, string $status, string $error = '', int $processed = 0): void {
  try {
    $pdo->prepare("INSERT INTO brain_worker_heartbeat (worker_id,worker_type,status,last_seen_at,last_error,processed_events,started_at)
      VALUES (?,?,?,NOW(),NULLIF(?,''),?,NOW())
      ON DUPLICATE KEY UPDATE status=VALUES(status),last_seen_at=NOW(),last_error=VALUES(last_error),processed_events=processed_events+VALUES(processed_events)")
      ->execute([$workerId, 'outbox', $status, mb_substr($error, 0, 2000, 'UTF-8'), max(0, $processed)]);
  } catch (Throwable $e) {}
}

function brain_worker_claim(PDO $pdo, string $workerId, int $batchSize): array {
  // Libera eventos abandonados por processo encerrado.
  $pdo->exec("UPDATE brain_event_outbox
    SET status='pending', locked_by=NULL, locked_at=NULL, available_at=NOW(), last_error=CONCAT('Reprocessado após lock expirado. ',COALESCE(last_error,''))
    WHERE status='processing' AND locked_at < NOW() - INTERVAL 5 MINUTE");

  $pdo->beginTransaction();
  try {
    $sql = "SELECT id FROM brain_event_outbox
      WHERE status='pending' AND available_at<=NOW()
      ORDER BY id ASC LIMIT " . (int)$batchSize . " FOR UPDATE";
    $ids = array_values(array_map('intval', $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: []));
    if (!$ids) { $pdo->commit(); return []; }
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$workerId], $ids);
    $pdo->prepare("UPDATE brain_event_outbox SET status='processing',locked_by=?,locked_at=NOW(),attempts=attempts+1 WHERE id IN ($ph)")->execute($params);
    $st = $pdo->prepare("SELECT * FROM brain_event_outbox WHERE id IN ($ph) ORDER BY id ASC");
    $st->execute($ids);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $pdo->commit();
    return $rows;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }
}

function brain_worker_dashboard_event(string $eventType): bool {
  foreach (['order.','stock.','product.cost.','product.updated','receivable.','payable.','finance.','fiscal.','dfe.','brain.alert'] as $prefix) {
    if (str_starts_with($eventType, $prefix)) return true;
  }
  return false;
}

function brain_worker_actions_event(string $eventType): bool {
  // Eventos de alerta servem para atualizar a tela, mas não devem disparar
  // uma nova avaliação das próprias regras. Isso evita realimentação do
  // Outbox: avaliação -> brain.alerts.updated -> nova avaliação.
  if (str_starts_with($eventType, 'brain.alert')) return false;
  return brain_worker_dashboard_event($eventType);
}

function brain_worker_publish(array $event, bool $dashboardChanged, ?array $cache): string {
  $payloadRaw = $event['payload'] ?? '{}';
  if (is_array($payloadRaw)) $payloadRaw = json_encode($payloadRaw, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  return nexa_redis_stream_add([
    'outbox_id' => (string)(int)$event['id'],
    'company_id' => (string)(int)$event['company_id'],
    'event_type' => (string)$event['event_type'],
    'entity_type' => (string)($event['entity_type'] ?? ''),
    'entity_id' => (string)(int)($event['entity_id'] ?? 0),
    'payload' => (string)$payloadRaw,
    'occurred_at' => (string)($event['created_at'] ?? date('Y-m-d H:i:s')),
    'dashboard_changed' => $dashboardChanged ? '1' : '0',
    'cache_generated_at' => (string)($cache['generatedAt'] ?? ''),
  ]);
}

function brain_worker_mark_published(PDO $pdo, int $id, string $streamId): void {
  $pdo->prepare("UPDATE brain_event_outbox SET status='published',published_at=NOW(),redis_stream_id=?,locked_by=NULL,locked_at=NULL,last_error=NULL WHERE id=?")
    ->execute([$streamId, $id]);
}

function brain_worker_mark_failed(PDO $pdo, array $event, Throwable $error, int $maxAttempts): void {
  $id = (int)$event['id'];
  $attempts = (int)($event['attempts'] ?? 1);
  $terminal = $attempts >= $maxAttempts;
  $delay = min(900, max(2, 2 ** min(9, $attempts)));
  $availableAt = (new DateTimeImmutable())->modify('+' . $delay . ' seconds')->format('Y-m-d H:i:s');
  $pdo->prepare("UPDATE brain_event_outbox SET status=?,available_at=?,locked_by=NULL,locked_at=NULL,last_error=? WHERE id=?")
    ->execute([$terminal ? 'failed' : 'pending', $availableAt, mb_substr($error->getMessage(), 0, 4000, 'UTF-8'), $id]);
}

brain_worker_log('Worker iniciado.', ['worker'=>$workerId,'batch'=>$batchSize,'once'=>$once]);
brain_worker_heartbeat($pdo, $workerId, 'running');

while ($running) {
  $processed = 0;
  try {
    // Obriga conexão Redis antes de reservar eventos.
    $redis = nexa_redis(true);
    if (!$redis) throw new RuntimeException('Redis indisponível. Nenhum evento foi reservado.');

    $events = brain_worker_claim($pdo, $workerId, $batchSize);
    if (!$events) {
      brain_worker_heartbeat($pdo, $workerId, 'idle');
      if ($once) break;
      sleep($sleepSeconds);
      continue;
    }

    $companiesToRefresh = [];
    $companiesToEvaluate = [];
    foreach ($events as $event) {
      $eventType = (string)$event['event_type'];
      $companyId = (int)$event['company_id'];
      if (brain_worker_dashboard_event($eventType)) $companiesToRefresh[$companyId] = true;
      if (brain_worker_actions_event($eventType)) $companiesToEvaluate[$companyId] = true;
    }

    $cacheByCompany = [];
    $cacheErrors = [];
    foreach (array_keys($companiesToRefresh) as $companyId) {
      try {
        // Avalia as regras uma única vez por empresa e por lote somente
        // quando houve evento operacional. Eventos brain.alert apenas
        // recompõem o cache e notificam os navegadores.
        if (isset($companiesToEvaluate[$companyId])) {
          brain_actions_evaluate_company($pdo, $companyId, 'outbox_worker');
        }
        $cacheByCompany[$companyId] = brain_dashboard_rebuild_cache($pdo, $companyId);
      } catch (Throwable $e) {
        $cacheErrors[$companyId] = $e;
      }
    }

    foreach ($events as $event) {
      $companyId = (int)$event['company_id'];
      $dashboardChanged = brain_worker_dashboard_event((string)$event['event_type']);
      try {
        if ($dashboardChanged && isset($cacheErrors[$companyId])) throw $cacheErrors[$companyId];
        $streamId = brain_worker_publish($event, $dashboardChanged, $cacheByCompany[$companyId] ?? null);
        brain_worker_mark_published($pdo, (int)$event['id'], $streamId);
        $processed++;
      } catch (Throwable $e) {
        brain_worker_mark_failed($pdo, $event, $e, $maxAttempts);
        brain_worker_log('Falha ao processar evento.', ['id'=>(int)$event['id'],'type'=>$event['event_type'],'error'=>$e->getMessage()]);
      }
    }

    brain_worker_heartbeat($pdo, $workerId, 'running', '', $processed);
    brain_worker_log('Lote processado.', ['reserved'=>count($events),'published'=>$processed]);
  } catch (Throwable $e) {
    brain_worker_heartbeat($pdo, $workerId, 'error', $e->getMessage());
    brain_worker_log('Erro no ciclo do worker.', ['error'=>$e->getMessage()]);
    if ($once) break;
    sleep($sleepSeconds);
  }
  if ($once) break;
}

brain_worker_heartbeat($pdo, $workerId, 'stopped');
brain_worker_log('Worker encerrado.', ['worker'=>$workerId]);
