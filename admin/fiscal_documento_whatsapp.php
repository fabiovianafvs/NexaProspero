<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/whatsapp_service.php';
require_once __DIR__ . '/../config/fiscal_document_pdf.php';
require_once __DIR__ . '/../config/navigation_return.php';

$cid = (int)COMPANY_ID;
$docId = (int)($_GET['doc_id'] ?? $_GET['id'] ?? $_POST['doc_id'] ?? 0);
$returnTo = nexa_navigation_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ''), 'fiscal_documentos.php');

function fdw_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function fdw_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function fdw_doc_label(string $model): string { return $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : ($model === 'NFSE' ? 'NFS-e' : 'Documento fiscal')); }

try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
$st = $pdo->prepare("SELECT d.*,n.provider_code,n.dps_series,n.dps_number,n.nfse_number,n.verification_code,n.response_xml FROM fiscal_documents d LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id WHERE d.company_id=? AND d.id=? AND d.model IN ('55','65','NFSE') LIMIT 1");
$st->execute([$cid, $docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); echo 'Documento fiscal não encontrado.'; exit; }

$modelRaw = strtoupper(trim((string)($doc['model'] ?? '')));
$isNfse = $modelRaw === 'NFSE';
$dest = fiscal_nfce_document_participant($pdo, $cid, $docId, $isNfse ? 'TOMADOR' : 'DESTINATARIO');
$emit = fiscal_nfce_document_participant($pdo, $cid, $docId, $isNfse ? 'PRESTADOR' : 'EMITENTE');
$model = $isNfse ? 'NFSE' : (preg_replace('/\D+/', '', $modelRaw) ?: '65');
$label = fdw_doc_label($model);
$displayNumber = $isNfse ? (trim((string)($doc['nfse_number'] ?? '')) ?: (string)($doc['number'] ?? '')) : (string)($doc['number'] ?? '');
$displaySeries = $isNfse ? trim((string)($doc['dps_series'] ?? $doc['series'] ?? '')) : (string)($doc['series'] ?? '');
$phone = whatsapp_digits((string)($_POST['phone'] ?? $_GET['phone'] ?? ($dest['phone'] ?? '')));
$key = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
$customerName = trim((string)($dest['name'] ?? '')) ?: 'cliente';
$issuerName = trim((string)($emit['name'] ?? '')) ?: 'empresa';
$fallbackMessage = trim(implode("\n", array_filter([
  'Olá, ' . $customerName . '!',
  'Segue em anexo o PDF do documento fiscal emitido pela ' . $issuerName . '.',
  $isNfse ? ($label . ' nº ' . $displayNumber . ' · DPS ' . $displaySeries . '/' . (string)($doc['dps_number'] ?? $doc['number'] ?? '')) : ($label . ' nº ' . (string)($doc['series'] ?? '') . '/' . (string)($doc['number'] ?? '')),
  'Total: ' . fdw_money($doc['total_document'] ?? 0),
  $key !== '' ? 'Chave de acesso: ' . $key : '',
])));

// O catálogo de modelos é opt-in. Sem um modelo selecionado para NF-e/NFC-e,
// a mensagem anterior permanece exatamente igual.
$message = whatsapp_message_model_render($pdo, $cid, $model === '55' ? 'nfe' : ($model === 'NFSE' ? 'nfse' : 'nfce'), [
  'empresa' => $issuerName,
  'cliente' => $customerName,
  'documento' => $label,
  'numero' => $displayNumber,
  'serie' => $displaySeries,
  'dps_serie' => $displaySeries,
  'dps_numero' => (string)($doc['dps_number'] ?? $doc['number'] ?? ''),
  'codigo_verificacao' => (string)($doc['verification_code'] ?? ''),
  'total' => fdw_money($doc['total_document'] ?? 0),
  'chave_acesso' => $key,
], $fallbackMessage);

