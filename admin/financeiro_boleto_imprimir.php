<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/boleto_document.php';
require_once __DIR__ . '/_print_brand.php';

$billingCrossContext = function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_billing_read');
$cid = function_exists('multi_company_operational_company_id') ? multi_company_operational_company_id($pdo, 'finance_billing_read') : (int)COMPANY_ID;
$chargeId = (int)($_GET['charge_id'] ?? 0);
$receivableId = (int)($_GET['receivable_id'] ?? 0);
$returnTo = trim((string)($_GET['return_to'] ?? 'financeiro_contas_receber.php'));
if ($returnTo === '' || preg_match('#^https?://#i', $returnTo) || str_starts_with($returnTo, '//') || str_contains($returnTo, "\n") || str_contains($returnTo, "\r")) $returnTo = 'financeiro_contas_receber.php';
if ($billingCrossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
$selfQuery = $chargeId > 0 ? 'financeiro_boleto_imprimir.php?charge_id=' . $chargeId : 'financeiro_boleto_imprimir.php?receivable_id=' . $receivableId;
$pdfUrl = $selfQuery . '&format=pdf';
if ($billingCrossContext && function_exists('multi_company_context_url')) $pdfUrl = multi_company_context_url($pdfUrl);

try {
  $data = boleto_document_load($pdo, $cid, $chargeId, $receivableId);
  if (!boleto_document_ready($data)) throw new RuntimeException('Este boleto ainda não possui linha digitável e código de barras. Aguarde o registro no banco.');
  if ((string)($_GET['format'] ?? '') === 'pdf') {
    $pdf = boleto_document_pdf($data);
    $name = 'boleto-' . ((int)($data['receivable_id'] ?? 0) ?: (int)$data['id']) . '.pdf';
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . $name . '"');
    header('Content-Length: ' . strlen($pdf));
    echo $pdf;
    exit;
  }
} catch (Throwable $e) {
  http_response_code(422);
  ?><!doctype html><html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Boleto indisponível</title><style>body{font-family:Arial,sans-serif;background:#f6f8fc;margin:0;padding:24px;color:#0f172a}.box{max-width:760px;margin:50px auto;background:#fff;border:1px solid #fecaca;border-radius:20px;padding:22px}.btn{display:inline-block;margin-top:14px;padding:10px 14px;border-radius:12px;background:#0b1f5b;color:#fff;text-decoration:none;font-weight:800}</style></head><body><div class="box"><h2>Boleto indisponível</h2><p><?= boleto_document_h($e->getMessage()) ?></p><a class="btn" href="<?= boleto_document_h($returnTo) ?>">Voltar</a></div></body></html><?php
  exit;
}

$company = (array)($data['company'] ?? []);
$companyName = boleto_document_company_name($data);
$companyDoc = boleto_document_digits($company['cnpj'] ?? '');
$printLogoUrl = nexa_print_logo_url((int)$cid);
$payer = trim((string)($data['payer_name'] ?? '')) ?: 'Pagador';
$payerDoc = boleto_document_digits($data['payer_document'] ?? '');
$bank = boleto_document_bank_label($data);
$bankCodeDisplay = boleto_document_bank_code_with_dv($data);
$barcodeHtml = boleto_document_barcode_html((string)$data['barcode']);
$qrHtml = boleto_document_qr_html($data);

$isItau = boleto_document_is_itau($data);
$isSicoob = boleto_document_is_sicoob($data);
if ($isItau || $isSicoob) {
  // Itaú e Sicoob: a tela mostra o próprio PDF no modelo oficial do banco
  // (mesma logomarca e disposição do boleto emitido pelo banco). Assim a
  // impressão, o PDF, o e-mail e o WhatsApp saem sempre iguais.
  ?>
  <!doctype html>
  <html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Boleto <?= $isItau ? 'Itaú' : 'Sicoob' ?> #<?= (int)($data['receivable_id'] ?? 0) ?></title>
  <style>*{box-sizing:border-box}html,body{height:100%}body{margin:0;background:#eef2f7;font-family:Arial,Helvetica,sans-serif;display:flex;flex-direction:column}.toolbar{display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:12px;background:#0b1f5b}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}iframe{flex:1;width:100%;border:0;background:#fff}</style></head><body>
  <div class="toolbar"><a class="btn primary" href="<?= boleto_document_h($pdfUrl) ?>" target="_blank">Abrir PDF para imprimir</a><a class="btn" href="<?= boleto_document_h($returnTo) ?>">Voltar</a></div>
  <iframe src="<?= boleto_document_h($pdfUrl) ?>" title="Boleto"></iframe>
  </body></html>  <?php
  exit;
}
?>
<!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Boleto #<?= (int)($data['receivable_id'] ?? 0) ?></title>
<style>
*{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111827;font-family:Arial,Helvetica,sans-serif}.toolbar{position:sticky;top:0;z-index:20;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:12px;background:#0b1f5b}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}.sheet{width:min(100%,210mm);min-height:297mm;margin:18px auto;background:#fff;padding:10mm;box-shadow:0 16px 40px rgba(15,23,42,.15);overflow:hidden}.boleto{border-top:2px solid #111}.header{display:grid;grid-template-columns:42mm 22mm 1fr;align-items:center;border-bottom:1px solid #111;min-height:12mm}.bank{font-size:22px;font-weight:950}.bank-brand{display:flex;align-items:center;gap:2.2mm;padding:0 1.2mm}.sicoob-brand-svg{display:block;width:36mm;height:auto;max-width:100%}.bank-code{border-left:1px solid #111;border-right:1px solid #111;text-align:center;font-size:16px;font-weight:950;height:100%;display:grid;place-items:center}.linha{text-align:right;font-size:13px;font-weight:900;padding:0 2mm;word-break:break-word}.grid{display:grid}.row{display:grid;border-bottom:1px solid #111}.row.two{grid-template-columns:1fr 45mm}.row.five{grid-template-columns:34mm 42mm 30mm 39mm 45mm}.field{min-height:12mm;padding:1.2mm 1.5mm;border-right:1px solid #111}.field:last-child{border-right:0}.field .label{font-size:8px;color:#374151}.field .value{font-size:11px;font-weight:800;margin-top:1.2mm;line-height:1.25}.beneficiary-value{display:flex;align-items:center;gap:2mm}.beneficiary-logo{width:16mm;height:10mm;object-fit:contain;flex:0 0 16mm}.field.right .value{text-align:right}.payer{border-bottom:1px solid #111;padding:1.5mm;min-height:26mm}.payer .label{font-size:8px}.payer strong{display:block;font-size:11px;margin-top:2mm}.payer div{font-size:10px;margin-top:1mm}.comp{display:grid;grid-template-columns:minmax(0,1fr) 50mm;gap:3mm;padding-top:3mm;align-items:start}.comp>div,.comp>aside{min-width:0}.barcode-box{padding:2mm 0}.barcode-render{height:22mm;overflow:hidden}.barcode-render>div{height:22mm!important;max-width:100%;overflow:hidden}.barcode-number{text-align:center;font-size:8px;letter-spacing:.5px;margin-top:1mm}.pix-box{border:1px solid #111;padding:2mm;text-align:center;min-height:48mm;width:50mm;max-width:50mm;justify-self:end;overflow:hidden}.pix-box h3{font-size:11px;margin:0 0 2mm}.boleto-qr{width:24mm;height:24mm;object-fit:contain}.boleto-qr-svg svg{width:24mm!important;height:24mm!important}.pix-code{font-size:6px;line-height:1.15;word-break:break-all;margin-top:1mm;max-width:100%;overflow:hidden}.receipt-title{font-size:12px;font-weight:900;margin:9mm 0 2mm;border-bottom:1px dashed #64748b;padding-bottom:2mm}.footer-note{font-size:8px;color:#475569;margin-top:8mm;line-height:1.4}.cut{border-top:1px dashed #64748b;margin-top:8mm;padding-top:3mm;text-align:center;font-size:8px;color:#64748b}@media(max-width:760px){.sheet{margin:0;padding:5mm}.header{grid-template-columns:1fr 20mm}.linha{grid-column:1/-1;border-top:1px solid #111;padding:2mm}.row.five{grid-template-columns:1fr 1fr}.row.five .field{border-bottom:1px solid #111}.comp{grid-template-columns:1fr}.pix-box{width:100%;max-width:100%}}@media print{body{background:#fff}.toolbar{display:none}.sheet{width:190mm;min-height:auto;margin:0;padding:0;box-shadow:none}@page{size:A4 portrait;margin:10mm}}
</style>
</head>
<body>
<div class="toolbar"><button class="btn primary" type="button" onclick="window.print()">Imprimir</button><a class="btn" href="<?= boleto_document_h($pdfUrl) ?>" target="_blank">Abrir PDF</a><a class="btn" href="<?= boleto_document_h($returnTo) ?>">Voltar</a></div>
<main class="sheet">
  <section class="boleto">
    <div class="header"><div class="bank"><?php if($isSicoob): ?><div class="bank-brand"><svg class="sicoob-brand-svg" viewBox="0 0 220 60" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Sicoob"><rect x="0" y="9" width="42" height="42" rx="4" fill="#007a5e"/><text x="21" y="37" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="22" font-weight="700" fill="#ffffff">S</text><text x="54" y="39" font-family="Arial,Helvetica,sans-serif" font-size="30" font-weight="700" fill="#006b51">SICOOB</text></svg></div><?php else: ?><?= boleto_document_h($bank) ?><?php endif; ?></div><div class="bank-code"><?= boleto_document_h($bankCodeDisplay) ?></div><div class="linha"><?= boleto_document_h($data['linha_digitavel'] ?? '') ?></div></div>
    <div class="row two"><div class="field"><div class="label">Local de pagamento</div><div class="value">Pagável em qualquer banco ou via Pix até o vencimento</div></div><div class="field right"><div class="label">Vencimento</div><div class="value"><?= boleto_document_h(boleto_document_date($data['due_date'] ?? '')) ?></div></div></div>
    <div class="row two"><div class="field"><div class="label">Beneficiário</div><div class="value beneficiary-value"><?php if($printLogoUrl!==''): ?><img class="beneficiary-logo" src="<?= boleto_document_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?><span><?= boleto_document_h($companyName) ?><?= $companyDoc!==''?' · CNPJ '.boleto_document_h($companyDoc):'' ?></span></div></div><div class="field right"><div class="label">Agência / Conta</div><div class="value"><?= boleto_document_h(trim((string)($data['bank_agency']??'')) . ' / ' . trim((string)($data['bank_account_number']??''))) ?></div></div></div>
    <div class="row five"><div class="field"><div class="label">Data documento</div><div class="value"><?= boleto_document_h(boleto_document_date($data['issue_date']??'')) ?></div></div><div class="field"><div class="label">Nº documento</div><div class="value"><?= boleto_document_h($data['document_number']??$data['seu_numero']??'') ?></div></div><div class="field"><div class="label">Carteira</div><div class="value"><?= boleto_document_h($data['carteira']??'') ?></div></div><div class="field"><div class="label">Nosso Número</div><div class="value"><?= boleto_document_h($data['nosso_numero']??'') ?></div></div><div class="field right"><div class="label">Valor do documento</div><div class="value"><?= boleto_document_h(boleto_document_money($data['amount']??0)) ?></div></div></div>
    <div class="row two"><div class="field" style="min-height:18mm"><div class="label">Instruções</div><div class="value"><?= boleto_document_h(trim((string)($data['instructions']??'')) ?: 'Após o vencimento, consulte as condições atualizadas de pagamento.') ?></div></div><div class="field right" style="min-height:18mm"><div class="label">Descontos / encargos</div><div class="value">Conforme condições do título</div></div></div>
    <div class="payer"><div class="label">Pagador</div><strong><?= boleto_document_h($payer) ?><?= $payerDoc!==''?' · CPF/CNPJ '.boleto_document_h($payerDoc):'' ?></strong><div><?= boleto_document_h(boleto_document_payer_address($data)) ?></div></div>
    <div class="comp"><div><div style="text-align:right;font-size:9px;font-weight:800">Autenticação mecânica / Ficha de Compensação</div><div class="barcode-box"><div class="barcode-render"><?= $barcodeHtml ?></div><div class="barcode-number"><?= boleto_document_h($data['barcode']??'') ?></div></div></div><?php if($qrHtml !== '' || !empty($data['pix_emv'])): ?><aside class="pix-box"><h3>Pague também via Pix</h3><?= $qrHtml !== '' ? $qrHtml : '<div style="font-size:9px;padding-top:10mm">QR Code Pix não disponível.</div>' ?><?php if(!empty($data['pix_emv'])): ?><div class="pix-code"><?= boleto_document_h($data['pix_emv']) ?></div><?php endif; ?></aside><?php endif; ?></div>
  </section>
  <div class="receipt-title">Recibo do Pagador</div>
  <div class="row two"><div class="field"><div class="label">Beneficiário</div><div class="value beneficiary-value"><?php if($printLogoUrl!==''): ?><img class="beneficiary-logo" src="<?= boleto_document_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?><span><?= boleto_document_h($companyName) ?></span></div></div><div class="field right"><div class="label">Nosso Número</div><div class="value"><?= boleto_document_h($data['nosso_numero']??'') ?></div></div></div>
  <div class="row two"><div class="field"><div class="label">Pagador</div><div class="value"><?= boleto_document_h($payer) ?></div></div><div class="field right"><div class="label">Valor</div><div class="value"><?= boleto_document_h(boleto_document_money($data['amount']??0)) ?></div></div></div>
  <div class="row"><div class="field"><div class="label">Linha digitável</div><div class="value" style="text-align:center"><?= boleto_document_h($data['linha_digitavel']??'') ?></div></div></div>
  <div class="footer-note">Documento de cobrança gerado pelo Nexa Próspero a partir dos dados registrados pelo banco. Confira favorecido, vencimento e valor antes do pagamento.</div>
  <div class="cut">✂ Linha de corte</div>
</main>
</body></html>
