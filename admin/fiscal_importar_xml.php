<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = fiscal_v10_entry_company_id($pdo);
$entryCrossContext = fiscal_v10_entry_is_cross_context($pdo);
$entryCompanyName = fiscal_v10_entry_company_name($pdo, $cid);
fiscal_xml_ensure_schema($pdo);
$results = [];
$err = '';

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $files = $_FILES['xml_files'] ?? null;
    if (!$files || empty($files['name'])) throw new RuntimeException('Selecione pelo menos um arquivo XML.');
    $names = is_array($files['name']) ? $files['name'] : [$files['name']];
    $count = count($names);
    for ($i=0; $i<$count; $i++) {
      $name = (string)($files['name'][$i] ?? 'arquivo.xml');
      $tmp = (string)($files['tmp_name'][$i] ?? '');
      $error = (int)($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
      if ($error === UPLOAD_ERR_NO_FILE) continue;
      if ($error !== UPLOAD_ERR_OK || $tmp === '') {
        $results[] = ['ok'=>false, 'name'=>$name, 'message'=>'Falha no upload do arquivo.'];
        continue;
      }
      try {
        if ($entryCrossContext) {
          $data = fiscal_xml_parse_file($tmp, $name);
          fiscal_v10_assert_xml_recipient($pdo, $cid, $data);
          $xmlId = fiscal_xml_import_data($pdo, $cid, $data);
          $r = ['id'=>$xmlId, 'number'=>(string)($data['number'] ?? ''), 'supplier_name'=>(string)($data['supplier_name'] ?? ''), 'total_invoice'=>(float)($data['total_invoice'] ?? 0)];
        } else {
          $r = fiscal_xml_import_file($pdo, $cid, $tmp, $name);
        }
        $results[] = ['ok'=>true, 'name'=>$name, 'id'=>$r['id'], 'message'=>'XML importado: NF-e ' . ($r['number'] ?: '') . ' - ' . ($r['supplier_name'] ?: '') . ' - R$ ' . number_format((float)$r['total_invoice'], 2, ',', '.')];
      } catch (Throwable $e) {
        $results[] = ['ok'=>false, 'name'=>$name, 'message'=>$e->getMessage()];
      }
    }
    if (!$results) throw new RuntimeException('Nenhum arquivo foi processado.');
    $oks = array_values(array_filter($results, static fn($r) => !empty($r['ok']) && !empty($r['id'])));
    if (count($results) === 1 && count($oks) === 1) {
      header('Location: ' . fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$oks[0]['id'] . '&ok=' . rawurlencode('XML importado. Confira os dados da nota, vincule os produtos, ajuste conversões e gere a entrada.') . '#itensXml'));
      exit;
    }
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

// Filtros da relação de XMLs importados.
// Ao abrir a tela, o período padrão fica entre 10 dias atrás e hoje.
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$supplierFilter = trim((string)($_GET['supplier'] ?? ''));
$keyFilter = preg_replace('/\D+/', '', (string)($_GET['access_key'] ?? ''));

if ($dateFrom === '') $dateFrom = date('Y-m-d', strtotime('-10 days'));
if ($dateTo === '') $dateTo = date('Y-m-d');

