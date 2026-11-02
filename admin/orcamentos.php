<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/orcamento_module.php';
require_once __DIR__ . '/_orcamento_ui.php';

$pdo=orc_db();
$companyId=orc_company_id();
orc_require_enabled($pdo,$companyId);
orc_require_schema($pdo);
orc_ensure_defaults($pdo,$companyId);

$marginHidden=function_exists('access_quote_margin_hidden')?access_quote_margin_hidden($pdo,$companyId):false;
$showMargins=!$marginHidden;
$costSnapshotReady=orc_schema_has_column($pdo,'orcamento_items','cost_snapshot');
$productCostReady=orc_schema_has_column($pdo,'produtos','custo');

$gridUserId=function_exists('auth_user_id')?(int)(auth_user_id()??0):(int)($_SESSION['user_id']??0);
$gridPreferenceKey=$gridUserId>0?('ui_orcamentos_grid_user_'.$gridUserId):'';

function orc_list_grid_csrf_token():string{
  if(function_exists('auth_start'))auth_start();elseif(session_status()!==PHP_SESSION_ACTIVE)session_start();
  if(empty($_SESSION['orcamentos_grid_csrf_token'])||!is_string($_SESSION['orcamentos_grid_csrf_token']))$_SESSION['orcamentos_grid_csrf_token']=bin2hex(random_bytes(24));
  return (string)$_SESSION['orcamentos_grid_csrf_token'];
}
function orc_list_grid_preferences_load(PDO $pdo,int $companyId,string $cfgKey):array{
  if($companyId<=0||$cfgKey==='')return [];
  try{$st=$pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');$st->execute([$companyId,$cfgKey]);$raw=$st->fetchColumn();if(!is_string($raw)||trim($raw)==='')return [];$d=json_decode($raw,true);return is_array($d)?$d:[];}catch(Throwable $e){return [];}
}
function orc_list_grid_preferences_normalize(array $prefs):array{
  $allowed=['numero','data','hora','cliente','vendedor','profissional','usuario','status','total','margem_custo','margem_venda'];
  $visible=[];foreach((array)($prefs['visible']??[]) as $col){$col=(string)$col;if(in_array($col,$allowed,true)&&!in_array($col,$visible,true))$visible[]=$col;}
  $order=[];foreach((array)($prefs['order']??[]) as $col){$col=(string)$col;if(in_array($col,$allowed,true)&&!in_array($col,$order,true))$order[]=$col;}
  $widths=[];foreach((array)($prefs['widths']??[]) as $col=>$width){$col=(string)$col;$width=(int)$width;if(in_array($col,$allowed,true)&&$width>=50&&$width<=800)$widths[$col]=$width;}
  return ['visible'=>$visible,'order'=>$order,'widths'=>$widths];
}
$gridCsrf=orc_list_grid_csrf_token();
if($_SERVER['REQUEST_METHOD']==='POST'&&(string)($_POST['action']??'')==='save_grid_preferences'){
  header('Content-Type: application/json; charset=utf-8');
  try{
    if($gridUserId<=0||$gridPreferenceKey==='')throw new RuntimeException('Usuário não identificado.');
    $csrf=(string)($_POST['csrf_token']??'');if($csrf===''||!hash_equals($gridCsrf,$csrf))throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    $decoded=json_decode((string)($_POST['preferences']??''),true);if(!is_array($decoded))throw new RuntimeException('Preferências inválidas.');
    $normalized=orc_list_grid_preferences_normalize($decoded);$json=json_encode($normalized,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);if($json===false)throw new RuntimeException('Não foi possível salvar as preferências.');
    $st=$pdo->prepare('INSERT INTO app_config (company_id,cfg_key,cfg_json,updated_at) VALUES (?,?,?,NOW()) ON DUPLICATE KEY UPDATE cfg_json=VALUES(cfg_json),updated_at=NOW()');$st->execute([$companyId,$gridPreferenceKey,$json]);
    echo json_encode(['ok'=>true],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
  }catch(Throwable $e){http_response_code(422);echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);}exit;
}
$gridPreferences=orc_list_grid_preferences_normalize(orc_list_grid_preferences_load($pdo,$companyId,$gridPreferenceKey));
$gridHasServerPreferences=!empty($gridPreferences['visible'])||!empty($gridPreferences['order'])||!empty($gridPreferences['widths']);

$printFocusId=max(0,(int)($_GET['print_id']??0));
$printFocusDate='';
if($printFocusId>0){
  try{$st=$pdo->prepare('SELECT quote_date FROM orcamentos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$printFocusId]);$printFocusDate=(string)($st->fetchColumn()?:'');}catch(Throwable $e){$printFocusDate='';}
}
$q=trim((string)($_GET['q']??''));
$status=strtoupper(trim((string)($_GET['status']??'')));
$dateFrom=array_key_exists('date_from',$_GET)?trim((string)$_GET['date_from']):($printFocusDate!==''?$printFocusDate:date('Y-m-d'));
$dateTo=array_key_exists('date_to',$_GET)?trim((string)$_GET['date_to']):($printFocusDate!==''?$printFocusDate:date('Y-m-d'));
$gridFilters=[
  'numero'=>trim((string)($_GET['f_numero']??'')),
  'data'=>trim((string)($_GET['f_data']??'')),
  'hora'=>trim((string)($_GET['f_hora']??'')),
  'cliente'=>trim((string)($_GET['f_cliente']??'')),
  'vendedor'=>trim((string)($_GET['f_vendedor']??'')),
  'profissional'=>trim((string)($_GET['f_profissional']??'')),
  'usuario'=>trim((string)($_GET['f_usuario']??'')),
  'status'=>strtoupper(trim((string)($_GET['f_status']??''))),
  'total'=>trim((string)($_GET['f_total']??'')),
  'margem_custo'=>trim((string)($_GET['f_margem_custo']??'')),
  'margem_venda'=>trim((string)($_GET['f_margem_venda']??'')),
];
$sortKey=trim((string)($_GET['sort']??''));
$sortDir=strtolower(trim((string)($_GET['dir']??'asc')))==='desc'?'desc':'asc';

function orc_list_query_url(array $changes=[]):string{$params=$_GET;unset($params['print_id'],$params['keyboard_print']);foreach($changes as $key=>$value){if($value===null||$value==='')unset($params[$key]);else$params[$key]=$value;}$query=http_build_query($params);return 'orcamentos.php'.($query!==''?'?'.$query:'');}
function orc_list_sort_url(string $column,string $currentKey,string $currentDir):string{$next=($currentKey===$column&&$currentDir==='asc')?'desc':'asc';return orc_list_query_url(['sort'=>$column,'dir'=>$next]);}
function orc_list_sort_mark(string $column,string $currentKey,string $currentDir):string{if($currentKey!==$column)return '↕';return $currentDir==='desc'?'↓':'↑';}
function orc_list_filter_menu(string $field,string $label,string $value,string $kind='text'):string{
  $active=trim($value)!=='';$fieldEsc=orc_e($field);$labelEsc=orc_e($label);$valueEsc=orc_e($value);$activeClass=$active?' has-filter':'';$icon='<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5h16l-6.2 7.1v5.3l-3.6 1.8v-7.1L4 5z" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round"/></svg>';
  if($kind==='status'){$control='<select form="orcSearchForm" class="orc-grid-filter-input" name="'.$fieldEsc.'"><option value="">Todos</option>';foreach(orc_statuses() as $k=>$v)$control.='<option value="'.orc_e($k).'"'.($value===$k?' selected':'').'>'.orc_e($v).'</option>';$control.='</select>';}
  else{$inputMode=$kind==='number'?' inputmode="decimal"':'';$control='<input form="orcSearchForm" class="orc-grid-filter-input" name="'.$fieldEsc.'" value="'.$valueEsc.'" placeholder="Digite para filtrar"'.$inputMode.' autocomplete="off">';}
  return '<details class="orc-grid-filter-menu'.$activeClass.'" data-orc-grid-filter-menu><summary class="orc-grid-filter-trigger" title="Filtrar '.$labelEsc.'">'.$icon.'</summary><div class="orc-grid-filter-popover"><div class="orc-grid-filter-title">Filtrar '.$labelEsc.'</div>'.$control.'<div class="orc-grid-filter-actions"><button class="orc-btn primary sm" type="submit" form="orcSearchForm">Aplicar</button><button class="orc-btn sm" type="button" data-clear-filter="'.$fieldEsc.'">Limpar</button></div></div></details>';
}
function orc_list_numeric_like(string $expr,string $value,array &$where,array &$params):void{if(trim($value)==='')return;$where[]='CAST(ROUND('.$expr.',2) AS CHAR) LIKE ?';$params[]=nexa_search_like(str_replace(',','.',$value));}

$costExpr=$costSnapshotReady?($productCostReady?'COALESCE(oi.cost_snapshot,p.custo,0)':'COALESCE(oi.cost_snapshot,0)'):($productCostReady?'COALESCE(p.custo,0)':'0');
$costSub="SELECT oi.company_id,oi.quote_id,SUM(oi.quantity*({$costExpr})) total_cost FROM orcamento_items oi LEFT JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id GROUP BY oi.company_id,oi.quote_id";
$totalCostExpr='COALESCE(costs.total_cost,0)';
$marginCostExpr="CASE WHEN {$totalCostExpr}>0 THEN ((o.total_general-{$totalCostExpr})/{$totalCostExpr})*100 ELSE NULL END";
$marginSaleExpr="CASE WHEN o.total_general>0 THEN ((o.total_general-{$totalCostExpr})/o.total_general)*100 ELSE NULL END";
$where=['o.company_id=?'];$params=[$companyId];
if($q!=='')foreach(array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[],0,6) as $t){$where[]='(CAST(o.quote_number AS CHAR) LIKE ? OR o.person_name_snapshot LIKE ? OR o.person_document_snapshot LIKE ? OR o.seller_name_snapshot LIKE ? OR o.professional_name_snapshot LIKE ? OR o.notes LIKE ? OR u.name LIKE ?)';for($i=0;$i<7;$i++)$params[]=nexa_search_like($t);}
if(isset(orc_statuses()[$status])){$where[]='o.status=?';$params[]=$status;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateFrom)){$where[]='o.quote_date>=?';$params[]=$dateFrom;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$dateTo)){$where[]='o.quote_date<=?';$params[]=$dateTo;}
if($gridFilters['numero']!==''){$where[]='CAST(o.quote_number AS CHAR) LIKE ?';$params[]=nexa_search_like($gridFilters['numero']);}
if($gridFilters['data']!==''){$where[]="DATE_FORMAT(o.quote_date,'%d/%m/%Y') LIKE ?";$params[]=nexa_search_like($gridFilters['data']);}
if($gridFilters['hora']!==''){$where[]="DATE_FORMAT(o.created_at,'%H:%i') LIKE ?";$params[]=nexa_search_like($gridFilters['hora']);}
foreach(['cliente'=>'o.person_name_snapshot','vendedor'=>'o.seller_name_snapshot','profissional'=>'o.professional_name_snapshot','usuario'=>'u.name'] as $k=>$expr)if($gridFilters[$k]!==''){$where[]=$expr.' LIKE ?';$params[]=nexa_search_like($gridFilters[$k]);}
if(isset(orc_statuses()[$gridFilters['status']])){$where[]='o.status=?';$params[]=$gridFilters['status'];}
orc_list_numeric_like('o.total_general',$gridFilters['total'],$where,$params);
if($showMargins){orc_list_numeric_like($marginCostExpr,$gridFilters['margem_custo'],$where,$params);orc_list_numeric_like($marginSaleExpr,$gridFilters['margem_venda'],$where,$params);}

