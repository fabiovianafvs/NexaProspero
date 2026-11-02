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
$msg = trim((string)($_GET['msg'] ?? ''));
$erro = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    if ((string)($_POST['action'] ?? '') === 'delete') {
      stock_conference_delete($pdo,$conferenceCompanyId,(int)($_POST['id'] ?? 0));
      header('Location: ' . (function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencias.php?msg=' . urlencode('Conferência excluída.')) : ('estoque_conferencias.php?msg=' . urlencode('Conferência excluída.'))));
      exit;
    }
  } catch (Throwable $e) { $erro = $e->getMessage(); }
}
$rows = stock_conference_list($pdo,$conferenceCompanyId);
admin_topbar('Entradas - Conferência de Estoque','estoque_conferencias');
?>
<style>
.sc-wrap{max-width:1200px;margin:0 auto;padding:22px}.sc-head{display:flex;justify-content:space-between;gap:15px;align-items:flex-start;margin-bottom:16px}.sc-head h1{margin:0;color:#0b1f5b}.sc-muted{color:#64748b;line-height:1.5}.sc-btn{display:inline-flex;align-items:center;justify-content:center;padding:10px 15px;border-radius:12px;border:1px solid #cbd5e1;background:#fff;color:#0f172a;text-decoration:none;font-weight:850;cursor:pointer}.sc-btn.primary{background:#0b1f5b;color:#fff;border-color:#0b1f5b}.sc-btn.danger{color:#991b1b;border-color:#fecaca;background:#fff}.sc-card{background:#fff;border:1px solid #e2e8f0;border-radius:20px;box-shadow:0 10px 30px rgba(15,23,42,.06);overflow:hidden}.sc-table{width:100%;border-collapse:collapse;min-width:850px}.sc-table th,.sc-table td{padding:13px 14px;border-bottom:1px solid #e2e8f0;text-align:left}.sc-table th{background:#f8fafc;color:#334155}.sc-scroll{overflow:auto}.sc-status{display:inline-flex;padding:6px 10px;border-radius:999px;font-size:12px;font-weight:900}.sc-status.draft{background:#fff7ed;color:#9a3412}.sc-status.processed{background:#ecfdf5;color:#065f46}.sc-actions{display:flex;gap:7px;flex-wrap:wrap}.sc-msg{padding:13px 15px;border-radius:14px;margin-bottom:14px}.sc-msg.ok{background:#ecfdf5;color:#065f46;border:1px solid #a7f3d0}.sc-msg.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca}@media(max-width:700px){.sc-wrap{padding:14px}.sc-head{flex-direction:column}}
</style>
<div class="sc-wrap">
  <?php if ($conferenceCrossCompany): ?><div class="sc-msg ok"><strong>V9.2 · Conferência Multiempresa — <?= stock_conference_h($conferenceCompanyName) ?></strong><br>A contagem, produtos e processamento desta tela estão isolados na empresa selecionada pelo F11.</div><?php endif; ?>
  <?php if ($msg !== ''): ?><div class="sc-msg ok"><?= stock_conference_h($msg) ?></div><?php endif; ?>
  <?php if ($erro !== ''): ?><div class="sc-msg err"><?= stock_conference_h($erro) ?></div><?php endif; ?>
  <div class="sc-head"><div><h1>Conferências de estoque · <?= stock_conference_h($conferenceCompanyName) ?></h1><p class="sc-muted">Digite a contagem sem visualizar o saldo do sistema. Depois confira as diferenças e processe o ajuste de estoque.</p></div><a class="sc-btn primary" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php') : 'estoque_conferencia_form.php') ?>">+ Adicionar conferência</a></div>
  <div class="sc-card sc-scroll"><table class="sc-table"><thead><tr><th>Número</th><th>Nome</th><th>Data/hora da contagem</th><th>Produtos</th><th>Status</th><th>Processamento</th><th>Ações</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="7" class="sc-muted">Nenhuma conferência cadastrada.</td></tr><?php endif; ?>
  <?php foreach ($rows as $row): $processed=(string)$row['status']==='PROCESSED'; ?>
    <tr>
      <td><strong>#<?= (int)$row['id'] ?></strong></td>
      <td><strong><?= stock_conference_h($row['name']) ?></strong><?php if (!empty($row['notes'])): ?><br><small class="sc-muted"><?= stock_conference_h($row['notes']) ?></small><?php endif; ?></td>
      <td><?= stock_conference_h(date('d/m/Y H:i',strtotime((string)$row['counted_at']))) ?></td>
      <td><?= (int)$row['item_count'] ?></td>
      <td><span class="sc-status <?= $processed?'processed':'draft' ?>"><?= $processed?'Processada':'Em digitação' ?></span></td>
      <td><?php if ($processed): ?>Referência: <?= stock_conference_h(date('d/m/Y H:i',strtotime((string)$row['reference_at']))) ?><br><small class="sc-muted">Executada em <?= stock_conference_h(date('d/m/Y H:i',strtotime((string)$row['processed_at']))) ?></small><?php else: ?>—<?php endif; ?></td>
      <td><div class="sc-actions">
        <a class="sc-btn" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_form.php?id=' . (int)$row['id']) : ('estoque_conferencia_form.php?id=' . (int)$row['id'])) ?>"><?= $processed?'Visualizar':'Continuar contagem' ?></a>
        <a class="sc-btn primary" href="<?= stock_conference_h(function_exists('multi_company_context_url') ? multi_company_context_url('estoque_conferencia_processar.php?id=' . (int)$row['id']) : ('estoque_conferencia_processar.php?id=' . (int)$row['id'])) ?>"><?= $processed?'Ver resultado':'Processar' ?></a>
        <?php if (!$processed): ?><form method="post" onsubmit="return confirm('Excluir esta conferência e toda a contagem digitada?')"><input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="<?= (int)$row['id'] ?>"><button class="sc-btn danger" type="submit">Excluir</button></form><?php endif; ?>
      </div></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php admin_footer(); ?>
