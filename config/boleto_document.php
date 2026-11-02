<?php
declare(strict_types=1);

require_once __DIR__ . '/billing_module.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

use NFePHP\DA\Legacy\Pdf as BoletoPdf;
use Com\Tecnick\Barcode\Barcode as BarcodeFactory;

function boleto_document_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function boleto_document_digits($value): string {
  return preg_replace('/\D+/', '', (string)$value) ?? '';
}

function boleto_document_money($value): string {
  return 'R$ ' . number_format((float)$value, 2, ',', '.');
}

function boleto_document_date($value): string {
  $value = trim((string)$value);
  if ($value === '') return '';
  $ts = strtotime($value);
  return $ts ? date('d/m/Y', $ts) : $value;
}

function boleto_document_pdf_text(string $value): string {
  $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
  return $out !== false ? $out : preg_replace('/[^\x20-\x7E]/', '?', $value);
}

function boleto_document_company(PDO $pdo, int $companyId): array {
  $company = [
    'name' => 'Nexa Próspero',
    'trade_name' => '',
    'cnpj' => '',
    'address' => '',
    'phone' => '',
  ];
  try {
    if (billing_table_exists($pdo, 'fiscal_company_config')) {
      $st = $pdo->prepare('SELECT company_name,company_trade_name,company_cnpj,company_address,company_number,company_complement,company_district,company_city,uf,company_zip,company_phone FROM fiscal_company_config WHERE company_id=? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      if ($row) {
        $company['name'] = trim((string)($row['company_name'] ?? '')) ?: $company['name'];
        $company['trade_name'] = trim((string)($row['company_trade_name'] ?? ''));
        $company['cnpj'] = trim((string)($row['company_cnpj'] ?? ''));
        $company['phone'] = trim((string)($row['company_phone'] ?? ''));
        $cityUf = trim((string)($row['company_city'] ?? ''));
        if (trim((string)($row['uf'] ?? '')) !== '') $cityUf .= ($cityUf !== '' ? '/' : '') . trim((string)$row['uf']);
        $company['address'] = implode(', ', array_filter([
          trim((string)($row['company_address'] ?? '')),
          trim((string)($row['company_number'] ?? '')),
          trim((string)($row['company_complement'] ?? '')),
          trim((string)($row['company_district'] ?? '')),
          $cityUf,
          trim((string)($row['company_zip'] ?? '')),
        ]));
      }
    }
  } catch (Throwable $e) {}

  if ($company['name'] === 'Nexa Próspero' && function_exists('load_app_config')) {
    try {
      $app = load_app_config($companyId);
      $name = trim((string)($app['site_name'] ?? ''));
      if ($name !== '') $company['name'] = $name;
    } catch (Throwable $e) {}
  }
  return $company;
}

function boleto_document_load(PDO $pdo, int $companyId, int $chargeId = 0, int $receivableId = 0): array {
  $crossBillingRead = (function_exists('finance_multi_company_cross_context') && finance_multi_company_cross_context($pdo))
    || (function_exists('multi_company_scope_is_cross_company') && multi_company_scope_is_cross_company($pdo, 'finance_billing_read'));
  if (!$crossBillingRead) {
    billing_ensure_schema($pdo);
  } elseif (!billing_table_exists($pdo, 'finance_boleto_charges')) {
    throw new RuntimeException('Estrutura de Cobranças/Boletos não está disponível na empresa selecionada.');
  }
  if ($chargeId <= 0 && $receivableId <= 0) throw new RuntimeException('Informe a cobrança ou a Conta a Receber.');

  $where = $chargeId > 0 ? 'bc.id=?' : 'bc.receivable_id=?';
  $lookup = $chargeId > 0 ? $chargeId : $receivableId;
  $sql = "SELECT bc.*, fr.description AS receivable_description, fr.document_number, fr.installment,
                 fr.issue_date AS receivable_issue_date, fr.open_amount AS receivable_open_amount,
                 fp.name AS payer_name, fp.fantasy_name AS payer_fantasy_name, fp.document AS payer_document,
                 fp.email AS payer_email, fp.phone AS payer_phone, fp.whatsapp AS payer_whatsapp,
                 fp.address AS payer_address, fp.number AS payer_number, fp.complement AS payer_complement,
                 fp.district AS payer_district, fp.city AS payer_city, fp.state AS payer_state, fp.zip_code AS payer_zip_code,
                 ba.name AS bank_account_name, ba.bank_name AS bank_official_name, ba.agency AS bank_agency, ba.account_number AS bank_account_number,
                 bcfg.bank_code, bcfg.convenio, bcfg.carteira, bcfg.instructions
          FROM finance_boleto_charges bc
          LEFT JOIN finance_receivables fr ON fr.company_id=bc.company_id AND fr.id=bc.receivable_id
          LEFT JOIN finance_people fp ON fp.company_id=bc.company_id AND fp.id=fr.person_id
          LEFT JOIN finance_bank_accounts ba ON ba.company_id=bc.company_id AND ba.id=bc.bank_account_id
          LEFT JOIN finance_bank_billing_configs bcfg ON bcfg.company_id=bc.company_id AND bcfg.bank_account_id=bc.bank_account_id
          WHERE bc.company_id=? AND {$where}
          ORDER BY bc.id DESC LIMIT 1";
  $st = $pdo->prepare($sql);
  $st->execute([$companyId, $lookup]);
  $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
  if (!$row) throw new RuntimeException('Nenhum boleto está vinculado a esta Conta a Receber.');

  $row['company'] = boleto_document_company($pdo, $companyId);
  return $row;
}

