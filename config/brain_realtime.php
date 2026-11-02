<?php
declare(strict_types=1);

require_once __DIR__ . '/nexa_env.php';

/**
 * Infraestrutura Redis/cache e autenticação do WebSocket sem dependência externa.
 * Compatível com Redis RESP2 por conexão TCP.
 */

if (!class_exists('NexaRedisClient')) {
  final class NexaRedisClient {
    /** @var resource|null */
    private $socket = null;
    private string $host;
    private int $port;
    private float $timeout;
    private string $password;
    private int $database;

    public function __construct(?array $config = null) {
      $config = $config ?: nexa_redis_config();
      $this->host = (string)$config['host'];
      $this->port = (int)$config['port'];
      $this->timeout = (float)$config['timeout'];
      $this->password = (string)$config['password'];
      $this->database = (int)$config['database'];
    }

    public function connect(): void {
      if (is_resource($this->socket)) return;
      $errno = 0; $errstr = '';
      $socket = @stream_socket_client('tcp://' . $this->host . ':' . $this->port, $errno, $errstr, $this->timeout, STREAM_CLIENT_CONNECT);
      if (!is_resource($socket)) throw new RuntimeException('Redis indisponível: ' . ($errstr !== '' ? $errstr : ('erro ' . $errno)));
      stream_set_timeout($socket, max(1, (int)ceil($this->timeout)), (int)(($this->timeout - floor($this->timeout)) * 1000000));
      $this->socket = $socket;
      if ($this->password !== '') $this->command(['AUTH', $this->password]);
      if ($this->database > 0) $this->command(['SELECT', (string)$this->database]);
    }

    public function close(): void {
      if (is_resource($this->socket)) @fclose($this->socket);
      $this->socket = null;
    }

    public function command(array $parts) {
      $this->connect();
      $payload = '*' . count($parts) . "\r\n";
      foreach ($parts as $part) {
        $value = (string)$part;
        $payload .= '$' . strlen($value) . "\r\n" . $value . "\r\n";
      }
      $written = 0;
      $length = strlen($payload);
      while ($written < $length) {
        $n = @fwrite($this->socket, substr($payload, $written));
        if ($n === false || $n === 0) { $this->close(); throw new RuntimeException('Falha ao enviar comando ao Redis.'); }
        $written += $n;
      }
      return $this->readReply();
    }

    private function readLine(): string {
      if (!is_resource($this->socket)) throw new RuntimeException('Conexão Redis fechada.');
      $line = @fgets($this->socket);
      if ($line === false) { $this->close(); throw new RuntimeException('Falha ao ler resposta do Redis.'); }
      return rtrim($line, "\r\n");
    }

    private function readBytes(int $length): string {
      $data = '';
      while (strlen($data) < $length) {
        $chunk = @fread($this->socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') { $this->close(); throw new RuntimeException('Resposta Redis incompleta.'); }
        $data .= $chunk;
      }
      $this->readBytesRaw(2); // CRLF
      return $data;
    }

    private function readBytesRaw(int $length): string {
      $data = '';
      while (strlen($data) < $length) {
        $chunk = @fread($this->socket, $length - strlen($data));
        if ($chunk === false || $chunk === '') { $this->close(); throw new RuntimeException('Resposta Redis incompleta.'); }
        $data .= $chunk;
      }
      return $data;
    }

    private function readReply() {
      if (!is_resource($this->socket)) throw new RuntimeException('Conexão Redis fechada.');
      $prefix = @fread($this->socket, 1);
      if ($prefix === false || $prefix === '') { $this->close(); throw new RuntimeException('Redis encerrou a conexão.'); }
      if ($prefix === '+') return $this->readLine();
      if ($prefix === '-') throw new RuntimeException('Redis: ' . $this->readLine());
      if ($prefix === ':') return (int)$this->readLine();
      if ($prefix === '$') {
        $length = (int)$this->readLine();
        return $length < 0 ? null : $this->readBytes($length);
      }
      if ($prefix === '*') {
        $count = (int)$this->readLine();
        if ($count < 0) return null;
        $items = [];
        for ($i = 0; $i < $count; $i++) $items[] = $this->readReply();
        return $items;
      }
      throw new RuntimeException('Resposta Redis inválida.');
    }

    public function __destruct() { $this->close(); }
  }
}

if (!function_exists('nexa_redis_config')) {
  function nexa_redis_config(): array {
    return [
      'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
      'port' => (int)(getenv('REDIS_PORT') ?: 6379),
      'password' => (string)(getenv('REDIS_PASSWORD') ?: ''),
      'database' => (int)(getenv('REDIS_DATABASE') ?: 0),
      'timeout' => max(0.2, (float)(getenv('REDIS_TIMEOUT') ?: 1.2)),
      'stream' => (string)(getenv('NEXA_REALTIME_REDIS_STREAM') ?: (getenv('NEXA_BRAIN_REDIS_STREAM') ?: 'nexa:brain:events')),
      'stream_maxlen' => max(1000, (int)(getenv('NEXA_BRAIN_STREAM_MAXLEN') ?: 20000)),
      'cache_ttl' => max(30, (int)(getenv('NEXA_BRAIN_CACHE_TTL') ?: 300)),
    ];
  }
}

if (!function_exists('nexa_redis')) {
  function nexa_redis(bool $fresh = false): ?NexaRedisClient {
    static $client = null;
    static $failedAt = 0;
    if ($fresh) { if ($client instanceof NexaRedisClient) $client->close(); $client = null; $failedAt = 0; }
    if ($client instanceof NexaRedisClient) return $client;
    if ($failedAt > 0 && (time() - $failedAt) < 10) return null;
    try {
      $client = new NexaRedisClient();
      $client->command(['PING']);
      return $client;
    } catch (Throwable $e) {
      $failedAt = time();
      error_log('[Nexa Redis] ' . $e->getMessage());
      return null;
    }
  }
}

if (!function_exists('nexa_cache_get_json')) {
  function nexa_cache_get_json(string $key): ?array {
    $redis = nexa_redis();
    if (!$redis) return null;
    try {
      $raw = $redis->command(['GET', $key]);
      if (!is_string($raw) || $raw === '') return null;
      $data = json_decode($raw, true);
      return is_array($data) ? $data : null;
    } catch (Throwable $e) { error_log('[Nexa Cache GET] ' . $e->getMessage()); return null; }
  }
}

if (!function_exists('nexa_cache_set_json')) {
  function nexa_cache_set_json(string $key, array $value, int $ttl = 0): bool {
    $redis = nexa_redis();
    if (!$redis) return false;
    $cfg = nexa_redis_config();
    $ttl = $ttl > 0 ? $ttl : (int)$cfg['cache_ttl'];
    $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    if ($json === false) return false;
    try { $redis->command(['SETEX', $key, (string)$ttl, $json]); return true; }
    catch (Throwable $e) { error_log('[Nexa Cache SET] ' . $e->getMessage()); return false; }
  }
}

if (!function_exists('nexa_cache_delete')) {
  function nexa_cache_delete(string $key): void {
    $redis = nexa_redis();
    if (!$redis) return;
    try { $redis->command(['DEL', $key]); } catch (Throwable $e) {}
  }
}

if (!function_exists('nexa_redis_stream_add')) {
  function nexa_redis_stream_add(array $fields): string {
    $redis = nexa_redis();
    if (!$redis) throw new RuntimeException('Redis indisponível para publicação.');
    $cfg = nexa_redis_config();
    $args = ['XADD', $cfg['stream'], 'MAXLEN', '~', (string)$cfg['stream_maxlen'], '*'];
    foreach ($fields as $key => $value) { $args[] = (string)$key; $args[] = is_scalar($value) || $value === null ? (string)$value : (json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}'); }
    $id = $redis->command($args);
    if (!is_string($id) || $id === '') throw new RuntimeException('Redis não confirmou a publicação no Stream.');
    return $id;
  }
}

if (!function_exists('nexa_base64url_encode')) {
  function nexa_base64url_encode(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
}

if (!function_exists('brain_realtime_token')) {
  function brain_realtime_token(int $companyId, int $userId, int $ttl = 3600): string {
    $secret = (string)(getenv('NEXA_REALTIME_WS_SECRET') ?: (getenv('NEXA_BRAIN_WS_SECRET') ?: ''));
    if ($secret === '' || $companyId <= 0) return '';
    $payload = [
      'company_id' => $companyId,
      'user_id' => max(0, $userId),
      'exp' => time() + max(60, min(86400, $ttl)),
      'nonce' => bin2hex(random_bytes(8)),
    ];
    $body = nexa_base64url_encode(json_encode($payload, JSON_UNESCAPED_SLASHES) ?: '{}');
    return $body . '.' . nexa_base64url_encode(hash_hmac('sha256', $body, $secret, true));
  }
}

if (!function_exists('brain_realtime_normalize_host')) {
  function brain_realtime_normalize_host(string $host): string {
    $host = strtolower(trim($host));
    if ($host === '') return '';
    if (str_contains($host, ',')) $host = trim(explode(',', $host, 2)[0]);
    $host = preg_replace('/:\d+$/', '', $host) ?: '';
    $host = rtrim($host, '.');
    return preg_match('/^[a-z0-9.-]+$/', $host) ? $host : '';
  }
}

if (!function_exists('brain_realtime_host_belongs_to_root')) {
  function brain_realtime_host_belongs_to_root(string $host, string $rootDomain): bool {
    $host = brain_realtime_normalize_host($host);
    $rootDomain = brain_realtime_normalize_host($rootDomain);
    if ($host === '' || $rootDomain === '') return false;
    return $host === $rootDomain || str_ends_with($host, '.' . $rootDomain);
  }
}

if (!function_exists('brain_realtime_auto_url')) {
  function brain_realtime_auto_url(): string {
    $rootDomain = (string)(getenv('NEXA_REALTIME_WS_ROOT_DOMAIN') ?: (getenv('NEXA_BRAIN_WS_ROOT_DOMAIN') ?: 'nexaprospero.com.br'));
    $host = brain_realtime_normalize_host((string)($_SERVER['HTTP_HOST'] ?? ''));
    if (!brain_realtime_host_belongs_to_root($host, $rootDomain)) return '';
    $path = trim((string)(getenv('NEXA_REALTIME_WS_PATH') ?: (getenv('NEXA_BRAIN_WS_PATH') ?: '/brain-ws')));
    if ($path === '' || $path[0] !== '/') $path = '/' . ltrim($path, '/');
    return 'wss://' . $host . $path;
  }
}

if (!function_exists('brain_realtime_browser_config')) {
  function brain_realtime_browser_config(int $companyId, int $userId): array {
    $configuredUrl = trim((string)(getenv('NEXA_REALTIME_WS_URL') ?: (getenv('NEXA_BRAIN_WS_URL') ?: '')));
    $url = strtolower($configuredUrl) === 'auto' ? brain_realtime_auto_url() : $configuredUrl;
    $token = $url !== '' ? brain_realtime_token($companyId, $userId, 28800) : '';
    return ['enabled' => $url !== '' && $token !== '', 'url' => $url, 'token' => $token, 'companyId' => $companyId];
  }
}


/**
 * API genérica de eventos em tempo real do Nexa.
 * Mantém compatibilidade com os nomes históricos do Nexa Brain, mas pode ser
 * utilizada por Delivery, Fiscal, Financeiro, O.S. e demais módulos.
 */
if (!function_exists('nexa_realtime_publish_event')) {
  function nexa_realtime_publish_event(
    int $companyId,
    string $eventType,
    string $entityType = '',
    int $entityId = 0,
    array $payload = [],
    bool $dashboardChanged = false,
    ?string $occurredAt = null
  ): bool {
    $eventType = trim($eventType);
    if ($companyId <= 0 || $eventType === '') return false;

    try {
      nexa_redis_stream_add([
        'company_id' => $companyId,
        'event_type' => $eventType,
        'entity_type' => trim($entityType),
        'entity_id' => max(0, $entityId),
        'dashboard_changed' => $dashboardChanged ? '1' : '0',
        'occurred_at' => $occurredAt ?: date(DATE_ATOM),
        'payload' => $payload,
      ]);
      return true;
    } catch (Throwable $e) {
      // O evento em tempo real é best-effort e nunca deve desfazer a transação
      // de negócio já confirmada no banco.
      error_log('[Nexa Realtime] ' . $eventType . ': ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('nexa_realtime_token')) {
  function nexa_realtime_token(int $companyId, int $userId, int $ttl = 3600): string {
    return brain_realtime_token($companyId, $userId, $ttl);
  }
}

if (!function_exists('nexa_realtime_auto_url')) {
  function nexa_realtime_auto_url(): string {
    return brain_realtime_auto_url();
  }
}

if (!function_exists('nexa_realtime_browser_config')) {
  function nexa_realtime_browser_config(int $companyId, int $userId): array {
    return brain_realtime_browser_config($companyId, $userId);
  }
}
