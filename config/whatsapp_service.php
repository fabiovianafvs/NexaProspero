<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config_store.php';


function whatsapp_message_model_contexts(): array {
  return [
    'boleto' => 'Boleto',
    'pedido' => 'Pedido',
    'pedido_compra' => 'Pedido de Compra',
    'nfe' => 'NF-e',
    'nfce' => 'NFC-e',
    'nfse' => 'NFS-e',
    'orcamento' => 'Orçamento',
    'ordem_servico' => 'Ordem de Serviço',
    'aniversario' => 'Aniversário de Pessoa',
    'crm' => 'CRM / Lead',
  ];
}

function whatsapp_message_model_context_label(string $context): string {
  $contexts = whatsapp_message_model_contexts();
  $context = strtolower(trim($context));
  return (string)($contexts[$context] ?? 'Modelo');
}

function whatsapp_message_model_context_options(): array {
  return array_keys(whatsapp_message_model_contexts());
}

function whatsapp_message_model_variables(string $context): array {
  $all = [
    'boleto' => ['{{empresa}}','{{cliente}}','{{titulo_id}}','{{boleto_id}}','{{pedido}}','{{contrato}}','{{vencimento}}','{{valor}}','{{nosso_numero}}','{{linha_digitavel}}','{{pix_copia_cola}}'],
    'pedido_compra' => ['{{empresa}}','{{loja}}','{{fornecedor}}','{{pedido}}','{{pedido_id}}','{{numero}}','{{data}}','{{itens}}','{{total}}'],
    'pedido' => ['{{pedido_id}}','{{loja}}','{{cliente}}','{{itens}}','{{subtotal}}','{{taxa_entrega}}','{{total}}','{{tipo_entrega}}','{{pagamento}}','{{pix_linha}}','{{bairro}}','{{endereco}}','{{referencia}}','{{observacoes}}'],
    'nfe'    => ['{{empresa}}','{{cliente}}','{{documento}}','{{numero}}','{{serie}}','{{total}}','{{chave_acesso}}'],
    'nfce'   => ['{{empresa}}','{{cliente}}','{{documento}}','{{numero}}','{{serie}}','{{total}}','{{chave_acesso}}'],
    'nfse'   => ['{{empresa}}','{{cliente}}','{{documento}}','{{numero}}','{{dps_serie}}','{{dps_numero}}','{{total}}','{{chave_acesso}}','{{codigo_verificacao}}'],
    'orcamento' => ['{{empresa}}','{{cliente}}','{{orcamento}}','{{data}}','{{total}}','{{status}}'],
    'ordem_servico' => ['{{empresa}}','{{cliente}}','{{os}}','{{data}}','{{status}}','{{previsao}}','{{total}}'],
    'aniversario' => ['{{empresa}}','{{nome}}','{{primeiro_nome}}','{{data_nascimento}}','{{idade}}'],
    'crm' => ['{{empresa}}','{{lead}}','{{nome}}','{{primeiro_nome}}','{{telefone}}','{{email}}','{{temperatura}}','{{responsavel}}','{{proximo_contato}}','{{tramitacao}}'],
  ];
  $context = strtolower(trim($context));
  return (array)($all[$context] ?? []);
}

function whatsapp_message_models_default_state(): array {
  return [
    'next_id' => 1,
    'items' => [],
    'defaults' => [
      'boleto' => 0,
      'pedido' => 0,
      'pedido_compra' => 0,
      'nfe' => 0,
      'nfce' => 0,
      'nfse' => 0,
      'orcamento' => 0,
      'ordem_servico' => 0,
      'aniversario' => 0,
      'crm' => 0,
    ],
  ];
}

function whatsapp_message_model_infer_context(array $item, array $defaults = []): string {
  $id = (int)($item['id'] ?? 0);
  foreach (whatsapp_message_model_context_options() as $context) {
    if ((int)($defaults[$context] ?? 0) === $id) return $context;
  }
  $haystack = mb_strtolower(trim((string)($item['title'] ?? '') . ' ' . (string)($item['message'] ?? '')), 'UTF-8');
  if ($haystack !== '') {
    if (str_contains($haystack, 'nfs-e') || str_contains($haystack, 'nfse')) return 'nfse';
    if (str_contains($haystack, 'nfc')) return 'nfce';
    if (str_contains($haystack, 'nf-e') || str_contains($haystack, 'nfe') || str_contains($haystack, 'nota fiscal')) return 'nfe';
    if (str_contains($haystack, 'boleto') || str_contains($haystack, 'linha_digitavel') || str_contains($haystack, 'nosso_numero') || str_contains($haystack, 'vencimento')) return 'boleto';
  }
  return 'pedido';
}

