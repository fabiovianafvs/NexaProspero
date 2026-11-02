<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Itaú API Boletos (v3) / Cash Management.
 *
 * Implementado estritamente a partir do OpenAPI oficial 1.34.1 recebido em 04/09/2026.
 * Esta API oferece consultas de Francesa/movimentações e gestão do Webhook de boletos.
 * Ela NÃO possui endpoint de emissão/registro de títulos no arquivo OpenAPI recebido.
 */
require_once __DIR__ . '/itau_boleto.php';

if (!function_exists('itau_v3_base_url')) {
    function itau_v3_base_url(): string {
        return 'https://boletos.cloud.itau.com.br/boletos/v3';
    }
}

if (!function_exists('itau_v3_beneficiary_id')) {
    function itau_v3_beneficiary_id(string $value): string {
        $digits = preg_replace('/\D+/', '', $value) ?? '';
        if (strlen($digits) !== 12) {
            throw new RuntimeException('ID Beneficiário Itaú inválido. Informe 12 dígitos: Agência(4) + Conta(7) + DAC(1).');
        }
        return $digits;
    }
}

if (!function_exists('itau_v3_beneficiary_parts')) {
    function itau_v3_beneficiary_parts(string $value): array {
        $id = itau_v3_beneficiary_id($value);
        return [
            'id_beneficiario' => $id,
            'agencia' => substr($id, 0, 4),
            'conta' => substr($id, 4, 7),
            'dac' => substr($id, 11, 1),
        ];
    }
}

if (!function_exists('itau_v3_headers')) {
    function itau_v3_headers(array $rawCfg, string $accessToken, ?string $correlationId = null): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $clientId = trim((string)($cfg['api_client_id'] ?? ''));
        if ($clientId === '') throw new RuntimeException('Client ID Itaú não configurado.');
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . trim($accessToken),
            'x-itau-apikey' => $clientId,
            'x-itau-correlationID' => trim((string)$correlationId) ?: itau_boleto_uuid(),
        ];
    }
}

if (!function_exists('itau_v3_request')) {
    function itau_v3_request(array $rawCfg, string $method, string $path, array $query = [], ?array $jsonBody = null): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $token = itau_boleto_get_token($cfg);
        $url = rtrim(itau_v3_base_url(), '/') . '/' . ltrim($path, '/');
        $query = array_filter($query, static fn($v): bool => $v !== null && $v !== '');
        if ($query) $url .= '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
        $body = $jsonBody === null ? null : json_encode($jsonBody, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($jsonBody !== null && $body === false) throw new RuntimeException('Falha ao gerar JSON para a API Itaú Boletos v3.');
        $resp = itau_boleto_http($cfg, strtoupper($method), $url, itau_v3_headers($cfg, (string)$token['access_token']), $body);
        if ($resp['status'] < 200 || $resp['status'] >= 300) {
            throw new RuntimeException('Itaú Boletos v3 retornou HTTP ' . $resp['status'] . ': ' . itau_boleto_response_message($resp));
        }
        return $resp;
    }
}

if (!function_exists('itau_v3_register_webhook')) {
    function itau_v3_register_webhook(array $cfg, array $data): array {
        $types = array_values(array_unique(array_filter((array)($data['tipos_notificacoes'] ?? []), static fn($v): bool => in_array($v, ['BAIXA_EFETIVA','BAIXA_OPERACIONAL'], true))));
        if (!$types) throw new RuntimeException('Selecione ao menos um tipo de notificação do Webhook Itaú.');
        $payload = [
            'id_beneficiario' => itau_v3_beneficiary_id((string)($data['id_beneficiario'] ?? '')),
            'webhook_url' => trim((string)($data['webhook_url'] ?? '')),
            'webhook_client_id' => trim((string)($data['webhook_client_id'] ?? '')),
            'webhook_client_secret' => trim((string)($data['webhook_client_secret'] ?? '')),
            'webhook_oauth_url' => trim((string)($data['webhook_oauth_url'] ?? '')),
            'tipos_notificacoes' => $types,
        ];
        $scope = trim((string)($data['webhook_oauth_scope'] ?? ''));
        if ($scope !== '') $payload['webhook_oauth_scope'] = $scope;
        if (isset($data['valor_minimo']) && $data['valor_minimo'] !== '') $payload['valor_minimo'] = max(0.01, (float)$data['valor_minimo']);

        foreach (['webhook_url','webhook_client_id','webhook_client_secret','webhook_oauth_url'] as $key) {
            if ($payload[$key] === '') throw new RuntimeException('Campo obrigatório do Webhook Itaú não informado: ' . $key . '.');
        }
        foreach (['webhook_url','webhook_oauth_url'] as $key) {
            $url = (string)$payload[$key];
            if (!filter_var($url, FILTER_VALIDATE_URL) || stripos($url, 'https://') !== 0) {
                throw new RuntimeException($key . ' deve ser uma URL HTTPS válida.');
            }
            $host = (string)(parse_url($url, PHP_URL_HOST) ?? '');
            if ($host === '' || filter_var($host, FILTER_VALIDATE_IP)) throw new RuntimeException($key . ' deve usar nome de domínio; IP não é aceito pelo Itaú.');
        }

        // OpenAPI 1.34.1: o exemplo oficial de POST usa "data" como OBJETO, não array.
        return itau_v3_request($cfg, 'POST', '/notificacoes_boletos', [], ['data' => $payload]);
    }
}

