<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
products_send_dynamic_catalog_headers();

$q = (string)($_GET['q'] ?? '');
$items = products_search_db($q);
$items = products_filter_visible_catalog_categories_db($items, 'order');

$catalogUi = (array)($biz['catalog_ui'] ?? []);
$horizontalCategoryMenu = !empty($catalogUi['horizontal_category_menu']);

// Agrupa por categoria e guarda uma imagem de referência para o menu visual.
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

// Catálogos muito grandes permanecem com a mesma experiência visual e de navegação,
// mas não inserem centenas de cards no DOM de uma única vez. Isso é importante
// principalmente para bases importadas com muitos produtos ativos.
$progressiveCatalog = trim($q) === '' && count($items) > 200;
$initialProductsPerCategory = 12;
$deferredBatchSize = 24;

// IDs internos únicos para as âncoras. O nome exibido da categoria não é alterado.
$categoryAnchors = [];
$categorySeq = 0;
foreach (array_keys($groups) as $catName) {
  $categorySeq++;
  $categoryAnchors[$catName] = 'cat-' . $categorySeq;
}

$deferredProducts = [];
if ($progressiveCatalog) {
  foreach ($groups as $catName => $catProducts) {
    $anchor = (string)($categoryAnchors[$catName] ?? '');
    $remaining = array_slice($catProducts, $initialProductsPerCategory);
    if (!$remaining) continue;
    $deferredProducts[$anchor] = [];
    foreach ($remaining as $p) {
      $d = trim((string)($p['descricao'] ?? ''));
      if ($d !== '') {
        $d = preg_replace('/\s+/', ' ', $d);
        if (mb_strlen($d) > 160) $d = mb_substr($d, 0, 159) . '…';
      }
      $deferredProducts[$anchor][] = [
        'href' => asset_url('produto.php?id=' . (int)$p['id'], $app),
        'img' => upload_url((string)(($p['imagem'] ?? '') ?: 'placeholder.svg'), $app),
        'nome' => (string)($p['nome'] ?? ''),
        'descricao' => $d,
        'preco' => money((float)($p['preco'] ?? 0)),
        'categoria' => (string)($p['categoria'] ?? ''),
      ];
    }
  }
}

