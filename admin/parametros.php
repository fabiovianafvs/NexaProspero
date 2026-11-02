<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/config_store.php';
require_once __DIR__ . '/../config/company_identity.php';
require_once __DIR__ . '/../config/firebird_runner.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/balanca_module.php';

$msg = '';
$erro = '';

function post_bool(string $k): bool {
  return !empty($_POST[$k]) && ($_POST[$k] === '1' || $_POST[$k] === 'on');
}

function clean_time(?string $t): string {
  $t = trim((string)$t);
  if ($t === '') return '';
  if (!preg_match('/^\d{2}:\d{2}$/', $t)) return '';
  return $t;
}

function params_money_in($value): float {
  $value = trim((string)$value);
  if ($value === '') return 0.0;
  $value = str_replace(['R$', ' '], '', $value);
  if (str_contains($value, ',') && str_contains($value, '.')) {
    $value = str_replace('.', '', $value);
  }
  $value = str_replace(',', '.', $value);
  return is_numeric($value) ? max(0, (float)$value) : 0.0;
}

function params_company_has_column(PDO $pdo, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute(['companies', $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    return false;
  }
}

function params_company_columns(PDO $pdo): array {
  return [
    'name' => params_company_has_column($pdo, 'name'),
    'whatsapp' => params_company_has_column($pdo, 'whatsapp'),
    'slug' => params_company_has_column($pdo, 'slug'),
    'subdomain' => params_company_has_column($pdo, 'subdomain'),
    'custom_domain' => params_company_has_column($pdo, 'custom_domain'),
  ];
}

function params_limit_text(string $value, int $max): string {
  $value = trim($value);
  if ($max <= 0) return '';
  return function_exists('mb_substr') ? mb_substr($value, 0, $max, 'UTF-8') : substr($value, 0, $max);
}


function params_hex_color($value, string $fallback): string {
  $fallback = strtoupper(trim($fallback));
  if (!preg_match('/^#[0-9A-F]{6}$/', $fallback)) $fallback = '#0F766E';

  $value = strtoupper(trim((string)$value));
  if ($value !== '' && $value[0] !== '#') $value = '#' . $value;
  return preg_match('/^#[0-9A-F]{6}$/', $value) ? $value : $fallback;
}

function params_hex_to_rgb(string $hex): array {
  $hex = params_hex_color($hex, '#0F766E');
  return [
    hexdec(substr($hex, 1, 2)),
    hexdec(substr($hex, 3, 2)),
    hexdec(substr($hex, 5, 2)),
  ];
}

function params_rgb_to_hex(int $r, int $g, int $b): string {
  $r = max(0, min(255, $r));
  $g = max(0, min(255, $g));
  $b = max(0, min(255, $b));
  return sprintf('#%02X%02X%02X', $r, $g, $b);
}

function params_mix_hex(string $hex, string $with, float $amount): string {
  [$r1, $g1, $b1] = params_hex_to_rgb($hex);
  [$r2, $g2, $b2] = params_hex_to_rgb($with);
  $amount = max(0, min(1, $amount));
  return params_rgb_to_hex(
    (int)round($r1 + (($r2 - $r1) * $amount)),
    (int)round($g1 + (($g2 - $g1) * $amount)),
    (int)round($b1 + (($b2 - $b1) * $amount))
  );
}

function params_luminance_hex(string $hex): float {
  [$r, $g, $b] = params_hex_to_rgb($hex);
  $convert = static function(int $v): float {
    $c = $v / 255;
    return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
  };
  return (0.2126 * $convert($r)) + (0.7152 * $convert($g)) + (0.0722 * $convert($b));
}

function params_read_svg_palette(string $file): ?array {
  $raw = @file_get_contents($file);
  if (!is_string($raw) || $raw === '') return null;
  preg_match_all('/#[0-9a-fA-F]{6}\b/', $raw, $m);
  $colors = $m[0] ?? [];
  if (!$colors) return null;

  $count = [];
  foreach ($colors as $c) {
    $hex = params_hex_color($c, '#0F766E');
    [$r, $g, $b] = params_hex_to_rgb($hex);
    if ($r > 238 && $g > 238 && $b > 238) continue;
    $count[$hex] = ($count[$hex] ?? 0) + 1;
  }
  if (!$count) return null;
  arsort($count);
  $primary = (string)array_key_first($count);
  $lum = params_luminance_hex($primary);
  if ($lum > .72) $primary = params_mix_hex($primary, '#0F172A', .24);
  if ($lum < .08) $primary = params_mix_hex($primary, '#FFFFFF', .20);

  return [
    'primary_color' => $primary,
    'secondary_color' => params_mix_hex($primary, '#FFFFFF', .42),
    'accent_color' => params_mix_hex($primary, params_luminance_hex($primary) < .30 ? '#FFFFFF' : '#0F172A', .18),
    'text_on_primary' => params_luminance_hex($primary) > .45 ? '#0F172A' : '#FFFFFF',
  ];
}

function params_extract_logo_palette(string $file): ?array {
  if (!is_file($file)) return null;
  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  if ($ext === 'svg') return params_read_svg_palette($file);
  if (!function_exists('imagecreatetruecolor')) return null;

  $loader = null;
  if (in_array($ext, ['jpg', 'jpeg'], true) && function_exists('imagecreatefromjpeg')) $loader = 'imagecreatefromjpeg';
  if ($ext === 'png' && function_exists('imagecreatefrompng')) $loader = 'imagecreatefrompng';
  if ($ext === 'webp' && function_exists('imagecreatefromwebp')) $loader = 'imagecreatefromwebp';
  if (!$loader) return null;

  $img = @$loader($file);
  if (!$img) return null;

  $w = imagesx($img);
  $h = imagesy($img);
  if ($w <= 0 || $h <= 0) {
    imagedestroy($img);
    return null;
  }

  $maxSide = 96;
  $scale = min(1, $maxSide / max($w, $h));
  $sw = max(1, (int)round($w * $scale));
  $sh = max(1, (int)round($h * $scale));
  $small = imagecreatetruecolor($sw, $sh);
  imagealphablending($small, false);
  imagesavealpha($small, true);
  imagecopyresampled($small, $img, 0, 0, 0, 0, $sw, $sh, $w, $h);
  imagedestroy($img);

  $buckets = [];
  $fallback = ['r' => 0, 'g' => 0, 'b' => 0, 'n' => 0];
  for ($y = 0; $y < $sh; $y++) {
    for ($x = 0; $x < $sw; $x++) {
      $rgba = imagecolorat($small, $x, $y);
      $a = ($rgba & 0x7F000000) >> 24;
      if ($a > 90) continue;
      $r = ($rgba >> 16) & 0xFF;
      $g = ($rgba >> 8) & 0xFF;
      $b = $rgba & 0xFF;
      if ($r > 242 && $g > 242 && $b > 242) continue;

      $max = max($r, $g, $b);
      $min = min($r, $g, $b);
      $sat = $max > 0 ? (($max - $min) / $max) : 0;
      $lumSimple = (($r * .299) + ($g * .587) + ($b * .114)) / 255;

      $fallback['r'] += $r;
      $fallback['g'] += $g;
      $fallback['b'] += $b;
      $fallback['n']++;

      if ($sat < .08 && $lumSimple > .45) continue;
      $qr = (int)round($r / 24) * 24;
      $qg = (int)round($g / 24) * 24;
      $qb = (int)round($b / 24) * 24;
      $qr = max(0, min(255, $qr));
      $qg = max(0, min(255, $qg));
      $qb = max(0, min(255, $qb));
      $key = $qr . ',' . $qg . ',' . $qb;
      $score = 1 + ($sat * 1.8) + (1 - abs($lumSimple - .48));
      if (!isset($buckets[$key])) $buckets[$key] = ['score' => 0.0, 'count' => 0, 'r' => $qr, 'g' => $qg, 'b' => $qb];
      $buckets[$key]['score'] += $score;
      $buckets[$key]['count']++;
    }
  }
  imagedestroy($small);

  if ($buckets) {
    uasort($buckets, static fn($a, $b) => ($b['score'] <=> $a['score']));
    $best = reset($buckets);
    $primary = params_rgb_to_hex((int)$best['r'], (int)$best['g'], (int)$best['b']);
  } elseif ($fallback['n'] > 0) {
    $primary = params_rgb_to_hex(
      (int)round($fallback['r'] / $fallback['n']),
      (int)round($fallback['g'] / $fallback['n']),
      (int)round($fallback['b'] / $fallback['n'])
    );
  } else {
    return null;
  }

  $lum = params_luminance_hex($primary);
  if ($lum > .72) $primary = params_mix_hex($primary, '#0F172A', .24);
  if ($lum < .08) $primary = params_mix_hex($primary, '#FFFFFF', .20);

  $accentTarget = params_luminance_hex($primary) < .30 ? '#FFFFFF' : '#0F172A';
  return [
    'primary_color' => $primary,
    'secondary_color' => params_mix_hex($primary, '#FFFFFF', .42),
    'accent_color' => params_mix_hex($primary, $accentTarget, .18),
    'text_on_primary' => params_luminance_hex($primary) > .45 ? '#0F172A' : '#FFFFFF',
  ];
}

// Garante compatibilidade com bases que ainda não receberam a migração v32.
try {
  if (!params_company_has_column($pdo, 'subdomain')) {
    $pdo->exec('ALTER TABLE companies ADD COLUMN subdomain VARCHAR(100) NULL AFTER slug');
  }
  if (!params_company_has_column($pdo, 'custom_domain')) {
    $pdo->exec('ALTER TABLE companies ADD COLUMN custom_domain VARCHAR(190) NULL AFTER subdomain');
  }
} catch (Throwable $e) {
  // Sem permissão de ALTER: não deve derrubar a página.
}

$companyCols = params_company_columns($pdo);
$biz = load_business_config(COMPANY_ID);
$hasExportacaoModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, (int)COMPANY_ID, 'exportacao') : true;
$hasComandasModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, (int)COMPANY_ID, 'comandas') : false;
$hasGestaoModule = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, (int)COMPANY_ID, 'gestao') : true;
$hasScaleModule = $hasGestaoModule || $hasComandasModule;
$scaleCfg = balanca_config($pdo, (int)COMPANY_ID);
if ($hasScaleModule) {
  try { balanca_ensure_schema($pdo); } catch (Throwable $e) {}
  try { comanda_ensure_schema($pdo); } catch (Throwable $e) {}
}
$fb  = $hasExportacaoModule ? load_firebird_config(COMPANY_ID) : [];
$comandaSettings = $hasComandasModule ? comanda_settings($pdo, (int)COMPANY_ID) : [];
$comandaPrinterGroups = $hasComandasModule ? comanda_printer_groups($pdo, (int)COMPANY_ID, false) : [];
$stCompany = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
$stCompany->execute([COMPANY_ID]);
$company = $stCompany->fetch(PDO::FETCH_ASSOC) ?: ['id'=>COMPANY_ID,'name'=>''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $section = (string)($_POST['section'] ?? '');
  $action = (string)($_POST['action'] ?? 'save');

  if ($section === 'business') {
    // horários
    $hours = [];
    foreach (['sun','mon','tue','wed','thu','fri','sat'] as $d) {
      $a1 = clean_time($_POST["{$d}_open1"] ?? '');
      $b1 = clean_time($_POST["{$d}_close1"] ?? '');
      $a2 = clean_time($_POST["{$d}_open2"] ?? '');
      $b2 = clean_time($_POST["{$d}_close2"] ?? '');
      $hours[$d] = [];
      if ($a1 !== '' && $b1 !== '') $hours[$d][] = [$a1,$b1];
      if ($a2 !== '' && $b2 !== '') $hours[$d][] = [$a2,$b2];
    }

    // bairros
    $bairros = [];
    $bn = $_POST['bairros_nome'] ?? null;
    $bf = $_POST['bairros_taxa'] ?? null;
    if (is_array($bn) && is_array($bf)) {
      $n = min(count($bn), count($bf));
      for ($i=0; $i<$n; $i++) {
        $name = trim((string)$bn[$i]);
        $fee  = trim((string)$bf[$i]);
        if ($name === '') continue;
        $feeValue = params_money_in($fee);
        $bairros[$name] = $feeValue;
      }
    }

    $primaryColor = params_hex_color($_POST['brand_primary_color'] ?? ($biz['brand']['primary_color'] ?? '#0f766e'), '#0F766E');
    $secondaryColor = params_hex_color($_POST['brand_secondary_color'] ?? ($biz['brand']['secondary_color'] ?? '#14b8a6'), '#14B8A6');
    $accentColor = params_hex_color($_POST['brand_accent_color'] ?? ($biz['brand']['accent_color'] ?? '#f59e0b'), '#F59E0B');
    $textOnPrimary = params_hex_color($_POST['brand_text_on_primary'] ?? ($biz['brand']['text_on_primary'] ?? '#ffffff'), '#FFFFFF');
    $followLogoColors = post_bool('brand_follow_logo_colors');

    $adminThemeEnabled = post_bool('admin_theme_enabled');
    $adminPrimaryColor = params_hex_color($_POST['admin_primary_color'] ?? ($biz['admin_theme']['primary_color'] ?? '#0B1F5B'), '#0B1F5B');
    $adminSecondaryColor = params_hex_color($_POST['admin_secondary_color'] ?? ($biz['admin_theme']['secondary_color'] ?? '#2454D6'), '#2454D6');
    $adminAccentColor = params_hex_color($_POST['admin_accent_color'] ?? ($biz['admin_theme']['accent_color'] ?? '#00B8FF'), '#00B8FF');
    $adminGradientColor = params_hex_color($_POST['admin_gradient_color'] ?? ($biz['admin_theme']['gradient_color'] ?? '#06122E'), '#06122E');
    $adminTextColor = params_hex_color($_POST['admin_text_color'] ?? ($biz['admin_theme']['text_color'] ?? '#FFFFFF'), '#FFFFFF');

    $adminFlowThemeEnabled = post_bool('admin_flow_theme_enabled');
    $adminFlowPrimaryColor = params_hex_color($_POST['admin_flow_primary_color'] ?? ($biz['admin_flow_theme']['primary_color'] ?? '#143A9C'), '#143A9C');
    $adminFlowSecondaryColor = params_hex_color($_POST['admin_flow_secondary_color'] ?? ($biz['admin_flow_theme']['secondary_color'] ?? '#2563EB'), '#2563EB');
    $adminFlowTextColor = params_hex_color($_POST['admin_flow_text_color'] ?? ($biz['admin_flow_theme']['text_color'] ?? '#FFFFFF'), '#FFFFFF');

    foreach (['primaryColor','secondaryColor','accentColor','textOnPrimary','adminPrimaryColor','adminSecondaryColor','adminAccentColor','adminGradientColor','adminTextColor','adminFlowPrimaryColor','adminFlowSecondaryColor','adminFlowTextColor'] as $varName) {
      if (!preg_match('/^#[0-9A-F]{6}$/', $$varName)) {
        $erro = 'Use cores no formato hexadecimal. Ex.: #0F766E';
      }
    }

    $catalogTitle = params_limit_text((string)($_POST['catalog_title'] ?? ($company['name'] ?? $app['site_name'] ?? '')), 150);
    $catalogSubtitle = params_limit_text((string)($_POST['catalog_subtitle'] ?? ($biz['brand']['catalog_subtitle'] ?? '')), 250);
    $subdomain = company_subdomain_normalize((string)($_POST['catalog_subdomain'] ?? ($biz['domain']['subdomain'] ?? ($company['subdomain'] ?? ''))));
    $subdomainValidation = company_subdomain_validate($subdomain);
    if (!$subdomainValidation['valid']) $erro = $subdomainValidation['message'];
    $customDomain = strtolower(trim((string)($_POST['custom_domain'] ?? ($biz['domain']['custom_domain'] ?? ($company['custom_domain'] ?? '')))));
    $customDomain = preg_replace('/^https?:\/\//', '', $customDomain);
    $customDomain = params_limit_text(trim($customDomain, '/ '), 190);

    $orderTypesCheckout = [
      'entrega' => post_bool('order_type_checkout_entrega'),
      'retirada' => post_bool('order_type_checkout_retirada'),
    ];
    if (!$orderTypesCheckout['entrega'] && !$orderTypesCheckout['retirada']) {
      $orderTypesCheckout['retirada'] = true;
    }

    $orderTypesPdv = [
      'balcao' => post_bool('order_type_pdv_balcao'),
      'retirada' => post_bool('order_type_pdv_retirada'),
      'entrega' => post_bool('order_type_pdv_entrega'),
    ];
    if (!$orderTypesPdv['balcao'] && !$orderTypesPdv['retirada'] && !$orderTypesPdv['entrega']) {
      $orderTypesPdv['balcao'] = true;
    }

    $orderTypes = [
      'checkout' => $orderTypesCheckout,
      'pdv' => $orderTypesPdv,
      // Compatibilidade com telas antigas que ainda leem as chaves simples.
      'entrega' => $orderTypesCheckout['entrega'] || $orderTypesPdv['entrega'],
      'retirada' => $orderTypesCheckout['retirada'] || $orderTypesPdv['retirada'],
      'balcao' => $orderTypesPdv['balcao'],
    ];

    $deliveryDefault = params_money_in($_POST['delivery_default'] ?? ($biz['delivery_fees']['default'] ?? 0));
    $checkoutCustomerNameLabel = params_limit_text((string)($_POST['checkout_customer_name_label'] ?? ($biz['checkout']['customer_name_label'] ?? 'Nome/Razão social')), 80);
    if ($checkoutCustomerNameLabel === '') $checkoutCustomerNameLabel = 'Nome/Razão social';
    $checkoutCustomerNamePlaceholder = params_limit_text((string)($_POST['checkout_customer_name_placeholder'] ?? ($biz['checkout']['customer_name_placeholder'] ?? 'Nome completo ou razão social')), 120);
    if ($checkoutCustomerNamePlaceholder === '') $checkoutCustomerNamePlaceholder = $checkoutCustomerNameLabel;

    $productOptionsCfg = (array)($biz['product_options'] ?? []);
    $xmlPricingPolicy = strtolower(trim((string)($_POST['fiscal_xml_pricing_policy'] ?? ($biz['fiscal_xml_entry']['pricing_policy'] ?? 'keep_margin'))));
    if (!in_array($xmlPricingPolicy, ['keep_margin', 'keep_price'], true)) $xmlPricingPolicy = 'keep_margin';
    $productOptionsItemNoteInstruction = params_limit_text(
      (string)($_POST['product_options_item_note_instruction'] ?? ($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...')),
      220
    );

    $scaleCfgPost = $hasScaleModule ? balanca_sanitize_config([
      'enabled' => post_bool('scale_enabled'),
      'model' => trim((string)($_POST['scale_model'] ?? 'prix_3_fit')),
      'connection' => trim((string)($_POST['scale_connection'] ?? 'webserial')),
      'protocol' => trim((string)($_POST['scale_protocol'] ?? 'auto')),
      'request_mode' => trim((string)($_POST['scale_request_mode'] ?? 'enq')),
      'baud_rate' => (int)($_POST['scale_baud_rate'] ?? 2400),
      'data_bits' => (int)($_POST['scale_data_bits'] ?? 8),
      'parity' => trim((string)($_POST['scale_parity'] ?? 'none')),
      'stop_bits' => (int)($_POST['scale_stop_bits'] ?? 1),
      'decimal_places' => (int)($_POST['scale_decimal_places'] ?? 3),
      'stable_only' => post_bool('scale_stable_only'),
      'timeout_ms' => (int)($_POST['scale_timeout_ms'] ?? 2500),
      'min_weight' => str_replace(',', '.', (string)($_POST['scale_min_weight'] ?? '0.001')),
      'auto_fill_quantity' => post_bool('scale_auto_fill_quantity'),
      'port_hint' => params_limit_text((string)($_POST['scale_port_hint'] ?? ''), 120),
    ]) : $scaleCfg;

    $over = [
      'timezone' => trim((string)($_POST['timezone'] ?? ($biz['timezone'] ?? 'America/Sao_Paulo'))),
      'whatsapp_store' => substr(preg_replace('/\D+/', '', (string)($_POST['whatsapp_store'] ?? ($biz['whatsapp_store'] ?? ''))), 0, 30),
      'whatsapp_auto_open' => post_bool('whatsapp_auto_open'),
      'whatsapp_pdv_auto_send' => post_bool('whatsapp_pdv_auto_send'),
      'delivery_alerts' => [
        'new_order_enabled' => post_bool('delivery_new_order_alert_enabled'),
      ],
      'logo' => (array)($biz['logo'] ?? []),
      'pdv_checkout' => [
        'logo' => (array)($biz['pdv_checkout']['logo'] ?? []),
      ],
      'cover' => (array)($biz['cover'] ?? []),
      'brand' => [
        'catalog_title' => $catalogTitle,
        'catalog_subtitle' => $catalogSubtitle,
        'primary_color' => $primaryColor,
        'secondary_color' => $secondaryColor,
        'accent_color' => $accentColor,
        'text_on_primary' => $textOnPrimary,
        'follow_logo_colors' => $followLogoColors,
      ],
      'domain' => [
        'subdomain' => $subdomain,
        'custom_domain' => $customDomain,
      ],
      'catalog_ui' => [
        'horizontal_category_menu' => post_bool('catalog_horizontal_category_menu'),
      ],
      'admin_theme' => [
        'enabled' => $adminThemeEnabled,
        'primary_color' => $adminPrimaryColor,
        'secondary_color' => $adminSecondaryColor,
        'accent_color' => $adminAccentColor,
        'gradient_color' => $adminGradientColor,
        'text_color' => $adminTextColor,
      ],
      'admin_flow_theme' => [
        'enabled' => $adminFlowThemeEnabled,
        'primary_color' => $adminFlowPrimaryColor,
        'secondary_color' => $adminFlowSecondaryColor,
        'text_color' => $adminFlowTextColor,
      ],
      'admin_ui' => [
        'side_menu_enabled' => post_bool('admin_side_menu_enabled'),
      ],
      'hours' => $hours,
      'delivery_scheduling' => [
        'enabled' => post_bool('delivery_scheduling_enabled'),
        'label' => trim((string)($_POST['delivery_scheduling_label'] ?? ($biz['delivery_scheduling']['label'] ?? 'Data/Hora Prevista'))),
        'required' => post_bool('delivery_scheduling_required'),
        'only_business_hours' => post_bool('delivery_scheduling_only_business_hours'),
        'min_minutes' => max(0, (int)($_POST['delivery_scheduling_min_minutes'] ?? ($biz['delivery_scheduling']['min_minutes'] ?? 0))),
      ],
      'checkout' => [
        'customer_name_label' => $checkoutCustomerNameLabel,
        'customer_name_placeholder' => $checkoutCustomerNamePlaceholder,
      ],
      'event_sales' => [
        'enabled' => post_bool('event_sales_enabled'),
      ],
      'fiscal_xml_entry' => [
        'pricing_policy' => $xmlPricingPolicy,
      ],
      'product_options' => [
        'show_additional_unit_total' => post_bool('product_options_show_additional_unit_total'),
        'show_item_total_with_additionals' => post_bool('product_options_show_item_total_with_additionals'),
        'item_note_instruction' => $productOptionsItemNoteInstruction,
      ],
      'orders_grid' => [
        // Piloto por empresa, desligado por padrão.
        'enabled' => post_bool('orders_grid_enabled'),
      ],
      'order_edit' => [
        // V11.52.11: nova visualização avançada do Editar Pedido fica opt-in por empresa.
        // Clientes atuais preservam a tela já homologada até ativarem explicitamente.
        'advanced_enabled' => post_bool('order_edit_advanced_enabled'),
      ],
      'scale' => $scaleCfgPost,
      'order_types' => $orderTypes,
      'order_reports' => [
        'enabled' => post_bool('order_reports_enabled'),
      ],
      'delivery_fees' => [
        'default' => $deliveryDefault,
        'bairros' => $bairros,
      ],
      'banner' => [
        'enabled' => post_bool('banner_enabled'),
        'title' => trim((string)($_POST['banner_title'] ?? '')),
        'subtitle' => trim((string)($_POST['banner_subtitle'] ?? '')),
        'link_text' => trim((string)($_POST['banner_link_text'] ?? '')),
        'link_url' => trim((string)($_POST['banner_link_url'] ?? '')),
      ],
    ];

    if ($catalogTitle === '') {
      $erro = 'Informe o nome do catálogo.';
    }

    if (!empty($_POST['logo_remove'])) {
      $oldLogo = trim((string)($over['logo']['file'] ?? ''));
      if ($oldLogo !== '') {
        $oldPath = __DIR__ . '/../uploads/logos/' . basename($oldLogo);
        if (is_file($oldPath)) @unlink($oldPath);
      }
      $over['logo'] = ['file' => '', 'enabled' => false];
    }

    if (!empty($_POST['pdv_checkout_logo_remove'])) {
      $oldPdvLogo = trim((string)($over['pdv_checkout']['logo']['file'] ?? ''));
      if ($oldPdvLogo !== '') {
        $oldPath = __DIR__ . '/../uploads/logos/' . basename($oldPdvLogo);
        if (is_file($oldPath)) @unlink($oldPath);
      }
      $over['pdv_checkout']['logo'] = ['file' => '', 'enabled' => false];
    }

    if (!empty($_POST['cover_remove'])) {
      $oldCover = trim((string)($over['cover']['file'] ?? ''));
      if ($oldCover !== '') {
        $oldPath = __DIR__ . '/../uploads/covers/' . basename($oldCover);
        if (is_file($oldPath)) @unlink($oldPath);
      }
      $over['cover'] = ['file' => '', 'enabled' => false];
    }

    if (!empty($_FILES['cover']['name'])) {
      $tmp = $_FILES['cover']['tmp_name'] ?? '';
      $errUp = (int)($_FILES['cover']['error'] ?? 0);
      if ($errUp === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
        $ext = strtolower(pathinfo((string)$_FILES['cover']['name'], PATHINFO_EXTENSION));
        $permitidas = ['jpg','jpeg','png','webp'];
        if (!in_array($ext, $permitidas, true)) {
          $erro = 'Capa inválida. Use JPG, PNG ou WEBP.';
        } else {
          $dir = __DIR__ . '/../uploads/covers/';
          if (!is_dir($dir)) @mkdir($dir, 0755, true);
          $baseName = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower($catalogTitle));
          $baseName = trim((string)$baseName, '-');
          if ($baseName === '') $baseName = 'capa';
          $coverFile = $baseName . '-cover-' . COMPANY_ID . '-' . time() . '.' . $ext;
          if (!move_uploaded_file($tmp, $dir . $coverFile)) {
            $erro = 'Falha ao salvar a capa.';
          } else {
            $oldCover = trim((string)($over['cover']['file'] ?? ''));
            if ($oldCover !== '' && $oldCover !== $coverFile) {
              $oldPath = $dir . basename($oldCover);
              if (is_file($oldPath)) @unlink($oldPath);
            }
            $over['cover'] = ['file' => $coverFile, 'enabled' => true];
          }
        }
      }
    }

    if (!empty($_FILES['logo']['name'])) {
      $tmp = $_FILES['logo']['tmp_name'] ?? '';
      $errUp = (int)($_FILES['logo']['error'] ?? 0);
      if ($errUp === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
        $ext = strtolower(pathinfo((string)$_FILES['logo']['name'], PATHINFO_EXTENSION));
        $permitidas = ['jpg','jpeg','png','webp','svg'];
        if (!in_array($ext, $permitidas, true)) {
          $erro = 'Logo inválida. Use JPG, PNG, WEBP ou SVG.';
        } else {
          $dirLogo = __DIR__ . '/../uploads/logos/';
          if (!is_dir($dirLogo)) @mkdir($dirLogo, 0755, true);
          $baseLogo = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower((string)($app['site_name'] ?? 'logo')));
          $baseLogo = trim((string)$baseLogo, '-');
          if ($baseLogo === '') $baseLogo = 'logo';
          $logoFile = $baseLogo . '-' . COMPANY_ID . '-' . time() . '.' . $ext;
          if (!move_uploaded_file($tmp, $dirLogo . $logoFile)) {
            $erro = 'Falha ao salvar a logo.';
          } else {
            $oldLogo = trim((string)($over['logo']['file'] ?? ''));
            if ($oldLogo !== '' && $oldLogo !== $logoFile) {
              $oldPath = $dirLogo . basename($oldLogo);
              if (is_file($oldPath)) @unlink($oldPath);
            }
            $over['logo'] = ['file' => $logoFile, 'enabled' => true];
          }
        }
      }
    }

    if (!empty($_FILES['pdv_checkout_logo']['name'])) {
      $tmp = $_FILES['pdv_checkout_logo']['tmp_name'] ?? '';
      $errUp = (int)($_FILES['pdv_checkout_logo']['error'] ?? 0);
      if ($errUp === UPLOAD_ERR_OK && is_uploaded_file($tmp)) {
        $ext = strtolower(pathinfo((string)$_FILES['pdv_checkout_logo']['name'], PATHINFO_EXTENSION));
        $permitidas = ['jpg','jpeg','png','webp','svg'];
        if (!in_array($ext, $permitidas, true)) {
          $erro = 'Logo do PDV Checkout inválida. Use JPG, PNG, WEBP ou SVG.';
        } else {
          $dirLogo = __DIR__ . '/../uploads/logos/';
          if (!is_dir($dirLogo)) @mkdir($dirLogo, 0755, true);
          $baseLogo = preg_replace('/[^a-zA-Z0-9_-]/', '-', strtolower((string)($app['site_name'] ?? 'logo')));
          $baseLogo = trim((string)$baseLogo, '-');
          if ($baseLogo === '') $baseLogo = 'logo';
          $logoFile = $baseLogo . '-pdv-checkout-' . COMPANY_ID . '-' . time() . '.' . $ext;
          if (!move_uploaded_file($tmp, $dirLogo . $logoFile)) {
            $erro = 'Falha ao salvar a logo do PDV Checkout.';
          } else {
            $oldPdvLogo = trim((string)($over['pdv_checkout']['logo']['file'] ?? ''));
            if ($oldPdvLogo !== '' && $oldPdvLogo !== $logoFile) {
              $oldPath = $dirLogo . basename($oldPdvLogo);
              if (is_file($oldPath)) @unlink($oldPath);
            }
            $over['pdv_checkout']['logo'] = ['file' => $logoFile, 'enabled' => true];
          }
        }
      }
    }

    if ($erro === '' && $followLogoColors) {
      $paletteLogoFile = trim((string)($over['logo']['file'] ?? ''));
      if ($paletteLogoFile !== '') {
        $palette = params_extract_logo_palette(__DIR__ . '/../uploads/logos/' . basename($paletteLogoFile));
        if (is_array($palette)) {
          $over['brand']['primary_color'] = $palette['primary_color'];
          $over['brand']['secondary_color'] = $palette['secondary_color'];
          $over['brand']['accent_color'] = $palette['accent_color'];
          $over['brand']['text_on_primary'] = $palette['text_on_primary'];
          $primaryColor = $palette['primary_color'];
          $secondaryColor = $palette['secondary_color'];
          $accentColor = $palette['accent_color'];
          $textOnPrimary = $palette['text_on_primary'];
        }
      }
    }

    if ($erro === '' && $subdomain !== '') {
      try {
        $availability = company_subdomain_availability($pdo, $subdomain, COMPANY_ID);
        if (!$availability['available']) $erro = $availability['message'];
        else $subdomain = (string)$availability['subdomain'];
      } catch (Throwable $e) {
        error_log('parametros.php duplicate subdomain check: ' . $e->getMessage());
        $erro = 'Não foi possível validar o subdomínio agora.';
      }
    }

    if ($erro === '' && $customDomain !== '' && !empty($companyCols['custom_domain'])) {
      try {
        $checkDomain = $pdo->prepare('SELECT id FROM companies WHERE LOWER(custom_domain) = ? AND id <> ? LIMIT 1');
        $checkDomain->execute([strtolower($customDomain), COMPANY_ID]);
        if ($checkDomain->fetchColumn()) {
          $erro = 'Este domínio personalizado já está em uso.';
        }
      } catch (Throwable $e) {
        error_log('parametros.php duplicate custom domain check: ' . $e->getMessage());
      }
    }

    if ($erro === '') {
      try {
        config_store_set($pdo, COMPANY_ID, 'business', $over);
        if (function_exists('business_save_delivery_fees_to_db')) {
          business_save_delivery_fees_to_db($pdo, COMPANY_ID, (float)$over['delivery_fees']['default'], (array)$over['delivery_fees']['bairros']);
        }

        $sets = [];
        $vals = [];
        if (!empty($companyCols['name'])) { $sets[] = 'name = ?'; $vals[] = $catalogTitle; }
        if (!empty($companyCols['whatsapp'])) { $sets[] = 'whatsapp = ?'; $vals[] = (string)($over['whatsapp_store'] ?? ''); }
        if (!empty($companyCols['slug'])) { $sets[] = 'slug = ?'; $vals[] = $subdomain !== '' ? $subdomain : COMPANY_SLUG; }
        if (!empty($companyCols['subdomain'])) { $sets[] = 'subdomain = ?'; $vals[] = $subdomain !== '' ? $subdomain : COMPANY_SLUG; }
        if (!empty($companyCols['custom_domain'])) {
          $sets[] = 'custom_domain = ?';
          // Campo com índice UNIQUE: quando estiver vazio, grava NULL para não conflitar com outros catálogos.
          $vals[] = $customDomain !== '' ? $customDomain : null;
        }

        if ($sets) {
          $vals[] = COMPANY_ID;
          $stUpd = $pdo->prepare('UPDATE companies SET ' . implode(', ', $sets) . ' WHERE id = ?');
          $stUpd->execute($vals);
        }


        if ($hasComandasModule) {
          $pdo->prepare('UPDATE comanda_settings SET show_only_occupied=?, allow_transfer=?, allow_multi_transfer=?, allow_item_transfer=?, allow_named_comanda=?, prompt_name_first_launch=?, allow_customer=?, auto_print_kitchen=?, direct_print_kitchen=?, inactive_alert_minutes=?, public_qr_enabled=?, public_qr_require_name=?, public_qr_require_phone=?, updated_at=NOW() WHERE company_id=?')->execute([
            post_bool('cmd_show_only_occupied') ? 1 : 0,
            post_bool('cmd_allow_transfer') ? 1 : 0,
            post_bool('cmd_allow_multi_transfer') ? 1 : 0,
            post_bool('cmd_allow_item_transfer') ? 1 : 0,
            post_bool('cmd_allow_named_comanda') ? 1 : 0,
            post_bool('cmd_prompt_name_first_launch') ? 1 : 0,
            post_bool('cmd_allow_customer') ? 1 : 0,
            post_bool('cmd_auto_print_kitchen') ? 1 : 0,
            post_bool('cmd_direct_print_kitchen') ? 1 : 0,
            max(0, min(1440, (int)($_POST['cmd_inactive_alert_minutes'] ?? 10))),
            post_bool('cmd_public_qr_enabled') ? 1 : 0,
            post_bool('cmd_public_qr_require_name') ? 1 : 0,
            post_bool('cmd_public_qr_require_phone') ? 1 : 0,
            COMPANY_ID,
          ]);

          $pgIds = (array)($_POST['cmd_pg_id'] ?? []);
          $pgNames = (array)($_POST['cmd_pg_name'] ?? []);
          $pgPrinters = (array)($_POST['cmd_pg_printer'] ?? []);
          $pgOrders = (array)($_POST['cmd_pg_order'] ?? []);
          $pgActive = (array)($_POST['cmd_pg_active'] ?? []);
          $updPg = $pdo->prepare("UPDATE comanda_printer_groups SET name=?, printer_name=NULLIF(?, ''), sort_order=?, is_active=?, updated_at=NOW() WHERE company_id=? AND id=?");
          foreach ($pgIds as $idx => $idRaw) {
            $pgId = (int)$idRaw;
            if ($pgId <= 0) continue;
            $pgName = trim((string)($pgNames[$idx] ?? ''));
            if ($pgName === '') continue;
            $updPg->execute([$pgName, trim((string)($pgPrinters[$idx] ?? '')), (int)($pgOrders[$idx] ?? 0), !empty($pgActive[$pgId]) ? 1 : 0, COMPANY_ID, $pgId]);
          }
          $newGroupName = trim((string)($_POST['cmd_new_group_name'] ?? ''));
          if ($newGroupName !== '') {
            $pdo->prepare("INSERT INTO comanda_printer_groups (company_id, name, printer_name, sort_order, is_active) VALUES (?, ?, NULLIF(?, ''), ?, 1) ON DUPLICATE KEY UPDATE printer_name=VALUES(printer_name), sort_order=VALUES(sort_order), is_active=1, updated_at=NOW()")
              ->execute([COMPANY_ID, $newGroupName, trim((string)($_POST['cmd_new_group_printer'] ?? '')), (int)($_POST['cmd_new_group_order'] ?? 99)]);
          }
        }
      } catch (Throwable $e) {
        error_log('parametros.php save business: ' . $e->getMessage());
        $erro = 'Não foi possível salvar as configurações. Verifique se a base está atualizada e tente novamente.';
      }
    }

    // Firebird / Próspero: esta parte pertence ao módulo Exportação / Firebird.
    if ($hasExportacaoModule) {
    $fb_over = [
      'enabled'         => post_bool('fb_enabled'),
      'auto_export_on_order' => post_bool('fb_auto_export_on_order'),
      'host'            => trim((string)($_POST['fb_host'] ?? '')),
      'port'            => trim((string)($_POST['fb_port'] ?? '')),
      'database'        => trim((string)($_POST['fb_database'] ?? '')),
      'user'            => trim((string)($_POST['fb_user'] ?? '')),
      'pass'            => (string)($_POST['fb_password'] ?? ''),
      'charset'         => trim((string)($_POST['fb_charset'] ?? '')),
      'empresa_codigo'  => (int)($_POST['fb_empresa_codigo'] ?? 0),
      'cliente_padrao'  => (int)($_POST['fb_cliente_padrao'] ?? 0),
      'tables'          => [
        'orders' => trim((string)($_POST['fb_table_orders'] ?? '')),
        'items'  => trim((string)($_POST['fb_table_items'] ?? '')),
      ],
      'fields' => [
        'orders' => [
          'empresa' => trim((string)($_POST['fb_orders_empresa'] ?? '')),
          'codigo'  => trim((string)($_POST['fb_orders_codigo'] ?? '')),
          'status'  => trim((string)($_POST['fb_orders_status'] ?? '')),
          'cliente' => trim((string)($_POST['fb_orders_cliente'] ?? '')),
          'data'    => trim((string)($_POST['fb_orders_data'] ?? '')),
          'frete'   => trim((string)($_POST['fb_orders_frete'] ?? '')),
          'total'   => trim((string)($_POST['fb_orders_total'] ?? '')),
          'obs'      => trim((string)($_POST['fb_orders_obs'] ?? '')),
          'tipo'     => trim((string)($_POST['fb_orders_tipo'] ?? '')),
          'operacao' => trim((string)($_POST['fb_orders_operacao'] ?? '')),
          'vendedor' => trim((string)($_POST['fb_orders_vendedor'] ?? '')),
          'vlr_produtos' => trim((string)($_POST['fb_orders_vlr_produtos'] ?? '')),
          'situacao' => trim((string)($_POST['fb_orders_situacao'] ?? '')),
          'plano_contas' => trim((string)($_POST['fb_orders_plano_contas'] ?? '')),
          'transportadora' => trim((string)($_POST['fb_orders_transportadora'] ?? '')),
          'cfop' => trim((string)($_POST['fb_orders_cfop'] ?? '')),
          'data_entrega' => trim((string)($_POST['fb_orders_data_entrega'] ?? '')),
          'usuario_cadastro' => trim((string)($_POST['fb_orders_usuario_cadastro'] ?? '')),
          'centro_custos' => trim((string)($_POST['fb_orders_centro_custos'] ?? '')),
          'status_id' => trim((string)($_POST['fb_orders_status_id'] ?? '')),
        ],
        'items' => [
          'idx'               => trim((string)($_POST['fb_items_idx'] ?? '')),
          'codigo_orcamento'  => trim((string)($_POST['fb_items_codigo_orcamento'] ?? '')),
          'empresa'           => trim((string)($_POST['fb_items_empresa'] ?? '')),
          'cliente'           => trim((string)($_POST['fb_items_cliente'] ?? '')),
          'referencia'        => trim((string)($_POST['fb_items_referencia'] ?? '')),
          'descricao'         => trim((string)($_POST['fb_items_descricao'] ?? '')),
          'qtde'              => trim((string)($_POST['fb_items_qtde'] ?? '')),
          'vlr_unit'          => trim((string)($_POST['fb_items_vlr_unit'] ?? '')),
          'vlr_total'         => trim((string)($_POST['fb_items_vlr_total'] ?? '')),
          'frete_rateio'      => trim((string)($_POST['fb_items_frete_rateio'] ?? '')),
          'total_com_rateios' => trim((string)($_POST['fb_items_total_com_rateios'] ?? '')),
          'und'               => trim((string)($_POST['fb_items_und'] ?? '')),
          'tipo'              => trim((string)($_POST['fb_items_tipo'] ?? '')),
          'data'              => trim((string)($_POST['fb_items_data'] ?? '')),
          'data_vendedor'     => trim((string)($_POST['fb_items_data_vendedor'] ?? '')),
          'codprod'           => trim((string)($_POST['fb_items_codprod'] ?? '')),
          'prc_venda'         => trim((string)($_POST['fb_items_prc_venda'] ?? '')),
          'transportadora'     => trim((string)($_POST['fb_items_transportadora'] ?? '')),
          'cfop'               => trim((string)($_POST['fb_items_cfop'] ?? '')),
        ],
      ],
      'defaults' => [
        'orders' => [
          'tipo'     => trim((string)($_POST['fb_default_order_tipo'] ?? '')),
          'operacao' => (int)($_POST['fb_default_order_operacao'] ?? 7),
          'vendedor' => (int)($_POST['fb_default_order_vendedor'] ?? 2),
          'situacao' => (int)($_POST['fb_default_order_situacao'] ?? 1),
          'plano_contas' => trim((string)($_POST['fb_default_order_plano_contas'] ?? '1.1')),
          'transportadora' => (int)($_POST['fb_default_order_transportadora'] ?? 3),
          'cfop' => trim((string)($_POST['fb_default_order_cfop'] ?? '5102')),
          'usuario_cadastro' => (int)($_POST['fb_default_order_usuario_cadastro'] ?? 1),
          'centro_custos' => trim((string)($_POST['fb_default_order_centro_custos'] ?? '1')),
          'status_id' => (int)($_POST['fb_default_order_status_id'] ?? 1),
        ],
        'items' => [
          'und'  => trim((string)($_POST['fb_default_und'] ?? '')),
          'tipo' => trim((string)($_POST['fb_default_tipo'] ?? '')),
          'transportadora' => (int)($_POST['fb_default_transportadora'] ?? 3),
          'cfop' => trim((string)($_POST['fb_default_cfop'] ?? '5102')),
        ],
      ],
    ];
    foreach (['host','port','database','user','pass','charset'] as $k) {
      if ($fb_over[$k] === '') unset($fb_over[$k]);
    }
    foreach (['empresa_codigo','cliente_padrao'] as $k) {
      if (($fb_over[$k] ?? 0) <= 0) unset($fb_over[$k]);
    }
    foreach (['orders','items'] as $grp) {
      foreach (($fb_over['fields'][$grp] ?? []) as $k => $v) {
        if ($v === '') unset($fb_over['fields'][$grp][$k]);
      }
      if (empty($fb_over['fields'][$grp])) unset($fb_over['fields'][$grp]);
    }
    if (empty($fb_over['fields'])) unset($fb_over['fields']);
    foreach (['orders','items'] as $k) {
      if (($fb_over['tables'][$k] ?? '') === '') unset($fb_over['tables'][$k]);
    }
    if (empty($fb_over['tables'])) unset($fb_over['tables']);
    foreach (['orders','items'] as $grpDef) {
      foreach (($fb_over['defaults'][$grpDef] ?? []) as $k => $v) {
        if ($v === '') unset($fb_over['defaults'][$grpDef][$k]);
      }
      if (empty($fb_over['defaults'][$grpDef])) unset($fb_over['defaults'][$grpDef]);
    }
    if (empty($fb_over['defaults'])) unset($fb_over['defaults']);
    if ($erro === '') {
      try {
        config_store_set($pdo, COMPANY_ID, 'firebird', $fb_over);
      } catch (Throwable $e) {
        error_log('parametros.php save firebird: ' . $e->getMessage());
        $erro = 'Não foi possível salvar as configurações de integração. Verifique se a base está atualizada e tente novamente.';
      }
    }

    if ($erro === '') {
      $biz = load_business_config(COMPANY_ID);
      $scaleCfg = balanca_config($pdo, (int)COMPANY_ID);
      $fb  = load_firebird_config(COMPANY_ID);
      $stCompany->execute([COMPANY_ID]);
      $company = $stCompany->fetch(PDO::FETCH_ASSOC) ?: $company;
      if ($hasComandasModule) {
        $comandaSettings = comanda_settings($pdo, (int)COMPANY_ID);
        $comandaPrinterGroups = comanda_printer_groups($pdo, (int)COMPANY_ID, false);
      }

      if ($action === 'fb_test') {
        $test = firebird_test_connection(COMPANY_ID);
        if (!empty($test['ok'])) {
          $msg = (string)($test['message'] ?? 'Conexão com Firebird OK.');
        } else {
          $erro = 'Falha ao testar Firebird: ' . (string)($test['error'] ?? 'erro desconhecido');
        }
      } elseif ($action === 'fb_export_now') {
        $resFb = firebird_export_pending($pdo, COMPANY_ID, null, 20);
        $logFb = (array)($resFb['log'] ?? []);
        if (!empty($resFb['error'])) {
          $erro = 'Falha na exportação Firebird: ' . (string)$resFb['error'];
        } else {
          $msg = 'Exportação Firebird executada. ' . ($logFb ? implode(' | ', array_slice(array_map('strval', $logFb), 0, 3)) : 'Sem mensagens.');
        }
      } else {
        $msg = 'Configurações atualizadas.';
      }
    }
    } elseif ($erro === '' && in_array($action, ['fb_test', 'fb_export_now'], true)) {
      $erro = 'A integração Firebird depende da liberação do módulo Exportação / Firebird.';
    } elseif ($erro === '') {
      $biz = load_business_config(COMPANY_ID);
      $scaleCfg = balanca_config($pdo, (int)COMPANY_ID);
      $stCompany->execute([COMPANY_ID]);
      $company = $stCompany->fetch(PDO::FETCH_ASSOC) ?: $company;
      if ($hasComandasModule) {
        $comandaSettings = comanda_settings($pdo, (int)COMPANY_ID);
        $comandaPrinterGroups = comanda_printer_groups($pdo, (int)COMPANY_ID, false);
      }
      $msg = 'Configurações atualizadas.';
    }
  }
}

