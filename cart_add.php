<?php
session_start();

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/product_options.php';
require_once __DIR__ . '/config/product_half_pizza.php';
require_once __DIR__ . '/config/products_repository.php';

$companySlug = defined('COMPANY_SLUG') ? (string)COMPANY_SLUG : (string)($_GET['company'] ?? ($_SESSION['company_slug'] ?? ''));
$company = null;
if ($companySlug !== '' || !tenant_is_base_domain(tenant_host_name())) {
  $company = tenant_resolve_company($pdo, $companySlug);
  $_SESSION['company_slug'] = (string)($company['slug'] ?? $companySlug);
}
$companyId = $company ? tenant_company_id($company) : 0;
$cartUrl = $company ? company_catalog_url($company, 'carrinho.php') : '/carrinho.php';

$id = (int)($_POST['id'] ?? 0);
$qtd = max(1, (int)($_POST['qtd'] ?? 1));
$halfId = (int)($_POST['half_id'] ?? 0);
$itemObs = trim((string)($_POST['item_obs'] ?? ''));
if (mb_strlen($itemObs) > 500) {
  $itemObs = mb_substr($itemObs, 0, 500);
}

// A interface pode esconder um item, mas o POST do carrinho também deve validar.
// Evita adicionar por link antigo, requisição manual ou como 2º sabor da pizza.
try {
  $available = $id > 0 && products_order_catalog_available_db($companyId, $id);
  if ($available && $halfId > 0 && $halfId !== $id) {
    $available = products_order_catalog_available_db($companyId, $halfId);
  }
} catch (Throwable $e) {
  $available = false; // Na dúvida, não aceita novo pedido público.
}
if (!$available) {
  http_response_code(303);
  header('Location: ' . ($company ? company_catalog_url($company, '') : '/'));
  exit;
}

if (!isset($_SESSION['cart'])) $_SESSION['cart'] = [];
if (!isset($_SESSION['cart_custom'])) $_SESSION['cart_custom'] = [];
if (!isset($_SESSION['cart_obs'])) $_SESSION['cart_obs'] = [];

// Validação server-side da pizza meio a meio. O catálogo só permite meio a meio
// quando a categoria estiver marcada e os dois sabores pertencerem à mesma categoria.
if ($id > 0 && $halfId > 0 && $halfId !== $id) {
  $halfError = '';
  if ($companyId <= 0 || !product_half_pizza_ensure_schema($pdo, false)) {
    $halfError = 'A opção de pizza meio a meio não está disponível para este produto.';
  } else {
    try {
      $stHalf = $pdo->prepare('SELECT id, categoria, COALESCE(category_allow_half_pizza,0) AS allow_half FROM produtos WHERE company_id = ? AND ativo = 1 AND id IN (?, ?)');
      $stHalf->execute([$companyId, $id, $halfId]);
      $rowsHalf = $stHalf->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $byId = [];
      foreach ($rowsHalf as $rowHalf) $byId[(int)($rowHalf['id'] ?? 0)] = $rowHalf;
      $baseHalf = $byId[$id] ?? null;
      $secondHalf = $byId[$halfId] ?? null;
      if (!$baseHalf || !$secondHalf) {
        $halfError = 'Não foi possível localizar os dois sabores selecionados.';
      } else {
        $baseCategory = trim((string)($baseHalf['categoria'] ?? ''));
        $secondCategory = trim((string)($secondHalf['categoria'] ?? ''));
        $enabledCategories = product_half_pizza_enabled_categories($pdo, $companyId);
        if (!product_half_pizza_category_enabled($enabledCategories, $baseCategory)) {
          $halfError = 'Esta categoria não está habilitada para pizza meio a meio.';
        } elseif (product_half_pizza_normalize_category($baseCategory) !== product_half_pizza_normalize_category($secondCategory)) {
          $halfError = 'O segundo sabor precisa pertencer à mesma categoria da pizza selecionada.';
        }
      }
    } catch (Throwable $e) {
      $halfError = 'Não foi possível validar a pizza meio a meio.';
    }
  }

  if ($halfError !== '') {
    $back = $company ? company_catalog_url($company, 'produto.php?id=' . $id . '&err=' . rawurlencode($halfError)) : ('/produto.php?id=' . $id . '&err=' . rawurlencode($halfError));
    header('Location: ' . $back);
    exit;
  }
}

