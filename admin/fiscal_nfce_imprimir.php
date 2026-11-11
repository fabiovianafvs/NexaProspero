<?php
declare(strict_types=1);
// V11.46.1: a impressão retornada diretamente do PDV pode reutilizar a permissão
// operacional do próprio PDV. A permissão é revalidada no contexto F11; o parâmetro
// apenas identifica qual fluxo originou a impressão.
$nfcePrintSource = strtolower(trim((string)($_GET['source'] ?? '')));
if ($nfcePrintSource === 'pdv_order') $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_SALES_PERMISSION'] = 'gestao_pdv';
elseif ($nfcePrintSource === 'pdv_checkout') $GLOBALS['NEXA_MULTI_COMPANY_FISCAL_SALES_PERMISSION'] = 'gestao_pdv_checkout';
require_once __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_fiscal.php';
require_once __DIR__ . '/_print_brand.php';

$cid = function_exists('multi_company_operational_company_id')
  ? (int)multi_company_operational_company_id($pdo, 'fiscal_nfce_homologation')
  : (int)COMPANY_ID;
$nfcePrintCrossContext = function_exists('multi_company_scope_is_cross_company')
  && multi_company_scope_is_cross_company($pdo, 'fiscal_nfce_homologation');
$docId = (int)($_GET['doc_id'] ?? $_GET['id'] ?? 0);
if ($docId <= 0) { http_response_code(400); echo 'Documento inválido.'; exit; }

try { fiscal_nfce_ensure_schema($pdo); } catch (Throwable $e) {}
$st = $pdo->prepare("SELECT * FROM fiscal_documents WHERE company_id=? AND id=? AND model IN ('55','65') LIMIT 1");
$st->execute([$cid, $docId]);
$doc = $st->fetch(PDO::FETCH_ASSOC);
if (!$doc) { http_response_code(404); echo 'Documento fiscal não encontrado.'; exit; }
if ($nfcePrintCrossContext && in_array($nfcePrintSource, ['pdv_order','pdv_checkout'], true)
    && strtoupper(trim((string)($doc['source_type'] ?? ''))) !== 'PEDIDO') {
  http_response_code(403);
  echo 'V11.46.1 Multiempresa: a permissão do PDV somente pode imprimir documento fiscal originado de Pedido.';
  exit;
}
if ($nfcePrintCrossContext) {
  $printModel = in_array((string)($doc['model'] ?? ''), ['55','65'], true) ? (string)$doc['model'] : '65';
  try {
    if (function_exists('fiscal_multi_company_document_homologation_assert')) {
      fiscal_multi_company_document_homologation_assert($pdo, $cid, $printModel, 'consultar');
    }
  } catch (Throwable $e) {
    http_response_code(409);
    echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
    exit;
  }
  if (!(function_exists('multi_company_fiscal_environment_allowed') && multi_company_fiscal_environment_allowed($pdo, $cid, (string)($doc['environment'] ?? '')))) {
    http_response_code(403);
    echo htmlspecialchars(function_exists('multi_company_fiscal_environment_message') ? multi_company_fiscal_environment_message('a impressão da nota') : 'V11.46 Multiempresa: impressão fiscal em PRODUÇÃO permanece bloqueada no F11.', ENT_QUOTES, 'UTF-8');
    exit;
  }
}
$items = fiscal_nfce_document_items($pdo, $cid, $docId);
$payments = fiscal_nfce_document_payments($pdo, $cid, $docId);
$emit = fiscal_nfce_document_participant($pdo, $cid, $docId, 'EMITENTE');
$dest = fiscal_nfce_document_participant($pdo, $cid, $docId, 'DESTINATARIO');
$printLogoUrl = nexa_print_logo_url($cid);

function nfce_print_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function nfce_print_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
function nfce_print_decimal($v, int $dec = 2): string { return number_format((float)$v, $dec, ',', '.'); }
function nfce_print_qty($v): string { return rtrim(rtrim(number_format((float)$v, 4, ',', '.'), '0'), ','); }
function nfce_print_date_br($v): string { $ts = strtotime((string)$v); return $ts ? date('d/m/Y H:i:s', $ts) : ''; }
function nfce_print_xml_tag(string $xml, string $tag): string {
  if (trim($xml) === '') return '';
  try {
    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml)) return '';
    $xp = new DOMXPath($dom);
    $node = $xp->query('//*[local-name()="' . $tag . '"]')->item(0);
    return $node ? trim((string)$node->textContent) : '';
  } catch (Throwable $e) { return ''; }
}
/**
 * Le valores fiscais diretamente do ICMSTot do XML armazenado. A base ST nao e
 * armazenada em fiscal_documents; nunca usar valor fixo em um DANFE autorizado.
 * Somente consulta o XML: nao o modifica e nao participa da emissao/tributacao.
 */
