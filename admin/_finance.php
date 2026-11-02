<?php
declare(strict_types=1);

// NEXA_PATCH_PLANO_CLASSIFICACAO_V1_20260805

require_once __DIR__ . '/../config/finance_module.php';
require_once __DIR__ . '/../config/saas_modules.php';

// V11-V11.23 - o COMPANY_ID global permanece legado. Somente páginas explicitamente
// homologadas abaixo podem usar a empresa selecionada pelo F11.
if (!function_exists('finance_multi_company_read_pages')) {
  function finance_multi_company_read_pages(): array {
    return [
      'financeiro.php','financeiro_contas_receber.php','financeiro_cartoes.php','financeiro_contas_pagar.php',
      'financeiro_despesas_fixas.php','financeiro_movimentos.php','financeiro_vales.php','financeiro_resultado.php',
      'financeiro_caixas_bancos.php','financeiro_itau.php','financeiro_plano_contas.php','financeiro_centros_custo.php',
      'financeiro_formas_pagamento.php','financeiro_recibo_baixa.php','financeiro_recibo_whatsapp.php',
      'financeiro_boletos.php','financeiro_boleto_itau.php','financeiro_boleto_imprimir.php','financeiro_boleto_whatsapp.php',
      'financeiro_conciliacao.php'
    ];
  }
}
if (!function_exists('finance_multi_company_title_write_pages')) {
  function finance_multi_company_title_write_pages(): array {
    return ['financeiro_contas_receber.php','financeiro_contas_pagar.php'];
  }
}
if (!function_exists('finance_multi_company_page_scope')) {
  function finance_multi_company_page_scope(string $page): string {
    $page = strtolower(basename($page));
    return match ($page) {
      'financeiro_caixas_bancos.php' => 'finance_bank_accounts',
      'financeiro_itau.php' => 'finance_billing_config',
      'financeiro_plano_contas.php' => 'finance_chart_accounts',
      'financeiro_centros_custo.php' => 'finance_cost_centers',
      'financeiro_despesas_fixas.php' => 'finance_fixed_expenses',
      'financeiro_formas_pagamento.php' => 'finance_payment_methods',
      'financeiro_boletos.php', 'financeiro_boleto_itau.php', 'financeiro_boleto_imprimir.php' => 'finance_billing_read',
      'financeiro_boleto_whatsapp.php' => 'finance_billing_send',
      'financeiro_recibo_whatsapp.php' => 'finance_receipt_send',
      'email_documento.php' => strtolower(trim((string)($_REQUEST['type'] ?? ''))) === 'recibo' ? 'finance_receipt_send' : 'finance_billing_send',
      'financeiro_conciliacao.php' => 'finance_bank_reconciliation',
      'financeiro_cartoes.php' => 'finance_card_control',
      'financeiro_contas_receber.php', 'financeiro_contas_pagar.php' => 'finance_write',
      default => 'finance_read',
    };
  }
}
if (!function_exists('finance_multi_company_cross_context')) {
  function finance_multi_company_cross_context(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, finance_multi_company_read_pages(), true)) return false;
    $scope = finance_multi_company_page_scope($page);
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, $scope);
  }
}
if (!function_exists('finance_multi_company_title_write_enabled')) {
  function finance_multi_company_title_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, finance_multi_company_title_write_pages(), true)) return false;
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'finance_write');
  }
}
if (!function_exists('finance_multi_company_reconciliation_settlement_enabled')) {
  function finance_multi_company_reconciliation_settlement_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_settlement')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}
if (!function_exists('finance_multi_company_settlement_write_enabled')) {
  function finance_multi_company_settlement_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page === 'financeiro_conciliacao.php') {
      return finance_multi_company_reconciliation_settlement_enabled($pdo);
    }
    if (!in_array($page, finance_multi_company_title_write_pages(), true)) return false;
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'finance_settlement');
  }
}
if (!function_exists('finance_multi_company_reversal_write_enabled')) {
  function finance_multi_company_reversal_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, finance_multi_company_title_write_pages(), true)) return false;
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'finance_reversal');
  }
}
if (!function_exists('finance_multi_company_bank_write_enabled')) {
  function finance_multi_company_bank_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_caixas_bancos.php') return false;
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'finance_bank_accounts');
  }
}
if (!function_exists('finance_multi_company_billing_config_enabled')) {
  function finance_multi_company_billing_config_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, ['financeiro_caixas_bancos.php','financeiro_itau.php'], true)) return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_billing_config')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_caixas');
  }
}
if (!function_exists('finance_multi_company_itau_settlement_enabled')) {
  function finance_multi_company_itau_settlement_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, ['financeiro_contas_receber.php','financeiro_itau.php'], true)) return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_itau_settlement')) {
      return false;
    }
    if (!function_exists('multi_company_context_permission_allowed')) return false;
    return multi_company_context_permission_allowed($pdo, 'financeiro_receber')
      && multi_company_context_permission_allowed($pdo, 'financeiro_baixar_receber')
      && multi_company_context_permission_allowed($pdo, 'financeiro_boletos');
  }
}

if (!function_exists('finance_multi_company_billing_cancel_enabled')) {
  function finance_multi_company_billing_cancel_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, ['financeiro_boletos.php','financeiro_contas_receber.php'], true)) return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_billing_cancel')) {
      return false;
    }
    if (!function_exists('multi_company_context_permission_allowed')) return false;
    return multi_company_context_permission_allowed($pdo, 'financeiro_boletos')
      && multi_company_context_permission_allowed($pdo, 'financeiro_cancelar_titulos');
  }
}

if (!function_exists('finance_multi_company_billing_send_enabled')) {
  function finance_multi_company_billing_send_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page === 'email_documento.php' && strtolower(trim((string)($_REQUEST['type'] ?? ''))) !== 'boleto') return false;
    if (!in_array($page, ['financeiro_boletos.php','financeiro_contas_receber.php','financeiro_boleto_whatsapp.php','email_documento.php'], true)) return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_billing_send')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_boletos');
  }
}

if (!function_exists('finance_multi_company_receipt_send_enabled')) {
  function finance_multi_company_receipt_send_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page === 'email_documento.php' && strtolower(trim((string)($_REQUEST['type'] ?? ''))) !== 'recibo') return false;
    if (!in_array($page, ['financeiro_recibo_baixa.php','financeiro_recibo_whatsapp.php','email_documento.php'], true)) return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_receipt_send')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_receber');
  }
}

if (!function_exists('finance_multi_company_movement_scope_enabled')) {
  function finance_multi_company_movement_scope_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_movimentos.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_movements_write')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_movimentos');
  }
}
if (!function_exists('finance_multi_company_movement_write_enabled')) {
  function finance_multi_company_movement_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    return $page === 'financeiro_movimentos.php'
      && finance_multi_company_movement_scope_enabled($pdo)
      && multi_company_context_permission_allowed($pdo, 'financeiro_movimento_manual');
  }
}
if (!function_exists('finance_multi_company_movement_cancel_enabled')) {
  function finance_multi_company_movement_cancel_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    return $page === 'financeiro_movimentos.php'
      && finance_multi_company_movement_scope_enabled($pdo)
      && multi_company_context_permission_allowed($pdo, 'financeiro_cancelar_titulos');
  }
}

