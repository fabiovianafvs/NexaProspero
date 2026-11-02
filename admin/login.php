<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/access_control.php';
require_once __DIR__ . '/../config/fiscal_contingency.php';

$erro = '';

/** Somente navegação, sem DDL, sem gerar pedidos e sem consultar gateway bancário.
 * O master da plataforma conserva seu acesso técnico, mas visualiza o aviso
 * quando entra no Admin da empresa de teste ou da empresa contratante.
 */
function nexa_login_subscription_destination(PDO $pdo, int $companyId): ?string {
  $role = auth_role();
  if (!in_array($role, ['company_admin', 'platform_admin'], true)) return null;
  if ($role !== 'platform_admin' && (int)auth_company_id() !== $companyId) return null;
  require_once __DIR__ . '/../config/saas_trial_cycle.php';
  try {
    $sub = tenant_latest_subscription($pdo, $companyId);
    if (!$sub) return null;
    $cycle = nexa_saas_cycle_state($sub);
    if (in_array((string)($cycle['phase'] ?? ''), ['warning', 'last_day', 'grace', 'blocked'], true)) {
      return 'assinatura_regularizar.php';
    }
  } catch (Throwable $e) {
    error_log('[SaaS login] Falha ao verificar aviso de assinatura: '.$e->getMessage());
  }
  return null;
}

if (auth_role() === 'platform_admin' || (in_array(auth_role(), ['company_admin', 'company_user'], true) && auth_company_id() === COMPANY_ID)) {
  try { access_ensure_schema($pdo); } catch (Throwable $e) {}
  $defaultUrl = access_admin_default_url($pdo, (int)COMPANY_ID);
  $renewalUrl = nexa_login_subscription_destination($pdo, (int)COMPANY_ID);
  header('Location: ' . ($renewalUrl ?? fiscal_contingency_post_login_url($pdo, (int)COMPANY_ID, $defaultUrl)));
  exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $senha = (string)($_POST['senha'] ?? '');

  if ($email === '' || $senha === '') {
    $erro = 'Informe e-mail e senha.';
  } else {
    try {
      $st = $pdo->prepare('SELECT id, company_id, name, email, password_hash, role, is_active FROM users WHERE email = ? LIMIT 1');
      $st->execute([$email]);
      $u = $st->fetch(PDO::FETCH_ASSOC);

      $isPlatformMaster = $u && (string)$u['role'] === 'platform_admin' && (int)$u['is_active'] === 1;
      $isCompanyUser = $u
        && (int)$u['company_id'] === COMPANY_ID
        && in_array((string)$u['role'], ['company_admin','company_user'], true)
        && (int)$u['is_active'] === 1;

      if (!$isPlatformMaster && !$isCompanyUser) {
        $erro = 'Acesso não permitido.';
      } elseif (!password_verify($senha, (string)$u['password_hash'])) {
        $erro = 'E-mail ou senha inválidos.';
      } else {
        auth_login($u);
        try { access_ensure_schema($pdo); } catch (Throwable $e) {}
        $defaultUrl = access_admin_default_url($pdo, (int)COMPANY_ID);
        $renewalUrl = nexa_login_subscription_destination($pdo, (int)COMPANY_ID);
        header('Location: ' . ($renewalUrl ?? fiscal_contingency_post_login_url($pdo, (int)COMPANY_ID, $defaultUrl)));
        exit;
      }
    } catch (Throwable $e) {
      $erro = 'Erro ao autenticar. Tente novamente.';
    }
  }
}

$companyName = trim((string)($company['name'] ?? $APP_CFG['site_name'] ?? 'Minha empresa'));
if ($companyName === '') $companyName = 'Minha empresa';

$catalogUrl = '../index.php?company=' . rawurlencode(COMPANY_SLUG);

$logoFile = '';
try {
  $bizLogin = function_exists('load_business_config') ? load_business_config(COMPANY_ID) : [];
  $logoFile = trim((string)($bizLogin['logo']['file'] ?? ''));
} catch (Throwable $e) {
  $bizLogin = [];
}

