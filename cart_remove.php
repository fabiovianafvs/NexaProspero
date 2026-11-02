<?php
session_start();
require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
$companySlug = (string)($_GET['company'] ?? ($_SESSION['company_slug'] ?? ''));
$company = null;
if ($companySlug !== '' || !tenant_is_base_domain(tenant_host_name())) {
  $company = tenant_resolve_company($pdo, $companySlug);
  $_SESSION['company_slug'] = (string)($company['slug'] ?? $companySlug);
}
$redir = $company ? company_catalog_url($company, 'carrinho.php') : '/carrinho.php';

$id = (int)($_GET['id'] ?? 0);
$key = (string)($_GET['k'] ?? '');
if ($id > 0 && isset($_SESSION['cart'][$id])) {
  unset($_SESSION['cart'][$id], $_SESSION['cart_obs'][$id]);
}
if ($key !== '' && isset($_SESSION['cart_custom'][$key])) {
  unset($_SESSION['cart_custom'][$key]);
}
header('Location: ' . $redir);
