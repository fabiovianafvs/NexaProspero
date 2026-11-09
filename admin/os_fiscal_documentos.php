<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/../config/os_nfse_consulta.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db();$companyId=os_company_id();os_require_enabled($pdo,$companyId);os_ensure_defaults($pdo,$companyId);os_fiscal_queue_ensure_schema($pdo);
$q=trim((string)($_GET['q'] ?? ''));
$type=os_fiscal_type((string)($_GET['type'] ?? ''));
$status=strtoupper(trim((string)($_GET['status'] ?? '')));
$from=trim((string)($_GET['date_from'] ?? ''));
$to=trim((string)($_GET['date_to'] ?? ''));
$osId=(int)($_GET['os_id'] ?? 0);
$allowedStatus=array('','PENDENTE','PROCESSANDO','CONCLUIDO','ERRO');if(!in_array($status,$allowedStatus,true))$status='';
$fiscalOptions=os_fiscal_company_options($pdo,$companyId);
$csrf=os_fiscal_csrf_token();

$where=array("o.company_id=?","o.billing_status='FATURADA'");$params=array($companyId);
if($osId>0){$where[]='o.id=?';$params[]=$osId;}
if($type==='NFSE'){$where[]='o.requires_service_fiscal=1';}
elseif($type==='NFE' || $type==='NFCE'){$where[]='o.requires_product_fiscal=1';}
if($q!==''){
    $parts=array('CAST(o.os_number AS CHAR) LIKE ?','o.person_name_snapshot LIKE ?','o.person_document_snapshot LIKE ?','CAST(o.pedido_id AS CHAR) LIKE ?');
    for($i=0;$i<4;$i++)$params[]=nexa_search_like($q);
    $seqClause=os_pedido_sequence_search_clause($pdo,'o');
    if($seqClause!==''){$parts[]=$seqClause;$params[]=nexa_search_like($q);}
    $where[]='('.implode(' OR ',$parts).')';
}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$where[]='DATE(o.billed_at)>=?';$params[]=$from;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$where[]='DATE(o.billed_at)<=?';$params[]=$to;}
$sql="SELECT o.* FROM os_orders o WHERE ".implode(' AND ',$where)." ORDER BY o.billed_at DESC,o.id DESC LIMIT 150";
$st=$pdo->prepare($sql);$st->execute($params);$orders=$st->fetchAll(PDO::FETCH_ASSOC) ?: array();
$pedidoDisplayMap=os_pedido_display_numbers($pdo,$companyId,array_column($orders,'pedido_id'));
$orderIds=array_map('intval',array_column($orders,'id'));
$queueByOrder=array();$docByOrder=array();
if($orderIds){
    $ph=implode(',',array_fill(0,count($orderIds),'?'));
    $st=$pdo->prepare("SELECT * FROM os_fiscal_queue WHERE company_id=? AND os_order_id IN ($ph) ORDER BY id DESC");
    $st->execute(array_merge(array($companyId),$orderIds));
    foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row){$oid=(int)$row['os_order_id'];$t=os_fiscal_type($row['document_type']);if($t!==''&&!isset($queueByOrder[$oid][$t]))$queueByOrder[$oid][$t]=$row;}
    try{
        $st=$pdo->prepare("SELECT d.*,nd.pedido_id AS nfse_pedido_id FROM fiscal_documents d LEFT JOIN fiscal_nfse_details nd ON nd.company_id=d.company_id AND nd.fiscal_document_id=d.id WHERE d.company_id=? AND d.source_type='ORDEM_SERVICO' AND d.source_id IN ($ph) AND d.model IN ('55','65','NFSE') ORDER BY d.id DESC");
        $st->execute(array_merge(array($companyId),$orderIds));
        foreach($st->fetchAll(PDO::FETCH_ASSOC) ?: array() as $row){$oid=(int)$row['source_id'];$t=((string)$row['model']==='55'?'NFE':((string)$row['model']==='65'?'NFCE':'NFSE'));if($t==='NFSE'&&strtoupper((string)$row['status'])==='CANCELADA' && (int)($row['nfse_pedido_id']??0)>0){$currentPedido=0;foreach($orders as $oCheck){if((int)$oCheck['id']===$oid){$currentPedido=(int)$oCheck['pedido_id'];break;}}if($currentPedido>0 && $currentPedido!==(int)$row['nfse_pedido_id'])continue;}if(!isset($docByOrder[$oid][$t]))$docByOrder[$oid][$t]=$row;}
    }catch(Throwable $e){}
    // Vínculo legado: NFS-e emitida diretamente pelo Pedido faturado pela O.S.
    // Apenas leitura. NÃO altera a origem fiscal nem cria documentos.
    $pedidoIds=array_values(array_unique(array_filter(array_map('intval',array_column($orders,'pedido_id')))));
    if($pedidoIds){
        try {
            $pph=implode(',',array_fill(0,count($pedidoIds),'?'));
            $s=$pdo->prepare("SELECT d.* FROM fiscal_documents d WHERE d.company_id=? AND d.model='NFSE' AND d.direction='SAIDA' AND d.source_type='PEDIDO' AND d.source_id IN ($pph) ORDER BY (d.status='AUTORIZADA') DESC,d.id DESC");
            $s->execute(array_merge(array($companyId),$pedidoIds));
            $osByPedido=array();foreach($orders as $ord){$pid=(int)($ord['pedido_id']??0);if($pid>0)$osByPedido[$pid]=(int)$ord['id'];}
            foreach($s->fetchAll(PDO::FETCH_ASSOC)?:array() as $doc){$oid=$osByPedido[(int)$doc['source_id']]??0;
                if($oid>0 && (!isset($docByOrder[$oid]['NFSE']) || (strtoupper((string)$doc['status'])==='AUTORIZADA' && strtoupper((string)$docByOrder[$oid]['NFSE']['status'])!=='AUTORIZADA'))) $docByOrder[$oid]['NFSE']=$doc;
            }
            // Considera também o vínculo fiscal_nfse_details.pedido_id (documentos legados).
            $s=$pdo->prepare("SELECT d.*,nd.pedido_id AS linked_pedido_id FROM fiscal_nfse_details nd JOIN fiscal_documents d ON d.company_id=nd.company_id AND d.id=nd.fiscal_document_id WHERE nd.company_id=? AND nd.pedido_id IN ($pph) AND d.model='NFSE' AND d.direction='SAIDA' ORDER BY (d.status='AUTORIZADA') DESC,d.id DESC");
            $s->execute(array_merge(array($companyId),$pedidoIds));
            foreach($s->fetchAll(PDO::FETCH_ASSOC)?:array() as $doc){$oid=$osByPedido[(int)$doc['linked_pedido_id']]??0;
                if($oid>0 && (!isset($docByOrder[$oid]['NFSE']) || (strtoupper((string)$doc['status'])==='AUTORIZADA' && strtoupper((string)$docByOrder[$oid]['NFSE']['status'])!=='AUTORIZADA'))) $docByOrder[$oid]['NFSE']=$doc;
            }
        }catch(Throwable $e){error_log('Nexa OS NFSe leitura vinculo pedido: '.$e->getMessage());}
    }
}

