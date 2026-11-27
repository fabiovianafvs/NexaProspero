<?php
/**
 * Números dos documentos fiscais de cada pedido (NFC-e, NF-e, NFS-e e CT-e) e do
 * pedido de cada conta a receber — usados nas listas de Pedidos e Contas a Receber.
 *
 * Só leitura. Entram os documentos autorizados e os cancelados (marcados como
 * cancelados). NFS-e mostra o número da NFS-e (ou o da DPS, se ainda não houver).
 * Documentos da O.S. aparecem no pedido faturado da O.S. (os_orders.pedido_id).
 * CT-e não tem pedido: aparece no pedido cuja NF-e ele transporta (chave da NF-e
 * no CT-e).
 */
declare(strict_types=1);

if (!function_exists('ofn_table_exists')) {
  function ofn_table_exists(PDO $pdo, string $table): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!preg_match('/^[a-z0-9_]+$/', $table)) return $cache[$key] = false;
    try { $pdo->query('SELECT 1 FROM `' . $table . '` WHERE 1=0'); return $cache[$key] = true; }
    catch (Throwable $e) { return $cache[$key] = false; }
  }
}

if (!function_exists('ofn_column_exists')) {
  function ofn_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . $table . '.' . $column;
    if (array_key_exists($key, $cache)) return $cache[$key];
    if (!preg_match('/^[a-z0-9_]+$/', $table) || !preg_match('/^[a-z0-9_]+$/', $column)) return $cache[$key] = false;
    try { $pdo->query('SELECT `' . $column . '` FROM `' . $table . '` WHERE 1=0'); return $cache[$key] = true; }
    catch (Throwable $e) { return $cache[$key] = false; }
  }
}

if (!function_exists('ofn_ids')) {
  function ofn_ids(array $ids, int $max = 1000): array {
    $out = [];
    foreach ($ids as $id) { $id = (int)$id; if ($id > 0) $out[$id] = $id; }
    return array_slice(array_values($out), 0, $max);
  }
}

if (!function_exists('ofn_label')) {
  function ofn_label(string $model): string {
    $model = strtoupper(trim($model));
    return ['65' => 'NFC-e', '55' => 'NF-e', 'NFSE' => 'NFS-e', '57' => 'CT-e'][$model] ?? $model;
  }
}

