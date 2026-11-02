<?php
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/os_module.php';
require_once __DIR__ . '/../config/os_extra_options.php';
require_once __DIR__ . '/../config/os_step_visibility.php';
require_once __DIR__ . '/../config/os_customer_communication.php';
require_once __DIR__ . '/_os_ui.php';
require_once __DIR__ . '/_os_vehicle_damage_ui.php';

$pdo=os_db(); $companyId=os_company_id(); os_require_enabled($pdo,$companyId); os_ensure_defaults($pdo,$companyId);
$userId=os_user_id(); $id=(int)(isset($_GET['id'])?$_GET['id']:(isset($_POST['order_id'])?$_POST['order_id']:0));
$step=(int)(isset($_GET['step'])?$_GET['step']:(isset($_POST['step'])?$_POST['step']:1)); if($step<1||$step>5)$step=1; if($id<=0)$step=1;
$error=''; $ok=isset($_GET['ok'])?$_GET['ok']:'';

function os_edit_redirect($id,$msg,$step=1,$anchor='',$continueItem=false){ $url='os_editar.php?id='.(int)$id.'&step='.(int)$step.'&ok='.urlencode($msg); if($continueItem)$url.='&continue_item=1'; if($anchor!=='')$url.='#'.ltrim($anchor,'#'); header('Location: '.$url); exit; }
function os_edit_exit_requested(){return isset($_POST['save_exit']) && (string)$_POST['save_exit']==='1';}
function os_edit_exit($msg){header('Location: os_monitor.php?ok='.urlencode($msg));exit;}
function os_fetch_order(PDO $pdo,$companyId,$id){$s=$pdo->prepare("SELECT o.*,st.name status_name,st.color status_color,st.code status_code FROM os_orders o LEFT JOIN os_statuses st ON st.id=o.status_id AND st.company_id=o.company_id WHERE o.company_id=? AND o.id=? LIMIT 1");$s->execute(array((int)$companyId,(int)$id));return $s->fetch(PDO::FETCH_ASSOC);}
$stmt=$pdo->prepare("SELECT * FROM os_settings WHERE company_id=? LIMIT 1");$stmt->execute(array($companyId));$settings=$stmt->fetch(PDO::FETCH_ASSOC)?:array();
$osExtra=os_extra_options($pdo,(int)$companyId);
$stepVisibility=os_step_visibility($pdo,(int)$companyId);
$showVehicle=(bool)$stepVisibility['show_vehicle_step'];
$showDamage=(bool)$stepVisibility['show_damage_step'];
if(!$showDamage && $step===3) $step=4;