function whatsapp_message_models_load(PDO $pdo, int $companyId): array {
  $state = whatsapp_message_models_default_state();
  try {
    $saved = config_store_get($pdo, $companyId, 'whatsapp_message_models');
    if (is_array($saved)) $state = config_deep_merge($state, $saved);
  } catch (Throwable $e) {}

  $contexts = whatsapp_message_model_context_options();
  $cleanItems = [];
  $maxId = 0;
  foreach ((array)($state['items'] ?? []) as $item) {
    if (!is_array($item)) continue;
    $id = max(0, (int)($item['id'] ?? 0));
    $title = trim((string)($item['title'] ?? ''));
    $message = trim((string)($item['message'] ?? ''));
    if ($id <= 0 || $title === '' || $message === '') continue;
    $context = strtolower(trim((string)($item['context'] ?? '')));
    if (!in_array($context, $contexts, true)) $context = whatsapp_message_model_infer_context($item, (array)($state['defaults'] ?? []));
    $cleanItems[] = [
      'id' => $id,
      'context' => $context,
      'title' => mb_substr($title, 0, 120),
      'message' => mb_substr($message, 0, 12000),
      'active' => !array_key_exists('active', $item) || !empty($item['active']),
    ];
    $maxId = max($maxId, $id);
  }
  usort($cleanItems, static function($a, $b): int {
    $cmp = strcasecmp((string)($a['context'] ?? ''), (string)($b['context'] ?? ''));
    return $cmp !== 0 ? $cmp : strcasecmp((string)$a['title'], (string)$b['title']);
  });

  $validIdsByContext = [];
  foreach ($cleanItems as $cleanItem) {
    if (!empty($cleanItem['active'])) {
      $validIdsByContext[(string)$cleanItem['context']][(int)$cleanItem['id']] = true;
    }
  }
  $defaults = [];
  foreach ($contexts as $context) {
    $id = max(0, (int)($state['defaults'][$context] ?? 0));
    $defaults[$context] = ($id > 0 && !empty($validIdsByContext[$context][$id])) ? $id : 0;
  }

  return [
    'next_id' => max($maxId + 1, (int)($state['next_id'] ?? 1), 1),
    'items' => $cleanItems,
    'defaults' => $defaults,
  ];
}

function whatsapp_message_models_save(PDO $pdo, int $companyId, array $state): void {
  $current = whatsapp_message_models_load($pdo, $companyId);
  $contexts = whatsapp_message_model_context_options();
  $items = [];
  $maxId = 0;
  foreach ((array)($state['items'] ?? $current['items']) as $item) {
    if (!is_array($item)) continue;
    $id = max(0, (int)($item['id'] ?? 0));
    $title = trim((string)($item['title'] ?? ''));
    $message = trim((string)($item['message'] ?? ''));
    $context = strtolower(trim((string)($item['context'] ?? '')));
    if ($id <= 0 || $title === '' || $message === '' || !in_array($context, $contexts, true)) continue;
    $items[] = [
      'id' => $id,
      'context' => $context,
      'title' => mb_substr($title, 0, 120),
      'message' => mb_substr($message, 0, 12000),
      'active' => !empty($item['active']),
    ];
    $maxId = max($maxId, $id);
  }
  usort($items, static function($a, $b): int {
    $cmp = strcasecmp((string)($a['context'] ?? ''), (string)($b['context'] ?? ''));
    return $cmp !== 0 ? $cmp : strcasecmp((string)$a['title'], (string)$b['title']);
  });

  $validIdsByContext = [];
  foreach ($items as $savedItem) if (!empty($savedItem['active'])) $validIdsByContext[(string)$savedItem['context']][(int)$savedItem['id']] = true;

  $defaultsSource = is_array($state['defaults'] ?? null) ? $state['defaults'] : $current['defaults'];
  $defaults = [];
  foreach ($contexts as $context) {
    $id = max(0, (int)($defaultsSource[$context] ?? 0));
    $defaults[$context] = ($id > 0 && !empty($validIdsByContext[$context][$id])) ? $id : 0;
  }

  $payload = [
    'next_id' => max($maxId + 1, (int)($state['next_id'] ?? $current['next_id'] ?? 1), 1),
    'items' => $items,
    'defaults' => $defaults,
  ];
  config_store_set($pdo, $companyId, 'whatsapp_message_models', $payload);
}

