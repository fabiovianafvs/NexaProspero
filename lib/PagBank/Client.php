<?php
declare(strict_types=1);

namespace Nexa\PagBank;

use RuntimeException;

final class Client
{
    private $config;
    private $logger;
    private $lastDiagnostic = [];

    public function __construct(array $config, Logger $logger)
    {
        $this->config = $config;
        $this->logger = $logger;
    }

    public function request(string $operation, string $method, string $path, ?array $payload = null, array $headers = []): array
    {
        $base = rtrim((string)($this->config['api_base_url'] ?? ''), '/');
        $url = preg_match('~^https?://~i', $path) ? $path : $base . '/' . ltrim($path, '/');
        $requestHeaders = array_merge([
            'Authorization: Bearer ' . (string)($this->config['token'] ?? ''),
            'Accept: application/json',
            'Content-Type: application/json',
            'x-api-version: 4.0',
        ], $headers);
        $started = microtime(true);
        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_ENCODING => '',
        ];
        if ($payload !== null) {
            $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        curl_setopt_array($ch, $options);
        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $curlError = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $contentType = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        curl_close($ch);
        $duration = (int)round((microtime(true) - $started) * 1000);
        $rawText = $raw === false ? '' : (string)$raw;
        $isBinary = stripos($contentType, 'image/') === 0 || stripos($contentType, 'application/octet-stream') === 0;
        $decoded = $isBinary ? null : json_decode($rawText, true);
        $response = $isBinary
            ? ['binary_response' => true, 'content_type' => $contentType, 'size_bytes' => strlen($rawText)]
            : (is_array($decoded) ? $decoded : $rawText);
        $externalId = is_array($decoded) ? (string)($decoded['id'] ?? '') : '';
        $reference = (string)($payload['reference_id'] ?? '');
        $success = $errno === 0 && $status >= 200 && $status < 300;
        $error = $errno !== 0 ? $curlError : (!$success ? self::errorMessage(is_array($decoded) ? $decoded : [], $status, $rawText) : '');
        $logId = $this->logger->write([
            'operation'=>$operation, 'method'=>$method, 'endpoint'=>$url,
            'reference_id'=>$reference, 'external_id'=>$externalId,
            'http_status'=>$status, 'success'=>$success,
            'request'=>$payload, 'response'=>$response, 'error'=>$error,
            'duration_ms'=>$duration,
        ]);
        $this->lastDiagnostic = [
            'log_id'=>$logId, 'operation'=>$operation, 'method'=>strtoupper($method),
            'url'=>$url, 'request_payload'=>$payload, 'request_json'=>$payload === null ? '' : json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            'http_status'=>$status, 'response_raw'=>$isBinary ? '[conteúdo binário PNG recebido: ' . strlen($rawText) . ' bytes]' : $rawText,
            'response_json'=>is_array($decoded) ? $decoded : ($isBinary ? ['binary_response'=>true,'content_type'=>$contentType,'size_bytes'=>strlen($rawText)] : []),
            'curl_error'=>$curlError, 'content_type'=>$contentType, 'duration_ms'=>$duration,
        ];
        $GLOBALS['pagbank_last_diagnostic'] = $this->lastDiagnostic;
        if ($errno !== 0) throw new RuntimeException('Falha na comunicação com o PagBank: ' . ($curlError ?: 'erro cURL ' . $errno));
        if (!$success) throw new RuntimeException('O PagBank recusou a operação. ' . $error);
        return ['status'=>$status, 'json'=>is_array($decoded) ? $decoded : [], 'raw'=>$rawText, 'content_type'=>$contentType, 'url'=>$url, 'log_id'=>$logId];
    }

    public function getLastDiagnostic(): array { return $this->lastDiagnostic; }

    public static function errorMessage(array $json, int $status = 0, string $raw = ''): string
    {
        $messages = [];
        $errors = $json['error_messages'] ?? $json['errors'] ?? [];
        if (is_array($errors)) {
            foreach ($errors as $error) {
                if (is_array($error)) {
                    $field = trim((string)($error['parameter_name'] ?? $error['field'] ?? ''));
                    $description = trim((string)($error['description'] ?? $error['message'] ?? $error['code'] ?? ''));
                    if ($description !== '') $messages[] = ($field !== '' ? $field . ': ' : '') . $description;
                } elseif (is_scalar($error)) $messages[] = (string)$error;
            }
        }
        if (!$messages && isset($json['message'])) $messages[] = (string)$json['message'];
        if (!$messages && $raw !== '') $messages[] = substr($raw, 0, 800);
        return $messages ? implode(' | ', $messages) : 'HTTP ' . $status;
    }
}
