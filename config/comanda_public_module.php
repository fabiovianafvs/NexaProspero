<?php
declare(strict_types=1);

require_once __DIR__ . '/comanda_module.php';
require_once __DIR__ . '/product_options.php';

if (!function_exists('comanda_public_h')) {
  function comanda_public_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
}

if (!function_exists('comanda_public_phone_digits')) {
  function comanda_public_phone_digits(string $value): string { return preg_replace('/\D+/', '', $value) ?: ''; }
}

if (!function_exists('comanda_public_catalog_data')) {
  function comanda_public_catalog_data(PDO $pdo, int $companyId): array {
    $products = comanda_products($pdo, $companyId);
    $ids = array_values(array_filter(array_map(static fn(array $p): int => (int)($p['id'] ?? 0), $products)));
    $options = $ids ? product_options_load_map($pdo, $companyId, $ids, true) : [];
    return ['products' => $products, 'options' => $options];
  }
}

if (!function_exists('comanda_public_validate_product_item')) {
  function comanda_public_validate_product_item(PDO $pdo, int $companyId, array $raw): array {
    $productId = (int)($raw['product_id'] ?? 0);
    $qty = (float)str_replace(',', '.', (string)($raw['qty'] ?? '1'));
    $qty = round($qty, 3);
    if ($productId <= 0) throw new RuntimeException('Um dos produtos informados é inválido.');
    if ($qty <= 0 || $qty > 99) throw new RuntimeException('Quantidade inválida para um dos produtos.');

    $extra = comanda_column_exists($pdo, 'produtos', 'grupo_impressao') ? ', grupo_impressao' : ', NULL AS grupo_impressao';
    $kitchenExtra = comanda_column_exists($pdo, 'produtos', 'imprimir_cozinha') ? ', COALESCE(imprimir_cozinha,1) AS imprimir_cozinha' : ', 1 AS imprimir_cozinha';
    $st = $pdo->prepare("SELECT id, nome, preco, categoria, grupo_producao{$extra}{$kitchenExtra} FROM produtos WHERE company_id=? AND id=? AND COALESCE(ativo,1)=1 LIMIT 1");
    $st->execute([$companyId, $productId]);
    $product = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$product) throw new RuntimeException('Um produto selecionado não está mais disponível. Atualize a página e tente novamente.');

    $groups = product_options_load($pdo, $companyId, $productId, true);
    $selectionRaw = (array)($raw['options'] ?? []);
    $selection = [];
    foreach ($selectionRaw as $groupKey => $idsRaw) {
      $gid = (int)$groupKey;
      $ids = is_array($idsRaw) ? $idsRaw : [$idsRaw];
      $selection[$gid] = array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id > 0)));
    }

    $extraPrice = 0.0;
    $optionLines = [];
    $knownGroupIds = [];
    foreach ($groups as $group) {
      $gid = (int)($group['id'] ?? 0);
      if ($gid <= 0) continue;
      $knownGroupIds[$gid] = true;
      $chosen = $selection[$gid] ?? [];
      $available = [];
      foreach ((array)($group['items'] ?? []) as $option) $available[(int)$option['id']] = $option;
      foreach ($chosen as $optionId) {
        if (!isset($available[$optionId])) throw new RuntimeException('Uma opção selecionada não está mais disponível.');
      }
      $count = count($chosen);
      $min = max((int)($group['min_escolhas'] ?? 0), !empty($group['obrigatorio']) ? 1 : 0);
      $max = max(0, (int)($group['max_escolhas'] ?? 0));
      $type = (string)($group['tipo'] ?? 'single');
      if ($type === 'single') $max = 1;
      if ($count < $min) throw new RuntimeException('Selecione ao menos ' . $min . ' opção(ões) em “' . (string)$group['titulo'] . '”.');
      if ($max > 0 && $count > $max) throw new RuntimeException('Selecione no máximo ' . $max . ' opção(ões) em “' . (string)$group['titulo'] . '”.');
      if (!$chosen) continue;
      $names = [];
      foreach ($chosen as $optionId) {
        $option = $available[$optionId];
        $extraPrice += (float)($option['preco'] ?? 0);
        $name = trim((string)($option['nome'] ?? ''));
        if ($name !== '') $names[] = $name;
      }
      if ($names) $optionLines[] = trim((string)($group['titulo'] ?? 'Opções')) . ': ' . implode(', ', $names);
    }
    foreach (array_keys($selection) as $gid) {
      if (!isset($knownGroupIds[(int)$gid])) throw new RuntimeException('Uma opção selecionada não pertence ao produto.');
    }

    $note = trim((string)($raw['note'] ?? ''));
    if (function_exists('mb_substr')) $note = mb_substr($note, 0, 500, 'UTF-8'); else $note = substr($note, 0, 500);
    $obsParts = $optionLines;
    if ($note !== '') $obsParts[] = 'Obs.: ' . $note;
    $unitPrice = round((float)($product['preco'] ?? 0) + $extraPrice, 2);

    return [
      'product_id' => $productId,
      'product' => $product,
      'qty' => $qty,
      'unit_price' => $unitPrice,
      'total' => round($qty * $unitPrice, 2),
      'observation' => implode("\n", $obsParts),
      'should_print' => ((int)($product['imprimir_cozinha'] ?? 1) === 1),
    ];
  }
}