if (!function_exists('finance_multi_company_fixed_expense_write_enabled')) {
  function finance_multi_company_fixed_expense_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_despesas_fixas.php') return false;
    return function_exists('multi_company_scope_is_cross_company')
      && multi_company_scope_is_cross_company($pdo, 'finance_fixed_expenses');
  }
}
if (!function_exists('finance_multi_company_reconciliation_import_enabled')) {
  function finance_multi_company_reconciliation_import_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_import')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}
if (!function_exists('finance_multi_company_reconciliation_status_enabled')) {
  function finance_multi_company_reconciliation_status_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_status')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}

if (!function_exists('finance_multi_company_reconciliation_create_title_enabled')) {
  function finance_multi_company_reconciliation_create_title_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_settlement')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_create_title')
        || !multi_company_scope_is_cross_company($pdo, 'finance_write')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}

if (!function_exists('finance_multi_company_reconciliation_create_movement_enabled')) {
  function finance_multi_company_reconciliation_create_movement_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation_create_movement')) {
      return false;
    }
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}

// V11.23.1 HOTFIX: nas baixas de Contas a Receber/Pagar pelo F11, o usuário
// autenticado continua pertencendo ao tenant de entrada. Por isso, user_cash_accounts
// não pode filtrar os caixas/bancos da empresa de destino. A autorização vem do
// perfil efetivo Multiempresa e da permissão específica de baixa; a conta escolhida
// ainda precisa estar ativa e pertencer exatamente à empresa operacional.
if (!function_exists('finance_multi_company_settlement_bank_access_enabled')) {
  function finance_multi_company_settlement_bank_access_enabled(PDO $pdo, ?int $companyId = null): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, ['financeiro_contas_receber.php','financeiro_contas_pagar.php'], true)) return false;
    if (!finance_multi_company_settlement_write_enabled($pdo)) return false;

    $targetCompanyId = function_exists('finance_operational_company_id')
      ? (int)finance_operational_company_id($pdo)
      : (defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : 0);
    if ($targetCompanyId <= 0) return false;
    if ($companyId !== null && $companyId > 0 && $companyId !== $targetCompanyId) return false;

    $permission = $page === 'financeiro_contas_receber.php'
      ? 'financeiro_baixar_receber'
      : 'financeiro_baixar_pagar';

    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, $permission);
  }
}

if (!function_exists('finance_multi_company_settlement_bank_options')) {
  function finance_multi_company_settlement_bank_options(PDO $pdo, int $companyId): array {
    if (!finance_multi_company_settlement_bank_access_enabled($pdo, $companyId)) {
      return finance_bank_options($pdo, $companyId);
    }
    return finance_query_all(
      $pdo,
      'SELECT id, name, type FROM finance_bank_accounts WHERE company_id = ? AND active = 1 ORDER BY name ASC',
      [$companyId]
    );
  }
}

