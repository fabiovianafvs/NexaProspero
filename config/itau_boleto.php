<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Integração Itaú / Boletos (base pré-API de cobrança).
 *
 * Esta camada cuida somente da infraestrutura comum da integração:
 * - configuração por conta bancária;
 * - validação local de credenciais/certificado/chave;
 * - OAuth 2.0 Client Credentials com mTLS;
 * - cliente HTTP mTLS reutilizável para os endpoints que o Itaú liberar.
 *
 * O endpoint e o payload de registro/consulta de boletos NÃO são definidos aqui
 * até a documentação específica da API de Cobrança ser entregue/homologada.
 */

if (!function_exists('itau_boleto_default_oauth_url')) {
    function itau_boleto_default_oauth_url(): string {
        // Endpoint documentado atualmente pelo Itaú para Client Credentials + mTLS.
        // Mantemos o campo editável por conta bancária porque alguns produtos/jornadas
        // do Itaú podem informar um STS diferente durante o onboarding.
        return 'https://sts.itau.com.br/api/oauth/token';
    }
}

if (!function_exists('itau_boleto_normalize_config')) {
    function itau_boleto_normalize_config(array $cfg): array {
        $oauthUrl = trim((string)($cfg['oauth_url'] ?? ''));
        if ($oauthUrl === '') $oauthUrl = itau_boleto_default_oauth_url();

        return array_merge($cfg, [
            'enabled' => !empty($cfg['enabled']) ? 1 : 0,
            'provider' => strtoupper(trim((string)($cfg['provider'] ?? ''))),
            'integration_mode' => strtoupper(trim((string)($cfg['integration_mode'] ?? 'API'))),
            'environment' => strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO'))),
            'api_client_id' => trim((string)($cfg['api_client_id'] ?? '')),
            'api_client_secret' => trim((string)($cfg['api_client_secret'] ?? '')),
            'cert_path' => trim((string)($cfg['cert_path'] ?? '')),
            'key_path' => trim((string)($cfg['key_path'] ?? '')),
            'ca_path' => trim((string)($cfg['ca_path'] ?? '')),
            'oauth_url' => $oauthUrl,
            'api_base_url' => rtrim(trim((string)($cfg['api_base_url'] ?? '')), '/'),
        ]);
    }
}

if (!function_exists('itau_boleto_mask_secret')) {
    function itau_boleto_mask_secret(string $value): string {
        $value = trim($value);
        if ($value === '') return '';
        $len = strlen($value);
        if ($len <= 8) return str_repeat('*', $len);
        return substr($value, 0, 4) . str_repeat('*', max(4, $len - 8)) . substr($value, -4);
    }
}

if (!function_exists('itau_boleto_uuid')) {
    function itau_boleto_uuid(): string {
        try {
            $data = random_bytes(16);
            $data[6] = chr((ord($data[6]) & 0x0f) | 0x40);
            $data[8] = chr((ord($data[8]) & 0x3f) | 0x80);
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        } catch (Throwable $e) {
            return 'nexa-' . date('YmdHis') . '-' . bin2hex(substr(hash('sha256', uniqid('', true), true), 0, 8));
        }
    }
}

if (!function_exists('itau_boleto_file_check')) {
    function itau_boleto_file_check(string $path, string $label, bool $required = true): array {
        $path = trim($path);
        if ($path === '') {
            return [
                'ok' => !$required,
                'level' => $required ? 'error' : 'info',
                'label' => $label,
                'message' => $required ? 'Não informado.' : 'Opcional e não informado.',
            ];
        }
        if (!is_file($path)) {
            return ['ok'=>false,'level'=>'error','label'=>$label,'message'=>'Arquivo não encontrado em: ' . $path];
        }
        if (!is_readable($path)) {
            return ['ok'=>false,'level'=>'error','label'=>$label,'message'=>'Arquivo existe, mas o PHP não possui permissão de leitura: ' . $path];
        }
        return ['ok'=>true,'level'=>'ok','label'=>$label,'message'=>'Arquivo localizado e legível: ' . $path];
    }
}

