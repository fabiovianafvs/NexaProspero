<?php
declare(strict_types=1);

require __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/load_config.php';
require_once __DIR__ . '/saas/saas_guard.php';

$companySlug = (string)($_GET['company'] ?? '');
$company = tenant_resolve_company($pdo, $companySlug);
saas_enforce_company_access($pdo, $company, 'catalog');

define('COMPANY_ID', tenant_company_id($company));
define('COMPANY_SLUG', (string)$company['slug']);
define('COMPANY_SUBDOMAIN', (string)($company['subdomain'] ?? ''));
define('COMPANY_CUSTOM_DOMAIN', (string)($company['custom_domain'] ?? ''));

if (session_status() === PHP_SESSION_ACTIVE) {
  $_SESSION['company_slug'] = (string)COMPANY_SLUG;
}

$app = load_app_config(COMPANY_ID);
$biz = load_business_config(COMPANY_ID);
$firebird = load_firebird_config(COMPANY_ID);

$brandTitle = trim((string)($biz['brand']['catalog_title'] ?? ''));
if ($brandTitle !== '') {
  $app['site_name'] = $brandTitle;
} elseif (!empty($company['name'])) {
  $app['site_name'] = (string)$company['name'];
}

require __DIR__ . '/config/products_repository.php';

$biz['timezone'] = app_apply_company_timezone($pdo, (string)($biz['timezone'] ?? 'America/Sao_Paulo'));
