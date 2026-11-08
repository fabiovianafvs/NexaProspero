<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/cpa_settings.php';

// Configurações CPA (Contas a Pagar). Sempre na empresa de login (sem F11).
$cid = (int)COMPANY_ID;
$err = '';
$cpaCross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $cid;
$cpaCanSave = !$cpaCross && (
  (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo, $cid, 'configuracoes_cpa'))
);
if (session_status() === PHP_SESSION_ACTIVE && empty($_SESSION['nexa_cpa_cfg_csrf'])) {
  $_SESSION['nexa_cpa_cfg_csrf'] = bin2hex(random_bytes(20));
}
$cpaCsrf = (string)($_SESSION['nexa_cpa_cfg_csrf'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if (!$cpaCanSave) throw new RuntimeException('Seu usuário não possui permissão para alterar as configurações CPA desta empresa.');
    if ($cpaCsrf === '' || !hash_equals($cpaCsrf, (string)($_POST['cpa_csrf'] ?? ''))) {
      throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
    nexa_cpa_settings_save($pdo, $cid, ['settle_individually' => !empty($_POST['settle_individually'])]);
    finance_redirect('configuracoes_cpa.php?ok=' . rawurlencode('Configurações CPA salvas com sucesso.'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}

$cfg = nexa_cpa_settings($pdo, $cid, true);

finance_admin_start('Configurações CPA', 'configuracoes_cpa');
finance_page_header('Configurações CPA', 'Comportamento das baixas do Contas a Pagar.');
finance_flash();
if ($err !== '') echo '<div class="msg err">' . finance_h($err) . '</div>';
if ($cpaCross) echo '<div class="msg err">Estas configurações pertencem à empresa de login. Saia do contexto Multiempresa (F11) para alterá-las.</div>';
?>
<style>.finance-check{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:16px;background:#f8fbff;padding:12px}.finance-check input{width:auto;margin-top:3px}.finance-check span{color:#334155}</style>
<form method="post" class="finance-form">
  <input type="hidden" name="cpa_csrf" value="<?= finance_h($cpaCsrf) ?>">
  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Configurações de baixa</h2><div class="finance-muted">Vale para “Baixar selecionados” no Contas a Pagar.</div></div></div>
    <label class="finance-check"><input type="checkbox" name="settle_individually" value="1" <?= !empty($cfg['settle_individually']) ? 'checked' : '' ?>><span><strong>Mostrar/lançar parcelas quitadas separadamente no movimento financeiro</strong><br><small>Marcado: cada conta paga gera o seu próprio lançamento no Movimento Financeiro. Desmarcado: a baixa de várias contas gera um único movimento com o valor total e identifica as parcelas nas observações.</small></span></label>
  </div>
  <div class="finance-form-actions"><a class="btn" href="financeiro_contas_pagar.php">Contas a Pagar</a><?php if ($cpaCanSave): ?><button class="btn btn-primary" type="submit">Salvar configurações CPA</button><?php endif; ?></div>
</form>
<?php finance_admin_end(); ?>
