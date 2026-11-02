<?php
declare(strict_types=1);

require_once __DIR__ . '/company_sequences.php';

if (!function_exists('order_email_pdf_text')) {
  function order_email_pdf_text(string $value): string {
    $out = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $value);
    return $out !== false ? $out : (preg_replace('/[^\x20-\x7E]/', '?', $value) ?? $value);
  }
}

if (!function_exists('order_email_pdf_money')) {
  function order_email_pdf_money($value): string {
    return 'R$ ' . number_format((float)$value, 2, ',', '.');
  }
}

if (!function_exists('order_email_pdf_qty')) {
  function order_email_pdf_qty($value): string {
    $out = number_format((float)$value, 4, ',', '.');
    $out = rtrim(rtrim($out, '0'), ',');
    return $out !== '' ? $out : '0';
  }
}

if (!function_exists('order_email_pdf_first_number')) {
  function order_email_pdf_first_number(array $row, array $keys): float {
    foreach ($keys as $key) {
      if (array_key_exists($key, $row) && is_numeric($row[$key])) return (float)$row[$key];
    }
    return 0.0;
  }
}

if (!function_exists('order_email_pdf_unit_price')) {
  function order_email_pdf_unit_price(array $item): float {
    foreach (['preco_unit','preco_unitario','valor_unitario','unitario','preco','valor'] as $key) {
      if (array_key_exists($key, $item) && is_numeric($item[$key])) return (float)$item[$key];
    }
    $qty = (float)($item['qtd'] ?? 0);
    $total = (float)($item['total'] ?? 0);
    return $qty > 0 ? round($total / $qty, 4) : $total;
  }
}

if (!function_exists('order_email_pdf_company_doc')) {
  function order_email_pdf_company_doc(array $company): string {
    foreach (['cnpj','cpf','document'] as $key) {
      $value = trim((string)($company[$key] ?? ''));
      if ($value !== '') return $value;
    }
    return '';
  }
}

if (!function_exists('order_email_pdf_company_address')) {
  function order_email_pdf_company_address(array $company): string {
    $state = trim((string)($company['estado'] ?? $company['state'] ?? $company['uf'] ?? ''));
    $city = trim((string)($company['cidade'] ?? $company['city'] ?? ''));
    if ($city !== '' && $state !== '') $city .= '/' . $state;
    $parts = [
      trim((string)($company['endereco'] ?? $company['address'] ?? '')),
      trim((string)($company['numero'] ?? $company['number'] ?? '')),
      trim((string)($company['bairro'] ?? $company['district'] ?? '')),
      $city,
    ];
    return trim(implode(' - ', array_values(array_filter($parts, static fn($v) => $v !== ''))));
  }
}

if (!function_exists('order_email_pdf_detect_item_obs_col')) {
  function order_email_pdf_detect_item_obs_col(array $items): ?string {
    if (!$items || !is_array($items[0] ?? null)) return null;
    foreach (array_keys($items[0]) as $key) {
      foreach (['complemento','observacao','obs','observacoes','adicionais','observacao_item'] as $candidate) {
        if (mb_strtolower((string)$key) === mb_strtolower($candidate)) return (string)$key;
      }
    }
    return null;
  }
}

if (!function_exists('order_email_pdf_normalize_item_note')) {
  function order_email_pdf_normalize_item_note(string $text): string {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return '';
    $text = preg_replace('/\s+(Pizza\s+meio\s+a\s+meio\s*:)/iu', "\n$1", $text) ?: $text;
    $text = preg_replace('/\s+(1\/2\s+)/u', "\n$1", $text) ?: $text;
    $text = preg_replace('/(?<=[a-záéíóúâêôãõç])(?=[A-ZÁÉÍÓÚÂÊÔÃÕÇ])/u', "\n", $text) ?: $text;
    $text = preg_replace('/\s*\+\s*R\$\s*/u', ' + R$ ', $text) ?: $text;
    return trim($text);
  }
}

if (!function_exists('order_email_pdf_split_order_notes')) {
  function order_email_pdf_split_order_notes(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return ['', [], []];
    $parts = preg_split('/(?:^|\R+)Obs\.? por item:\R+/u', $raw, 2);
    $orderNote = trim((string)($parts[0] ?? ''));
    $queues = [];
    $queue = [];
    $lastName = null;
    if (!empty($parts[1])) {
      foreach (preg_split('/\R/u', trim((string)$parts[1])) as $line) {
        $line = trim((string)$line);
        if ($line === '') continue;
        if (preg_match('/^[-•]\s*(.+?)\s*:\s*(.+)$/u', $line, $m)) {
          $name = trim((string)$m[1]);
          $note = trim((string)$m[2]);
          if ($name === '' || $note === '') continue;
          $queues[$name][] = $note;
          $queue[] = ['name' => $name, 'note' => $note];
          $lastName = $name;
          continue;
        }
        if ($lastName !== null && isset($queues[$lastName]) && $queues[$lastName]) {
          $idx = count($queues[$lastName]) - 1;
          $queues[$lastName][$idx] .= "\n" . $line;
          $qidx = count($queue) - 1;
          if ($qidx >= 0 && ($queue[$qidx]['name'] ?? '') === $lastName) $queue[$qidx]['note'] .= "\n" . $line;
        }
      }
    }
    return [$orderNote, $queues, $queue];
  }
}