if (!function_exists('order_fiscal_numbers_map')) {
  /**
   * [pedido_id => [['type'=>'NFC-e','number'=>'123','canceled'=>false,'key'=>'...'], ...]]
   */
  function order_fiscal_numbers_map(PDO $pdo, int $companyId, array $orderIds): array {
    $orderIds = ofn_ids($orderIds);
    if ($companyId <= 0 || !$orderIds || !ofn_table_exists($pdo, 'fiscal_documents')) return [];
    $hasNfse = ofn_table_exists($pdo, 'fiscal_nfse_details');
    $statusExpr = $hasNfse ? "UPPER(COALESCE(NULLIF(nd.status,''), d.status, ''))" : "UPPER(COALESCE(d.status,''))";
    $numberExpr = $hasNfse ? "CASE WHEN d.model='NFSE' AND COALESCE(nd.nfse_number,'')<>'' THEN nd.nfse_number ELSE d.number END" : 'd.number';
    $join = $hasNfse ? ' LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id' : '';
    $out = [];
    $add = static function (int $orderId, array $row) use (&$out): void {
      $number = ltrim(trim((string)($row['doc_number'] ?? '')), '0');
      if ($orderId <= 0 || $number === '') return;
      $type = ofn_label((string)($row['model'] ?? ''));
      $dedupe = $type . ':' . $number;
      foreach ($out[$orderId] ?? [] as $existing) if ($existing['type'] . ':' . $existing['number'] === $dedupe) return;
      $out[$orderId][] = [
        'type' => $type,
        'number' => $number,
        'canceled' => strtoupper((string)($row['doc_status'] ?? '')) === 'CANCELADA',
        'key' => preg_replace('/\D+/', '', (string)($row['access_key'] ?? '')) ?: '',
        'model' => strtoupper((string)($row['model'] ?? '')),
      ];
    };
    $select = "SELECT d.id, d.model, d.source_type, d.source_id, d.access_key, $numberExpr AS doc_number, $statusExpr AS doc_status FROM fiscal_documents d$join";
    $ph = implode(',', array_fill(0, count($orderIds), '?'));
    try {
      $st = $pdo->prepare("$select WHERE d.company_id=? AND d.direction='SAIDA' AND d.model IN ('55','65','NFSE') AND d.source_type='PEDIDO' AND d.source_id IN ($ph) ORDER BY d.id");
      $st->execute(array_merge([$companyId], $orderIds));
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        if (!in_array((string)$r['doc_status'], ['AUTORIZADA', 'CANCELADA'], true)) continue;
        $add((int)$r['source_id'], $r);
      }
    } catch (Throwable $e) {}

    // O.S. faturada em pedido: as notas ficam na O.S. (source_type ORDEM_SERVICO).
    if (ofn_column_exists($pdo, 'os_orders', 'pedido_id')) {
      try {
        $st = $pdo->prepare("SELECT id, pedido_id FROM os_orders WHERE company_id=? AND pedido_id IN ($ph)");
        $st->execute(array_merge([$companyId], $orderIds));
        $osMap = [];
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $osMap[(int)$r['id']] = (int)$r['pedido_id'];
        if ($osMap) {
          $osIds = array_keys($osMap);
          $ph2 = implode(',', array_fill(0, count($osIds), '?'));
          $st = $pdo->prepare("$select WHERE d.company_id=? AND d.direction='SAIDA' AND d.model IN ('55','65','NFSE') AND d.source_type='ORDEM_SERVICO' AND d.source_id IN ($ph2) ORDER BY d.id");
          $st->execute(array_merge([$companyId], $osIds));
          foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
            if (!in_array((string)$r['doc_status'], ['AUTORIZADA', 'CANCELADA'], true)) continue;
            $add((int)($osMap[(int)$r['source_id']] ?? 0), $r);
          }
        }
      } catch (Throwable $e) {}
    }

    // CT-e que transporta a NF-e do pedido.
    $keyToOrder = [];
    foreach ($out as $orderId => $docs) foreach ($docs as $doc) if ($doc['model'] === '55' && strlen($doc['key']) === 44) $keyToOrder[$doc['key']] = $orderId;
    if ($keyToOrder && ofn_table_exists($pdo, 'cte_documentos')) {
      try {
        $likes = [];
        $params = [$companyId];
        foreach (array_keys($keyToOrder) as $k) { $likes[] = 'dados_json LIKE ?'; $params[] = '%' . $k . '%'; }
        $st = $pdo->prepare("SELECT numero, status, dados_json FROM cte_documentos WHERE company_id=? AND numero IS NOT NULL AND status IN ('AUTORIZADO','CANCELADO') AND (" . implode(' OR ', $likes) . ')');
        $st->execute($params);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
          $data = json_decode((string)($r['dados_json'] ?? ''), true);
          foreach ((array)($data['documentos']['nfe'] ?? []) as $nfe) {
            $k = preg_replace('/\D+/', '', (string)($nfe['chave'] ?? '')) ?: '';
            if (isset($keyToOrder[$k])) {
              $add($keyToOrder[$k], ['model' => '57', 'doc_number' => (string)$r['numero'], 'doc_status' => strtoupper((string)$r['status']) === 'CANCELADO' ? 'CANCELADA' : 'AUTORIZADA']);
            }
          }
        }
      } catch (Throwable $e) {}
    }
    return $out;
  }
}

