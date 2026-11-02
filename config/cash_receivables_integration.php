<?php
declare(strict_types=1);

/**
 * Ponte opcional Contas a Receber -> Caixa operacional.
 * Nunca gera finance_movements: utiliza o movimento criado pela baixa original.
 * Habilitacao por empresa (ativa por padrao apos a migration v2, com desligamento explicito) e registro na MESMA transacao da baixa.
 * Nenhuma DDL em runtime. Migrations exclusivamente pelo Atualizador do Banco.
 */

if (!function_exists('nexa_cash_cr_schema_ready')) {
  function nexa_cash_cr_schema_ready(PDO $pdo): bool {
    return function_exists('finance_table_exists')
      && finance_table_exists($pdo, 'gestao_cash_receivable_settings')
      && finance_table_exists($pdo, 'gestao_cash_receivable_links')
      && finance_table_exists($pdo, 'finance_settlement_payments')
      && finance_table_exists($pdo, 'gestao_cash_operations')
      && finance_table_exists($pdo, 'gestao_cash_sessions');
  }
}

if (!function_exists('nexa_cash_cr_enabled')) {
  function nexa_cash_cr_enabled(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0 || !nexa_cash_cr_schema_ready($pdo)) return false;
    $st = $pdo->prepare('SELECT enabled FROM gestao_cash_receivable_settings WHERE company_id=? LIMIT 1');
    $st->execute([$companyId]);
    // Sem configuracao explicita: ativo por padrao. Um registro com enabled=0
    // continua sendo respeitado e pode ser reativado pelo administrador.
    $enabled = $st->fetchColumn();
    return $enabled === false ? true : (int)$enabled === 1;
  }
}

if (!function_exists('nexa_cash_cr_set_enabled')) {
  function nexa_cash_cr_set_enabled(PDO $pdo, int $companyId, int $userId, bool $enabled): void {
    if ($companyId <= 0 || $userId <= 0 || !nexa_cash_cr_schema_ready($pdo)) {
      throw new RuntimeException('Atualize o banco pelo Atualizador antes de habilitar recebimentos no caixa.');
    }
    $st = $pdo->prepare('INSERT INTO gestao_cash_receivable_settings (company_id,enabled,updated_by_user_id,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()');
    $st->execute([$companyId, $enabled ? 1 : 0, $userId]);
  }
}

