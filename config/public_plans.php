<?php
declare(strict_types=1);

/**
 * Planos públicos oficiais da Nexa Próspero.
 *
 * A Central SaaS pode ter outros planos cadastrados para histórico, testes ou uso interno.
 * As páginas públicas devem exibir somente estes quatro planos.
 */

if (!function_exists('public_plan_definitions')) {
  function public_plan_definitions(): array {
    return [
      'trial' => [
        'public_key' => 'trial',
        'name' => 'Start Free',
        'slug' => 'trial',
        'headline' => 'Start Free',
        'summary' => 'Comece com 7 dias grátis e valide sua operação sem custo.',
        'price' => 0.00,
        'original_price' => null,
        'max_orders' => 30,
        'trial_days' => 7,
        'billing_days' => 30,
        'badge_text' => 'Teste',
        'button_label' => 'Quero testar grátis',
        'sort_order' => 1,
        'is_featured' => 0,
      ],
      'essencial' => [
        'public_key' => 'essencial',
        'name' => 'Delivery Start',
        'slug' => 'essencial',
        'headline' => 'Delivery Start',
        'summary' => 'Até 100 pedidos por mês.',
        'price' => 59.90,
        'original_price' => 79.90,
        'max_orders' => 100,
        'trial_days' => 0,
        'billing_days' => 30,
        'badge_text' => 'Mais vendido',
        'button_label' => 'Assinar plano Essencial',
        'sort_order' => 2,
        'is_featured' => 1,
      ],
      'profissional' => [
        'public_key' => 'profissional',
        'name' => 'Delivery Pro',
        'slug' => 'profissional',
        'headline' => 'Delivery Pro',
        'summary' => 'De 101 a 500 pedidos por mês.',
        'price' => 139.90,
        'original_price' => 159.90,
        'max_orders' => 500,
        'trial_days' => 0,
        'billing_days' => 30,
        'badge_text' => 'Escala',
        'button_label' => 'Falar sobre esse plano',
        'sort_order' => 3,
        'is_featured' => 0,
      ],
      'enterprise' => [
        'public_key' => 'enterprise',
        'name' => 'Delivery Premium',
        'slug' => 'enterprise',
        'headline' => 'Delivery Premium',
        'summary' => 'De 501 a 1000 pedidos por mês.',
        'price' => 199.00,
        'original_price' => 229.90,
        'max_orders' => 1000,
        'trial_days' => 0,
        'billing_days' => 30,
        'badge_text' => 'Premium',
        'button_label' => 'Quero escalar meu delivery',
        'sort_order' => 4,
        'is_featured' => 0,
      ],
    ];
  }
}

if (!function_exists('public_plan_normalize_text')) {
  function public_plan_normalize_text(string $value): string {
    $value = trim(mb_strtolower($value, 'UTF-8'));
    $from = ['á','à','ã','â','ä','é','è','ê','ë','í','ì','î','ï','ó','ò','õ','ô','ö','ú','ù','û','ü','ç'];
    $to = ['a','a','a','a','a','e','e','e','e','i','i','i','i','o','o','o','o','o','u','u','u','u','c'];
    return str_replace($from, $to, $value);
  }
}

