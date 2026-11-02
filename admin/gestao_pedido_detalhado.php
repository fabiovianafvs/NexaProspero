<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_gestao.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/nfse_quick_emit.php';
require_once __DIR__ . '/../config/detailed_order_module.php';
require_once __DIR__ . '/../config/product_composition.php';
require_once __DIR__ . '/../config/orcamento_pedido_bridge.php';
require_once __DIR__ . '/../config/pix_sales_flow.php';

$companyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_detailed_order')
  : (int)COMPANY_ID;
$detailedCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_detailed_order');
if ($detailedCrossContext) {
  $GLOBALS['NEXA_MULTI_COMPANY_NO_RUNTIME_DDL'] = true;
  if (function_exists('multi_company_context_permission_allowed')
      && !multi_company_context_permission_allowed($pdo, 'gestao_pedido_detalhado')) {
    http_response_code(403);
    admin_topbar('Pedido Detalhado', 'gestao');
    echo '<div class="msg err" style="margin:24px">Seu perfil não possui permissão para operar o Pedido Detalhado na empresa selecionada pelo F11.</div>';
    admin_footer();
    exit;
  }
}
$detailedContextUrl = static function(string $url) use ($detailedCrossContext): string {
  return $detailedCrossContext && function_exists('multi_company_context_url') ? multi_company_context_url($url) : $url;
};
$detailedMcCsrf = $detailedCrossContext && function_exists('multi_company_context_csrf_token') ? multi_company_context_csrf_token() : '';
$detailedMcCtx = $detailedCrossContext && is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? (string)($GLOBALS['MULTI_COMPANY_CONTEXT']['token'] ?? '') : '';
$orcamentoReadEnabled = function_exists('orc_bridge_ready') && orc_bridge_ready($pdo, $companyId);
$orcamentoAjaxUrl = $orcamentoReadEnabled ? $detailedContextUrl('orcamento_pedido_ajax.php?scope=sales_detailed_order') : '';
if (!$detailedCrossContext && function_exists('product_composition_enabled') && product_composition_enabled($pdo, $companyId)) product_composition_ensure_schema($pdo);
$userId = (int)(auth_user_id() ?? 0);
$pixSalesReady = pix_sales_flow_ready($pdo, !$detailedCrossContext);
pix_sales_flow_handle_ajax($pdo, $companyId, $userId, $detailedCrossContext, $pixSalesReady);
$detailedProductCostHidden = $detailedCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'desativa_preco_custo'))
  : (function_exists('access_product_cost_hidden') ? access_product_cost_hidden($pdo, $companyId) : false);
$detailedSaleFinalizationBlocked = $detailedCrossContext
  ? (function_exists('multi_company_context_permission_allowed') && multi_company_context_permission_allowed($pdo, 'nao_finalizar_venda'))
  : (function_exists('access_sale_finalization_blocked') ? access_sale_finalization_blocked($pdo, $companyId) : false);
$erro = '';
try { detailed_order_ensure_schema($pdo); } catch (Throwable $e) { $erro = $e->getMessage(); }

// V11.53: conferência de pedidos já faturados pela O.S. na própria tela de Pedido Detalhado.
// Não chama gestao_finalize_pdv_order() nem cria outro pedido ou lançamentos.
if (max(0,(int)($_GET['os_nfse'] ?? $_POST['os_nfse'] ?? 0)) > 0
    || max(0,(int)($_GET['os_nfse_lote'] ?? $_POST['os_nfse_lote'] ?? 0)) > 0) {
  require __DIR__ . '/_os_nfse_pedido_detalhado.php';
  exit;
}