if (!function_exists('itau_boleto_certificate_details')) {
    function itau_boleto_certificate_details(string $certPath): array {
        if ($certPath === '' || !is_file($certPath) || !is_readable($certPath)) return [];
        if (!function_exists('openssl_x509_parse')) return [];
        $content = @file_get_contents($certPath);
        if ($content === false || trim($content) === '') return [];
        $parsed = @openssl_x509_parse($content);
        if (!is_array($parsed)) return [];

        $validTo = isset($parsed['validTo_time_t']) ? (int)$parsed['validTo_time_t'] : 0;
        $validFrom = isset($parsed['validFrom_time_t']) ? (int)$parsed['validFrom_time_t'] : 0;
        $days = $validTo > 0 ? (int)floor(($validTo - time()) / 86400) : null;

        return [
            'subject_cn' => trim((string)($parsed['subject']['CN'] ?? '')),
            'issuer_cn' => trim((string)($parsed['issuer']['CN'] ?? '')),
            'serial' => trim((string)($parsed['serialNumberHex'] ?? $parsed['serialNumber'] ?? '')),
            'valid_from' => $validFrom > 0 ? date('Y-m-d H:i:s', $validFrom) : '',
            'valid_to' => $validTo > 0 ? date('Y-m-d H:i:s', $validTo) : '',
            'days_remaining' => $days,
            'expired' => $validTo > 0 && $validTo < time(),
        ];
    }
}

if (!function_exists('itau_boleto_certificate_matches_key')) {
    function itau_boleto_certificate_matches_key(string $certPath, string $keyPath): ?bool {
        if (!function_exists('openssl_x509_check_private_key')) return null;
        if (!is_file($certPath) || !is_readable($certPath) || !is_file($keyPath) || !is_readable($keyPath)) return null;
        $cert = @file_get_contents($certPath);
        $key = @file_get_contents($keyPath);
        if ($cert === false || $key === false || trim($cert) === '' || trim($key) === '') return null;
        return @openssl_x509_check_private_key($cert, $key);
    }
}

