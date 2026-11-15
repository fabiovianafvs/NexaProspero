<?php
declare(strict_types=1);

/*
 * Nexa Próspero - Movimentação de estoque do produto (cadastro de produtos).
 * Lê estoque_movimentos e completa cada linha com a operação (venda, entrada de
 * NF-e, devolução, acerto...), a pessoa (cliente/fornecedor), o valor (custo ou
 * preço de venda) e o usuário.
 * Usuário: coluna usuario_id (gravada a partir da migração 20261115_estoque_mov_usuario_v1,
 * pelo stock_movements_stamp_user ao fim de cada POST do painel); para vendas
 * antigas, o operador do caixa do pedido.
 */

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/stock_movement_user.php';

if (!function_exists('produto_movimentacao_origin_label')) {
  function produto_movimentacao_origin_label(string $origin): string {
    return [
      'XML_NFE' => 'Entrada NF-e',
      'XML_NFE_UNDO' => 'Estorno de entrada NF-e',
      'FISCAL_DOCUMENTO' => 'Nota fiscal',
      'PDV' => 'Venda',
      'CANCELAMENTO_PEDIDO' => 'Cancelamento de venda',
      'COMPOSICAO_PDV' => 'Venda (ficha técnica)',
      'COMPOSICAO_CANCELAMENTO' => 'Cancelamento de venda (ficha técnica)',
      'ORDEM_ENTREGA' => 'Ordem de entrega',
      'CANCELAMENTO_ORDEM_ENTREGA' => 'Cancelamento de ordem de entrega',
      'ACERTO_MANUAL' => 'Acerto manual',
      'CONFERENCIA_ESTOQUE' => 'Conferência de estoque',
      'ORDEM_PRODUCAO_CONSUMO' => 'Consumo na produção',
      'ORDEM_PRODUCAO_ENTRADA' => 'Entrada da produção',
    ][$origin] ?? ($origin !== '' ? mb_convert_case(str_replace('_', ' ', mb_strtolower($origin)), MB_CASE_TITLE) : 'Movimento');
  }
}

