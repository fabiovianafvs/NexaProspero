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
  --bg:#f5f7fb;
  --card:#ffffff;
  --text:#0f172a;
  --muted:#475569;
  --muted-soft:#64748b;
  --line:#e8eef5;
  --primary:#147a8a;
  --primary-dark:#0f172a;
  --white:#ffffff;
  --danger:#b91c1c;
  --danger-dark:#991b1b;
  --success:#0f766e;
  --success-dark:#115e59;
  --shadow:0 12px 32px rgba(2,6,23,.06);
  --radius:22px;
  --sidebar-w:250px;
}

*{box-sizing:border-box}

html,body{
  margin:0;
  padding:0;
  background:var(--bg);
  color:var(--text);
  font-family:Arial, Helvetica, sans-serif;
}

body{
  overflow-x:hidden;
}

a{
  color:inherit;
}

.saas-app{
  display:flex;
  min-height:100vh;
}

.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(180deg,#0f172a 0%, #147a8a 100%);
  color:#fff;
  position:fixed;
  left:0;
  top:0;
  bottom:0;
  padding:22px 18px;
  box-shadow:12px 0 30px rgba(15,23,42,.10);
  z-index:30;
}

.brand{
  display:block;
  text-decoration:none;
  color:#fff;
  margin-bottom:22px;
}

.brand .name{
  font-size:24px;
  font-weight:800;
  line-height:1.1;
  color:#ffffff;
}

.brand .sub{
  margin-top:6px;
  font-size:13px;
  color:#dbeafe;
}

.nav-group{
  margin-top:18px;
}

.nav-label{
  font-size:11px;
  text-transform:uppercase;
  letter-spacing:.08em;
  color:rgba(255,255,255,.70);
  margin:0 0 10px 10px;
  font-weight:700;
}

.nav{
  display:flex;
  flex-direction:column;
  gap:8px;
}

.nav a{
  display:flex;
  align-items:center;
  min-height:44px;
  padding:10px 14px;
  border-radius:14px;
  text-decoration:none;
  color:#ffffff;
  font-weight:700;
  border:1px solid rgba(255,255,255,.10);
  background:rgba(255,255,255,.07);
  transition:.2s;
}

.nav a:hover{
  background:rgba(255,255,255,.15);
  border-color:rgba(255,255,255,.18);
}

.nav a.active{
  background:#ffffff;
  color:#0f172a;
  border-color:#ffffff;
}

.main{
  margin-left:var(--sidebar-w);
  width:calc(100% - var(--sidebar-w));
  padding:24px;
  min-width:0;
}

.topbar{
  background:linear-gradient(135deg,#0f172a 0%, #147a8a 100%);
  color:#ffffff;
  border-radius:24px;
  padding:24px 26px;
  border:1px solid rgba(255,255,255,.08);
  box-shadow:0 18px 45px rgba(15,23,42,.12);
  margin-bottom:18px;
}

.topbar h1{
  margin:0;
  font-size:34px;
  line-height:1.05;
  font-weight:800;
  color:#ffffff;
}

.topbar p{
  margin:8px 0 0;
  color:#dbeafe;
  font-size:15px;
  font-weight:500;
}

.top-actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:18px;
}

.content-grid{
  display:grid;
  gap:16px;
}

.panel{
  background:var(--card);
  border-radius:var(--radius);
  padding:20px;
  box-shadow:var(--shadow);
  border:1px solid var(--line);
  min-width:0;
}

.panel h3{
  margin:0 0 14px;
  color:var(--text);
  font-size:22px;
  font-weight:800;
}

.panel p{
  color:var(--text);
}

.cards{
  display:grid;
  grid-template-columns:repeat(6,minmax(0,1fr));
  gap:16px;
}

.card{
  background:var(--card);
  border-radius:var(--radius);
  padding:18px;
  box-shadow:var(--shadow);
  border:1px solid var(--line);
  min-width:0;
}

.card .label{
  color:var(--muted);
  font-size:13px;
  margin-bottom:10px;
  font-weight:600;
}

.card .value{
  font-size:30px;
  font-weight:800;
  color:var(--text);
}

.card .sub{
  color:var(--muted-soft);
  font-size:12px;
  margin-top:6px;
  font-weight:500;
}

.grid-2{
  display:grid;
  grid-template-columns:minmax(0,1fr) minmax(0,1fr);
  gap:16px;
}

.grid-main{
  display:grid;
  grid-template-columns:minmax(0,1.35fr) minmax(320px,0.75fr);
  gap:16px;
}

.btn2,
a.btn2,
button.btn2{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  min-height:44px !important;
  padding:12px 16px !important;
  border-radius:14px !important;
  text-decoration:none !important;
  font-weight:700 !important;
  font-size:14px !important;
  border:1px solid transparent !important;
  cursor:pointer !important;
  box-shadow:none !important;
  line-height:1 !important;
  white-space:nowrap !important;
  margin:0 !important;
}

.btn2-primary,
a.btn2-primary,
button.btn2-primary{
  background:#ffffff !important;
  color:#0f172a !important;
  border-color:#ffffff !important;
}

.btn2-primary:hover,
a.btn2-primary:hover,
button.btn2-primary:hover{
  background:#f8fafc !important;
  color:#0f172a !important;
  border-color:#f8fafc !important;
}