function boleto_document_ready(array $data): bool {
  return boleto_document_digits($data['barcode'] ?? '') !== '' && trim((string)($data['linha_digitavel'] ?? '')) !== '';
}

function boleto_document_bank_label(array $data): string {
  $provider = strtoupper(trim((string)($data['provider'] ?? '')));
  if ($provider === 'ITAU') return 'ITAÚ';
  $name = trim((string)($data['bank_official_name'] ?? $data['bank_account_name'] ?? ''));
  return $name !== '' ? mb_strtoupper($name, 'UTF-8') : 'BANCO';
}

function boleto_document_company_name(array $data): string {
  return trim((string)($data['company']['name'] ?? '')) ?: 'Beneficiário';
}

function boleto_document_payer_address(array $data): string {
  $city = trim((string)($data['payer_city'] ?? ''));
  $state = trim((string)($data['payer_state'] ?? ''));
  if ($state !== '') $city .= ($city !== '' ? '/' : '') . $state;
  return implode(', ', array_filter([
    trim((string)($data['payer_address'] ?? '')),
    trim((string)($data['payer_number'] ?? '')),
    trim((string)($data['payer_complement'] ?? '')),
    trim((string)($data['payer_district'] ?? '')),
    $city,
    trim((string)($data['payer_zip_code'] ?? '')),
  ]));
}

function boleto_document_barcode_html(string $barcode): string {
  $digits = boleto_document_digits($barcode);
  if ($digits === '' || strlen($digits) % 2 !== 0) return '';
  try {
    $factory = new BarcodeFactory();
    return $factory->getBarcodeObj('I25', $digits, -2, -28, 'black', [0,0,0,0])->getHtmlDiv();
  } catch (Throwable $e) { return ''; }
}

function boleto_document_qr_html(array $data): string {
  $base64 = trim((string)($data['pix_qr_base64'] ?? ''));
  if ($base64 !== '') {
    $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $base64) ?? $base64;
    return '<img class="boleto-qr" alt="QR Code Pix" src="data:image/png;base64,' . boleto_document_h($base64) . '">';
  }
  $emv = trim((string)($data['pix_emv'] ?? ''));
  if ($emv === '') return '';
  try {
    $factory = new BarcodeFactory();
    $svg = $factory->getBarcodeObj('QRCODE,H', $emv, -4, -4, 'black', [-2,-2,-2,-2])->getSvgCode();
    return '<div class="boleto-qr-svg">' . $svg . '</div>';
  } catch (Throwable $e) { return ''; }
}

function boleto_document_pdf_draw_barcode(BoletoPdf $pdf, string $barcode, float $x, float $y, float $w, float $h): void {
  $digits = boleto_document_digits($barcode);
  if ($digits === '' || strlen($digits) % 2 !== 0) return;
  try {
    $factory = new BarcodeFactory();
    $obj = $factory->getBarcodeObj('I25', $digits, -2, -28, 'black', [0,0,0,0]);
    $arr = $obj->getArray();
    $cols = max(1, (int)($arr['full_width'] ?? $arr['width'] ?? $arr['ncols'] ?? 1));
    $scale = $w / $cols;
    $pdf->SetFillColor(0,0,0);
    foreach ($obj->getBarsArray() as $bar) {
      $bx1 = (float)($bar[0] ?? 0);
      $bx2 = (float)($bar[2] ?? $bx1);
      $bw = max($scale, (($bx2 - $bx1) + 1) * $scale);
      $pdf->Rect($x + $bx1 * $scale, $y, $bw, $h, 'F');
    }
  } catch (Throwable $e) {}
}

function boleto_document_pdf_field(BoletoPdf $pdf, float $x, float $y, float $w, float $h, string $label, string $value, float $fontSize = 8, string $align = 'L'): void {
  $pdf->Rect($x, $y, $w, $h);
  $pdf->SetXY($x + 1.2, $y + 1.0);
  $pdf->SetFont('Arial', '', 5.8);
  $pdf->Cell($w - 2.4, 2.6, boleto_document_pdf_text($label), 0, 0, 'L');
  $pdf->SetXY($x + 1.2, $y + 4.0);
  $pdf->SetFont('Arial', 'B', $fontSize);
  $pdf->Cell($w - 2.4, max(3.5, $h - 4.6), boleto_document_pdf_text($value), 0, 0, $align);
}


function boleto_document_is_itau(array $data): bool {
  $provider = strtoupper(trim((string)($data['provider'] ?? '')));
  $bankCode = boleto_document_digits($data['bank_code'] ?? '');
  return $provider === 'ITAU' || $bankCode === '341';
}

