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
    if (!in_array($currentPage, ['login.php', 'logout.php', 'definir_senha.php', 'esqueci_senha.php', $renewalPage], true)) {
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

// V13 Multiempresa ISOLADO: com um contexto F11 válido de grupo no modo Isolado, esta
// aba passa a trabalhar inteiramente na empresa escolhida, como se o usuário tivesse
// entrado nela: COMPANY_ID vira a empresa selecionada e TODAS as telas/rotinas usam
// os dados, configurações, séries, caixas e perfil dessa empresa. A empresa do login
// continua em MULTI_COMPANY_ENTRY_ID e a sessão não é alterada (outras abas seguem
// na empresa em que estavam). Grupos Integrados continuam no modelo anterior (escopos).
$mcSwapCompany = null;
if (!empty($MULTI_COMPANY_CONTEXT['valid']) && !empty($MULTI_COMPANY_CONTEXT['active'])
    && (string)($MULTI_COMPANY_CONTEXT['operation_mode'] ?? '') === 'isolated'
    && (int)($MULTI_COMPANY_CONTEXT['company_id'] ?? 0) > 0) {
  try {
    $stSwap = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $stSwap->execute([(int)$MULTI_COMPANY_CONTEXT['company_id']]);
    $mcSwapCompany = $stSwap->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {
    $mcSwapCompany = null;
  }
}
define('MULTI_COMPANY_ENTRY_ID', $tenantCompanyId);
define('MULTI_COMPANY_SWAP_ACTIVE', $mcSwapCompany !== null);
define('MULTI_COMPANY_SWAP_IS_ADMIN', $mcSwapCompany !== null && !empty($MULTI_COMPANY_CONTEXT['is_admin']));
define('MULTI_COMPANY_SWAP_PROFILE_ID', $mcSwapCompany !== null ? (int)($MULTI_COMPANY_CONTEXT['company_profile_id'] ?? 0) : 0);
if ($mcSwapCompany !== null) $company = $mcSwapCompany;

// V13: página aberta pela navegação (link sem token, window.open, location.href) a partir
// de uma tela em outra empresa: recarrega com o token no endereço, para tudo o que esta
// página fizer depois (AJAX, impressão, F5) continuar na mesma empresa.
if (!empty($GLOBALS['MULTI_COMPANY_TOKEN_FROM_REFERER']) && !empty($MULTI_COMPANY_CONTEXT['valid'])
    && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET'
    && strtolower((string)($_SERVER['HTTP_SEC_FETCH_MODE'] ?? '')) === 'navigate'
    && !headers_sent()) {
  $mcUri = (string)($_SERVER['REQUEST_URI'] ?? '');
  if ($mcUri !== '' && str_starts_with($mcUri, '/admin/') && !preg_match('/(?:^|[?&])mc_ctx=/', $mcUri)
      && !preg_match('~/admin/(login|logout|esqueci_senha|definir_senha|multiempresa_context_switch)\.php~i', $mcUri)) {
    header('Location: ' . $mcUri . (str_contains($mcUri, '?') ? '&' : '?') . 'mc_ctx=' . rawurlencode((string)$MULTI_COMPANY_CONTEXT['token']), true, 302);
    exit;
  }
}

// V13: redirecionamentos (header Location) das telas levam o token da aba, para a
// página seguinte continuar na empresa escolhida pelo F11 (inclusive as chamadas
// AJAX dela, que usam o endereço da própria página como origem).
if (!empty($MULTI_COMPANY_CONTEXT['valid']) && (string)($MULTI_COMPANY_CONTEXT['token'] ?? '') !== '' && function_exists('header_register_callback')) {
  header_register_callback(static function (): void {
    $token = (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '');
    if ($token === '' || !preg_match('/^[a-f0-9]{48,128}$/', $token)) return;
    foreach (headers_list() as $h) {
      if (stripos($h, 'Location:') !== 0) continue;
      $url = trim(substr($h, 9));
      if ($url === '' || preg_match('/(?:^|[?&])mc_ctx=/', $url)) return;
      $p = parse_url($url);
      if (!is_array($p)) return;
      $host = strtolower((string)($p['host'] ?? ''));
      if ($host !== '' && $host !== strtolower(preg_replace('/:\d+$/', '', (string)($_SERVER['HTTP_HOST'] ?? '')))) return;
      $path = (string)($p['path'] ?? '');
      // Só páginas do painel: caminho relativo (mesma pasta /admin/) ou /admin/...
      if ($path !== '' && $path[0] === '/' && !str_starts_with($path, '/admin/')) return;
      if ($host === '' && $path !== '' && $path[0] !== '/' && !str_starts_with((string)($_SERVER['SCRIPT_NAME'] ?? ''), '/admin/')) return;
      if (preg_match('/(?:^|\/)(login|logout)\.php$/i', $path)) return;
      $frag = '';
      if (($hp = strpos($url, '#')) !== false) { $frag = substr($url, $hp); $url = substr($url, 0, $hp); }
      header('Location: ' . $url . (str_contains($url, '?') ? '&' : '?') . 'mc_ctx=' . $token . $frag, true);
      return;
    }
  });
}

// Fora do modo Isolado: COMPANY_ID continua sendo a empresa do subdomínio/tenant.
define('COMPANY_ID', $mcSwapCompany !== null ? (int)$mcSwapCompany['id'] : $tenantCompanyId);
define('COMPANY_SLUG', (string)$company['slug']);

$APP_CFG = load_app_config(COMPANY_ID);
$ADMIN_BUSINESS_CFG = load_business_config(COMPANY_ID);
app_apply_company_timezone($pdo, (string)($ADMIN_BUSINESS_CFG['timezone'] ?? 'America/Sao_Paulo'));