if (!function_exists('itau_v3_get_webhooks')) {
    function itau_v3_get_webhooks(array $cfg, string $beneficiaryId): array {
        try {
            return itau_v3_request($cfg, 'GET', '/notificacoes_boletos', [
                'id_beneficiario' => itau_v3_beneficiary_id($beneficiaryId),
            ]);
        } catch (RuntimeException $e) {
            // O Itaú responde 404 quando não existe callback cadastrado para o beneficiário.
            // Na tela de configuração isso deve ser tratado como "não cadastrado", e não
            // como falha técnica da integração.
            if (str_contains($e->getMessage(), 'HTTP 404')) {
                return ['status'=>404,'headers'=>[],'body'=>'{"data":[]}','json'=>['data'=>[]]];
            }
            throw $e;
        }
    }
}

if (!function_exists('itau_v3_patch_webhook')) {
    function itau_v3_patch_webhook(array $cfg, string $notificationId, array $data): array {
        $notificationId = preg_replace('/\D+/', '', $notificationId) ?? '';
        if ($notificationId === '') throw new RuntimeException('Informe o ID da notificação Itaú.');
        $payload = array_filter([
            'webhook_client_id' => trim((string)($data['webhook_client_id'] ?? '')),
            'webhook_client_secret' => trim((string)($data['webhook_client_secret'] ?? '')),
            'webhook_url' => trim((string)($data['webhook_url'] ?? '')),
            'webhook_oauth_url' => trim((string)($data['webhook_oauth_url'] ?? '')),
            'webhook_oauth_scope' => trim((string)($data['webhook_oauth_scope'] ?? '')),
            'valor_minimo' => isset($data['valor_minimo']) && $data['valor_minimo'] !== '' ? max(0.01, (float)$data['valor_minimo']) : null,
        ], static fn($v): bool => $v !== null && $v !== '');
        if (!$payload) throw new RuntimeException('Nenhum campo informado para atualizar a notificação Itaú.');
        return itau_v3_request($cfg, 'PATCH', '/notificacoes_boletos/' . rawurlencode($notificationId), [], ['data' => $payload]);
    }
}

if (!function_exists('itau_v3_delete_webhook')) {
    function itau_v3_delete_webhook(array $cfg, string $notificationId): array {
        $notificationId = preg_replace('/\D+/', '', $notificationId) ?? '';
        if ($notificationId === '') throw new RuntimeException('Informe o ID da notificação Itaú.');
        return itau_v3_request($cfg, 'DELETE', '/notificacoes_boletos/' . rawurlencode($notificationId));
    }
}

if (!function_exists('itau_v3_get_francesas')) {
    function itau_v3_get_francesas(array $cfg, string $beneficiaryId, string $referenceMonth = ''): array {
        $parts = itau_v3_beneficiary_parts($beneficiaryId);
        $referenceMonth = preg_replace('/\D+/', '', $referenceMonth) ?? '';
        if ($referenceMonth !== '' && !preg_match('/^(0[1-9]|1[0-2])\d{4}$/', $referenceMonth)) {
            throw new RuntimeException('Mês de referência inválido. Use MMYYYY, por exemplo 092026.');
        }
        return itau_v3_request($cfg, 'GET', '/francesas', [
            'agencia' => $parts['agencia'],
            'conta' => $parts['conta'],
            'dac' => $parts['dac'],
            'mes_referencia' => $referenceMonth ?: null,
        ]);
    }
}

if (!function_exists('itau_v3_get_movements')) {
    function itau_v3_get_movements(array $cfg, string $beneficiaryId, string $date, array $filters = []): array {
        $id = itau_v3_beneficiary_id($beneficiaryId);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$dt || $dt->format('Y-m-d') !== trim($date)) throw new RuntimeException('Data da movimentação inválida.');
        $tipoCobranca = trim((string)($filters['tipo_cobranca'] ?? ''));
        if ($tipoCobranca !== '' && !in_array($tipoCobranca, ['cobranca_digital','boleto','bolecode'], true)) throw new RuntimeException('Tipo de cobrança inválido.');
        $tipoMov = trim((string)($filters['tipo_movimentacao'] ?? ''));
        if ($tipoMov !== '' && !in_array($tipoMov, ['entradas','liquidacoes','baixas'], true)) throw new RuntimeException('Tipo de movimentação inválido.');
        $page = isset($filters['page']) ? (int)$filters['page'] : 0;
        $pageSize = isset($filters['page_size']) ? (int)$filters['page_size'] : 50;
        if ($page < 0) throw new RuntimeException('Página da movimentação inválida.');
        if ($pageSize < 1 || $pageSize > 50) throw new RuntimeException('Quantidade por página inválida. Informe de 1 a 50.');
        return itau_v3_request($cfg, 'GET', '/francesas/' . rawurlencode($id) . '/movimentacoes', [
            'data' => $date,
            'tipo_cobranca' => $tipoCobranca ?: null,
            'tipo_movimentacao' => $tipoMov ?: null,
            'nosso_numero' => trim((string)($filters['nosso_numero'] ?? '')) ?: null,
            'seu_numero' => trim((string)($filters['seu_numero'] ?? '')) ?: null,
            'numero_carteira' => trim((string)($filters['numero_carteira'] ?? '')) ?: null,
            'nome_pagador' => trim((string)($filters['nome_pagador'] ?? '')) ?: null,
            'page' => $page,
            'page_size' => $pageSize,
        ]);
    }
}

if (!function_exists('itau_v3_get_movements_summary')) {
    function itau_v3_get_movements_summary(array $cfg, string $beneficiaryId, string $date): array {
        $id = itau_v3_beneficiary_id($beneficiaryId);
        $dt = DateTimeImmutable::createFromFormat('!Y-m-d', trim($date));
        if (!$dt || $dt->format('Y-m-d') !== trim($date)) throw new RuntimeException('Data da movimentação inválida.');
        return itau_v3_request($cfg, 'GET', '/francesas/' . rawurlencode($id) . '/movimentacoes_resumidas', ['data' => $date]);
    }
}
