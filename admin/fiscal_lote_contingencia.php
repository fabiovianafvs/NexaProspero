<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
$q = trim((string)($_GET['q'] ?? ''));
$fromLogin = !empty($_GET['contingencia_login']);
$filter = strtoupper(trim((string)($_GET['status'] ?? ($fromLogin ? 'CONTINGENCIA' : 'TODAS'))));
if (!in_array($filter, ['TODAS','REJEITADAS','CONTINGENCIA'], true)) $filter = 'TODAS';
$pendingHint = max(0, (int)($_GET['pending'] ?? 0));
$err = '';
$rows = [];
try { fiscal_nfce_ensure_schema($pdo); $rows = fiscal_batch_documents($pdo, $cid, $filter, $q, 500); } catch (Throwable $e) { $err = $e->getMessage(); }
function lote_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function lote_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function lote_date($v): string { $ts = strtotime((string)$v); return $ts ? date('d/m/Y H:i', $ts) : '-'; }
function lote_emission_label($value): string {
  $v = strtoupper(trim((string)$value));
  return ['NFC_OFFLINE'=>'NFC-e offline','OFFLINE'=>'NFC-e offline','SVCAN'=>'SVC-AN','SVCRS'=>'SVC-RS','FS_DA'=>'FS-DA'][$v] ?? ($v !== '' ? $v : 'Contingência');
}

function lote_status_label($value): string {
  $v = strtoupper(trim((string)$value));
  if ($v === 'REJEITADA') return 'Rejeitada';
  if (in_array($v, ['CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE','FS_DA'], true)) return 'Contingência';
  return $v !== '' ? $v : '-';
}
function lote_status_class($value): string {
  return strtoupper(trim((string)$value)) === 'REJEITADA' ? 'rejected' : 'contingency';
}

