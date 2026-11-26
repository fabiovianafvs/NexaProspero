<?php
/**
 * Reforma Tributária — Imposto Seletivo (IS) na NF-e/NFC-e e arredondamento oficial.
 *
 * Regras copiadas da Calculadora de Tributos oficial da Receita (calculadora-rtc v1.7.2):
 * - O IS começa em 01/01/2027 (antes disso o grupo <IS> não é gerado, mesmo configurado).
 * - NCM sujeito ao IS = NCM com alíquota ad valorem ou ad rem na tabela oficial
 *   (fiscal_is_ncm_rates, por prefixo, sem as exceções de fiscal_is_ncm_exceptions).
 *   As alíquotas ainda não estão definidas em lei (rate NULL): a empresa informa no
 *   produto/tributação (seletivo_rate % e seletivo_rate_specific R$ por unidade).
 * - vBCIS = valor do item (− desconto, + frete/seguro/outras, − ICMS), 2 casas.
 * - vIS = arred2(vBCIS × pIS ÷ 100 + qTrib × pISEspec), conta exata e arredondada uma vez.
 * - O vIS publicado entra na base do IBS/CBS.
 * - Arredondamento: HALF_EVEN (bancário), cada número publicado arredondado uma única vez
 *   (base 2 casas, alíquotas 4 casas, valores 2 casas) — ADR003 da calculadora.
 *
 * Tabelas e colunas: db/20261126_reforma_imposto_seletivo_v1.sql. Sem a migração aplicada,
 * nada muda (o IS simplesmente não é gerado).
 */
declare(strict_types=1);

if (!defined('FISCAL_IS_START_DATE')) define('FISCAL_IS_START_DATE', '2027-01-01');

/* ------------------------------------------------------------------ */
/* Arredondamento oficial (HALF_EVEN, conta exata em inteiros)         */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_rtc_to_int')) {
  /** Converte um número decimal em inteiro escalado (ex.: 12.34 com 2 casas = 1234), sem ruído de float. */
  function fiscal_rtc_to_int($value, int $decimals): int {
    $s = is_string($value) ? trim($value) : sprintf('%.' . ($decimals + 6) . 'F', (float)$value);
    if ($s === '' || !is_numeric($s)) return 0;
    $neg = false;
    if ($s[0] === '-') { $neg = true; $s = substr($s, 1); }
    elseif ($s[0] === '+') { $s = substr($s, 1); }
    if (stripos($s, 'e') !== false) $s = sprintf('%.' . ($decimals + 6) . 'F', (float)$s);
    $parts = explode('.', $s, 2);
    $int = ltrim($parts[0], '0');
    $frac = $parts[1] ?? '';
    $keep = str_pad(substr($frac, 0, $decimals), $decimals, '0');
    $rest = substr($frac, $decimals);
    $n = (int)(($int === '' ? '0' : $int) . $keep);
    // Arredonda o que sobrou (HALF_EVEN) para a escala pedida.
    if ($rest !== '' && trim($rest, '0') !== '') {
      $first = (int)$rest[0];
      $tailNonZero = trim(substr($rest, 1), '0') !== '';
      if ($first > 5 || ($first === 5 && $tailNonZero) || ($first === 5 && !$tailNonZero && ($n % 2) === 1)) $n++;
    }
    return $neg ? -$n : $n;
  }
}

if (!function_exists('fiscal_rtc_div_half_even')) {
  /** Divisão inteira com arredondamento HALF_EVEN (bancário). */
  function fiscal_rtc_div_half_even(int $num, int $den): int {
    if ($den <= 0) return 0;
    $neg = $num < 0;
    $num = abs($num);
    $q = intdiv($num, $den);
    $r = $num - $q * $den;
    $twice = 2 * $r;
    if ($twice > $den || ($twice === $den && ($q % 2) === 1)) $q++;
    return $neg ? -$q : $q;
  }
}

if (!function_exists('fiscal_rtc_round')) {
  /** Arredonda um número publicado (base, alíquota, valor) uma única vez, HALF_EVEN. */
  function fiscal_rtc_round($value, int $decimals = 2): float {
    $n = fiscal_rtc_to_int($value, $decimals);
    return $n / (10 ** $decimals);
  }
}

if (!function_exists('fiscal_rtc_percent_of')) {
  /**
   * Valor = arred2(base × alíquota ÷ 100), com base já em 2 casas e alíquota em 4 casas.
   * Conta exata em inteiros (sem erro de float), HALF_EVEN.
   */
  function fiscal_rtc_percent_of(float $base, float $ratePercent): float {
    $b = fiscal_rtc_to_int($base, 2);      // centavos
    $r = fiscal_rtc_to_int($ratePercent, 4); // décimos de milésimo de ponto percentual
    if ($b === 0 || $r === 0) return 0.0;
    if (abs($b) > intdiv(PHP_INT_MAX, max(1, abs($r)))) return fiscal_rtc_round($base * $ratePercent / 100, 2);
    // b (escala 2) × r (escala 4) ÷ 100 = escala 8 em reais; para centavos divide por 10^6.
    return fiscal_rtc_div_half_even($b * $r, 1000000) / 100;
  }
}

