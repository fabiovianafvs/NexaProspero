<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';

$lock = fopen(sys_get_temp_dir() . '/nexa_whatsapp_keepalive.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

const WA_KEEPALIVE_SERVICE = 'http://127.0.0.1:3333';

function wa_keepalive_request(string $method, string $path, array $payload = []): array {
  $url = WA_KEEPALIVE_SERVICE . $path;
  $method = strtoupper($method);
  $ch = curl_init($url);
  $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_CONNECTTIMEOUT => 2,
    CURLOPT_TIMEOUT => 8,
    CURLOPT_CUSTOMREQUEST => $method,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
  ]);
  if ($body !== '') curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
  $raw = curl_exec($ch);
  $err = curl_error($ch);
  curl_close($ch);
  if ($raw === false) return ['ok'=>false,'error'=>$err ?: 'Serviço indisponível'];
  $data = json_decode((string)$raw, true);
  return is_array($data) ? $data : ['ok'=>false,'error'=>'Resposta inválida'];
}

function wa_keepalive_sid(string $value): string {
  $id = preg_replace('/\D+/', '', $value) ?: '';
  return preg_match('/^[1-9][0-9]{0,19}$/', $id) ? $id : '';
}

$desired = [];

// Empresas Nexa com automação WhatsApp habilitada.
try {
  $st = $pdo->query("SELECT c.id, c.name, ac.cfg_json FROM companies c JOIN app_config ac ON ac.company_id=c.id AND ac.cfg_key='whatsapp'");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $cfg = json_decode((string)$r['cfg_json'], true) ?: [];
    if (empty($cfg['enabled'])) continue;
    $sid = wa_keepalive_sid((string)($cfg['session_company_id'] ?? ''));
    if ($sid === '') $sid = (string)(int)$r['id'];
    if ($sid !== '') $desired[$sid] = 'Empresa ' . (string)$r['name'];
  }
} catch (Throwable $e) {}

// Central SaaS, apenas enquanto estiver usando WebJS.
try {
  $cfg = saas_whatsapp_load_config($pdo);
  if (!empty($cfg['enabled']) && (string)$cfg['provider'] === 'unofficial') {
    $sid = wa_keepalive_sid(saas_whatsapp_unofficial_instance_id($cfg));
    if ($sid !== '') $desired[$sid] = 'Central SaaS';
  }
} catch (Throwable $e) {}

// Revendas habilitadas no provider WebJS.
try {
  $st = $pdo->query("SELECT rw.reseller_company_id, rw.unofficial_instance_id, c.name
                    FROM saas_reseller_whatsapp_config rw
                    LEFT JOIN companies c ON c.id=rw.reseller_company_id
                    WHERE rw.enabled=1 AND rw.provider='unofficial'");
  while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
    $sid = wa_keepalive_sid((string)$r['unofficial_instance_id']);
    if ($sid !== '') $desired[$sid] = 'Revenda ' . (trim((string)($r['name'] ?? '')) ?: ('#' . (int)$r['reseller_company_id']));
  }
} catch (Throwable $e) {}

if (!$desired) exit(0);

$list = wa_keepalive_request('GET', '/admin/sessions');
if (empty($list['ok'])) {
  fwrite(STDERR, '[whatsapp-keepalive] Serviço indisponível: ' . (string)($list['error'] ?? '') . PHP_EOL);
  exit(1);
}

$map = [];
foreach ((array)($list['items'] ?? []) as $item) {
  if (is_array($item) && !empty($item['company_id'])) $map[(string)$item['company_id']] = $item;
}

$started = 0;
foreach ($desired as $sid => $label) {
  $info = $map[$sid] ?? null;
  if (!$info) continue;
  $status = (string)($info['status'] ?? '');
  $stored = !empty($info['stored']);

  // Somente acorda sessões autenticadas persistidas que ficaram em repouso após
  // reinício do PM2/VPS. Não interfere em QR, STOP manual, erro ou sessão desconectada.
  if ($status !== 'stored' || !$stored) continue;

  $res = wa_keepalive_request('POST', '/admin/session/start', ['company_id'=>$sid]);
  if (!empty($res['ok'])) {
    $started++;
    echo '[whatsapp-keepalive] Iniciando ' . $label . ' (company-' . $sid . ')' . PHP_EOL;
  } else {
    fwrite(STDERR, '[whatsapp-keepalive] Falha ' . $label . ': ' . (string)($res['error'] ?? 'erro desconhecido') . PHP_EOL);
  }
}

if ($started > 0) echo '[whatsapp-keepalive] Sessões acordadas: ' . $started . PHP_EOL;
