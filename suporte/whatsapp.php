<?php
declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');
require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';
require_once __DIR__ . '/../config/config_store.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';
require_once __DIR__ . '/../config/reseller_integrations.php';

$user = require_support_user($pdo);
support_ensure_schema($pdo);
try { reseller_integrations_ensure_schema($pdo); } catch (Throwable $e) {}

const SUPPORT_WA_DEFAULT_SERVICE = 'http://127.0.0.1:3333';

function support_wa_normalize_service_url(string $url): string {
  $url = rtrim(trim($url), '/');
  return $url !== '' ? $url : SUPPORT_WA_DEFAULT_SERVICE;
}

function support_wa_service(string $method, string $path, array $payload = [], string $serviceUrl = SUPPORT_WA_DEFAULT_SERVICE, string $apiToken = ''): array {
  $serviceUrl = support_wa_normalize_service_url($serviceUrl);
  $url = $serviceUrl . $path;
  $method = strtoupper($method);
  $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
  $headers = ['Content-Type: application/json'];
  $apiToken = trim($apiToken);
  if ($apiToken !== '') $headers[] = 'X-API-Token: ' . $apiToken;

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 2,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
    ]);
    if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($raw === false) return ['ok' => false, 'error' => $err !== '' ? $err : 'Serviço WhatsApp indisponível.', '_http_code' => $code];
    $data = json_decode((string)$raw, true);
    if (!is_array($data)) return ['ok' => false, 'error' => 'Resposta inválida do serviço WhatsApp.', '_http_code' => $code];
    if ($code >= 400 && !isset($data['ok'])) $data['ok'] = false;
    $data['_http_code'] = $code;
    return $data;
  }

  $header = "Content-Type: application/json\r\n";
  if ($apiToken !== '') $header .= 'X-API-Token: ' . $apiToken . "\r\n";
  $opts = ['http' => ['method' => $method, 'timeout' => 8, 'ignore_errors' => true, 'header' => $header]];
  if ($body !== '') $opts['http']['content'] = $body;
  $raw = @file_get_contents($url, false, stream_context_create($opts));
  if ($raw === false) return ['ok' => false, 'error' => 'Serviço WhatsApp indisponível.'];
  $data = json_decode((string)$raw, true);
  return is_array($data) ? $data : ['ok' => false, 'error' => 'Resposta inválida do serviço WhatsApp.'];
}

function support_wa_session_key(array $row): string {
  return support_wa_normalize_service_url((string)($row['service_url'] ?? '')) . '|' . (string)($row['session_company_id'] ?? '');
}

function support_wa_session_info(array $row, array $sessionMap): array {
  return (array)($sessionMap[support_wa_session_key($row)] ?? []);
}

function support_wa_first_value(array $item, array $keys, $default = null) {
  foreach ($keys as $key) {
    if (array_key_exists($key, $item)) return $item[$key];
  }
  return $default;
}

function support_wa_normalize_session_info(array $item, string $source = ''): array {
  $out = $item;

  $companyId = support_wa_first_value($item, ['company_id','companyId'], '');
  if ($companyId !== '') $out['company_id'] = (string)$companyId;

  $out['connected'] = !empty(support_wa_first_value($item, ['connected'], false));
  $out['ready'] = !empty(support_wa_first_value($item, ['ready'], false));
  $out['authenticated'] = !empty(support_wa_first_value($item, ['authenticated'], false));
  $out['initializing'] = !empty(support_wa_first_value($item, ['initializing'], false));

  $qr = support_wa_first_value($item, ['qr'], '');
  $out['qr'] = is_string($qr) ? $qr : '';

  $status = strtolower(trim((string)support_wa_first_value($item, ['status','state'], '')));
  if ($status === 'ready') $status = 'connected';
  if ($out['ready'] || $out['connected']) $status = 'connected';
  elseif ($out['initializing'] && $status === '') $status = 'initializing';
  elseif ($out['qr'] !== '' && ($status === '' || $status === 'disconnected')) $status = 'qr';
  if ($status === '') $status = 'disconnected';
  $out['status'] = $status;

  $out['last_error'] = trim((string)support_wa_first_value($item, ['last_error','lastError'], ''));
  $out['updated_at'] = trim((string)support_wa_first_value($item, ['updated_at','updatedAt'], ''));

  $browserPid = support_wa_first_value($item, ['browser_pid','browserPid','pid'], null);
  if ($browserPid !== null && $browserPid !== '') $out['browser_pid'] = (int)$browserPid;

  $reconnects = support_wa_first_value($item, ['reconnect_attempts','reconnectAttempts'], null);
  if ($reconnects !== null && $reconnects !== '') $out['reconnect_attempts'] = (int)$reconnects;

  $cpuPeak = support_wa_first_value($item, ['cpu_peak_pct','cpuPeakPct','cpu_peak','cpuPeak'], null);
  $cpuHot = support_wa_first_value($item, ['cpu_hot_samples','cpuHotSamples'], null);
  $cpuTrips = support_wa_first_value($item, ['cpu_watchdog_trips','cpuWatchdogTrips'], null);
  $cpuCurrent = support_wa_first_value($item, ['cpu_pct','cpuPct','cpu_percent','cpuPercent'], null);
  $telemetry = ($cpuPeak !== null || $cpuHot !== null || $cpuTrips !== null || $cpuCurrent !== null);
  $out['_cpu_telemetry'] = $telemetry;
  if ($cpuPeak !== null && $cpuPeak !== '') $out['cpu_peak_pct'] = (float)$cpuPeak;
  if ($cpuHot !== null && $cpuHot !== '') $out['cpu_hot_samples'] = (int)$cpuHot;
  if ($cpuTrips !== null && $cpuTrips !== '') $out['cpu_watchdog_trips'] = (int)$cpuTrips;
  if ($cpuCurrent !== null && $cpuCurrent !== '') $out['cpu_pct'] = (float)$cpuCurrent;

  $stored = support_wa_first_value($item, ['stored','persisted','hasSession'], null);
  if ($stored !== null) $out['stored'] = !empty($stored);

  if ($source !== '') $out['_monitor_source'] = $source;
  return $out;
}

function support_wa_has_status_payload(array $item): bool {
  foreach (['status','state','connected','ready','authenticated','initializing','qr','updated_at','updatedAt'] as $key) {
    if (array_key_exists($key, $item)) return true;
  }
  return false;
}

function support_wa_valid_session_id(string $value): bool {
  return (bool)preg_match('/^[1-9][0-9]{0,19}$/', $value);
}

