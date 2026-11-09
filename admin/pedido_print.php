<?php
declare(strict_types=1);

// Página de impressão (admin). Mantém autenticação e tenant.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/company_sequences.php';
require_once __DIR__ . '/../config/fiscal_operational.php';
require_once __DIR__ . '/../config/internal_delivery_status.php';
require_once __DIR__ . '/_print_brand.php';

// V11.24: a impressão simples acompanha a empresa selecionada pelo F11,
// mas permanece estritamente em modo leitura. Nenhuma DDL/backfill é executada
// quando a consulta está operando em empresa diferente do tenant de entrada.
$orderPrintCompanyId = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'sales_orders_read')
  : (int)COMPANY_ID;
$orderPrintCross = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'sales_orders_read');
$orderPrintCompanyName = '';
try {
  $stCompany = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
  $stCompany->execute([$orderPrintCompanyId]);
  $orderPrintCompanyName = trim((string)($stCompany->fetchColumn() ?: ''));
} catch (Throwable $e) {}

if ($orderPrintCross && function_exists('multi_company_context_permission_allowed')) {
  $canViewOrderPrint = multi_company_context_permission_allowed($pdo, 'gestao_pedidos')
    || multi_company_context_permission_allowed($pdo, 'pedidos');
  if (!$canViewOrderPrint) {
    http_response_code(403);
    echo 'Sem permissão para consultar pedidos na empresa selecionada pelo F11.';
    exit;
  }
}

if (!$orderPrintCross) {
  company_sequence_ensure_schema($pdo, true);
  internal_delivery_status_ensure_schema($pdo);
}

// O bootstrap legado carrega APP_CFG para COMPANY_ID. Em contexto cruzado lemos a
// configuração visual da empresa selecionada diretamente, sem chamar config_store_init().
$app = is_array($APP_CFG ?? null) ? $APP_CFG : [];
if ($orderPrintCross) {
  try {
    $baseApp = require __DIR__ . '/../config/app.php';
    $app = is_array($baseApp) ? $baseApp : [];
    if (function_exists('multi_company_table_exists') && multi_company_table_exists($pdo, 'app_config')) {
      $stAppCfg = $pdo->prepare('SELECT cfg_json FROM app_config WHERE company_id=? AND cfg_key=? LIMIT 1');
      $stAppCfg->execute([$orderPrintCompanyId, 'app']);
      $appOver = json_decode((string)($stAppCfg->fetchColumn() ?: ''), true);
      if (is_array($appOver)) {
        $app = function_exists('config_deep_merge') ? config_deep_merge($app, $appOver) : array_replace_recursive($app, $appOver);
      }
    }
  } catch (Throwable $e) {}
}

$printLogoUrl = nexa_print_logo_url($orderPrintCompanyId);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
  header('Location: pedidos.php');
  exit;
}

