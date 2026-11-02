<?php
declare(strict_types=1);

if (!function_exists('nexa_pricing_module_icon')) {
  function nexa_pricing_module_icon(string $code, string $type = 'module'): string {
    return match ($code) {
      'gestao' => '🧾',
      'comandas' => '📱',
      'impressao' => '🖨️',
      'financeiro' => '💰',
      'fiscal' => '📄',
      'api' => '🔌',
      'exportacao' => '🔄',
      default => $type === 'service' ? '🛠️' : '🧩',
    };
  }
}

$prices = [
  'whatsapp' => '5531999999999',
  'seller' => ['is_reseller'=>false,'company_id'=>0,'slug'=>'','name'=>'Nexa Próspero','logo'=>'/assets/logo-nexa-prospero.jpg','primary'=>'#0b1f5b','secondary'=>'#2454d6','menu_text'=>'#ffffff'],
  'delivery' => [
    'start' => ['name'=>'Delivery Start','min'=>0,'max'=>100,'regular'=>79.90,'promo'=>59.90,'setup'=>350.00,'cost'=>0.00,'setup_cost'=>0.00,'plan_id'=>0,'active'=>true],
    'pro' => ['name'=>'Delivery Pro','min'=>101,'max'=>500,'regular'=>159.90,'promo'=>139.90,'setup'=>350.00,'cost'=>0.00,'setup_cost'=>0.00,'plan_id'=>0,'active'=>true],
    'premium' => ['name'=>'Delivery Premium','min'=>501,'max'=>1000,'regular'=>229.90,'promo'=>199.00,'setup'=>350.00,'cost'=>0.00,'setup_cost'=>0.00,'plan_id'=>0,'active'=>true],
  ],
  'modules' => [
    'gestao' => ['name'=>'Gestão Fiscal','description'=>'Fiscal, estoque, compras, vendas, caixa, financeiro e relatórios','icon'=>'🧾','item_type'=>'module','monthly'=>189.90,'regular'=>249.90,'setup'=>375.00,'setup_regular'=>750.00,'cost'=>0.00,'setup_cost'=>0.00,'module_id'=>0,'allow_quantity'=>false,'quantity_label'=>'unidade','quantity_min'=>1,'quantity_max'=>99,'quantity_default'=>1,'active'=>true],
    'comandas' => ['name'=>'Comandas','description'=>'Mesas, pedidos pelo smartphone, transferências e fechamento','icon'=>'📱','item_type'=>'module','monthly'=>59.90,'regular'=>79.90,'setup'=>150.00,'setup_regular'=>250.00,'cost'=>0.00,'setup_cost'=>0.00,'module_id'=>0,'allow_quantity'=>false,'quantity_label'=>'unidade','quantity_min'=>1,'quantity_max'=>99,'quantity_default'=>1,'active'=>true],
    'impressao' => ['name'=>'Agente de Impressão','description'=>'Impressão automática na cozinha, bar, caixa e outros setores','icon'=>'🖨️','item_type'=>'module','monthly'=>29.90,'regular'=>29.90,'setup'=>0.00,'setup_regular'=>0.00,'cost'=>0.00,'setup_cost'=>0.00,'module_id'=>0,'allow_quantity'=>false,'quantity_label'=>'unidade','quantity_min'=>1,'quantity_max'=>99,'quantity_default'=>1,'active'=>true],
  ],
  'delivery_setup' => ['promo'=>350.00,'regular'=>550.00],
];