function support_wa_rows(PDO $pdo): array {
  $rows = [];

  // 1) WhatsApps das empresas Nexa (Admin > WhatsApp).
  try {
    $st = $pdo->query("SELECT c.id, c.name, c.slug, ac.cfg_json FROM companies c LEFT JOIN app_config ac ON ac.company_id=c.id AND ac.cfg_key='whatsapp' ORDER BY c.name ASC");
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $cfg = [];
      if (!empty($r['cfg_json'])) {
        $tmp = json_decode((string)$r['cfg_json'], true);
        if (is_array($tmp)) $cfg = $tmp;
      }
      $enabled = !empty($cfg['enabled']);
      if (!$enabled && empty($r['cfg_json'])) continue;
      $sessionId = preg_replace('/\D+/', '', (string)($cfg['session_company_id'] ?? '')) ?: '';
      if ($sessionId === '' || $sessionId === '0') $sessionId = (string)$r['id'];
      $rows[] = [
        'owner_key' => 'company:' . (int)$r['id'],
        'source' => 'company',
        'source_label' => 'Empresa Nexa',
        'company_id' => (int)$r['id'],
        'name' => (string)$r['name'],
        'slug' => (string)$r['slug'],
        'enabled' => $enabled,
        'provider' => 'unofficial',
        'service_url' => support_wa_normalize_service_url((string)($cfg['service_url'] ?? '')),
        'api_token' => trim((string)($cfg['api_token'] ?? '')),
        'session_company_id' => $sessionId,
      ];
    }
  } catch (Throwable $e) {}

  // 2) Central SaaS. Usa o mesmo serviço WebJS, mas com ID técnico reservado.
  try {
    $cfg = saas_whatsapp_load_config($pdo);
    $provider = (string)($cfg['provider'] ?? 'unofficial');
    $sessionId = $provider === 'unofficial' ? saas_whatsapp_unofficial_instance_id($cfg) : '';
    $rows[] = [
      'owner_key' => 'saas:1',
      'source' => 'saas',
      'source_label' => 'Central SaaS',
      'company_id' => null,
      'name' => 'Central SaaS Nexa Próspero',
      'slug' => '/saas/whatsapp.php',
      'enabled' => !empty($cfg['enabled']),
      'provider' => $provider,
      'service_url' => support_wa_normalize_service_url((string)($cfg['service_url'] ?? '')),
      'api_token' => trim((string)($cfg['api_token'] ?? '')),
      'session_company_id' => $sessionId,
    ];
  } catch (Throwable $e) {}

  // 3) WhatsApps das revendas. Lista todas as revendas ativas, mesmo antes da primeira
  // conexão, para que o suporte enxergue também o número comercial cadastrado.
  try {
    $sql = "SELECT c.id AS reseller_company_id, c.name, c.slug, c.reseller_brand_name, c.reseller_whatsapp,
                   COALESCE(rw.enabled,0) AS enabled, COALESCE(rw.provider,'unofficial') AS provider,
                   rw.service_url, rw.api_token, rw.unofficial_instance_id
            FROM companies c
            LEFT JOIN saas_reseller_whatsapp_config rw ON rw.reseller_company_id = c.id
            WHERE c.is_reseller=1 AND COALESCE(c.reseller_status,'inactive')='active'
            ORDER BY COALESCE(NULLIF(c.reseller_brand_name,''), c.name, CONCAT('Revenda #', c.id))";
    $st = $pdo->query($sql);
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      $resellerId = (int)$r['reseller_company_id'];
      $provider = in_array((string)$r['provider'], ['unofficial','official'], true) ? (string)$r['provider'] : 'unofficial';
      $sessionId = preg_replace('/\D+/', '', (string)($r['unofficial_instance_id'] ?? '')) ?: '';
      if ($provider === 'unofficial' && ($sessionId === '' || $sessionId === '0')) $sessionId = reseller_whatsapp_default_instance_id($resellerId);
      $displayName = trim((string)($r['reseller_brand_name'] ?? ''));
      if ($displayName === '') $displayName = trim((string)($r['name'] ?? ''));
      if ($displayName === '') $displayName = 'Revenda #' . $resellerId;
      $rows[] = [
        'owner_key' => 'reseller:' . $resellerId,
        'source' => 'reseller',
        'source_label' => 'Revenda',
        'company_id' => $resellerId,
        'name' => $displayName,
        'slug' => trim((string)($r['slug'] ?? '')) !== '' ? (string)$r['slug'] : '/revenda/whatsapp.php',
        'enabled' => !empty($r['enabled']),
        'provider' => $provider,
        'service_url' => support_wa_normalize_service_url((string)($r['service_url'] ?? '')),
        'api_token' => trim((string)($r['api_token'] ?? '')),
        'session_company_id' => $provider === 'unofficial' ? $sessionId : '',
        'contact_phone' => preg_replace('/\D+/', '', (string)($r['reseller_whatsapp'] ?? '')) ?: '',
      ];
    }
  } catch (Throwable $e) {}

  usort($rows, static function(array $a, array $b): int {
    $rank = ['saas'=>0,'reseller'=>1,'company'=>2];
    return ($rank[$a['source']] ?? 9) <=> ($rank[$b['source']] ?? 9)
      ?: strcasecmp((string)$a['name'], (string)$b['name']);
  });
  return $rows;
}

function support_wa_status_label(string $status): string {
  return match ($status) {
    'connected' => 'Conectado',
    'initializing' => 'Inicializando',
    'qr' => 'Aguardando QR',
    'stopped' => 'Parado',
    'stored' => 'Em repouso',
    'official' => 'API Oficial',
    'invalid' => 'Configuração inválida',
    'conflict' => 'Conflito de sessão',
    default => 'Desconectado',
  };
}

function support_wa_row_status(array $row, array $sessionMap, array $sessionUsage): string {
  if (($row['provider'] ?? 'unofficial') === 'official') return 'official';
  $sessionId = (string)($row['session_company_id'] ?? '');
  if (!support_wa_valid_session_id($sessionId)) return 'invalid';
  $sessionKey = support_wa_session_key($row);
  if (($sessionUsage[$sessionKey] ?? 0) > 1) return 'conflict';
  $info = support_wa_session_info($row, $sessionMap);

  // O serviço já teve versões que retornam apenas flags (ready/connected)
  // e outras que retornam também o campo textual status. O monitor aceita ambos.
  if (!empty($info['ready']) || !empty($info['connected'])) return 'connected';
  if (!empty($info['initializing'])) return 'initializing';
  if (!empty($info['qr'])) return 'qr';

  $status = strtolower(trim((string)($info['status'] ?? '')));
  if ($status === 'ready') $status = 'connected';
  // Sem retorno da instância correta não presumimos que a sessão esteja em repouso.
  // 'stored' deve vir explicitamente do serviço quando existir perfil persistido.
  return $status !== '' ? $status : 'disconnected';
}

function support_wa_format_updated_at(string $value): string {
  $value = trim($value);
  if ($value === '') return '—';
  try {
    $dt = new DateTimeImmutable($value);
    $dt = $dt->setTimezone(new DateTimeZone('America/Sao_Paulo'));
    return $dt->format('d/m/Y H:i:s');
  } catch (Throwable $e) {
    return $value;
  }
}

