<?php
declare(strict_types=1);

/*
 * Nexa Próspero - Agente de IA de entrada de notas.
 *
 * Pega a NF-e de compra do Radar, gera a entrada (fiscal_xml_imports) e deixa
 * os itens prontos para a conferência:
 *  1. vínculo já salvo do fornecedor (código/EAN) e código de barras: decisão direta;
 *  2. demais itens: a IA escolhe entre produtos parecidos do cadastro, ou indica
 *     cadastrar um novo (nome, grupo, unidade e fator de conversão);
 *  3. fator de conversão conferido pelo custo (ex.: caixa com 12 → fator 12);
 *  4. CFOP de entrada pela regra de conversão da empresa ou pelo padrão
 *     (5102 → 1102, 5405 → 1403, 6102 → 2102...);
 *  5. produto novo: preço de venda pela margem de produtos parecidos
 *     (mesmo NCM, depois mesmo grupo, depois mesmo fornecedor).
 * Não gera a entrada: ela fica "pronta para conferir" na tela da nota.
 * Vínculos escolhidos pela IA só viram regra do fornecedor depois que a entrada
 * é gerada (fiscal_xml_generate_entry), ou seja, depois da conferência.
 */

require_once __DIR__ . '/ia_module.php';
require_once __DIR__ . '/fiscal_operational.php';

const IA_ENTRADA_AGENT = 'entrada_notas';

if (!function_exists('ia_entrada_norm')) {
  function ia_entrada_norm(string $s): string {
    $s = mb_strtoupper(trim($s), 'UTF-8');
    $s = strtr($s, ['Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I','Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N']);
    return preg_replace('/\s+/', ' ', preg_replace('/[^A-Z0-9]+/', ' ', $s) ?? '') ?? '';
  }
}

if (!function_exists('ia_entrada_tokens')) {
  /** Palavras que ajudam a achar o produto (sem unidades e palavras genéricas). */
  function ia_entrada_tokens(string $desc): array {
    static $stop = ['COM','SEM','PARA','POR','DAS','DOS','UND','UNID','UNIDADE','CXA','CAIXA','PCT','PACOTE','FARDO','LITRO','LITROS','GRS','KGS','UNI','PCS','PECA','PECAS','TIPO','REF','COD','MOD','MODELO','NOVO','NOVA'];
    $out = [];
    foreach (explode(' ', ia_entrada_norm($desc)) as $w) {
      if (strlen($w) < 3 || in_array($w, $stop, true)) continue;
      if (ctype_digit($w) && strlen($w) < 4) continue;
      $out[$w] = strlen($w);
    }
    arsort($out);
    return array_slice(array_keys($out), 0, 6);
  }
}

if (!function_exists('ia_cfop_entrada')) {
  /**
   * CFOP de entrada padrão a partir do CFOP de saída do fornecedor (compra para revenda).
   * Devolve [cfop, certeza]: certeza=false quando só trocou o primeiro dígito.
   */
  function ia_cfop_entrada(string $cfop): array {
    $cfop = preg_replace('/\D+/', '', $cfop) ?? '';
    if (strlen($cfop) !== 4) return ['', false];
    $first = $cfop[0];
    if (in_array($first, ['1', '2', '3'], true)) return [$cfop, true];
    $prefix = ['5' => '1', '6' => '2', '7' => '3'][$first] ?? '';
    if ($prefix === '') return ['', false];
    $tail = substr($cfop, 1);
    $map = [
      '101' => '102', '102' => '102', '103' => '102', '104' => '102', '105' => '102', '106' => '102', '109' => '102', '110' => '102',
      '115' => '102', '116' => '102', '117' => '102', '118' => '102', '119' => '102', '120' => '102', '122' => '102', '123' => '102',
      '401' => '403', '402' => '403', '403' => '403', '405' => '403', '404' => '403',
      '910' => '910', '911' => '911', '949' => '949', '151' => '151', '152' => '152', '408' => '408', '409' => '409',
      '901' => '901', '902' => '902', '915' => '915', '916' => '916', '920' => '920', '921' => '921', '124' => '124', '125' => '125',
      '551' => '551', '552' => '552', '557' => '557',
    ];
    if (isset($map[$tail])) return [$prefix . $map[$tail], true];
    return [$prefix . $tail, false];
  }
}

if (!function_exists('ia_entrada_barcode_cols')) {
  function ia_entrada_barcode_cols(PDO $pdo): array {
    static $cols = null;
    if ($cols !== null) return $cols;
    return $cols = array_values(array_filter(['codigo_barras', 'ean', 'gtin', 'barcode'], static fn($c) => fiscal_column_exists($pdo, 'produtos', $c)));
  }
}

if (!function_exists('ia_entrada_product_select')) {
  function ia_entrada_product_select(PDO $pdo): string {
    $sel = 'p.id, p.nome, p.categoria, p.preco';
    foreach (['custo', 'unidade_estoque', 'ativo'] as $c) if (fiscal_column_exists($pdo, 'produtos', $c)) $sel .= ', p.' . $c;
    foreach (ia_entrada_barcode_cols($pdo) as $c) $sel .= ', p.`' . $c . '`';
    return $sel;
  }
}

if (!function_exists('ia_entrada_product_view')) {
  function ia_entrada_product_view(array $p): array {
    $ean = '';
    foreach (['codigo_barras', 'ean', 'gtin', 'barcode'] as $c) if (!empty($p[$c])) { $ean = (string)$p[$c]; break; }
    return [
      'id' => (int)$p['id'], 'nome' => (string)$p['nome'], 'grupo' => (string)($p['categoria'] ?? ''),
      'unidade' => (string)($p['unidade_estoque'] ?? ''), 'custo' => round((float)($p['custo'] ?? 0), 4),
      'preco' => round((float)($p['preco'] ?? 0), 2), 'ean' => $ean,
    ];
  }
}