function whatsapp_message_model_find(array $state, int $id): ?array {
  if ($id <= 0) return null;
  foreach ((array)($state['items'] ?? []) as $item) {
    if ((int)($item['id'] ?? 0) === $id && !empty($item['active'])) return $item;
  }
  return null;
}

function whatsapp_message_models_for_context(PDO $pdo, int $companyId, string $context): array {
  $context = strtolower(trim($context));
  if (!in_array($context, whatsapp_message_model_context_options(), true)) return [];
  $state = whatsapp_message_models_load($pdo, $companyId);
  $items = [];
  foreach ((array)($state['items'] ?? []) as $item) {
    if (empty($item['active']) || (string)($item['context'] ?? '') !== $context) continue;
    $items[] = $item;
  }
  return $items;
}

/**
 * Retorna o modelo do contexto. Quando $modelId é null, usa o modelo padrão
 * configurado para o contexto. Quando é 0, força a mensagem segura do sistema.
 */
function whatsapp_message_model_selected(PDO $pdo, int $companyId, string $context, ?int $modelId = null): ?array {
  $context = strtolower(trim($context));
  if (!in_array($context, whatsapp_message_model_context_options(), true)) return null;
  $state = whatsapp_message_models_load($pdo, $companyId);
  $id = $modelId === null ? (int)($state['defaults'][$context] ?? 0) : max(0, $modelId);
  if ($id <= 0) return null;
  $item = whatsapp_message_model_find($state, $id);
  if (!$item || (string)($item['context'] ?? '') !== $context) return null;
  return $item;
}

function whatsapp_message_model_text(PDO $pdo, int $companyId, string $context, ?int $modelId = null): string {
  $item = whatsapp_message_model_selected($pdo, $companyId, $context, $modelId);
  return $item ? trim((string)($item['message'] ?? '')) : '';
}

function whatsapp_message_model_render(PDO $pdo, int $companyId, string $context, array $vars, string $fallback = '', ?int $modelId = null): string {
  $template = whatsapp_message_model_text($pdo, $companyId, $context, $modelId);
  if ($template === '') $template = $fallback;
  return whatsapp_render_template($template, $vars);
}

function whatsapp_default_templates(): array {
  return [
    'order_customer_full' => implode("\n", [
      '🧾 Pedido #{{pedido_id}}',
      '👤 Cliente: {{cliente}}',
      '{{whatsapp_linha}}',
      '',
      '🍽️ Itens:',
      '{{itens}}',
      '',
      'Subtotal: {{subtotal}}',
      '{{taxa_entrega_linha}}',
      '💰 Total: {{total}}',
      '🚚 Entrega: {{tipo_entrega}}',
      '💳 Pagamento: {{pagamento}}',
      '{{pix_linha}}',
      '{{data_hora_prevista_linha}}',
      '{{bairro_linha}}',
      '{{endereco_linha}}',
      '{{cep_linha}}',
      '{{referencia_linha}}',
      '{{observacoes_linha}}',
    ]),
    'status_customer' => implode("\n", [
      'Olá{{cliente_saudacao}}!',
      'Seu pedido #{{pedido_id}} na {{loja}} foi atualizado.',
      'Status: {{status}}.',
      'Total: {{total}}.',
      '{{mensagem_status}}',
    ]),
  ];
}

function whatsapp_default_config(): array {
  return [
    'enabled' => false,
    'service_url' => getenv('WHATSAPP_SERVICE_URL') ?: 'http://127.0.0.1:3333',
    'api_token' => getenv('WHATSAPP_API_TOKEN') ?: '',
    'store_phone' => '',
    'send_order_to_store' => false,
    'send_order_confirmation_to_customer' => true,
    'send_status_to_customer' => true,
    'timeout_seconds' => 30,
    // Identificador usado apenas pelo serviço Node/WhatsApp Web.
    // Quando uma sessão fica travada, pode ser trocado sem alterar o ID real da empresa no sistema.
    'session_company_id' => '',
    'pix' => [
      'include_on_order' => false,
      'details' => '',
      'label' => '🔑 Pix',
    ],
    'templates' => whatsapp_default_templates(),
  ];
}

