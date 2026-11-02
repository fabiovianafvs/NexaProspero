<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/helpers.php';
require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/auth.php';

$st = $pdo->query("SELECT COUNT(*) FROM users WHERE role='platform_admin'");
if ((int)$st->fetchColumn() > 0) {
  header('Location: login.php');
  exit;
}

$erro = '';
$ok = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? 'Administrador'));
  $email = trim((string)($_POST['email'] ?? ''));
  $senha = (string)($_POST['senha'] ?? '');

  if ($email === '' || $senha === '') {
    $erro = 'Informe e-mail e senha.';
  } else {
    $hash = password_hash($senha, PASSWORD_DEFAULT);
    $st = $pdo->prepare("INSERT INTO users (company_id, name, email, password_hash, role, is_active) VALUES (NULL, ?, ?, ?, 'platform_admin', 1)");
    $st->execute([$name, $email, $hash]);
    $ok = 'Administrador criado. Faça login.';
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Central SaaS - Setup</title>
<style>
:root{--azul-escuro:#0b1f5b;--azul:#2454d6;--ciano:#00b8ff;--fundo:#f4f7fb;--texto:#0b1f5b;--muted:#5b6475;--linha:#dce7f5;--erro:#b91c1c;--ok:#065f46}*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Segoe UI",Arial,sans-serif;color:var(--texto);background:radial-gradient(circle at 12% 10%,rgba(0,184,255,.16),transparent 28%),radial-gradient(circle at 90% 5%,rgba(36,84,214,.16),transparent 24%),var(--fundo);display:grid;place-items:center;padding:24px}.setup-card{width:min(100%,560px);background:#fff;border:1px solid rgba(36,84,214,.12);border-radius:32px;padding:34px;box-shadow:0 28px 70px rgba(11,31,91,.16)}.brand{display:flex;gap:14px;align-items:center;margin-bottom:24px}.brand img{width:64px;height:64px;object-fit:contain;background:#fff;border-radius:18px;padding:6px;box-shadow:0 12px 30px rgba(11,31,91,.14)}.brand strong{display:block;font-size:24px;letter-spacing:-.04em}.brand span{display:block;color:var(--muted);font-weight:700;margin-top:3px}h2{font-size:30px;margin:0 0 8px;letter-spacing:-.04em}.hint{margin:0 0 22px;color:var(--muted);line-height:1.5}.notice{border-radius:16px;padding:13px 14px;margin-bottom:16px;font-weight:700}.notice-ok{background:#ecfdf5;color:var(--ok);border:1px solid #a7f3d0}.notice-error{background:#fef2f2;color:var(--erro);border:1px solid #fecaca}label{display:block;font-weight:900;margin:14px 0 7px;color:#0b1f5b}input{width:100%;height:48px;border-radius:15px;border:1px solid var(--linha);padding:0 14px;font-size:15px;outline:none;color:#0b1f5b}input:focus{border-color:rgba(0,184,255,.85);box-shadow:0 0 0 4px rgba(0,184,255,.14)}.actions{display:flex;gap:10px;align-items:center;margin-top:18px;flex-wrap:wrap}.np-btn{display:inline-flex;align-items:center;justify-content:center;min-height:46px;border-radius:999px;padding:12px 20px;font-weight:900;text-decoration:none;border:1px solid transparent;cursor:pointer;background:linear-gradient(135deg,var(--azul),var(--ciano));color:#fff;box-shadow:0 14px 30px rgba(36,84,214,.22)}.np-btn.secondary{background:#fff;color:#0b1f5b;border-color:#dce7f5;box-shadow:none}.np-btn:hover{transform:translateY(-1px)}@media(max-width:560px){body{padding:14px}.setup-card{padding:24px}.actions .np-btn{width:100%}}
</style>
</head>
<body>
  <main class="setup-card">
    <div class="brand">
      <img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Próspero">
      <div><strong>Central SaaS</strong><span>Nexa Próspero</span></div>
    </div>

    <h2>Criar 1º Administrador</h2>
    <p class="hint">Configure o primeiro acesso administrativo da plataforma.</p>

    <?php if ($erro): ?><div class="notice notice-error"><?= e($erro) ?></div><?php endif; ?>
    <?php if ($ok): ?><div class="notice notice-ok"><?= e($ok) ?></div><?php endif; ?>

    <form method="post">
      <label>Nome</label>
      <input name="name" value="<?= e((string)($_POST['name'] ?? 'Administrador')) ?>">

      <label>E-mail</label>
      <input name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" placeholder="admin@dominio.com">

      <label>Senha</label>
      <input name="senha" type="password" placeholder="Defina uma senha forte">

      <div class="actions">
        <button class="np-btn" type="submit">Criar administrador</button>
        <a class="np-btn secondary" href="login.php">Voltar</a>
      </div>
    </form>
  </main>
</body>
</html>