function support_wa_cpu_state(array $info): string {
  if (empty($info['_cpu_telemetry'])) return 'unknown';
  // Quando existe leitura instantânea, ela é a referência para "atenção agora".
  // cpu_hot_samples é histórico e não deve manter a sessão em vermelho para sempre.
  if (array_key_exists('cpu_pct', $info)) {
    return (float)$info['cpu_pct'] >= 85.0 ? 'attention' : 'stable';
  }
  return (int)($info['cpu_hot_samples'] ?? 0) > 0 ? 'attention' : 'stable';
}

function support_wa_is_local_service_url(string $url): bool {
  $host = strtolower(trim((string)parse_url(support_wa_normalize_service_url($url), PHP_URL_HOST)));
  return in_array($host, ['127.0.0.1', 'localhost', '::1'], true);
}

/**
 * Lê um snapshot leve de /proc para localizar o Chromium de cada sessão WebJS.
 * Não executa shell, ps, top ou sudo. Se o PHP não puder ler /proc, simplesmente
 * devolve vazio e o monitor continua funcionando sem telemetria.
 *
 * @return array{total_ticks:int,cpu_count:int,processes:array<int,array{ppid:int,ticks:int,cmdline:string}>}
 */
function support_wa_proc_snapshot(): array {
  $result = ['total_ticks' => 0, 'cpu_count' => 1, 'processes' => []];
  if (PHP_OS_FAMILY !== 'Linux' || !is_readable('/proc/stat')) return $result;

  $stat = @file('/proc/stat', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
  if (!is_array($stat) || !$stat) return $result;
  $cpuCount = 0;
  foreach ($stat as $line) {
    if (preg_match('/^cpu\d+\s/', (string)$line)) $cpuCount++;
  }
  $result['cpu_count'] = max(1, $cpuCount);
  $first = preg_split('/\s+/', trim((string)$stat[0]));
  if (is_array($first) && isset($first[0]) && $first[0] === 'cpu') {
    $total = 0;
    foreach (array_slice($first, 1) as $v) $total += (int)$v;
    $result['total_ticks'] = $total;
  }

  $dirs = @glob('/proc/[0-9]*', GLOB_ONLYDIR);
  if (!is_array($dirs)) return $result;
  foreach ($dirs as $dir) {
    $pid = (int)basename($dir);
    if ($pid <= 0) continue;
    $raw = @file_get_contents($dir . '/stat');
    if (!is_string($raw) || $raw === '') continue;
    $end = strrpos($raw, ')');
    if ($end === false) continue;
    $tail = trim(substr($raw, $end + 1));
    $parts = preg_split('/\s+/', $tail);
    // Após o nome entre parênteses: state[0], ppid[1], ... utime[11], stime[12].
    if (!is_array($parts) || !isset($parts[12])) continue;
    $ppid = (int)$parts[1];
    $ticks = (int)$parts[11] + (int)$parts[12];
    $cmdRaw = @file_get_contents($dir . '/cmdline');
    $cmdline = is_string($cmdRaw) ? trim(str_replace("\0", ' ', $cmdRaw)) : '';
    $result['processes'][$pid] = ['ppid' => $ppid, 'ticks' => $ticks, 'cmdline' => $cmdline];
  }
  return $result;
}

/** @return array{pid:int,ticks:int}|null */
function support_wa_proc_session_tree(array $snapshot, string $sessionId): ?array {
  if (!support_wa_valid_session_id($sessionId)) return null;
  $processes = (array)($snapshot['processes'] ?? []);
  if (!$processes) return null;
  $marker = 'session-company-' . $sessionId;
  $roots = [];
  foreach ($processes as $pid => $proc) {
    $cmd = (string)($proc['cmdline'] ?? '');
    if ($cmd !== '' && strpos($cmd, $marker) !== false) $roots[(int)$pid] = true;
  }
  if (!$roots) return null;

  // Se mais de um processo contém o marcador, escolhemos apenas os que não são
  // descendentes de outro candidato. Normalmente sobra o processo browser raiz.
  foreach (array_keys($roots) as $pid) {
    $parent = (int)($processes[$pid]['ppid'] ?? 0);
    $guard = 0;
    while ($parent > 0 && isset($processes[$parent]) && $guard++ < 64) {
      if (isset($roots[$parent])) { unset($roots[$pid]); break; }
      $parent = (int)($processes[$parent]['ppid'] ?? 0);
    }
  }
  if (!$roots) return null;
  $rootPid = (int)array_key_first($roots);

  $children = [];
  foreach ($processes as $pid => $proc) {
    $ppid = (int)($proc['ppid'] ?? 0);
    $children[$ppid][] = (int)$pid;
  }
  $queue = [$rootPid];
  $seen = [];
  $ticks = 0;
  while ($queue) {
    $pid = array_pop($queue);
    if (isset($seen[$pid])) continue;
    $seen[$pid] = true;
    if (isset($processes[$pid])) $ticks += (int)($processes[$pid]['ticks'] ?? 0);
    foreach ((array)($children[$pid] ?? []) as $child) $queue[] = (int)$child;
  }
  return ['pid' => $rootPid, 'ticks' => $ticks];
}

function support_wa_cpu_history_path(): string {
  return __DIR__ . '/../storage/support_whatsapp_cpu_metrics.json';
}

function support_wa_cpu_history_load(): array {
  $path = support_wa_cpu_history_path();
  $raw = @file_get_contents($path);
  if (!is_string($raw) || trim($raw) === '') return [];
  $data = json_decode($raw, true);
  return is_array($data) ? $data : [];
}

function support_wa_cpu_history_save(array $history): void {
  $path = support_wa_cpu_history_path();
  $dir = dirname($path);
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
  $now = time();
  foreach ($history as $key => $item) {
    if (!is_array($item) || (int)($item['last_seen'] ?? 0) < $now - 604800) unset($history[$key]);
  }
  @file_put_contents($path, json_encode($history, JSON_UNESCAPED_SLASHES), LOCK_EX);
}

/**
 * Mede CPU das sessões locais por aproximadamente 250 ms. 100% equivale a um
 * núcleo totalmente ocupado; uma árvore Chromium pode ultrapassar 100% em VPS
 * com múltiplos núcleos. O pico é mantido enquanto o PID raiz do browser existir.
 *
 * @return array<string,array{browser_pid:int,cpu_pct:float,cpu_peak_pct:float,cpu_hot_samples:int,cpu_watchdog_trips:int,_cpu_telemetry:bool,_cpu_source:string}>
 */
function support_wa_local_cpu_telemetry(array $rows): array {
  if (PHP_OS_FAMILY !== 'Linux') return [];
  $targets = [];
  foreach ($rows as $row) {
    if (($row['provider'] ?? '') === 'official' || !support_wa_is_local_service_url((string)($row['service_url'] ?? ''))) continue;
    $sid = (string)($row['session_company_id'] ?? '');
    if (!support_wa_valid_session_id($sid)) continue;
    $targets[$sid] = true;
  }
  if (!$targets) return [];

  $a = support_wa_proc_snapshot();
  if ((int)($a['total_ticks'] ?? 0) <= 0) return [];
  $treeA = [];
  foreach (array_keys($targets) as $sid) {
    $tree = support_wa_proc_session_tree($a, (string)$sid);
    if ($tree) $treeA[(string)$sid] = $tree;
  }
  if (!$treeA) return [];

  usleep(250000);
  $b = support_wa_proc_snapshot();
  $totalDelta = (int)($b['total_ticks'] ?? 0) - (int)($a['total_ticks'] ?? 0);
  if ($totalDelta <= 0) return [];
  $cpuCount = max(1, (int)($b['cpu_count'] ?? $a['cpu_count'] ?? 1));

  $history = support_wa_cpu_history_load();
  $now = time();
  $out = [];
  foreach ($treeA as $sid => $first) {
    $second = support_wa_proc_session_tree($b, (string)$sid);
    if (!$second || (int)$second['pid'] !== (int)$first['pid']) continue;
    $procDelta = max(0, (int)$second['ticks'] - (int)$first['ticks']);
    $cpu = ($procDelta / $totalDelta) * $cpuCount * 100.0;
    $cpu = round(max(0.0, min(999.9, $cpu)), 1);

    $histKey = 'local|' . $sid;
    $old = is_array($history[$histKey] ?? null) ? $history[$histKey] : [];
    if ((int)($old['browser_pid'] ?? 0) !== (int)$second['pid']) $old = [];
    $peak = max((float)($old['peak_pct'] ?? 0.0), $cpu);
    $hotSamples = (int)($old['hot_samples'] ?? 0) + ($cpu >= 85.0 ? 1 : 0);
    $history[$histKey] = [
      'browser_pid' => (int)$second['pid'],
      'peak_pct' => round($peak, 1),
      'hot_samples' => $hotSamples,
      'last_seen' => $now,
    ];
    $out[(string)$sid] = [
      'browser_pid' => (int)$second['pid'],
      'cpu_pct' => $cpu,
      'cpu_peak_pct' => round($peak, 1),
      'cpu_hot_samples' => $hotSamples,
      'cpu_watchdog_trips' => 0,
      '_cpu_telemetry' => true,
      '_cpu_source' => 'Linux /proc',
    ];
  }
  support_wa_cpu_history_save($history);
  return $out;
}

$rows = support_wa_rows($pdo);
$sessionUsage = [];
foreach ($rows as $row) {
  $sid = (string)($row['session_company_id'] ?? '');
  if (($row['provider'] ?? '') === 'unofficial' && support_wa_valid_session_id($sid)) {
    $sessionKey = support_wa_session_key($row);
    $sessionUsage[$sessionKey] = ($sessionUsage[$sessionKey] ?? 0) + 1;
  }
}
$rowsByKey = [];
foreach ($rows as $row) $rowsByKey[(string)$row['owner_key']] = $row;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  $ownerKey = trim((string)($_POST['owner_key'] ?? ''));
  $sessionId = preg_replace('/\D+/', '', (string)($_POST['session_company_id'] ?? '')) ?: '';
  $csrf = (string)($_POST['csrf'] ?? '');
  $target = $rowsByKey[$ownerKey] ?? null;
  $result = ['ok' => false, 'error' => 'Ação inválida.'];

  if (!support_verify_csrf($csrf)) {
    $result = ['ok' => false, 'error' => 'Sessão expirada. Atualize a página e tente novamente.'];
  } elseif (!$target || (string)$target['session_company_id'] !== $sessionId) {
    $result = ['ok' => false, 'error' => 'Sessão não vinculada a uma configuração válida.'];
  } elseif (($target['provider'] ?? '') !== 'unofficial' || !support_wa_valid_session_id($sessionId)) {
    $result = ['ok' => false, 'error' => 'Esta configuração não utiliza uma sessão WebJS gerenciável.'];
  } elseif (($sessionUsage[support_wa_session_key($target)] ?? 0) > 1) {
    $result = ['ok' => false, 'error' => 'Este ID de sessão está compartilhado por mais de uma configuração. Corrija o conflito antes de executar ações.'];
  } else {
    $path = match ($action) {
      'restart' => '/admin/session/restart',
      'stop' => '/admin/session/stop',
      'new_qr' => '/admin/session/new-qr',
      default => '',
    };
    if ($path !== '') $result = support_wa_service('POST', $path, ['company_id' => $sessionId], (string)($target['service_url'] ?? SUPPORT_WA_DEFAULT_SERVICE), (string)($target['api_token'] ?? ''));
  }

  $detail = (string)($result['message'] ?? $result['error'] ?? '');
  support_whatsapp_audit(
    $pdo,
    (int)$user['id'],
    $target && !empty($target['company_id']) ? (int)$target['company_id'] : null,
    $sessionId,
    $action,
    !empty($result['ok']),
    '[' . ($target['source_label'] ?? 'WhatsApp') . '] ' . $detail
  );
  $_SESSION['support_wa_flash'] = ['ok' => !empty($result['ok']), 'message' => $detail !== '' ? $detail : (!empty($result['ok']) ? 'Operação concluída.' : 'Falha na operação.')];
  header('Location: /suporte/whatsapp.php');
  exit;
}

