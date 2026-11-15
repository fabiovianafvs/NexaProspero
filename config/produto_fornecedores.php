<?php
declare(strict_types=1);

/*
 * Nexa Próspero - aba "Fornecedores" do cadastro de produtos.
 * Compras do produto pelas entradas de NF-e já geradas (fiscal_xml_imports com
 * status ENTRADA_GERADA) e os vínculos salvos com os fornecedores
 * (fiscal_xml_product_links). O custo é o mesmo "custo completo" da geração da
 * entrada: produto + IPI + ST + FCP-ST + frete + seguro + outras − desconto,
 * com o rateio do que a nota informa só no total (fiscal_xml_entry_landed_value_map),
 * dividido pela quantidade em unidades de estoque (quantidade × fator).
 */

require_once __DIR__ . '/fiscal_module.php';

if (!function_exists('produto_fornecedores_data')) {
  function produto_fornecedores_data(PDO $pdo, int $companyId, int $productId, int $limit = 60): array {
    $out = ['suppliers' => [], 'purchases' => []];
    if ($productId <= 0 || !fiscal_table_exists($pdo, 'fiscal_xml_items') || !fiscal_table_exists($pdo, 'fiscal_xml_imports')) return $out;

    $dateExpr = fiscal_column_exists($pdo, 'fiscal_xml_imports', 'entry_date') ? 'COALESCE(x.entry_date, x.issue_date)' : 'x.issue_date';
    $st = $pdo->prepare("SELECT i.*, x.id AS xml_id, x.number AS nf_number, x.series AS nf_series, x.supplier_doc, x.supplier_name, x.issue_date AS nf_issue_date, $dateExpr AS purchase_date
      FROM fiscal_xml_items i
      INNER JOIN fiscal_xml_imports x ON x.company_id = i.company_id AND x.id = i.xml_import_id
      WHERE i.company_id = ? AND i.product_id = ? AND x.status = 'ENTRADA_GERADA'
      ORDER BY purchase_date DESC, x.id DESC, i.item_number ASC
      LIMIT " . max(1, min(200, $limit)));
    $st->execute([$companyId, $productId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Custo completo por item: rateio feito nota a nota, como na geração da entrada.
    $landed = [];
    foreach (array_unique(array_map(static fn($r) => (int)$r['xml_id'], $rows)) as $xmlId) {
      $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
      if (!$xml) continue;
      foreach (fiscal_xml_entry_landed_value_map($xml, fiscal_xml_items($pdo, $companyId, $xmlId)) as $itemId => $value) $landed[(int)$itemId] = (float)$value;
    }

    $people = [];
    $person = static function (string $doc) use ($pdo, $companyId, &$people): array {
      $doc = fiscal_xml_digits($doc);
      if ($doc === '') return ['id' => 0, 'code' => '', 'name' => ''];
      if (isset($people[$doc])) return $people[$doc];
      $res = ['id' => 0, 'code' => '', 'name' => ''];
      try {
        if (fiscal_table_exists($pdo, 'finance_people')) {
          $cols = 'id, name' . (fiscal_column_exists($pdo, 'finance_people', 'numero_cadastro') ? ', numero_cadastro' : '');
          $q = $pdo->prepare("SELECT $cols FROM finance_people WHERE company_id = ? AND document = ? LIMIT 1");
          $q->execute([$companyId, $doc]);
          if ($p = $q->fetch(PDO::FETCH_ASSOC)) {
            $res = ['id' => (int)$p['id'], 'code' => (string)((int)($p['numero_cadastro'] ?? 0) > 0 ? (int)$p['numero_cadastro'] : (int)$p['id']), 'name' => (string)$p['name']];
          }
        }
      } catch (Throwable $e) {}
      return $people[$doc] = $res;
    };

    $suppliers = [];
    foreach ($rows as $r) {
      $factor = fiscal_xml_parse_decimal($r['conversion_factor'] ?? 1, 1.0) ?: 1.0;
      $qty = (float)($r['quantity'] ?? 0);
      $stockQty = round($qty * $factor, 4);
      $value = $landed[(int)$r['id']] ?? fiscal_xml_item_landed_base_value($r);
      $unitCost = $stockQty > 0 ? round($value / $stockQty, 4) : 0.0;
      $doc = fiscal_xml_digits($r['supplier_doc'] ?? '');
      $p = $person($doc);
      $name = $p['name'] !== '' ? $p['name'] : (string)($r['supplier_name'] ?? '');
      $purchase = [
        'date' => (string)($r['purchase_date'] ?? ''),
        'xml_id' => (int)$r['xml_id'],
        'nf' => trim((string)($r['nf_number'] ?? '') . ((string)($r['nf_series'] ?? '') !== '' ? '/' . $r['nf_series'] : '')),
        'supplier_code' => $p['code'],
        'supplier_name' => $name,
        'supplier_doc' => $doc,
        'product_code' => (string)($r['product_code'] ?? ''),
        'description' => (string)($r['name'] ?? ''),
        'unit' => (string)($r['unit'] ?? ''),
        'quantity' => $qty,
        'factor' => $factor,
        'stock_quantity' => $stockQty,
        'unit_value' => round((float)($r['unit_value'] ?? 0), 4),
        'total' => round($value, 2),
        'cost' => $unitCost,
      ];
      $out['purchases'][] = $purchase;
      $key = $doc !== '' ? $doc : 'nome:' . mb_strtolower($name);
      if (!isset($suppliers[$key])) {
        $suppliers[$key] = $purchase + ['purchases' => 0, 'stock_total' => 0.0, 'previous_cost' => null, 'codes' => []];
      } elseif ($suppliers[$key]['previous_cost'] === null) {
        $suppliers[$key]['previous_cost'] = $unitCost; // compra anterior à última
      }
      $suppliers[$key]['purchases']++;
      $suppliers[$key]['stock_total'] += $stockQty;
      if ($purchase['product_code'] !== '') $suppliers[$key]['codes'][$purchase['product_code']] = true;
    }

    // Fornecedores com vínculo salvo, mesmo sem entrada gerada ainda.
    try {
      if (fiscal_table_exists($pdo, 'fiscal_xml_product_links')) {
        $q = $pdo->prepare('SELECT supplier_doc, supplier_product_code, supplier_ean, conversion_factor FROM fiscal_xml_product_links WHERE company_id = ? AND product_id = ? ORDER BY id DESC LIMIT 50');
        $q->execute([$companyId, $productId]);
        foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $l) {
          $doc = fiscal_xml_digits($l['supplier_doc'] ?? '');
          if ($doc === '') continue;
          $code = (string)($l['supplier_product_code'] ?? '');
          if (isset($suppliers[$doc])) {
            if ($code !== '') $suppliers[$doc]['codes'][$code] = true;
            continue;
          }
          $p = $person($doc);
          $name = $p['name'];
          if ($name === '') {
            $n = $pdo->prepare('SELECT supplier_name FROM fiscal_xml_imports WHERE company_id = ? AND supplier_doc = ? ORDER BY id DESC LIMIT 1');
            $n->execute([$companyId, $doc]);
            $name = (string)($n->fetchColumn() ?: '');
          }
          $suppliers[$doc] = [
            'date' => '', 'xml_id' => 0, 'nf' => '', 'supplier_code' => $p['code'], 'supplier_name' => $name, 'supplier_doc' => $doc,
            'product_code' => $code, 'description' => '', 'unit' => '', 'quantity' => 0, 'factor' => fiscal_xml_parse_decimal($l['conversion_factor'] ?? 1, 1.0) ?: 1.0,
            'stock_quantity' => 0, 'unit_value' => 0, 'total' => 0, 'cost' => null, 'purchases' => 0, 'stock_total' => 0.0, 'previous_cost' => null,
            'codes' => $code !== '' ? [$code => true] : [],
          ];
        }
      }
    } catch (Throwable $e) {}

    foreach ($suppliers as $s) {
      $s['codes'] = array_keys($s['codes']);
      $s['variation'] = ($s['previous_cost'] !== null && (float)$s['previous_cost'] > 0 && $s['cost'] !== null)
        ? round(((float)$s['cost'] / (float)$s['previous_cost'] - 1) * 100, 1) : null;
      $out['suppliers'][] = $s;
    }
    return $out;
  }
}
