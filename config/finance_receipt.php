<?php
declare(strict_types=1);

require_once __DIR__ . '/load_config.php';

if (!function_exists('finance_receipt_h')) {
  function finance_receipt_h($value): string { return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('finance_receipt_money')) {
  function finance_receipt_money($value): string { return number_format((float)$value, 2, ',', '.'); }
}


if (!function_exists('finance_receipt_column_exists')) {
  function finance_receipt_column_exists(PDO $pdo, string $table, string $column): bool {
    static $cache = [];
    $key = strtolower($table . '.' . $column);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=? AND COLUMN_NAME=?');
      $st->execute([$table, $column]);
      return $cache[$key] = ((int)$st->fetchColumn() > 0);
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('finance_receipt_batch_ids')) {
  function finance_receipt_batch_ids(int $companyId, string $token): array {
    $token = preg_replace('/[^a-f0-9]/i', '', $token) ?: '';
    $batch = $_SESSION['finance_receipt_batches'][$token] ?? null;
    if (!$batch || !is_array($batch) || (int)($batch['company_id'] ?? 0) !== $companyId) return [];
    if (time() - (int)($batch['created_at'] ?? 0) > 7200) {
      unset($_SESSION['finance_receipt_batches'][$token]);
      return [];
    }
    return array_values(array_unique(array_filter(array_map('intval', (array)($batch['settlement_ids'] ?? [])), static fn($id)=>$id>0)));
  }
}

if (!function_exists('finance_receipt_load')) {
  function finance_receipt_load(PDO $pdo, int $companyId, array $settlementIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $settlementIds), static fn($id)=>$id>0)));
    if (!$ids) return [];
    $ph = implode(',', array_fill(0, count($ids), '?'));
    $params = array_merge([$companyId], $ids);
    // O recibo deve continuar funcionando também em bases antigas que ainda não
    // possuem todos os campos complementares de Pessoas. Campos opcionais são
    // projetados como NULL em vez de derrubar a página com SQLSTATE/HTTP 500.
    $statusWhere = finance_receipt_column_exists($pdo, 'finance_settlements', 'status') ? " AND COALESCE(s.status,'ATIVO')='ATIVO'" : '';
    $personWhatsapp = finance_receipt_column_exists($pdo, 'finance_people', 'whatsapp') ? 'p.whatsapp' : 'NULL';
    $personAddress = finance_receipt_column_exists($pdo, 'finance_people', 'address') ? 'p.address' : 'NULL';
    $personNumber = finance_receipt_column_exists($pdo, 'finance_people', 'number') ? 'p.number' : 'NULL';
    $personDistrict = finance_receipt_column_exists($pdo, 'finance_people', 'district') ? 'p.district' : 'NULL';
    $personCity = finance_receipt_column_exists($pdo, 'finance_people', 'city') ? 'p.city' : 'NULL';
    $personState = finance_receipt_column_exists($pdo, 'finance_people', 'state') ? 'p.state' : 'NULL';
    $st = $pdo->prepare("SELECT
      s.id AS settlement_id, s.settlement_date, s.payment_method, s.amount AS principal_amount,
      s.fine_amount, s.interest_amount, s.discount_amount, s.notes,
      r.id AS title_id, r.person_id, r.description, r.document_number, r.installment, r.issue_date, r.due_date, r.expected_payment_method,
      p.name AS person_name, p.document AS person_document, p.phone AS person_phone, {$personWhatsapp} AS person_whatsapp,
      {$personAddress} AS person_address, {$personNumber} AS person_number, {$personDistrict} AS person_district, {$personCity} AS person_city, {$personState} AS person_state
      FROM finance_settlements s
      INNER JOIN finance_receivables r ON r.company_id=s.company_id AND r.id=s.origin_id
      LEFT JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id
      WHERE s.company_id=? AND s.origin_type='RECEBER'{$statusWhere} AND s.id IN ({$ph})
      ORDER BY s.id");
    $st->execute($params);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) return [];

    $paymentMap = [];
    try {
      $stPay = $pdo->prepare("SELECT sp.settlement_id, sp.payment_method, sp.amount, b.name AS bank_name
        FROM finance_settlement_payments sp
        LEFT JOIN finance_bank_accounts b ON b.company_id=sp.company_id AND b.id=sp.bank_account_id
        WHERE sp.company_id=? AND sp.settlement_id IN ({$ph}) ORDER BY sp.settlement_id, sp.id");
      $stPay->execute($params);
      foreach ($stPay->fetchAll(PDO::FETCH_ASSOC) ?: [] as $pr) $paymentMap[(int)$pr['settlement_id']][] = $pr;
    } catch (Throwable $ignored) {}

    $principal = $fine = $interest = $discount = $total = 0.0;
    $personIds = [];
    $paymentsSummary = [];
    foreach ($rows as &$row) {
      $row['payments'] = $paymentMap[(int)$row['settlement_id']] ?? [];
      $row['total_received'] = round((float)$row['principal_amount'] + (float)$row['fine_amount'] + (float)$row['interest_amount'] - (float)$row['discount_amount'], 2);
      $principal += (float)$row['principal_amount'];
      $fine += (float)$row['fine_amount'];
      $interest += (float)$row['interest_amount'];
      $discount += (float)$row['discount_amount'];
      $total += (float)$row['total_received'];
      $pid = (int)($row['person_id'] ?? 0); if ($pid > 0) $personIds[$pid] = true;
      if ($row['payments']) {
        foreach ($row['payments'] as $pay) {
          $key = trim((string)($pay['payment_method'] ?? '')) ?: 'Não informado';
          $paymentsSummary[$key] = ($paymentsSummary[$key] ?? 0.0) + (float)($pay['amount'] ?? 0);
        }
      } else {
        $key = trim((string)($row['payment_method'] ?? '')) ?: 'Não informado';
        $paymentsSummary[$key] = ($paymentsSummary[$key] ?? 0.0) + (float)$row['total_received'];
      }
    }
    unset($row);

    $app = []; $business = [];
    try { $app = load_app_config($companyId); } catch (Throwable $ignored) {}
    try { $business = load_business_config($companyId); } catch (Throwable $ignored) {}
    $companyName = trim((string)($app['site_name'] ?? ''));
    if ($companyName === '') $companyName = trim((string)($business['brand']['catalog_title'] ?? ''));
    if ($companyName === '') $companyName = 'Nexa Próspero';

    $samePerson = count($personIds) === 1;
    $first = $rows[0];
    $customer = [
      'name'=>$samePerson ? trim((string)($first['person_name'] ?? '')) : 'Diversos clientes',
      'document'=>$samePerson ? trim((string)($first['person_document'] ?? '')) : '',
      'phone'=>$samePerson ? trim((string)($first['person_whatsapp'] ?? $first['person_phone'] ?? '')) : '',
      'address'=>$samePerson ? trim(implode(', ', array_filter([
        trim((string)($first['person_address'] ?? '')),
        trim((string)($first['person_number'] ?? '')),
        trim((string)($first['person_district'] ?? '')),
        trim((string)($first['person_city'] ?? '')) . (trim((string)($first['person_state'] ?? '')) !== '' ? '/' . trim((string)$first['person_state']) : ''),
      ]))) : '',
    ];

    return [
      'rows'=>$rows,
      'customer'=>$customer,
      'company_name'=>$companyName,
      'receipt_number'=>'REC-' . (int)$rows[0]['settlement_id'],
      'settlement_date'=>(string)($rows[0]['settlement_date'] ?? date('Y-m-d')),
      'principal'=>round($principal,2),
      'fine'=>round($fine,2),
      'interest'=>round($interest,2),
      'discount'=>round($discount,2),
      'total'=>round($total,2),
      'payments'=>$paymentsSummary,
    ];
  }
}

