<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

/**
 * Nexa Próspero - Conciliação Bancária OFX v1
 *
 * Este arquivo não executa DDL. A estrutura deve ser aplicada pelo patch SQL
 * db/20260825_conciliacao_bancaria_ofx_v1.sql.
 */

require_once __DIR__ . '/finance_module.php';

if (!function_exists('bank_recon_schema_ok')) {
  function bank_recon_schema_ok(PDO $pdo): bool {
    return finance_table_exists($pdo, 'finance_bank_reconciliation_imports')
      && finance_table_exists($pdo, 'finance_bank_reconciliation_items');
  }
}

if (!function_exists('bank_recon_text')) {
  function bank_recon_text(string $value): string {
    $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
    return function_exists('mb_substr') ? mb_substr($value, 0, 500, 'UTF-8') : substr($value, 0, 500);
  }
}

if (!function_exists('bank_recon_normalize_search')) {
  function bank_recon_normalize_search(string $value): string {
    $value = trim(function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value));
    if ($value === '') return '';
    if (function_exists('iconv')) {
      $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
      if (is_string($ascii) && $ascii !== '') $value = $ascii;
    }
    $value = preg_replace('/[^a-z0-9]+/i', ' ', $value) ?? $value;
    return trim(preg_replace('/\s+/', ' ', $value) ?? $value);
  }
}

if (!function_exists('bank_recon_ofx_field')) {
  function bank_recon_ofx_field(string $block, string $tag): string {
    $tag = preg_quote($tag, '/');
    if (preg_match('/<' . $tag . '\b[^>]*>\s*(.*?)\s*<\/' . $tag . '>/is', $block, $m)) {
      return trim(strip_tags((string)$m[1]));
    }
    if (preg_match('/<' . $tag . '\b[^>]*>\s*([^<\r\n]*)/is', $block, $m)) {
      return trim((string)$m[1]);
    }
    return '';
  }
}

if (!function_exists('bank_recon_convert_ofx_encoding')) {
  function bank_recon_convert_ofx_encoding(string $raw): string {
    $head = substr($raw, 0, 1200);
    $encoding = '';
    if (preg_match('/(?:^|[\r\n])ENCODING\s*:\s*([^\r\n]+)/i', $head, $m)) {
      $encoding = strtoupper(trim((string)$m[1]));
    }
    $charset = '';
    if (preg_match('/(?:^|[\r\n])CHARSET\s*:\s*([^\r\n]+)/i', $head, $m)) {
      $charset = strtoupper(trim((string)$m[1]));
    }

    $source = '';
    if (str_contains($encoding, '1252') || str_contains($charset, '1252')) $source = 'Windows-1252';
    elseif (str_contains($encoding, '8859') || str_contains($charset, '8859')) $source = 'ISO-8859-1';
    elseif (str_contains($encoding, 'USASCII') || str_contains($encoding, 'ASCII')) $source = 'Windows-1252';

    if ($source !== '' && function_exists('mb_convert_encoding')) {
      return mb_convert_encoding($raw, 'UTF-8', $source);
    }
    if (function_exists('mb_check_encoding') && !mb_check_encoding($raw, 'UTF-8') && function_exists('mb_convert_encoding')) {
      return mb_convert_encoding($raw, 'UTF-8', 'Windows-1252');
    }
    return $raw;
  }
}

if (!function_exists('bank_recon_ofx_date')) {
  function bank_recon_ofx_date(string $value): ?string {
    if (!preg_match('/(\d{4})(\d{2})(\d{2})/', $value, $m)) return null;
    $date = $m[1] . '-' . $m[2] . '-' . $m[3];
    $dt = DateTime::createFromFormat('Y-m-d', $date);
    return $dt && $dt->format('Y-m-d') === $date ? $date : null;
  }
}

if (!function_exists('bank_recon_ofx_amount')) {
  function bank_recon_ofx_amount(string $value): float {
    $value = trim(str_replace(["\xc2\xa0", ' '], '', $value));
    if ($value === '') return 0.0;
    if (str_contains($value, ',') && !str_contains($value, '.')) $value = str_replace(',', '.', $value);
    $value = preg_replace('/[^0-9+\-.]/', '', $value) ?? '';
    return round((float)$value, 2);
  }
}