// V11.17.1 HOTFIX: no F11, o usuário autenticado pertence ao tenant de entrada e
// não deve depender de user_cash_accounts da empresa de destino. A autorização da
// Conciliação passa pelo perfil efetivo Multiempresa; o uso da conta continua
// restrito a uma conta ativa pertencente exatamente à empresa operacional.
if (!function_exists('finance_multi_company_reconciliation_bank_access_enabled')) {
  function finance_multi_company_reconciliation_bank_access_enabled(PDO $pdo, ?int $companyId = null): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if ($page !== 'financeiro_conciliacao.php') return false;
    if (!function_exists('multi_company_scope_is_cross_company')
        || !multi_company_scope_is_cross_company($pdo, 'finance_bank_reconciliation')) {
      return false;
    }
    $targetCompanyId = function_exists('finance_operational_company_id')
      ? (int)finance_operational_company_id($pdo)
      : (defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : 0);
    if ($targetCompanyId <= 0) return false;
    if ($companyId !== null && $companyId > 0 && $companyId !== $targetCompanyId) return false;
    return function_exists('multi_company_context_permission_allowed')
      && multi_company_context_permission_allowed($pdo, 'financeiro_conciliacao');
  }
}
if (!function_exists('finance_multi_company_reconciliation_bank_options')) {
  function finance_multi_company_reconciliation_bank_options(PDO $pdo, int $companyId): array {
    if (!finance_multi_company_reconciliation_bank_access_enabled($pdo, $companyId)) {
      return finance_bank_options($pdo, $companyId);
    }
    return finance_query_all(
      $pdo,
      'SELECT id, name, type FROM finance_bank_accounts WHERE company_id = ? AND active = 1 ORDER BY name ASC',
      [$companyId]
    );
  }
}
if (!function_exists('finance_assert_cash_account_effective')) {
  function finance_assert_cash_account_effective(PDO $pdo, int $companyId, ?int $bankId, string $message = ''): void {
    $bankId = (int)($bankId ?? 0);
    if ($bankId <= 0) return;

    if (finance_multi_company_reconciliation_bank_access_enabled($pdo, $companyId)
        || finance_multi_company_settlement_bank_access_enabled($pdo, $companyId)
        || (finance_multi_company_movement_scope_enabled($pdo)
            && function_exists('finance_operational_company_id')
            && (int)finance_operational_company_id($pdo) === $companyId)) {
      $st = $pdo->prepare('SELECT 1 FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1');
      $st->execute([$companyId, $bankId]);
      if (!$st->fetchColumn()) {
        throw new RuntimeException($message !== '' ? $message : 'A conta bancária selecionada não pertence à empresa escolhida no F11 ou está inativa.');
      }
      return;
    }

    if (function_exists('access_assert_cash_account')) {
      access_assert_cash_account($pdo, $companyId, $bankId, $message);
      return;
    }
    if (!finance_validate_bank_account($pdo, $companyId, $bankId)) {
      throw new RuntimeException($message !== '' ? $message : 'Caixa/banco inválido ou inativo.');
    }
  }
}
if (!function_exists('finance_multi_company_page_write_enabled')) {
  function finance_multi_company_page_write_enabled(PDO $pdo): bool {
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (in_array($page, finance_multi_company_title_write_pages(), true)) return finance_multi_company_title_write_enabled($pdo);
    if ($page === 'financeiro_caixas_bancos.php') return finance_multi_company_bank_write_enabled($pdo);
    if ($page === 'financeiro_itau.php') return finance_multi_company_billing_config_enabled($pdo) || finance_multi_company_itau_settlement_enabled($pdo);
    if ($page === 'financeiro_despesas_fixas.php') return finance_multi_company_fixed_expense_write_enabled($pdo);
    if ($page === 'financeiro_movimentos.php') return finance_multi_company_movement_write_enabled($pdo) || finance_multi_company_movement_cancel_enabled($pdo);
    if ($page === 'financeiro_recibo_whatsapp.php') return finance_multi_company_receipt_send_enabled($pdo);
    if ($page === 'financeiro_plano_contas.php') return function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_chart_accounts');
    if ($page === 'financeiro_centros_custo.php') return function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_cost_centers');
    if ($page === 'financeiro_conciliacao.php') return finance_multi_company_reconciliation_import_enabled($pdo) || finance_multi_company_reconciliation_settlement_enabled($pdo) || finance_multi_company_reconciliation_status_enabled($pdo) || finance_multi_company_reconciliation_create_title_enabled($pdo) || finance_multi_company_reconciliation_create_movement_enabled($pdo);
    return false;
  }
}
if (!function_exists('finance_effective_permission_allowed')) {
  function finance_effective_permission_allowed(PDO $pdo, string $permission): bool {
    if (finance_multi_company_cross_context($pdo)) {
      return function_exists('multi_company_context_permission_allowed')
        && multi_company_context_permission_allowed($pdo, $permission);
    }
    return !function_exists('access_has_permission') || access_has_permission($pdo, finance_operational_company_id($pdo), $permission);
  }
}
if (!function_exists('finance_multi_company_assert_settlement_safe')) {
  function finance_multi_company_assert_settlement_safe(PDO $pdo, int $companyId, string $originType, array $originIds): void {
    if (!finance_multi_company_cross_context($pdo)) return;
    if (!finance_multi_company_settlement_write_enabled($pdo)) {
      throw new RuntimeException('V11.2: baixas financeiras ainda não estão habilitadas para este contexto.');
    }
    $originType = strtoupper(trim($originType));
    if (!in_array($originType, ['RECEBER','PAGAR'], true)) throw new RuntimeException('Tipo de título inválido para baixa.');
    $ids = array_values(array_unique(array_filter(array_map('intval', $originIds), static fn($id) => $id > 0)));
    if (!$ids) throw new RuntimeException('Título inválido para baixa.');
    $table = $originType === 'RECEBER' ? 'finance_receivables' : 'finance_payables';
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $rows = finance_query_all($pdo, "SELECT * FROM {$table} WHERE company_id=? AND id IN ({$ph})", array_merge([$companyId], $ids));
    if (count($rows) !== count($ids)) throw new RuntimeException('Um ou mais títulos não pertencem à empresa selecionada pelo F11.');
    foreach ($rows as $title) {
      $id = (int)($title['id'] ?? 0);
      $status = strtoupper(trim((string)($title['status'] ?? '')));
      if (!in_array($status, ['ABERTO','PARCIAL'], true) || (float)($title['open_amount'] ?? 0) <= 0.009) {
        throw new RuntimeException('O título #' . $id . ' não possui saldo em aberto para baixa.');
      }
      $personId = (int)($title['person_id'] ?? 0);
      if ($personId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_people WHERE company_id=? AND id=?');
        $st->execute([$companyId, $personId]);
        if ((int)$st->fetchColumn() <= 0) throw new RuntimeException('O título #' . $id . ' referencia pessoa de outra empresa ou inexistente.');
      }
      $chartId = (int)($title['chart_account_id'] ?? 0);
      if ($chartId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_chart_accounts WHERE company_id=? AND id=?');
        $st->execute([$companyId, $chartId]);
        if ((int)$st->fetchColumn() <= 0) throw new RuntimeException('O título #' . $id . ' referencia plano de contas de outra empresa ou inexistente.');
      }
      $costId = (int)($title['cost_center_id'] ?? 0);
      if ($costId > 0) {
        $st = $pdo->prepare('SELECT COUNT(*) FROM finance_cost_centers WHERE company_id=? AND id=?');
        $st->execute([$companyId, $costId]);
        if ((int)$st->fetchColumn() <= 0) throw new RuntimeException('O título #' . $id . ' referencia centro de custo de outra empresa ou inexistente.');
      }
      if ($originType === 'RECEBER' && finance_table_exists($pdo, 'finance_boleto_charges')) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? AND COALESCE(status,'')<>'CANCELADO'");
        $st->execute([$companyId, $id]);
        if ((int)$st->fetchColumn() > 0) throw new RuntimeException('O título #' . $id . ' possui cobrança/boleto ativo. A baixa manual pela Conciliação no F11 permanece bloqueada para evitar divergência com o fluxo bancário Itaú.');
      }
      if ($originType === 'PAGAR' && finance_table_exists($pdo, 'finance_fixed_expense_titles')) {
        $st = $pdo->prepare('SELECT fixed_expense_id FROM finance_fixed_expense_titles WHERE company_id=? AND payable_id=? LIMIT 1');
        $st->execute([$companyId, $id]);
        $fixedExpenseId = (int)($st->fetchColumn() ?: 0);
        if ($fixedExpenseId > 0) {
          $fixedScopeOk = function_exists('multi_company_scope_is_cross_company')
            && multi_company_scope_is_cross_company($pdo, 'finance_fixed_expenses');
          $fixedPermissionOk = function_exists('multi_company_context_permission_allowed')
            && multi_company_context_permission_allowed($pdo, 'financeiro_despesas_fixas');
          if (!$fixedScopeOk || !$fixedPermissionOk) {
            throw new RuntimeException('O título #' . $id . ' pertence a uma Despesa Fixa. Seu perfil precisa da permissão Despesas fixas para baixar este título pelo F11.');
          }
          $fixedRule = finance_query_one($pdo, 'SELECT * FROM finance_fixed_expenses WHERE company_id=? AND id=? LIMIT 1', [$companyId, $fixedExpenseId]);
          if (!$fixedRule) throw new RuntimeException('O título #' . $id . ' possui vínculo com uma Despesa Fixa inexistente nesta empresa.');
          if (!empty($fixedRule['active'])) {
            if (!finance_validate_person_role($pdo, $companyId, (int)($fixedRule['person_id'] ?? 0), 'supplier')) {
              throw new RuntimeException('A Despesa Fixa #' . $fixedExpenseId . ' referencia fornecedor inválido/inativo. Corrija a regra antes da baixa pelo F11.');
            }
            if (!finance_validate_analytic_chart($pdo, $companyId, (int)($fixedRule['chart_account_id'] ?? 0))) {
              throw new RuntimeException('A Despesa Fixa #' . $fixedExpenseId . ' referencia Plano de Contas inválido. Corrija a regra antes da baixa pelo F11.');
            }
            $fixedCostId = (int)($fixedRule['cost_center_id'] ?? 0) ?: null;
            if (!finance_validate_analytic_cost($pdo, $companyId, $fixedCostId)) {
              throw new RuntimeException('A Despesa Fixa #' . $fixedExpenseId . ' referencia Centro de Custo inválido. Corrija a regra antes da baixa pelo F11.');
            }
            $fixedBankId = (int)($fixedRule['bank_account_id'] ?? 0) ?: null;
            if (!finance_validate_bank_account($pdo, $companyId, $fixedBankId)) {
              throw new RuntimeException('A Despesa Fixa #' . $fixedExpenseId . ' referencia Caixa/Banco inválido ou inativo. Corrija a regra antes da baixa pelo F11.');
            }
          }
        }
      }
    }
  }
}
if (!function_exists('finance_operational_company_id')) {
  function finance_operational_company_id(PDO $pdo): int {
    $legacy = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    if (!in_array($page, finance_multi_company_read_pages(), true)) return $legacy;
    $scope = finance_multi_company_page_scope($page);
    return function_exists('multi_company_operational_company_id')
      ? (int)multi_company_operational_company_id($pdo, $scope)
      : $legacy;
  }
}
if (!function_exists('finance_multi_company_readonly')) {
  function finance_multi_company_readonly(PDO $pdo): bool {
    if (!finance_multi_company_cross_context($pdo)) return false;
    return !finance_multi_company_page_write_enabled($pdo);
  }
}
if (!function_exists('finance_operational_company_name')) {
  function finance_operational_company_name(PDO $pdo): string {
    $cid = finance_operational_company_id($pdo);
    try {
      $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
      $st->execute([$cid]);
      $name = trim((string)($st->fetchColumn() ?: ''));
      if ($name !== '') return $name;
    } catch (Throwable $e) {}
    return 'Empresa #' . $cid;
  }
}

