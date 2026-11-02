<?php
/**
 * Integração server-to-server entre Delivery Nexa Próspero e Plataforma EAD.
 *
 * Fluxo:
 * - O Delivery cria a empresa e o usuário administrador.
 * - Este cliente chama a API /api/delivery_nexa.php no EAD.
 * - O EAD cria/atualiza o aluno com o mesmo usuário/senha e libera o curso marcado.
 */

declare(strict_types=1);

if (!function_exists('ead_integration_config')) {
  function ead_integration_config(): array {
    $local = __DIR__ . '/ead_integration.local.php';
    if (is_file($local)) {
      $cfg = require $local;
      if (is_array($cfg)) return $cfg;
    }

    return [
      'enabled' => filter_var(getenv('EAD_INTEGRATION_ENABLED') ?: '1', FILTER_VALIDATE_BOOLEAN),
      'base_url' => rtrim((string)(getenv('EAD_BASE_URL') ?: 'https://ead.prospero.tec.br'), '/'),
      'api_path' => (string)(getenv('EAD_INTEGRATION_API_PATH') ?: '/api/delivery_nexa.php'),
      // Precisa ser exatamente igual ao INTEGRACAO_DELIVERY_TOKEN do EAD.
      'token' => (string)(getenv('EAD_INTEGRATION_TOKEN') ?: 'mMS3igEPDMqiGUvN4_iXyeczKCRBFuxJXXrbx3Kp7YDK99Fq6ghyjppoWACzYsD5'),
      'timeout' => (int)(getenv('EAD_INTEGRATION_TIMEOUT') ?: 20),
    ];
  }
}

if (!function_exists('ead_integration_login_url')) {
  function ead_integration_login_url(): string {
    $cfg = ead_integration_config();
    return rtrim((string)$cfg['base_url'], '/') . '/login.php';
  }
}

if (!function_exists('ead_integration_is_enabled')) {
  function ead_integration_is_enabled(): bool {
    $cfg = ead_integration_config();
    return !empty($cfg['enabled']) && trim((string)($cfg['base_url'] ?? '')) !== '' && trim((string)($cfg['token'] ?? '')) !== '';
  }
}

if (!function_exists('ead_integration_sync_company')) {
  function ead_integration_sync_company(array $companyData): array {
    $cfg = ead_integration_config();
    $loginUrl = ead_integration_login_url();

    if (!ead_integration_is_enabled()) {
      return [
        'ok' => false,
        'skipped' => true,
        'login_url' => $loginUrl,
        'error' => 'Integração EAD desativada ou sem token configurado.',
      ];
    }

    if (!function_exists('curl_init')) {
      return [
        'ok' => false,
        'login_url' => $loginUrl,
        'error' => 'Extensão cURL não está habilitada no servidor do Delivery.',
      ];
    }

    $endpoint = rtrim((string)$cfg['base_url'], '/') . '/' . ltrim((string)($cfg['api_path'] ?? '/api/delivery_nexa.php'), '/');
    $payload = [
      'delivery_company_id' => (int)($companyData['company_id'] ?? 0),
      'delivery_company_slug' => (string)($companyData['slug'] ?? ''),
      'nome' => (string)($companyData['nome'] ?? $companyData['empresa'] ?? ''),
      'empresa' => (string)($companyData['empresa'] ?? $companyData['nome'] ?? ''),
      'email' => (string)($companyData['email'] ?? ''),
      'usuario' => (string)($companyData['email'] ?? ''),
      'senha' => (string)($companyData['senha'] ?? ''),
      'whatsapp' => (string)($companyData['whatsapp'] ?? ''),
      'celular' => (string)($companyData['celular'] ?? $companyData['whatsapp'] ?? ''),
    ];

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_POST => true,
      CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-EAD-Integration-Token: ' . (string)$cfg['token'],
      ],
      CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      CURLOPT_TIMEOUT => max(5, (int)($cfg['timeout'] ?? 20)),
    ]);

    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    if ($raw === false) {
      return ['ok' => false, 'login_url' => $loginUrl, 'endpoint' => $endpoint, 'error' => $err ?: 'Falha ao chamar a API do EAD.'];
    }

    $json = json_decode((string)$raw, true);
    if (!is_array($json)) {
      return ['ok' => false, 'login_url' => $loginUrl, 'endpoint' => $endpoint, 'http' => $http, 'error' => 'Resposta inválida da API do EAD.', 'raw' => (string)$raw];
    }

    $json['http'] = $http;
    $json['endpoint'] = $endpoint;
    if (empty($json['login_url'])) $json['login_url'] = $loginUrl;
    if ($http < 200 || $http >= 300) {
      $json['ok'] = false;
      if (empty($json['error']) && !empty($json['erro'])) $json['error'] = (string)$json['erro'];
      if (empty($json['error'])) $json['error'] = 'HTTP ' . $http . ' na API do EAD.';
    }

    return $json;
  }
}
