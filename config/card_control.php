<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Controle de Cartões (V11.52.7)
 *
 * Agenda de recebimento de cartões separada do Contas a Receber:
 * - regras por forma de pagamento / quantidade de parcelas;
 * - snapshot de taxa, dias, adquirente e dados TEF por venda;
 * - parcelas previstas com valor bruto, taxa e valor líquido;
 * - baixa somente quando a parcela é efetivamente recebida;
 * - preparado para futura conciliação com adquirentes/TEF.
 */

require_once __DIR__ . '/finance_module.php';

if (!function_exists('card_control_schema_ready')) {
  function card_control_schema_ready(PDO $pdo): bool {
    try {
      return finance_table_exists($pdo, 'finance_card_payment_plans')
        && finance_table_exists($pdo, 'finance_card_sales')
        && finance_table_exists($pdo, 'finance_card_installments')
        && finance_table_exists($pdo, 'finance_payment_methods')
        && finance_column_exists($pdo, 'finance_payment_methods', 'card_control_enabled');
    } catch (Throwable $e) { return false; }
  }
}

if (!function_exists('card_control_csrf_token')) {
  function card_control_csrf_token(): string {
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    if (empty($_SESSION['nexa_card_control_csrf']) || !is_string($_SESSION['nexa_card_control_csrf'])) {
      $_SESSION['nexa_card_control_csrf'] = bin2hex(random_bytes(32));
    }
    return (string)$_SESSION['nexa_card_control_csrf'];
  }
}

if (!function_exists('card_control_csrf_check')) {
  function card_control_csrf_check(string $token): void {
    $expected = card_control_csrf_token();
    if ($token === '' || !hash_equals($expected, $token)) {
      throw new RuntimeException('Sessão expirada. Atualize a página e tente novamente.');
    }
  }
}

if (!function_exists('card_control_payment_plans')) {
  function card_control_payment_plans(PDO $pdo, int $companyId, int $paymentMethodId, bool $activeOnly = true): array {
    if ($companyId <= 0 || $paymentMethodId <= 0 || !card_control_schema_ready($pdo)) return [];
    $sql = 'SELECT * FROM finance_card_payment_plans WHERE company_id=? AND payment_method_id=?';
    $params = [$companyId, $paymentMethodId];
    if ($activeOnly) $sql .= ' AND active=1';
    $sql .= ' ORDER BY installments ASC,id ASC';
    return finance_query_all($pdo, $sql, $params);
  }
}

if (!function_exists('card_control_payment_map')) {
  function card_control_payment_map(PDO $pdo, int $companyId, array $payments): array {
    if (!card_control_schema_ready($pdo)) return [];
    $out = [];
    foreach ($payments as $payment) {
      $id = (int)($payment['id'] ?? 0);
      if ($id <= 0 || empty($payment['card_control_enabled'])) continue;
      $plans = card_control_payment_plans($pdo, $companyId, $id, true);
      if (!$plans) continue;
      $out[(string)$id] = [
        'name' => (string)($payment['name'] ?? 'Cartão'),
        'acquirer' => (string)($payment['card_acquirer_name'] ?? ''),
        'plans' => array_map(static fn(array $p): array => [
          'id' => (int)$p['id'],
          'installments' => (int)$p['installments'],
          'firstDays' => (int)$p['first_receipt_days'],
          'intervalDays' => (int)$p['interval_days'],
          'feePercent' => (float)$p['fee_percent'],
        ], $plans),
      ];
    }
    return $out;
  }
}

