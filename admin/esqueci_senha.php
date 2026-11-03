<?php
declare(strict_types=1);

/**
 * "Esqueci minha senha": envia um link seguro (config/password_setup.php) para o
 * usuário criar uma nova senha.
 *
 * Canais, nesta ordem:
 *  - e-mail do usuário, quando é um endereço real (Central SaaS > E-mail);
 *  - WhatsApp da empresa, somente para o administrador (Central SaaS > WhatsApp).
 * Usuários com e-mail fictício (usado só como login) são orientados a pedir ao
 * administrador da empresa, que troca a senha em Tabelas > Usuários.
 */

header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/_bootstrap_public.php';
require_once __DIR__ . '/../config/password_setup.php';
require_once __DIR__ . '/../config/saas_email.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';

auth_start();
if (empty($_SESSION['forgot_pw_csrf'])) $_SESSION['forgot_pw_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['forgot_pw_csrf'];

if (!function_exists('forgot_mask_email')) {
  function forgot_mask_email(string $email): string {
    [$user, $domain] = array_pad(explode('@', $email, 2), 2, '');
    $visible = function_exists('mb_substr') ? mb_substr($user, 0, 2) : substr($user, 0, 2);
    return $visible . str_repeat('*', max(3, strlen($user) - 2)) . '@' . $domain;
  }
}
if (!function_exists('forgot_mask_phone')) {
  function forgot_mask_phone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone) ?? '';
    return $digits === '' ? '' : 'final ' . substr($digits, -4);
  }
}