.btn2-ghost,
a.btn2-ghost,
button.btn2-ghost{
  background:rgba(255,255,255,.10) !important;
  color:#ffffff !important;
  border:1px solid rgba(255,255,255,.22) !important;
}

.btn2-ghost:hover,
a.btn2-ghost:hover,
button.btn2-ghost:hover{
  background:rgba(255,255,255,.16) !important;
  color:#ffffff !important;
  border-color:rgba(255,255,255,.28) !important;
}

.btn2-dark,
a.btn2-dark,
button.btn2-dark{
  background:var(--success) !important;
  color:#ffffff !important;
  border-color:var(--success) !important;
}

.btn2-dark:hover,
a.btn2-dark:hover,
button.btn2-dark:hover{
  background:var(--success-dark) !important;
  color:#ffffff !important;
  border-color:var(--success-dark) !important;
}

.btn2-danger,
a.btn2-danger,
button.btn2-danger{
  background:var(--danger) !important;
  color:#ffffff !important;
  border-color:var(--danger) !important;
}

.btn2-danger:hover,
a.btn2-danger:hover,
button.btn2-danger:hover{
  background:var(--danger-dark) !important;
  color:#ffffff !important;
  border-color:var(--danger-dark) !important;
}

table{
  width:100%;
  border-collapse:collapse;
}

th,td{
  padding:12px 10px;
  border-bottom:1px solid #eef2f7;
  text-align:left;
  vertical-align:top;
}

th{
  color:var(--muted);
  font-size:13px;
  font-weight:700;
}

td{
  color:var(--text);
  font-size:14px;
}

.table-wrap{
  width:100%;
  overflow-x:auto;
}

.table-wrap table{
  min-width:0;
  table-layout:fixed;
}

.table-wrap th,
.table-wrap td{
  word-break:break-word;
  overflow-wrap:anywhere;
}

.pill{
  display:inline-flex;
  align-items:center;
  padding:6px 10px;
  border-radius:999px;
  font-size:12px;
  font-weight:700;
}

.muted{
  color:var(--muted);
  font-weight:500;
}

.row-actions{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
}

.mini,
a.mini,
button.mini{
  display:inline-flex !important;
  align-items:center !important;
  justify-content:center !important;
  min-height:36px !important;
  padding:8px 12px !important;
  border-radius:10px !important;
  font-weight:700 !important;
  font-size:12px !important;
  text-decoration:none !important;
  border:1px solid #cbd5e1 !important;
  background:#ffffff !important;
  color:#0f172a !important;
  white-space:nowrap !important;
  margin:0 !important;
}

.mini:hover{
  background:#f8fafc !important;
}

.mini-primary{
  background:#0f766e !important;
  color:#ffffff !important;
  border-color:#0f766e !important;
}

.mini-primary:hover{
  background:#115e59 !important;
}

.alert-ok{
  background:#ecfdf5;
  color:#065f46;
  border:1px solid #a7f3d0;
  padding:12px 14px;
  border-radius:14px;
  margin-bottom:16px;
  font-weight:600;
}

.alert-err{
  background:#fef2f2;
  color:#b91c1c;
  border:1px solid #fecaca;
  padding:12px 14px;
  border-radius:14px;
  margin-bottom:16px;
  font-weight:600;
}

.warn{
  background:#fff7ed;
  color:#9a3412;
  border:1px solid #fdba74;
  border-radius:16px;
  padding:14px 16px;
  margin-bottom:16px;
  font-weight:600;
}

.field,select{
  width:100%;
  padding:12px 14px;
  border-radius:12px;
  border:1px solid #dbe4ee;
  background:#fff;
  color:#0f172a;
}

code.box{
  display:block;
  padding:14px;
  border-radius:14px;
  background:#0f172a;
  color:#fff;
  font-size:16px;
  word-break:break-word;
}

@media (max-width: 1200px){
  .cards{grid-template-columns:repeat(3,minmax(0,1fr))}
}

@media (max-width: 960px){
  .sidebar{
    position:static;
    width:100%;
    border-radius:0 0 24px 24px;
  }
  .saas-app{display:block}
  .main{
    margin-left:0;
    width:100%;
    padding:16px;
  }
  .grid-2,
  .grid-main{
    grid-template-columns:1fr;
  }
}

@media (max-width: 700px){
  .cards{grid-template-columns:1fr}
  .topbar h1{font-size:28px}
}
</style>
</head>
<body>
<div class="saas-app">
  <aside class="sidebar">
    <a class="brand" href="/saas/dashboard.php">
      <div class="name">Central SaaS</div>
      <div class="sub">Nexa Pr&oacute;spero</div>
    </a>

    <div class="nav-group">
      <div class="nav-label">Opera&ccedil;&atilde;o</div>
      <nav class="nav">
        <a href="/saas/dashboard.php" class="<?= basename($_SERVER['PHP_SELF']) === 'dashboard.php' ? 'active' : '' ?>">Dashboard</a>
        <a href="/saas/empresas.php" class="<?= basename($_SERVER['PHP_SELF']) === 'empresas.php' ? 'active' : '' ?>">Empresas</a>
        <a href="/saas/planos.php" class="<?= basename($_SERVER['PHP_SELF']) === 'planos.php' ? 'active' : '' ?>">Planos</a>
        <a href="/cadastro.php">Nova empresa</a>
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