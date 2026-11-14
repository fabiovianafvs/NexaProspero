<?php
declare(strict_types=1);

/*
 * Nexa Próspero - Agentes de IA (módulo do SaaS "ia_agentes").
 *
 * Configuração por empresa em app_config, chave "ia":
 *  - key_source: 'platform' (chave única da plataforma, em /etc/nexa/ia.env,
 *    variável NEXA_IA_API_KEY) ou 'company' (chave própria da empresa).
 *  - model: modelo Claude usado pelos agentes.
 *  - entrada: opções do agente de entrada de notas (config/ia_entrada_notas.php).
 * A chave nunca vai para o código nem para o navegador.
 */

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/nexa_env.php';
nexa_load_environment_file('/etc/nexa/ia.env');

if (!function_exists('ia_models')) {
  /** Modelos oferecidos na tela e preço estimado (US$ por milhão de tokens: entrada, saída). */
  function ia_models(): array {
    return [
      'claude-haiku-5-5' => ['label' => 'Claude Haiku 5.5 (econômico, recomendado)', 'in' => 0.10, 'out' => 0.50],
      'claude-sonnet-5-5' => ['label' => 'Claude Sonnet 5.5 (mais preciso)', 'in' => 2.00, 'out' => 10.00],
    ];
  }
}

if (!function_exists('ia_defaults')) {
  function ia_defaults(): array {
    return [
      'enabled' => false,
      'key_source' => 'platform',
      'api_key' => '',
      'model' => '',
      'entrada' => [
        'enabled' => false,
        'mode' => 'manual',          // 'auto' = ao chegar nota no Radar; 'manual' = botão no Radar
        'auto_ciencia' => false,     // registra Ciência da Emissão para baixar o XML completo
        'create_products' => true,   // cadastra produto novo quando não encontrar no Nexa
        'default_category' => 'XML / Compras',
        'default_margin' => 30,      // usada só quando não há produto parecido com custo e preço
        'since' => '',               // notas do Radar a partir desta data (modo automático)
      ],
    ];
  }
}

if (!function_exists('ia_ready')) {
  function ia_ready(PDO $pdo): bool {
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
      $pdo->query('SELECT 1 FROM ai_agent_runs LIMIT 1');
      $pdo->query('SELECT 1 FROM ai_usage_log LIMIT 1');
      return $ok = true;
    } catch (Throwable $e) {
      return $ok = false;
    }
  }
}

