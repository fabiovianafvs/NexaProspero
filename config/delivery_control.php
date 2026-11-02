<?php
declare(strict_types=1);

/**
 * Nexa Próspero V11.39 - Controle de Entregas
 * Fluxo isolado: orçamento -> pedido comercial -> entrega futura -> O.E. -> romaneio.
 * Nenhum DDL é executado em runtime. A estrutura vem da migration controlada.
 */

require_once __DIR__ . '/saas_modules.php';
require_once __DIR__ . '/orcamento_module.php';
require_once __DIR__ . '/company_sequences.php';

if (!function_exists('delivery_control_table_exists')) {
    function delivery_control_table_exists(PDO $pdo, string $table): bool {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        static $cache = [];
        $key = spl_object_id($pdo) . ':' . $table;
        if (array_key_exists($key, $cache)) return $cache[$key];
        try {
            $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=? LIMIT 1');
            $st->execute([$table]);
            return $cache[$key] = (bool)$st->fetchColumn();
        } catch (Throwable $e) { return $cache[$key] = false; }
    }
}

if (!function_exists('delivery_control_schema_ready')) {
    function delivery_control_schema_ready(PDO $pdo): bool {
        foreach (['delivery_control_quote_items','delivery_control_sales','delivery_control_sale_items','delivery_control_orders','delivery_control_order_items','delivery_control_manifests','delivery_control_manifest_orders','delivery_control_fiscal_queue'] as $t) {
            if (!delivery_control_table_exists($pdo, $t)) return false;
        }
        return true;
    }
}

if (!function_exists('delivery_control_enabled')) {
    function delivery_control_enabled(PDO $pdo, int $companyId): bool {
        return $companyId > 0 && function_exists('saas_company_has_module') && saas_company_has_module($pdo, $companyId, 'controle_entregas');
    }
}

if (!function_exists('delivery_control_require_enabled')) {
    function delivery_control_require_enabled(PDO $pdo, int $companyId): void {
        if (!delivery_control_enabled($pdo, $companyId)) {
            http_response_code(403);
            die('Módulo Controle de Entregas não habilitado para esta empresa.');
        }
        if (!delivery_control_schema_ready($pdo)) {
            http_response_code(503);
            die('Estrutura do Controle de Entregas pendente. Aplique a migration V11.39 no Atualizador do Banco.');
        }
    }
}

if (!function_exists('delivery_control_decimal')) {
    function delivery_control_decimal($v, int $decimals = 4): float {
        $s = trim((string)$v);
        if ($s === '') return 0.0;
        if (str_contains($s, ',')) { $s = str_replace('.', '', $s); $s = str_replace(',', '.', $s); }
        $s = preg_replace('/[^0-9.\-]+/', '', $s) ?: '0';
        return round((float)$s, $decimals);
    }
}

if (!function_exists('delivery_control_event')) {
    function delivery_control_event(PDO $pdo, int $companyId, string $entityType, int $entityId, string $eventType, ?int $userId = null, array $payload = []): void {
        try {
            $pdo->prepare('INSERT INTO delivery_control_events(company_id,entity_type,entity_id,event_type,user_id,payload_json) VALUES(?,?,?,?,?,?)')
                ->execute([$companyId,$entityType,$entityId,$eventType,$userId ?: null,$payload ? json_encode($payload, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES) : null]);
        } catch (Throwable $e) {}
    }
}

if (!function_exists('delivery_control_quote_item')) {
    function delivery_control_quote_item(PDO $pdo, int $companyId, int $quoteItemId): ?array {
        if (!delivery_control_schema_ready($pdo)) return null;
        $st=$pdo->prepare('SELECT * FROM delivery_control_quote_items WHERE company_id=? AND quote_item_id=? LIMIT 1');
        $st->execute([$companyId,$quoteItemId]);
        return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}

if (!function_exists('delivery_control_quote_map')) {
    function delivery_control_quote_map(PDO $pdo, int $companyId, int $quoteId): array {
        if (!delivery_control_schema_ready($pdo) || $quoteId <= 0) return [];
        $st=$pdo->prepare('SELECT * FROM delivery_control_quote_items WHERE company_id=? AND quote_id=? ORDER BY quote_item_id');
        $st->execute([$companyId,$quoteId]);
        $out=[];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['quote_item_id']]=$r;
        return $out;
    }
}

if (!function_exists('delivery_control_quote_save_item')) {
    function delivery_control_quote_save_item(PDO $pdo, int $companyId, int $quoteId, int $quoteItemId, int $productId, float $futureQty): void {
        if (!delivery_control_enabled($pdo,$companyId) || !delivery_control_schema_ready($pdo)) return;
        $futureQty=max(0,round($futureQty,4));
        if ($futureQty <= 0) {
            $pdo->prepare('DELETE FROM delivery_control_quote_items WHERE company_id=? AND quote_item_id=?')->execute([$companyId,$quoteItemId]);
            return;
        }
        $pdo->prepare('INSERT INTO delivery_control_quote_items(company_id,quote_id,quote_item_id,product_id,future_quantity) VALUES(?,?,?,?,?) ON DUPLICATE KEY UPDATE quote_id=VALUES(quote_id),product_id=VALUES(product_id),future_quantity=VALUES(future_quantity),updated_at=NOW()')
            ->execute([$companyId,$quoteId,$quoteItemId,$productId,$futureQty]);
    }
}

