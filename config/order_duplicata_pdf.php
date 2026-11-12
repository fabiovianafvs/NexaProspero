<?php
declare(strict_types=1);

/**
 * Pedido em PDF no modelo "Duplicata de Venda Mercantil" (A4) — mesmo conteúdo de
 * admin/_pedido_print_duplicata.php, para envio por WhatsApp/e-mail.
 * Também decide qual modelo de PDF do pedido é enviado (o padrão das Configurações fiscais).
 */

require_once __DIR__ . '/order_email_pdf.php';

if (!function_exists('order_pdf_default_model')) {
  /**
   * Modelo do PDF do pedido = layout padrão de impressão (Configurações fiscais).
   * Duplicata -> 'duplicata'; Moderno 70 mm -> '72mm'; Moderno A4 ou modelo atual (cupom) -> 'a4'.
   */
  function order_pdf_default_model(PDO $pdo, int $companyId): string {
    try {
      $st = $pdo->prepare('SELECT order_modern_print_enabled, order_modern_print_default FROM fiscal_company_config WHERE company_id = ? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return 'a4';
    }
    if (empty($row['order_modern_print_enabled'])) return 'a4';
    $def = strtoupper(trim((string)($row['order_modern_print_default'] ?? 'A4')));
    return ['72MM' => '72mm', 'DUPLICATA' => 'duplicata'][$def] ?? 'a4';
  }
}

if (!function_exists('order_document_pdf_build')) {
  /** PDF do pedido no modelo pedido ('a4', '72mm' ou 'duplicata'). */
  function order_document_pdf_build(array $order, array $items, string $companyName, string $model, ?PDO $pdo, int $companyId): array {
    if (strtolower(trim($model)) === 'duplicata' && $pdo) return order_duplicata_pdf_build($order, $items, $pdo, $companyId);
    return order_email_pdf_build($order, $items, $companyName, $model, $pdo, $companyId);
  }
}

if (!function_exists('order_duplicata_pdf_data')) {
  /** Dados da Duplicata (mesmas regras da tela de impressão). */
  function order_duplicata_pdf_data(array $order, array $items, PDO $pdo, int $cid): array {
    $pick = static function (array $row, array $keys): string {
      foreach ($keys as $k) { $v = trim((string)($row[$k] ?? '')); if ($v !== '') return $v; }
      return '';
    };
    $company = [];
    $fiscal = [];
    try { $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1'); $st->execute([$cid]); $company = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    try { $st = $pdo->prepare('SELECT * FROM fiscal_company_config WHERE company_id = ? LIMIT 1'); $st->execute([$cid]); $fiscal = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    $num = $pick($company, ['numero', 'number']);
    $bairro = $pick($company, ['bairro', 'district']);
    $c = [
      'name' => $pick($company, ['razao_social', 'legal_name', 'name']),
      'doc' => $pick($company, ['cnpj', 'cpf', 'document']) ?: $pick($fiscal, ['company_cnpj']),
      'ie' => $pick($fiscal, ['state_registration']) ?: $pick($company, ['ie', 'state_registration', 'inscricao_estadual']),
      'street' => trim($pick($company, ['endereco', 'address']) . ($num !== '' ? ', ' . $num : '') . ($bairro !== '' ? ' - ' . $bairro : '')),
      'city' => $pick($company, ['cidade', 'city']),
      'uf' => $pick($company, ['estado', 'state', 'uf']),
      'phones' => implode(' / ', array_values(array_unique(array_filter([$pick($company, ['phone', 'telefone']), $pick($company, ['whatsapp'])])))),
    ];
    $terms = '';
    try {
      if (!function_exists('config_store_get')) require_once __DIR__ . '/config_store.php';
      $cfg = config_store_get($pdo, $cid, 'pedido_duplicata') ?: [];
      $terms = trim((string)($cfg['terms'] ?? ''));
      $ov = static fn(string $k): string => trim((string)($cfg['company_' . $k] ?? ''));
      if ($ov('name') !== '') $c['name'] = $ov('name');
      if ($ov('doc') !== '') $c['doc'] = $ov('doc');
      if ($ov('ie') !== '') $c['ie'] = $ov('ie');
      if ($ov('address') !== '') $c['street'] = $ov('address');
      if ($ov('city') !== '') { $c['city'] = $ov('city'); $c['uf'] = ''; }
      if ($ov('phone') !== '') $c['phones'] = $ov('phone');
    } catch (Throwable $e) {}

    // Cliente: pedido + cadastro de Pessoas.
    $person = [];
    $pid = (int)($order['finance_person_id'] ?? 0) ?: (int)($order['customer_id'] ?? 0);
    if ($pid > 0) {
      try { $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1'); $st->execute([$cid, $pid]); $person = $st->fetch(PDO::FETCH_ASSOC) ?: []; } catch (Throwable $e) {}
    }
    [$district, $address, , $city, $uf] = order_email_pdf_split_address($order);
    $pAddr = trim($pick($person, ['address']) . ($pick($person, ['number']) !== '' ? ', ' . $pick($person, ['number']) : '') . ($pick($person, ['complement']) !== '' ? ', ' . $pick($person, ['complement']) : ''));
    $customer = [
      'name' => trim((string)($order['nome_cliente'] ?? '')) ?: ($pick($person, ['name']) ?: 'CONSUMIDOR FINAL'),
      'address' => $address !== '' ? $address : $pAddr,
      'city' => $city !== '' ? $city : $pick($person, ['city']),
      'uf' => $uf !== '' ? $uf : $pick($person, ['state']),
      'district' => $district !== '' ? $district : $pick($person, ['district']),
      'doc' => trim((string)($order['customer_document'] ?? '')) ?: $pick($person, ['document']),
      'phone' => trim((string)($order['whatsapp'] ?? '')) ?: $pick($person, ['phone', 'whatsapp']),
      'rg_ie' => $pick($order, ['customer_state_registration']) ?: $pick($person, ['state_registration', 'identity_document']),
    ];

    // Código e unidade dos produtos.
    $products = [];
    try {
      $ids = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['produto_id'] ?? 0), $items))));
      if ($ids) {
        $cols = [];
        $stc = $pdo->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='produtos' AND COLUMN_NAME IN ('numero_cadastro','codigo_firebird','unidade_estoque')");
        foreach ($stc->fetchAll(PDO::FETCH_COLUMN) ?: [] as $col) $cols[(string)$col] = true;
        $expr = 'CAST(id AS CHAR)';
        if (isset($cols['numero_cadastro'], $cols['codigo_firebird'])) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
        elseif (isset($cols['numero_cadastro'])) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),CAST(id AS CHAR))";
        elseif (isset($cols['codigo_firebird'])) $expr = "COALESCE(NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
        $marks = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare('SELECT id,' . $expr . ' AS code,' . (isset($cols['unidade_estoque']) ? "COALESCE(NULLIF(unidade_estoque,''),'UN')" : "'UN'") . ' AS un FROM produtos WHERE company_id=? AND id IN (' . $marks . ')');
        $st->execute(array_merge([$cid], $ids));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $products[(int)$r['id']] = $r;
      }
    } catch (Throwable $e) {}

    $rawNotes = '';
    foreach (['observacoes', 'observacao', 'obs'] as $col) {
      if (array_key_exists($col, $order)) { $rawNotes = trim((string)($order[$col] ?? '')); break; }
    }
    [$orderNotes, $itemNotes] = order_email_pdf_item_notes($items, $rawNotes);

    $rows = [];
    $gross = 0.0;
    $itemDisc = 0.0;
    foreach (array_values($items) as $idx => $it) {
      $pidItem = (int)($it['produto_id'] ?? 0);
      $qty = (float)($it['qtd'] ?? 0);
      $unit = order_email_pdf_unit_price($it);
      $net = round((float)($it['total'] ?? ($qty * $unit)), 2);
      $g = round($qty * $unit, 2);
      if ($g < $net) $g = $net;
      $disc = round($g - $net, 2);
      $note = trim((string)($itemNotes[$idx] ?? ''));
      $rows[] = [
        'code' => (string)($products[$pidItem]['code'] ?? ($pidItem > 0 ? $pidItem : '')),
        'un' => (string)($products[$pidItem]['un'] ?? 'UN'),
        'desc' => (string)($it['nome'] ?? ('#' . $pidItem)) . ($note !== '' ? ' - ' . preg_replace('/\s+/u', ' ', $note) : ''),
        'qty' => $qty, 'unit' => $unit, 'gross' => $g, 'disc' => $disc,
        'pct' => $g > 0 ? round($disc / $g * 100, 2) : 0.0, 'net' => $net,
      ];
      $gross += $g;
      $itemDisc += $disc;
    }
    $orderDisc = order_email_pdf_first_number($order, ['desconto', 'discount', 'discount_value', 'valor_desconto']);
    $addition = order_email_pdf_first_number($order, ['acrescimo', 'addition', 'addition_value', 'valor_acrescimo']);
    $freight = order_email_pdf_first_number($order, ['frete', 'freight', 'freight_value']);
    if ($freight <= 0 && (float)($order['taxa_entrega'] ?? 0) > 0) $freight = (float)$order['taxa_entrega'];
    $total = (float)($order['total'] ?? 0);

    // Recebimento e parcelamento (Contas a Receber do pedido).
    $payments = [];
    $recIds = [];
    try {
      $st = $pdo->prepare('SELECT p.*, pm.name AS method_name FROM gestao_order_payments p LEFT JOIN finance_payment_methods pm ON pm.company_id=p.company_id AND pm.id=p.payment_method_id WHERE p.company_id=? AND p.order_id=? ORDER BY p.id');
      $st->execute([$cid, (int)($order['id'] ?? 0)]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
        $payments[] = ['label' => trim((string)($p['payment_label'] ?? '')) ?: (trim((string)($p['method_name'] ?? '')) ?: 'Pagamento'), 'amount' => (float)($p['amount'] ?? 0)];
        if ((int)($p['receivable_id'] ?? 0) > 0) $recIds[] = (int)$p['receivable_id'];
        preg_match_all('/\d+/', (string)($p['receivable_ids'] ?? ''), $m);
        foreach ($m[0] as $rid) if ((int)$rid > 0) $recIds[] = (int)$rid;
      }
    } catch (Throwable $e) {}
    $forma = trim((string)($order['forma_pagamento'] ?? ''));
    if (!$payments && $forma !== '') $payments[] = ['label' => order_email_pdf_payment_label($forma), 'amount' => $total];
    $installments = [];
    $recIds = array_values(array_unique($recIds));
    if ($recIds) {
      try {
        $marks = implode(',', array_fill(0, count($recIds), '?'));
        $st = $pdo->prepare("SELECT document_number, installment, due_date, amount FROM finance_receivables WHERE company_id=? AND id IN ($marks) AND UPPER(COALESCE(status,'')) <> 'CANCELADO' ORDER BY due_date, id");
        $st->execute(array_merge([$cid], $recIds));
        $installments = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {}
    }

    $seller = '';
    $sellerId = (int)($order['seller_id'] ?? 0);
    if ($sellerId > 0) {
      try { $st = $pdo->prepare('SELECT name FROM finance_people WHERE company_id=? AND id=? LIMIT 1'); $st->execute([$cid, $sellerId]); $seller = trim((string)($st->fetchColumn() ?: '')); } catch (Throwable $e) {}
      if ($seller === '') $seller = 'Vendedor #' . $sellerId;
    }

    $logo = '';
    try {
      if (function_exists('load_business_config')) {
        $biz = (array)load_business_config($cid);
        $lg = (array)($biz['logo'] ?? []);
        if (!array_key_exists('enabled', $lg) || !empty($lg['enabled'])) {
          $file = basename(trim((string)($lg['file'] ?? '')));
          $path = __DIR__ . '/../uploads/logos/' . $file;
          if ($file !== '' && is_file($path) && in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), ['png', 'jpg', 'jpeg'], true)) $logo = $path;
        }
      }
    } catch (Throwable $e) {}

    $created = trim((string)($order['created_at'] ?? ''));
    $ts = $created !== '' ? strtotime($created) : false;
    return [
      'number' => function_exists('company_sequence_display') ? (string)company_sequence_display($order, 'pedidos') : (string)(int)($order['id'] ?? 0),
      'date' => $ts ? date('d/m/Y', $ts) : '', 'time' => $ts ? date('H:i:s', $ts) : '',
      'company' => $c, 'customer' => $customer, 'rows' => $rows,
      'gross' => $gross, 'discount' => round($itemDisc + $orderDisc, 2), 'addition' => $addition, 'freight' => $freight, 'total' => $total,
      'payments' => $payments, 'installments' => $installments, 'seller' => $seller, 'terms' => $terms,
      'obs' => trim((string)$orderNotes), 'logo' => $logo,
    ];
  }
}