$logoUrl = $logoFile !== '' ? ('/uploads/logos/' . rawurlencode(basename($logoFile))) : '/assets/logo-nexa-prospero.jpg';

$primary = '#0B1F5B';
$secondary = '#2454D6';
$accent = '#00B8FF';
$gradient = '#06122E';

try {
  $adminTheme = (array)($bizLogin['admin_theme'] ?? []);
  if (!empty($adminTheme['enabled'])) {
    foreach ([
      'primary_color' => 'primary',
      'secondary_color' => 'secondary',
      'accent_color' => 'accent',
      'gradient_color' => 'gradient',
    ] as $key => $var) {
      $value = strtoupper(trim((string)($adminTheme[$key] ?? '')));
      if (preg_match('/^#[0-9A-F]{6}$/', $value)) {
        $$var = $value;
      }
    }
  }
} catch (Throwable $e) {
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Admin - Login</title>
<style>
:root{
  --primary:<?= e($primary) ?>;
  --secondary:<?= e($secondary) ?>;
  --accent:<?= e($accent) ?>;
  --gradient:<?= e($gradient) ?>;
  --text:#0B1F5B;
  --muted:#5B6475;
  --line:#DCE7F5;
  --bg:#F4F7FB;
  --white:#fff;
  --error:#B91C1C;
}
*{box-sizing:border-box}
html{min-height:100%}
body{
  margin:0;
  min-height:100vh;
  font-family:"Segoe UI",system-ui,-apple-system,BlinkMacSystemFont,Arial,sans-serif;
  color:var(--text);
  background:
    radial-gradient(circle at 12% 10%,color-mix(in srgb,var(--accent) 16%,transparent),transparent 28%),
    radial-gradient(circle at 90% 5%,color-mix(in srgb,var(--secondary) 16%,transparent),transparent 24%),
    var(--bg);
  display:grid;
  place-items:center;
  padding:24px;
  -webkit-font-smoothing:antialiased;
}
.login-shell{
  width:min(100%,980px);
  display:grid;
  grid-template-columns:1fr 460px;
  background:var(--white);
  border:1px solid rgba(36,84,214,.12);
  border-radius:32px;
  overflow:hidden;
  box-shadow:0 28px 70px rgba(11,31,91,.16);
}
.login-hero{
  position:relative;
  min-height:520px;
  padding:38px;
  color:#fff;
  background:
    radial-gradient(circle at 20% 10%,color-mix(in srgb,var(--accent) 30%,transparent),transparent 32%),
    linear-gradient(135deg,var(--primary),var(--secondary) 58%,var(--gradient) 100%);
  display:flex;
  flex-direction:column;
  justify-content:space-between;
  overflow:hidden;
}
.login-hero::after{
  content:"";
  position:absolute;
  right:-90px;
  bottom:-120px;
  width:320px;
  height:320px;
  border-radius:999px;
  background:rgba(255,255,255,.09);
}
.brand{
  position:relative;
  z-index:1;
  display:flex;
  gap:14px;
  align-items:center;
}
.brand img{
  width:70px;
  height:70px;
  object-fit:contain;
  background:#fff;
  border-radius:20px;
  padding:7px;
  box-shadow:0 14px 34px rgba(0,0,0,.18);
}
.brand strong{
  display:block;
  font-size:26px;
  line-height:1.05;
  letter-spacing:-.04em;
}
.brand span{
  display:block;
  color:#DFF6FF;
  font-weight:800;
  margin-top:4px;
}
.hero-text{
  position:relative;
  z-index:1;
}
.hero-text h1{
  max-width:420px;
  font-size:42px;
  line-height:1.02;
  margin:0 0 14px;
  letter-spacing:-.06em;
  color:#fff;
}
.hero-text p{
  max-width:470px;
  font-size:17px;
  line-height:1.55;
  color:#EAF8FF;
  margin:0;
}
.login-form{
  padding:38px;
  display:flex;
  flex-direction:column;
  justify-content:center;
}
.login-form h2{
  font-size:30px;
  margin:0 0 6px;
  letter-spacing:-.045em;
  color:var(--text);
}
.hint{
  margin:0 0 24px;
  color:var(--muted);
  line-height:1.5;
}
.notice{
  background:#FEF2F2;
  color:var(--error);
  border:1px solid #FECACA;
  border-radius:16px;
  padding:13px 14px;
  margin-bottom:16px;
  font-weight:750;
}
label{
  display:block;
  font-weight:900;
  margin:14px 0 7px;
  color:var(--text);
}
input{
  width:100%;
  height:48px;
  border-radius:15px;
  border:1px solid var(--line);
  padding:0 14px;
  font-size:15px;
  outline:none;
  color:var(--text);
  background:#fff;
}
input:focus{
  border-color:color-mix(in srgb,var(--accent) 85%,#fff);
  box-shadow:0 0 0 4px color-mix(in srgb,var(--accent) 14%,transparent);
}
.actions{
  display:flex;
  gap:10px;
  align-items:center;
  margin-top:18px;
  flex-wrap:wrap;
}
.np-btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  min-height:46px;
  border-radius:999px;
  padding:12px 20px;
  font-weight:900;
  text-decoration:none;
  border:1px solid transparent;
  cursor:pointer;
  background:linear-gradient(135deg,var(--secondary),var(--accent));
  color:#fff;
  box-shadow:0 14px 30px color-mix(in srgb,var(--secondary) 22%,transparent);
}
.np-btn.secondary{
  background:#fff;
  color:var(--text);
  border-color:var(--line);
  box-shadow:none;
}
.np-btn:hover{transform:translateY(-1px)}
.footer-note{
  margin-top:18px;
  color:#64748B;
  font-size:12px;
  line-height:1.45;
}
@media(max-width:820px){
  body{padding:18px}
  .login-shell{grid-template-columns:1fr}
  .login-hero{min-height:auto;gap:58px}
  .hero-text h1{font-size:34px}
}
@media(max-width:560px){
  body{padding:12px;place-items:start center}
  .login-shell{border-radius:24px;margin-top:14px}
  .login-hero,.login-form{padding:24px}
  .brand img{width:60px;height:60px;border-radius:18px}
  .brand strong{font-size:22px}
  .hero-text h1{font-size:31px}
  .actions .np-btn{width:100%}
}
</style>
</head>
<body>
  <main class="login-shell">
    <section class="login-hero">
      <div class="brand">
        <img src="<?= e($logoUrl) ?>" alt="<?= e($companyName) ?>" onerror="this.src='/assets/logo-nexa-prospero.jpg'">
        <div>
          <strong><?= e($companyName) ?></strong>
          <span>Painel administrativo</span>
        </div>
      </div>

      <div class="hero-text">
        <h1>Controle sua empresa em um só lugar.</h1>
        <p>Acompanhe pedidos, produtos, WhatsApp automático, integrações e resultados com uma experiência profissional.</p>
      </div>
    </section>

    <section class="login-form">
      <h2>Acessar Admin</h2>
      <p class="hint">Entre com o usuário administrador da empresa para gerenciar o catálogo e os pedidos.</p>

      <?php if ($erro): ?><div class="notice"><?= e($erro) ?></div><?php endif; ?>

      <form method="post" autocomplete="on">
        <label>E-mail</label>
        <input name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="username" placeholder="seuemail@empresa.com" inputmode="email">

        <label>Senha</label>
        <input name="senha" type="password" autocomplete="current-password" placeholder="Sua senha">

        <div class="actions">
          <button class="np-btn" type="submit">Entrar</button>
          <a class="np-btn secondary" href="<?= e($catalogUrl) ?>">Voltar ao catálogo</a>
        </div>
      </form>

      <div class="footer-note">
        Ambiente seguro Nexa Próspero. O acesso é restrito aos administradores cadastrados para esta empresa.
      </div>
    </section>
  </main>
</body>
</html>