function whatsapp_load_config(PDO $pdo, int $companyId, bool $applyMessageModels = true): array {
  $cfg = whatsapp_default_config();
  try {
    $over = config_store_get($pdo, $companyId, 'whatsapp');
    if (is_array($over)) $cfg = config_deep_merge($cfg, $over);
  } catch (Throwable $e) {}
  $cfg['enabled'] = !empty($cfg['enabled']);
  $cfg['send_order_to_store'] = !empty($cfg['send_order_to_store']);
  $cfg['send_order_confirmation_to_customer'] = !empty($cfg['send_order_confirmation_to_customer']);
  $cfg['send_status_to_customer'] = !empty($cfg['send_status_to_customer']);
  $cfg['service_url'] = rtrim(trim((string)($cfg['service_url'] ?? '')), '/');
  $cfg['api_token'] = trim((string)($cfg['api_token'] ?? ''));
  $cfg['store_phone'] = trim((string)($cfg['store_phone'] ?? ''));
  $cfg['timeout_seconds'] = max(2, min(60, (int)($cfg['timeout_seconds'] ?? 30)));
  $cfg['session_company_id'] = whatsapp_digits((string)($cfg['session_company_id'] ?? ''));
  if ($cfg['session_company_id'] === '0') $cfg['session_company_id'] = '';
  if (!isset($cfg['pix']) || !is_array($cfg['pix'])) $cfg['pix'] = [];
  $cfg['pix'] = config_deep_merge([
    'include_on_order' => false,
    'details' => '',
    'label' => '🔑 Pix',
  ], $cfg['pix']);
  $cfg['pix']['include_on_order'] = !empty($cfg['pix']['include_on_order']);
  $cfg['pix']['details'] = trim((string)($cfg['pix']['details'] ?? ''));
  $cfg['pix']['label'] = trim((string)($cfg['pix']['label'] ?? '🔑 Pix'));
  if ($cfg['pix']['label'] === '') $cfg['pix']['label'] = '🔑 Pix';
  if (!isset($cfg['templates']) || !is_array($cfg['templates'])) $cfg['templates'] = [];
  $cfg['templates'] = config_deep_merge(whatsapp_default_templates(), $cfg['templates']);
  foreach ($cfg['templates'] as $k => $v) $cfg['templates'][$k] = (string)$v;

  // Modelo cadastrado é opt-in: enquanto nenhum padrão de Pedido for escolhido,
  // o texto atual continua exatamente como já funcionava.
  if ($applyMessageModels) {
    try {
      $pedidoModel = whatsapp_message_model_text($pdo, $companyId, 'pedido');
      if ($pedidoModel !== '') $cfg['templates']['order_customer_full'] = $pedidoModel;
    } catch (Throwable $e) {}
  }

  return $cfg;
}

function whatsapp_save_config(PDO $pdo, int $companyId, array $cfg): void {
  $templates = whatsapp_default_templates();
  if (isset($cfg['templates']) && is_array($cfg['templates'])) {
    foreach ($templates as $key => $defaultText) {
      $value = trim((string)($cfg['templates'][$key] ?? ''));
      $templates[$key] = $value !== '' ? $value : $defaultText;
    }
  }

  $clean = [
    'enabled' => !empty($cfg['enabled']),
    'service_url' => rtrim(trim((string)($cfg['service_url'] ?? '')), '/'),
    'api_token' => trim((string)($cfg['api_token'] ?? '')),
    'store_phone' => whatsapp_digits((string)($cfg['store_phone'] ?? '')),
    'send_order_to_store' => false,
    'send_order_confirmation_to_customer' => !empty($cfg['send_order_confirmation_to_customer']),
    'send_status_to_customer' => !empty($cfg['send_status_to_customer']),
    'timeout_seconds' => max(2, min(60, (int)($cfg['timeout_seconds'] ?? 30))),
    'session_company_id' => whatsapp_digits((string)($cfg['session_company_id'] ?? '')),
    'pix' => [
      'include_on_order' => !empty($cfg['pix']['include_on_order']),
      'details' => mb_substr(trim((string)($cfg['pix']['details'] ?? '')), 0, 1000),
      'label' => trim((string)($cfg['pix']['label'] ?? '🔑 Pix')) !== '' ? mb_substr(trim((string)($cfg['pix']['label'] ?? '🔑 Pix')), 0, 40) : '🔑 Pix',
    ],
    'templates' => $templates,
  ];
  if ($clean['service_url'] === '') $clean['service_url'] = 'http://127.0.0.1:3333';
  config_store_set($pdo, $companyId, 'whatsapp', $clean);
}

function whatsapp_digits(string $value): string {
  return preg_replace('/\D+/', '', $value) ?? '';
}

