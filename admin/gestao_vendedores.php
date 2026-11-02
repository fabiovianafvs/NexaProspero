<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/commercial_rules.php';
$companyId = (int)COMPANY_ID;
company_sequence_ensure_schema($pdo, true);
commercial_ensure_schema($pdo);
$hasPessoaNumero = company_sequence_has_column($pdo, 'pessoas');
$q = trim((string)($_GET['q'] ?? ''));
$where=['company_id=?','active=1','is_seller=1']; $params=[$companyId];
if($q!==''){
  $like=nexa_search_like($q);
  $parts=['name LIKE ?','document LIKE ?','fantasy_name LIKE ?'];
  $searchParams=[$like,$like,$like];
  $qDigits=preg_replace('/\D+/', '', $q);
  if($hasPessoaNumero && $qDigits!==''){ $parts[]='numero_cadastro = ?'; $searchParams[]=(int)$qDigits; }
  elseif($hasPessoaNumero){ $parts[]='CAST(numero_cadastro AS CHAR) LIKE ?'; $searchParams[]=$like; }
  $where[]='('.implode(' OR ',$parts).')';
  $params=array_merge($params,$searchParams);
}
$orderPeople=$hasPessoaNumero ? 'COALESCE(numero_cadastro, id) ASC, id ASC' : 'name ASC';
$rows=finance_query_all($pdo,'SELECT * FROM finance_people WHERE '.implode(' AND ',$where).' ORDER BY '.$orderPeople.' LIMIT 300',$params);
gestao_admin_start('Vendedores','gestao_vendedores');
gestao_page_header('Vendedores','Lista de pessoas marcadas como vendedor/colaborador comercial.', [['label'=>'+ Novo vendedor','href'=>'financeiro_pessoas.php?modal=1&role=seller','class'=>'btn btn-primary']]);
gestao_tabs('gestao_vendedores');
?>
<form class="mg-card mg-filter" method="get"><label class="wide">Pesquisar<input name="q" value="<?= gestao_h($q) ?>" placeholder="Nome, nº, CPF/CNPJ ou fantasia"></label><div class="mg-filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn" href="gestao_vendedores.php">Limpar</a></div></form>
<div class="mg-card" style="margin-top:14px;"><h2>Vendedores</h2><div class="table-wrap"><table class="mg-table"><thead><tr><th>Nº</th><th>Nome</th><th>Documento</th><th>Contato</th><th>Comissão</th><th>Status</th><th>Ação</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="7" class="mg-empty">Nenhum vendedor cadastrado.</td></tr><?php endif; ?><?php foreach($rows as $r): ?><?php $numeroPessoa = company_sequence_display($r, 'pessoas'); ?><tr><td data-label="Nº"><strong>#<?= (int)$numeroPessoa ?></strong></td><td data-label="Nome" class="primary"><strong><?= gestao_h($r['name']) ?></strong><div class="mg-muted"><?= gestao_h($r['fantasy_name'] ?? '') ?></div></td><td data-label="Documento"><?= gestao_h($r['document'] ?? '') ?></td><td data-label="Contato"><?= gestao_h($r['whatsapp'] ?: ($r['phone'] ?? '')) ?></td><td data-label="Comissão"><?= $r['seller_commission_percent'] === null ? '<span class="mg-muted">Não definida</span>' : gestao_h(number_format((float)$r['seller_commission_percent'],2,',','.').'%') ?></td><td data-label="Status"><span class="mg-badge green">Ativo</span></td><td data-label="Ação"><a class="btn btn-sm" href="financeiro_pessoas.php?edit=<?= (int)$r['id'] ?>">Editar</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php gestao_admin_end(); ?>
