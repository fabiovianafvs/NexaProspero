<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * V11.36.3 - rotinas seguras para manutenções em lote.
 * Não executa DDL. Toda alteração deve chegar aqui já autenticada pelo admin.
 */

if (!function_exists('nexa_bulk_h')) {
  function nexa_bulk_h(mixed $value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
  }
}

if (!function_exists('nexa_bulk_csrf_token')) {
  function nexa_bulk_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['nexa_bulk_csrf']) || !is_string($_SESSION['nexa_bulk_csrf'])) {
      $_SESSION['nexa_bulk_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['nexa_bulk_csrf'];
  }
}

if (!function_exists('nexa_bulk_csrf_check')) {
  function nexa_bulk_csrf_check(string $token): void {
    $expected = nexa_bulk_csrf_token();
    if ($token === '' || !hash_equals($expected, $token)) {
      throw new RuntimeException('A sessão de segurança expirou. Atualize a página e tente novamente.');
    }
  }
}


if (!function_exists('nexa_bulk_flash_set')) {
  function nexa_bulk_flash_set(string $type, string $message): void {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $_SESSION['nexa_bulk_flash'] = ['type' => $type, 'message' => $message];
  }
}

if (!function_exists('nexa_bulk_flash_get')) {
  function nexa_bulk_flash_get(): array {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    $flash = $_SESSION['nexa_bulk_flash'] ?? [];
    unset($_SESSION['nexa_bulk_flash']);
    return is_array($flash) ? $flash : [];
  }
}

