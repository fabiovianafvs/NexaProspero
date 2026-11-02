<?php
declare(strict_types=1);

require_once __DIR__ . '/schema_runtime.php';
require_once __DIR__ . '/brain_profitability.php';

/**
 * Nexa Brain v2.3.0 - Central de Ações Inteligentes.
 *
 * Este arquivo não executa DDL durante requisições web. A estrutura deve ser
 * instalada pelo worker migrate_brain_actions_v230.php.
 */

if (!defined('NEXA_BRAIN_ACTIONS_SCHEMA_VERSION')) {
  define('NEXA_BRAIN_ACTIONS_SCHEMA_VERSION', 2026072701);
}

if (!function_exists('brain_actions_schema_ready')) {
  function brain_actions_schema_ready(PDO $pdo): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    return $cache[$key] = nexa_schema_version_ready($pdo, 'brain_actions', NEXA_BRAIN_ACTIONS_SCHEMA_VERSION);
  }
}

if (!function_exists('brain_actions_table_exists')) {
  function brain_actions_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    $key = spl_object_id($pdo) . ':' . strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? LIMIT 1');
      $st->execute([$table]);
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('brain_actions_json')) {
  function brain_actions_json(mixed $value): array {
    if (is_array($value)) return $value;
    $decoded = json_decode((string)$value, true);
    return is_array($decoded) ? $decoded : [];
  }
}

