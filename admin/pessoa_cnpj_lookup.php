<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, max-age=0');

function nexa_cnpj_json(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}
function nexa_cnpj_digits(string $value): string { return preg_replace('/\D+/', '', $value) ?: ''; }
function nexa_cnpj_valid(string $cnpj): bool {
  $cnpj = nexa_cnpj_digits($cnpj);
  if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
  foreach ([12,13] as $pos) {
    $len = $pos;
    $sum = 0;
    $weight = $len - 7;
    for ($i=0; $i<$len; $i++) {
      $sum += ((int)$cnpj[$i]) * $weight;
      $weight--;
      if ($weight < 2) $weight = 9;
    }
    $digit = 11 - ($sum % 11);
    if ($digit >= 10) $digit = 0;
    if ((int)$cnpj[$pos] !== $digit) return false;
  }
  return true;
}
function nexa_cnpj_http_json(string $url): ?array {
  $body = null;
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 9,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_HTTPHEADER => ['Accept: application/json', 'User-Agent: NexaProspero/1.9.3'],
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if (is_string($raw) && $raw !== '' && $code >= 200 && $code < 300) $body = $raw;
  } elseif (filter_var(ini_get('allow_url_fopen'), FILTER_VALIDATE_BOOLEAN)) {
    $ctx = stream_context_create(['http'=>[
      'method'=>'GET', 'timeout'=>9,
      'header'=>"Accept: application/json\r\nUser-Agent: NexaProspero/1.9.3\r\n"
    ]]);
    $raw = @file_get_contents($url, false, $ctx);
    if (is_string($raw) && $raw !== '') $body = $raw;
  }
  if (!is_string($body) || $body === '') return null;
  $data = json_decode($body, true);
  return is_array($data) ? $data : null;
}
function nexa_cnpj_text($value): string { return trim((string)($value ?? '')); }

$cnpj = nexa_cnpj_digits((string)($_GET['cnpj'] ?? ''));
if (!nexa_cnpj_valid($cnpj)) nexa_cnpj_json(['ok'=>false,'error'=>'CNPJ inválido. Confira os 14 dígitos.'], 422);

$cacheDir = dirname(__DIR__) . '/storage/cnpj_lookup';
$cacheFile = $cacheDir . '/' . $cnpj . '.json';
$cachedFallback = null;
if (is_file($cacheFile) && (time() - (int)@filemtime($cacheFile)) < 43200) {
  $cached = json_decode((string)@file_get_contents($cacheFile), true);
  if (is_array($cached) && !empty($cached['ok'])) {
    $cachedFallback = $cached;
    $cachedIe = trim((string)($cached['data']['state_registration'] ?? ''));
    $cacheAge = time() - (int)@filemtime($cacheFile);
    // Quando a IE já foi encontrada, o cache de 12h é suficiente. Se a consulta
    // anterior ficou sem IE (por exemplo, limite momentâneo do CNPJ.ws), permite
    // nova tentativa depois de 60 segundos sem perder os dados básicos em cache.
    if ($cachedIe !== '' || $cacheAge < 60 || empty($_GET['refresh_ie'])) {
      $cached['cached'] = true;
      nexa_cnpj_json($cached);
    }
  }
}

$br = nexa_cnpj_http_json('https://brasilapi.com.br/api/cnpj/v1/' . rawurlencode($cnpj));
$ws = nexa_cnpj_http_json('https://publica.cnpj.ws/cnpj/' . rawurlencode($cnpj));
if (!$br && !$ws) {
  if (is_array($cachedFallback) && !empty($cachedFallback['ok'])) {
    $cachedFallback['cached'] = true;
    $cachedFallback['stale'] = true;
    nexa_cnpj_json($cachedFallback);
  }
  nexa_cnpj_json(['ok'=>false,'error'=>'Não foi possível consultar o CNPJ nas fontes disponíveis. Tente novamente em instantes.'], 502);
}