/* ------------------------------------------------------------------ */
/* Estrutura (migração aplicada?)                                       */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_columns_ready')) {
  /** true se a coluna existir (consulta sem linhas; funciona em qualquer banco). */
  function fiscal_is_columns_ready(PDO $pdo, string $table = 'fiscal_product_tax'): bool {
    static $cache = [];
    if (!in_array($table, ['fiscal_product_tax','fiscal_tax_profiles','fiscal_documents','fiscal_is_classifications'], true)) return false;
    $key = spl_object_id($pdo) . ':' . $table;
    if (array_key_exists($key, $cache)) return $cache[$key];
    $col = [
      'fiscal_product_tax' => 'seletivo_class_code',
      'fiscal_tax_profiles' => 'seletivo_class_code',
      'fiscal_documents' => 'total_is',
      'fiscal_is_classifications' => 'class_code',
    ][$table];
    try {
      $pdo->query('SELECT `' . $col . '` FROM `' . $table . '` WHERE 1=0');
      return $cache[$key] = true;
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('fiscal_is_ready')) {
  function fiscal_is_ready(PDO $pdo): bool {
    return fiscal_is_columns_ready($pdo, 'fiscal_is_classifications') && fiscal_is_columns_ready($pdo, 'fiscal_product_tax');
  }
}

if (!function_exists('fiscal_is_field_keys')) {
  /** Campos do IS no produto e na tributação. */
  function fiscal_is_field_keys(): array {
    return ['seletivo_cst', 'seletivo_class_code', 'seletivo_rate', 'seletivo_rate_specific', 'seletivo_unit'];
  }
}

if (!function_exists('fiscal_is_fields_from_post')) {
  /** Lê os campos do IS de um formulário ($prefix = 'fiscal_' no produto, '' na tributação). */
  function fiscal_is_fields_from_post(array $post, string $prefix = ''): array {
    $dec = static function ($v): float {
      $s = trim((string)$v);
      if ($s === '') return 0.0;
      $s = str_replace(' ', '', $s);
      if (str_contains($s, ',') && str_contains($s, '.')) $s = str_replace('.', '', $s);
      $s = str_replace(',', '.', $s);
      return is_numeric($s) ? max(0.0, min(999.9999, round((float)$s, 4))) : 0.0;
    };
    $class = substr(preg_replace('/\D+/', '', (string)($post[$prefix . 'seletivo_class_code'] ?? '')) ?: '', 0, 6);
    $cst = substr(preg_replace('/\D+/', '', (string)($post[$prefix . 'seletivo_cst'] ?? '')) ?: '', 0, 3);
    if ($class !== '' && strlen($class) === 6 && $cst === '') $cst = substr($class, 0, 3);
    if ($class === '') $cst = '';
    $unit = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($post[$prefix . 'seletivo_unit'] ?? '')) ?: '', 0, 6));
    return [
      'seletivo_cst' => $cst,
      'seletivo_class_code' => $class,
      'seletivo_rate' => $class === '' ? 0.0 : $dec($post[$prefix . 'seletivo_rate'] ?? 0),
      'seletivo_rate_specific' => $class === '' ? 0.0 : $dec($post[$prefix . 'seletivo_rate_specific'] ?? 0),
      'seletivo_unit' => $class === '' ? '' : $unit,
    ];
  }
}

/* ------------------------------------------------------------------ */
/* Tabelas oficiais                                                    */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_ncm_digits')) {
  function fiscal_is_ncm_digits($ncm): string {
    return substr(preg_replace('/\D+/', '', (string)$ncm) ?: '', 0, 8);
  }
}