if (!function_exists('itau_boleto_validate_config')) {
    function itau_boleto_validate_config(array $rawCfg, bool $requireCredentials = false): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $checks = [];

        $checks[] = [
            'ok' => $cfg['provider'] === 'ITAU',
            'level' => $cfg['provider'] === 'ITAU' ? 'ok' : 'error',
            'label' => 'Provedor',
            'message' => $cfg['provider'] === 'ITAU' ? 'Itaú selecionado.' : 'A conta não está configurada com o provedor ITAU.',
        ];
        $checks[] = [
            'ok' => $cfg['integration_mode'] === 'API',
            'level' => $cfg['integration_mode'] === 'API' ? 'ok' : 'error',
            'label' => 'Integração',
            'message' => $cfg['integration_mode'] === 'API' ? 'Modo API selecionado.' : 'Para a integração de boletos via API selecione o modo API.',
        ];
        $checks[] = [
            'ok' => !empty($cfg['enabled']),
            'level' => !empty($cfg['enabled']) ? 'ok' : 'error',
            'label' => 'Cobrança habilitada',
            'message' => !empty($cfg['enabled']) ? 'Cobrança/boleto habilitado para esta conta.' : 'Habilite cobrança/boleto nesta conta.',
        ];

        $clientOk = $cfg['api_client_id'] !== '';
        $secretOk = $cfg['api_client_secret'] !== '';
        $credLevel = ($clientOk && $secretOk) ? 'ok' : ($requireCredentials ? 'error' : 'warning');
        $checks[] = [
            'ok' => $clientOk && $secretOk,
            'level' => $credLevel,
            'label' => 'Credenciais OAuth',
            'message' => ($clientOk && $secretOk)
                ? 'Client ID e Client Secret estão preenchidos.'
                : 'Aguardando Client ID e/ou Client Secret do Itaú.',
        ];

        $checks[] = itau_boleto_file_check((string)$cfg['cert_path'], 'Certificado mTLS', true);
        $checks[] = itau_boleto_file_check((string)$cfg['key_path'], 'Chave privada', true);
        $checks[] = itau_boleto_file_check((string)$cfg['ca_path'], 'CA / cadeia de certificados', false);

        $certDetails = itau_boleto_certificate_details((string)$cfg['cert_path']);
        if ($certDetails) {
            if (!empty($certDetails['expired'])) {
                $checks[] = ['ok'=>false,'level'=>'error','label'=>'Validade do certificado','message'=>'Certificado expirado em ' . $certDetails['valid_to'] . '.'];
            } else {
                $days = $certDetails['days_remaining'];
                $level = is_int($days) && $days < 30 ? 'warning' : 'ok';
                $checks[] = [
                    'ok'=>true,
                    'level'=>$level,
                    'label'=>'Validade do certificado',
                    'message'=>'Válido até ' . ($certDetails['valid_to'] ?: 'data não identificada') . (is_int($days) ? ' (' . $days . ' dia(s) restante(s)).' : '.'),
                ];
            }
        } elseif ($cfg['cert_path'] !== '' && is_file((string)$cfg['cert_path'])) {
            $checks[] = ['ok'=>false,'level'=>'error','label'=>'Leitura do certificado','message'=>'O arquivo existe, mas não foi reconhecido pelo OpenSSL como certificado X.509 válido.'];
        }

        $match = itau_boleto_certificate_matches_key((string)$cfg['cert_path'], (string)$cfg['key_path']);
        if ($match !== null) {
            $checks[] = [
                'ok'=>$match,
                'level'=>$match ? 'ok' : 'error',
                'label'=>'Certificado x chave privada',
                'message'=>$match ? 'A chave privada corresponde ao certificado.' : 'A chave privada NÃO corresponde ao certificado informado.',
            ];
        }

        $oauthOk = filter_var((string)$cfg['oauth_url'], FILTER_VALIDATE_URL) !== false;
        $checks[] = [
            'ok'=>$oauthOk,
            'level'=>$oauthOk ? 'ok' : 'error',
            'label'=>'URL OAuth',
            'message'=>$oauthOk ? (string)$cfg['oauth_url'] : 'URL OAuth inválida.',
        ];

        $errors = array_values(array_filter($checks, static fn(array $c): bool => ($c['level'] ?? '') === 'error'));
        $warnings = array_values(array_filter($checks, static fn(array $c): bool => ($c['level'] ?? '') === 'warning'));
        return [
            'ok' => count($errors) === 0,
            'ready_for_token' => $clientOk && $secretOk
                && is_file((string)$cfg['cert_path']) && is_readable((string)$cfg['cert_path'])
                && is_file((string)$cfg['key_path']) && is_readable((string)$cfg['key_path'])
                && $cfg['provider'] === 'ITAU' && $cfg['integration_mode'] === 'API' && !empty($cfg['enabled']),
            'checks' => $checks,
            'errors' => $errors,
            'warnings' => $warnings,
            'certificate' => $certDetails,
            'config' => $cfg,
        ];
    }
}

if (!function_exists('itau_boleto_compose_headers')) {
    function itau_boleto_compose_headers(array $headers): array {
        $out = [];
        foreach ($headers as $name => $value) $out[] = $name . ': ' . $value;
        return $out;
    }
}