$recent = [];
try {
  $sql = 'SELECT * FROM fiscal_xml_imports WHERE company_id=?';
  $params = [$cid];
  if ($dateFrom !== '') { $sql .= ' AND issue_date >= ?'; $params[] = $dateFrom; }
  if ($dateTo !== '') { $sql .= ' AND issue_date <= ?'; $params[] = $dateTo; }
  if ($supplierFilter !== '') {
    $like = nexa_search_like($supplierFilter);
    $sql .= ' AND (supplier_name LIKE ? OR supplier_doc LIKE ? OR supplier_ie LIKE ?)';
    array_push($params, $like, $like, $like);
  }
  if ($keyFilter !== '') {
    $sql .= ' AND access_key LIKE ?';
    $params[] = nexa_search_like($keyFilter);
  }
  $sql .= ' ORDER BY issue_date DESC, created_at DESC, id DESC LIMIT 200';
  $st = $pdo->prepare($sql);
  $st->execute($params);
  $recent = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {}

fiscal_admin_start('Entradas - Importar XML', 'fiscal_importar_xml');
$headerActions = $entryCrossContext ? [
  ['label'=>'Entradas', 'href'=>fiscal_v10_entry_context_url('fiscal_entrada_notas.php'), 'class'=>'btn btn-primary'],
  ['label'=>'RADAR (consulta)', 'href'=>fiscal_v10_entry_context_url('fiscal_radar_xml.php'), 'class'=>'btn'],
] : [
  ['label'=>'Entradas', 'href'=>'fiscal_radar_xml.php', 'class'=>'btn btn-primary'],
  ['label'=>'+ Devolução de cliente', 'href'=>'fiscal_devolucao_cliente.php', 'class'=>'btn'],
  ['label'=>'+ Entrada manual', 'href'=>'fiscal_nota_manual.php', 'class'=>'btn'],
  ['label'=>'Conversões XML', 'href'=>'fiscal_xml_conversoes.php', 'class'=>'btn'],
];
fiscal_page_header('Importar XML de NF-e' . ($entryCrossContext ? ' · ' . $entryCompanyName : ''), '', $headerActions);
fiscal_flash();
if ($entryCrossContext) echo '<div class="msg ok"><strong>V10 Multiempresa:</strong> o XML deve ter como destinatário o CNPJ fiscal de <strong>' . fiscal_h($entryCompanyName) . '</strong>. XML de outra empresa será bloqueado. O Contas a Pagar permanece fora desta etapa.</div>';
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<style>
.xml-import-compact{display:grid;gap:10px;margin-bottom:12px}
.xml-flow-card,.xml-upload-card,.xml-filter-card{padding:12px 14px}
.xml-flow-line{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
.xml-flow-line h2{margin:0 8px 0 0;font-size:16px;white-space:nowrap}
.xml-flow-step{display:flex;align-items:center;gap:6px;border:1px solid #dbe4f0;background:#f8fbff;border-radius:999px;padding:6px 9px;color:#475569;font-size:12px;line-height:1.2}
.xml-flow-step b{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:999px;background:#0b1f5b;color:#fff;font-size:11px;flex:0 0 20px}
.xml-upload-head{display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:8px}
.xml-upload-head h2{margin:0;font-size:18px}
.xml-upload-head .fiscal-muted{margin:0;font-size:12px}
.xml-upload-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end}
.xml-upload-row label{display:block;margin-bottom:5px}
.xml-upload-row input[type=file]{width:100%;box-sizing:border-box}
.xml-upload-row .btn{min-height:42px;white-space:nowrap}
.xml-filter-grid{display:grid;grid-template-columns:145px 145px minmax(190px,.9fr) minmax(270px,1.35fr) auto auto;gap:9px;align-items:end}
.xml-filter-grid label{display:block;font-size:11px;font-weight:900;color:#334155;margin-bottom:5px}
.xml-filter-grid input{width:100%;box-sizing:border-box}
.xml-filter-grid .btn{min-height:42px;white-space:nowrap}
.xml-list-count{font-size:12px;color:#64748b;font-weight:800}
@media(max-width:1000px){.xml-filter-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.xml-filter-grid .xml-wide{grid-column:span 2}.xml-upload-row{grid-template-columns:1fr}}
@media(max-width:620px){.xml-filter-grid{grid-template-columns:1fr}.xml-filter-grid .xml-wide{grid-column:auto}.xml-flow-line{align-items:flex-start}.xml-flow-line h2{width:100%}}
</style>

<div class="xml-import-compact">
  <div class="fiscal-card xml-flow-card">
    <div class="xml-flow-line">
      <h2>Fluxo recomendado</h2>
      <span class="xml-flow-step"><b>1</b> Importar XML</span>
      <span class="xml-flow-step"><b>2</b> Conferir fornecedor, datas e parcelas</span>
      <span class="xml-flow-step"><b>3</b> Vincular/cadastrar produtos</span>
      <span class="xml-flow-step"><b>4</b> Ajustar conversão e tributação</span>
      <span class="xml-flow-step"><b>5</b> Gerar entrada</span>
    </div>
  </div>

  <div class="fiscal-card xml-upload-card">
    <div class="xml-upload-head">
      <div>
        <h2>Enviar arquivos XML</h2>
        <div class="fiscal-muted">Selecione um ou vários XMLs autorizados de NF-e.</div>
      </div>
    </div>
    <form method="post" enctype="multipart/form-data">
      <div class="xml-upload-row">
        <div><label>XML(s) da NF-e</label><input type="file" name="xml_files[]" accept=".xml,text/xml,application/xml" multiple required></div>
        <button class="btn btn-primary">Importar XML</button>
      </div>
    </form>
  </div>
</div>

<?php if ($results): ?>
<div class="fiscal-card">
  <h2>Resultado da importação</h2>
  <?php foreach ($results as $r): ?>
    <div class="msg <?= !empty($r['ok']) ? 'ok' : 'err' ?>" style="margin-bottom:8px">
      <strong><?= fiscal_h($r['name'] ?? '') ?>:</strong> <?= fiscal_h($r['message'] ?? '') ?>
      <?php if (!empty($r['ok']) && !empty($r['id'])): ?>
        <a class="btn btn-sm" style="margin-left:8px" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$r['id'])) ?>">Ver / Gerar entrada</a>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
<?php endif; ?>

<div class="fiscal-card">
  <div class="fiscal-list-head"><div><h2 style="margin-bottom:3px">XMLs importados</h2><div class="xml-list-count"><?= count($recent) ?> registro(s) no filtro atual</div></div><a class="btn btn-sm" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_radar_xml.php')) ?>">Ver entradas</a></div>
  <form method="get" class="xml-filter-card" style="padding-left:0;padding-right:0">
    <div class="xml-filter-grid">
      <div><label>De</label><input type="date" name="date_from" value="<?= fiscal_h($dateFrom) ?>"></div>
      <div><label>Até</label><input type="date" name="date_to" value="<?= fiscal_h($dateTo) ?>"></div>
      <div class="xml-wide"><label>Fornecedor</label><input type="text" name="supplier" value="<?= fiscal_h($supplierFilter) ?>" placeholder="Nome, CNPJ/CPF ou IE"></div>
      <div class="xml-wide"><label>Chave de acesso</label><input type="text" name="access_key" value="<?= fiscal_h((string)($_GET['access_key'] ?? '')) ?>" placeholder="Digite a chave ou parte dela" inputmode="numeric"></div>
      <button class="btn btn-primary" type="submit">Filtrar</button>
      <a class="btn" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_importar_xml.php')) ?>">Limpar</a>
    </div>
  </form>
  <div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Data</th><th>NF-e</th><th>Fornecedor</th><th>Total</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php if (!$recent): ?><tr><td colspan="6" style="text-align:center;color:#64748b">Nenhum XML encontrado para os filtros informados.</td></tr><?php endif; ?>
  <?php foreach ($recent as $x): ?>
    <tr>
      <td data-label="Data"><?= fiscal_h(fiscal_date_br($x['issue_date'] ?? '')) ?></td>
      <td data-label="NF-e" class="primary"><strong><?= fiscal_h(($x['series'] ?? '') . '/' . ($x['number'] ?? '')) ?></strong><div class="fiscal-muted"><?= fiscal_h($x['access_key'] ?? '') ?></div></td>
      <td data-label="Fornecedor"><?= fiscal_h($x['supplier_name'] ?? '') ?></td>
      <td data-label="Total">R$ <?= number_format((float)($x['total_invoice'] ?? 0), 2, ',', '.') ?></td>
      <td data-label="Status"><span class="fiscal-badge <?= ($x['status'] ?? '') === 'ENTRADA_GERADA' ? '' : 'off' ?>"><?= fiscal_h($x['status'] ?? '') ?></span></td>
      <td data-label="Ações"><a class="btn btn-sm" href="<?= fiscal_h(fiscal_v10_entry_context_url('fiscal_xml_entrada.php?id=' . (int)$x['id'])) ?>">Abrir</a></td>
    </tr>
  <?php endforeach; ?>
  </tbody></table></div>
</div>
<?php fiscal_admin_end(); ?>
