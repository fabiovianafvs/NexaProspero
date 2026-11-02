<?php
declare(strict_types=1);
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/delivery_control.php';

header('Content-Type: application/json; charset=UTF-8');
try{
    $companyId=(int)COMPANY_ID;
    delivery_control_require_enabled($pdo,$companyId);
    $q=trim((string)($_GET['q']??''));
    $where=[
        'o.company_id=?',
        "o.status IN ('GERADA','SEPARADA')",
        "NOT EXISTS(SELECT 1 FROM delivery_control_manifest_orders mo JOIN delivery_control_manifests mx ON mx.company_id=mo.company_id AND mx.id=mo.manifest_id WHERE mo.company_id=o.company_id AND mo.delivery_order_id=o.id AND mx.status<>'CANCELADO')"
    ];
    $params=[$companyId];
    if($q!==''){
        $like=nexa_search_like($q);
        $digits=preg_replace('/\D+/','',$q) ?: '';
        $parts=[
            'CAST(o.order_number AS CHAR) LIKE ?',
            'CAST(s.pedido_id AS CHAR) LIKE ?',
            's.customer_name LIKE ?',
            'o.delivery_address LIKE ?',
            'o.delivery_district LIKE ?',
            'o.delivery_city LIKE ?'
        ];
        array_push($params,$like,$like,$like,$like,$like,$like);
        if($digits!==''){
            $parts[]='o.order_number=?';$params[]=(int)$digits;
            $parts[]='s.pedido_id=?';$params[]=(int)$digits;
        }
        $where[]='('.implode(' OR ',$parts).')';
    }
    $sql="SELECT o.id,o.order_number,o.scheduled_date,o.scheduled_time,o.status,o.delivery_address,o.delivery_number,o.delivery_district,o.delivery_city,o.delivery_state,
                 s.customer_name,s.pedido_id,COALESCE(SUM(oi.quantity),0) total_qty
          FROM delivery_control_orders o
          JOIN delivery_control_sales s ON s.company_id=o.company_id AND s.id=o.delivery_sale_id
          LEFT JOIN delivery_control_order_items oi ON oi.company_id=o.company_id AND oi.delivery_order_id=o.id
          WHERE ".implode(' AND ',$where)."
          GROUP BY o.id
          ORDER BY (o.scheduled_date IS NULL),o.scheduled_date,o.scheduled_time,o.order_number
          LIMIT 80";
    $st=$pdo->prepare($sql);$st->execute($params);$rows=$st->fetchAll(PDO::FETCH_ASSOC)?:[];
    $out=[];
    foreach($rows as $r){
        $out[]=[
            'id'=>(int)$r['id'],
            'order_number'=>(int)$r['order_number'],
            'pedido_id'=>(int)$r['pedido_id'],
            'customer_name'=>(string)$r['customer_name'],
            'scheduled_date'=>(string)($r['scheduled_date']??''),
            'scheduled_time'=>(string)($r['scheduled_time']??''),
            'status'=>(string)$r['status'],
            'address'=>trim((string)($r['delivery_address']??'').(((string)($r['delivery_number']??''))!==''?', '.(string)$r['delivery_number']:'')),
            'district'=>(string)($r['delivery_district']??''),
            'city'=>(string)($r['delivery_city']??''),
            'state'=>(string)($r['delivery_state']??''),
            'total_qty'=>(float)$r['total_qty'],
        ];
    }
    echo json_encode(['ok'=>true,'rows'=>$out],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){
    http_response_code(400);
    echo json_encode(['ok'=>false,'message'=>$e->getMessage()],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES);
}
