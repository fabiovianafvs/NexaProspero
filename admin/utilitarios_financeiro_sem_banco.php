<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/maintenance_bulk.php';
require_once __DIR__ . '/../config/finance_module.php';
require __DIR__ . '/_chrome.php';

// Utilitário: preenche o Caixa/Banco dos movimentos financeiros que ficaram sem conta.
// Só o administrador da empresa de login (o _bootstrap bloqueia F11 e perfis comuns).
$companyId = (int)COMPANY_ID;
$error = '';
$success = '';

$banks = finance_query_all($pdo, 'SELECT id, name, type FROM finance_bank_accounts WHERE company_id=? AND active=1 ORDER BY name ASC', [$companyId]);
$bankNames = [];
foreach ($banks as $b) $bankNames[(int)$b['id']] = (string)$b['name'];
$defaultBank = finance_default_bank_account_id($pdo, $companyId);

/** Conta sugerida pela origem do movimento (forma de pagamento, sessão de caixa, baixa). 0 = sem pista. */
$suggest = static function (array $m) use ($pdo, $companyId, $bankNames): array {
  $tries = [
    ['Forma de pagamento da venda', 'SELECT pm.default_bank_account_id FROM gestao_order_payments op JOIN finance_payment_methods pm ON pm.company_id=op.company_id AND pm.id=op.payment_method_id WHERE op.company_id=? AND op.finance_movement_id=? LIMIT 1'],
    ['Conta do pagamento da venda', 'SELECT op.bank_account_id FROM gestao_order_payments op WHERE op.company_id=? AND op.finance_movement_id=? LIMIT 1'],
    ['Caixa da sessão', 'SELECT cs.bank_account_id FROM gestao_cash_operations co JOIN gestao_cash_sessions cs ON cs.company_id=co.company_id AND cs.id=co.cash_session_id WHERE co.company_id=? AND co.movement_id=? LIMIT 1'],
    ['Conta da baixa', 'SELECT s.bank_account_id FROM finance_settlements s WHERE s.company_id=? AND s.movement_id=? LIMIT 1'],
    ['Forma de pagamento da baixa', 'SELECT pm.default_bank_account_id FROM finance_settlements s JOIN finance_payment_methods pm ON pm.company_id=s.company_id AND pm.name=s.payment_method WHERE s.company_id=? AND s.movement_id=? LIMIT 1'],
  ];
  foreach ($tries as [$why, $sql]) {
    try {
      $st = $pdo->prepare($sql);
      $st->execute([$companyId, (int)$m['id']]);
      $id = (int)($st->fetchColumn() ?: 0);
      if ($id > 0 && isset($bankNames[$id])) return [$id, $why];
    } catch (Throwable $e) {
      // Tabela do módulo não existe nesta instalação: tenta a próxima pista.
    }
  }
  return [0, ''];
};

$loadRows = static function () use ($pdo, $companyId): array {
  return finance_query_all($pdo, "SELECT m.id, m.movement_date, m.type, m.origin_type, m.description, m.amount, m.status, m.bank_account_id FROM finance_movements m LEFT JOIN finance_bank_accounts b ON b.company_id=m.company_id AND b.id=m.bank_account_id WHERE m.company_id=? AND b.id IS NULL ORDER BY m.movement_date DESC, m.id DESC LIMIT 5000", [$companyId]);
};

