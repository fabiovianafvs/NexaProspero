<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/producao_module.php';
require_once __DIR__ . '/_producao_ui.php';

$companyId=(int)COMPANY_ID;
if(!production_enabled($pdo,$companyId)) saas_require_company_module($pdo,$companyId,'ordem_producao','Monitor de Produção');
if(!production_ensure_schema($pdo)) throw new RuntimeException('Não foi possível preparar a estrutura da Ordem de Produção.');

$q=trim((string)($_GET['q']??''));
$status=strtoupper(trim((string)($_GET['status']??'')));
$dateFrom=trim((string)($_GET['date_from']??''));
$dateTo=trim((string)($_GET['date_to']??''));
if(!in_array($status,['','EM_PROCESSO','FINALIZADA','CANCELADA'],true))$status='';
if($dateFrom!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom))$dateFrom='';
if($dateTo!==''&&!preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo))$dateTo='';

$where=['o.company_id=?'];$params=[$companyId];
if($status!==''){$where[]='o.status=?';$params[]=$status;}
if($dateFrom!==''){$where[]='o.created_at>=?';$params[]=$dateFrom.' 00:00:00';}
if($dateTo!==''){$where[]='o.created_at<=?';$params[]=$dateTo.' 23:59:59';}
if($q!==''){
  $where[]='(CAST(o.numero_op AS CHAR) LIKE ? OR EXISTS (SELECT 1 FROM producao_ordem_itens qi JOIN produtos qp ON qp.company_id=qi.company_id AND qp.id=qi.produto_id WHERE qi.company_id=o.company_id AND qi.ordem_id=o.id AND qp.nome LIKE ?))';
  $like=nexa_search_like($q);$params[]=$like;$params[]=$like;
}

$sql="SELECT o.id,o.numero_op,o.status,o.order_by_sector_snapshot,o.created_at,o.finished_at,
  (SELECT GROUP_CONCAT(CONCAT(p.nome,' (',TRIM(TRAILING '0' FROM TRIM(TRAILING '.' FROM FORMAT(i.quantidade_planejada,4,'en_US'))),' ',COALESCE(p.unidade_estoque,'UN'),')') ORDER BY i.id SEPARATOR ', ')
     FROM producao_ordem_itens i JOIN produtos p ON p.company_id=i.company_id AND p.id=i.produto_id
    WHERE i.company_id=o.company_id AND i.ordem_id=o.id) produtos,
  (SELECT COUNT(*) FROM producao_ordem_itens i2 WHERE i2.company_id=o.company_id AND i2.ordem_id=o.id) total_itens,
  (SELECT COUNT(*) FROM producao_ordem_materiais m WHERE m.company_id=o.company_id AND m.ordem_id=o.id) total_materiais
  FROM producao_ordens o WHERE ".implode(' AND ',$where).' ORDER BY o.id DESC LIMIT 300';
try{$st=$pdo->prepare($sql);$st->execute($params);$orders=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){
  // Fallback simples para versões de MySQL/MariaDB sem locale em FORMAT().
  $sql="SELECT o.id,o.numero_op,o.status,o.order_by_sector_snapshot,o.created_at,o.finished_at,
    (SELECT GROUP_CONCAT(CONCAT(p.nome,' (',i.quantidade_planejada,' ',COALESCE(p.unidade_estoque,'UN'),')') ORDER BY i.id SEPARATOR ', ')
       FROM producao_ordem_itens i JOIN produtos p ON p.company_id=i.company_id AND p.id=i.produto_id
      WHERE i.company_id=o.company_id AND i.ordem_id=o.id) produtos,
    (SELECT COUNT(*) FROM producao_ordem_itens i2 WHERE i2.company_id=o.company_id AND i2.ordem_id=o.id) total_itens,
    (SELECT COUNT(*) FROM producao_ordem_materiais m WHERE m.company_id=o.company_id AND m.ordem_id=o.id) total_materiais
    FROM producao_ordens o WHERE ".implode(' AND ',$where).' ORDER BY o.id DESC LIMIT 300';
  $st=$pdo->prepare($sql);$st->execute($params);$orders=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
}

