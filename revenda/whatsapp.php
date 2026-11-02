<?php
declare(strict_types=1);

require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_layout.php';

$msg = '';
$erro = '';
$cfg = reseller_whatsapp_load_config($pdo, RESELLER_COMPANY_ID);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? 'save_settings');

    if ($action === 'save_settings' || $action === 'generate_token') {
      $data = $_POST;
      $provider = (string)($data['provider'] ?? $cfg['provider'] ?? 'unofficial');
      $apiToken = trim((string)($data['api_token'] ?? ''));

      if ($provider === 'unofficial' && ($action === 'generate_token' || $apiToken === '')) {
        $data['api_token'] = saas_whatsapp_generate_token();
      }

      $cfg = reseller_whatsapp_save_config($pdo, RESELLER_COMPANY_ID, $data);
      reseller_save_contact_whatsapp(
        $pdo,
        RESELLER_COMPANY_ID,
        (string)($data['reseller_whatsapp'] ?? ($reseller['reseller_whatsapp'] ?? ''))
      );
      $reseller = reseller_company_by_id($pdo, RESELLER_COMPANY_ID) ?: $reseller;

      $syncMsg = '';
      if ((string)$cfg['provider'] === 'unofficial' && trim((string)$cfg['api_token']) !== '') {
        $sync = saas_whatsapp_register_unofficial_token($cfg, (int)$cfg['unofficial_instance_id'], (string)$cfg['api_token']);
        $syncMsg = !empty($sync['ok'])
          ? ' Token registrado no serviço WhatsApp.'
          : ' Token salvo; valide a conexão pelo QR Code.';
      }
      $msg = 'Configurações do WhatsApp da revenda salvas com sucesso.' . $syncMsg;
    } elseif ($action === 'save_template') {
      reseller_whatsapp_save_template($pdo, RESELLER_COMPANY_ID, $_POST);
      $msg = 'Modelo de mensagem salvo com sucesso.';
    } elseif ($action === 'disable_template' || $action === 'enable_template') {
      $id = (int)($_POST['id'] ?? 0);
      reseller_whatsapp_set_template_active($pdo, RESELLER_COMPANY_ID, $id, $action === 'enable_template');
      $msg = $action === 'enable_template' ? 'Modelo ativado com sucesso.' : 'Modelo inativado com sucesso.';
    } elseif ($action === 'reset_templates') {
      reseller_whatsapp_reset_default_templates($pdo, RESELLER_COMPANY_ID);
      $msg = 'Modelos padrões da revenda restaurados com sucesso.';
    } elseif ($action === 'send_due') {
      $r = reseller_whatsapp_send_due_notices($pdo, RESELLER_COMPANY_ID);
      $msg = (int)$r['sent'] . ' aviso(s) enviado(s). ' . (int)$r['skipped'] . ' já haviam sido enviados hoje.';
      if (!empty($r['errors'])) $erro = implode(' | ', $r['errors']);
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
  }
}

$cfg = reseller_whatsapp_load_config($pdo, RESELLER_COMPANY_ID);
$templates = reseller_whatsapp_templates($pdo, RESELLER_COMPANY_ID);
$edit = null;
if (isset($_GET['edit_template'])) {
  $edit = reseller_whatsapp_get_template($pdo, RESELLER_COMPANY_ID, (int)$_GET['edit_template']);
}

