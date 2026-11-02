<?php
declare(strict_types=1);
/** V11.61: Exclusividade de CAIXA físico por empresa/operador; BANCO/PIX/CARTAO não são exclusivos. */
if (!function_exists('nexa_cash_operator_conflict')) {
  function nexa_cash_operator_conflict(PDO $pdo, int $companyId, int $bankId, int $userId): ?array {
    if ($companyId <= 0 || $bankId <= 0 || $userId === 0) return ['name'=>'Operador inválido'];
    $st=$pdo->prepare("SELECT id,type FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1");
    $st->execute([$companyId,$bankId]); $bank=$st->fetch(PDO::FETCH_ASSOC);
    if (!$bank) return ['name'=>'Caixa/Banco inexistente ou inativo'];
    if (strtoupper((string)$bank['type']) !== 'CAIXA') return null;
    // Uma sessão ativa de outro operador sempre impede reutilização do caixa físico.
    if (!function_exists('finance_table_exists') || finance_table_exists($pdo, 'gestao_cash_sessions')) {
    $st=$pdo->prepare("SELECT s.user_id,COALESCE(u.name,'Outro operador') name FROM gestao_cash_sessions s LEFT JOIN users u ON u.id=s.user_id AND u.company_id=s.company_id WHERE s.company_id=? AND s.bank_account_id=? AND s.status='ABERTO' AND s.user_id<>? ORDER BY s.id DESC LIMIT 1");
    $st->execute([$companyId,$bankId,$userId]);$other=$st->fetch(PDO::FETCH_ASSOC);
    if ($other) return $other;
    }
    // A vinculação exclusiva também vale para permissões e caixas padrão (PDV/Comanda/Financeiro).
    $st=$pdo->prepare("SELECT u.id AS user_id,u.name FROM users u WHERE u.company_id=? AND u.id<>? AND u.is_active=1 AND (u.default_cash_bank_account_id=? OR u.default_pdv_cash_bank_account_id=? OR u.default_comanda_cash_bank_account_id=? OR EXISTS(SELECT 1 FROM user_cash_accounts a WHERE a.company_id=u.company_id AND a.user_id=u.id AND a.bank_account_id=?)) ORDER BY u.id LIMIT 1");
    $st->execute([$companyId,$userId,$bankId,$bankId,$bankId,$bankId]);return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}
if (!function_exists('nexa_cash_assert_operator')) {
  function nexa_cash_assert_operator(PDO $pdo, int $companyId, int $bankId, int $userId): void {
    $conflict=nexa_cash_operator_conflict($pdo,$companyId,$bankId,$userId);
    if ($conflict) throw new RuntimeException('Este caixa físico está vinculado ou aberto por '.(string)($conflict['name']??'outro operador').'. Selecione outro caixa ou peça ao administrador para revisar os vínculos em Usuários.');
  }
}