function pdh_json(array $data, int $code = 200): never {
  http_response_code($code); header('Content-Type: application/json; charset=utf-8');
  echo json_encode($data, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); exit;
}
function pdh_product_row(PDO $pdo, int $cid, int $id): array {
  $cols = ['id','nome','preco','categoria'];
  foreach (['numero_cadastro','codigo_firebird','codigo_barras','referencia','unidade_estoque','estoque_atual','is_service'] as $c) if (function_exists('fiscal_column_exists') && fiscal_column_exists($pdo,'produtos',$c)) $cols[]=$c;
  $st=$pdo->prepare('SELECT `'.implode('`,`',$cols).'` FROM produtos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$cid,$id]);$p=$st->fetch(PDO::FETCH_ASSOC)?:[];
  if (!$p) return [];
  if (function_exists('product_composition_enabled') && product_composition_enabled($pdo,$cid) && product_composition_schema_ready($pdo) && product_composition_product_is_composed($pdo,$cid,$id)) {
    $stats=product_composition_stats($pdo,$cid,$id);
    $p['estoque_atual']=(float)($stats['calculated']??0);
  }
  $p['tax']=fiscal_product_tax($pdo,$cid,$id);
  return $p;
}

if (isset($_GET['ajax'])) {
  try {
    $ajax=(string)$_GET['ajax'];
    if (in_array($ajax,['customers','carriers','sellers','professionals'],true)) {
      $q=trim((string)($_GET['q']??'')); $like=nexa_search_like($q);
      $role=match($ajax){'carriers'=>'is_carrier','sellers'=>'is_seller','professionals'=>'is_professional',default=>'is_customer'};
      $sql="SELECT id,name,fantasy_name,document,state_registration,email,phone,whatsapp,zip_code,address,number,complement,district,city,city_ibge,state FROM finance_people WHERE company_id=? AND active=1 AND {$role}=1";
      $pa=[$companyId];
      if ($q!=='') {$sql.=' AND (name LIKE ? OR fantasy_name LIKE ? OR document LIKE ? OR phone LIKE ? OR whatsapp LIKE ?)'; array_push($pa,$like,$like,$like,$like,$like);}
      $sql.=' ORDER BY name ASC LIMIT 40'; $st=$pdo->prepare($sql);$st->execute($pa);pdh_json(['ok'=>true,'rows'=>$st->fetchAll(PDO::FETCH_ASSOC)?:[]]);
    }
    if ($ajax==='cfops') {
      $q=trim((string)($_GET['q']??'')); $like=nexa_search_like($q);
      $sql="SELECT id,company_id,code,description,operation_type,direction FROM fiscal_cfops WHERE company_id IN (?,0) AND COALESCE(is_active,1)=1 AND direction='SAIDA'";
      $pa=[$companyId];
      if ($q!=='') {$sql.=' AND (code LIKE ? OR description LIKE ? OR operation_type LIKE ?)'; array_push($pa,$like,$like,$like);}
      $sql.=' ORDER BY company_id DESC, code ASC LIMIT 120';
      $st=$pdo->prepare($sql);$st->execute($pa);$rows=[];$seen=[];
      foreach(($st->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r){$code=(string)($r['code']??'');if($code===''||isset($seen[$code]))continue;$seen[$code]=1;$rows[]=$r;if(count($rows)>=60)break;}
      pdh_json(['ok'=>true,'rows'=>$rows]);
    }
    if ($ajax==='products') {
      $q=trim((string)($_GET['q']??''));
      $type=strtoupper(trim((string)($_GET['type']??'')));
      $hasCol=static function(string $col) use ($pdo): bool { return function_exists('fiscal_column_exists') && fiscal_column_exists($pdo,'produtos',$col); };
      $hasNumber=$hasCol('numero_cadastro');
      $hasLegacyCode=$hasCol('codigo_firebird');
      $hasMap=function_exists('fiscal_table_exists') && fiscal_table_exists($pdo,'produtos_firebird_map')
        && function_exists('fiscal_column_exists') && fiscal_column_exists($pdo,'produtos_firebird_map','codigo_firebird');
      $hasImage=$hasCol('imagem');
      $hasCurrentStock=$hasCol('estoque_atual');
      $hasReservedStock=$hasCol('estoque_reservado');
      $hasService=$hasCol('is_service');
      $joinMap=$hasMap?' LEFT JOIN produtos_firebird_map pfm ON pfm.company_id=p.company_id AND pfm.produto_id=p.id':'';
      $mapCodeExpr=$hasMap?"COALESCE(NULLIF(TRIM(pfm.codigo_firebird),''),'')":"''";
      $legacyColExpr=$hasLegacyCode?"COALESCE(NULLIF(TRIM(p.codigo_firebird),''),'')":"''";
      $legacyExpr=$hasLegacyCode&&$hasMap?"COALESCE(NULLIF(TRIM(p.codigo_firebird),''),NULLIF(TRIM(pfm.codigo_firebird),''),'')":($hasLegacyCode?$legacyColExpr:$mapCodeExpr);
      $numberExpr=$hasNumber?"COALESCE(CAST(p.numero_cadastro AS CHAR),'')":"''";
      $codeExpr=$hasNumber?"COALESCE(NULLIF(CAST(p.numero_cadastro AS CHAR),''),NULLIF({$legacyExpr},''),CAST(p.id AS CHAR))":"COALESCE(NULLIF({$legacyExpr},''),CAST(p.id AS CHAR))";
      $barcodeCols=[]; foreach(['codigo_barras','ean','gtin','barcode','gtin_tributavel'] as $bc){ if($hasCol($bc))$barcodeCols[]=$bc; }
      $barParts=[]; foreach($barcodeCols as $bc)$barParts[]="NULLIF(TRIM(p.`".str_replace('`','``',$bc)."`),'')";
      $barExpr=$barParts?('COALESCE('.implode(',',$barParts).",'')"):"''";
      $refExpr=$hasCol('referencia')?"COALESCE(p.referencia,'')":"''";
      $unitExpr=$hasCol('unidade_estoque')?"COALESCE(NULLIF(p.unidade_estoque,''),'UN')":"'UN'";
      $costExpr=(!$detailedProductCostHidden && $hasCol('custo')) ? 'COALESCE(p.custo,0)' : 'NULL';
      $descriptionExpr=$hasCol('descricao')?"COALESCE(p.descricao,'')":"''";
      $categoryExpr=$hasCol('categoria')?"COALESCE(p.categoria,'')":"''";
      $menuExpr=$hasCol('codigo_cardapio')?"COALESCE(p.codigo_cardapio,'')":"''";
      $imageExpr=$hasImage?"COALESCE(p.imagem,'')":"''";
      $currentStockExpr=$hasCurrentStock?'COALESCE(p.estoque_atual,0)':'0';
      $reservedStockExpr=$hasReservedStock?'COALESCE(p.estoque_reservado,0)':'0';
      $serviceExpr=$hasService?'COALESCE(p.is_service,0)':'0';
      $where=['p.company_id=?','COALESCE(p.ativo,1)=1']; $params=[$companyId];
      if($hasService){
        if($type==='PRODUTO')$where[]='COALESCE(p.is_service,0)=0';
        elseif($type==='SERVICO')$where[]='COALESCE(p.is_service,0)=1';
      } elseif($type==='SERVICO') {
        pdh_json(['ok'=>true,'rows'=>[]]);
      }
      $tokens=array_slice(preg_split('/\\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[],0,6);
      foreach($tokens as $token){
        $like=nexa_search_like((string)$token); $parts=['p.nome LIKE ?','CAST(p.id AS CHAR) LIKE ?']; array_push($params,$like,$like);
        if($hasCol('descricao')){$parts[]='p.descricao LIKE ?';$params[]=$like;}
        if($hasCol('categoria')){$parts[]='p.categoria LIKE ?';$params[]=$like;}
        if($hasNumber){$parts[]='CAST(p.numero_cadastro AS CHAR) LIKE ?';$params[]=$like;}
        if($hasLegacyCode){$parts[]='p.codigo_firebird LIKE ?';$params[]=$like;}
        if($hasMap){$parts[]='pfm.codigo_firebird LIKE ?';$params[]=$like;}
        if($hasCol('referencia')){$parts[]='p.referencia LIKE ?';$params[]=$like;}
        if($hasCol('codigo_cardapio')){$parts[]='p.codigo_cardapio LIKE ?';$params[]=$like;}
        foreach($barcodeCols as $bc){$parts[]='p.`'.str_replace('`','``',$bc).'` LIKE ?';$params[]=$like;}
        $where[]='('.implode(' OR ',$parts).')';
      }
      $sql="SELECT p.id,p.nome,p.preco,{$categoryExpr} AS categoria,{$descriptionExpr} AS descricao,{$codeExpr} AS codigo,{$numberExpr} AS numero_cadastro,{$legacyExpr} AS codigo_origem,{$barExpr} AS codigo_barras,{$refExpr} AS referencia,{$menuExpr} AS codigo_cardapio,{$unitExpr} AS unidade,{$unitExpr} AS unidade_estoque,{$costExpr} AS custo,{$imageExpr} AS imagem,{$currentStockExpr} AS estoque_atual,{$reservedStockExpr} AS estoque_reservado,{$serviceExpr} AS is_service FROM produtos p{$joinMap} WHERE ".implode(' AND ',$where).' ORDER BY '.($hasService?'COALESCE(p.is_service,0),':'').' p.nome ASC,p.id ASC LIMIT 120';
      $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
      foreach($rows as &$row){
        $img=trim((string)($row['imagem']??''));
        $row['image_url']=$img!==''?('/uploads/products/'.rawurlencode($img)):'/assets/no-image.svg';
        $row['available_qty']=max(0,(float)($row['estoque_atual']??0)-(float)($row['estoque_reservado']??0));
      } unset($row);
      pdh_json(['ok'=>true,'rows'=>$rows]);
    }
    if ($ajax==='product') {
      $id=(int)($_GET['id']??0);$p=pdh_product_row($pdo,$companyId,$id); if(!$p)pdh_json(['ok'=>false,'message'=>'Produto não encontrado.'],404); pdh_json(['ok'=>true,'row'=>$p]);
    }
    pdh_json(['ok'=>false,'message'=>'Ação inválida.'],400);
  } catch (Throwable $e) { pdh_json(['ok'=>false,'message'=>$e->getMessage()],500); }
}

if ($_SERVER['REQUEST_METHOD']==='POST') {
  try {
    if ($detailedCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf'] ?? '')))) {
      throw new RuntimeException('Contexto Multiempresa expirou. Atualize o Pedido Detalhado e tente novamente.');
    }
    if ($detailedSaleFinalizationBlocked) throw new RuntimeException('Este usuário não possui permissão para finalizar vendas.');
    $requestedCheckoutOption = strtolower(trim((string)($_POST['emit_doc_after'] ?? 'none')));
    if ($detailedCrossContext) {
      if (!in_array($requestedCheckoutOption, ['', 'none'], true)) {
        throw new RuntimeException('V11.30 Multiempresa: emissão fiscal pelo F11 continua bloqueada. Grave somente o Pedido Detalhado.');
      }
      $requestedCheckoutOption = 'none';
      $_POST['emit_doc_after'] = 'none';
      $fiscalCfgPost = ['order_auto_print_enabled'=>1, 'order_print_disabled'=>0];
    } else {
      $fiscalCfgPost = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : [];
      if (!function_exists('fiscal_checkout_option_enabled') || !fiscal_checkout_option_enabled($fiscalCfgPost, $requestedCheckoutOption)) {
        throw new RuntimeException('A opção de finalização selecionada não está habilitada nas Configurações Fiscais.');
      }
    }
    $_POST['source']='pedido_detalhado';
    $_POST['order_type']='balcao';
    $_POST['delivery_fee']=$_POST['freight_amount']??0;
    $professionalId=max(0,(int)($_POST['professional_id']??0));
    if($professionalId>0){
      if(!function_exists('fiscal_column_exists')||!fiscal_column_exists($pdo,'pedidos','professional_id')) throw new RuntimeException('Aplique a migration V11.45.14 antes de informar o Profissional no Pedido Detalhado.');
      $stProfessional=$pdo->prepare('SELECT id FROM finance_people WHERE company_id=? AND id=? AND active=1 AND is_professional=1 LIMIT 1');
      $stProfessional->execute([$companyId,$professionalId]);
      if(!(int)$stProfessional->fetchColumn()) throw new RuntimeException('Profissional selecionado não pertence à empresa ou não está marcado como Profissional.');
    }
    $verifiedPixCharges=pix_sales_flow_validate_before_finalize($pdo,$companyId,$_POST,$pixSalesReady);$deliveryControlPrep=['active'=>false,'requested_doc'=>'none'];if(function_exists('delivery_control_prepare_order_post'))$deliveryControlPrep=delivery_control_prepare_order_post($pdo,$companyId,$_POST);if(!empty($deliveryControlPrep['active']))$requestedCheckoutOption='none';
    // Pré-valida o comprimento de xDescServ antes de gravar o pedido.
    // Garante que as observações não sejam cortadas silenciosamente pela NFS-e.
    if ($requestedCheckoutOption === 'nfse') {
      require_once __DIR__ . '/../config/detailed_order_fiscal_notes.php';
      $noteHeader = detailed_order_fiscal_observation(['observacoes'=>$_POST['notes'] ?? ''], false);
      $itemNotesPosted = (array)($_POST['item_obs'] ?? []);
      $hasNotes = $noteHeader !== '';
      foreach ($itemNotesPosted as $itemPostedNote) {
        if (detailed_order_fiscal_normalize_note($itemPostedNote) !== '') { $hasNotes = true; break; }
      }
      if ($hasNotes) {
        $idsForDesc = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['item_product_id'] ?? [])))));
        $productDescMap = [];
        if ($idsForDesc) {
          $phDesc = implode(',', array_fill(0, count($idsForDesc), '?'));
          $stDesc = $pdo->prepare("SELECT id,nome,is_service FROM produtos WHERE company_id=? AND id IN ($phDesc)");
          $stDesc->execute(array_merge([$companyId], $idsForDesc));
          foreach (($stDesc->fetchAll(PDO::FETCH_ASSOC) ?: []) as $productDesc) $productDescMap[(int)$productDesc['id']] = $productDesc;
        }
        $linesDesc = [];
        foreach ((array)($_POST['item_product_id'] ?? []) as $ixDesc => $idDesc) {
          $productDesc = $productDescMap[(int)$idDesc] ?? null;
          if (!$productDesc || (int)($productDesc['is_service'] ?? 0) !== 1) continue;
          $qtyDesc = function_exists('gestao_quantity_in') ? gestao_quantity_in($_POST['item_qty'][$ixDesc] ?? 0, 3) : (float)($_POST['item_qty'][$ixDesc] ?? 0);
          if ($qtyDesc <= 0) continue;
          $serviceName = nfse_emitter_text($productDesc['nome'] ?? '', 255);
          $qtyLabel = rtrim(rtrim(number_format($qtyDesc, 4, '.', ''), '0'), '.');
          $serviceLine = $qtyLabel . ' x ' . $serviceName;
          $itemDesc = detailed_order_fiscal_normalize_note($itemNotesPosted[$ixDesc] ?? '');
          if ($itemDesc !== '') $serviceLine .= ' — Obs. do item: ' . $itemDesc;
          $linesDesc[] = $serviceLine;
        }
        $allDescription = implode(' | ', $linesDesc) . ($noteHeader !== '' ? ' | Observação do pedido: ' . $noteHeader : '');
        $descLength = function_exists('mb_strlen') ? mb_strlen($allDescription, 'UTF-8') : strlen($allDescription);
        if ($descLength > 1000) throw new RuntimeException('Observações muito longas para a NFS-e: o texto dos serviços e observações tem ' . $descLength . ' caracteres (limite: 1000). Reduza as observações antes de finalizar.');
      }
    }
    $orderId=gestao_finalize_pdv_order($pdo,$companyId,$userId,$_POST);if($verifiedPixCharges){try{pix_sales_flow_link_order($pdo,$companyId,$orderId,$verifiedPixCharges);}catch(Throwable $pixLinkError){error_log('[Nexa V11.52.6] Pedido detalhado #'.$orderId.' salvo, mas não foi possível vincular a cobrança Pix: '.$pixLinkError->getMessage());}}
    if(function_exists('fiscal_column_exists')&&fiscal_column_exists($pdo,'pedidos','professional_id')){
      $pdo->prepare('UPDATE pedidos SET professional_id=? WHERE company_id=? AND id=?')->execute([$professionalId?:null,$companyId,$orderId]);
    }
    $orcamentoOrigemId=(int)($_POST['orcamento_id']??0);
    if($orcamentoOrigemId>0 && function_exists('orc_bridge_release_reservations_after_order')){
      try{orc_bridge_release_reservations_after_order($pdo,$companyId,$orcamentoOrigemId,$userId,$orderId,(string)($deliveryControlPrep['requested_doc']??'none'));}
      catch(Throwable $orcBridgeError){error_log('[Nexa V11.36.4] Pedido detalhado #'.$orderId.' salvo, mas não foi possível liberar a reserva do orçamento #'.$orcamentoOrigemId.': '.$orcBridgeError->getMessage());}
    }
    $orderRow = finance_query_one($pdo, 'SELECT * FROM pedidos WHERE company_id = ? AND id = ? LIMIT 1', [$companyId, $orderId]);
    if (!$orderRow) throw new RuntimeException('Falha de isolamento: o pedido gravado não pertence à empresa operacional selecionada.');
    detailed_order_save_snapshot($pdo,$companyId,$orderId,$_POST);

    $orderNumber = function_exists('company_sequence_display') ? company_sequence_display($orderRow, 'pedidos') : $orderId;
    $returnBase = $detailedContextUrl('gestao_pedido_detalhado.php');
    $autoPrint = (!array_key_exists('order_auto_print_enabled', $fiscalCfgPost) || !empty($fiscalCfgPost['order_auto_print_enabled'])) ? '1' : '0';
    $printDisabled = !empty($fiscalCfgPost['order_print_disabled']);
    $pedidoDoneUrl = $detailedContextUrl('gestao_pedido_detalhado.php?ok=' . rawurlencode('Pedido detalhado #' . $orderNumber . ' salvo com sucesso.'));
    $pedidoPrintUrl = $detailedContextUrl('pedido_print.php?id=' . $orderId . '&return_to=' . rawurlencode($returnBase) . '&autoprint=' . $autoPrint);
    $finalUrl = $printDisabled ? $pedidoDoneUrl : $pedidoPrintUrl;

    if (!$detailedCrossContext && $requestedCheckoutOption === 'nfse') {
      try {
        $nfseResult = nfse_quick_authorize_order($pdo, $companyId, $orderId);
        $nfseDocId = nfse_quick_document_id($pdo, $companyId, $orderId, $nfseResult);
        if ($nfseDocId <= 0) throw new RuntimeException('A NFS-e foi processada, mas o documento fiscal não pôde ser localizado.');
        $nfseNumber = trim((string)($nfseResult['nfse_number'] ?? $nfseResult['document']['nfse_number'] ?? ''));
        $nfseMessage = 'Pedido detalhado #' . $orderNumber . ' salvo e NFS-e autorizada' . ($nfseNumber !== '' ? ' nº ' . $nfseNumber : '') . '.';
        header('Location: fiscal_nfse_nacional.php?pedido_id=' . $orderId . '&source=detailed_order&ok=' . rawurlencode($nfseMessage));
        exit;
      } catch (Throwable $fiscalError) {
        header('Location: ' . $pedidoPrintUrl . '&err=' . rawurlencode('Pedido salvo, mas a emissão da NFS-e retornou: ' . $fiscalError->getMessage()));
        exit;
      }
    }
    if (!$detailedCrossContext && in_array($requestedCheckoutOption, ['nfce','nfe'], true)) {
      try {
        $result = $requestedCheckoutOption === 'nfe'
          ? fiscal_nfe_authorize_order($pdo, $companyId, $orderId)
          : fiscal_nfce_authorize_order($pdo, $companyId, $orderId);
        $docId = (int)($result['doc_id'] ?? 0);
        if ($docId <= 0) {
          $doc = $requestedCheckoutOption === 'nfe'
            ? fiscal_document_order_document($pdo, $companyId, $orderId, '55')
            : fiscal_document_order_document($pdo, $companyId, $orderId, '65');
          $docId = (int)($doc['id'] ?? 0);
        }
        if ($docId > 0) {
          $returnTo = $printDisabled ? $returnBase : ('pedido_print.php?id=' . $orderId . '&return_to=' . rawurlencode($returnBase) . '&autoprint=0');
          header('Location: fiscal_nfce_imprimir.php?doc_id=' . $docId . '&source=checkout&return_to=' . rawurlencode($returnTo));
          exit;
        }
      } catch (Throwable $fiscalError) {
        header('Location: ' . $pedidoPrintUrl . '&err=' . rawurlencode($fiscalError->getMessage()));
        exit;
      }
    }
    header('Location: ' . $finalUrl);
    exit;
  } catch (Throwable $e) { $erro=$e->getMessage(); }
}

$sellers=finance_people_options($pdo,$companyId,'seller');
$rules=commercial_user_rules($pdo,$companyId,$userId);$defaultSeller=(int)($rules['default_seller_id']??0);$allowSeller=!empty($rules['allow_seller_change']);
$defaultSellerName='';foreach($sellers as $sellerOpt){if((int)($sellerOpt['id']??0)===$defaultSeller){$defaultSellerName=(string)($sellerOpt['name']??'');break;}}
$payments=gestao_payment_methods($pdo,$companyId,true);
$defaultCfop='';try{$op=gestao_pdv_sale_operation($pdo,$companyId);$defaultCfop=preg_replace('/\D+/','',(string)($op['cfop_out_state']??''));}catch(Throwable $e){}
$fiscalCfgDetailed = $detailedCrossContext ? [] : (function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $companyId) : []);
$detailedCheckoutOptions = $detailedCrossContext
  ? ['nfce'=>false,'nfe'=>false,'nfse'=>false,'none'=>true]
  : (function_exists('fiscal_checkout_options') ? fiscal_checkout_options($fiscalCfgDetailed) : ['nfce'=>false,'nfe'=>true,'nfse'=>false,'none'=>true]);
$detailedCanNfce = !$detailedCrossContext && !empty($detailedCheckoutOptions['nfce']);
$detailedCanNfe = !$detailedCrossContext && !empty($detailedCheckoutOptions['nfe']);
$detailedCanNfse = !$detailedCrossContext && !empty($detailedCheckoutOptions['nfse']);
$detailedCanSave = !empty($detailedCheckoutOptions['none']);
$detailedFiscalEnabled = !$detailedCrossContext && ($detailedCanNfce || $detailedCanNfe || $detailedCanNfse || $detailedCanSave);

$paymentJson=[];foreach($payments as $i=>$p){$paymentJson[]=['id'=>(int)$p['id'],'key'=>(string)($i+1),'name'=>(string)$p['name'],'type'=>(string)($p['type']??$p['code']??''),'receivable'=>(!empty($p['card_control_enabled']) ? 0 : (int)($p['generate_receivable']??0)),'allowInstallments'=>(int)($p['allow_installments']??0),'defaultInstallments'=>(int)($p['default_installments']??1),'firstDueDays'=>(int)($p['first_due_days']??30),'keepDueDay'=>(int)($p['keep_due_day']??1),'creRulesEnabled'=>(int)($p['cre_rules_enabled']??0),'periodDays'=>(int)($p['cre_installment_period_days']??30),'fixedDueDay'=>(int)($p['cre_fixed_due_day']??0)];}

