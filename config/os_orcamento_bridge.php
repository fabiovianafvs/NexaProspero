<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';

require_once __DIR__ . '/os_module.php';
require_once __DIR__ . '/orcamento_module.php';

if (!function_exists('os_quote_bridge_table_exists')) {
  function os_quote_bridge_table_exists(PDO $pdo, string $table): bool {
    try {
      $st=$pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
      $st->execute([$table]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('os_quote_bridge_schema_ready')) {
  function os_quote_bridge_schema_ready(PDO $pdo): bool {
    return os_quote_bridge_table_exists($pdo,'os_quote_links');
  }
}

if (!function_exists('os_quote_bridge_link')) {
  function os_quote_bridge_link(PDO $pdo, int $companyId, int $quoteId): ?array {
    if ($quoteId<=0 || !os_quote_bridge_schema_ready($pdo)) return null;
    $st=$pdo->prepare('SELECT l.*,o.os_number,o.status_id FROM os_quote_links l JOIN os_orders o ON o.company_id=l.company_id AND o.id=l.os_order_id WHERE l.company_id=? AND l.quote_id=? LIMIT 1');
    $st->execute([$companyId,$quoteId]);
    $row=$st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('os_quote_bridge_map')) {
  function os_quote_bridge_map(PDO $pdo, int $companyId, array $quoteIds): array {
    if (!os_quote_bridge_schema_ready($pdo)) return [];
    $ids=array_values(array_unique(array_filter(array_map('intval',$quoteIds),fn($v)=>$v>0)));
    if(!$ids) return [];
    $ph=implode(',',array_fill(0,count($ids),'?'));
    $st=$pdo->prepare("SELECT l.quote_id,l.os_order_id,o.os_number FROM os_quote_links l JOIN os_orders o ON o.company_id=l.company_id AND o.id=l.os_order_id WHERE l.company_id=? AND l.quote_id IN ($ph)");
    $st->execute(array_merge([$companyId],$ids));
    $out=[];
    foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $row)$out[(int)$row['quote_id']]=$row;
    return $out;
  }
}

if (!function_exists('os_quote_bridge_search')) {
  function os_quote_bridge_search(PDO $pdo, int $companyId, string $q='', int $limit=150): array {
    if(!os_quote_bridge_schema_ready($pdo)) return [];
    $limit=max(1,min(500,$limit));
    $where=["q.company_id=?","q.status NOT IN ('RECUSADO','CANCELADO')","l.id IS NULL"];
    $params=[$companyId];
    $q=trim($q);
    if($q!==''){
      foreach(array_slice(preg_split('/\s+/u',$q,-1,PREG_SPLIT_NO_EMPTY)?:[],0,6) as $token){
        $where[]='(CAST(q.quote_number AS CHAR) LIKE ? OR q.person_name_snapshot LIKE ? OR q.person_document_snapshot LIKE ? OR q.notes LIKE ?)';
        for($i=0;$i<4;$i++)$params[]=nexa_search_like($token);
      }
    }
    $sql='SELECT q.* FROM orcamentos q LEFT JOIN os_quote_links l ON l.company_id=q.company_id AND l.quote_id=q.id WHERE '.implode(' AND ',$where).' ORDER BY q.quote_date DESC,q.quote_number DESC,q.id DESC LIMIT '.$limit;
    $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('os_quote_bridge_future_quantity')) {
  function os_quote_bridge_future_quantity(PDO $pdo, int $companyId, int $quoteId): float {
    if(!os_quote_bridge_table_exists($pdo,'delivery_control_quote_items')) return 0.0;
    try{$st=$pdo->prepare('SELECT COALESCE(SUM(future_quantity),0) FROM delivery_control_quote_items WHERE company_id=? AND quote_id=?');$st->execute([$companyId,$quoteId]);return (float)$st->fetchColumn();}catch(Throwable $e){return 0.0;}
  }
}

if (!function_exists('os_quote_bridge_create')) {
  function os_quote_bridge_create(PDO $pdo, int $companyId, int $quoteId, int $userId=0): int {
    if($quoteId<=0) throw new RuntimeException('Orçamento inválido.');
    if(!os_quote_bridge_schema_ready($pdo)) throw new RuntimeException('A V11.40 ainda não foi aplicada no Atualizador do Banco.');
    if(!os_module_enabled($pdo,$companyId)) throw new RuntimeException('O módulo Ordem de Serviço não está habilitado para esta empresa.');
    if(!orc_module_enabled($pdo,$companyId)) throw new RuntimeException('O módulo Orçamento não está habilitado para esta empresa.');

    $ownTx=!$pdo->inTransaction();
    if($ownTx)$pdo->beginTransaction();
    try{
      $st=$pdo->prepare('SELECT * FROM orcamentos WHERE company_id=? AND id=? LIMIT 1 FOR UPDATE');
      $st->execute([$companyId,$quoteId]);$quote=$st->fetch(PDO::FETCH_ASSOC);
      if(!$quote)throw new RuntimeException('Orçamento não encontrado.');
      if(in_array((string)$quote['status'],['RECUSADO','CANCELADO'],true))throw new RuntimeException('Este orçamento não pode gerar O.S. porque está '.orc_status_label((string)$quote['status']).'.');

      $existing=os_quote_bridge_link($pdo,$companyId,$quoteId);
      if($existing)throw new RuntimeException('Este orçamento já gerou a O.S. #'.str_pad((string)(int)$existing['os_number'],6,'0',STR_PAD_LEFT).'.');
      $future=os_quote_bridge_future_quantity($pdo,$companyId,$quoteId);
      if($future>0.00001)throw new RuntimeException('Este orçamento possui quantidade marcada para entrega futura. Converta-o pelo Pedido/Controle de Entregas para preservar o fluxo de estoque e fiscal.');

      $st=$pdo->prepare('SELECT * FROM orcamento_items WHERE company_id=? AND quote_id=? ORDER BY id FOR UPDATE');$st->execute([$companyId,$quoteId]);$items=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
      if(!$items)throw new RuntimeException('Inclua ao menos um produto ou serviço antes de criar a O.S.');
      $st=$pdo->prepare('SELECT * FROM orcamento_payments WHERE company_id=? AND quote_id=? ORDER BY due_date,id');$st->execute([$companyId,$quoteId]);$payments=$st->fetchAll(PDO::FETCH_ASSOC)?:[];

      $number=os_next_number($pdo,$companyId);$statusId=os_initial_status_id($pdo,$companyId);
      $expected=null;
      if(!empty($quote['delivery_date']))$expected=(string)$quote['delivery_date'].' '.(!empty($quote['delivery_time'])?substr((string)$quote['delivery_time'],0,8):'18:00:00');
      $contact=trim((string)($quote['person_phone_snapshot']??''));
      $title='Orçamento #'.str_pad((string)(int)$quote['quote_number'],6,'0',STR_PAD_LEFT);
      $quoteDiscount=max(0.0,(float)($quote['discount_amount']??0));$quoteAddition=max(0.0,(float)($quote['addition_amount']??0))+max(0.0,(float)($quote['freight_amount']??0));
      $stmt=$pdo->prepare('INSERT INTO os_orders (company_id,os_number,person_id,person_name_snapshot,person_document_snapshot,person_phone_snapshot,contact_name,contact_phone,status_id,opened_at,expected_at,title,customer_notes,discount_amount,addition_amount,created_by,updated_by) VALUES (?,?,?,?,?,?,?,?,?,NOW(),?,?,?,?,?,?,?)');
      $stmt->execute([$companyId,$number,(int)($quote['person_id']??0)?:null,$quote['person_name_snapshot']?:null,$quote['person_document_snapshot']?:null,$quote['person_phone_snapshot']?:null,$quote['person_name_snapshot']?:null,$contact!==''?$contact:null,$statusId>0?$statusId:null,$expected,$title,trim((string)($quote['notes']??''))?:null,$quoteDiscount,$quoteAddition,$userId?:null,$userId?:null]);
      $orderId=(int)$pdo->lastInsertId();

      $ins=$pdo->prepare('INSERT INTO os_order_items (company_id,order_id,produto_id,item_type,description_snapshot,unidade_snapshot,quantity,unit_price,total,reserve_stock,reserved_quantity,reservation_status,seller_person_id,seller_name_snapshot,technician_person_id,technician_name_snapshot,notes) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $move=$pdo->prepare("UPDATE orcamento_items SET reserve_stock=0,reserved_quantity=0,reservation_status='TRANSFERIDO_OS',updated_at=NOW() WHERE company_id=? AND quote_id=? AND id=? AND reservation_status='RESERVADO'");
      foreach($items as $item){
        $qty=(float)$item['quantity'];if($qty<=0)continue;
        $total=(float)$item['total'];$unit=$qty>0?round($total/$qty,4):(float)$item['unit_price'];
        $reserved=((string)$item['reservation_status']==='RESERVADO')?max(0.0,min($qty,(float)$item['reserved_quantity'])):0.0;
        $resStatus=$reserved>0.00001?'RESERVADO':'NAO_RESERVADO';
        $respId=(int)($quote['professional_person_id']??0);$respName=trim((string)($quote['professional_name_snapshot']??''));
        if($respId<=0){$respId=(int)($quote['seller_person_id']??0);$respName=trim((string)($quote['seller_name_snapshot']??''));}
        $ins->execute([$companyId,$orderId,(int)$item['produto_id'],(string)$item['item_type'],(string)$item['description_snapshot'],$item['unidade_snapshot']?:null,$qty,$unit,$total,$reserved>0.00001?1:0,$reserved,$resStatus,$respId>0?$respId:null,$respName!==''?$respName:null,null,null,$item['notes']?:null]);
        if($reserved>0.00001)$move->execute([$companyId,$quoteId,(int)$item['id']]);
      }

      if($payments){
        $insPay=$pdo->prepare('INSERT INTO os_order_payments (company_id,order_id,payment_method_id,payment_label,amount,due_date,notes) VALUES (?,?,?,?,?,?,?)');
        foreach($payments as $p)$insPay->execute([$companyId,$orderId,(int)($p['payment_method_id']??0)?:null,$p['payment_label']?:null,(float)$p['amount'],$p['due_date']?:null,$p['notes']?:null]);
      }
      os_recalculate_totals($pdo,$companyId,$orderId);
      $pdo->prepare("UPDATE orcamentos SET status=CASE WHEN status='ABERTO' THEN 'APROVADO' ELSE status END,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$userId?:null,$companyId,$quoteId]);
      $pdo->prepare('INSERT INTO os_quote_links (company_id,quote_id,os_order_id,created_by) VALUES (?,?,?,?)')->execute([$companyId,$quoteId,$orderId,$userId?:null]);
      if($statusId>0)$pdo->prepare('INSERT INTO os_status_history (company_id,order_id,from_status_id,to_status_id,user_id,notes,created_at) VALUES (?,?,NULL,?,?,?,NOW())')->execute([$companyId,$orderId,$statusId,$userId?:null,'O.S. criada a partir do Orçamento #'.(int)$quote['quote_number']]);
      if($ownTx)$pdo->commit();
      return $orderId;
    }catch(Throwable $e){if($ownTx&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}
