<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/comanda_module.php';
require_once __DIR__ . '/../config/saas_modules.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_comanda_setup')
  : (int)COMPANY_ID;
$agentCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_comanda_setup');
$agentCsrf = $agentCross && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$agentContextUrl = static function(string $url) use ($agentCross): string {
  return $agentCross && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
if ($agentCross && (!function_exists('multi_company_context_permission_allowed') || !multi_company_context_permission_allowed($pdo, 'gestao_comandas'))) {
  access_render_denied('Seu perfil na empresa selecionada pelo F11 não possui permissão para configurar Agentes de Impressão.');
}
saas_require_company_module($pdo, $companyId, 'comandas', 'Agentes de impressão');
comanda_ensure_schema($pdo);
$groups = comanda_printer_groups($pdo, $companyId, true);
$groupNames = comanda_print_agent_normalize_groups(array_map(static fn(array $g): string => (string)($g['name'] ?? ''), $groups));
$err = '';
$newToken = '';
$newAgentName = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($agentCross && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirado. Atualize a página e tente novamente.');
    }
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'create') {
      $newAgentName = trim((string)($_POST['name'] ?? ''));
      $result = comanda_print_agent_create($pdo, $companyId, $newAgentName, (array)($_POST['groups'] ?? []));
      $newToken = (string)$result['token'];
    } elseif ($action === 'update') {
      $agentId = (int)($_POST['agent_id'] ?? 0);
      $name = trim((string)($_POST['name'] ?? ''));
      $selected = comanda_print_agent_normalize_groups((array)($_POST['groups'] ?? []));
      if ($agentId <= 0 || $name === '') throw new RuntimeException('Informe o agente e seu nome.');
      if (!$selected) throw new RuntimeException('Selecione ao menos um setor.');
      $pdo->prepare('UPDATE comanda_print_agents SET name=?, allowed_groups_json=?, updated_at=NOW() WHERE company_id=? AND id=?')
        ->execute([$name, json_encode($selected, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $companyId, $agentId]);
      header('Location: ' . $agentContextUrl('gestao_comandas_agentes.php?ok=' . rawurlencode('Agente atualizado.'))); exit;
    } elseif ($action === 'regenerate') {
      $agentId = (int)($_POST['agent_id'] ?? 0);
      $row = finance_query_one($pdo, 'SELECT name FROM comanda_print_agents WHERE company_id=? AND id=? LIMIT 1', [$companyId, $agentId]);
      if (!$row) throw new RuntimeException('Agente não encontrado.');
      $newAgentName = (string)$row['name'];
      $result = comanda_print_agent_regenerate_token($pdo, $companyId, $agentId);
      $newToken = (string)$result['token'];
    } elseif ($action === 'toggle') {
      $agentId = (int)($_POST['agent_id'] ?? 0);
      $pdo->prepare('UPDATE comanda_print_agents SET is_active=IF(is_active=1,0,1), updated_at=NOW() WHERE company_id=? AND id=?')->execute([$companyId, $agentId]);
      header('Location: ' . $agentContextUrl('gestao_comandas_agentes.php?ok=' . rawurlencode('Situação do agente atualizada.'))); exit;
    } elseif ($action === 'delete') {
      $agentId = (int)($_POST['agent_id'] ?? 0);
      $pdo->prepare("UPDATE comanda_print_jobs SET status=IF(status='PROCESSANDO','PENDENTE',status), agent_id=NULL, claimed_by=NULL, claimed_at=NULL, next_attempt_at=NOW(), updated_at=NOW() WHERE company_id=? AND agent_id=? AND status<>'IMPRESSO'")->execute([$companyId, $agentId]);
      $pdo->prepare('DELETE FROM comanda_print_agents WHERE company_id=? AND id=?')->execute([$companyId, $agentId]);
      header('Location: ' . $agentContextUrl('gestao_comandas_agentes.php?ok=' . rawurlencode('Agente removido.'))); exit;
    }
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

$agents = comanda_print_agent_rows($pdo, $companyId);
$host = (string)($_SERVER['HTTP_HOST'] ?? 'localhost');
$hostOnly = strtolower((string)preg_replace('/:\\d+$/', '', $host));
$forwardedProto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0] ?? ''));
$cfVisitor = json_decode((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), true);
$cfScheme = is_array($cfVisitor) ? strtolower(trim((string)($cfVisitor['scheme'] ?? ''))) : '';
$isHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
  || $forwardedProto === 'https'
  || $cfScheme === 'https'
  || $hostOnly === 'nexaprospero.com.br'
  || str_ends_with($hostOnly, '.nexaprospero.com.br');
