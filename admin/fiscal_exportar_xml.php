<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/fiscal_xml_export.php';

$cid = (int)COMPANY_ID;
$err = '';
$catalog = fiscal_xml_export_category_catalog();
$input = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : [
  'ini' => date('Y-m-01'),
  'fim' => date('Y-m-d'),
  'types' => ['nfce','nfe'],
  'series' => '',
];

try {
  $options = fiscal_xml_export_options($input);
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    $result = fiscal_xml_export_collect($pdo, $cid, $options);
    if (empty($result['entries'])) {
      throw new RuntimeException('Nenhum XML foi localizado no período, série e tipos selecionados.');
    }
    $seriesName = $options['series'] !== '' ? '_SERIE_' . fiscal_xml_export_safe_part($options['series']) : '_TODAS_SERIES';
    $filename = 'XML_FISCAIS_' . str_replace('-', '', $options['ini']) . '_' . str_replace('-', '', $options['fim']) . $seriesName . '.zip';
    fiscal_xml_export_download($filename, $result['entries']);
  }
} catch (Throwable $e) {
  $err = $e->getMessage();
  try { $options = fiscal_xml_export_options($input); } catch (Throwable $ignored) {
    $options = ['types'=>['nfce','nfe'],'ini'=>date('Y-m-01'),'fim'=>date('Y-m-d'),'series'=>''];
  }
}

$seriesRows = fiscal_xml_export_series($pdo, $cid);

fiscal_admin_start('Exportar XMLs', 'fiscal_exportar_xml');
fiscal_page_header('Exportar XMLs', 'Selecione os modelos, situações, período e série para gerar um único arquivo ZIP com os XMLs fiscais.', [
  ['label'=>'Relatórios Fiscais', 'href'=>'fiscal_relatorios.php', 'class'=>'btn'],
  ['label'=>'Documentos fiscais', 'href'=>'fiscal_documentos.php', 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_exportar_xml');
if ($err !== '') echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <h2>Filtros da exportação</h2>
  <form method="post" class="fiscal-form" data-no-processing>
    <input type="hidden" name="action" value="export">

    <div class="fiscal-section">
      <h3>Tipos de XML</h3>
      <div class="fiscal-export-options">
        <?php foreach ($catalog as $key => $cfg): ?>
          <label class="fiscal-export-option">
            <input type="checkbox" name="types[]" value="<?= fiscal_h($key) ?>" <?= in_array($key, (array)$options['types'], true) ? 'checked' : '' ?>>
            <span>
              <strong><?= fiscal_h($cfg['label']) ?></strong>
              <small>Modelo <?= fiscal_h($cfg['model']) ?> • <?= fiscal_h($cfg['status'] === 'AUTORIZADA' ? 'Autorizadas' : ($cfg['status'] === 'CANCELADA' ? 'Canceladas' : 'Inutilizadas')) ?></small>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="fiscal-form-grid" style="align-items:end">
      <div><label>Data inicial</label><input type="date" name="ini" value="<?= fiscal_h($options['ini']) ?>" required></div>
      <div><label>Data final</label><input type="date" name="fim" value="<?= fiscal_h($options['fim']) ?>" required></div>
      <div>
        <label>Série</label>
        <select name="series">
          <option value="">Todas as séries</option>
          <?php foreach ($seriesRows as $serie): ?><option value="<?= fiscal_h($serie) ?>" <?= (string)$options['series'] === (string)$serie ? 'selected' : '' ?>>Série <?= fiscal_h($serie) ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="span-3 fiscal-form-actions"><button class="btn btn-primary" type="submit">Exportar XMLs em ZIP</button></div>
    </div>
  </form>
</div>

<div class="fiscal-grid">
  <div class="fiscal-card">
    <h2>Organização do ZIP</h2>
    <p class="fiscal-muted">Os arquivos são separados em pastas de NF-e e NFC-e, divididas entre autorizadas, canceladas e inutilizadas.</p>
  </div>
  <div class="fiscal-card">
    <h2>Documentos cancelados</h2>
    <p class="fiscal-muted">Quando disponível, o sistema gera o XML processado com o evento de cancelamento. Caso contrário, inclui o XML da nota e o XML do evento separadamente.</p>
  </div>
  <div class="fiscal-card">
    <h2>Numerações inutilizadas</h2>
    <p class="fiscal-muted">A exportação considera eventos de inutilização gravados no período. Se o pedido e o retorno estiverem disponíveis, tenta gerar o XML processado de inutilização.</p>
  </div>
  <div class="fiscal-card">
    <h2>Filtro por série</h2>
    <p class="fiscal-muted">Escolha uma série específica ou mantenha “Todas as séries” para reunir todos os documentos dos tipos marcados.</p>
  </div>
</div>
<style>
.fiscal-export-options{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}
.fiscal-export-option{display:flex;gap:10px;align-items:flex-start;border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:13px;cursor:pointer;min-height:78px}
.fiscal-export-option:hover{border-color:#60a5fa;background:#f8fbff}
.fiscal-export-option input{width:19px!important;min-height:19px!important;margin-top:2px}
.fiscal-export-option span{display:grid;gap:4px}.fiscal-export-option strong{color:var(--admin-primary);font-size:14px}.fiscal-export-option small{color:#64748b;line-height:1.3}
@media(max-width:900px){.fiscal-export-options{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:560px){.fiscal-export-options{grid-template-columns:1fr}}
</style>
<?php fiscal_admin_end(); ?>