if (!function_exists('itau_boleto_http')) {
    function itau_boleto_http(array $rawCfg, string $method, string $url, array $headers = [], ?string $body = null): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL do PHP não está disponível na VPS.');
        if (!filter_var($url, FILTER_VALIDATE_URL)) throw new RuntimeException('URL inválida para comunicação com o Itaú.');

        $ch = curl_init($url);
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a conexão cURL.');

        $responseHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => itau_boleto_compose_headers($headers),
            CURLOPT_TIMEOUT => 60,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_HEADERFUNCTION => static function ($curl, $line) use (&$responseHeaders) {
                $len = strlen($line);
                $line = trim($line);
                if ($line === '' || !str_contains($line, ':')) return $len;
                [$name,$value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))] = trim($value);
                return $len;
            },
        ]);

        if ($body !== null) curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        if ($cfg['cert_path'] !== '') curl_setopt($ch, CURLOPT_SSLCERT, (string)$cfg['cert_path']);
        if ($cfg['key_path'] !== '') curl_setopt($ch, CURLOPT_SSLKEY, (string)$cfg['key_path']);
        if ($cfg['ca_path'] !== '') curl_setopt($ch, CURLOPT_CAINFO, (string)$cfg['ca_path']);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Falha mTLS/cURL na comunicação com o Itaú: ' . ($error !== '' ? $error : 'sem resposta') . ' (cURL ' . $errno . ').');
        }
        $json = json_decode((string)$raw, true);
        return [
            'status'=>$status,
            'headers'=>$responseHeaders,
            'body'=>(string)$raw,
            'json'=>is_array($json) ? $json : null,
            'content_type'=>$contentType,
        ];
    }
}

if (!function_exists('itau_boleto_response_message')) {
    function itau_boleto_response_message(array $response): string {
        $json = $response['json'] ?? null;
        if (is_array($json)) {
            $base = '';
            foreach (['message','mensagem','detail','descricao','error_description','error'] as $key) {
                if (isset($json[$key]) && is_scalar($json[$key]) && trim((string)$json[$key]) !== '') { $base = trim((string)$json[$key]); break; }
            }
            $fieldErrors = [];
            foreach (['campos','fields','errors','erros'] as $listKey) {
                $list = $json[$listKey] ?? null;
                if (!is_array($list)) continue;
                foreach ($list as $item) {
                    if (!is_array($item)) continue;
                    $field = trim((string)($item['campo'] ?? $item['field'] ?? $item['nome'] ?? ''));
                    $msg = trim((string)($item['mensagem'] ?? $item['message'] ?? $item['detail'] ?? $item['descricao'] ?? ''));
                    $value = $item['valor'] ?? $item['value'] ?? null;
                    if ($field === '' && $msg === '') continue;
                    $piece = ($field !== '' ? $field . ': ' : '') . ($msg !== '' ? $msg : 'valor inválido');
                    if (is_scalar($value) && trim((string)$value) !== '') $piece .= ' [valor=' . trim((string)$value) . ']';
                    $fieldErrors[] = $piece;
                }
                if ($fieldErrors) break;
            }
            if ($fieldErrors) return trim(($base !== '' ? $base . ' — ' : '') . implode('; ', array_slice($fieldErrors, 0, 8)));
            if ($base !== '') return $base;
        }
        $body = trim((string)($response['body'] ?? ''));
        return $body !== '' ? mb_substr($body, 0, 500, 'UTF-8') : 'Sem mensagem retornada.';
    }
}

