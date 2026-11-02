<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/external_api.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/brain_profitability.php';
require_once __DIR__ . '/../config/billing_module.php';

external_api_handle_options();

/** @var PDO $pdo */
$auth = external_api_authenticate($pdo, 'orders');
$companyId = (int)$auth['company_id'];
$method = external_api_method();
company_sequence_ensure_schema($pdo, true);
brain_profitability_ensure_schema($pdo, true);

function api_pedido_obs_col(PDO $pdo): ?string {
  foreach (['observacoes','observacao','obs'] as $col) {
    if (external_api_mysql_has_column($pdo, 'pedidos', $col)) return $col;
  }
  return null;
}

function api_pedido_has_bairro(PDO $pdo): bool {
  return external_api_mysql_has_column($pdo, 'pedidos', 'bairro');
}

function api_pedido_to_array(PDO $pdo, int $companyId, array $pedido, bool $withItems = true): array {
  $obsCol = api_pedido_obs_col($pdo);
  $out = [
    'id' => (int)($pedido['id'] ?? 0),
    'numero_pedido' => (int)($pedido['numero_pedido'] ?? ($pedido['id'] ?? 0)),
    'cliente' => [
      'nome' => (string)($pedido['nome_cliente'] ?? ''),
      'whatsapp' => (string)($pedido['whatsapp'] ?? ''),
    ],
    'endereco' => (string)($pedido['endereco'] ?? ''),
    'bairro' => (string)($pedido['bairro'] ?? ''),
    'tipo_entrega' => (string)($pedido['tipo_entrega'] ?? ''),
    'forma_pagamento' => (string)($pedido['forma_pagamento'] ?? ''),
    'observacoes' => $obsCol ? (string)($pedido[$obsCol] ?? '') : '',
    'total_produtos' => (float)($pedido['total_produtos'] ?? 0),
    'taxa_entrega' => (float)($pedido['taxa_entrega'] ?? 0),
    'total' => (float)($pedido['total'] ?? 0),
    'status' => (string)($pedido['status'] ?? ''),
    'source' => (string)($pedido['source'] ?? ''),
    'sales_channel' => (string)($pedido['sales_channel'] ?? ''),
    'fulfillment_type' => (string)($pedido['fulfillment_type'] ?? ($pedido['tipo_entrega'] ?? '')),
    'rentabilidade' => [
      'receita_liquida_produtos' => (float)($pedido['receita_liquida_produtos'] ?? 0),
      'cmv' => (float)($pedido['total_cmv'] ?? 0),
      'lucro_bruto' => (float)($pedido['lucro_bruto'] ?? 0),
      'margem_sobre_venda_percentual' => (float)($pedido['margem_venda_percentual'] ?? 0),
      'margem_sobre_custo_percentual' => (float)($pedido['margem_custo_percentual'] ?? 0),
      'markup_fator' => (float)($pedido['markup_fator'] ?? 0),
      'origem_custo' => (string)($pedido['profitability_cost_source'] ?? 'nao_informado'),
      'calculado_em' => (string)($pedido['profitability_calculated_at'] ?? ''),
    ],
    'recognized_at' => (string)($pedido['recognized_at'] ?? ''),
    'created_at' => (string)($pedido['created_at'] ?? ''),
    'updated_at' => (string)($pedido['updated_at'] ?? ''),
  ];

  if (!$withItems) return $out;

  $itemObsCol = external_api_order_item_obs_column($pdo);
  $selectObs = $itemObsCol ? "pi.{$itemObsCol} AS complemento" : "'' AS complemento";
  $codeCol = external_api_product_code_column($pdo);
  $selectCode = $codeCol ? "p.codigo_firebird" : "m.codigo_firebird";
  $joinMap = $codeCol ? "" : "LEFT JOIN produtos_firebird_map m ON m.company_id = pi.company_id AND m.produto_id = pi.produto_id";

  if (!$codeCol) external_api_ensure_product_map($pdo);

  $st = $pdo->prepare("
    SELECT pi.*, p.nome, p.categoria, {$selectCode}, {$selectObs}
    FROM pedido_itens pi
    LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id
    {$joinMap}
    WHERE pi.company_id = ? AND pi.pedido_id = ?
    ORDER BY pi.id ASC
  ");
  $st->execute([$companyId, (int)$pedido['id']]);
  $items = [];
  foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $it) {
    $items[] = [
      'id' => (int)($it['id'] ?? 0),
      'produto_id' => (int)($it['produto_id'] ?? 0),
      'codigo_firebird' => (string)($it['codigo_firebird'] ?? ''),
      'nome' => (string)($it['produto_nome_snapshot'] ?? $it['nome'] ?? ''),
      'categoria' => (string)($it['categoria_snapshot'] ?? $it['categoria'] ?? ''),
      'qtd' => (float)($it['qtd'] ?? 0),
      'preco_unit' => (float)($it['preco_unit'] ?? 0),
      'total' => (float)($it['total'] ?? 0),
      'custo_unitario_snapshot' => (float)($it['custo_unitario_snapshot'] ?? 0),
      'cmv_total' => (float)($it['cmv_total'] ?? 0),
      'desconto_rateado' => (float)($it['desconto_rateado'] ?? 0),
      'acrescimo_rateado' => (float)($it['acrescimo_rateado'] ?? 0),
      'receita_liquida' => (float)($it['receita_liquida'] ?? 0),
      'lucro_bruto' => (float)($it['lucro_bruto'] ?? 0),
      'custo_origem' => (string)($it['custo_origem'] ?? 'nao_informado'),
      'complemento' => (string)($it['complemento'] ?? ''),
    ];
  }
  $out['items'] = $items;
  return $out;
}

