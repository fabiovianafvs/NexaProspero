<?php
declare(strict_types=1);

/**
 * Tela única de envio pelo WhatsApp a partir da Consulta de pedidos:
 * o usuário escolhe Pedido (PDF), Boleto(s) e Nota(s) Fiscal(is) do pedido,
 * confere o número do cliente (preenchido automaticamente) e envia tudo.
 *
 * Incluída por pedido_whatsapp.php, que já validou acesso, empresa e pedido e
 * definiu: $pdo, $id, $data, $returnTo, $modernPrintEnabled, $modernPrintModel, $appWa.
 */

if (!isset($pdo, $id, $data, $returnTo)) { http_response_code(404); exit; }

require_once __DIR__ . '/../config/order_whatsapp_bundle.php';

$cid = (int)COMPANY_ID;
$canBoleto = function_exists('access_has_permission') ? access_has_permission($pdo, $cid, 'financeiro_boletos') : true;
$canFiscal = function_exists('access_has_permission') ? access_has_permission($pdo, $cid, 'fiscal_documentos') : true;
$boletos = [];
$fiscais = [];
try { if ($canBoleto) $boletos = owb_order_boletos($pdo, $cid, $id); } catch (Throwable $e) { error_log('[Nexa][WhatsApp pedido] boletos: ' . $e->getMessage()); }
try { if ($canFiscal) $fiscais = owb_order_fiscal_docs($pdo, $cid, $id); } catch (Throwable $e) { error_log('[Nexa][WhatsApp pedido] fiscais: ' . $e->getMessage()); }

$options = ['pedido' => ['label' => 'Pedido (PDF)', 'detail' => $modernPrintEnabled ? 'PDF do pedido com a mensagem padrão' : 'Mensagem com os dados do pedido']];
foreach ($boletos as $b) $options['boleto:' . $b['id']] = ['label' => $b['label'], 'detail' => $b['detail']];
foreach ($fiscais as $f) $options['fiscal:' . $f['id']] = ['label' => $f['label'], 'detail' => $f['detail']];

// Modelo de mensagem por tipo de documento presente na tela.
$contextLabels = ['pedido' => 'Pedido', 'boleto' => 'Boleto', 'nfe' => 'NF-e', 'nfce' => 'NFC-e', 'nfse' => 'NFS-e'];
$modelContexts = ['pedido'];
if ($boletos) $modelContexts[] = 'boleto';
foreach ($fiscais as $f) if (!in_array($f['context'], $modelContexts, true)) $modelContexts[] = $f['context'];
$modelChoices = [];
$selectedModels = [];
foreach ($modelContexts as $ctx) {
  try { $modelChoices[$ctx] = owb_model_choices($pdo, $cid, $ctx); } catch (Throwable $e) { $modelChoices[$ctx] = ['models' => [], 'default' => 0]; }
  $posted = $_POST['models'][$ctx] ?? null;
  $mid = $posted !== null ? max(0, (int)$posted) : (int)$modelChoices[$ctx]['default'];
  if ($mid > 0 && !isset($modelChoices[$ctx]['models'][$mid])) $mid = (int)$modelChoices[$ctx]['default'];
  $selectedModels[$ctx] = $mid;
}
$fiscalContextById = [];
foreach ($fiscais as $f) $fiscalContextById[(int)$f['id']] = $f['context'];

auth_start();
if (empty($_SESSION['pedido_wa_docs_csrf'])) $_SESSION['pedido_wa_docs_csrf'] = bin2hex(random_bytes(24));
$csrf = (string)$_SESSION['pedido_wa_docs_csrf'];

