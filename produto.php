<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
products_send_dynamic_catalog_headers();
require_once __DIR__ . '/config/product_options.php';
require_once __DIR__ . '/config/product_half_pizza.php';

$id = (int)($_GET['id'] ?? 0);
$p = $id ? products_find_db($id) : null;
if ($p && (!empty($p['hide_order_catalog_product']) || products_category_hidden_db((string)($p['categoria'] ?? ''), 'order'))) {
  $p = null;
}

if (!$p) {
  http_response_code(404);
  $page_title = 'Produto não encontrado';
  require __DIR__ . '/_layout_top.php';
  echo '<div class="panel"><div class="p">Produto não encontrado.</div></div>';
  require __DIR__ . '/_layout_bottom.php';
  exit;
}

$page_title = $app['site_name'] . ' — ' . ((string)$p['nome'] ?: 'Produto');

$added = (int)($_GET['added'] ?? 0);
$categoryName = trim((string)($p['categoria'] ?? ''));
$halfPizzaReady = product_half_pizza_ensure_schema($pdo, false);
$halfPizzaCategories = $halfPizzaReady ? product_half_pizza_enabled_categories($pdo, COMPANY_ID) : [];
$isPizza = $halfPizzaReady && product_half_pizza_category_enabled($halfPizzaCategories, $categoryName);
$pizzas = [];
if ($isPizza) {
  $candidates = product_half_pizza_candidates($pdo, COMPANY_ID, [$categoryName]);
  $hiddenPizzaIds = [];
  if ($candidates && products_has_column($pdo, 'hide_order_catalog_product')) {
    $candidateIds = array_values(array_unique(array_filter(array_map(
      static fn(array $row): int => (int)($row['id'] ?? 0), $candidates
    ))));
    if ($candidateIds) {
      $ph = implode(',', array_fill(0, count($candidateIds), '?'));
      $stHidden = $pdo->prepare("SELECT id FROM produtos WHERE company_id=? AND COALESCE(hide_order_catalog_product,0)=1 AND id IN ({$ph})");
      $stHidden->execute(array_merge([COMPANY_ID], $candidateIds));
      $hiddenPizzaIds = array_fill_keys(array_map('intval', $stHidden->fetchAll(PDO::FETCH_COLUMN)), true);
    }
  }
  foreach ($candidates as $candidate) {
    if (product_half_pizza_normalize_category((string)($candidate['category'] ?? '')) !== product_half_pizza_normalize_category($categoryName)) continue;
    if (!empty($hiddenPizzaIds[(int)($candidate['id'] ?? 0)])) continue;
    $pizzas[] = [
      'id' => (int)($candidate['id'] ?? 0),
      'nome' => (string)($candidate['name'] ?? ''),
      'categoria' => (string)($candidate['category'] ?? ''),
      'preco' => (float)($candidate['price'] ?? 0),
    ];
  }
}
$optionGroups = product_options_load($pdo, COMPANY_ID, (int)$p['id'], true);
$productOptionsCfg = (array)($biz['product_options'] ?? []);
$showAdditionalUnitTotal = !empty($productOptionsCfg['show_additional_unit_total']);
$showItemTotalWithAdditionals = !empty($productOptionsCfg['show_item_total_with_additionals']);
$itemNoteInstruction = trim((string)($productOptionsCfg['item_note_instruction'] ?? 'Ex.: sem cebola, bem passada, retirar ingrediente...'));
$err = trim((string)($_GET['err'] ?? ''));

