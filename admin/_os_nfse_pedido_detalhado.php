<?php
/**
 * Conferência fiscal de pedido JÁ faturado pela O.S. (individual ou lote).
 * Incluído pela página gestao_pedido_detalhado.php, sem gerar outro pedido.
 */
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/../config/os_batch_billing.php';
require_once __DIR__ . '/../config/nfse_provider_router.php';
require_once __DIR__ . '/../config/detailed_order_fiscal_notes.php';

if ($detailedCrossContext) { http_response_code(403); exit('Conferência fiscal da O.S. não habilitada pelo F11.'); }
if ($detailedSaleFinalizationBlocked) { http_response_code(403); exit('Seu perfil não pode finalizar vendas e emitir NFS-e.'); }
os_require_enabled($pdo, $companyId);

$osReviewId = max(0, (int)($_GET['os_nfse'] ?? $_POST['os_nfse'] ?? 0));
$osReviewInvoiceId = max(0, (int)($_GET['os_nfse_lote'] ?? $_POST['os_nfse_lote'] ?? 0));
$osReviewBack = $osReviewInvoiceId ? 'os_faturas.php?id='.$osReviewInvoiceId : 'os_faturar.php?id='.$osReviewId;
$reviewError = ''; $reviewSuccess = trim((string)($_GET['ok'] ?? ''));
$reviewOrder = []; $reviewOs = null; $reviewInvoice = null; $reviewVehicles = [];
$reviewPeriod = ''; $reviewItems = []; $reviewFiscalIssues=[];
$reviewNoteCol = function_exists('gestao_pedido_obs_column') ? gestao_pedido_obs_column($pdo) : '';
$reviewItemCol = function_exists('gestao_pedido_item_obs_column') ? gestao_pedido_item_obs_column($pdo) : '';

// Mantém o valor histórico de placa/modelo na O.S., sem alterar o cadastro do veículo.
$reviewVehicle = static function(int $orderId) use ($pdo, $companyId): string {
  $s=$pdo->prepare("SELECT cf.semantic_role, fv.value_text FROM os_order_field_values fv INNER JOIN os_custom_fields cf ON cf.company_id=fv.company_id AND cf.id=fv.field_id WHERE fv.company_id=? AND fv.order_id=? AND cf.semantic_role IN ('vehicle_plate','vehicle_model')");
  $s->execute([$companyId,$orderId]); $roles=[];
  foreach(($s->fetchAll(PDO::FETCH_ASSOC)?:[]) as $r) $roles[(string)$r['semantic_role']]=trim((string)$r['value_text']);
  $plate=strtoupper(trim((string)($roles['vehicle_plate']??''))); $model=trim((string)($roles['vehicle_model']??''));
  return trim(($plate!==''?'Placa: '.$plate:'').($model!==''?($plate!==''?' — ':'').'Modelo: '.$model:''));
};

