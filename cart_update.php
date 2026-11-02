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
$isAjax = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
  || str_contains(strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? '')), 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  foreach ((array)($_POST['qtd'] ?? []) as $id => $qtd) {
    $id = (int)$id;
    $qtd = max(0, (int)$qtd);
    if ($id <= 0) continue;
    if ($qtd <= 0) {
      unset($_SESSION['cart'][$id], $_SESSION['cart_obs'][$id]);
    } else {
      $_SESSION['cart'][$id] = $qtd;
    }
  }

  foreach ((array)($_POST['qtd_custom'] ?? []) as $key => $qtd) {
    $key = (string)$key;
    $qtd = max(0, (int)$qtd);
    if ($key === '' || !isset($_SESSION['cart_custom'][$key])) continue;
    if ($qtd <= 0) {
      unset($_SESSION['cart_custom'][$key]);
    } else {
      $_SESSION['cart_custom'][$key]['qtd'] = $qtd;
    }
  }

  foreach ((array)($_POST['obs'] ?? []) as $id => $obs) {
    $id = (int)$id;
    if ($id <= 0) continue;
    $_SESSION['cart_obs'][$id] = trim((string)$obs);
  }

  foreach ((array)($_POST['obs_custom'] ?? []) as $key => $obs) {
    $key = (string)$key;
    if ($key === '' || !isset($_SESSION['cart_custom'][$key])) continue;
    $_SESSION['cart_custom'][$key]['obs'] = trim((string)$obs);
  }
}

if ($isAjax) {
  header('Content-Type: application/json; charset=utf-8');
  echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

header('Location: ' . $redir);
