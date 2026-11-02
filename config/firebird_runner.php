<?php
declare(strict_types=1);

require_once __DIR__ . '/load_config.php';
require_once __DIR__ . '/saas_modules.php';

function fbr_module_allowed(?PDO $pdo, int $companyId): bool {
  if (!$pdo instanceof PDO || $companyId <= 0 || !function_exists('saas_company_has_module')) return true;
  try { saas_ensure_modules_schema($pdo); } catch (Throwable $e) {}
  try { return saas_company_has_module($pdo, $companyId, 'exportacao'); } catch (Throwable $e) { return true; }
}

function fbr_pdo(array $fb): PDO {
  $host = (string)($fb['host'] ?? '127.0.0.1');
  $port = (int)($fb['port'] ?? 3050);
  $db   = (string)($fb['database'] ?? '');
  $user = (string)($fb['user'] ?? 'SYSDBA');
  $pass = (string)($fb['pass'] ?? ($fb['password'] ?? 'masterkey'));
  $charset = (string)($fb['charset'] ?? 'UTF8');
  if ($db === '') throw new RuntimeException('FIREBIRD_DATABASE não configurado.');
  $dsn = "firebird:dbname={$host}/{$port}:{$db};charset={$charset}";
  return new PDO($dsn, $user, $pass, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
  ]);
}

