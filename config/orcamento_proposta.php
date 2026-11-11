<?php
declare(strict_types=1);

/**
 * Orçamento no modelo "Proposta/Orçamento" (A4 com quadros), além do A4 e da Bobina 70 mm.
 * Os mesmos dados alimentam a impressão (admin/_orcamento_print_proposta.php) e o PDF
 * enviado por WhatsApp/E-mail (orc_proposta_pdf_build).
 * Textos e validade ficam em Configurações do Orçamento (app_config "orcamento_print").
 */

require_once __DIR__ . '/config_store.php';

if (!function_exists('orc_proposta_defaults')) {
  function orc_proposta_defaults(): array {
    return [
      'proposta_validity_days' => 0,
      'proposta_intro' => 'Conforme entendimento e honrados com a oportunidade, segue abaixo proposta de Produtos/Serviços',
      'proposta_other' => '* Todos os impostos inclusos',
      'proposta_terms' => 'CONCORDO COM A PROPOSTA ACIMA ESPECIFICADA E AUTORIZO TRANSFORMÁ-LA EM PEDIDO DE COMPRA DO PRODUTO/SERVIÇO AQUI DISCRIMINADO CONFORME ASSINATURA ABAIXO.',
    ];
  }
}

if (!function_exists('orc_print_config')) {
  /** Configuração de impressão do orçamento (modelo padrão + textos da Proposta). */
  function orc_print_config(PDO $pdo, int $companyId): array {
    $cfg = [];
    try { $cfg = config_store_get($pdo, $companyId, 'orcamento_print') ?: []; } catch (Throwable $e) {}
    $out = array_merge(['default_model' => 'a4'], orc_proposta_defaults());
    $model = (string)($cfg['default_model'] ?? 'a4');
    $out['default_model'] = in_array($model, ['a4', '72mm', 'proposta'], true) ? $model : 'a4';
    $out['proposta_validity_days'] = max(0, min(365, (int)($cfg['proposta_validity_days'] ?? 0)));
    foreach (['proposta_intro', 'proposta_other', 'proposta_terms'] as $k) {
      if (array_key_exists($k, $cfg)) $out[$k] = trim((string)$cfg[$k]);
    }
    return $out;
  }
}

if (!function_exists('orc_proposta_pick')) {
  function orc_proposta_pick(array $row, array $keys): string {
    foreach ($keys as $k) { $v = trim((string)($row[$k] ?? '')); if ($v !== '') return $v; }
    return '';
  }
}

