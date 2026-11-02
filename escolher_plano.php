<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/public_plans.php';

function has_column_local(PDO $pdo, string $table, string $column): bool
{
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
}

$hasSort = has_column_local($pdo, 'plans', 'sort_order');
$hasHeadline = has_column_local($pdo, 'plans', 'headline');
$hasSummary = has_column_local($pdo, 'plans', 'summary');
$hasOriginal = has_column_local($pdo, 'plans', 'original_price');
$hasBadge = has_column_local($pdo, 'plans', 'badge_text');
$hasButton = has_column_local($pdo, 'plans', 'button_label');
$hasOrders = has_column_local($pdo, 'plans', 'max_orders');
$hasTrial = has_column_local($pdo, 'plans', 'trial_days');
$hasSlug = has_column_local($pdo, 'plans', 'slug');

$plans = public_plans_fetch($pdo);
$mainSiteUrl = rtrim((string)(getenv('NEXA_MAIN_SITE_URL') ?: 'https://nexaprospero.com.br/'), '/') . '/';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Escolha seu plano | Nexa Próspero</title>
<style>
:root{
  --primary-dark:#0b1f5b;
  --primary:#2454d6;
  --primary-2:#007bff;
  --cyan:#00b8ff;
  --teal:#147a8a;
  --bg:#f3f7fc;
  --card:#ffffff;
  --text:#071427;
  --muted:#637083;
  --line:#dce7f5;
  --shadow:0 22px 60px rgba(11,31,91,.12);
  --shadow-soft:0 14px 34px rgba(11,31,91,.08);
}
*{box-sizing:border-box}
html{scroll-behavior:smooth}
body{
  margin:0;
  min-height:100vh;
  font-family:Inter,Segoe UI,Arial,Helvetica,sans-serif;
  color:var(--text);
  background:
    radial-gradient(circle at 8% 10%, rgba(0,184,255,.16), transparent 28%),
    radial-gradient(circle at 92% 0%, rgba(36,84,214,.18), transparent 32%),
    linear-gradient(180deg,#f8fbff 0%,var(--bg) 58%,#eef4fb 100%);
}
a{text-decoration:none;color:inherit}
.page{
  width:min(1180px,calc(100% - 36px));
  margin:0 auto;
  padding:28px 0 44px;
}
.topbar{
  display:flex;
  justify-content:space-between;
  align-items:center;
  gap:18px;
  margin-bottom:24px;
}
.brand{
  display:flex;
  align-items:center;
  gap:13px;
  min-width:0;
  border-radius:22px;
  padding:8px 12px 8px 8px;
  transition:all .22s ease;
}
.brand:hover{
  background:rgba(255,255,255,.72);
  box-shadow:var(--shadow-soft);
  transform:translateY(-1px);
}
.brand img{
  width:74px;
  height:54px;
  object-fit:contain;
  background:#fff;
  border:1px solid rgba(217,229,251,.95);
  border-radius:18px;
  padding:6px;
  box-shadow:0 12px 26px rgba(11,31,91,.10);
}
.brand strong{display:block;font-size:20px;letter-spacing:-.03em;color:var(--primary-dark)}
.brand span{display:block;color:var(--muted);font-size:13px;margin-top:3px}
.top-actions{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.btn{
  display:inline-flex;
  align-items:center;
  justify-content:center;
  gap:8px;
  border-radius:999px;
  border:1px solid var(--line);
  padding:12px 18px;
  font-weight:850;
  font-size:14px;
  cursor:pointer;
  background:#fff;
  color:var(--primary-dark);
  box-shadow:0 10px 24px rgba(11,31,91,.07);
  transition:all .22s ease;
}
.btn:hover{transform:translateY(-2px);box-shadow:0 16px 34px rgba(36,84,214,.16);border-color:rgba(0,184,255,.5)}
.btn-primary{
  color:#fff;
  border-color:transparent;
  background:linear-gradient(135deg,var(--primary),var(--cyan));
  box-shadow:0 16px 32px rgba(0,184,255,.24);
}
.btn-primary:hover{box-shadow:0 20px 42px rgba(0,184,255,.34)}
.btn-ghost{background:rgba(255,255,255,.72)}
.hero{
  position:relative;
  overflow:hidden;
  display:grid;
  grid-template-columns:1.35fr .65fr;
  gap:24px;
  align-items:stretch;
  padding:34px;
  border-radius:34px;
  color:#fff;
  background:
    radial-gradient(circle at 84% 18%, rgba(0,184,255,.42), transparent 28%),
    radial-gradient(circle at 8% 90%, rgba(36,84,214,.38), transparent 34%),
    linear-gradient(135deg,#081b4b 0%,#123f8f 50%,#107c8a 100%);
  box-shadow:var(--shadow);
}
.hero:before{
  content:"";
  position:absolute;
  inset:auto -90px -120px auto;
  width:340px;
  height:340px;
  border-radius:50%;
  background:rgba(255,255,255,.10);
  filter:blur(2px);
}
.hero > *{position:relative;z-index:1}
.eyebrow{
  display:inline-flex;
  align-items:center;
  gap:8px;
  margin-bottom:12px;
  padding:8px 12px;
  border-radius:999px;
  background:rgba(255,255,255,.12);
  border:1px solid rgba(255,255,255,.20);
  color:#eaf7ff;
  font-size:12px;
  font-weight:900;
  letter-spacing:.09em;
  text-transform:uppercase;
}
.hero h1{
  margin:0;
  max-width:820px;
  font-size:clamp(32px,5vw,58px);
  line-height:1.02;
  letter-spacing:-.055em;
}
.hero p{
  margin:16px 0 0;
  max-width:760px;
  color:#dcecff;
  font-size:17px;
  line-height:1.62;
}
.hero-actions{
  display:flex;
  gap:12px;
  flex-wrap:wrap;
  margin-top:24px;
}
.hero-card{
  border-radius:28px;
  padding:24px;
  background:rgba(255,255,255,.13);
  border:1px solid rgba(255,255,255,.22);
  backdrop-filter:blur(12px);
  box-shadow:0 20px 50px rgba(1,12,36,.18);
}
.hero-card strong{
  display:block;
  font-size:18px;
  margin-bottom:12px;
}
.hero-list{
  display:grid;
  gap:11px;
  margin:0;
  padding:0;
  list-style:none;
}
.hero-list li{
  display:flex;
  gap:10px;
  align-items:flex-start;
  color:#eef8ff;
  font-size:14px;
  line-height:1.45;
}
.check{
  flex:0 0 auto;
  width:22px;
  height:22px;
  border-radius:50%;
  display:grid;
  place-items:center;
  background:rgba(0,184,255,.92);
  color:#fff;
  font-size:13px;
  font-weight:900;
}
.section-head{
  display:flex;
  justify-content:space-between;
  gap:18px;
  align-items:flex-end;
  margin:34px 0 18px;
}
.section-head h2{
  margin:0;
  font-size:28px;
  letter-spacing:-.04em;
  color:var(--primary-dark);
}
.section-head p{
  margin:7px 0 0;
  color:var(--muted);
  line-height:1.5;
}
.trust-pill{
  display:inline-flex;
  align-items:center;
  gap:8px;
  padding:10px 14px;
  border-radius:999px;
  background:#fff;
  border:1px solid var(--line);
  color:var(--primary-dark);
  font-size:13px;
  font-weight:850;
  box-shadow:var(--shadow-soft);
  white-space:nowrap;
}
.grid{
  display:grid;
  grid-template-columns:repeat(auto-fit,minmax(280px,1fr));
  gap:18px;
}
.plan-card{
  position:relative;
  overflow:hidden;
  display:flex;
  flex-direction:column;
  min-height:430px;
  padding:24px;
  border-radius:30px;
  background:rgba(255,255,255,.92);
  border:1px solid rgba(220,231,245,.96);
  box-shadow:0 18px 44px rgba(11,31,91,.08);
  transition:all .24s ease;
}
.plan-card:hover{
  transform:translateY(-5px);
  box-shadow:0 26px 62px rgba(36,84,214,.16);
  border-color:rgba(0,184,255,.46);
}
.plan-card.featured{
  border-color:rgba(0,184,255,.62);
  box-shadow:0 24px 64px rgba(0,123,255,.16);
}
.plan-card.featured:before{
  content:"";
  position:absolute;
  inset:0 0 auto;
  height:6px;
  background:linear-gradient(90deg,var(--primary),var(--cyan));
}
.card-top{
  display:flex;
  justify-content:space-between;
  align-items:flex-start;
  gap:12px;
}
.badge{
  display:inline-flex;
  align-items:center;
  align-self:flex-start;
  padding:8px 12px;
  border-radius:999px;
  background:linear-gradient(135deg,rgba(36,84,214,.12),rgba(0,184,255,.16));
  color:var(--primary-dark);
  font-weight:900;
  font-size:12px;
  border:1px solid rgba(0,184,255,.35);
}
.badge.free{background:#ecfdf5;color:#047857;border-color:#bbf7d0}
.plan-card h3{
  margin:16px 0 10px;
  font-size:27px;
  line-height:1.1;
  letter-spacing:-.04em;
  color:var(--text);
}
.price-row{
  display:flex;
  align-items:flex-end;
  gap:8px;
  flex-wrap:wrap;
  margin:0 0 8px;
}
.price{
  font-size:36px;
  line-height:1;
  font-weight:950;
  letter-spacing:-.055em;
  color:var(--primary-dark);
}
.period{
  color:var(--muted);
  font-size:13px;
  font-weight:800;
  padding-bottom:4px;
}
.original{
  display:inline-block;
  margin:2px 0 10px;
  color:#94a3b8;
  text-decoration:line-through;
  font-weight:750;
}
.meta{
  color:var(--muted);
  font-size:14px;
  line-height:1.62;
  min-height:68px;
  margin:0 0 16px;
}
.pills{
  display:flex;
  gap:8px;
  flex-wrap:wrap;
  margin:0 0 18px;
}
.pill{
  display:inline-flex;
  align-items:center;
  gap:6px;
  padding:8px 10px;
  border-radius:999px;
  background:#f3f8ff;
  border:1px solid #dbeafe;
  color:#24436b;
  font-size:12px;
  font-weight:850;
}
.features{
  display:grid;
  gap:10px;
  margin:2px 0 22px;
  padding:0;
  list-style:none;
}
.features li{
  display:flex;
  align-items:flex-start;
  gap:9px;
  color:#334155;
  font-size:14px;
  line-height:1.42;
}
.features .check{
  width:20px;
  height:20px;
  font-size:12px;
  background:linear-gradient(135deg,var(--primary),var(--cyan));
}
.card-action{
  margin-top:auto;
}
.card-action .btn{width:100%;padding:13px 18px}
.empty{
  grid-column:1/-1;
  padding:26px;
  border-radius:24px;
  background:#fff;
  border:1px solid var(--line);
  color:var(--muted);
  box-shadow:var(--shadow-soft);
}
.bottom-note{
  margin-top:24px;
  display:grid;
  grid-template-columns:repeat(3,1fr);
  gap:14px;
}
.note-card{
  padding:18px;
  border-radius:22px;
  background:rgba(255,255,255,.78);
  border:1px solid var(--line);
  box-shadow:0 12px 28px rgba(11,31,91,.06);
}
.note-card strong{
  display:block;
  color:var(--primary-dark);
  margin-bottom:5px;
}
.note-card span{
  display:block;
  color:var(--muted);
  font-size:13px;
  line-height:1.45;
}
@media (max-width:920px){
  .hero{grid-template-columns:1fr;padding:26px}
  .section-head{align-items:flex-start;flex-direction:column}
  .bottom-note{grid-template-columns:1fr}
}
@media (max-width:640px){
  .page{width:min(100%,calc(100% - 22px));padding-top:16px}
  .topbar{align-items:flex-start;flex-direction:column}
  .top-actions,.top-actions .btn{width:100%}
  .brand img{width:66px;height:50px}
  .hero{border-radius:26px;padding:22px}
  .hero-actions .btn{width:100%}
  .plan-card{border-radius:24px;min-height:auto}
}
</style>
</head>
<body>
<div class="page">
  <header class="topbar">
    <a class="brand" href="<?= e($mainSiteUrl) ?>">
      <img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Próspero">
      <span>
        <strong>Nexa Próspero</strong>
        <span>Planos para delivery digital</span>
      </span>
    </a>
    <div class="top-actions">
      <a class="btn btn-ghost" href="/cardapios.php">Ver catálogos</a>
      <a class="btn btn-primary" href="#planos">Escolher plano</a>
    </div>
  </header>

  <section class="hero">
    <div>
      <span class="eyebrow">Nexa Próspero</span>
      <h1>Escolha o plano ideal para vender mais com seu delivery.</h1>
      <p>Tenha um catálogo digital profissional, receba pedidos com mais organização e comece a transformar atendimento em resultado. Selecione um plano e avance para o cadastro da sua empresa.</p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="#planos">Comparar planos</a>
        <a class="btn" href="/cadastro.php">Cadastrar sem escolher plano</a>
      </div>
    </div>

    <aside class="hero-card">
      <strong>O que você leva para o seu negócio</strong>
      <ul class="hero-list">
        <li><span class="check">✓</span><span>Presença online com link próprio para divulgar seu catálogo.</span></li>
        <li><span class="check">✓</span><span>Experiência mais profissional para o cliente fazer pedidos.</span></li>
        <li><span class="check">✓</span><span>Central SaaS para acompanhar empresas, planos e ativações.</span></li>
        <li><span class="check">✓</span><span>Fluxo preparado para teste grátis ou pagamento via Pix.</span></li>
      </ul>
    </aside>
  </section>

  <div class="section-head" id="planos">
    <div>
      <h2>Planos disponíveis</h2>
      <p>Escolha a opção que melhor acompanha o momento do seu negócio.</p>
    </div>
    <span class="trust-pill">✓ Cadastro simples, rápido e seguro</span>
  </div>

  <section class="grid">
    <?php if (!$plans): ?>
      <div class="empty">
        Nenhum plano ativo foi encontrado no momento. Acesse a Central SaaS para ativar ou cadastrar os planos.
      </div>
    <?php endif; ?>

    <?php foreach ($plans as $index => $plan):
        $price = (float)($plan['price'] ?? 0);
        $isFree = $price <= 0;
        $hasPlanBadge = $hasBadge && !empty($plan['badge_text']);
        $title = (string)($hasHeadline && !empty($plan['headline']) ? $plan['headline'] : ($plan['name'] ?? 'Plano'));
        $summary = (string)($hasSummary && !empty($plan['summary']) ? $plan['summary'] : 'Plano SaaS para operação de delivery.');
        $buttonLabel = (string)($hasButton && !empty($plan['button_label']) ? $plan['button_label'] : 'Escolher plano');
        $planUrl = '/cadastro.php?plan=' . rawurlencode((string)($hasSlug ? ($plan['slug'] ?? $plan['id']) : $plan['id']));
        $featured = $hasPlanBadge || (!$isFree && $index === 1);
    ?>
      <article class="plan-card<?= $featured ? ' featured' : '' ?>">
        <div class="card-top">
          <?php if ($hasPlanBadge): ?>
            <span class="badge"><?= e((string)$plan['badge_text']) ?></span>
          <?php elseif ($isFree): ?>
            <span class="badge free">Teste inicial</span>
          <?php else: ?>
            <span class="badge">Plano profissional</span>
          <?php endif; ?>
        </div>

        <h3><?= e($title) ?></h3>

        <div class="price-row">
          <div class="price"><?= money($price) ?></div>
          <span class="period"><?= $isFree ? 'para testar' : '/ mês' ?></span>
        </div>

        <?php if ($hasOriginal && !empty($plan['original_price']) && (float)$plan['original_price'] > $price): ?>
          <div class="original">De <?= money((float)$plan['original_price']) ?></div>
        <?php endif; ?>

        <p class="meta"><?= e($summary) ?></p>

        <div class="pills">
          <?php if ($hasOrders && !empty($plan['max_orders'])): ?>
            <span class="pill">✓ Até <?= (int)$plan['max_orders'] ?> pedidos</span>
          <?php else: ?>
            <span class="pill">✓ Pedidos sob demanda</span>
          <?php endif; ?>

          <?php if ($hasTrial && (int)($plan['trial_days'] ?? 0) > 0): ?>
            <span class="pill">✓ <?= (int)$plan['trial_days'] ?> dias grátis</span>
          <?php elseif ($isFree): ?>
            <span class="pill">✓ Comece sem custo</span>
          <?php else: ?>
            <span class="pill">✓ Ativação pelo SaaS</span>
          <?php endif; ?>
        </div>

        <ul class="features">
          <li><span class="check">✓</span><span>Catálogo online para divulgar produtos e receber pedidos.</span></li>
          <li><span class="check">✓</span><span>Cadastro profissional da empresa com dados completos.</span></li>
          <li><span class="check">✓</span><span>Painel administrativo para acompanhar a operação.</span></li>
          <li><span class="check">✓</span><span><?= $isFree ? 'Ideal para validar o delivery antes de contratar.' : 'Plano preparado para contratação e pagamento via Pix.' ?></span></li>
        </ul>

        <div class="card-action">
          <a class="btn btn-primary" href="<?= e($planUrl) ?>"><?= e($buttonLabel) ?></a>
        </div>
      </article>
    <?php endforeach; ?>
  </section>

  <section class="bottom-note">
    <div class="note-card">
      <strong>Mais presença digital</strong>
      <span>Divulgue seu catálogo com uma página própria e visual profissional.</span>
    </div>
    <div class="note-card">
      <strong>Mais organização</strong>
      <span>Centralize cadastro, pedidos e informações importantes do negócio.</span>
    </div>
    <div class="note-card">
      <strong>Mais resultado</strong>
      <span>Facilite o pedido do cliente e aumente as chances de vender mais.</span>
    </div>
  </section>
</div>
</body>
</html>
