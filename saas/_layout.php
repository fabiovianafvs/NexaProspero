<?php
declare(strict_types=1);

if (!function_exists('saas_layout_start')) {
  function saas_layout_start(string $title, string $pageTitle, string $pageSubtitle = ''): void {
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= $title ?></title>
<link rel="stylesheet" href="/assets/admin.css">
<style>
:root{
  --bg:#f4f7fb;
  --card:#ffffff;
  --text:#0b1f5b;
  --muted:#5b6475;
  --muted-soft:#748197;
  --line:#dce7f5;
  --primary:#2454d6;
  --primary-dark:#0b1f5b;
  --accent:#00b8ff;
  --accent-soft:#e8f8ff;
  --white:#ffffff;
  --danger:#b91c1c;
  --danger-dark:#991b1b;
  --success:#16a34a;
  --success-dark:#15803d;
  --shadow:0 18px 44px rgba(11,31,91,.09);
  --shadow-soft:0 10px 30px rgba(36,84,214,.08);
  --radius:22px;
  --sidebar-w:265px;
}

*{box-sizing:border-box}

html,body{
  margin:0;
  padding:0;
  background:
    radial-gradient(circle at 12% 10%, rgba(0,184,255,.12), transparent 28%),
    radial-gradient(circle at 88% 0%, rgba(36,84,214,.12), transparent 24%),
    var(--bg);
  color:var(--text);
  font-family:"Segoe UI", Arial, Helvetica, sans-serif;
}

body{overflow-x:hidden}
a{color:inherit}

.saas-app{display:flex;min-height:100vh}

.sidebar{
  width:var(--sidebar-w);
  background:
    radial-gradient(circle at 20% 10%, rgba(0,184,255,.34), transparent 30%),
    linear-gradient(180deg,#06133c 0%, #0b1f5b 48%, #2454d6 100%);
  color:#fff;
  position:fixed;
  left:0;top:0;bottom:0;
  padding:22px 18px;
  box-shadow:16px 0 42px rgba(11,31,91,.22);
  z-index:30;
  overflow-y:auto;
}

.sidebar::after{
  content:"";
  position:absolute;
  inset:auto -80px -100px 30px;
  width:210px;height:210px;
  border-radius:999px;
  background:rgba(0,184,255,.18);
  filter:blur(2px);
  pointer-events:none;
}

.brand{
  display:flex;
  align-items:center;
  gap:12px;
  text-decoration:none;
  color:#fff;
  margin-bottom:26px;
  position:relative;
  z-index:1;
}

.brand img{
  width:54px;height:54px;
  object-fit:contain;
  border-radius:16px;
  background:#fff;
  padding:6px;
  box-shadow:0 12px 30px rgba(0,0,0,.14);
}

.brand .name{font-size:22px;font-weight:900;line-height:1.05;color:#ffffff;letter-spacing:-.03em}
.brand .sub{margin-top:4px;font-size:12px;color:#dff6ff;font-weight:700;letter-spacing:.02em}

.nav-group{margin-top:18px;position:relative;z-index:1}.nav-label{font-size:11px;text-transform:uppercase;letter-spacing:.10em;color:rgba(255,255,255,.70);margin:0 0 10px 10px;font-weight:800}.nav{display:flex;flex-direction:column;gap:8px}
.nav a{display:flex;align-items:center;min-height:44px;padding:10px 14px;border-radius:15px;text-decoration:none;color:#ffffff;font-weight:800;border:1px solid rgba(255,255,255,.12);background:rgba(255,255,255,.07);transition:.2s;box-shadow:inset 0 1px 0 rgba(255,255,255,.08)}
.nav a:hover{background:rgba(255,255,255,.16);border-color:rgba(0,184,255,.38);transform:translateX(2px)}
.nav a.active{background:#ffffff;color:var(--primary-dark);border-color:#ffffff;box-shadow:0 12px 26px rgba(0,184,255,.18)}
.nav-folder{display:flex;flex-direction:column;gap:6px}.nav-folder>.nav-parent{justify-content:space-between}.nav-folder>.nav-parent::after{content:"▾";font-size:11px;opacity:.8}.nav-folder.open>.nav-parent{background:rgba(255,255,255,.16);border-color:rgba(0,184,255,.38)}.nav-submenu{display:none;flex-direction:column;gap:5px;margin-left:14px;padding-left:10px;border-left:2px solid rgba(255,255,255,.18)}.nav-folder.open .nav-submenu{display:flex}.nav-submenu a{min-height:36px;padding:8px 11px;border-radius:12px;font-size:12px;background:rgba(255,255,255,.045)}.nav-submenu a.active{background:#ffffff;color:var(--primary-dark);border-color:#ffffff}

.main{margin-left:var(--sidebar-w);width:calc(100% - var(--sidebar-w));padding:24px;min-width:0}

.topbar{
  background:
    radial-gradient(circle at 15% 0%, rgba(0,184,255,.24), transparent 30%),
    linear-gradient(135deg,#0b1f5b 0%, #2454d6 58%, #00b8ff 135%);
  color:#ffffff;
  border-radius:28px;
  padding:26px;
  border:1px solid rgba(255,255,255,.15);
  box-shadow:0 24px 55px rgba(11,31,91,.18);
  margin-bottom:18px;
}

.topbar h1{margin:0;font-size:34px;line-height:1.05;font-weight:900;color:#ffffff;letter-spacing:-.04em}.topbar p{margin:8px 0 0;color:#dff6ff;font-size:15px;font-weight:600}.top-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:18px}.content-grid{display:grid;gap:16px}

.panel,.card{background:var(--card);border-radius:var(--radius);box-shadow:var(--shadow);border:1px solid rgba(36,84,214,.12);min-width:0}.panel{padding:20px}.card{padding:18px;position:relative;overflow:hidden}.card::before{content:"";position:absolute;left:0;top:0;right:0;height:4px;background:linear-gradient(90deg,var(--primary),var(--accent))}.panel h3{margin:0 0 14px;color:var(--primary-dark);font-size:22px;font-weight:900;letter-spacing:-.02em}.panel p{color:var(--text)}
.cards{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:16px}.card .label{color:var(--muted);font-size:13px;margin-bottom:10px;font-weight:800;text-transform:uppercase;letter-spacing:.04em}.card .value{font-size:30px;font-weight:900;color:var(--primary-dark);letter-spacing:-.04em}.card .sub{color:var(--muted-soft);font-size:12px;margin-top:6px;font-weight:600}.grid-2{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:16px}.grid-main{display:grid;grid-template-columns:minmax(0,1.35fr) minmax(320px,0.75fr);gap:16px}

.btn2,a.btn2,button.btn2{display:inline-flex !important;align-items:center !important;justify-content:center !important;min-height:44px !important;padding:12px 16px !important;border-radius:999px !important;text-decoration:none !important;font-weight:900 !important;font-size:14px !important;border:1px solid transparent !important;cursor:pointer !important;box-shadow:none !important;line-height:1 !important;white-space:nowrap !important;margin:0 !important;transition:.2s !important}.btn2:hover{transform:translateY(-1px)}
.btn2-primary,a.btn2-primary,button.btn2-primary{background:#ffffff !important;color:var(--primary-dark) !important;border-color:#ffffff !important;box-shadow:0 12px 28px rgba(0,184,255,.18) !important}.btn2-primary:hover,a.btn2-primary:hover,button.btn2-primary:hover{background:#eef9ff !important;color:var(--primary-dark) !important;border-color:#eef9ff !important}
.btn2-ghost,a.btn2-ghost,button.btn2-ghost{background:rgba(255,255,255,.12) !important;color:#ffffff !important;border:1px solid rgba(255,255,255,.24) !important}.btn2-ghost:hover,a.btn2-ghost:hover,button.btn2-ghost:hover{background:rgba(255,255,255,.20) !important;color:#ffffff !important;border-color:rgba(0,184,255,.45) !important}
.btn2-dark,a.btn2-dark,button.btn2-dark{background:linear-gradient(135deg,var(--primary),var(--accent)) !important;color:#ffffff !important;border-color:rgba(0,184,255,.55) !important;box-shadow:0 12px 26px rgba(36,84,214,.20) !important}.btn2-dark:hover,a.btn2-dark:hover,button.btn2-dark:hover{filter:brightness(.98);box-shadow:0 16px 32px rgba(36,84,214,.28) !important;color:#ffffff !important}
.btn2-danger,a.btn2-danger,button.btn2-danger{background:var(--danger) !important;color:#ffffff !important;border-color:var(--danger) !important}.btn2-danger:hover,a.btn2-danger:hover,button.btn2-danger:hover{background:var(--danger-dark) !important;color:#ffffff !important;border-color:var(--danger-dark) !important}
.btn2-warning,a.btn2-warning,button.btn2-warning{background:#f97316 !important;color:#ffffff !important;border-color:#f97316 !important;box-shadow:0 12px 26px rgba(249,115,22,.18) !important}.btn2-warning:hover,a.btn2-warning:hover,button.btn2-warning:hover{background:#ea580c !important;color:#ffffff !important;border-color:#ea580c !important}
.panel .btn2-primary,.card .btn2-primary,.wa-card .btn2-primary{background:linear-gradient(135deg,var(--primary),var(--accent)) !important;color:#ffffff !important;border-color:rgba(0,184,255,.55) !important}.panel .btn2-primary:hover,.card .btn2-primary:hover,.wa-card .btn2-primary:hover{color:#ffffff !important;filter:brightness(.98)}
.panel .btn2-ghost,.card .btn2-ghost,.wa-card .btn2-ghost{background:#ffffff !important;color:var(--primary-dark) !important;border:1px solid #bfd2f4 !important;box-shadow:0 10px 24px rgba(11,31,91,.06) !important}.panel .btn2-ghost:hover,.card .btn2-ghost:hover,.wa-card .btn2-ghost:hover{background:#f4f9ff !important;color:var(--primary-dark) !important;border-color:rgba(0,184,255,.55) !important}

table{width:100%;border-collapse:collapse}th,td{padding:12px 10px;border-bottom:1px solid #eef4fb;text-align:left;vertical-align:top}th{color:var(--muted);font-size:12px;font-weight:900;text-transform:uppercase;letter-spacing:.04em;background:#f8fbff}td{color:#1f2a44;font-size:14px}.table-wrap{width:100%;overflow-x:auto;border-radius:16px}.table-wrap table{min-width:0;table-layout:fixed}.table-wrap th,.table-wrap td{word-break:break-word;overflow-wrap:anywhere}.pill{display:inline-flex;align-items:center;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:800}.muted{color:var(--muted);font-weight:600}.row-actions{display:flex;gap:8px;flex-wrap:wrap}

.mini,a.mini,button.mini{display:inline-flex !important;align-items:center !important;justify-content:center !important;min-height:36px !important;padding:8px 12px !important;border-radius:999px !important;font-weight:800 !important;font-size:12px !important;text-decoration:none !important;border:1px solid #cbd7ea !important;background:#ffffff !important;color:var(--primary-dark) !important;white-space:nowrap !important;margin:0 !important}.mini:hover{background:#f4f9ff !important}.mini-primary{background:linear-gradient(135deg,var(--primary),var(--accent)) !important;color:#ffffff !important;border-color:rgba(0,184,255,.55) !important}.mini-primary:hover{filter:brightness(.98)}

.alert-ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;padding:12px 14px;border-radius:16px;margin-bottom:16px;font-weight:700}.alert-err{background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;padding:12px 14px;border-radius:16px;margin-bottom:16px;font-weight:700}.warn{background:#fff7ed;color:#9a3412;border:1px solid #fdba74;border-radius:16px;padding:14px 16px;margin-bottom:16px;font-weight:700}
.field,select,textarea,input{width:100%;padding:12px 14px;border-radius:14px;border:1px solid #dbe7f6;background:#fff;color:#0b1f5b;outline:none}.field:focus,select:focus,textarea:focus,input:focus{border-color:rgba(0,184,255,.85);box-shadow:0 0 0 4px rgba(0,184,255,.14)}code.box{display:block;padding:14px;border-radius:14px;background:var(--primary-dark);color:#fff;font-size:16px;word-break:break-word}

@media (max-width:1200px){.cards{grid-template-columns:repeat(3,minmax(0,1fr))}}
@media (max-width:960px){.sidebar{position:static;width:100%;border-radius:0 0 24px 24px}.saas-app{display:block}.main{margin-left:0;width:100%;padding:16px}.grid-2,.grid-main{grid-template-columns:1fr}}
@media (max-width:700px){.cards{grid-template-columns:1fr}.topbar h1{font-size:28px}.brand img{width:48px;height:48px}}
</style>
</head>
<body>
<div class="saas-app">
  <aside class="sidebar">
    <a class="brand" href="/saas/dashboard.php">
      <img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Pr&oacute;spero">
      <div class="brand-text">
        <div class="name">Central SaaS</div>
        <div class="sub">Nexa Pr&oacute;spero</div>
      </div>
    </a>

    <?php
      $saasPage = basename($_SERVER['PHP_SELF']);
      $saasSubscriptionPage = $saasPage === 'assinaturas_nexa.php';
      $saasSubscriptionSection = (string)($_GET['section'] ?? 'faturamento');
      if (!in_array($saasSubscriptionSection, ['faturamento','periodos','modulos','contratos'], true)) $saasSubscriptionSection = 'faturamento';
      if (!empty($_GET['migrate'])) $saasSubscriptionSection = 'contratos';
    ?>
    <div class="nav-group">
      <div class="nav-label">Opera&ccedil;&atilde;o</div>
      <nav class="nav">
        <a href="/saas/dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="/saas/empresas.php" class="<?= basename($_SERVER['PHP_SELF']) === 'empresas.php' ? 'active' : '' ?>">Empresas</a>
        <a href="/saas/grupos_empresariais.php" class="<?= in_array($saasPage ?? basename($_SERVER['PHP_SELF']), ['grupos_empresariais.php','grupo_empresarial.php'], true) ? 'active' : '' ?>">Grupos Empresariais</a>
        <a href="/saas/revendas.php" class="<?= in_array($saasPage, ['revendas.php','faturamento_revendas.php'], true) ? 'active' : '' ?>">Revendas</a>
        <a href="/saas/planos.php" class="<?= in_array($saasPage, ['planos.php','planos_form.php','modulos.php','modulo_form.php'], true) ? 'active' : '' ?>">Preços e módulos</a>
        <a href="/saas/catalogos_fiscais.php" class="<?= $saasPage === 'catalogos_fiscais.php' ? 'active' : '' ?>">Catálogos fiscais</a>
        <a href="/saas/cobrancas.php" class="<?= basename($_SERVER['PHP_SELF']) === 'cobrancas.php' ? 'active' : '' ?>">Cobranças</a>
        <div class="nav-folder <?= $saasSubscriptionPage ? 'open' : '' ?>">
          <a href="/saas/assinaturas_nexa.php?section=faturamento" class="nav-parent">Assinaturas Nexa</a>
          <div class="nav-submenu">
            <a href="/saas/assinaturas_nexa.php?section=faturamento" class="<?= $saasSubscriptionPage && $saasSubscriptionSection === 'faturamento' ? 'active' : '' ?>">Configurações Faturamento</a>
            <a href="/saas/assinaturas_nexa.php?section=periodos" class="<?= $saasSubscriptionPage && $saasSubscriptionSection === 'periodos' ? 'active' : '' ?>">Períodos de Renovação</a>
            <a href="/saas/assinaturas_nexa.php?section=modulos" class="<?= $saasSubscriptionPage && $saasSubscriptionSection === 'modulos' ? 'active' : '' ?>">Vínculo Módulos/Serviços</a>
            <a href="/saas/assinaturas_nexa.php?section=contratos" class="<?= $saasSubscriptionPage && $saasSubscriptionSection === 'contratos' ? 'active' : '' ?>">Vincular Contratos</a>
          </div>
        </div>
        <a href="/saas/whatsapp.php" class="<?= basename($_SERVER['PHP_SELF']) === 'whatsapp.php' ? 'active' : '' ?>">WhatsApp</a>
        <a href="/saas/pagbank.php" class="<?= basename($_SERVER['PHP_SELF']) === 'pagbank.php' ? 'active' : '' ?>">PagBank</a>
        <a href="/escolher_plano.php">Escolher plano</a>
        <a href="/cadastro.php">Nova empresa</a>
        <a href="/saas/equipe_suporte.php" class="<?= $saasPage === 'equipe_suporte.php' ? 'active' : '' ?>">Equipe de Suporte</a>
        <a href="/saas/logout.php" style="margin-top:8px;background:rgba(185,28,28,.20);border-color:rgba(254,202,202,.26)">Sair</a>
      </nav>
    </div>
  </aside>

  <main class="main">
    <section class="topbar">
      <h1><?= $pageTitle ?></h1>
      <?php if ($pageSubtitle !== ''): ?>
        <p><?= $pageSubtitle ?></p>
      <?php endif; ?>
<?php
  }

  function saas_layout_end(): void {
?>
    </section>
  </main>
</div>
</body>
</html>
<?php
  }
}