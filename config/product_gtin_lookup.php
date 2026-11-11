<?php
declare(strict_types=1);

/**
 * Consulta de produto pelo código de barras (GTIN/EAN) para o cadastro de produtos.
 *
 * Fontes, nesta ordem:
 *  1) Bluesoft Cosmos (catálogo brasileiro: descrição, foto, NCM, CEST, marca) —
 *     precisa de token da conta Cosmos da empresa (app_config 'produto_gtin') ou,
 *     para toda a plataforma, NEXA_COSMOS_TOKEN no ambiente.
 *  2) Open Food Facts (gratuito, sem token; bom para alimentos e bebidas).
 * As respostas ficam guardadas por empresa (app_config 'produto_gtin_cache') para
 * não gastar consultas repetidas.
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('product_gtin_digits')) {
  function product_gtin_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}

if (!function_exists('product_gtin_valid')) {
  /** GTIN-8/12/13/14 com dígito verificador correto. */
  function product_gtin_valid(string $gtin): bool {
    if (!in_array(strlen($gtin), [8, 12, 13, 14], true) || preg_match('/^0+$/', $gtin)) return false;
    $body = substr($gtin, 0, -1);
    $sum = 0;
    $len = strlen($body);
    for ($i = 0; $i < $len; $i++) {
      $digit = (int)$body[$len - 1 - $i];
      $sum += $digit * ($i % 2 === 0 ? 3 : 1);
    }
    return ((10 - ($sum % 10)) % 10) === (int)substr($gtin, -1);
  }
}

if (!function_exists('product_gtin_config')) {
  function product_gtin_config(PDO $pdo, int $companyId): array {
    $cfg = [];
    try { $cfg = config_store_get($pdo, $companyId, 'produto_gtin') ?: []; } catch (Throwable $e) {}
    $token = trim((string)($cfg['cosmos_token'] ?? ''));
    $global = false;
    if ($token === '') {
      $token = trim((string)(getenv('NEXA_COSMOS_TOKEN') ?: ''));
      $global = $token !== '';
    }
    return ['cosmos_token' => $token, 'cosmos_global' => $global, 'company_token_set' => trim((string)($cfg['cosmos_token'] ?? '')) !== ''];
  }
}

if (!function_exists('product_gtin_save_token')) {
  function product_gtin_save_token(PDO $pdo, int $companyId, string $token): void {
    $token = trim($token);
    if ($token !== '' && !preg_match('/^[A-Za-z0-9_\-]{10,200}$/', $token)) throw new RuntimeException('Token inválido.');
    $cfg = [];
    try { $cfg = config_store_get($pdo, $companyId, 'produto_gtin') ?: []; } catch (Throwable $e) {}
    $cfg['cosmos_token'] = $token;
    config_store_set($pdo, $companyId, 'produto_gtin', $cfg);
  }
}

if (!function_exists('product_gtin_http_json')) {
  /** GET com timeout curto. Retorna [status, json|null]. */
  function product_gtin_http_json(string $url, array $headers): array {
    if (!function_exists('curl_init')) return [0, null];
    $ch = curl_init($url);
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => $headers,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 10,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ]);
    $body = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = is_string($body) ? json_decode($body, true) : null;
    return [$status, is_array($json) ? $json : null];
  }
}

if (!function_exists('product_gtin_from_cosmos')) {
  function product_gtin_from_cosmos(string $gtin, string $token): ?array {
    [$status, $j] = product_gtin_http_json('https://api.cosmos.bluesoft.com.br/gtins/' . rawurlencode($gtin) . '.json', [
      'X-Cosmos-Token: ' . $token,
      'User-Agent: Cosmos-API-Request',
      'Content-Type: application/json',
    ]);
    if ($status === 401 || $status === 403) throw new RuntimeException('Token do Cosmos recusado. Confira o token em "Configurar consulta".');
    if ($status === 429) throw new RuntimeException('Limite de consultas do Cosmos atingido por hoje.');
    if ($status !== 200 || !$j || trim((string)($j['description'] ?? '')) === '') return null;
    $ncm = product_gtin_digits($j['ncm']['code'] ?? '');
    $cest = '';
    if (!empty($j['cest'])) $cest = product_gtin_digits(is_array($j['cest']) ? ($j['cest']['code'] ?? '') : $j['cest']);
    $price = '';
    if (isset($j['avg_price']) && is_numeric($j['avg_price'])) $price = number_format((float)$j['avg_price'], 2, '.', '');
    return [
      'source' => 'Cosmos (Bluesoft)',
      'gtin' => $gtin,
      'name' => trim((string)$j['description']),
      'brand' => trim((string)($j['brand']['name'] ?? '')),
      'ncm' => strlen($ncm) === 8 ? $ncm : '',
      'ncm_description' => trim((string)($j['ncm']['full_description'] ?? $j['ncm']['description'] ?? '')),
      'cest' => strlen($cest) === 7 ? $cest : '',
      'image_url' => trim((string)($j['thumbnail'] ?? '')),
      'quantity' => trim((string)($j['net_weight'] ?? '') !== '' ? ((string)$j['net_weight'] . ' g') : ''),
      'avg_price' => $price,
    ];
  }
}