try {
  require_once __DIR__ . '/pdo.php';
  require_once __DIR__ . '/resellers.php';
  require_once __DIR__ . '/reseller_integrations.php';
  reseller_ensure_pricing_schema($pdo);

  $reseller = reseller_resolve_context($pdo);
  $resellerId = $reseller ? (int)$reseller['id'] : 0;
  if ($reseller) {
    $theme = reseller_theme_config($reseller);
    $prices['seller'] = [
      'is_reseller' => true,
      'company_id' => $resellerId,
      'slug' => (string)($reseller['subdomain'] ?? $reseller['slug'] ?? ''),
      'name' => (string)$theme['brand_name'],
      'logo' => reseller_theme_logo_url($reseller),
      'primary' => (string)$theme['primary'],
      'secondary' => (string)$theme['secondary'],
      'menu_text' => (string)$theme['menu_text'],
    ];
    $resellerWhatsapp = preg_replace('/\D+/', '', (string)($reseller['reseller_whatsapp'] ?? $reseller['whatsapp'] ?? '')) ?: '';
    if ($resellerWhatsapp !== '') $prices['whatsapp'] = $resellerWhatsapp;
  }

  foreach (reseller_plan_catalog($pdo, $resellerId, true) as $plan) {
    $target = (string)($plan['delivery_key'] ?? '');
    if ($target === '' || !isset($prices['delivery'][$target])) continue;
    $prices['delivery'][$target]['name'] = trim((string)($plan['name'] ?? '')) ?: $prices['delivery'][$target]['name'];
    $prices['delivery'][$target]['promo'] = (float)($plan['effective_sale_price'] ?? $plan['price'] ?? $prices['delivery'][$target]['promo']);
    $prices['delivery'][$target]['setup'] = (float)($plan['effective_setup_price'] ?? $plan['setup_price'] ?? $prices['delivery'][$target]['setup']);
    $prices['delivery'][$target]['cost'] = (float)($plan['monthly_cost'] ?? 0);
    $prices['delivery'][$target]['setup_cost'] = (float)($plan['setup_cost'] ?? 0);
    $prices['delivery'][$target]['plan_id'] = (int)($plan['id'] ?? 0);
    $prices['delivery'][$target]['active'] = !empty($plan['reseller_price_active']);
    if (($plan['original_price'] ?? null) !== null) $prices['delivery'][$target]['regular'] = (float)$plan['original_price'];
    if (($plan['max_orders'] ?? null) !== null) {
      $prices['delivery'][$target]['max'] = (int)$plan['max_orders'];
      $prices['delivery'][$target]['min'] = $target === 'start' ? 0 : ($target === 'pro' ? 101 : 501);
    }
  }

  // O banco passa a ser a fonte oficial quando a estrutura está disponível. Os valores
  // fixos acima ficam apenas como contingência caso a conexão falhe antes desta etapa.
  foreach ($prices['modules'] as &$fallbackModule) $fallbackModule['active'] = false;
  unset($fallbackModule);

  // Carrega todos os módulos e serviços ativos e respeita a opção "Exibir no configurador".
  // Assim, itens novos aparecem automaticamente e itens despublicados deixam de aparecer.
  foreach (reseller_module_catalog($pdo, $resellerId, false) as $row) {
    $code = strtolower(trim((string)($row['code'] ?? '')));
    if ($code === '' || $code === 'delivery') continue;
    $type = strtolower((string)($row['item_type'] ?? 'module')) === 'service' ? 'service' : 'module';
    $current = (array)($prices['modules'][$code] ?? []);
    $prices['modules'][$code] = array_merge($current, [
      'name' => trim((string)($row['name'] ?? '')) ?: ucfirst(str_replace(['-','_'], ' ', $code)),
      'description' => trim((string)($row['description'] ?? '')),
      'icon' => (string)($current['icon'] ?? nexa_pricing_module_icon($code, $type)),
      'item_type' => $type,
      'monthly' => (float)($row['effective_sale_price'] ?? $row['monthly_price'] ?? 0),
      'setup' => (float)($row['effective_setup_price'] ?? $row['setup_price'] ?? 0),
      'cost' => (float)($row['monthly_cost'] ?? 0),
      'setup_cost' => (float)($row['setup_cost'] ?? 0),
      'module_id' => (int)($row['id'] ?? 0),
      'allow_quantity' => !empty($row['allow_quantity']),
      'quantity_label' => trim((string)($row['quantity_label'] ?? '')) ?: 'unidade',
      'quantity_min' => max(1, (int)($row['quantity_min'] ?? 1)),
      'quantity_max' => max(1, (int)($row['quantity_max'] ?? 99)),
      'quantity_default' => max(1, (int)($row['quantity_default'] ?? 1)),
      'active' => !empty($row['reseller_price_active']) && !empty($row['is_active']) && !empty($row['is_public_sale']),
      'sort_order' => (int)($row['sort_order'] ?? 0),
    ]);
  }

  uasort($prices['modules'], static function (array $a, array $b): int {
    return [(int)($a['sort_order'] ?? 0), (string)($a['name'] ?? '')] <=> [(int)($b['sort_order'] ?? 0), (string)($b['name'] ?? '')];
  });

  $firstActiveDelivery = null;
  foreach ($prices['delivery'] as $delivery) if (!empty($delivery['active'])) { $firstActiveDelivery = $delivery; break; }
  if ($firstActiveDelivery) $prices['delivery_setup']['promo'] = (float)$firstActiveDelivery['setup'];
} catch (Throwable $e) {
  // Mantém o configurador disponível com os valores de fallback caso o banco esteja indisponível.
}

return $prices;