// Pizza meio a meio: mantém os dois sabores e também as opções/adicionais selecionados.
if ($id > 0 && $halfId > 0 && $halfId !== $id) {
  $postedOptions = (array)($_POST['add_options'] ?? []);
  $details = [];
  $optionsTotal = 0.0;
  $hasConfiguredGroups = false;

  if ($companyId > 0) {
    try {
      $groups = product_options_load($pdo, $companyId, $id, true);
      $hasConfiguredGroups = !empty($groups);
    } catch (Throwable $e) {
      $hasConfiguredGroups = false;
    }
  }

  if ($hasConfiguredGroups) {
    $error = null;
    $details = product_options_validate_selection($pdo, $companyId, $id, $postedOptions, $error);
    if ($error !== null) {
      $back = $company ? company_catalog_url($company, 'produto.php?id=' . $id . '&err=' . rawurlencode($error)) : ('/produto.php?id=' . $id . '&err=' . rawurlencode($error));
      header('Location: ' . $back);
      exit;
    }
    $optionsTotal = (float)($details['total'] ?? 0);
  }

  // A assinatura impede que duas pizzas com os mesmos sabores, mas adicionais/observações
  // diferentes, sejam mescladas em um único item do carrinho.
  $signaturePayload = [
    'a' => $id,
    'b' => $halfId,
    'options' => $details,
    'obs' => $itemObs,
  ];
  $signature = md5(json_encode($signaturePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
  $key = 'half_' . $id . '_' . $halfId . '_' . substr($signature, 0, 16);

  if (!isset($_SESSION['cart_custom'][$key])) {
    $_SESSION['cart_custom'][$key] = [
      'type' => 'half',
      'a' => $id,
      'b' => $halfId,
      'qtd' => 0,
      'options' => $details,
      'options_total' => $optionsTotal,
      'obs' => $itemObs,
    ];
  }
  $_SESSION['cart_custom'][$key]['qtd'] = (int)($_SESSION['cart_custom'][$key]['qtd'] ?? 0) + $qtd;
  $_SESSION['cart_custom'][$key]['options'] = $details;
  $_SESSION['cart_custom'][$key]['options_total'] = $optionsTotal;
  $_SESSION['cart_custom'][$key]['obs'] = $itemObs;
} elseif ($id > 0) {
  $postedOptions = (array)($_POST['add_options'] ?? []);
  $hasConfiguredGroups = false;

  if ($companyId > 0) {
    try {
      $groups = product_options_load($pdo, $companyId, $id, true);
      $hasConfiguredGroups = !empty($groups);
    } catch (Throwable $e) {
      $hasConfiguredGroups = false;
    }
  }

  if ($hasConfiguredGroups) {
    $error = null;
    $details = product_options_validate_selection($pdo, $companyId, $id, $postedOptions, $error);
    if ($error !== null) {
      $back = $company ? company_catalog_url($company, 'produto.php?id=' . $id . '&err=' . rawurlencode($error)) : ('/produto.php?id=' . $id . '&err=' . rawurlencode($error));
      header('Location: ' . $back);
      exit;
    }

    $signature = md5(json_encode($details, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . '|' . $itemObs);
    $key = 'cfg_' . $id . '_' . substr($signature, 0, 16);
    if (!isset($_SESSION['cart_custom'][$key])) {
      $_SESSION['cart_custom'][$key] = [
        'type' => 'configured',
        'id' => $id,
        'qtd' => 0,
        'options' => $details,
        'options_total' => (float)($details['total'] ?? 0),
        'obs' => '',
      ];
    }
    $_SESSION['cart_custom'][$key]['qtd'] = (int)($_SESSION['cart_custom'][$key]['qtd'] ?? 0) + $qtd;
    $_SESSION['cart_custom'][$key]['options'] = $details;
    $_SESSION['cart_custom'][$key]['options_total'] = (float)($details['total'] ?? 0);
    $_SESSION['cart_custom'][$key]['obs'] = $itemObs;
  } else {
    if (!isset($_SESSION['cart'][$id])) $_SESSION['cart'][$id] = 0;
    $_SESSION['cart'][$id] += $qtd;
    if ($itemObs !== '') {
      $_SESSION['cart_obs'][$id] = $itemObs;
    }
  }
}

header('Location: ' . $cartUrl);