// O Portal pode monitorar mais de uma instância do serviço WhatsApp.
// Antes esta tela consultava sempre 127.0.0.1:3333, mesmo quando a empresa
// estava configurada para outro service_url. Isso fazia sessões realmente
// conectadas aparecerem como "Em repouso".
$serviceConfigs = [];
foreach ($rows as $row) {
  if (($row['provider'] ?? '') !== 'unofficial') continue;
  $serviceUrl = support_wa_normalize_service_url((string)($row['service_url'] ?? ''));
  if (!isset($serviceConfigs[$serviceUrl])) $serviceConfigs[$serviceUrl] = ['tokens' => []];
  $token = trim((string)($row['api_token'] ?? ''));
  if ($token !== '' && !in_array($token, $serviceConfigs[$serviceUrl]['tokens'], true)) $serviceConfigs[$serviceUrl]['tokens'][] = $token;
}
if (!$serviceConfigs) $serviceConfigs[SUPPORT_WA_DEFAULT_SERVICE] = ['tokens' => []];

$sessionMap = [];
$serviceResults = [];
$serviceOnline = 0;
foreach ($serviceConfigs as $serviceUrl => $serviceCfg) {
  // /admin/sessions é uma rota de monitoramento/administrativa. Em instalações
  // locais ela pode aceitar a chamada sem token e devolver a visão completa.
  // Em outras versões, um token cadastrado pode limitar a resposta à própria
  // sessão. Por isso NÃO podemos parar no primeiro HTTP 200/ok: consultamos a
  // rota sem token primeiro e depois com todos os tokens conhecidos, mesclando
  // as sessões retornadas. Isso mantém a consulta leve e não inicializa Chromium.
  $tokens = [''];
  foreach ((array)($serviceCfg['tokens'] ?? []) as $token) {
    $token = trim((string)$token);
    if ($token !== '' && !in_array($token, $tokens, true)) $tokens[] = $token;
  }

  $healthRes = ['ok' => false, 'error' => 'Não consultado.'];
  $sessionsRes = ['ok' => false, 'items' => [], 'error' => 'Não consultado.'];
  $mergedItems = [];
  $successfulSessionCalls = 0;

  foreach ($tokens as $candidateToken) {
    if (empty($healthRes['ok'])) {
      $healthTry = support_wa_service('GET', '/health', [], $serviceUrl, (string)$candidateToken);
      if (!empty($healthTry['ok'])) $healthRes = $healthTry;
      elseif (empty($healthRes['ok'])) $healthRes = $healthTry;
    }

    $sessionsTry = support_wa_service('GET', '/admin/sessions', [], $serviceUrl, (string)$candidateToken);
    if (empty($sessionsTry['ok'])) {
      // Mantém o último erro apenas se nenhuma consulta tiver funcionado.
      if ($successfulSessionCalls === 0) $sessionsRes = $sessionsTry;
      continue;
    }

    $successfulSessionCalls++;
    foreach ((array)($sessionsTry['items'] ?? []) as $item) {
      if (!is_array($item)) continue;
      $item = support_wa_normalize_session_info($item, '/admin/sessions');
      if (empty($item['company_id'])) continue;
      $sid = (string)$item['company_id'];
      // A versão mais informativa prevalece. Se uma chamada indicar conexão,
      // ela substitui uma leitura anterior apenas persistida/stored.
      $existing = $mergedItems[$sid] ?? null;
      $itemConnected = (string)($item['status'] ?? '') === 'connected';
      $existingConnected = is_array($existing) && (string)($existing['status'] ?? '') === 'connected';
      if ($existing === null || ($itemConnected && !$existingConnected)) $mergedItems[$sid] = $item;
      elseif (is_array($existing)) $mergedItems[$sid] = array_merge($existing, $item);
    }
  }

  if ($successfulSessionCalls > 0) {
    $sessionsRes = ['ok' => true, 'items' => array_values($mergedItems), 'queries_ok' => $successfulSessionCalls];
  }

  if (!empty($healthRes['ok']) || !empty($sessionsRes['ok'])) $serviceOnline++;
  $serviceResults[$serviceUrl] = ['health' => $healthRes, 'sessions' => $sessionsRes];
  foreach ((array)($sessionsRes['items'] ?? []) as $item) {
    if (!is_array($item) || !isset($item['company_id'])) continue;
    $sid = (string)$item['company_id'];
    $sessionMap[$serviceUrl . '|' . $sid] = $item;
  }
}

