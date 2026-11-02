<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_fiscal_queue.php';
require_once __DIR__ . '/_os_ui.php';

$pdo=os_db();
$companyId=os_company_id();
os_require_enabled($pdo,$companyId);
os_ensure_defaults($pdo,$companyId);
os_fiscal_queue_ensure_schema($pdo);

$id=(int)(isset($_GET['id'])?$_GET['id']:(isset($_POST['order_id'])?$_POST['order_id']:0));
if($id<=0) die('O.S. inválida.');
$error='';
$ok=trim((string)($_GET['ok'] ?? ''));

function osf_order_v19(PDO $pdo,$companyId,$id){
    $s=$pdo->prepare("SELECT o.*,s.name AS status_name FROM os_orders o LEFT JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id WHERE o.company_id=? AND o.id=? LIMIT 1");
    $s->execute(array((int)$companyId,(int)$id));
    return $s->fetch(PDO::FETCH_ASSOC);
}

$order=osf_order_v19($pdo,$companyId,$id);
if(!$order){http_response_code(404);die('O.S. não encontrada.');}
$batchInvoice=null;
try{
    $bs=$pdo->prepare("SELECT bi.* FROM os_batch_invoice_orders io INNER JOIN os_batch_invoices bi ON bi.company_id=io.company_id AND bi.id=io.invoice_id WHERE io.company_id=? AND io.os_order_id=? AND bi.status IN ('CONFERENCIA','APROVADA','FATURADA') ORDER BY bi.id DESC LIMIT 1");
    $bs->execute(array($companyId,$id));$batchInvoice=$bs->fetch(PDO::FETCH_ASSOC)?:null;
}catch(Throwable $ignored){}

if($_SERVER['REQUEST_METHOD']==='POST' && ($_POST['action'] ?? '')==='bill_os'){
    try{
        os_fiscal_csrf_check($_POST['csrf'] ?? '');
        if($batchInvoice){
            throw new RuntimeException('Esta O.S. pertence à Fatura de O.S. #'.str_pad((string)$batchInvoice['invoice_number'],6,'0',STR_PAD_LEFT).'. O faturamento deve ser concluído pela Fatura consolidada.');
        }
        $saveOnly=!empty($_POST['save_only']);
        $productDoc=$saveOnly?'':os_fiscal_type((string)($_POST['product_document'] ?? ''));
        $serviceDoc=(!$saveOnly && !empty($_POST['service_document']))?'NFSE':'';
        if($productDoc!=='' && !in_array($productDoc,array('NFE','NFCE'),true)) $productDoc='';
        if($productDoc!=='' && (int)$order['requires_product_fiscal']!==1) throw new RuntimeException('A O.S. não possui produtos para o documento selecionado.');
        if($serviceDoc!=='' && (int)$order['requires_service_fiscal']!==1) throw new RuntimeException('A O.S. não possui serviços para NFS-e.');

        $pedidoId=os_billing_finalize_order($pdo,$companyId,$id,(int)(os_user_id() ?: 0));
        $pdo->prepare("UPDATE os_orders SET product_document_type=?,service_document_type=?,updated_at=NOW() WHERE company_id=? AND id=?")
            ->execute(array($productDoc!==''?$productDoc:null,$serviceDoc!==''?$serviceDoc:null,$companyId,$id));

        $pedidoNumber=os_pedido_display_number($pdo,$companyId,$pedidoId);
        $messages=array('O.S. faturada no Pedido #'.$pedidoNumber.'.');
        $queueIds=array();
        if($productDoc!==''){
            $r=os_fiscal_queue_enqueue($pdo,$companyId,$id,$productDoc,(int)(os_user_id() ?: 0));
            $messages[]=$r['message'];
            if((int)($r['queue_id'] ?? 0)>0)$queueIds[]=(int)$r['queue_id'];
        }
        // A NFS-e não é mais enviada automaticamente ao faturar. Primeiro abre a
        // conferência no Pedido Detalhado, para revisar descrição, placa/modelo e observações.
        if($serviceDoc!==''){
            header('Location: gestao_pedido_detalhado.php?os_nfse='.$id);
            exit;
        }
        if($queueIds){
            header('Location: os_fiscal_processar.php?os_id='.$id.'&jobs='.rawurlencode(implode(',',$queueIds)));
            exit;
        }
        if($productDoc==='' && $serviceDoc==='') $messages[]='Pedido gravado sem emissão fiscal imediata. Os documentos podem ser gerados depois.';
        header('Location: os_faturar.php?id='.$id.'&ok='.rawurlencode(implode(' ',$messages)));
        exit;
    }catch(Throwable $e){
        $error=$e->getMessage();
    }
}