if (!function_exists('card_control_replace_payment_plans')) {
  function card_control_replace_payment_plans(PDO $pdo, int $companyId, int $paymentMethodId, array $post): void {
    if (!card_control_schema_ready($pdo)) throw new RuntimeException('A estrutura do Controle de Cartões ainda não foi instalada. Execute o Atualizador do Banco.');
    $installments = $post['card_plan_installments'] ?? [];
    $firstDays = $post['card_plan_first_days'] ?? [];
    $intervalDays = $post['card_plan_interval_days'] ?? [];
    $fees = $post['card_plan_fee_percent'] ?? [];
    if (!is_array($installments)) $installments = [];
    if (!is_array($firstDays)) $firstDays = [];
    if (!is_array($intervalDays)) $intervalDays = [];
    if (!is_array($fees)) $fees = [];

    $normalized = [];
    foreach ($installments as $idx => $raw) {
      $qty = max(1, min(48, (int)$raw));
      $first = max(0, min(365, (int)($firstDays[$idx] ?? 30)));
      $interval = max(0, min(365, (int)($intervalDays[$idx] ?? 30)));
      $fee = finance_money_in($fees[$idx] ?? 0);
      $fee = max(0.0, min(100.0, round($fee, 4)));
      if ($qty > 1 && $interval <= 0) $interval = 30;
      if ($qty === 1 && $interval < 0) $interval = 0;
      $normalized[$qty] = ['installments'=>$qty,'first'=>$first,'interval'=>$interval,'fee'=>$fee];
    }
    if (!$normalized) throw new RuntimeException('Cadastre pelo menos uma opção de recebimento/parcelamento para o cartão.');
    ksort($normalized, SORT_NUMERIC);

    $pdo->prepare('UPDATE finance_card_payment_plans SET active=0,updated_at=NOW() WHERE company_id=? AND payment_method_id=?')->execute([$companyId,$paymentMethodId]);
    $sql = 'INSERT INTO finance_card_payment_plans (company_id,payment_method_id,installments,first_receipt_days,interval_days,fee_percent,active,created_at,updated_at) VALUES (?,?,?,?,?,?,1,NOW(),NOW()) ON DUPLICATE KEY UPDATE first_receipt_days=VALUES(first_receipt_days),interval_days=VALUES(interval_days),fee_percent=VALUES(fee_percent),active=1,updated_at=NOW()';
    $st = $pdo->prepare($sql);
    foreach ($normalized as $plan) $st->execute([$companyId,$paymentMethodId,$plan['installments'],$plan['first'],$plan['interval'],$plan['fee']]);
  }
}

if (!function_exists('card_control_plan_for_installments')) {
  function card_control_plan_for_installments(PDO $pdo, int $companyId, int $paymentMethodId, int $installments): ?array {
    if (!card_control_schema_ready($pdo)) return null;
    return finance_query_one($pdo, 'SELECT * FROM finance_card_payment_plans WHERE company_id=? AND payment_method_id=? AND installments=? AND active=1 LIMIT 1', [$companyId,$paymentMethodId,$installments]) ?: null;
  }
}

if (!function_exists('card_control_line_plan_from_post')) {
  function card_control_line_plan_from_post(PDO $pdo, int $companyId, array $payment, array $post, string $lineKey): ?array {
    if (empty($payment['card_control_enabled'])) return null;
    if (!card_control_schema_ready($pdo)) throw new RuntimeException('A forma de pagamento usa Controle de Cartões, mas a estrutura ainda não foi instalada.');
    $matrix = $post['card_installment_count'] ?? [];
    $selected = 0;
    if (is_array($matrix)) $selected = (int)($matrix[$lineKey] ?? 0);
    elseif ($lineKey === '0') $selected = (int)$matrix;
    $plans = card_control_payment_plans($pdo, $companyId, (int)$payment['id'], true);
    if (!$plans) throw new RuntimeException('A forma de pagamento está com Controle de Cartões habilitado, mas não possui regras de parcelas/dias/taxa.');
    if ($selected <= 0 && count($plans) === 1) $selected = (int)$plans[0]['installments'];
    if ($selected <= 0) throw new RuntimeException('Selecione o parcelamento do cartão antes de finalizar a venda.');
    foreach ($plans as $plan) if ((int)$plan['installments'] === $selected) return $plan;
    throw new RuntimeException('O parcelamento selecionado não está habilitado para esta forma de pagamento.');
  }
}