// A rota /admin/sessions varia entre versões do serviço e, em algumas instalações,
// pode devolver somente sessões persistidas ou uma visão parcial. A própria tela
// Admin > WhatsApp usa /status?company_id=... como leitura autoritativa. Portanto,
// para toda sessão que não foi confirmada como conectada pelo inventário, fazemos
// uma consulta direta ao mesmo endpoint usado pelo Admin. Essa consulta também
// corrige updatedAt/lastError e campos camelCase sem inventar valores ausentes.
$statusQueries = 0;
$statusQueriesOk = 0;
foreach ($rows as $row) {
  if (($row['provider'] ?? '') !== 'unofficial') continue;
  if (empty($row['enabled'])) continue;
  $sid = (string)($row['session_company_id'] ?? '');
  if (!support_wa_valid_session_id($sid)) continue;
  $key = support_wa_session_key($row);
  $existing = (array)($sessionMap[$key] ?? []);
  $existingStatus = strtolower(trim((string)($existing['status'] ?? '')));
  if (!empty($existing['ready']) || !empty($existing['connected']) || in_array($existingStatus, ['ready','connected'], true)) {
    continue;
  }

  $statusQueries++;
  $statusRes = support_wa_service(
    'GET',
    '/status?company_id=' . rawurlencode($sid),
    [],
    (string)($row['service_url'] ?? SUPPORT_WA_DEFAULT_SERVICE),
    (string)($row['api_token'] ?? '')
  );

  if (!support_wa_has_status_payload($statusRes)) {
    if (!$existing) {
      $sessionMap[$key] = [
        'company_id' => $sid,
        'status' => 'disconnected',
        'last_error' => (string)($statusRes['error'] ?? ''),
        '_monitor_source' => '/status (sem resposta útil)',
        '_cpu_telemetry' => false,
      ];
    }
    continue;
  }

  $statusQueriesOk++;
  $direct = support_wa_normalize_session_info($statusRes, '/status');
  $direct['company_id'] = $sid;
  // A resposta direta é autoritativa para estado, timestamps e erro. Mantemos
  // telemetria/identificadores adicionais do inventário quando não vierem em /status.
  $combined = array_merge($existing, $direct);
  if (empty($direct['_cpu_telemetry']) && !empty($existing['_cpu_telemetry'])) {
    $combined['_cpu_telemetry'] = true;
  }
  $sessionMap[$key] = $combined;
}

// O serviço WhatsApp atual não publica CPU. Quando ele está nesta mesma VPS,
// medimos a árvore Chromium diretamente em /proc sem alterar/reiniciar o Node.
// A telemetria do próprio serviço, se existir em versão futura, continua tendo
// prioridade; /proc entra apenas como complemento seguro para sessões locais.
$localCpu = support_wa_local_cpu_telemetry($rows);
foreach ($rows as $row) {
  if (($row['provider'] ?? '') === 'official') continue;
  $sid = (string)($row['session_company_id'] ?? '');
  if (!isset($localCpu[$sid])) continue;
  $key = support_wa_session_key($row);
  $existing = (array)($sessionMap[$key] ?? []);
  if (empty($existing['_cpu_telemetry'])) {
    $sessionMap[$key] = array_merge($existing, $localCpu[$sid]);
  } elseif (empty($existing['browser_pid']) && !empty($localCpu[$sid]['browser_pid'])) {
    $existing['browser_pid'] = (int)$localCpu[$sid]['browser_pid'];
    $sessionMap[$key] = $existing;
  }
}

$serviceTotal = count($serviceConfigs);
$health = [
  'ok' => $serviceOnline === $serviceTotal,
  'online' => $serviceOnline,
  'total' => $serviceTotal,
  'status_queries' => $statusQueries,
  'status_queries_ok' => $statusQueriesOk,
];