if (!function_exists('order_fiscal_numbers_text')) {
  /** Texto simples para pesquisa/ordenação: "NFC-e 123 · NFS-e 45". */
  function order_fiscal_numbers_text(array $docs): string {
    $parts = [];
    foreach ($docs as $d) $parts[] = $d['type'] . ' ' . $d['number'] . (!empty($d['canceled']) ? ' (cancelada)' : '');
    return implode(' · ', $parts);
  }
}

if (!function_exists('order_fiscal_numbers_html')) {
  function order_fiscal_numbers_html(array $docs): string {
    if (!$docs) return '<span class="ofn-none">—</span>';
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $parts = [];
    foreach ($docs as $d) {
      $parts[] = '<span class="ofn-doc' . (!empty($d['canceled']) ? ' ofn-canceled' : '') . '"' . (!empty($d['canceled']) ? ' title="Cancelada"' : '') . '><small>'
        . $h($d['type']) . '</small> ' . $h($d['number']) . '</span>';
    }
    return implode(' ', $parts);
  }
}

if (!function_exists('order_fiscal_numbers_search_orders')) {
  /**
   * Pedidos cujo documento fiscal tem o número (ou a chave de acesso) informado.
   * Inclui O.S. faturadas e CT-e (pelo número do CT-e → NF-e transportada → pedido).
   */
  function order_fiscal_numbers_search_orders(PDO $pdo, int $companyId, string $digits, int $limit = 500): array {
    $digits = preg_replace('/\D+/', '', $digits) ?: '';
    if ($companyId <= 0 || $digits === '' || strlen($digits) > 44 || !ofn_table_exists($pdo, 'fiscal_documents')) return [];
    $isKey = strlen($digits) === 44;
    $num = ltrim($digits, '0');
    if (!$isKey && ($num === '' || strlen($num) > 15)) return [];
    $hasNfse = ofn_table_exists($pdo, 'fiscal_nfse_details');
    $docIds = [];
    try {
      if ($isKey) {
        $st = $pdo->prepare("SELECT id, model, source_type, source_id, access_key FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND access_key=? LIMIT 50");
        $st->execute([$companyId, $digits]);
      } else {
        // Número da NF-e/NFC-e (ou da DPS) — usa o índice (company_id, model, series, number).
        $st = $pdo->prepare("SELECT d.number AS number_found, d.id, d.model, d.source_type, d.source_id, d.access_key FROM fiscal_documents d WHERE d.company_id=? AND d.direction='SAIDA' AND d.model IN ('55','65','NFSE') AND d.number=? LIMIT " . (int)$limit);
        $st->execute([$companyId, (int)$num]);
        if ($hasNfse) {
          $rowsA = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
          // Número da NFS-e (pode vir com zeros à esquerda, ex.: "00000088").
          $st = $pdo->prepare("SELECT nd.nfse_number AS nfse_number_found, d.id, d.model, d.source_type, d.source_id, d.access_key FROM fiscal_nfse_details nd JOIN fiscal_documents d ON d.company_id=nd.company_id AND d.id=nd.fiscal_document_id WHERE nd.company_id=? AND (nd.nfse_number=? OR nd.nfse_number LIKE ?) LIMIT " . (int)$limit);
          $st->execute([$companyId, $num, '%0' . $num]);
          $docIds = array_merge($rowsA, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
          $st = null;
        }
      }
      if ($st) $docIds = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      // NFS-e com zeros à esquerda ("00000088"): confere o número sem os zeros.
      if (!$isKey) {
        $docIds = array_values(array_filter($docIds, static function (array $r) use ($num): bool {
          if (ltrim((string)($r['number_found'] ?? ''), '0') === $num) return true;
          return ltrim((string)($r['nfse_number_found'] ?? ''), '0') === $num;
        }));
      }
    } catch (Throwable $e) { $docIds = []; }

    // CT-e: número do CT-e → chaves de NF-e → documentos fiscais.
    if (!$isKey && ofn_table_exists($pdo, 'cte_documentos')) {
      try {
        $st = $pdo->prepare("SELECT dados_json FROM cte_documentos WHERE company_id=? AND numero=? AND status IN ('AUTORIZADO','CANCELADO') LIMIT 20");
        $st->execute([$companyId, (int)$num]);
        $keys = [];
        foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $json) {
          $data = json_decode((string)$json, true);
          foreach ((array)($data['documentos']['nfe'] ?? []) as $nfe) {
            $k = preg_replace('/\D+/', '', (string)($nfe['chave'] ?? '')) ?: '';
            if (strlen($k) === 44) $keys[$k] = $k;
          }
        }
        if ($keys) {
          $ph = implode(',', array_fill(0, count($keys), '?'));
          $st = $pdo->prepare("SELECT id, model, source_type, source_id, access_key FROM fiscal_documents WHERE company_id=? AND direction='SAIDA' AND access_key IN ($ph)");
          $st->execute(array_merge([$companyId], array_values($keys)));
          $docIds = array_merge($docIds, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
        }
      } catch (Throwable $e) {}
    }

    $orders = [];
    $osIds = [];
    foreach ($docIds as $r) {
      $type = strtoupper((string)($r['source_type'] ?? ''));
      if ($type === 'PEDIDO') $orders[(int)$r['source_id']] = (int)$r['source_id'];
      elseif ($type === 'ORDEM_SERVICO') $osIds[(int)$r['source_id']] = (int)$r['source_id'];
    }
    if ($osIds && ofn_column_exists($pdo, 'os_orders', 'pedido_id')) {
      try {
        $ph = implode(',', array_fill(0, count($osIds), '?'));
        $st = $pdo->prepare("SELECT pedido_id FROM os_orders WHERE company_id=? AND id IN ($ph) AND COALESCE(pedido_id,0)>0");
        $st->execute(array_merge([$companyId], array_values($osIds)));
        foreach (($st->fetchAll(PDO::FETCH_COLUMN) ?: []) as $pid) $orders[(int)$pid] = (int)$pid;
      } catch (Throwable $e) {}
    }
    unset($orders[0]);
    return array_slice(array_values($orders), 0, $limit);
  }
}

if (!function_exists('receivable_orders_map')) {
  /**
   * [receivable_id => pedido_id] pelos mesmos vínculos da consulta "Ver produtos":
   * finance_receivables.order_id → gestao_order_payments.receivable_id →
   * gestao_order_payments.receivable_ids → finance_boleto_charges.order_id.
   */
  function receivable_orders_map(PDO $pdo, int $companyId, array $rows): array {
    $out = [];
    $ids = [];
    foreach ($rows as $r) {
      $rid = (int)($r['id'] ?? 0);
      if ($rid <= 0) continue;
      $ids[$rid] = $rid;
      if ((int)($r['order_id'] ?? 0) > 0) $out[$rid] = (int)$r['order_id'];
    }
    $missing = static function () use (&$ids, &$out): array { return array_values(array_diff_key($ids, $out)); };
    if ($companyId <= 0 || !$ids) return $out;
    // order_id do título (a linha da lista pode não ter trazido a coluna).
    if ($missing() && ofn_column_exists($pdo, 'finance_receivables', 'order_id')) {
      try {
        $m = $missing(); $ph = implode(',', array_fill(0, count($m), '?'));
        $st = $pdo->prepare("SELECT id, order_id FROM finance_receivables WHERE company_id=? AND id IN ($ph) AND COALESCE(order_id,0)>0");
        $st->execute(array_merge([$companyId], $m));
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out[(int)$r['id']] = (int)$r['order_id'];
      } catch (Throwable $e) {}
    }
    if ($missing() && ofn_column_exists($pdo, 'gestao_order_payments', 'receivable_id')) {
      try {
        $m = $missing(); $ph = implode(',', array_fill(0, count($m), '?'));
        $st = $pdo->prepare("SELECT receivable_id, order_id FROM gestao_order_payments WHERE company_id=? AND receivable_id IN ($ph) AND COALESCE(order_id,0)>0 ORDER BY id DESC");
        $st->execute(array_merge([$companyId], $m));
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) if (!isset($out[(int)$r['receivable_id']])) $out[(int)$r['receivable_id']] = (int)$r['order_id'];
      } catch (Throwable $e) {}
    }
    // Vendas parceladas: lista "12,13,14" em receivable_ids (comparação feita no PHP).
    if ($missing() && ofn_column_exists($pdo, 'gestao_order_payments', 'receivable_ids')) {
      try {
        $m = $missing();
        $likes = [];
        $params = [$companyId];
        foreach (array_slice($m, 0, 300) as $rid) { $likes[] = 'receivable_ids LIKE ?'; $params[] = '%' . $rid . '%'; }
        $st = $pdo->prepare("SELECT order_id, receivable_ids FROM gestao_order_payments WHERE company_id=? AND COALESCE(order_id,0)>0 AND receivable_ids IS NOT NULL AND receivable_ids<>'' AND (" . implode(' OR ', $likes) . ") ORDER BY id DESC LIMIT 2000");
        $st->execute($params);
        $wanted = array_flip($m);
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
          foreach (preg_split('/\s*,\s*/', trim((string)$r['receivable_ids']), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $rid) {
            $rid = (int)$rid;
            if (isset($wanted[$rid]) && !isset($out[$rid])) $out[$rid] = (int)$r['order_id'];
          }
        }
      } catch (Throwable $e) {}
    }
    if ($missing() && ofn_column_exists($pdo, 'finance_boleto_charges', 'order_id')) {
      try {
        $m = $missing(); $ph = implode(',', array_fill(0, count($m), '?'));
        $st = $pdo->prepare("SELECT receivable_id, order_id FROM finance_boleto_charges WHERE company_id=? AND receivable_id IN ($ph) AND COALESCE(order_id,0)>0 ORDER BY id DESC");
        $st->execute(array_merge([$companyId], $m));
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) if (!isset($out[(int)$r['receivable_id']])) $out[(int)$r['receivable_id']] = (int)$r['order_id'];
      } catch (Throwable $e) {}
    }
    return $out;
  }
}

