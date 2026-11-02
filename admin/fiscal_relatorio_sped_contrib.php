<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';

$cid = (int)COMPANY_ID;
fiscal_xml_ensure_schema($pdo);
$err = '';
[$ini, $fim, $periodLabel] = fiscal_report_period($_POST ?: ['ini'=>date('d-m-Y', strtotime('first day of this month')), 'fim'=>date('d-m-Y')]);
$entries = fiscal_report_entries($pdo, $cid, $ini, $fim);
$nfces = fiscal_report_nfce_documents($pdo, $cid, $ini, $fim, true);
try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    $content = fiscal_sped_contrib_generate_content($pdo, $cid, $ini, $fim, $_POST);
    fiscal_report_txt_download('SPED_CONTRIBUICOES_' . str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim) . '.txt', $content);
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

fiscal_admin_start('Geração EFD Contribuições', 'fiscal_sped_contrib');
fiscal_page_header('Geração EFD Contribuições', 'Geração inicial de PIS/COFINS com entradas e CSTs convertidos. O filtro permite gerar de um único dia ou de qualquer intervalo.', [
  ['label'=>'Relatórios Fiscais', 'href'=>'fiscal_relatorios.php', 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_sped_contrib');
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <h2>Filtro</h2>
  <form method="post" class="fiscal-form fiscal-form-grid fiscal-report-export-form" style="align-items:end" data-no-processing>
    <input type="hidden" name="action" value="export">
    <input type="hidden" name="include_nfce" value="1">
    <div><label>Data inicial</label><input name="ini" value="<?= fiscal_h(fiscal_date_br($ini)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Data final</label><input name="fim" value="<?= fiscal_h(fiscal_date_br($fim)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Tipo de escrituração</label><select name="tipo_escrituracao"><option value="0">Original</option><option value="1">Retificadora</option></select></div>
    <div><label>Tipo do item padrão</label><select name="tipo_item"><option value="00">Mercadoria para Revenda</option><option value="09">Serviços</option><option value="07">Material de uso e consumo</option></select></div>
    <div><label>Indicador da atividade preponderante</label><select name="activity"><option value="1">Atividade de comércio</option><option value="0">Industrial ou equiparado</option><option value="2">Serviços</option></select></div>
    <div><label>Versão do layout</label><select name="layout"><option value="006">006</option><option value="005">005</option></select></div>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="include_nfce_visual" value="1" checked disabled> Incluir NFC-e modelo 65 em C100/C175</label>
    <div class="span-3 fiscal-form-actions"><button class="btn btn-primary">Exportar SPED Contribuições</button></div>
  </form>
</div>
<div class="fiscal-card"><h2>Prévia do período</h2><p class="fiscal-muted">Período: <?= fiscal_h($periodLabel) ?> • Entradas consideradas: <?= count($entries) ?> • NFC-e consideradas: <?= count($nfces) ?>. A NFC-e modelo 65 é gerada como C100/C175 por CST/CFOP/alíquotas.</p></div>
<script>
(function(){document.querySelectorAll('input[name="ini"],input[name="fim"]').forEach(function(el){el.addEventListener('input',function(){let v=el.value.replace(/\D/g,'').slice(0,8);if(v.length>4)v=v.slice(0,2)+'-'+v.slice(2,4)+'-'+v.slice(4);else if(v.length>2)v=v.slice(0,2)+'-'+v.slice(2);el.value=v;});});})();
</script>
<?php fiscal_admin_end(); ?>
