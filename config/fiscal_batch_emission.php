<?php
/**
 * V11.43 - Emissão fiscal em lote por Pedido.
 *
 * A camada é apenas orquestradora: cada Pedido continua gerando seu próprio
 * documento fiscal e reutiliza os emissores já homologados (NFS-e e NFC-e).
 */
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/fiscal_operational.php';


if (!function_exists('fiscal_batch_provider_code')) {
  function fiscal_batch_provider_code(PDO $pdo, int $companyId): string {
    try {
      $cfg = fiscal_company_config($pdo, $companyId);
      $v = strtoupper(trim((string)($cfg['nfse_provider'] ?? 'NACIONAL')));
      if (in_array($v, ['PRONIM','GOVBR','ISS_DIGITAL','PRONIM_GOVBR','GOVBR_ISS_DIGITAL'], true)) return 'PRONIM';
    } catch (Throwable $e) {}
    return 'NACIONAL';
  }
}

if (!function_exists('fiscal_batch_provider_label')) {
  function fiscal_batch_provider_label(string $provider): string {
    return strtoupper(trim($provider)) === 'PRONIM' ? 'Pronim / GOVBR - ISS Digital' : 'Ambiente Nacional';
  }
}

if (!function_exists('fiscal_batch_require_nfse_provider')) {
  function fiscal_batch_require_nfse_provider(): void {
    if (function_exists('nfse_provider_authorize_order')) return;
    $file = __DIR__ . '/nfse_provider_router.php';
    if (!is_file($file)) throw new RuntimeException('Arquivo do provider NFS-e não encontrado: config/nfse_provider_router.php.');
    require_once $file;
    if (!function_exists('nfse_provider_authorize_order')) throw new RuntimeException('Provider NFS-e não pôde ser carregado.');
  }
}

if (!function_exists('fiscal_batch_h')) {
  function fiscal_batch_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('fiscal_batch_order_number_column')) {
  function fiscal_batch_order_number_column(PDO $pdo): string {
    try {
      if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'pedidos', 'numero_pedido')) return 'p.numero_pedido';
    } catch (Throwable $e) {}
    return 'p.id';
  }
}

if (!function_exists('fiscal_batch_order_source_expr')) {
  function fiscal_batch_order_source_expr(PDO $pdo): string {
    try {
      if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'pedidos', 'source')) return "COALESCE(p.source,'')";
    } catch (Throwable $e) {}
    return "''";
  }
}

if (!function_exists('fiscal_batch_order_contract_expr')) {
  function fiscal_batch_order_contract_expr(PDO $pdo): string {
    try {
      if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'pedidos', 'contract_id')) return 'COALESCE(p.contract_id,0)';
    } catch (Throwable $e) {}
    return '0';
  }
}

if (!function_exists('fiscal_batch_latest_documents')) {
  function fiscal_batch_latest_documents(PDO $pdo, int $companyId, array $orderIds): array {
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds || !function_exists('fiscal_table_exists') || !fiscal_table_exists($pdo, 'fiscal_documents')) return [];
    try {
      $ph = implode(',', array_fill(0, count($orderIds), '?'));
      $hasNfse = fiscal_table_exists($pdo, 'fiscal_nfse_details');
      $select = "d.id,d.source_id AS pedido_id,d.model,d.status,d.series,d.number,d.access_key,d.sefaz_status_code,d.sefaz_status_message";
      $join = '';
      if ($hasNfse) {
        $select .= ",nd.status AS nfse_status,nd.nfse_number,nd.dps_series,nd.dps_number";
        $join = " LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id ";
      } else {
        $select .= ",NULL AS nfse_status,NULL AS nfse_number,NULL AS dps_series,NULL AS dps_number";
      }
      $sql = "SELECT $select FROM fiscal_documents d $join
               WHERE d.company_id=? AND d.direction='SAIDA' AND d.source_type='PEDIDO'
                 AND d.source_id IN ($ph) AND d.model IN ('55','65','NFSE')
            ORDER BY d.id DESC";
      $st = $pdo->prepare($sql);
      $st->execute(array_merge([$companyId], $orderIds));
      $map = [];
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['pedido_id'] ?? 0);
        $model = strtoupper(trim((string)($r['model'] ?? '')));
        if ($oid <= 0 || $model === '' || isset($map[$oid][$model])) continue;
        if ($model === 'NFSE' && trim((string)($r['nfse_status'] ?? '')) !== '') $r['status'] = (string)$r['nfse_status'];
        $map[$oid][$model] = $r;
      }
      return $map;
    } catch (Throwable $e) {
      // A listagem do lote nunca deve derrubar a página por incompatibilidade
      // de uma coluna auxiliar. A emissão individual fará a validação completa.
      return [];
    }
  }
}