if (!function_exists('order_numbers_display_map')) {
  /** [pedido_id => número exibido (numero_pedido ou id)], só pedidos da empresa. */
  function order_numbers_display_map(PDO $pdo, int $companyId, array $orderIds): array {
    $orderIds = ofn_ids($orderIds);
    if ($companyId <= 0 || !$orderIds) return [];
    $out = [];
    try {
      $ph = implode(',', array_fill(0, count($orderIds), '?'));
      $cols = ofn_column_exists($pdo, 'pedidos', 'numero_pedido') ? 'id, numero_pedido' : 'id';
      $st = $pdo->prepare("SELECT $cols FROM pedidos WHERE company_id=? AND id IN ($ph)");
      $st->execute(array_merge([$companyId], $orderIds));
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $n = function_exists('company_sequence_display') ? (string)company_sequence_display($r, 'pedidos') : '';
        if ($n === '' || $n === '0') $n = (int)($r['numero_pedido'] ?? 0) > 0 ? (string)(int)$r['numero_pedido'] : (string)(int)$r['id'];
        $out[(int)$r['id']] = $n;
      }
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('order_fiscal_numbers_css')) {
  function order_fiscal_numbers_css(): string {
    return '<style>.ofn-doc{display:inline-block;white-space:nowrap;margin:1px 6px 1px 0;font-weight:700;color:#0f172a}.ofn-doc small{font-size:10px;font-weight:800;color:#64748b;text-transform:none}.ofn-canceled{color:#94a3b8;text-decoration:line-through}.ofn-none{color:#94a3b8}</style>';
  }
}