function boleto_document_is_sicoob(array $data): bool {
  $provider = strtoupper(trim((string)($data['provider'] ?? '')));
  $bankCode = boleto_document_digits($data['bank_code'] ?? '');
  return $provider === 'SICOOB' || $bankCode === '756';
}

function boleto_document_bank_code_with_dv(array $data): string {
  $bankCode = boleto_document_digits($data['bank_code'] ?? '');
  if ($bankCode === '' && boleto_document_is_itau($data)) $bankCode = '341';
  if ($bankCode === '' && boleto_document_is_sicoob($data)) $bankCode = '756';
  $map = [
    '341' => '341-7',
    '756' => '756-0',
  ];
  if (isset($map[$bankCode])) return $map[$bankCode];
  return $bankCode;
}

function boleto_document_format_document($value): string {
  $digits = boleto_document_digits($value);
  if (strlen($digits) === 14) return substr($digits,0,2).'.'.substr($digits,2,3).'.'.substr($digits,5,3).'/'.substr($digits,8,4).'-'.substr($digits,12,2);
  if (strlen($digits) === 11) return substr($digits,0,3).'.'.substr($digits,3,3).'.'.substr($digits,6,3).'-'.substr($digits,9,2);
  return trim((string)$value);
}

function boleto_document_itau_beneficiary_code(array $data): string {
  $agency = trim((string)($data['bank_agency'] ?? ''));
  $account = trim((string)($data['bank_account_number'] ?? ''));
  if ($agency !== '' && $account !== '') return $agency . '/' . $account;
  return trim($agency . $account);
}

function boleto_document_itau_our_number(array $data): string {
  $wallet = boleto_document_digits($data['carteira'] ?? '');
  $raw = trim((string)($data['nosso_numero'] ?? ''));
  if ($raw === '') return '';
  if (str_contains($raw, '/')) return preg_replace('/\s+/', ' ', $raw) ?? $raw;
  $digits = boleto_document_digits($raw);
  if ($wallet !== '' && str_starts_with($digits, $wallet) && strlen($digits) >= strlen($wallet) + 8) {
    $digits = substr($digits, strlen($wallet));
  }
  if (strlen($digits) >= 9) {
    $body = substr($digits, -9, 8);
    $dv = substr($digits, -1);
    return ($wallet !== '' ? $wallet . ' / ' : '') . $body . ' - ' . $dv;
  }
  if (strlen($digits) === 8) return ($wallet !== '' ? $wallet . ' / ' : '') . $digits;
  return ($wallet !== '' ? $wallet . ' / ' : '') . $raw;
}

function boleto_document_pdf_itau_brand(BoletoPdf $pdf, float $x, float $y, float $w, float $h): void {
  // O arquivo foi extraído do próprio modelo de boleto Itaú fornecido para homologação.
  // Mantemos fallback desenhado para não impedir a emissão caso o asset seja removido.
  $asset = dirname(__DIR__) . '/assets/img/itau-logo.png';
  if (is_file($asset)) {
    $imgW = min($w - 1.6, 31.5);
    $imgH = $imgW * (56 / 246);
    if ($imgH > $h - 1.0) {
      $imgH = $h - 1.0;
      $imgW = $imgH * (246 / 56);
    }
    $pdf->Image($asset, $x + 0.8, $y + (($h - $imgH) / 2), $imgW, $imgH, 'PNG');
    return;
  }

  $logo = min(8.2, $h - 1.2);
  $ly = $y + ($h - $logo) / 2;
  $pdf->SetFillColor(15, 43, 122);
  $pdf->Rect($x + 0.8, $ly, $logo, $logo, 'F');
  $pdf->SetTextColor(255,255,255);
  $pdf->SetFont('Arial','B',5.5);
  $pdf->SetXY($x + 0.8, $ly + 1.5);
  $pdf->Cell($logo, 4.6, boleto_document_pdf_text('itaú'), 0, 0, 'C');
  $pdf->SetTextColor(20,20,20);
  $pdf->SetFont('Arial','B',8.5);
  $pdf->SetXY($x + 10.2, $y + 1.5);
  $pdf->Cell(max(1, $w - 10.5), $h - 2.0, boleto_document_pdf_text('Banco Itaú S.A.'), 0, 0, 'L');
}

function boleto_document_pdf_sicoob_brand(BoletoPdf $pdf, float $x, float $y, float $w, float $h): void {
  $green = [0, 111, 81];
  $dark = [0, 84, 61];
  $icon = min($h - 1.6, 7.4);
  $ix = $x + 1.0;
  $iy = $y + ($h - $icon) / 2;
  $pdf->SetFillColor($green[0], $green[1], $green[2]);
  $pdf->Rect($ix, $iy, $icon, $icon, 'F');
  $pdf->SetTextColor(255,255,255);
  $pdf->SetFont('Arial','B',7.2);
  $pdf->SetXY($ix, $iy + 0.4);
  $pdf->Cell($icon, $icon - 0.6, boleto_document_pdf_text('S'), 0, 0, 'C');
  $pdf->SetTextColor($dark[0], $dark[1], $dark[2]);
  $pdf->SetFont('Arial','B',8.8);
  $pdf->SetXY($ix + $icon + 1.2, $y + 1.2);
  $pdf->Cell(max(1, $w - ($icon + 3.0)), $h - 2.0, boleto_document_pdf_text('SICOOB'), 0, 0, 'L');
  $pdf->SetTextColor(20,20,20);
}

