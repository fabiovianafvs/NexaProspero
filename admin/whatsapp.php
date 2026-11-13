<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/load_config.php';
require_once __DIR__ . '/../config/whatsapp_service.php';

$msg = '';
$erro = '';
$waCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'whatsapp_config')
  : (int)COMPANY_ID;
$waCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'whatsapp_config');
$waMcCsrf = ($waCrossContext && function_exists('multi_company_context_csrf_token'))
  ? multi_company_context_csrf_token()
  : '';
$waMcCtx = ($waCrossContext && function_exists('multi_company_context_request_token'))
  ? multi_company_context_request_token()
  : '';
$cfg = whatsapp_load_config($pdo, $waCompanyId, false, true);
$messageModels = whatsapp_message_models_load($pdo, $waCompanyId);
$appCfg = load_app_config($waCompanyId);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ($waCrossContext && (!function_exists('multi_company_context_csrf_valid')
        || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('V11.22.1: sessão de segurança expirada. Atualize a página antes de alterar o WhatsApp da empresa selecionada.');
    }
    $action = (string)($_POST['action'] ?? 'save');
    $oldCfg = $cfg;

    $apiToken = trim((string)($_POST['api_token'] ?? ''));
    $syncMsg = '';

    if ($action === 'generate_token' || $apiToken === '') {
      $apiToken = whatsapp_generate_service_token();

      // Tenta registrar automaticamente o token no serviço Node/PM2.
      // Se falhar, salva mesmo assim e informa o usuário para reiniciar/atualizar o server.js.
      $syncTargets = array_values(array_unique([$waCompanyId, whatsapp_service_company_id($oldCfg, $waCompanyId)]));
      $syncOk = false;
      $syncErrors = [];
      foreach ($syncTargets as $syncTarget) {
        $sync = whatsapp_register_company_token($oldCfg, $syncTarget, $apiToken);
        if (!empty($sync['ok'])) {
          $syncOk = true;
        } else {
          $syncErrors[] = 'company-' . $syncTarget . ': ' . (string)($sync['error'] ?? 'falha desconhecida');
        }
      }
      if ($syncOk && !$syncErrors) {
        $syncMsg = ' Token gerado e registrado automaticamente no serviço WhatsApp.';
      } elseif ($syncOk) {
        $syncMsg = ' Token gerado e registrado parcialmente no serviço WhatsApp. Verifique: ' . implode('; ', $syncErrors) . '.';
      } else {
        $syncMsg = ' Token gerado, mas não foi possível registrar automaticamente no serviço WhatsApp: ' . implode('; ', $syncErrors) . '.';
      }
    }

    $cfg = [
      'enabled' => isset($_POST['enabled']),
      'service_url' => (string)($_POST['service_url'] ?? ($oldCfg['service_url'] ?? 'http://127.0.0.1:3333')),
      'api_token' => $apiToken,
      'store_phone' => (string)($_POST['store_phone'] ?? ''),
      'send_order_to_store' => false,
      'send_order_confirmation_to_customer' => isset($_POST['send_order_confirmation_to_customer']),
      'send_status_to_customer' => isset($_POST['send_status_to_customer']),
      'timeout_seconds' => (int)($_POST['timeout_seconds'] ?? 30),
      'session_company_id' => (string)($oldCfg['session_company_id'] ?? ''),
      'pix' => [
        'include_on_order' => isset($_POST['pix_include_on_order']),
        'details' => (string)($_POST['pix_details'] ?? ''),
        'label' => (string)($_POST['pix_label'] ?? '🔑 Pix'),
      ],
      'templates' => [
        'order_customer_full' => (string)($_POST['template_order_customer_full'] ?? ''),
        'status_customer' => (string)($_POST['template_status_customer'] ?? ''),
      ],
    ];
    whatsapp_save_config($pdo, $waCompanyId, $cfg);

    // Seleção dos modelos novos é armazenada separadamente para não alterar
    // as mensagens atuais de clientes que ainda não optaram pelo catálogo.
    $messageModels = whatsapp_message_models_load($pdo, $waCompanyId);
    $validModelIds = [];
    foreach ((array)$messageModels['items'] as $modelItem) {
      if (!empty($modelItem['active'])) $validModelIds[(int)$modelItem['id']] = true;
    }
    foreach (['boleto','pedido','nfe','nfce'] as $contextKey) {
      $selectedId = max(0, (int)($_POST['message_model_' . $contextKey] ?? 0));
      $messageModels['defaults'][$contextKey] = ($selectedId > 0 && isset($validModelIds[$selectedId])) ? $selectedId : 0;
    }
    whatsapp_message_models_save($pdo, $waCompanyId, $messageModels);

    $cfg = whatsapp_load_config($pdo, $waCompanyId, false, true);
    $messageModels = whatsapp_message_models_load($pdo, $waCompanyId);
    $msg = 'Configurações do WhatsApp salvas com sucesso.' . $syncMsg;
  } catch (Throwable $e) {
    $erro = 'Falha ao salvar: ' . $e->getMessage();
  }
}