if (!function_exists('comanda_public_cookie_name')) {
  function comanda_public_cookie_name(int $companyId, int $comandaId): string {
    return 'nexa_cmd_access_' . $companyId . '_' . $comandaId;
  }
}

if (!function_exists('comanda_public_b64url_encode')) {
  function comanda_public_b64url_encode(string $value): string {
    return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
  }
}

if (!function_exists('comanda_public_b64url_decode')) {
  function comanda_public_b64url_decode(string $value): string {
    $pad = strlen($value) % 4;
    if ($pad > 0) $value .= str_repeat('=', 4 - $pad);
    $decoded = base64_decode(strtr($value, '-_', '+/'), true);
    return $decoded === false ? '' : $decoded;
  }
}

if (!function_exists('comanda_public_access_cookie_value')) {
  function comanda_public_access_cookie_value(array $comanda, int $companyId, int $ttlSeconds = 43200): string {
    $payload = [
      'company_id' => $companyId,
      'comanda_id' => (int)($comanda['id'] ?? 0),
      'version' => max(1, (int)($comanda['public_session_version'] ?? 1)),
      'expires_at' => time() + max(900, $ttlSeconds),
      'nonce' => bin2hex(random_bytes(12)),
    ];
    $encoded = comanda_public_b64url_encode((string)json_encode($payload, JSON_UNESCAPED_SLASHES));
    $secret = (string)($comanda['public_token'] ?? '');
    $signature = hash_hmac('sha256', $encoded, $secret);
    return $encoded . '.' . $signature;
  }
}

if (!function_exists('comanda_public_validate_access_cookie')) {
  function comanda_public_validate_access_cookie(array $comanda, int $companyId, string $cookieValue): bool {
    if ($cookieValue === '' || !str_contains($cookieValue, '.')) return false;
    [$encoded, $signature] = explode('.', $cookieValue, 2);
    if ($encoded === '' || !preg_match('/^[a-f0-9]{64}$/', $signature)) return false;
    $secret = (string)($comanda['public_token'] ?? '');
    if ($secret === '' || !hash_equals(hash_hmac('sha256', $encoded, $secret), $signature)) return false;
    $payload = json_decode(comanda_public_b64url_decode($encoded), true);
    if (!is_array($payload)) return false;
    return (int)($payload['company_id'] ?? 0) === $companyId
      && (int)($payload['comanda_id'] ?? 0) === (int)($comanda['id'] ?? 0)
      && (int)($payload['version'] ?? 0) === max(1, (int)($comanda['public_session_version'] ?? 1))
      && (int)($payload['expires_at'] ?? 0) >= time();
  }
}

