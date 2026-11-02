<?php
declare(strict_types=1);

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/public_companies.php';

$baseDomain = configured_root_domains()[0] ?? current_host_name();
$baseUrl = current_scheme() . '://' . $baseDomain;
$landingUrl = $baseUrl . '/landing_home.php';
if (!is_file(__DIR__ . '/landing_home.php')) {
    $landingUrl = $baseUrl;
}
$mainSiteUrl = rtrim((string)(getenv('NEXA_MAIN_SITE_URL') ?: 'https://nexaprospero.com.br/'), '/') . '/';

$q = trim((string)($_GET['q'] ?? ''));
$companies = [];
$stats = [
    'empresas_ativas' => 0,
    'pedidos_total' => 0,
    'subdominios_ativos' => 0,
];

try {
    $companies = public_companies_fetch($pdo);
    $stats = public_companies_stats($pdo);
} catch (Throwable $e) {
    $companies = [];
}

if ($q !== '') {
    $needle = mb_strtolower($q);
    $companies = array_values(array_filter($companies, static function (array $co) use ($needle): bool {
        $hay = mb_strtolower(trim((string)(
            ($co['catalog_title'] ?? '') . ' ' .
            ($co['catalog_subtitle'] ?? '') . ' ' .
            ($co['subdomain'] ?? '') . ' ' .
            ($co['custom_domain'] ?? '')
        )));
        return str_contains($hay, $needle);
    }));
}

$totalFiltered = count($companies);

