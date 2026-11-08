<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/../config/finance_module.php';

$cid = function_exists('fiscal_v10_3_supplier_return_company_id') ? (int)fiscal_v10_3_supplier_return_company_id($pdo) : (int)COMPANY_ID;
$supplierReturnCrossContext = function_exists('fiscal_v10_3_supplier_return_is_cross_context') && fiscal_v10_3_supplier_return_is_cross_context($pdo);
$supplierReturnCompanyName = function_exists('fiscal_v10_entry_company_name') ? fiscal_v10_entry_company_name($pdo, $cid) : ('Empresa #' . $cid);
$err = '';
try {
  fiscal_xml_ensure_schema($pdo);
  fiscal_ensure_document_reference_schema($pdo);
  fiscal_nfce_ensure_schema($pdo);
  finance_ensure_schema($pdo);
} catch (Throwable $e) { $err = $e->getMessage(); }

if (!function_exists('fiscal_saida_xml_tax_map')) {
  function fiscal_saida_xml_tax_map(string $xml): array {
    if (trim($xml) === '' || !class_exists('DOMDocument')) return [];
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    $ok = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
    libxml_clear_errors(); libxml_use_internal_errors($old);
    if (!$ok) return [];
    $xp = new DOMXPath($dom);
    $value = static function(DOMXPath $xp, DOMNode $ctx, string $name): string {
      $n = $xp->query('.//*[local-name()="' . $name . '"][1]', $ctx)?->item(0);
      return $n ? trim((string)$n->textContent) : '';
    };
    $number = static function(string $raw): float { return fiscal_xml_parse_decimal($raw, 0); };
    $out = [];
    foreach (($xp->query('//*[local-name()="det"]') ?: []) as $det) {
      if (!$det instanceof DOMElement) continue;
      $nItem = (int)($det->getAttribute('nItem') ?: (count($out) + 1));
      $icms = $xp->query('.//*[local-name()="ICMS"]', $det)?->item(0);
      $ipi = $xp->query('.//*[local-name()="IPI"]', $det)?->item(0);
      $pis = $xp->query('.//*[local-name()="PIS"]', $det)?->item(0);
      $cofins = $xp->query('.//*[local-name()="COFINS"]', $det)?->item(0);
      $out[$nItem] = [
        'icms_origin'=>$icms?$value($xp,$icms,'orig'):'', 'icms_cst'=>$icms?$value($xp,$icms,'CST'):'', 'icms_csosn'=>$icms?$value($xp,$icms,'CSOSN'):'',
        'icms_base'=>$icms?$number($value($xp,$icms,'vBC')):0, 'icms_rate'=>$icms?$number($value($xp,$icms,'pICMS')):0, 'icms_amount'=>$icms?$number($value($xp,$icms,'vICMS')):0,
        'icms_st_base'=>$icms?$number($value($xp,$icms,'vBCST')):0, 'icms_st_rate'=>$icms?$number($value($xp,$icms,'pICMSST')):0, 'icms_st_amount'=>$icms?$number($value($xp,$icms,'vICMSST')):0,
        'icms_st_mva'=>$icms?$number($value($xp,$icms,'pMVAST')):0, 'icms_st_reduction'=>$icms?$number($value($xp,$icms,'pRedBCST')):0,
        'fcp_st_base'=>$icms?$number($value($xp,$icms,'vBCFCPST')):0, 'fcp_st_rate'=>$icms?$number($value($xp,$icms,'pFCPST')):0, 'fcp_st_amount'=>$icms?$number($value($xp,$icms,'vFCPST')):0,
        'ipi_cst'=>$ipi?$value($xp,$ipi,'CST'):'', 'ipi_cenq'=>$ipi?$value($xp,$ipi,'cEnq'):'', 'ipi_base'=>$ipi?$number($value($xp,$ipi,'vBC')):0, 'ipi_rate'=>$ipi?$number($value($xp,$ipi,'pIPI')):0, 'ipi_amount'=>$ipi?$number($value($xp,$ipi,'vIPI')):0,
        'pis_cst'=>$pis?$value($xp,$pis,'CST'):'', 'pis_base'=>$pis?$number($value($xp,$pis,'vBC')):0, 'pis_rate'=>$pis?$number($value($xp,$pis,'pPIS')):0, 'pis_amount'=>$pis?$number($value($xp,$pis,'vPIS')):0,
        'cofins_cst'=>$cofins?$value($xp,$cofins,'CST'):'', 'cofins_base'=>$cofins?$number($value($xp,$cofins,'vBC')):0, 'cofins_rate'=>$cofins?$number($value($xp,$cofins,'pCOFINS')):0, 'cofins_amount'=>$cofins?$number($value($xp,$cofins,'vCOFINS')):0,
      ];
    }
    return $out;
  }
}

if (!function_exists('fiscal_saida_xml_supplier_city_ibge')) {
  function fiscal_saida_xml_supplier_city_ibge(string $xml): string {
    if (trim($xml) === '') return '';
    if (preg_match('/<(?:(?:[A-Za-z_][A-Za-z0-9_.-]*):)?enderEmit\b[^>]*>.*?<(?:(?:[A-Za-z_][A-Za-z0-9_.-]*):)?cMun\b[^>]*>(\d{7})<\//is', $xml, $m)) return (string)$m[1];
    return '';
  }
}

if (!function_exists('fiscal_saida_tax_defaults')) {
  function fiscal_saida_tax_defaults(array $item = [], array $xmlTax = []): array {
    $decoded = [];
    if (!empty($item['tax_json'])) {
      $tmp = json_decode((string)$item['tax_json'], true);
      if (is_array($tmp)) $decoded = $tmp;
    }
    $base = $decoded + $xmlTax;
    $defaults = [
      'icms_origin'=>$item['origem'] ?? '', 'icms_cst'=>$item['icms_cst'] ?? '', 'icms_csosn'=>$item['icms_csosn'] ?? '',
      'icms_base'=>'','icms_rate'=>'','icms_amount'=>'','icms_st_base'=>'','icms_st_rate'=>'','icms_st_amount'=>(float)($item['icms_st_value'] ?? 0),
      'icms_st_mva'=>'','icms_st_reduction'=>'','fcp_st_base'=>'','fcp_st_rate'=>'','fcp_st_amount'=>'',
      'ipi_cst'=>$item['ipi_cst'] ?? '', 'ipi_cenq'=>'999', 'ipi_base'=>'','ipi_rate'=>'','ipi_amount'=>(float)($item['ipi_value'] ?? 0),
      'pis_cst'=>$item['pis_cst'] ?? '', 'pis_base'=>'','pis_rate'=>'','pis_amount'=>'',
      'cofins_cst'=>$item['cofins_cst'] ?? '', 'cofins_base'=>'','cofins_rate'=>'','cofins_amount'=>'',
    ];
    foreach ($defaults as $key=>$value) if (array_key_exists($key,$base) && $base[$key] !== '' && $base[$key] !== null) $defaults[$key]=$base[$key];
    return $defaults;
  }
}

