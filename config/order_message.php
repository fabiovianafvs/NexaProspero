<?php
declare(strict_types=1);

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/company_sequences.php';

if (!function_exists('order_message_column_exists')) {
  function order_message_column_exists(PDO $pdo, string $table, string $column): bool {
    try {
      $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1');
      $st->execute([$table, $column]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('order_message_ensure_product_group_column')) {
  function order_message_ensure_product_group_column(PDO $pdo): bool {
    if (order_message_column_exists($pdo, 'produtos', 'grupo_producao')) return true;
    if (!$pdo->inTransaction()) {
      try {
        $pdo->exec('ALTER TABLE produtos ADD COLUMN grupo_producao VARCHAR(120) NULL AFTER categoria');
      } catch (Throwable $e) {}
    }
    return order_message_column_exists($pdo, 'produtos', 'grupo_producao');
  }
}

if (!function_exists('order_message_money')) {
  function order_message_money(float $value): string {
    return function_exists('money') ? money($value) : ('R$ ' . number_format($value, 2, ',', '.'));
  }
}

if (!function_exists('order_message_qty')) {
  function order_message_qty($value): string {
    $n = (float)$value;
    $out = number_format($n, 2, ',', '.');
    $out = rtrim(rtrim($out, '0'), ',');
    return $out !== '' ? $out : '0';
  }
}

if (!function_exists('order_message_label_pgto')) {
  function order_message_label_pgto(string $value): string {
    return match ($value) {
      'pix' => 'Pix',
      'dinheiro' => 'Dinheiro',
      'debito' => 'Cartão Débito',
      'credito' => 'Cartão Crédito',
      default => trim($value) !== '' ? ucfirst(str_replace('_', ' ', $value)) : '',
    };
  }
}

if (!function_exists('order_message_label_entrega')) {
  function order_message_label_entrega(string $value, array $app = []): string {
    $value = trim($value);
    if ($value === 'retirada') return (string)($app['retirada_label'] ?? 'Retirada no balcão');
    if ($value === 'entrega') return 'Entrega';
    if ($value === 'balcao') return 'Balcão';
    return $value !== '' ? ucfirst(str_replace('_', ' ', $value)) : '';
  }
}

if (!function_exists('order_message_format_scheduled')) {
  function order_message_format_scheduled(array $pedido): string {
    $date = trim((string)($pedido['scheduled_delivery_date'] ?? ''));
    $time = trim((string)($pedido['scheduled_delivery_time'] ?? ''));
    if ($date === '') return '';
    $label = date('d/m/Y', strtotime($date));
    if ($time !== '') $label .= ' ' . substr($time, 0, 5);
    return $label;
  }
}

if (!function_exists('order_message_clean_line')) {
  function order_message_clean_line(string $value): string {
    $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
    $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
    return trim($value);
  }
}

if (!function_exists('order_message_extract_after_known_district')) {
  function order_message_extract_after_known_district(string $text, string $district): string {
    $text = trim($text);
    $district = trim($district);
    if ($text === '' || $district === '') return $text;
    return trim((string)preg_replace('/^' . preg_quote($district, '/') . '\s*/iu', '', $text, 1));
  }
}

if (!function_exists('order_message_clean_address_with_district')) {
  function order_message_clean_address_with_district(string $address, string $district = ''): string {
    $address = order_message_clean_line($address);
    $district = order_message_clean_line($district);
    if ($address === '') return '';
    $streetTypes = '(?:Rua|R\.?|Avenida|Av\.?|Alameda|Travessa|Tv\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
    if ($district !== '') {
      $districtQuoted = preg_quote($district, '/');
      $address = preg_replace('/^Bairro:\s*' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
      $address = preg_replace('/^' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
      if (mb_strtolower(trim($address)) === mb_strtolower($district)) return '';
    }
    $address = preg_replace('/^Bairro:\s*.*?(?=' . $streetTypes . '\b)/iu', '', $address) ?? $address;
    $address = preg_replace('/^Bairro:\s*/iu', '', $address) ?? $address;
    $address = order_message_clean_line($address);
    if ($district !== '' && mb_strtolower($address) === mb_strtolower($district)) return '';
    return $address;
  }
}

if (!function_exists('order_message_normalize_item_text')) {
  function order_message_normalize_item_text(string $value): string {
    $value = str_replace(["\r\n", "\r"], "\n", trim($value));
    $value = preg_replace('/\n{3,}/u', "\n\n", $value) ?: $value;
    return trim($value);
  }
}

if (!function_exists('order_message_detect_item_obs_col')) {
  function order_message_detect_item_obs_col(array $itens): ?string {
    if (!$itens || !is_array($itens[0] ?? null)) return null;
    $candidates = ['complemento','observacao','obs','observacoes','adicionais','observacao_item'];
    foreach (array_keys($itens[0]) as $key) {
      foreach ($candidates as $candidate) {
        if (mb_strtolower((string)$key) === mb_strtolower($candidate)) return (string)$key;
      }
    }
    return null;
  }
}

if (!function_exists('order_message_split_order_notes')) {
  function order_message_split_order_notes(string $raw): array {
    $raw = trim($raw);
    if ($raw === '') return ['', [], []];
    $parts = preg_split('/(?:^|\R+)Obs\.? por item:\R+/u', $raw, 2);
    $pedidoObs = trim((string)($parts[0] ?? ''));
    $itemMap = [];
    $itemQueue = [];
    if (!empty($parts[1])) {
      foreach (preg_split('/\R/u', trim((string)$parts[1])) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (preg_match('/^[-•]\s*(.+?)\s*:\s*(.+)$/u', $line, $m)) {
          $name = trim($m[1]);
          $note = trim($m[2]);
          $itemMap[$name][] = $note;
          $itemQueue[] = $note;
        }
      }
    }
    return [$pedidoObs, $itemMap, $itemQueue];
  }
}

if (!function_exists('order_message_dequeue_note')) {
  function order_message_dequeue_note(array &$map, array &$queue, string $name): string {
    $name = trim($name);
    if ($name !== '' && isset($map[$name]) && $map[$name]) {
      return (string)array_shift($map[$name]);
    }
    return $queue ? (string)array_shift($queue) : '';
  }
}

if (!function_exists('order_message_split_address')) {
  function order_message_split_address(array $pedido): array {
    $bairro = trim((string)($pedido['bairro'] ?? ''));
    $referencia = trim((string)($pedido['referencia'] ?? ''));
    $cidade = trim((string)($pedido['cidade'] ?? $pedido['city'] ?? ''));
    $uf = trim((string)($pedido['uf'] ?? $pedido['state'] ?? ''));
    $cep = preg_replace('/\D+/', '', (string)($pedido['zip_code'] ?? $pedido['cep'] ?? ''));
    if ($cep !== '') $cep = preg_replace('/(\d{5})(\d{3})$/', '$1-$2', $cep);

    $lines = [];
    $endereco = str_replace(["\r\n", "\r"], "\n", trim((string)($pedido['endereco'] ?? '')));
    foreach (preg_split('/\n/u', $endereco) as $line) {
      $line = trim((string)$line);
      if ($line === '') continue;
      if (preg_match('/^Bairro:\s*(.+)$/iu', $line, $m)) {
        $bairroLinha = trim((string)$m[1]);
        $resto = $bairro !== '' ? order_message_extract_after_known_district($bairroLinha, $bairro) : '';
        if ($bairro === '') {
          $bairro = trim((string)preg_replace('/\s+-\s+.*$/u', '', $bairroLinha));
        }
        if ($resto !== '' && !preg_match('/^[\-–—,\s]+$/u', $resto)) {
          if (!preg_match('/^\s*[\-–—]\s*[\p{L}\s]+\/[A-Z]{2}\s*$/u', $resto)) {
            $resto = trim((string)preg_replace('/^[\-–—,\s]+/u', '', $resto));
            if ($resto !== '') $lines[] = $resto;
          }
        }
        continue;
      }
      if (preg_match('/^(?:Ref\.?|Refer[eê]ncia):\s*(.+)$/iu', $line, $m)) {
        if ($referencia === '') $referencia = trim((string)$m[1]);
        continue;
      }
      $lines[] = $line;
    }

    $enderecoLimpo = order_message_clean_address_with_district(trim(implode("\n", $lines)), $bairro);
    $numero = trim((string)($pedido['numero'] ?? $pedido['number'] ?? ''));
    $complemento = trim((string)($pedido['complemento'] ?? $pedido['complement'] ?? ''));
    $oneLine = order_message_clean_line($enderecoLimpo);
    $norm = mb_strtolower($oneLine);
    if ($numero !== '' && !str_contains($norm, mb_strtolower($numero))) $oneLine = trim($oneLine . ($oneLine !== '' ? ', ' : '') . $numero);
    if ($complemento !== '' && !str_contains(mb_strtolower($oneLine), mb_strtolower($complemento))) $oneLine = trim($oneLine . ($oneLine !== '' ? ' - ' : '') . $complemento);

    return [$bairro, $oneLine, $referencia, $cidade, $uf, $cep];
  }
}

if (!function_exists('order_message_fill_missing_order_address_from_customer')) {
  function order_message_fill_missing_order_address_from_customer(PDO $pdo, int $companyId, array $pedido): array {
    $hasAddress = trim((string)($pedido['endereco'] ?? '')) !== '';
    $hasDistrict = trim((string)($pedido['bairro'] ?? '')) !== '';
    $hasZip = preg_replace('/\D+/', '', (string)($pedido['zip_code'] ?? $pedido['cep'] ?? '')) !== '';
    if ($hasAddress && $hasDistrict && $hasZip && trim((string)($pedido['whatsapp'] ?? '')) !== '') return $pedido;

    $personId = (int)($pedido['finance_person_id'] ?? $pedido['customer_id'] ?? 0);
    if ($personId <= 0) return $pedido;

    $addr = null;
    try {
      $st = $pdo->prepare('SELECT * FROM gestao_customer_addresses WHERE company_id = ? AND person_id = ? AND active = 1 ORDER BY is_default DESC, id ASC LIMIT 1');
      $st->execute([$companyId, $personId]);
      $addr = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    $person = null;
    try {
      $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1');
      $st->execute([$companyId, $personId]);
      $person = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {}

    $src = $addr ?: $person;
    if (!$src) return $pedido;

    if (trim((string)($pedido['endereco'] ?? '')) === '') $pedido['endereco'] = order_message_clean_address_with_district((string)($src['address'] ?? ''), (string)($src['district'] ?? $pedido['bairro'] ?? ''));
    if (trim((string)($pedido['bairro'] ?? '')) === '') $pedido['bairro'] = trim((string)($src['district'] ?? ''));
    if (trim((string)($pedido['zip_code'] ?? '')) === '' && trim((string)($pedido['cep'] ?? '')) === '') $pedido['zip_code'] = preg_replace('/\D+/', '', (string)($src['zip_code'] ?? ''));
    if (trim((string)($pedido['numero'] ?? '')) === '') $pedido['numero'] = trim((string)($src['number'] ?? ''));
    if (trim((string)($pedido['complemento'] ?? '')) === '') $pedido['complemento'] = trim((string)($src['complement'] ?? ''));
    if (trim((string)($pedido['referencia'] ?? '')) === '') $pedido['referencia'] = trim((string)($src['reference'] ?? $src['referencia'] ?? ''));
    if (trim((string)($pedido['cidade'] ?? '')) === '') $pedido['cidade'] = trim((string)($src['city'] ?? ''));
    if (trim((string)($pedido['uf'] ?? '')) === '') $pedido['uf'] = trim((string)($src['state'] ?? ''));
    if (trim((string)($pedido['whatsapp'] ?? '')) === '' && $person) $pedido['whatsapp'] = trim((string)($person['whatsapp'] ?? $person['phone'] ?? ''));
    return $pedido;
  }
}

if (!function_exists('order_message_load')) {
  function order_message_load(PDO $pdo, int $companyId, int $pedidoId): ?array {
    if ($pedidoId <= 0) return null;
    try { company_sequence_ensure_schema($pdo, true); } catch (Throwable $e) {}

    $st = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$pedidoId, $companyId]);
    $pedido = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$pedido) return null;
    $pedido = order_message_fill_missing_order_address_from_customer($pdo, $companyId, $pedido);

    $hasGrupoProducao = order_message_ensure_product_group_column($pdo);
    $grupoProducaoSelect = $hasGrupoProducao
      ? 'p.grupo_producao AS produto_grupo_producao'
      : "'' AS produto_grupo_producao";

    $sti = $pdo->prepare("
      SELECT pi.*, p.nome, p.categoria AS produto_categoria, {$grupoProducaoSelect}
      FROM pedido_itens pi
      LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id
      WHERE pi.pedido_id = ? AND pi.company_id = ?
      ORDER BY pi.id ASC
    ");
    $sti->execute([$pedidoId, $companyId]);
    $itens = $sti->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return ['pedido' => $pedido, 'itens' => $itens];
  }
}

if (!function_exists('order_message_build_full')) {
  function order_message_build_full(array $pedido, array $itens, array $app = [], ?array $waCfg = null): string {
    $pedidoNumero = function_exists('company_sequence_display') ? company_sequence_display($pedido, 'pedidos') : (int)($pedido['id'] ?? 0);
    $itemObsCol = order_message_detect_item_obs_col($itens);
    $pedidoObsCol = null;
    foreach (['observacoes','observacao','obs'] as $c) {
      if (array_key_exists($c, $pedido)) { $pedidoObsCol = $c; break; }
    }
    [$obsPedido, $fallbackMap, $fallbackQueue] = order_message_split_order_notes((string)($pedidoObsCol ? ($pedido[$pedidoObsCol] ?? '') : ''));

    $itemLines = [];
    foreach ($itens as $i) {
      $nome = trim((string)($i['nome'] ?? ''));
      if ($nome === '') $nome = '#' . (int)($i['produto_id'] ?? 0);
      $qtd = order_message_qty($i['qtd'] ?? 1);
      $total = order_message_money((float)($i['total'] ?? 0));
      $itemLines[] = '- ' . $qtd . 'x ' . $nome . ' - ' . $total;

      $obsItem = '';
      if ($itemObsCol) $obsItem = order_message_normalize_item_text((string)($i[$itemObsCol] ?? ''));
      if ($obsItem === '') $obsItem = order_message_dequeue_note($fallbackMap, $fallbackQueue, $nome);
      $obsItem = order_message_normalize_item_text($obsItem);
      if ($obsItem !== '') {
        foreach (preg_split('/\R/u', $obsItem) as $line) {
          $line = trim((string)$line);
          if ($line !== '') $itemLines[] = '  ' . $line;
        }
      }
    }

    [$bairro, $endereco, $referencia, $cidade, $uf, $cep] = order_message_split_address($pedido);
    $scheduled = order_message_format_scheduled($pedido);
    $subtotal = (float)($pedido['total_produtos'] ?? max(0, (float)($pedido['total'] ?? 0) - (float)($pedido['taxa_entrega'] ?? 0)));
    $taxa = (float)($pedido['taxa_entrega'] ?? 0);
    $totalGeral = (float)($pedido['total'] ?? ($subtotal + $taxa));
    $tipoEntrega = order_message_label_entrega((string)($pedido['tipo_entrega'] ?? ''), $app);
    $formaPgto = order_message_label_pgto((string)($pedido['forma_pagamento'] ?? ''));

    $loja = trim((string)($app['site_name'] ?? ($app['brand']['catalog_title'] ?? '')));
    if ($loja === '') $loja = 'Nossa loja';

    $waCfg = $waCfg ?: ['templates' => whatsapp_default_templates()];
    $template = whatsapp_template_from_config($waCfg, 'order_customer_full');
    $pixLinha = whatsapp_pix_line_for_payment((string)($pedido['forma_pagamento'] ?? $formaPgto), $waCfg);
    $pixDados = $pixLinha !== '' ? trim((string)($waCfg['pix']['details'] ?? '')) : '';
    return whatsapp_render_order_template($template, [
      'pedido_id' => (string)$pedidoNumero,
      'loja' => $loja,
      'cliente' => trim((string)($pedido['nome_cliente'] ?? '')) ?: 'Consumidor final',
      'whatsapp' => trim((string)($pedido['whatsapp'] ?? '')),
      'whatsapp_linha' => whatsapp_optional_line('📱 WhatsApp: ', trim((string)($pedido['whatsapp'] ?? ''))),
      'itens' => implode("\n", $itemLines),
      'subtotal' => order_message_money($subtotal),
      'taxa_entrega' => order_message_money($taxa),
      'taxa_entrega_linha' => $taxa > 0 ? '🛵 Taxa de entrega: ' . order_message_money($taxa) : '',
      'total' => order_message_money($totalGeral),
      'tipo_entrega' => $tipoEntrega,
      'pagamento' => $formaPgto,
      'pix_linha' => $pixLinha,
      'pix_dados' => $pixDados,
      'data_hora_prevista' => $scheduled,
      'data_hora_prevista_linha' => whatsapp_optional_line('📅 Data/Hora Prevista: ', $scheduled),
      'bairro' => $bairro,
      'bairro_linha' => whatsapp_optional_line('📍 Bairro: ', $bairro),
      'cep' => $cep,
      'cep_linha' => whatsapp_optional_line('📮 CEP: ', $cep),
      'endereco' => $endereco,
      'endereco_linha' => whatsapp_optional_line('🏠 Endereço: ', trim($endereco)),
      'referencia' => $referencia,
      'referencia_linha' => whatsapp_optional_line('📌 Referência: ', $referencia),
      'cidade' => $cidade,
      'uf' => $uf,
      'observacoes' => $obsPedido,
      'observacoes_linha' => whatsapp_optional_line('📝 Observação do pedido: ', $obsPedido),
    ]);
  }
}

if (!function_exists('order_message_whatsapp_url')) {
  function order_message_whatsapp_url(array $pedido, string $message): string {
    $destino = whatsapp_normalize_phone((string)($pedido['whatsapp'] ?? ''));
    if ($destino === '') return '';
    return 'https://wa.me/' . rawurlencode($destino) . '?text=' . rawurlencode($message);
  }
}