if (!function_exists('public_plan_key')) {
  function public_plan_key(array $plan): string {
    $slug = public_plan_normalize_text((string)($plan['slug'] ?? ''));
    $name = public_plan_normalize_text((string)($plan['name'] ?? ''));
    $headline = public_plan_normalize_text((string)($plan['headline'] ?? ''));
    $hay = trim($slug . ' ' . $name . ' ' . $headline);

    if (in_array($slug, ['trial','start-free','startfree'], true)) return 'trial';
    if ($slug === 'essencial') return 'essencial';
    if ($slug === 'profissional') return 'profissional';
    if ($slug === 'enterprise') return 'enterprise';

    if (str_contains($hay, 'start free') || str_contains($hay, 'trial') || str_contains($hay, 'teste')) return 'trial';
    if (str_contains($hay, 'essencial') || str_contains($hay, 'start promo')) return 'essencial';
    if (str_contains($hay, 'profissional')) return 'profissional';
    if (str_contains($hay, 'enterprise')) return 'enterprise';

    /*
     * Fallback para bancos antigos sem slug/headline.
     * Não mapeamos o preço 79,90 para nenhum plano, para impedir que o antigo plano "Mensal"
     * apareça nas páginas públicas.
     */
    $price = round((float)($plan['price'] ?? -1), 2);
    if ($price <= 0 && (int)($plan['trial_days'] ?? 0) > 0) return 'trial';
    if (abs($price - 59.90) < 0.01) return 'essencial';
    if (abs($price - 139.90) < 0.01) return 'profissional';
    if (abs($price - 199.90) < 0.01 || abs($price - 199.00) < 0.01) return 'enterprise';

    return '';
  }
}

if (!function_exists('public_plan_apply_defaults')) {
  function public_plan_apply_defaults(array $plan, ?string $key = null): array {
    $definitions = public_plan_definitions();
    $key = $key ?: public_plan_key($plan);
    if ($key === '' || !isset($definitions[$key])) return $plan;

    $definition = $definitions[$key];

    /*
     * Mantém o ID real do banco para cadastro, assinatura e cobrança,
     * mas padroniza a comunicação pública, ordem e valores exibidos.
     */
    $original = $plan;
    $id = $plan['id'] ?? null;
    $realSlug = trim((string)($plan['slug'] ?? ''));
    $plan = array_merge($plan, $definition);
    // Os textos oficiais continuam padronizados, mas preços e limites passam a vir do cadastro da Central SaaS.
    foreach (['price','original_price','max_orders','trial_days','billing_days','is_active','is_public','is_internal'] as $field) {
      if (array_key_exists($field, $original) && $original[$field] !== null) $plan[$field] = $original[$field];
    }
    if ($id !== null) $plan['id'] = $id;
    if ($realSlug !== '') $plan['slug'] = $realSlug;
    $plan['public_key'] = $key;
    $plan['public_slug'] = $definition['slug'];

    return $plan;
  }
}

if (!function_exists('public_plans_fetch')) {
  function public_plans_fetch(PDO $pdo): array {
    $rows = [];
    try {
      $rows = $pdo->query('SELECT * FROM plans WHERE is_active = 1')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      $rows = [];
    }

    $selected = [];
    foreach ($rows as $row) {
      $key = public_plan_key($row);
      if ($key === '') continue;

      $row = public_plan_apply_defaults($row, $key);

      if (!isset($selected[$key])) {
        $selected[$key] = $row;
        continue;
      }

      /*
       * Se houver duplicidade, prioriza o registro cujo slug bate com o plano oficial.
       */
      $currentSlug = public_plan_normalize_text((string)($selected[$key]['slug'] ?? ''));
      $newSlug = public_plan_normalize_text((string)($row['slug'] ?? ''));
      $officialSlug = public_plan_normalize_text((string)($row['public_slug'] ?? $key));

      $currentIsOfficial = $currentSlug === $officialSlug || ($key === 'trial' && in_array($currentSlug, ['trial','start-free','startfree'], true));
      $newIsOfficial = $newSlug === $officialSlug || ($key === 'trial' && in_array($newSlug, ['trial','start-free','startfree'], true));

      if (!$currentIsOfficial && $newIsOfficial) {
        $selected[$key] = $row;
      }
    }

    $ordered = [];
    foreach (array_keys(public_plan_definitions()) as $key) {
      if (isset($selected[$key])) $ordered[] = $selected[$key];
    }

    return $ordered;
  }
}

if (!function_exists('public_plans_default_rows')) {
  function public_plans_default_rows(): array {
    return array_values(public_plan_definitions());
  }
}
