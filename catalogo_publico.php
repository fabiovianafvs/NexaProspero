<?php
declare(strict_types=1);

// A Central do Contador é um portal geral e não pertence a uma empresa/tenant.
// Esta verificação precisa ocorrer antes de carregar o resolvedor de empresas,
// pois o domínio principal encaminha rotas públicas para este arquivo.
$publicPath = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
$publicParts = array_values(array_filter(explode('/', $publicPath), static fn(string $part): bool => $part !== ''));
if (strtolower((string)($publicParts[0] ?? '')) === 'contadorprospero') {
  $requestedPage = strtolower((string)($publicParts[1] ?? 'index.php'));
  if ($requestedPage === '') $requestedPage = 'index.php';
  if (!str_ends_with($requestedPage, '.php')) $requestedPage .= '.php';
  $allowedPages = ['index.php','login.php','cadastro.php','logout.php','download.php'];
  if (!in_array($requestedPage, $allowedPages, true)) {
    http_response_code(404);
    exit('Página não encontrada.');
  }
  require __DIR__ . '/contadorprospero/' . $requestedPage;
  exit;
}

require_once __DIR__ . '/config/helpers.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/tenant.php';
require_once __DIR__ . '/config/load_config.php';
require_once __DIR__ . '/saas/saas_guard.php';
require_once __DIR__ . '/config/product_options.php';

// Proxy seguro para imagens do catálogo público.
// Necessário quando o domínio principal usa /var/www/nexa-landing e os uploads reais ficam em /var/www/nexa/uploads.
if (isset($_GET['img'])) {
  $img = basename((string)$_GET['img']);
  $img = preg_replace('/[^a-zA-Z0-9._-]+/', '', $img) ?? '';
  if ($img === '') {
    http_response_code(404);
    exit;
  }

  $paths = [
    __DIR__ . '/uploads/products/' . $img,
    __DIR__ . '/uploads/' . $img,
    __DIR__ . '/assets/' . $img,
  ];

  $file = '';
  foreach ($paths as $candidate) {
    if (is_file($candidate)) {
      $file = $candidate;
      break;
    }
  }

  if ($file === '') {
    $file = __DIR__ . '/assets/placeholder.svg';
  }

  if (!is_file($file)) {
    http_response_code(404);
    exit;
  }

  $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
  $types = [
    'jpg' => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'png' => 'image/png',
    'webp' => 'image/webp',
    'gif' => 'image/gif',
    'svg' => 'image/svg+xml',
  ];
  header('Content-Type: ' . ($types[$ext] ?? 'application/octet-stream'));
  header('Cache-Control: public, max-age=86400');
  readfile($file);
  exit;
}

$slug = trim((string)($_GET['empresa'] ?? $_GET['company'] ?? $_GET['slug'] ?? ''));
if ($slug === '') {
  $path = trim((string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?: ''), '/');
  $parts = array_values(array_filter(explode('/', $path)));
  $slug = (string)($parts[0] ?? '');
}
$slug = strtolower(preg_replace('/[^a-z0-9-]+/i', '', $slug) ?? '');

if ($slug === '' || in_array($slug, ['admin','api','assets','uploads','saas','config','db','landing','cardapios','cadastro','escolher-plano'], true)) {
  http_response_code(404);
  echo 'Catálogo não encontrado.';
  exit;
}

