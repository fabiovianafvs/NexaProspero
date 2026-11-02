<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/order_message.php';
require_once __DIR__ . '/../config/load_config.php';
require_once __DIR__ . '/../config/order_email_pdf.php';
require_once __DIR__ . '/../config/navigation_return.php';

$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
if ($id <= 0) {
  header('Location: pedidos.php');
  exit;
}

function pedido_wa_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function pedido_wa_failure_page(string $rawError, string $returnTo): void {
  $error = strtolower($rawError);
  $message = 'O serviço de WhatsApp não confirmou o envio. Consulte o histórico de mensagens WhatsApp da empresa para identificar a resposta da integração.';
  if (str_contains($error, 'send-document') || str_contains($error, '404') || str_contains($error, 'cannot post')) {
    $message = 'O serviço conectado pode não oferecer a rota de envio de PDF (/send-document). Verifique a versão do serviço Node e os logs do PM2.';
  } elseif (str_contains($error, 'timeout') || str_contains($error, 'timed out') || str_contains($error, 'tempo esgotado')) {
    $message = 'O serviço não respondeu dentro do tempo limite. Antes de reenviar, confira se o destinatário já recebeu o documento para evitar duplicidade.';
  } elseif (str_contains($error, 'token') || str_contains($error, '401') || str_contains($error, '403') || str_contains($error, 'unauthoriz')) {
    $message = 'A integração recusou a autenticação. Revise o token e as configurações da empresa no serviço WhatsApp.';
  } elseif (str_contains($error, 'número') || str_contains($error, 'numero') || str_contains($error, 'destino') || str_contains($error, 'phone')) {
    $message = 'O serviço não aceitou o número de destino. Confira o DDD e o número do WhatsApp.';
  } elseif (str_contains($error, '8 mb') || str_contains($error, 'arquivo') || str_contains($error, 'pdf')) {
    $message = 'Falha ao preparar ou enviar o PDF. Verifique o documento e o histórico de mensagens WhatsApp.';
  }
  http_response_code(502);
  header('Content-Type: text/html; charset=UTF-8');
  echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Falha no envio WhatsApp</title></head>';
  echo '<body style="font:16px Arial,sans-serif;background:#f6f8fc;padding:26px;color:#0b1f5b"><main style="max-width:760px;margin:auto;padding:25px;background:white;border:1px solid #dbe4f0;border-radius:16px">';
  echo '<h2>Envio não confirmado</h2><p>'.pedido_wa_h($message).'</p><p><strong>Conectado</strong> significa que a sessão está ativa; não confirma que a rota de envio respondeu com sucesso.</p>';
  echo '<p>Se a mensagem já tiver chegado ao cliente, não reenvie. Verifique o histórico antes de tentar novamente.</p>';
  echo '<a style="display:inline-block;background:#0b1f5b;color:white;padding:12px 16px;border-radius:9px;text-decoration:none" href="'.pedido_wa_h($returnTo).'">Voltar ao pedido</a></main></body></html>';
  exit;
}

function pedido_wa_safe_return(string $value, int $id): string {
  return nexa_navigation_safe_return($value, 'pedido_print.php?id=' . $id . '&autoprint=0');
}

$data = order_message_load($pdo, (int)COMPANY_ID, $id);
if (!$data) {
  http_response_code(404);
  echo 'Pedido não encontrado.';
  exit;
}

$returnTo = pedido_wa_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''), $id);
$modernPrintEnabled = false;
$modernPrintModel = 'a4';
try {
  $stModern = $pdo->prepare('SELECT order_modern_print_enabled,order_modern_print_default FROM fiscal_company_config WHERE company_id=? LIMIT 1');
  $stModern->execute([(int)COMPANY_ID]);
  $modernCfg = $stModern->fetch(PDO::FETCH_ASSOC) ?: [];
  $modernPrintEnabled = !empty($modernCfg['order_modern_print_enabled']);
  $modernPrintModel = strtoupper(trim((string)($modernCfg['order_modern_print_default'] ?? 'A4'))) === '72MM' ? '72mm' : 'a4';
} catch (Throwable $e) {
  $modernPrintEnabled = false;
  $modernPrintModel = 'a4';
}
$appWa = is_array($APP_CFG ?? null) ? $APP_CFG : load_app_config((int)COMPANY_ID);
try {
  $bizWa = load_business_config((int)COMPANY_ID);
  $catalogTitle = trim((string)($bizWa['brand']['catalog_title'] ?? ''));
  if ($catalogTitle !== '') {
    $appWa['site_name'] = $catalogTitle;
    if (!isset($appWa['brand']) || !is_array($appWa['brand'])) $appWa['brand'] = [];
    $appWa['brand']['catalog_title'] = $catalogTitle;
  }
} catch (Throwable $e) {}