if (!function_exists('order_duplicata_pdf_build')) {
  function order_duplicata_pdf_build(array $order, array $items, PDO $pdo, int $cid): array {
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $f = __DIR__ . '/../vendor/nfephp-org/sped-da/src/Legacy/FPDF/Fpdf.php';
      if (is_file($f)) require_once $f;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) { $a = __DIR__ . '/../vendor/autoload.php'; if (is_file($a)) require_once $a; }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) throw new RuntimeException('Biblioteca de PDF não disponível para gerar o pedido.');
    $D = order_duplicata_pdf_data($order, $items, $pdo, $cid);
    $tx = static fn(string $v): string => order_email_pdf_text($v);
    $up = static fn(string $v): string => mb_strtoupper($v, 'UTF-8');
    $n2 = static fn($v): string => number_format((float)$v, 2, ',', '.');
    $qf = static function ($v): string { $s = number_format((float)$v, 4, ',', '.'); return rtrim(rtrim($s, '0'), ','); };

    $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P', 'mm', 'A4');
    $pdf->SetMargins(8, 8, 8);
    $pdf->SetAutoPageBreak(false);
    $pdf->AddPage();
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(.2);
    $X = 8.0; $W = 194.0; $y = 8.0; $BOTTOM = 289.0;
    // Texto que não cabe é cortado com "…" (mesmo efeito da tela).
    $fit = function (string $s, float $w) use ($pdf, $tx): string {
      if ($pdf->GetStringWidth($tx($s)) <= $w) return $s;
      while ($s !== '' && $pdf->GetStringWidth($tx($s . '...')) > $w) $s = mb_substr($s, 0, -1);
      return $s . '...';
    };
    $wrap = function (string $text, float $w) use ($pdf, $tx): array {
      $out = []; $line = '';
      foreach (preg_split('/\s+/u', trim($text)) ?: [] as $word) {
        if ($word === '') continue;
        $try = $line === '' ? $word : $line . ' ' . $word;
        if ($pdf->GetStringWidth($tx($try)) <= $w) { $line = $try; continue; }
        if ($line !== '') $out[] = $line;
        $line = $word;
      }
      if ($line !== '' || !$out) $out[] = $line;
      return $out;
    };

    // Cabeçalho: logo | título + dados.
    $logoW = 34.0; $titleH = 9.0; $metaH = 12.0; $headH = $titleH + $metaH;
    $pdf->Rect($X, $y, $logoW, $headH);
    $pdf->Rect($X + $logoW, $y, $W - $logoW, $titleH);
    $pdf->Rect($X + $logoW, $y + $titleH, $W - $logoW, $metaH);
    $drawn = false;
    if ($D['logo'] !== '') {
      try {
        $info = @getimagesize($D['logo']);
        if ($info && $info[0] > 0 && $info[1] > 0) {
          $bw = $logoW - 1.6; $bh = $headH - 1.6; $r = min($bw / $info[0], $bh / $info[1]);
          $lw = $info[0] * $r; $lh = $info[1] * $r;
          $pdf->Image($D['logo'], $X + ($logoW - $lw) / 2, $y + ($headH - $lh) / 2, $lw, $lh);
          $drawn = true;
        }
      } catch (Throwable $e) {}
    }
    if (!$drawn) { $pdf->SetFont('Times', 'B', 8); $pdf->SetXY($X + 1, $y + $headH / 2 - 4); $pdf->MultiCell($logoW - 2, 3.6, $tx($D['company']['name']), 0, 'C'); }
    $pdf->SetFont('Times', 'B', 13); $pdf->SetXY($X + $logoW, $y + 1.5); $pdf->Cell($W - $logoW, 6, $tx('DUPLICATA DE VENDA MERCANTIL'), 0, 0, 'C');
    $mw = $W - $logoW;
    $metas = [['Documento:', $D['number'], .20, 'B', 11, 'L'], ['Emissão:', $D['date'], .20, 'B', 11, 'L'], ['Hora:', $D['time'], .15, '', 11, 'L'], ['Valor:', $n2($D['total']), .22, 'B', 11, 'R'], ['Consultor:', $up($D['seller']), .23, 'B', 9.5, 'L']];
    $mx = $X + $logoW;
    foreach ($metas as $i => [$lbl, $val, $frac, $st, $size, $align]) {
      $cw = $mw * $frac;
      if ($i > 0) $pdf->Line($mx, $y + $titleH, $mx, $y + $headH);
      $pdf->SetFont('Arial', '', 6.5); $pdf->SetXY($mx + 1.5, $y + $titleH + .8); $pdf->Cell($cw - 3, 3, $tx($lbl), 0, 0, 'L');
      $pdf->SetFont('Times', $st, $size); $pdf->SetXY($mx + 1.5, $y + $titleH + 4.6); $pdf->Cell($cw - 3, 5, $tx($fit($val, $cw - 3)), 0, 0, $align);
      $mx += $cw;
    }
    $y += $headH;
    $pdf->SetFont('Times', 'B', 12.5); $pdf->SetXY($X, $y); $pdf->Cell($W, 6.5, $tx('NÃO É DOCUMENTO FISCAL. NÃO É VÁLIDO COMO GARANTIA DE MERCADORIA'), 1, 0, 'C');
    $y += 6.5;

    // Empresa | Cliente.
    $half = $W / 2;
    $c = $D['company'];
    $leftLines = [[$up($c['name']), 'B']];
    if ($c['doc'] !== '') $leftLines[] = [(strlen(preg_replace('/\D+/', '', $c['doc']) ?? '') === 11 ? 'CPF' : 'CNPJ') . ': ' . $c['doc'], ''];
    if ($c['ie'] !== '') $leftLines[] = ['I.E.: ' . $c['ie'], ''];
    if ($c['street'] !== '') $leftLines[] = [$up($c['street']), ''];
    if ($c['city'] !== '') $leftLines[] = [$up($c['city'] . ($c['uf'] !== '' ? '/' . $c['uf'] : '')), ''];
    if ($c['phones'] !== '') $leftLines[] = [$c['phones'], ''];
    $cu = $D['customer'];
    $lh = 3.7; $bodyH = max(count($leftLines), 5) * $lh + 1.6; $barH = 4.4;
    $pdf->SetFillColor(191, 191, 191);
    $pdf->Rect($X, $y, $W, $barH + $bodyH);
    $pdf->Line($X + $half, $y, $X + $half, $y + $barH + $bodyH);
    $pdf->SetFont('Times', 'B', 8.5);
    $pdf->SetXY($X, $y); $pdf->Cell($half, $barH, $tx(' Empresa:'), 0, 0, 'L', true);
    $pdf->SetXY($X + $half, $y); $pdf->Cell($half, $barH, $tx(' Cliente:'), 0, 0, 'L', true);
    $yy = $y + $barH + .8;
    foreach ($leftLines as [$t, $st]) { $pdf->SetFont('Times', $st, 8.2); $pdf->SetXY($X + 1.5, $yy); $pdf->Cell($half - 3, $lh, $tx($fit($t, $half - 3)), 0, 0, 'L'); $yy += $lh; }
    $kv = function (float $x, float $yy, float $w, string $k, string $v, float $kw = 15.0) use ($pdf, $tx, $fit): void {
      $pdf->SetFont('Times', 'B', 8.2); $pdf->SetXY($x, $yy); $pdf->Cell($kw, 3.7, $tx($k), 0, 0, 'L');
      $pdf->SetFont('Times', '', 8.2); $pdf->Cell($w - $kw, 3.7, $tx($fit($v, $w - $kw)), 0, 0, 'L');
    };
    $rx = $X + $half + 1.5; $rw = $half - 3; $yy = $y + $barH + .8;
    $kv($rx, $yy, $rw, 'Cliente:', $up($cu['name'])); $yy += $lh;
    $kv($rx, $yy, $rw, 'Endereço:', $up($cu['address'])); $yy += $lh;
    $kv($rx, $yy, $rw - 16, 'Cidade:', $up($cu['city'])); $kv($rx + $rw - 16, $yy, 16, 'UF', $up($cu['uf']), 5); $yy += $lh;
    $kv($rx, $yy, $rw / 2, 'CPF/CNPJ:', $cu['doc'], 16); $kv($rx + $rw / 2, $yy, $rw / 2, 'RG/I.E.:', $cu['rg_ie'], 13); $yy += $lh;
    $kv($rx, $yy, $rw / 2, 'Bairro:', $up($cu['district'])); $kv($rx + $rw / 2, $yy, $rw / 2, 'Telefone:', $cu['phone'], 15);
    $y += $barH + $bodyH;

    // Itens.
    $cols = [['Código', 15, 'L'], ['Un', 8, 'L'], ['Descrição/Complemento', 75, 'L'], ['Quantidade', 17, 'C'], ['Vlr. Unit', 16, 'R'], ['Total s/ desc', 17, 'R'], ['% desc', 13, 'R'], ['Vlr Desc', 15, 'R'], ['Total c/ desc', 18, 'R']];
    $itemHead = function () use ($pdf, $tx, &$y, $X, $W, $cols): void {
      $pdf->SetFillColor(191, 191, 191);
      $pdf->SetFont('Times', 'B', 8.5);
      $pdf->SetXY($X, $y); $pdf->Cell($W, 4.4, $tx(' Produtos/Serviços'), 1, 0, 'L', true);
      $y += 4.4;
      $x = $X;
      $pdf->Rect($X, $y, $W, 4.6);
      foreach ($cols as [$lbl, $w, $al]) { $pdf->SetXY($x + .6, $y); $pdf->Cell($w - 1.2, 4.6, $tx($lbl), 0, 0, $al === 'C' ? 'C' : ($al === 'R' ? 'R' : 'L')); $x += $w; }
      $y += 4.6;
    };
    $itemHead();
    foreach ($D['rows'] as $r) {
      $pdf->SetFont('Arial', '', 7.4);
      $descLines = $wrap($up($r['desc']), 75 - 1.2);
      $h = max(4.0, count($descLines) * 3.2 + .8);
      if ($y + $h > $BOTTOM - 6) { $pdf->AddPage(); $y = 8.0; $itemHead(); $pdf->SetFont('Arial', '', 7.4); }
      $vals = [$r['code'], $r['un'], '', $qf($r['qty']), $n2($r['unit']), $n2($r['gross']), $n2($r['pct']), $n2($r['disc']), $n2($r['net'])];
      $x = $X;
      foreach ($cols as $i => [$lbl, $w, $al]) {
        if ($i === 2) {
          $ly = $y + .4;
          foreach ($descLines as $ln) { $pdf->SetXY($x + .6, $ly); $pdf->Cell($w - 1.2, 3.2, $tx($ln), 0, 0, 'L'); $ly += 3.2; }
        } else {
          $pdf->SetXY($x + .6, $y + .4); $pdf->Cell($w - 1.2, 3.2, $tx($fit((string)$vals[$i], $w - 1.2)), 0, 0, $al);
        }
        $x += $w;
      }
      $pdf->Line($X, $y, $X, $y + $h); $pdf->Line($X + $W, $y, $X + $W, $y + $h);
      $y += $h;
      $pdf->Line($X, $y, $X + $W, $y);
    }

    // Totais + recebimento (esquerda) e parcelamento (direita).
    $y += 1.5;
    $leftW = 76.0; $rightX = $X + $leftW + 4; $rightW = $W - $leftW - 4;
    $tot = [['Valor total bruto:', $n2($D['gross']), 'B', 'B'], ['Total de Descontos:', $n2($D['discount']), 'I', 'B'], ['Total de Acréscimos:', $n2($D['addition']), 'I', 'I'], ['Frete:', $n2($D['freight']), 'I', 'I'], ['Valor a pagar:', $n2($D['total']), 'B', 'B']];
    $needed = count($tot) * 4 + 2 + (count($D['payments']) + 1) * 3.4 + 4;
    $instNeed = (count($D['installments']) + 2) * 4;
    if ($y + max($needed, $instNeed) + 30 > $BOTTOM) { $pdf->AddPage(); $y = 8.0; }
    $by = $y;
    $pdf->Rect($X, $y, $leftW, count($tot) * 4 + 2);
    $ty = $y + 1;
    foreach ($tot as [$l, $v, $sl, $sv]) {
      $pdf->SetFont('Times', $sl, 8.8); $pdf->SetXY($X + 2, $ty); $pdf->Cell($leftW / 2, 4, $tx($l), 0, 0, 'L');
      $pdf->SetFont('Times', $sv, 8.8); $pdf->SetXY($X + $leftW / 2, $ty); $pdf->Cell($leftW / 2 - 2, 4, $tx($v), 0, 0, 'R');
      $ty += 4;
    }
    $ly = $y + count($tot) * 4 + 4;
    if ($D['payments']) {
      $pdf->SetFont('Times', 'B', 8.5); $pdf->SetXY($X + 1, $ly); $pdf->Cell(40, 3.6, $tx('Recebimento'), 0, 0, 'L'); $pdf->Cell(19, 3.6, $tx('Valor'), 0, 0, 'R'); $ly += 3.6;
      foreach ($D['payments'] as $p) {
        $pdf->SetFont('Arial', '', 7.4); $pdf->SetXY($X + 1, $ly); $pdf->Cell(40, 3.4, $tx($fit($up($p['label']), 40)), 0, 0, 'L'); $pdf->Cell(19, 3.4, $tx($n2($p['amount'])), 0, 0, 'R'); $ly += 3.4;
      }
    }
    $ry = $y;
    if ($D['installments']) {
      $pdf->SetFont('Times', 'B', 8.5); $pdf->SetXY($rightX, $ry); $pdf->Cell(60, 4, $tx('Parcelamento'), 0, 0, 'L'); $ry += 4.4;
      $iw = [32.0, 20.0, 24.0, 26.0];
      $x = $rightX;
      foreach (['Nº do documento', 'Parcela', 'Vencimento', 'Valor'] as $i => $h) { $pdf->SetFont('Times', 'B', 8.5); $pdf->SetXY($x, $ry); $pdf->Cell($iw[$i], 4.4, $tx($h), 1, 0, $i === 3 ? 'R' : 'C'); $x += $iw[$i]; }
      $ry += 4.4;
      $count = count($D['installments']);
      foreach ($D['installments'] as $k => $in) {
        $num = trim((string)($in['installment'] ?? '')) ?: str_pad((string)($k + 1), 2, '0', STR_PAD_LEFT) . '/' . str_pad((string)$count, 2, '0', STR_PAD_LEFT);
        $doc = trim((string)($in['document_number'] ?? '')) ?: str_pad($D['number'], 6, '0', STR_PAD_LEFT);
        $due = !empty($in['due_date']) ? date('d/m/Y', strtotime((string)$in['due_date'])) : '';
        $x = $rightX;
        foreach ([[$doc, '', 'C'], [$num, '', 'C'], [$due, 'B', 'C'], ['R$ ' . $n2($in['amount'] ?? 0), 'B', 'R']] as $i => [$v, $st, $al]) {
          $pdf->SetFont('Arial', $st, 7.4); $pdf->SetXY($x, $ry); $pdf->Cell($iw[$i], 3.8, $tx($v), 0, 0, $al); $x += $iw[$i];
        }
        $ry += 3.8;
      }
    }
    $y = max($ly, $ry, $by) + 6;

    // Declaração, assinatura, observações | garantia.
    if ($y + 30 > $BOTTOM) { $pdf->AddPage(); $y = 8.0; }
    $pdf->SetFont('Arial', '', 7.8);
    $dy = $y;
    foreach ($wrap('Reconhe(cemos) a exatidão desta duplicata de Venda Mercantil na importância, praça e vencimento indicados.', $leftW) as $ln) { $pdf->SetXY($X, $dy); $pdf->Cell($leftW, 3.4, $tx($ln), 0, 0, 'L'); $dy += 3.4; }
    $dy += 9;
    $pdf->Line($X, $dy, $X + 68, $dy);
    $pdf->SetFont('Times', '', 9); $pdf->SetXY($X, $dy + .5); $pdf->Cell(68, 3.8, $tx($fit($up($D['customer']['name']), 68)), 0, 2, 'L');
    $pdf->SetFont('Times', 'B', 9); $pdf->Cell(68, 3.8, $tx('(ASS POR EXTENSO)'), 0, 0, 'L');
    $dy += 9;
    if ($D['obs'] !== '') {
      $pdf->SetFont('Arial', '', 7.8);
      foreach ($wrap('Observações: ' . $D['obs'], $leftW) as $i => $ln) { if ($dy > $BOTTOM - 4) break; $pdf->SetXY($X, $dy); $pdf->Cell($leftW, 3.4, $tx($ln), 0, 0, 'L'); $dy += 3.4; }
    }
    if ($D['terms'] !== '') {
      $ty = $y;
      $pdf->SetFont('Arial', 'B', 8.3); $pdf->SetXY($rightX, $ty); $pdf->Cell($rightW, 3.8, $tx('GARANTIA E ORIENTAÇÕES'), 0, 0, 'L'); $ty += 4.4;
      $pdf->SetFont('Arial', 'B', 7.8);
      foreach (preg_split('/\R/u', $D['terms']) ?: [] as $para) {
        foreach ($wrap($para, $rightW) as $ln) {
          if ($ty > $BOTTOM - 4) { $pdf->AddPage(); $ty = 8.0; $pdf->SetFont('Arial', 'B', 7.8); }
          $pdf->SetXY($rightX, $ty); $pdf->Cell($rightW, 3.4, $tx($ln), 0, 0, 'L'); $ty += 3.4;
        }
      }
    }

    $content = $pdf->Output('', 'S');
    if (!is_string($content) || !str_starts_with($content, '%PDF')) throw new RuntimeException('Não foi possível gerar o PDF da duplicata.');
    return [
      'content' => $content,
      'filename' => 'pedido-' . preg_replace('/[^0-9A-Za-z_-]+/', '-', $D['number']) . '-duplicata.pdf',
      'mimetype' => 'application/pdf',
      'size' => strlen($content),
      'label' => 'Pedido',
    ];
  }
}