if (isset($_GET['ajax']) && $_GET['ajax'] === 'status') {
  header('Content-Type: application/json; charset=UTF-8');
  echo json_encode(['ok' => !empty($health['ok']), 'health' => $health, 'services' => $serviceResults], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$cpuFilter = trim((string)($_GET['cpu'] ?? ''));
$filtered = array_values(array_filter($rows, static function(array $row) use ($q, $statusFilter, $cpuFilter, $sessionMap, $sessionUsage): bool {
  $haystack = $row['name'] . ' ' . $row['slug'] . ' ' . $row['session_company_id'] . ' ' . $row['source_label'] . ' ' . (string)($row['contact_phone'] ?? '');
  if ($q !== '' && stripos($haystack, $q) === false) return false;
  $status = support_wa_row_status($row, $sessionMap, $sessionUsage);
  if ($statusFilter !== '' && $status !== $statusFilter) return false;

  if ($cpuFilter !== '' && ($row['provider'] ?? 'unofficial') !== 'official') {
    $sid = (string)($row['session_company_id'] ?? '');
    $info = support_wa_session_info($row, $sessionMap);
    if (empty($info['_cpu_telemetry'])) return false;
    $current = array_key_exists('cpu_pct', $info) ? (float)$info['cpu_pct'] : null;
    $hot = (int)($info['cpu_hot_samples'] ?? 0);
    $peak = (float)($info['cpu_peak_pct'] ?? 0);
    $attentionNow = $current !== null ? $current >= 85.0 : $hot > 0;
    if ($cpuFilter === 'attention' && !$attentionNow) return false;
    if ($cpuFilter === 'peak' && $peak < 85) return false;
    if ($cpuFilter === 'stable' && $attentionNow) return false;
  }
  return true;
}));

$counts = ['connected'=>0,'initializing'=>0,'qr'=>0,'disconnected'=>0,'stopped'=>0,'stored'=>0,'cpu_attention'=>0,'cpu_peak'=>0,'cpu_telemetry'=>0,'webjs'=>0,'official'=>0,'conflict'=>0];
foreach ($rows as $row) {
  $status = support_wa_row_status($row, $sessionMap, $sessionUsage);
  if (isset($counts[$status])) $counts[$status]++;
  elseif (in_array($status, ['invalid'], true)) $counts['disconnected']++;
  else $counts['disconnected']++;
  $sid = (string)($row['session_company_id'] ?? '');
  $info = support_wa_session_info($row, $sessionMap);
  if (($row['provider'] ?? 'unofficial') !== 'official') {
    $counts['webjs']++;
    if (!empty($info['_cpu_telemetry'])) {
      $counts['cpu_telemetry']++;
      $currentCpu = array_key_exists('cpu_pct', $info) ? (float)$info['cpu_pct'] : null;
      $attentionNow = $currentCpu !== null ? $currentCpu >= 85.0 : (int)($info['cpu_hot_samples'] ?? 0) > 0;
      if ($attentionNow) $counts['cpu_attention']++;
      if ((float)($info['cpu_peak_pct'] ?? 0) >= 85) $counts['cpu_peak']++;
    }
  }
}

$flash = $_SESSION['support_wa_flash'] ?? null;
unset($_SESSION['support_wa_flash']);
$csrf = support_csrf_token();
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>WhatsApp - Suporte Nexa Próspero</title>
<style>
:root{--bg:#f4f7fb;--card:#fff;--text:#0b1f5b;--muted:#64748b;--line:#dce7f5;--primary:#2454d6;--cyan:#00b8ff;--ok:#15803d;--warn:#b45309;--danger:#b91c1c;--gray:#475569;--shadow:0 14px 36px rgba(11,31,91,.08)}*{box-sizing:border-box}body{margin:0;background:var(--bg);font-family:"Segoe UI",Arial,sans-serif;color:var(--text)}.shell{max-width:1500px;margin:auto;padding:20px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:15px 18px;border-radius:20px;background:linear-gradient(135deg,#0b1f5b,#2454d6);color:#fff}.brand{display:flex;align-items:center;gap:12px}.brand img{width:44px;height:44px;background:#fff;border-radius:12px;padding:4px}.brand strong{font-size:18px}.nav{display:flex;gap:8px;flex-wrap:wrap}.nav a{color:#eaf4ff;text-decoration:none;font-weight:800;padding:9px 13px;border-radius:999px}.nav a.active,.nav a:hover{background:rgba(255,255,255,.16);color:#fff}.user{font-size:12px;text-align:right}.user strong{display:block;font-size:13px}.hero{display:flex;justify-content:space-between;gap:16px;align-items:end;padding:24px 3px 14px}.hero h1{margin:0;font-size:30px}.hero p{margin:5px 0 0;color:var(--muted)}.hero-side{display:flex;align-items:center;gap:8px;flex-wrap:wrap;justify-content:flex-end}.service{font-size:12px;font-weight:900;padding:8px 12px;border-radius:999px;background:#dcfce7;color:#166534}.service.off{background:#fee2e2;color:#991b1b}.refresh-info{font-size:11px;color:var(--muted)}.stats{display:grid;grid-template-columns:repeat(8,minmax(0,1fr));gap:10px;margin-bottom:14px}.stat{background:#fff;border:1px solid var(--line);border-radius:16px;padding:12px 14px;box-shadow:var(--shadow);min-width:0}.stat small{display:block;color:var(--muted);font-weight:800;line-height:1.25}.stat strong{font-size:24px}.stat.attention{border-color:#fecaca;background:#fffafa}.stat.attention strong{color:var(--danger)}.stat.peak{border-color:#fde68a;background:#fffdf5}.stat.peak strong{color:#a16207}.filters{display:grid;grid-template-columns:minmax(260px,1fr) 220px 190px auto auto;gap:10px;background:#fff;padding:14px;border:1px solid var(--line);border-radius:18px;margin-bottom:14px}.filters input,.filters select{height:44px;border:1px solid var(--line);border-radius:12px;padding:0 12px;font-size:14px;min-width:0}.btn{border:0;border-radius:12px;padding:10px 14px;font-weight:900;cursor:pointer;white-space:nowrap}.btn-primary{background:linear-gradient(135deg,var(--primary),var(--cyan));color:#fff}.btn-light{background:#fff;color:var(--text);border:1px solid #cbd5e1}.btn-warn{background:#fff7ed;color:#9a3412;border:1px solid #fed7aa}.btn-danger{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.flash{padding:12px 14px;border-radius:14px;margin-bottom:14px;font-weight:800;background:#ecfdf5;color:#166534;border:1px solid #bbf7d0}.flash.bad{background:#fef2f2;color:#991b1b;border-color:#fecaca}.table-wrap{overflow:auto;background:#fff;border:1px solid var(--line);border-radius:18px;box-shadow:var(--shadow)}table{width:100%;border-collapse:collapse;min-width:1260px}th,td{padding:12px 11px;border-bottom:1px solid #edf2f7;text-align:left;font-size:13px;vertical-align:middle}th{background:#f8fbff;color:#475569;font-size:11px;text-transform:uppercase;letter-spacing:.04em}.company strong{display:block}.company small{display:block;color:var(--muted)}.source{display:inline-flex;margin-bottom:5px;border-radius:999px;padding:4px 7px;background:#eef6ff;color:#1d4ed8;font-size:10px;font-weight:900}.badge{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:6px 9px;font-weight:900;font-size:11px}.badge::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.connected{background:#ecfdf5;color:#15803d}.initializing{background:#eff6ff;color:#1d4ed8}.qr{background:#fff7ed;color:#c2410c}.disconnected,.invalid,.conflict{background:#fef2f2;color:#b91c1c}.stopped{background:#f1f5f9;color:#475569}.stored{background:#f8fafc;color:#64748b}.official{background:#f0fdf4;color:#166534}.cpu-box{display:flex;flex-direction:column;gap:3px}.cpu-state{display:inline-flex;align-items:center;gap:6px;width:max-content;border-radius:999px;padding:5px 8px;font-size:11px;font-weight:900}.cpu-state::before{content:"";width:7px;height:7px;border-radius:50%;background:currentColor}.cpu-stable{background:#ecfdf5;color:#15803d}.cpu-attention{background:#fef2f2;color:#b91c1c}.cpu-unknown{background:#f1f5f9;color:#64748b}.cpu-peak{font-size:11px;color:var(--muted)}.cpu-peak.hot{color:#a16207;font-weight:900}.actions{display:flex;gap:6px;flex-wrap:wrap}.actions form{margin:0}.muted{color:var(--muted)}.small{font-size:11px}.conflict-note{color:#b91c1c;font-weight:900}.empty{padding:28px;text-align:center;color:var(--muted)}.note{margin-top:12px;color:var(--muted);font-size:12px;line-height:1.5;background:#fff;border:1px solid var(--line);border-radius:14px;padding:12px 14px}.legend{display:flex;gap:12px;flex-wrap:wrap;margin:0 0 12px 2px;color:var(--muted);font-size:11px}.legend strong{color:var(--text)}@media(max-width:1250px){.stats{grid-template-columns:repeat(4,1fr)}.filters{grid-template-columns:1fr 200px 180px auto auto}}@media(max-width:900px){.filters{grid-template-columns:1fr}.hero{align-items:flex-start;flex-direction:column}.hero-side{justify-content:flex-start}.topbar{align-items:flex-start;flex-direction:column}.user{text-align:left}}@media(max-width:520px){.stats{grid-template-columns:repeat(2,1fr)}.shell{padding:10px}}
</style>
</head>
<body><div class="shell">
<header class="topbar"><div class="brand"><img src="/assets/logo-nexa-v6-2.png" alt="Nexa"><div><strong>Portal do Suporte</strong><div class="nav"><a href="/suporte/index.php">Empresas</a><a href="/suporte/monitor_fiscal.php">Monitor Fiscal</a><a class="active" href="/suporte/whatsapp.php">WhatsApp</a><a href="/suporte/monitor_saude.php">Saúde do Sistema</a></div></div></div><div class="user"><strong><?= e((string)$user['name']) ?></strong><?= e((string)$user['email']) ?> · <a style="color:#fff" href="/suporte/logout.php">Sair</a></div></header>
<section class="hero"><div><h1>Monitor de WhatsApp</h1><p>Visão operacional das sessões WebJS, consultando o mesmo status usado em Admin &gt; WhatsApp.</p></div><div class="hero-side"><span class="refresh-info">Atualizado em <?= date('d/m/Y H:i:s') ?><?php if ((int)($health['status_queries'] ?? 0) > 0): ?> · status direto <?= (int)($health['status_queries_ok'] ?? 0) ?>/<?= (int)$health['status_queries'] ?><?php endif; ?></span><a class="btn btn-light" href="/suporte/whatsapp.php<?= $q!=='' || $statusFilter!=='' || $cpuFilter!=='' ? '?' . e(http_build_query(['q'=>$q,'status'=>$statusFilter,'cpu'=>$cpuFilter])) : '' ?>">Atualizar</a><span class="service <?= empty($health['ok']) ? 'off' : '' ?>"><?= (int)($health['total'] ?? 1) > 1 ? ('Serviços: '.(int)($health['online'] ?? 0).'/'.(int)($health['total'] ?? 0).' online') : ('Serviço: '.(!empty($health['ok']) ? 'online' : 'indisponível')) ?></span></div></section>
<?php if (is_array($flash)): ?><div class="flash <?= empty($flash['ok']) ? 'bad' : '' ?>"><?= e((string)$flash['message']) ?></div><?php endif; ?>
<div class="stats">
  <div class="stat"><small>Conectadas</small><strong><?= (int)$counts['connected'] ?></strong></div>
  <div class="stat"><small>Inicializando</small><strong><?= (int)$counts['initializing'] ?></strong></div>
  <div class="stat"><small>Aguardando QR</small><strong><?= (int)$counts['qr'] ?></strong></div>
  <div class="stat"><small>Desconectadas</small><strong><?= (int)$counts['disconnected'] ?></strong></div>
  <div class="stat"><small>Em repouso/paradas</small><strong><?= (int)$counts['stored'] + (int)$counts['stopped'] ?></strong></div>
  <div class="stat attention"><small>CPU em atenção agora</small><strong><?= (int)$counts['cpu_telemetry'] > 0 ? (int)$counts['cpu_attention'] : '—' ?></strong></div>
  <div class="stat peak"><small>Pico de CPU ≥ 85%</small><strong><?= (int)$counts['cpu_telemetry'] > 0 ? (int)$counts['cpu_peak'] : '—' ?></strong></div>
  <div class="stat"><small>API Oficial</small><strong><?= (int)$counts['official'] ?></strong></div>
</div>
<form class="filters" method="get"><input name="q" value="<?= e($q) ?>" placeholder="Empresa, Central SaaS, Revenda ou ID da sessão"><select name="status"><option value="">Todos os status</option><?php foreach (['connected'=>'Conectado','initializing'=>'Inicializando','qr'=>'Aguardando QR','disconnected'=>'Desconectado','stopped'=>'Parado','stored'=>'Em repouso','official'=>'API Oficial','conflict'=>'Conflito de sessão','invalid'=>'Configuração inválida'] as $k=>$v): ?><option value="<?= e($k) ?>" <?= $statusFilter===$k?'selected':'' ?>><?= e($v) ?></option><?php endforeach; ?></select><select name="cpu"><option value="">CPU: todas</option><option value="attention" <?= $cpuFilter==='attention'?'selected':'' ?>>Em atenção agora</option><option value="peak" <?= $cpuFilter==='peak'?'selected':'' ?>>Pico ≥ 85%</option><option value="stable" <?= $cpuFilter==='stable'?'selected':'' ?>>Sem amostra alta</option></select><button class="btn btn-primary">Filtrar</button><a class="btn btn-light" href="/suporte/whatsapp.php">Limpar</a></form>
<div class="legend"><span><strong>CPU em atenção agora</strong>: sessão com amostras altas registradas pelo serviço.</span><span><strong>Pico</strong>: maior percentual registrado; não representa necessariamente o consumo atual.</span></div>
<div class="table-wrap"><table><thead><tr><th>Origem / Empresa</th><th>Status</th><th>Provider / Sessão</th><th>Browser</th><th>CPU / Pico</th><th>Reconexões</th><th>Última atualização</th><th>Ações</th></tr></thead><tbody>
<?php if (!$filtered): ?><tr><td colspan="8" class="empty">Nenhuma configuração de WhatsApp encontrada.</td></tr><?php endif; ?>
<?php foreach ($filtered as $row):
  $sid=(string)$row['session_company_id'];
  $info=support_wa_session_info($row,$sessionMap);
  $st=support_wa_row_status($row,$sessionMap,$sessionUsage);
  $cpuPeak=(float)($info['cpu_peak_pct']??0);
  $cpuHotSamples=(int)($info['cpu_hot_samples']??0);
  $cpuState=support_wa_cpu_state($info);
  $sessionKey=support_wa_session_key($row);
  $collision=($sid!=='' && ($sessionUsage[$sessionKey]??0)>1);
  $manageable=(($row['provider']??'')==='unofficial' && support_wa_valid_session_id($sid) && !$collision);
?>
<tr>
<td class="company"><span class="source"><?= e((string)$row['source_label']) ?></span><strong><?= e((string)$row['name']) ?></strong><small><?= e((string)$row['slug']) ?><?= !empty($row['company_id']) ? ' · empresa #'.(int)$row['company_id'] : '' ?></small><?php if (!empty($row['contact_phone'])): ?><small>WhatsApp: <?= e((string)$row['contact_phone']) ?></small><?php endif; ?></td>
<td><span class="badge <?= e($st) ?>"><?= e(support_wa_status_label($st)) ?></span><?php if (!empty($info['last_error'])): ?><div class="small muted" title="<?= e((string)$info['last_error']) ?>"><?= e(mb_strimwidth((string)$info['last_error'],0,90,'…')) ?></div><?php endif; ?><?php if ($collision): ?><div class="small conflict-note">ID compartilhado por <?= (int)$sessionUsage[$sessionKey] ?> configurações.</div><?php endif; ?></td>
<td><strong><?= ($row['provider']==='official') ? 'Meta Cloud API' : 'WebJS' ?></strong><div class="small muted"><?= $row['provider']==='official' ? 'sem Chromium/QR no monitor' : ('company-'.e($sid)) ?></div><?php if ($row['provider']!=='official'): ?><div class="small muted"><?php if (array_key_exists('stored',$info)): ?><?= !empty($info['stored']) ? 'sessão persistida' : 'sem perfil salvo' ?><?php else: ?>persistência não informada<?php endif; ?></div><?php if ((int)($health['total'] ?? 1) > 1): ?><div class="small muted" title="<?= e((string)$row['service_url']) ?>">serviço: <?= e((string)parse_url((string)$row['service_url'], PHP_URL_HOST)) ?><?= parse_url((string)$row['service_url'], PHP_URL_PORT) ? ':'.(int)parse_url((string)$row['service_url'], PHP_URL_PORT) : '' ?></div><?php endif; ?><?php endif; ?></td>
<td><?= !empty($info['browser_pid']) ? '#'.(int)$info['browser_pid'] : '—' ?></td>
<td><?php if ($row['provider']==='official'): ?>—<?php elseif ($cpuState==='unknown'): ?><div class="cpu-box"><span class="cpu-state cpu-unknown">Sem telemetria</span><span class="small muted">o serviço não informou métricas de CPU</span></div><?php else: ?><div class="cpu-box"><span class="cpu-state <?= $cpuState==='attention'?'cpu-attention':'cpu-stable' ?>"><?= $cpuState==='attention' ? 'Em atenção' : 'Estável' ?></span><?php if (array_key_exists('cpu_pct',$info)): ?><span class="small muted">agora: <?= number_format((float)$info['cpu_pct'],1,',','.') ?>%</span><?php endif; ?><span class="cpu-peak <?= $cpuPeak>=85?'hot':'' ?>">pico: <?= number_format($cpuPeak,1,',','.') ?>%</span><?php if ($cpuHotSamples>0): ?><span class="small muted">amostras ≥ 85%: <?= $cpuHotSamples ?></span><?php endif; ?><?php if ((int)($info['cpu_watchdog_trips']??0)>0): ?><span class="small muted">watchdog: <?= (int)$info['cpu_watchdog_trips'] ?></span><?php endif; ?><?php if (!empty($info['_cpu_source'])): ?><span class="small muted">fonte: <?= e((string)$info['_cpu_source']) ?></span><?php endif; ?></div><?php endif; ?></td>
<td><?= $row['provider']==='official' ? '—' : (array_key_exists('reconnect_attempts',$info) ? (int)$info['reconnect_attempts'] : '—') ?></td>
<td><?= e(support_wa_format_updated_at((string)($info['updated_at'] ?? ''))) ?><div class="small muted">horário de Brasília<?php if (!empty($info['_monitor_source'])): ?> · <?= e((string)$info['_monitor_source']) ?><?php endif; ?></div></td>
<td><div class="actions">
<?php if ($manageable): ?>
<form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="owner_key" value="<?= e((string)$row['owner_key']) ?>"><input type="hidden" name="session_company_id" value="<?= e($sid) ?>"><input type="hidden" name="action" value="restart"><button class="btn btn-light" onclick="return confirm('Reiniciar somente esta sessão preservando a autenticação?')">Reiniciar sessão</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="owner_key" value="<?= e((string)$row['owner_key']) ?>"><input type="hidden" name="session_company_id" value="<?= e($sid) ?>"><input type="hidden" name="action" value="stop"><button class="btn btn-warn" onclick="return confirm('Parar esta sessão? A autenticação será preservada.')">Parar</button></form>
<form method="post"><input type="hidden" name="csrf" value="<?= e($csrf) ?>"><input type="hidden" name="owner_key" value="<?= e((string)$row['owner_key']) ?>"><input type="hidden" name="session_company_id" value="<?= e($sid) ?>"><input type="hidden" name="action" value="new_qr"><button class="btn btn-danger" onclick="return confirm('Gerar novo QR? A autenticação atual desta sessão será removida e será necessário ler um novo QR Code.')">Novo QR</button></form>
<?php elseif ($row['provider']==='official'): ?><span class="small muted">Gerenciado pela configuração Meta/API.</span><?php elseif ($collision): ?><span class="small conflict-note">Ações bloqueadas até separar os IDs.</span><?php else: ?><span class="small muted">Configure um ID WebJS válido.</span><?php endif; ?>
</div></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<div class="note"><strong>Como esta versão lê o monitor:</strong> o estado da conexão é confirmado pela mesma rota <code>/status</code> usada em <strong>Admin &gt; WhatsApp</strong>. Para serviços WebJS executados nesta mesma VPS (<code>127.0.0.1</code>/<code>localhost</code>), a CPU do Chromium é medida diretamente pela árvore de processos Linux em <code>/proc</code>, sem reiniciar nem alterar as sessões. <strong>100% equivale a um núcleo ocupado</strong>; uma sessão com vários processos pode ultrapassar 100%. O pico é reiniciado quando o browser da sessão recebe um novo PID. Serviços remotos continuam dependendo da telemetria fornecida pelo próprio serviço. Nesta carga, <?= (int)$counts['cpu_telemetry'] ?> de <?= (int)$counts['webjs'] ?> sessões WebJS forneceram métricas de CPU.</div>
</div></body></html>
