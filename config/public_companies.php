<?php
declare(strict_types=1);

require_once __DIR__ . '/pdo.php';
require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/load_config.php';

function public_companies_app_base_url(): string {
  $base = trim((string)(getenv('NEXA_APP_URL') ?: getenv('APP_PUBLIC_URL') ?: ''));
  if ($base === '') {
    // Domínio onde o app e os uploads ficam publicados.
    $base = 'https://app.nexaprospero.com.br';
  }
  return rtrim($base, '/');
}

function public_companies_upload_url(string $folder, string $file): string {
  $file = trim($file);
  if ($file === '') return '';
  if (preg_match('~^https?://~i', $file)) return $file;

  $file = basename($file);
  $folder = trim($folder, '/');
  return public_companies_app_base_url() . '/uploads/' . $folder . '/' . rawurlencode($file);
}

function public_company_visibility_sql(): string {
  return "(
    c.status IS NULL OR c.status IN ('active','trial')
  )";
}

function public_companies_fetch(PDO $pdo, int $limit = 0): array {
  $sql = "
    SELECT c.id, c.name, c.slug, c.subdomain, c.custom_domain, c.status,
           c.email, c.whatsapp,
           (
             SELECT COUNT(*)
             FROM pedidos p
             WHERE p.company_id = c.id
           ) AS total_pedidos
    FROM companies c
    WHERE " . public_company_visibility_sql() . "
    ORDER BY c.id DESC
  ";

  if ($limit > 0) {
    $sql .= ' LIMIT ' . (int)$limit;
  }

  $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
  $items = [];

  foreach ($rows as $row) {
    $companyId = (int)($row['id'] ?? 0);
    if ($companyId <= 0) continue;

    $biz = [];
    try {
      $biz = load_business_config($companyId);
    } catch (Throwable $e) {
      $biz = [];
    }

    $logoFile = trim((string)($biz['logo']['file'] ?? ''));
    $coverFile = trim((string)($biz['cover']['file'] ?? ''));
    $catalogTitle = trim((string)($biz['brand']['catalog_title'] ?? '')) ?: trim((string)($row['name'] ?? ''));
    $catalogSubtitle = trim((string)($biz['brand']['catalog_subtitle'] ?? ''));

    $row['catalog_title'] = $catalogTitle;
    $row['catalog_subtitle'] = $catalogSubtitle;
    $row['logo_url'] = $logoFile !== '' ? public_companies_upload_url('logos', $logoFile) : '';
    $row['cover_url'] = $coverFile !== '' ? public_companies_upload_url('covers', $coverFile) : '';
    $row['catalog_url'] = company_catalog_url($row);

    $items[] = $row;
  }

  return $items;
}

function public_companies_stats(PDO $pdo): array {
  $stats = [
    'empresas_ativas' => 0,
    'pedidos_total' => 0,
    'subdominios_ativos' => 0,
  ];

  try {
    $stats['empresas_ativas'] = (int)$pdo->query('SELECT COUNT(*) FROM companies c WHERE ' . public_company_visibility_sql())->fetchColumn();
  } catch (Throwable $e) {
  }

  try {
    $stats['pedidos_total'] = (int)$pdo->query('SELECT COUNT(*) FROM pedidos')->fetchColumn();
  } catch (Throwable $e) {
  }

  try {
    $stats['subdominios_ativos'] = (int)$pdo->query("SELECT COUNT(*) FROM companies c WHERE " . public_company_visibility_sql() . " AND COALESCE(NULLIF(TRIM(subdomain), ''), NULLIF(TRIM(custom_domain), '')) IS NOT NULL")->fetchColumn();
  } catch (Throwable $e) {
  }

  return $stats;
}