try {
  if ($osReviewId>0 && $osReviewInvoiceId>0) throw new RuntimeException('Selecione somente uma origem para conferência.');
  if ($osReviewId>0) {
    $s=$pdo->prepare('SELECT * FROM os_orders WHERE company_id=? AND id=? LIMIT 1'); $s->execute([$companyId,$osReviewId]);
    $reviewOs=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$reviewOs || strtoupper((string)$reviewOs['billing_status'])!=='FATURADA' || (int)$reviewOs['pedido_id']<=0) throw new RuntimeException('Fature a O.S. antes de conferir a NFS-e.');
    if (empty($reviewOs['requires_service_fiscal'])) throw new RuntimeException('A O.S. não possui serviço para NFS-e.');
    $linkedInvoice=(int)($reviewOs['batch_invoice_id']??0);
    if ($linkedInvoice>0) throw new RuntimeException('Esta O.S. pertence a faturamento consolidado. Abra a fatura correspondente.');
    $reviewPedidoId=(int)$reviewOs['pedido_id'];
    $vehicleLabel=$reviewVehicle($osReviewId); if($vehicleLabel!=='')$reviewVehicles[]=$vehicleLabel;
    $dateStart=substr((string)($reviewOs['opened_at']??''),0,10);$dateEnd=substr((string)($reviewOs['closed_at']?:($reviewOs['opened_at']??'')),0,10);
    if($dateStart!==''&&$dateEnd!=='')$reviewPeriod=date('d/m/Y',strtotime($dateStart)).' a '.date('d/m/Y',strtotime($dateEnd));
  } else {
    $s=$pdo->prepare('SELECT * FROM os_batch_invoices WHERE company_id=? AND id=? LIMIT 1');$s->execute([$companyId,$osReviewInvoiceId]);
    $reviewInvoice=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if (!$reviewInvoice || strtoupper((string)$reviewInvoice['status'])!=='FATURADA' || (int)$reviewInvoice['pedido_id']<=0) throw new RuntimeException('A fatura deve estar faturada antes de emitir NFS-e.');
    $reviewPedidoId=(int)$reviewInvoice['pedido_id'];
    $s=$pdo->prepare('SELECT io.os_order_id,io.service_date_snapshot,o.requires_service_fiscal FROM os_batch_invoice_orders io INNER JOIN os_orders o ON o.company_id=io.company_id AND o.id=io.os_order_id WHERE io.company_id=? AND io.invoice_id=? ORDER BY io.service_date_snapshot,io.os_order_id');
    $s->execute([$companyId,$osReviewInvoiceId]);$batchOsRows=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    if(!array_filter($batchOsRows,static fn($r)=>!empty($r['requires_service_fiscal']))) throw new RuntimeException('Fatura sem serviços para NFS-e.');
    $dates=[];foreach($batchOsRows as $bo){$d=substr((string)$bo['service_date_snapshot'],0,10);if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$d))$dates[]=$d;$vl=$reviewVehicle((int)$bo['os_order_id']);if($vl!=='')$reviewVehicles[$vl]=$vl;}
    $reviewVehicles=array_values($reviewVehicles);
    if($dates){sort($dates);$reviewPeriod=date('d/m/Y',strtotime($dates[0])).' a '.date('d/m/Y',strtotime(end($dates)));}
  }
  $s=$pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');$s->execute([$companyId,$reviewPedidoId]);$reviewOrder=$s->fetch(PDO::FETCH_ASSOC)?:[];
  if (!$reviewOrder || strtolower(trim((string)($reviewOrder['source']??'')))!==($osReviewId>0?'ordem_servico':'ordem_servico_lote')) throw new RuntimeException('Pedido não confere com a origem fiscal desta O.S./fatura.');
  if (strtolower(trim((string)($reviewOrder['status']??'')))==='cancelado') throw new RuntimeException('Pedido cancelado. Refature a O.S. para gerar um novo pedido.');
  if (!$reviewNoteCol || !$reviewItemCol) throw new RuntimeException('O banco não possui as colunas de observação dos pedidos. Atualize a estrutura antes de usar a conferência.');
  $s=$pdo->prepare("SELECT pi.*,p.nome AS nome, COALESCE(p.is_service,0) AS is_service, fpt.iss_rate, fpt.iss_withheld, fpt.nbs_code, fpt.nfse_national_tax_code, fpt.municipal_tax_code FROM pedido_itens pi INNER JOIN produtos p ON p.company_id=pi.company_id AND p.id=pi.produto_id LEFT JOIN fiscal_product_tax fpt ON fpt.company_id=pi.company_id AND fpt.product_id=pi.produto_id WHERE pi.company_id=? AND pi.pedido_id=? ORDER BY pi.id");
  $s->execute([$companyId,$reviewPedidoId]);$reviewItems=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
  $serviceItems=array_values(array_filter($reviewItems,static fn($r)=>(int)($r['is_service']??0)===1));
  if(!$serviceItems)throw new RuntimeException('Pedido sem itens de serviço para emissão.');
  $baseObs=trim((string)($reviewOrder[$reviewNoteCol]??''));
  $suggestion=($reviewPeriod!==''?'Serviço - Período: '.$reviewPeriod:'');
  if($reviewVehicles)$suggestion.=($suggestion!==''?' | ':'').'Placas e Modelos: '.implode('; ',$reviewVehicles);
  if ($osReviewInvoiceId && $suggestion!=='' && !str_contains($baseObs,'Serviço - Período:')) $baseObs.=($baseObs!==''?' | ':'').$suggestion;
  $noteValue=trim(preg_replace('/\[NEXA-OS-(?:BILL|BATCH):\d+:\d+\]/','',$baseObs) ?? $baseObs);
  if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['os_review_action']??'')!=='') {
    os_fiscal_csrf_check((string)($_POST['csrf']??''));
    $reviewAction=(string)($_POST['os_review_action']??'');
    if(!in_array($reviewAction,['save','issue'],true))throw new RuntimeException('Ação inválida.');
    $noteValue=trim((string)($_POST['observacao_geral']??''));
    $submitted=(array)($_POST['observacao_item']??[]);
    // Não permite remover o identificador interno de conciliação da O.S.
    // Identificadores internos ficam no banco, mas não são exibidos ao usuário ou enviados ao XML.
    $token=$osReviewId>0 ? os_billing_token($companyId,$osReviewId) : '[NEXA-OS-BATCH:'.$companyId.':'.$osReviewInvoiceId.']';
    if(!str_contains($noteValue,$token))$noteValue=trim($token.' '.$noteValue);
    $nextLines=[];$descLines=[];
    foreach($reviewItems as $r){if((int)$r['is_service']!==1)continue;$itemId=(int)$r['id'];$txt=detailed_order_fiscal_normalize_note($submitted[$itemId]??'');$len=function_exists('mb_strlen')?mb_strlen($txt,'UTF-8'):strlen($txt);if($len>700)throw new RuntimeException('A observação do item '.$itemId.' ultrapassa 700 caracteres.');$nextLines[$itemId]=$txt;
      $qty=(float)$r['qtd'];$ql=rtrim(rtrim(number_format($qty,4,'.',''),'0'),'.');$descLines[]=$ql.' x '.(string)$r['nome'].($txt!==''?' — Obs. do item: '.$txt:'');}
    $fiscalNote=preg_replace('/\[NEXA-OS-(?:BILL|BATCH):\d+:\d+\]/','',$noteValue) ?? $noteValue;
    $fiscalNote=trim($fiscalNote);
    $nfseDescription=implode(' | ',$descLines).($fiscalNote!==''?' | Observação do pedido: '.$fiscalNote:'');
    $len=function_exists('mb_strlen')?mb_strlen($nfseDescription,'UTF-8'):strlen($nfseDescription);
    if($len>1000)throw new RuntimeException('A descrição fiscal completa possui '.$len.' caracteres (máximo 1000). Reduza as observações antes de emitir.');
    // Nenhuma alteração financeira, de preço, estoque ou de impostos nesta etapa.
    $pdo->beginTransaction();
    try {
      $s=$pdo->prepare('SELECT status FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');$s->execute([$companyId,$reviewPedidoId]);$currentStatus=strtolower((string)$s->fetchColumn());
      if($currentStatus==='cancelado')throw new RuntimeException('Pedido cancelado durante a conferência.');
      $s=$pdo->prepare("UPDATE pedidos SET `".str_replace('`','``',$reviewNoteCol)."`=? WHERE company_id=? AND id=?");$s->execute([$noteValue,$companyId,$reviewPedidoId]);
      $s=$pdo->prepare("UPDATE pedido_itens SET `".str_replace('`','``',$reviewItemCol)."`=? WHERE company_id=? AND pedido_id=? AND id=?");
      foreach($nextLines as $id=>$value)$s->execute([$value,$companyId,$reviewPedidoId,$id]);
      $pdo->commit();
    } catch(Throwable $e) {if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    if($reviewAction==='issue'){
      $checkExisting=nfse_emitter_existing_document($pdo,$companyId,$reviewPedidoId);
      if($checkExisting && !in_array(strtoupper((string)($checkExisting['status']??'')),['REJEITADA','CANCELADA'],true))
        throw new RuntimeException('Já existe NFS-e ou DPS em andamento para este pedido. Consulte antes de tentar emitir.');
      if($osReviewId>0){
        $queued=os_fiscal_queue_enqueue($pdo,$companyId,$osReviewId,'NFSE',$userId);
        if((int)($queued['queue_id']??0)>0){header('Location: os_fiscal_processar.php?os_id='.$osReviewId.'&jobs='.(int)$queued['queue_id']);exit;}
        throw new RuntimeException((string)($queued['message']??'Não foi possível preparar a emissão. Verifique a situação fiscal da O.S.'));
      }
      $result=nfse_quick_authorize_order($pdo,$companyId,$reviewPedidoId);
      if(empty($result['ok']) && !empty($result['message']))throw new RuntimeException((string)$result['message']);
      header('Location: fiscal_nfse_nacional.php?pedido_id='.$reviewPedidoId.'&ok='.rawurlencode('NFS-e da fatura de O.S. processada.'));exit;
    }
    header('Location: gestao_pedido_detalhado.php?'.($osReviewId>0?'os_nfse='.$osReviewId:'os_nfse_lote='.$osReviewInvoiceId).'&ok='.rawurlencode('Conferência fiscal salva. Nenhuma NFS-e foi transmitida.'));exit;
  }
  // Confere documentos antigos pelo pedido E pela O.S. antes de permitir outra emissão.
  if($osReviewId>0){$q=os_fiscal_latest_queue($pdo,$companyId,$osReviewId,'NFSE');if($q && in_array(strtoupper((string)$q['status']),['PENDENTE','PROCESSANDO'],true))$reviewFiscalIssues[]='Há uma NFS-e na fila ou em processamento.';}
  $existing=nfse_emitter_existing_document($pdo,$companyId,$reviewPedidoId);
  if($existing && !in_array(strtoupper((string)($existing['status']??'')),['REJEITADA','CANCELADA'],true))$reviewFiscalIssues[]='Há DPS/NFS-e já iniciada ou autorizada para este pedido. Consulte o documento antes de tentar outra emissão.';
  if($osReviewId>0){require_once __DIR__.'/../config/os_nfse_consulta.php';$ctx=os_nfse_consulta_context($pdo,$companyId,$osReviewId);foreach($ctx['docs'] as $d){if(!in_array(strtoupper((string)$d['status']),['REJEITADA','CANCELADA'],true)){$reviewFiscalIssues[]='Existe documento fiscal vinculado à O.S., que deve ser regularizado antes de nova emissão.';break;}}}
  $nfseCtx=nfse_emitter_order_context($pdo,$companyId,$reviewPedidoId);
  $reviewFiscalIssues=array_values(array_unique(array_merge($reviewFiscalIssues,(array)($nfseCtx['issues']??[]))));
} catch(Throwable $e){$reviewError=$e->getMessage();}
$csrf=os_fiscal_csrf_token();
$e=static fn($x)=>htmlspecialchars((string)$x,ENT_QUOTES,'UTF-8');
gestao_admin_start('Pedido Detalhado · Conferência NFS-e','gestao_pedido_detalhado');
gestao_page_header('Pedido Detalhado · Conferência da NFS-e', 'Pedido já faturado pela O.S. · conferência fiscal sem duplicação do financeiro e estoque.');
?>
<style>
.osnf-head{background:linear-gradient(120deg,#10317b,#215bd9);color:#fff;border-radius:15px;padding:17px 20px;margin:12px 0 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}.osnf-head a{color:#fff;border:1px solid #afc7fc;border-radius:10px;padding:9px 13px;text-decoration:none}.osnf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.osnf-card{border:1px solid #d5e0ee;border-radius:14px;background:#fff;padding:15px;margin-bottom:13px;box-shadow:0 5px 20px rgba(20,47,98,.045)}.osnf-card h3{color:#173878;margin:0 0 13px}.osnf-card label{font-size:12px;color:#374d71;font-weight:850;display:block;margin:8px 0 5px}.osnf-card textarea,.osnf-card input{width:100%;border:1px solid #9ab5d6;border-radius:10px;padding:10px;background:#fbfdff;box-sizing:border-box;font:inherit}.osnf-value{font-size:19px;font-weight:900;color:#173878}.osnf-muted{font-size:12px;color:#64748b}.osnf-row{border:1px solid #dce6f3;border-radius:12px;padding:12px;margin:9px 0;background:#f8fbff}.osnf-tax{font-size:12px;display:flex;gap:14px;flex-wrap:wrap;padding:9px 0;color:#334155}.osnf-actions{display:flex;align-items:center;justify-content:flex-end;gap:9px;flex-wrap:wrap}.osnf-warning{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412;border-radius:10px;padding:12px;margin:10px 0}.osnf-success{background:#ecfdf5;border:1px solid #a7f3d0;color:#065f46;border-radius:10px;padding:12px;margin:10px 0}@media(max-width:750px){.osnf-grid{grid-template-columns:1fr}.osnf-actions{justify-content:stretch}.osnf-actions .btn{width:100%}}
</style>
<div class="osnf-head"><div><strong><?= $osReviewId?'O.S. #'.$e($reviewOs['os_number']??$osReviewId):'Fatura em lote #'.$e($reviewInvoice['invoice_number']??$osReviewInvoiceId) ?></strong><div>Revisão dos dados da NFS-e antes do envio</div></div><a href="<?=$e($osReviewBack)?>">← Voltar à O.S. / Fatura</a></div>
<?php if($reviewError!==''):?><div class="osnf-warning"><?=$e($reviewError)?></div><?php elseif($reviewOrder):?>
<?php if($reviewSuccess!==''):?><div class="osnf-success"><?=$e($reviewSuccess)?></div><?php endif;?>
<div class="osnf-grid">
  <div class="osnf-card"><div class="osnf-muted">Pedido vinculado</div><div class="osnf-value">#<?=$e($reviewPedidoId)?></div></div>
  <div class="osnf-card"><div class="osnf-muted">Tomador</div><div class="osnf-value" style="font-size:15px"><?=$e($reviewOrder['nome_cliente']??'—')?></div><div class="osnf-muted"><?=$e($reviewOrder['customer_document']??'')?></div></div>
  <div class="osnf-card"><div class="osnf-muted">Total do pedido</div><div class="osnf-value">R$ <?=number_format((float)($reviewOrder['total']??0),2,',','.')?></div></div>
</div>
<?php if($reviewFiscalIssues):?><div class="osnf-warning"><b>Confira antes de emitir:</b><ul><?php foreach($reviewFiscalIssues as $issue):?><li><?=$e($issue)?></li><?php endforeach;?></ul></div><?php endif;?>
<form method="post" action="gestao_pedido_detalhado.php">
<input type="hidden" name="csrf" value="<?=$e($csrf)?>"><input type="hidden" name="os_nfse" value="<?=$osReviewId?>"><input type="hidden" name="os_nfse_lote" value="<?=$osReviewInvoiceId?>">
<div class="osnf-card"><h3>1. Descrição dos serviços</h3><div class="osnf-muted">Altere ou complemente cada item. As observações serão incluídas na descrição da NFS-e sem modificar o cadastro mestre dos serviços.</div>
<?php foreach($reviewItems as $it):if((int)$it['is_service']!==1)continue;$itemId=(int)$it['id'];$itemNote=trim((string)($it[$reviewItemCol]??''));$defaultNote=($itemNote===''&&$osReviewId>0&&!empty($reviewVehicles))?implode(' | ',$reviewVehicles):$itemNote; ?>
<div class="osnf-row"><strong><?=$e($it['nome'])?></strong> <span class="osnf-muted">· <?=number_format((float)$it['qtd'],2,',','.')?> × R$ <?=number_format((float)$it['preco_unit'],2,',','.')?></span>
<label for="osnf-item-<?=$itemId?>">Observação / complemento da descrição do serviço</label><textarea rows="3" id="osnf-item-<?=$itemId?>" name="observacao_item[<?=$itemId?>]" maxlength="700"><?=$e($defaultNote)?></textarea>
<div class="osnf-tax"><span><b>ISS:</b> <?=$e(number_format((float)($it['iss_rate']??0),2,',','.'))?>%</span><span><b>ISS retido:</b> <?=!empty($it['iss_withheld'])?'Sim':'Não'?></span><span><b>NBS:</b> <?=$e($it['nbs_code']??'—')?></span><span><b>Cód. NFS-e:</b> <?=$e($it['nfse_national_tax_code']??'—')?></span></div></div>
<?php endforeach;?></div>
<div class="osnf-card"><h3>2. Informações adicionais</h3>
<?php if($reviewPeriod!==''):?><div class="osnf-muted"><b>Período das O.S.:</b> <?=$e($reviewPeriod)?></div><?php endif;?>
<?php if($reviewVehicles):?><div class="osnf-muted" style="margin-top:7px"><b>Placas e modelos:</b> <?=$e(implode(' · ',$reviewVehicles))?></div><?php endif;?>
<label for="osnf-general">Observação geral da NFS-e</label><textarea rows="5" id="osnf-general" name="observacao_geral" maxlength="1000"><?=$e($noteValue)?></textarea><div class="osnf-muted">A observação é gravada no pedido vinculado. Alterar ISS, códigos de tributação, valor ou tomador exige corrigir o cadastro fiscal ou a O.S. antes do faturamento; não são modificados por esta prévia.</div>
</div>
<div class="osnf-card"><h3>3. Conferir e emitir</h3><div class="osnf-muted">O botão de salvar não emite nota. A emissão ocorre somente mediante confirmação explícita. Se houver DPS anterior ou inconsistência fiscal, regularize antes de transmitir.</div><div class="osnf-actions"><a class="btn" href="<?=$e($osReviewBack)?>">Voltar</a><button type="submit" name="os_review_action" value="save" class="btn">Salvar conferência</button><button type="submit" name="os_review_action" value="issue" class="btn btn-primary" <?=($reviewFiscalIssues?'disabled title="Regularize os impedimentos fiscais antes de emitir"':'')?> onclick="return confirm('CONFIRMA a emissão de NFS-e? Uma nota fiscal real poderá ser autorizada. Não repita em caso de retorno incerto.');">Confirmar e emitir NFS-e</button></div></div>
</form>
<?php endif;?>
<?php gestao_admin_end(); ?>