if($_SERVER['REQUEST_METHOD']==='POST'){
 $action=isset($_POST['action'])?$_POST['action']:'';
 try{
  if($action==='save_order'){
   $personId=(int)(isset($_POST['person_id'])?$_POST['person_id']:0);
   $statusId=(int)(isset($_POST['status_id'])?$_POST['status_id']:0);
   $contactName=trim(isset($_POST['contact_name'])?$_POST['contact_name']:'');
   $contactPhone=trim(isset($_POST['contact_phone'])?$_POST['contact_phone']:'');
   $expected=trim(isset($_POST['expected_at'])?$_POST['expected_at']:'');
   if (!empty($osExtra['require_contact_name']) && $contactName==='') throw new RuntimeException('Informe o contato da O.S.');
   if (!empty($osExtra['require_contact_phone']) && $contactPhone==='') throw new RuntimeException('Informe o telefone/WhatsApp da O.S.');
   list($pid,$pname,$pdoc,$pphone)=os_build_order_snapshot($pdo,$companyId,$personId);

   $fs=$pdo->prepare("SELECT * FROM os_custom_fields WHERE company_id=? AND active=1 ORDER BY sort_order,id");$fs->execute(array($companyId));$activeFields=$fs->fetchAll(PDO::FETCH_ASSOC);
   $semanticValues=array();
   foreach(($showVehicle?$activeFields:array()) as $f){
    $key='field_'.$f['id'];$v=isset($_POST[$key])?$_POST[$key]:'';if($f['input_type']==='checkbox')$v=isset($_POST[$key])?'1':'0';
    if((int)$f['required']===1 && trim((string)$v)==='')throw new RuntimeException('Preencha o campo obrigatório: '.$f['label']);
    if(isset($f['semantic_role']) && trim((string)$f['semantic_role'])!=='')$semanticValues[(string)$f['semantic_role']]=trim((string)$v);
   }

   $pdo->beginTransaction();
   $oldStatus=0;
   if($id>0){
    $current=os_fetch_order($pdo,$companyId,$id); if(!$current)throw new RuntimeException('O.S. não encontrada.'); $oldStatus=(int)$current['status_id'];
    $stmt=$pdo->prepare("UPDATE os_orders SET person_id=?,person_name_snapshot=?,person_document_snapshot=?,person_phone_snapshot=?,contact_name=?,contact_phone=?,status_id=?,expected_at=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?");
    $stmt->execute(array($pid,$pname,$pdoc,$pphone,$contactName!==''?$contactName:null,$contactPhone!==''?$contactPhone:null,$statusId>0?$statusId:null,$expected!==''?str_replace('T',' ',$expected):null,$userId,$companyId,$id));
   }else{
    $number=os_next_number($pdo,$companyId); if($statusId<=0)$statusId=os_initial_status_id($pdo,$companyId);
    $stmt=$pdo->prepare("INSERT INTO os_orders (company_id,os_number,person_id,person_name_snapshot,person_document_snapshot,person_phone_snapshot,contact_name,contact_phone,status_id,expected_at,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
    $stmt->execute(array($companyId,$number,$pid,$pname,$pdoc,$pphone,$contactName!==''?$contactName:null,$contactPhone!==''?$contactPhone:null,$statusId>0?$statusId:null,$expected!==''?str_replace('T',' ',$expected):null,$userId,$userId));
    $id=(int)$pdo->lastInsertId(); $oldStatus=0;
   }
   $up=$pdo->prepare("INSERT INTO os_order_field_values (company_id,order_id,field_id,value_text,created_at) VALUES (?,?,?,?,NOW()) ON DUPLICATE KEY UPDATE value_text=VALUES(value_text),updated_at=NOW()");
   foreach(($showVehicle?$activeFields:array()) as $f){$key='field_'.$f['id'];$v=$f['input_type']==='checkbox'?(isset($_POST[$key])?'1':'0'):(isset($_POST[$key])?trim((string)$_POST[$key]):'');$up->execute(array($companyId,$id,(int)$f['id'],$v));}
   if($semanticValues)os_vehicle_profile_save($pdo,$companyId,$id,$pid,$semanticValues);
   if($statusId>0 && $statusId!==$oldStatus){$h=$pdo->prepare("INSERT INTO os_status_history (company_id,order_id,from_status_id,to_status_id,user_id,created_at) VALUES (?,?,?,?,?,NOW())");$h->execute(array($companyId,$id,$oldStatus>0?$oldStatus:null,$statusId,$userId));$cs=$pdo->prepare("SELECT code,is_final FROM os_statuses WHERE company_id=? AND id=? LIMIT 1");$cs->execute(array($companyId,$statusId));$newSt=$cs->fetch(PDO::FETCH_ASSOC);if($newSt && $newSt['code']==='CANCELADA'){os_release_order_reservations($pdo,$companyId,$id);$pdo->prepare("UPDATE os_orders SET billing_status='CANCELADA',closed_at=NOW() WHERE company_id=? AND id=?")->execute(array($companyId,$id));}elseif($newSt && (int)$newSt['is_final']===1){$pdo->prepare("UPDATE os_orders SET closed_at=NOW() WHERE company_id=? AND id=?")->execute(array($companyId,$id));}elseif($newSt){$pdo->prepare("UPDATE os_orders SET closed_at=NULL WHERE company_id=? AND id=?")->execute(array($companyId,$id));}}
   $statusChangedForCustomer=($oldStatus>0 && $statusId>0 && $statusId!==$oldStatus && os_status_notify_enabled($pdo,(int)$companyId,$statusId));
   $pdo->commit();
   // Mesmo em "Salvar e sair", oferecer o aviso antes de retornar ao Monitor.
   if($statusChangedForCustomer){header('Location: os_editar.php?id='.(int)$id.'&step=1&ok='.urlencode('Status da O.S. atualizado.').'&notify_status=1&after_notify='.(os_edit_exit_requested()?'monitor':'editar'));exit;}
   if(os_edit_exit_requested())os_edit_exit('O.S. salva com sucesso.');
   os_edit_redirect($id,'Etapa 1 salva. Continue com o atendimento.',2);
  }

  if($action==='save_attendance'){
   if($id<=0)throw new RuntimeException('Salve os dados da O.S. antes de continuar.');
   $reported=trim(isset($_POST['reported_problem'])?$_POST['reported_problem']:'');
   $diagnosis=trim(isset($_POST['diagnosis'])?$_POST['diagnosis']:'');
   $technical=trim(isset($_POST['technical_notes'])?$_POST['technical_notes']:'');
   $customerNotes=trim(isset($_POST['customer_notes'])?$_POST['customer_notes']:'');
   $stmt=$pdo->prepare("UPDATE os_orders SET reported_problem=?,diagnosis=?,technical_notes=?,customer_notes=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?");
   $stmt->execute(array($reported!==''?$reported:null,$diagnosis!==''?$diagnosis:null,$technical!==''?$technical:null,$customerNotes!==''?$customerNotes:null,$userId,$companyId,$id));
   if(os_edit_exit_requested())os_edit_exit('O.S. salva com sucesso.');
   os_edit_redirect($id,'Atendimento salvo. Continue com produtos e serviços.', $showDamage?3:4);
  }

  if($action==='save_damage'){
   if($id<=0)throw new RuntimeException('O.S. inválida.');
   if($showDamage && os_vehicle_damage_enabled((array)$settings)){
    $pdo->beginTransaction();
    os_vehicle_damage_replace($pdo,$companyId,$id,isset($_POST['damage_points_json'])?$_POST['damage_points_json']:'[]');
    $pdo->commit(); os_vehicle_damage_flush_photo_deletes();
   }
   if(os_edit_exit_requested())os_edit_exit('O.S. salva com sucesso.');
   os_edit_redirect($id,'Etapa 3 salva. Continue com produtos e serviços.',4);
  }

  if($action==='save_step4'){
   if($id<=0)throw new RuntimeException('O.S. inválida.');
   $discount=os_decimal(isset($_POST['discount_amount'])?$_POST['discount_amount']:0);
   $addition=os_decimal(isset($_POST['addition_amount'])?$_POST['addition_amount']:0);
   $stmt=$pdo->prepare("UPDATE os_orders SET discount_amount=?,addition_amount=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?");
   $stmt->execute(array($discount,$addition,$userId,$companyId,$id));
   os_recalculate_totals($pdo,$companyId,$id);
   if(os_edit_exit_requested())os_edit_exit('O.S. salva com sucesso.');
   os_edit_redirect($id,'Etapa 4 salva. Informe a forma de pagamento, se desejar.',5);
  }

  if($action==='add_item' || $action==='save_item'){
   if($id<=0)throw new RuntimeException('Salve a O.S. antes de inserir itens.');
   $itemId=(int)(isset($_POST['item_id'])?$_POST['item_id']:0);
   $productId=(int)(isset($_POST['produto_id'])?$_POST['produto_id']:0);
   $qty=os_decimal(isset($_POST['quantity'])?$_POST['quantity']:1);
   $price=os_decimal(isset($_POST['unit_price'])?$_POST['unit_price']:0);
   $reserve=isset($_POST['reserve_stock'])?1:0;
   $notes=trim(isset($_POST['notes'])?$_POST['notes']:'');
   $responsibleId=(int)(isset($_POST['responsible_person_id'])?$_POST['responsible_person_id']:(isset($_POST['seller_person_id'])?$_POST['seller_person_id']:0));
   if($productId<=0||$qty<=0)throw new RuntimeException('Selecione um produto/serviço e informe a quantidade.');
   list($responsibleIdDb,$responsibleName)=os_item_responsible_snapshot($pdo,$companyId,$responsibleId);
   $allowOutOfStock=!empty($settings['allow_out_of_stock_reservation']);
   $pdo->beginTransaction();
   $oldItem=null;
   if($action==='save_item'){
    if($itemId<=0)throw new RuntimeException('Item inválido para edição.');
    $s=$pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? AND id=? LIMIT 1 FOR UPDATE");$s->execute(array($companyId,$id,$itemId));$oldItem=$s->fetch(PDO::FETCH_ASSOC);if(!$oldItem)throw new RuntimeException('Item não encontrado.');
    if($oldItem['reservation_status']==='RESERVADO'&&(float)$oldItem['reserved_quantity']>0)os_unreserve_product($pdo,$companyId,$oldItem['produto_id'],$oldItem['reserved_quantity']);
   }
   $s=$pdo->prepare("SELECT id,nome,unidade_estoque,preco,is_service FROM produtos WHERE company_id=? AND id=? AND ativo=1 LIMIT 1 FOR UPDATE");$s->execute(array($companyId,$productId));$p=$s->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Produto/serviço não encontrado.');
   if($price<=0)$price=(float)$p['preco'];$type=(int)$p['is_service']===1?'SERVICO':'PRODUTO';if($type==='SERVICO')$reserve=0;
   $reserved=0;$resStatus='NAO_RESERVADO';if($reserve){os_reserve_product($pdo,$companyId,$productId,$qty,$allowOutOfStock);$reserved=$qty;$resStatus='RESERVADO';}
   $total=round($qty*$price,2);
   if($action==='save_item'){
    $up=$pdo->prepare("UPDATE os_order_items SET produto_id=?,item_type=?,description_snapshot=?,unidade_snapshot=?,quantity=?,unit_price=?,total=?,reserve_stock=?,reserved_quantity=?,reservation_status=?,seller_person_id=?,seller_name_snapshot=?,technician_person_id=NULL,technician_name_snapshot=NULL,notes=?,updated_at=NOW() WHERE company_id=? AND order_id=? AND id=?");
    $up->execute(array($productId,$type,$p['nome'],$p['unidade_estoque'],$qty,$price,$total,$reserve,$reserved,$resStatus,$responsibleIdDb,$responsibleName,$notes!==''?$notes:null,$companyId,$id,$itemId));
   }else{
    $ins=$pdo->prepare("INSERT INTO os_order_items (company_id,order_id,produto_id,item_type,description_snapshot,unidade_snapshot,quantity,unit_price,total,reserve_stock,reserved_quantity,reservation_status,seller_person_id,seller_name_snapshot,technician_person_id,technician_name_snapshot,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,?)");
    $ins->execute(array($companyId,$id,$productId,$type,$p['nome'],$p['unidade_estoque'],$qty,$price,$total,$reserve,$reserved,$resStatus,$responsibleIdDb,$responsibleName,$notes!==''?$notes:null));
   }
   os_recalculate_totals($pdo,$companyId,$id);$pdo->commit();if(os_edit_exit_requested())os_edit_exit($action==='save_item'?'Item alterado e O.S. salva com sucesso.':'Item adicionado e O.S. salva com sucesso.');os_edit_redirect($id,$action==='save_item'?'Item alterado com sucesso.':'Item adicionado.',4,'itens',$action==='add_item');
  }

  if($action==='remove_item'){
   $itemId=(int)$_POST['item_id'];$pdo->beginTransaction();$s=$pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? AND id=? LIMIT 1 FOR UPDATE");$s->execute(array($companyId,$id,$itemId));$item=$s->fetch(PDO::FETCH_ASSOC);if(!$item)throw new RuntimeException('Item não encontrado.');if($item['reservation_status']==='RESERVADO'&&(float)$item['reserved_quantity']>0)os_unreserve_product($pdo,$companyId,$item['produto_id'],$item['reserved_quantity']);$pdo->prepare("DELETE FROM os_order_items WHERE company_id=? AND order_id=? AND id=?")->execute(array($companyId,$id,$itemId));os_recalculate_totals($pdo,$companyId,$id);$pdo->commit();os_edit_redirect($id,'Item removido.',4,'itens');
  }

  if($action==='add_payment'){
   $methodId=(int)(isset($_POST['payment_method_id'])?$_POST['payment_method_id']:0);
   $amount=os_decimal(isset($_POST['amount'])?$_POST['amount']:0);
   $due=trim(isset($_POST['due_date'])?$_POST['due_date']:'');
   $notes=trim(isset($_POST['payment_notes'])?$_POST['payment_notes']:'');
   if($id<=0||$methodId<=0||$amount<=0)throw new RuntimeException('Informe forma de pagamento e valor.');
   $s=$pdo->prepare("SELECT * FROM finance_payment_methods WHERE company_id=? AND id=? AND is_active=1 LIMIT 1");$s->execute(array($companyId,$methodId));$method=$s->fetch(PDO::FETCH_ASSOC);if(!$method)throw new RuntimeException('Forma de pagamento inválida.');if(strtoupper(trim((string)($method['type']??'')))==='VALE'||strtoupper(trim((string)($method['code']??'')))==='VALE')throw new RuntimeException('O pagamento com Vale está disponível nas vendas/PDV. A O.S. possui fluxo financeiro próprio e ainda não utiliza vales.');
   $creSet=function_exists('finance_settings')?finance_settings($pdo,$companyId):array();
   if(!empty($creSet['cre_rules_enabled'])&&!empty($method['generate_receivable'])){$method['first_due_days']=(int)$creSet['cre_first_due_days'];$method['keep_due_day']=!empty($creSet['cre_keep_month_due_day'])?1:0;$method['cre_fixed_due_day']=$creSet['cre_fixed_due_day'];$method['cre_rules_enabled']=1;}
   $termText=strtolower((string)$method['name'].' '.(string)$method['type']);
   $isTerm=!empty($method['generate_receivable']) || strpos($termText,'prazo')!==false || strpos($termText,'credi')!==false || strpos($termText,'fiado')!==false;
   if($isTerm && $due===''){if(!empty($method['cre_rules_enabled'])&&!empty($method['keep_due_day'])&&(int)($method['cre_fixed_due_day']??0)>0&&function_exists('finance_cre_due_dates')){$dates=finance_cre_due_dates($creSet,1,date('Y-m-d'));$due=$dates[0]??'';}else{$days=max(0,min(365,(int)(isset($method['first_due_days'])?$method['first_due_days']:30)));$due=date('Y-m-d',strtotime('+'.$days.' days'));}}
   if($due!=='' && !preg_match('/^\d{4}-\d{2}-\d{2}$/',$due))throw new RuntimeException('Data de vencimento inválida.');
   $sumStmt=$pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM os_order_payments WHERE company_id=? AND order_id=?");$sumStmt->execute(array($companyId,$id));$existing=(float)$sumStmt->fetchColumn();
   $ord=os_fetch_order($pdo,$companyId,$id);$total=$ord?(float)$ord['total_general']:0;
   if($total>0 && $existing+$amount>$total+0.01)throw new RuntimeException('O valor informado ultrapassa o total restante da O.S.');
   $ins=$pdo->prepare("INSERT INTO os_order_payments (company_id,order_id,payment_method_id,payment_label,amount,due_date,notes) VALUES (?,?,?,?,?,?,?)");
   $ins->execute(array($companyId,$id,$methodId,$method['name'],$amount,$isTerm?$due:null,$notes!==''?$notes:null));
   if(os_edit_exit_requested())os_edit_exit('Pagamento adicionado e O.S. salva com sucesso.');
   os_edit_redirect($id,'Forma de pagamento adicionada.',5);
  }

  if($action==='remove_payment'){$payId=(int)$_POST['payment_id'];$pdo->prepare("DELETE FROM os_order_payments WHERE company_id=? AND order_id=? AND id=?")->execute(array($companyId,$id,$payId));os_edit_redirect($id,'Pagamento removido.',5);}
 }catch(Exception $e){if($pdo->inTransaction())$pdo->rollBack();os_vehicle_damage_clear_photo_delete_queue();$error=$e->getMessage();}
}

$order=$id>0?os_fetch_order($pdo,$companyId,$id):null;if($id>0&&!$order){http_response_code(404);die('O.S. não encontrada.');}
$stmt=$pdo->prepare("SELECT * FROM os_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id");$stmt->execute(array($companyId));$statuses=$stmt->fetchAll(PDO::FETCH_ASSOC);
$stmt=$pdo->prepare("SELECT * FROM os_custom_fields WHERE company_id=? AND active=1 ORDER BY sort_order,id");$stmt->execute(array($companyId));$fields=$stmt->fetchAll(PDO::FETCH_ASSOC);
$optionsByField=array();if($fields){$stmt=$pdo->prepare("SELECT * FROM os_custom_field_options WHERE company_id=? AND active=1 ORDER BY field_id,sort_order,id");$stmt->execute(array($companyId));foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $o){if(!isset($optionsByField[$o['field_id']]))$optionsByField[$o['field_id']]=array();$optionsByField[$o['field_id']][]=$o;}}
$values=array();if($id>0){$stmt=$pdo->prepare("SELECT field_id,value_text FROM os_order_field_values WHERE company_id=? AND order_id=?");$stmt->execute(array($companyId,$id));foreach($stmt->fetchAll(PDO::FETCH_ASSOC) as $v)$values[$v['field_id']]=$v['value_text'];}
$damagePoints=($showDamage&&!empty($settings['auto_parts_mode'])&&$id>0)?os_vehicle_damage_load($pdo,$companyId,$id):array();
$items=array();$payments=array();if($id>0){$stmt=$pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? ORDER BY item_type,id");$stmt->execute(array($companyId,$id));$items=$stmt->fetchAll(PDO::FETCH_ASSOC);$stmt=$pdo->prepare("SELECT * FROM os_order_payments WHERE company_id=? AND order_id=? ORDER BY id");$stmt->execute(array($companyId,$id));$payments=$stmt->fetchAll(PDO::FETCH_ASSOC);}
$responsibles=os_item_responsible_people($pdo,$companyId);
$editItemId=(int)(isset($_GET['edit_item'])?$_GET['edit_item']:0);$editItem=null;if($id>0&&$editItemId>0){$stmt=$pdo->prepare("SELECT * FROM os_order_items WHERE company_id=? AND order_id=? AND id=? LIMIT 1");$stmt->execute(array($companyId,$id,$editItemId));$editItem=$stmt->fetch(PDO::FETCH_ASSOC);}
try{require_once __DIR__ . '/../config/gestao_module.php';$methods=gestao_payment_methods($pdo,$companyId,true);}catch(Throwable $e){$stmt=$pdo->prepare("SELECT * FROM finance_payment_methods WHERE company_id=? AND is_active=1 ORDER BY sort_order,name,id");$stmt->execute(array($companyId));$methods=$stmt->fetchAll(PDO::FETCH_ASSOC);}$methods=array_values(array_filter($methods,static fn(array $pm):bool=>strtoupper(trim((string)($pm['type']??'')))!=='VALE'&&strtoupper(trim((string)($pm['code']??'')))!=='VALE'));
$initialStatus=$order?(int)$order['status_id']:os_initial_status_id($pdo,$companyId);
$vehicleFeatureReady=os_vehicle_feature_ready($pdo);
$paySum=0;foreach($payments as $pp)$paySum+=(float)$pp['amount'];
$paymentRemaining=$order?max(0,round((float)$order['total_general']-$paySum,2)):0;
$paymentMapForJs=array();foreach($methods as $m){$paymentMapForJs[(string)(int)$m['id']]=array('name'=>(string)$m['name'],'type'=>(string)(isset($m['type'])?$m['type']:''),'receivable'=>(int)(isset($m['generate_receivable'])?$m['generate_receivable']:0),'firstDueDays'=>(int)(isset($m['first_due_days'])?$m['first_due_days']:30),'keepDueDay'=>(int)(isset($m['keep_due_day'])?$m['keep_due_day']:0),'fixedDueDay'=>(int)(isset($m['cre_fixed_due_day'])?$m['cre_fixed_due_day']:0),'creRulesEnabled'=>(int)(isset($m['cre_rules_enabled'])?$m['cre_rules_enabled']:0));}

os_page_start($order?'O.S. #'.$order['os_number']:'Nova O.S.','nova');
echo '<style>
.os-wizard-stepper{display:grid;grid-template-columns:repeat(5,minmax(0,1fr));gap:9px;margin:4px 0 16px}.os-wizard-step{display:flex;align-items:center;gap:9px;padding:11px 12px;border:1px solid #b9c5d1;border-radius:14px;background:#fff;color:#526071;text-decoration:none;min-width:0}.os-wizard-step .n{width:31px;height:31px;display:grid;place-items:center;border-radius:50%;background:#edf2f7;color:#526071;font-weight:900;flex:0 0 auto}.os-wizard-step b{display:block;color:#26354d;font-size:12px;line-height:1.2}.os-wizard-step small{display:block;color:#718096;font-size:10px;margin-top:2px}.os-wizard-step.active{border-color:var(--admin-secondary);background:color-mix(in srgb,var(--admin-secondary) 7%,#fff);box-shadow:0 6px 18px rgba(36,84,214,.10)}.os-wizard-step.active .n{background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff}.os-wizard-step.disabled{opacity:.55;cursor:not-allowed}.os-wizard-summary{display:flex;gap:10px 18px;flex-wrap:wrap;padding:10px 13px;margin-bottom:14px;border:1px solid #cbd5e1;border-radius:13px;background:#f8fafc}.os-wizard-summary span{font-size:12px;color:#64748b}.os-wizard-summary b{color:#172554}.os-step-actions{display:flex;gap:9px;justify-content:space-between;align-items:center;margin-top:16px;flex-wrap:wrap}.os-step-actions .right{display:flex;gap:8px;margin-left:auto}.vehicle-lookup-note{margin-top:5px;font-size:12px;color:#64748b}.vehicle-lookup-note.ok{color:#047857;font-weight:700}.vehicle-lookup-note.warn{color:#b45309;font-weight:700}.vehicle-lookup-note.error{color:#b91c1c;font-weight:700}.os-payment-overview{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px;margin-bottom:14px}.os-payment-overview .total-box strong{font-size:18px}.os-term-due{display:none}.os-term-due.show{display:block}.os-payment-help{padding:10px 12px;border:1px solid #cbd5e1;background:#f8fafc;border-radius:12px;margin-bottom:13px;font-size:12px;color:#526071}.os-step-card .card-head{display:flex;align-items:center;gap:8px}.os-step-badge{width:26px;height:26px;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,var(--admin-primary),var(--admin-secondary));color:#fff;font-weight:900;font-size:12px}
#modalPessoa .modal{width:min(920px,100%)}#modalNovaPessoa .modal,#modalPessoaView .modal{width:min(1120px,100%)}.os-person-register{border:1px solid #d5dde7;border-radius:16px;background:#fbfdff;padding:14px}.os-person-register h4{margin:0 0 10px;color:var(--admin-primary);font-size:13px}.os-person-register .person-section{padding:12px 0;border-top:1px solid #e3e9f0}.os-person-register .person-section:first-of-type{border-top:0;padding-top:0}.os-person-register .lookup-status{font-size:11px;margin-top:5px;color:#64748b;min-height:15px}.os-person-register .lookup-status.ok{color:#08783e}.os-person-register .lookup-status.err{color:#b42318}.os-person-register .lookup-status.load{color:#2454d6}.os-person-register .person-actions{display:flex;justify-content:flex-end;gap:8px;margin-top:12px}.os-person-register .inline-lookup{display:flex;gap:7px;align-items:center}.os-person-register .inline-lookup input{flex:1}.os-person-register .inline-lookup .btn{flex:0 0 auto}.person-view-section{border:1px solid #dbe3ec;border-radius:14px;background:#fff;padding:13px;margin-bottom:12px}.person-view-section:last-child{margin-bottom:0}.person-view-section h4{margin:0 0 11px;color:var(--admin-primary);font-size:13px}.person-view-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.person-view-field{min-width:0}.person-view-field.wide{grid-column:span 2}.person-view-field.full{grid-column:1/-1}.person-view-field small{display:block;color:#64748b;font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.02em;margin-bottom:3px}.person-view-field strong{display:block;color:#1e293b;font-size:13px;line-height:1.35;overflow-wrap:anywhere}.person-view-empty{color:#94a3b8;font-weight:600}.person-view-badges{display:flex;gap:6px;flex-wrap:wrap}.person-view-badge{display:inline-flex;align-items:center;padding:5px 8px;border-radius:999px;background:#eef4ff;color:#1e40af;border:1px solid #cbdaf8;font-size:11px;font-weight:800}.person-view-loading{padding:20px;text-align:center;color:#64748b}
/* Botões de manutenção da O.S. responsivos, sem extrapolar o card. */
.os-step-actions .right,.item-action,.os-save-actions,.payment-action{flex-wrap:wrap;min-width:0;max-width:100%}
.item-action .btn,.item-action button,.os-save-actions .btn,.os-save-actions button,.payment-action .btn,.payment-action button{min-width:0;max-width:100%;box-sizing:border-box;white-space:normal;line-height:1.25}
@media(max-width:700px){.item-action,.os-save-actions,.payment-action{display:flex!important;flex-direction:column;align-items:stretch;gap:8px}.item-action .btn,.item-action button,.os-save-actions .btn,.os-save-actions button,.payment-action .btn,.payment-action button{width:100%;flex:0 1 auto;font-size:13px;padding:10px 8px}}
@media(max-width:760px){.os-wizard-stepper{grid-template-columns:1fr 1fr}.os-wizard-step:last-child{grid-column:1/-1}.os-wizard-step{padding:9px}.os-wizard-step small{display:none}.os-payment-overview{grid-template-columns:1fr}.os-step-actions,.os-step-actions .right{display:grid;grid-template-columns:1fr;width:100%;margin-left:0}.os-step-actions .btn{width:100%}.os-person-register .inline-lookup{flex-wrap:wrap}.os-person-register .inline-lookup .btn{width:100%}.person-view-grid{grid-template-columns:1fr}.person-view-field.wide{grid-column:auto}}

/* O.S. > Produtos e servicos: cadastro compacto em duas linhas, sem coluna vazia. */
.os-module .item-entry-grid-v16{grid-template-columns:repeat(12,minmax(0,1fr));gap:9px 12px;align-items:end}
.os-module .item-entry-grid-v16 > .item-product{grid-column:span 4;min-width:0}
.os-module .item-entry-grid-v16 > .item-quantity{grid-column:span 1;min-width:0}
.os-module .item-entry-grid-v16 > .item-price{grid-column:span 2;min-width:0}
.os-module .item-entry-grid-v16 > .item-stock{grid-column:span 2;min-width:0}
.os-module .item-entry-grid-v16 > .item-responsible{grid-column:span 3;min-width:0}
.os-module .item-entry-grid-v16 > .item-notes{grid-column:span 6;min-width:0}
.os-module .item-entry-grid-v16 > .item-action{grid-column:span 6;display:flex;flex-direction:row;flex-wrap:nowrap;align-items:flex-end;gap:8px;min-width:0}
.os-module .item-entry-grid-v16 .item-action .btn{width:auto;min-width:0;min-height:40px;flex:1 1 0;white-space:normal;padding:8px 9px;text-align:center;line-height:1.2}
@media(max-width:1200px){
 .os-module .item-entry-grid-v16 > .item-product{grid-column:span 6}
 .os-module .item-entry-grid-v16 > .item-quantity,.os-module .item-entry-grid-v16 > .item-price{grid-column:span 3}
 .os-module .item-entry-grid-v16 > .item-stock,.os-module .item-entry-grid-v16 > .item-responsible{grid-column:span 6}
 .os-module .item-entry-grid-v16 > .item-notes,.os-module .item-entry-grid-v16 > .item-action{grid-column:span 6}
}
@media(max-width:760px){
 .os-module .item-entry-grid-v16{grid-template-columns:repeat(12,minmax(0,1fr))}
 .os-module .item-entry-grid-v16 > .item-product,.os-module .item-entry-grid-v16 > .item-notes,.os-module .item-entry-grid-v16 > .item-action{grid-column:1/-1}
 .os-module .item-entry-grid-v16 > .item-quantity,.os-module .item-entry-grid-v16 > .item-price,.os-module .item-entry-grid-v16 > .item-stock,.os-module .item-entry-grid-v16 > .item-responsible{grid-column:span 6}
 .os-module .item-entry-grid-v16 > .item-action{flex-direction:row!important;flex-wrap:wrap;align-items:stretch}
 .os-module .item-entry-grid-v16 .item-action .btn{width:auto;flex:1 1 140px}
}
@media(max-width:460px){
 .os-module .item-entry-grid-v16 > .item-stock,.os-module .item-entry-grid-v16 > .item-responsible{grid-column:1/-1}
}
</style>';
echo '<div class="os-title-row"><h1>'.($order?'O.S. #'.str_pad((string)$order['os_number'],6,'0',STR_PAD_LEFT):'Nova Ordem de Serviço').'</h1><div class="spacer"></div>';
if($order){echo '<a class="btn btn-secondary" href="os_imprimir.php?id='.$id.'">Imprimir</a>';if($order['billing_status']==='PENDENTE'&&(float)$order['total_general']>0)echo '<a class="btn btn-success" href="os_faturar.php?id='.$id.'">Faturar O.S.</a>';}else{echo '<a class="btn btn-secondary" href="os_importar_orcamento.php">Importar Orçamento</a>';}
echo '</div>';if($ok)echo '<div class="alert alert-ok">'.os_e($ok).'</div>';if($error)echo '<div class="alert alert-error">'.os_e($error).'</div>';
$statusNotifyReturn=((string)($_GET['after_notify']??'')==='monitor')?'os_monitor.php':'os_editar.php?id='.$id.'&step=1';
if($order && !empty($_GET['notify_status']) && os_status_notify_enabled($pdo,(int)$companyId,(int)$order['status_id'])){echo '<div class="alert" style="display:flex;gap:10px;align-items:center;flex-wrap:wrap;background:#eff6ff;border-color:#bfdbfe;color:#1e3a8a"><div style="flex:1 1 360px"><b>Status alterado para '.os_e($order['status_name']).'.</b><br>Deseja avisar o cliente sobre esta alteração?</div><a class="btn btn-success" href="os_status_comunicar.php?id='.$id.'&channel=whatsapp&return_to='.rawurlencode($statusNotifyReturn).'">Sim, enviar WhatsApp</a><a class="btn btn-secondary" href="'.os_e($statusNotifyReturn).'">Agora não</a></div>';}

$stepLabels=array(
  1=>array('Cliente e dados','Identificação da O.S.'),
  2=>array('Atendimento','Observações'),
  3=>array('Mapa de avarias','Veículo'),
  4=>array('Produtos e serviços','Itens e valores'),
  5=>array('Pagamento','Opcional')
);
if(!$showDamage) unset($stepLabels[3]);
echo '<div class="os-wizard-stepper">';
foreach($stepLabels as $sn=>$sl){$enabled=$order||$sn===1;$cls='os-wizard-step'.($step===$sn?' active':'').(!$enabled?' disabled':'');if($enabled)echo '<a class="'.$cls.'" href="os_editar.php'.($id>0?'?id='.$id.'&step='.$sn:'').'">';else echo '<div class="'.$cls.'">';echo '<span class="n">'.$sn.'</span><span><b>'.os_e($sl[0]).'</b><small>'.os_e($sl[1]).'</small></span>';echo $enabled?'</a>':'</div>';}
echo '</div>';
if($order){echo '<div class="os-wizard-summary"><span>Cliente: <b>'.os_e($order['person_name_snapshot']?:'Não informado').'</b></span><span>Status: <b>'.os_e($order['status_name']?:'—').'</b></span><span>Total: <b>'.os_money($order['total_general']).'</b></span><span>Etapa atual: <b>'.$step.' de 5</b></span></div>';}

if($step===1){
 echo '<form method="post"><input type="hidden" name="action" value="save_order"><input type="hidden" name="step" value="1"><input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" id="person_id" name="person_id" value="'.($order?(int)$order['person_id']:0).'">';
 echo '<div class="card os-step-card"><div class="card-head"><span class="os-step-badge">1</span> Cliente e dados da O.S.</div><div class="card-body"><div class="grid">';
 echo '<div class="c6"><label>Cliente / Pessoa</label><div class="inline"><input id="person_name" readonly value="'.os_e($order?$order['person_name_snapshot']:'').'" placeholder="Selecione ou cadastre uma pessoa"><button type="button" class="btn btn-secondary nowrap" id="btnVerPessoa" '.($order&&(int)$order['person_id']>0?'':'style="display:none"').'>Ver cadastro</button><button type="button" class="btn btn-outline nowrap" id="btnBuscarPessoa">Buscar (F8)</button></div></div>';
 echo '<div class="c3"><label>Status</label><select name="status_id">';foreach($statuses as $st)echo '<option value="'.(int)$st['id'].'" '.($initialStatus==(int)$st['id']?'selected':'').'>'.os_e($st['name']).'</option>';echo '</select></div>';
 echo '<div class="c3"><label>Previsão de conclusão</label><input type="datetime-local" name="expected_at" value="'.os_e($order&&$order['expected_at']?date('Y-m-d\TH:i',strtotime($order['expected_at'])):'').'"></div>';
 $contactNameValue=$order?(string)(isset($order['contact_name'])?$order['contact_name']:''):'';$contactPhoneValue=$order?(string)(isset($order['contact_phone'])?$order['contact_phone']:'' ):'';
 if($order && $contactNameValue==='' && !empty($order['person_name_snapshot']))$contactNameValue=(string)$order['person_name_snapshot'];if($order && $contactPhoneValue==='' && !empty($order['person_phone_snapshot']))$contactPhoneValue=(string)$order['person_phone_snapshot'];
 echo '<div class="c6"><label>Contato</label><input id="contact_name" name="contact_name" value="'.os_e($contactNameValue).'" placeholder="Nome da pessoa que solicitou / receberá o atendimento"></div>';
 echo '<div class="c6"><label>Telefone / WhatsApp</label><input id="contact_phone" name="contact_phone" value="'.os_e($contactPhoneValue).'" placeholder="(00) 00000-0000" inputmode="tel"></div>';
 if($showVehicle && $fields){
  echo '<div class="c12 section-caption">Dados específicos da atividade</div><div class="c12"><div class="grid compact-grid">';
  foreach($fields as $f){
   $v=isset($values[$f['id']])?$values[$f['id']]:'';$req=(int)$f['required']?' required':'';$label=os_e($f['label']);$ph=os_e($f['placeholder']);$key='field_'.(int)$f['id'];$cls=$f['input_type']==='textarea'?'c6':'c3';$role=$vehicleFeatureReady&&isset($f['semantic_role'])?trim((string)$f['semantic_role']):'';$semanticAttr=$role!==''?' data-semantic="'.os_e($role).'"':'';
   echo '<div class="'.$cls.'"><label>'.$label.((int)$f['required']?' *':'').'</label>';
   if($f['input_type']==='textarea')echo '<textarea id="'.$key.'" name="'.$key.'"'.$semanticAttr.$req.' placeholder="'.$ph.'">'.os_e($v).'</textarea>';
   elseif($f['input_type']==='select'){echo '<div class="inline"><select id="'.$key.'" name="'.$key.'"'.$semanticAttr.$req.'><option value="">Selecione</option>';if(isset($optionsByField[$f['id']]))foreach($optionsByField[$f['id']] as $o)echo '<option value="'.os_e($o['option_value']).'" '.($v===$o['option_value']?'selected':'').'>'.os_e($o['label']).'</option>';echo '</select>';if((int)$f['allow_quick_add'])echo '<button type="button" class="btn btn-secondary btn-sm nowrap" onclick="quickAddOption('.(int)$f['id'].',\''.$key.'\')">+ Cadastrar</button>';echo '</div>';}
   elseif($f['input_type']==='checkbox')echo '<label style="font-weight:normal;min-height:38px;display:flex;align-items:center;gap:7px;margin:0"><input id="'.$key.'"'.$semanticAttr.' style="width:auto;min-height:auto" type="checkbox" name="'.$key.'" value="1" '.($v==='1'?'checked':'').'> Sim</label>';
   elseif($f['input_type']==='date')echo '<input id="'.$key.'"'.$semanticAttr.' type="date" name="'.$key.'" value="'.os_e($v).'"'.$req.'>';
   elseif($f['input_type']==='number')echo '<input id="'.$key.'"'.$semanticAttr.' type="number" step="any" name="'.$key.'" value="'.os_e($v).'"'.$req.' placeholder="'.$ph.'">';
   elseif($f['input_type']==='password')echo '<input id="'.$key.'"'.$semanticAttr.' type="password" autocomplete="off" name="'.$key.'" value="'.os_e($v).'"'.$req.' placeholder="'.$ph.'"><div class="hint">Não impresso por padrão.</div>';
   elseif($role==='vehicle_plate' && $vehicleFeatureReady){echo '<div class="inline"><input id="'.$key.'"'.$semanticAttr.' class="os-plate-input" type="text" name="'.$key.'" value="'.os_e($v).'"'.$req.' placeholder="'.($ph!==''?$ph:'AAA0A00').'" maxlength="10" autocomplete="off"><button type="button" class="btn btn-outline btn-sm nowrap" data-plate-lookup data-target="'.$key.'">Consultar placa</button></div><div id="vehicleLookupMessage" class="vehicle-lookup-note">Digite a placa e consulte para carregar dados já cadastrados neste veículo.</div>';}
   else echo '<input id="'.$key.'"'.$semanticAttr.' type="text" name="'.$key.'" value="'.os_e($v).'"'.$req.' placeholder="'.$ph.'">';
   echo '</div>';
  }
  echo '</div></div>';
 }
 if($showVehicle && !$vehicleFeatureReady)echo '<div class="c12"><div class="hint">A identificação automática de Placa/Marca/Modelo será habilitada após executar o SQL desta atualização.</div></div>';
 echo '<div class="c12"><div class="os-step-actions"><a class="btn btn-secondary" href="os_monitor.php">Cancelar / voltar ao monitor</a><div class="right"><button class="btn btn-success" name="save_exit" value="1">Salvar e sair</button><button class="btn btn-primary">Salvar e continuar → Atendimento</button></div></div></div>';
 echo '</div></div></div></form>';
}

if($order && $step===2){
 echo '<div class="card os-step-card"><div class="card-head"><span class="os-step-badge">2</span> Atendimento e observações</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="save_attendance"><input type="hidden" name="step" value="2"><input type="hidden" name="order_id" value="'.$id.'"><div class="grid">';
 echo '<div class="c6"><label>Problema relatado / solicitação</label><textarea name="reported_problem">'.os_e($order['reported_problem']).'</textarea></div><div class="c6"><label>Diagnóstico</label><textarea name="diagnosis">'.os_e($order['diagnosis']).'</textarea></div>';
 echo '<div class="c6"><label>Observações técnicas internas</label><textarea name="technical_notes">'.os_e($order['technical_notes']).'</textarea></div><div class="c6"><label>Observações para o cliente / impressão</label><textarea name="customer_notes">'.os_e($order['customer_notes']).'</textarea></div>';
 echo '<div class="c12"><div class="os-step-actions"><a class="btn btn-secondary" href="os_editar.php?id='.$id.'&step=1">← Voltar</a><div class="right"><button class="btn btn-success" name="save_exit" value="1">Salvar e sair</button><button class="btn btn-primary">Salvar e continuar → '.($showDamage?'Mapa de avarias':'Produtos e serviços').'</button></div></div></div></div></form></div></div>';
}

if($order && $showDamage && $step===3){
 echo '<div class="card os-step-card"><div class="card-head"><span class="os-step-badge">3</span> Mapa de avarias / inspeção</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="save_damage"><input type="hidden" name="step" value="3"><input type="hidden" name="order_id" value="'.$id.'">';
 if(os_vehicle_damage_enabled((array)$settings)){echo '<div class="hint" style="margin-bottom:12px">Marque avarias ou pontos de inspeção antes do serviço. As fotos continuam opcionais.</div>';os_vehicle_damage_editor($damagePoints,(array)$settings);}else{echo '<div class="alert alert-ok">O mapa de avarias está desabilitado nas Configurações da O.S. Esta etapa pode ser avançada normalmente.</div>';}
 echo '<div class="os-step-actions"><a class="btn btn-secondary" href="os_editar.php?id='.$id.'&step=2">← Voltar</a><div class="right"><button class="btn btn-success" name="save_exit" value="1">Salvar e sair</button><button class="btn btn-primary">Salvar e continuar → Produtos e serviços</button></div></div></form></div></div>';
}

if($order && $step===4){
 $itemAction=$editItem?'save_item':'add_item';$itemProductId=$editItem?(int)$editItem['produto_id']:0;$itemProductName=$editItem?$editItem['description_snapshot']:'';$itemQty=$editItem?$editItem['quantity']:'1';$itemPrice=$editItem?$editItem['unit_price']:'';$itemReserve=$editItem?((int)$editItem['reserve_stock']===1):((int)$settings['default_reserve_product']===1);$itemResponsible=$editItem?os_item_responsible_id($editItem):0;$itemNotes=$editItem?$editItem['notes']:'';
 echo '<div class="card os-step-card" id="itens"><div class="card-head"><span class="os-step-badge">4</span> '.($editItem?'Editar produto / serviço':'Produtos e serviços').'</div><div class="card-body"><form method="post"><input type="hidden" name="action" value="'.$itemAction.'"><input type="hidden" name="step" value="4"><input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="item_id" value="'.($editItem?(int)$editItem['id']:0).'"><input type="hidden" id="produto_id" name="produto_id" value="'.$itemProductId.'"><div class="item-entry-grid item-entry-grid-v16"><div class="item-product"><label>Produto / Serviço</label><div class="product-quick-wrap"><div class="inline"><input id="produto_nome" autocomplete="off" value="'.os_e($itemProductName).'" placeholder="Digite o produto/serviço ou use F8"><button type="button" class="btn btn-outline" id="btnBuscarItem">Buscar (F8)</button></div><div id="produto_sugestoes" class="product-quick-results" aria-live="polite"></div></div></div><div class="item-quantity"><label>Quantidade</label><input type="number" step="0.0001" min="0.0001" id="item_qty" name="quantity" value="'.os_e($itemQty).'"></div><div class="item-price"><label>Valor unitário</label><input id="item_price" name="unit_price" value="'.os_e($itemPrice).'" inputmode="decimal"></div><div class="item-stock"><label>Estoque</label><label class="checkline"><input id="reserve_stock" type="checkbox" name="reserve_stock" value="1" '.($itemReserve?'checked':'').'> Reservar produto</label><div id="stock_info" class="hint">'.(!empty($settings['allow_out_of_stock_reservation'])?'Reserva acima do saldo está permitida nas configurações.':'A reserva exige estoque disponível.').'</div></div>';
 echo '<div class="item-responsible"><label>Vendedor/Técnico</label><select name="responsible_person_id"><option value="">Não informado</option>';foreach($responsibles as $rp)echo '<option value="'.(int)$rp['id'].'" '.($itemResponsible===(int)$rp['id']?'selected':'').'>'.os_e($rp['name']).'</option>';echo '</select></div>';
 echo '<div class="item-notes"><label>Observação do item</label><input name="notes" value="'.os_e($itemNotes).'" placeholder="Opcional"></div><div class="item-action"><button class="btn btn-primary" type="submit">'.($editItem?'Salvar item':'Adicionar item').'</button><button class="btn btn-success" type="submit" name="save_exit" value="1">'.($editItem?'Salvar item e sair':'Adicionar item e sair').'</button>';if($editItem)echo '<a class="btn btn-secondary" href="os_editar.php?id='.$id.'&step=4#itens">Cancelar</a>';echo '</div></div></form>';
 echo '<div class="hint" style="margin-top:10px">O campo <b>Vendedor/Técnico</b> identifica uma única pessoa responsável pelo item e ficará disponível para os relatórios de comissão.</div>';
 echo '<div class="table-wrap" style="margin-top:15px"><table class="table"><thead><tr><th>Tipo</th><th>Descrição</th><th>Vendedor/Técnico</th><th class="num">Qtd.</th><th class="num">Unitário</th><th>Reserva</th><th class="num">Total</th><th></th></tr></thead><tbody>';if(!$items)echo '<tr><td colspan="8" class="muted">Nenhum produto ou serviço lançado.</td></tr>';foreach($items as $it){echo '<tr><td><span class="badge" style="background:'.($it['item_type']==='SERVICO'?'#ede9fe;color:#6d28d9':'#e8f4f4;color:#0f5b61').'">'.os_e($it['item_type']).'</span></td><td><b>'.os_e($it['description_snapshot']).'</b>';if($it['notes'])echo '<div class="small muted">'.os_e($it['notes']).'</div>';echo '</td><td>';$respName=os_item_responsible_name($it);echo $respName!==''?'<div class="small"><b>Resp.:</b> '.os_e($respName).'</div>':'<span class="muted">—</span>';echo '</td><td class="num">'.number_format((float)$it['quantity'],4,',','.').'</td><td class="num">'.os_money($it['unit_price']).'</td><td>'.os_e($it['reservation_status']).($it['reservation_status']==='RESERVADO'?'<div class="small muted">'.number_format((float)$it['reserved_quantity'],4,',','.').'</div>':'').'</td><td class="num"><b>'.os_money($it['total']).'</b></td><td><div class="inline" style="justify-content:flex-end"><a class="btn btn-outline btn-sm" href="os_editar.php?id='.$id.'&step=4&edit_item='.(int)$it['id'].'#itens">Editar</a><form method="post" onsubmit="return confirm(\'Remover este item?\')"><input type="hidden" name="action" value="remove_item"><input type="hidden" name="step" value="4"><input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="item_id" value="'.(int)$it['id'].'"><button class="btn btn-danger btn-sm">Remover</button></form></div></td></tr>';}
 echo '</tbody></table></div><div class="totals" style="margin-top:15px"><div class="total-box"><span>Produtos</span><strong>'.os_money($order['total_products']).'</strong></div><div class="total-box"><span>Serviços</span><strong>'.os_money($order['total_services']).'</strong></div><div class="total-box"><span>Total geral</span><strong>'.os_money($order['total_general']).'</strong></div></div>';
 echo '<form method="post" style="margin-top:14px"><input type="hidden" name="action" value="save_step4"><input type="hidden" name="step" value="4"><input type="hidden" name="order_id" value="'.$id.'"><div class="os-form-actions"><div class="os-adjustments"><div><label>Desconto geral</label><input name="discount_amount" value="'.os_e($order['discount_amount']).'" inputmode="decimal"></div><div><label>Acréscimo geral</label><input name="addition_amount" value="'.os_e($order['addition_amount']).'" inputmode="decimal"></div></div><div class="os-save-actions"><a class="btn btn-secondary" href="os_editar.php?id='.$id.'&step='.($showDamage?3:2).'">← Voltar</a><button class="btn btn-success" name="save_exit" value="1">Salvar e sair</button><button class="btn btn-primary">Salvar e continuar → Pagamento</button></div></div></form></div></div>';
}

if($order && $step===5){
 echo '<div class="card os-step-card"><div class="card-head"><span class="os-step-badge">5</span> Formas de pagamento <span class="muted small">(opcional)</span></div><div class="card-body">';
 echo '<div class="os-payment-overview"><div class="total-box"><span>Total da O.S.</span><strong>'.os_money($order['total_general']).'</strong></div><div class="total-box"><span>Já informado</span><strong>'.os_money($paySum).'</strong></div><div class="total-box"><span>Saldo restante</span><strong>'.os_money($paymentRemaining).'</strong></div></div>';
 if((int)$settings['allow_payment_plan']){
  echo '<div class="os-payment-help">Ao selecionar a forma de pagamento, o valor recebe automaticamente o <b>saldo restante da O.S.</b>. Nas formas configuradas como <b>A Prazo / Conta a receber</b>, o vencimento será exibido e poderá ser alterado.</div>';
  echo '<form method="post"><input type="hidden" name="action" value="add_payment"><input type="hidden" name="step" value="5"><input type="hidden" name="order_id" value="'.$id.'"><div class="payment-entry-grid"><div><label>Forma</label><select id="os_payment_method" name="payment_method_id" required><option value="">Selecione</option>';foreach($methods as $m)echo '<option value="'.(int)$m['id'].'">'.os_e($m['name']).'</option>';echo '</select></div><div><label>Valor</label><input id="os_payment_amount" name="amount" required inputmode="decimal" value="" placeholder="Selecione a forma" data-remaining="'.number_format($paymentRemaining,2,'.','').'" '.($paymentRemaining<=0?'readonly':'').'></div><div id="os_term_due" class="os-term-due"><label>Data do pagamento / vencimento</label><input id="os_payment_due" type="date" name="due_date"></div><div class="payment-notes"><label>Observação</label><input name="payment_notes" placeholder="Opcional"></div><div class="payment-action" style="display:flex;gap:7px;flex-wrap:wrap"><button class="btn btn-success" name="save_exit" value="1" '.($paymentRemaining<=0?'disabled':'').'>Salvar e sair</button><button class="btn btn-secondary" '.($paymentRemaining<=0?'disabled':'').'>Adicionar pagamento</button></div></div></form>';
  if($payments){echo '<div class="table-wrap" style="margin-top:14px"><table class="table"><thead><tr><th>Forma</th><th>Vencimento</th><th>Obs.</th><th class="num">Valor</th><th></th></tr></thead><tbody>';foreach($payments as $pay){echo '<tr><td>'.os_e($pay['payment_label']).'</td><td>'.($pay['due_date']?date('d/m/Y',strtotime($pay['due_date'])):'À vista').'</td><td>'.os_e($pay['notes']).'</td><td class="num">'.os_money($pay['amount']).'</td><td><form method="post"><input type="hidden" name="action" value="remove_payment"><input type="hidden" name="step" value="5"><input type="hidden" name="order_id" value="'.$id.'"><input type="hidden" name="payment_id" value="'.(int)$pay['id'].'"><button class="btn btn-danger btn-sm">Remover</button></form></td></tr>';}echo '</tbody></table></div>';}
 }else echo '<div class="alert alert-ok">As formas de pagamento opcionais estão desabilitadas em Configurações da O.S. Você pode concluir o lançamento normalmente.</div>';
 echo '<div class="os-step-actions"><a class="btn btn-secondary" href="os_editar.php?id='.$id.'&step=4">← Voltar</a><div class="right"><a class="btn btn-outline" href="os_editar.php?id='.$id.'&step=1">Revisar O.S.</a><a class="btn btn-success" href="os_monitor.php?ok='.urlencode('O.S. salva com sucesso.').'">Salvar e sair</a><a class="btn btn-primary" href="os_monitor.php">Concluir lançamento</a></div></div></div></div>';
}
?>
<div id="modalPessoa" class="modal-bg"><div class="modal"><div class="modal-head"><strong>Buscar cliente / pessoa</strong><button type="button" class="btn btn-secondary btn-sm" data-close="modalPessoa">Fechar</button></div><div class="modal-body">
<div class="inline"><input id="searchPessoa" placeholder="Digite e pressione Enter"><button type="button" class="btn btn-primary" id="runPessoa">Pesquisar</button><button type="button" class="btn btn-secondary" id="showNewPerson">+ Cadastrar cliente completo</button></div>
<div class="hint">Enter pesquisa; ↑/↓ percorrem; Enter seleciona. Ao digitar novamente, Enter executa uma nova pesquisa.</div>
<div id="listPessoa" class="search-list"></div></div></div></div>
<div id="modalNovaPessoa" class="modal-bg"><div class="modal"><div class="modal-head"><strong>Cadastrar cliente completo</strong><button type="button" class="btn btn-secondary btn-sm" data-close="modalNovaPessoa">Fechar</button></div><div class="modal-body">
<div class="os-person-register">
  <div class="hint" style="margin-bottom:12px">Preencha os dados do cliente. Este cadastro alimenta o cadastro central de Pessoas e fica disponível para NF-e, NFC-e, NFS-e, financeiro e demais módulos.</div>
  <div class="person-section"><div class="grid">
    <div class="c3"><label>Tipo de pessoa *</label><select id="newPersonType"><option value="FISICA">Pessoa Física</option><option value="JURIDICA">Pessoa Jurídica</option></select></div>
    <div class="c5"><label id="newPersonDocLabel">CPF</label><div class="inline-lookup"><input id="newPersonDoc" inputmode="numeric" autocomplete="off" placeholder="Digite o CPF"><button type="button" class="btn btn-secondary" id="btnNewPersonCnpj" style="display:none">Buscar CNPJ</button></div><div id="newPersonDocStatus" class="lookup-status"></div></div>
    <div class="c4"><label id="newPersonBirthLabel">Data de nascimento</label><input id="newPersonBirth" type="date"></div>
  </div></div>
  <div class="person-section"><div class="grid">
    <div class="c6"><label>Nome / Razão social *</label><input id="newPersonName"></div>
    <div class="c6" id="newPersonFantasyWrap" style="display:none"><label>Nome fantasia</label><input id="newPersonFantasy"></div>
  </div></div>
  <div class="person-section"><div class="grid">
    <div class="c4" id="newPersonIeWrap"><label>Inscrição Estadual (IE)</label><input id="newPersonIe" placeholder="Número da IE ou ISENTO"><div class="hint">Quando aplicável, informe a IE ou ISENTO.</div></div>
    <div class="c4" id="newPersonImWrap" style="display:none"><label>Inscrição Municipal (IM)</label><input id="newPersonIm" placeholder="Inscrição municipal"></div>
    <div class="c4" id="newPersonRgWrap"><label>RG / Identidade</label><input id="newPersonRg" placeholder="Documento de identidade"></div>
    <div class="c4" id="newPersonIssuerWrap"><label>Órgão expedidor</label><input id="newPersonIssuer" placeholder="Ex.: SSP"></div>
    <div class="c4" id="newPersonIdentityStateWrap"><label>UF da identidade</label><input id="newPersonIdentityState" maxlength="2" placeholder="UF"></div>
  </div></div>
  <div class="person-section"><div class="grid">
    <div class="c4"><label>E-mail</label><input id="newPersonEmail" type="email"></div>
    <div class="c4"><label>WhatsApp</label><input id="newPersonWhats"></div>
    <div class="c4"><label>Telefone</label><input id="newPersonPhone"></div>
  </div></div>
  <div class="person-section"><div class="grid">
    <div class="c3"><label>CEP</label><input id="newPersonZip" inputmode="numeric" autocomplete="postal-code" placeholder="Digite o CEP"><div id="newPersonZipStatus" class="lookup-status"></div></div>
    <div class="c6"><label>Endereço</label><input id="newPersonAddress"></div>
    <div class="c3"><label>Número</label><input id="newPersonNumber"></div>
    <div class="c4"><label>Bairro</label><input id="newPersonDistrict"></div>
    <div class="c4"><label>Cidade</label><input id="newPersonCity"></div>
    <div class="c2"><label>Código IBGE</label><input id="newPersonCityIbge" inputmode="numeric" maxlength="7"></div>
    <div class="c2"><label>UF</label><input id="newPersonState" maxlength="2"></div>
    <div class="c8"><label>Complemento</label><input id="newPersonComplement"></div>
  </div></div>
  <div class="person-actions"><button type="button" class="btn btn-secondary" id="cancelNewPerson">Voltar à pesquisa</button><button type="button" class="btn btn-primary" id="saveNewPerson">Cadastrar e selecionar</button></div>
</div></div></div></div>
<div id="modalPessoaView" class="modal-bg"><div class="modal"><div class="modal-head"><strong>Cadastro completo do cliente</strong><button type="button" class="btn btn-secondary btn-sm" data-close="modalPessoaView">Fechar</button></div><div class="modal-body"><div id="personViewContent" class="person-view-loading">Selecione um cliente para visualizar o cadastro.</div></div></div></div>
<div id="modalItem" class="modal-bg"><div class="modal"><div class="modal-head"><strong>Buscar produto / serviço</strong><button type="button" class="btn btn-secondary btn-sm" data-close="modalItem">Fechar</button></div><div class="modal-body"><div class="inline"><input id="searchItem" placeholder="Digite e pressione Enter"><select id="searchItemType" style="max-width:180px"><option value="">Todos</option><option value="PRODUTO">Produtos</option><option value="SERVICO">Serviços</option></select><button type="button" class="btn btn-primary" id="runItem">Pesquisar</button></div><div class="hint">Enter pesquisa; ↑/↓ percorrem; Enter seleciona.</div><div id="listItem" class="search-list"></div></div></div></div>
<script>
(function(){
 function esc(s){return String(s==null?'':s).replace(/[&<>"']/g,function(c){return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[c]})}

 function openModal(id,inputId){
   var m=document.getElementById(id);
   if(!m)return;
   m.classList.add('open');
   var inp=document.getElementById(inputId);
   if(inp){inp.value='';inp.dataset.dirty='1';}
   var list=document.getElementById(id==='modalPessoa'?'listPessoa':'listItem');
   if(list){list.innerHTML='';list.style.display='';}
   setTimeout(function(){if(inp)inp.focus();},40);
 }
 function closeModal(id){var m=document.getElementById(id);if(m)m.classList.remove('open')}
 document.querySelectorAll('[data-close]').forEach(function(b){b.addEventListener('click',function(){closeModal(this.dataset.close)})});
 document.querySelectorAll('.modal-bg').forEach(function(m){m.addEventListener('click',function(e){if(e.target===m)closeModal(m.id)})});

 var bp=document.getElementById('btnBuscarPessoa');
 var bv=document.getElementById('btnVerPessoa');
 var bi=document.getElementById('btnBuscarItem');
 function syncPersonViewButton(){var pid=document.getElementById('person_id');if(!bv||!pid)return;bv.style.display=parseInt(pid.value||'0',10)>0?'':'none';}
 window.osAutoFillContact=<?php echo !empty($osExtra['auto_fill_contact'])?'true':'false'; ?>;
 function applyPerson(r){if(!r)return;document.getElementById('person_id').value=r.id||'';document.getElementById('person_name').value=r.name||'';var cn=document.getElementById('contact_name'),cp=document.getElementById('contact_phone');if(window.osAutoFillContact){if(cn)cn.value=r.name||'';if(cp)cp.value=r.whatsapp||r.phone||'';}syncPersonViewButton();}
 var allowOutOfStock=<?php echo !empty($settings['allow_out_of_stock_reservation'])?'true':'false'; ?>;
 if(bp)bp.onclick=function(){openModal('modalPessoa','searchPessoa')};
 if(bi)bi.onclick=function(){openModal('modalItem','searchItem')};
 <?php if($editItem && $editItem['item_type']==='SERVICO'): ?>var rs0=document.getElementById('reserve_stock');if(rs0){rs0.checked=false;rs0.disabled=true;}<?php endif; ?>

 document.addEventListener('keydown',function(e){
   if(e.key!=='F8')return;
   var newModal=document.getElementById('modalNovaPessoa'),viewModal=document.getElementById('modalPessoaView');
   if((newModal&&newModal.classList.contains('open'))||(viewModal&&viewModal.classList.contains('open')))return;
   e.preventDefault();
   var active=document.activeElement;
   if((active&&active.id==='produto_nome') || document.getElementById('modalItem').classList.contains('open')){
     if(bi)bi.click();
   }else{
     if(bp)bp.click();
   }
 });

 function jsonFetch(url,options){
   return fetch(url,options||{credentials:'same-origin'}).then(function(r){
     return r.text().then(function(body){
       var j;
       try{j=JSON.parse(body)}catch(ex){throw new Error('Resposta inválida da pesquisa. HTTP '+r.status);}
       if(!r.ok || !j.ok)throw new Error(j.message||('Falha na pesquisa. HTTP '+r.status));
       return j;
     });
   });
 }

 function applyItem(r){
   if(!r)return;
   document.getElementById('produto_id').value=r.id||'';
   document.getElementById('produto_nome').value=r.nome||'';
   document.getElementById('item_price').value=parseFloat(r.preco||0).toFixed(2).replace('.',',');
   var av=parseFloat(r.estoque_atual||0)-parseFloat(r.estoque_reservado||0);
   var isService=parseInt(r.is_service||0,10)===1;
   document.getElementById('stock_info').textContent=isService?'Serviço: sem reserva de estoque.':('Disponível para reserva: '+av.toFixed(4)+(allowOutOfStock?' • reserva acima do saldo permitida':''));
   document.getElementById('reserve_stock').disabled=isService;
   if(isService)document.getElementById('reserve_stock').checked=false;
   hideQuickResults();
   setTimeout(function(){var q=document.getElementById('item_qty');if(q){q.focus();q.select();}},20);
 }

 function setupSearch(cfg){
   var input=document.getElementById(cfg.input),list=document.getElementById(cfg.list),run=document.getElementById(cfg.run),rows=[],idx=-1,lastQuery='';
   function render(message){
     list.innerHTML='';
     if(message){list.innerHTML='<div class="search-message">'+esc(message)+'</div>';return;}
     if(!rows.length){list.innerHTML='<div class="search-message">Nenhum registro encontrado.</div>';return;}
     rows.forEach(function(r,i){
       var d=document.createElement('div');
       d.className='search-row'+(i===idx?' selected':'');
       d.innerHTML=cfg.html(r);
       d.onclick=function(){idx=i;select()};
       list.appendChild(d);
     });
     if(idx>=0&&list.children[idx])list.children[idx].scrollIntoView({block:'nearest'});
   }
   function search(){
     var q=String(input.value||'').trim();
     lastQuery=q;
     render('Pesquisando...');
     var url='os_ajax.php?action='+cfg.action+'&q='+encodeURIComponent(q);
     if(cfg.extra)url+=cfg.extra();
     return jsonFetch(url,{credentials:'same-origin'}).then(function(j){
       if(String(input.value||'').trim()!==lastQuery)return;
       rows=j.rows||[];
       idx=rows.length?0:-1;
       input.dataset.dirty='0';
       render();
     }).catch(function(err){
       rows=[];idx=-1;
       render(err.message||'Não foi possível pesquisar.');
     });
   }
   function select(){
     if(idx<0||!rows[idx])return;
     cfg.select(rows[idx]);
     closeModal(cfg.modal);
   }
   input.addEventListener('input',function(){input.dataset.dirty='1'});
   input.addEventListener('keydown',function(e){
     if(e.key==='ArrowDown'){
       e.preventDefault();if(rows.length){idx=Math.min(rows.length-1,idx+1);render();}
     }else if(e.key==='ArrowUp'){
       e.preventDefault();if(rows.length){idx=Math.max(0,idx-1);render();}
     }else if(e.key==='Enter'){
       e.preventDefault();
       if(input.dataset.dirty==='1'||!rows.length)search();else select();
     }else if(e.key==='Escape'){
       e.preventDefault();closeModal(cfg.modal);
     }
   });
   if(run)run.onclick=search;
   return search;
 }

 setupSearch({
   input:'searchPessoa',list:'listPessoa',run:'runPessoa',modal:'modalPessoa',action:'search_people',
   html:function(r){return '<b>'+esc(r.name)+'</b><small>'+esc(r.document||'')+' '+esc(r.whatsapp||r.phone||'')+'</small>'},
   select:applyPerson
 });

 var modalItemSearch=setupSearch({
   input:'searchItem',list:'listItem',run:'runItem',modal:'modalItem',action:'search_items',
   extra:function(){return '&type='+encodeURIComponent(document.getElementById('searchItemType').value)},
   html:function(r){
     var av=(parseFloat(r.estoque_atual||0)-parseFloat(r.estoque_reservado||0)).toFixed(4);
     return '<b>'+esc(r.nome)+'</b><small>Cód. '+esc(r.numero_cadastro||r.codigo_firebird||r.id)+' • '+(parseInt(r.is_service||0,10)===1?'SERVIÇO':'PRODUTO')+' • '+esc(r.categoria||'')+' • R$ '+parseFloat(r.preco||0).toFixed(2).replace('.',',')+' • disponível: '+av+'</small>';
   },
   select:applyItem
 });
 var searchItemType=document.getElementById('searchItemType');
 if(searchItemType)searchItemType.addEventListener('change',function(){
   var inp=document.getElementById('searchItem');
   if(inp&&String(inp.value||'').trim()!==''){inp.dataset.dirty='1';modalItemSearch();}
 });

 /* Pesquisa direta no campo Produto / Serviço. */
 var quickInput=document.getElementById('produto_nome');
 var quickBox=document.getElementById('produto_sugestoes');
 <?php if((string)($_GET['continue_item']??'')==='1' && $step===4 && !$editItem): ?>
 if(quickInput){setTimeout(function(){quickInput.focus({preventScroll:true});},30);}
 <?php endif; ?>
 var quickRows=[],quickIdx=-1,quickTimer=null,quickLastQuery='',quickRequest=0;

 function hideQuickResults(){if(quickBox){quickBox.classList.remove('open');quickBox.innerHTML='';}quickRows=[];quickIdx=-1;}
 function renderQuick(message){
   if(!quickBox)return;
   quickBox.innerHTML='';
   if(message){
     quickBox.innerHTML='<div class="product-quick-message">'+esc(message)+'</div>';
     quickBox.classList.add('open');
     return;
   }
   if(!quickRows.length){
     quickBox.innerHTML='<div class="product-quick-message">Nenhum produto ou serviço encontrado.</div>';
     quickBox.classList.add('open');
     return;
   }
   quickRows.slice(0,12).forEach(function(r,i){
     var row=document.createElement('div');
     row.className='product-quick-row'+(i===quickIdx?' selected':'');
     var av=(parseFloat(r.estoque_atual||0)-parseFloat(r.estoque_reservado||0)).toFixed(4);
     row.innerHTML='<b>'+esc(r.nome)+'</b><small>Cód. '+esc(r.numero_cadastro||r.codigo_firebird||r.id)+' • '+(parseInt(r.is_service||0,10)===1?'SERVIÇO':'PRODUTO')+' • '+esc(r.categoria||'')+' • R$ '+parseFloat(r.preco||0).toFixed(2).replace('.',',')+' • disponível: '+av+'</small>';
     row.addEventListener('mousedown',function(e){e.preventDefault();quickIdx=i;applyItem(r);});
     quickBox.appendChild(row);
   });
   quickBox.classList.add('open');
   if(quickIdx>=0&&quickBox.children[quickIdx])quickBox.children[quickIdx].scrollIntoView({block:'nearest'});
 }
 function runQuickSearch(){
   if(!quickInput)return;
   var q=String(quickInput.value||'').trim();
   quickLastQuery=q;
   document.getElementById('produto_id').value='';
   document.getElementById('stock_info').textContent='';
   if(q===''){hideQuickResults();return Promise.resolve();}
   var requestId=++quickRequest;
   renderQuick('Pesquisando...');
   return jsonFetch('os_ajax.php?action=search_items&q='+encodeURIComponent(q),{credentials:'same-origin'}).then(function(j){
     if(requestId!==quickRequest || String(quickInput.value||'').trim()!==q)return;
     quickRows=j.rows||[];
     quickIdx=quickRows.length?0:-1;
     renderQuick();
   }).catch(function(err){
     if(requestId!==quickRequest)return;
     quickRows=[];quickIdx=-1;
     renderQuick(err.message||'Não foi possível pesquisar produtos.');
   });
 }
 function moveQuick(delta){
   if(!quickRows.length)return;
   quickIdx=Math.max(0,Math.min(Math.min(quickRows.length,12)-1,quickIdx+delta));
   renderQuick();
 }

 if(quickInput){
   quickInput.addEventListener('input',function(){
     document.getElementById('produto_id').value='';
     document.getElementById('stock_info').textContent='';
     clearTimeout(quickTimer);
     var q=String(quickInput.value||'').trim();
     if(q===''){hideQuickResults();return;}
     quickTimer=setTimeout(runQuickSearch,260);
   });
   quickInput.addEventListener('focus',function(){
     if(quickRows.length)renderQuick();
   });
   quickInput.addEventListener('keydown',function(e){
     if(e.key==='ArrowDown'){
       e.preventDefault();moveQuick(1);
     }else if(e.key==='ArrowUp'){
       e.preventDefault();moveQuick(-1);
     }else if(e.key==='Enter'){
       e.preventDefault();
       var q=String(quickInput.value||'').trim();
       if(quickRows.length && quickIdx>=0 && q===quickLastQuery){
         applyItem(quickRows[quickIdx]);
       }else{
         clearTimeout(quickTimer);
         runQuickSearch();
       }
     }else if(e.key==='Escape'){
       hideQuickResults();
     }
   });
 }
 document.addEventListener('mousedown',function(e){
   if(!e.target.closest('.product-quick-wrap'))hideQuickResults();
 });

 function personDigits(v){return String(v||'').replace(/\D/g,'');}
 function personFormatCpf(v){var d=personDigits(v).slice(0,11);return d.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2');}
 function personFormatCnpj(v){var d=personDigits(v).slice(0,14);return d.replace(/(\d{2})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1/$2').replace(/(\d{4})(\d{1,2})$/,'$1-$2');}
 function personFormatCep(v){var d=personDigits(v).slice(0,8);return d.length>5?d.slice(0,5)+'-'+d.slice(5):d;}
 function personSet(id,v,onlyEmpty){var el=document.getElementById(id);if(!el)return;if(onlyEmpty&&String(el.value||'').trim()!=='')return;el.value=v||'';}
 function personStatus(id,text,kind){var el=document.getElementById(id);if(!el)return;el.textContent=text||'';el.className='lookup-status'+(kind?' '+kind:'');}
 function syncNewPersonType(){
   var type=document.getElementById('newPersonType'),doc=document.getElementById('newPersonDoc');if(!type||!doc)return;
   var pf=type.value==='FISICA';
   document.getElementById('newPersonDocLabel').textContent=pf?'CPF':'CNPJ';
   document.getElementById('newPersonBirthLabel').textContent=pf?'Data de nascimento':'Data de abertura';
   document.getElementById('newPersonFantasyWrap').style.display=pf?'none':'';
   document.getElementById('newPersonImWrap').style.display=pf?'none':'';
   document.getElementById('newPersonRgWrap').style.display=pf?'':'none';
   document.getElementById('newPersonIssuerWrap').style.display=pf?'':'none';
   document.getElementById('newPersonIdentityStateWrap').style.display=pf?'':'none';
   document.getElementById('btnNewPersonCnpj').style.display=pf?'none':'';
   doc.placeholder=pf?'Digite o CPF':'Digite o CNPJ';doc.value=pf?personFormatCpf(doc.value):personFormatCnpj(doc.value);
   personStatus('newPersonDocStatus','','');
 }
 function lookupNewPersonCnpj(){
   var type=document.getElementById('newPersonType');if(!type||type.value!=='JURIDICA')return;
   var cnpj=personDigits(document.getElementById('newPersonDoc').value);if(cnpj.length!==14){personStatus('newPersonDocStatus','Informe um CNPJ com 14 dígitos.','err');return;}
   personStatus('newPersonDocStatus','Consultando CNPJ e dados fiscais...','load');
   fetch('pessoa_cnpj_lookup.php?cnpj='+encodeURIComponent(cnpj),{cache:'no-store',credentials:'same-origin'}).then(function(r){return r.json().then(function(j){if(!r.ok||!j.ok)throw new Error(j.error||'CNPJ não encontrado.');return j;});}).then(function(j){
     var d=j.data||{};personSet('newPersonName',d.razao_social||'',true);personSet('newPersonFantasy',d.nome_fantasia||'',true);personSet('newPersonEmail',d.email||'',true);personSet('newPersonPhone',d.telefone||'',true);personSet('newPersonWhats',d.telefone||'',true);personSet('newPersonIe',d.state_registration||'',true);personSet('newPersonIm',d.municipal_registration||'',true);personSet('newPersonBirth',d.data_inicio_atividade||'',true);if(d.cep)personSet('newPersonZip',personFormatCep(d.cep),true);personSet('newPersonAddress',d.logradouro||'',true);personSet('newPersonNumber',d.numero||'',true);personSet('newPersonComplement',d.complemento||'',true);personSet('newPersonDistrict',d.bairro||'',true);personSet('newPersonCity',d.municipio||'',true);personSet('newPersonCityIbge',d.city_ibge||'',true);personSet('newPersonState',d.uf||'',true);personStatus('newPersonDocStatus','Dados do CNPJ carregados. Confira antes de salvar.','ok');
   }).catch(function(e){personStatus('newPersonDocStatus',e.message||'Não foi possível consultar o CNPJ.','err');});
 }
 function lookupNewPersonCep(showError){
   var cep=personDigits(document.getElementById('newPersonZip').value);if(cep.length!==8){if(showError)personStatus('newPersonZipStatus','Informe um CEP com 8 dígitos.','err');return;}
   personStatus('newPersonZipStatus','Consultando CEP...','load');
   fetch('https://viacep.com.br/ws/'+cep+'/json/',{cache:'no-store'}).then(function(r){if(!r.ok)throw new Error('CEP não encontrado.');return r.json();}).then(function(d){if(d.erro)throw new Error('CEP não encontrado.');personSet('newPersonAddress',d.logradouro||'',false);personSet('newPersonDistrict',d.bairro||'',false);personSet('newPersonCity',d.localidade||'',false);personSet('newPersonCityIbge',d.ibge||'',false);personSet('newPersonState',d.uf||'',false);if(d.complemento)personSet('newPersonComplement',d.complemento,true);personStatus('newPersonZipStatus','Endereço carregado pelo CEP.','ok');}).catch(function(e){personStatus('newPersonZipStatus',e.message||'Não foi possível consultar o CEP.','err');});
 }
 var newType=document.getElementById('newPersonType'),newDoc=document.getElementById('newPersonDoc'),newZip=document.getElementById('newPersonZip');
 if(newType)newType.addEventListener('change',syncNewPersonType);
 if(newDoc){newDoc.addEventListener('input',function(){this.value=(newType&&newType.value==='JURIDICA')?personFormatCnpj(this.value):personFormatCpf(this.value);});newDoc.addEventListener('blur',function(){if(newType&&newType.value==='JURIDICA'&&personDigits(this.value).length===14)lookupNewPersonCnpj();});}
 if(newZip){newZip.addEventListener('input',function(){this.value=personFormatCep(this.value);});newZip.addEventListener('blur',function(){lookupNewPersonCep(true);});}
 var btnCnpj=document.getElementById('btnNewPersonCnpj');if(btnCnpj)btnCnpj.onclick=lookupNewPersonCnpj;
 function resetNewPersonForm(){var ids=['newPersonDoc','newPersonBirth','newPersonName','newPersonFantasy','newPersonIe','newPersonIm','newPersonRg','newPersonIssuer','newPersonIdentityState','newPersonEmail','newPersonWhats','newPersonPhone','newPersonZip','newPersonAddress','newPersonNumber','newPersonDistrict','newPersonCity','newPersonCityIbge','newPersonState','newPersonComplement'];ids.forEach(function(id){var el=document.getElementById(id);if(el)el.value='';});var t=document.getElementById('newPersonType');if(t)t.value='FISICA';personStatus('newPersonDocStatus','','');personStatus('newPersonZipStatus','','');syncNewPersonType();}
 function openNewPersonModal(){resetNewPersonForm();closeModal('modalPessoa');var m=document.getElementById('modalNovaPessoa');if(m)m.classList.add('open');setTimeout(function(){var t=document.getElementById('newPersonType');if(t)t.focus();},40);}
 var show=document.getElementById('showNewPerson');if(show)show.onclick=openNewPersonModal;
 var cancelNew=document.getElementById('cancelNewPerson');if(cancelNew)cancelNew.onclick=function(){closeModal('modalNovaPessoa');openModal('modalPessoa','searchPessoa');};
 var save=document.getElementById('saveNewPerson');
 if(save)save.onclick=function(){
   var name=String(document.getElementById('newPersonName').value||'').trim();if(!name){alert('Informe o nome/razão social.');document.getElementById('newPersonName').focus();return;}
   var fd=new FormData();fd.append('action','create_person');
   var fields={person_type:'newPersonType',name:'newPersonName',fantasy_name:'newPersonFantasy',document:'newPersonDoc',birth_opening_date:'newPersonBirth',state_registration:'newPersonIe',municipal_registration:'newPersonIm',identity_document:'newPersonRg',identity_issuer:'newPersonIssuer',identity_state:'newPersonIdentityState',email:'newPersonEmail',whatsapp:'newPersonWhats',phone:'newPersonPhone',zip_code:'newPersonZip',address:'newPersonAddress',number:'newPersonNumber',district:'newPersonDistrict',city:'newPersonCity',city_ibge:'newPersonCityIbge',state:'newPersonState',complement:'newPersonComplement'};
   Object.keys(fields).forEach(function(k){var el=document.getElementById(fields[k]);fd.append(k,el?el.value:'');});
   save.disabled=true;var oldText=save.textContent;save.textContent='Salvando...';
   jsonFetch('os_ajax.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(j){
     applyPerson(j.row);
     closeModal('modalNovaPessoa');
   }).catch(function(err){alert(err.message||'Erro ao cadastrar a pessoa.');}).finally(function(){save.disabled=false;save.textContent=oldText;});
 };
 function pv(v){v=String(v==null?'':v).trim();return v!==''?esc(v):'<span class="person-view-empty">Não informado</span>';}
 function pvDate(v){v=String(v||'').trim();if(!/^\d{4}-\d{2}-\d{2}/.test(v))return pv(v);var p=v.slice(0,10).split('-');return esc(p[2]+'/'+p[1]+'/'+p[0]);}
 function pvField(label,value,cls){return '<div class="person-view-field'+(cls?' '+cls:'')+'"><small>'+esc(label)+'</small><strong>'+value+'</strong></div>';}
 function renderPersonView(r){
   var rawType=String(r.person_type||'').toUpperCase(),docDigits=String(r.document||'').replace(/\D/g,'');var type=rawType==='JURIDICA'||(rawType!=='FISICA'&&docDigits.length>11)?'Pessoa Jurídica':'Pessoa Física';
   var birthLabel=type==='Pessoa Jurídica'?'Data de abertura':'Data de nascimento';
   var html='<div class="person-view-section"><h4>Tipo e identificação</h4><div class="person-view-grid">';
   html+=pvField('Número',pv(r.numero_cadastro||r.id));html+=pvField('Tipo de pessoa',pv(type));html+=pvField(type==='Pessoa Jurídica'?'CNPJ':'CPF',pv(r.document));
   html+=pvField('Nome / Razão social',pv(r.name),'wide');html+=pvField('Nome fantasia',pv(r.fantasy_name));html+=pvField(birthLabel,pvDate(r.birth_opening_date));html+='</div></div>';
   html+='<div class="person-view-section"><h4>Dados fiscais e identidade</h4><div class="person-view-grid">';
   html+=pvField('Inscrição Estadual (IE)',pv(r.state_registration));html+=pvField('Inscrição Municipal (IM)',pv(r.municipal_registration));html+=pvField('RG / Identidade',pv(r.identity_document));html+=pvField('Órgão expedidor',pv(r.identity_issuer));html+=pvField('UF da identidade',pv(r.identity_state));html+='</div></div>';
   html+='<div class="person-view-section"><h4>Contato</h4><div class="person-view-grid">';
   html+=pvField('E-mail',pv(r.email));html+=pvField('WhatsApp',pv(r.whatsapp));html+=pvField('Telefone',pv(r.phone));html+='</div></div>';
   html+='<div class="person-view-section"><h4>Endereço</h4><div class="person-view-grid">';
   html+=pvField('CEP',pv(r.zip_code));html+=pvField('Endereço',pv(r.address),'wide');html+=pvField('Número',pv(r.number));html+=pvField('Bairro',pv(r.district));html+=pvField('Cidade',pv(r.city));html+=pvField('Código IBGE',pv(r.city_ibge));html+=pvField('UF',pv(r.state));html+=pvField('Complemento',pv(r.complement),'wide');html+='</div></div>';
   var roles=[];if(parseInt(r.is_customer||0,10)===1)roles.push('Cliente');if(parseInt(r.is_supplier||0,10)===1)roles.push('Fornecedor');if(parseInt(r.is_seller||0,10)===1)roles.push('Vendedor');if(parseInt(r.is_employee||0,10)===1)roles.push('Colaborador');if(parseInt(r.is_professional||0,10)===1)roles.push('Profissional');if(parseInt(r.is_carrier||0,10)===1)roles.push('Transportadora');
   html+='<div class="person-view-section"><h4>Classificação e observações</h4><div class="person-view-grid"><div class="person-view-field full"><small>Classificação</small><div class="person-view-badges">'+(roles.length?roles.map(function(x){return '<span class="person-view-badge">'+esc(x)+'</span>';}).join(''):'<span class="person-view-empty">Não informado</span>')+'</div></div>'+pvField('Observações',pv(r.notes),'full')+'</div></div>';
   return html;
 }
 if(bv)bv.onclick=function(){var pid=parseInt((document.getElementById('person_id')||{}).value||'0',10);if(pid<=0)return;var m=document.getElementById('modalPessoaView'),box=document.getElementById('personViewContent');if(box){box.className='person-view-loading';box.innerHTML='Carregando cadastro...';}if(m)m.classList.add('open');jsonFetch('os_ajax.php?action=person_details&id='+encodeURIComponent(pid),{credentials:'same-origin'}).then(function(j){if(box){box.className='';box.innerHTML=renderPersonView(j.row||{});}}).catch(function(err){if(box){box.className='person-view-loading';box.textContent=err.message||'Não foi possível carregar o cadastro.';}});};
 syncPersonViewButton();
 syncNewPersonType();

 /* Consulta do veículo pela placa cadastrada nas O.S. anteriores. */
 var plateInput=document.querySelector('[data-semantic="vehicle_plate"]');
 var plateButton=document.querySelector('[data-plate-lookup]');
 var vehicleMsg=document.getElementById('vehicleLookupMessage');
 function normalizePlate(v){return String(v||'').toUpperCase().replace(/[^A-Z0-9]/g,'').slice(0,10);}
 function setVehicleMessage(text,kind){if(!vehicleMsg)return;vehicleMsg.textContent=text||'';vehicleMsg.className='vehicle-lookup-note'+(kind?' '+kind:'');}
 function applyVehicleData(values){
   values=values||{};
   var targets=Array.from(document.querySelectorAll('[data-semantic]'));
   var conflicts=targets.filter(function(el){var role=el.dataset.semantic||'';var incoming=values[role];if(incoming==null||String(incoming)==='')return false;var current=el.type==='checkbox'?(el.checked?'1':'0'):String(el.value||'');return current!==''&&current!==String(incoming)&&role!=='vehicle_plate';});
   if(conflicts.length && !confirm('Já existem dados preenchidos para este veículo. Deseja substituí-los pelos dados cadastrados anteriormente?'))return false;
   targets.forEach(function(el){var role=el.dataset.semantic||'';if(!Object.prototype.hasOwnProperty.call(values,role))return;var val=values[role];if(val==null)return;if(el.type==='checkbox')el.checked=String(val)==='1';else el.value=String(val);});
   if(plateInput)plateInput.value=normalizePlate(values.vehicle_plate||plateInput.value);
   return true;
 }
 function lookupVehicle(){
   if(!plateInput)return;
   var plate=normalizePlate(plateInput.value);plateInput.value=plate;
   if(plate.length<6){setVehicleMessage('Informe uma placa válida para consultar.','warn');return;}
   setVehicleMessage('Consultando veículo...','');
   jsonFetch('os_ajax.php?action=vehicle_by_plate&plate='+encodeURIComponent(plate),{credentials:'same-origin'}).then(function(j){
     if(normalizePlate(plateInput.value)!==plate)return;
     if(!j.found){setVehicleMessage('Placa ainda não localizada. Os dados informados serão cadastrados ao salvar esta etapa.','warn');return;}
     if(applyVehicleData(j.values||{})){
       var ref=j.last_order_number?(' • última O.S. #'+String(j.last_order_number).padStart(6,'0')):'';
       setVehicleMessage('Veículo localizado e dados carregados'+ref+'.','ok');
     }
   }).catch(function(err){setVehicleMessage(err.message||'Não foi possível consultar a placa.','error');});
 }
 if(plateInput){plateInput.addEventListener('input',function(){this.value=normalizePlate(this.value);setVehicleMessage('Digite a placa e consulte para carregar dados já cadastrados neste veículo.','');});plateInput.addEventListener('keydown',function(e){if(e.key==='Enter'){e.preventDefault();lookupVehicle();}});}
 if(plateButton)plateButton.addEventListener('click',lookupVehicle);
 if(plateInput)plateInput.addEventListener('nexa-plate-recognized',lookupVehicle);

 /* Formas de pagamento da O.S. no padrão operacional do PDV. */
 var osPaymentMap=<?php echo json_encode($paymentMapForJs, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES); ?>||{};
 var osPaymentMethod=document.getElementById('os_payment_method');
 var osPaymentAmount=document.getElementById('os_payment_amount');
 var osPaymentDueWrap=document.getElementById('os_term_due');
 var osPaymentDue=document.getElementById('os_payment_due');
 var osToday=<?php echo json_encode(date('Y-m-d')); ?>;
 function osAddDays(iso,days){var p=String(iso).split('-').map(Number),d=new Date(Date.UTC(p[0],p[1]-1,p[2]));d.setUTCDate(d.getUTCDate()+Number(days||0));return d.toISOString().slice(0,10);}
 function osNextFixedDay(iso,day){var p=String(iso).split('-').map(Number),base=new Date(Date.UTC(p[0],p[1]-1,p[2])),wanted=Math.max(1,Math.min(31,Number(day||1)));for(var i=0;i<24;i++){var d=new Date(Date.UTC(base.getUTCFullYear(),base.getUTCMonth()+i,1)),last=new Date(Date.UTC(d.getUTCFullYear(),d.getUTCMonth()+1,0)).getUTCDate();d.setUTCDate(Math.min(wanted,last));if(d>base)return d.toISOString().slice(0,10);}return osAddDays(iso,30);}
 function osMethodIsTerm(m){if(!m)return false;var t=String((m.name||'')+' '+(m.type||'')).toLowerCase();return Number(m.receivable||0)===1||t.indexOf('prazo')>=0||t.indexOf('credi')>=0||t.indexOf('fiado')>=0;}
 function updateOsPaymentMethod(){
   if(!osPaymentMethod)return;
   var m=osPaymentMap[String(osPaymentMethod.value)]||null;
   var isTerm=osMethodIsTerm(m);
   if(osPaymentAmount && osPaymentMethod.value){var remaining=parseFloat(osPaymentAmount.dataset.remaining||'0')||0;osPaymentAmount.value=remaining.toFixed(2).replace('.',',');}
   if(osPaymentDueWrap)osPaymentDueWrap.classList.toggle('show',isTerm);
   if(osPaymentDue){osPaymentDue.required=isTerm;if(isTerm&&!osPaymentDue.value){var fixed=m?Number(m.fixedDueDay||0):0;osPaymentDue.value=(m&&Number(m.creRulesEnabled||0)===1&&Number(m.keepDueDay||0)===1&&fixed>0)?osNextFixedDay(osToday,fixed):osAddDays(osToday,m?Number(m.firstDueDays||30):30);}if(!isTerm)osPaymentDue.value='';}
 }
 if(osPaymentMethod){osPaymentMethod.addEventListener('change',updateOsPaymentMethod);updateOsPaymentMethod();}

 window.quickAddOption=function(fieldId,selectId){
   var label=prompt('Digite a nova opção:');if(!label)return;
   var fd=new FormData();fd.append('action','quick_add_option');fd.append('field_id',fieldId);fd.append('label',label);
   jsonFetch('os_ajax.php',{method:'POST',body:fd,credentials:'same-origin'}).then(function(j){
     var sel=document.getElementById(selectId),opt=document.createElement('option');
     opt.value=j.row.value;opt.textContent=j.row.label;opt.selected=true;sel.appendChild(opt);
   }).catch(function(err){alert(err.message||'Erro ao cadastrar a opção.');});
 };
})();
</script>
<script src="assets/os_plate_ocr.js?v=20261019_2"></script>
<?php os_page_end(); ?>
