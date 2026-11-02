<?php
declare(strict_types=1);
$prices = require __DIR__ . '/config/pricing.php';
$seller = (array)($prices['seller'] ?? []);
$brand = trim((string)($seller['name'] ?? 'Nexa Próspero')) ?: 'Nexa Próspero';
$systemBrand = 'Nexa Próspero';
$logo = trim((string)($seller['logo'] ?? '/assets/logo-nexa-prospero.jpg')) ?: '/assets/logo-nexa-prospero.jpg';
$brandPrimary = preg_match('/^#[0-9a-f]{6}$/i', (string)($seller['primary'] ?? '')) ? (string)$seller['primary'] : '#0b1f5b';
$brandSecondary = preg_match('/^#[0-9a-f]{6}$/i', (string)($seller['secondary'] ?? '')) ? (string)$seller['secondary'] : '#2454d6';
$brandMenuText = preg_match('/^#[0-9a-f]{6}$/i', (string)($seller['menu_text'] ?? '')) ? (string)$seller['menu_text'] : '#ffffff';
$hasGestao = !empty($prices['modules']['gestao']['active']);
$line = ($_GET['linha'] ?? '') === 'gestao' ? 'gestao' : (string)($_GET['linha'] ?? '');
if ($line === 'gestao' && !$hasGestao) $line = '';
$activeDeliveries = array_filter((array)($prices['delivery'] ?? []), static fn(array $p): bool => !empty($p['active']));
$activeModules = array_filter((array)($prices['modules'] ?? []), static fn(array $m): bool => !empty($m['active']));
$requestedModuleCodes = $_GET['modules'] ?? $_GET['nexa_modules'] ?? [];
if (!is_array($requestedModuleCodes)) $requestedModuleCodes = [$requestedModuleCodes];
$requestedModuleCodes = array_values(array_unique(array_filter(array_map(static fn($v): string => strtolower(trim((string)$v)), $requestedModuleCodes))));
$requestedModuleQuantities = $_GET['nexa_module_quantities'] ?? $_GET['module_quantities'] ?? [];
if (!is_array($requestedModuleQuantities)) $requestedModuleQuantities = [];
$requestedModuleQuantities = array_change_key_case($requestedModuleQuantities, CASE_LOWER);
$selectedDelivery = (string)($_GET['delivery'] ?? 'start');
if (!isset($activeDeliveries[$selectedDelivery])) $selectedDelivery = (string)(array_key_first($activeDeliveries) ?? 'start');
function nexa_public_money(float $v): string { return 'R$ '.number_format($v,2,',','.'); }
function nexa_public_quantity(array $module, array $requested, string $code): int {
  $min = max(1, (int)($module['quantity_min'] ?? 1));
  $max = max($min, (int)($module['quantity_max'] ?? 99));
  $default = max($min, min($max, (int)($module['quantity_default'] ?? 1)));
  return max($min, min($max, (int)($requested[$code] ?? $default)));
}
?><!doctype html>
<html lang="pt-BR" style="--brand-primary:<?= htmlspecialchars($brandPrimary, ENT_QUOTES, 'UTF-8') ?>;--brand-secondary:<?= htmlspecialchars($brandSecondary, ENT_QUOTES, 'UTF-8') ?>;--brand-menu-text:<?= htmlspecialchars($brandMenuText, ENT_QUOTES, 'UTF-8') ?>;"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Monte seu plano <?= htmlspecialchars($systemBrand, ENT_QUOTES, 'UTF-8') ?> | <?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></title><meta name="description" content="Monte seu plano Nexa Próspero, veja o valor e inicie seu teste.">
<link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet"><link rel="stylesheet" href="/assets/nexa-ux-v8.css?v=20260806-2">
<style>
.nexa-flow{--primary:var(--brand-secondary);--primary-dark:var(--brand-primary);--primary-2:var(--brand-secondary);--text:var(--brand-primary);--text-2:var(--brand-primary);--cyan:var(--brand-secondary);--shadow-strong:0 26px 70px color-mix(in srgb,var(--brand-secondary) 22%,transparent)}
.nexa-flow.is-reseller-brand .topbar{background:var(--brand-primary);background:color-mix(in srgb,var(--brand-primary) 94%,transparent);border-bottom-color:color-mix(in srgb,var(--brand-secondary) 44%,transparent)}
.nexa-flow.is-reseller-brand .brand strong,.nexa-flow.is-reseller-brand .brand span,.nexa-flow.is-reseller-brand .menu a:not(.btn){color:var(--brand-menu-text)}
.nexa-flow.is-reseller-brand .menu a:not(.btn):hover{background:color-mix(in srgb,var(--brand-secondary) 30%,transparent);color:var(--brand-menu-text)}
.nexa-flow .flow-head h1,.nexa-flow .plan-section-head h2,.nexa-flow .entry-card strong,.nexa-flow .delivery-card-title strong,.nexa-flow .delivery-price,.nexa-flow .addon-copy strong{color:var(--brand-primary)}
.nexa-flow .flow-step.active i,.nexa-flow .plan-section-number,.nexa-flow .addon-card .addon-select:checked + .addon-body .addon-check{background:var(--brand-secondary);border-color:var(--brand-secondary)}
.nexa-flow .entry-card input:checked + .entry-card-body,.nexa-flow .delivery-card input:checked + .delivery-card-body,.nexa-flow .addon-card .addon-select:checked + .addon-body{border-color:var(--brand-secondary);box-shadow:0 0 0 4px color-mix(in srgb,var(--brand-secondary) 12%,transparent),0 15px 35px color-mix(in srgb,var(--brand-secondary) 16%,transparent)}
.nexa-flow .addon-value{color:var(--brand-secondary)}
.nexa-flow .addon-card.has-quantity.is-selected{border-color:var(--brand-secondary);box-shadow:0 0 0 4px color-mix(in srgb,var(--brand-secondary) 12%,transparent),0 15px 35px color-mix(in srgb,var(--brand-secondary) 16%,transparent)}
.nexa-flow .addon-card.has-quantity>.addon-select:checked + .addon-body{border:0;box-shadow:none}
.nexa-flow .quantity-control button{color:var(--brand-primary)}
.nexa-flow .plan-recap{background:linear-gradient(155deg,var(--brand-primary) 0%,color-mix(in srgb,var(--brand-primary) 70%,var(--brand-secondary)) 58%,var(--brand-secondary) 100%)}
.nexa-flow .plan-recap .btn{color:var(--brand-primary)}
.nexa-flow .tag{color:var(--brand-primary);border-color:color-mix(in srgb,var(--brand-secondary) 22%,transparent)}
</style></head>
<body class="nexa-flow<?= !empty($seller['is_reseller']) ? ' is-reseller-brand' : '' ?>">
<header class="topbar"><div class="container nav"><a class="brand" href="/"><img class="brand-logo" src="<?= htmlspecialchars($logo, ENT_QUOTES, 'UTF-8') ?>" alt="<?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?>"><div class="brand-copy"><strong><?= htmlspecialchars($brand, ENT_QUOTES, 'UTF-8') ?></strong><span>Monte uma solução sob medida</span></div></a><nav class="menu"><a href="/">Início</a><a href="food.php">Food</a><a href="gestao.php">Gestão</a></nav></div></header>
<main>
<section class="flow-hero"><div class="container"><div class="flow-head"><span class="tag"><?= !empty($seller['is_reseller']) ? 'Proposta personalizada da revenda' : 'Plano sob medida e transparente' ?></span><h1>Monte sua solução.<br>Veja o valor na hora.</h1><p>Escolha a linha, o volume, os módulos e os serviços adicionais. Depois, cadastre sua empresa para testar gratuitamente durante 7 dias.</p><div class="flow-steps"><span class="flow-step active"><i>1</i> Monte seu plano</span><span class="flow-step-line"></span><span class="flow-step"><i>2</i> Cadastre a empresa</span><span class="flow-step-line"></span><span class="flow-step"><i>3</i> Teste por 7 dias</span></div></div></div></section>
<section class="plan-builder-shell"><div class="container"><form class="plan-builder" id="planBuilder" data-mobile-step="1" action="/cadastro.php" method="get">
<input type="hidden" name="nexa_source" value="<?= !empty($seller['is_reseller']) ? 'reseller_landing' : 'configurador' ?>">
<input type="hidden" id="nexaLine" name="nexa_line" value="<?= htmlspecialchars($line, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="nexaDelivery" name="nexa_delivery" value="<?= htmlspecialchars($selectedDelivery, ENT_QUOTES, 'UTF-8') ?>">
<input type="hidden" id="nexaDeliveryPlanId" name="nexa_delivery_plan_id" value="0"><span id="nexaModuleFields"></span>
<?php if (!empty($seller['is_reseller']) && !empty($seller['slug'])): ?><input type="hidden" name="revenda" value="<?= htmlspecialchars((string)$seller['slug'], ENT_QUOTES, 'UTF-8') ?>"><?php endif; ?>
<div class="mobile-wizard-progress" id="mobileWizardProgress" aria-live="polite" hidden><span id="mobileStepLabel">Passo 1 de 3</span><div class="mobile-progress-track"><span id="mobileProgressBar"></span></div></div>
<div class="plan-column">
<section class="plan-section" data-wizard-step="1"><div class="plan-section-head"><span class="plan-section-number">1</span><div><h2>Como sua empresa quer começar?</h2><p>Você poderá adicionar outros módulos e serviços agora ou posteriormente.</p></div></div><div class="entry-grid">
<?php if ($activeDeliveries): ?><label class="entry-card"><input type="radio" name="linha" value="food" <?= $line==='food'?'checked':'' ?>><span class="entry-card-body"><span class="entry-icon">🍔</span><span><strong><?= htmlspecialchars($systemBrand, ENT_QUOTES, 'UTF-8') ?> Food</strong><small>Comece pelo Delivery e evolua para uma gestão completa.</small></span></span></label><?php endif; ?>
<?php if ($hasGestao): ?><label class="entry-card"><input type="radio" name="linha" value="gestao" <?= $line==='gestao'?'checked':'' ?>><span class="entry-card-body"><span class="entry-icon">📊</span><span><strong><?= htmlspecialchars($systemBrand, ENT_QUOTES, 'UTF-8') ?> Gestão</strong><small>Comece pelo fiscal, estoque, caixa e financeiro.</small></span></span></label><?php endif; ?>
</div></section>
<?php if ($activeDeliveries): ?><section class="plan-section delivery-options" data-wizard-step="2"><div class="plan-section-head"><span class="plan-section-number">2</span><div><h2>Qual é o volume mensal do Delivery?</h2><p>Escolha a faixa atual. O plano pode ser ampliado quando o movimento crescer.</p></div></div><div class="delivery-grid">
<?php foreach($activeDeliveries as $key=>$p): $limit=(int)$p['min']===0?'Até '.(int)$p['max'].' pedidos':'De '.(int)$p['min'].' a '.(int)$p['max'].' pedidos'; ?>
<label class="delivery-card"><input type="radio" name="delivery" value="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" data-plan-id="<?= (int)($p['plan_id'] ?? 0) ?>" data-monthly="<?= (float)$p['promo'] ?>" data-setup="<?= (float)($p['setup'] ?? 0) ?>" <?= $selectedDelivery===$key?'checked':'' ?>><span class="delivery-card-body"><span class="delivery-card-title"><strong><?= htmlspecialchars((string)$p['name'], ENT_QUOTES, 'UTF-8') ?></strong><?= $key==='start'?'<span>OFERTA</span>':'' ?></span><span class="delivery-limit"><?= $limit ?>/mês</span><?php if(!empty($p['regular'])): ?><span class="delivery-old">De <?= nexa_public_money((float)$p['regular']) ?></span><?php endif; ?><strong class="delivery-price"><?= nexa_public_money((float)$p['promo']) ?><small>/mês</small></strong></span></label>
<?php endforeach; ?></div></section><?php endif; ?>
<section class="plan-section" data-wizard-step="3"><div class="plan-section-head"><span class="plan-section-number">3</span><div><h2>Complete sua solução</h2><p>Marque os módulos e serviços necessários para sua operação.</p></div></div><div class="addon-list">
<?php foreach($activeModules as $key=>$m):
  $monthly=(float)($m['monthly'] ?? 0);
  $setup=(float)($m['setup'] ?? 0);
  $icon=(string)($m['icon'] ?? (($m['item_type'] ?? 'module') === 'service' ? '🛠️' : '🧩'));
  $desc=trim((string)($m['description'] ?? '')) ?: ((($m['item_type'] ?? 'module') === 'service') ? 'Serviço adicional para a implantação da sua solução.' : 'Recurso adicional para completar a sua solução.');
  $allowQuantity=!empty($m['allow_quantity']);
  $quantity=nexa_public_quantity($m, $requestedModuleQuantities, (string)$key);
  $quantityLabel=trim((string)($m['quantity_label'] ?? 'unidades')) ?: 'unidades';
  $quantityMin=max(1,(int)($m['quantity_min'] ?? 1));
  $quantityMax=max($quantityMin,(int)($m['quantity_max'] ?? 99));
  $checked=in_array((string)$key, $requestedModuleCodes, true);