function boleto_document_pdf_generic_brand(BoletoPdf $pdf, array $data, float $x, float $y, float $w, float $h): void {
  if (boleto_document_is_sicoob($data)) {
    boleto_document_pdf_sicoob_brand($pdf, $x, $y, $w, $h);
    return;
  }
  $label = boleto_document_bank_label($data);
  $pdf->SetFont('Arial','B',18);
  $pdf->SetXY($x + 1.2, $y + 0.7);
  $pdf->Cell(max(1, $w - 2.4), $h - 1.4, boleto_document_pdf_text($label), 0, 0, 'L');
}


function boleto_document_pdf_itau_label(BoletoPdf $pdf, float $x, float $y, float $w, string $label, float $size = 5.7): void {
  $pdf->SetFont('Arial','', $size);
  $pdf->SetXY($x + 0.8, $y + 0.5);
  $pdf->Cell(max(1, $w - 1.6), 2.5, boleto_document_pdf_text($label), 0, 0, 'L');
}

function boleto_document_pdf_itau_value(BoletoPdf $pdf, float $x, float $y, float $w, string $value, float $size = 8.2, string $align = 'C', string $style = ''): void {
  $pdf->SetFont('Arial', $style, $size);
  $pdf->SetXY($x + 0.8, $y + 3.1);
  $pdf->Cell(max(1, $w - 1.6), 4.3, boleto_document_pdf_text($value), 0, 0, $align);
}

function boleto_document_pdf_itau_qr_temp(array $data): string {
  $bin = '';
  $base64 = trim((string)($data['pix_qr_base64'] ?? ''));
  if ($base64 !== '') {
    $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $base64) ?? $base64;
    $decoded = base64_decode($base64, true);
    if (is_string($decoded)) $bin = $decoded;
  }
  if ($bin === '') {
    $emv = trim((string)($data['pix_emv'] ?? ''));
    if ($emv !== '') {
      try {
        $factory = new BarcodeFactory();
        $bin = $factory->getBarcodeObj('QRCODE,H', $emv, -4, -4, 'black', [-2,-2,-2,-2])->setBackgroundColor('white')->getPngData(false);
      } catch (Throwable $e) { $bin = ''; }
    }
  }
  if (!is_string($bin) || $bin === '') return '';
  $tmp = tempnam(sys_get_temp_dir(), 'nexa_itau_qr_') ?: '';
  if ($tmp === '') return '';
  file_put_contents($tmp, $bin);
  return $tmp;
}

function boleto_document_pdf_itau_draw_qr(BoletoPdf $pdf, array $data, float $x, float $y, float $size): void {
  // Primeiro usa a imagem devolvida pela API Itaú quando disponível.
  $base64 = trim((string)($data['pix_qr_base64'] ?? ''));
  if ($base64 !== '') {
    $base64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $base64) ?? $base64;
    $bin = base64_decode($base64, true);
    if (is_string($bin) && $bin !== '') {
      $tmp = tempnam(sys_get_temp_dir(), 'nexa_itau_qr_') ?: '';
      if ($tmp !== '') {
        try {
          file_put_contents($tmp, $bin);
          $pdf->Image($tmp, $x, $y, $size, $size, 'PNG');
          @unlink($tmp);
          return;
        } catch (Throwable $e) {
          @unlink($tmp);
        }
      }
    }
  }

  // Fallback sem GD/Imagick: desenha diretamente os módulos do QR no PDF.
  $emv = trim((string)($data['pix_emv'] ?? ''));
  if ($emv === '') return;
  try {
    $factory = new BarcodeFactory();
    $obj = $factory->getBarcodeObj('QRCODE,H', $emv, -4, -4, 'black', [-2,-2,-2,-2]);
    $arr = $obj->getArray();
    $fullW = max(1.0, (float)($arr['full_width'] ?? 1));
    $fullH = max(1.0, (float)($arr['full_height'] ?? $fullW));
    $scaleX = $size / $fullW;
    $scaleY = $size / $fullH;
    $pdf->SetFillColor(0,0,0);
    foreach ($obj->getBarsArray('XYXY') as $bar) {
      $bx1 = (float)($bar[0] ?? 0);
      $by1 = (float)($bar[1] ?? 0);
      $bx2 = (float)($bar[2] ?? $bx1);
      $by2 = (float)($bar[3] ?? $by1);
      $bw = max($scaleX, (($bx2 - $bx1) + 1) * $scaleX);
      $bh = max($scaleY, (($by2 - $by1) + 1) * $scaleY);
      $pdf->Rect($x + $bx1 * $scaleX, $y + $by1 * $scaleY, $bw, $bh, 'F');
    }
  } catch (Throwable $e) {
  }
}