if (!function_exists('comanda_public_issue_access_cookie')) {
  function comanda_public_issue_access_cookie(array $comanda, int $companyId): void {
    $name = comanda_public_cookie_name($companyId, (int)$comanda['id']);
    $value = comanda_public_access_cookie_value($comanda, $companyId);
    setcookie($name, $value, [
      'expires' => time() + 43200,
      'path' => '/',
      'secure' => (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off'),
      'httponly' => true,
      'samesite' => 'Lax',
    ]);
  }
}

if (!function_exists('comanda_public_has_valid_access')) {
  function comanda_public_has_valid_access(array $comanda, int $companyId): bool {
    $name = comanda_public_cookie_name($companyId, (int)$comanda['id']);
    return comanda_public_validate_access_cookie($comanda, $companyId, (string)($_COOKIE[$name] ?? ''));
  }
}

if (!function_exists('comanda_public_create_order')) {
  function comanda_public_create_order(PDO $pdo, int $companyId, array $comanda, array $payload, string $remoteIp = '', string $userAgent = ''): array {
    comanda_ensure_schema($pdo);
    $settings = comanda_settings($pdo, $companyId);
    $freshComanda = comanda_find($pdo, $companyId, (int)($comanda['id'] ?? 0));
    if (!$freshComanda || !comanda_public_has_valid_access($freshComanda, $companyId)) {
      throw new RuntimeException('Esta sessão da mesa foi encerrada. Leia novamente o QR Code da mesa para fazer um novo pedido.');
    }
    $comanda = $freshComanda;
    if (empty($settings['public_qr_enabled'])) throw new RuntimeException('Os pedidos por QR Code estão temporariamente desativados.');
    if (empty($comanda['public_order_enabled'])) throw new RuntimeException('Esta mesa/comanda não está habilitada para pedidos por QR Code.');
    if (strtoupper((string)($comanda['status'] ?? '')) === 'BLOQUEADA') throw new RuntimeException('Esta mesa/comanda está bloqueada. Chame um atendente.');

    $name = trim((string)($payload['customer_name'] ?? ''));
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 120, 'UTF-8'); else $name = substr($name, 0, 120);
    $phone = comanda_public_phone_digits((string)($payload['customer_phone'] ?? ''));
    if (!empty($settings['public_qr_require_name']) && strlen($name) < 2) throw new RuntimeException('Informe seu nome para enviar o pedido.');
    if (!empty($settings['public_qr_require_phone']) && (strlen($phone) < 10 || strlen($phone) > 13)) throw new RuntimeException('Informe um celular válido com DDD.');
    if ($name === '') $name = 'Cliente da mesa';

    $requestToken = strtolower(trim((string)($payload['request_token'] ?? '')));
    if (!preg_match('/^[a-f0-9]{24,64}$/', $requestToken)) throw new RuntimeException('A sessão do pedido expirou. Atualize a página e tente novamente.');
    $existing = finance_query_one($pdo, 'SELECT * FROM comanda_public_orders WHERE company_id=? AND request_token=? LIMIT 1', [$companyId, $requestToken]);
    if ($existing) return ['order_id'=>(int)$existing['id'], 'subtotal'=>(float)$existing['subtotal'], 'jobs'=>0, 'duplicate'=>true];

    $itemsJson = (string)($payload['items_json'] ?? '');
    $rawItems = json_decode($itemsJson, true);
    if (!is_array($rawItems) || !$rawItems) throw new RuntimeException('Adicione ao menos um produto antes de enviar o pedido.');
    if (count($rawItems) > 50) throw new RuntimeException('O pedido possui muitos itens. Divida-o em dois pedidos.');

    $prepared = [];
    $subtotal = 0.0;
    foreach ($rawItems as $raw) {
      if (!is_array($raw)) continue;
      $item = comanda_public_validate_product_item($pdo, $companyId, $raw);
      $prepared[] = $item;
      $subtotal += (float)$item['total'];
    }
    if (!$prepared) throw new RuntimeException('Adicione ao menos um produto válido.');
    $subtotal = round($subtotal, 2);
    $comandaId = (int)($comanda['id'] ?? 0);
    $openBefore = (int)(finance_query_one($pdo, "SELECT COUNT(*) AS qtd FROM comanda_items WHERE company_id=? AND comanda_id=? AND status='ABERTO'", [$companyId, $comandaId])['qtd'] ?? 0);

    $pdo->beginTransaction();
    try {
      $stOrder = $pdo->prepare("INSERT INTO comanda_public_orders (company_id, comanda_id, request_token, customer_name, customer_phone, subtotal, status, remote_ip, user_agent, created_at) VALUES (?, ?, ?, ?, ?, ?, 'RECEBIDO', NULLIF(?,''), NULLIF(?,''), NOW())");
      $stOrder->execute([$companyId, $comandaId, $requestToken, $name, $phone, $subtotal, substr($remoteIp, 0, 64), substr($userAgent, 0, 255)]);
      $orderId = (int)$pdo->lastInsertId();
      $groupItems = [];
      foreach ($prepared as $item) {
        $itemId = comanda_add_item($pdo, $companyId, $comandaId, (int)$item['product_id'], (float)$item['qty'], (string)$item['observation'], (float)$item['unit_price']);
        $pdo->prepare("UPDATE comanda_items SET public_order_id=?, source='qr_cliente', updated_at=NOW() WHERE company_id=? AND id=?")->execute([$orderId, $companyId, $itemId]);
        if (!empty($item['should_print'])) {
          $group = comanda_product_print_group((array)$item['product']);
          $groupItems[$group] ??= [];
          $groupItems[$group][] = $itemId;
        }
      }
      if ($openBefore <= 0) {
        $pdo->prepare("UPDATE comandas SET nome=?, notes=CONCAT('Celular: ', ?), updated_at=NOW() WHERE company_id=? AND id=?")->execute([$name, $phone, $companyId, $comandaId]);
      }
      $jobs = comanda_print_queue_create($pdo, $companyId, $comandaId, $orderId, $groupItems);
      $status = $jobs > 0 ? 'AGUARDANDO_IMPRESSAO' : 'RECEBIDO';
      $pdo->prepare('UPDATE comanda_public_orders SET status=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$status, $companyId, $orderId]);
      $pdo->commit();
      return ['order_id'=>$orderId, 'subtotal'=>$subtotal, 'jobs'=>$jobs, 'duplicate'=>false];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      if (stripos($e->getMessage(), 'Duplicate') !== false) {
        $existing = finance_query_one($pdo, 'SELECT * FROM comanda_public_orders WHERE company_id=? AND request_token=? LIMIT 1', [$companyId, $requestToken]);
        if ($existing) return ['order_id'=>(int)$existing['id'], 'subtotal'=>(float)$existing['subtotal'], 'jobs'=>0, 'duplicate'=>true];
      }
      throw $e;
    }
  }
}