require __DIR__ . '/_layout_top.php';
?>

  <div class="breadcrumb">
    <a href="<?= asset_url('', $app) ?>">Início</a> / Produto
  </div>

  <div class="product">
    <div class="panel">
      <div class="thumb thumb-product thumb-product-detail">
        <img alt="<?= e((string)$p['nome']) ?>" src="<?= upload_url((string)(($p['imagem'] ?? '') ?: 'placeholder.svg'), $app) ?>">
      </div>
    </div>

    <div class="panel">
      <div class="p">

        <?php if ($added): ?>
          <div class="notice" style="margin-bottom:12px;">
            Adicionado ao carrinho ✅
            <div class="btn-group">
              <a class="btn" href="<?= asset_url('', $app) ?>">Continuar comprando</a>
              <a class="btn" href="<?= asset_url('carrinho.php', $app) ?>">Ver carrinho</a>
            </div>
          </div>
        <?php endif; ?>

        <?php if ($err !== ''): ?>
          <div class="notice" style="margin-bottom:12px;background:#fff7ed;border-color:#fed7aa;color:#9a3412;">
            <?= e($err) ?>
          </div>
        <?php endif; ?>

        <h2 style="margin:0 0 8px; font-size:22px;"><?= e((string)$p['nome']) ?></h2>
        <div class="muted" style="margin-bottom:10px;"><?= e((string)($p['categoria'] ?? '')) ?></div>
        <div class="price" style="font-size:22px; margin-bottom:12px;"><?= money((float)$p['preco']) ?></div>
        <div class="muted" style="line-height:1.55; font-size:14px;"><?= nl2br(e((string)($p['descricao'] ?? ''))) ?></div>

        <form id="productAddForm" method="post" action="<?= asset_url('cart_add.php', $app) ?>" class="product-actions product-form-grid" data-base-price="<?= e(number_format((float)$p['preco'], 2, '.', '')) ?>" data-show-additional-unit-total="<?= $showAdditionalUnitTotal ? '1' : '0' ?>" data-show-item-total-with-additionals="<?= $showItemTotalWithAdditionals ? '1' : '0' ?>">
          <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">

          <?php if ($isPizza && count($pizzas) > 1): ?>
            <div class="pizza-half-wrap">
              <label class="pizza-half-check">
                <input type="checkbox" id="chkHalf" name="use_half" value="1">
                <b>Quero pizza meio a meio</b>
              </label>

              <div id="halfBox" class="half-box" style="display:none;">
                <label class="half-label" for="half_id">Escolha o 2º sabor:</label>
                <select id="half_id" name="half_id" class="half-select field">
                  <option value="0">Selecione...</option>
                  <?php foreach ($pizzas as $pz): if ((int)$pz['id'] === (int)$p['id']) continue; ?>
                    <option value="<?= (int)$pz['id'] ?>" data-price="<?= e(number_format((float)$pz['preco'], 2, '.', '')) ?>"><?= e((string)$pz['nome']) ?> (<?= money((float)$pz['preco']) ?>)</option>
                  <?php endforeach; ?>
                </select>
                <div class="muted small half-note">Somente sabores da mesma categoria. Preço considerado: o maior entre os 2 sabores.</div>
              </div>
            </div>
          <?php endif; ?>

          <?php if ($optionGroups): ?>
            <div class="product-options-box">
              <div class="options-title">
                <strong>Escolha as opções do produto</strong>
                <span>Monte seu item do jeito que o cliente deseja.</span>
              </div>

              <?php foreach ($optionGroups as $group):
                $gid = (int)$group['id'];
                $type = (string)($group['tipo'] ?? 'single');
                $min = max(0, (int)($group['min_escolhas'] ?? 0));
                $max = max(0, (int)($group['max_escolhas'] ?? 0));
                $required = (int)($group['obrigatorio'] ?? 0) === 1;
                if ($required && $min < 1) $min = 1;
                if ($type === 'single') $max = 1;
              ?>
                <fieldset class="option-group"
                          data-type="<?= e($type) ?>"
                          data-min="<?= (int)$min ?>"
                          data-max="<?= (int)$max ?>"
                          data-title="<?= e((string)$group['titulo']) ?>">
                  <legend>
                    <span><?= e((string)$group['titulo']) ?></span>
                    <small>
                      <?php if ($type === 'single'): ?>
                        escolha 1 opção
                      <?php elseif ($max > 0): ?>
                        escolha até <?= (int)$max ?>
                      <?php else: ?>
                        escolha livremente
                      <?php endif; ?>
                      <?= $required ? ' • obrigatório' : ' • opcional' ?>
                    </small>
                  </legend>

                  <div class="option-items">
                    <?php foreach ((array)$group['items'] as $item):
                      $iid = (int)$item['id'];
                      $inputType = $type === 'single' ? 'radio' : 'checkbox';
                      $inputName = $type === 'single' ? 'add_options['.$gid.']' : 'add_options['.$gid.'][]';
                      $priceAdd = (float)($item['preco'] ?? 0);
                    ?>
                      <label class="option-item">
                        <input
                          type="<?= e($inputType) ?>"
                          name="<?= e($inputName) ?>"
                          value="<?= $iid ?>"
                          data-price="<?= e(number_format($priceAdd, 2, '.', '')) ?>"
                        >
                        <span>
                          <b><?= e((string)$item['nome']) ?></b>
                          <small class="option-price">
                            <span class="option-extra"><?= $priceAdd > 0 ? '+ ' . money($priceAdd) : 'sem acréscimo' ?></span>
                            <span class="option-final" hidden>Total: <?= money(((float)$p['preco']) + $priceAdd) ?></span>
                          </small>
                        </span>
                      </label>
                    <?php endforeach; ?>
                  </div>
                </fieldset>
              <?php endforeach; ?>

              <div class="options-total-line">
                <span>Adicionais selecionados</span>
                <strong id="optionsTotal">+ R$ 0,00</strong>
              </div>
              <?php if ($showItemTotalWithAdditionals): ?>
                <div class="options-total-line options-item-total-line">
                  <span>Total do item</span>
                  <strong id="optionsItemTotal"><?= money((float)$p['preco']) ?></strong>
                </div>
              <?php endif; ?>
            </div>
          <?php endif; ?>

          <div class="product-note-box">
            <label class="muted" for="item_obs" style="display:block; margin-bottom:6px;">Observação do item</label>
            <textarea id="item_obs" name="item_obs" class="field" rows="3" placeholder="<?= e($itemNoteInstruction) ?>"></textarea>
          </div>

          <div class="product-order-controls">
            <div class="qty-ctrl">
              <button class="qty-btn" type="button" data-action="dec" aria-label="Diminuir">−</button>
              <input class="qty-input" name="qtd" value="1" inputmode="numeric" pattern="[0-9]*">
              <button class="qty-btn" type="button" data-action="inc" aria-label="Aumentar">+</button>
            </div>
            <button class="btn btn-primary" type="submit">Adicionar ao carrinho</button>
            <a class="btn" href="<?= asset_url('carrinho.php', $app) ?>">Ver carrinho</a>
            <a class="btn btn-back" href="<?= asset_url('', $app) ?>">Voltar</a>
          </div>
        </form>

        <button
          id="floatingAddToCart"
          class="floating-add-to-cart"
          type="submit"
          form="productAddForm"
          hidden
          aria-label="Adicionar este produto ao carrinho"
        >Adicionar ao carrinho</button>