if (!function_exists('itau_boleto_get_token')) {
    function itau_boleto_get_token(array $rawCfg): array {
        static $memoryCache = [];
        $validation = itau_boleto_validate_config($rawCfg, true);
        $cfg = $validation['config'];
        if (!$validation['ready_for_token'] || !$validation['ok']) {
            $messages = array_map(static fn(array $x): string => (string)($x['message'] ?? ''), $validation['errors']);
            if (!$validation['ready_for_token']) $messages[] = 'Credenciais/certificado/chave ainda não estão completos para solicitar o token.';
            throw new RuntimeException(implode(' ', array_filter(array_unique($messages))));
        }

        $cacheKey = hash('sha256', $cfg['api_client_id'] . '|' . $cfg['oauth_url'] . '|' . $cfg['cert_path']);
        if (isset($memoryCache[$cacheKey]) && (int)$memoryCache[$cacheKey]['expires_at'] > time() + 30) return $memoryCache[$cacheKey];

        $body = http_build_query([
            'grant_type'=>'client_credentials',
            'client_id'=>$cfg['api_client_id'],
            'client_secret'=>$cfg['api_client_secret'],
        ], '', '&', PHP_QUERY_RFC3986);

        $resp = itau_boleto_http($cfg, 'POST', (string)$cfg['oauth_url'], [
            'Accept'=>'application/json',
            'Content-Type'=>'application/x-www-form-urlencoded',
        ], $body);

        if ($resp['status'] < 200 || $resp['status'] >= 300 || empty($resp['json']['access_token'])) {
            throw new RuntimeException('O Itaú não retornou um access_token. HTTP ' . $resp['status'] . ': ' . itau_boleto_response_message($resp));
        }

        $expiresIn = max(60, (int)($resp['json']['expires_in'] ?? 300));
        $tokenData = [
            'access_token'=>(string)$resp['json']['access_token'],
            'token_type'=>(string)($resp['json']['token_type'] ?? 'Bearer'),
            'expires_in'=>$expiresIn,
            'expires_at'=>time() + $expiresIn,
            'http_status'=>$resp['status'],
        ];
        $memoryCache[$cacheKey] = $tokenData;
        return $tokenData;
    }
}

if (!function_exists('itau_boleto_api_headers')) {
    function itau_boleto_api_headers(array $rawCfg, string $accessToken, ?string $correlationId = null, ?string $flowId = null): array {
        $cfg = itau_boleto_normalize_config($rawCfg);
        $correlationId = trim((string)$correlationId) ?: itau_boleto_uuid();
        $flowId = trim((string)$flowId) ?: itau_boleto_uuid();
        return [
            'Accept'=>'application/json',
            'Content-Type'=>'application/json',
            'Authorization'=>'Bearer ' . $accessToken,
            'x-itau-apikey'=>(string)$cfg['api_client_id'],
            'x-itau-correlationID'=>$correlationId,
            'x-itau-flowID'=>$flowId,
        ];
    }
}

if (!function_exists('itau_boleto_test_token')) {
    function itau_boleto_test_token(array $cfg): array {
        $started = microtime(true);
        $token = itau_boleto_get_token($cfg);
        return [
            'ok'=>true,
            'http_status'=>(int)($token['http_status'] ?? 0),
            'expires_in'=>(int)($token['expires_in'] ?? 0),
            'elapsed_ms'=>(int)round((microtime(true) - $started) * 1000),
        ];
    }
}

if (!function_exists('itau_boleto_certificate_request_url')) {
    function itau_boleto_certificate_request_url(): string {
        return 'https://sts.itau.com.br/seguranca/v1/certificado/solicitacao';
    }
}

if (!function_exists('itau_boleto_webhook_management_url')) {
    function itau_boleto_webhook_management_url(): string {
        return 'https://boletos.cloud.itau.com.br/boletos/v3/notificacoes_boletos';
    }
}

if (!function_exists('itau_boleto_ascii_subject')) {
    function itau_boleto_ascii_subject(string $value): string {
        $value = trim($value);
        $map = [
            'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
            'é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
            'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
            'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
            'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
            'ç'=>'c','Ç'=>'C','ñ'=>'n','Ñ'=>'N'
        ];
        $value = strtr($value, $map);
        $value = preg_replace('/[^A-Za-z0-9 ._\-]/', ' ', $value) ?? $value;
        return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
    }
}

