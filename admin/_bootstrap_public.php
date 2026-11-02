<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/tenant.php';
require_once __DIR__ . '/../config/load_config.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../saas/saas_guard.php';

$companySlug = (string)($_GET['company'] ?? '');
// Para o Admin, primeiro identifica a empresa; o bloqueio SaaS e tratado em
// seguida, permitindo autenticar o responsavel e exibir a regularizacao.
// O catalogo publico continua usando tenant_resolve_company sem bypass.
$company = tenant_resolve_company($pdo, $companySlug, true);
if (auth_role() !== 'platform_admin') {
  $guard = saas_company_is_blocked($pdo, $company);
  if (!empty($guard['blocked'])) {
    $currentPage = strtolower(basename((string)($_SERVER['SCRIPT_NAME'] ?? '')));
    $renewalPage = 'assinatura_regularizar.php';
    if (!in_array($currentPage, ['login.php', 'logout.php', $renewalPage], true)) {
      $loggedInHere = auth_user_id() && in_array(auth_role(), ['company_admin','company_user'], true)
        && (int)auth_company_id() === (int)($company['id'] ?? 0);
      $target = $loggedInHere ? $renewalPage : 'login.php';
      header('Location: ' . $target, true, 302);
      exit;
    }
  }
}

// V3 Multiempresa: preserva integralmente o tenant/COMPANY_ID legado e resolve,
// em paralelo, um contexto seguro por aba. Nenhuma rotina operacional usa o novo
// contexto automaticamente nesta versão.
$tenantCompanyId = tenant_company_id($company);
define('TENANT_COMPANY_ID', $tenantCompanyId);
define('TENANT_COMPANY_SLUG', (string)$company['slug']);

require_once __DIR__ . '/../config/multi_company.php';
$MULTI_COMPANY_CONTEXT = multi_company_context_resolve($pdo, $tenantCompanyId);
define('MULTI_COMPANY_CONTEXT_ID', (int)($MULTI_COMPANY_CONTEXT['company_id'] ?? $tenantCompanyId));
define('MULTI_COMPANY_CONTEXT_ACTIVE', !empty($MULTI_COMPANY_CONTEXT['active']));
define('MULTI_COMPANY_CONTEXT_VALID', !empty($MULTI_COMPANY_CONTEXT['valid']));

// Compatibilidade inviolável da V3: COMPANY_ID continua sendo a empresa do
// subdomínio/tenant. A futura ativação operacional será feita em etapa própria.
define('COMPANY_ID', $tenantCompanyId);
define('COMPANY_SLUG', (string)$company['slug']);

$APP_CFG = load_app_config(COMPANY_ID);
$ADMIN_BUSINESS_CFG = load_business_config(COMPANY_ID);
app_apply_company_timezone($pdo, (string)($ADMIN_BUSINESS_CFG['timezone'] ?? 'America/Sao_Paulo'));

