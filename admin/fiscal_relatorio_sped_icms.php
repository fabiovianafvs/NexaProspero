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
    $content = fiscal_sped_icms_generate_content($pdo, $cid, $ini, $fim, $_POST);
    fiscal_report_txt_download('SPED_ICMS_IPI_' . str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim) . '.txt', $content);
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

fiscal_admin_start('Geração EFD ICMS/IPI', 'fiscal_sped_icms');
fiscal_page_header('Geração EFD ICMS/IPI', 'Estrutura inicial do SPED ICMS/IPI com base nas entradas cadastradas. O filtro permite gerar de um único dia ou de qualquer intervalo.', [
  ['label'=>'Relatórios Fiscais', 'href'=>'fiscal_relatorios.php', 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_sped_icms');
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <h2>Filtro</h2>
  <form method="post" class="fiscal-form fiscal-form-grid fiscal-report-export-form" style="align-items:end" data-no-processing>
    <input type="hidden" name="action" value="export">
    <input type="hidden" name="include_nfce" value="1">
    <div><label>Data inicial</label><input name="ini" value="<?= fiscal_h(fiscal_date_br($ini)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Data final</label><input name="fim" value="<?= fiscal_h(fiscal_date_br($fim)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Tipo do item padrão</label><select name="tipo_item"><option value="00">Mercadoria para Revenda</option><option value="01">Matéria-prima</option><option value="07">Material de uso e consumo</option><option value="09">Serviços</option></select></div>
    <div><label>Finalidade</label><select name="finalidade"><option value="0">0 - Remessa de Arquivo Original</option><option value="1">1 - Remessa de Arquivo Substituto</option></select></div>
    <div><label>Versão do layout</label><select name="layout"><option value="020">020</option><option value="019">019</option></select></div>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="include_nfce_visual" value="1" checked disabled> Incluir NFC-e modelo 65 em C100/C190</label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="bloco_h" value="1" disabled> Gerar bloco H - Inventário futuramente</label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="registro_0221" value="1" disabled> Gerar Registro 0221 futuramente</label>
    <div class="span-3 fiscal-form-actions"><button class="btn btn-primary">Exportar SPED ICMS/IPI</button></div>
  </form>
</div>
<div class="fiscal-card"><h2>Prévia do período</h2><p class="fiscal-muted">Período: <?= fiscal_h($periodLabel) ?> • Entradas consideradas: <?= count($entries) ?> • NFC-e consideradas: <?= count($nfces) ?>. A NFC-e modelo 65 é gerada no bloco C como C100/C190.</p></div>
<script>
(function(){document.querySelectorAll('input[name="ini"],input[name="fim"]').forEach(function(el){el.addEventListener('input',function(){let v=el.value.replace(/\D/g,'').slice(0,8);if(v.length>4)v=v.slice(0,2)+'-'+v.slice(2,4)+'-'+v.slice(4);else if(v.length>2)v=v.slice(0,2)+'-'+v.slice(2);el.value=v;});});})();
</script>
<?php fiscal_admin_end(); ?>
