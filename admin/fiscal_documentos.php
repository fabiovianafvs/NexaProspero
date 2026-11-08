<?php
declare(strict_types=1);
// NEXA_PATCH_FISCAL_DOCUMENTOS_PEDIDO_EMPRESA_V2_20260805
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_chrome.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/company_sequences.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_documents_read')
  : (int)COMPANY_ID;
$fiscalReadOnlyF11 = defined('MULTI_COMPANY_CONTEXT_VALID') && MULTI_COMPANY_CONTEXT_VALID === true
  && $cid !== (int)COMPANY_ID;
$fiscalCancelF11 = $fiscalReadOnlyF11
  && function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_cancel_homologation');
$fiscalCancelF11Allowed = $fiscalCancelF11
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'fiscal_documentos')
  && (!function_exists('saas_company_has_module') || saas_company_has_module($pdo, $cid, 'fiscal'));
$fiscalCceF11 = $fiscalReadOnlyF11
  && function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_cce_homologation');
$fiscalCceF11Allowed = $fiscalCceF11
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'fiscal_documentos')
  && (!function_exists('saas_company_has_module') || saas_company_has_module($pdo, $cid, 'fiscal'));
$fiscalNfseF11 = $fiscalReadOnlyF11
  && function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_homologation');
$fiscalNfseF11Allowed = $fiscalNfseF11
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'fiscal_documentos')
  && (!function_exists('saas_company_has_module') || saas_company_has_module($pdo, $cid, 'fiscal'));
$fiscalNfseCancelF11 = $fiscalReadOnlyF11
  && function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_nfse_cancel_homologation');
$fiscalNfseCancelF11Allowed = $fiscalNfseCancelF11
  && function_exists('multi_company_context_permission_allowed')
  && multi_company_context_permission_allowed($pdo, 'fiscal_documentos')
  && (!function_exists('saas_company_has_module') || saas_company_has_module($pdo, $cid, 'fiscal'));
$fiscalMcCsrf = $fiscalReadOnlyF11 && function_exists('multi_company_context_csrf_token')
  ? multi_company_context_csrf_token()
  : '';
if ($fiscalReadOnlyF11) $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
company_sequence_ensure_schema($pdo, !$fiscalReadOnlyF11);

$q = trim((string)($_GET['q'] ?? ''));
$seriesFilter = trim((string)($_GET['series'] ?? ''));
$statusFilter = strtoupper(trim((string)($_GET['auth_status'] ?? '')));
$issueFrom = trim((string)($_GET['issue_from'] ?? ''));
$issueTo = trim((string)($_GET['issue_to'] ?? ''));
$authFrom = trim((string)($_GET['auth_from'] ?? ''));
$authTo = trim((string)($_GET['auth_to'] ?? ''));

if ($issueFrom === '') $issueFrom = date('Y-m-d');
if ($issueTo === '') $issueTo = date('Y-m-d');