if (!function_exists('bank_recon_parse_ofx')) {
  /** @return array{bank_code:string,agency:string,account_number:string,account_type:string,date_start:?string,date_end:?string,transactions:array<int,array<string,mixed>>} */
  function bank_recon_parse_ofx(string $raw): array {
    if (trim($raw) === '') throw new RuntimeException('O arquivo OFX está vazio.');
    $raw = bank_recon_convert_ofx_encoding($raw);
    if (stripos($raw, '<OFX') === false) throw new RuntimeException('O arquivo enviado não possui uma estrutura OFX válida.');

    $bankBlock = '';
    if (preg_match('/<BANKACCTFROM\b[^>]*>(.*?)<\/BANKACCTFROM>/is', $raw, $m)) $bankBlock = (string)$m[1];
    elseif (preg_match('/<CCACCTFROM\b[^>]*>(.*?)<\/CCACCTFROM>/is', $raw, $m)) $bankBlock = (string)$m[1];

    $bankCode = bank_recon_ofx_field($bankBlock, 'BANKID');
    $agency = bank_recon_ofx_field($bankBlock, 'BRANCHID');
    $account = bank_recon_ofx_field($bankBlock, 'ACCTID');
    $accountType = bank_recon_ofx_field($bankBlock, 'ACCTTYPE');

    $dateStart = bank_recon_ofx_date(bank_recon_ofx_field($raw, 'DTSTART'));
    $dateEnd = bank_recon_ofx_date(bank_recon_ofx_field($raw, 'DTEND'));

    preg_match_all('/<STMTTRN\b[^>]*>(.*?)<\/STMTTRN>/is', $raw, $matches);
    $transactions = [];
    $occurrences = [];
    foreach (($matches[1] ?? []) as $block) {
      $posted = bank_recon_ofx_date(bank_recon_ofx_field((string)$block, 'DTPOSTED'));
      $amount = bank_recon_ofx_amount(bank_recon_ofx_field((string)$block, 'TRNAMT'));
      if (!$posted || abs($amount) < 0.005) continue;

      $fitid = bank_recon_text(bank_recon_ofx_field((string)$block, 'FITID'));
      $name = bank_recon_text(bank_recon_ofx_field((string)$block, 'NAME'));
      $memo = bank_recon_text(bank_recon_ofx_field((string)$block, 'MEMO'));
      $checknum = bank_recon_text(bank_recon_ofx_field((string)$block, 'CHECKNUM'));
      $refnum = bank_recon_text(bank_recon_ofx_field((string)$block, 'REFNUM'));
      $trnType = strtoupper(bank_recon_text(bank_recon_ofx_field((string)$block, 'TRNTYPE')));

      $canonicalBase = implode('|', [
        $bankCode, $agency, $account, $posted, number_format($amount, 2, '.', ''), $name, $memo, $checknum, $refnum, $trnType,
      ]);
      if ($fitid !== '') {
        $transactionKey = hash('sha256', 'FITID|' . $bankCode . '|' . $agency . '|' . $account . '|' . $fitid);
      } else {
        $occurrence = ($occurrences[$canonicalBase] ?? 0) + 1;
        $occurrences[$canonicalBase] = $occurrence;
        $transactionKey = hash('sha256', 'NOFITID|' . $canonicalBase . '|OCC:' . $occurrence);
      }

      $transactions[] = [
        'posted_date' => $posted,
        'amount' => $amount,
        'direction' => $amount >= 0 ? 'CREDITO' : 'DEBITO',
        'trn_type' => $trnType,
        'fitid' => $fitid,
        'name' => $name,
        'memo' => $memo,
        'checknum' => $checknum,
        'refnum' => $refnum,
        'transaction_key' => $transactionKey,
      ];
    }

    if (!$transactions) throw new RuntimeException('Nenhuma transação bancária foi encontrada no arquivo OFX.');
    if (!$dateStart) $dateStart = min(array_column($transactions, 'posted_date')) ?: null;
    if (!$dateEnd) $dateEnd = max(array_column($transactions, 'posted_date')) ?: null;

    return [
      'bank_code' => $bankCode,
      'agency' => $agency,
      'account_number' => $account,
      'account_type' => $accountType,
      'date_start' => $dateStart,
      'date_end' => $dateEnd,
      'transactions' => $transactions,
    ];
  }
}

