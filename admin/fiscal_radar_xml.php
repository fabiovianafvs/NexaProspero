<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/ia_entrada_notas.php';

$cid = fiscal_v10_entry_company_id($pdo);
$entryCrossContext = fiscal_v10_entry_is_cross_context($pdo);
$entryCompanyName = fiscal_v10_entry_company_name($pdo, $cid);
$err = '';
try { fiscal_xml_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }
try { if (function_exists('fiscal_ensure_dfe_distribution_schema')) fiscal_ensure_dfe_distribution_schema($pdo); } catch (Throwable $e) { $err = trim($err . ' ' . $e->getMessage()); }

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($entryCrossContext && in_array($action, ['buscar_dfe','manifest_dfe','fetch_dfe_key'], true)) {
      throw new RuntimeException('V10 Multiempresa: busca/manifestação DF-e na SEFAZ ainda não foi liberada pelo F11. Use o acesso direto da empresa para esta operação fiscal externa.');
    }
    if ($action === 'buscar_dfe') {
      $force = !empty($_POST['force']);
      $res = fiscal_dfe_download_from_sefaz($pdo, $cid, $force);
      $msg = 'Busca concluída pelo ' . (string)($res['provider'] ?? 'provedor configurado')
        . '. Documentos localizados: ' . (int)($res['documents_found'] ?? 0)
        . '. XMLs completos: ' . (int)($res['xmls_stored'] ?? 0)
        . '. Resumos: ' . (int)($res['summaries_stored'] ?? 0)
        . '. NSU: ' . (string)($res['last_nsu'] ?? '0') . '/' . (string)($res['max_nsu'] ?? '0') . '.';
      // Agente de IA no modo automático: já prepara as primeiras notas novas.
      try {
        $iaCfgAuto = ia_config($pdo, $cid);
        if ($iaCfgAuto['enabled'] && $iaCfgAuto['entrada']['enabled'] && $iaCfgAuto['entrada']['mode'] === 'auto' && ia_available($pdo, $cid, $iaCfgAuto)) {
          @set_time_limit(300);
          $iaDone = 0;
          foreach (ia_entrada_pending_radar($pdo, $cid, $iaCfgAuto['entrada']['since'] ?: null, 3) as $iaRid) { ia_entrada_process_radar($pdo, $cid, $iaRid, 'auto', auth_user_id()); $iaDone++; }
          if ($iaDone) $msg .= ' Agente de IA preparou ' . $iaDone . ' nota(s); as demais serão preparadas na próxima busca automática do Radar.';
        }
      } catch (Throwable $e) {}
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_radar_xml.php?ok=' . rawurlencode($msg)));
      exit;
    }
    if ($action === 'manifest_dfe') {
      $radarId = (int)($_POST['radar_id'] ?? 0);
      $eventType = (int)($_POST['event_type'] ?? 0);
      $justification = trim((string)($_POST['justification'] ?? ''));
      $res = fiscal_dfe_manifest($pdo, $cid, $radarId, $eventType, $justification);
      $fetch = (array)($res['fetch'] ?? []);
      $msg = 'Manifestação registrada: ' . (string)($res['manifest_status'] ?? '')
        . '. ' . trim((string)($res['manifest_code'] ?? '') . ' ' . (string)($res['manifest_message'] ?? ''))
        . '. XMLs completos recuperados: ' . (int)($fetch['xmls_stored'] ?? 0) . '.';
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_radar_xml.php?ok=' . rawurlencode($msg)));
      exit;
    }
    if ($action === 'fetch_dfe_key') {
      $key = trim((string)($_POST['access_key'] ?? ''));
      $res = fiscal_dfe_fetch_by_key($pdo, $cid, $key);
      $msg = 'Consulta por chave concluída. XMLs completos: ' . (int)($res['xmls_stored'] ?? 0)
        . '. Resumos/eventos: ' . (int)($res['summaries_stored'] ?? 0)
        . '. Retorno: ' . trim((string)($res['status_code'] ?? '') . ' ' . (string)($res['status_message'] ?? '')) . '.';
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_radar_xml.php?ok=' . rawurlencode($msg)));
      exit;
    }
    if ($action === 'ia_preparar') {
      // Agente de IA: só na empresa de login (o F11 não usa agentes).
      if ($entryCrossContext) throw new RuntimeException('O agente de IA só prepara notas da empresa de login.');
      $iaReason = ia_unavailable_reason($pdo, $cid);
      if ($iaReason !== '') throw new RuntimeException($iaReason);
      if (!ia_config($pdo, $cid)['entrada']['enabled']) throw new RuntimeException('Ligue o agente de entrada de notas em Configurações > Agentes de IA.');
      @set_time_limit(300);
      $run = ia_entrada_process_radar($pdo, $cid, (int)($_POST['radar_id'] ?? 0), 'manual', auth_user_id());
      if (($run['status'] ?? '') === 'ERRO') throw new RuntimeException('O agente não conseguiu preparar a nota: ' . (string)($run['error_message'] ?? ''));
      $xmlIdRun = (int)($run['xml_import_id'] ?? 0);
      $target = $xmlIdRun > 0 ? 'fiscal_xml_entrada.php?id=' . $xmlIdRun . '&ok=' . rawurlencode('Nota preparada pelo agente de IA: ' . (string)($run['summary'] ?? '')) . '#iaEntrada' : 'fiscal_radar_xml.php?ok=' . rawurlencode((string)($run['summary'] ?? 'Nota analisada.'));
      header('Location: ' . fiscal_v10_entry_context_url($target));
      exit;
    }
    if ($action === 'preparar_entrada_xml') {
      $radarId = (int)($_POST['radar_id'] ?? 0);
      $radar = function_exists('fiscal_radar_one') ? fiscal_radar_one($pdo, $cid, $radarId) : null;
      if (!$radar) throw new RuntimeException('Documento da busca DF-e não encontrado.');
      $xml = trim((string)($radar['xml_raw'] ?? ''));
      $kind = strtoupper((string)($radar['dfe_kind'] ?? ''));
      if ($xml === '' || in_array($kind, ['RESNFE','RESEVENTO','EVENTO'], true) || stripos($xml, '<NFe') === false) {
        throw new RuntimeException('Esta nota ainda não possui XML completo disponível. Faça a manifestação/recuperação quando necessário e tente novamente.');
      }
      $tmp = tempnam(sys_get_temp_dir(), 'dfe_xml_');
      if ($tmp === false) throw new RuntimeException('Não foi possível preparar o XML temporário.');
      try {
        file_put_contents($tmp, $xml);
        $fileName = (string)($radar['file_name'] ?? ('sefaz_' . ($radar['access_key'] ?? $radarId) . '.xml'));
        $data = fiscal_xml_parse_file($tmp, $fileName);
        fiscal_v10_assert_xml_recipient($pdo, $cid, $data);
        $xmlId = fiscal_xml_import_data($pdo, $cid, $data);
        try { $pdo->prepare("UPDATE fiscal_xml_radar SET radar_status='ANALISADO', updated_at=NOW() WHERE company_id=? AND id=?")->execute([$cid, $radarId]); } catch (Throwable $e) {}
      } finally {
        @unlink($tmp);
      }
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$xmlId . '&ok=' . rawurlencode('XML recebido pela busca DF-e. Confira os dados, vincule produtos, ajuste conversões e gere a entrada.') . '#itensXml'));
      exit;
    }
  }
} catch (Throwable $e) { $err = trim($err . ' ' . $e->getMessage()); }