$dayLabel = [
  'sun'=>'Dom', 'mon'=>'Seg', 'tue'=>'Ter', 'wed'=>'Qua', 'thu'=>'Qui', 'fri'=>'Sex', 'sat'=>'Sáb'
];

$scalePublicJson = function_exists('balanca_public_config') ? json_encode(balanca_public_config($scaleCfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : '{}';

admin_topbar('Central - Configurações','params');
?>

<?php if ($erro): ?><div class="msg err"><?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($msg): ?><div class="msg ok"><?= htmlspecialchars($msg) ?></div><?php endif; ?>

<div class="card card-pad">
  <div class="cardhead">
    <div>
      <div style="font-weight:900;">Configurações da empresa</div>
      <div class="hint">WhatsApp, horários, taxa de entrega e banner do catálogo.</div>
    </div>
  </div>

  <form method="post" enctype="multipart/form-data" data-validate>
    <input type="hidden" name="section" value="business">

    <div class="grid">
      <div>
        <label>WhatsApp da loja (somente números com DDD)</label>
        <input class="field" name="whatsapp_store" value="<?= e((string)($biz['whatsapp_store'] ?? '')) ?>" placeholder="31999999999">
        <div class="hint">Esse número recebe o pedido via WhatsApp.</div>
      </div>

      <div>
        <label>Fuso horário</label>
        <input class="field" name="timezone" value="<?= e((string)($biz['timezone'] ?? 'America/Sao_Paulo')) ?>" placeholder="America/Sao_Paulo">
        <div class="hint">Use um timezone válido (ex.: America/Sao_Paulo).</div>
      </div>
    </div>

    <div class="checkline" style="margin-top:10px;">
      <input id="wa_auto" type="checkbox" name="whatsapp_auto_open" value="1" <?= !empty($biz['whatsapp_auto_open']) ? 'checked' : '' ?>>
      <label for="wa_auto" style="margin:0;">Abrir WhatsApp automaticamente ao finalizar no catálogo</label>
    </div>

    <div class="checkline" style="margin-top:10px;">
      <input id="wa_pdv_auto" type="checkbox" name="whatsapp_pdv_auto_send" value="1" <?= !empty($biz['whatsapp_pdv_auto_send']) ? 'checked' : '' ?>>
      <label for="wa_pdv_auto" style="margin:0;">Enviar pedido automaticamente pelo WhatsApp no PDV</label>
    </div>
    <div class="hint" style="margin-top:6px;">Usa o WhatsApp conectado no Admin e envia a mensagem completa do pedido para o número do cliente/WhatsApp avulso informado no PDV.</div>

    <div class="checkline" style="margin-top:14px;">
      <input id="delivery_new_order_alert_enabled" type="checkbox" name="delivery_new_order_alert_enabled" value="1" <?= !empty($biz['delivery_alerts']['new_order_enabled']) ? 'checked' : '' ?>>
      <label for="delivery_new_order_alert_enabled" style="margin:0;"><strong>Habilita bip ao chegar novo pedido do delivery</strong></label>
    </div>
    <div class="hint" style="margin-top:6px;">Usa o Nexa Realtime: o alerta é disparado por evento no instante em que o pedido do catálogo é confirmado, sem consultas periódicas ao banco.</div>

    <div style="margin-top:18px;" id="recalculo-estoque">
      <h4 style="margin:0 0 10px;">Manutenção do estoque</h4>
      <div class="subcard">
        <strong>Recalcular estoque</strong>
        <div class="hint" style="margin-top:6px;">Corrige devoluções pendentes de pedidos cancelados e sincroniza o estoque atual com as movimentações registradas.</div>
        <a class="btn" href="estoque_recalcular.php" style="margin-top:10px;">Abrir recálculo do estoque</a>
      </div>
    </div>

    <div style="margin-top:18px;" id="entrada-notas-xml">
      <h4 style="margin:0 0 10px;">Entrada de notas fiscais</h4>
      <div class="subcard">
        <?php $xmlEntryCfg = (array)($biz['fiscal_xml_entry'] ?? []); $xmlPricingPolicyCfg = (string)($xmlEntryCfg['pricing_policy'] ?? 'keep_margin'); ?>
        <strong>Formação do preço de venda na entrada XML</strong>
        <div class="hint" style="margin-top:6px;">Defina o que o Nexa deve preservar quando o novo custo do produto for diferente do custo anterior.</div>
        <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;margin-top:12px;">
          <label class="checkline" style="align-items:flex-start;gap:10px;border:1px solid #dbe4f0;border-radius:14px;padding:12px;margin:0;">
            <input type="radio" name="fiscal_xml_pricing_policy" value="keep_margin" <?= $xmlPricingPolicyCfg !== 'keep_price' ? 'checked' : '' ?> style="margin-top:3px;">
            <span><strong>Manter margem de lucro</strong><br><small class="hint">Ao calcular o novo custo, mantém a margem atual e atualiza automaticamente o preço de venda.</small></span>
          </label>
          <label class="checkline" style="align-items:flex-start;gap:10px;border:1px solid #dbe4f0;border-radius:14px;padding:12px;margin:0;">
            <input type="radio" name="fiscal_xml_pricing_policy" value="keep_price" <?= $xmlPricingPolicyCfg === 'keep_price' ? 'checked' : '' ?> style="margin-top:3px;">
            <span><strong>Manter preço de venda</strong><br><small class="hint">Ao calcular o novo custo, mantém o preço de venda e recalcula automaticamente a margem de lucro.</small></span>
          </label>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;" id="edicao-pedido-avancada">
      <h4 style="margin:0 0 10px;">Edição de pedidos</h4>
      <div class="subcard">
        <?php $orderEditCfg = (array)($biz['order_edit'] ?? []); ?>
        <label class="checkline" style="align-items:flex-start;gap:10px;margin:0;">
          <input type="checkbox" name="order_edit_advanced_enabled" value="1" <?= !empty($orderEditCfg['advanced_enabled']) ? 'checked' : '' ?>>
          <span><strong>Habilitar nova tela avançada de Editar Pedido</strong><br><small class="hint">Ativa busca F8 de cliente/produto, detalhes tributários por item, identificação de devoluções e rastreamento completo das integrações de pagamento. Desabilitado mantém a tela atual.</small></span>
        </label>
      </div>
    </div>

    <div style="margin-top:18px;" id="consulta-pedidos-grid">
      <h4 style="margin:0 0 10px;">Consulta de Pedidos</h4>
      <div class="subcard">
        <label class="checkline" style="align-items:flex-start;gap:10px;margin:0;">
          <input type="checkbox" name="orders_grid_enabled" value="1" <?= !empty($biz['orders_grid']['enabled']) ? 'checked' : '' ?>>
          <span><strong>Habilita novo grid na Consulta de Pedidos</strong><br><small class="hint">Ações em ícones na mesma linha, coluna fixa, impressões e fiscal em menus, status em modal e colunas personalizáveis por usuário. Desabilitado preserva o layout atual. A configuração é exclusiva desta empresa.</small></span>
        </label>
      </div>
    </div>

    <div style="margin-top:18px;" id="venda-evento">
      <h4 style="margin:0 0 10px;">Venda Mobile para eventos</h4>
      <div class="subcard">
        <?php $eventSalesCfg = (array)($biz['event_sales'] ?? []); ?>
        <label class="checkline">
          <input type="checkbox" name="event_sales_enabled" value="1" <?= !empty($eventSalesCfg['enabled']) ? 'checked' : '' ?>>
          <span><strong>Habilita Venda para Evento</strong></span>
        </label>
        <div class="hint" style="margin-top:8px;">Quando habilitada, somente a <strong>Venda Mobile</strong> imprime, em uma única sequência de 58 mm, primeiro os produtos separados para produção e depois o pedido completo com o número ampliado para entregar ao cliente. O Pedido PDV normal permanece inalterado.</div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 class="form-section-title">Identidade visual e domínio</h4>
      <div class="subcard">
        <div class="grid">
          <div>
            <label>Nome do catálogo</label>
            <input class="field" name="catalog_title" value="<?= e((string)($biz['brand']['catalog_title'] ?? ($company['name'] ?? $app['site_name'] ?? ''))) ?>" placeholder="Nome exibido no catálogo">
          </div>
          <div>
            <label>Subtítulo</label>
            <input class="field" name="catalog_subtitle" value="<?= e((string)($biz['brand']['catalog_subtitle'] ?? 'Catálogo • carrinho • pedidos')) ?>" placeholder="Ex.: Peça online e receba no WhatsApp">
          </div>
          <div>
            <label>Cor principal</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="brand_primary_color" value="<?= e((string)($biz['brand']['primary_color'] ?? '#0f766e')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="brand_primary_color" data-color-name="brand_primary_color" value="<?= e((string)($biz['brand']['primary_color'] ?? '#0f766e')) ?>">
            </div>
          </div>
          <div>
            <label>Cor secundária</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="brand_secondary_color" value="<?= e((string)($biz['brand']['secondary_color'] ?? '#14b8a6')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="brand_secondary_color" data-color-name="brand_secondary_color" value="<?= e((string)($biz['brand']['secondary_color'] ?? '#14b8a6')) ?>">
            </div>
          </div>
          <div>
            <label>Cor de destaque</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="brand_accent_color" value="<?= e((string)($biz['brand']['accent_color'] ?? '#f59e0b')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="brand_accent_color" data-color-name="brand_accent_color" value="<?= e((string)($biz['brand']['accent_color'] ?? '#f59e0b')) ?>">
            </div>
          </div>
          <div>
            <label>Texto sobre cor principal</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="brand_text_on_primary" value="<?= e((string)($biz['brand']['text_on_primary'] ?? '#ffffff')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="brand_text_on_primary" data-color-name="brand_text_on_primary" value="<?= e((string)($biz['brand']['text_on_primary'] ?? '#ffffff')) ?>">
            </div>
          </div>
          <div style="grid-column:1 / -1;">
            <div class="checkline" style="align-items:flex-start;gap:12px;">
              <input id="brand_follow_logo_colors" type="checkbox" name="brand_follow_logo_colors" value="1" <?= !empty($biz['brand']['follow_logo_colors']) ? 'checked' : '' ?> style="margin-top:4px;">
              <div style="flex:1;min-width:260px;">
                <label for="brand_follow_logo_colors" style="margin:0;font-weight:850;">Seguir cores da logo</label>
                <div class="hint">Quando esta opção estiver marcada, o sistema usa a logo cadastrada para gerar as cores principais do catálogo ao salvar.</div>
              </div>
              <button class="btn btn-mini" type="button" id="btnApplyLogoColors">Aplicar agora</button>
            </div>
          </div>
          <div>
            <label>Subdomínio</label>
            <input class="field" name="catalog_subdomain" value="<?= e((string)($biz['domain']['subdomain'] ?? ($company['subdomain'] ?? COMPANY_SLUG))) ?>" placeholder="minhaloja">
            <div class="hint">Acentos, ç e caracteres especiais serão removidos automaticamente. Ex.: Sabor &amp; Art → sabor-e-art.</div>
          </div>
          <div>
            <label>Domínio personalizado</label>
            <input class="field" name="custom_domain" value="<?= e((string)($biz['domain']['custom_domain'] ?? ($company['custom_domain'] ?? ''))) ?>" placeholder="catalogo.minhaloja.com.br">
            <div class="hint">Aponte o DNS para seu servidor antes de ativar.</div>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 class="form-section-title">Cores do painel administrativo</h4>
      <div class="subcard">
        <div class="checkline" style="margin-bottom:12px;">
          <input id="admin_theme_enabled" type="checkbox" name="admin_theme_enabled" value="1" <?= !empty($biz['admin_theme']['enabled']) ? 'checked' : '' ?>>
          <label for="admin_theme_enabled" style="margin:0;">Usar cores personalizadas no painel administrativo</label>
        </div>
        <div class="hint" style="margin-bottom:12px;">
          Se desmarcado, o painel usa o azul institucional da Nexa Próspero. Se marcado, use as cores abaixo para adequar o admin à marca do cliente.
        </div>

        <div class="grid">
          <div>
            <label>Cor principal do admin</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_primary_color" value="<?= e((string)($biz['admin_theme']['primary_color'] ?? '#0B1F5B')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_primary_color" data-color-name="admin_primary_color" value="<?= e((string)($biz['admin_theme']['primary_color'] ?? '#0B1F5B')) ?>">
            </div>
          </div>
          <div>
            <label>Cor secundária do admin</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_secondary_color" value="<?= e((string)($biz['admin_theme']['secondary_color'] ?? '#2454D6')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_secondary_color" data-color-name="admin_secondary_color" value="<?= e((string)($biz['admin_theme']['secondary_color'] ?? '#2454D6')) ?>">
            </div>
          </div>
          <div>
            <label>Cor de destaque do admin</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_accent_color" value="<?= e((string)($biz['admin_theme']['accent_color'] ?? '#00B8FF')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_accent_color" data-color-name="admin_accent_color" value="<?= e((string)($biz['admin_theme']['accent_color'] ?? '#00B8FF')) ?>">
            </div>
          </div>
          <div>
            <label>Cor final do degradê do admin</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_gradient_color" value="<?= e((string)($biz['admin_theme']['gradient_color'] ?? '#06122E')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_gradient_color" data-color-name="admin_gradient_color" value="<?= e((string)($biz['admin_theme']['gradient_color'] ?? '#06122E')) ?>">
            </div>
            <div class="hint">Controla a cor mais escura/final do degradê na barra superior, no menu lateral e na tela de login.</div>
          </div>
          <div>
            <label>Texto sobre a barra do admin</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_text_color" value="<?= e((string)($biz['admin_theme']['text_color'] ?? '#FFFFFF')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_text_color" data-color-name="admin_text_color" value="<?= e((string)($biz['admin_theme']['text_color'] ?? '#FFFFFF')) ?>">
            </div>
          </div>
        </div>

        <div class="actions" style="margin-top:12px;">
          <button class="btn btn-mini" type="button" id="btnCopyCatalogColors">Copiar cores do catálogo</button>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 class="form-section-title">Cores do passo a passo das telas de venda</h4>
      <div class="subcard">
        <?php $adminFlowThemeCfg = (array)($biz['admin_flow_theme'] ?? []); ?>
        <div class="checkline" style="margin-bottom:12px;">
          <input id="admin_flow_theme_enabled" type="checkbox" name="admin_flow_theme_enabled" value="1" <?= !empty($adminFlowThemeCfg['enabled']) ? 'checked' : '' ?>>
          <label for="admin_flow_theme_enabled" style="margin:0;">Usar cores personalizadas nos indicadores de etapas</label>
        </div>
        <div class="hint" style="margin-bottom:12px;">
          Estas cores são independentes do menu e da barra do painel. Elas serão usadas no Pedido PDV, PDV Mobile, PDV Resumido, Comandas e Comanda Mobile.
        </div>

        <div class="grid-2">
          <div>
            <label>Cor inicial do passo a passo</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_flow_primary_color" value="<?= e((string)($adminFlowThemeCfg['primary_color'] ?? '#143A9C')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_flow_primary_color" data-color-name="admin_flow_primary_color" value="<?= e((string)($adminFlowThemeCfg['primary_color'] ?? '#143A9C')) ?>">
            </div>
          </div>
          <div>
            <label>Cor final do passo a passo</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_flow_secondary_color" value="<?= e((string)($adminFlowThemeCfg['secondary_color'] ?? '#2563EB')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_flow_secondary_color" data-color-name="admin_flow_secondary_color" value="<?= e((string)($adminFlowThemeCfg['secondary_color'] ?? '#2563EB')) ?>">
            </div>
          </div>
          <div>
            <label>Texto sobre o passo a passo</label>
            <div class="rowline color-pair">
              <input class="field js-color-picker" type="color" data-color-target="admin_flow_text_color" value="<?= e((string)($adminFlowThemeCfg['text_color'] ?? '#FFFFFF')) ?>" style="max-width:92px;padding:8px;">
              <input class="field js-color-text" name="admin_flow_text_color" data-color-name="admin_flow_text_color" value="<?= e((string)($adminFlowThemeCfg['text_color'] ?? '#FFFFFF')) ?>">
            </div>
          </div>
        </div>

        <div class="actions" style="margin-top:12px;">
          <button class="btn btn-mini" type="button" id="btnCopyAdminFlowColors">Copiar cores do admin</button>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 class="form-section-title">Layout do painel administrativo</h4>
      <div class="subcard">
        <?php $adminUiCfg = (array)($biz['admin_ui'] ?? []); ?>
        <label class="checkline" style="align-items:flex-start;gap:12px;">
          <input id="admin_side_menu_enabled" type="checkbox" name="admin_side_menu_enabled" value="1" <?= !empty($adminUiCfg['side_menu_enabled']) ? 'checked' : '' ?> style="margin-top:4px;">
          <span>
            <strong>Habilita novo Menu</strong><br>
            <small class="hint">Quando marcado, o painel passa a usar menu lateral no computador e menu lateral recolhível no celular. Se desmarcar, volta para o menu superior tradicional.</small>
          </span>
        </label>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Horário de funcionamento</h4>
      <div class="table-wrap">
        <table>
          <thead>
            <tr><th>Dia</th><th>1º horário</th><th>2º horário</th></tr>
          </thead>
          <tbody>
          <?php foreach (['sun','mon','tue','wed','thu','fri','sat'] as $d):
            $h = (array)($biz['hours'][$d] ?? []);
            $o1 = $h[0][0] ?? ''; $c1 = $h[0][1] ?? '';
            $o2 = $h[1][0] ?? ''; $c2 = $h[1][1] ?? '';
          ?>
            <tr>
              <td style="font-weight:800;"><?= e($dayLabel[$d]) ?></td>
              <td>
                <div class="rowline">
                  <input class="field" type="time" name="<?= e($d) ?>_open1" value="<?= e((string)$o1) ?>">
                  <input class="field" type="time" name="<?= e($d) ?>_close1" value="<?= e((string)$c1) ?>">
                </div>
              </td>
              <td>
                <div class="rowline">
                  <input class="field" type="time" name="<?= e($d) ?>_open2" value="<?= e((string)$o2) ?>">
                  <input class="field" type="time" name="<?= e($d) ?>_close2" value="<?= e((string)$c2) ?>">
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <div class="hint">Você pode usar 1 ou 2 períodos por dia. Deixe vazio para “fechado”.</div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Checkout</h4>
      <div class="subcard">
        <?php $checkoutCfg = (array)($biz['checkout'] ?? []); ?>
        <div class="grid">
          <div>
            <label>Nome do campo Nome/Razão no checkout</label>
            <input class="field" name="checkout_customer_name_label" value="<?= e((string)($checkoutCfg['customer_name_label'] ?? 'Nome/Razão social')) ?>" placeholder="Nome/Razão social">
            <div class="hint">Ex.: Nome completo, Nome do cliente, Responsável, Nome/Razão social.</div>
          </div>
          <div>
            <label>Texto de exemplo dentro do campo</label>
            <input class="field" name="checkout_customer_name_placeholder" value="<?= e((string)($checkoutCfg['customer_name_placeholder'] ?? 'Nome completo ou razão social')) ?>" placeholder="Nome completo ou razão social">
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Produtos, adicionais e observações</h4>
      <div class="subcard">
        <?php
          $productOptionsCfg = (array)($biz['product_options'] ?? []);
          $itemNoteInstruction = (string)($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...');
        ?>
        <?php $catalogUiCfg = (array)($biz['catalog_ui'] ?? []); ?>
        <label class="checkline">
          <input type="checkbox" name="catalog_horizontal_category_menu" value="1" <?= !empty($catalogUiCfg['horizontal_category_menu']) ? 'checked' : '' ?>>
          <span>Habilitar menu de categoria horizontal, com botões maiores e rolagem lateral no catálogo</span>
        </label>
        <div class="hint" style="margin:6px 0 14px 28px;">Ao ativar, as categorias deixam de aparecer como chips pequenos e passam a aparecer como botões verticais com imagem/ícone, facilitando a navegação no celular.</div>

        <label class="checkline">
          <input type="checkbox" name="product_options_show_additional_unit_total" value="1" <?= !empty($productOptionsCfg['show_additional_unit_total']) ? 'checked' : '' ?>>
          <span>Mostrar, abaixo do valor do adicional, o total do produto + aquele adicional marcado</span>
        </label>
        <label class="checkline" style="margin-top:12px;">
          <input type="checkbox" name="product_options_show_item_total_with_additionals" value="1" <?= !empty($productOptionsCfg['show_item_total_with_additionals']) ? 'checked' : '' ?>>
          <span>Mostrar o total do item considerando produto + todos os adicionais marcados</span>
        </label>
        <div style="margin-top:10px;">
          <label>Instrução da observação do item</label>
          <textarea class="field" name="product_options_item_note_instruction" rows="2" placeholder="Ex.: sem cebola, bem passada, retirar ingrediente..."><?= e($itemNoteInstruction) ?></textarea>
          <div class="hint">Este texto aparece como instrução/exemplo no campo Observação do item, no catálogo, PDV e edição de pedido.</div>
        </div>
      </div>
    </div>

    <?php if ($hasScaleModule): ?>
    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Integração com balança</h4>
      <div class="subcard">
        <label class="checkline">
          <input type="checkbox" name="scale_enabled" value="1" <?= !empty($scaleCfg['enabled']) ? 'checked' : '' ?>>
          <span>Habilitar leitura de balança nos produtos marcados como pesáveis</span>
        </label>
        <div class="hint" style="margin-top:8px;">No cadastro do produto, marque <strong>Produto pesável</strong>. No PDV e na Comanda, ao selecionar este item, a quantidade pode ser preenchida pela balança.</div>
        <div class="grid" style="margin-top:12px;">
          <div>
            <label>Modelo</label>
            <select class="field" name="scale_model">
              <option value="prix_3_fit" <?= (string)$scaleCfg['model']==='prix_3_fit'?'selected':'' ?>>Toledo Prix 3 Fit</option>
              <option value="prix_3" <?= (string)$scaleCfg['model']==='prix_3'?'selected':'' ?>>Toledo Prix 3</option>
              <option value="toledo" <?= (string)$scaleCfg['model']==='toledo'?'selected':'' ?>>Toledo genérica</option>
              <option value="urano_udc_coe" <?= (string)$scaleCfg['model']==='urano_udc_coe'?'selected':'' ?>>Urano UDC CO-E (Checkout)</option>
              <option value="generic_serial" <?= (string)$scaleCfg['model']==='generic_serial'?'selected':'' ?>>Serial genérica</option>
            </select>
            <div class="hint">Urano UDC CO-E (Checkout): ao selecionar, o Nexa sugere 9600 bps, 8 bits, sem paridade e 2 stop bits. O teste continua editável para versões configuradas em Std01–Std05.</div>
          </div>
          <div>
            <label>Conexão</label>
            <select class="field" name="scale_connection">
              <option value="webserial" <?= (string)$scaleCfg['connection']==='webserial'?'selected':'' ?>>Navegador/Web Serial</option>
              <option value="manual" <?= (string)$scaleCfg['connection']==='manual'?'selected':'' ?>>Manual</option>
            </select>
            <div class="hint">Web Serial funciona no Chrome/Edge em HTTPS ou ambiente local compatível.</div>
          </div>
          <div>
            <label>Protocolo</label>
            <select class="field" name="scale_protocol">
              <option value="auto" <?= (string)$scaleCfg['protocol']==='auto'?'selected':'' ?>>Automático</option>
              <option value="prt1" <?= (string)$scaleCfg['protocol']==='prt1'?'selected':'' ?>>Prt1</option>
              <option value="prt2" <?= (string)$scaleCfg['protocol']==='prt2'?'selected':'' ?>>Prt2</option>
              <option value="prt3" <?= (string)$scaleCfg['protocol']==='prt3'?'selected':'' ?>>Prt3</option>
              <option value="prt5" <?= (string)$scaleCfg['protocol']==='prt5'?'selected':'' ?>>Prt5</option>
              <option value="urano12" <?= (string)$scaleCfg['protocol']==='urano12'?'selected':'' ?>>Urano Uran12</option>
              <option value="urano_std01" <?= (string)$scaleCfg['protocol']==='urano_std01'?'selected':'' ?>>Urano Std01</option>
              <option value="urano_std02" <?= (string)$scaleCfg['protocol']==='urano_std02'?'selected':'' ?>>Urano Std02</option>
              <option value="urano_std03" <?= (string)$scaleCfg['protocol']==='urano_std03'?'selected':'' ?>>Urano Std03</option>
              <option value="urano_std04" <?= (string)$scaleCfg['protocol']==='urano_std04'?'selected':'' ?>>Urano Std04</option>
              <option value="urano_std05" <?= (string)$scaleCfg['protocol']==='urano_std05'?'selected':'' ?>>Urano Std05</option>
              <option value="generic_line" <?= (string)$scaleCfg['protocol']==='generic_line'?'selected':'' ?>>Linha serial genérica</option>
            </select>
          </div>
          <div>
            <label>Modo de leitura</label>
            <select class="field" name="scale_request_mode">
              <option value="enq" <?= (string)$scaleCfg['request_mode']==='enq'?'selected':'' ?>>Solicitar peso com ENQ</option>
              <option value="continuous" <?= (string)$scaleCfg['request_mode']==='continuous'?'selected':'' ?>>Transmissão contínua</option>
              <option value="manual" <?= (string)$scaleCfg['request_mode']==='manual'?'selected':'' ?>>Somente manual</option>
            </select>
          </div>
        </div>
        <div class="grid" style="grid-template-columns:repeat(6,minmax(0,1fr));gap:10px;margin-top:12px;">
          <div><label>Baud rate</label><input class="field" name="scale_baud_rate" value="<?= e((string)$scaleCfg['baud_rate']) ?>" inputmode="numeric"></div>
          <div><label>Bits</label><select class="field" name="scale_data_bits"><option value="8" <?= (int)$scaleCfg['data_bits']===8?'selected':'' ?>>8</option><option value="7" <?= (int)$scaleCfg['data_bits']===7?'selected':'' ?>>7</option></select></div>
          <div><label>Paridade</label><select class="field" name="scale_parity"><option value="none" <?= (string)$scaleCfg['parity']==='none'?'selected':'' ?>>Nenhuma</option><option value="even" <?= (string)$scaleCfg['parity']==='even'?'selected':'' ?>>Par</option><option value="odd" <?= (string)$scaleCfg['parity']==='odd'?'selected':'' ?>>Ímpar</option></select></div>
          <div><label>Stop bit</label><select class="field" name="scale_stop_bits"><option value="1" <?= (int)$scaleCfg['stop_bits']===1?'selected':'' ?>>1</option><option value="2" <?= (int)$scaleCfg['stop_bits']===2?'selected':'' ?>>2</option></select></div>
          <div><label>Decimais kg</label><input class="field" name="scale_decimal_places" value="<?= e((string)$scaleCfg['decimal_places']) ?>" inputmode="numeric"></div>
          <div><label>Timeout ms</label><input class="field" name="scale_timeout_ms" value="<?= e((string)$scaleCfg['timeout_ms']) ?>" inputmode="numeric"></div>
        </div>
        <div class="grid" style="margin-top:12px;">
          <div>
            <label>Porta/identificação</label>
            <input class="field" name="scale_port_hint" value="<?= e((string)$scaleCfg['port_hint']) ?>" placeholder="Ex.: COM3, USB Serial, Bluetooth SPP">
            <div class="hint">O navegador solicitará a porta no primeiro uso. Este campo é apenas orientação para o operador.</div>
          </div>
          <div>
            <label>Peso mínimo para aceitar</label>
            <input class="field" name="scale_min_weight" value="<?= e(str_replace('.', ',', (string)$scaleCfg['min_weight'])) ?>" inputmode="decimal" placeholder="0,001">
          </div>
        </div>
        <div style="margin-top:12px;display:grid;gap:10px;">
          <label class="checkline"><input type="checkbox" name="scale_stable_only" value="1" <?= !empty($scaleCfg['stable_only']) ? 'checked' : '' ?>><span>Aceitar somente peso estável quando o protocolo informar estabilidade</span></label>
          <label class="checkline"><input type="checkbox" name="scale_auto_fill_quantity" value="1" <?= !empty($scaleCfg['auto_fill_quantity']) ? 'checked' : '' ?>><span>Ao ler peso, preencher automaticamente o campo Quantidade do produto</span></label>
        </div>

        <div class="scale-test-panel" id="scaleTestPanel" style="margin-top:14px;border:1px solid #bfdbfe;background:#eff6ff;border-radius:18px;padding:14px;display:grid;gap:10px;">
          <div style="display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap;">
            <div>
              <strong style="display:block;color:var(--admin-primary);font-size:16px;">Teste de leitura da balança</strong>
              <div class="hint">Clique em <strong>Testar peso</strong>, selecione a porta da Prix 3 Fit e aguarde a leitura. O teste usa os parâmetros preenchidos acima, mesmo antes de salvar.</div>
            </div>
            <button class="btn btn-primary" type="button" id="btnScaleTest">Testar peso</button>
          </div>
          <div class="scale-test-result" style="display:grid;grid-template-columns:160px minmax(0,1fr);gap:10px;align-items:center;">
            <div style="border:2px solid #1d4ed8;border-radius:16px;background:#fff;padding:12px;text-align:center;">
              <span style="display:block;color:#64748b;font-size:12px;font-weight:850;">Peso lido</span>
              <strong id="scaleTestWeight" style="display:block;color:#047857;font-size:28px;line-height:1.1;">--,--- kg</strong>
            </div>
            <div>
              <div id="scaleTestStatus" class="hint" style="font-weight:850;color:#334155;">Aguardando teste.</div>
              <div class="hint" style="margin-top:5px;">Se o navegador perguntar, escolha a porta correspondente à balança/USB Serial/Bluetooth SPP.</div>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Agendamento de entrega</h4>
      <div class="subcard">
        <label class="checkline">
          <input type="checkbox" name="delivery_scheduling_enabled" value="1" <?= !empty($biz['delivery_scheduling']['enabled']) ? 'checked' : '' ?>>
          <span>Habilitar campo de Data/Hora Prevista no checkout</span>
        </label>
        <label class="checkline" style="margin-top:12px;">
          <input type="checkbox" name="delivery_scheduling_required" value="1" <?= !empty($biz['delivery_scheduling']['required']) ? 'checked' : '' ?>>
          <span>Obrigar preenchimento da data e hora prevista no checkout</span>
        </label>
        <div style="margin-top:10px;">
          <label>Nome do campo</label>
          <input class="field" name="delivery_scheduling_label" value="<?= e((string)($biz['delivery_scheduling']['label'] ?? 'Data/Hora Prevista')) ?>" placeholder="Data/Hora Prevista">
          <div class="hint">Quando habilitado, o cliente poderá informar data e hora previstas de entrega no checkout.</div>
        </div>
        <div style="margin-top:10px;">
          <label>Tempo mínimo entre o pedido e a previsão (minutos)</label>
          <input class="field" type="number" min="0" step="1" name="delivery_scheduling_min_minutes" value="<?= e((string)max(0, (int)($biz['delivery_scheduling']['min_minutes'] ?? 0))) ?>" placeholder="Ex.: 30">
          <div class="hint">Use 0 para não limitar. Ex.: se informar 30, a previsão precisa ficar pelo menos 30 minutos após a data/hora do pedido.</div>
        </div>
        <label class="checkline" style="margin-top:12px;">
          <input type="checkbox" name="delivery_scheduling_only_business_hours" value="1" <?= !empty($biz['delivery_scheduling']['only_business_hours']) ? 'checked' : '' ?>>
          <span>Permitir previsão de entrega somente dentro do horário de funcionamento</span>
        </label>
        <div class="hint">Quando marcado, o checkout valida a data e a hora escolhidas conforme os horários cadastrados acima.</div>
      </div>
    </div>

    <?php if ($hasComandasModule): ?>
    <div id="configuracao-comandas" style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Configuração de comandas</h4>
      <div class="subcard">
        <div class="hint" style="margin-bottom:12px;">Estas opções só aparecem quando o módulo Comandas está habilitado para a empresa. O cadastro das comandas fica em <a href="gestao_comandas_config.php">Cadastro de Comandas</a>.</div>
        <div class="grid" style="grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;">
          <label class="checkline"><input type="checkbox" name="cmd_show_only_occupied" value="1" <?= !empty($comandaSettings['show_only_occupied']) ? 'checked' : '' ?>><span>Mostrar somente comandas ocupadas na tela inicial</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_allow_transfer" value="1" <?= !empty($comandaSettings['allow_transfer']) ? 'checked' : '' ?>><span>Permitir transferência de comandas</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_allow_multi_transfer" value="1" <?= !empty($comandaSettings['allow_multi_transfer']) ? 'checked' : '' ?>><span>Permitir múltiplas transferências</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_allow_item_transfer" value="1" <?= !empty($comandaSettings['allow_item_transfer']) ? 'checked' : '' ?>><span>Permitir transferência de item entre comandas</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_allow_named_comanda" value="1" <?= !empty($comandaSettings['allow_named_comanda']) ? 'checked' : '' ?>><span>Permitir nome/apelido na comanda</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_prompt_name_first_launch" value="1" <?= !empty($comandaSettings['prompt_name_first_launch']) ? 'checked' : '' ?>><span>Habilitar digitação do nome da pessoa/comanda no primeiro lançamento</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_allow_customer" value="1" <?= !empty($comandaSettings['allow_customer']) ? 'checked' : '' ?>><span>Permitir cliente cadastrado na comanda</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_auto_print_kitchen" value="1" <?= !empty($comandaSettings['auto_print_kitchen']) ? 'checked' : '' ?>><span>Imprimir automaticamente os itens de cozinha ao lançar no Menu Comanda</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_direct_print_kitchen" value="1" <?= !empty($comandaSettings['direct_print_kitchen']) ? 'checked' : '' ?>><span>Menu Comanda: enviar diretamente ao agente, sem abrir janela de impressão</span></label>
          <label style="display:block"><span style="display:block;font-weight:850;margin-bottom:6px">Alerta de inatividade da comanda (minutos)</span><input class="field" type="number" min="0" max="1440" step="1" name="cmd_inactive_alert_minutes" value="<?= (int)($comandaSettings['inactive_alert_minutes'] ?? 10) ?>"><small class="hint">Após este tempo sem novos pedidos, a comanda ocupada ficará amarela. Use 0 para desativar.</small></label>
          <label class="checkline"><input type="checkbox" name="cmd_public_qr_enabled" value="1" <?= !empty($comandaSettings['public_qr_enabled']) ? 'checked' : '' ?>><span>Permitir que clientes façam pedidos pelo QR Code da mesa</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_public_qr_require_name" value="1" <?= !empty($comandaSettings['public_qr_require_name']) ? 'checked' : '' ?>><span>Exigir nome no pedido pelo QR Code</span></label>
          <label class="checkline"><input type="checkbox" name="cmd_public_qr_require_phone" value="1" <?= !empty($comandaSettings['public_qr_require_phone']) ? 'checked' : '' ?>><span>Exigir celular no pedido pelo QR Code</span></label>
        </div>
        <div class="hint" style="margin-top:10px">Quando a identificação no primeiro lançamento estiver habilitada, o Menu Comanda e o Comanda Mobile solicitarão o nome somente ao processar o primeiro grupo de produtos de uma comanda livre. Nos lançamentos seguintes, a tela não será exibida novamente. A impressão direta usa os mesmos grupos e agentes do cardápio QR Code. Mantenha também a opção de impressão automática marcada. <a href="gestao_comandas_qrcode.php">Imprimir QR Codes das mesas</a> · <a href="gestao_comandas_agentes.php">Configurar agentes automáticos</a> · <a href="gestao_comandas_impressao.php">Abrir monitor da cozinha/bar</a></div>

        <div style="margin-top:16px;">
          <h4 class="form-section-title">Grupos de impressão da cozinha</h4>
          <div class="table-wrap">
            <table>
              <thead><tr><th>Grupo</th><th>Impressora padrão</th><th>Ordem</th><th>Ativo</th></tr></thead>
              <tbody>
              <?php foreach ($comandaPrinterGroups as $g): ?>
                <tr>
                  <td><input type="hidden" name="cmd_pg_id[]" value="<?= (int)$g['id'] ?>"><input class="field" name="cmd_pg_name[]" value="<?= e((string)$g['name']) ?>"></td>
                  <td><input class="field" name="cmd_pg_printer[]" value="<?= e((string)($g['printer_name'] ?? '')) ?>" placeholder="Nome da impressora no Windows/Linux"></td>
                  <td><input class="field" name="cmd_pg_order[]" value="<?= e((string)($g['sort_order'] ?? 0)) ?>" inputmode="numeric"></td>
                  <td><label class="checkline"><input type="checkbox" name="cmd_pg_active[<?= (int)$g['id'] ?>]" value="1" <?= !empty($g['is_active']) ? 'checked' : '' ?>><span>Sim</span></label></td>
                </tr>
              <?php endforeach; ?>
              </tbody>
            </table>
          </div>
          <div class="grid" style="grid-template-columns:1fr 120px 1fr;gap:12px;margin-top:12px;">
            <div><label>Novo grupo</label><input class="field" name="cmd_new_group_name" placeholder="Ex.: Sobremesas"></div>
            <div><label>Ordem</label><input class="field" name="cmd_new_group_order" value="99" inputmode="numeric"></div>
            <div><label>Impressora</label><input class="field" name="cmd_new_group_printer" placeholder="Nome da impressora"></div>
          </div>
          <div class="hint" style="margin-top:8px;">O produto usa o campo Grupo de impressão. Se estiver vazio, o sistema usa grupo de produção ou categoria.</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Relatórios de pedidos</h4>
      <div class="subcard">
        <?php $orderReportsCfg = (array)($biz['order_reports'] ?? ['enabled' => true]); ?>
        <label class="checkline">
          <input type="checkbox" name="order_reports_enabled" value="1" <?= !array_key_exists('enabled', $orderReportsCfg) || !empty($orderReportsCfg['enabled']) ? 'checked' : '' ?>>
          <span>Exibir botão <strong>Relatórios</strong> na tela de pedidos</span>
        </label>
        <div class="hint">O botão abre o relatório de pedidos em aberto totalizado por produto, separado por grupo de produção e/ou categoria, pronto para impressão em impressora 72 colunas.</div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Tipos de pedido/entrega</h4>
      <div class="subcard">
        <?php
          $orderTypesCfg = (array)($biz['order_types'] ?? []);
          $checkoutTypesCfg = isset($orderTypesCfg['checkout']) && is_array($orderTypesCfg['checkout'])
            ? (array)$orderTypesCfg['checkout']
            : [
                'entrega' => !array_key_exists('entrega', $orderTypesCfg) || !empty($orderTypesCfg['entrega']),
                'retirada' => !array_key_exists('retirada', $orderTypesCfg) || !empty($orderTypesCfg['retirada']),
              ];
          $pdvTypesCfg = isset($orderTypesCfg['pdv']) && is_array($orderTypesCfg['pdv'])
            ? (array)$orderTypesCfg['pdv']
            : [
                'balcao' => !array_key_exists('balcao', $orderTypesCfg) || !empty($orderTypesCfg['balcao']),
                'retirada' => !array_key_exists('retirada', $orderTypesCfg) || !empty($orderTypesCfg['retirada']),
                'entrega' => !array_key_exists('entrega', $orderTypesCfg) || !empty($orderTypesCfg['entrega']),
              ];
        ?>
        <div class="grid">
          <div>
            <h5 style="margin:0 0 10px;font-size:14px;">Checkout / catálogo</h5>
            <div class="checkline" style="margin-bottom:10px;">
              <input id="order_type_checkout_entrega" type="checkbox" name="order_type_checkout_entrega" value="1" <?= !array_key_exists('entrega', $checkoutTypesCfg) || !empty($checkoutTypesCfg['entrega']) ? 'checked' : '' ?>>
              <label for="order_type_checkout_entrega" style="margin:0;">Exibir Entrega no checkout</label>
            </div>
            <div class="checkline">
              <input id="order_type_checkout_retirada" type="checkbox" name="order_type_checkout_retirada" value="1" <?= !array_key_exists('retirada', $checkoutTypesCfg) || !empty($checkoutTypesCfg['retirada']) ? 'checked' : '' ?>>
              <label for="order_type_checkout_retirada" style="margin:0;">Exibir Retirada no checkout</label>
            </div>
            <div class="hint" style="margin-top:8px;">Use quando quiser controlar as opções disponíveis para o cliente no catálogo.</div>
          </div>
          <div>
            <h5 style="margin:0 0 10px;font-size:14px;">PDV / atendimento interno</h5>
            <div class="checkline" style="margin-bottom:10px;">
              <input id="order_type_pdv_entrega" type="checkbox" name="order_type_pdv_entrega" value="1" <?= !array_key_exists('entrega', $pdvTypesCfg) || !empty($pdvTypesCfg['entrega']) ? 'checked' : '' ?>>
              <label for="order_type_pdv_entrega" style="margin:0;">Exibir Entrega no PDV</label>
            </div>
            <div class="checkline" style="margin-bottom:10px;">
              <input id="order_type_pdv_retirada" type="checkbox" name="order_type_pdv_retirada" value="1" <?= !array_key_exists('retirada', $pdvTypesCfg) || !empty($pdvTypesCfg['retirada']) ? 'checked' : '' ?>>
              <label for="order_type_pdv_retirada" style="margin:0;">Exibir Retirada no PDV</label>
            </div>
            <div class="checkline">
              <input id="order_type_pdv_balcao" type="checkbox" name="order_type_pdv_balcao" value="1" <?= !array_key_exists('balcao', $pdvTypesCfg) || !empty($pdvTypesCfg['balcao']) ? 'checked' : '' ?>>
              <label for="order_type_pdv_balcao" style="margin:0;">Exibir Balcão no PDV</label>
            </div>
            <div class="hint" style="margin-top:8px;">Permite, por exemplo, aceitar entrega no PDV e esconder entrega no checkout.</div>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Taxa de entrega</h4>
      <div class="grid">
        <div>
          <label>Taxa padrão (quando bairro não estiver cadastrado)</label>
          <input class="field" name="delivery_default" value="<?= e((string)($biz['delivery_fees']['default'] ?? 0)) ?>" placeholder="0.00" inputmode="decimal">
          <div class="hint">Se o bairro não estiver na lista, será usada esta taxa.</div>
        </div>
        <div>
          <label>Bairros e taxas</label>
          <div id="bairrosList" class="bairros-list">
            <?php foreach ((array)($biz['delivery_fees']['bairros'] ?? []) as $bn => $fee): ?>
              <div class="bairros-row">
                <input class="field" name="bairros_nome[]" value="<?= e((string)$bn) ?>" placeholder="Bairro">
                <input class="field" name="bairros_taxa[]" value="<?= e(number_format((float)$fee, 2, '.', '')) ?>" placeholder="0.00" inputmode="decimal">
                <button type="button" class="btn btn-mini btn-danger" data-remove>Remover</button>
              </div>
            <?php endforeach; ?>
          </div>
          <div style="margin-top:10px;">
            <button type="button" class="btn btn-mini" id="btnAddBairro">+ Adicionar bairro</button>
          </div>
          <div class="hint">Informe a taxa em reais (ex.: 5.00).</div>
        </div>
      </div>
    </div>


    <div style="margin-top:18px;">
      <h4 class="form-section-title">Logo do catálogo</h4>
      <div class="subcard">
        <div class="fileline">
          <?php $logoFile = trim((string)($biz['logo']['file'] ?? '')); $logoUrl = $logoFile !== '' ? ('/uploads/logos/' . rawurlencode($logoFile)) : ''; ?>
          <div id="logoPreview" class="logo-preview">
            <?php if ($logoUrl !== ''): ?>
              <img src="<?= e($logoUrl) ?>" alt="Logo da empresa">
            <?php else: ?>
              <span style="font-size:38px;">🏪</span>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:280px;">
            <label>Arquivo da logo</label>
            <input class="field" type="file" name="logo" accept=".jpg,.jpeg,.png,.webp,.svg" data-preview="#logoPreview img">
            <div class="inline-note">Use imagem quadrada ou horizontal com fundo transparente para melhor resultado.</div>
            <?php if ($logoUrl !== ''): ?>
              <div class="checkline" style="margin-top:10px;">
                <input id="logo_remove" type="checkbox" name="logo_remove" value="1">
                <label for="logo_remove" style="margin:0;">Remover logo atual</label>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>


    <div style="margin-top:18px;">
      <h4 class="form-section-title">Logo do PDV Checkout</h4>
      <div class="subcard">
        <?php
          $pdvLogoFile = trim((string)($biz['pdv_checkout']['logo']['file'] ?? ''));
          $pdvLogoUrl = $pdvLogoFile !== '' ? ('/uploads/logos/' . rawurlencode($pdvLogoFile)) : '';
          $catalogLogoFile = trim((string)($biz['logo']['file'] ?? ''));
          $catalogLogoUrl = $catalogLogoFile !== '' ? ('/uploads/logos/' . rawurlencode($catalogLogoFile)) : '';
          $pdvPreviewUrl = $pdvLogoUrl !== '' ? $pdvLogoUrl : $catalogLogoUrl;
        ?>
        <div class="fileline">
          <div id="pdvCheckoutLogoPreview" class="logo-preview" style="width:8cm;height:8cm;max-width:302px;max-height:302px;border-radius:18px;display:flex;align-items:center;justify-content:center;overflow:hidden;">
            <?php if ($pdvPreviewUrl !== ''): ?>
              <img src="<?= e($pdvPreviewUrl) ?>" alt="Logo do PDV Checkout" style="width:100%;height:100%;object-fit:contain;">
            <?php else: ?>
              <span style="font-size:38px;">🏪</span>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:280px;">
            <label>Arquivo da logo do PDV Checkout</label>
            <input class="field" type="file" name="pdv_checkout_logo" accept=".jpg,.jpeg,.png,.webp,.svg">
            <div class="inline-note">Exibida somente no PDV Checkout em uma área de 8 cm × 8 cm. Recomenda-se PNG/WEBP quadrado com fundo transparente. Se não cadastrar uma logo específica, o PDV usa automaticamente a Logo do catálogo.</div>
            <?php if ($pdvLogoUrl !== ''): ?>
              <div class="checkline" style="margin-top:10px;">
                <input id="pdv_checkout_logo_remove" type="checkbox" name="pdv_checkout_logo_remove" value="1">
                <label for="pdv_checkout_logo_remove" style="margin:0;">Remover logo específica do PDV e voltar a usar a logo do catálogo</label>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>


    <div style="margin-top:18px;">
      <h4 class="form-section-title">Capa do catálogo</h4>
      <div class="subcard">
        <?php $coverFile = trim((string)($biz['cover']['file'] ?? '')); $coverUrl = $coverFile !== '' ? ('/uploads/covers/' . rawurlencode($coverFile)) : ''; ?>
        <div class="fileline">
          <div id="coverPreview" class="logo-preview" style="width:220px;height:120px;border-radius:22px;">
            <?php if ($coverUrl !== ''): ?>
              <img src="<?= e($coverUrl) ?>" alt="Capa do catálogo" style="object-fit:cover;">
            <?php else: ?>
              <span style="font-size:30px;">🖼️</span>
            <?php endif; ?>
          </div>
          <div style="flex:1;min-width:280px;">
            <label>Imagem de capa</label>
            <input class="field" type="file" name="cover" accept=".jpg,.jpeg,.png,.webp">
            <div class="inline-note">Aparece no topo do catálogo. Tamanho recomendado: 1600x500 px.</div>
            <?php if ($coverUrl !== ''): ?>
              <div class="checkline" style="margin-top:10px;">
                <input id="cover_remove" type="checkbox" name="cover_remove" value="1">
                <label for="cover_remove" style="margin:0;">Remover capa atual</label>
              </div>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>

    <div style="margin-top:18px;">
      <h4 style="margin:0 0 10px;">Banner promocional</h4>
      <div class="checkline">
        <input id="ban_en" type="checkbox" name="banner_enabled" value="1" <?= !empty($biz['banner']['enabled']) ? 'checked' : '' ?>>
        <label for="ban_en" style="margin:0;">Exibir banner no catálogo</label>
      </div>
      <div class="grid" style="margin-top:10px;">
        <div>
          <label>Título</label>
          <input class="field" name="banner_title" value="<?= e((string)($biz['banner']['title'] ?? '')) ?>" placeholder="Promoção do dia">
        </div>
        <div>
          <label>Subtítulo</label>
          <input class="field" name="banner_subtitle" value="<?= e((string)($biz['banner']['subtitle'] ?? '')) ?>" placeholder="Ex.: 8% no Pix">
        </div>
        <div>
          <label>Texto do link</label>
          <input class="field" name="banner_link_text" value="<?= e((string)($biz['banner']['link_text'] ?? '')) ?>" placeholder="Ver promoção">
        </div>
        <div>
          <label>URL do link</label>
          <input class="field" name="banner_link_url" value="<?= e((string)($biz['banner']['link_url'] ?? '')) ?>" placeholder="#">
        </div>
      </div>
    </div>


    <?php if ($hasExportacaoModule): ?>
    <div class="card" id="firebird-settings" style="margin-top:16px;">
      <h3 style="margin:0 0 12px 0;">Integração Firebird</h3>
      <p class="muted" style="margin-top:-6px;">Configuração profissional para exportação no Próspero.</p>
      <div class="actions" style="margin:12px 0 16px;">
        <button class="btn" type="submit" name="action" value="fb_test">Testar conexão</button>
        <button class="btn" type="submit" name="action" value="fb_export_now" onclick="return confirm('Rodar a exportação dos pedidos pendentes agora?');">Rodar exportação agora</button>
      </div>

      <div class="grid" style="grid-template-columns: 1fr 1fr; gap:12px;">
        <div>
          <label class="lbl">Host</label>
          <input class="field" name="fb_host" type="text" value="<?= htmlspecialchars((string)($fb['host'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Porta</label>
          <input class="field" name="fb_port" type="text" value="<?= htmlspecialchars((string)($fb['port'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div style="grid-column:1 / -1;">
          <label class="lbl">Database (caminho)</label>
          <input class="field" name="fb_database" type="text" value="<?= htmlspecialchars((string)($fb['database'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Usuário</label>
          <input class="field" name="fb_user" type="text" value="<?= htmlspecialchars((string)($fb['user'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Senha</label>
          <input class="field" name="fb_password" type="password" value="<?= htmlspecialchars((string)($fb['pass'] ?? ($fb['password'] ?? '')), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Charset</label>
          <input class="field" name="fb_charset" type="text" value="<?= htmlspecialchars((string)($fb['charset'] ?? 'UTF8'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Código da empresa no Firebird</label>
          <input class="field" name="fb_empresa_codigo" type="number" min="1" value="<?= htmlspecialchars((string)($fb['empresa_codigo'] ?? 1), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Cliente padrão no Firebird</label>
          <input class="field" name="fb_cliente_padrao" type="number" min="1" value="<?= htmlspecialchars((string)($fb['cliente_padrao'] ?? 1), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Tabela pedidos</label>
          <input class="field" name="fb_table_orders" type="text" value="<?= htmlspecialchars((string)($fb['tables']['orders'] ?? 'ORCAMENTO'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
        <div>
          <label class="lbl">Tabela itens</label>
          <input class="field" name="fb_table_items" type="text" value="<?= htmlspecialchars((string)($fb['tables']['items'] ?? 'ORCAMENTO_ITENS'), ENT_QUOTES, 'UTF-8') ?>">
        </div>
      </div>

      <div style="margin-top:16px;">
        <h4 class="form-section-title">Mapeamento ORCAMENTO</h4>
        <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;">
          <div><label>EMPRESA</label><input class="field" name="fb_orders_empresa" value="<?= e((string)($fb['fields']['orders']['empresa'] ?? 'EMPRESA')) ?>"></div>
          <div><label>CODIGO</label><input class="field" name="fb_orders_codigo" value="<?= e((string)($fb['fields']['orders']['codigo'] ?? 'CODIGO')) ?>"></div>
          <div><label>STATUS</label><input class="field" name="fb_orders_status" value="<?= e((string)($fb['fields']['orders']['status'] ?? 'STATUS')) ?>"></div>
          <div><label>CLIENTE</label><input class="field" name="fb_orders_cliente" value="<?= e((string)($fb['fields']['orders']['cliente'] ?? 'CLIENTE')) ?>"></div>
          <div><label>DATA</label><input class="field" name="fb_orders_data" value="<?= e((string)($fb['fields']['orders']['data'] ?? 'DATA')) ?>"></div>
          <div><label>VLR_FRETE</label><input class="field" name="fb_orders_frete" value="<?= e((string)($fb['fields']['orders']['frete'] ?? 'VLR_FRETE')) ?>"></div>
          <div><label>VLR_TOTAL</label><input class="field" name="fb_orders_total" value="<?= e((string)($fb['fields']['orders']['total'] ?? 'VLR_TOTAL')) ?>"></div>
          <div><label>OBSERVACAO</label><input class="field" name="fb_orders_obs" value="<?= e((string)($fb['fields']['orders']['obs'] ?? 'OBSERVACAO')) ?>"></div>
          <div><label>TIPO</label><input class="field" name="fb_orders_tipo" value="<?= e((string)($fb['fields']['orders']['tipo'] ?? 'TIPO')) ?>"></div>
          <div><label>OPERACAO</label><input class="field" name="fb_orders_operacao" value="<?= e((string)($fb['fields']['orders']['operacao'] ?? 'OPERACAO')) ?>"></div>
          <div><label>VENDEDOR</label><input class="field" name="fb_orders_vendedor" value="<?= e((string)($fb['fields']['orders']['vendedor'] ?? 'VENDEDOR')) ?>"></div>
          <div><label>VLR_PRODUTOS</label><input class="field" name="fb_orders_vlr_produtos" value="<?= e((string)($fb['fields']['orders']['vlr_produtos'] ?? 'VLR_PRODUTOS')) ?>"></div>
          <div><label>SITUACAO</label><input class="field" name="fb_orders_situacao" value="<?= e((string)($fb['fields']['orders']['situacao'] ?? 'SITUACAO')) ?>"></div>
          <div><label>PLANO_CONTAS</label><input class="field" name="fb_orders_plano_contas" value="<?= e((string)($fb['fields']['orders']['plano_contas'] ?? 'PLANO_CONTAS')) ?>"></div>
          <div><label>TRANSPORTADORA</label><input class="field" name="fb_orders_transportadora" value="<?= e((string)($fb['fields']['orders']['transportadora'] ?? 'TRANSPORTADORA')) ?>"></div>
          <div><label>CFOP</label><input class="field" name="fb_orders_cfop" value="<?= e((string)($fb['fields']['orders']['cfop'] ?? 'CFOP')) ?>"></div>
          <div><label>DATA_ENTREGA</label><input class="field" name="fb_orders_data_entrega" value="<?= e((string)($fb['fields']['orders']['data_entrega'] ?? 'DATA_ENTREGA')) ?>"></div>
          <div><label>USUARIO_CADASTRO</label><input class="field" name="fb_orders_usuario_cadastro" value="<?= e((string)($fb['fields']['orders']['usuario_cadastro'] ?? 'USUARIO_CADASTRO')) ?>"></div>
          <div><label>CENTRO_CUSTOS</label><input class="field" name="fb_orders_centro_custos" value="<?= e((string)($fb['fields']['orders']['centro_custos'] ?? 'CENTRO_CUSTOS')) ?>"></div>
          <div><label>STATUS_ID</label><input class="field" name="fb_orders_status_id" value="<?= e((string)($fb['fields']['orders']['status_id'] ?? 'STATUS_ID')) ?>"></div>
        </div>
      </div>

      <div style="margin-top:16px;">
        <h4 class="form-section-title">Mapeamento ORCAMENTO_ITENS</h4>
        <div class="grid" style="grid-template-columns:repeat(4,1fr);gap:12px;">
          <div><label>IDX</label><input class="field" name="fb_items_idx" value="<?= e((string)($fb['fields']['items']['idx'] ?? 'IDX')) ?>"></div>
          <div><label>CODIGO</label><input class="field" name="fb_items_codigo_orcamento" value="<?= e((string)($fb['fields']['items']['codigo_orcamento'] ?? 'CODIGO')) ?>"></div>
          <div><label>EMPRESA</label><input class="field" name="fb_items_empresa" value="<?= e((string)($fb['fields']['items']['empresa'] ?? 'EMPRESA')) ?>"></div>
          <div><label>CLIENTE</label><input class="field" name="fb_items_cliente" value="<?= e((string)($fb['fields']['items']['cliente'] ?? 'CLIENTE')) ?>"></div>
          <div><label>REFERENCIA</label><input class="field" name="fb_items_referencia" value="<?= e((string)($fb['fields']['items']['referencia'] ?? 'REFERENCIA')) ?>"></div>
          <div><label>DESCRICAO</label><input class="field" name="fb_items_descricao" value="<?= e((string)($fb['fields']['items']['descricao'] ?? 'DESCRICAO')) ?>"></div>
          <div><label>QTDE</label><input class="field" name="fb_items_qtde" value="<?= e((string)($fb['fields']['items']['qtde'] ?? 'QTDE')) ?>"></div>
          <div><label>VLR_UNIT</label><input class="field" name="fb_items_vlr_unit" value="<?= e((string)($fb['fields']['items']['vlr_unit'] ?? 'VLR_UNIT')) ?>"></div>
          <div><label>VLR_TOTAL</label><input class="field" name="fb_items_vlr_total" value="<?= e((string)($fb['fields']['items']['vlr_total'] ?? 'VLR_TOTAL')) ?>"></div>
          <div><label>FRETE_RATEIO</label><input class="field" name="fb_items_frete_rateio" value="<?= e((string)($fb['fields']['items']['frete_rateio'] ?? 'FRETE_RATEIO')) ?>"></div>
          <div><label>TOTAL_COM_RATEIOS</label><input class="field" name="fb_items_total_com_rateios" value="<?= e((string)($fb['fields']['items']['total_com_rateios'] ?? 'TOTAL_COM_RATEIOS')) ?>"></div>
          <div><label>UND</label><input class="field" name="fb_items_und" value="<?= e((string)($fb['fields']['items']['und'] ?? 'UND')) ?>"></div>
          <div><label>TIPO</label><input class="field" name="fb_items_tipo" value="<?= e((string)($fb['fields']['items']['tipo'] ?? 'TIPO')) ?>"></div>
          <div><label>DATA</label><input class="field" name="fb_items_data" value="<?= e((string)($fb['fields']['items']['data'] ?? 'DATA')) ?>"></div>
          <div><label>DATA_VENDEDOR</label><input class="field" name="fb_items_data_vendedor" value="<?= e((string)($fb['fields']['items']['data_vendedor'] ?? 'DATA_VENDEDOR')) ?>"></div>
          <div><label>CODPROD</label><input class="field" name="fb_items_codprod" value="<?= e((string)($fb['fields']['items']['codprod'] ?? 'CODPROD')) ?>"></div>
          <div><label>PRC_VENDA</label><input class="field" name="fb_items_prc_venda" value="<?= e((string)($fb['fields']['items']['prc_venda'] ?? 'PRC_VENDA')) ?>"></div>
          <div><label>TRANSPORTADORA</label><input class="field" name="fb_items_transportadora" value="<?= e((string)($fb['fields']['items']['transportadora'] ?? 'TRANSPORTADORA')) ?>"></div>
          <div><label>CFOP</label><input class="field" name="fb_items_cfop" value="<?= e((string)($fb['fields']['items']['cfop'] ?? 'CFOP')) ?>"></div>
        </div>
      </div>

      <div style="margin-top:16px;">
        <h4 class="form-section-title">Padrões automáticos</h4>
        <div class="grid" style="grid-template-columns:repeat(5,1fr);gap:12px;">
          <div><label>TIPO pedido padrão</label><input class="field" name="fb_default_order_tipo" value="<?= e((string)($fb['defaults']['orders']['tipo'] ?? 'ORCAMENTO')) ?>"></div>
          <div><label>OPERAÇÃO padrão</label><input class="field" name="fb_default_order_operacao" type="number" value="<?= e((string)($fb['defaults']['orders']['operacao'] ?? 7)) ?>"></div>
          <div><label>VENDEDOR padrão</label><input class="field" name="fb_default_order_vendedor" type="number" value="<?= e((string)($fb['defaults']['orders']['vendedor'] ?? 2)) ?>"></div>
          <div><label>SITUACAO padrão</label><input class="field" name="fb_default_order_situacao" type="number" value="<?= e((string)($fb['defaults']['orders']['situacao'] ?? 1)) ?>"></div>
          <div><label>PLANO_CONTAS padrão</label><input class="field" name="fb_default_order_plano_contas" value="<?= e((string)($fb['defaults']['orders']['plano_contas'] ?? '1.1')) ?>"></div>
          <div><label>TRANSPORTADORA cabeçalho</label><input class="field" name="fb_default_order_transportadora" value="<?= e((string)($fb['defaults']['orders']['transportadora'] ?? 3)) ?>"></div>
          <div><label>CFOP cabeçalho</label><input class="field" name="fb_default_order_cfop" value="<?= e((string)($fb['defaults']['orders']['cfop'] ?? '5102')) ?>"></div>
          <div><label>USUÁRIO CADASTRO</label><input class="field" name="fb_default_order_usuario_cadastro" value="<?= e((string)($fb['defaults']['orders']['usuario_cadastro'] ?? 1)) ?>"></div>
          <div><label>CENTRO CUSTOS</label><input class="field" name="fb_default_order_centro_custos" value="<?= e((string)($fb['defaults']['orders']['centro_custos'] ?? '1')) ?>"></div>
          <div><label>STATUS_ID</label><input class="field" name="fb_default_order_status_id" value="<?= e((string)($fb['defaults']['orders']['status_id'] ?? 1)) ?>"></div>
          <div><label>UND padrão</label><input class="field" name="fb_default_und" value="<?= e((string)($fb['defaults']['items']['und'] ?? 'UN')) ?>"></div>
          <div><label>TIPO item padrão</label><input class="field" name="fb_default_tipo" value="<?= e((string)($fb['defaults']['items']['tipo'] ?? 'V')) ?>"></div>
          <div><label>TRANSPORTADORA padrão</label><input class="field" name="fb_default_transportadora" type="number" value="<?= e((string)($fb['defaults']['items']['transportadora'] ?? 3)) ?>"></div>
          <div><label>CFOP padrão</label><input class="field" name="fb_default_cfop" value="<?= e((string)($fb['defaults']['items']['cfop'] ?? '5102')) ?>"></div>
        </div>
        <div class="hint" style="margin-top:8px;">O exportador preenche automaticamente também PRC_VENDA, CODPROD, DATA/DATA_VENDEDOR, TRANSPORTADORA, CFOP, VLR_PRODUTOS, SITUACAO, PLANO_CONTAS, DATA_ENTREGA, USUARIO_CADASTRO, CENTRO_CUSTOS e STATUS_ID quando essas colunas existirem.</div>
      </div>

      <div style="display:flex;align-items:center;gap:10px;margin-top:16px;">
        <input id="fb_enabled" name="fb_enabled" type="checkbox" value="1" <?= !empty($fb['enabled']) ? 'checked' : '' ?>>
        <label for="fb_enabled" style="margin:0;">Ativar integração Firebird</label>
      </div>
      <div style="display:flex;align-items:flex-start;gap:10px;margin-top:12px;">
        <input id="fb_auto_export_on_order" name="fb_auto_export_on_order" type="checkbox" value="1" <?= !empty($fb['auto_export_on_order']) ? 'checked' : '' ?> style="margin-top:3px;">
        <label for="fb_auto_export_on_order" style="margin:0;">
          Enviar automaticamente para o Firebird ao gravar o pedido no Nexa Próspero
          <span class="hint" style="display:block;margin-top:4px;">Se ativado, o sistema tenta exportar o pedido logo após salvar. Se falhar, o pedido continua na fila de exportação para reprocessar depois.</span>
        </label>
      </div>
    </div>
    <?php endif; ?>

<div class="actions right" style="margin-top:16px;">
      <div class="actions-right">
        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar alterações</button>
      </div>
    </div>
  </form>
</div>

<?php if ($hasScaleModule): ?>
<script src="/assets/balanca.js?v=<?= (int)@filemtime(__DIR__ . '/../assets/balanca.js') ?>"></script>
<?php endif; ?>
<script>
(function(){
  const scaleCfgBase = <?= $scalePublicJson ?: '{}' ?>;
  const list = document.getElementById('bairrosList');
  const add = document.getElementById('btnAddBairro');

  function bindRemove(scope){
    (scope||document).querySelectorAll('[data-remove]').forEach(btn=>{
      if (btn.dataset.bound === '1') return;
      btn.dataset.bound = '1';
      btn.addEventListener('click', ()=> {
        if (confirm('Deseja realmente remover este bairro da taxa de entrega?')) {
          btn.closest('.bairros-row')?.remove();
        }
      });
    });
  }
  bindRemove();

  if(add && list){
    add.addEventListener('click', ()=>{
      const row = document.createElement('div');
      row.className = 'bairros-row';
      row.innerHTML = `
        <input class="field" name="bairros_nome[]" placeholder="Bairro">
        <input class="field" name="bairros_taxa[]" placeholder="0.00" inputmode="decimal">
        <button type="button" class="btn btn-mini btn-danger" data-remove>Remover</button>
      `;
      list.appendChild(row);
      bindRemove(row);
    });
  }

  function bindPreview(inputName, previewId, altText, fit){
    const input = document.querySelector(`input[name="${inputName}"]`);
    const preview = document.getElementById(previewId);
    if(!input || !preview) return;
    input.addEventListener('change', ()=>{
      const f = input.files && input.files[0];
      if(!f) return;
      let img = preview.querySelector('img');
      if(!img){
        preview.innerHTML = '';
        img = document.createElement('img');
        img.alt = altText;
        if(fit) img.style.objectFit = fit;
        if(previewId === 'pdvCheckoutLogoPreview'){
          img.style.width = '100%';
          img.style.height = '100%';
        }
        preview.appendChild(img);
      }
      const url = URL.createObjectURL(f);
      img.src = url;
      img.onload = ()=> URL.revokeObjectURL(url);
    });
  }
  function normalizeHex(value){
    value = String(value || '').trim().toUpperCase();
    if(value && value.charAt(0) !== '#') value = '#' + value;
    return /^#[0-9A-F]{6}$/.test(value) ? value : '';
  }

  function setColorField(name, value){
    const hex = normalizeHex(value);
    if(!hex) return false;
    const text = document.querySelector(`.js-color-text[data-color-name="${name}"]`);
    const picker = document.querySelector(`.js-color-picker[data-color-target="${name}"]`);
    if(text) text.value = hex;
    if(picker) picker.value = hex;
    return true;
  }

  function getColorField(name, fallback){
    const text = document.querySelector(`.js-color-text[data-color-name="${name}"]`);
    return normalizeHex(text ? text.value : '') || fallback;
  }

  document.querySelectorAll('.js-color-picker').forEach(input => {
    input.addEventListener('input', () => setColorField(input.dataset.colorTarget || '', input.value));
  });
  document.querySelectorAll('.js-color-text').forEach(input => {
    input.addEventListener('input', () => {
      const hex = normalizeHex(input.value);
      if(hex) setColorField(input.dataset.colorName || '', hex);
    });
    input.addEventListener('blur', () => {
      const hex = normalizeHex(input.value);
      if(hex) setColorField(input.dataset.colorName || '', hex);
    });
  });

  const btnCopyCatalogColors = document.getElementById('btnCopyCatalogColors');
  if(btnCopyCatalogColors){
    btnCopyCatalogColors.addEventListener('click', () => {
      setColorField('admin_primary_color', getColorField('brand_primary_color', '#0B1F5B'));
      setColorField('admin_secondary_color', getColorField('brand_secondary_color', '#2454D6'));
      setColorField('admin_accent_color', getColorField('brand_accent_color', '#00B8FF'));
      setColorField('admin_gradient_color', mixHex(getColorField('brand_primary_color', '#0B1F5B'), '#0F172A', .46));
      setColorField('admin_text_color', getColorField('brand_text_on_primary', '#FFFFFF'));
      const adminTheme = document.getElementById('admin_theme_enabled');
      if(adminTheme) adminTheme.checked = true;
    });
  }

  const btnCopyAdminFlowColors = document.getElementById('btnCopyAdminFlowColors');
  if(btnCopyAdminFlowColors){
    btnCopyAdminFlowColors.addEventListener('click', () => {
      setColorField('admin_flow_primary_color', getColorField('admin_primary_color', '#143A9C'));
      setColorField('admin_flow_secondary_color', getColorField('admin_secondary_color', '#2563EB'));
      setColorField('admin_flow_text_color', getColorField('admin_text_color', '#FFFFFF'));
      const flowTheme = document.getElementById('admin_flow_theme_enabled');
      if(flowTheme) flowTheme.checked = true;
    });
  }

  function hexToRgb(hex){
    hex = normalizeHex(hex);
    return hex ? [parseInt(hex.slice(1,3),16), parseInt(hex.slice(3,5),16), parseInt(hex.slice(5,7),16)] : [15,118,110];
  }
  function rgbToHex(r,g,b){
    const clamp = n => Math.max(0, Math.min(255, Math.round(n))).toString(16).padStart(2,'0').toUpperCase();
    return '#' + clamp(r) + clamp(g) + clamp(b);
  }
  function mixHex(hex, withHex, amount){
    const a = hexToRgb(hex), b = hexToRgb(withHex);
    amount = Math.max(0, Math.min(1, Number(amount) || 0));
    return rgbToHex(a[0] + ((b[0]-a[0]) * amount), a[1] + ((b[1]-a[1]) * amount), a[2] + ((b[2]-a[2]) * amount));
  }
  function luminance(hex){
    const rgb = hexToRgb(hex).map(v => {
      const c = v / 255;
      return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
    });
    return 0.2126 * rgb[0] + 0.7152 * rgb[1] + 0.0722 * rgb[2];
  }

  function paletteFromDominant(hex){
    let primary = normalizeHex(hex) || '#0F766E';
    const lum = luminance(primary);
    if(lum > .72) primary = mixHex(primary, '#0F172A', .24);
    if(lum < .08) primary = mixHex(primary, '#FFFFFF', .20);
    return {
      primary: primary,
      secondary: mixHex(primary, '#FFFFFF', .42),
      accent: mixHex(primary, luminance(primary) < .30 ? '#FFFFFF' : '#0F172A', .18),
      text: luminance(primary) > .45 ? '#0F172A' : '#FFFFFF'
    };
  }

  function extractPaletteFromImage(src){
    return new Promise((resolve, reject) => {
      const img = new Image();
      img.onload = () => {
        try{
          const maxSide = 96;
          const scale = Math.min(1, maxSide / Math.max(img.naturalWidth || img.width, img.naturalHeight || img.height));
          const w = Math.max(1, Math.round((img.naturalWidth || img.width) * scale));
          const h = Math.max(1, Math.round((img.naturalHeight || img.height) * scale));
          const canvas = document.createElement('canvas');
          canvas.width = w;
          canvas.height = h;
          const ctx = canvas.getContext('2d', {willReadFrequently:true});
          ctx.drawImage(img, 0, 0, w, h);
          const data = ctx.getImageData(0,0,w,h).data;
          const buckets = new Map();
          let avg = [0,0,0,0];
          for(let i=0;i<data.length;i+=4){
            const r=data[i], g=data[i+1], b=data[i+2], a=data[i+3];
            if(a < 150) continue;
            if(r > 242 && g > 242 && b > 242) continue;
            const max = Math.max(r,g,b), min = Math.min(r,g,b);
            const sat = max ? (max-min)/max : 0;
            const lumSimple = ((r*.299)+(g*.587)+(b*.114))/255;
            avg[0]+=r; avg[1]+=g; avg[2]+=b; avg[3]++;
            if(sat < .08 && lumSimple > .45) continue;
            const qr = Math.max(0, Math.min(255, Math.round(r/24)*24));
            const qg = Math.max(0, Math.min(255, Math.round(g/24)*24));
            const qb = Math.max(0, Math.min(255, Math.round(b/24)*24));
            const key = `${qr},${qg},${qb}`;
            const score = 1 + (sat * 1.8) + (1 - Math.abs(lumSimple - .48));
            const old = buckets.get(key) || {score:0, r:qr, g:qg, b:qb};
            old.score += score;
            buckets.set(key, old);
          }
          let best = null;
          buckets.forEach(v => { if(!best || v.score > best.score) best = v; });
          if(best) return resolve(paletteFromDominant(rgbToHex(best.r,best.g,best.b)));
          if(avg[3] > 0) return resolve(paletteFromDominant(rgbToHex(avg[0]/avg[3], avg[1]/avg[3], avg[2]/avg[3])));
          reject(new Error('Não foi possível identificar uma cor predominante na logo.'));
        } catch(err){ reject(err); }
      };
      img.onerror = () => reject(new Error('Não foi possível carregar a logo.'));
      img.src = src;
    });
  }

  async function applyLogoColors(){
    const fileInput = document.querySelector('input[name="logo"]');
    const previewImg = document.querySelector('#logoPreview img');
    let src = '';
    let revoke = '';
    if(fileInput && fileInput.files && fileInput.files[0]){
      revoke = URL.createObjectURL(fileInput.files[0]);
      src = revoke;
    } else if(previewImg && previewImg.src) {
      src = previewImg.src;
    }
    if(!src){
      alert('Cadastre ou selecione uma logo antes de usar esta opção.');
      return;
    }
    try{
      const palette = await extractPaletteFromImage(src);
      setColorField('brand_primary_color', palette.primary);
      setColorField('brand_secondary_color', palette.secondary);
      setColorField('brand_accent_color', palette.accent);
      setColorField('brand_text_on_primary', palette.text);
      const follow = document.getElementById('brand_follow_logo_colors');
      if(follow) follow.checked = true;
    } catch(err){
      alert((err && err.message) ? err.message : 'Não foi possível ler as cores da logo.');
    } finally {
      if(revoke) URL.revokeObjectURL(revoke);
    }
  }

  const btnApplyLogoColors = document.getElementById('btnApplyLogoColors');
  if(btnApplyLogoColors) btnApplyLogoColors.addEventListener('click', applyLogoColors);

  function currentScaleConfig(){
    const get = name => { const el = document.querySelector('[name="'+name+'"]'); return el ? el.value : ''; };
    const checked = name => { const el = document.querySelector('[name="'+name+'"]'); return !!(el && el.checked); };
    return Object.assign({}, scaleCfgBase || {}, {
      enabled: checked('scale_enabled'),
      model: get('scale_model') || 'prix_3_fit',
      connection: get('scale_connection') || 'webserial',
      protocol: get('scale_protocol') || 'auto',
      requestMode: get('scale_request_mode') || 'enq',
      baudRate: parseInt(get('scale_baud_rate') || '2400', 10) || 2400,
      dataBits: parseInt(get('scale_data_bits') || '8', 10) || 8,
      parity: get('scale_parity') || 'none',
      stopBits: parseInt(get('scale_stop_bits') || '1', 10) || 1,
      decimalPlaces: parseInt(get('scale_decimal_places') || '3', 10) || 3,
      stableOnly: checked('scale_stable_only'),
      timeoutMs: parseInt(get('scale_timeout_ms') || '2500', 10) || 2500,
      minWeight: Number(String(get('scale_min_weight') || '0').replace(',', '.')) || 0,
      autoFillQuantity: checked('scale_auto_fill_quantity'),
      portHint: get('scale_port_hint') || ''
    });
  }

  const scaleModelEl = document.querySelector('[name="scale_model"]');
  if(scaleModelEl){
    scaleModelEl.addEventListener('change', function(){
      if(this.value !== 'urano_udc_coe') return;
      const set = (name, value) => { const el = document.querySelector('[name="'+name+'"]'); if(el) el.value = String(value); };
      set('scale_connection', 'webserial');
      set('scale_protocol', 'auto');
      set('scale_request_mode', 'enq');
      set('scale_baud_rate', '9600');
      set('scale_data_bits', '8');
      set('scale_parity', 'none');
      set('scale_stop_bits', '2');
      set('scale_decimal_places', '3');
      set('scale_timeout_ms', '2500');
      const stable = document.querySelector('[name="scale_stable_only"]'); if(stable) stable.checked = true;
    });
  }

  const btnScaleTest = document.getElementById('btnScaleTest');
  if(btnScaleTest){
    const status = document.getElementById('scaleTestStatus');
    const weight = document.getElementById('scaleTestWeight');
    const setStatus = (msg, ok) => {
      if(status){ status.textContent = msg; status.style.color = ok === true ? '#047857' : (ok === false ? '#b91c1c' : '#334155'); }
    };
    const setWeight = val => { if(weight && window.NexaScale) weight.textContent = window.NexaScale.moneyWeight(val); };
    window.addEventListener('nexa-scale-status', e => {
      if(e.detail && e.detail.message) setStatus(e.detail.message);
      if(e.detail && typeof e.detail.weight === 'number') setWeight(e.detail.weight);
    });
    btnScaleTest.addEventListener('click', async () => {
      if(!window.NexaScale){ setStatus('Arquivo de integração da balança não foi carregado.', false); return; }
      const cfg = currentScaleConfig();
      if(!cfg.enabled){ setStatus('Marque a opção “Habilitar leitura de balança” antes de testar.', false); return; }
      if(!window.NexaScale.isSupported()){ setStatus('Web Serial indisponível. Use Chrome ou Edge em HTTPS/local e verifique as permissões do navegador.', false); return; }
      btnScaleTest.disabled = true;
      const oldText = btnScaleTest.textContent;
      btnScaleTest.textContent = 'Lendo...';
      try{
        setStatus('Selecione a porta da balança, se o navegador solicitar. Depois aguarde o peso estabilizar.');
        const w = await window.NexaScale.readWeight(cfg);
        setWeight(w);
        setStatus('Teste concluído: peso lido com sucesso.', true);
      } catch(err){
        setStatus((err && err.message) ? err.message : 'Não foi possível ler o peso.', false);
      } finally {
        btnScaleTest.disabled = false;
        btnScaleTest.textContent = oldText;
      }
    });
  }

  bindPreview('logo','logoPreview','Pré-visualização da logo','contain');
  bindPreview('pdv_checkout_logo','pdvCheckoutLogoPreview','Pré-visualização da logo do PDV Checkout','contain');
  bindPreview('cover','coverPreview','Pré-visualização da capa','cover');
})();
</script>

<?php admin_footer(); ?>