fiscal_admin_start('Reprocessamento fiscal', 'fiscal_documentos');
fiscal_page_header('Reprocessamento fiscal', 'Reprocesse NF-e/NFC-e rejeitadas e transmita documentos em contingência. Para novos pedidos use Emissão em lote.', [
  ['label'=>'Documentos fiscais', 'href'=>'fiscal_documentos.php', 'class'=>'btn'],
  ['label'=>'Configurar contingência', 'href'=>'fiscal_configuracoes.php?edit_config=1', 'class'=>'btn'],
]);
fiscal_tabs('fiscal_lote_contingencia');
fiscal_flash();
if ($err !== '') echo '<div class="msg err">' . lote_h($err) . '</div>';
?>
<style>
  .lote-table{width:100%;border-collapse:collapse;min-width:980px}.lote-table th,.lote-table td{padding:10px;border-bottom:1px solid #e5edf6;vertical-align:middle}.lote-table th{background:#f8fbff;color:#334155;font-size:12px}.lote-key{display:block;max-width:300px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;color:#64748b;font-size:12px}.lote-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:12px;flex-wrap:wrap}.lote-check{width:20px;height:20px}.lote-alert{border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:20px;padding:16px;margin-bottom:14px;display:flex;gap:14px;align-items:flex-start;justify-content:space-between;flex-wrap:wrap}.lote-alert strong{display:block;font-size:17px;margin-bottom:4px}.lote-note{font-size:12px;color:#64748b;line-height:1.45}.lote-kind{display:inline-flex;border:1px solid #fed7aa;background:#fff7ed;color:#9a3412;padding:4px 8px;border-radius:999px;font-weight:900;font-size:11px}.lote-status{display:inline-flex;padding:4px 9px;border-radius:999px;font-weight:950;font-size:11px}.lote-status.rejected{border:1px solid #fecaca;background:#fef2f2;color:#b91c1c}.lote-status.contingency{border:1px solid #fde68a;background:#fffbeb;color:#92400e}.lote-filter-row{display:grid;grid-template-columns:minmax(280px,1fr) 190px auto auto;gap:9px;align-items:end}.lote-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.lote-toolbar .btn{min-height:38px}@media(max-width:850px){.lote-filter-row{grid-template-columns:1fr 1fr}.lote-filter-row .btn{width:100%}}
</style>
<?php if ($fromLogin && ($pendingHint > 0 || count($rows) > 0)): ?>
  <div class="lote-alert">
    <div><strong>Atenção: existem documentos emitidos em contingência</strong><div>O Nexa direcionou você automaticamente para esta tela. Confira o retorno da SEFAZ e transmita os documentos pendentes quando o serviço estiver disponível.</div></div>
    <span class="fiscal-list-count"><?= max($pendingHint, count($rows)) ?> pendente(s)</span>
  </div>
<?php endif; ?>
<div class="fiscal-card">
  <form method="get" class="fiscal-form">
    <div class="lote-filter-row">
      <label>Pesquisar por número, pedido, chave, natureza ou retorno SEFAZ<input name="q" value="<?= lote_h($q) ?>" placeholder="Ex.: nº, chave, pedido ou rejeição"></label>
      <label>Situação<select name="status"><option value="TODAS" <?= $filter==='TODAS'?'selected':'' ?>>Todas</option><option value="REJEITADAS" <?= $filter==='REJEITADAS'?'selected':'' ?>>Rejeitadas</option><option value="CONTINGENCIA" <?= $filter==='CONTINGENCIA'?'selected':'' ?>>Contingência</option></select></label>
      <button class="btn btn-primary" type="submit">Pesquisar</button>
      <a class="btn" href="fiscal_lote_contingencia.php">Limpar</a>
    </div>
  </form>
</div>
<form method="post" action="fiscal_nfce_acao.php" id="loteForm">
  <input type="hidden" name="action" value="processar_lote_documentos">
  <input type="hidden" name="batch_filter" value="<?= lote_h($filter) ?>">
  <input type="hidden" name="batch_q" value="<?= lote_h($q) ?>">
  <input type="hidden" name="return_to" value="<?= lote_h('fiscal_lote_contingencia.php?status=' . rawurlencode($filter) . ($q !== '' ? '&q=' . rawurlencode($q) : '')) ?>">
  <div class="fiscal-card">
    <div class="fiscal-list-head"><div><h2 style="margin-bottom:4px">Documentos disponíveis para processamento em lote</h2><div class="lote-note">Rejeitadas são regeneradas com as regras fiscais atuais antes do reenvio. Contingência mantém o tratamento próprio de transmissão. Documentos autorizados ou com retorno inconclusivo não entram nesta lista.</div></div><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
    <div class="lote-toolbar" style="margin:8px 0 12px"><button class="btn" type="button" id="markAll">Marcar todos</button><button class="btn" type="button" id="unmarkAll">Desmarcar</button><span class="lote-note" id="selectedCount">0 selecionada(s)</span></div>
    <div class="table-wrap"><table class="lote-table">
      <thead><tr><th style="width:42px"><input type="checkbox" id="checkAll" title="Selecionar todos os registros exibidos"></th><th>Documento</th><th>Situação</th><th>Origem</th><th>Operação / emissão fiscal</th><th>Total</th><th>Tentativas</th><th>Último retorno SEFAZ</th></tr></thead>
      <tbody>
        <?php if (!$rows): ?><tr><td colspan="8"><div class="fiscal-empty-state">Nenhuma nota rejeitada ou em contingência encontrada para o filtro informado.</div></td></tr><?php endif; ?>
        <?php foreach ($rows as $r): $label=((string)($r['model'] ?? '')==='55')?'NF-e':'NFC-e'; ?>
          <tr>
            <td><input class="lote-check" type="checkbox" name="doc_ids[]" value="<?= (int)$r['id'] ?>"></td>
            <td><b><?= lote_h($label . ' ' . ($r['series'] ?? '') . '/' . ($r['number'] ?? '')) ?></b><span class="lote-key" title="<?= lote_h($r['access_key'] ?? '') ?>"><?= lote_h($r['access_key'] ?? '') ?></span></td>
            <td><span class="lote-status <?= lote_h(lote_status_class($r['status'] ?? '')) ?>"><?= lote_h(lote_status_label($r['status'] ?? '')) ?></span><div class="lote-note"><?= lote_h(($r['sefaz_status_code'] ?? '') !== '' ? ('SEFAZ ' . $r['sefaz_status_code']) : '') ?></div></td>
            <td><?= lote_h(($r['source_type'] ?? '-') . ' #' . ($r['source_id'] ?? '-')) ?></td>
            <td><span class="lote-kind"><?= lote_h(lote_emission_label($r['emission_type'] ?? 'NORMAL')) ?></span><div class="lote-note"><b>Operação:</b> <?= lote_h(lote_date($r['operation_datetime'] ?? $r['issue_datetime'] ?? '')) ?></div><?php if (!empty($r['operation_datetime']) && !empty($r['issue_datetime']) && abs((strtotime((string)$r['operation_datetime']) ?: 0) - (strtotime((string)$r['issue_datetime']) ?: 0)) > 60): ?><div class="lote-note"><b>Emissão fiscal:</b> <?= lote_h(lote_date($r['issue_datetime'])) ?></div><?php endif; ?><?php if (!empty($r['contingency_started_at'])): ?><div class="lote-note">Início conting.: <?= lote_h(lote_date($r['contingency_started_at'])) ?></div><?php endif; ?></td>
            <td><b><?= lote_money($r['total_document'] ?? 0) ?></b></td>
            <td><?= (int)($r['contingency_transmission_attempts'] ?? 0) ?><div class="lote-note"><?= lote_h(lote_date($r['contingency_last_attempt_at'] ?? '')) ?></div></td>
            <td><div class="lote-note" style="font-size:12px;color:#334155"><?= lote_h(trim((string)($r['sefaz_status_code'] ?? '') . ' - ' . (string)($r['sefaz_status_message'] ?? '')) ?: '-') ?></div></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table></div>
    <div class="lote-actions"><a class="btn" href="fiscal_documentos.php">Voltar</a><button class="btn btn-primary" type="submit" <?= !$rows ? 'disabled' : '' ?>>Processar selecionadas</button></div>
  </div>
</form>
<script>
  (function(){
    var all=document.getElementById('checkAll');
    var checks=function(){ return Array.prototype.slice.call(document.querySelectorAll('.lote-check')); };
    var countEl=document.getElementById('selectedCount');
    function refresh(){
      var list=checks(), selected=list.filter(function(c){ return c.checked; }).length;
      if(countEl) countEl.textContent=selected+' selecionada(s)';
      if(all){ all.checked=list.length>0 && selected===list.length; all.indeterminate=selected>0 && selected<list.length; }
    }
    if(all){ all.addEventListener('change', function(){ checks().forEach(function(c){ c.checked=all.checked; }); refresh(); }); }
    checks().forEach(function(c){ c.addEventListener('change',refresh); });
    var mark=document.getElementById('markAll'), unmark=document.getElementById('unmarkAll');
    if(mark) mark.addEventListener('click',function(){ checks().forEach(function(c){c.checked=true;}); refresh(); });
    if(unmark) unmark.addEventListener('click',function(){ checks().forEach(function(c){c.checked=false;}); refresh(); });
    var form=document.getElementById('loteForm');
    if(form){ form.addEventListener('submit', function(ev){
      var selected=checks().filter(function(c){return c.checked;}).length;
      if(!selected){ ev.preventDefault(); alert('Selecione ao menos uma nota rejeitada ou em contingência.'); return; }
      if(!confirm('Processar '+selected+' documento(s) selecionado(s) agora? Notas rejeitadas serão regeneradas com as regras fiscais atuais antes do novo envio.')) ev.preventDefault();
    }); }
    refresh();
  })();
</script>
<?php fiscal_admin_end(); ?>