if (!function_exists('order_email_pdf_dequeue_note')) {
  function order_email_pdf_dequeue_note(array &$queues, array &$queue, string $name): string {
    if (isset($queues[$name]) && !empty($queues[$name])) {
      $note = (string)array_shift($queues[$name]);
      foreach ($queue as $idx => $entry) {
        if (($entry['name'] ?? '') === $name && ($entry['note'] ?? '') === $note) {
          unset($queue[$idx]);
          $queue = array_values($queue);
          break;
        }
      }
      return $note;
    }
    return '';
  }
}

if (!function_exists('order_email_pdf_item_notes')) {
  function order_email_pdf_item_notes(array $items, string $orderRawNotes): array {
    $itemObsCol = order_email_pdf_detect_item_obs_col($items);
    [$orderNote, $queues, $queue] = order_email_pdf_split_order_notes($orderRawNotes);
    $notes = array_fill(0, count($items), '');
    for ($idx = 0; $idx < count($items); $idx++) {
      $direct = $itemObsCol ? trim((string)($items[$idx][$itemObsCol] ?? '')) : '';
      if ($direct !== '') {
        $notes[$idx] = order_email_pdf_normalize_item_note($direct);
        continue;
      }
      $name = (string)($items[$idx]['nome'] ?? ('#'.(int)($items[$idx]['produto_id'] ?? 0)));
      $qty = (float)($items[$idx]['qtd'] ?? 0);
      $nextQty = ($idx + 1 < count($items)) ? (float)($items[$idx + 1]['qtd'] ?? 0) : 0;
      if (abs($qty - 0.5) < 0.0001 && abs($nextQty - 0.5) < 0.0001) {
        $shared = '';
        foreach (['Pizza meio a meio','PIZZA MEIO A MEIO','pizza meio a meio'] as $key) {
          $shared = order_email_pdf_dequeue_note($queues, $queue, $key);
          if ($shared !== '') break;
        }
        if ($shared === '') $shared = order_email_pdf_dequeue_note($queues, $queue, $name);
        if ($shared === '') {
          $name2 = (string)($items[$idx + 1]['nome'] ?? ('#'.(int)($items[$idx + 1]['produto_id'] ?? 0)));
          $shared = order_email_pdf_dequeue_note($queues, $queue, $name2);
        }
        $notes[$idx] = order_email_pdf_normalize_item_note($shared);
        $notes[$idx + 1] = '';
        $idx++;
        continue;
      }
      $notes[$idx] = order_email_pdf_normalize_item_note(order_email_pdf_dequeue_note($queues, $queue, $name));
    }
    return [$orderNote, $notes];
  }
}

if (!function_exists('order_email_pdf_split_address')) {
  function order_email_pdf_split_address(array $order): array {
    if (function_exists('order_message_split_address')) return order_message_split_address($order);
    $district = trim((string)($order['bairro'] ?? ''));
    $address = trim((string)($order['endereco'] ?? ''));
    $reference = trim((string)($order['referencia'] ?? ''));
    $city = trim((string)($order['cidade'] ?? $order['city'] ?? ''));
    $state = trim((string)($order['uf'] ?? $order['state'] ?? ''));
    $zip = preg_replace('/\D+/', '', (string)($order['zip_code'] ?? $order['cep'] ?? ''));
    if ($zip !== '') $zip = preg_replace('/(\d{5})(\d{3})$/', '$1-$2', $zip);
    $number = trim((string)($order['numero'] ?? $order['number'] ?? ''));
    $complement = trim((string)($order['complemento'] ?? $order['complement'] ?? ''));
    $address = preg_replace('/\s+/u', ' ', str_replace(["\r\n","\r","\n"], ' ', $address)) ?: $address;
    if ($number !== '' && !str_contains(mb_strtolower($address), mb_strtolower($number))) $address .= ($address !== '' ? ', ' : '') . $number;
    if ($complement !== '' && !str_contains(mb_strtolower($address), mb_strtolower($complement))) $address .= ($address !== '' ? ' - ' : '') . $complement;
    return [$district, trim($address), $reference, $city, $state, $zip];
  }
}

if (!function_exists('order_email_pdf_payment_label')) {
  function order_email_pdf_payment_label(string $value): string {
    return match (trim($value)) {
      'pix' => 'Pix', 'dinheiro' => 'Dinheiro', 'debito' => 'Cartão Débito', 'credito' => 'Cartão Crédito',
      default => trim($value) !== '' ? ucfirst(str_replace('_', ' ', trim($value))) : 'Não informado',
    };
  }
}