if (!function_exists('bank_recon_get_item')) {
  function bank_recon_get_item(PDO $pdo, int $companyId, int $itemId): ?array {
    return finance_query_one($pdo, 'SELECT i.*, bi.file_name, b.name AS bank_account_name FROM finance_bank_reconciliation_items i LEFT JOIN finance_bank_reconciliation_imports bi ON bi.company_id=i.company_id AND bi.id=i.import_id LEFT JOIN finance_bank_accounts b ON b.company_id=i.company_id AND b.id=i.bank_account_id WHERE i.company_id=? AND i.id=?', [$companyId, $itemId]);
  }
}

if (!function_exists('bank_recon_title_candidates')) {
  function bank_recon_title_candidates(PDO $pdo, int $companyId, array $item, int $limit = 8, string $search = ''): array {
    $direction = (string)($item['direction'] ?? '');
    $originType = $direction === 'CREDITO' ? 'RECEBER' : 'PAGAR';
    $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';
    $amount = abs((float)($item['amount'] ?? 0));
    $date = (string)($item['posted_date'] ?? date('Y-m-d'));
    $tolerance = max(5.00, round($amount * 0.05, 2));
    $limit = max(1, min(30, $limit));
    $search = trim($search);

    $where = ["t.company_id=?", "t.status IN ('ABERTO','PARCIAL')", 'COALESCE(t.open_amount,0) > 0.009'];
    $params = [$date, $amount, $companyId];
    if ($search === '') {
      $where[] = 'ABS(COALESCE(t.open_amount,0) - ?) <= ?';
      $where[] = 'COALESCE(t.due_date,t.issue_date) BETWEEN DATE_SUB(?, INTERVAL 45 DAY) AND DATE_ADD(?, INTERVAL 45 DAY)';
      array_push($params, $amount, $tolerance, $date, $date);
    } else {
      $where[] = '(p.name LIKE ? OR t.description LIKE ? OR t.document_number LIKE ?)';
      $like = nexa_search_like($search);
      array_push($params, $like, $like, $like);
    }

    $rows = finance_query_all($pdo, "SELECT t.*, p.name AS person_name, ca.code AS chart_code, ca.name AS chart_name, ABS(DATEDIFF(COALESCE(t.due_date,t.issue_date), ?)) AS date_distance, ABS(COALESCE(t.open_amount,0) - ?) AS value_distance FROM {$table} t LEFT JOIN finance_people p ON p.company_id=t.company_id AND p.id=t.person_id LEFT JOIN finance_chart_accounts ca ON ca.company_id=t.company_id AND ca.id=t.chart_account_id WHERE " . implode(' AND ', $where) . " ORDER BY value_distance ASC, date_distance ASC, t.id DESC LIMIT {$limit}", $params);

    $needle = bank_recon_normalize_search(trim((string)($item['name'] ?? '') . ' ' . (string)($item['memo'] ?? '')));
    foreach ($rows as &$row) {
      $valueDistance = abs((float)($row['open_amount'] ?? 0) - $amount);
      $dateDistance = (int)($row['date_distance'] ?? 999);
      $score = 0;
      if ($valueDistance <= 0.01) $score += 70;
      elseif ($valueDistance <= 1.00) $score += 58;
      elseif ($valueDistance <= 5.00) $score += 45;
      elseif ($amount > 0 && ($valueDistance / $amount) <= 0.05) $score += 35;
      else $score += 15;

      if ($dateDistance <= 1) $score += 20;
      elseif ($dateDistance <= 3) $score += 16;
      elseif ($dateDistance <= 7) $score += 12;
      elseif ($dateDistance <= 15) $score += 8;
      elseif ($dateDistance <= 45) $score += 3;

      $person = bank_recon_normalize_search((string)($row['person_name'] ?? ''));
      if ($needle !== '' && $person !== '') {
        $parts = array_values(array_filter(explode(' ', $person), static fn($p) => strlen($p) >= 4));
        foreach ($parts as $part) {
          if (strpos($needle, $part) !== false) { $score += 10; break; }
        }
      }
      $row['match_score'] = min(100, $score);
      $row['origin_type'] = $originType;
    }
    unset($row);
    usort($rows, static function(array $a, array $b): int {
      $cmp = ((int)$b['match_score']) <=> ((int)$a['match_score']);
      if ($cmp !== 0) return $cmp;
      return ((float)$a['value_distance']) <=> ((float)$b['value_distance']);
    });
    return $rows;
  }
}

