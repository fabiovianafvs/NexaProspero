<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/config_store.php';
require_once __DIR__ . '/config/saas_billing.php';
require_once __DIR__ . '/config/public_plans.php';
require_once __DIR__ . '/config/ead_integration.php';
require_once __DIR__ . '/config/saas_whatsapp_service.php';
require_once __DIR__ . '/config/saas_modules.php';
require_once __DIR__ . '/config/company_identity.php';
require_once __DIR__ . '/config/resellers.php';
require_once __DIR__ . '/config/reseller_integrations.php';
require_once __DIR__ . '/config/company_sequences.php';
require_once __DIR__ . '/config/saas_subscription_bridge.php';
require_once __DIR__ . '/config/company_signup_defaults.php';
require_once __DIR__ . '/config/password_setup.php';

function slugify(string $s): string {
  return company_subdomain_normalize($s);
}

function random_pass(int $len = 10): string {
  $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#$%';
  $out = '';
  for ($i = 0; $i < $len; $i++) $out .= $alphabet[random_int(0, strlen($alphabet) - 1)];
  return $out;
}

function has_column(PDO $pdo, string $table, string $column): bool {
  $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
  $st->execute([$table, $column]);
  return (bool)$st->fetchColumn();
}

function ensure_company_column(PDO $pdo, string $column, string $definition): void {
  try {
    if (!has_column($pdo, 'companies', $column)) {
      $pdo->exec('ALTER TABLE companies ADD COLUMN ' . $definition);
    }
  } catch (Throwable $e) {
    // Mantém a página funcionando mesmo quando o usuário do banco não possui permissão de ALTER.
  }
}

function ensure_company_signup_columns(PDO $pdo): void {
  // Campos usados pelo catálogo/subdomínio. Mantém compatibilidade quando a migração v32 ainda não foi aplicada.
  ensure_company_column($pdo, 'subdomain', "subdomain VARCHAR(100) NULL AFTER slug");
  ensure_company_column($pdo, 'custom_domain', "custom_domain VARCHAR(190) NULL AFTER subdomain");
  ensure_company_column($pdo, 'person_type', "person_type ENUM('pf','pj') NULL AFTER whatsapp");
  ensure_company_column($pdo, 'cpf', "cpf VARCHAR(20) NULL AFTER person_type");
  ensure_company_column($pdo, 'cnpj', "cnpj VARCHAR(20) NULL AFTER cpf");
  ensure_company_column($pdo, 'nome_fantasia', "nome_fantasia VARCHAR(180) NULL AFTER cnpj");
  ensure_company_column($pdo, 'razao_social', "razao_social VARCHAR(180) NULL AFTER nome_fantasia");
  ensure_company_column($pdo, 'celular', "celular VARCHAR(30) NULL AFTER razao_social");
  ensure_company_column($pdo, 'cep', "cep VARCHAR(12) NULL AFTER celular");
  ensure_company_column($pdo, 'endereco', "endereco VARCHAR(180) NULL AFTER cep");
  ensure_company_column($pdo, 'numero', "numero VARCHAR(20) NULL AFTER endereco");
  ensure_company_column($pdo, 'complemento', "complemento VARCHAR(120) NULL AFTER numero");
  ensure_company_column($pdo, 'bairro', "bairro VARCHAR(120) NULL AFTER complemento");
  ensure_company_column($pdo, 'cidade', "cidade VARCHAR(120) NULL AFTER bairro");
  ensure_company_column($pdo, 'estado', "estado CHAR(2) NULL AFTER cidade");
  ensure_company_column($pdo, 'desired_plan_id', "desired_plan_id INT UNSIGNED NULL AFTER status");
  ensure_company_column($pdo, 'commercial_plan_id', "commercial_plan_id INT UNSIGNED NULL AFTER desired_plan_id");
}

function load_public_plans(PDO $pdo): array {
  return public_plans_fetch($pdo);
}

function digits_only(string $value): string {
  return preg_replace('/\D+/', '', $value) ?? '';
}

function nexa_signup_selected_codes(PDO $pdo, array $input, int $resellerCompanyId = 0): array {
  $lineValue = (string)($input['nexa_line'] ?? $input['linha'] ?? '');
  $line = ($lineValue === 'gestao') ? 'gestao' : 'food';
  $requested = $input['nexa_modules'] ?? $input['modules'] ?? [];
  if (!is_array($requested)) $requested = [$requested];
  $requested = array_values(array_unique(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), $requested))));

  $allowed = [];
  foreach (reseller_module_catalog($pdo, $resellerCompanyId, true) as $module) {
    if (!empty($module['reseller_price_active'])) $allowed[] = strtolower((string)$module['code']);
  }
  $allowed = array_values(array_unique(array_filter($allowed)));

  $codes = [];
  if ($line === 'food') $codes[] = 'delivery';
  foreach ($requested as $code) if (in_array($code, $allowed, true)) $codes[] = $code;
  if ($line === 'gestao' && in_array('gestao', $allowed, true)) $codes[] = 'gestao';

  // Gestão é vendida como um único produto, mas libera os módulos operacionais internos.
  if (in_array('gestao', $codes, true)) $codes = array_merge($codes, ['fiscal', 'financeiro']);
  return array_values(array_unique($codes));
}

function nexa_signup_module_ids(PDO $pdo, array $codes): array {
  $ids = [];
  foreach ($codes as $code) {
    $id = saas_module_id_by_code($pdo, (string)$code);
    if ($id > 0) $ids[] = $id;
  }
  return array_values(array_unique($ids));
}


function nexa_signup_selected_quantities(PDO $pdo, array $input, int $resellerCompanyId, array $selectedCodes): array {
  $raw = $input['nexa_module_quantities'] ?? $input['module_quantities'] ?? [];
  if (!is_array($raw)) $raw = [];
  $raw = array_change_key_case($raw, CASE_LOWER);

  $catalog = [];
  foreach (reseller_module_catalog($pdo, $resellerCompanyId, false) as $module) {
    $code = strtolower(trim((string)($module['code'] ?? '')));
    if ($code !== '') $catalog[$code] = $module;
  }

  $quantities = [];
  foreach ($selectedCodes as $selectedCode) {
    $code = strtolower(trim((string)$selectedCode));
    if ($code === '') continue;
    $module = $catalog[$code] ?? null;
    if (!$module || empty($module['allow_quantity'])) {
      $quantities[$code] = 1;
      continue;
    }

    $min = max(1, (int)($module['quantity_min'] ?? 1));
    $max = max($min, (int)($module['quantity_max'] ?? 99));
    $default = max($min, min($max, (int)($module['quantity_default'] ?? 1)));
    $quantities[$code] = max($min, min($max, (int)($raw[$code] ?? $default)));
  }
  return $quantities;
}

function nexa_signup_module_labels(PDO $pdo, array $codes, array $quantities, int $resellerCompanyId = 0): array {
  $catalogNames = [];
  foreach (reseller_module_catalog($pdo, $resellerCompanyId, false) as $module) {
    $code = strtolower(trim((string)($module['code'] ?? '')));
    if ($code !== '') $catalogNames[$code] = trim((string)($module['name'] ?? ''));
  }

  $labels = [];
  foreach ($codes as $codeValue) {
    $code = strtolower(trim((string)$codeValue));
    if ($code === '') continue;
    $label = $catalogNames[$code] ?? '';
    if ($label === '') $label = saas_module_label($code);
    $quantity = max(1, (int)($quantities[$code] ?? 1));
    $labels[] = $quantity > 1 ? $quantity . ' × ' . $label : $label;
  }
  return $labels;
}

