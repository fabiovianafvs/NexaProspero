<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/auth.php';
require_once __DIR__ . '/../config/resellers.php';
require_once __DIR__ . '/../config/reseller_integrations.php';

try {
    reseller_ensure_schema($pdo);
    reseller_integrations_ensure_schema($pdo);
} catch (Throwable $e) {
    // A tela continua sendo exibida; eventuais problemas estruturais serão
    // apresentados de forma amigável em vez de gerar um erro fatal.
}

$reseller = reseller_resolve_context($pdo, true);
$brand = trim((string)($reseller['reseller_brand_name'] ?? $reseller['name'] ?? 'Revenda Nexa Próspero'));
if ($brand === '') {
    $brand = 'Revenda Nexa Próspero';
}

function reseller_login_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

$error = '';

if (!$reseller) {
    http_response_code(404);
    $error = 'Esta revenda não foi encontrada ou ainda não está ativa na Central SaaS.';
} else {
    $companyId = (int)$reseller['id'];

    if (
        auth_user_id()
        && in_array(auth_role(), ['company_admin', 'company_user'], true)
        && auth_company_id() === $companyId
    ) {
        header('Location: /revenda/');
        exit;
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $email = trim((string)($_POST['email'] ?? ''));
        $password = (string)($_POST['senha'] ?? '');

        if ($email === '' || $password === '') {
            $error = 'Informe o e-mail e a senha.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Informe um e-mail válido.';
        } else {
            try {
                $st = $pdo->prepare(
                    "SELECT id, company_id, name, email, password_hash, role, is_active
                       FROM users
                      WHERE email = ?
                        AND company_id = ?
                        AND role IN ('company_admin', 'company_user')
                      LIMIT 1"
                );
                $st->execute([$email, $companyId]);
                $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;

                if (!$user || (int)$user['is_active'] !== 1 || !password_verify($password, (string)$user['password_hash'])) {
                    $error = 'E-mail ou senha inválidos.';
                } else {
                    if (session_status() !== PHP_SESSION_ACTIVE) {
                        auth_start();
                    }
                    session_regenerate_id(true);
                    auth_login($user);
                    header('Location: /revenda/');
                    exit;
                }
            } catch (Throwable $e) {
                $error = 'Não foi possível entrar agora. Tente novamente em alguns instantes.';
            }
        }
    }
}