if (!function_exists('order_email_pdf_delivery_label')) {
  function order_email_pdf_delivery_label(string $value, array $app = []): string {
    $value = trim($value);
    if ($value === 'retirada') return (string)($app['retirada_label'] ?? 'Retirada');
    return $value !== '' ? ucfirst(str_replace('_', ' ', $value)) : '';
  }
}

if (!function_exists('order_email_pdf_wrap_count')) {
  function order_email_pdf_wrap_count($pdf, string $text, float $width, float $fontSize, string $style = ''): int {
    $text = str_replace(["\r\n", "\r"], "\n", trim($text));
    if ($text === '') return 0;
    $pdf->SetFont('Arial', $style, $fontSize);
    $count = 0;
    foreach (explode("\n", $text) as $paragraph) {
      $words = preg_split('/\s+/u', trim($paragraph)) ?: [];
      if (!$words) { $count++; continue; }
      $line = '';
      foreach ($words as $word) {
        $candidate = $line === '' ? $word : ($line . ' ' . $word);
        if ($pdf->GetStringWidth(order_email_pdf_text($candidate)) <= $width) $line = $candidate;
        else {
          if ($line !== '') $count++;
          $line = $word;
          while ($pdf->GetStringWidth(order_email_pdf_text($line)) > $width && mb_strlen($line) > 1) {
            $cut = max(1, (int)floor(mb_strlen($line) * ($width / max(0.1, $pdf->GetStringWidth(order_email_pdf_text($line))))));
            $count++;
            $line = mb_substr($line, $cut);
          }
        }
      }
      if ($line !== '') $count++;
    }
    return max(1, $count);
  }
}

