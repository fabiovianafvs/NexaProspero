<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
fiscal_ensure_schema($pdo);
$q=trim((string)($_GET['q']??''));
$items=fiscal_search_cests($pdo,(int)COMPANY_ID,$q,500);
fiscal_admin_start('Fiscal - CEST','fiscal_cests');
fiscal_page_header('CEST global','Catálogo compartilhado de CEST por NCM. A aplicação de ICMS/ST permanece individual na tributação da empresa.',[
  ['label'=>'Tributações da empresa','href'=>'fiscal_tributacoes.php','class'=>'btn btn-primary'],
  ['label'=>'Atualização global','href'=>'fiscal_importacoes.php','class'=>'btn'],
]);
fiscal_tabs('fiscal_cests');
?>
<div class="msg ok"><b>Somente leitura.</b> Uma empresa não pode alterar o CEST oficial das demais.</div>
<div class="fiscal-card"><form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end">
  <div class="row span-2"><label>Pesquisar</label><input class="field" name="q" value="<?= fiscal_h($q) ?>" placeholder="CEST, NCM, descrição ou segmento"></div>
  <div class="row fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="fiscal_cests.php">Limpar</a></div>
</form></div>
<div class="fiscal-card"><div class="fiscal-list-head"><h2>Catálogo global</h2><span class="fiscal-list-count"><?= count($items) ?> registro(s)</span></div>
<div class="table-wrap"><table class="fiscal-table"><thead><tr><th>CEST</th><th>NCM</th><th>Descrição</th><th>Segmento</th></tr></thead><tbody>
<?php foreach($items as $r): ?><tr><td data-label="CEST"><strong><?= fiscal_h($r['cest']??'') ?></strong></td><td data-label="NCM"><?= fiscal_h($r['ncm']??'') ?></td><td data-label="Descrição" class="primary"><?= fiscal_h($r['description']??'') ?></td><td data-label="Segmento"><?= fiscal_h($r['segment_name']??'') ?></td></tr><?php endforeach; ?>
<?php if(!$items): ?><tr><td colspan="4" style="text-align:center;color:#64748b">Nenhum CEST encontrado.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php fiscal_admin_end(); ?>