if (!function_exists('fiscal_batch_contract_map')) {
  function fiscal_batch_contract_map(PDO $pdo, int $companyId, array $orderIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$ids || !function_exists('fiscal_table_exists') || !fiscal_table_exists($pdo, 'contract_generations')) return [];
    try {
      $ph = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("SELECT order_id,contract_id,id AS generation_id FROM contract_generations WHERE company_id=? AND order_id IN ($ph)");
      $st->execute(array_merge([$companyId], $ids));
      $map = [];
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $oid = (int)($r['order_id'] ?? 0);
        if ($oid > 0) $map[$oid] = $r;
      }
      return $map;
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('fiscal_batch_candidate_orders')) {
  function fiscal_batch_candidate_orders(PDO $pdo, int $companyId, string $dateIni, string $dateFim, string $origin = 'TODOS', string $q = '', int $limit = 250): array {
    $origin = strtoupper(trim($origin));
    if (!in_array($origin, ['TODOS','CONTRATOS','VENDAS'], true)) $origin = 'TODOS';
    $dateIni = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateIni) ? $dateIni : date('Y-m-d');
    $dateFim = preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateFim) ? $dateFim : $dateIni;
    if ($dateFim < $dateIni) [$dateIni,$dateFim] = [$dateFim,$dateIni];
    $limit = max(1, min(500, $limit));

    $numberExpr = fiscal_batch_order_number_column($pdo);
    $where = ["p.company_id=?", "DATE(p.created_at)>=?", "DATE(p.created_at)<=?", "LOWER(COALESCE(p.status,'')) NOT IN ('cancelado','cancelada','canceled')"];
    $params = [$companyId, $dateIni, $dateFim];

    $hasContracts = function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'contract_generations');
    if ($origin === 'CONTRATOS' && $hasContracts) {
      $where[] = "EXISTS (SELECT 1 FROM contract_generations cg WHERE cg.company_id=p.company_id AND cg.order_id=p.id)";
    } elseif ($origin === 'VENDAS' && $hasContracts) {
      $where[] = "NOT EXISTS (SELECT 1 FROM contract_generations cg WHERE cg.company_id=p.company_id AND cg.order_id=p.id)";
    }

    $q = trim($q);
    if ($q !== '') {
      $where[] = "(CAST($numberExpr AS CHAR) LIKE ? OR CAST(p.id AS CHAR) LIKE ? OR COALESCE(p.nome_cliente,'') LIKE ?)";
      $like = nexa_search_like($q);
      array_push($params, $like, $like, $like);
    }

    // Evita SQL paralela para classificação de itens. A Central de Pedidos já
    // possui um helper tolerante a diferenças de schema para este trabalho.
    $sql = "SELECT p.id, $numberExpr AS numero_pedido, p.created_at, p.status, p.nome_cliente, p.total
              FROM pedidos p
             WHERE " . implode(' AND ', $where) . "
          ORDER BY p.created_at DESC,p.id DESC LIMIT $limit";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    $ids = array_map('intval', array_column($rows, 'id'));
    $composition = function_exists('fiscal_order_composition_map') ? fiscal_order_composition_map($pdo, $companyId, $ids) : [];
    $contracts = fiscal_batch_contract_map($pdo, $companyId, $ids);
    $docs = fiscal_batch_latest_documents($pdo, $companyId, $ids);
    foreach ($rows as &$r) {
      $oid = (int)$r['id'];
      $comp = $composition[$oid] ?? ['service_count'=>0,'product_count'=>0,'item_count'=>0];
      $r['service_count'] = (int)($comp['service_count'] ?? 0);
      $r['product_count'] = (int)($comp['product_count'] ?? 0);
      $r['item_count'] = (int)($comp['item_count'] ?? 0);
      $r['contract_id'] = (int)($contracts[$oid]['contract_id'] ?? 0);
      $r['source'] = $r['contract_id'] > 0 ? 'contract' : '';
      $r['documents'] = $docs[$oid] ?? [];
    }
    unset($r);
    return $rows;
  }
}