gestao_admin_start('Pedido Detalhado','gestao_pedido_detalhado');
gestao_page_header('Pedido Detalhado',$detailedCrossContext ? 'Venda detalhada na empresa selecionada pelo F11. Nesta etapa, somente grava o pedido; emissão fiscal permanece bloqueada.' : 'Venda orientada à NF-e, com conferência e alteração da tributação item a item.');
gestao_flash();if($erro)echo '<div class="msg err">'.gestao_h($erro).'</div>';
if ($detailedCrossContext) echo '<div class="msg ok"><strong>V11.30 · Pedido Detalhado Multiempresa.</strong> Cliente, produtos, transportadora, pagamentos, Caixa, estoque e snapshot tributário pertencem à empresa selecionada pelo F11. A finalização é obrigatoriamente <strong>Somente gravar pedido</strong>; NF-e, NFC-e e NFS-e continuam bloqueadas.</div>';
if ($detailedSaleFinalizationBlocked) echo '<div class="msg" style="background:#fff7ed;color:#9a3412;border:1px solid #fed7aa;">Este perfil está configurado com <strong>Não permitir finalizar a venda</strong>. A montagem e conferência continuam disponíveis, mas o fechamento está bloqueado.</div>';
if ($orcamentoReadEnabled) echo '<div style="display:flex;justify-content:flex-end;margin:0 0 10px"><button type="button" class="btn btn-primary" onclick="openOrcamentoReader()">Ler Orçamento</button></div>';
?>
<style>
.pdh-steps{display:flex;gap:8px;margin:8px 0 14px;flex-wrap:wrap}.pdh-step{border:1px solid #dbe4f0;background:#fff;border-radius:999px;padding:9px 14px;font-weight:900;color:#64748b}.pdh-step.active{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));border-color:transparent;color:#fff}.pdh-pane{display:none}.pdh-pane.active{display:block}.pdh-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:11px}.pdh-grid .span-2{grid-column:span 2}.pdh-grid .span-4{grid-column:1/-1}.pdh-field label{display:block;font-size:12px;font-weight:900;color:#334155;margin-bottom:5px}.pdh-field input,.pdh-field select,.pdh-field textarea{width:100%;box-sizing:border-box}.pdh-actions{display:flex;justify-content:space-between;gap:10px;margin-top:14px;border-top:1px solid #eef2f7;padding-top:14px}.pdh-search-line{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}.pdh-selected{border:1px solid #bfdbfe;background:#eff6ff;border-radius:16px;padding:10px;margin-top:8px;display:none}.pdh-selected.show{display:block}.pdh-item{border:1px solid #dbe4f0;border-radius:18px;background:#fff;margin-bottom:10px;overflow:hidden}.pdh-item-main{display:grid;grid-template-columns:minmax(280px,2.2fr) 95px 120px 120px 46px;gap:8px;align-items:end;padding:11px}.pdh-item-title strong{display:block;color:#0b1f5b}.pdh-item-title small{display:block;color:#64748b;margin-top:3px}.pdh-item-main input{width:100%;box-sizing:border-box}.pdh-remove{height:40px;border-radius:12px;border:1px solid #fecaca;background:#fff;color:#b91c1c;font-weight:950;cursor:pointer}.pdh-tax{border-top:1px solid #e5edf6;background:#f8fbff;padding:10px}.pdh-tax summary{cursor:pointer;font-weight:950;color:#1e3a8a}.pdh-tax-grid{display:grid;grid-template-columns:repeat(6,minmax(0,1fr));gap:8px;margin-top:9px}.pdh-tax-grid label{font-size:11px;font-weight:900;color:#475569}.pdh-tax-grid input{width:100%;box-sizing:border-box;min-height:36px}.pdh-product-search{position:relative}.pdh-results{position:absolute;z-index:80;left:0;right:0;top:100%;background:#fff;border:1px solid #dbe4f0;border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.18);max-height:280px;overflow:auto;display:none}.pdh-results.open{display:block}.pdh-result{padding:9px 10px;border-bottom:1px solid #eef2f7;cursor:pointer}.pdh-result:hover{background:#eff6ff}.pdh-summary{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px}.pdh-kpi{border:1px solid #dbe4f0;border-radius:16px;padding:10px;background:#fff}.pdh-kpi span{display:block;color:#64748b;font-size:11px;font-weight:850}.pdh-kpi strong{display:block;margin-top:5px;color:#0b1f5b;font-size:18px}.pdh-pay-row{display:grid;grid-template-columns:90px minmax(240px,1fr) 150px 44px;gap:8px;align-items:end;margin-bottom:8px}.pdh-pay-row input,.pdh-pay-row select{width:100%;box-sizing:border-box}.pdh-pay-remove{height:40px}.pdh-hint{font-size:12px;color:#64748b;margin-top:5px}.pdh-modal{position:fixed;inset:0;z-index:900;background:rgba(15,23,42,.55);display:none;align-items:flex-start;justify-content:center;padding:5vh 16px;overflow:auto}.pdh-modal.open{display:flex}.pdh-dialog{width:min(900px,100%);background:#fff;border-radius:22px;padding:16px;box-shadow:0 30px 80px rgba(2,6,23,.35)}.pdh-modal-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.pdh-modal-results{margin-top:10px;display:grid;gap:7px}.pdh-person,.pdh-cfop{border:1px solid #b8cae0;border-radius:14px;padding:10px;cursor:pointer;background:#f8fbff}.pdh-person:hover,.pdh-cfop:hover{background:#e0ebf8;border-color:#7fa4d2}.pdh-person.pdh-active,.pdh-cfop.pdh-active{background:#c7d9ef;border-color:#3b6ea8;box-shadow:inset 4px 0 #2563eb}@media(max-width:1100px){.pdh-tax-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.pdh-item-main{grid-template-columns:minmax(220px,1.6fr) 90px 115px 115px 44px}.pdh-grid{grid-template-columns:repeat(2,minmax(0,1fr))}.pdh-grid .span-4{grid-column:1/-1}}@media(max-width:720px){.pdh-grid,.pdh-tax-grid,.pdh-summary,.pdh-pay-row,.pdh-item-main{grid-template-columns:1fr}.pdh-grid .span-2,.pdh-grid .span-4{grid-column:auto}.pdh-remove{width:100%}}

.pdh-launch{border:1px solid #dbe4f0;border-radius:18px;background:#fff;overflow:hidden}
.pdh-launch-head{display:flex;align-items:center;gap:10px;padding:12px 14px;border-bottom:1px solid #e5edf6;font-weight:900;color:#0b1f5b;background:#f8fbff}
.pdh-launch-head .dot{width:24px;height:24px;border-radius:999px;background:#0b1f5b;color:#fff;display:inline-flex;align-items:center;justify-content:center;font-size:18px;line-height:1}
.pdh-launch-body{padding:12px 14px}
.pdh-launch-grid{display:grid;grid-template-columns:minmax(280px,2.2fr) 115px 115px 115px 95px 95px 95px 95px 115px;gap:8px;align-items:end}
.pdh-launch-grid .wide-search{display:grid;grid-template-columns:minmax(0,1fr) auto;gap:8px}
.pdh-mini label,.pdh-launch-grid label{display:block;font-size:11px;font-weight:900;color:#334155;margin-bottom:5px}
.pdh-launch-grid input{width:100%;box-sizing:border-box}
.pdh-inline-note{font-size:12px;color:#64748b;margin-top:8px}
.pdh-item-main{grid-template-columns:minmax(260px,2fr) 90px 110px 110px 90px 90px 90px 90px 120px 46px}
.pdh-item-main .pdh-item-title small{margin-bottom:6px}
.pdh-item-main label{font-size:11px;font-weight:900;color:#334155}
.pdh-item-main label input{margin-top:4px}
@media(max-width:1400px){.pdh-launch-grid{grid-template-columns:minmax(240px,2fr) 100px 100px 100px 90px 90px 90px 90px 110px}.pdh-item-main{grid-template-columns:minmax(220px,1.7fr) 85px 100px 100px 85px 85px 85px 85px 110px 44px}}
@media(max-width:1100px){.pdh-launch-grid,.pdh-item-main{grid-template-columns:repeat(3,minmax(0,1fr))}.pdh-item-main .pdh-item-title{grid-column:1/-1}.pdh-item-main .pdh-remove{grid-column:1/-1}}
@media(max-width:720px){.pdh-launch-grid,.pdh-item-main{grid-template-columns:1fr}.pdh-launch-grid .wide-search{grid-template-columns:1fr}.pdh-item-main .pdh-remove{width:100%}}


.pdh-product-dialog{width:min(900px,96vw);padding:0;overflow:hidden}.pdh-product-dialog .pdh-modal-head{padding:14px 16px;border-bottom:1px solid #dbe4f0}.pdh-product-dialog-body{padding:15px}.pdh-product-searchbar{display:flex;gap:8px;align-items:center}.pdh-product-searchbar input{flex:1}.pdh-product-searchbar select{width:180px;max-width:32vw}.pdh-product-results{margin-top:12px;display:block;border:1px solid #dbe4f0;border-radius:12px;overflow-y:auto;max-height:48vh;background:#fff}.pdh-product-choice{border:0;border-bottom:1px solid #cbd9e8;padding:0;cursor:pointer;outline:none;background:#f8fbff}.pdh-product-choice:last-child{border-bottom:0}.pdh-product-choice:hover{background:#e0ebf8}.pdh-product-choice.active{background:#c7d9ef;box-shadow:inset 4px 0 #2563eb}.pdh-product-result{display:grid;grid-template-columns:38px 90px minmax(220px,1fr) 112px 105px 105px;gap:8px;align-items:center;padding:5px 8px;min-height:44px}.pdh-product-result.no-cost{grid-template-columns:38px 90px minmax(220px,1fr) 112px 105px}.pdh-product-thumb{width:34px;height:34px;object-fit:cover;border-radius:7px;border:1px solid #dbe4f0;background:#f8fafc;cursor:zoom-in}.pdh-product-code{font-size:12px;font-weight:950;color:#0b1f5b;white-space:nowrap}.pdh-product-name{min-width:0}.pdh-product-name strong{display:block;color:#0b1f5b;line-height:1.1}.pdh-product-name small{display:block;color:#64748b;font-size:9px;line-height:1.1;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.pdh-product-stock{font-size:11px;color:#475569;text-align:right;white-space:nowrap}.pdh-product-cost{font-size:11px;font-weight:850;color:#475569;text-align:right;white-space:nowrap}.pdh-product-price{font-size:13px;font-weight:950;color:#047857;text-align:right;white-space:nowrap}.pdh-image-preview{display:none;position:fixed;inset:0;z-index:980;background:rgba(15,23,42,.76);align-items:center;justify-content:center;padding:20px}.pdh-image-preview.open{display:flex}.pdh-image-preview-box{position:relative;display:flex;align-items:center;justify-content:center;max-width:96vw;max-height:92vh}.pdh-image-preview img{max-width:min(900px,94vw);max-height:88vh;border-radius:16px;background:#fff;box-shadow:0 30px 90px rgba(0,0,0,.45)}.pdh-image-preview-close{position:absolute;right:-10px;top:-10px;z-index:2;width:38px;height:38px;border:0;border-radius:50%;background:#fff;color:#0f172a;font-size:25px;font-weight:900;line-height:1;cursor:pointer;box-shadow:0 8px 24px rgba(0,0,0,.28)}.pdh-image-preview-close:hover{background:#f1f5f9}@media(max-width:900px){.pdh-product-result{grid-template-columns:38px 80px minmax(180px,1fr) 100px 95px}}@media(max-width:620px){.pdh-product-searchbar{align-items:stretch;flex-direction:column}.pdh-product-searchbar select{width:100%;max-width:none}.pdh-product-result{grid-template-columns:38px minmax(0,1fr) 88px}.pdh-product-code,.pdh-product-stock,.pdh-product-cost{display:none}}
.pdh-payment-layout{display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:12px;align-items:start}
.pdh-payment-main,.pdh-payment-side{border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:12px}
.pdh-payment-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start;margin-bottom:10px}
.pdh-payment-head h2{margin:0 0 3px}
.pdh-installments{grid-column:1/-1;display:none;border-top:1px dashed #cbd5e1;padding-top:9px;margin-top:2px}.pdh-installments.show{display:block}.pdh-installments-head{display:flex;justify-content:space-between;gap:10px;align-items:end}.pdh-installments-head label{width:110px}.pdh-installment-row{display:grid;grid-template-columns:70px minmax(150px,1fr) minmax(120px,.7fr);gap:8px;align-items:center;margin-top:6px}.pdh-installment-row span{font-size:11px;font-weight:900;color:#475569}.pdh-installment-row input{width:100%;box-sizing:border-box}
.pdh-pay-shortcuts{display:flex;gap:7px;flex-wrap:wrap;padding:8px;border:1px dashed #bfdbfe;background:#eff6ff;border-radius:14px;margin-bottom:10px}
.pdh-pay-chip{border:1px solid #dbe4f0;background:#fff;border-radius:10px;padding:7px 9px;font-weight:900;color:#0b1f5b;cursor:pointer}
.pdh-pay-chip .key{display:inline-flex;min-width:22px;height:22px;align-items:center;justify-content:center;border-radius:6px;background:#0b1f5b;color:#fff;margin-right:6px;font-size:11px}
.pdh-pay-row{display:grid;grid-template-columns:minmax(240px,1fr) minmax(150px,.65fr) 42px;gap:8px;align-items:end;border:2px solid #2563eb;border-radius:14px;padding:9px;margin-bottom:8px}
.pdh-pay-row label{font-size:11px;font-weight:900;color:#334155}
.pdh-pay-row select,.pdh-pay-row input{margin-top:4px}
.pdh-pay-side-line{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid #eef2f7}
.pdh-pay-side-line strong{color:#0b1f5b}.pdh-pay-diff.ok{color:#15803d}.pdh-pay-diff.bad{color:#b91c1c}
.pdh-pay-grand{display:flex;justify-content:space-between;align-items:center;font-size:22px;font-weight:950;color:#0b1f5b;padding:12px 0}
.pdh-final-btn{width:100%;min-height:44px;font-weight:950}.pdh-final-btn small{margin-left:5px;opacity:.8}
@media(max-width:900px){.pdh-payment-layout{grid-template-columns:1fr}.pdh-payment-side{order:-1}}
@media(max-width:620px){.pdh-pay-row{grid-template-columns:1fr}.pdh-pay-shortcuts{gap:5px}}


.pdh-fiscal-dialog{width:min(780px,100%)}
.pdh-fiscal-choice-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:10px}
.pdh-fiscal-choice{border:1px solid #dbe4f0;border-radius:18px;background:#fff;padding:16px;text-align:left;cursor:pointer;min-height:92px;transition:.15s}
.pdh-fiscal-choice:hover{border-color:#60a5fa;background:#eff6ff;transform:translateY(-1px)}
.pdh-fiscal-choice strong{display:block;color:#0b1f5b;font-size:16px}.pdh-fiscal-choice small{display:block;color:#64748b;margin-top:5px;line-height:1.35}
@media(max-width:620px){.pdh-fiscal-choice-grid{grid-template-columns:1fr}}

/* V11.45.14 - contraste azul alinhado ao cadastro de produtos */
.pdh-pane .mg-card,.pdh-payment-main,.pdh-payment-side{background:#f7faff!important;border-color:#bdd0e8!important}
.pdh-launch,.pdh-item,.pdh-kpi,.pdh-product-choice,.pdh-person,.pdh-cfop{border-color:#bdd0e8;background:#f8fbff}
.pdh-launch-head{background:#eaf2fc;border-bottom-color:#bdd0e8}
.pdh-tax{background:#eef5fd;border-top-color:#bdd0e8}
.pdh-actions{border-top-color:#c6d6e9}
.pdh-pane input:not([type="hidden"]),.pdh-pane select,.pdh-pane textarea,
.pdh-dialog input:not([type="hidden"]),.pdh-dialog select,.pdh-dialog textarea{
  background:#fbfdff!important;border:1px solid #9fb8d8!important;color:#0f172a!important;
  box-shadow:inset 0 1px 2px rgba(30,64,175,.035);
  transition:border-color .15s ease,box-shadow .15s ease,background .15s ease
}
.pdh-pane input:not([type="hidden"]):focus,.pdh-pane select:focus,.pdh-pane textarea:focus,
.pdh-dialog input:not([type="hidden"]):focus,.pdh-dialog select:focus,.pdh-dialog textarea:focus{
  background:#fff!important;border-color:#2563eb!important;outline:none!important;
  box-shadow:0 0 0 3px rgba(37,99,235,.13)!important
}
.pdh-pane input[readonly],.pdh-pane textarea[readonly],.pdh-pane input:disabled,.pdh-pane select:disabled,
.pdh-dialog input[readonly],.pdh-dialog input:disabled,.pdh-dialog select:disabled{
  background:#edf4fc!important;border-color:#b5c9e2!important;color:#475569!important
}
.pdh-selected{border-color:#a8c1df;background:#edf5ff}
.pdh-results{border-color:#afc5e0;background:#fbfdff}
.pdh-payment-main,.pdh-payment-side{box-shadow:0 5px 18px rgba(30,64,175,.05)}

.pdh-item-observation{padding:0 11px 12px;display:flex;flex-direction:column;gap:5px}
.pdh-item-observation label{font-size:12px;font-weight:900;color:#334155}
.pdh-item-observation textarea{width:100%;box-sizing:border-box;resize:vertical;min-height:56px}
.pdh-item-observation small{font-size:11px;color:#64748b}
.pdh-service-tax-hint{font-size:12px;color:#335179;background:#eef5ff;padding:9px 12px;border-radius:10px;margin:10px 0 2px}.pdh-tax-grid input[readonly]{background:#f8fafc;color:#183459;cursor:default}
</style>
<form method="post" id="pdhForm" autocomplete="off">
<input type="hidden" name="action" value="finalize_detailed_order">
<?php if ($detailedCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= gestao_h($detailedMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= gestao_h($detailedMcCtx) ?>"><?php endif; ?>
<input type="hidden" name="emit_doc_after" id="emitDocAfter" value="none">
<input type="hidden" name="orcamento_id" id="orcamentoId" value="">
<div class="pdh-steps"><button type="button" class="pdh-step active" data-step="1">1. Cliente / NF-e</button><button type="button" class="pdh-step" data-step="2">2. Produtos e tributação</button><button type="button" class="pdh-step" data-step="3">3. Totais / Transporte</button><button type="button" class="pdh-step" data-step="4">4. Pagamento</button></div>

<section class="pdh-pane active" data-pane="1"><div class="mg-card"><h2>Cliente e parâmetros da NF-e</h2><div class="pdh-grid">
<div class="pdh-field span-2"><label>Cliente *</label><div class="pdh-search-line"><input id="customerText" placeholder="F8 ou digite nome/CPF/CNPJ" readonly><button class="btn" id="btnCustomer" type="button">Buscar (F8)</button></div><div class="pdh-selected" id="customerSelected"></div><input type="hidden" name="customer_id" id="customerId"><input type="hidden" name="customer_name" id="customerName"><input type="hidden" name="whatsapp" id="customerWhatsapp"><input type="hidden" name="zip_code" id="customerZip"><input type="hidden" name="address" id="customerAddress"><input type="hidden" name="address_number" id="customerNumber"><input type="hidden" name="address_complement" id="customerComplement"><input type="hidden" name="district" id="customerDistrict"><input type="hidden" name="city" id="customerCity"><input type="hidden" name="state" id="customerState"></div>
<div class="pdh-field"><label>Vendedor</label><div class="pdh-search-line"><input id="sellerText" data-f8-role="seller" value="<?= gestao_h($defaultSellerName) ?>" placeholder="Selecione o vendedor" readonly><button class="btn" id="btnSeller" data-f8-role="seller" type="button" <?= !$allowSeller?'disabled':'' ?>>Buscar (F8)</button></div><input type="hidden" name="seller_id" id="sellerId" value="<?= $defaultSeller ?>"></div>
<div class="pdh-field"><label>Profissional</label><div class="pdh-search-line"><input id="professionalText" data-f8-role="professional" placeholder="Selecione o profissional" readonly><button class="btn" id="btnProfessional" data-f8-role="professional" type="button">Buscar (F8)</button></div><input type="hidden" name="professional_id" id="professionalId" value=""></div>
<div class="pdh-field span-2"><label>CFOP padrão da NF-e</label><div class="pdh-search-line"><input name="default_cfop" id="defaultCfop" data-f8-role="cfop" maxlength="4" inputmode="numeric" value="<?= gestao_h($defaultCfop) ?>" placeholder="Ex.: 5102"><button class="btn" id="btnCfop" data-f8-role="cfop" type="button">Buscar (F8)</button></div></div>
<div class="pdh-field span-2"><label>Observação do pedido (NF-e / NFS-e)</label><textarea name="notes" rows="2" maxlength="1000" placeholder="Informações adicionais para constar na nota fiscal e no pedido"></textarea><div class="pdh-hint">Quando preenchida, a observação também será encaminhada à NF-e ou NFS-e emitida por este pedido.</div></div>
</div><div class="pdh-actions"><span></span><button class="btn btn-primary pdh-next" data-next="2" type="button">Produtos →</button></div></div></section>

<section class="pdh-pane" data-pane="2"><div class="mg-card"><div class="pdh-launch"><div class="pdh-launch-head"><span class="dot">−</span><span>Adicionar produtos / serviços</span></div><div class="pdh-launch-body"><div class="pdh-launch-grid">
<div><label>Produto / Serviço</label><div class="wide-search pdh-product-search"><input id="productSearch" placeholder="Selecione o produto ou serviço"><button class="btn" type="button" id="btnProductSearch">Buscar (F8)</button><div class="pdh-results" id="productResults"></div></div></div>
<div><label>Quantidade</label><input id="draftQty" value="1" inputmode="decimal" readonly></div>
<div><label>Preço venda</label><input id="draftPrice" value="0,00" readonly></div>
<div><label>Valor Total</label><input id="draftTotal" value="0,00" readonly></div>
<div><label>% Acrésc.</label><input value="0,00" readonly></div>
<div><label>Vlr Acrésc.</label><input value="0,00" readonly></div>
<div><label>% Desc.</label><input value="0,00" readonly></div>
<div><label>Vlr Desc.</label><input value="0,00" readonly></div>
<div><label>Total Líquido</label><input id="draftNet" value="0,00" readonly></div>
</div><div class="pdh-inline-note">Ao selecionar o produto, ele é adicionado abaixo com a <strong>Tributação do item</strong> recolhida. Clique nela somente quando precisar conferir ou alterar a tributação.</div></div></div><div id="itemsWrap" style="margin-top:12px"></div><div class="pdh-actions"><button class="btn pdh-next" data-next="1" type="button">← Cliente</button><button class="btn btn-primary pdh-next" data-next="3" type="button">Totais / transporte →</button></div></div></section>

<section class="pdh-pane" data-pane="3"><div class="mg-card"><h2>Totais e transporte</h2><div class="pdh-summary"><div class="pdh-kpi"><span>Produtos</span><strong id="sumProducts">R$ 0,00</strong></div><div class="pdh-kpi"><span>Desconto</span><strong id="sumDiscount">R$ 0,00</strong></div><div class="pdh-kpi"><span>Acréscimo</span><strong id="sumAddition">R$ 0,00</strong></div><div class="pdh-kpi"><span>Frete</span><strong id="sumFreight">R$ 0,00</strong></div><div class="pdh-kpi"><span>Total</span><strong id="sumTotal">R$ 0,00</strong></div></div>
<div class="pdh-grid" style="margin-top:12px"><div class="pdh-field"><label>Desconto</label><input name="discount_amount" id="discount" value="0,00" inputmode="decimal"></div><div class="pdh-field"><label>Acréscimo</label><input name="addition_amount" id="addition" value="0,00" inputmode="decimal"></div><div class="pdh-field"><label>Frete</label><input name="freight_amount" id="freight" value="0,00" inputmode="decimal"></div><div class="pdh-field"><label>Modalidade do frete</label><select name="freight_mode"><option value="9">9 - Sem ocorrência de transporte</option><option value="0">0 - Frete por conta do remetente (CIF)</option><option value="1">1 - Frete por conta do destinatário (FOB)</option><option value="2">2 - Frete por conta de terceiros</option><option value="3">3 - Transporte próprio remetente</option><option value="4">4 - Transporte próprio destinatário</option></select></div>
<div class="pdh-field span-2"><label>Transportadora</label><div class="pdh-search-line"><input name="carrier_name" id="carrierName" data-f8-role="carrier" placeholder="Nome da transportadora"><button class="btn" id="btnCarrier" data-f8-role="carrier" type="button">Buscar (F8)</button></div><input type="hidden" name="carrier_person_id" id="carrierId"></div><div class="pdh-field"><label>CNPJ/CPF transportadora</label><input name="carrier_document" id="carrierDoc"></div><div class="pdh-field"><label>IE transportadora</label><input name="carrier_ie" id="carrierIe"></div><div class="pdh-field span-2"><label>Endereço transportadora</label><input name="carrier_address" id="carrierAddress"></div><div class="pdh-field"><label>Cidade</label><input name="carrier_city" id="carrierCity"></div><div class="pdh-field"><label>UF</label><input name="carrier_state" id="carrierState" maxlength="2"></div></div>
<div class="pdh-actions"><button class="btn pdh-next" data-next="2" type="button">← Produtos</button><button class="btn btn-primary pdh-next" data-next="4" type="button">Pagamento →</button></div></div></section>

<section class="pdh-pane" data-pane="4"><div class="pdh-payment-layout"><div class="pdh-payment-main"><div class="pdh-payment-head"><div><h2>Formas de pagamento</h2><div class="mg-muted">Use o número da forma, digite o valor, pressione + para adicionar e - para remover.</div></div><button class="btn" type="button" id="addPayment">+ Pagamento</button></div><div class="pdh-pay-shortcuts" id="paymentShortcuts"></div><div id="paymentsWrap"></div><div class="pdh-hint">Atalhos: número seleciona a forma · + adiciona · - remove · F10 finaliza.</div><div class="pdh-actions"><button class="btn pdh-next" data-next="3" type="button">← Totais / transporte</button><span></span></div></div><aside class="pdh-payment-side"><div class="pdh-pay-side-line"><span>Cliente</span><strong id="payCustomerName">Não selecionado</strong></div><div class="pdh-pay-side-line"><span>Total do pedido</span><strong id="payOrderTotal">R$ 0,00</strong></div><div class="pdh-pay-side-line"><span>Total informado</span><strong id="payInformed">R$ 0,00</strong></div><div class="pdh-pay-side-line"><span>Diferença</span><strong id="payDifference" class="pdh-pay-diff ok">R$ 0,00</strong></div><div class="pdh-pay-grand"><span>Total</span><span id="payGrandTotal">R$ 0,00</span></div><button class="btn btn-primary pdh-final-btn" id="finalizeDetailedOrder" type="submit" <?= $detailedSaleFinalizationBlocked ? 'disabled aria-disabled="true"' : '' ?>><?= $detailedSaleFinalizationBlocked ? 'Finalização bloqueada' : 'Finalizar pedido' ?> <small>F10</small></button></aside></div></section>
</form>


<?php if ($detailedFiscalEnabled): ?>
<div class="pdh-modal" id="detailedFiscalModal"><div class="pdh-dialog pdh-fiscal-dialog"><div class="pdh-modal-head"><div><h2 style="margin:0">Documento fiscal</h2><div class="mg-muted" style="margin-top:4px">Escolha o documento que deseja emitir agora ou apenas salve o pedido.</div></div><button class="btn" type="button" id="detailedFiscalClose">×</button></div><div class="pdh-fiscal-choice-grid">
<?php if ($detailedCanNfce): ?><button type="button" class="pdh-fiscal-choice" data-detailed-fiscal="nfce"><strong>1 · Emitir NFC-e</strong><small>Cupom fiscal eletrônico modelo 65.</small></button><?php endif; ?>
<?php if ($detailedCanNfe): ?><button type="button" class="pdh-fiscal-choice" data-detailed-fiscal="nfe"><strong>2 · Emitir NF-e</strong><small>Nota fiscal eletrônica modelo 55 usando a tributação detalhada dos itens.</small></button><?php endif; ?>
<?php if ($detailedCanNfse): ?><button type="button" class="pdh-fiscal-choice" data-detailed-fiscal="nfse"><strong>3 · Emitir NFS-e</strong><small>Salva o pedido, transmite a NFS-e e abre o documento autorizado.</small></button><?php endif; ?>
<?php if ($detailedCanSave): ?><button type="button" class="pdh-fiscal-choice" data-detailed-fiscal="none"><strong>4 · Salvar pedido</strong><small>Grava o pedido sem emitir documento fiscal.</small></button><?php endif; ?>
</div></div></div>
<?php endif; ?>

<div class="pdh-modal" id="personModal"><div class="pdh-dialog"><div class="pdh-modal-head"><div><h2 id="personModalTitle" style="margin:0">Buscar</h2><div class="mg-muted">Digite e pressione Enter. Use ↑/↓ e Enter para selecionar.</div></div><button class="btn" type="button" id="personClose">×</button></div><input id="personQuery" style="width:100%;margin-top:10px" placeholder="Nome, documento ou telefone"><div class="pdh-modal-results" id="personResults"></div></div></div>
<div class="pdh-modal" id="cfopModal"><div class="pdh-dialog"><div class="pdh-modal-head"><div><h2 style="margin:0">Buscar CFOP</h2><div class="mg-muted">Digite o código ou parte da descrição. Use ↑/↓ e Enter para selecionar.</div></div><button class="btn" type="button" id="cfopClose">×</button></div><input id="cfopQuery" style="width:100%;margin-top:10px" placeholder="Código ou descrição do CFOP"><div class="pdh-modal-results" id="cfopResults"></div></div></div>
<div class="pdh-modal" id="productModal"><div class="pdh-dialog pdh-product-dialog"><div class="pdh-modal-head"><div><h2 style="margin:0">Buscar produto / serviço</h2><div class="mg-muted">Pesquisa no mesmo padrão do orçamento. Enter pesquisa; ↑/↓ percorrem; Enter seleciona.</div></div><button class="btn" type="button" id="productModalClose">Fechar</button></div><div class="pdh-product-dialog-body"><div class="pdh-product-searchbar"><input id="productModalQuery" placeholder="Digite e pressione Enter"><select id="productModalType"><option value="">Todos</option><option value="PRODUTO">Produtos</option><option value="SERVICO">Serviços</option></select><button class="btn btn-primary" type="button" id="productModalSearchBtn">Pesquisar</button></div><div class="pdh-modal-results pdh-product-results" id="productModalResults"></div></div></div></div><div class="pdh-image-preview" id="pdhImagePreview"><div class="pdh-image-preview-box"><button type="button" class="pdh-image-preview-close" id="pdhImagePreviewClose" aria-label="Fechar">×</button><img id="pdhImagePreviewImg" alt="Imagem do produto"></div></div>
<script>
(()=>{
const paymentMethods=<?= json_encode($paymentJson,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const detailedAjaxBase=<?= json_encode($detailedContextUrl('gestao_pedido_detalhado.php'),JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) ?>;
const detailedFiscalEnabled=<?= $detailedFiscalEnabled ? 'true' : 'false' ?>;
const allowSellerChange=<?= $allowSeller ? 'true' : 'false' ?>;
const todayIso=<?= json_encode(date('Y-m-d')) ?>;
let detailedFiscalConfirmed=!detailedFiscalEnabled;
const $=s=>document.querySelector(s), $$=s=>Array.from(document.querySelectorAll(s));
const money=v=>'R$ '+Number(v||0).toLocaleString('pt-BR',{minimumFractionDigits:2,maximumFractionDigits:2});
const num=v=>{v=String(v??'').replace(/R\$|\s/g,''); if(v.includes(','))v=v.replace(/\./g,'').replace(',','.'); return Number(v.replace(/[^0-9.-]/g,''))||0};
function isoAddDays(dateStr,days){const d=new Date(String(dateStr||todayIso)+'T12:00:00');d.setDate(d.getDate()+Number(days||0));return d.toISOString().slice(0,10)}
function isoAddMonthsKeepDay(dateStr,months,day){const base=new Date(String(dateStr||todayIso)+'T12:00:00'),first=new Date(base.getFullYear(),base.getMonth()+Number(months||0),1,12,0,0),last=new Date(first.getFullYear(),first.getMonth()+1,0).getDate();first.setDate(Math.min(Math.max(1,Number(day||base.getDate())),last));return first.toISOString().slice(0,10)}
function isoNextFixedDay(dateStr,day){const base=new Date(String(dateStr||todayIso)+'T12:00:00'),wanted=Math.max(1,Math.min(31,Number(day||1)));for(let i=0;i<24;i++){const d=new Date(base.getFullYear(),base.getMonth()+i,1,12,0,0),last=new Date(d.getFullYear(),d.getMonth()+1,0).getDate();d.setDate(Math.min(wanted,last));if(d>base)return d.toISOString().slice(0,10)}return isoAddDays(dateStr,30)}
function splitInstallments(total,count){const cents=Math.round(Number(total||0)*100);count=Math.max(1,Number(count||1));const base=Math.floor(cents/count),rem=cents-base*count;return Array.from({length:count},(_,i)=>(base+(i===count-1?rem:0))/100)}
function go(n){$$('.pdh-pane').forEach(x=>x.classList.toggle('active',x.dataset.pane===String(n)));$$('.pdh-step').forEach(x=>x.classList.toggle('active',x.dataset.step===String(n)));if(String(n)==='2')focusProductEntry(60);if(String(n)==='4'){updatePaymentSummary();setTimeout(()=>focusPaymentSelector(),40)}window.scrollTo({top:0,behavior:'smooth'})}
$$('.pdh-next,.pdh-step').forEach(b=>b.addEventListener('click',()=>go(b.dataset.next||b.dataset.step)));
let itemSeq=0;
function esc(s){return String(s??'').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]))}
function detailedAjaxUrl(params){return detailedAjaxBase+(detailedAjaxBase.includes('?')?'&':'?')+params}async function getJSON(url){const r=await fetch(url,{headers:{'X-Requested-With':'XMLHttpRequest'}});return await r.json()}
let productActive=-1;
function openProductModal(query=''){productActive=-1;$('#productModal').classList.add('open');$('#productModalQuery').value=query||'';$('#productModalResults').innerHTML='';setTimeout(()=>{ $('#productModalQuery').focus(); if((query||'').trim()) productModalSearch(); },40)}
function focusProductEntry(delay=30){setTimeout(()=>{let el=$('#productSearch');if(!el)return;el.focus({preventScroll:true});if(typeof el.select==='function')el.select();el.scrollIntoView({block:'center',behavior:'smooth'})},delay)}
function closeProductModal(){$('#productModal').classList.remove('open');productActive=-1;focusProductEntry(40)}
function closeProductImagePreview(){$('#pdhImagePreview')?.classList.remove('open');if($('#pdhImagePreviewImg'))$('#pdhImagePreviewImg').src=''}
async function productModalSearch(){let q=$('#productModalQuery').value.trim(),type=$('#productModalType')?.value||'',box=$('#productModalResults');box.innerHTML='<div class="mg-muted" style="padding:10px">Pesquisando...</div>';productActive=-1;let d=await getJSON(detailedAjaxUrl('ajax=products&type='+encodeURIComponent(type)+'&q='+encodeURIComponent(q)));if(!d.ok){box.innerHTML='<div class="msg err" style="margin:8px">'+esc(d.message||'Não foi possível pesquisar os produtos.')+'</div>';return}(d.rows||[]).forEach((p,idx)=>{let e=document.createElement('div');e.className='pdh-product-choice';e.tabIndex=0;e.dataset.index=idx;let noCost=p.custo===null||p.custo===undefined,service=parseInt(p.is_service||0,10)===1,code=p.numero_cadastro||p.codigo_origem||p.codigo||p.id,barcode=p.codigo_barras?('Cód. barras: '+esc(p.codigo_barras)):'Sem código de barras',stock=service?'Serviço':('Qtd.: '+Number(p.available_qty||0).toLocaleString('pt-BR',{maximumFractionDigits:4})+' '+esc(p.unidade_estoque||p.unidade||'UN')),cost=noCost?'':('Custo R$ '+Number(p.custo||0).toFixed(2).replace('.',',')),price='R$ '+Number(p.preco||0).toFixed(2).replace('.',',');e.innerHTML='<div class="pdh-product-result'+(noCost?' no-cost':'')+'"><img class="pdh-product-thumb" src="'+esc(p.image_url||'/assets/no-image.svg')+'" data-preview-image="'+esc(p.image_url||'/assets/no-image.svg')+'" alt=""><div class="pdh-product-code">'+esc(code)+'</div><div class="pdh-product-name"><strong>'+esc(p.nome)+'</strong><small>'+barcode+'</small></div><div class="pdh-product-stock">'+stock+'</div><div class="pdh-product-cost">'+esc(cost)+'</div><div class="pdh-product-price">'+esc(price)+'</div></div>';e.onclick=ev=>{if(ev.target.closest('[data-preview-image]'))return;chooseProduct(p.id)};e.onkeydown=ev=>productRowKey(ev,idx,p.id);box.appendChild(e)});if(!(d.rows||[]).length)box.innerHTML='<div class="mg-muted" style="padding:10px">Nenhum produto encontrado.</div>'}
function productFocus(index){let rows=$$('.pdh-product-choice');if(!rows.length)return;productActive=Math.max(0,Math.min(index,rows.length-1));rows.forEach((r,i)=>r.classList.toggle('active',i===productActive));rows[productActive].focus();rows[productActive].scrollIntoView({block:'nearest'})}
function productRowKey(ev,idx,id){if(ev.key==='ArrowDown'){ev.preventDefault();productFocus(idx+1)}else if(ev.key==='ArrowUp'){ev.preventDefault();if(idx===0){$('#productModalQuery').focus();productActive=-1}else productFocus(idx-1)}else if(ev.key==='Enter'){ev.preventDefault();chooseProduct(id)}else if(ev.key.length===1&&!ev.ctrlKey&&!ev.altKey&&!ev.metaKey){ev.preventDefault();$('#productModalQuery').focus();$('#productModalQuery').value=ev.key;productActive=-1}}
async function chooseProduct(id){await addProduct(id);closeProductModal()}
$('#productSearch').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();openProductModal($('#productSearch').value.trim())}});
$('#btnProductSearch').addEventListener('click',()=>openProductModal($('#productSearch').value.trim()));
$('#productModalClose').onclick=closeProductModal;$('#productModal').addEventListener('click',e=>{if(e.target===$('#productModal'))closeProductModal()});
$('#productModalQuery').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();if(productActive>=0){let r=$$('.pdh-product-choice')[productActive];if(r)r.click();}else productModalSearch()}else if(e.key==='ArrowDown'){e.preventDefault();productFocus(0)}else if(e.key==='Escape'){e.preventDefault();closeProductModal()}});
$('#productModalSearchBtn').addEventListener('click',productModalSearch);
$('#productModalType').addEventListener('change',()=>{productActive=-1;productModalSearch()});
$('#productModalResults').addEventListener('click',e=>{const img=e.target.closest('[data-preview-image]');if(!img)return;e.preventDefault();e.stopPropagation();$('#pdhImagePreviewImg').src=img.dataset.previewImage||img.src;$('#pdhImagePreview').classList.add('open')});
$('#pdhImagePreviewClose').addEventListener('click',e=>{e.preventDefault();e.stopPropagation();closeProductImagePreview()});
$('#pdhImagePreview').addEventListener('click',e=>{if(e.target===$('#pdhImagePreview'))closeProductImagePreview()});
async function addProduct(id,seed=null){let d=await getJSON(detailedAjaxUrl('ajax=product&id='+id));if(!d.ok)return;let p=d.row,t=p.tax||{},cfop=$('#defaultCfop').value.replace(/\D/g,'')||t.cfop_sale_state||'5102';itemSeq++;let price=Number(p.preco||0).toFixed(2).replace('.',',');let row=document.createElement('div');row.className='pdh-item';row.dataset.seq=itemSeq;row.dataset.isService=String(Number(p.is_service||0));row.innerHTML=`<div class="pdh-item-main"><div class="pdh-item-title"><strong>${esc(p.nome)}</strong><small>${Number(p.is_service||0)===1?'Serviço':'Produto'} · Cód. ${esc(p.numero_cadastro||p.codigo_firebird||p.id)}${p.referencia?' · Ref. '+esc(p.referencia):''} · ${esc(p.categoria||'')}${Number(p.is_service||0)===1?' · LC 116: '+esc(t.lc116_service_code||'-'):' · NCM '+esc(t.ncm||'-')}</small><input type="hidden" name="item_product_id[]" value="${p.id}"></div><label>Quantidade<input name="item_qty[]" value="1" inputmode="decimal"></label><label>Preço venda<input data-role="unit-price" value="${price}" inputmode="decimal"></label><label>Valor Total<input class="pdh-line-base" value="${price}" readonly></label><label>% Acrésc.<input data-role="add-rate" value="0,00" inputmode="decimal"></label><label>Vlr Acrésc.<input data-role="add-value" value="0,00" inputmode="decimal"></label><label>% Desc.<input data-role="disc-rate" value="0,00" inputmode="decimal"></label><label>Vlr Desc.<input data-role="disc-value" value="0,00" inputmode="decimal"></label><label>Total Líquido<input class="pdh-line-total" value="${price}" readonly></label><button type="button" class="pdh-remove">×</button><input type="hidden" name="item_price[]" data-role="submit-price" value="${price}"></div><div class="pdh-item-observation"><label>Observação do item — complemento para NF-e / NFS-e</label><textarea name="item_obs[]" rows="2" maxlength="500" placeholder="Ex.: Instalação e configuração do sistema na unidade do cliente; período e detalhes do serviço."></textarea><small>Opcional. A observação será enviada na nota fiscal ao emitir NF-e ou NFS-e pelo Pedido Detalhado.</small></div><details class="pdh-tax"><summary>${Number(p.is_service||0)===1?'Tributação do serviço':'Tributação do produto'} — clique para recolher/expandir</summary>${Number(p.is_service||0)===1?'<p class="pdh-service-tax-hint">Tributação carregada do cadastro fiscal do serviço. Para corrigir alíquotas e códigos antes da emissão, altere o <strong>Cadastro de Produtos → Tributação</strong>. Esta tela apenas consulta os dados fiscais do serviço.</p>':''}<div class="pdh-tax-grid">${Number(p.is_service||0)===1?serviceTaxInfo(t):''}
${Number(p.is_service||0)===1?taxInputHidden('ncm',t.ncm):taxInput('NCM','ncm',t.ncm)}${Number(p.is_service||0)===1?taxInputHidden('cest',t.cest):taxInput('CEST','cest',t.cest)}${Number(p.is_service||0)===1?taxInputHidden('cfop',cfop):taxInput('CFOP','cfop',cfop)}${Number(p.is_service||0)===1?taxInputHidden('origem',t.origem):taxInput('Origem','origem',t.origem||'0')}
${Number(p.is_service||0)===1?taxInputHidden('icms_cst',t.icms_cst):taxInput('ICMS CST','icms_cst',t.icms_cst)}${Number(p.is_service||0)===1?taxInputHidden('icms_csosn',t.icms_csosn):taxInput('CSOSN','icms_csosn',t.icms_csosn)}${Number(p.is_service||0)===1?taxInputHidden('icms_rate',t.icms_rate):taxInput('ICMS %','icms_rate',t.icms_rate)}${Number(p.is_service||0)===1?taxInputHidden('icms_st_rate',t.icms_st_rate):taxInput('ICMS ST %','icms_st_rate',t.icms_st_rate)}
${Number(p.is_service||0)===1?taxInputHidden('ipi_cst',t.ipi_cst):taxInput('IPI CST','ipi_cst',t.ipi_cst)}${Number(p.is_service||0)===1?taxInputHidden('ipi_rate',t.ipi_rate):taxInput('IPI %','ipi_rate',t.ipi_rate)}${Number(p.is_service||0)===1?taxInputHidden('pis_cst',t.pis_cst):taxInput('PIS CST','pis_cst',t.pis_cst)}${Number(p.is_service||0)===1?taxInputHidden('pis_rate',t.pis_rate):taxInput('PIS %','pis_rate',t.pis_rate)}
${Number(p.is_service||0)===1?taxInputHidden('cofins_cst',t.cofins_cst):taxInput('COFINS CST','cofins_cst',t.cofins_cst)}${Number(p.is_service||0)===1?taxInputHidden('cofins_rate',t.cofins_rate):taxInput('COFINS %','cofins_rate',t.cofins_rate)}${Number(p.is_service||0)===1?taxInputHidden('ibs_cbs_cst',t.ibs_cbs_cst):taxInput('IBS/CBS CST','ibs_cbs_cst',t.ibs_cbs_cst)}${Number(p.is_service||0)===1?taxInputHidden('ibs_cbs_classification_code',t.ibs_cbs_classification_code):taxInput('cClassTrib','ibs_cbs_classification_code',t.ibs_cbs_classification_code)}
${Number(p.is_service||0)===1?taxInputHidden('cbs_rate',t.cbs_rate):taxInput('CBS %','cbs_rate',t.cbs_rate)}${Number(p.is_service||0)===1?taxInputHidden('ibs_uf_rate',t.ibs_uf_rate):taxInput('IBS UF %','ibs_uf_rate',t.ibs_uf_rate)}${Number(p.is_service||0)===1?taxInputHidden('ibs_mun_rate',t.ibs_mun_rate):taxInput('IBS Mun. %','ibs_mun_rate',t.ibs_mun_rate)}${Number(p.is_service||0)===1?taxInputHidden('tax_notes',t.fiscal_notes||''):taxInput('Obs. fiscal','tax_notes',t.fiscal_notes||'')}
</div></details>`;$('#itemsWrap').appendChild(row);row.querySelector('.pdh-remove').onclick=()=>{row.remove();recalc()};row.querySelectorAll('input[name="item_qty[]"],input[data-role="unit-price"],input[data-role="add-rate"],input[data-role="add-value"],input[data-role="disc-rate"],input[data-role="disc-value"]').forEach(i=>i.addEventListener('input',()=>lineCalc(row,i.dataset.role||'')));if(seed){row.querySelector('input[name="item_qty[]"]').value=Number(seed.quantity||1).toFixed(4).replace(/0+$/,'').replace(/\.$/,'').replace('.',',');row.querySelector('input[data-role="unit-price"]').value=Number(seed.unit_price||p.preco||0).toFixed(4).replace(/0+$/,'').replace(/\.$/,'').replace('.',',');row.querySelector('input[data-role="add-rate"]').value=Number(seed.addition_percent||0).toFixed(2).replace('.',',');row.querySelector('input[data-role="add-value"]').value=Number(seed.addition_amount||0).toFixed(2).replace('.',',');row.querySelector('input[data-role="disc-rate"]').value=Number(seed.discount_percent||0).toFixed(2).replace('.',',');row.querySelector('input[data-role="disc-value"]').value=Number(seed.discount_amount||0).toFixed(2).replace('.',',');row.querySelector('textarea[name="item_obs[]"]').value=String(seed.notes||'')}$('#productSearch').value='';$('#draftQty').value='1';$('#draftPrice').value=price;$('#draftTotal').value=price;$('#draftNet').value=price;lineCalc(row);if(!seed)focusProductEntry(70);return row}
function taxInput(label,name,value){return `<label>${label}<input name="tax_${name}[]" value="${esc(value??'')}"></label>`}
function taxInputHidden(name,value){return `<input type="hidden" name="tax_${name}[]" value="${esc(value??'')}">`}
function serviceTaxInfo(t){const f=(label,value)=>`<label>${label}<input readonly tabindex="-1" value="${esc(value??'')}"></label>`;return [
 f('Alíquota ISS %',t.iss_rate),f('ISS retido',Number(t.iss_withheld||0)?'Sim':'Não'),
 f('NBS',t.nbs_code),f('LC 116/03',t.lc116_service_code),
 f('Tributação nacional NFS-e',t.nfse_national_tax_code),f('Código municipal',t.municipal_tax_code),
 f('Indicador da operação',t.nfse_national_operation_indicator),f('CNAE',t.cnae),
 f('IBS/CBS CST',t.ibs_cbs_cst),f('cClassTrib',t.ibs_cbs_classification_code),
 f('CBS %',t.cbs_rate),f('IBS UF %',t.ibs_uf_rate),f('IBS Mun. %',t.ibs_mun_rate),
 f('PIS %',t.pis_rate),f('COFINS %',t.cofins_rate),
 f('IRRF %',t.irrf_rate),f('CSLL %',t.csll_rate),f('INSS %',t.inss_rate)
].join('')}
function lineCalc(row,changed=''){let q=num(row.querySelector('input[name="item_qty[]"]').value),u=num(row.querySelector('input[data-role="unit-price"]').value),base=q*u;let addRate=row.querySelector('input[data-role="add-rate"]'),addValue=row.querySelector('input[data-role="add-value"]'),discRate=row.querySelector('input[data-role="disc-rate"]'),discValue=row.querySelector('input[data-role="disc-value"]');if(changed==='add-rate'){addValue.value=(base*(num(addRate.value)/100)).toFixed(2).replace('.',',')}else if(changed==='add-value'){let pct=base>0?(num(addValue.value)/base*100):0;addRate.value=pct.toFixed(2).replace('.',',')}else if(changed==='disc-rate'){discValue.value=(base*(num(discRate.value)/100)).toFixed(2).replace('.',',')}else if(changed==='disc-value'){let pct=base>0?(num(discValue.value)/base*100):0;discRate.value=pct.toFixed(2).replace('.',',')}else{if(num(addRate.value)>0 && num(addValue.value)===0)addValue.value=(base*(num(addRate.value)/100)).toFixed(2).replace('.',',');if(num(discRate.value)>0 && num(discValue.value)===0)discValue.value=(base*(num(discRate.value)/100)).toFixed(2).replace('.',',')}
let add=num(addValue.value),disc=num(discValue.value),net=Math.max(0,base+add-disc);row.querySelector('.pdh-line-base').value=base.toFixed(2).replace('.',',');row.querySelector('.pdh-line-total').value=net.toFixed(2).replace('.',',');let submitUnit=q>0?(net/q):0;row.querySelector('input[data-role="submit-price"]').value=submitUnit.toFixed(2).replace('.',',');recalc()}
function recalc(){let sub=0;$$('.pdh-item').forEach(r=>sub+=num(r.querySelector('.pdh-line-total').value));let d=num($('#discount').value),a=num($('#addition').value),f=num($('#freight').value),tot=Math.max(0,sub-d+a+f);$('#sumProducts').textContent=money(sub);$('#sumDiscount').textContent=money(d);$('#sumAddition').textContent=money(a);$('#sumFreight').textContent=money(f);$('#sumTotal').textContent=money(tot);syncPayments(tot);updatePaymentSummary()}
['discount','addition','freight'].forEach(id=>$('#'+id).addEventListener('input',recalc));
function payOptions(){return '<option value="">Selecione pelo número</option>'+paymentMethods.map(p=>`<option value="${p.id}" data-payment-type="${esc(p.type||'')}">${p.key} - ${esc(p.name)}</option>`).join('')}
function paymentMethod(id){return paymentMethods.find(p=>String(p.id)===String(id))||{}}
function renderDetailedInstallments(row,force=false,stored=null){const sel=row.querySelector('select[name="payment_method_id[]"]'),p=paymentMethod(sel?.value),box=row.querySelector('.pdh-installments'),rows=box?.querySelector('.pdh-installment-rows');if(!box||!rows)return;if(Number(p.receivable||0)!==1){box.classList.remove('show');rows.innerHTML='';return}box.classList.add('show');const countInput=box.querySelector('.pdh-installment-count'),def=Math.max(1,Number(p.defaultInstallments||1));countInput.disabled=Number(p.allowInstallments||0)!==1;if(stored&&stored.length)countInput.value=String(stored.length);else if(!countInput.value||Number(countInput.value)<1)countInput.value=String(Number(p.allowInstallments||0)===1?def:1);const count=Number(p.allowInstallments||0)===1?Math.max(1,Math.min(120,Number(countInput.value||def))):1;if(!force&&!stored&&rows.children.length===count)return;const amount=num(row.querySelector('.pdh-pay-value')?.value||0),vals=splitInstallments(amount,count),fixed=Number(p.fixedDueDay||0),first=(Number(p.creRulesEnabled||0)===1&&Number(p.keepDueDay||0)===1&&fixed>=1)?isoNextFixedDay(todayIso,fixed):isoAddDays(todayIso,Number(p.firstDueDays||30)),day=fixed>=1?fixed:Number(first.slice(8,10)),period=Math.max(1,Number(p.periodDays||30)),key=row.querySelector('input[name="payment_line_key[]"]')?.value||'p0';rows.innerHTML='';for(let i=0;i<count;i++){const st=stored?.[i]||null,due=st?.due_date|| (i===0?first:(Number(p.keepDueDay||0)===1?isoAddMonthsKeepDay(first,i,day):isoAddDays(first,period*i))),val=st?Number(st.amount||0):vals[i],r=document.createElement('div');r.className='pdh-installment-row';r.innerHTML=`<span>${i+1}/${count}</span><input type="date" class="pdh-installment-due" name="installment_due[${key}][]" value="${esc(due)}" required><input class="pdh-installment-amount" name="installment_amount[${key}][]" value="${Number(val).toFixed(2).replace('.',',')}" inputmode="decimal" required>`;rows.appendChild(r)}}
function renderPaymentShortcuts(){let w=$('#paymentShortcuts');w.innerHTML='';paymentMethods.slice(0,9).forEach(p=>{let b=document.createElement('button');b.type='button';b.className='pdh-pay-chip';b.innerHTML=`<span class="key">${esc(p.key)}</span>${esc(p.name)}`;b.onclick=()=>selectPaymentByKey(p.key);w.appendChild(b)});let plus=document.createElement('button');plus.type='button';plus.className='pdh-pay-chip';plus.innerHTML='<span class="key">+</span>Adicionar';plus.onclick=()=>addPaymentRemaining();w.appendChild(plus);let minus=document.createElement('button');minus.type='button';minus.className='pdh-pay-chip';minus.innerHTML='<span class="key">-</span>Remover';minus.onclick=()=>removeActivePayment();w.appendChild(minus);let fin=document.createElement('button');fin.type='button';fin.className='pdh-pay-chip';fin.innerHTML='<span class="key">F10</span>Finalizar';fin.onclick=()=>$('#finalizeDetailedOrder').click();w.appendChild(fin)}
let paySeq=0,activePayRow=null;function orderTotal(){return num($('#sumTotal').textContent)}function paymentTotal(){return $$('.pdh-pay-value').reduce((s,e)=>s+num(e.value),0)}
function updatePaymentSummary(){let total=orderTotal(),inf=paymentTotal(),dif=total-inf;let c=$('#payCustomerName');if(c)c.textContent=$('#customerName').value||'Não selecionado';if($('#payOrderTotal'))$('#payOrderTotal').textContent=money(total);if($('#payGrandTotal'))$('#payGrandTotal').textContent=money(total);if($('#payInformed'))$('#payInformed').textContent=money(inf);if($('#payDifference')){$('#payDifference').textContent=money(dif);$('#payDifference').classList.toggle('ok',Math.abs(dif)<0.005);$('#payDifference').classList.toggle('bad',Math.abs(dif)>=0.005)}}
function markActivePayment(r){activePayRow=r;$$('.pdh-pay-row').forEach(x=>x.style.boxShadow=x===r?'0 0 0 2px rgba(37,99,235,.12)':'none')}
function addPay(amount=0,methodId='',stored=null){paySeq++;let key='p'+paySeq,r=document.createElement('div');r.className='pdh-pay-row';r.innerHTML=`<label>Forma<select name="payment_method_id[]" class="pay-method" required>${payOptions()}</select></label><label>Valor<input name="payment_amount[]" class="pdh-pay-value pay-amount" value="${Number(amount).toFixed(2).replace('.',',')}" inputmode="decimal"><input type="hidden" name="payment_received[]" value="${Number(amount).toFixed(2).replace('.',',')}"><input type="hidden" name="payment_line_key[]" class="pay-line-key" value="${key}"></label><button type="button" class="btn pdh-pay-remove" title="Remover">×</button><div class="pdh-installments"><div class="pdh-installments-head"><strong>Parcelas / vencimentos</strong><label>Parcelas<input type="number" min="1" max="120" class="pdh-installment-count" value="1"></label></div><div class="pdh-installment-rows"></div></div>`;$('#paymentsWrap').appendChild(r);let sel=r.querySelector('select'),val=r.querySelector('.pdh-pay-value'),received=r.querySelector('input[name="payment_received[]"]');if(methodId)sel.value=String(methodId);[sel,val].forEach(el=>el.addEventListener('focus',()=>markActivePayment(r)));sel.addEventListener('change',()=>{markActivePayment(r);renderDetailedInstallments(r,true);val.focus();val.select()});val.addEventListener('input',()=>{received.value=val.value;val.dataset.touched='1';renderDetailedInstallments(r,true);updatePaymentSummary()});r.querySelector('.pdh-installment-count').addEventListener('input',()=>renderDetailedInstallments(r,true));val.addEventListener('keydown',e=>{if(e.key==='+'){e.preventDefault();addPaymentRemaining()}else if(e.key==='-'){e.preventDefault();removeActivePayment()}else if(e.key==='Enter'){e.preventDefault();addPaymentRemaining()}});r.querySelector('.pdh-pay-remove').onclick=()=>{markActivePayment(r);removeActivePayment()};renderDetailedInstallments(r,true,stored);markActivePayment(r);updatePaymentSummary();return r}
function focusPaymentSelector(){let r=activePayRow&&document.body.contains(activePayRow)?activePayRow:$('.pdh-pay-row');if(!r)r=addPay(Math.max(0,orderTotal()-paymentTotal()));markActivePayment(r);let s=r.querySelector('select');if(s)setTimeout(()=>s.focus(),20)}
function selectPaymentByKey(key){let p=paymentMethods.find(x=>String(x.key)===String(key));if(!p)return;let r=activePayRow&&document.body.contains(activePayRow)?activePayRow:$('.pdh-pay-row:last-child');if(!r)r=addPay(Math.max(0,orderTotal()-paymentTotal()));let sel=r.querySelector('select');sel.value=String(p.id);sel.dispatchEvent(new Event('change',{bubbles:true}));markActivePayment(r);let v=r.querySelector('.pdh-pay-value');v.focus();v.select()}
function addPaymentRemaining(){let remaining=Math.max(0,orderTotal()-paymentTotal()),r=addPay(remaining);r.querySelector('select').focus()}
function removeActivePayment(){let rows=$$('.pdh-pay-row');if(!rows.length)return;let r=activePayRow&&document.body.contains(activePayRow)?activePayRow:rows[rows.length-1];if(rows.length===1){r.querySelector('select').value='';let v=r.querySelector('.pdh-pay-value');v.value=orderTotal().toFixed(2).replace('.',',');r.querySelector('input[name="payment_received[]"]').value=v.value;v.dataset.touched='';renderDetailedInstallments(r,true);markActivePayment(r)}else{let prev=r.previousElementSibling||r.nextElementSibling;r.remove();activePayRow=prev&&prev.classList.contains('pdh-pay-row')?prev:null}updatePaymentSummary();focusPaymentSelector()}
function syncPayments(total){let rows=$$('.pdh-pay-row');if(!rows.length){addPay(total);return}if(rows.length===1){let v=rows[0].querySelector('.pdh-pay-value');if(!v.dataset.touched){v.value=total.toFixed(2).replace('.',',');rows[0].querySelector('input[name="payment_received[]"]').value=v.value;renderDetailedInstallments(rows[0],true)}}updatePaymentSummary()}
$('#addPayment').onclick=()=>addPaymentRemaining();
let personMode='customer';function openPerson(mode){if(mode==='seller'&&!allowSellerChange)return;personMode=mode;const titles={carrier:'Buscar transportadora',seller:'Buscar vendedor',professional:'Buscar profissional',customer:'Buscar cliente'};$('#personModalTitle').textContent=titles[mode]||'Buscar';$('#personModal').classList.add('open');$('#personQuery').value='';$('#personResults').innerHTML='';setTimeout(()=>$('#personQuery').focus(),50)}function closePerson(){$('#personModal').classList.remove('open')}
$('#btnCustomer').onclick=()=>openPerson('customer');$('#btnCarrier').onclick=()=>openPerson('carrier');if($('#btnSeller'))$('#btnSeller').onclick=()=>openPerson('seller');if($('#btnProfessional'))$('#btnProfessional').onclick=()=>openPerson('professional');$('#personClose').onclick=closePerson;$('#personModal').addEventListener('click',e=>{if(e.target===$('#personModal'))closePerson()});
let personActive=-1;
function personFocus(index){let rows=$$('.pdh-person');if(!rows.length)return;personActive=Math.max(0,Math.min(index,rows.length-1));rows.forEach((r,i)=>{r.classList.toggle('pdh-active',i===personActive);r.style.outline=i===personActive?'2px solid #2563eb':''});rows[personActive].focus();rows[personActive].scrollIntoView({block:'nearest'})}
async function personSearch(){let q=$('#personQuery').value.trim(),map={carrier:'carriers',seller:'sellers',professional:'professionals',customer:'customers'},a=map[personMode]||'customers',d=await getJSON(detailedAjaxUrl('ajax='+a+'&q='+encodeURIComponent(q)));let w=$('#personResults');w.innerHTML='';personActive=-1;(d.rows||[]).forEach((p,idx)=>{let e=document.createElement('div');e.className='pdh-person';e.tabIndex=0;e.dataset.index=idx;e.innerHTML='<strong>'+esc(p.name)+'</strong><div class="mg-muted">'+esc(p.document||'')+' · '+esc(p.city||'')+'/'+esc(p.state||'')+'</div>';e.onclick=()=>selectPerson(p);e.onkeydown=ev=>{if(ev.key==='ArrowDown'){ev.preventDefault();personFocus(idx+1)}else if(ev.key==='ArrowUp'){ev.preventDefault();if(idx===0){$('#personQuery').focus();personActive=-1}else personFocus(idx-1)}else if(ev.key==='Enter'){ev.preventDefault();selectPerson(p)}else if(ev.key.length===1&&!ev.ctrlKey&&!ev.altKey&&!ev.metaKey){$('#personQuery').focus();$('#personQuery').value=ev.key;personActive=-1}};w.appendChild(e)})}
$('#personQuery').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();personSearch()}else if(e.key==='ArrowDown'){e.preventDefault();personFocus(0)}});
function selectPerson(p){if(personMode==='carrier'){$('#carrierId').value=p.id;$('#carrierName').value=p.name||'';$('#carrierDoc').value=p.document||'';$('#carrierIe').value=p.state_registration||'';$('#carrierAddress').value=[p.address,p.number,p.complement].filter(Boolean).join(', ');$('#carrierCity').value=p.city||'';$('#carrierState').value=p.state||''}else if(personMode==='seller'){$('#sellerId').value=p.id;$('#sellerText').value=p.name||''}else if(personMode==='professional'){$('#professionalId').value=p.id;$('#professionalText').value=p.name||''}else{$('#customerId').value=p.id;$('#customerName').value=p.name||'';$('#customerWhatsapp').value=p.whatsapp||p.phone||'';$('#customerZip').value=p.zip_code||'';$('#customerAddress').value=p.address||'';$('#customerNumber').value=p.number||'';$('#customerComplement').value=p.complement||'';$('#customerDistrict').value=p.district||'';$('#customerCity').value=p.city||'';$('#customerState').value=p.state||'';$('#customerText').value=p.name||'';$('#customerSelected').innerHTML='<strong>'+esc(p.name)+'</strong><div class="mg-muted">'+esc(p.document||'')+' · '+esc(p.address||'')+', '+esc(p.number||'')+' · '+esc(p.city||'')+'/'+esc(p.state||'')+'</div>';$('#customerSelected').classList.add('show');if($('#payCustomerName'))$('#payCustomerName').textContent=p.name||'Não selecionado'}closePerson()}
let cfopActive=-1;function openCfop(){cfopActive=-1;$('#cfopModal').classList.add('open');$('#cfopQuery').value='';$('#cfopResults').innerHTML='';setTimeout(()=>$('#cfopQuery').focus(),50)}function closeCfop(){$('#cfopModal').classList.remove('open');cfopActive=-1}
if($('#btnCfop'))$('#btnCfop').onclick=openCfop;$('#cfopClose').onclick=closeCfop;$('#cfopModal').addEventListener('click',e=>{if(e.target===$('#cfopModal'))closeCfop()});
function cfopFocus(index){let rows=$$('.pdh-cfop');if(!rows.length)return;cfopActive=Math.max(0,Math.min(index,rows.length-1));rows.forEach((r,i)=>{r.classList.toggle('pdh-active',i===cfopActive);r.style.outline=i===cfopActive?'2px solid #2563eb':''});rows[cfopActive].focus();rows[cfopActive].scrollIntoView({block:'nearest'})}
async function cfopSearch(){let q=$('#cfopQuery').value.trim(),d=await getJSON(detailedAjaxUrl('ajax=cfops&q='+encodeURIComponent(q))),w=$('#cfopResults');w.innerHTML='';cfopActive=-1;(d.rows||[]).forEach((r,idx)=>{let e=document.createElement('div');e.className='pdh-cfop';e.tabIndex=0;e.innerHTML='<strong>'+esc(r.code||'')+' - '+esc(r.description||'')+'</strong><div class="mg-muted">'+esc(r.operation_type||'')+' / '+esc(r.direction||'')+'</div>';e.onclick=()=>selectCfop(r);e.onkeydown=ev=>{if(ev.key==='ArrowDown'){ev.preventDefault();cfopFocus(idx+1)}else if(ev.key==='ArrowUp'){ev.preventDefault();if(idx===0){$('#cfopQuery').focus();cfopActive=-1}else cfopFocus(idx-1)}else if(ev.key==='Enter'){ev.preventDefault();selectCfop(r)}else if(ev.key.length===1&&!ev.ctrlKey&&!ev.altKey&&!ev.metaKey){$('#cfopQuery').focus();$('#cfopQuery').value=ev.key;cfopActive=-1}};w.appendChild(e)});if(!(d.rows||[]).length)w.innerHTML='<div class="mg-muted" style="padding:10px">Nenhum CFOP encontrado.</div>'}
function selectCfop(r){$('#defaultCfop').value=String(r.code||'').replace(/\D/g,'').slice(0,4);closeCfop();$('#defaultCfop').focus()}
$('#cfopQuery').addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();cfopSearch()}else if(e.key==='ArrowDown'){e.preventDefault();cfopFocus(0)}});
function detailedFiscalOpen(){return !!($('#detailedFiscalModal')&&$('#detailedFiscalModal').classList.contains('open'))}
function closeDetailedFiscal(){if($('#detailedFiscalModal'))$('#detailedFiscalModal').classList.remove('open')}
function finishDetailedFiscal(choice){
  if(choice==='nfse'){
    const orderNote=String(document.querySelector('#pdhForm textarea[name="notes"]')?.value||'').trim().replace(/\s+/g,' ');
    const itemNotes=$$('.pdh-item textarea[name="item_obs[]"]').map(e=>String(e.value||'').trim().replace(/\s+/g,' '));
    if(orderNote||itemNotes.some(Boolean)){
      const serviceLines=$$('.pdh-item').filter(r=>Number(r.dataset.isService||0)===1).map(r=>{
        const qty=num(r.querySelector('input[name="item_qty[]"]')?.value||0);
        const qtyText=qty.toFixed(4).replace(/0+$/,'').replace(/\.$/,'');
        const name=String(r.querySelector('.pdh-item-title strong')?.textContent||'').trim().slice(0,255);
        const note=String(r.querySelector('textarea[name="item_obs[]"]')?.value||'').trim().replace(/\s+/g,' ');
        return qtyText+' x '+name+(note?' — Obs. do item: '+note:'');
      });
      const text=serviceLines.join(' | ')+(orderNote?' | Observação do pedido: '+orderNote:'');
      if(Array.from(text).length>1000){
        alert('O texto de serviços e observações ultrapassa 1000 caracteres permitidos na NFS-e. Reduza as observações antes de finalizar.');
        closeDetailedFiscal();go(2);return;
      }
    }
  }
  $('#emitDocAfter').value=['nfce','nfe','nfse'].includes(choice)?choice:'none';detailedFiscalConfirmed=true;closeDetailedFiscal();let f=$('#pdhForm');if(f.requestSubmit)f.requestSubmit();else f.submit()}
$$('[data-detailed-fiscal]').forEach(b=>b.addEventListener('click',()=>finishDetailedFiscal(b.dataset.detailedFiscal||'none')));
if($('#detailedFiscalClose'))$('#detailedFiscalClose').onclick=closeDetailedFiscal;
if($('#detailedFiscalModal'))$('#detailedFiscalModal').addEventListener('click',e=>{if(e.target===$('#detailedFiscalModal'))closeDetailedFiscal()});
document.addEventListener('keydown',e=>{if(detailedFiscalOpen()){e.stopImmediatePropagation();if(e.key==='1'&&$('[data-detailed-fiscal="nfce"]')){e.preventDefault();finishDetailedFiscal('nfce');return}if(e.key==='2'&&$('[data-detailed-fiscal="nfe"]')){e.preventDefault();finishDetailedFiscal('nfe');return}if(e.key==='3'&&$('[data-detailed-fiscal="nfse"]')){e.preventDefault();finishDetailedFiscal('nfse');return}if(e.key==='4'&&$('[data-detailed-fiscal="none"]')){e.preventDefault();finishDetailedFiscal('none');return}if(e.key==='Escape'){e.preventDefault();closeDetailedFiscal();return}return}});
document.addEventListener('keydown',e=>{let active=$('.pdh-pane.active')?.dataset.pane||'1';if(e.key==='F8'){e.preventDefault();const role=document.activeElement?.dataset?.f8Role||'';if(role==='seller')openPerson('seller');else if(role==='professional')openPerson('professional');else if(role==='cfop')openCfop();else if(role==='carrier')openPerson('carrier');else if(active==='2')openProductModal($('#productSearch').value.trim());else openPerson('customer');return}if(e.key==='Escape'){if($('#pdhImagePreview')?.classList.contains('open')){e.preventDefault();closeProductImagePreview();return}if($('#productModal').classList.contains('open')){e.preventDefault();closeProductModal();return}if($('#personModal').classList.contains('open')){e.preventDefault();closePerson();return}if($('#cfopModal').classList.contains('open')){e.preventDefault();closeCfop();return}}if(active==='4'){if(e.key==='F10'){e.preventDefault();$('#finalizeDetailedOrder').click();return}let tag=(document.activeElement?.tagName||'').toLowerCase(),cls=document.activeElement?.classList;if(/^[1-9]$/.test(e.key) && tag!=='input'){e.preventDefault();selectPaymentByKey(e.key);return}if(e.key==='+' && !(cls&&cls.contains('pdh-pay-value'))){e.preventDefault();addPaymentRemaining();return}if(e.key==='-' && !(cls&&cls.contains('pdh-pay-value'))){e.preventDefault();removeActivePayment();return}}});
$('#pdhForm').addEventListener('submit',e=>{if(!$('#customerId').value){e.preventDefault();alert('Selecione o cliente.');go(1);return}if(!$$('.pdh-item').length){e.preventDefault();alert('Inclua pelo menos um produto.');go(2);return}recalc();let rows=$$('.pdh-pay-row'),invalid=rows.some(r=>!r.querySelector('select').value||num(r.querySelector('.pdh-pay-value').value)<=0),installmentInvalid=false;rows.forEach(r=>{if(r.querySelector('.pdh-installments.show')){let sum=0,missing=false;r.querySelectorAll('.pdh-installment-row').forEach(x=>{if(!x.querySelector('.pdh-installment-due')?.value)missing=true;sum+=num(x.querySelector('.pdh-installment-amount')?.value||0)});if(missing||Math.abs(sum-num(r.querySelector('.pdh-pay-value').value))>.009)installmentInvalid=true}});if(invalid){e.preventDefault();alert('Informe corretamente as formas de pagamento.');go(4);return}if(installmentInvalid){e.preventDefault();alert('Confira vencimentos e valores das parcelas. A soma das parcelas deve ser igual ao valor da forma de pagamento.');go(4);return}if(Math.abs(orderTotal()-paymentTotal())>0.009){e.preventDefault();alert('O total informado nas formas de pagamento deve ser igual ao total do pedido.');go(4);return}if(!window.NexaDetailedDeliveryConfirmed&&Array.isArray(window.NexaDetailedDeliveryFuture)&&window.NexaDetailedDeliveryFuture.length){e.preventDefault();const lines=window.NexaDetailedDeliveryFuture.map(it=>{const f=Number(it.future_quantity||0),q=Number(it.quantity||0);return '• '+String(it.description_snapshot||'Item')+' — agora '+Math.max(0,q-f).toLocaleString('pt-BR',{maximumFractionDigits:4})+' / futuro '+f.toLocaleString('pt-BR',{maximumFractionDigits:4})});if(confirm('CONFIRMAÇÃO DE ENTREGA\n\n'+lines.join('\n')+'\n\nO saldo futuro permanecerá reservado e será baixado somente ao gerar a Ordem de Entrega.')){window.NexaDetailedDeliveryConfirmed=true;$('#pdhForm').requestSubmit();}return}if(detailedFiscalEnabled&&!detailedFiscalConfirmed){e.preventDefault();$('#detailedFiscalModal').classList.add('open');return}});
window.NexaOrcamentoApply=async function(data){const q=data?.quote||{},items=Array.isArray(data?.items)?data.items:[],pays=Array.isArray(data?.payments)?data.payments:[],customer=data?.customer||{};window.NexaDetailedDeliveryFuture=items.filter(it=>Number(it.future_quantity||0)>0.00001);window.NexaDetailedDeliveryConfirmed=false;if(!q.id)throw new Error('Orçamento inválido.');if(items.some(it=>!Number(it.current_product_id||0)||Number(it.current_active||0)!==1)){alert('Existem produtos removidos ou inativos no orçamento. Regularize-os antes de carregar o pedido.');return false}if($$('.pdh-item').length&&!confirm('Os itens atuais serão substituídos pelo orçamento #'+(q.quote_number||q.id)+'. Deseja continuar?'))return false;$('#orcamentoId').value=q.id;$('#itemsWrap').innerHTML='';itemSeq=0;if(Number(customer.id||0)>0){selectPerson({id:Number(customer.id),name:String(customer.name||''),document:String(customer.document||''),phone:String(customer.phone||''),whatsapp:String(customer.whatsapp||''),zip_code:String(customer.zip_code||''),address:String(customer.address||''),number:String(customer.number||''),complement:String(customer.complement||''),district:String(customer.district||''),city:String(customer.city||''),state:String(customer.state||'')})}else{$('#customerId').value='';$('#customerName').value=String(customer.name||q.person_name_snapshot||'');$('#customerWhatsapp').value=String(customer.whatsapp||customer.phone||q.person_phone_snapshot||'');$('#customerText').value=String(customer.name||q.person_name_snapshot||'Cliente do orçamento');$('#customerSelected').innerHTML='<strong>'+esc($('#customerText').value)+'</strong><div class="mg-muted">Cliente não vinculado ao cadastro. Selecione/cadastre a pessoa antes de finalizar.</div>';$('#customerSelected').classList.add('show');$('#payCustomerName').textContent=$('#customerText').value}
if(q.delivery_address){$('#customerAddress').value=q.delivery_address||'';$('#customerZip').value=q.delivery_zip_code||'';$('#customerNumber').value=q.delivery_number||'';$('#customerComplement').value=q.delivery_complement||'';$('#customerDistrict').value=q.delivery_district||'';$('#customerCity').value=q.delivery_city||'';$('#customerState').value=q.delivery_state||''}
if(allowSellerChange&&Number(q.seller_person_id||0)>0){$('#sellerId').value=String(Number(q.seller_person_id));$('#sellerText').value=String(q.seller_name_snapshot||'')}if(Number(q.professional_person_id||0)>0){$('#professionalId').value=String(Number(q.professional_person_id));$('#professionalText').value=String(q.professional_name_snapshot||'')}const notes=document.querySelector('#pdhForm textarea[name="notes"]');if(notes)notes.value=[String(q.notes||'').trim(),'Origem: Orçamento #'+(q.quote_number||q.id)].filter(Boolean).join('\n');for(const it of items)await addProduct(Number(it.current_product_id||it.produto_id),it);$('#discount').value=Number(q.discount_amount||0).toFixed(2).replace('.',',');$('#addition').value=Number(q.addition_amount||0).toFixed(2).replace('.',',');$('#freight').value=Number(q.freight_amount||0).toFixed(2).replace('.',',');recalc();$('#paymentsWrap').innerHTML='';activePayRow=null;if(pays.length){const unresolved=pays.filter(p=>!Number(p.resolved_payment_method_id||p.payment_method_id||0)||!paymentMethod(p.resolved_payment_method_id||p.payment_method_id).id);if(unresolved.length){const labels=[...new Set(unresolved.map(p=>String(p.payment_label||p.current_method_name||'Forma de pagamento').trim()).filter(Boolean))];alert('Não foi possível relacionar a(s) forma(s) de pagamento do orçamento com as formas ativas desta empresa: '+labels.join(', ')+'. Regularize a forma de pagamento antes de continuar.');$('#paymentsWrap').innerHTML='';addPay(orderTotal());go(4);return false}const used=new Set();pays.forEach((p,idx)=>{if(used.has(idx))return;const resolvedId=String(p.resolved_payment_method_id||p.payment_method_id||''),method=paymentMethod(resolvedId),mid=resolvedId;let group=[p];used.add(idx);if(Number(method.receivable||0)===1){group=[];pays.forEach((x,j)=>{const xid=String(x.resolved_payment_method_id||x.payment_method_id||'');if(!used.has(j)&&xid===mid){group.push(x);used.add(j)}});if(!group.length)group=[p]}const amount=group.reduce((a,x)=>a+Number(x.amount||0),0),row=addPay(amount,mid,Number(method.receivable||0)===1?group:null);const sel=row.querySelector('select[name="payment_method_id[]"]');if(!sel||String(sel.value)!==mid)throw new Error('A forma de pagamento '+String(p.payment_label||p.current_method_name||mid)+' não pôde ser selecionada no Pedido Detalhado.');row.querySelector('.pdh-pay-value').dataset.touched='1'})}else addPay(orderTotal());recalc();detailedFiscalConfirmed=!detailedFiscalEnabled;go(2);setTimeout(()=>$('#productSearch')?.focus(),80);return true};
renderPaymentShortcuts();addPay(0);recalc();
})();
</script>
<?php
$pixSalesWidget=[
  'form_id'=>'pdhForm',
  'line_selector'=>'.pdh-pay-row',
  'method_selector'=>'.pay-method',
  'amount_selector'=>'.pdh-pay-value',
  'payments'=>$payments,
  'self_url'=>$detailedContextUrl('gestao_pedido_detalhado.php'),
  'mc_csrf'=>$detailedMcCsrf,
  'mc_ctx'=>$detailedMcCtx,
  'label'=>'Pedido Detalhado · Pagamento Pix',
];
require __DIR__ . '/_pix_sales_payment.php';
require __DIR__ . '/_card_installment_picker.php';
?>
<?php $voucherPaymentScope = 'detailed_order'; require __DIR__ . '/_voucher_payment_selector.php'; ?>
<?php if ($orcamentoReadEnabled) require __DIR__ . '/_orcamento_pedido_reader.php'; ?>
<?php gestao_admin_end(); ?>