$est = is_array($ws['estabelecimento'] ?? null) ? $ws['estabelecimento'] : [];
$uf = strtoupper(nexa_cnpj_text($br['uf'] ?? ($est['estado']['sigla'] ?? '')));
$ies = is_array($est['inscricoes_estaduais'] ?? null) ? $est['inscricoes_estaduais'] : [];
$selectedIe = null;
foreach ($ies as $ie) {
  if (!is_array($ie) || empty($ie['ativo'])) continue;
  $ieUf = strtoupper(nexa_cnpj_text($ie['estado']['sigla'] ?? ''));
  if ($uf !== '' && $ieUf === $uf) { $selectedIe = $ie; break; }
  if ($selectedIe === null) $selectedIe = $ie;
}
if ($selectedIe === null) {
  foreach ($ies as $ie) {
    if (is_array($ie) && nexa_cnpj_text($ie['inscricao_estadual'] ?? '') !== '') { $selectedIe = $ie; break; }
  }
}

$ddd1 = nexa_cnpj_text($est['ddd1'] ?? '');
$tel1 = nexa_cnpj_text($est['telefone1'] ?? '');
$wsPhone = trim($ddd1 . $tel1);
$brPhone = nexa_cnpj_text($br['ddd_telefone_1'] ?? ($br['ddd_telefone_2'] ?? ''));
$typeStreet = nexa_cnpj_text($est['tipo_logradouro'] ?? '');
$street = nexa_cnpj_text($est['logradouro'] ?? '');
$wsStreet = trim(($typeStreet !== '' ? $typeStreet . ' ' : '') . $street);

$data = [
  'cnpj' => $cnpj,
  'razao_social' => nexa_cnpj_text($br['razao_social'] ?? ($ws['razao_social'] ?? '')),
  'nome_fantasia' => nexa_cnpj_text($br['nome_fantasia'] ?? ($est['nome_fantasia'] ?? '')),
  'situacao_cadastral' => nexa_cnpj_text($br['descricao_situacao_cadastral'] ?? ($br['situacao_cadastral'] ?? ($est['situacao_cadastral'] ?? ''))),
  'data_inicio_atividade' => nexa_cnpj_text($br['data_inicio_atividade'] ?? ($est['data_inicio_atividade'] ?? '')),
  'email' => nexa_cnpj_text($br['email'] ?? ($est['email'] ?? '')),
  'telefone' => $brPhone !== '' ? $brPhone : $wsPhone,
  'cep' => nexa_cnpj_digits(nexa_cnpj_text($br['cep'] ?? ($est['cep'] ?? ''))),
  'logradouro' => nexa_cnpj_text($br['logradouro'] ?? '') ?: $wsStreet,
  'numero' => nexa_cnpj_text($br['numero'] ?? ($est['numero'] ?? '')),
  'complemento' => nexa_cnpj_text($br['complemento'] ?? ($est['complemento'] ?? '')),
  'bairro' => nexa_cnpj_text($br['bairro'] ?? ($est['bairro'] ?? '')),
  'municipio' => nexa_cnpj_text($br['municipio'] ?? ($est['cidade']['nome'] ?? '')),
  'city_ibge' => nexa_cnpj_digits(nexa_cnpj_text($br['codigo_municipio_ibge'] ?? ($br['codigo_municipio'] ?? ($est['cidade']['ibge_id'] ?? '')))),
  'uf' => $uf,
  'state_registration' => nexa_cnpj_text($selectedIe['inscricao_estadual'] ?? ''),
  'state_registration_active' => $selectedIe === null ? null : (bool)($selectedIe['ativo'] ?? false),
  'state_registration_uf' => strtoupper(nexa_cnpj_text($selectedIe['estado']['sigla'] ?? '')),
  'municipal_registration' => '',
  'sources' => array_values(array_filter([$br ? 'BrasilAPI' : '', $ws ? 'CNPJ.ws' : ''])),
];

$result = ['ok'=>true,'cached'=>false,'data'=>$data];
try {
  if (!is_dir($cacheDir)) @mkdir($cacheDir, 0775, true);
  if (is_dir($cacheDir) && is_writable($cacheDir)) @file_put_contents($cacheFile, json_encode($result, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), LOCK_EX);
} catch (Throwable $e) {}

nexa_cnpj_json($result);