if ($modernPrintEnabled) {
  $docUrl = 'documento_whatsapp.php?type=pedido&id=' . $id . '&modelo=' . rawurlencode($modernPrintModel) . '&return_to=' . rawurlencode($returnTo);
  if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . $docUrl);
    exit;
  }
  $requestedMode = (string)($_POST['mode'] ?? 'api');
  $directPhone = whatsapp_digits((string)($_POST['phone'] ?? ($data['pedido']['whatsapp'] ?? '')));
  if ($requestedMode !== 'api' || $directPhone === '') {
    if ($directPhone !== '') $docUrl .= '&phone=' . rawurlencode($directPhone);
    header('Location: ' . $docUrl);
    exit;
  }
  $modernWaCfg = whatsapp_load_config($pdo, (int)COMPANY_ID);
  $modernWaCfg['enabled'] = true;
  $modernWaCfg['timeout_seconds'] = max(60, (int)($modernWaCfg['timeout_seconds'] ?? 30));
  $modernMessage = order_message_build_full($data['pedido'], $data['itens'], $appWa, $modernWaCfg);
  $companyName = trim((string)($appWa['site_name'] ?? ($appWa['brand']['catalog_title'] ?? ''))) ?: 'Nexa Próspero';
  try {
    $pdf = order_email_pdf_build((array)$data['pedido'], (array)$data['itens'], $companyName, $modernPrintModel, $pdo, (int)COMPANY_ID);
    $send = whatsapp_send_document($pdo, (int)COMPANY_ID, $directPhone, $modernMessage, (string)$pdf['filename'], (string)$pdf['mimetype'], (string)$pdf['content'], 'order_customer_pdf_admin', $id, $modernWaCfg);
    $sentOk = !empty($send['ok']);
    $sendError = (string)($send['error'] ?? '');
  } catch (Throwable $e) {
    $sentOk = false;
    $sendError = $e->getMessage();
  }
  if (!$sentOk) pedido_wa_failure_page($sendError, $returnTo);
  $sep = str_contains($returnTo, '?') ? '&' : '?';
  header('Location: ' . $returnTo . $sep . 'wa_sent=1');
  exit;
}

$waCfg = whatsapp_load_config($pdo, (int)COMPANY_ID, false);
$messageState = whatsapp_message_models_load($pdo, (int)COMPANY_ID);
$messageModels = whatsapp_message_models_for_context($pdo, (int)COMPANY_ID, 'pedido');
$defaultMessageModel = max(0, (int)($messageState['defaults']['pedido'] ?? 0));
$selectedMessageModel = max(0, (int)($_POST['model_id'] ?? $_GET['model_id'] ?? $defaultMessageModel));
$validMessageIds = [0 => true]; foreach ($messageModels as $m) $validMessageIds[(int)$m['id']] = true;
if (empty($validMessageIds[$selectedMessageModel])) $selectedMessageModel = !empty($validMessageIds[$defaultMessageModel]) ? $defaultMessageModel : 0;
$messageRendered = [0 => order_message_build_full($data['pedido'], $data['itens'], $appWa, $waCfg)];
foreach ($messageModels as $m) {
  $mid = (int)$m['id'];
  $cfgModel = $waCfg;
  $cfgModel['templates']['order_customer_full'] = (string)$m['message'];
  $messageRendered[$mid] = order_message_build_full($data['pedido'], $data['itens'], $appWa, $cfgModel);
}
$mensagem = (string)($messageRendered[$selectedMessageModel] ?? $messageRendered[0]);
$phone = whatsapp_digits((string)($_POST['phone'] ?? $_GET['phone'] ?? ($data['pedido']['whatsapp'] ?? '')));
$err = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $phone = whatsapp_digits((string)($_POST['phone'] ?? ''));
  if ($phone === '') {
    $err = 'Informe o número do WhatsApp para envio.';
  } else {
    $mode = (string)($_POST['mode'] ?? 'web');
    if ($mode === 'api') {
      // Envio manual pelo Admin: deve enviar imediatamente pelo serviço conectado,
      // mesmo quando a automação automática do checkout/status estiver desativada.
      $waCfg['enabled'] = true;
      $waCfg['timeout_seconds'] = max(30, (int)($waCfg['timeout_seconds'] ?? 30));
      $ok = whatsapp_send_message($pdo, (int)COMPANY_ID, $phone, $mensagem, 'order_customer_full_admin', $id, $waCfg);
      $sep = str_contains($returnTo, '?') ? '&' : '?';
      if (!$ok) pedido_wa_failure_page('Falha no envio da mensagem. Consulte o histórico WhatsApp.', $returnTo);
      header('Location: ' . $returnTo . $sep . 'wa_sent=1');
      exit;
    }
    $to = whatsapp_normalize_phone($phone);
    header('Location: https://wa.me/' . rawurlencode($to) . '?text=' . rawurlencode($mensagem));
    exit;
  }
}

