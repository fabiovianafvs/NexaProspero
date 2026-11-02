<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/external_api.php';
require_once __DIR__ . '/../config/company_sequences.php';

external_api_handle_options();

/** @var PDO $pdo */
$auth = external_api_authenticate($pdo, 'products');
$companyId = (int)$auth['company_id'];
$method = external_api_method();
company_sequence_ensure_schema($pdo, true);

function api_produtos_has_column(PDO $pdo, string $column): bool {
  try {
    $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
    $st->execute(['produtos', $column]);
    return (bool)$st->fetchColumn();
  } catch (Throwable $e) {
    try { $st = $pdo->query("SHOW COLUMNS FROM produtos LIKE " . $pdo->quote($column)); return $st && (bool)$st->fetch(); } catch (Throwable $e2) { return false; }
  }
}
function api_produtos_barcode_columns(PDO $pdo): array {
  $cols = [];
  foreach (['codigo_barras','ean','gtin','barcode'] as $col) if (api_produtos_has_column($pdo, $col)) $cols[] = $col;
  return array_values(array_unique($cols));
}

function api_produtos_fetch(PDO $pdo, int $companyId, array $params): array {
  $codeCol = external_api_product_code_column($pdo);
  $hasMap = external_api_table_exists($pdo, 'produtos_firebird_map');

  $selectCode = $codeCol ? "p.codigo_firebird" : ($hasMap ? "m.codigo_firebird" : "'' AS codigo_firebird");
  $join = (!$codeCol && $hasMap) ? "LEFT JOIN produtos_firebird_map m ON m.company_id = p.company_id AND m.produto_id = p.id" : "";

  $where = ['p.company_id = ?'];
  $bind = [$companyId];

  if (!empty($params['id'])) {
    $where[] = 'p.id = ?';
    $bind[] = (int)$params['id'];
  }
  if (!empty($params['codigo_firebird'])) {
    if ($codeCol) {
      $where[] = 'p.codigo_firebird = ?';
    } else {
      external_api_ensure_product_map($pdo);
      if ($join === '') $join = "LEFT JOIN produtos_firebird_map m ON m.company_id = p.company_id AND m.produto_id = p.id";
      $where[] = 'm.codigo_firebird = ?';
    }
    $bind[] = trim((string)$params['codigo_firebird']);
  }
  if (isset($params['ativo']) && $params['ativo'] !== '') {
    $where[] = 'p.ativo = ?';
    $bind[] = ((string)$params['ativo'] === '0' || (string)$params['ativo'] === 'false') ? 0 : 1;
  }
  if (!empty($params['q'])) {
    $like = nexa_search_like(trim((string)$params['q']));
    $parts = ['p.nome LIKE ?', 'p.categoria LIKE ?', 'p.descricao LIKE ?', 'CAST(p.id AS CHAR) LIKE ?'];
    array_push($bind, $like, $like, $like, $like);
    if (company_sequence_has_column($pdo, 'produtos')) { $parts[] = 'p.numero_cadastro LIKE ?'; $bind[] = $like; }
    if (api_produtos_has_column($pdo, 'referencia')) { $parts[] = 'p.referencia LIKE ?'; $bind[] = $like; }
    foreach (api_produtos_barcode_columns($pdo) as $bcCol) { $parts[] = 'p.`' . str_replace('`', '``', $bcCol) . '` LIKE ?'; $bind[] = $like; }
    $where[] = '(' . implode(' OR ', $parts) . ')';
  }

  $limit = max(1, min(500, (int)($params['limit'] ?? 100)));
  $offset = max(0, (int)($params['offset'] ?? 0));

  $sql = "SELECT p.*, {$selectCode}
          FROM produtos p
          {$join}
          WHERE " . implode(' AND ', $where) . "
          ORDER BY p.id DESC
          LIMIT {$limit} OFFSET {$offset}";

  $st = $pdo->prepare($sql);
  $st->execute($bind);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  return array_map(fn($r) => external_api_product_response($pdo, $r), $rows);
}

