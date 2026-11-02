<?php
declare(strict_types=1);

if (!function_exists('nexa_print_logo_url')) {
  function nexa_print_logo_url(int $companyId): string {
    if ($companyId <= 0) return '';
    try {
      $biz = function_exists('load_business_config') ? (array)load_business_config($companyId) : [];
      $logo = (array)($biz['logo'] ?? []);
      if (array_key_exists('enabled', $logo) && empty($logo['enabled'])) return '';
      $file = basename(trim((string)($logo['file'] ?? '')));
      if ($file === '') return '';
      $path = __DIR__ . '/../uploads/logos/' . $file;
      if (!is_file($path)) return '';
      return '/uploads/logos/' . rawurlencode($file);
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('nexa_print_brand_name')) {
  function nexa_print_brand_name(int $companyId, string $fallback = ''): string {
    try {
      $biz = function_exists('load_business_config') ? (array)load_business_config($companyId) : [];
      $name = trim((string)($biz['brand']['catalog_title'] ?? ''));
      if ($name !== '') return $name;
    } catch (Throwable $e) {}
    return trim($fallback);
  }
}
