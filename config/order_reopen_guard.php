<?php
declare(strict_types=1);
/**
 * Guarda de leitura para a futura reabertura controlada de pedidos.
 * Nunca altera dados, não transmite nem descarta documentos.
 * Fail-closed: qualquer falha de consulta gera bloqueio.
 * Chamar novamente SOB uma transação e com o pedido travado FOR UPDATE
 * antes de permitir qualquer operação futura de reversão.
 */
if (!function_exists('nexa_order_reopen_guard')) {
  function nexa_order_reopen_guard(PDO $pdo, int $companyId, int $orderId): array {
    $reasons=[];
    if ($companyId<=0 || $orderId<=0) return ['eligible'=>false,'reasons'=>['Empresa ou pedido inválido.']];
    try {
      $q=$pdo->prepare('SELECT status FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $q->execute([$companyId,$orderId]);
      $status=$q->fetchColumn();
      if ($status===false) $reasons[]='Pedido não localizado na empresa atual.';
      elseif (strtolower(trim((string)$status))!=='finalizado') $reasons[]='Pedido não está finalizado.';

      // Toda estrutura exigida precisa existir. Não criar tabelas durante requisições.
      foreach(['fiscal_documents','fiscal_document_items','pedido_itens','order_cancellation_effects'] as $table){
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if (!(int)$q->fetchColumn()) $reasons[]='Estrutura necessária indisponível: '.$table.'.';
      }
      if ($reasons) return ['eligible'=>false,'reasons'=>$reasons];

      $q=$pdo->prepare("SELECT COUNT(DISTINCT fd.id) FROM fiscal_documents fd WHERE fd.company_id=? AND ((UPPER(TRIM(COALESCE(fd.source_type,''))) IN ('PEDIDO','ORDER','VENDA') AND fd.source_id=?) OR EXISTS(SELECT 1 FROM fiscal_document_items di JOIN pedido_itens pi ON pi.company_id=di.company_id AND pi.id=di.order_item_id WHERE di.company_id=fd.company_id AND di.fiscal_document_id=fd.id AND pi.company_id=? AND pi.pedido_id=?))");
      $q->execute([$companyId,$orderId,$companyId,$orderId]);
      if ((int)$q->fetchColumn()>0) $reasons[]='Documento fiscal vinculado por pedido ou item: regularização fiscal prévia obrigatória.';

      $q=$pdo->prepare('SELECT COUNT(*) FROM order_cancellation_effects WHERE company_id=? AND order_id=?');
      $q->execute([$companyId,$orderId]);
      if ((int)$q->fetchColumn()>0) $reasons[]='Existe controle anterior de cancelamento/estorno: exige reconciliação.';
    } catch(Throwable $e) {
      $reasons[]='Falha de conferência de vínculos. Operação bloqueada.';
    }
    return ['eligible'=>!$reasons,'reasons'=>$reasons];
  }
}
