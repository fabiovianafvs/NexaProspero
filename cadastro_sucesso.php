<?php
declare(strict_types=1);
session_start();

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

$result = $_SESSION['signup_success'] ?? null;
if (!is_array($result) || empty($result['company_id']) || empty($result['email']) || (empty($result['pass']) && empty($result['password_link']))) {
  header('Location: /cadastro.php', true, 302);
  exit;
}

$createdAt = (int)($result['created_at'] ?? time());
if ($createdAt > 0 && $createdAt < time() - 7200) {
  unset($_SESSION['signup_success']);
  header('Location: /cadastro.php', true, 302);
  exit;
}

function signup_success_e(string $value): string {
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

$companyName = trim((string)($result['company_name'] ?? 'Empresa')) ?: 'Empresa';
$email = (string)$result['email'];
$password = (string)($result['pass'] ?? '');
$passwordLink = (string)($result['password_link'] ?? '');
$catalogUrl = (string)($result['catalog_url_path'] ?? '');
$publicCatalogUrl = (string)($result['public_catalog_url'] ?? '');
$adminUrl = (string)($result['admin_url'] ?? '');
$eadUrl = (string)($result['ead_login_url'] ?? 'https://ead.prospero.tec.br/login.php');
$modules = array_values(array_filter(array_map('strval', (array)($result['modules'] ?? []))));
$context = (array)($result['signup_context'] ?? []);
$whatsappResult = (array)($result['whatsapp_credentials'] ?? []);
$whatsappSent = !empty($whatsappResult['ok']);
$whatsappDestination = (string)($whatsappResult['destino'] ?? $result['whatsapp'] ?? '');
$manualWhatsappUrl = (string)($result['whatsapp_manual_url'] ?? '');
$whatsappError = trim((string)($whatsappResult['error'] ?? ''));
$emailResult = (array)($result['email_credentials'] ?? []);
$emailSent = !empty($emailResult['ok']);
$emailDestination = (string)($emailResult['to'] ?? '');
$planLabel = trim((string)($context['plan_label'] ?? 'Nexa Próspero')) ?: 'Nexa Próspero';
$sellerBrand = trim((string)($result['seller_brand'] ?? 'Nexa Próspero')) ?: 'Nexa Próspero';
$sellerLogo = trim((string)($result['seller_logo'] ?? '/assets/logo-nexa-prospero.jpg')) ?: '/assets/logo-nexa-prospero.jpg';
$sellerPrimary = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($result['seller_primary_color'] ?? '')) ? (string)$result['seller_primary_color'] : '#0b1f5b';
$sellerSecondary = preg_match('/^#[0-9a-fA-F]{6}$/', (string)($result['seller_secondary_color'] ?? '')) ? (string)$result['seller_secondary_color'] : '#2454d6';
$sellerWhatsapp = preg_replace('/\D+/', '', (string)($result['seller_whatsapp'] ?? '')) ?: '';
$isResellerSignup = !empty($result['is_reseller_signup']);
$trialStart = trim((string)($context['trial_start'] ?? ''));
$trialEnd = trim((string)($context['trial_end'] ?? ''));
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Ambiente criado | <?= signup_success_e($sellerBrand) ?></title>
<style>
:root{--navy:<?= signup_success_e($sellerPrimary) ?>;--blue:<?= signup_success_e($sellerSecondary) ?>;--cyan:#00b8ff;--bg:#f3f7ff;--line:#d8e5fb;--muted:#64748b;--ok:#15803d;--warn:#9a3412}
*{box-sizing:border-box}body{margin:0;min-height:100vh;font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;color:var(--navy);background:radial-gradient(circle at 8% 0,rgba(0,184,255,.18),transparent 32%),radial-gradient(circle at 92% 4%,rgba(36,84,214,.18),transparent 30%),linear-gradient(180deg,#f8fbff,var(--bg))}
a{text-decoration:none;color:inherit}.page{width:min(1040px,calc(100% - 28px));margin:0 auto;padding:26px 0 46px}.topbar{display:flex;align-items:center;justify-content:space-between;gap:16px;margin-bottom:24px}.brand{display:flex;align-items:center;gap:12px}.brand img{width:70px;height:52px;object-fit:contain;background:#fff;border:1px solid var(--line);border-radius:17px;padding:6px;box-shadow:0 12px 28px rgba(11,31,91,.09)}.brand strong{display:block;font-size:20px}.brand span{display:block;color:var(--muted);font-size:13px;margin-top:3px}.card{background:rgba(255,255,255,.96);border:1px solid var(--line);border-radius:32px;padding:28px;box-shadow:0 24px 64px rgba(11,31,91,.13)}.hero{display:flex;gap:18px;align-items:flex-start}.icon{width:68px;height:68px;flex:0 0 68px;border-radius:22px;display:grid;place-items:center;font-size:34px;color:#fff;background:linear-gradient(135deg,#16a34a,#22c55e);box-shadow:0 16px 34px rgba(22,163,74,.22)}h1{font-size:clamp(30px,5vw,46px);letter-spacing:-.045em;margin:2px 0 8px}.lead{margin:0;color:var(--muted);line-height:1.65}.status{margin:22px 0 0;padding:15px 17px;border-radius:19px;font-weight:750;line-height:1.5}.status.ok{background:#ecfdf5;border:1px solid #bbf7d0;color:#14532d}.status.warn{background:#fff7ed;border:1px solid #fed7aa;color:var(--warn)}.grid{display:grid;grid-template-columns:1fr 1fr;gap:13px;margin-top:22px}.box{border:1px solid var(--line);border-radius:20px;padding:15px;background:linear-gradient(180deg,#fff,#f8fbff);min-width:0}.box.wide{grid-column:1/-1}.box span{display:block;color:var(--muted);font-size:11px;font-weight:850;text-transform:uppercase;letter-spacing:.07em}.box strong,.box a{display:block;margin-top:7px;font-size:16px;word-break:break-word;color:var(--navy)}.credentials{position:relative}.copy{position:absolute;right:12px;top:12px;border:1px solid var(--line);background:#fff;color:var(--blue);font-weight:850;border-radius:999px;padding:8px 12px;cursor:pointer}.summary{margin-top:20px;border:1px solid var(--line);border-radius:22px;padding:17px;background:#f8fbff}.summary h2{font-size:18px;margin:0 0 10px}.summary p{margin:6px 0;color:var(--muted)}.chips{display:flex;flex-wrap:wrap;gap:8px;margin-top:12px}.chip{padding:8px 11px;border-radius:999px;background:#eaf2ff;border:1px solid #cfe0ff;font-size:12px;font-weight:800;color:#1e3a8a}.actions{display:flex;flex-wrap:wrap;gap:10px;margin-top:22px}.btn{min-height:48px;display:inline-flex;align-items:center;justify-content:center;padding:0 18px;border-radius:999px;border:1px solid var(--line);font-weight:850;background:#fff}.btn.primary{border:0;color:#fff;background:linear-gradient(135deg,var(--blue),#087ff5 58%,var(--cyan));box-shadow:0 14px 32px rgba(36,84,214,.24)}.btn.whatsapp{border-color:#bbf7d0;color:#166534;background:#f0fdf4}.note{margin-top:18px;color:var(--muted);font-size:13px;line-height:1.55}@media(max-width:700px){.page{width:min(100%,calc(100% - 20px));padding-top:16px}.card{padding:20px;border-radius:25px}.hero{display:block}.icon{margin-bottom:15px}.grid{grid-template-columns:1fr}.box.wide{grid-column:auto}.actions .btn{width:100%}.topbar{align-items:flex-start}.brand img{width:60px;height:46px}}
</style>
</head>
<body>
<div class="page">
  <header class="topbar">
    <a class="brand" href="/">
      <img src="<?= signup_success_e($sellerLogo) ?>" alt="<?= signup_success_e($sellerBrand) ?>">
      <span><strong><?= signup_success_e($sellerBrand) ?></strong><span><?= $isResellerSignup ? 'Cadastro concluído pela revenda' : 'Ambiente de teste criado' ?></span></span>
    </a>
  </header>

  <main class="card">
    <div class="hero">
      <div class="icon">✓</div>
      <div>
        <h1><?= signup_success_e($companyName) ?> foi cadastrada!</h1>
        <p class="lead"><?= $isResellerSignup ? 'Seu ambiente foi criado pela ' . signup_success_e($sellerBrand) . '. ' . ($passwordLink !== '' ? 'Agora crie a sua senha para acessar o painel administrativo e os treinamentos.' : 'Guarde o usuário e a senha abaixo para acessar o painel administrativo e os treinamentos.') : ($passwordLink !== '' ? 'O ambiente de teste está pronto. Agora crie a sua senha para acessar o painel administrativo e os treinamentos.' : 'O ambiente de teste está pronto. Guarde o usuário e a senha abaixo para acessar o painel administrativo e os treinamentos.') ?></p>
      </div>
    </div>

    <?php if ($whatsappSent): ?>
      <div class="status ok">✓ As credenciais também foram enviadas automaticamente pelo WhatsApp<?= $whatsappDestination !== '' ? ' para ' . signup_success_e($whatsappDestination) : '' ?>.</div>
    <?php else: ?>
      <div class="status warn">A empresa foi criada, mas o envio automático pelo WhatsApp não foi confirmado<?= $whatsappError !== '' ? ': ' . signup_success_e($whatsappError) : '.' ?> Use o botão abaixo para enviar a mensagem manualmente.</div>
    <?php endif; ?>

    <?php if ($emailSent): ?>
      <div class="status ok">✓ Enviamos também os dados de acesso para o e-mail <?= signup_success_e($emailDestination) ?>. Se não encontrar, confira a caixa de spam.</div>
    <?php endif; ?>

    <section class="grid">
      <div class="box credentials"><span>Usuário</span><strong id="loginValue"><?= signup_success_e($email) ?></strong><button class="copy" type="button" data-copy="loginValue">Copiar</button></div>
      <?php if ($passwordLink !== ''): ?>
      <div class="box credentials"><span>Senha</span><strong>Crie a sua senha pelo botão abaixo (link válido por 48 horas, uso único).</strong></div>
      <?php else: ?>
      <div class="box credentials"><span>Senha inicial</span><strong id="passwordValue"><?= signup_success_e($password) ?></strong><button class="copy" type="button" data-copy="passwordValue">Copiar</button></div>
      <?php endif; ?>
      <div class="box"><span>Painel administrativo</span><a href="<?= signup_success_e($adminUrl) ?>" target="_blank" rel="noopener"><?= signup_success_e($adminUrl) ?></a></div>
      <div class="box"><span>Catálogo de pedidos</span><a href="<?= signup_success_e($catalogUrl) ?>" target="_blank" rel="noopener"><?= signup_success_e($catalogUrl) ?></a></div>
      <div class="box"><span>Catálogo público</span><a href="<?= signup_success_e($publicCatalogUrl) ?>" target="_blank" rel="noopener"><?= signup_success_e($publicCatalogUrl) ?></a></div>
      <div class="box"><span>Treinamentos Próspero EAD</span><a href="<?= signup_success_e($eadUrl) ?>" target="_blank" rel="noopener"><?= signup_success_e($eadUrl) ?></a></div>
      <?php if ($isResellerSignup && $sellerWhatsapp !== ''): ?><div class="box"><span>Atendimento da revenda</span><strong><?= signup_success_e($sellerWhatsapp) ?></strong></div><?php endif; ?>
    </section>

    <section class="summary">
      <h2><?= signup_success_e($planLabel) ?></h2>
      <?php if ($trialStart !== '' || $trialEnd !== ''): ?><p><strong>Teste:</strong> <?= signup_success_e($trialStart) ?><?= $trialEnd !== '' ? ' até ' . signup_success_e($trialEnd) : '' ?></p><?php endif; ?>
      <?php if ($modules): ?><div class="chips"><?php foreach ($modules as $module): ?><span class="chip">✓ <?= signup_success_e($module) ?></span><?php endforeach; ?></div><?php endif; ?>
    </section>

    <div class="actions">
      <?php if ($passwordLink !== ''): ?>
      <a class="btn primary" href="<?= signup_success_e($passwordLink) ?>">Criar minha senha</a>
      <?php else: ?>
      <a class="btn primary" href="<?= signup_success_e($adminUrl) ?>">Entrar no painel administrativo</a>
      <?php endif; ?>
      <a class="btn" href="<?= signup_success_e($catalogUrl) ?>">Abrir catálogo</a>
      <?php if ($manualWhatsappUrl !== ''): ?><a class="btn whatsapp" href="<?= signup_success_e($manualWhatsappUrl) ?>" target="_blank" rel="noopener"><?= $whatsappSent ? 'Reenviar pelo WhatsApp' : 'Enviar credenciais pelo WhatsApp' ?></a><?php endif; ?>
    </div>

    <?php if (!empty($result['email_was_generated'])): ?><p class="note">Como não foi informado um e-mail, o sistema criou um login técnico. Ele pode ser alterado posteriormente no cadastro da empresa.</p><?php endif; ?>
    <?php if ($passwordLink === ''): ?><p class="note">Recomendamos alterar a senha após o primeiro acesso.</p><?php endif; ?>
  </main>
</div>
<script>
document.querySelectorAll('[data-copy]').forEach(function(button){
  button.addEventListener('click', async function(){
    const target = document.getElementById(button.dataset.copy || '');
    if (!target) return;
    try {
      await navigator.clipboard.writeText(target.textContent.trim());
      const original = button.textContent;
      button.textContent = 'Copiado!';
      setTimeout(function(){ button.textContent = original; }, 1400);
    } catch (e) {}
  });
});
</script>
</body>
</html>