if (!function_exists('bank_recon_refresh_suggestion')) {
  function bank_recon_refresh_suggestion(PDO $pdo, int $companyId, int $itemId): void {
    $item = bank_recon_get_item($pdo, $companyId, $itemId);
    if (!$item || (string)($item['status'] ?? '') !== 'PENDENTE') return;
    $candidates = bank_recon_title_candidates($pdo, $companyId, $item, 3);
    $best = $candidates[0] ?? null;
    if ($best && (int)($best['match_score'] ?? 0) >= 70) {
      $pdo->prepare('UPDATE finance_bank_reconciliation_items SET suggested_origin_type=?, suggested_origin_id=?, suggested_score=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([
        $best['origin_type'], (int)$best['id'], (int)$best['match_score'], $companyId, $itemId,
      ]);
    } else {
      $pdo->prepare('UPDATE finance_bank_reconciliation_items SET suggested_origin_type=NULL, suggested_origin_id=NULL, suggested_score=NULL, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $itemId]);
    }
  }
}

if (!function_exists('bank_recon_import_ofx')) {
  function bank_recon_import_ofx(PDO $pdo, int $companyId, int $bankAccountId, array $file, ?int $userId = null): array {
    if (!bank_recon_schema_ok($pdo)) throw new RuntimeException('Estrutura da conciliação bancária ainda não foi instalada. Aplique o patch SQL da versão.');
    if ($bankAccountId <= 0) throw new RuntimeException('Selecione a conta bancária que receberá o extrato.');
    if (function_exists('finance_assert_cash_account_effective')) finance_assert_cash_account_effective($pdo, $companyId, $bankAccountId, 'Este usuário não possui permissão para usar esta conta bancária.');
    elseif (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo, $companyId, $bankAccountId, 'Este usuário não possui permissão para usar esta conta bancária.');
    $bank = finance_query_one($pdo, 'SELECT id,name,active FROM finance_bank_accounts WHERE company_id=? AND id=?', [$companyId, $bankAccountId]);
    if (!$bank || empty($bank['active'])) throw new RuntimeException('A conta bancária selecionada não está disponível.');

    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) throw new RuntimeException('Selecione um arquivo OFX válido.');
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_file($tmp)) throw new RuntimeException('O arquivo OFX temporário não foi encontrado.');
    if ($size <= 0 || $size > 8 * 1024 * 1024) throw new RuntimeException('O arquivo OFX deve possuir no máximo 8 MB.');

    $raw = (string)file_get_contents($tmp);
    $fileHash = hash('sha256', $raw);
    $already = finance_query_one($pdo, 'SELECT id,file_name,created_at FROM finance_bank_reconciliation_imports WHERE company_id=? AND bank_account_id=? AND file_hash=? LIMIT 1', [$companyId, $bankAccountId, $fileHash]);
    if ($already) throw new RuntimeException('Este mesmo arquivo OFX já foi importado para esta conta bancária.');

    $parsed = bank_recon_parse_ofx($raw);
    $fileName = bank_recon_text((string)($file['name'] ?? 'extrato.ofx'));
    $transactions = $parsed['transactions'];
    $totalCredits = 0.0; $totalDebits = 0.0;
    foreach ($transactions as $tr) {
      if ((float)$tr['amount'] >= 0) $totalCredits += (float)$tr['amount'];
      else $totalDebits += abs((float)$tr['amount']);
    }

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('INSERT INTO finance_bank_reconciliation_imports (company_id,bank_account_id,file_name,file_hash,bank_code,agency,account_number,account_type,date_start,date_end,transaction_count,total_credits,total_debits,status,imported_by_user_id) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $st->execute([$companyId,$bankAccountId,$fileName,$fileHash,$parsed['bank_code'] ?: null,$parsed['agency'] ?: null,$parsed['account_number'] ?: null,$parsed['account_type'] ?: null,$parsed['date_start'],$parsed['date_end'],count($transactions),round($totalCredits,2),round($totalDebits,2),'IMPORTADO',$userId]);
      $importId = (int)$pdo->lastInsertId();

      $ins = $pdo->prepare('INSERT IGNORE INTO finance_bank_reconciliation_items (company_id,import_id,bank_account_id,transaction_key,fitid,trn_type,posted_date,amount,direction,name,memo,checknum,refnum,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'PENDENTE\')');
      $inserted = 0; $duplicates = 0; $itemIds = [];
      foreach ($transactions as $tr) {
        $ins->execute([$companyId,$importId,$bankAccountId,$tr['transaction_key'],$tr['fitid'] ?: null,$tr['trn_type'] ?: null,$tr['posted_date'],$tr['amount'],$tr['direction'],$tr['name'] ?: null,$tr['memo'] ?: null,$tr['checknum'] ?: null,$tr['refnum'] ?: null]);
        if ($ins->rowCount() > 0) { $inserted++; $itemIds[] = (int)$pdo->lastInsertId(); }
        else $duplicates++;
      }
      $pdo->prepare('UPDATE finance_bank_reconciliation_imports SET imported_count=?, duplicate_count=?, updated_at=NOW() WHERE company_id=? AND id=?')->execute([$inserted,$duplicates,$companyId,$importId]);
      $pdo->commit();

      foreach ($itemIds as $itemId) {
        try { bank_recon_refresh_suggestion($pdo, $companyId, $itemId); } catch (Throwable $e) {}
      }
      return ['import_id'=>$importId,'inserted'=>$inserted,'duplicates'=>$duplicates,'total'=>count($transactions)];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('bank_recon_assert_pending')) {
  function bank_recon_assert_pending(PDO $pdo, int $companyId, int $itemId): array {
    $item = bank_recon_get_item($pdo, $companyId, $itemId);
    if (!$item) throw new RuntimeException('Movimento OFX não encontrado.');
    if ((string)($item['status'] ?? '') !== 'PENDENTE') throw new RuntimeException('Este movimento OFX não está pendente de conciliação.');
    return $item;
  }
}

