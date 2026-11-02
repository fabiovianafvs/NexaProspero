<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';

$apply = in_array('--apply', $argv, true);
$serviceUrl = rtrim((string)(getenv('WHATSAPP_SERVICE_URL') ?: 'http://127.0.0.1:3333'), '/');

function wa_cleanup_call(string $url, array $payload): array {
  $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($json === false) throw new RuntimeException('Falha ao montar JSON.');
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
      CURLOPT_POSTFIELDS => $json,
      CURLOPT_CONNECTTIMEOUT => 5,
      CURLOPT_TIMEOUT => 30,
    ]);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException($err ?: 'Falha ao conectar no serviço WhatsApp.');
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) throw new RuntimeException('Resposta inválida do serviço WhatsApp.');
    if ($code >= 400) throw new RuntimeException((string)($data['error'] ?? ('HTTP ' . $code)));
    return $data;
  }
  $ctx = stream_context_create(['http' => [
    'method' => 'POST',
    'header' => "Content-Type: application/json\r\n",
    'content' => $json,
    'timeout' => 30,
    'ignore_errors' => true,
  ]]);
  $raw = @file_get_contents($url, false, $ctx);
  if ($raw === false) throw new RuntimeException('Falha ao conectar no serviço WhatsApp.');
  $data = json_decode((string)$raw, true);
  if (!is_array($data)) throw new RuntimeException('Resposta inválida do serviço WhatsApp.');
  if (empty($data['ok']) && !array_key_exists('candidates', $data)) throw new RuntimeException((string)($data['error'] ?? 'Falha na manutenção.'));
  return $data;
}

$companyIds = [];
$st = $pdo->query('SELECT id FROM companies ORDER BY id');
foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $id) {
  $id = (int)$id;
  if ($id > 0) $companyIds[$id] = (string)$id;
}

try {
  $st = $pdo->query("SELECT company_id, cfg_json FROM app_config WHERE cfg_key='whatsapp'");
  while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
    $companyId = (int)($row['company_id'] ?? 0);
    if ($companyId <= 0) continue;
    $cfg = json_decode((string)($row['cfg_json'] ?? ''), true);
    if (!is_array($cfg)) continue;
    $sessionId = preg_replace('/\D+/', '', (string)($cfg['session_company_id'] ?? '')) ?: '';
    if ($sessionId !== '' && $sessionId !== '0') $companyIds[$companyId] = $sessionId;
  }
} catch (Throwable $e) {
  // Se app_config não existir/estiver indisponível, os IDs reais das empresas continuam protegidos.
}

$keepIds = array_values(array_unique(array_filter($companyIds, static fn($v) => preg_match('/^[1-9][0-9]{0,19}$/', (string)$v))));
sort($keepIds, SORT_NATURAL);

try {
  $res = wa_cleanup_call($serviceUrl . '/maintenance/archive-orphans', [
    'keep_ids' => $keepIds,
    'apply' => $apply,
  ]);
} catch (Throwable $e) {
  fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
  exit(1);
}

echo "Nexa WhatsApp - limpeza controlada de sessões\n";
echo "Modo: " . ($apply ? 'APLICAR' : 'SIMULAÇÃO') . "\n";
echo "IDs protegidos pelo cadastro atual: " . count($keepIds) . "\n";
if (!$apply) {
  $candidates = (array)($res['candidates'] ?? []);
  echo "Sessões persistidas: " . (int)($res['stored_total'] ?? 0) . "\n";
  echo "Sessões carregadas: " . (int)($res['loaded_total'] ?? 0) . "\n";
  echo "Candidatas a arquivamento: " . count($candidates) . "\n";
  foreach ($candidates as $item) {
    echo '  - ' . (string)($item['company_id'] ?? '') . ' | mtime=' . (string)($item['mtime'] ?? '') . ' | token=' . (!empty($item['token_registered']) ? 'sim' : 'não') . "\n";
  }
  echo "\nNada foi alterado. Para arquivar as candidatas, execute com --apply.\n";
} else {
  echo "Arquivadas: " . (int)($res['archived_count'] ?? 0) . "\n";
  echo "Falhas: " . (int)($res['failed_count'] ?? 0) . "\n";
  echo "Pasta de arquivo: " . (string)($res['archive_path'] ?? '') . "\n";
  foreach ((array)($res['failed'] ?? []) as $item) {
    echo '  FALHA ' . (string)($item['company_id'] ?? '') . ': ' . (string)($item['error'] ?? '') . "\n";
  }
}
