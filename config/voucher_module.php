<?php
declare(strict_types=1);

require_once __DIR__ . '/finance_module.php';
require_once __DIR__ . '/gestao_module.php';

if (!function_exists('voucher_schema_ready')) {
  function voucher_schema_ready(PDO $pdo): bool {
    try {
      foreach (['finance_vouchers','finance_voucher_usages'] as $table) {
        $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
        $st->execute([$table]);
        if (!$st->fetchColumn()) return false;
      }
      return true;
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('voucher_assert_ready')) {
  function voucher_assert_ready(PDO $pdo): void {
    if (!voucher_schema_ready($pdo)) {
      throw new RuntimeException('O Controle de Vales ainda não está instalado. Aplique a atualização pendente em Configurações → Atualizador do Banco.');
    }
  }
}

if (!function_exists('voucher_is_payment')) {
  function voucher_is_payment(array $payment): bool {
    return strtoupper(trim((string)($payment['type'] ?? ''))) === 'VALE'
      || strtoupper(trim((string)($payment['code'] ?? ''))) === 'VALE';
  }
}

if (!function_exists('voucher_ensure_payment_method')) {
  function voucher_ensure_payment_method(PDO $pdo, int $companyId): void {
    if ($companyId <= 0 || !finance_table_exists($pdo, 'finance_payment_methods')) return;
    $st = $pdo->prepare("SELECT id FROM finance_payment_methods WHERE company_id=? AND (UPPER(type)='VALE' OR UPPER(code)='VALE') LIMIT 1");
    $st->execute([$companyId]);
    if ($st->fetchColumn()) return;
    $sql = "INSERT INTO finance_payment_methods
      (company_id,code,name,type,default_bank_account_id,default_chart_account_id,default_cost_center_id,generate_receivable,allow_installments,default_installments,first_due_days,keep_due_day,affects_cash,require_open_cash,allow_change,is_active,sort_order,notes)
      VALUES (?, 'VALE', 'Vale', 'VALE', NULL, NULL, NULL, 0, 0, 1, 0, 1, 0, 0, 0, 1, 60, 'Crédito do cliente gerado por devolução')";
    $pdo->prepare($sql)->execute([$companyId]);
    if (finance_column_exists($pdo, 'finance_payment_methods', 'nfce_payment_type')) {
      $pdo->prepare("UPDATE finance_payment_methods SET nfce_payment_type='21', nfce_payment_indicator='0' WHERE company_id=? AND UPPER(code)='VALE'")
        ->execute([$companyId]);
    }
  }
}

if (!function_exists('voucher_code')) {
  function voucher_code(int $id): string {
    return 'VAL-' . str_pad((string)$id, 8, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('voucher_issue')) {
  function voucher_issue(PDO $pdo, int $companyId, int $personId, float $amount, string $sourceType, ?int $sourceId, ?int $userId, string $documentNumber = '', string $notes = ''): int {
    voucher_assert_ready($pdo);
    $amount = round($amount, 2);
    if ($companyId <= 0 || $personId <= 0) throw new RuntimeException('Para gerar vale, selecione um cliente cadastrado.');
    if ($amount <= 0) throw new RuntimeException('O valor do vale deve ser maior que zero.');
    $sourceType = strtoupper(trim($sourceType)) ?: 'MANUAL';
    if ($sourceId) {
      $st = $pdo->prepare('SELECT id FROM finance_vouchers WHERE company_id=? AND source_type=? AND source_id=? LIMIT 1');
      $st->execute([$companyId,$sourceType,$sourceId]);
      $existing = (int)($st->fetchColumn() ?: 0);
      if ($existing > 0) return $existing;
    }
    $pdo->prepare("INSERT INTO finance_vouchers
      (company_id,code,person_id,source_type,source_id,source_document_number,issue_date,original_amount,used_amount,balance_amount,status,notes,created_by,created_at,updated_at)
      VALUES (?, '', ?, ?, ?, ?, CURDATE(), ?, 0, ?, 'ABERTO', ?, ?, NOW(), NOW())")
      ->execute([$companyId,$personId,$sourceType,$sourceId,$documentNumber !== '' ? $documentNumber : null,$amount,$amount,$notes !== '' ? $notes : null,$userId ?: null]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE finance_vouchers SET code=? WHERE company_id=? AND id=?')->execute([voucher_code($id),$companyId,$id]);
    return $id;
  }
}

if (!function_exists('voucher_available')) {
  function voucher_available(PDO $pdo, int $companyId, int $personId): array {
    voucher_assert_ready($pdo);
    if ($companyId <= 0 || $personId <= 0) return [];
    $st = $pdo->prepare("SELECT v.id,v.code,v.issue_date,v.original_amount,v.used_amount,v.balance_amount,v.status,v.source_type,v.source_id,v.source_document_number,v.notes
      FROM finance_vouchers v
      WHERE v.company_id=? AND v.person_id=? AND v.status IN ('ABERTO','PARCIAL') AND v.balance_amount>0.009
      ORDER BY v.issue_date ASC,v.id ASC");
    $st->execute([$companyId,$personId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('voucher_parse_allocations')) {
  function voucher_parse_allocations(array $post, string $lineKey, float $lineAmount): array {
    $idMatrix = is_array($post['voucher_id'] ?? null) ? $post['voucher_id'] : [];
    $amountMatrix = is_array($post['voucher_amount'] ?? null) ? $post['voucher_amount'] : [];
    $ids = isset($idMatrix[$lineKey]) && is_array($idMatrix[$lineKey]) ? $idMatrix[$lineKey] : [];
    $amounts = isset($amountMatrix[$lineKey]) && is_array($amountMatrix[$lineKey]) ? $amountMatrix[$lineKey] : [];
    $out = []; $sum = 0.0;
    foreach ($ids as $idx => $rawId) {
      $id = (int)$rawId;
      $amount = round(finance_money_in($amounts[$idx] ?? 0), 2);
      if ($id <= 0 || $amount <= 0) continue;
      $out[] = ['voucher_id'=>$id,'amount'=>$amount];
      $sum += $amount;
    }
    $sum = round($sum,2);
    if (!$out) throw new RuntimeException('Selecione pelo menos um vale para a forma de pagamento Vale.');
    if (abs($sum - round($lineAmount,2)) > 0.01) {
      throw new RuntimeException('A soma dos vales selecionados deve ser igual ao valor informado na forma de pagamento Vale.');
    }
    return $out;
  }
}

if (!function_exists('voucher_apply_order_usage')) {
  function voucher_apply_order_usage(PDO $pdo, int $companyId, int $personId, int $orderId, ?int $paymentMethodId, array $allocations, ?int $userId): void {
    voucher_assert_ready($pdo);
    if ($personId <= 0) throw new RuntimeException('Pagamento com Vale exige cliente cadastrado na venda.');
    foreach ($allocations as $allocation) {
      $voucherId = (int)($allocation['voucher_id'] ?? 0);
      $amount = round((float)($allocation['amount'] ?? 0),2);
      if ($voucherId <= 0 || $amount <= 0) continue;
      $st = $pdo->prepare('SELECT * FROM finance_vouchers WHERE company_id=? AND id=? FOR UPDATE');
      $st->execute([$companyId,$voucherId]);
      $voucher = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$voucher || (int)($voucher['person_id'] ?? 0) !== $personId) throw new RuntimeException('Vale inválido para o cliente desta venda.');
      if (!in_array(strtoupper((string)($voucher['status'] ?? '')), ['ABERTO','PARCIAL'], true)) throw new RuntimeException('O vale ' . ($voucher['code'] ?? ('#'.$voucherId)) . ' não está disponível.');
      $balance = round((float)($voucher['balance_amount'] ?? 0),2);
      if ($amount > $balance + 0.009) throw new RuntimeException('O vale ' . ($voucher['code'] ?? ('#'.$voucherId)) . ' não possui saldo suficiente.');
      $newBalance = max(0, round($balance - $amount,2));
      $newUsed = round((float)($voucher['used_amount'] ?? 0) + $amount,2);
      $status = $newBalance <= 0.009 ? 'UTILIZADO' : 'PARCIAL';
      $pdo->prepare('UPDATE finance_vouchers SET used_amount=?,balance_amount=?,status=?,updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$newUsed,$newBalance,$status,$companyId,$voucherId]);
      $pdo->prepare("INSERT INTO finance_voucher_usages (company_id,voucher_id,order_id,payment_method_id,amount,status,created_by,created_at) VALUES (?,?,?,?,?,'ATIVO',?,NOW())")
        ->execute([$companyId,$voucherId,$orderId,$paymentMethodId ?: null,$amount,$userId ?: null]);
    }
  }
}

if (!function_exists('voucher_reverse_order_usages')) {
  function voucher_reverse_order_usages(PDO $pdo, int $companyId, int $orderId, ?int $userId, string $reason = ''): void {
    if (!voucher_schema_ready($pdo) || $orderId <= 0) return;
    $st = $pdo->prepare("SELECT * FROM finance_voucher_usages WHERE company_id=? AND order_id=? AND status='ATIVO' ORDER BY id FOR UPDATE");
    $st->execute([$companyId,$orderId]);
    foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $usage) {
      $voucherId = (int)$usage['voucher_id']; $amount = round((float)$usage['amount'],2);
      $sv = $pdo->prepare('SELECT * FROM finance_vouchers WHERE company_id=? AND id=? FOR UPDATE');
      $sv->execute([$companyId,$voucherId]);
      $voucher = $sv->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($voucher) {
        $newUsed = max(0, round((float)$voucher['used_amount'] - $amount,2));
        $newBalance = min((float)$voucher['original_amount'], round((float)$voucher['balance_amount'] + $amount,2));
        $status = $newUsed <= 0.009 ? 'ABERTO' : 'PARCIAL';
        $pdo->prepare('UPDATE finance_vouchers SET used_amount=?,balance_amount=?,status=?,updated_at=NOW() WHERE company_id=? AND id=?')
          ->execute([$newUsed,$newBalance,$status,$companyId,$voucherId]);
      }
      $pdo->prepare("UPDATE finance_voucher_usages SET status='ESTORNADO',reversed_at=NOW(),reversed_by=?,reverse_reason=? WHERE company_id=? AND id=?")
        ->execute([$userId ?: null,$reason !== '' ? $reason : null,$companyId,(int)$usage['id']]);
    }
  }
}

if (!function_exists('voucher_settle_customer_return')) {
  function voucher_settle_customer_return(PDO $pdo, int $companyId, int $documentId, int $personId, float $amount, string $mode, ?int $userId, string $notes = ''): array {
    voucher_assert_ready($pdo);
    $mode = strtoupper(trim($mode));
    $amount = round($amount,2);
    if (!in_array($mode,['DINHEIRO','VALE'],true)) throw new RuntimeException('Escolha como devolver o valor ao cliente: Dinheiro ou Vale.');
    if ($personId <= 0) throw new RuntimeException('Selecione um cliente cadastrado para concluir a devolução do valor.');
    if ($amount <= 0) throw new RuntimeException('O valor da devolução deve ser maior que zero.');
    $docNo = (string)$documentId;
    try {
      $stNo = $pdo->prepare('SELECT number FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
      $stNo->execute([$companyId,$documentId]); $v = $stNo->fetchColumn(); if ($v !== false && $v !== null) $docNo = (string)$v;
    } catch (Throwable $e) {}

    if ($mode === 'VALE') {
      $voucherId = voucher_issue($pdo,$companyId,$personId,$amount,'DEVOLUCAO_CLIENTE',$documentId,$userId,$docNo,$notes);
      $pdo->prepare('UPDATE fiscal_documents SET finance_generated=1,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId,$documentId]);
      return ['mode'=>'VALE','voucher_id'=>$voucherId];
    }

    $session = gestao_open_cash_session($pdo,$companyId,(int)$userId);
    $cashFree = function_exists('nexa_cash_user_terminal_enabled') && !nexa_cash_user_terminal_enabled($pdo,$companyId,(int)$userId);
    if (!$session && !$cashFree) throw new RuntimeException('Abra o caixa antes de devolver o valor em dinheiro.');
    // Usuário sem Caixa Terminal: sai do caixa padrão do usuário, sem sessão.
    $bankId = $session ? (int)($session['bank_account_id'] ?? 0) : gestao_user_default_cash_account($pdo,$companyId,(int)$userId);
    if ($bankId <= 0) throw new RuntimeException('Defina o caixa padrão do usuário para devolver o valor em dinheiro.');
    if ($bankId > 0 && function_exists('gestao_pdv_assert_cash_account')) gestao_pdv_assert_cash_account($pdo,$companyId,$bankId,'Este usuário não possui permissão para usar o caixa da devolução.');
    $chartId = gestao_default_chart_id($pdo,$companyId,'DEBITO');
    if ($chartId <= 0) throw new RuntimeException('Configure um plano de contas de débito para registrar a devolução em dinheiro.');
    $description = 'Devolução de cliente - documento #' . $docNo;
    $pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,CURDATE(),'DEBITO',?,?,NULL,?,'DEVOLUCAO_CLIENTE',?,?,?,?,?)")
      ->execute([$companyId,$personId,$chartId,finance_movement_bank($pdo,$companyId,$bankId),$documentId,$description,$docNo,$amount,$notes !== '' ? $notes : null]);
    $movementId = (int)$pdo->lastInsertId();
    $pdo->prepare("INSERT INTO gestao_cash_operations (company_id,cash_session_id,user_id,type,amount,bank_account_id,chart_account_id,cost_center_id,movement_id,order_id,description,notes) VALUES (?,?,?,'ESTORNO',?,?,?,NULL,?,NULL,?,?)")
      ->execute([$companyId,$session ? (int)$session['id'] : null,$userId ?: null,$amount,$bankId ?: null,$chartId,$movementId,$description,$notes !== '' ? $notes : null]);
    $pdo->prepare('UPDATE fiscal_documents SET finance_generated=1,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId,$documentId]);
    return ['mode'=>'DINHEIRO','movement_id'=>$movementId];
  }
}