if (!function_exists('orc_proposta_data')) {
  /**
   * Monta tudo o que o modelo precisa a partir do orçamento já carregado
   * ($quote, $items, $payments) da empresa $companyId.
   */
  function orc_proposta_data(PDO $pdo, int $companyId, array $quote, array $items, array $payments = []): array {
    $pick = 'orc_proposta_pick';
    $company = [];
    try { $st = $pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1'); $st->execute([$companyId]); $company = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $fiscal = [];
    try { $st = $pdo->prepare('SELECT * FROM fiscal_company_config WHERE company_id=? LIMIT 1'); $st->execute([$companyId]); $fiscal = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}

    $street = $pick($company, ['endereco', 'address']);
    $num = $pick($company, ['numero', 'number']);
    $district = $pick($company, ['bairro', 'district']);
    $cep = $pick($company, ['cep', 'zip_code']);
    $line = trim($street . ($num !== '' ? ', ' . $num : '') . ($district !== '' ? ' - ' . $district : '') . ($cep !== '' ? ' - Cep: ' . $cep : ''));
    $city = $pick($company, ['cidade', 'city']);
    $uf = $pick($company, ['estado', 'state', 'uf']);
    $c = [
      'id' => $companyId,
      'name' => $pick($company, ['razao_social', 'legal_name', 'name']) ?: 'Empresa',
      'doc' => $pick($company, ['cnpj', 'cpf', 'document']),
      'ie' => $pick($fiscal, ['state_registration']) ?: $pick($company, ['ie', 'state_registration', 'inscricao_estadual']),
      'address' => $line,
      'city' => trim($city . ($uf !== '' ? '/' . $uf : '') . ($city !== '' ? ' - Brasil' : '')),
      'phone' => implode(' / ', array_values(array_unique(array_filter([$pick($company, ['phone', 'telefone']), $pick($company, ['whatsapp'])])))),
    ];
    // Mesmos dados personalizados da Duplicata (Configurações fiscais), quando preenchidos.
    try {
      $dup = config_store_get($pdo, $companyId, 'pedido_duplicata') ?: [];
      $ov = static fn(string $k): string => trim((string)($dup['company_' . $k] ?? ''));
      if ($ov('name') !== '') $c['name'] = $ov('name');
      if ($ov('doc') !== '') $c['doc'] = $ov('doc');
      if ($ov('ie') !== '') $c['ie'] = $ov('ie');
      if ($ov('address') !== '') $c['address'] = $ov('address');
      if ($ov('city') !== '') $c['city'] = $ov('city');
      if ($ov('phone') !== '') $c['phone'] = $ov('phone');
    } catch (Throwable $e) {}

    $person = [];
    if ((int)($quote['person_id'] ?? 0) > 0) {
      try { $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$companyId, (int)$quote['person_id']]); $person = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    }
    $addr = $pick($person, ['address']);
    if ($addr !== '' && $pick($person, ['number']) !== '') $addr .= ', ' . $pick($person, ['number']);
    if ($pick($person, ['complement']) !== '') $addr .= ($addr !== '' ? ', ' : '') . $pick($person, ['complement']);
    $cust = [
      'name' => trim((string)($quote['person_name_snapshot'] ?? '')) ?: ($pick($person, ['name']) ?: 'Cliente não informado'),
      'address' => $addr,
      'district' => $pick($person, ['district']),
      'city' => $pick($person, ['city']),
      'uf' => $pick($person, ['state']),
      'zip' => $pick($person, ['zip_code']),
      'phone' => trim((string)($quote['person_phone_snapshot'] ?? '')) ?: $pick($person, ['phone', 'whatsapp']),
      'doc' => trim((string)($quote['person_document_snapshot'] ?? '')) ?: $pick($person, ['document']),
      'rg_ie' => $pick($person, ['state_registration', 'identity_document']),
    ];

    $rows = [];
    $itemDisc = 0.0;
    $itemAdd = 0.0;
    foreach ($items as $it) {
      $desc = trim((string)($it['description_snapshot'] ?? ''));
      $note = trim((string)($it['notes'] ?? ''));
      $rows[] = [
        'code' => trim((string)($it['product_code_snapshot'] ?? '')) ?: (string)($it['produto_id'] ?? ''),
        'qty' => (float)($it['quantity'] ?? 0),
        'unit' => trim((string)($it['unidade_snapshot'] ?? '')) ?: 'UN',
        'desc' => $desc . ($note !== '' ? "\n" . $note : ''),
        'price' => (float)($it['unit_price'] ?? 0),
        'total' => (float)($it['total'] ?? 0),
      ];
      $itemDisc += (float)($it['discount_amount'] ?? 0);
      $itemAdd += (float)($it['addition_amount'] ?? 0);
    }
    $desconto = round($itemDisc + (float)($quote['discount_amount'] ?? 0), 2);
    $acrescimo = round($itemAdd + (float)($quote['addition_amount'] ?? 0), 2);
    $frete = round((float)($quote['freight_amount'] ?? 0), 2);
    $pagar = round((float)($quote['total_general'] ?? 0), 2);
    // Bruto calculado a partir do total final para a conta sempre fechar.
    $bruto = round($pagar - $frete - $acrescimo + $desconto, 2);

    $cfg = orc_print_config($pdo, $companyId);
    $quoteDate = (string)($quote['quote_date'] ?? '');
    $ts = $quoteDate !== '' ? strtotime($quoteDate) : false;
    $days = (int)$cfg['proposta_validity_days'];
    $pays = [];
    foreach ($payments as $p) {
      $label = trim((string)($p['payment_label'] ?? ''));
      $due = !empty($p['due_date']) && strtotime((string)$p['due_date']) ? date('d/m/Y', strtotime((string)$p['due_date'])) : '';
      $pays[] = trim($label . ($due !== '' ? ' - ' . $due : '') . ': R$ ' . number_format((float)($p['amount'] ?? 0), 2, ',', '.'));
    }
    return [
      'number' => (string)(int)($quote['quote_number'] ?? 0),
      'date' => $ts ? date('d/m/Y', $ts) : '',
      'validity' => $days > 0 ? ($days . ($days === 1 ? ' Dia' : ' Dias')) : '',
      'company' => $c,
      'customer' => $cust,
      'items' => $rows,
      'totals' => ['bruto' => $bruto, 'desconto' => $desconto, 'acrescimo' => $acrescimo, 'frete' => $frete, 'pagar' => $pagar],
      'seller' => trim((string)($quote['seller_name_snapshot'] ?? '')),
      'payments' => $pays,
      'notes' => trim((string)($quote['notes'] ?? '')),
      'intro' => (string)$cfg['proposta_intro'],
      'other' => (string)$cfg['proposta_other'],
      'terms' => (string)$cfg['proposta_terms'],
    ];
  }
}

