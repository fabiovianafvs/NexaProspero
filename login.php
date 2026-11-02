<?php
declare(strict_types=1);
session_start();

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/saas_guard.php';

$guard = saas_company_is_blocked($pdo, $company);
if ($guard['blocked']) {
  saas_render_block_page('Admin temporariamente bloqueado', $guard['reason']);
}

if (!empty($_SESSION['user'])) {
  header('Location: ' . company_admin_url($company, 'admin/area_trabalho.php'));
  exit;
}

$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $email = trim((string)($_POST['email'] ?? ''));
  $senha = (string)($_POST['senha'] ?? '');

  if ($email === '' || $senha === '') {
    $erro = 'Informe e-mail e senha.';
  } else {
    try {
      $sql = "SELECT * FROM users WHERE email = ? AND role IN ('company_admin','company_user') AND is_active = 1 AND company_id = ? LIMIT 1";
      $st = $pdo->prepare($sql);
      $st->execute([$email, COMPANY_ID]);
      $u = $st->fetch(PDO::FETCH_ASSOC) ?: null;

      if (!$u || !password_verify($senha, (string)$u['password_hash'])) {
        $erro = 'Login inválido.';
      } else {
        login_user($u);
        header('Location: ' . company_admin_url($company, 'admin/area_trabalho.php'));
        exit;
      }
    } catch (Throwable $e) {
      $erro = 'Falha no login: ' . $e->getMessage();
    }
  }
}
?>
<!doctype html><html lang="pt-br"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<title>Login da Empresa</title>
<link rel="stylesheet" href="/assets/admin.css">
</head><body>
  <div class="wrap" style="max-width:520px;margin:40px auto;">
    <h2>Login da Empresa</h2>
    <p class="hint" style="margin-top:6px;">Acesse o painel administrativo de <strong><?= e((string)$company['name']) ?></strong>.</p>

    <?php if ($erro): ?><div class="toast toast-error" style="margin:14px 0;"><?= e($erro) ?></div><?php endif; ?>

    <form method="post" class="card" style="padding:16px;">
      <label>E-mail</label>
      <input class="field" name="email" value="<?= e((string)($_POST['email'] ?? '')) ?>" placeholder="email@empresa.com">

      <div style="height:10px"></div>

      <label>Senha</label>
      <input class="field" name="senha" type="password" placeholder="Sua senha">

      <div style="display:flex;gap:10px;align-items:center;margin-top:14px;flex-wrap:wrap;">
        <button class="btn" type="submit">Entrar</button>
        <a class="btn btn-ghost" href="<?= e(company_catalog_url($company)) ?>">Voltar ao catálogo</a>
      </div>
    </form>
  </div>
</body></html>