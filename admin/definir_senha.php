<?php
declare(strict_types=1);

/**
 * Página pública para o usuário criar/redefinir a própria senha a partir do
 * link seguro enviado pelo WhatsApp (config/password_setup.php).
 * Não exige login: a autorização é o próprio token de uso único.
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Referrer-Policy: no-referrer');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/password_setup.php';

$token = trim((string)($_POST['t'] ?? $_GET['t'] ?? ''));
$tokenRow = password_setup_find_valid($pdo, $token, (int)COMPANY_ID);
$erro = '';
$done = false;
$eadUpdated = false;

if ($tokenRow && ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $senha = (string)($_POST['senha'] ?? '');
  $confirma = (string)($_POST['confirma'] ?? '');
  $erro = password_setup_validate_password($senha, $confirma);
  if ($erro === '') {
    try {
      $res = password_setup_consume($pdo, $tokenRow, $senha);
      $done = true;
      $eadUpdated = !empty($res['ead']['ok']);
    } catch (Throwable $e) {
      $erro = 'Não foi possível salvar a senha. Solicite um novo link.';
    }
  }
}

$companyName = trim((string)($company['name'] ?? 'Minha empresa')) ?: 'Minha empresa';
$logoFile = '';
try {
  $biz = function_exists('load_business_config') ? load_business_config(COMPANY_ID) : [];
  $logoFile = trim((string)($biz['logo']['file'] ?? ''));
} catch (Throwable $e) {}
$logoUrl = $logoFile !== '' ? ('/uploads/logos/' . rawurlencode(basename($logoFile))) : '/assets/logo-nexa-prospero.jpg';
$isReset = $tokenRow && (string)$tokenRow['purpose'] === 'reset';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= $isReset ? 'Redefinir senha' : 'Criar senha' ?> - <?= e($companyName) ?></title>
<style>
:root{--primary:#0B1F5B;--secondary:#2454D6;--muted:#5B6475;--line:#DCE7F5;--bg:#F4F7FB;--error:#B91C1C;--ok:#047857}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--primary)}
.card{width:min(440px,100%);background:#fff;border:1px solid var(--line);border-radius:22px;padding:28px;box-shadow:0 18px 45px rgba(11,31,91,.08)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.brand img{width:52px;height:52px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff}
.brand strong{display:block;font-size:17px}.brand span{font-size:13px;color:var(--muted)}
h1{margin:0 0 6px;font-size:24px}p{margin:0 0 16px;color:var(--muted);line-height:1.5}
label{display:block;font-weight:700;margin:14px 0 6px;font-size:14px}
input[type=password]{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:12px;font-size:16px;background:#F8FAFD}
input[type=password]:focus{outline:2px solid var(--secondary);border-color:transparent}
.hint{font-size:12px;color:var(--muted);margin-top:6px}
.btn{display:block;width:100%;margin-top:20px;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;font-size:16px;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}
.msg{padding:12px 14px;border-radius:12px;margin-bottom:14px;font-size:14px;line-height:1.45}
.msg.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--error)}
.msg.ok{background:#ECFDF5;border:1px solid #A7F3D0;color:var(--ok)}
.user{background:#F8FAFD;border:1px solid var(--line);border-radius:12px;padding:10px 12px;font-size:14px;word-break:break-all}
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="<?= e($logoUrl) ?>" alt="">
    <div><strong><?= e($companyName) ?></strong><span>Painel administrativo</span></div>
  </div>

  <?php if ($done): ?>
    <h1>Senha criada!</h1>
    <div class="msg ok">Sua senha foi salva com sucesso. Use seu e-mail e a nova senha para entrar no painel<?= $eadUpdated ? ' e nos treinamentos' : '' ?>.</div>
    <a class="btn" href="login.php">Entrar no painel</a>

  <?php elseif (!$tokenRow): ?>
    <h1>Link inválido ou expirado</h1>
    <div class="msg err">Este link já foi usado ou passou do prazo de validade. Por segurança, cada link funciona uma única vez.</div>
    <p>Peça um novo link ao suporte ou ao administrador do sistema.</p>
    <a class="btn" href="login.php">Ir para o login</a>

  <?php else: ?>
    <h1><?= $isReset ? 'Redefinir sua senha' : 'Crie sua senha' ?></h1>
    <p>Escolha uma senha para acessar o painel. Somente você vai conhecê-la.</p>
    <div class="user">Usuário: <strong><?= e((string)$tokenRow['user_email']) ?></strong></div>
    <?php if ($erro !== ''): ?><div class="msg err" style="margin-top:14px"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" autocomplete="off">
      <input type="hidden" name="t" value="<?= e($token) ?>">
      <input type="text" name="username" value="<?= e((string)$tokenRow['user_email']) ?>" autocomplete="username" hidden>
      <label for="senha">Nova senha</label>
      <input id="senha" name="senha" type="password" autocomplete="new-password" required minlength="<?= (int)NEXA_PASSWORD_MIN_LENGTH ?>">
      <div class="hint">Mínimo de <?= (int)NEXA_PASSWORD_MIN_LENGTH ?> caracteres, com letras e números.</div>
      <label for="confirma">Repita a nova senha</label>
      <input id="confirma" name="confirma" type="password" autocomplete="new-password" required minlength="<?= (int)NEXA_PASSWORD_MIN_LENGTH ?>">
      <button class="btn" type="submit">Salvar senha</button>
    </form>
  <?php endif; ?>
</main>
</body>
</html>
