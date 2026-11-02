<?php
declare(strict_types=1);

require_once __DIR__ . '/fiscal_module.php';
require_once __DIR__ . '/fiscal_operational.php';

if (!function_exists('fiscal_doc_report_payment_bucket')) {
  function fiscal_doc_report_payment_bucket(array $payment): string {
    $type = str_pad(preg_replace('/\D+/', '', (string)($payment['payment_type'] ?? '')) ?: '', 2, '0', STR_PAD_LEFT);
    $desc = function_exists('mb_strtolower') ? mb_strtolower((string)($payment['payment_description'] ?? ''), 'UTF-8') : strtolower((string)($payment['payment_description'] ?? ''));
    $indicator = preg_replace('/\D+/', '', (string)($payment['payment_indicator'] ?? ''));
    if ($type === '01' || strpos($desc, 'dinheiro') !== false) return 'dinheiro';
    if ($type === '17' || strpos($desc, 'pix') !== false) return 'pix';
    if ($type === '02' || strpos($desc, 'cheque') !== false) return 'cheque';
    if (in_array($type, ['03','04'], true) || strpos($desc, 'cart') !== false || strpos($desc, 'crédito') !== false || strpos($desc, 'credito') !== false || strpos($desc, 'débito') !== false || strpos($desc, 'debito') !== false) return 'cartao';
    if ($indicator === '1' || in_array($type, ['05','15'], true) || strpos($desc, 'prazo') !== false || strpos($desc, 'credi') !== false || strpos($desc, 'boleto') !== false || strpos($desc, 'conta') !== false) return 'prazo';
    return 'outros';
  }
}