function fbr_safe_str($v): string { return trim((string)$v); }
function fbr_ident(string $name): string {
  $name = strtoupper(trim($name));
  if ($name === '' || !preg_match('/^[A-Z0-9_\$]+$/', $name)) throw new RuntimeException('Identificador Firebird inválido: ' . $name);
  return $name;
}
function fbr_mysql_table_has_column(PDO $pdo, string $table, string $column): bool {
  try {
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return $st && (bool)$st->fetch();
  } catch (Throwable $e) {
    try {
      $st = $pdo->prepare("SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1");
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e2) {
      return false;
    }
  }
}
function fbr_table_exists(PDO $pdoFb, string $table): bool {
  $t = strtoupper(trim($table));
  if ($t === '') return false;
  $st = $pdoFb->prepare("SELECT 1 FROM RDB\$RELATIONS WHERE RDB\$RELATION_NAME = ? AND (RDB\$SYSTEM_FLAG IS NULL OR RDB\$SYSTEM_FLAG = 0) ROWS 1");
  $st->execute([$t]);
  return (bool)$st->fetchColumn();
}
function fbr_pick_table(PDO $pdoFb, array $candidates): string {
  foreach ($candidates as $c) {
    $c = trim((string)$c);
    if ($c !== '' && fbr_table_exists($pdoFb, $c)) return strtoupper($c);
  }
  return '';
}
function fbr_table_fields(PDO $pdoFb, string $table): array {
  $t = strtoupper(trim($table));
  $st = $pdoFb->prepare("SELECT TRIM(RDB\$FIELD_NAME) AS F FROM RDB\$RELATION_FIELDS WHERE RDB\$RELATION_NAME = ? ORDER BY RDB\$FIELD_POSITION");
  $st->execute([$t]);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $out = [];
  foreach ($rows as $r) $out[] = strtoupper(trim((string)($r['F'] ?? '')));
  return array_values(array_filter($out));
}
function fbr_require_fields(string $table, array $map, array $available, array $requiredKeys, string $groupLabel): void {
  $need = [];
  foreach ($requiredKeys as $k) {
    $v = strtoupper(trim((string)($map[$k] ?? '')));
    if ($v === '') {
      $need[] = $k . '=(não configurado)';
      continue;
    }
    if (!in_array($v, $available, true)) $need[] = "{$k}={$v}";
  }
  if ($need) {
    $avail = implode(', ', $available);
    $miss  = implode(', ', $need);
    throw new RuntimeException("Firebird: campos não encontrados em {$table} ({$groupLabel}). Faltando: {$miss}. Campos existentes: {$avail}");
  }
}
function fbr_next_orcamento_codigo(PDO $pdoFb, string $table, string $empresaField, string $codigoField, int $empresa): int {
  $empresaField = fbr_ident($empresaField);
  $codigoField  = fbr_ident($codigoField);
  $table        = fbr_ident($table);
  $sql = "SELECT COALESCE(MAX({$codigoField}), 0) + 1 AS PROXIMO FROM {$table} WHERE {$empresaField} = ?";
  $st = $pdoFb->prepare($sql);
  $st->execute([$empresa]);
  return (int)$st->fetchColumn();
}
function fbr_next_global_int(PDO $pdoFb, string $table, string $field): int {
  $table = fbr_ident($table);
  $field = fbr_ident($field);
  $sql = "SELECT COALESCE(MAX({$field}), 0) + 1 AS PROXIMO FROM {$table}";
  $st = $pdoFb->query($sql);
  return (int)$st->fetchColumn();
}
function fbr_pick_source_table(PDO $pdoFb, array $candidates): string {
  foreach ($candidates as $c) {
    $c = trim((string)$c);
    if ($c !== '' && fbr_table_exists($pdoFb, $c)) return strtoupper($c);
  }
  return '';
}
function fbr_lookup_produto_fiscal(PDO $pdoFb, string $table, array $fieldMap, string $codigo, array &$cache): array {
  $codigo = trim($codigo);
  if ($table === '' || $codigo === '') return [];
  $cacheKey = strtoupper($table) . '|' . $codigo;
  if (array_key_exists($cacheKey, $cache)) return $cache[$cacheKey];

  $codigoCol = strtoupper(trim((string)($fieldMap['codigo'] ?? 'CODIGO')));
  if ($codigoCol === '') $codigoCol = 'CODIGO';

  $wanted = [];
  foreach (['cfop','cst','csosn','origem'] as $k) {
    $col = strtoupper(trim((string)($fieldMap[$k] ?? '')));
    if ($col !== '') $wanted[$k] = $col;
  }
  if (!$wanted) {
    $cache[$cacheKey] = [];
    return [];
  }

  $available = fbr_table_fields($pdoFb, $table);
  if (!in_array($codigoCol, $available, true)) {
    $cache[$cacheKey] = [];
    return [];
  }

  $selectCols = [];
  $aliasToKey = [];
  foreach ($wanted as $logical => $col) {
    if (in_array($col, $available, true)) {
      $alias = strtoupper($logical);
      $selectCols[] = fbr_ident($col) . ' AS ' . fbr_ident($alias);
      $aliasToKey[$alias] = $logical;
    }
  }
  if (!$selectCols) {
    $cache[$cacheKey] = [];
    return [];
  }

  $sql = 'SELECT ' . implode(', ', $selectCols) . ' FROM ' . fbr_ident($table) . ' WHERE ' . fbr_ident($codigoCol) . ' = ? ROWS 1';
  $st = $pdoFb->prepare($sql);
  $st->execute([$codigo]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];

  $out = [];
  foreach ($row as $alias => $value) {
    $alias = strtoupper((string)$alias);
    $logical = $aliasToKey[$alias] ?? strtolower($alias);
    $out[$logical] = is_string($value) ? trim($value) : $value;
  }
  $cache[$cacheKey] = $out;
  return $out;
}
function fbr_montar_observacao_pedido(array $p): string {
  $partes = [];
  $nome = fbr_safe_str($p['nome_cliente'] ?? '');
  if ($nome !== '') $partes[] = 'Cliente: ' . $nome;
  $whats = fbr_safe_str($p['whatsapp'] ?? '');
  if ($whats !== '') $partes[] = 'WhatsApp: ' . $whats;
  $entrega = fbr_safe_str($p['tipo_entrega'] ?? '');
  if ($entrega !== '') $partes[] = 'Entrega: ' . $entrega;

  $end = [];
  foreach (['endereco','numero','complemento','bairro'] as $k) {
    $v = fbr_safe_str($p[$k] ?? '');
    if ($v !== '') $end[] = $k === 'numero' ? 'Nº ' . $v : $v;
  }
  if ($end) $partes[] = 'Endereço: ' . implode(', ', $end);

  $pag = fbr_safe_str($p['forma_pagamento'] ?? '');
  if ($pag !== '') $partes[] = 'Pagamento: ' . $pag;
  $troco = fbr_safe_str($p['troco_para'] ?? '');
  if ($troco !== '') $partes[] = 'Troco para: ' . $troco;
  $obs = fbr_safe_str($p['observacoes'] ?? '');
  if ($obs !== '') $partes[] = 'Obs pedido: ' . $obs;

  return implode(' | ', $partes);
}
function fbr_prepare_insert(string $table, array $pairs): array {
  $cols = [];
  $vals = [];
  $marks = [];
  foreach ($pairs as $col => $val) {
    if ($col === '' || $val === null) continue;
    $cols[] = fbr_ident($col);
    $vals[] = $val;
    $marks[] = '?';
  }
  if (!$cols) throw new RuntimeException('Nenhuma coluna Firebird disponível para INSERT em ' . $table);
  $sql = 'INSERT INTO ' . fbr_ident($table) . ' (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $marks) . ')';
  return [$sql, $vals];
}