function api_pedidos_fetch(PDO $pdo, int $companyId, array $params): array {
  $obsCol = api_pedido_obs_col($pdo);
  $hasBairro = api_pedido_has_bairro($pdo);
  $selectObs = $obsCol ? ", {$obsCol}" : "";
  $selectBairro = $hasBairro ? ", bairro" : ", '' AS bairro";

  $where = ['company_id = ?'];
  $bind = [$companyId];

  if (!empty($params['id'])) {
    $where[] = 'id = ?';
    $bind[] = (int)$params['id'];
  }
  if (!empty($params['numero_pedido']) && company_sequence_has_column($pdo, 'pedidos')) {
    $where[] = 'numero_pedido = ?';
    $bind[] = (int)$params['numero_pedido'];
  }
  if (!empty($params['status'])) {
    $where[] = 'status = ?';
    $bind[] = (string)$params['status'];
  }
  if (!empty($params['desde'])) {
    $where[] = 'created_at >= ?';
    $bind[] = (string)$params['desde'];
  }
  if (!empty($params['ate'])) {
    $where[] = 'created_at <= ?';
    $bind[] = (string)$params['ate'];
  }

  $limit = max(1, min(500, (int)($params['limit'] ?? 100)));
  $offset = max(0, (int)($params['offset'] ?? 0));

  $sql = "SELECT * {$selectBairro} {$selectObs}
          FROM pedidos
          WHERE " . implode(' AND ', $where) . "
          ORDER BY " . (company_sequence_has_column($pdo, 'pedidos') ? "COALESCE(numero_pedido, id)" : "id") . " DESC, id DESC
          LIMIT {$limit} OFFSET {$offset}";
  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  $withItems = ((string)($params['items'] ?? '1')) !== '0';
  return array_map(fn($p) => api_pedido_to_array($pdo, $companyId, $p, $withItems), $rows);
}

function api_resolve_produto_id(PDO $pdo, int $companyId, array $item): int {
  $produtoId = (int)($item['produto_id'] ?? $item['id_produto'] ?? $item['id'] ?? 0);
  if ($produtoId > 0) {
    $st = $pdo->prepare("SELECT id FROM produtos WHERE id = ? AND company_id = ? LIMIT 1");
    $st->execute([$produtoId, $companyId]);
    if ($st->fetchColumn()) return $produtoId;
  }

  $codigo = trim((string)($item['codigo_firebird'] ?? $item['codigo'] ?? $item['referencia'] ?? ''));
  if ($codigo !== '') {
    $p = external_api_product_by_code($pdo, $companyId, $codigo);
    if ($p) return (int)$p['id'];
  }

  return 0;
}