function whatsapp_normalize_phone(string $value): string {
  $n = whatsapp_digits($value);
  if ($n === '') return '';
  // Brasil: se vier com DDD sem DDI, inclui 55.
  if ((strlen($n) === 10 || strlen($n) === 11) && !str_starts_with($n, '55')) {
    $n = '55' . $n;
  }
  return $n;
}

function whatsapp_generate_service_token(): string {
  return bin2hex(random_bytes(32));
}


function whatsapp_service_company_id(array $cfg, int $companyId): string {
  $sessionCompanyId = whatsapp_digits((string)($cfg['session_company_id'] ?? ''));
  if ($sessionCompanyId === '' || $sessionCompanyId === '0') return (string)$companyId;
  return $sessionCompanyId;
}

function whatsapp_generate_service_company_id(int $companyId): string {
  // Mantém um número legível e evita reaproveitar o perfil travado session-company-X.
  // Ex.: empresa 11 pode virar 11834721. O ID real da empresa continua 11 no banco.
  $prefix = max(1, $companyId) * 1000000;
  return (string)($prefix + random_int(100000, 999999));
}

function whatsapp_rotate_service_company_id(PDO $pdo, int $companyId): array {
  $cfg = whatsapp_load_config($pdo, $companyId);
  $oldServiceCompanyId = whatsapp_service_company_id($cfg, $companyId);
  $newServiceCompanyId = whatsapp_generate_service_company_id($companyId);
  $guard = 0;
  while ($newServiceCompanyId === $oldServiceCompanyId && $guard < 5) {
    $newServiceCompanyId = whatsapp_generate_service_company_id($companyId);
    $guard++;
  }
  $cfg['session_company_id'] = $newServiceCompanyId;
  whatsapp_save_config($pdo, $companyId, $cfg);
  $cfg = whatsapp_load_config($pdo, $companyId);
  return [
    'old_service_company_id' => $oldServiceCompanyId,
    'new_service_company_id' => whatsapp_service_company_id($cfg, $companyId),
    'config' => $cfg,
  ];
}

/**
 * Registra um token específico da empresa no serviço Node.
 *
 * O serviço aceita o token global de ambiente e tokens por empresa salvos em tokens.json.
 * Esta função usa o token atual para autenticar no serviço e cadastrar o novo token.
 */