$page_title = $app['site_name'] . ' — Lista';
require __DIR__ . '/_layout_top.php';
?>

  <main class="order-catalog-page<?= $progressiveCatalog ? ' catalog-progressive' : '' ?>">
  <form method="get" class="search" style="margin:10px 0 14px; display:flex; gap:10px;">
    <input name="q" value="<?= e($q) ?>" placeholder="Pesquisar produto (nome, categoria, descrição)..." autocomplete="off">
  </form>

  <?php if (!$items): ?>
    <div class="panel"><div class="p">Nenhum produto encontrado.</div></div>
  <?php else: ?>

    <div class="cats<?= $horizontalCategoryMenu ? ' cat-tile-menu' : '' ?>">
      <?php foreach (array_keys($groups) as $cat):
        $cat = (string)$cat;
        $slug = (string)($categoryAnchors[$cat] ?? '');
      ?>
        <?php if ($horizontalCategoryMenu):
          $catImg = trim((string)($categoryThumbs[$cat] ?? ''));
          $catInitial = mb_strtoupper(mb_substr($cat, 0, 1));
        ?>
          <a class="cat-tile" href="#<?= e($slug) ?>" title="<?= e($cat) ?>">
            <span class="cat-tile-media">
              <?php if ($catImg !== ''): ?>
                <img src="<?= e(upload_url($catImg, $app)) ?>" alt="<?= e($cat) ?>">
              <?php else: ?>
                <span class="cat-tile-initial"><?= e($catInitial) ?></span>
              <?php endif; ?>
            </span>
            <span class="cat-tile-label"><?= e($cat) ?></span>
          </a>
        <?php else: ?>
          <a class="chip" href="#<?= e($slug) ?>"><?= e($cat) ?></a>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>

    <?php foreach ($groups as $cat => $list):
      $cat = (string)$cat;
      $slug = (string)($categoryAnchors[$cat] ?? '');
    ?>
      <?php
        $visibleList = $progressiveCatalog ? array_slice($list, 0, $initialProductsPerCategory) : $list;
        $hasDeferred = $progressiveCatalog && !empty($deferredProducts[$slug]);
      ?>
      <div id="<?= e($slug) ?>" class="cat-section">
        <div class="cat-title"><?= e($cat) ?></div>
        <div class="grid"<?= $hasDeferred ? ' data-progressive-grid="' . e($slug) . '"' : '' ?>>
          <?php foreach ($visibleList as $p): ?>
            <a class="card" href="<?= asset_url('produto.php?id=' . (int)$p['id'], $app) ?>">
              <div class="thumb">
                <img alt="<?= e((string)$p['nome']) ?>" src="<?= upload_url((string)(($p['imagem'] ?? '') ?: 'placeholder.svg'), $app) ?>" loading="lazy" decoding="async">
              </div>
              <div class="card-body">
                <h3><?= e((string)$p['nome']) ?></h3>
                <?php $d = trim((string)($p['descricao'] ?? '')); if ($d !== '') { $d = preg_replace('/\s+/', ' ', $d); if (mb_strlen($d) > 160) $d = mb_substr($d, 0, 159) . '…'; } ?>
                <?php if ($d !== '') { ?><p class="desc"><?= e($d) ?></p><?php } ?>
                <div class="price"><?= money((float)$p['preco']) ?></div>
                <div class="muted"><?= e((string)($p['categoria'] ?? '')) ?></div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
        <?php if ($hasDeferred): ?>
          <div class="catalog-progressive-sentinel" data-progressive-sentinel="<?= e($slug) ?>" aria-hidden="true"></div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  <?php endif; ?>

  <?php if ($progressiveCatalog): ?>
  <style>
    .catalog-progressive-sentinel{height:2px;width:100%;pointer-events:none}
    .order-catalog-page.catalog-progressive .card{animation:none!important}
  </style>
  <script>
    (function(){
      const pending = <?= json_encode($deferredProducts, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
      const batchSize = <?= (int)$deferredBatchSize ?>;

      function buildCard(item){
        const card = document.createElement('a');
        card.className = 'card';
        card.href = item.href || '#';

        const thumb = document.createElement('div');
        thumb.className = 'thumb';
        const img = document.createElement('img');
        img.alt = item.nome || '';
        img.src = item.img || '';
        img.loading = 'lazy';
        img.decoding = 'async';
        thumb.appendChild(img);

        const body = document.createElement('div');
        body.className = 'card-body';
        const h3 = document.createElement('h3');
        h3.textContent = item.nome || '';
        body.appendChild(h3);
        if (item.descricao) {
          const desc = document.createElement('p');
          desc.className = 'desc';
          desc.textContent = item.descricao;
          body.appendChild(desc);
        }
        const price = document.createElement('div');
        price.className = 'price';
        price.textContent = item.preco || '';
        body.appendChild(price);
        const muted = document.createElement('div');
        muted.className = 'muted';
        muted.textContent = item.categoria || '';
        body.appendChild(muted);

        card.appendChild(thumb);
        card.appendChild(body);
        return card;
      }

      function loadBatch(key){
        const list = pending[key];
        const grid = document.querySelector('[data-progressive-grid="' + CSS.escape(key) + '"]');
        const sentinel = document.querySelector('[data-progressive-sentinel="' + CSS.escape(key) + '"]');
        if (!Array.isArray(list) || !grid || !list.length) {
          if (sentinel) sentinel.remove();
          return false;
        }
        const frag = document.createDocumentFragment();
        list.splice(0, batchSize).forEach(function(item){ frag.appendChild(buildCard(item)); });
        grid.appendChild(frag);
        if (!list.length && sentinel) sentinel.remove();
        return list.length > 0;
      }

      function fallbackCheck(){
        document.querySelectorAll('[data-progressive-sentinel]').forEach(function(sentinel){
          const rect = sentinel.getBoundingClientRect();
          if (rect.top < window.innerHeight + 900) loadBatch(sentinel.getAttribute('data-progressive-sentinel') || '');
        });
      }

      const sentinels = Array.from(document.querySelectorAll('[data-progressive-sentinel]'));
      if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver(function(entries){
          entries.forEach(function(entry){
            if (!entry.isIntersecting) return;
            const key = entry.target.getAttribute('data-progressive-sentinel') || '';
            const hasMore = loadBatch(key);
            if (!hasMore) observer.unobserve(entry.target);
          });
        }, {rootMargin:'900px 0px'});
        sentinels.forEach(function(el){ observer.observe(el); });
      } else {
        window.addEventListener('scroll', fallbackCheck, {passive:true});
        window.addEventListener('resize', fallbackCheck);
        fallbackCheck();
      }
    })();
  </script>
  <?php endif; ?>

  <script>
    (function(){
      const page = document.querySelector('.order-catalog-page');
      const cats = page ? page.querySelector('.cats') : null;
      if (!page || !cats) return;

      function scrollToCategory(target){
        const menuHeight = cats.getBoundingClientRect().height || 0;
        const extraSpace = 22;
        const targetTop = target.getBoundingClientRect().top + window.pageYOffset;
        const desiredTop = Math.max(targetTop - menuHeight - extraSpace, 0);
        window.scrollTo({ top: desiredTop, behavior: 'smooth' });
      }

      cats.querySelectorAll('a[href^="#"]').forEach(function(link){
        link.addEventListener('click', function(ev){
          const hash = link.getAttribute('href');
          if (!hash || hash === '#') return;
          const target = document.getElementById(hash.slice(1));
          if (!target) return;

          ev.preventDefault();
          scrollToCategory(target);

          if (history && history.replaceState) {
            history.replaceState(null, '', hash);
          }
        });
      });
    })();
  </script>
  </main>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