$logs = [];
try {
  $st = $pdo->prepare('SELECT l.*,c.name AS client_name FROM saas_reseller_whatsapp_logs l LEFT JOIN companies c ON c.id=l.client_company_id WHERE l.reseller_company_id=? ORDER BY l.id DESC LIMIT 25');
  $st->execute([RESELLER_COMPANY_ID]);
  $logs = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

$contactWhatsapp = trim((string)($reseller['reseller_whatsapp'] ?? ''));
$currentProvider = (string)($cfg['provider'] ?? 'unofficial');

revenda_layout_start('WhatsApp da revenda', 'whatsapp', $reseller);
?>
<style>
.rvwa-page{display:grid;gap:18px}.rvwa-grid{display:grid;grid-template-columns:minmax(0,1.08fr) minmax(360px,.92fr);gap:18px;align-items:start}.rvwa-card{background:#fff;border:1px solid #d9e5fb;border-radius:22px;padding:20px;box-shadow:0 18px 48px rgba(9,31,91,.08);min-width:0}.rvwa-card h2{font-size:19px;margin:0 0 6px;color:var(--primary)}.rvwa-card h3{font-size:15px;margin:20px 0 7px;color:var(--primary)}.rvwa-muted{color:#64748b;font-size:13px;line-height:1.5}.rvwa-form>label,.rvwa-field>label{display:block;margin:13px 0 7px;font-weight:800;color:var(--primary)}.rvwa-form input[type=text],.rvwa-form input[type=tel],.rvwa-form input[type=number],.rvwa-form select,.rvwa-form textarea{width:100%;border:1px solid #bfd2f4;border-radius:14px;padding:11px 12px;background:#fff;color:var(--primary);font:inherit}.rvwa-form textarea{resize:vertical;min-height:95px}.rvwa-row{display:grid;grid-template-columns:1fr 1fr;gap:12px}.rvwa-provider{display:grid;grid-template-columns:1fr 1fr;gap:10px;margin-top:8px}.rvwa-provider label{display:flex;align-items:flex-start;gap:9px;margin:0;border:1px solid #d9e5fb;border-radius:17px;padding:13px;background:#fff;cursor:pointer}.rvwa-provider label:has(input:checked){border-color:#60a5fa;background:#eff6ff;box-shadow:0 0 0 2px rgba(59,130,246,.08)}.rvwa-provider input{margin-top:3px}.rvwa-provider strong{display:block;color:var(--primary)}.rvwa-provider small{display:block;color:#64748b;margin-top:3px;line-height:1.4}.rvwa-checks{display:grid;gap:9px;margin-top:14px}.rvwa-check{display:flex!important;align-items:flex-start;gap:10px;margin:0!important;padding:12px 13px;border:1px solid #d9e5fb;border-radius:16px;background:#f8fbff;font-weight:400!important}.rvwa-check input{margin-top:3px}.rvwa-check b{color:var(--primary)}.rvwa-divider{height:1px;background:#e5eefb;margin:19px 0}.rvwa-actions{display:flex;gap:9px;flex-wrap:wrap;margin-top:15px}.rvwa-actions button,.rvwa-actions a{min-height:42px}.rvwa-statusbar{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;margin:12px 0}.rvwa-status{display:inline-flex;align-items:center;border-radius:999px;padding:8px 11px;font-size:12px;font-weight:900}.rvwa-status.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.rvwa-status.warn{background:#fff7ed;color:#c2410c;border:1px solid #fdba74}.rvwa-status.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.rvwa-instance{font-size:12px;font-weight:800;color:var(--primary);margin:9px 0}.rvwa-qr{border:1px dashed #bfd2f4;background:#f8fbff;border-radius:18px;min-height:270px;display:flex;align-items:center;justify-content:center;text-align:center;padding:18px;overflow:hidden}.rvwa-qr img{max-width:280px;width:100%;height:auto}.rvwa-repair{width:100%;text-align:left;border:1px solid #fecaca;background:#fff7f7;color:#7f1d1d;border-radius:16px;padding:15px;line-height:1.5}.rvwa-repair code{display:block;margin:9px 0;padding:9px;border-radius:10px;background:#fff;border:1px solid #fecaca;white-space:pre-wrap;word-break:break-word}.rvwa-tech{margin-top:12px;border:1px solid #d9e5fb;border-radius:14px;background:#f8fbff;overflow:hidden}.rvwa-tech summary{cursor:pointer;padding:11px 13px;font-weight:800;color:var(--primary)}.rvwa-log{margin:0;border-radius:0;max-height:250px}.rvwa-template-grid{display:grid;grid-template-columns:minmax(0,.9fr) minmax(0,1.1fr);gap:18px;align-items:start}.rvwa-vars{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:8px;margin-top:10px}.rvwa-vars code{display:block;background:#eef6ff;border:1px solid #dbeafe;color:var(--primary);border-radius:10px;padding:7px 8px;font-size:12px;word-break:break-word}.rvwa-table-wrap{overflow:auto}.rvwa-table td{vertical-align:top}.rvwa-template-preview{font-size:12px;color:#475569;white-space:pre-wrap;max-height:74px;overflow:hidden;min-width:220px}.rvwa-pill-off{background:#f1f5f9!important;color:#64748b!important}.rvwa-row-actions{display:flex;gap:7px;flex-wrap:wrap}.rvwa-note{padding:12px 14px;border-radius:15px;background:#eef6ff;border:1px solid #bfdbfe;color:#1e3a8a;font-size:13px;line-height:1.5}.rvwa-advanced{margin-top:14px;border:1px solid #d9e5fb;border-radius:16px;background:#fbfdff}.rvwa-advanced summary{cursor:pointer;padding:13px 14px;font-weight:900;color:var(--primary)}.rvwa-advanced-body{padding:0 14px 14px}.rvwa-contact{display:grid;grid-template-columns:minmax(220px,.65fr) minmax(0,1.35fr);gap:12px;align-items:end}.rvwa-btn-warning{background:#fff7ed!important;color:#9a3412!important;border-color:#fdba74!important}@media(max-width:1100px){.rvwa-grid,.rvwa-template-grid{grid-template-columns:1fr}.rvwa-row,.rvwa-provider,.rvwa-contact{grid-template-columns:1fr}.rvwa-vars{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:620px){.rvwa-vars{grid-template-columns:1fr}}
</style>

<div class="rvwa-page">
  <?php if ($msg): ?><div class="ok"><?= rev_h($msg) ?></div><?php endif; ?>
  <?php if ($erro): ?><div class="err"><?= rev_h($erro) ?></div><?php endif; ?>

  <div class="rvwa-grid">
    <section class="rvwa-card">
      <h2>Configurações gerais</h2>
      <p class="rvwa-muted">Configuração exclusiva desta revenda. Nenhum ajuste desta tela altera o WhatsApp das empresas cadastradas nem a Central SaaS.</p>

      <form method="post" class="rvwa-form" id="rvwaForm">
        <input type="hidden" name="action" value="save_settings">

        <div class="rvwa-contact">
          <div>
            <label>WhatsApp de atendimento da revenda</label>
            <input type="tel" name="reseller_whatsapp" inputmode="tel" value="<?= rev_h($contactWhatsapp) ?>" placeholder="(31) 99999-9999">
          </div>
          <div class="rvwa-note">Número comercial exibido no cadastro dos clientes e no Monitor de WhatsApp do suporte. A sessão técnica conectada por QR Code continua sendo controlada separadamente.</div>
        </div>

        <div class="rvwa-divider"></div>
        <label>Tipo de integração</label>
        <div class="rvwa-provider">
          <label><input type="radio" name="provider" value="unofficial" <?= $currentProvider === 'unofficial' ? 'checked' : '' ?>><span><strong>API Não Oficial</strong><small>Conexão por QR Code usando a instância exclusiva desta revenda.</small></span></label>
          <label><input type="radio" name="provider" value="official" <?= $currentProvider === 'official' ? 'checked' : '' ?>><span><strong>API Oficial / Meta</strong><small>Cloud API com Phone Number ID e token de acesso.</small></span></label>
        </div>

        <div class="rvwa-checks">
          <label class="rvwa-check"><input type="checkbox" name="enabled" value="1" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>><span><b>Ativar WhatsApp da revenda</b><br><span class="rvwa-muted">Controla os envios feitos pela automação exclusiva da revenda.</span></span></label>
          <label class="rvwa-check"><input type="checkbox" name="auto_send_credentials" value="1" <?= !empty($cfg['auto_send_credentials']) ? 'checked' : '' ?>><span><b>Enviar credenciais após cadastro</b><br><span class="rvwa-muted">Envia links, usuário e senha para empresas cadastradas por esta revenda.</span></span></label>
          <label class="rvwa-check"><input type="checkbox" name="auto_send_billing" value="1" <?= !empty($cfg['auto_send_billing']) ? 'checked' : '' ?>><span><b>Enviar avisos de cobrança</b><br><span class="rvwa-muted">Utiliza os modelos de vencimento configurados abaixo.</span></span></label>
          <label class="rvwa-check"><input type="checkbox" name="auto_send_status" value="1" <?= !empty($cfg['auto_send_status']) ? 'checked' : '' ?>><span><b>Enviar bloqueio e reativação</b><br><span class="rvwa-muted">Avisa o cliente quando a revenda altera o status de acesso.</span></span></label>
        </div>

        <div class="rvwa-row">
          <div><label>Dias antes do vencimento</label><input type="number" min="1" max="15" name="billing_notice_days" value="<?= (int)$cfg['billing_notice_days'] ?>"></div>
          <div><label>Dias de tolerância para bloqueio</label><input type="number" min="0" max="30" name="block_grace_days" value="<?= (int)$cfg['block_grace_days'] ?>"></div>
        </div>
        <div class="rvwa-row">
          <div><label>DDI padrão</label><input type="text" name="default_country_code" value="<?= rev_h((string)$cfg['default_country_code']) ?>" placeholder="55"></div>
          <div><label>Telefone para teste</label><input type="tel" name="test_phone" value="<?= rev_h((string)$cfg['test_phone']) ?>" placeholder="5531999999999"></div>
        </div>

        <details class="rvwa-advanced">
          <summary>Configurações avançadas da integração</summary>
          <div class="rvwa-advanced-body">
            <h3>API Não Oficial</h3>
            <label>URL do serviço WhatsApp</label>
            <input type="text" name="service_url" value="<?= rev_h((string)$cfg['service_url']) ?>" placeholder="http://127.0.0.1:3333">
            <div class="rvwa-row">
              <div><label>ID da instância da revenda</label><input type="text" name="unofficial_instance_id" value="<?= rev_h((string)$cfg['unofficial_instance_id']) ?>" readonly><div class="rvwa-muted">ID técnico exclusivo desta revenda. Não altere manualmente.</div></div>
              <div><label>Token da API não oficial</label><input type="text" name="api_token" value="<?= rev_h((string)$cfg['api_token']) ?>" placeholder="Gerado automaticamente"><div class="rvwa-muted">Se ficar vazio, um token será gerado ao salvar.</div></div>
            </div>

            <h3>API Oficial / Meta</h3>
            <div class="rvwa-row">
              <div><label>Graph API version</label><input type="text" name="official_graph_version" value="<?= rev_h((string)$cfg['official_graph_version']) ?>" placeholder="v20.0"></div>
              <div><label>Phone Number ID</label><input type="text" name="official_phone_number_id" value="<?= rev_h((string)$cfg['official_phone_number_id']) ?>"></div>
            </div>
            <label>Token de acesso oficial</label>
            <textarea name="official_access_token" rows="4" placeholder="Token da Meta"><?= rev_h((string)$cfg['official_access_token']) ?></textarea>
            <input type="hidden" name="official_language_code" value="pt_BR">
            <input type="hidden" name="cron_token" value="<?= rev_h((string)$cfg['cron_token']) ?>">
          </div>
        </details>

        <div class="rvwa-actions">
          <button class="btn btn-primary" type="submit" name="action" value="save_settings">Salvar configurações</button>
          <button class="btn btn-ghost" type="submit" name="action" value="generate_token" onclick="return confirm('Gerar novo token somente para esta revenda? O token anterior desta sessão deixará de funcionar.');">Gerar token automático</button>
          <button class="btn btn-ghost" type="button" id="btnTest">Enviar teste</button>
        </div>
      </form>
    </section>

    <section class="rvwa-card">
      <h2>Conexão por QR Code</h2>
      <p class="rvwa-muted">Mesma rotina operacional já estabilizada no sistema, aplicada somente à instância exclusiva desta revenda.</p>
      <div class="rvwa-statusbar">
        <span id="waStatus" class="rvwa-status warn">Verificando...</span>
        <div class="rvwa-actions" style="margin:0">
          <button class="btn btn-ghost" type="button" id="btnRefresh">Atualizar</button>
          <button class="btn btn-ghost" type="button" id="btnRestart">Reiniciar sessão</button>
          <button class="btn btn-ghost rvwa-btn-warning" type="button" id="btnRepair">Corrigir conexão</button>
          <button class="btn btn-danger" type="button" id="btnLogout">Desconectar</button>
        </div>
      </div>
      <div class="rvwa-instance" id="waInstance">Sessão WhatsApp atual: company-<?= rev_h((string)$cfg['unofficial_instance_id']) ?></div>
      <div class="rvwa-qr" id="qrBox"><div class="rvwa-muted">Consultando a conexão desta revenda...</div></div>
      <details class="rvwa-tech">
        <summary>Detalhes técnicos da conexão</summary>
        <pre class="codebox rvwa-log" id="waLog">Aguardando conexão...</pre>
      </details>

      <form method="post" style="margin-top:14px">
        <input type="hidden" name="action" value="send_due">
        <div class="rvwa-actions"><button class="btn btn-primary" type="submit">Enviar avisos pendentes agora</button></div>
      </form>
      <p class="rvwa-muted" style="margin-top:13px">Rotina automática: <code>/revenda/cron_whatsapp.php?token=<?= rev_h((string)$cfg['cron_token']) ?></code></p>
    </section>
  </div>

  <div class="rvwa-template-grid">
    <section class="rvwa-card">
      <h2><?= $edit ? 'Editar modelo de mensagem' : 'Criar modelo de mensagem' ?></h2>
      <p class="rvwa-muted">Modelos exclusivos desta revenda para credenciais, cobranças e mudança de status.</p>
      <form method="post" class="rvwa-form">
        <input type="hidden" name="action" value="save_template">
        <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
        <div class="rvwa-row">
          <div><label>Tipo</label><select name="tipo"><option value="credentials" <?= (($edit['tipo'] ?? '') === 'credentials') ? 'selected' : '' ?>>Credenciais</option><option value="billing_notice" <?= (!$edit || ($edit['tipo'] ?? '') === 'billing_notice') ? 'selected' : '' ?>>Cobrança / vencimento</option><option value="status_notice" <?= (($edit['tipo'] ?? '') === 'status_notice') ? 'selected' : '' ?>>Bloqueio / reativação</option></select></div>
          <div><label>Dias de referência</label><input type="text" name="dias_referencia" value="<?= rev_h((string)($edit['dias_referencia'] ?? '')) ?>" placeholder="3, 0, -1, -100, 100"><div class="rvwa-muted">Em credenciais, deixe vazio. Status usa -100 para bloqueio e 100 para reativação.</div></div>
        </div>
        <label>Título interno</label><input type="text" name="titulo" value="<?= rev_h((string)($edit['titulo'] ?? '')) ?>" placeholder="Ex.: Faltam 3 dias para vencer">
        <label>Mensagem</label><textarea name="body" rows="10"><?= rev_h((string)($edit['body'] ?? '')) ?></textarea>
        <label class="rvwa-check"><input type="checkbox" name="ativo" value="1" <?= !isset($edit['ativo']) || !empty($edit['ativo']) ? 'checked' : '' ?>><span><b>Modelo ativo</b><br><span class="rvwa-muted">Somente modelos ativos são utilizados nos envios.</span></span></label>
        <div class="rvwa-actions"><button class="btn btn-primary" type="submit">Salvar modelo</button><?php if ($edit): ?><a class="btn btn-ghost" href="/revenda/whatsapp.php">Novo modelo</a><?php endif; ?></div>
      </form>
    </section>

    <section class="rvwa-card">
      <h2>Variáveis disponíveis</h2>
      <p class="rvwa-muted">O sistema substitui estas variáveis automaticamente pelos dados do cliente e da revenda.</p>
      <div class="rvwa-vars"><code>{{empresa}}</code><code>{{descricao}}</code><code>{{valor}}</code><code>{{vencimento}}</code><code>{{dias}}</code><code>{{dias_vencido}}</code><code>{{link_pagamento}}</code><code>{{link_admin}}</code><code>{{link_catalogo_delivery}}</code><code>{{link_catalogo}}</code><code>{{usuario}}</code><code>{{senha}}</code><code>{{link_treinamento}}</code><code>{{bloco_treinamento}}</code><code>{{revenda}}</code><code>{{whatsapp_revenda}}</code></div>
      <form method="post" style="margin-top:16px" onsubmit="return confirm('Restaurar os modelos padrões desta revenda? Os modelos atuais serão substituídos.');"><input type="hidden" name="action" value="reset_templates"><button class="btn btn-ghost" type="submit">Restaurar modelos padrão</button></form>
    </section>
  </div>

  <section class="rvwa-card">
    <h2>Modelos cadastrados</h2>
    <div class="rvwa-table-wrap"><table class="rvwa-table"><thead><tr><th>Tipo</th><th>Dias</th><th>Título</th><th>Mensagem</th><th>Status</th><th>Ações</th></tr></thead><tbody>
      <?php foreach ($templates as $t): ?>
        <tr><td><?= rev_h((string)$t['tipo']) ?></td><td><?= $t['dias_referencia'] === null ? '—' : rev_h((string)$t['dias_referencia']) ?></td><td><strong><?= rev_h((string)$t['titulo']) ?></strong></td><td><div class="rvwa-template-preview"><?= rev_h((string)$t['body']) ?></div></td><td><span class="pill <?= (int)$t['ativo'] === 1 ? '' : 'rvwa-pill-off' ?>"><?= (int)$t['ativo'] === 1 ? 'ativo' : 'inativo' ?></span></td><td><div class="rvwa-row-actions"><a class="btn btn-ghost" href="?edit_template=<?= (int)$t['id'] ?>">Editar</a><form method="post"><input type="hidden" name="id" value="<?= (int)$t['id'] ?>"><button class="btn btn-ghost" type="submit" name="action" value="<?= (int)$t['ativo'] === 1 ? 'disable_template' : 'enable_template' ?>"><?= (int)$t['ativo'] === 1 ? 'Inativar' : 'Ativar' ?></button></form></div></td></tr>
      <?php endforeach; ?>
      <?php if (!$templates): ?><tr><td colspan="6">Nenhum modelo cadastrado.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>

  <section class="rvwa-card">
    <h2>Últimos envios</h2>
    <div class="rvwa-table-wrap"><table><thead><tr><th>Data</th><th>Cliente</th><th>Tipo</th><th>Destino</th><th>Status</th><th>Erro</th></tr></thead><tbody>
      <?php foreach ($logs as $l): ?><tr><td><?= rev_h(rev_datetime_br($l['created_at'])) ?></td><td><?= rev_h((string)($l['client_name'] ?? '—')) ?></td><td><?= rev_h((string)$l['tipo']) ?><br><span class="rvwa-muted"><?= rev_h((string)($l['provider'] ?? '')) ?></span></td><td><?= rev_h((string)($l['destino'] ?? '—')) ?></td><td><span class="pill"><?= rev_h((string)$l['status']) ?></span></td><td><?= rev_h((string)($l['erro'] ?? '')) ?></td></tr><?php endforeach; ?>
      <?php if (!$logs): ?><tr><td colspan="6">Nenhum envio registrado.</td></tr><?php endif; ?>
    </tbody></table></div>
  </section>
</div>

<script>
(function(){
  const statusEl = document.getElementById('waStatus');
  const qrBox = document.getElementById('qrBox');
  const logEl = document.getElementById('waLog');
  const instanceEl = document.getElementById('waInstance');
  const testPhone = <?= json_encode((string)$cfg['test_phone']) ?>;
  const provider = <?= json_encode($currentProvider) ?>;

  function setStatus(text, cls){ statusEl.className = 'rvwa-status ' + (cls || 'warn'); statusEl.textContent = text; }
  function sanitize(value, key){
    if (typeof value === 'string') {
      if (value.indexOf('data:image/') === 0 || value.length > 5000) return key && key.toLowerCase().includes('qr') ? '[QR Code recebido]' : '[conteúdo técnico omitido]';
      return value;
    }
    if (Array.isArray(value)) return value.map(function(v){ return sanitize(v, key); });
    if (value && typeof value === 'object') {
      const out = {};
      Object.keys(value).forEach(function(k){ out[k] = sanitize(value[k], k); });
      return out;
    }
    return value;
  }
  function writeLog(obj){ try { logEl.textContent = JSON.stringify(sanitize(obj || {}), null, 2); } catch(e){ logEl.textContent = String(obj || ''); } }
  function rawError(obj){ return obj ? String(obj.lastError || obj.error || obj.message || '') : ''; }
  function isBrowserLockError(value){ const text = String(value || '').toLowerCase(); return text.includes('browser is already running') || text.includes('use a different userdata') || text.includes('userdatadir'); }
  function escapeHtml(value){ return String(value || '').replace(/[&<>"']/g, function(ch){ return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch]; }); }
  function showRepair(errorText){ setStatus('Sessão travada','err'); qrBox.innerHTML = '<div class="rvwa-repair"><b>Esta sessão do WhatsApp da revenda ficou presa.</b><br>Use <b>Corrigir conexão</b> para encerrar somente esta sessão, criar uma nova instância da revenda e liberar outro QR Code.<code>' + escapeHtml(errorText) + '</code><button class="btn btn-ghost rvwa-btn-warning" type="button" onclick="document.getElementById(\'btnRepair\').click()">Corrigir conexão agora</button></div>'; }
  function getNewInstanceId(res){ if (!res) return ''; return String(res.new_instance_id || (res.repair && res.repair.new_instance_id) || ''); }
  async function call(action, options){
    const response = await fetch('whatsapp_api.php?action=' + encodeURIComponent(action), Object.assign({cache:'no-store',headers:{'Accept':'application/json'}}, options || {}));
    const text = await response.text();
    try { return JSON.parse(text); } catch(e){ return {ok:false,error:text || 'Resposta inválida'}; }
  }

  let refreshing = false;
  async function refresh(force){
    if (provider !== 'unofficial') {
      setStatus('API Oficial / Meta','ok');
      qrBox.innerHTML = '<div class="rvwa-muted"><b>QR Code não é utilizado na API Oficial.</b><br>Use o botão Enviar teste após salvar Phone Number ID e token.</div>';
      return;
    }
    if (refreshing && !force) return;
    refreshing = true;
    try {
      setStatus('Verificando...','warn');
      qrBox.innerHTML = '<div class="rvwa-muted">Consultando a conexão da revenda...</div>';
      const st = await call('status');
      writeLog(st);
      const stError = rawError(st);
      if (st && (st.connected || st.ready || st.status === 'connected' || st.status === 'ready')) {
        setStatus('Conectado','ok');
        qrBox.innerHTML = '<div><strong>WhatsApp conectado.</strong><br><span class="rvwa-muted">A revenda já pode utilizar os envios configurados.</span></div>';
        return;
      }
      if (isBrowserLockError(stError)) { showRepair(stError); return; }
      if (st && st.error && !String(st.error).toLowerCase().includes('not initialized')) {
        setStatus('Erro','err');
        qrBox.innerHTML = '<div class="rvwa-muted">' + escapeHtml(st.error) + '</div>';
        return;
      }
      setStatus('Aguardando QR Code','warn');
      const qr = await call('qr');
      writeLog(qr);
      const qrError = rawError(qr);
      const src = qr.qrDataUrl || qr.qr_image || qr.qrImage || (typeof qr.qr === 'string' && qr.qr.indexOf('data:image') === 0 ? qr.qr : '');
      if (qr && (qr.connected || qr.ready || qr.status === 'connected' || qr.status === 'ready')) {
        setStatus('Conectado','ok');
        qrBox.innerHTML = '<div><strong>WhatsApp conectado.</strong><br><span class="rvwa-muted">A revenda já pode utilizar os envios configurados.</span></div>';
      } else if (isBrowserLockError(qrError)) {
        showRepair(qrError);
      } else if (src) {
        qrBox.innerHTML = '<div><img src="' + src + '" alt="QR Code WhatsApp"><div class="rvwa-muted" style="margin-top:10px">Leia o QR Code com o WhatsApp que será usado pela revenda.</div></div>';
      } else if (qr && qr.error) {
        setStatus('Erro','err');
        qrBox.innerHTML = '<div class="rvwa-muted">' + escapeHtml(qr.error) + '</div>';
      } else {
        qrBox.innerHTML = '<div class="rvwa-muted">QR Code ainda não disponível. Clique em Atualizar novamente em alguns segundos.</div>';
      }
    } catch (e) {
      setStatus('Falha na consulta','err');
      qrBox.innerHTML = '<div class="rvwa-muted">Não foi possível falar com o serviço WhatsApp.</div>';
      writeLog({ok:false,error:String(e)});
    } finally { refreshing = false; }
  }

  document.getElementById('btnRefresh').addEventListener('click', function(){ refresh(true); });
  document.getElementById('btnRestart').addEventListener('click', async function(){
    if (provider !== 'unofficial') return alert('Reinício por QR Code é usado somente na API Não Oficial.');
    if (!confirm('Reiniciar somente a sessão desta revenda preservando a autenticação atual?')) return;
    setStatus('Reiniciando...','warn');
    const res = await call('restart',{method:'POST'}); writeLog(res); setTimeout(function(){ refresh(true); },1800);
  });
  document.getElementById('btnRepair').addEventListener('click', async function(){
    if (provider !== 'unofficial') return alert('Correção por QR Code é usada somente na API Não Oficial.');
    if (!confirm('Corrigir somente a conexão desta revenda? Uma nova instância será criada e será necessário ler outro QR Code.')) return;
    setStatus('Corrigindo...','warn');
    qrBox.innerHTML = '<div class="rvwa-muted">Encerrando a sessão antiga e preparando uma nova conexão exclusiva da revenda...</div>';
    const res = await call('reconnect',{method:'POST'});
    const newId = getNewInstanceId(res); if (newId && instanceEl) instanceEl.textContent = 'Sessão WhatsApp atual: company-' + newId;
    writeLog(res); setTimeout(function(){ refresh(true); },1800);
  });
  document.getElementById('btnLogout').addEventListener('click', async function(){
    if (provider !== 'unofficial') return alert('Desconexão por QR Code é usada somente na API Não Oficial.');
    if (!confirm('Desconectar somente o WhatsApp desta revenda?')) return;
    const res = await call('logout',{method:'POST'}); writeLog(res); setTimeout(function(){ refresh(true); },1000);
  });
  document.getElementById('btnTest').addEventListener('click', async function(){
    const to = prompt('Enviar teste para qual WhatsApp?', testPhone || ''); if (!to) return;
    const body = new URLSearchParams(); body.set('to',to);
    const res = await call('test',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded','Accept':'application/json'},body:body});
    writeLog(res); alert(res && res.ok ? 'Teste enviado com sucesso.' : ('Falha no teste: ' + (res.error || (res.result && res.result.error) || 'verifique os detalhes técnicos.')));
  });

  refresh(true);
})();
</script>
<?php revenda_layout_end(); ?>
