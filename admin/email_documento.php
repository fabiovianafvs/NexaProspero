<?php
declare(strict_types=1);

require __DIR__ . '/_bootstrap.php';
require __DIR__ . '/_chrome.php';
require __DIR__ . '/_finance.php';
require_once __DIR__ . '/../config/email_service.php';
require_once __DIR__ . '/../config/load_config.php';
require_once __DIR__ . '/../config/customer_document_pdf.php';
require_once __DIR__ . '/../config/os_document_share_options.php';
require_once __DIR__ . '/../config/orcamento_module.php';

$uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : (int)($_SESSION['user_id'] ?? 0);
$type = strtolower(trim((string)($_GET['type'] ?? $_POST['type'] ?? '')));
if (function_exists('access_has_permission')) {
  if ($type === 'orcamento' && !access_has_permission($pdo,(int)COMPANY_ID,'orcamentos')) access_render_denied('Seu usuário não possui acesso aos Orçamentos.');
  if ($type === 'os' && !access_has_permission($pdo,(int)COMPANY_ID,'os_monitor')) access_render_denied('Seu usuário não possui acesso às Ordens de Serviço.');
}
$billingCrossContext = $type === 'boleto' && function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_billing_send');
$receiptCrossContext = $type === 'recibo' && function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_receipt_send');
if ($billingCrossContext && (!function_exists('finance_multi_company_billing_send_enabled') || !finance_multi_company_billing_send_enabled($pdo))) {
  access_render_denied('V11.23: seu perfil não possui permissão para enviar boletos por e-mail na empresa selecionada pelo F11.');
}
if ($receiptCrossContext && (!function_exists('finance_multi_company_receipt_send_enabled') || !finance_multi_company_receipt_send_enabled($pdo))) {
  access_render_denied('V11.23: seu perfil não possui permissão para enviar recibos por e-mail na empresa selecionada pelo F11.');
}
$emailCrossContext = $billingCrossContext || $receiptCrossContext;
$emailScope = $receiptCrossContext ? 'finance_receipt_send' : 'finance_billing_send';
$cid = $emailCrossContext && function_exists('multi_company_operational_company_id')
  ? multi_company_operational_company_id($pdo, $emailScope)
  : (int)COMPANY_ID;
$emailMcCsrf = ($emailCrossContext && function_exists('multi_company_context_csrf_token')) ? multi_company_context_csrf_token() : '';
$emailMcCtx = ($emailCrossContext && function_exists('multi_company_context_request_token')) ? multi_company_context_request_token() : '';

