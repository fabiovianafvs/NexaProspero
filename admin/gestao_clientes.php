<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/company_sequences.php';
$companyId = function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, 'customers_write')
  : (int)COMPANY_ID;
$clientsCrossContext = $companyId !== (int)COMPANY_ID;
$clientsCompanyRow = function_exists('multi_company_context_company_row')
  ? multi_company_context_company_row($pdo, $companyId)
  : null;
$clientsCompanyName = trim((string)($clientsCompanyRow['name'] ?? '')) ?: ('Empresa #' . $companyId);
if ($clientsCrossContext && function_exists('nexa_people_permission_allowed')
    && !nexa_people_permission_allowed($pdo, $companyId)) {
  access_render_denied('O acesso ao cadastro de Pessoas foi desabilitado para este perfil.');
}
company_sequence_ensure_schema($pdo, true);
$hasPessoaNumero = company_sequence_has_column($pdo, 'pessoas');
$q = trim((string)($_GET['q'] ?? ''));
$where=['company_id=?','active=1','is_customer=1']; $params=[$companyId];
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
gestao_admin_start('Pessoas','gestao_clientes');
$clientHeaderActions = [
  [
    'label'=>'Importar planilha',
    'href'=>'gestao_clientes_importar.php',
    'class'=>'btn'
  ],
  [
    'label'=>'+ Nova pessoa',
    'href'=>$clientsCrossContext ? 'financeiro_pessoas.php?new=1&role=customer&mc_customer=1' : 'financeiro_pessoas.php?modal=1&role=customer',
    'class'=>'btn btn-primary'
  ]
];
gestao_page_header('Pessoas','Lista de pessoas marcadas como cliente. O cadastro completo é unificado em Pessoas.', $clientHeaderActions);
gestao_tabs('gestao_clientes');
?>
<?php if ($clientsCrossContext): ?>
<div class="mg-card" style="margin-top:14px;border-color:#f59e0b;background:#fffbeb;">
  <strong style="color:#92400e;">V7 · Cadastro Multiempresa — <?= gestao_h($clientsCompanyName) ?></strong>
  <div class="mg-muted" style="margin-top:5px;color:#92400e;">Clientes estão operando na empresa selecionada pelo F11. Inclusão e edição estão liberadas com validação do perfil e da empresa de destino.</div>
</div>
<?php endif; ?>
<form class="mg-card mg-filter" method="get"><label class="wide">Pesquisar<input name="q" value="<?= gestao_h($q) ?>" placeholder="Nome, nº, fantasia ou CPF/CNPJ"></label><div class="mg-filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn" href="gestao_clientes.php">Limpar</a></div></form>
<div class="mg-card" style="margin-top:14px;"><h2>Pessoas (clientes)</h2><div class="table-wrap"><table class="mg-table"><thead><tr><th>Nº</th><th>Nome</th><th>Documento</th><th>Contato</th><th>Cidade</th><th>Ação</th></tr></thead><tbody><?php if(!$rows): ?><tr><td colspan="6" class="mg-empty">Nenhuma pessoa cliente cadastrada.</td></tr><?php endif; ?><?php foreach($rows as $r): ?><?php $numeroPessoa = company_sequence_display($r, 'pessoas'); ?><tr><td data-label="Nº"><strong>#<?= (int)$numeroPessoa ?></strong></td><td data-label="Nome" class="primary"><strong><?= gestao_h($r['name']) ?></strong><div class="mg-muted"><?= gestao_h($r['fantasy_name'] ?? '') ?></div></td><td data-label="Documento"><?= gestao_h($r['document'] ?? '') ?></td><td data-label="Contato"><?= gestao_h($r['whatsapp'] ?: ($r['phone'] ?? '')) ?></td><td data-label="Cidade"><?= gestao_h(trim(($r['city'] ?? '').' / '.($r['state'] ?? ''),' /')) ?></td><td data-label="Ação"><a class="btn btn-sm" href="financeiro_pessoas.php?edit=<?= (int)$r['id'] ?><?= $clientsCrossContext ? '&role=customer&mc_customer=1' : '' ?>">Editar</a></td></tr><?php endforeach; ?></tbody></table></div></div>
<?php gestao_admin_end(); ?>