if (!function_exists('fiscal_batch_candidate_order')) {
  function fiscal_batch_candidate_order(PDO $pdo, int $companyId, int $orderId): ?array {
    if ($orderId <= 0) return null;
    $numberExpr = fiscal_batch_order_number_column($pdo);
    $sql = "SELECT p.id, $numberExpr AS numero_pedido, p.created_at, p.status, p.nome_cliente, p.total
              FROM pedidos p
             WHERE p.company_id=? AND p.id=? LIMIT 1";
    $st = $pdo->prepare($sql);
    $st->execute([$companyId,$orderId]);
    $row = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$row) return null;
    if (in_array(strtolower(trim((string)($row['status'] ?? ''))), ['cancelado','cancelada','canceled'], true)) return null;

    $comp = function_exists('fiscal_order_composition') ? fiscal_order_composition($pdo, $companyId, $orderId) : ['service_count'=>0,'product_count'=>0,'item_count'=>0];
    $contracts = fiscal_batch_contract_map($pdo, $companyId, [$orderId]);
    $row['service_count'] = (int)($comp['service_count'] ?? 0);
    $row['product_count'] = (int)($comp['product_count'] ?? 0);
    $row['item_count'] = (int)($comp['item_count'] ?? 0);
    $row['contract_id'] = (int)($contracts[$orderId]['contract_id'] ?? 0);
    $row['source'] = $row['contract_id'] > 0 ? 'contract' : '';
    $docs = fiscal_batch_latest_documents($pdo, $companyId, [$orderId]);
    $row['documents'] = $docs[$orderId] ?? [];
    return $row;
  }
}

if (!function_exists('fiscal_batch_doc_status')) {
  function fiscal_batch_doc_status(?array $doc): string {
    return strtoupper(trim((string)($doc['status'] ?? '')));
  }
}