try {
  if ($companyId <= 0) throw new RuntimeException('Empresa inválida.');
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nexa_bulk_csrf_check((string)($_POST['csrf'] ?? ''));
    if ((string)($_POST['action'] ?? '') !== 'apply' || (int)($_POST['company_id'] ?? 0) !== $companyId
        || strtoupper(trim((string)($_POST['confirm_text'] ?? ''))) !== 'CORRIGIR') {
      throw new RuntimeException('Confirmação inválida. Digite CORRIGIR.');
    }
    $fallback = (int)($_POST['fallback_bank_id'] ?? 0);
    if (!isset($bankNames[$fallback])) throw new RuntimeException('Escolha o Caixa/Banco para os lançamentos sem pista de origem.');
    $useOrigin = !empty($_POST['use_origin']);
    $pdo->beginTransaction();
    try {
      $upd = $pdo->prepare('UPDATE finance_movements SET bank_account_id=?, updated_at=NOW() WHERE company_id=? AND id=? AND NOT EXISTS (SELECT 1 FROM finance_bank_accounts b WHERE b.company_id=? AND b.id=finance_movements.bank_account_id)');
      $count = 0;
      $porConta = [];
      foreach ($loadRows() as $m) {
        $target = $fallback;
        if ($useOrigin) {
          [$sug] = $suggest($m);
          if ($sug > 0) $target = $sug;
        }
        $upd->execute([$target, $companyId, (int)$m['id'], $companyId]);
        if ($upd->rowCount() > 0) {
          $count++;
          $porConta[$target] = ($porConta[$target] ?? 0) + 1;
        }
      }
      $pdo->commit();
      $parts = [];
      foreach ($porConta as $bid => $q) $parts[] = $q . ' em ' . ($bankNames[$bid] ?? '#' . $bid);
      $success = $count . ' lançamento(s) corrigido(s)' . ($parts ? ': ' . implode(', ', $parts) : '') . '. Valores, datas e títulos não foram alterados.';
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
} catch (Throwable $e) {
  $error = $e->getMessage();
}

$rows = [];
$totals = ['qtd' => 0, 'com_pista' => 0, 'liquido' => 0.0];
$byOrigin = [];
try {
  foreach ($loadRows() as $m) {
    [$sug, $why] = $suggest($m);
    $m['sug'] = $sug;
    $m['why'] = $why;
    $rows[] = $m;
    $totals['qtd']++;
    if ($sug > 0) $totals['com_pista']++;
    $active = in_array(strtoupper(trim((string)($m['status'] ?? ''))), ['', 'ATIVO'], true);
    $signed = $active ? ((string)$m['type'] === 'CREDITO' ? 1 : -1) * (float)$m['amount'] : 0;
    $totals['liquido'] += $signed;
    $o = trim((string)$m['origin_type']) ?: 'SEM ORIGEM';
    if (!isset($byOrigin[$o])) $byOrigin[$o] = ['qtd' => 0, 'valor' => 0.0];
    $byOrigin[$o]['qtd']++;
    $byOrigin[$o]['valor'] += $signed;
  }
} catch (Throwable $e) {
  if ($error === '') $error = $e->getMessage();
}
ksort($byOrigin);
$originLabels = [
  'RECEBER' => 'Baixa de contas a receber', 'PAGAR' => 'Baixa de contas a pagar', 'RECEBER_MULTI' => 'Baixa em lote (receber)', 'PAGAR_MULTI' => 'Baixa em lote (pagar)',
  'PEDIDO' => 'Venda (PDV/pedido/O.S.)', 'MANUAL' => 'Lançamento manual', 'ESTORNO_BAIXA' => 'Estorno de baixa', 'ESTORNO_PEDIDO' => 'Cancelamento de venda',
  'CARTAO' => 'Recebimento de cartão', 'CARTAO_TAXA' => 'Taxa de cartão', 'DEVOLUCAO_CLIENTE' => 'Devolução a cliente', 'OFX' => 'Conciliação bancária (OFX)',
  'BOLETO_TARIFA' => 'Tarifa de boleto', 'TRANSFERENCIA' => 'Transferência',
];
$e = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
$money = static fn($v): string => 'R$ ' . number_format((float)$v, 2, ',', '.');
admin_topbar('Utilitários - Lançamentos sem Caixa/Banco', 'financeiro_sem_banco');
?>
<style>
.sb-panel{background:#fff;border:1px solid #dbe3ee;border-radius:14px;padding:20px;margin:12px 0;box-shadow:0 3px 16px #162b4310}
.sb-stats{display:flex;gap:12px;flex-wrap:wrap}.sb-stat{flex:1;min-width:170px;padding:14px;border:1px solid #e2e8f0;border-radius:12px}.sb-stat strong{font-size:24px;display:block;color:#123964}
.sb-msg{padding:12px;border-radius:10px;margin:12px 0}.sb-err{background:#fef2f2;color:#991b1b}.sb-ok{background:#f0fdf4;color:#166534}
.sb-table{width:100%;border-collapse:collapse;font-size:13px}.sb-table th,.sb-table td{text-align:left;padding:8px 10px;border-bottom:1px solid #e2e8f0}.sb-table th{background:#f1f5f9}.sb-table .num{text-align:right;white-space:nowrap}
.sb-wrap{overflow-x:auto;max-height:440px;overflow-y:auto}.sb-input{border:1px solid #94a3b8;border-radius:8px;padding:9px 10px;max-width:240px}
.sb-form{display:grid;gap:12px;max-width:640px}.sb-form label{font-weight:700}.sb-form select{border:1px solid #94a3b8;border-radius:8px;padding:9px 10px;width:100%}
.sb-muted{color:#64748b}.sb-good{color:#166534;font-weight:700}
</style>
<section class="sb-panel">
<h1>Lançamentos sem Caixa/Banco</h1>
<p>Movimentos financeiros desta empresa (#<?= $companyId ?>) que estão sem Caixa/Banco ou ligados a uma conta que não existe mais. Eles entram no saldo de "Todos", mas não aparecem quando você filtra um banco.</p>
<p class="sb-muted">A correção só preenche o Caixa/Banco. Não altera valor, data, títulos, baixas, caixa nem notas fiscais. Novos lançamentos sem conta passam a ir automaticamente para o Caixa/Banco padrão de <a href="configuracoes_caixa.php">Configurações de caixa</a>.</p>
<?php if ($error !== ''): ?><div class="sb-msg sb-err"><?= $e($error) ?></div><?php endif; ?>
<?php if ($success !== ''): ?><div class="sb-msg sb-ok"><?= $e($success) ?></div><?php endif; ?>
<div class="sb-stats">
  <div class="sb-stat">Lançamentos sem conta<strong><?= (int)$totals['qtd'] ?></strong></div>
  <div class="sb-stat">Com conta descoberta pela origem<strong><?= (int)$totals['com_pista'] ?></strong></div>
  <div class="sb-stat">Valor líquido (ativos)<strong><?= $e($money($totals['liquido'])) ?></strong></div>
</div>
</section>

<?php if ($byOrigin): ?>
<section class="sb-panel">
<h2>De onde vieram</h2>
<div class="sb-wrap"><table class="sb-table"><thead><tr><th>Origem</th><th class="num">Qtd.</th><th class="num">Valor líquido</th></tr></thead><tbody>
<?php foreach ($byOrigin as $o => $info): ?>
<tr><td><?= $e($originLabels[$o] ?? $o) ?> <span class="sb-muted">(<?= $e($o) ?>)</span></td><td class="num"><?= (int)$info['qtd'] ?></td><td class="num"><?= $e($money($info['valor'])) ?></td></tr>
<?php endforeach; ?>
</tbody></table></div>
</section>

<section class="sb-panel">
<h2>Prévia</h2>
<div class="sb-wrap"><table class="sb-table"><thead><tr><th>#</th><th>Data</th><th>Tipo</th><th>Origem</th><th>Histórico</th><th class="num">Valor</th><th>Conta descoberta</th></tr></thead><tbody>
<?php foreach (array_slice($rows, 0, 500) as $m): ?>
<tr>
  <td><?= (int)$m['id'] ?></td>
  <td><?= $e(date('d/m/Y', strtotime((string)$m['movement_date']) ?: time())) ?></td>
  <td><?= (string)$m['type'] === 'CREDITO' ? 'Crédito' : 'Débito' ?><?= in_array(strtoupper(trim((string)($m['status'] ?? ''))), ['', 'ATIVO'], true) ? '' : ' <span class="sb-muted">(cancelado)</span>' ?></td>
  <td><?= $e($m['origin_type']) ?></td>
  <td><?= $e($m['description']) ?></td>
  <td class="num"><?= $e($money($m['amount'])) ?></td>
  <td><?php if ($m['sug'] > 0): ?><span class="sb-good"><?= $e($bankNames[$m['sug']] ?? '') ?></span> <span class="sb-muted">· <?= $e($m['why']) ?></span><?php else: ?><span class="sb-muted">— vai para a conta escolhida abaixo</span><?php endif; ?></td>
</tr>
<?php endforeach; ?>
</tbody></table></div>
<?php if (count($rows) > 500): ?><p class="sb-muted">Exibindo os primeiros 500. A correção vale para todos.</p><?php endif; ?>
</section>

<?php if ($banks): ?>
<section class="sb-panel">
<h2>Corrigir</h2>
<form method="post" class="sb-form" onsubmit="return confirm('Confirma o preenchimento do Caixa/Banco desses lançamentos?');">
  <input type="hidden" name="csrf" value="<?= $e(nexa_bulk_csrf_token()) ?>">
  <input type="hidden" name="action" value="apply"><input type="hidden" name="company_id" value="<?= $companyId ?>">
  <label><input type="checkbox" name="use_origin" value="1" checked> Usar a conta descoberta pela origem quando houver (forma de pagamento, sessão de caixa ou baixa)</label>
  <div><label>Caixa/Banco para os demais lançamentos</label>
    <select name="fallback_bank_id" required><option value="">Selecione</option><?php foreach ($banks as $b): ?><option value="<?= (int)$b['id'] ?>" <?= (int)$b['id'] === $defaultBank ? 'selected' : '' ?>><?= $e($b['name']) ?></option><?php endforeach; ?></select></div>
  <div><label>Digite <strong>CORRIGIR</strong> para confirmar</label><br>
    <input class="sb-input" name="confirm_text" autocomplete="off" required placeholder="CORRIGIR" pattern="[Cc][Oo][Rr][Rr][Ii][Gg][Ii][Rr]">
    <button class="btn btn-primary" type="submit">Preencher Caixa/Banco</button></div>
</form>
</section>
<?php else: ?>
<section class="sb-panel"><div class="sb-msg sb-err">Cadastre um Caixa/Banco ativo em Financeiro &gt; Caixas e bancos antes de corrigir.</div></section>
<?php endif; ?>
<?php else: ?>
<section class="sb-panel"><p class="sb-good">Nenhum lançamento sem Caixa/Banco nesta empresa.</p></section>
<?php endif; ?>
<?php admin_footer(); ?>
