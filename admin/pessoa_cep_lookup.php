<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function nexa_cep_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function nexa_cep_digits(string $value): string { return preg_replace('/\D+/', '', $value) ?: ''; }
function nexa_cep_http_json(string $url): ?array {
  $body = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 8,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NexaProspero/1.9.3'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (is_string($raw) && $raw !== '' && $code >= 200 && $code < 300) $body = $raw;
  } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    $ctx = stream_context_create(['http'=>[
      'method'=>'GET','timeout'=>8,
      'header'=>"Accept: application/json\r\nUser-Agent: NexaProspero/1.9.3\r\n",
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (is_string($raw) && $raw !== '') $body = $raw;
  }
  if (!is_string($body) || $body === '') return null;
  $data = json_decode($body, true);
  return is_array($data) ? $data : null;
}
function nexa_cep_text($value): string { return trim((string)($value ?? '')); }

$cep = nexa_cep_digits((string)($_GET['cep'] ?? ''));
if (strlen($cep) !== 8) nexa_cep_json(['ok'=>false,'error'=>'CEP inválido. Informe 8 dígitos.'], 422);

$cacheDir = dirname(__DIR__) . '/storage/cep_lookup';
$cacheFile = $cacheDir . '/' . $cep . '.json';
if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 604800) {
  $cached = json_decode((string)@file_get_contents($cacheFile), true);
  if (is_array($cached) && !empty($cached['ok'])) {
    $cached['cached'] = true;
    nexa_cep_json($cached);
  }
}

// BrasilAPI agrega múltiplos provedores. ViaCEP permanece como fallback independente.
$br = nexa_cep_http_json('https://brasilapi.com.br/api/cep/v1/' . rawurlencode($cep));
$via = null;
if (!$br || empty($br['street']) || empty($br['city'])) {
  $via = nexa_cep_http_json('https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/');
  if (is_array($via) && !empty($via['erro'])) $via = null;
}
if (!$br && !$via) nexa_cep_json(['ok'=>false,'error'=>'Não foi possível consultar o CEP nas fontes disponíveis.'], 502);

$source = $br ? 'BrasilAPI' : 'ViaCEP';
$ibge = '';
if ($br && is_array($br['ibge'] ?? null)) $ibge = nexa_cep_digits(nexa_cep_text($br['ibge']['city'] ?? ''));
if ($ibge === '' && $via) $ibge = nexa_cep_digits(nexa_cep_text($via['ibge'] ?? ''));

$data = [
  'cep' => $cep,
  'logradouro' => nexa_cep_text($br['street'] ?? ($via['logradouro'] ?? '')),
  'complemento' => nexa_cep_text($via['complemento'] ?? ''),
  'bairro' => nexa_cep_text($br['neighborhood'] ?? ($via['bairro'] ?? '')),
  'cidade' => nexa_cep_text($br['city'] ?? ($via['localidade'] ?? '')),
  'uf' => strtoupper(nexa_cep_text($br['state'] ?? ($via['uf'] ?? ''))),
  'city_ibge' => $ibge,
  'source' => $source,
];
$result = ['ok'=>true,'cached'=>false,'data'=>$data];
try {
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
  if (is_dir($cacheDir) && is_writable($cacheDir)) @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
} catch (Throwable $e) {}

nexa_cep_json($result);