$scheme = $isHttps ? 'https' : 'http';
$basePath = rtrim(str_replace('\\', '/', dirname(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/admin/gestao_comandas_agentes.php')))), '/');
$apiUrl = $scheme . '://' . $host . ($basePath !== '' ? $basePath : '') . '/api/comanda_print_agent.php';

gestao_admin_start('Agentes de Impressão', 'gestao_comandas_agentes');
gestao_page_header('Agentes de Impressão Automática', 'Instale um agente em cada computador ou use um único computador para várias impressoras. O agente recebe a fila e imprime sem abrir a janela do navegador.', [
  ['label'=>'Configuração de Comandas','href'=>$agentContextUrl('gestao_comandas_config.php'),'class'=>'btn'],
  ['label'=>'Monitor da fila','href'=>$agentContextUrl('gestao_comandas_impressao.php'),'class'=>'btn'],
  ['label'=>'Baixar Agente V1.10.1','href'=>'../downloads/AgenteImpressaoNexa_V1.zip?v=1.10.1','class'=>'btn btn-primary'],
]);
gestao_tabs('gestao_comandas_agentes');
gestao_flash();
if ($agentCross) echo '<div class="msg ok"><strong>V11.32 · Agentes Multiempresa</strong> — estações, chaves e setores pertencem exclusivamente à empresa selecionada pelo F11.</div>';
if ($err !== '') echo '<div class="msg err">' . comanda_h($err) . '</div>';
?>
<style>
.agent-grid{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:14px;align-items:start}.agent-token{background:#ecfdf5;border:1px solid #86efac;border-radius:20px;padding:18px;margin-bottom:14px}.agent-token code{display:block;background:#052e16;color:#dcfce7;border-radius:13px;padding:13px;overflow-wrap:anywhere;font-size:13px;margin:10px 0}.agent-card{border:1px solid #dbe4f0;border-radius:19px;padding:15px;margin-bottom:10px;background:#fff}.agent-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.agent-status{display:inline-flex;align-items:center;gap:6px;border-radius:999px;padding:5px 9px;font-size:11px;font-weight:900;background:#f1f5f9;color:#475569}.agent-status:before{content:'';width:8px;height:8px;border-radius:50%;background:#94a3b8}.agent-status.online{background:#ecfdf5;color:#047857}.agent-status.online:before{background:#16a34a;box-shadow:0 0 0 3px #bbf7d0}.agent-status.off{background:#fef2f2;color:#b91c1c}.agent-meta{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px;margin:12px 0}.agent-meta div{background:#f8fafc;border-radius:12px;padding:9px}.agent-meta small{display:block;color:#64748b;font-weight:800}.agent-groups{display:flex;flex-wrap:wrap;gap:6px;margin:9px 0}.agent-group{padding:4px 8px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:11px;font-weight:900}.agent-actions{display:flex;flex-wrap:wrap;gap:7px;border-top:1px solid #eef2f7;padding-top:10px}.agent-form label{display:grid;gap:6px;margin-bottom:11px}.agent-checks{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:7px}.agent-check{display:flex!important;grid-template-columns:auto 1fr!important;align-items:center;gap:7px!important;border:1px solid #dbe4f0;padding:9px;border-radius:12px;margin:0!important}.agent-api{font-family:monospace;font-size:12px;overflow-wrap:anywhere;background:#f8fafc;border:1px solid #dbe4f0;border-radius:12px;padding:10px}.agent-edit{margin-top:10px}.agent-edit summary{cursor:pointer;font-weight:900;color:#1d4ed8}@media(max-width:1000px){.agent-grid{grid-template-columns:1fr}.agent-meta{grid-template-columns:1fr}.agent-checks{grid-template-columns:1fr}}
</style>
<?php if ($newToken !== ''): ?>
<div class="agent-token">
  <strong>Chave criada para <?= comanda_h($newAgentName) ?></strong>
  <p>Copie agora. Por segurança, a chave completa não será exibida novamente. Depois, abra o configurador no computador, cole esta chave e clique em <strong>Salvar e ativar</strong>. Ao gerar uma nova chave, a anterior deixa de funcionar imediatamente.</p>
  <code id="newAgentToken"><?= comanda_h($newToken) ?></code>
  <button type="button" class="btn btn-primary" onclick="navigator.clipboard.writeText(document.getElementById('newAgentToken').textContent).then(()=>this.textContent='Copiado!')">Copiar chave</button>
</div>
<?php endif; ?>
<div class="agent-grid">
  <main class="mg-card">
    <h2>Estações cadastradas</h2>
    <p class="mg-muted">O status fica verde quando o agente se comunica com o servidor. Os setores mapeados no configurador do computador são sincronizados automaticamente e cada setor pode apontar para uma impressora diferente. Se aparecer Offline após trocar a chave, atualize a chave no computador e execute Diagnosticar_Agente.bat.</p>
    <?php foreach ($agents as $agent):
      $lastSeen = trim((string)($agent['last_seen_at'] ?? ''));
      $online = $lastSeen !== '' && (time() - (int)strtotime($lastSeen)) <= 90;
      $allowed = comanda_print_agent_groups($agent);
    ?>
      <article class="agent-card">
        <div class="agent-head"><div><strong><?= comanda_h($agent['name']) ?></strong><div class="mg-muted">Chave: <?= comanda_h($agent['token_hint'] ?: 'não informada') ?></div></div><span class="agent-status <?= $online?'online':'off' ?>"><?= $online?'Online':'Offline' ?></span></div>
        <div class="agent-groups"><?php foreach ($allowed as $g): ?><span class="agent-group"><?= comanda_h($g) ?></span><?php endforeach; ?></div>
        <div class="agent-meta">
          <div><small>Computador</small><strong><?= comanda_h($agent['machine_name'] ?: '-') ?></strong></div>
          <div><small>Último contato</small><strong><?= $lastSeen !== '' ? comanda_h(date('d/m/Y H:i:s', strtotime($lastSeen))) : 'Nunca' ?></strong></div>
          <div><small>Versão</small><strong><?= comanda_h($agent['agent_version'] ?: '-') ?></strong></div>
          <div><small>IP</small><strong><?= comanda_h($agent['last_ip'] ?: '-') ?></strong></div>
        </div>
        <div class="agent-actions">
          <form method="post"><input type="hidden" name="action" value="regenerate"><?php if ($agentCross && $agentCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($agentCsrf) ?>"><?php endif; ?><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="btn btn-sm" onclick="return confirm('A chave atual deixará de funcionar. Continuar?')">Nova chave</button></form>
          <form method="post"><input type="hidden" name="action" value="toggle"><?php if ($agentCross && $agentCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($agentCsrf) ?>"><?php endif; ?><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="btn btn-sm"><?= !empty($agent['is_active'])?'Desativar':'Ativar' ?></button></form>
          <form method="post"><input type="hidden" name="action" value="delete"><?php if ($agentCross && $agentCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($agentCsrf) ?>"><?php endif; ?><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><button class="btn btn-sm" onclick="return confirm('Remover este agente?')">Remover</button></form>
        </div>
        <details class="agent-edit"><summary>Editar nome e setores</summary><form method="post" class="agent-form" style="margin-top:10px"><input type="hidden" name="action" value="update"><?php if ($agentCross && $agentCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($agentCsrf) ?>"><?php endif; ?><input type="hidden" name="agent_id" value="<?= (int)$agent['id'] ?>"><label>Nome<input name="name" required value="<?= comanda_h($agent['name']) ?>"></label><div class="agent-checks"><?php foreach ($groupNames as $g): ?><label class="agent-check"><input type="checkbox" name="groups[]" value="<?= comanda_h($g) ?>" <?= in_array($g,$allowed,true)?'checked':'' ?>><span><?= comanda_h($g) ?></span></label><?php endforeach; ?></div><button class="btn btn-primary" style="margin-top:10px">Salvar</button></form></details>
      </article>
    <?php endforeach; ?>
    <?php if (!$agents): ?><div class="empty-state">Nenhum agente cadastrado. Crie a primeira estação no formulário ao lado.</div><?php endif; ?>
  </main>
  <aside>
    <div class="mg-card">
      <h2>Novo agente</h2>
      <form method="post" class="agent-form"><input type="hidden" name="action" value="create"><?php if ($agentCross && $agentCsrf !== ''): ?><input type="hidden" name="mc_csrf" value="<?= comanda_h($agentCsrf) ?>"><?php endif; ?><label>Nome da estação<input name="name" required placeholder="Ex.: Computador da cozinha"></label><strong>Setores atendidos</strong><div class="agent-checks" style="margin-top:8px"><?php foreach ($groupNames as $g): ?><label class="agent-check"><input type="checkbox" name="groups[]" value="<?= comanda_h($g) ?>"><span><?= comanda_h($g) ?></span></label><?php endforeach; ?></div><?php if (!$groupNames): ?><div class="msg err" style="margin-top:10px">Cadastre primeiro os grupos de impressão nas configurações de Comandas.</div><?php endif; ?><button class="btn btn-primary" style="margin-top:12px" <?= !$groupNames?'disabled':'' ?>>Criar agente e chave</button></form>
    </div>
    <div class="mg-card" style="margin-top:14px">
      <h2>Endereço da API</h2><div class="agent-api" id="agentApiUrl"><?= comanda_h($apiUrl) ?></div><button type="button" class="btn" style="margin-top:9px" onclick="navigator.clipboard.writeText(document.getElementById('agentApiUrl').textContent)">Copiar endereço</button>
      <p class="mg-muted">O configurador do agente recebe este endereço, a chave gerada e o mapeamento de cada setor para uma impressora USB/rede instalada no Windows ou para um endereço TCP/IP.</p>
    </div>
  </aside>
</div>
<?php gestao_admin_end(); ?>
