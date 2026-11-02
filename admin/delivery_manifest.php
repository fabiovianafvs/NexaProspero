<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';
require_once __DIR__ . '/_delivery_control_ui.php';

$companyId=(int)COMPANY_ID;
delivery_control_require_enabled($pdo,$companyId);
$userId=dc_user_id();
$msg='';
$err='';

if(($_SERVER['REQUEST_METHOD']??'GET')==='POST'){
    try{
        dc_csrf_check((string)($_POST['csrf']??''));
        $date=trim((string)($_POST['manifest_date']??date('Y-m-d')));
        if(!preg_match('/^\d{4}-\d{2}-\d{2}$/',$date))throw new RuntimeException('Data do romaneio inválida.');
        $carrierId=(int)($_POST['carrier_person_id']??0);
        $vehicleId=(int)($_POST['vehicle_id']??0);
        $driver=trim((string)($_POST['driver_name']??''));
        $notes=trim((string)($_POST['notes']??''));
        $carrierName=null;$vehicleDesc=null;$plate=null;
        if($carrierId>0){
            $st=$pdo->prepare('SELECT name FROM finance_people WHERE company_id=? AND id=? AND is_carrier=1 AND active=1');
            $st->execute([$companyId,$carrierId]);$carrierName=$st->fetchColumn()?:null;
            if(!$carrierName)throw new RuntimeException('Transportadora inválida.');
        }
        if($vehicleId>0){
            $st=$pdo->prepare('SELECT description,plate,driver_name FROM delivery_control_vehicles WHERE company_id=? AND id=? AND active=1');
            $st->execute([$companyId,$vehicleId]);$v=$st->fetch(PDO::FETCH_ASSOC);
            if(!$v)throw new RuntimeException('Veículo inválido.');
            $vehicleDesc=$v['description'];$plate=$v['plate'];if($driver==='')$driver=(string)($v['driver_name']??'');
        }
        $pdo->beginTransaction();
        $number=delivery_control_next_number($pdo,$companyId,'manifest');
        $pdo->prepare("INSERT INTO delivery_control_manifests(company_id,manifest_number,manifest_date,status,carrier_person_id,carrier_name_snapshot,vehicle_id,vehicle_description_snapshot,vehicle_plate_snapshot,driver_name_snapshot,notes,created_by) VALUES(?,?,?,'ABERTO',?,?,?,?,?,?,?,?)")
            ->execute([$companyId,$number,$date,$carrierId?:null,$carrierName,$vehicleId?:null,$vehicleDesc,$plate,$driver?:null,$notes?:null,$userId?:null]);
        $id=(int)$pdo->lastInsertId();
        delivery_control_event($pdo,$companyId,'MANIFEST',$id,'ROMANEIO_CRIADO',$userId,['manifest_number'=>$number]);
        $pdo->commit();
        header('Location: delivery_manifest_view.php?id='.$id);exit;
    }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();$err=$e->getMessage();}
}

$carriers=$pdo->prepare('SELECT id,name FROM finance_people WHERE company_id=? AND is_carrier=1 AND active=1 ORDER BY name');
$carriers->execute([$companyId]);$carriers=$carriers->fetchAll(PDO::FETCH_ASSOC)?:[];
$vehicles=$pdo->prepare('SELECT * FROM delivery_control_vehicles WHERE company_id=? AND active=1 ORDER BY description');
$vehicles->execute([$companyId]);$vehicles=$vehicles->fetchAll(PDO::FETCH_ASSOC)?:[];