$q = trim((string)($_GET['q'] ?? ''));
$dfeStatus = trim((string)($_GET['dfe_status'] ?? ''));
$defaultIni = date('Y-m-d', strtotime('-9 days'));
$defaultFim = date('Y-m-d');
$ini = fiscal_xml_date($_GET['ini'] ?? '') ?: $defaultIni;
$fim = fiscal_xml_date($_GET['fim'] ?? '') ?: $defaultFim;

$state = [];
$logs = [];
try {
  if (function_exists('fiscal_dfe_state')) {
    $state = fiscal_dfe_state($pdo, $cid);
    $logs = fiscal_dfe_recent_logs($pdo, $cid, 6);
  }
} catch (Throwable $e) {}

$dfeRows = [];
try {
  if (function_exists('fiscal_table_exists') && fiscal_table_exists($pdo, 'fiscal_xml_radar')) {
    $where = ["r.company_id=?", "r.direction='ENTRADA'", "r.model='55'"];
    $params = [$cid];
    if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'fiscal_xml_radar', 'dfe_kind')) {
      $where[] = "(r.dfe_kind IS NULL OR r.dfe_kind='' OR r.dfe_kind IN ('RESNFE','PROCNFE','NFE'))";
    }
    if ($dfeStatus !== '') { $where[] = 'r.radar_status=?'; $params[] = $dfeStatus; }
    if ($ini !== '') { $where[] = 'DATE(r.issue_datetime)>=?'; $params[] = $ini; }
    if ($fim !== '') { $where[] = 'DATE(r.issue_datetime)<=?'; $params[] = $fim; }
    if ($q !== '') {
      $like = nexa_search_like($q);
      if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo, 'fiscal_xml_radar', 'dfe_nsu')) {
        $where[] = '(r.access_key LIKE ? OR CAST(r.document_number AS CHAR) LIKE ? OR r.issuer_name LIKE ? OR r.issuer_document LIKE ? OR r.file_name LIKE ? OR r.dfe_nsu LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like, $like);
      } else {
        $where[] = '(r.access_key LIKE ? OR CAST(r.document_number AS CHAR) LIKE ? OR r.issuer_name LIKE ? OR r.issuer_document LIKE ? OR r.file_name LIKE ?)';
        array_push($params, $like, $like, $like, $like, $like);
      }
    }
    $st = $pdo->prepare('SELECT r.* FROM fiscal_xml_radar r WHERE ' . implode(' AND ', $where) . ' ORDER BY COALESCE(r.issue_datetime,r.created_at) DESC, r.id DESC LIMIT 300');
    $st->execute($params);
    $dfeRows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
} catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