$companyName = trim((string)($company['name'] ?? 'Minha empresa')) ?: 'Minha empresa';
$login = '';
$sentTo = [];
$submitted = false;
$erro = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  $login = trim((string)($_POST['login'] ?? ''));
  try {
    if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) throw new RuntimeException('A página expirou. Tente novamente.');
    if ($login === '') throw new RuntimeException('Informe o e-mail que você usa para entrar no sistema.');
    if (!password_setup_ready($pdo)) throw new RuntimeException('Recuperação de senha indisponível no momento. Procure o administrador da sua empresa.');

    // Limite por sessão: no máximo 5 pedidos a cada 15 minutos.
    $now = time();
    $hits = array_values(array_filter((array)($_SESSION['forgot_pw_hits'] ?? []), static fn($t) => (int)$t > $now - 900));
    if (count($hits) >= 5) throw new RuntimeException('Muitas tentativas. Aguarde alguns minutos e tente novamente.');
    $hits[] = $now;
    $_SESSION['forgot_pw_hits'] = $hits;
    $submitted = true;

    $st = $pdo->prepare("SELECT id, name, email, role FROM users WHERE company_id = ? AND LOWER(email) = LOWER(?) AND is_active = 1 AND role IN ('company_admin','company_user') LIMIT 1");
    $st->execute([(int)COMPANY_ID, $login]);
    $user = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    if ($user) {
      // Limite por usuário: no máximo 3 links por hora.
      $st = $pdo->prepare('SELECT COUNT(*) FROM user_password_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
      $st->execute([(int)$user['id']]);
      $recent = (int)$st->fetchColumn();

      $emailCfg = saas_email_load($pdo);
      $userEmail = (string)$user['email'];
      $canEmail = !empty($emailCfg['send_password_reset']) && saas_email_ready($emailCfg) && saas_email_is_real_address($userEmail);

      $companyPhone = trim((string)($company['whatsapp'] ?? '')) ?: trim((string)($company['celular'] ?? ''));
      $waCfg = saas_whatsapp_load_config($pdo);
      $canWhatsapp = (string)$user['role'] === 'company_admin' && $companyPhone !== '' && !empty($waCfg['enabled']);

      if ($recent < 3 && ($canEmail || $canWhatsapp)) {
        $link = password_setup_create_link($pdo, $company, (int)$user['id'], 'reset');
        $firstName = trim(explode(' ', trim((string)$user['name']))[0] ?? '') ?: 'Olá';
        $text = "Olá, {$firstName}!\n\n"
          . "Recebemos um pedido para redefinir a sua senha do painel {$companyName}.\n\n"
          . "Crie uma nova senha pelo link abaixo (válido por " . (int)NEXA_PASSWORD_LINK_HOURS . " horas, uso único):\n{$link}\n\n"
          . "Usuário: {$userEmail}\n\n"
          . "Se não foi você que pediu, ignore esta mensagem: a sua senha atual continua valendo.";

        if ($canEmail) {
          $res = saas_email_send($pdo, $userEmail, 'Redefinir sua senha - ' . $companyName, $text, 'saas_password_reset', (int)COMPANY_ID, $emailCfg);
          if (!empty($res['ok'])) $sentTo[] = 'no e-mail ' . forgot_mask_email($userEmail);
        }
        if ($canWhatsapp) {
          $res = saas_whatsapp_send_message($pdo, $companyPhone, $text, 'password_reset', (int)COMPANY_ID);
          if (!empty($res['ok'])) $sentTo[] = 'no WhatsApp da empresa (' . forgot_mask_phone($companyPhone) . ')';
        }
      } elseif ($recent >= 3) {
        $erro = 'Já enviamos vários links nesta última hora. Use o link mais recente que você recebeu ou aguarde um pouco.';
      }
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$logoFile = '';
try {
  $biz = function_exists('load_business_config') ? load_business_config(COMPANY_ID) : [];
  $logoFile = trim((string)($biz['logo']['file'] ?? ''));
} catch (Throwable $e) {}
$logoUrl = $logoFile !== '' ? ('/uploads/logos/' . rawurlencode(basename($logoFile))) : '/assets/logo-nexa-prospero.jpg';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>Esqueci minha senha - <?= e($companyName) ?></title>
<style>
:root{--primary:#0B1F5B;--secondary:#2454D6;--muted:#5B6475;--line:#DCE7F5;--bg:#F4F7FB;--error:#B91C1C;--ok:#047857}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:16px;background:var(--bg);font-family:Arial,Helvetica,sans-serif;color:var(--primary)}
.card{width:min(460px,100%);background:#fff;border:1px solid var(--line);border-radius:22px;padding:28px;box-shadow:0 18px 45px rgba(11,31,91,.08)}
.brand{display:flex;align-items:center;gap:12px;margin-bottom:20px}
.brand img{width:52px;height:52px;object-fit:contain;border-radius:14px;border:1px solid var(--line);background:#fff}
.brand strong{display:block;font-size:17px}.brand span{font-size:13px;color:var(--muted)}
h1{margin:0 0 6px;font-size:24px}p{margin:0 0 14px;color:var(--muted);line-height:1.55}
label{display:block;font-weight:700;margin:14px 0 6px;font-size:14px}
input[type=text]{width:100%;padding:13px 14px;border:1px solid var(--line);border-radius:12px;font-size:16px;background:#F8FAFD}
input[type=text]:focus{outline:2px solid var(--secondary);border-color:transparent}
.btn{display:block;width:100%;margin-top:18px;padding:14px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--primary),var(--secondary));color:#fff;font-size:16px;font-weight:700;text-align:center;text-decoration:none;cursor:pointer}
.btn.secondary{background:#fff;color:var(--primary);border:1px solid var(--line)}
.msg{padding:12px 14px;border-radius:12px;margin:0 0 14px;font-size:14px;line-height:1.5}
.msg.err{background:#FEF2F2;border:1px solid #FECACA;color:var(--error)}
.msg.ok{background:#ECFDF5;border:1px solid #A7F3D0;color:var(--ok)}
.msg.info{background:#EFF6FF;border:1px solid #BFDBFE;color:#1E3A8A}
.msg ul{margin:6px 0 0;padding-left:18px}
</style>
</head>
<body>
<main class="card">
  <div class="brand">
    <img src="<?= e($logoUrl) ?>" alt="">
    <div><strong><?= e($companyName) ?></strong><span>Painel administrativo</span></div>
  </div>

  <?php if ($submitted && $sentTo): ?>
    <h1>Link enviado!</h1>
    <div class="msg ok">Enviamos um link para você criar uma nova senha:
      <ul><?php foreach ($sentTo as $where): ?><li><?= e($where) ?></li><?php endforeach; ?></ul>
    </div>
    <p>O link vale por <?= (int)NEXA_PASSWORD_LINK_HOURS ?> horas e funciona uma única vez. Se não encontrar o e-mail, confira a caixa de spam.</p>
    <a class="btn secondary" href="login.php">Voltar ao login</a>

  <?php elseif ($submitted && $erro === ''): ?>
    <h1>Não foi possível enviar automaticamente</h1>
    <div class="msg info">
      Não conseguimos enviar o link para este usuário. Isso acontece quando o e-mail de acesso não é um e-mail real (é usado só como nome de login) ou quando foi digitado diferente do cadastro.
    </div>
    <p><b>O que fazer:</b> peça ao administrador da sua empresa para definir uma nova senha para você no menu <b>Tabelas &rsaquo; Usuários</b>.</p>
    <p>Se você é o administrador, confira se digitou o e-mail de acesso corretamente ou fale com o suporte Nexa Próspero.</p>
    <a class="btn" href="esqueci_senha.php">Tentar de novo</a>
    <a class="btn secondary" href="login.php">Voltar ao login</a>

  <?php else: ?>
    <h1>Esqueci minha senha</h1>
    <p>Informe o e-mail que você usa para entrar no sistema. Vamos enviar um link para você criar uma nova senha.</p>
    <?php if ($erro !== ''): ?><div class="msg err"><?= e($erro) ?></div><?php endif; ?>
    <form method="post" autocomplete="on">
      <input type="hidden" name="csrf" value="<?= e($csrf) ?>">
      <label for="login">E-mail de acesso</label>
      <input id="login" name="login" type="text" autocomplete="username" required value="<?= e($login) ?>" placeholder="seu@email.com.br">
      <button class="btn" type="submit">Enviar link</button>
    </form>
    <a class="btn secondary" href="login.php">Voltar ao login</a>
    <p style="margin-top:16px;font-size:13px">Seu login não é um e-mail real? Peça ao administrador da sua empresa para trocar a sua senha em <b>Tabelas &rsaquo; Usuários</b>.</p>
  <?php endif; ?>
</main>
</body>
</html>