function nexa_signup_quote_summary(PDO $pdo, array $input, array $quantities, int $resellerCompanyId = 0, ?array $deliveryPlan = null): array {
  $catalog = [];
  foreach (reseller_module_catalog($pdo, $resellerCompanyId, false) as $module) {
    $code = strtolower(trim((string)($module['code'] ?? '')));
    if ($code !== '') $catalog[$code] = $module;
  }

  $requested = $input['nexa_modules'] ?? $input['modules'] ?? [];
  if (!is_array($requested)) $requested = [$requested];
  $codes = array_values(array_unique(array_filter(array_map(static fn($v) => strtolower(trim((string)$v)), $requested))));
  $line = ((string)($input['nexa_line'] ?? $input['linha'] ?? '') === 'gestao') ? 'gestao' : 'food';
  if ($line === 'gestao' && !in_array('gestao', $codes, true)) $codes[] = 'gestao';

  $items = [];
  $monthly = 0.0;
  $setup = 0.0;

  if ($line === 'food' && $deliveryPlan) {
    $price = (float)($deliveryPlan['effective_sale_price'] ?? $deliveryPlan['price'] ?? 0);
    $setupPrice = (float)($deliveryPlan['effective_setup_price'] ?? $deliveryPlan['setup_price'] ?? 0);
    $items[] = [
      'code' => 'delivery',
      'label' => trim((string)($deliveryPlan['name'] ?? 'Delivery')) ?: 'Delivery',
      'quantity' => 1,
      'monthly' => $price,
      'setup' => $setupPrice,
    ];
    $monthly += $price;
    $setup += $setupPrice;
  }

  foreach ($codes as $code) {
    if ($code === 'delivery') continue;
    $module = $catalog[$code] ?? null;
    if (!$module || empty($module['reseller_price_active'])) continue;
    $quantity = !empty($module['allow_quantity']) ? max(1, (int)($quantities[$code] ?? 1)) : 1;
    $price = (float)($module['effective_sale_price'] ?? $module['monthly_price'] ?? 0);
    $setupPrice = (float)($module['effective_setup_price'] ?? $module['setup_price'] ?? 0);
    $label = trim((string)($module['name'] ?? '')) ?: saas_module_label($code);
    $items[] = [
      'code' => $code,
      'label' => $quantity > 1 ? $quantity . ' × ' . $label : $label,
      'quantity' => $quantity,
      'monthly' => $price * $quantity,
      'setup' => $setupPrice * $quantity,
    ];
    $monthly += $price * $quantity;
    $setup += $setupPrice * $quantity;
  }

  return ['items' => $items, 'monthly' => round($monthly, 2), 'setup' => round($setup, 2)];
}


function nexa_signup_delivery_key(array $input): string {
  $raw = strtolower(trim((string)($input['nexa_delivery'] ?? $input['delivery'] ?? 'start')));
  $raw = str_replace(['_', ' '], '-', $raw);
  return match ($raw) {
    'essencial', 'delivery-start', 'start-free', 'startfree' => 'start',
    'profissional', 'delivery-pro' => 'pro',
    'enterprise', 'delivery-premium' => 'premium',
    default => in_array($raw, ['start','pro','premium'], true) ? $raw : 'start',
  };
}

function nexa_signup_delivery_public_key(string $deliveryKey): string {
  return match ($deliveryKey) {
    'start' => 'essencial',
    'pro' => 'profissional',
    'premium' => 'enterprise',
    default => '',
  };
}

function nexa_signup_prepare_delivery_plan(PDO $pdo, array $plan, string $deliveryKey, int $resellerCompanyId = 0): ?array {
  $publicKey = function_exists('public_plan_key') ? public_plan_key($plan) : strtolower((string)($plan['slug'] ?? ''));
  $expectedPublicKey = nexa_signup_delivery_public_key($deliveryKey);
  if ($expectedPublicKey === '' || $publicKey !== $expectedPublicKey) return null;

  if (function_exists('public_plan_apply_defaults')) {
    $plan = public_plan_apply_defaults($plan, $publicKey);
  }
  $plan['delivery_key'] = $deliveryKey;
  $plan['effective_sale_price'] = (float)($plan['price'] ?? 0);
  $plan['effective_setup_price'] = (float)($plan['setup_price'] ?? 0);
  $plan['reseller_price_active'] = 1;

  if ($resellerCompanyId > 0) {
    try {
      $st = $pdo->prepare('SELECT sale_price, setup_price, is_active FROM saas_reseller_plan_prices WHERE reseller_company_id = ? AND plan_id = ? LIMIT 1');
      $st->execute([$resellerCompanyId, (int)($plan['id'] ?? 0)]);
      $override = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($override) {
        if (empty($override['is_active'])) return null;
        $plan['effective_sale_price'] = (float)$override['sale_price'];
        $plan['effective_setup_price'] = (float)$override['setup_price'];
        $plan['reseller_price_active'] = 1;
      }
    } catch (Throwable $e) {
      // Na ausência da tabela de preços da revenda, mantém o preço principal.
    }
  }

  return $plan;
}

function nexa_signup_delivery_plan(PDO $pdo, array $input, int $resellerCompanyId = 0): ?array {
  $lineValue = (string)($input['nexa_line'] ?? $input['linha'] ?? '');
  $line = ($lineValue === 'gestao') ? 'gestao' : 'food';
  if ($line !== 'food') return null;

  $requestedId = (int)($input['nexa_delivery_plan_id'] ?? 0);
  $requestedKey = nexa_signup_delivery_key($input);

  // Primeira tentativa: catálogo comercial completo, incluindo valores da revenda.
  try {
    foreach (reseller_plan_catalog($pdo, $resellerCompanyId, true) as $plan) {
      if (empty($plan['reseller_price_active'])) continue;
      $planKey = strtolower((string)($plan['delivery_key'] ?? ''));
      if ($requestedId > 0 && (int)($plan['id'] ?? 0) === $requestedId) return $plan;
      if ($planKey === $requestedKey) return $plan;
    }
  } catch (Throwable $e) {
    // Continua para as rotas de compatibilidade abaixo.
  }

  // Segunda tentativa: ID real enviado pelo configurador, mesmo em banco antigo.
  if ($requestedId > 0) {
    try {
      $plan = saas_find_plan($pdo, $requestedId);
      if ($plan) {
        $prepared = nexa_signup_prepare_delivery_plan($pdo, $plan, $requestedKey, $resellerCompanyId);
        if ($prepared) return $prepared;
      }
    } catch (Throwable $e) {}
  }

  // Terceira tentativa: planos públicos normalizados por nome, slug ou preço.
  try {
    foreach (public_plans_fetch($pdo) as $plan) {
      $prepared = nexa_signup_prepare_delivery_plan($pdo, $plan, $requestedKey, $resellerCompanyId);
      if ($prepared) return $prepared;
    }
  } catch (Throwable $e) {}

  // Último fallback para estruturas antigas onde public_plans_fetch não consegue
  // filtrar por is_active ou outras colunas ainda não migradas.
  try {
    $rows = $pdo->query('SELECT * FROM plans ORDER BY id ASC')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as $plan) {
      $prepared = nexa_signup_prepare_delivery_plan($pdo, $plan, $requestedKey, $resellerCompanyId);
      if ($prepared) return $prepared;
    }
  } catch (Throwable $e) {}

  return null;
}

saas_billing_ensure_schema($pdo);
try { saas_whatsapp_ensure_schema($pdo); } catch (Throwable $e) {}
// A estrutura de cadastro é instalada previamente pelo worker migrate_company_signup_v24.php.
// Não execute ALTER TABLE durante o cadastro público.
try { reseller_ensure_schema($pdo); reseller_ensure_pricing_schema($pdo); } catch (Throwable $e) {}
$requestInput = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;
$directSignup = (defined('NEXA_DIRECT_SIGNUP') && NEXA_DIRECT_SIGNUP === true)
  || (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET')) === 'POST'
      && in_array((string)($requestInput['nexa_source'] ?? ''), ['configurador','reseller_landing'], true));
