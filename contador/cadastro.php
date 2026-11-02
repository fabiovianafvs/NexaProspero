<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (accountant_id() > 0) { header('Location: index.php?view=requests'); exit; }
$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim((string)($_POST['name'] ?? ''));
  $email = strtolower(trim((string)($_POST['email'] ?? '')));
  $document = accounting_digits((string)($_POST['document'] ?? ''));
  $phone = trim((string)($_POST['phone'] ?? ''));
  $password = (string)($_POST['password'] ?? '');
  $confirm = (string)($_POST['confirm_password'] ?? '');
  try {
    accountant_check_csrf((string)($_POST['csrf'] ?? ''));
    if ($name === '') throw new RuntimeException('Informe seu nome completo.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail válido.');
    if ($document !== '' && !in_array(strlen($document), [11, 14], true)) throw new RuntimeException('Informe um CPF ou CNPJ válido.');
    if (strlen($password) < 8) throw new RuntimeException('A senha deve possuir pelo menos 8 caracteres.');
    if ($password !== $confirm) throw new RuntimeException('A confirmação da senha não confere.');

    $st = $pdo->prepare('SELECT id FROM accountants WHERE email=? LIMIT 1');
    $st->execute([$email]);
    if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Já existe um cadastro com este e-mail. Use a opção Entrar na Central.');

    $pdo->prepare('INSERT INTO accountants (name,email,password_hash,document,phone,is_active,created_at) VALUES (?,?,?,?,?,1,NOW())')
      ->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $document, $phone]);
    $id = (int)$pdo->lastInsertId();
    session_regenerate_id(true);
    $_SESSION['accountant_id'] = $id;
    $_SESSION['accountant_name'] = $name;
    $_SESSION['accountant_email'] = $email;
    unset($_SESSION['accountant_company_id']);
    header('Location: index.php?view=requests&registered=1');
    exit;
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Cadastro — Central do Contador Próspero</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Segoe UI",system-ui,Arial,sans-serif;background:radial-gradient(circle at 10% 0,#dff6ff,transparent 30%),radial-gradient(circle at 90% 0,#dbeafe,transparent 28%),#f4f7fb;color:#0f172a;padding:24px}.page{width:min(1100px,100%);margin:auto}.top{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:18px}.brand{display:flex;align-items:center;gap:12px}.brand img{width:54px;height:54px;object-fit:contain;background:#fff;border-radius:16px;padding:5px;box-shadow:0 8px 24px rgba(15,23,42,.1)}.brand strong{display:block;color:#0b1f5b;font-size:22px}.brand span{color:#64748b;font-weight:700}.back{display:inline-flex;align-items:center;justify-content:center;height:44px;padding:0 15px;border:1px solid #cbd5e1;background:#fff;border-radius:13px;color:#0b1f5b;text-decoration:none;font-weight:900}.shell{display:grid;grid-template-columns:.85fr 1.15fr;background:#fff;border:1px solid #dbe4f0;border-radius:30px;overflow:hidden;box-shadow:0 28px 80px rgba(15,23,42,.14)}.info{padding:38px;background:linear-gradient(145deg,#0b1f5b,#2454d6 72%,#00b8ff);color:#fff}.info h1{font-size:38px;line-height:1.06;letter-spacing:-.04em;margin:0 0 16px}.info p{color:#eaf8ff;line-height:1.55}.steps{display:grid;gap:12px;margin-top:28px}.step{display:grid;grid-template-columns:38px 1fr;gap:11px;align-items:start}.step b{display:flex;width:38px;height:38px;align-items:center;justify-content:center;border-radius:12px;background:rgba(255,255,255,.18)}.step strong{display:block}.step span{display:block;color:#dff6ff;font-size:13px;margin-top:3px}.form{padding:38px}.form h2{font-size:28px;margin:0 0 6px;color:#0b1f5b}.muted{color:#64748b;margin:0 0 20px;line-height:1.5}.grid{display:grid;grid-template-columns:1fr 1fr;gap:13px}.span-2{grid-column:1/-1}label{display:block;font-size:12px;font-weight:900;margin-bottom:6px}input{width:100%;height:47px;border:1px solid #dbe4f0;border-radius:14px;padding:0 13px;font:inherit}input:focus{outline:3px solid rgba(37,84,214,.13);border-color:#2454d6}.btn{width:100%;height:49px;border:0;border-radius:14px;background:linear-gradient(135deg,#0b1f5b,#2454d6);color:#fff;font-weight:900;font-size:15px;cursor:pointer}.err{padding:12px 13px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:14px;font-weight:750;margin-bottom:15px}.note{font-size:12px;color:#64748b;line-height:1.5;margin-top:12px}@media(max-width:800px){.shell{grid-template-columns:1fr}.info{padding:28px}.info h1{font-size:31px}.form{padding:28px}.grid{grid-template-columns:1fr}.span-2{grid-column:auto}.top{align-items:flex-start}}
</style>
</head>
<body>
<div class="page">
  <div class="top"><div class="brand"><img src="/assets/logo-nexa-prospero.jpg" alt="Próspero"><div><strong>Central do Contador</strong><span>Próspero</span></div></div><a class="back" href="login.php">Já tenho cadastro</a></div>
  <main class="shell">
    <section class="info"><h1>Um único acesso para todas as empresas que você contabiliza.</h1><p>Cadastre-se gratuitamente e solicite acesso informando o CNPJ de cada empresa. A empresa receberá sua solicitação e decidirá pela liberação.</p><div class="steps"><div class="step"><b>1</b><div><strong>Crie sua conta</strong><span>Use seu e-mail profissional e uma senha segura.</span></div></div><div class="step"><b>2</b><div><strong>Informe o CNPJ</strong><span>Solicite acesso a uma ou várias empresas.</span></div></div><div class="step"><b>3</b><div><strong>Aguarde a aprovação</strong><span>Após a empresa autorizar, os documentos aparecerão na Central.</span></div></div></div></section>
    <section class="form"><h2>Cadastro do contador</h2><p class="muted">Preencha seus dados para criar o acesso à Central.</p><?php if($err!==''): ?><div class="err"><?= accountant_e($err) ?></div><?php endif; ?><form method="post" autocomplete="off"><input type="hidden" name="csrf" value="<?= accountant_e(accountant_csrf()) ?>"><div class="grid"><div class="span-2"><label>Nome completo</label><input name="name" value="<?= accountant_e((string)($_POST['name'] ?? '')) ?>" required autofocus></div><div class="span-2"><label>E-mail profissional</label><input type="email" name="email" value="<?= accountant_e((string)($_POST['email'] ?? '')) ?>" autocomplete="email" required></div><div><label>CPF ou CNPJ do contador/escritório</label><input name="document" inputmode="numeric" value="<?= accountant_e((string)($_POST['document'] ?? '')) ?>"></div><div><label>Telefone</label><input name="phone" value="<?= accountant_e((string)($_POST['phone'] ?? '')) ?>"></div><div><label>Senha</label><input type="password" name="password" minlength="8" autocomplete="new-password" required></div><div><label>Confirmar senha</label><input type="password" name="confirm_password" minlength="8" autocomplete="new-password" required></div><div class="span-2"><button class="btn" type="submit">Cadastrar e solicitar acessos</button><p class="note">Seu cadastro não libera automaticamente dados de nenhuma empresa. Cada acesso precisa ser aprovado pelo administrador da empresa solicitada.</p></div></div></form></section>
  </main>
</div>
</body>
</html>