function api_produtos_save(PDO $pdo, int $companyId, array $data): array {
  $id = (int)($data['id'] ?? 0);
  $codigo = trim((string)($data['codigo_firebird'] ?? $data['codigo'] ?? ''));
  $referencia = trim((string)($data['referencia'] ?? $data['reference'] ?? ''));
  $codigoBarras = preg_replace('/\D+/', '', (string)($data['codigo_barras'] ?? $data['ean'] ?? $data['gtin'] ?? $data['barcode'] ?? '')) ?? '';

  if ($id <= 0 && $codigo !== '') {
    $found = external_api_product_by_code($pdo, $companyId, $codigo);
    if ($found) $id = (int)$found['id'];
  }

  $nome = trim((string)($data['nome'] ?? $data['name'] ?? ''));
  $descricao = trim((string)($data['descricao'] ?? $data['description'] ?? ''));
  $categoria = trim((string)($data['categoria'] ?? $data['category'] ?? ''));
  $preco = external_api_money_to_float($data['preco'] ?? $data['price'] ?? 0);
  $ativo = array_key_exists('ativo', $data) ? (!empty($data['ativo']) ? 1 : 0) : 1;

  if ($id <= 0 && $nome === '') {
    external_api_json_response(['ok' => false, 'error' => 'Informe o nome do produto.'], 400);
  }

  $hasCodeCol = external_api_product_code_column($pdo) !== null;
  $hasBarcodeCol = api_produtos_has_column($pdo, 'codigo_barras');
  $hasReferenceCol = api_produtos_has_column($pdo, 'referencia');

  if ($id > 0) {
    $stOld = $pdo->prepare("SELECT * FROM produtos WHERE id = ? AND company_id = ? LIMIT 1");
    $stOld->execute([$id, $companyId]);
    $old = $stOld->fetch(PDO::FETCH_ASSOC);
    if (!$old) external_api_json_response(['ok' => false, 'error' => 'Produto não encontrado.'], 404);

    if ($nome === '') $nome = (string)($old['nome'] ?? '');
    if ($codigo === '' && isset($old['codigo_firebird'])) $codigo = (string)($old['codigo_firebird'] ?? '');
    if (!array_key_exists('descricao', $data) && !array_key_exists('description', $data)) $descricao = (string)($old['descricao'] ?? '');
    if (!array_key_exists('categoria', $data) && !array_key_exists('category', $data)) $categoria = (string)($old['categoria'] ?? '');
    if (!array_key_exists('preco', $data) && !array_key_exists('price', $data)) $preco = (float)($old['preco'] ?? 0);
    if (!array_key_exists('ativo', $data)) $ativo = (int)($old['ativo'] ?? 1);
    if (!array_key_exists('referencia', $data) && !array_key_exists('reference', $data)) $referencia = (string)($old['referencia'] ?? '');

    $sql = $hasCodeCol
      ? "UPDATE produtos SET nome=?, descricao=?, categoria=?, preco=?, ativo=?, codigo_firebird=? WHERE id=? AND company_id=?"
      : "UPDATE produtos SET nome=?, descricao=?, categoria=?, preco=?, ativo=? WHERE id=? AND company_id=?";
    $params = $hasCodeCol
      ? [$nome, $descricao, $categoria, $preco, $ativo, ($codigo !== '' ? $codigo : null), $id, $companyId]
      : [$nome, $descricao, $categoria, $preco, $ativo, $id, $companyId];
    $st = $pdo->prepare($sql);
    $st->execute($params);
    if ($hasBarcodeCol && (array_key_exists('codigo_barras', $data) || array_key_exists('ean', $data) || array_key_exists('gtin', $data) || array_key_exists('barcode', $data))) {
      $pdo->prepare('UPDATE produtos SET codigo_barras = ? WHERE id = ? AND company_id = ?')->execute([$codigoBarras !== '' ? $codigoBarras : null, $id, $companyId]);
    }
    if ($hasReferenceCol && (array_key_exists('referencia', $data) || array_key_exists('reference', $data))) {
      $pdo->prepare('UPDATE produtos SET referencia = ? WHERE id = ? AND company_id = ?')->execute([$referencia !== '' ? mb_substr($referencia, 0, 180) : null, $id, $companyId]);
    }
  } else {
    $insertCols = ['company_id'];
    $insertVals = [$companyId];
    if (company_sequence_has_column($pdo, 'produtos')) {
      $insertCols[] = 'numero_cadastro';
      $insertVals[] = company_sequence_next($pdo, $companyId, 'produtos');
    }
    $insertCols = array_merge($insertCols, ['nome','descricao','categoria','preco','ativo']);
    $insertVals = array_merge($insertVals, [$nome, $descricao, $categoria, $preco, $ativo]);
    if ($hasCodeCol) {
      $insertCols[] = 'codigo_firebird';
      $insertVals[] = ($codigo !== '' ? $codigo : null);
    }
    if ($hasBarcodeCol) {
      $insertCols[] = 'codigo_barras';
      $insertVals[] = ($codigoBarras !== '' ? $codigoBarras : null);
    }
    if ($hasReferenceCol) {
      $insertCols[] = 'referencia';
      $insertVals[] = ($referencia !== '' ? mb_substr($referencia, 0, 180) : null);
    }
    $ph = implode(',', array_fill(0, count($insertCols), '?'));
    $st = $pdo->prepare('INSERT INTO produtos (`' . implode('`,`', $insertCols) . '`) VALUES (' . $ph . ')');
    $st->execute($insertVals);
    $id = (int)$pdo->lastInsertId();
  }

  if ($codigo !== '') external_api_save_product_code($pdo, $companyId, $id, $codigo);

  $rows = api_produtos_fetch($pdo, $companyId, ['id' => $id, 'limit' => 1]);
  return $rows[0] ?? ['id' => $id];
}