$showForm = isset($_GET['novo']) || $_SERVER['REQUEST_METHOD'] === 'POST';
$selectedEntryIds = $_GET['source_entry_ids'] ?? $_POST['source_entry_ids'] ?? [];
if (!is_array($selectedEntryIds)) $selectedEntryIds = [$selectedEntryIds];
$selectedEntryIds = array_values(array_unique(array_filter(array_map('intval', $selectedEntryIds), static fn($v) => $v > 0)));

try {
  if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $_POST['direction'] = 'SAIDA';
    $_POST['source_type'] = $selectedEntryIds ? 'SAIDA_MANUAL_COMPRA' : 'SAIDA_MANUAL';
    if ($supplierReturnCrossContext) {
      if (function_exists('fiscal_v10_3_assert_supplier_person')) {
        fiscal_v10_3_assert_supplier_person($pdo, $cid, (int)($_POST['person_person_id'] ?? 0));
      }
      if (function_exists('fiscal_v10_3_assert_supplier_return_operation')) {
        fiscal_v10_3_assert_supplier_return_operation($pdo, $cid, (int)($_POST['operation_id'] ?? 0));
      }
      if (function_exists('fiscal_v10_3_assert_source_entries_and_items')) {
        fiscal_v10_3_assert_source_entries_and_items($pdo, $cid, $selectedEntryIds, $_POST);
      }
    }
    fiscal_create_manual_movement_document($pdo, $cid, $_POST);
    if ($supplierReturnCrossContext) {
      $url = 'fiscal_saidas.php?ok=' . rawurlencode('Devolução a fornecedor registrada em ' . $supplierReturnCompanyName . '. O estoque foi baixado conforme a operação selecionada. Emissão da pré-NF-e permanece bloqueada no contexto F11.');
      if (function_exists('fiscal_v10_3_supplier_return_context_url')) $url = fiscal_v10_3_supplier_return_context_url($url);
      header('Location: ' . $url);
    } else {
      header('Location: fiscal_saidas.php?ok=' . rawurlencode('Saída fiscal salva e XML da pré-NF-e gerado. Agora use o botão Gerar NF-e na lista.'));
    }
    exit;
  }
} catch (Throwable $e) { $err = $e->getMessage(); $showForm = true; }

$operations = fiscal_operations($pdo, $cid, 'SAIDA', true);
if ($supplierReturnCrossContext) {
  $operations = array_values(array_filter($operations, static function(array $op): bool {
    $type = strtoupper(trim((string)($op['operation_type'] ?? '')));
    $name = strtoupper(trim((string)($op['name'] ?? '')));
    $isSupplierReturn = $type === 'DEVOLUCAO_FORNECEDOR'
      || (str_contains($type, 'DEVOL') && str_contains($type, 'FORNEC'))
      || (str_contains($name, 'DEVOL') && str_contains($name, 'FORNEC'));
    return $isSupplierReturn && (int)($op['update_stock'] ?? 0) === 1 && (int)($op['create_finance'] ?? 0) === 0;
  }));
}
$suppliers = fiscal_finance_people_options($pdo, $cid, 'supplier', '', 500);
$products = fiscal_product_options_for_movement($pdo, $cid, '', 600);