$phone = whatsapp_digits((string)($_POST['phone'] ?? $_GET['phone'] ?? ($data['pedido']['whatsapp'] ?? '')));
$selected = array_values(array_intersect(array_map('strval', (array)($_POST['docs'] ?? array_keys($options))), array_keys($options)));
$results = [];
$err = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && (string)($_POST['action'] ?? '') === 'send_bundle') {
  if (!hash_equals($csrf, (string)($_POST['csrf'] ?? ''))) {
    $err = 'A página expirou. Atualize e tente novamente.';
  } elseif ($phone === '') {
    $err = 'Informe o número do WhatsApp do cliente.';
  } elseif (!$selected) {
    $err = 'Marque pelo menos um documento para enviar.';
  } else {
    foreach ($selected as $key) {
      $label = $options[$key]['label'];
      try {
        if ($key === 'pedido') {
          $cfg = owb_send_cfg($pdo, $cid);
          $msgCfg = whatsapp_load_config($pdo, $cid, false); // igual à tela antiga: modelo só quando escolhido
          $pedidoModel = (int)($selectedModels['pedido'] ?? 0);
          if ($pedidoModel > 0) $msgCfg['templates']['order_customer_full'] = (string)$modelChoices['pedido']['models'][$pedidoModel]['message'];
          $message = order_message_build_full($data['pedido'], $data['itens'], $appWa, $msgCfg);
          if ($modernPrintEnabled) {
            $companyName = trim((string)($appWa['site_name'] ?? ($appWa['brand']['catalog_title'] ?? ''))) ?: 'Nexa Próspero';
            $pdf = order_email_pdf_build((array)$data['pedido'], (array)$data['itens'], $companyName, $modernPrintModel, $pdo, $cid);
            $send = whatsapp_send_document($pdo, $cid, $phone, $message, (string)$pdf['filename'], (string)$pdf['mimetype'], (string)$pdf['content'], 'order_customer_pdf_admin', $id, $cfg);
            $ok = !empty($send['ok']); $error = (string)($send['error'] ?? '');
          } else {
            $ok = whatsapp_send_message($pdo, $cid, $phone, $message, 'order_customer_full_admin', $id, $cfg);
            $error = $ok ? '' : 'O serviço WhatsApp não confirmou o envio.';
          }
        } elseif (str_starts_with($key, 'boleto:')) {
          $send = owb_send_boleto($pdo, $cid, (int)substr($key, 7), $phone, (int)($selectedModels['boleto'] ?? 0));
          $ok = !empty($send['ok']); $error = (string)($send['error'] ?? '');
        } else {
          $fiscalId = (int)substr($key, 7);
          $send = owb_send_fiscal($pdo, $cid, $fiscalId, $phone, (int)($selectedModels[$fiscalContextById[$fiscalId] ?? ''] ?? 0));
          $ok = !empty($send['ok']); $error = (string)($send['error'] ?? '');
        }
      } catch (Throwable $e) {
        $ok = false; $error = $e->getMessage();
      }
      $results[] = ['label' => $label, 'ok' => $ok, 'error' => $ok ? '' : ($error ?: 'Falha no envio.')];
    }
    $failed = array_filter($results, static fn($r) => !$r['ok']);
    if (!$failed) {
      $sep = str_contains($returnTo, '?') ? '&' : '?';
      $sent = implode(', ', array_column($results, 'label'));
      header('Location: ' . $returnTo . $sep . 'ok=' . rawurlencode('Enviado pelo WhatsApp: ' . $sent . '.'));
      exit;
    }
  }
}