function boleto_document_pdf_itau_section(BoletoPdf $pdf, array $data, float $x, float $y, float $w, bool $receipt): float {
  $rightW = 44.0;
  $leftW = $w - $rightW;
  $brandW = 39.0;
  $codeW = 17.0;
  $lineW = $w - $brandW - $codeW;
  $bankCode = trim((string)($data['bank_code'] ?? '341')) ?: '341';
  $line = trim((string)($data['linha_digitavel'] ?? ''));
  $company = (array)($data['company'] ?? []);
  $companyName = boleto_document_company_name($data);
  $companyDoc = boleto_document_digits($company['cnpj'] ?? '');
  $companyAddress = trim((string)($company['address'] ?? ''));
  $payer = trim((string)($data['payer_name'] ?? '')) ?: 'Pagador';
  $payerDoc = boleto_document_format_document($data['payer_document'] ?? '');
  $wallet = trim((string)($data['carteira'] ?? ''));
  $ourNumber = boleto_document_itau_our_number($data);
  $beneficiaryCode = boleto_document_itau_beneficiary_code($data);
  $docNumber = trim((string)($data['document_number'] ?? $data['seu_numero'] ?? ''));
  $issueDate = boleto_document_date($data['issue_date'] ?? $data['receivable_issue_date'] ?? '');
  $processDate = $issueDate;
  $due = boleto_document_date($data['due_date'] ?? '');
  $amount = number_format((float)($data['amount'] ?? 0), 2, ',', '.');
  $instructions = trim((string)($data['instructions'] ?? ''));
  $instructionBase = 'Instruções de responsabilidade do BENEFICIÁRIO. Qualquer dúvida sobre este boleto contate o BENEFICIÁRIO.';

  if ($receipt) {
    $pdf->SetFont('Arial','B',7.8);
    $pdf->SetXY($x, $y - 5.3);
    $pdf->Cell($w, 4, boleto_document_pdf_text('RECIBO DO PAGADOR'), 0, 0, 'C');
  }

  // Cabeçalho do banco
  $h = 9.0;
  $pdf->Rect($x, $y, $w, $h);
  $pdf->Line($x + $brandW, $y, $x + $brandW, $y + $h);
  $pdf->Line($x + $brandW + $codeW, $y, $x + $brandW + $codeW, $y + $h);
  boleto_document_pdf_itau_brand($pdf, $x, $y, $brandW, $h);
  $pdf->SetFont('Arial','B',9.5); $pdf->SetXY($x+$brandW,$y+1.1); $pdf->Cell($codeW,$h-2.0,boleto_document_pdf_text($bankCode.'-7'),0,0,'C');
  $pdf->SetFont('Arial','B',8.8); $pdf->SetXY($x+$brandW+$codeW+1,$y+1.1); $pdf->Cell($lineW-2,$h-2.0,boleto_document_pdf_text($line),0,0,'R');
  $y += $h;

  // Local de pagamento / vencimento
  $h = 10.0; $pdf->Rect($x,$y,$w,$h); $pdf->Line($x+$leftW,$y,$x+$leftW,$y+$h);
  boleto_document_pdf_itau_label($pdf,$x,$y,$leftW,'Local de pagamento:');
  $pdf->SetFont('Arial','',6.8); $pdf->SetXY($x+31,$y+0.3); $pdf->Cell($leftW-32,3.0,boleto_document_pdf_text('Pague pelo aplicativo, internet ou em agências e correspondentes.'),0,0,'L');
  boleto_document_pdf_itau_label($pdf,$x+$leftW,$y,$rightW,'Vencimento');
  boleto_document_pdf_itau_value($pdf,$x+$leftW,$y,$rightW,$due,9.4,'C','B');
  $y += $h;

  // Beneficiário
  $h = 14.0; $pdf->Rect($x,$y,$w,$h); $pdf->Line($x+$leftW,$y,$x+$leftW,$y+$h);
  boleto_document_pdf_itau_label($pdf,$x,$y,$leftW,'Beneficiário');
  $pdf->SetFont('Arial','B',7.8); $pdf->SetXY($x+0.8,$y+3.0); $pdf->Cell(70,4,boleto_document_pdf_text($companyName),0,0,'L');
  $pdf->SetFont('Arial','B',6.7); $pdf->SetXY($x+76,$y+3.0); $pdf->Cell(19,4,boleto_document_pdf_text('CNPJ/CPF:'),0,0,'R');
  $pdf->SetFont('Arial','',6.5); $pdf->SetXY($x+96,$y+3.0); $pdf->Cell($leftW-97,4,boleto_document_pdf_text($companyDoc),0,0,'L');
  $pdf->SetFont('Arial','',6.3); $pdf->SetXY($x+0.8,$y+7.3); $pdf->Cell($leftW-1.6,4,boleto_document_pdf_text($companyAddress),0,0,'L');
  boleto_document_pdf_itau_label($pdf,$x+$leftW,$y,$rightW,'Agência/Código Beneficiário');
  boleto_document_pdf_itau_value($pdf,$x+$leftW,$y,$rightW,$beneficiaryCode,7.8,'C','');
  $y += $h;

  // Dados do documento
  $h = 9.0;
  $cols = [30.0,36.0,24.0,14.0,$leftW-104.0,$rightW];
  $labels = ['Data do documento','Núm. do documento','Espécie Doc.','Aceite','Data Processamento','Nosso Número'];
  $vals = [$issueDate,$docNumber,'DMI','N',$processDate,$ourNumber];
  $cx=$x; $pdf->Rect($x,$y,$w,$h);
  foreach($cols as $i=>$cw){ if($i>0)$pdf->Line($cx,$y,$cx,$y+$h); boleto_document_pdf_itau_label($pdf,$cx,$y,$cw,$labels[$i],5.2); boleto_document_pdf_itau_value($pdf,$cx,$y,$cw,$vals[$i],7.2,'C',''); $cx+=$cw; }
  $y += $h;

  // Uso do banco / carteira / espécie / quantidade / valor / valor documento
  $h = 9.0;
  $cols = [30.0,23.0,17.0,35.0,$leftW-105.0,$rightW];
  $labels = ['Uso do Banco','Carteira','Espécie','Quantidade','Valor','(=) Valor do Documento'];
  $vals = ['', $wallet, 'R$', '', '', $amount];
  $cx=$x; $pdf->Rect($x,$y,$w,$h);
  foreach($cols as $i=>$cw){ if($i>0)$pdf->Line($cx,$y,$cx,$y+$h); boleto_document_pdf_itau_label($pdf,$cx,$y,$cw,$labels[$i],5.2); boleto_document_pdf_itau_value($pdf,$cx,$y,$cw,$vals[$i],$i===5?8.8:7.3,'C',$i===5?'B':''); $cx+=$cw; }
  $y += $h;

  // Instruções e totais
  $h = $receipt ? 31.0 : 52.0;
  $pdf->Rect($x,$y,$w,$h); $pdf->Line($x+$leftW,$y,$x+$leftW,$y+$h);
  $pdf->SetFont('Arial','B',5.4); $pdf->SetXY($x+0.8,$y+0.8); $pdf->MultiCell($leftW-1.6,2.7,boleto_document_pdf_text($instructionBase),0,'L');
  if ($instructions !== '') { $pdf->SetFont('Arial','',5.2); $pdf->SetXY($x+0.8,$y+6.3); $pdf->MultiCell($leftW-2.0,2.5,boleto_document_pdf_text($instructions),0,'L'); }

  $third = $h/3.0;
  $pdf->Line($x+$leftW,$y+$third,$x+$w,$y+$third); $pdf->Line($x+$leftW,$y+2*$third,$x+$w,$y+2*$third);
  boleto_document_pdf_itau_label($pdf,$x+$leftW,$y,$rightW,'(-) Descontos/Abatimento');
  boleto_document_pdf_itau_label($pdf,$x+$leftW,$y+$third,$rightW,'(+) Juros/Multa');
  boleto_document_pdf_itau_label($pdf,$x+$leftW,$y+2*$third,$rightW,'(=) Valor Cobrado');

  if (!$receipt) {
    boleto_document_pdf_itau_draw_qr($pdf, $data, $x+$leftW-38, $y+6.5, 33);
    $note = 'Escolha a forma mais conveniente para realizar seu pagamento: Código de Barras ou QR Code. Basta acessar o aplicativo da sua instituição financeira e utilizar apenas uma das opções.';
    $pdf->SetFont('Arial','',5.1); $pdf->SetXY($x+0.8,$y+$h-9.2); $pdf->MultiCell($leftW-2.0,2.5,boleto_document_pdf_text($note),0,'L');
  }
  $y += $h;

  // Pagador / beneficiário final
  $h = 9.0; $pdf->Rect($x,$y,$w,$h);
  $split = 82.0;
  $pdf->SetFont('Arial','B',6.1); $pdf->SetXY($x+0.8,$y+0.8); $pdf->Cell(15,3,boleto_document_pdf_text('Pagador:'),0,0,'L');
  $pdf->SetFont('Arial','',6.5); $pdf->SetXY($x+16,$y+0.8); $pdf->Cell($split-16,3,boleto_document_pdf_text($payer),0,0,'L');
  $pdf->SetFont('Arial','B',6.1); $pdf->SetXY($x+$split,$y+0.8); $pdf->Cell(18,3,boleto_document_pdf_text('CNPJ/CPF:'),0,0,'L');
  $pdf->SetFont('Arial','',6.3); $pdf->SetXY($x+$split+19,$y+0.8); $pdf->Cell($w-$split-20,3,boleto_document_pdf_text($payerDoc),0,0,'L');
  $pdf->SetFont('Arial','B',6.0); $pdf->SetXY($x+0.8,$y+4.6); $pdf->Cell(28,3,boleto_document_pdf_text('Beneficiário final:'),0,0,'L');
  $pdf->SetXY($x+$split,$y+4.6); $pdf->Cell(18,3,boleto_document_pdf_text('CNPJ/CPF:'),0,0,'L');
  $y += $h;

  return $y;
}