$qWhere=array('q.company_id=?');$qParams=array($companyId);
if($osId>0){$qWhere[]='q.os_order_id=?';$qParams[]=$osId;}
if($type!==''){$qWhere[]='q.document_type=?';$qParams[]=$type;}
if($status!==''){$qWhere[]='q.status=?';$qParams[]=$status;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$from)){$qWhere[]='DATE(q.created_at)>=?';$qParams[]=$from;}
if(preg_match('/^\d{4}-\d{2}-\d{2}$/',$to)){$qWhere[]='DATE(q.created_at)<=?';$qParams[]=$to;}
if($q!==''){
    $parts=array('CAST(o.os_number AS CHAR) LIKE ?','o.person_name_snapshot LIKE ?','CAST(q.pedido_id AS CHAR) LIKE ?');
    for($i=0;$i<3;$i++)$qParams[]=nexa_search_like($q);
    $seqClause=os_pedido_sequence_search_clause($pdo,'q');
    if($seqClause!==''){$parts[]=$seqClause;$qParams[]=nexa_search_like($q);}
    $qWhere[]='('.implode(' OR ',$parts).')';
}
$qSql="SELECT q.*,o.os_number,o.person_name_snapshot,o.total_general,d.model AS fiscal_model,d.status AS fiscal_status,d.number AS fiscal_number,d.series AS fiscal_series,d.access_key,d.sefaz_status_code,d.sefaz_status_message
       FROM os_fiscal_queue q
       JOIN os_orders o ON o.company_id=q.company_id AND o.id=q.os_order_id
       LEFT JOIN fiscal_documents d ON d.company_id=q.company_id AND d.id=q.fiscal_document_id
       WHERE ".implode(' AND ',$qWhere)." ORDER BY q.id DESC LIMIT 300";