$q=trim((string)($_GET['q']??''));
$status=strtoupper(trim((string)($_GET['status']??'')));
$dateStart=trim((string)($_GET['date_start']??''));
$dateEnd=trim((string)($_GET['date_end']??''));
if($dateStart!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateStart))$dateStart='';
if($dateEnd!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateEnd))$dateEnd='';
$where=['m.company_id=?'];$params=[$companyId];
if($q!==''){
    $like=nexa_search_like($q);
    $where[]='(CAST(m.manifest_number AS CHAR) LIKE ? OR m.carrier_name_snapshot LIKE ? OR m.vehicle_description_snapshot LIKE ? OR m.vehicle_plate_snapshot LIKE ? OR m.driver_name_snapshot LIKE ? OR m.notes LIKE ?)';
    array_push($params,$like,$like,$like,$like,$like,$like);
}
if(in_array($status,['ABERTO','FECHADO','EM_ROTA','CANCELADO'],true)){$where[]='m.status=?';$params[]=$status;}
if($dateStart!==''){$where[]='m.manifest_date>=?';$params[]=$dateStart;}
if($dateEnd!==''){$where[]='m.manifest_date<=?';$params[]=$dateEnd;}
$sql="SELECT m.*,COUNT(mo.id) order_count
      FROM delivery_control_manifests m
      LEFT JOIN delivery_control_manifest_orders mo ON mo.company_id=m.company_id AND mo.manifest_id=m.id
      WHERE ".implode(' AND ',$where)."
      GROUP BY m.id ORDER BY m.manifest_date DESC,m.id DESC LIMIT 300";
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

$gridColumns=['numero'=>'Nº','data'=>'Data','status'=>'Status','transportadora'=>'Transportadora','veiculo'=>'Veículo','motorista'=>'Motorista','oe'=>'O.E.','acoes'=>'Ações'];
$gridPrefs=dc_grid_preferences_load($pdo,$companyId,'romaneios',array_keys($gridColumns));
dc_page_start('Romaneios de Entrega','delivery_manifest');
?>
<div class="dc-head"><h1>Romaneios de Entrega</h1><div class="dc-spacer"></div><a class="dc-btn" href="delivery_orders.php">Ordens de Entrega</a><a class="dc-btn" href="delivery_vehicles.php">Veículos</a></div>
<?php if($err):?><div class="dc-alert error"><?=dc_e($err)?></div><?php endif;?>
<form method="post" class="dc-card">
  <input type="hidden" name="csrf" value="<?=dc_e(dc_csrf_token())?>">
  <div class="dc-card-head">Novo Romaneio</div>
  <div class="dc-card-body">
    <div class="dc-grid">
      <div class="dc-field"><label>Data</label><input type="date" name="manifest_date" value="<?=date('Y-m-d')?>" required></div>
      <div class="dc-field"><label>Transportadora</label><select name="carrier_person_id"><option value="0">Entrega própria / não informar</option><?php foreach($carriers as $c):?><option value="<?=(int)$c['id']?>"><?=dc_e($c['name'])?></option><?php endforeach;?></select></div>
      <div class="dc-field"><label>Caminhão / carro</label><select name="vehicle_id"><option value="0">Não informar</option><?php foreach($vehicles as $v):?><option value="<?=(int)$v['id']?>"><?=dc_e($v['description'])?><?=!empty($v['plate'])?' · '.dc_e($v['plate']):''?></option><?php endforeach;?></select></div>
      <div class="dc-field"><label>Motorista</label><input name="driver_name" placeholder="Opcional"></div>
    </div>
    <div class="dc-manifest-notes-row">
      <div class="dc-field"><label>Observações</label><input name="notes" placeholder="Opcional"></div>
      <button class="dc-btn primary" type="submit">Criar Romaneio</button>
    </div>
  </div>
</form>
<div class="dc-card">
  <div class="dc-card-head">Filtros</div>
  <div class="dc-card-body">
    <form method="get" class="dc-manifest-filter">
      <div class="dc-field"><label>Pesquisar</label><input name="q" value="<?=dc_e($q)?>" placeholder="Nº, transportadora, veículo, placa ou motorista"></div>
      <div class="dc-field"><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['ABERTO','FECHADO','EM_ROTA'] as $s):?><option value="<?=$s?>" <?=$status===$s?'selected':''?>><?=dc_e(dc_status_label($s))?></option><?php endforeach;?></select></div>
      <div class="dc-field"><label>Período inicial</label><input type="date" name="date_start" value="<?=dc_e($dateStart)?>"></div>
      <div class="dc-field"><label>Período final</label><input type="date" name="date_end" value="<?=dc_e($dateEnd)?>"></div>
      <div class="dc-filter-actions"><button class="dc-btn primary">Filtrar</button><a class="dc-btn" href="delivery_manifest.php">Limpar</a></div>
    </form>
  </div>
