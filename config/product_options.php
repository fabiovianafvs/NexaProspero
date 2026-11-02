<?php
declare(strict_types=1);

/**
 * Grupos de adicionais/opções por produto.
 *
 * Exemplo de uso:
 * - Grupo "Escolha a carne": tipo single, obrigatório, min=1, max=1
 * - Grupo "Acompanhamentos": tipo multiple, obrigatório, min=0, max=3
 */

if (!function_exists('product_options_ensure_schema')) {
  function product_options_ensure_schema(PDO $pdo): void {
    // V11.25: no F11 o Pedido PDV usa apenas a estrutura já homologada.
    if (!empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS produto_opcao_grupos (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NOT NULL,
      produto_id INT UNSIGNED NOT NULL,
      titulo VARCHAR(120) NOT NULL,
      tipo ENUM('single','multiple') NOT NULL DEFAULT 'single',
      obrigatorio TINYINT(1) NOT NULL DEFAULT 0,
      min_escolhas INT NOT NULL DEFAULT 0,
      max_escolhas INT NOT NULL DEFAULT 1,
      sort_order INT NOT NULL DEFAULT 0,
      ativo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_produto_opcao_grupos_empresa_produto (company_id, produto_id),
      KEY idx_produto_opcao_grupos_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS produto_opcao_itens (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NOT NULL,
      grupo_id INT UNSIGNED NOT NULL,
      nome VARCHAR(140) NOT NULL,
      preco DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      sort_order INT NOT NULL DEFAULT 0,
      ativo TINYINT(1) NOT NULL DEFAULT 1,
      created_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at TIMESTAMP NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      KEY idx_produto_opcao_itens_empresa_grupo (company_id, grupo_id),
      KEY idx_produto_opcao_itens_ativo (ativo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('product_options_money_to_float')) {
  function product_options_money_to_float(string $value): float {
    $value = trim($value);
    if ($value === '') return 0.0;
    $value = str_replace(['R$', ' '], '', $value);
    if (str_contains($value, ',') && str_contains($value, '.')) {
      $value = str_replace('.', '', $value);
      $value = str_replace(',', '.', $value);
    } else {
      $value = str_replace(',', '.', $value);
    }
    return is_numeric($value) ? (float)$value : 0.0;
  }
}

if (!function_exists('product_options_load')) {
  function product_options_load(PDO $pdo, int $companyId, int $produtoId, bool $activeOnly = true): array {
    if ($companyId <= 0 || $produtoId <= 0) return [];
    product_options_ensure_schema($pdo);

    $where = 'company_id = ? AND produto_id = ?';
    $params = [$companyId, $produtoId];
    if ($activeOnly) $where .= ' AND ativo = 1';

    $st = $pdo->prepare("SELECT * FROM produto_opcao_grupos WHERE {$where} ORDER BY sort_order ASC, id ASC");
    $st->execute($params);
    $groups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$groups) return [];

    $groupIds = array_values(array_filter(array_map(static fn($g) => (int)($g['id'] ?? 0), $groups)));
    if (!$groupIds) return [];

    $in = implode(',', array_fill(0, count($groupIds), '?'));
    $paramsItems = array_merge([$companyId], $groupIds);
    $whereItems = "company_id = ? AND grupo_id IN ({$in})";
    if ($activeOnly) $whereItems .= ' AND ativo = 1';

    $sti = $pdo->prepare("SELECT * FROM produto_opcao_itens WHERE {$whereItems} ORDER BY grupo_id ASC, sort_order ASC, id ASC");
    $sti->execute($paramsItems);
    $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byGroup = [];
    foreach ($items as $it) {
      $gid = (int)($it['grupo_id'] ?? 0);
      if (!isset($byGroup[$gid])) $byGroup[$gid] = [];
      $byGroup[$gid][] = $it;
    }

    $out = [];
    foreach ($groups as $g) {
      $gid = (int)($g['id'] ?? 0);
      $g['items'] = $byGroup[$gid] ?? [];
      // No catálogo, grupos sem itens ativos não devem aparecer nem exigir escolha.
      if ($activeOnly && empty($g['items'])) continue;
      $out[] = $g;
    }

    return $out;
  }
}


if (!function_exists('product_options_load_map')) {
  function product_options_load_map(PDO $pdo, int $companyId, array $produtoIds, bool $activeOnly = true): array {
    if ($companyId <= 0) return [];
    $produtoIds = array_values(array_unique(array_filter(array_map('intval', $produtoIds), static fn($v) => $v > 0)));
    if (!$produtoIds) return [];
    product_options_ensure_schema($pdo);

    $inProd = implode(',', array_fill(0, count($produtoIds), '?'));
    $params = array_merge([$companyId], $produtoIds);
    $where = "company_id = ? AND produto_id IN ({$inProd})";
    if ($activeOnly) $where .= ' AND ativo = 1';

    $st = $pdo->prepare("SELECT * FROM produto_opcao_grupos WHERE {$where} ORDER BY produto_id ASC, sort_order ASC, id ASC");
    $st->execute($params);
    $groups = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$groups) return [];

    $groupIds = array_values(array_filter(array_map(static fn($g) => (int)($g['id'] ?? 0), $groups)));
    if (!$groupIds) return [];

    $inGroup = implode(',', array_fill(0, count($groupIds), '?'));
    $paramsItems = array_merge([$companyId], $groupIds);
    $whereItems = "company_id = ? AND grupo_id IN ({$inGroup})";
    if ($activeOnly) $whereItems .= ' AND ativo = 1';

    $sti = $pdo->prepare("SELECT * FROM produto_opcao_itens WHERE {$whereItems} ORDER BY grupo_id ASC, sort_order ASC, id ASC");
    $sti->execute($paramsItems);
    $items = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $byGroup = [];
    foreach ($items as $it) {
      $gid = (int)($it['grupo_id'] ?? 0);
      if (!isset($byGroup[$gid])) $byGroup[$gid] = [];
      $byGroup[$gid][] = $it;
    }

    $out = [];
    foreach ($groups as $g) {
      $pid = (int)($g['produto_id'] ?? 0);
      $gid = (int)($g['id'] ?? 0);
      $g['items'] = $byGroup[$gid] ?? [];
      if ($activeOnly && empty($g['items'])) continue;
      if (!isset($out[$pid])) $out[$pid] = [];
      $out[$pid][] = $g;
    }
    return $out;
  }
}

if (!function_exists('product_options_save_from_post')) {
  function product_options_save_from_post(PDO $pdo, int $companyId, int $produtoId, array $post): void {
    if ($companyId <= 0 || $produtoId <= 0) return;
    product_options_ensure_schema($pdo);

    $pdo->prepare("DELETE i FROM produto_opcao_itens i INNER JOIN produto_opcao_grupos g ON g.id = i.grupo_id WHERE g.company_id = ? AND g.produto_id = ?")->execute([$companyId, $produtoId]);
    $pdo->prepare("DELETE FROM produto_opcao_grupos WHERE company_id = ? AND produto_id = ?")->execute([$companyId, $produtoId]);

    $keys = (array)($post['option_group_key'] ?? []);
    $titles = (array)($post['option_group_title'] ?? []);
    $types = (array)($post['option_group_type'] ?? []);
    $requireds = (array)($post['option_group_required'] ?? []);
    $mins = (array)($post['option_group_min'] ?? []);
    $maxs = (array)($post['option_group_max'] ?? []);
    $sorts = (array)($post['option_group_sort'] ?? []);
    $actives = (array)($post['option_group_active'] ?? []);

    $itemNames = (array)($post['option_item_name'] ?? []);
    $itemPrices = (array)($post['option_item_price'] ?? []);
    $itemSorts = (array)($post['option_item_sort'] ?? []);
    $itemActives = (array)($post['option_item_active'] ?? []);

    $orderAuto = 0;
    foreach ($keys as $rawKey) {
      $key = (string)$rawKey;
      if ($key === '') continue;

      $titulo = trim((string)($titles[$key] ?? ''));
      if ($titulo === '') continue;

      $tipo = (string)($types[$key] ?? 'single');
      if (!in_array($tipo, ['single','multiple'], true)) $tipo = 'single';

      $obrigatorio = (int)($requireds[$key] ?? 0) === 1 ? 1 : 0;
      $min = max(0, (int)($mins[$key] ?? ($obrigatorio ? 1 : 0)));
      $max = max(0, (int)($maxs[$key] ?? ($tipo === 'single' ? 1 : 0)));
      if ($tipo === 'single') {
        $max = 1;
        if ($obrigatorio && $min < 1) $min = 1;
      }
      if ($max > 0 && $min > $max) $min = $max;

      $sort = (int)($sorts[$key] ?? (++$orderAuto));
      $ativo = (int)($actives[$key] ?? 1) === 1 ? 1 : 0;

      $stg = $pdo->prepare("INSERT INTO produto_opcao_grupos (company_id, produto_id, titulo, tipo, obrigatorio, min_escolhas, max_escolhas, sort_order, ativo) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
      $stg->execute([$companyId, $produtoId, $titulo, $tipo, $obrigatorio, $min, $max, $sort, $ativo]);
      $grupoId = (int)$pdo->lastInsertId();

      $names = (array)($itemNames[$key] ?? []);
      $prices = (array)($itemPrices[$key] ?? []);
      $sortItems = (array)($itemSorts[$key] ?? []);
      $activeItems = (array)($itemActives[$key] ?? []);

      $sti = $pdo->prepare("INSERT INTO produto_opcao_itens (company_id, grupo_id, nome, preco, sort_order, ativo) VALUES (?, ?, ?, ?, ?, ?)");
      foreach ($names as $idx => $nameRaw) {
        $nome = trim((string)$nameRaw);
        if ($nome === '') continue;
        $preco = product_options_money_to_float((string)($prices[$idx] ?? '0'));
        $sortItem = (int)($sortItems[$idx] ?? ((int)$idx + 1));
        $ativoItem = (int)($activeItems[$idx] ?? 1) === 1 ? 1 : 0;
        $sti->execute([$companyId, $grupoId, $nome, $preco, $sortItem, $ativoItem]);
      }
    }
  }
}

if (!function_exists('product_options_validate_selection')) {
  function product_options_validate_selection(PDO $pdo, int $companyId, int $produtoId, array $posted, ?string &$error = null): array {
    $error = null;
    $groups = product_options_load($pdo, $companyId, $produtoId, true);
    if (!$groups) return ['groups' => [], 'total' => 0.0, 'summary' => ''];

    $details = [];
    $total = 0.0;
    $summaryLines = [];

    foreach ($groups as $g) {
      $gid = (int)($g['id'] ?? 0);
      $title = trim((string)($g['titulo'] ?? 'Opção'));
      $type = (string)($g['tipo'] ?? 'single');
      $required = ((int)($g['obrigatorio'] ?? 0) === 1);
      $min = max(0, (int)($g['min_escolhas'] ?? 0));
      $max = max(0, (int)($g['max_escolhas'] ?? 0));
      if ($required && $min < 1) $min = 1;
      if ($type === 'single') $max = 1;

      $raw = $posted[$gid] ?? [];
      if (!is_array($raw)) $raw = [$raw];
      $selectedIds = array_values(array_unique(array_filter(array_map('intval', $raw), static fn($v) => $v > 0)));

      $allowed = [];
      foreach ((array)($g['items'] ?? []) as $it) {
        $allowed[(int)$it['id']] = $it;
      }

      $selected = [];
      foreach ($selectedIds as $itemId) {
        if (isset($allowed[$itemId])) $selected[] = $allowed[$itemId];
      }

      $count = count($selected);
      if ($count < $min) {
        $error = $min <= 1
          ? 'Selecione uma opção em "' . $title . '".'
          : 'Selecione pelo menos ' . $min . ' opções em "' . $title . '".';
        return ['groups' => [], 'total' => 0.0, 'summary' => ''];
      }
      if ($max > 0 && $count > $max) {
        $error = 'Selecione no máximo ' . $max . ' opção(ões) em "' . $title . '".';
        return ['groups' => [], 'total' => 0.0, 'summary' => ''];
      }

      $itemsOut = [];
      foreach ($selected as $it) {
        $price = (float)($it['preco'] ?? 0);
        $total += $price;
        $itemsOut[] = [
          'id' => (int)$it['id'],
          'name' => (string)$it['nome'],
          'price' => $price,
        ];
      }

      if ($itemsOut) {
        $line = $title . ': ' . implode(', ', array_map(static function(array $i): string {
          $txt = (string)$i['name'];
          if ((float)$i['price'] > 0) $txt .= ' +' . money((float)$i['price']);
          return $txt;
        }, $itemsOut));
        $summaryLines[] = $line;
      }

      $details[] = [
        'group_id' => $gid,
        'title' => $title,
        'type' => $type,
        'items' => $itemsOut,
      ];
    }

    return [
      'groups' => $details,
      'total' => $total,
      'summary' => implode("\n", $summaryLines),
    ];
  }
}

if (!function_exists('product_options_summary_lines')) {
  function product_options_summary_lines(array $details): array {
    $lines = [];
    foreach ((array)($details['groups'] ?? []) as $group) {
      $title = trim((string)($group['title'] ?? 'Opção'));
      $items = [];
      foreach ((array)($group['items'] ?? []) as $it) {
        $txt = trim((string)($it['name'] ?? ''));
        if ($txt === '') continue;
        if ((float)($it['price'] ?? 0) > 0) $txt .= ' +' . money((float)$it['price']);
        $items[] = $txt;
      }
      if ($items) $lines[] = $title . ': ' . implode(', ', $items);
    }
    return $lines;
  }
}

if (!function_exists('product_options_text')) {
  function product_options_text(array $details): string {
    return implode("\n", product_options_summary_lines($details));
  }
}
