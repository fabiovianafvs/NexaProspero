<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
fiscal_ensure_schema($pdo);
$imports=[];
try{$imports=$pdo->query('SELECT * FROM fiscal_catalog_imports ORDER BY imported_at DESC LIMIT 20')->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){}
fiscal_admin_start('Fiscal - Importações','fiscal_importacoes');
fiscal_page_header('Importações fiscais','XMLs continuam por empresa; catálogos oficiais são atualizados uma vez para todo o SaaS.');
fiscal_tabs('fiscal_importacoes');
?>
<div class="fiscal-card"><div class="fiscal-list-head"><div><h2>XML de NF-e / RADAR XML</h2><p class="fiscal-muted" style="margin:0">Esta importação permanece isolada por empresa.</p></div><div class="fiscal-inline-actions"><a class="btn btn-primary" href="fiscal_importar_xml.php">Importar XML</a><a class="btn" href="fiscal_radar_xml.php">Abrir RADAR XML</a></div></div></div>
<div class="msg ok"><b>Catálogos globais protegidos.</b> NCM, CEST, IBPT, cClassTrib, CBS e IBS não são mais importados dentro de uma empresa. O administrador atualiza uma única vez no servidor.</div>
<div class="fiscal-card"><h2>Comandos do administrador</h2><p class="fiscal-muted">Use o worker <code>workers/import_global_fiscal_catalog_v26.php</code>. Exemplos:</p>
<pre style="white-space:pre-wrap">php workers/import_global_fiscal_catalog_v26.php --type=ncm --file=/caminho/ncm.csv
php workers/import_global_fiscal_catalog_v26.php --type=cest --file=/caminho/cest.csv
php workers/import_global_fiscal_catalog_v26.php --type=ibpt --uf=MG --file=/caminho/ibpt.csv
php workers/import_global_fiscal_catalog_v26.php --type=ibscbs --file=/caminho/classificacoes.json
php workers/import_global_fiscal_catalog_v26.php --type=cbs-rates --file=/caminho/cbs.csv
php workers/import_global_fiscal_catalog_v26.php --type=ibs-uf-rates --file=/caminho/ibs_uf.csv
php workers/import_global_fiscal_catalog_v26.php --type=ibs-mun-rates --file=/caminho/ibs_municipio.csv</pre></div>
<?php if($imports): ?><div class="fiscal-card"><div class="fiscal-list-head"><h2>Últimas atualizações globais</h2><span class="fiscal-list-count"><?= count($imports) ?> registro(s)</span></div><div class="table-wrap"><table class="fiscal-table"><thead><tr><th>Data</th><th>Catálogo</th><th>UF</th><th>Versão</th><th>Arquivo</th><th>Linhas</th></tr></thead><tbody><?php foreach($imports as $im): ?><tr><td><?= fiscal_h($im['imported_at']??'') ?></td><td><strong><?= fiscal_h($im['catalog_type']??'') ?></strong></td><td><?= fiscal_h($im['uf']??'') ?></td><td><?= fiscal_h($im['version']??'') ?></td><td><?= fiscal_h($im['filename']??'') ?></td><td><?= (int)($im['rows_imported']??0) ?>/<?= (int)($im['rows_total']??0) ?></td></tr><?php endforeach; ?></tbody></table></div></div><?php endif; ?>
<?php fiscal_admin_end(); ?>