if (!function_exists('fiscal_doc_report_allocate_payments')) {
  function fiscal_doc_report_allocate_payments(array $groups, array $payments): array {
    if (!$groups) return [];
    $total = 0.0;
    foreach ($groups as $g) $total += max(0.0, (float)($g['total'] ?? 0));
    $keys = array_keys($groups);
    $out = [];
    foreach ($keys as $key) $out[$key] = ['dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0];
    if ($total <= 0) return $out;
    foreach (['dinheiro','pix','cheque','cartao','prazo','outros'] as $bucket) {
      $amount = round((float)($payments[$bucket] ?? 0), 2);
      $allocated = 0.0;
      foreach ($keys as $idx => $key) {
        if ($idx === count($keys) - 1) $part = round($amount - $allocated, 2);
        else {
          $part = round($amount * max(0.0, (float)$groups[$key]['total']) / $total, 2);
          $allocated += $part;
        }
        $out[$key][$bucket] = $part;
      }
    }
    return $out;
  }
}

if (!function_exists('fiscal_doc_report_add_totals')) {
  function fiscal_doc_report_add_totals(array &$target, array $source): void {
    foreach (['total','dinheiro','pix','cheque','cartao','prazo','outros','icms_base','icms_amount','st_base','st_amount'] as $field) {
      $target[$field] = (float)($target[$field] ?? 0) + (float)($source[$field] ?? 0);
    }
  }
}

if (!function_exists('fiscal_doc_report_identity')) {
  function fiscal_doc_report_identity(PDO $pdo, int $companyId): array {
    $cfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
    $name = function_exists('fiscal_nfce_company_display_name') ? fiscal_nfce_company_display_name($pdo, $companyId, $cfg) : '';
    if (trim($name) === '') {
      try {
        $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
        $st->execute([$companyId]);
        $name = (string)($st->fetchColumn() ?: 'Empresa');
      } catch (Throwable $e) { $name = 'Empresa'; }
    }
    return [
      'name' => $name,
      'cnpj' => fiscal_digits((string)($cfg['company_cnpj'] ?? $cfg['cnpj'] ?? '')),
      'ie' => trim((string)($cfg['state_registration'] ?? $cfg['ie'] ?? '')),
      'city' => trim((string)($cfg['city'] ?? $cfg['municipio'] ?? '')),
      'uf' => trim((string)($cfg['uf'] ?? '')),
    ];
  }
}

if (!function_exists('fiscal_doc_report_build')) {
  /**
   * Reproduz os cálculos da tela Relatórios fiscais > Documentos fiscais.
   * Retorna os três conjuntos usados nos PDFs do fechamento contábil.
   */
  function fiscal_doc_report_build(PDO $pdo, int $companyId, string $ini, string $fim, string $model = '', string $series = ''): array {
    fiscal_nfce_ensure_schema($pdo);
    if (!in_array($model, ['', '55', '65'], true)) $model = '';
    $series = trim($series);

    $where = [
      'fd.company_id=?',
      "fd.direction='SAIDA'",
      "fd.model IN ('55','65')",
      "COALESCE(fd.issue_datetime,fd.created_at)>=?",
      "COALESCE(fd.issue_datetime,fd.created_at)<?",
      "UPPER(COALESCE(fd.status,'')) IN ('AUTORIZADA','CANCELADA')",
    ];
    $params = [$companyId, $ini . ' 00:00:00', date('Y-m-d 00:00:00', strtotime($fim . ' +1 day'))];
    if ($model !== '') { $where[] = 'fd.model=?'; $params[] = $model; }
    if ($series !== '') { $where[] = "COALESCE(fd.series,'')=?"; $params[] = $series; }

    // Seleciona somente os campos usados nos relatórios. fiscal_documents.xml_raw é LONGTEXT
    // e não deve ser carregado para montar PDFs contábeis.
    $sql = "SELECT fd.id,fd.issue_datetime,fd.created_at,fd.model,fd.series,fd.number,fd.status,fd.total_document,
      (SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_name,
      (SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS recipient_document
      FROM fiscal_documents fd WHERE " . implode(' AND ', $where) . " ORDER BY COALESCE(fd.issue_datetime,fd.created_at),fd.model,CAST(fd.series AS UNSIGNED),fd.number,fd.id";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $docs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $docIds = array_values(array_filter(array_map(static function(array $d): int { return (int)($d['id'] ?? 0); }, $docs)));
    $itemsByDoc = [];
    $taxesByItem = [];
    $paymentsByDoc = [];
    if ($docIds) {
      foreach (array_chunk($docIds, 400) as $chunk) {
        $ph = implode(',', array_fill(0, count($chunk), '?'));
        $st = $pdo->prepare("SELECT id,fiscal_document_id,cfop,total_value,freight_value,insurance_value,other_value,discount_value FROM fiscal_document_items WHERE company_id=? AND fiscal_document_id IN ($ph) ORDER BY fiscal_document_id,item_number,id");
        $st->execute(array_merge([$companyId], $chunk));
        foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $itemsByDoc[(int)$row['fiscal_document_id']][] = $row;

        try {
          $st = $pdo->prepare("SELECT id,fiscal_document_id,fiscal_document_item_id,tax_group,cst,csosn,rate,amount,base_calc,base_calc_st,extra_json FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_id IN ($ph) AND UPPER(tax_group) IN ('ICMS','ICMSST') ORDER BY fiscal_document_item_id,id");
          $st->execute(array_merge([$companyId], $chunk));
          foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) $taxesByItem[(int)$row['fiscal_document_item_id']][] = $row;
        } catch (Throwable $e) {}

        try {
          $st = $pdo->prepare("SELECT fiscal_document_id,payment_type,payment_description,payment_indicator,amount FROM fiscal_document_payments WHERE company_id=? AND fiscal_document_id IN ($ph) ORDER BY fiscal_document_id,id");
          $st->execute(array_merge([$companyId], $chunk));
          foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $row) {
            $docId = (int)$row['fiscal_document_id'];
            $bucket = fiscal_doc_report_payment_bucket($row);
            if (!isset($paymentsByDoc[$docId])) $paymentsByDoc[$docId] = ['dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0];
            $paymentsByDoc[$docId][$bucket] += (float)($row['amount'] ?? 0);
          }
        } catch (Throwable $e) {}
      }
    }

    $docTaxGroups = [];
    $docCfopSimple = [];
    foreach ($docs as $doc) {
      $docId = (int)$doc['id'];
      $groups = [];
      $simple = [];
      foreach (($itemsByDoc[$docId] ?? []) as $item) {
        $cfop = preg_replace('/\D+/', '', (string)($item['cfop'] ?? '')) ?: 'SEM CFOP';
        $lineTotal = max(0.0, round((float)($item['total_value'] ?? 0) + (float)($item['freight_value'] ?? 0) + (float)($item['insurance_value'] ?? 0) + (float)($item['other_value'] ?? 0) - (float)($item['discount_value'] ?? 0), 2));
        $icms = null; $stTax = null; $stRate = 0.0; $stAmount = 0.0; $stBase = 0.0;
        foreach (($taxesByItem[(int)$item['id']] ?? []) as $tx) {
          $tg = strtoupper((string)($tx['tax_group'] ?? ''));
          if ($tg === 'ICMS' && $icms === null) $icms = $tx;
          if ($tg === 'ICMSST' && $stTax === null) $stTax = $tx;
          $extra = json_decode((string)($tx['extra_json'] ?? ''), true);
          if (is_array($extra)) {
            $stRate = max($stRate, (float)($extra['st_rate'] ?? $extra['pICMSST'] ?? 0));
            $stAmount += (float)($extra['st_amount'] ?? $extra['vICMSST'] ?? 0);
          }
          $stBase += (float)($tx['base_calc_st'] ?? 0);
        }
        if ($stTax) {
          $stRate = max($stRate, (float)($stTax['rate'] ?? 0));
          if ($stAmount == 0.0) $stAmount = (float)($stTax['amount'] ?? 0);
          if ($stBase == 0.0) $stBase = (float)($stTax['base_calc'] ?? 0);
        }
        $cst = trim((string)($icms['cst'] ?? ''));
        $csosn = trim((string)($icms['csosn'] ?? ''));
        $icmsRate = (float)($icms['rate'] ?? 0);
        $groupKey = implode('|', [$cfop,$cst,$csosn,number_format($icmsRate,4,'.',''),number_format($stRate,4,'.','')]);
        if (!isset($groups[$groupKey])) $groups[$groupKey] = [
          'cfop'=>$cfop,'cst'=>$cst,'csosn'=>$csosn,'icms_rate'=>$icmsRate,'st_rate'=>$stRate,
          'total'=>0.0,'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0,
        ];
        $groups[$groupKey]['total'] += $lineTotal;
        $groups[$groupKey]['icms_base'] += (float)($icms['base_calc'] ?? 0);
        $groups[$groupKey]['icms_amount'] += (float)($icms['amount'] ?? 0);
        $groups[$groupKey]['st_base'] += $stBase;
        $groups[$groupKey]['st_amount'] += $stAmount;
        if (!isset($simple[$cfop])) $simple[$cfop] = 0.0;
        $simple[$cfop] += $lineTotal;
      }
      if (!$groups && (float)($doc['total_document'] ?? 0) > 0) {
        $groups['SEM CFOP||||'] = ['cfop'=>'SEM CFOP','cst'=>'','csosn'=>'','icms_rate'=>0.0,'st_rate'=>0.0,'total'=>(float)$doc['total_document'],'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0];
        $simple['SEM CFOP'] = (float)$doc['total_document'];
      }
      $allocation = fiscal_doc_report_allocate_payments($groups, $paymentsByDoc[$docId] ?? []);
      foreach ($groups as $key => &$g) foreach (($allocation[$key] ?? []) as $bucket => $value) $g[$bucket] = $value;
      unset($g);
      $docTaxGroups[$docId] = $groups;
      $docCfopSimple[$docId] = $simple;
    }

    $dayRows = [];
    $summaryRows = [];
    $generalTotals = ['total'=>0.0,'dinheiro'=>0.0,'pix'=>0.0,'cheque'=>0.0,'cartao'=>0.0,'prazo'=>0.0,'outros'=>0.0,'icms_base'=>0.0,'icms_amount'=>0.0,'st_base'=>0.0,'st_amount'=>0.0];
    foreach ($docs as $doc) {
      if (strtoupper((string)($doc['status'] ?? '')) !== 'AUTORIZADA') continue;
      $docId = (int)$doc['id'];
      $dateKey = date('Y-m-d', strtotime((string)($doc['issue_datetime'] ?? $doc['created_at'] ?? 'now')));
      $number = (int)($doc['number'] ?? 0);
      foreach (($docTaxGroups[$docId] ?? []) as $key => $g) {
        $rowKey = (string)$doc['model'] . '|' . (string)$doc['series'] . '|' . $key;
        if (!isset($dayRows[$dateKey][$rowKey])) $dayRows[$dateKey][$rowKey] = $g + ['model'=>(string)$doc['model'],'series'=>(string)$doc['series'],'number_min'=>$number,'number_max'=>$number];
        else {
          $dayRows[$dateKey][$rowKey]['number_min'] = min((int)$dayRows[$dateKey][$rowKey]['number_min'], $number);
          $dayRows[$dateKey][$rowKey]['number_max'] = max((int)$dayRows[$dateKey][$rowKey]['number_max'], $number);
          fiscal_doc_report_add_totals($dayRows[$dateKey][$rowKey], $g);
        }
        if (!isset($summaryRows[$rowKey])) $summaryRows[$rowKey] = $g + ['model'=>(string)$doc['model'],'series'=>(string)$doc['series']];
        else fiscal_doc_report_add_totals($summaryRows[$rowKey], $g);
        fiscal_doc_report_add_totals($generalTotals, $g);
      }
    }
    ksort($dayRows);
    ksort($summaryRows, SORT_NATURAL | SORT_FLAG_CASE);

    $cfopGeneral = [];
    $totalDocuments = 0.0;
    $totalCanceledDocuments = 0.0;
    $countAuthorizedDocuments = 0;
    $countCanceledDocuments = 0;
    foreach ($docs as $doc) {
      $docStatus = strtoupper((string)($doc['status'] ?? ''));
      if ($docStatus === 'CANCELADA') { $totalCanceledDocuments += (float)($doc['total_document'] ?? 0); $countCanceledDocuments++; }
      else { $totalDocuments += (float)($doc['total_document'] ?? 0); $countAuthorizedDocuments++; }
      if ($docStatus !== 'AUTORIZADA') continue;
      foreach (($docCfopSimple[(int)$doc['id']] ?? []) as $cfop => $value) {
        if (!isset($cfopGeneral[$cfop])) $cfopGeneral[$cfop] = 0.0;
        $cfopGeneral[$cfop] += (float)$value;
      }
    }
    ksort($cfopGeneral, SORT_NATURAL);

    return [
      'identity' => fiscal_doc_report_identity($pdo, $companyId),
      'ini' => $ini,
      'fim' => $fim,
      'model' => $model,
      'series' => $series,
      'documents' => $docs,
      'doc_cfop' => $docCfopSimple,
      'day_rows' => $dayRows,
      'summary_rows' => array_values($summaryRows),
      'cfop_general' => $cfopGeneral,
      'totals' => [
        'documents' => $totalDocuments,
        'cancelled' => $totalCanceledDocuments,
        'authorized_count' => $countAuthorizedDocuments,
        'cancelled_count' => $countCanceledDocuments,
      ],
      'payment_totals' => $generalTotals,
    ];
  }
}