$signupRequestToken = preg_replace('/[^a-f0-9]/i', '', (string)($requestInput['csrf'] ?? '')) ?: '';
if ($directSignup && $signupRequestToken !== ''
    && !empty($_SESSION['signup_processed_tokens'][$signupRequestToken])
    && !empty($_SESSION['signup_success'])) {
  header('Location: /cadastro_sucesso.php', true, 303);
  exit;
}
$reseller = reseller_resolve_context($pdo);
$isResellerSignup = $reseller !== null && (string)($requestInput['nexa_source'] ?? '') === 'reseller_landing';
$selectedDeliveryPlan = nexa_signup_delivery_plan($pdo, $requestInput, $isResellerSignup ? (int)$reseller['id'] : 0);
$commercialPlanId = (int)($selectedDeliveryPlan['id'] ?? 0);
$selectedModuleCodes = nexa_signup_selected_codes($pdo, $requestInput, $isResellerSignup ? (int)$reseller['id'] : 0);
$selectedModuleQuantities = nexa_signup_selected_quantities($pdo, $requestInput, $isResellerSignup ? (int)$reseller['id'] : 0, $selectedModuleCodes);
$signupFromConfigurator = (string)($requestInput['nexa_source'] ?? '') === 'configurador';

$companyColumns = [
  'person_type' => has_column($pdo, 'companies', 'person_type'),
  'cnpj' => has_column($pdo, 'companies', 'cnpj'),
  'cpf' => has_column($pdo, 'companies', 'cpf'),
  'nome_fantasia' => has_column($pdo, 'companies', 'nome_fantasia'),
  'razao_social' => has_column($pdo, 'companies', 'razao_social'),
  'celular' => has_column($pdo, 'companies', 'celular'),
  'cep' => has_column($pdo, 'companies', 'cep'),
  'endereco' => has_column($pdo, 'companies', 'endereco'),
  'numero' => has_column($pdo, 'companies', 'numero'),
  'complemento' => has_column($pdo, 'companies', 'complemento'),
  'bairro' => has_column($pdo, 'companies', 'bairro'),
  'cidade' => has_column($pdo, 'companies', 'cidade'),
  'estado' => has_column($pdo, 'companies', 'estado'),
  'desired_plan_id' => has_column($pdo, 'companies', 'desired_plan_id'),
  'commercial_plan_id' => has_column($pdo, 'companies', 'commercial_plan_id'),
  'subdomain' => has_column($pdo, 'companies', 'subdomain'),
  'reseller_company_id' => has_column($pdo, 'companies', 'reseller_company_id'),
];

$plans = load_public_plans($pdo);
if ($isResellerSignup) {
  try { $plans = [reseller_ensure_internal_plan($pdo)]; } catch (Throwable $e) { $plans = []; }
}
$requestedPlan = trim((string)($_GET['plan'] ?? $_POST['plan'] ?? ''));
$selectedPlanId = 0;
foreach ($plans as $p) {
  if ((string)($p['slug'] ?? '') === $requestedPlan || (string)($p['public_slug'] ?? '') === $requestedPlan || (string)($p['public_key'] ?? '') === $requestedPlan || ((string)($p['public_key'] ?? '') === 'trial' && in_array($requestedPlan, ['start-free','startfree'], true)) || (string)($p['id'] ?? '') === $requestedPlan) { $selectedPlanId = (int)$p['id']; break; }
}
if ($selectedPlanId <= 0 && $plans) {
  foreach ($plans as $p) { if ((string)($p['public_key'] ?? '') === 'trial') { $selectedPlanId = (int)$p['id']; break; } }
  if ($selectedPlanId <= 0) $selectedPlanId = (int)($plans[0]['id'] ?? 0);
}

