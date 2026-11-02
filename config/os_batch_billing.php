<?php
/**
 * Nexa Próspero - Faturamento em Lote de O.S. v1.3
 *
 * Fluxo:
 * 1) seleciona O.S. pendentes de um único cliente;
 * 2) gera Fatura de Conferência (não altera financeiro/estoque/pedido);
 * 3) cliente confere e a fatura é aprovada;
 * 4) faturamento final gera UM pedido consolidado e UMA cobrança pelo total.
 *
 * O pedido consolidado usa source=ordem_servico_lote e, após o faturamento,
 * passa a utilizar o mesmo fluxo fiscal dos Pedidos/PDV. O fiscal individual da O.S. permanece isolado.
 */

require_once __DIR__ . '/os_module.php';

if (!function_exists('os_batch_table_exists')) {
    function os_batch_table_exists(PDO $pdo, string $table): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
        try {
            // MySQL 8 pode rejeitar placeholders em alguns comandos SHOW quando
            // o PDO usa prepares nativos. information_schema funciona de forma
            // consistente e evita falso negativo após o SQL já ter sido aplicado.
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.tables '
                . 'WHERE table_schema = DATABASE() AND table_name = ?'
            );
            $st->execute([$table]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('os_batch_column_exists')) {
    function os_batch_column_exists(PDO $pdo, string $table, string $column): bool
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/', $table) || !preg_match('/^[a-zA-Z0-9_]+$/', $column)) return false;
        try {
            $st = $pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.columns '
                . 'WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?'
            );
            $st->execute([$table, $column]);
            return (int)$st->fetchColumn() > 0;
        } catch (Throwable $e) { return false; }
    }
}

if (!function_exists('os_batch_schema_ok')) {
    function os_batch_schema_ok(PDO $pdo): bool
    {
        return os_batch_table_exists($pdo, 'os_batch_invoices')
            && os_batch_table_exists($pdo, 'os_batch_invoice_orders')
            && os_batch_table_exists($pdo, 'os_batch_invoice_sequences')
            && os_batch_column_exists($pdo, 'os_orders', 'batch_invoice_id')
            && os_batch_column_exists($pdo, 'os_statuses', 'allow_batch_billing')
            && os_batch_column_exists($pdo, 'os_statuses', 'is_billed_status');
    }
}

if (!function_exists('os_batch_require_schema')) {
    function os_batch_require_schema(PDO $pdo): void
    {
        if (!os_batch_schema_ok($pdo)) {
            throw new RuntimeException('Estrutura do Faturamento em Lote de O.S. ainda não instalada. Execute db/20260825_os_fiscal_status_faturado_v1.sql.');
        }
    }
}

if (!function_exists('os_batch_csrf_token')) {
    function os_batch_csrf_token(): string
    {
        if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
        if (empty($_SESSION['os_batch_billing_csrf'])) $_SESSION['os_batch_billing_csrf'] = bin2hex(random_bytes(24));
        return (string)$_SESSION['os_batch_billing_csrf'];
    }
}