if (!function_exists('orc_proposta_money')) {
  function orc_proposta_money($v): string { return number_format((float)$v, 2, ',', '.'); }
}
if (!function_exists('orc_proposta_qty')) {
  function orc_proposta_qty($v): string { $n = number_format((float)$v, 4, ',', '.'); return rtrim(rtrim($n, '0'), ','); }
}

if (!function_exists('orc_proposta_pdf_build')) {
  /** PDF A4 do modelo Proposta para WhatsApp/E-mail. $data = nexa_quote_document_load(). */
  function orc_proposta_pdf_build(PDO $pdo, int $companyId, array $data): array {
    nexa_customer_pdf_bootstrap();
    $d = orc_proposta_data($pdo, $companyId, (array)$data['quote'], (array)$data['items'], (array)($data['payments'] ?? []));
    $t = static fn($v): string => nexa_customer_pdf_text((string)$v);
    $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P', 'mm', 'A4');
    $m = 10.0; $W = 190.0;
    $pdf->SetMargins($m, $m, $m);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetTextColor(0, 0, 0);
    $pdf->SetDrawColor(0, 0, 0);
    $gray = [217, 217, 217];
    $c = $d['company'];

    // Cabeçalho: logo | empresa | número.
    $top = $pdf->GetY(); $h = 34.0;
    $pdf->SetLineWidth(.3);
    $pdf->Rect($m, $top, $W, $h);
    $pdf->Line($m + 38, $top, $m + 38, $top + $h);
    $pdf->Line($m + 128, $top, $m + 128, $top + $h);
    $logo = nexa_customer_pdf_logo_path($companyId);
    if ($logo !== '') {
      try {
        $info = @getimagesize($logo);
        $bw = 34.0; $bh = 30.0;
        if ($info && $info[0] > 0 && $info[1] > 0) {
          $ratio = $info[1] / $info[0];
          $w = $bw; $hh = $w * $ratio;
          if ($hh > $bh) { $hh = $bh; $w = $hh / $ratio; }
          $pdf->Image($logo, $m + 2 + ($bw - $w) / 2, $top + 2 + ($bh - $hh) / 2, $w, $hh);
        }
      } catch (Throwable $e) {}
    }
    $pdf->SetXY($m + 41, $top + 3);
    $pdf->SetFont('Times', 'B', 10); $pdf->MultiCell(85, 4.2, $t(mb_strtoupper($c['name'], 'UTF-8')), 0, 'L');
    $pdf->SetFont('Times', '', 9);
    foreach (array_filter([$c['doc'] !== '' ? (strlen(preg_replace('/\D/', '', $c['doc'])) > 11 ? 'CNPJ: ' : 'CPF: ') . $c['doc'] : '', $c['ie'] !== '' ? 'I.E.: ' . $c['ie'] : '', $c['address'], $c['city'], $c['phone']]) as $ln) {
      $pdf->SetX($m + 41); $pdf->MultiCell(85, 3.9, $t($ln), 0, 'L');
    }
    $x3 = $m + 131;
    $pdf->SetXY($x3, $top + 6); $pdf->SetFont('Times', 'B', 10); $pdf->Cell(40, 5, $t('Nº da Proposta/Orçamento :'), 0, 0, 'L');
    $pdf->SetFont('Arial', 'B', 11); $pdf->Cell(17, 5, $t($d['number']), 0, 1, 'R');
    $pdf->SetFont('Times', '', 9);
    $pdf->SetXY($x3, $top + 13); $pdf->Cell(28, 5, $t('Data da Proposta:'), 0, 0); $pdf->Cell(28, 5, $t($d['date']), 0, 1);
    if ($d['validity'] !== '') { $pdf->SetXY($x3, $top + 19); $pdf->Cell(28, 5, $t('Validade da Proposta'), 0, 0); $pdf->Cell(28, 5, $t($d['validity']), 0, 1); }
    $y = $top + $h;

    $bar = static function (string $title, float $y) use ($pdf, $m, $W, $gray, $t): float {
      $pdf->SetFillColor(...$gray); $pdf->SetXY($m, $y); $pdf->SetFont('Times', 'B', 9.5);
      $pdf->Cell($W, 5, $t($title), 1, 1, 'L', true);
      return $y + 5;
    };

    // Dados do cliente.
    $y = $bar('Dados de Cliente', $y);
    $cu = $d['customer'];
    $boxTop = $y; $pdf->SetXY($m + 2, $y + 1.5);
    $field = static function (string $label, string $value, float $x, float $lw, float $vw) use ($pdf, $t): void {
      $pdf->SetX($x); $pdf->SetFont('Times', 'B', 9); $pdf->Cell($lw, 4.4, $t($label), 0, 0);
      $pdf->SetFont('Times', '', 9); $pdf->Cell($vw, 4.4, $t($value), 0, 0);
    };
    $field('Destinatário:', $cu['name'], $m + 2, 20, 160); $pdf->Ln(4.6);
    $field('Endereço:', $cu['address'], $m + 2, 20, 95); $field('Bairro:', $cu['district'], $m + 120, 16, 50); $pdf->Ln(4.4);
    $field('Cidade:', $cu['city'], $m + 2, 20, 60); $field('UF', $cu['uf'], $m + 84, 7, 25); $field('Telefone:', $cu['phone'], $m + 120, 16, 50); $pdf->Ln(4.4);
    $field('CPF/CNPJ:', $cu['doc'], $m + 2, 20, 95); $field('RG/I.E.:', $cu['rg_ie'], $m + 120, 16, 50); $pdf->Ln(4.4);
    $field('A/C:', '', $m + 2, 20, 95); $pdf->Ln(5.2);
    $y = $pdf->GetY(); $pdf->Rect($m, $boxTop, $W, $y - $boxTop);

    if ($d['intro'] !== '') {
      $pdf->SetXY($m, $y + 1.5); $pdf->SetFont('Arial', 'IU', 11);
      $pdf->MultiCell($W, 5.5, $t($d['intro']), 0, 'C');
      $y2 = $pdf->GetY() + 1.5; $pdf->Rect($m, $y, $W, $y2 - $y); $y = $y2;
    }

    // Itens.
    $y = $bar('Dados dos Produtos/Serviços', $y);
    $cols = [['Código', 22, 'L'], ['Quantidade', 20, 'C'], ['Unidade', 15, 'C'], ['Descrição', 85, 'L'], ['Vlr Unitário', 24, 'R'], ['Total', 24, 'R']];
    $pdf->SetXY($m, $y); $pdf->SetFont('Times', 'B', 8.5);
    foreach ($cols as [$l, $w, $a]) $pdf->Cell($w, 4.6, $t($l), 'B', 0, $a);
    $pdf->Ln(5.6);
    $pdf->SetFont('Arial', '', 7.5);
    foreach ($d['items'] as $it) {
      $lines = max(1, count(explode("\n", (string)$it['desc'])) + (int)floor($pdf->GetStringWidth($t($it['desc'])) / 82));
      $rh = max(6.0, $lines * 3.6 + 2);
      if ($pdf->GetY() + $rh > $pdf->h - 15) { $pdf->AddPage(); }
      $ry = $pdf->GetY();
      $pdf->Rect($m, $ry, $W, $rh);
      $pdf->SetXY($m + 1, $ry + 1); $pdf->Cell(21, 3.6, $t($it['code']), 0, 0, 'L');
      $pdf->Cell(20, 3.6, $t(orc_proposta_qty($it['qty'])), 0, 0, 'C');
      $pdf->Cell(15, 3.6, $t($it['unit']), 0, 0, 'C');
      $dx = $pdf->GetX(); $pdf->MultiCell(85, 3.6, $t($it['desc']), 0, 'L');
      $pdf->SetXY($dx + 85, $ry + 1); $pdf->Cell(24, 3.6, $t(orc_proposta_money($it['price'])), 0, 0, 'R');
      $pdf->Cell(23, 3.6, $t(orc_proposta_money($it['total'])), 0, 0, 'R');
      $pdf->SetY($ry + $rh);
    }

    // Totais.
    $tt = $d['totals'];
    $tRows = [['Valor total bruto:', orc_proposta_money($tt['bruto']), 'B'], ['Total de Descontos:', $tt['desconto'] > 0 ? orc_proposta_money($tt['desconto']) : '', 'I'], ['Total de Acréscimos:', $tt['acrescimo'] > 0 ? orc_proposta_money($tt['acrescimo']) : '', 'I']];
    if ($tt['frete'] > 0) $tRows[] = ['Frete:', orc_proposta_money($tt['frete']), 'I'];
    $tRows[] = ['Valor a pagar:', orc_proposta_money($tt['pagar']), 'B'];
    $ty = $pdf->GetY() + 1; $th = count($tRows) * 4.4 + 2;
    if ($ty + $th > $pdf->h - 15) { $pdf->AddPage(); $ty = $pdf->GetY(); }
    $pdf->Rect($m, $ty, 80, $th); $pdf->SetXY($m + 2, $ty + 1);
    foreach ($tRows as [$l, $v, $st]) {
      $pdf->SetX($m + 2); $pdf->SetFont('Times', $st, 8.5); $pdf->Cell(45, 4.4, $t($l), 0, 0);
      $pdf->SetFont('Times', 'B', 8.5); $pdf->Cell(28, 4.4, $t($v), 0, 1, 'R');
    }
    $y = $ty + $th + 6;

    // Outros dados.
    $other = [];
    if ($d['seller'] !== '') $other[] = ['Representante: ', mb_strtoupper($d['seller'], 'UTF-8')];
    foreach ($d['payments'] as $i => $p) $other[] = [$i === 0 ? 'Pagamento: ' : '', $p];
    if ($d['other'] !== '') foreach (preg_split('/\R/', $d['other']) ?: [] as $ln) $other[] = ['', $ln];
    if ($other) {
      if ($y + 12 > $pdf->h - 15) { $pdf->AddPage(); $y = $pdf->GetY(); }
      $y = $bar('Outros dados:', $y); $pdf->SetXY($m + 2, $y + 1.5);
      foreach ($other as [$l, $v]) {
        $pdf->SetX($m + 2); $pdf->SetFont('Times', 'B', 9.5);
        if ($l !== '') { $pdf->Cell($pdf->GetStringWidth($t($l)) + 1, 4.6, $t($l), 0, 0); $pdf->SetFont('Arial', 'B', 9); }
        $pdf->MultiCell(180, 4.6, $t($v), 0, 'L');
      }
      $y2 = $pdf->GetY() + 1.5; $pdf->Rect($m, $y, $W, $y2 - $y); $y = $y2 + 4;
    }

    // Observações.
    if ($y + 30 > $pdf->h - 15) { $pdf->AddPage(); $y = $pdf->GetY(); }
    $y = $bar('Observações:', $y); $pdf->SetXY($m + 1, $y + 1.5); $pdf->SetFont('Times', 'B', 9);
    $pdf->MultiCell($W - 2, 4.2, $t(mb_strtoupper($d['notes'], 'UTF-8')), 0, 'L');
    $y2 = max($pdf->GetY() + 2, $y + 30); $pdf->Rect($m, $y, $W, $y2 - $y); $y = $y2 + 4;

    // Aceite e assinaturas.
    if ($y + 30 > $pdf->h - 10) { $pdf->AddPage(); $y = $pdf->GetY(); }
    $pdf->SetXY($m, $y); $pdf->SetFont('Times', '', 8);
    if ($d['terms'] !== '') $pdf->MultiCell(150, 4, $t($d['terms']), 0, 'L');
    $sy = $pdf->GetY() + 8;
    $pdf->SetXY($m + 160, $sy); $pdf->SetFont('Times', '', 8.5); $pdf->Cell(30, 4, $t($d['date']), 0, 2, 'C');
    $pdf->SetFont('Times', 'B', 8.5); $pdf->Cell(30, 4, 'DATA', 0, 0, 'C');
    $ly = $sy + 12;
    $pdf->Line($m, $ly, $m + 75, $ly); $pdf->Line($m + 80, $ly, $m + 155, $ly);
    $pdf->SetXY($m, $ly + .5); $pdf->SetFont('Times', 'B', 8); $pdf->Cell(75, 4, $t(mb_strtoupper($c['name'], 'UTF-8')), 0, 0, 'C');
    $pdf->SetX($m + 80); $pdf->Cell(75, 4, 'ASSINATURA/CARIMBO CLIENTE', 0, 0, 'C');

    return ['filename' => 'proposta-orcamento-' . $d['number'] . '.pdf', 'mimetype' => 'application/pdf', 'content' => $pdf->Output('', 'S')];
  }
}