<style>
.product-options-box{display:grid;gap:14px;margin:16px 0;padding:16px;border:1px solid rgba(36,84,214,.16);background:linear-gradient(180deg,#f8fbff,#fff);border-radius:20px}
.options-title strong{display:block;color:#0b1f5b;font-size:16px}
.options-title span{display:block;color:#64748b;font-size:13px;margin-top:4px}
.option-group{border:1px solid #dbeafe;border-radius:18px;padding:14px;margin:0;background:#fff}
.option-group legend{display:flex;gap:10px;align-items:center;justify-content:space-between;flex-wrap:wrap;padding:0 6px;color:#0b1f5b;font-weight:900}
.option-group legend small{font-weight:700;color:#64748b}
.option-items{display:grid;gap:10px;margin-top:10px}
.option-item{display:flex;gap:10px;align-items:flex-start;padding:12px;border:1px solid #e5edf8;border-radius:16px;cursor:pointer;transition:all .18s ease;background:#fff}
.option-item:hover{border-color:#00b8ff;box-shadow:0 10px 22px rgba(0,184,255,.10)}
.option-item input{margin-top:3px}
.option-item span{display:flex;justify-content:space-between;gap:12px;width:100%;align-items:flex-start}
.option-item b{font-size:14px;color:#111827}
.option-item small{font-size:13px;color:#2454d6;font-weight:800;white-space:nowrap}
.option-price{display:flex;flex-direction:column;align-items:flex-end;line-height:1.2}
.option-final{font-size:11px;color:#64748b;font-weight:700;margin-top:3px}
.options-total-line{display:flex;justify-content:space-between;align-items:center;gap:12px;padding:12px 14px;border-radius:16px;background:#eef7ff;color:#0b1f5b;font-weight:850}
.options-item-total-line{background:#f0fdf4;color:#065f46}
.option-limit-toast{position:fixed;left:50%;top:50%;transform:translate(-50%,-50%) scale(.96);z-index:9999;max-width:min(88vw,520px);padding:16px 20px;border-radius:18px;background:#0f172a;color:#fff;box-shadow:0 24px 70px rgba(2,6,23,.38);font-weight:850;line-height:1.4;text-align:center;opacity:0;pointer-events:none;transition:opacity .18s ease,transform .18s ease}.option-limit-toast.show{opacity:1;transform:translate(-50%,-50%) scale(1)}.option-group.limit-hit{border-color:#f59e0b;box-shadow:0 0 0 3px rgba(245,158,11,.14)}
.product-order-controls .btn-primary{font-size:17px;font-weight:950}
.floating-add-to-cart{position:fixed;z-index:10000;right:200px;bottom:22px;min-height:58px;padding:0 22px;border:0;border-radius:999px;background:linear-gradient(135deg,var(--brand-primary,#2454d6),var(--brand-secondary,#00b8ff));color:#fff;font-size:16px;font-weight:950;line-height:1.1;box-shadow:0 18px 42px rgba(15,23,42,.22);cursor:pointer;white-space:nowrap;transition:transform .18s ease,filter .18s ease,opacity .18s ease}
.floating-add-to-cart:hover{transform:translateY(-2px);filter:brightness(1.04)}
.floating-add-to-cart[hidden]{display:none!important}
@media(max-width:760px){
  .product-order-controls .btn-primary{font-size:17px}
  .floating-add-to-cart{left:12px;right:156px;bottom:16px;min-height:52px;padding:0 12px;font-size:15.5px;white-space:normal;text-align:center}
}
@media(max-width:380px){
  .floating-add-to-cart{right:148px;font-size:15px;padding:0 9px}
}
</style>


<script>
  (function(){
    const root = document.querySelector('.product-actions');
    if(!root) return;
    const input = root.querySelector('.qty-input');
    root.querySelectorAll('.qty-btn').forEach(btn=>{
      btn.addEventListener('click', ()=>{
        const v = Math.max(1, parseInt(input.value||'1',10)||1);
        const next = btn.dataset.action==='inc' ? v+1 : Math.max(1, v-1);
        input.value = String(next);
        input.dispatchEvent(new Event('change'));
      });
    });

    const brMoney = value => {
      try { return value.toLocaleString('pt-BR', {style:'currency', currency:'BRL'}); }
      catch(e) { return 'R$ ' + (Math.round(value*100)/100).toFixed(2).replace('.', ','); }
    };
    const totalEl = document.getElementById('optionsTotal');
    const itemTotalEl = document.getElementById('optionsItemTotal');
    const showAdditionalUnitTotal = root.dataset.showAdditionalUnitTotal === '1';
    const showItemTotalWithAdditionals = root.dataset.showItemTotalWithAdditionals === '1';
    const basePrice = Number(root.dataset.basePrice || 0);
    const halfCheck = document.getElementById('chkHalf');
    const halfSelect = document.getElementById('half_id');
    const floatingAdd = document.getElementById('floatingAddToCart');

    let optionToastTimer = null;
    const showOptionWarning = (message, group = null) => {
      let toast = document.getElementById('optionLimitToast');
      if (!toast) {
        toast = document.createElement('div');
        toast.id = 'optionLimitToast';
        toast.className = 'option-limit-toast';
        toast.setAttribute('role', 'alert');
        toast.setAttribute('aria-live', 'assertive');
        document.body.appendChild(toast);
      }
      toast.textContent = message;
      toast.classList.remove('show');
      void toast.offsetWidth;
      toast.classList.add('show');
      if (group) {
        group.classList.add('limit-hit');
        window.setTimeout(() => group.classList.remove('limit-hit'), 1100);
      }
      if (optionToastTimer) window.clearTimeout(optionToastTimer);
      optionToastTimer = window.setTimeout(() => toast.classList.remove('show'), 2400);
    };

    // O botão flutuante só aparece quando todas as escolhas obrigatórias
    // necessárias para montar o produto já foram preenchidas. O botão
    // original do formulário permanece disponível e com a validação atual.
    const requiredOptionsReady = () => {
      for (const group of root.querySelectorAll('.option-group')) {
        const min = parseInt(group.dataset.min || '0', 10) || 0;
        const max = parseInt(group.dataset.max || '0', 10) || 0;
        const checked = group.querySelectorAll('input:checked').length;
        if (checked < min) return false;
        if (max > 0 && checked > max) return false;
      }

      // Ao optar por pizza meio a meio, o segundo sabor passa a ser
      // obrigatório antes de oferecer o atalho flutuante para adicionar.
      if (halfCheck && halfSelect && halfCheck.checked && (parseInt(halfSelect.value || '0', 10) || 0) <= 0) {
        return false;
      }

      return true;
    };

    const updateFloatingAdd = () => {
      if (!floatingAdd) return;
      floatingAdd.hidden = !requiredOptionsReady();
    };

    // Para pizza meio a meio, o preço unitário é sempre o maior entre os dois sabores.
    // Mantemos a mesma regra usada no carrinho já durante a montagem do item.
    const getEffectiveBasePrice = () => {
      if (!halfCheck || !halfSelect || !halfCheck.checked) return basePrice;
      const selected = halfSelect.options[halfSelect.selectedIndex];
      const halfPrice = Number(selected?.dataset?.price || 0);
      return Math.max(basePrice, Number.isFinite(halfPrice) ? halfPrice : 0);
    };

    const updateOptionsTotal = () => {
      const effectiveBasePrice = getEffectiveBasePrice();
      let total = 0;
      root.querySelectorAll('.product-options-box input').forEach(inp => {
        const price = parseFloat(inp.dataset.price || '0') || 0;
        const finalEl = inp.closest('.option-item')?.querySelector('.option-final');
        if (finalEl) {
          finalEl.textContent = 'Total: ' + brMoney(effectiveBasePrice + price);
          finalEl.hidden = !(showAdditionalUnitTotal && inp.checked);
        }
        if (inp.checked) total += price;
      });
      if (totalEl) totalEl.textContent = '+ ' + brMoney(total);
      if (itemTotalEl && showItemTotalWithAdditionals) itemTotalEl.textContent = brMoney(effectiveBasePrice + total);
    };
    root.querySelectorAll('.product-options-box input').forEach(inp => inp.addEventListener('change', () => {
      const group = inp.closest('.option-group');
      if (group && inp.checked) {
        const max = parseInt(group.dataset.max || '0', 10) || 0;
        const checked = group.querySelectorAll('input:checked').length;
        if (max > 0 && checked > max) {
          inp.checked = false;
          const title = group.dataset.title || 'opções';
          showOptionWarning('Você pode selecionar no máximo ' + max + ' opção(ões) em "' + title + '".', group);
        }
      }
      updateOptionsTotal();
      updateFloatingAdd();
    }));
    if (halfCheck) halfCheck.addEventListener('change', () => {
      updateOptionsTotal();
      updateFloatingAdd();
    });
    if (halfSelect) halfSelect.addEventListener('change', () => {
      updateOptionsTotal();
      updateFloatingAdd();
    });
    updateOptionsTotal();
    updateFloatingAdd();

    root.addEventListener('submit', function(ev){
      for (const group of root.querySelectorAll('.option-group')) {
        const title = group.dataset.title || 'opção';
        const min = parseInt(group.dataset.min || '0', 10) || 0;
        const max = parseInt(group.dataset.max || '0', 10) || 0;
        const checked = group.querySelectorAll('input:checked').length;
        if (checked < min) {
          ev.preventDefault();
          showOptionWarning(min <= 1 ? 'Selecione uma opção em "' + title + '".' : 'Selecione pelo menos ' + min + ' opções em "' + title + '".', group);
          return;
        }
        if (max > 0 && checked > max) {
          ev.preventDefault();
          showOptionWarning('Selecione no máximo ' + max + ' opção(ões) em "' + title + '".', group);
          return;
        }
      }
    });
  })();
</script>


        <div class="muted small" style="margin-top:12px;">
          Central (cadastros): <b>/admin</b>
        </div>
      </div>
    </div>
  </div>

  <script>
    (function(){
      const chk = document.getElementById('chkHalf');
      const box = document.getElementById('halfBox');
      const sel = document.getElementById('half_id');
      if (!chk || !box || !sel) return;
      const toggle = () => {
        box.style.display = chk.checked ? 'grid' : 'none';
        if (!chk.checked) sel.value = '0';
      };
      chk.addEventListener('change', toggle);
      toggle();
    })();
  </script>

<?php require __DIR__ . '/_layout_bottom.php'; ?>
