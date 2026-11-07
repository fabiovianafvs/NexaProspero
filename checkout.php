<?php
declare(strict_types=1);
session_start();

require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/config/pdo.php';
require_once __DIR__ . '/config/product_options.php';
require_once __DIR__ . '/config/finance_module.php';
require_once __DIR__ . '/config/gestao_module.php';
require_once __DIR__ . '/config/whatsapp_service.php';
require_once __DIR__ . '/config/firebird_runner.php';
require_once __DIR__ . '/config/company_sequences.php';
require_once __DIR__ . '/config/brain_realtime.php';

$page_title = $app['site_name'] . ' - Finalizar';

$cart = $_SESSION['cart'] ?? [];
$custom = $_SESSION['cart_custom'] ?? [];
$cartObs = $_SESSION['cart_obs'] ?? [];

if (!$cart && !$custom) {
  header("Location: /carrinho.php");
  exit;
}

$pdo = pdo_connect(require __DIR__ . '/config/db.php');
app_apply_company_timezone($pdo, (string)($biz['timezone'] ?? 'America/Sao_Paulo'));

try { finance_ensure_schema($pdo); gestao_ensure_schema($pdo); company_sequence_ensure_schema($pdo, true); } catch (Throwable $e) {}

if (isset($_GET['ajax']) && (string)$_GET['ajax'] === 'customer_lookup') {
  header('Content-Type: application/json; charset=utf-8');
  $term = preg_replace('/\D+/', '', (string)($_GET['term'] ?? ''));
  $out = ['ok' => false, 'customer' => null, 'addresses' => []];
  try {
    if ($term !== '' && strlen($term) >= 8) {
      $sqlDigitsWhatsapp = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''), '+',''), '(', ''), ')',''), '-', ''), ' ', '')";
      $sqlDigitsPhone = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''), '+',''), '(', ''), ')',''), '-', ''), ' ', '')";
      $params = [COMPANY_ID, $term, $term, $term];
      $customer = finance_query_one($pdo, "
        SELECT id, name, fantasy_name, document, person_type, email, phone, whatsapp, zip_code, address, number, complement, district, city, state, birth_opening_date
        FROM finance_people
        WHERE company_id = ?
          AND active = 1
          AND is_customer = 1
          AND (document = ? OR {$sqlDigitsWhatsapp} = ? OR {$sqlDigitsPhone} = ?)
        ORDER BY id DESC
        LIMIT 1
      ", $params);
      if ($customer) {
        $out['ok'] = true;
        $out['customer'] = checkout_sanitize_customer_address_array($customer);
        $addresses = gestao_customer_addresses($pdo, COMPANY_ID, (int)$customer['id']);
        $out['addresses'] = array_map('checkout_sanitize_customer_address_array', is_array($addresses) ? $addresses : []);
      }
    }
  } catch (Throwable $e) {
    $out = ['ok' => false, 'error' => $e->getMessage(), 'customer' => null, 'addresses' => []];
  }
  echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
}

$ids = array_keys($cart);
foreach ($custom as $ci) {
  if (($ci['type'] ?? '') === 'half') {
    $ids[] = (int)($ci['a'] ?? 0);
    $ids[] = (int)($ci['b'] ?? 0);
  } elseif (($ci['type'] ?? '') === 'configured') {
    $ids[] = (int)($ci['id'] ?? 0);
  }
}
$ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

$rows = [];
if ($ids) {
  $in = implode(',', array_fill(0, count($ids), '?'));
  $flagSelect = products_has_column($pdo, 'hide_order_catalog_product')
    ? ', COALESCE(hide_order_catalog_product,0) AS hide_order_catalog_product'
    : ', 0 AS hide_order_catalog_product';
  $st = $pdo->prepare("SELECT id, nome, categoria, preco, imagem{$flagSelect} FROM produtos WHERE company_id = ? AND id IN ($in) AND ativo = 1");
  $st->execute(array_merge([COMPANY_ID], $ids));
  $rows = products_filter_visible_catalog_categories_db($st->fetchAll(PDO::FETCH_ASSOC) ?: [], 'order');
}

$map = [];
foreach ($rows as $r) {
  $map[(int)$r['id']] = $r;
}


/**
 * Consulta leve do estado já carregado no serviço WhatsApp.
 * /sessions não inicializa Chromium/sessão e usa timeout curto, portanto um
 * WhatsApp desconectado não pode segurar a confirmação do pedido por 30s.
 */
function checkout_whatsapp_connected_quick(array $cfg, int $companyId): bool {
  $peekCfg = $cfg;
  $peekCfg['timeout_seconds'] = 2;
  $serviceCompanyId = whatsapp_service_company_id($cfg, $companyId);

  // Serviço atual: consulta leve das sessões já carregadas, sem iniciar Chromium.
  // Versões anteriores do checkout consultavam /sessions, rota que não existe
  // no serviço e retornava HTTP 404, fazendo o envio automático ser ignorado.
  try {
    $list = whatsapp_api_request($peekCfg, 'GET', '/admin/sessions');
    foreach ((array)($list['items'] ?? []) as $item) {
      if (!is_array($item)) continue;
      if ((string)($item['company_id'] ?? '') !== $serviceCompanyId) continue;
      $status = strtolower(trim((string)($item['status'] ?? 'disconnected')));
      return in_array($status, ['connected', 'ready'], true);
    }
    return false;
  } catch (Throwable $sessionsError) {
    // Compatibilidade com instalações do serviço que ainda não possuem
    // /admin/sessions. Usa a mesma rota de status da tela Admin > WhatsApp.
    try {
      $res = whatsapp_api_request(
        $peekCfg,
        'GET',
        '/status?company_id=' . rawurlencode($serviceCompanyId)
      );
      $status = strtolower(trim((string)($res['status'] ?? 'disconnected')));
      return !empty($res['ready'])
        || !empty($res['connected'])
        || in_array($status, ['connected', 'ready'], true);
    } catch (Throwable $statusError) {
      @file_put_contents(
        __DIR__ . '/checkout_error.log',
        '[' . date('c') . '] WhatsApp status rápido: ' . $statusError->getMessage() . PHP_EOL,
        FILE_APPEND
      );
    }
  }

  return false;
}

function label_pgto_checkout(string $v): string {
  return match ($v) {
    'pix' => 'Pix',
    'dinheiro' => 'Dinheiro',
    'debito' => 'Cartão Débito',
    'credito' => 'Cartão Crédito',
    default => $v,
  };
}