function nfce_print_xml_icms_totals(string $xml): array {
  $values = ['vBC', 'vICMS', 'vBCST', 'vST', 'vProd', 'vFrete', 'vSeg',
    'vDesc', 'vOutro', 'vIPI', 'vPIS', 'vCOFINS', 'vNF', 'vTotTrib'];
  $out = ['present' => false];
  foreach ($values as $field) $out[$field] = null;
  if (trim($xml) === '') return $out;
  if (!class_exists(DOMDocument::class)) {
    // Contingencia para PHP sem ext-dom: limitado ao grupo ICMSTot.
    // Nao consulta tags de item nem usa valores do pedido para bases fiscais.
    if (!preg_match('~<(?:[A-Za-z_][\w.-]*:)?ICMSTot\b[^>]*>(.*?)</(?:[A-Za-z_][\w.-]*:)?ICMSTot\s*>~si', $xml, $m)) return $out;
    $out['present'] = true;
    foreach ($values as $field) {
      if (preg_match('~<(?:[A-Za-z_][\w.-]*:)?' . $field . '\b[^>]*>\s*(-?\d+(?:\.\d+)?)\s*</(?:[A-Za-z_][\w.-]*:)?' . $field . '\s*>~si', $m[1], $v)) $out[$field] = (float)$v[1];
    }
    return $out;
  }
  try {
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $old = libxml_use_internal_errors(true);
    try { $loaded = $dom->loadXML($xml, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if (!$loaded) return $out;
    $xp = new DOMXPath($dom);
    $group = $xp->query('//*[local-name()="infNFe"]/*[local-name()="total"]/*[local-name()="ICMSTot"]')->item(0);
    if (!$group instanceof DOMElement) return $out;
    $out['present'] = true;
    foreach ($values as $field) {
      $node = $xp->query('./*[local-name()="' . $field . '"]', $group)->item(0);
      if (!$node) continue;
      $raw = trim((string)$node->textContent);
      if (preg_match('/^-?\d+(?:\.\d+)?$/D', $raw)) $out[$field] = (float)$raw;
    }
  } catch (Throwable $e) { return $out; }
  return $out;
}

/** Retorna o valor da primeira tag no grupo total/IBSCBSTot (sem alterar XML). */
function nfce_print_xml_ibs_tax_total(string $xml, string $group, string $field): ?float {
  if (!in_array($group, ['gIBSUF', 'gIBSMun', 'gCBS'], true)) return null;
  if (!in_array($field, ['vIBSUF', 'vIBSMun', 'vCBS'], true)) return null;
  if (trim($xml) === '') return null;
  if (!class_exists(DOMDocument::class)) {
    if (!preg_match('~<(?:[A-Za-z_][\w.-]*:)?IBSCBSTot\b[^>]*>(.*?)</(?:[A-Za-z_][\w.-]*:)?IBSCBSTot\s*>~si', $xml, $tot)) return null;
    if (!preg_match('~<(?:[A-Za-z_][\w.-]*:)?' . $group . '\b[^>]*>(.*?)</(?:[A-Za-z_][\w.-]*:)?' . $group . '\s*>~si', $tot[1], $g)) return null;
    if (!preg_match('~<(?:[A-Za-z_][\w.-]*:)?' . $field . '\b[^>]*>\s*(-?\d+(?:\.\d+)?)\s*</(?:[A-Za-z_][\w.-]*:)?' . $field . '\s*>~si', $g[1], $v)) return null;
    return (float)$v[1];
  }
  try {
    $dom = new DOMDocument();
    $old = libxml_use_internal_errors(true);
    try { $loaded = $dom->loadXML($xml, LIBXML_NONET); }
    finally { libxml_clear_errors(); libxml_use_internal_errors($old); }
    if (!$loaded) return null;
    $xp = new DOMXPath($dom);
    $node = $xp->query('//*[local-name()="infNFe"]/*[local-name()="total"]/*[local-name()="IBSCBSTot"]//*[local-name()="' . $group . '"]/*[local-name()="' . $field . '"]')->item(0);
    if (!$node) return null;
    $raw = trim((string)$node->textContent);
    return preg_match('/^-?\d+(?:\.\d+)?$/D', $raw) ? (float)$raw : null;
  } catch (Throwable $e) { return null; }
}

function nfce_print_xml_ibs_cbs_totals(string $xml): array {
  $totals = ['present' => false, 'ibs' => 0.0, 'cbs' => 0.0];
  if (trim($xml) === '') return $totals;
  if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
    try {
      $dom = new DOMDocument();
      libxml_use_internal_errors(true);
      if ($dom->loadXML($xml)) {
        $xp = new DOMXPath($dom);
        $group = $xp->query('//*[local-name()="total"]/*[local-name()="IBSCBSTot"]')->item(0);
        if ($group) {
          $totals['present'] = true;
          $ibs = $xp->query('./*[local-name()="gIBS"]/*[local-name()="vIBS"]', $group)->item(0);
          $cbs = $xp->query('./*[local-name()="gCBS"]/*[local-name()="vCBS"]', $group)->item(0);
          if ($ibs) $totals['ibs'] = (float)str_replace(',', '.', trim((string)$ibs->textContent));
          if ($cbs) $totals['cbs'] = (float)str_replace(',', '.', trim((string)$cbs->textContent));
          return $totals;
        }
      }
    } catch (Throwable $e) {}
  }
  $prefix = '(?:[A-Za-z_][A-Za-z0-9_.-]*:)?';
  if (!preg_match('~<' . $prefix . 'IBSCBSTot\b[^>]*>(.*?)</' . $prefix . 'IBSCBSTot\s*>~si', $xml, $totalMatch)) return $totals;
  $totals['present'] = true;
  $body = (string)($totalMatch[1] ?? '');
  if (preg_match('~<' . $prefix . 'gIBS\b[^>]*>(.*?)</' . $prefix . 'gIBS\s*>~si', $body, $ibsGroup)
      && preg_match('~<' . $prefix . 'vIBS\b[^>]*>\s*([-+]?\d+(?:[.,]\d+)?)\s*</' . $prefix . 'vIBS\s*>~si', (string)($ibsGroup[1] ?? ''), $ibsValue)) {
    $totals['ibs'] = (float)str_replace(',', '.', (string)$ibsValue[1]);
  }
  if (preg_match('~<' . $prefix . 'gCBS\b[^>]*>(.*?)</' . $prefix . 'gCBS\s*>~si', $body, $cbsGroup)
      && preg_match('~<' . $prefix . 'vCBS\b[^>]*>\s*([-+]?\d+(?:[.,]\d+)?)\s*</' . $prefix . 'vCBS\s*>~si', (string)($cbsGroup[1] ?? ''), $cbsValue)) {
    $totals['cbs'] = (float)str_replace(',', '.', (string)$cbsValue[1]);
  }
  return $totals;
}

function nfce_print_br_money_float(string $value): float {
  $value = trim($value);
  if ($value === '') return 0.0;
  $value = str_replace(['R$', ' '], '', $value);
  if (str_contains($value, ',')) $value = str_replace(['.', ','], ['', '.'], $value);
  return (float)$value;
}
function nfce_print_xml_ibpt_totals(string $xml): array {
  $out = ['present'=>false, 'federal'=>0.0, 'state'=>0.0, 'municipal'=>0.0, 'total'=>0.0, 'source'=>''];
  if (trim($xml) === '') return $out;
  $infCpl = '';
  if (class_exists('DOMDocument') && class_exists('DOMXPath')) {
    try {
      $dom = new DOMDocument();
      libxml_use_internal_errors(true);
      if ($dom->loadXML($xml)) {
        $xp = new DOMXPath($dom);
        $totalNode = $xp->query('//*[local-name()="total"]/*[local-name()="ICMSTot"]/*[local-name()="vTotTrib"]')->item(0);
        if ($totalNode) {
          $out['present'] = true;
          $out['total'] = (float)str_replace(',', '.', trim((string)$totalNode->textContent));
        }
        $infNode = $xp->query('//*[local-name()="infAdic"]/*[local-name()="infCpl"]')->item(0);
        if ($infNode) $infCpl = trim((string)$infNode->textContent);
      }
    } catch (Throwable $e) {}
  }
  if (!$out['present']) {
    $prefix = '(?:[A-Za-z_][A-Za-z0-9_.-]*:)?';
    if (preg_match('~<' . $prefix . 'ICMSTot\b[^>]*>(.*?)</' . $prefix . 'ICMSTot\s*>~si', $xml, $totalGroup)
        && preg_match('~<' . $prefix . 'vTotTrib\b[^>]*>\s*([-+]?\d+(?:[.,]\d+)?)\s*</' . $prefix . 'vTotTrib\s*>~si', (string)($totalGroup[1] ?? ''), $totalValue)) {
      $out['present'] = true;
      $out['total'] = (float)str_replace(',', '.', (string)$totalValue[1]);
    }
  }
  if ($infCpl === '' && preg_match('~<(?:[A-Za-z_][A-Za-z0-9_.-]*:)?infCpl\b[^>]*>(.*?)</(?:[A-Za-z_][A-Za-z0-9_.-]*:)?infCpl\s*>~si', $xml, $infMatch)) {
    $infCpl = html_entity_decode(strip_tags((string)($infMatch[1] ?? '')), ENT_QUOTES | ENT_XML1, 'UTF-8');
  }
  if ($infCpl === '') $infCpl = nfce_print_xml_tag($xml, 'infCpl');
  if ($infCpl !== '' && preg_match(
      '~Tributos aproximados Lei 12\\.741/2012:\\s*Federal R\\$\\s*([0-9.,]+);\\s*Estadual R\\$\\s*([0-9.,]+);\\s*Municipal R\\$\\s*([0-9.,]+);\\s*Total R\\$\\s*([0-9.,]+)\\.\\s*Fonte:\\s*(.+)$~iu',
      $infCpl,
      $m
  )) {
    $out['present'] = true;
    $out['federal'] = nfce_print_br_money_float((string)$m[1]);
    $out['state'] = nfce_print_br_money_float((string)$m[2]);
    $out['municipal'] = nfce_print_br_money_float((string)$m[3]);
    if ($out['total'] <= 0) $out['total'] = nfce_print_br_money_float((string)$m[4]);
    $out['source'] = rtrim(trim((string)$m[5]), '. ');
  }
  return $out;
}

function nfce_print_load_barcode_vendor(): bool {
  $autoload = dirname(__DIR__) . '/vendor/autoload.php';
  if (is_file($autoload)) require_once $autoload;
  return class_exists('Com\\Tecnick\\Barcode\\Barcode');
}
function nfce_print_qrcode_render(string $content): array {
  $content = trim($content);
  $empty = ['uri' => '', 'size_mm' => 30.0, 'pixels' => 0, 'modules' => 0];
  if ($content === '' || !nfce_print_load_barcode_vendor()) return $empty;
  try {
    $barcode = new \Com\Tecnick\Barcode\Barcode();
    // Primeiro identifica a quantidade de módulos. Depois escolhe uma quantidade inteira
    // de pontos por módulo compatível com 203 dpi e com largura física entre 25 e 34 mm.
    $probe = $barcode->getBarcodeObj('QRCODE,M', $content, -1, -1, 'black', [-4, -4, -4, -4])->setBackgroundColor('white');
    $probeMeta = method_exists($probe, 'getArray') ? (array)$probe->getArray() : [];
    $modules = (int)($probeMeta['ncols'] ?? 0) + 8;
    if ($modules <= 8) return $empty;

    $minDots = (int)ceil(25.0 * 203.0 / 25.4);
    $maxDots = (int)floor(34.0 * 203.0 / 25.4);
    $dotsPerModule = max(3, (int)ceil($minDots / $modules));
    $maxDotsPerModule = max(1, (int)floor($maxDots / $modules));
    $dotsPerModule = min($dotsPerModule, $maxDotsPerModule);

    $obj = $barcode->getBarcodeObj(
      'QRCODE,M',
      $content,
      -$dotsPerModule,
      -$dotsPerModule,
      'black',
      [-4, -4, -4, -4]
    )->setBackgroundColor('white');
    $meta = method_exists($obj, 'getArray') ? (array)$obj->getArray() : [];
    $pixels = (int)ceil((float)($meta['full_width'] ?? 0));
    // 203 dpi é a resolução predominante nas impressoras térmicas de 58/80 mm.
    // A medida física abaixo preserva um número inteiro de pontos para cada módulo.
    $sizeMm = $pixels > 0 ? ($pixels * 25.4 / 203.0) : 30.0;

    // SVG com coordenadas inteiras e crispEdges: não depende de GD/Imagick e evita
    // a última linha deslocada pela interpolação do driver térmico.
    $svg = method_exists($obj, 'getSvgCode') ? (string)$obj->getSvgCode() : '';
    if ($svg !== '') {
      $svg = preg_replace('/<svg\b/', '<svg shape-rendering="crispEdges"', $svg, 1) ?: $svg;
      $svg = str_replace('<g id="bars"', '<g id="bars" shape-rendering="crispEdges"', $svg);
      return [
        'uri' => 'data:image/svg+xml;base64,' . base64_encode($svg),
        'size_mm' => round($sizeMm, 3),
        'pixels' => $pixels,
        'modules' => $modules,
      ];
    }

    if (function_exists('imagecreate')) {
      $png = $obj->getPngData(false);
      if (is_string($png) && $png !== '') {
        return [
          'uri' => 'data:image/png;base64,' . base64_encode($png),
          'size_mm' => round($sizeMm, 3),
          'pixels' => $pixels,
          'modules' => $modules,
        ];
      }
    }
    return $empty;
  } catch (Throwable $e) { return $empty; }
}
function nfce_print_qrcode_data_uri(string $content): string {
  $render = nfce_print_qrcode_render($content);
  return (string)($render['uri'] ?? '');
}
function nfce_print_barcode_data_uri(string $content): string {
  $content = preg_replace('/\D+/', '', $content);
  if ($content === '' || !nfce_print_load_barcode_vendor()) return '';
  try {
    $barcode = new \Com\Tecnick\Barcode\Barcode();
    $type = (strlen($content) % 2 === 0) ? 'C128C' : 'C128B';
    $obj = $barcode->getBarcodeObj($type, $content, 330, 46, 'black', [0, 0, 0, 0])->setBackgroundColor('white');
    $png = $obj->getPngData();
    return is_string($png) && $png !== '' ? 'data:image/png;base64,' . base64_encode($png) : '';
  } catch (Throwable $e) { return ''; }
}
function nfce_print_access_key_format($value): string {
  $digits = preg_replace('/\D+/', '', (string)$value);
  if (strlen($digits) !== 44) return (string)$value;
  return trim(chunk_split($digits, 4, ' '));
}
function nfce_print_doc_format($value): string {
  $d = preg_replace('/\D+/', '', (string)$value);
  if (strlen($d) === 14) return substr($d,0,2).'.'.substr($d,2,3).'.'.substr($d,5,3).'/'.substr($d,8,4).'-'.substr($d,12,2);
  if (strlen($d) === 11) return substr($d,0,3).'.'.substr($d,3,3).'.'.substr($d,6,3).'-'.substr($d,9,2);
  return (string)$value;
}
function nfce_print_address(array $p): string {
  $parts = [];
  $addr = trim((string)($p['address'] ?? ''));
  $num = trim((string)($p['number'] ?? ''));
  if ($addr !== '') $parts[] = $addr . ($num !== '' ? ', ' . $num : '');
  foreach (['complement','district','city','state','zip_code'] as $k) {
    $v = trim((string)($p[$k] ?? ''));
    if ($v !== '') $parts[] = $v;
  }
  return $parts ? implode(' - ', $parts) : 'Não informado';
}
function nfce_print_payment_label(string $type): string {
  $map = ['01'=>'Dinheiro','02'=>'Cheque','03'=>'Cartão de crédito','04'=>'Cartão de débito','05'=>'Crédito loja','10'=>'Vale alimentação','11'=>'Vale refeição','15'=>'Boleto','16'=>'Depósito bancário','17'=>'PIX','18'=>'Transferência bancária','19'=>'Programa fidelidade','90'=>'Sem pagamento','99'=>'Outros'];
  $t = str_pad(substr(preg_replace('/\D+/', '', $type) ?: '99', 0, 2), 2, '0', STR_PAD_LEFT);
  return $map[$t] ?? ('Pagamento ' . $t);
}
function nfce_print_safe_return(string $value, string $fallback = 'gestao_pdv.php'): string {
  $value = trim($value);
  if ($value === '') return $fallback;
  if (preg_match('/^https?:\/\//i', $value) || str_contains($value, '\n') || str_contains($value, '\r')) return $fallback;
  return ltrim($value, '/');
}

require_once __DIR__ . '/../config/navigation_return.php';
$returnFallback = in_array($nfcePrintSource, ['pdv_checkout','pdv_summary'], true)
  ? ($nfcePrintSource === 'pdv_checkout' ? 'gestao_pdv_checkout.php' : 'gestao_pdv_resumido.php')
  : 'fiscal_documentos.php';
$returnTo = nexa_navigation_safe_return((string)($_GET['return_to'] ?? ''), $returnFallback);
$waShareLink = 'fiscal_documento_whatsapp.php?doc_id=' . $docId . '&return_to=' . rawurlencode($returnTo);
$emailShareLink = 'email_documento.php?type=fiscal&doc_id=' . $docId . '&return_to=' . rawurlencode($returnTo);
$model = preg_replace('/\D+/', '', (string)($doc['model'] ?? '65')) ?: '65';
$xml = (string)($doc['xml_raw'] ?? '');
$rtcTaxTotals = nfce_print_xml_ibs_cbs_totals($xml);
$ibptPrintTotals = nfce_print_xml_ibpt_totals($xml);
$ibptPrintTotals['calculated_fallback'] = false;
// Documentos autorizados antes do vTotTrib, ou emissões em que o catálogo não foi
// encontrado durante a montagem do XML, podem receber a informação ao consumidor
// depois do final do DANFE. O XML autorizado não é alterado.
if (empty($ibptPrintTotals['present']) && function_exists('fiscal_nfce_ibpt_document_totals')) {
  $issueForIbpt = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? date('Y-m-d'));
  $fallbackUf = '';
  try {
    $fallbackCfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $cid) : [];
    $fallbackUf = (string)($fallbackCfg['uf'] ?? '');
  } catch (Throwable $e) {}
  $calculatedIbpt = fiscal_nfce_ibpt_document_totals($pdo, $cid, $docId, $issueForIbpt, $fallbackUf);
  if (!empty($calculatedIbpt['present'])) {
    $ibptPrintTotals = array_merge($ibptPrintTotals, [
      'present'=>true,
      'federal'=>(float)($calculatedIbpt['federal'] ?? 0),
      'state'=>(float)($calculatedIbpt['state'] ?? 0),
      'municipal'=>(float)($calculatedIbpt['municipal'] ?? 0),
      'total'=>(float)($calculatedIbpt['total'] ?? 0),
      'source'=>(string)($calculatedIbpt['source'] ?? 'IBPT'),
      'calculated_fallback'=>true,
    ]);
  } elseif ((float)($doc['total_tax_approx'] ?? 0) > 0) {
    $ibptPrintTotals = array_merge($ibptPrintTotals, [
      'present'=>true,
      'total'=>(float)$doc['total_tax_approx'],
      'source'=>'IBPT - valor armazenado no documento',
      'calculated_fallback'=>true,
    ]);
  }
}
$status = strtoupper((string)($doc['status'] ?? ''));
$amb = strtoupper((string)($doc['environment'] ?? 'HOMOLOGACAO'));
$accessKey = preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''));
$fiscalPrintCfg = function_exists('fiscal_company_config') ? fiscal_company_config($pdo, $cid) : [];
$printPack58mm = !empty($fiscalPrintCfg['print_pack_58mm_enabled']);
$emissionType = strtoupper(trim((string)($doc['emission_type'] ?? 'NORMAL')));
$isContingencyPrint = $emissionType !== '' && $emissionType !== 'NORMAL';
$contingencyReasonPrint = trim((string)($doc['contingency_reason'] ?? ''));
$contingencyStartedPrint = nfce_print_date_br($doc['contingency_started_at'] ?? '');