$st=$pdo->prepare($qSql);$st->execute($qParams);$queueRows=$st->fetchAll(PDO::FETCH_ASSOC) ?: array();
$pedidoDisplayMap=$pedidoDisplayMap + os_pedido_display_numbers($pdo,$companyId,array_column($queueRows,'pedido_id'));

os_page_start('Documentos fiscais da O.S.','fiscal');
echo '<div class="os-title-row"><h1>Documentos fiscais da O.S.</h1><div class="spacer"></div><a class="btn btn-primary" href="os_monitor.php">Monitor de O.S.</a></div>';
if(isset($_GET['ok']))echo '<div class="alert alert-ok">'.os_e($_GET['ok']).'</div>';
if(isset($_GET['err']))echo '<div class="alert alert-error">'.os_e($_GET['err']).'</div>';
$nfseKind=(string)($_GET['nfse_result']??'');
if(in_array($nfseKind,array('ok','warn','info'),true)){
    $nfseText=trim((string)($_GET['nfse_message']??'Consulta NFS-e concluída.'));
    $nfseText=function_exists('mb_substr')?mb_substr($nfseText,0,600,'UTF-8'):substr($nfseText,0,600);
    $title=$nfseKind==='ok'?'Consulta NFS-e concluída':($nfseKind==='warn'?'Consulta NFS-e: atenção':'Consulta NFS-e');
    $tone=$nfseKind==='ok'?'#08783e':($nfseKind==='warn'?'#92400e':'#1e3a8a');
    echo '<div role="alertdialog" aria-modal="true" aria-labelledby="osNfseModalTitle" style="position:fixed;inset:0;background:rgba(15,23,42,.5);z-index:9999;display:grid;place-items:center;padding:16px"><div style="width:min(95vw,520px);background:#fff;border-radius:16px;box-shadow:0 20px 70px #0f172a55;padding:24px;border:1px solid #dbe4ee"><h2 id="osNfseModalTitle" style="color:'.os_e($tone).';font-size:19px;margin:0 0 14px">'.os_e($title).'</h2><p style="line-height:1.55;white-space:pre-wrap">'.os_e($nfseText).'</p><div style="display:flex;justify-content:flex-end;margin-top:20px"><a class="btn btn-primary" href="os_fiscal_documentos.php'.($osId>0?'?os_id='.$osId:'').'" autofocus>OK</a></div></div></div>';
}
echo '<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a"><b>Área fiscal isolada da O.S.</b> A geração iniciada pelo usuário é processada imediatamente na tela. <code>os_fiscal_queue</code> permanece como controle e proteção contra interrupções; vendas comuns do PDV não entram nesta fila.</div>';

