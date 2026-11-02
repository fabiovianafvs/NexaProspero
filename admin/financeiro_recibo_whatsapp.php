<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/finance_receipt.php';
require_once __DIR__ . '/../config/whatsapp_service.php';

$receiptCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'finance_receipt_send');
if ($receiptCrossContext && (!function_exists('finance_multi_company_receipt_send_enabled') || !finance_multi_company_receipt_send_enabled($pdo))) {
  access_render_denied('V11.23: seu perfil não possui permissão para enviar recibos da empresa selecionada pelo F11.');
}
$cid = $receiptCrossContext && function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'finance_receipt_send')
  : (int)COMPANY_ID;
$mcCsrf = ($receiptCrossContext && function_exists('multi_company_context_csrf_token')) ? multi_company_context_csrf_token() : '';
$mcCtx = ($receiptCrossContext && function_exists('multi_company_context_request_token')) ? multi_company_context_request_token() : '';
$batch = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['batch'] ?? $_POST['batch'] ?? '')) ?: '';
$layout = strtolower((string)($_GET['layout'] ?? $_POST['layout'] ?? 'a4')) === '72' ? '72' : 'a4';
$ids = finance_receipt_batch_ids($cid, $batch);
$data = $ids ? finance_receipt_load($pdo, $cid, $ids) : [];
if (!$data) { http_response_code(404); echo 'Recibo não encontrado ou expirado.'; exit; }

function frw_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
$phone = whatsapp_digits((string)($_POST['phone'] ?? ($data['customer']['phone'] ?? '')));
$customer = trim((string)($data['customer']['name'] ?? 'cliente')) ?: 'cliente';
$paymentParts = [];
foreach ((array)($data['payments'] ?? []) as $name=>$amount) $paymentParts[] = $name . ': R$ ' . finance_receipt_money($amount);
$message = trim(implode("\n", array_filter([
  'Olá, ' . $customer . '!',
  'Segue em anexo o recibo da baixa realizada.',
  'Recibo: ' . (string)($data['receipt_number'] ?? ''),
  'Data: ' . (!empty($data['settlement_date']) ? date('d/m/Y', strtotime((string)$data['settlement_date'])) : ''),
  'Principal: R$ ' . finance_receipt_money($data['principal'] ?? 0),
  (float)($data['fine'] ?? 0) > 0.009 ? 'Multa: R$ ' . finance_receipt_money($data['fine']) : '',
  (float)($data['interest'] ?? 0) > 0.009 ? 'Juros: R$ ' . finance_receipt_money($data['interest']) : '',
  (float)($data['discount'] ?? 0) > 0.009 ? 'Desconto: R$ ' . finance_receipt_money($data['discount']) : '',
  'Total recebido: R$ ' . finance_receipt_money($data['total'] ?? 0),
  $paymentParts ? 'Pagamento: ' . implode(' | ', $paymentParts) : '',
])));

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if ($receiptCrossContext && (string)($_POST['action'] ?? '') !== 'send_receipt_whatsapp') {
    $err = 'V11.23: ação de envio inválida no contexto Multiempresa.';
  } elseif ($receiptCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
    $err = 'V11.23: sessão de segurança expirada. Atualize a página antes de enviar o recibo.';
  }
  $phone = whatsapp_digits((string)($_POST['phone'] ?? ''));
  if ($err === '' && $phone === '') {
    $err = 'Informe o número do WhatsApp para envio.';
  } elseif ($err === '') {
    $mode = (string)($_POST['mode'] ?? 'api');
    if ($mode === 'api') {
      try {
        $pdf = finance_receipt_pdf_build($data, $layout);
        $waCfg = whatsapp_load_config($pdo, $cid);
        $waCfg['enabled'] = true;
        $waCfg['timeout_seconds'] = max(60, (int)($waCfg['timeout_seconds'] ?? 30));
        $send = whatsapp_send_document(
          $pdo, $cid, $phone, $message,
          (string)$pdf['filename'], (string)$pdf['mimetype'], (string)$pdf['content'],
          'finance_receipt_admin', null, $waCfg, $receiptCrossContext
        );
        if (!empty($send['ok'])) {
          $successUrl = 'financeiro_recibo_baixa.php?batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout) . '&wa_sent=1';
          if ($receiptCrossContext && function_exists('multi_company_context_url')) $successUrl = multi_company_context_url($successUrl);
          header('Location: ' . $successUrl);
          exit;
        }
        $err = trim((string)($send['error'] ?? '')) ?: 'Não foi possível enviar o recibo pelo WhatsApp.';
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    } else {
      $to = whatsapp_normalize_phone($phone);
      header('Location: https://wa.me/' . rawurlencode($to) . '?text=' . rawurlencode($message . "\n\nO PDF deverá ser anexado manualmente nesta conversa."));
      exit;
    }
  }
}