function ned_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function ned_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function ned_date($v, bool $withTime=false): string {
  $v=trim((string)$v); if($v==='') return '';
  $ts=strtotime($v); return $ts ? date($withTime?'d/m/Y H:i':'d/m/Y',$ts) : $v;
}
function ned_safe_return(string $value, string $fallback='pedidos.php'): string {
  $value=trim($value);
  if($value==='' || preg_match('#^https?://#i',$value) || str_starts_with($value,'//') || str_contains($value,"\n") || str_contains($value,"\r")) return $fallback;
  return ltrim($value,'/');
}
function ned_company_name(PDO $pdo,int $companyId): string {
  try { $st=$pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');$st->execute([$companyId]);$v=trim((string)($st->fetchColumn() ?: ''));if($v!=='')return $v; } catch(Throwable $e) {}
  return 'Nexa Próspero';
}
/** Documentos efetivamente associados ao pedido na mesma empresa. */
function ned_order_documents(PDO $pdo,int $companyId,int $orderId): array {
  $out=['fiscal'=>[], 'boleto'=>[]];
  try {
    $st=$pdo->prepare("SELECT id,model FROM fiscal_documents WHERE company_id=? AND source_type='PEDIDO' AND source_id=? AND model IN ('55','65','NFSE') AND UPPER(COALESCE(status,'')) IN ('AUTORIZADA','AUTORIZADO') ORDER BY id");
    $st->execute([$companyId,$orderId]);$out['fiscal']=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }catch(Throwable $e){}
  try {
    $st=$pdo->prepare('SELECT id FROM finance_boleto_charges WHERE company_id=? AND order_id=? ORDER BY id');
    $st->execute([$companyId,$orderId]);$out['boleto']=array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]);
  }catch(Throwable $e){}
  try {
    $st=$pdo->prepare('SELECT bc.id FROM finance_boleto_charges bc INNER JOIN finance_receivables fr ON fr.company_id=bc.company_id AND fr.id=bc.receivable_id WHERE bc.company_id=? AND fr.order_id=? ORDER BY bc.id');
    $st->execute([$companyId,$orderId]);$out['boleto']=array_values(array_unique(array_merge($out['boleto'],array_map('intval',$st->fetchAll(PDO::FETCH_COLUMN)?:[]))));
  }catch(Throwable $e){}
  return $out;
}
function ned_person_email(PDO $pdo,int $companyId,int $personId): string {
  if($personId<=0) return '';
  try { $st=$pdo->prepare('SELECT email FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$personId]);return strtolower(trim((string)($st->fetchColumn() ?: ''))); } catch(Throwable $e) { return ''; }
}

function ned_email_sender_candidates(PDO $pdo,int $companyId): array {
  if($companyId<=0) return [];
  try {
    $sql = "SELECT u.id,u.name,u.email,s.enabled,s.smtp_host,s.smtp_port,s.from_email,s.from_name
              FROM user_email_settings s
              INNER JOIN users u ON u.id=s.user_id AND u.company_id=s.company_id
             WHERE s.company_id=?
               AND u.is_active=1
               AND s.enabled=1
               AND COALESCE(NULLIF(TRIM(s.smtp_host),''),'')<>''
               AND COALESCE(NULLIF(TRIM(s.from_email),''),'')<>''
             ORDER BY u.name ASC,u.id ASC";
    $st=$pdo->prepare($sql);$st->execute([$companyId]);
    $rows=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $out=[];
    foreach($rows as $row){
      $id=(int)($row['id']??0);
      if($id<=0) continue;
      $out[$id]=$row;
    }
    return $out;
  } catch(Throwable $e) { return []; }
}
function ned_order_items_text(array $items): string {
  $lines=[];
  foreach($items as $item){
    $name=trim((string)($item['nome'] ?? $item['produto_nome'] ?? '')) ?: ('Produto #'.(int)($item['produto_id'] ?? 0));
    $qty=(float)($item['qtd'] ?? 1); $q=number_format($qty,3,',','.');$q=rtrim(rtrim($q,'0'),',');
    $lines[]='- '.$q.'x '.$name.' - '.ned_money($item['total'] ?? 0);
  }
  return implode("\n",$lines);
}

$companyName = ned_company_name($pdo,$cid);
$orderDocuments=['fiscal'=>[],'boleto'=>[]];
$userName = 'Usuário';
if($uid>0){ try{$st=$pdo->prepare('SELECT name FROM users WHERE id=? AND company_id=? LIMIT 1');$st->execute([$uid,(int)COMPANY_ID]);$userName=trim((string)($st->fetchColumn() ?: 'Usuário'));}catch(Throwable $e){} }

$returnTo='pedidos.php';
$context=''; $pageTitle='Enviar por e-mail'; $docLabel='Documento'; $summary=''; $recipient=''; $referenceId=null;
$vars=['empresa'=>$companyName,'cliente'=>'','email_cliente'=>'','usuario'=>$userName];
$fallbackSubject='Documento - '.$companyName;
$fallbackBody='Olá!'."\n\n".'Segue o documento solicitado.'."\n\n".$companyName;
$attachmentLabel='';
$payload=[];

try {
  switch($type){
    case 'pedido':
      require_once __DIR__ . '/../config/order_message.php';
      require_once __DIR__ . '/../config/order_email_pdf.php';
      $id=max(0,(int)($_GET['id'] ?? $_POST['id'] ?? 0));
      if($id<=0) throw new RuntimeException('Pedido inválido.');
      $loaded=order_message_load($pdo,$cid,$id);
      if(!$loaded) throw new RuntimeException('Pedido não encontrado.');
      $order=(array)$loaded['pedido']; $items=(array)$loaded['itens'];
      $number=function_exists('company_sequence_display') ? company_sequence_display($order,'pedidos') : $id;
      $personId=(int)($order['finance_person_id'] ?? $order['customer_id'] ?? 0);
      $recipient=strtolower(trim((string)($order['customer_email'] ?? $order['email'] ?? '')));
      if(!filter_var($recipient,FILTER_VALIDATE_EMAIL)) $recipient=ned_person_email($pdo,$cid,$personId);
      $customer=trim((string)($order['nome_cliente'] ?? '')) ?: 'Consumidor final';
      $itemsText=ned_order_items_text($items);
      $subtotal=(float)($order['total_produtos'] ?? 0); if($subtotal<=0){foreach($items as $it)$subtotal+=(float)($it['total']??0);}
      $fee=(float)($order['taxa_entrega'] ?? 0); $total=(float)($order['total'] ?? ($subtotal+$fee));
      $obs='';foreach(['observacoes','observacao','obs'] as $c){if(trim((string)($order[$c]??''))!==''){$obs=trim((string)$order[$c]);break;}}
      $vars=array_merge($vars,[
        'cliente'=>$customer,'email_cliente'=>$recipient,'pedido_id'=>(string)$number,'data'=>ned_date($order['created_at']??'',true),'itens'=>$itemsText,
        'subtotal'=>ned_money($subtotal),'taxa_entrega'=>ned_money($fee),'total'=>ned_money($total),'tipo_entrega'=>trim((string)($order['tipo_entrega']??'')),
        'pagamento'=>trim((string)($order['forma_pagamento']??'')),'endereco'=>trim((string)($order['endereco']??'')),'observacoes'=>$obs,
      ]);
      $context='pedido';$pageTitle='Enviar Pedido #'.$number.' por e-mail';$docLabel='Pedido #'.$number;$referenceId=$id;
      $summary='Cliente: '.$customer.' · Total: '.ned_money($total);
      $fallbackSubject='Pedido - '.$companyName;
      $fallbackBody=trim("Olá, {$customer}!\n\nSegue o pedido em anexo.\n".
        ($vars['data']!==''?'Data: '.$vars['data']."\n":'')."\nItens:\n{$itemsText}\n\nSubtotal: ".ned_money($subtotal).
        ($fee>0.009?"\nTaxa de entrega: ".ned_money($fee):'')."\nTotal: ".ned_money($total).
        ($vars['tipo_entrega']!==''?"\nEntrega: ".$vars['tipo_entrega']:'').($vars['pagamento']!==''?"\nPagamento: ".$vars['pagamento']:'').
        ($vars['endereco']!==''?"\nEndereço: ".$vars['endereco']:'')."\n\nAtenciosamente,\n{$companyName}");
      $attachmentLabel='Pedido em PDF';
      $payload=['order'=>$order,'items'=>$items,'number'=>$number];
      $orderDocuments=ned_order_documents($pdo,$cid,$id);
      $returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ('pedido_print.php?id='.$id.'&autoprint=0')),'pedidos.php');
      break;

    case 'orcamento':
      $id=max(0,(int)($_GET['id'] ?? $_POST['id'] ?? 0));
      if($id<=0) throw new RuntimeException('Orçamento inválido.');
      $model=strtolower(trim((string)($_GET['modelo'] ?? $_POST['modelo'] ?? 'a4')));if(!in_array($model,['72mm','a4','proposta'],true))$model='a4';
      $data=nexa_quote_document_load($pdo,$cid,$id);$q=(array)$data['quote'];$person=(array)$data['person'];
      $number=str_pad((string)(int)($q['quote_number']??0),6,'0',STR_PAD_LEFT);$customer=trim((string)($q['person_name_snapshot']??''))?:'cliente';
      $recipient=strtolower(trim((string)($person['email']??'')));if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))$recipient=ned_person_email($pdo,$cid,(int)($q['person_id']??0));
      $vars=array_merge($vars,['cliente'=>$customer,'email_cliente'=>$recipient,'orcamento_id'=>$number,'data'=>ned_date($q['quote_date']??''),'validade'=>'','total'=>ned_money($q['total_general']??0),'status'=>orc_status_label((string)($q['status']??''))]);
      $context='orcamento';$pageTitle='Enviar Orçamento #'.$number.' por e-mail';$docLabel='Orçamento #'.$number;$referenceId=$id;$summary='Cliente: '.$customer.' · Total: '.$vars['total'];
      $fallbackSubject='Orçamento #'.$number.' - '.$companyName;$fallbackBody=trim("Olá, {$customer}!\n\nSegue em anexo o Orçamento #{$number}.\nData: {$vars['data']}\nTotal: {$vars['total']}\n\nFicamos à disposição.\n{$companyName}");
      $attachmentLabel='Orçamento em PDF';$payload=['data'=>$data,'model'=>$model];$returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ('orcamento_imprimir.php?id='.$id.'&modelo='.$model)),'orcamentos.php');
      break;

    case 'os':
      $osShareOptions=nexa_os_share_options($_SERVER['REQUEST_METHOD']==='POST'?$_POST:$_GET);
      $id=max(0,(int)($_GET['id'] ?? $_POST['id'] ?? 0));
      if($id<=0) throw new RuntimeException('Ordem de Serviço inválida.');
      $model=strtolower(trim((string)($_GET['modelo'] ?? $_POST['modelo'] ?? 'a4_half')));if(!in_array($model,['72mm','a4_half','a4_full','a4_landscape_dual'],true))$model='a4_half';
      $data=nexa_os_document_load($pdo,$cid,$id);$o=(array)$data['order'];$person=(array)$data['person'];
      $number=str_pad((string)(int)($o['os_number']??0),6,'0',STR_PAD_LEFT);$customer=trim((string)($o['person_name_snapshot']??''))?:'cliente';
      $recipient=strtolower(trim((string)($person['email']??'')));if(!filter_var($recipient,FILTER_VALIDATE_EMAIL))$recipient=ned_person_email($pdo,$cid,(int)($o['person_id']??0));
      $vars=array_merge($vars,['cliente'=>$customer,'email_cliente'=>$recipient,'os_id'=>$number,'data'=>ned_date($o['opened_at']??'',true),'status'=>trim((string)($o['status_name']??'')),'previsao'=>ned_date($o['expected_at']??'',true),'total'=>$osShareOptions['conteudo']==='completa'?ned_money($o['total_general']??0):'']);
      $context='ordem_servico';$pageTitle='Enviar O.S. #'.$number.' por e-mail';$docLabel='Ordem de Serviço #'.$number;$referenceId=$id;$summary='Cliente: '.$customer.' · Status: '.$vars['status'].($osShareOptions['conteudo']==='completa'?' · Total: '.$vars['total']:'');
      $fallbackSubject='Ordem de Serviço #'.$number.' - '.$companyName;$fallbackBody=trim("Olá, {$customer}!\n\nSegue em anexo a Ordem de Serviço #{$number}.\nStatus: {$vars['status']}".($vars['previsao']!==''?"\nPrevisão: {$vars['previsao']}":'').($osShareOptions['conteudo']==='completa'?"\nTotal: {$vars['total']}":'')."\n\nFicamos à disposição.\n{$companyName}");
      $attachmentLabel=nexa_os_share_label($osShareOptions['conteudo']).' em PDF';$payload=['data'=>$data,'model'=>$model,'os_share_options'=>$osShareOptions];$returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ('os_imprimir.php?id='.$id.'&modelo='.$model)),'os_monitor.php');
      break;

    case 'fiscal':
      require_once __DIR__ . '/_fiscal.php';
      require_once __DIR__ . '/../config/fiscal_document_pdf.php';
      $docId=max(0,(int)($_GET['doc_id'] ?? $_GET['id'] ?? $_POST['doc_id'] ?? 0));
      if($docId<=0) throw new RuntimeException('Documento fiscal inválido.');
      try{fiscal_nfce_ensure_schema($pdo);}catch(Throwable $e){}
      $st=$pdo->prepare("SELECT d.*,n.provider_code,n.dps_series,n.dps_number,n.nfse_number,n.verification_code,n.response_xml FROM fiscal_documents d LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id WHERE d.company_id=? AND d.id=? AND d.model IN ('55','65','NFSE') LIMIT 1");$st->execute([$cid,$docId]);$doc=$st->fetch(PDO::FETCH_ASSOC)?:[];
      if(!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
      $modelRaw=strtoupper(trim((string)($doc['model']??'')));$isNfse=$modelRaw==='NFSE';
      $dest=fiscal_nfce_document_participant($pdo,$cid,$docId,$isNfse?'TOMADOR':'DESTINATARIO');
      $emit=fiscal_nfce_document_participant($pdo,$cid,$docId,$isNfse?'PRESTADOR':'EMITENTE');
      $model=$isNfse?'NFSE':(preg_replace('/\D+/','',$modelRaw) ?: '65');$label=$model==='55'?'NF-e':($model==='NFSE'?'NFS-e':'NFC-e');
      $number=$isNfse?(trim((string)($doc['nfse_number']??'')) ?: (string)($doc['number']??'')):(string)($doc['number']??'');
      $series=$isNfse?trim((string)($doc['dps_series']??$doc['series']??'')):(string)($doc['series']??'');$dpsNumber=(string)($doc['dps_number']??$doc['number']??'');
      $customer=trim((string)($dest['name']??'')) ?: 'cliente';$recipient=strtolower(trim((string)($dest['email']??'')));
      $issuer=trim((string)($emit['name']??'')) ?: $companyName;$key=preg_replace('/\D+/','',(string)($doc['access_key']??''));
      $vars=array_merge($vars,['empresa'=>$issuer,'cliente'=>$customer,'email_cliente'=>$recipient,'documento'=>$label,'numero'=>$number,'serie'=>$series,'dps_serie'=>$series,'dps_numero'=>$dpsNumber,'total'=>ned_money($doc['total_document']??0),'chave_acesso'=>$key,'codigo_verificacao'=>(string)($doc['verification_code']??'')]);
      $context=$model==='55'?'nfe':($model==='NFSE'?'nfse':'nfce');$pageTitle='Enviar '.$label.' por e-mail';$docLabel=$isNfse?($label.' nº '.$number):($label.' '.trim($series.'/'.$number,'/'));$referenceId=$docId;
      $summary='Cliente: '.$customer.' · Total: '.ned_money($doc['total_document']??0);
      $fallbackSubject=$label.' nº '.$number.' - '.$issuer;
      $fallbackBody=$isNfse?trim("Olá, {$customer}!\n\nSegue em anexo o PDF e o XML da NFS-e emitida pela {$issuer}.\nNFS-e nº {$number}\nDPS: {$series}/{$dpsNumber}\nTotal: ".ned_money($doc['total_document']??0).($key!==''?"\nChave de acesso: {$key}":'').(trim((string)($doc['verification_code']??''))!==''?"\nCódigo de verificação: ".trim((string)$doc['verification_code']):'')."\n\nAtenciosamente,\n{$issuer}"):trim("Olá, {$customer}!\n\nSegue em anexo o PDF".(trim((string)($doc['xml_raw']??''))!==''?' e o XML':'')." da {$label} emitida pela {$issuer}.\nNúmero: {$number}\nSérie: {$series}\nTotal: ".ned_money($doc['total_document']??0).($key!==''?"\nChave de acesso: {$key}":'')."\n\nAtenciosamente,\n{$issuer}");
      if(function_exists('fiscal_company_report_identity'))$doc['_issuer_identity']=fiscal_company_report_identity($pdo,$cid);if($isNfse){$doc['_prestador']=$emit;$doc['_tomador']=$dest;}
      $attachmentLabel=$label.' em PDF'.(trim((string)($doc['xml_raw']??''))!==''?' + XML':'');$payload=['doc'=>$doc,'label'=>$label];
      $returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'fiscal_documentos.php'),'fiscal_documentos.php');
      break;

    case 'boleto':
      require_once __DIR__ . '/../config/boleto_document.php';
      $chargeId=max(0,(int)($_GET['charge_id'] ?? $_POST['charge_id'] ?? 0));$receivableId=max(0,(int)($_GET['receivable_id'] ?? $_POST['receivable_id'] ?? 0));
      $data=boleto_document_load($pdo,$cid,$chargeId,$receivableId);if(!boleto_document_ready($data)) throw new RuntimeException('Este boleto ainda não está pronto para envio.');
      $customer=trim((string)($data['payer_name']??'')) ?: 'cliente';$recipient=strtolower(trim((string)($data['payer_email']??'')));$company=boleto_document_company_name($data);
      $pedido=(int)($data['order_id']??0)>0?'#'.(int)$data['order_id']:'';$contrato=(int)($data['contract_id']??0)>0?'#'.(int)$data['contract_id']:'';
      $vars=array_merge($vars,['empresa'=>$company,'cliente'=>$customer,'email_cliente'=>$recipient,'titulo_id'=>(string)(int)($data['receivable_id']??0),'boleto_id'=>(string)(int)($data['id']??0),'pedido'=>$pedido,'contrato'=>$contrato,'vencimento'=>boleto_document_date($data['due_date']??''),'valor'=>boleto_document_money($data['amount']??0),'nosso_numero'=>trim((string)($data['nosso_numero']??'')),'linha_digitavel'=>trim((string)($data['linha_digitavel']??'')),'pix_copia_cola'=>trim((string)($data['pix_emv']??''))]);
      $context='boleto';$pageTitle='Enviar boleto por e-mail';$docLabel='Boleto da Conta a Receber #'.(int)($data['receivable_id']??0);$referenceId=(int)($data['receivable_id']??0) ?: (int)$data['id'];
      $summary='Cliente: '.$customer.' · Vencimento: '.$vars['vencimento'].' · Valor: '.$vars['valor'];
      $fallbackSubject='Boleto - vencimento '.$vars['vencimento'].' - '.$company;
      $fallbackBody=trim("Olá, {$customer}!\n\nSegue em anexo o boleto emitido pela {$company}.\nVencimento: {$vars['vencimento']}\nValor: {$vars['valor']}".($vars['linha_digitavel']!==''?"\nLinha digitável: {$vars['linha_digitavel']}":'')."\n\nAtenciosamente,\n{$company}");
      $attachmentLabel='Boleto em PDF';$payload=['data'=>$data];
      $returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? 'financeiro_contas_receber.php'),'financeiro_contas_receber.php');
      break;

    case 'recibo':
      require_once __DIR__ . '/../config/finance_receipt.php';
      $batch=preg_replace('/[^a-f0-9]/i','',(string)($_GET['batch'] ?? $_POST['batch'] ?? '')) ?: '';$layout=strtolower((string)($_GET['layout'] ?? $_POST['layout'] ?? 'a4'))==='72'?'72':'a4';
      $ids=finance_receipt_batch_ids($cid,$batch);$data=$ids?finance_receipt_load($pdo,$cid,$ids):[];if(!$data) throw new RuntimeException('Recibo não encontrado ou expirado.');
      $customer=trim((string)($data['customer']['name']??'')) ?: 'cliente';$personId=(int)($data['rows'][0]['person_id']??0);$recipient=ned_person_email($pdo,$cid,$personId);
      $date=ned_date($data['settlement_date']??'');$value=ned_money($data['total']??0);$receiptNo=(string)($data['receipt_number']??'Recibo');
      $vars=array_merge($vars,['cliente'=>$customer,'email_cliente'=>$recipient,'recibo_id'=>$receiptNo,'data'=>$date,'valor'=>$value]);
      $context='recibo';$pageTitle='Enviar recibo por e-mail';$docLabel=$receiptNo;$referenceId=(int)($data['rows'][0]['settlement_id']??0);
      $summary='Cliente: '.$customer.' · Data: '.$date.' · Total: '.$value;
      $fallbackSubject=$receiptNo.' - '.$companyName;$fallbackBody=trim("Olá, {$customer}!\n\nSegue em anexo o recibo da baixa realizada.\nRecibo: {$receiptNo}\nData: {$date}\nTotal recebido: {$value}\n\nAtenciosamente,\n{$companyName}");
      $attachmentLabel=$layout==='72'?'Recibo 72 mm em PDF':'Recibo A4 em PDF';$payload=['data'=>$data,'batch'=>$batch,'layout'=>$layout];
      $returnTo=ned_safe_return((string)($_GET['return_to'] ?? $_POST['return_to'] ?? ('financeiro_recibo_baixa.php?batch='.rawurlencode($batch).'&layout='.rawurlencode($layout))),'financeiro_contas_receber.php');
      break;

    default: throw new RuntimeException('Tipo de documento não suportado para envio por e-mail.');
  }
} catch(Throwable $e) {
  if ($emailCrossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
  admin_topbar('Enviar por e-mail','');
  echo '<div style="max-width:900px;margin:18px auto;background:#fff;border:1px solid #fecaca;border-radius:18px;padding:18px;color:#991b1b;font-weight:800">'.ned_h($e->getMessage()).'<div style="margin-top:14px"><a class="btn" href="'.ned_h($returnTo).'">Voltar</a></div></div>';
  admin_footer(); exit;
}

if ($emailCrossContext && function_exists('multi_company_context_url')) $returnTo = multi_company_context_url($returnTo);
$emailSchemaReady = true;
$canChooseSmtpUser = (function_exists('access_is_company_master') && access_is_company_master())
  || (function_exists('access_has_permission') && access_has_permission($pdo,(int)COMPANY_ID,'usuarios'));
$smtpSenderCandidates = $canChooseSmtpUser ? ned_email_sender_candidates($pdo,$cid) : [];
$requestedSmtpUserId = max(0,(int)($_POST['smtp_user_id'] ?? $_GET['smtp_user_id'] ?? 0));
$smtpUserId = $uid;
if($requestedSmtpUserId>0 && ($requestedSmtpUserId===$uid || ($canChooseSmtpUser && isset($smtpSenderCandidates[$requestedSmtpUserId])))){
  $smtpUserId=$requestedSmtpUserId;
}
try {
  $settings = $smtpUserId>0 ? email_settings_load($pdo,$cid,$smtpUserId,$emailCrossContext) : email_settings_default();
  if(!email_settings_ready($settings) && $canChooseSmtpUser && $smtpSenderCandidates){
    if(isset($smtpSenderCandidates[$uid])){
      $smtpUserId=$uid;
    } elseif($requestedSmtpUserId>0 && isset($smtpSenderCandidates[$requestedSmtpUserId])){
      $smtpUserId=$requestedSmtpUserId;
    } else {
      $smtpUserId=(int)array_key_first($smtpSenderCandidates);
    }
    $settings=email_settings_load($pdo,$cid,$smtpUserId,$emailCrossContext);
  }
} catch (Throwable $e) {
  $settings = email_settings_default();
  $emailSchemaReady = false;
}
$emailReady = $emailSchemaReady && $smtpUserId>0 && email_settings_ready($settings);
$smtpSenderName='Usuário atual';
$smtpSenderEmail='';
if($smtpUserId>0){
  try{
    $st=$pdo->prepare('SELECT name,email FROM users WHERE id=? AND company_id=? LIMIT 1');
    $st->execute([$smtpUserId,$cid]);
    $smtpSenderRow=$st->fetch(PDO::FETCH_ASSOC) ?: [];
    $smtpSenderName=trim((string)($smtpSenderRow['name']??'')) ?: ('Usuário #'.$smtpUserId);
    $smtpSenderEmail=strtolower(trim((string)($smtpSenderRow['email']??'')));
  }catch(Throwable $e){}
}
$smtpSaved = false;
$smtpErr = '';
if ($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='save_smtp') {
  if (!$emailCrossContext) {
    $smtpErr = 'A configuração rápida de SMTP desta tela é exclusiva do contexto Multiempresa.';
  } elseif (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf']??''))) {
    $smtpErr = 'V11.23: sessão de segurança expirada. Atualize a página antes de salvar o SMTP.';
  } elseif (!$emailSchemaReady) {
    $smtpErr = 'V11.23: a estrutura de configuração de e-mail não está disponível. Atualize o banco antes de configurar o SMTP.';
  } elseif ($uid <= 0) {
    $smtpErr = 'Usuário autenticado inválido para configuração de e-mail.';
  } else {
    try {
      email_settings_save($pdo,$cid,$uid,[
        'enabled'=>!empty($_POST['smtp_enabled']),
        'smtp_host'=>trim((string)($_POST['smtp_host']??'')),
        'smtp_port'=>(int)($_POST['smtp_port']??587),
        'smtp_security'=>(string)($_POST['smtp_security']??'tls'),
        'smtp_username'=>trim((string)($_POST['smtp_username']??'')),
        'smtp_password'=>(string)($_POST['smtp_password']??''),
        'from_email'=>strtolower(trim((string)($_POST['from_email']??''))),
        'from_name'=>trim((string)($_POST['from_name']??'')),
        'reply_to'=>strtolower(trim((string)($_POST['reply_to']??''))),
        'timeout_seconds'=>(int)($_POST['timeout_seconds']??30),
      ], true);
      $smtpUserId=$uid;
      $settings=email_settings_load($pdo,$cid,$smtpUserId,true);
      $emailReady=email_settings_ready($settings);
      $smtpSenderName=$userName;
      $smtpSaved=true;
    } catch (Throwable $e) {
      $smtpErr=$e->getMessage();
    }
  }
}
$state=email_message_models_load($pdo,$cid);$models=[];
foreach((array)$state['items'] as $item){if(!empty($item['active']) && (string)($item['context']??'')===$context)$models[]=$item;}
$defaultModel=(int)($state['defaults'][$context]??0);
$selectedModel=max(0,(int)($_POST['model_id'] ?? $_GET['model_id'] ?? $defaultModel));
$rendered=[];$rendered[0]=email_message_model_render($pdo,$cid,$context,$vars,0,$fallbackSubject,$fallbackBody);
foreach($models as $m){$mid=(int)$m['id'];$rendered[$mid]=email_message_model_render($pdo,$cid,$context,$vars,$mid,$fallbackSubject,$fallbackBody);}
if(!isset($rendered[$selectedModel]))$selectedModel=isset($rendered[$defaultModel])?$defaultModel:0;

$subject=(string)($rendered[$selectedModel]['subject']??$fallbackSubject);$body=(string)($rendered[$selectedModel]['body']??$fallbackBody);
if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='send'){
  $recipient=strtolower(trim((string)($_POST['recipient']??$recipient)));
  $subject=trim((string)($_POST['subject']??$subject));$body=trim((string)($_POST['body']??$body));
}
$err='';$sent=false;
if($_SERVER['REQUEST_METHOD']==='POST' && (string)($_POST['action']??'')==='send'){
  if($emailCrossContext && (!function_exists('multi_company_context_csrf_valid') || !multi_company_context_csrf_valid((string)($_POST['mc_csrf']??'')))) $err='V11.23: sessão de segurança expirada. Atualize a página antes de enviar o documento.';
  elseif(!$emailSchemaReady) $err='V11.23: a estrutura de configuração/log de e-mail não está disponível. Atualize o banco antes de enviar pelo F11.';
  elseif(!$emailReady) $err=$emailCrossContext ? 'O SMTP deste usuário ainda não está configurado para a empresa selecionada pelo F11. Use a configuração SMTP desta tela.' : 'As configurações SMTP do usuário atual estão incompletas ou desativadas.';
  elseif(!filter_var($recipient,FILTER_VALIDATE_EMAIL)) $err='Informe um destinatário de e-mail válido.';
  elseif($subject==='') $err='Informe o assunto do e-mail.';
  elseif($body==='') $err='Informe a mensagem do e-mail.';
  elseif($type==='pedido' && empty($_POST['attach_pedido']) && empty($_POST['attach_fiscal']) && empty($_POST['attach_boleto'])) $err='Selecione pelo menos um documento para anexar.';
  elseif($type==='pedido' && !empty($_POST['attach_fiscal']) && !$orderDocuments['fiscal']) $err='Não há nota fiscal autorizada disponível para este pedido.';
  elseif($type==='pedido' && !empty($_POST['attach_boleto']) && !$orderDocuments['boleto']) $err='Não há boleto vinculado disponível para este pedido.';
  else{
    try{
      $attachments=[];
      if($type==='pedido'){
        if(!empty($_POST['attach_pedido'])) $attachments[]=order_email_pdf_build((array)$payload['order'],(array)$payload['items'],$companyName,'a4',$pdo,$cid);
        if(!empty($_POST['attach_fiscal']) && $orderDocuments['fiscal']){
          require_once __DIR__ . '/../config/fiscal_document_pdf.php';
          foreach($orderDocuments['fiscal'] as $frow){
            $fid=(int)$frow['id'];
            $st=$pdo->prepare('SELECT d.*,n.provider_code,n.dps_series,n.dps_number,n.nfse_number,n.verification_code,n.response_xml FROM fiscal_documents d LEFT JOIN fiscal_nfse_details n ON n.company_id=d.company_id AND n.fiscal_document_id=d.id WHERE d.company_id=? AND d.id=? LIMIT 1');
            $st->execute([$cid,$fid]);$fdoc=$st->fetch(PDO::FETCH_ASSOC)?:[];
            if(!$fdoc)continue;
            $attachments[]=fiscal_document_pdf_build($fdoc);
            $xml=trim((string)($fdoc['xml_raw']??''));
            if($xml!=='')$attachments[]=['filename'=>'nota-'.$fid.'.xml','mimetype'=>'application/xml','content'=>$xml];
          }
        }
        if(!empty($_POST['attach_boleto']) && $orderDocuments['boleto']){
          require_once __DIR__ . '/../config/boleto_document.php';
          foreach($orderDocuments['boleto'] as $bid){
            $data=boleto_document_load($pdo,$cid,(int)$bid,0);
            if(!boleto_document_ready($data))continue;
            $attachments[]=['filename'=>'boleto-'.(int)$bid.'.pdf','mimetype'=>'application/pdf','content'=>boleto_document_pdf($data)];
          }
        }
        if(!$attachments)throw new RuntimeException('Não há documentos disponíveis para a seleção informada.');
      } elseif($type==='orcamento'){
        $attachments[]=nexa_quote_document_pdf_build((array)$payload['data'],(string)$payload['model']);
      } elseif($type==='os'){
        $attachments[]=nexa_os_document_pdf_build((array)$payload['data'],(string)$payload['model'],(string)$payload['os_share_options']['conteudo'],(string)$payload['os_share_options']['papel'],(string)$payload['os_share_options']['perfil_impressao']);
      } elseif($type==='fiscal'){
        $pdf=fiscal_document_pdf_build((array)$payload['doc']);$attachments[]=$pdf;
        $xml=trim((string)($payload['doc']['xml_raw']??''));
        if($xml!==''){$rawNum=(strtoupper((string)($payload['doc']['model']??''))==='NFSE' && trim((string)($payload['doc']['nfse_number']??''))!=='')?(string)$payload['doc']['nfse_number']:(string)($payload['doc']['number']??$referenceId);$num=preg_replace('/[^0-9A-Za-z_-]+/','-',$rawNum);$attachments[]=['filename'=>strtolower((string)$payload['label']).'-'.$num.'.xml','mimetype'=>'application/xml','content'=>$xml];}
      } elseif($type==='boleto'){
        $pdfContent=boleto_document_pdf((array)$payload['data']);$rid=(int)($payload['data']['receivable_id']??0) ?: (int)($payload['data']['id']??0);$attachments[]=['filename'=>'boleto-'.$rid.'.pdf','mimetype'=>'application/pdf','content'=>$pdfContent];
      } elseif($type==='recibo'){
        $attachments[]=finance_receipt_pdf_build((array)$payload['data'],(string)$payload['layout']);
      }
      $result=email_send_for_user($pdo,$cid,$smtpUserId,[['email'=>$recipient,'name'=>(string)($vars['cliente']??'')]],$subject,$body,$attachments,$context,$referenceId,$emailCrossContext);
      if(!empty($result['ok']))$sent=true;else $err=trim((string)($result['error']??'')) ?: 'Não foi possível enviar o e-mail.';
    }catch(Throwable $e){$err=$e->getMessage();}
  }
}