function public_company_domain_label(array $company): string
{
    $url = trim((string)($company['catalog_url'] ?? ''));
    if ($url !== '') {
        $host = (string)(parse_url($url, PHP_URL_HOST) ?: '');
        if ($host !== '') {
            return $host;
        }
    }

    $custom = trim((string)($company['custom_domain'] ?? ''));
    if ($custom !== '') {
        return $custom;
    }

    return trim((string)($company['subdomain'] ?? ''));
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Catálogos em funcionamento — Nexa Próspero</title>
  <meta name="description" content="Veja empresas ativas e acesse diretamente os catálogos criados na plataforma Nexa Próspero.">
  <style>
    :root{
      --primary-dark:#0b1f5b;
      --primary:#2454d6;
      --primary-2:#007bff;
      --cyan:#00b8ff;
      --ink:#0b1526;
      --text:#1d2433;
      --muted:#66758a;
      --bg:#f3f7fc;
      --surface:#ffffff;
      --line:#d9e5f5;
      --soft:#eef5ff;
      --shadow:0 24px 64px rgba(11,31,91,.11);
      --shadow-soft:0 14px 36px rgba(11,31,91,.08);
      --radius-xl:34px;
      --radius-lg:26px;
      --radius-md:20px;
      --max:1200px;
    }
    *{box-sizing:border-box}
    html{scroll-behavior:smooth}
    body{
      margin:0;
      font-family:Inter,system-ui,-apple-system,"Segoe UI",Arial,sans-serif;
      color:var(--text);
      background:
        radial-gradient(circle at 0% 0%, rgba(0,184,255,.12), transparent 28%),
        radial-gradient(circle at 100% 0%, rgba(36,84,214,.14), transparent 32%),
        linear-gradient(180deg,#f9fbff 0%, var(--bg) 58%, #edf4fc 100%);
    }
    a{text-decoration:none;color:inherit}
    img{max-width:100%;display:block}
    .wrap{max-width:var(--max);margin:0 auto;padding:0 20px}
    .topbar{
      position:sticky;top:0;z-index:30;
      background:rgba(255,255,255,.78);
      backdrop-filter:blur(14px);
      border-bottom:1px solid rgba(217,229,245,.95);
    }
    .topbar .wrap{display:flex;align-items:center;justify-content:space-between;gap:16px;padding:14px 20px}
    .brand{
      display:flex;align-items:center;gap:14px;min-width:0;
      padding:6px 10px 6px 6px;border-radius:22px;transition:all .22s ease;
    }
    .brand:hover{background:rgba(255,255,255,.85);box-shadow:var(--shadow-soft);transform:translateY(-1px)}
    .brand img{
      width:70px;height:52px;object-fit:contain;background:#fff;border-radius:18px;
      padding:6px;border:1px solid var(--line);box-shadow:0 12px 26px rgba(11,31,91,.10)
    }
    .brand strong{display:block;font-size:21px;line-height:1.05;letter-spacing:-.03em;color:var(--primary-dark)}
    .brand span{display:block;font-size:13px;color:var(--muted);margin-top:4px}
    .nav{display:flex;align-items:center;gap:10px;flex-wrap:wrap}
    .nav .link{padding:10px 14px;border-radius:14px;font-size:14px;color:#456}
    .nav .link:hover{background:#fff}
    .btn{
      display:inline-flex;align-items:center;justify-content:center;gap:8px;
      padding:13px 20px;border-radius:999px;border:1px solid transparent;
      font-size:14px;font-weight:850;cursor:pointer;transition:all .2s ease;
    }
    .btn:hover{transform:translateY(-1px)}
    .btn-primary{background:linear-gradient(135deg,var(--primary),var(--cyan));color:#fff;box-shadow:0 16px 34px rgba(0,184,255,.24)}
    .btn-outline{background:#fff;border-color:var(--line);color:var(--primary-dark);box-shadow:0 10px 24px rgba(11,31,91,.06)}
    .btn-soft{background:rgba(255,255,255,.85);border-color:rgba(255,255,255,.22);color:#fff}

    .hero{padding:38px 0 24px}
    .hero-shell{
      position:relative;overflow:hidden;
      display:grid;grid-template-columns:1.2fr .8fr;gap:22px;align-items:stretch;
      background:
        radial-gradient(circle at 86% 16%, rgba(0,184,255,.34), transparent 28%),
        radial-gradient(circle at 8% 96%, rgba(36,84,214,.34), transparent 30%),
        linear-gradient(135deg,#081b4b 0%, #113f90 54%, #0f7887 100%);
      color:#fff;border-radius:36px;padding:34px;box-shadow:var(--shadow);
    }
    .hero-shell:after{
      content:"";position:absolute;right:-80px;bottom:-90px;width:280px;height:280px;
      border-radius:50%;background:rgba(255,255,255,.08)
    }
    .hero-content,.hero-side{position:relative;z-index:1}
    .pill{
      display:inline-flex;align-items:center;gap:8px;padding:9px 14px;border-radius:999px;
      background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);
      color:#e9f7ff;font-size:12px;font-weight:900;letter-spacing:.08em;text-transform:uppercase
    }
    .hero h1{margin:16px 0 0;font-size:clamp(34px,5vw,60px);line-height:.98;letter-spacing:-.055em;max-width:720px}
    .hero p{margin:18px 0 0;max-width:720px;font-size:17px;line-height:1.7;color:#dcecff}
    .hero-actions{display:flex;gap:12px;flex-wrap:wrap;margin-top:24px}
    .hero-side{
      display:grid;grid-template-columns:1fr;gap:14px;
    }
    .stat-card{
      background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);
      border-radius:24px;padding:18px 18px 16px;backdrop-filter:blur(10px)
    }
    .stat-card strong{display:block;font-size:30px;letter-spacing:-.04em}
    .stat-card span{display:block;margin-top:6px;font-size:14px;color:#e5f4ff;line-height:1.45}

    .search-section{padding:22px 0 10px}
    .search-panel{
      display:flex;align-items:center;gap:14px;flex-wrap:wrap;
      background:rgba(255,255,255,.92);border:1px solid var(--line);
      border-radius:28px;padding:16px;box-shadow:var(--shadow-soft)
    }
    .search-icon{
      width:48px;height:48px;border-radius:16px;display:grid;place-items:center;
      background:linear-gradient(135deg,rgba(36,84,214,.12),rgba(0,184,255,.18));
      color:var(--primary-dark);font-size:20px;font-weight:900
    }
    .search-copy{min-width:220px}
    .search-copy strong{display:block;color:var(--primary-dark);font-size:16px}
    .search-copy span{display:block;color:var(--muted);font-size:13px;margin-top:4px}
    .search-form{display:flex;align-items:center;gap:12px;flex:1;min-width:260px}
    .search-form input{
      flex:1;border:1px solid var(--line);background:#fff;border-radius:18px;
      padding:15px 16px;font-size:15px;outline:none;color:var(--text)
    }
    .search-form input:focus{border-color:rgba(0,184,255,.7);box-shadow:0 0 0 4px rgba(0,184,255,.12)}
    .result-meta{display:flex;justify-content:space-between;gap:12px;align-items:center;flex-wrap:wrap;margin-top:18px;color:var(--muted);font-size:14px}
    .result-chip{display:inline-flex;align-items:center;gap:8px;padding:10px 14px;border-radius:999px;background:#fff;border:1px solid var(--line);color:var(--primary-dark);font-weight:800}

    .catalog-section{padding:12px 0 28px}
    .catalog-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:20px}
    .catalog-card{
      background:rgba(255,255,255,.94);border:1px solid var(--line);border-radius:30px;
      overflow:hidden;box-shadow:0 16px 38px rgba(11,31,91,.07);transition:all .22s ease
    }
    .catalog-card:hover{transform:translateY(-4px);box-shadow:0 24px 54px rgba(36,84,214,.14);border-color:rgba(0,184,255,.42)}
    .catalog-cover{height:170px;position:relative;background:linear-gradient(135deg,#dbeaff,#f2fbff 58%,#ecf1ff 100%)}
    .catalog-cover > img{width:100%;height:100%;object-fit:cover}
    .catalog-cover-fallback{position:absolute;inset:0;background:linear-gradient(135deg,#dce9ff,#f1f7ff 50%,#dff8ff 100%)}
    .catalog-logo{
      position:absolute;left:20px;bottom:-28px;width:88px;height:88px;border-radius:28px;
      background:#fff;border:1px solid #e6eef9;display:grid;place-items:center;padding:10px;
      box-shadow:0 18px 34px rgba(11,31,91,.14)
    }
    .catalog-logo img{width:100%;height:100%;object-fit:contain}
    .catalog-body{padding:42px 22px 22px}
    .catalog-body h3{margin:0;font-size:24px;line-height:1.1;letter-spacing:-.04em;color:var(--ink)}
    .catalog-body p{margin:12px 0 0;color:var(--muted);font-size:14px;line-height:1.62;min-height:44px}
    .domain-line{
      display:flex;align-items:center;gap:8px;margin-top:14px;padding:10px 12px;border-radius:16px;
      background:var(--soft);border:1px solid #dbe8fb;color:var(--primary-dark);font-size:13px;font-weight:800;
      word-break:break-all
    }
    .domain-line .dot{width:10px;height:10px;border-radius:50%;background:linear-gradient(135deg,var(--primary),var(--cyan));flex:0 0 auto}
    .catalog-meta{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}
    .catalog-meta span{display:inline-flex;align-items:center;gap:6px;padding:8px 10px;border-radius:999px;background:#fff;border:1px solid var(--line);color:#516277;font-size:12px;font-weight:800}
    .catalog-actions{display:flex;gap:10px;margin-top:18px}
    .catalog-actions .btn{flex:1}

    .empty-state{padding:28px;border-radius:26px;background:#fff;border:1px solid var(--line);box-shadow:var(--shadow-soft);text-align:center;color:var(--muted)}
    .footer{margin-top:24px;border-top:1px solid rgba(217,229,245,.95);background:rgba(255,255,255,.84)}
    .footer .wrap{display:flex;justify-content:space-between;align-items:center;gap:18px;flex-wrap:wrap;padding:22px 20px;color:var(--muted);font-size:14px}
    .foot-links{display:flex;gap:14px;flex-wrap:wrap}

    @media (max-width:1080px){
      .hero-shell,.catalog-grid{grid-template-columns:1fr 1fr}
      .hero-shell{grid-template-columns:1fr}
      .hero-side{grid-template-columns:repeat(3,1fr)}
    }
    @media (max-width:860px){
      .topbar .wrap,.footer .wrap{flex-direction:column;align-items:flex-start}
      .catalog-grid,.hero-side{grid-template-columns:1fr}
    }
    @media (max-width:640px){
      .wrap{padding:0 16px}
      .topbar .wrap,.footer .wrap{padding-left:16px;padding-right:16px}
      .hero{padding-top:24px}
      .hero-shell{padding:24px;border-radius:28px}
      .search-panel{padding:14px;border-radius:22px}
      .search-form{flex-direction:column;align-items:stretch}
      .search-form .btn{width:100%}
      .catalog-card{border-radius:24px}
      .catalog-actions{flex-direction:column}
      .brand img{width:64px;height:48px}
      .brand strong{font-size:19px}
      .hero h1{font-size:38px}
    }
  </style>
</head>
<body>
  <header class="topbar">
    <div class="wrap">
      <a class="brand" href="<?= e($mainSiteUrl) ?>">
        <img src="/assets/logo-nexa-prospero.jpg" alt="Nexa Próspero">
        <div>
          <strong>Nexa Próspero</strong>
          <span>Vitrine pública de empresas e catálogos ativos</span>
        </div>
      </a>
      <nav class="nav">
        <a class="link" href="/escolher_plano.php">Planos</a>
        <a class="link" href="/cadastro.php">Cadastrar empresa</a>
        <a class="btn btn-outline" href="<?= e($mainSiteUrl) ?>">Voltar para o site principal</a>
      </nav>
    </div>
  </header>

  <main>
    <section class="hero">
      <div class="wrap">
        <div class="hero-shell">
          <div class="hero-content">
            <span class="pill">Catálogos em funcionamento</span>
            <h1>Escolha uma empresa e entre direto no catálogo.</h1>
            <p>Use esta vitrine para divulgar a plataforma, mostrar empresas ativas e facilitar o acesso dos clientes aos catálogos publicados na Nexa Próspero. Uma página mais profissional também gera mais confiança e prova social para o seu negócio.</p>
            <div class="hero-actions">
              <a class="btn btn-primary" href="#empresas">Ver empresas</a>
              <a class="btn btn-soft" href="/escolher_plano.php">Quero contratar</a>
            </div>
          </div>

          <aside class="hero-side">
            <div class="stat-card">
              <strong><?= e(number_format((int)($stats['empresas_ativas'] ?? 0), 0, ',', '.')) ?></strong>
              <span>empresas ativas na plataforma</span>
            </div>
            <div class="stat-card">
              <strong><?= e(number_format((int)($stats['pedidos_total'] ?? 0), 0, ',', '.')) ?></strong>
              <span>pedidos registrados no ambiente</span>
            </div>
            <div class="stat-card">
              <strong><?= e(number_format((int)($stats['subdominios_ativos'] ?? 0), 0, ',', '.')) ?></strong>
              <span>subdomínios e endereços publicados</span>
            </div>
          </aside>
        </div>
      </div>
    </section>

    <section class="search-section" id="empresas">
      <div class="wrap">
        <div class="search-panel">
          <div class="search-icon">⌕</div>
          <div class="search-copy">
            <strong>Encontre uma empresa rapidamente</strong>
            <span>Busque por nome, segmento, subdomínio ou endereço do catálogo.</span>
          </div>
          <form class="search-form" method="get">
            <input type="text" name="q" value="<?= e($q) ?>" placeholder="Ex.: pizzaria, empresa-padrao ou nexaprospero.com.br">
            <button class="btn btn-primary" type="submit">Buscar</button>
            <?php if ($q !== ''): ?>
              <a class="btn btn-outline" href="/cardapios.php">Limpar</a>
            <?php endif; ?>
          </form>
        </div>
        <div class="result-meta">
          <span class="result-chip"><?= e(number_format($totalFiltered, 0, ',', '.')) ?> empresa(s) exibida(s)</span>
          <?php if ($q !== ''): ?>
            <span>Resultado para: <strong><?= e($q) ?></strong></span>
          <?php else: ?>
            <span>Explore os catálogos publicados e entre direto na empresa desejada.</span>
          <?php endif; ?>
        </div>
      </div>
    </section>

    <section class="catalog-section">
      <div class="wrap">
        <?php if (!$companies): ?>
          <div class="empty-state">Nenhuma empresa encontrada para essa busca. Tente outro nome, segmento ou subdomínio.</div>
        <?php else: ?>
          <div class="catalog-grid">
            <?php foreach ($companies as $co):
                $title = trim((string)($co['catalog_title'] ?? 'Empresa'));
                $subtitle = trim((string)($co['catalog_subtitle'] ?? ''));
                $domainLabel = public_company_domain_label($co);
            ?>
              <article class="catalog-card">
                <div class="catalog-cover">
                  <?php if (($co['cover_url'] ?? '') !== ''): ?>
                    <img src="<?= e((string)$co['cover_url']) ?>" alt="Capa de <?= e($title) ?>">
                  <?php else: ?>
                    <div class="catalog-cover-fallback"></div>
                  <?php endif; ?>

                  <div class="catalog-logo">
                    <?php if (($co['logo_url'] ?? '') !== ''): ?>
                      <img src="<?= e((string)$co['logo_url']) ?>" alt="Logo de <?= e($title) ?>">
                    <?php else: ?>
                      <span style="font-weight:900;font-size:28px;color:var(--primary-dark);">
                        <?= e(mb_strtoupper(mb_substr($title, 0, 1))) ?>
                      </span>
                    <?php endif; ?>
                  </div>
                </div>

                <div class="catalog-body">
                  <h3><?= e($title) ?></h3>
                  <p><?= e($subtitle !== '' ? $subtitle : 'Catálogo disponível para consulta online com acesso rápido para seus clientes.') ?></p>

                  <?php if ($domainLabel !== ''): ?>
                    <div class="domain-line">
                      <span class="dot"></span>
                      <span><?= e($domainLabel) ?></span>
                    </div>
                  <?php endif; ?>

                  <div class="catalog-meta">
                    <span>📦 Catálogo online</span>
                    <?php if (!empty($co['subdomain'])): ?>
                      <span>🌐 Subdomínio ativo</span>
                    <?php endif; ?>
                  </div>

                  <div class="catalog-actions">
                    <a class="btn btn-primary" href="<?= e((string)$co['catalog_url']) ?>" target="_blank" rel="noopener">Acessar catálogo</a>
                  </div>
                </div>
              </article>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </section>
  </main>

  <footer class="footer">
    <div class="wrap">
      <div>© 2026 Nexa Próspero. Vitrine pública de empresas ativas e catálogos online.</div>
      <div class="foot-links">
        <a href="<?= e($mainSiteUrl) ?>">Site principal</a>
        <a href="/escolher_plano.php">Planos</a>
        <a href="/cadastro.php">Cadastrar empresa</a>
      </div>
    </div>
  </footer>
</body>
</html>