if (!function_exists('nexa_bulk_table_exists')) {
  function nexa_bulk_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) return false;
    $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
    $st->execute([$table]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('nexa_bulk_column_exists')) {
  function nexa_bulk_column_exists(PDO $pdo, string $table, string $column): bool {
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) return false;
    $st = $pdo->prepare('SELECT 1 FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=? AND column_name=? LIMIT 1');
    $st->execute([$table, $column]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('nexa_bulk_require_product_schema')) {
  function nexa_bulk_require_product_schema(PDO $pdo): void {
    if (!nexa_bulk_table_exists($pdo, 'produtos')) throw new RuntimeException('Tabela de produtos não encontrada.');
    foreach (['company_id','categoria','category_hide_order_catalog','category_hide_product_catalog'] as $column) {
      if (!nexa_bulk_column_exists($pdo, 'produtos', $column)) {
        throw new RuntimeException('A estrutura necessária para a manutenção em lote não está preparada: campo produtos.' . $column . ' ausente.');
      }
    }
  }
}

if (!function_exists('nexa_bulk_categories')) {
  /** @return array<int,array<string,mixed>> */
  function nexa_bulk_categories(PDO $pdo, int $companyId): array {
    nexa_bulk_require_product_schema($pdo);
    $sql = "SELECT TRIM(COALESCE(categoria,'')) AS categoria,
                   COUNT(*) AS total,
                   SUM(CASE WHEN COALESCE(ativo,0)=1 THEN 1 ELSE 0 END) AS ativos,
                   SUM(CASE WHEN COALESCE(category_hide_order_catalog,0)<>0 THEN 1 ELSE 0 END) AS ocultos_pedidos,
                   SUM(CASE WHEN COALESCE(category_hide_product_catalog,0)<>0 THEN 1 ELSE 0 END) AS ocultos_catalogo
            FROM produtos
            WHERE company_id=? AND TRIM(COALESCE(categoria,''))<>''
            GROUP BY BINARY TRIM(COALESCE(categoria,'')), TRIM(COALESCE(categoria,''))
            ORDER BY TRIM(COALESCE(categoria,'')) ASC";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('nexa_bulk_category_count')) {
  function nexa_bulk_category_count(PDO $pdo, int $companyId, string $category): int {
    $category = trim($category);
    if ($category === '') return 0;
    $st = $pdo->prepare("SELECT COUNT(*) FROM produtos WHERE company_id=? AND BINARY TRIM(COALESCE(categoria,'')) = BINARY ?");
    $st->execute([$companyId, $category]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('nexa_bulk_rename_category')) {
  function nexa_bulk_rename_category(PDO $pdo, int $companyId, string $from, string $to): int {
    nexa_bulk_require_product_schema($pdo);
    $from = trim($from);
    $to = trim($to);
    if ($from === '') throw new RuntimeException('Selecione a categoria atual.');
    if ($to === '') throw new RuntimeException('Informe o novo nome da categoria.');
    if ($from === $to) throw new RuntimeException('O novo nome é exatamente igual ao nome atual. Informe uma alteração ou mude maiúsculas/minúsculas.');
    if (mb_strlen($to) > 150) throw new RuntimeException('O novo nome da categoria é muito longo.');
    $count = nexa_bulk_category_count($pdo, $companyId, $from);
    if ($count <= 0) throw new RuntimeException('A categoria selecionada não possui produtos nesta empresa.');

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("UPDATE produtos SET categoria=? WHERE company_id=? AND BINARY TRIM(COALESCE(categoria,'')) = BINARY ?");
      $st->execute([$to, $companyId, $from]);
      $updated = $st->rowCount();
      if ($started) $pdo->commit();
      return $updated > 0 ? $updated : $count; // case-only rename may report 0 on some MySQL configurations.
    } catch (Throwable $e) {
      if ($started && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('nexa_bulk_category_visibility')) {
  function nexa_bulk_category_visibility(PDO $pdo, int $companyId, string $category, ?int $hideOrders, ?int $hideCatalog): int {
    nexa_bulk_require_product_schema($pdo);
    $category = trim($category);
    if ($category === '') throw new RuntimeException('Selecione a categoria.');
    if ($hideOrders === null && $hideCatalog === null) throw new RuntimeException('Escolha pelo menos uma alteração de visibilidade.');
    if ($hideOrders !== null && !in_array($hideOrders, [0,1], true)) throw new RuntimeException('Valor inválido para Catálogo de pedidos.');
    if ($hideCatalog !== null && !in_array($hideCatalog, [0,1], true)) throw new RuntimeException('Valor inválido para Catálogo de produtos.');
    if (nexa_bulk_category_count($pdo, $companyId, $category) <= 0) throw new RuntimeException('A categoria selecionada não possui produtos nesta empresa.');

    $sets = [];
    $params = [];
    if ($hideOrders !== null) { $sets[] = 'category_hide_order_catalog=?'; $params[] = $hideOrders; }
    if ($hideCatalog !== null) { $sets[] = 'category_hide_product_catalog=?'; $params[] = $hideCatalog; }
    $params[] = $companyId;
    $params[] = $category;

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
      $st = $pdo->prepare("UPDATE produtos SET " . implode(', ', $sets) . " WHERE company_id=? AND BINARY TRIM(COALESCE(categoria,'')) = BINARY ?");
      $st->execute($params);
      $updated = $st->rowCount();
      if ($started) $pdo->commit();
      return $updated;
    } catch (Throwable $e) {
      if ($started && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('nexa_bulk_tax_filters')) {
  function nexa_bulk_tax_filters(array $src): array {
    return [
      'q' => trim((string)($src['q'] ?? '')),
      'categoria' => trim((string)($src['categoria'] ?? '')),
      'ncm' => preg_replace('/\D+/', '', (string)($src['ncm'] ?? '')) ?: '',
      'tipo' => in_array(strtoupper(trim((string)($src['tipo'] ?? 'ALL'))), ['ALL','PRODUCT','SERVICE'], true) ? strtoupper(trim((string)($src['tipo'] ?? 'ALL'))) : 'ALL',
      'tax_profile_id' => max(0, (int)($src['tax_profile_id'] ?? 0)),
    ];
  }
}

if (!function_exists('nexa_bulk_tax_where')) {
  /** @return array{sql:string,params:array<int,mixed>} */
  function nexa_bulk_tax_where(PDO $pdo, int $companyId, array $filters): array {
    $where = ['p.company_id=?'];
    $params = [$companyId];
    $q = trim((string)($filters['q'] ?? ''));
    if ($q !== '') {
      $bulkParts = ['p.nome LIKE ?', 'CAST(p.id AS CHAR) = ?'];
      $params[] = nexa_search_like($q);
      $params[] = $q;
      if (nexa_bulk_column_exists($pdo, 'produtos', 'numero_cadastro')) { $bulkParts[] = 'p.numero_cadastro LIKE ?'; $params[] = nexa_search_like($q); }
      if (nexa_bulk_column_exists($pdo, 'produtos', 'referencia')) { $bulkParts[] = 'p.referencia LIKE ?'; $params[] = nexa_search_like($q); }
      $where[] = '(' . implode(' OR ', $bulkParts) . ')';
    }
    $cat = trim((string)($filters['categoria'] ?? ''));
    if ($cat !== '') {
      $where[] = "BINARY TRIM(COALESCE(p.categoria,'')) = BINARY ?";
      $params[] = $cat;
    }
    $ncm = preg_replace('/\D+/', '', (string)($filters['ncm'] ?? '')) ?: '';
    if ($ncm !== '') {
      $where[] = "REPLACE(REPLACE(COALESCE(ft.ncm,''),'.',''),'-','') LIKE ?";
      $params[] = nexa_search_like($ncm);
    }
    $type = strtoupper((string)($filters['tipo'] ?? 'ALL'));
    if ($type === 'PRODUCT') $where[] = 'COALESCE(p.is_service,0)=0';
    elseif ($type === 'SERVICE') $where[] = 'COALESCE(p.is_service,0)=1';
    $profileId = (int)($filters['tax_profile_id'] ?? 0);
    if ($profileId > 0) { $where[] = 'ft.tax_profile_id=?'; $params[] = $profileId; }
    return ['sql' => implode(' AND ', $where), 'params' => $params];
  }
}

if (!function_exists('nexa_bulk_tax_search')) {
  /** @return array{rows:array<int,array<string,mixed>>,total:int} */
  function nexa_bulk_tax_search(PDO $pdo, int $companyId, array $filters, int $limit = 500): array {
    if (!nexa_bulk_column_exists($pdo, 'produtos', 'is_service')) throw new RuntimeException('O cadastro de produtos ainda não possui o campo de Produto/Serviço esperado por esta manutenção.');
    if (!nexa_bulk_table_exists($pdo, 'fiscal_product_tax') || !nexa_bulk_table_exists($pdo, 'fiscal_tax_profiles')) {
      throw new RuntimeException('A estrutura fiscal necessária ainda não está disponível.');
    }
    $parts = nexa_bulk_tax_where($pdo, $companyId, $filters);
    $count = $pdo->prepare('SELECT COUNT(*) FROM produtos p LEFT JOIN fiscal_product_tax ft ON ft.company_id=p.company_id AND ft.product_id=p.id WHERE ' . $parts['sql']);
    $count->execute($parts['params']);
    $total = (int)$count->fetchColumn();

    $hasNum = nexa_bulk_column_exists($pdo, 'produtos', 'numero_cadastro');
    $sql = 'SELECT p.id, ' . ($hasNum ? 'p.numero_cadastro' : "'' AS numero_cadastro") . ', p.nome, COALESCE(p.categoria,\'\') AS categoria,
                   COALESCE(p.is_service,0) AS is_service, COALESCE(p.ativo,0) AS ativo,
                   COALESCE(ft.ncm,\'\') AS ncm, COALESCE(ft.cest,\'\') AS cest, COALESCE(ft.tax_profile_id,0) AS tax_profile_id,
                   COALESCE(tp.code,\'\') AS tax_profile_code, COALESCE(tp.name,\'\') AS tax_profile_name, COALESCE(tp.profile_type,\'\') AS tax_profile_type
            FROM produtos p
            LEFT JOIN fiscal_product_tax ft ON ft.company_id=p.company_id AND ft.product_id=p.id
            LEFT JOIN fiscal_tax_profiles tp ON tp.company_id=p.company_id AND tp.id=ft.tax_profile_id
            WHERE ' . $parts['sql'] . '
            ORDER BY p.nome ASC, p.id ASC LIMIT ' . max(1, min(1000, $limit));
    $st = $pdo->prepare($sql);
    $st->execute($parts['params']);
    return ['rows' => $st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'total' => $total];
  }
}

if (!function_exists('nexa_bulk_tax_filtered_ids')) {
  /** @return array<int,int> */
  function nexa_bulk_tax_filtered_ids(PDO $pdo, int $companyId, array $filters): array {
    $parts = nexa_bulk_tax_where($pdo, $companyId, $filters);
    $st = $pdo->prepare('SELECT p.id FROM produtos p LEFT JOIN fiscal_product_tax ft ON ft.company_id=p.company_id AND ft.product_id=p.id WHERE ' . $parts['sql'] . ' ORDER BY p.id');
    $st->execute($parts['params']);
    return array_values(array_filter(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), static fn(int $v): bool => $v > 0));
  }
}

if (!function_exists('nexa_bulk_tax_current_to_post')) {
  function nexa_bulk_tax_current_to_post(array $tax, bool $isService): array {
    $map = [
      'tax_profile_id'=>'fiscal_tax_profile_id','nbs_code'=>'fiscal_nbs_code','nbs_description'=>'fiscal_nbs_description',
      'lc116_service_code'=>'fiscal_lc116_service_code','lc116_service_description'=>'fiscal_lc116_service_description','iss_rate'=>'fiscal_iss_rate',
      'pis_cofins_retention_type'=>'fiscal_pis_cofins_retention_type','irrf_rate'=>'fiscal_irrf_rate','csll_rate'=>'fiscal_csll_rate','inss_rate'=>'fiscal_inss_rate',
      'municipal_tax_code'=>'fiscal_municipal_tax_code','cnae'=>'fiscal_cnae','nfse_municipal_tax_type'=>'fiscal_nfse_municipal_tax_type',
      'nfse_municipal_tax_nature'=>'fiscal_nfse_municipal_tax_nature','nfse_municipal_iss_exigibility'=>'fiscal_nfse_municipal_iss_exigibility',
      'nfse_municipal_special_regime'=>'fiscal_nfse_municipal_special_regime','nfse_national_tax_type'=>'fiscal_nfse_national_tax_type',
      'nfse_national_tax_code'=>'fiscal_nfse_national_tax_code','nfse_national_operation_indicator'=>'fiscal_nfse_national_operation_indicator',
      'nfse_simples_tax_regime'=>'fiscal_nfse_simples_tax_regime','nfse_operation_payment_type'=>'fiscal_nfse_operation_payment_type',
      'nfse_rtc_special_regime'=>'fiscal_nfse_rtc_special_regime','municipal_benefit_type'=>'fiscal_municipal_benefit_type',
      'municipal_differentiated_rate'=>'fiscal_municipal_differentiated_rate','municipal_reduction_base_percent'=>'fiscal_municipal_reduction_base_percent',
      'municipal_benefit_number'=>'fiscal_municipal_benefit_number','ibs_cbs_cst'=>'fiscal_ibs_cbs_cst',
      'ncm'=>'fiscal_ncm','cest'=>'fiscal_cest','origem'=>'fiscal_origem','tax_mode'=>'fiscal_tax_mode','icms_rate'=>'fiscal_icms_rate',
      'icms_st_rate'=>'fiscal_icms_st_rate','icms_cst'=>'fiscal_icms_cst','icms_csosn'=>'fiscal_icms_csosn','ipi_rate'=>'fiscal_ipi_rate','ipi_cst'=>'fiscal_ipi_cst',
      'freight_rate'=>'fiscal_freight_rate','pis_rate'=>'fiscal_pis_rate','pis_cst'=>'fiscal_pis_cst','cofins_rate'=>'fiscal_cofins_rate','cofins_cst'=>'fiscal_cofins_cst',
      'cbs_rate'=>'fiscal_cbs_rate','ibs_uf_rate'=>'fiscal_ibs_uf_rate','ibs_mun_rate'=>'fiscal_ibs_mun_rate','ibs_cbs_rate_mode'=>'fiscal_ibs_cbs_rate_mode',
      'ibs_cbs_classification_code'=>'fiscal_ibs_cbs_classification_code','ibs_cbs_classification_name'=>'fiscal_ibs_cbs_classification_name',
      'cfop_sale_state'=>'fiscal_cfop_sale_state','cfop_sale_interstate'=>'fiscal_cfop_sale_interstate','cfop_return_state'=>'fiscal_cfop_return_state',
      'cfop_return_interstate'=>'fiscal_cfop_return_interstate','cfop_entry_state'=>'fiscal_cfop_entry_state','cfop_entry_interstate'=>'fiscal_cfop_entry_interstate',
      'fiscal_notes'=>'fiscal_notes',
    ];
    $post = ['fiscal_item_type' => $isService ? 'SERVICE' : 'PRODUCT'];
    foreach ($map as $src => $dst) $post[$dst] = $tax[$src] ?? '';
    if (!empty($tax['iss_withheld'])) $post['fiscal_iss_withheld'] = '1';
    if (!empty($tax['fiscal_incentive'])) $post['fiscal_fiscal_incentive'] = '1';
    if (!empty($tax['is_monofasico'])) $post['fiscal_is_monofasico'] = '1';
    return $post;
  }
}

if (!function_exists('nexa_bulk_tax_apply_profile')) {
  function nexa_bulk_tax_apply_profile(array $post, array $profile): array {
    $map = [
      'profile_type'=>'fiscal_item_type','tax_mode'=>'fiscal_tax_mode','nbs_code'=>'fiscal_nbs_code','nbs_description'=>'fiscal_nbs_description',
      'lc116_service_code'=>'fiscal_lc116_service_code','lc116_service_description'=>'fiscal_lc116_service_description','iss_rate'=>'fiscal_iss_rate',
      'pis_cofins_retention_type'=>'fiscal_pis_cofins_retention_type','irrf_rate'=>'fiscal_irrf_rate','csll_rate'=>'fiscal_csll_rate','inss_rate'=>'fiscal_inss_rate',
      'municipal_tax_code'=>'fiscal_municipal_tax_code','cnae'=>'fiscal_cnae','nfse_municipal_tax_type'=>'fiscal_nfse_municipal_tax_type',
      'nfse_municipal_tax_nature'=>'fiscal_nfse_municipal_tax_nature','nfse_municipal_iss_exigibility'=>'fiscal_nfse_municipal_iss_exigibility',
      'nfse_municipal_special_regime'=>'fiscal_nfse_municipal_special_regime','nfse_national_tax_type'=>'fiscal_nfse_national_tax_type',
      'nfse_national_tax_code'=>'fiscal_nfse_national_tax_code','nfse_national_operation_indicator'=>'fiscal_nfse_national_operation_indicator',
      'nfse_simples_tax_regime'=>'fiscal_nfse_simples_tax_regime','nfse_operation_payment_type'=>'fiscal_nfse_operation_payment_type',
      'nfse_rtc_special_regime'=>'fiscal_nfse_rtc_special_regime','municipal_benefit_type'=>'fiscal_municipal_benefit_type',
      'municipal_differentiated_rate'=>'fiscal_municipal_differentiated_rate','municipal_reduction_base_percent'=>'fiscal_municipal_reduction_base_percent',
      'municipal_benefit_number'=>'fiscal_municipal_benefit_number','ibs_cbs_cst'=>'fiscal_ibs_cbs_cst',
      'ibs_cbs_classification_code'=>'fiscal_ibs_cbs_classification_code','ibs_cbs_classification_name'=>'fiscal_ibs_cbs_classification_name',
      'icms_rate'=>'fiscal_icms_rate','icms_st_rate'=>'fiscal_icms_st_rate','icms_cst'=>'fiscal_icms_cst','icms_csosn'=>'fiscal_icms_csosn','ipi_rate'=>'fiscal_ipi_rate','ipi_cst'=>'fiscal_ipi_cst',
      'pis_out_rate'=>'fiscal_pis_rate','pis_out_cst'=>'fiscal_pis_cst','cofins_out_rate'=>'fiscal_cofins_rate','cofins_out_cst'=>'fiscal_cofins_cst',
      'cbs_rate'=>'fiscal_cbs_rate','ibs_uf_rate'=>'fiscal_ibs_uf_rate','ibs_mun_rate'=>'fiscal_ibs_mun_rate','ibs_cbs_rate_mode'=>'fiscal_ibs_cbs_rate_mode',
      'cfop_sale_state'=>'fiscal_cfop_sale_state','cfop_sale_interstate'=>'fiscal_cfop_sale_interstate','cfop_return_state'=>'fiscal_cfop_return_state',
      'cfop_return_interstate'=>'fiscal_cfop_return_interstate','cfop_entry_state'=>'fiscal_cfop_entry_state','cfop_entry_interstate'=>'fiscal_cfop_entry_interstate',
    ];
    foreach ($map as $src => $dst) $post[$dst] = $profile[$src] ?? '';
    $post['fiscal_tax_profile_id'] = (int)($profile['id'] ?? 0);
    if (!empty($profile['iss_withheld'])) $post['fiscal_iss_withheld'] = '1'; else unset($post['fiscal_iss_withheld']);
    if (!empty($profile['fiscal_incentive'])) $post['fiscal_fiscal_incentive'] = '1'; else unset($post['fiscal_fiscal_incentive']);
    return $post;
  }
}

if (!function_exists('nexa_bulk_apply_tax_changes')) {
  /** @param array<int,int> $productIds */
  function nexa_bulk_apply_tax_changes(PDO $pdo, int $companyId, array $productIds, array $changes): array {
    if (!function_exists('fiscal_product_tax') || !function_exists('fiscal_validate_product_tax_post') || !function_exists('fiscal_save_product_tax_from_post')) {
      throw new RuntimeException('As rotinas fiscais do cadastro de produtos não estão carregadas.');
    }
    $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), static fn(int $v): bool => $v > 0)));
    if (!$ids) throw new RuntimeException('Selecione pelo menos um produto ou serviço.');

    $changeNcm = !empty($changes['change_ncm']);
    $changeCest = !empty($changes['change_cest']);
    $changeProfile = !empty($changes['change_profile']);
    if (!$changeNcm && !$changeCest && !$changeProfile) throw new RuntimeException('Marque pelo menos um campo para substituir.');

    $newNcm = preg_replace('/\D+/', '', (string)($changes['ncm'] ?? '')) ?: '';
    $newCest = preg_replace('/\D+/', '', (string)($changes['cest'] ?? '')) ?: '';
    if ($changeNcm && $newNcm !== '' && strlen($newNcm) !== 8) throw new RuntimeException('O NCM deve possuir 8 dígitos ou ficar vazio para limpar.');
    if ($changeCest && $newCest !== '' && strlen($newCest) !== 7) throw new RuntimeException('O CEST deve possuir 7 dígitos ou ficar vazio para limpar.');

    $profile = null;
    $profileId = max(0, (int)($changes['tax_profile_id'] ?? 0));
    if ($changeProfile) {
      if ($profileId <= 0) throw new RuntimeException('Selecione a tributação que será aplicada.');
      $profile = fiscal_tax_profile($pdo, $companyId, $profileId);
      if (!$profile) throw new RuntimeException('A tributação selecionada não pertence à empresa operacional.');
      $profile = array_merge($profile, function_exists('fiscal_profile_payload') ? fiscal_profile_payload($profile) : []);
    }

    $ph = implode(',', array_fill(0, count($ids), '?'));
    $stProducts = $pdo->prepare('SELECT id, nome, COALESCE(is_service,0) AS is_service FROM produtos WHERE company_id=? AND id IN (' . $ph . ') ORDER BY id FOR UPDATE');

    $started = !$pdo->inTransaction();
    if ($started) $pdo->beginTransaction();
    try {
      $stProducts->execute(array_merge([$companyId], $ids));
      $products = $stProducts->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if (count($products) !== count($ids)) throw new RuntimeException('Um ou mais itens selecionados não pertencem à empresa operacional. Atualize a pesquisa e tente novamente.');

      $errors = [];
      $changed = 0;
      $profileTypes = [];
      foreach ($products as $product) {
        $productId = (int)$product['id'];
        $isService = !empty($product['is_service']);
        if (($changeNcm || $changeCest) && $isService) {
          throw new RuntimeException('NCM e CEST são campos de produto. Filtre apenas Produtos para alterar esses campos; Serviços usam NBS/LC 116.');
        }
        if ($changeProfile && $profile) {
          $expected = $isService ? 'SERVICE' : 'PRODUCT';
          $actual = function_exists('fiscal_normalize_item_type') ? fiscal_normalize_item_type($profile['profile_type'] ?? 'PRODUCT') : strtoupper((string)($profile['profile_type'] ?? 'PRODUCT'));
          if ($expected !== $actual) {
            throw new RuntimeException('A tributação "' . (string)($profile['name'] ?? '') . '" não é compatível com o item #' . $productId . ' - ' . (string)$product['nome'] . '.');
          }
        }

        $tax = fiscal_product_tax($pdo, $companyId, $productId);
        $post = nexa_bulk_tax_current_to_post($tax, $isService);
        if ($changeProfile && $profile) $post = nexa_bulk_tax_apply_profile($post, $profile);
        if ($changeNcm) $post['fiscal_ncm'] = $newNcm;
        if ($changeCest) $post['fiscal_cest'] = $newCest;

        $validation = fiscal_validate_product_tax_post($pdo, $companyId, $post);
        if ($validation) {
          $errors[] = '#' . $productId . ' - ' . (string)$product['nome'] . ': ' . implode(' ', $validation);
          continue;
        }

        if ($changeProfile && nexa_bulk_table_exists($pdo, 'fiscal_product_operation_cfops')) {
          // Remove somente heranças do perfil antigo. Customizações do produto (inherited=0) ficam intactas.
          $pdo->prepare('DELETE FROM fiscal_product_operation_cfops WHERE company_id=? AND product_id=? AND inherited=1')->execute([$companyId, $productId]);
        }
        fiscal_save_product_tax_from_post($pdo, $companyId, $productId, $post);
        $changed++;
        if ($changeProfile && $profile) $profileTypes[$profileId] = $isService ? 'SERVICE' : 'PRODUCT';
      }

      if ($errors) throw new RuntimeException("A alteração foi cancelada porque alguns itens não passaram na validação fiscal:\n" . implode("\n", array_slice($errors, 0, 10)) . (count($errors) > 10 ? '\n...' : ''));

      // Reaplica as operações/CFOPs herdados do perfil apenas para perfis de PRODUTO.
      // A rotina nativa preserva personalizações individuais (inherited=0).
      foreach ($profileTypes as $pid => $ptype) {
        if ($ptype === 'PRODUCT' && function_exists('fiscal_sync_profile_operation_cfops_to_products')) {
          fiscal_sync_profile_operation_cfops_to_products($pdo, $companyId, (int)$pid);
        }
      }

      if ($started) $pdo->commit();
      return ['updated' => $changed, 'selected' => count($ids)];
    } catch (Throwable $e) {
      if ($started && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}