function split_order_item_notes_checkout(string $raw): array {
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

function normalize_order_address_checkout(string $bairro, string $endereco): array {
  [$bairro, $enderecoLimpo, $referencia] = checkout_split_order_address($bairro, $endereco);
  return [$bairro, $enderecoLimpo, $referencia];
}

function checkout_extract_after_known_district(string $text, string $district): string {
  $text = trim($text);
  $district = trim($district);
  if ($text === '' || $district === '') return $text;
  $pattern = '/^' . preg_quote($district, '/') . '\s*/iu';
  return trim((string)preg_replace($pattern, '', $text, 1));
}

function checkout_clean_address_with_district(string $address, string $district = ''): string {
  $address = str_replace(["\r\n", "\r", "\n"], ' ', trim($address));
  $address = preg_replace('/\s+/u', ' ', $address) ?: $address;
  $district = str_replace(["\r\n", "\r", "\n"], ' ', trim($district));
  $district = preg_replace('/\s+/u', ' ', $district) ?: $district;
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
  $address = preg_replace('/\s+/u', ' ', trim($address)) ?: $address;
  if ($district !== '' && mb_strtolower(trim($address)) === mb_strtolower($district)) return '';
  return trim($address);
}

function checkout_sanitize_customer_address_array(array $row): array {
  $row['address'] = checkout_clean_address_with_district((string)($row['address'] ?? ''), (string)($row['district'] ?? ''));
  return $row;
}

function checkout_split_order_address(string $bairro, string $endereco): array {
  $bairro = trim($bairro);
  $endereco = str_replace(["\r\n", "\r"], "\n", trim($endereco));
  $referencia = '';
  $linesOut = [];

  foreach (preg_split('/\n/u', $endereco) as $line) {
    $line = trim((string)$line);
    if ($line === '') continue;

    if (preg_match('/^(?:Ref\.?|Refer[eê]ncia):\s*(.+)$/iu', $line, $m)) {
      $referencia = trim((string)$m[1]);
      continue;
    }

    if (preg_match('/^Bairro:\s*(.+)$/iu', $line, $m)) {
      $bairroLinha = trim((string)$m[1]);
      if ($bairroLinha === '') continue;

      // Quando versões anteriores gravaram uma linha única como
      // "Bairro: São PedroTravessa dos Cravos, 10", não podemos descartar
      // tudo depois do bairro, senão o WhatsApp sai sem endereço.
      $resto = '';
      if ($bairro !== '') {
        $resto = checkout_extract_after_known_district($bairroLinha, $bairro);
      }

      if ($bairro === '') {
        // Mantém apenas o bairro quando a linha vem como "Bairro: São Pedro - Cidade/UF".
        $bairro = trim((string)preg_replace('/\s+-\s+.*$/u', '', $bairroLinha));
      }

      // Se sobrou algum texto depois do bairro, ele é parte do endereço.
      if ($resto !== '' && !preg_match('/^[\-–—,\s]+$/u', $resto)) {
        // Se o resto for apenas "- Cidade/UF", não é logradouro.
        if (preg_match('/^\s*[\-–—]\s*[\p{L}\s]+\/[A-Z]{2}\s*$/u', $resto)) {
          continue;
        }
        $resto = trim((string)preg_replace('/^[\-–—,\s]+/u', '', $resto));
        if ($resto !== '') $linesOut[] = $resto;
      }
      continue;
    }

    $linesOut[] = $line;
  }

  $enderecoLimpo = checkout_clean_address_with_district(trim(implode("\n", $linesOut)), $bairro);
  return [$bairro, $enderecoLimpo, $referencia];
}

function checkout_clean_address_line(string $value): string {
  $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
  $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
  return trim($value);
}

function checkout_append_address_part(string $base, string $part, string $separator = ', '): string {
  $base = trim($base);
  $part = trim($part);
  if ($part === '') return $base;
  $baseNorm = mb_strtolower(checkout_clean_address_line($base));
  $partNorm = mb_strtolower(checkout_clean_address_line($part));
  if ($baseNorm !== '' && $partNorm !== '' && str_contains($baseNorm, $partNorm)) return $base;
  return trim($base . ($base !== '' ? $separator : '') . $part);
}


function checkout_digits(string $value): string {
  return preg_replace('/\D+/', '', $value) ?: '';
}

function checkout_valid_cpf(string $cpf): bool {
  $cpf = checkout_digits($cpf);
  if (strlen($cpf) !== 11 || preg_match('/^(\d)\1{10}$/', $cpf)) return false;
  for ($t = 9; $t < 11; $t++) {
    $sum = 0;
    for ($i = 0; $i < $t; $i++) $sum += (int)$cpf[$i] * (($t + 1) - $i);
    $digit = ((10 * $sum) % 11) % 10;
    if ((int)$cpf[$t] !== $digit) return false;
  }
  return true;
}

function checkout_valid_cnpj(string $cnpj): bool {
  $cnpj = checkout_digits($cnpj);
  if (strlen($cnpj) !== 14 || preg_match('/^(\d)\1{13}$/', $cnpj)) return false;
  $weights1 = [5,4,3,2,9,8,7,6,5,4,3,2];
  $weights2 = [6,5,4,3,2,9,8,7,6,5,4,3,2];
  $calc = function(string $base, array $weights): int {
    $sum = 0;
    foreach ($weights as $i => $w) $sum += (int)$base[$i] * $w;
    $rest = $sum % 11;
    return $rest < 2 ? 0 : 11 - $rest;
  };
  return ((int)$cnpj[12] === $calc($cnpj, $weights1)) && ((int)$cnpj[13] === $calc($cnpj, $weights2));
}

function checkout_date_or_null(string $value): ?string {
  $value = trim($value);
  if ($value === '') return null;
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) return $value;
  if (preg_match('/^(\d{2})\/(\d{2})\/(\d{4})$/', $value, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
  return null;
}

function checkout_time_or_null(string $value): ?string {
  $value = trim($value);
  if ($value === '') return null;
  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d$/', $value)) return $value . ':00';
  if (preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d$/', $value)) return $value;
  return null;
}

function checkout_format_scheduled_delivery(string $date, string $time = ''): string {
  $date = trim($date);
  $time = trim($time);
  if ($date === '') return '';
  $label = date('d/m/Y', strtotime($date));
  if ($time !== '') $label .= ' ' . substr($time, 0, 5);
  return $label;
}

function checkout_schedule_day_key(string $date): string {
  $date = trim($date);
  if ($date === '') return '';
  try {
    $dt = new DateTimeImmutable($date);
    $map = ['sun','mon','tue','wed','thu','fri','sat'];
    return $map[(int)$dt->format('w')] ?? '';
  } catch (Throwable $e) {
    return '';
  }
}

function checkout_time_minutes(string $time): ?int {
  $time = trim($time);
  if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d(?::[0-5]\d)?$/', $time)) return null;
  [$h, $m] = array_map('intval', explode(':', substr($time, 0, 5)));
  return ($h * 60) + $m;
}

function checkout_business_hours_label_for_date(array $biz, string $date): string {
  $day = checkout_schedule_day_key($date);
  if ($day === '') return '';
  $hours = (array)($biz['hours'][$day] ?? []);
  $parts = [];
  foreach ($hours as $w) {
    $ini = trim((string)($w[0] ?? ''));
    $fim = trim((string)($w[1] ?? ''));
    if (preg_match('/^\d{2}:\d{2}$/', $ini) && preg_match('/^\d{2}:\d{2}$/', $fim)) {
      $parts[] = $ini . ' às ' . $fim;
    }
  }
  return implode(' ou ', $parts);
}

function checkout_scheduled_delivery_in_business_hours(array $biz, string $date, string $time): bool {
  $day = checkout_schedule_day_key($date);
  $timeMin = checkout_time_minutes($time);
  if ($day === '' || $timeMin === null) return false;

  $hours = (array)($biz['hours'][$day] ?? []);
  foreach ($hours as $w) {
    $ini = trim((string)($w[0] ?? ''));
    $fim = trim((string)($w[1] ?? ''));
    $iniMin = checkout_time_minutes($ini);
    $fimMin = checkout_time_minutes($fim);
    if ($iniMin === null || $fimMin === null) continue;

    if ($fimMin >= $iniMin) {
      if ($timeMin >= $iniMin && $timeMin <= $fimMin) return true;
    } else {
      // Período que passa da meia-noite. Ex.: 18:00 às 02:00.
      if ($timeMin >= $iniMin || $timeMin <= $fimMin) return true;
    }
  }
  return false;
}

function checkout_scheduled_delivery_is_past(array $biz, string $date, string $time): bool {
  try {
    $tz = new DateTimeZone((string)($biz['timezone'] ?? 'America/Sao_Paulo'));
    $scheduled = new DateTimeImmutable($date . ' ' . substr($time, 0, 5) . ':00', $tz);
    $now = new DateTimeImmutable('now', $tz);
    return $scheduled < $now;
  } catch (Throwable $e) {
    return false;
  }
}

function checkout_minutes_label(int $minutes): string {
  $minutes = max(0, $minutes);
  if ($minutes === 1) return '1 minuto';
  if ($minutes > 0 && $minutes % 60 === 0) {
    $hours = (int)($minutes / 60);
    return $hours === 1 ? '1 hora' : $hours . ' horas';
  }
  if ($minutes > 60) {
    $hours = intdiv($minutes, 60);
    $rest = $minutes % 60;
    return $hours . 'h' . str_pad((string)$rest, 2, '0', STR_PAD_LEFT) . 'min';
  }
  return $minutes . ' minutos';
}

function checkout_scheduled_delivery_respects_minimum(array $biz, string $date, string $time, int $minMinutes): bool {
  $minMinutes = max(0, $minMinutes);
  if ($minMinutes <= 0) return true;
  try {
    $tz = new DateTimeZone((string)($biz['timezone'] ?? 'America/Sao_Paulo'));
    $scheduled = new DateTimeImmutable($date . ' ' . substr($time, 0, 5) . ':00', $tz);
    $minimum = (new DateTimeImmutable('now', $tz))->modify('+' . $minMinutes . ' minutes');
    return $scheduled >= $minimum;
  } catch (Throwable $e) {
    return true;
  }
}

function checkout_order_address_text(string $address, string $number, string $complement, string $reference, string $district = '', string $city = '', string $state = ''): string {
  // Salva apenas logradouro/número/complemento no campo endereço.
  // Bairro, cidade, UF, CEP e referência possuem campos próprios e são impressos/enviados separadamente.
  $line = checkout_clean_address_with_district($address, $district);
  $line = checkout_clean_address_line($line);
  $line = checkout_append_address_part($line, $number, ', ');
  $line = checkout_append_address_part($line, $complement, ' - ');
  return trim($line);
}

function checkout_upsert_customer(PDO $pdo, int $companyId, array $data, bool $saveAddress = false, bool $makeDefault = false): int {
  $document = checkout_digits((string)($data['document'] ?? ''));
  if ($document === '') return 0;

  $name = trim((string)($data['name'] ?? '')) ?: 'Consumidor final';
  $personType = strtoupper(trim((string)($data['person_type'] ?? 'FISICA'))) === 'JURIDICA' ? 'JURIDICA' : 'FISICA';
  $fantasyName = $personType === 'JURIDICA' ? (trim((string)($data['fantasy_name'] ?? '')) ?: null) : null;
  $phone = checkout_digits((string)($data['phone'] ?? ''));
  $whatsapp = checkout_digits((string)($data['whatsapp'] ?? '')) ?: $phone;
  $birthOpeningDate = checkout_date_or_null((string)($data['birth_opening_date'] ?? ''));

  $existing = finance_query_one($pdo, 'SELECT id FROM finance_people WHERE company_id = ? AND document = ? AND active = 1 LIMIT 1', [$companyId, $document]);
  if ($existing) {
    $personId = (int)$existing['id'];
    $pdo->prepare('UPDATE finance_people SET is_customer = 1, name = ?, fantasy_name = ?, person_type = ?, birth_opening_date = ?, email = ?, phone = ?, whatsapp = ?, zip_code = ?, address = ?, number = ?, complement = ?, district = ?, city = ?, state = ?, updated_at = NOW() WHERE company_id = ? AND id = ?')->execute([
      $name,
      $fantasyName,
      $personType,
      $birthOpeningDate,
      trim((string)($data['email'] ?? '')) ?: null,
      $phone ?: null,
      $whatsapp ?: null,
      checkout_digits((string)($data['zip_code'] ?? '')) ?: null,
      trim((string)($data['address'] ?? '')) ?: null,
      trim((string)($data['number'] ?? '')) ?: null,
      trim((string)($data['complement'] ?? '')) ?: null,
      trim((string)($data['district'] ?? '')) ?: null,
      trim((string)($data['city'] ?? '')) ?: null,
      strtoupper(substr(trim((string)($data['state'] ?? '')), 0, 2)) ?: null,
      $companyId,
      $personId,
    ]);
  } else {
    if (!$pdo->inTransaction()) company_sequence_ensure_schema($pdo, false);
    $cols = ['company_id'];
    $vals = ['?'];
    $params = [$companyId];
    if (company_sequence_has_column($pdo, 'pessoas')) {
      $cols[] = 'numero_cadastro';
      $vals[] = '?';
      $params[] = company_sequence_next($pdo, $companyId, 'pessoas');
    }
    $insertData = [
      'name' => $name,
      'fantasy_name' => $fantasyName,
      'document' => $document,
      'person_type' => $personType,
      'birth_opening_date' => $birthOpeningDate,
      'email' => trim((string)($data['email'] ?? '')) ?: null,
      'phone' => $phone ?: null,
      'whatsapp' => $whatsapp ?: null,
      'zip_code' => checkout_digits((string)($data['zip_code'] ?? '')) ?: null,
      'address' => trim((string)($data['address'] ?? '')) ?: null,
      'number' => trim((string)($data['number'] ?? '')) ?: null,
      'complement' => trim((string)($data['complement'] ?? '')) ?: null,
      'district' => trim((string)($data['district'] ?? '')) ?: null,
      'city' => trim((string)($data['city'] ?? '')) ?: null,
      'state' => strtoupper(substr(trim((string)($data['state'] ?? '')), 0, 2)) ?: null,
      'is_customer' => 1,
      'active' => 1,
    ];
    foreach ($insertData as $col => $value) {
      $cols[] = $col;
      $vals[] = '?';
      $params[] = $value;
    }
    $st = $pdo->prepare('INSERT INTO finance_people (' . implode(', ', $cols) . ') VALUES (' . implode(', ', $vals) . ')');
    $st->execute($params);
    $personId = (int)$pdo->lastInsertId();
    $saveAddress = true;
    $makeDefault = true;
  }

  if ($saveAddress && (trim((string)($data['address'] ?? '')) !== '' || trim((string)($data['district'] ?? '')) !== '')) {
    gestao_create_customer_address($pdo, $companyId, $personId, [
      'label' => trim((string)($data['label'] ?? '')) ?: 'Entrega',
      'zip_code' => $data['zip_code'] ?? '',
      'address' => $data['address'] ?? '',
      'number' => $data['number'] ?? '',
      'complement' => $data['complement'] ?? '',
      'reference' => $data['reference'] ?? $data['referencia'] ?? '',
      'district' => $data['district'] ?? '',
      'city' => $data['city'] ?? '',
      'state' => $data['state'] ?? '',
    ], $makeDefault);
  }

  return $personId;
}

function checkout_ensure_order_schema(PDO $pdo): void {
  try { $pdo->exec("ALTER TABLE finance_people ADD COLUMN birth_opening_date DATE NULL AFTER person_type"); } catch (Throwable $e) {}
  // Evita erro ao gravar telefone com DDD e mantém espaço para formatos futuros.
  try { $pdo->exec("ALTER TABLE pedidos MODIFY COLUMN whatsapp VARCHAR(30) NULL"); } catch (Throwable $e) {}

  // Garante coluna própria para adicionais/observações por item.
  try { $pdo->exec("ALTER TABLE pedido_itens ADD COLUMN complemento TEXT NULL AFTER total"); } catch (Throwable $e) {}

  foreach ([
    'customer_id INT NULL',
    'customer_document VARCHAR(30) NULL',
    'zip_code VARCHAR(20) NULL',
    'numero VARCHAR(30) NULL',
    'complemento VARCHAR(160) NULL',
    'referencia VARCHAR(200) NULL',
    'cidade VARCHAR(120) NULL',
    'uf VARCHAR(2) NULL',
    'scheduled_delivery_date DATE NULL',
    'scheduled_delivery_time TIME NULL'
  ] as $def) {
    try { $pdo->exec("ALTER TABLE pedidos ADD COLUMN {$def}"); } catch (Throwable $e) {}
  }

  // Se a coluna já existia pequena (VARCHAR), amplia para TEXT.
  foreach (['complemento','observacao','obs','observacoes'] as $col) {
    try { $pdo->exec("ALTER TABLE pedido_itens MODIFY COLUMN {$col} TEXT NULL"); } catch (Throwable $e) {}
  }
}

function checkout_detect_column(PDO $pdo, string $table, array $columns): ?string {
  try {
    $placeholders = implode(',', array_fill(0, count($columns), '?'));
    $params = array_merge([$table], $columns);
    $q = $pdo->prepare("
      SELECT COLUMN_NAME
      FROM INFORMATION_SCHEMA.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND TABLE_NAME = ?
        AND COLUMN_NAME IN ({$placeholders})
      ORDER BY FIELD(COLUMN_NAME, {$placeholders})
      LIMIT 1
    ");
    $q->execute(array_merge($params, $columns));
    $col = $q ? (string)$q->fetchColumn() : '';
    return $col !== '' ? $col : null;
  } catch (Throwable $e) {
    return null;
  }
}

function montar_whatsapp_pedido_checkout(int $pedidoId, array $itens, float $subtotal, float $taxaEntrega, float $totalGeral, string $tipoEntrega, string $formaPgto, string $bairro, string $endereco, string $zipCode, string $nome, string $whatsapp, array $app, string $scheduledDeliveryDate = '', string $scheduledDeliveryTime = '', ?array $waCfg = null, string $obsPedido = ''): string {
  $retiradaLabel = (string)($app['retirada_label'] ?? 'Retirada no balcão');
  $tipoEntregaLabel = $tipoEntrega === 'retirada' ? $retiradaLabel : 'Entrega';

  $itemLines = [];
  foreach ($itens as $i) {
    if (($i['type'] ?? 'simple') === 'half') {
      $itemLines[] = '- ' . rtrim(rtrim(number_format((float)($i['qtd'] ?? 1), 2, ',', '.'), '0'), ',') . 'x Pizza Meio a Meio';
      $itemLines[] = '  1/2 ' . (string)($i['sabor_a'] ?? '');
      $itemLines[] = '  1/2 ' . (string)($i['sabor_b'] ?? '');
      foreach (preg_split('/\R/u', trim((string)($i['options_text'] ?? ''))) as $line) {
        $line = trim($line);
        if ($line !== '') $itemLines[] = '  ' . $line;
      }
      if (trim((string)($i['obs_cliente'] ?? '')) !== '') $itemLines[] = '  Obs: ' . trim((string)$i['obs_cliente']);
      $itemLines[] = '  ' . money((float)($i['total'] ?? 0));
    } elseif (($i['type'] ?? 'simple') === 'configured') {
      $itemLines[] = '- ' . rtrim(rtrim(number_format((float)($i['qtd'] ?? 1), 2, ',', '.'), '0'), ',') . 'x ' . (string)($i['nome'] ?? 'Item') . ' - ' . money((float)($i['total'] ?? 0));
      foreach (preg_split('/
/u', str_replace(["\r\n", "\r"], "\n", trim((string)($i['options_text'] ?? '')))) as $line) {
        $line = trim($line);
        if ($line !== '') $itemLines[] = '  ' . $line;
      }
      if (trim((string)($i['obs_cliente'] ?? '')) !== '') $itemLines[] = '  Obs: ' . trim((string)$i['obs_cliente']);
    } else {
      $itemLines[] = '- ' . rtrim(rtrim(number_format((float)($i['qtd'] ?? 1), 2, ',', '.'), '0'), ',') . 'x ' . (string)($i['nome'] ?? 'Item') . ' - ' . money((float)($i['total'] ?? 0));
      if (trim((string)($i['obs'] ?? '')) !== '') $itemLines[] = '  Obs: ' . trim((string)$i['obs']);
    }
  }

  [$bairro, $enderecoLimpo, $referencia] = checkout_split_order_address($bairro, $endereco);
  $scheduled = trim($scheduledDeliveryDate) !== '' ? checkout_format_scheduled_delivery($scheduledDeliveryDate, $scheduledDeliveryTime) : '';
  $obsPedido = trim($obsPedido);

  $loja = trim((string)($app['site_name'] ?? ($app['brand']['catalog_title'] ?? '')));
  if ($loja === '') $loja = 'Nossa loja';

  $waCfg = $waCfg ?: ['templates' => whatsapp_default_templates()];
  $template = whatsapp_template_from_config($waCfg, 'order_customer_full');
  $pagamentoLabel = label_pgto_checkout($formaPgto);
  $pixLinha = whatsapp_pix_line_for_payment($formaPgto, $waCfg);
  $pixDados = $pixLinha !== '' ? trim((string)($waCfg['pix']['details'] ?? '')) : '';
  return whatsapp_render_order_template($template, [
    'pedido_id' => (string)$pedidoId,
    'loja' => $loja,
    'cliente' => trim($nome) !== '' ? trim($nome) : 'Consumidor final',
    'whatsapp' => trim($whatsapp),
    'whatsapp_linha' => whatsapp_optional_line('📱 WhatsApp: ', trim($whatsapp)),
    'itens' => implode("\n", $itemLines),
    'subtotal' => money($subtotal),
    'taxa_entrega' => money($taxaEntrega),
    'taxa_entrega_linha' => $taxaEntrega > 0 ? '🛵 Taxa de entrega: ' . money($taxaEntrega) : '',
    'total' => money($totalGeral),
    'tipo_entrega' => $tipoEntregaLabel,
    'pagamento' => $pagamentoLabel,
    'pix_linha' => $pixLinha,
    'pix_dados' => $pixDados,
    'data_hora_prevista' => $scheduled,
    'data_hora_prevista_linha' => whatsapp_optional_line('📅 Data/Hora Prevista: ', $scheduled),
    'bairro' => $bairro,
    'bairro_linha' => whatsapp_optional_line('📍 Bairro: ', $bairro),
    'cep' => preg_replace('/(\d{5})(\d{3})$/', '$1-$2', preg_replace('/\D+/', '', $zipCode)),
    'cep_linha' => whatsapp_optional_line('📮 CEP: ', preg_replace('/(\d{5})(\d{3})$/', '$1-$2', preg_replace('/\D+/', '', $zipCode))),
    'endereco' => trim((string)preg_replace('/\s+/', ' ', $enderecoLimpo)),
    'endereco_linha' => whatsapp_optional_line('🏠 Endereço: ', trim((string)preg_replace('/\s+/', ' ', $enderecoLimpo))),
    'referencia' => $referencia,
    'referencia_linha' => whatsapp_optional_line('📌 Referência: ', $referencia),
    'observacoes' => $obsPedido,
    'observacoes_linha' => whatsapp_optional_line('📝 Observação do pedido: ', $obsPedido),
  ]);
}

$itens = [];
$total = 0.0;

// Itens simples
foreach (array_keys($cart) as $id) {
  $id = (int)$id;
  if (!isset($map[$id])) continue;

  $qtd = (float)$cart[$id];
  $preco = (float)$map[$id]['preco'];
  $sub = $qtd * $preco;

  $itens[] = [
    'type' => 'simple',
    'id' => $id,
    'nome' => (string)$map[$id]['nome'],
    'qtd' => $qtd,
    'preco' => $preco,
    'total' => $sub,
    'obs' => trim((string)($cartObs[$id] ?? '')),
  ];
  $total += $sub;
}

// Itens compostos
foreach ($custom as $key => $ci) {
  if (($ci['type'] ?? '') === 'half') {
    $a = (int)($ci['a'] ?? 0);
    $b = (int)($ci['b'] ?? 0);
    if (!isset($map[$a], $map[$b])) continue;

    $qtd = (float)($ci['qtd'] ?? 1);
    $pa = (float)$map[$a]['preco'];
    $pb = (float)$map[$b]['preco'];
    $precoBase = max($pa, $pb);
    $optionsTotal = (float)($ci['options_total'] ?? 0);
    $preco = $precoBase + $optionsTotal;
    $sub = $qtd * $preco;
    $optionsText = product_options_text((array)($ci['options'] ?? []));
    $obsCliente = trim((string)($ci['obs'] ?? ''));
    $obsCompleta = trim($optionsText . ($obsCliente !== '' ? "\nObs: " . $obsCliente : ''));

    $itens[] = [
      'type' => 'half',
      'key' => (string)$key,
      'a' => $a,
      'b' => $b,
      'nome' => 'Pizza meio a meio',
      'sabor_a' => (string)$map[$a]['nome'],
      'sabor_b' => (string)$map[$b]['nome'],
      'qtd' => $qtd,
      'preco' => $preco,
      'preco_base' => $precoBase,
      'options_total' => $optionsTotal,
      'options_text' => $optionsText,
      'total' => $sub,
      'obs' => $obsCompleta,
      'obs_cliente' => $obsCliente,
    ];
    $total += $sub;
    continue;
  }

  if (($ci['type'] ?? '') === 'configured') {
    $id = (int)($ci['id'] ?? 0);
    if (!isset($map[$id])) continue;

    $qtd = (float)($ci['qtd'] ?? 1);
    $base = (float)$map[$id]['preco'];
    $optionsTotal = (float)($ci['options_total'] ?? 0);
    $preco = $base + $optionsTotal;
    $sub = $qtd * $preco;
    $optionsText = product_options_text((array)($ci['options'] ?? []));
    $obsCliente = trim((string)($ci['obs'] ?? ''));
    $obsCompleta = trim($optionsText . ($obsCliente !== '' ? "\nObs: " . $obsCliente : ''));

    $itens[] = [
      'type' => 'configured',
      'key' => (string)$key,
      'id' => $id,
      'nome' => (string)$map[$id]['nome'],
      'qtd' => $qtd,
      'preco' => $preco,
      'preco_base' => $base,
      'options_total' => $optionsTotal,
      'options_text' => $optionsText,
      'total' => $sub,
      'obs' => $obsCompleta,
      'obs_cliente' => $obsCliente,
    ];
    $total += $sub;
    continue;
  }
}

$erro = '';
$ok = false;
$pedidoId = null;
$pedidoNumero = 0;

$taxaEntregaPadrao = (float)($biz['delivery_fees']['default'] ?? ($app['taxa_entrega'] ?? 0));

$document = '';
$personType = 'FISICA';
$customerId = 0;
$customerAddressId = 0;
$nome = '';
$fantasyName = '';
$email = '';
$whatsapp = '';
$zipCode = '';
$endereco = '';
$numero = '';
$complemento = '';
$referencia = '';
$bairro = '';
$cidade = '';
$uf = '';
$tipoEntrega = 'entrega';
$formaPgto = '';
$obs = '';
$obsPedidoOnly = '';
$lgpdAccept = false;
$birthOpeningDate = '';
$scheduledDeliveryDate = '';
$scheduledDeliveryTime = '';
$deliverySchedulingEnabled = !empty($biz['delivery_scheduling']['enabled']);
$deliverySchedulingLabel = trim((string)($biz['delivery_scheduling']['label'] ?? 'Data/Hora Prevista')) ?: 'Data/Hora Prevista';
$deliverySchedulingRequired = !empty($biz['delivery_scheduling']['required']);
$deliverySchedulingOnlyBusinessHours = !empty($biz['delivery_scheduling']['only_business_hours']);
$deliverySchedulingMinMinutes = max(0, (int)($biz['delivery_scheduling']['min_minutes'] ?? 0));
$checkoutCfg = (array)($biz['checkout'] ?? []);
$customerNameLabel = trim((string)($checkoutCfg['customer_name_label'] ?? 'Nome/Razão social'));
if ($customerNameLabel === '') $customerNameLabel = 'Nome/Razão social';
$customerNamePlaceholder = trim((string)($checkoutCfg['customer_name_placeholder'] ?? ''));
if ($customerNamePlaceholder === '') $customerNamePlaceholder = $customerNameLabel;
$orderTypesCfg = (array)($biz['order_types'] ?? []);
$checkoutOrderTypesCfg = isset($orderTypesCfg['checkout']) && is_array($orderTypesCfg['checkout']) ? (array)$orderTypesCfg['checkout'] : $orderTypesCfg;
$checkoutDeliveryTypes = [];
if (!array_key_exists('entrega', $checkoutOrderTypesCfg) || !empty($checkoutOrderTypesCfg['entrega'])) $checkoutDeliveryTypes[] = 'entrega';
if (!array_key_exists('retirada', $checkoutOrderTypesCfg) || !empty($checkoutOrderTypesCfg['retirada'])) $checkoutDeliveryTypes[] = 'retirada';
if (!$checkoutDeliveryTypes) $checkoutDeliveryTypes = ['retirada'];
$tipoEntrega = in_array('entrega', $checkoutDeliveryTypes, true) ? 'entrega' : $checkoutDeliveryTypes[0];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $customerId = (int)($_POST['customer_id'] ?? 0);
  $customerAddressId = (int)($_POST['customer_address_id'] ?? 0);
  $personType = strtoupper(trim((string)($_POST['person_type'] ?? 'FISICA'))) === 'JURIDICA' ? 'JURIDICA' : 'FISICA';
  $lookupDigits = checkout_digits((string)($_POST['document'] ?? ''));
  $document = '';
  if (strlen($lookupDigits) === 14 && checkout_valid_cnpj($lookupDigits)) {
    $document = $lookupDigits;
    $personType = 'JURIDICA';
  } elseif (strlen($lookupDigits) === 11 && checkout_valid_cpf($lookupDigits)) {
    $document = $lookupDigits;
    $personType = 'FISICA';
  }

  $nome = trim((string)($_POST['nome'] ?? ''));
  $fantasyName = $personType === 'JURIDICA' ? trim((string)($_POST['fantasy_name'] ?? '')) : '';
  $email = trim((string)($_POST['email'] ?? ''));
  $whatsapp = preg_replace('/\D+/', '', (string)($_POST['whatsapp'] ?? ''));
  if ($whatsapp === '' && $document === '' && strlen($lookupDigits) >= 8) {
    $whatsapp = $lookupDigits;
  }
  $whatsapp = substr($whatsapp, 0, 20);
  $zipCode = preg_replace('/\D+/', '', (string)($_POST['zip_code'] ?? ''));

  $endereco = trim((string)($_POST['endereco'] ?? ''));
  $numero = trim((string)($_POST['numero'] ?? ''));
  $complemento = trim((string)($_POST['complemento'] ?? ''));
  $referencia = trim((string)($_POST['referencia'] ?? ''));
  $bairro = trim((string)($_POST['bairro'] ?? ''));
  $cidade = trim((string)($_POST['cidade'] ?? ''));
  $uf = strtoupper(substr(trim((string)($_POST['uf'] ?? '')), 0, 2));

  // Quando o cliente seleciona um endereço salvo, garante que os campos separados
  // sejam carregados também no POST antes de montar o pedido e a mensagem.
  $customerAddressIdPost = (int)($_POST['customer_address_id'] ?? 0);
  if ($customerAddressIdPost > 0 && ($endereco === '' || $bairro === '' || $numero === '' || $zipCode === '')) {
    try {
      $addrSel = finance_query_one($pdo, 'SELECT * FROM gestao_customer_addresses WHERE company_id = ? AND id = ? AND active = 1 LIMIT 1', [COMPANY_ID, $customerAddressIdPost]);
      if ($addrSel) {
        if ($endereco === '') $endereco = trim((string)($addrSel['address'] ?? ''));
        if ($numero === '') $numero = trim((string)($addrSel['number'] ?? ''));
        if ($complemento === '') $complemento = trim((string)($addrSel['complement'] ?? ''));
        if ($referencia === '') $referencia = trim((string)($addrSel['reference'] ?? ''));
        if ($bairro === '') $bairro = trim((string)($addrSel['district'] ?? ''));
        if ($cidade === '') $cidade = trim((string)($addrSel['city'] ?? ''));
        if ($uf === '') $uf = strtoupper(substr(trim((string)($addrSel['state'] ?? '')), 0, 2));
        if ($zipCode === '') $zipCode = checkout_digits((string)($addrSel['zip_code'] ?? ''));
      }
    } catch (Throwable $e) {}
  }
  $birthOpeningDate = checkout_date_or_null((string)($_POST['birth_opening_date'] ?? '')) ?: '';
  $scheduledDeliveryDate = checkout_date_or_null((string)($_POST['scheduled_delivery_date'] ?? '')) ?: '';
  $scheduledDeliveryTime = checkout_time_or_null((string)($_POST['scheduled_delivery_time'] ?? '')) ?: '';
  $lgpdAccept = !empty($_POST['lgpd_accept']);
  $customerAlreadyRegistered = false;
  if ($customerId > 0) {
    try {
      $rowCustomer = finance_query_one($pdo, 'SELECT id FROM finance_people WHERE company_id = ? AND id = ? AND active = 1 AND is_customer = 1 LIMIT 1', [COMPANY_ID, $customerId]);
      $customerAlreadyRegistered = !empty($rowCustomer);
      if (!$customerAlreadyRegistered) {
        $customerId = 0;
      }
    } catch (Throwable $e) {
      $customerAlreadyRegistered = false;
      $customerId = 0;
    }
  }

  $tipoEntrega = (string)($_POST['tipo_entrega'] ?? ($checkoutDeliveryTypes[0] ?? 'retirada'));
  if (!in_array($tipoEntrega, $checkoutDeliveryTypes, true)) {
    $tipoEntrega = $checkoutDeliveryTypes[0] ?? 'retirada';
  }

  $formaPgto = (string)($_POST['forma_pagamento'] ?? '');
  $allowedPgto = ['pix', 'dinheiro', 'debito', 'credito'];
  if (!in_array($formaPgto, $allowedPgto, true)) {
    $formaPgto = '';
  }

  $obs = trim((string)($_POST['observacoes'] ?? ''));
  if (mb_strlen($obs) > 2000) {
    $obs = mb_substr($obs, 0, 2000);
  }
  $obsPedidoOnly = $obs;

  $taxaEntrega = ($tipoEntrega === 'entrega') ? delivery_fee($biz, $tipoEntrega, $bairro) : 0.0;
  if ($taxaEntrega === 0.0 && $taxaEntregaPadrao > 0.0 && $tipoEntrega === 'entrega') {
    $taxaEntrega = $taxaEntregaPadrao;
  }

  $totalGeral = $total + $taxaEntrega;

  if ($nome === '') {
    $erro = 'Informe o ' . $customerNameLabel . ' para finalizar o pedido.';
  } elseif ($birthOpeningDate === '') {
    $erro = $personType === 'JURIDICA' ? 'Informe a data de abertura para finalizar o pedido.' : 'Informe a data de nascimento para finalizar o pedido.';
  } elseif ($document !== '' && !$lgpdAccept && !$customerAlreadyRegistered) {
    $erro = 'Para salvar os dados para próximos pedidos, confirme o aceite da LGPD.';
  } elseif ($whatsapp === '') {
    $erro = 'Informe o WhatsApp para contato do pedido.';
  } elseif ($tipoEntrega === 'entrega' && $endereco === '') {
    $erro = 'Informe o endereço para entrega.';
  } elseif ($tipoEntrega === 'entrega' && $bairro === '') {
    $erro = 'Informe o bairro para calcular a taxa de entrega.';
  } elseif ($deliverySchedulingEnabled && $deliverySchedulingRequired && ($scheduledDeliveryDate === '' || $scheduledDeliveryTime === '')) {
    $erro = 'Informe a data e a hora da previsão de entrega.';
  } elseif ($deliverySchedulingEnabled && ($scheduledDeliveryDate !== '' xor $scheduledDeliveryTime !== '')) {
    $erro = 'Informe a data e a hora da previsão de entrega.';
  } elseif ($deliverySchedulingEnabled && $deliverySchedulingMinMinutes > 0 && $scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && !checkout_scheduled_delivery_respects_minimum($biz, $scheduledDeliveryDate, $scheduledDeliveryTime, $deliverySchedulingMinMinutes)) {
    $erro = 'A previsão de entrega precisa respeitar o tempo mínimo de ' . checkout_minutes_label($deliverySchedulingMinMinutes) . ' após o horário do pedido.';
  } elseif ($deliverySchedulingEnabled && $deliverySchedulingOnlyBusinessHours && $scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && !checkout_scheduled_delivery_in_business_hours($biz, $scheduledDeliveryDate, $scheduledDeliveryTime)) {
    $hoursLabel = checkout_business_hours_label_for_date($biz, $scheduledDeliveryDate);
    $erro = $hoursLabel !== ''
      ? 'A previsão de entrega precisa estar dentro do horário de funcionamento: ' . $hoursLabel . '.'
      : 'A previsão de entrega precisa estar dentro do horário de funcionamento. A data escolhida está fechada.';
  } elseif ($deliverySchedulingEnabled && $deliverySchedulingOnlyBusinessHours && $scheduledDeliveryDate !== '' && $scheduledDeliveryTime !== '' && checkout_scheduled_delivery_is_past($biz, $scheduledDeliveryDate, $scheduledDeliveryTime)) {
    $erro = 'A previsão de entrega não pode ser anterior ao horário atual.';
  } elseif ($formaPgto === '') {
    $erro = 'Selecione a forma de pagamento.';
  } elseif (!$itens) {
    $erro = 'Carrinho sem itens válidos.';
  } else {
    $stNow = store_status($biz);
    if (!$stNow['open'] && $tipoEntrega === 'entrega') {
      $erro = 'No momento estamos fechados para entrega. ' . ($stNow['next'] ? ('Reabrimos: ' . $stNow['next']) : '');
    }

    if ($erro === '') {
      try {
        checkout_ensure_order_schema($pdo);

        $colBairro = checkout_detect_column($pdo, 'pedidos', ['bairro']);
        $colItemObs = checkout_detect_column($pdo, 'pedido_itens', ['complemento','observacao','obs','observacoes']);

        // Se não houver coluna bairro, incorpora no endereço
        if (!$colBairro && trim($bairro) !== '' && $tipoEntrega === 'entrega') {
          $endereco = trim("Bairro: " . $bairro . "\n" . $endereco);
        }

        $obsParaSalvar = $obsPedidoOnly;
        if (!$colItemObs) {
          $obsItens = '';
          foreach ($itens as $i0) {
            $o = trim((string)($i0['obs'] ?? ''));
            if ($o === '') continue;
            $o = trim((string)preg_replace('/\s*\R+\s*/u', ' | ', $o));
            $obsItens .= "\n- " . (string)($i0['nome'] ?? 'Item') . ': ' . $o;
          }
          if ($obsItens !== '') {
            $obsParaSalvar = trim($obsParaSalvar . "\n" . 'Obs. por item:' . $obsItens);
          }
        }

        $pdo->beginTransaction();

        $enderecoParaPedido = checkout_order_address_text($endereco, $numero, $complemento, $referencia, $bairro, $cidade, $uf);

        if ($document !== '' && ($lgpdAccept || $customerAlreadyRegistered)) {
          $customerId = checkout_upsert_customer($pdo, COMPANY_ID, [
            'document' => $document,
            'person_type' => $personType,
            'name' => $nome,
            'fantasy_name' => $fantasyName,
            'email' => $email,
            'phone' => $whatsapp,
            'whatsapp' => $whatsapp,
            'birth_opening_date' => $birthOpeningDate,
            'zip_code' => $zipCode,
            'address' => $endereco,
            'number' => $numero,
            'complement' => $complemento,
            'reference' => $referencia,
            'district' => $bairro,
            'city' => $cidade,
            'state' => $uf,
            'label' => trim((string)($_POST['address_label'] ?? '')) ?: 'Entrega',
          ], !empty($_POST['save_address']) || $customerId <= 0, !empty($_POST['address_default']) || $customerId <= 0);
        }

        $cols = ['company_id'];
        $vals = [COMPANY_ID];
        if (company_sequence_has_column($pdo, 'pedidos')) {
          $pedidoNumero = company_sequence_next($pdo, (int)COMPANY_ID, 'pedidos');
          $cols[] = 'numero_pedido';
          $vals[] = $pedidoNumero;
        }
        $cols = array_merge($cols, [
          'nome_cliente',
          'whatsapp',
          'endereco',
          'tipo_entrega',
          'taxa_entrega',
          'forma_pagamento',
          'observacoes',
          'total_produtos',
          'total',
          'status'
        ]);

        $vals = array_merge($vals, [
          $nome,
          $whatsapp,
          $enderecoParaPedido,
          $tipoEntrega,
          $taxaEntrega,
          $formaPgto,
          $obsParaSalvar,
          $total,
          $totalGeral,
          'aberto'
        ]);

        if ($colBairro) {
          $idxTipoEntrega = array_search('tipo_entrega', $cols, true);
          if ($idxTipoEntrega === false) $idxTipoEntrega = 4;
          array_splice($cols, (int)$idxTipoEntrega, 0, ['bairro']);
          array_splice($vals, (int)$idxTipoEntrega, 0, [$bairro]);
        }

        $extrasPedido = [
          'source' => 'delivery',
          'customer_id' => $customerId > 0 ? $customerId : null,
          'customer_document' => $document !== '' ? $document : null,
          'zip_code' => $zipCode !== '' ? $zipCode : null,
          'numero' => $numero !== '' ? $numero : null,
          'complemento' => $complemento !== '' ? $complemento : null,
          'referencia' => $referencia !== '' ? $referencia : null,
          'cidade' => $cidade !== '' ? $cidade : null,
          'uf' => $uf !== '' ? $uf : null,
          'scheduled_delivery_date' => ($deliverySchedulingEnabled && $scheduledDeliveryDate !== '') ? $scheduledDeliveryDate : null,
          'scheduled_delivery_time' => ($deliverySchedulingEnabled && $scheduledDeliveryTime !== '') ? $scheduledDeliveryTime : null,
        ];
        foreach ($extrasPedido as $extraCol => $extraValue) {
          if (checkout_detect_column($pdo, 'pedidos', [$extraCol])) {
            $cols[] = $extraCol;
            $vals[] = $extraValue;
          }
        }

        if ($deliverySchedulingEnabled && $scheduledDeliveryDate !== '' && !checkout_detect_column($pdo, 'pedidos', ['scheduled_delivery_date'])) {
          $obsParaSalvar = trim($obsParaSalvar . "
" . 'Data/Hora Prevista: ' . checkout_format_scheduled_delivery($scheduledDeliveryDate, $scheduledDeliveryTime));
          $idxObs = array_search('observacoes', $cols, true);
          if ($idxObs !== false) $vals[$idxObs] = $obsParaSalvar;
        }

        $ph = implode(',', array_fill(0, count($cols), '?'));
        $sql = "INSERT INTO pedidos (" . implode(',', $cols) . ") VALUES (" . $ph . ")";
        $stp = $pdo->prepare($sql);
        $stp->execute($vals);

        $pedidoId = (int)$pdo->lastInsertId();
        if ((int)$pedidoNumero <= 0) $pedidoNumero = (int)$pedidoId;

        $sti = $pdo->prepare($colItemObs
          ? "INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, {$colItemObs}) VALUES (?, ?, ?, ?, ?, ?, ?)"
          : "INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?, ?, ?, ?, ?, ?)"
        );

        foreach ($itens as $i) {
          if (($i['type'] ?? 'simple') === 'half') {
            $qMeia = (float)$i['qtd'] * 0.5;
            $preco = (float)$i['preco'];
            $totalMeia = $qMeia * $preco;
            $obsBase = trim((string)($i['obs'] ?? ''));
            $obsHalf = '1/2 ' . (string)($i['sabor_a'] ?? '') . ' + 1/2 ' . (string)($i['sabor_b'] ?? '');
            if ($obsBase !== '') {
              $obsHalf .= ' | Obs: ' . $obsBase;
            }

            if ($colItemObs) {
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['a'], $qMeia, $preco, $totalMeia, $obsHalf]);
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['b'], $qMeia, $preco, $totalMeia, $obsHalf]);
            } else {
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['a'], $qMeia, $preco, $totalMeia]);
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['b'], $qMeia, $preco, $totalMeia]);
            }
          } else {
            $colObs = trim((string)($i['obs'] ?? ''));
            if ($colItemObs) {
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['id'], $i['qtd'], $i['preco'], $i['total'], $colObs]);
            } else {
              $sti->execute([COMPANY_ID, $pedidoId, (int)$i['id'], $i['qtd'], $i['preco'], $i['total']]);
            }
          }
        }

        brain_profitability_recalculate_order($pdo, (int)COMPANY_ID, $pedidoId, true, 'order.created');

        try {
          $ste = $pdo->prepare("INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)");
          $ste->execute([COMPANY_ID, $pedidoId]);
        } catch (Throwable $e) {
          // ignora
        }

        $pdo->commit();

        // Evento em tempo real: publicado SOMENTE depois do COMMIT.
        // Não consulta o banco e não interfere na gravação do pedido se Redis/WebSocket estiver indisponível.
        nexa_realtime_publish_event(
          (int)COMPANY_ID,
          'delivery.order.created',
          'order',
          (int)$pedidoId,
          [
            'order_id' => (int)$pedidoId,
            'order_number' => (int)$pedidoNumero,
            'customer' => (string)$nome,
            'total' => (float)$totalGeral,
            'subtotal' => (float)$total,
            'delivery_fee' => (float)$taxaEntrega,
            'delivery_type' => (string)$tipoEntrega,
            'payment_method' => (string)$formaPgto,
            'source' => 'catalog',
          ],
          false
        );

        // Exportação automática para Firebird/Próspero, quando ativada nas Configurações.
        // Se falhar, o pedido continua gravado e permanece na fila para reprocessamento.
        try {
          $fbCfgAuto = load_firebird_config(COMPANY_ID);
          if (!empty($fbCfgAuto['enabled']) && !empty($fbCfgAuto['auto_export_on_order'])) {
            $fbRes = firebird_export_pending($pdo, COMPANY_ID, (int)$pedidoId, 1);
            if (!empty($fbRes['error'])) {
              @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] Firebird auto pedido #' . (int)$pedidoId . ': ' . (string)$fbRes['error'] . PHP_EOL, FILE_APPEND);
            }
          }
        } catch (Throwable $e) {
          @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] Firebird auto pedido #' . (int)$pedidoId . ': ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }

        // Envio automático via WhatsApp conectado por QR Code.
        // Se o serviço estiver indisponível, o pedido permanece gravado normalmente.
        try {
          $waCfg = whatsapp_load_config($pdo, COMPANY_ID);
          // Mensagens automáticas podem usar fallback visual no WhatsApp Web;
          // por isso, aumentamos o tempo mínimo do envio mesmo que a tela esteja com 6s.
          $waCfg['timeout_seconds'] = max(30, (int)($waCfg['timeout_seconds'] ?? 30));

          if (!empty($waCfg['enabled'])) {
            $msgPedidoLoja = montar_whatsapp_pedido_checkout(
              (int)$pedidoNumero,
              $itens,
              (float)$total,
              (float)$taxaEntrega,
              (float)$totalGeral,
              (string)$tipoEntrega,
              (string)$formaPgto,
              (string)$bairro,
              (string)$enderecoParaPedido,
              (string)$zipCode,
              (string)$nome,
              (string)$whatsapp,
              $app,
              (string)$scheduledDeliveryDate,
              (string)$scheduledDeliveryTime,
              $waCfg,
              (string)$obsPedidoOnly
            );

            // Envia somente UMA mensagem automática ao finalizar o pedido:
            // a mensagem completa do pedido, usando a API/WhatsApp conectado no Admin.
            // O destino é o WhatsApp do cliente informado no checkout.
            if (!empty($waCfg['send_order_confirmation_to_customer'])) {
              $destinoCliente = (string)$whatsapp;

              // Com a fila instalada, o pedido termina na hora e a mensagem é
              // enviada em segundo plano (inclusive se o WhatsApp voltar depois).
              // Sem a fila, mantém a checagem rápida para não segurar o checkout.
              if (whatsapp_outbox_active($pdo)) {
                if (!whatsapp_queue_message($pdo, COMPANY_ID, $destinoCliente, $msgPedidoLoja, 'order_customer_full', (int)$pedidoId, $waCfg)) {
                  @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] WhatsApp: não foi possível colocar o pedido na fila. Pedido=' . (int)$pedidoId . PHP_EOL, FILE_APPEND);
                }
              } elseif (checkout_whatsapp_connected_quick($waCfg, (int)COMPANY_ID)) {
                $okCliente = whatsapp_send_message($pdo, COMPANY_ID, $destinoCliente, $msgPedidoLoja, 'order_customer_full', (int)$pedidoId, $waCfg);
                if (!$okCliente) {
                  @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] WhatsApp auto pedido completo para cliente falhou. Pedido=' . (int)$pedidoId . ' Destino=' . $destinoCliente . PHP_EOL, FILE_APPEND);
                }
              } else {
                @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] WhatsApp auto ignorado: sessão desconectada/indisponível. Pedido=' . (int)$pedidoId . PHP_EOL, FILE_APPEND);
              }
            }
          } else {
            @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] WhatsApp auto ignorado: automação desativada. Pedido=' . (int)$pedidoId . PHP_EOL, FILE_APPEND);
          }
        } catch (Throwable $e) {
          @file_put_contents(__DIR__ . '/checkout_error.log', '[' . date('c') . '] WhatsApp auto: ' . $e->getMessage() . PHP_EOL, FILE_APPEND);
        }

        unset($_SESSION['cart'], $_SESSION['cart_custom'], $_SESSION['cart_obs']);

        $ok = true;
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }

        $erro = 'Não foi possível finalizar o pedido agora. Tente novamente.';
        if (str_contains($e->getMessage(), 'Data too long') && str_contains($e->getMessage(), 'whatsapp')) {
          $erro = 'Não foi possível finalizar: o campo WhatsApp do banco está pequeno. Atualize esta versão e tente novamente.';
        }
        @file_put_contents(
          __DIR__ . '/checkout_error.log',
          '[' . date('c') . '] ' . $e->getMessage() . PHP_EOL,
          FILE_APPEND
        );
      }
    }
  }
}