function firebird_test_connection(int $companyId): array {
  try {
    $pdoCheck = $GLOBALS['pdo'] ?? null;
    if (!fbr_module_allowed($pdoCheck instanceof PDO ? $pdoCheck : null, $companyId)) {
      throw new RuntimeException('Módulo Exportação / Firebird não liberado para esta empresa.');
    }
    $fb = load_firebird_config($companyId);
    if (empty($fb['enabled'])) {
      throw new RuntimeException('Integração Firebird está desativada. Ative nas Configurações.');
    }
    $conn = fbr_pdo($fb);
    return ['ok' => true, 'driver' => 'pdo', 'message' => 'Conexão com Firebird OK.'];
  } catch (Throwable $e) {
    return ['ok' => false, 'error' => $e->getMessage()];
  }
}

function firebird_export_pending(PDO $pdo, int $companyId, ?int $onlyPedidoId = null, int $limit = 20): array {
  $fb = load_firebird_config($companyId);
  $log = [];
  $err = '';
try {
  if (!fbr_module_allowed($pdo, $companyId)) throw new RuntimeException('Módulo Exportação / Firebird não liberado para esta empresa.');
  if (empty($fb['enabled'])) throw new RuntimeException('Integração Firebird desativada. Ative nas Configurações.');
  $pdoFb = fbr_pdo($fb);

  $params = [$companyId];
  $sqlPend = "SELECT pe.pedido_id FROM pedidos_exportacao pe WHERE pe.company_id = ? AND pe.exportado = 0";
  if ($onlyPedidoId !== null && $onlyPedidoId > 0) {
    $sqlPend .= " AND pe.pedido_id = ?";
    $params[] = $onlyPedidoId;
  }
  $sqlPend .= " ORDER BY pe.pedido_id LIMIT " . max(1, min(50, $limit));
  $st = $pdo->prepare($sqlPend);
  $st->execute($params);
  $pend = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  if (!$pend) $log[] = 'Nenhum pedido pendente.';

  $tOrdersCfg = (string)($fb['tables']['orders'] ?? '');
  $tItemsCfg  = (string)($fb['tables']['items'] ?? '');
  $tProductsCfg = (string)($fb['tables']['products'] ?? '');
  $tOrders = fbr_pick_table($pdoFb, [$tOrdersCfg, 'ORCAMENTO', 'PEDIDOS', 'PEDIDO']);
  $tItems  = fbr_pick_table($pdoFb, [$tItemsCfg, 'ORCAMENTO_ITENS', 'PEDIDO_ITENS', 'PEDIDOS_ITENS']);
  $tProducts = fbr_pick_source_table($pdoFb, [$tProductsCfg, 'CAD_PRODUTOS']);
  if ($tOrders === '' || $tItems === '') throw new RuntimeException('Tabelas Firebird não encontradas. Revise a configuração das tabelas.');

  $fO = (array)($fb['fields']['orders'] ?? []);
  $fI = (array)($fb['fields']['items'] ?? []);
  $fP = (array)($fb['fields']['products'] ?? []);
  $defaultsOrders = (array)($fb['defaults']['orders'] ?? []);
  $defaultsItems = (array)($fb['defaults']['items'] ?? []);

  $fieldsOrders = fbr_table_fields($pdoFb, $tOrders);
  $fieldsItems  = fbr_table_fields($pdoFb, $tItems);
  $produtoFiscalCache = [];
  fbr_require_fields($tOrders, $fO, $fieldsOrders, ['empresa','codigo','status','cliente','data','frete','total','obs'], 'orders');
  if ($pdoFb->inTransaction()) { try { $pdoFb->commit(); } catch (Throwable $e) {} }
  fbr_require_fields($tItems,  $fI, $fieldsItems,  ['idx','codigo_orcamento','empresa','cliente','referencia','descricao','qtde','vlr_unit','vlr_total','frete_rateio','total_com_rateios'], 'items');

  $hasProdCodigoFb = fbr_mysql_table_has_column($pdo, 'produtos', 'codigo_firebird');
  $hasProdCodigoFbMap = fbr_mysql_table_has_column($pdo, 'produtos_firebird_map', 'codigo_firebird');
  $hasPedidoItemObs = fbr_mysql_table_has_column($pdo, 'pedido_itens', 'observacao')
    ? 'observacao'
    : (fbr_mysql_table_has_column($pdo, 'pedido_itens', 'obs') ? 'obs' : (fbr_mysql_table_has_column($pdo, 'pedido_itens', 'observacoes') ? 'observacoes' : null));

  foreach ($pend as $row) {
    $pid = (int)($row['pedido_id'] ?? 0);
    if ($pid <= 0) continue;

    try {
      $stp = $pdo->prepare("SELECT * FROM pedidos WHERE id = ? AND company_id = ?");
      $stp->execute([$pid, $companyId]);
      $p = $stp->fetch(PDO::FETCH_ASSOC);
      if (!$p) throw new RuntimeException('Pedido não encontrado no MySQL.');

      $sqlItens = "SELECT pi.*, pr.nome AS produto_nome";
      if ($hasProdCodigoFb) $sqlItens .= ", pr.codigo_firebird AS produto_codigo_firebird";
      if ($hasProdCodigoFbMap) $sqlItens .= ", pfm.codigo_firebird AS produto_codigo_firebird_map";
      $sqlItens .= " FROM pedido_itens pi JOIN produtos pr ON pr.id = pi.produto_id AND pr.company_id = pi.company_id";
      if ($hasProdCodigoFbMap) $sqlItens .= " LEFT JOIN produtos_firebird_map pfm ON pfm.company_id = pr.company_id AND pfm.produto_id = pr.id";
      $sqlItens .= " WHERE pi.pedido_id = ? AND pi.company_id = ?";
      $sti = $pdo->prepare($sqlItens);
      $sti->execute([$pid, $companyId]);
      $it = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
      if (!$it) throw new RuntimeException('Pedido sem itens para exportação.');

      $empresaCodigo = (int)($fb['empresa_codigo'] ?? 1);
      $clienteCodigo = (int)($p['cliente_codigo_firebird'] ?? ($fb['cliente_padrao'] ?? 1));
      if ($empresaCodigo <= 0) throw new RuntimeException('Código da empresa no Firebird inválido.');
      if ($clienteCodigo <= 0) throw new RuntimeException('Cliente padrão no Firebird inválido.');

      if ($pdoFb->inTransaction()) {
        try { $pdoFb->rollBack(); } catch (Throwable $e) { try { $pdoFb->commit(); } catch (Throwable $e2) {} }
      }
      if (!$pdoFb->inTransaction()) $pdoFb->beginTransaction();

      $orcamentoCodigo = fbr_next_orcamento_codigo($pdoFb, $tOrders, (string)$fO['empresa'], (string)$fO['codigo'], $empresaCodigo);
      $obsPedido = fbr_montar_observacao_pedido($p);

      $orderPairs = [
        (string)$fO['empresa'] => $empresaCodigo,
        (string)$fO['codigo']  => $orcamentoCodigo,
        (string)$fO['status']  => 'N',
        (string)$fO['cliente'] => $clienteCodigo,
        (string)$fO['data']    => date('Y-m-d H:i:s', strtotime((string)($p['created_at'] ?? 'now'))),
        (string)$fO['frete']   => (float)($p['taxa_entrega'] ?? 0),
        (string)$fO['total']   => (float)($p['total'] ?? 0),
        (string)$fO['obs']     => $obsPedido,
      ];
      $orderOptionalValues = [
        'tipo'         => (string)($defaultsOrders['tipo'] ?? 'ORCAMENTO'),
        'operacao'     => (int)($defaultsOrders['operacao'] ?? 7),
        'vendedor'     => (int)($defaultsOrders['vendedor'] ?? 2),
        'vlr_produtos' => (float)($p['total'] ?? 0),
        'situacao'     => (int)($defaultsOrders['situacao'] ?? 1),
        'plano_contas' => (string)($defaultsOrders['plano_contas'] ?? '1.1'),
        'transportadora' => (int)($defaultsOrders['transportadora'] ?? 3),
        'cfop' => (string)($defaultsOrders['cfop'] ?? '5102'),
        'data_entrega' => date('Y-m-d H:i:s', strtotime((string)($p['created_at'] ?? 'now'))),
        'usuario_cadastro' => (int)($defaultsOrders['usuario_cadastro'] ?? 1),
        'centro_custos' => (string)($defaultsOrders['centro_custos'] ?? '1'),
        'status_id' => (int)($defaultsOrders['status_id'] ?? 1),
      ];
      foreach ($orderOptionalValues as $logicalKey => $value) {
        $col = strtoupper(trim((string)($fO[$logicalKey] ?? '')));
        if ($col !== '' && in_array($col, $fieldsOrders, true)) $orderPairs[$col] = $value;
      }
      [$sqlO, $valsO] = fbr_prepare_insert($tOrders, $orderPairs);
      $cmdO = $pdoFb->prepare($sqlO);
      $cmdO->execute($valsO);

      $totalItens = count($it);
      $freteTotal = round((float)($p['taxa_entrega'] ?? 0), 2);
      $rateios = [];
      $acumRateio = 0.0;
      foreach (array_keys($it) as $idxKey) {
        if ($idxKey === $totalItens - 1) {
          $rateio = round($freteTotal - $acumRateio, 2);
        } else {
          $rateio = $totalItens > 0 ? round($freteTotal / $totalItens, 2) : 0.0;
          $acumRateio += $rateio;
        }
        $rateios[$idxKey] = $rateio;
      }

      $itemIdxInicial = fbr_next_global_int($pdoFb, $tItems, (string)$fI['idx']);

      foreach ($it as $k => $i) {
        $idx = $itemIdxInicial + $k;
        $qtde = (float)($i['qtd'] ?? 0);
        $vlrUnit = round((float)($i['preco_unit'] ?? 0), 2);
        $vlrTotal = round((float)($i['total'] ?? 0), 2);
        $rateio = (float)($rateios[$k] ?? 0);
        $totalComRateio = round($vlrTotal + $rateio, 2);

        $descricao = fbr_safe_str($i['produto_nome'] ?? '');
        if ($hasPedidoItemObs && !empty($i[$hasPedidoItemObs])) $descricao .= ' - ' . fbr_safe_str($i[$hasPedidoItemObs]);

        $referencia = fbr_safe_str($i['produto_codigo_firebird_map'] ?? '');
        if ($referencia === '') $referencia = fbr_safe_str($i['produto_codigo_firebird'] ?? '');
        if ($referencia === '') $referencia = (string)($i['produto_id'] ?? '');

        $itemPairs = [
          (string)$fI['idx']               => $idx,
          (string)$fI['codigo_orcamento']  => $orcamentoCodigo,
          (string)$fI['empresa']           => $empresaCodigo,
          (string)$fI['cliente']           => $clienteCodigo,
          (string)$fI['referencia']        => $referencia,
          (string)$fI['descricao']         => $descricao,
          (string)$fI['qtde']              => $qtde,
          (string)$fI['vlr_unit']          => $vlrUnit,
          (string)$fI['vlr_total']         => $vlrTotal,
          (string)$fI['frete_rateio']      => $rateio,
          (string)$fI['total_com_rateios'] => $totalComRateio,
        ];

        $produtoFiscal = fbr_lookup_produto_fiscal($pdoFb, $tProducts, $fP, $referencia, $produtoFiscalCache);
        $cfopFiscal = trim((string)($produtoFiscal['cfop'] ?? ''));
        $cstFiscal = trim((string)($produtoFiscal['cst'] ?? ''));
        $csosnFiscal = trim((string)($produtoFiscal['csosn'] ?? ''));
        $origemFiscal = trim((string)($produtoFiscal['origem'] ?? ''));

        $itemDateValue = date('Y-m-d H:i:s', strtotime((string)($p['created_at'] ?? 'now')));
        $optionalValues = [
          'und'              => (string)($defaultsItems['und'] ?? 'UN'),
          'tipo'             => (string)($defaultsItems['tipo'] ?? 'V'),
          'data'             => $itemDateValue,
          'data_vendedor'    => $itemDateValue,
          'codprod'          => $referencia,
          'prc_venda'        => $vlrUnit,
          'transportadora'   => (int)($defaultsItems['transportadora'] ?? 3),
          'cfop'             => $cfopFiscal !== '' ? $cfopFiscal : (string)($defaultsItems['cfop'] ?? '5102'),
          'cst'              => $cstFiscal !== '' ? $cstFiscal : (string)($defaultsItems['cst'] ?? ''),
          'csosn'            => $csosnFiscal !== '' ? $csosnFiscal : (string)($defaultsItems['csosn'] ?? ''),
          'origem'           => $origemFiscal !== '' ? $origemFiscal : (string)($defaultsItems['origem'] ?? ''),
          'acrescimos'       => (float)($defaultsItems['acrescimos'] ?? 0),
          'vlr_acrescimo'    => (float)($defaultsItems['vlr_acrescimo'] ?? 0),
          'acrescimo_rateio' => (float)($defaultsItems['acrescimo_rateio'] ?? 0),
          'desconto'         => (float)($defaultsItems['desconto'] ?? 0),
          'vlr_desconto'     => (float)($defaultsItems['vlr_desconto'] ?? 0),
          'desconto_rateio'  => (float)($defaultsItems['desconto_rateio'] ?? 0),
          'vlr_impostos'     => (float)($defaultsItems['vlr_impostos'] ?? 0),
          'qtde_liberada'    => (float)($defaultsItems['qtde_liberada'] ?? 0),
          'qtde_faturada'    => (float)($defaultsItems['qtde_faturada'] ?? 0),
          'qtde_cancelada'   => (float)($defaultsItems['qtde_cancelada'] ?? 0),
        ];
        foreach ($optionalValues as $logicalKey => $value) {
          $col = strtoupper(trim((string)($fI[$logicalKey] ?? '')));
          if ($col !== '' && in_array($col, $fieldsItems, true)) $itemPairs[$col] = $value;
        }
        // Alguns schemas do Próspero usam DATA no item em vez de DATA_VENDEDOR.
        if (!isset($itemPairs['DATA']) && in_array('DATA', $fieldsItems, true)) {
          $itemPairs['DATA'] = $itemDateValue;
        }
        if (!isset($itemPairs['DATA_VENDEDOR']) && in_array('DATA_VENDEDOR', $fieldsItems, true)) {
          $itemPairs['DATA_VENDEDOR'] = $itemDateValue;
        }

        [$sqlI, $valsI] = fbr_prepare_insert($tItems, $itemPairs);
        $cmdI = $pdoFb->prepare($sqlI);
        $cmdI->execute($valsI);
      }

      $pdoFb->commit();

      $stU = $pdo->prepare("UPDATE pedidos_exportacao SET exportado=1, exportado_em=NOW(), ultimo_erro=NULL WHERE company_id=? AND pedido_id=?");
      $stU->execute([$companyId, $pid]);
      $log[] = "Pedido #{$pid} exportado para Firebird. Orçamento {$orcamentoCodigo}.";
    } catch (Throwable $e) {
      try { if ($pdoFb->inTransaction()) $pdoFb->rollBack(); } catch (Throwable $e2) {}
      $stE = $pdo->prepare("UPDATE pedidos_exportacao SET ultimo_erro=? WHERE company_id=? AND pedido_id=?");
      $stE->execute([mb_substr($e->getMessage(), 0, 900), $companyId, $pid]);
      $log[] = "ERRO no pedido #{$pid}: " . $e->getMessage();
    }
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

  return ['ok' => $err === '', 'error' => $err, 'log' => $log];
}