if (!function_exists('bank_recon_mark_reconciled')) {
  function bank_recon_mark_reconciled(PDO $pdo, int $companyId, int $itemId, string $mode, string $originType, ?int $originId, ?int $settlementId, ?int $movementId, ?int $userId): void {
    $st = $pdo->prepare("UPDATE finance_bank_reconciliation_items SET status='CONCILIADO', reconciliation_mode=?, matched_origin_type=?, matched_origin_id=?, settlement_id=?, movement_id=?, reconciled_at=NOW(), reconciled_by_user_id=?, updated_at=NOW() WHERE company_id=? AND id=? AND status='PENDENTE'");
    $st->execute([$mode,$originType,$originId,$settlementId,$movementId,$userId,$companyId,$itemId]);
    if ($st->rowCount() <= 0) throw new RuntimeException('Não foi possível concluir a conciliação deste movimento.');
  }
}

if (!function_exists('bank_recon_reconcile_title')) {
  function bank_recon_reconcile_title(PDO $pdo, int $companyId, int $itemId, string $originType, int $originId, array $data, ?int $userId = null): void {
    $item = bank_recon_assert_pending($pdo, $companyId, $itemId);
    $expected = (string)$item['direction'] === 'CREDITO' ? 'RECEBER' : 'PAGAR';
    $originType = strtoupper($originType);
    if ($originType !== $expected) throw new RuntimeException('O tipo do título não corresponde ao crédito/débito do extrato.');
    if ($originId <= 0) throw new RuntimeException('Selecione o título que será baixado.');
    $perm = $originType === 'RECEBER' ? 'financeiro_baixar_receber' : 'financeiro_baixar_pagar';
    if (function_exists('finance_effective_permission_allowed')) {
      if (!finance_effective_permission_allowed($pdo, $perm)) throw new RuntimeException('Seu usuário não possui permissão para baixar este título.');
    } elseif (function_exists('access_has_permission') && !access_has_permission($pdo, $companyId, $perm)) {
      throw new RuntimeException('Seu usuário não possui permissão para baixar este título.');
    }
    if (function_exists('finance_multi_company_assert_settlement_safe')) {
      finance_multi_company_assert_settlement_safe($pdo, $companyId, $originType, [$originId]);
    }

    $principal = finance_money_in($data['amount'] ?? 0);
    $fine = finance_money_in($data['fine_amount'] ?? 0);
    $interest = finance_money_in($data['interest_amount'] ?? 0);
    $discount = finance_money_in($data['discount_amount'] ?? 0);
    $net = round($principal + $fine + $interest - $discount, 2);
    $bankAmount = round(abs((float)$item['amount']), 2);
    if (abs($net - $bankAmount) > 0.01) throw new RuntimeException('O total da baixa deve ser igual ao valor do movimento bancário: R$ ' . finance_money_br($bankAmount) . '.');

    $settleData = [
      'settlement_date' => (string)$item['posted_date'],
      'payment_method' => trim((string)($data['payment_method'] ?? 'CONCILIAÇÃO OFX')) ?: 'CONCILIAÇÃO OFX',
      'bank_account_id' => (int)$item['bank_account_id'],
      'amount' => number_format($principal, 2, ',', '.'),
      'fine_amount' => number_format($fine, 2, ',', '.'),
      'interest_amount' => number_format($interest, 2, ',', '.'),
      'discount_amount' => number_format($discount, 2, ',', '.'),
      'notes' => 'Conciliação OFX #' . $itemId . ' | ' . trim((string)($item['memo'] ?? $item['name'] ?? '')),
    ];
    finance_settle_title($pdo, $companyId, $originType, $originId, $settleData);
    $settlement = finance_query_one($pdo, "SELECT id,movement_id FROM finance_settlements WHERE company_id=? AND origin_type=? AND origin_id=? AND bank_account_id=? AND settlement_date=? AND COALESCE(status,'ATIVO')='ATIVO' ORDER BY id DESC LIMIT 1", [$companyId,$originType,$originId,(int)$item['bank_account_id'],(string)$item['posted_date']]);
    if (!$settlement) throw new RuntimeException('A baixa foi realizada, mas não foi possível identificar seu vínculo para a conciliação.');
    bank_recon_mark_reconciled($pdo,$companyId,$itemId,'TITULO_EXISTENTE',$originType,$originId,(int)$settlement['id'],(int)($settlement['movement_id'] ?? 0) ?: null,$userId);
  }
}