$order=osf_order_v19($pdo,$companyId,$id);
$pedidoNumber=((int)($order['pedido_id'] ?? 0)>0)?os_pedido_display_number($pdo,$companyId,(int)$order['pedido_id']):0;
$stmt=$pdo->prepare("SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id");
$stmt->execute(array($companyId,$id));
$payments=$stmt->fetchAll(PDO::FETCH_ASSOC);
$payTotal=0;foreach($payments as $p)$payTotal+=(float)$p['amount'];
$fiscalOptions=os_fiscal_company_options($pdo,$companyId);
$csrf=os_fiscal_csrf_token();
$types=array('NFE','NFCE','NFSE');
$queueMap=array();$docMap=array();
foreach($types as $t){$queueMap[$t]=os_fiscal_latest_queue($pdo,$companyId,$id,$t);$docMap[$t]=os_fiscal_document_for_os($pdo,$companyId,$id,$t);}

os_page_start('Faturar O.S.','fiscal');
echo '<div class="os-title-row"><h1>Faturar O.S. #'.str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT).'</h1><div class="spacer"></div><a class="btn btn-secondary" href="os_editar.php?id='.$id.'">Voltar à O.S.</a><a class="btn btn-outline" href="os_fiscal_documentos.php?os_id='.$id.'">Documentos fiscais da O.S.</a></div>';
if($ok!=='')echo '<div class="alert alert-ok">'.os_e($ok).'</div>';
if($error!=='')echo '<div class="alert alert-error">'.os_e($error).'</div>';

echo '<div class="card"><div class="card-head">Resumo do faturamento</div><div class="card-body"><div class="grid">';
echo '<div class="c4"><label>Cliente</label><div><b>'.os_e($order['person_name_snapshot']?:'Não informado').'</b></div><div class="small muted">'.os_e($order['person_document_snapshot']).'</div><div class="small muted">Status da O.S.: '.os_e($order['status_name']?:'').'</div></div>';
echo '<div class="c8"><div class="totals"><div class="total-box"><span>Produtos</span><strong>'.os_money($order['total_products']).'</strong></div><div class="total-box"><span>Serviços</span><strong>'.os_money($order['total_services']).'</strong></div><div class="total-box"><span>Total</span><strong>'.os_money($order['total_general']).'</strong></div></div></div>';
echo '</div></div></div>';

if(!$batchInvoice){
echo '<div class="card"><div class="card-head">Condições de pagamento</div><div class="card-body">';
if(!$payments){
    echo '<div class="alert alert-error">Nenhuma forma de pagamento foi informada. Ela continua opcional durante o lançamento da O.S., porém é necessária para efetivar o faturamento e gerar o pedido financeiro/estoque. <a href="os_editar.php?id='.$id.'">Voltar e informar pagamento</a>.</div>';
}else{
    echo '<div class="table-wrap"><table class="table"><thead><tr><th>Forma</th><th>Vencimento</th><th>Observação</th><th class="num">Valor</th></tr></thead><tbody>';
    foreach($payments as $p)echo '<tr><td>'.os_e($p['payment_label']).'</td><td>'.os_e(!empty($p['due_date'])?date('d/m/Y',strtotime($p['due_date'])):'—').'</td><td>'.os_e($p['notes']).'</td><td class="num">'.os_money($p['amount']).'</td></tr>';
    echo '</tbody></table></div><div class="small muted" style="margin-top:8px">Total informado: <b>'.os_money($payTotal).'</b> · Total da O.S.: <b>'.os_money($order['total_general']).'</b></div>';
    if(abs(round($payTotal-(float)$order['total_general'],2))>0.01)echo '<div class="alert alert-error" style="margin-top:10px">As formas de pagamento não fecham o total da O.S. Ajuste antes de faturar.</div>';
}
echo '</div></div>';
}

