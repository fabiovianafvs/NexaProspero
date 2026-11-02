<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/boleto_document.php';

$billingCrossContext = function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_billing_send');
if ($billingCrossContext && (!function_exists('finance_multi_company_billing_send_enabled') || !finance_multi_company_billing_send_enabled($pdo))) {
  access_render_denied('V11.22: seu perfil não possui permissão para enviar boletos da empresa selecionada pelo F11.');
}
$cid = function_exists('multi_company_operational_company_id') ? multi_company_operational_company_id($pdo, 'finance_billing_send') : (int)COMPANY_ID;
$billingMcCsrf = ($billingCrossContext && function_exists('multi_company_context_csrf_token')) ? multi_company_context_csrf_token() : '';
$billingMcCtx = ($billingCrossContext && function_exists('multi_company_context_request_token')) ? multi_company_context_request_token() : '';
$chargeId = (int)($_GET['charge_id'] ?? $_POST['charge_id'] ?? 0);
$receivableId = (int)($_GET['receivable_id'] ?? $_POST['receivable_id'] ?? 0);
$returnTo = trim((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'financeiro_contas_receber.php'));
if ($returnTo === '' || preg_match('#^https?://#i', $returnTo) || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) $returnTo = 'financeiro_contas_receber.php';
if ($billingCrossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);

function fbw_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }

try {
  $data = boleto_document_load($pdo, $cid, $chargeId, $receivableId);
  if (!boleto_document_ready($data)) throw new RuntimeException('Este boleto ainda não possui linha digitável e código de barras para envio. Aguarde o registro no banco.');
} catch (Throwable $e) {
  finance_admin_start('Enviar boleto por WhatsApp', 'fin_receber');
  finance_page_header('Enviar boleto por WhatsApp', 'Não foi possível preparar o documento.', [['href'=>$returnTo,'label'=>'Voltar','class'=>'btn']]);
  finance_tabs('fin_receber');
  echo '<div class="msg err">' . fbw_h($e->getMessage()) . '</div>';
  finance_admin_end();
  exit;
}

$companyName = boleto_document_company_name($data);
$customerName = trim((string)($data['payer_name'] ?? '')) ?: 'cliente';
$phone = whatsapp_digits((string)($_POST['phone'] ?? $_GET['phone'] ?? ($data['payer_whatsapp'] ?? $data['payer_phone'] ?? '')));
$pedido = (int)($data['order_id'] ?? 0) > 0 ? '#' . (int)$data['order_id'] : '';
$contrato = (int)($data['contract_id'] ?? 0) > 0 ? '#' . (int)$data['contract_id'] : '';
$fallbackMessage = trim(implode("\n", array_filter([
  'Olá, ' . $customerName . '!',
  'Segue em anexo o boleto emitido pela ' . $companyName . '.',
  'Vencimento: ' . boleto_document_date($data['due_date'] ?? ''),
  'Valor: ' . boleto_document_money($data['amount'] ?? 0),
  trim((string)($data['linha_digitavel'] ?? '')) !== '' ? 'Linha digitável: ' . trim((string)$data['linha_digitavel']) : '',
])));
$messageVars = [
  'empresa' => $companyName,
  'cliente' => $customerName,
  'titulo_id' => (string)((int)($data['receivable_id'] ?? 0)),
  'boleto_id' => (string)((int)($data['id'] ?? 0)),
  'pedido' => $pedido,
  'contrato' => $contrato,
  'vencimento' => boleto_document_date($data['due_date'] ?? ''),
  'valor' => boleto_document_money($data['amount'] ?? 0),
  'nosso_numero' => trim((string)($data['nosso_numero'] ?? '')),
  'linha_digitavel' => trim((string)($data['linha_digitavel'] ?? '')),
  'pix_copia_cola' => trim((string)($data['pix_emv'] ?? '')),
];
$messageState = whatsapp_message_models_load($pdo, $cid);
$messageModels = whatsapp_message_models_for_context($pdo, $cid, 'boleto');
$defaultMessageModel = max(0, (int)($messageState['defaults']['boleto'] ?? 0));
$selectedMessageModel = max(0, (int)($_POST['model_id'] ?? $_GET['model_id'] ?? $defaultMessageModel));
$validMessageIds = [0 => true]; foreach ($messageModels as $m) $validMessageIds[(int)$m['id']] = true;
if (empty($validMessageIds[$selectedMessageModel])) $selectedMessageModel = !empty($validMessageIds[$defaultMessageModel]) ? $defaultMessageModel : 0;
$messageRendered = [0 => $fallbackMessage];
foreach ($messageModels as $m) { $mid=(int)$m['id']; $messageRendered[$mid]=whatsapp_message_model_render($pdo,$cid,'boleto',$messageVars,$fallbackMessage,$mid); }
$message = (string)($messageRendered[$selectedMessageModel] ?? $fallbackMessage);

