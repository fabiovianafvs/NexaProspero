<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
fiscal_ensure_schema($pdo);
$type=(string)($_GET['type']??'classificacoes');
$configs=[
 'classificacoes'=>['title'=>'Classificação IBS/CBS','table'=>'fiscal_ibs_cbs_classifications','order'=>'classification_code','cols'=>['classification_code'=>'Código','description'=>'Descrição','cst_code'=>'CST','rate_type'=>'Tipo','ibs_reduction_rate'=>'Red. IBS %','cbs_reduction_rate'=>'Red. CBS %','can_nfe'=>'NF-e','can_nfce'=>'NFC-e','validity_start'=>'Início','validity_end'=>'Fim']],
 'cbs'=>['title'=>'Alíquotas CBS','table'=>'fiscal_cbs_rates','order'=>'validity_start DESC,id DESC','cols'=>['code'=>'Código','rate'=>'Alíquota %','validity_start'=>'Início','validity_end'=>'Fim','notes'=>'Observações']],
 'ibs_uf'=>['title'=>'Alíquotas IBS por UF','table'=>'fiscal_ibs_uf_rates','order'=>'uf,validity_start DESC','cols'=>['uf'=>'UF','rate'=>'Alíquota %','validity_start'=>'Início','validity_end'=>'Fim','notes'=>'Observações']],
 'ibs_municipio'=>['title'=>'Alíquotas IBS por município','table'=>'fiscal_ibs_mun_rates','order'=>'uf,city_name,validity_start DESC','cols'=>['ibge_code'=>'IBGE','city_name'=>'Município','uf'=>'UF','rate'=>'Alíquota %','validity_start'=>'Início','validity_end'=>'Fim']],
 'cidades'=>['title'=>'Municípios / IBGE','table'=>'fiscal_cities','order'=>'uf,name','cols'=>['ibge_code'=>'IBGE','name'=>'Município','uf'=>'UF']],
 'ufs'=>['title'=>'UF / Estados','table'=>'fiscal_states','order'=>'uf','cols'=>['ibge_code'=>'IBGE','name'=>'Estado','uf'=>'UF','timezone_normal'=>'Fuso']],
];
if(!isset($configs[$type]))$type='classificacoes';
$cfg=$configs[$type];
$q=trim((string)($_GET['q']??''));
$args=[];$where='WHERE company_id=0 AND is_active=1';
if($q!==''){
 $parts=[];foreach(array_keys($cfg['cols']) as $col){$parts[]="`{$col}` LIKE ?";$args[]=nexa_search_like($q);}
 $where.=' AND ('.implode(' OR ',$parts).')';
}
$st=$pdo->prepare("SELECT * FROM `{$cfg['table']}` {$where} ORDER BY {$cfg['order']} LIMIT 500");
$st->execute($args);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
fiscal_admin_start('Fiscal - '.$cfg['title'],'fiscal_ibscbs');
fiscal_page_header($cfg['title'],'Catálogo oficial global, compartilhado por todas as empresas. A seleção e o enquadramento permanecem individuais nos produtos e tributações.',[
 ['label'=>'Tributações da empresa','href'=>'fiscal_tributacoes.php','class'=>'btn btn-primary'],
 ['label'=>'Atualização global','href'=>'fiscal_importacoes.php','class'=>'btn'],
]);
fiscal_tabs('fiscal_ibscbs');
?>
<div class="msg ok"><b>Somente leitura.</b> Registros locais antigos não substituem mais o catálogo global.</div>
<div class="fiscal-tabs" style="margin-top:0">
 <a class="<?= $type==='classificacoes'?'active':'' ?>" href="fiscal_ibscbs.php?type=classificacoes">Classificação IBS/CBS</a>
 <a class="<?= $type==='cbs'?'active':'' ?>" href="fiscal_ibscbs.php?type=cbs">CBS</a>
 <a class="<?= $type==='ibs_uf'?'active':'' ?>" href="fiscal_ibscbs.php?type=ibs_uf">IBS UF</a>
 <a class="<?= $type==='ibs_municipio'?'active':'' ?>" href="fiscal_ibscbs.php?type=ibs_municipio">IBS Município</a>
 <a class="<?= $type==='cidades'?'active':'' ?>" href="fiscal_ibscbs.php?type=cidades">Cidades/IBGE</a>
 <a class="<?= $type==='ufs'?'active':'' ?>" href="fiscal_ibscbs.php?type=ufs">UF/Estados</a>
</div>
<div class="fiscal-card"><form method="get" class="fiscal-form fiscal-form-grid" style="align-items:end"><input type="hidden" name="type" value="<?= fiscal_h($type) ?>"><div class="row span-2"><label>Pesquisar</label><input class="field" name="q" value="<?= fiscal_h($q) ?>" placeholder="Código ou descrição"></div><div class="row fiscal-filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn" href="fiscal_ibscbs.php?type=<?= fiscal_h($type) ?>">Limpar</a></div></form></div>
<div class="fiscal-card"><div class="fiscal-list-head"><h2>Registros globais</h2><span class="fiscal-list-count"><?= count($rows) ?> registro(s)</span></div><div class="table-wrap"><table class="fiscal-table"><thead><tr><?php foreach($cfg['cols'] as $label): ?><th><?= fiscal_h($label) ?></th><?php endforeach; ?></tr></thead><tbody>
<?php foreach($rows as $r): ?><tr><?php foreach($cfg['cols'] as $col=>$label): ?><td data-label="<?= fiscal_h($label) ?>" class="<?= in_array($col,['description','name','city_name','notes'],true)?'primary':'' ?>"><?= fiscal_h($r[$col]??'') ?></td><?php endforeach; ?></tr><?php endforeach; ?>
<?php if(!$rows): ?><tr><td colspan="<?= count($cfg['cols']) ?>" style="text-align:center;color:#64748b">Nenhum registro global encontrado.</td></tr><?php endif; ?>
</tbody></table></div></div>
<?php fiscal_admin_end(); ?>