// Agente de IA: situação de cada nota e se o botão aparece.
$iaOn = false;
$iaRuns = [];
try {
  if (!$entryCrossContext && ia_ready($pdo) && ia_module_enabled($pdo, $cid)) {
    $iaCfg = ia_config($pdo, $cid);
    $iaOn = $iaCfg['enabled'] && $iaCfg['entrada']['enabled'];
    $radarIds = array_map(static fn($r) => (int)$r['id'], $dfeRows);
    if ($radarIds) {
      $stIa = $pdo->prepare("SELECT ref_id, status, summary, xml_import_id FROM ai_agent_runs WHERE company_id=? AND agent='entrada_notas' AND ref_type='radar' AND ref_id IN (" . implode(',', array_fill(0, count($radarIds), '?')) . ')');
      $stIa->execute(array_merge([$cid], $radarIds));
      foreach ($stIa->fetchAll(PDO::FETCH_ASSOC) ?: [] as $iaRow) $iaRuns[(int)$iaRow['ref_id']] = $iaRow;
    }
  }
} catch (Throwable $e) {}

$dfeKpis = ['total'=>count($dfeRows), 'novos'=>0, 'completos'=>0, 'valor'=>0.0];
foreach ($dfeRows as $row) {
  if (in_array((string)($row['radar_status'] ?? ''), ['NOVO','ANALISADO'], true)) $dfeKpis['novos']++;
  $kind = strtoupper((string)($row['dfe_kind'] ?? ''));
  if (in_array($kind, ['PROCNFE','NFE'], true)) $dfeKpis['completos']++;
  $dfeKpis['valor'] += (float)($row['total_document'] ?? 0);
}