if (!function_exists('bank_recon_create_title_and_settle')) {
  function bank_recon_create_title_and_settle(PDO $pdo, int $companyId, int $itemId, array $data, ?int $userId = null): int {
    $item = bank_recon_assert_pending($pdo, $companyId, $itemId);
    $originType = (string)$item['direction'] === 'CREDITO' ? 'RECEBER' : 'PAGAR';
    $settlePerm = $originType === 'RECEBER' ? 'financeiro_baixar_receber' : 'financeiro_baixar_pagar';
    $titlePerm = $originType === 'RECEBER' ? 'financeiro_receber' : 'financeiro_pagar';
    $crossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
    if ($crossContext) {
      if (!function_exists('finance_multi_company_reconciliation_create_title_enabled') || !finance_multi_company_reconciliation_create_title_enabled($pdo)) {
        throw new RuntimeException('V11.18: a criação de título pela Conciliação não está habilitada para este contexto Multiempresa.');
      }
      if (!function_exists('finance_effective_permission_allowed')
          || !finance_effective_permission_allowed($pdo, $titlePerm)
          || !finance_effective_permission_allowed($pdo, $settlePerm)) {
        throw new RuntimeException('Seu usuário não possui as permissões necessárias para criar e baixar este título na empresa selecionada pelo F11.');
      }
    } elseif (function_exists('access_has_permission') && !access_has_permission($pdo, $companyId, $settlePerm)) {
      throw new RuntimeException('Seu usuário não possui permissão para criar e baixar este título.');
    }
    $personId = (int)($data['person_id'] ?? 0);
    $chartId = (int)($data['chart_account_id'] ?? 0);
    $costId = (int)($data['cost_center_id'] ?? 0) ?: null;
    if ($personId <= 0) throw new RuntimeException('Selecione a pessoa do lançamento.');
    if ($crossContext && function_exists('finance_validate_person_role')) {
      $requiredRole = $originType === 'RECEBER' ? 'customer' : 'supplier';
      if (!finance_validate_person_role($pdo, $companyId, $personId, $requiredRole)) {
        throw new RuntimeException($originType === 'RECEBER'
          ? 'O cliente selecionado não pertence à empresa ativa no F11 ou não está habilitado como cliente.'
          : 'O fornecedor selecionado não pertence à empresa ativa no F11 ou não está habilitado como fornecedor.');
      }
    }
    if (!finance_validate_analytic_chart($pdo,$companyId,$chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
    if (!finance_validate_analytic_cost($pdo,$companyId,$costId)) throw new RuntimeException('Selecione um centro de custo analítico.');

    $amount = round(abs((float)$item['amount']),2);
    $description = trim((string)($data['description'] ?? '')) ?: trim((string)($item['memo'] ?? $item['name'] ?? 'Movimento importado do OFX'));
    $document = trim((string)($data['document_number'] ?? '')) ?: trim((string)($item['fitid'] ?? $item['refnum'] ?? ''));
    $date = (string)$item['posted_date'];
    $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';
    $notes = 'Título criado automaticamente pela Conciliação Bancária OFX #' . $itemId;

    $st = $pdo->prepare("INSERT INTO {$table} (company_id,person_id,chart_account_id,cost_center_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,fine_amount,interest_amount,discount_amount,paid_amount,open_amount,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
    $st->execute([$companyId,$personId,$chartId,$costId,$description,$document ?: null,'01/01',$date,$date,'CONCILIAÇÃO OFX',$amount,0,0,0,0,$amount,'ABERTO',$notes]);
    $titleId = (int)$pdo->lastInsertId();
    try {
      finance_settle_title($pdo,$companyId,$originType,$titleId,[
        'settlement_date'=>$date,
        'payment_method'=>'CONCILIAÇÃO OFX',
        'bank_account_id'=>(int)$item['bank_account_id'],
        'amount'=>number_format($amount,2,',','.'),
        'fine_amount'=>'0,00','interest_amount'=>'0,00','discount_amount'=>'0,00',
        'notes'=>'Baixa automática pela Conciliação Bancária OFX #' . $itemId,
      ]);
    } catch (Throwable $e) {
      try { $pdo->prepare("DELETE FROM {$table} WHERE company_id=? AND id=? AND paid_amount=0")->execute([$companyId,$titleId]); } catch (Throwable $cleanup) {}
      throw $e;
    }
    $settlement = finance_query_one($pdo,"SELECT id,movement_id FROM finance_settlements WHERE company_id=? AND origin_type=? AND origin_id=? AND COALESCE(status,'ATIVO')='ATIVO' ORDER BY id DESC LIMIT 1",[$companyId,$originType,$titleId]);
    bank_recon_mark_reconciled($pdo,$companyId,$itemId,'TITULO_CRIADO',$originType,$titleId,(int)($settlement['id'] ?? 0) ?: null,(int)($settlement['movement_id'] ?? 0) ?: null,$userId);
    return $titleId;
  }
}

if (!function_exists('bank_recon_create_movement')) {
  function bank_recon_create_movement(PDO $pdo, int $companyId, int $itemId, array $data, ?int $userId = null): int {
    $item = bank_recon_assert_pending($pdo,$companyId,$itemId);
    $crossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
    if ($crossContext) {
      if (!function_exists('finance_multi_company_reconciliation_create_movement_enabled') || !finance_multi_company_reconciliation_create_movement_enabled($pdo)) {
        throw new RuntimeException('V11.19: o movimento financeiro direto pela Conciliação não está habilitado para este contexto Multiempresa.');
      }
      if (!function_exists('finance_effective_permission_allowed')
          || !finance_effective_permission_allowed($pdo, 'financeiro_movimentos')
          || !finance_effective_permission_allowed($pdo, 'financeiro_movimento_manual')) {
        throw new RuntimeException('Seu usuário não possui as permissões necessárias para lançar movimento financeiro na empresa selecionada pelo F11.');
      }
    }

    $chartId = (int)($data['chart_account_id'] ?? 0);
    $costId = (int)($data['cost_center_id'] ?? 0) ?: null;
    $personId = (int)($data['person_id'] ?? 0) ?: null;
    if (!finance_validate_analytic_chart($pdo,$companyId,$chartId)) throw new RuntimeException('Selecione uma conta analítica no plano de contas.');
    if (!finance_validate_analytic_cost($pdo,$companyId,$costId)) throw new RuntimeException('Selecione um centro de custo analítico.');
    $direction = strtoupper(trim((string)($item['direction'] ?? '')));
    if (!in_array($direction, ['CREDITO','DEBITO'], true)) throw new RuntimeException('Direção bancária inválida para lançamento financeiro.');
    if ($crossContext) {
      if ($personId && function_exists('finance_validate_person_role') && !finance_validate_person_role($pdo,$companyId,$personId,'')) {
        throw new RuntimeException('A pessoa selecionada não pertence à empresa ativa ou está inativa.');
      }
      $chart = finance_query_one($pdo, 'SELECT movement_type FROM finance_chart_accounts WHERE company_id=? AND id=? AND active=1 AND is_analytic=1 LIMIT 1', [$companyId,$chartId]);
      $movementType = strtoupper(trim((string)($chart['movement_type'] ?? 'AMBOS')));
      if ($movementType !== 'AMBOS' && $movementType !== $direction) {
        throw new RuntimeException('A conta do plano selecionada não aceita este tipo de movimento (' . $direction . ').');
      }
    }
    $bankId = (int)$item['bank_account_id'];
    if (function_exists('finance_assert_cash_account_effective')) finance_assert_cash_account_effective($pdo,$companyId,$bankId,'Este usuário não possui permissão para usar esta conta bancária.');
    elseif (function_exists('access_assert_cash_account')) access_assert_cash_account($pdo,$companyId,$bankId,'Este usuário não possui permissão para usar esta conta bancária.');

    $description = trim((string)($data['description'] ?? '')) ?: trim((string)($item['memo'] ?? $item['name'] ?? 'Movimento importado do OFX'));
    $document = trim((string)($data['document_number'] ?? '')) ?: trim((string)($item['fitid'] ?? $item['refnum'] ?? ''));
    $notes = 'Movimento criado pela Conciliação Bancária OFX #' . $itemId . ($crossContext ? ' | Multiempresa F11 V11.19' : '');

    $pdo->beginTransaction();
    try {
      $st=$pdo->prepare('INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes,status) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,\'ATIVO\')');
      $st->execute([$companyId,(string)$item['posted_date'],$direction,$personId,$chartId,$costId,$bankId,'OFX',$itemId,$description,$document ?: null,abs((float)$item['amount']),$notes]);
      $movementId=(int)$pdo->lastInsertId();
      bank_recon_mark_reconciled($pdo,$companyId,$itemId,'MOVIMENTO_DIRETO','MOVIMENTO',$movementId,null,$movementId,$userId);
      $pdo->commit();
      return $movementId;
    } catch(Throwable $e){ if($pdo->inTransaction())$pdo->rollBack(); throw $e; }
  }
}

if (!function_exists('bank_recon_set_ignored')) {
  function bank_recon_set_ignored(PDO $pdo, int $companyId, int $itemId, bool $ignored, ?int $userId = null): void {
    if ($companyId <= 0 || $itemId <= 0) throw new RuntimeException('Movimento OFX inválido.');
    $item = bank_recon_get_item($pdo, $companyId, $itemId);
    if (!$item) throw new RuntimeException('Movimento OFX não encontrado nesta empresa.');

    if ($ignored) {
      if ((string)($item['status'] ?? '') !== 'PENDENTE') throw new RuntimeException('Somente movimentos pendentes podem ser ignorados.');
      $st = $pdo->prepare("UPDATE finance_bank_reconciliation_items SET status='IGNORADO', reconciled_by_user_id=?, updated_at=NOW() WHERE company_id=? AND id=? AND status='PENDENTE'");
      $st->execute([$userId,$companyId,$itemId]);
      if ($st->rowCount() <= 0) throw new RuntimeException('Não foi possível ignorar o movimento. O status pode ter sido alterado por outro processo.');
    } else {
      if ((string)($item['status'] ?? '') !== 'IGNORADO') throw new RuntimeException('Somente movimentos ignorados podem ser reabertos.');
      $st = $pdo->prepare("UPDATE finance_bank_reconciliation_items SET status='PENDENTE', reconciled_by_user_id=NULL, updated_at=NOW() WHERE company_id=? AND id=? AND status='IGNORADO'");
      $st->execute([$companyId,$itemId]);
      if ($st->rowCount() <= 0) throw new RuntimeException('Não foi possível reabrir o movimento. O status pode ter sido alterado por outro processo.');
      try { bank_recon_refresh_suggestion($pdo,$companyId,$itemId); } catch(Throwable $e) {}
    }
  }
}