$__financePage = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
$__financeCrossContext = finance_multi_company_cross_context($pdo);
$__financeReadOnly = finance_multi_company_readonly($pdo);
$__financeCompanyId = finance_operational_company_id($pdo);
// Em qualquer contexto cruzado evita DDL/ensure_schema. A V11.1 grava somente nas tabelas
// já existentes do módulo Financeiro e não altera estrutura durante a operação.
if (!$__financeCrossContext) {
  try { finance_ensure_schema($pdo); } catch (Throwable $e) {}
}
$__deliveryCanUsePeople = $__financePage === 'financeiro_pessoas.php' && function_exists('saas_company_has_module') && saas_company_has_module($pdo, (int)COMPANY_ID, 'delivery');
if (!$__deliveryCanUsePeople) {
  saas_require_company_module($pdo, $__financeCompanyId > 0 ? $__financeCompanyId : (int)COMPANY_ID, 'financeiro', 'Módulo Financeiro');
}

function finance_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function finance_admin_start(string $title, string $active = 'financeiro'): void {
  admin_topbar($title, $active);
  ?>
  <style>
    .finance-page{--fin-border:#dbe4f0;--fin-muted:#64748b;--fin-bg:#f8fbff;--fin-radius:22px;}
    .finance-hero{display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;margin:8px 0 14px}
    .finance-hero h1{margin:0;font-size:clamp(24px,3.2vw,34px);letter-spacing:-.04em;color:var(--admin-primary);line-height:1.08}
    .finance-hero p{margin:7px 0 0;color:#64748b;line-height:1.45;max-width:850px}
    .finance-actions{display:flex;gap:9px;flex-wrap:wrap;align-items:center;justify-content:flex-end;margin-left:auto}
    .finance-actions .btn{min-height:42px;display:inline-flex;align-items:center;justify-content:center}
    .finance-tabs{display:flex;gap:8px;flex-wrap:wrap;margin:10px 0 16px;align-items:center}
    .finance-tabs a,.finance-tabs summary{display:inline-flex;align-items:center;padding:9px 12px;border:1px solid #dbe4f0;border-radius:999px;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;font-size:13px;cursor:pointer;min-height:38px}
    .finance-tabs a.active,.finance-tabs summary.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:var(--admin-text);border-color:transparent}
    .finance-tab-menu{position:relative;display:inline-block}
    .finance-tab-menu summary{list-style:none}.finance-tab-menu summary::-webkit-details-marker{display:none}.finance-tab-menu summary:after{content:' ▾';font-size:11px;opacity:.75}
    .finance-tab-menu[open] summary{border-radius:18px 18px 10px 10px}
    .finance-tab-panel{position:absolute;z-index:45;right:0;top:calc(100% + 6px);min-width:230px;background:#fff;border:1px solid var(--fin-border);border-radius:18px;padding:8px;box-shadow:0 20px 55px rgba(11,31,91,.16)}
    .finance-tab-panel a{display:flex;border-radius:12px;border:0;white-space:nowrap;background:#fff}.finance-tab-panel a:hover{background:#f1f5f9}
    .finance-card{background:#fff;border:1px solid var(--fin-border);border-radius:var(--fin-radius);padding:18px;box-shadow:0 12px 30px rgba(2,6,23,.06);margin-bottom:14px;min-width:0}
    .finance-page input,.finance-page select,.finance-page textarea{max-width:100%;box-sizing:border-box}.finance-page table{max-width:100%}
    .finance-subtitle{margin:0 0 12px;font-size:18px;color:var(--admin-primary);font-weight:950;letter-spacing:-.02em}
    .finance-card-head{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:14px}
    .finance-card-head .finance-subtitle{margin-bottom:0}
    .finance-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}
    .finance-grid-3{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}
    .finance-grid-4{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
    .finance-dashboard-lists{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px;align-items:stretch}.finance-dashboard-lists .finance-card{height:100%;margin-bottom:0}
    .finance-kpi{background:#fff;border:1px solid var(--fin-border);border-radius:20px;padding:16px;box-shadow:0 10px 24px rgba(2,6,23,.05);min-width:0}
    .finance-kpi small{display:block;color:#64748b;font-weight:800;margin-bottom:8px}.finance-kpi strong{font-size:clamp(21px,2.7vw,28px);color:#0f172a;line-height:1.08}.finance-kpi .pos{color:#047857}.finance-kpi .neg{color:#b91c1c}
    .finance-filter-card{padding:14px}.finance-filter{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:12px;align-items:end}.finance-filter .wide{grid-column:span 2}.finance-filter-actions{display:flex;gap:8px;justify-content:flex-end;align-items:center}
    .finance-filter-periodo{grid-template-columns:minmax(160px,1fr) minmax(160px,1fr) minmax(180px,1fr) auto}.finance-filter-periodo .history{grid-column:1 / -1}.finance-filter-periodo .finance-filter-actions{min-width:160px}.finance-filter-periodo .finance-filter-actions .btn{min-height:46px}
    .finance-field-inline{display:flex;gap:8px;align-items:center}.finance-field-inline input{flex:1;min-width:0}.finance-field-inline .btn{height:46px;padding:0 13px;white-space:nowrap}.finance-lookup-status{margin-top:6px;color:#64748b;font-size:12px;min-height:16px}.finance-lookup-status.ok{color:#047857}.finance-lookup-status.err{color:#b91c1c}.finance-lookup-status.load{color:#1d4ed8}
    .finance-form-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.finance-form-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.finance-form-grid .span-2{grid-column:span 2}.finance-form-grid .span-3{grid-column:span 3}
    .finance-form-section{border:1px solid #eef2f7;border-radius:18px;padding:14px;background:#fff;margin-bottom:14px}.finance-form-section h3{margin:0 0 12px;font-size:15px;color:var(--admin-primary);font-weight:950}
    .finance-form label{font-weight:850;color:#334155;font-size:13px;display:block;margin-bottom:5px}.finance-form .checkline{display:flex;gap:8px;align-items:center;margin:0;padding:11px 12px;border:1px solid #dbe4f0;background:#f8fbff;border-radius:16px}.finance-form .checkline input{width:auto}.finance-form .hint,.finance-muted{color:#64748b;font-size:13px;line-height:1.4}.finance-form .hint a{font-weight:850;color:var(--admin-primary)}
    .finance-form-actions{display:flex;gap:10px;justify-content:flex-end;align-items:center;position:sticky;bottom:0;background:linear-gradient(180deg,rgba(255,255,255,.76),#fff 35%);padding-top:12px;margin-top:10px;border-top:1px solid #eef2f7}
    .finance-value{text-align:right;font-variant-numeric:tabular-nums;white-space:nowrap}.finance-value.neg{color:#b91c1c}.finance-value.pos{color:#047857}.finance-danger{color:#b91c1c}.finance-ok{color:#047857}.finance-warning{color:#b45309}
    .finance-tree-row.synthetic{font-weight:900;background:#f8fbff}.finance-tree-row .code,.code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;color:#475569}
    .finance-status{display:inline-flex;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid #dbe4f0;background:#fff;white-space:nowrap}.finance-status.ABERTO{color:#1d4ed8;background:#eff6ff;border-color:#bfdbfe}.finance-status.PARCIAL{color:#92400e;background:#fffbeb;border-color:#fde68a}.finance-status.BAIXADO{color:#047857;background:#ecfdf5;border-color:#a7f3d0}.finance-status.CANCELADO{color:#b91c1c;background:#fef2f2;border-color:#fecaca}
    .finance-badge{display:inline-flex;align-items:center;padding:4px 9px;border-radius:999px;font-size:12px;font-weight:900;background:#eef6ff;color:#1d4ed8;border:1px solid #bfdbfe;margin:2px 4px 2px 0}.finance-badge.green{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.finance-badge.gray{background:#f8fafc;color:#475569;border-color:#e2e8f0}.finance-badge.red{background:#fef2f2;color:#b91c1c;border-color:#fecaca}
    .finance-table-titles{table-layout:fixed;width:100%;min-width:0}.finance-table-titles th,.finance-table-titles td{vertical-align:middle}.finance-table-titles th:nth-child(1),.finance-table-titles td:nth-child(1){width:5.5%}.finance-table-titles th:nth-child(2),.finance-table-titles td:nth-child(2){width:16%}.finance-table-titles th:nth-child(3),.finance-table-titles td:nth-child(3){width:18%}.finance-table-titles th:nth-child(4),.finance-table-titles td:nth-child(4){width:10%}.finance-table-titles th:nth-child(5),.finance-table-titles td:nth-child(5){width:9.5%}.finance-table-titles th:nth-child(6),.finance-table-titles td:nth-child(6){width:10%}.finance-table-titles th:nth-child(7),.finance-table-titles td:nth-child(7){width:13%}.finance-table-titles th:nth-child(8),.finance-table-titles td:nth-child(8){width:18%}.finance-table-titles td:not(.finance-action-cell){overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}.finance-table-titles .finance-primary{white-space:normal;line-height:1.25}.finance-table-titles .finance-primary strong,.finance-table-titles .finance-primary .finance-muted{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}.finance-actions-th,.finance-action-cell{text-align:right!important}.finance-table-titles .finance-actions-th,.finance-table-titles .finance-action-cell{position:sticky;right:0;z-index:3;background:#fff;box-shadow:-10px 0 18px rgba(255,255,255,.96)}.finance-table-titles thead .finance-actions-th{z-index:4;background:#f8fbff}.finance-action-cell{overflow:visible!important;white-space:nowrap!important}.finance-action-line{display:flex;gap:5px;flex-wrap:nowrap;align-items:center;justify-content:flex-end}.finance-action-line form{display:inline-flex;margin:0}.finance-action-line .btn{min-width:58px;height:32px;min-height:32px;padding:0 8px;border-radius:10px;font-size:12px;font-weight:900}.finance-action-line .btn-danger{min-width:70px}.finance-inline-actions{display:flex;gap:5px;flex-wrap:nowrap;align-items:center;justify-content:flex-end}.finance-inline-actions .btn{min-width:58px;height:32px;min-height:32px;padding:0 8px;border-radius:10px;font-size:12px;font-weight:900}.finance-inline-actions .btn-danger{min-width:70px}.finance-inline-actions form{display:inline-flex;margin:0}

    .finance-table-movements{table-layout:fixed;width:100%;min-width:0}.finance-table-movements th,.finance-table-movements td{vertical-align:middle}.finance-table-movements th:nth-child(1),.finance-table-movements td:nth-child(1){width:7%}.finance-table-movements th:nth-child(2),.finance-table-movements td:nth-child(2){width:7%}.finance-table-movements th:nth-child(3),.finance-table-movements td:nth-child(3){width:14%}.finance-table-movements th:nth-child(4),.finance-table-movements td:nth-child(4){width:20%}.finance-table-movements th:nth-child(5),.finance-table-movements td:nth-child(5){width:8%}.finance-table-movements th:nth-child(6),.finance-table-movements td:nth-child(6){width:10%}.finance-table-movements th:nth-child(7),.finance-table-movements td:nth-child(7){width:7%}.finance-table-movements th:nth-child(8),.finance-table-movements td:nth-child(8){width:9%}.finance-table-movements th:nth-child(9),.finance-table-movements td:nth-child(9){width:18%}.finance-table-movements td:not(.finance-action-cell){overflow:hidden;text-overflow:ellipsis;white-space:nowrap}.finance-table-movements .finance-primary{white-space:normal;line-height:1.25}.finance-table-movements .finance-primary .finance-muted{display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:100%}.finance-table-movements .finance-actions-th,.finance-table-movements .finance-action-cell{position:sticky;right:0;z-index:3;background:#fff;box-shadow:-10px 0 18px rgba(255,255,255,.96)}.finance-table-movements thead .finance-actions-th{z-index:4;background:#f8fbff}.finance-row-canceled td:not(.finance-action-cell){opacity:.68}.finance-cancel-reason{display:block;color:#b91c1c;font-size:12px;line-height:1.35;margin-top:3px}
    .finance-empty{padding:18px!important;color:#64748b;text-align:center}.finance-mobile-note{display:none;color:#64748b;font-size:12px;margin:8px 0 0}
    .finance-modal{position:fixed;inset:0;z-index:200;display:flex;align-items:flex-start;justify-content:center;padding:22px;background:rgba(15,23,42,.56);backdrop-filter:blur(3px);overflow:auto}.finance-modal[hidden]{display:none!important}.finance-modal-dialog{width:min(980px,100%);max-width:calc(100vw - 44px);background:#fff;border:1px solid #dbe4f0;border-radius:26px;box-shadow:0 26px 80px rgba(2,6,23,.34);padding:18px;animation:financeModalIn .16s ease-out;overflow-x:hidden}.finance-modal-wide{width:min(1120px,100%)}.finance-modal-head{display:flex;gap:12px;align-items:flex-start;justify-content:space-between;margin-bottom:14px}.finance-modal-head h2{margin:0;color:var(--admin-primary);font-size:21px}.finance-modal-close{display:inline-flex;width:42px;height:42px;align-items:center;justify-content:center;border-radius:14px;border:1px solid #dbe4f0;background:#fff;text-decoration:none;color:#0f172a;font-size:22px;font-weight:900}
    @keyframes financeModalIn{from{transform:translateY(8px) scale(.985);opacity:.4}to{transform:none;opacity:1}}
    @media(max-width:1100px){.finance-grid-4{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-filter .wide{grid-column:auto}.finance-filter-periodo{grid-template-columns:repeat(2,minmax(0,1fr))}.finance-filter-periodo .history{grid-column:1 / -1}.finance-filter-periodo .finance-filter-actions{min-width:0;justify-content:flex-end}}
    @media(max-width:980px){.finance-table-titles th:nth-child(3),.finance-table-titles td:nth-child(3),.finance-table-titles th:nth-child(7),.finance-table-titles td:nth-child(7){width:12%}.finance-table-titles th:nth-child(8),.finance-table-titles td:nth-child(8){width:21%}.finance-action-line .btn,.finance-inline-actions .btn{min-width:54px;padding:0 7px}.finance-action-line .btn-danger,.finance-inline-actions .btn-danger{min-width:66px}}
    @media(max-width:820px){.finance-grid,.finance-grid-3,.finance-grid-4,.finance-dashboard-lists,.finance-form-grid,.finance-form-grid.three{grid-template-columns:1fr}.finance-form-grid .span-2,.finance-form-grid .span-3{grid-column:auto}.finance-hero{align-items:stretch}.finance-actions{width:100%;justify-content:stretch}.finance-actions .btn{flex:1 1 auto}.finance-tabs{overflow-x:auto;flex-wrap:nowrap;padding-bottom:4px}.finance-tabs>a,.finance-tab-menu>summary{white-space:nowrap}.finance-tab-panel{position:fixed;left:14px;right:14px;top:auto;min-width:0}.finance-filter{grid-template-columns:1fr}.finance-filter-actions{justify-content:stretch}.finance-filter-actions .btn{flex:1}.finance-card{border-radius:20px;padding:15px}.finance-modal{padding:0;align-items:stretch}.finance-modal-dialog{width:100%;min-height:100vh;border-radius:0;border:0;padding:15px}.finance-mobile-note{display:block}}
    @media(max-width:680px){.table-wrap{overflow:visible}.finance-table-titles .finance-actions-th,.finance-table-titles .finance-action-cell,.finance-table-movements .finance-actions-th,.finance-table-movements .finance-action-cell{position:static;box-shadow:none}.finance-list-table{border-collapse:separate;border-spacing:0 10px}.finance-list-table thead,.finance-list-table tr:first-child:has(th){display:none}.finance-list-table tr{display:block;border:1px solid #dbe4f0;border-radius:18px;background:#fff;box-shadow:0 8px 22px rgba(2,6,23,.05);padding:10px;margin-bottom:10px}.finance-list-table td{display:flex;justify-content:space-between;gap:14px;border:0!important;padding:7px 4px!important;text-align:right}.finance-list-table td::before{content:attr(data-label);font-weight:900;color:#64748b;text-align:left;min-width:112px}.finance-list-table td.finance-primary{display:block;text-align:left}.finance-list-table td.finance-primary::before{display:none}.finance-table-titles,.finance-table-movements{min-width:0;table-layout:auto}.finance-table-titles td:not(.finance-action-cell),.finance-table-movements td:not(.finance-action-cell){max-width:none;overflow:visible;text-overflow:clip;white-space:normal}.finance-list-table .finance-action-cell{display:block;text-align:left!important}.finance-list-table .finance-action-cell::before{display:block;margin-bottom:8px}.finance-list-table .finance-inline-actions,.finance-list-table .finance-action-line{justify-content:flex-start;gap:7px;flex-wrap:wrap}.finance-list-table .finance-inline-actions .btn,.finance-list-table .finance-action-line .btn{width:auto;min-width:88px;flex:0 0 auto}.finance-value{text-align:right}.finance-kpi{padding:14px}.finance-actions{flex-direction:column}.finance-actions .btn{width:100%}}
    @media print{.topbar,.finance-tabs,.finance-actions,.finance-filter-card,.admin-footer,.finance-inline-actions,.btn{display:none!important}.container{max-width:none;padding:0}.finance-card{box-shadow:none;border:0}.finance-modal{position:static;background:#fff;padding:0}.finance-modal-dialog{box-shadow:none;border:0;width:100%}}
  </style>
  <div class="finance-page">
  <?php
}

function finance_tabs(string $active): void {
  $items = [
    'financeiro' => ['Dashboard', 'financeiro.php'],
    'fin_pessoas' => ['Pessoas', 'financeiro_pessoas.php'],
    'fin_receber' => ['Contas a Receber', 'financeiro_contas_receber.php'],
    'fin_cartoes' => ['Controle de Cartões', 'financeiro_cartoes.php'],
    'fin_pagar' => ['Contas a Pagar', 'financeiro_contas_pagar.php'],
    'fin_fixas' => ['Despesas Fixas', 'financeiro_despesas_fixas.php'],
    'fin_mov' => ['Movimento Financeiro', 'financeiro_movimentos.php'],
    'fin_vales' => ['Vales', 'financeiro_vales.php'],
    'fin_boletos' => ['Cobranças/Boletos', 'financeiro_boletos.php'],
    'fin_conciliacao' => ['Conciliação Bancária', 'financeiro_conciliacao.php'],
    'fin_resultado' => ['Resultado por Plano', 'financeiro_resultado.php'],
  ];
  $aux = [
    'fin_plano' => ['Plano de Contas', 'financeiro_plano_contas.php'],
    'fin_centro' => ['Centro de Custo', 'financeiro_centros_custo.php'],
    'fin_caixas' => ['Caixas/Bancos', 'financeiro_caixas_bancos.php'],
    'gestao_pagamentos' => ['Formas de Pagamento', 'gestao_formas_pagamento.php'],
  ];
  $cross = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && finance_multi_company_cross_context($GLOBALS['pdo']);
  if ($cross) {
    // V11.9-V11.15: exibe somente áreas financeiras explicitamente homologadas no F11.
    $safeKeys = ['financeiro','fin_receber','fin_cartoes','fin_pagar','fin_fixas','fin_mov','fin_vales','fin_boletos','fin_conciliacao','fin_resultado'];
    $permissionByKey = [
      'financeiro'=>'financeiro_dashboard', 'fin_receber'=>'financeiro_receber', 'fin_cartoes'=>'financeiro_receber',
      'fin_pagar'=>'financeiro_pagar', 'fin_fixas'=>'financeiro_despesas_fixas',
      'fin_mov'=>'financeiro_movimentos', 'fin_vales'=>'financeiro_vales', 'fin_boletos'=>'financeiro_boletos', 'fin_conciliacao'=>'financeiro_conciliacao', 'fin_resultado'=>'financeiro_resultado',
    ];
    $items = array_filter($items, function($it, $key) use ($safeKeys, $permissionByKey) {
      if (!in_array((string)$key, $safeKeys, true)) return false;
      $perm = $permissionByKey[(string)$key] ?? '';
      return $perm === '' || !function_exists('multi_company_context_permission_allowed') || multi_company_context_permission_allowed($GLOBALS['pdo'], $perm);
    }, ARRAY_FILTER_USE_BOTH);

    unset($aux['gestao_pagamentos']);
    $aux['fin_pagamentos'] = ['Formas de Pagamento', 'financeiro_formas_pagamento.php'];
    $safeAux = ['fin_plano','fin_centro','fin_caixas','fin_pagamentos'];
    $permissionByAux = [
      'fin_plano'=>'financeiro_plano', 'fin_centro'=>'financeiro_centro', 'fin_caixas'=>'financeiro_caixas',
      'fin_pagamentos'=>'gestao_pagamentos',
    ];
    $aux = array_filter($aux, function($it, $key) use ($safeAux, $permissionByAux) {
      if (!in_array((string)$key, $safeAux, true)) return false;
      $perm = $permissionByAux[(string)$key] ?? '';
      return $perm === '' || !function_exists('multi_company_context_permission_allowed') || multi_company_context_permission_allowed($GLOBALS['pdo'], $perm);
    }, ARRAY_FILTER_USE_BOTH);
  } elseif (function_exists('saas_admin_nav_allowed') && defined('COMPANY_ID') && isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
    $pdoTabs = $GLOBALS['pdo'];
    $items = array_filter($items, fn($it, $key) => saas_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key) && (!function_exists('access_admin_nav_allowed') || access_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key)), ARRAY_FILTER_USE_BOTH);
    $aux = array_filter($aux, fn($it, $key) => saas_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key) && (!function_exists('access_admin_nav_allowed') || access_admin_nav_allowed($pdoTabs, (int)COMPANY_ID, (string)$key)), ARRAY_FILTER_USE_BOTH);
  }
  $auxActive = array_key_exists($active, $aux);
  // Caixas/Bancos abre com o menu auxiliar recolhido para manter a tela limpa.
  // O item continua destacado quando o usuário abrir o menu manualmente.
  $auxOpen = $auxActive && $active !== 'fin_caixas';
  echo '<div class="finance-tabs">';
  foreach ($items as $key => $it) {
    $cls = $key === $active ? 'active' : '';
    $href = (string)$it[1];
    if ($cross && function_exists('multi_company_context_url')) $href = multi_company_context_url($href);
    echo '<a class="' . $cls . '" href="' . finance_h($href) . '">' . finance_h($it[0]) . '</a>';
  }
  if ($aux) {
    echo '<details class="finance-tab-menu"' . ($auxOpen ? ' open' : '') . '><summary class="' . ($auxActive ? 'active' : '') . '">Tabelas Auxiliares</summary><div class="finance-tab-panel">';
    foreach ($aux as $key => $it) {
      $cls = $key === $active ? 'active' : '';
      $href = (string)$it[1];
      if ($cross && function_exists('multi_company_context_url')) $href = multi_company_context_url($href);
      echo '<a class="' . $cls . '" href="' . finance_h($href) . '">' . finance_h($it[0]) . '</a>';
    }
    echo '</div></details>';
  }
  echo '</div>';
}

function finance_page_header(string $title, string $description, array $actions = []): void {
  $cross = isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && finance_multi_company_cross_context($GLOBALS['pdo']);
  if ($cross) {
    if (finance_multi_company_page_write_enabled($GLOBALS['pdo'])) {
      // No F11, ações do cabeçalho ficam restritas à própria tela homologada.
      $current = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
      $actions = array_values(array_filter($actions, static function(array $a) use ($current): bool {
        $href = (string)($a['href'] ?? '');
        $parts = parse_url($href);
        $path = strtolower(basename((string)($parts['path'] ?? '')));
        return $path === $current;
      }));
    } else {
      $actions = [];
    }
  }
  echo '<div class="finance-hero"><div><h1>' . finance_h($title) . '</h1><p>' . finance_h($description) . '</p></div>';
  if ($actions) {
    echo '<div class="finance-actions">';
    foreach ($actions as $a) {
      $href = (string)($a['href'] ?? '#');
      if ($cross && function_exists('multi_company_context_url')) $href = multi_company_context_url($href);
      $label = (string)($a['label'] ?? 'Ação');
      $class = (string)($a['class'] ?? 'btn');
      echo '<a class="' . finance_h($class) . '" href="' . finance_h($href) . '">' . finance_h($label) . '</a>';
    }
    echo '</div>';
  }
  echo '</div>';
}

function finance_select_options(array $rows, $selected = null, string $empty = 'Selecione', bool $disableSynthetic = true): void {
  echo '<option value="">' . finance_h($empty) . '</option>';
  foreach ($rows as $r) {
    $id = (int)($r['id'] ?? 0);
    $labelParts = [];
    $classification = trim((string)($r['classification'] ?? ''));
    $code = trim((string)($r['code'] ?? ''));
    if ($classification !== '') $labelParts[] = $classification;
    elseif ($code !== '') $labelParts[] = $code;
    $labelParts[] = (string)($r['name'] ?? '');
    if ($classification !== '' && $code !== '' && $classification !== $code) $labelParts[] = '[' . $code . ']';
    if (isset($r['document']) && trim((string)$r['document']) !== '') $labelParts[] = '(' . (string)$r['document'] . ')';
    $label = implode(' - ', array_filter($labelParts));
    $disabled = ($disableSynthetic && isset($r['is_analytic']) && (int)$r['is_analytic'] !== 1) ? ' disabled' : '';
    $sel = ((string)$selected === (string)$id) ? ' selected' : '';
    echo '<option value="' . $id . '"' . $sel . $disabled . '>' . str_repeat('— ', max(0, (int)($r['level'] ?? 1) - 1)) . finance_h($label) . '</option>';
  }
}

function finance_flash(): void {
  $ok = trim((string)($_GET['ok'] ?? ''));
  $err = trim((string)($_GET['err'] ?? ''));
  if ($ok !== '') echo '<div class="msg ok">' . finance_h($ok) . '</div>';
  if ($err !== '') echo '<div class="msg err">' . finance_h($err) . '</div>';
  if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO && finance_multi_company_cross_context($GLOBALS['pdo'])) {
    $pdo = $GLOBALS['pdo'];
    $page = strtolower(basename($_SERVER['PHP_SELF'] ?? ''));
    $companyName = finance_h(finance_operational_company_name($pdo));
    if ($page === 'financeiro_despesas_fixas.php' && finance_multi_company_fixed_expense_write_enabled($pdo)) {
      echo '<div class="msg ok"><strong>V11.5 · Despesas Fixas Multiempresa:</strong> regras e provisões pertencem a ' . $companyName . '. O horizonte pode ser criado/reposto pelo F11; títulos gerados permanecem vinculados à regra para auditoria.</div>';
    } elseif ($page === 'financeiro_caixas_bancos.php' && finance_multi_company_bank_write_enabled($pdo)) {
      echo '<div class="msg ok"><strong>V11.15 · Caixa/Banco + Cobrança Multiempresa:</strong> os dados cadastrais e a configuração de cobrança/Itaú pertencem a ' . $companyName . '. As credenciais não são compartilhadas entre empresas; configure cada conta da empresa selecionada pelo F11.</div>';
    } elseif ($page === 'financeiro_itau.php' && finance_multi_company_billing_config_enabled($pdo)) {
      echo '<div class="msg ok"><strong>V11.23 · Integração Itaú Multiempresa:</strong> esta configuração pertence exclusivamente a ' . $companyName . '. Certificado, OAuth, Webhook, Beneficiário, Carteira, processamento de retornos e sincronização de liquidações usam a empresa selecionada e exigem as permissões efetivas do F11.</div>';
    } elseif ($page === 'financeiro_plano_contas.php') {
      echo '<div class="msg ok"><strong>V11.4 · Plano de Contas Multiempresa:</strong> inclusões, alterações, importação, exportação e inativação pertencem a ' . $companyName . '.</div>';
    } elseif ($page === 'financeiro_centros_custo.php') {
      echo '<div class="msg ok"><strong>V11.4 · Centro de Custo Multiempresa:</strong> inclusões, alterações e inativações pertencem a ' . $companyName . '.</div>';
    } elseif ($page === 'financeiro_movimentos.php' && finance_multi_company_movement_scope_enabled($pdo)) {
      $manual = finance_multi_company_movement_write_enabled($pdo);
      $cancel = finance_multi_company_movement_cancel_enabled($pdo);
      echo '<div class="msg ok"><strong>V11.23 · Movimento Financeiro Multiempresa:</strong> os movimentos exibidos pertencem a ' . $companyName . '. Lançamentos manuais' . ($manual ? ' estão habilitados' : ' dependem da permissão de lançamento manual') . ' e cancelamentos' . ($cancel ? ' estão habilitados' : ' dependem da permissão de cancelamento') . '; movimentos originados de baixas continuam protegidos.</div>';
    } elseif ($page === 'financeiro_conciliacao.php') {
      if (finance_multi_company_reconciliation_create_movement_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.19 · Conciliação Bancária Multiempresa — Movimento direto:</strong> as contas bancárias e movimentos pertencem a ' . $companyName . '. Além de OFX, título existente, Ignorar/Reabrir e criação de título, agora é possível lançar tarifa, rendimento, transferência ou outro evento diretamente no Movimento Financeiro e conciliá-lo. A operação exige Conciliação + Movimento Financeiro + Lançar movimento manual, CSRF Multiempresa, vínculos da empresa e lock do item.</div>';
      } elseif (finance_multi_company_reconciliation_create_title_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.18 · Conciliação Bancária Multiempresa — Criar título:</strong> as contas bancárias e movimentos pertencem a ' . $companyName . '. Além de OFX, título existente e Ignorar/Reabrir, agora é possível criar um novo Contas a Receber/Pagar, baixá-lo e conciliá-lo no mesmo fluxo protegido. A operação exige permissões do módulo/título/baixa, CSRF Multiempresa, vínculos da empresa e lock do item. Criar movimento financeiro direto continua bloqueado no F11.</div>';
      } elseif (finance_multi_company_reconciliation_status_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.17.1 · Conciliação Bancária Multiempresa — Hotfix de bancos:</strong> as contas bancárias, extratos, importação OFX, conciliação com título existente e mudanças de estado pertencem a ' . $companyName . '. No F11, as contas são carregadas da empresa selecionada e não do vínculo legado user_cash_accounts do tenant de entrada. Ignorar/Reabrir exige permissão de Conciliação, CSRF Multiempresa e proteção por lock do item. Criar título e criar movimento financeiro continuam bloqueados no F11.</div>';
      } elseif (finance_multi_company_reconciliation_settlement_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.16 · Conciliação Bancária Multiempresa — conciliar com título existente:</strong> extratos, importação OFX, pesquisa de títulos e baixa vinculada pertencem a ' . $companyName . '. A baixa exige permissão específica de Contas a Receber/Pagar, valida empresa/conta/título e usa proteção contra duplo processamento. Criar título, criar movimento e ignorar/reabrir continuam bloqueados no F11.</div>';
      } elseif (finance_multi_company_reconciliation_import_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.14 · Conciliação Bancária Multiempresa — importação OFX protegida:</strong> extratos, KPIs, filtros, detalhes e novas importações OFX pertencem a ' . $companyName . '. A conta bancária é validada na empresa selecionada e arquivos duplicados continuam bloqueados. Conciliar, ignorar/reabrir e criar título ou movimento permanecem bloqueados no F11.</div>';
      } else {
        echo '<div class="msg ok"><strong>V11.13 · Conciliação Bancária Multiempresa — consulta protegida:</strong> extratos já importados, KPIs, filtros, detalhes e sugestões pertencem a ' . $companyName . '. Importação OFX, conciliar, ignorar/reabrir e criação de título ou movimento permanecem bloqueados no F11 nesta etapa.</div>';
      }
    } elseif (in_array($page, ['financeiro_boletos.php','financeiro_boleto_itau.php','financeiro_boleto_imprimir.php'], true)) {
      if ($page === 'financeiro_boletos.php') {
        echo '<div class="msg ok"><strong>V11.23 · Boletos/Itaú Multiempresa:</strong> os dados pertencem a ' . $companyName . '. Consulta, auditoria, impressão, Simulação, Registro, Reprocessamento, estados incertos, configuração Itaú por conta, liquidações, cancelamento local pré-registro e WhatsApp/E-mail estão homologados no F11. Somente a baixa/cancelamento bancário de boleto já registrado permanece fora do motor atual.</div>';
      } else {
        echo '<div class="msg ok"><strong>V11.12 · Boletos/Itaú Multiempresa — auditoria protegida:</strong> os dados pertencem a ' . $companyName . '. Visualização, auditoria e impressão estão homologadas; registro/reprocessamento e acompanhamento de estados incertos são executados somente pela fila protegida de Cobranças/Boletos.</div>';
      }
    } elseif (in_array($page, ['financeiro_recibo_baixa.php','financeiro_recibo_whatsapp.php'], true)) {
      if (finance_multi_company_receipt_send_enabled($pdo)) {
        echo '<div class="msg ok"><strong>V11.23 · Recibo de Baixa Multiempresa:</strong> este recibo pertence a ' . $companyName . '. Visualização, impressão, WhatsApp e E-mail usam exclusivamente os dados e configurações da empresa selecionada pelo F11.</div>';
      } else {
        echo '<div class="msg ok"><strong>V11.23 · Recibo de Baixa Multiempresa:</strong> este recibo pertence a ' . $companyName . '. Visualização e impressão estão disponíveis; o envio depende da permissão de Contas a Receber nesta empresa.</div>';
      }
    } elseif (finance_multi_company_settlement_write_enabled($pdo)) {
      echo '<div class="msg ok"><strong>V11.23.1 · Financeiro Multiempresa — hotfix Caixa/Banco na baixa:</strong> os títulos e os caixas/bancos disponíveis na baixa pertencem a ' . $companyName . '. No F11, a seleção de Caixa/Banco não depende mais do vínculo legado user_cash_accounts da empresa de entrada; a permissão efetiva de baixa e a validação da conta na empresa selecionada continuam obrigatórias.</div>';
    } elseif (finance_multi_company_title_write_enabled($pdo)) {
      echo '<div class="msg ok"><strong>V11.1 · Financeiro Multiempresa — títulos:</strong> os dados pertencem a ' . $companyName . '. Criação e edição de títulos ABERTOS estão habilitadas.</div>';
    } else {
      echo '<div class="msg ok"><strong>V11 · Financeiro Multiempresa — consulta:</strong> os dados exibidos pertencem a ' . $companyName . '. Esta tela permanece somente consulta no contexto F11.</div>';
    }
  }
}

function finance_status_badge(string $status): string {
  return '<span class="finance-status ' . finance_h($status) . '">' . finance_h($status) . '</span>';
}

function finance_roles_badges(array $row): string {
  $out = [];
  if (!empty($row['is_customer'])) $out[] = '<span class="finance-badge green">Cliente</span>';
  if (!empty($row['is_supplier'])) $out[] = '<span class="finance-badge">Fornecedor</span>';
  if (!empty($row['is_seller'])) $out[] = '<span class="finance-badge gray">Vendedor</span>';
  if (!empty($row['is_employee'])) $out[] = '<span class="finance-badge gray">Colaborador</span>';
  if (!empty($row['is_professional'])) $out[] = '<span class="finance-badge gray">Profissional</span>';
  if (!empty($row['is_carrier'])) $out[] = '<span class="finance-badge gray">Transportadora</span>';
  return $out ? implode('', $out) : '<span class="finance-muted">Sem papel</span>';
}

function finance_admin_end(): void {
  echo '</div>';
  admin_footer();
}