if (!function_exists('finance_receipt_pdf_text')) {
  function finance_receipt_pdf_text(string $value): string {
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $out !== false ? $out : preg_replace('/[^\x20-\x7E]/', '?', $value);
  }
}

if (!function_exists('finance_receipt_pdf_build')) {
  function finance_receipt_pdf_build(array $data, string $layout = 'a4'): array {
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $fpdfFile = __DIR__ . '/../vendor/nfephp-org/sped-da/src/Legacy/FPDF/Fpdf.php';
      if (is_file($fpdfFile)) require_once $fpdfFile;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $autoload = __DIR__ . '/../vendor/autoload.php';
      if (is_file($autoload)) require_once $autoload;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) throw new RuntimeException('Biblioteca de PDF não disponível.');
    $layout = strtolower($layout) === '72' ? '72' : 'a4';
    $rows = (array)($data['rows'] ?? []);
    $company = finance_receipt_pdf_text((string)($data['company_name'] ?? ''));
    $customer = finance_receipt_pdf_text((string)($data['customer']['name'] ?? ''));
    $doc = finance_receipt_pdf_text((string)($data['customer']['document'] ?? ''));
    $receiptNo = finance_receipt_pdf_text((string)($data['receipt_number'] ?? ''));
    $date = !empty($data['settlement_date']) ? date('d/m/Y', strtotime((string)$data['settlement_date'])) : date('d/m/Y');

    $writeCopy = static function($pdf, float $x, float $y, float $w, array $chunk, string $partLabel='') use ($data,$company,$customer,$doc,$receiptNo,$date) {
      $tx = static fn(string $v): string => finance_receipt_pdf_text($v);
      $pdf->SetXY($x,$y); $pdf->SetFont('Arial','B',10); $pdf->Cell($w,5,$company,0,1,'C');
      $pdf->SetX($x); $pdf->SetFont('Arial','B',9); $pdf->Cell($w,5,$tx('RECIBO DE BAIXA ' . $receiptNo . ($partLabel!==''?' - '.$partLabel:'')),0,1,'C');
      $pdf->SetX($x); $pdf->SetFont('Arial','',7); $pdf->Cell($w,4,$tx('Data: '.$date),0,1,'L');
      $pdf->SetX($x); $pdf->Cell($w,4,$tx('Cliente: '.$customer),0,1,'L');
      if ($doc !== '') { $pdf->SetX($x); $pdf->Cell($w,4,$tx('CPF/CNPJ: '.$doc),0,1,'L'); }
      $pdf->Ln(1);
      foreach ($chunk as $row) {
        $line1 = '#'.(int)$row['title_id'].'  Doc./Pedido '.trim((string)($row['document_number'] ?? '-')).'  Parc. '.trim((string)($row['installment'] ?? '-'));
        $line2 = trim((string)($row['description'] ?? ''));
        $line3 = 'Principal R$ '.finance_receipt_money($row['principal_amount'] ?? 0)
          .'  Multa R$ '.finance_receipt_money($row['fine_amount'] ?? 0)
          .'  Juros R$ '.finance_receipt_money($row['interest_amount'] ?? 0)
          .'  Desc. R$ '.finance_receipt_money($row['discount_amount'] ?? 0)
          .'  Total R$ '.finance_receipt_money($row['total_received'] ?? 0);
        $pdf->SetX($x); $pdf->SetFont('Arial','B',7); $pdf->MultiCell($w,3.4,$tx($line1),0,'L');
        if ($line2 !== '') { $pdf->SetX($x); $pdf->SetFont('Arial','',6.5); $pdf->MultiCell($w,3.2,$tx($line2),0,'L'); }
        $pdf->SetX($x); $pdf->SetFont('Arial','',6.5); $pdf->MultiCell($w,3.2,$tx($line3),0,'L');
        $pdf->SetX($x); $pdf->Cell($w,1,'',0,1);
      }
      $pdf->SetX($x); $pdf->SetFont('Arial','B',7.5);
      $pdf->Cell($w,4,$tx('Principal: R$ '.finance_receipt_money($data['principal'] ?? 0).'   Multa: R$ '.finance_receipt_money($data['fine'] ?? 0)),0,1,'L');
      $pdf->SetX($x); $pdf->Cell($w,4,$tx('Juros: R$ '.finance_receipt_money($data['interest'] ?? 0).'   Desconto: R$ '.finance_receipt_money($data['discount'] ?? 0)),0,1,'L');
      $pdf->SetX($x); $pdf->SetFont('Arial','B',10); $pdf->Cell($w,6,$tx('TOTAL RECEBIDO: R$ '.finance_receipt_money($data['total'] ?? 0)),1,1,'C');
      $pdf->Ln(1); $pdf->SetX($x); $pdf->SetFont('Arial','B',7); $pdf->Cell($w,4,$tx('Formas de pagamento:'),0,1,'L');
      foreach ((array)($data['payments'] ?? []) as $name=>$amount) {
        $pdf->SetX($x); $pdf->SetFont('Arial','',6.5); $pdf->Cell($w,3.6,$tx($name.' - R$ '.finance_receipt_money($amount)),0,1,'L');
      }
      $pdf->Ln(4); $pdf->SetX($x+4); $pdf->Cell($w-8,0,'','T',1,'C'); $pdf->SetX($x); $pdf->SetFont('Arial','',6.5); $pdf->Cell($w,4,$tx('Assinatura / confirmação do recebimento'),0,1,'C');
    };

    if ($layout === '72') {
      $height = max(130, min(600, 82 + count($rows) * 18 + count((array)($data['payments'] ?? []))*5));
      $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P','mm',[72,$height]);
      $pdf->SetMargins(4,4,4); $pdf->SetAutoPageBreak(true,4); $pdf->AddPage();
      $writeCopy($pdf,4,4,64,$rows);
      $content = $pdf->Output('', 'S');
      return ['filename'=>$receiptNo.'_72mm.pdf','mimetype'=>'application/pdf','content'=>$content];
    }

    $chunks = array_chunk($rows, 8);
    if (!$chunks) $chunks = [[]];
    $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P','mm','A4');
    $pdf->SetMargins(5,5,5); $pdf->SetAutoPageBreak(false);
    foreach ($chunks as $idx=>$chunk) {
      $pdf->AddPage(); $pdf->SetDrawColor(180,180,180); $pdf->Line(105,5,105,292);
      $label = count($chunks)>1 ? 'pág. '.($idx+1).'/'.count($chunks) : '';
      $writeCopy($pdf,6,7,94,$chunk,$label);
      $writeCopy($pdf,110,7,94,$chunk,$label);
    }
    $content = $pdf->Output('', 'S');
    return ['filename'=>$receiptNo.'_A4_2vias.pdf','mimetype'=>'application/pdf','content'=>$content];
  }
}
