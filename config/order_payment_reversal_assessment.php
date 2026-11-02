<?php
declare(strict_types=1);
/**
 * Avaliação SOMENTE LEITURA, conservadora, dos pagamentos vinculados ao pedido.
 * Nenhum resultado desta função autoriza estorno automático. Toda mudança futura
 * deverá passar por conciliação externa, controle transacional e trilha de auditoria.
 */
if (!function_exists('nexa_order_payment_reversal_assessment')) {
  function nexa_order_payment_reversal_assessment(array $payments, array $receivables, ?string $traceError): array {
    if ($traceError !== null && $traceError !== '') return [
      'category'=>'INDETERMINADO', 'issues'=>['Falha na rastreabilidade dos pagamentos.'], 'rows'=>[]
    ];
    $receivableMap=[];
    foreach ($receivables as $r) { $receivableMap[(int)($r['id']??0)]=$r; }
    $issues=[]; $rows=[];
    if (!$payments) $issues[]='Não há registros de pagamento associados; é necessário conferir outras origens de financeiro e caixa.';
    foreach ($payments as $pay) {
      $id=(int)($pay['id']??0);
      $label=trim((string)($pay['payment_label']??''));
      $local=[];
      $amount=(float)($pay['amount']??0);
      if (!is_finite($amount) || $amount<0) $local[]='Valor de pagamento inválido.';
      if ((int)($pay['finance_movement_id']??0)>0) $local[]='Há movimento financeiro registrado.';
      if ((int)($pay['cash_session_id']??0)>0) $local[]='Há vínculo com sessão de caixa.';
      $refs=[];
      $direct=(int)($pay['receivable_id']??0);
      if ($direct>0) $refs[]=$direct;
      foreach (explode(',',(string)($pay['receivable_ids']??'')) as $part) {
        $part=trim($part);
        if ($part==='') continue;
        if (!ctype_digit($part) || (int)$part<=0) { $local[]='Referência de parcela inválida.'; continue; }
        $refs[]=(int)$part;
      }
      foreach (array_unique($refs) as $rid) {
        if (!isset($receivableMap[$rid])) { $local[]='Parcela vinculada não localizada: '.$rid.'.'; continue; }
        $rec=$receivableMap[$rid];
        if ((float)($rec['paid_amount']??0)>0) $local[]='Parcela '.$rid.' com pagamento registrado.';
        $status=strtoupper(trim((string)($rec['status']??'')));
        if (!in_array($status,['ABERTO','PENDENTE','EM_ABERTO'],true)) $local[]='Parcela '.$rid.' com situação não confirmada como aberta ('.$status.').';
      }
      // O rótulo é apenas indicador de atenção, NUNCA comprovação de liquidação.
      if (preg_match('/PIX|CART[AÃ]O|CREDITO|CRÉDITO|DEBITO|DÉBITO|BOLETO|TEF|GATEWAY|LINK|TRANSFER|CHEQUE/ui',$label))
        $local[]='Forma de pagamento que pode depender de liquidação ou integração externa.';
      // Sem identificadores ainda não significa ausência de registro em outros módulos.
      if (!$local) $local[]='Sem bloqueio nos vínculos explícitos; conferir financeiro, caixa e integrações antes de autorizar qualquer alteração.';
      $rows[]=['payment_id'=>$id,'label'=>$label,'issues'=>$local,'requires_review'=>true];
      foreach ($local as $i) $issues[]='Pagamento '.$id.': '.$i;
    }
    return ['category'=>'REVISÃO OBRIGATÓRIA','issues'=>$issues,'rows'=>$rows];
  }
}
