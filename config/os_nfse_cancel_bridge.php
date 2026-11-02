<?php
declare(strict_types=1);
/** Isolamento: sincronização de O.S. após cancelamento comercial efetivo. */

if (!function_exists('os_nfse_related_authorized_documents')) {
  function os_nfse_related_authorized_documents(PDO $pdo, int $companyId, int $pedidoId): array {
    $s=$pdo->prepare("SELECT DISTINCT d.id,d.model,d.status,d.source_type,d.source_id
      FROM fiscal_documents d
      WHERE d.company_id=? AND UPPER(COALESCE(d.status,''))='AUTORIZADA'
        AND ( (d.source_type='PEDIDO' AND d.source_id=?)
          OR (d.source_type='ORDEM_SERVICO' AND d.source_id IN
              (SELECT id FROM os_orders WHERE company_id=? AND pedido_id=?))
          OR (d.id IN (SELECT fiscal_document_id FROM fiscal_nfse_details
                WHERE company_id=? AND pedido_id=?)) )
      ORDER BY d.id");
    $s->execute([$companyId,$pedidoId,$companyId,$pedidoId,$companyId,$pedidoId]);
    return $s->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('os_nfse_release_after_order_cancel')) {
  function os_nfse_release_after_order_cancel(PDO $pdo, int $companyId, int $pedidoId): void {
    // Somente libera depois que o pedido está cancelado E os efeitos comerciais
    // foram concluídos; jamais somente após receber a solicitação ao provedor.
    $s=$pdo->prepare("SELECT p.status,e.status AS cancellation_status FROM pedidos p JOIN order_cancellation_effects e ON e.company_id=p.company_id AND e.order_id=p.id WHERE p.company_id=? AND p.id=? LIMIT 1");
    $s->execute([$companyId,$pedidoId]);$row=$s->fetch(PDO::FETCH_ASSOC)?:[];
    if(strtolower((string)($row['status']??''))!=='cancelado'||strtoupper((string)($row['cancellation_status']??''))!=='COMPLETED')return;
    if(os_nfse_related_authorized_documents($pdo,$companyId,$pedidoId)) {
      throw new RuntimeException('Ainda existe documento fiscal autorizado ligado à O.S./pedido. A O.S. não pode ser liberada.');
    }
    $s=$pdo->prepare("SELECT id,batch_invoice_id FROM os_orders WHERE company_id=? AND pedido_id=? FOR UPDATE");
    $s->execute([$companyId,$pedidoId]);$orders=$s->fetchAll(PDO::FETCH_ASSOC)?:[];
    $s=$pdo->prepare("SELECT * FROM os_batch_invoices WHERE company_id=? AND pedido_id=? LIMIT 1 FOR UPDATE");
    $s->execute([$companyId,$pedidoId]);$invoice=$s->fetch(PDO::FETCH_ASSOC)?:null;
    if(!$orders&&!$invoice)return;
    if($invoice){
      $statusId=(int)($invoice['status_id_filter']??0);
      if($statusId<=0){$s=$pdo->prepare('SELECT id FROM os_statuses WHERE company_id=? AND active=1 AND allow_batch_billing=1 ORDER BY id LIMIT 1');$s->execute([$companyId]);$statusId=(int)$s->fetchColumn();}
      if($statusId<=0)throw new RuntimeException('Configure ao menos um status habilitado para faturamento em lote antes de liberar as O.S.');
      $pdo->prepare("UPDATE os_batch_invoices SET status='CANCELADA',cancelled_at=COALESCE(cancelled_at,NOW()),updated_at=NOW() WHERE company_id=? AND id=? AND pedido_id=?")
        ->execute([$companyId,(int)$invoice['id'],$pedidoId]);
      // A relação de O.S. permanece no histórico da fatura CANCELADA.
      $pdo->prepare("UPDATE os_orders SET billing_status='PENDENTE',pedido_id=NULL,batch_invoice_id=NULL,billed_at=NULL,service_document_type=NULL,product_document_type=NULL,status_id=?,updated_at=NOW() WHERE company_id=? AND pedido_id=? AND batch_invoice_id=?")
        ->execute([$statusId,$companyId,$pedidoId,(int)$invoice['id']]);
    } else {
      $pdo->prepare("UPDATE os_orders SET billing_status='PENDENTE',pedido_id=NULL,billed_at=NULL,service_document_type=NULL,product_document_type=NULL,updated_at=NOW() WHERE company_id=? AND pedido_id=? AND (batch_invoice_id IS NULL OR batch_invoice_id=0)")
        ->execute([$companyId,$pedidoId]);
    }
    // O histórico permanece auditável, mas nenhum job antigo pode retransmitir a NFS-e.
    $pdo->prepare("UPDATE os_fiscal_queue SET status='CANCELADO',scheduled_at=NULL,finished_at=COALESCE(finished_at,NOW()),updated_at=NOW() WHERE company_id=? AND pedido_id=? AND status IN ('PENDENTE','ERRO','PROCESSANDO')")
      ->execute([$companyId,$pedidoId]);
  }
}
