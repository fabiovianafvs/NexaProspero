<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/multi_company.php';

$entryCompanyId = (int)COMPANY_ID;
$userId = (int)(auth_user_id() ?? 0);
$featureEnabled = multi_company_feature_enabled($pdo, $entryCompanyId);
$group = multi_company_group_by_company($pdo, $entryCompanyId);
$available = $featureEnabled ? multi_company_context_available_companies($pdo, $userId, $entryCompanyId) : [];
$availableMap = [];
foreach ($available as $row) $availableMap[(int)($row['company_id'] ?? 0)] = $row;

if (empty($_SESSION['multi_company_v3_csrf'])) {
  try { $_SESSION['multi_company_v3_csrf'] = bin2hex(random_bytes(24)); }
  catch (Throwable $e) { $_SESSION['multi_company_v3_csrf'] = hash('sha256', session_id() . '|' . $userId . '|multiempresa-v3'); }
}
$csrf = (string)$_SESSION['multi_company_v3_csrf'];
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $postedCsrf = (string)($_POST['csrf'] ?? '');
  if ($postedCsrf === '' || !hash_equals($csrf, $postedCsrf)) {
    $error = 'Sessão de diagnóstico expirada. Atualize a página e tente novamente.';
  } else {
    $targetCompanyId = (int)($_POST['target_company_id'] ?? 0);
    if (!isset($availableMap[$targetCompanyId])) {
      $error = 'A empresa selecionada não está autorizada para este usuário neste Grupo Empresarial.';
    } else {
      $issued = multi_company_context_issue($pdo, $userId, $entryCompanyId, $targetCompanyId);
      if (empty($issued['ok'])) {
        $error = 'Não foi possível criar o contexto de teste: ' . (string)($issued['reason'] ?? 'acesso_negado');
      } else {
        header('Location: multiempresa_contexto.php?mc_ctx=' . rawurlencode((string)$issued['token']));
        exit;
      }
    }
  }
}

$ctx = $GLOBALS['MULTI_COMPANY_CONTEXT'] ?? [
  'active' => false,
  'requested' => false,
  'valid' => false,
  'reason' => 'legacy',
  'entry_company_id' => $entryCompanyId,
  'company_id' => $entryCompanyId,
  'operation_mode' => '',
];
$contextCompanyId = (int)($ctx['company_id'] ?? $entryCompanyId);
$entryCompany = multi_company_context_company_row($pdo, $entryCompanyId) ?: ['id'=>$entryCompanyId,'name'=>'Empresa ' . $entryCompanyId];
$contextCompany = multi_company_context_company_row($pdo, $contextCompanyId) ?: ['id'=>$contextCompanyId,'name'=>'Empresa ' . $contextCompanyId];
$profile = !empty($ctx['valid']) ? multi_company_context_profile($pdo, $userId, $entryCompanyId, $contextCompanyId) : null;

function mcv3_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function mcv3_mode_label(string $mode): string { return $mode === 'integrated' ? 'Integrado' : ($mode === 'isolated' ? 'Isolado' : '—'); }