if (!function_exists('fiscal_batch_candidate_state')) {
  function fiscal_batch_candidate_state(array $row, string $type): array {
    $type = strtoupper(trim($type));
    $source = strtolower(trim((string)($row['source'] ?? '')));
    if ($source === 'ordem_servico_lote') {
      return ['eligible'=>false,'label'=>'Fila O.S.','message'=>'Pedido originado da O.S. usa a fila fiscal exclusiva da O.S.'];
    }
    if ($type === 'NFSE') {
      if ((int)($row['service_count'] ?? 0) <= 0) return ['eligible'=>false,'label'=>'Sem serviço','message'=>'Pedido não possui item de serviço.'];
      $doc = $row['documents']['NFSE'] ?? null;
      $status = fiscal_batch_doc_status($doc);
      if ($status === 'AUTORIZADA') return ['eligible'=>false,'label'=>'Autorizada','message'=>'NFS-e já autorizada.'];
      if ($status === 'CANCELADA') return ['eligible'=>false,'label'=>'Cancelada','message'=>'A NFS-e deste pedido foi cancelada; reemissão exige novo fluxo/documento.'];
      if (in_array($status, ['TRANSMITINDO','ERRO_COMUNICACAO','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
        return ['eligible'=>false,'label'=>'Consultar','message'=>'Retorno fiscal pendente/inconclusivo. Consulte antes de novo envio.'];
      }
      if ($status === 'REJEITADA') return ['eligible'=>true,'label'=>'Rejeitada','message'=>'Será reconstruída com o cadastro fiscal atual e retransmitida.'];
      if ($status === 'ASSINADA') return ['eligible'=>true,'label'=>'Assinada','message'=>'DPS assinada pronta para transmissão.'];
      return ['eligible'=>true,'label'=>'Pendente','message'=>'Será gerada, assinada e transmitida.'];
    }

    if ((int)($row['product_count'] ?? 0) <= 0) return ['eligible'=>false,'label'=>'Sem mercadoria','message'=>'Pedido não possui mercadoria para NFC-e.'];
    $nfe = $row['documents']['55'] ?? null;
    $nfeStatus = fiscal_batch_doc_status($nfe);
    if (in_array($nfeStatus, ['AUTORIZADA','CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
      return ['eligible'=>false,'label'=>'NF-e existente','message'=>'O pedido já possui NF-e emitida/pendente e não pode gerar NFC-e.'];
    }
    $doc = $row['documents']['65'] ?? null;
    $status = fiscal_batch_doc_status($doc);
    if ($status === 'AUTORIZADA') return ['eligible'=>false,'label'=>'Autorizada','message'=>'NFC-e já autorizada.'];
    if ($status === 'CANCELADA') return ['eligible'=>false,'label'=>'Cancelada','message'=>'A NFC-e deste pedido foi cancelada; reemissão exige novo fluxo/documento.'];
    if (in_array($status, ['PENDENTE_RETORNO','EM_PROCESSAMENTO','DUPLICIDADE','CONSULTADA'], true)) {
      return ['eligible'=>false,'label'=>'Consultar','message'=>'Retorno SEFAZ pendente/inconclusivo. Consulte antes de novo envio.'];
    }
    if (in_array($status, ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE'], true)) return ['eligible'=>true,'label'=>'Contingência','message'=>'Documento existente será transmitido pelo fluxo de contingência.'];
    if ($status === 'REJEITADA') return ['eligible'=>true,'label'=>'Rejeitada','message'=>'Será regenerada com regras fiscais atuais e retransmitida.'];
    return ['eligible'=>true,'label'=>'Pendente','message'=>'Será gerada e transmitida.'];
  }
}


if (!function_exists('fiscal_batch_record_contract_nfse')) {
  function fiscal_batch_record_contract_nfse(PDO $pdo, int $companyId, int $orderId, int $docId, string $nfseNumber = ''): void {
    try {
      if (!function_exists('fiscal_table_exists') || !fiscal_table_exists($pdo, 'contract_generations') || !fiscal_table_exists($pdo, 'contract_billing_history')) return;
      $st = $pdo->prepare("SELECT g.id generation_id,g.contract_id,c.contract_number FROM contract_generations g LEFT JOIN contracts c ON c.id=g.contract_id AND c.company_id=g.company_id WHERE g.company_id=? AND g.order_id=? LIMIT 1");
      $st->execute([$companyId,$orderId]);
      $g = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$g) return;
      $check = $pdo->prepare("SELECT id FROM contract_billing_history WHERE company_id=? AND generation_id=? AND origin_type='NFSE' AND origin_id=? AND event_type='NFSE_AUTORIZADA' LIMIT 1");
      $check->execute([$companyId,(int)$g['generation_id'],$docId]);
      if ((int)($check->fetchColumn() ?: 0) > 0) return;
      $desc = 'NFS-e' . ($nfseNumber !== '' ? ' #' . $nfseNumber : '') . ' autorizada para o Pedido #' . $orderId;
      $uid = isset($_SESSION['user_id']) ? (int)$_SESSION['user_id'] : null;
      $pdo->prepare("INSERT INTO contract_billing_history(company_id,contract_id,generation_id,origin_type,origin_id,event_type,description,event_at,created_by) VALUES(?,?,?,'NFSE',?,'NFSE_AUTORIZADA',?,NOW(),?)")
          ->execute([$companyId,(int)$g['contract_id'],(int)$g['generation_id'],$docId,$desc,$uid ?: null]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('fiscal_batch_process_orders')) {
  function fiscal_batch_process_orders(PDO $pdo, int $companyId, array $orderIds, string $type, bool $productionConfirmed = false): array {
    $type = strtoupper(trim($type));
    if (!in_array($type, ['NFSE','NFCE'], true)) throw new RuntimeException('Tipo fiscal do lote inválido.');
    $orderIds = array_values(array_unique(array_filter(array_map('intval', $orderIds), static fn($v) => $v > 0)));
    if (!$orderIds) throw new RuntimeException('Selecione pelo menos um Pedido.');
    if (count($orderIds) > 100) throw new RuntimeException('Por segurança, processe no máximo 100 Pedidos por lote.');

    $cfg = fiscal_company_config($pdo, $companyId);
    $environment = strtoupper(trim((string)($cfg['environment'] ?? 'HOMOLOGACAO')));
    if (!in_array($environment, ['HOMOLOGACAO','PRODUCAO'], true)) $environment = 'HOMOLOGACAO';
    if ($environment === 'PRODUCAO' && !$productionConfirmed) {
      throw new RuntimeException('Ambiente de PRODUÇÃO: digite exatamente EMITIR para confirmar a emissão fiscal do lote.');
    }
    if ($type === 'NFSE' && fiscal_batch_provider_code($pdo, $companyId) === 'PRONIM' && $environment === 'PRODUCAO') {
      if (!defined('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED')) define('NEXA_NFSE_PRONIM_PRODUCTION_CONFIRMED', true);
    }

    $ok = 0; $fail = 0; $skipped = 0; $messages = [];
    foreach ($orderIds as $orderId) {
      $row = fiscal_batch_candidate_order($pdo, $companyId, $orderId);
      if (!$row) { $fail++; $messages[] = 'Pedido #' . $orderId . ': não encontrado ou cancelado.'; continue; }
      $state = fiscal_batch_candidate_state($row, $type);
      if (empty($state['eligible'])) {
        $skipped++;
        $messages[] = 'Pedido #' . ($row['numero_pedido'] ?? $orderId) . ': ignorado — ' . (string)($state['message'] ?? $state['label'] ?? 'não elegível') . '.';
        continue;
      }
      try {
        if ($type === 'NFSE') {
          fiscal_batch_require_nfse_provider();
          $result = nfse_provider_authorize_order($pdo, $companyId, $orderId);
          if (empty($result['ok'])) throw new RuntimeException((string)($result['message'] ?? 'NFS-e não autorizada.'));
          $number = trim((string)($result['nfse_number'] ?? $result['document']['nfse_number'] ?? ''));
          $docId = (int)($result['document']['id'] ?? 0);
          if ($docId > 0) fiscal_batch_record_contract_nfse($pdo, $companyId, $orderId, $docId, $number);
          $ok++;
          $messages[] = 'Pedido #' . ($row['numero_pedido'] ?? $orderId) . ': NFS-e' . ($number !== '' ? ' #' . $number : '') . ' autorizada.';
        } else {
          $result = fiscal_nfce_authorize_order($pdo, $companyId, $orderId);
          if (empty($result['ok'])) throw new RuntimeException((string)(($result['code'] ?? '') . (($result['code'] ?? '') !== '' ? ' - ' : '') . ($result['message'] ?? 'NFC-e não autorizada.')));
          $docId = (int)($result['doc_id'] ?? 0);
          $doc = $docId > 0 ? fiscal_nfce_document_by_id($pdo, $companyId, $docId) : fiscal_nfce_order_document($pdo, $companyId, $orderId);
          $number = $doc ? trim((string)($doc['number'] ?? '')) : '';
          $ok++;
          $messages[] = 'Pedido #' . ($row['numero_pedido'] ?? $orderId) . ': NFC-e' . ($number !== '' ? ' #' . $number : '') . ' autorizada.';
        }
      } catch (Throwable $e) {
        $fail++;
        $messages[] = 'Pedido #' . ($row['numero_pedido'] ?? $orderId) . ': ' . (trim($e->getMessage()) ?: 'falha não identificada.');
      }
    }
    return ['type'=>$type,'environment'=>$environment,'ok_count'=>$ok,'fail_count'=>$fail,'skip_count'=>$skipped,'messages'=>$messages];
  }
}