try {
  if ($method === 'GET') {
    $items = api_produtos_fetch($pdo, $companyId, $_GET);
    $payload = [
      'ok' => true,
      'total' => count($items),
      'items' => $items,
    ];
    if (!empty($_GET['id']) || !empty($_GET['codigo_firebird'])) {
      $payload['item'] = $items[0] ?? null;
    }
    external_api_json_response($payload);
  }

  if (in_array($method, ['POST','PUT','PATCH'], true)) {
    $data = external_api_read_json();
    if (!$data) $data = $_POST;

    $isBatch = isset($data[0]) && is_array($data[0]);
    $items = $isBatch ? $data : (array)($data['items'] ?? []);
    if (!$items) $items = [$data];

    $out = [];
    foreach ($items as $item) {
      if (!is_array($item)) continue;
      $out[] = api_produtos_save($pdo, $companyId, $item);
    }

    $payload = ['ok' => true, 'total' => count($out), 'items' => $out];
    if (!$isBatch && count($out) === 1) $payload['item'] = $out[0];
    external_api_log($pdo, $companyId, 'produtos', $method, 200, $data, $payload);
    external_api_json_response($payload);
  }

  if ($method === 'DELETE') {
    $data = external_api_read_json();
    $id = (int)($_GET['id'] ?? $data['id'] ?? 0);
    $codigo = trim((string)($_GET['codigo_firebird'] ?? $data['codigo_firebird'] ?? ''));
    if ($id <= 0 && $codigo !== '') {
      $found = external_api_product_by_code($pdo, $companyId, $codigo);
      if ($found) $id = (int)$found['id'];
    }
    if ($id <= 0) external_api_json_response(['ok' => false, 'error' => 'Informe id ou codigo_firebird.'], 400);

    $st = $pdo->prepare("UPDATE produtos SET ativo = 0 WHERE id = ? AND company_id = ?");
    $st->execute([$id, $companyId]);
    external_api_json_response(['ok' => true, 'message' => 'Produto desativado.', 'id' => $id]);
  }

  external_api_json_response(['ok' => false, 'error' => 'Método não permitido.'], 405);
} catch (Throwable $e) {
  $payload = ['ok' => false, 'error' => $e->getMessage()];
  external_api_log($pdo, $companyId ?? null, 'produtos', $method ?? 'GET', 500, $_GET ?? [], $payload);
  external_api_json_response($payload, 500);
}
