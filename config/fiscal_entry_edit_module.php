<?php
declare(strict_types=1);

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/product_composition.php';

if (!function_exists('fiscal_entry_edit_ensure_schema')) {
  function fiscal_entry_edit_ensure_schema(PDO $pdo): void {
    fiscal_xml_ensure_schema($pdo);
    finance_ensure_schema($pdo);
    foreach ([
      'undone_at' => 'DATETIME NULL',
      'undone_by' => 'INT NULL',
      'undo_reason' => 'VARCHAR(255) NULL',
      'edited_at' => 'DATETIME NULL',
      'edited_by' => 'INT NULL',
    ] as $col => $def) {
      try { fiscal_add_column_if_missing($pdo, 'fiscal_xml_imports', $col, $def); } catch (Throwable $e) {}
    }
  }
}

if (!function_exists('fiscal_entry_edit_user_id')) {
  function fiscal_entry_edit_user_id(): int {
    if (function_exists('auth_user_id')) return (int)(auth_user_id() ?? 0);
    return (int)($_SESSION['user_id'] ?? ($_SESSION['user']['id'] ?? 0));
  }
}

if (!function_exists('fiscal_entry_edit_decimal')) {
  function fiscal_entry_edit_decimal($value, float $default = 0.0): float {
    if (function_exists('fiscal_xml_parse_decimal')) return fiscal_xml_parse_decimal($value, $default);
    $v = trim((string)$value);
    if ($v === '') return $default;
    $v = str_replace(['R$', ' '], '', $v);
    if (str_contains($v, ',') && str_contains($v, '.')) $v = str_replace('.', '', $v);
    $v = str_replace(',', '.', $v);
    return is_numeric($v) ? (float)$v : $default;
  }
}

if (!function_exists('fiscal_entry_tax_json')) {
  function fiscal_entry_tax_json(array $item): array {
    $raw = trim((string)($item['tax_json'] ?? ''));
    if ($raw === '') return [];
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
  }
}

if (!function_exists('fiscal_entry_finance_payable_ids')) {
  function fiscal_entry_finance_payable_ids(array $xml): array {
    $ids = json_decode((string)($xml['finance_payable_ids'] ?? ''), true);
    if (!is_array($ids)) return [];
    $out = [];
    foreach ($ids as $id) { $id = (int)$id; if ($id > 0) $out[$id] = $id; }
    return array_values($out);
  }
}

if (!function_exists('fiscal_entry_assert_finance_reversible')) {
  function fiscal_entry_assert_finance_reversible(PDO $pdo, int $companyId, array $payableIds): void {
    if (!$payableIds) return;
    $stPay = $pdo->prepare('SELECT id, document_number, installment, paid_amount, status FROM finance_payables WHERE company_id = ? AND id = ? LIMIT 1');
    $stSet = $pdo->prepare("SELECT COUNT(*) FROM finance_settlements WHERE company_id = ? AND origin_type = 'PAGAR' AND origin_id = ? AND COALESCE(status,'ATIVO')='ATIVO'");
    foreach ($payableIds as $pid) {
      $stPay->execute([$companyId, (int)$pid]);
      $p = $stPay->fetch(PDO::FETCH_ASSOC);
      if (!$p) continue;
      $stSet->execute([$companyId, (int)$pid]);
      $settlements = (int)$stSet->fetchColumn();
      $paid = round((float)($p['paid_amount'] ?? 0), 2);
      if ($paid > 0.009 || $settlements > 0 || in_array(strtoupper((string)($p['status'] ?? '')), ['BAIXADO','PARCIAL'], true)) {
        $label = trim((string)($p['document_number'] ?? '') . ' ' . (string)($p['installment'] ?? ''));
        throw new RuntimeException('Não é possível desfazer a nota porque o Contas a Pagar ' . ($label !== '' ? $label : ('#' . (int)$pid)) . ' já possui baixa financeira. Estorne a baixa no Contas a Pagar e tente novamente.');
      }
    }
  }
}