if (!function_exists('ia_module_enabled')) {
  function ia_module_enabled(PDO $pdo, int $companyId): bool {
    try {
      require_once __DIR__ . '/saas_modules.php';
      return function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'ia_agentes');
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('ia_config')) {
  function ia_config(PDO $pdo, int $companyId): array {
    $cfg = ia_defaults();
    try {
      $saved = config_store_get($pdo, $companyId, 'ia');
      if (is_array($saved)) {
        $entrada = array_merge($cfg['entrada'], array_intersect_key((array)($saved['entrada'] ?? []), $cfg['entrada']));
        $cfg = array_merge($cfg, array_intersect_key($saved, $cfg));
        $cfg['entrada'] = $entrada;
      }
    } catch (Throwable $e) {}
    $cfg['enabled'] = !empty($cfg['enabled']);
    $cfg['key_source'] = $cfg['key_source'] === 'company' ? 'company' : 'platform';
    $cfg['api_key'] = trim((string)$cfg['api_key']);
    if (!isset(ia_models()[(string)$cfg['model']])) $cfg['model'] = '';
    $e = $cfg['entrada'];
    $e['enabled'] = !empty($e['enabled']);
    $e['mode'] = $e['mode'] === 'auto' ? 'auto' : 'manual';
    $e['auto_ciencia'] = !empty($e['auto_ciencia']);
    $e['create_products'] = !empty($e['create_products']);
    $e['default_category'] = trim((string)$e['default_category']) ?: 'XML / Compras';
    $e['default_margin'] = max(0.0, min(1000.0, (float)$e['default_margin']));
    $e['since'] = (string)$e['since'];
    $cfg['entrada'] = $e;
    return $cfg;
  }
}

if (!function_exists('ia_config_save')) {
  function ia_config_save(PDO $pdo, int $companyId, array $cfg): void {
    config_store_set($pdo, $companyId, 'ia', $cfg);
  }
}

if (!function_exists('ia_platform_key')) {
  function ia_platform_key(): string {
    return trim((string)(getenv('NEXA_IA_API_KEY') ?: ''));
  }
}

if (!function_exists('ia_model')) {
  function ia_model(array $cfg): string {
    if ($cfg['model'] !== '') return $cfg['model'];
    $env = trim((string)(getenv('NEXA_IA_MODEL') ?: ''));
    return isset(ia_models()[$env]) ? $env : 'claude-haiku-5-5';
  }
}

if (!function_exists('ia_resolve_key')) {
  /** [chave, origem]. Usa a chave da empresa quando ela escolheu; senão a da plataforma. */
  function ia_resolve_key(array $cfg): array {
    if ($cfg['key_source'] === 'company' && $cfg['api_key'] !== '') return [$cfg['api_key'], 'company'];
    $platform = ia_platform_key();
    if ($platform !== '') return [$platform, 'platform'];
    return ['', ''];
  }
}

if (!function_exists('ia_available')) {
  /** A empresa pode usar a IA agora? Devolve '' quando sim, ou o motivo. */
  function ia_unavailable_reason(PDO $pdo, int $companyId, ?array $cfg = null): string {
    if (!ia_ready($pdo)) return 'Aplique a migração "Agentes de IA" no Atualizador do Banco.';
    if (!ia_module_enabled($pdo, $companyId)) return 'O módulo Agentes de IA não está liberado para esta empresa.';
    $cfg = $cfg ?? ia_config($pdo, $companyId);
    if (!$cfg['enabled']) return 'Os agentes de IA estão desligados em Configurações > Agentes de IA.';
    [$key] = ia_resolve_key($cfg);
    if ($key === '') {
      return $cfg['key_source'] === 'company'
        ? 'Informe a chave da Anthropic da empresa em Configurações > Agentes de IA.'
        : 'A chave da IA da plataforma ainda não foi configurada no servidor.';
    }
    return '';
  }
  function ia_available(PDO $pdo, int $companyId, ?array $cfg = null): bool {
    return ia_unavailable_reason($pdo, $companyId, $cfg) === '';
  }
}

if (!function_exists('ia_mask_key')) {
  function ia_mask_key(string $key): string {
    $key = trim($key);
    if ($key === '') return '';
    return '••••••••' . substr($key, -4);
  }
}

if (!function_exists('ia_log_usage')) {
  function ia_log_usage(PDO $pdo, int $companyId, string $agent, string $model, string $source, int $in, int $out, bool $ok, string $ref = '', string $error = ''): void {
    if (!ia_ready($pdo)) return;
    try {
      $pdo->prepare('INSERT INTO ai_usage_log (company_id, agent, model, key_source, input_tokens, output_tokens, ok, ref, error_message) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, substr($agent, 0, 40), substr($model, 0, 60), $source ?: 'platform', $in, $out, $ok ? 1 : 0, $ref !== '' ? substr($ref, 0, 80) : null, $error !== '' ? mb_substr($error, 0, 255) : null]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('ia_usage_month')) {
  /** Consumo do mês corrente: chamadas, tokens e custo estimado (US$). */
  function ia_usage_month(PDO $pdo, int $companyId): array {
    $out = ['calls' => 0, 'errors' => 0, 'input' => 0, 'output' => 0, 'usd' => 0.0];
    if (!ia_ready($pdo)) return $out;
    try {
      $st = $pdo->prepare("SELECT model, COUNT(*) calls, SUM(1-ok) errors, SUM(input_tokens) tin, SUM(output_tokens) tout FROM ai_usage_log WHERE company_id=? AND created_at >= ? GROUP BY model");
      $st->execute([$companyId, date('Y-m-01 00:00:00')]);
      $models = ia_models();
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $out['calls'] += (int)$r['calls'];
        $out['errors'] += (int)$r['errors'];
        $out['input'] += (int)$r['tin'];
        $out['output'] += (int)$r['tout'];
        $price = $models[(string)$r['model']] ?? ['in' => 2.0, 'out' => 10.0];
        $out['usd'] += ((int)$r['tin'] * $price['in'] + (int)$r['tout'] * $price['out']) / 1000000;
      }
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('ia_claude_json')) {
  /**
   * Chama o Claude e devolve a resposta já como array, no formato do $schema
   * (saída estruturada da Messages API: output_config.format = json_schema).
   * Lança RuntimeException com mensagem em português quando não der certo.
   */
  function ia_claude_json(PDO $pdo, int $companyId, string $agent, string $system, string $user, array $schema, array $opts = []): array {
    $cfg = $opts['cfg'] ?? ia_config($pdo, $companyId);
    [$key, $source] = ia_resolve_key($cfg);
    if ($key === '') throw new RuntimeException('Chave da IA não configurada.');
    $model = (string)($opts['model'] ?? ia_model($cfg));
    $url = trim((string)(getenv('NEXA_IA_API_URL') ?: '')) ?: 'https://api.anthropic.com/v1/messages';
    $body = [
      'model' => $model,
      'max_tokens' => (int)($opts['max_tokens'] ?? 8000),
      'system' => $system,
      'messages' => [['role' => 'user', 'content' => $user]],
      'output_config' => ['effort' => (string)($opts['effort'] ?? 'low'), 'format' => ['type' => 'json_schema', 'schema' => $schema]],
    ];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_POST => true,
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 10,
      CURLOPT_TIMEOUT => (int)($opts['timeout'] ?? 120),
      CURLOPT_HTTPHEADER => ['content-type: application/json', 'x-api-key: ' . $key, 'anthropic-version: 2023-06-01'],
      CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $raw = curl_exec($ch);
    $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr = curl_error($ch);
    curl_close($ch);
    $ref = (string)($opts['ref'] ?? '');
    if ($raw === false || $raw === '') {
      ia_log_usage($pdo, $companyId, $agent, $model, $source, 0, 0, false, $ref, 'Sem resposta: ' . $curlErr);
      throw new RuntimeException('A IA não respondeu (' . ($curlErr ?: 'sem resposta') . '). Tente novamente em instantes.');
    }
    $res = json_decode((string)$raw, true);
    $usage = is_array($res) ? (array)($res['usage'] ?? []) : [];
    $tin = (int)($usage['input_tokens'] ?? 0) + (int)($usage['cache_read_input_tokens'] ?? 0) + (int)($usage['cache_creation_input_tokens'] ?? 0);
    $tout = (int)($usage['output_tokens'] ?? 0);
    if ($http !== 200 || !is_array($res)) {
      $msg = is_array($res) ? (string)($res['error']['message'] ?? '') : '';
      ia_log_usage($pdo, $companyId, $agent, $model, $source, $tin, $tout, false, $ref, 'HTTP ' . $http . ' ' . $msg);
      if ($http === 401) throw new RuntimeException('A chave da IA foi recusada (verifique a chave em Configurações > Agentes de IA).');
      if ($http === 429) throw new RuntimeException('Limite de uso da IA atingido no momento. Tente novamente em alguns minutos.');
      if ($http === 529 || $http >= 500) throw new RuntimeException('O serviço da IA está sobrecarregado. Tente novamente em alguns minutos.');
      throw new RuntimeException('A IA recusou o pedido (HTTP ' . $http . ($msg !== '' ? ': ' . $msg : '') . ').');
    }
    $text = '';
    foreach ((array)($res['content'] ?? []) as $block) {
      if (($block['type'] ?? '') === 'text') $text .= (string)($block['text'] ?? '');
    }
    $stop = (string)($res['stop_reason'] ?? '');
    $data = json_decode($text, true);
    if ($stop === 'refusal' || $stop === 'max_tokens' || !is_array($data)) {
      ia_log_usage($pdo, $companyId, $agent, $model, $source, $tin, $tout, false, $ref, 'Resposta inválida (' . ($stop ?: 'json') . ')');
      throw new RuntimeException($stop === 'max_tokens' ? 'A resposta da IA ficou grande demais. Tente com menos itens.' : 'A IA não devolveu uma resposta válida.');
    }
    ia_log_usage($pdo, $companyId, $agent, $model, $source, $tin, $tout, true, $ref);
    return $data;
  }
}
