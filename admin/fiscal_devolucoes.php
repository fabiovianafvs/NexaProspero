<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = function_exists('fiscal_v10_2_return_company_id') ? (int)fiscal_v10_2_return_company_id($pdo) : (int)COMPANY_ID;
$returnCrossContext = function_exists('fiscal_v10_2_return_is_cross_context') && fiscal_v10_2_return_is_cross_context($pdo);
$returnCompanyName = function_exists('fiscal_v10_entry_company_name') ? fiscal_v10_entry_company_name($pdo, $cid) : ('Empresa #' . $cid);
$err = '';
try { fiscal_nfce_ensure_schema($pdo); fiscal_ensure_document_reference_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }

$q = trim((string)($_GET['q'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$defaultFim = date('Y-m-d');
$defaultIni = date('Y-m-d', strtotime('-9 days'));
$ini = fiscal_xml_date($_GET['ini'] ?? $defaultIni) ?: $defaultIni;
$fim = fiscal_xml_date($_GET['fim'] ?? $defaultFim) ?: $defaultFim;

$where = ["fd.company_id=?", "fd.direction='ENTRADA'", "COALESCE(fd.source_type,'') <> 'COMPRA_XML'"];
$params = [$cid];
if ($q !== '') {
  $like = nexa_search_like($q);
  $where[] = "(fd.access_key LIKE ? OR fd.number LIKE ? OR fd.series LIKE ? OR fd.operation_nature LIKE ? OR fd.source_type LIKE ? OR EXISTS (SELECT 1 FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND (fp.name LIKE ? OR fp.document LIKE ?)) OR EXISTS (SELECT 1 FROM pedidos po WHERE po.company_id=fd.company_id AND po.id=fd.source_id AND UPPER(COALESCE(fd.source_type,''))='DEVOLUCAO_CLIENTE' AND (CAST(po.id AS CHAR) LIKE ? OR CAST(po.numero_pedido AS CHAR) LIKE ? OR po.nome_cliente LIKE ?)))";
  array_push($params, $like, $like, $like, $like, $like, $like, $like, $like, $like, $like);
}
if ($status !== '') { $where[] = 'fd.status=?'; $params[] = $status; }
if ($ini !== '') { $where[] = 'DATE(fd.issue_datetime) >= ?'; $params[] = $ini; }
if ($fim !== '') { $where[] = 'DATE(fd.issue_datetime) <= ?'; $params[] = $fim; }

$rows = [];
try {
  $sql = "SELECT fd.*,
                 (SELECT GROUP_CONCAT(DISTINCT NULLIF(fp.name,'') ORDER BY fp.role SEPARATOR ' | ')
                    FROM fiscal_document_participants fp
                   WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id) AS participant_names,
                 (SELECT GROUP_CONCAT(DISTINCT NULLIF(fp.document,'') ORDER BY fp.role SEPARATOR ' | ')
                    FROM fiscal_document_participants fp
                   WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id) AS participant_docs,
                 (SELECT COUNT(*) FROM fiscal_document_references fr WHERE fr.company_id=fd.company_id AND fr.fiscal_document_id=fd.id) AS ref_count,
                 (SELECT COALESCE(NULLIF(po.numero_pedido,0),po.id) FROM pedidos po WHERE po.company_id=fd.company_id AND po.id=fd.source_id LIMIT 1) AS source_order_number
            FROM fiscal_documents fd
           WHERE " . implode(' AND ', $where) . "
        ORDER BY fd.issue_datetime DESC, fd.created_at DESC, fd.id DESC
           LIMIT 500";
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

$totalDocs = count($rows);
$totalValue = array_sum(array_map(static fn($r) => (float)($r['total_document'] ?? 0), $rows));
$returnTo = 'fiscal_devolucoes.php';
$queryString = (string)($_SERVER['QUERY_STRING'] ?? '');
if ($queryString !== '') $returnTo .= '?' . $queryString;
if (function_exists('fiscal_v10_2_return_context_url')) $returnTo = fiscal_v10_2_return_context_url($returnTo);

fiscal_admin_start('Devolução / Outras Entradas', 'fiscal_devolucoes');
?>
<style>
  .devolucao-page-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:nowrap;margin:8px 0 14px}.devolucao-page-actions .btn{white-space:nowrap}
  .devolucao-search-form{display:grid;grid-template-columns:minmax(260px,2.4fr) minmax(150px,.9fr) minmax(135px,.75fr) minmax(135px,.75fr) auto;gap:10px;align-items:end}
  .devolucao-search-form .fiscal-filter-actions{justify-content:flex-end;align-items:end;flex-wrap:nowrap}.devolucao-search-form .fiscal-filter-actions .btn{white-space:nowrap}
  .devolucao-grid{min-width:1080px}.devolucao-grid .fiscal-actions-head,.devolucao-grid .fiscal-actions-col{width:200px;min-width:200px;max-width:200px}
  .devolucao-grid .fiscal-actions-col{vertical-align:top;padding:10px}.devolucao-grid .fiscal-actions-col .fiscal-inline-actions{display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:6px}
  .devolucao-grid .fiscal-actions-col form{display:block!important;margin:0}.devolucao-grid .fiscal-actions-col .btn{display:flex;width:100%;min-height:34px;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
  .devolucao-grid .fiscal-cell-wrap,.devolucao-status-help{display:block;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word}
  .devolucao-status-help{font-size:12px;line-height:1.35;color:#64748b;margin-top:5px}
  @media(max-width:900px){.devolucao-search-form{grid-template-columns:repeat(2,minmax(0,1fr))}.devolucao-search-form .devolucao-search-query{grid-column:1/-1}.devolucao-search-form .fiscal-filter-actions{grid-column:1/-1}}
  @media(max-width:680px){.devolucao-page-actions{flex-wrap:wrap}.devolucao-page-actions .btn{flex:1 1 auto}.devolucao-search-form{grid-template-columns:1fr}.devolucao-search-form .devolucao-search-query,.devolucao-search-form .fiscal-filter-actions{grid-column:auto}.devolucao-search-form .fiscal-filter-actions{justify-content:stretch}.devolucao-search-form .fiscal-filter-actions .btn{flex:1}.devolucao-grid{min-width:0}.devolucao-grid .fiscal-actions-head,.devolucao-grid .fiscal-actions-col{width:auto;min-width:0;max-width:none}.devolucao-grid .fiscal-actions-col .fiscal-inline-actions{width:100%;max-width:230px;margin-left:auto}}
</style>
<div class="devolucao-page-actions" aria-label="Ações da página">
  <a class="btn" href="<?= fiscal_h(function_exists('fiscal_v10_2_return_context_url') ? fiscal_v10_2_return_context_url('fiscal_devolucoes.php') : 'fiscal_devolucoes.php') ?>" aria-current="page">Devolução / Outras Entradas</a>
  <a class="btn" href="<?= fiscal_h(function_exists('fiscal_v10_2_return_context_url') ? fiscal_v10_2_return_context_url('fiscal_devolucao_cliente.php?origem=pedido') : 'fiscal_devolucao_cliente.php?origem=pedido') ?>">↩ Devolver pedido sem nota</a>
  <a class="btn btn-primary" href="<?= fiscal_h(function_exists('fiscal_v10_2_return_context_url') ? fiscal_v10_2_return_context_url('fiscal_devolucao_cliente.php') : 'fiscal_devolucao_cliente.php') ?>">+ Nova devolução de cliente</a>
</div>
<?php
fiscal_flash();
if ($returnCrossContext) {
  echo '<div class="msg ok"><strong>V10.2 · Devoluções Multiempresa:</strong> listagem e criação estão operando em ' . fiscal_h($returnCompanyName) . '. O retorno de estoque pode ser gravado nesta empresa; edição/transmissão/consulta SEFAZ da pré-NF-e continuam bloqueadas no contexto F11.</div>';
}
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-grid-3">
  <div class="fiscal-kpi"><span>Documentos encontrados</span><strong><?= (int)$totalDocs ?></strong></div>
  <div class="fiscal-kpi"><span>Total listado</span><strong>R$ <?= number_format((float)$totalValue, 2, ',', '.') ?></strong></div>
  <div class="fiscal-kpi"><span>Pré-NF-e pendentes</span><strong><?= (int)count(array_filter($rows, static fn($r) => !in_array(strtoupper((string)($r['status'] ?? '')), ['AUTORIZADA','CANCELADA','INTERNA'], true))) ?></strong></div>
</div>

<div class="fiscal-card fiscal-filter-card devolucao-filter-card">
  <form method="get" id="devolucaoSearchForm" class="fiscal-form devolucao-search-form">
    <div class="devolucao-search-query"><label>Pesquisar cliente/fornecedor</label><input name="q" value="<?= fiscal_h($q) ?>" placeholder="Cliente, fornecedor, CPF/CNPJ, número, série, chave ou operação"></div>
    <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach (['PRE_NFE'=>'Pré-NF-e','INTERNA'=>'Devolução interna','ASSINADA'=>'Assinada','AUTORIZADA'=>'Autorizada','REJEITADA'=>'Rejeitada','ERRO_AUTORIZACAO'=>'Erro autorização','CANCELADA'=>'Cancelada'] as $k=>$v): ?><option value="<?= fiscal_h($k) ?>" <?= $status===$k?'selected':'' ?>><?= fiscal_h($v) ?></option><?php endforeach; ?></select></div>
    <div><label>Emissão de</label><input type="date" name="ini" value="<?= fiscal_h($ini) ?>"></div>
    <div><label>Emissão até</label><input type="date" name="fim" value="<?= fiscal_h($fim) ?>"></div>
    <div class="fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?= fiscal_h(function_exists('fiscal_v10_2_return_context_url') ? fiscal_v10_2_return_context_url('fiscal_devolucoes.php') : 'fiscal_devolucoes.php') ?>">Limpar</a></div>
  </form>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head"><h2>Devoluções e outras entradas</h2><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div>
  <div class="table-wrap">
    <?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><table class="fiscal-table fiscal-grid-table devolucao-grid"<?=nexa_grid_auto_attrs($pdo,'auto_fiscal_devolucoes')?>>
      <colgroup>
        <col style="width:18%">
        <col style="width:17%">
        <col style="width:14%">
        <col style="width:9%">
        <col style="width:10%">
        <col style="width:19%">
        <col style="width:7%">
        <col style="width:200px">
      </colgroup>
      <thead>
        <tr>
          <th>Documento</th>
          <th>Cliente/Fornecedor</th>
          <th>Operação</th>
          <th>Data</th>
          <th>Total</th>
          <th>Status</th>
          <th>Referências</th>
          <th class="fiscal-actions-head">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php if (!$rows): ?><tr><td colspan="8" style="text-align:center;color:#64748b">Nenhuma devolução ou outra entrada localizada.</td></tr><?php endif; ?>
      <?php foreach ($rows as $d):
        $statusDoc = strtoupper((string)($d['status'] ?? ''));
        $isDevolucao = strtoupper((string)($d['source_type'] ?? '')) === 'DEVOLUCAO_CLIENTE';
        $isInternalReturn = $isDevolucao && (string)($d['model'] ?? '') === '00' && $statusDoc === 'INTERNA';
        $docLabel = $isInternalReturn ? 'Devolução interna' : fiscal_doc_model_label((string)($d['model'] ?? '55'));
        $canTransmit = !$isInternalReturn && !in_array($statusDoc, ['AUTORIZADA','CANCELADA'], true);
        $sourceOrderNo = (int)($d['source_order_number'] ?? 0);
        $sefazMessage = trim((string)($d['sefaz_status_code'] ?? '') . ' ' . (string)($d['sefaz_status_message'] ?? ''));
      ?>
        <tr>
          <td data-label="Documento" class="primary">
            <strong class="fiscal-cell-wrap"><?= $isInternalReturn ? fiscal_h('Devolução interna · Pedido #' . ($sourceOrderNo > 0 ? $sourceOrderNo : (int)($d['source_id'] ?? 0))) : fiscal_h($docLabel . ' ' . trim((string)($d['series'] ?? '') . '/' . (string)($d['number'] ?? ''))) ?></strong>
            <div class="fiscal-muted fiscal-cell-wrap">#<?= (int)$d['id'] ?> <?= $isInternalReturn ? 'Controle interno sem nota fiscal' : fiscal_h($d['source_type'] ?? '') ?></div>
            <?php if (!empty($d['access_key'])): ?><div class="fiscal-muted fiscal-cell-wrap" style="font-size:12px"><?= fiscal_h($d['access_key']) ?></div><?php endif; ?>
          </td>
          <td data-label="Cliente/Fornecedor">
            <strong class="fiscal-cell-wrap"><?= fiscal_h($d['participant_names'] ?: '-') ?></strong>
            <div class="fiscal-muted fiscal-cell-wrap"><?= fiscal_h($d['participant_docs'] ?: '') ?></div>
          </td>
          <td data-label="Operação"><span class="fiscal-cell-wrap"><?= fiscal_h($d['operation_nature'] ?? '') ?></span></td>
          <td data-label="Data"><?= fiscal_h(fiscal_date_br(substr((string)($d['issue_datetime'] ?? ''),0,10))) ?></td>
          <td data-label="Total">R$ <?= number_format((float)($d['total_document'] ?? 0), 2, ',', '.') ?></td>
          <td data-label="Status">
            <span class="fiscal-badge <?= in_array($statusDoc, ['AUTORIZADA','INTERNA'], true) ? '' : 'off' ?>"><?= fiscal_h($isInternalReturn ? 'INTERNA' : ($d['status'] ?? '')) ?></span>
            <?php if ($sefazMessage !== ''): ?><div class="devolucao-status-help" title="<?= fiscal_h($sefazMessage) ?>"><?= fiscal_h($sefazMessage) ?></div><?php endif; ?>
          </td>
          <td data-label="Referências"><?= $isInternalReturn ? ('Pedido #' . (int)($sourceOrderNo > 0 ? $sourceOrderNo : ($d['source_id'] ?? 0)) . '<div class="fiscal-muted">Sem nota fiscal</div>') : ((int)($d['ref_count'] ?? 0) . ' nota(s)') ?></td>
          <td data-label="Ações" class="fiscal-actions-col">
            <div class="fiscal-inline-actions">
              <?php if ($isInternalReturn): ?>
                <span class="fiscal-badge off">Controle interno</span>
                <div class="devolucao-status-help">Estoque e devolução financeira/Vale registrados pelo pedido. Não existe nota de referência, XML ou ação na SEFAZ.</div>
              <?php elseif ($returnCrossContext): ?>
                <span class="fiscal-badge off">Emissão fiscal protegida no F11</span>
                <div class="devolucao-status-help">Para editar, transmitir, consultar SEFAZ ou imprimir esta pré-NF-e, acesse diretamente a empresa <?= fiscal_h($returnCompanyName) ?>. A V10.2 homologa somente o lançamento/retorno de estoque.</div>
              <?php else: ?>
                <a class="btn btn-sm" href="fiscal_nfce_editar.php?id=<?= (int)$d['id'] ?>">Editar dados</a>
                <?php if ($canTransmit): ?>
                  <form method="post" action="fiscal_nfce_acao.php" onsubmit="return confirm('Gerar/transmitir a nota de devolução agora?');">
                    <input type="hidden" name="action" value="authorize_now">
                    <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                    <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                    <button class="btn btn-primary btn-sm"><?= $isDevolucao ? 'Gerar nota de devolução' : 'Gerar NF-e' ?></button>
                  </form>
                <?php endif; ?>
                <a class="btn btn-sm" href="fiscal_nfce_acao.php?action=download_xml&doc_id=<?= (int)$d['id'] ?>&return_to=<?= rawurlencode($returnTo) ?>">Baixar XML</a>
                <form method="post" action="fiscal_nfce_acao.php">
                  <input type="hidden" name="action" value="consult_status">
                  <input type="hidden" name="doc_id" value="<?= (int)$d['id'] ?>">
                  <input type="hidden" name="return_to" value="<?= fiscal_h($returnTo) ?>">
                  <button class="btn btn-sm">Consultar situação</button>
                </form>
                <?php if ($statusDoc === 'AUTORIZADA'): ?><a class="btn btn-sm" data-no-processing target="_blank" href="fiscal_nfce_imprimir.php?doc_id=<?= (int)$d['id'] ?>&return_to=<?= rawurlencode($returnTo) ?>">Imprimir <?= fiscal_h($docLabel) ?></a><?php endif; ?>
              <?php endif; ?>
            </div>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php fiscal_admin_end(); ?>