if (!function_exists('order_email_pdf_build')) {
  function order_email_pdf_build(array $order, array $items, string $companyName = 'Nexa Próspero', string $model = 'a4', ?PDO $pdo = null, int $companyId = 0): array {
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $fpdfFile = __DIR__ . '/../vendor/nfephp-org/sped-da/src/Legacy/FPDF/Fpdf.php';
      if (is_file($fpdfFile)) require_once $fpdfFile;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) {
      $autoload = __DIR__ . '/../vendor/autoload.php';
      if (is_file($autoload)) require_once $autoload;
    }
    if (!class_exists(\NFePHP\DA\Legacy\FPDF\Fpdf::class)) throw new RuntimeException('Biblioteca de PDF não disponível para gerar o pedido.');

    $model = strtolower(trim($model));
    if (!in_array($model, ['a4','72mm'], true)) $model = 'a4';
    if ($companyId <= 0) $companyId = (int)($order['company_id'] ?? 0);
    $tx = static fn(string $value): string => order_email_pdf_text($value);
    $number = function_exists('company_sequence_display') ? company_sequence_display($order, 'pedidos') : (int)($order['id'] ?? 0);

    $company = [];
    $productCodes = [];
    $internalDeliveryLabel = '';
    $sellerName = '';
    if ($pdo && $companyId > 0) {
      try {
        $st = $pdo->prepare('SELECT * FROM companies WHERE id=? LIMIT 1');
        $st->execute([$companyId]);
        $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      } catch (Throwable $e) {}
      try {
        $ids = array_values(array_unique(array_filter(array_map(static fn($row) => (int)($row['produto_id'] ?? 0), $items), static fn($id) => $id > 0)));
        if ($ids) {
          $hasNumber = false; $hasLegacy = false;
          try {
            $stCol = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='produtos' AND COLUMN_NAME IN ('numero_cadastro','codigo_firebird')");
            $stCol->execute();
            foreach ($stCol->fetchAll(PDO::FETCH_COLUMN) ?: [] as $col) {
              if ($col === 'numero_cadastro') $hasNumber = true;
              if ($col === 'codigo_firebird') $hasLegacy = true;
            }
          } catch (Throwable $e) {}
          $expr = 'CAST(id AS CHAR)';
          if ($hasNumber && $hasLegacy) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
          elseif ($hasNumber) $expr = "COALESCE(NULLIF(TRIM(numero_cadastro),''),CAST(id AS CHAR))";
          elseif ($hasLegacy) $expr = "COALESCE(NULLIF(TRIM(codigo_firebird),''),CAST(id AS CHAR))";
          $marks = implode(',', array_fill(0, count($ids), '?'));
          $st = $pdo->prepare('SELECT id,' . $expr . ' AS display_code FROM produtos WHERE company_id=? AND id IN (' . $marks . ')');
          $st->execute(array_merge([$companyId], $ids));
          foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) $productCodes[(int)$row['id']] = (string)$row['display_code'];
        }
      } catch (Throwable $e) {}
      $internalId = (int)($order['internal_delivery_status_id'] ?? 0);
      if ($internalId > 0) {
        try {
          $st = $pdo->prepare('SELECT name FROM gestao_internal_delivery_statuses WHERE company_id=? AND id=? LIMIT 1');
          $st->execute([$companyId, $internalId]);
          $internalDeliveryLabel = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
      }
      $sellerId = (int)($order['seller_id'] ?? 0);
      if ($sellerId > 0) {
        try {
          $st = $pdo->prepare('SELECT name FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
          $st->execute([$companyId, $sellerId]);
          $sellerName = trim((string)($st->fetchColumn() ?: ''));
        } catch (Throwable $e) {}
        if ($sellerName === '') $sellerName = 'Vendedor #' . $sellerId;
      }
    }

    $app = [];
    if ($companyId > 0 && function_exists('load_app_config')) {
      try { $app = (array)load_app_config($companyId); } catch (Throwable $e) { $app = []; }
    }
    $displayCompanyName = trim((string)($company['name'] ?? '')) ?: trim($companyName);
    if ($displayCompanyName === '') $displayCompanyName = 'Nexa Próspero';
    $companyDoc = order_email_pdf_company_doc($company);
    $companyAddress = order_email_pdf_company_address($company);
    $companyPhone = trim((string)($company['whatsapp'] ?? ''));

    $logoPath = '';
    if ($companyId > 0 && function_exists('load_business_config')) {
      try {
        $biz = (array)load_business_config($companyId);
        $logo = (array)($biz['logo'] ?? []);
        if (!array_key_exists('enabled', $logo) || !empty($logo['enabled'])) {
          $file = basename(trim((string)($logo['file'] ?? '')));
          if ($file !== '') {
            $candidate = __DIR__ . '/../uploads/logos/' . $file;
            $ext = strtolower(pathinfo($candidate, PATHINFO_EXTENSION));
            if (is_file($candidate) && in_array($ext, ['png','jpg','jpeg'], true)) $logoPath = $candidate;
          }
        }
      } catch (Throwable $e) {}
    }

    $customer = trim((string)($order['nome_cliente'] ?? '')) ?: 'Consumidor final';
    $customerDoc = trim((string)($order['customer_document'] ?? ''));
    $customerPhone = trim((string)($order['whatsapp'] ?? ''));
    [$district, $address, $reference, $city, $state, $zip] = order_email_pdf_split_address($order);
    $customerAddress = trim($address);
    if ($district !== '') $customerAddress .= ($customerAddress !== '' ? ' - ' : '') . $district;
    if ($city !== '') $customerAddress .= ($customerAddress !== '' ? ' - ' : '') . $city . ($state !== '' ? '/' . $state : '');
    if ($zip !== '') $customerAddress .= ($customerAddress !== '' ? ' - ' : '') . 'CEP ' . $zip;

    $created = trim((string)($order['created_at'] ?? ''));
    $createdLabel = ($created !== '' && strtotime($created)) ? date('d/m/Y H:i', strtotime($created)) : '';
    $scheduled = '';
    $scheduledDate = trim((string)($order['scheduled_delivery_date'] ?? ''));
    if ($scheduledDate !== '' && strtotime($scheduledDate)) {
      $scheduled = date('d/m/Y', strtotime($scheduledDate));
      $scheduledTime = trim((string)($order['scheduled_delivery_time'] ?? ''));
      if ($scheduledTime !== '') $scheduled .= ' ' . substr($scheduledTime, 0, 5);
    }
    $deliveryLabel = order_email_pdf_delivery_label((string)($order['tipo_entrega'] ?? ''), $app);
    $paymentLabel = order_email_pdf_payment_label((string)($order['forma_pagamento'] ?? ''));
    $status = trim((string)($order['status'] ?? 'PEDIDO')) ?: 'PEDIDO';

    $rawNotes = '';
    foreach (['observacoes','observacao','obs'] as $col) {
      if (array_key_exists($col, $order)) { $rawNotes = trim((string)($order[$col] ?? '')); break; }
    }
    [$orderNotes, $itemNotes] = order_email_pdf_item_notes($items, $rawNotes);

    $subtotal = (float)($order['total_produtos'] ?? 0);
    if ($subtotal <= 0) foreach ($items as $item) $subtotal += (float)($item['total'] ?? 0);
    $discount = order_email_pdf_first_number($order, ['desconto','discount','discount_value','valor_desconto']);
    $addition = order_email_pdf_first_number($order, ['acrescimo','addition','addition_value','valor_acrescimo']);
    $freight = order_email_pdf_first_number($order, ['frete','freight','freight_value']);
    $deliveryFee = (float)($order['taxa_entrega'] ?? 0);
    if ($freight <= 0 && $deliveryFee > 0) $freight = $deliveryFee;
    $total = (float)($order['total'] ?? ($subtotal + $addition + $freight - $discount));

    $blue = [11,31,91]; $text = [17,24,39]; $muted = [100,116,139]; $line = [203,213,225]; $headerFill = [248,250,252]; $labelColor = [82,97,115];

    if ($model === '72mm') {
      $estimate = 170.0;
      foreach ($items as $idx => $item) {
        $name = trim((string)($item['nome'] ?? $item['produto_nome'] ?? '')) ?: ('#'.(int)($item['produto_id'] ?? 0));
        $note = (string)($itemNotes[$idx] ?? '');
        $estimate += 7.0 + max(0, ceil(mb_strlen($name) / 30) - 1) * 3.4 + max(0, ceil(mb_strlen($note) / 32)) * 3.2;
      }
      if ($orderNotes !== '') $estimate += 12 + ceil(mb_strlen($orderNotes) / 34) * 3.4;
      $estimate = max(220.0, min(1000.0, $estimate));
      $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P', 'mm', [72, $estimate]);
      $pdf->SetMargins(2.5, 2.5, 2.5);
      $pdf->SetAutoPageBreak(false);
      $pdf->AddPage();
      $contentW = 67.0;
      $left = 2.5;

      $topY = $pdf->GetY();
      $companyX = $left;
      if ($logoPath !== '') {
        try { $pdf->Image($logoPath, $left, $topY, 14, 10); $companyX += 16; } catch (Throwable $e) {}
      }
      $companyW = 37.0 - ($companyX - $left);
      $docX = 40.0; $docW = 29.5;
      $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',10);
      $pdf->SetXY($companyX, $topY); $pdf->MultiCell($companyW, 3.7, $tx($displayCompanyName), 0, 'L');
      $cy = $pdf->GetY();
      $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',6.3);
      if ($companyDoc !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 2.8, $tx($companyDoc), 0, 'L'); }
      $pdf->SetTextColor(...$muted);
      if ($companyAddress !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 2.8, $tx($companyAddress), 0, 'L'); }
      if ($companyPhone !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 2.8, $tx($companyPhone), 0, 'L'); }
      $companyBottom = max($cy, $pdf->GetY(), $topY + 10);

      $pdf->SetXY($docX, $topY); $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',13);
      $pdf->Cell($docW, 5.5, $tx('PEDIDO #'.$number), 0, 1, 'R');
      $pdf->SetX($docX); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',7);
      if ($createdLabel !== '') $pdf->Cell($docW, 3.5, $tx($createdLabel), 0, 1, 'R');
      $statusW = min($docW, max(14.0, $pdf->GetStringWidth($tx($status)) + 5));
      $pdf->SetX($docX + $docW - $statusW); $pdf->SetDrawColor(...$line); $pdf->SetFont('Arial','B',6.8);
      $pdf->Cell($statusW, 4, $tx($status), 1, 1, 'C');
      $headerBottom = max($companyBottom, $pdf->GetY()) + 1.2;
      $pdf->SetDrawColor(...$blue); $pdf->SetLineWidth(.55); $pdf->Line($left, $headerBottom, $left+$contentW, $headerBottom); $pdf->SetLineWidth(.2);
      $pdf->SetY($headerBottom + 2);

      $section = static function($pdf, string $title, float $left, float $width) use ($tx, $blue, $line): void {
        $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',8); $pdf->Cell($width, 4, $tx(strtoupper($title)), 0, 1, 'L');
        $y = $pdf->GetY(); $pdf->SetDrawColor(...$line); $pdf->Line($left, $y, $left+$width, $y); $pdf->Ln(1.2);
      };
      $field = static function($pdf, string $label, string $value, float $left, float $width) use ($tx, $labelColor, $text): void {
        if ($value === '') return;
        $pdf->SetX($left); $pdf->SetTextColor(...$labelColor); $pdf->SetFont('Arial','B',6.5); $pdf->Cell($width, 3, $tx($label), 0, 1, 'L');
        $pdf->SetX($left); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',7.4); $pdf->MultiCell($width, 3.4, $tx($value), 0, 'L');
      };

      $section($pdf, 'Cliente', $left, $contentW);
      $field($pdf, 'Nome / Razão social', $customer, $left, $contentW);
      $field($pdf, 'CPF/CNPJ', $customerDoc, $left, $contentW);
      $field($pdf, 'Telefone / WhatsApp', $customerPhone, $left, $contentW);
      $field($pdf, 'Endereço', $customerAddress, $left, $contentW);
      $field($pdf, 'Referência', $reference, $left, $contentW);
      $pdf->Ln(1);

      $section($pdf, 'Atendimento / Entrega', $left, $contentW);
      $field($pdf, 'Vendedor', $sellerName, $left, $contentW);
      $field($pdf, 'Tipo', $deliveryLabel, $left, $contentW);
      $field($pdf, 'Entrega interno', $internalDeliveryLabel, $left, $contentW);
      $field($pdf, 'Pagamento', $paymentLabel, $left, $contentW);
      $field($pdf, 'Previsão', $scheduled, $left, $contentW);
      $pdf->Ln(1);

      $section($pdf, 'Produtos / Serviços', $left, $contentW);
      $descW = 35.0; $qtyW = 14.0; $totalW = 18.0;
      $pdf->SetFillColor(...$headerFill); $pdf->SetTextColor(...$labelColor); $pdf->SetFont('Arial','B',6.6);
      $pdf->Cell($descW, 5, $tx('DESCRIÇÃO'), 0, 0, 'L', true); $pdf->Cell($qtyW, 5, $tx('QTD.'), 0, 0, 'R', true); $pdf->Cell($totalW, 5, $tx('TOTAL'), 0, 1, 'R', true);
      foreach ($items as $idx => $item) {
        $name = trim((string)($item['nome'] ?? $item['produto_nome'] ?? '')) ?: ('#'.(int)($item['produto_id'] ?? 0));
        $note = (string)($itemNotes[$idx] ?? '');
        $nameLines = order_email_pdf_wrap_count($pdf, $name, $descW-.8, 7, 'B');
        $noteLines = $note !== '' ? order_email_pdf_wrap_count($pdf, $note, $descW-.8, 6.2, '') : 0;
        $rowH = max(5.3, $nameLines*3.3 + ($noteLines ? $noteLines*3.0 + .8 : 0));
        $y = $pdf->GetY(); $x = $left;
        $pdf->SetXY($x, $y); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','B',7); $pdf->MultiCell($descW, 3.3, $tx($name), 0, 'L');
        if ($note !== '') { $pdf->SetX($x); $pdf->SetTextColor(...$muted); $pdf->SetFont('Arial','',6.2); $pdf->MultiCell($descW, 3, $tx($note), 0, 'L'); }
        $pdf->SetXY($x+$descW, $y); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',6.8); $pdf->Cell($qtyW, $rowH, $tx(order_email_pdf_qty($item['qtd'] ?? 0)), 0, 0, 'R');
        $pdf->SetFont('Arial','B',6.8); $pdf->Cell($totalW, $rowH, $tx(order_email_pdf_money((float)($item['total'] ?? 0))), 0, 0, 'R');
        $pdf->SetDrawColor(...$line); $pdf->Line($left, $y+$rowH, $left+$contentW, $y+$rowH); $pdf->SetY($y+$rowH+0.4);
      }

      $pdf->Ln(1.5); $totalX = $left; $totalWBox = $contentW;
      $totalLine = static function($pdf, string $label, string $value, bool $grand = false) use ($tx, $blue, $text): void {
        if ($grand) { $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',10); }
        else { $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',7.4); }
        $pdf->Cell(34, $grand ? 5.5 : 3.8, $tx($label), 0, 0, 'L'); $pdf->Cell(33, $grand ? 5.5 : 3.8, $tx($value), 0, 1, 'R');
      };
      $totalLine($pdf, 'Produtos', order_email_pdf_money($subtotal));
      if ($discount > .009) $totalLine($pdf, 'Desconto', '- '.order_email_pdf_money($discount));
      if ($addition > .009) $totalLine($pdf, 'Acréscimo', '+ '.order_email_pdf_money($addition));
      if ($freight > .009) $totalLine($pdf, 'Frete / entrega', '+ '.order_email_pdf_money($freight));
      $pdf->SetDrawColor(...$blue); $pdf->Line($totalX, $pdf->GetY()+.5, $totalX+$totalWBox, $pdf->GetY()+.5); $pdf->Ln(1.2);
      $totalLine($pdf, 'Total', order_email_pdf_money($total), true);

      if ($orderNotes !== '') {
        $pdf->Ln(1.5); $section($pdf, 'Observações', $left, $contentW); $pdf->SetX($left); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',7); $pdf->MultiCell($contentW, 3.3, $tx($orderNotes), 0, 'L');
      }
      $pdf->Ln(3); $pdf->SetDrawColor(148,163,184); $pdf->Line($left, $pdf->GetY(), $left+$contentW, $pdf->GetY()); $pdf->Ln(2);
      $pdf->SetTextColor(...$muted); $pdf->SetFont('Arial','',6.3); $pdf->Cell($contentW, 3.5, $tx('Pedido emitido pelo Nexa Próspero.'), 0, 1, 'C');
    } else {
      $pdf = new \NFePHP\DA\Legacy\FPDF\Fpdf('P', 'mm', 'A4');
      $pdf->SetMargins(18, 18, 18); $pdf->SetAutoPageBreak(true, 18); $pdf->AddPage();
      $contentW = 174.0; $left = 18.0;
      $topY = $pdf->GetY();
      $companyX = $left;
      if ($logoPath !== '') { try { $pdf->Image($logoPath, $left, $topY, 24, 18); $companyX += 28; } catch (Throwable $e) {} }
      $companyW = 96.0 - ($companyX - $left);
      $docX = 124.0; $docW = 68.0;
      $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',15); $pdf->SetXY($companyX, $topY); $pdf->MultiCell($companyW, 5.3, $tx($displayCompanyName), 0, 'L');
      $companyBottom = max($topY+18, $pdf->GetY());
      $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',8.5);
      if ($companyDoc !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 3.8, $tx($companyDoc), 0, 'L'); }
      $pdf->SetTextColor(...$muted); $pdf->SetFont('Arial','',8);
      if ($companyAddress !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 3.6, $tx($companyAddress), 0, 'L'); }
      if ($companyPhone !== '') { $pdf->SetX($companyX); $pdf->MultiCell($companyW, 3.6, $tx($companyPhone), 0, 'L'); }
      $companyBottom = max($companyBottom, $pdf->GetY());

      $pdf->SetXY($docX, $topY); $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',19); $pdf->Cell($docW, 7, $tx('PEDIDO #'.$number), 0, 1, 'R');
      $pdf->SetX($docX); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',9); if ($createdLabel !== '') $pdf->Cell($docW, 4.5, $tx($createdLabel), 0, 1, 'R');
      $statusW = min($docW, max(20.0, $pdf->GetStringWidth($tx($status)) + 8)); $pdf->SetX($docX+$docW-$statusW); $pdf->SetDrawColor(...$line); $pdf->SetFont('Arial','B',8); $pdf->Cell($statusW, 5, $tx($status), 1, 1, 'C');
      $headerBottom = max($companyBottom, $pdf->GetY()) + 2;
      $pdf->SetDrawColor(...$blue); $pdf->SetLineWidth(.7); $pdf->Line($left, $headerBottom, $left+$contentW, $headerBottom); $pdf->SetLineWidth(.2); $pdf->SetY($headerBottom+3);

      $section = static function($pdf, string $title, float $left, float $width) use ($tx, $blue, $line): void {
        $pdf->SetTextColor(...$blue); $pdf->SetFont('Arial','B',9); $pdf->Cell($width, 5, $tx(strtoupper($title)), 0, 1, 'L');
        $y = $pdf->GetY(); $pdf->SetDrawColor(...$line); $pdf->Line($left, $y, $left+$width, $y); $pdf->Ln(1.5);
      };
      $fieldBox = static function($pdf, string $label, string $value, float $x, float $y, float $width) use ($tx, $labelColor, $text): float {
        if ($value === '') return 0.0;
        $pdf->SetXY($x,$y); $pdf->SetTextColor(...$labelColor); $pdf->SetFont('Arial','B',7.3); $pdf->Cell($width, 3.3, $tx($label), 0, 1, 'L');
        $pdf->SetX($x); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',9); $lines = order_email_pdf_wrap_count($pdf, $value, $width, 9, ''); $pdf->MultiCell($width, 4, $tx($value), 0, 'L');
        return 3.3 + max(4.0, $lines*4.0);
      };

      $section($pdf, 'Cliente', $left, $contentW);
      $colW = 81.0; $gap = 12.0; $x2 = $left+$colW+$gap;
      $y = $pdf->GetY(); $h1 = $fieldBox($pdf,'Nome / Razão social',$customer,$left,$y,$colW); $h2 = $fieldBox($pdf,'CPF/CNPJ',$customerDoc,$x2,$y,$colW); $pdf->SetY($y+max($h1,$h2,7.5)+1);
      $y = $pdf->GetY(); $h1 = $fieldBox($pdf,'Telefone / WhatsApp',$customerPhone,$left,$y,$colW); $h2 = $fieldBox($pdf,'Endereço',$customerAddress,$x2,$y,$colW); $pdf->SetY($y+max($h1,$h2,7.5)+1);
      if ($reference !== '') { $y=$pdf->GetY(); $h=$fieldBox($pdf,'Referência',$reference,$left,$y,$contentW); $pdf->SetY($y+$h+1); }

      $section($pdf, 'Atendimento / Entrega', $left, $contentW);
      $fields = [['Vendedor',$sellerName],['Tipo',$deliveryLabel],['Entrega interno',$internalDeliveryLabel],['Pagamento',$paymentLabel],['Previsão',$scheduled]];
      for ($i=0; $i<count($fields); $i+=2) {
        $y=$pdf->GetY(); $a=$fields[$i] ?? ['', '']; $b=$fields[$i+1] ?? ['', ''];
        $h1=$fieldBox($pdf,$a[0],$a[1],$left,$y,$colW); $h2=$fieldBox($pdf,$b[0],$b[1],$x2,$y,$colW); $pdf->SetY($y+max($h1,$h2,7.5)+1);
      }

      $section($pdf, 'Produtos / Serviços', $left, $contentW);
      $codeW=17.0; $descW=108.0; $qtyW=22.0; $totalW=27.0;
      $drawItemsHeader = static function($pdf) use ($tx,$headerFill,$labelColor,$codeW,$descW,$qtyW,$totalW): void {
        $pdf->SetFillColor(...$headerFill); $pdf->SetTextColor(...$labelColor); $pdf->SetFont('Arial','B',8);
        $pdf->Cell($codeW,6,$tx('CÓDIGO'),0,0,'L',true); $pdf->Cell($descW,6,$tx('DESCRIÇÃO'),0,0,'L',true); $pdf->Cell($qtyW,6,$tx('QTD.'),0,0,'R',true); $pdf->Cell($totalW,6,$tx('TOTAL'),0,1,'R',true);
      };
      $drawItemsHeader($pdf);
      foreach ($items as $idx => $item) {
        $pid=(int)($item['produto_id']??0); $code=$productCodes[$pid]??($pid>0?(string)$pid:''); $name=trim((string)($item['nome']??$item['produto_nome']??''))?:('#'.$pid); $note=(string)($itemNotes[$idx]??''); $unit=order_email_pdf_unit_price($item);
        $nameLines=order_email_pdf_wrap_count($pdf,$name,$descW-1,8.5,'B'); $noteLines=$note!==''?order_email_pdf_wrap_count($pdf,$note,$descW-1,7,''):0; $unitLines=1;
        $rowH=max(7.0,$nameLines*4.0 + ($noteLines?$noteLines*3.4+.8:0) + 3.4);
        if ($pdf->GetY()+$rowH > 276) { $pdf->AddPage(); $drawItemsHeader($pdf); }
        $y=$pdf->GetY(); $x=$left;
        $pdf->SetXY($x,$y); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',8.5); $pdf->Cell($codeW,$rowH,$tx($code),0,0,'L');
        $pdf->SetXY($x+$codeW,$y); $pdf->SetFont('Arial','B',8.5); $pdf->MultiCell($descW,4,$tx($name),0,'L');
        if($note!==''){ $pdf->SetX($x+$codeW); $pdf->SetTextColor(...$muted); $pdf->SetFont('Arial','',7); $pdf->MultiCell($descW,3.4,$tx($note),0,'L'); }
        $pdf->SetX($x+$codeW); $pdf->SetTextColor(...$muted); $pdf->SetFont('Arial','',7); $pdf->Cell($descW,3.4,$tx(order_email_pdf_money($unit).' / un.'),0,0,'L');
        $pdf->SetXY($x+$codeW+$descW,$y); $pdf->SetTextColor(...$text); $pdf->SetFont('Arial','',8.5); $pdf->Cell($qtyW,$rowH,$tx(order_email_pdf_qty($item['qtd']??0)),0,0,'R');
        $pdf->SetFont('Arial','B',8.5); $pdf->Cell($totalW,$rowH,$tx(order_email_pdf_money((float)($item['total']??0))),0,0,'R');
        $pdf->SetDrawColor(...$line); $pdf->Line($left,$y+$rowH,$left+$contentW,$y+$rowH); $pdf->SetY($y+$rowH+.5);
      }

      $pdf->Ln(3); $totalsW=75.0; $totalsX=$left+$contentW-$totalsW; $pdf->SetX($totalsX);
      $totalLine = static function($pdf,string $label,string $value,float $x,float $width,bool $grand=false) use($tx,$blue,$text):void{
        $pdf->SetX($x); if($grand){$pdf->SetTextColor(...$blue);$pdf->SetFont('Arial','B',13);$h=7;}else{$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',9);$h=5;}
        $pdf->Cell($width*.55,$h,$tx($label),0,0,'L');$pdf->Cell($width*.45,$h,$tx($value),0,1,'R');
      };
      $totalLine($pdf,'Produtos',order_email_pdf_money($subtotal),$totalsX,$totalsW);
      if($discount>.009)$totalLine($pdf,'Desconto','- '.order_email_pdf_money($discount),$totalsX,$totalsW);
      if($addition>.009)$totalLine($pdf,'Acréscimo','+ '.order_email_pdf_money($addition),$totalsX,$totalsW);
      if($freight>.009)$totalLine($pdf,'Frete / entrega','+ '.order_email_pdf_money($freight),$totalsX,$totalsW);
      $pdf->SetDrawColor(...$blue);$pdf->SetLineWidth(.6);$pdf->Line($totalsX,$pdf->GetY()+1,$totalsX+$totalsW,$pdf->GetY()+1);$pdf->SetLineWidth(.2);$pdf->Ln(2);
      $totalLine($pdf,'Total',order_email_pdf_money($total),$totalsX,$totalsW,true);

      if($orderNotes!==''){
        if($pdf->GetY()>250)$pdf->AddPage();$pdf->Ln(4);$section($pdf,'Observações',$left,$contentW);$pdf->SetX($left);$pdf->SetTextColor(...$text);$pdf->SetFont('Arial','',9);$pdf->MultiCell($contentW,4.2,$tx($orderNotes),0,'L');
      }
      $pdf->Ln(8);$pdf->SetDrawColor(148,163,184);$pdf->Line($left,$pdf->GetY(),$left+$contentW,$pdf->GetY());$pdf->Ln(3);$pdf->SetTextColor(...$muted);$pdf->SetFont('Arial','',8);$pdf->Cell($contentW,4,$tx('Pedido emitido pelo Nexa Próspero.'),0,1,'C');
    }

    $content = $pdf->Output('', 'S');
    return [
      'filename' => 'pedido-' . preg_replace('/[^0-9A-Za-z_-]+/', '-', (string)$number) . '-' . ($model === '72mm' ? '72mm' : 'A4') . '.pdf',
      'mimetype' => 'application/pdf',
      'content' => $content,
    ];
  }
}