// Checkout usa uma apresentação mais limpa: sem hero, cabeçalho institucional e
// promoções do catálogo. O carrinho flutuante continua visível com o valor atual.
$checkoutCompactLayout = true;
$checkoutFloatingCartFee = 0.0;
if ($tipoEntrega === 'entrega') {
  $checkoutFloatingCartFee = (float)delivery_fee($biz, $tipoEntrega, $bairro);
  if ($checkoutFloatingCartFee === 0.0 && $taxaEntregaPadrao > 0.0) $checkoutFloatingCartFee = (float)$taxaEntregaPadrao;
}
$checkoutFloatingCartTotal = (float)$total + $checkoutFloatingCartFee;

require __DIR__ . '/_layout_top.php';
?>

<div class="breadcrumb">
  <a href="<?= asset_url('', $app) ?>">In&iacute;cio</a> / <a href="<?= asset_url('carrinho.php', $app) ?>">Carrinho</a> / Finalizar
</div>

<?php if ($ok): ?>
  <div class="panel"><div class="p">
    <h2 style="margin:0 0 8px;">Pedido registrado ✅</h2>
    <div class="muted" style="font-size:1.18rem; font-weight:700; line-height:1.35;">Número do pedido: <b style="font-size:1.55rem; font-weight:900; color:#111827;">#<?= (int)$pedidoNumero ?></b></div>

    <div class="notice" style="margin-top:14px; padding:18px 16px; line-height:1.45;">
      <div style="font-size:1.32rem; font-weight:800; line-height:1.25;">Pedido realizado com sucesso!</div>
      <div style="font-size:1.12rem; font-weight:700; margin-top:6px;">Aguarde a confirmação do pedido!</div>
      <?php
        try {
          $waCfgView = whatsapp_load_config($pdo, COMPANY_ID);
        } catch (Throwable $e) {
          $waCfgView = [];
        }
      ?>
      <?php if (!empty($waCfgView['enabled']) && !empty($waCfgView['send_order_confirmation_to_customer'])): ?>
        <div style="font-size:1rem; font-weight:600; margin-top:10px;">
          A mensagem completa do pedido será enviada automaticamente pelo WhatsApp.
        </div>
      <?php endif; ?>
    </div>

    <div style="margin-top:14px; display:flex; gap:10px; flex-wrap:wrap;">
      <a class="btn" href="<?= asset_url('', $app) ?>">Voltar ao catálogo</a>
    </div>
  </div></div>

