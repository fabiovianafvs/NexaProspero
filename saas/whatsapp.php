<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';
require_once __DIR__ . '/../config/saas_whatsapp_service.php';

$msg = '';
$erro = '';
saas_whatsapp_ensure_schema($pdo);
$cfg = saas_whatsapp_load_config($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'save_settings' || $action === 'generate_token') {
      $data = $_POST;
      $oldCfg = $cfg;
      $provider = (string)($data['provider'] ?? $oldCfg['provider'] ?? 'unofficial');
      $apiToken = trim((string)($data['api_token'] ?? ''));
      $syncMsg = '';

      // Mesmo comportamento do Admin: se clicar em gerar token ou deixar vazio na API Não Oficial,
      // a Central SaaS gera o token e tenta registrar no serviço WhatsApp/Node automaticamente.
      if ($provider === 'unofficial' && ($action === 'generate_token' || $apiToken === '')) {
        $apiToken = saas_whatsapp_generate_token();
        $data['api_token'] = $apiToken;
        $instanceId = max(0, (int)($data['unofficial_company_id'] ?? $oldCfg['unofficial_company_id'] ?? 0));
        $sync = saas_whatsapp_register_unofficial_token($oldCfg, $instanceId, $apiToken);
        if (!empty($sync['ok'])) {
          $syncMsg = ' Token gerado e registrado automaticamente no serviço WhatsApp.';
        } else {
          $syncMsg = ' Token gerado, mas não foi possível registrar automaticamente no serviço WhatsApp: ' . (string)($sync['error'] ?? 'falha desconhecida') . '.';
        }
      }

      saas_whatsapp_save_config($pdo, $data);
      $cfg = saas_whatsapp_load_config($pdo);
      $msg = 'Configurações de WhatsApp salvas com sucesso.' . $syncMsg;
    } elseif ($action === 'save_template') {
      $id = saas_whatsapp_save_template($pdo, $_POST);
      $msg = $id > 0 ? 'Modelo de mensagem salvo com sucesso.' : 'Modelo de mensagem criado com sucesso.';
    } elseif ($action === 'disable_template' || $action === 'enable_template') {
      $id = (int)($_POST['id'] ?? 0);
      if ($id <= 0) throw new RuntimeException('Modelo inválido.');
      saas_whatsapp_set_template_active($pdo, $id, $action === 'enable_template');
      $msg = $action === 'enable_template' ? 'Modelo ativado com sucesso.' : 'Modelo inativado com sucesso.';
    } elseif ($action === 'reset_templates') {
      saas_whatsapp_reset_default_templates($pdo);
      $msg = 'Modelos padrões restaurados com sucesso.';
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$cfg = saas_whatsapp_load_config($pdo);
$templates = saas_whatsapp_load_templates($pdo);
$editTemplate = null;
if (isset($_GET['edit_template'])) {
  $editTemplate = saas_whatsapp_get_template($pdo, (int)$_GET['edit_template']);
}

$recentLogs = [];
try {
  $recentLogs = $pdo->query("SELECT l.*, c.name AS company_name FROM saas_whatsapp_logs l LEFT JOIN companies c ON c.id = l.company_id ORDER BY l.id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$defaultTemplateBody = $editTemplate['body'] ?? "Olá, {{empresa}}!\n\nFaltam {{dias}} dias para o vencimento da sua assinatura.\n\nVencimento: {{vencimento}}\nValor: {{valor}}\n\nLink para pagamento:\n{{link_pagamento}}";

saas_layout_start('WhatsApp • Central SaaS', 'WhatsApp', 'Configure envio automático, QR Code central e modelos das mensagens.');
?>

<style>
.wa-grid{display:grid;grid-template-columns:minmax(0,1.1fr) minmax(340px,.9fr);gap:18px;align-items:start}.wa-card{background:#fff;border:1px solid #d9e5fb;border-radius:22px;padding:18px;box-shadow:0 18px 48px rgba(9,31,91,.08);color:#0b1f5b}.wa-title{font-size:18px;margin:0 0 12px;color:#0b1f5b}.wa-muted{color:#64748b;font-size:13px;line-height:1.45}.wa-form label{font-weight:800;color:#0b1f5b;display:block;margin:13px 0 7px}.wa-form input,.wa-form select,.wa-form textarea{width:100%;border:1px solid #bfd2f4;border-radius:14px;padding:11px 12px;font:inherit;background:#fff;color:#0b1f5b}.wa-form textarea{min-height:105px;resize:vertical}.wa-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.wa-check{display:flex!important;align-items:flex-start;gap:10px;padding:12px;border:1px solid #d9e5fb;border-radius:16px;background:#f8fbff;margin-top:10px}.wa-check input{width:auto;margin-top:3px}.wa-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}.wa-status{display:inline-flex;align-items:center;border-radius:999px;padding:8px 11px;font-size:12px;font-weight:900}.wa-status.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.wa-status.warn{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}.wa-status.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.qr-box{border:1px dashed #bfd2f4;background:#f8fbff;border-radius:18px;min-height:220px;display:flex;align-items:center;justify-content:center;text-align:center;padding:14px}.qr-box img{max-width:260px;width:100%;height:auto}.log-note{white-space:pre-wrap;background:#0b1f5b;color:#e0f2fe;border-radius:16px;padding:12px;font-size:12px;line-height:1.45;max-height:240px;overflow:auto}.provider-box{display:grid;grid-template-columns:1fr 1fr;gap:10px}.provider-option{border:1px solid #d9e5fb;border-radius:18px;padding:12px;background:#fff;cursor:pointer}.provider-option strong{display:block;color:#0b1f5b}.provider-option small{display:block;color:#64748b;margin-top:4px}.provider-option input{width:auto;margin-right:6px}.section-line{height:1px;background:#e5eefb;margin:18px 0}.template-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:18px;align-items:start}.template-table td{vertical-align:top}.template-body-preview{font-size:12px;line-height:1.35;color:#475569;white-space:pre-wrap;max-height:80px;overflow:hidden}.placeholder-box{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:8px}.placeholder-box code{display:block;background:#eef6ff;border:1px solid #dbeafe;color:#0b1f5b;border-radius:10px;padding:7px 8px;font-size:12px;word-break:break-word}.pill-off{background:#f1f5f9;color:#64748b;border:1px solid #cbd5e1}.log-table td{vertical-align:top}@media(max-width:1100px){.wa-grid,.template-grid{grid-template-columns:1fr}.wa-row,.provider-box,.placeholder-box{grid-template-columns:1fr}}
.wa-repair-box{width:100%;border:1px solid #fecaca;background:#fff7f7;color:#7f1d1d;border-radius:18px;padding:16px;text-align:left;line-height:1.5}.wa-repair-box b{color:#991b1b}.wa-repair-box code{display:block;background:#fff;border:1px solid #fecaca;border-radius:12px;padding:9px 10px;margin-top:10px;color:#7f1d1d;white-space:pre-wrap;word-break:break-word}.wa-instance{display:block;margin-top:7px;color:#0b1f5b;font-weight:800;font-size:12px}.wa-card .wa-actions .btn2{min-width:120px}</style>

<div class="top-actions">
  <a class="btn2 btn2-ghost" href="/saas/empresas.php">Empresas</a>
  <a class="btn2 btn2-ghost" href="/saas/cobrancas.php">Cobranças</a>
  <a class="btn2 btn2-ghost" href="/saas/dashboard.php">Dashboard</a>
</div>

<?php if ($msg): ?><div class="alert-ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="alert-err"><?= e($erro) ?></div><?php endif; ?>

<div class="wa-grid">
  <div class="wa-card">
    <h2 class="wa-title">Configurações gerais</h2>
    <form method="post" class="wa-form" id="waForm">
      <input type="hidden" name="action" value="save_settings">

      <label>Tipo de integração</label>
      <div class="provider-box">
        <label class="provider-option">
          <input type="radio" name="provider" value="unofficial" <?= $cfg['provider'] === 'unofficial' ? 'checked' : '' ?>>
          <strong>API Não Oficial</strong>
          <small>Usa o mesmo serviço com QR Code do admin/catálogo delivery.</small>
        </label>
        <label class="provider-option">
          <input type="radio" name="provider" value="official" <?= $cfg['provider'] === 'official' ? 'checked' : '' ?>>
          <strong>API Oficial / Meta</strong>
          <small>Usa Cloud API com Phone Number ID e token de acesso.</small>
        </label>
      </div>

      <label class="wa-check"><input type="checkbox" name="enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>><span><b>Ativar WhatsApp SaaS</b><br><span class="wa-muted">Controla envios automáticos de credenciais e cobranças pela Central SaaS.</span></span></label>
      <label class="wa-check"><input type="checkbox" name="auto_send_credentials" <?= !empty($cfg['auto_send_credentials']) ? 'checked' : '' ?>><span><b>Enviar credenciais após cadastro</b><br><span class="wa-muted">Após criar empresa em /cadastro.php, envia links, usuário e senha inicial.</span></span></label>
      <label class="wa-check"><input type="checkbox" name="auto_send_billing" <?= !empty($cfg['auto_send_billing']) ? 'checked' : '' ?>><span><b>Enviar avisos de vencimento</b><br><span class="wa-muted">O cron avisa antes do vencimento e também durante a tolerância configurada.</span></span></label>

      <div class="wa-row">
        <div><label>Dias antes do vencimento</label><input type="number" min="1" max="15" name="billing_notice_days" value="<?= (int)$cfg['billing_notice_days'] ?>"></div>
        <div><label>Dias de tolerância para bloqueio</label><input type="number" min="0" max="30" name="block_grace_days" value="<?= (int)$cfg['block_grace_days'] ?>"></div>
      </div>
      <div class="wa-row">
        <div><label>DDI padrão</label><input name="default_country_code" value="<?= e((string)$cfg['default_country_code']) ?>" placeholder="55"></div>
        <div><label>Telefone para teste</label><input name="test_phone" value="<?= e((string)$cfg['test_phone']) ?>" placeholder="5531999999999"></div>
      </div>

      <div class="section-line"></div>
      <h2 class="wa-title">API Não Oficial</h2>
      <label>URL do serviço WhatsApp</label>
      <input name="service_url" value="<?= e((string)$cfg['service_url']) ?>" placeholder="http://127.0.0.1:3333">
      <div class="wa-row">
        <div><label>ID da instância central</label><input type="number" min="1" name="unofficial_company_id" value="<?= (int)$cfg['unofficial_company_id'] ?>" readonly><div class="wa-muted">ID técnico exclusivo da Central SaaS no mesmo serviço WebJS usado pelas empresas. Não altere manualmente.</div></div>
        <div><label>Token da API não oficial</label><input name="api_token" value="<?= e((string)$cfg['api_token']) ?>" placeholder="Clique em Gerar token automático"><div class="wa-muted">Se ficar vazio, o SaaS gera automaticamente ao salvar. O token é registrado no serviço via /tokens/upsert, igual ao Admin.</div></div>
      </div>

      <div class="section-line"></div>
      <h2 class="wa-title">API Oficial / Meta</h2>
      <div class="wa-row">
        <div><label>Graph API version</label><input name="official_graph_version" value="<?= e((string)$cfg['official_graph_version']) ?>" placeholder="v20.0"></div>
        <div><label>Phone Number ID</label><input name="official_phone_number_id" value="<?= e((string)$cfg['official_phone_number_id']) ?>"></div>
      </div>
      <label>Token de acesso oficial</label>
      <textarea name="official_access_token" placeholder="Token permanente/temporário da Meta"><?= e((string)$cfg['official_access_token']) ?></textarea>
      <div class="wa-row">
        <div><label>Template oficial de credenciais</label><input name="official_template_credentials" value="<?= e((string)$cfg['official_template_credentials']) ?>" placeholder="novo_catalogo_saas"></div>
        <div><label>Template oficial de cobrança</label><input name="official_template_billing" value="<?= e((string)$cfg['official_template_billing']) ?>" placeholder="aviso_vencimento_saas"></div>
      </div>
      <label>Idioma dos templates oficiais</label>
      <input name="official_language_code" value="<?= e((string)$cfg['official_language_code']) ?>" placeholder="pt_BR">
      <div class="wa-muted">Os modelos editáveis abaixo são usados diretamente na API Não Oficial. Na API Oficial, serão usados quando os campos de template oficial estiverem vazios.</div>

      <div class="wa-actions">
        <button class="btn2 btn2-primary" type="submit" name="action" value="save_settings">Salvar configurações</button>
        <button class="btn2 btn2-ghost" type="submit" name="action" value="generate_token" onclick="return confirm('Gerar novo token para a API não oficial? O token anterior desta instância deixará de funcionar.');">Gerar token automático</button>
        <button class="btn2 btn2-dark" type="button" id="btnTest">Enviar teste</button>
      </div>
    </form>
  </div>

  <div class="wa-card">
    <h2 class="wa-title">Conexão por QR Code</h2>
    <p class="wa-muted">Esta área usa a API Não Oficial. Gere o token, salve as configurações e leia o QR Code com o WhatsApp que será usado pela Central SaaS.</p>
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin:12px 0 14px;">
      <span id="waStatus" class="wa-status warn">Verificando...</span>
      <div class="wa-actions" style="margin:0">
        <button class="btn2 btn2-ghost" type="button" id="btnRefresh">Atualizar</button>
        <button class="btn2 btn2-ghost" type="button" id="btnRestart">Reiniciar sessão</button>
        <button class="btn2 btn2-warning" type="button" id="btnRepair">Corrigir conexão</button>
        <button class="btn2 btn2-danger" type="button" id="btnLogout">Desconectar</button>
      </div>
    </div>
    <div class="wa-instance" id="waInstance">Sessão WhatsApp atual: company-<?= e(saas_whatsapp_unofficial_instance_id($cfg)) ?></div>
    <div class="qr-box" id="qrBox"><div class="wa-muted">Clique em atualizar para carregar o QR Code da instância central.</div></div>
    <div style="margin-top:12px" class="log-note" id="waLog">Aguardando conexão...</div>
  </div>
</div>

<div class="template-grid" style="margin-top:18px">
  <div class="wa-card">
    <h2 class="wa-title"><?= $editTemplate ? 'Editar modelo de mensagem' : 'Criar modelo de mensagem' ?></h2>
    <form method="post" class="wa-form">
      <input type="hidden" name="action" value="save_template">
      <input type="hidden" name="id" value="<?= (int)($editTemplate['id'] ?? 0) ?>">
      <div class="wa-row">
        <div>
          <label>Tipo</label>
          <select name="tipo" id="templateTipo">
            <option value="credentials" <?= (($editTemplate['tipo'] ?? '') === 'credentials') ? 'selected' : '' ?>>Credenciais da nova empresa</option>
            <option value="billing_notice" <?= (!$editTemplate || ($editTemplate['tipo'] ?? '') === 'billing_notice') ? 'selected' : '' ?>>Cobrança / vencimento</option>
          </select>
        </div>
        <div>
          <label>Dias de referência</label>
          <input name="dias_referencia" value="<?= e((string)($editTemplate['dias_referencia'] ?? '')) ?>" placeholder="3, 2, 1, 0, -1, -2...">
          <div class="wa-muted">Use 3, 2, 1 para antes; 0 para hoje; -1/-2 para vencido. Em credenciais, deixe vazio.</div>
        </div>
      </div>
      <label>Título interno</label>
      <input name="titulo" value="<?= e((string)($editTemplate['titulo'] ?? '')) ?>" placeholder="Ex.: Faltam 3 dias para vencer">
      <label>Mensagem</label>
      <textarea name="body" rows="11" placeholder="Escreva a mensagem usando variáveis como {{empresa}}, {{vencimento}} e {{link_pagamento}}."><?= e((string)$defaultTemplateBody) ?></textarea>
      <label class="wa-check"><input type="checkbox" name="ativo" <?= !isset($editTemplate['ativo']) || (int)$editTemplate['ativo'] === 1 ? 'checked' : '' ?>><span><b>Modelo ativo</b><br><span class="wa-muted">Somente modelos ativos serão usados nos envios automáticos.</span></span></label>
      <div class="wa-actions">
        <button class="btn2 btn2-dark" type="submit">Salvar modelo</button>
        <?php if ($editTemplate): ?><a class="btn2 btn2-ghost" href="/saas/whatsapp.php">Novo modelo</a><?php endif; ?>
      </div>
    </form>
  </div>

  <div class="wa-card">
    <h2 class="wa-title">Variáveis disponíveis</h2>
    <p class="wa-muted">Use estas variáveis no texto. O sistema troca automaticamente pelos dados da empresa, cobrança ou acesso.</p>
    <div class="placeholder-box">
      <code>{{empresa}}</code><code>{{plano}}</code><code>{{valor}}</code>
      <code>{{vencimento}}</code><code>{{dias}}</code><code>{{dias_vencido}}</code>
      <code>{{link_pagamento}}</code><code>{{link_catalogo_delivery}}</code><code>{{link_catalogo}}</code>
      <code>{{link_admin}}</code><code>{{usuario}}</code><code>{{link_criar_senha}}</code>
      <code>{{link_treinamento}}</code><code>{{bloco_treinamento}}</code><code>{{curso_treinamento}}</code>
    </div>
    <div class="wa-actions">
      <form method="post" onsubmit="return confirm('Restaurar os modelos padrões? Isso remove os modelos atuais.');">
        <input type="hidden" name="action" value="reset_templates">
        <button class="btn2 btn2-ghost" type="submit">Restaurar modelos padrão</button>
      </form>
    </div>
  </div>
</div>

<div class="panel" style="margin-top:18px">
  <h3>Modelos cadastrados</h3>
  <div class="table-wrap"><table class="template-table"><thead><tr><th>Tipo</th><th>Dias</th><th>Título</th><th>Mensagem</th><th>Status</th><th>Ações</th></tr></thead><tbody>
    <?php foreach ($templates as $t): ?>
      <tr>
        <td><?= e($t['tipo'] === 'credentials' ? 'Credenciais' : 'Cobrança') ?></td>
        <td><?= $t['dias_referencia'] === null ? '—' : e((string)$t['dias_referencia']) ?></td>
        <td><strong><?= e((string)$t['titulo']) ?></strong></td>
        <td><div class="template-body-preview"><?= e((string)$t['body']) ?></div></td>
        <td><span class="pill <?= (int)$t['ativo'] === 1 ? '' : 'pill-off' ?>"><?= (int)$t['ativo'] === 1 ? 'ativo' : 'inativo' ?></span></td>
        <td>
          <div class="row-actions">
            <a class="mini mini-primary" href="/saas/whatsapp.php?edit_template=<?= (int)$t['id'] ?>">Editar</a>
            <form method="post" style="display:inline">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <button class="mini" type="submit" name="action" value="<?= (int)$t['ativo'] === 1 ? 'disable_template' : 'enable_template' ?>"><?= (int)$t['ativo'] === 1 ? 'Inativar' : 'Ativar' ?></button>
            </form>
          </div>
        </td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$templates): ?><tr><td colspan="6" class="muted">Nenhum modelo cadastrado.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<div class="panel" style="margin-top:18px">
  <h3>Últimos envios do WhatsApp</h3>
  <div class="table-wrap"><table class="log-table"><thead><tr><th>Data</th><th>Empresa</th><th>Tipo</th><th>Destino</th><th>Status</th><th>Erro</th></tr></thead><tbody>
    <?php foreach ($recentLogs as $l): ?>
      <tr>
        <td><?= e(saas_datetime_br($l['created_at'] ?? null)) ?></td>
        <td><?= e((string)($l['company_name'] ?? '—')) ?></td>
        <td><?= e((string)$l['tipo']) ?><br><span class="muted"><?= e((string)$l['provider']) ?></span></td>
        <td><?= e((string)($l['destino'] ?? '—')) ?></td>
        <td><span class="pill"><?= e((string)$l['status']) ?></span></td>
        <td><?= e((string)($l['erro'] ?? '')) ?></td>
      </tr>
    <?php endforeach; ?>
    <?php if (!$recentLogs): ?><tr><td colspan="6" class="muted">Nenhum envio registrado.</td></tr><?php endif; ?>
  </tbody></table></div>
</div>

<script>
(function(){
  const statusEl = document.getElementById('waStatus');
  const qrBox = document.getElementById('qrBox');
  const log = document.getElementById('waLog');
  const testPhone = <?= json_encode((string)$cfg['test_phone']) ?>;
  const instanceEl = document.getElementById('waInstance');

  function setStatus(text, cls){ statusEl.className = 'wa-status ' + (cls || 'warn'); statusEl.textContent = text; }
  function writeLog(obj){
    if (typeof obj === 'string') { log.textContent = obj; return; }
    const clean = Object.assign({}, obj || {});
    if (clean.qrDataUrl) clean.qrDataUrl = '[QR Code carregado acima]';
    if (clean.qr) clean.qr = '[QR Code recebido]';
    log.textContent = JSON.stringify(clean, null, 2);
  }
  function rawError(obj){
    if (!obj) return '';
    return String(obj.lastError || obj.error || obj.message || '');
  }
  function isBrowserLockError(value){
    const text = String(value || '').toLowerCase();
    return text.includes('browser is already running') || text.includes('use a different userdata') || text.includes('userdatadir');
  }
  function escapeHtml(value){
    return String(value || '').replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; });
  }
  function showSessionRepair(errorText){
    setStatus('Sessão travada', 'err');
    qrBox.innerHTML = '<div class="wa-repair-box"><b>Esta conexão ficou presa no navegador do WhatsApp.</b><br>Clique em <b>Corrigir conexão</b> para gerar uma nova sessão da Central SaaS e liberar o QR Code novamente.<code>' + escapeHtml(errorText) + '</code><button class="btn2 btn2-warning" type="button" onclick="document.getElementById(\'btnRepair\').click()">Corrigir conexão agora</button></div>';
  }
  function getNewInstanceId(res){
    if (!res) return '';
    if (res.new_instance_id) return String(res.new_instance_id);
    if (res.repair && res.repair.new_instance_id) return String(res.repair.new_instance_id);
    return '';
  }
  async function call(action, options){
    const response = await fetch('whatsapp_api.php?action=' + encodeURIComponent(action), Object.assign({cache:'no-store',headers:{'Accept':'application/json'}}, options || {}));
    const text = await response.text();
    try { return JSON.parse(text); } catch(e){ return {ok:false,error:text || 'Resposta inválida'}; }
  }
  let refreshRunning = false;
  async function refresh(force){
    if (refreshRunning && !force) return;
    refreshRunning = true;
    try {
      setStatus('Verificando...', 'warn');
      qrBox.innerHTML = '<div class="wa-muted">Consultando conexão...</div>';
      const st = await call('status');
      writeLog(st);
      const stError = rawError(st);
      if (st && (st.connected || st.ready || st.status === 'connected' || st.status === 'ready')) {
        setStatus('Conectado', 'ok');
        qrBox.innerHTML = '<div><strong>WhatsApp conectado.</strong><br><span class="wa-muted">A Central SaaS já pode enviar mensagens.</span></div>';
        return;
      }
      if (isBrowserLockError(stError)) { showSessionRepair(stError); return; }
      if (st && st.error) {
        setStatus('Erro', 'err');
        qrBox.innerHTML = '<div class="wa-muted">' + escapeHtml(st.error) + '</div>';
        return;
      }
      setStatus('Aguardando QR Code', 'warn');
      const qr = await call('qr');
      writeLog(qr);
      const qrError = rawError(qr);
      const src = qr.qrDataUrl || qr.qr_image || qr.qrImage || '';
      if (qr && (qr.connected || qr.ready || qr.status === 'connected' || qr.status === 'ready')) {
        setStatus('Conectado', 'ok');
        qrBox.innerHTML = '<div><strong>WhatsApp conectado.</strong><br><span class="wa-muted">A Central SaaS já pode enviar mensagens.</span></div>';
      } else if (isBrowserLockError(qrError)) {
        showSessionRepair(qrError);
      } else if (src) qrBox.innerHTML = '<img src="' + src + '" alt="QR Code WhatsApp">';
      else if (qr.qr && String(qr.qr).startsWith('data:image')) qrBox.innerHTML = '<img src="' + qr.qr + '" alt="QR Code WhatsApp">';
      else qrBox.innerHTML = '<div class="wa-muted">QR Code ainda não disponível. Atualize novamente em alguns segundos.</div>';
    } finally {
      refreshRunning = false;
    }
  }
  document.getElementById('btnRefresh').addEventListener('click', () => refresh(true));
  document.getElementById('btnRestart').addEventListener('click', async () => {
    if (!confirm('Reiniciar somente esta sessão do WhatsApp preservando a autenticação atual?')) return;
    setStatus('Reiniciando...', 'warn');
    const res = await call('restart', {method:'POST'});
    writeLog(res);
    setTimeout(() => refresh(true), 1800);
  });
  document.getElementById('btnRepair').addEventListener('click', async () => {
    if (!confirm('Corrigir esta conexão do WhatsApp?')) return;
    setStatus('Corrigindo...', 'warn');
    qrBox.innerHTML = '<div class="wa-muted">Encerrando a sessão antiga e tentando abrir nova conexão...</div>';
    const res = await call('reconnect', {method:'POST'});
    const newInstance = getNewInstanceId(res);
    if (newInstance && instanceEl) instanceEl.textContent = 'Sessão WhatsApp atual: company-' + newInstance;
    writeLog(res);
    setTimeout(() => refresh(true), 1800);
  });
  document.getElementById('btnLogout').addEventListener('click', async () => {
    if (!confirm('Desconectar a instância central do WhatsApp?')) return;
    const res = await call('logout', {method:'POST'});
    writeLog(res);
    await refresh(true);
  });
  document.getElementById('btnTest').addEventListener('click', async () => {
    const to = prompt('Enviar teste para qual WhatsApp?', testPhone || '');
    if (!to) return;
    const body = new URLSearchParams();
    body.set('to', to);
    const res = await call('test', {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'}, body});
    writeLog(res);
    alert(res && res.ok ? 'Teste enviado com sucesso.' : ('Falha no teste: ' + (res.error || (res.result && res.result.error) || 'verifique o log.')));
  });
  refresh(true);
})();
</script>

<?php saas_layout_end(); ?>
