<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/fiscal_entry_edit_module.php';

$cid = fiscal_v10_entry_company_id($pdo);
$entryCrossContext = fiscal_v10_entry_is_cross_context($pdo);
$entryCompanyName = fiscal_v10_entry_company_name($pdo, $cid);
$err = '';
try { fiscal_xml_ensure_schema($pdo); fiscal_entry_edit_ensure_schema($pdo); } catch (Throwable $e) { $err = $e->getMessage(); }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  try {
    $action = (string)($_POST['action'] ?? '');
    $entryId = (int)($_POST['id'] ?? 0);
    if ($action === 'delete_entry' && $entryId > 0) {
      fiscal_entry_delete($pdo, $cid, $entryId);
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_entrada_notas.php?ok=' . rawurlencode('Nota de entrada excluída.')));
      exit;
    }
  } catch (Throwable $e) { $err = $e->getMessage(); }
}

$fornecedor = trim((string)($_GET['fornecedor'] ?? ''));
$numero = trim((string)($_GET['numero'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));
$defaultIssueFrom = date('Y-m-d', strtotime('-9 days'));
$defaultIssueTo = date('Y-m-d');
$issueFrom = fiscal_xml_date($_GET['issue_from'] ?? '') ?: $defaultIssueFrom;
$issueTo = fiscal_xml_date($_GET['issue_to'] ?? '') ?: $defaultIssueTo;
$entryFrom = fiscal_xml_date($_GET['entry_from'] ?? '') ?: '';
$entryTo = fiscal_xml_date($_GET['entry_to'] ?? '') ?: '';

$where = ['x.company_id = ?'];
$params = [$cid];
if ($fornecedor !== '') {
  $like = nexa_search_like($fornecedor);
  $where[] = '(x.supplier_name LIKE ? OR x.supplier_doc LIKE ?)';
  array_push($params, $like, $like);
}
if ($numero !== '') {
  $digits = preg_replace('/\D+/', '', $numero);
  $where[] = '(CAST(x.number AS CHAR) LIKE ? OR x.access_key LIKE ?)';
  $likeNumber = '%' . ($digits !== '' ? $digits : $numero) . '%';
  array_push($params, $likeNumber, nexa_search_like($numero));
}
if ($status !== '') { $where[] = 'x.status = ?'; $params[] = $status; }
if ($issueFrom !== '') { $where[] = 'x.issue_date >= ?'; $params[] = $issueFrom; }
if ($issueTo !== '') { $where[] = 'x.issue_date <= ?'; $params[] = $issueTo; }
if ($entryFrom !== '') { $where[] = 'COALESCE(x.entry_date,x.issue_date) >= ?'; $params[] = $entryFrom; }
if ($entryTo !== '') { $where[] = 'COALESCE(x.entry_date,x.issue_date) <= ?'; $params[] = $entryTo; }

$rows = [];
try {
  $st = $pdo->prepare('SELECT x.*, o.name AS operation_name FROM fiscal_xml_imports x LEFT JOIN fiscal_operations o ON o.id=x.operation_id AND o.company_id=x.company_id WHERE ' . implode(' AND ', $where) . ' ORDER BY x.issue_date DESC, x.id DESC LIMIT 500');
  $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }

$kpis = ['total'=>count($rows), 'conferencia'=>0, 'geradas'=>0, 'valor'=>0.0];
foreach ($rows as $row) {
  if ((string)($row['status'] ?? '') === 'ENTRADA_GERADA') $kpis['geradas']++;
  else $kpis['conferencia']++;
  $kpis['valor'] += (float)($row['total_invoice'] ?? 0);
}

fiscal_admin_start('Entrada de Notas', 'fiscal_entrada_notas');
fiscal_flash();
if ($entryCrossContext) echo '<div class="msg ok"><strong>V10 Multiempresa:</strong> Entrada de Mercadorias operando em <strong>' . fiscal_h($entryCompanyName) . '</strong>. Nesta etapa o estoque é isolado pelo F11 e o Contas a Pagar não é gerado no contexto cruzado.</div>';
if ($err) echo '<div class="msg err">' . fiscal_h(trim($err)) . '</div>';
?>
<style>
  .entrada-notas-filter-card{background:#fff;border:1px solid #dbe4f0;border-radius:18px;padding:14px;margin:10px 0 14px;box-shadow:0 12px 30px rgba(15,23,42,.04)}
  .entrada-notas-filters{display:flex;flex-wrap:wrap;gap:10px;align-items:end}
  .entrada-notas-filters label{margin:0;font-size:12px;font-weight:800;color:#334155;flex:1 1 145px;min-width:135px}
  .entrada-notas-filters .entrada-fornecedor{flex:2 1 270px;min-width:230px}
  .entrada-notas-filters .entrada-numero{flex:0 1 150px;min-width:130px}
  .entrada-notas-filters input,.entrada-notas-filters select{min-height:42px;width:100%;margin-top:6px;border-radius:14px;box-sizing:border-box}
  .entrada-notas-filter-actions{display:flex;gap:10px;align-items:end;margin-left:auto}
  .entrada-notas-filter-actions .btn{min-height:42px;min-width:86px;display:inline-flex;align-items:center;justify-content:center}
  .entrada-notas-wrap{position:relative;overflow:auto}
  .entrada-notas-record-row td{border-bottom:0}
  .entrada-notas-action-row td{padding:0 12px 14px!important;border-top:0;border-bottom:1px solid #e5edf6}
  .entrada-notas-action-panel{display:flex;align-items:center;justify-content:space-between;gap:14px;background:#f8fbff;border:1px solid #e2e8f0;border-radius:16px;padding:9px 10px;box-sizing:border-box}
  .entrada-notas-action-title{font-size:12px;font-weight:950;color:#64748b;text-transform:uppercase;letter-spacing:.02em;flex:1 1 240px;min-width:220px}
  .entrada-notas-action-buttons{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap;width:auto;margin-left:auto}
  .entrada-notas-action-buttons .btn{white-space:nowrap}
  @media(max-width:900px){.entrada-notas-filters{display:grid;grid-template-columns:1fr}.entrada-notas-filters label,.entrada-notas-filters .entrada-fornecedor,.entrada-notas-filters .entrada-numero{min-width:0;width:100%}.entrada-notas-filter-actions{display:grid;grid-template-columns:1fr 1fr;width:100%;margin-left:0}.entrada-notas-filter-actions .btn{width:100%}.entrada-notas-action-panel{align-items:stretch;flex-direction:column}.entrada-notas-action-title{width:100%;min-width:0}.entrada-notas-action-buttons{width:100%;margin-left:0}.entrada-notas-action-buttons .btn{flex:1 1 160px;justify-content:center}}
</style>

<div class="fiscal-grid-4">
  <div class="fiscal-kpi"><span>Notas no período</span><strong><?= (int)$kpis['total'] ?></strong></div>
  <div class="fiscal-kpi"><span>Em conferência</span><strong><?= (int)$kpis['conferencia'] ?></strong></div>
  <div class="fiscal-kpi"><span>Entradas geradas</span><strong><?= (int)$kpis['geradas'] ?></strong></div>
  <div class="fiscal-kpi"><span>Valor das notas</span><strong>R$ <?= number_format((float)$kpis['valor'], 2, ',', '.') ?></strong></div>
</div>

<div class="entrada-notas-filter-card">
  <form method="get" class="fiscal-form">
    <div class="entrada-notas-filters">
      <label class="entrada-fornecedor">Fornecedor
        <input type="search" name="fornecedor" value="<?= fiscal_h($fornecedor) ?>" placeholder="Nome ou CNPJ/CPF do fornecedor">
      </label>
      <label class="entrada-numero">Número da nota
        <input type="text" name="numero" value="<?= fiscal_h($numero) ?>" placeholder="Número">
      </label>
      <label>Status da entrada
        <select name="status">
          <option value="">Todos</option>
          <option value="IMPORTADO" <?= $status==='IMPORTADO'?'selected':'' ?>>Em conferência</option>
          <option value="ENTRADA_GERADA" <?= $status==='ENTRADA_GERADA'?'selected':'' ?>>Entrada gerada</option>
        </select>
      </label>
      <label>Emissão de
        <input type="date" name="issue_from" value="<?= fiscal_h($issueFrom) ?>">
      </label>
      <label>Emissão até
        <input type="date" name="issue_to" value="<?= fiscal_h($issueTo) ?>">
      </label>
      <label>Entrada de
        <input type="date" name="entry_from" value="<?= fiscal_h($entryFrom) ?>">
      </label>
      <label>Entrada até
        <input type="date" name="entry_to" value="<?= fiscal_h($entryTo) ?>">
      </label>
      <div class="entrada-notas-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_entrada_notas.php')) ?>">Limpar</a></div>
    </div>
  </form>
</div>

<div class="fiscal-card">
  <div class="fiscal-list-head"><div><h2>Notas em conferência e entradas geradas</h2></div><div class="fiscal-inline-actions"><a class="btn btn-primary" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_importar_xml.php')) ?>">Importar XML</a><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div></div>
  <?php require_once __DIR__.'/../config/grid_preferences.php'; echo nexa_grid_assets(); ?><div class="table-wrap entrada-notas-wrap"><table class="fiscal-table"<?=nexa_grid_auto_attrs($pdo,'auto_fiscal_entrada_notas')?>><thead><tr><th>NF-e</th><th>Fornecedor</th><th>Emissão</th><th>Entrada</th><th>Total</th><th>Status</th><th>Operação</th></tr></thead><tbody>
  <?php if (!$rows): ?><tr><td colspan="7" style="text-align:center;color:#64748b">Nenhuma nota encontrada para os filtros informados.</td></tr><?php endif; ?>
  <?php foreach ($rows as $x): ?>
    <tr class="entrada-notas-record-row">
      <td data-label="NF-e" class="primary"><strong>Série <?= fiscal_h($x['series'] ?? '') ?> / Nº <?= fiscal_h($x['number'] ?? '') ?></strong><div class="fiscal-muted" style="font-size:12px"><?= fiscal_h($x['access_key'] ?? '') ?></div></td>
      <td data-label="Fornecedor"><strong><?= fiscal_h($x['supplier_name'] ?? '') ?></strong><div class="fiscal-muted"><?= fiscal_h($x['supplier_doc'] ?? '') ?></div></td>
      <td data-label="Emissão"><?= fiscal_h(fiscal_date_br($x['issue_date'] ?? '')) ?></td>
      <td data-label="Entrada"><?= fiscal_h(fiscal_date_br($x['entry_date'] ?? ($x['issue_date'] ?? ''))) ?></td>
      <td data-label="Total"><strong>R$ <?= number_format((float)($x['total_invoice'] ?? 0), 2, ',', '.') ?></strong><?php if ((string)($x['number'] ?? '') === '0' || (int)($x['include_fiscal_reports'] ?? 1) !== 1): ?><div class="fiscal-muted">Fora do Sintegra/SPED</div><?php endif; ?></td>
      <td data-label="Status"><span class="fiscal-badge <?= ($x['status'] ?? '') === 'ENTRADA_GERADA' ? '' : 'off' ?>"><?= fiscal_h(($x['status'] ?? '') === 'ENTRADA_GERADA' ? 'Entrada gerada' : 'Em conferência') ?></span></td>
      <td data-label="Operação"><?= fiscal_h($x['operation_name'] ?? '') ?></td>
    </tr>
    <tr class="entrada-notas-action-row">
      <td colspan="7">
        <div class="entrada-notas-action-panel">
          <span class="entrada-notas-action-title">Ações da NF-e série <?= fiscal_h($x['series'] ?? '') ?> / nº <?= fiscal_h($x['number'] ?? '') ?></span>
          <div class="entrada-notas-action-buttons">
            <a class="btn btn-sm" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$x['id'] . '#editarEntrada')) ?>">Editar</a>
            <?php if (($x['status'] ?? '') === 'ENTRADA_GERADA'): ?>
              <a class="btn btn-sm" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$x['id'] . '#desfazerEntrada')) ?>">Desfazer Nota</a>
            <?php else: ?>
              <a class="btn btn-primary btn-sm" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$x['id'] . '#gerarEntrada')) ?>">Gerar entrada</a>
              <form method="post" style="display:inline" onsubmit="return confirm('Excluir esta nota de entrada? Esta ação remove a conferência e os itens importados.');">
                <input type="hidden" name="action" value="delete_entry"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
                <button class="btn btn-sm" type="submit">Excluir</button>
              </form>
            <?php endif; ?>
          </div>
        </div>
      </td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>

<?php fiscal_admin_end(); ?>