<?php else: ?>

  <div class="panel"><div class="p">
    <h2 style="margin:0 0 10px;">Finalizar pedido</h2>

    <?php if ($erro): ?>
      <div class="panel" style="border-radius:14px; margin-bottom:12px;">
        <div class="p"><?= e($erro) ?></div>
      </div>
    <?php endif; ?>

    <div class="muted" style="margin-bottom:10px;">Total: <b><?= money($total) ?></b></div>

    <?php if ($taxaEntregaPadrao > 0): ?>
      <div class="muted" style="margin-bottom:10px;">Taxa de entrega: <b><?= money($taxaEntregaPadrao) ?></b> (somente para entrega)</div>
    <?php endif; ?>

    <style>
      .checkout-modern{display:grid;gap:14px;padding-bottom:142px}
      .checkout-stepper{display:grid;grid-template-columns:repeat(var(--checkout-steps,4),minmax(0,1fr));gap:8px;margin:4px 0 6px}
      .checkout-step-pill{position:relative;border:1.5px solid #b8c5d6;background:#f8fafc;border-radius:14px;padding:10px 8px;text-align:center;color:#64748b;font-size:12px;font-weight:800;line-height:1.2;transition:.18s ease}
      .checkout-step-pill .checkout-step-number{display:inline-flex;width:24px;height:24px;border-radius:999px;align-items:center;justify-content:center;margin-right:5px;background:#e2e8f0;color:#334155;font-size:12px}
      .checkout-step-pill.is-active{border-color:#3157c8;background:#eef4ff;color:#0B1F5B;box-shadow:0 0 0 2px rgba(49,87,200,.08)}
      .checkout-step-pill.is-active .checkout-step-number{background:#3157c8;color:#fff}
      .checkout-step-pill.is-done{border-color:#9ab0e7;background:#f4f7ff;color:#1e3a8a}
      .checkout-step-pill.is-done .checkout-step-number{background:#dbe7ff;color:#1e3a8a}
      .checkout-step{display:none}.checkout-step.is-active{display:block}
      .checkout-section{border:1.7px solid #b6c3d4;border-radius:18px;padding:16px;background:#fff;max-width:100%;box-sizing:border-box;box-shadow:0 6px 20px rgba(15,31,91,.04)}
      .checkout-section h3{margin:0 0 12px;color:#0B1F5B;font-size:18px}
      .checkout-grid{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:12px;max-width:100%}.checkout-grid.three{grid-template-columns:repeat(3,minmax(0,1fr))}.checkout-grid .span-2{grid-column:span 2}.checkout-grid .span-3{grid-column:span 3}.checkout-grid>div,.checkout-grid label{min-width:0}.checkout-grid input,.checkout-grid select,.checkout-grid textarea,.checkout-inline input,.checkout-inline select,.checkout-inline textarea{width:100%;max-width:100%;min-width:0;box-sizing:border-box}.checkout-inline{display:flex;gap:8px;align-items:center;max-width:100%}.checkout-inline .field{flex:1;min-width:0}
      .checkout-field-compact{width:min(100%,230px);max-width:230px;min-width:0}.checkout-field-compact .field{width:100%;max-width:100%;min-width:0;inline-size:100%;max-inline-size:100%}.checkout-schedule-grid{grid-template-columns:repeat(2,minmax(0,230px));justify-content:start}.checkout-schedule-grid .checkout-help{grid-column:1 / -1;max-width:540px}
      .checkout-form .field{border:1.7px solid #7f8fa5!important;background:#fff!important;box-shadow:0 1px 2px rgba(15,23,42,.06);transition:border-color .15s ease,box-shadow .15s ease}
      .checkout-form .field:focus{border-color:#3157c8!important;box-shadow:0 0 0 3px rgba(49,87,200,.12)!important;outline:none}
      .checkout-help{color:#64748b;font-size:13px;line-height:1.4}.checkout-status{font-size:13px;margin-top:6px;color:#64748b}.checkout-status.ok{color:#047857}.checkout-status.err{color:#b91c1c}
      .checkout-lgpd{display:flex;gap:9px;align-items:flex-start;background:#f8fbff;border:1.5px solid #b8c5d6;border-radius:16px;padding:12px;color:#334155}.checkout-lgpd input{margin-top:3px}.checkout-hidden{display:none!important}
      .checkout-wizard-nav{position:fixed;left:50%;transform:translateX(-50%);bottom:12px;z-index:10020;width:min(760px,calc(100vw - 24px));display:flex;justify-content:space-between;gap:12px;align-items:center;margin:0;padding:10px;border:1px solid #b8c5d6;border-radius:18px;background:rgba(255,255,255,.97);box-shadow:0 16px 42px rgba(15,31,91,.22);backdrop-filter:blur(10px);box-sizing:border-box}
      .checkout-wizard-nav .btn{min-width:132px;justify-content:center;font-size:17px;font-weight:900;line-height:1.1}.checkout-wizard-nav .checkout-next{margin-left:auto;background:linear-gradient(135deg,var(--brand-primary),var(--brand-secondary));color:var(--brand-on-primary);border-color:transparent}.checkout-wizard-nav .checkout-step-caption{font-size:12px;color:#64748b;font-weight:700;text-align:center;flex:1}
      @media(max-width:760px){
        .checkout-modern{padding-bottom:138px}
        /* Os 4 passos permanecem sempre visíveis no celular, sem rolagem/corte. */
        .checkout-stepper{display:grid;grid-template-columns:repeat(var(--checkout-steps,4),minmax(0,1fr));gap:4px;overflow:visible;padding-bottom:2px;width:100%;max-width:100%;box-sizing:border-box}
        .checkout-step-pill{min-width:0;padding:6px 2px 5px;border-radius:11px;font-size:8.5px;line-height:1.05;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:3px;white-space:normal;overflow:hidden}
        .checkout-step-pill .checkout-step-number{width:27px;height:27px;margin:0;flex:0 0 27px;font-size:12px}
        .checkout-grid,.checkout-grid.three{grid-template-columns:minmax(0,1fr)}
        .checkout-grid .span-2,.checkout-grid .span-3{grid-column:auto}
        .checkout-schedule-grid{grid-template-columns:minmax(0,1fr)}
        .checkout-schedule-grid .checkout-help{grid-column:auto}
        .checkout-inline{flex-direction:column;align-items:stretch}
        .checkout-section{padding:13px;border-radius:16px}
        /* 16px evita o zoom automático do navegador ao focar CPF/CNPJ e outros campos. */
        .checkout-form input.field,.checkout-form select.field,.checkout-form textarea.field{font-size:16px!important}
        .checkout-field-compact{width:min(100%,210px);max-width:210px}
        .checkout-schedule-grid .checkout-field-compact{width:min(100%,190px);max-width:190px}
        .checkout-wizard-nav{bottom:8px;width:calc(100vw - 16px);padding:8px;border-radius:16px}
        .checkout-wizard-nav .btn{min-width:0;flex:1}
        .checkout-wizard-nav .checkout-step-caption{display:none}
      }
    </style>

    <?php $checkoutWizardSteps = $deliverySchedulingEnabled ? 4 : 3; ?>
    <form method="post" class="checkout-form checkout-modern" id="checkoutForm" novalidate>
      <input type="hidden" name="customer_id" id="customerId" value="<?= (int)$customerId ?>">
      <input type="hidden" name="checkout_step" id="checkoutStepInput" value="1">

      <div class="checkout-stepper" id="checkoutStepper" style="--checkout-steps:<?= (int)$checkoutWizardSteps ?>" aria-label="Etapas do checkout">
        <div class="checkout-step-pill is-active" data-step-pill="0"><span class="checkout-step-number">1</span>Identificação</div>
        <div class="checkout-step-pill" data-step-pill="1"><span class="checkout-step-number">2</span>Entrega</div>
        <?php if ($deliverySchedulingEnabled): ?>
          <div class="checkout-step-pill" data-step-pill="2"><span class="checkout-step-number">3</span>Previsão</div>
          <div class="checkout-step-pill" data-step-pill="3"><span class="checkout-step-number">4</span>Pagamento</div>
        <?php else: ?>
          <div class="checkout-step-pill" data-step-pill="2"><span class="checkout-step-number">3</span>Pagamento</div>
        <?php endif; ?>
      </div>

      <div class="checkout-section checkout-step is-active" data-checkout-step="identification">
        <h3>1. Identificação</h3>
        <div class="checkout-grid three">
          <div>
            <label>Tipo</label>
            <select class="field" name="person_type" id="personType">
              <option value="FISICA" <?= $personType === 'FISICA' ? 'selected' : '' ?>>Pessoa Física</option>
              <option value="JURIDICA" <?= $personType === 'JURIDICA' ? 'selected' : '' ?>>Pessoa Jurídica</option>
            </select>
          </div>
          <div class="span-2">
            <label id="documentLabel">CPF ou CNPJ</label>
            <div class="checkout-inline">
              <input class="field" name="document" id="document" placeholder="Digite CPF, CNPJ ou telefone para pesquisar" value="<?= e($document) ?>" inputmode="numeric" autocomplete="off">
              <button type="button" class="btn" id="btnSearchCustomer">Pesquisar</button>
            </div>
            <div id="customerLookupStatus" class="checkout-status">Opcional. Se não informar CPF/CNPJ, o pedido fica como consumidor final e não grava cadastro.</div>
          </div>
          <div class="span-2"><label><?= e($customerNameLabel) ?> *</label><input class="field" name="nome" id="nome" placeholder="<?= e($customerNamePlaceholder) ?>" value="<?= e($nome) ?>" required></div>
          <div id="fantasyNameWrap"><label>Nome fantasia</label><input class="field" name="fantasy_name" id="fantasyName" value="<?= e($fantasyName) ?>"></div>
          <div><label>WhatsApp *</label><input class="field" name="whatsapp" id="whatsapp" placeholder="WhatsApp com DDD" value="<?= e($whatsapp) ?>" inputmode="tel" required><div id="whatsappLengthWarning" class="checkout-status" style="margin-top:6px;"></div></div>
          <div><label>E-mail</label><input class="field" type="email" name="email" id="email" value="<?= e($email) ?>"></div>
          <div class="checkout-field-compact"><label id="dateLabel">Data de nascimento *</label><input class="field" type="date" name="birth_opening_date" id="birthOpeningDate" value="<?= e($birthOpeningDate) ?>" required></div>
        </div>
        <label class="checkout-lgpd" id="lgpdBox" style="margin-top:12px;">
          <input type="checkbox" name="lgpd_accept" id="lgpdAccept" value="1" <?= $lgpdAccept ? 'checked' : '' ?>>
          <span id="lgpdText">Autorizo o armazenamento dos dados informados para facilitar próximos pedidos, conforme a LGPD. Obrigatório apenas para novo cadastro com CPF/CNPJ.</span>
        </label>
        <div id="lgpdAlreadyRegistered" class="checkout-status ok" style="display:none;margin-top:8px;">Cliente já cadastrado. Não é necessário marcar novo aceite de LGPD para finalizar este pedido.</div>
      </div>

      <div class="checkout-section checkout-step" data-checkout-step="delivery">
        <h3>2. Entrega</h3>
        <div style="display:flex;gap:14px;flex-wrap:wrap;align-items:center;margin-bottom:12px;">
          <?php if (in_array('entrega', $checkoutDeliveryTypes, true)): ?>
            <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
              <input type="radio" name="tipo_entrega" value="entrega" <?= ($tipoEntrega === 'entrega' ? 'checked' : '') ?>>
              <span>Entrega</span>
            </label>
          <?php endif; ?>
          <?php if (in_array('retirada', $checkoutDeliveryTypes, true)): ?>
            <label style="display:flex;gap:8px;align-items:center;cursor:pointer;">
              <input type="radio" name="tipo_entrega" value="retirada" <?= ($tipoEntrega === 'retirada' ? 'checked' : '') ?>>
              <span><?= e((string)($app['retirada_label'] ?? 'Retirada no balcão')) ?></span>
            </label>
          <?php endif; ?>
        </div>

        <div id="addressSavedBox" class="checkout-grid" style="margin-bottom:12px;display:none;">
          <div class="span-2">
            <label>Endereços cadastrados</label>
            <select class="field" name="customer_address_id" id="customerAddressSelect">
              <option value="0">Usar novo/endereço digitado abaixo</option>
            </select>
          </div>
        </div>

        <?php $bairrosCfg = (array)(($biz['delivery_fees']['bairros'] ?? [])); ?>
        <div class="checkout-grid three">
          <div>
            <label>CEP</label>
            <input class="field" id="zipCode" name="zip_code" placeholder="CEP" value="<?= e($zipCode) ?>" inputmode="numeric" autocomplete="postal-code">
            <div class="checkout-status" id="zipLookupStatus"></div>
          </div>
          <div class="span-2"><label>Endereço</label><input class="field" id="endereco" name="endereco" placeholder="Rua, avenida..." value="<?= e($endereco) ?>"></div>
          <div><label>Número</label><input class="field" id="numero" name="numero" value="<?= e($numero) ?>"></div>
          <div><label>Bairro</label><input class="field" id="bairro" name="bairro" list="bairros_list" placeholder="Bairro" value="<?= e($bairro) ?>"></div>
          <div><label>Complemento</label><input class="field" id="complemento" name="complemento" value="<?= e($complemento) ?>"></div>
          <div><label>Cidade</label><input class="field" id="cidade" name="cidade" value="<?= e($cidade) ?>"></div>
          <div><label>UF</label><input class="field" id="uf" name="uf" maxlength="2" value="<?= e($uf) ?>"></div>
          <div><label>Referência</label><input class="field" id="referencia" name="referencia" placeholder="Ponto de referência" value="<?= e($referencia) ?>"></div>
          <div><label>Nome do endereço</label><input class="field" name="address_label" id="addressLabel" placeholder="Casa, trabalho..." value="Entrega"></div>
          <div style="display:flex;align-items:flex-end;gap:12px;flex-wrap:wrap;">
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="save_address" id="saveAddress" value="1"> Salvar endereço</label>
            <label style="display:flex;gap:8px;align-items:center;"><input type="checkbox" name="address_default" id="addressDefault" value="1"> Tornar padrão</label>
          </div>
        </div>
        <datalist id="bairros_list">
          <?php foreach ($bairrosCfg as $bn => $v): ?>
            <option value="<?= e((string)$bn) ?>"></option>
          <?php endforeach; ?>
        </datalist>
      </div>

      <?php if ($deliverySchedulingEnabled): ?>
        <div class="checkout-section checkout-step" data-checkout-step="schedule">
          <h3>3. Previsão / agendamento</h3>
          <div class="checkout-grid checkout-schedule-grid">
            <div class="checkout-field-compact"><label><?= e($deliverySchedulingLabel) ?> - Data<?= $deliverySchedulingRequired ? ' *' : '' ?></label><input class="field" type="date" name="scheduled_delivery_date" id="scheduledDeliveryDate" value="<?= e($scheduledDeliveryDate) ?>" <?= $deliverySchedulingOnlyBusinessHours ? 'data-restrict-hours="1"' : '' ?> <?= $deliverySchedulingRequired ? 'required' : '' ?>></div>
            <div class="checkout-field-compact"><label><?= e($deliverySchedulingLabel) ?> - Hora<?= $deliverySchedulingRequired ? ' *' : '' ?></label><input class="field" type="time" name="scheduled_delivery_time" id="scheduledDeliveryTime" value="<?= e(substr($scheduledDeliveryTime, 0, 5)) ?>" <?= $deliverySchedulingOnlyBusinessHours ? 'data-restrict-hours="1"' : '' ?> <?= $deliverySchedulingRequired ? 'required' : '' ?>></div>
            <?php if ($deliverySchedulingOnlyBusinessHours || $deliverySchedulingMinMinutes > 0): ?>
              <div class="span-2 checkout-help" id="scheduledDeliveryHelp"><?= $deliverySchedulingMinMinutes > 0 ? e('Tempo mínimo entre pedido e previsão: ' . checkout_minutes_label($deliverySchedulingMinMinutes) . '.') : 'Escolha uma data e horário dentro do funcionamento da loja.' ?></div>
            <?php endif; ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="checkout-section checkout-step" data-checkout-step="payment">
        <h3><?= $deliverySchedulingEnabled ? '4' : '3' ?>. Pagamento e observações</h3>
        <div class="checkout-grid">
          <div>
            <label>Forma de pagamento *</label>
            <select class="field" name="forma_pagamento" required>
              <option value="" <?= ($formaPgto === '' ? 'selected' : '') ?>>Forma de pagamento</option>
              <option value="pix" <?= ($formaPgto === 'pix' ? 'selected' : '') ?>>Pix</option>
              <option value="dinheiro" <?= ($formaPgto === 'dinheiro' ? 'selected' : '') ?>>Dinheiro</option>
              <option value="debito" <?= ($formaPgto === 'debito' ? 'selected' : '') ?>>Cartão Débito</option>
              <option value="credito" <?= ($formaPgto === 'credito' ? 'selected' : '') ?>>Cartão Crédito</option>
            </select>
          </div>
          <div>
            <label>Resumo</label>
            <div class="notice" id="feeBox" style="display:flex;justify-content:space-between;gap:10px;align-items:center;margin:0;min-height:48px;">
              <div class="muted small">Subtotal: <b id="subTxt"><?= money((float)$total) ?></b><br>Entrega: <b id="feeTxt">—</b></div>
              <div style="text-align:right;"><div class="muted small">Total</div><div style="font-weight:950;font-size:18px;" id="totTxt"><?= money((float)($total + (float)$taxaEntregaPadrao)) ?></div></div>
            </div>
          </div>
          <div class="span-2"><label>Observações gerais do pedido</label><textarea class="field" name="observacoes" placeholder="Observações gerais do pedido (opcional)"><?= e($obsPedidoOnly ?? '') ?></textarea></div>
        </div>
      </div>

      <button class="checkout-hidden" type="submit" id="checkoutRealSubmit">Confirmar pedido</button>
      <div class="checkout-wizard-nav" id="checkoutWizardNav">
        <button class="btn" type="button" id="checkoutBackBtn">Voltar</button>
        <div class="checkout-step-caption" id="checkoutStepCaption">Passo 1 de <?= (int)$checkoutWizardSteps ?></div>
        <button class="btn checkout-next" type="button" id="checkoutNextBtn">Avançar</button>
      </div>
    </form>

    <script>
      (function(){
        const $ = (id) => document.getElementById(id);
        const onlyDigits = (value) => String(value || '').replace(/\D+/g, '');
        const sub = <?= json_encode((float)$total) ?>;
        const fees = <?= json_encode((array)($biz['delivery_fees']['bairros'] ?? [])) ?>;
        const feeDefault = <?= json_encode((float)($biz['delivery_fees']['default'] ?? 0)) ?>;
        const checkoutDefaultType = <?= json_encode((string)($checkoutDeliveryTypes[0] ?? 'retirada')) ?>;
        const restrictScheduleHours = <?= json_encode((bool)$deliverySchedulingOnlyBusinessHours) ?>;
        const minScheduleMinutes = <?= json_encode((int)$deliverySchedulingMinMinutes) ?>;
        const businessHours = <?= json_encode((array)($biz['hours'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;

        const form = $('checkoutForm');
        const type = $('personType');
        const documentInput = $('document');
        const whatsappInput = $('whatsapp');
        const whatsappWarn = $('whatsappLengthWarning');
        const docLabel = $('documentLabel');
        const dateLabel = $('dateLabel');
        const btnSearch = $('btnSearchCustomer');
        const lookupStatus = $('customerLookupStatus');
        const customerId = $('customerId');
        const lgpdBox = $('lgpdBox');
        const lgpdInput = $('lgpdAccept');
        const lgpdAlreadyRegistered = $('lgpdAlreadyRegistered');
        const addrBox = $('addressSavedBox');
        const addrSelect = $('customerAddressSelect');
        const zip = $('zipCode');
        const zipStatus = $('zipLookupStatus');
        const bairro = $('bairro');
        const endereco = $('endereco');
        const radios = document.querySelectorAll('input[name="tipo_entrega"]');
        const feeTxt = $('feeTxt');
        const totTxt = $('totTxt');
        const wizardSteps = Array.from(document.querySelectorAll('[data-checkout-step]'));
        const wizardPills = Array.from(document.querySelectorAll('[data-step-pill]'));
        const wizardBackBtn = $('checkoutBackBtn');
        const wizardNextBtn = $('checkoutNextBtn');
        const wizardCaption = $('checkoutStepCaption');
        const wizardStepInput = $('checkoutStepInput');
        const realSubmit = $('checkoutRealSubmit');
        const cartUrl = <?= json_encode(asset_url('carrinho.php', $app), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?>;
        let currentWizardStep = 0;
        let wizardSubmitting = false;
        let loadedAddresses = [];

        function setStatus(el, msg, cls){ if(!el) return; el.textContent = msg || ''; el.className = 'checkout-status' + (cls ? ' ' + cls : ''); }
        function setVal(id, value, overwrite=true){ const el=$(id); if(!el || value===undefined || value===null) return; if(!overwrite && String(el.value||'').trim()!=='') return; el.value = value; }
        function syncLgpdVisibility(){
          const found = Number(customerId?.value || 0) > 0;
          if (lgpdBox) lgpdBox.style.display = found ? 'none' : 'flex';
          if (lgpdAlreadyRegistered) lgpdAlreadyRegistered.style.display = found ? 'block' : 'none';
          if (lgpdInput) {
            lgpdInput.disabled = found;
            if (found) lgpdInput.checked = false;
          }
        }
        function money(v){ try { return 'R$ ' + (Number(v).toFixed(2)).replace('.', ','); } catch(e){ return v; } }
        function formatCpf(v){ v=onlyDigits(v).slice(0,11); return v.replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d{1,2})$/,'$1-$2'); }
        function formatCnpj(v){ v=onlyDigits(v).slice(0,14); return v.replace(/(\d{2})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1.$2').replace(/(\d{3})(\d)/,'$1/$2').replace(/(\d{4})(\d{1,2})$/,'$1-$2'); }
        function formatCep(v){ v=onlyDigits(v).slice(0,8); return v.replace(/(\d{5})(\d{1,3})$/,'$1-$2'); }
        function syncDocumentMode(){
          const digits = onlyDigits(documentInput?.value || '');
          if (digits.length > 11 && type) type.value = 'JURIDICA';
          const isJ = type && type.value === 'JURIDICA';
          docLabel.textContent = isJ ? 'CNPJ' : 'CPF';
          dateLabel.textContent = isJ ? 'Data de abertura *' : 'Data de nascimento *';
          const fantasyWrap = $('fantasyNameWrap');
          if (fantasyWrap) fantasyWrap.style.display = isJ ? '' : 'none';
          if (!isJ) setVal('fantasyName', '', true);
          if (documentInput && (digits.length === 11 || digits.length === 14)) documentInput.value = isJ ? formatCnpj(digits) : formatCpf(digits);
        }
        function getTipo(){ return document.querySelector('input[name="tipo_entrega"]:checked')?.value || checkoutDefaultType || 'retirada'; }
        function syncWhatsappWarning(){
          if (!whatsappInput || !whatsappWarn) return;
          const digits = onlyDigits(whatsappInput.value || '');
          if (digits !== '' && digits.length !== 10 && digits.length !== 11) {
            setStatus(whatsappWarn, `Atenção: este número possui ${digits.length} dígitos. Celular com DDD normalmente tem 11 e telefone fixo com DDD tem 10. Você pode continuar mesmo assim.`, 'err');
          } else {
            setStatus(whatsappWarn, '', '');
          }
        }
        function calcFee(){
          if (getTipo() === 'retirada') return 0;
          const b = (bairro?.value || '').trim().toLowerCase();
          if (!b) return feeDefault;
          for (const k in fees){ if (String(k).trim().toLowerCase() === b) return Number(fees[k] || 0); }
          return feeDefault;
        }
        function syncFee(){
          if (endereco) endereco.placeholder = getTipo() === 'entrega' ? 'Rua, avenida...' : 'Endereço opcional para retirada';
          const fee = calcFee();
          if (feeTxt) feeTxt.textContent = money(fee);
          const grandTotal = sub + fee;
          if (totTxt) totTxt.textContent = money(grandTotal);
          const floatingAmount = $('floatingCartAmount');
          if (floatingAmount) floatingAmount.textContent = money(grandTotal);
        }
        function scheduleDayKey(dateValue){
          if(!dateValue) return '';
          const d = new Date(dateValue + 'T00:00:00');
          if (Number.isNaN(d.getTime())) return '';
          return ['sun','mon','tue','wed','thu','fri','sat'][d.getDay()] || '';
        }
        function timeToMinutes(value){
          const m = String(value || '').match(/^(\d{2}):(\d{2})/);
          if(!m) return null;
          return (Number(m[1]) * 60) + Number(m[2]);
        }
        function scheduleHoursLabel(dateValue){
          const key = scheduleDayKey(dateValue);
          const rows = Array.isArray(businessHours[key]) ? businessHours[key] : [];
          return rows
            .filter(r => Array.isArray(r) && /^\d{2}:\d{2}$/.test(String(r[0]||'')) && /^\d{2}:\d{2}$/.test(String(r[1]||'')))
            .map(r => String(r[0]) + ' às ' + String(r[1]))
            .join(' ou ');
        }
        function scheduleIsInsideHours(dateValue, timeValue){
          const key = scheduleDayKey(dateValue);
          const t = timeToMinutes(timeValue);
          if(!key || t === null) return true;
          const rows = Array.isArray(businessHours[key]) ? businessHours[key] : [];
          for(const r of rows){
            if(!Array.isArray(r)) continue;
            const ini = timeToMinutes(r[0]);
            const fim = timeToMinutes(r[1]);
            if(ini === null || fim === null) continue;
            if(fim >= ini){
              if(t >= ini && t <= fim) return true;
            } else if(t >= ini || t <= fim){
              return true;
            }
          }
          return false;
        }
        function minScheduleLabel(){
          const m = Number(minScheduleMinutes || 0);
          if(m <= 0) return '';
          if(m === 1) return '1 minuto';
          if(m > 0 && m % 60 === 0){
            const h = Math.floor(m / 60);
            return h === 1 ? '1 hora' : h + ' horas';
          }
          if(m > 60){
            const h = Math.floor(m / 60);
            const r = m % 60;
            return h + 'h' + String(r).padStart(2, '0') + 'min';
          }
          return m + ' minutos';
        }
        function scheduleMeetsMinimum(dateValue, timeValue){
          const m = Number(minScheduleMinutes || 0);
          if(m <= 0 || !dateValue || !timeValue) return true;
          const chosen = new Date(dateValue + 'T' + String(timeValue).slice(0,5) + ':00');
          if(Number.isNaN(chosen.getTime())) return true;
          return chosen.getTime() >= (Date.now() + (m * 60000));
        }
        function syncScheduleHelp(){
          if(!restrictScheduleHours && Number(minScheduleMinutes || 0) <= 0) return true;
          const dateEl = $('scheduledDeliveryDate');
          const timeEl = $('scheduledDeliveryTime');
          const help = $('scheduledDeliveryHelp');
          if(!dateEl || !timeEl || !help) return true;
          const d = dateEl.value || '';
          const t = timeEl.value || '';
          const label = scheduleHoursLabel(d);
          const minLabel = minScheduleLabel();
          const baseMessages = [];
          if(restrictScheduleHours){
            baseMessages.push(d ? (label ? ('Horário disponível nesta data: ' + label + '.') : 'A loja não possui horário de funcionamento para a data escolhida.') : 'Escolha uma data e horário dentro do funcionamento da loja.');
          }
          if(minLabel){
            baseMessages.push('Tempo mínimo entre pedido e previsão: ' + minLabel + '.');
          }
          let msg = baseMessages.join(' ');
          let ok = true;
          if((d && !t) || (!d && t)){
            ok = false;
            msg = 'Informe a data e a hora da previsão de entrega.';
          } else if(d && t && restrictScheduleHours && !scheduleIsInsideHours(d, t)){
            ok = false;
            msg = label ? ('Horário fora do funcionamento. Use: ' + label + '.') : 'A loja está fechada na data escolhida.';
          } else if(d && t && !scheduleMeetsMinimum(d, t)){
            ok = false;
            msg = 'A previsão precisa respeitar o tempo mínimo de ' + minLabel + ' após o horário do pedido.';
          }
          help.textContent = msg || '';
          help.className = 'span-2 checkout-help checkout-status' + (ok ? '' : ' err');
          return ok;
        }
        function cleanAddressWithDistrict(address, district){
          let value = String(address || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
          const bairro = String(district || '').replace(/[\r\n]+/g, ' ').replace(/\s+/g, ' ').trim();
          if (!value) return '';
          const streetTypes = '(?:Rua|R\\.?|Avenida|Av\\.?|Alameda|Travessa|Tv\\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
          if (bairro) {
            const escBairro = bairro.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            value = value.replace(new RegExp('^Bairro:\\s*' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
            value = value.replace(new RegExp('^' + escBairro + '\\s*(?=' + streetTypes + '\\b)', 'iu'), '');
          }
          value = value.replace(new RegExp('^Bairro:\\s*.*?(?=' + streetTypes + '\\b)', 'iu'), '');
          value = value.replace(/^Bairro:\s*/iu, '');
          value = value.replace(/\s+/g, ' ').trim();
          if (bairro && value.toLocaleLowerCase('pt-BR') === bairro.toLocaleLowerCase('pt-BR')) return '';
          return value;
        }
        function applyAddress(a){
          if (!a) return;
          setVal('zipCode', formatCep(a.zip_code || ''), true);
          setVal('endereco', cleanAddressWithDistrict(a.address || '', a.district || ''), true);
          setVal('numero', a.number || '', true);
          setVal('complemento', a.complement || '', true);
          setVal('referencia', a.reference || '', true);
          setVal('bairro', a.district || '', true);
          setVal('cidade', a.city || '', true);
          setVal('uf', a.state || '', true);
          setVal('addressLabel', a.label || 'Entrega', true);
          syncFee();
        }
        function renderAddresses(addresses){
          loadedAddresses = Array.isArray(addresses) ? addresses : [];
          if (!addrSelect || !addrBox) return;
          addrSelect.innerHTML = '<option value="0">Usar novo/endereço digitado abaixo</option>';
          loadedAddresses.forEach(a => {
            const opt = document.createElement('option');
            opt.value = String(a.id || 0);
            const parts = [a.label || 'Entrega', cleanAddressWithDistrict(a.address || '', a.district || ''), a.number || '', a.district || ''].filter(Boolean);
            opt.textContent = parts.join(' - ') + (Number(a.is_default||0) ? ' (padrão)' : '');
            addrSelect.appendChild(opt);
          });
          addrBox.style.display = loadedAddresses.length ? 'grid' : 'none';
          if (loadedAddresses.length) { addrSelect.value = String(loadedAddresses[0].id || 0); applyAddress(loadedAddresses[0]); }
        }
        async function lookupCustomer(){
          if (documentInput && typeof documentInput.blur === 'function') documentInput.blur();
          const term = onlyDigits(documentInput?.value || '');
          if (term.length < 8) { setStatus(lookupStatus, 'Digite CPF, CNPJ ou telefone para pesquisar.', 'err'); return; }
          setStatus(lookupStatus, 'Pesquisando cadastro...', '');
          try {
            const resp = await fetch('checkout.php?ajax=customer_lookup&term=' + encodeURIComponent(term), {cache:'no-store'});
            const data = await resp.json();
            if (!data.ok || !data.customer) {
              customerId.value = '0';
              syncLgpdVisibility();
              renderAddresses([]);
              setStatus(lookupStatus, 'Cadastro não encontrado. Continue preenchendo os dados.', 'err');
              if (term.length === 14) lookupCnpj(term);
              return;
            }
            const c = data.customer;
            customerId.value = String(c.id || 0);
            syncLgpdVisibility();
            setVal('nome', c.name || '', true);
            setVal('fantasyName', c.fantasy_name || '', true);
            setVal('email', c.email || '', true);
            setVal('whatsapp', c.whatsapp || c.phone || '', true);
            setVal('birthOpeningDate', c.birth_opening_date || '', true);
            if (c.document) {
              documentInput.value = String(c.document).length > 11 ? formatCnpj(c.document) : formatCpf(c.document);
              if (type) type.value = String(c.document).length > 11 ? 'JURIDICA' : 'FISICA';
            }
            syncDocumentMode();
            const customerAddresses = Array.isArray(data.addresses) ? data.addresses : [];
            renderAddresses(customerAddresses);
            if (!customerAddresses.length) applyAddress(c);
            setStatus(lookupStatus, 'Cliente encontrado e carregado.', 'ok');
          } catch(e) { setStatus(lookupStatus, 'Não foi possível pesquisar agora.', 'err'); }
        }
        async function lookupCnpj(cnpjDigits){
          const cnpj = cnpjDigits || onlyDigits(documentInput?.value || '');
          if (cnpj.length !== 14) return;
          setStatus(lookupStatus, 'Consultando CNPJ...', '');
          try {
            const resp = await fetch('https://brasilapi.com.br/api/cnpj/v1/' + cnpj, {cache:'no-store'});
            if (!resp.ok) throw new Error('CNPJ não encontrado.');
            const data = await resp.json();
            setVal('nome', data.razao_social || data.nome || '', false);
            setVal('fantasyName', data.nome_fantasia || '', false);
            setVal('email', data.email || '', false);
            const tel = [data.ddd_telefone_1, data.ddd_telefone_2].filter(Boolean)[0] || '';
            setVal('whatsapp', tel, false);
            setVal('birthOpeningDate', data.data_inicio_atividade || '', false);
            if(data.cep) setVal('zipCode', formatCep(data.cep), false);
            setVal('endereco', data.logradouro || '', false);
            setVal('numero', data.numero || '', false);
            setVal('complemento', data.complemento || '', false);
            setVal('bairro', data.bairro || '', false);
            setVal('cidade', data.municipio || '', false);
            setVal('uf', data.uf || '', false);
            syncFee();
            setStatus(lookupStatus, 'Dados do CNPJ carregados. Confira antes de finalizar.', 'ok');
          } catch(e) { setStatus(lookupStatus, e.message || 'Não foi possível consultar o CNPJ.', 'err'); }
        }
        async function lookupCep(){
          const cep = onlyDigits(zip?.value || '');
          if (cep.length !== 8) return;
          setStatus(zipStatus, 'Consultando CEP...', '');
          try {
            const resp = await fetch('https://viacep.com.br/ws/' + cep + '/json/', {cache:'no-store'});
            if (!resp.ok) throw new Error('CEP não encontrado.');
            const data = await resp.json();
            if (data.erro) throw new Error('CEP não encontrado.');
            setVal('endereco', data.logradouro || '', true);
            setVal('bairro', data.bairro || '', true);
            setVal('cidade', data.localidade || '', true);
            setVal('uf', data.uf || '', true);
            setVal('complemento', data.complemento || '', false);
            syncFee();
            setStatus(zipStatus, 'Endereço carregado pelo CEP.', 'ok');
          } catch(e) { setStatus(zipStatus, e.message || 'Não foi possível consultar o CEP.', 'err'); }
        }
        function wizardStepName(index){
          const step = wizardSteps[index];
          return step ? String(step.dataset.checkoutStep || '') : '';
        }
        function showWizardStep(index, focusTop=true){
          if (!wizardSteps.length) return;
          index = Math.max(0, Math.min(index, wizardSteps.length - 1));
          currentWizardStep = index;
          wizardSteps.forEach((step, i) => step.classList.toggle('is-active', i === index));
          wizardPills.forEach((pill, i) => {
            pill.classList.toggle('is-active', i === index);
            pill.classList.toggle('is-done', i < index);
          });
          if (wizardStepInput) wizardStepInput.value = String(index + 1);
          if (wizardCaption) wizardCaption.textContent = 'Passo ' + (index + 1) + ' de ' + wizardSteps.length;
          if (wizardBackBtn) wizardBackBtn.textContent = '← Voltar';
          if (wizardNextBtn) wizardNextBtn.textContent = index === wizardSteps.length - 1 ? 'Confirmar pedido' : 'Avançar →';
          const activePill = wizardPills[index];
          if (activePill && activePill.scrollIntoView) activePill.scrollIntoView({behavior:'smooth', block:'nearest', inline:'center'});
          if (focusTop) {
            const stepper = $('checkoutStepper');
            if (stepper && stepper.scrollIntoView) stepper.scrollIntoView({behavior:'smooth', block:'start'});
          }
        }
        function reportInvalidField(field){
          if (!field) return false;
          try { field.reportValidity(); } catch(e) {}
          try { field.focus({preventScroll:false}); } catch(e) { try { field.focus(); } catch(_) {} }
          return false;
        }
        function validateNativeFields(step){
          if (!step) return true;
          const fields = Array.from(step.querySelectorAll('input,select,textarea')).filter(el => !el.disabled && el.type !== 'hidden');
          for (const field of fields) {
            if (typeof field.checkValidity === 'function' && !field.checkValidity()) return reportInvalidField(field);
          }
          return true;
        }
        function validateWizardStep(index, showMessages=true){
          const step = wizardSteps[index];
          if (!step) return true;
          const name = wizardStepName(index);
          if (!validateNativeFields(step)) return false;

          if (name === 'identification') {
            const docDigits = onlyDigits(documentInput?.value || '');
            const found = Number(customerId?.value || 0) > 0;
            if (docDigits !== '' && !found && lgpdInput && !lgpdInput.checked) {
              if (showMessages) alert('Para salvar os dados para próximos pedidos, confirme o aceite da LGPD.');
              try { lgpdInput.focus(); } catch(e) {}
              return false;
            }
          }

          if (name === 'delivery' && getTipo() === 'entrega') {
            if (!String(endereco?.value || '').trim()) {
              if (showMessages) alert('Informe o endereço para entrega.');
              try { endereco?.focus(); } catch(e) {}
              return false;
            }
            if (!String(bairro?.value || '').trim()) {
              if (showMessages) alert('Informe o bairro para calcular a taxa de entrega.');
              try { bairro?.focus(); } catch(e) {}
              return false;
            }
          }

          if (name === 'schedule') {
            const dateEl = $('scheduledDeliveryDate');
            const timeEl = $('scheduledDeliveryTime');
            const d = String(dateEl?.value || '').trim();
            const t = String(timeEl?.value || '').trim();
            if ((d && !t) || (!d && t)) {
              if (showMessages) alert('Informe a data e a hora da previsão/agendamento.');
              return reportInvalidField(!d ? dateEl : timeEl);
            }
            if (!syncScheduleHelp()) {
              if (showMessages) alert(($('scheduledDeliveryHelp')?.textContent || 'A previsão/agendamento precisa ser ajustada.'));
              return false;
            }
          }
          return true;
        }
        function validateAllWizardSteps(){
          for (let i = 0; i < wizardSteps.length; i++) {
            showWizardStep(i, false);
            if (!validateWizardStep(i, true)) return false;
          }
          return true;
        }
        function initialWizardStep(){
          const errorText = <?= json_encode((string)$erro, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>.toLocaleLowerCase('pt-BR');
          if (!errorText) return 0;
          if (errorText.includes('endereço') || errorText.includes('bairro') || errorText.includes('taxa de entrega')) {
            return wizardSteps.findIndex(s => s.dataset.checkoutStep === 'delivery');
          }
          if (errorText.includes('previs') || errorText.includes('data e a hora') || errorText.includes('horário de funcionamento')) {
            const idx = wizardSteps.findIndex(s => s.dataset.checkoutStep === 'schedule');
            return idx >= 0 ? idx : 0;
          }
          if (errorText.includes('pagamento')) {
            const idx = wizardSteps.findIndex(s => s.dataset.checkoutStep === 'payment');
            return idx >= 0 ? idx : wizardSteps.length - 1;
          }
          return 0;
        }
        if (type) type.addEventListener('change', syncDocumentMode);
        if (documentInput) {
          documentInput.addEventListener('input', () => { customerId.value = '0'; syncLgpdVisibility(); syncDocumentMode(); });
          documentInput.addEventListener('blur', () => { const d=onlyDigits(documentInput.value); if(d.length===11 || d.length===14 || d.length>=8) lookupCustomer(); });
        }
        if (btnSearch) btnSearch.addEventListener('click', lookupCustomer);
        if (whatsappInput) {
          whatsappInput.addEventListener('input', syncWhatsappWarning);
          whatsappInput.addEventListener('blur', syncWhatsappWarning);
        }
        if (form) form.addEventListener('submit', syncWhatsappWarning);
        if (zip) { zip.addEventListener('input', () => { zip.value = formatCep(zip.value); }); zip.addEventListener('blur', lookupCep); }
        if (addrSelect) addrSelect.addEventListener('change', () => { const a=loadedAddresses.find(x => String(x.id) === String(addrSelect.value)); if(a) applyAddress(a); });
        radios.forEach(r => r.addEventListener('change', syncFee));
        if (bairro) bairro.addEventListener('input', syncFee);
        if ($('scheduledDeliveryDate')) $('scheduledDeliveryDate').addEventListener('change', syncScheduleHelp);
        if ($('scheduledDeliveryTime')) $('scheduledDeliveryTime').addEventListener('change', syncScheduleHelp);
        if (form) form.addEventListener('submit', (ev) => {
          if (wizardSubmitting) return;
          ev.preventDefault();
          if (currentWizardStep < wizardSteps.length - 1) {
            if (!validateWizardStep(currentWizardStep, true)) return;
            showWizardStep(currentWizardStep + 1);
            return;
          }
          if (!validateAllWizardSteps()) return;
          showWizardStep(wizardSteps.length - 1, false);
          wizardSubmitting = true;
          if (realSubmit) realSubmit.click();
        });
        if (wizardBackBtn) wizardBackBtn.addEventListener('click', () => {
          if (currentWizardStep <= 0) { window.location.href = cartUrl; return; }
          showWizardStep(currentWizardStep - 1);
        });
        if (wizardNextBtn) wizardNextBtn.addEventListener('click', () => {
          if (currentWizardStep < wizardSteps.length - 1) {
            if (!validateWizardStep(currentWizardStep, true)) return;
            showWizardStep(currentWizardStep + 1);
            return;
          }
          if (!validateAllWizardSteps()) return;
          showWizardStep(wizardSteps.length - 1, false);
          wizardSubmitting = true;
          if (realSubmit) realSubmit.click();
        });
        syncDocumentMode(); syncLgpdVisibility(); syncWhatsappWarning(); syncFee(); syncScheduleHelp();
        showWizardStep(initialWizardStep(), false);
      })();
    </script>
  </div></div>

<?php endif; ?>

<?php require __DIR__ . '/_layout_bottom.php'; ?>