$sortMap=['numero'=>'o.quote_number','data'=>'o.quote_date','hora'=>'o.created_at','cliente'=>'o.person_name_snapshot','vendedor'=>'o.seller_name_snapshot','profissional'=>'o.professional_name_snapshot','usuario'=>'u.name','status'=>'o.status','total'=>'o.total_general','margem_custo'=>$marginCostExpr,'margem_venda'=>$marginSaleExpr];
$orderSql=isset($sortMap[$sortKey])&&($showMargins||!in_array($sortKey,['margem_custo','margem_venda'],true))?$sortMap[$sortKey].' '.strtoupper($sortDir):'o.quote_date DESC,o.created_at DESC,o.quote_number DESC,o.id DESC';
$sql="SELECT o.*,u.name created_by_name,{$totalCostExpr} total_cost,{$marginCostExpr} margin_cost_percent,{$marginSaleExpr} margin_sale_percent FROM orcamentos o LEFT JOIN users u ON u.id=o.created_by LEFT JOIN ({$costSub}) costs ON costs.company_id=o.company_id AND costs.quote_id=o.id WHERE ".implode(' AND ',$where)." ORDER BY {$orderSql} LIMIT 500";
$st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

$osBridgeEnabled=false;$osLinks=[];try{require_once __DIR__.'/../config/os_module.php';require_once __DIR__.'/../config/os_orcamento_bridge.php';$osBridgeEnabled=os_module_enabled($pdo,$companyId)&&os_quote_bridge_schema_ready($pdo);if($osBridgeEnabled)$osLinks=os_quote_bridge_map($pdo,$companyId,array_column($rows,'id'));}catch(Throwable $e){$osBridgeEnabled=false;$osLinks=[];}