if ($model === '55'):
  $barcodeImage = nfce_print_barcode_data_uri($accessKey);
  $docNumber = str_pad((string)(int)($doc['number'] ?? 0), 9, '0', STR_PAD_LEFT);
  $protocol = (string)($doc['protocol_number'] ?? '');
  if ($protocol === '') $protocol = nfce_print_xml_tag($xml, 'nProt');
  $sefazUrl = 'www.nfe.fazenda.gov.br/portal ou no site da SEFAZ autorizadora';
  // O documento fiscal pode ter totais diferentes do pedido original: a fonte
  // juridicamente relevante para o DANFE e o XML autorizado armazenado.
  $icmsPrintTotals = nfce_print_xml_icms_totals($xml);
  $nfePrintTotal = static function (string $tag, $fallback = 0) use ($icmsPrintTotals) {
    return $icmsPrintTotals[$tag] ?? $fallback;
  };
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>DANFE NF-e <?= nfce_print_h($docNumber) ?></title>
<style>
  *{box-sizing:border-box}
  body{font-family:Arial,Helvetica,sans-serif;background:#e5e7eb;margin:0;padding:16px;color:#111827}
  .actions{width:min(196mm,100%);margin:0 auto 12px;display:flex;gap:8px;justify-content:flex-end;flex-wrap:wrap}.btn{border:1px solid #cbd5e1;border-radius:8px;background:#fff;padding:8px 12px;font-weight:700;cursor:pointer;text-decoration:none;color:#111827}.primary{background:#1e3a8a;color:#fff;border-color:#1e3a8a}.whatsapp{background:#25D366;color:#fff;border-color:#1ebe57;box-shadow:0 7px 16px rgba(37,211,102,.22)}.whatsapp:hover{background:#1fbd5a;border-color:#18a94c;color:#fff}
  .danfe{width:196mm;max-width:100%;margin:0 auto;background:#fff;border:1px solid #111;padding:2.2mm}.grid{display:grid;gap:0}.box{border:1px solid #111;padding:.85mm 1mm;min-height:6mm;overflow:hidden}.lbl{font-size:6.1pt;text-transform:uppercase;color:#374151;font-weight:700;line-height:1.05}.val{font-size:7.8pt;font-weight:700;line-height:1.12;overflow-wrap:anywhere}.small{font-size:7.4pt;line-height:1.2}.tiny{font-size:6.1pt;line-height:1.15}.center{text-align:center}.right{text-align:right}.bold{font-weight:800}.title{font-size:12pt;font-weight:900;line-height:1.03}.subtitle{font-size:6.5pt;font-weight:700;line-height:1.08}.section-title{font-size:7pt;font-weight:900;text-transform:uppercase;background:#f3f4f6;border:1px solid #111;border-bottom:0;padding:.65mm 1mm;margin-top:1.1mm}.header{display:grid;grid-template-columns:72mm 40mm minmax(0,1fr);align-items:stretch}.header>.box{min-width:0;min-height:22mm;padding:.65mm .85mm}.header .emitente{height:auto;min-height:22mm}.header>.box:nth-child(2){display:flex;flex-direction:column;justify-content:center;align-items:center}.header>.box:nth-child(3){display:flex;flex-direction:column;justify-content:center}.emit-wrap{display:flex;align-items:flex-start;gap:2mm;height:100%}.emit-logo{width:18mm;height:15.5mm;object-fit:contain;flex:0 0 18mm}.emit-data{min-width:0;flex:1}.logo-name{font-size:9.6pt;font-weight:900;margin-bottom:.6mm;line-height:1.08}.nfe-num{font-size:8.5pt;font-weight:900;line-height:1.1}.barcode{display:block;width:100%;max-width:100%;height:7.5mm;object-fit:fill;margin:.3mm auto .2mm}.access{font-size:6.5pt;letter-spacing:0;word-spacing:0;font-weight:800;line-height:1.08;white-space:nowrap;overflow-wrap:normal;word-break:normal}.auth-info{font-size:5.5pt;line-height:1.05;overflow-wrap:anywhere;margin-top:.3mm}.two{display:grid;grid-template-columns:1fr 1fr}.three{display:grid;grid-template-columns:1fr 1fr 1fr}.four{display:grid;grid-template-columns:1fr 1fr 1fr 1fr}.items{width:100%;border-collapse:collapse;table-layout:fixed}.items th,.items td{border:1px solid #111;padding:.7mm .8mm;font-size:6.4pt;line-height:1.12;vertical-align:top;overflow-wrap:anywhere}.items th{background:#f3f4f6;text-transform:uppercase;font-size:5.8pt}.items th:nth-child(1){width:8%}.items th:nth-child(2){width:25%}.items th:nth-child(3){width:8%}.items th:nth-child(4){width:6%}.items th:nth-child(5){width:4%}.items th:nth-child(6){width:7%}.items th:nth-child(7){width:8%}.items th:nth-child(8){width:8%}.items th:nth-child(9){width:7%}.items th:nth-child(10){width:7%}.items th:nth-child(11){width:6%}.items th:nth-child(12){width:6%}.items thead{display:table-header-group}.items tr{break-inside:avoid;page-break-inside:avoid}.totals{display:block}.tax-row{display:grid}.tax-row.five{grid-template-columns:repeat(5,minmax(0,1fr))}.tax-row.six{grid-template-columns:repeat(6,minmax(0,1fr))}.totals .box{min-height:6.1mm;padding:.65mm .9mm}.totals .lbl{font-size:5.6pt;white-space:nowrap}.totals .val{font-size:7.6pt}.tax-row + .tax-row .box{border-top:0}.obs{min-height:12mm}.homolog{border:2px solid #111;padding:1.2mm;margin:1.2mm 0;font-size:8pt;font-weight:900;text-align:center;background:#fef3c7}.no-border-top{border-top:0}.page-foot{display:flex;justify-content:space-between;font-size:6.2pt;margin-top:1.2mm;color:#374151}
  @page{size:A4 portrait;margin:7mm}
  @media print{html,body{width:auto!important;max-width:none;min-width:0;background:#fff;padding:0!important;margin:0!important}.actions{display:none!important}.header{grid-template-columns:72mm 40mm minmax(0,1fr)!important}.tax-row.five{grid-template-columns:repeat(5,minmax(0,1fr))!important}.tax-row.six{grid-template-columns:repeat(6,minmax(0,1fr))!important}.danfe{width:100%;max-width:196mm;border:0;padding:0;margin:0 auto;overflow:visible}.box,.items th,.items td,.section-title{border-color:#000}.header,.tax-row,.grid,.section-title,.page-foot{break-inside:avoid;page-break-inside:avoid}.items{break-inside:auto}.items tr{break-inside:avoid;page-break-inside:avoid}.page-break{page-break-before:always}}
  @media screen and (max-width:760px){.danfe{width:100%;overflow:auto}.header{grid-template-columns:1fr}.header .emitente{height:auto;min-height:0}.two,.three,.four{grid-template-columns:1fr}.tax-row.five,.tax-row.six{grid-template-columns:repeat(2,minmax(0,1fr))}.items{min-width:850px}}
</style>
</head>
<body>
<div class="actions"><a class="btn whatsapp" title="Enviar PDF por WhatsApp" aria-label="Enviar PDF por WhatsApp" href="<?= nfce_print_h($waShareLink) ?>"><span class="btn-icon" aria-hidden="true">💬</span><span class="btn-label">WhatsApp</span></a><a class="btn" title="Enviar por e-mail" aria-label="Enviar por e-mail" href="<?= nfce_print_h($emailShareLink) ?>"><span class="btn-icon" aria-hidden="true">✉</span><span class="btn-label">E-mail</span></a><button class="btn primary" type="button" title="Imprimir NF-e" aria-label="Imprimir NF-e" onclick="window.print()"><span class="btn-icon" aria-hidden="true">🖨</span><span class="btn-label">Imprimir</span></button><a class="btn" title="Sair" aria-label="Sair" href="<?= nfce_print_h($returnTo) ?>"><span class="btn-icon" aria-hidden="true">↩</span><span class="btn-label">Sair</span></a></div>
<div class="danfe">
  <?php if ($amb !== 'PRODUCAO'): ?><div class="homolog">NF-e EMITIDA EM AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL</div><?php endif; ?>
  <?php if ($isContingencyPrint): ?><div class="homolog">NF-e EMITIDA EM CONTINGÊNCIA <?= nfce_print_h(str_replace(['SVCAN','SVCRS'],['SVC-AN','SVC-RS'],$emissionType)) ?><?php if ($contingencyReasonPrint !== ''): ?><div class="small">Motivo: <?= nfce_print_h($contingencyReasonPrint) ?><?= $contingencyStartedPrint !== '' ? ' — ' . nfce_print_h($contingencyStartedPrint) : '' ?></div><?php endif; ?></div><?php endif; ?>
  <div class="header">
    <div class="box emitente">
      <div class="emit-wrap">
        <?php if ($printLogoUrl !== ''): ?><img class="emit-logo" src="<?= nfce_print_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
        <div class="emit-data">
          <div class="logo-name"><?= nfce_print_h($emit['name'] ?? 'Emitente') ?></div>
          <div class="small">CNPJ: <?= nfce_print_h(nfce_print_doc_format($emit['document'] ?? '')) ?> &nbsp; IE: <?= nfce_print_h($emit['state_registration'] ?? '') ?></div>
          <div class="small"><?= nfce_print_h(nfce_print_address($emit)) ?></div>
        </div>
      </div>
    </div>
    <div class="box center">
      <div class="title">DANFE</div>
      <div class="subtitle">Documento Auxiliar da Nota Fiscal Eletrônica</div>
      <div style="margin-top:3px" class="small">0 - Entrada &nbsp;&nbsp; <b>1 - Saída</b></div>
      <div class="nfe-num">NF-e Nº <?= nfce_print_h($docNumber) ?></div>
      <div class="nfe-num">SÉRIE <?= nfce_print_h($doc['series'] ?? '') ?></div>
      <div class="small">Folha 1/1</div>
    </div>
    <div class="box center">
      <div class="lbl">Controle do Fisco</div>
      <?php if ($barcodeImage !== ''): ?><img class="barcode" src="<?= nfce_print_h($barcodeImage) ?>" alt="Código de barras da chave de acesso"><?php else: ?><div class="small" style="height:7.5mm;display:flex;align-items:center;justify-content:center">Código de barras indisponível</div><?php endif; ?>
      <div class="lbl">Chave de acesso</div>
      <div class="access"><?= nfce_print_h(nfce_print_access_key_format($accessKey)) ?></div>
      <div class="tiny auth-info">Consulta de autenticidade no portal nacional da NF-e: <?= nfce_print_h($sefazUrl) ?></div>
    </div>
  </div>

  <div class="grid" style="grid-template-columns:2fr 1.4fr 1fr">
    <div class="box no-border-top"><div class="lbl">Natureza da operação</div><div class="val"><?= nfce_print_h($doc['operation_nature'] ?? 'VENDA NF-E') ?></div></div>
    <div class="box no-border-top"><div class="lbl">Protocolo de autorização de uso</div><div class="val"><?= nfce_print_h($protocol !== '' ? $protocol : 'Aguardando autorização') ?></div></div>
    <div class="box no-border-top"><div class="lbl">Status SEFAZ</div><div class="val"><?= nfce_print_h(($doc['sefaz_status_code'] ?? '') . ' ' . ($doc['sefaz_status_message'] ?? '')) ?></div></div>
  </div>
  <div class="grid three">
    <div class="box no-border-top"><div class="lbl">Inscrição Estadual</div><div class="val"><?= nfce_print_h($emit['state_registration'] ?? '') ?></div></div>
    <div class="box no-border-top"><div class="lbl">CNPJ</div><div class="val"><?= nfce_print_h(nfce_print_doc_format($emit['document'] ?? '')) ?></div></div>
    <div class="box no-border-top"><div class="lbl">Emissão</div><div class="val"><?= nfce_print_h(nfce_print_date_br($doc['issue_datetime'] ?? '')) ?></div></div>
  </div>

  <div class="section-title">Destinatário / Remetente</div>
  <div class="grid" style="grid-template-columns:2fr 1fr 1fr 1fr">
    <div class="box"><div class="lbl">Nome / Razão social</div><div class="val"><?= nfce_print_h($dest['name'] ?? '') ?></div></div>
    <div class="box"><div class="lbl">CPF/CNPJ</div><div class="val"><?= nfce_print_h(nfce_print_doc_format($dest['document'] ?? '')) ?></div></div>
    <div class="box"><div class="lbl">Data emissão</div><div class="val"><?= nfce_print_h(nfce_print_date_br($doc['issue_datetime'] ?? '')) ?></div></div>
    <div class="box"><div class="lbl">Data saída</div><div class="val"><?= nfce_print_h(nfce_print_date_br($doc['exit_entry_datetime'] ?? $doc['issue_datetime'] ?? '')) ?></div></div>
  </div>
  <div class="grid" style="grid-template-columns:2fr 1fr .7fr .7fr">
    <div class="box no-border-top"><div class="lbl">Endereço</div><div class="val"><?= nfce_print_h(trim((string)($dest['address'] ?? '') . ', ' . (string)($dest['number'] ?? ''), ', ')) ?></div></div>
    <div class="box no-border-top"><div class="lbl">Bairro / Distrito</div><div class="val"><?= nfce_print_h($dest['district'] ?? '') ?></div></div>
    <div class="box no-border-top"><div class="lbl">CEP</div><div class="val"><?= nfce_print_h($dest['zip_code'] ?? '') ?></div></div>
    <div class="box no-border-top"><div class="lbl">Telefone</div><div class="val"><?= nfce_print_h($dest['phone'] ?? '') ?></div></div>
  </div>
  <div class="grid" style="grid-template-columns:1.4fr .4fr .9fr .8fr">
    <div class="box no-border-top"><div class="lbl">Município</div><div class="val"><?= nfce_print_h($dest['city'] ?? '') ?></div></div>
    <div class="box no-border-top"><div class="lbl">UF</div><div class="val"><?= nfce_print_h($dest['state'] ?? '') ?></div></div>
    <div class="box no-border-top"><div class="lbl">Inscrição Estadual</div><div class="val"><?= nfce_print_h($dest['state_registration'] ?? 'ISENTO') ?></div></div>
    <div class="box no-border-top"><div class="lbl">Hora saída</div><div class="val"><?= nfce_print_h(($doc['exit_entry_datetime'] ?? '') ? date('H:i:s', strtotime((string)$doc['exit_entry_datetime'])) : '') ?></div></div>
  </div>

  <div class="section-title">Cálculo do imposto</div>
  <!-- As 2 primeiras faixas mantem o grupo fiscal padrao; a 3a agrega IBS/CBS e outros tributos. -->
  <div class="totals">
    <div class="tax-row five">
      <div class="box"><div class="lbl">Base ICMS</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vBC', 0)) ?></div></div>
      <div class="box"><div class="lbl">Valor ICMS</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vICMS', $doc['total_icms'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Base ICMS ST</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vBCST', 0)) ?></div></div>
      <div class="box"><div class="lbl">Valor ICMS ST</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vST', $doc['total_icms_st'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Produtos</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vProd', $doc['total_products'] ?? 0)) ?></div></div>
    </div>
    <div class="tax-row six">
      <div class="box"><div class="lbl">Frete</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vFrete', $doc['total_freight'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Seguro</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vSeg', 0)) ?></div></div>
      <div class="box"><div class="lbl">Desconto</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vDesc', $doc['total_discount'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Outras desp.</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vOutro', $doc['total_other'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">IPI</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vIPI', $doc['total_ipi'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Total NF</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vNF', $doc['total_document'] ?? 0)) ?></div></div>
    </div>
    <div class="tax-row six">
      <div class="box"><div class="lbl">PIS</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vPIS', $doc['total_pis'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">COFINS</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vCOFINS', $doc['total_cofins'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">IBS UF</div><div class="val right"><?= nfce_print_decimal(nfce_print_xml_ibs_tax_total($xml, 'gIBSUF', 'vIBSUF') ?? ($doc['total_ibs_uf'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">IBS Mun.</div><div class="val right"><?= nfce_print_decimal(nfce_print_xml_ibs_tax_total($xml, 'gIBSMun', 'vIBSMun') ?? ($doc['total_ibs_mun'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">CBS</div><div class="val right"><?= nfce_print_decimal(nfce_print_xml_ibs_tax_total($xml, 'gCBS', 'vCBS') ?? ($doc['total_cbs'] ?? 0)) ?></div></div>
      <div class="box"><div class="lbl">Trib. aprox.</div><div class="val right"><?= nfce_print_decimal($nfePrintTotal('vTotTrib', $doc['total_tax_approx'] ?? 0)) ?></div></div>
    </div>
  </div>

  <div class="section-title">Transportador / Volumes transportados</div>
  <div class="grid" style="grid-template-columns:2fr 1fr 1fr 1fr">
    <div class="box"><div class="lbl">Razão social</div><div class="val">Sem frete / não informado</div></div>
    <div class="box"><div class="lbl">Frete por conta</div><div class="val">9 - Sem frete</div></div>
    <div class="box"><div class="lbl">Placa veículo</div><div class="val"></div></div>
    <div class="box"><div class="lbl">UF</div><div class="val"></div></div>
  </div>

  <div class="section-title">Dados dos produtos / serviços</div>
  <table class="items">
    <thead><tr><th>Cód.</th><th>Descrição</th><th>NCM</th><th>CFOP</th><th>UN</th><th>Qtd.</th><th>Vlr. Unit.</th><th>Vlr. Total</th><th>BC ICMS</th><th>Vlr. ICMS</th><th>Vlr. IPI</th><th>Alíq. ICMS</th></tr></thead>
    <tbody>
      <?php foreach ($items as $it): ?>
        <?php
          $taxRows = [];
          try {
            $tx = $pdo->prepare('SELECT * FROM fiscal_document_item_taxes WHERE company_id=? AND fiscal_document_item_id=? ORDER BY id ASC');
            $tx->execute([$cid, (int)$it['id']]);
            $taxRows = $tx->fetchAll(PDO::FETCH_ASSOC) ?: [];
          } catch (Throwable $e) {}
          $icmsBase = $icmsValue = $icmsRate = 0.0;
          foreach ($taxRows as $txr) if (strtoupper((string)($txr['tax_group'] ?? '')) === 'ICMS') { $icmsBase=(float)($txr['base_calc'] ?? 0); $icmsValue=(float)($txr['amount'] ?? 0); $icmsRate=(float)($txr['rate'] ?? 0); break; }
        ?>
        <tr>
          <td><?= nfce_print_h($it['product_code'] ?? $it['product_id'] ?? '') ?></td>
          <td><?= nfce_print_h($it['description'] ?? '') ?></td>
          <td><?= nfce_print_h($it['ncm'] ?? '') ?></td>
          <td><?= nfce_print_h($it['cfop'] ?? '') ?></td>
          <td><?= nfce_print_h($it['unidade'] ?? 'UN') ?></td>
          <td class="right"><?= nfce_print_decimal($it['quantity'] ?? 0, 4) ?></td>
          <td class="right"><?= nfce_print_decimal($it['unit_value'] ?? 0, 2) ?></td>
          <td class="right"><?= nfce_print_decimal($it['total_value'] ?? 0, 2) ?></td>
          <td class="right"><?= nfce_print_decimal($icmsBase, 2) ?></td>
          <td class="right"><?= nfce_print_decimal($icmsValue, 2) ?></td>
          <td class="right">0,00</td>
          <td class="right"><?= nfce_print_decimal($icmsRate, 2) ?></td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>

  <div class="section-title">Pagamento</div>
  <div class="grid three">
    <?php foreach ($payments as $pay): ?>
      <div class="box"><div class="lbl"><?= nfce_print_h(nfce_print_payment_label((string)($pay['payment_type'] ?? '99'))) ?></div><div class="val right"><?= nfce_print_money($pay['amount'] ?? 0) ?></div></div>
    <?php endforeach; ?>
    <?php if (!$payments): ?><div class="box"><div class="lbl">Pagamento</div><div class="val right"><?= nfce_print_money($doc['total_document'] ?? 0) ?></div></div><?php endif; ?>
  </div>

  <div class="section-title">Dados adicionais</div>
  <div class="grid two">
    <div class="box obs"><div class="lbl">Informações complementares</div><div class="small"><?= nfce_print_h(nfce_print_xml_tag($xml, 'infCpl') ?: 'Documento fiscal gerado pelo Nexa Próspero.') ?></div></div>
    <div class="box obs"><div class="lbl">Reservado ao Fisco</div><div class="small"><?= nfce_print_h(trim((string)($doc['sefaz_status_code'] ?? '') . ' - ' . (string)($doc['sefaz_status_message'] ?? ''), ' -')) ?></div></div>
  </div>
  <div class="page-foot"><span>Nexa Próspero - DANFE NF-e modelo 55</span><span><?= date('d/m/Y H:i') ?></span></div>
</div>
<script>
(function(){
  const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && returnTo){ e.preventDefault(); window.location.href = returnTo; }
  });
})();
</script>
</body>
</html>
<?php exit; endif; ?>
<?php
$qrCode = nfce_print_xml_tag($xml, 'qrCode');
$urlChave = nfce_print_xml_tag($xml, 'urlChave');
$qrRender = nfce_print_qrcode_render($qrCode);
$qrImage = (string)($qrRender['uri'] ?? '');
$qrSizeMm = (float)($qrRender['size_mm'] ?? ($printPack58mm ? 27.0 : 30.0));
$qrFrameMm = $qrSizeMm + 2.0; // 1 mm de margem branca em cada lado.
// Compensação física da cabeça térmica/driver: a área imprimível real fica deslocada para a direita.
// Valor negativo move apenas o QR Code para a esquerda, sem alterar o restante do DANFE.
$qrOffsetMm = $printPack58mm ? -2.0 : -3.0;
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Impressão NFC-e <?= nfce_print_h($doc['number'] ?? '') ?></title>
<style>
  *{box-sizing:border-box}
  :root{--nfce-page-paper:<?= $printPack58mm ? '58mm' : '80mm' ?>;--nfce-page-inner:<?= $printPack58mm ? '52mm' : '72mm' ?>;--nfce-content-paper:<?= $printPack58mm ? '48mm' : '66mm' ?>;--nfce-qr-offset-x:<?= number_format($qrOffsetMm, 3, '.', '') ?>mm;}
  body{font-family:Arial,Helvetica,sans-serif;background:#f3f4f6;margin:0;padding:<?= $printPack58mm ? '8px' : '18px' ?>;color:#111827}
  .wrap{width:var(--nfce-content-paper);max-width:var(--nfce-content-paper);margin:0 auto;background:#fff;border:1px solid #d1d5db;padding:<?= $printPack58mm ? '6px' : '8px' ?>;border-radius:10px;overflow:visible}
  .center{text-align:center}.nfce-head{position:relative;line-height:1.15}.nfce-head.has-logo{padding-left:11mm;padding-right:2mm}.nfce-logo{position:absolute;left:0;top:0;width:9mm;height:8mm;object-fit:contain}.muted{color:#6b7280}.small{font-size:<?= $printPack58mm ? '10px' : '11px' ?>}.tiny{font-size:<?= $printPack58mm ? '8px' : '9px' ?>}.micro{font-size:<?= $printPack58mm ? '7px' : '8px' ?>}.big{font-size:<?= $printPack58mm ? '13px' : '15px' ?>;font-weight:800;white-space:nowrap;line-height:1.05}
  .line{border-top:1px dashed #9ca3af;margin:<?= $printPack58mm ? '5px' : '8px' ?> 0}.row{display:flex;justify-content:space-between;gap:8px}.rtc-tax-row{padding-top:1px;font-weight:700}.rtc-tax-inline{margin-top:2px;text-align:right;color:#374151}.item{margin:<?= $printPack58mm ? '4px' : '7px' ?> 0}.total{font-size:<?= $printPack58mm ? '14px' : '17px' ?>;font-weight:800}
  .actions{width:var(--nfce-content-paper);max-width:var(--nfce-content-paper);margin:0 auto 12px;display:flex;gap:8px;justify-content:space-between;align-items:center}.btn{border:1px solid #cbd5e1;border-radius:10px;background:#fff;padding:0;font-weight:700;cursor:pointer;text-decoration:none;color:#111827}.actions .btn{flex:1 1 0;min-width:0;height:48px;display:inline-flex;flex-direction:column;align-items:center;justify-content:center;gap:2px}.btn-icon{font-size:18px;line-height:1}.btn-label{font-size:<?= $printPack58mm ? '7px' : '8px' ?>;line-height:1;white-space:nowrap}.primary{background:#1e3a8a;color:#fff;border-color:#1e3a8a}.whatsapp{background:#25D366;color:#fff;border-color:#1ebe57;box-shadow:0 7px 16px rgba(37,211,102,.22)}.whatsapp:hover{background:#1fbd5a;border-color:#18a94c;color:#fff}
  .qr-area{text-align:center;margin:6px auto 0;width:100%;break-inside:avoid;page-break-inside:avoid;overflow:visible;clear:both}.qr-centering{display:flex;width:100%;justify-content:center;align-items:center;margin:0;padding:0}.qr-title{font-size:<?= $printPack58mm ? '8px' : '10px' ?>;font-weight:800;margin-bottom:4px}.qr-frame{display:block;flex:0 0 auto;width:<?= number_format($qrFrameMm, 3, '.', '') ?>mm;height:<?= number_format($qrFrameMm, 3, '.', '') ?>mm;padding:1mm;margin:0;background:#fff;line-height:0;overflow:hidden;transform:translateX(var(--nfce-qr-offset-x));transform-origin:center center}.qr-img{width:100%;height:100%;max-width:100%;display:block;margin:0;background:#fff;object-fit:contain;image-rendering:pixelated;image-rendering:crisp-edges;print-color-adjust:exact;-webkit-print-color-adjust:exact}.qr-link{margin-top:4px;font-size:<?= $printPack58mm ? '7px' : '8px' ?>;overflow-wrap:anywhere;word-break:break-word;color:#374151}.qr-alert{background:#fff7ed;border:1px solid #fed7aa;border-radius:8px;padding:8px;color:#9a3412;text-align:left;line-height:1.35}.access-key{font-family:"Courier New",monospace;font-size:<?= $printPack58mm ? '6px' : '7px' ?>;white-space:nowrap;word-break:normal;overflow:visible;text-align:center;letter-spacing:0}
  .row>*{min-width:0}.row b{white-space:nowrap}.item,.small,.tiny,.micro{overflow-wrap:anywhere;word-break:normal}.ibpt-summary{margin-top:5px;padding-top:4px;border-top:1px dashed #9ca3af;text-align:center;line-height:1.08;font-size:<?= $printPack58mm ? '6.2px' : '7.2px' ?>}.ibpt-line{margin:1px 0;white-space:nowrap}.ibpt-source{color:#4b5563;font-size:inherit}.nexa-foot{margin-top:3px;text-align:center;color:#4b5563}
  @media print{@page{size:<?= $printPack58mm ? '58mm' : '80mm' ?> auto;margin:<?= $printPack58mm ? '3mm' : '4mm' ?>}html,body{background:#fff;padding:0;margin:0;width:var(--nfce-page-inner);max-width:var(--nfce-page-inner);overflow:visible}.actions{display:none}.wrap{border:none;border-radius:0;width:var(--nfce-content-paper);max-width:var(--nfce-content-paper);margin-left:auto;margin-right:auto;padding:0 1mm;overflow:visible}.no-print{display:none}.qr-area{width:100%;text-align:center;overflow:visible}.qr-centering{display:flex!important;width:100%!important;justify-content:center!important;align-items:center!important;margin:0!important;padding:0!important}.qr-frame{display:block;flex:0 0 auto;width:<?= number_format($qrFrameMm, 3, '.', '') ?>mm;height:<?= number_format($qrFrameMm, 3, '.', '') ?>mm;margin:0;padding:1mm;overflow:hidden;transform:translateX(var(--nfce-qr-offset-x))!important}.qr-img{width:100%;height:100%;min-width:0;min-height:0;max-width:100%;margin:0}}
</style>
</head>
<body>
<div class="actions"><a class="btn whatsapp" title="Enviar PDF por WhatsApp" aria-label="Enviar PDF por WhatsApp" href="<?= nfce_print_h($waShareLink) ?>"><span class="btn-icon" aria-hidden="true">💬</span><span class="btn-label">WhatsApp</span></a><a class="btn" title="Enviar por e-mail" aria-label="Enviar por e-mail" href="<?= nfce_print_h($emailShareLink) ?>"><span class="btn-icon" aria-hidden="true">✉</span><span class="btn-label">E-mail</span></a><button class="btn primary" type="button" title="Imprimir NFC-e" aria-label="Imprimir NFC-e" onclick="window.print()"><span class="btn-icon" aria-hidden="true">🖨</span><span class="btn-label">Imprimir</span></button><a class="btn" title="Sair" aria-label="Sair" href="<?= nfce_print_h($returnTo) ?>"><span class="btn-icon" aria-hidden="true">↩</span><span class="btn-label">Sair</span></a></div>
<div class="wrap">
  <div class="center nfce-head<?= $printLogoUrl !== '' ? ' has-logo' : '' ?>">
    <?php if ($printLogoUrl !== ''): ?><img class="nfce-logo" src="<?= nfce_print_h($printLogoUrl) ?>" alt="Logo da empresa"><?php endif; ?>
    <div class="big">DANFE NFC-e</div>
    <div class="tiny">Documento Auxiliar da Nota Fiscal de Consumidor Eletrônica</div>
    <div class="tiny no-print">Perfil: <?= $printPack58mm ? 'Pack 58mm' : 'padrão' ?></div>
    <div class="small" style="margin-top:4px;font-weight:700;"><?= nfce_print_h($emit['name'] ?? 'Emitente') ?></div>
    <div class="tiny">CNPJ: <?= nfce_print_h($emit['document'] ?? '') ?> IE: <?= nfce_print_h($emit['state_registration'] ?? '') ?></div>
    <?php if ($amb !== 'PRODUCAO'): ?><div class="small"><b>AMBIENTE DE HOMOLOGAÇÃO - SEM VALOR FISCAL</b></div><?php endif; ?>
    <?php if ($isContingencyPrint): ?><div class="small" style="margin-top:5px;border:2px solid #111;padding:5px"><b>EMITIDA EM CONTINGÊNCIA OFFLINE</b><?php if ($contingencyReasonPrint !== ''): ?><br><span class="tiny">Motivo: <?= nfce_print_h($contingencyReasonPrint) ?><?= $contingencyStartedPrint !== '' ? ' — ' . nfce_print_h($contingencyStartedPrint) : '' ?></span><?php endif; ?></div><?php endif; ?>
  </div>
  <div class="line"></div>
  <div class="small">Modelo 65 · Série <?= nfce_print_h($doc['series'] ?? '') ?> · Número <?= nfce_print_h($doc['number'] ?? '') ?></div>
  <div class="small">Emissão: <?= nfce_print_h($doc['issue_datetime'] ?? '') ?></div>
  <div class="small">Status: <b><?= nfce_print_h($doc['status'] ?? '') ?></b> <?= nfce_print_h($doc['sefaz_status_code'] ?? '') ?></div>
  <?php if (!empty($doc['protocol_number'])): ?><div class="small">Protocolo: <?= nfce_print_h($doc['protocol_number']) ?></div><?php endif; ?>
  <div class="line"></div>
  <?php if (!empty($dest['name'])): ?>
    <div class="small"><b>Consumidor:</b> <?= nfce_print_h($dest['name']) ?></div>
    <?php if (!empty($dest['document'])): ?><div class="tiny">CPF/CNPJ: <?= nfce_print_h($dest['document']) ?></div><?php endif; ?>
    <?php if (!empty($dest['address'])): ?><div class="tiny">Endereço: <?= nfce_print_h($dest['address']) ?>, <?= nfce_print_h($dest['number'] ?? '') ?></div><?php endif; ?>
    <div class="line"></div>
  <?php endif; ?>
  <div class="small"><b>Itens</b></div>
  <?php foreach ($items as $it): ?>
    <div class="item small">
      <div><b><?= (int)$it['item_number'] ?> - <?= nfce_print_h($it['description'] ?? '') ?></b></div>
      <div class="row"><span><?= nfce_print_qty($it['quantity'] ?? 0) ?> <?= nfce_print_h($it['unidade'] ?? 'UN') ?> x <?= nfce_print_money($it['unit_value'] ?? 0) ?></span><b><?= nfce_print_money($it['total_value'] ?? 0) ?></b></div>
      <?php if ((float)($it['other_value'] ?? 0) > 0): ?><div class="tiny muted">Acréscimo no item: <?= nfce_print_money($it['other_value']) ?></div><?php endif; ?>
    </div>
  <?php endforeach; ?>
  <div class="line"></div>
  <div class="row small"><span>Produtos</span><b><?= nfce_print_money($doc['total_products'] ?? 0) ?></b></div>
  <?php $totalOtherPrint = (float)($doc['total_other'] ?? 0) + (float)($doc['total_freight'] ?? 0); ?>
  <?php if ($totalOtherPrint > 0): ?><div class="row small"><span>Acréscimo/Entrega</span><b><?= nfce_print_money($totalOtherPrint) ?></b></div><?php endif; ?>
  <?php if ((float)($doc['total_discount'] ?? 0) > 0): ?><div class="row small"><span>Desconto</span><b><?= nfce_print_money($doc['total_discount']) ?></b></div><?php endif; ?>
  <?php if (!empty($rtcTaxTotals['present'])): ?>
    <div class="micro rtc-tax-inline">CBS: <b><?= nfce_print_money($rtcTaxTotals['cbs'] ?? 0) ?></b> &nbsp;|&nbsp; IBS: <b><?= nfce_print_money($rtcTaxTotals['ibs'] ?? 0) ?></b></div>
  <?php endif; ?>
  <div class="row total"><span>Total</span><span><?= nfce_print_money($doc['total_document'] ?? 0) ?></span></div>
  <div class="line"></div>
  <div class="small"><b>Pagamento</b></div>
  <?php foreach ($payments as $pay): ?><div class="row small"><span><?= nfce_print_h($pay['payment_type'] ?? '') ?> - <?= nfce_print_h($pay['payment_description'] ?? '') ?></span><b><?= nfce_print_money($pay['amount'] ?? 0) ?></b></div><?php endforeach; ?>
  <div class="line"></div>
  <div class="tiny center"><b>Consulte pela chave de acesso</b></div>
  <div class="access-key"><?= nfce_print_h(preg_replace('/\D+/', '', (string)($doc['access_key'] ?? ''))) ?></div>

  <div class="line"></div>
  <?php if ($qrCode !== '' && $qrImage !== ''): ?>
    <div class="qr-area">
      <div class="qr-title">Consulta via QR Code</div>
      <div class="qr-centering"><span class="qr-frame"><img class="qr-img" src="<?= nfce_print_h($qrImage) ?>" alt="QR Code da NFC-e"></span></div>
      <?php if ($urlChave !== ''): ?><div class="qr-link"><b>Consulta por chave:</b> <?= nfce_print_h($urlChave) ?></div><?php endif; ?>
    </div>
  <?php elseif ($qrCode !== ''): ?>
    <div class="qr-area">
      <div class="qr-alert">
        <b>QR Code encontrado no XML, mas a imagem não pôde ser gerada.</b><br>
        Verifique se a pasta <code>vendor</code> está instalada no servidor. Abaixo segue o conteúdo do QR Code para conferência.
      </div>
      <div class="qr-link"><b>QR Code:</b> <?= nfce_print_h($qrCode) ?></div>
      <?php if ($urlChave !== ''): ?><div class="qr-link"><b>Consulta:</b> <?= nfce_print_h($urlChave) ?></div><?php endif; ?>
    </div>
  <?php else: ?>
    <div class="qr-alert">
      <b>QR Code ainda não disponível.</b><br>
      O XML atual não possui a tag <code>infNFeSupl/qrCode</code>. Reenvie/recupere a NFC-e autorizada para que o XML assinado e autorizado grave o QR Code.
    </div>
    <?php if ($urlChave !== ''): ?><div class="qr-link"><b>Consulta:</b> <?= nfce_print_h($urlChave) ?></div><?php endif; ?>
  <?php endif; ?>

  <?php if (!empty($ibptPrintTotals['present'])): ?>
    <?php $ibptPercent = (float)($doc['total_document'] ?? 0) > 0 ? ((float)$ibptPrintTotals['total'] / (float)$doc['total_document']) * 100 : 0.0; ?>
    <div class="ibpt-summary micro">
      <?php if ((float)$ibptPrintTotals['federal'] > 0 || (float)$ibptPrintTotals['state'] > 0 || (float)$ibptPrintTotals['municipal'] > 0): ?>
        <div class="ibpt-line">IBPT: Fed <?= nfce_print_money($ibptPrintTotals['federal']) ?> | Est <?= nfce_print_money($ibptPrintTotals['state']) ?> | Mun <?= nfce_print_money($ibptPrintTotals['municipal']) ?></div>
        <div class="ibpt-line">Total <?= nfce_print_money($ibptPrintTotals['total']) ?><?php if ($ibptPercent > 0): ?> | Carga <?= nfce_print_decimal($ibptPercent, 2) ?>%<?php endif; ?></div>
      <?php else: ?>
        <div class="ibpt-line">Trib. aprox. Lei 12.741/2012: Total <?= nfce_print_money($ibptPrintTotals['total']) ?><?php if ($ibptPercent > 0): ?> · Carga <?= nfce_print_decimal($ibptPercent, 2) ?>%<?php endif; ?></div>
      <?php endif; ?>
      <?php if (trim((string)$ibptPrintTotals['source']) !== ''): ?><div class="ibpt-source micro">Fonte: <?= nfce_print_h($ibptPrintTotals['source']) ?></div><?php endif; ?>
      <?php if (!empty($ibptPrintTotals['calculated_fallback'])): ?><div class="ibpt-source micro">Informação aproximada calculada pelo Nexa.</div><?php endif; ?>
    </div>
  <?php endif; ?>
  <div class="nexa-foot micro">- NFC-e emitida por Nexa Próspero.</div>
</div>
<script>
(function(){
  const returnTo = <?= json_encode($returnTo, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
  document.addEventListener('keydown', function(e){
    if(e.key === 'Escape' && returnTo){ e.preventDefault(); window.location.href = returnTo; }
  });
})();
</script>
</body>
</html>
