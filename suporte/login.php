<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/support_portal.php';

support_ensure_schema($pdo);
support_session_start();

if (support_user_id()) {
  header('Location: /suporte/index.php');
  exit;
}

$erro = '';
if (isset($_GET['inactive'])) $erro = 'Seu acesso ao portal de suporte está desativado.';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $senha = (string)($_POST['senha'] ?? '');

  if ($email === '' || $senha === '') {
    $erro = 'Informe e-mail e senha.';
  } else {
    $st = $pdo->prepare('SELECT id, name, email, password_hash, is_active FROM saas_support_users WHERE email = ? LIMIT 1');
    $st->execute([$email]);
    $user = $st->fetch(PDO::FETCH_ASSOC);

    if (!$user || (int)$user['is_active'] !== 1 || !password_verify($senha, (string)$user['password_hash'])) {
      $erro = 'E-mail ou senha inválidos.';
    } else {
      support_login($user);
      try {
        $pdo->prepare('UPDATE saas_support_users SET last_login_at = NOW() WHERE id = ?')->execute([(int)$user['id']]);
      } catch (Throwable $e) {}
      header('Location: /suporte/index.php');
      exit;
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Consulta de Empresas - Nexa Próspero</title>
<style>
:root{--azul-escuro:#0b1f5b;--azul:#2454d6;--ciano:#00b8ff;--fundo:#f4f7fb;--texto:#0b1f5b;--muted:#5b6475;--linha:#dce7f5;--branco:#fff;--erro:#b91c1c}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--texto);background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.16),transparent 28%),radial-gradient(circle at 90% 5%,rgba(36,84,214,.16),transparent 24%),var(--fundo);display:grid;place-items:center;padding:24px}.login-shell{width:min(100%,980px);display:grid;grid-template-columns:1fr 460px;background:#fff;border:1px solid rgba(36,84,214,.12);border-radius:32px;overflow:hidden;box-shadow:0 28px 70px rgba(11,31,91,.16)}.login-hero{padding:38px;background:radial-gradient(circle at 20% 10%,rgba(0,184,255,.30),transparent 32%),linear-gradient(135deg,#0b1f5b,#2454d6 70%,#00b8ff 140%);color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:520px}.brand{display:flex;gap:14px;align-items:center}.brand img{width:70px;height:70px;object-fit:contain;background:#fff;border-radius:20px;padding:7px;box-shadow:0 14px 34px rgba(0,0,0,.18)}.brand strong{display:block;font-size:26px;letter-spacing:-.04em}.brand span{display:block;color:#dff6ff;font-weight:700;margin-top:3px}.hero-text h1{font-size:42px;line-height:1.02;margin:0 0 14px;letter-spacing:-.06em}.hero-text p{font-size:17px;line-height:1.55;color:#eaf8ff;margin:0}.login-form{padding:38px;display:flex;flex-direction:column;justify-content:center}.login-form h2{font-size:30px;margin:0 0 6px;letter-spacing:-.04em}.hint{margin:0 0 24px;color:var(--muted);line-height:1.5}.notice{background:#fef2f2;color:var(--erro);border:1px solid #fecaca;border-radius:16px;padding:13px 14px;margin-bottom:16px;font-weight:650}label{display:block;font-weight:900;margin:14px 0 7px;color:#0b1f5b}input{width:100%;height:48px;border-radius:15px;border:1px solid var(--linha);padding:0 14px;font-size:15px;outline:none;color:#0b1f5b}input:focus{border-color:rgba(0,184,255,.85);box-shadow:0 0 0 4px rgba(0,184,255,.14)}.actions{display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap}.np-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;border-radius:999px;padding:12px 20px;font-weight:900;text-decoration:none;border:1px solid transparent;cursor:pointer;background:linear-gradient(135deg,var(--azul),var(--ciano));color:#fff;box-shadow:0 14px 30px rgba(36,84,214,.22)}.security-note{margin-top:18px;padding:12px 14px;border-radius:14px;background:#f7fbff;border:1px solid #dce7f5;color:var(--muted);font-size:13px;line-height:1.5}@media(max-width:820px){.login-shell{grid-template-columns:1fr}.login-hero{min-height:auto;gap:60px}.hero-text h1{font-size:34px}}@media(max-width:560px){body{padding:14px}.login-hero,.login-form{padding:24px}.actions .np-btn{width:100%}}
</style>
</head>
<body>
<main class="login-shell">
  <section class="login-hero">
    <div class="brand">
      <img src="/assets/logo-nexa-v6-2.png" alt="Nexa Próspero">
      <div><strong>Nexa Próspero</strong><span>Consulta de Empresas</span></div>
    </div>
    <div class="hero-text">
      <h1>Informação rápida para sua equipe de suporte.</h1>
      <p>Consulte empresas, monitore os serviços e acompanhe o armazenamento por empresa sem acesso às áreas administrativas da Central SaaS.</p>
    </div>
  </section>
  <section class="login-form">
    <h2>Acesso do Suporte</h2>
    <p class="hint">Use o usuário exclusivo fornecido para sua equipe.</p>
    <?php if ($erro !== ''): ?><div class="notice"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <label for="email">E-mail</label>
      <input id="email" name="email" type="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" autocomplete="username" required autofocus>
      <label for="senha">Senha</label>
      <input id="senha" name="senha" type="password" autocomplete="current-password" required>
      <div class="actions"><button class="np-btn" type="submit">Entrar</button></div>
    </form>
    <div class="security-note">Este acesso permite consulta cadastral, diagnóstico operacional e indicadores de armazenamento somente para leitura. Não libera cobrança, planos, módulos, senhas ou gerenciamento da plataforma.</div>
  </section>
</main>
</body>
</html>