$theme = $reseller ? reseller_theme_config($reseller) : ['primary'=>'#0b1f5b','secondary'=>'#2454d6','menu_text'=>'#ffffff'];
$logoUrl = $reseller ? reseller_theme_logo_url($reseller) : '/assets/logo-nexa-prospero.jpg';
$landingUrl = '/montesuaproposta';
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acesso da revenda | <?= reseller_login_h($brand) ?></title>
<style>
:root{--primary:<?= reseller_login_h((string)$theme['primary']) ?>;--secondary:<?= reseller_login_h((string)$theme['secondary']) ?>;--accent:#00b8ff;--bg:#f4f7fb;--line:#dce7f5;--muted:#64748b;--danger:#991b1b}*{box-sizing:border-box}html{min-height:100%}body{margin:0;min-height:100vh;font-family:"Segoe UI",system-ui,-apple-system,BlinkMacSystemFont,Arial,sans-serif;color:var(--primary);background:radial-gradient(circle at 12% 8%,rgba(0,184,255,.18),transparent 28%),radial-gradient(circle at 92% 4%,rgba(36,84,214,.17),transparent 26%),var(--bg);display:grid;place-items:center;padding:24px}.login-shell{width:min(100%,980px);display:grid;grid-template-columns:minmax(0,1fr) 440px;background:#fff;border:1px solid rgba(36,84,214,.12);border-radius:32px;overflow:hidden;box-shadow:0 28px 70px rgba(11,31,91,.16)}.login-hero{position:relative;min-height:540px;padding:38px;color:#fff;background:radial-gradient(circle at 92% 5%,rgba(0,184,255,.34),transparent 35%),linear-gradient(135deg,var(--primary),var(--secondary));display:flex;flex-direction:column;justify-content:space-between;overflow:hidden}.login-hero:after{content:"";position:absolute;right:-105px;bottom:-130px;width:340px;height:340px;border-radius:50%;background:rgba(255,255,255,.08)}.brand{position:relative;z-index:1;display:flex;gap:14px;align-items:center}.brand img{width:68px;height:64px;object-fit:contain;background:#fff;padding:7px;border-radius:19px;box-shadow:0 14px 34px rgba(0,0,0,.18)}.brand strong{display:block;font-size:25px;line-height:1.08}.brand span{display:block;color:#dff6ff;font-weight:750;margin-top:4px}.hero-copy{position:relative;z-index:1}.hero-copy span{display:inline-flex;padding:7px 11px;border-radius:999px;background:rgba(255,255,255,.14);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.04em}.hero-copy h1{max-width:500px;margin:16px 0 13px;font-size:44px;line-height:1.02;letter-spacing:-.055em}.hero-copy p{max-width:500px;margin:0;color:#eaf8ff;font-size:17px;line-height:1.55}.login-form{padding:38px;display:flex;flex-direction:column;justify-content:center}.login-form h2{font-size:31px;letter-spacing:-.045em;margin:0 0 7px}.hint{margin:0 0 21px;color:var(--muted);line-height:1.5}.notice{padding:13px 14px;margin-bottom:14px;border:1px solid #fecaca;border-radius:15px;background:#fef2f2;color:var(--danger);font-weight:750;line-height:1.4}label{display:block;margin:14px 0 7px;font-weight:900}input{width:100%;height:49px;padding:0 14px;border:1px solid var(--line);border-radius:15px;background:#fff;color:var(--primary);font-size:15px;outline:none}input:focus{border-color:var(--accent);box-shadow:0 0 0 4px rgba(0,184,255,.13)}button,.link-button{display:flex;align-items:center;justify-content:center;width:100%;min-height:50px;margin-top:19px;border:0;border-radius:999px;background:linear-gradient(135deg,var(--secondary),var(--accent));color:#fff;font-size:15px;font-weight:900;text-decoration:none;cursor:pointer;box-shadow:0 13px 28px rgba(36,84,214,.22)}.secondary-link{display:block;margin-top:17px;text-align:center;color:var(--secondary);font-weight:850;text-decoration:none}.security{display:flex;gap:9px;align-items:flex-start;margin-top:22px;padding-top:18px;border-top:1px solid #edf2f8;color:var(--muted);font-size:13px;line-height:1.45}.security b{color:var(--primary)}@media(max-width:820px){body{padding:14px}.login-shell{grid-template-columns:1fr;max-width:520px}.login-hero{min-height:auto;padding:27px}.hero-copy{margin-top:54px}.hero-copy h1{font-size:35px}.login-form{padding:28px}}@media(max-width:480px){body{padding:0;background:#fff}.login-shell{min-height:100vh;border:0;border-radius:0;box-shadow:none}.login-hero{padding:22px}.brand img{width:56px;height:54px}.brand strong{font-size:21px}.hero-copy{margin-top:38px}.hero-copy h1{font-size:31px}.hero-copy p{font-size:15px}.login-form{padding:25px 22px 32px}}
</style>
</head>
<body>
<div class="login-shell">
  <section class="login-hero">
    <div class="brand">
      <img src="<?= reseller_login_h($logoUrl) ?>" alt="<?= reseller_login_h($brand) ?>">
      <div><strong><?= reseller_login_h($brand) ?></strong><span>Parceiro Nexa Próspero</span></div>
    </div>
    <div class="hero-copy">
      <span>Painel exclusivo</span>
      <h1>Gerencie clientes, preços e cobranças em um só lugar.</h1>
      <p>Acesse sua operação de revenda para acompanhar clientes vinculados, configurar valores regionais e consultar as faturas consolidadas.</p>
    </div>
  </section>
  <main class="login-form">
    <h2>Entrar no painel</h2>
    <p class="hint">Use o mesmo e-mail e a mesma senha do administrador da empresa cadastrada como revenda.</p>
    <?php if ($error !== ''): ?><div class="notice"><?= reseller_login_h($error) ?></div><?php endif; ?>
    <?php if ($reseller): ?>
    <form method="post" autocomplete="on">
      <label for="email">E-mail</label>
      <input id="email" type="email" name="email" value="<?= reseller_login_h((string)($_POST['email'] ?? '')) ?>" autocomplete="username" required autofocus>
      <label for="senha">Senha</label>
      <input id="senha" type="password" name="senha" autocomplete="current-password" required>
      <button type="submit">Entrar no painel da revenda</button>
    </form>
    <?php endif; ?>
    <a class="secondary-link" href="<?= reseller_login_h($landingUrl) ?>">← Voltar para a página de propostas</a>
    <div class="security"><span>🔒</span><span><b>Acesso protegido.</b> Somente usuários ativos vinculados à empresa revendedora podem entrar.</span></div>
  </main>
</div>
</body>
</html>
