<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/screen_performance.php';

$cid = (int)COMPANY_ID;
$allowed = (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo, $cid, 'configuracoes'));
if (!$allowed) {
  if (function_exists('access_render_denied')) access_render_denied('Seu usuário não possui permissão para alterar as configurações gerais.');
  http_response_code(403); exit('Acesso restrito.');
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    nexa_screen_performance_save($pdo, $cid, $_POST);
    finance_redirect('configuracoes_desempenho.php?ok=' . rawurlencode('Períodos iniciais salvos com sucesso.'));
  } catch (Throwable $e) {
    $err = $e->getMessage();
  }
}
$cfg = nexa_screen_performance_get($pdo, $cid);

finance_admin_start('Desempenho e períodos', 'configuracoes_desempenho');
finance_page_header('Desempenho e períodos', 'Defina quanto histórico cada tela carrega automaticamente ao abrir. Os filtros continuam podendo ser alterados manualmente.');
finance_flash();
if ($err !== '') echo '<div class="msg err">' . finance_h($err) . '</div>';
?>
<style>
.perf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:14px}.perf-box{border:1px solid #dbe4f0;background:#f8fbff;border-radius:18px;padding:16px}.perf-box h3{margin:0 0 5px;color:#0b1f5b}.perf-box p{margin:0 0 12px;color:#64748b;line-height:1.45}.perf-box input{max-width:180px}.perf-note{border:1px solid #bfdbfe;background:#eff6ff;color:#1e3a8a;border-radius:18px;padding:14px;line-height:1.5}.perf-example{margin-top:7px;font-size:12px;color:#475569}@media(max-width:900px){.perf-grid{grid-template-columns:1fr}}
</style>
<form method="post" class="finance-form">
  <div class="finance-card">
    <div class="finance-card-head"><div><h2 class="finance-subtitle">Período inicial das listagens</h2><div class="finance-muted">Configuração independente por empresa. Limite aceito: 0 a 3650 dias.</div></div></div>
    <div class="perf-grid">
      <div class="perf-box">
        <h3>Pedidos</h3><p>Dias exibidos automaticamente na Central de Pedidos.</p>
        <label>Dias iniciais<input type="number" min="0" max="3650" name="orders_days" value="<?= (int)$cfg['orders_days'] ?>" required></label>
        <div class="perf-example">0 = hoje. 10 = hoje + 9 dias anteriores.</div>
      </div>
      <div class="perf-box">
        <h3>Movimento Financeiro</h3><p>Dias exibidos automaticamente no livro de movimentos.</p>
        <label>Dias iniciais<input type="number" min="0" max="3650" name="movements_days" value="<?= (int)$cfg['movements_days'] ?>" required></label>
        <div class="perf-example">O período é aplicado sobre a data do movimento.</div>
      </div>
      <div class="perf-box">
        <h3>Contas a Receber</h3><p>Dias exibidos automaticamente ao entrar na listagem.</p>
        <label>Dias iniciais<input type="number" min="0" max="3650" name="receivables_days" value="<?= (int)$cfg['receivables_days'] ?>" required></label>
        <div class="perf-example">O período inicial é aplicado sobre a data de emissão do título.</div>
      </div>
    </div>
  </div>
  <div class="perf-note"><strong>Importante:</strong> esta configuração controla apenas a abertura inicial das telas. Depois de abrir, você pode alterar as datas normalmente. Em Pedidos e Contas a Receber, se apagar as duas datas e clicar em Filtrar, a consulta pode abranger todo o histórico — use isso somente quando necessário.</div>
  <div class="finance-form-actions"><a class="btn" href="parametros.php">Voltar</a><button type="submit" class="btn btn-primary">Salvar períodos</button></div>
</form>
<?php finance_admin_end(); ?>