echo '<div class="card"><div class="card-head">Filtros</div><div class="card-body"><form method="get"><div class="filter-grid">';
echo '<div class="filter-search"><label>Pesquisar</label><input name="q" value="'.os_e($q).'" placeholder="Nº O.S., cliente ou pedido"></div>';
echo '<div><label>Tipo de documento</label><select name="type"><option value="">Todos</option><option value="NFE" '.($type==='NFE'?'selected':'').'>NF-e</option><option value="NFCE" '.($type==='NFCE'?'selected':'').'>NFC-e</option><option value="NFSE" '.($type==='NFSE'?'selected':'').'>NFS-e</option></select></div>';
echo '<div><label>Status da fila</label><select name="status"><option value="">Todos</option>';foreach(array('PENDENTE'=>'Em fila','PROCESSANDO'=>'Processando','CONCLUIDO'=>'Concluído','ERRO'=>'Erro') as $k=>$v)echo '<option value="'.$k.'" '.($status===$k?'selected':'').'>'.$v.'</option>';echo '</select></div>';
echo '<div><label>Data inicial</label><input type="date" name="date_from" value="'.os_e($from).'"></div><div><label>Data final</label><input type="date" name="date_to" value="'.os_e($to).'"></div>';
echo '<div class="filter-actions"><button class="btn btn-primary">Filtrar</button><a class="btn btn-secondary" href="os_fiscal_documentos.php">Limpar</a></div></div></form></div></div>';

