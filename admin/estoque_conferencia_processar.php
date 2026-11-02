<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require_once __DIR__ . '/../config/stock_conference.php';

stock_conference_ensure_schema($pdo);
$conferenceCompanyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'stock_conference')
  : (int)COMPANY_ID;
$conferenceCrossCompany = $conferenceCompanyId > 0 && $conferenceCompanyId !== (int)COMPANY_ID;
$conferenceCompanyName = 'Empresa ' . $conferenceCompanyId;
try {
  $stConferenceCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $stConferenceCompany->execute([$conferenceCompanyId]);
  $conferenceCompanyName = (string)($stConferenceCompany->fetchColumn() ?: $conferenceCompanyName);
} catch (Throwable $ignored) {}

$id = (int)($_POST['id'] ?? ($_GET['id'] ?? 0));
if ($id <= 0) {
  header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencias.php') : 'estoque_conferencias.php'));
  exit;
}

$conference = stock_conference_get($pdo, $conferenceCompanyId, $id);
if (!$conference) {
  http_response_code(404);
  exit('Conferência de estoque não encontrada.');
}

$msg = trim((string)($_GET['msg'] ?? ''));
$erro = '';
$preview = null;
$isProcessed = (string)$conference['status'] === 'PROCESSED';
$referenceAt = $isProcessed
  ? (string)($conference['reference_at'] ?? $conference['counted_at'] ?? date('Y-m-d H:i:s'))
  : (string)($conference['counted_at'] ?? date('Y-m-d H:i:s'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = (string)($_POST['action'] ?? '');
  $referenceAt = stock_conference_datetime((string)($_POST['reference_at'] ?? $referenceAt));
  try {
    if ($action === 'preview') {
      $preview = stock_conference_preview($pdo, $conferenceCompanyId, $id, $referenceAt);
    } elseif ($action === 'process') {
      if (empty($_POST['confirm_process'])) {
        throw new RuntimeException('Marque a confirmação para efetivar o ajuste de estoque.');
      }
      $result = stock_conference_process($pdo, $conferenceCompanyId, $id, $referenceAt);
      $message = 'Conferência processada: ' . (int)$result['items'] . ' produto(s) conferido(s) e ' . (int)$result['changed'] . ' saldo(s) ajustado(s).';
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_processar.php?id=' . $id . '&msg=' . urlencode($message)) : ('estoque_conferencia_processar.php?id=' . $id . '&msg=' . urlencode($message))));
      exit;
    }
  } catch (Throwable $e) {
    $erro = $e->getMessage();
    try { $preview = stock_conference_preview($pdo, $conferenceCompanyId, $id, $referenceAt); } catch (Throwable $ignored) {}
  }
}

$conference = stock_conference_get($pdo, $conferenceCompanyId, $id) ?: $conference;
$isProcessed = (string)$conference['status'] === 'PROCESSED';
if ($isProcessed) {
  $referenceAt = (string)($conference['reference_at'] ?? $referenceAt);
  try { $preview = stock_conference_preview($pdo, $conferenceCompanyId, $id, $referenceAt); } catch (Throwable $e) { $erro = $erro ?: $e->getMessage(); }
}

$itemCount = count(stock_conference_items($pdo, $conferenceCompanyId, $id));
$referenceInput = date('Y-m-d\TH:i', strtotime($referenceAt));

admin_topbar('Entradas - Processar Conferência de Estoque', 'estoque_conferencias');
?>
<style>
.scp-wrap{max-width:1280px;margin:0 auto;padding:22px}.scp-head{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:16px}.scp-head h1{margin:0;color:#0b1f5b}.scp-muted{color:#64748b;line-height:1.55}.scp-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;padding:20px;box-shadow:0 10px 30px rgba(15,23,42,.06);margin-bottom:16px}.scp-fields{display:grid;grid-template-columns:minmax(260px,430px) 1fr;gap:14px;align-items:end}.scp-field{display:flex;flex-direction:column;gap:7px}.scp-field label{font-weight:850;color:#334155}.scp-field input{padding:11px 12px;border:1px solid #cbd5e1;border-radius:12px}.scp-actions{display:flex;gap:9px;flex-wrap:wrap}.scp-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;cursor:pointer}.scp-btn.primary{background:#0b1f5b;color:#fff;border-color:#0b1f5b}.scp-btn.danger{background:#991b1b;color:#fff;border-color:#991b1b}.scp-btn[disabled]{opacity:.55;cursor:not-allowed}.scp-msg{padding:13px 15px;border-radius:14px;margin-bottom:14px}.scp-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.scp-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}.scp-alert{padding:13px 15px;border:1px solid #bfdbfe;border-radius:14px;background:#eff6ff;color:#1e3a8a;line-height:1.5}.scp-table{width:100%;border-collapse:collapse;min-width:900px}.scp-table th,.scp-table td{padding:12px 13px;border-bottom:1px solid #e2e8f0;text-align:left}.scp-table th{background:#f8fafc;color:#334155}.scp-scroll{overflow:auto;border:1px solid #e2e8f0;border-radius:15px}.scp-num{text-align:right!important;font-variant-numeric:tabular-nums}.scp-diff.positive{color:#047857;font-weight:900}.scp-diff.negative{color:#b91c1c;font-weight:900}.scp-diff.zero{color:#64748b;font-weight:800}.scp-confirm{display:flex;align-items:flex-start;gap:10px;padding:14px;border:1px solid #fecaca;border-radius:14px;background:#fff7f7;margin:15px 0}.scp-status{display:inline-flex;padding:6px 10px;border-radius:999px;background:#ecfdf5;color:#065f46;font-size:12px;font-weight:900}@media(max-width:760px){.scp-wrap{padding:14px}.scp-head{flex-direction:column}.scp-fields{grid-template-columns:1fr}}
</style>
<div class="scp-wrap">
  <?php if ($conferenceCrossCompany): ?><div class="scp-msg ok"><strong>V9.2 · Conferência Multiempresa — <?= stock_conference_h($conferenceCompanyName) ?></strong><br>A prévia e o processamento ajustarão somente os saldos e movimentos desta empresa.</div><?php endif; ?>
  <?php if ($msg !== ''): ?><div class="scp-msg ok"><?= stock_conference_h($msg) ?></div><?php endif; ?>
  <?php if ($erro !== ''): ?><div class="scp-msg err"><?= stock_conference_h($erro) ?></div><?php endif; ?>

  <div class="scp-head">
    <div>
      <h1>Conferência #<?= (int)$id ?> — <?= stock_conference_h($conference['name']) ?> · <?= stock_conference_h($conferenceCompanyName) ?></h1>
      <p class="scp-muted">Contagem registrada em <?= stock_conference_h(date('d/m/Y H:i', strtotime((string)$conference['counted_at']))) ?>, com <?= (int)$itemCount ?> produto(s).</p>
    </div>
    <div class="scp-actions"><a class="scp-btn" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . (int)$id) : ('estoque_conferencia_form.php?id=' . (int)$id)) ?>">Voltar à contagem</a><a class="scp-btn" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencias.php') : 'estoque_conferencias.php') ?>">Lista de conferências</a></div>
  </div>

  <?php if (!$isProcessed): ?>
  <section class="scp-card">
    <h2 style="margin-top:0;color:#0b1f5b">1. Escolha a data/hora de referência</h2>
    <p class="scp-muted">O sistema reconstrói o saldo que cada produto possuía nessa data. Assim, movimentações realizadas depois da contagem são preservadas no saldo atual.</p>
    <form method="post">
      <input type="hidden" name="id" value="<?= (int)$id ?>">
      <div class="scp-fields">
        <div class="scp-field"><label>Data/hora para calcular o estoque</label><input type="datetime-local" name="reference_at" required value="<?= stock_conference_h($referenceInput) ?>"></div>
        <div class="scp-actions"><button class="scp-btn primary" type="submit" name="action" value="preview" <?= $itemCount<=0?'disabled':'' ?>>Mostrar diferenças</button></div>
      </div>
    </form>
    <?php if ($itemCount <= 0): ?><p class="scp-alert" style="margin-bottom:0">Inclua ao menos um produto na conferência antes de processar.</p><?php endif; ?>
  </section>
  <?php else: ?>
  <div class="scp-alert" style="margin-bottom:16px"><span class="scp-status">Processada</span> Referência utilizada: <strong><?= stock_conference_h(date('d/m/Y H:i', strtotime($referenceAt))) ?></strong>. O resultado abaixo é o registro definitivo do processamento.</div>
  <?php endif; ?>

  <?php if (is_array($preview)): ?>
  <section class="scp-card">
    <h2 style="margin-top:0;color:#0b1f5b"><?= $isProcessed?'Resultado da conferência':'2. Confira as diferenças' ?></h2>
    <p class="scp-muted">Estoque do sistema calculado em <strong><?= stock_conference_h(date('d/m/Y H:i', strtotime((string)$preview['reference_at']))) ?></strong>.</p>
    <div class="scp-scroll">
      <table class="scp-table">
        <thead><tr><th>Código</th><th>Descrição</th><th>Un.</th><th class="scp-num">Estoque no sistema</th><th class="scp-num">Quantidade contada</th><th class="scp-num">Diferença</th></tr></thead>
        <tbody>
        <?php if (empty($preview['items'])): ?><tr><td colspan="6" class="scp-muted">Nenhum produto digitado.</td></tr><?php endif; ?>
        <?php foreach ((array)$preview['items'] as $item): $diff=(float)$item['difference_qty']; $diffClass=$diff>0.00005?'positive':($diff<-0.00005?'negative':'zero'); ?>
          <tr>
            <td><strong><?= stock_conference_h($item['product_code']) ?></strong></td>
            <td><?= stock_conference_h($item['nome']) ?></td>
            <td><?= stock_conference_h($item['unidade']) ?></td>
            <td class="scp-num"><?= stock_qty_br($item['system_qty_reference']) ?></td>
            <td class="scp-num"><strong><?= stock_qty_br($item['counted_qty']) ?></strong></td>
            <td class="scp-num scp-diff <?= $diffClass ?>"><?= $diff>0?'+':'' ?><?= stock_qty_br($diff) ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php if (!$isProcessed && !empty($preview['items'])): ?>
      <form method="post" onsubmit="return confirm('Confirma o processamento definitivo desta conferência e o ajuste dos saldos de estoque?')">
        <input type="hidden" name="id" value="<?= (int)$id ?>">
        <input type="hidden" name="reference_at" value="<?= stock_conference_h(date('Y-m-d\TH:i', strtotime((string)$preview['reference_at']))) ?>">
        <label class="scp-confirm"><input type="checkbox" name="confirm_process" value="1" required><span><strong>Confirmo o ajuste de estoque desta empresa.</strong><br><small class="scp-muted">Será aplicada a diferença encontrada ao saldo atual e gravado um movimento de auditoria por produto.</small></span></label>
        <div class="scp-actions"><button class="scp-btn danger" type="submit" name="action" value="process">Processar e acertar estoque</button><button class="scp-btn" type="submit" name="action" value="preview" formnovalidate>Recalcular prévia</button></div>
      </form>
    <?php endif; ?>
  </section>
  <?php endif; ?>
</div>
<?php admin_footer(); ?>