function boleto_document_pdf_itau(array $data): string {
  if (!boleto_document_ready($data)) throw new RuntimeException('O boleto ainda não possui linha digitável e código de barras para impressão.');
  $pdf = new BoletoPdf('P','mm','A4');
  $pdf->SetMargins(14,8,14);
  $pdf->SetAutoPageBreak(false);
  $pdf->AddPage();
  $pdf->SetDrawColor(20,20,20);
  $pdf->SetTextColor(20,20,20);
  $pdf->SetLineWidth(0.22);

  $x=14.0; $w=182.0;
  $endTop = boleto_document_pdf_itau_section($pdf,$data,$x,13.0,$w,true);
  $cutY = $endTop + 8.0;
  $pdf->SetFont('Arial','',5.8); $pdf->SetXY($x,$cutY-4.0); $pdf->Cell($w,3,boleto_document_pdf_text('Autenticação mecânica'),0,0,'R');
  $pdf->SetDrawColor(110,110,110);
  $segments = 90; $segW=$w/$segments;
  for($i=0;$i<$segments;$i+=2){ $pdf->Line($x+$i*$segW,$cutY,$x+($i+1)*$segW,$cutY); }
  $pdf->SetDrawColor(20,20,20);

  $endBottom = boleto_document_pdf_itau_section($pdf,$data,$x,$cutY+4.0,$w,false);
  $barcodeY = $endBottom + 1.3;
  boleto_document_pdf_draw_barcode($pdf,(string)$data['barcode'],$x,$barcodeY,111.0,14.5);
  $pdf->SetFont('Arial','B',8.7); $pdf->SetXY($x+112.0,$barcodeY+0.5); $pdf->Cell($w-112.0,5,boleto_document_pdf_text('Ficha de Compensação'),0,0,'R');
  $pdf->SetFont('Arial','',5.7); $pdf->SetXY($x+112.0,$barcodeY+5.0); $pdf->Cell($w-112.0,4,boleto_document_pdf_text('Autenticação mecânica'),0,0,'R');
  return (string)$pdf->Output('', 'S');
}