$nav = $type==='fiscal' ? 'fiscal_documentos' : ($type==='boleto'||$type==='recibo' ? 'fin_receber' : ($type==='orcamento' ? 'orcamentos' : ($type==='os' ? 'os_monitor' : 'pedidos')));
admin_topbar($pageTitle,$nav);
?>
<style>
.ned-wrap{max-width:900px;margin:16px auto 28px;display:grid;gap:14px}.ned-card{background:#fff;border:1px solid #dbe4f0;border-radius:22px;padding:18px;box-shadow:0 14px 34px rgba(11,31,91,.07)}.ned-head{display:flex;justify-content:space-between;gap:14px;align-items:flex-start;flex-wrap:wrap}.ned-head h1{margin:0;color:var(--admin-primary,#0b1f5b);font-size:26px}.ned-muted{color:#64748b;line-height:1.5}.ned-file{display:flex;gap:12px;align-items:center;border:1px solid #dbe4f0;background:#f8fafc;border-radius:15px;padding:12px;margin:12px 0}.ned-icon{width:46px;height:46px;border-radius:13px;background:#eff6ff;color:#1d4ed8;display:grid;place-items:center;font-weight:950;font-size:20px}.ned-form{display:grid;gap:12px}.ned-form label{display:grid;gap:6px;font-weight:850;color:#0f172a}.ned-form input,.ned-form select,.ned-form textarea{width:100%;border:1px solid #dbe4f0;border-radius:14px;padding:11px 12px;font:inherit}.ned-form textarea{min-height:210px;resize:vertical;line-height:1.45}.ned-actions{display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap;border-top:1px solid #eef2f7;padding-top:14px}.ned-smtp{border:1px solid #dbe4f0;border-radius:16px;padding:12px;background:#f8fafc}.ned-smtp summary{cursor:pointer;font-weight:900;color:#0f172a}.ned-smtp-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px;margin-top:12px}.ned-smtp-grid .span-2{grid-column:1/-1}.ned-smtp-grid label{display:grid;gap:5px;font-weight:800}.ned-smtp-grid input,.ned-smtp-grid select{width:100%;border:1px solid #dbe4f0;border-radius:12px;padding:9px 10px;background:#fff}.ned-email-btn{background:#2563eb!important;color:#fff!important;border-color:#1d4ed8!important}.ned-note{border-radius:14px;padding:11px 13px;line-height:1.45}.ned-note.warn{background:#fff7ed;border:1px solid #fed7aa;color:#9a3412}.ned-note.ok{background:#ecfdf5;border:1px solid #a7f3d0;color:#047857}.ned-note.err{background:#fef2f2;border:1px solid #fecaca;color:#b91c1c}.ned-model-default{font-size:12px;color:#64748b;margin-top:-4px}@media(max-width:640px){.ned-actions .btn{width:100%}.ned-smtp-grid{grid-template-columns:1fr}.ned-smtp-grid .span-2{grid-column:1}}
</style>
<div class="ned-wrap">
  <section class="ned-card">
    <div class="ned-head"><div><h1><?= ned_h($pageTitle) ?></h1><div class="ned-muted" style="margin-top:5px"><?= ned_h($summary) ?></div></div><a class="btn" href="<?= ned_h($returnTo) ?>">Voltar</a></div>
    <div class="ned-file"><div class="ned-icon">✉</div><div><strong><?= ned_h($docLabel) ?></strong><div class="ned-muted"><?= $type==='pedido' ? 'Selecione abaixo quais documentos serão anexados.' : ned_h($attachmentLabel).' será anexado automaticamente.' ?></div></div></div>
    <?php if($sent): ?><div class="ned-note ok"><b>E-mail enviado com sucesso.</b><br>Destinatário: <?= ned_h($recipient) ?></div><?php endif; ?>
    <?php if($smtpSaved): ?><div class="ned-note ok"><b>SMTP salvo para a empresa selecionada.</b> Esta configuração pertence somente ao seu usuário nesta empresa.</div><?php endif; ?>
    <?php if($smtpErr!==''): ?><div class="ned-note err"><b>Falha ao salvar SMTP:</b> <?= ned_h($smtpErr) ?></div><?php endif; ?>
    <?php if($err!==''): ?><div class="ned-note err"><b>Falha no envio:</b> <?= ned_h($err) ?></div><?php endif; ?>
    <?php if(!$emailReady): ?><div class="ned-note warn"><b>Nenhuma configuração SMTP válida foi encontrada para este envio<?= $emailCrossContext ? ' nesta empresa' : '' ?>.</b> <?= $canChooseSmtpUser ? 'Configure um SMTP em Usuários ou selecione uma conta de envio válida abaixo.' : ($emailCrossContext ? 'No F11 o Nexa usa somente a configuração SMTP vinculada ao seu usuário na empresa selecionada.' : 'O envio usa a configuração SMTP do usuário que está logado. Solicite ao administrador que configure e teste este usuário em Usuários.') ?></div><?php else: ?><div class="ned-note ok" style="margin-bottom:0"><b>Conta de envio:</b> <?= ned_h($smtpSenderName) ?><?= $smtpSenderEmail!=='' ? ' · '.ned_h($smtpSenderEmail) : '' ?><?= $smtpUserId!==$uid ? ' · selecionada pelo administrador' : '' ?></div><?php endif; ?>
    <?php if($emailCrossContext): ?>
      <details class="ned-smtp" <?= !$emailReady || $smtpErr!=='' ? 'open' : '' ?>>
        <summary>Configuração SMTP do meu usuário nesta empresa</summary>
        <form method="post">
          <input type="hidden" name="action" value="save_smtp"><input type="hidden" name="type" value="<?= ned_h($type) ?>"><input type="hidden" name="return_to" value="<?= ned_h($returnTo) ?>"><input type="hidden" name="mc_csrf" value="<?= ned_h($emailMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= ned_h($emailMcCtx) ?>">
          <?php if($type==='boleto'): ?><input type="hidden" name="charge_id" value="<?= (int)($payload['data']['id']??0) ?>"><input type="hidden" name="receivable_id" value="<?= (int)($payload['data']['receivable_id']??0) ?>"><?php endif; ?>
          <?php if($type==='recibo'): ?><input type="hidden" name="batch" value="<?= ned_h($payload['batch']) ?>"><input type="hidden" name="layout" value="<?= ned_h($payload['layout']) ?>"><?php endif; ?>
          <div class="ned-smtp-grid">
            <label class="span-2" style="display:flex;grid-template-columns:auto 1fr;align-items:center"><input type="checkbox" name="smtp_enabled" value="1" style="width:auto" <?= !empty($settings['enabled'])?'checked':'' ?>><span>Habilitar envio por e-mail nesta empresa</span></label>
            <label>Servidor SMTP<input name="smtp_host" value="<?= ned_h($settings['smtp_host']??'') ?>" placeholder="smtp.seudominio.com.br"></label>
            <label>Porta<input type="number" name="smtp_port" min="1" max="65535" value="<?= (int)($settings['smtp_port']??587) ?>"></label>
            <label>Segurança<select name="smtp_security"><option value="tls" <?= ($settings['smtp_security']??'tls')==='tls'?'selected':'' ?>>TLS</option><option value="ssl" <?= ($settings['smtp_security']??'')==='ssl'?'selected':'' ?>>SSL</option><option value="none" <?= ($settings['smtp_security']??'')==='none'?'selected':'' ?>>Sem criptografia</option></select></label>
            <label>Usuário SMTP<input name="smtp_username" value="<?= ned_h($settings['smtp_username']??'') ?>" autocomplete="off"></label>
            <label>Senha SMTP<input type="password" name="smtp_password" autocomplete="new-password" placeholder="<?= !empty($settings['smtp_password_secret'])?'Senha já configurada — deixe em branco para manter':'Informe a senha' ?>"></label>
            <label>E-mail remetente<input type="email" name="from_email" value="<?= ned_h($settings['from_email']??'') ?>"></label>
            <label>Nome do remetente<input name="from_name" value="<?= ned_h($settings['from_name']??'') ?>"></label>
            <label>E-mail de resposta<input type="email" name="reply_to" value="<?= ned_h($settings['reply_to']??'') ?>"></label>
            <label>Timeout (segundos)<input type="number" name="timeout_seconds" min="10" max="120" value="<?= (int)($settings['timeout_seconds']??30) ?>"></label>
            <div class="span-2 ned-actions"><button class="btn" type="submit">Salvar SMTP desta empresa</button></div>
          </div>
        </form>
      </details>
    <?php endif; ?>
    <form method="post" class="ned-form" id="ned-email-form">
      <input type="hidden" name="action" value="send"><input type="hidden" name="type" value="<?= ned_h($type) ?>"><input type="hidden" name="return_to" value="<?= ned_h($returnTo) ?>"><?php if($emailCrossContext): ?><input type="hidden" name="mc_csrf" value="<?= ned_h($emailMcCsrf) ?>"><input type="hidden" name="mc_ctx" value="<?= ned_h($emailMcCtx) ?>"><?php endif; ?>
      <?php if($type==='pedido'): ?><input type="hidden" name="id" value="<?= (int)$referenceId ?>"><?php endif; ?>
      <?php if($type==='orcamento'||$type==='os'): ?><input type="hidden" name="id" value="<?= (int)$referenceId ?>"><input type="hidden" name="modelo" value="<?= ned_h($payload['model']??'') ?>"><?php endif; ?><?php if($type==='os'): ?><input type="hidden" name="conteudo" value="<?= ned_h($payload['os_share_options']['conteudo']) ?>"><input type="hidden" name="papel" value="<?= ned_h($payload['os_share_options']['papel']) ?>"><input type="hidden" name="perfil_impressao" value="<?= ned_h($payload['os_share_options']['perfil_impressao']) ?>"><?php endif; ?>
      <?php if($type==='fiscal'): ?><input type="hidden" name="doc_id" value="<?= (int)$referenceId ?>"><?php endif; ?>
      <?php if($type==='boleto'): ?><input type="hidden" name="charge_id" value="<?= (int)($payload['data']['id']??0) ?>"><input type="hidden" name="receivable_id" value="<?= (int)($payload['data']['receivable_id']??0) ?>"><?php endif; ?>
      <?php if($type==='recibo'): ?><input type="hidden" name="batch" value="<?= ned_h($payload['batch']) ?>"><input type="hidden" name="layout" value="<?= ned_h($payload['layout']) ?>"><?php endif; ?>
      <?php if($canChooseSmtpUser && $smtpSenderCandidates): ?>
        <label>Conta de envio
          <select name="smtp_user_id">
            <?php foreach($smtpSenderCandidates as $senderId=>$sender): ?>
              <option value="<?= (int)$senderId ?>" <?= $smtpUserId===(int)$senderId?'selected':'' ?>><?= ned_h(trim((string)($sender['name']??'')) ?: ('Usuário #'.(int)$senderId)) ?><?= trim((string)($sender['from_email']??''))!=='' ? ' — '.ned_h((string)$sender['from_email']) : '' ?></option>
            <?php endforeach; ?>
          </select>
        </label>
      <?php else: ?>
        <input type="hidden" name="smtp_user_id" value="<?= (int)$smtpUserId ?>">
      <?php endif; ?>
      <?php if($type==='pedido'): ?>
        <div class="ned-file" style="display:block"><strong>Documentos para anexar</strong><div class="ned-muted">Marque os documentos que deseja enviar. Apenas documentos disponíveis são oferecidos.</div>
          <div style="display:flex;gap:16px;flex-wrap:wrap;margin-top:10px">
            <label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="attach_pedido" value="1" <?= $_SERVER['REQUEST_METHOD']!=='POST'||!empty($_POST['attach_pedido'])?'checked':'' ?>> Pedido (PDF)</label>
            <?php if($orderDocuments['fiscal']): ?><label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="attach_fiscal" value="1" <?= $_SERVER['REQUEST_METHOD']!=='POST'||!empty($_POST['attach_fiscal'])?'checked':'' ?>> Nota fiscal (PDF e XML, quando disponível) — <?= count($orderDocuments['fiscal']) ?></label><?php endif; ?>
            <?php if($orderDocuments['boleto']): ?><label style="display:flex;align-items:center;gap:6px"><input type="checkbox" name="attach_boleto" value="1" <?= $_SERVER['REQUEST_METHOD']!=='POST'||!empty($_POST['attach_boleto'])?'checked':'' ?>> Boleto(s) — <?= count($orderDocuments['boleto']) ?></label><?php endif; ?>
          </div>
        </div>
      <?php endif; ?>
      <label>Enviar para<input type="email" name="recipient" value="<?= ned_h($recipient) ?>" placeholder="cliente@exemplo.com" required autofocus></label>
      <label>Modelo da mensagem<select name="model_id" id="ned-model"><option value="0" <?= $selectedModel===0?'selected':'' ?>>Mensagem padrão do sistema</option><?php foreach($models as $m): ?><option value="<?= (int)$m['id'] ?>" <?= $selectedModel===(int)$m['id']?'selected':'' ?>><?= ned_h($m['title']) ?><?= $defaultModel===(int)$m['id']?' — padrão':'' ?></option><?php endforeach; ?></select></label>
      <?php if(!$models): ?><div class="ned-model-default">Nenhum modelo de e-mail ativo para <?= ned_h(email_message_model_contexts()[$context] ?? $context) ?>. A mensagem padrão será usada.</div><?php endif; ?>
      <label>Assunto<input name="subject" id="ned-subject" maxlength="255" value="<?= ned_h($subject) ?>" required></label>
      <label>Mensagem<textarea name="body" id="ned-body" maxlength="20000" required><?= ned_h($body) ?></textarea></label>
      <div class="ned-actions"><a class="btn" href="<?= ned_h($returnTo) ?>">Cancelar</a><button class="btn ned-email-btn" type="submit" <?= !$emailReady?'disabled':'' ?>>✉ Enviar e-mail</button></div>
    </form>
  </section>
</div>
<script>
(function(){
  const select=document.getElementById('ned-model'),subject=document.getElementById('ned-subject'),body=document.getElementById('ned-body');
  if(!select||!subject||!body)return;
  const rendered=<?= json_encode($rendered,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) ?>;
  select.addEventListener('change',function(){const r=rendered[String(this.value)]||rendered[this.value]||rendered[0];if(!r)return;subject.value=r.subject||'';body.value=r.body||'';});
})();
</script>
<?php admin_footer(); ?>
