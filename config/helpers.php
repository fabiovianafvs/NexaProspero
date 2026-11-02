<?php
declare(strict_types=1);

function e(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// Alias (compatibilidade)
function h(string $value): string { return e($value); }

function money(float $value): string {
  return 'R$ ' . number_format($value, 2, ',', '.');
}


/**
 * Monta o padrão LIKE usado nas pesquisas textuais do Nexa.
 *
 * O usuário pode separar partes do texto apenas com espaços. Ex.:
 *   "interruptor petra" -> "%interruptor%petra%"
 * Assim não é necessário digitar o caractere % manualmente para localizar
 * palavras que estejam separadas no começo/meio/fim do mesmo campo.
 *
 * Wildcards digitados manualmente continuam aceitos para compatibilidade.
 */
function nexa_search_like(string $value): string {
  $value = trim($value);
  if ($value === '') return '%';

  // Espaços passam a significar "qualquer conteúdo entre as palavras".
  $value = (string)(preg_replace('/\s+/u', '%', $value) ?? $value);
  // Evita sequências desnecessárias de curingas quando o usuário já digitou %.
  $value = (string)(preg_replace('/%+/', '%', $value) ?? $value);

  $value = trim($value, '%');
  if ($value === '') return '%';

  return '%' . $value . '%';
}

function app_apply_company_timezone(?PDO $pdo, string $timezone): string {
  $timezone = trim($timezone) ?: 'America/Sao_Paulo';

  try {
    $tz = new DateTimeZone($timezone);
  } catch (Throwable $e) {
    $timezone = 'America/Sao_Paulo';
    $tz = new DateTimeZone($timezone);
  }

  try {
    date_default_timezone_set($timezone);
  } catch (Throwable $e) {
    date_default_timezone_set('America/Sao_Paulo');
    $timezone = 'America/Sao_Paulo';
    $tz = new DateTimeZone($timezone);
  }

  if ($pdo instanceof PDO) {
    try {
      $now = new DateTimeImmutable('now', $tz);
      $offset = $tz->getOffset($now);
      $sign = $offset >= 0 ? '+' : '-';
      $abs = abs($offset);
      $hh = str_pad((string)floor($abs / 3600), 2, '0', STR_PAD_LEFT);
      $mm = str_pad((string)floor(($abs % 3600) / 60), 2, '0', STR_PAD_LEFT);
      $pdo->exec("SET time_zone = " . $pdo->quote($sign . $hh . ':' . $mm));
    } catch (Throwable $e) {
      // Se o banco não permitir alterar o timezone da sessão, mantém ao menos o PHP ajustado.
    }
  }

  return $timezone;
}

function request_is_https(): bool {
  return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
      || (($_SERVER['SERVER_PORT'] ?? '') == 443)
      || (strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https');
}

function current_host_name(): string {
  $host = strtolower(trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost')));
  if ($host === '') return 'localhost';
  if (str_contains($host, ':')) {
    $parts = explode(':', $host);
    $host = $parts[0] ?? $host;
  }
  return $host;
}

function current_scheme(): string {
  return request_is_https() ? 'https' : 'http';
}

function configured_root_domains(): array {
  $items = [];

  $env = trim((string)(getenv('APP_ROOT_DOMAINS') ?: getenv('APP_ROOT_DOMAIN') ?: ''));
  if ($env !== '') {
    $items = array_merge($items, preg_split('/[,;\s]+/', $env) ?: []);
  }

  $configFile = __DIR__ . '/root_domains.php';
  if (is_file($configFile)) {
    $fileDomains = require $configFile;
    if (is_array($fileDomains)) {
      $items = array_merge($items, $fileDomains);
    }
  }

  $out = [];
  foreach ($items as $item) {
    $item = strtolower(trim((string)$item));
    $item = preg_replace('#^https?://#', '', $item);
    $item = trim((string)$item, '/');
    if (str_starts_with($item, 'www.')) {
      $item = substr($item, 4);
    }
    if ($item !== '') $out[] = $item;
  }

  return array_values(array_unique($out));
}

function path_based_company_url(string $slug, string $path = ''): string {
  $path = ltrim($path, '/');
  $base = current_scheme() . '://' . current_host_name() . '/c/' . rawurlencode($slug) . '/';
  return $path !== '' ? $base . $path : $base;
}

function company_catalog_url(array $company, string $path = ''): string {
  $path = ltrim($path, '/');
  $slug = trim((string)($company['slug'] ?? ''));
  $customDomain = strtolower(trim((string)($company['custom_domain'] ?? '')));
  $customDomain = preg_replace('#^https?://#', '', $customDomain);
  $customDomain = trim((string)$customDomain, '/');
  $subdomain = strtolower(trim((string)($company['subdomain'] ?? '')));
  $scheme = current_scheme();

  if ($customDomain !== '') {
    return $scheme . '://' . $customDomain . '/' . $path;
  }

  foreach (configured_root_domains() as $rootDomain) {
    if ($subdomain !== '') {
      return $scheme . '://' . $subdomain . '.' . $rootDomain . '/' . $path;
    }
  }

  // Fallback: quando APP_ROOT_DOMAINS não foi configurado, usa o domínio principal
  // para evitar links path-based como /c/empresa em ambientes com subdomínio wildcard.
  $mainSite = trim((string)(getenv('NEXA_MAIN_SITE_URL') ?: ''));
  if ($subdomain !== '' && $mainSite !== '') {
    $mainHost = (string)(parse_url($mainSite, PHP_URL_HOST) ?: '');
    $mainHost = strtolower(trim($mainHost));
    if (str_starts_with($mainHost, 'www.')) $mainHost = substr($mainHost, 4);
    if ($mainHost !== '') {
      return $scheme . '://' . $subdomain . '.' . $mainHost . '/' . $path;
    }
  }

  return path_based_company_url($slug, $path);
}

function company_public_catalog_url(array $company): string {
  $slug = trim((string)($company['slug'] ?? ''));
  if ($slug === '') return current_scheme() . '://' . current_host_name() . '/catalogo_publico.php';

  // O catálogo público/visualização deve usar o mesmo domínio/subdomínio do
  // catálogo da empresa. Antes o link era montado no domínio principal
  // (ex.: https://nexaprospero.com.br/empresa), o que podia abrir uma rota
  // diferente e carregar o catálogo com layout/imagens distorcidos.
  // Exemplo esperado: https://empresa.nexaprospero.com.br/empresa
  return company_catalog_url($company, rawurlencode($slug));
}

function company_admin_url(array $company, string $path = 'admin/login.php'): string {
  return company_catalog_url($company, $path);
}

function base_url(array $app): string {
  if (!empty($app['base_url'])) return rtrim((string)$app['base_url'], '/');

  $scheme = current_scheme();
  $host = current_host_name();

  $scriptName = $_SERVER['SCRIPT_NAME'] ?? '/';
  $path = rtrim(str_replace('\\', '/', dirname($scriptName)), '/');

  return rtrim($scheme . '://' . $host . $path, '/');
}

function asset_url(string $path, array $app): string {
  $path = ltrim($path, '/');
  $slug = defined('COMPANY_SLUG') ? (string)COMPANY_SLUG : '';

  $isStatic = ($path !== '') && (
    str_starts_with($path, 'assets/')
    || str_starts_with($path, 'uploads/')
    || str_starts_with($path, 'img/')
    || str_starts_with($path, 'favicon')
  );

  if ($isStatic) {
    return base_url($app) . '/' . $path;
  }

  if ($slug !== '') {
    $company = [
      'slug' => $slug,
      'subdomain' => defined('COMPANY_SUBDOMAIN') ? (string)COMPANY_SUBDOMAIN : '',
      'custom_domain' => defined('COMPANY_CUSTOM_DOMAIN') ? (string)COMPANY_CUSTOM_DOMAIN : '',
    ];
    return company_catalog_url($company, $path);
  }

  return base_url($app) . '/' . $path;
}

function upload_url(string $filename, array $app): string {
  $base = rtrim((string)$app['uploads_url'], '/');
  return base_url($app) . '/' . $base . '/' . rawurlencode($filename);
}

function fmt_status(string $status): string {
  $map = [
    'aberto' => 'Aberto',
    'confirmado' => 'Confirmado',
    'em_producao' => 'Em produção',
    'saiu_entrega' => 'Saiu para entrega',
    'finalizado' => 'Finalizado',
    'cancelado' => 'Cancelado',
  ];
  return $map[$status] ?? $status;
}

function fmt_dt_br(?string $dt): string {
  $dt = trim((string)$dt);
  if ($dt === '') return '';
  try {
    $d = new DateTime($dt);
    if (preg_match('/\d{2}:\d{2}/', $dt)) return $d->format('d/m/Y H:i');
    return $d->format('d/m/Y');
  } catch (Throwable $e) {
    return $dt;
  }
}

function store_status(array $biz): array {
  $timezone = trim((string)($biz['timezone'] ?? 'America/Sao_Paulo'));
  if ($timezone === '') $timezone = 'America/Sao_Paulo';

  try {
    $tz = new DateTimeZone($timezone);
  } catch (Throwable $e) {
    $tz = new DateTimeZone('America/Sao_Paulo');
  }

  $now = new DateTime('now', $tz);

  $dowMap = ['sun','mon','tue','wed','thu','fri','sat'];
  $dow = $dowMap[(int)$now->format('w')];

  $hours = (array)($biz['hours'][$dow] ?? []);
  $curMin = ((int)$now->format('H')) * 60 + (int)$now->format('i');

  $open = false;
  $nextOpen = null;

  foreach ($hours as $w) {
    $ini = (string)($w[0] ?? '');
    $fim = (string)($w[1] ?? '');
    if (!preg_match('/^\d{2}:\d{2}$/', $ini) || !preg_match('/^\d{2}:\d{2}$/', $fim)) continue;

    [$ih,$im] = array_map('intval', explode(':', $ini));
    [$fh,$fm] = array_map('intval', explode(':', $fim));
    $iMin = $ih*60 + $im;
    $fMin = $fh*60 + $fm;

    if ($curMin >= $iMin && $curMin <= $fMin) { $open = true; break; }
    if ($curMin < $iMin) {
      $cand = (clone $now);
      $cand->setTime($ih,$im,0);
      if ($nextOpen === null || $cand < $nextOpen) $nextOpen = $cand;
    }
  }

  if (!$open && $nextOpen === null) {
    for ($d=1;$d<=7;$d++) {
      $day = (clone $now);
      $day->modify("+{$d} day");
      $dow2 = $dowMap[(int)$day->format('w')];
      $hours2 = (array)($biz['hours'][$dow2] ?? []);
      if (!$hours2) continue;
      $w = $hours2[0];
      $ini = (string)($w[0] ?? '');
      if (!preg_match('/^\d{2}:\d{2}$/', $ini)) continue;
      [$ih,$im] = array_map('intval', explode(':', $ini));
      $day->setTime($ih,$im,0);
      $nextOpen = $day;
      break;
    }
  }

  $nextTxt = '';
  if (!$open && $nextOpen instanceof DateTime) {
    $sameDay = $nextOpen->format('Y-m-d') === $now->format('Y-m-d');
    $nextTxt = $sameDay ? ('abre às ' . $nextOpen->format('H:i')) : ('abre ' . $nextOpen->format('d/m') . ' às ' . $nextOpen->format('H:i'));
  }

  return [
    'open' => $open,
    'label' => $open ? 'Aberto agora' : 'Fechado',
    'next' => $nextTxt,
  ];
}

function delivery_fee(array $biz, string $tipoEntrega, string $bairro): float {
  if ($tipoEntrega === 'retirada') return 0.0;

  $cfg = (array)($biz['delivery_fees'] ?? []);
  $default = (float)($cfg['default'] ?? 0);
  $bairros = (array)($cfg['bairros'] ?? []);

  $bairro = trim($bairro);
  if ($bairro === '') return $default;

  foreach ($bairros as $k => $v) {
    if (mb_strtolower((string)$k) === mb_strtolower($bairro)) return (float)$v;
  }
  return $default;
}

// Faixa "AMBIENTE DE HOMOLOGAÇÃO" (só age quando NEXA_AMBIENTE=homologacao; em produção não faz nada).
require_once __DIR__ . '/ambiente.php';
nexa_homologacao_banner_start();
