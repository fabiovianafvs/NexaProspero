<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/finance_receipt.php';

$cid = function_exists('finance_operational_company_id') ? finance_operational_company_id($pdo) : (int)COMPANY_ID;
$receiptCrossContext = function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo);
$receiptCrossSend = $receiptCrossContext && function_exists('finance_multi_company_receipt_send_enabled') && finance_multi_company_receipt_send_enabled($pdo);
$batch = preg_replace('/[^a-f0-9]/i', '', (string)($_GET['batch'] ?? '')) ?: '';
$layout = strtolower((string)($_GET['layout'] ?? 'a4')) === '72' ? '72' : 'a4';
$receiptLoadError = '';
try {
  $ids = finance_receipt_batch_ids($cid, $batch);
  $data = $ids ? finance_receipt_load($pdo, $cid, $ids) : [];
} catch (Throwable $e) {
  $ids = [];
  $data = [];
  $receiptLoadError = $e->getMessage();
  error_log('[NEXA V11.6.2 RECIBO] empresa=' . $cid . ' batch=' . $batch . ' ' . $receiptLoadError);
}
if (!$data) {
  http_response_code(404);
  finance_admin_start('Recibo de baixa', 'fin_receber');
  finance_page_header('Recibo de baixa', $receiptLoadError !== '' ? 'A baixa foi registrada, mas o recibo não pôde ser montado.' : 'O recibo solicitado não está mais disponível.');
  finance_tabs('fin_receber');
  if ($receiptLoadError !== '') {
    echo '<div class="msg err"><strong>V11.6.2 — falha ao montar o recibo:</strong><br>' . finance_receipt_h($receiptLoadError) . '<br><br>A baixa não é desfeita por este erro. Volte ao título para conferir o histórico.</div>';
  } else {
    echo '<div class="msg err">O recibo expirou ou não pertence a esta empresa. Volte ao Contas a Receber.</div>';
  }
  $fallbackBack = 'financeiro_contas_receber.php';
  if ($receiptCrossContext && function_exists('multi_company_context_url')) $fallbackBack = multi_company_context_url($fallbackBack);
  echo '<div style="margin-top:12px"><a class="btn btn-primary" href="' . finance_receipt_h($fallbackBack) . '">Voltar ao Contas a Receber</a></div>';
  finance_admin_end();
  exit;
}

function frb_date(string $value): string { return $value !== '' ? date('d/m/Y', strtotime($value)) : ''; }
function frb_receipt_copy(array $data): void {
  ?>
  <section class="receipt-copy">
    <header>
      <strong class="company"><?= finance_receipt_h($data['company_name'] ?? '') ?></strong>
      <h2>RECIBO DE BAIXA <?= finance_receipt_h($data['receipt_number'] ?? '') ?></h2>
      <div class="receipt-meta">
        <span><b>Data:</b> <?= finance_receipt_h(frb_date((string)($data['settlement_date'] ?? ''))) ?></span>
        <span><b>Cliente:</b> <?= finance_receipt_h($data['customer']['name'] ?? '') ?></span>
        <?php if (!empty($data['customer']['document'])): ?><span><b>CPF/CNPJ:</b> <?= finance_receipt_h($data['customer']['document']) ?></span><?php endif; ?>
      </div>
    </header>

    <div class="receipt-lines">
      <?php foreach ((array)($data['rows'] ?? []) as $row): ?>
        <article class="receipt-title-line">
          <div class="receipt-title-head"><strong>#<?= (int)($row['title_id'] ?? 0) ?></strong><span>Doc./Pedido <?= finance_receipt_h($row['document_number'] ?? '-') ?></span><span>Parc. <?= finance_receipt_h($row['installment'] ?? '-') ?></span><span>Venc. <?= finance_receipt_h(frb_date((string)($row['due_date'] ?? ''))) ?></span></div>
          <?php if (!empty($row['description'])): ?><div class="receipt-desc"><?= finance_receipt_h($row['description']) ?></div><?php endif; ?>
          <div class="receipt-values">
            <span>Principal <b>R$ <?= finance_receipt_money($row['principal_amount'] ?? 0) ?></b></span>
            <span>Multa <b>R$ <?= finance_receipt_money($row['fine_amount'] ?? 0) ?></b></span>
            <span>Juros <b>R$ <?= finance_receipt_money($row['interest_amount'] ?? 0) ?></b></span>
            <span>Desconto <b>R$ <?= finance_receipt_money($row['discount_amount'] ?? 0) ?></b></span>
            <span class="receipt-row-total">Total <b>R$ <?= finance_receipt_money($row['total_received'] ?? 0) ?></b></span>
          </div>
        </article>
      <?php endforeach; ?>
    </div>

    <div class="receipt-summary">
      <div><span>Principal</span><b>R$ <?= finance_receipt_money($data['principal'] ?? 0) ?></b></div>
      <div><span>Multa</span><b>R$ <?= finance_receipt_money($data['fine'] ?? 0) ?></b></div>
      <div><span>Juros</span><b>R$ <?= finance_receipt_money($data['interest'] ?? 0) ?></b></div>
      <div><span>Desconto</span><b>R$ <?= finance_receipt_money($data['discount'] ?? 0) ?></b></div>
      <div class="grand"><span>Total recebido</span><b>R$ <?= finance_receipt_money($data['total'] ?? 0) ?></b></div>
    </div>

    <div class="receipt-payments">
      <strong>Formas de pagamento</strong>
      <?php foreach ((array)($data['payments'] ?? []) as $name=>$amount): ?>
        <span><?= finance_receipt_h($name) ?> — <b>R$ <?= finance_receipt_money($amount) ?></b></span>
      <?php endforeach; ?>
    </div>

    <footer>
      <div class="signature"></div>
      <small>Assinatura / confirmação do recebimento</small>
    </footer>
  </section>
  <?php
}