try {
  $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? OR subdomain = ? LIMIT 1');
  $st->execute([$slug, $slug]);
  $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (Throwable $e) {
  $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
  $st->execute([$slug]);
  $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (!$company) {
  http_response_code(404);
  echo 'Empresa não encontrada.';
  exit;
}

saas_enforce_company_access($pdo, $company, 'catalog');

define('COMPANY_ID', (int)$company['id']);
define('COMPANY_SLUG', (string)$company['slug']);
define('COMPANY_SUBDOMAIN', (string)($company['subdomain'] ?? ''));
define('COMPANY_CUSTOM_DOMAIN', (string)($company['custom_domain'] ?? ''));

$app = load_app_config(COMPANY_ID);
$biz = load_business_config(COMPANY_ID);
require_once __DIR__ . '/config/products_repository.php';
products_send_dynamic_catalog_headers();
app_apply_company_timezone($pdo, (string)($biz['timezone'] ?? 'America/Sao_Paulo'));

$brand = (array)($biz['brand'] ?? []);
$catalogTitle = trim((string)($brand['catalog_title'] ?? ''));
if ($catalogTitle === '') $catalogTitle = (string)($company['name'] ?? 'Catálogo');
$catalogSubtitle = trim((string)($brand['catalog_subtitle'] ?? ''));
if ($catalogSubtitle === '') $catalogSubtitle = 'Conheça nossos produtos.';
$app['site_name'] = $catalogTitle;

$primary = trim((string)($brand['primary_color'] ?? '#0f766e'));
$secondary = trim((string)($brand['secondary_color'] ?? '#14b8a6'));
$accent = trim((string)($brand['accent_color'] ?? '#f59e0b'));
$textOnPrimary = trim((string)($brand['text_on_primary'] ?? '#ffffff'));

$catalogUi = (array)($biz['catalog_ui'] ?? []);
$horizontalCategoryMenu = !empty($catalogUi['horizontal_category_menu']);
$productOptionsCfg = (array)($biz['product_options'] ?? []);
$showAdditionalUnitTotal = !empty($productOptionsCfg['show_additional_unit_total']);

$q = trim((string)($_GET['q'] ?? ''));
$items = products_search_db($q);
$items = products_filter_visible_catalog_categories_db($items, 'product');

$groups = [];
$categoryThumbs = [];
foreach ($items as $p) {
  $cat = trim((string)($p['categoria'] ?? ''));
  if ($cat === '') $cat = 'Outros';
  $groups[$cat] ??= [];
  $groups[$cat][] = $p;

  if (!isset($categoryThumbs[$cat])) {
    $img = trim((string)($p['imagem'] ?? ''));
    if ($img !== '') $categoryThumbs[$cat] = $img;
  }
}

$mainSiteUrl = rtrim((string)(getenv('NEXA_MAIN_SITE_URL') ?: 'https://nexaprospero.com.br/'), '/') . '/';
$publicCatalogUrl = company_public_catalog_url($company);
$stStatus = store_status($biz);
$logoFile = trim((string)($biz['logo']['file'] ?? ''));
$logoUrl = ($logoFile !== '' && !empty($biz['logo']['enabled'])) ? ('/uploads/logos/' . rawurlencode($logoFile)) : '';
$coverFile = trim((string)($biz['cover']['file'] ?? ''));
$coverUrl = ($coverFile !== '' && !empty($biz['cover']['enabled'])) ? ('/uploads/covers/' . rawurlencode($coverFile)) : '';

function public_catalog_product_image_url(array $product, array $company): string {
  $filename = trim((string)(($product['imagem'] ?? '') ?: 'placeholder.svg'));
  $filename = basename($filename);
  $slug = trim((string)($company['slug'] ?? ($_GET['empresa'] ?? '')));
  $script = (string)($_SERVER['SCRIPT_NAME'] ?? '/catalogo_publico.php');
  if ($script === '') $script = '/catalogo_publico.php';
  return $script . '?empresa=' . rawurlencode($slug) . '&img=' . rawurlencode($filename);
}

function public_catalog_category_slug(string $cat): string {
  return 'cat-' . preg_replace('/[^a-z0-9]+/i', '-', mb_strtolower($cat));
}

function public_catalog_options_map(PDO $pdo, int $companyId, array $products): array {
  $ids = [];
  foreach ($products as $p) {
    $id = (int)($p['id'] ?? 0);
    if ($id > 0) $ids[$id] = $id;
  }
  $ids = array_values($ids);
  if (!$ids) return [];

  try {
    product_options_ensure_schema($pdo);

    $in = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    $st = $pdo->prepare("SELECT * FROM produto_opcao_grupos WHERE company_id = ? AND produto_id IN ({$in}) AND ativo = 1 ORDER BY produto_id ASC, sort_order ASC, id ASC");
    $st->execute($params);
    $groups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$groups) return [];

    $groupIds = [];
    foreach ($groups as $g) {
      $gid = (int)($g['id'] ?? 0);
      if ($gid > 0) $groupIds[$gid] = $gid;
    }
    if (!$groupIds) return [];

    $groupIds = array_values($groupIds);
    $inGroups = implode(',', array_fill(0, count($groupIds), '?'));
    $paramsItems = array_merge([$companyId], $groupIds);
    $sti = $pdo->prepare("SELECT * FROM produto_opcao_itens WHERE company_id = ? AND grupo_id IN ({$inGroups}) AND ativo = 1 ORDER BY grupo_id ASC, sort_order ASC, id ASC");
    $sti->execute($paramsItems);
    $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $itemsByGroup = [];
    foreach ($items as $it) {
      $gid = (int)($it['grupo_id'] ?? 0);
      if ($gid <= 0) continue;
      $itemsByGroup[$gid] ??= [];
      $itemsByGroup[$gid][] = $it;
    }

    $out = [];
    foreach ($groups as $g) {
      $gid = (int)($g['id'] ?? 0);
      $pid = (int)($g['produto_id'] ?? 0);
      if ($gid <= 0 || $pid <= 0) continue;
      $g['items'] = $itemsByGroup[$gid] ?? [];
      if (!$g['items']) continue;
      $out[$pid] ??= [];
      $out[$pid][] = $g;
    }
    return $out;
  } catch (Throwable $e) {
    return [];
  }
}

$optionsByProduct = public_catalog_options_map($pdo, COMPANY_ID, $items);
$cssFile = __DIR__ . '/assets/app.css';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($catalogTitle) ?> | Catálogo de produtos</title>
<meta name="description" content="<?= e($catalogSubtitle) ?>">
<link rel="stylesheet" href="<?= asset_url('assets/app.css?v=' . $cssVersion, $app) ?>">
<style>
:root{
  --brand-primary:<?= e($primary) ?>;
  --brand-secondary:<?= e($secondary) ?>;
  --brand-accent:<?= e($accent) ?>;
  --brand-on-primary:<?= e($textOnPrimary) ?>;
  --bg:color-mix(in srgb, var(--brand-primary) 6%, #f8fafc);
  --line:color-mix(in srgb, var(--brand-primary) 14%, rgba(2,6,23,.10));
  --accent:var(--brand-secondary);
  --brand-soft:color-mix(in srgb, var(--brand-primary) 11%, #fff);
  --brand-soft-2:color-mix(in srgb, var(--brand-secondary) 10%, #fff);
  --brand-border-soft:color-mix(in srgb, var(--brand-primary) 28%, rgba(2,6,23,.10));
}
body{background:var(--bg)}
.catalog-topbar{position:sticky;top:0;z-index:50;background:rgba(255,255,255,.88);backdrop-filter:blur(14px);border-bottom:1px solid var(--line)}
.catalog-topbar-inner{max-width:1240px;margin:0 auto;padding:10px 24px;display:flex;align-items:center;justify-content:space-between;gap:14px}
.catalog-brand-mini{display:flex;align-items:center;gap:10px;min-width:0}
.catalog-logo-mini{width:40px;height:40px;border-radius:14px;background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));display:flex;align-items:center;justify-content:center;color:var(--brand-on-primary);font-weight:950;overflow:hidden;box-shadow:0 10px 22px color-mix(in srgb, var(--brand-primary) 20%, transparent)}
.catalog-logo-mini img{width:100%;height:100%;object-fit:contain;background:#fff}
.catalog-brand-mini strong{display:block;font-size:15px;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;max-width:48vw}
.catalog-brand-mini span{display:block;font-size:12px;color:#64748b;margin-top:1px}
.catalog-layout-note{display:inline-flex;align-items:center;gap:6px;border:1px solid var(--line);background:#fff;border-radius:999px;padding:8px 11px;color:#64748b;font-weight:850;font-size:12px;white-space:nowrap}
.catalog-only .hero-banner{position:relative;overflow:hidden;border-radius:28px;min-height:220px;margin:14px 0 18px;background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));color:var(--brand-on-primary);box-shadow:0 20px 45px rgba(15,23,42,.14)}
.catalog-only .hero-banner:after{content:"";position:absolute;inset:auto -40px -40px auto;width:180px;height:180px;background:color-mix(in srgb, var(--brand-accent) 42%, transparent);filter:blur(20px);border-radius:999px;opacity:.85}
.catalog-only .hero-content{position:relative;z-index:1;padding:28px;display:flex;justify-content:space-between;gap:18px;align-items:flex-end;min-height:220px}
.catalog-only .hero-kicker{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.14);backdrop-filter:blur(6px);font-size:12px;font-weight:800;letter-spacing:.02em}
.catalog-only .hero-title{font-size:clamp(28px,4vw,42px);line-height:1.05;margin:12px 0 8px;font-weight:950;max-width:680px}
.catalog-only .hero-subtitle{max-width:680px;font-size:15px;opacity:.92;line-height:1.5}
.catalog-only .hero-card{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(10px);padding:16px 18px;border-radius:22px;min-width:240px}
.catalog-only .hero-card strong{display:block;font-size:18px;margin-bottom:0}
.catalog-only .hero-banner.has-cover{background-image:linear-gradient(135deg, color-mix(in srgb, var(--brand-primary) 76%, transparent), color-mix(in srgb, var(--brand-secondary) 64%, transparent)), url('<?= e($coverUrl) ?>');background-size:cover;background-position:center}
.catalog-only .hero-kicker,.catalog-only .hero-card{color:var(--brand-on-primary)}
.catalog-only .search{display:flex;margin:10px 0 14px;gap:10px;justify-content:stretch}
.catalog-only .search input{width:100%}
.catalog-only .cats{top:62px;z-index:40;background:linear-gradient(180deg,var(--bg),color-mix(in srgb, var(--bg) 88%, rgba(255,255,255,.86)));backdrop-filter:blur(12px)}
.catalog-only .cat-section{scroll-margin-top:132px}
.catalog-only .cats.cat-tile-menu ~ .cat-section{scroll-margin-top:220px}
.catalog-search-actions{display:flex;gap:8px;flex-wrap:wrap}

.catalog-only .cats.cat-tile-menu{display:flex;gap:12px;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;padding:12px 0 14px;margin-bottom:16px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
.catalog-only .cats.cat-tile-menu .cat-tile{flex:0 0 96px;max-width:96px;min-height:112px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:8px;padding:10px 8px;border:1px solid var(--line);border-radius:22px;background:#fff;color:#0f172a;text-align:center;box-shadow:0 10px 24px rgba(2,6,23,.07);overflow:hidden}
.catalog-only .cats.cat-tile-menu .cat-tile-media{width:70px;height:62px;min-width:70px;max-width:70px;flex:0 0 62px;border-radius:18px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--brand-soft);border:1px solid var(--brand-border-soft)}
.catalog-only .cats.cat-tile-menu .cat-tile-media img{width:100%;height:100%;max-width:100%;max-height:100%;object-fit:cover;display:block}
.catalog-only .cats.cat-tile-menu .cat-tile-label{width:100%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.12;font-size:12px;font-weight:900;color:#0f172a;word-break:break-word}
@media(max-width:520px){.catalog-only .cats.cat-tile-menu{gap:10px}.catalog-only .cats.cat-tile-menu .cat-tile{flex-basis:86px;max-width:86px;min-height:104px;border-radius:20px;padding:9px 7px}.catalog-only .cats.cat-tile-menu .cat-tile-media{width:62px;height:56px;min-width:62px;max-width:62px;flex-basis:56px;border-radius:16px}.catalog-only .cats.cat-tile-menu .cat-tile-label{font-size:11px}}
.public-product-card{font:inherit;text-align:left;width:100%;cursor:pointer;border:1px solid var(--line)}
.public-product-card .thumb img{object-fit:cover}
.public-product-card .card-body{min-height:164px;display:flex;flex-direction:column}
.public-card-footer{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:end;gap:10px;margin-top:auto;padding-top:10px;min-width:0}
.public-card-footer .muted{min-width:0;overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;line-height:1.15;word-break:break-word}
.public-view-tag{display:inline-flex;align-items:center;justify-content:center;min-width:0;max-width:100%;font-size:11px;font-weight:900;color:var(--brand-primary);background:var(--brand-soft);border:1px solid var(--brand-border-soft);border-radius:999px;padding:6px 8px;white-space:nowrap;text-align:center}
@media(max-width:520px){.public-card-footer{grid-template-columns:1fr;align-items:stretch}.public-view-tag{width:100%}}
.public-readonly-note{margin:8px 0 14px;padding:12px 14px;border:1px solid var(--line);background:#fff;border-radius:18px;color:#64748b;font-size:13px;line-height:1.4}
.public-modal{position:fixed;inset:0;z-index:99999;display:none;align-items:center;justify-content:center;padding:18px;background:rgba(15,23,42,.58)}
.public-modal.is-open{display:flex}
.public-modal-dialog{width:min(880px,100%);max-height:min(86vh,900px);overflow:auto;background:#fff;border-radius:26px;border:1px solid rgba(255,255,255,.7);box-shadow:0 26px 80px rgba(2,6,23,.28)}
.public-modal-head{position:sticky;top:0;z-index:2;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:14px 16px;border-bottom:1px solid var(--line);background:rgba(255,255,255,.94);backdrop-filter:blur(10px)}
.public-modal-head strong{font-size:16px;color:#0f172a}
.public-modal-close{width:38px;height:38px;border-radius:14px;border:1px solid var(--line);background:#fff;cursor:pointer;font-size:24px;line-height:1;color:#0f172a}
.public-modal-body{padding:16px}
.public-detail{display:grid;grid-template-columns:280px minmax(0,1fr);gap:16px}
.public-detail-img{height:240px;border-radius:22px;border:1px solid var(--line);background:#f8fafc;overflow:hidden;display:flex;align-items:center;justify-content:center}
.public-detail-img img{width:100%;height:100%;object-fit:cover}
.public-detail h2{margin:0 0 6px;font-size:24px;line-height:1.1;color:#0f172a}
.public-detail-desc{color:#64748b;font-size:14px;line-height:1.55;margin:10px 0 0}
.readonly-options-box{display:grid;gap:12px;margin-top:16px;padding:14px;border:1px solid color-mix(in srgb, var(--brand-primary) 18%, #dbeafe);background:linear-gradient(180deg,#f8fbff,#fff);border-radius:20px}
.readonly-options-title strong{display:block;color:#0b1f5b;font-size:16px}
.readonly-options-title span{display:block;color:#64748b;font-size:13px;margin-top:3px}
.readonly-option-group{border:1px solid #e5edf8;border-radius:18px;padding:12px;background:#fff}
.readonly-option-group-title{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap;margin-bottom:8px;color:#0f172a;font-weight:950}
.readonly-option-group-title small{color:#64748b;font-weight:750}
.readonly-option-list{display:grid;gap:8px}
.readonly-option-item{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 12px;border:1px solid #edf2f7;border-radius:14px;background:#fbfdff;color:#0f172a}
.readonly-option-item span{font-weight:800;min-width:0;line-height:1.25}
.readonly-option-price{display:flex;flex-direction:column;align-items:flex-end;gap:3px;flex:0 0 auto;text-align:right}
.readonly-option-price strong{font-size:13px;color:var(--brand-primary);white-space:nowrap}
.readonly-option-price small{font-size:11px;color:#64748b;font-weight:750;white-space:nowrap}
.readonly-empty{margin-top:16px;border:1px dashed var(--line);border-radius:18px;padding:14px;color:#64748b;background:#fff}
.catalog-footer{max-width:1240px;margin:30px auto 0;padding:18px 24px;color:#64748b;font-size:12px;display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}
@media(max-width:760px){
  .catalog-topbar-inner{padding:9px 16px}.catalog-layout-note{display:none}.catalog-only.container{padding:16px}.catalog-only .hero-banner{border-radius:24px;min-height:auto}.catalog-only .hero-content{padding:22px;align-items:flex-start;flex-direction:column;min-height:0}.catalog-only .hero-card{width:100%;min-width:0}.catalog-only .cats{top:58px}.catalog-only .cat-section{scroll-margin-top:124px}.catalog-only .cats.cat-tile-menu ~ .cat-section{scroll-margin-top:195px}.catalog-search-actions .btn{flex:1}.public-detail{grid-template-columns:1fr}.public-detail-img{height:210px}.public-modal{padding:8px;align-items:flex-end}.public-modal-dialog{max-height:92vh;border-radius:24px 24px 0 0}.public-modal-body{padding:14px}.catalog-brand-mini strong{max-width:70vw}
}
</style>
</head>
<body id="catalogo-topo">
<header class="catalog-topbar">
  <div class="catalog-topbar-inner">
    <a class="catalog-brand-mini" href="#catalogo-topo" title="Voltar ao início do catálogo">
      <span class="catalog-logo-mini">
        <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="Logo da empresa"><?php else: ?><?= e(mb_strtoupper(mb_substr($catalogTitle, 0, 1))) ?><?php endif; ?>
      </span>
      <span>
        <strong><?= e($catalogTitle) ?></strong>
        <span>Catálogo de produtos</span>
      </span>
    </a>
    <span class="catalog-layout-note">Somente visualização</span>
  </div>
</header>

<main class="container catalog-only">
  <section class="hero-banner<?= $coverUrl !== '' ? ' has-cover' : '' ?>">
    <div class="hero-content">
      <div>
        <div class="hero-kicker">✨ Catálogo da empresa</div>
        <div class="hero-title"><?= e($catalogTitle) ?></div>
        <div class="hero-subtitle"><?= e($catalogSubtitle) ?></div>
      </div>
      <div class="hero-card">
        <strong><?= e((string)($company['name'] ?? $catalogTitle)) ?></strong>
        <div style="margin-top:7px;font-size:13px;opacity:.92;">
          <?= $stStatus['open'] ? '🟢 ' : '🔴 ' ?><?= e($stStatus['label']) ?>
          <?php if (!$stStatus['open'] && $stStatus['next']): ?>
            <span style="display:block;margin-top:3px;">abre <?= e($stStatus['next']) ?></span>
          <?php endif; ?>
        </div>
      </div>
    </div>
  </section>

  <?php if (!empty($biz['banner']['enabled'])): ?>
    <?php
      $bText = (string)($biz['banner']['text'] ?? $biz['banner']['title'] ?? '');
      $bHint = (string)($biz['banner']['hint'] ?? $biz['banner']['subtitle'] ?? '');
      $bLink = trim((string)($biz['banner']['link'] ?? $biz['banner']['link_url'] ?? ''));
      $bCta  = (string)($biz['banner']['cta'] ?? $biz['banner']['link_text'] ?? '');
    ?>
    <div class="promo" role="note">
      <div class="promo-main">
        <span class="promo-ico" aria-hidden="true">✨</span>
        <div>
          <div class="promo-title"><?= e($bText) ?></div>
          <?php if ($bHint !== ''): ?><div class="promo-hint"><?= e($bHint) ?></div><?php endif; ?>
        </div>
      </div>
      <?php if ($bLink !== ''): ?>
        <a class="promo-link" href="<?= e($bLink) ?>" rel="noopener"><?= e($bCta ?: 'Ver') ?></a>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="public-readonly-note">
    Este catálogo é apenas para consulta. Clique em um produto para visualizar descrição e adicionais cadastrados, sem seleção ou compra nesta tela.
  </div>

  <form method="get" class="search" action="">
    <input type="hidden" name="empresa" value="<?= e((string)$company['slug']) ?>">
    <input name="q" value="<?= e($q) ?>" placeholder="Pesquisar produto (nome, categoria, descrição)..." autocomplete="off">
    <div class="catalog-search-actions">
      <button class="btn btn-primary" type="submit">Pesquisar</button>
      <?php if ($q !== ''): ?><a class="btn" href="<?= e($publicCatalogUrl) ?>">Limpar</a><?php endif; ?>
    </div>
  </form>

  <?php if (!$items): ?>
    <div class="panel"><div class="p">Nenhum produto encontrado neste catálogo.</div></div>
  <?php else: ?>
    <nav class="cats<?= $horizontalCategoryMenu ? ' cat-tile-menu' : '' ?>" aria-label="Categorias de produtos">
      <?php foreach (array_keys($groups) as $cat):
        $catSlug = public_catalog_category_slug($cat);
      ?>
        <?php if ($horizontalCategoryMenu):
          $catImg = trim((string)($categoryThumbs[$cat] ?? ''));
          $catInitial = mb_strtoupper(mb_substr($cat, 0, 1));
        ?>
          <a class="cat-tile" href="#<?= e($catSlug) ?>" title="<?= e($cat) ?>">
            <span class="cat-tile-media">
              <?php if ($catImg !== ''): ?>
                <img src="<?= e(public_catalog_product_image_url(['imagem' => $catImg], $company)) ?>" alt="<?= e($cat) ?>">
              <?php else: ?>
                <span class="cat-tile-initial"><?= e($catInitial) ?></span>
              <?php endif; ?>
            </span>
            <span class="cat-tile-label"><?= e($cat) ?></span>
          </a>
        <?php else: ?>
          <a class="chip" href="#<?= e($catSlug) ?>"><?= e($cat) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </nav>

    <?php foreach ($groups as $cat => $list):
      $catSlug = public_catalog_category_slug($cat);
    ?>
      <section id="<?= e($catSlug) ?>" class="cat-section">
        <div class="cat-title"><?= e($cat) ?></div>
        <div class="grid">
          <?php foreach ($list as $p):
            $pid = (int)$p['id'];
            $desc = trim((string)($p['descricao'] ?? ''));
            if ($desc !== '') {
              $desc = preg_replace('/\s+/', ' ', $desc);
              if (mb_strlen($desc) > 120) $desc = mb_substr($desc, 0, 119) . '…';
            }
            $hasOptions = !empty($optionsByProduct[$pid]);
          ?>
            <button type="button" class="card public-product-card" data-product-id="<?= $pid ?>">
              <div class="thumb">
                <img alt="<?= e((string)$p['nome']) ?>" src="<?= e(public_catalog_product_image_url($p, $company)) ?>">
              </div>
              <div class="card-body">
                <h3><?= e((string)$p['nome']) ?></h3>
                <?php if ($desc !== ''): ?><p class="desc"><?= e($desc) ?></p><?php endif; ?>
                <div class="price"><?= money((float)$p['preco']) ?></div>
                <div class="public-card-footer">
                  <span class="muted"><?= e((string)($p['categoria'] ?? '')) ?></span>
                  <span class="public-view-tag"><?= $hasOptions ? 'Ver adicionais' : 'Ver detalhes' ?></span>
                </div>
              </div>
            </button>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  <?php endif; ?>
</main>

<?php foreach ($items as $p):
  $pid = (int)$p['id'];
  $groupsOpt = (array)($optionsByProduct[$pid] ?? []);
?>
  <template id="public-product-template-<?= $pid ?>">
    <div class="public-detail">
      <div class="public-detail-img">
        <img alt="<?= e((string)$p['nome']) ?>" src="<?= e(public_catalog_product_image_url($p, $company)) ?>">
      </div>
      <div>
        <h2><?= e((string)$p['nome']) ?></h2>
        <div class="muted"><?= e((string)($p['categoria'] ?? '')) ?></div>
        <div class="price" style="font-size:24px;margin-top:10px;"><?= money((float)$p['preco']) ?></div>
        <?php if (trim((string)($p['descricao'] ?? '')) !== ''): ?>
          <div class="public-detail-desc"><?= nl2br(e((string)$p['descricao'])) ?></div>
        <?php endif; ?>

        <?php if ($groupsOpt): ?>
          <div class="readonly-options-box">
            <div class="readonly-options-title">
              <strong>Adicionais e opções</strong>
              <span>Visualização apenas para consulta. Não é possível marcar opções nesta tela.</span>
            </div>
            <?php foreach ($groupsOpt as $group):
              $type = (string)($group['tipo'] ?? 'single');
              $min = max(0, (int)($group['min_escolhas'] ?? 0));
              $max = max(0, (int)($group['max_escolhas'] ?? 0));
              $required = (int)($group['obrigatorio'] ?? 0) === 1;
              if ($required && $min < 1) $min = 1;
              if ($type === 'single') $max = 1;
              $rule = $type === 'single' ? 'escolha 1' : ($max > 0 ? 'até ' . $max : 'livre');
            ?>
              <div class="readonly-option-group">
                <div class="readonly-option-group-title">
                  <span><?= e((string)$group['titulo']) ?></span>
                  <small><?= e($rule) ?><?= $required ? ' • obrigatório' : ' • opcional' ?></small>
                </div>
                <div class="readonly-option-list">
                  <?php foreach ((array)$group['items'] as $item):
                    $priceAdd = (float)($item['preco'] ?? 0);
                    $optionFinalPrice = ((float)$p['preco']) + $priceAdd;
                  ?>
                    <div class="readonly-option-item" aria-disabled="true">
                      <span><?= e((string)$item['nome']) ?></span>
                      <div class="readonly-option-price">
                        <strong><?= $priceAdd > 0 ? '+ ' . money($priceAdd) : 'sem acréscimo' ?></strong>
                        <?php if ($showAdditionalUnitTotal && $priceAdd > 0): ?>
                          <small>Total: <?= money($optionFinalPrice) ?></small>
                        <?php endif; ?>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php else: ?>
          <div class="readonly-empty">Este produto não possui adicionais cadastrados.</div>
        <?php endif; ?>
      </div>
    </div>
  </template>
<?php endforeach; ?>

<div class="public-modal" id="publicProductModal" aria-hidden="true">
  <div class="public-modal-dialog" role="dialog" aria-modal="true" aria-labelledby="publicProductModalTitle">
    <div class="public-modal-head">
      <strong id="publicProductModalTitle">Detalhes do produto</strong>
      <button type="button" class="public-modal-close" data-close-public-modal aria-label="Fechar">×</button>
    </div>
    <div class="public-modal-body" id="publicProductModalBody"></div>
  </div>
</div>

<footer class="catalog-footer">
  <span><?= e($catalogTitle) ?> — Catálogo público</span>
  <span>Powered by Nexa Próspero</span>
</footer>

<script>
(function(){
  const modal = document.getElementById('publicProductModal');
  const body = document.getElementById('publicProductModalBody');
  if(!modal || !body) return;

  const openModal = (id) => {
    const tpl = document.getElementById('public-product-template-' + id);
    if(!tpl) return;
    body.innerHTML = '';
    body.appendChild(tpl.content.cloneNode(true));
    modal.classList.add('is-open');
    modal.setAttribute('aria-hidden', 'false');
    document.body.style.overflow = 'hidden';
  };

  const closeModal = () => {
    modal.classList.remove('is-open');
    modal.setAttribute('aria-hidden', 'true');
    body.innerHTML = '';
    document.body.style.overflow = '';
  };

  document.querySelectorAll('.public-product-card').forEach(card => {
    card.addEventListener('click', () => openModal(card.dataset.productId));
  });
  modal.addEventListener('click', (ev) => {
    if(ev.target === modal || ev.target.closest('[data-close-public-modal]')) closeModal();
  });
  document.addEventListener('keydown', (ev) => {
    if(ev.key === 'Escape' && modal.classList.contains('is-open')) closeModal();
  });
})();
</script>
</body>
</html>