function whatsapp_register_company_token(array $currentCfg, $companyId, string $newToken): array {
  $newToken = trim($newToken);
  if ($newToken === '') {
    return ['ok' => false, 'error' => 'Token vazio.'];
  }

  $cfg = $currentCfg;
  $cfg['timeout_seconds'] = max(5, (int)($cfg['timeout_seconds'] ?? 30));

  try {
    return whatsapp_api_request($cfg, 'POST', '/tokens/upsert', [
      'company_id' => (string)$companyId,
      'token' => $newToken,
    ]);
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function whatsapp_ensure_schema(PDO $pdo): void {
  try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NOT NULL,
      pedido_id INT UNSIGNED NULL,
      tipo VARCHAR(40) NOT NULL,
      destino VARCHAR(30) NOT NULL,
      mensagem LONGTEXT NULL,
      status VARCHAR(30) NOT NULL DEFAULT 'pending',
      erro VARCHAR(255) NULL,
      response_json LONGTEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_whatsapp_logs_company (company_id),
      KEY idx_whatsapp_logs_pedido (pedido_id),
      KEY idx_whatsapp_logs_status (status),
      KEY idx_whatsapp_logs_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  } catch (Throwable $e) {}
}

function whatsapp_log(PDO $pdo, int $companyId, ?int $pedidoId, string $tipo, string $destino, string $mensagem, string $status, string $erro = '', array $response = [], bool $skipSchemaEnsure = false): void {
  try {
    if (!$skipSchemaEnsure) whatsapp_ensure_schema($pdo);
    $st = $pdo->prepare('INSERT INTO whatsapp_logs (company_id, pedido_id, tipo, destino, mensagem, status, erro, response_json) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    $st->execute([
      $companyId,
      $pedidoId ?: null,
      mb_substr($tipo, 0, 40),
      mb_substr($destino, 0, 30),
      $mensagem,
      mb_substr($status, 0, 30),
      $erro !== '' ? mb_substr($erro, 0, 255) : null,
      $response ? json_encode($response, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
    ]);
  } catch (Throwable $e) {}
}

function whatsapp_api_request(array $cfg, string $method, string $path, array $payload = []): array {
  $base = rtrim((string)($cfg['service_url'] ?? ''), '/');
  if ($base === '') throw new RuntimeException('URL do serviço de WhatsApp não configurada.');

  $url = $base . '/' . ltrim($path, '/');
  $headers = ['Content-Type: application/json'];
  $token = trim((string)($cfg['api_token'] ?? ''));
  if ($token !== '') $headers[] = 'X-API-Token: ' . $token;

  $method = strtoupper($method);
  $timeout = max(2, min(60, (int)($cfg['timeout_seconds'] ?? 30)));
  // O envio com fallback visual pelo WhatsApp Web pode levar alguns segundos.
  // Status/QR continuam rápidos, mas /send precisa de mais tempo para não abortar no PHP.
  if (str_starts_with('/' . ltrim($path, '/'), '/send-document')) {
    $timeout = max(60, $timeout);
  } elseif (str_starts_with('/' . ltrim($path, '/'), '/send')) {
    $timeout = max(25, $timeout);
  }
  $body = $payload ? json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '';

  if (function_exists('curl_init')) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CUSTOMREQUEST => $method,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_CONNECTTIMEOUT => $timeout,
      CURLOPT_TIMEOUT => $timeout,
    ]);
    if ($method !== 'GET') curl_setopt($ch, CURLOPT_POSTFIELDS, $body ?: '{}');
    $raw = curl_exec($ch);
    $err = curl_error($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false) throw new RuntimeException($err ?: 'Falha ao conectar no serviço WhatsApp.');
  } else {
    $opts = [
      'http' => [
        'method' => $method,
        'header' => implode("\r\n", $headers),
        'timeout' => $timeout,
        'ignore_errors' => true,
      ],
    ];
    if ($method !== 'GET') $opts['http']['content'] = $body ?: '{}';
    $raw = @file_get_contents($url, false, stream_context_create($opts));
    $code = 0;
    if (isset($http_response_header) && is_array($http_response_header)) {
      foreach ($http_response_header as $h) {
        if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int)$m[1]; break; }
      }
    }
    if ($raw === false) throw new RuntimeException('Falha ao conectar no serviço WhatsApp.');
  }

  $data = json_decode((string)$raw, true);
  if (!is_array($data)) $data = ['ok' => false, 'raw' => (string)$raw];
  if ($code >= 400) {
    $msg = (string)($data['error'] ?? $data['message'] ?? ('HTTP ' . $code));
    throw new RuntimeException($msg);
  }
  return $data;
}


function whatsapp_norm_for_compare(string $value): string {
  $value = trim($value);
  if (function_exists('iconv')) {
    $converted = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if (is_string($converted) && $converted !== '') $value = $converted;
  }
  $value = strtolower($value);
  return preg_replace('/[^a-z0-9]+/', '', $value) ?? '';
}

function whatsapp_payment_is_pix(string $payment): bool {
  $norm = whatsapp_norm_for_compare($payment);
  return $norm === 'pix' || str_contains($norm, 'pix');
}

function whatsapp_pix_line_for_payment(string $payment, ?array $cfg = null): string {
  if (!whatsapp_payment_is_pix($payment)) return '';
  $cfg = $cfg ?: [];
  $pix = $cfg['pix'] ?? [];
  if (!is_array($pix) || empty($pix['include_on_order'])) return '';

  $details = trim((string)($pix['details'] ?? ''));
  if ($details === '') return '';

  $label = trim((string)($pix['label'] ?? '🔑 Pix'));
  if ($label === '') $label = '🔑 Pix';

  $details = str_replace(["\r\n", "\r"], "\n", $details);
  $lines = [];
  foreach (preg_split('/\n/u', $details) as $line) {
    $line = trim((string)$line);
    if ($line !== '') $lines[] = $line;
  }
  if (!$lines) return '';
  if (count($lines) === 1) return $label . ': ' . $lines[0];
  return $label . ":\n" . implode("\n", $lines);
}

function whatsapp_render_order_template(string $template, array $vars): string {
  $out = whatsapp_render_template($template, $vars);
  $pixLine = trim((string)($vars['pix_linha'] ?? ''));
  if ($pixLine === '' || str_contains($out, $pixLine)) return $out;

  $lines = explode("\n", $out);
  foreach ($lines as $idx => $line) {
    $norm = whatsapp_norm_for_compare($line);
    if (str_contains($norm, 'pagamento') || str_contains($norm, 'payment')) {
      array_splice($lines, $idx + 1, 0, [$pixLine]);
      return trim(implode("\n", $lines));
    }
  }
  return trim($out . "\n" . $pixLine);
}