</div>
<style>
.dc-manifest-notes-row{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:10px;align-items:end;margin-top:12px}.dc-manifest-notes-row .dc-btn{min-height:39px}
.dc-manifest-filter{display:grid;grid-template-columns:minmax(270px,1.45fr) minmax(145px,.6fr) minmax(140px,.55fr) minmax(140px,.55fr) auto;gap:10px;align-items:end}
@media(max-width:1100px){.dc-manifest-filter{grid-template-columns:repeat(2,minmax(0,1fr))}.dc-manifest-filter .dc-filter-actions{grid-column:span 2}}
@media(max-width:700px){.dc-manifest-notes-row,.dc-manifest-filter{grid-template-columns:1fr}.dc-manifest-filter .dc-filter-actions{grid-column:auto}.dc-manifest-notes-row .dc-btn{width:100%}}
</style>
<div class="dc-card"><div class="dc-card-head with-grid-tools"><span>Romaneios <span class="dc-muted">• <?=count($rows)?> registro(s)</span></span><?php dc_grid_toolbar('romaneios',$gridColumns,$gridPrefs);?></div><div class="dc-card-body"><div class="dc-advanced-wrap"><table class="dc-data-grid" id="dcGrid-romaneios"><thead><tr>
<th data-col="numero"><div class="dc-grid-th-inner">Nº</div></th><th data-col="data"><div class="dc-grid-th-inner">Data</div></th><th data-col="status"><div class="dc-grid-th-inner">Status</div></th><th data-col="transportadora"><div class="dc-grid-th-inner">Transportadora</div></th><th data-col="veiculo"><div class="dc-grid-th-inner">Veículo</div></th><th data-col="motorista"><div class="dc-grid-th-inner">Motorista</div></th><th data-col="oe" class="num"><div class="dc-grid-th-inner">O.E.</div></th><th data-col="acoes" class="dc-actions-col"><div class="dc-grid-th-inner">Ações</div></th>
</tr></thead><tbody>
<?php if(!$rows):?><tr><?php foreach(array_keys($gridColumns) as $c):?><td data-col="<?=dc_e($c)?>" class="<?=$c==='numero'?'dc-empty-grid':''?>"><?=$c==='numero'?'Nenhum romaneio encontrado.':''?></td><?php endforeach;?></tr><?php endif;?>
<?php foreach($rows as $r):?><tr><td data-col="numero"><strong>#<?=str_pad((string)$r['manifest_number'],6,'0',STR_PAD_LEFT)?></strong></td><td data-col="data"><?=dc_date($r['manifest_date'])?></td><td data-col="status"><span class="dc-badge <?=dc_status_class((string)$r['status'])?>"><?=dc_e(dc_status_label((string)$r['status']))?></span></td><td data-col="transportadora"><?=dc_e($r['carrier_name_snapshot']?:'—')?></td><td data-col="veiculo"><?=dc_e($r['vehicle_description_snapshot']?:'—')?><?=!empty($r['vehicle_plate_snapshot'])?' · '.dc_e($r['vehicle_plate_snapshot']):''?></td><td data-col="motorista"><?=dc_e($r['driver_name_snapshot']?:'—')?></td><td data-col="oe" class="num"><?=(int)$r['order_count']?></td><td data-col="acoes" class="dc-actions-col"><a class="dc-btn sm primary" href="delivery_manifest_view.php?id=<?=(int)$r['id']?>">Abrir</a></td></tr><?php endforeach;?></tbody></table></div></div></div>
<?php dc_grid_script('romaneios',$gridColumns,$gridPrefs,['numero'=>95,'data'=>110,'status'=>125,'transportadora'=>230,'veiculo'=>240,'motorista'=>190,'oe'=>80,'acoes'=>105]); ?>
<?php dc_page_end(); ?>
