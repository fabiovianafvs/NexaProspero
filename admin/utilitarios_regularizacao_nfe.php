<?php
declare(strict_types=1);

/**
 * Habilitacao administrativa do piloto de descarte de NF-e rejeitada por empresa.
 * Nao transmite, altera, exclui ou consulta documentos fiscais.
 * O bootstrap proibe perfil comum e qualquer mudanca via F11.
 */
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/order_rejected_nfe_discard.php';

$companyId = (int)COMPANY_ID;
$userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
$cross = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && defined('MULTI_COMPANY_CONTEXT_ID') && (int)MULTI_COMPANY_CONTEXT_ID !== $companyId;
if ($companyId <= 0 || $userId <= 0 || $cross
    || !function_exists('access_is_company_master') || !access_is_company_master()) {
  http_response_code(403);
  exit('Acesso negado. A configuracao e exclusiva do administrador da empresa de entrada.');
}

function nfe_flag_h($text): string {
  return htmlspecialchars((string)$text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

if (empty($_SESSION['nexa_nfe_flag_csrf'])) {
  $_SESSION['nexa_nfe_flag_csrf'] = bin2hex(random_bytes(32));
}

$error = '';
$success = (string)($_SESSION['nexa_nfe_flag_success'] ?? '');
unset($_SESSION['nexa_nfe_flag_success']);
$companyName = '';
$setting = null;
$enabled = false;
$source = '';
$canSave = true;

try {
  $q = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $q->execute([$companyId]);
  $companyName = (string)$q->fetchColumn();
  if ($companyName === '') throw new RuntimeException('Empresa nao localizada no banco de dados.');
  // Falha se nao existir app_config: NUNCA criamos tabela automaticamente em producao.
  $setting = nexa_rejected_nfe_setting($pdo, $companyId);
  $enabled = nexa_rejected_nfe_discard_enabled($companyId, $pdo);
  $source = $setting !== null ? 'Configuração salva pela tela' : 'Configuração anterior (arquivo legado)';
  if ($setting !== null && !empty($setting['invalid'])) {
    throw new RuntimeException('Configuração anterior inválida. Desabilitada por segurança.');
  }
} catch (Throwable $e) {
  $error = 'Não foi possível ler a configuração. Verifique a tabela app_config no banco e os logs do servidor.';
  error_log('[NEXA_NFE_FLAG_SCREEN_READ] company='.$companyId.' '.$e->getMessage());
  $canSave = false;
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!$canSave) throw new RuntimeException('Configuração indisponível; nenhuma alteração foi aplicada.');
    if (!hash_equals((string)($_SESSION['nexa_nfe_flag_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
      throw new RuntimeException('Sessão inválida. Atualize a página e tente novamente.');
    }
    if ((string)($_POST['action'] ?? '') !== 'save' || !in_array((string)($_POST['enabled'] ?? ''), ['0','1'], true)) {
      throw new RuntimeException('Escolha se deseja habilitar ou desabilitar a regularização.');
    }
    $newEnabled = (string)$_POST['enabled'] === '1';
    if ($newEnabled) {
      if ((string)($_POST['acknowledge'] ?? '') !== '1'
          || strtoupper(trim((string)($_POST['confirmation'] ?? ''))) !== 'HABILITAR') {
        throw new RuntimeException('Para habilitar, confirme a responsabilidade de conferência fiscal e digite HABILITAR.');
      }
    }
    nexa_rejected_nfe_setting_save($pdo, $companyId, $newEnabled, $userId);
    // CSRF renovado apos salvar. Evita reenvio POST / formulario antigo.
    $_SESSION['nexa_nfe_flag_csrf'] = bin2hex(random_bytes(32));
    $_SESSION['nexa_nfe_flag_success'] = $newEnabled
      ? 'Piloto de regularização fiscal HABILITADO para esta empresa.'
      : 'Piloto de regularização fiscal DESABILITADO para esta empresa.';
    header('Location: utilitarios_regularizacao_nfe.php', true, 303);
    exit;
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    $error = $e->getMessage();
    error_log('[NEXA_NFE_FLAG_SCREEN_BLOCK] company='.$companyId.' user='.$userId.' '.$e->getMessage());
  }
}

$history = $setting !== null && isset($setting['history']) && is_array($setting['history'])
  ? array_slice(array_reverse($setting['history']), 0, 10) : [];

admin_topbar('Utilitários - Regularização de NF-e rejeitada', 'regularizacao_nfe_rejeitada');
?>
<style>
.nfe-flag-container{max-width:990px;margin:10px auto 30px;color:#14203b}
.nfe-flag-header h1{color:#0b1f5b;font-size:26px;margin:0 0 8px}
.nfe-flag-header p{color:#475569;font-size:14px;line-height:1.65;margin:0 0 18px}
.nfe-flag-box{background:#fff;border:1px solid #d5dfef;border-radius:14px;padding:20px;margin-bottom:16px;box-shadow:0 5px 20px rgba(15,35,73,.04)}
.nfe-flag-row{display:flex;align-items:center;justify-content:space-between;gap:15px;flex-wrap:wrap}
.nfe-flag-title{font-size:17px;font-weight:750;color:#0b1f5b;margin:0 0 10px}
.nfe-flag-note{font-size:13px;color:#475569;line-height:1.65;margin:5px 0}
.nfe-flag-pill{display:inline-flex;font-size:12px;font-weight:800;border-radius:20px;padding:7px 12px}
.nfe-flag-on{background:#dcfce7;color:#166534}
.nfe-flag-off{background:#f1f5f9;color:#475569}
.nfe-flag-help{background:#f7faff;border-left:4px solid #2454d6;padding:12px 14px;border-radius:8px;font-size:13px;line-height:1.65;color:#334155;margin:14px 0}
.nfe-flag-choices{display:flex;gap:12px;flex-wrap:wrap;margin:12px 0}
.nfe-flag-choice{flex:1 1 220px;border:1px solid #cbd5e1;border-radius:10px;padding:12px 14px;display:flex;gap:9px;cursor:pointer;font-weight:700;font-size:14px}
.nfe-flag-choice input{accent-color:#2454d6}
.nfe-flag-confirm{display:flex;gap:10px;align-items:flex-start;font-size:13px;color:#334155;line-height:1.5;margin:14px 0}
.nfe-flag-confirm input{margin-top:3px;accent-color:#2454d6}
.nfe-flag-input{height:42px;padding:8px 12px;max-width:280px;width:100%;border:1px solid #94a3b8;border-radius:8px;font-size:14px}
.nfe-flag-button{background:#0b1f5b;border:0;color:white;font-weight:700;font-size:14px;border-radius:9px;padding:12px 20px;cursor:pointer}
.nfe-flag-button:disabled{background:#94a3b8;cursor:not-allowed}
.nfe-flag-msg{border-radius:9px;border:1px solid #fecaca;background:#fef2f2;padding:11px 13px;color:#991b1b;margin-bottom:14px;font-size:13px}
.nfe-flag-ok{border-color:#bbf7d0;background:#f0fdf4;color:#166534}
.nfe-flag-table{width:100%;border-collapse:collapse;font-size:12px}
.nfe-flag-table td,.nfe-flag-table th{text-align:left;padding:9px 11px;border-bottom:1px solid #e2e8f0}
.nfe-flag-table th{background:#f8fafc;color:#475569}
@media(max-width:600px){.nfe-flag-box{padding:15px}.nfe-flag-header h1{font-size:22px}}
</style>
<main class="nfe-flag-container">
  <div class="nfe-flag-header">
    <h1>Regularização de NF-e rejeitada</h1>
    <p>Configure pela tela a liberação do procedimento pontual de descarte <strong>local</strong> de NF-e rejeitada, quando o mesmo pedido já tem NFC-e autorizada. Não é necessário editar IDs ou arquivos na VPS.</p>
  </div>
  <?php if ($success !== ''): ?><div class="nfe-flag-msg nfe-flag-ok" role="status"><?= nfe_flag_h($success) ?></div><?php endif; ?>
  <?php if ($error !== ''): ?><div class="nfe-flag-msg" role="alert"><?= nfe_flag_h($error) ?></div><?php endif; ?>

  <section class="nfe-flag-box">
    <div class="nfe-flag-row">
      <div>
        <h2 class="nfe-flag-title">Empresa: <?= nfe_flag_h($companyName) ?></h2>
        <div class="nfe-flag-note">Código interno: <?= (int)$companyId ?> · <?= nfe_flag_h($source) ?></div>
      </div>
      <span class="nfe-flag-pill <?= $enabled ? 'nfe-flag-on' : 'nfe-flag-off' ?>"><?= $enabled ? 'HABILITADO' : 'DESABILITADO' ?></span>
    </div>
    <div class="nfe-flag-help"><strong>Importante:</strong> esta configuração vale somente para a empresa de entrada. Não habilita automaticamente outras empresas, não modifica a emissão normal, não cancela documentos na SEFAZ, não reabre pedidos e não altera estoque, caixa ou financeiro. A operação fiscal continua exigindo permissão, justificativa, consulta prévia à SEFAZ e todas as conferências já implementadas.</div>
  </section>

  <section class="nfe-flag-box">
    <h2 class="nfe-flag-title">Habilitar ou desabilitar</h2>
    <form method="post" action="utilitarios_regularizacao_nfe.php" autocomplete="off">
      <input type="hidden" name="action" value="save">
      <input type="hidden" name="csrf" value="<?= nfe_flag_h($_SESSION['nexa_nfe_flag_csrf']) ?>">
      <div class="nfe-flag-choices">
        <label class="nfe-flag-choice"><input type="radio" name="enabled" value="1" <?= $enabled ? 'checked' : '' ?> <?= !$canSave ? 'disabled' : '' ?>> Habilitar piloto nesta empresa</label>
        <label class="nfe-flag-choice"><input type="radio" name="enabled" value="0" <?= !$enabled ? 'checked' : '' ?> <?= !$canSave ? 'disabled' : '' ?>> Desabilitar nesta empresa</label>
      </div>
      <div id="nfeEnableConfirm">
        <label class="nfe-flag-confirm"><input type="checkbox" name="acknowledge" value="1" <?= !$canSave ? 'disabled' : '' ?>> Estou ciente de que cada NF-e rejeitada exige verificação da situação fiscal real na SEFAZ antes de qualquer descarte local.</label>
        <div class="nfe-flag-note" style="margin-bottom:8px"><label for="nfeConfirmText">Digite <strong>HABILITAR</strong> para liberar o procedimento nesta empresa:</label></div>
        <input class="nfe-flag-input" id="nfeConfirmText" name="confirmation" type="text" placeholder="HABILITAR" <?= !$canSave ? 'disabled' : '' ?>>
      </div>
      <div style="margin-top:20px"><button class="nfe-flag-button" type="submit" <?= !$canSave ? 'disabled' : '' ?>>Salvar configuração</button></div>
    </form>
    <p class="nfe-flag-note" style="margin-top:15px">Após habilitar, acesse <strong>Pedidos → Editar pedido finalizado → Verificar correção / situação fiscal</strong>. A ação de descarte só será exibida quando o documento atender aos critérios técnicos e o usuário tiver a permissão fiscal necessária.</p>
  </section>

  <?php if ($setting !== null && !empty($setting['updated_at'])): ?>
    <section class="nfe-flag-box">
      <h2 class="nfe-flag-title">Última configuração</h2>
      <p class="nfe-flag-note">Data/hora do servidor: <?= nfe_flag_h($setting['updated_at']) ?> · Usuário: <?= (int)($setting['updated_by'] ?? 0) ?></p>
      <?php if ($history): ?>
      <div style="overflow-x:auto">
        <table class="nfe-flag-table"><thead><tr><th>Data/hora do servidor</th><th>Usuário ID</th><th>Alteração</th></tr></thead><tbody>
          <?php foreach ($history as $h): ?>
          <tr><td><?= nfe_flag_h($h['at'] ?? '') ?></td><td><?= (int)($h['by_user_id'] ?? 0) ?></td><td><?= !empty($h['enabled']) ? 'Habilitou' : 'Desabilitou' ?></td></tr>
          <?php endforeach; ?>
        </tbody></table>
      </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
</main>
<script>
(function(){
  const radios=document.querySelectorAll('input[name="enabled"]');
  const panel=document.getElementById('nfeEnableConfirm');
  if(!panel || !radios.length) return;
  function refresh(){
    const selected=document.querySelector('input[name="enabled"]:checked');
    panel.style.display=selected && selected.value==='1' ? 'block':'none';
  }
  radios.forEach(r=>r.addEventListener('change',refresh));
  refresh();
})();
</script>
<?php admin_footer(); ?>