function admin_print_h($value): string {
  return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function admin_print_safe_return(string $value, string $fallback = 'pedidos.php'): string {
  $value = trim($value);
  if ($value === '') return $fallback;
  if (preg_match('/^https?:\/\//i', $value) || str_starts_with($value, '//') || str_contains($value, "\n") || str_contains($value, "\r")) return $fallback;
  return ltrim($value, '/');
}

function admin_clean_order_address_with_district(string $value, string $district = ''): string {
  $value = str_replace(["\r\n", "\r", "\n"], ' ', trim($value));
  $value = preg_replace('/\s+/u', ' ', $value) ?: $value;
  $district = str_replace(["\r\n", "\r", "\n"], ' ', trim($district));
  $district = preg_replace('/\s+/u', ' ', $district) ?: $district;
  if ($value === '') return '';
  $streetTypes = '(?:Rua|R\.?|Avenida|Av\.?|Alameda|Travessa|Tv\.?|Pra[cç]a|Rodovia|Estrada|Viela|Largo|Quadra|Condom[ií]nio|Conjunto|Residencial)';
  if ($district !== '') {
    $districtQuoted = preg_quote($district, '/');
    $value = preg_replace('/^Bairro:\s*' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
    $value = preg_replace('/^' . $districtQuoted . '\s*(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
    if (mb_strtolower(trim($value)) === mb_strtolower($district)) return '';
  }
  $value = preg_replace('/^Bairro:\s*.*?(?=' . $streetTypes . '\b)/iu', '', $value) ?? $value;
  $value = preg_replace('/^Bairro:\s*/iu', '', $value) ?? $value;
  $value = preg_replace('/\s+/u', ' ', trim($value)) ?: $value;
  if ($district !== '' && mb_strtolower(trim($value)) === mb_strtolower($district)) return '';
  return trim($value);
}

function admin_extract_after_known_district(string $text, string $district): string {
  $text = trim($text);
  $district = trim($district);
  if ($text === '' || $district === '') return $text;
  return trim((string)preg_replace('/^' . preg_quote($district, '/') . '\s*/iu', '', $text, 1));
}

$st = $pdo->prepare('SELECT * FROM pedidos WHERE id = ? AND company_id = ?');
$st->execute([$id, $orderPrintCompanyId]);
$pedido = $st->fetch(PDO::FETCH_ASSOC);
if (!$pedido) {
  http_response_code(404);
  echo 'Pedido não encontrado';
  exit;
}

$orderSellerId = (int)($pedido['seller_id'] ?? 0);
$orderSellerName = '';
if ($orderSellerId > 0) {
  try {
    $stSeller = $pdo->prepare('SELECT name FROM finance_people WHERE company_id=? AND id=? LIMIT 1');
    $stSeller->execute([$orderPrintCompanyId, $orderSellerId]);
    $orderSellerName = trim((string)($stSeller->fetchColumn() ?: ''));
  } catch (Throwable $e) {}
  if ($orderSellerName === '') $orderSellerName = 'Vendedor #' . $orderSellerId;
}

function admin_fill_missing_order_address_from_customer(PDO $pdo, array $pedido, int $companyId): array {
  if (trim((string)($pedido['endereco'] ?? '')) !== '') {
    $pedido['endereco'] = admin_clean_order_address_with_district((string)$pedido['endereco'], (string)($pedido['bairro'] ?? ''));
  }
  $hasAddress = trim((string)($pedido['endereco'] ?? '')) !== '';
  $hasDistrict = trim((string)($pedido['bairro'] ?? '')) !== '';
  $hasZip = preg_replace('/\D+/', '', (string)($pedido['zip_code'] ?? $pedido['cep'] ?? '')) !== '';
  $hasWhatsapp = preg_replace('/\D+/', '', (string)($pedido['whatsapp'] ?? '')) !== '';
  if ($hasAddress && $hasDistrict && $hasZip && $hasWhatsapp) return $pedido;

  $personId = (int)($pedido['finance_person_id'] ?? $pedido['customer_id'] ?? 0);
  if ($personId <= 0) return $pedido;

  $addr = null;
  try {
    $st = $pdo->prepare('SELECT * FROM gestao_customer_addresses WHERE company_id = ? AND person_id = ? AND active = 1 ORDER BY is_default DESC, id ASC LIMIT 1');
    $st->execute([$companyId, $personId]);
    $addr = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) {}

  try {
    $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $personId]);
    $person = $st->fetch(PDO::FETCH_ASSOC) ?: null;
  } catch (Throwable $e) { $person = null; }

  $src = $addr ?: $person;
  if (!$src) return $pedido;

  if (trim((string)($pedido['endereco'] ?? '')) === '') $pedido['endereco'] = admin_clean_order_address_with_district((string)($src['address'] ?? ''), (string)($src['district'] ?? $pedido['bairro'] ?? ''));
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

$pedido = admin_fill_missing_order_address_from_customer($pdo, $pedido, $orderPrintCompanyId);
$internalDeliveryLabel = '';
$internalDeliveryStatusId = (int)($pedido['internal_delivery_status_id'] ?? 0);
if ($internalDeliveryStatusId > 0) {
  if ($orderPrintCross) {
    try {
      if (function_exists('multi_company_table_exists') && multi_company_table_exists($pdo, 'gestao_internal_delivery_statuses')) {
        $stDelivery = $pdo->prepare('SELECT name FROM gestao_internal_delivery_statuses WHERE company_id=? AND id=? LIMIT 1');
        $stDelivery->execute([$orderPrintCompanyId, $internalDeliveryStatusId]);
        $internalDeliveryLabel = trim((string)($stDelivery->fetchColumn() ?: ''));
      }
    } catch (Throwable $e) {}
  } else {
    $internalDeliveryLabel = internal_delivery_status_label($pdo, $orderPrintCompanyId, $internalDeliveryStatusId);
  }
}
$pedidoNumero = company_sequence_display($pedido, 'pedidos');
$returnTo = admin_print_safe_return((string)($_GET['return_to'] ?? 'pedidos.php'), 'pedidos.php');
if ($orderPrintCross && function_exists('multi_company_context_url')) {
  $returnTo = multi_company_context_url($returnTo);
}
$fiscalPrintCfg = [];
if ($orderPrintCross) {
  // Somente leitura: busca apenas as preferências de impressão já existentes.
  try {
    if (function_exists('multi_company_table_exists') && multi_company_table_exists($pdo, 'fiscal_company_config')) {
      $stPrintCfg = $pdo->prepare('SELECT * FROM fiscal_company_config WHERE company_id=? LIMIT 1');
      $stPrintCfg->execute([$orderPrintCompanyId]);
      $fiscalPrintCfg = $stPrintCfg->fetch(PDO::FETCH_ASSOC) ?: [];
    }
  } catch (Throwable $e) {
    $fiscalPrintCfg = [];
  }
} else {
  $fiscalPrintCfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $orderPrintCompanyId) : [];
}
$orderAutoPrintEnabled = !array_key_exists('order_auto_print_enabled', $fiscalPrintCfg) || !empty($fiscalPrintCfg['order_auto_print_enabled']);
$printPack58mm = !empty($fiscalPrintCfg['print_pack_58mm_enabled']);
$orderModernPrintEnabled = !empty($fiscalPrintCfg['order_modern_print_enabled']);
$orderModernPrintDefault = strtoupper(trim((string)($fiscalPrintCfg['order_modern_print_default'] ?? 'A4')));
if (!in_array($orderModernPrintDefault, ['A4','72MM'], true)) $orderModernPrintDefault = 'A4';
$orderPrintForceLegacy = (string)($_GET['legacy'] ?? '') === '1';
$orderModernPrintModel = strtolower(trim((string)($_GET['modelo'] ?? ($orderModernPrintDefault === '72MM' ? '72mm' : 'a4'))));
if (!in_array($orderModernPrintModel, ['72mm','a4'], true)) $orderModernPrintModel = $orderModernPrintDefault === '72MM' ? '72mm' : 'a4';
$autoPrint = $orderAutoPrintEnabled && ((string)($_GET['autoprint'] ?? '1') !== '0');
$pedidoWhatsapp = preg_replace('/\D+/', '', (string)($pedido['whatsapp'] ?? ''));
$printReturnTo = 'pedido_print.php?id=' . $id . '&return_to=' . rawurlencode($returnTo) . '&autoprint=0';
if ($orderPrintCross && function_exists('multi_company_context_url')) {
  $printReturnTo = multi_company_context_url($printReturnTo);
}

$sti = $pdo->prepare('
  SELECT pi.*, p.nome
  FROM pedido_itens pi
  LEFT JOIN produtos p ON p.id = pi.produto_id AND p.company_id = pi.company_id
  WHERE pi.pedido_id = ? AND pi.company_id = ?
  ORDER BY pi.id ASC
');
$sti->execute([$id, $orderPrintCompanyId]);
$itens = $sti->fetchAll(PDO::FETCH_ASSOC);

// Detecta coluna de observação do item, se existir.
function admin_detect_item_obs_col_from_rows(array $itens): ?string {
  if (empty($itens) || !is_array($itens[0] ?? null)) return null;
  $candidates = ['complemento','observacao','obs','observacoes','adicionais','observacao_item'];
  foreach (array_keys($itens[0]) as $key) {
    foreach ($candidates as $candidate) {
      if (mb_strtolower((string)$key) === mb_strtolower($candidate)) return (string)$key;
    }
  }
  return null;
}
$itemObsCol = admin_detect_item_obs_col_from_rows($itens);

// Detecta coluna de observação do pedido.
$pedidoObsCol = null;
foreach (['observacoes','observacao','obs'] as $c) {
  if (array_key_exists($c, $pedido)) { $pedidoObsCol = $c; break; }
}

function money_br(float $v): string { return 'R$ ' . number_format($v, 2, ',', '.'); }
function qty_br(float $v): string {
  return number_format($v, 2, ',', '.');
}

function scheduled_delivery_label_admin(array $pedido): string {
  $date = trim((string)($pedido['scheduled_delivery_date'] ?? ''));
  if ($date === '') return '';
  $label = date('d/m/Y', strtotime($date));
  $time = trim((string)($pedido['scheduled_delivery_time'] ?? ''));
  if ($time !== '') $label .= ' ' . substr($time, 0, 5);
  return $label;
}
function item_unit_price_admin(array $i): float {
  foreach (['preco_unit','preco_unitario','valor_unitario','unitario','preco','valor'] as $c) {
    if (array_key_exists($c, $i) && is_numeric($i[$c])) return (float)$i[$c];
  }
  $qtd = (float)($i['qtd'] ?? 0);
  $total = (float)($i['total'] ?? 0);
  return $qtd > 0 ? round($total / $qtd, 4) : $total;
}
function split_order_item_notes_admin(string $raw): array {
  $raw = trim($raw);
  if ($raw === '') return ['', [], []];
  $parts = preg_split('/(?:^|\R+)Obs\.? por item:\R+/u', $raw, 2);
  $pedidoObs = trim((string)($parts[0] ?? ''));
  $queues = [];
  $queue = [];
  $lastName = null;

  if (!empty($parts[1])) {
    foreach (preg_split('/\R/u', trim((string)$parts[1])) as $line) {
      $line = trim($line);
      if ($line === '') continue;

      if (preg_match('/^[-•]\s*(.+?)\s*:\s*(.+)$/u', $line, $m)) {
        $name = trim($m[1]);
        $note = trim($m[2]);
        if ($name === '' || $note === '') continue;
        $queues[$name][] = $note;
        $queue[] = ['name' => $name, 'note' => $note];
        $lastName = $name;
        continue;
      }

      // Linha de continuação do item anterior. Isso preserva grupos de adicionais em várias linhas.
      if ($lastName !== null && isset($queues[$lastName]) && $queues[$lastName]) {
        $idx = count($queues[$lastName]) - 1;
        $queues[$lastName][$idx] .= "
" . $line;
        $qidx = count($queue) - 1;
        if ($qidx >= 0 && ($queue[$qidx]['name'] ?? '') === $lastName) {
          $queue[$qidx]['note'] .= "
" . $line;
        }
      }
    }
  }
  return [$pedidoObs, $queues, $queue];
}
function dequeue_item_note_admin(array &$queues, array &$queue, string $name): string {
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
function build_item_note_map_admin(array $itens, array &$queues, array &$queue): array {
  $notes = array_fill(0, count($itens), '');
  for ($idx = 0; $idx < count($itens); $idx++) {
    $nome = (string)($itens[$idx]['nome'] ?? ('#'.(int)($itens[$idx]['produto_id'] ?? 0)));
    $qtd = (float)($itens[$idx]['qtd'] ?? 0);
    $nextQtd = ($idx + 1 < count($itens)) ? (float)($itens[$idx + 1]['qtd'] ?? 0) : 0;

    if (abs($qtd - 0.5) < 0.0001 && abs($nextQtd - 0.5) < 0.0001) {
      $shared = '';
      foreach (['Pizza meio a meio','PIZZA MEIO A MEIO','pizza meio a meio'] as $k) {
        $shared = dequeue_item_note_admin($queues, $queue, $k);
        if ($shared !== '') break;
      }
      if ($shared === '') $shared = dequeue_item_note_admin($queues, $queue, $nome);
      if ($shared === '') {
        $nome2 = (string)($itens[$idx + 1]['nome'] ?? ('#'.(int)($itens[$idx + 1]['produto_id'] ?? 0)));
        $shared = dequeue_item_note_admin($queues, $queue, $nome2);
      }
      $notes[$idx] = $shared;
      $notes[$idx + 1] = ''; // não duplica complemento da pizza meio a meio no segundo item
      $idx++;
      continue;
    }

    // Importante: não usar fila genérica para item normal.
    // Antes, uma observação de Prato Executivo podia ser exibida na Coca-Cola por estar antes na lista.
    $notes[$idx] = dequeue_item_note_admin($queues, $queue, $nome);
  }
  return $notes;
}

$site = $orderPrintCompanyName !== '' ? $orderPrintCompanyName : (string)($app['site_name'] ?? 'Nexa Próspero');
$tipoEntrega = (string)($pedido['tipo_entrega'] ?? '');
$formaPgto = (string)($pedido['forma_pagamento'] ?? '');
$taxaEntrega = (float)($pedido['taxa_entrega'] ?? 0);
$totalProdutos = (float)($pedido['total_produtos'] ?? ($pedido['total'] ?? 0));
$totalGeral = (float)($pedido['total'] ?? 0);
$obsRaw = trim((string)($pedidoObsCol ? ($pedido[$pedidoObsCol] ?? '') : ''));
[$obs, $fallbackItemObsQueues, $fallbackItemObsQueue] = split_order_item_notes_admin($obsRaw);
$fallbackItemObsByRow = build_item_note_map_admin($itens, $fallbackItemObsQueues, $fallbackItemObsQueue);

// --- Impressão em modo texto (72 colunas por padrão) ---
$COLS = (int)($app['print_cols'] ?? 40);
$PAPER = (string)($app['print_paper'] ?? '80mm');
if ($printPack58mm) {
  // Pack 58mm: perfil para impressoras de máquinas de cartão.
  // Usa 32 colunas para reduzir corte na margem direita.
  $PAPER = '58mm';
  $COLS = 32;
} else {
  if ($COLS < 32) $COLS = 32;
  if ($COLS > 96) $COLS = 96;
  // Impressoras térmicas 80mm variam bastante na área útil.
  // Mantemos uma folga de 2 colunas para não cortar valores na margem direita.
  if ($PAPER === '80mm' && $COLS > 40) $COLS = 40;
}

function line(string $s = ''): string { return rtrim($s) . "\n"; }
function cut(string $s, int $len): string {
  if (mb_strlen($s) <= $len) return $s;
  return mb_substr($s, 0, max(0, $len));
}
function padR(string $s, int $len): string {
  $s = cut($s, $len);
  $n = $len - mb_strlen($s);
  return $s . str_repeat(' ', max(0, $n));
}
function padL(string $s, int $len): string {
  $s = cut($s, $len);
  $n = $len - mb_strlen($s);
  return str_repeat(' ', max(0, $n)) . $s;
}
function wrap_text_admin(string $text, int $width): array {
  $width = max(8, $width);
  $text = str_replace(["
", "
"], "
", $text);
  $lines = [];
  foreach (preg_split('/
/u', $text) as $rawLine) {
    $line = trim(preg_replace('/[ 	]+/u', ' ', (string)$rawLine));
    if ($line === '') continue;
    while (mb_strlen($line) > $width) {
      $chunk = mb_substr($line, 0, $width);
      $breakAt = mb_strrpos($chunk, ' ');
      if ($breakAt !== false && $breakAt >= 10) {
        $lines[] = rtrim(mb_substr($line, 0, $breakAt));
        $line = ltrim(mb_substr($line, $breakAt + 1));
      } else {
        $lines[] = mb_substr($line, 0, $width);
        $line = ltrim(mb_substr($line, $width));
      }
    }
    if ($line !== '') $lines[] = $line;
  }
  return $lines;
}
function append_wrapped_admin(string &$out, string $text, string $prefix, int $cols): void {
  $width = max(8, $cols - mb_strlen($prefix));
  $first = true;
  foreach (wrap_text_admin($text, $width) as $line) {
    $out .= line(($first ? $prefix : str_repeat(' ', mb_strlen($prefix))) . $line);
    $first = false;
  }
}
function admin_print_split_order_address(array $pedido): array {
  $bairro = trim((string)($pedido['bairro'] ?? ''));
  $endereco = str_replace(["\r\n", "\r"], "\n", trim((string)($pedido['endereco'] ?? '')));
  $referencia = trim((string)($pedido['referencia'] ?? ''));
  $numero = trim((string)($pedido['numero'] ?? $pedido['number'] ?? ''));
  $complemento = trim((string)($pedido['complemento'] ?? $pedido['complement'] ?? ''));
  $cidade = trim((string)($pedido['cidade'] ?? $pedido['city'] ?? ''));
  $uf = trim((string)($pedido['uf'] ?? $pedido['state'] ?? ''));
  $cep = preg_replace('/\D+/', '', (string)($pedido['zip_code'] ?? $pedido['cep'] ?? ''));
  if ($cep !== '') $cep = preg_replace('/(\d{5})(\d{3})$/', '$1-$2', $cep);
  $lines = [];
  foreach (preg_split('/\n/u', $endereco) as $line) {
    $line = trim((string)$line);
    if ($line === '') continue;
    if (preg_match('/^Bairro:\s*(.+)$/iu', $line, $m)) {
      $bairroLinha = trim((string)$m[1]);
      $resto = $bairro !== '' ? admin_extract_after_known_district($bairroLinha, $bairro) : '';
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
  $enderecoLimpo = admin_clean_order_address_with_district(trim(implode("\n", $lines)), $bairro);
  $lineNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $enderecoLimpo) ?: $enderecoLimpo);
  foreach ([[$numero, ', '], [$complemento, ' - ']] as $partData) {
    [$part, $sep] = $partData;
    $part = trim((string)$part);
    if ($part !== '' && !str_contains($lineNorm, mb_strtolower($part))) {
      $enderecoLimpo = trim($enderecoLimpo . ($enderecoLimpo !== '' ? $sep : '') . $part);
      $lineNorm = mb_strtolower(preg_replace('/\s+/u', ' ', $enderecoLimpo) ?: $enderecoLimpo);
    }
  }
  return [$bairro, trim($enderecoLimpo), $referencia, $cidade, $uf, $cep];
}

function normalize_item_complement_admin(string $text): string {
  $text = str_replace(["
", "
"], "
", trim($text));
  if ($text === '') return '';
  // Corrige pedidos antigos gravados por INPUT hidden, onde as quebras viraram espaços.
  $text = preg_replace('/\s+(Pizza\s+meio\s+a\s+meio\s*:)/iu', "
$1", $text);
  $text = preg_replace('/\s+(1\/2\s+)/u', "
$1", $text);
  // Quando opções foram coladas, tenta separar transições tipo: Bife aceboladoArrozFeijão.
  $text = preg_replace('/(?<=[a-záéíóúâêôãõç])(?=[A-ZÁÉÍÓÚÂÊÔÃÕÇ])/u', "
", $text);
  $text = preg_replace('/\s*\+\s*R\$\s*/u', ' + R$ ', $text);
  return trim($text);
}

// V11.45.18: novo impresso é estritamente opt-in por empresa. O legado continua sendo o padrão
// para todos os clientes já instalados e pode ser forçado com legacy=1 mesmo após habilitar o novo modelo.
if ($orderModernPrintEnabled && !$orderPrintForceLegacy) {
  $modernOrderPrintModel = $orderModernPrintModel;
  require __DIR__ . '/_pedido_print_moderno.php';
  exit;
}

$out = '';
$out .= line(str_repeat('=', $COLS));
$out .= line(padR($site, $COLS));
$out .= line(str_repeat('-', $COLS));
$out .= line('PEDIDO: #' . (int)$pedidoNumero . '   DATA: ' . (string)($pedido['created_at'] ?? ''));
$out .= line('CLIENTE: ' . (string)($pedido['nome_cliente'] ?? ''));
if ($orderSellerName !== '') $out .= line('VENDEDOR: ' . $orderSellerName);
$out .= line('WHATSAPP: ' . (string)($pedido['whatsapp'] ?? ''));
if (trim((string)($pedido['customer_document'] ?? '')) !== '') {
  $out .= line('CPF/CNPJ: ' . (string)$pedido['customer_document']);
}
$scheduledLabel = scheduled_delivery_label_admin($pedido);
if ($scheduledLabel !== '') {
  $out .= line('DATA/HORA PREVISTA: ' . $scheduledLabel);
}

if ($tipoEntrega !== '') {
  $lbl = ($tipoEntrega === 'retirada') ? (string)($app['retirada_label'] ?? 'Retirada') : 'Entrega';
  $out .= line('ENTREGA: ' . $lbl);
}

[$printBairro, $printEndereco, $printReferencia, $printCidade, $printUf, $printCep] = admin_print_split_order_address($pedido);
if ($printEndereco !== '') {
  $addr = preg_replace('/\s+/', ' ', trim($printEndereco));
  append_wrapped_admin($out, $addr, 'ENDEREÇO: ', $COLS);
}
if ($printCep !== '') {
  append_wrapped_admin($out, $printCep, 'CEP: ', $COLS);
}
if ($printBairro !== '') {
  append_wrapped_admin($out, $printBairro, 'BAIRRO: ', $COLS);
}
if ($printReferencia !== '') {
  append_wrapped_admin($out, $printReferencia, 'REFERÊNCIA: ', $COLS);
}

if ($formaPgto !== '') {
  $pgto = $formaPgto;
  if ($pgto === 'pix') $pgto = 'Pix';
  if ($pgto === 'dinheiro') $pgto = 'Dinheiro';
  if ($pgto === 'debito') $pgto = 'Cartão Débito';
  if ($pgto === 'credito') $pgto = 'Cartão Crédito';
  $out .= line('PAGAMENTO: ' . $pgto);
}

if ($obs !== '') {
  append_wrapped_admin($out, preg_replace('/\s+/', ' ', $obs), 'OBS: ', $COLS);
}

$out .= line(str_repeat('-', $COLS));
$out .= line('ITEM');
$out .= line(str_repeat('-', $COLS));

$lastHalfNote = '';
$lastWasHalf = false;
foreach ($itens as $__itemIdx => $i) {
  $qtdFloat = (float)($i['qtd'] ?? 0);
  $qtd = qty_br($qtdFloat);
  $nome = (string)($i['nome'] ?? ('#' . (int)($i['produto_id'] ?? 0)));
  $unit = item_unit_price_admin($i);
  $tot = money_br((float)($i['total'] ?? 0));

  // Produto sozinho na primeira linha; observações/adicionais nas linhas seguintes.
  append_wrapped_admin($out, $nome, '', $COLS);

  // Obs/adicionais por item. Não usa reticências: quebra linha sem perder caracteres.
  $io = '';
  if ($itemObsCol) {
    $io = trim((string)($i[$itemObsCol] ?? ''));
  }
  if ($io === '') {
    $io = (string)($fallbackItemObsByRow[$__itemIdx] ?? '');
  }
  $io = normalize_item_complement_admin($io);

  // Se a pizza meio a meio vier em dois itens de 0,5 e o complemento estiver igual,
  // imprime o complemento apenas no primeiro item para evitar duplicidade.
  $isHalf = abs($qtdFloat - 0.5) < 0.0001;
  if ($isHalf && $lastWasHalf && $io !== '' && $lastHalfNote !== '' && $io === $lastHalfNote) {
    $io = '';
  }
  if ($io !== '') {
    foreach (preg_split('/\R/u', trim($io)) as $noteLine) {
      $noteLine = trim((string)$noteLine);
      if ($noteLine === '') continue;
      append_wrapped_admin($out, $noteLine, '  * ', $COLS);
    }
  }

  // Quantidade, valor unitário e total ficam juntos somente depois dos complementos.
  $left = '  QTD ' . $qtd . '  UNIT ' . money_br($unit);
  $out .= line(padR($left, max(10, $COLS - 12)) . padL($tot, 12));
  $lastHalfNote = ($isHalf && $io !== '') ? $io : ($isHalf ? $lastHalfNote : '');
  $lastWasHalf = $isHalf;
}

$out .= line(str_repeat('-', $COLS));
$wVal = ($COLS <= 48 ? 10 : 12);
$wLbl = max(10, $COLS - $wVal);
$out .= line(padR('SUBTOTAL:', $wLbl) . padL(money_br($totalProdutos), $wVal));
if ($taxaEntrega > 0) {
  $out .= line(padR('TAXA ENTREGA:', $wLbl) . padL(money_br($taxaEntrega), $wVal));
}
$out .= line(padR('TOTAL:', $wLbl) . padL(money_br($totalGeral), $wVal));
$out .= line(str_repeat('=', $COLS));

?><?php
$waShareLink = 'pedido_whatsapp.php?id=' . (int)$id . '&return_to=' . rawurlencode($returnTo);
$emailShareLink = 'email_documento.php?type=pedido&id=' . (int)$id . '&return_to=' . rawurlencode($returnTo);
if (!$orderPrintCross && function_exists('multi_company_context_url')) {
  $waShareLink = multi_company_context_url($waShareLink);
  $emailShareLink = multi_company_context_url($emailShareLink);
}
$pedidoCriadoPrint = '';
if (!empty($pedido['created_at'])) {
  $ts = strtotime((string)$pedido['created_at']);
  if ($ts) $pedidoCriadoPrint = date('d/m/Y H:i', $ts);
}
$tipoEntregaPrint = $tipoEntrega !== '' ? (($tipoEntrega === 'retirada') ? (string)($app['retirada_label'] ?? 'Retirada') : ucfirst(str_replace('_', ' ', $tipoEntrega))) : '';
$formaPgtoPrint = $formaPgto;
if ($formaPgtoPrint === 'pix') $formaPgtoPrint = 'Pix';
if ($formaPgtoPrint === 'dinheiro') $formaPgtoPrint = 'Dinheiro';
if ($formaPgtoPrint === 'debito') $formaPgtoPrint = 'Cartão Débito';
if ($formaPgtoPrint === 'credito') $formaPgtoPrint = 'Cartão Crédito';
if ($formaPgtoPrint === '') $formaPgtoPrint = 'Não informado';
$itemNotesPrintQueues = $fallbackItemObsQueues;
$itemNotesPrintQueue = $fallbackItemObsQueue;
$itemNotesPrintByRow = build_item_note_map_admin($itens, $itemNotesPrintQueues, $itemNotesPrintQueue);
?>
<!doctype html>
<html lang="pt-br">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Imprimir Pedido #<?= (int)$pedidoNumero ?></title>
  <link rel="stylesheet" href="../assets/nexa-print-actions.css?v=1145197">
  <style>
    :root{--paper:<?= $printPack58mm ? '58mm' : '72mm' ?>;--ink:#000;--muted:#333;--line:#000;--screen:#f5f7fb;}
    *{box-sizing:border-box}
    body{font-family:Arial,Helvetica,sans-serif;margin:0;background:var(--screen);color:var(--ink);font-weight:800;padding:16px;}
    .toolbar{width:min(860px,100%);margin:0 auto 12px;background:#fff;border:1px solid #dbe4f0;border-radius:16px;padding:12px;display:flex;justify-content:space-between;gap:10px;align-items:center;box-shadow:0 12px 28px rgba(15,23,42,.06);}
    .toolbar strong{color:#0B1F5B}.actions{display:flex;gap:8px;flex-wrap:wrap;justify-content:flex-end}.btn{display:inline-flex;align-items:center;justify-content:center;text-decoration:none;border:1px solid #0f172a;background:#fff;color:#0f172a;border-radius:999px;padding:9px 13px;font-weight:900;cursor:pointer}.btn.primary{background:#0B1F5B;color:#fff;border-color:#0B1F5B}.hint{font-size:12px;color:#64748b;margin-top:4px;font-weight:750}.msg{width:min(860px,100%);margin:0 auto 10px;padding:9px 11px;border:1px solid #ddd;border-radius:10px;font-size:13px;font-weight:750}.msg.ok{background:#ecfdf5;border-color:#a7f3d0;color:#047857}.msg.err{background:#fef2f2;border-color:#fecaca;color:#b91c1c}
    .print-wrap{width:min(860px,100%);margin:0 auto;padding:0 4px}.ticket{background:#fff;border:2px solid var(--line);border-radius:14px;padding:16px;box-shadow:0 10px 24px rgba(15,23,42,.08)}
    .head{position:relative;text-align:center;border-bottom:3px double var(--line);padding-bottom:10px;margin-bottom:11px}.head.has-logo .brand{padding-left:18mm;padding-right:18mm}.print-logo{position:absolute;left:0;top:0;width:16mm;height:11mm;object-fit:contain}.brand{font-size:18px;font-weight:950;text-transform:uppercase;letter-spacing:.5px}.doc-title{font-size:24px;font-weight:950;margin-top:4px}.doc-date{font-size:13px;font-weight:900;margin-top:4px;color:#111}.section{border-top:1px dashed var(--line);padding-top:8px;margin-top:8px}.section:first-of-type{border-top:0;margin-top:0;padding-top:0}.line{display:flex;justify-content:space-between;gap:8px;align-items:flex-start;font-size:13px;line-height:1.25;margin:3px 0}.line span{color:#475569;font-size:11px;text-transform:uppercase;font-weight:900;white-space:nowrap}.line strong{text-align:right;font-weight:950}.line.block{display:block}.line.block strong{text-align:left;display:block;margin-top:2px;white-space:pre-wrap}.items{display:grid;gap:0;margin-top:6px}.item{border-bottom:1px dashed var(--line);padding:6px 0}.item:last-child{border-bottom:0}.name{font-size:12.5px;font-weight:950;line-height:1.16;text-transform:uppercase;overflow-wrap:anywhere}.obs-item{margin-top:3px;font-size:10.5px;line-height:1.22;font-weight:850;border-left:3px solid #000;padding-left:5px;white-space:pre-wrap;overflow-wrap:anywhere}.item-values{display:grid;grid-template-columns:auto minmax(0,1fr) auto;gap:7px;align-items:baseline;margin-top:4px;font-size:10.5px;line-height:1.15;font-weight:850}.item-values span{white-space:nowrap}.item-values span:nth-child(2){text-align:center}.item-values strong{font-size:11px;font-weight:950;white-space:nowrap}.totals{border-top:2px solid var(--line);margin-top:10px;padding-top:7px}.total-line{display:flex;justify-content:space-between;font-size:14px;margin:3px 0}.total-line.final{font-size:20px;font-weight:950;margin-top:6px}.footer{text-align:center;border-top:1px dashed var(--line);margin-top:10px;padding-top:8px;font-size:11px;font-weight:850;color:#111}
    @media print{
      @page{size:<?= $printPack58mm ? '58mm' : '72mm' ?> auto;margin:<?= $printPack58mm ? '1mm' : '2mm' ?>;}
      *{-webkit-print-color-adjust:exact;print-color-adjust:exact}.toolbar,.msg{display:none!important}body{background:#fff;width:var(--paper);padding:0;margin:0 auto;color:#000}.print-wrap{width:var(--paper);margin:0;padding:0}.ticket{box-shadow:none;border:0;border-radius:0;padding:0 <?= $printPack58mm ? '1.5mm' : '2mm' ?>}.head{padding-bottom:4px;margin-bottom:5px;border-bottom:2px double #000}.print-logo{width:<?= $printPack58mm ? '13mm' : '16mm' ?>;height:<?= $printPack58mm ? '9mm' : '11mm' ?>}.head.has-logo .brand{padding-left:<?= $printPack58mm ? '14mm' : '18mm' ?>;padding-right:<?= $printPack58mm ? '14mm' : '18mm' ?>}.brand{font-size:<?= $printPack58mm ? '11pt' : '13pt' ?>;line-height:1.05}.doc-title{font-size:<?= $printPack58mm ? '14pt' : '17pt' ?>;line-height:1.08;margin-top:2px}.doc-date{font-size:<?= $printPack58mm ? '8.5pt' : '10pt' ?>;margin-top:2px}.section{padding-top:4px;margin-top:4px}.line{font-size:<?= $printPack58mm ? '8.4pt' : '10pt' ?>;margin:1.5px 0}.line span{font-size:<?= $printPack58mm ? '6.8pt' : '8pt' ?>}.item{padding:<?= $printPack58mm ? '1.7mm' : '2mm' ?> 0}.name{font-size:<?= $printPack58mm ? '8.2pt' : '9.2pt' ?>;line-height:1.12}.obs-item{font-size:<?= $printPack58mm ? '6.8pt' : '7.8pt' ?>;line-height:1.18;border-left:2pt solid #000;padding-left:1.2mm;margin-top:1mm}.item-values{font-size:<?= $printPack58mm ? '6.8pt' : '8pt' ?>;gap:1mm;margin-top:1mm}.item-values strong{font-size:<?= $printPack58mm ? '7.2pt' : '8.4pt' ?>}.total-line{font-size:<?= $printPack58mm ? '9pt' : '11pt' ?>}.total-line.final{font-size:<?= $printPack58mm ? '13pt' : '16pt' ?>}.footer{font-size:<?= $printPack58mm ? '7.2pt' : '8.5pt' ?>;margin-top:5px;padding-top:4px}
    }
  </style>
</head>
<body>
  <?php if (!empty($_GET['wa_sent'])): ?><div class="msg ok">WhatsApp enviado com sucesso.</div><?php endif; ?>
  <?php if (!empty($_GET['wa_error'])): ?><div class="msg err">Não foi possível enviar o WhatsApp. Confira a configuração e o número do cliente.</div><?php endif; ?>
  <?php if (!empty($_GET['err'])): ?><div class="msg err">Pedido gravado, mas a emissão fiscal retornou: <?= admin_print_h($_GET['err']) ?></div><?php endif; ?>
  <div class="nexa-print-toolbar <?= $printPack58mm?'nexa-print-toolbar--compact':'' ?>">
    <div class="nexa-print-toolbar-copy">
      <strong class="nexa-print-toolbar-title">Pedido #<?= admin_print_h($pedidoNumero) ?></strong>
      <div class="nexa-print-toolbar-hint"><?= $orderPrintCross ? ('V11.24 · ' . admin_print_h($orderPrintCompanyName !== '' ? $orderPrintCompanyName : ('Empresa ' . $orderPrintCompanyId)) . ' · somente consulta') : ('Modelo anterior · ' . ($printPack58mm ? 'pack 58 mm' : 'padrão')) ?></div>
    </div>
    <div class="nexa-print-actions"><?php if (!$orderPrintCross): ?><a class="nexa-print-action is-whatsapp" title="Enviar pedido por WhatsApp" aria-label="Enviar pedido por WhatsApp" href="<?= admin_print_h($waShareLink) ?>"><span class="nexa-print-action-icon" aria-hidden="true">💬</span><span class="nexa-print-action-label">WhatsApp</span></a><a class="nexa-print-action" title="Enviar pedido por e-mail" aria-label="Enviar pedido por e-mail" href="<?= admin_print_h($emailShareLink) ?>"><span class="nexa-print-action-icon" aria-hidden="true">✉</span><span class="nexa-print-action-label">E-mail</span></a><?php endif; ?><button class="nexa-print-action is-primary" type="button" title="Imprimir pedido" aria-label="Imprimir pedido" onclick="window.print()"><span class="nexa-print-action-icon" aria-hidden="true">🖨</span><span class="nexa-print-action-label">Imprimir</span></button><a class="nexa-print-action" title="Sair da impressão" aria-label="Sair da impressão" href="<?= admin_print_h($returnTo) ?>"><span class="nexa-print-action-icon" aria-hidden="true">↩</span><span class="nexa-print-action-label">Sair</span></a></div>
  </div>
  <div class="print-wrap">
    <section class="ticket">
      <header class="head<?= $printLogoUrl !== '' ? ' has-logo' : '' ?>">
        <?php if ($printLogoUrl !== ''): ?><img class="print-logo" src="<?= admin_print_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
        <div class="brand"><?= admin_print_h($site) ?></div>
        <div class="doc-title">PEDIDO #<?= admin_print_h($pedidoNumero) ?></div>
        <?php if ($pedidoCriadoPrint !== ''): ?><div class="doc-date">Data do pedido: <?= admin_print_h($pedidoCriadoPrint) ?></div><?php endif; ?>
      </header>

      <div class="section">
        <div class="line"><span>Cliente</span><strong><?= admin_print_h(trim((string)($pedido['nome_cliente'] ?? '')) ?: 'Consumidor final') ?></strong></div>
        <?php if ($orderSellerName !== ''): ?><div class="line"><span>Vendedor</span><strong><?= admin_print_h($orderSellerName) ?></strong></div><?php endif; ?>
        <?php if (trim((string)($pedido['whatsapp'] ?? '')) !== ''): ?><div class="line"><span>WhatsApp</span><strong><?= admin_print_h($pedido['whatsapp']) ?></strong></div><?php endif; ?>
        <?php if (trim((string)($pedido['customer_document'] ?? '')) !== ''): ?><div class="line"><span>CPF/CNPJ</span><strong><?= admin_print_h($pedido['customer_document']) ?></strong></div><?php endif; ?>
        <?php if ($tipoEntregaPrint !== ''): ?><div class="line"><span>Tipo</span><strong><?= admin_print_h($tipoEntregaPrint) ?></strong></div><?php endif; ?>
        <?php if ($internalDeliveryLabel !== ''): ?><div class="line"><span>Entrega Interno</span><strong><?= admin_print_h($internalDeliveryLabel) ?></strong></div><?php endif; ?>
        <div class="line"><span>Pagamento</span><strong><?= admin_print_h($formaPgtoPrint) ?></strong></div>
        <?php if ($scheduledLabel !== ''): ?><div class="line"><span>Previsão</span><strong><?= admin_print_h($scheduledLabel) ?></strong></div><?php endif; ?>
      </div>

      <?php if ($printEndereco !== '' || $printBairro !== '' || $printReferencia !== '' || $printCep !== ''): ?>
      <div class="section">
        <?php if ($printEndereco !== ''): ?><div class="line block"><span>Endereço</span><strong><?= admin_print_h($printEndereco) ?></strong></div><?php endif; ?>
        <?php if ($printBairro !== ''): ?><div class="line"><span>Bairro</span><strong><?= admin_print_h($printBairro) ?></strong></div><?php endif; ?>
        <?php if ($printCep !== ''): ?><div class="line"><span>CEP</span><strong><?= admin_print_h($printCep) ?></strong></div><?php endif; ?>
        <?php if ($printReferencia !== ''): ?><div class="line block"><span>Referência</span><strong><?= admin_print_h($printReferencia) ?></strong></div><?php endif; ?>
      </div>
      <?php endif; ?>

      <?php if ($obs !== ''): ?><div class="section"><div class="line block"><span>Observação do pedido</span><strong><?= nl2br(admin_print_h($obs)) ?></strong></div></div><?php endif; ?>

      <div class="section"><strong style="display:block;font-size:13px;text-transform:uppercase">Itens do pedido</strong>
        <div class="items">
          <?php foreach ($itens as $__itemIdx => $i): ?>
            <?php
              $qtdFloat = (float)($i['qtd'] ?? 0);
              $nomeItem = (string)($i['nome'] ?? ('#' . (int)($i['produto_id'] ?? 0)));
              $unitItem = item_unit_price_admin($i);
              $totalItem = (float)($i['total'] ?? 0);
              $obsItemPrint = '';
              if ($itemObsCol) $obsItemPrint = trim((string)($i[$itemObsCol] ?? ''));
              if ($obsItemPrint === '') $obsItemPrint = (string)($itemNotesPrintByRow[$__itemIdx] ?? '');
              $obsItemPrint = normalize_item_complement_admin($obsItemPrint);
            ?>
            <div class="item">
              <div class="name"><?= admin_print_h($nomeItem) ?></div>
              <?php if ($obsItemPrint !== ''): ?><div class="obs-item"><?= nl2br(admin_print_h($obsItemPrint)) ?></div><?php endif; ?>
              <div class="item-values">
                <span>Qtd.: <?= admin_print_h(qty_br($qtdFloat)) ?></span>
                <span>Unit.: <?= admin_print_h(money_br($unitItem)) ?></span>
                <strong>Total: <?= admin_print_h(money_br($totalItem)) ?></strong>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="totals">
        <div class="total-line"><span>Subtotal</span><strong><?= admin_print_h(money_br($totalProdutos)) ?></strong></div>
        <?php if ($taxaEntrega > 0): ?><div class="total-line"><span>Taxa entrega</span><strong><?= admin_print_h(money_br($taxaEntrega)) ?></strong></div><?php endif; ?>
        <div class="total-line final"><span>Total</span><strong><?= admin_print_h(money_br($totalGeral)) ?></strong></div>
      </div>
      <div class="footer">Impresso pelo Nexa Próspero</div>
    </section>
  </div>
  <?php if ($autoPrint): ?><script>window.addEventListener('load',function(){setTimeout(function(){try{window.print();}catch(e){}},250);});</script><?php endif; ?>
  <script>
    document.addEventListener('keydown', function(e){
      if(e.key === 'Escape'){
        e.preventDefault();
        try{if(window.opener||window.history.length<=1){window.close();}}catch(err){}
        setTimeout(function(){ window.location.href = <?= json_encode($returnTo) ?>; }, 250);
      }
    });
  </script>
</body>
</html>