$gridColumnLabels=['numero'=>'Nº','data'=>'Data','hora'=>'Hora','cliente'=>'Cliente','vendedor'=>'Vendedor','profissional'=>'Profissional','usuario'=>'Usuário','status'=>'Status','total'=>'Total'];
if($showMargins){$gridColumnLabels['margem_custo']='Margem s/ custo';$gridColumnLabels['margem_venda']='Margem s/ venda';}
$gridColumnLabels['acoes']='Ações';

orc_page_start('Orçamento','orcamentos');
?>
<style>
.orc-grid-toolbar{display:flex;gap:8px;align-items:center;flex-wrap:wrap}.orc-columns-picker{position:relative}.orc-columns-picker>summary{list-style:none}.orc-columns-picker>summary::-webkit-details-marker{display:none}.orc-columns-picker-menu{position:absolute;right:0;top:calc(100% + 7px);z-index:120;width:300px;background:#fff;border:1px solid #d9e3f2;border-radius:14px;box-shadow:0 18px 44px rgba(15,35,80,.22);padding:12px}.orc-columns-picker-menu label{display:flex;gap:8px;align-items:center;padding:6px 4px;font-size:12px}.orc-columns-picker-menu input{width:auto;min-height:auto}.orc-columns-note{border-top:1px solid #eef4ff;margin-top:8px;padding-top:8px;font-size:11px;color:#64748b}.orc-grid-pref-status{font-weight:800}.orc-grid-pref-status.saving{color:#2563eb}.orc-grid-pref-status.saved{color:#047857}.orc-grid-pref-status.error{color:#b91c1c}
.orc-advanced-wrap{overflow-x:auto;border-top:1px solid #e5edf8}.orc-advanced-grid{width:100%;border-collapse:separate;border-spacing:0;min-width:1450px;font-size:12px;table-layout:fixed}.orc-advanced-grid th,.orc-advanced-grid td{padding:9px 10px;border-bottom:1px solid #e9eff8;border-right:1px solid #f1f5f9;vertical-align:middle;text-align:left}.orc-advanced-grid th:last-child,.orc-advanced-grid td:last-child{border-right:0}.orc-advanced-grid thead th{position:sticky;top:0;z-index:15;background:#f7faff;color:#243b70;font-size:10px;text-transform:uppercase;letter-spacing:.025em;font-weight:900}.orc-advanced-grid tbody tr:nth-child(odd) td{background:#fff}.orc-advanced-grid tbody tr:nth-child(even) td{background:#dfe8f4}.orc-advanced-grid tbody tr:hover td{background:#cbdaf0}
/* V11.45.19.18: a cor do status identifica a linha inteira do orçamento. */
.orc-advanced-grid tbody tr.orc-status-row td{background:color-mix(in srgb,var(--orc-row-status) 10%,#ffffff)!important;border-bottom-color:color-mix(in srgb,var(--orc-row-status) 24%,#e9eff8)}
.orc-advanced-grid tbody tr.orc-status-row:nth-child(even) td{background:color-mix(in srgb,var(--orc-row-status) 13%,#f5f8fc)!important}
.orc-advanced-grid tbody tr.orc-status-row:hover td{background:color-mix(in srgb,var(--orc-row-status) 20%,#ffffff)!important}
.orc-advanced-grid tbody tr.orc-status-row td:first-child{box-shadow:inset 5px 0 0 var(--orc-row-status)}
.orc-advanced-grid tbody tr.orc-status-row td.orc-actions-col{background:color-mix(in srgb,var(--orc-row-status) 10%,#ffffff)!important}
.orc-advanced-grid tbody tr.orc-status-row:nth-child(even) td.orc-actions-col{background:color-mix(in srgb,var(--orc-row-status) 13%,#f5f8fc)!important}
.orc-advanced-grid tbody tr.orc-status-row:hover td.orc-actions-col{background:color-mix(in srgb,var(--orc-row-status) 20%,#ffffff)!important}.orc-advanced-grid thead th.filter-open{z-index:95}.orc-advanced-grid thead th[data-col]{user-select:none}.orc-grid-th-inner{display:flex;align-items:center;gap:5px;min-height:22px}.orc-sort-link{display:flex;min-width:0;flex:1;gap:5px;align-items:center;justify-content:space-between;color:inherit;text-decoration:none;white-space:nowrap}.orc-sort-link span{font-size:12px;color:#64748b}.orc-col-drag-handle{display:inline-flex;align-items:center;justify-content:center;width:14px;height:22px;color:#94a3b8;cursor:grab;border-radius:4px;font-size:12px;line-height:1;letter-spacing:-2px}.orc-col-drag-handle:hover{color:#3558c9;background:#e8efff}.orc-col-resizer{position:absolute;top:0;right:-3px;z-index:110;width:7px;height:100%;cursor:col-resize;touch-action:none}.orc-col-resizer:after{content:"";position:absolute;top:20%;bottom:20%;left:3px;width:1px;background:#c8d4e8}.orc-advanced-grid.resizing-columns,.orc-advanced-grid.resizing-columns *{cursor:col-resize!important}.orc-advanced-grid thead th.col-drop-before{box-shadow:inset 3px 0 0 #3558c9}.orc-advanced-grid thead th.col-drop-after{box-shadow:inset -3px 0 0 #3558c9}.orc-advanced-grid thead th.col-dragging{opacity:.58}.orc-grid-filter-menu{position:relative;flex:0 0 auto}.orc-grid-filter-menu>summary{list-style:none}.orc-grid-filter-menu>summary::-webkit-details-marker{display:none}.orc-grid-filter-trigger{width:22px;height:22px;display:inline-flex;align-items:center;justify-content:center;border:1px solid transparent;border-radius:6px;color:#71809c;cursor:pointer;background:transparent}.orc-grid-filter-trigger:hover{background:#eaf1ff;color:#2448b8;border-color:#cfdbf5}.orc-grid-filter-trigger svg{width:14px;height:14px}.orc-grid-filter-menu.has-filter .orc-grid-filter-trigger,.orc-grid-filter-menu[open] .orc-grid-filter-trigger{background:#e8efff;color:#163da8;border-color:#b9c9f3}.orc-grid-filter-popover{position:absolute;top:calc(100% + 7px);right:0;z-index:80;width:240px;padding:12px;background:#fff;border:1px solid #d9e3f2;border-radius:12px;box-shadow:0 16px 38px rgba(15,35,80,.22);text-transform:none;letter-spacing:normal;font-weight:600}.orc-grid-filter-title{font-size:12px;font-weight:900;color:#18336f;margin-bottom:8px}.orc-grid-filter-input{width:100%;height:36px;border:1px solid #ccd8ea;border-radius:8px;padding:6px 9px;background:#fff;color:#0f172a;font-size:12px}.orc-grid-filter-actions{display:flex;gap:7px;margin-top:9px}.orc-grid-filter-actions .orc-btn{flex:1}.orc-col-hidden{display:none!important}.orc-actions-col{min-width:250px;position:sticky;right:0;box-shadow:-6px 0 10px rgba(15,35,80,.03)}.orc-advanced-grid thead .orc-actions-col{background:#f7faff!important}.orc-advanced-grid tbody tr:nth-child(odd) .orc-actions-col{background:#fff!important}.orc-advanced-grid tbody tr:nth-child(even) .orc-actions-col{background:#dfe8f4!important}.orc-advanced-grid tbody tr:hover .orc-actions-col{background:#cbdaf0!important}.orc-margin-cell{font-weight:900;white-space:nowrap}.orc-margin-cell.bad{color:#b91c1c}.orc-margin-cell.good{color:#15803d}.orc-empty-grid{text-align:center!important;color:#52627d;padding:34px!important;background:#fbfdff!important}.orc-list-head{display:flex;gap:12px;align-items:center;flex-wrap:wrap}.orc-list-head>div:first-child{flex:1}
@media(max-width:760px){.orc-mobile-cards{display:none!important}.orc-advanced-wrap{display:block}.orc-filter{grid-template-columns:1fr}.orc-grid-toolbar{justify-content:flex-start}.orc-columns-picker-menu{left:0;right:auto}}
</style>
<div class="orc-head"><h1>Orçamentos</h1><div class="orc-spacer"></div><a class="orc-btn primary" href="orcamento_editar.php">+ Novo Orçamento</a></div>
<?php if(trim((string)($_GET['ok']??''))!==''):?><div class="orc-alert ok"><?=orc_e($_GET['ok'])?></div><?php endif;?>
<?php if($showMargins&&!$costSnapshotReady):?><div class="orc-alert err"><b>V11.45.17 pendente.</b> As margens estão usando o custo atual do cadastro até a migration criar o snapshot histórico de custo. Aplique a atualização pelo Atualizador do Banco.</div><?php endif;?>
<div class="orc-card"><div class="orc-card-head">Filtros</div><div class="orc-card-body">
<form method="get" class="orc-filter" id="orcSearchForm">
  <div class="wide"><label>Pesquisar</label><input name="q" value="<?=orc_e($q)?>" placeholder="Nº, cliente, documento, vendedor, profissional, usuário ou observação"></div>
  <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(orc_statuses() as $k=>$v):?><option value="<?=orc_e($k)?>" <?=$status===$k?'selected':''?>><?=orc_e($v)?></option><?php endforeach;?></select></div>
  <div><label>Data inicial</label><input type="date" name="date_from" value="<?=orc_e($dateFrom)?>"></div>
  <div><label>Data final</label><input type="date" name="date_to" value="<?=orc_e($dateTo)?>"></div>
  <div class="orc-filter-actions"><button class="orc-btn primary" type="submit">Filtrar</button><a class="orc-btn" href="orcamentos.php">Hoje</a></div>
</form>
</div></div>

<div class="orc-card"><div class="orc-card-head orc-list-head"><div>Lista de Orçamentos <span class="orc-muted" style="font-weight:600">• <?=count($rows)?> registro(s)</span></div><div class="orc-grid-toolbar"><details class="orc-columns-picker" id="orcColumnsPicker"><summary class="orc-btn sm">Colunas</summary><div class="orc-columns-picker-menu"><strong>Colunas visíveis</strong><?php foreach($gridColumnLabels as $colKey=>$colLabel):?><?php if($colKey==='acoes')continue;?><label><input type="checkbox" data-column-toggle value="<?=orc_e($colKey)?>" checked> <?=orc_e($colLabel)?></label><?php endforeach;?><div class="orc-columns-note">A preferência fica salva no seu usuário. Arraste as colunas pelo marcador e puxe a divisória do cabeçalho para ajustar a largura. <span id="orcGridPreferenceStatus" class="orc-grid-pref-status"></span></div></div></details></div></div><div class="orc-card-body" style="padding:0">
<div class="orc-advanced-wrap"><table class="orc-advanced-grid" id="orcamentosGrid"><thead><tr>
<th data-col="numero"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('numero',$sortKey,$sortDir))?>">Nº <span><?=orc_e(orc_list_sort_mark('numero',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_numero','Nº',$gridFilters['numero'])?></div></th>
<th data-col="data"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('data',$sortKey,$sortDir))?>">Data <span><?=orc_e(orc_list_sort_mark('data',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_data','Data',$gridFilters['data'])?></div></th>
<th data-col="hora"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('hora',$sortKey,$sortDir))?>">Hora <span><?=orc_e(orc_list_sort_mark('hora',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_hora','Hora',$gridFilters['hora'])?></div></th>
<th data-col="cliente"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('cliente',$sortKey,$sortDir))?>">Cliente <span><?=orc_e(orc_list_sort_mark('cliente',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_cliente','Cliente',$gridFilters['cliente'])?></div></th>
<th data-col="vendedor"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('vendedor',$sortKey,$sortDir))?>">Vendedor <span><?=orc_e(orc_list_sort_mark('vendedor',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_vendedor','Vendedor',$gridFilters['vendedor'])?></div></th>
<th data-col="profissional"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('profissional',$sortKey,$sortDir))?>">Profissional <span><?=orc_e(orc_list_sort_mark('profissional',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_profissional','Profissional',$gridFilters['profissional'])?></div></th>
<th data-col="usuario"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('usuario',$sortKey,$sortDir))?>">Usuário <span><?=orc_e(orc_list_sort_mark('usuario',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_usuario','Usuário',$gridFilters['usuario'])?></div></th>
<th data-col="status"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('status',$sortKey,$sortDir))?>">Status <span><?=orc_e(orc_list_sort_mark('status',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_status','Status',$gridFilters['status'],'status')?></div></th>
<th data-col="total"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('total',$sortKey,$sortDir))?>">Total <span><?=orc_e(orc_list_sort_mark('total',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_total','Total',$gridFilters['total'],'number')?></div></th>
<?php if($showMargins):?><th data-col="margem_custo"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('margem_custo',$sortKey,$sortDir))?>">Margem s/ custo <span><?=orc_e(orc_list_sort_mark('margem_custo',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_margem_custo','Margem s/ custo',$gridFilters['margem_custo'],'number')?></div></th><th data-col="margem_venda"><div class="orc-grid-th-inner"><a class="orc-sort-link" href="<?=orc_e(orc_list_sort_url('margem_venda',$sortKey,$sortDir))?>">Margem s/ venda <span><?=orc_e(orc_list_sort_mark('margem_venda',$sortKey,$sortDir))?></span></a><?=orc_list_filter_menu('f_margem_venda','Margem s/ venda',$gridFilters['margem_venda'],'number')?></div></th><?php endif;?>
<th data-col="acoes" class="orc-actions-col">Ações</th>
</tr></thead><tbody>
<?php if(!$rows):?><tr><td colspan="<?=count($gridColumnLabels)?>" class="orc-empty-grid">Nenhum orçamento encontrado para os filtros informados.</td></tr><?php endif;?>
<?php foreach($rows as $r):$link=$osLinks[(int)$r['id']]??null;$mc=$r['margin_cost_percent']!==null?(float)$r['margin_cost_percent']:null;$mv=$r['margin_sale_percent']!==null?(float)$r['margin_sale_percent']:null;?><tr class="orc-status-row" style="--orc-row-status:<?=orc_e(orc_status_color((string)$r['status']))?>">
<td data-col="numero"><strong>#<?=str_pad((string)$r['quote_number'],6,'0',STR_PAD_LEFT)?></strong></td>
<td data-col="data"><?=date('d/m/Y',strtotime((string)$r['quote_date']))?></td>
<td data-col="hora"><?=!empty($r['created_at'])?date('H:i',strtotime((string)$r['created_at'])):'—'?></td>
<td data-col="cliente"><strong><?=orc_e($r['person_name_snapshot']?:'Não informado')?></strong><div class="orc-muted"><?=orc_e($r['person_document_snapshot'])?></div></td>
<td data-col="vendedor"><?=orc_e($r['seller_name_snapshot']?:'—')?></td>
<td data-col="profissional"><?=orc_e($r['professional_name_snapshot']?:'—')?></td>
<td data-col="usuario"><?=orc_e($r['created_by_name']?:'—')?></td>
<td data-col="status"><span class="orc-badge <?=orc_status_class((string)$r['status'])?>"><?=orc_e(orc_status_label((string)$r['status']))?></span></td>
<td data-col="total" class="num"><strong><?=orc_money($r['total_general'])?></strong></td>
<?php if($showMargins):?><td data-col="margem_custo" class="num orc-margin-cell <?=$mc!==null&&$mc<0?'bad':($mc!==null?'good':'')?>"><?=$mc===null?'—':number_format($mc,2,',','.').'%'?></td><td data-col="margem_venda" class="num orc-margin-cell <?=$mv!==null&&$mv<0?'bad':($mv!==null?'good':'')?>"><?=$mv===null?'—':number_format($mv,2,',','.').'%'?></td><?php endif;?>
<td data-col="acoes" class="orc-actions-col"><div class="orc-inline"><a class="orc-btn sm" href="orcamento_editar.php?id=<?=(int)$r['id']?>"><?=strtoupper((string)$r['status'])==='FATURADO'?'Visualizar':'Abrir'?></a><a class="orc-btn sm" <?=($printFocusId===(int)$r['id'])?'id="orcPrintFocus"':''?> target="_blank" href="orcamento_imprimir.php?id=<?=(int)$r['id']?>">Imprimir</a><?php if($osBridgeEnabled):?><?php if($link):?><a class="orc-btn sm success" href="os_editar.php?id=<?=(int)$link['os_order_id']?>">O.S. #<?=str_pad((string)(int)$link['os_number'],6,'0',STR_PAD_LEFT)?></a><?php elseif(!in_array((string)$r['status'],['RECUSADO','CANCELADO'],true)):?><a class="orc-btn sm" href="os_importar_orcamento.php?quote_id=<?=(int)$r['id']?>">Criar O.S.</a><?php endif;?><?php endif;?></div></td>
</tr><?php endforeach;?>
</tbody></table></div>
</div></div>
<script>
(function(){
 const grid=document.getElementById('orcamentosGrid');if(!grid)return;
 const companyId='<?= (int)$companyId ?>';const storageKey='nexa.orcamentos.grid.columns.company.'+companyId;const orderKey='nexa.orcamentos.grid.order.company.'+companyId;const widthKey='nexa.orcamentos.grid.widths.company.'+companyId;
 const preferenceCsrf=<?=json_encode($gridCsrf,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>;const serverPreferences=<?=json_encode($gridPreferences,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)?>||{};const hasServerPreferences=<?=$gridHasServerPreferences?'true':'false'?>;
 const preferenceStatus=document.getElementById('orcGridPreferenceStatus');const toggles=Array.from(document.querySelectorAll('[data-column-toggle]'));const available=toggles.map(cb=>cb.value);const wrap=grid.closest('.orc-advanced-wrap');
 const defaultWidths={numero:90,data:105,hora:85,cliente:250,vendedor:170,profissional:170,usuario:160,status:115,total:120,margem_custo:135,margem_venda:135,acoes:260};const minWidths={numero:75,data:90,hora:70,cliente:150,vendedor:110,profissional:110,usuario:110,status:95,total:95,margem_custo:110,margem_venda:110,acoes:245};
 let legacy=false;let visible=available.slice();if(hasServerPreferences&&Array.isArray(serverPreferences.visible))visible=available.filter(c=>serverPreferences.visible.includes(c));else{try{const v=JSON.parse(localStorage.getItem(storageKey)||'null');if(Array.isArray(v)){visible=available.filter(c=>v.includes(c));legacy=true;}}catch(e){}}
 const headerCols=Array.from(grid.querySelectorAll('thead th[data-col]')).map(th=>th.dataset.col).filter(Boolean);const draggableCols=headerCols.filter(c=>c!=='acoes');let columnOrder=draggableCols.slice();if(hasServerPreferences&&Array.isArray(serverPreferences.order)){const valid=serverPreferences.order.filter(c=>draggableCols.includes(c));columnOrder=valid.concat(draggableCols.filter(c=>!valid.includes(c)));}else{try{const v=JSON.parse(localStorage.getItem(orderKey)||'null');if(Array.isArray(v)){const valid=v.filter(c=>draggableCols.includes(c));columnOrder=valid.concat(draggableCols.filter(c=>!valid.includes(c)));legacy=true;}}catch(e){}}
 let widths={};if(hasServerPreferences&&serverPreferences.widths&&typeof serverPreferences.widths==='object'&&!Array.isArray(serverPreferences.widths))widths=Object.assign({},serverPreferences.widths);else{try{const v=JSON.parse(localStorage.getItem(widthKey)||'{}');if(v&&typeof v==='object'&&!Array.isArray(v)&&Object.keys(v).length){widths=v;legacy=true;}}catch(e){}}
 let saveTimer=null,saveSeq=0;function status(t,c){if(!preferenceStatus)return;preferenceStatus.textContent=t||'';preferenceStatus.className='orc-grid-pref-status'+(c?' '+c:'');}function local(){try{localStorage.setItem(storageKey,JSON.stringify(visible));localStorage.setItem(orderKey,JSON.stringify(columnOrder));localStorage.setItem(widthKey,JSON.stringify(widths));}catch(e){}}
 function prefs(){const w={};Object.keys(widths).forEach(c=>{if(available.includes(c)&&Number.isFinite(Number(widths[c])))w[c]=Math.round(Number(widths[c]));});return{visible:visible.filter(c=>available.includes(c)),order:columnOrder.filter(c=>draggableCols.includes(c)),widths:w};}
 async function save(){local();const seq=++saveSeq;status('Salvando…','saving');try{const body=new URLSearchParams();body.set('action','save_grid_preferences');body.set('csrf_token',preferenceCsrf);body.set('preferences',JSON.stringify(prefs()));const r=await fetch('orcamentos.php',{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded;charset=UTF-8','X-Requested-With':'XMLHttpRequest'},body:body.toString()});const d=await r.json().catch(()=>({}));if(!r.ok||!d.ok)throw new Error(d.message||'Falha');if(seq===saveSeq){status('Salvo no usuário','saved');setTimeout(()=>{if(seq===saveSeq)status('','');},1800);}}catch(e){if(seq===saveSeq)status('Não foi possível salvar','error');}}
 function schedule(ms){if(saveTimer)clearTimeout(saveTimer);saveTimer=setTimeout(save,typeof ms==='number'?ms:180);}function ordered(){return columnOrder.concat(headerCols.includes('acoes')?['acoes']:[]);}function applyOrder(){const order=ordered();grid.querySelectorAll('tr').forEach(row=>{const by={};Array.from(row.children).forEach(cell=>{const c=cell.getAttribute&&cell.getAttribute('data-col');if(c)by[c]=cell;});if(!Object.keys(by).length)return;order.forEach(c=>{if(by[c])row.appendChild(by[c]);});});}
 function widthFor(c){const n=Number(widths[c]);return Number.isFinite(n)&&n>0?n:(defaultWidths[c]||120);}function refresh(){if(!wrap)return;let total=0;ordered().forEach(c=>{if(c!=='acoes'&&!visible.includes(c))return;total+=widthFor(c);});grid.style.width=Math.max(wrap.clientWidth||0,total)+'px';}function applyWidth(c,px,persist){const min=minWidths[c]||70,max=c==='cliente'?650:480,v=Math.max(min,Math.min(max,Math.round(px)));if(persist){widths[c]=v;local();schedule(180);}grid.querySelectorAll('[data-col="'+CSS.escape(c)+'"]').forEach(el=>{el.style.width=v+'px';el.style.minWidth=v+'px';el.style.maxWidth=v+'px';});refresh();}function applyWidths(){ordered().forEach(c=>applyWidth(c,widthFor(c),false));refresh();}function applyColumns(){toggles.forEach(cb=>cb.checked=visible.includes(cb.value));available.forEach(c=>grid.querySelectorAll('[data-col="'+CSS.escape(c)+'"]').forEach(el=>el.classList.toggle('orc-col-hidden',!visible.includes(c))));refresh();}
 toggles.forEach(cb=>cb.addEventListener('change',function(){if(this.checked){if(!visible.includes(this.value))visible.push(this.value);}else visible=visible.filter(v=>v!==this.value);local();applyColumns();schedule(120);}));
 let dragging=null,dropAfter=false;function clearMarks(){grid.querySelectorAll('thead th').forEach(th=>th.classList.remove('col-drop-before','col-drop-after','col-dragging'));}
 draggableCols.forEach(c=>{const th=grid.querySelector('thead th[data-col="'+CSS.escape(c)+'"]');if(!th)return;const host=th.querySelector('.orc-grid-th-inner')||th;const h=document.createElement('span');h.className='orc-col-drag-handle';h.textContent='⋮⋮';h.title='Arrastar coluna';h.draggable=true;host.insertBefore(h,host.firstChild);h.addEventListener('dragstart',e=>{dragging=c;th.classList.add('col-dragging');try{e.dataTransfer.setData('text/plain',c);}catch(x){}if(e.dataTransfer)e.dataTransfer.effectAllowed='move';});h.addEventListener('dragend',()=>{dragging=null;clearMarks();});});
 grid.querySelectorAll('thead th[data-col]').forEach(th=>{const target=th.dataset.col||'';if(!target||target==='acoes')return;th.addEventListener('dragover',e=>{if(!dragging||dragging===target)return;e.preventDefault();const r=th.getBoundingClientRect();dropAfter=e.clientX>r.left+r.width/2;clearMarks();th.classList.add(dropAfter?'col-drop-after':'col-drop-before');const src=grid.querySelector('thead th[data-col="'+CSS.escape(dragging)+'"]');if(src)src.classList.add('col-dragging');});th.addEventListener('drop',e=>{if(!dragging||dragging===target)return;e.preventDefault();const next=columnOrder.filter(c=>c!==dragging);let idx=next.indexOf(target);if(idx<0)idx=next.length;if(dropAfter)idx++;next.splice(idx,0,dragging);columnOrder=next;local();schedule(120);applyOrder();applyWidths();dragging=null;clearMarks();});});
 draggableCols.forEach(c=>{const th=grid.querySelector('thead th[data-col="'+CSS.escape(c)+'"]');if(!th)return;const r=document.createElement('span');r.className='orc-col-resizer';r.title='Arraste para ajustar a largura';th.appendChild(r);r.addEventListener('pointerdown',e=>{if(e.button!==0)return;e.preventDefault();e.stopPropagation();const x=e.clientX,w=th.getBoundingClientRect().width||widthFor(c);grid.classList.add('resizing-columns');document.body.style.userSelect='none';const move=ev=>applyWidth(c,w+(ev.clientX-x),false);const up=ev=>{document.removeEventListener('pointermove',move);grid.classList.remove('resizing-columns');document.body.style.userSelect='';applyWidth(c,w+(ev.clientX-x),true);};document.addEventListener('pointermove',move);document.addEventListener('pointerup',up,{once:true});});});
 applyOrder();applyColumns();applyWidths();window.addEventListener('resize',refresh);if(!hasServerPreferences&&legacy)schedule(350);
 const form=document.getElementById('orcSearchForm'),menus=Array.from(grid.querySelectorAll('[data-orc-grid-filter-menu]'));menus.forEach(menu=>menu.addEventListener('toggle',function(){const th=this.closest('th');if(th)th.classList.toggle('filter-open',this.open);if(!this.open)return;menus.forEach(o=>{if(o!==this){o.open=false;const oth=o.closest('th');if(oth)oth.classList.remove('filter-open');}});setTimeout(()=>{const input=this.querySelector('.orc-grid-filter-input');if(input)input.focus();},20);}));document.addEventListener('click',e=>menus.forEach(menu=>{if(menu.open&&!menu.contains(e.target)){menu.open=false;const th=menu.closest('th');if(th)th.classList.remove('filter-open');}}));grid.querySelectorAll('.orc-grid-filter-input').forEach(el=>el.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();if(form)form.requestSubmit();}else if(e.key==='Escape'){const m=this.closest('[data-orc-grid-filter-menu]');if(m)m.open=false;}}));grid.querySelectorAll('[data-clear-filter]').forEach(btn=>btn.addEventListener('click',function(){if(!form)return;const n=this.getAttribute('data-clear-filter')||'';const f=form.querySelector('[name="'+n.replace(/"/g,'')+'"]');if(f)f.value='';form.requestSubmit();}));
 const printFocus=document.getElementById('orcPrintFocus');if(printFocus){setTimeout(()=>{try{printFocus.scrollIntoView({block:'center',behavior:'smooth'});}catch(e){}printFocus.focus();},120);}
})();
</script>
<?php orc_page_end(); ?>