$pedidoNumero = function_exists('company_sequence_display') ? company_sequence_display($data['pedido'], 'pedidos') : (int)($data['pedido']['id'] ?? $id);
$cliente = trim((string)($data['pedido']['nome_cliente'] ?? '')) ?: 'Consumidor final';
$textOnlyUrl = 'pedido_whatsapp.php?id=' . (int)$id . '&return_to=' . rawurlencode($returnTo);
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Enviar pelo WhatsApp - Pedido #<?= pedido_wa_h($pedidoNumero) ?></title>
<style>
*{box-sizing:border-box}
body{font-family:Arial,Helvetica,sans-serif;margin:0;background:#f5f7fb;color:#0f172a;padding:18px}
.wrap{max-width:720px;margin:0 auto}
.card{background:#fff;border:1px solid #dbe4f0;border-radius:20px;padding:22px;box-shadow:0 14px 34px rgba(15,23,42,.07)}
h1{margin:0 0 6px;font-size:22px;color:#0b1f5b}.muted{color:#64748b;font-size:14px;line-height:1.5;margin:0 0 16px}
label.field{display:block;font-weight:700;font-size:14px;margin:0 0 6px}
input[type=text]{width:100%;padding:12px 13px;border:1px solid #cbd5e1;border-radius:12px;font-size:16px}
.docs{display:grid;gap:9px;margin:18px 0}
.doc{display:flex;gap:12px;align-items:flex-start;padding:12px 14px;border:1px solid #dbe4f0;border-radius:14px;background:#f8fafc;cursor:pointer}
.doc input{width:18px;height:18px;margin-top:2px}.doc strong{display:block;color:#0b1f5b}.doc span{font-size:13px;color:#64748b}
.empty{font-size:13px;color:#64748b;padding:4px 2px}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:16px}
.models{display:grid;gap:10px;padding:14px;border:1px solid #dbe4f0;border-radius:14px;background:#fff}
.model span{display:block;font-weight:700;font-size:13px;margin:0 0 5px;color:#0b1f5b}
.model select{width:100%;padding:10px 12px;border:1px solid #cbd5e1;border-radius:10px;font-size:14px;background:#fff}
.hint{margin:0;font-size:12px;color:#64748b}
.btn{display:inline-flex;align-items:center;justify-content:center;padding:12px 16px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0b1f5b;font-weight:700;text-decoration:none;cursor:pointer;font-size:14px}
.btn.wa{background:#16a34a;border-color:#16a34a;color:#fff}
.msg{padding:12px 14px;border-radius:12px;margin:0 0 14px;font-size:14px;line-height:1.5}
.msg.err{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}
.res{list-style:none;padding:0;margin:0 0 14px}.res li{padding:8px 0;border-bottom:1px solid #eef2f7;font-size:14px}
.ok{color:#047857;font-weight:700}.fail{color:#b91c1c;font-weight:700}
</style>
</head>
<body>
<div class="wrap"><div class="card">
  <h1>Enviar pelo WhatsApp · Pedido #<?= pedido_wa_h($pedidoNumero) ?></h1>
  <p class="muted">Cliente: <b><?= pedido_wa_h($cliente) ?></b>. Confira o número e marque o que deseja enviar. Cada documento vai em PDF, com a mensagem padrão do seu modelo.</p>

  <?php if ($err !== ''): ?><div class="msg err"><?= pedido_wa_h($err) ?></div><?php endif; ?>
  <?php if ($results): ?>
    <div class="msg err">Alguns envios não foram confirmados. Antes de reenviar, confira se o cliente já recebeu para não duplicar.</div>
    <ul class="res"><?php foreach ($results as $r): ?>
      <li><?= $r['ok'] ? '<span class="ok">✓ Enviado</span>' : '<span class="fail">✗ Não enviado</span>' ?> — <?= pedido_wa_h($r['label']) ?><?= $r['ok'] ? '' : '<br><small>' . pedido_wa_h($r['error']) . '</small>' ?></li>
    <?php endforeach; ?></ul>
  <?php endif; ?>

  <form method="post">
    <input type="hidden" name="action" value="send_bundle">
    <input type="hidden" name="csrf" value="<?= pedido_wa_h($csrf) ?>">
    <input type="hidden" name="id" value="<?= (int)$id ?>">
    <input type="hidden" name="return_to" value="<?= pedido_wa_h($returnTo) ?>">
    <label class="field" for="wa-phone">WhatsApp do cliente</label>
    <input type="text" id="wa-phone" name="phone" value="<?= pedido_wa_h($phone) ?>" inputmode="numeric" placeholder="Ex.: 31999999999">

    <div class="docs">
      <?php foreach ($options as $key => $opt): ?>
        <label class="doc"><input type="checkbox" name="docs[]" value="<?= pedido_wa_h($key) ?>" <?= in_array($key, $selected, true) ? 'checked' : '' ?>><div><strong><?= pedido_wa_h($opt['label']) ?></strong><span><?= pedido_wa_h($opt['detail']) ?></span></div></label>
      <?php endforeach; ?>
      <?php if ($canBoleto && !$boletos): ?><div class="empty">Nenhum boleto registrado em aberto para este pedido.</div><?php endif; ?>
      <?php if ($canFiscal && !$fiscais): ?><div class="empty">Nenhuma nota fiscal autorizada para este pedido.</div><?php endif; ?>
    </div>

    <div class="models">
      <?php foreach ($modelContexts as $ctx): $choice = $modelChoices[$ctx]; ?>
        <label class="model"><span>Modelo da mensagem · <?= pedido_wa_h($contextLabels[$ctx] ?? $ctx) ?></span>
          <select name="models[<?= pedido_wa_h($ctx) ?>]">
            <option value="0" <?= $selectedModels[$ctx] === 0 ? 'selected' : '' ?>>Mensagem padrão do sistema</option>
            <?php foreach ($choice['models'] as $mid => $m): ?>
              <option value="<?= (int)$mid ?>" <?= $selectedModels[$ctx] === (int)$mid ? 'selected' : '' ?>><?= pedido_wa_h($m['title']) ?><?= (int)$choice['default'] === (int)$mid ? ' — padrão' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php endforeach; ?>
      <p class="hint">Os modelos são cadastrados em <b>WhatsApp › Modelos de mensagens</b>.</p>
    </div>

    <div class="actions">
      <button class="btn wa" type="submit">Enviar selecionados</button>
      <a class="btn" href="<?= pedido_wa_h($textOnlyUrl) ?>">Abrir no WhatsApp (só texto)</a>
      <a class="btn" href="<?= pedido_wa_h($returnTo) ?>">Cancelar</a>
    </div>
  </form>
</div></div>
</body>
</html>