fiscal_admin_start('RADAR NF-e' . ($entryCrossContext ? ' · ' . $entryCompanyName : ''), 'fiscal_radar_xml');
fiscal_flash();
if ($entryCrossContext) echo '<div class="msg ok"><strong>V10 Multiempresa:</strong> RADAR em modo consulta para <strong>' . fiscal_h($entryCompanyName) . '</strong>. É permitido preparar uma entrada a partir de XML completo já armazenado, mas busca, manifestação e consulta por chave na SEFAZ ficam bloqueadas pelo F11 nesta etapa.</div>';
if ($err) echo '<div class="msg err">' . fiscal_h(trim($err)) . '</div>';
?>
<style>
  .radar-sync-card{display:flex;justify-content:space-between;align-items:center;gap:14px;flex-wrap:wrap}
  .radar-sync-actions{display:flex;gap:8px;align-items:center}
  .radar-filter-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin:10px 0 14px;box-shadow:0 12px 30px rgba(15,23,42,.04)}
  .radar-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
  .radar-filters label{margin:0;font-size:12px;font-weight:800;color:#334155;flex:1 1 145px;min-width:135px}
  .radar-filters .radar-search{flex:2 1 300px;min-width:240px}
  .radar-filters input,.radar-filters select{min-height:42px;width:100%;margin-top:6px;border-radius:14px;box-sizing:border-box}
  .radar-filter-actions{display:flex;gap:10px;align-items:end;margin-left:auto}
  .radar-filter-actions .btn{min-height:42px;min-width:86px;display:inline-flex;align-items:center;justify-content:center}
  .radar-dfe-wrap{position:relative;overflow:auto}
  .radar-dfe-record-row td{border-bottom:0}
  .radar-dfe-action-row td{padding:0 12px 14px!important;border-top:0;border-bottom:1px solid #e5edf6}
  .radar-dfe-action-panel{display:flex;align-items:center;justify-content:space-between;gap:14px;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:9px 10px;box-sizing:border-box}
  .radar-dfe-action-title{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;flex:1 1 240px;min-width:220px}
  .radar-dfe-action-buttons{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;width:auto;margin-left:auto}
  .radar-dfe-action-buttons form{display:inline-flex;margin:0}.radar-dfe-action-buttons .btn{white-space:nowrap}
  .radar-ia-btn{background:#f5f3ff!important;border-color:#c4b5fd!important;color:#5b21b6!important;font-weight:800}
  .radar-ia-chip{display:inline-block;margin-top:4px;padding:2px 8px;border-radius:999px;font-size:11px;font-weight:800;background:#ede9fe;color:#5b21b6}.radar-ia-chip.ok{background:#dcfce7;color:#166534}.radar-ia-chip.warn{background:#fef3c7;color:#92400e}.radar-ia-chip.err{background:#fee2e2;color:#991b1b}
  @media(max-width:900px){.radar-filters{display:grid;grid-template-columns:1fr}.radar-filters label,.radar-filters .radar-search{min-width:0;width:100%}.radar-filter-actions{display:grid;grid-template-columns:1fr 1fr;width:100%;margin-left:0}.radar-filter-actions .btn{width:100%}.radar-dfe-action-panel{align-items:stretch;flex-direction:column}.radar-dfe-action-title{width:100%;min-width:0}.radar-dfe-action-buttons{width:100%;margin-left:0}.radar-dfe-action-buttons .btn{flex:1 1 160px;justify-content:center}}
</style>
<script>
function radarSolicitarJustificativa(form) {
  const texto = window.prompt('Informe a justificativa da operação não realizada (mínimo de 15 caracteres):');
  if (texto === null) return false;
  const justificativa = texto.trim();
  if (justificativa.length < 15) { window.alert('A justificativa deve possuir no mínimo 15 caracteres.'); return false; }
  const campo = form.querySelector('input[name="justification"]');
  if (!campo) return false;
  campo.value = justificativa.slice(0, 255);
  return window.confirm('Registrar a manifestação de operação não realizada?');
}
</script>

<div class="fiscal-card radar-sync-card">
  <div>
    <h2 style="margin:0 0 5px">Sincronização com o Ambiente Nacional</h2>
    <div class="fiscal-muted">
      Provedor: <b><?= fiscal_h(($state['provider'] ?? 'NFEPHP_DIRECT') === 'NFEPHP_DIRECT' ? 'NFePHP direto' : ($state['provider'] ?? 'ACBrMonitor')) ?></b>
      · CNPJ: <b><?= fiscal_h($state['cnpj'] ?? '-') ?></b>
      · NSU: <b><?= fiscal_h(($state['last_nsu'] ?? '0') . ' / ' . ($state['max_nsu'] ?? '0')) ?></b>
      · Próxima busca: <b><?= fiscal_h(($state['next_allowed_at'] ?? '') ?: 'agora') ?></b>
    </div>
  </div>
  <?php if (!$entryCrossContext): ?>
  <form method="post" class="radar-sync-actions">
    <input type="hidden" name="action" value="buscar_dfe">
    <button class="btn btn-primary" type="submit">Buscar NF-e agora</button>
  </form>
  <?php else: ?>
  <div class="radar-sync-actions"><span class="fiscal-badge off">SEFAZ bloqueada no F11 · V10</span></div>
  <?php endif; ?>
</div>

<div class="fiscal-grid-4">
  <div class="fiscal-kpi"><span>NF-e no período</span><strong><?= (int)$dfeKpis['total'] ?></strong></div>
  <div class="fiscal-kpi"><span>Novas/analisadas</span><strong><?= (int)$dfeKpis['novos'] ?></strong></div>
  <div class="fiscal-kpi"><span>XML completo</span><strong><?= (int)$dfeKpis['completos'] ?></strong></div>
  <div class="fiscal-kpi"><span>Valor no período</span><strong>R$ <?= number_format((float)$dfeKpis['valor'], 2, ',', '.') ?></strong></div>
</div>

<div class="radar-filter-card">
  <form method="get" class="fiscal-form">
    <div class="radar-filters">
      <label class="radar-search">Fornecedor, número, chave ou NSU
        <input type="search" name="q" value="<?= fiscal_h($q) ?>" placeholder="Fornecedor, CNPJ, número, chave ou NSU">
      </label>
      <label>Status DF-e
        <select name="dfe_status">
          <option value="">Todos</option>
          <option value="NOVO" <?= $dfeStatus==='NOVO'?'selected':'' ?>>Novo</option>
          <option value="ANALISADO" <?= $dfeStatus==='ANALISADO'?'selected':'' ?>>Analisado</option>
          <option value="IMPORTADO" <?= $dfeStatus==='IMPORTADO'?'selected':'' ?>>Importado</option>
          <option value="ERRO" <?= $dfeStatus==='ERRO'?'selected':'' ?>>Erro</option>
        </select>
      </label>
      <label>Emissão de
        <input type="date" name="ini" value="<?= fiscal_h($ini) ?>">
      </label>
      <label>Emissão até
        <input type="date" name="fim" value="<?= fiscal_h($fim) ?>">
      </label>
      <div class="radar-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_radar_xml.php')) ?>">Limpar</a></div>
    </div>
  </form>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>NF-e emitidas para o CNPJ</h2><span class="fiscal-list-count"><?= count($dfeRows) ?> registro(s)</span></div>
  <?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="table-wrap radar-dfe-wrap"><table class="fiscal-table radar-dfe-table"<?=nexa_grid_auto_attrs($pdo,'auto_fiscal_radar')?>><thead><tr><th>Documento</th><th>Fornecedor</th><th>Origem</th><th>Emissão</th><th>Total</th><th>Status</th></tr></thead><tbody>
  <?php if (!$dfeRows): ?><tr><td colspan="6" style="text-align:center;color:#64748b">Nenhuma NF-e localizada no período informado.</td></tr><?php endif; ?>
  <?php foreach ($dfeRows as $x): $kind = strtoupper((string)($x['dfe_kind'] ?? '')); $hasXml = trim((string)($x['xml_raw'] ?? '')) !== '' && !in_array($kind, ['RESNFE','RESEVENTO','EVENTO'], true) && stripos((string)($x['xml_raw'] ?? ''), '<NFe') !== false; ?>
    <tr class="radar-dfe-record-row">
      <td data-label="Documento" class="primary"><strong>Modelo <?= fiscal_h($x['model'] ?? '') ?> Série <?= fiscal_h($x['series'] ?? '') ?> / Nº <?= fiscal_h($x['document_number'] ?? '') ?></strong><div class="fiscal-muted" style="font-size:12px"><?= fiscal_h($x['access_key'] ?? '') ?></div></td>
      <td data-label="Fornecedor"><strong><?= fiscal_h($x['issuer_name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($x['issuer_document'] ?? '') ?></div></td>
      <td data-label="Origem"><?= fiscal_h($x['source'] ?? '') ?><?php if (!empty($x['dfe_nsu'])): ?><div class="fiscal-muted">NSU <?= fiscal_h($x['dfe_nsu']) ?></div><?php endif; ?></td>
      <td data-label="Emissão"><?= fiscal_h(fiscal_date_br(substr((string)($x['issue_datetime'] ?? ''),0,10))) ?></td>
      <td data-label="Total"><strong>R$ <?= number_format((float)($x['total_document'] ?? 0), 2, ',', '.') ?></strong></td>
      <td data-label="Status"><span class="fiscal-badge <?= ($x['radar_status'] ?? '') === 'IMPORTADO' ? '' : 'off' ?>"><?= fiscal_h($x['radar_status'] ?? '') ?></span><?php $iaRun = $iaRuns[(int)$x['id']] ?? null; if ($iaRun): [$iaLbl, $iaCls] = match ((string)$iaRun['status']) { 'PRONTA' => ['IA: pronta para conferir', 'ok'], 'REVISAR' => ['IA: conferir itens', 'warn'], 'PROCESSANDO' => ['IA: preparando…', ''], 'IGNORADA' => ['IA: já lançada', ''], default => ['IA: erro', 'err'] }; ?><div><span class="radar-ia-chip <?= $iaCls ?>" title="<?= fiscal_h($iaRun['summary'] ?? '') ?>"><?= fiscal_h($iaLbl) ?></span></div><?php endif; ?><?php if ($kind): ?><div class="fiscal-muted"><?= fiscal_h($kind) ?></div><?php endif; ?><?php if (!empty($x['manifest_status'])): ?><div class="fiscal-muted">Manifestação: <?= fiscal_h($x['manifest_status']) ?></div><?php endif; ?></td>
    </tr>
    <tr class="radar-dfe-action-row">
      <td colspan="6">
        <div class="radar-dfe-action-panel">
          <span class="radar-dfe-action-title">Ações da NF-e modelo <?= fiscal_h($x['model'] ?? '') ?> série <?= fiscal_h($x['series'] ?? '') ?> / nº <?= fiscal_h($x['document_number'] ?? '') ?></span>
          <div class="radar-dfe-action-buttons">
            <?php if ($hasXml): ?>
              <?php if ($iaOn && !isset($iaRuns[(int)$x['id']])): ?><form method="post" onsubmit="const b=this.querySelector('button');b.disabled=true;b.textContent='Preparando com IA…'"><input type="hidden" name="action" value="ia_preparar"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-sm radar-ia-btn">✨ Preparar com IA</button></form><?php elseif ($iaOn && (int)($iaRuns[(int)$x['id']]['xml_import_id'] ?? 0) > 0): ?><a class="btn btn-sm radar-ia-btn" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$iaRuns[(int)$x['id']]['xml_import_id'] . '#iaEntrada')) ?>">✨ Ver preparo da IA</a><?php endif; ?>
              <form method="post"><input type="hidden" name="action" value="preparar_entrada_xml"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><button class="btn btn-primary btn-sm">Conferir entrada</button></form>
            <?php else: ?>
              <?php if ($entryCrossContext): ?>
                <span class="fiscal-muted">XML completo ainda não armazenado. A recuperação/manifestação SEFAZ exige acesso direto à empresa nesta versão.</span>
              <?php else: ?>
              <?php if (!empty($x['access_key'])): ?><form method="post"><input type="hidden" name="action" value="fetch_dfe_key"><input type="hidden" name="access_key" value="<?= fiscal_h($x['access_key']) ?>"><button class="btn btn-sm">Tentar obter XML</button></form><?php endif; ?>
              <?php if ($kind === 'RESNFE'): ?>
                <form method="post" onsubmit="return confirm('Registrar Ciência da Emissão desta NF-e? Esta é uma manifestação fiscal do destinatário.')"><input type="hidden" name="action" value="manifest_dfe"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_type" value="210210"><button class="btn btn-primary btn-sm">Ciência da emissão</button></form>
                <form method="post" onsubmit="return confirm('Confirmar definitivamente a operação desta NF-e?')"><input type="hidden" name="action" value="manifest_dfe"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_type" value="210200"><button class="btn btn-sm">Confirmar operação</button></form>
                <form method="post" onsubmit="return confirm('Declarar que a empresa desconhece esta operação?')"><input type="hidden" name="action" value="manifest_dfe"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_type" value="210220"><button class="btn btn-sm" type="submit">Desconheço a operação</button></form>
                <form method="post" onsubmit="return radarSolicitarJustificativa(this)"><input type="hidden" name="action" value="manifest_dfe"><input type="hidden" name="radar_id" value="<?= (int)$x['id'] ?>"><input type="hidden" name="event_type" value="210240"><input type="hidden" name="justification" value=""><button class="btn btn-sm" type="submit">Operação não realizada</button></form>
              <?php elseif (empty($x['access_key'])): ?><span class="fiscal-muted">Resumo/evento recebido; o XML completo ainda não foi distribuído.</span><?php endif; ?>
              <?php endif; ?>
            <?php endif; ?>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php if ($logs): ?>
<div class="fiscal-card"><h2>Últimas buscas DF-e</h2><div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Data</th><th>Status</th><th>Retorno</th><th>Documentos</th><th>XMLs</th><th>NSU</th></tr></thead><tbody><?php foreach ($logs as $l): ?><tr><td><?= fiscal_h($l['started_at'] ?? '') ?></td><td><span class="fiscal-badge <?= ($l['status'] ?? '')==='CONCLUIDO'?'':'off' ?>"><?= fiscal_h($l['status'] ?? '') ?></span></td><td><?= fiscal_h(trim(($l['status_code'] ?? '') . ' ' . ($l['status_message'] ?? '') . ' ' . ($l['error_message'] ?? ''))) ?></td><td><?= (int)($l['documents_found'] ?? 0) ?></td><td><?= (int)($l['xmls_stored'] ?? 0) ?></td><td><?= fiscal_h(($l['last_nsu_before'] ?? '') . ' → ' . ($l['last_nsu_after'] ?? '')) ?></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php endif; ?>
<?php fiscal_admin_end(); ?>