function whatsapp_send_message(PDO $pdo, int $companyId, string $to, string $message, string $tipo = 'manual', ?int $pedidoId = null, ?array $cfg = null): bool {
  $cfg = $cfg ?: whatsapp_load_config($pdo, $companyId);
  if (empty($cfg['enabled'])) {
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, whatsapp_digits($to), $message, 'skipped', 'Automação WhatsApp desativada.');
    return false;
  }
  $destino = whatsapp_normalize_phone($to);
  $message = trim($message);
  if ($destino === '' || $message === '') {
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, whatsapp_digits($to), $message, 'error', 'Destino ou mensagem vazia.');
    return false;
  }

  try {
    $res = whatsapp_api_request($cfg, 'POST', '/send', [
      'company_id' => whatsapp_service_company_id($cfg, $companyId),
      'to' => $destino,
      'message' => $message,
    ]);
    $ok = !empty($res['ok']);
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, $destino, $message, $ok ? 'sent' : 'error', $ok ? '' : (string)($res['error'] ?? 'Falha no envio'), $res);
    return $ok;
  } catch (Throwable $e) {
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, $destino, $message, 'error', $e->getMessage());
    return false;
  }
}


/**
 * Envia um documento binário pelo serviço WhatsApp conectado.
 *
 * O serviço Node deve disponibilizar POST /send-document e aceitar o arquivo
 * em Base64. O conteúdo não é persistido no log do MySQL.
 *
 * @return array{ok:bool,error:string,response:array}
 */
function whatsapp_send_document(
  PDO $pdo,
  int $companyId,
  string $to,
  string $caption,
  string $fileName,
  string $mimeType,
  string $binaryContent,
  string $tipo = 'fiscal_document_admin',
  ?int $pedidoId = null,
  ?array $cfg = null,
  bool $skipSchemaEnsure = false
): array {
  $cfg = $cfg ?: whatsapp_load_config($pdo, $companyId);
  if (empty($cfg['enabled'])) {
    $error = 'Automação WhatsApp desativada.';
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, whatsapp_digits($to), $caption, 'skipped', $error, [], $skipSchemaEnsure);
    return ['ok' => false, 'error' => $error, 'response' => []];
  }

  $destino = whatsapp_normalize_phone($to);
  $caption = trim($caption);
  $fileName = trim($fileName);
  $mimeType = trim($mimeType);

  if ($destino === '') {
    $error = 'Número de destino inválido.';
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, whatsapp_digits($to), $caption, 'error', $error, [], $skipSchemaEnsure);
    return ['ok' => false, 'error' => $error, 'response' => []];
  }
  if ($binaryContent === '') {
    $error = 'Arquivo do documento fiscal vazio.';
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, $destino, $caption, 'error', $error, [], $skipSchemaEnsure);
    return ['ok' => false, 'error' => $error, 'response' => []];
  }
  if (strlen($binaryContent) > 8 * 1024 * 1024) {
    $error = 'O documento ultrapassa o limite de 8 MB para envio.';
    whatsapp_log($pdo, $companyId, $pedidoId, $tipo, $destino, $caption, 'error', $error, [], $skipSchemaEnsure);
    return ['ok' => false, 'error' => $error, 'response' => []];
  }
  if ($fileName === '') $fileName = 'documento-fiscal.pdf';
  if ($mimeType === '') $mimeType = 'application/pdf';

  try {
    $cfg['timeout_seconds'] = max(60, (int)($cfg['timeout_seconds'] ?? 30));
    $res = whatsapp_api_request($cfg, 'POST', '/send-document', [
      'company_id' => whatsapp_service_company_id($cfg, $companyId),
      'to' => $destino,
      'caption' => $caption,
      'filename' => $fileName,
      'mimetype' => $mimeType,
      'file_base64' => base64_encode($binaryContent),
    ]);
    $ok = !empty($res['ok']);
    $error = $ok ? '' : (string)($res['error'] ?? 'Falha no envio do PDF.');
    whatsapp_log(
      $pdo,
      $companyId,
      $pedidoId,
      $tipo,
      $destino,
      $caption . "\n[Anexo: " . $fileName . ']',
      $ok ? 'sent' : 'error',
      $error,
      $res,
      $skipSchemaEnsure
    );
    return ['ok' => $ok, 'error' => $error, 'response' => $res];
  } catch (Throwable $e) {
    $error = $e->getMessage();
    if (stripos($error, 'Cannot POST /send-document') !== false || stripos($error, '404') !== false) {
      $error = 'O serviço WhatsApp ainda não possui o recurso de envio de PDF. Atualize o server.js do serviço Node e reinicie o PM2.';
    }
    whatsapp_log(
      $pdo,
      $companyId,
      $pedidoId,
      $tipo,
      $destino,
      $caption . "\n[Anexo: " . $fileName . ']',
      'error',
      $error,
      [],
      $skipSchemaEnsure
    );
    return ['ok' => false, 'error' => $error, 'response' => []];
  }
}