if (!function_exists('os_batch_csrf_check')) {
    function os_batch_csrf_check(string $token): void
    {
        $expected = os_batch_csrf_token();
        if ($token === '' || !hash_equals($expected, $token)) throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
}

if (!function_exists('os_batch_date')) {
    function os_batch_date($value): ?string
    {
        $value = trim((string)$value);
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return null;
        $dt = DateTime::createFromFormat('Y-m-d', $value);
        return ($dt && $dt->format('Y-m-d') === $value) ? $value : null;
    }
}

if (!function_exists('os_batch_status_label')) {
    function os_batch_status_label(string $status): string
    {
        return match (strtoupper(trim($status))) {
            'APROVADA' => 'Aprovada',
            'FATURADA' => 'Faturada',
            'CANCELADA' => 'Cancelada',
            default => 'Em conferência',
        };
    }
}

if (!function_exists('os_batch_billed_status')) {
    function os_batch_billed_status(PDO $pdo, int $companyId): ?array
    {
        $st = $pdo->prepare('SELECT id,name,code,color,is_final FROM os_statuses WHERE company_id=? AND active=1 AND is_billed_status=1 ORDER BY id LIMIT 2');
        $st->execute([$companyId]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        if (count($rows) > 1) throw new RuntimeException('Existe mais de um status marcado como Faturado. Deixe somente um em Configurações da O.S.');
        return $rows[0] ?? null;
    }
}

if (!function_exists('os_batch_invoice')) {
    function os_batch_invoice(PDO $pdo, int $companyId, int $invoiceId): ?array
    {
        $st = $pdo->prepare('SELECT i.*,s.name AS status_filter_name,p.name AS person_current_name FROM os_batch_invoices i LEFT JOIN os_statuses s ON s.company_id=i.company_id AND s.id=i.status_id_filter LEFT JOIN finance_people p ON p.company_id=i.company_id AND p.id=i.person_id WHERE i.company_id=? AND i.id=? LIMIT 1');
        $st->execute([$companyId, $invoiceId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('os_batch_invoice_orders')) {
    function os_batch_invoice_orders(PDO $pdo, int $companyId, int $invoiceId): array
    {
        $st = $pdo->prepare('SELECT io.*,o.billing_status,o.batch_invoice_id,o.person_id AS current_person_id,o.total_general AS current_total,o.status_id AS current_status_id FROM os_batch_invoice_orders io LEFT JOIN os_orders o ON o.company_id=io.company_id AND o.id=io.os_order_id WHERE io.company_id=? AND io.invoice_id=? ORDER BY io.service_date_snapshot,io.os_number_snapshot,io.id');
        $st->execute([$companyId,$invoiceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('os_batch_invoice_lines')) {
    function os_batch_invoice_lines(PDO $pdo, int $companyId, int $invoiceId): array
    {
        $sql = "SELECT io.os_number_snapshot,io.service_date_snapshot,oi.id,oi.item_type,oi.description_snapshot,oi.quantity,oi.unit_price,oi.total,oi.notes,
                       COALESCE(NULLIF(oi.seller_name_snapshot,''),NULLIF(oi.technician_name_snapshot,''),'') AS responsible_name
                FROM os_batch_invoice_orders io
                INNER JOIN os_order_items oi ON oi.company_id=io.company_id AND oi.order_id=io.os_order_id
                WHERE io.company_id=? AND io.invoice_id=?
                ORDER BY io.service_date_snapshot,io.os_number_snapshot,oi.item_type,oi.id";
        $st=$pdo->prepare($sql);$st->execute([$companyId,$invoiceId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('os_batch_list_invoices')) {
    function os_batch_list_invoices(PDO $pdo, int $companyId, string $start, string $end, int $personId=0, string $status=''): array
    {
        $where=['i.company_id=?','i.issue_date BETWEEN ? AND ?'];$params=[$companyId,$start,$end];
        if($personId>0){$where[]='i.person_id=?';$params[]=$personId;}
        $status=strtoupper(trim($status));if(in_array($status,['CONFERENCIA','APROVADA','FATURADA','CANCELADA'],true)){$where[]='i.status=?';$params[]=$status;}
        $sql='SELECT i.*,(SELECT COUNT(*) FROM os_batch_invoice_orders io WHERE io.company_id=i.company_id AND io.invoice_id=i.id) os_count FROM os_batch_invoices i WHERE '.implode(' AND ',$where).' ORDER BY i.issue_date DESC,i.invoice_number DESC LIMIT 300';
        $st=$pdo->prepare($sql);$st->execute($params);return $st->fetchAll(PDO::FETCH_ASSOC)?:[];
    }
}

if (!function_exists('os_batch_candidate_orders')) {
    function os_batch_candidate_orders(PDO $pdo, int $companyId, int $personId, int $statusId, string $start, string $end): array
    {
        if($personId<=0)return[];
        $where=["o.company_id=?","o.person_id=?","o.billing_status='PENDENTE'","s.active=1","s.allow_batch_billing=1","DATE(COALESCE(o.closed_at,o.opened_at)) BETWEEN ? AND ?"];
        $params=[$companyId,$personId,$start,$end];
        if($statusId>0){$where[]='o.status_id=?';$params[]=$statusId;}
        $sql="SELECT o.id,o.os_number,o.opened_at,o.closed_at,DATE(COALESCE(o.closed_at,o.opened_at)) AS service_date,o.title,o.reported_problem,o.total_products,o.total_services,o.discount_amount,o.addition_amount,o.total_general,o.status_id,
                     s.name AS status_name,s.color AS status_color,s.allow_batch_billing,
                     EXISTS(SELECT 1 FROM os_batch_invoice_orders io INNER JOIN os_batch_invoices bi ON bi.company_id=io.company_id AND bi.id=io.invoice_id WHERE io.company_id=o.company_id AND io.os_order_id=o.id AND bi.status IN ('CONFERENCIA','APROVADA','FATURADA')) AS linked_invoice
              FROM os_orders o
              INNER JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id
              WHERE ".implode(' AND ',$where)."
              ORDER BY service_date,o.os_number,o.id";
        $st=$pdo->prepare($sql);$st->execute($params);
        return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC)?:[],static fn($r)=>(int)($r['linked_invoice']??0)===0));
    }
}

if (!function_exists('os_batch_next_number_locked')) {
    function os_batch_next_number_locked(PDO $pdo, int $companyId): int
    {
        $pdo->prepare('INSERT IGNORE INTO os_batch_invoice_sequences(company_id,last_number) VALUES(?,0)')->execute([$companyId]);
        $st=$pdo->prepare('SELECT last_number FROM os_batch_invoice_sequences WHERE company_id=? FOR UPDATE');$st->execute([$companyId]);
        $next=((int)$st->fetchColumn())+1;
        $pdo->prepare('UPDATE os_batch_invoice_sequences SET last_number=? WHERE company_id=?')->execute([$next,$companyId]);
        return $next;
    }
}

if (!function_exists('os_batch_create_invoice')) {
    function os_batch_create_invoice(PDO $pdo, int $companyId, int $userId, int $personId, int $statusId, string $start, string $end, array $orderIds, string $notes=''): int
    {
        os_batch_require_schema($pdo);
        $start=os_batch_date($start)?:'';$end=os_batch_date($end)?:'';
        if($start===''||$end==='')throw new RuntimeException('Período inválido.');if($end<$start)[$start,$end]=[$end,$start];
        $orderIds=array_values(array_unique(array_filter(array_map('intval',$orderIds),static fn($v)=>$v>0)));
        if(!$orderIds)throw new RuntimeException('Selecione pelo menos uma O.S.');if(count($orderIds)>200)throw new RuntimeException('Por segurança, gere no máximo 200 O.S. por fatura.');
        if($personId<=0)throw new RuntimeException('Selecione um cliente para gerar a fatura.');
        $ps=$pdo->prepare('SELECT id,name,document FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$ps->execute([$companyId,$personId]);$person=$ps->fetch(PDO::FETCH_ASSOC);if(!$person)throw new RuntimeException('Cliente não encontrado.');

        $pdo->beginTransaction();
        try{
            $ph=implode(',',array_fill(0,count($orderIds),'?'));
            $sql="SELECT o.*,s.name AS status_name,s.active AS status_active,s.allow_batch_billing FROM os_orders o LEFT JOIN os_statuses s ON s.company_id=o.company_id AND s.id=o.status_id WHERE o.company_id=? AND o.id IN ($ph) FOR UPDATE";
            $st=$pdo->prepare($sql);$st->execute(array_merge([$companyId],$orderIds));$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
            if(count($rows)!==count($orderIds))throw new RuntimeException('Uma ou mais O.S. selecionadas não foram encontradas.');
            $byId=[];foreach($rows as $r)$byId[(int)$r['id']]=$r;
            $totP=$totS=$totD=$totA=$totG=0.0;
            foreach($orderIds as $oid){
                $r=$byId[$oid];$serviceDate=substr((string)(!empty($r['closed_at'])?$r['closed_at']:$r['opened_at']),0,10);
                if((int)$r['person_id']!==$personId)throw new RuntimeException('Todas as O.S. da fatura precisam pertencer ao mesmo cliente.');
                if(strtoupper((string)$r['billing_status'])!=='PENDENTE')throw new RuntimeException('A O.S. #'.str_pad((string)$r['os_number'],6,'0',STR_PAD_LEFT).' não está pendente de faturamento.');
                if(empty($r['status_active']) || empty($r['allow_batch_billing']))throw new RuntimeException('A O.S. #'.str_pad((string)$r['os_number'],6,'0',STR_PAD_LEFT).' não está em um status habilitado para Faturamento em Lote.');
                if($statusId>0 && (int)$r['status_id']!==$statusId)throw new RuntimeException('A situação de uma O.S. mudou. Atualize a pesquisa antes de gerar.');
                if($serviceDate<$start||$serviceDate>$end)throw new RuntimeException('A data de uma O.S. selecionada ficou fora do período informado.');
                $chk=$pdo->prepare("SELECT bi.invoice_number FROM os_batch_invoice_orders io INNER JOIN os_batch_invoices bi ON bi.company_id=io.company_id AND bi.id=io.invoice_id WHERE io.company_id=? AND io.os_order_id=? AND bi.status IN ('CONFERENCIA','APROVADA','FATURADA') LIMIT 1 FOR UPDATE");
                $chk->execute([$companyId,$oid]);$already=$chk->fetchColumn();if($already)throw new RuntimeException('A O.S. #'.str_pad((string)$r['os_number'],6,'0',STR_PAD_LEFT).' já pertence à Fatura #'.str_pad((string)$already,6,'0',STR_PAD_LEFT).'.');
                $totP+=(float)$r['total_products'];$totS+=(float)$r['total_services'];$totD+=(float)$r['discount_amount'];$totA+=(float)$r['addition_amount'];$totG+=(float)$r['total_general'];
            }
            $number=os_batch_next_number_locked($pdo,$companyId);
            $ins=$pdo->prepare("INSERT INTO os_batch_invoices(company_id,invoice_number,person_id,person_name_snapshot,person_document_snapshot,period_start,period_end,status_id_filter,issue_date,status,total_products,total_services,discount_amount,addition_amount,total_general,notes,created_by,updated_by,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,CURDATE(),'CONFERENCIA',?,?,?,?,?,?,?,?,NOW(),NOW())");
            $ins->execute([$companyId,$number,$personId,(string)$person['name'],(string)($person['document']??''),$start,$end,$statusId?:null,round($totP,2),round($totS,2),round($totD,2),round($totA,2),round($totG,2),trim($notes)?:null,$userId?:null,$userId?:null]);
            $invoiceId=(int)$pdo->lastInsertId();
            $io=$pdo->prepare('INSERT INTO os_batch_invoice_orders(company_id,invoice_id,os_order_id,os_number_snapshot,service_date_snapshot,status_name_snapshot,title_snapshot,total_products,total_services,discount_amount,addition_amount,total_general) VALUES(?,?,?,?,?,?,?,?,?,?,?,?)');
            foreach($orderIds as $oid){$r=$byId[$oid];$serviceDate=substr((string)(!empty($r['closed_at'])?$r['closed_at']:$r['opened_at']),0,10);$io->execute([$companyId,$invoiceId,$oid,(int)$r['os_number'],$serviceDate,(string)($r['status_name']??''),(string)($r['title']??''),(float)$r['total_products'],(float)$r['total_services'],(float)$r['discount_amount'],(float)$r['addition_amount'],(float)$r['total_general']]);}
            $pdo->commit();return$invoiceId;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}

if (!function_exists('os_batch_set_status')) {
    function os_batch_set_status(PDO $pdo, int $companyId, int $invoiceId, int $userId, string $action): void
    {
        os_batch_require_schema($pdo);$action=strtolower(trim($action));
        $pdo->beginTransaction();try{
            $st=$pdo->prepare('SELECT * FROM os_batch_invoices WHERE company_id=? AND id=? FOR UPDATE');$st->execute([$companyId,$invoiceId]);$inv=$st->fetch(PDO::FETCH_ASSOC);if(!$inv)throw new RuntimeException('Fatura não encontrada.');
            $status=strtoupper((string)$inv['status']);
            if($action==='approve'){
                if($status!=='CONFERENCIA')throw new RuntimeException('Somente faturas em conferência podem ser aprovadas.');
                $pdo->prepare("UPDATE os_batch_invoices SET status='APROVADA',approved_at=NOW(),approved_by=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$userId?:null,$userId?:null,$companyId,$invoiceId]);
            }elseif($action==='reopen'){
                if($status!=='APROVADA')throw new RuntimeException('Somente faturas aprovadas e ainda não faturadas podem voltar à conferência.');
                $pdo->prepare("UPDATE os_batch_invoices SET status='CONFERENCIA',approved_at=NULL,approved_by=NULL,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$userId?:null,$companyId,$invoiceId]);
            }elseif($action==='cancel'){
                if($status==='FATURADA')throw new RuntimeException('Fatura já faturada não pode ser cancelada por esta tela.');
                if($status==='CANCELADA'){$pdo->commit();return;}
                $pdo->prepare("UPDATE os_batch_invoices SET status='CANCELADA',cancelled_at=NOW(),cancelled_by=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$userId?:null,$userId?:null,$companyId,$invoiceId]);
            }else throw new RuntimeException('Ação inválida.');
            $pdo->commit();
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}

if (!function_exists('os_batch_finalize_invoice')) {
    function os_batch_finalize_invoice(PDO $pdo, int $companyId, int $invoiceId, int $userId, int $paymentMethodId, ?string $dueDate): int
    {
        os_batch_require_schema($pdo);
        require_once __DIR__ . '/gestao_module.php';
        if(function_exists('gestao_ensure_schema')){$schema=gestao_ensure_schema($pdo);if(is_array($schema)&&!empty($schema['errors']))throw new RuntimeException('Não foi possível preparar a estrutura de gestão: '.implode(' ',(array)$schema['errors']));}
        $invoice=os_batch_invoice($pdo,$companyId,$invoiceId);if(!$invoice)throw new RuntimeException('Fatura não encontrada.');
        if(strtoupper((string)$invoice['status'])==='FATURADA' && (int)$invoice['pedido_id']>0)return(int)$invoice['pedido_id'];
        if(strtoupper((string)$invoice['status'])!=='APROVADA')throw new RuntimeException('A fatura precisa estar aprovada pelo cliente antes do faturamento final.');
        $total=round((float)$invoice['total_general'],2);if($total<=0)throw new RuntimeException('A fatura não possui valor para faturamento.');
        $payment=gestao_payment_method($pdo,$companyId,$paymentMethodId);if(!$payment||empty($payment['is_active']))throw new RuntimeException('Forma de pagamento inválida.');if(strtoupper(trim((string)($payment['type']??'')))==='VALE'||strtoupper(trim((string)($payment['code']??'')))==='VALE')throw new RuntimeException('O pagamento com Vale está disponível nas vendas/PDV. O faturamento em lote da O.S. ainda não utiliza vales.');
        $dueDate=os_batch_date($dueDate);if(!empty($payment['generate_receivable'])&&!$dueDate)throw new RuntimeException('Informe o vencimento da cobrança.');
        $post=['payment_method_id'=>[$paymentMethodId],'payment_amount'=>[$total],'payment_received'=>[$total],'payment_line_key'=>['batch']];
        if($dueDate){$post['installment_due']=['batch'=>[$dueDate]];$post['installment_amount']=['batch'=>[$total]];}
        $payments=gestao_payment_lines_from_post($pdo,$companyId,$post,$total);
        $customerId=(int)$invoice['person_id'];$c=$pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1');$c->execute([$companyId,$customerId]);$customer=$c->fetch(PDO::FETCH_ASSOC);if(!$customer)throw new RuntimeException('Cliente da fatura não encontrado.');
        $session=gestao_open_cash_session($pdo,$companyId,$userId);
        foreach($payments as $line){$pm=$line['payment'];if(!empty($pm['require_open_cash'])&&!$session)throw new RuntimeException('Abra o caixa antes de faturar em '.(string)$pm['name'].'.');$chart=(int)($pm['default_chart_account_id']??0)?:gestao_default_chart_id($pdo,$companyId,'CREDITO');if($chart<=0)throw new RuntimeException('Configure o plano de contas da forma de pagamento: '.(string)$pm['name']);}

        $orderLinks=os_batch_invoice_orders($pdo,$companyId,$invoiceId);if(!$orderLinks)throw new RuntimeException('A fatura não possui O.S. vinculadas.');
        $osIds=array_map(static fn($r)=>(int)$r['os_order_id'],$orderLinks);$ph=implode(',',array_fill(0,count($osIds),'?'));
        $itemSql="SELECT oi.*,o.os_number,o.total_general AS os_total,p.nome AS product_name,p.categoria AS product_category,COALESCE(p.custo,0) AS product_cost,COALESCE(p.custo_medio,0) AS product_avg_cost
                  FROM os_order_items oi INNER JOIN os_orders o ON o.company_id=oi.company_id AND o.id=oi.order_id
                  LEFT JOIN produtos p ON p.company_id=oi.company_id AND p.id=oi.produto_id
                  WHERE oi.company_id=? AND oi.order_id IN ($ph) ORDER BY o.os_number,oi.id";
        $it=$pdo->prepare($itemSql);$it->execute(array_merge([$companyId],$osIds));$osItems=$it->fetchAll(PDO::FETCH_ASSOC)?:[];if(!$osItems)throw new RuntimeException('As O.S. da fatura não possuem itens válidos.');
        $items=[];$stockItems=[];$gross=0.0;$responsibles=[];
        foreach($osItems as $row){$qty=round((float)$row['quantity'],4);$price=round((float)$row['unit_price'],6);if((int)$row['produto_id']<=0||$qty<=0||$price<0)continue;$lineTotal=round($qty*$price,2);$gross+=$lineTotal;$osNumber=str_pad((string)$row['os_number'],6,'0',STR_PAD_LEFT);$original=trim((string)($row['notes']??''));$obs='O.S. #'.$osNumber.($original!==''?' - '.$original:'');$item=['produto_id'=>(int)$row['produto_id'],'nome'=>(string)($row['product_name']?:$row['description_snapshot']?:'Item'),'categoria'=>(string)($row['product_category']?:'SEM CATEGORIA'),'custo'=>(float)$row['product_cost'],'custo_medio'=>(float)$row['product_avg_cost'],'qtd'=>$qty,'price'=>$price,'total'=>$lineTotal,'obs'=>$obs,'item_type'=>(string)$row['item_type']];$items[]=$item;if(strtoupper((string)$row['item_type'])==='PRODUTO')$stockItems[]=$item;$resp=os_item_responsible_id($row);if($resp>0)$responsibles[$resp]=true;}
        if(!$items)throw new RuntimeException('Nenhum item válido foi encontrado nas O.S. selecionadas.');
        $gross=round($gross,2);$calc=round($gross-(float)$invoice['discount_amount']+(float)$invoice['addition_amount'],2);if(abs($calc-$total)>0.02)throw new RuntimeException('Os valores das O.S. foram alterados após a geração da fatura. Cancele esta fatura, gere uma nova relação e peça nova conferência do cliente.');

        $paymentLabel=implode(' + ',array_map(static fn($l)=>(string)$l['payment']['name'],$payments));$paymentLabelDb=gestao_payment_label_for_pedidos($pdo,$payments,$paymentLabel);$first=$payments[0]['payment'];$firstPaymentId=count($payments)===1?(int)$payments[0]['payment_id']:null;$firstBankId=(int)($first['default_bank_account_id']??0)?:gestao_user_default_cash_account($pdo,$companyId,$userId,'pdv');if(function_exists('access_assert_cash_account'))access_assert_cash_account($pdo,$companyId,$firstBankId,'Este usuário não possui permissão para usar o caixa/banco padrão do faturamento.');$firstChartId=(int)($first['default_chart_account_id']??0)?:gestao_default_chart_id($pdo,$companyId,'CREDITO');$firstCostId=(int)($first['default_cost_center_id']??0);$sellerId=count($responsibles)===1?(int)array_key_first($responsibles):0;
        $personName=trim((string)($customer['name']??$invoice['person_name_snapshot']))?:'Consumidor final';$personPhone=(string)(($customer['whatsapp']??'')?:($customer['phone']??''));$personDoc=(string)($customer['document']??$invoice['person_document_snapshot']??'');$addr=(string)($customer['address']??'');$district=(string)($customer['district']??'');$zip=(string)($customer['zip_code']??'');$number=(string)($customer['number']??'');$complement=(string)($customer['complement']??'');$city=(string)($customer['city']??'');$state=strtoupper(substr((string)($customer['state']??''),0,2));
        $invoiceNo=str_pad((string)$invoice['invoice_number'],6,'0',STR_PAD_LEFT);$osLabels=array_map(static fn($r)=>'#'.str_pad((string)$r['os_number_snapshot'],6,'0',STR_PAD_LEFT),$orderLinks);$notes='[NEXA-OS-BATCH:'.$companyId.':'.$invoiceId.'] Fatura de O.S. #'.$invoiceNo.' | O.S.: '.implode(', ',$osLabels);
        $saleOperation=gestao_pdv_sale_operation($pdo,$companyId);$updateStock=$stockItems?gestao_pdv_prepare_stock_out($pdo,$companyId,$saleOperation):false;$obsCol=gestao_pedido_obs_column($pdo);$itemObsCol=gestao_pedido_item_obs_column($pdo);

        $pdo->beginTransaction();
        try{
            $lock=$pdo->prepare('SELECT * FROM os_batch_invoices WHERE company_id=? AND id=? FOR UPDATE');$lock->execute([$companyId,$invoiceId]);$freshInv=$lock->fetch(PDO::FETCH_ASSOC);if(!$freshInv)throw new RuntimeException('Fatura não encontrada durante o faturamento.');if(strtoupper((string)$freshInv['status'])==='FATURADA'&&(int)$freshInv['pedido_id']>0){$pdo->commit();return(int)$freshInv['pedido_id'];}if(strtoupper((string)$freshInv['status'])!=='APROVADA')throw new RuntimeException('A situação da fatura mudou. Atualize a tela.');
            $stBilled=$pdo->prepare('SELECT id,name FROM os_statuses WHERE company_id=? AND active=1 AND is_billed_status=1 ORDER BY id FOR UPDATE');$stBilled->execute([$companyId]);$billedRows=$stBilled->fetchAll(PDO::FETCH_ASSOC)?:[];if(count($billedRows)!==1)throw new RuntimeException(count($billedRows)>1?'Existe mais de um status marcado como Faturado. Ajuste em Configurações da O.S.':'Cadastre ou marque um status como Faturado em Configurações da O.S. antes de concluir o faturamento.');$billedStatusId=(int)$billedRows[0]['id'];
            $locks=$pdo->prepare("SELECT id,os_number,person_id,status_id,billing_status,batch_invoice_id,total_general,opened_at,closed_at FROM os_orders WHERE company_id=? AND id IN ($ph) FOR UPDATE");$locks->execute(array_merge([$companyId],$osIds));$locked=[];foreach($locks->fetchAll(PDO::FETCH_ASSOC)?:[] as $r)$locked[(int)$r['id']]=$r;if(count($locked)!==count($osIds))throw new RuntimeException('Uma O.S. da fatura não foi encontrada.');
            foreach($orderLinks as $link){$o=$locked[(int)$link['os_order_id']];if((int)$o['person_id']!==$customerId)throw new RuntimeException('O cliente de uma O.S. foi alterado após a conferência.');if(strtoupper((string)$o['billing_status'])!=='PENDENTE')throw new RuntimeException('A O.S. #'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).' deixou de estar pendente.');if((int)($o['batch_invoice_id']??0)>0&&(int)$o['batch_invoice_id']!==$invoiceId)throw new RuntimeException('Uma O.S. foi vinculada a outra fatura.');if((int)($freshInv['status_id_filter']??0)>0 && (int)$o['status_id']!==(int)$freshInv['status_id_filter'])throw new RuntimeException('O status da O.S. #'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).' foi alterado após a conferência. Gere uma nova fatura.');$currentServiceDate=substr((string)(!empty($o['closed_at'])?$o['closed_at']:$o['opened_at']),0,10);if($currentServiceDate!==(string)$link['service_date_snapshot'])throw new RuntimeException('A data de conclusão da O.S. #'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).' foi alterada após a conferência. Gere uma nova fatura.');if(abs(round((float)$o['total_general']-(float)$link['total_general'],2))>0.01)throw new RuntimeException('A O.S. #'.str_pad((string)$o['os_number'],6,'0',STR_PAD_LEFT).' teve o valor alterado após a conferência. Gere uma nova fatura.');}

            $pedidoId=gestao_insert_pedido_dynamic($pdo,['company_id'=>$companyId,'nome_cliente'=>$personName,'customer_document'=>$personDoc,'whatsapp'=>preg_replace('/\D+/','',$personPhone),'endereco'=>$addr,'bairro'=>$district,'zip_code'=>$zip!==''?$zip:null,'numero'=>$number!==''?$number:null,'complemento'=>$complement!==''?$complement:null,'cidade'=>$city!==''?$city:null,'uf'=>$state!==''?$state:null,'city'=>$city!==''?$city:null,'state'=>$state!==''?$state:null,'tipo_entrega'=>'balcao','taxa_entrega'=>0,'forma_pagamento'=>$paymentLabelDb,$obsCol=>$notes,'total_produtos'=>$gross,'total'=>$total,'status'=>'finalizado','finance_person_id'=>$customerId,'customer_id'=>$customerId,'seller_id'=>$sellerId?:null,'payment_method_id'=>$firstPaymentId,'bank_account_id'=>$firstBankId?:null,'chart_account_id'=>$firstChartId,'cost_center_id'=>$firstCostId?:null,'cash_session_id'=>$session?(int)$session['id']:null,'source'=>'ordem_servico_lote','discount_amount'=>(float)$invoice['discount_amount'],'addition_amount'=>(float)$invoice['addition_amount']]);
            if($pedidoId<=0)throw new RuntimeException('Não foi possível gerar o Pedido consolidado da fatura.');$pedidoRow=finance_query_one($pdo,'SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1',[$companyId,$pedidoId])?:['id'=>$pedidoId];$orderNumber=company_sequence_display($pedidoRow,'pedidos');
            foreach($items as $item){if($itemObsCol){$pdo->prepare('INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total,`'.str_replace('`','``',$itemObsCol).'`) VALUES (?,?,?,?,?,?,?)')->execute([$companyId,$pedidoId,$item['produto_id'],$item['qtd'],$item['price'],$item['total'],$item['obs']]);}else{$pdo->prepare('INSERT INTO pedido_itens (company_id,pedido_id,produto_id,qtd,preco_unit,total) VALUES (?,?,?,?,?,?)')->execute([$companyId,$pedidoId,$item['produto_id'],$item['qtd'],$item['price'],$item['total']]);}}
            if(function_exists('brain_profitability_recalculate_order'))brain_profitability_recalculate_order($pdo,$companyId,$pedidoId,true,'order.finalized');if($updateStock&&$stockItems)gestao_pdv_apply_stock_out($pdo,$companyId,$pedidoId,(string)$orderNumber,$stockItems,$saleOperation);
            foreach($payments as $line){$pm=$line['payment'];$amount=(float)$line['amount'];$bankId=(int)($pm['default_bank_account_id']??0)?:gestao_user_default_cash_account($pdo,$companyId,$userId,'pdv');if(function_exists('access_assert_cash_account'))access_assert_cash_account($pdo,$companyId,$bankId,'Este usuário não possui permissão para usar o caixa/banco da forma de pagamento.');$chartId=(int)($pm['default_chart_account_id']??0)?:gestao_default_chart_id($pdo,$companyId,'CREDITO');$costId=(int)($pm['default_cost_center_id']??0);$movementId=null;$receivableId=null;$receivableIds=[];$desc='Fatura O.S. #'.$invoiceNo.' / Pedido #'.$orderNumber.' - '.(string)$pm['name'];
                if(!empty($pm['generate_receivable'])){$schedule=$line['installments']??[];if(!$schedule)$schedule=gestao_default_installment_schedule($pm,$amount,$dueDate?:date('Y-m-d'));$n=count($schedule);foreach($schedule as $idx=>$parc){$pa=round((float)($parc['amount']??0),2);if($pa<=0)continue;$due=finance_date_or_null($parc['due_date']??'')?:($dueDate?:date('Y-m-d'));$label=str_pad((string)($idx+1),2,'0',STR_PAD_LEFT).'/'.str_pad((string)$n,2,'0',STR_PAD_LEFT);$pdo->prepare("INSERT INTO finance_receivables (company_id,person_id,chart_account_id,cost_center_id,description,document_number,installment,issue_date,due_date,expected_payment_method,amount,paid_amount,open_amount,status,notes) VALUES (?,?,?,?,?,?,?,CURDATE(),?,?,?,0,?,'ABERTO',?)")->execute([$companyId,$customerId,$chartId,$costId?:null,$desc,$invoiceNo,$label,$due,(string)$pm['name'],$pa,$pa,$notes]);$receivableIds[]=(int)$pdo->lastInsertId();}$receivableId=$receivableIds[0]??null;
                }
                if(empty($pm['generate_receivable'])){$pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,CURDATE(),'CREDITO',?,?,?,?,'PEDIDO',?,?,?,?,?)")->execute([$companyId,$customerId,$chartId,$costId?:null,$bankId?:null,$pedidoId,$desc,$invoiceNo,$amount,$notes]);$movementId=(int)$pdo->lastInsertId();if($session&&!empty($pm['affects_cash']))gestao_create_cash_operation($pdo,$companyId,$userId,(int)$session['id'],'VENDA',$amount,$bankId?:null,null,$costId?:null,$desc,'',$pedidoId);}
                $pdo->prepare('INSERT INTO gestao_order_payments (company_id,order_id,cash_session_id,payment_method_id,payment_label,amount,received_amount,change_amount,bank_account_id,chart_account_id,cost_center_id,finance_movement_id,receivable_id,receivable_ids) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([$companyId,$pedidoId,$session?(int)$session['id']:null,(int)$line['payment_id'],(string)$pm['name'],$amount,(float)$line['received'],(float)$line['change'],$bankId?:null,$chartId,$costId?:null,$movementId,$receivableId,$receivableIds?implode(',',$receivableIds):null]);
            }
            try{$pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id,pedido_id) VALUES (?,?)')->execute([$companyId,$pedidoId]);}catch(Throwable $ignore){}
            foreach($osIds as $oid)os_release_order_reservations($pdo,$companyId,$oid);
            $up=$pdo->prepare("UPDATE os_orders SET status_id=?,billing_status='FATURADA',batch_invoice_id=?,billed_at=NOW(),updated_at=NOW() WHERE company_id=? AND id=?");foreach($osIds as $oid)$up->execute([$billedStatusId,$invoiceId,$companyId,$oid]);
            $pdo->prepare("UPDATE os_batch_invoices SET status='FATURADA',payment_method_id=?,due_date=?,pedido_id=?,billed_at=NOW(),billed_by=?,updated_by=?,updated_at=NOW() WHERE company_id=? AND id=?")->execute([$paymentMethodId,$dueDate,$pedidoId,$userId?:null,$userId?:null,$companyId,$invoiceId]);
            $pdo->commit();return$pedidoId;
        }catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw$e;}
    }
}