if (!function_exists('ia_entrada_product')) {
  function ia_entrada_product(PDO $pdo, int $companyId, int $productId): ?array {
    $st = $pdo->prepare('SELECT ' . ia_entrada_product_select($pdo) . ' FROM produtos p WHERE p.company_id=? AND p.id=? LIMIT 1');
    $st->execute([$companyId, $productId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('ia_entrada_find_by_ean')) {
  /** Produto com o mesmo código de barras (só quando for um único produto). */
  function ia_entrada_find_by_ean(PDO $pdo, int $companyId, string $ean): ?array {
    $ean = preg_replace('/\D+/', '', $ean) ?? '';
    if (strlen($ean) < 8 || strlen($ean) > 14 || trim($ean, '0') === '') return null;
    $cols = ia_entrada_barcode_cols($pdo);
    if (!$cols) return null;
    $where = implode(' OR ', array_map(static fn($c) => 'p.`' . $c . '` = ?', $cols));
    $st = $pdo->prepare('SELECT ' . ia_entrada_product_select($pdo) . ' FROM produtos p WHERE p.company_id=? AND (' . $where . ') LIMIT 2');
    $st->execute(array_merge([$companyId], array_fill(0, count($cols), $ean)));
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    return count($rows) === 1 ? $rows[0] : null;
  }
}

if (!function_exists('ia_entrada_candidates')) {
  /** Produtos do cadastro com nome parecido com a descrição do item (até $limit). */
  function ia_entrada_candidates(PDO $pdo, int $companyId, string $desc, int $limit = 8): array {
    $tokens = ia_entrada_tokens($desc);
    if (!$tokens) return [];
    $parts = []; $params = [$companyId];
    foreach ($tokens as $t) { $parts[] = 'p.nome LIKE ?'; $params[] = '%' . $t . '%'; }
    $st = $pdo->prepare('SELECT ' . ia_entrada_product_select($pdo) . ' FROM produtos p WHERE p.company_id=? AND (' . implode(' OR ', $parts) . ') LIMIT 300');
    $st->execute($params);
    $scored = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $p) {
      $name = ' ' . ia_entrada_norm((string)$p['nome']) . ' ';
      $score = 0.0;
      foreach ($tokens as $i => $t) {
        if (str_contains($name, ' ' . $t . ' ')) $score += 2 - $i * 0.1;
        elseif (str_contains($name, $t)) $score += 1 - $i * 0.05;
      }
      if (isset($p['ativo']) && (int)$p['ativo'] !== 1) $score -= 0.5;
      if ($score > 0) $scored[] = [$score, $p];
    }
    usort($scored, static fn($a, $b) => $b[0] <=> $a[0]);
    return array_map(static fn($x) => $x[1], array_slice($scored, 0, $limit));
  }
}

if (!function_exists('ia_entrada_categories')) {
  function ia_entrada_categories(PDO $pdo, int $companyId): array {
    try {
      $st = $pdo->prepare("SELECT categoria, COUNT(*) n FROM produtos WHERE company_id=? AND COALESCE(categoria,'')<>'' GROUP BY categoria ORDER BY n DESC LIMIT 200");
      $st->execute([$companyId]);
      return array_map(static fn($r) => (string)$r['categoria'], $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('ia_entrada_median')) {
  function ia_entrada_median(array $v): float {
    sort($v);
    $n = count($v);
    if ($n === 0) return 0.0;
    return $n % 2 ? (float)$v[intdiv($n, 2)] : ((float)$v[$n / 2 - 1] + (float)$v[$n / 2]) / 2;
  }
}

if (!function_exists('ia_entrada_similar_markup')) {
  /**
   * Margem (markup sobre o custo, em %) de produtos parecidos que já têm custo e preço:
   * mesmo NCM; se não houver, mesmo grupo; depois produtos do mesmo fornecedor.
   * Devolve [markup %, de onde veio] ou [null, ''].
   */
  function ia_entrada_similar_markup(PDO $pdo, int $companyId, string $ncm, string $category, string $supplierDoc): array {
    if (!fiscal_column_exists($pdo, 'produtos', 'custo')) return [null, ''];
    $collect = static function (PDOStatement $st, array $params): array {
      $st->execute($params);
      $out = [];
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $c = (float)$r['custo']; $p = (float)$r['preco'];
        if ($c > 0 && $p > 0 && $p >= $c * 0.5 && $p <= $c * 11) $out[] = ($p / $c - 1) * 100;
      }
      return $out;
    };
    $ncm = preg_replace('/\D+/', '', $ncm) ?? '';
    if (strlen($ncm) >= 4 && fiscal_table_exists($pdo, 'fiscal_product_tax')) {
      $st = $pdo->prepare('SELECT p.custo, p.preco FROM produtos p INNER JOIN fiscal_product_tax t ON t.company_id=p.company_id AND t.product_id=p.id WHERE p.company_id=? AND t.ncm=? AND p.custo>0 AND p.preco>0 LIMIT 200');
      $v = $collect($st, [$companyId, $ncm]);
      if (count($v) >= 2) return [round(ia_entrada_median($v), 2), count($v) . ' produto(s) com o mesmo NCM ' . $ncm];
    }
    if ($category !== '') {
      $st = $pdo->prepare('SELECT p.custo, p.preco FROM produtos p WHERE p.company_id=? AND p.categoria=? AND p.custo>0 AND p.preco>0 LIMIT 200');
      $v = $collect($st, [$companyId, $category]);
      if (count($v) >= 2) return [round(ia_entrada_median($v), 2), count($v) . ' produto(s) do grupo ' . $category];
    }
    $supplierDoc = preg_replace('/\D+/', '', $supplierDoc) ?? '';
    if ($supplierDoc !== '' && fiscal_table_exists($pdo, 'fiscal_xml_product_links')) {
      $st = $pdo->prepare('SELECT DISTINCT p.id, p.custo, p.preco FROM fiscal_xml_product_links l INNER JOIN produtos p ON p.company_id=l.company_id AND p.id=l.product_id WHERE l.company_id=? AND l.supplier_doc=? AND p.custo>0 AND p.preco>0 LIMIT 200');
      $v = $collect($st, [$companyId, $supplierDoc]);
      if (count($v) >= 2) return [round(ia_entrada_median($v), 2), count($v) . ' produto(s) do mesmo fornecedor'];
    }
    return [null, ''];
  }
}

if (!function_exists('ia_entrada_tax_profile_guess')) {
  /** Tributação de saída mais usada em produtos do mesmo NCM (ou do mesmo grupo). */
  function ia_entrada_tax_profile_guess(PDO $pdo, int $companyId, string $ncm, string $category): int {
    if (!fiscal_table_exists($pdo, 'fiscal_product_tax') || !fiscal_column_exists($pdo, 'fiscal_product_tax', 'tax_profile_id')) return 0;
    try {
      $ncm = preg_replace('/\D+/', '', $ncm) ?? '';
      if ($ncm !== '') {
        $st = $pdo->prepare('SELECT tax_profile_id, COUNT(*) n FROM fiscal_product_tax WHERE company_id=? AND ncm=? AND tax_profile_id>0 GROUP BY tax_profile_id ORDER BY n DESC LIMIT 1');
        $st->execute([$companyId, $ncm]);
        if ($id = (int)$st->fetchColumn()) return $id;
      }
      if ($category !== '') {
        $st = $pdo->prepare('SELECT t.tax_profile_id, COUNT(*) n FROM fiscal_product_tax t INNER JOIN produtos p ON p.company_id=t.company_id AND p.id=t.product_id WHERE t.company_id=? AND p.categoria=? AND t.tax_profile_id>0 GROUP BY t.tax_profile_id ORDER BY n DESC LIMIT 1');
        $st->execute([$companyId, $category]);
        if ($id = (int)$st->fetchColumn()) return $id;
      }
    } catch (Throwable $e) {}
    return 0;
  }
}

if (!function_exists('ia_entrada_fmt')) {
  function ia_entrada_fmt(float $v, int $dec = 2): string {
    return number_format($v, $dec, ',', '.');
  }
  function ia_entrada_factor_txt(float $f): string {
    return rtrim(rtrim(number_format($f, 4, ',', ''), '0'), ',');
  }
}

if (!function_exists('ia_entrada_factor_check')) {
  /**
   * Confere o fator pelo custo: se com o fator atual o custo por unidade de estoque
   * fica ~N vezes o custo cadastrado, sugere o fator ajustado (só para N inteiro).
   * Devolve [fator, motivo] ou [null, ''].
   */
  function ia_entrada_factor_check(float $landed, float $qty, float $factor, float $currentCost): array {
    if ($landed <= 0 || $qty <= 0 || $factor <= 0 || $currentCost <= 0) return [null, ''];
    $unitCost = $landed / ($qty * $factor);
    $ratio = $unitCost / $currentCost;
    if ($ratio >= 1.8) {
      $n = (int)round($ratio);
      if ($n >= 2 && abs($ratio - $n) / $n <= 0.15) {
        return [$factor * $n, 'com fator ' . ia_entrada_factor_txt($factor) . ' o custo por unidade ficaria R$ ' . ia_entrada_fmt($unitCost) . ' (no cadastro: R$ ' . ia_entrada_fmt($currentCost) . ')'];
      }
    }
    return [null, ''];
  }
}

if (!function_exists('ia_entrada_ai_schema')) {
  function ia_entrada_ai_schema(): array {
    return [
      'type' => 'object', 'additionalProperties' => false, 'required' => ['items'],
      'properties' => ['items' => ['type' => 'array', 'items' => [
        'type' => 'object', 'additionalProperties' => false,
        'required' => ['n', 'action', 'product_id', 'confidence', 'factor', 'stock_unit', 'product_name', 'category', 'reason'],
        'properties' => [
          'n' => ['type' => 'integer'],
          'action' => ['type' => 'string', 'enum' => ['link', 'create', 'review']],
          'product_id' => ['type' => 'integer'],
          'confidence' => ['type' => 'integer'],
          'factor' => ['type' => 'number'],
          'stock_unit' => ['type' => 'string'],
          'product_name' => ['type' => 'string'],
          'category' => ['type' => 'string'],
          'reason' => ['type' => 'string'],
        ],
      ]]],
    ];
  }
}

if (!function_exists('ia_entrada_ai_system')) {
  function ia_entrada_ai_system(): string {
    return <<<'TXT'
Você é o assistente de compras de uma loja de varejo brasileira, dentro do sistema de gestão Nexa Próspero.
Recebe os itens de uma NF-e de compra e, para cada item, decide como dar entrada no estoque.

Para cada item "n" responda:
- action "link": é o mesmo produto de um dos "candidatos" (ou do "vinculado"). Informe product_id desse produto.
  Só use "link" quando for claramente o mesmo produto (mesma marca, mesmo tipo, mesmo tamanho/volume/sabor/cor).
  Produto parecido mas de tamanho, sabor, cor, voltagem ou marca diferente NÃO é o mesmo produto.
- action "create": nenhum candidato é o mesmo produto; o produto deve ser cadastrado. product_id = 0.
- action "review": há um candidato provável, mas você não tem certeza. Informe o product_id dele.
- confidence: 0 a 100, sua certeza na decisão.
- factor: quantas unidades de estoque vêm em 1 unidade da nota (uCom). Ex.: nota em "CX" com
  "C/12" ou "12X1L" na descrição → 12; nota em "FD" com 6 unidades → 6; se a nota já vem na mesma
  unidade do estoque → 1. Para "link" compare com a unidade e o custo do produto do cadastro:
  custo da nota por unidade dividido pelo fator deve ficar próximo do custo cadastrado.
  Para produto vendido a granel (KG, L, M) mantenha 1.
- stock_unit: unidade de estoque (UN, KG, L, M, CX, PC...). Para "link", a unidade do produto do cadastro.
- product_name: para "create", nome limpo para o cadastro (sem códigos do fornecedor, abreviações
  expandidas quando óbvias, em maiúsculas como na nota); para os demais, repita o nome do produto escolhido.
- category: para "create", um dos grupos existentes que melhor combine (copie exatamente); se nenhum
  servir, sugira um nome curto de grupo novo. Para os demais, o grupo do produto escolhido.
- reason: uma frase curta em português explicando a decisão.
Responda um objeto para cada item recebido, com o mesmo "n".
TXT;
  }
}

if (!function_exists('ia_entrada_run_get')) {
  function ia_entrada_run_get(PDO $pdo, int $companyId, string $refType, int $refId): ?array {
    if (!ia_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM ai_agent_runs WHERE company_id=? AND agent=? AND ref_type=? AND ref_id=? LIMIT 1');
    $st->execute([$companyId, IA_ENTRADA_AGENT, $refType, $refId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
  function ia_entrada_run_for_xml(PDO $pdo, int $companyId, int $xmlId): ?array {
    if (!ia_ready($pdo) || $xmlId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM ai_agent_runs WHERE company_id=? AND agent=? AND xml_import_id=? ORDER BY id DESC LIMIT 1');
    $st->execute([$companyId, IA_ENTRADA_AGENT, $xmlId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('ia_entrada_run_save')) {
  function ia_entrada_run_save(PDO $pdo, int $companyId, string $refType, int $refId, array $f): void {
    $cols = ['xml_import_id', 'status', 'trigger_type', 'user_id', 'title', 'summary', 'details_json', 'error_message', 'started_at', 'finished_at'];
    $f = array_intersect_key($f, array_flip($cols));
    $existing = ia_entrada_run_get($pdo, $companyId, $refType, $refId);
    if ($existing) {
      if (!$f) return;
      $sets = implode(', ', array_map(static fn($c) => $c . '=?', array_keys($f)));
      $pdo->prepare('UPDATE ai_agent_runs SET ' . $sets . ' WHERE id=? AND company_id=?')->execute(array_merge(array_values($f), [(int)$existing['id'], $companyId]));
      return;
    }
    $f = array_merge(['company_id' => $companyId, 'agent' => IA_ENTRADA_AGENT, 'ref_type' => $refType, 'ref_id' => $refId], $f);
    $pdo->prepare('INSERT INTO ai_agent_runs (' . implode(',', array_keys($f)) . ') VALUES (' . implode(',', array_fill(0, count($f), '?')) . ')')->execute(array_values($f));
  }
}

if (!function_exists('ia_entrada_prepare_xml')) {
  /**
   * Prepara os itens de uma nota já importada (fiscal_xml_imports). Devolve o
   * resultado por item e os totais. Não gera a entrada.
   */
  function ia_entrada_prepare_xml(PDO $pdo, int $companyId, int $xmlId, ?array $cfg = null): array {
    $cfg = $cfg ?? ia_config($pdo, $companyId);
    $xml = fiscal_xml_get($pdo, $companyId, $xmlId);
    if (!$xml) throw new RuntimeException('Nota de entrada não encontrada.');
    if ((string)($xml['status'] ?? '') === 'ENTRADA_GERADA') throw new RuntimeException('A entrada desta nota já foi gerada.');
    $supplierDoc = (string)($xml['supplier_doc'] ?? '');
    $items = fiscal_xml_items($pdo, $companyId, $xmlId);
    $rows = [];
    $askAi = [];

    foreach ($items as $it) {
      $itemId = (int)$it['id'];
      $qty = (float)($it['quantity'] ?? 0);
      $landed = fiscal_xml_item_landed_base_value((array)$it);
      $factor = fiscal_xml_parse_decimal($it['conversion_factor'] ?? 1, 1.0) ?: 1.0;
      $row = [
        'item_id' => $itemId, 'n' => (int)($it['item_number'] ?? 0), 'name' => (string)($it['name'] ?? ''),
        'code' => (string)($it['product_code'] ?? ''), 'ean' => (string)($it['ean'] ?? ''), 'unit' => (string)($it['unit'] ?? ''),
        'qty' => $qty, 'landed' => round($landed, 2), 'result' => 'revisar', 'product_id' => 0, 'product_name' => '',
        'confidence' => 0, 'factor_old' => $factor, 'factor' => $factor, 'notes' => [],
      ];

      // CFOP/CST de entrada: regra da empresa; sem regra, o padrão de compra para revenda.
      $payload = fiscal_xml_item_tax_payload($pdo, $companyId, $it);
      $entryCfop = (string)$payload['entry_cfop'];
      $row['cfop_from'] = (string)($it['cfop'] ?? '');
      if ((int)($payload['tax_conversion_id'] ?? 0) > 0) {
        $row['rule'] = (string)($payload['tax_conversion_name'] ?? ('#' . $payload['tax_conversion_id']));
      } else {
        [$std, $sure] = ia_cfop_entrada((string)($it['cfop'] ?? ''));
        if ($std !== '' && ($entryCfop === '' || $entryCfop === fiscal_cfop_digits($it['cfop'] ?? ''))) $entryCfop = $std;
        if (!$sure && $std !== '') $row['notes'][] = 'Confira o CFOP de entrada ' . $std . ' (não há regra para o CFOP ' . $row['cfop_from'] . ').';
      }
      $row['cfop_to'] = $entryCfop;
      $row['entry_tax'] = [
        'entry_cfop' => $entryCfop, 'entry_origem' => (string)$payload['entry_origem'], 'entry_icms_cst' => (string)$payload['entry_icms_cst'],
        'entry_icms_csosn' => (string)$payload['entry_icms_csosn'], 'entry_ipi_cst' => (string)$payload['entry_ipi_cst'],
        'entry_pis_cst' => (string)$payload['entry_pis_cst'], 'entry_cofins_cst' => (string)$payload['entry_cofins_cst'],
      ];
      $row['tax_conversion_id'] = (int)($payload['tax_conversion_id'] ?? 0);
      $row['tax_profile_id'] = (int)($payload['tax_profile_id'] ?? 0);

      $linkedId = (int)($it['product_id'] ?? 0);
      $linked = $linkedId > 0 ? ia_entrada_product($pdo, $companyId, $linkedId) : null;
      if ($linked) {
        $row['result'] = 'vinculo_salvo';
        $row['product_id'] = $linkedId;
        $row['product_name'] = (string)$linked['nome'];
        $row['confidence'] = 100;
        [$newFactor, $why] = ia_entrada_factor_check($landed, $qty, $factor, (float)($linked['custo'] ?? 0));
        if ($newFactor !== null) { $row['factor_hint'] = [$newFactor, $why]; $askAi[$itemId] = ['linked' => $linked, 'candidates' => []]; }
      } elseif ($byEan = ia_entrada_find_by_ean($pdo, $companyId, (string)($it['ean'] ?? ''))) {
        $row['result'] = 'ean';
        $row['product_id'] = (int)$byEan['id'];
        $row['product_name'] = (string)$byEan['nome'];
        $row['confidence'] = 100;
        $askAi[$itemId] = ['linked' => $byEan, 'candidates' => []]; // só para conferir o fator
      } else {
        $askAi[$itemId] = ['linked' => null, 'candidates' => ia_entrada_candidates($pdo, $companyId, (string)($it['name'] ?? ''))];
      }
      $rows[$itemId] = $row;
    }

    // IA: uma chamada por nota (em blocos de 40 itens).
    $aiUsed = false;
    $aiError = '';
    $aiAnswers = [];
    if ($askAi) {
      $reason = ia_unavailable_reason($pdo, $companyId, $cfg);
      if ($reason !== '') {
        $aiError = $reason;
      } else {
        $categories = ia_entrada_categories($pdo, $companyId);
        $itemsById = [];
        foreach ($items as $it) $itemsById[(int)$it['id']] = $it;
        foreach (array_chunk($askAi, 40, true) as $chunk) {
          $payloadItems = [];
          foreach ($chunk as $itemId => $ctx) {
            $it = $itemsById[$itemId];
            $q = (float)($it['quantity'] ?? 0);
            $payloadItems[] = [
              'n' => (int)$it['item_number'], 'codigo_fornecedor' => (string)($it['product_code'] ?? ''), 'ean' => (string)($it['ean'] ?? ''),
              'descricao' => (string)($it['name'] ?? ''), 'ncm' => (string)($it['ncm'] ?? ''), 'unidade_nota' => (string)($it['unit'] ?? ''),
              'quantidade' => $q, 'custo_unitario_nota' => $q > 0 ? round(fiscal_xml_item_landed_base_value((array)$it) / $q, 4) : 0,
              'fator_atual' => (float)($it['conversion_factor'] ?? 1) ?: 1,
              'vinculado' => $ctx['linked'] ? ia_entrada_product_view($ctx['linked']) : null,
              'candidatos' => array_map('ia_entrada_product_view', $ctx['candidates']),
            ];
          }
          $user = "Fornecedor: " . (string)($xml['supplier_name'] ?? '') . "\n"
            . "Grupos existentes: " . json_encode($categories, JSON_UNESCAPED_UNICODE) . "\n"
            . "Itens da nota:\n" . json_encode($payloadItems, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
          try {
            $res = ia_claude_json($pdo, $companyId, IA_ENTRADA_AGENT, ia_entrada_ai_system(), $user, ia_entrada_ai_schema(), [
              'cfg' => $cfg, 'ref' => 'xml:' . $xmlId, 'max_tokens' => 4000 + 250 * count($payloadItems),
            ]);
            $aiUsed = true;
            foreach ((array)($res['items'] ?? []) as $a) $aiAnswers[(int)($a['n'] ?? 0)] = $a;
          } catch (Throwable $e) {
            $aiError = $e->getMessage();
            break;
          }
        }
      }
    }

    $cats = null;
    foreach ($rows as $itemId => &$row) {
      $it = null;
      foreach ($items as $x) if ((int)$x['id'] === $itemId) { $it = $x; break; }
      $ctx = $askAi[$itemId] ?? null;
      $a = $aiAnswers[$row['n']] ?? null;
      $qty = $row['qty'];
      $landed = (float)$row['landed'];

      if ($ctx && $ctx['linked']) {
        // Vínculo salvo/EAN: a IA só ajuda a conferir o fator de conversão.
        $current = (float)($ctx['linked']['custo'] ?? 0);
        $hint = $row['factor_hint'] ?? null;
        $aiFactor = $a ? (float)($a['factor'] ?? 0) : 0.0;
        if ($hint && $aiFactor > 0 && abs($aiFactor - $hint[0]) < 0.001) {
          $row['factor'] = $hint[0];
          $row['notes'][] = 'Fator ajustado de ' . ia_entrada_factor_txt($row['factor_old']) . ' para ' . ia_entrada_factor_txt($hint[0]) . ': ' . $hint[1] . '.';
        } elseif ($hint) {
          $row['notes'][] = 'Confira o fator de conversão: ' . $hint[1] . '; o fator ' . ia_entrada_factor_txt($hint[0]) . ' deixaria o custo próximo do cadastro.';
          $row['attention'] = true;
        } elseif ($row['result'] === 'ean' && $aiFactor >= 1 && abs($aiFactor - $row['factor_old']) > 0.001) {
          $unitCost = $qty > 0 ? $landed / ($qty * $aiFactor) : 0;
          if ($current <= 0 || ($unitCost >= $current * 0.5 && $unitCost <= $current * 2)) {
            $row['factor'] = $aiFactor;
            $row['notes'][] = 'Fator ' . ia_entrada_factor_txt($aiFactor) . ' (' . (string)($a['reason'] ?? '') . ').';
          }
        }
        continue;
      }
      if (!$ctx) continue;

      if (!$a) {
        $row['result'] = 'revisar';
        $row['notes'][] = $aiError !== '' ? 'IA indisponível: ' . $aiError : 'A IA não respondeu este item.';
        if ($ctx['candidates']) $row['suggestion'] = ia_entrada_product_view($ctx['candidates'][0]);
        continue;
      }
      $candIds = array_map(static fn($p) => (int)$p['id'], $ctx['candidates']);
      $pid = (int)($a['product_id'] ?? 0);
      $conf = max(0, min(100, (int)($a['confidence'] ?? 0)));
      $row['confidence'] = $conf;
      $row['reason'] = (string)($a['reason'] ?? '');
      $aiFactor = (float)($a['factor'] ?? 1);
      if ($aiFactor <= 0) $aiFactor = 1.0;

      if (($a['action'] ?? '') === 'link' && $conf >= 80 && in_array($pid, $candIds, true)) {
        $prod = null;
        foreach ($ctx['candidates'] as $c) if ((int)$c['id'] === $pid) { $prod = $c; break; }
        $current = (float)($prod['custo'] ?? 0);
        $factor = $aiFactor;
        if ($current > 0 && $qty > 0) {
          $unitCost = $landed / ($qty * $factor);
          if ($unitCost < $current * 0.5 || $unitCost > $current * 2) {
            // Fator da IA não bate com o custo: tenta o fator pelo custo.
            [$byCost, $why] = ia_entrada_factor_check($landed, $qty, 1.0, $current);
            if ($byCost !== null) { $factor = $byCost; $row['notes'][] = 'Fator ' . ia_entrada_factor_txt($byCost) . ' pelo custo: ' . $why . '.'; }
            else { $factor = 1.0; $row['attention'] = true; $row['notes'][] = 'Custo da nota bem diferente do cadastro: confira o fator de conversão.'; }
          }
        }
        $row['result'] = 'ia';
        $row['product_id'] = $pid;
        $row['product_name'] = (string)($prod['nome'] ?? '');
        $row['factor'] = $factor;
        continue;
      }

      $createOk = ($a['action'] ?? '') === 'create' && $conf >= 70;
      if ($createOk && !empty($cfg['entrada']['create_products']) && $it) {
        $cats = $cats ?? ia_entrada_categories($pdo, $companyId);
        $category = trim((string)($a['category'] ?? ''));
        if ($category === '') $category = $cfg['entrada']['default_category'];
        foreach ($cats as $c) if (mb_strtolower($c) === mb_strtolower($category)) { $category = $c; break; }
        $name = trim((string)($a['product_name'] ?? '')) ?: (string)($it['name'] ?? '');
        $unit = strtoupper(substr(preg_replace('/[^A-Za-z0-9]/', '', (string)($a['stock_unit'] ?? '')) ?? '', 0, 6)) ?: (string)($it['unit'] ?? 'UN');
        $factor = $aiFactor;
        $stockQty = $qty * $factor;
        $unitCost = $stockQty > 0 ? round($landed / $stockQty, 4) : 0.0;
        [$markup, $basis] = ia_entrada_similar_markup($pdo, $companyId, (string)($it['ncm'] ?? ''), $category, $supplierDoc);
        if ($markup === null && (float)$cfg['entrada']['default_margin'] > 0) { $markup = (float)$cfg['entrada']['default_margin']; $basis = 'margem padrão da configuração'; }
        $price = $markup !== null && $unitCost > 0 ? round($unitCost * (1 + $markup / 100), 2) : 0.0;
        $taxProfile = ia_entrada_tax_profile_guess($pdo, $companyId, (string)($it['ncm'] ?? ''), $category);
        $overrides = ['name' => mb_substr($name, 0, 150), 'unit' => $unit, 'sale_price' => $price, 'fiscal_notes' => 'Cadastrado pelo agente de IA na entrada da NF-e ' . (string)($xml['number'] ?? '') . '.']
          + $row['entry_tax'];
        if ($taxProfile > 0) $overrides['tax_profile_id'] = $taxProfile;
        try {
          $newId = fiscal_xml_create_product_from_item($pdo, $companyId, $xmlId, $itemId, $category, $factor, $overrides);
        } catch (Throwable $e) {
          $row['result'] = 'revisar';
          $row['notes'][] = 'Não consegui cadastrar o produto novo: ' . $e->getMessage();
          continue;
        }
        $row['result'] = 'novo';
        $row['product_id'] = $newId;
        $row['product_name'] = $name;
        $row['factor'] = $factor;
        $row['created'] = ['category' => $category, 'unit' => $unit, 'cost' => $unitCost, 'price' => $price, 'markup' => $markup, 'basis' => $basis, 'tax_profile_id' => $taxProfile];
        if ($price <= 0) { $row['attention'] = true; $row['notes'][] = 'Produto novo sem preço de venda: informe o preço.'; }
        else $row['notes'][] = 'Produto novo #' . $newId . ' no grupo ' . $category . ', preço R$ ' . ia_entrada_fmt($price) . ' (margem ' . ia_entrada_fmt((float)$markup, 1) . '% de ' . $basis . ').';
        if ($taxProfile <= 0) $row['notes'][] = 'Defina a tributação de saída do produto novo.';
        continue;
      }

      $row['result'] = 'revisar';
      if ($pid > 0 && in_array($pid, $candIds, true)) {
        foreach ($ctx['candidates'] as $c) if ((int)$c['id'] === $pid) { $row['suggestion'] = ia_entrada_product_view($c); break; }
      }
      $row['notes'][] = ($a['action'] ?? '') === 'create'
        ? (!empty($cfg['entrada']['create_products']) ? 'A IA não tem certeza se é produto novo.' : 'Produto não encontrado no cadastro (cadastro automático desligado).')
        : 'Vincule manualmente: a IA não teve certeza suficiente.';
    }
    unset($row);

    // Grava o resultado nos itens da nota (produto, fator e CFOP/CST de entrada).
    $up = $pdo->prepare('UPDATE fiscal_xml_items SET product_id=?, conversion_factor=?, stock_quantity=?, stock_unit_cost=?, entry_cfop=?, entry_origem=?, entry_icms_cst=?, entry_icms_csosn=?, entry_ipi_cst=?, entry_pis_cst=?, entry_cofins_cst=?, linked_at=IF(? IS NULL, linked_at, NOW()) WHERE company_id=? AND xml_import_id=? AND id=?');
    foreach ($rows as $itemId => $row) {
      if ($row['result'] === 'novo') continue; // já gravado no cadastro do produto novo
      $pid = (int)$row['product_id'] > 0 ? (int)$row['product_id'] : null;
      $factor = (float)$row['factor'] > 0 ? (float)$row['factor'] : 1.0;
      $stockQty = $row['qty'] * $factor;
      $unitCost = $stockQty > 0 ? round((float)$row['landed'] / $stockQty, 4) : 0.0;
      $t = $row['entry_tax'];
      $up->execute([$pid, $factor, $stockQty, $unitCost, $t['entry_cfop'] ?: null, $t['entry_origem'] ?: null, $t['entry_icms_cst'] ?: null, $t['entry_icms_csosn'] ?: null, $t['entry_ipi_cst'] ?: null, $t['entry_pis_cst'] ?: null, $t['entry_cofins_cst'] ?: null, $pid, $companyId, $xmlId, $itemId]);
      // Código de barras igual é certeza: o vínculo já vale para as próximas notas.
      if ($row['result'] === 'ean' && $pid) {
        try { fiscal_xml_save_product_link($pdo, $companyId, $supplierDoc, $row['code'], $row['ean'], $pid, $factor, $xmlId, $itemId); } catch (Throwable $e) {}
      }
    }

    $count = ['total' => count($rows), 'vinculados' => 0, 'novos' => 0, 'revisar' => 0, 'atencao' => 0];
    foreach ($rows as $r) {
      if ($r['result'] === 'revisar') $count['revisar']++;
      elseif ($r['result'] === 'novo') { $count['novos']++; $count['vinculados']++; }
      else $count['vinculados']++;
      if (!empty($r['attention'])) $count['atencao']++;
    }
    foreach ($rows as &$r) unset($r['entry_tax'], $r['factor_hint']);
    unset($r);
    return ['items' => array_values($rows), 'count' => $count, 'ai_used' => $aiUsed, 'ai_error' => $aiError, 'model' => ia_model($cfg)];
  }
}

if (!function_exists('ia_entrada_summary')) {
  function ia_entrada_summary(array $res): string {
    $c = $res['count'];
    $parts = [$c['vinculados'] . ' de ' . $c['total'] . ' item(ns) prontos'];
    if ($c['novos']) $parts[] = $c['novos'] . ' produto(s) novo(s)';
    if ($c['revisar']) $parts[] = $c['revisar'] . ' para vincular';
    if ($c['atencao']) $parts[] = $c['atencao'] . ' para conferir';
    $s = implode(' · ', $parts);
    if (!$res['ai_used'] && $res['ai_error'] !== '') $s .= ' · IA: ' . $res['ai_error'];
    return mb_substr($s, 0, 500);
  }
}

if (!function_exists('ia_entrada_process_radar')) {
  /**
   * Prepara a entrada de uma nota do Radar. $trigger: 'auto' ou 'manual'.
   * Devolve a execução gravada (ai_agent_runs).
   */
  function ia_entrada_process_radar(PDO $pdo, int $companyId, int $radarId, string $trigger = 'manual', ?int $userId = null): array {
    if (!ia_ready($pdo)) throw new RuntimeException('Aplique a migração "Agentes de IA" no Atualizador do Banco.');
    $cfg = ia_config($pdo, $companyId);
    $radar = fiscal_radar_one($pdo, $companyId, $radarId);
    if (!$radar) throw new RuntimeException('Nota não encontrada no Radar.');
    $title = 'NF-e ' . (string)($radar['document_number'] ?? '') . ' · ' . mb_substr((string)($radar['issuer_name'] ?? ''), 0, 120);
    $prev = ia_entrada_run_get($pdo, $companyId, 'radar', $radarId);
    if ($prev && $prev['status'] === 'PROCESSANDO' && strtotime((string)$prev['started_at']) > time() - 600) {
      throw new RuntimeException('O agente já está preparando esta nota.');
    }
    ia_entrada_run_save($pdo, $companyId, 'radar', $radarId, ['status' => 'PROCESSANDO', 'trigger_type' => $trigger, 'user_id' => $userId, 'title' => $title, 'started_at' => date('Y-m-d H:i:s'), 'finished_at' => null, 'error_message' => null]);
    try {
      $xmlRaw = trim((string)($radar['xml_raw'] ?? ''));
      $kind = strtoupper((string)($radar['dfe_kind'] ?? ''));
      if ($xmlRaw === '' || in_array($kind, ['RESNFE', 'RESEVENTO', 'EVENTO'], true) || stripos($xmlRaw, '<NFe') === false) {
        throw new RuntimeException('A nota ainda não tem o XML completo (é preciso registrar a Ciência da Emissão).');
      }
      $key = fiscal_digits((string)($radar['access_key'] ?? ''));
      $xmlId = 0;
      if ($key !== '') {
        $st = $pdo->prepare('SELECT id, status FROM fiscal_xml_imports WHERE company_id=? AND access_key=? LIMIT 1');
        $st->execute([$companyId, $key]);
        if ($existing = $st->fetch(PDO::FETCH_ASSOC)) {
          if ((string)$existing['status'] === 'ENTRADA_GERADA') {
            ia_entrada_run_save($pdo, $companyId, 'radar', $radarId, ['status' => 'IGNORADA', 'xml_import_id' => (int)$existing['id'], 'summary' => 'A entrada desta nota já foi gerada.', 'finished_at' => date('Y-m-d H:i:s')]);
            return ia_entrada_run_get($pdo, $companyId, 'radar', $radarId) ?? [];
          }
          $xmlId = (int)$existing['id']; // já importada: não reimporta para não perder o que foi feito
        }
      }
      if ($xmlId <= 0) {
        $tmp = tempnam(sys_get_temp_dir(), 'ia_xml_');
        if ($tmp === false) throw new RuntimeException('Não foi possível preparar o XML temporário.');
        try {
          file_put_contents($tmp, $xmlRaw);
          $data = fiscal_xml_parse_file($tmp, (string)($radar['file_name'] ?? ('sefaz_' . $key . '.xml')));
        } finally {
          @unlink($tmp);
        }
        $identity = fiscal_company_report_identity($pdo, $companyId);
        $companyDoc = fiscal_xml_digits($identity['document'] ?? '');
        $buyerDoc = fiscal_xml_digits($data['buyer_doc'] ?? '');
        if ($companyDoc !== '' && $buyerDoc !== '' && $companyDoc !== $buyerDoc) throw new RuntimeException('O destinatário desta NF-e não é o CNPJ da empresa.');
        $xmlId = fiscal_xml_import_data($pdo, $companyId, $data);
        try { $pdo->prepare("UPDATE fiscal_xml_radar SET radar_status='ANALISADO', updated_at=NOW() WHERE company_id=? AND id=? AND radar_status='NOVO'")->execute([$companyId, $radarId]); } catch (Throwable $e) {}
      }
      ia_entrada_run_save($pdo, $companyId, 'radar', $radarId, ['xml_import_id' => $xmlId]);
      $res = ia_entrada_prepare_xml($pdo, $companyId, $xmlId, $cfg);
      $status = $res['count']['revisar'] > 0 || ($res['count']['atencao'] ?? 0) > 0 ? 'REVISAR' : 'PRONTA';
      ia_entrada_run_save($pdo, $companyId, 'radar', $radarId, [
        'status' => $status, 'summary' => ia_entrada_summary($res), 'details_json' => json_encode($res, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'finished_at' => date('Y-m-d H:i:s'),
      ]);
    } catch (Throwable $e) {
      ia_entrada_run_save($pdo, $companyId, 'radar', $radarId, ['status' => 'ERRO', 'error_message' => mb_substr($e->getMessage(), 0, 1000), 'summary' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => date('Y-m-d H:i:s')]);
    }
    return ia_entrada_run_get($pdo, $companyId, 'radar', $radarId) ?? [];
  }
}

if (!function_exists('ia_entrada_pending_radar')) {
  /** Notas do Radar com XML completo ainda não preparadas pelo agente. */
  function ia_entrada_pending_radar(PDO $pdo, int $companyId, ?string $since = null, int $limit = 20): array {
    if (!ia_ready($pdo) || !fiscal_table_exists($pdo, 'fiscal_xml_radar')) return [];
    $where = ["r.company_id=?", "r.direction='ENTRADA'", "r.model='55'", "r.radar_status IN ('NOVO','ANALISADO')", "r.xml_raw IS NOT NULL", "r.xml_raw LIKE '%<NFe%'"];
    $params = [$companyId];
    if (fiscal_column_exists($pdo, 'fiscal_xml_radar', 'dfe_kind')) $where[] = "(r.dfe_kind IS NULL OR r.dfe_kind='' OR r.dfe_kind IN ('PROCNFE','NFE'))";
    if ($since) { $where[] = 'r.created_at >= ?'; $params[] = $since; }
    $where[] = "NOT EXISTS (SELECT 1 FROM ai_agent_runs a WHERE a.company_id=r.company_id AND a.agent='" . IA_ENTRADA_AGENT . "' AND a.ref_type='radar' AND a.ref_id=r.id)";
    $where[] = "NOT EXISTS (SELECT 1 FROM fiscal_xml_imports x WHERE x.company_id=r.company_id AND x.access_key=r.access_key AND x.status='ENTRADA_GERADA')";
    $st = $pdo->prepare('SELECT r.id FROM fiscal_xml_radar r WHERE ' . implode(' AND ', $where) . ' ORDER BY r.id ASC LIMIT ' . max(1, min(100, $limit)));
    $st->execute($params);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('ia_entrada_auto_ciencia')) {
  /** Registra a Ciência da Emissão das notas só com resumo, para baixar o XML completo. */
  function ia_entrada_auto_ciencia(PDO $pdo, int $companyId, ?string $since, int $limit = 10): int {
    if (!fiscal_column_exists($pdo, 'fiscal_xml_radar', 'dfe_kind') || !fiscal_column_exists($pdo, 'fiscal_xml_radar', 'manifest_status')) return 0;
    $where = ["company_id=?", "direction='ENTRADA'", "dfe_kind='RESNFE'", "COALESCE(manifest_status,'')=''", "access_key IS NOT NULL"];
    $params = [$companyId];
    if ($since) { $where[] = 'created_at >= ?'; $params[] = $since; }
    $where[] = "(issue_datetime IS NULL OR issue_datetime >= ?)";
    $params[] = date('Y-m-d', strtotime('-90 days'));
    $st = $pdo->prepare('SELECT id FROM fiscal_xml_radar WHERE ' . implode(' AND ', $where) . ' ORDER BY id ASC LIMIT ' . max(1, $limit));
    $st->execute($params);
    $done = 0;
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $rid) {
      try { fiscal_dfe_manifest($pdo, $companyId, (int)$rid, 210210); $done++; } catch (Throwable $e) {}
    }
    return $done;
  }
}

if (!function_exists('ia_entrada_run_auto')) {
  /** Modo automático (chamado depois da busca do Radar): ciência + preparo das notas novas. */
  function ia_entrada_run_auto(PDO $pdo, int $companyId, int $limit = 20): array {
    $out = ['ciencia' => 0, 'notas' => 0, 'erros' => 0];
    $cfg = ia_config($pdo, $companyId);
    if (!$cfg['enabled'] || !$cfg['entrada']['enabled'] || $cfg['entrada']['mode'] !== 'auto') return $out;
    if (!ia_module_enabled($pdo, $companyId) || !ia_ready($pdo)) return $out;
    $since = $cfg['entrada']['since'] !== '' ? $cfg['entrada']['since'] : null;
    if ($cfg['entrada']['auto_ciencia']) $out['ciencia'] = ia_entrada_auto_ciencia($pdo, $companyId, $since);
    foreach (ia_entrada_pending_radar($pdo, $companyId, $since, $limit) as $radarId) {
      $run = ia_entrada_process_radar($pdo, $companyId, $radarId, 'auto');
      $out['notas']++;
      if (($run['status'] ?? '') === 'ERRO') $out['erros']++;
    }
    return $out;
  }
}

if (!function_exists('ia_entrada_result_label')) {
  function ia_entrada_result_label(string $r): array {
    return match ($r) {
      'vinculo_salvo' => ['Vínculo do fornecedor', 'ok'],
      'ean' => ['Código de barras', 'ok'],
      'ia' => ['Encontrado pela IA', 'ok'],
      'novo' => ['Produto novo', 'new'],
      default => ['Vincular', 'warn'],
    };
  }
}