function whatsapp_render_template(string $template, array $vars): string {
  $replace = [];
  foreach ($vars as $key => $value) {
    $replace['{{' . $key . '}}'] = (string)$value;
  }
  $out = strtr($template, $replace);
  $out = preg_replace('/^[ 	]*\{\{[a-zA-Z0-9_]+\}\}[ 	]*$/m', '', (string)$out);
  $out = preg_replace('/[ 	]+\n/u', "\n", (string)$out);
  $out = preg_replace('/\n{3,}/u', "\n\n", (string)$out);
  return trim((string)$out);
}

function whatsapp_template_from_config(array $cfg, string $key): string {
  $templates = $cfg['templates'] ?? [];
  if (is_array($templates) && trim((string)($templates[$key] ?? '')) !== '') {
    return (string)$templates[$key];
  }
  $defaults = whatsapp_default_templates();
  return (string)($defaults[$key] ?? '');
}

function whatsapp_optional_line(string $label, string $value): string {
  $value = trim($value);
  return $value !== '' ? $label . $value : '';
}

function whatsapp_status_label(string $status): string {
  return match ($status) {
    'aberto' => 'Aberto',
    'confirmado' => 'Confirmado',
    'em_producao' => 'Em produção',
    'saiu_entrega' => 'Saiu para entrega',
    'finalizado' => 'Finalizado',
    'cancelado' => 'Cancelado',
    default => ucfirst(str_replace('_', ' ', $status)),
  };
}

function whatsapp_build_status_message(array $pedido, string $novoStatus, array $app = [], ?array $cfg = null): string {
  $nome = trim((string)($pedido['nome_cliente'] ?? ''));
  $id = (int)($pedido['numero_pedido'] ?? ($pedido['id'] ?? 0));
  $loja = trim((string)($app['site_name'] ?? ($app['brand']['catalog_title'] ?? 'Nossa loja')));
  if ($loja === '') $loja = 'Nossa loja';
  $status = whatsapp_status_label($novoStatus);
  $total = money((float)($pedido['total'] ?? 0));

  $mensagemStatus = '';
  if ($novoStatus === 'confirmado') $mensagemStatus = 'Já recebemos seu pedido e vamos iniciar o preparo.';
  if ($novoStatus === 'em_producao') $mensagemStatus = 'Seu pedido está em preparo.';
  if ($novoStatus === 'saiu_entrega') $mensagemStatus = 'Seu pedido saiu para entrega.';
  if ($novoStatus === 'finalizado') $mensagemStatus = 'Obrigado pela preferência!';
  if ($novoStatus === 'cancelado') $mensagemStatus = 'Caso tenha alguma dúvida, fale conosco.';

  $cfg = $cfg ?: ['templates' => whatsapp_default_templates()];
  $template = whatsapp_template_from_config($cfg, 'status_customer');
  return whatsapp_render_template($template, [
    'cliente' => $nome,
    'cliente_saudacao' => $nome !== '' ? ', ' . $nome : '',
    'pedido_id' => (string)$id,
    'loja' => $loja,
    'status' => $status,
    'status_codigo' => $novoStatus,
    'total' => $total,
    'mensagem_status' => $mensagemStatus,
  ]);
}

function whatsapp_build_customer_order_message(int $pedidoId, string $nome, float $totalGeral, string $tipoEntrega, string $formaPgto, array $app = []): string {
  $loja = trim((string)($app['site_name'] ?? 'Nossa loja'));
  $msg = "Olá" . (trim($nome) !== '' ? ", " . trim($nome) : '') . "!\n";
  $msg .= "Recebemos seu pedido #{$pedidoId} na {$loja}.\n";
  $msg .= "Total: " . money($totalGeral) . ".\n";
  $msg .= "Entrega: " . ($tipoEntrega === 'retirada' ? (string)($app['retirada_label'] ?? 'Retirada no balcão') : 'Entrega') . ".\n";
  $msg .= "Pagamento: " . ucfirst($formaPgto) . ".";
  return $msg;
}
