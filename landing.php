<?php
declare(strict_types=1);
require_once __DIR__ . '/config/helpers.php';

$scheme = current_scheme();
$rootDomain = configured_root_domains()[0] ?? current_host_name();
$base = $scheme . '://' . $rootDomain;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Nexa Próspero — Crie sua loja online em minutos</title>
<meta name="description" content="Crie sua loja online com catálogo digital, pedidos e painel simples para administrar seu negócio.">
<style>
:root{--bg:#0f172a;--bg2:#111827;--text:#0f172a;--muted:#64748b;--primary:#0f766e;--primary2:#14b8a6;--accent:#f59e0b;--white:#fff;--line:#e5e7eb;--panel:#ffffff;--soft:#f8fafc}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;font-family:Arial,Helvetica,sans-serif;color:var(--text);background:#fff}a{text-decoration:none;color:inherit}
.wrap{max-width:1160px;margin:0 auto;padding:0 20px}.top{position:sticky;top:0;z-index:20;background:rgba(255,255,255,.96);backdrop-filter:blur(8px);border-bottom:1px solid var(--line)}
.nav{display:flex;justify-content:space-between;align-items:center;min-height:74px;gap:16px}.brand strong{display:block;font-size:22px}.brand span{font-size:13px;color:var(--muted)}
.navlinks{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.navlinks a{font-size:14px;color:#334155}.btn{display:inline-flex;align-items:center;justify-content:center;padding:14px 22px;border-radius:14px;font-weight:700}.btn-primary{background:var(--text);color:#fff}.btn-light{background:#fff;border:1px solid var(--line)}.btn-accent{background:var(--accent);color:#111827}
.hero{background:linear-gradient(135deg,var(--soft),#ecfeff 55%,#fff7ed);padding:72px 0}.hero-grid{display:grid;grid-template-columns:1.15fr .85fr;gap:36px;align-items:center}.badge{display:inline-flex;background:#fff;border:1px solid var(--line);border-radius:999px;padding:10px 14px;font-size:13px;color:var(--muted)}
.hero h1{font-size:54px;line-height:1.04;margin:18px 0 14px}.hero p{font-size:19px;line-height:1.65;color:#334155;max-width:720px}.hero-actions{display:flex;flex-wrap:wrap;gap:14px;margin-top:28px}.metrics{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-top:24px;max-width:700px}.metric{background:#fff;border:1px solid var(--line);border-radius:20px;padding:18px}.metric strong{font-size:26px;display:block}.metric span{color:var(--muted);font-size:14px}
.mock{background:#fff;border:1px solid var(--line);border-radius:30px;overflow:hidden;box-shadow:0 20px 60px rgba(15,23,42,.12)}.mock-head{padding:18px 22px;background:var(--bg);color:#fff;display:flex;justify-content:space-between;align-items:center}.mock-head small{color:#cbd5e1}.mock-body{padding:18px;background:#f8fafc}.item{display:flex;justify-content:space-between;gap:16px;background:#fff;border:1px solid var(--line);border-radius:18px;padding:16px;margin-bottom:12px}.item h3{margin:0 0 6px;font-size:16px}.item p{margin:0;color:var(--muted);font-size:14px;line-height:1.5}.price{font-weight:700;text-align:right}.chip{margin-top:10px;display:inline-block;padding:8px 12px;border-radius:999px;background:#ecfeff;color:#0f766e;font-size:12px;font-weight:700}
.section{padding:72px 0}.section h2{font-size:38px;text-align:center;margin:0 0 12px}.section .lead{text-align:center;color:var(--muted);font-size:18px;max-width:760px;margin:0 auto 34px}.grid3{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.card{background:#fff;border:1px solid var(--line);border-radius:24px;padding:26px;box-shadow:0 10px 28px rgba(15,23,42,.04)}.card h3{margin:0 0 10px}.card p{margin:0;color:var(--muted);line-height:1.65}
.steps{display:grid;grid-template-columns:repeat(3,1fr);gap:18px}.step{background:linear-gradient(180deg,#fff,#f8fafc);border:1px solid var(--line);border-radius:24px;padding:24px}.step .n{width:42px;height:42px;border-radius:50%;display:flex;align-items:center;justify-content:center;background:var(--text);color:#fff;font-weight:700;margin-bottom:12px}
.pricing{display:grid;grid-template-columns:repeat(2,1fr);gap:22px;max-width:920px;margin:0 auto}.plan{border-radius:28px;padding:30px;border:1px solid var(--line);background:#fff}.plan.featured{background:var(--bg);color:#fff;border-color:transparent;box-shadow:0 22px 50px rgba(15,23,42,.18)}.plan .tag{font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:.08em}.plan.featured .tag{color:#99f6e4}.plan h3{font-size:34px;margin:10px 0 6px}.plan p{color:var(--muted)}.plan.featured p,.plan.featured li{color:#dbe4ee}.plan ul{padding-left:18px;line-height:1.9}.faq{max-width:900px;margin:0 auto}.faq-item{background:#fff;border:1px solid var(--line);border-radius:20px;padding:18px 20px;margin-bottom:14px}.faq-item h4{margin:0 0 8px}.faq-item p{margin:0;color:var(--muted);line-height:1.7}
.cta{padding:20px 0 80px}.cta-box{background:linear-gradient(135deg,#0f766e,#06b6d4);padding:42px;border-radius:32px;color:#fff;text-align:center;box-shadow:0 20px 50px rgba(15,118,110,.24)}.cta-box h2{margin:0 0 12px;font-size:40px}.cta-box p{margin:0 auto 24px;max-width:760px;font-size:18px;color:#ecfeff}.footer{padding:24px 0;border-top:1px solid var(--line);font-size:14px;color:var(--muted)}
@media (max-width: 980px){.hero-grid,.grid3,.steps,.pricing{grid-template-columns:1fr}.hero h1{font-size:40px}.metrics{grid-template-columns:1fr}.nav{padding:10px 0}.navlinks{justify-content:flex-end}}
@media (max-width: 640px){.nav{flex-direction:column;align-items:flex-start}.hero{padding:52px 0}.hero h1{font-size:34px}.section h2,.cta-box h2{font-size:30px}.btn{width:100%}}
</style>
</head>
<body>
<header class="top">
  <div class="wrap nav">
    <div class="brand"><strong>Nexa Próspero</strong><span>Catálogo digital, pedidos e delivery para o seu negócio</span></div>
    <nav class="navlinks">
      <a href="#recursos">Recursos</a>
      <a href="#como-funciona">Como funciona</a>
      <a href="#planos">Planos</a>
      <a href="#faq">FAQ</a>
      <a class="btn btn-light" href="<?= e($base) ?>/saas/login.php">Entrar na Central</a>
      <a class="btn btn-primary" href="<?= e($base) ?>/escolher_plano.php">Criar minha empresa</a>
    </nav>
  </div>
</header>

<section class="hero">
  <div class="wrap hero-grid">
    <div>
      <span class="badge">SaaS para catálogo online, pedidos e delivery multiempresa</span>
      <h1>Crie sua loja online em minutos e receba pedidos com um link próprio.</h1>
      <p>Organize seus produtos, destaque promoções, receba pedidos e tenha uma presença digital profissional sem complicação. Ideal para pizzarias, lanchonetes, restaurantes, padarias e negócios locais.</p>
      <div class="hero-actions">
        <a class="btn btn-primary" href="<?= e($base) ?>/escolher_plano.php">Criar minha empresa agora</a>
        <a class="btn btn-light" href="<?= e($base) ?>/saas/login.php">Acessar Central SaaS</a>
      </div>
      <div class="metrics">
        <div class="metric"><strong>1 link</strong><span>para divulgar sua loja</span></div>
        <div class="metric"><strong>24/7</strong><span>catálogo sempre disponível</span></div>
        <div class="metric"><strong>+ controle</strong><span>pedidos e operação em um painel simples</span></div>
      </div>
    </div>
    <div class="mock">
      <div class="mock-head"><div><strong>Minha Pizzaria</strong><br><small>minhaloja.nexaprospero.com.br</small></div><span class="chip">Loja ativa</span></div>
      <div class="mock-body">
        <div class="item"><div><h3>Pizza Frango com Catupiry</h3><p>Catálogo bonito, rápido e fácil para vender mais.</p></div><div class="price">R$ 59,90</div></div>
        <div class="item"><div><h3>Pizza Três Queijos</h3><p>Produtos organizados, fotos e preços atualizados.</p></div><div class="price">R$ 59,90</div></div>
        <div class="item"><div><h3>Prato Executivo</h3><p>Receba pedidos online com mais praticidade.</p></div><div class="price">R$ 39,90</div></div>
      </div>
    </div>
  </div>
</section>

<section id="recursos" class="section">
  <div class="wrap">
    <h2>Tudo o que você precisa para vender mais</h2>
    <p class="lead">Uma estrutura pensada para apresentar produtos com qualidade, receber pedidos e administrar várias empresas em uma única central.</p>
    <div class="grid3">
      <div class="card"><h3>Catálogo online</h3><p>Produtos organizados por categorias, fotos, descrições e preços atualizados para facilitar a decisão do cliente.</p></div>
      <div class="card"><h3>Pedidos rápidos</h3><p>Receba pedidos com mais agilidade e acompanhe cada etapa com um fluxo claro para sua operação.</p></div>
      <div class="card"><h3>Subdomínio próprio</h3><p>Cada empresa pode ter seu próprio link, fortalecendo a marca e facilitando a divulgação.</p></div>
      <div class="card"><h3>Painel administrativo</h3><p>Gerencie produtos, pedidos, banners, horários, taxas e configurações da loja em um só lugar.</p></div>
      <div class="card"><h3>Central SaaS</h3><p>Gerencie várias empresas com uma visão única e uma base preparada para escalar a operação.</p></div>
      <div class="card"><h3>Pronto para delivery</h3><p>Estrutura pronta para catálogos com retirada, entrega e operação comercial organizada.</p></div>
    </div>
  </div>
</section>

<section id="como-funciona" class="section" style="background:#f8fafc">
  <div class="wrap">
    <h2>Como funciona</h2>
    <p class="lead">Um processo simples para colocar sua empresa no ar e começar a receber pedidos.</p>
    <div class="steps">
      <div class="step"><div class="n">1</div><h3>Cadastre sua empresa</h3><p>Preencha os dados básicos e gere sua estrutura inicial dentro da plataforma.</p></div>
      <div class="step"><div class="n">2</div><h3>Ative seu catálogo</h3><p>Cadastre produtos, categorias, imagens e personalize sua identidade visual.</p></div>
      <div class="step"><div class="n">3</div><h3>Comece a vender</h3><p>Divulgue seu link, receba pedidos e gerencie tudo pelo painel administrativo.</p></div>
    </div>
  </div>
</section>

<section id="planos" class="section">
  <div class="wrap">
    <h2>Planos simples para começar</h2>
    <p class="lead">Comece rápido e evolua sua operação com uma plataforma pensada para negócios locais.</p>
    <div class="pricing">
      <div class="plan"><div class="tag">Trial</div><h3>R$ 0</h3><p>Ideal para testar a experiência, montar seu catálogo e conhecer o sistema.</p><ul><li>Catálogo online</li><li>Cadastro de produtos</li><li>Link próprio da loja</li><li>Painel administrativo</li></ul><a class="btn btn-primary" href="<?= e($base) ?>/escolher_plano.php">Começar grátis</a></div>
      <div class="plan featured"><div class="tag">Mais escolhido</div><h3>Plano Profissional</h3><p>Perfeito para empresas com operação ativa que precisam de mais controle e escala.</p><ul><li>Catálogo profissional</li><li>Gestão de pedidos</li><li>Marca própria da loja</li><li>Central pronta para expansão</li></ul><a class="btn btn-accent" href="<?= e($base) ?>/escolher_plano.php">Quero criar minha empresa</a></div>
    </div>
  </div>
</section>

<section id="faq" class="section" style="background:#f8fafc">
  <div class="wrap faq">
    <h2>Perguntas frequentes</h2>
    <div class="faq-item"><h4>Preciso instalar algo?</h4><p>Não. O sistema funciona online e você acessa pelo navegador.</p></div>
    <div class="faq-item"><h4>Minha empresa terá um link próprio?</h4><p>Sim. Cada empresa pode usar um subdomínio próprio dentro da plataforma.</p></div>
    <div class="faq-item"><h4>Posso cadastrar produtos e categorias?</h4><p>Sim. O painel permite organizar o catálogo com praticidade.</p></div>
    <div class="faq-item"><h4>Funciona para delivery?</h4><p>Sim. A estrutura foi pensada para catálogo, pedidos e operações de entrega.</p></div>
  </div>
</section>

<section class="cta">
  <div class="wrap">
    <div class="cta-box">
      <h2>Pronto para colocar sua loja online?</h2>
      <p>Crie sua empresa agora, gere seu link próprio e comece a divulgar um catálogo profissional com pedidos online.</p>
      <div class="hero-actions" style="justify-content:center">
        <a class="btn btn-light" href="<?= e($base) ?>/escolher_plano.php">Criar minha empresa</a>
        <a class="btn btn-primary" style="background:#111827" href="<?= e($base) ?>/saas/login.php">Entrar na Central SaaS</a>
      </div>
    </div>
  </div>
</section>

<footer class="footer"><div class="wrap">© 2026 Nexa Próspero. Todos os direitos reservados.</div></footer>
</body>
</html>
