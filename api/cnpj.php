<?php
declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

function cnpj_digits(string $value): string {
  return preg_replace('/\D+/', '', $value) ?? '';
}

function json_out(array $payload, int $status = 200): void {
  http_response_code($status);
  echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

function http_get_json(string $url): array {
  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_FOLLOWLOCATION => true,
      CURLOPT_CONNECTTIMEOUT => 6,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_USERAGENT => 'NexaProspero/1.0 CNPJ Lookup',
      CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $body === '') {
      throw new RuntimeException($err !== '' ? $err : 'Não foi possível consultar o serviço de CNPJ.');
    }
    $data = json_decode((string)$body, true);
    if (!is_array($data)) {
      throw new RuntimeException('Resposta inválida do serviço de CNPJ.');
    }
    if ($code < 200 || $code >= 300) {
      $message = (string)($data['message'] ?? $data['erro'] ?? $data['error'] ?? 'CNPJ não encontrado.');
      throw new RuntimeException($message);
    }
    return $data;
  }

  $context = stream_context_create([
    'http' => [
      'timeout' => 10,
      'header' => "Accept: application/json\r\nUser-Agent: NexaProspero/1.0 CNPJ Lookup\r\n",
    ],
  ]);
  $body = @file_get_contents($url, false, $context);
  if ($body === false || $body === '') {
    throw new RuntimeException('Não foi possível consultar o serviço de CNPJ.');
  }
  $data = json_decode((string)$body, true);
  if (!is_array($data)) {
    throw new RuntimeException('Resposta inválida do serviço de CNPJ.');
  }
  return $data;
}

$cnpj = cnpj_digits((string)($_GET['cnpj'] ?? ''));
if (strlen($cnpj) !== 14) {
  json_out(['ok' => false, 'message' => 'Informe um CNPJ válido com 14 dígitos.'], 422);
}

try {
  $data = http_get_json('https://brasilapi.com.br/api/cnpj/v1/' . rawurlencode($cnpj));

  $telefone = (string)($data['ddd_telefone_1'] ?? '');
  if ($telefone === '') $telefone = (string)($data['ddd_telefone_2'] ?? '');

  $uf = strtoupper(substr(trim((string)($data['uf'] ?? '')), 0, 2));
  $inscricaoEstadual = '';
  $inscricoes = $data['inscricoes_estaduais'] ?? [];
  if (is_array($inscricoes)) {
    foreach ($inscricoes as $ieRow) {
      if (!is_array($ieRow)) continue;
      $ie = trim((string)($ieRow['inscricao_estadual'] ?? ''));
      $ieUf = strtoupper(substr(trim((string)($ieRow['uf'] ?? $ieRow['sigla_uf'] ?? '')), 0, 2));
      $active = !array_key_exists('ativo', $ieRow) || !empty($ieRow['ativo']);
      if ($ie !== '' && $active && ($ieUf === '' || $uf === '' || $ieUf === $uf)) {
        $inscricaoEstadual = $ie;
        break;
      }
    }
  }

  $normalized = [
    'cnpj' => $cnpj,
    'razao_social' => (string)($data['razao_social'] ?? $data['nome'] ?? ''),
    'nome_fantasia' => (string)($data['nome_fantasia'] ?? ''),
    'telefone' => cnpj_digits($telefone),
    'email' => (string)($data['email'] ?? ''),
    'cep' => cnpj_digits((string)($data['cep'] ?? '')),
    'endereco' => trim((string)($data['logradouro'] ?? '')),
    'numero' => trim((string)($data['numero'] ?? '')),
    'complemento' => trim((string)($data['complemento'] ?? '')),
    'bairro' => trim((string)($data['bairro'] ?? '')),
    'cidade' => trim((string)($data['municipio'] ?? '')),
    'estado' => $uf,
    'city_ibge' => cnpj_digits((string)($data['codigo_municipio_ibge'] ?? $data['codigo_municipio'] ?? '')),
    'inscricao_estadual' => $inscricaoEstadual,
    'situacao_cadastral' => (string)($data['descricao_situacao_cadastral'] ?? ''),
    'data_inicio_atividade' => (string)($data['data_inicio_atividade'] ?? ''),
  ];

  if ($normalized['nome_fantasia'] === '') {
    $normalized['nome_fantasia'] = $normalized['razao_social'];
  }

  json_out(['ok' => true, 'source' => 'BrasilAPI', 'data' => $normalized]);
} catch (Throwable $e) {
  json_out(['ok' => false, 'message' => $e->getMessage()], 404);
}