if (!function_exists('brain_actions_query_rows')) {
  function brain_actions_query_rows(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('brain_actions_query_row')) {
  function brain_actions_query_row(PDO $pdo, string $sql, array $params = []): array {
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('brain_actions_severity')) {
  function brain_actions_severity(string $severity, string $fallback = 'warning'): string {
    $severity = strtolower(trim($severity));
    return in_array($severity, ['info','opportunity','warning','high','critical'], true) ? $severity : $fallback;
  }
}

if (!function_exists('brain_actions_safe_url')) {
  function brain_actions_safe_url(string $url, string $fallback = 'brain.php'): string {
    $url = trim($url);
    if ($url === '' || str_contains($url, "\0") || str_contains($url, '..') || str_starts_with($url, '//')) return $fallback;
    if (preg_match('~^[a-zA-Z0-9_./-]+\.php(?:\?[a-zA-Z0-9_=&%+.,:-]*)?$~', $url) !== 1) return $fallback;
    return $url;
  }
}

if (!function_exists('brain_actions_history_add')) {
  function brain_actions_history_add(PDO $pdo, int $alertId, int $companyId, string $actionCode, ?string $fromStatus, ?string $toStatus, string $note = '', ?int $userId = null): void {
    try {
      $pdo->prepare('INSERT INTO brain_alert_history (alert_id,company_id,action_code,from_status,to_status,note,user_id,created_at) VALUES (?,?,?,?,?,?,?,NOW())')
        ->execute([$alertId,$companyId,mb_substr($actionCode,0,30,'UTF-8'),$fromStatus,$toStatus,mb_substr(trim($note),0,500,'UTF-8'),$userId]);
    } catch (Throwable $e) {
      error_log('[Nexa Brain Actions] Falha ao gravar histórico do alerta #' . $alertId . ': ' . $e->getMessage());
    }
  }
}

if (!function_exists('brain_actions_rule_catalog')) {
  function brain_actions_rule_catalog(PDO $pdo, int $companyId): array {
    if (!brain_actions_schema_ready($pdo)) return [];
    $rows = brain_actions_query_rows($pdo, "SELECT
        r.rule_code,r.module_code,r.name,r.description,r.default_severity,r.default_settings,
        r.action_url,r.recommendation,r.is_active,
        s.is_enabled AS company_enabled,s.severity_override,s.settings AS company_settings
      FROM brain_rules r
      LEFT JOIN brain_company_rule_settings s
        ON s.company_id=? AND s.rule_code=r.rule_code
      WHERE r.company_id=0
      ORDER BY FIELD(r.default_severity,'critical','high','warning','opportunity','info'),r.module_code,r.name", [$companyId]);
    $out = [];
    foreach ($rows as $row) {
      $defaults = brain_actions_json($row['default_settings'] ?? null);
      $overrides = brain_actions_json($row['company_settings'] ?? null);
      $row['settings'] = array_replace($defaults, $overrides);
      $row['enabled'] = $row['company_enabled'] === null ? (bool)$row['is_active'] : (bool)$row['company_enabled'];
      $row['severity'] = brain_actions_severity((string)($row['severity_override'] ?: $row['default_severity']), 'warning');
      $out[(string)$row['rule_code']] = $row;
    }
    return $out;
  }
}

if (!function_exists('brain_actions_setting_labels')) {
  function brain_actions_setting_labels(): array {
    return [
      'days' => ['label'=>'Período analisado (dias)','step'=>'1','min'=>'1','max'=>'365'],
      'minimum_loss' => ['label'=>'Prejuízo mínimo para alertar (R$)','step'=>'0.01','min'=>'0'],
      'minimum_stock' => ['label'=>'Estoque mínimo considerado','step'=>'0.001','min'=>'0'],
      'minimum_amount' => ['label'=>'Valor mínimo para alertar (R$)','step'=>'0.01','min'=>'0'],
      'minimum_gap' => ['label'=>'Diferença mínima de caixa (R$)','step'=>'0.01','min'=>'0'],
      'drop_pct' => ['label'=>'Queda mínima nas vendas (%)','step'=>'0.1','min'=>'0','max'=>'100'],
      'minimum_reference_sales' => ['label'=>'Venda mínima de referência (R$)','step'=>'0.01','min'=>'0'],
      'min_margin_pct' => ['label'=>'Margem mínima desejada (%)','step'=>'0.1','min'=>'0','max'=>'100'],
      'minimum_sales' => ['label'=>'Venda mínima do dia para avaliar (R$)','step'=>'0.01','min'=>'0'],
      'minutes' => ['label'=>'Tempo máximo do pedido em aberto (minutos)','step'=>'1','min'=>'5','max'=>'1440'],
      'pending_days' => ['label'=>'Dias para considerar NF-e pendente','step'=>'1','min'=>'0','max'=>'365'],
      'max_alerts' => ['label'=>'Máximo de alertas por regra','step'=>'1','min'=>'1','max'=>'200'],
    ];
  }
}

if (!function_exists('brain_actions_setting_number')) {
  function brain_actions_setting_number(array $settings, string $key, float $fallback): float {
    $value = $settings[$key] ?? $fallback;
    return is_numeric($value) ? (float)$value : $fallback;
  }
}

if (!function_exists('brain_actions_fingerprint')) {
  function brain_actions_fingerprint(int $companyId, string $ruleCode, string $entityType, int|string|null $entityId = null, string $reference = ''): string {
    return hash('sha256', implode('|', [$companyId, $ruleCode, $entityType, (string)$entityId, $reference]));
  }
}

if (!function_exists('brain_actions_enqueue_update')) {
  function brain_actions_enqueue_update(PDO $pdo, int $companyId, string $eventType, array $payload = []): void {
    if (!function_exists('brain_event_enqueue')) return;
    try {
      brain_event_enqueue($pdo, $companyId, $eventType, 'brain_alert', (int)($payload['alert_id'] ?? 0), $payload);
    } catch (Throwable $e) {
      error_log('[Nexa Brain Actions] Falha ao enfileirar atualização: ' . $e->getMessage());
    }
  }
}

if (!function_exists('brain_actions_upsert_alert')) {
  function brain_actions_upsert_alert(PDO $pdo, int $companyId, array $rule, array $alert): array {
    $ruleCode = (string)$rule['rule_code'];
    $entityType = trim((string)($alert['entity_type'] ?? 'company')) ?: 'company';
    $entityId = isset($alert['entity_id']) ? (int)$alert['entity_id'] : null;
    $reference = trim((string)($alert['reference_key'] ?? ''));
    $fingerprint = brain_actions_fingerprint($companyId, $ruleCode, $entityType, $entityId, $reference);
    $existing = brain_actions_query_row($pdo, 'SELECT * FROM brain_alerts WHERE company_id=? AND fingerprint=? LIMIT 1', [$companyId, $fingerprint]);

    $severity = brain_actions_severity((string)($alert['severity'] ?? $rule['severity'] ?? 'warning'));
    $moduleCode = trim((string)($alert['module_code'] ?? $rule['module_code'] ?? 'brain')) ?: 'brain';
    $title = mb_substr(trim((string)($alert['title'] ?? $rule['name'] ?? 'Ação recomendada')), 0, 180, 'UTF-8');
    $description = trim((string)($alert['description'] ?? $rule['description'] ?? ''));
    $recommendation = trim((string)($alert['recommendation'] ?? $rule['recommendation'] ?? ''));
    $actionUrl = brain_actions_safe_url(mb_substr(trim((string)($alert['action_url'] ?? $rule['action_url'] ?? 'brain.php')), 0, 255, 'UTF-8'));
    $metricValue = isset($alert['metric_value']) && is_numeric($alert['metric_value']) ? (float)$alert['metric_value'] : null;
    $financialImpact = isset($alert['financial_impact']) && is_numeric($alert['financial_impact']) ? (float)$alert['financial_impact'] : null;
    $metadata = json_encode((array)($alert['metadata'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($metadata === false) $metadata = '{}';

    if (!$existing) {
      $st = $pdo->prepare("INSERT INTO brain_alerts
        (company_id,rule_code,fingerprint,module_code,severity,entity_type,entity_id,reference_key,title,description,recommendation,action_url,metric_value,financial_impact,metadata,status,first_detected_at,last_detected_at,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'open',NOW(),NOW(),NOW(),NOW())");
      $st->execute([$companyId,$ruleCode,$fingerprint,$moduleCode,$severity,$entityType,$entityId,$reference,$title,$description,$recommendation,$actionUrl,$metricValue,$financialImpact,$metadata]);
      $newAlertId = (int)$pdo->lastInsertId();
      brain_actions_history_add($pdo,$newAlertId,$companyId,'detected',null,'open','Alerta criado automaticamente pela regra ' . $ruleCode . '.');
      return ['fingerprint'=>$fingerprint,'alert_id'=>$newAlertId,'created'=>1,'updated'=>0,'reopened'=>0];
    }

    $oldStatus = (string)$existing['status'];
    $newStatus = $oldStatus;
    $snoozedUntil = (string)($existing['snoozed_until'] ?? '');
    if ($oldStatus === 'resolved') $newStatus = 'open';
    if ($oldStatus === 'snoozed' && ($snoozedUntil === '' || strtotime($snoozedUntil) <= time())) $newStatus = 'open';
    // Alertas ignorados permanecem ignorados até que o usuário os reabra manualmente.

    $changed = $newStatus !== $oldStatus
      || (string)$existing['severity'] !== $severity
      || (string)$existing['title'] !== $title
      || (string)$existing['description'] !== $description
      || (string)$existing['recommendation'] !== $recommendation
      || (string)$existing['action_url'] !== $actionUrl
      || (float)($existing['metric_value'] ?? 0) !== (float)($metricValue ?? 0)
      || (float)($existing['financial_impact'] ?? 0) !== (float)($financialImpact ?? 0);

    $st = $pdo->prepare("UPDATE brain_alerts SET
      module_code=?,severity=?,entity_type=?,entity_id=?,reference_key=?,title=?,description=?,recommendation=?,action_url=?,metric_value=?,financial_impact=?,metadata=?,
      status=?,last_detected_at=NOW(),resolved_at=CASE WHEN ?='open' THEN NULL ELSE resolved_at END,
      snoozed_until=CASE WHEN ?='open' THEN NULL ELSE snoozed_until END,updated_at=NOW()
      WHERE id=? AND company_id=?");
    $st->execute([$moduleCode,$severity,$entityType,$entityId,$reference,$title,$description,$recommendation,$actionUrl,$metricValue,$financialImpact,$metadata,$newStatus,$newStatus,$newStatus,(int)$existing['id'],$companyId]);

    $reopened = $oldStatus !== $newStatus && $newStatus === 'open';
    if ($reopened) brain_actions_history_add($pdo,(int)$existing['id'],$companyId,'auto_reopen',$oldStatus,'open','A condição da regra voltou a ocorrer ou o período de adiamento terminou.');
    return ['fingerprint'=>$fingerprint,'alert_id'=>(int)$existing['id'],'created'=>0,'updated'=>$changed ? 1 : 0,'reopened'=>$reopened ? 1 : 0];
  }
}

if (!function_exists('brain_actions_resolve_absent')) {
  function brain_actions_resolve_absent(PDO $pdo, int $companyId, string $ruleCode, array $activeFingerprints, string $historyAction = 'auto_resolve', string $historyNote = ''): int {
    $params = [$companyId, $ruleCode];
    $where = "company_id=? AND rule_code=? AND status IN ('open','snoozed')";
    if ($activeFingerprints) {
      $where .= ' AND fingerprint NOT IN (' . implode(',', array_fill(0, count($activeFingerprints), '?')) . ')';
      $params = array_merge($params, array_values($activeFingerprints));
    }
    $rows = brain_actions_query_rows($pdo,'SELECT id,status FROM brain_alerts WHERE ' . $where,$params);
    if (!$rows) return 0;
    $st = $pdo->prepare("UPDATE brain_alerts SET status='resolved',resolved_at=NOW(),snoozed_until=NULL,updated_at=NOW() WHERE " . $where);
    $st->execute($params);
    foreach ($rows as $row) {
      brain_actions_history_add($pdo,(int)$row['id'],$companyId,$historyAction,(string)$row['status'],'resolved',$historyNote !== '' ? $historyNote : 'A condição deixou de ser detectada pela regra ' . $ruleCode . '.');
    }
    return $st->rowCount();
  }
}

if (!function_exists('brain_actions_detect_rule')) {
  function brain_actions_detect_rule(PDO $pdo, int $companyId, array $rule): array {
    $code = (string)$rule['rule_code'];
    $settings = (array)$rule['settings'];
    $alerts = [];

    if ($code === 'NEGATIVE_MARGIN_PRODUCT') {
      $days = (int)brain_actions_setting_number($settings, 'days', 30);
      $minimumLoss = brain_actions_setting_number($settings, 'minimum_loss', 0.01);
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 50);
      $start = (new DateTimeImmutable('today'))->modify('-' . max(0, $days - 1) . ' days')->format('Y-m-d 00:00:00');
      $rows = brain_actions_query_rows($pdo, "SELECT
          COALESCE(pi.produto_id_snapshot,pi.produto_id,0) AS product_id,
          COALESCE(NULLIF(MAX(pi.produto_nome_snapshot),''),MAX(p.nome),CONCAT('Produto #',MAX(COALESCE(pi.produto_id_snapshot,pi.produto_id,0)))) AS product_name,
          ROUND(SUM(pi.receita_liquida),2) AS revenue,
          ROUND(SUM(pi.cmv_total),2) AS cmv,
          ROUND(SUM(pi.lucro_bruto),2) AS loss,
          COUNT(DISTINCT pe.id) AS orders_count
        FROM pedido_itens pi
        JOIN pedidos pe ON pe.company_id=pi.company_id AND pe.id=pi.pedido_id
        LEFT JOIN produtos p ON p.company_id=pi.company_id AND p.id=COALESCE(pi.produto_id_snapshot,pi.produto_id)
        WHERE pi.company_id=? AND pe.status='finalizado' AND pe.recognized_at IS NOT NULL AND pe.recognized_at>=? AND pi.lucro_bruto<0
        GROUP BY COALESCE(pi.produto_id_snapshot,pi.produto_id,0)
        HAVING ABS(SUM(pi.lucro_bruto))>=?
        ORDER BY SUM(pi.lucro_bruto) ASC
        LIMIT " . max(1, min(200, $limit)), [$companyId,$start,$minimumLoss]);
      foreach ($rows as $row) {
        $loss = abs((float)$row['loss']);
        $alerts[] = [
          'entity_type'=>'product','entity_id'=>(int)$row['product_id'],'reference_key'=>'rolling_' . $days . 'd',
          'title'=>'Produto com margem negativa: ' . (string)$row['product_name'],
          'description'=>'Nos últimos ' . $days . ' dias, este produto acumulou ' . number_format($loss,2,',','.') . ' de prejuízo bruto em ' . (int)$row['orders_count'] . ' pedido(s).',
          'recommendation'=>'Revise o custo, preço, descontos, adicionais e ficha técnica antes de continuar vendendo nas mesmas condições.',
          'action_url'=>(int)$row['product_id'] > 0 ? 'produto_form.php?id=' . (int)$row['product_id'] : 'pedidos.php',
          'metric_value'=>(float)$row['loss'],'financial_impact'=>$loss,
          'metadata'=>$row,
        ];
      }
    }

    if ($code === 'PRODUCT_WITHOUT_COST') {
      $minimumStock = brain_actions_setting_number($settings, 'minimum_stock', 0.001);
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 50);
      $rows = brain_actions_query_rows($pdo, "SELECT id,nome,preco,GREATEST(estoque_atual-estoque_reservado,0) AS available
        FROM produtos
        WHERE company_id=? AND ativo=1 AND GREATEST(estoque_atual-estoque_reservado,0)>=?
          AND COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0)<=0
        ORDER BY available*preco DESC,nome ASC LIMIT " . max(1, min(200, $limit)), [$companyId,$minimumStock]);
      foreach ($rows as $row) {
        $potential = (float)$row['available'] * (float)$row['preco'];
        $alerts[] = [
          'entity_type'=>'product','entity_id'=>(int)$row['id'],'reference_key'=>'current',
          'title'=>'Produto com estoque e sem custo: ' . (string)$row['nome'],
          'description'=>'Existem ' . number_format((float)$row['available'],3,',','.') . ' unidade(s) disponíveis sem custo cadastrado. O CMV, lucro e valor do estoque ficam incompletos.',
          'recommendation'=>'Cadastre o custo de compra ou custo médio e confirme a unidade de medida do produto.',
          'action_url'=>'produto_form.php?id=' . (int)$row['id'],
          'metric_value'=>(float)$row['available'],'financial_impact'=>$potential,'metadata'=>$row,
        ];
      }
    }

    if ($code === 'LOW_STOCK') {
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 50);
      $rows = brain_actions_query_rows($pdo, "SELECT id,nome,preco,estoque_minimo,GREATEST(estoque_atual-estoque_reservado,0) AS available
        FROM produtos
        WHERE company_id=? AND ativo=1 AND estoque_minimo>0 AND (estoque_atual-estoque_reservado)<=estoque_minimo
        ORDER BY ((estoque_minimo-GREATEST(estoque_atual-estoque_reservado,0))*preco) DESC,nome ASC
        LIMIT " . max(1, min(200, $limit)), [$companyId]);
      foreach ($rows as $row) {
        $shortage = max(0.0, (float)$row['estoque_minimo'] - (float)$row['available']);
        $alerts[] = [
          'entity_type'=>'product','entity_id'=>(int)$row['id'],'reference_key'=>'current',
          'title'=>'Risco de ruptura: ' . (string)$row['nome'],
          'description'=>'Disponível: ' . number_format((float)$row['available'],3,',','.') . '. Estoque mínimo: ' . number_format((float)$row['estoque_minimo'],3,',','.') . '.',
          'recommendation'=>'Avalie o giro e faça a reposição prioritária caso o item continue com demanda.',
          'action_url'=>'produto_form.php?id=' . (int)$row['id'],
          'metric_value'=>(float)$row['available'],'financial_impact'=>$shortage * (float)$row['preco'],'metadata'=>$row,
        ];
      }
    }

    if ($code === 'EXCESS_STOCK') {
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 50);
      $rows = brain_actions_query_rows($pdo, "SELECT id,nome,estoque_maximo,GREATEST(estoque_atual-estoque_reservado,0) AS available,
          COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0) AS unit_cost
        FROM produtos
        WHERE company_id=? AND ativo=1 AND estoque_maximo>0 AND (estoque_atual-estoque_reservado)>estoque_maximo
        ORDER BY ((estoque_atual-estoque_reservado)-estoque_maximo)*COALESCE(NULLIF(custo_medio,0),NULLIF(custo,0),0) DESC,nome ASC
        LIMIT " . max(1, min(200, $limit)), [$companyId]);
      foreach ($rows as $row) {
        $excess = max(0.0, (float)$row['available'] - (float)$row['estoque_maximo']);
        $capital = $excess * (float)$row['unit_cost'];
        $alerts[] = [
          'entity_type'=>'product','entity_id'=>(int)$row['id'],'reference_key'=>'current',
          'title'=>'Estoque acima do máximo: ' . (string)$row['nome'],
          'description'=>'Há ' . number_format($excess,3,',','.') . ' unidade(s) acima do máximo, representando aproximadamente R$ ' . number_format($capital,2,',','.') . ' em custo.',
          'recommendation'=>'Reduza temporariamente a reposição ou crie uma ação comercial sem comprometer a margem.',
          'action_url'=>'produto_form.php?id=' . (int)$row['id'],
          'metric_value'=>$excess,'financial_impact'=>$capital,'metadata'=>$row,
        ];
      }
    }

    if ($code === 'OVERDUE_RECEIVABLES') {
      if (!brain_actions_table_exists($pdo, 'finance_receivables')) return [];
      $minimum = brain_actions_setting_number($settings, 'minimum_amount', 0.01);
      $row = brain_actions_query_row($pdo, "SELECT COALESCE(SUM(open_amount),0) AS amount,COUNT(*) AS titles
        FROM finance_receivables WHERE company_id=? AND due_date<CURDATE() AND open_amount>0
          AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId]);
      if ((float)($row['amount'] ?? 0) >= $minimum) {
        $alerts[] = [
          'entity_type'=>'company','entity_id'=>$companyId,'reference_key'=>'current',
          'title'=>'Contas vencidas a receber',
          'description'=>'Existem R$ ' . number_format((float)$row['amount'],2,',','.') . ' em ' . (int)$row['titles'] . ' título(s) vencido(s).',
          'recommendation'=>'Priorize a cobrança dos maiores valores e registre os contatos realizados.',
          'action_url'=>'financeiro_contas_receber.php','metric_value'=>(float)$row['titles'],'financial_impact'=>(float)$row['amount'],'metadata'=>$row,
        ];
      }
    }

    if ($code === 'CASH_PRESSURE_7D') {
      if (!brain_actions_table_exists($pdo, 'finance_receivables') || !brain_actions_table_exists($pdo, 'finance_payables')) return [];
      $minimum = brain_actions_setting_number($settings, 'minimum_gap', 0.01);
      $receivable = brain_actions_query_row($pdo, "SELECT COALESCE(SUM(open_amount),0) amount FROM finance_receivables
        WHERE company_id=? AND due_date>=CURDATE() AND due_date<CURDATE()+INTERVAL 8 DAY AND open_amount>0 AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId]);
      $payable = brain_actions_query_row($pdo, "SELECT COALESCE(SUM(open_amount),0) amount FROM finance_payables
        WHERE company_id=? AND due_date>=CURDATE() AND due_date<CURDATE()+INTERVAL 8 DAY AND open_amount>0 AND UPPER(status) NOT IN ('PAGO','QUITADO','CANCELADO')", [$companyId]);
      $gap = (float)($payable['amount'] ?? 0) - (float)($receivable['amount'] ?? 0);
      if ($gap >= $minimum) {
        $alerts[] = [
          'entity_type'=>'company','entity_id'=>$companyId,'reference_key'=>(new DateTimeImmutable('today'))->format('Y-m-d'),
          'title'=>'Pressão de caixa nos próximos 7 dias',
          'description'=>'Os pagamentos previstos superam os recebimentos em R$ ' . number_format($gap,2,',','.') . '.',
          'recommendation'=>'Revise vencimentos, antecipe recebimentos possíveis e evite novas saídas não essenciais.',
          'action_url'=>'financeiro.php','metric_value'=>$gap,'financial_impact'=>$gap,
          'metadata'=>['receivables'=>(float)($receivable['amount'] ?? 0),'payables'=>(float)($payable['amount'] ?? 0)],
        ];
      }
    }

    if ($code === 'SALES_DROP_TODAY') {
      $dropPct = brain_actions_setting_number($settings, 'drop_pct', 15);
      $minimumReference = brain_actions_setting_number($settings, 'minimum_reference_sales', 100);
      $now = new DateTimeImmutable();
      $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');
      $todayEnd = $now->format('Y-m-d H:i:s');
      $yesterday = (new DateTimeImmutable('yesterday'))->format('Y-m-d 00:00:00');
      $yesterdayComparableEnd = $now->modify('-1 day')->format('Y-m-d H:i:s');
      $row = brain_actions_query_row($pdo, "SELECT
          COALESCE(SUM(CASE WHEN recognized_at>=? AND recognized_at<? THEN total ELSE 0 END),0) today_sales,
          COALESCE(SUM(CASE WHEN recognized_at>=? AND recognized_at<? THEN total ELSE 0 END),0) yesterday_sales
        FROM pedidos WHERE company_id=? AND status='finalizado' AND recognized_at IS NOT NULL AND recognized_at>=? AND recognized_at<?", [$todayStart,$todayEnd,$yesterday,$yesterdayComparableEnd,$companyId,$yesterday,$todayEnd]);
      $todaySales = (float)($row['today_sales'] ?? 0);
      $reference = (float)($row['yesterday_sales'] ?? 0);
      $variation = $reference > 0 ? (($todaySales - $reference) / $reference) * 100 : 0;
      if ($reference >= $minimumReference && $variation <= -abs($dropPct)) {
        $loss = max(0.0, $reference - $todaySales);
        $alerts[] = [
          'entity_type'=>'company','entity_id'=>$companyId,'reference_key'=>(new DateTimeImmutable('today'))->format('Y-m-d'),
          'title'=>'Queda relevante nas vendas de hoje',
          'description'=>'As vendas estão ' . number_format(abs($variation),1,',','.') . '% abaixo de ontem, uma diferença de R$ ' . number_format($loss,2,',','.') . '.',
          'recommendation'=>'Compare canais, horários, ticket médio e produtos líderes antes de definir uma campanha.',
          'action_url'=>'pedidos.php','metric_value'=>$variation,'financial_impact'=>$loss,'metadata'=>$row,
        ];
      }
    }

    if ($code === 'LOW_MARGIN_TODAY') {
      $minimumSales = brain_actions_setting_number($settings, 'minimum_sales', 100);
      $minMargin = brain_actions_setting_number($settings, 'min_margin_pct', 20);
      $todayStart = (new DateTimeImmutable('today'))->format('Y-m-d 00:00:00');
      $tomorrow = (new DateTimeImmutable('tomorrow'))->format('Y-m-d 00:00:00');
      $row = brain_actions_query_row($pdo, "SELECT COALESCE(SUM(receita_liquida_produtos),0) revenue,COALESCE(SUM(lucro_bruto),0) profit
        FROM pedidos WHERE company_id=? AND status='finalizado' AND recognized_at IS NOT NULL AND recognized_at>=? AND recognized_at<?", [$companyId,$todayStart,$tomorrow]);
      $revenue = (float)($row['revenue'] ?? 0);
      $profit = (float)($row['profit'] ?? 0);
      $margin = $revenue > 0 ? ($profit / $revenue) * 100 : 0;
      if ($revenue >= $minimumSales && $margin < $minMargin) {
        $targetProfit = $revenue * ($minMargin / 100);
        $gap = max(0.0, $targetProfit - $profit);
        $alerts[] = [
          'entity_type'=>'company','entity_id'=>$companyId,'reference_key'=>(new DateTimeImmutable('today'))->format('Y-m-d'),
          'title'=>'Margem do dia abaixo da meta',
          'description'=>'A margem atual é ' . number_format($margin,1,',','.') . '%, abaixo da meta de ' . number_format($minMargin,1,',','.') . '%.',
          'recommendation'=>'Revise vendas com desconto, itens abaixo do custo e a composição do mix vendido hoje.',
          'action_url'=>'pedidos.php','metric_value'=>$margin,'financial_impact'=>$gap,'metadata'=>$row + ['target_margin'=>$minMargin],
        ];
      }
    }

    if ($code === 'DELAYED_OPEN_ORDER') {
      $minutes = (int)brain_actions_setting_number($settings, 'minutes', 45);
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 30);
      $cutoff = (new DateTimeImmutable())->modify('-' . max(5, $minutes) . ' minutes')->format('Y-m-d H:i:s');
      $rows = brain_actions_query_rows($pdo, "SELECT id,status,total,created_at,sales_channel,fulfillment_type,TIMESTAMPDIFF(MINUTE,created_at,NOW()) delay_minutes
        FROM pedidos WHERE company_id=? AND status IN ('aberto','confirmado','em_producao','saiu_entrega') AND created_at<?
        ORDER BY created_at ASC LIMIT " . max(1, min(200, $limit)), [$companyId,$cutoff]);
      foreach ($rows as $row) {
        $alerts[] = [
          'entity_type'=>'order','entity_id'=>(int)$row['id'],'reference_key'=>'open',
          'title'=>'Pedido em aberto há ' . (int)$row['delay_minutes'] . ' minutos',
          'description'=>'O pedido #' . (int)$row['id'] . ' permanece com status “' . (string)$row['status'] . '”.',
          'recommendation'=>'Confirme se o pedido está em produção, aguardando retirada ou entrega e atualize o status operacional.',
          'action_url'=>'pedido_editar.php?id=' . (int)$row['id'],'metric_value'=>(float)$row['delay_minutes'],'financial_impact'=>(float)$row['total'],'metadata'=>$row,
        ];
      }
    }

    if ($code === 'RADAR_NFE_PENDING') {
      if (!brain_actions_table_exists($pdo, 'fiscal_xml_radar')) return [];
      $days = (int)brain_actions_setting_number($settings, 'pending_days', 1);
      $limit = (int)brain_actions_setting_number($settings, 'max_alerts', 20);
      $cutoff = (new DateTimeImmutable('today'))->modify('-' . max(0, $days) . ' days')->format('Y-m-d 23:59:59');
      $rows = brain_actions_query_rows($pdo, "SELECT id,access_key,document_number,issuer_name,total_document,radar_status,COALESCE(issue_datetime,created_at) document_date
        FROM fiscal_xml_radar WHERE company_id=? AND direction='ENTRADA' AND radar_status IN ('NOVO','ANALISADO')
          AND COALESCE(issue_datetime,created_at)<=? ORDER BY COALESCE(issue_datetime,created_at) ASC
          LIMIT " . max(1, min(200, $limit)), [$companyId,$cutoff]);
      foreach ($rows as $row) {
        $alerts[] = [
          'entity_type'=>'fiscal_radar','entity_id'=>(int)$row['id'],'reference_key'=>(string)($row['access_key'] ?: $row['id']),
          'title'=>'NF-e recebida aguardando conferência',
          'description'=>'NF-e ' . ((string)$row['document_number'] !== '' ? 'nº ' . (string)$row['document_number'] . ' ' : '') . 'de ' . ((string)$row['issuer_name'] ?: 'fornecedor não identificado') . ', no valor de R$ ' . number_format((float)$row['total_document'],2,',','.') . '.',
          'recommendation'=>'Confira os produtos, vínculos e custos antes de gerar a entrada no estoque.',
          'action_url'=>'fiscal_radar_xml.php','metric_value'=>1,'financial_impact'=>(float)$row['total_document'],'metadata'=>$row,
        ];
      }
    }

    return $alerts;
  }
}

if (!function_exists('brain_actions_evaluate_company')) {
  function brain_actions_evaluate_company(PDO $pdo, int $companyId, string $trigger = 'manual'): array {
    $started = microtime(true);
    $stats = ['company_id'=>$companyId,'rules_evaluated'=>0,'alerts_detected'=>0,'created'=>0,'updated'=>0,'reopened'=>0,'resolved'=>0,'errors'=>[],'changed'=>false];
    if ($companyId <= 0 || !brain_actions_schema_ready($pdo)) {
      $stats['errors'][] = 'Estrutura brain_actions ainda não instalada.';
      return $stats;
    }

    $logId = 0;
    try {
      $st = $pdo->prepare("INSERT INTO brain_processing_log (company_id,trigger_source,status,started_at,created_at) VALUES (?,?,'processing',NOW(),NOW())");
      $st->execute([$companyId, mb_substr($trigger,0,40,'UTF-8')]);
      $logId = (int)$pdo->lastInsertId();
    } catch (Throwable $e) {}

    $rules = brain_actions_rule_catalog($pdo, $companyId);
    foreach ($rules as $rule) {
      $code = (string)$rule['rule_code'];
      if (empty($rule['enabled'])) {
        try {
          $stats['resolved'] += brain_actions_resolve_absent($pdo,$companyId,$code,[],'rule_disabled','A regra foi desativada para esta empresa.');
        } catch (Throwable $e) {
          $stats['errors'][] = $code . ': ' . $e->getMessage();
        }
        continue;
      }
      try {
        $alerts = brain_actions_detect_rule($pdo, $companyId, $rule);
        $stats['rules_evaluated']++;
        $fingerprints = [];
        foreach ($alerts as $alert) {
          $result = brain_actions_upsert_alert($pdo, $companyId, $rule, $alert);
          $fingerprints[] = (string)$result['fingerprint'];
          $stats['alerts_detected']++;
          $stats['created'] += (int)$result['created'];
          $stats['updated'] += (int)$result['updated'];
          $stats['reopened'] += (int)$result['reopened'];
        }
        $stats['resolved'] += brain_actions_resolve_absent($pdo, $companyId, $code, $fingerprints);
      } catch (Throwable $e) {
        $stats['errors'][] = $code . ': ' . $e->getMessage();
        error_log('[Nexa Brain Actions][' . $code . '] ' . $e->getMessage());
      }
    }

    $stats['changed'] = ($stats['created'] + $stats['updated'] + $stats['reopened'] + $stats['resolved']) > 0;
    $durationMs = (int)round((microtime(true) - $started) * 1000);
    if ($logId > 0) {
      try {
        $pdo->prepare("UPDATE brain_processing_log SET status=?,rules_evaluated=?,alerts_detected=?,alerts_created=?,alerts_updated=?,alerts_resolved=?,duration_ms=?,details=?,error_message=?,finished_at=NOW(),updated_at=NOW() WHERE id=?")
          ->execute([
            $stats['errors'] ? 'warning' : 'success',$stats['rules_evaluated'],$stats['alerts_detected'],$stats['created'],$stats['updated']+$stats['reopened'],$stats['resolved'],$durationMs,
            json_encode($stats, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$stats['errors'] ? implode("\n",$stats['errors']) : null,$logId
          ]);
      } catch (Throwable $e) {}
    }

    if ($stats['changed']) {
      brain_actions_enqueue_update($pdo, $companyId, 'brain.alerts.updated', [
        'created'=>$stats['created'],'updated'=>$stats['updated']+$stats['reopened'],'resolved'=>$stats['resolved'],'trigger'=>$trigger,
      ]);
    }
    return $stats;
  }
}

if (!function_exists('brain_actions_counts')) {
  function brain_actions_counts(PDO $pdo, int $companyId): array {
    $base = ['total'=>0,'critical'=>0,'high'=>0,'warning'=>0,'opportunity'=>0,'info'=>0,'financial_impact'=>0.0];
    if (!brain_actions_schema_ready($pdo)) return $base;
    try {
      $row = brain_actions_query_row($pdo, "SELECT COUNT(*) total,
          SUM(severity='critical') critical,SUM(severity='high') high,SUM(severity='warning') warning,
          SUM(severity='opportunity') opportunity,SUM(severity='info') info,
          COALESCE(SUM(financial_impact),0) financial_impact
        FROM brain_alerts WHERE company_id=? AND status='open'", [$companyId]);
      foreach ($base as $key=>$value) $base[$key] = isset($row[$key]) ? ($key === 'financial_impact' ? (float)$row[$key] : (int)$row[$key]) : $value;
    } catch (Throwable $e) {}
    return $base;
  }
}

if (!function_exists('brain_actions_list')) {
  function brain_actions_list(PDO $pdo, int $companyId, int $limit = 20, string $status = 'open'): array {
    if (!brain_actions_schema_ready($pdo)) return [];
    $allowed = ['open','snoozed','resolved','ignored','all'];
    if (!in_array($status,$allowed,true)) $status = 'open';
    $params = [$companyId];
    $where = 'company_id=?';
    if ($status !== 'all') { $where .= ' AND status=?'; $params[] = $status; }
    return brain_actions_query_rows($pdo, "SELECT * FROM brain_alerts WHERE {$where}
      ORDER BY FIELD(severity,'critical','high','warning','opportunity','info'),financial_impact DESC,last_detected_at DESC,id DESC
      LIMIT " . max(1,min(200,$limit)), $params);
  }
}

if (!function_exists('brain_actions_dashboard_data')) {
  function brain_actions_dashboard_data(PDO $pdo, int $companyId, int $limit = 12): array {
    return ['counts'=>brain_actions_counts($pdo,$companyId),'alerts'=>brain_actions_list($pdo,$companyId,$limit,'open')];
  }
}

if (!function_exists('brain_actions_csrf_token')) {
  function brain_actions_csrf_token(string $scope = 'brain_actions'): string {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    $key = 'csrf_' . preg_replace('/[^a-z0-9_]/i','_',$scope);
    if (empty($_SESSION[$key])) $_SESSION[$key] = bin2hex(random_bytes(24));
    return (string)$_SESSION[$key];
  }
}

if (!function_exists('brain_actions_csrf_check')) {
  function brain_actions_csrf_check(string $token, string $scope = 'brain_actions'): void {
    if ($token === '' || !hash_equals(brain_actions_csrf_token($scope), $token)) {
      throw new RuntimeException('Sessão expirada ou token de segurança inválido. Atualize a página e tente novamente.');
    }
  }
}

if (!function_exists('brain_actions_transition')) {
  function brain_actions_transition(PDO $pdo, int $companyId, int $alertId, string $action, int $userId = 0, string $note = ''): array {
    if (!brain_actions_schema_ready($pdo)) throw new RuntimeException('A Central de Ações ainda não foi instalada.');
    $alert = brain_actions_query_row($pdo, 'SELECT * FROM brain_alerts WHERE id=? AND company_id=? LIMIT 1', [$alertId,$companyId]);
    if (!$alert) throw new RuntimeException('Alerta não encontrado para esta empresa.');
    $from = (string)$alert['status'];
    $to = match ($action) {
      'resolve' => 'resolved',
      'snooze' => 'snoozed',
      'ignore' => 'ignored',
      'reopen' => 'open',
      default => throw new RuntimeException('Ação inválida.'),
    };
    $snoozedUntil = $to === 'snoozed' ? (new DateTimeImmutable('+1 day'))->format('Y-m-d H:i:s') : null;
    $resolvedAt = $to === 'resolved' ? date('Y-m-d H:i:s') : null;
    $pdo->beginTransaction();
    try {
      $pdo->prepare('UPDATE brain_alerts SET status=?,snoozed_until=?,resolved_at=?,last_action_by=?,updated_at=NOW() WHERE id=? AND company_id=?')
        ->execute([$to,$snoozedUntil,$resolvedAt,$userId ?: null,$alertId,$companyId]);
      $pdo->commit();
      brain_actions_history_add($pdo,$alertId,$companyId,$action,$from,$to,$note,$userId ?: null);
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    brain_actions_enqueue_update($pdo,$companyId,'brain.alert.action',['alert_id'=>$alertId,'action'=>$action,'from'=>$from,'to'=>$to]);
    return ['alert_id'=>$alertId,'from'=>$from,'to'=>$to,'snoozed_until'=>$snoozedUntil];
  }
}

if (!function_exists('brain_actions_save_rule_setting')) {
  function brain_actions_save_rule_setting(PDO $pdo, int $companyId, string $ruleCode, bool $enabled, array $submittedSettings, string $severityOverride = '', int $userId = 0): void {
    $catalog = brain_actions_rule_catalog($pdo,$companyId);
    if (!isset($catalog[$ruleCode])) throw new RuntimeException('Regra não encontrada.');
    $defaults = brain_actions_json($catalog[$ruleCode]['default_settings'] ?? []);
    $clean = [];
    foreach ($defaults as $key=>$default) {
      if (!array_key_exists($key,$submittedSettings)) continue;
      $value = $submittedSettings[$key];
      if (is_numeric($default)) {
        $normalized = str_replace(',','.',trim((string)$value));
        if (!is_numeric($normalized)) continue;
        $meta = brain_actions_setting_labels()[$key] ?? [];
        $integerField = (string)($meta['step'] ?? '') === '1';
        $clean[$key] = $integerField ? (int)$normalized : (float)$normalized;
      } elseif (is_bool($default)) {
        $clean[$key] = !empty($value);
      } else {
        $clean[$key] = mb_substr(trim((string)$value),0,120,'UTF-8');
      }
    }
    $severityOverride = $severityOverride === '' ? '' : brain_actions_severity($severityOverride,'');
    $pdo->prepare("INSERT INTO brain_company_rule_settings (company_id,rule_code,is_enabled,severity_override,settings,updated_by,created_at,updated_at)
      VALUES (?,?,?,?,?,?,NOW(),NOW())
      ON DUPLICATE KEY UPDATE is_enabled=VALUES(is_enabled),severity_override=VALUES(severity_override),settings=VALUES(settings),updated_by=VALUES(updated_by),updated_at=NOW()")
      ->execute([$companyId,$ruleCode,$enabled ? 1 : 0,$severityOverride !== '' ? $severityOverride : null,json_encode($clean,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId ?: null]);
  }
}
