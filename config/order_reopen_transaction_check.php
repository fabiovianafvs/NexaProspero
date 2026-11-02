<?php
declare(strict_types=1);
/**
 * Simulação consistente e SOMENTE LEITURA, sob lock exclusivo do pedido.
 * Não concede elegibilidade operacional: a refinalização continua desabilitada.
 * Não usa o motor de cancelamento (estorno definitivo).
 */
if (!function_exists('nexa_order_reopen_transaction_check')) {
  function nexa_order_reopen_transaction_check(PDO $pdo, int $companyId, int $orderId): array {
    if ($companyId <= 0 || $orderId <= 0) throw new InvalidArgumentException('Empresa/pedido inválido.');
    if ($pdo->inTransaction()) throw new RuntimeException('Verificação requer transação exclusiva.');
    $reasons=[]; $found=[];
    $pdo->beginTransaction();
    try {
      $q=$pdo->prepare('SELECT id,status FROM pedidos WHERE company_id=? AND id=? FOR UPDATE');
      $q->execute([$companyId,$orderId]); $order=$q->fetch(PDO::FETCH_ASSOC);
      if (!$order) throw new RuntimeException('Pedido não pertence à empresa atual.');
      if (strtolower(trim((string)$order['status'])) !== 'finalizado') $reasons[]='Pedido não está finalizado.';
      // Guarda reaplicada enquanto o pedido permanece bloqueado.
      $g=nexa_order_reopen_guard($pdo,$companyId,$orderId);
      foreach ($g['reasons'] as $reason) $reasons[]=$reason;
      // Todas as origens conhecidas devem ser examinadas, mas a existência de
      // dados em outras integrações não pode ser descartada por esta consulta.
      $checks=[
        ['gestao_order_payments','order_id=?'],
        ['finance_movements',"origin_type='PEDIDO' AND origin_id=?"],
        ['gestao_cash_operations','order_id=?'],
        ['estoque_movimentos',"origem_tipo IN ('PDV','CANCELAMENTO_PEDIDO') AND origem_id=?"],
        ['order_cancellation_effects','order_id=?'],
      ];
      // Contas a receber: o Nexa usa finance_receivables.order_id nas
      // versões novas e gestao_order_payments.receivable_id no fluxo do PDV.
      // Nunca inferir vínculo somente por cliente/valor/data.
      try {
        $col=$pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema=DATABASE() AND table_name=?');
        $col->execute(['finance_receivables']);
        $receivableCols=array_fill_keys(array_map('strtolower',$col->fetchAll(PDO::FETCH_COLUMN)),true);
        if (!$receivableCols) {
          $found['finance_receivables']=null;
          $reasons[]='Estrutura indisponível: finance_receivables';
        } elseif (!isset($receivableCols['company_id']) || !isset($receivableCols['id'])) {
          $found['finance_receivables']=null;
          $reasons[]='finance_receivables sem company_id/id; conferência bloqueada.';
        } else {
          $col->execute(['gestao_order_payments']);
          $paymentCols=array_fill_keys(array_map('strtolower',$col->fetchAll(PDO::FETCH_COLUMN)),true);
          $hasDirect=isset($receivableCols['order_id']);
          $hasPayment=isset($paymentCols['company_id'],$paymentCols['order_id'],$paymentCols['receivable_id']);
          if (!$hasDirect && !$hasPayment) {
            $found['finance_receivables']=null;
            $reasons[]='Não há coluna order_id nem vínculo receivable_id disponível para conferir contas a receber; reabertura bloqueada.';
          } else {
            $paths=[]; $params=[$companyId];
            if ($hasDirect) { $paths[]='r.order_id=?'; $params[]=$orderId; }
            if ($hasPayment) {
              $paths[]='EXISTS (SELECT 1 FROM gestao_order_payments p WHERE p.company_id=r.company_id AND p.receivable_id=r.id AND p.order_id=?)';
              $params[]=$orderId;
            }
            $sql='SELECT COUNT(*) FROM finance_receivables r WHERE r.company_id=? AND ('.implode(' OR ',$paths).')';
            $q=$pdo->prepare($sql);
            $q->execute($params);
            $count=(int)$q->fetchColumn();
            $found['finance_receivables']=$count;
            if ($count>0) $reasons[]='finance_receivables: '.$count.' vínculo(s) a reconciliar.';
          }
        }
      } catch (PDOException $e) {
        $found['finance_receivables']=null;
        $reasons[]='Não foi possível conferir finance_receivables (SQLSTATE '.(string)$e->getCode().'); reabertura bloqueada.';
        error_log('[NEXA_REOPEN_SIMULATION] check=finance_receivables company='.$companyId.' order='.$orderId.' error='.$e->getMessage());
      }
      foreach($checks as [$table,$predicate]) {
        $q=$pdo->prepare('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name=?');
        $q->execute([$table]);
        if(!(int)$q->fetchColumn()) { $reasons[]='Estrutura indisponível: '.$table; $found[$table]=null; continue; }
        try {
          // As instalações podem ter versões diferentes dessas tabelas. Uma coluna
          // ausente deve aparecer como impedimento identificável, não derrubar toda a simulação.
          $q=$pdo->prepare('SELECT COUNT(*) FROM '.$table.' WHERE company_id=? AND '.$predicate);
          $q->execute([$companyId,$orderId]); $count=(int)$q->fetchColumn();
          $found[$table]=$count;
          if ($count>0) $reasons[]=$table.': '.$count.' vínculo(s) a reconciliar.';
        } catch (PDOException $e) {
          $found[$table]=null;
          $sqlState=(string)$e->getCode();
          $reasons[]='Não foi possível conferir '.$table.' (SQLSTATE '.$sqlState.'). Verifique a estrutura da tabela; reabertura bloqueada.';
          error_log('[NEXA_REOPEN_SIMULATION] check='.$table.' company='.$companyId.' order='.$orderId.' error='.$e->getMessage());
        }
      }
      // Checagem complementar: cobranças e integrações externas podem ter
      // vínculos não modelados nas tabelas acima; nunca liberá-las por omissão.
      if (!$reasons) $reasons[]='Sem vínculos locais nas origens verificadas, mas ainda faltam conciliação externa, idempotência da refinalização e homologação do estoque/financeiro.';
      $pdo->rollBack(); // a simulação nunca grava nem confirma mudanças
      return ['release_allowed'=>false,'reasons'=>array_values(array_unique($reasons)),'counts'=>$found];
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw new RuntimeException('Não foi possível concluir a simulação. Reabertura bloqueada.',0,$e);
    }
  }
}