if (!function_exists('produto_movimentacao_data')) {
  /** $from/$to: período (AAAA-MM-DD, inclusive). Sem período: últimos 90 dias. */
  function produto_movimentacao_data(PDO $pdo, int $companyId, int $productId, int $limit = 100, int $offset = 0, string $from = '', string $to = ''): array {
    $isDate = static fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && strtotime($d) !== false;
    if (!$isDate($to)) $to = date('Y-m-d');
    if (!$isDate($from)) $from = date('Y-m-d', strtotime($to . ' -90 days'));
    if ($from > $to) [$from, $to] = [$to, $from];
    $out = ['rows' => [], 'more' => false, 'has_user' => false, 'from' => $from, 'to' => $to];
    if ($productId <= 0 || !fiscal_table_exists($pdo, 'estoque_movimentos')) return $out;
    $has = static fn(string $c) => fiscal_column_exists($pdo, 'estoque_movimentos', $c);
    $cols = 'm.id, m.tipo, m.origem_tipo, m.origem_id, m.qtd, m.saldo_anterior, m.saldo_posterior, m.observacao, m.created_at';
    foreach (['custo_unitario', 'custo_medio_posterior', 'usuario_id'] as $c) if ($has($c)) $cols .= ', m.' . $c;
    $out['has_user'] = $has('usuario_id');
    $limit = max(1, min(300, $limit));
    $st = $pdo->prepare("SELECT $cols FROM estoque_movimentos m WHERE m.company_id = ? AND m.produto_id = ? AND m.created_at >= ? AND m.created_at < ? ORDER BY m.created_at DESC, m.id DESC LIMIT " . ($limit + 1) . ' OFFSET ' . max(0, $offset));
    $st->execute([$companyId, $productId, $from . ' 00:00:00', date('Y-m-d', strtotime($to . ' +1 day')) . ' 00:00:00']);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (count($rows) > $limit) { $out['more'] = true; array_pop($rows); }

    // Origens agrupadas para buscar tudo de uma vez.
    $ids = [];
    foreach ($rows as $r) {
      $o = (string)($r['origem_tipo'] ?? '');
      if ((int)($r['origem_id'] ?? 0) > 0) $ids[$o][(int)$r['origem_id']] = true;
    }
    $in = static fn(array $list) => implode(',', array_fill(0, count($list), '?'));
    $orders = []; $orderItems = []; $xmls = []; $docs = []; $people = []; $users = [];

    $orderIds = array_keys(($ids['PDV'] ?? []) + ($ids['CANCELAMENTO_PEDIDO'] ?? []) + ($ids['COMPOSICAO_PDV'] ?? []) + ($ids['COMPOSICAO_CANCELAMENTO'] ?? []));
    if ($orderIds && fiscal_table_exists($pdo, 'pedidos')) {
      $sel = 'id';
      foreach (['numero_pedido', 'source', 'finance_person_id', 'nome_cliente', 'cash_session_id'] as $c) if (fiscal_column_exists($pdo, 'pedidos', $c)) $sel .= ', ' . $c;
      $q = $pdo->prepare("SELECT $sel FROM pedidos WHERE company_id = ? AND id IN (" . $in($orderIds) . ')');
      $q->execute(array_merge([$companyId], $orderIds));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $o) $orders[(int)$o['id']] = $o;
      if (fiscal_table_exists($pdo, 'pedido_itens')) {
        $q = $pdo->prepare('SELECT pedido_id, SUM(qtd) q, SUM(total) t FROM pedido_itens WHERE company_id = ? AND produto_id = ? AND pedido_id IN (' . $in($orderIds) . ') GROUP BY pedido_id');
        $q->execute(array_merge([$companyId, $productId], $orderIds));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $it) if ((float)$it['q'] > 0) $orderItems[(int)$it['pedido_id']] = round((float)$it['t'] / (float)$it['q'], 4);
      }
    }
    $xmlIds = array_keys(($ids['XML_NFE'] ?? []) + ($ids['XML_NFE_UNDO'] ?? []));
    if ($xmlIds && fiscal_table_exists($pdo, 'fiscal_xml_imports')) {
      $q = $pdo->prepare('SELECT id, number, series, supplier_doc, supplier_name FROM fiscal_xml_imports WHERE company_id = ? AND id IN (' . $in($xmlIds) . ')');
      $q->execute(array_merge([$companyId], $xmlIds));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $x) $xmls[(int)$x['id']] = $x;
    }
    $docIds = array_keys($ids['FISCAL_DOCUMENTO'] ?? []);
    if ($docIds && fiscal_table_exists($pdo, 'fiscal_documents')) {
      $q = $pdo->prepare('SELECT id, model, number, series, operation_nature, direction FROM fiscal_documents WHERE company_id = ? AND id IN (' . $in($docIds) . ')');
      $q->execute(array_merge([$companyId], $docIds));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $d) $docs[(int)$d['id']] = $d;
      if (fiscal_table_exists($pdo, 'fiscal_document_participants')) {
        $q = $pdo->prepare('SELECT fiscal_document_id, name FROM fiscal_document_participants WHERE company_id = ? AND fiscal_document_id IN (' . $in($docIds) . ') ORDER BY id ASC');
        $q->execute(array_merge([$companyId], $docIds));
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) if (!isset($docs[(int)$p['fiscal_document_id']]['person'])) $docs[(int)$p['fiscal_document_id']]['person'] = (string)$p['name'];
      }
    }
    $personIds = [];
    foreach ($orders as $o) if ((int)($o['finance_person_id'] ?? 0) > 0) $personIds[(int)$o['finance_person_id']] = true;
    if ($personIds && fiscal_table_exists($pdo, 'finance_people')) {
      $list = array_keys($personIds);
      $q = $pdo->prepare('SELECT id, name FROM finance_people WHERE company_id = ? AND id IN (' . $in($list) . ')');
      $q->execute(array_merge([$companyId], $list));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) $people[(int)$p['id']] = (string)$p['name'];
    }
    // Usuário: o gravado no movimento; senão, para vendas, o operador do caixa.
    $sessionUser = [];
    $sessionIds = [];
    foreach ($orders as $o) if ((int)($o['cash_session_id'] ?? 0) > 0) $sessionIds[(int)$o['cash_session_id']] = true;
    if ($sessionIds && fiscal_table_exists($pdo, 'gestao_cash_sessions')) {
      $list = array_keys($sessionIds);
      $q = $pdo->prepare('SELECT id, user_id FROM gestao_cash_sessions WHERE company_id = ? AND id IN (' . $in($list) . ')');
      $q->execute(array_merge([$companyId], $list));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $s) $sessionUser[(int)$s['id']] = (int)$s['user_id'];
    }
    $userIds = array_filter(array_merge(array_map(static fn($r) => (int)($r['usuario_id'] ?? 0), $rows), array_values($sessionUser)));
    if ($userIds && fiscal_table_exists($pdo, 'users')) {
      $list = array_values(array_unique($userIds));
      $q = $pdo->prepare('SELECT id, name FROM users WHERE id IN (' . $in($list) . ')');
      $q->execute($list);
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $u) $users[(int)$u['id']] = (string)$u['name'];
    }

    foreach ($rows as $r) {
      $origin = (string)($r['origem_tipo'] ?? '');
      $oid = (int)($r['origem_id'] ?? 0);
      $delta = round((float)$r['saldo_posterior'] - (float)$r['saldo_anterior'], 4);
      if (abs($delta) < 0.00005) $delta = in_array((string)$r['tipo'], ['saida'], true) ? -abs((float)$r['qtd']) : (float)$r['qtd'];
      $row = [
        'id' => (int)$r['id'], 'date' => (string)$r['created_at'], 'operation' => produto_movimentacao_origin_label($origin), 'ref' => '', 'link' => '',
        'person' => '', 'type' => $delta >= 0 ? 'entrada' : 'saida', 'adjust' => (string)$r['tipo'] === 'ajuste', 'qty' => $delta,
        'value' => null, 'value_kind' => '', 'balance' => round((float)$r['saldo_posterior'], 4), 'user' => '', 'note' => trim((string)($r['observacao'] ?? '')),
      ];
      $cost = (float)($r['custo_unitario'] ?? 0);
      if ($cost > 0) { $row['value'] = round($cost, 4); $row['value_kind'] = 'custo'; }
      if (isset($orders[$oid]) && in_array($origin, ['PDV', 'CANCELAMENTO_PEDIDO', 'COMPOSICAO_PDV', 'COMPOSICAO_CANCELAMENTO'], true)) {
        $o = $orders[$oid];
        $num = (int)($o['numero_pedido'] ?? 0) > 0 ? (int)$o['numero_pedido'] : $oid;
        $src = mb_strtolower((string)($o['source'] ?? ''));
        if ($origin === 'PDV') $row['operation'] = str_contains($src, 'pdv') ? 'Venda PDV' : (str_contains($src, 'comanda') ? 'Venda comanda' : 'Venda (pedido)');
        $row['ref'] = 'Pedido ' . $num;
        $row['link'] = 'pedido.php?id=' . $oid;
        $row['person'] = $people[(int)($o['finance_person_id'] ?? 0)] ?? trim((string)($o['nome_cliente'] ?? ''));
        if (isset($orderItems[$oid])) { $row['value'] = $orderItems[$oid]; $row['value_kind'] = 'venda'; }
        if ((int)($o['cash_session_id'] ?? 0) > 0 && empty($r['usuario_id'])) $row['user'] = $users[$sessionUser[(int)$o['cash_session_id']] ?? 0] ?? '';
      } elseif (isset($xmls[$oid]) && in_array($origin, ['XML_NFE', 'XML_NFE_UNDO'], true)) {
        $x = $xmls[$oid];
        $row['ref'] = 'NF ' . trim((string)$x['number'] . ((string)($x['series'] ?? '') !== '' ? '/' . $x['series'] : ''));
        $row['link'] = 'fiscal_xml_entrada.php?id=' . $oid;
        $row['person'] = (string)($x['supplier_name'] ?? '');
      } elseif (isset($docs[$oid]) && $origin === 'FISCAL_DOCUMENTO') {
        $d = $docs[$oid];
        $row['operation'] = trim((string)($d['operation_nature'] ?? '')) ?: 'Nota fiscal';
        $row['ref'] = ((string)$d['model'] === '65' ? 'NFC-e ' : 'NF-e ') . (string)($d['number'] ?? '');
        $row['person'] = (string)($d['person'] ?? '');
      } elseif ($oid > 0 && $origin !== 'ACERTO_MANUAL') {
        $row['ref'] = '#' . $oid;
      }
      if (!empty($r['usuario_id'])) $row['user'] = $users[(int)$r['usuario_id']] ?? ('Usuário #' . (int)$r['usuario_id']);
      $out['rows'][] = $row;
    }
    return $out;
  }
}