function boleto_document_pdf_generic(array $data): string {
  if (!boleto_document_ready($data)) throw new RuntimeException('O boleto ainda não possui linha digitável e código de barras para impressão.');

  $pdf = new BoletoPdf('P', 'mm', 'A4');
  $pdf->SetMargins(10, 9, 10);
  $pdf->SetAutoPageBreak(false);
  $pdf->AddPage();
  $pdf->SetDrawColor(40,40,40);
  $pdf->SetTextColor(20,20,20);

  $x = 10.0; $w = 190.0;
  $bank = boleto_document_bank_label($data);
  $bankCodeDisplay = boleto_document_bank_code_with_dv($data);
  $line = trim((string)($data['linha_digitavel'] ?? ''));

  $pdf->Rect($x,10,35,10);
  boleto_document_pdf_generic_brand($pdf, $data, $x, 10, 35, 10);
  $pdf->SetFont('Arial','B',11); $pdf->SetXY($x+35,10); $pdf->Cell(20,10,boleto_document_pdf_text($bankCodeDisplay),1,0,'C');
  $pdf->SetFont('Arial','B',10); $pdf->SetXY($x+55,10); $pdf->Cell(135,10,boleto_document_pdf_text($line),1,0,'R');

  boleto_document_pdf_field($pdf,$x,22,145,12,'Local de pagamento','Pagável em qualquer banco ou via Pix até o vencimento',8);
  boleto_document_pdf_field($pdf,$x+145,22,45,12,'Vencimento',boleto_document_date($data['due_date'] ?? ''),10,'R');

  $companyName = boleto_document_company_name($data);
  $companyDoc = boleto_document_digits($data['company']['cnpj'] ?? '');
  boleto_document_pdf_field($pdf,$x,34,145,12,'Beneficiário',$companyName . ($companyDoc!==''?'  CNPJ: '.$companyDoc:''),8);
  boleto_document_pdf_field($pdf,$x+145,34,45,12,'Agência / Conta',trim((string)($data['bank_agency'] ?? '')) . ' / ' . trim((string)($data['bank_account_number'] ?? '')),8,'R');

  boleto_document_pdf_field($pdf,$x,46,34,11,'Data do documento',boleto_document_date($data['issue_date'] ?? ''),8);
  boleto_document_pdf_field($pdf,$x+34,46,42,11,'Nº documento',trim((string)($data['document_number'] ?? $data['seu_numero'] ?? '')),8);
  boleto_document_pdf_field($pdf,$x+76,46,30,11,'Espécie doc.','DM',8,'C');
  boleto_document_pdf_field($pdf,$x+106,46,39,11,'Nosso Número',trim((string)($data['nosso_numero'] ?? '')),8);
  boleto_document_pdf_field($pdf,$x+145,46,45,11,'Valor do documento',boleto_document_money($data['amount'] ?? 0),9,'R');

  boleto_document_pdf_field($pdf,$x,57,145,18,'Instruções',trim((string)($data['instructions'] ?? '')) ?: 'Após o vencimento, consulte as condições atualizadas de pagamento.',7);
  boleto_document_pdf_field($pdf,$x+145,57,45,18,'(-) Desconto / (+) Encargos','Conforme condições do título',6.5,'R');

  $payer = trim((string)($data['payer_name'] ?? '')) ?: 'Pagador';
  $payerDoc = boleto_document_digits($data['payer_document'] ?? '');
  $address = boleto_document_payer_address($data);
  $pdf->Rect($x,75,$w,28);
  $pdf->SetXY($x+1.2,76); $pdf->SetFont('Arial','',5.8); $pdf->Cell(25,3,boleto_document_pdf_text('Pagador'),0,0,'L');
  $pdf->SetXY($x+1.2,80); $pdf->SetFont('Arial','B',8.5); $pdf->Cell(187,4,boleto_document_pdf_text($payer . ($payerDoc!==''?'  CPF/CNPJ: '.$payerDoc:'')),0,0,'L');
  if ($address !== '') { $pdf->SetXY($x+1.2,85); $pdf->SetFont('Arial','',7.3); $pdf->MultiCell(187,4,boleto_document_pdf_text($address),0,'L'); }
  $pdf->SetXY($x+1.2,97); $pdf->SetFont('Arial','',6.5); $pdf->Cell(187,4,boleto_document_pdf_text('Sacador/Avalista: '),0,0,'L');

  $pdf->SetXY($x,107); $pdf->SetFont('Arial','B',7); $pdf->Cell($w,5,boleto_document_pdf_text('Autenticação mecânica / Ficha de Compensação'),0,1,'R');

  // A área do Pix possui margem de segurança própria. Isso evita que QR Code/texto
  // ultrapassem a largura útil do A4 em leitores que tratam PNG com DPI diferente.
  $pixBase64 = trim((string)($data['pix_qr_base64'] ?? ''));
  $pixEmv = trim((string)($data['pix_emv'] ?? ''));
  $hasPix = ($pixBase64 !== '' || $pixEmv !== '');
  $barcodeX = $x + 4;
  $barcodeW = $hasPix ? 104.0 : 176.0;
  boleto_document_pdf_draw_barcode($pdf, (string)$data['barcode'], $barcodeX, 114, $barcodeW, 20);
  $pdf->SetXY($barcodeX,135); $pdf->SetFont('Arial','',6.2); $pdf->Cell($barcodeW,4,boleto_document_pdf_text((string)$data['barcode']),0,0,'C');

  if ($hasPix) {
    $pixX = 126.0;
    $pixY = 111.0;
    $pixW = 70.0;
    $pixH = 48.0;
    $pdf->Rect($pixX,$pixY,$pixW,$pixH);
    $pdf->SetXY($pixX+1,$pixY+1); $pdf->SetFont('Arial','B',7); $pdf->Cell($pixW-2,4,boleto_document_pdf_text('Pague também via Pix'),0,0,'C');

    $tmp = '';
    if ($pixBase64 !== '') {
      $pixBase64 = preg_replace('#^data:image/[^;]+;base64,#i', '', $pixBase64) ?? $pixBase64;
      $bin = base64_decode($pixBase64, true);
      if (is_string($bin) && $bin !== '') {
        $tmp = tempnam(sys_get_temp_dir(), 'nexa_pix_') ?: '';
        if ($tmp !== '') {
          file_put_contents($tmp, $bin);
          try { $pdf->Image($tmp, $pixX+18, $pixY+6, 34, 34, 'PNG'); } catch (Throwable $e) {}
        }
      }
    }
    if ($tmp !== '') @unlink($tmp);
    if ($pixEmv !== '') {
      $short = mb_strlen($pixEmv) > 74 ? mb_substr($pixEmv,0,74) . '...' : $pixEmv;
      $pdf->SetXY($pixX+2,$pixY+41); $pdf->SetFont('Arial','',4.7); $pdf->MultiCell($pixW-4,2.2,boleto_document_pdf_text($short),0,'C');
    }
  }

  $pdf->SetXY($x,165); $pdf->SetFont('Arial','B',9); $pdf->Cell($w,6,boleto_document_pdf_text('Recibo do Pagador'),0,1,'L');
  boleto_document_pdf_field($pdf,$x,172,95,12,'Beneficiário',$companyName,7.5);
  boleto_document_pdf_field($pdf,$x+95,172,50,12,'Nosso Número',trim((string)($data['nosso_numero'] ?? '')),8);
  boleto_document_pdf_field($pdf,$x+145,172,45,12,'Vencimento',boleto_document_date($data['due_date'] ?? ''),9,'R');
  boleto_document_pdf_field($pdf,$x,184,145,12,'Pagador',$payer,8);
  boleto_document_pdf_field($pdf,$x+145,184,45,12,'Valor',boleto_document_money($data['amount'] ?? 0),9,'R');
  boleto_document_pdf_field($pdf,$x,196,190,12,'Linha digitável',$line,8,'C');

  $pdf->SetXY($x,215); $pdf->SetFont('Arial','',6); $pdf->MultiCell($w,3.5,boleto_document_pdf_text('Documento de cobrança gerado pelo Nexa Próspero a partir dos dados registrados pelo banco. Confira o favorecido, vencimento e valor antes do pagamento.'),0,'L');

  return (string)$pdf->Output('', 'S');
}

if (!function_exists('boleto_document_pdf')) {
  function boleto_document_pdf(array $data): string {
    return boleto_document_is_itau($data) ? boleto_document_pdf_itau($data) : boleto_document_pdf_generic($data);
  }
}