echo '<div class="card"><div class="card-head">O.S. faturadas — gerar documentos separadamente</div><div class="card-body table-wrap"><table class="table"><thead><tr><th>O.S.</th><th>Pedido</th><th>Cliente</th><th class="num">Total</th><th>NF-e / NFC-e</th><th>NFS-e</th><th></th></tr></thead><tbody>';
if(!$orders)echo '<tr><td colspan="7" class="muted">Nenhuma O.S. faturada encontrada.</td></tr>';
foreach($orders as $o){
    $oid=(int)$o['id'];
    $productInfo='—';$serviceInfo='—';
    if((int)$o['requires_product_fiscal']===1){
        $nfeD=$docByOrder[$oid]['NFE'] ?? null;$nfceD=$docByOrder[$oid]['NFCE'] ?? null;$nfeQ=$queueByOrder[$oid]['NFE'] ?? null;$nfceQ=$queueByOrder[$oid]['NFCE'] ?? null;
        if($nfeD)$productInfo='NF-e: '.os_fiscal_status_label($nfeD['status']);elseif($nfceD)$productInfo='NFC-e: '.os_fiscal_status_label($nfceD['status']);elseif($nfeQ)$productInfo='NF-e: '.os_fiscal_status_label($nfeQ['status']);elseif($nfceQ)$productInfo='NFC-e: '.os_fiscal_status_label($nfceQ['status']);else $productInfo='Não gerado';
    }
    if((int)$o['requires_service_fiscal']===1){$d=$docByOrder[$oid]['NFSE'] ?? null;$qq=$queueByOrder[$oid]['NFSE'] ?? null;$serviceInfo=$d?'NFS-e: '.os_fiscal_status_label($d['status']):($qq?'NFS-e: '.os_fiscal_status_label($qq['status']):'Não gerado');}
    echo '<tr><td><b>#'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).'</b></td><td>#'.(int)($pedidoDisplayMap[(int)$o['pedido_id']] ?? (int)$o['pedido_id']).'</td><td>'.os_e($o['person_name_snapshot']).'</td><td class="num">'.os_money($o['total_general']).'</td><td>'.os_e($productInfo);
    if((int)$o['requires_product_fiscal']===1 && !$nfeD && !$nfceD && !$nfeQ && !$nfceQ){
        echo '<div style="display:flex;gap:5px;flex-wrap:wrap;margin-top:6px">';
        if(!empty($fiscalOptions['NFE']))echo '<form method="post" action="os_fiscal_acao.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="'.$oid.'"><input type="hidden" name="doc_type" value="NFE"><input type="hidden" name="return_to" value="os_fiscal_documentos.php"><button class="btn btn-primary btn-sm">Gerar NF-e</button></form>';
        if(!empty($fiscalOptions['NFCE']))echo '<form method="post" action="os_fiscal_acao.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="'.$oid.'"><input type="hidden" name="doc_type" value="NFCE"><input type="hidden" name="return_to" value="os_fiscal_documentos.php"><button class="btn btn-primary btn-sm">Gerar NFC-e</button></form>';
        echo '</div>';
    }
    echo '</td><td>'.os_e($serviceInfo);
    $nfseD=$docByOrder[$oid]['NFSE'] ?? null;$nfseQ=$queueByOrder[$oid]['NFSE'] ?? null;
    if((int)$o['requires_service_fiscal']===1 && !$nfseD && !$nfseQ && !empty($fiscalOptions['NFSE'])){
        echo '<div style="margin-top:6px"><a class="btn btn-primary btn-sm" href="gestao_pedido_detalhado.php?os_nfse='.$oid.'">Conferir / emitir NFS-e</a></div>';
    }
    if($nfseD && strtoupper((string)$nfseD['status'])==='AUTORIZADA')echo '<div style="margin-top:6px"><a class="btn btn-outline btn-sm" target="_blank" href="fiscal_nfse_imprimir.php?doc_id='.(int)$nfseD['id'].'">Imprimir NFS-e</a></div>';
    if((int)$o['requires_service_fiscal']===1 && ($nfseD || $nfseQ)){
        echo '<form method="post" action="os_fiscal_consultar_nfse.php" style="display:inline-block;margin-top:6px"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="os_id" value="'.$oid.'"><button class="btn btn-outline btn-sm" type="submit">Consultar NFS-e</button></form>';
    }
    if($nfseD && in_array(strtoupper((string)($nfseD['status']??'')),['AUTORIZADA','CANCELADA'],true)){
        echo '<details style="margin-top:7px"><summary style="color:#b42318;cursor:pointer;font-weight:800">Cancelar NFS-e / pedido</summary>';
        echo '<form method="post" action="os_nfse_cancelar.php" style="display:grid;gap:5px;margin-top:8px">';
        echo '<input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="os_id" value="'.$oid.'"><input type="hidden" name="doc_id" value="'.(int)$nfseD['id'].'">';
        echo '<label class="small">Motivo fiscal<select name="motivo_codigo"><option value="1">1 - Erro na emissão</option><option value="2">2 - Serviço não prestado</option><option value="9" selected>9 - Outros</option></select></label>';
        echo '<label class="small">Justificativa (mínimo 15 caracteres)<input name="motivo" maxlength="255" minlength="15" required placeholder="Motivo do cancelamento"></label><label class="small">Digite CANCELAR<input name="confirmacao" required autocomplete="off" placeholder="CANCELAR"></label>';
        echo '<button class="btn btn-danger btn-sm" onclick="return confirm(\'Confirmar solicitação de cancelamento FISCAL e estorno COMERCIAL?\')">Confirmar cancelamento</button></form><div class="small muted">A O.S. só será liberada após a confirmação fiscal e o estorno comercial.</div></details>';
    }
    echo '</td><td class="nowrap"><a class="btn btn-outline btn-sm" href="os_faturar.php?id='.$oid.'">Gerenciar fiscal</a></td></tr>';
}
echo '</tbody></table></div></div>';