?>
<div class="addon-card<?= $allowQuantity ? ' has-quantity' : '' ?><?= $checked ? ' is-selected' : '' ?>">
  <input class="addon-select" id="addon-<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" type="checkbox" name="modules[]" value="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" data-module-code="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" data-monthly="<?= $monthly ?>" data-setup="<?= $setup ?>" data-allow-quantity="<?= $allowQuantity ? '1' : '0' ?>" <?= $checked ? 'checked' : '' ?>>
  <label class="addon-body" for="addon-<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>"><span class="addon-check">✓</span><span class="addon-copy"><strong><?= htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars((string)$m['name'], ENT_QUOTES, 'UTF-8') ?></strong><small><?= htmlspecialchars($desc, ENT_QUOTES, 'UTF-8') ?></small></span><span class="addon-value"><?= $monthly > 0 ? '+ '.nexa_public_money($monthly) : nexa_public_money($setup) ?><small><?= $allowQuantity ? ($monthly > 0 ? 'por mês / unidade' : 'valor único / unidade') : ($monthly > 0 ? 'por mês' : 'valor único') ?></small></span></label>
  <?php if($allowQuantity): ?>
    <div class="addon-quantity">
      <span>Quantidade de <?= htmlspecialchars($quantityLabel, ENT_QUOTES, 'UTF-8') ?></span>
      <div class="quantity-control">
        <button type="button" class="quantity-minus" aria-label="Diminuir quantidade">−</button>
        <input type="number" name="nexa_module_quantities[<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>]" value="<?= $quantity ?>" min="<?= $quantityMin ?>" max="<?= $quantityMax ?>" step="1" inputmode="numeric" data-quantity-for="<?= htmlspecialchars((string)$key, ENT_QUOTES, 'UTF-8') ?>" aria-label="Quantidade de <?= htmlspecialchars($quantityLabel, ENT_QUOTES, 'UTF-8') ?>">
        <button type="button" class="quantity-plus" aria-label="Aumentar quantidade">+</button>
      </div>
    </div>
  <?php endif; ?>