if (!function_exists('fiscal_entry_undo')) {
  function fiscal_entry_undo(PDO $pdo, int $companyId, int $xmlId, int $userId = 0, string $reason = ''): void {
    fiscal_entry_edit_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota de entrada não encontrada.');
    if ((string)($xml['status'] ?? '') !== 'ENTRADA_GERADA') throw new RuntimeException('Esta nota ainda não possui uma entrada gerada para desfazer.');

    $payableIds = fiscal_entry_finance_payable_ids($xml);
    fiscal_entry_assert_finance_reversible($pdo, $companyId, $payableIds);

    $operation = !empty($xml['operation_id']) ? fiscal_operation($pdo, $companyId, (int)$xml['operation_id']) : null;
    $updateStock = $operation && (int)($operation['update_stock'] ?? 0) === 1;
    $items = fiscal_xml_items($pdo, $companyId, $xmlId);

    if ($updateStock) {
      require_once __DIR__ . '/stock_module.php';
      if (function_exists('stock_ensure_schema')) stock_ensure_schema($pdo, true);
    }

    $pdo->beginTransaction();
    try {
      if ($updateStock) {
        $hasStock = fiscal_column_exists($pdo, 'produtos', 'estoque_atual');
        if (!$hasStock) throw new RuntimeException('O controle de estoque não está disponível para desfazer esta nota.');
        $sel = $pdo->prepare('SELECT estoque_atual FROM produtos WHERE company_id = ? AND id = ? FOR UPDATE');
        $upd = $pdo->prepare('UPDATE produtos SET estoque_atual = ? WHERE company_id = ? AND id = ?');
        $mov = null;
        if (fiscal_table_exists($pdo, 'estoque_movimentos')) {
          try { $mov = $pdo->prepare('INSERT INTO estoque_movimentos (company_id, produto_id, tipo, origem_tipo, origem_id, qtd, saldo_anterior, saldo_posterior, observacao) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'); } catch (Throwable $e) {}
        }
        foreach ($items as $it) {
          $productId = (int)($it['product_id'] ?? 0);
          if ($productId <= 0) continue;
          $qty = (float)($it['stock_quantity'] ?? 0);
          if ($qty <= 0) {
            $factor = fiscal_entry_edit_decimal($it['conversion_factor'] ?? 1, 1.0);
            if ($factor <= 0) $factor = 1.0;
            $qty = round((float)($it['quantity'] ?? 0) * $factor, 4);
          }
          if ($qty <= 0) continue;
          $sel->execute([$companyId, $productId]);
          $row = $sel->fetch(PDO::FETCH_ASSOC);
          if (!$row) throw new RuntimeException('Produto #' . $productId . ' não foi encontrado para desfazer o estoque.');
          $before = round((float)($row['estoque_atual'] ?? 0), 4);
          $after = round($before - $qty, 4);
          if ($after < -0.0001) {
            throw new RuntimeException('Não é possível desfazer a nota: o produto #' . $productId . ' possui estoque atual de ' . number_format($before, 4, ',', '.') . ', inferior aos ' . number_format($qty, 4, ',', '.') . ' lançados por esta nota. Revise as saídas/estoque deste produto antes de desfazer.');
          }
          $upd->execute([$after, $companyId, $productId]);
          if ($mov) {
            $obs = 'Desfazer entrada NF-e ' . (string)($xml['number'] ?? '') . ' / item ' . (string)($it['item_number'] ?? '') . ($reason !== '' ? ' - ' . mb_substr($reason, 0, 120) : '');
            $mov->execute([$companyId, $productId, 'saida', 'XML_NFE_UNDO', $xmlId, $qty, $before, $after, $obs]);
          }
        }
      }

      if ($updateStock && function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId) && product_composition_schema_ready($pdo)) {
        product_composition_recalculate_all($pdo, $companyId, 'Desfazer entrada XML #' . $xmlId);
      }

      if ($payableIds) {
        $del = $pdo->prepare('DELETE FROM finance_payables WHERE company_id = ? AND id = ?');
        foreach ($payableIds as $pid) $del->execute([$companyId, (int)$pid]);
      }

      $st = $pdo->prepare("UPDATE fiscal_xml_imports SET status='IMPORTADO', operation_id=NULL, finance_payable_ids=NULL, generated_at=NULL, undone_at=NOW(), undone_by=?, undo_reason=?, updated_at=NOW() WHERE company_id=? AND id=?");
      $st->execute([$userId > 0 ? $userId : null, $reason !== '' ? mb_substr($reason, 0, 255) : null, $companyId, $xmlId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('fiscal_entry_delete')) {
  function fiscal_entry_delete(PDO $pdo, int $companyId, int $xmlId): void {
    fiscal_entry_edit_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota não encontrada.');
    if ((string)($xml['status'] ?? '') === 'ENTRADA_GERADA') throw new RuntimeException('Esta nota já movimentou estoque/financeiro. Use “Desfazer Nota” antes de excluir.');
    $payableIds = fiscal_entry_finance_payable_ids($xml);
    if ($payableIds) throw new RuntimeException('Esta nota ainda possui vínculos financeiros. Desfaça a entrada antes de excluir.');
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM fiscal_xml_items WHERE company_id = ? AND xml_import_id = ?')->execute([$companyId, $xmlId]);
      $pdo->prepare('DELETE FROM fiscal_xml_imports WHERE company_id = ? AND id = ?')->execute([$companyId, $xmlId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('fiscal_entry_save_header')) {
  function fiscal_entry_save_header(PDO $pdo, int $companyId, int $xmlId, array $post, int $userId = 0): void {
    fiscal_entry_edit_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota não encontrada.');
    $issue = fiscal_xml_date($post['edit_issue_date'] ?? '') ?: ($xml['issue_date'] ?? null);
    $entry = fiscal_xml_date($post['edit_entry_date'] ?? '') ?: ($xml['entry_date'] ?? $issue);
    $supplierName = trim((string)($post['edit_supplier_name'] ?? $xml['supplier_name'] ?? ''));
    $supplierDoc = preg_replace('/\D+/', '', (string)($post['edit_supplier_doc'] ?? $xml['supplier_doc'] ?? ''));
    $supplierIe = trim((string)($post['edit_supplier_ie'] ?? $xml['supplier_ie'] ?? ''));
    $supplierUf = strtoupper(substr(trim((string)($post['edit_supplier_uf'] ?? $xml['supplier_uf'] ?? '')), 0, 2));
    $freight = round(max(0, fiscal_entry_edit_decimal($post['edit_freight_value'] ?? $xml['freight_value'] ?? 0)), 2);
    $discount = round(max(0, fiscal_entry_edit_decimal($post['edit_discount_value'] ?? $xml['discount_value'] ?? 0)), 2);
    $other = round(max(0, fiscal_entry_edit_decimal($post['edit_other_value'] ?? $xml['other_value'] ?? 0)), 2);
    $totalInvoice = round(max(0, fiscal_entry_edit_decimal($post['edit_total_invoice'] ?? $xml['total_invoice'] ?? 0)), 2);
    $notes = trim((string)($post['edit_notes'] ?? $xml['notes'] ?? ''));
    if ($supplierName === '') throw new RuntimeException('Informe o fornecedor da nota.');
    $st = $pdo->prepare('UPDATE fiscal_xml_imports SET issue_date=?, entry_date=?, supplier_name=?, supplier_doc=?, supplier_ie=?, supplier_uf=?, freight_value=?, discount_value=?, other_value=?, total_invoice=?, notes=?, edited_at=NOW(), edited_by=?, updated_at=NOW() WHERE company_id=? AND id=?');
    $st->execute([$issue, $entry, $supplierName, $supplierDoc ?: null, $supplierIe ?: null, $supplierUf ?: null, $freight, $discount, $other, $totalInvoice, $notes ?: null, $userId > 0 ? $userId : null, $companyId, $xmlId]);
  }
}

if (!function_exists('fiscal_entry_auto_tax_conversion')) {
  function fiscal_entry_auto_tax_conversion(PDO $pdo, int $companyId, array $sourceItem, array $entryTax): int {
    if (!function_exists('fiscal_xml_match_tax_conversion') || !function_exists('fiscal_xml_tax_code')) return 0;
    try {
      $match = fiscal_xml_match_tax_conversion($pdo, $companyId, $sourceItem);
      if ($match && (int)($match['id'] ?? 0) > 0) return (int)$match['id'];

      $input = [
        'input_cfop' => fiscal_cfop_digits($sourceItem['cfop'] ?? ''),
        'input_icms_cst' => fiscal_xml_tax_code($sourceItem['icms_cst'] ?? ''),
        'input_icms_csosn' => fiscal_xml_tax_code($sourceItem['icms_csosn'] ?? ''),
        'input_ipi_cst' => fiscal_xml_tax_code($sourceItem['ipi_cst'] ?? ''),
        'input_pis_cst' => fiscal_xml_tax_code($sourceItem['pis_cst'] ?? ''),
        'input_cofins_cst' => fiscal_xml_tax_code($sourceItem['cofins_cst'] ?? ''),
      ];
      $output = [
        'output_cfop' => fiscal_cfop_digits($entryTax['entry_cfop'] ?? ''),
        'output_icms_cst' => fiscal_xml_tax_code($entryTax['entry_icms_cst'] ?? ''),
        'output_icms_csosn' => fiscal_xml_tax_code($entryTax['entry_icms_csosn'] ?? ''),
        'output_ipi_cst' => fiscal_xml_tax_code($entryTax['entry_ipi_cst'] ?? ''),
        'output_pis_cst' => fiscal_xml_tax_code($entryTax['entry_pis_cst'] ?? ''),
        'output_cofins_cst' => fiscal_xml_tax_code($entryTax['entry_cofins_cst'] ?? ''),
      ];
      if (!array_filter($output, static fn($v) => trim((string)$v) !== '')) return 0;

      $st = $pdo->prepare("SELECT id FROM fiscal_xml_tax_conversions WHERE company_id=? AND is_active=1
        AND COALESCE(input_cfop,'')=? AND COALESCE(input_icms_cst,'')=? AND COALESCE(input_icms_csosn,'')=?
        AND COALESCE(input_ipi_cst,'')=? AND COALESCE(input_pis_cst,'')=? AND COALESCE(input_cofins_cst,'')=?
        AND COALESCE(output_cfop,'')=? AND COALESCE(output_icms_cst,'')=? AND COALESCE(output_icms_csosn,'')=?
        AND COALESCE(output_ipi_cst,'')=? AND COALESCE(output_pis_cst,'')=? AND COALESCE(output_cofins_cst,'')=? LIMIT 1");
      $st->execute([$companyId, $input['input_cfop'], $input['input_icms_cst'], $input['input_icms_csosn'], $input['input_ipi_cst'], $input['input_pis_cst'], $input['input_cofins_cst'], $output['output_cfop'], $output['output_icms_cst'], $output['output_icms_csosn'], $output['output_ipi_cst'], $output['output_pis_cst'], $output['output_cofins_cst']]);
      $found = (int)$st->fetchColumn();
      if ($found > 0) return $found;

      $parts = [];
      if ($input['input_cfop'] !== '') $parts[] = 'CFOP '.$input['input_cfop'];
      $icmsIn = $input['input_icms_cst'] !== '' ? $input['input_icms_cst'] : $input['input_icms_csosn'];
      $icmsOut = $output['output_icms_cst'] !== '' ? $output['output_icms_cst'] : $output['output_icms_csosn'];
      if ($icmsIn !== '') $parts[] = 'ICMS '.$icmsIn;
      $name = 'Auto XML ' . ($parts ? implode(' / ', $parts) : date('d/m/Y H:i'));
      if ($output['output_cfop'] !== '') $name .= ' → '.$output['output_cfop'];
      if ($icmsOut !== '' && $icmsOut !== $icmsIn) $name .= ' / '.$icmsOut;
      $name = mb_substr($name, 0, 120);

      $ins = $pdo->prepare("INSERT INTO fiscal_xml_tax_conversions
        (company_id,name,input_cfop,input_icms_cst,input_icms_csosn,input_ipi_cst,input_pis_cst,input_cofins_cst,output_cfop,output_icms_cst,output_icms_csosn,output_ipi_cst,output_pis_cst,output_cofins_cst,is_active,notes,created_at,updated_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW(),NOW())");
      $ins->execute([$companyId,$name,$input['input_cfop']?:null,$input['input_icms_cst']?:null,$input['input_icms_csosn']?:null,$input['input_ipi_cst']?:null,$input['input_pis_cst']?:null,$input['input_cofins_cst']?:null,$output['output_cfop']?:null,$output['output_icms_cst']?:null,$output['output_icms_csosn']?:null,$output['output_ipi_cst']?:null,$output['output_pis_cst']?:null,$output['output_cofins_cst']?:null,'Criada automaticamente ao conferir tributação de XML.']);
      return (int)$pdo->lastInsertId();
    } catch (Throwable $e) {
      return 0;
    }
  }
}

if (!function_exists('fiscal_entry_save_items')) {
  function fiscal_entry_save_items(PDO $pdo, int $companyId, int $xmlId, array $post, bool $allowStockFields, int $userId = 0): int {
    fiscal_entry_edit_ensure_schema($pdo);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota não encontrada.');
    if ((string)($xml['status'] ?? '') === 'ENTRADA_GERADA') $allowStockFields = false;
    $ids = (array)($post['edit_item_id'] ?? []);
    if (!$ids) return 0;
    $currentRows = [];
    foreach (fiscal_xml_items($pdo, $companyId, $xmlId) as $r) $currentRows[(int)$r['id']] = $r;

    $get = static function(array $post, string $key, int $id, $default = '') {
      $arr = (array)($post[$key] ?? []);
      return array_key_exists($id, $arr) ? $arr[$id] : $default;
    };
    $updated = 0;
    $totalProducts = 0.0;
    $pdo->beginTransaction();
    try {
      foreach ($ids as $rawId) {
        $itemId = (int)$rawId;
        if ($itemId <= 0 || !isset($currentRows[$itemId])) continue;
        $cur = $currentRows[$itemId];
        $productId = $allowStockFields ? (int)$get($post, 'edit_product_id', $itemId, $cur['product_id'] ?? 0) : (int)($cur['product_id'] ?? 0);
        $qty = $allowStockFields ? max(0, fiscal_entry_edit_decimal($get($post, 'edit_quantity', $itemId, $cur['quantity'] ?? 0))) : (float)($cur['quantity'] ?? 0);
        $unitValue = $allowStockFields ? max(0, fiscal_entry_edit_decimal($get($post, 'edit_unit_value', $itemId, $cur['unit_value'] ?? 0))) : (float)($cur['unit_value'] ?? 0);
        $totalValue = $allowStockFields ? max(0, fiscal_entry_edit_decimal($get($post, 'edit_total_value', $itemId, $cur['total_value'] ?? 0))) : (float)($cur['total_value'] ?? 0);
        if ($allowStockFields && $totalValue <= 0 && $qty > 0 && $unitValue > 0) $totalValue = round($qty * $unitValue, 2);
        $factor = $allowStockFields ? max(0.000001, fiscal_entry_edit_decimal($get($post, 'edit_conversion_factor', $itemId, $cur['conversion_factor'] ?? 1), 1.0)) : (float)($cur['conversion_factor'] ?? 1);
        $stockQty = $allowStockFields ? max(0, fiscal_entry_edit_decimal($get($post, 'edit_stock_quantity', $itemId, $qty * $factor))) : (float)($cur['stock_quantity'] ?? 0);
        if ($allowStockFields && $stockQty <= 0) $stockQty = round($qty * $factor, 4);
        $stockCost = $allowStockFields ? max(0, fiscal_entry_edit_decimal($get($post, 'edit_stock_unit_cost', $itemId, $cur['stock_unit_cost'] ?? 0))) : (float)($cur['stock_unit_cost'] ?? 0);
        if ($allowStockFields && $stockCost <= 0 && $stockQty > 0) $stockCost = round($totalValue / $stockQty, 4);

        $tax = fiscal_entry_tax_json($cur);
        $taxMap = [
          'icms_base'=>'edit_icms_base','icms_rate'=>'edit_icms_rate','icms_amount'=>'edit_icms_amount',
          'icms_st_base'=>'edit_icms_st_base','icms_st_rate'=>'edit_icms_st_rate','icms_st_amount'=>'edit_icms_st_amount',
          'ipi_base'=>'edit_ipi_base','ipi_rate'=>'edit_ipi_rate','ipi_amount'=>'edit_ipi_amount',
          'pis_base'=>'edit_pis_base','pis_rate'=>'edit_pis_rate','pis_amount'=>'edit_pis_amount',
          'cofins_base'=>'edit_cofins_base','cofins_rate'=>'edit_cofins_rate','cofins_amount'=>'edit_cofins_amount',
        ];
        foreach ($taxMap as $jsonKey => $postKey) {
          $tax[$jsonKey] = round(max(0, fiscal_entry_edit_decimal($get($post, $postKey, $itemId, $tax[$jsonKey] ?? 0))), in_array($jsonKey, ['icms_rate','icms_st_rate','ipi_rate','pis_rate','cofins_rate'], true) ? 4 : 2);
        }
        $entryOrigem = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_origem', $itemId, $cur['entry_origem'] ?? $cur['origem'] ?? ''));
        $entryIcmsCst = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_icms_cst', $itemId, $cur['entry_icms_cst'] ?? ''));
        $entryIcmsCsosn = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_icms_csosn', $itemId, $cur['entry_icms_csosn'] ?? ''));
        $entryIpiCst = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_ipi_cst', $itemId, $cur['entry_ipi_cst'] ?? ''));
        $entryPisCst = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_pis_cst', $itemId, $cur['entry_pis_cst'] ?? ''));
        $entryCofinsCst = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_cofins_cst', $itemId, $cur['entry_cofins_cst'] ?? ''));
        $tax['icms_origin'] = $entryOrigem ?: ($tax['icms_origin'] ?? '');
        $tax['icms_cst'] = $entryIcmsCst ?: ($tax['icms_cst'] ?? '');
        $tax['icms_csosn'] = $entryIcmsCsosn ?: ($tax['icms_csosn'] ?? '');
        $tax['ipi_cst'] = $entryIpiCst ?: ($tax['ipi_cst'] ?? '');
        $tax['pis_cst'] = $entryPisCst ?: ($tax['pis_cst'] ?? '');
        $tax['cofins_cst'] = $entryCofinsCst ?: ($tax['cofins_cst'] ?? '');
        $ncm = preg_replace('/\D+/', '', (string)$get($post, 'edit_ncm', $itemId, $cur['ncm'] ?? ''));
        $cest = preg_replace('/\D+/', '', (string)$get($post, 'edit_cest', $itemId, $cur['cest'] ?? ''));
        $entryCfop = preg_replace('/\D+/', '', (string)$get($post, 'edit_entry_cfop', $itemId, $cur['entry_cfop'] ?? $cur['cfop'] ?? ''));
        $ipiValue = round((float)($tax['ipi_amount'] ?? $cur['ipi_value'] ?? 0), 2);
        $icmsStValue = round((float)($tax['icms_st_amount'] ?? $cur['icms_st_value'] ?? 0), 2);
        $taxConversionId = (int)($cur['tax_conversion_id'] ?? 0);
        if ($taxConversionId <= 0) {
          $taxConversionId = fiscal_entry_auto_tax_conversion($pdo, $companyId, $cur, [
            'entry_cfop'=>$entryCfop,'entry_icms_cst'=>$entryIcmsCst,'entry_icms_csosn'=>$entryIcmsCsosn,
            'entry_ipi_cst'=>$entryIpiCst,'entry_pis_cst'=>$entryPisCst,'entry_cofins_cst'=>$entryCofinsCst
          ]);
        }

        $sql = 'UPDATE fiscal_xml_items SET product_id=?, ncm=?, cest=?, entry_cfop=?, entry_origem=?, entry_icms_cst=?, entry_icms_csosn=?, entry_ipi_cst=?, entry_pis_cst=?, entry_cofins_cst=?, tax_conversion_id=?, quantity=?, unit_value=?, total_value=?, conversion_factor=?, stock_quantity=?, stock_unit_cost=?, ipi_value=?, icms_st_value=?, tax_json=?, linked_at=NOW() WHERE company_id=? AND xml_import_id=? AND id=?';
        $pdo->prepare($sql)->execute([
          $productId > 0 ? $productId : null, $ncm ?: null, $cest ?: null, $entryCfop ?: null, $entryOrigem ?: null, $entryIcmsCst ?: null, $entryIcmsCsosn ?: null,
          $entryIpiCst ?: null, $entryPisCst ?: null, $entryCofinsCst ?: null, $taxConversionId > 0 ? $taxConversionId : null, $qty, $unitValue, $totalValue, $factor, $stockQty, $stockCost,
          $ipiValue, $icmsStValue, json_encode($tax, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES), $companyId, $xmlId, $itemId
        ]);
        $totalProducts += $totalValue;
        $updated++;
      }
      if ($allowStockFields && $updated > 0) {
        $pdo->prepare('UPDATE fiscal_xml_imports SET total_products=?, edited_at=NOW(), edited_by=?, updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([round($totalProducts, 2), $userId > 0 ? $userId : null, $companyId, $xmlId]);
      } else if ($updated > 0) {
        $pdo->prepare('UPDATE fiscal_xml_imports SET edited_at=NOW(), edited_by=?, updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$userId > 0 ? $userId : null, $companyId, $xmlId]);
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return $updated;
  }
}
