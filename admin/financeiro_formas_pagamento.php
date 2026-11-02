<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$cross = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
if (!$cross) {
  // Fora do F11 preserva integralmente o cadastro operacional já existente.
  finance_redirect('gestao_formas_pagamento.php');
}
$err = '';
if (!function_exists('multi_company_scope_is_cross_company') || !multi_company_scope_is_cross_company($pdo, 'finance_payment_methods')) {
  $err = 'V11.6: a parametrização financeira de Formas de Pagamento não está habilitada neste contexto.';
}
if (!finance_table_exists($pdo, 'finance_payment_methods')) {
  $err = 'Cadastro operacional de Formas de Pagamento não encontrado. Esta tela não cria a estrutura do PDV.';
}
if (!finance_table_exists($pdo, 'finance_payment_method_defaults')) {
  $err = 'A migration V11.6 ainda não foi aplicada. Execute 20260917_multiempresa_financeiro_v11_6.sql.';
}

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($err !== '') throw new RuntimeException($err);
    $action = strtolower(trim((string)($_POST['action'] ?? '')));
    $methodId = (int)($_POST['payment_method_id'] ?? 0);
    if ($methodId <= 0) throw new RuntimeException('Forma de pagamento inválida.');
    $method = finance_query_one($pdo, 'SELECT id,name,type,is_active FROM finance_payment_methods WHERE company_id=? AND id=? LIMIT 1', [$cid,$methodId]);
    if (!$method) throw new RuntimeException('A forma de pagamento não pertence à empresa selecionada pelo F11.');

    if ($action === 'reset_defaults') {
      $pdo->prepare('DELETE FROM finance_payment_method_defaults WHERE company_id=? AND payment_method_id=?')->execute([$cid,$methodId]);
      finance_redirect('financeiro_formas_pagamento.php?ok=' . rawurlencode('Parametrização financeira removida. O Financeiro voltou a usar os padrões operacionais da forma.'));
    }
    if ($action !== 'save_defaults') throw new RuntimeException('Ação não permitida.');

    $bankId=(int)($_POST['bank_account_id']??0) ?: null;
    if ($bankId && !finance_validate_bank_account($pdo,$cid,$bankId)) throw new RuntimeException('Caixa/Banco inválido ou pertencente a outra empresa.');
    $uid=function_exists('auth_user_id')?(int)(auth_user_id()??0):0;
    $sql='INSERT INTO finance_payment_method_defaults (company_id,payment_method_id,bank_account_id,updated_by_user_id,created_at,updated_at) VALUES (?,?,?,?,NOW(),NOW()) ON DUPLICATE KEY UPDATE bank_account_id=VALUES(bank_account_id),updated_by_user_id=VALUES(updated_by_user_id),updated_at=NOW()';
    $pdo->prepare($sql)->execute([$cid,$methodId,$bankId,$uid>0?$uid:null]);
    finance_redirect('financeiro_formas_pagamento.php?ok=' . rawurlencode('Parametrização financeira salva sem alterar o cadastro operacional do PDV.'));
  }
} catch (Throwable $e) { $err=$e->getMessage(); }

$methods=[];$banks=[];
if ($err === '' || finance_table_exists($pdo,'finance_payment_methods')) {
  try {
    $methods=finance_query_all($pdo, "SELECT pm.id,pm.code,pm.name,pm.type,pm.is_active,pm.default_bank_account_id AS operational_bank_id,fd.bank_account_id FROM finance_payment_methods pm LEFT JOIN finance_payment_method_defaults fd ON fd.company_id=pm.company_id AND fd.payment_method_id=pm.id WHERE pm.company_id=? ORDER BY pm.sort_order,pm.name,pm.id", [$cid]);
    $banks=finance_bank_options($pdo,$cid);
  } catch(Throwable $e) { if($err==='')$err=$e->getMessage(); }
}

finance_admin_start('Financeiro - Formas de Pagamento', 'fin_pagamentos');
finance_page_header('Formas de Pagamento · Financeiro', 'Parametrize apenas os padrões do Financeiro para a empresa selecionada no F11. O cadastro operacional usado por PDV/NFC-e/Boletos não é alterado.');
finance_tabs('fin_pagamentos'); finance_flash();
if($err!=='') echo '<div class="msg err">'.finance_h($err).'</div>';
?>
<div class="msg ok"><strong>V11.6 · camada isolada.</strong> Nome, código, tipo, status, parcelamento, geração de contas, NFC-e e configuração de boleto continuam pertencendo ao cadastro operacional. Aqui é gravado somente o Caixa/Banco padrão usado nas baixas do Financeiro.</div>
<div class="finance-card"><div class="table-wrap"><table class="finance-list-table"><thead><tr><th>Forma operacional</th><th>Padrões operacionais atuais</th><th>Sobreposição financeira</th><th>Ações</th></tr></thead><tbody>
<?php if(!$methods): ?><tr><td colspan="4" class="finance-empty">Nenhuma forma de pagamento já cadastrada nesta empresa. Por segurança, o F11 não cria formas operacionais do PDV.</td></tr><?php endif; ?>
<?php foreach($methods as $m): ?>
<tr>
<td data-label="Forma"><strong><?= finance_h($m['name']??'') ?></strong><div class="finance-muted"><?= finance_h($m['code']??'') ?> · <?= finance_h($m['type']??'') ?> · <?= !empty($m['is_active'])?'Ativa':'Inativa' ?></div></td>
<td data-label="Operacional" class="finance-muted">Caixa/Banco padrão #<?= (int)($m['operational_bank_id']??0) ?: '—' ?></td>
<td data-label="Financeiro">
<form method="post" class="finance-form" style="margin:0"><input type="hidden" name="action" value="save_defaults"><input type="hidden" name="payment_method_id" value="<?= (int)$m['id'] ?>">
<div class="finance-form-grid">
<div><label>Caixa/Banco padrão do Financeiro</label><select name="bank_account_id"><?php finance_select_options($banks,$m['bank_account_id']??'','Usar padrão operacional'); ?></select></div>
</div>
<div class="finance-form-actions" style="margin-top:8px;padding-top:8px"><button class="btn btn-primary">Salvar financeiro</button></div></form>
</td>
<td data-label="Ações"><form method="post" onsubmit="return confirm('Remover a parametrização financeira desta forma e voltar aos padrões operacionais?');"><input type="hidden" name="action" value="reset_defaults"><input type="hidden" name="payment_method_id" value="<?= (int)$m['id'] ?>"><button class="btn">Usar padrão operacional</button></form></td>
</tr>
<?php endforeach; ?></tbody></table></div></div>
<?php finance_admin_end(); ?>