if (!function_exists('fisc_doc_search_documents')) {
  function fisc_doc_search_documents(PDO $pdo, int $companyId, array $filters, int $limit = 150): array {
    if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) fiscal_nfce_ensure_schema($pdo);

    $q = trim((string)($filters['q'] ?? ''));
    $series = trim((string)($filters['series'] ?? ''));
    $status = strtoupper(trim((string)($filters['status'] ?? '')));
    $issueFrom = trim((string)($filters['issue_from'] ?? ''));
    $issueTo = trim((string)($filters['issue_to'] ?? ''));
    $authFrom = trim((string)($filters['auth_from'] ?? ''));
    $authTo = trim((string)($filters['auth_to'] ?? ''));

    $hasCompanyOrderNumber = company_sequence_has_column($pdo, 'pedidos');
    $orderNumberExpr = $hasCompanyOrderNumber ? 'p.numero_pedido' : 'p.id';

    $where = ["fd.company_id = ?", "fd.direction = 'SAIDA'", "fd.model IN ('65','55','NFSE')"];
    $params = [$companyId];

    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueFrom)) {
      $where[] = 'DATE(COALESCE(fd.operation_datetime,fd.issue_datetime)) >= ?';
      $params[] = $issueFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $issueTo)) {
      $where[] = 'DATE(COALESCE(fd.operation_datetime,fd.issue_datetime)) <= ?';
      $params[] = $issueTo;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $authFrom)) {
      $where[] = "DATE(COALESCE(fd.authorization_datetime,nfd.authorized_at, CASE WHEN UPPER(COALESCE(fd.status,''))='AUTORIZADA' THEN fd.updated_at END)) >= ?";
      $params[] = $authFrom;
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $authTo)) {
      $where[] = "DATE(COALESCE(fd.authorization_datetime,nfd.authorized_at, CASE WHEN UPPER(COALESCE(fd.status,''))='AUTORIZADA' THEN fd.updated_at END)) <= ?";
      $params[] = $authTo;
    }
    if ($status !== '') {
      $where[] = 'UPPER(fd.status) = ?';
      $params[] = $status;
    }
    if ($series !== '') {
      $where[] = 'fd.series = ?';
      $params[] = $series;
    }

    if ($q !== '') {
      $digits = preg_replace('/\D+/', '', $q) ?: '';
      $like = nexa_search_like($q);
      $search = [
        'fd.access_key LIKE ?',
        'fd.status LIKE ?',
        'fd.operation_nature LIKE ?',
        'CAST(fd.number AS CHAR) LIKE ?',
        'EXISTS (SELECT 1 FROM fiscal_document_participants fp WHERE fp.fiscal_document_id = fd.id AND fp.company_id = fd.company_id AND (fp.name LIKE ? OR fp.document LIKE ?))',
      ];
      $searchParams = [$like, $like, $like, $like, $like, $like];
      if ($digits !== '') {
        $search[] = $orderNumberExpr . ' = ?';
        $searchParams[] = (int)$digits;
        $search[] = 'fd.number = ?';
        $searchParams[] = (int)$digits;
      } else {
        $search[] = 'CAST(COALESCE(' . $orderNumberExpr . ", '') AS CHAR) LIKE ?";
        $searchParams[] = $like;
      }
      $where[] = '(' . implode(' OR ', $search) . ')';
      $params = array_merge($params, $searchParams);
    }

    // Desempenho: a lista não traz o XML (em média 12 KB por nota); só informa se
    // ele existe. O XML é lido à parte apenas quando precisa completar a data de autorização.
    static $fdListCols = null;
    if ($fdListCols === null) {
      $fdListCols = [];
      foreach ($pdo->query('SHOW COLUMNS FROM fiscal_documents')->fetchAll(PDO::FETCH_COLUMN) as $col) {
        if (in_array(strtolower((string)$col), ['xml_raw'], true)) continue;
        $fdListCols[] = 'fd.`' . str_replace('`', '', (string)$col) . '`';
      }
    }
    $sql = "SELECT " . implode(', ', $fdListCols) . ", CASE WHEN fd.xml_raw IS NULL OR fd.xml_raw = '' THEN '' ELSE '1' END AS xml_raw, nfd.nfse_number, nfd.dps_number, nfd.dps_series, nfd.provider_code, nfd.authorized_at AS nfse_authorized_at, nfd.api_error_message AS nfse_error_message, " . $orderNumberExpr . " AS order_company_number, "
         . "(SELECT fp.name FROM fiscal_document_participants fp "
         . " WHERE fp.fiscal_document_id = fd.id AND fp.company_id = fd.company_id "
         . " ORDER BY CASE WHEN fp.role IN ('DESTINATARIO','CLIENTE','TOMADOR') THEN 0 ELSE 1 END, fp.id ASC LIMIT 1) AS person_name "
         . "FROM fiscal_documents fd "
         . "LEFT JOIN fiscal_nfse_details nfd ON nfd.fiscal_document_id=fd.id AND nfd.company_id=fd.company_id "
         . "LEFT JOIN pedidos p ON p.id = fd.source_id AND p.company_id = fd.company_id AND fd.source_type = 'PEDIDO' "
         . 'WHERE ' . implode(' AND ', $where)
         . ' ORDER BY COALESCE(fd.operation_datetime, fd.issue_datetime, fd.created_at) DESC, fd.id DESC LIMIT ' . max(10, min(500, $limit));

    $st = $pdo->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    // Backfill leve da data de autorização para documentos antigos exibidos nesta tela.
    foreach ($rows as &$row) {
      if (in_array((string)($row['model'] ?? ''), ['55','65'], true) && strtoupper((string)($row['status'] ?? '')) === 'AUTORIZADA' && empty($row['authorization_datetime']) && !empty($row['xml_raw']) && function_exists('fiscal_nfce_authorization_datetime_from_xml')) {
        $stXml = $pdo->prepare('SELECT xml_raw FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
        $stXml->execute([$companyId, (int)$row['id']]);
        $authDt = fiscal_nfce_authorization_datetime_from_xml((string)$stXml->fetchColumn());
        if ($authDt) {
          $row['authorization_datetime'] = $authDt;
          if (empty($GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'])) { try { $pdo->prepare('UPDATE fiscal_documents SET authorization_datetime=? WHERE company_id=? AND id=? AND authorization_datetime IS NULL')->execute([$authDt,$companyId,(int)$row['id']]); } catch (Throwable $e) {} }
        }
      }
    }
    unset($row);
    return $rows;
  }
}

$filters = [
  'q' => $q,
  'series' => $seriesFilter,
  'status' => $statusFilter,
  'issue_from' => $issueFrom,
  'issue_to' => $issueTo,
  'auth_from' => $authFrom,
  'auth_to' => $authTo,
];

$err = '';
try {
  $rows = fisc_doc_search_documents($pdo, $cid, $filters, 150);
} catch (Throwable $e) {
  $rows = [];
  $err = $e->getMessage();
}
function fisc_doc_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function fisc_doc_date($v): string { $ts = strtotime((string)$v); return $ts ? date('d-m-Y H:i', $ts) : '-'; }
function fisc_doc_badge_class(string $status): string {
  $s = strtoupper(trim($status));
  if ($s === 'AUTORIZADA') return 'fiscal-badge';
  if (in_array($s, ['REJEITADA','ERRO_AUTORIZACAO','DUPLICIDADE'], true)) return 'fiscal-badge nfce-doc-badge-warn';
  return 'fiscal-badge off';
}
function fisc_doc_status_label(string $status): string {
  $s = strtoupper(trim($status));
  if ($s === '') return '-';
  if ($s === 'PRE_NFCE' || $s === 'PRE_NFE') return 'Pré';
  if ($s === 'AUTORIZADA') return 'Autorizada';
  if ($s === 'REJEITADA') return 'Rejeitada';
  if ($s === 'DESCARTADA') return 'Rejeição descartada (local)';
  if ($s === 'ERRO_AUTORIZACAO') return 'Erro';
  if ($s === 'DUPLICIDADE') return 'Duplicidade';
  if ($s === 'CANCELADA') return 'Cancelada';
  return str_replace('_', ' ', $s);
}

$returnParams = array_filter([
  'q' => $q,
  'series' => $seriesFilter,
  'auth_status' => $statusFilter,
  'issue_from' => $issueFrom,
  'issue_to' => $issueTo,
  'auth_from' => $authFrom,
  'auth_to' => $authTo,
], static fn($value) => trim((string)$value) !== '');
$returnTo = 'fiscal_documentos.php' . ($returnParams ? '?' . http_build_query($returnParams) : '');
$printReturnTo = $fiscalReadOnlyF11 && function_exists('multi_company_context_url')
  ? multi_company_context_url($returnTo) : $returnTo;

fiscal_admin_start('Documentos fiscais', 'fiscal_documentos');
?>
<div class="fiscal-doc-page-title">
  <div><h1>Documentos fiscais</h1></div>
  <div class="fiscal-doc-page-actions">
    <?php if ($fiscalReadOnlyF11): ?>
      <span class="fiscal-badge off">V11.49 · fiscal protegido</span>
      <a class="btn" href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_inutilizacao.php') : 'fiscal_inutilizacao.php') ?>">Inutilização</a>
    <?php else: ?>
      <a class="btn btn-primary" href="fiscal_notas_referenciadas.php">Emitir Notas Referenciadas</a>
      <a class="btn" href="fiscal_lote_contingencia.php">Notas em lote</a>
      <a class="btn" href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_inutilizacao.php') : 'fiscal_inutilizacao.php') ?>">Inutilização</a>
    <?php endif; ?>
  </div>
</div>
<?php
fiscal_tabs('fiscal_documentos');
fiscal_flash();
if ($fiscalReadOnlyF11) echo '<div class="msg ok"><b>V11.49 · Fiscal Multiempresa em HOMOLOGAÇÃO.</b> Os documentos abaixo pertencem à empresa selecionada pelo F11. NF-e/NFC-e podem ser emitidas, consultadas, impressas e canceladas; NF-e modelo 55 pode receber Carta de Correção; a inutilização de numeração está liberada em tela própria. Produção, NFS-e e lote continuam bloqueados.</div>';
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>

<style>
  .nfce-doc-table{width:100%;min-width:900px;table-layout:fixed;border-collapse:collapse}
  .nfce-doc-table th,.nfce-doc-table td{vertical-align:middle;border-bottom:1px solid #e5edf6}
  .nfce-doc-table th{padding:10px 12px;background:#f8fbff;color:#0f255f;font-size:12px;font-weight:950}
  .nfce-doc-table td{padding:12px;color:#0f172a}
  .nfce-doc-table th:nth-child(1),.nfce-doc-table td:nth-child(1){width:24%}
  .nfce-doc-table th:nth-child(2),.nfce-doc-table td:nth-child(2){width:13%}
  .nfce-doc-table th:nth-child(3),.nfce-doc-table td:nth-child(3){width:14%}
  .nfce-doc-table th:nth-child(4),.nfce-doc-table td:nth-child(4){width:17%}
  .nfce-doc-table th:nth-child(5),.nfce-doc-table td:nth-child(5){width:12%}
  .nfce-doc-table th:nth-child(6),.nfce-doc-table td:nth-child(6){width:20%}
  .nfce-doc-row td{border-bottom:0}.nfce-doc-action-row td{padding:0 12px 14px!important;border-bottom:1px solid #e5edf6}
  .nfce-doc-action-panel{display:flex;align-items:center;justify-content:flex-end;gap:10px;flex-wrap:wrap;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:9px 10px}.nfce-doc-action-title{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;margin-right:auto}.nfce-doc-action-buttons{display:flex;gap:8px;align-items:center;justify-content:flex-end;flex-wrap:wrap}.nfce-doc-action-buttons form{margin:0;display:inline-flex}.nfce-doc-action-buttons .btn{min-width:86px;min-height:34px;padding:7px 11px;display:inline-flex;align-items:center;justify-content:center;border-radius:12px;font-size:12px;font-weight:900;line-height:1;white-space:nowrap}.nfce-doc-copy-key.copied{border-color:#86efac;background:#f0fdf4;color:#166534}.nfce-doc-key{display:block;max-width:100%;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.nfce-doc-badge-warn{background:#fff7ed!important;color:#9a3412!important;border-color:#fed7aa!important}.nfce-doc-table .fiscal-badge{font-size:10px;padding:3px 7px;white-space:nowrap;line-height:1.1}
  .fiscal-doc-page-title{display:flex;align-items:flex-end;justify-content:space-between;gap:14px;flex-wrap:wrap;margin:6px 0 14px}
  .fiscal-doc-page-title h1{margin:0;color:var(--admin-primary);font-size:26px;line-height:1.1;letter-spacing:-.03em}
  .fiscal-doc-page-actions{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
  .fiscal-doc-filter-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin:10px 0 14px;box-shadow:0 12px 30px rgba(15,23,42,.04)}
  .fiscal-doc-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end;max-width:100%;overflow:visible}
  .fiscal-doc-filters label{margin:0;font-size:12px;font-weight:800;color:#334155;flex:1 1 135px;min-width:130px}
  .fiscal-doc-filters .fiscal-doc-search-field{flex:2 1 250px;min-width:220px}
  .fiscal-doc-filters .fiscal-doc-series-field{flex:0 1 105px;min-width:95px;max-width:120px}
  .fiscal-doc-filters input,.fiscal-doc-filters select{min-height:42px;width:100%;margin-top:6px;border-radius:14px;box-sizing:border-box}
  .fiscal-doc-filter-actions{display:flex;gap:10px;align-items:end;justify-content:flex-end;flex:0 0 auto;margin-left:auto}
  .fiscal-doc-filters .btn{min-height:42px;min-width:86px;display:inline-flex;align-items:center;justify-content:center}
  .fiscal-doc-origin{display:block;font-weight:900;color:#0f172a}
  .fiscal-doc-person{display:block;margin-top:4px;color:#64748b;font-size:12px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  @media(max-width:900px){.fiscal-doc-page-title{align-items:flex-start}.fiscal-doc-page-actions{width:100%}.fiscal-doc-page-actions .btn{flex:1 1 180px;justify-content:center}.fiscal-doc-filter-card{padding:12px}.fiscal-doc-filters{display:grid;grid-template-columns:1fr;gap:11px}.fiscal-doc-filters label,.fiscal-doc-filters .fiscal-doc-search-field,.fiscal-doc-filters .fiscal-doc-series-field{min-width:0;width:100%;max-width:none;flex:none}.fiscal-doc-filters input,.fiscal-doc-filters select{font-size:16px}.fiscal-doc-filter-actions{display:grid;grid-template-columns:1fr 1fr;gap:10px;width:100%;margin-left:0}.fiscal-doc-filter-actions .btn{width:100%;min-width:0}}
  @media(max-width:760px){.nfce-doc-table{min-width:0}.nfce-doc-table thead{display:none}.nfce-doc-table,.nfce-doc-table tbody,.nfce-doc-table tr,.nfce-doc-table td{display:block;width:100%}.nfce-doc-row{border:1px solid #dbe4f0;border-bottom:0;border-radius:18px 18px 0 0;background:#fff;margin-top:10px}.nfce-doc-row td{display:flex;justify-content:space-between;gap:12px;border-bottom:1px solid #edf2f7;padding:9px 12px;white-space:normal!important;overflow:visible!important}.nfce-doc-row td::before{content:attr(data-label);font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em}.nfce-doc-row td.primary{display:block}.nfce-doc-row td.primary::before{display:block;margin-bottom:5px}.nfce-doc-action-row{display:block;border:1px solid #dbe4f0;border-top:0;border-radius:0 0 18px 18px;background:#fff;margin-bottom:10px}.nfce-doc-action-row td{display:block!important;border:0!important;padding:0 10px 10px!important}.nfce-doc-action-panel{justify-content:flex-start}.nfce-doc-action-title{flex:1 0 100%;margin:0}.nfce-doc-action-buttons,.nfce-doc-action-buttons form,.nfce-doc-action-buttons .btn{width:100%}.nfce-doc-action-buttons .btn{min-height:40px}}
</style>

<div class="fiscal-doc-filter-card">
  <form method="get" class="fiscal-form">
    <div class="fiscal-doc-filters">
      <label class="fiscal-doc-search-field">Histórico / Cliente / número
        <input type="search" name="q" value="<?= fiscal_h($q) ?>" placeholder="Cliente, pedido ou documento fiscal">
      </label>
      <label class="fiscal-doc-series-field">Série
        <input type="text" name="series" value="<?= fiscal_h($seriesFilter) ?>" placeholder="Todas">
      </label>
      <label>Status
        <select name="auth_status">
          <?php $statusOptions = [''=>'Todos','AUTORIZADA'=>'Autorizada','CANCELADA'=>'Cancelada','REJEITADA'=>'Rejeitada','DESCARTADA'=>'Rejeição descartada','ERRO_COMUNICACAO'=>'Comunicação incerta','ASSINADA'=>'Assinada','PREPARADA'=>'Preparada','TRANSMITINDO'=>'Transmitindo','ERRO_AUTORIZACAO'=>'Erro','DUPLICIDADE'=>'Duplicidade','PRE_NFCE'=>'Pré NFC-e','PRE_NFE'=>'Pré NF-e']; foreach ($statusOptions as $val => $lab): ?>
            <option value="<?= fiscal_h($val) ?>" <?= $statusFilter === $val ? 'selected' : '' ?>><?= fiscal_h($lab) ?></option>
          <?php endforeach; ?>
        </select>
      </label>
      <label>Operação de
        <input type="date" name="issue_from" value="<?= fiscal_h($issueFrom) ?>">
      </label>
      <label>Operação até
        <input type="date" name="issue_to" value="<?= fiscal_h($issueTo) ?>">
      </label>
      <label>Autorização de
        <input type="date" name="auth_from" value="<?= fiscal_h($authFrom) ?>">
      </label>
      <label>Autorização até
        <input type="date" name="auth_to" value="<?= fiscal_h($authTo) ?>">
      </label>
      <div class="fiscal-doc-filter-actions">
        <button class="btn btn-primary" type="submit">Filtrar</button>
        <a class="btn" href="fiscal_documentos.php">Limpar</a>
      </div>
    </div>
  </form>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Documentos</h2><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
  <?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="table-wrap"><table class="nfce-doc-table"<?=nexa_grid_auto_attrs($pdo,'auto_fiscal_documentos')?>>
    <thead><tr><th>Documento</th><th>Origem</th><th>Operação</th><th>Autorização</th><th>Status</th><th>Total</th><th>Retorno fiscal</th></tr></thead>
    <tbody>
      <?php if (!$rows): ?><tr><td colspan="7"><div class="fiscal-empty-state">Nenhum documento fiscal encontrado.</div></td></tr><?php endif; ?>
      <?php foreach ($rows as $r):
        $docId=(int)$r['id'];
        $pedidoId=(($r['source_type'] ?? '')==='PEDIDO')?(int)$r['source_id']:0;
        $statusDoc=strtoupper((string)($r['status'] ?? ''));
        $isAut=$statusDoc==='AUTORIZADA';
        $model=strtoupper((string)($r['model'] ?? '65'));
        $isNfse=$model==='NFSE';
        $docLabel=$model==='55'?'NF-e':($model==='65'?'NFC-e':'NFS-e');
        $nfseNumber=trim((string)($r['nfse_number'] ?? ''));
        $dpsNumber=trim((string)($r['dps_number'] ?? $r['number'] ?? ''));
        $dpsSeries=trim((string)($r['dps_series'] ?? $r['series'] ?? ''));
        $authDate=$r['authorization_datetime'] ?? $r['nfse_authorized_at'] ?? '';
        $returnFiscal=trim((string)($r['sefaz_status_code'] ?? '') . ' ' . (string)($r['sefaz_status_message'] ?? ''));
      ?>
        <tr class="nfce-doc-row">
          <td data-label="Documento" class="primary">
            <?php if ($isNfse): ?>
              <b>NFS-e <?= fiscal_h($nfseNumber !== '' ? $nfseNumber : '-') ?></b>
              <span class="fiscal-doc-person">DPS <?= fiscal_h(ltrim($dpsSeries,'0') !== '' ? ltrim($dpsSeries,'0') : $dpsSeries) ?>/<?= fiscal_h($dpsNumber) ?> · <?= fiscal_h((string)($r['provider_code'] ?? '')) ?></span>
            <?php else: ?>
              <b><?= fiscal_h(($r['model'] ?? '') . ' ' . ($r['series'] ?? '') . '/' . ($r['number'] ?? '')) ?></b>
            <?php endif; ?>
            <span class="fiscal-muted nfce-doc-key"><?= fiscal_h($r['access_key'] ?? '') ?></span>
          </td>
          <td data-label="Origem"><span class="fiscal-doc-origin"><?= fiscal_h(($r['source_type'] ?? '-') . ' #' . (($r['order_company_number'] ?? '') !== '' ? $r['order_company_number'] : ($r['source_id'] ?? '-'))) ?></span><?php if (trim((string)($r['person_name'] ?? '')) !== ''): ?><span class="fiscal-doc-person"><?= fiscal_h((string)$r['person_name']) ?></span><?php endif; ?></td>
          <td data-label="Operação"><?= fiscal_h(fisc_doc_date($r['operation_datetime'] ?? $r['issue_datetime'] ?? '')) ?><?php if (!empty($r['operation_datetime']) && !empty($r['issue_datetime']) && abs((strtotime((string)$r['operation_datetime']) ?: 0) - (strtotime((string)$r['issue_datetime']) ?: 0)) > 60): ?><span class="fiscal-doc-person">Emissão fiscal: <?= fiscal_h(fisc_doc_date($r['issue_datetime'])) ?></span><?php endif; ?></td>
          <td data-label="Autorização"><?= fiscal_h(fisc_doc_date($authDate)) ?></td>
          <td data-label="Status"><span class="<?= fiscal_h(fisc_doc_badge_class((string)($r['status'] ?? ''))) ?>"><?= fiscal_h(fisc_doc_status_label((string)($r['status'] ?? ''))) ?></span></td>
          <td data-label="Total"><b><?= fisc_doc_money($r['total_document'] ?? 0) ?></b></td>
          <td data-label="Retorno fiscal" title="<?= fiscal_h($returnFiscal) ?>"><?= fiscal_h(trim((string)($r['sefaz_status_code'] ?? '')) !== '' ? (($r['sefaz_status_code'] ?? '') . ' - ' . ($r['sefaz_status_message'] ?? '')) : ($isNfse && trim((string)($r['nfse_error_message'] ?? '')) !== '' ? (string)$r['nfse_error_message'] : '-')) ?></td>
        </tr>
        <tr class="nfce-doc-action-row">
          <td colspan="7">
            <div class="nfce-doc-action-panel">
              <span class="nfce-doc-action-title">Ações fiscais</span>
              <div class="nfce-doc-action-buttons">
                <?php $accessKeyDigits = preg_replace('/\D+/', '', (string)($r['access_key'] ?? '')) ?: ''; ?>
                <?php if ($statusDoc === 'DESCARTADA'): ?>
                  <span class="fiscal-muted">Tentativa rejeitada arquivada. XML e histórico preservados; sem novas ações fiscais neste documento.</span>
                  <?php if (!$fiscalReadOnlyF11): ?>
                    <a class="btn btn-sm" data-no-processing href="fiscal_nfce_acao.php?action=download_xml&amp;doc_id=<?= $docId ?>">Baixar XML histórico</a>
                  <?php endif; ?>
                <?php elseif ($fiscalReadOnlyF11): ?>
                  <?php if ($accessKeyDigits !== ''): ?><button class="btn btn-sm nfce-doc-copy-key" type="button" data-copy-access-key="<?= fiscal_h($accessKeyDigits) ?>">Copiar chave de acesso</button><?php endif; ?>
                  <?php if ($isNfse && $fiscalNfseF11Allowed && strtoupper(trim((string)($r['environment'] ?? ''))) === 'HOMOLOGACAO'): ?>
                    <?php if ($pedidoId > 0): ?><a class="btn btn-sm btn-primary" href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_nacional.php?pedido_id=' . $pedidoId) : ('fiscal_nfse_nacional.php?pedido_id=' . $pedidoId)) ?>">Abrir NFS-e · Homologação</a><?php endif; ?>
                    <?php if (in_array($statusDoc,['AUTORIZADA','CANCELADA'],true)): ?>
                      <a class="btn btn-sm btn-primary" data-no-processing href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_imprimir.php?doc_id=' . $docId . '&return_to=' . rawurlencode($printReturnTo)) : ('fiscal_nfse_imprimir.php?doc_id=' . $docId . '&return_to=' . rawurlencode($printReturnTo))) ?>">Imprimir NFS-e</a>
                    <?php endif; ?>
                    <?php if (!empty($r['xml_raw']) || !empty($r['nfse_number'])): ?><a class="btn btn-sm" data-no-processing href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_acao.php?action=download_nfse&doc_id=' . $docId . '&pedido_id=' . $pedidoId) : ('fiscal_nfse_acao.php?action=download_nfse&doc_id=' . $docId . '&pedido_id=' . $pedidoId)) ?>">Baixar XML NFS-e</a><?php endif; ?>
                    <?php
                      $f11NfseCancelDocAllowed = $fiscalNfseCancelF11Allowed
                        && $statusDoc === 'AUTORIZADA'
                        && strtoupper(trim((string)($r['environment'] ?? ''))) === 'HOMOLOGACAO'
                        && strtoupper(trim((string)($r['source_type'] ?? ''))) === 'PEDIDO'
                        && $pedidoId > 0
                        && strtoupper(trim((string)($r['provider_code'] ?? ''))) === 'PRONIM';
                    ?>
                    <?php if ($f11NfseCancelDocAllowed): ?>
                      <a class="btn btn-sm" href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_nacional.php?pedido_id=' . $pedidoId) : ('fiscal_nfse_nacional.php?pedido_id=' . $pedidoId)) ?>">Cancelar NFS-e · Homologação</a>
                    <?php endif; ?>
                    <?php if ($statusDoc === 'CANCELADA' && $fiscalNfseCancelF11Allowed && strtoupper(trim((string)($r['environment'] ?? ''))) === 'HOMOLOGACAO' && strtoupper(trim((string)($r['source_type'] ?? ''))) === 'PEDIDO' && $pedidoId > 0 && strtoupper(trim((string)($r['provider_code'] ?? ''))) === 'PRONIM'): ?>
                      <a class="btn btn-sm" data-no-processing href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfse_acao.php?action=download_cancel_event&doc_id=' . $docId . '&pedido_id=' . $pedidoId) : ('fiscal_nfse_acao.php?action=download_cancel_event&doc_id=' . $docId . '&pedido_id=' . $pedidoId)) ?>">Baixar cancelamento</a>
                    <?php endif; ?>
                  <?php elseif (!$isNfse && in_array($model, ['55','65'], true)): ?>
                    <a class="btn btn-sm" data-no-processing href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php?action=download_xml&doc_id=' . $docId) : ('fiscal_nfce_acao.php?action=download_xml&doc_id=' . $docId)) ?>">Baixar XML atual</a>
                    <a class="btn btn-sm btn-primary" data-no-processing href="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_imprimir.php?doc_id=' . $docId . '&return_to=' . rawurlencode($printReturnTo)) : ('fiscal_nfce_imprimir.php?doc_id=' . $docId . '&return_to=' . rawurlencode($printReturnTo))) ?>">Imprimir <?= fiscal_h($docLabel) ?></a>
                    <form method="post" action="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>">
                      <input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalMcCsrf) ?>">
                      <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="consult_status"><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                      <button class="btn btn-sm" type="submit">Consultar Situação</button>
                    </form>
                    <?php
                      $f11CancelDocAllowed = $fiscalCancelF11Allowed
                        && $isAut
                        && strtoupper(trim((string)($r['environment'] ?? ''))) === 'HOMOLOGACAO'
                        && strtoupper(trim((string)($r['source_type'] ?? ''))) === 'PEDIDO'
                        && (int)($r['source_id'] ?? 0) > 0;
                    ?>
                    <?php if ($f11CancelDocAllowed): ?>
                      <form method="post" action="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>" data-fiscal-cancel-form>
                        <input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalMcCsrf) ?>">
                        <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="cancel_document"><input type="hidden" name="justification" value=""><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Cancelar <?= fiscal_h($docLabel) ?> · Homologação</button>
                      </form>
                    <?php endif; ?>
                    <?php
                      $f11CceDocAllowed = $fiscalCceF11Allowed
                        && $model === '55'
                        && $isAut
                        && strtoupper(trim((string)($r['environment'] ?? ''))) === 'HOMOLOGACAO'
                        && strtoupper(trim((string)($r['source_type'] ?? ''))) === 'PEDIDO'
                        && (int)($r['source_id'] ?? 0) > 0;
                    ?>
                    <?php if ($f11CceDocAllowed): ?>
                      <form method="post" action="<?= fiscal_h(function_exists('multi_company_context_url') ? multi_company_context_url('fiscal_nfce_acao.php') : 'fiscal_nfce_acao.php') ?>" data-fiscal-cce-form>
                        <input type="hidden" name="mc_csrf" value="<?= fiscal_h($fiscalMcCsrf) ?>">
                        <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="cce_document"><input type="hidden" name="correction" value=""><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Carta de Correção · Homologação</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                <?php elseif ($isNfse): ?>
                  <?php if ($pedidoId > 0): ?><a class="btn btn-sm btn-primary" href="fiscal_nfse_nacional.php?pedido_id=<?= $pedidoId ?>">Abrir NFS-e</a><?php endif; ?>
                  <?php if ($accessKeyDigits !== ''): ?><button class="btn btn-sm nfce-doc-copy-key" type="button" data-copy-access-key="<?= fiscal_h($accessKeyDigits) ?>">Copiar chave de acesso</button><?php endif; ?>
                  <?php if (in_array($statusDoc,['AUTORIZADA','CANCELADA'],true)): ?>
                    <a class="btn btn-sm btn-primary" data-no-processing href="fiscal_nfse_imprimir.php?doc_id=<?= $docId ?>&amp;return_to=<?= rawurlencode($printReturnTo) ?>">Imprimir NFS-e</a>
                    <a class="btn btn-sm" data-no-processing href="fiscal_documento_whatsapp.php?doc_id=<?= $docId ?>&return_to=<?= rawurlencode($returnTo) ?>">WhatsApp</a>
                    <a class="btn btn-sm" data-no-processing href="email_documento.php?type=fiscal&doc_id=<?= $docId ?>&return_to=<?= rawurlencode($returnTo) ?>">E-mail</a>
                  <?php endif; ?>
                  <a class="btn btn-sm" data-no-processing href="fiscal_nfse_acao.php?action=download_dps&doc_id=<?= $docId ?>&pedido_id=<?= $pedidoId ?>">Baixar DPS</a>
                  <?php if (in_array($statusDoc,['AUTORIZADA','CANCELADA'],true)): ?><a class="btn btn-sm" data-no-processing href="fiscal_nfse_acao.php?action=download_nfse&doc_id=<?= $docId ?>&pedido_id=<?= $pedidoId ?>">Baixar XML NFS-e</a><?php endif; ?>
                  <?php if ($statusDoc==='CANCELADA'): ?><a class="btn btn-sm" data-no-processing href="fiscal_nfse_acao.php?action=download_cancel_event&doc_id=<?= $docId ?>&pedido_id=<?= $pedidoId ?>">Baixar cancelamento</a><?php endif; ?>
                <?php else: ?>
                  <?php if ($pedidoId > 0 && $model === '65'): ?><a class="btn btn-sm btn-primary" href="fiscal_nfce_pedido.php?pedido_id=<?= $pedidoId ?>">Abrir NFC-e</a><?php elseif ($pedidoId > 0): ?><a class="btn btn-sm btn-primary" href="pedido_editar.php?id=<?= $pedidoId ?>">Abrir pedido/NF-e</a><?php endif; ?>
                  <?php if ($accessKeyDigits !== ''): ?><button class="btn btn-sm nfce-doc-copy-key" type="button" data-copy-access-key="<?= fiscal_h($accessKeyDigits) ?>" title="Copiar a chave de acesso para a área de transferência">Copiar chave de acesso</button><?php endif; ?>
                  <?php if ($isAut && $statusDoc !== 'CANCELADA'): ?>
                    <form method="post" action="fiscal_nfce_acao.php" data-fiscal-cancel-form>
                      <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="cancel_document"><input type="hidden" name="justification" value=""><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                      <button class="btn btn-sm" type="submit">Cancelar <?= fiscal_h($docLabel) ?></button>
                    </form>
                    <?php if ($model === '55'): ?>
                      <form method="post" action="fiscal_nfce_acao.php" data-fiscal-cce-form>
                        <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="cce_document"><input type="hidden" name="correction" value=""><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                        <button class="btn btn-sm" type="submit">Carta de Correção</button>
                      </form>
                    <?php endif; ?>
                  <?php endif; ?>
                  <?php if (!$isAut): ?><a class="btn btn-sm" href="fiscal_nfce_editar.php?doc_id=<?= $docId ?>">Editar dados</a><?php endif; ?>
                  <a class="btn btn-sm" data-no-processing href="fiscal_nfce_acao.php?action=download_xml&doc_id=<?= $docId ?>">Baixar XML atual</a>
                  <a class="btn btn-sm" data-no-processing href="fiscal_nfce_imprimir.php?doc_id=<?= $docId ?>&amp;return_to=<?= rawurlencode($printReturnTo) ?>">Imprimir <?= fiscal_h($docLabel) ?></a>
                  <form method="post" action="fiscal_nfce_acao.php">
                    <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="consult_status"><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                    <button class="btn btn-sm" type="submit">Consultar Situação</button>
                  </form>
                  <?php if (!$isAut): ?>
                    <form method="post" action="fiscal_nfce_acao.php">
                      <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="recover_authorization"><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                      <button class="btn btn-sm" type="submit">Recuperar <?= fiscal_h($docLabel) ?></button>
                    </form>
                    <form method="post" action="fiscal_nfce_acao.php">
                      <input type="hidden" name="doc_id" value="<?= $docId ?>"><input type="hidden" name="action" value="authorize_now"><input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                      <button class="btn btn-sm btn-primary" type="submit">Reenviar <?= fiscal_h($docLabel) ?></button>
                    </form>
                  <?php endif; ?>
                <?php endif; ?>
              </div>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table></div>
</div>


<script>
  function fiscalCopyText(text) {
    text = String(text || '').replace(/\D+/g, '');
    if (!text) return Promise.reject(new Error('Chave vazia'));
    if (navigator.clipboard && window.isSecureContext) return navigator.clipboard.writeText(text);
    return new Promise(function(resolve, reject){
      try {
        var ta = document.createElement('textarea');
        ta.value = text;
        ta.setAttribute('readonly', '');
        ta.style.position = 'fixed';
        ta.style.opacity = '0';
        ta.style.pointerEvents = 'none';
        document.body.appendChild(ta);
        ta.select();
        ta.setSelectionRange(0, ta.value.length);
        var ok = document.execCommand('copy');
        document.body.removeChild(ta);
        ok ? resolve() : reject(new Error('Falha ao copiar'));
      } catch (err) { reject(err); }
    });
  }
  document.addEventListener('click', function(ev){
    var btn = ev.target.closest('[data-copy-access-key]');
    if (!btn) return;
    ev.preventDefault();
    var original = btn.textContent;
    fiscalCopyText(btn.getAttribute('data-copy-access-key')).then(function(){
      btn.textContent = 'Chave copiada!';
      btn.classList.add('copied');
      window.setTimeout(function(){ btn.textContent = original; btn.classList.remove('copied'); }, 1600);
    }).catch(function(){ alert('Não foi possível copiar a chave de acesso.'); });
  });

  document.addEventListener('submit', function(ev){
    var form = ev.target;
    if (!form || !form.matches('[data-fiscal-cancel-form],[data-fiscal-cce-form]')) return;
    if (form.matches('[data-fiscal-cancel-form]')) {
      var reason = window.prompt('Informe a justificativa do cancelamento (mínimo 15 caracteres):', 'Cancelamento solicitado pelo cliente');
      if (reason === null || reason.trim().length < 15) {
        ev.preventDefault();
        alert('Para cancelar, informe uma justificativa com pelo menos 15 caracteres.');
        return;
      }
      if (!window.confirm('Confirma o cancelamento deste documento fiscal? Após a confirmação da SEFAZ, o pedido será cancelado, o estoque será devolvido e o financeiro/caixa receberá o estorno correspondente.')) {
        ev.preventDefault();
        return;
      }
      var input = form.querySelector('input[name="justification"]');
      if (input) input.value = reason.trim();
    }
    if (form.matches('[data-fiscal-cce-form]')) {
      var txt = window.prompt('Informe o texto da Carta de Correção:', 'Correção de informações complementares, sem alterar valores, impostos, destinatário/remetente ou natureza da operação.');
      if (txt === null || txt.trim().length < 15) {
        ev.preventDefault();
        alert('Informe o texto da Carta de Correção com pelo menos 15 caracteres.');
        return;
      }
      var corr = form.querySelector('input[name="correction"]');
      if (corr) corr.value = txt.trim();
    }
  });
</script>

<?php fiscal_admin_end(); ?>