$pdfHref = 'financeiro_boleto_imprimir.php?charge_id=' . (int)$data['id'] . '&format=pdf';
$emailHref = 'email_documento.php?type=boleto&charge_id=' . (int)$data['id'] . '&return_to=' . rawurlencode($returnTo);
if ($billingCrossContext && function_exists('multi_company_context_url')) {
  $pdfHref = multi_company_context_url($pdfHref);
  $emailHref = multi_company_context_url($emailHref);
}

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $crossPostOk = true;
  if ($billingCrossContext) {
    if ((string)($_POST['action'] ?? '') !== 'send_boleto_whatsapp') { $err = 'V11.22: ação de envio inválida no contexto Multiempresa.'; $crossPostOk = false; }
    elseif (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? ''))) { $err = 'V11.22: sessão de segurança expirada. Atualize a página antes de enviar o boleto.'; $crossPostOk = false; }
  }
  $phone = whatsapp_digits((string)($_POST['phone'] ?? ''));
  if (!$crossPostOk) {
    // A mensagem já foi definida acima; nenhuma chamada externa é executada.
  } elseif ($phone === '') {
    $err = 'Informe o número do WhatsApp para envio.';
  } else {
    $mode = (string)($_POST['mode'] ?? 'api');
    if ($mode === 'api') {
      try {
        $pdf = boleto_document_pdf($data);
        $waCfg = whatsapp_load_config($pdo, $cid);
        // Envio manual deve funcionar mesmo se somente a automação estiver desativada.
        $waCfg['enabled'] = true;
        $waCfg['timeout_seconds'] = max(60, (int)($waCfg['timeout_seconds'] ?? 30));
        $filename = 'boleto-' . ((int)($data['receivable_id'] ?? 0) ?: (int)$data['id']) . '.pdf';
        $send = whatsapp_send_document($pdo, $cid, $phone, $message, $filename, 'application/pdf', $pdf, 'finance_boleto_admin', null, $waCfg, $billingCrossContext);
        if (!empty($send['ok'])) {
          $sep = str_contains($returnTo, '?') ? '&' : '?';
          header('Location: ' . $returnTo . $sep . 'ok=' . rawurlencode('Boleto enviado em PDF pelo WhatsApp.'));
          exit;
        }
        $err = trim((string)($send['error'] ?? '')) ?: 'Não foi possível enviar o boleto pelo serviço WhatsApp.';
      } catch (Throwable $e) { $err = $e->getMessage(); }
    } else {
      $to = whatsapp_normalize_phone($phone);
      $manualMessage = $message . "\n\nO PDF deverá ser anexado manualmente nesta conversa.";
      header('Location: https://wa.me/' . rawurlencode($to) . '?text=' . rawurlencode($manualMessage));
      exit;
    }
  }
}

