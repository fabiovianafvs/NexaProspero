<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/inter_pix.php';
require_once __DIR__ . '/pagbank.php';

if (!function_exists('saas_billing_ensure_schema')) {
  function saas_billing_ensure_schema(PDO $pdo): void {
    inter_pix_ensure_schema($pdo);
    pagbank_ensure_schema($pdo);
    try { $pdo->exec("ALTER TABLE subscriptions MODIFY COLUMN status ENUM('trial','active','past_due','canceled','cancelled','pending_payment','complimentary') NOT NULL DEFAULT 'trial'"); } catch (Throwable $e) {}
    try { $pdo->exec("ALTER TABLE companies MODIFY COLUMN status ENUM('active','blocked','suspended') NOT NULL DEFAULT 'active'"); } catch (Throwable $e) {}

    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_charges (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      company_id INT UNSIGNED NOT NULL,
      subscription_id INT UNSIGNED NULL,
      plan_id INT UNSIGNED NOT NULL,
      gateway VARCHAR(40) NOT NULL DEFAULT 'inter',
      payment_method VARCHAR(20) NOT NULL DEFAULT 'pix',
      status VARCHAR(30) NOT NULL DEFAULT 'open',
      txid VARCHAR(100) NOT NULL,
      inter_loc_id BIGINT NULL,
      amount DECIMAL(10,2) NOT NULL DEFAULT 0.00,
      billing_days INT NOT NULL DEFAULT 30,
      expires_at DATETIME NULL,
      paid_at DATETIME NULL,
      pix_copy_paste LONGTEXT NULL,
      qr_image LONGTEXT NULL,
      inter_status VARCHAR(50) NULL,
      e2eid VARCHAR(191) NULL,
      payment_payload LONGTEXT NULL,
      inter_response LONGTEXT NULL,
      webhook_payload LONGTEXT NULL,
      payment_url VARCHAR(255) NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      UNIQUE KEY uk_saas_charges_txid (txid),
      KEY idx_saas_charges_company (company_id),
      KEY idx_saas_charges_subscription (subscription_id),
      KEY idx_saas_charges_plan (plan_id),
      KEY idx_saas_charges_status (status),
      CONSTRAINT fk_saas_charges_company FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE ON UPDATE CASCADE,
      CONSTRAINT fk_saas_charges_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT ON UPDATE CASCADE,
      CONSTRAINT fk_saas_charges_subscription FOREIGN KEY (subscription_id) REFERENCES subscriptions(id) ON DELETE SET NULL ON UPDATE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    foreach ([
      'gateway_reference' => 'VARCHAR(100) NULL',
      'gateway_status' => 'VARCHAR(50) NULL',
      'checkout_url' => 'VARCHAR(500) NULL',
      'description' => 'VARCHAR(255) NULL',
      'api_log_id' => 'BIGINT UNSIGNED NULL',
      'cancelled_at' => 'DATETIME NULL'
    ] as $column => $definition) {
      try { if (!saas_db_has_column($pdo, 'saas_charges', $column)) $pdo->exec("ALTER TABLE saas_charges ADD COLUMN {$column} {$definition}"); } catch (Throwable $e) {}
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_webhook_logs (
      id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
      event_name VARCHAR(80) DEFAULT NULL,
      txid VARCHAR(100) DEFAULT NULL,
      e2eid VARCHAR(191) DEFAULT NULL,
      headers_json LONGTEXT NULL,
      payload_json LONGTEXT NULL,
      processed TINYINT(1) NOT NULL DEFAULT 0,
      processed_at DATETIME NULL,
      notes VARCHAR(255) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      KEY idx_saas_webhook_txid (txid),
      KEY idx_saas_webhook_processed (processed)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
  }
}

if (!function_exists('saas_db_has_column')) {
  function saas_db_has_column(PDO $pdo, string $table, string $column): bool { return inter_pix_db_has_column($pdo, $table, $column); }
}

if (!function_exists('saas_find_company')) {
  function saas_find_company(PDO $pdo, int $companyId): ?array {
    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_find_company_by_slug_any_status')) {
  function saas_find_company_by_slug_any_status(PDO $pdo, string $slug): ?array {
    $st = $pdo->prepare('SELECT * FROM companies WHERE slug = ? LIMIT 1');
    $st->execute([$slug]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_find_plan')) {
  function saas_find_plan(PDO $pdo, int $planId): ?array {
    $st = $pdo->prepare('SELECT * FROM plans WHERE id = ? LIMIT 1');
    $st->execute([$planId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_plan_is_trial')) {
  function saas_plan_is_trial(array $plan): bool {
    return ((float)($plan['price'] ?? 0) <= 0.0)
      || ((int)($plan['trial_days'] ?? 0) > 0)
      || in_array(strtolower((string)($plan['slug'] ?? '')), ['trial','start-free','startfree'], true)
      || stripos((string)($plan['name'] ?? ''), 'trial') !== false;
  }
}

if (!function_exists('saas_plan_billing_days')) {
  function saas_plan_billing_days(array $plan): int { return max(1, (int)($plan['billing_days'] ?? 30)); }
}

if (!function_exists('saas_generate_txid')) {
  function saas_generate_txid(int $companyId, int $planId): string {
    $base = strtoupper(base_convert((int)microtime(true), 10, 36));
    $rand = strtoupper(bin2hex(random_bytes(4)));
    $txid = 'NX' . $companyId . 'P' . $planId . $base . $rand;
    $txid = preg_replace('/[^A-Z0-9]/', '', $txid);
    return substr((string)$txid, 0, 35);
  }
}

if (!function_exists('saas_get_latest_subscription_any_status')) {
  function saas_get_latest_subscription_any_status(PDO $pdo, int $companyId): ?array {
    $st = $pdo->prepare('SELECT * FROM subscriptions WHERE company_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_create_subscription_for_signup')) {
  function saas_create_subscription_for_signup(PDO $pdo, int $companyId, array $plan): int {
    if (saas_plan_is_trial($plan)) {
      $days = max(1, (int)($plan['trial_days'] ?? 7));
      $st = $pdo->prepare('INSERT INTO subscriptions (company_id, plan_id, status, started_at, ends_at, next_billing_at, payment_provider, external_ref) VALUES (?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), DATE_ADD(NOW(), INTERVAL ? DAY), ?, NULL)');
      $st->execute([$companyId, (int)$plan['id'], 'trial', $days, $days, 'pagbank']);
      return (int)$pdo->lastInsertId();
    }
    $st = $pdo->prepare('INSERT INTO subscriptions (company_id, plan_id, status, started_at, ends_at, next_billing_at, payment_provider, external_ref) VALUES (?, ?, ?, NOW(), NULL, NOW(), ?, NULL)');
    $st->execute([$companyId, (int)$plan['id'], 'pending_payment', 'pagbank']);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('saas_charge_public_url')) {
  function saas_charge_public_url(array $company, int $chargeId): string {
    return current_scheme() . '://' . current_host_name() . '/pagamento_pix.php?company=' . rawurlencode((string)$company['slug']) . '&charge=' . $chargeId;
  }
}

if (!function_exists('saas_pagbank_webhook_url')) {
  function saas_pagbank_webhook_url(PDO $pdo): string {
    $cfg = pagbank_get_config($pdo, false) ?: [];
    $url = trim((string)($cfg['webhook_url'] ?? ''));
    if ($url === '') $url = current_scheme() . '://' . current_host_name() . '/webhooks/pagbank.php';
    $token = trim((string)($cfg['webhook_token'] ?? ''));
    if ($token !== '') $url .= (str_contains($url, '?') ? '&' : '?') . 'token=' . rawurlencode($token);
    return $url;
  }
}

if (!function_exists('saas_insert_pagbank_charge')) {
  function saas_insert_pagbank_charge(PDO $pdo, array $company, array $plan, ?int $subscriptionId, string $method, array $gateway, array $requestPayload): int {
    $billingDays = saas_plan_billing_days($plan);
    $expiresAt = null;
    if (!empty($gateway['expires_at'])) {
      try { $expiresAt = (new DateTimeImmutable((string)$gateway['expires_at']))->format('Y-m-d H:i:s'); } catch (Throwable $e) {}
    }
    $externalId = (string)($gateway['id'] ?? '');
    $reference = (string)($gateway['reference_id'] ?? $externalId);
    $st = $pdo->prepare('INSERT INTO saas_charges (company_id, subscription_id, plan_id, gateway, payment_method, status, txid, gateway_reference, amount, billing_days, expires_at, pix_copy_paste, qr_image, gateway_status, payment_payload, inter_response, payment_url, checkout_url) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $st->execute([
      (int)$company['id'], $subscriptionId, (int)$plan['id'], 'pagbank', $method, 'open', $externalId, $reference,
      (float)$plan['price'], $billingDays, $expiresAt,
      !empty($gateway['copy_paste']) ? (string)$gateway['copy_paste'] : null,
      !empty($gateway['qr_image']) ? (string)$gateway['qr_image'] : null,
      (string)($gateway['status'] ?? 'WAITING'),
      json_encode($requestPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      json_encode($gateway['payload'] ?? [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
      null,
      !empty($gateway['pay_url']) ? (string)$gateway['pay_url'] : null,
    ]);
    $chargeId = (int)$pdo->lastInsertId();
    $paymentUrl = saas_charge_public_url($company, $chargeId);
    $pdo->prepare('UPDATE saas_charges SET payment_url = ? WHERE id = ?')->execute([$paymentUrl, $chargeId]);
    if ($subscriptionId) $pdo->prepare('UPDATE subscriptions SET external_ref = ?, payment_provider = ? WHERE id = ?')->execute([$externalId, 'pagbank', $subscriptionId]);
    return $chargeId;
  }
}

if (!function_exists('saas_create_pix_charge')) {
  function saas_create_pix_charge(PDO $pdo, array $company, array $plan, ?int $subscriptionId = null, ?string $description = null): array {
    saas_billing_ensure_schema($pdo);
    $cfg = pagbank_require_config($pdo);
    if (empty($cfg['pix_enabled'])) throw new RuntimeException('O Pix está desativado na configuração do PagBank.');
    $reference = saas_generate_txid((int)$company['id'], (int)$plan['id']);
    $gateway = pagbank_create_pix_order($cfg, $company, $plan, $reference, saas_pagbank_webhook_url($pdo), $description ?: 'Assinatura Nexa Próspero');
    if (($gateway['id'] ?? '') === '') throw new RuntimeException('O PagBank não retornou o identificador do pedido Pix.');
    $chargeId = saas_insert_pagbank_charge($pdo, $company, $plan, $subscriptionId, 'pix', $gateway, $gateway['request_payload'] ?? []);
    $charge = saas_get_charge($pdo, $chargeId, (int)$company['id']);
    if (!$charge) throw new RuntimeException('A cobrança Pix foi criada no PagBank, mas não pôde ser persistida localmente.');
    return $charge;
  }
}

if (!function_exists('saas_create_card_checkout')) {
  function saas_create_card_checkout(PDO $pdo, array $company, array $plan, ?int $subscriptionId = null, ?string $description = null): array {
    saas_billing_ensure_schema($pdo);
    $cfg = pagbank_require_config($pdo);
    if (empty($cfg['card_enabled'])) throw new RuntimeException('O cartão está desativado na configuração do PagBank.');
    $reference = saas_generate_txid((int)$company['id'], (int)$plan['id']) . 'C';
    $returnUrl = current_scheme() . '://' . current_host_name() . '/pagamento_pix.php?company=' . rawurlencode((string)$company['slug']);
    $gateway = pagbank_create_card_checkout($cfg, $company, $plan, $reference, saas_pagbank_webhook_url($pdo), $returnUrl, $description ?: 'Assinatura Nexa Próspero');
    if (($gateway['id'] ?? '') === '') throw new RuntimeException('O PagBank não retornou o identificador do checkout de cartão.');
    $chargeId = saas_insert_pagbank_charge($pdo, $company, $plan, $subscriptionId, 'card', $gateway, $gateway['request_payload'] ?? []);
    $charge = saas_get_charge($pdo, $chargeId, (int)$company['id']);
    if (!$charge) throw new RuntimeException('O checkout de cartão foi criado no PagBank, mas não pôde ser persistido localmente.');
    return $charge;
  }
}

if (!function_exists('saas_get_charge')) {
  function saas_get_charge(PDO $pdo, int $chargeId, int $companyId = 0): ?array {
    saas_billing_ensure_schema($pdo);
    $sql = 'SELECT c.*, p.name AS plan_name, p.price AS plan_price, co.name AS company_name, co.slug AS company_slug, co.email AS company_email FROM saas_charges c INNER JOIN plans p ON p.id = c.plan_id INNER JOIN companies co ON co.id = c.company_id WHERE c.id = ?';
    if ($companyId > 0) $sql .= ' AND c.company_id = ?';
    $sql .= ' LIMIT 1';
    $st = $pdo->prepare($sql);
    $params = [$chargeId]; if ($companyId > 0) $params[] = $companyId;
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_find_latest_open_charge')) {
  function saas_find_latest_open_charge(PDO $pdo, int $companyId): ?array {
    saas_billing_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT c.*, p.name AS plan_name, p.price AS plan_price, co.slug AS company_slug FROM saas_charges c INNER JOIN plans p ON p.id = c.plan_id INNER JOIN companies co ON co.id = c.company_id WHERE c.company_id = ? AND c.status IN ('open','processing') ORDER BY c.id DESC LIMIT 1");
    $st->execute([$companyId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_find_charge_by_txid')) {
  function saas_find_charge_by_txid(PDO $pdo, string $txid): ?array {
    saas_billing_ensure_schema($pdo);
    $st = $pdo->prepare("SELECT c.*, p.name AS plan_name, p.price AS plan_price, co.slug AS company_slug FROM saas_charges c INNER JOIN plans p ON p.id = c.plan_id INNER JOIN companies co ON co.id = c.company_id WHERE c.txid = ? LIMIT 1");
    $st->execute([$txid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('saas_list_company_charges')) {
  function saas_list_company_charges(PDO $pdo, int $companyId, int $limit = 20): array {
    saas_billing_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT c.*, p.name AS plan_name FROM saas_charges c INNER JOIN plans p ON p.id = c.plan_id WHERE c.company_id = ? ORDER BY c.id DESC LIMIT ' . max(1, (int)$limit));
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('saas_charge_status_label')) {
  function saas_charge_status_label(string $status): string {
    return match (strtolower(trim($status))) {
      'open' => 'Aguardando pagamento',
      'processing' => 'Processando',
      'paid' => 'Pago',
      'expired' => 'Expirada',
      'error' => 'Erro',
      default => ucfirst($status),
    };
  }
}

if (!function_exists('saas_charge_status_badge_style')) {
  function saas_charge_status_badge_style(string $status): string {
    return match (strtolower(trim($status))) {
      'open' => 'background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;',
      'processing' => 'background:#fff7ed;color:#c2410c;border:1px solid #fdba74;',
      'paid' => 'background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;',
      'expired', 'error' => 'background:#fef2f2;color:#991b1b;border:1px solid #fecaca;',
      default => 'background:#f3f4f6;color:#374151;border:1px solid #d1d5db;',
    };
  }
}

if (!function_exists('saas_calculate_due_dates')) {
  function saas_calculate_due_dates(?array $latestSubscription, int $billingDays, DateTimeImmutable $paidAt): array {
    $base = $paidAt;
    if ($latestSubscription && !empty($latestSubscription['ends_at'])) {
      try {
        $candidate = new DateTimeImmutable((string)$latestSubscription['ends_at']);
        if ($candidate > $paidAt) $base = $candidate;
      } catch (Throwable $e) {}
    }
    $endsAt = $base->modify('+' . max(1, $billingDays) . ' days');
    return [
      'started_at' => $paidAt->format('Y-m-d H:i:s'),
      'ends_at' => $endsAt->format('Y-m-d H:i:s'),
      'next_billing_at' => $endsAt->format('Y-m-d H:i:s'),
    ];
  }
}

if (!function_exists('saas_confirm_charge_payment')) {
  function saas_confirm_charge_payment(PDO $pdo, array $charge, array $paymentData, array $sourcePayload = []): array {
    saas_billing_ensure_schema($pdo);
    $pdo->beginTransaction();
    try {
      $locked = $pdo->prepare('SELECT * FROM saas_charges WHERE id = ? FOR UPDATE');
      $locked->execute([(int)$charge['id']]);
      $row = $locked->fetch(PDO::FETCH_ASSOC);
      if (!$row) throw new RuntimeException('Cobrança não encontrada para confirmação.');
      if (strtolower((string)$row['status']) === 'paid') { $pdo->commit(); return saas_get_charge($pdo, (int)$row['id']) ?? $row; }

      $companyId = (int)$row['company_id'];
      $planId = (int)$row['plan_id'];
      $billingDays = max(1, (int)($row['billing_days'] ?? 30));
      $paidAt = !empty($paymentData['paid_at']) ? new DateTimeImmutable((string)$paymentData['paid_at']) : new DateTimeImmutable('now');
      $latestSubscription = saas_get_latest_subscription_any_status($pdo, $companyId);
      $subscriptionId = (int)($row['subscription_id'] ?? 0);
      $targetSub = null;
      if ($subscriptionId > 0) {
        $stSub = $pdo->prepare('SELECT * FROM subscriptions WHERE id = ? LIMIT 1');
        $stSub->execute([$subscriptionId]);
        $targetSub = $stSub->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      $due = saas_calculate_due_dates($latestSubscription, $billingDays, $paidAt);

      if ($targetSub && strtolower((string)($targetSub['status'] ?? '')) === 'pending_payment') {
        $pdo->prepare('UPDATE subscriptions SET plan_id = ?, status = ?, started_at = ?, ends_at = ?, next_billing_at = ?, payment_provider = ?, external_ref = ? WHERE id = ?')->execute([
          $planId, 'active', $due['started_at'], $due['ends_at'], $due['next_billing_at'], (string)($row['gateway'] ?? 'pagbank'), (string)$row['txid'], $subscriptionId,
        ]);
      } else {
        $pdo->prepare('INSERT INTO subscriptions (company_id, plan_id, status, started_at, ends_at, next_billing_at, payment_provider, external_ref) VALUES (?, ?, ?, ?, ?, ?, ?, ?)')->execute([
          $companyId, $planId, 'active', $due['started_at'], $due['ends_at'], $due['next_billing_at'], (string)($row['gateway'] ?? 'pagbank'), (string)$row['txid'],
        ]);
        $subscriptionId = (int)$pdo->lastInsertId();
      }

      $pdo->prepare('UPDATE saas_charges SET subscription_id = ?, status = ?, paid_at = ?, inter_status = ?, e2eid = ?, payment_payload = ?, webhook_payload = ? WHERE id = ?')->execute([
        $subscriptionId > 0 ? $subscriptionId : null,
        'paid',
        $paidAt->format('Y-m-d H:i:s'),
        (string)($paymentData['gateway_status'] ?? $paymentData['inter_status'] ?? 'PAID'),
        !empty($paymentData['e2eid']) ? (string)$paymentData['e2eid'] : null,
        json_encode($paymentData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        $sourcePayload ? json_encode($sourcePayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
        (int)$row['id'],
      ]);

      if (saas_db_has_column($pdo, 'companies', 'desired_plan_id')) $pdo->prepare('UPDATE companies SET status = ?, desired_plan_id = ? WHERE id = ?')->execute(['active', $planId, $companyId]);
      else $pdo->prepare('UPDATE companies SET status = ? WHERE id = ?')->execute(['active', $companyId]);

      $pdo->commit();
      return saas_get_charge($pdo, (int)$row['id']) ?? $row;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('saas_extract_payment_data')) {
  function saas_extract_payment_data(array $chargeData, array $pixList = []): array {
    $pixItems = [];
    if (!empty($chargeData['pix']) && is_array($chargeData['pix'])) $pixItems = $chargeData['pix'];
    if (!$pixItems && !empty($pixList['pix']) && is_array($pixList['pix'])) $pixItems = $pixList['pix'];
    $first = $pixItems[0] ?? [];
    return [
      'inter_status' => (string)($chargeData['status'] ?? 'CONCLUIDA'),
      'e2eid' => (string)($first['endToEndId'] ?? $first['e2eid'] ?? ''),
      'amount' => (float)($first['valor'] ?? $chargeData['valor']['original'] ?? 0),
      'paid_at' => (string)($first['horario'] ?? date('Y-m-d H:i:s')),
    ];
  }
}

if (!function_exists('saas_sync_charge_status')) {
  function saas_sync_charge_status(PDO $pdo, int $chargeId): array {
    saas_billing_ensure_schema($pdo);
    $charge = saas_get_charge($pdo, $chargeId);
    if (!$charge) throw new RuntimeException('Cobrança não encontrada.');
    if (strtolower((string)$charge['status']) === 'paid') return $charge;
    if (strtolower((string)$charge['gateway']) === 'pagbank') {
      $cfg = pagbank_require_config($pdo);
      $payload = strtolower((string)$charge['payment_method']) === 'card'
        ? pagbank_get_checkout($cfg, (string)$charge['txid'])
        : pagbank_get_order($cfg, (string)$charge['txid']);
      $status = '';
      foreach (($payload['charges'] ?? []) as $c) if (is_array($c) && !empty($c['status'])) { $status = (string)$c['status']; break; }
      $pdo->prepare('UPDATE saas_charges SET gateway_status = ?, inter_status = ?, inter_response = ? WHERE id = ?')->execute([
        $status, $status, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chargeId,
      ]);
      if (pagbank_payload_is_paid($payload)) return saas_confirm_charge_payment($pdo, $charge, pagbank_extract_payment_data($payload), $payload);
      return saas_get_charge($pdo, $chargeId) ?? $charge;
    }
    $cfg = inter_pix_require_config($pdo);
    $chargeData = inter_pix_get_charge($cfg, (string)$charge['txid']);
    $pixList = inter_pix_list_received_pix($cfg, (string)$charge['txid']);
    $pdo->prepare('UPDATE saas_charges SET inter_status = ?, inter_response = ? WHERE id = ?')->execute([(string)($chargeData['status'] ?? ''), json_encode(['charge'=>$chargeData,'pix'=>$pixList], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $chargeId]);
    if (inter_pix_charge_paid($chargeData) || (!empty($pixList['pix']) && is_array($pixList['pix']))) return saas_confirm_charge_payment($pdo, $charge, saas_extract_payment_data($chargeData, $pixList), ['charge'=>$chargeData,'pix'=>$pixList]);
    return saas_get_charge($pdo, $chargeId) ?? $charge;
  }
}

if (!function_exists('saas_process_pagbank_webhook')) {
  function saas_process_pagbank_webhook(PDO $pdo, array $headers, array $payload): array {
    saas_billing_ensure_schema($pdo);
    $reference = trim((string)($payload['reference_id'] ?? ''));
    $ids = [];
    $walk = static function($node) use (&$walk, &$ids): void {
      if (!is_array($node)) return;
      foreach (['id','reference_id'] as $key) if (isset($node[$key]) && is_scalar($node[$key])) $ids[] = trim((string)$node[$key]);
      foreach ($node as $value) if (is_array($value)) $walk($value);
    };
    $walk($payload);
    $ids = array_values(array_unique(array_filter($ids)));
    $logId = saas_log_inter_webhook($pdo, $headers, $payload, $ids[0] ?? null, 'pagbank_webhook', null);
    $charge = null;
    foreach ($ids as $id) {
      $st = $pdo->prepare('SELECT * FROM saas_charges WHERE gateway = ? AND (txid = ? OR gateway_reference = ?) ORDER BY id DESC LIMIT 1');
      $st->execute(['pagbank',$id,$id]);
      $charge = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($charge) break;
    }
    if (!$charge && $reference !== '') {
      $st = $pdo->prepare('SELECT * FROM saas_charges WHERE gateway = ? AND gateway_reference = ? ORDER BY id DESC LIMIT 1');
      $st->execute(['pagbank',$reference]);
      $charge = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$charge) { saas_finish_webhook_log($pdo, $logId, false, 'Cobrança PagBank não localizada.'); return ['processed'=>false,'message'=>'Cobrança não localizada.']; }
    $paid = pagbank_payload_is_paid($payload);
    $status = '';
    $walker = static function($node) use (&$walker, &$status): void { if ($status !== '' || !is_array($node)) return; if (isset($node['status'])) $status=(string)$node['status']; foreach ($node as $v) if (is_array($v)) $walker($v); };
    $walker($payload);
    $pdo->prepare('UPDATE saas_charges SET gateway_status=?, inter_status=?, webhook_payload=? WHERE id=?')->execute([$status,$status,json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),(int)$charge['id']]);
    if ($paid) saas_confirm_charge_payment($pdo, $charge, pagbank_extract_payment_data($payload), $payload);
    saas_finish_webhook_log($pdo, $logId, true, $paid ? 'Pagamento confirmado.' : 'Notificação registrada.');
    return ['processed'=>true,'message'=>$paid ? 'Pagamento confirmado.' : 'Notificação registrada.'];
  }
}

if (!function_exists('saas_log_inter_webhook')) {
  function saas_log_inter_webhook(PDO $pdo, array $headers, array $payload, ?string $txid, ?string $eventName = null, ?string $e2eid = null): int {
    saas_billing_ensure_schema($pdo);
    $st = $pdo->prepare('INSERT INTO saas_webhook_logs (event_name, txid, e2eid, headers_json, payload_json, processed, processed_at, notes) VALUES (?,?,?,?,?,0,NULL,NULL)');
    $st->execute([$eventName, $txid, $e2eid, json_encode($headers, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('saas_finish_webhook_log')) {
  function saas_finish_webhook_log(PDO $pdo, int $logId, bool $processed, string $notes = ''): void {
    $pdo->prepare('UPDATE saas_webhook_logs SET processed = ?, processed_at = NOW(), notes = ? WHERE id = ?')->execute([$processed ? 1 : 0, $notes !== '' ? $notes : null, $logId]);
  }
}

if (!function_exists('saas_collect_webhook_txids')) {
  function saas_collect_webhook_txids(array $payload): array {
    $found = [];
    $walker = static function ($node) use (&$found, &$walker): void {
      if (!is_array($node)) return;
      if (isset($node['txid']) && is_scalar($node['txid'])) {
        $txid = trim((string)$node['txid']); if ($txid !== '') $found[] = $txid;
      }
      foreach ($node as $value) if (is_array($value)) $walker($value);
    };
    $walker($payload);
    return array_values(array_unique($found));
  }
}

if (!function_exists('saas_process_inter_webhook')) {
  function saas_process_inter_webhook(PDO $pdo, array $headers, array $payload): array {
    saas_billing_ensure_schema($pdo);
    $txids = saas_collect_webhook_txids($payload);
    $e2eid = (string)(inter_pix_pick_first($payload, ['endToEndId', 'e2eid']) ?? '');
    $eventName = (string)(inter_pix_pick_first($payload, ['evento', 'event', 'tipoCob']) ?? 'pix_webhook');
    $logId = saas_log_inter_webhook($pdo, $headers, $payload, $txids[0] ?? null, $eventName, $e2eid !== '' ? $e2eid : null);
    if (!$txids) {
      saas_finish_webhook_log($pdo, $logId, false, 'Nenhum TXID encontrado no payload.');
      return ['processed' => false, 'message' => 'Nenhum TXID encontrado no payload.'];
    }
    $processed = 0;
    foreach ($txids as $txid) {
      $charge = saas_find_charge_by_txid($pdo, $txid);
      if (!$charge) continue;
      saas_sync_charge_status($pdo, (int)$charge['id']);
      $processed++;
    }
    saas_finish_webhook_log($pdo, $logId, $processed > 0, $processed > 0 ? ('Cobranças sincronizadas: ' . $processed) : 'TXID(s) recebidos, mas sem cobrança local correspondente.');
    return ['processed' => $processed > 0, 'message' => $processed > 0 ? 'Cobrança(s) sincronizada(s).' : 'Nenhuma cobrança local encontrada para os TXIDs recebidos.'];
  }
}


if (!function_exists('saas_create_manual_pagbank_charge')) {
  function saas_create_manual_pagbank_charge(PDO $pdo, int $companyId, int $planId, float $amount, string $method, string $description = ''): array {
    $company = saas_find_company($pdo, $companyId);
    $plan = saas_find_plan($pdo, $planId);
    if (!$company || !$plan) throw new RuntimeException('Empresa ou plano não localizado.');
    if ($amount < 1.00) throw new RuntimeException('O valor mínimo da cobrança é R$ 1,00.');
    $method = strtolower($method);
    if (!in_array($method, ['pix','card'], true)) throw new RuntimeException('Forma de pagamento inválida.');
    $customPlan = $plan;
    $customPlan['price'] = round($amount, 2);
    $customPlan['name'] = $description !== '' ? $description : (string)$plan['name'];
    $charge = $method === 'card'
      ? saas_create_card_checkout($pdo, $company, $customPlan, null, $description)
      : saas_create_pix_charge($pdo, $company, $customPlan, null, $description);
    $pdo->prepare('UPDATE saas_charges SET description = ?, amount = ? WHERE id = ?')->execute([
      $description !== '' ? substr($description,0,255) : null, round($amount,2), (int)$charge['id']
    ]);
    return saas_get_charge($pdo, (int)$charge['id']) ?? $charge;
  }
}

if (!function_exists('saas_cancel_open_charge')) {
  function saas_cancel_open_charge(PDO $pdo, int $chargeId): array {
    $charge = saas_get_charge($pdo, $chargeId);
    if (!$charge) throw new RuntimeException('Cobrança não encontrada.');
    if (strtolower((string)$charge['status']) === 'paid') throw new RuntimeException('Uma cobrança paga não pode ser cancelada por esta opção. Use o processo de estorno do PagBank.');
    $pdo->prepare("UPDATE saas_charges SET status='canceled', cancelled_at=NOW() WHERE id=?")->execute([$chargeId]);
    return saas_get_charge($pdo, $chargeId) ?? $charge;
  }
}

if (!function_exists('saas_pagbank_recent_logs')) {
  function saas_pagbank_recent_logs(PDO $pdo, int $limit = 50): array {
    pagbank_ensure_schema($pdo);
    $limit = max(1, min(200, $limit));
    return $pdo->query('SELECT * FROM pagbank_api_logs ORDER BY id DESC LIMIT ' . $limit)->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
