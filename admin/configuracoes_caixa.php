<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/cash_settings.php';

// Configurações de caixa: "Desabilitar caixa usuário" e planos de contas padrão
// de transferência, sangria e aporte. Sempre na empresa de login (sem F11).
$cid = (int)COMPANY_ID;
$err = '';
$cashCfgCross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid;
$cashCfgCanSave = !$cashCfgCross && (
  (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo, $cid, 'configuracoes_caixa'))
);
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_cash_cfg_csrf'])) {
  $_SESSION['nexa_cash_cfg_csrf'] = bin2hex(random_bytes(20));
}
$cashCfgCsrf = (string)($_SESSION['nexa_cash_cfg_csrf'] ?? '');

$chartsDebito = finance_chart_options($pdo, $cid, 'DEBITO');
$chartsCredito = finance_chart_options($pdo, $cid, 'CREDITO');
$validChart = static function (int $chartId, array $rows): bool {
  if ($chartId === 0) return true;
  foreach ($rows as $r) if ((int)$r['id'] === $chartId) return (int)($r['is_analytic'] ?? 0) === 1;
  return false;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!$cashCfgCanSave) throw new RuntimeException('Seu usuário não possui permissão para alterar as configurações de caixa desta empresa.');
    if ($cashCfgCsrf === '' || !hash_equals($cashCfgCsrf, (string)($_POST['cash_cfg_csrf'] ?? ''))) {
      throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
    $new = [
      'chart_transfer_out' => (int)($_POST['chart_transfer_out'] ?? 0),
      'chart_transfer_in' => (int)($_POST['chart_transfer_in'] ?? 0),
      'chart_sangria' => (int)($_POST['chart_sangria'] ?? 0),
      'chart_aporte' => (int)($_POST['chart_aporte'] ?? 0),
    ];
    if (!$validChart($new['chart_transfer_out'], $chartsDebito)) throw new RuntimeException('Plano de contas da transferência (saída) inválido: escolha uma conta analítica de débito.');
    if (!$validChart($new['chart_transfer_in'], $chartsCredito)) throw new RuntimeException('Plano de contas da transferência (entrada) inválido: escolha uma conta analítica de crédito.');
    if (!$validChart($new['chart_sangria'], $chartsDebito)) throw new RuntimeException('Plano de contas da sangria inválido: escolha uma conta analítica de débito.');
    if (!$validChart($new['chart_aporte'], $chartsCredito)) throw new RuntimeException('Plano de contas do aporte inválido: escolha uma conta analítica de crédito.');
    nexa_cash_settings_save($pdo, $cid, $new);
    finance_redirect('configuracoes_caixa.php?ok=' . rawurlencode('Configurações de caixa salvas.'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = nexa_cash_settings($pdo, $cid, true);

finance_admin_start('Configurações de caixa', 'configuracoes_caixa');
finance_page_header('Configurações de caixa', 'Planos de contas padrão para transferência, sangria e aporte.');
finance_flash();
if ($err !== '') echo '<div class="msg err">' . finance_h($err) . '</div>';
if ($cashCfgCross) echo '<div class="msg err">Estas configurações pertencem à empresa de login. Saia do contexto Multiempresa (F11) para alterá-las.</div>';
?>
<style>
.cashcfg-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.cashcfg-option{border:1px solid #dbe4f0;border-radius:18px;background:#f8fbff;padding:14px;min-width:0}.cashcfg-option label{display:block;font-weight:900;color:#0f172a;font-size:13px;margin-bottom:6px}.cashcfg-option select{width:100%}.cashcfg-option small{display:block;color:#64748b;line-height:1.4;margin-top:6px}.finance-check{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:16px;background:#f8fbff;padding:12px}.finance-check input{width:auto;margin-top:3px}.finance-check span{color:#334155}@media(max-width:780px){.cashcfg-grid{grid-template-columns:1fr}}
</style>

<form method="post" class="finance-form">
  <input type="hidden" name="cash_cfg_csrf" value="<?= finance_h($cashCfgCsrf) ?>">
  <div class="finance-card"><div class="finance-muted">Para exigir abertura e fechamento de caixa de um usuário, marque <b>Ativar Caixa Terminal</b> no cadastro dele em Usuários. Os demais usuários vendem e lançam sem abrir caixa.</div></div>

  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Planos de contas padrão</h2><div class="finance-muted">Já vêm preenchidos nas telas; podem ser trocados em cada lançamento.</div></div></div>
    <div class="cashcfg-grid">
      <div class="cashcfg-option"><label>Transferência · saída (conta de origem)</label><select name="chart_transfer_out"><?php finance_select_options($chartsDebito, (int)$cfg['chart_transfer_out'] ?: null, 'Sem padrão'); ?></select><small>Débito lançado na conta de origem da transferência.</small></div>
      <div class="cashcfg-option"><label>Transferência · entrada (conta de destino)</label><select name="chart_transfer_in"><?php finance_select_options($chartsCredito, (int)$cfg['chart_transfer_in'] ?: null, 'Sem padrão'); ?></select><small>Crédito lançado na conta de destino da transferência.</small></div>
      <div class="cashcfg-option"><label>Sangria / retirada</label><select name="chart_sangria"><?php finance_select_options($chartsDebito, (int)$cfg['chart_sangria'] ?: null, 'Sem padrão'); ?></select><small>Usado na Retirada/Sangria da tela Caixa.</small></div>
      <div class="cashcfg-option"><label>Aporte / suprimento</label><select name="chart_aporte"><?php finance_select_options($chartsCredito, (int)$cfg['chart_aporte'] ?: null, 'Sem padrão'); ?></select><small>Usado no Reforço/Suprimento da tela Caixa.</small></div>
    </div>
  </div>

  <div class="finance-form-actions"><a class="btn" href="financeiro_movimentos.php">Movimento financeiro</a><?php if ($cashCfgCanSave): ?><button class="btn btn-primary" type="submit">Salvar configurações de caixa</button><?php endif; ?></div>
</form>
<?php finance_admin_end(); ?>