function api_pedidos_create(PDO $pdo, int $companyId, array $data): array {
  $cliente = (array)($data['cliente'] ?? []);
  $nome = trim((string)($data['nome_cliente'] ?? $cliente['nome'] ?? $data['nome'] ?? ''));
  $whatsapp = external_api_clean_phone((string)($data['whatsapp'] ?? $cliente['whatsapp'] ?? $cliente['telefone'] ?? ''));
  $endereco = trim((string)($data['endereco'] ?? $cliente['endereco'] ?? ''));
  $bairro = trim((string)($data['bairro'] ?? $cliente['bairro'] ?? ''));
  $tipoEntrega = (string)($data['tipo_entrega'] ?? 'entrega');
  if (!in_array($tipoEntrega, ['entrega','retirada'], true)) $tipoEntrega = 'entrega';
  $formaPgto = trim((string)($data['forma_pagamento'] ?? $data['pagamento'] ?? ''));
  $obs = trim((string)($data['observacoes'] ?? $data['observacao'] ?? ''));
  $status = trim((string)($data['status'] ?? 'aberto'));
  if (!in_array($status, ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'], true)) $status = 'aberto';

  $items = (array)($data['items'] ?? $data['itens'] ?? []);
  if ($nome === '' || $whatsapp === '') external_api_json_response(['ok' => false, 'error' => 'Informe nome_cliente e whatsapp.'], 400);
  if (!$items) external_api_json_response(['ok' => false, 'error' => 'Informe ao menos um item em "items".'], 400);

  $totalProdutos = 0.0;
  $preparedItems = [];
  foreach ($items as $idx => $item) {
    if (!is_array($item)) continue;
    $produtoId = api_resolve_produto_id($pdo, $companyId, $item);
    if ($produtoId <= 0) {
      external_api_json_response(['ok' => false, 'error' => 'Produto não encontrado no item ' . ($idx + 1) . '. Informe produto_id ou codigo_firebird válido.'], 400);
    }

    $qtd = max(0.001, external_api_money_to_float($item['qtd'] ?? $item['quantidade'] ?? 1));
    $preco = external_api_money_to_float($item['preco_unit'] ?? $item['preco'] ?? $item['valor_unitario'] ?? 0);
    if ($preco <= 0) {
      $stp = $pdo->prepare("SELECT preco FROM produtos WHERE id = ? AND company_id = ? LIMIT 1");
      $stp->execute([$produtoId, $companyId]);
      $preco = (float)($stp->fetchColumn() ?: 0);
    }
    $totalItem = array_key_exists('total', $item) ? external_api_money_to_float($item['total']) : ($qtd * $preco);
    $complemento = trim((string)($item['complemento'] ?? $item['observacao'] ?? $item['obs'] ?? ''));

    $totalProdutos += $totalItem;
    $preparedItems[] = [
      'produto_id' => $produtoId,
      'qtd' => $qtd,
      'preco_unit' => $preco,
      'total' => $totalItem,
      'complemento' => $complemento,
    ];
  }

  $taxaEntrega = external_api_money_to_float($data['taxa_entrega'] ?? 0);
  $discountAmount = max(0.0, external_api_money_to_float($data['discount_amount'] ?? $data['desconto'] ?? 0));
  $additionAmount = max(0.0, external_api_money_to_float($data['addition_amount'] ?? $data['acrescimo'] ?? 0));
  $sellerId = max(0, (int)($data['seller_id'] ?? $data['vendedor_id'] ?? 0));
  $total = array_key_exists('total', $data) ? external_api_money_to_float($data['total']) : max(0.0, $totalProdutos - $discountAmount + $additionAmount + $taxaEntrega);

  $pdo->beginTransaction();
  try {
    $obsCol = api_pedido_obs_col($pdo) ?: 'observacoes';
    $hasBairro = api_pedido_has_bairro($pdo);
    $cols = ['company_id'];
    $vals = [$companyId];
    if (company_sequence_has_column($pdo, 'pedidos')) {
      $cols[] = 'numero_pedido';
      $vals[] = company_sequence_next($pdo, $companyId, 'pedidos');
    }
    $cols = array_merge($cols, ['nome_cliente','whatsapp','endereco','tipo_entrega','taxa_entrega','forma_pagamento',$obsCol,'total_produtos','total','status']);
    $vals = array_merge($vals, [$nome,$whatsapp,$endereco,$tipoEntrega,$taxaEntrega,$formaPgto,$obs,$totalProdutos,$total,$status]);
    foreach ([
      'source' => 'api',
      'discount_amount' => $discountAmount,
      'addition_amount' => $additionAmount,
      'seller_id' => $sellerId > 0 ? $sellerId : null,
    ] as $optionalCol => $optionalValue) {
      if (external_api_mysql_has_column($pdo, 'pedidos', $optionalCol)) {
        $cols[] = $optionalCol;
        $vals[] = $optionalValue;
      }
    }

    if ($hasBairro) {
      $idxTipoEntrega = array_search('tipo_entrega', $cols, true);
      if ($idxTipoEntrega === false) $idxTipoEntrega = 4;
      array_splice($cols, (int)$idxTipoEntrega, 0, ['bairro']);
      array_splice($vals, (int)$idxTipoEntrega, 0, [$bairro]);
    } elseif ($bairro !== '' && $tipoEntrega === 'entrega') {
      $vals[3] = trim("Bairro: " . $bairro . "\n" . $endereco);
    }

    $ph = implode(',', array_fill(0, count($cols), '?'));
    $st = $pdo->prepare("INSERT INTO pedidos (" . implode(',', $cols) . ") VALUES ({$ph})");
    $st->execute($vals);
    $pedidoId = (int)$pdo->lastInsertId();

    $itemObsCol = external_api_order_item_obs_column($pdo);
    $sti = $pdo->prepare($itemObsCol
      ? "INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, {$itemObsCol}) VALUES (?, ?, ?, ?, ?, ?, ?)"
      : "INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?, ?, ?, ?, ?, ?)"
    );

    foreach ($preparedItems as $it) {
      if ($itemObsCol) {
        $sti->execute([$companyId, $pedidoId, $it['produto_id'], $it['qtd'], $it['preco_unit'], $it['total'], $it['complemento']]);
      } else {
        $sti->execute([$companyId, $pedidoId, $it['produto_id'], $it['qtd'], $it['preco_unit'], $it['total']]);
      }
    }

    brain_profitability_recalculate_order($pdo, $companyId, $pedidoId, true, $status === 'finalizado' ? 'order.finalized' : 'order.created');

    try {
      $ste = $pdo->prepare("INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)");
      $ste->execute([$companyId, $pedidoId]);
    } catch (Throwable $e) {}

    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  $rows = api_pedidos_fetch($pdo, $companyId, ['id' => $pedidoId, 'limit' => 1]);
  return $rows[0] ?? ['id' => $pedidoId];
}

function api_pedidos_update_status(PDO $pdo, int $companyId, int $id, string $status): array {
  if (!in_array($status, ['aberto','confirmado','em_producao','saiu_entrega','finalizado','cancelado'], true)) {
    external_api_json_response(['ok' => false, 'error' => 'Status inválido.'], 400);
  }

  if ($status === 'cancelado') billing_assert_order_can_cancel_locally($pdo, $companyId, $id);
  $pdo->beginTransaction();
  try {
    $oldStatusSt = $pdo->prepare("SELECT status FROM pedidos WHERE id = ? AND company_id = ? LIMIT 1 FOR UPDATE");
    $oldStatusSt->execute([$id, $companyId]);
    $oldStatusRaw = $oldStatusSt->fetchColumn();
    if ($oldStatusRaw === false) {
      $pdo->rollBack();
      external_api_json_response(['ok' => false, 'error' => 'Pedido não encontrado.'], 404);
    }
    $oldStatus = (string)$oldStatusRaw;
    $pdo->prepare("UPDATE pedidos SET status = ? WHERE id = ? AND company_id = ?")->execute([$status, $id, $companyId]);
    if ($status === 'cancelado' && $oldStatus !== 'cancelado') {
      billing_cancel_order_unregistered_charges($pdo, $companyId, $id, 'Pedido #' . $id . ' cancelado pela API.', 'API_PEDIDOS');
    }
    brain_profitability_sync_order_status($pdo, $companyId, $id, $status, $oldStatus);
    $pdo->commit();
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw $e;
  }

  $rows = api_pedidos_fetch($pdo, $companyId, ['id' => $id, 'limit' => 1]);
  return $rows[0] ?? ['id' => $id, 'status' => $status];
}

try {
  if ($method === 'GET') {
    $items = api_pedidos_fetch($pdo, $companyId, $_GET);
    $payload = [
      'ok' => true,
      'total' => count($items),
      'items' => $items,
    ];
    if (!empty($_GET['id'])) $payload['pedido'] = $items[0] ?? null;
    external_api_json_response($payload);
  }

  if ($method === 'POST') {
    $data = external_api_read_json();
    if (!$data) $data = $_POST;
    $pedido = api_pedidos_create($pdo, $companyId, $data);
    $payload = ['ok' => true, 'pedido' => $pedido];
    external_api_log($pdo, $companyId, 'pedidos', $method, 200, $data, $payload);
    external_api_json_response($payload, 201);
  }

  if (in_array($method, ['PUT','PATCH'], true)) {
    $data = external_api_read_json();
    if (!$data) $data = $_POST;
    $id = (int)($_GET['id'] ?? $data['id'] ?? $data['pedido_id'] ?? 0);
    $status = trim((string)($data['status'] ?? ''));
    if ($id <= 0) external_api_json_response(['ok' => false, 'error' => 'Informe id do pedido.'], 400);
    if ($status === '') external_api_json_response(['ok' => false, 'error' => 'Informe status.'], 400);
    $pedido = api_pedidos_update_status($pdo, $companyId, $id, $status);
    external_api_json_response(['ok' => true, 'pedido' => $pedido]);
  }

  external_api_json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
  $payload = ['ok' => false, 'error' => $e->getMessage()];
  external_api_log($pdo, $companyId ?? null, 'pedidos', $method ?? 'GET', 500, $_GET ?? [], $payload);
  external_api_json_response($payload, 500);
}
