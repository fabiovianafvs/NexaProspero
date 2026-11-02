<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if (accountant_id()>0) { header('Location: index.php'); exit; }
$err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  $email=strtolower(trim((string)($_POST['email'] ?? '')));
  $password=(string)($_POST['password'] ?? '');
  try {
    accountant_check_csrf((string)($_POST['csrf'] ?? ''));
    if (!filter_var($email,FILTER_VALIDATE_EMAIL) || $password==='') throw new RuntimeException('Informe e-mail e senha.');
    $st=$pdo->prepare('SELECT * FROM accountants WHERE email=? AND is_active=1 LIMIT 1');
    $st->execute([$email]);
    $row=$st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row || !password_verify($password,(string)$row['password_hash'])) {
      usleep(350000);
      throw new RuntimeException('E-mail ou senha inválidos.');
    }
    $companies=accounting_linked_companies($pdo,(int)$row['id']);
    session_regenerate_id(true);
    $_SESSION['accountant_id']=(int)$row['id'];
    $_SESSION['accountant_name']=(string)$row['name'];
    $_SESSION['accountant_email']=(string)$row['email'];
    if ($companies) $_SESSION['accountant_company_id']=(int)$companies[0]['id']; else unset($_SESSION['accountant_company_id']);
    $pdo->prepare('UPDATE accountants SET last_login_at=NOW(),updated_at=NOW() WHERE id=?')->execute([(int)$row['id']]);
    header('Location: index.php');exit;
  } catch (Throwable $e) { $err=$e->getMessage(); }
}
?>
<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Central do Contador Próspero</title>
<style>
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:"Segoe UI",system-ui,Arial,sans-serif;background:radial-gradient(circle at 10% 0,#dff6ff,transparent 30%),radial-gradient(circle at 90% 0,#dbeafe,transparent 28%),#f4f7fb;color:#0f172a;display:grid;place-items:center;padding:24px}.shell{width:min(960px,100%);display:grid;grid-template-columns:1.05fr .95fr;background:#fff;border:1px solid #dbe4f0;border-radius:30px;overflow:hidden;box-shadow:0 28px 80px rgba(15,23,42,.16)}.hero{padding:42px;background:linear-gradient(145deg,#0b1f5b,#2454d6 72%,#00b8ff);color:#fff;display:flex;flex-direction:column;justify-content:space-between;min-height:510px}.brand{display:flex;gap:14px;align-items:center}.brand img{width:64px;height:64px;object-fit:contain;background:#fff;border-radius:18px;padding:6px}.brand strong{display:block;font-size:25px}.brand span{color:#dff6ff;font-weight:700}.hero h1{font-size:43px;line-height:1.03;letter-spacing:-.055em;margin:0 0 14px}.hero p{color:#eaf8ff;font-size:17px;line-height:1.55}.form{padding:42px;display:flex;flex-direction:column;justify-content:center}.form h2{font-size:30px;margin:0 0 7px;color:#0b1f5b}.muted{color:#64748b;margin:0 0 24px;line-height:1.5}label{display:block;font-weight:850;margin:14px 0 7px}input{width:100%;height:48px;border:1px solid #dbe4f0;border-radius:14px;padding:0 14px;font:inherit}button{width:100%;height:48px;margin-top:18px;border:0;border-radius:14px;background:linear-gradient(135deg,#0b1f5b,#2454d6);color:#fff;font-weight:900;font-size:15px;cursor:pointer}.err{padding:12px 13px;border:1px solid #fecaca;background:#fef2f2;color:#b91c1c;border-radius:14px;font-weight:750}.signup{display:flex;align-items:center;justify-content:center;height:46px;margin-top:10px;border:1px solid #cbd5e1;border-radius:14px;color:#0b1f5b;text-decoration:none;font-weight:900}.signup:hover{background:#eff6ff;border-color:#60a5fa}.secure{margin-top:18px;color:#64748b;font-size:12px;line-height:1.5}@media(max-width:760px){.shell{grid-template-columns:1fr}.hero{min-height:240px;padding:28px}.hero h1{font-size:32px}.form{padding:28px}}
</style></head><body><main class="shell"><section class="hero"><div class="brand"><img src="/assets/logo-nexa-prospero.jpg" alt="Próspero"><div><strong>Central do Contador</strong><span>Próspero</span></div></div><div><h1>Documentos fiscais organizados por empresa e período.</h1><p>Acesse fechamentos, SINTEGRA, XMLs, inventários e relações de documentos disponibilizados pelas empresas vinculadas.</p></div></section><section class="form"><h2>Acesso do contador</h2><p class="muted">Entre com seu usuário ou faça seu cadastro para solicitar acesso às empresas que você atende.</p><?php if($err!==''): ?><div class="err"><?= accountant_e($err) ?></div><?php endif; ?><form method="post"><input type="hidden" name="csrf" value="<?= accountant_e(accountant_csrf()) ?>"><label>E-mail</label><input type="email" name="email" value="<?= accountant_e((string)($_POST['email'] ?? '')) ?>" autocomplete="username" required autofocus><label>Senha</label><input type="password" name="password" autocomplete="current-password" required><button type="submit">Entrar na Central</button></form><a class="signup" href="cadastro.php">Ainda não possui acesso? Cadastre-se</a><p class="secure">Após o cadastro, solicite acesso usando o CNPJ da empresa. A liberação será feita pelo administrador da própria empresa.</p></section></main></body></html>