finance_admin_start('Enviar boleto por WhatsApp', 'fin_receber');
finance_page_header('Enviar boleto por WhatsApp', 'Confirme o número e a mensagem. O serviço conectado enviará o boleto em PDF como anexo.', [
  ['href'=>$returnTo,'label'=>'Voltar','class'=>'btn'],
]);
finance_tabs('fin_receber');
if ($err !== '') echo '<div class="msg err">' . fbw_h($err) . '</div>';
?>
<style>
.fbw-wrap{max-width:820px}.fbw-button{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important;box-shadow:0 8px 18px rgba(37,211,102,.24)}.fbw-note{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:14px;padding:12px 14px;line-height:1.45}.fbw-file{display:flex;gap:12px;align-items:center;border:1px solid #dbe4f0;background:#f8fafc;border-radius:14px;padding:12px}.fbw-file-icon{width:44px;height:44px;border-radius:12px;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;font-weight:950}.fbw-message{min-height:160px;resize:vertical}.fbw-model{width:100%;min-height:40px;border:1px solid #9eacbc;border-radius:12px;padding:8px 10px;background:#fff;color:#172033}
</style>
<div class="finance-card fbw-wrap">
  <h2 class="finance-subtitle">Boleto da Conta a Receber #<?= (int)($data['receivable_id'] ?? 0) ?></h2>
  <p class="finance-muted">Cliente: <b><?= fbw_h($customerName) ?></b> · Vencimento: <b><?= fbw_h(boleto_document_date($data['due_date'] ?? '')) ?></b> · Valor: <b><?= fbw_h(boleto_document_money($data['amount'] ?? 0)) ?></b></p>
  <div class="fbw-file" style="margin:12px 0"><div class="fbw-file-icon">PDF</div><div><strong>Boleto em PDF</strong><div class="finance-muted">O documento é montado com os dados registrados pelo banco, incluindo linha digitável, código de barras e Pix quando disponível.</div><a href="<?= fbw_h($pdfHref) ?>" target="_blank">Visualizar PDF</a></div></div>
  <form method="post" class="finance-form">
    <input type="hidden" name="action" value="send_boleto_whatsapp">
    <?php if($billingCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= fbw_h($billingMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= fbw_h($billingMcCtx) ?>"><?php endif; ?>
    <input type="hidden" name="charge_id" value="<?= (int)$data['id'] ?>">
    <input type="hidden" name="receivable_id" value="<?= (int)($data['receivable_id'] ?? 0) ?>">
    <input type="hidden" name="return_to" value="<?= fbw_h($returnTo) ?>">
    <label>Número do WhatsApp
      <input name="phone" value="<?= fbw_h($phone) ?>" placeholder="Ex.: 31999999999" inputmode="numeric" autofocus>
    </label>
    <label>Modelo da mensagem
      <select class="fbw-model" name="model_id" id="fbw-model">
        <option value="0" <?= $selectedMessageModel===0?'selected':'' ?>>Mensagem padrão do sistema</option>
        <?php foreach($messageModels as $m): $mid=(int)$m['id']; ?>
          <option value="<?= $mid ?>" <?= $selectedMessageModel===$mid?'selected':'' ?>><?= fbw_h((string)$m['title']) ?><?= $defaultMessageModel===$mid?' — padrão':'' ?></option>
        <?php endforeach; ?>
      </select>
    </label>
    <label>Mensagem que acompanhará o boleto
      <textarea class="fbw-message" id="fbw-message" rows="8" readonly><?= fbw_h($message) ?></textarea>
    </label>
    <div class="fbw-note"><b>Modelo selecionável:</b> escolha a mensagem de <b>Boleto</b> antes do envio. Se escolher “Mensagem padrão do sistema”, o Nexa usa o texto seguro acima.</div>
    <div class="finance-form-actions">
      <button class="btn fbw-button" type="submit" name="mode" value="api">Enviar PDF pelo WhatsApp</button>
      <button class="btn" type="submit" name="mode" value="web">Abrir WhatsApp sem anexo</button>
      <a class="btn" href="<?= fbw_h($emailHref) ?>">✉ Enviar por e-mail</a>
      <a class="btn" href="<?= fbw_h($returnTo) ?>">Cancelar</a>
    </div>
  </form>
</div>
<script>
(function(){const sel=document.getElementById('fbw-model'),box=document.getElementById('fbw-message');if(!sel||!box)return;const rendered=<?= json_encode($messageRendered, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;sel.addEventListener('change',function(){const key=String(this.value);if(Object.prototype.hasOwnProperty.call(rendered,key))box.value=rendered[key];});})();
</script>
<?php finance_admin_end(); ?>
