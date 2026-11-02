<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';

// V11.49: no F11 esta tela opera explicitamente na empresa selecionada.
$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_inutilization_homologation')
  : (int)COMPANY_ID;
$isCrossF11 = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_inutilization_homologation');
$mcCsrf = $isCrossF11 && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
$ctxUrl = static function(string $url): string {
  return function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};

$err = '';
try {
  fiscal_nfce_ensure_schema($pdo);
  fiscal_inutilization_ensure_schema($pdo);
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $result = fiscal_inutilize_number_range($pdo, $cid, $_POST, function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : null);
    $label = ($result['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e';
    $message = $label . ' série ' . (int)$result['series'] . ', número(s) ' . (int)$result['start_number'] . ' a ' . (int)$result['end_number'] . ' inutilizado(s). Protocolo: ' . ((string)($result['protocol'] ?? '') ?: '-');
    $target = 'fiscal_inutilizacao.php?ok=' . rawurlencode($message);
    header('Location: ' . $ctxUrl($target));
    exit;
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
}

$seriesRows = [];
$currentEnvironment = 'HOMOLOGACAO';
$cfg = [];
try {
  $cfg = fiscal_company_config($pdo, $cid);
  $currentEnvironment = strtoupper((string)($cfg['environment'] ?? 'HOMOLOGACAO')) === 'PRODUCAO' ? 'PRODUCAO' : 'HOMOLOGACAO';
  if ($isCrossF11 && $currentEnvironment !== 'HOMOLOGACAO') {
    throw new RuntimeException('V11.49 Multiempresa: inutilização pelo F11 está liberada somente em HOMOLOGAÇÃO. Produção permanece bloqueada.');
  }
  $st = $pdo->prepare("SELECT model,series,environment,is_active,next_number,last_number FROM fiscal_document_series WHERE company_id=? AND model IN ('55','65') AND environment=? AND is_active=1 ORDER BY model,CAST(series AS UNSIGNED)");
  $st->execute([$cid, $currentEnvironment]);
  $seriesRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  $seriesRows = array_values(array_filter($seriesRows, static function(array $row) use ($cfg): bool {
    $model = (string)($row['model'] ?? '');
    if ($model === '55') return !empty($cfg['nfe_enabled']);
    if ($model === '65') return !empty($cfg['nfce_enabled']);
    return false;
  }));
} catch (Throwable $e) {
  $seriesRows = [];
  if ($err === '') $err = $e->getMessage();
}
$availableModels = array_values(array_unique(array_map(static fn(array $row): string => (string)($row['model'] ?? ''), $seriesRows)));
$defaultModel = in_array('65', $availableModels, true) ? '65' : (in_array('55', $availableModels, true) ? '55' : '65');

$recent = [];
try { $recent = fiscal_inutilization_rows($pdo, $cid, [], 40); } catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

fiscal_admin_start('Inutilização fiscal', 'fiscal_inutilizacao');
fiscal_page_header('Inutilização de NF-e / NFC-e', 'Inutilize um número isolado ou uma faixa que não foi utilizada. A solicitação é transmitida para a SEFAZ usando o certificado e o ambiente fiscal da empresa.', [
  ['label'=>'Documentos fiscais', 'href'=>$ctxUrl('fiscal_documentos.php'), 'class'=>'btn'],
  ['label'=>'Relatório de inutilizações', 'href'=>$ctxUrl('fiscal_relatorio_inutilizacoes.php'), 'class'=>'btn'],
  ['label'=>'Configurações fiscais', 'href'=>$ctxUrl('fiscal_configuracoes.php'), 'class'=>'btn'],
]);
fiscal_tabs('fiscal_inutilizacao');
fiscal_flash();
if ($isCrossF11) {
  echo '<div class="msg ok"><b>V11.49 · Inutilização Multiempresa em HOMOLOGAÇÃO.</b> Esta operação usa exclusivamente CNPJ, certificado, séries e configuração fiscal da empresa selecionada pelo F11. Produção permanece bloqueada.</div>';
}
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<style>
  .inut-warning{border:1px solid #fde68a;background:#fffbeb;color:#92400e;border-radius:18px;padding:13px 14px;margin-bottom:14px;line-height:1.5;font-weight:750}.inut-warning strong{color:#78350f}.inut-range{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px}.inut-status-ok{background:#ecfdf5;color:#047857;border-color:#a7f3d0}.inut-status-bad{background:#fff7ed;color:#9a3412;border-color:#fed7aa}@media(max-width:760px){.inut-range{grid-template-columns:1fr}}
</style>
<div class="inut-warning"><strong>Atenção:</strong> somente inutilize numerações que não foram autorizadas nem canceladas. Uma faixa homologada não pode voltar a ser utilizada. Para um único número, repita o mesmo valor em “Inicial” e “Final”.</div>

<div class="fiscal-card">
  <form method="post" action="<?= fiscal_h($ctxUrl('fiscal_inutilizacao.php')) ?>" class="fiscal-form" id="inutForm">
    <?php if ($isCrossF11): ?><input type="hidden" name="mc_csrf" value="<?= fiscal_h($mcCsrf) ?>"><?php endif; ?>
    <div class="fiscal-section">
      <h3>Documento e série</h3>
      <div class="fiscal-form-grid">
        <div><label>Tipo de documento</label><select name="model" id="inutModel" required><option value="65" <?= $defaultModel==='65'?'selected':'' ?>>NFC-e — modelo 65</option><option value="55" <?= $defaultModel==='55'?'selected':'' ?>>NF-e — modelo 55</option></select></div>
        <div><label>Série</label><select name="series" id="inutSeries" required><option value="">Selecione</option><?php foreach ($seriesRows as $s): ?><option value="<?= (int)$s['series'] ?>" data-model="<?= fiscal_h((string)$s['model']) ?>" data-env="<?= fiscal_h((string)$s['environment']) ?>"><?= fiscal_h(($s['model']==='55'?'NF-e':'NFC-e') . ' · Série ' . $s['series'] . ' · ' . $s['environment'] . ' · próxima ' . $s['next_number']) ?></option><?php endforeach; ?></select><div class="fiscal-muted">Serão exibidas apenas séries ativas e documentos habilitados no ambiente <b><?= fiscal_h($currentEnvironment) ?></b> da empresa atual.</div></div>
        <div><label>Ano fiscal</label><input name="fiscal_year" value="<?= fiscal_h(date('Y')) ?>" inputmode="numeric" maxlength="4" required><div class="fiscal-muted">Ano da numeração a inutilizar.</div></div>
      </div>
    </div>
    <div class="fiscal-section">
      <h3>Número ou faixa</h3>
      <div class="inut-range">
        <label>Número inicial<input type="number" min="1" max="999999999" name="start_number" required></label>
        <label>Número final<input type="number" min="1" max="999999999" name="end_number" required><span class="fiscal-muted">Para um único número, informe o mesmo valor.</span></label>
      </div>
    </div>
    <div class="fiscal-section">
      <h3>Justificativa</h3>
      <label>Motivo da inutilização<textarea name="justification" minlength="15" maxlength="255" required placeholder="Ex.: Quebra de sequência causada por falha no sistema durante a emissão."></textarea></label>
      <div class="fiscal-muted">A SEFAZ exige pelo menos 15 caracteres. Evite acentos ou símbolos incomuns caso o serviço estadual rejeite a mensagem.</div>
    </div>
    <div class="fiscal-form-actions"><a class="btn" href="<?= fiscal_h($ctxUrl('fiscal_documentos.php')) ?>">Cancelar</a><button class="btn btn-primary" type="submit" <?= !$seriesRows ? 'disabled' : '' ?>>Transmitir inutilização</button></div>
  </form>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head"><div><h2>Solicitações recentes</h2><div class="fiscal-muted">Últimas inutilizações enviadas pela empresa selecionada.</div></div><span class="fiscal-list-count"><?= count($recent) ?> registro(s)</span></div>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Data</th><th>Documento</th><th>Faixa</th><th>Ano/Ambiente</th><th>Status</th><th>Protocolo</th></tr></thead><tbody>
  <?php if (!$recent): ?><tr><td colspan="6"><div class="fiscal-empty-state">Nenhuma inutilização registrada.</div></td></tr><?php endif; ?>
  <?php foreach ($recent as $row): $status = strtoupper((string)($row['status'] ?? '')); ?>
    <tr>
      <td data-label="Data"><?= fiscal_h(fiscal_date_br(substr((string)($row['event_datetime'] ?? $row['created_at'] ?? ''),0,10))) ?></td>
      <td data-label="Documento" class="primary"><strong><?= ($row['model'] ?? '') === '55' ? 'NF-e' : 'NFC-e' ?></strong><div class="fiscal-muted">Série <?= (int)$row['series'] ?></div></td>
      <td data-label="Faixa"><strong><?= (int)$row['start_number'] ?> a <?= (int)$row['end_number'] ?></strong></td>
      <td data-label="Ano/Ambiente"><?= fiscal_h((string)$row['fiscal_year']) ?> · <?= fiscal_h((string)$row['environment']) ?></td>
      <td data-label="Status"><span class="fiscal-badge <?= $status==='HOMOLOGADA'?'inut-status-ok':'inut-status-bad' ?>"><?= fiscal_h($status ?: '-') ?></span><div class="fiscal-muted" style="margin-top:4px"><?= fiscal_h(trim((string)($row['status_code'] ?? '') . ' ' . (string)($row['status_message'] ?? ''))) ?></div></td>
      <td data-label="Protocolo"><?= fiscal_h((string)($row['protocol_number'] ?? '-')) ?></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<script>
(function(){
  const model = document.getElementById('inutModel');
  const series = document.getElementById('inutSeries');
  function filterSeries(){
    const wanted = model?.value || '65';
    let first = '';
    Array.from(series?.options || []).forEach((opt,idx)=>{
      if(idx===0) return;
      const show = opt.dataset.model === wanted;
      opt.hidden = !show;
      opt.disabled = !show;
      if(show && first==='') first=opt.value;
    });
    if(series && (!series.value || series.selectedOptions[0]?.disabled)) series.value=first;
  }
  model?.addEventListener('change',filterSeries); filterSeries();
  document.getElementById('inutForm')?.addEventListener('submit', function(ev){
    const start = Number(this.elements.start_number.value || 0);
    const end = Number(this.elements.end_number.value || 0);
    const label = model?.value === '55' ? 'NF-e' : 'NFC-e';
    if(!confirm(`Confirma a inutilização de ${label}, série ${series?.value || '-'}, número(s) ${start} a ${end}? Esta ação fiscal não pode ser desfeita.`)) ev.preventDefault();
  });
})();
</script>
<?php fiscal_admin_end(); ?>