$counts=['EM_PROCESSO'=>0,'FINALIZADA'=>0,'CANCELADA'=>0];
try{
  $c=$pdo->prepare('SELECT status,COUNT(*) qtd FROM producao_ordens WHERE company_id=? GROUP BY status');$c->execute([$companyId]);
  foreach($c->fetchAll(PDO::FETCH_ASSOC)?:[] as $r){$s=(string)$r['status'];if(isset($counts[$s]))$counts[$s]=(int)$r['qtd'];}
}catch(Throwable $e){}

production_page_start('Monitor de Produção','producao_monitor');
production_page_header('Monitor de Produção','Acompanhe as ordens de produção, consulte o consumo de matérias-primas e finalize as O.P.s que estiverem em processo.',[
  ['label'=>'+ Nova Ordem de Produção','href'=>'producao_ordem.php','class'=>'btn btn-primary'],
]);
if(!empty($_GET['ok']))echo '<div class="msg ok">'.production_h($_GET['ok']).'</div>';
if(!empty($_GET['err']))echo '<div class="msg err">'.production_h($_GET['err']).'</div>';
?>
<div class="pr-kpis">
  <div class="pr-kpi"><small>Em processo</small><strong><?= (int)$counts['EM_PROCESSO'] ?></strong></div>
  <div class="pr-kpi"><small>Finalizadas</small><strong><?= (int)$counts['FINALIZADA'] ?></strong></div>
  <div class="pr-kpi"><small>Canceladas</small><strong><?= (int)$counts['CANCELADA'] ?></strong></div>
</div>

<div class="pr-card">
  <div class="pr-card-head"><div><h2>Pesquisar O.P.</h2><div class="pr-muted">Filtre pelo número da ordem, nome do produto acabado, status ou período.</div></div></div>
  <form method="get" class="pr-filter">
    <div class="pr-row"><label>O.P. / Produto</label><input name="q" value="<?= production_h($q) ?>" placeholder="Ex.: 25 ou Pão de Sal"></div>
    <div class="pr-row"><label>Status</label><select name="status"><option value="">Todos</option><option value="EM_PROCESSO"<?= $status==='EM_PROCESSO'?' selected':'' ?>>Em processo</option><option value="FINALIZADA"<?= $status==='FINALIZADA'?' selected':'' ?>>Finalizada</option><option value="CANCELADA"<?= $status==='CANCELADA'?' selected':'' ?>>Cancelada</option></select></div>
    <div class="pr-row"><label>De</label><input type="date" name="date_from" value="<?= production_h($dateFrom) ?>"></div>
    <div class="pr-row"><label>Até</label><input type="date" name="date_to" value="<?= production_h($dateTo) ?>"></div>
    <div class="pr-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="producao_monitor.php">Limpar</a></div>
  </form>
</div>

<div class="pr-card">
  <div class="pr-card-head"><div><h2>Ordens de Produção</h2><div class="pr-muted"><?= count($orders) ?> registro(s) exibido(s).</div></div></div>
  <div class="pr-table-wrap"><table class="pr-table"><thead><tr><th>O.P.</th><th>Geração</th><th>Produtos acabados</th><th>Modo</th><th>Status</th><th>Ações</th></tr></thead><tbody>
  <?php if(!$orders): ?><tr><td colspan="6" class="pr-empty">Nenhuma Ordem de Produção encontrada.</td></tr><?php else: foreach($orders as $o): ?>
    <tr>
      <td><b>#<?= production_h(str_pad((string)$o['numero_op'],6,'0',STR_PAD_LEFT)) ?></b></td>
      <td><?= production_h(date('d/m/Y H:i',strtotime((string)$o['created_at']))) ?></td>
      <td><?= production_h((string)($o['produtos']?:'—')) ?><div class="pr-muted" style="font-size:11px;margin-top:3px"><?= (int)$o['total_itens'] ?> produto(s) · <?= (int)$o['total_materiais'] ?> matéria(s)-prima(s)</div></td>
      <td><?= !empty($o['order_by_sector_snapshot'])?'Por setor':'Automática' ?></td>
      <td><?= production_status_badge((string)$o['status']) ?></td>
      <td><div class="pr-actions-inline"><a class="btn btn-sm" href="producao_ordem.php?id=<?= (int)$o['id'] ?>">Abrir</a></div></td>
    </tr>
  <?php endforeach; endif; ?>
  </tbody></table></div>
</div>
<?php production_page_end(); ?>