$entryCandidates = [];
try {
  $st = $pdo->prepare("SELECT id,access_key,model,number,series,issue_date,entry_date,supplier_doc,supplier_name,supplier_ie,supplier_uf,supplier_city,supplier_city_ibge,supplier_address,supplier_number,supplier_district,supplier_zip,total_invoice,status,xml_content FROM fiscal_xml_imports WHERE company_id=? AND COALESCE(number,'')<>'0' ORDER BY COALESCE(entry_date,issue_date) DESC,id DESC LIMIT 500");
  $st->execute([$cid]); $entryCandidates = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { if ($err === '') $err = $e->getMessage(); }
$entryMap=[]; foreach($entryCandidates as $entry)$entryMap[(int)$entry['id']]=$entry;
$selectedEntries=[]; foreach($selectedEntryIds as $entryId)if(isset($entryMap[$entryId]))$selectedEntries[]=$entryMap[$entryId];
$sourceSupplierWarning='';
if(count($selectedEntries)>1){$docs=array_values(array_unique(array_filter(array_map(static fn($e)=>fiscal_digits($e['supplier_doc']??''),$selectedEntries))));if(count($docs)>1)$sourceSupplierWarning='As entradas selecionadas pertencem a fornecedores diferentes. Escolha abaixo o destinatário correto da saída fiscal.';}

$prefillPerson=['person_id'=>'','name'=>'','document'=>'','state_registration'=>'','phone'=>'','email'=>'','zip_code'=>'','address'=>'','number'=>'','complement'=>'','district'=>'','city'=>'','city_ibge'=>'','state'=>''];
if($selectedEntries){
  $first=$selectedEntries[0];$doc=fiscal_digits($first['supplier_doc']??'');$entryIbge=fiscal_digits($first['supplier_city_ibge']??'');if(strlen($entryIbge)!==7)$entryIbge=fiscal_saida_xml_supplier_city_ibge((string)($first['xml_content']??''));
  foreach($suppliers as $supplier){if($doc!==''&&fiscal_digits($supplier['document']??'')===$doc){$prefillPerson=['person_id'=>(int)$supplier['id'],'name'=>$supplier['name']??'','document'=>$supplier['document']??'','state_registration'=>(($supplier['state_registration']??'')?:($first['supplier_ie']??'')),'phone'=>($supplier['whatsapp']??'')?:($supplier['phone']??''),'email'=>$supplier['email']??'','zip_code'=>$supplier['zip_code']??'','address'=>$supplier['address']??'','number'=>$supplier['number']??'','complement'=>$supplier['complement']??'','district'=>$supplier['district']??'','city'=>$supplier['city']??'','city_ibge'=>($supplier['city_ibge']??'')?:$entryIbge,'state'=>$supplier['state']??''];break;}}
  if($prefillPerson['name']==='')$prefillPerson=['person_id'=>'','name'=>$first['supplier_name']??'','document'=>$first['supplier_doc']??'','state_registration'=>$first['supplier_ie']??'','phone'=>'','email'=>'','zip_code'=>$first['supplier_zip']??'','address'=>$first['supplier_address']??'','number'=>$first['supplier_number']??'','complement'=>'','district'=>$first['supplier_district']??'','city'=>$first['supplier_city']??'','city_ibge'=>$entryIbge,'state'=>$first['supplier_uf']??''];
}
if($_SERVER['REQUEST_METHOD']==='POST'){foreach($prefillPerson as $key=>$value){$postKey='person_'.($key==='state_registration'?'ie':($key==='zip_code'?'zip':$key));if(array_key_exists($postKey,$_POST))$prefillPerson[$key]=(string)$_POST[$postKey];}}

$taxFields=['icms_origin','icms_cst','icms_csosn','icms_base','icms_rate','icms_amount','icms_st_base','icms_st_rate','icms_st_amount','icms_st_mva','icms_st_reduction','fcp_st_base','fcp_st_rate','fcp_st_amount','ipi_cst','ipi_cenq','ipi_base','ipi_rate','ipi_amount','pis_cst','pis_base','pis_rate','pis_amount','cofins_cst','cofins_base','cofins_rate','cofins_amount'];
$itemRows=[];
if($_SERVER['REQUEST_METHOD']==='POST'&&$err!==''){
  $postedIds=(array)($_POST['product_id']??[]);$count=max(count($postedIds),count((array)($_POST['quantity']??[])));
  for($i=0;$i<$count;$i++){
    $row=['product_id'=>(int)($postedIds[$i]??0),'product_label'=>(string)(((array)($_POST['product_label']??[]))[$i]??''),'quantity'=>(string)(((array)($_POST['quantity']??[]))[$i]??''),'unit_value'=>(string)(((array)($_POST['unit_value']??[]))[$i]??''),'cfop'=>(string)(((array)($_POST['cfop']??[]))[$i]??''),'source_label'=>(string)(((array)($_POST['source_label']??[]))[$i]??''),'max_quantity'=>(string)(((array)($_POST['max_quantity']??[]))[$i]??''),'source_item_id'=>(int)(((array)($_POST['source_item_id']??[]))[$i]??0),'unit'=>'','ncm'=>'','cest'=>'','original_cfop'=>''];
    foreach($taxFields as $f)$row[$f]=(string)(((array)($_POST[$f]??[]))[$i]??'');$itemRows[]=$row;
  }
}elseif($selectedEntries){
  foreach($selectedEntries as $entry){$xmlMap=fiscal_saida_xml_tax_map((string)($entry['xml_content']??''));try{$entryItems=fiscal_report_items($pdo,$cid,(int)$entry['id']);}catch(Throwable $e){$entryItems=[];}
    foreach($entryItems as $item){$payload=fiscal_report_item_system_payload($item);$pid=(int)($payload['product_id']??0);$label=trim((string)($payload['code']??'').' · '.(string)($payload['name']??''));if($pid<=0)$label=trim((string)($item['product_code']??'').' · '.(string)($item['name']??''));$taxData=fiscal_saida_tax_defaults($item,$xmlMap[(int)($item['item_number']??0)]??[]);
      $row=['product_id'=>$pid,'product_label'=>$label,'quantity'=>(string)(float)($item['quantity']??0),'max_quantity'=>(string)(float)($item['quantity']??0),'unit_value'=>number_format((float)($item['unit_value']??0),2,',',''),'cfop'=>'','source_item_id'=>(int)($item['id']??0),'source_label'=>'Entrada NF-e '.($entry['series']??'').'/'.($entry['number']??'').' · '.($entry['supplier_name']??''),'unit'=>$payload['unit']??($item['unit']??'UN'),'ncm'=>$payload['ncm']??($item['ncm']??''),'cest'=>$payload['cest']??($item['cest']??''),'original_cfop'=>$item['cfop']??'']+$taxData;$itemRows[]=$row;
    }
  }
}
if(!$itemRows)$itemRows[]=['product_id'=>0,'product_label'=>'','quantity'=>'','max_quantity'=>'','unit_value'=>'','cfop'=>'','source_item_id'=>0,'source_label'=>'','unit'=>'','ncm'=>'','cest'=>'','original_cfop'=>'']+fiscal_saida_tax_defaults();

$referenceKeys=[];foreach($selectedEntries as $entry){$key=fiscal_digits($entry['access_key']??'');if(strlen($key)===44)$referenceKeys[$key]=$key;}$referenceText=implode("\n",array_values($referenceKeys));if($_SERVER['REQUEST_METHOD']==='POST')$referenceText=trim((string)($_POST['reference_keys']??$referenceText));
$q=trim((string)($_GET['q']??''));$statusFilter=trim((string)($_GET['status']??''));$defaultFim=date('Y-m-d');$defaultIni=date('Y-m-d',strtotime('-9 days'));$ini=fiscal_xml_date($_GET['ini']??$defaultIni)?:$defaultIni;$fim=fiscal_xml_date($_GET['fim']??$defaultFim)?:$defaultFim;$where=["fd.company_id=?","fd.direction='SAIDA'","UPPER(COALESCE(fd.source_type,'')) LIKE 'SAIDA_MANUAL%'"];$params=[$cid];if($supplierReturnCrossContext){$where[]="EXISTS (SELECT 1 FROM fiscal_operations fo WHERE fo.company_id=fd.company_id AND fo.id=fd.operation_nature_id AND (UPPER(COALESCE(fo.operation_type,''))='DEVOLUCAO_FORNECEDOR' OR (UPPER(COALESCE(fo.operation_type,'')) LIKE '%DEVOL%' AND UPPER(COALESCE(fo.operation_type,'')) LIKE '%FORNEC%') OR (UPPER(COALESCE(fo.name,'')) LIKE '%DEVOL%' AND UPPER(COALESCE(fo.name,'')) LIKE '%FORNEC%')))";}
if($q!==''){$like=nexa_search_like($q);$where[]="(fd.number LIKE ? OR fd.series LIKE ? OR fd.operation_nature LIKE ? OR fd.access_key LIKE ? OR EXISTS (SELECT 1 FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND (fp.name LIKE ? OR fp.document LIKE ?)))";array_push($params,$like,$like,$like,$like,$like,$like);}if($statusFilter!==''){$where[]='fd.status=?';$params[]=$statusFilter;}if($ini!==''){$where[]='DATE(fd.issue_datetime)>=?';$params[]=$ini;}if($fim!==''){$where[]='DATE(fd.issue_datetime)<=?';$params[]=$fim;}
$recent=[];try{$sql="SELECT fd.*,(SELECT fp.name FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS participant_name,(SELECT fp.document FROM fiscal_document_participants fp WHERE fp.company_id=fd.company_id AND fp.fiscal_document_id=fd.id AND UPPER(fp.role)='DESTINATARIO' ORDER BY fp.id DESC LIMIT 1) AS participant_document FROM fiscal_documents fd WHERE ".implode(' AND ',$where)." ORDER BY fd.created_at DESC,fd.id DESC LIMIT 300";$st=$pdo->prepare($sql);$st->execute($params);$recent=$st->fetchAll(PDO::FETCH_ASSOC)?:[];}catch(Throwable $e){if($err==='')$err=$e->getMessage();}

$saidaPageTitle = $supplierReturnCrossContext ? ('Devolução a fornecedor · ' . $supplierReturnCompanyName) : 'Saídas fiscais';
$saidaSubtitle = $supplierReturnCrossContext
  ? ($showForm ? 'Registre a devolução ao fornecedor usando apenas entradas, fornecedor, produtos e operação fiscal da empresa selecionada pelo F11.' : 'Consulte as devoluções a fornecedor da empresa selecionada pelo F11. Emissão fiscal e financeiro continuam protegidos nesta etapa.')
  : ($showForm ? 'Selecione entradas de compra, fornecedor e produtos para gerar uma pré-NF-e de saída.' : 'Consulte as saídas fiscais e gere a NF-e quando os dados e o XML estiverem conferidos.');
$saidaListUrl = function_exists('fiscal_v10_3_supplier_return_context_url') ? fiscal_v10_3_supplier_return_context_url('fiscal_saidas.php') : 'fiscal_saidas.php';
$saidaNewUrl = function_exists('fiscal_v10_3_supplier_return_context_url') ? fiscal_v10_3_supplier_return_context_url('fiscal_saidas.php?novo=1') : 'fiscal_saidas.php?novo=1';
$saidaHeaderActions = [
  ['label'=>$showForm?'Voltar à lista':($supplierReturnCrossContext?'+ Nova devolução a fornecedor':'+ Nova saída fiscal'),'href'=>$showForm?$saidaListUrl:$saidaNewUrl,'class'=>'btn btn-primary'],
];
if (!$supplierReturnCrossContext) $saidaHeaderActions[] = ['label'=>'Documentos fiscais','href'=>'fiscal_documentos.php','class'=>'btn'];
$saidaHeaderActions[] = ['label'=>'Operações fiscais','href'=>function_exists('fiscal_v10_3_supplier_return_context_url')?fiscal_v10_3_supplier_return_context_url('fiscal_operacoes.php'):'fiscal_operacoes.php','class'=>'btn'];

fiscal_admin_start($supplierReturnCrossContext ? 'Fiscal - Devolução a fornecedor' : 'Fiscal - Saídas','fiscal_saidas');
fiscal_page_header($saidaPageTitle,$saidaSubtitle,$saidaHeaderActions);
fiscal_tabs('fiscal_saidas');fiscal_flash();
if($supplierReturnCrossContext) echo '<div class="msg ok"><strong>V10.3 · Devolução a Fornecedor Multiempresa:</strong> fornecedor, entradas de origem, produtos, operação e baixa de estoque estão operando em '.fiscal_h($supplierReturnCompanyName).'. Emissão/transmissão fiscal e Financeiro continuam bloqueados pelo F11.</div>';
if($supplierReturnCrossContext && !$operations) echo '<div class="msg err">Nenhuma operação de devolução a fornecedor elegível foi encontrada em '.fiscal_h($supplierReturnCompanyName).'. Configure uma operação de saída do tipo DEVOLUCAO_FORNECEDOR com movimentação de estoque habilitada e sem geração financeira.</div>';
if($err)echo '<div class="msg err">'.fiscal_h($err).'</div>';
?>
<style>
.saida-source-details{margin-top:6px;font-size:12px;color:#64748b}.saida-total-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:10px;margin-top:12px}.saida-total-card{border:1px solid #dbe4f0;border-radius:16px;background:#f8fbff;padding:12px}.saida-total-card span{display:block;font-size:12px;color:#64748b}.saida-total-card strong{font-size:19px;color:#047857}.saida-modal-results{max-height:420px;overflow:auto}.saida-supplier-card{border:1px solid #dbe4f0;border-radius:16px;padding:12px;margin-bottom:8px;display:flex;justify-content:space-between;gap:12px;align-items:center}.saida-items-table{min-width:1180px}.saida-items-table .cfop-input{min-width:112px;width:112px;text-align:center;font-weight:800}.saida-tax-row td{background:#f8fafc!important;padding:8px 12px 13px!important}.saida-tax-details summary{cursor:pointer;font-weight:800;color:#1e3a8a}.saida-tax-grid{display:grid;grid-template-columns:repeat(4,minmax(180px,1fr));gap:12px 14px;margin-top:12px;align-items:end}.saida-tax-grid h4{grid-column:1/-1;margin:12px 0 0;padding-top:10px;border-top:1px solid #dbe4f0;color:#0f172a;font-size:14px}.saida-tax-grid h4:first-child{margin-top:0;padding-top:0;border-top:0}.saida-tax-grid label{display:flex;flex-direction:column;gap:6px;min-width:0;font-size:12px;line-height:1.25;font-weight:750;color:#475569}.saida-tax-grid input{display:block;width:100%;min-width:0;height:44px;margin:0!important;padding:0 12px;position:static!important;font:inherit;color:#0f172a;background:#fff}.saida-actions-row td{background:#f8fafc}.remove-row{white-space:nowrap}.saida-search-form{display:grid;grid-template-columns:minmax(260px,2.4fr) minmax(150px,.9fr) minmax(135px,.75fr) minmax(135px,.75fr) auto;gap:10px;align-items:end}.saida-search-form .fiscal-filter-actions{justify-content:flex-end;align-items:end;flex-wrap:nowrap}.saida-search-form .fiscal-filter-actions .btn{white-space:nowrap}@media(max-width:1250px){.saida-tax-grid{grid-template-columns:repeat(3,minmax(170px,1fr))}}@media(max-width:900px){.saida-total-grid{grid-template-columns:repeat(2,1fr)}.saida-tax-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.saida-search-form{grid-template-columns:repeat(2,minmax(0,1fr))}.saida-search-form .saida-search-query{grid-column:1/-1}.saida-search-form .fiscal-filter-actions{grid-column:1/-1}}@media(max-width:560px){.saida-total-grid,.saida-tax-grid,.saida-search-form{grid-template-columns:1fr}.saida-search-form .saida-search-query,.saida-search-form .fiscal-filter-actions{grid-column:auto}.saida-search-form .fiscal-filter-actions{justify-content:stretch}.saida-search-form .fiscal-filter-actions .btn{flex:1}}

.saida-list-grid{min-width:1000px}.saida-list-grid .fiscal-actions-head,.saida-list-grid .fiscal-actions-col{width:200px;min-width:200px;max-width:200px}
.saida-list-grid .fiscal-actions-col{vertical-align:top;padding:10px}.saida-list-grid .fiscal-actions-col .fiscal-inline-actions{display:flex;flex-direction:column;align-items:stretch;justify-content:flex-start;gap:6px}
.saida-list-grid .fiscal-actions-col form{display:block!important;margin:0}.saida-list-grid .fiscal-actions-col .btn{display:flex;width:100%;min-height:34px;align-items:center;justify-content:center;box-sizing:border-box;text-align:center}
.saida-list-grid .fiscal-cell-wrap,.saida-status-help{display:block;max-width:100%;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.saida-status-help{font-size:12px;line-height:1.35;color:#64748b;margin-top:5px}
@media(max-width:680px){.saida-list-grid{min-width:0}.saida-list-grid .fiscal-actions-head,.saida-list-grid .fiscal-actions-col{width:auto;min-width:0;max-width:none}.saida-list-grid .fiscal-actions-col .fiscal-inline-actions{width:100%;max-width:230px;margin-left:auto}}
</style>
<?php if(!$showForm): ?>
<div class="fiscal-card">
  <form method="get" id="saidaSearchForm" class="fiscal-form saida-search-form">
    <div class="saida-search-query"><label>Pesquisar fornecedor/destinatário</label><input name="q" value="<?=fiscal_h($q)?>" placeholder="Fornecedor, destinatário, CPF/CNPJ, número, série, chave ou operação"></div>
    <div><label>Status</label><select name="status"><option value="">Todos</option><?php foreach(['PRE_NFE'=>'Pré-NF-e','ASSINADA'=>'Assinada','AUTORIZADA'=>'Autorizada','REJEITADA'=>'Rejeitada','ERRO_AUTORIZACAO'=>'Erro autorização','CANCELADA'=>'Cancelada'] as $k=>$v):?><option value="<?=$k?>" <?=$statusFilter===$k?'selected':''?>><?=fiscal_h($v)?></option><?php endforeach;?></select></div>
    <div><label>Emissão de</label><input type="date" name="ini" value="<?=fiscal_h($ini)?>"></div>
    <div><label>Emissão até</label><input type="date" name="fim" value="<?=fiscal_h($fim)?>"></div>
    <div class="fiscal-filter-actions"><button class="btn btn-primary" type="submit">Filtrar</button><a class="btn" href="<?=fiscal_h($saidaListUrl)?>">Limpar</a></div>
  </form>
</div>
<div class="fiscal-card">
  <div class="fiscal-list-head">
    <h2><?=$supplierReturnCrossContext?'Devoluções a fornecedor':'Lista de saídas fiscais'?></h2>
    <span class="fiscal-list-count"><?=count($recent)?> registro(s)</span>
  </div>
  <div class="table-wrap">
    <table class="fiscal-table fiscal-grid-table saida-list-grid">
      <colgroup>
        <col style="width:20%">
        <col style="width:19%">
        <col style="width:15%">
        <col style="width:9%">
        <col style="width:10%">
        <col style="width:17%">
        <col style="width:200px">
      </colgroup>
      <thead>
        <tr>
          <th>Documento</th>
          <th>Fornecedor/destinatário</th>
          <th>Operação</th>
          <th>Data</th>
          <th>Total</th>
          <th>Status</th>
          <th class="fiscal-actions-head">Ações</th>
        </tr>
      </thead>
      <tbody>
      <?php if(!$recent):?>
        <tr><td colspan="7"><div class="fiscal-empty-state"><?=$supplierReturnCrossContext?'Nenhuma devolução a fornecedor encontrada.':'Nenhuma saída fiscal encontrada.'?></div></td></tr>
      <?php endif;?>
      <?php foreach($recent as $d):
        $docId=(int)$d['id'];
        $status=strtoupper((string)($d['status']??''));
        $sefazMessage=trim((string)($d['sefaz_status_code']??'').' '.(string)($d['sefaz_status_message']??''));
      ?>
        <tr>
          <td data-label="Documento" class="primary">
            <strong class="fiscal-cell-wrap">NF-e <?=fiscal_h(($d['series']??'').'/'.($d['number']??''))?></strong>
            <div class="fiscal-muted fiscal-cell-wrap">#<?=$docId?> · <?=fiscal_h($d['access_key']??'')?></div>
          </td>
          <td data-label="Fornecedor/destinatário">
            <strong class="fiscal-cell-wrap"><?=fiscal_h($d['participant_name']??'-')?></strong>
            <div class="fiscal-muted fiscal-cell-wrap"><?=fiscal_h($d['participant_document']??'')?></div>
          </td>
          <td data-label="Operação"><span class="fiscal-cell-wrap"><?=fiscal_h($d['operation_nature']??'')?></span></td>
          <td data-label="Data"><?=fiscal_h(fiscal_date_br(substr((string)($d['issue_datetime']??''),0,10)))?></td>
          <td data-label="Total">R$ <?=number_format((float)($d['total_document']??0),2,',','.')?></td>
          <td data-label="Status">
            <span class="fiscal-badge <?=$status==='AUTORIZADA'?'':'off'?>"><?=fiscal_h($status?:'-')?></span>
            <?php if($sefazMessage!==''):?><div class="saida-status-help" title="<?=fiscal_h($sefazMessage)?>"><?=fiscal_h($sefazMessage)?></div><?php endif;?>
          </td>
          <td data-label="Ações" class="fiscal-actions-col">
            <div class="fiscal-inline-actions">
              <?php if($supplierReturnCrossContext):?>
                <span class="fiscal-badge off" title="A emissão fiscal permanece protegida no contexto F11.">Emissão bloqueada no F11</span>
              <?php else:?>
                <?php if(!in_array($status,['AUTORIZADA','CANCELADA'],true)):?>
                  <a class="btn btn-sm" href="fiscal_nfce_editar.php?doc_id=<?=$docId?>&return_to=fiscal_saidas.php">Editar e regenerar XML</a>
                  <form method="post" action="fiscal_nfce_acao.php">
                    <input type="hidden" name="action" value="authorize_now">
                    <input type="hidden" name="doc_id" value="<?=$docId?>">
                    <input type="hidden" name="return_to" value="fiscal_saidas.php">
                    <button class="btn btn-sm btn-primary">Gerar NF-e</button>
                  </form>
                <?php endif;?>
                <a class="btn btn-sm" data-no-processing href="fiscal_nfce_acao.php?action=download_xml&doc_id=<?=$docId?>">Baixar XML</a>
                <a class="btn btn-sm" target="_blank" href="fiscal_nfce_imprimir.php?doc_id=<?=$docId?>">Imprimir NF-e</a>
              <?php endif;?>
            </div>
          </td>
        </tr>
      <?php endforeach;?>
      </tbody>
    </table>
  </div>
</div>
<?php else: ?>
<div class="fiscal-card"><div class="fiscal-list-head"><div><h2><?=$supplierReturnCrossContext?'Nova devolução a fornecedor':'Nova saída fiscal'?></h2><div class="fiscal-muted"><?=$supplierReturnCrossContext?'Carregue uma compra da empresa selecionada, mantenha os itens devolvidos e confira os tributos. Nesta etapa a gravação baixa o estoque, mas não transmite NF-e.':'Carregue uma compra, mantenha somente os itens devolvidos e confira todos os tributos antes de gerar a NF-e.'?></div></div><div class="fiscal-inline-actions"><button type="button" class="btn" id="openEntries">Buscar entradas/compras</button><button type="button" class="btn" id="openSupplier">Buscar fornecedor</button></div></div>
<?php if($sourceSupplierWarning!==''):?><div class="msg" style="border-color:#fde68a;background:#fffbeb;color:#92400e"><?=fiscal_h($sourceSupplierWarning)?></div><?php endif;?>
<?php if($selectedEntries):?><div class="msg ok"><?=count($selectedEntries)?> entrada(s) selecionada(s). Os produtos e impostos foram carregados para conferência.</div><?php endif;?>
<form method="post" class="fiscal-form" id="saidaForm"><?php foreach($selectedEntryIds as $entryId):?><input type="hidden" name="source_entry_ids[]" value="<?=(int)$entryId?>"><?php endforeach;?>
<div class="fiscal-section"><h3>Operação fiscal</h3><div class="fiscal-form-grid"><div class="span-2"><label>Operação</label><select name="operation_id" required><option value="">Selecione</option><?php foreach($operations as $op):?><option value="<?=(int)$op['id']?>" <?=(string)($_POST['operation_id']??'')===(string)$op['id']?'selected':''?>><?=fiscal_h(($op['code']??'').' - '.($op['name']??''))?></option><?php endforeach;?></select></div><div><label>Chaves das entradas referenciadas</label><textarea name="reference_keys" placeholder="Uma chave por linha"><?=fiscal_h($referenceText)?></textarea></div><div class="span-3"><label>Observações</label><textarea name="notes"><?=fiscal_h((string)($_POST['notes']??''))?></textarea></div></div></div>
<div class="fiscal-section"><div class="fiscal-list-head"><h3>Fornecedor / destinatário</h3><button type="button" class="btn btn-sm" id="openSupplier2">Buscar fornecedor</button></div><div class="fiscal-form-grid"><div><label>ID pessoa</label><input name="person_person_id" id="person_id" value="<?=fiscal_h((string)$prefillPerson['person_id'])?>" readonly></div><div class="span-2"><label>Nome/Razão social</label><input name="person_name" id="person_name" value="<?=fiscal_h((string)$prefillPerson['name'])?>" required></div><div><label>CPF/CNPJ</label><input name="person_document" id="person_document" value="<?=fiscal_h((string)$prefillPerson['document'])?>" required></div><div><label>IE</label><input name="person_ie" id="person_ie" value="<?=fiscal_h((string)$prefillPerson['state_registration'])?>"></div><div><label>Telefone</label><input name="person_phone" id="person_phone" value="<?=fiscal_h((string)$prefillPerson['phone'])?>"></div><div><label>E-mail</label><input name="person_email" id="person_email" value="<?=fiscal_h((string)$prefillPerson['email'])?>"></div><div><label>CEP</label><input name="person_zip" id="person_zip" value="<?=fiscal_h((string)$prefillPerson['zip_code'])?>"></div><div class="span-2"><label>Endereço</label><input name="person_address" id="person_address" value="<?=fiscal_h((string)$prefillPerson['address'])?>"></div><div><label>Número</label><input name="person_number" id="person_number" value="<?=fiscal_h((string)$prefillPerson['number'])?>"></div><div><label>Complemento</label><input name="person_complement" id="person_complement" value="<?=fiscal_h((string)$prefillPerson['complement'])?>"></div><div><label>Bairro</label><input name="person_district" id="person_district" value="<?=fiscal_h((string)$prefillPerson['district'])?>"></div><div><label>Cidade</label><input name="person_city" id="person_city" value="<?=fiscal_h((string)$prefillPerson['city'])?>"></div><div><label>IBGE da cidade</label><input name="person_city_ibge" id="person_city_ibge" maxlength="7" value="<?=fiscal_h((string)$prefillPerson['city_ibge'])?>"></div><div><label>UF</label><input name="person_state" id="person_state" maxlength="2" value="<?=fiscal_h((string)$prefillPerson['state'])?>"></div></div></div>
<div class="fiscal-section"><div class="fiscal-list-head"><div><h3>Produtos da saída</h3><div class="fiscal-muted">Remova itens que não serão devolvidos. Use “Impostos do item” para revisar ICMS, ST/FCP, IPI, PIS e COFINS.</div></div><button type="button" class="btn btn-sm" id="addItem">+ Adicionar item</button></div><div class="table-wrap"><table class="fiscal-table saida-items-table" id="itemsTable"><thead><tr><th>Produto e origem</th><th>Qtd. entrada</th><th>Qtd. saída</th><th>Valor unit.</th><th>Total</th><th style="width:126px">CFOP</th><th style="width:105px">Ação</th></tr></thead><tbody>
<?php foreach($itemRows as $row):$details=[];if(($row['source_label']??'')!=='')$details[]=$row['source_label'];if(($row['unit']??'')!=='')$details[]='Un. '.$row['unit'];if(($row['ncm']??'')!=='')$details[]='NCM '.$row['ncm'];if(($row['cest']??'')!=='')$details[]='CEST '.$row['cest'];if(($row['original_cfop']??'')!=='')$details[]='CFOP entrada '.$row['original_cfop'];?>
<tr class="saida-item-row"><td class="primary"><input list="productOptions" class="product-pick" name="product_label[]" value="<?=fiscal_h((string)$row['product_label'])?>" placeholder="Produto, código ou código de barras"><input type="hidden" name="product_id[]" class="product-id" value="<?=(int)$row['product_id']?>"><input type="hidden" name="source_label[]" value="<?=fiscal_h((string)$row['source_label'])?>"><input type="hidden" name="source_item_id[]" value="<?=(int)($row['source_item_id']??0)?>"><input type="hidden" name="max_quantity[]" value="<?=fiscal_h((string)$row['max_quantity'])?>"><div class="saida-source-details"><?=fiscal_h($details?implode(' · ',$details):'Lançamento manual.')?></div></td><td><?=((float)($row['max_quantity']??0)>0)?number_format((float)$row['max_quantity'],3,',','.'):'—'?></td><td><input name="quantity[]" class="saida-qty" inputmode="decimal" value="<?=fiscal_h((string)$row['quantity'])?>"></td><td><input name="unit_value[]" class="unit-value" inputmode="decimal" value="<?=fiscal_h((string)$row['unit_value'])?>"></td><td><strong class="line-total">R$ 0,00</strong></td><td><input name="cfop[]" class="cfop-input" maxlength="4" inputmode="numeric" value="<?=fiscal_h((string)$row['cfop'])?>" placeholder="Operação"></td><td><button type="button" class="btn btn-sm btn-danger remove-row">Remover</button></td></tr>
<tr class="saida-tax-row"><td colspan="7"><details class="saida-tax-details"><summary>Impostos do item — clique para conferir ou alterar</summary><div class="saida-tax-grid">
<h4>ICMS próprio</h4><?php foreach(['icms_origin'=>'Origem','icms_cst'=>'CST','icms_csosn'=>'CSOSN','icms_base'=>'Base ICMS','icms_rate'=>'Alíquota ICMS %','icms_amount'=>'Valor ICMS'] as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>" value="<?=fiscal_h((string)($row[$f]??''))?>"></label><?php endforeach;?>
<h4>ICMS ST e FCP ST</h4><?php foreach(['icms_st_base'=>'Base ST','icms_st_rate'=>'Alíquota ST %','icms_st_amount'=>'Valor ICMS ST','icms_st_mva'=>'MVA %','icms_st_reduction'=>'Redução base ST %','fcp_st_base'=>'Base FCP ST','fcp_st_rate'=>'Alíquota FCP ST %','fcp_st_amount'=>'Valor FCP ST'] as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>" value="<?=fiscal_h((string)($row[$f]??''))?>"></label><?php endforeach;?>
<h4>IPI</h4><?php foreach(['ipi_cst'=>'CST IPI','ipi_cenq'=>'Enquadramento IPI','ipi_base'=>'Base IPI','ipi_rate'=>'Alíquota IPI %','ipi_amount'=>'Valor IPI'] as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>" value="<?=fiscal_h((string)($row[$f]??''))?>"></label><?php endforeach;?>
<h4>PIS</h4><?php foreach(['pis_cst'=>'CST PIS','pis_base'=>'Base PIS','pis_rate'=>'Alíquota PIS %','pis_amount'=>'Valor PIS'] as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>" value="<?=fiscal_h((string)($row[$f]??''))?>"></label><?php endforeach;?>
<h4>COFINS</h4><?php foreach(['cofins_cst'=>'CST COFINS','cofins_base'=>'Base COFINS','cofins_rate'=>'Alíquota COFINS %','cofins_amount'=>'Valor COFINS'] as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>" value="<?=fiscal_h((string)($row[$f]??''))?>"></label><?php endforeach;?>
</div></details></td></tr><?php endforeach;?>
</tbody></table></div><div class="saida-total-grid"><div class="saida-total-card"><span>Produtos</span><strong id="saidaTotal">R$ 0,00</strong></div><div class="saida-total-card"><span>IPI</span><strong id="saidaIpi">R$ 0,00</strong></div><div class="saida-total-card"><span>ICMS ST + FCP ST</span><strong id="saidaSt">R$ 0,00</strong></div><div class="saida-total-card"><span>Total estimado da NF-e</span><strong id="saidaDocumento">R$ 0,00</strong></div></div></div><div class="fiscal-form-actions"><a class="btn" href="<?=fiscal_h($saidaListUrl)?>">Cancelar</a><button class="btn btn-primary" <?=$supplierReturnCrossContext&&!$operations?'disabled':''?>><?=$supplierReturnCrossContext?'Registrar devolução e baixar estoque':'Salvar saída e gerar XML'?></button></div></form></div>

<datalist id="productOptions"><?php foreach($products as $p):$label=trim((string)($p['code']??$p['id']).' · '.(string)($p['nome']??'').' · '.(string)($p['barcode']??''));?><option value="<?=fiscal_h($label)?>" data-id="<?=(int)$p['id']?>" data-price="<?=fiscal_h((string)($p['preco']??0))?>" data-cost="<?=fiscal_h((string)($p['custo']??0))?>"></option><?php endforeach;?></datalist>
<template id="itemTemplate"><tr class="saida-item-row"><td class="primary"><input list="productOptions" class="product-pick" name="product_label[]" placeholder="Produto, código ou código de barras"><input type="hidden" name="product_id[]" class="product-id"><input type="hidden" name="source_label[]"><input type="hidden" name="source_item_id[]"><input type="hidden" name="max_quantity[]"><div class="saida-source-details">Lançamento manual.</div></td><td>—</td><td><input name="quantity[]" class="saida-qty" inputmode="decimal"></td><td><input name="unit_value[]" class="unit-value" inputmode="decimal"></td><td><strong class="line-total">R$ 0,00</strong></td><td><input name="cfop[]" class="cfop-input" maxlength="4" inputmode="numeric" placeholder="Operação"></td><td><button type="button" class="btn btn-sm btn-danger remove-row">Remover</button></td></tr><tr class="saida-tax-row"><td colspan="7"><details class="saida-tax-details"><summary>Impostos do item — clique para conferir ou alterar</summary><div class="saida-tax-grid"><?php foreach(['ICMS próprio'=>['icms_origin'=>'Origem','icms_cst'=>'CST','icms_csosn'=>'CSOSN','icms_base'=>'Base ICMS','icms_rate'=>'Alíquota ICMS %','icms_amount'=>'Valor ICMS'],'ICMS ST e FCP ST'=>['icms_st_base'=>'Base ST','icms_st_rate'=>'Alíquota ST %','icms_st_amount'=>'Valor ICMS ST','icms_st_mva'=>'MVA %','icms_st_reduction'=>'Redução base ST %','fcp_st_base'=>'Base FCP ST','fcp_st_rate'=>'Alíquota FCP ST %','fcp_st_amount'=>'Valor FCP ST'],'IPI'=>['ipi_cst'=>'CST IPI','ipi_cenq'=>'Enquadramento IPI','ipi_base'=>'Base IPI','ipi_rate'=>'Alíquota IPI %','ipi_amount'=>'Valor IPI'],'PIS'=>['pis_cst'=>'CST PIS','pis_base'=>'Base PIS','pis_rate'=>'Alíquota PIS %','pis_amount'=>'Valor PIS'],'COFINS'=>['cofins_cst'=>'CST COFINS','cofins_base'=>'Base COFINS','cofins_rate'=>'Alíquota COFINS %','cofins_amount'=>'Valor COFINS']] as $group=>$fields):?><h4><?=$group?></h4><?php foreach($fields as $f=>$label):?><label><?=$label?><input name="<?=$f?>[]" class="tax-input <?=str_ends_with($f,'_amount')?'tax-amount':''?>"></label><?php endforeach;?><?php endforeach;?></div></details></td></tr></template>
<div class="fiscal-modal" id="entriesModal" role="dialog" aria-modal="true" style="display:none"><div class="fiscal-modal-dialog fiscal-modal-wide"><div class="fiscal-modal-head"><div><h2>Buscar entradas/compras</h2><p class="fiscal-muted">Selecione uma ou mais NF-e de entrada.</p></div><button type="button" class="fiscal-modal-close" data-close="entriesModal">×</button></div><form method="get" class="fiscal-form"><input type="hidden" name="novo" value="1"><label>Pesquisar entrada<input type="search" id="entrySearch" placeholder="Número, chave, fornecedor ou CNPJ"></label><div class="table-wrap saida-modal-results"><table class="fiscal-table"><thead><tr><th></th><th>NF-e</th><th>Fornecedor</th><th>Data</th><th>Total</th><th>Status</th></tr></thead><tbody><?php foreach($entryCandidates as $entry):$search=strtolower(trim(($entry['number']??'').' '.($entry['access_key']??'').' '.($entry['supplier_name']??'').' '.($entry['supplier_doc']??'')));?><tr data-entry-search="<?=fiscal_h($search)?>"><td><input type="checkbox" name="source_entry_ids[]" value="<?=(int)$entry['id']?>" <?=in_array((int)$entry['id'],$selectedEntryIds,true)?'checked':''?>></td><td><strong><?=fiscal_h(($entry['series']??'').'/'.($entry['number']??''))?></strong><div class="fiscal-muted"><?=fiscal_h($entry['access_key']??'')?></div></td><td><strong><?=fiscal_h($entry['supplier_name']??'')?></strong><div class="fiscal-muted"><?=fiscal_h($entry['supplier_doc']??'')?></div></td><td><?=fiscal_h(fiscal_date_br((string)(($entry['entry_date']??'')?:($entry['issue_date']??''))))?></td><td>R$ <?=number_format((float)($entry['total_invoice']??0),2,',','.')?></td><td><?=fiscal_h($entry['status']??'')?></td></tr><?php endforeach;?></tbody></table></div><div class="fiscal-form-actions"><button type="button" class="btn" data-close="entriesModal">Cancelar</button><button class="btn btn-primary">Carregar entradas selecionadas</button></div></form></div></div>
<div class="fiscal-modal" id="supplierModal" role="dialog" aria-modal="true" style="display:none"><div class="fiscal-modal-dialog fiscal-modal-wide"><div class="fiscal-modal-head"><div><h2>Buscar fornecedor</h2><p class="fiscal-muted">Pesquise por nome, CPF/CNPJ, telefone ou cidade.</p></div><button type="button" class="fiscal-modal-close" data-close="supplierModal">×</button></div><label class="fiscal-form">Pesquisar fornecedor<input type="search" id="supplierSearch"></label><div class="saida-modal-results" id="supplierResults"></div></div></div>
<script>
(function(){
const products=Array.from(document.querySelectorAll('#productOptions option'));const suppliers=<?=json_encode(array_map(static fn($s)=>['id'=>(int)$s['id'],'name'=>(string)($s['name']??''),'document'=>(string)($s['document']??''),'ie'=>(string)($s['state_registration']??''),'phone'=>(string)(($s['whatsapp']??'')?:($s['phone']??'')),'email'=>(string)($s['email']??''),'zip'=>(string)($s['zip_code']??''),'address'=>(string)($s['address']??''),'number'=>(string)($s['number']??''),'complement'=>(string)($s['complement']??''),'district'=>(string)($s['district']??''),'city'=>(string)($s['city']??''),'city_ibge'=>(string)($s['city_ibge']??''),'state'=>(string)($s['state']??'')],$suppliers),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT)?:'[]'?>;
const parse=v=>{v=String(v||'').trim();if(v.includes(','))v=v.replace(/\./g,'').replace(',','.');const n=Number(v.replace(/[^0-9.-]/g,''));return Number.isFinite(n)?n:0};const money=v=>'R$ '+Number(v||0).toFixed(2).replace('.',',');const norm=v=>String(v||'').toLowerCase().normalize('NFD').replace(/[\u0300-\u036f]/g,'');const esc=v=>String(v??'').replace(/[&<>"']/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[m]));
function pair(row){return row?.nextElementSibling?.classList.contains('saida-tax-row')?row.nextElementSibling:null}function calcTax(taxRow,prefix){const base=parse(taxRow.querySelector(`[name="${prefix}_base[]"]`)?.value);const rate=parse(taxRow.querySelector(`[name="${prefix}_rate[]"]`)?.value);const amount=taxRow.querySelector(`[name="${prefix}_amount[]"]`);if(amount&&document.activeElement!==amount&&(!amount.value||amount.dataset.auto==='1')){amount.value=(base*rate/100).toFixed(2).replace('.',',');amount.dataset.auto='1'}}
function update(){let productsTotal=0,ipi=0,st=0;document.querySelectorAll('.saida-item-row').forEach(row=>{const line=parse(row.querySelector('.saida-qty')?.value)*parse(row.querySelector('.unit-value')?.value);productsTotal+=line;row.querySelector('.line-total').textContent=money(line);const tax=pair(row);if(tax){ipi+=parse(tax.querySelector('[name="ipi_amount[]"]')?.value);st+=parse(tax.querySelector('[name="icms_st_amount[]"]')?.value)+parse(tax.querySelector('[name="fcp_st_amount[]"]')?.value)}});document.getElementById('saidaTotal').textContent=money(productsTotal);document.getElementById('saidaIpi').textContent=money(ipi);document.getElementById('saidaSt').textContent=money(st);document.getElementById('saidaDocumento').textContent=money(productsTotal+ipi+st)}
function bindRow(row){if(!row||row.dataset.bound)return;row.dataset.bound='1';const tax=pair(row);row.querySelector('.product-pick')?.addEventListener('change',function(){const op=products.find(o=>o.value===this.value);if(!op)return;row.querySelector('.product-id').value=op.dataset.id||'';const unit=row.querySelector('.unit-value');if(unit&&!unit.value)unit.value=String(op.dataset.price||op.dataset.cost||'0').replace('.',',');update()});row.querySelectorAll('.saida-qty,.unit-value').forEach(el=>el.addEventListener('input',update));row.querySelector('.remove-row')?.addEventListener('click',()=>{tax?.remove();row.remove();if(!document.querySelector('.saida-item-row'))addItem();update()});tax?.querySelectorAll('.tax-input').forEach(el=>{el.addEventListener('input',()=>{if(el.classList.contains('tax-amount'))el.dataset.auto='0';['icms','icms_st','fcp_st','ipi','pis','cofins'].forEach(p=>calcTax(tax,p));update()})})}
function addItem(){const t=document.getElementById('itemTemplate');const frag=t.content.cloneNode(true);const row=frag.querySelector('.saida-item-row');document.querySelector('#itemsTable tbody').appendChild(frag);bindRow(row);update()}document.querySelectorAll('.saida-item-row').forEach(bindRow);document.getElementById('addItem')?.addEventListener('click',addItem);update();
function open(id){document.getElementById(id).style.display='flex'}function close(id){document.getElementById(id).style.display='none'}document.getElementById('openEntries')?.addEventListener('click',()=>open('entriesModal'));document.getElementById('openSupplier')?.addEventListener('click',()=>open('supplierModal'));document.getElementById('openSupplier2')?.addEventListener('click',()=>open('supplierModal'));document.querySelectorAll('[data-close]').forEach(b=>b.addEventListener('click',()=>close(b.dataset.close)));document.getElementById('entrySearch')?.addEventListener('input',function(){const q=norm(this.value);document.querySelectorAll('[data-entry-search]').forEach(tr=>tr.style.display=!q||norm(tr.dataset.entrySearch).includes(q)?'':'none')});
const results=document.getElementById('supplierResults');function fill(id,v){const el=document.getElementById(id);if(el)el.value=v||''}function pick(s){fill('person_id',s.id);fill('person_name',s.name);fill('person_document',s.document);fill('person_ie',s.ie);fill('person_phone',s.phone);fill('person_email',s.email);fill('person_zip',s.zip);fill('person_address',s.address);fill('person_number',s.number);fill('person_complement',s.complement);fill('person_district',s.district);fill('person_city',s.city);fill('person_city_ibge',s.city_ibge);fill('person_state',s.state);close('supplierModal')}function render(q=''){q=norm(q);const list=suppliers.map((s,i)=>({s,i})).filter(x=>!q||norm([x.s.name,x.s.document,x.s.phone,x.s.city].join(' ')).includes(q)).slice(0,100);results.innerHTML=list.length?list.map(x=>`<div class="saida-supplier-card"><div><strong>${esc(x.s.name)}</strong><div class="fiscal-muted">${esc((window.nexaMaskDoc||String)(x.s.document||''))} · ${esc(x.s.phone)} · ${esc(x.s.city)}/${esc(x.s.state)}</div></div><button type="button" class="btn btn-sm" data-pick="${x.i}">Selecionar</button></div>`).join(''):'<div class="fiscal-empty-state">Nenhum fornecedor encontrado.</div>';results.querySelectorAll('[data-pick]').forEach(b=>b.addEventListener('click',()=>pick(suppliers[Number(b.dataset.pick)])))}document.getElementById('supplierSearch')?.addEventListener('input',function(){render(this.value)});render();
})();
</script>
<?php endif;?>
<?php fiscal_admin_end(); ?>