if (!function_exists('itau_boleto_storage_dir')) {
    function itau_boleto_storage_dir(int $companyId, int $bankAccountId): string {
        if ($companyId <= 0 || $bankAccountId <= 0) throw new InvalidArgumentException('Empresa/conta bancária inválida para o certificado Itaú.');
        $base = dirname(__DIR__) . '/storage/certs/itau/company_' . $companyId . '/bank_' . $bankAccountId;
        if (!is_dir($base) && !@mkdir($base, 0700, true) && !is_dir($base)) {
            throw new RuntimeException('Não foi possível criar a pasta segura do certificado Itaú: ' . $base);
        }
        @chmod($base, 0700);
        return $base;
    }
}

if (!function_exists('itau_boleto_secure_write')) {
    function itau_boleto_secure_write(string $path, string $content, int $mode = 0600): void {
        if (@file_put_contents($path, $content, LOCK_EX) === false) throw new RuntimeException('Não foi possível gravar o arquivo: ' . $path);
        @chmod($path, $mode);
    }
}

if (!function_exists('itau_boleto_generate_csr')) {
    function itau_boleto_generate_csr(int $companyId, int $bankAccountId, string $clientId, string $organizationUnit, string $city, string $state): array {
        if (!function_exists('openssl_pkey_new') || !function_exists('openssl_csr_new')) {
            throw new RuntimeException('A extensão OpenSSL do PHP não está disponível para gerar CSR/chave.');
        }
        $clientId = trim($clientId);
        if ($clientId === '') throw new RuntimeException('Informe o Client ID do Itaú antes de gerar o CSR.');
        $ou = itau_boleto_ascii_subject($organizationUnit);
        $city = itau_boleto_ascii_subject($city);
        $state = strtoupper(substr(itau_boleto_ascii_subject($state), 0, 2));
        if ($ou === '' || $city === '' || strlen($state) !== 2) throw new RuntimeException('Informe Nome da empresa/app, Cidade e UF para gerar o CSR.');

        $dn = [
            'countryName' => 'BR',
            'stateOrProvinceName' => $state,
            'localityName' => $city,
            'organizationName' => $ou,
            'organizationalUnitName' => $ou,
            'commonName' => $clientId,
        ];
        $opts = ['digest_alg'=>'sha512','private_key_bits'=>2048,'private_key_type'=>OPENSSL_KEYTYPE_RSA];
        $privateKey = openssl_pkey_new($opts);
        if ($privateKey === false) throw new RuntimeException('OpenSSL não conseguiu gerar a chave privada RSA 2048.');
        $csr = openssl_csr_new($dn, $privateKey, ['digest_alg'=>'sha512']);
        if ($csr === false) throw new RuntimeException('OpenSSL não conseguiu gerar o CSR.');
        if (!openssl_pkey_export($privateKey, $keyPem)) throw new RuntimeException('Não foi possível exportar a chave privada.');
        if (!openssl_csr_export($csr, $csrPem)) throw new RuntimeException('Não foi possível exportar o CSR.');

        $dir = itau_boleto_storage_dir($companyId, $bankAccountId);
        $keyPath = $dir . '/private.key';
        $csrPath = $dir . '/request.csr';
        itau_boleto_secure_write($keyPath, $keyPem, 0600);
        itau_boleto_secure_write($csrPath, $csrPem, 0600);
        return ['key_path'=>$keyPath,'csr_path'=>$csrPath,'csr_pem'=>$csrPem,'subject'=>$dn];
    }
}

