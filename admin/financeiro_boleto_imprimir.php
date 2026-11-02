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
if ($isItau) {
  // A marca do Itaú na impressão HTML é desenhada em SVG inline.
  // Não usa arquivo externo nem data URI; assim não depende de caminho de asset,
  // permissões do servidor, cache ou política do navegador para carregar imagens.

  $companyAddress = trim((string)($company['address'] ?? ''));
  $beneficiaryCode = boleto_document_itau_beneficiary_code($data);
  $ourNumber = boleto_document_itau_our_number($data);
  $issueDate = boleto_document_date($data['issue_date'] ?? $data['receivable_issue_date'] ?? '');
  $docNumber = trim((string)($data['document_number'] ?? $data['seu_numero'] ?? ''));
  $wallet = trim((string)($data['carteira'] ?? ''));
  $amountPlain = number_format((float)($data['amount'] ?? 0), 2, ',', '.');
  $payerDocFormatted = boleto_document_format_document($data['payer_document'] ?? '');
  $instructions = trim((string)($data['instructions'] ?? ''));
  $instructionBase = 'Instruções de responsabilidade do BENEFICIÁRIO. Qualquer dúvida sobre este boleto contate o BENEFICIÁRIO.';
  $paymentNote = 'Escolha a forma mais conveniente para realizar seu pagamento: Código de Barras ou QR Code. Basta acessar o aplicativo da sua instituição financeira e utilizar apenas uma das opções.';
  ?>
  <!doctype html>
  <html lang="pt-br"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Boleto Itaú #<?= (int)($data['receivable_id'] ?? 0) ?></title>
  <style>
  *{box-sizing:border-box}body{margin:0;background:#eef2f7;color:#111;font-family:Arial,Helvetica,sans-serif}.toolbar{position:sticky;top:0;z-index:20;display:flex;gap:8px;justify-content:center;flex-wrap:wrap;padding:12px;background:#0b1f5b}.btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 14px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:900;cursor:pointer}.btn.primary{background:#2563eb;border-color:#2563eb;color:#fff}.itau-sheet{width:182mm;min-height:250mm;margin:18px auto;background:#fff;padding:0;box-shadow:0 16px 40px rgba(15,23,42,.15);font-family:Arial,Helvetica,sans-serif}.receipt-title{text-align:center;font-size:11px;font-weight:900;padding:5mm 0 2mm}.itau-block{width:100%;border:1px solid #111}.r{display:grid;border-bottom:1px solid #111}.r:last-child{border-bottom:0}.bank-row{grid-template-columns:39mm 17mm 1fr;height:9mm}.bank-row>div{border-right:1px solid #111}.bank-row>div:last-child{border-right:0}.brand{display:flex;align-items:center;padding:1mm}.itau-brand-svg{display:block;width:31.5mm;height:auto;max-width:100%}.itau-mark{display:grid;place-items:center;width:8mm;height:8mm;background:#0f2b7a;color:#fff;font-weight:900;font-size:11px}.bank-name{font-size:13px;font-weight:900}.bank-code{display:grid;place-items:center;font-size:14px;font-weight:900}.digitable{display:flex;align-items:center;justify-content:flex-end;padding:1mm 1.5mm;font-size:12px;font-weight:900;white-space:nowrap}.two{grid-template-columns:1fr 44mm}.docrow{grid-template-columns:30mm 36mm 24mm 14mm 34mm 44mm}.moneyrow{grid-template-columns:30mm 23mm 17mm 35mm 33mm 44mm}.cell{min-height:9mm;padding:.8mm 1mm;border-right:1px solid #111;overflow:hidden}.cell:last-child{border-right:0}.label{font-size:7px;line-height:1.05}.value{font-size:10px;line-height:1.2;text-align:center;margin-top:1mm}.value.bold{font-weight:900}.local-row .cell{min-height:10mm}.local-text{font-size:9px;margin-left:29mm;margin-top:-2.7mm;white-space:nowrap}.benef-row .cell{min-height:14mm}.benef-wrap{display:flex;align-items:flex-start;gap:2mm}.benef-logo{width:15mm;height:10mm;object-fit:contain;flex:0 0 15mm}.benef-body{min-width:0;flex:1}.benef-name{font-size:10px;font-weight:900;margin-top:.8mm}.benef-meta{display:flex;gap:2mm;align-items:baseline}.benef-meta b{margin-left:auto}.benef-address{font-size:8px;margin-top:.8mm;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.instructions{grid-template-columns:1fr 44mm}.instructions .left{position:relative;min-height:31mm;padding:1mm;border-right:1px solid #111}.instructions.big .left{min-height:52mm}.instruction-title{font-size:7px;font-weight:900;line-height:1.2}.instruction-extra{font-size:7px;margin-top:1.5mm;line-height:1.25}.right-stack{display:grid;grid-template-rows:repeat(3,1fr)}.right-stack .stack{padding:.8mm 1mm;border-bottom:1px solid #111;font-size:8px}.right-stack .stack:last-child{border-bottom:0}.qr-wrap{position:absolute;width:33mm;height:33mm;right:5mm;top:7mm;display:grid;place-items:center}.qr-wrap .boleto-qr,.qr-wrap .boleto-qr-svg svg{width:33mm!important;height:33mm!important}.payment-note{position:absolute;left:1mm;right:1mm;bottom:1.5mm;font-size:6.5px;line-height:1.2}.payer-row{height:9mm;padding:.7mm 1mm;font-size:8px;line-height:1.6}.payer-line{display:grid;grid-template-columns:16mm 66mm 20mm 1fr}.payer-line b{font-weight:900}.auth{font-size:8px;text-align:right;margin-top:4mm}.cutline{border-top:1px dashed #888;margin:1.5mm 0 4mm}.bottom{display:grid;grid-template-columns:112mm 1fr;gap:2mm;margin-top:1mm}.barcode-render{height:14.5mm;overflow:hidden}.barcode-render>div{height:14.5mm!important;max-width:100%;overflow:hidden}.comp{text-align:right}.comp strong{display:block;font-size:13px}.comp span{font-size:8px}.itau-sheet .boleto-qr{object-fit:contain}@media(max-width:800px){.itau-sheet{width:100%;margin:0;box-shadow:none;overflow:auto}.digitable{font-size:10px}.local-text{white-space:normal}}@media print{body{background:#fff}.toolbar{display:none}.itau-sheet{width:182mm;min-height:auto;margin:0 auto;box-shadow:none}.receipt-title{padding-top:2mm}@page{size:A4 portrait;margin:10mm 14mm}}
  </style></head><body>
  <div class="toolbar"><button class="btn primary" type="button" onclick="window.print()">Imprimir</button><a class="btn" href="<?= boleto_document_h($pdfUrl) ?>" target="_blank">Abrir PDF</a><a class="btn" href="<?= boleto_document_h($returnTo) ?>">Voltar</a></div>
  <main class="itau-sheet">
    <div class="receipt-title">RECIBO DO PAGADOR</div>
    <?php for($part=0;$part<2;$part++): $receipt=($part===0); ?>
      <section class="itau-block">
        <div class="r bank-row"><div class="brand"><svg class="itau-brand-svg" viewBox="0 0 246 56" xmlns="http://www.w3.org/2000/svg" role="img" aria-label="Banco Itaú S.A."><rect x="1" y="1" width="54" height="54" rx="8" fill="#0f2b7a"/><text x="28" y="39" text-anchor="middle" font-family="Arial,Helvetica,sans-serif" font-size="28" font-weight="700" fill="#ffffff">itaú</text><text x="68" y="38" font-family="Arial,Helvetica,sans-serif" font-size="25" font-weight="400" fill="#111111">Banco Itaú S.A.</text></svg></div><div class="bank-code"><?= boleto_document_h($bankCode) ?>-7</div><div class="digitable"><?= boleto_document_h($data['linha_digitavel'] ?? '') ?></div></div>
        <div class="r two local-row"><div class="cell"><div class="label"><b>Local de pagamento:</b></div><div class="local-text">Pague pelo aplicativo, internet ou em agências e correspondentes.</div></div><div class="cell"><div class="label">Vencimento</div><div class="value bold"><?= boleto_document_h(boleto_document_date($data['due_date'] ?? '')) ?></div></div></div>
        <div class="r two benef-row"><div class="cell"><div class="label">Beneficiário</div><div class="benef-wrap"><?php if($printLogoUrl!==''): ?><img class="benef-logo" src="<?= boleto_document_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?><div class="benef-body"><div class="benef-meta"><span class="benef-name"><?= boleto_document_h($companyName) ?></span><b>CNPJ/CPF:</b><span><?= boleto_document_h($companyDoc) ?></span></div><div class="benef-address"><?= boleto_document_h($companyAddress) ?></div></div></div></div><div class="cell"><div class="label">Agência/Código Beneficiário</div><div class="value"><?= boleto_document_h($beneficiaryCode) ?></div></div></div>
        <div class="r docrow"><?php foreach ([['Data do documento',$issueDate],['Núm. do documento',$docNumber],['Espécie Doc.','DMI'],['Aceite','N'],['Data Processamento',$issueDate],['Nosso Número',$ourNumber]] as $dv): ?><div class="cell"><div class="label"><?= boleto_document_h($dv[0]) ?></div><div class="value"><?= boleto_document_h($dv[1]) ?></div></div><?php endforeach; ?></div>
        <div class="r moneyrow"><?php foreach ([['Uso do Banco',''],['Carteira',$wallet],['Espécie','R$'],['Quantidade',''],['Valor',''],['(=) Valor do Documento',$amountPlain]] as $idx=>$mv): ?><div class="cell"><div class="label"><?= boleto_document_h($mv[0]) ?></div><div class="value <?= $idx===5?'bold':'' ?>"><?= boleto_document_h($mv[1]) ?></div></div><?php endforeach; ?></div>
        <div class="r instructions <?= $receipt?'':'big' ?>"><div class="left"><div class="instruction-title"><?= boleto_document_h($instructionBase) ?></div><?php if($instructions!==''): ?><div class="instruction-extra"><?= nl2br(boleto_document_h($instructions)) ?></div><?php endif; ?><?php if(!$receipt && ($qrHtml!=='' || !empty($data['pix_emv']))): ?><div class="qr-wrap"><?= $qrHtml !== '' ? $qrHtml : '' ?></div><div class="payment-note"><?= boleto_document_h($paymentNote) ?></div><?php endif; ?></div><div class="right-stack"><div class="stack">(-) Descontos/Abatimento</div><div class="stack">(+) Juros/Multa</div><div class="stack">(=) Valor Cobrado</div></div></div>
        <div class="payer-row"><div class="payer-line"><b>Pagador:</b><span><?= boleto_document_h($payer) ?></span><b>CNPJ/CPF:</b><span><?= boleto_document_h($payerDocFormatted) ?></span></div><div class="payer-line"><b style="grid-column:1/3">Beneficiário final:</b><b>CNPJ/CPF:</b><span></span></div></div>
      </section>
      <?php if($receipt): ?><div class="auth">Autenticação mecânica</div><div class="cutline"></div><?php endif; ?>
    <?php endfor; ?>
    <div class="bottom"><div class="barcode-render"><?= $barcodeHtml ?></div><div class="comp"><strong>Ficha de Compensação</strong><span>Autenticação mecânica</span></div></div>
  </main></body></html>
  <?php
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