admin_topbar('Contexto Multiempresa V4', 'usuarios');
?>
<style>
.mcv3-wrap{display:grid;gap:16px}.mcv3-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}.mcv3-head h1{margin:0;color:var(--admin-primary)}.mcv3-muted{color:#64748b;line-height:1.55}.mcv3-card{background:#fff;border:1px solid #dbe4f0;border-radius:24px;padding:20px;box-shadow:0 15px 40px rgba(11,31,91,.08)}.mcv3-card h2{margin:0 0 12px;color:#0b1f5b;font-size:19px}.mcv3-note{border:1px solid #bae6fd;background:#f0f9ff;color:#0c4a6e;border-radius:16px;padding:13px 15px;line-height:1.55}.mcv3-warning{border-color:#fed7aa;background:#fff7ed;color:#9a3412}.mcv3-error{border-color:#fecaca;background:#fef2f2;color:#991b1b}.mcv3-ok{border-color:#a7f3d0;background:#ecfdf5;color:#065f46}.mcv3-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:14px}.mcv3-kv{border:1px solid #e2e8f0;border-radius:16px;padding:14px}.mcv3-kv small{display:block;color:#64748b;font-weight:800;margin-bottom:5px}.mcv3-kv strong{display:block;color:#0f172a;word-break:break-word}.mcv3-list{display:grid;gap:10px}.mcv3-company{display:grid;grid-template-columns:minmax(0,1fr) auto;align-items:center;gap:12px;border:1px solid #e2e8f0;border-radius:16px;padding:13px}.mcv3-company strong{display:block}.mcv3-company small{display:block;color:#64748b;margin-top:4px}.mcv3-btn{display:inline-flex;align-items:center;justify-content:center;border:1px solid #dbe4f0;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;text-decoration:none;cursor:pointer}.mcv3-btn.primary{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));border-color:transparent;color:#fff}.mcv3-badge{display:inline-flex;padding:5px 9px;border-radius:999px;font-size:12px;font-weight:900;border:1px solid #bfdbfe;background:#eff6ff;color:#1d4ed8}.mcv3-code{font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:12px;background:#0f172a;color:#e2e8f0;padding:10px 12px;border-radius:12px;overflow:auto}.mcv3-actions{display:flex;gap:8px;flex-wrap:wrap}@media(max-width:780px){.mcv3-grid{grid-template-columns:1fr}.mcv3-company{grid-template-columns:1fr}}
</style>

<div class="mcv3-wrap">
  <div class="mcv3-head">
    <div>
      <h1>Contexto seguro Multiempresa — V4</h1>
      <p class="mcv3-muted" style="margin:5px 0 0">F11 ativo para troca do contexto seguro desta aba, ainda sem ativação operacional dos módulos.</p>
    </div>
    <div class="mcv3-actions">
      <?php if (access_is_company_master()): ?>
        <a class="mcv3-btn" href="multiempresa_acessos.php">Voltar para acessos</a>
      <?php else: ?>
        <a class="mcv3-btn" href="dashboard.php">Voltar ao painel</a>
      <?php endif; ?>
      <a class="mcv3-btn" href="multiempresa_contexto.php">Limpar contexto de teste</a>
    </div>
  </div>

  <div class="mcv3-note">
    <strong>Proteção da V4:</strong> o valor operacional <code>COMPANY_ID</code> continua sendo a empresa do tenant/subdomínio. O F11 e esta tela alteram apenas <code>MULTI_COMPANY_CONTEXT_ID</code> desta aba. Portanto, selecionar outra empresa nesta versão <strong>não muda Venda, PDV, O.S., Estoque, Financeiro ou Fiscal</strong>.
  </div>

  <?php if ($error !== ''): ?><div class="mcv3-note mcv3-error"><strong>Erro:</strong> <?= mcv3_h($error) ?></div><?php endif; ?>

  <?php if (!$featureEnabled): ?>
    <div class="mcv3-note mcv3-warning"><strong>Multiempresa desabilitado.</strong> Esta empresa continua integralmente no fluxo legado. Habilite o módulo no SaaS antes de testar contexto.</div>
  <?php elseif (!$group): ?>
    <div class="mcv3-note mcv3-warning"><strong>Grupo não encontrado.</strong> A empresa precisa pertencer a um Grupo Empresarial ativo.</div>
  <?php endif; ?>

  <div class="mcv3-card">
    <h2>Estado desta aba</h2>
    <div class="mcv3-grid">
      <div class="mcv3-kv"><small>COMPANY_ID operacional — legado</small><strong><?= (int)COMPANY_ID ?> · <?= mcv3_h($entryCompany['name'] ?? '') ?></strong></div>
      <div class="mcv3-kv"><small>MULTI_COMPANY_CONTEXT_ID — V3</small><strong><?= $contextCompanyId ?> · <?= mcv3_h($contextCompany['name'] ?? '') ?></strong></div>
      <div class="mcv3-kv"><small>Contexto solicitado</small><strong><?= !empty($ctx['requested']) ? 'SIM' : 'NÃO' ?></strong></div>
      <div class="mcv3-kv"><small>Contexto válido</small><strong><?= !empty($ctx['valid']) ? 'SIM' : 'NÃO' ?></strong></div>
      <div class="mcv3-kv"><small>Troca em relação ao tenant</small><strong><?= !empty($ctx['active']) ? 'SIM — contexto V4 pré-operacional' : 'NÃO' ?></strong></div>
      <div class="mcv3-kv"><small>Motivo / status</small><strong><?= mcv3_h($ctx['reason'] ?? 'legacy') ?></strong></div>
      <div class="mcv3-kv"><small>Grupo</small><strong><?= mcv3_h($ctx['business_group_name'] ?? ($group['name'] ?? '—')) ?></strong></div>
      <div class="mcv3-kv"><small>Modo</small><strong><?= mcv3_h(mcv3_mode_label((string)($ctx['operation_mode'] ?? ($group['operation_mode'] ?? '')))) ?></strong></div>
      <div class="mcv3-kv"><small>Perfil previsto para o contexto</small><strong><?= $profile ? mcv3_h(($profile['name'] ?? '') . ' · ' . (($profile['profile_scope'] ?? '') === 'group' ? 'perfil integrado' : 'perfil da empresa')) : '—' ?></strong></div>
      <div class="mcv3-kv"><small>Isolamento entre abas</small><strong>Token opaco por aba, sem variável global de empresa ativa na sessão</strong></div>
    </div>
  </div>

  <div class="mcv3-card">
    <h2>Empresas disponíveis no F11</h2>
    <p class="mcv3-muted">A lista abaixo já aplica as travas da V4: mesmo grupo, módulo Multiempresa habilitado na origem e no destino, usuário ativo no grupo, acesso explícito à empresa e perfil válido.</p>
    <?php if (!$available): ?>
      <div class="mcv3-note mcv3-warning">Nenhuma empresa está disponível para este usuário. Revise os acessos da V2 e a habilitação do módulo no SaaS.</div>
    <?php else: ?>
      <div class="mcv3-list">
        <?php foreach ($available as $row): $targetId=(int)($row['company_id'] ?? 0); $validation=(array)($row['context_validation'] ?? []); ?>
          <div class="mcv3-company">
            <div>
              <strong><?= mcv3_h($row['company_name'] ?? ('Empresa ' . $targetId)) ?> <span class="mcv3-badge">ID <?= $targetId ?></span></strong>
              <small><?= mcv3_h(mcv3_mode_label((string)($validation['operation_mode'] ?? ''))) ?><?= !empty($validation['is_default']) ? ' · Empresa padrão' : '' ?><?= $targetId === $entryCompanyId ? ' · Empresa de entrada' : '' ?></small>
            </div>
            <form method="post" target="_blank" style="margin:0">
              <input type="hidden" name="csrf" value="<?= mcv3_h($csrf) ?>">
              <input type="hidden" name="target_company_id" value="<?= $targetId ?>">
              <button class="mcv3-btn primary" type="submit">Abrir teste em nova aba</button>
            </form>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="mcv3-card">
    <h2>Como validar duas abas</h2>
    <p class="mcv3-muted">Abra uma empresa em uma nova aba e depois abra outra empresa em outra aba. Cada uma deve mostrar um <code>MULTI_COMPANY_CONTEXT_ID</code> diferente, enquanto o <code>COMPANY_ID</code> permanece igual à empresa de entrada. Atualizar uma aba não deve alterar a outra.</p>
    <?php if (!empty($ctx['token'])): ?><div class="mcv3-code">mc_ctx=<?= mcv3_h(substr((string)$ctx['token'], 0, 12)) ?>… (token ocultado na tela)</div><?php endif; ?>
  </div>
</div>

<?php admin_footer(); ?>