if (!function_exists('product_gtin_from_openfoodfacts')) {
  function product_gtin_from_openfoodfacts(string $gtin): ?array {
    [$status, $j] = product_gtin_http_json('https://world.openfoodfacts.org/api/v2/product/' . rawurlencode($gtin) . '.json?fields=product_name,product_name_pt,brands,image_front_url,image_url,quantity', [
      'User-Agent: NexaProspero/1.0 (cadastro de produtos)',
    ]);
    if ($status !== 200 || !$j || (int)($j['status'] ?? 0) !== 1) return null;
    $p = (array)($j['product'] ?? []);
    $name = trim((string)($p['product_name_pt'] ?? '')) ?: trim((string)($p['product_name'] ?? ''));
    if ($name === '') return null;
    $brand = trim(explode(',', (string)($p['brands'] ?? ''))[0]);
    $qty = trim((string)($p['quantity'] ?? ''));
    return [
      'source' => 'Open Food Facts',
      'gtin' => $gtin,
      'name' => trim($name . ($brand !== '' && stripos($name, $brand) === false ? ' ' . $brand : '') . ($qty !== '' && stripos($name, $qty) === false ? ' ' . $qty : '')),
      'brand' => $brand,
      'ncm' => '',
      'ncm_description' => '',
      'cest' => '',
      'image_url' => trim((string)($p['image_front_url'] ?? $p['image_url'] ?? '')),
      'quantity' => $qty,
      'avg_price' => '',
    ];
  }
}

if (!function_exists('product_gtin_lookup')) {
  /** Retorna os dados do produto ou null se nenhuma fonte conhecer o código. */
  function product_gtin_lookup(PDO $pdo, int $companyId, string $rawGtin): ?array {
    $gtin = product_gtin_digits($rawGtin);
    if (!product_gtin_valid($gtin)) throw new RuntimeException('Código de barras inválido (confira os números).');
    $cache = [];
    try { $cache = config_store_get($pdo, $companyId, 'produto_gtin_cache') ?: []; } catch (Throwable $e) {}
    if (isset($cache[$gtin]['data']) && (int)($cache[$gtin]['at'] ?? 0) > time() - 30 * 86400) return $cache[$gtin]['data'] ?: null;

    $cfg = product_gtin_config($pdo, $companyId);
    $data = null;
    $cosmosError = null;
    if ($cfg['cosmos_token'] !== '') {
      try { $data = product_gtin_from_cosmos($gtin, $cfg['cosmos_token']); } catch (RuntimeException $e) { $cosmosError = $e; }
    }
    if (!$data) $data = product_gtin_from_openfoodfacts($gtin);
    if (!$data && $cosmosError) throw $cosmosError;

    // Guarda (inclusive "não encontrado") para não repetir a consulta; até 300 códigos.
    $cache[$gtin] = ['at' => time(), 'data' => $data ?: []];
    if (count($cache) > 300) {
      uasort($cache, static fn($a, $b) => ((int)($a['at'] ?? 0)) <=> ((int)($b['at'] ?? 0)));
      $cache = array_slice($cache, -300, null, true);
    }
    try { config_store_set($pdo, $companyId, 'produto_gtin_cache', $cache); } catch (Throwable $e) {}
    return $data;
  }
}

if (!function_exists('product_gtin_image_allowed')) {
  /** Só baixa imagem das fontes conhecidas, por HTTPS (evita usar o servidor para acessar outros endereços). */
  function product_gtin_image_allowed(string $url): bool {
    $p = parse_url($url);
    if (!is_array($p) || strtolower((string)($p['scheme'] ?? '')) !== 'https') return false;
    $host = strtolower((string)($p['host'] ?? ''));
    foreach (['bluesoft.com.br', 'bluesoft.io', 'openfoodfacts.org'] as $ok) {
      if ($host === $ok || substr($host, -strlen('.' . $ok)) === '.' . $ok) return true;
    }
    return false;
  }
}

if (!function_exists('product_gtin_download_image')) {
  /** Baixa a foto sugerida e grava em uploads/products. Retorna o nome do arquivo ou ''. */
  function product_gtin_download_image(string $url, string $baseName, string $destDir): string {
    if (!product_gtin_image_allowed($url) || !function_exists('curl_init')) return '';
    $ch = curl_init($url);
    $max = 3 * 1024 * 1024;
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 12,
      CURLOPT_FOLLOWLOCATION => false,
      CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
      CURLOPT_NOPROGRESS => false,
      CURLOPT_PROGRESSFUNCTION => static fn($c, $t, $d) => $d > $max ? 1 : 0,
      CURLOPT_USERAGENT => 'NexaProspero/1.0',
    ]);
    $bytes = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($bytes) || $status !== 200 || $bytes === '' || strlen($bytes) > $max) return '';
    $info = @getimagesizefromstring($bytes);
    $ext = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'][$info['mime'] ?? ''] ?? '';
    if ($ext === '') return '';
    if (!is_dir($destDir)) @mkdir($destDir, 0775, true);
    $safe = trim((string)preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($baseName)), '-') ?: 'produto';
    $name = substr($safe, 0, 60) . '-' . time() . '.' . $ext;
    return @file_put_contents(rtrim($destDir, '/') . '/' . $name, $bytes) !== false ? $name : '';
  }
}