if (!function_exists('itau_boleto_request_dynamic_certificate')) {
    function itau_boleto_request_dynamic_certificate(string $temporaryToken, string $csrPem): array {
        $temporaryToken = trim($temporaryToken);
        if ($temporaryToken === '') throw new RuntimeException('Informe o token temporário enviado pelo Itaú.');
        if (!str_contains($csrPem, 'BEGIN CERTIFICATE REQUEST')) throw new RuntimeException('CSR inválido para envio ao Itaú.');
        if (!function_exists('curl_init')) throw new RuntimeException('A extensão cURL do PHP não está disponível.');

        $ch = curl_init(itau_boleto_certificate_request_url());
        if ($ch === false) throw new RuntimeException('Não foi possível iniciar a solicitação do certificado Itaú.');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER=>true,
            CURLOPT_POST=>true,
            CURLOPT_POSTFIELDS=>$csrPem,
            CURLOPT_HTTPHEADER=>[
                'Content-Type: text/plain',
                'Accept: text/plain, application/json',
                'Authorization: Bearer ' . $temporaryToken,
            ],
            CURLOPT_TIMEOUT=>60,
            CURLOPT_CONNECTTIMEOUT=>20,
        ]);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($raw === false) throw new RuntimeException('Falha ao solicitar certificado Itaú: ' . ($error ?: ('cURL ' . $errno)) . '.');
        $body = trim((string)$raw);
        if ($status < 200 || $status >= 300) throw new RuntimeException('Itaú recusou a solicitação do certificado. HTTP ' . $status . ': ' . mb_substr($body,0,700,'UTF-8'));

        if (!preg_match('/-----BEGIN CERTIFICATE-----[\s\S]+?-----END CERTIFICATE-----/', $body, $m)) {
            throw new RuntimeException('O Itaú respondeu com sucesso HTTP, mas o certificado CRT não foi localizado no retorno.');
        }
        $certPem = trim($m[0]) . "\n";
        $before = trim(substr($body, 0, (int)strpos($body, $m[0])));
        $secret = '';
        foreach (preg_split('/\R+/', $before) ?: [] as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if (preg_match('/^(?:secret|client[_ ]?secret)\s*[:=]\s*(.+)$/i', $line, $sm)) { $secret = trim($sm[1]); break; }
            if ($secret === '') $secret = $line;
        }
        $secret = trim($secret, " \t\r\n\"'");
        if ($secret === '') throw new RuntimeException('O certificado foi retornado, mas o Client Secret não foi identificado na resposta.');
        return ['http_status'=>$status,'client_secret'=>$secret,'certificate_pem'=>$certPem];
    }
}

if (!function_exists('itau_boleto_enroll_certificate')) {
    function itau_boleto_enroll_certificate(int $companyId, int $bankAccountId, string $clientId, string $organizationUnit, string $city, string $state, string $temporaryToken): array {
        $generated = itau_boleto_generate_csr($companyId,$bankAccountId,$clientId,$organizationUnit,$city,$state);
        $response = itau_boleto_request_dynamic_certificate($temporaryToken,(string)$generated['csr_pem']);
        $dir = itau_boleto_storage_dir($companyId,$bankAccountId);
        $certPath = $dir . '/certificate.crt';
        itau_boleto_secure_write($certPath,(string)$response['certificate_pem'],0600);
        $match = itau_boleto_certificate_matches_key($certPath,(string)$generated['key_path']);
        if ($match !== true) throw new RuntimeException('O certificado retornado pelo Itaú não corresponde à chave privada gerada.');
        return array_merge($generated,$response,['cert_path'=>$certPath,'certificate'=>itau_boleto_certificate_details($certPath)]);
    }
}

if (!function_exists('itau_boleto_register_webhook')) {
    /** @deprecated Use itau_v3_register_webhook() from itau_boleto_v3.php. */
    function itau_boleto_register_webhook(array $rawCfg, array $webhookData): array {
        require_once __DIR__ . '/itau_boleto_v3.php';
        return itau_v3_register_webhook($rawCfg, $webhookData);
    }
}

if (!function_exists('itau_boleto_get_webhook_registrations')) {
    /** @deprecated Use itau_v3_get_webhooks() from itau_boleto_v3.php. */
    function itau_boleto_get_webhook_registrations(array $rawCfg): array {
        require_once __DIR__ . '/itau_boleto_v3.php';
        return itau_v3_get_webhooks($rawCfg, (string)($rawCfg['itau_beneficiary_id'] ?? ''));
    }
}
