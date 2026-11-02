<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cep_digits(string $value): string {
  return preg_replace('/\D+/', '', $value) ?? '';
}

function cep_json_out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function cep_http_get_json(string $url): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'NexaProspero/1.0 CEP Lookup',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === '') {
      throw new RuntimeException($error !== '' ? $error : 'Não foi possível consultar o serviço de CEP.');
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) throw new RuntimeException('Resposta inválida do serviço de CEP.');
    if ($code < 200 || $code >= 300) throw new RuntimeException('CEP não encontrado.');
    return $data;
  }

  $context = stream_context_create([
    'http' => [
      'timeout' => 10,
      'header' => "Accept: application/json\r\nUser-Agent: NexaProspero/1.0 CEP Lookup\r\n",
    ],
  ]);
  $body = @file_get_contents($url, false, $context);
  if ($body === false || $body === '') throw new RuntimeException('Não foi possível consultar o serviço de CEP.');
  $data = json_decode($body, true);
  if (!is_array($data)) throw new RuntimeException('Resposta inválida do serviço de CEP.');
  return $data;
}

$cep = cep_digits((string)($_GET['cep'] ?? ''));
if (strlen($cep) !== 8) {
  cep_json_out(['ok' => false, 'message' => 'Informe um CEP válido com 8 dígitos.'], 422);
}

try {
  $data = cep_http_get_json('https://viacep.com.br/ws/' . rawurlencode($cep) . '/json/');
  if (!empty($data['erro'])) throw new RuntimeException('CEP não encontrado.');

  cep_json_out([
    'ok' => true,
    'source' => 'ViaCEP',
    'data' => [
      'cep' => $cep,
      'logradouro' => trim((string)($data['logradouro'] ?? '')),
      'complemento' => trim((string)($data['complemento'] ?? '')),
      'bairro' => trim((string)($data['bairro'] ?? '')),
      'cidade' => trim((string)($data['localidade'] ?? '')),
      'uf' => strtoupper(substr(trim((string)($data['uf'] ?? '')), 0, 2)),
      'ibge' => cep_digits((string)($data['ibge'] ?? '')),
    ],
  ]);
} catch (Throwable $e) {
  cep_json_out(['ok' => false, 'message' => $e->getMessage()], 404);
}