if (!function_exists('delivery_control_quote_remove_item')) {
    function delivery_control_quote_remove_item(PDO $pdo, int $companyId, int $quoteItemId): void {
        if (!delivery_control_schema_ready($pdo)) return;
        $pdo->prepare('DELETE FROM delivery_control_quote_items WHERE company_id=? AND quote_item_id=?')->execute([$companyId,$quoteItemId]);
    }
}

if (!function_exists('delivery_control_quote_future_rows')) {
    function delivery_control_quote_future_rows(PDO $pdo, int $companyId, int $quoteId): array {
        if (!delivery_control_schema_ready($pdo)) return [];
        $st=$pdo->prepare("SELECT dqi.*,oi.quantity,oi.description_snapshot,oi.unidade_snapshot,oi.reserved_quantity,oi.reservation_status,oi.item_type
            FROM delivery_control_quote_items dqi
            JOIN orcamento_items oi ON oi.company_id=dqi.company_id AND oi.id=dqi.quote_item_id AND oi.quote_id=dqi.quote_id
            WHERE dqi.company_id=? AND dqi.quote_id=? AND dqi.future_quantity>0 ORDER BY oi.id");
        $st->execute([$companyId,$quoteId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('delivery_control_prepare_order_post')) {
    /**
     * Ajusta somente a quantidade que baixa estoque; o pedido comercial mantém a quantidade total.
     * Se houver entrega futura, bloqueia a emissão fiscal padrão do pedido completo e cria a fila isolada após a gravação.
     */
    function delivery_control_prepare_order_post(PDO $pdo, int $companyId, array &$post): array {
        $quoteId=(int)($post['orcamento_id'] ?? 0);
        if ($quoteId<=0 || !delivery_control_enabled($pdo,$companyId) || !delivery_control_schema_ready($pdo)) return ['active'=>false,'quote_id'=>$quoteId,'requested_doc'=>'none'];
        $futureRows=delivery_control_quote_future_rows($pdo,$companyId,$quoteId);
        if (!$futureRows) return ['active'=>false,'quote_id'=>$quoteId,'requested_doc'=>'none'];

        $products=array_values((array)($post['item_product_id'] ?? []));
        $qtys=array_values((array)($post['item_qty'] ?? []));
        $stockQty=[];$futureQty=[];$quoteItemIds=[];$used=[];$hasFuture=false;
        foreach ($products as $i=>$pidRaw) {
            $pid=(int)$pidRaw;$postedQty=delivery_control_decimal($qtys[$i] ?? 0,4);
            $match=null;
            foreach ($futureRows as $k=>$r) {
                if (isset($used[$k])) continue;
                if ((int)$r['product_id'] !== $pid) continue;
                $orig=round((float)$r['quantity'],4);
                if (abs($orig-$postedQty) > 0.00011) continue;
                $match=['k'=>$k,'row'=>$r];break;
            }
            if (!$match) {
                $stockQty[]=$postedQty;$futureQty[]=0;$quoteItemIds[]=0;continue;
            }
            $used[$match['k']]=true;$r=$match['row'];$fq=min($postedQty,max(0,(float)$r['future_quantity']));
            if ($fq>0.00001) $hasFuture=true;
            $stockQty[]=round(max(0,$postedQty-$fq),4);$futureQty[]=round($fq,4);$quoteItemIds[]=(int)$r['quote_item_id'];
        }
        if (!$hasFuture) return ['active'=>false,'quote_id'=>$quoteId,'requested_doc'=>'none'];
        if (count($used) !== count($futureRows)) throw new RuntimeException('Os itens do orçamento com entrega futura foram alterados no pedido. Reimporte o orçamento antes de finalizar.');

        $requested=strtolower(trim((string)($post['emit_doc_after'] ?? 'none')));
        if (!in_array($requested,['nfe','nfce','nfse'],true)) $requested='none';
        if ($requested === 'nfse') {
            throw new RuntimeException('O Controle de Entregas futura trabalha com produtos e fila fiscal NF-e/NFC-e. Para NFS-e, finalize sem emissão automática e trate o serviço separadamente.');
        }
        $post['item_stock_qty']=$stockQty;
        $post['item_future_qty']=$futureQty;
        $post['item_quote_item_id']=$quoteItemIds;
        $post['delivery_control_active']='1';
        $post['delivery_requested_doc']=$requested;
        // Nunca envia o pedido comercial completo ao emissor fiscal quando há saldo futuro.
        if ($requested !== 'none') $post['emit_doc_after']='none';
        return ['active'=>true,'quote_id'=>$quoteId,'requested_doc'=>$requested,'future_rows'=>$futureRows];
    }
}

if (!function_exists('delivery_control_next_number')) {
    function delivery_control_next_number(PDO $pdo, int $companyId, string $kind): int {
        $pdo->prepare('INSERT IGNORE INTO delivery_control_sequences(company_id,last_order_number,last_manifest_number) VALUES(?,0,0)')->execute([$companyId]);
        $col=$kind==='manifest'?'last_manifest_number':'last_order_number';
        $st=$pdo->prepare("SELECT {$col} FROM delivery_control_sequences WHERE company_id=? FOR UPDATE");$st->execute([$companyId]);
        $next=(int)$st->fetchColumn()+1;
        $pdo->prepare("UPDATE delivery_control_sequences SET {$col}=? WHERE company_id=?")->execute([$next,$companyId]);
        return $next;
    }
}

if (!function_exists('delivery_control_queue_fiscal')) {
    function delivery_control_queue_fiscal(PDO $pdo, int $companyId, int $saleId, ?int $deliveryOrderId, string $originType, string $documentType, array $items, ?int $userId): int {
        $documentType=strtoupper($documentType);
        if (!in_array($documentType,['NFE','NFCE'],true)) return 0;
        $payload=['items'=>array_values($items)];
        $pdo->prepare("INSERT INTO delivery_control_fiscal_queue(company_id,delivery_sale_id,delivery_order_id,origin_type,document_type,status,payload_json,requested_by) VALUES(?,?,?,?,?,'PENDENTE',?,?)")
            ->execute([$companyId,$saleId,$deliveryOrderId ?: null,$originType,$documentType,json_encode($payload,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$userId ?: null]);
        return (int)$pdo->lastInsertId();
    }
}

if (!function_exists('delivery_control_finalize_quote_order')) {
    function delivery_control_finalize_quote_order(PDO $pdo, int $companyId, int $quoteId, int $orderId, ?int $userId, string $requestedDoc='none'): ?int {
        if ($quoteId<=0 || $orderId<=0 || !delivery_control_enabled($pdo,$companyId) || !delivery_control_schema_ready($pdo)) return null;
        $futureRows=delivery_control_quote_future_rows($pdo,$companyId,$quoteId);
        if (!$futureRows) return null;
        $ownTx=!$pdo->inTransaction();if($ownTx)$pdo->beginTransaction();
        try {
            $st=$pdo->prepare('SELECT * FROM delivery_control_sales WHERE company_id=? AND pedido_id=? LIMIT 1 FOR UPDATE');$st->execute([$companyId,$orderId]);
            $existing=$st->fetch(PDO::FETCH_ASSOC);if($existing){if($ownTx)$pdo->commit();return (int)$existing['id'];}
            $q=orc_fetch($pdo,$companyId,$quoteId);if(!$q)throw new RuntimeException('Orçamento de origem não encontrado.');
            $st=$pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$orderId]);$p=$st->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Pedido comercial não encontrado.');
            $pdo->prepare("INSERT INTO delivery_control_sales(company_id,quote_id,pedido_id,person_id,customer_name,customer_document,customer_phone,delivery_zip_code,delivery_address,delivery_number,delivery_complement,delivery_district,delivery_city,delivery_state,delivery_reference,expected_date,expected_time,status,notes,created_by)
                VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'PENDENTE',?,?)")
                ->execute([$companyId,$quoteId,$orderId,$q['person_id'] ?: null,(string)($q['person_name_snapshot'] ?: ($p['nome_cliente'] ?? 'Cliente')),$q['person_document_snapshot'] ?: null,$q['person_phone_snapshot'] ?: null,$q['delivery_zip_code'] ?: null,$q['delivery_address'] ?: null,$q['delivery_number'] ?: null,$q['delivery_complement'] ?: null,$q['delivery_district'] ?: null,$q['delivery_city'] ?: null,$q['delivery_state'] ?: null,$q['delivery_reference'] ?: null,$q['delivery_date'] ?: null,$q['delivery_time'] ?: null,'Origem automática do Orçamento #'.(string)$q['quote_number'],$userId ?: null]);
            $saleId=(int)$pdo->lastInsertId();

            $st=$pdo->prepare('SELECT * FROM pedido_itens WHERE company_id=? AND pedido_id=? ORDER BY id');$st->execute([$companyId,$orderId]);$pedidoItems=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];$usedPedido=[];
            $immediateFiscal=[];
            $allQuoteItems=$pdo->prepare('SELECT * FROM orcamento_items WHERE company_id=? AND quote_id=? ORDER BY id');$allQuoteItems->execute([$companyId,$quoteId]);
            $futureByItem=[];foreach($futureRows as $fr)$futureByItem[(int)$fr['quote_item_id']]=$fr;
            foreach($allQuoteItems->fetchAll(PDO::FETCH_ASSOC) ?: [] as $qi){
                $qid=(int)$qi['id'];$fq=isset($futureByItem[$qid])?round((float)$futureByItem[$qid]['future_quantity'],4):0.0;$sold=round((float)$qi['quantity'],4);$immediate=round(max(0,$sold-$fq),4);$effectiveUnit=$sold>0?round((float)$qi['total']/$sold,4):(float)$qi['unit_price'];
                $pedidoItemId=null;
                foreach($pedidoItems as $pk=>$pi){if(isset($usedPedido[$pk]))continue;if((int)$pi['produto_id']===(int)$qi['produto_id'] && abs((float)$pi['qtd']-$sold)<0.00011){$usedPedido[$pk]=true;$pedidoItemId=(int)$pi['id'];break;}}
                if($fq>0){
                    $pdo->prepare('INSERT INTO delivery_control_sale_items(company_id,delivery_sale_id,quote_item_id,pedido_item_id,product_id,product_name,unit,unit_price,sold_quantity,immediate_quantity,future_quantity) VALUES(?,?,?,?,?,?,?,?,?,?,?)')
                        ->execute([$companyId,$saleId,$qid,$pedidoItemId ?: null,(int)$qi['produto_id'],(string)$qi['description_snapshot'],$qi['unidade_snapshot'] ?: null,$effectiveUnit,$sold,$immediate,$fq]);
                }
                if((string)$qi['reservation_status']==='RESERVADO' && (float)$qi['reserved_quantity']>0){
                    $reserved=(float)$qi['reserved_quantity'];$keep=min($fq,$reserved);$release=max(0,$reserved-$keep);
                    // A parcela imediata deixa de ser reservada. A parcela futura permanece em
                    // produtos.estoque_reservado, mas a responsabilidade passa do orçamento
                    // para delivery_control_sale_items; por isso zeramos a reserva no item do orçamento
                    // sem chamar orc_unreserve_product() para a quantidade futura.
                    if($release>0)orc_unreserve_product($pdo,$companyId,(int)$qi['produto_id'],$release);
                    $pdo->prepare("UPDATE orcamento_items SET reserved_quantity=0,reservation_status='LIBERADO',updated_at=NOW() WHERE company_id=? AND id=?")
                        ->execute([$companyId,$qid]);
                }
                if($immediate>0 && $requestedDoc!=='none')$immediateFiscal[]=['product_id'=>(int)$qi['produto_id'],'product_name'=>(string)$qi['description_snapshot'],'quantity'=>$immediate,'unit_price'=>$effectiveUnit];
            }
            $newStatus=(string)$q['status']==='ABERTO'?'APROVADO':(string)$q['status'];
            $pdo->prepare('UPDATE orcamentos SET status=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$newStatus,$userId ?: null,$companyId,$quoteId]);
            if($immediateFiscal && in_array(strtolower($requestedDoc),['nfe','nfce'],true)) delivery_control_queue_fiscal($pdo,$companyId,$saleId,null,'IMEDIATA',strtoupper($requestedDoc),$immediateFiscal,$userId);
            delivery_control_event($pdo,$companyId,'SALE',$saleId,'CRIADA_DO_PEDIDO',$userId,['quote_id'=>$quoteId,'pedido_id'=>$orderId]);
            if($ownTx)$pdo->commit();return $saleId;
        } catch(Throwable $e){if($ownTx&&$pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

if (!function_exists('delivery_control_sale_status_refresh')) {
    function delivery_control_sale_status_refresh(PDO $pdo, int $companyId, int $saleId): void {
        $stState=$pdo->prepare('SELECT status FROM delivery_control_sales WHERE company_id=? AND id=? LIMIT 1');$stState->execute([$companyId,$saleId]);if(strtoupper((string)$stState->fetchColumn())==='CANCELADA')return;
        $st=$pdo->prepare('SELECT COALESCE(SUM(future_quantity),0) future_qty,COALESCE(SUM(ordered_quantity),0) ordered_qty,COALESCE(SUM(delivered_quantity),0) delivered_qty FROM delivery_control_sale_items WHERE company_id=? AND delivery_sale_id=?');$st->execute([$companyId,$saleId]);$r=$st->fetch(PDO::FETCH_ASSOC) ?: [];
        $future=(float)($r['future_qty']??0);$ordered=(float)($r['ordered_qty']??0);$delivered=(float)($r['delivered_qty']??0);
        $status='PENDENTE';if($future>0 && $delivered>=$future-0.0001)$status='CONCLUIDA';elseif($delivered>0)$status='ENTREGA_PARCIAL';elseif($ordered>0)$status='PROGRAMADA';
        $pdo->prepare('UPDATE delivery_control_sales SET status=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$status,$companyId,$saleId]);
    }
}

if (!function_exists('delivery_control_stock_out')) {
    function delivery_control_stock_out(PDO $pdo, int $companyId, int $deliveryOrderId, int $productId, float $qty, string $label): void {
        if($qty<=0)return;
        $st=$pdo->prepare('SELECT estoque_atual,COALESCE(estoque_reservado,0) estoque_reservado,COALESCE(custo_medio,0) custo_medio,COALESCE(custo,0) custo FROM produtos WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$productId]);$p=$st->fetch(PDO::FETCH_ASSOC);if(!$p)throw new RuntimeException('Produto não encontrado ao gerar Ordem de Entrega.');
        $before=(float)$p['estoque_atual'];$after=round($before-$qty,4);$reserved=max(0,(float)$p['estoque_reservado']-$qty);
        $pdo->prepare('UPDATE produtos SET estoque_atual=?,estoque_reservado=? WHERE company_id=? AND id=?')->execute([$after,$reserved,$companyId,$productId]);
        try{$pdo->prepare("INSERT INTO estoque_movimentos(company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES(?,?,'saida','ORDEM_ENTREGA',?,?,?,?,?)")->execute([$companyId,$productId,$deliveryOrderId,$qty,$before,$after,$label]);}catch(Throwable $e){}
    }
}

if (!function_exists('delivery_control_stock_restore')) {
    function delivery_control_stock_restore(PDO $pdo, int $companyId, int $deliveryOrderId, int $productId, float $qty, string $label): void {
        if($qty<=0)return;$st=$pdo->prepare('SELECT estoque_atual,COALESCE(estoque_reservado,0) estoque_reservado FROM produtos WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$productId]);$p=$st->fetch(PDO::FETCH_ASSOC);if(!$p)return;$before=(float)$p['estoque_atual'];$after=round($before+$qty,4);$reserved=round((float)$p['estoque_reservado']+$qty,4);$pdo->prepare('UPDATE produtos SET estoque_atual=?,estoque_reservado=? WHERE company_id=? AND id=?')->execute([$after,$reserved,$companyId,$productId]);
        try{$pdo->prepare("INSERT INTO estoque_movimentos(company_id,produto_id,tipo,origem_tipo,origem_id,qtd,saldo_anterior,saldo_posterior,observacao) VALUES(?,?,'entrada','CANCELAMENTO_ORDEM_ENTREGA',?,?,?,?,?)")->execute([$companyId,$productId,$deliveryOrderId,$qty,$before,$after,$label]);}catch(Throwable $e){}
    }
}

if (!function_exists('delivery_control_create_order')) {
    function delivery_control_create_order(PDO $pdo, int $companyId, int $saleId, array $quantities, ?int $userId, ?string $scheduledDate=null, string $notes='', string $documentType='NFE'): int {
        delivery_control_require_enabled($pdo,$companyId);$pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT * FROM delivery_control_sales WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$saleId]);$sale=$st->fetch(PDO::FETCH_ASSOC);if(!$sale)throw new RuntimeException('Entrega não encontrada.');if(in_array((string)$sale['status'],['CONCLUIDA','CANCELADA'],true))throw new RuntimeException('Esta entrega já foi concluída ou cancelada.');
            $selected=[];$stItem=$pdo->prepare('SELECT * FROM delivery_control_sale_items WHERE company_id=? AND delivery_sale_id=? AND id=? FOR UPDATE');
            foreach($quantities as $itemId=>$qtyRaw){$qty=delivery_control_decimal($qtyRaw,4);if($qty<=0)continue;$stItem->execute([$companyId,$saleId,(int)$itemId]);$it=$stItem->fetch(PDO::FETCH_ASSOC);if(!$it)throw new RuntimeException('Item da entrega não encontrado.');$available=round((float)$it['future_quantity']-(float)$it['ordered_quantity']-(float)$it['canceled_quantity'],4);if($qty>$available+0.0001)throw new RuntimeException('Quantidade da O.E. maior que o saldo disponível de '.$it['product_name'].'. Saldo: '.number_format($available,4,',','.'));$selected[]=['row'=>$it,'qty'=>$qty];}
            if(!$selected)throw new RuntimeException('Informe ao menos uma quantidade para gerar a Ordem de Entrega.');
            $number=delivery_control_next_number($pdo,$companyId,'order');
            $pdo->prepare("INSERT INTO delivery_control_orders(company_id,delivery_sale_id,order_number,status,scheduled_date,delivery_zip_code,delivery_address,delivery_number,delivery_complement,delivery_district,delivery_city,delivery_state,delivery_reference,notes,stock_out_at,created_by) VALUES(?,?,?,'GERADA',?,?,?,?,?,?,?,?,?,?,NOW(),?)")
                ->execute([$companyId,$saleId,$number,$scheduledDate ?: ($sale['expected_date'] ?: null),$sale['delivery_zip_code'],$sale['delivery_address'],$sale['delivery_number'],$sale['delivery_complement'],$sale['delivery_district'],$sale['delivery_city'],$sale['delivery_state'],$sale['delivery_reference'],$notes!==''?$notes:null,$userId ?: null]);
            $orderId=(int)$pdo->lastInsertId();$fiscalItems=[];
            foreach($selected as $s){$it=$s['row'];$qty=$s['qty'];$pdo->prepare('INSERT INTO delivery_control_order_items(company_id,delivery_order_id,delivery_sale_item_id,product_id,product_name,unit,quantity) VALUES(?,?,?,?,?,?,?)')->execute([$companyId,$orderId,(int)$it['id'],(int)$it['product_id'],$it['product_name'],$it['unit'],$qty]);$pdo->prepare('UPDATE delivery_control_sale_items SET ordered_quantity=ordered_quantity+?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$qty,$companyId,(int)$it['id']]);delivery_control_stock_out($pdo,$companyId,$orderId,(int)$it['product_id'],$qty,'Baixa por Ordem de Entrega #'.str_pad((string)$number,6,'0',STR_PAD_LEFT));$fiscalItems[]=['product_id'=>(int)$it['product_id'],'product_name'=>(string)$it['product_name'],'quantity'=>$qty,'unit_price'=>(float)($it['unit_price']??0)];}
            if(in_array(strtoupper($documentType),['NFE','NFCE'],true))delivery_control_queue_fiscal($pdo,$companyId,$saleId,$orderId,'ORDEM_ENTREGA',strtoupper($documentType),$fiscalItems,$userId);
            delivery_control_sale_status_refresh($pdo,$companyId,$saleId);delivery_control_event($pdo,$companyId,'ORDER',$orderId,'ORDEM_GERADA',$userId,['order_number'=>$number]);$pdo->commit();return $orderId;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

if (!function_exists('delivery_control_cancel_order')) {
    function delivery_control_cancel_order(PDO $pdo,int $companyId,int $orderId,?int $userId):void{
        delivery_control_require_enabled($pdo,$companyId);$pdo->beginTransaction();try{$st=$pdo->prepare('SELECT * FROM delivery_control_orders WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$orderId]);$o=$st->fetch(PDO::FETCH_ASSOC);if(!$o)throw new RuntimeException('Ordem de Entrega não encontrada.');if((string)$o['status']==='CANCELADA'){ $pdo->commit();return;}if(in_array((string)$o['status'],['SAIU_PARA_ENTREGA','ENTREGUE'],true))throw new RuntimeException('Não é possível cancelar uma O.E. que já saiu para entrega ou foi entregue.');$st=$pdo->prepare("SELECT COUNT(*) FROM delivery_control_fiscal_queue WHERE company_id=? AND delivery_order_id=? AND status IN ('AUTORIZADA','PROCESSANDO')");$st->execute([$companyId,$orderId]);if((int)$st->fetchColumn()>0)throw new RuntimeException('A O.E. possui documento fiscal processado/em processamento. Regularize o fiscal antes de cancelar.');$st=$pdo->prepare('SELECT * FROM delivery_control_order_items WHERE company_id=? AND delivery_order_id=?');$st->execute([$companyId,$orderId]);foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $it){delivery_control_stock_restore($pdo,$companyId,$orderId,(int)$it['product_id'],(float)$it['quantity'],'Estorno da Ordem de Entrega #'.(string)$o['order_number']);$pdo->prepare('UPDATE delivery_control_sale_items SET ordered_quantity=GREATEST(0,ordered_quantity-?),updated_at=NOW() WHERE company_id=? AND id=?')->execute([(float)$it['quantity'],$companyId,(int)$it['delivery_sale_item_id']]);}$pdo->prepare("UPDATE delivery_control_orders SET status='CANCELADA',canceled_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$orderId]);$pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='CANCELADA',updated_at=NOW() WHERE company_id=? AND delivery_order_id=? AND status='PENDENTE'")->execute([$companyId,$orderId]);delivery_control_sale_status_refresh($pdo,$companyId,(int)$o['delivery_sale_id']);delivery_control_event($pdo,$companyId,'ORDER',$orderId,'ORDEM_CANCELADA',$userId);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}

if (!function_exists('delivery_control_mark_delivered')) {
    function delivery_control_mark_delivered(PDO $pdo,int $companyId,int $orderId,?int $userId):void{
        delivery_control_require_enabled($pdo,$companyId);$pdo->beginTransaction();try{$st=$pdo->prepare('SELECT * FROM delivery_control_orders WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$orderId]);$o=$st->fetch(PDO::FETCH_ASSOC);if(!$o)throw new RuntimeException('O.E. não encontrada.');if((string)$o['status']==='ENTREGUE'){ $pdo->commit();return;}if((string)$o['status']==='CANCELADA')throw new RuntimeException('O.E. cancelada não pode ser entregue.');$st=$pdo->prepare('SELECT * FROM delivery_control_order_items WHERE company_id=? AND delivery_order_id=?');$st->execute([$companyId,$orderId]);foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $it)$pdo->prepare('UPDATE delivery_control_sale_items SET delivered_quantity=LEAST(future_quantity,delivered_quantity+?),updated_at=NOW() WHERE company_id=? AND id=?')->execute([(float)$it['quantity'],$companyId,(int)$it['delivery_sale_item_id']]);$pdo->prepare("UPDATE delivery_control_orders SET status='ENTREGUE',delivered_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$orderId]);delivery_control_sale_status_refresh($pdo,$companyId,(int)$o['delivery_sale_id']);delivery_control_event($pdo,$companyId,'ORDER',$orderId,'ENTREGA_CONFIRMADA',$userId);$pdo->commit();}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
    }
}


if (!function_exists('delivery_control_fiscal_prepare_shadow')) {
    function delivery_control_fiscal_prepare_shadow(PDO $pdo,int $companyId,int $queueId,?int $userId): int {
        delivery_control_require_enabled($pdo,$companyId);
        require_once __DIR__ . '/gestao_module.php';
        $ownTx=!$pdo->inTransaction();if($ownTx)$pdo->beginTransaction();
        try{
            $st=$pdo->prepare('SELECT q.*,s.pedido_id original_pedido_id,s.customer_name,s.person_id FROM delivery_control_fiscal_queue q JOIN delivery_control_sales s ON s.company_id=q.company_id AND s.id=q.delivery_sale_id WHERE q.company_id=? AND q.id=? FOR UPDATE');$st->execute([$companyId,$queueId]);$q=$st->fetch(PDO::FETCH_ASSOC);if(!$q)throw new RuntimeException('Item da fila fiscal não encontrado.');
            $st=$pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,(int)$q['original_pedido_id']]);$orig=$st->fetch(PDO::FETCH_ASSOC);if(!$orig)throw new RuntimeException('Pedido comercial de origem não encontrado.');

            // A NFC-e modelo 65 não aceita tPag=90 (Sem pagamento). O pedido fiscal
            // isolado não cria novo financeiro, mas precisa preservar o meio de
            // pagamento da venda comercial original para compor corretamente o XML.
            $origPaymentLabel=trim((string)($orig['forma_pagamento']??''));
            $origPaymentMethodId=(int)($orig['payment_method_id']??0);
            if(strtoupper((string)($q['document_type']??''))==='NFCE' && $origPaymentLabel==='' && $origPaymentMethodId<=0){
                throw new RuntimeException('Entrega futura com NFC-e exige forma de pagamento no pedido comercial. Informe a forma de pagamento e tente novamente.');
            }

            if((int)($q['shadow_pedido_id']??0)>0){
                $shadowId=(int)$q['shadow_pedido_id'];
                // Recupera também filas já preparadas antes deste hotfix: sincroniza
                // a forma de pagamento do pedido comercial no pedido fiscal isolado.
                $sets=['forma_pagamento=?'];$params=[$origPaymentLabel!==''?$origPaymentLabel:'Sem pagamento'];
                $cols=function_exists('gestao_table_columns')?gestao_table_columns($pdo,'pedidos'):[];
                if(isset($cols['payment_method_id'])){$sets[]='payment_method_id=?';$params[]=$origPaymentMethodId>0?$origPaymentMethodId:null;}
                $params[]=$companyId;$params[]=$shadowId;
                $pdo->prepare('UPDATE pedidos SET '.implode(',',$sets).' WHERE company_id=? AND id=?')->execute($params);
                if($ownTx)$pdo->commit();return $shadowId;
            }
            if(!in_array((string)$q['status'],['PENDENTE','ERRO'],true))throw new RuntimeException('Esta fila fiscal não está disponível para preparação.');
            $payload=json_decode((string)($q['payload_json']??''),true);$items=is_array($payload['items']??null)?$payload['items']:[];if(!$items)throw new RuntimeException('A fila fiscal não possui itens.');
            $total=0.0;foreach($items as $it)$total+=round((float)($it['quantity']??0)*(float)($it['unit_price']??0),2);if($total<=0)throw new RuntimeException('Não foi possível calcular o total fiscal da entrega.');
            $ref='Fiscal parcial da entrega';if((int)($q['delivery_order_id']??0)>0)$ref.=' • O.E. #'.(int)$q['delivery_order_id'];$ref.=' • Pedido comercial #'.(int)$q['original_pedido_id'];
            $shadowId=gestao_insert_pedido_dynamic($pdo,[
                'company_id'=>$companyId,'nome_cliente'=>(string)($orig['nome_cliente']??$q['customer_name']??'Cliente'),'whatsapp'=>(string)($orig['whatsapp']??''),'endereco'=>(string)($orig['endereco']??''),'bairro'=>$orig['bairro']??null,'zip_code'=>$orig['zip_code']??null,'numero'=>$orig['numero']??null,'complemento'=>$orig['complemento']??null,'referencia'=>$orig['referencia']??null,'cidade'=>$orig['cidade']??($orig['city']??null),'uf'=>$orig['uf']??($orig['state']??null),'city'=>$orig['city']??($orig['cidade']??null),'state'=>$orig['state']??($orig['uf']??null),'tipo_entrega'=>'entrega','taxa_entrega'=>0,'forma_pagamento'=>$origPaymentLabel!==''?$origPaymentLabel:'Sem pagamento','observacoes'=>$ref,'total_produtos'=>$total,'total'=>$total,'status'=>'finalizado','finance_person_id'=>$orig['finance_person_id']??$q['person_id']??null,'seller_id'=>$orig['seller_id']??null,'payment_method_id'=>$origPaymentMethodId>0?$origPaymentMethodId:null,'source'=>'delivery_control'
            ]);
            $ins=$pdo->prepare('INSERT INTO pedido_itens(company_id,pedido_id,produto_id,qtd,preco_unit,total) VALUES(?,?,?,?,?,?)');
            foreach($items as $it){$pid=(int)($it['product_id']??0);$qty=(float)($it['quantity']??0);$price=(float)($it['unit_price']??0);if($pid<=0||$qty<=0)continue;$ins->execute([$companyId,$shadowId,$pid,$qty,$price,round($qty*$price,2)]);}
            $pdo->prepare("UPDATE delivery_control_fiscal_queue SET shadow_pedido_id=?,status='PREPARADA',last_error=NULL,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$shadowId,$companyId,$queueId]);
            delivery_control_event($pdo,$companyId,'FISCAL_QUEUE',$queueId,'PEDIDO_FISCAL_PREPARADO',$userId,['shadow_pedido_id'=>$shadowId]);
            if($ownTx)$pdo->commit();return $shadowId;
        }catch(Throwable $e){if($ownTx&&$pdo->inTransaction())$pdo->rollBack();try{$pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='ERRO',last_error=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$e->getMessage(),$companyId,$queueId]);}catch(Throwable $ignore){}throw $e;}
    }
}

if (!function_exists('delivery_control_fiscal_authorize')) {
    function delivery_control_fiscal_authorize(PDO $pdo,int $companyId,int $queueId,?int $userId): array {
        delivery_control_require_enabled($pdo,$companyId);
        $shadowId=delivery_control_fiscal_prepare_shadow($pdo,$companyId,$queueId,$userId);
        require_once __DIR__ . '/fiscal_operational.php';
        $st=$pdo->prepare('SELECT document_type FROM delivery_control_fiscal_queue WHERE company_id=? AND id=? LIMIT 1');$st->execute([$companyId,$queueId]);$type=strtoupper((string)$st->fetchColumn());
        try{
            $pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='PROCESSANDO',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,$queueId]);
            $result=$type==='NFCE'?fiscal_nfce_authorize_order($pdo,$companyId,$shadowId):fiscal_nfe_authorize_order($pdo,$companyId,$shadowId);
            $docId=(int)($result['doc_id']??0);
            if(empty($result['ok']) || strtoupper((string)($result['status']??''))!=='AUTORIZADA'){
                throw new RuntimeException((string)($result['message']??'O emissor fiscal não retornou autorização. Consulte a situação do documento antes de tentar novamente.'));
            }
            $pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='AUTORIZADA',fiscal_document_id=?,last_error=NULL,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$docId?:null,$companyId,$queueId]);
            delivery_control_event($pdo,$companyId,'FISCAL_QUEUE',$queueId,'DOCUMENTO_AUTORIZADO',$userId,['document_type'=>$type,'fiscal_document_id'=>$docId,'shadow_pedido_id'=>$shadowId]);
            return $result+['shadow_pedido_id'=>$shadowId];
        }catch(Throwable $e){$pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='ERRO',last_error=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$e->getMessage(),$companyId,$queueId]);throw $e;}
    }
}

if (!function_exists('delivery_control_prepare_commercial_order_cancellation')) {
    /**
     * Protege o cancelamento do pedido comercial. Deve ser chamado dentro da mesma
     * transação de nexa_cancel_order(): O.E. ativa precisa ser regularizada antes e
     * o saldo futuro ainda reservado é liberado somente quando o cancelamento pode prosseguir.
     */
    function delivery_control_prepare_commercial_order_cancellation(PDO $pdo,int $companyId,int $pedidoId,?int $userId=null): void {
        if($companyId<=0||$pedidoId<=0||!delivery_control_schema_ready($pdo))return;
        $st=$pdo->prepare('SELECT * FROM delivery_control_sales WHERE company_id=? AND pedido_id=? LIMIT 1 FOR UPDATE');$st->execute([$companyId,$pedidoId]);$sale=$st->fetch(PDO::FETCH_ASSOC);if(!$sale)return;
        if((string)$sale['status']==='CANCELADA')return;

        $st=$pdo->prepare("SELECT COUNT(*) FROM delivery_control_orders WHERE company_id=? AND delivery_sale_id=? AND status<>'CANCELADA'");$st->execute([$companyId,(int)$sale['id']]);
        if((int)$st->fetchColumn()>0)throw new RuntimeException('Este pedido possui Ordem(ns) de Entrega ativa(s). Cancele/regularize todas as O.E. antes de cancelar a venda comercial.');

        try{
            $st=$pdo->prepare("SELECT q.id,q.status,q.fiscal_document_id,fd.status fiscal_status FROM delivery_control_fiscal_queue q LEFT JOIN fiscal_documents fd ON fd.company_id=q.company_id AND fd.id=q.fiscal_document_id WHERE q.company_id=? AND q.delivery_sale_id=? FOR UPDATE");
            $st->execute([$companyId,(int)$sale['id']]);$queues=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        }catch(Throwable $e){
            $st=$pdo->prepare('SELECT id,status,fiscal_document_id,NULL fiscal_status FROM delivery_control_fiscal_queue WHERE company_id=? AND delivery_sale_id=? FOR UPDATE');$st->execute([$companyId,(int)$sale['id']]);$queues=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
        }
        foreach($queues as $q){
            $qs=strtoupper((string)$q['status']);$fs=strtoupper((string)($q['fiscal_status']??''));
            if($qs==='PROCESSANDO')throw new RuntimeException('Existe documento fiscal da entrega em processamento. Aguarde/regularize o fiscal antes de cancelar o pedido.');
            if($qs==='AUTORIZADA' && !in_array($fs,['CANCELADA','CANCELADO'],true))throw new RuntimeException('Existe documento fiscal autorizado referente a uma saída parcial. Cancele primeiro o documento fiscal antes de cancelar o pedido comercial.');
        }
        $pdo->prepare("UPDATE delivery_control_fiscal_queue SET status='CANCELADA',updated_at=NOW() WHERE company_id=? AND delivery_sale_id=? AND status IN ('PENDENTE','PREPARADA','ERRO','AUTORIZADA')")
            ->execute([$companyId,(int)$sale['id']]);

        $st=$pdo->prepare('SELECT product_id,future_quantity,ordered_quantity FROM delivery_control_sale_items WHERE company_id=? AND delivery_sale_id=? FOR UPDATE');$st->execute([$companyId,(int)$sale['id']]);
        foreach($st->fetchAll(PDO::FETCH_ASSOC)?:[] as $it){
            $remaining=round(max(0,(float)$it['future_quantity']-(float)$it['ordered_quantity']),4);if($remaining<=0)continue;
            $pdo->prepare('UPDATE produtos SET estoque_reservado=GREATEST(0,COALESCE(estoque_reservado,0)-?) WHERE company_id=? AND id=?')->execute([$remaining,$companyId,(int)$it['product_id']]);
        }
        $pdo->prepare("UPDATE delivery_control_sales SET status='CANCELADA',updated_at=NOW() WHERE company_id=? AND id=?")->execute([$companyId,(int)$sale['id']]);
        delivery_control_event($pdo,$companyId,'SALE',(int)$sale['id'],'VENDA_COMERCIAL_CANCELADA',$userId,['pedido_id'=>$pedidoId]);
    }
}