if($batchInvoice){
    $batchNo=str_pad((string)$batchInvoice['invoice_number'],6,'0',STR_PAD_LEFT);
    $batchStatus=strtoupper((string)$batchInvoice['status']);
    echo '<div class="card"><div class="card-head">Faturamento consolidado</div><div class="card-body">';
    if($batchStatus==='FATURADA'){
        echo '<div class="alert alert-ok"><b>Esta O.S. foi faturada pela Fatura de O.S. #'.os_e($batchNo).'.</b> O faturamento financeiro e o Pedido foram consolidados com as demais O.S. da mesma fatura.</div>';
    }else{
        echo '<div class="alert" style="background:#fff7ed;border:1px solid #fed7aa;color:#9a3412"><b>Esta O.S. está vinculada à Fatura de O.S. #'.os_e($batchNo).'.</b> Enquanto a fatura estiver em conferência/aprovação, o faturamento individual fica bloqueado para evitar cobrança em duplicidade.</div>';
    }
    echo '<a class="btn btn-primary" href="os_faturas.php?id='.(int)$batchInvoice['id'].'">Abrir Fatura de O.S. #'.os_e($batchNo).'</a></div></div>';
}elseif(strtoupper((string)$order['billing_status'])==='PENDENTE'){
    echo '<div class="card"><div class="card-head">Faturar e escolher documentos</div><div class="card-body">';
    echo '<div class="alert" style="background:#eff6ff;border:1px solid #bfdbfe;color:#1e3a8a"><b>Fluxo isolado:</b> o faturamento desta O.S. usa as regras operacionais existentes para gravar o pedido, estoque e financeiro. Os documentos selecionados são registrados na <b>fila exclusiva da O.S.</b> e autorizados imediatamente na tela, no mesmo estilo do PDV. O PDV comum e sua emissão atual não são alterados.</div>';
    if(!empty($fiscalOptions['problems']))echo '<div class="alert alert-error">'.os_e(implode(' ',$fiscalOptions['problems'])).' Você ainda pode usar “Somente gravar pedido”.</div>';
    echo '<form method="post" id="osBillForm"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="bill_os"><input type="hidden" name="order_id" value="'.$id.'">';
    echo '<div class="grid">';
    if((int)$order['requires_product_fiscal']===1){
        echo '<div class="c6"><label>Documento das mercadorias</label><select name="product_document" id="productDocument"><option value="">Gerar depois</option>';
        if(!empty($fiscalOptions['NFCE']))echo '<option value="NFCE">NFC-e</option>';
        if(!empty($fiscalOptions['NFE']))echo '<option value="NFE">NF-e</option>';
        echo '</select><div class="hint">NF-e e NFC-e são alternativas. Apenas uma pode ser escolhida para os produtos.</div></div>';
    }
    if((int)$order['requires_service_fiscal']===1){
        echo '<div class="c6"><label>NFS-e dos serviços</label><label style="display:flex;align-items:center;gap:8px;min-height:38px;border:1px solid #cbd5e1;border-radius:9px;padding:8px 10px;background:#fff"><input type="checkbox" name="service_document" value="1" id="serviceDocument" style="width:auto;min-height:auto" '.(!empty($fiscalOptions['NFSE'])?'':'disabled').'> Conferir NFS-e antes de emitir</label><div class="hint">'.(!empty($fiscalOptions['NFSE'])?'Pode ser combinada com NF-e ou NFC-e.':'NFS-e não está habilitada nas Configurações Fiscais.').'</div></div>';
    }
    echo '<div class="c12"><label style="display:flex;align-items:center;gap:8px;padding:12px;border:1px solid #dbe4ee;border-radius:10px;background:#f8fafc"><input type="checkbox" name="save_only" value="1" id="saveOnly" style="width:auto;min-height:auto"> <span><b>Somente gravar pedido</b><span class="small muted" style="display:block;margin-top:3px">Fatura a O.S., atualiza pedido/estoque/financeiro e deixa os documentos fiscais para serem gerados depois.</span></span></label></div>';
    echo '<div class="c12" style="display:flex;gap:8px;align-items:center;flex-wrap:wrap"><button class="btn btn-success" type="submit" '.(!$payments || abs(round($payTotal-(float)$order['total_general'],2))>0.01?'disabled style="opacity:.5;cursor:not-allowed"':'').' onclick="return confirm(\'Confirma o faturamento desta O.S.? O pedido será efetivado; a NFS-e terá conferência obrigatória antes da transmissão.\')">Faturar O.S.</button><span class="small muted">Após gravar o pedido, a tela mostrará as etapas de preparação, envio e autorização. A fila/worker fica apenas como proteção em caso de interrupção.</span></div>';
    echo '</div></form>';
    echo '<script>(function(){var save=document.getElementById("saveOnly"),prod=document.getElementById("productDocument"),svc=document.getElementById("serviceDocument");if(!save)return;save.addEventListener("change",function(){if(prod){prod.disabled=this.checked;if(this.checked)prod.value="";}if(svc){svc.disabled=this.checked || svc.dataset.originalDisabled==="1";if(this.checked)svc.checked=false;}});if(svc && svc.disabled)svc.dataset.originalDisabled="1";})();</script>';
    echo '</div></div>';
}elseif(strtoupper((string)$order['billing_status'])==='ENVIADA_PDV'){
    echo '<div class="alert alert-error"><b>Fluxo anterior detectado.</b> Esta O.S. possui pedido criado pela versão anterior para envio ao PDV. Por segurança, a v1.9 não transforma automaticamente esse pedido aberto em faturado. Regularize/cancele o pedido antigo antes de usar o novo faturamento isolado.</div>';
}else{
    echo '<div class="card"><div class="card-head">Pedido faturado</div><div class="card-body"><div class="alert alert-ok">Esta O.S. está faturada'.($pedidoNumber>0?' no Pedido #'.$pedidoNumber:'').'. Os documentos fiscais abaixo são independentes e podem ser gerados/reprocessados separadamente.</div>';
    echo '<div class="table-wrap"><table class="table"><thead><tr><th>Documento</th><th>Fila</th><th>Documento fiscal</th><th></th></tr></thead><tbody>';
    foreach($types as $t){
        if(($t==='NFE'||$t==='NFCE') && (int)$order['requires_product_fiscal']!==1)continue;
        if($t==='NFSE' && (int)$order['requires_service_fiscal']!==1)continue;
        $q=$queueMap[$t];$d=$docMap[$t];$qs=$q?os_fiscal_status_label($q['status']):'Não enviado';$ds=$d?os_fiscal_status_label($d['status']):'Não gerado';
        echo '<tr><td><b>'.os_e(os_fiscal_label($t)).'</b></td><td>'.os_e($qs).($q && !empty($q['error_message'])?'<div class="small" style="color:#b42318;max-width:420px">'.os_e($q['error_message']).'</div>':'').'</td><td>'.os_e($ds).($d?'<div class="small muted">Documento #'.(int)$d['id'].'</div>':'').'</td><td class="nowrap">';
        if($d && strtoupper((string)$d['status'])==='AUTORIZADA'){
            if($t==='NFE'||$t==='NFCE')echo '<a class="btn btn-outline btn-sm" target="_blank" href="fiscal_nfce_imprimir.php?doc_id='.(int)$d['id'].'&amp;source=os&amp;return_to='.rawurlencode('os_monitor.php').'">Imprimir</a>';
        }elseif($q && strtoupper((string)$q['status'])==='ERRO'){
            echo '<form method="post" action="os_fiscal_acao.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="retry"><input type="hidden" name="queue_id" value="'.(int)$q['id'].'"><input type="hidden" name="return_to" value="os_faturar.php?id='.$id.'"><button class="btn btn-danger btn-sm">Tentar novamente</button></form>';
        }elseif($q && strtoupper((string)$q['status'])==='PENDENTE'){
            echo '<a class="btn btn-primary btn-sm" href="os_fiscal_processar.php?os_id='.$id.'&jobs='.(int)$q['id'].'">Processar agora</a>';
        }elseif(!$q || !in_array(strtoupper((string)$q['status']),array('PENDENTE','PROCESSANDO','CONCLUIDO'),true)){
            $disabled=empty($fiscalOptions[$t]);
            if($t==='NFSE') echo '<a class="btn btn-primary btn-sm" href="gestao_pedido_detalhado.php?os_nfse='.$id.'">Conferir / emitir NFS-e</a>';
            else echo '<form method="post" action="os_fiscal_acao.php" style="display:inline"><input type="hidden" name="csrf" value="'.os_e($csrf).'"><input type="hidden" name="action" value="enqueue"><input type="hidden" name="os_id" value="'.$id.'"><input type="hidden" name="doc_type" value="'.$t.'"><input type="hidden" name="return_to" value="os_faturar.php?id='.$id.'"><button class="btn btn-primary btn-sm" '.($disabled?'disabled style="opacity:.5"':'').'>Gerar '.os_e(os_fiscal_label($t)).'</button></form>';
        }
        echo '</td></tr>';
    }
    echo '</tbody></table></div><div style="margin-top:12px"><a class="btn btn-outline" href="os_fiscal_documentos.php?os_id='.$id.'">Abrir documentos fiscais da O.S.</a></div></div></div>';
}

os_page_end();