if (!function_exists('card_control_split_cents')) {
  function card_control_split_cents(float $amount, int $count): array {
    $count = max(1,$count);
    $total = (int)round($amount * 100);
    $base = intdiv($total,$count);
    $rem = $total - ($base*$count);
    $out = array_fill(0,$count,$base);
    if ($out) $out[$count-1] += $rem;
    return $out;
  }
}

if (!function_exists('card_control_register_order_payment')) {
  function card_control_register_order_payment(PDO $pdo, int $companyId, int $orderId, int $orderPaymentId, array $line, int $personId = 0): int {
    $payment = (array)($line['payment'] ?? []);
    $plan = is_array($line['card_plan'] ?? null) ? $line['card_plan'] : null;
    if (!$plan || empty($payment['card_control_enabled'])) return 0;
    if (!card_control_schema_ready($pdo)) throw new RuntimeException('Controle de Cartões não disponível. Atualize o banco.');

    $amount = round((float)($line['amount'] ?? 0),2);
    if ($amount <= 0) return 0;
    $count = max(1,(int)$plan['installments']);
    $feePercent = max(0.0,min(100.0,(float)$plan['fee_percent']));
    $firstDays = max(0,(int)$plan['first_receipt_days']);
    $interval = max(0,(int)$plan['interval_days']);
    if ($count > 1 && $interval <= 0) $interval = 30;

    $saleDate = date('Y-m-d');
    $grossCents = card_control_split_cents($amount,$count);
    $feeTotalCents = 0;
    $netTotalCents = 0;
    $installmentRows = [];
    $baseDate = new DateTimeImmutable($saleDate);
    foreach ($grossCents as $idx=>$grossCent) {
      $feeCent = (int)round($grossCent * ($feePercent/100));
      $netCent = max(0,$grossCent-$feeCent);
      $feeTotalCents += $feeCent; $netTotalCents += $netCent;
      $days = $firstDays + ($idx*$interval);
      $due = $baseDate->modify('+' . $days . ' days')->format('Y-m-d');
      $installmentRows[] = ['no'=>$idx+1,'due'=>$due,'gross'=>$grossCent/100,'fee'=>$feeCent/100,'net'=>$netCent/100];
    }

    $ins = $pdo->prepare("INSERT INTO finance_card_sales
      (company_id,order_id,order_payment_id,person_id,payment_method_id,sale_date,gross_amount,installments,fee_percent,fee_amount,net_amount,acquirer_name,acquirer_cnpj,card_brand,tef_integration,nsu,authorization_code,provider_transaction_id,terminal_id,merchant_id,bank_account_id,chart_account_id,cost_center_id,fee_chart_account_id,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ABERTO',NOW(),NOW())");
    $ins->execute([
      $companyId,$orderId,$orderPaymentId,$personId?:null,(int)$payment['id'],$saleDate,$amount,$count,$feePercent,$feeTotalCents/100,$netTotalCents/100,
      trim((string)($payment['card_acquirer_name'] ?? '')) ?: null, trim((string)($payment['nfce_card_cnpj'] ?? '')) ?: null, trim((string)($payment['nfce_card_brand'] ?? '')) ?: null,
      trim((string)($payment['nfce_card_integration'] ?? '')) ?: null,
      trim((string)($line['card_nsu'] ?? '')) ?: null, trim((string)($line['card_authorization_code'] ?? '')) ?: null, trim((string)($line['card_transaction_id'] ?? '')) ?: null,
      trim((string)($line['card_terminal_id'] ?? '')) ?: null, trim((string)($line['card_merchant_id'] ?? '')) ?: null,
      (int)($payment['default_bank_account_id'] ?? 0) ?: null,(int)($payment['default_chart_account_id'] ?? 0) ?: null,(int)($payment['default_cost_center_id'] ?? 0) ?: null,(int)($payment['card_fee_chart_account_id'] ?? 0) ?: null
    ]);
    $saleId = (int)$pdo->lastInsertId();

    $st = $pdo->prepare("INSERT INTO finance_card_installments
      (company_id,card_sale_id,installment_no,total_installments,due_date,gross_amount,fee_percent,fee_amount,net_amount,status,created_at,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,'ABERTO',NOW(),NOW())");
    foreach ($installmentRows as $r) $st->execute([$companyId,$saleId,$r['no'],$count,$r['due'],$r['gross'],$feePercent,$r['fee'],$r['net']]);

    if (finance_column_exists($pdo,'gestao_order_payments','card_sale_id')) {
      $pdo->prepare('UPDATE gestao_order_payments SET card_sale_id=? WHERE company_id=? AND id=?')->execute([$saleId,$companyId,$orderPaymentId]);
    }
    return $saleId;
  }
}

if (!function_exists('card_control_receivable_ready')) {
  /** Estrutura para vendas no cartão vindas do Contas a Receber (db/20261123_cartoes_contas_receber_v1.sql). */
  function card_control_receivable_ready(PDO $pdo): bool {
    return card_control_schema_ready($pdo) && finance_column_exists($pdo, 'finance_card_sales', 'settlement_id');
  }
}

if (!function_exists('card_control_method_enabled')) {
  /** Forma de pagamento com "Habilitar controle" marcado (dados completos) ou null. */
  function card_control_method_enabled(PDO $pdo, int $companyId, int $paymentMethodId): ?array {
    if ($paymentMethodId <= 0 || !card_control_schema_ready($pdo)) return null;
    $m = finance_query_one($pdo, 'SELECT * FROM finance_payment_methods WHERE company_id=? AND id=? LIMIT 1', [$companyId, $paymentMethodId]);
    return ($m && !empty($m['card_control_enabled'])) ? $m : null;
  }
}

if (!function_exists('card_control_register_receivable_payment')) {
  /**
   * Baixa do Contas a Receber paga com cartão: o título fica quitado, mas o dinheiro só entra
   * no banco quando a operadora pagar. Cria a venda no Controle de Cartões (origem RECEBER,
   * ligada ao título e à baixa) com as parcelas previstas, em vez do crédito no caixa/banco.
   * O recebimento de cada parcela (Controle de Cartões) gera o crédito no plano de contas do título.
   */
  function card_control_register_receivable_payment(PDO $pdo, int $companyId, int $settlementId, array $title, array $split, float $amount, string $saleDate): int {
    $amount = round($amount, 2);
    if ($amount <= 0) return 0;
    if (!card_control_receivable_ready($pdo)) throw new RuntimeException('Para baixar com cartão no Controle de Cartões, aplique a atualização "Controle de Cartões - baixas do Contas a Receber" no Atualizador do Banco.');
    $payment = card_control_method_enabled($pdo, $companyId, (int)($split['payment_method_id'] ?? 0));
    if (!$payment) throw new RuntimeException('Forma de pagamento sem Controle de Cartões.');
    $plans = card_control_payment_plans($pdo, $companyId, (int)$payment['id'], true);
    if (!$plans) throw new RuntimeException('A forma "' . (string)$payment['name'] . '" está com Controle de Cartões habilitado, mas não tem as regras de parcelas (dias/taxa). Cadastre em Formas de pagamento.');
    $count = (int)($split['card_installments'] ?? 0);
    if ($count <= 0 && count($plans) === 1) $count = (int)$plans[0]['installments'];
    $plan = null;
    foreach ($plans as $p) if ((int)$p['installments'] === $count) $plan = $p;
    if (!$plan) throw new RuntimeException('Selecione em quantas parcelas foi o cartão (' . (string)$payment['name'] . ').');

    $count = max(1, (int)$plan['installments']);
    $feePercent = max(0.0, min(100.0, (float)$plan['fee_percent']));
    $firstDays = max(0, (int)$plan['first_receipt_days']);
    $interval = max(0, (int)$plan['interval_days']);
    if ($count > 1 && $interval <= 0) $interval = 30;
    $saleDate = finance_date_or_null($saleDate) ?: date('Y-m-d');
    $baseDate = new DateTimeImmutable($saleDate);
    $rows = []; $feeTotal = 0; $netTotal = 0;
    foreach (card_control_split_cents($amount, $count) as $idx => $grossCent) {
      $feeCent = (int)round($grossCent * ($feePercent / 100));
      $netCent = max(0, $grossCent - $feeCent);
      $feeTotal += $feeCent; $netTotal += $netCent;
      $rows[] = ['no' => $idx + 1, 'due' => $baseDate->modify('+' . ($firstDays + $idx * $interval) . ' days')->format('Y-m-d'), 'gross' => $grossCent / 100, 'fee' => $feeCent / 100, 'net' => $netCent / 100];
    }
    // Plano de contas/centro de custo do próprio título: o recebimento do cartão fica igual a uma baixa comum, só que na data em que a operadora paga.
    $chartId = (int)($title['chart_account_id'] ?? 0) ?: ((int)($payment['default_chart_account_id'] ?? 0) ?: null);
    $costId = (int)($title['cost_center_id'] ?? 0) ?: ((int)($payment['default_cost_center_id'] ?? 0) ?: null);
    $bankId = (int)($split['bank_account_id'] ?? 0) ?: ((int)($payment['default_bank_account_id'] ?? 0) ?: null);
    $pdo->prepare("INSERT INTO finance_card_sales
      (company_id,origin_type,order_id,order_payment_id,receivable_id,settlement_id,person_id,payment_method_id,sale_date,gross_amount,installments,fee_percent,fee_amount,net_amount,acquirer_name,acquirer_cnpj,card_brand,tef_integration,bank_account_id,chart_account_id,cost_center_id,fee_chart_account_id,status,created_at,updated_at)
      VALUES (?,'RECEBER',0,NULL,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'ABERTO',NOW(),NOW())")
      ->execute([
        $companyId, (int)($title['id'] ?? 0) ?: null, $settlementId, (int)($title['person_id'] ?? 0) ?: null, (int)$payment['id'], $saleDate, $amount, $count, $feePercent, $feeTotal / 100, $netTotal / 100,
        trim((string)($payment['card_acquirer_name'] ?? '')) ?: null, trim((string)($payment['nfce_card_cnpj'] ?? '')) ?: null, trim((string)($payment['nfce_card_brand'] ?? '')) ?: null,
        trim((string)($payment['nfce_card_integration'] ?? '')) ?: null, $bankId, $chartId, $costId, (int)($payment['card_fee_chart_account_id'] ?? 0) ?: null,
      ]);
    $saleId = (int)$pdo->lastInsertId();
    $st = $pdo->prepare("INSERT INTO finance_card_installments (company_id,card_sale_id,installment_no,total_installments,due_date,gross_amount,fee_percent,fee_amount,net_amount,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,'ABERTO',NOW(),NOW())");
    foreach ($rows as $r) $st->execute([$companyId, $saleId, $r['no'], $count, $r['due'], $r['gross'], $feePercent, $r['fee'], $r['net']]);
    return $saleId;
  }
}

if (!function_exists('card_control_reverse_settlement')) {
  /**
   * Estorno de uma baixa do Contas a Receber: cancela as vendas no cartão dela (só se nenhuma
   * parcela foi recebida da operadora). Devolve o valor bruto cancelado.
   */
  function card_control_reverse_settlement(PDO $pdo, int $companyId, int $settlementId): float {
    if (!card_control_receivable_ready($pdo)) return 0.0;
    $sales = finance_query_all($pdo, "SELECT id,gross_amount FROM finance_card_sales WHERE company_id=? AND settlement_id=? AND status<>'CANCELADO' FOR UPDATE", [$companyId, $settlementId]);
    if (!$sales) return 0.0;
    $ids = array_map(static fn($r) => (int)$r['id'], $sales);
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $recv = (int)(finance_query_one($pdo, "SELECT COUNT(*) qtd FROM finance_card_installments WHERE company_id=? AND card_sale_id IN ({$ph}) AND status='RECEBIDO'", array_merge([$companyId], $ids))['qtd'] ?? 0);
    if ($recv > 0) throw new RuntimeException('Esta baixa foi paga com cartão e já tem parcela recebida da operadora no Controle de Cartões. Estorne o recebimento do cartão antes de estornar a baixa.');
    $pdo->prepare("UPDATE finance_card_installments SET status='CANCELADO',updated_at=NOW() WHERE company_id=? AND card_sale_id IN ({$ph}) AND status='ABERTO'")->execute(array_merge([$companyId], $ids));
    $pdo->prepare("UPDATE finance_card_sales SET status='CANCELADO',updated_at=NOW() WHERE company_id=? AND id IN ({$ph})")->execute(array_merge([$companyId], $ids));
    return round(array_sum(array_map(static fn($r) => (float)$r['gross_amount'], $sales)), 2);
  }
}

if (!function_exists('card_control_settle_installments')) {
  function card_control_settle_installments(PDO $pdo, int $companyId, array $installmentIds, int $bankId, string $paymentDate, int $userId = 0, string $notes = ''): array {
    if (!card_control_schema_ready($pdo)) throw new RuntimeException('Controle de Cartões não disponível.');
    $installmentIds = array_values(array_unique(array_filter(array_map('intval',$installmentIds),static fn($id)=>$id>0)));
    if (!$installmentIds) throw new RuntimeException('Selecione ao menos uma parcela para baixar.');
    $paymentDate = finance_date_or_null($paymentDate) ?: date('Y-m-d');
    $bank = finance_query_one($pdo,'SELECT id,name FROM finance_bank_accounts WHERE company_id=? AND id=? AND active=1 LIMIT 1',[$companyId,$bankId]);
    if (!$bank) throw new RuntimeException('Selecione um Caixa/Banco ativo desta empresa.');

    $pdo->beginTransaction();
    try {
      $settled=[];$totalGross=0.0;$totalFee=0.0;$totalNet=0.0;
      foreach ($installmentIds as $installmentId) {
        $recvCols = finance_column_exists($pdo,'finance_card_sales','receivable_id') ? 'cs.receivable_id,' : '';
        $row = finance_query_one($pdo,"SELECT ci.*,{$recvCols}cs.order_id,cs.person_id,cs.payment_method_id,cs.acquirer_name,cs.card_brand,cs.chart_account_id,cs.cost_center_id,cs.fee_chart_account_id,pm.name payment_name
          FROM finance_card_installments ci
          JOIN finance_card_sales cs ON cs.company_id=ci.company_id AND cs.id=ci.card_sale_id
          LEFT JOIN finance_payment_methods pm ON pm.company_id=cs.company_id AND pm.id=cs.payment_method_id
          WHERE ci.company_id=? AND ci.id=? FOR UPDATE",[$companyId,$installmentId]);
        if (!$row) throw new RuntimeException('Parcela de cartão não encontrada.');
        if ((string)$row['status'] !== 'ABERTO') continue;
        $gross=round((float)$row['gross_amount'],2);$fee=round((float)$row['fee_amount'],2);$net=round((float)$row['net_amount'],2);
        $chartId=(int)($row['chart_account_id']??0);$costId=(int)($row['cost_center_id']??0);$feeChart=(int)($row['fee_chart_account_id']??0);
        if ($chartId<=0) throw new RuntimeException('A forma de pagamento do cartão não possui Plano de Contas padrão para registrar o recebimento.');
        $orderId=(int)$row['order_id'];$parc=(int)$row['installment_no'];$tot=(int)$row['total_installments'];
        $recvId=(int)($row['receivable_id']??0);
        $originLabel=($orderId<=0 && $recvId>0)?('Conta a receber #'.$recvId):('Pedido #'.$orderId);
        $descBase='Recebimento cartão '.$originLabel.' '.$parc.'/'.$tot.' - '.trim((string)($row['payment_name']??'Cartão'));
        $creditAmount=($fee>0 && $feeChart>0)?$gross:$net;
        $pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,?,'CREDITO',?,?,?,?,'CARTAO',?,?,?,?,?)")
          ->execute([$companyId,$paymentDate,(int)($row['person_id']??0)?:null,$chartId,$costId?:null,finance_movement_bank($pdo,$companyId,$bankId),$installmentId,$descBase,(string)($orderId>0?$orderId:$recvId),$creditAmount,$notes?:null]);
        $creditMovementId=(int)$pdo->lastInsertId();
        $feeMovementId=null;
        if($fee>0 && $feeChart>0){
          $pdo->prepare("INSERT INTO finance_movements (company_id,movement_date,type,person_id,chart_account_id,cost_center_id,bank_account_id,origin_type,origin_id,description,document_number,amount,notes) VALUES (?,?,'DEBITO',?,?,?,?,'CARTAO_TAXA',?,?,?,?,?)")
            ->execute([$companyId,$paymentDate,(int)($row['person_id']??0)?:null,$feeChart,$costId?:null,finance_movement_bank($pdo,$companyId,$bankId),$installmentId,'Taxa de cartão '.$originLabel.' '.$parc.'/'.$tot,(string)($orderId>0?$orderId:$recvId),$fee,$notes?:null]);
          $feeMovementId=(int)$pdo->lastInsertId();
        }
        $pdo->prepare("UPDATE finance_card_installments SET status='RECEBIDO',payment_date=?,received_at=NOW(),bank_account_id=?,credit_movement_id=?,fee_movement_id=?,settled_by=?,settlement_notes=?,updated_at=NOW() WHERE company_id=? AND id=?")
          ->execute([$paymentDate,$bankId,$creditMovementId,$feeMovementId,$userId?:null,$notes?:null,$companyId,$installmentId]);
        $saleId=(int)$row['card_sale_id'];
        $counts=finance_query_one($pdo,"SELECT COUNT(*) total,SUM(status='RECEBIDO') received FROM finance_card_installments WHERE company_id=? AND card_sale_id=?",[$companyId,$saleId]);
        $status=((int)($counts['received']??0)>0?(((int)$counts['received'] >= (int)$counts['total'])?'RECEBIDO':'PARCIAL'):'ABERTO');
        $pdo->prepare('UPDATE finance_card_sales SET status=?,updated_at=NOW() WHERE company_id=? AND id=?')->execute([$status,$companyId,$saleId]);
        $settled[]=$installmentId;$totalGross+=$gross;$totalFee+=$fee;$totalNet+=$net;
      }
      $pdo->commit();
      return ['count'=>count($settled),'gross'=>round($totalGross,2),'fee'=>round($totalFee,2),'net'=>round($totalNet,2),'ids'=>$settled];
    } catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();throw $e;}
  }
}

if (!function_exists('card_control_cancel_open_order')) {
  function card_control_cancel_open_order(PDO $pdo, int $companyId, int $orderId): void {
    if ($orderId <= 0 || !card_control_schema_ready($pdo)) return; // order_id 0 = venda vinda do Contas a Receber
    $received=(int)(finance_query_one($pdo,"SELECT COUNT(*) qtd FROM finance_card_installments ci JOIN finance_card_sales cs ON cs.company_id=ci.company_id AND cs.id=ci.card_sale_id WHERE cs.company_id=? AND cs.order_id=? AND ci.status='RECEBIDO'",[$companyId,$orderId])['qtd']??0);
    if($received>0) throw new RuntimeException('O pedido possui parcela(s) de cartão já recebida(s). Estorne/ajuste o recebimento no Controle de Cartões antes de cancelar o pedido.');
    $pdo->prepare("UPDATE finance_card_installments ci JOIN finance_card_sales cs ON cs.company_id=ci.company_id AND cs.id=ci.card_sale_id SET ci.status='CANCELADO',ci.updated_at=NOW() WHERE cs.company_id=? AND cs.order_id=? AND ci.status='ABERTO'")->execute([$companyId,$orderId]);
    $pdo->prepare("UPDATE finance_card_sales SET status='CANCELADO',updated_at=NOW() WHERE company_id=? AND order_id=? AND status IN ('ABERTO','PARCIAL')")->execute([$companyId,$orderId]);
  }
}