$pedidoNumero = function_exists('company_sequence_display') ? company_sequence_display($data['pedido'], 'pedidos') : (int)($data['pedido']['id'] ?? $id);
$cliente = trim((string)($data['pedido']['nome_cliente'] ?? '')) ?: 'Consumidor final';
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enviar pedido por WhatsApp</title>
<style>
  *{box-sizing:border-box} body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f5f7fb;color:#0f172a;padding:18px}.wrap{max-width:780px;margin:0 auto}.card{background:#fff;border:1px solid #dbe4f0;border-radius:22px;padding:18px;box-shadow:0 18px 45px rgba(11,31,91,.08)}h1{margin:0;color:#0B1F5B;font-size:26px}.muted{color:#64748b;font-weight:750;line-height:1.45}.form{display:grid;gap:12px;margin-top:16px}.form label{display:grid;gap:6px;font-weight:900;color:#0B1F5B}.form input,.form textarea,.form select{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;font:inherit}.form textarea{min-height:180px;resize:vertical}.actions{display:flex;gap:9px;flex-wrap:wrap;justify-content:flex-end;border-top:1px solid #eef2f7;margin-top:14px;padding-top:14px}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #cbd5e1;background:#fff;color:#0f172a;border-radius:999px;padding:10px 14px;font-weight:900;cursor:pointer}.btn.primary{background:#0B1F5B;color:#fff;border-color:#0B1F5B}.msg.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c;padding:10px 12px;border-radius:14px;margin-top:12px;font-weight:800}@media(max-width:640px){body{padding:12px}.actions{justify-content:stretch}.btn{width:100%}}
</style>
</head>
<body>
<div class="wrap">
  <div class="card">
    <h1>Enviar Pedido #<?= pedido_wa_h($pedidoNumero) ?> por WhatsApp</h1>
    <p class="muted">Cliente: <b><?= pedido_wa_h($cliente) ?></b>. Confirme o número e escolha se deseja abrir o WhatsApp Web/App ou enviar pelo serviço conectado.</p>
    <?php if ($err !== ''): ?><div class="msg err"><?= pedido_wa_h($err) ?></div><?php endif; ?>
    <form method="post" class="form">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <input type="hidden" name="return_to" value="<?= pedido_wa_h($returnTo) ?>">
      <label>Número do WhatsApp
        <input name="phone" value="<?= pedido_wa_h($phone) ?>" placeholder="Ex.: 31999999999" inputmode="numeric" autofocus>
      </label>
      <label>Modelo da mensagem
        <select name="model_id" id="pedido-wa-model">
          <option value="0" <?= $selectedMessageModel===0?'selected':'' ?>>Mensagem padrão do sistema</option>
          <?php foreach($messageModels as $m): $mid=(int)$m['id']; ?>
            <option value="<?= $mid ?>" <?= $selectedMessageModel===$mid?'selected':'' ?>><?= pedido_wa_h((string)$m['title']) ?><?= $defaultMessageModel===$mid?' — padrão':'' ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Mensagem
        <textarea id="pedido-wa-message" readonly><?= pedido_wa_h($mensagem) ?></textarea>
      </label>
      <div class="actions">
        <button class="btn primary" type="submit" name="mode" value="web">Abrir WhatsApp</button>
        <button class="btn" type="submit" name="mode" value="api">Enviar pelo serviço conectado</button>
        <a class="btn" href="email_documento.php?type=pedido&id=<?= (int)$id ?>&return_to=<?= rawurlencode($returnTo) ?>">✉ Enviar por e-mail</a>
        <a class="btn" href="<?= pedido_wa_h($returnTo) ?>">Cancelar</a>
      </div>
    </form>
  </div>
</div>
<script>
(function(){const sel=document.getElementById('pedido-wa-model'),box=document.getElementById('pedido-wa-message');if(!sel||!box)return;const rendered=<?= json_encode($messageRendered, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;sel.addEventListener('change',function(){const key=String(this.value);if(Object.prototype.hasOwnProperty.call(rendered,key))box.value=rendered[key];});})();
</script>
</body>
</html>