if (!function_exists('nexa_cash_cr_open_session')) {
  function nexa_cash_cr_open_session(PDO $pdo, int $companyId, int $userId, bool $forUpdate = false): ?array {
    if ($companyId <= 0 || $userId <= 0 || !finance_table_exists($pdo, 'gestao_cash_sessions')) return null;
    $st = $pdo->prepare("SELECT id,company_id,user_id,bank_account_id,status FROM gestao_cash_sessions WHERE company_id=? AND user_id=? AND status='ABERTO' ORDER BY id DESC LIMIT 1" . ($forUpdate ? ' FOR UPDATE' : ''));
    $st->execute([$companyId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('nexa_cash_cr_prepare_manual_data')) {
  function nexa_cash_cr_prepare_manual_data(PDO $pdo, int $companyId, int $userId, array $data): array {
    // So a baixa manual (individual ou em lote) opta por entrar no caixa;
    // sincronizacoes Itaú/Sicoob e outras origens continuam intactas.
    if (!nexa_cash_cr_enabled($pdo, $companyId)) return $data;
    $session = nexa_cash_cr_open_session($pdo, $companyId, $userId);
    if ($session) {
      $data['_nexa_cash_cr_session_id'] = (int)$session['id'];
      $data['_nexa_cash_cr_user_id'] = $userId;
    }
    return $data;
  }
}

if (!function_exists('nexa_cash_cr_register_payment')) {
  function nexa_cash_cr_register_payment(PDO $pdo, int $companyId, int $settlementId, int $paymentId,
    int $movementId, int $originId, array $title, array $split, float $amount, array $context): void {
    $userId = (int)($context['_nexa_cash_cr_user_id'] ?? 0);
    $sessionId = (int)($context['_nexa_cash_cr_session_id'] ?? 0);
    if ($userId <= 0 || $sessionId <= 0 || $companyId <= 0 || $paymentId <= 0 || $movementId <= 0 || $amount <= 0) return;
    if (!nexa_cash_cr_enabled($pdo, $companyId)) return;
    if (!$pdo->inTransaction()) throw new RuntimeException('Integração C/R-Caixa exige transação ativa.');

    $session = nexa_cash_cr_open_session($pdo, $companyId, $userId, true);
    if (!$session || (int)$session['id'] !== $sessionId) {
      throw new RuntimeException('O caixa foi fechado ou alterado. Atualize a página para confirmar a baixa.');
    }
    $st = $pdo->prepare('SELECT id FROM gestao_cash_receivable_links WHERE company_id=? AND settlement_payment_id=? LIMIT 1');
    $st->execute([$companyId, $paymentId]);
    if ($st->fetchColumn()) return; // chave unica adicional protege concorrencia

    $methodId = (int)($split['payment_method_id'] ?? 0);
    $methodLabel = trim((string)($split['payment_method'] ?? ''));
    $affectsCash = false;
    if ($methodId > 0) {
      $st = $pdo->prepare('SELECT affects_cash FROM finance_payment_methods WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId, $methodId]);
      $affectsCash = (int)($st->fetchColumn() ?: 0) === 1;
    } else {
      // Baixas no formato legado: registrar no caixa apenas se a forma for
      // localizada com seguranca no cadastro da empresa e movimentar caixa.
      if ($methodLabel !== '') {
        $st = $pdo->prepare('SELECT affects_cash FROM finance_payment_methods WHERE company_id=? AND name=? AND is_active=1 LIMIT 2');
        $st->execute([$companyId, $methodLabel]);
        $found = $st->fetchAll(PDO::FETCH_COLUMN);
        $affectsCash = count($found) === 1 && (int)$found[0] === 1;
      }
    }
    if (!$affectsCash) return; // mesmo criterio utilizado pela venda PDV

    $bankId = (int)($split['bank_account_id'] ?? 0) ?: (int)($session['bank_account_id'] ?? 0);
    $chartId = (int)($title['chart_account_id'] ?? 0) ?: null;
    $costId = (int)($title['cost_center_id'] ?? 0) ?: null;
    $description = 'Recebimento C/R - Título #' . $originId . ($methodLabel !== '' ? ' · ' . $methodLabel : '');
    $notes = 'Baixa #' . $settlementId . '; pagamento #' . $paymentId;
    $st = $pdo->prepare("INSERT INTO gestao_cash_operations (company_id,cash_session_id,user_id,type,amount,bank_account_id,chart_account_id,cost_center_id,movement_id,order_id,description,notes) VALUES (?,?,?,'RECEBIMENTO_CR',?,?,?,?,?,NULL,?,?)");
    $st->execute([$companyId, $sessionId, $userId, round($amount,2), $bankId ?: null, $chartId, $costId, $movementId, $description, $notes]);
    $operationId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare('INSERT INTO gestao_cash_receivable_links (company_id,settlement_id,settlement_payment_id,cash_operation_id,created_at) VALUES (?,?,?,?,NOW())');
    $st->execute([$companyId, $settlementId, $paymentId, $operationId]);
  }
}

if (!function_exists('nexa_cash_cr_reverse_settlement')) {
  function nexa_cash_cr_reverse_settlement(PDO $pdo, int $companyId, int $settlementId, int $userId,
    string $reason, array $reverseMovementByOriginal): void {
    if (!nexa_cash_cr_schema_ready($pdo)) return;
    $st = $pdo->prepare('SELECT l.id AS link_id,l.reversal_cash_operation_id,o.* FROM gestao_cash_receivable_links l JOIN gestao_cash_operations o ON o.company_id=l.company_id AND o.id=l.cash_operation_id WHERE l.company_id=? AND l.settlement_id=? ORDER BY l.id FOR UPDATE');
    $st->execute([$companyId, $settlementId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return; // sem entrada no caixa; estorno financeiro continua normal
    if (!$pdo->inTransaction()) throw new RuntimeException('Estorno C/R-Caixa exige transação ativa.');
    $session = nexa_cash_cr_open_session($pdo, $companyId, $userId, true);
    if (!$session) throw new RuntimeException('Esta baixa movimentou o Caixa. Abra seu caixa para registrar o estorno e tente novamente.');
    $currentSessionId = (int)$session['id'];
    foreach ($rows as $row) {
      if ((int)($row['reversal_cash_operation_id'] ?? 0) > 0) continue;
      $originalMovementId = (int)($row['movement_id'] ?? 0);
      $reverseMovementId = (int)($reverseMovementByOriginal[$originalMovementId] ?? 0);
      if ($reverseMovementId <= 0) throw new RuntimeException('Não foi possível vincular o contra-lançamento financeiro ao estorno do Caixa.');
      $st = $pdo->prepare("INSERT INTO gestao_cash_operations (company_id,cash_session_id,user_id,type,amount,bank_account_id,chart_account_id,cost_center_id,movement_id,order_id,description,notes) VALUES (?,?,?,'ESTORNO_CR',?,?,?,?,?,NULL,?,?)");
      $st->execute([$companyId, $currentSessionId, $userId, (float)$row['amount'], (int)$row['bank_account_id'] ?: null, (int)$row['chart_account_id'] ?: null, (int)$row['cost_center_id'] ?: null, $reverseMovementId, 'Estorno C/R - baixa #' . $settlementId, mb_substr($reason,0,250)]);
      $reverseOperationId = (int)$pdo->lastInsertId();
      $up = $pdo->prepare('UPDATE gestao_cash_receivable_links SET reversal_cash_operation_id=?,reversed_at=NOW() WHERE company_id=? AND id=? AND reversal_cash_operation_id IS NULL');
      $up->execute([$reverseOperationId,$companyId,(int)$row['link_id']]);
      if ($up->rowCount() !== 1) throw new RuntimeException('Concorrência identificada no vínculo de estorno do Caixa.');
    }
  }
}
