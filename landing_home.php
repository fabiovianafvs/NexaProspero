<?php
declare(strict_types=1);
require_once __DIR__ . '/config/helpers.php';

require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/public_plans.php';

function money_br(float $v): string {
  return 'R$ ' . number_format($v, 2, ',', '.');
}

$landingPlans = public_plans_fetch($pdo);
if (!$landingPlans) {
  $landingPlans = public_plans_default_rows();
}

$baseDomain = configured_root_domains()[0] ?? current_host_name();
$cadastroUrl = current_scheme() . '://' . $baseDomain . '/escolher_plano.php';
$saasLoginUrl = current_scheme() . '://' . $baseDomain . '/saas/login.php';
?><!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Nexa Próspero — Crie sua loja online em minutos</title>
  <meta name="description" content="Crie sua loja online com catálogo, pedidos e delivery em uma plataforma SaaS profissional e fácil de usar.">
  <style>
    :root {
      --bg: #f8fafc;
      --panel: #ffffff;
      --text: #0f172a;
      --muted: #475569;
      --line: #e2e8f0;
      --primary: #0f766e;
      --primary-2: #14b8a6;
      --accent: #f59e0b;
      --dark: #020617;
      --ok: #10b981;
      --shadow: 0 20px 60px rgba(15, 23, 42, 0.12);
      --radius: 28px;
      --max: 1180px;
    }
    * { box-sizing: border-box; }
    html { scroll-behavior: smooth; }
    body {
      margin: 0;
      font-family: Inter, ui-sans-serif, system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
      color: var(--text);
      background: linear-gradient(180deg, #f8fafc 0%, #eef6f6 100%);
    }
    a { color: inherit; text-decoration: none; }
    .wrap { max-width: var(--max); margin: 0 auto; padding: 0 24px; }
    .topbar {
      position: sticky; top: 0; z-index: 40;
      background: rgba(255,255,255,.85);
      backdrop-filter: blur(14px);
      border-bottom: 1px solid rgba(226,232,240,.9);
    }
    .topbar .wrap {
      display: flex; align-items: center; justify-content: space-between; gap: 20px; padding-top: 18px; padding-bottom: 18px;
    }
    .brand-title { font-size: 30px; font-weight: 900; letter-spacing: -.03em; }
    .brand-sub { font-size: 13px; color: var(--muted); margin-top: 4px; }
    .nav { display: flex; align-items: center; gap: 14px; flex-wrap: wrap; }
    .nav a.link { font-size: 14px; color: #334155; padding: 8px 10px; border-radius: 12px; }
    .nav a.link:hover { background: #f1f5f9; }
    .btn {
      display: inline-flex; align-items: center; justify-content: center; gap: 10px;
      padding: 14px 22px; border-radius: 18px; font-weight: 700; border: 1px solid transparent;
      transition: transform .15s ease, box-shadow .15s ease, opacity .15s ease;
    }
    .btn:hover { transform: translateY(-1px); }
    .btn-primary { background: var(--dark); color: #fff; box-shadow: var(--shadow); }
    .btn-primary:hover { opacity: .94; }
    .btn-outline { background: #fff; border-color: var(--line); color: var(--text); }
    .hero { padding: 76px 0 54px; }
    .hero-grid { display: grid; grid-template-columns: 1.1fr .9fr; gap: 42px; align-items: center; }
    .pill {
      display: inline-flex; padding: 10px 16px; border-radius: 999px; border: 1px solid var(--line);
      background: rgba(255,255,255,.82); color: var(--muted); font-size: 14px; box-shadow: 0 12px 28px rgba(15,23,42,.04);
    }
    h1 {
      margin: 18px 0 0; font-size: clamp(40px, 6vw, 68px); line-height: .98; letter-spacing: -.05em;
    }
    .hero p.lead { margin: 24px 0 0; font-size: 20px; line-height: 1.7; color: var(--muted); max-width: 760px; }
    .hero-cta { display: flex; gap: 16px; flex-wrap: wrap; margin-top: 30px; }
    .stats { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-top: 26px; max-width: 640px; }
    .stat { background: rgba(255,255,255,.92); border: 1px solid var(--line); border-radius: 24px; padding: 20px; box-shadow: 0 16px 35px rgba(15,23,42,.06); }
    .stat strong { font-size: 30px; display: block; }
    .stat span { font-size: 14px; color: var(--muted); display: block; margin-top: 6px; }
    .mock-wrap { position: relative; }
    .glow {
      position: absolute; inset: -18px; border-radius: 36px;
      background: radial-gradient(circle at 20% 20%, rgba(20,184,166,.3), transparent 40%),
                  radial-gradient(circle at 85% 15%, rgba(245,158,11,.25), transparent 35%),
                  radial-gradient(circle at 60% 85%, rgba(59,130,246,.2), transparent 35%);
      filter: blur(18px);
    }
    .mock {
      position: relative; overflow: hidden; border-radius: 34px; border: 1px solid rgba(15,23,42,.08);
      background: #fff; box-shadow: 0 30px 80px rgba(15,23,42,.16);
    }
    .mock-head {
      display: flex; align-items: center; justify-content: space-between; gap: 12px;
      padding: 18px 22px; background: linear-gradient(135deg, #062b28, #0f766e); color: #fff;
    }
    .mock-head small { display: block; color: rgba(255,255,255,.75); margin-top: 4px; }
    .tag { background: rgba(255,255,255,.12); border: 1px solid rgba(255,255,255,.14); border-radius: 999px; padding: 7px 12px; font-size: 12px; }
    .mock-body { padding: 20px; background: #f8fafc; }
    .item { background: #fff; border-radius: 22px; padding: 18px; border: 1px solid #e5e7eb; box-shadow: 0 10px 20px rgba(15,23,42,.05); display: flex; justify-content: space-between; gap: 20px; margin-bottom: 14px; }
    .item:last-child { margin-bottom: 0; }
    .item h3 { margin: 0 0 8px; font-size: 18px; }
    .item p { margin: 0; color: var(--muted); font-size: 14px; line-height: 1.55; }
    .price { text-align: right; white-space: nowrap; }
    .price strong { display: block; font-size: 20px; }
    .mini-btn { margin-top: 10px; background: var(--ok); color: #fff; border: 0; padding: 10px 14px; border-radius: 14px; font-weight: 700; }
    section.block { padding: 36px 0; }
    .section-head { text-align: center; max-width: 840px; margin: 0 auto 24px; }
    .section-head h2 { margin: 0; font-size: clamp(30px, 4vw, 46px); letter-spacing: -.04em; }
    .section-head p { margin: 16px 0 0; color: var(--muted); font-size: 18px; line-height: 1.75; }
    .cards { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; }
    .card { background: rgba(255,255,255,.96); border-radius: 30px; border: 1px solid var(--line); padding: 28px; box-shadow: 0 18px 35px rgba(15,23,42,.05); }
    .card .icon { width: 52px; height: 52px; border-radius: 16px; display: grid; place-items: center; font-size: 24px; margin-bottom: 18px; background: linear-gradient(135deg, rgba(20,184,166,.16), rgba(245,158,11,.16)); }
    .card h3 { margin: 0; font-size: 22px; }
    .card p { margin: 12px 0 0; color: var(--muted); line-height: 1.75; }
    .dark-panel { background: linear-gradient(135deg, #0f172a, #0b2942); border-radius: 38px; color: #fff; box-shadow: 0 30px 70px rgba(2,6,23,.35); }
    .dark-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 28px; align-items: center; padding: 46px; }
    .dark-panel h3 { margin: 12px 0 0; font-size: clamp(32px, 4vw, 46px); letter-spacing: -.04em; }
    .dark-panel p { color: rgba(255,255,255,.78); font-size: 18px; line-height: 1.75; }
    .eyebrow { color: #99f6e4; font-size: 13px; letter-spacing: .2em; text-transform: uppercase; font-weight: 800; }
    .metric-grid { display: grid; grid-template-columns: repeat(2,1fr); gap: 16px; }
    .metric, .alert-card { border-radius: 24px; padding: 22px; background: rgba(255,255,255,.08); border: 1px solid rgba(255,255,255,.1); }
    .alert-card { background: #fff; color: var(--text); }
    .alert-card small { color: var(--muted); }
    .plans { display: grid; grid-template-columns: repeat(4, 1fr); gap: 22px; max-width: 1220px; margin: 0 auto; }
    .plan { border-radius: 34px; padding: 34px; background: #fff; border: 1px solid var(--line); box-shadow: 0 16px 35px rgba(15,23,42,.06); }
    .plan.featured { background: linear-gradient(160deg, #0f172a, #0f3b35); color: #fff; border-color: rgba(255,255,255,.08); box-shadow: 0 28px 60px rgba(2,6,23,.28); }
    .plan small { color: var(--muted); }
    .plan.featured small { color: #99f6e4; }
    .plan .price-big { font-size: 52px; font-weight: 900; letter-spacing: -.05em; margin-top: 8px; line-height: 1; }
    .plan .price-note{margin-top:8px;color:var(--muted);font-weight:600;}
    .plan .price-extra-note{display:block;margin-top:4px;font-size:12px;line-height:1.35;font-weight:500;color:var(--muted);}
    .plan.featured .price-note{color:#dbeafe;}
    .plan.featured .price-extra-note{color:#dbeafe;}
    .plan .strike{display:inline-block;margin-left:8px;font-size:18px;text-decoration:line-through;color:#94a3b8;font-weight:700;}
    .plan.featured .strike{color:#99f6e4;}
    .plan ul { margin: 24px 0 0; padding: 0; list-style: none; }
    .plan li { margin: 12px 0; position: relative; padding-left: 24px; line-height: 1.6; }
    .plan li::before { content: '•'; position: absolute; left: 0; top: 0; color: var(--ok); font-size: 24px; line-height: 1; }
    .plan.featured li::before { color: #99f6e4; }
    .faq { max-width: 980px; margin: 0 auto; display: grid; gap: 16px; }
    .faq-item { background: #fff; border: 1px solid var(--line); border-radius: 24px; padding: 24px 26px; box-shadow: 0 10px 20px rgba(15,23,42,.04); }
    .faq-item h4 { margin: 0; font-size: 20px; }
    .faq-item p { margin: 10px 0 0; color: var(--muted); line-height: 1.75; }
    .final-cta {
      border-radius: 40px; padding: 48px 30px; text-align: center;
      background: linear-gradient(120deg, #0f766e, #06b6d4 55%, #0f766e 100%);
      color: #fff; box-shadow: 0 34px 70px rgba(6,182,212,.22);
    }
    .final-cta h2 { margin: 0; font-size: clamp(34px, 5vw, 58px); letter-spacing: -.05em; }
    .final-cta p { max-width: 820px; margin: 18px auto 0; line-height: 1.75; font-size: 19px; color: rgba(255,255,255,.92); }
    .final-cta .hero-cta { justify-content: center; }
    footer { border-top: 1px solid rgba(226,232,240,.9); background: rgba(255,255,255,.85); margin-top: 40px; }
    footer .wrap { display: flex; justify-content: space-between; gap: 18px; align-items: center; padding-top: 26px; padding-bottom: 26px; color: var(--muted); font-size: 14px; }
    .foot-links { display: flex; gap: 18px; flex-wrap: wrap; }
    @media (max-width: 1024px) {
      .hero-grid, .dark-grid, .cards, .plans { grid-template-columns: 1fr; }
      .stats { grid-template-columns: 1fr; max-width: none; }
    }
    @media (max-width: 720px) {
      .topbar .wrap, footer .wrap { flex-direction: column; align-items: flex-start; }
      .nav { width: 100%; }
      .hero { padding-top: 48px; }
      .wrap { padding-left: 18px; padding-right: 18px; }
      .dark-grid, .plan, .card, .final-cta { padding: 24px; }
      .brand-title { font-size: 26px; }
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="wrap">
      <div>
        <div class="brand-title">Nexa Próspero</div>
        <div class="brand-sub">Catálogo digital, pedidos e delivery em uma plataforma SaaS profissional</div>
      </div>
      <nav class="nav">
        <a class="link" href="#recursos">Recursos</a>
        <a class="link" href="#planos">Planos</a>
        <a class="link" href="#faq">FAQ</a>
        <a class="btn btn-outline" href="<?= e($saasLoginUrl) ?>">Entrar</a>
        <a class="btn btn-primary" href="<?= e($cadastroUrl) ?>">Criar minha loja</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="wrap hero-grid">
        <div>
          <div class="pill">SaaS multiempresa para catálogo, pedidos e delivery</div>
          <h1>Crie sua loja online em minutos e receba pedidos com mais praticidade.</h1>
          <p class="lead">Ideal para pizzarias, lanchonetes, restaurantes, padarias, lojas e operações locais que precisam vender mais com um catálogo profissional, link próprio e gestão centralizada.</p>
          <div class="hero-cta">
            <a class="btn btn-primary" href="<?= e($cadastroUrl) ?>">Criar minha loja agora</a>
            <a class="btn btn-outline" href="#demo">Ver demonstração</a>
          </div>
          <div class="stats">
            <div class="stat"><strong>24/7</strong><span>catálogo online disponível para seus clientes</span></div>
            <div class="stat"><strong>1 link</strong><span>próprio para fortalecer a marca da empresa</span></div>
            <div class="stat"><strong>+ controle</strong><span>da operação, pedidos e informações da loja</span></div>
          </div>
        </div>
        <div class="mock-wrap">
          <div class="glow"></div>
          <div class="mock">
            <div class="mock-head">
              <div>
                <strong>Minha Pizzaria</strong>
                <small>minhapizzaria.nexaprospero.com.br</small>
              </div>
              <div class="tag">Loja ativa</div>
            </div>
            <div class="mock-body">
              <div class="item">
                <div>
                  <h3>Pizza Frango com Catupiry</h3>
                  <p>Catálogo bonito, rápido e preparado para vender com mais profissionalismo.</p>
                </div>
                <div class="price">
                  <strong>R$ 59,90</strong>
                  <button class="mini-btn" type="button">Adicionar</button>
                </div>
              </div>
              <div class="item">
                <div>
                  <h3>Pizza Três Queijos</h3>
                  <p>Produtos organizados por categoria, com descrição e preço sempre claros.</p>
                </div>
                <div class="price">
                  <strong>R$ 59,90</strong>
                  <button class="mini-btn" type="button">Adicionar</button>
                </div>
              </div>
              <div class="item">
                <div>
                  <h3>Prato Executivo</h3>
                  <p>Uma experiência simples para o cliente finalizar o pedido com rapidez.</p>
                </div>
                <div class="price">
                  <strong>R$ 39,90</strong>
                  <button class="mini-btn" type="button">Adicionar</button>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="block" id="recursos">
      <div class="wrap">
        <div class="section-head">
          <h2>Tudo o que você precisa para vender mais</h2>
          <p>Uma plataforma pensada para quem quer praticidade, velocidade e um visual profissional para apresentar produtos e receber pedidos sem complicação.</p>
        </div>
        <div class="cards">
          <article class="card"><div class="icon">🛍️</div><h3>Catálogo online</h3><p>Produtos organizados por categorias, com fotos, descrições e preços atualizados em uma vitrine digital profissional.</p></article>
          <article class="card"><div class="icon">📦</div><h3>Pedidos rápidos</h3><p>Receba pedidos com mais clareza, acompanhe as etapas e tenha uma operação mais organizada no dia a dia.</p></article>
          <article class="card"><div class="icon">🌐</div><h3>Subdomínio próprio</h3><p>Cada empresa pode ter seu próprio link, fortalecendo marca, presença digital e facilidade de divulgação.</p></article>
          <article class="card"><div class="icon">⚙️</div><h3>Painel administrativo</h3><p>Gerencie produtos, banners, horários, informações da loja e outros pontos importantes em um só lugar.</p></article>
          <article class="card"><div class="icon">📊</div><h3>Central SaaS</h3><p>Controle várias empresas em uma única operação, com estrutura preparada para escalar e crescer com segurança.</p></article>
          <article class="card"><div class="icon">🚀</div><h3>Preparado para expansão</h3><p>Base pronta para evoluir com integrações, planos, cobrança recorrente e uma gestão cada vez mais profissional.</p></article>
        </div>
      </div>
    </section>

    <section class="block" id="demo">
      <div class="wrap">
        <div class="dark-panel">
          <div class="dark-grid">
            <div>
              <div class="eyebrow">Demonstração</div>
              <h3>Visual profissional para sua loja vender melhor.</h3>
              <p>Organize produtos, destaque promoções e facilite o pedido do cliente com uma experiência clara, moderna e eficiente.</p>
              <div class="hero-cta">
                <a class="btn btn-outline" href="<?= e($cadastroUrl) ?>">Quero criar minha loja</a>
                <a class="btn btn-outline" href="<?= e($saasLoginUrl) ?>">Acessar central</a>
              </div>
            </div>
            <div>
              <div class="alert-card">
                <small>Pedido recebido</small>
                <div style="font-size:30px;font-weight:900;margin-top:8px;letter-spacing:-.03em;">Pizza Frango com Catupiry</div>
                <p style="margin:10px 0 0;color:#475569;line-height:1.7;">Cliente finalizou o pedido em poucos cliques, com uma jornada simples e objetiva.</p>
              </div>
              <div class="metric-grid" style="margin-top:16px;">
                <div class="metric"><small style="color:#cbd5e1;">Catálogos ativos</small><div style="font-size:34px;font-weight:900;margin-top:8px;">+ lojas</div></div>
                <div class="metric"><small style="color:#cbd5e1;">Pedidos online</small><div style="font-size:34px;font-weight:900;margin-top:8px;">24h</div></div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </section>

    <section class="block" id="planos">
      <div class="wrap">
        <div class="section-head">
          <h2>Comece com 7 dias grátis e evolua conforme seu volume de pedidos.</h2>
          <p>Os planos da landing e da Central SaaS agora seguem a mesma base. Quando o cliente clicar, ele já vai para o cadastro com o plano pré-selecionado.</p>
        </div>
        <div class="plans">
          <?php foreach ($landingPlans as $plan): ?>
            <?php
              $isFeatured = !empty($plan['is_featured']);
              $cadastroPlanoUrl = $cadastroUrl . '?plan=' . urlencode((string)($plan['slug'] ?? ''));
              $price = (float)($plan['price'] ?? 0);
              $original = $plan['original_price'] ?? null;
              $maxOrders = $plan['max_orders'] ?? null;
              $trialDays = (int)($plan['trial_days'] ?? 0);
            ?>
            <article class="plan<?= $isFeatured ? ' featured' : '' ?>">
              <?php if (!empty($plan['badge_text'])): ?><small><?= e((string)$plan['badge_text']) ?></small><?php endif; ?>
              <div style="font-size:18px;font-weight:800;margin-top:14px;"><?= e((string)$plan['name']) ?></div>
              <div class="price-big"><?= money_br($price) ?><?php if ($trialDays > 0): ?><span style="font-size:20px;color:#64748b;">/<?= $trialDays ?> dias</span><?php endif; ?></div>
              <?php if ($original !== null && (float)$original > $price): ?><div class="price-note"><span class="strike"><?= money_br((float)$original) ?></span></div><?php endif; ?>
              <div class="price-note">
                <?php if ($trialDays > 0): ?>Até <?= (int)($maxOrders ?: 30) ?> pedidos<?php elseif (!empty($maxOrders)): ?>Até <?= (int)$maxOrders ?> pedidos<?php else: ?>De 500 a 1000 pedidos<span class="price-extra-note">Acima de 1000, entrar em contato</span><?php endif; ?>
              </div>
              <p style="line-height:1.75;<?= $isFeatured ? 'color:rgba(255,255,255,.82);' : 'color:var(--muted);' ?>"><?= e((string)($plan['summary'] ?? 'Plano disponível na plataforma.')) ?></p>
              <ul>
                <?php if ($trialDays > 0): ?>
                  <li>Catálogo online</li>
                  <li>Pedidos por WhatsApp</li>
                  <li>Painel básico</li>
                  <li>Até <?= (int)($maxOrders ?: 30) ?> pedidos</li>
                <?php elseif ((string)($plan['slug'] ?? '') === 'essencial'): ?>
                  <li>Catálogo completo</li>
                  <li>Carrinho e pedidos</li>
                  <li>Mensagem organizada no WhatsApp</li>
                  <li>Personalização da marca</li>
                <?php elseif ((string)($plan['slug'] ?? '') === 'profissional'): ?>
                  <li>Tudo do Essencial</li>
                  <li>Relatórios e gestão mais robusta</li>
                  <li>Melhor suporte operacional</li>
                  <li>Ideal para volume crescente</li>
                <?php else: ?>
                  <li>Tudo do Profissional</li>
                  <li>Integração com Próspero ERP</li>
                  <li>Fluxo automatizado de pedidos</li>
                  <li>Suporte premium</li>
                <?php endif; ?>
              </ul>
              <div class="hero-cta"><a class="btn <?= $isFeatured ? 'btn-outline' : 'btn-primary' ?>" href="<?= e($cadastroPlanoUrl) ?>"><?= e((string)($plan['button_label'] ?? 'Quero esse plano')) ?></a></div>
            </article>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <section class="block" id="faq">
      <div class="wrap">
        <div class="section-head">
          <h2>Perguntas frequentes</h2>
        </div>
        <div class="faq">
          <article class="faq-item"><h4>Preciso instalar algo?</h4><p>Não. O sistema funciona online e você acessa tudo pelo navegador.</p></article>
          <article class="faq-item"><h4>Minha empresa terá um link próprio?</h4><p>Sim. Cada empresa pode usar um subdomínio próprio dentro da plataforma.</p></article>
          <article class="faq-item"><h4>Posso cadastrar produtos e categorias?</h4><p>Sim. O painel permite organizar seu catálogo de forma prática e profissional.</p></article>
          <article class="faq-item"><h4>Funciona para delivery?</h4><p>Sim. A estrutura foi pensada para catálogo, pedidos e operações de entrega.</p></article>
        </div>
      </div>
    </section>

    <section class="block">
      <div class="wrap">
        <div class="final-cta">
          <h2>Pronto para colocar sua loja online?</h2>
          <p>Crie sua empresa agora e comece a divulgar seu catálogo com um link profissional e uma estrutura pensada para crescer de forma organizada.</p>
          <div class="hero-cta">
            <a class="btn btn-outline" href="<?= e($cadastroUrl) ?>">Criar minha loja</a>
            <a class="btn btn-outline" href="<?= e($saasLoginUrl) ?>">Entrar na central SaaS</a>
          </div>
        </div>
      </div>
    </section>
  </main>

  <footer>
    <div class="wrap">
      <div>© 2026 Nexa Próspero. Todos os direitos reservados.</div>
      <div class="foot-links">
        <a href="#planos">Planos</a>
        <a href="#faq">FAQ</a>
        <a href="<?= e($saasLoginUrl) ?>">Central SaaS</a>
      </div>
    </div>
  </footer>
</body>
</html>