$erro = '';
$ok = false;
$result = [];
$form = [
  'person_type' => (string)($_POST['person_type'] ?? 'pj'),
  'cpf' => (string)($_POST['cpf'] ?? ''),
  'nome_pf' => (string)($_POST['nome_pf'] ?? $_POST['responsavel'] ?? ''),
  'whatsapp' => (string)($_POST['whatsapp'] ?? $_POST['celular'] ?? ''),
  'cnpj' => (string)($_POST['cnpj'] ?? ''),
  'nome_fantasia' => (string)($_POST['nome_fantasia'] ?? ''),
  'razao_social' => (string)($_POST['razao_social'] ?? ''),
  'celular' => (string)($_POST['celular'] ?? ''),
  'email' => (string)($_POST['email'] ?? ''),
  'cep' => (string)($_POST['cep'] ?? ''),
  'endereco' => (string)($_POST['endereco'] ?? ''),
  'numero' => (string)($_POST['numero'] ?? ''),
  'complemento' => (string)($_POST['complemento'] ?? ''),
  'bairro' => (string)($_POST['bairro'] ?? ''),
  'cidade' => (string)($_POST['cidade'] ?? ''),
  'estado' => (string)($_POST['estado'] ?? ''),
  'plan_id' => (string)($selectedPlanId > 0 ? $selectedPlanId : ''),
  'responsavel' => (string)($_POST['responsavel'] ?? ''),
  'segmento' => (string)($_POST['segmento'] ?? ''),
  'cargo' => (string)($_POST['cargo'] ?? ''),
  'observacoes' => (string)($_POST['observacoes'] ?? ''),
  'aceite' => (string)($_POST['aceite'] ?? ''),
  'nexa_line' => (string)($requestInput['nexa_line'] ?? $requestInput['linha'] ?? ''),
  'nexa_delivery' => (string)($requestInput['nexa_delivery'] ?? $requestInput['delivery'] ?? ''),
  'nexa_delivery_plan_id' => (string)($requestInput['nexa_delivery_plan_id'] ?? $commercialPlanId),
];
if (!in_array($form['person_type'], ['pf','pj'], true)) $form['person_type'] = 'pj';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $personType = in_array($form['person_type'], ['pj','pf'], true) ? $form['person_type'] : 'pj';
  $cpf = digits_only($form['cpf']);
  $cnpj = digits_only($form['cnpj']);
  $nomePf = trim($form['nome_pf']);
  $nomeFantasia = trim($form['nome_fantasia']);
  $razaoSocial = trim($form['razao_social']);
  $email = trim($form['email']);
  $whatsapp = digits_only($form['whatsapp']);
  $celular = digits_only($form['celular']);
  $cep = digits_only($form['cep']);
  $endereco = trim($form['endereco']);
  $numero = trim($form['numero']);
  $complemento = trim($form['complemento']);
  $bairro = trim($form['bairro']);
  $cidade = trim($form['cidade']);
  $estado = strtoupper(substr(trim($form['estado']), 0, 2));
  $selectedPlanId = (int)($form['plan_id'] !== '' ? $form['plan_id'] : 0);
  $plan = saas_find_plan($pdo, $selectedPlanId);
  $publicPlanKey = $plan ? public_plan_key($plan) : '';
  if ($plan && $publicPlanKey !== '') {
    $plan = public_plan_apply_defaults($plan, $publicPlanKey);
  }

  // O nome fantasia é a identidade pública da empresa em ambos os tipos de cadastro.
  // Ele também é a única origem do slug/subdomínio.
  $empresa = $nomeFantasia;
  $responsavelInformado = trim((string)($form['responsavel'] ?? ''));
  $responsavel = $responsavelInformado !== '' ? $responsavelInformado : ($personType === 'pf' ? $nomePf : ($razaoSocial !== '' ? $razaoSocial : $nomeFantasia));
  $segmento = trim((string)($form['segmento'] ?? ''));
  $cargo = trim((string)($form['cargo'] ?? ''));
  $observacoes = trim((string)($form['observacoes'] ?? ''));
  $aceite = (string)($form['aceite'] ?? '') === '1';
  $signupFromConfigurator = (string)($_POST['nexa_source'] ?? '') === 'configurador';
  $signupFromReseller = (string)($_POST['nexa_source'] ?? '') === 'reseller_landing' && $reseller !== null;
  $selectedDeliveryPlan = nexa_signup_delivery_plan($pdo, $_POST, $signupFromReseller ? (int)$reseller['id'] : 0);
  $commercialPlanId = (int)($selectedDeliveryPlan['id'] ?? 0);
  $selectedModuleCodes = nexa_signup_selected_codes($pdo, $_POST, $signupFromReseller ? (int)$reseller['id'] : 0);
  if ($signupFromReseller) {
    $allowedCodes = [];
    foreach (reseller_module_catalog($pdo, (int)$reseller['id'], true) as $catalogModule) {
      if (!empty($catalogModule['reseller_price_active'])) $allowedCodes[] = (string)$catalogModule['code'];
    }
    if ($selectedDeliveryPlan && !empty($selectedDeliveryPlan['reseller_price_active'])) $allowedCodes[] = 'delivery';
    // Gestão Fiscal é um produto comercial único, mas libera também os módulos operacional fiscal e financeiro.
    if (in_array('gestao', $allowedCodes, true)) $allowedCodes = array_merge($allowedCodes, ['fiscal','financeiro']);
    $selectedModuleCodes = array_values(array_intersect($selectedModuleCodes, array_unique($allowedCodes)));
  }
  $selectedModuleQuantities = nexa_signup_selected_quantities($pdo, $_POST, $signupFromReseller ? (int)$reseller['id'] : 0, $selectedModuleCodes);
  $telefoneContato = $personType === 'pf' ? $whatsapp : $celular;

  if (!$plan || (!$signupFromReseller && $publicPlanKey === '')) $erro = 'Selecione um plano válido.';
  elseif (($signupFromConfigurator || $signupFromReseller) && (string)($form['nexa_line'] ?? '') !== 'gestao' && !$selectedDeliveryPlan) $erro = 'Selecione uma faixa válida do Delivery.';
  elseif ($signupFromReseller && !$selectedModuleCodes) $erro = 'Selecione pelo menos um módulo válido na proposta da revenda.';
  elseif ($nomeFantasia === '') $erro = 'Informe o nome fantasia do negócio.';
  elseif ($personType === 'pf' && !company_identity_valid_cpf($cpf)) $erro = 'Informe um CPF válido.';
  elseif ($personType === 'pf' && $nomePf === '') $erro = 'Informe o nome do responsável.';
  elseif ($personType === 'pf' && $whatsapp === '') $erro = 'Informe o WhatsApp do responsável.';
  elseif ($personType === 'pj' && !company_identity_valid_cnpj($cnpj)) $erro = 'Informe um CNPJ válido.';
  elseif ($personType === 'pj' && $razaoSocial === '') $erro = 'Informe a razão social.';
  elseif ($personType === 'pj' && $celular === '') $erro = 'Informe o WhatsApp do responsável.';
  elseif ($responsavel === '') $erro = 'Informe o nome do responsável pelo cadastro.';
  elseif ($segmento === '') $erro = 'Informe o segmento da empresa.';
  elseif (($signupFromConfigurator || $signupFromReseller) && !$aceite) $erro = 'Confirme a autorização de contato para concluir o cadastro.';
  elseif (company_identity_document_exists($pdo, $personType, $personType === 'pf' ? $cpf : $cnpj)) $erro = ($personType === 'pf' ? 'Este CPF' : 'Este CNPJ') . ' já está vinculado a outra empresa.';
  elseif ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $erro = 'Informe um e-mail válido ou deixe o campo em branco.';
  elseif (strlen($cep) !== 8) $erro = 'Informe o CEP com 8 dígitos.';
  elseif ($endereco === '') $erro = 'Informe o endereço.';
  elseif ($numero === '') $erro = 'Informe o número.';
  elseif ($complemento === '') $erro = 'Informe o complemento. Caso não tenha, preencha como "Sem complemento".';
  elseif ($bairro === '') $erro = 'Informe o bairro.';
  elseif ($cidade === '') $erro = 'Informe a cidade.';
  elseif (strlen($estado) !== 2) $erro = 'Informe o estado com a sigla UF, por exemplo MG.';
  else {
    try {
      config_store_init($pdo);
      // Estrutura previamente homologada: nunca executar DDL ao cadastrar empresas.
      nexa_signup_assert_defaults_schema($pdo);
      $availability = company_subdomain_availability($pdo, $nomeFantasia);
      if (!$availability['available']) {
        throw new RuntimeException($availability['message']);
      }
      $slug = (string)$availability['subdomain'];
      $subdomain = $slug;
      $loginEmail = $email !== '' ? $email : ($slug . '@sem-email.nexaprospero.local');
      $stUser = $pdo->prepare('SELECT COUNT(*) FROM users WHERE email = ?');
      $stUser->execute([$loginEmail]);
      if ((int)$stUser->fetchColumn() > 0) {
        throw new RuntimeException('Já existe um usuário cadastrado com este e-mail. Informe outro e-mail ou deixe em branco para gerar um login técnico.');
      }
      $pass = random_pass(10);
      $hash = password_hash($pass, PASSWORD_DEFAULT);
      $isTrial = saas_plan_is_trial($plan);
      $companyStatus = ($isTrial || $signupFromReseller) ? 'active' : 'blocked';

      // Garante a estrutura das sequências antes da transação de criação.
      try { company_sequence_ensure_schema($pdo, false); } catch (Throwable $e) {}

      $pdo->beginTransaction();
      $insertCols = ['name', 'slug', 'email', 'whatsapp', 'status'];
      $insertVals = [$empresa, $slug, ($email !== '' ? $email : null), $telefoneContato, $companyStatus];
      if ($companyColumns['subdomain']) { $insertCols[] = 'subdomain'; $insertVals[] = $subdomain; }

      $extraCompanyData = [
        'person_type' => $personType,
        'cnpj' => $personType === 'pj' ? $cnpj : null,
        'cpf' => $personType === 'pf' ? $cpf : null,
        'nome_fantasia' => $nomeFantasia,
        'razao_social' => $personType === 'pj' ? $razaoSocial : null,
        'celular' => $telefoneContato,
        'cep' => $cep,
        'endereco' => $endereco,
        'numero' => $numero,
        'complemento' => $complemento,
        'bairro' => $bairro,
        'cidade' => $cidade,
        'estado' => $estado,
      ];
      foreach ($extraCompanyData as $col => $value) {
        if ($companyColumns[$col] ?? false) { $insertCols[] = $col; $insertVals[] = $value !== '' ? $value : null; }
      }
      if ($companyColumns['desired_plan_id']) { $insertCols[] = 'desired_plan_id'; $insertVals[] = (int)$plan['id']; }
      if ($companyColumns['commercial_plan_id'] && $commercialPlanId > 0) { $insertCols[] = 'commercial_plan_id'; $insertVals[] = $commercialPlanId; }
      if ($signupFromReseller && $companyColumns['reseller_company_id']) { $insertCols[] = 'reseller_company_id'; $insertVals[] = (int)$reseller['id']; }

      $sql = 'INSERT INTO companies (' . implode(', ', $insertCols) . ') VALUES (' . implode(', ', array_fill(0, count($insertCols), '?')) . ')';
      $pdo->prepare($sql)->execute($insertVals);
      $companyId = (int)$pdo->lastInsertId();
      // Cada nova empresa inicia pedidos, produtos e pessoas no número 1.
      try { company_sequence_initialize_company($pdo, $companyId); } catch (Throwable $e) {}
      if ($signupFromReseller) {
        // Compatibilidade com bancos anteriores ao módulo de Revendas: os metadados
        // comerciais são gravados somente quando as respectivas colunas existem.
        $subCols = ['company_id','plan_id','status','started_at','ends_at','next_billing_at','payment_provider'];
        $subExpr = ['?','?',"'active'",'NOW()','NULL','NULL',"'reseller'"];
        $subVals = [$companyId, (int)$plan['id']];
        if (has_column($pdo, 'subscriptions', 'manual_note')) { $subCols[]='manual_note'; $subExpr[]='?'; $subVals[]='Cliente faturado diretamente pela revenda'; }
        if (has_column($pdo, 'subscriptions', 'granted_by')) { $subCols[]='granted_by'; $subExpr[]='?'; $subVals[]='Central SaaS'; }
        if (has_column($pdo, 'subscriptions', 'granted_reason')) { $subCols[]='granted_reason'; $subExpr[]='?'; $subVals[]='Cadastro pela landing da revenda'; }
        if (has_column($pdo, 'subscriptions', 'updated_at')) { $subCols[]='updated_at'; $subExpr[]='NOW()'; }
        $pdo->prepare('INSERT INTO subscriptions (' . implode(',', $subCols) . ') VALUES (' . implode(',', $subExpr) . ')')->execute($subVals);
        $subscriptionId = (int)$pdo->lastInsertId();
        reseller_attach_client($pdo, $companyId, (int)$reseller['id']);
      } else {
        $subscriptionId = saas_create_subscription_for_signup($pdo, $companyId, $plan);
      }
      $moduleIds = nexa_signup_module_ids($pdo, $selectedModuleCodes);
      if (($signupFromConfigurator || $signupFromReseller) && $moduleIds) {
        $trialModuleExpires = (new DateTimeImmutable('+7 days'))->format('Y-m-d');
        saas_save_company_modules_custom(
          $pdo,
          $companyId,
          $moduleIds,
          null,
          $signupFromReseller ? 'Módulos contratados pela landing da revenda' : 'Módulos habilitados automaticamente pelo configurador Nexa Próspero',
          $selectedModuleQuantities
        );
      }
      // Padrões operacionais para toda nova empresa do Nexa Próspero.
      // São gravados por empresa para não alterar configurações de clientes já existentes.
      config_store_set($pdo, $companyId, 'business', [
        'whatsapp_auto_open' => true,
        'whatsapp_pdv_auto_send' => true,
        'brand' => [
          'catalog_title' => $empresa,
          'catalog_subtitle' => 'Seu catálogo online com pedido rápido',
          'primary_color' => '#2454d6',
          'secondary_color' => '#00b8ff',
          'accent_color' => '#007bff',
          'text_on_primary' => '#ffffff',
        ],
        'domain' => ['subdomain' => $subdomain, 'custom_domain' => ''],
        'admin_ui' => [
          'side_menu_enabled' => true,
        ],
        'catalog_ui' => [
          'horizontal_category_menu' => true,
        ],
        'product_options' => [
          'show_additional_unit_total' => true,
          'show_item_total_with_additionals' => true,
        ],
        'order_reports' => [
          'enabled' => false,
        ],
        // Apenas empresas recém-criadas: preserva preço na entrada XML
        // e habilita explicitamente a nova edição de pedidos.
        'fiscal_xml_entry' => [
          'pricing_policy' => 'keep_price',
        ],
        'order_edit' => [
          'advanced_enabled' => true,
        ],
        'banner' => [
          'title' => 'Faça seu pedido',
        ],
      ]);
      // Metadados do cadastro preservam a origem comercial e os dados do formulário
      // sem criar dependência de novas colunas na tabela principal de empresas.
      config_store_set($pdo, $companyId, 'signup_profile', [
        'source' => $signupFromReseller ? 'reseller_landing' : ($signupFromConfigurator ? 'configurador' : 'cadastro'),
        'reseller_company_id' => ($signupFromReseller && $reseller) ? (int)$reseller['id'] : null,
        'reseller_brand' => ($signupFromReseller && $reseller) ? (string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? '') : 'Nexa Próspero',
        'segmento' => $segmento,
        'responsavel' => $responsavel,
        'cargo' => $cargo,
        'observacoes' => $observacoes,
        'aceite_contato' => $aceite,
        'aceite_em' => $aceite ? date('Y-m-d H:i:s') : null,
      ]);
      // Perfil e contas são exclusivos desta nova empresa, dentro da mesma transação.
      $generalProfileId = nexa_signup_create_general_profile($pdo, $companyId);
      nexa_signup_seed_chart_of_accounts($pdo, $companyId);
      nexa_signup_seed_tax_profiles($pdo, $companyId);
      nexa_signup_seed_payment_methods($pdo, $companyId);
      // A senha aleatória não é mais guardada em texto aberto nem enviada ao cliente:
      // ele recebe um link seguro para criar a própria senha (config/password_setup.php).
      $pdo->prepare("INSERT INTO users (company_id, name, email, password_hash, role, is_active, profile_id) VALUES (?,?,?,?, 'company_admin', 1, ?)")
        ->execute([$companyId, $responsavel, $loginEmail, $hash, $generalProfileId]);
      $adminUserId = (int)$pdo->lastInsertId();
      $pdo->commit();

      // V11.52: após o cadastro SaaS estar definitivamente salvo, sincroniza o Trial
      // com a empresa faturadora configurada. A falha desta integração não desfaz
      // o cadastro do cliente; fica registrada para reprocessamento na Central SaaS.
      $saasBridgeSync = ['ok'=>false,'skipped'=>true];
      if (!$signupFromReseller && $isTrial) {
        $saasBridgeSync = saas_sub_bridge_sync_signup_safe($pdo, $companyId);
      }

      $company = saas_find_company($pdo, $companyId) ?: [
        'id' => $companyId,
        'slug' => $slug,
        'subdomain' => $subdomain,
        'custom_domain' => '',
        'name' => $empresa,
        'email' => $email,
        'cnpj' => $cnpj,
        'cpf' => $cpf,
      ];
      // Credencial enviada ao cliente: link para criar a senha. Se a migração ainda não
      // tiver sido aplicada, mantém o comportamento anterior (senha inicial) para não
      // impedir o cadastro.
      $passwordLink = '';
      try {
        $passwordLink = password_setup_create_link($pdo, $company, $adminUserId, 'setup');
      } catch (Throwable $e) {
        $passwordLink = '';
      }
      $credential = $passwordLink !== '' ? $passwordLink : $pass;
      $catalogUrlPath = company_catalog_url($company);
      $publicCatalogUrl = company_public_catalog_url($company);
      $eadSync = ead_integration_sync_company([
        'company_id' => $companyId,
        'slug' => $slug,
        'nome' => $responsavel,
        'empresa' => $empresa,
        'email' => $loginEmail,
        'senha' => $pass,
        'whatsapp' => $telefoneContato,
        'celular' => $telefoneContato,
      ]);

      $moduleLabels = nexa_signup_module_labels($pdo, $selectedModuleCodes, $selectedModuleQuantities, $signupFromReseller && $reseller ? (int)$reseller['id'] : 0);
      $signupContext = [
        'source' => $signupFromReseller ? 'revenda' : ($signupFromConfigurator ? 'configurador' : 'cadastro'),
        'line' => (string)($form['nexa_line'] ?? ''),
        'delivery_tier' => nexa_signup_delivery_key($form),
        'plan_label' => ((string)($form['nexa_line'] ?? '') === 'gestao') ? 'Nexa Próspero Gestão' : (string)($selectedDeliveryPlan['name'] ?? 'Nexa Próspero Food'),
        'module_codes' => $selectedModuleCodes,
        'module_quantities' => $selectedModuleQuantities,
        'module_labels' => $moduleLabels,
        'trial_start' => (new DateTimeImmutable('today'))->format('d/m/Y'),
        'trial_end' => $signupFromReseller ? '' : (new DateTimeImmutable('+7 days'))->format('d/m/Y'),
        'segmento' => $segmento,
        'responsavel' => $responsavel,
        'cargo' => $cargo,
        'observacoes' => $observacoes,
        'seller_brand' => ($signupFromReseller && $reseller) ? (string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda') : 'Nexa Próspero',
        'seller_whatsapp' => ($signupFromReseller && $reseller) ? (string)($reseller['reseller_whatsapp'] ?? '') : '',
      ];
      $adminUrl = company_admin_url(['slug' => $slug, 'subdomain' => $subdomain, 'custom_domain' => '']);
      $eadLoginUrl = (string)($eadSync['login_url'] ?? ead_integration_login_url());

      if ($isTrial || $signupFromReseller) {
        if ($signupFromReseller && $reseller) {
          $whatsappMessage = reseller_whatsapp_credentials_message($pdo, (int)$reseller['id'], $company, $loginEmail, $credential, $eadSync, $signupContext);
          $whatsappCredentials = reseller_whatsapp_send_credentials($pdo, (int)$reseller['id'], $companyId, $credential, $eadSync, $signupContext);
          $waCfg = reseller_whatsapp_load_config($pdo, (int)$reseller['id']);
        } else {
          $whatsappMessage = saas_whatsapp_build_credentials_message($pdo, $company, $loginEmail, $credential, '', $eadSync, $signupContext);
          $whatsappCredentials = saas_whatsapp_send_credentials($pdo, $companyId, $credential, '', $eadSync, $signupContext);
          $waCfg = saas_whatsapp_load_config($pdo);
        }
        $manualWhatsappUrl = '';
        try {
          $waNumber = saas_whatsapp_normalize_phone($telefoneContato, (string)($waCfg['default_country_code'] ?? '55'));
          if ($waNumber !== '') $manualWhatsappUrl = 'https://wa.me/' . rawurlencode($waNumber) . '?text=' . rawurlencode($whatsappMessage);
        } catch (Throwable $e) {}

        $result = [
          'company_id' => $companyId,
          'company_name' => $empresa,
          'seller_brand' => $signupFromReseller && $reseller ? (string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda') : 'Nexa Próspero',
          'seller_logo' => $signupFromReseller && $reseller ? reseller_theme_logo_url($reseller) : '/assets/logo-nexa-prospero.jpg',
          'seller_primary_color' => $signupFromReseller && $reseller ? reseller_theme_config($reseller)['primary'] : '#0b1f5b',
          'seller_secondary_color' => $signupFromReseller && $reseller ? reseller_theme_config($reseller)['secondary'] : '#2454d6',
          'seller_whatsapp' => $signupFromReseller && $reseller ? (string)($reseller['reseller_whatsapp'] ?? '') : '',
          'is_reseller_signup' => $signupFromReseller,
          'slug' => $slug,
          'email' => $loginEmail,
          'email_was_generated' => $email === '',
          'pass' => $passwordLink !== '' ? '' : $pass,
          'password_link' => $passwordLink,
          'selected_plan' => $selectedPlanId,
          'catalog_url_path' => $catalogUrlPath,
          'public_catalog_url' => $publicCatalogUrl,
          'admin_url' => $adminUrl,
          'ead' => $eadSync,
          'ead_login_url' => $eadLoginUrl,
          'modules' => $moduleLabels,
          'signup_context' => $signupContext,
          'whatsapp' => $telefoneContato,
          'whatsapp_credentials' => $whatsappCredentials,
          'whatsapp_manual_url' => $manualWhatsappUrl,
          'created_at' => time(),
        ];
        $_SESSION['signup_success'] = $result;
        if ($signupRequestToken !== '') $_SESSION['signup_processed_tokens'][$signupRequestToken] = $companyId;
        $ok = true;

        // Evita reenvio do POST ao atualizar a página e leva diretamente à etapa final.
        header('Location: /cadastro_sucesso.php', true, 303);
        exit;
      } else {
        $charge = saas_create_pix_charge($pdo, $company, $plan, $subscriptionId, 'Assinatura do plano ' . (string)$plan['name']);
        $paymentUrl = (string)($charge['payment_url'] ?? '');
        $whatsappCredentials = saas_whatsapp_send_credentials($pdo, $companyId, $credential, $paymentUrl, $eadSync, $signupContext);
        $_SESSION['signup_success'] = [
          'company_id' => $companyId,
          'company_name' => $empresa,
          'seller_brand' => 'Nexa Próspero',
          'seller_logo' => '/assets/logo-nexa-prospero.jpg',
          'seller_primary_color' => '#0b1f5b',
          'seller_secondary_color' => '#2454d6',
          'slug' => $slug,
          'email' => $loginEmail,
          'pass' => $passwordLink !== '' ? '' : $pass,
          'password_link' => $passwordLink,
          'catalog_url_path' => $catalogUrlPath,
          'public_catalog_url' => $publicCatalogUrl,
          'admin_url' => $adminUrl,
          'ead' => $eadSync,
          'ead_login_url' => $eadLoginUrl,
          'modules' => $moduleLabels,
          'signup_context' => $signupContext,
          'whatsapp_credentials' => $whatsappCredentials,
          'payment_url' => $paymentUrl,
          'created_at' => time(),
        ];
        if ($signupRequestToken !== '') $_SESSION['signup_processed_tokens'][$signupRequestToken] = $companyId;
        header('Location: /pagamento_pix.php?company=' . rawurlencode($slug) . '&charge=' . (int)$charge['id']);
        exit;
      }
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      $message = $e->getMessage();
      if ($e instanceof PDOException && (string)$e->getCode() === '23000') {
        $message = 'O subdomínio, CPF, CNPJ ou e-mail já está em uso. Confira os dados e tente novamente.';
      }
      $erro = 'Falha ao cadastrar: ' . $message;
    }
  }
}

$selectedPlan = null;
foreach ($plans as $p) { if ((int)$p['id'] === (int)$selectedPlanId) { $selectedPlan = $p; break; } }
$mainSiteUrl = rtrim((string)(getenv('NEXA_MAIN_SITE_URL') ?: 'https://nexaprospero.com.br/'), '/') . '/';
$signupRootDomains = function_exists('configured_root_domains') ? configured_root_domains() : [];
$signupRootDomain = (string)($signupRootDomains[0] ?? 'nexaprospero.com.br');
$signupLine = ((string)($form['nexa_line'] ?? '') === 'gestao') ? 'gestao' : 'food';
$signupIsGestao = $signupLine === 'gestao';
$signupResellerTheme = ($isResellerSignup && $reseller) ? reseller_theme_config($reseller) : null;
$signupSellerName = ($isResellerSignup && $reseller)
  ? (string)($signupResellerTheme['brand_name'] ?? $reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda')
  : 'Nexa Próspero';
$signupSellerLogo = ($isResellerSignup && $reseller) ? reseller_theme_logo_url($reseller) : '/assets/logo-nexa-prospero.jpg';
$signupBrandHref = ($isResellerSignup && $reseller)
  ? (current_scheme() . '://' . current_host_name() . '/')
  : $mainSiteUrl;
$signupPlansHref = ($isResellerSignup && $reseller) ? '/montesuaproposta' : '/escolher_plano.php';
$signupBackHref = ($isResellerSignup && $reseller) ? '/montesuaproposta' : ($mainSiteUrl . 'configurador.php');
$signupProductName = $signupIsGestao ? 'Nexa Próspero Gestão' : 'Nexa Próspero Delivery';
$signupTopSubtitle = $isResellerSignup ? ('Cadastro pela revenda ' . $signupSellerName) : 'Última etapa para iniciar';
$signupTag = $isResellerSignup ? ('Cadastro pela ' . $signupSellerName) : 'Teste grátis por 7 dias';
$signupIntro = $isResellerSignup
  ? 'Sua solução já está selecionada pela revenda. Após o cadastro, o sistema criará automaticamente sua empresa, subdomínio, usuário e módulos contratados.'
  : 'Sua solução já está selecionada. Após o cadastro, o sistema criará automaticamente sua empresa, subdomínio, usuário e módulos selecionados.';
$signupButtonLabel = $isResellerSignup ? 'Criar minha empresa →' : 'Criar meu ambiente de teste →';
$signupConsentBrand = $isResellerSignup ? $signupSellerName : 'equipe Nexa Próspero';
$signupQuote = nexa_signup_quote_summary($pdo, $requestInput, $selectedModuleQuantities, $isResellerSignup && $reseller ? (int)$reseller['id'] : 0, $selectedDeliveryPlan);
$signupQuoteItems = (array)($signupQuote['items'] ?? []);
if (!$signupQuoteItems) {
  foreach (nexa_signup_module_labels($pdo, $selectedModuleCodes, $selectedModuleQuantities, $isResellerSignup && $reseller ? (int)$reseller['id'] : 0) as $label) {
    $signupQuoteItems[] = ['label' => $label, 'monthly' => 0, 'setup' => 0, 'quantity' => 1];
  }
}
$signupQuoteMonthly = (float)($signupQuote['monthly'] ?? 0);
$signupQuoteSetup = (float)($signupQuote['setup'] ?? 0);
$signupResellerWhatsapp = $isResellerSignup && $reseller ? preg_replace('/\D+/', '', (string)($reseller['reseller_whatsapp'] ?? '')) : '';
if (!isset($_SESSION['signup_form_token']) || !is_string($_SESSION['signup_form_token']) || strlen($_SESSION['signup_form_token']) < 20) {
  $_SESSION['signup_form_token'] = bin2hex(random_bytes(24));
}
$signupFormToken = $signupRequestToken !== '' ? $signupRequestToken : (string)$_SESSION['signup_form_token'];
$ufOptions = ['AC','AL','AP','AM','BA','CE','DF','ES','GO','MA','MT','MS','MG','PA','PB','PR','PE','PI','RJ','RN','RS','RO','RR','SC','SP','SE','TO'];
$segmentOptions = ['Restaurante','Lanchonete','Pizzaria','Hamburgueria','Padaria','Comércio varejista','Loja de roupas ou calçados','Material de construção','Autopeças','Outro'];
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>Cadastre sua empresa | <?= e($signupSellerName) ?></title>
  <meta name="description" content="Cadastre sua empresa no Nexa Próspero.">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="/assets/cadastro-empresa-v2.css?v=20261020-1">
  <?php if ($isResellerSignup && $signupResellerTheme): ?>
  <style>
    :root{--primary-dark:<?= e((string)$signupResellerTheme['primary']) ?>;--primary:<?= e((string)$signupResellerTheme['secondary']) ?>}
  </style>
  <?php endif; ?>
</head>
<body>
<header class="topbar">
  <div class="container nav">
    <a class="brand" href="<?= e($signupBrandHref) ?>">
      <img class="brand-logo" src="<?= e($signupSellerLogo) ?>" alt="<?= e($signupSellerName) ?>">
      <div class="brand-copy"><strong><?= e($signupSellerName) ?></strong><span><?= e($signupTopSubtitle) ?></span></div>
    </a>
    <nav class="menu"><a href="<?= e($signupPlansHref) ?>"><?= $isResellerSignup ? '← Voltar à proposta' : '← Alterar plano' ?></a></nav>
  </div>
</header>

<section class="section compact-top">
  <div class="container">
    <?php if (($directSignup ?? false) && $erro !== ''): ?>
      <div class="success-card">
        <div class="success-icon" style="background:#fff1f2;color:#be123c">!</div>
        <h1>Não foi possível concluir o cadastro</h1>
        <div class="form-error"><?= e($erro) ?></div>
        <p>Volte à etapa anterior, confira os dados e tente novamente.</p>
        <div class="form-actions"><a class="btn btn-primary" href="<?= e($signupBackHref) ?>"><?= $isResellerSignup ? 'Voltar à proposta' : 'Voltar ao configurador' ?></a></div>
      </div>
    <?php else: ?>
      <div class="section-head">
        <span class="tag"><?= e($signupTag) ?></span>
        <h1>Conte um pouco sobre sua empresa.</h1>
        <p><?= e($signupIntro) ?></p>
      </div>

      <div class="registration-layout">
        <form
          class="registration-card"
          id="company-signup-form"
          method="post"
          autocomplete="on"
          data-subdomain-endpoint="/api/subdomain.php"
          data-cnpj-endpoint="/api/cnpj.php"
          data-root-domain="<?= e($signupRootDomain) ?>"
        >
          <?php if ($erro): ?><div class="form-error"><?= e($erro) ?></div><?php endif; ?>
          <input type="hidden" name="csrf" value="<?= e($signupFormToken) ?>">
          <input type="hidden" name="plan_id" value="<?= (int)$selectedPlanId ?>">
          <input type="hidden" name="nexa_source" value="<?= $isResellerSignup ? 'reseller_landing' : (($signupFromConfigurator ?? false) ? 'configurador' : 'cadastro') ?>">
          <?php if ($isResellerSignup && $reseller): ?>
            <input type="hidden" name="revenda" value="<?= e((string)($reseller['subdomain'] ?? $reseller['slug'] ?? '')) ?>">
          <?php endif; ?>
          <input type="hidden" name="nexa_line" value="<?= e((string)($form['nexa_line'] ?? '')) ?>">
          <input type="hidden" name="nexa_delivery" value="<?= e((string)($form['nexa_delivery'] ?? '')) ?>">
          <input type="hidden" name="nexa_delivery_plan_id" value="<?= (int)($form['nexa_delivery_plan_id'] ?? 0) ?>">
          <?php foreach (($selectedModuleCodes ?? []) as $moduleCode): ?>
            <?php if (in_array((string)$moduleCode, ['fiscal','financeiro'], true) && in_array('gestao', $selectedModuleCodes, true)) continue; ?>
            <input type="hidden" name="nexa_modules[]" value="<?= e((string)$moduleCode) ?>">
            <input type="hidden" name="nexa_module_quantities[<?= e((string)$moduleCode) ?>]" value="<?= (int)($selectedModuleQuantities[(string)$moduleCode] ?? 1) ?>">
          <?php endforeach; ?>
          <div class="honeypot"><label>Site<input type="text" name="website" tabindex="-1" autocomplete="off"></label></div>

          <h2 class="form-section-title">Dados da empresa</h2>
          <p class="form-section-copy">Escolha CPF ou CNPJ e preencha os dados principais para preparar seu acesso.</p>

          <div class="person-type-selector" role="radiogroup" aria-label="Tipo de documento">
            <label class="person-type-option">
              <input type="radio" name="person_type" value="pj" <?= $form['person_type'] === 'pj' ? 'checked' : '' ?>>
              <span class="person-type-card"><strong>Tenho CNPJ</strong><small>Cadastro de pessoa jurídica</small></span>
            </label>
            <label class="person-type-option">
              <input type="radio" name="person_type" value="pf" <?= $form['person_type'] === 'pf' ? 'checked' : '' ?>>
              <span class="person-type-card"><strong>Não tenho CNPJ</strong><small>Usar CPF do responsável</small></span>
            </label>
          </div>

          <div class="form-grid">
            <div class="form-field full cnpj-lookup-field person-pj" data-person-section="pj">
              <label for="cnpj">CNPJ *</label>
              <div class="cnpj-input-wrap">
                <input id="cnpj" name="cnpj" required inputmode="numeric" maxlength="18" placeholder="00.000.000/0000-00" autocomplete="off" value="<?= e($form['cnpj']) ?>">
                <button class="cnpj-search-button" id="consultar-cnpj" type="button" aria-label="Consultar CNPJ">Consultar</button>
              </div>
              <div id="cnpj-status" class="cnpj-status" role="status" aria-live="polite">Digite o CNPJ para preencher automaticamente os dados da empresa.</div>
            </div>

            <div class="form-field full person-pf hidden-by-type" data-person-section="pf">
              <label for="cpf">CPF do responsável *</label>
              <input id="cpf" name="cpf" inputmode="numeric" maxlength="14" placeholder="000.000.000-00" autocomplete="off" value="<?= e($form['cpf']) ?>" disabled>
              <div id="cpf-status" class="cnpj-status" role="status" aria-live="polite">O CPF será usado somente para identificar o responsável pelo cadastro.</div>
            </div>

            <div class="form-field person-pj" data-person-section="pj">
              <label for="razao_social">Razão social *</label>
              <input id="razao_social" name="razao_social" required maxlength="150" value="<?= e($form['razao_social']) ?>">
            </div>

            <div class="form-field">
              <label for="nome_fantasia">Nome fantasia *</label>
              <input id="nome_fantasia" name="nome_fantasia" required maxlength="120" autocomplete="organization" placeholder="Ex.: Sabor & Art" value="<?= e($form['nome_fantasia']) ?>">
              <div class="subdomain-preview-box">
                <span>Endereço previsto:</span>
                <strong id="subdomain-preview">seu-nome.<?= e($signupRootDomain) ?></strong>
                <small id="subdomain-status" role="status" aria-live="polite">Digite o nome fantasia para validar a disponibilidade.</small>
              </div>
            </div>

            <div class="form-field">
              <label for="segmento">Segmento *</label>
              <select id="segmento" name="segmento" required>
                <option value="">Selecione</option>
                <?php foreach ($segmentOptions as $segment): ?><option value="<?= e($segment) ?>" <?= (string)$form['segmento'] === $segment ? 'selected' : '' ?>><?= e($segment) ?></option><?php endforeach; ?>
              </select>
            </div>
            <div class="form-field"><label for="cidade">Cidade *</label><input id="cidade" name="cidade" required maxlength="100" value="<?= e($form['cidade']) ?>"></div>
            <div class="form-field"><label for="uf">UF *</label><select id="uf" name="estado" required><option value="">Selecione</option><?php foreach ($ufOptions as $uf): ?><option value="<?= e($uf) ?>" <?= strtoupper((string)$form['estado']) === $uf ? 'selected' : '' ?>><?= e($uf) ?></option><?php endforeach; ?></select></div>
            <div class="form-field"><label for="cep">CEP *</label><input id="cep" name="cep" required inputmode="numeric" maxlength="9" placeholder="00000-000" value="<?= e($form['cep']) ?>"></div>
            <div class="form-field full"><label for="endereco">Endereço *</label><input id="endereco" name="endereco" required maxlength="180" value="<?= e($form['endereco']) ?>"></div>
            <div class="form-field"><label for="numero">Número *</label><input id="numero" name="numero" required maxlength="20" value="<?= e($form['numero']) ?>"></div>
            <div class="form-field"><label for="complemento">Complemento *</label><input id="complemento" name="complemento" required maxlength="120" placeholder="Sem complemento" value="<?= e($form['complemento']) ?>"></div>
            <div class="form-field full"><label for="bairro">Bairro *</label><input id="bairro" name="bairro" required maxlength="120" value="<?= e($form['bairro']) ?>"></div>
          </div>

          <h2 class="form-section-title" style="margin-top:28px">Responsável pelo cadastro</h2>
          <p class="form-section-copy">Usaremos estes dados para liberar e acompanhar seu acesso.</p>
          <div class="form-grid">
            <div class="form-field"><label for="responsavel">Nome do responsável *</label><input id="responsavel" name="responsavel" required maxlength="120" value="<?= e($form['responsavel']) ?>"></div>
            <div class="form-field"><label for="cargo">Cargo</label><input id="cargo" name="cargo" maxlength="80" value="<?= e($form['cargo']) ?>"></div>
            <div class="form-field"><label for="email">E-mail <small>(opcional)</small></label><input id="email" name="email" type="email" maxlength="150" placeholder="nome@empresa.com.br" value="<?= e($form['email']) ?>"></div>
            <div class="form-field"><label for="telefone">WhatsApp *</label><input id="telefone" name="celular" required inputmode="tel" maxlength="20" placeholder="(31) 99999-9999" value="<?= e($form['celular'] !== '' ? $form['celular'] : $form['whatsapp']) ?>"></div>
            <div class="form-field full"><label for="observacoes">Observações</label><textarea id="observacoes" name="observacoes" rows="3" maxlength="500" placeholder="Conte alguma necessidade específica da sua empresa."><?= e($form['observacoes']) ?></textarea></div>
          </div>

          <label class="consent-row"><input type="checkbox" name="aceite" value="1" required <?= $form['aceite'] === '1' ? 'checked' : '' ?>><span>Autorizo o contato da <?= e($signupConsentBrand) ?> para ativação, implantação e atendimento comercial. <?= $isResellerSignup ? 'Estou ciente de que a contratação e a cobrança são administradas pela revenda.' : 'Estou ciente de que o teste gratuito não gera cobrança automática.' ?></span></label>
          <div class="form-actions"><button class="btn btn-primary block" type="submit"><?= e($signupButtonLabel) ?></button></div>
        </form>

        <aside class="registration-summary">
          <span class="plan-badge"><?= $isResellerSignup ? 'Proposta da revenda' : 'Plano selecionado' ?></span>
          <h2><?= e($signupProductName) ?></h2>
          <div class="summary-items">
            <?php foreach ($signupQuoteItems as $item): ?><div><span>✓</span><?= e((string)($item['label'] ?? 'Módulo')) ?></div><?php endforeach; ?>
          </div>
          <?php if ($isResellerSignup): ?>
            <div class="trial-period"><strong><?= e($signupSellerName) ?></strong><span>Atendimento, implantação e cobrança pela revenda</span></div>
          <?php else: ?>
            <div class="trial-period"><strong>7 dias grátis</strong><span>Sem cobrança automática</span></div>
          <?php endif; ?>
          <div class="summary-total"><span><?= $isResellerSignup ? 'Mensalidade da proposta' : 'Mensalidade após o teste' ?></span><strong><?= $signupQuoteMonthly > 0 ? money($signupQuoteMonthly) : 'Conforme proposta' ?></strong></div>
          <div class="summary-setup"><span>Implantação e treinamento</span><strong><?= $signupQuoteSetup > 0 ? money($signupQuoteSetup) : 'Conforme proposta' ?></strong></div>
          <?php if ($isResellerSignup && $signupResellerWhatsapp !== ''): ?>
            <p class="summary-note"><strong>WhatsApp da revenda:</strong> <?= e($signupResellerWhatsapp) ?></p>
          <?php endif; ?>
          <p class="summary-note"><?= $isResellerSignup ? ('Os valores e condições serão administrados pela ' . e($signupSellerName) . '.') : 'Os valores serão confirmados pela equipe comercial antes da contratação definitiva.' ?></p>
        </aside>
      </div>
    <?php endif; ?>
  </div>
</section>
<div class="nexa-signup-progress" id="nexa-signup-progress" role="status" aria-live="polite" aria-atomic="true" aria-busy="true" hidden>
  <div class="nexa-signup-progress-panel">
    <div class="nexa-signup-progress-icon"><img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Próspero"></div>
    <div class="nexa-signup-progress-spinner" aria-hidden="true"></div>
    <h2>Criando seu ambiente Nexa Próspero...</h2>
    <p id="nexa-signup-progress-message" class="nexa-signup-progress-message">Estamos preparando o acesso da sua empresa.</p>
    <p class="nexa-signup-progress-help">Aguarde a confirmação. Não feche a página nem clique novamente em Cadastrar.</p>
  </div>
</div>
<script src="/assets/cadastro-empresa-v2.js?v=20261020-1"></script>
</body>
</html>
