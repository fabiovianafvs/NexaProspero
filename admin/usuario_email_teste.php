<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/email_service.php';

if (!access_is_company_master() && !access_has_permission($pdo, (int)COMPANY_ID, 'usuarios')) access_render_denied('Seu usuário não possui permissão para administrar usuários.');
$cid = (int)COMPANY_ID;
$id = max(0,(int)($_GET['id'] ?? $_POST['id'] ?? 0));
if ($id <= 0) { header('Location: usuarios.php'); exit; }

function uet_h($v): string { return htmlspecialchars((string)$v,ENT_QUOTES,'UTF-8'); }

$st=$pdo->prepare("SELECT id,name,email FROM users WHERE company_id=? AND id=? AND role='company_user' LIMIT 1");
$st->execute([$cid,$id]);
$user=$st->fetch(PDO::FETCH_ASSOC);
if (!$user) { http_response_code(404); echo 'Usuário não encontrado.'; exit; }

$cfg=email_settings_load($pdo,$cid,$id);
$recipient=strtolower(trim((string)($_POST['recipient'] ?? $user['email'] ?? '')));
$msg=''; $err='';
if ($_SERVER['REQUEST_METHOD']==='POST') {
  if (!filter_var($recipient,FILTER_VALIDATE_EMAIL)) {
    $err='Informe um destinatário válido.';
  } elseif (!email_settings_ready($cfg)) {
    $err='As configurações SMTP deste usuário estão incompletas ou desativadas. Salve primeiro a configuração no cadastro do usuário.';
  } else {
    $subject='Teste de envio - Nexa Próspero';
    $body="Olá!\n\nEste é um e-mail de teste enviado pelo Nexa Próspero usando as configurações SMTP do usuário " . (string)$user['name'] . ".\n\nSe você recebeu esta mensagem, a configuração está funcionando corretamente.";
    $result=email_send_for_user($pdo,$cid,$id,[['email'=>$recipient,'name'=>'']],$subject,$body,[],'teste',null);
    if (!empty($result['ok'])) $msg='E-mail de teste enviado com sucesso para ' . $recipient . '.';
    else $err='Falha no envio: ' . ((string)($result['error'] ?? 'erro SMTP não informado'));
  }
}

admin_topbar('Teste de e-mail do usuário','usuarios');
?>
<style>
.uet-wrap{max-width:820px;margin:14px auto 0}.uet-card{background:#fff;border:1px solid #dbe4f0;border-radius:22px;padding:20px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.uet-card h1{margin:0;color:var(--admin-primary)}.uet-muted{color:#64748b;line-height:1.45}.uet-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin:14px 0}.uet-grid div{border:1px solid #e2e8f0;border-radius:14px;padding:11px;background:#f8fafc}.uet-grid span,.uet-grid strong{display:block}.uet-grid span{font-size:11px;text-transform:uppercase;font-weight:900;color:#64748b}.uet-grid strong{margin-top:4px}.uet-form{display:grid;gap:10px}.uet-form label{display:grid;gap:6px;font-weight:850}.uet-form input{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px}.uet-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;margin-top:8px}.msg{padding:12px 14px;border-radius:14px;margin:0 0 12px;font-weight:850}.msg.ok{background:#ecfdf5;color:#047857;border:1px solid #a7f3d0}.msg.err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca}@media(max-width:700px){.uet-grid{grid-template-columns:1fr}.uet-actions .btn{flex:1 1 100%}}
</style>
<div class="uet-wrap">
  <?php if($msg!==''): ?><div class="msg ok"><?= uet_h($msg) ?></div><?php endif; ?>
  <?php if($err!==''): ?><div class="msg err"><?= uet_h($err) ?></div><?php endif; ?>
  <div class="uet-card">
    <h1>Testar configuração de e-mail</h1>
    <p class="uet-muted">Usuário: <b><?= uet_h($user['name']) ?></b>. O teste usa exatamente o SMTP salvo para este operador.</p>
    <div class="uet-grid">
      <div><span>Status</span><strong><?= !empty($cfg['enabled']) ? 'Habilitado' : 'Desabilitado' ?></strong></div>
      <div><span>Servidor</span><strong><?= uet_h(($cfg['smtp_host'] ?? '') . ':' . (int)($cfg['smtp_port'] ?? 0)) ?></strong></div>
      <div><span>Segurança</span><strong><?= uet_h(strtoupper((string)($cfg['smtp_security'] ?? 'tls'))) ?></strong></div>
      <div><span>Remetente</span><strong><?= uet_h($cfg['from_email'] ?? '') ?></strong></div>
    </div>
    <form method="post" class="uet-form">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <label>Enviar teste para
        <input type="email" name="recipient" value="<?= uet_h($recipient) ?>" required autofocus>
      </label>
      <div class="uet-actions">
        <a class="btn" href="usuario_form.php?id=<?= (int)$id ?>">Voltar ao usuário</a>
        <button class="btn btn-primary" type="submit">Enviar e-mail de teste</button>
      </div>
    </form>
  </div>
</div>
<?php admin_footer(); ?>