$receiptReturnUrl = 'financeiro_recibo_baixa.php?batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout);
$receiptEmailUrl = 'email_documento.php?type=recibo&batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout) . '&return_to=' . rawurlencode($receiptReturnUrl);
if ($receiptCrossContext && function_exists('multi_company_context_url')) {
  $receiptReturnUrl = multi_company_context_url($receiptReturnUrl);
  $receiptEmailUrl = multi_company_context_url($receiptEmailUrl);
}

finance_admin_start('Enviar recibo por WhatsApp', 'fin_receber');
finance_page_header('Enviar recibo por WhatsApp', $receiptCrossContext ? 'O recibo será enviado usando o WhatsApp exclusivo da empresa selecionada pelo F11.' : 'O recibo será enviado em PDF junto com um resumo da baixa.', [
  ['href'=>$receiptReturnUrl,'label'=>'Voltar ao recibo','class'=>'btn'],
]);
finance_tabs('fin_receber');
if ($receiptCrossContext) echo '<div class="msg ok"><strong>V11.23 · Recibo Multiempresa:</strong> documento, cliente, WhatsApp e log pertencem exclusivamente à empresa selecionada pelo F11.</div>';
if ($err !== '') echo '<div class="msg err">' . frw_h($err) . '</div>';
?>
<style>
.receipt-wa-card{max-width:780px}.receipt-wa-file{display:flex;gap:12px;align-items:center;border:1px solid #dbe4f0;background:#f8fafc;border-radius:14px;padding:12px;margin:12px 0}.receipt-wa-icon{width:44px;height:44px;border-radius:12px;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;font-weight:950}.receipt-wa-button{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important}.receipt-wa-note{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:14px;padding:11px 13px;line-height:1.45}
</style>
<div class="finance-card receipt-wa-card">
  <h2><?= frw_h($data['receipt_number'] ?? 'Recibo') ?></h2>
  <p class="finance-muted">Cliente: <b><?= frw_h($customer) ?></b> · Total: <b>R$ <?= finance_receipt_money($data['total'] ?? 0) ?></b></p>
  <div class="receipt-wa-file"><div class="receipt-wa-icon">PDF</div><div><strong><?= $layout==='72'?'Recibo 72 mm':'Recibo A4 — 2 vias' ?></strong><div class="finance-muted">O arquivo será gerado automaticamente e anexado ao WhatsApp.</div></div></div>
  <form method="post" class="finance-form">
    <input type="hidden" name="action" value="send_receipt_whatsapp">
    <input type="hidden" name="batch" value="<?= frw_h($batch) ?>">
    <input type="hidden" name="layout" value="<?= frw_h($layout) ?>">
    <?php if($receiptCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= frw_h($mcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= frw_h($mcCtx) ?>"><?php endif; ?>
    <label>Número do WhatsApp
      <input name="phone" value="<?= frw_h($phone) ?>" placeholder="Ex.: 31999999999" inputmode="numeric" autofocus>
    </label>
    <label>Mensagem
      <textarea rows="9" readonly><?= frw_h($message) ?></textarea>
    </label>
    <div class="receipt-wa-note"><b>Envio automático:</b> utiliza o WhatsApp conectado no Nexa e envia o recibo como documento PDF. A opção manual abre a conversa com o resumo, mas o anexo precisa ser incluído manualmente.</div>
    <div class="finance-form-actions">
      <button class="btn receipt-wa-button" type="submit" name="mode" value="api">Enviar PDF pelo WhatsApp</button>
      <button class="btn" type="submit" name="mode" value="web">Abrir WhatsApp sem anexo</button>
      <a class="btn" href="<?= frw_h($receiptEmailUrl) ?>">✉ Enviar por e-mail</a>
      <a class="btn" href="<?= frw_h($receiptReturnUrl) ?>">Cancelar</a>
    </div>
  </form>
</div>
<?php finance_admin_end(); ?>