if (!function_exists('fiscal_is_tables')) {
  /** Carrega uma vez por requisição as tabelas de referência (são pequenas). */
  function fiscal_is_tables(PDO $pdo): array {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (isset($cache[$key])) return $cache[$key];
    $out = ['cst' => [], 'classes' => [], 'class_ncm' => [], 'rates' => [], 'exceptions' => []];
    if (!fiscal_is_columns_ready($pdo, 'fiscal_is_classifications')) return $cache[$key] = $out;
    try {
      foreach (($pdo->query('SELECT cst_code, description, validity_start, validity_end FROM fiscal_is_cst ORDER BY cst_code')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out['cst'][(string)$r['cst_code']] = $r;
      foreach (($pdo->query('SELECT class_code, cst_code, description, with_calculation, validity_start, validity_end FROM fiscal_is_classifications ORDER BY class_code')->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) $out['classes'][(string)$r['class_code']] = $r;
      $out['class_ncm'] = $pdo->query('SELECT class_code, ncm_prefix, validity_start, validity_end FROM fiscal_is_class_ncm')->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $out['rates'] = $pdo->query('SELECT ncm_prefix, rate_type, unit, rate, validity_start, validity_end FROM fiscal_is_ncm_rates')->fetchAll(PDO::FETCH_ASSOC) ?: [];
      $out['exceptions'] = $pdo->query('SELECT ncm_prefix, base_prefix, rate_type, validity_start, validity_end FROM fiscal_is_ncm_exceptions')->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {}
    return $cache[$key] = $out;
  }
}

if (!function_exists('fiscal_is_in_validity')) {
  function fiscal_is_in_validity(array $row, string $date): bool {
    $start = trim((string)($row['validity_start'] ?? ''));
    $end = trim((string)($row['validity_end'] ?? ''));
    if ($start !== '' && substr($start, 0, 10) > $date) return false;
    if ($end !== '' && substr($end, 0, 10) < $date) return false;
    return true;
  }
}

if (!function_exists('fiscal_is_ncm_info')) {
  /**
   * Incidência do IS para o NCM na data (regra da calculadora: existe alíquota ad valorem
   * ou ad rem cadastrada para o prefixo, sem exceção). Retorna também a alíquota oficial
   * quando já publicada (hoje todas estão em branco) e a unidade da ad rem.
   */
  function fiscal_is_ncm_info(PDO $pdo, $ncm, string $date): array {
    $ncm = fiscal_is_ncm_digits($ncm);
    $out = ['ncm' => $ncm, 'subject' => false, 'ad_valorem' => false, 'ad_rem' => false, 'ad_valorem_rate' => null, 'ad_rem_rate' => null, 'unit' => '', 'prefix' => ''];
    if (strlen($ncm) !== 8) return $out;
    $t = fiscal_is_tables($pdo);
    $best = ['AD_VALOREM' => null, 'AD_REM' => null];
    foreach ($t['rates'] as $r) {
      $prefix = (string)($r['ncm_prefix'] ?? '');
      if ($prefix === '' || strncmp($ncm, $prefix, strlen($prefix)) !== 0) continue;
      if (!fiscal_is_in_validity($r, $date)) continue;
      $type = strtoupper((string)($r['rate_type'] ?? ''));
      if (!array_key_exists($type, $best)) continue;
      $excluded = false;
      foreach ($t['exceptions'] as $e) {
        if (strtoupper((string)($e['rate_type'] ?? '')) !== $type || (string)($e['base_prefix'] ?? '') !== $prefix) continue;
        if (!fiscal_is_in_validity($e, $date)) continue;
        $ep = (string)($e['ncm_prefix'] ?? '');
        if ($ep !== '' && (strncmp($ncm, $ep, strlen($ep)) === 0 || strncmp($prefix, $ep, strlen($ep)) === 0)) { $excluded = true; break; }
      }
      if ($excluded) continue;
      if ($best[$type] === null || strlen($prefix) > strlen((string)$best[$type]['ncm_prefix'])) $best[$type] = $r;
    }
    if ($best['AD_VALOREM']) {
      $out['ad_valorem'] = true;
      $out['ad_valorem_rate'] = $best['AD_VALOREM']['rate'] !== null ? (float)$best['AD_VALOREM']['rate'] : null;
      $out['prefix'] = (string)$best['AD_VALOREM']['ncm_prefix'];
    }
    if ($best['AD_REM']) {
      $out['ad_rem'] = true;
      $out['ad_rem_rate'] = $best['AD_REM']['rate'] !== null ? (float)$best['AD_REM']['rate'] : null;
      $out['unit'] = strtoupper((string)($best['AD_REM']['unit'] ?? ''));
      if ($out['prefix'] === '') $out['prefix'] = (string)$best['AD_REM']['ncm_prefix'];
    }
    $out['subject'] = $out['ad_valorem'] || $out['ad_rem'];
    return $out;
  }
}

if (!function_exists('fiscal_is_subject_ever')) {
  /** NCM sujeito ao IS em alguma data a partir de 2027 (para avisos no cadastro). */
  function fiscal_is_subject_ever(PDO $pdo, $ncm): array {
    $info = fiscal_is_ncm_info($pdo, $ncm, FISCAL_IS_START_DATE);
    if ($info['subject']) return $info;
    $today = date('Y-m-d');
    return $today > FISCAL_IS_START_DATE ? fiscal_is_ncm_info($pdo, $ncm, $today) : $info;
  }
}

if (!function_exists('fiscal_is_classification')) {
  function fiscal_is_classification(PDO $pdo, string $classCode): ?array {
    $classCode = substr(preg_replace('/\D+/', '', $classCode) ?: '', 0, 6);
    if ($classCode === '') return null;
    $t = fiscal_is_tables($pdo);
    return $t['classes'][$classCode] ?? null;
  }
}

if (!function_exists('fiscal_is_class_allows_ncm')) {
  /** O cClassTrib do IS só pode ser usado nos NCMs listados para ele (quando houver lista). */
  function fiscal_is_class_allows_ncm(PDO $pdo, string $classCode, string $ncm, string $date): bool {
    $t = fiscal_is_tables($pdo);
    $has = false;
    foreach ($t['class_ncm'] as $r) {
      if ((string)$r['class_code'] !== $classCode || !fiscal_is_in_validity($r, $date)) continue;
      $has = true;
      $p = (string)$r['ncm_prefix'];
      if ($p !== '' && strncmp($ncm, $p, strlen($p)) === 0) return true;
    }
    return !$has;
  }
}

if (!function_exists('fiscal_is_classifications_list')) {
  /** Lista para os campos de escolha (datalist). Com NCM, só as permitidas para ele. */
  function fiscal_is_classifications_list(PDO $pdo, string $ncm = ''): array {
    $t = fiscal_is_tables($pdo);
    $ncm = fiscal_is_ncm_digits($ncm);
    $date = max(date('Y-m-d'), FISCAL_IS_START_DATE);
    $out = [];
    foreach ($t['classes'] as $code => $r) {
      if (strlen($ncm) === 8 && !fiscal_is_class_allows_ncm($pdo, (string)$code, $ncm, $date)) continue;
      $out[] = ['class_code' => (string)$code, 'cst_code' => (string)$r['cst_code'], 'description' => (string)$r['description'], 'with_calculation' => (int)$r['with_calculation']];
    }
    return $out;
  }
}

if (!function_exists('fiscal_is_suggestion')) {
  /**
   * Sugestão para o cadastro: quem revende (comprou de quem já recolheu o IS) usa
   * CST 200 / cClassTrib 200007, sem valor; fabricante/importador usa 000001 com alíquota.
   */
  function fiscal_is_suggestion(PDO $pdo, $ncm): array {
    $info = fiscal_is_subject_ever($pdo, $ncm);
    if (!$info['subject']) return ['subject' => false, 'ncm' => $info['ncm']];
    $kind = $info['ad_rem'] && !$info['ad_valorem'] ? 'ad rem (R$ por ' . ($info['unit'] ?: 'unidade') . ')' : ($info['ad_rem'] ? 'ad valorem e ad rem' : 'ad valorem (%)');
    return [
      'subject' => true,
      'ncm' => $info['ncm'],
      'kind' => $kind,
      'unit' => $info['unit'],
      'revenda' => ['cst' => '200', 'class_code' => '200007'],
      'fabricante' => ['cst' => '000', 'class_code' => '000001'],
      'message' => 'NCM ' . $info['ncm'] . ' sujeito ao Imposto Seletivo a partir de 01/01/2027 (' . $kind . '). '
        . 'Revenda (comprou de quem já recolheu o IS): CST 200, cClassTrib 200007, sem valor. '
        . 'Fabricante/importador: CST 000, cClassTrib 000001, com a alíquota informada.',
    ];
  }
}

/* ------------------------------------------------------------------ */
/* Resolução e cálculo na emissão                                      */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_resolve')) {
  /**
   * Decide se o item leva o grupo <IS> e com quais dados.
   *
   * $tax    = cadastro fiscal do produto (fiscal_product_tax, campos seletivo_*).
   * $opts   = ['manual' => [is_class, is_cst, is_rate, is_rate_specific, is_unit],  // "Impostos do item"
   *            'return_role' => 'ADQUIRENTE'|'FORNECEDOR'|'' ]  // devolução: quem emite a nota
   *
   * Retorno: enabled (gera o grupo), error (bloqueia a emissão com a mensagem), dados do grupo.
   */
  function fiscal_is_resolve(PDO $pdo, array $tax, $ncm, string $issueDate, array $opts = []): array {
    $date = preg_match('/^\d{4}-\d{2}-\d{2}/', $issueDate, $m) ? $m[0] : date('Y-m-d');
    $ncm = fiscal_is_ncm_digits($ncm);
    $out = ['enabled' => false, 'error' => '', 'reason' => '', 'cst' => '', 'class_code' => '', 'with_calculation' => false,
      'rate' => 0.0, 'rate_specific' => 0.0, 'unit' => '', 'subject' => false, 'source' => ''];
    if ($date < FISCAL_IS_START_DATE) { $out['reason'] = 'O Imposto Seletivo começa em 01/01/2027.'; return $out; }
    if (!fiscal_is_ready($pdo)) { $out['reason'] = 'Tabelas do Imposto Seletivo não instaladas (Atualizador do Banco).'; return $out; }
    $manual = (array)($opts['manual'] ?? []);
    $info = fiscal_is_ncm_info($pdo, $ncm, $date);
    $out['subject'] = $info['subject'];

    $manualClass = substr(preg_replace('/\D+/', '', (string)($manual['is_class'] ?? '')) ?: '', 0, 6);
    $class = $manualClass !== '' ? $manualClass : substr(preg_replace('/\D+/', '', (string)($tax['seletivo_class_code'] ?? '')) ?: '', 0, 6);
    $out['source'] = $manualClass !== '' ? 'ITEM' : 'PRODUTO';
    if ($class === '') {
      if ($info['subject']) {
        $out['error'] = 'NCM ' . $ncm . ' está sujeito ao Imposto Seletivo desde 01/01/2027. Informe o CST e o cClassTrib do IS no cadastro fiscal do produto '
          . '(revenda: CST 200 · cClassTrib 200007; fabricante/importador: CST 000 · cClassTrib 000001 com a alíquota).';
      } else {
        $out['reason'] = 'Produto sem Imposto Seletivo.';
      }
      return $out;
    }

    // Devolução: o item que pagou IS na operação original volta com CST 300.
    $role = strtoupper(trim((string)($opts['return_role'] ?? '')));
    $row = fiscal_is_classification($pdo, $class);
    $converted = false;
    if ($row && in_array($role, ['ADQUIRENTE', 'FORNECEDOR'], true) && (int)$row['with_calculation'] === 1 && (string)$row['cst_code'] !== '300') {
      $class = $role === 'ADQUIRENTE' ? '300001' : '300002';
      $row = fiscal_is_classification($pdo, $class);
      $converted = true;
    }
    if (!$row) { $out['error'] = 'cClassTrib do Imposto Seletivo ' . $class . ' não existe na tabela oficial.'; return $out; }
    if (!fiscal_is_in_validity($row, $date)) { $out['error'] = 'cClassTrib do Imposto Seletivo ' . $class . ' fora da vigência em ' . $date . '.'; return $out; }
    $cst = (string)$row['cst_code'];
    $manualCst = substr(preg_replace('/\D+/', '', (string)($manual['is_cst'] ?? '')) ?: '', 0, 3);
    if (!$converted && $manualCst !== '' && str_pad($manualCst, 3, '0', STR_PAD_LEFT) !== $cst) {
      $out['error'] = 'O CST do Imposto Seletivo ' . $manualCst . ' não corresponde ao cClassTrib ' . $class . ' (CST ' . $cst . '). Deixe o CST em branco para usar o do cClassTrib.';
      return $out;
    }
    if ($info['subject'] && !fiscal_is_class_allows_ncm($pdo, $class, $ncm, $date)) {
      $out['error'] = 'O cClassTrib do Imposto Seletivo ' . $class . ' não pode ser usado com o NCM ' . $ncm . '.';
      return $out;
    }
    $out['cst'] = $cst;
    $out['class_code'] = $class;
    $out['with_calculation'] = (int)$row['with_calculation'] === 1;
    if ($out['with_calculation']) {
      $pick = static function ($manualValue, $taxValue, $official): float {
        if ($manualValue !== null && trim((string)$manualValue) !== '') return max(0.0, (float)$manualValue);
        if ((float)$taxValue > 0) return (float)$taxValue;
        return $official !== null ? max(0.0, (float)$official) : 0.0;
      };
      $out['rate'] = fiscal_rtc_round($pick($manual['is_rate'] ?? null, $tax['seletivo_rate'] ?? 0, $info['ad_valorem_rate']), 4);
      $out['rate_specific'] = fiscal_rtc_round($pick($manual['is_rate_specific'] ?? null, $tax['seletivo_rate_specific'] ?? 0, $info['ad_rem_rate']), 4);
      $unit = strtoupper(trim((string)($manual['is_unit'] ?? '')));
      if ($unit === '') $unit = strtoupper(trim((string)($tax['seletivo_unit'] ?? '')));
      if ($unit === '') $unit = $info['unit'];
      $out['unit'] = substr(preg_replace('/[^A-Z0-9]/', '', $unit) ?: '', 0, 6);
      if ($out['rate'] <= 0 && $out['rate_specific'] <= 0) {
        $out['error'] = 'Imposto Seletivo (cClassTrib ' . $class . '): informe a alíquota ad valorem (%) e/ou ad rem (R$ por unidade) no cadastro fiscal do produto.';
        return $out;
      }
      if ($out['rate_specific'] > 0 && $out['unit'] === '') {
        $out['error'] = 'Imposto Seletivo (cClassTrib ' . $class . '): informe a unidade de medida do IS (ex.: VN, LT, KG) para a alíquota ad rem.';
        return $out;
      }
    }
    $out['enabled'] = true;
    return $out;
  }
}

if (!function_exists('fiscal_is_calculate')) {
  /**
   * Calcula vBCIS/vIS. $grossBase = vProd − vDesc + frete + seguro + outras; $excluded = ICMS (não integra).
   * $baseOverride (devolução pela nota original) substitui a base calculada.
   */
  function fiscal_is_calculate(array $resolved, float $grossBase, float $quantity, float $excluded = 0.0, ?float $baseOverride = null): array {
    $base = $baseOverride !== null ? max(0.0, $baseOverride) : max(0.0, $grossBase - max(0.0, $excluded));
    $base = fiscal_rtc_round($base, 2);
    $out = ['base' => $base, 'rate' => 0.0, 'rate_specific' => 0.0, 'unit' => '', 'quantity' => 0.0, 'amount' => 0.0];
    if (empty($resolved['enabled']) || empty($resolved['with_calculation'])) return $out;
    $rate = fiscal_rtc_round((float)($resolved['rate'] ?? 0), 4);
    $esp = fiscal_rtc_round((float)($resolved['rate_specific'] ?? 0), 4);
    $out['rate'] = $rate;
    $out['rate_specific'] = $esp;
    // Conta exata em escala 8 (reais), arredondada uma vez para centavos.
    $b = fiscal_rtc_to_int($base, 2);
    $p = fiscal_rtc_to_int($rate, 4);
    $sum = $b * $p; // escala 2 + 4 + 2 (÷100 do percentual) = 8
    if ($esp > 0) {
      $q = fiscal_rtc_to_int(max(0.0, $quantity), 4);
      $e = fiscal_rtc_to_int($esp, 4);
      $sum += $q * $e; // escala 4 + 4 = 8
      $out['unit'] = (string)($resolved['unit'] ?? '');
      $out['quantity'] = fiscal_rtc_round(max(0.0, $quantity), 4);
    }
    $out['amount'] = fiscal_rtc_div_half_even($sum, 1000000) / 100;
    return $out;
  }
}

if (!function_exists('fiscal_is_tax_extra')) {
  /** extra_json da linha 'IS' em fiscal_document_item_taxes. */
  function fiscal_is_tax_extra(array $resolved, array $calc): string {
    return json_encode([
      'CSTIS' => (string)$resolved['cst'],
      'cClassTribIS' => (string)$resolved['class_code'],
      'with_calculation' => !empty($resolved['with_calculation']) ? 1 : 0,
      'vBCIS' => (float)$calc['base'],
      'pIS' => (float)$calc['rate'],
      'pISEspec' => (float)$calc['rate_specific'],
      'uTrib' => (string)$calc['unit'],
      'qTrib' => (float)$calc['quantity'],
      'vIS' => (float)$calc['amount'],
      'source' => (string)($resolved['source'] ?? ''),
      'calc_version' => 'NEXA_IS_V1',
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}';
  }
}

if (!function_exists('fiscal_is_manual_from_item')) {
  /** Campos "Impostos do item" (tela de saídas/devoluções) que interessam ao IS. */
  function fiscal_is_manual_from_item(array $manual): array {
    $out = [];
    foreach (['is_class', 'is_cst', 'is_unit'] as $k) {
      if (isset($manual[$k]) && trim((string)$manual[$k]) !== '') $out[$k] = trim((string)$manual[$k]);
    }
    foreach (['is_rate', 'is_rate_specific'] as $k) {
      if (isset($manual[$k]) && $manual[$k] !== null && trim((string)$manual[$k]) !== '') $out[$k] = max(0.0, (float)$manual[$k]);
    }
    return $out;
  }
}

/* ------------------------------------------------------------------ */
/* XML                                                                 */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_add_xml')) {
  /**
   * Grupo <IS> do item (leiaute PL_010): CSTIS, cClassTribIS, vBCIS, pIS, [pISEspec],
   * [uTrib, qTrib], vIS. Retorna o vIS publicado.
   */
  function fiscal_is_add_xml(Closure $add, DOMElement $imp, array $taxRow): float {
    $extra = [];
    $raw = trim((string)($taxRow['extra_json'] ?? ''));
    if ($raw !== '') { $tmp = json_decode($raw, true); if (is_array($tmp)) $extra = $tmp; }
    $cst = str_pad(substr(preg_replace('/\D+/', '', (string)($extra['CSTIS'] ?? $taxRow['cst'] ?? '')) ?: '', 0, 3), 3, '0', STR_PAD_LEFT);
    $class = substr(preg_replace('/\D+/', '', (string)($extra['cClassTribIS'] ?? $taxRow['class_code'] ?? '')) ?: '', 0, 6);
    if ($class === '' || strlen($class) !== 6) throw new RuntimeException('Imposto Seletivo sem cClassTrib válido no item. Abra Editar e informe o cClassTrib do IS.');
    $f2 = static fn($v): string => number_format(fiscal_rtc_round($v, 2), 2, '.', '');
    $f4 = static fn($v): string => number_format(fiscal_rtc_round($v, 4), 4, '.', '');
    $base = (float)($extra['vBCIS'] ?? $taxRow['base_calc'] ?? 0);
    $rate = (float)($extra['pIS'] ?? $taxRow['rate'] ?? 0);
    $esp = (float)($extra['pISEspec'] ?? 0);
    $unit = substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($extra['uTrib'] ?? '')) ?: '', 0, 6);
    $qty = (float)($extra['qTrib'] ?? 0);
    $amount = fiscal_rtc_round((float)($extra['vIS'] ?? $taxRow['amount'] ?? 0), 2);
    $node = $add($imp, 'IS', '');
    $add($node, 'CSTIS', $cst);
    $add($node, 'cClassTribIS', $class);
    $add($node, 'vBCIS', $f2($base));
    $add($node, 'pIS', $f4($rate));
    if ($esp > 0) {
      $add($node, 'pISEspec', $f4($esp));
      if ($unit !== '' && $qty > 0) {
        $add($node, 'uTrib', $unit);
        $add($node, 'qTrib', $f4($qty));
      }
    }
    $add($node, 'vIS', $f2($amount));
    return $amount;
  }
}

/* ------------------------------------------------------------------ */
/* Leitura de XML (entradas e devoluções)                              */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_from_xml_block')) {
  /** Lê o grupo <IS> de um <imposto> em texto (parser de entradas). */
  function fiscal_is_from_xml_block(string $imposto): array {
    if ($imposto === '' || !preg_match('/<(?:[A-Za-z0-9_]+:)?IS\b[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?IS>/is', $imposto, $m)) return [];
    $body = (string)$m[1];
    $tag = static function (string $name) use ($body): string {
      return preg_match('/<(?:[A-Za-z0-9_]+:)?' . $name . '\b[^>]*>([^<]*)<\//i', $body, $mm) ? trim(html_entity_decode((string)$mm[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    };
    $num = static fn(string $v): float => is_numeric($v) ? (float)$v : 0.0;
    return [
      'is_cst' => preg_replace('/\D+/', '', $tag('CSTIS')) ?: '',
      'is_class' => preg_replace('/\D+/', '', $tag('cClassTribIS')) ?: '',
      'is_base' => $num($tag('vBCIS')),
      'is_rate' => $num($tag('pIS')),
      'is_rate_specific' => $num($tag('pISEspec')),
      'is_unit' => $tag('uTrib'),
      'is_qty' => $num($tag('qTrib')),
      'is_amount' => $num($tag('vIS')),
    ];
  }
}

/* ------------------------------------------------------------------ */
/* Formulário (produto e tributação)                                   */
/* ------------------------------------------------------------------ */

if (!function_exists('fiscal_is_form_html')) {
  /**
   * Campos do Imposto Seletivo. $prefix = 'fiscal_' (produto) ou '' (tributação).
   * $ncmSelector = seletor CSS do campo NCM na tela (produto), para o aviso de "NCM sujeito ao IS".
   */
  function fiscal_is_form_html(PDO $pdo, array $current, string $prefix = '', string $ncmSelector = '', bool $disabled = false): string {
    if (!fiscal_is_ready($pdo)) {
      return '<div class="nexa-is-off">Imposto Seletivo: aplique a atualização <b>Reforma Tributária - Imposto Seletivo v1</b> no Atualizador do Banco para liberar estes campos.</div>';
    }
    $h = static fn($v): string => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
    $t = fiscal_is_tables($pdo);
    $classes = [];
    foreach ($t['classes'] as $code => $r) $classes[(string)$code] = ['cst' => (string)$r['cst_code'], 'd' => (string)$r['description'], 'c' => (int)$r['with_calculation']];
    $classNcm = [];
    foreach ($t['class_ncm'] as $r) $classNcm[(string)$r['class_code']][] = (string)$r['ncm_prefix'];
    $rates = [];
    foreach ($t['rates'] as $r) $rates[(string)$r['ncm_prefix'] . '|' . strtoupper((string)$r['rate_type'])] = ['p' => (string)$r['ncm_prefix'], 't' => strtoupper((string)$r['rate_type']), 'u' => strtoupper((string)($r['unit'] ?? ''))];
    $exc = [];
    foreach ($t['exceptions'] as $r) $exc[] = ['p' => (string)$r['ncm_prefix'], 'b' => (string)$r['base_prefix'], 't' => strtoupper((string)$r['rate_type'])];
    $data = ['classes' => $classes, 'classNcm' => $classNcm, 'rates' => array_values($rates), 'exceptions' => $exc];
    $class = (string)($current['seletivo_class_code'] ?? '');
    $cst = (string)($current['seletivo_cst'] ?? '');
    $desc = $classes[$class]['d'] ?? '';
    $rate = (float)($current['seletivo_rate'] ?? 0);
    $esp = (float)($current['seletivo_rate_specific'] ?? 0);
    $unit = (string)($current['seletivo_unit'] ?? '');
    $dis = $disabled ? ' disabled' : '';
    $fmt = static fn(float $v): string => $v > 0 ? rtrim(rtrim(number_format($v, 4, ',', ''), '0'), ',') : '';
    $id = 'nexaIs' . substr(md5($prefix . $ncmSelector), 0, 6);
    $html = '<div class="nexa-is-box" id="' . $id . '" data-prefix="' . $h($prefix) . '" data-ncm="' . $h($ncmSelector) . '">';
    $html .= '<div class="nexa-is-hint" data-is-hint hidden></div>';
    $html .= '<div class="nexa-is-grid">';
    $html .= '<label>cClassTrib do IS<input class="field" name="' . $h($prefix) . 'seletivo_class_code" data-is="class" data-fiscal="seletivo_class_code" list="' . $id . 'List" maxlength="6" inputmode="numeric" value="' . $h($class) . '" placeholder="Ex.: 200007"' . $dis . '></label>';
    $html .= '<label>CST do IS<input class="field" name="' . $h($prefix) . 'seletivo_cst" data-is="cst" data-fiscal="seletivo_cst" maxlength="3" value="' . $h($cst) . '" readonly title="Vem do cClassTrib"' . $dis . '></label>';
    $html .= '<label class="nexa-is-wide">Descrição<input class="field" data-is="desc" value="' . $h($desc) . '" readonly tabindex="-1"></label>';
    $html .= '<label>Alíquota ad valorem (%)<input class="field" name="' . $h($prefix) . 'seletivo_rate" data-is="rate" data-fiscal="seletivo_rate" inputmode="decimal" value="' . $h($fmt($rate)) . '" placeholder="Ex.: 10,0000"' . $dis . '></label>';
    $html .= '<label>Alíquota ad rem (R$ por unidade)<input class="field" name="' . $h($prefix) . 'seletivo_rate_specific" data-is="esp" data-fiscal="seletivo_rate_specific" inputmode="decimal" value="' . $h($fmt($esp)) . '" placeholder="Ex.: 1,5000"' . $dis . '></label>';
    $html .= '<label>Unidade do IS<input class="field" name="' . $h($prefix) . 'seletivo_unit" data-is="unit" data-fiscal="seletivo_unit" list="' . $id . 'Units" maxlength="6" value="' . $h($unit) . '" placeholder="Ex.: VN, LT, KG"' . $dis . '></label>';
    $html .= '</div>';
    $html .= '<div class="nexa-is-note">Vale a partir de 01/01/2027. Revenda de produto que já pagou o IS na compra: <b>200007</b> (CST 200, sem valor). Fabricante/importador: <b>000001</b> e a alíquota (ainda não definida em lei). Deixe em branco se o produto não tem IS.</div>';
    $html .= '<datalist id="' . $id . 'List">';
    foreach ($classes as $code => $c) $html .= '<option value="' . $h($code) . '">' . $h('CST ' . $c['cst'] . ' · ' . mb_strimwidth($c['d'], 0, 110, '…', 'UTF-8')) . '</option>';
    $html .= '</datalist><datalist id="' . $id . 'Units"><option value="UN">Unidade</option><option value="VN">Vintena (cigarros)</option><option value="LT">Litro</option><option value="KG">Quilograma</option><option value="M3">Metro cúbico</option></datalist>';
    $html .= '<script>(function(){var box=document.getElementById(' . json_encode($id) . ');if(!box)return;var D=' . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP) . ';'
      . 'function q(k){return box.querySelector(\'[data-is="\'+k+\'"]\');}'
      . 'function digits(v){return String(v||"").replace(/\\D+/g,"");}'
      . 'function subject(ncm){ncm=digits(ncm).slice(0,8);if(ncm.length!==8)return null;var out={av:false,ar:false,u:""};D.rates.forEach(function(r){if(ncm.indexOf(r.p)!==0)return;var ex=D.exceptions.some(function(e){return e.t===r.t&&e.b===r.p&&(ncm.indexOf(e.p)===0||r.p.indexOf(e.p)===0);});if(ex)return;if(r.t==="AD_REM"){out.ar=true;out.u=r.u;}else out.av=true;});return (out.av||out.ar)?out:null;}'
      . 'function allows(cls,ncm){var l=D.classNcm[cls];if(!l||!l.length)return true;ncm=digits(ncm);return l.some(function(p){return ncm.indexOf(p)===0;});}'
      . 'function ncmVal(){var sel=box.getAttribute("data-ncm");if(!sel)return "";var el=document.querySelector(sel);return el?el.value:"";}'
      . 'function sync(){var cls=digits(q("class").value).slice(0,6);var c=D.classes[cls];q("cst").value=c?c.cst:(cls?"":"");q("desc").value=c?c.d:(cls.length===6?"cClassTrib não encontrado na tabela oficial":"");'
      . '["rate","esp","unit"].forEach(function(k){var el=q(k);if(!el)return;var on=!!(c&&c.c===1);el.readOnly=!on;el.style.opacity=on?"":"0.55";if(!on&&c)el.value="";});'
      . 'var hint=box.querySelector("[data-is-hint]");var ncm=ncmVal();var s=subject(ncm);var msg="";'
      . 'if(s&&!cls){msg="<b>NCM "+digits(ncm)+" sujeito ao Imposto Seletivo a partir de 01/01/2027</b> ("+(s.av&&s.ar?"ad valorem e ad rem":(s.ar?"ad rem, por "+(s.u||"unidade"):"ad valorem"))+"). Sem estes campos a nota será recusada em 2027. <button type=\\"button\\" class=\\"btn btn-sm\\" data-is-use=\\"200007\\">Usar revenda (200007)</button> <button type=\\"button\\" class=\\"btn btn-sm\\" data-is-use=\\"000001\\">Usar fabricante (000001)</button>";}'
      . 'else if(cls&&c&&s&&!allows(cls,ncm)){msg="O cClassTrib "+cls+" não pode ser usado com o NCM "+digits(ncm)+".";}'
      . 'else if(cls&&c&&ncm&&digits(ncm).length===8&&!s){msg="Atenção: o NCM "+digits(ncm)+" não consta na tabela oficial do Imposto Seletivo.";}'
      . 'else if(s&&c&&c.c===1&&s.ar&&!q("unit").value){q("unit").value=s.u||"";}'
      . 'hint.innerHTML=msg;hint.hidden=!msg;}'
      . 'box.addEventListener("click",function(e){var b=e.target.closest("[data-is-use]");if(!b)return;q("class").value=b.getAttribute("data-is-use");sync();});'
      . '["input","change"].forEach(function(ev){q("class").addEventListener(ev,sync);});'
      . 'var sel=box.getAttribute("data-ncm");if(sel){document.addEventListener("input",function(e){if(e.target&&e.target.matches&&e.target.matches(sel))sync();});document.addEventListener("change",function(e){if(e.target&&e.target.matches&&e.target.matches(sel))sync();});}'
      . 'box.nexaIsSync=sync;sync();})();</script>';
    $html .= '<style>.nexa-is-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:10px}.nexa-is-grid label{display:flex;flex-direction:column;gap:4px;font-size:13px}.nexa-is-wide{grid-column:span 1}.nexa-is-note{font-size:12px;color:#64748b;margin-top:6px}.nexa-is-hint{background:#fff7ed;border:1px solid #fdba74;color:#9a3412;border-radius:8px;padding:8px 10px;margin-bottom:8px;font-size:13px}.nexa-is-hint .btn{margin-left:4px}.nexa-is-off{font-size:13px;color:#64748b}@media(max-width:720px){.nexa-is-grid{grid-template-columns:1fr}}</style>';
    $html .= '</div>';
    return $html;
  }
}

if (!function_exists('fiscal_is_original_item_tax')) {
  /**
   * IS do item de uma nota emitida pelo Nexa (devolução de venda): devolve os dados para
   * repetir na devolução, com a base por unidade para ficar proporcional à quantidade.
   */
  function fiscal_is_original_item_tax(PDO $pdo, int $companyId, int $fiscalItemId): ?array {
    if ($companyId <= 0 || $fiscalItemId <= 0) return null;
    try {
      $st = $pdo->prepare("SELECT t.cst, t.class_code, t.base_calc, t.rate, t.amount, t.extra_json, i.quantity
                             FROM fiscal_document_item_taxes t
                             JOIN fiscal_document_items i ON i.id=t.fiscal_document_item_id AND i.company_id=t.company_id
                            WHERE t.company_id=? AND t.fiscal_document_item_id=? AND UPPER(t.tax_group)='IS'
                            ORDER BY t.id ASC LIMIT 1");
      $st->execute([$companyId, $fiscalItemId]);
      $r = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return null; }
    if (!$r) return null;
    $extra = json_decode((string)($r['extra_json'] ?? ''), true);
    if (!is_array($extra)) $extra = [];
    $qty = max(0.0, (float)($r['quantity'] ?? 0));
    $base = (float)($extra['vBCIS'] ?? $r['base_calc'] ?? 0);
    return [
      'manual' => [
        'is_class' => (string)($extra['cClassTribIS'] ?? $r['class_code'] ?? ''),
        'is_rate' => (float)($extra['pIS'] ?? $r['rate'] ?? 0),
        'is_rate_specific' => (float)($extra['pISEspec'] ?? 0),
        'is_unit' => (string)($extra['uTrib'] ?? ''),
      ],
      'base_per_qty' => $qty > 0 ? $base / $qty : 0.0,
      'qtrib_per_qty' => $qty > 0 ? (float)($extra['qTrib'] ?? 0) / $qty : 0.0,
    ];
  }
}

/* ------------------------------------------------------------------ */
/* Monofasia do IBS/CBS (combustíveis) — SÓ O FORMATO                  */
/* ------------------------------------------------------------------ */
/*
 * A Receita desligou a monofasia na calculadora oficial (V0014) e removeu as alíquotas
 * ad rem (V0057). O Nexa deixa pronto apenas o formato do XML (leiaute PL_010):
 * o grupo gIBSCBSMono no item e gMono no total são gerados SOMENTE se a linha IBSCBS
 * trouxer 'gIBSCBSMono' no extra_json — nenhuma tela grava isso ainda. Quando a Receita
 * publicar as alíquotas, falta só calcular e gravar esse bloco (CST 620).
 *
 * Formato esperado em extra_json['gIBSCBSMono']:
 *   ['padrao' => ['qBCMono','adRemIBS','adRemCBS','vIBSMono','vCBSMono'],
 *    'reten'  => ['qBCMonoReten','adRemIBSReten','vIBSMonoReten','adRemCBSReten','vCBSMonoReten'],
 *    'ret'    => ['qBCMonoRet','adRemIBSRet','vIBSMonoRet','adRemCBSRet','vCBSMonoRet'],
 *    'dif'    => ['pDifIBS','vIBSMonoDif','pDifCBS','vCBSMonoDif']]
 */

if (!function_exists('fiscal_mono_groups')) {
  function fiscal_mono_groups(): array {
    return [
      'padrao' => ['tag' => 'gMonoPadrao', 'fields' => ['qBCMono' => 4, 'adRemIBS' => 4, 'adRemCBS' => 4, 'vIBSMono' => 2, 'vCBSMono' => 2]],
      'reten' => ['tag' => 'gMonoReten', 'fields' => ['qBCMonoReten' => 4, 'adRemIBSReten' => 4, 'vIBSMonoReten' => 2, 'adRemCBSReten' => 4, 'vCBSMonoReten' => 2]],
      'ret' => ['tag' => 'gMonoRet', 'fields' => ['qBCMonoRet' => 4, 'adRemIBSRet' => 4, 'vIBSMonoRet' => 2, 'adRemCBSRet' => 4, 'vCBSMonoRet' => 2]],
      'dif' => ['tag' => 'gMonoDif', 'fields' => ['pDifIBS' => 4, 'vIBSMonoDif' => 2, 'pDifCBS' => 4, 'vCBSMonoDif' => 2]],
    ];
  }
}

if (!function_exists('fiscal_mono_item_totals')) {
  /** vTotIBSMonoItem / vTotCBSMonoItem = padrão + retenção − diferimento (valores publicados). */
  function fiscal_mono_item_totals(array $mono): array {
    $v = static fn(string $g, string $k): float => fiscal_rtc_round((float)($mono[$g][$k] ?? 0), 2);
    $ibs = $v('padrao', 'vIBSMono') + $v('reten', 'vIBSMonoReten') - $v('dif', 'vIBSMonoDif');
    $cbs = $v('padrao', 'vCBSMono') + $v('reten', 'vCBSMonoReten') - $v('dif', 'vCBSMonoDif');
    return ['ibs' => fiscal_rtc_round(max(0.0, $ibs), 2), 'cbs' => fiscal_rtc_round(max(0.0, $cbs), 2)];
  }
}

if (!function_exists('fiscal_mono_add_xml')) {
  /** Grupo gIBSCBSMono dentro de <IBSCBS> (no lugar de gIBSCBS). */
  function fiscal_mono_add_xml(Closure $add, DOMElement $ibscbs, array $mono): array {
    $g = $add($ibscbs, 'gIBSCBSMono', '');
    foreach (fiscal_mono_groups() as $key => $def) {
      if (empty($mono[$key]) || !is_array($mono[$key])) continue;
      $node = $add($g, $def['tag'], '');
      foreach ($def['fields'] as $field => $dec) {
        $add($node, $field, number_format(fiscal_rtc_round((float)($mono[$key][$field] ?? 0), $dec), $dec, '.', ''));
      }
    }
    $tot = fiscal_mono_item_totals($mono);
    $add($g, 'vTotIBSMonoItem', number_format($tot['ibs'], 2, '.', ''));
    $add($g, 'vTotCBSMonoItem', number_format($tot['cbs'], 2, '.', ''));
    return $tot;
  }
}

if (!function_exists('fiscal_mono_add_tot_xml')) {
  /** Grupo gMono do IBSCBSTot (soma dos itens). */
  function fiscal_mono_add_tot_xml(Closure $add, DOMElement $ibscbsTot, array $sum): void {
    $g = $add($ibscbsTot, 'gMono', '');
    foreach (['vIBSMono', 'vCBSMono', 'vIBSMonoReten', 'vCBSMonoReten', 'vIBSMonoRet', 'vCBSMonoRet'] as $k) {
      $add($g, $k, number_format(fiscal_rtc_round((float)($sum[$k] ?? 0), 2), 2, '.', ''));
    }
  }
}

if (!function_exists('fiscal_is_products_pending')) {
  /** Produtos ativos da empresa com NCM sujeito ao IS e sem cClassTrib do IS (para o aviso nas tributações). */
  function fiscal_is_products_pending(PDO $pdo, int $companyId, int $limit = 30): array {
    $out = ['total' => 0, 'items' => []];
    if ($companyId <= 0 || !fiscal_is_ready($pdo)) return $out;
    try {
      $st = $pdo->prepare("SELECT p.id, p.nome, t.ncm FROM fiscal_product_tax t
                             JOIN produtos p ON p.id=t.product_id AND p.company_id=t.company_id
                            WHERE t.company_id=? AND COALESCE(t.seletivo_class_code,'')='' AND COALESCE(t.ncm,'')<>''
                              AND (t.ncm LIKE '22%' OR t.ncm LIKE '24%' OR t.ncm LIKE '26%' OR t.ncm LIKE '27%' OR t.ncm LIKE '87%' OR t.ncm LIKE '88%' OR t.ncm LIKE '89%')
                            ORDER BY p.nome");
      $st->execute([$companyId]);
      foreach (($st->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
        $info = fiscal_is_subject_ever($pdo, (string)$r['ncm']);
        if (!$info['subject']) continue;
        $out['total']++;
        if (count($out['items']) < $limit) $out['items'][] = ['id' => (int)$r['id'], 'nome' => (string)$r['nome'], 'ncm' => (string)$r['ncm']];
      }
    } catch (Throwable $e) {}
    return $out;
  }
}
