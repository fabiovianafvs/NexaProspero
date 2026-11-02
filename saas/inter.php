<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/inter_pix.php';
inter_pix_ensure_schema($pdo);
$msg=''; $erro='';
$current = inter_pix_get_config($pdo, false) ?: inter_pix_normalize_config([]);
$defaultWebhook = current_scheme() . '://' . current_host_name() . '/webhooks/inter_pix.php';
if (($current['webhook_url'] ?? '') === '') $current['webhook_url'] = $defaultWebhook;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $webhookToken = trim((string)($_POST['webhook_token'] ?? ''));
    if ($webhookToken === '') $webhookToken = strtoupper(bin2hex(random_bytes(8)));
    inter_pix_save_config($pdo, [
      'environment' => (string)($_POST['environment'] ?? 'sandbox'),
      'client_id' => trim((string)($_POST['client_id'] ?? '')),
      'client_secret' => trim((string)($_POST['client_secret'] ?? '')),
      'oauth_scope' => trim((string)($_POST['oauth_scope'] ?? '')),
      'pix_key' => trim((string)($_POST['pix_key'] ?? '')),
      'cert_path' => trim((string)($_POST['cert_path'] ?? '')),
      'key_path' => trim((string)($_POST['key_path'] ?? '')),
      'ca_path' => trim((string)($_POST['ca_path'] ?? '')),
      'api_base_url' => trim((string)($_POST['api_base_url'] ?? '')),
      'oauth_url' => trim((string)($_POST['oauth_url'] ?? '')),
      'webhook_url' => trim((string)($_POST['webhook_url'] ?? '')),
      'webhook_token' => $webhookToken,
      'charge_expiration_seconds' => (int)($_POST['charge_expiration_seconds'] ?? 86400),
      'active' => isset($_POST['active']) ? 1 : 0,
    ]);
    $current = inter_pix_get_config($pdo, false) ?: inter_pix_normalize_config([]);
    $msg = 'Configuração do Banco Inter salva com sucesso.';
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$webhookDisplay = trim((string)($current['webhook_url'] ?? ''));
if ($webhookDisplay !== '' && !empty($current['webhook_token'])) {
  $sep = str_contains($webhookDisplay, '?') ? '&' : '?';
  $webhookDisplay .= $sep . 'token=' . rawurlencode((string)$current['webhook_token']);
}
saas_layout_start('Banco Inter', 'Banco Inter • Pix', 'Configure a conta recebedora do Inter usada nas cobranças Pix do SaaS.'); ?>
<div class="top-actions">
  <a class="btn2 btn2-ghost" href="/saas/dashboard.php">Voltar ao dashboard</a>
  <a class="btn2 btn2-ghost" href="/saas/cobrancas.php">Ver cobranças</a>
</div>
<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>
<div class="grid-2" style="align-items:start;">
  <div class="panel"><h3>Credenciais e ambiente</h3>
    <form method="post" class="form-grid">
      <label>Ambiente</label><select class="field" name="environment"><option value="sandbox" <?= ($current['environment'] ?? '') === 'sandbox' ? 'selected' : '' ?>>Sandbox</option><option value="production" <?= ($current['environment'] ?? '') === 'production' ? 'selected' : '' ?>>Produção</option></select>
      <label>Client ID</label><input class="field" name="client_id" value="<?= e((string)($current['client_id'] ?? '')) ?>" required>
      <label>Client Secret</label><input class="field" name="client_secret" value="<?= e((string)($current['client_secret'] ?? '')) ?>" required>
      <label>Escopos OAuth</label><input class="field" name="oauth_scope" value="<?= e((string)($current['oauth_scope'] ?? '')) ?>">
      <label>Chave Pix recebedora</label><input class="field" name="pix_key" value="<?= e((string)($current['pix_key'] ?? '')) ?>" required>
      <label>Expiração da cobrança (segundos)</label><input class="field" name="charge_expiration_seconds" value="<?= e((string)($current['charge_expiration_seconds'] ?? '86400')) ?>">
      <label>URL base da API</label><input class="field" name="api_base_url" value="<?= e((string)($current['api_base_url'] ?? '')) ?>" placeholder="<?= e(inter_pix_default_api_base((string)($current['environment'] ?? 'sandbox'))) ?>">
      <label>URL do OAuth</label><input class="field" name="oauth_url" value="<?= e((string)($current['oauth_url'] ?? '')) ?>" placeholder="<?= e(inter_pix_default_oauth_url((string)($current['environment'] ?? 'sandbox'))) ?>">
      <label>Caminho do certificado</label><input class="field" name="cert_path" value="<?= e((string)($current['cert_path'] ?? '')) ?>">
      <label>Caminho da chave privada</label><input class="field" name="key_path" value="<?= e((string)($current['key_path'] ?? '')) ?>">
      <label>Caminho do CA bundle</label><input class="field" name="ca_path" value="<?= e((string)($current['ca_path'] ?? '')) ?>">
      <label>Webhook URL</label><input class="field" name="webhook_url" value="<?= e((string)($current['webhook_url'] ?? $defaultWebhook)) ?>">
      <label>Token do webhook</label><input class="field" name="webhook_token" value="<?= e((string)($current['webhook_token'] ?? '')) ?>">
      <label><input type="checkbox" name="active" <?= !isset($current['active']) || !empty($current['active']) ? 'checked' : '' ?>> Integração ativa</label>
      <div><button class="btn2 btn2-dark" type="submit">Salvar integração</button></div>
    </form>
  </div>
  <div class="panel">
    <h3>Resumo operacional</h3>
    <p><strong>Status:</strong> <?= !empty($current['active']) ? 'Ativa' : 'Inativa' ?></p>
    <p><strong>Ambiente:</strong> <?= e((string)($current['environment'] ?? 'sandbox')) ?></p>
    <p><strong>Client Secret:</strong> <?= e(inter_pix_mask_secret((string)($current['client_secret'] ?? ''))) ?></p>
    <p><strong>Webhook pronto para cadastro:</strong><br><code class="box"><?= e($webhookDisplay) ?></code></p>
    <div class="warn" style="margin-top:14px;">Cadastre a URL do webhook acima no portal do Inter e use primeiro o ambiente <strong>sandbox</strong>. Depois troque para produção sem mexer no fluxo do SaaS.</div>
  </div>
</div>
<?php saas_layout_end(); ?>