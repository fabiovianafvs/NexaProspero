<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
$counts = $pdo->query("SELECT status,COUNT(*) qtd,MIN(created_at) oldest FROM brain_event_outbox GROUP BY status ORDER BY status")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($counts as $row) echo str_pad((string)$row['status'], 12) . ' ' . str_pad((string)$row['qtd'], 8, ' ', STR_PAD_LEFT) . ' mais antigo: ' . ($row['oldest'] ?: '-') . PHP_EOL;
echo PHP_EOL . 'Workers:' . PHP_EOL;
$workers = $pdo->query("SELECT worker_id,status,last_seen_at,processed_events,last_error FROM brain_worker_heartbeat ORDER BY last_seen_at DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
foreach ($workers as $row) echo $row['worker_id'] . ' | ' . $row['status'] . ' | ' . $row['last_seen_at'] . ' | eventos=' . $row['processed_events'] . ($row['last_error'] ? ' | ' . $row['last_error'] : '') . PHP_EOL;