$storePhone = trim((string)($cfg['store_phone'] ?? ''));
if ($storePhone === '') $storePhone = trim((string)($appCfg['whatsapp_loja'] ?? ''));
$serviceCompanyId = whatsapp_service_company_id($cfg, $waCompanyId);

admin_topbar('Central - WhatsApp automático', 'whatsapp');
?>

<style>
  .wa-page{
    max-width:1180px;
    margin:14px auto 0;
    display:grid;
    grid-template-columns:minmax(300px,390px) minmax(0,1fr);
    gap:18px;
    align-items:start;
  }
  .wa-card{
    min-width:0;
    background:#fff;
    border:1px solid #dbeafe;
    border-radius:22px;
    padding:20px;
    box-shadow:0 14px 34px rgba(11,31,91,.07);
  }
  .wa-title{
    margin:0 0 6px;
    color:#0b1f5b;
    font-size:24px;
    letter-spacing:-.03em;
    line-height:1.15;
  }
  .wa-muted{
    color:#64748b;
    line-height:1.55;
    font-size:14px;
    overflow-wrap:anywhere;
  }
  .wa-status{
    display:inline-flex;
    align-items:center;
    gap:8px;
    padding:9px 12px;
    border-radius:999px;
    background:#f1f5f9;
    color:#334155;
    font-weight:850;
    font-size:13px;
  }
  .wa-status.ok{background:#ecfdf5;color:#047857}
  .wa-status.warn{background:#fff7ed;color:#c2410c}
  .wa-status.err{background:#fef2f2;color:#b91c1c}
  .wa-form{display:grid;gap:12px;margin-top:14px}
  .wa-form label{display:block;font-weight:850;color:#0f172a;margin-bottom:6px}
  .wa-form .field{
    width:100%;
    max-width:100%;
    min-width:0;
  }
  .wa-form textarea.field{
    min-height:160px;
    resize:vertical;
    font-family:ui-monospace,Consolas,monospace;
    line-height:1.45;
  }
  .wa-vars{
    display:flex;
    flex-wrap:wrap;
    gap:6px;
    margin-top:8px;
  }
  .wa-vars code{
    background:#eff6ff;
    color:#1d4ed8;
    border:1px solid #bfdbfe;
    border-radius:999px;
    padding:5px 8px;
    font-size:12px;
    font-weight:800;
  }
  .wa-check{
    display:flex;
    align-items:flex-start;
    gap:10px;
    padding:12px;
    border:1px solid #e2e8f0;
    border-radius:16px;
    background:#f8fbff;
  }
  .wa-check input{margin-top:4px}
  .wa-actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
  .qr-box{
    display:flex;
    align-items:center;
    justify-content:center;
    min-height:320px;
    border:1px dashed #bfdbfe;
    border-radius:22px;
    background:#f8fbff;
    padding:18px;
    text-align:center;
    overflow:hidden;
  }
  .qr-box img{
    width:min(280px,100%);
    max-width:280px;
    height:auto;
    display:block;
    margin:0 auto;
    background:#fff;
    border-radius:16px;
    padding:10px;
    box-shadow:0 14px 30px rgba(11,31,91,.12);
  }
  .log-note{
    min-width:0;
    font-family:ui-monospace,Consolas,monospace;
    background:#0b1f5b;
    color:#eaf7ff;
    border-radius:16px;
    padding:12px;
    white-space:pre-wrap;
    overflow:auto;
    overflow-wrap:anywhere;
    word-break:break-word;
    font-size:12px;
    line-height:1.5;
    max-height:220px;
    max-width:100%;
  }
  .wa-repair-box{
    width:100%;
    border:1px solid #fecaca;
    background:#fff7f7;
    color:#7f1d1d;
    border-radius:18px;
    padding:16px;
    text-align:left;
    line-height:1.5;
  }
  .wa-repair-box b{color:#991b1b}
  .wa-repair-box .btn{margin-top:12px}
  .wa-repair-box code{
    display:block;
    background:#fff;
    border:1px solid #fecaca;
    border-radius:12px;
    padding:9px 10px;
    margin-top:10px;
    color:#7f1d1d;
    white-space:pre-wrap;
    word-break:break-word;
  }
  .wa-help-card{
    max-width:1180px;
    margin:16px auto 0;
  }
  @media(max-width:980px){
    .wa-page{grid-template-columns:1fr}
  }
</style>

<?php if ($waCrossContext): ?><div class="msg ok" style="margin-top:12px;"><strong>V11.22.1 · WhatsApp Multiempresa:</strong> esta tela está configurando a empresa selecionada pelo F11 (ID <?= (int)$waCompanyId ?>). QR Code, token, sessão e modelos ficam isolados desta empresa.</div><?php endif; ?>
<?php $waBreaker = whatsapp_breaker_status($cfg); $waQueued = whatsapp_outbox_pending_count($pdo, $waCompanyId); ?>
<?php $waShared = whatsapp_shared_owner($pdo, (int)$waCompanyId); if ($waShared): ?><div class="msg" style="margin-top:12px;background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0;"><strong>Envios pelo WhatsApp de <?= htmlspecialchars($waShared['name'], ENT_QUOTES, 'UTF-8') ?>.</strong> Esta empresa faz parte de um Grupo Empresarial que usa somente o WhatsApp da empresa principal: boletos, pedidos, O.S. e notas desta empresa saem pelo número dela (com os textos e modelos configurados aqui). Não é preciso conectar um WhatsApp nesta empresa.</div><?php endif; ?>
<?php if ($waBreaker['open']): ?><div class="msg err" style="margin-top:12px;"><strong>WhatsApp com problemas no momento.</strong> Os envios estão pausados por mais <?= (int)ceil($waBreaker['seconds_left'] / 60) ?> minuto(s) para não travar o sistema. Vendas, pedidos e notas continuam funcionando normalmente.</div><?php endif; ?>
<?php if ($waQueued > 0): ?><div class="msg" style="margin-top:12px;background:#eff6ff;color:#1e3a8a;border:1px solid #bfdbfe;"><?= (int)$waQueued ?> mensagem(ns) automática(s) aguardando envio na fila. Elas são enviadas em segundo plano assim que o WhatsApp estiver disponível.</div><?php endif; ?>
<?php if ($msg): ?><div class="msg ok" style="margin-top:12px;"><?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="msg err" style="margin-top:12px;"><?= htmlspecialchars($erro) ?></div><?php endif; ?>

<div class="wa-page">
  <div class="wa-card">
    <h2 class="wa-title">Configuração do WhatsApp</h2>
    <div class="wa-muted">
      Conecte um WhatsApp por QR Code para envio automático das mensagens. Ao finalizar um pedido, a API conectada no Admin envia automaticamente ao cliente a mensagem completa do pedido. Ao mudar status, o sistema também pode avisar o cliente.
    </div>

    <form method="post" class="wa-form">
      <?php if($waCrossContext): ?><input type="hidden" name="mc_ctx" value="<?= htmlspecialchars($waMcCtx) ?>"><input type="hidden" name="mc_csrf" value="<?= htmlspecialchars($waMcCsrf) ?>"><?php endif; ?>
      <input type="hidden" name="service_url" value="<?= htmlspecialchars((string)($cfg['service_url'] ?: 'http://127.0.0.1:3333')) ?>">

      <label>Token de segurança</label>
      <div class="rowline">
        <input class="field" name="api_token" value="<?= htmlspecialchars((string)$cfg['api_token']) ?>" placeholder="Clique em gerar token automático">
        <button class="btn" type="submit" name="action" value="generate_token" onclick="return confirm('Gerar um novo token para esta empresa? O token anterior deixará de funcionar para esta conexão.');">Gerar token automático</button>
      </div>
      <div class="wa-muted">
        O token agora pode ser gerado por empresa e registrado automaticamente no serviço da hospedagem.
        Para isso, o arquivo <b>whatsapp-service/server.js</b> precisa estar atualizado para a versão com registro de tokens por empresa.
      </div>

      <label>WhatsApp da loja</label>
      <input class="field" name="store_phone" value="<?= htmlspecialchars($storePhone) ?>" placeholder="Ex.: 31999999999">

      <label>Tempo limite da conexão</label>
      <input class="field" type="number" min="2" max="60" name="timeout_seconds" value="<?= (int)$cfg['timeout_seconds'] ?>">
      <div class="wa-muted">Para envio automático com fallback visual, recomendamos 30 segundos.</div>

      <label class="wa-check"><input type="checkbox" name="enabled" <?= !empty($cfg['enabled']) ? 'checked' : '' ?>><span><b>Ativar automação WhatsApp</b><br><span class="wa-muted">Se desativado, os botões manuais continuam funcionando.</span></span></label>
      <label class="wa-check" style="background:#f8fbff;">
        <input type="checkbox" checked disabled>
        <span>
          <b>Envio automático via API do Admin</b><br>
          <span class="wa-muted">Ao finalizar o pedido, a API do WhatsApp conectado no Admin envia a mensagem automaticamente, sem abrir o botão manual.</span>
        </span>
      </label>
      <label class="wa-check">
        <input type="checkbox" name="send_order_confirmation_to_customer" <?= !empty($cfg['send_order_confirmation_to_customer']) ? 'checked' : '' ?>>
        <span>
          <b>Enviar pedido completo automaticamente para o cliente</b><br>
          <span class="wa-muted">Envia ao WhatsApp informado no checkout a mensagem completa do pedido, no mesmo modelo da mensagem manual do WhatsApp.</span>
        </span>
      </label>
      <label class="wa-check"><input type="checkbox" name="send_status_to_customer" <?= !empty($cfg['send_status_to_customer']) ? 'checked' : '' ?>><span><b>Enviar mudança de status para o cliente</b><br><span class="wa-muted">Ao confirmar, colocar em produção, sair para entrega, finalizar ou cancelar.</span></span></label>

      <hr style="border:0;border-top:1px solid #e2e8f0;margin:10px 0;">
      <h3 style="margin:0;color:#0b1f5b;">Pix na mensagem do pedido</h3>
      <?php $pixCfg = is_array($cfg['pix'] ?? null) ? $cfg['pix'] : []; ?>
      <label class="wa-check">
        <input type="checkbox" name="pix_include_on_order" <?= !empty($pixCfg['include_on_order']) ? 'checked' : '' ?>>
        <span>
          <b>Mostrar dados do Pix quando o pagamento for Pix</b><br>
          <span class="wa-muted">Quando a forma de pagamento do pedido for Pix, a mensagem enviada ao cliente incluirá a chave ou instrução abaixo.</span>
        </span>
      </label>
      <label>Rótulo da linha Pix</label>
      <input class="field" name="pix_label" value="<?= htmlspecialchars((string)($pixCfg['label'] ?? '🔑 Pix')) ?>" placeholder="🔑 Pix">
      <label>Chave ou instrução Pix</label>
      <textarea class="field" name="pix_details" rows="4" placeholder="Ex.: Chave Pix: 00.000.000/0001-00\nEnvie o comprovante neste WhatsApp."><?= htmlspecialchars((string)($pixCfg['details'] ?? '')) ?></textarea>
      <div class="wa-muted">Esta informação só será adicionada quando a mensagem for de pedido e o pagamento for identificado como Pix. Nos demais pagamentos, a linha fica vazia.</div>

      <hr style="border:0;border-top:1px solid #e2e8f0;margin:10px 0;">
      <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;">
        <h3 style="margin:0;color:#0b1f5b;">Modelos padrão por envio</h3>
        <a class="btn" href="<?= htmlspecialchars(function_exists('multi_company_context_url') ? multi_company_context_url('whatsapp_modelos.php') : 'whatsapp_modelos.php') ?>">Cadastrar modelos</a>
      </div>
      <div class="wa-muted">A seleção é opcional. Enquanto estiver em <b>Usar mensagem atual</b>, o comportamento existente permanece sem alteração.</div>
      <?php
        $activeMessageModels = array_values(array_filter((array)($messageModels['items'] ?? []), static fn($m) => !empty($m['active'])));
        $messageModelLabels = whatsapp_message_model_contexts();
      ?>
      <div style="display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;">
        <?php foreach($messageModelLabels as $contextKey=>$contextLabel): ?>
          <?php $contextModels = array_values(array_filter($activeMessageModels, static fn($m) => (string)($m['context'] ?? '') === (string)$contextKey)); ?>
          <label><?= htmlspecialchars('Envio de ' . $contextLabel) ?>
            <select class="field" name="message_model_<?= htmlspecialchars($contextKey) ?>">
              <option value="0">Usar mensagem atual / padrão do sistema</option>
              <?php foreach($contextModels as $modelItem): ?>
                <option value="<?= (int)$modelItem['id'] ?>" <?= (int)($messageModels['defaults'][$contextKey] ?? 0)===(int)$modelItem['id']?'selected':'' ?>><?= htmlspecialchars((string)$modelItem['title']) ?></option>
              <?php endforeach; ?>
            </select>
            <span class="wa-muted" style="display:block;margin-top:4px;">A origem do modelo é definida no cadastro em Modelos de Mensagens.</span>
          </label>
        <?php endforeach; ?>
      </div>
      <div class="wa-muted">Para Pedido, um modelo selecionado substitui o texto do campo legado abaixo em todos os envios que usam a mensagem completa. Para NF-e/NFC-e, a troca só ocorre no envio manual do PDF. Para boleto, o modelo é usado no novo botão de WhatsApp do Contas a Receber.</div>

      <hr style="border:0;border-top:1px solid #e2e8f0;margin:10px 0;">
      <h3 style="margin:0;color:#0b1f5b;">Modelos atuais (legado)</h3>
      <div class="wa-muted">Edite o texto enviado ao cliente. Use as variáveis abaixo; linhas com variáveis vazias são removidas automaticamente.</div>
      <div class="wa-vars">
        <code>{{pedido_id}}</code><code>{{cliente}}</code><code>{{whatsapp}}</code><code>{{itens}}</code><code>{{subtotal}}</code><code>{{taxa_entrega}}</code><code>{{total}}</code><code>{{tipo_entrega}}</code><code>{{pagamento}}</code><code>{{pix_linha}}</code><code>{{pix_dados}}</code><code>{{data_hora_prevista}}</code><code>{{bairro}}</code><code>{{endereco}}</code><code>{{referencia}}</code><code>{{observacoes}}</code><code>{{status}}</code>
      </div>

      <label>Mensagem de pedido</label>
      <textarea class="field" name="template_order_customer_full" rows="10"><?= htmlspecialchars((string)($cfg['templates']['order_customer_full'] ?? whatsapp_default_templates()['order_customer_full'])) ?></textarea>
      <div class="wa-muted">Usada nos pedidos do checkout/catálogo e também nos pedidos lançados pelo PDV.</div>

      <label>Mensagem ao alterar status</label>
      <textarea class="field" name="template_status_customer" rows="7"><?= htmlspecialchars((string)($cfg['templates']['status_customer'] ?? whatsapp_default_templates()['status_customer'])) ?></textarea>
      <div class="wa-muted">Variáveis extras: <code>{{status}}</code>, <code>{{mensagem_status}}</code>, <code>{{loja}}</code>.</div>

      <div class="wa-actions">
        <button class="btn btn-primary" type="submit" name="action" value="save">Salvar configurações</button>
        <button class="btn" type="button" id="btnTest">Enviar teste</button>
      </div>
    </form>
  </div>

  <div class="wa-card">
    <h2 class="wa-title">Conexão por QR Code</h2>
    <div class="wa-muted" style="margin:6px 0 10px;">Sessão WhatsApp atual: <b>company-<?= htmlspecialchars($serviceCompanyId) ?></b>. Empresa operacional no Nexa: <b><?= (int)$waCompanyId ?></b><?php if($waCrossContext): ?> (selecionada pelo F11)<?php endif; ?>.</div>
    <div style="display:flex;justify-content:space-between;gap:10px;align-items:center;flex-wrap:wrap;margin:10px 0 14px;">
      <span id="waStatus" class="wa-status warn">Verificando...</span>
      <div class="wa-actions" style="margin:0;">
        <button class="btn" type="button" id="btnRefresh">Atualizar</button>
        <button class="btn" type="button" id="btnRepair">Corrigir conexão</button>
        <button class="btn" type="button" id="btnLogout">Desconectar</button>
      </div>
    </div>
    <div class="qr-box" id="qrBox">
      <div class="wa-muted">Salve as configurações e clique em atualizar para carregar o QR Code.</div>
    </div>
    <div style="margin-top:12px;" class="log-note" id="waLog">Aguardando conexão...</div>
  </div>
</div>

<script>
(function(){
  const statusEl = document.getElementById('waStatus');
  const qrBox = document.getElementById('qrBox');
  const log = document.getElementById('waLog');
  const testPhone = <?= json_encode($storePhone) ?>;
  const mcCtx = <?= json_encode($waMcCtx) ?>;
  const mcCsrf = <?= json_encode($waMcCsrf) ?>;

  function setStatus(text, cls){
    statusEl.className = 'wa-status ' + (cls || 'warn');
    statusEl.textContent = text;
  }
  function writeLog(obj){
    if (typeof obj === 'string') {
      log.textContent = obj;
      return;
    }
    const clean = Object.assign({}, obj || {});
    if (clean.qrDataUrl) clean.qrDataUrl = '[QR Code carregado na área acima]';
    if (clean.qr) clean.qr = '[QR Code recebido]';
    log.textContent = JSON.stringify(clean, null, 2);
  }
  function rawError(obj){
    if (!obj) return '';
    return String(obj.lastError || obj.error || obj.message || (obj.service_response && obj.service_response.lastError) || '');
  }
  function isBrowserLockError(value){
    const text = String(value || '').toLowerCase();
    return text.includes('browser is already running') || text.includes('use a different userdata') || text.includes('userdatadir');
  }
  function isRuntimeSessionError(value){
    const text = String(value || '').toLowerCase();
    return text.includes('detached frame')
      || text.includes('execution context was destroyed')
      || text.includes('cannot find context with specified id')
      || text.includes('target closed')
      || text.includes('session closed');
  }
  function escapeHtml(value){
    return String(value || '').replace(/[&<>"']/g, function(ch){
      return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[ch];
    });
  }
  function showSessionRepair(errorText){
    setStatus('Sessão travada', 'err');
    qrBox.innerHTML = '<div class="wa-repair-box">' +
      '<b>Esta conexão ficou presa no navegador do WhatsApp.</b><br>' +
      'Clique em <b>Corrigir conexão</b> para o sistema criar um novo código de sessão, sair do company travado e abrir uma nova tentativa de QR Code.' +
      '<code>' + escapeHtml(errorText) + '</code>' +
      '<button class="btn" type="button" onclick="document.getElementById(\'btnRepair\').click()">Corrigir conexão agora</button>' +
      '</div>';
  }
  function showRuntimeRecovery(errorText){
    setStatus('Conectado · recuperação no próximo envio', 'warn');
    qrBox.innerHTML = '<div class="wa-repair-box">' +
      '<b>A autenticação do WhatsApp continua preservada.</b><br>' +
      'O navegador interno perdeu o contexto da página. O serviço R6 tenta reconstruir o Chromium automaticamente somente quando houver um envio, sem ficar consultando a sessão em segundo plano.' +
      '<code>' + escapeHtml(errorText) + '</code>' +
      '<div class="wa-muted" style="margin-top:8px;">Se o próximo envio ainda falhar, use <b>Corrigir conexão</b>.</div>' +
      '</div>';
  }
  async function call(action, options){
    const qs = new URLSearchParams();
    qs.set('action', action);
    if (mcCtx) qs.set('mc_ctx', mcCtx);
    const url = 'whatsapp_api.php?' + qs.toString();
    const opts = Object.assign({cache:'no-store', headers:{'Accept':'application/json'}}, options || {});
    if (String(opts.method || 'GET').toUpperCase() !== 'GET' && mcCsrf) {
      let body = opts.body instanceof URLSearchParams ? opts.body : new URLSearchParams();
      body.set('mc_csrf', mcCsrf);
      if (mcCtx) body.set('mc_ctx', mcCtx);
      opts.body = body;
    }
    const res = await fetch(url, opts);
    const data = await res.json().catch(() => ({ok:false,error:'Resposta inválida'}));
    if (!res.ok || data.ok === false) throw new Error(data.error || data.message || 'Falha na comunicação');
    return data;
  }
  let refreshRunning = false;
  async function refresh(force){
    if (refreshRunning && !force) return;
    refreshRunning = true;
    try {
      setStatus('Verificando...', 'warn');
      const status = await call('status');
      writeLog(status);
      const statusError = rawError(status);
      if (isRuntimeSessionError(statusError) || String(status.status || '').toLowerCase() === 'degraded') {
        showRuntimeRecovery(statusError || 'Sessão do navegador requer recuperação.');
        return;
      }
      if (status.ready || status.connected || status.status === 'ready' || status.status === 'connected') {
        setStatus('Conectado', 'ok');
        qrBox.innerHTML = '<div><b>WhatsApp conectado ✅</b><div class="wa-muted" style="margin-top:8px;">O envio automático já pode ser usado.</div></div>';
        return;
      }
      if (isBrowserLockError(statusError)) {
        showSessionRepair(statusError);
        return;
      }

      const data = await call('qr');
      writeLog(data);
      const qrError = rawError(data);
      if (isRuntimeSessionError(qrError) || String(data.status || '').toLowerCase() === 'degraded') {
        showRuntimeRecovery(qrError || 'Sessão do navegador requer recuperação.');
      } else if (data.ready || data.connected || data.status === 'ready' || data.status === 'connected') {
        setStatus('Conectado', 'ok');
        qrBox.innerHTML = '<div><b>WhatsApp conectado ✅</b><div class="wa-muted" style="margin-top:8px;">O envio automático já pode ser usado.</div></div>';
      } else if (isBrowserLockError(qrError)) {
        showSessionRepair(qrError);
      } else if (data.qrDataUrl || data.qr) {
        const qrDataUrl = data.qrDataUrl || data.qr;
        setStatus('Aguardando leitura do QR Code', 'warn');
        qrBox.innerHTML = '';
        const wrap = document.createElement('div');
        const img = document.createElement('img');
        img.src = qrDataUrl;
        img.alt = 'QR Code WhatsApp';
        const hint = document.createElement('div');
        hint.className = 'wa-muted';
        hint.style.marginTop = '10px';
        hint.textContent = 'Abra o WhatsApp no celular, vá em aparelhos conectados e leia este QR Code.';
        wrap.appendChild(img);
        wrap.appendChild(hint);
        qrBox.appendChild(wrap);
      } else if ((data.status || '').toLowerCase() === 'error' || qrError) {
        setStatus('Erro', 'err');
        qrBox.innerHTML = '<div class="wa-muted">' + escapeHtml(qrError || 'Não foi possível iniciar a conexão. Clique em Corrigir conexão ou tente atualizar novamente.') + '</div>';
      } else {
        setStatus(data.status || 'Inicializando', 'warn');
        qrBox.innerHTML = '<div class="wa-muted">Aguardando QR Code. Clique em atualizar novamente em alguns segundos.</div>';
      }
    } catch(e) {
      const msg = e.message || String(e);
      if (isBrowserLockError(msg)) {
        showSessionRepair(msg);
      } else {
        setStatus('Serviço indisponível', 'err');
        qrBox.innerHTML = '<div class="wa-muted">Não foi possível falar com o serviço WhatsApp.</div>';
      }
      writeLog(msg);
    } finally {
      refreshRunning = false;
    }
  }
  document.getElementById('btnRefresh').addEventListener('click', () => refresh(true));
  document.getElementById('btnRepair').addEventListener('click', async () => {
    if (!confirm('Corrigir a conexão desta empresa? O sistema vai trocar o código da sessão WhatsApp e gerar um novo QR Code.')) return;
    try {
      setStatus('Corrigindo...', 'warn');
      qrBox.innerHTML = '<div class="wa-muted">Encerrando a sessão antiga, criando novo código de company e abrindo nova conexão...</div>'; 
      writeLog(await call('reconnect', {method:'POST'}));
      setTimeout(() => refresh(true), 1800);
    } catch(e){
      const msg = e.message || String(e);
      if (isBrowserLockError(msg)) showSessionRepair(msg);
      writeLog(msg);
    }
  });
  document.getElementById('btnLogout').addEventListener('click', async () => {
    if (!confirm('Desconectar este WhatsApp?')) return;
    try { writeLog(await call('logout', {method:'POST'})); setTimeout(() => refresh(true), 1200); }
    catch(e){ writeLog(e.message || String(e)); }
  });
  document.getElementById('btnTest').addEventListener('click', async () => {
    const to = prompt('Enviar teste para qual número?', testPhone || '');
    if (!to) return;
    try {
      const body = new URLSearchParams(); body.set('to', to);
      const data = await call('test', {method:'POST', body});
      alert(data.message || 'Teste enviado.');
      writeLog(data);
    } catch(e){ alert(e.message || String(e)); }
  });
  // Consulta única ao abrir a tela. Novas consultas ocorrem somente por ação do usuário.
  refresh(true);
})();
</script>

<?php admin_footer(); ?>