echo '<div class="card"><div class="card-head">Fila fiscal e documentos</div><div class="card-body table-wrap"><table class="table"><thead><tr><th>Data</th><th>O.S.</th><th>Pedido</th><th>Cliente</th><th>Tipo</th><th>Processamento</th><th>Documento fiscal</th><th>Execuções</th><th></th></tr></thead><tbody>';
if(!$queueRows)echo '<tr><td colspan="9" class="muted">Nenhum item encontrado na fila fiscal da O.S.</td></tr>';
foreach($queueRows as $r){
    $t=os_fiscal_type($r['document_type']);$qStatus=strtoupper((string)$r['status']);$docStatus=trim((string)($r['fiscal_status'] ?? ''));
    $authorizedDoc=$docByOrder[(int)$r['os_order_id']]['NFSE']??null;
    $linkedAuthorized=($t==='NFSE' && $authorizedDoc && strtoupper((string)$authorizedDoc['status'])==='AUTORIZADA');
    $sameAuthorizedDoc=$linkedAuthorized && (int)($r['fiscal_document_id']??0)===(int)$authorizedDoc['id'];
    $displayStatus=($linkedAuthorized && $qStatus==='ERRO')?($sameAuthorizedDoc?'Autorizada — conciliar fila':'Autorizada no pedido — conferir fila'):os_fiscal_status_label($qStatus);
    echo '<tr><td>'.os_e(date('d/m/Y H:i',strtotime($r['created_at']))).'</td><td><b>#'.str_pad((string)$r['os_number'],6,'0',STR_PAD_LEFT).'</b></td><td>#'.(int)($pedidoDisplayMap[(int)$r['pedido_id']] ?? (int)$r['pedido_id']).'</td><td>'.os_e($r['person_name_snapshot']).'</td><td><b>'.os_e(os_fiscal_label($t)).'</b></td><td><span class="badge" style="background:#eef2ff;color:#3730a3">'.os_e($displayStatus).'</span>';
    if(!empty($r['error_message']) && !$sameAuthorizedDoc){
        $queueError=(string)$r['error_message'];
        if($t==='NFSE' && str_contains($queueError,'Digite EMITIR'))
            $queueError='A DPS foi preparada, mas a transmissão em produção não foi autorizada nesta tentativa. Use Consultar NFS-e para verificar a situação antes de transmitir.';
        echo '<div class="small" style="color:#b42318;max-width:360px;margin-top:5px">'.os_e($queueError).'</div>';
    }
    echo '</td><td>'.($docStatus!==''?'<b>'.os_e(os_fiscal_status_label($docStatus)).'</b>':'—');
    if(!empty($r['fiscal_number']))echo '<div class="small muted">'.os_e($r['fiscal_series']).'/'.os_e($r['fiscal_number']).'</div>';
    echo '</td><td>'.(int)$r['attempts'].'</td><td class="nowrap">';
    if($t==='NFSE')echo '<form method="post" action="os_fiscal_consultar_nfse.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="os_id" value="'.(int)$r['os_order_id'].'"><button class="btn btn-outline btn-sm" type="submit">Consultar NFS-e</button></form> ';
    elseif($qStatus==='ERRO')echo '<form method="post" action="os_fiscal_acao.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="retry"><input type="hidden" name="queue_id" value="'.(int)$r['id'].'"><input type="hidden" name="return_to" value="os_fiscal_documentos.php"><button class="btn btn-danger btn-sm">Tentar novamente</button></form> ';
    elseif($qStatus==='PENDENTE')echo '<a class="btn btn-primary btn-sm" href="os_fiscal_processar.php?os_id='.(int)$r['os_order_id'].'&jobs='.(int)$r['id'].'">Processar agora</a> ';
    if((int)$r['fiscal_document_id']>0 && strtoupper($docStatus)==='AUTORIZADA' && ($t==='NFE'||$t==='NFCE'))echo '<a class="btn btn-outline btn-sm" target="_blank" href="fiscal_nfce_imprimir.php?doc_id='.(int)$r['fiscal_document_id'].'&amp;source=os&amp;return_to='.rawurlencode('os_monitor.php').'">Imprimir</a> ';
    echo '<a class="btn btn-secondary btn-sm" href="os_faturar.php?id='.(int)$r['os_order_id'].'">O.S.</a></td></tr>';
}
echo '</tbody></table></div></div>';

os_page_end();