</div>
<?php endforeach; ?><?php if (!$activeModules): ?><div class="addon-card"><span class="addon-body"><span class="addon-copy"><strong>Nenhum item adicional disponível</strong><small>Os módulos e serviços públicos podem ser cadastrados na Central SaaS.</small></span></span></div><?php endif; ?></div></section>
</div>
<aside class="plan-recap" data-wizard-step="4"><span class="recap-eyebrow">Resumo da sua solução</span><h2 id="summaryTitle"><?= htmlspecialchars($systemBrand, ENT_QUOTES, 'UTF-8') ?></h2><p class="recap-subtitle">O valor muda automaticamente conforme suas escolhas.</p><div id="summaryItems" class="recap-items"></div><div class="recap-price-box"><span class="recap-label">Mensalidade após o teste</span><strong id="monthlyTotal" class="recap-monthly">R$ 0,00<small>/mês</small></strong><div class="recap-setup"><span>Implantação e serviços iniciais</span><strong id="setupTotal">R$ 0,00</strong></div></div><div class="trial-chip"><span class="trial-chip-icon">🎁</span><span><strong>7 dias grátis para testar</strong><span>Sem cartão e sem cobrança automática</span></span></div><button class="btn block" type="submit">Continuar para o cadastro →</button><p class="recap-safe">Você poderá revisar tudo antes da contratação.</p><a id="waQuote" class="recap-consultant" href="#" target="_blank" rel="noopener">Prefiro conversar com um especialista</a></aside>
<div class="mobile-plan-action" id="mobilePlanAction" aria-live="polite" hidden><button type="button" class="mobile-back" id="mobileBack" aria-label="Voltar para a etapa anterior">←</button><div class="mobile-action-value"><span id="mobileValueLabel">Valor atual</span><strong id="mobileCurrentValue">R$ 0,00/mês</strong></div><button type="button" class="mobile-next" id="mobileNext" disabled>Avançar →</button></div>
</form></div></section></main>
<script>
document.getElementById('planBuilder')?.addEventListener('submit', function(){
  const line = this.querySelector('input[name="linha"]:checked');
  const delivery = this.querySelector('input[name="delivery"]:checked');
  document.getElementById('nexaLine').value = line ? line.value : '';
  document.getElementById('nexaDelivery').value = delivery ? delivery.value : '';
  document.getElementById('nexaDeliveryPlanId').value = delivery ? (delivery.dataset.planId || '0') : '0';
  const holder = document.getElementById('nexaModuleFields'); holder.innerHTML = '';
  this.querySelectorAll('.addon-card .addon-select:checked').forEach(function(input){
    const hidden = document.createElement('input'); hidden.type = 'hidden'; hidden.name = 'nexa_modules[]'; hidden.value = input.dataset.moduleCode || input.value; holder.appendChild(hidden);
    input.disabled = true;
  });
});
window.NEXA_BUILDER={wa:'<?= htmlspecialchars((string)$prices['whatsapp'],ENT_QUOTES) ?>',seller:'<?= htmlspecialchars($brand,ENT_QUOTES) ?>',product:'<?= htmlspecialchars($systemBrand,ENT_QUOTES) ?>'};</script><script src="/assets/configurador-v8.js?v=20260806-2"></script></body></html>