$receiptReturn = 'financeiro_recibo_baixa.php?batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout);
$receipt72Href = 'financeiro_recibo_baixa.php?batch=' . rawurlencode($batch) . '&layout=72';
$receiptA4Href = 'financeiro_recibo_baixa.php?batch=' . rawurlencode($batch) . '&layout=a4';
$receiptWhatsappHref = 'financeiro_recibo_whatsapp.php?batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout);
$receiptEmailHref = 'email_documento.php?type=recibo&batch=' . rawurlencode($batch) . '&layout=' . rawurlencode($layout) . '&return_to=' . rawurlencode($receiptReturn);
$receiptBackHref = 'financeiro_contas_receber.php';
if ($receiptCrossContext && function_exists('multi_company_context_url')) {
  $receipt72Href = multi_company_context_url($receipt72Href);
  $receiptA4Href = multi_company_context_url($receiptA4Href);
  $receiptWhatsappHref = multi_company_context_url($receiptWhatsappHref);
  $receiptEmailHref = multi_company_context_url($receiptEmailHref);
  $receiptBackHref = multi_company_context_url($receiptBackHref);
}

finance_admin_start('Recibo de baixa', 'fin_receber');
finance_page_header('Recibo de baixa', $receiptCrossContext ? 'Baixa concluída na empresa selecionada pelo F11. Confira, imprima ou envie o recibo usando as configurações desta empresa.' : 'Baixa concluída. Confira o recibo, imprima ou envie pelo WhatsApp.', [
  ['href'=>$receiptBackHref,'label'=>'Voltar ao Contas a Receber','class'=>'btn'],
]);
finance_tabs('fin_receber');
finance_flash();
?>
<style>
.receipt-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap;margin-bottom:12px}.receipt-toolbar .btn.active{background:#0B1F5B;color:#fff;border-color:#0B1F5B}.receipt-whatsapp{background:#25D366!important;border-color:#1ebe57!important;color:#fff!important}.receipt-stage{background:#eef2f7;border:1px solid #dbe4f0;border-radius:18px;padding:16px;overflow:auto}.receipt-a4{width:min(100%,980px);margin:0 auto;background:#fff;display:grid;grid-template-columns:1fr 1fr;gap:0;box-shadow:0 12px 35px rgba(15,23,42,.12);min-height:680px}.receipt-a4>.receipt-copy:first-child{border-right:1px dashed #94a3b8}.receipt-72{width:320px;max-width:100%;margin:0 auto;background:#fff;box-shadow:0 12px 35px rgba(15,23,42,.12)}.receipt-copy{padding:16px;color:#111827;font-family:Arial,Helvetica,sans-serif;font-size:11px}.receipt-copy header{text-align:center;border-bottom:1px solid #cbd5e1;padding-bottom:8px;margin-bottom:8px}.receipt-copy .company{display:block;font-size:14px}.receipt-copy h2{font-size:13px;margin:5px 0}.receipt-meta{display:grid;gap:2px;text-align:left;font-size:10px}.receipt-lines{display:grid;gap:6px}.receipt-title-line{border-bottom:1px dotted #cbd5e1;padding-bottom:6px}.receipt-title-head{display:flex;gap:6px;flex-wrap:wrap;font-size:9px}.receipt-desc{font-size:9px;margin-top:2px;color:#475569}.receipt-values{display:grid;grid-template-columns:repeat(2,1fr);gap:2px 6px;margin-top:4px;font-size:9px}.receipt-values span{display:flex;justify-content:space-between;gap:5px}.receipt-row-total{grid-column:1/-1;border-top:1px solid #e2e8f0;padding-top:2px;font-weight:900}.receipt-summary{display:grid;grid-template-columns:1fr 1fr;gap:4px;margin-top:9px}.receipt-summary>div{border:1px solid #dbe4f0;border-radius:6px;padding:5px;display:flex;justify-content:space-between;gap:5px}.receipt-summary .grand{grid-column:1/-1;background:#0B1F5B;color:#fff;font-size:12px}.receipt-payments{display:grid;gap:2px;margin-top:8px;font-size:9px}.receipt-payments span{display:flex;justify-content:space-between}.receipt-copy footer{margin-top:20px;text-align:center}.signature{border-top:1px solid #334155;margin:0 8% 4px}.receipt-72 .receipt-copy{padding:10px;font-size:10px}.receipt-72 .receipt-copy .company{font-size:13px}.receipt-72 .receipt-values{grid-template-columns:1fr}.receipt-72 .receipt-row-total{grid-column:auto}.receipt-72 .receipt-summary{grid-template-columns:1fr}.receipt-72 .receipt-summary .grand{grid-column:auto}
@media(max-width:760px){.receipt-a4{grid-template-columns:1fr}.receipt-a4>.receipt-copy:first-child{border-right:0;border-bottom:1px dashed #94a3b8}.receipt-stage{padding:8px}}
@media print{
  body{background:#fff!important}.admin-sidebar,.admin-topbar,.finance-tabs,.receipt-toolbar,.finance-page-head,.admin-footer{display:none!important}
  .admin-main,.admin-content,.finance-content{margin:0!important;padding:0!important;width:100%!important;max-width:none!important}
  .receipt-stage{border:0!important;padding:0!important;background:#fff!important;overflow:visible!important}.receipt-a4,.receipt-72{box-shadow:none!important;margin:0!important}
  <?php if ($layout === '72'): ?>
  @page{size:72mm 297mm;margin:3mm}.receipt-72{width:66mm!important}.receipt-copy{padding:0!important}.receipt-a4{display:none!important}
  <?php else: ?>
  @page{size:A4 portrait;margin:7mm}.receipt-a4{width:196mm!important;min-height:280mm!important}.receipt-copy{padding:5mm!important}.receipt-72{display:none!important}
  <?php endif; ?>
}
</style>

<?php if (isset($_GET['wa_sent'])): ?><div class="msg ok">Recibo enviado pelo WhatsApp.</div><?php endif; ?>
<div class="receipt-toolbar">
  <a class="btn <?= $layout==='72'?'active':'' ?>" href="<?= finance_receipt_h($receipt72Href) ?>">Recibo 72 mm</a>
  <a class="btn <?= $layout==='a4'?'active':'' ?>" href="<?= finance_receipt_h($receiptA4Href) ?>">A4 — 2 vias lado a lado</a>
  <button class="btn btn-primary" type="button" onclick="window.print()">Imprimir</button>
  <?php if (!$receiptCrossContext || $receiptCrossSend): ?>
    <a class="btn receipt-whatsapp" href="<?= finance_receipt_h($receiptWhatsappHref) ?>">WhatsApp</a>
    <a class="btn" href="<?= finance_receipt_h($receiptEmailHref) ?>">✉ E-mail</a>
  <?php else: ?>
    <span class="finance-muted" style="font-weight:800">F11: seu perfil não possui permissão para enviar o recibo desta empresa.</span>
  <?php endif; ?>
</div>

<div class="receipt-stage">
<?php if ($layout === '72'): ?>
  <div class="receipt-72"><?php frb_receipt_copy($data); ?></div>
<?php else: ?>
  <div class="receipt-a4"><?php frb_receipt_copy($data); frb_receipt_copy($data); ?></div>
<?php endif; ?>
</div>
<script>
document.addEventListener('keydown', function(e){
  if (e.key === 'Escape' || e.key === 'Esc') {
    e.preventDefault();
    window.location.href = <?= json_encode($receiptBackHref, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
  }
});
</script>
<?php finance_admin_end(); ?>
