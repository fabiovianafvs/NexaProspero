<?php
declare(strict_types=1);
session_start();
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/product_options.php';

$page_title = $app['site_name'] . ' — Carrinho';

$cart = $_SESSION['cart'] ?? [];
$custom = $_SESSION['cart_custom'] ?? [];
$cartObs = $_SESSION['cart_obs'] ?? [];

$ids = array_keys($cart);
foreach ($custom as $ci) {
  if (($ci['type'] ?? '') === 'half') {
    $ids[] = (int)($ci['a'] ?? 0);
    $ids[] = (int)($ci['b'] ?? 0);
  } elseif (($ci['type'] ?? '') === 'configured') {
    $ids[] = (int)($ci['id'] ?? 0);
  }
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

$itens = [];
$total = 0.0;

if ($ids) {
  $pdo = pdo_connect(require __DIR__ . '/config/db.php');

  $in  = implode(',', array_fill(0, count($ids), '?'));
  $flagSelect = products_has_column($pdo, 'hide_order_catalog_product')
    ? ', COALESCE(hide_order_catalog_product,0) AS hide_order_catalog_product'
    : ', 0 AS hide_order_catalog_product';
  $st = $pdo->prepare("SELECT id, nome, categoria, preco, imagem{$flagSelect} FROM produtos WHERE company_id = ? AND id IN ($in) AND ativo = 1");
  $st->execute(array_merge([COMPANY_ID], $ids));
  $rows = products_filter_visible_catalog_categories_db($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'order');

  $map = [];
  foreach ($rows as $r) $map[(int)$r['id']] = $r;

  // Itens simples
  foreach (array_keys($cart) as $id) {
    $id = (int)$id;
    if (!isset($map[$id])) continue;

    $qtd = (float)$cart[$id];
    $preco = (float)$map[$id]['preco'];
    $sub = $qtd * $preco;

    $itens[] = [
      'type' => 'simple',
      'id' => $id,
      'nome' => (string)$map[$id]['nome'],
      'categoria' => (string)($map[$id]['categoria'] ?? ''),
      'imagem' => (string)(($map[$id]['imagem'] ?? '') ?: 'placeholder.svg'),
      'preco' => $preco,
      'qtd' => $qtd,
      'subtotal' => $sub,
    ];
    $total += $sub;
  }

  // Itens compostos (pizza meio a meio e produtos configurados com adicionais)
  foreach ($custom as $key => $ci) {
    if (($ci['type'] ?? '') === 'half') {
      $a = (int)($ci['a'] ?? 0);
      $b = (int)($ci['b'] ?? 0);
      if (!isset($map[$a], $map[$b])) continue;

      $qtd = (float)($ci['qtd'] ?? 1);
      $pa = (float)$map[$a]['preco'];
      $pb = (float)$map[$b]['preco'];
      $precoBase = max($pa, $pb);
      $optionsTotal = (float)($ci['options_total'] ?? 0);
      $preco = $precoBase + $optionsTotal;
      $sub = $qtd * $preco;
      $optionsText = product_options_text((array)($ci['options'] ?? []));

      $nome = 'Pizza meio a meio';
      $itens[] = [
        'type' => 'half',
        'key' => (string)$key,
        'a' => $a,
        'b' => $b,
        'nome' => $nome,
        'categoria' => '1/2 ' . (string)$map[$a]['nome'] . ' + 1/2 ' . (string)$map[$b]['nome'],
        'sabor_a' => (string)$map[$a]['nome'],
        'sabor_b' => (string)$map[$b]['nome'],
        'imagem' => (string)(($map[$a]['imagem'] ?? '') ?: ($map[$b]['imagem'] ?? '') ?: 'placeholder.svg'),
        'preco' => $preco,
        'preco_base' => $precoBase,
        'options_total' => $optionsTotal,
        'options_text' => $optionsText,
        'qtd' => $qtd,
        'subtotal' => $sub,
      ];
      $total += $sub;
      continue;
    }

    if (($ci['type'] ?? '') === 'configured') {
      $id = (int)($ci['id'] ?? 0);
      if (!isset($map[$id])) continue;

      $qtd = (float)($ci['qtd'] ?? 1);
      $base = (float)$map[$id]['preco'];
      $optionsTotal = (float)($ci['options_total'] ?? 0);
      $preco = $base + $optionsTotal;
      $sub = $qtd * $preco;
      $optionsText = product_options_text((array)($ci['options'] ?? []));

      $itens[] = [
        'type' => 'configured',
        'key' => (string)$key,
        'id' => $id,
        'nome' => (string)$map[$id]['nome'],
        'categoria' => (string)($map[$id]['categoria'] ?? ''),
        'imagem' => (string)(($map[$id]['imagem'] ?? '') ?: 'placeholder.svg'),
        'preco' => $preco,
        'preco_base' => $base,
        'options_total' => $optionsTotal,
        'options_text' => $optionsText,
        'qtd' => $qtd,
        'subtotal' => $sub,
      ];
      $total += $sub;
      continue;
    }
  }
}

require __DIR__ . '/_layout_top.php';
?>

<div class="breadcrumb">
  <a href="<?= asset_url('', $app) ?>">Início</a> / Carrinho
</div>

<?php if (!$itens): ?>
  <div class="panel"><div class="p">
    Seu carrinho está vazio.
    <div style="margin-top:12px;">
      <a class="btn" href="<?= asset_url('', $app) ?>">Ver produtos</a>
    </div>
  </div></div>
<?php else: ?>

  <div class="panel"><div class="p">
    <form id="cartForm" method="post" action="<?= asset_url('cart_update.php', $app) ?>">
      <div class="cart-list">
        <?php foreach ($itens as $i): ?>
          <div class="panel cart-card" data-unit-price="<?= e(number_format((float)$i['preco'], 4, '.', '')) ?>">
            <div class="p cart-row">
              <div class="cart-main">
                <div class="cart-thumb">
                  <img src="<?= upload_url($i['imagem'], $app) ?>" alt="<?= e($i['nome']) ?>">
                </div>
                <div class="cart-info">
                  <div class="cart-name"><?= e($i['nome']) ?></div>
                  <?php if (($i['type'] ?? 'simple') === 'simple') { $oid=(int)$i['id']; $ov=(string)($cartObs[$oid] ?? ''); ?>
                    <textarea class="field cart-obs" name="obs[<?= (int)$i['id'] ?>]" placeholder="Observação do item (opcional)" rows="2"><?= e($ov) ?></textarea>
                  <?php } else { $k=(string)($i['key'] ?? ''); $ov=(string)(($_SESSION['cart_custom'][$k]['obs'] ?? '') ?? ''); ?>
                    <textarea class="field cart-obs" name="obs_custom[<?= e($k) ?>]" placeholder="Observação do item (opcional)" rows="2"><?= e($ov) ?></textarea>
                  <?php } ?>

                  <?php if (($i['type'] ?? 'simple') === 'half'): ?>
                    <div class="muted" style="margin-top:6px;">1/2 <?= e((string)($i['sabor_a'] ?? '')) ?></div>
                    <div class="muted">1/2 <?= e((string)($i['sabor_b'] ?? '')) ?></div>
                    <?php if (trim((string)($i['options_text'] ?? '')) !== ''): ?>
                      <div class="muted" style="margin-top:6px;white-space:pre-line;color:#0b1f5b;"><?= e((string)$i['options_text']) ?></div>
                    <?php endif; ?>
                  <?php elseif (($i['type'] ?? 'simple') === 'configured'): ?>
                    <div class="muted"><?= e($i['categoria']) ?></div>
                    <?php if (trim((string)($i['options_text'] ?? '')) !== ''): ?>
                      <div class="muted" style="margin-top:6px;white-space:pre-line;color:#0b1f5b;"><?= e((string)$i['options_text']) ?></div>
                    <?php endif; ?>
                  <?php else: ?>
                    <div class="muted"><?= e($i['categoria']) ?></div>
                  <?php endif; ?>
                  <div class="muted">Preço: <?= money($i['preco']) ?></div>
                </div>
              </div>

              <div class="cart-actions">
                <?php if (in_array(($i['type'] ?? 'simple'), ['half','configured'], true)): ?>
                  <div class="qty qty-ctrl qty-ctrl-sm">
                    <button type="button" class="qty-btn" data-step="-1">−</button>
                    <input
                      class="qty-input"
                      type="number"
                      min="0"
                      step="1"
                      name="qtd_custom[<?= e((string)$i['key']) ?>]"
                      value="<?= e((string)$i['qtd']) ?>"
                    >
                    <button type="button" class="qty-btn" data-step="1">+</button>
                  </div>
                <?php else: ?>
                  <div class="qty qty-ctrl qty-ctrl-sm">
                    <button type="button" class="qty-btn" data-step="-1">−</button>
                    <input
                      class="qty-input"
                      type="number"
                      min="0"
                      step="1"
                      name="qtd[<?= (int)$i['id'] ?>]"
                      value="<?= e((string)$i['qtd']) ?>"
                    >
                    <button type="button" class="qty-btn" data-step="1">+</button>
                  </div>
                <?php endif; ?>
                <div class="cart-subtotal" data-cart-subtotal>
                  <?= money($i['subtotal']) ?>
                </div>
                <?php if (in_array(($i['type'] ?? 'simple'), ['half','configured'], true)): ?>
                  <a class="btn cart-remove" href="<?= asset_url('cart_remove.php?k='.rawurlencode((string)$i['key']), $app) ?>">Remover</a>
                <?php else: ?>
                  <a class="btn cart-remove" href="<?= asset_url('cart_remove.php?id='.(int)$i['id'], $app) ?>">Remover</a>
                <?php endif; ?>
              </div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>

      <div class="cart-footer">
        <a class="btn cart-footer-btn" href="<?= asset_url('', $app) ?>">Continuar comprando</a>
        <div class="cart-summary">
          <div class="price cart-total" id="cartTotal">Total: <?= money($total) ?></div>
          <button class="btn cart-footer-btn" type="submit">Atualizar carrinho</button>
          <div class="muted small" id="cartAutoStatus" aria-live="polite"></div>
          <a class="btn btn-primary cart-footer-btn" id="cartCheckout" href="<?= asset_url('checkout.php', $app) ?>">Finalizar pedido</a>
        </div>
      </div>
    </form>
  </div></div>

<?php endif; ?>

<script>
(function(){
  const form = document.getElementById('cartForm');
  if (!form) return;

  const totalEl = document.getElementById('cartTotal');
  const statusEl = document.getElementById('cartAutoStatus');
  const checkoutEl = document.getElementById('cartCheckout');
  let syncTimer = null;
  let syncPromise = null;

  const brMoney = (value) => {
    try {
      return Number(value || 0).toLocaleString('pt-BR', {style:'currency', currency:'BRL'});
    } catch (e) {
      return 'R$ ' + (Math.round(Number(value || 0) * 100) / 100).toFixed(2).replace('.', ',');
    }
  };

  const normalizeQty = (input) => {
    let qty = parseInt(String(input.value || '0').replace(',', '.'), 10);
    if (!Number.isFinite(qty) || qty < 0) qty = 0;
    input.value = String(qty);
    return qty;
  };

  const recalc = () => {
    let total = 0;
    form.querySelectorAll('.cart-card').forEach(card => {
      const input = card.querySelector('.qty-input');
      if (!input) return;
      const qty = normalizeQty(input);
      const unit = parseFloat(card.dataset.unitPrice || '0') || 0;
      const subtotal = qty * unit;
      total += subtotal;
      const subtotalEl = card.querySelector('[data-cart-subtotal]');
      if (subtotalEl) subtotalEl.textContent = brMoney(subtotal);
    });
    if (totalEl) totalEl.textContent = 'Total: ' + brMoney(total);
    return total;
  };

  const syncNow = async () => {
    if (syncTimer) {
      clearTimeout(syncTimer);
      syncTimer = null;
    }
    recalc();
    if (statusEl) statusEl.textContent = 'Salvando...';

    const body = new FormData(form);
    syncPromise = fetch(form.action, {
      method: 'POST',
      body,
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'Accept': 'application/json'
      },
      credentials: 'same-origin'
    }).then(async response => {
      if (!response.ok) throw new Error('Falha ao atualizar carrinho');
      const data = await response.json().catch(() => ({ok:true}));
      if (data && data.ok === false) throw new Error(data.error || 'Falha ao atualizar carrinho');
      if (statusEl) statusEl.textContent = 'Carrinho atualizado automaticamente.';
      return true;
    }).catch(() => {
      if (statusEl) statusEl.textContent = 'Não foi possível salvar automaticamente. Use “Atualizar carrinho”.';
      return false;
    }).finally(() => {
      syncPromise = null;
    });

    return syncPromise;
  };

  const scheduleSync = (delay = 350) => {
    if (syncTimer) clearTimeout(syncTimer);
    if (statusEl) statusEl.textContent = 'Alteração pendente...';
    syncTimer = setTimeout(() => { syncNow(); }, delay);
  };

  form.querySelectorAll('.qty-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const wrap = btn.closest('.qty');
      const input = wrap ? wrap.querySelector('.qty-input') : null;
      if (!input) return;
      const step = parseInt(btn.dataset.step || '1', 10) || 1;
      const current = normalizeQty(input);
      input.value = String(Math.max(0, current + step));
      recalc();
      scheduleSync(250);
    });
  });

  form.querySelectorAll('.qty-input').forEach(input => {
    input.addEventListener('input', () => {
      recalc();
      scheduleSync(500);
    });
    input.addEventListener('change', () => {
      recalc();
      scheduleSync(100);
    });
  });

  form.querySelectorAll('.cart-obs').forEach(input => {
    input.addEventListener('change', () => scheduleSync(150));
  });

  if (checkoutEl) {
    checkoutEl.addEventListener('click', async (ev) => {
      ev.preventDefault();
      const href = checkoutEl.href;
      const ok = await syncNow();
      if (ok) window.location.href = href;
    });
  }

  recalc();
})();
</script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
