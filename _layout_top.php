<?php
declare(strict_types=1);
/** @var array $app */
/** @var array $biz */
/** @var array $company */
/** @var string $page_title */

$st = store_status($biz);
$brand = (array)($biz['brand'] ?? []);
$primary = (string)($brand['primary_color'] ?? '#0f766e');
$secondary = (string)($brand['secondary_color'] ?? '#14b8a6');
$accent = (string)($brand['accent_color'] ?? '#f59e0b');
$textOnPrimary = (string)($brand['text_on_primary'] ?? '#ffffff');
$subtitle = trim((string)($brand['catalog_subtitle'] ?? 'Catálogo • carrinho • pedidos'));
$logoFile = trim((string)($biz['logo']['file'] ?? ''));
$logoUrl = $logoFile !== '' ? ('/uploads/logos/' . rawurlencode($logoFile)) : '';
$coverFile = trim((string)($biz['cover']['file'] ?? ''));
$coverUrl = ($coverFile !== '' && !empty($biz['cover']['enabled'])) ? ('/uploads/covers/' . rawurlencode($coverFile)) : '';
$cssFile = __DIR__ . '/assets/app.css';
$cssVersion = is_file($cssFile) ? (string)filemtime($cssFile) : '1';
$cartUrl = asset_url('carrinho.php', $app);
$compactCheckout = !empty($checkoutCompactLayout);
$floatingCartTotal = isset($checkoutFloatingCartTotal) ? (float)$checkoutFloatingCartTotal : null;
$cartCount = 0;
if (session_status() === PHP_SESSION_NONE && !headers_sent()) {
  @session_start();
}
if (session_status() === PHP_SESSION_ACTIVE) {
  $sessionCart = (array)($_SESSION['cart'] ?? []);
  $sessionCustom = (array)($_SESSION['cart_custom'] ?? []);

  foreach ($sessionCart as $qtd) {
    $cartCount += max(0, (int)$qtd);
  }
  foreach ($sessionCustom as $customItem) {
    $cartCount += max(0, (int)($customItem['qtd'] ?? 0));
  }

  // Fora do checkout, calcula o valor atual do carrinho para exibi-lo desde
  // as primeiras telas do catálogo. No checkout prevalece o total calculado
  // pela própria página, pois ele pode incluir a taxa de entrega.
  if ($floatingCartTotal === null) {
    $floatingCartTotal = 0.0;
    try {
      $cartProductIds = array_map('intval', array_keys($sessionCart));
      foreach ($sessionCustom as $customItem) {
        $type = (string)($customItem['type'] ?? '');
        if ($type === 'half') {
          $cartProductIds[] = (int)($customItem['a'] ?? 0);
          $cartProductIds[] = (int)($customItem['b'] ?? 0);
        } elseif ($type === 'configured') {
          $cartProductIds[] = (int)($customItem['id'] ?? 0);
        }
      }
      $cartProductIds = array_values(array_unique(array_filter($cartProductIds, static fn(int $id): bool => $id > 0)));

      if ($cartProductIds && isset($pdo) && $pdo instanceof PDO) {
        $in = implode(',', array_fill(0, count($cartProductIds), '?'));
        $params = array_merge([defined('COMPANY_ID') ? (int)COMPANY_ID : 0], $cartProductIds);
        $stmtCartTotal = $pdo->prepare("SELECT id, preco FROM produtos WHERE company_id = ? AND ativo = 1 AND id IN ($in)");
        $stmtCartTotal->execute($params);
        $priceMap = [];
        foreach ($stmtCartTotal->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $priceMap[(int)$row['id']] = (float)$row['preco'];
        }

        foreach ($sessionCart as $id => $qtd) {
          $id = (int)$id;
          if (!isset($priceMap[$id])) continue;
          $floatingCartTotal += max(0.0, (float)$qtd) * $priceMap[$id];
        }

        foreach ($sessionCustom as $customItem) {
          $type = (string)($customItem['type'] ?? '');
          $qtd = max(0.0, (float)($customItem['qtd'] ?? 0));
          if ($type === 'half') {
            $a = (int)($customItem['a'] ?? 0);
            $b = (int)($customItem['b'] ?? 0);
            if (isset($priceMap[$a], $priceMap[$b])) {
              $floatingCartTotal += $qtd * max($priceMap[$a], $priceMap[$b]);
            }
          } elseif ($type === 'configured') {
            $id = (int)($customItem['id'] ?? 0);
            if (isset($priceMap[$id])) {
              $floatingCartTotal += $qtd * ($priceMap[$id] + (float)($customItem['options_total'] ?? 0));
            }
          }
        }
      }
    } catch (Throwable $e) {
      // O valor é apenas informativo; uma falha aqui não pode impedir a navegação.
      $floatingCartTotal = null;
    }
  }
}
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($page_title) ?></title>
  <link rel="stylesheet" href="<?= asset_url('assets/app.css?v=' . $cssVersion, $app) ?>">
  <style>
    :root{
      --brand-primary: <?= e($primary) ?>;
      --brand-secondary: <?= e($secondary) ?>;
      --brand-accent: <?= e($accent) ?>;
      --brand-on-primary: <?= e($textOnPrimary) ?>;
      --bg: color-mix(in srgb, var(--brand-primary) 6%, #f8fafc);
      --line: color-mix(in srgb, var(--brand-primary) 14%, rgba(2,6,23,.10));
      --accent: var(--brand-secondary);
      --brand-soft: color-mix(in srgb, var(--brand-primary) 11%, #fff);
      --brand-soft-2: color-mix(in srgb, var(--brand-secondary) 10%, #fff);
      --brand-border-soft: color-mix(in srgb, var(--brand-primary) 28%, rgba(2,6,23,.10));
      --cart-color: var(--brand-accent);
      --cart-color-2: color-mix(in srgb, var(--brand-accent) 76%, var(--brand-primary));
    }
    .hero-banner{position:relative;overflow:hidden;border-radius:28px;min-height:220px;margin:0 0 18px;background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));color:var(--brand-on-primary);box-shadow:0 20px 45px rgba(15,23,42,.14)}
    .hero-banner.has-cover{background-image:linear-gradient(135deg, color-mix(in srgb, var(--brand-primary) 72%, transparent), color-mix(in srgb, var(--brand-secondary) 62%, transparent)), url('<?= e($coverUrl) ?>');background-size:cover;background-position:center}
    .hero-banner:after{content:"";position:absolute;inset:auto -40px -40px auto;width:180px;height:180px;background:color-mix(in srgb, var(--brand-accent) 42%, transparent);filter:blur(20px);border-radius:999px;opacity:.85}
    .hero-content{position:relative;z-index:1;padding:28px;display:flex;justify-content:space-between;gap:18px;align-items:flex-end;min-height:220px}
    .hero-kicker{display:inline-flex;align-items:center;gap:8px;padding:8px 12px;border-radius:999px;background:rgba(255,255,255,.14);backdrop-filter:blur(6px);font-size:12px;font-weight:700;letter-spacing:.02em}
    .hero-title{font-size:clamp(28px,4vw,42px);line-height:1.05;margin:12px 0 8px;font-weight:950;max-width:680px}
    .hero-subtitle{max-width:680px;font-size:15px;opacity:.92;line-height:1.5}
    .hero-card{background:rgba(255,255,255,.12);border:1px solid rgba(255,255,255,.18);backdrop-filter:blur(10px);padding:16px 18px;border-radius:22px;min-width:240px}
    .hero-card strong{display:block;font-size:18px;margin-bottom:0}
    .footer a{color:var(--brand-primary);font-weight:900;text-decoration:none}
    .footer a:hover{text-decoration:underline}
    .header{padding:10px 0 0}
    .logo{background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));box-shadow:0 10px 24px color-mix(in srgb, var(--brand-primary) 22%, transparent)}
    .btn,.btn-primary,.promo-link{border-color:color-mix(in srgb, var(--brand-secondary) 42%, rgba(2,6,23,.08))}
    .btn-primary{background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));color:var(--brand-on-primary);border-color:transparent;box-shadow:0 10px 22px color-mix(in srgb, var(--brand-primary) 18%, transparent)}
    .promo{background:linear-gradient(90deg,var(--brand-soft),var(--brand-soft-2));border-color:var(--brand-border-soft)}
    .promo-title{color:var(--text)}
    .promo-link{background:var(--brand-primary);color:var(--brand-on-primary);border-color:transparent;box-shadow:0 10px 22px color-mix(in srgb, var(--brand-primary) 16%, transparent)}
    .badge-open{background:#ecfdf5;border-color:rgba(34,197,94,.35);color:#047857}
    .badge.cart-badge{background:linear-gradient(135deg,var(--cart-color),var(--cart-color-2));border-color:transparent;color:#fff;font-weight:950;box-shadow:0 10px 24px color-mix(in srgb, var(--cart-color) 24%, transparent)}
    .cart-badge-total{font-size:12px;font-weight:950;white-space:nowrap;opacity:.96}
    .cart-badge-count{display:inline-flex;align-items:center;justify-content:center;min-width:20px;height:20px;padding:0 6px;border-radius:999px;background:#fff;color:var(--cart-color);font-size:11px;font-weight:950;margin-left:2px}
    .floating-cart{position:fixed;right:22px;bottom:22px;z-index:9999;display:inline-flex;align-items:center;gap:9px;min-height:58px;padding:0 18px;border-radius:999px;background:linear-gradient(135deg,var(--cart-color),var(--cart-color-2));color:#fff;text-decoration:none;font-weight:950;box-shadow:0 18px 42px color-mix(in srgb, var(--cart-color) 34%, rgba(15,23,42,.20));border:1px solid rgba(255,255,255,.24)}
    .floating-cart > span{font-size:22px;line-height:1}
    .cart-icon{width:24px;height:24px;display:inline-block;flex:0 0 24px;color:#fff;vertical-align:middle}
    .cart-icon svg{display:block;width:100%;height:100%;stroke:currentColor;fill:none;stroke-width:2.2;stroke-linecap:round;stroke-linejoin:round}
    .cart-badge .cart-icon{width:18px;height:18px;flex-basis:18px;margin-right:2px}
    .floating-cart strong{display:flex;flex-direction:column;gap:1px;line-height:1.05;font-size:14px;letter-spacing:-.02em}
    .floating-cart strong span{font-size:12px;line-height:1.05;opacity:.92}
    .floating-cart strong small{font-size:15px;line-height:1.12;color:#fff;font-weight:950;white-space:nowrap}
    .floating-cart em{position:absolute;top:-7px;right:-7px;min-width:24px;height:24px;padding:0 7px;border-radius:999px;background:#ef4444;color:#fff;border:2px solid #fff;font-style:normal;font-size:12px;display:flex;align-items:center;justify-content:center}
    .floating-cart:hover{transform:translateY(-2px);filter:brightness(1.03)}
    .floating-cart.floating-cart-checkout{bottom:92px;min-width:148px;justify-content:flex-start;padding-right:20px}
    .cats{top:0;background:linear-gradient(180deg,var(--bg),color-mix(in srgb, var(--bg) 86%, transparent))}
    .cat-section:before{background:linear-gradient(90deg,var(--brand-primary),var(--brand-accent))}

    /* CSS crítico do menu horizontal: evita quebra caso o CSS externo fique em cache no servidor/CDN. */
    .cats.cat-tile-menu{display:flex;gap:12px;flex-wrap:nowrap;overflow-x:auto;overflow-y:hidden;padding:12px 0 14px;margin-bottom:16px;scroll-snap-type:x proximity;-webkit-overflow-scrolling:touch}
    .cats.cat-tile-menu .cat-tile{flex:0 0 96px;max-width:96px;min-height:112px;display:flex;flex-direction:column;align-items:center;justify-content:flex-start;gap:8px;padding:10px 8px;border:1px solid var(--line);border-radius:22px;background:#fff;color:var(--text);text-align:center;box-shadow:0 10px 24px rgba(2,6,23,.07);overflow:hidden}
    .cats.cat-tile-menu .cat-tile-media{width:70px;height:62px;min-width:70px;max-width:70px;flex:0 0 62px;border-radius:18px;overflow:hidden;display:flex;align-items:center;justify-content:center;background:var(--brand-soft);border:1px solid var(--brand-border-soft)}
    .cats.cat-tile-menu .cat-tile-media img{width:100%;height:100%;max-width:100%;max-height:100%;object-fit:cover;display:block}
    .cats.cat-tile-menu .cat-tile-label{width:100%;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;line-height:1.12;font-size:12px;font-weight:900;color:#0f172a;word-break:break-word}
    .order-catalog-page .grid{align-items:stretch}
    .order-catalog-page .card{display:flex;flex-direction:column;min-width:0;height:100%}
    .order-catalog-page .thumb{width:100%;height:180px;aspect-ratio:auto;overflow:hidden;flex:0 0 auto;border-bottom:1px solid rgba(2,6,23,.06);background:#fff}
    .order-catalog-page .thumb img{display:block;width:100%;height:100%;max-width:100%;max-height:100%;object-fit:cover}
    .order-catalog-page .card-body{flex:1;display:flex;flex-direction:column;min-width:0}
    .order-catalog-page .card-body h3,.order-catalog-page .card-body .desc,.order-catalog-page .card-body .muted{overflow-wrap:anywhere}
    @media(max-width:760px){.order-catalog-page .thumb{height:150px}}
    @media(max-width:520px){.cats.cat-tile-menu{gap:10px}.cats.cat-tile-menu .cat-tile{flex-basis:86px;max-width:86px;min-height:104px;border-radius:20px;padding:9px 7px}.cats.cat-tile-menu .cat-tile-media{width:62px;height:56px;min-width:62px;max-width:62px;flex-basis:56px;border-radius:16px}.cats.cat-tile-menu .cat-tile-label{font-size:11px}.order-catalog-page .thumb{height:132px}}
    @media(max-width:760px){.hero-content{padding:22px;align-items:flex-start;flex-direction:column}.hero-card{width:100%}.floating-cart{right:12px;bottom:16px;min-height:52px;min-width:132px;padding:0 14px;justify-content:flex-start}.floating-cart strong{display:flex}.floating-cart.floating-cart-checkout{right:10px;bottom:84px;min-height:52px;min-width:136px;padding:0 14px}.floating-cart em{top:-6px;right:-6px}}
  </style>
</head>
<body>
  <div class="container">
    <a class="floating-cart<?= $cartCount > 0 ? ' has-items' : '' ?><?= $compactCheckout ? ' floating-cart-checkout' : '' ?>" href="<?= e($cartUrl) ?>" aria-label="Ir para o carrinho">
      <span class="cart-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="20" r="1"></circle><circle cx="19" cy="20" r="1"></circle><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.5h7.9a2 2 0 0 0 2-1.6L21 8H6"></path></svg></span>
      <?php if ($floatingCartTotal !== null): ?>
        <strong><span>Carrinho</span><small<?= $compactCheckout ? ' id="floatingCartAmount"' : '' ?>>R$ <?= e(number_format($floatingCartTotal, 2, ',', '.')) ?></small></strong>
      <?php else: ?>
        <strong><span>Carrinho</span></strong>
      <?php endif; ?>
      <?php if ($cartCount > 0): ?><em><?= e((string)$cartCount) ?></em><?php endif; ?>
    </a>

    <?php if (!$compactCheckout && $coverUrl !== ''): ?>
      <section class="hero-banner has-cover">
        <div class="hero-content">
          <div>
            <div class="hero-kicker">✨ Catálogo da empresa</div>
            <div class="hero-title"><?= e($app['site_name']) ?></div>
            <div class="hero-subtitle"><?= e($subtitle !== '' ? $subtitle : 'Seu catálogo digital com carrinho, checkout e atendimento rápido no WhatsApp.') ?></div>
          </div>
          <div class="hero-card">
            <strong><?= e((string)($company['name'] ?? $app['site_name'])) ?></strong>
          </div>
        </div>
      </section>
    <?php endif; ?>

    <?php if (!$compactCheckout && !empty($biz['banner']['enabled'])): ?>
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

    <?php if (!$compactCheckout): ?>
    <div class="header">
      <div class="brand">
        <div class="logo<?= $logoUrl !== '' ? ' logo-has-image' : '' ?>" aria-hidden="true">
          <?php if ($logoUrl !== ''): ?><img src="<?= e($logoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
        </div>
        <div class="title">
          <h1><?= e($app['site_name']) ?></h1>
          <p><?= e($subtitle) ?></p>
        </div>
      </div>
      <div style="display:flex; gap:10px; align-items:center; flex-wrap:wrap; justify-content:flex-end;">
        <div class="badge <?= $st['open'] ? 'badge-open' : 'badge-closed' ?>">
          <?= $st['open'] ? '🟢' : '🔴' ?> <?= e($st['label']) ?>
          <?php if (!$st['open'] && $st['next']): ?>
            <span class="muted" style="margin-left:6px; font-size:12px;">(<?= e($st['next']) ?>)</span>
          <?php endif; ?>
        </div>
        <a class="badge cart-badge" href="<?= e($cartUrl) ?>"><span class="cart-icon" aria-hidden="true"><svg viewBox="0 0 24 24" focusable="false"><circle cx="9" cy="20" r="1"></circle><circle cx="19" cy="20" r="1"></circle><path d="M3 4h2l2.4 10.2a2 2 0 0 0 2 1.5h7.9a2 2 0 0 0 2-1.6L21 8H6"></path></svg></span> Carrinho<?php if ($floatingCartTotal !== null): ?><span class="cart-badge-total">R$ <?= e(number_format($floatingCartTotal, 2, ',', '.')) ?></span><?php endif; ?><?php if ($cartCount > 0): ?><span class="cart-badge-count"><?= e((string)$cartCount) ?></span><?php endif; ?></a>
      </div>
    </div>
    <?php endif; ?>