$err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $phone = whatsapp_digits((string)($_POST['phone'] ?? ''));
  if ($phone === '') {
    $err = 'Informe o número do WhatsApp para envio.';
  } else {
    $mode = (string)($_POST['mode'] ?? 'api');
    if ($mode === 'api') {
      try {
        if (function_exists('fiscal_company_report_identity')) $doc['_issuer_identity'] = fiscal_company_report_identity($pdo, $cid);
        if ($isNfse) { $doc['_prestador'] = $emit; $doc['_tomador'] = $dest; }
        $pdf = fiscal_document_pdf_build($doc);
        $waCfg = whatsapp_load_config($pdo, $cid);
        // O botão manual de documento deve funcionar mesmo quando somente os envios
        // automáticos do checkout/status estiverem desativados.
        $waCfg['enabled'] = true;
        $waCfg['timeout_seconds'] = max(60, (int)($waCfg['timeout_seconds'] ?? 30));
        $send = whatsapp_send_document(
          $pdo,
          $cid,
          $phone,
          $message,
          (string)$pdf['filename'],
          (string)$pdf['mimetype'],
          (string)$pdf['content'],
          'fiscal_document_admin',
          null,
          $waCfg
        );
        if (!empty($send['ok'])) {
          $sep = str_contains($returnTo, '?') ? '&' : '?';
          header('Location: ' . $returnTo . $sep . 'ok=' . rawurlencode($label . ' enviada em PDF pelo WhatsApp.'));
          exit;
        }
        $err = trim((string)($send['error'] ?? '')) ?: 'Não foi possível enviar o PDF pelo serviço WhatsApp.';
      } catch (Throwable $e) {
        $err = $e->getMessage();
      }
    } else {
      // O protocolo wa.me permite preencher a mensagem, mas navegadores não podem
      // anexar automaticamente um arquivo local por segurança.
      $to = whatsapp_normalize_phone($phone);
      $manualMessage = $message . "\n\nO PDF deverá ser anexado manualmente nesta conversa.";
      header('Location: https://wa.me/' . rawurlencode($to) . '?text=' . rawurlencode($manualMessage));
      exit;
    }
  }
}

fiscal_admin_start('Enviar PDF por WhatsApp', 'fiscal_documentos');
fiscal_page_header('Enviar ' . $label . ' em PDF', 'Confirme o número do cliente. O serviço conectado enviará o PDF como anexo.', [
  ['label'=>'Voltar', 'href'=>$returnTo, 'class'=>'btn'],
]);
fiscal_tabs('fiscal_documentos');
if ($err !== '') echo '<div class="msg err">' . fdw_h($err) . '</div>';
?>
<style>
.fdw-whatsapp-button{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important;box-shadow:0 8px 18px rgba(37,211,102,.24)}
.fdw-whatsapp-button:hover{background:#1fbd5a!important;border-color:#18a94c!important;color:#fff!important}
.fdw-note{border:1px solid #bbf7d0;background:#f0fdf4;color:#166534;border-radius:14px;padding:12px 14px;line-height:1.45}
.fdw-file{display:flex;gap:12px;align-items:center;border:1px solid #dbe4f0;background:#f8fafc;border-radius:14px;padding:12px}
.fdw-file-icon{width:44px;height:44px;border-radius:12px;background:#fee2e2;color:#b91c1c;display:grid;place-items:center;font-weight:950}
</style>
<div class="fiscal-card" style="max-width:780px">
  <h2><?= fdw_h($label) ?> <?= fdw_h($isNfse ? ('nº ' . $displayNumber) : (($doc['series'] ?? '') . '/' . ($doc['number'] ?? ''))) ?></h2>
  <p class="fiscal-muted">Cliente: <b><?= fdw_h($customerName) ?></b></p>
  <div class="fdw-file" style="margin:12px 0">
    <div class="fdw-file-icon">PDF</div>
    <div><strong><?= fdw_h($label) ?> em PDF</strong><div class="fiscal-muted">O arquivo será gerado a partir do XML armazenado e enviado diretamente ao cliente.</div></div>
  </div>
  <form method="post" class="fiscal-form">
    <input type="hidden" name="doc_id" value="<?= (int)$docId ?>">
    <input type="hidden" name="return_to" value="<?= fdw_h($returnTo) ?>">
    <label>Número do WhatsApp
      <input name="phone" value="<?= fdw_h($phone) ?>" placeholder="Ex.: 31999999999" inputmode="numeric" autofocus>
    </label>
    <label>Mensagem que acompanhará o PDF
      <textarea rows="7" readonly><?= fdw_h($message) ?></textarea>
    </label>
    <div class="fdw-note">
      <b>Envio automático:</b> o botão verde envia o PDF como documento pelo WhatsApp conectado no Nexa. A opção de abertura manual preenche somente a mensagem, pois o navegador não pode anexar arquivos automaticamente.
    </div>
    <div class="mg-form-actions">
      <button class="btn fdw-whatsapp-button" type="submit" name="mode" value="api">Enviar PDF pelo WhatsApp</button>
      <button class="btn" type="submit" name="mode" value="web">Abrir WhatsApp sem anexo</button>
      <a class="btn" href="email_documento.php?type=fiscal&doc_id=<?= (int)$docId ?>&return_to=<?= rawurlencode($returnTo) ?>">✉ Enviar por e-mail</a>
      <a class="btn" href="<?= fdw_h($returnTo) ?>">Cancelar</a>
    </div>
  </form>
</div>
<?php fiscal_admin_end(); ?>
