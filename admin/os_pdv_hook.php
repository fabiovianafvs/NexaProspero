<?php
/**
 * Gancho de integração O.S. x PDV.
 * Inclua este arquivo no PDV após _bootstrap.php e chame as funções nos pontos indicados.
 */
require_once __DIR__ . '/../config/os_module.php';

if (!function_exists('nexa_os_pdv_finalize')) {
    function nexa_os_pdv_finalize(PDO $pdo, $companyId, $pedidoId)
    {
        $stmt = $pdo->prepare("SELECT source FROM pedidos WHERE company_id=? AND id=? LIMIT 1");
        $stmt->execute(array((int)$companyId,(int)$pedidoId));
        if ($stmt->fetchColumn() !== 'ordem_servico') return 0;
        return os_mark_billed_by_pedido($pdo, $companyId, $pedidoId);
    }
}

if (!function_exists('nexa_os_pdv_cancel')) {
    function nexa_os_pdv_cancel(PDO $pdo, $companyId, $pedidoId)
    {
        $pdo->beginTransaction();
        try {
            $stmt=$pdo->prepare("SELECT id FROM os_orders WHERE company_id=? AND pedido_id=? LIMIT 1 FOR UPDATE");
            $stmt->execute(array((int)$companyId,(int)$pedidoId));
            $orderId=(int)$stmt->fetchColumn();
            if($orderId>0){
                os_release_order_reservations($pdo,$companyId,$orderId);
                $u=$pdo->prepare("UPDATE os_orders SET billing_status='PENDENTE',pedido_id=NULL,product_document_type=NULL,service_document_type=NULL,updated_at=NOW() WHERE company_id=? AND id=?");
                $u->execute(array((int)$companyId,$orderId));
            }
            $pdo->commit();
            return $orderId;
        } catch(Exception $e){
            if($pdo->inTransaction())$pdo->rollBack();
            throw $e;
        }
    }
}
