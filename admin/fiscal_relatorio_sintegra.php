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
$nfces = fiscal_report_nfce_documents($pdo, $cid, $ini, $fim, false);

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'export') {
    $content = fiscal_sintegra_generate_content($pdo, $cid, $ini, $fim, $_POST);
    fiscal_report_txt_download('SINTEGRA_' . str_replace('-', '', $ini) . '_' . str_replace('-', '', $fim) . '.txt', $content);
  }
} catch (Throwable $e) { $err = $e->getMessage(); }

fiscal_admin_start('Geração SINTEGRA', 'fiscal_sintegra');
fiscal_page_header('Geração SINTEGRA', 'Informe o período e as opções básicas para gerar o arquivo magnético no layout fixo de 126 posições.', [
  ['label'=>'Relatórios Fiscais', 'href'=>'fiscal_relatorios.php', 'class'=>'btn'],
]);
fiscal_report_tabs('fiscal_sintegra');
if ($err) echo '<div class="msg err">' . fiscal_h($err) . '</div>';
?>
<div class="fiscal-card">
  <h2>Filtro</h2>
  <form method="post" class="fiscal-form fiscal-form-grid fiscal-report-export-form" style="align-items:end" data-no-processing>
    <input type="hidden" name="action" value="export">
    <input type="hidden" name="include_nfce" value="1">
    <div><label>Data inicial</label><input name="ini" value="<?= fiscal_h(fiscal_date_br($ini)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Data final</label><input name="fim" value="<?= fiscal_h(fiscal_date_br($fim)) ?>" placeholder="dd-mm-aaaa" inputmode="numeric" pattern="\d{2}-\d{2}-\d{4}"></div>
    <div><label>Finalidade</label><select name="finalidade"><option value="1">Arquivo normal de informações</option><option value="2">Arquivo retificador de informações</option><option value="3">Retificação aditiva</option><option value="5">Desfazimento</option></select></div>
    <div><label>Natureza das operações</label><select name="natureza"><option value="3">Totalidade das operações do informante</option><option value="2">Interestaduais com ou sem ST</option><option value="1">Interestaduais somente ST</option></select></div>
    <div><label>Estrutura</label><select name="estrutura"><option value="3" selected>Convênio 57/95 - versão atual (código 3)</option><option value="2">Convênio 57/95 - até 31/12/2003</option><option value="1">Convênio 57/95 - versão 31/99</option></select></div>
    <div class="span-3"><label>Tipos de registro</label><div class="fiscal-muted">Serão gerados: 10, 11, 50, 51, 53, 54, 61, 61R, 75 e 90. O Registro 51 entra quando houver IPI; o Registro 53 entra quando houver ICMS-ST/FCP-ST; as NFC-e autorizadas entram nos registros 61/61R e os produtos entram no 75.</div></div>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="registro_61" value="1" checked disabled> Registro 61/61R - NFC-e modelo 65 autorizada</label>
    <label style="display:flex;gap:8px;align-items:center"><input type="checkbox" name="registro_74" value="1" disabled> Registro 74 - inventário será liberado em etapa própria</label>
    <div class="span-3 fiscal-form-actions"><button class="btn btn-primary">Exportar SINTEGRA</button></div>
  </form>
</div>
<div class="fiscal-card"><h2>Prévia do período</h2><p class="fiscal-muted">Período: <?= fiscal_h($periodLabel) ?> • Entradas consideradas: <?= count($entries) ?> • NFC-e autorizadas consideradas: <?= count($nfces) ?></p></div>
<script>
(function(){document.querySelectorAll('input[name="ini"],input[name="fim"]').forEach(function(el){el.addEventListener('input',function(){let v=el.value.replace(/\D/g,'').slice(0,8);if(v.length>4)v=v.slice(0,2)+'-'+v.slice(2,4)+'-'+v.slice(4);else if(v.length>2)v=v.slice(0,2)+'-'+v.slice(2);el.value=v;});});})();
</script>
<?php fiscal_admin_end(); ?>
