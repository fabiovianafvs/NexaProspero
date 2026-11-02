<?php
declare(strict_types=1);

require_once __DIR__ . '/fiscal_module.php';

if (!function_exists('fiscal_catalog_admin_definitions')) {
  function fiscal_catalog_admin_definitions(): array {
    return [
      'ncm' => [
        'label' => 'NCM',
        'description' => 'Nomenclatura Comum do Mercosul compartilhada por todas as empresas.',
        'extensions' => ['csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_ncms WHERE company_id=0 AND is_active=1",
        'min_replace' => 1000,
        'source_name' => 'Portal Único Siscomex',
        'source_url' => 'https://www.gov.br/siscomex/pt-br',
      ],
      'cest' => [
        'label' => 'CEST x NCM',
        'description' => 'Catálogo global CEST e vínculos com NCM. A incidência de ICMS/ST continua configurável por empresa e operação.',
        'extensions' => ['csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_cests WHERE company_id=0 AND is_active=1",
        'min_replace' => 100,
        'source_name' => 'CONFAZ — Convênio ICMS 142/18',
        'source_url' => 'https://www.confaz.fazenda.gov.br/legislacao/convenios/2018/CV142_18',
      ],
      'ibpt' => [
        'label' => 'IBPT por UF',
        'description' => 'Tabela global IBPT separada por UF, versão e vigência.',
        'extensions' => ['csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_ibpt_rates WHERE is_active=1",
        'min_replace' => 100,
        'requires_uf' => true,
        'source_name' => 'De Olho no Imposto — IBPT',
        'source_url' => 'https://deolhonoimposto.ibpt.org.br/',
      ],
      'ibscbs' => [
        'label' => 'Classificação IBS/CBS',
        'description' => 'cClassTrib, CST, vigência, reduções e permissões por documento fiscal.',
        'extensions' => ['json','csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_ibs_cbs_classifications WHERE company_id=0 AND is_active=1",
        'min_replace' => 10,
        'source_name' => 'Portal Nacional da NF-e',
        'source_url' => 'https://www.nfe.fazenda.gov.br/portal/consulta.aspx/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
      ],
      'cbs_rates' => [
        'label' => 'Alíquotas da CBS',
        'description' => 'Alíquotas da CBS por código e período de vigência.',
        'extensions' => ['csv','xlsx','json'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_cbs_rates WHERE company_id=0 AND is_active=1",
        'min_replace' => 1,
        'source_name' => 'Portal Nacional da NF-e',
        'source_url' => 'https://www.nfe.fazenda.gov.br/portal/consulta.aspx/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
      ],
      'ibs_uf_rates' => [
        'label' => 'Alíquotas IBS por UF',
        'description' => 'Alíquotas do IBS estadual por UF e vigência.',
        'extensions' => ['csv','xlsx','json'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_ibs_uf_rates WHERE company_id=0 AND is_active=1",
        'min_replace' => 1,
        'source_name' => 'Portal Nacional da NF-e / legislação vigente',
        'source_url' => 'https://www.nfe.fazenda.gov.br/portal/consulta.aspx/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
      ],
      'ibs_mun_rates' => [
        'label' => 'Alíquotas IBS por Município',
        'description' => 'Alíquotas do IBS municipal por código IBGE e vigência.',
        'extensions' => ['csv','xlsx','json'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_ibs_mun_rates WHERE company_id=0 AND is_active=1",
        'min_replace' => 1,
        'source_name' => 'Portal Nacional da NF-e / legislação vigente',
        'source_url' => 'https://www.nfe.fazenda.gov.br/portal/consulta.aspx/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
      ],
      'municipios' => [
        'label' => 'Municípios / IBGE',
        'description' => 'Municípios, códigos IBGE e UF para uso fiscal.',
        'extensions' => ['json','csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_cities WHERE company_id=0 AND is_active=1",
        'min_replace' => 100,
        'source_name' => 'Portal Nacional da NF-e / IBGE',
        'source_url' => 'https://www.nfe.fazenda.gov.br/portal/consulta.aspx/listaConteudo.aspx?tipoConteudo=%2FNJarYc9nus%3D',
      ],
      'ufs' => [
        'label' => 'Estados / UF',
        'description' => 'Estados, siglas e códigos IBGE.',
        'extensions' => ['json','csv','xlsx'],
        'count_sql' => "SELECT COUNT(*) FROM fiscal_states WHERE company_id=0 AND is_active=1",
        'min_replace' => 20,
        'source_name' => 'IBGE / Portal Nacional da NF-e',
        'source_url' => 'https://www.ibge.gov.br/explica/codigos-dos-municipios.php',
      ],
    ];
  }
}

if (!function_exists('fiscal_catalog_admin_ensure_schema')) {
  function fiscal_catalog_admin_ensure_schema(PDO $pdo, bool $includeFiscalSchema = false): void {
    static $jobSchemaDone = false;
    if ($includeFiscalSchema) {
      fiscal_ensure_schema($pdo);
      fiscal_ensure_aux_schema($pdo);
    }
    if ($jobSchemaDone) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS saas_fiscal_catalog_jobs (
      id BIGINT NOT NULL AUTO_INCREMENT,
      admin_user_id BIGINT NULL,
      catalog_type VARCHAR(40) NOT NULL,
      scope_uf CHAR(2) NULL,
      filename VARCHAR(190) NULL,
      file_extension VARCHAR(12) NULL,
      file_size BIGINT NOT NULL DEFAULT 0,
      source_name VARCHAR(190) NULL,
      source_url VARCHAR(500) NULL,
      source_hash CHAR(64) NULL,
      version VARCHAR(80) NULL,
      validity_start DATE NULL,
      validity_end DATE NULL,
      replace_active TINYINT(1) NOT NULL DEFAULT 0,
      rows_total INT NOT NULL DEFAULT 0,
      rows_valid INT NOT NULL DEFAULT 0,
      rows_imported INT NOT NULL DEFAULT 0,
      rows_invalid INT NOT NULL DEFAULT 0,
      status VARCHAR(30) NOT NULL DEFAULT 'PENDENTE',
      message TEXT NULL,
      error_message TEXT NULL,
      sample_json LONGTEXT NULL,
      started_at DATETIME NULL,
      finished_at DATETIME NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_saas_fiscal_catalog_jobs_type (catalog_type, created_at),
      KEY idx_saas_fiscal_catalog_jobs_status (status, created_at),
      KEY idx_saas_fiscal_catalog_jobs_hash (catalog_type, scope_uf, source_hash)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    $jobSchemaDone = true;
  }
}

if (!function_exists('fiscal_catalog_admin_normalize_header')) {
  function fiscal_catalog_admin_normalize_header($value): string {
    $text = fiscal_clean_utf8((string)$value);
    $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text);
    if (is_string($ascii) && $ascii !== '') $text = $ascii;
    return strtolower((string)preg_replace('/[^a-z0-9]+/i', '', $text));
  }
}

if (!function_exists('fiscal_catalog_admin_is_list')) {
  function fiscal_catalog_admin_is_list(array $array): bool {
    $i = 0;
    foreach ($array as $key => $_) {
      if ($key !== $i++) return false;
    }
    return true;
  }
}

if (!function_exists('fiscal_catalog_admin_pick')) {
  function fiscal_catalog_admin_pick(array $row, array $keys, $default = '') {
    foreach ($keys as $key) {
      $normalized = fiscal_catalog_admin_normalize_header($key);
      if (array_key_exists($normalized, $row) && $row[$normalized] !== '' && $row[$normalized] !== null) return $row[$normalized];
    }
    return $default;
  }
}

if (!function_exists('fiscal_catalog_admin_date')) {
  function fiscal_catalog_admin_date($value, ?string $fallback = null): ?string {
    $text = trim((string)$value);
    if ($text === '') return $fallback;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $text, $m)) return $m[1] . '-' . $m[2] . '-' . $m[3];
    if (preg_match('/^(\d{2})[\/.-](\d{2})[\/.-](\d{4})$/', $text, $m)) return $m[3] . '-' . $m[2] . '-' . $m[1];
    try {
      return (new DateTimeImmutable($text))->format('Y-m-d');
    } catch (Throwable $e) {
      return $fallback;
    }
  }
}

if (!function_exists('fiscal_catalog_admin_flag')) {
  function fiscal_catalog_admin_flag($value): int {
    if (is_bool($value)) return $value ? 1 : 0;
    if (is_numeric($value)) return (float)$value != 0.0 ? 1 : 0;
    return in_array(strtoupper(trim((string)$value)), ['1','S','SIM','Y','YES','TRUE','X','ATIVO','PERMITIDO'], true) ? 1 : 0;
  }
}

if (!function_exists('fiscal_catalog_admin_digits')) {
  function fiscal_catalog_admin_digits($value, int $max = 0, int $pad = 0): string {
    $digits = preg_replace('/\D+/', '', (string)$value) ?: '';
    if ($max > 0) $digits = substr($digits, 0, $max);
    if ($pad > 0 && $digits !== '' && strlen($digits) < $pad) $digits = str_pad($digits, $pad, '0', STR_PAD_LEFT);
    return $digits;
  }
}

if (!function_exists('fiscal_catalog_admin_excel_column_index')) {
  function fiscal_catalog_admin_excel_column_index(string $letters): int {
    $letters = strtoupper($letters);
    $value = 0;
    for ($i = 0, $len = strlen($letters); $i < $len; $i++) $value = ($value * 26) + (ord($letters[$i]) - 64);
    return max(0, $value - 1);
  }
}

if (!function_exists('fiscal_catalog_admin_xlsx_date_styles')) {
  function fiscal_catalog_admin_xlsx_date_styles(ZipArchive $zip): array {
    $stylesXml = $zip->getFromName('xl/styles.xml');
    if ($stylesXml === false) return [];
    $xml = @simplexml_load_string($stylesXml);
    if (!$xml) return [];
    $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $custom = [];
    $numFmts = $xml->xpath('//m:numFmts/m:numFmt') ?: [];
    foreach ($numFmts as $fmt) $custom[(int)$fmt['numFmtId']] = strtolower((string)$fmt['formatCode']);
    $dateStyles = [];
    $xfs = $xml->xpath('//m:cellXfs/m:xf') ?: [];
    foreach ($xfs as $idx => $xf) {
      $numFmtId = (int)$xf['numFmtId'];
      $isDate = in_array($numFmtId, [14,15,16,17,18,19,20,21,22,45,46,47], true);
      if (!$isDate && isset($custom[$numFmtId])) {
        $fmt = preg_replace('/\[[^\]]+\]/', '', $custom[$numFmtId]);
        $isDate = (bool)preg_match('/(^|[^a-z])[dmy]{1,4}([^a-z]|$)/i', (string)$fmt);
      }
      if ($isDate) $dateStyles[(int)$idx] = true;
    }
    return $dateStyles;
  }
}

if (!function_exists('fiscal_catalog_admin_xlsx_sheet_map')) {
  function fiscal_catalog_admin_xlsx_sheet_map(ZipArchive $zip): array {
    $workbookXml = $zip->getFromName('xl/workbook.xml');
    $relsXml = $zip->getFromName('xl/_rels/workbook.xml.rels');
    if ($workbookXml === false || $relsXml === false) return [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
    $wb = @simplexml_load_string($workbookXml);
    $rels = @simplexml_load_string($relsXml);
    if (!$wb || !$rels) return [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
    $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
    $wb->registerXPathNamespace('r', 'http://schemas.openxmlformats.org/officeDocument/2006/relationships');
    $relMap = [];
    $rels->registerXPathNamespace('pr', 'http://schemas.openxmlformats.org/package/2006/relationships');
    $relNodes = $rels->xpath('//pr:Relationship') ?: [];
    foreach ($relNodes as $rel) $relMap[(string)$rel['Id']] = (string)$rel['Target'];
    $out = [];
    $sheets = $wb->xpath('//m:sheets/m:sheet') ?: [];
    foreach ($sheets as $sheet) {
      $attrs = $sheet->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships');
      $rid = (string)($attrs['id'] ?? '');
      $target = $relMap[$rid] ?? '';
      if ($target === '') continue;
      if (strpos($target, '/') === 0) $target = ltrim($target, '/');
      elseif (strpos($target, 'xl/') !== 0) $target = 'xl/' . ltrim($target, '/');
      $out[] = ['name' => (string)$sheet['name'], 'target' => $target];
    }
    return $out ?: [['name' => 'Planilha 1', 'target' => 'xl/worksheets/sheet1.xml']];
  }
}

if (!function_exists('fiscal_catalog_admin_sheet_keywords')) {
  function fiscal_catalog_admin_sheet_keywords(string $type): array {
    $map = [
      'ncm' => ['ncm','nomenclatura'],
      'cest' => ['cest'],
      'ibpt' => ['ibpt','aliquota','tabela'],
      'ibscbs' => ['cclasstrib','classificacao','ibs','cbs'],
      'cbs_rates' => ['cbs','aliquota'],
      'ibs_uf_rates' => ['ibs','uf','estado'],
      'ibs_mun_rates' => ['ibs','municipio','ibge'],
      'municipios' => ['municipio','cidade'],
      'ufs' => ['uf','estado'],
    ];
    return $map[$type] ?? [];
  }
}

if (!function_exists('fiscal_catalog_admin_xlsx_matrix')) {
  function fiscal_catalog_admin_xlsx_matrix(string $path, string $type, string $preferredSheet = ''): array {
    if (!class_exists('ZipArchive')) throw new RuntimeException('A extensão PHP ZipArchive é necessária para importar arquivos XLSX. Instale php-zip ou envie CSV/JSON.');
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir o arquivo XLSX.');
    try {
      $shared = [];
      $sharedXml = $zip->getFromName('xl/sharedStrings.xml');
      if ($sharedXml !== false) {
        $sx = @simplexml_load_string($sharedXml);
        if ($sx) {
          $sx->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          $items = $sx->xpath('//m:si') ?: [];
          foreach ($items as $item) {
            $item->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
            $parts = $item->xpath('.//m:t') ?: [];
            $text = '';
            foreach ($parts as $part) $text .= (string)$part;
            $shared[] = fiscal_clean_utf8($text);
          }
        }
      }
      $dateStyles = fiscal_catalog_admin_xlsx_date_styles($zip);
      $sheets = fiscal_catalog_admin_xlsx_sheet_map($zip);
      $selected = $sheets[0];
      if ($preferredSheet !== '') {
        foreach ($sheets as $sheet) if (strcasecmp($sheet['name'], $preferredSheet) === 0) { $selected = $sheet; break; }
      } else {
        $keywords = fiscal_catalog_admin_sheet_keywords($type);
        $bestScore = -1;
        foreach ($sheets as $sheet) {
          $name = fiscal_catalog_admin_normalize_header($sheet['name']);
          $score = 0;
          foreach ($keywords as $kw) if (strpos($name, fiscal_catalog_admin_normalize_header($kw)) !== false) $score++;
          if ($score > $bestScore) { $bestScore = $score; $selected = $sheet; }
        }
      }
      $sheetXml = $zip->getFromName($selected['target']);
      if ($sheetXml === false) throw new RuntimeException('Não foi possível ler a planilha selecionada no XLSX.');
      $xml = @simplexml_load_string($sheetXml);
      if (!$xml) throw new RuntimeException('A planilha XLSX está inválida.');
      $xml->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
      $rows = [];
      $sheetRows = $xml->xpath('//m:sheetData/m:row') ?: [];
      foreach ($sheetRows as $rowNode) {
        $rowNode->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $row = [];
        $cells = $rowNode->xpath('./m:c') ?: [];
        foreach ($cells as $cell) {
          $cell->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
          $ref = (string)$cell['r'];
          preg_match('/^([A-Z]+)/i', $ref, $m);
          $col = fiscal_catalog_admin_excel_column_index($m[1] ?? 'A');
          $typeAttr = (string)$cell['t'];
          $style = (int)$cell['s'];
          $value = '';
          if ($typeAttr === 'inlineStr') {
            $parts = $cell->xpath('./m:is//m:t') ?: [];
            foreach ($parts as $part) $value .= (string)$part;
          } else {
            $v = $cell->xpath('./m:v');
            $raw = isset($v[0]) ? (string)$v[0] : '';
            if ($typeAttr === 's') $value = $shared[(int)$raw] ?? '';
            elseif ($typeAttr === 'b') $value = $raw === '1' ? '1' : '0';
            elseif ($raw !== '' && isset($dateStyles[$style]) && is_numeric($raw)) {
              $serial = (float)$raw;
              $base = new DateTimeImmutable('1899-12-30 00:00:00', new DateTimeZone('UTC'));
              $days = (int)floor($serial);
              $seconds = (int)round(($serial - $days) * 86400);
              $value = $base->modify('+' . $days . ' days')->modify('+' . $seconds . ' seconds')->format('Y-m-d');
            } else $value = $raw;
          }
          $row[$col] = fiscal_clean_utf8((string)$value);
        }
        if ($row) {
          ksort($row);
          $max = max(array_keys($row));
          $dense = [];
          for ($i = 0; $i <= $max; $i++) $dense[] = $row[$i] ?? '';
          $rows[] = $dense;
        }
        if (count($rows) > 250000) throw new RuntimeException('A planilha excede o limite de 250.000 linhas por importação.');
      }
      return ['rows' => $rows, 'sheet' => $selected['name'], 'sheets' => array_column($sheets, 'name')];
    } finally {
      $zip->close();
    }
  }
}

if (!function_exists('fiscal_catalog_admin_csv_matrix')) {
  function fiscal_catalog_admin_csv_matrix(string $path): array {
    $fh = fopen($path, 'rb');
    if (!$fh) throw new RuntimeException('Não foi possível abrir o arquivo CSV.');
    $first = fgets($fh);
    if ($first === false) { fclose($fh); throw new RuntimeException('O arquivo CSV está vazio.'); }
    $delimiters = [';' => substr_count($first, ';'), ',' => substr_count($first, ','), "\t" => substr_count($first, "\t")];
    arsort($delimiters);
    $delimiter = (string)array_key_first($delimiters);
    if (($delimiters[$delimiter] ?? 0) === 0) $delimiter = ';';
    rewind($fh);
    $rows = [];
    while (($row = fgetcsv($fh, 0, $delimiter)) !== false) {
      if (isset($row[0])) $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
      $row = fiscal_clean_csv_row($row);
      $nonEmpty = false;
      foreach ($row as $value) if (trim((string)$value) !== '') { $nonEmpty = true; break; }
      if ($nonEmpty) $rows[] = $row;
      if (count($rows) > 250000) { fclose($fh); throw new RuntimeException('O CSV excede o limite de 250.000 linhas por importação.'); }
    }
    fclose($fh);
    return ['rows' => $rows, 'sheet' => null, 'sheets' => []];
  }
}

if (!function_exists('fiscal_catalog_admin_json_rows')) {
  function fiscal_catalog_admin_json_rows(string $path, string $type): array {
    $raw = file_get_contents($path);
    if ($raw === false || trim($raw) === '') throw new RuntimeException('O arquivo JSON está vazio.');
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new RuntimeException('JSON inválido: ' . json_last_error_msg());
    $keysByType = [
      'ibscbs' => ['classificacaotributariaibscbs','classificacoes','TabelaClassificacaoTributaria','data','items'],
      'municipios' => ['municipios','cities','data','items'],
      'ufs' => ['ufestados','estados','states','data','items'],
      'cbs_rates' => ['aliquotas','rates','data','items'],
      'ibs_uf_rates' => ['aliquotas','rates','data','items'],
      'ibs_mun_rates' => ['aliquotas','rates','data','items'],
    ];
    $rows = null;
    foreach ($keysByType[$type] ?? ['data','items'] as $key) {
      if (isset($data[$key]) && is_array($data[$key])) { $rows = $data[$key]; break; }
    }
    if ($rows === null) $rows = $data;
    if (!is_array($rows)) throw new RuntimeException('O JSON não contém uma lista reconhecida.');
    $out = [];
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $normalized = [];
      foreach ($row as $key => $value) $normalized[fiscal_catalog_admin_normalize_header((string)$key)] = is_scalar($value) || $value === null ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);
      $out[] = $normalized;
    }
    return ['rows' => $out, 'sheet' => null, 'sheets' => []];
  }
}

if (!function_exists('fiscal_catalog_admin_header_alias_groups')) {
  function fiscal_catalog_admin_header_alias_groups(string $type): array {
    $commonDate = ['validitystart','vigenciainicio','iniciovigencia','inicio','dinivig'];
    switch ($type) {
      case 'ncm': return [['ncm','codigo','codigoncm','concm'], ['descricao','descricaoncm','description','texto','noncmpor']];
      case 'cest': return [['cest','codigo','codigocest'], ['ncm','ncmsh','codigoncm'], ['descricao','descricaocest','description','nome']];
      case 'ibpt': return [['codigo','ncm','codigoncm'], ['aliqnac','aliquotanacional','federalnacional'], ['aliqimp','federalimportado']];
      case 'ibscbs': return [['cclasstrib','classificacao','classificationcode','codigo'], ['nomecclasstrib','descricao','description','nome'], ['cstibscbs','cst','cstcode']];
      case 'cbs_rates': return [['rate','aliquota','percentual','pcbs'], ['code','codigo','cclasstrib','cst'], $commonDate];
      case 'ibs_uf_rates': return [['uf','sigla'], ['rate','aliquota','percentual','pibsuf'], $commonDate];
      case 'ibs_mun_rates': return [['ibgecode','ibge','codigoibge'], ['rate','aliquota','percentual','pibsmun'], $commonDate];
      case 'municipios': return [['codigoibge','ibgecode','ibge','codigo'], ['nomemunicipio','municipio','cidade','name','nome'], ['uf','sigla']];
      case 'ufs': return [['uf','sigla'], ['descricao','name','nome','estado'], ['codigoibge','ibgecode','codigo']];
      default: return [];
    }
  }
}

if (!function_exists('fiscal_catalog_admin_tabular_to_assoc')) {
  function fiscal_catalog_admin_tabular_to_assoc(array $matrix, string $type): array {
    if (!$matrix) return [];
    if (isset($matrix[0]) && is_array($matrix[0]) && !fiscal_catalog_admin_is_list($matrix[0])) {
      $out = [];
      foreach ($matrix as $row) {
        $normalized = [];
        foreach ($row as $key => $value) $normalized[fiscal_catalog_admin_normalize_header((string)$key)] = $value;
        $out[] = $normalized;
      }
      return $out;
    }
    $groups = fiscal_catalog_admin_header_alias_groups($type);
    $bestIndex = 0;
    $bestScore = -1;
    $limit = min(35, count($matrix));
    for ($i = 0; $i < $limit; $i++) {
      $headers = [];
      foreach ((array)$matrix[$i] as $cell) $headers[] = fiscal_catalog_admin_normalize_header($cell);
      $score = 0;
      foreach ($groups as $group) {
        foreach ($group as $alias) {
          if (in_array(fiscal_catalog_admin_normalize_header($alias), $headers, true)) { $score++; break; }
        }
      }
      if ($score > $bestScore) { $bestScore = $score; $bestIndex = $i; }
    }
    if ($bestScore <= 0) throw new RuntimeException('Não foi possível identificar a linha de cabeçalho. Confira o modelo esperado para o catálogo.');
    $headers = [];
    foreach ((array)$matrix[$bestIndex] as $idx => $header) {
      $name = fiscal_catalog_admin_normalize_header($header);
      if ($name === '') $name = 'coluna' . ($idx + 1);
      if (isset($headers[$idx])) continue;
      $headers[$idx] = $name;
    }
    $out = [];
    for ($i = $bestIndex + 1, $len = count($matrix); $i < $len; $i++) {
      $raw = (array)$matrix[$i];
      $assoc = [];
      $nonEmpty = false;
      foreach ($headers as $idx => $name) {
        $value = $raw[$idx] ?? '';
        if (trim((string)$value) !== '') $nonEmpty = true;
        $assoc[$name] = $value;
      }
      if ($nonEmpty) $out[] = $assoc;
    }
    return $out;
  }
}

if (!function_exists('fiscal_catalog_admin_load_rows')) {
  function fiscal_catalog_admin_load_rows(string $path, string $extension, string $type, string $preferredSheet = ''): array {
    $extension = strtolower($extension);
    if ($extension === 'json') return fiscal_catalog_admin_json_rows($path, $type);
    $loaded = $extension === 'xlsx'
      ? fiscal_catalog_admin_xlsx_matrix($path, $type, $preferredSheet)
      : fiscal_catalog_admin_csv_matrix($path);
    $loaded['rows'] = fiscal_catalog_admin_tabular_to_assoc($loaded['rows'], $type);
    return $loaded;
  }
}

if (!function_exists('fiscal_catalog_admin_normalize_row')) {
  function fiscal_catalog_admin_normalize_row(string $type, array $row, array $options): ?array {
    $startFallback = fiscal_catalog_admin_date($options['validity_start'] ?? null);
    $endFallback = fiscal_catalog_admin_date($options['validity_end'] ?? null);
    $versionFallback = trim((string)($options['version'] ?? ''));
    $sourceFallback = trim((string)($options['source_name'] ?? ''));

    if ($type === 'ncm') {
      $rawCode = preg_replace('/\D+/', '', (string)fiscal_catalog_admin_pick($row, ['ncm','codigo','codigoncm','co_ncm'])) ?: '';
      if (!in_array(strlen($rawCode), [7,8], true)) return null;
      $code = str_pad(substr($rawCode,0,8),8,'0',STR_PAD_LEFT);
      return ['key'=>$code,'ncm'=>$code,'description'=>trim((string)fiscal_catalog_admin_pick($row,['descricao','descricaoncm','description','texto','no_ncm_por']))];
    }
    if ($type === 'cest') {
      $rawCest = preg_replace('/\D+/', '', (string)fiscal_catalog_admin_pick($row,['cest','codigo','codigocest'])) ?: '';
      $rawNcm = preg_replace('/\D+/', '', (string)fiscal_catalog_admin_pick($row,['ncm','ncmsh','codigoncm'])) ?: '';
      if (!in_array(strlen($rawCest), [6,7], true) || !in_array(strlen($rawNcm), [7,8], true)) return null;
      $cest = str_pad(substr($rawCest,0,7),7,'0',STR_PAD_LEFT);
      $ncm = str_pad(substr($rawNcm,0,8),8,'0',STR_PAD_LEFT);
      return [
        'key'=>$cest.'|'.$ncm,'cest'=>$cest,'ncm'=>$ncm,
        'description'=>trim((string)fiscal_catalog_admin_pick($row,['descricao','descricaocest','description','nome'])),
        'segment_code'=>trim((string)fiscal_catalog_admin_pick($row,['codigosegmento','segmentocode','segmentocodigo','segmento'])),
        'segment_name'=>trim((string)fiscal_catalog_admin_pick($row,['nomesegmento','segmentonome','segmentodescricao','segmento'])),
        'validity_start'=>fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validitystart','vigenciainicio','inicio']),$startFallback),
        'validity_end'=>fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validityend','vigenciafim','fim']),$endFallback),
      ];
    }
    if ($type === 'ibpt') {
      $code = fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['codigo','ncm','codigoncm']), 12, 0);
      if ($code === '') return null;
      $codeType = strtoupper(trim((string)fiscal_catalog_admin_pick($row,['tipo','tipocodigo','codetype'],strlen($code)===8?'NCM':'NBS')));
      if (!in_array($codeType,['NCM','NBS'],true)) $codeType = strlen($code)===8?'NCM':'NBS';
      if ($codeType === 'NCM' && strlen($code) < 8) $code = str_pad($code,8,'0',STR_PAD_LEFT);
      $version = trim((string)fiscal_catalog_admin_pick($row,['versao','versaoibpt','version'],$versionFallback));
      $start = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['vigenciainicio','iniciovigencia','validitystart','inicio']),$startFallback ?: '1900-01-01');
      $end = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['vigenciafim','fimvigencia','validityend','fim']),$endFallback);
      $exCode = trim((string)fiscal_catalog_admin_pick($row,['ex','excecao','excode']));
      if ($exCode !== '' && preg_match('/^0+$/', $exCode)) $exCode = '';
      return [
        'key'=>$codeType.'|'.$code.'|'.$exCode.'|'.$version.'|'.($start ?: '1900-01-01'),
        'code_type'=>$codeType,'code'=>$code,'ex_code'=>$exCode,
        'description'=>trim((string)fiscal_catalog_admin_pick($row,['descricao','descricaoncm','description'])),
        'federal_national'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['aliqnac','aliquotanacional','nacionalfederal','federalnacional'],0)),
        'federal_imported'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['aliqimp','aliquotaimportado','importadosfederal','federalimportado'],0)),
        'state_rate'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['aliqestadual','estadual','staterate'],0)),
        'municipal_rate'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['aliqmunicipal','municipal','municipalrate'],0)),
        'version'=>$version,'validity_start'=>$start ?: '1900-01-01','validity_end'=>$end,
        'source'=>trim((string)fiscal_catalog_admin_pick($row,['fonte','origem','source'],$sourceFallback ?: 'IBPT')),
      ];
    }
    if ($type === 'ibscbs') {
      $code = fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['cClassTrib','classificacao','classification_code','codigo']), 6, 6);
      $name = trim((string)fiscal_catalog_admin_pick($row,['Nome cClassTrib','nomeClassTrib','nome','description','descricao','Descrição cClassTrib']));
      $detail = trim((string)fiscal_catalog_admin_pick($row,['Descricao cClassTrib','descricaoClassTrib','descricaoCompleta','detalhamento']));
      if ($detail !== '' && $detail !== $name) $name = $name !== '' ? ($name . ' - ' . $detail) : $detail;
      if ($code === '' || $name === '') return null;
      return [
        'key'=>$code,'classification_code'=>$code,'description'=>$name,
        'cst_code'=>substr(fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['CST-IBS/CBS','CST','cst','cst_code'],substr($code,0,3)),3,3),0,3),
        'rate_type'=>trim((string)fiscal_catalog_admin_pick($row,['Tipo de Alíquota','TipoAliquota','tipodealiquota','rate_type'])),
        'ibs_reduction_rate'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['pRedIBS','aliquotareducaoibs','ibs_reduction_rate'],0)),
        'cbs_reduction_rate'=>fiscal_decimal(fiscal_catalog_admin_pick($row,['pRedCBS','aliquotareducaocbs','cbs_reduction_rate'],0)),
        'validity_start'=>fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['dIniVig','validity_start','vigenciaInicial','inicio']),$startFallback),
        'validity_end'=>fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['dFimVig','validity_end','vigenciaFinal','fim']),$endFallback),
        'can_nfse'=>fiscal_catalog_admin_flag(fiscal_catalog_admin_pick($row,['indNFSe','podenfse','can_nfse'],0)),
        'can_nfe'=>fiscal_catalog_admin_flag(fiscal_catalog_admin_pick($row,['indNFe','podenfe','can_nfe'],0)),
        'can_nfce'=>fiscal_catalog_admin_flag(fiscal_catalog_admin_pick($row,['indNFCe','podenfce','can_nfce'],0)),
      ];
    }
    if ($type === 'cbs_rates') {
      $code = trim((string)fiscal_catalog_admin_pick($row,['code','codigo','cclasstrib','cst'],'PADRAO')) ?: 'PADRAO';
      $start = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validitystart','vigenciainicio','inicio','dinivig']),$startFallback);
      $end = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validityend','vigenciafim','fim','dfimvig']),$endFallback);
      $rateRaw = fiscal_catalog_admin_pick($row,['rate','aliquota','percentual','pcbs'],null);
      if ($rateRaw === null || trim((string)$rateRaw) === '') return null;
      return ['key'=>$code.'|'.($start ?: '').'|'.($end ?: ''),'code'=>$code,'validity_start'=>$start,'validity_end'=>$end,'rate'=>fiscal_decimal($rateRaw)];
    }
    if ($type === 'ibs_uf_rates') {
      $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)fiscal_catalog_admin_pick($row,['uf','sigla'])) ?: '',0,2));
      $rateRaw = fiscal_catalog_admin_pick($row,['rate','aliquota','percentual','pibsuf'],null);
      if ($uf === '' || $rateRaw === null || trim((string)$rateRaw) === '') return null;
      $start = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validitystart','vigenciainicio','inicio','dinivig']),$startFallback);
      $end = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validityend','vigenciafim','fim','dfimvig']),$endFallback);
      return ['key'=>$uf.'|'.($start ?: '').'|'.($end ?: ''),'uf'=>$uf,'validity_start'=>$start,'validity_end'=>$end,'rate'=>fiscal_decimal($rateRaw)];
    }
    if ($type === 'ibs_mun_rates') {
      $ibge = fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['ibgecode','ibge','codigoibge']),7,7);
      $rateRaw = fiscal_catalog_admin_pick($row,['rate','aliquota','percentual','pibsmun'],null);
      if ($ibge === '' || $rateRaw === null || trim((string)$rateRaw) === '') return null;
      $start = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validitystart','vigenciainicio','inicio','dinivig']),$startFallback);
      $end = fiscal_catalog_admin_date(fiscal_catalog_admin_pick($row,['validityend','vigenciafim','fim','dfimvig']),$endFallback);
      return [
        'key'=>$ibge.'|'.($start ?: '').'|'.($end ?: ''),'ibge_code'=>$ibge,
        'city_name'=>trim((string)fiscal_catalog_admin_pick($row,['cityname','municipio','cidade','nome'])),
        'uf'=>strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)fiscal_catalog_admin_pick($row,['uf','sigla'])) ?: '',0,2)),
        'validity_start'=>$start,'validity_end'=>$end,'rate'=>fiscal_decimal($rateRaw),
      ];
    }
    if ($type === 'municipios') {
      $ibge = fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['codigoibge','ibge_code','ibge','codigo']),7,7);
      $name = trim((string)fiscal_catalog_admin_pick($row,['nomemunicipio','municipio','cidade','name','nome']));
      $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)fiscal_catalog_admin_pick($row,['sigla','uf'])) ?: '',0,2));
      if ($ibge === '' || $name === '' || $uf === '') return null;
      return ['key'=>$ibge,'ibge_code'=>$ibge,'name'=>$name,'uf'=>$uf];
    }
    if ($type === 'ufs') {
      $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)fiscal_catalog_admin_pick($row,['sigla','uf'])) ?: '',0,2));
      $name = trim((string)fiscal_catalog_admin_pick($row,['descricao','name','nome','estado']));
      $ibge = fiscal_catalog_admin_digits(fiscal_catalog_admin_pick($row,['codigoibge','ibge_code','codigo']),2,2);
      if ($uf === '' || $name === '' || $ibge === '') return null;
      return [
        'key'=>$uf,'uf'=>$uf,'name'=>$name,'ibge_code'=>$ibge,
        'timezone_normal'=>trim((string)fiscal_catalog_admin_pick($row,['fusohorarionormal','timezonenormal'])),
        'timezone_dst'=>trim((string)fiscal_catalog_admin_pick($row,['fusohorarioverao','timezonedst'])),
      ];
    }
    return null;
  }
}

if (!function_exists('fiscal_catalog_admin_prepare_rows')) {
  function fiscal_catalog_admin_prepare_rows(string $type, array $rows, array $options): array {
    $valid = [];
    $invalid = 0;
    $total = 0;
    foreach ($rows as $row) {
      if (!is_array($row)) continue;
      $total++;
      $normalized = fiscal_catalog_admin_normalize_row($type, $row, $options);
      if ($normalized === null) { $invalid++; continue; }
      $valid[(string)$normalized['key']] = $normalized;
    }
    $validRows = array_values($valid);
    return ['total'=>$total,'valid'=>$validRows,'valid_count'=>count($validRows),'invalid'=>$invalid,'sample'=>array_slice($validRows,0,5)];
  }
}

if (!function_exists('fiscal_catalog_admin_deactivate_scope')) {
  function fiscal_catalog_admin_deactivate_scope(PDO $pdo, string $type, array $options): void {
    switch ($type) {
      case 'ncm': $pdo->exec("UPDATE fiscal_ncms SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'cest': $pdo->exec("UPDATE fiscal_cests SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'ibpt':
        $st=$pdo->prepare("UPDATE fiscal_ibpt_rates SET is_active=0,updated_at=NOW() WHERE uf=?");
        $st->execute([$options['uf']]);
        break;
      case 'ibscbs': $pdo->exec("UPDATE fiscal_ibs_cbs_classifications SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'cbs_rates': $pdo->exec("UPDATE fiscal_cbs_rates SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'ibs_uf_rates': $pdo->exec("UPDATE fiscal_ibs_uf_rates SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'ibs_mun_rates': $pdo->exec("UPDATE fiscal_ibs_mun_rates SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'municipios': $pdo->exec("UPDATE fiscal_cities SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
      case 'ufs': $pdo->exec("UPDATE fiscal_states SET is_active=0,updated_at=NOW() WHERE company_id=0"); break;
    }
  }
}

if (!function_exists('fiscal_catalog_admin_import_prepared')) {
  function fiscal_catalog_admin_import_prepared(PDO $pdo, string $type, array $rows, array $options, string $hash): int {
    $imported = 0;
    if ($type === 'ncm') {
      $st=$pdo->prepare("INSERT INTO fiscal_ncms (company_id,ncm,description,is_active,source,updated_at) VALUES (0,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),is_active=1,source=VALUES(source),updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['ncm'],$r['description'],$options['source_name']]);$imported++;}
    } elseif ($type === 'cest') {
      $st=$pdo->prepare("INSERT INTO fiscal_cests (company_id,cest,ncm,description,segment_code,segment_name,validity_start,validity_end,is_active,updated_at) VALUES (0,?,?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),segment_code=VALUES(segment_code),segment_name=VALUES(segment_name),validity_start=VALUES(validity_start),validity_end=VALUES(validity_end),is_active=1,updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['cest'],$r['ncm'],$r['description'],$r['segment_code'],$r['segment_name'],$r['validity_start'],$r['validity_end']]);$imported++;}
    } elseif ($type === 'ibpt') {
      $st=$pdo->prepare("INSERT INTO fiscal_ibpt_rates (uf,code_type,code,ex_code,description,federal_national,federal_imported,state_rate,municipal_rate,version,validity_start,validity_end,source,source_hash,is_active,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),federal_national=VALUES(federal_national),federal_imported=VALUES(federal_imported),state_rate=VALUES(state_rate),municipal_rate=VALUES(municipal_rate),validity_end=VALUES(validity_end),source=VALUES(source),source_hash=VALUES(source_hash),is_active=1,updated_at=NOW()");
      $ncm=$pdo->prepare("INSERT INTO fiscal_ncms (company_id,ncm,description,is_active,source,updated_at) VALUES (0,?,?,1,'IBPT',NOW()) ON DUPLICATE KEY UPDATE description=IF(COALESCE(description,'')='',VALUES(description),description),is_active=1,updated_at=NOW()");
      foreach($rows as $r){$st->execute([$options['uf'],$r['code_type'],$r['code'],$r['ex_code'],$r['description'],$r['federal_national'],$r['federal_imported'],$r['state_rate'],$r['municipal_rate'],$r['version'],$r['validity_start'],$r['validity_end'],$r['source'],$hash]);if($r['code_type']==='NCM'&&strlen($r['code'])===8)$ncm->execute([$r['code'],$r['description']]);$imported++;}
    } elseif ($type === 'ibscbs') {
      $st=$pdo->prepare("INSERT INTO fiscal_ibs_cbs_classifications (company_id,classification_code,description,cst_code,rate_type,ibs_reduction_rate,cbs_reduction_rate,validity_start,validity_end,can_nfse,can_nfe,can_nfce,is_active,notes,updated_at) VALUES (0,?,?,?,?,?,?,?,?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE description=VALUES(description),cst_code=VALUES(cst_code),rate_type=VALUES(rate_type),ibs_reduction_rate=VALUES(ibs_reduction_rate),cbs_reduction_rate=VALUES(cbs_reduction_rate),validity_start=VALUES(validity_start),validity_end=VALUES(validity_end),can_nfse=VALUES(can_nfse),can_nfe=VALUES(can_nfe),can_nfce=VALUES(can_nfce),is_active=1,notes=VALUES(notes),updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['classification_code'],$r['description'],$r['cst_code'],$r['rate_type'],$r['ibs_reduction_rate'],$r['cbs_reduction_rate'],$r['validity_start'],$r['validity_end'],$r['can_nfse'],$r['can_nfe'],$r['can_nfce'],'Importado pela Central SaaS']);$imported++;}
    } elseif ($type === 'cbs_rates') {
      $st=$pdo->prepare("INSERT INTO fiscal_cbs_rates (company_id,code,validity_start,validity_end,rate,is_active,notes,updated_at) VALUES (0,?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['code'],$r['validity_start'],$r['validity_end'],$r['rate'],'Importado pela Central SaaS']);$imported++;}
    } elseif ($type === 'ibs_uf_rates') {
      $st=$pdo->prepare("INSERT INTO fiscal_ibs_uf_rates (company_id,uf,validity_start,validity_end,rate,is_active,notes,updated_at) VALUES (0,?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['uf'],$r['validity_start'],$r['validity_end'],$r['rate'],'Importado pela Central SaaS']);$imported++;}
    } elseif ($type === 'ibs_mun_rates') {
      $st=$pdo->prepare("INSERT INTO fiscal_ibs_mun_rates (company_id,ibge_code,city_name,uf,validity_start,validity_end,rate,is_active,notes,updated_at) VALUES (0,?,?,?,?,?,?,1,?,NOW()) ON DUPLICATE KEY UPDATE city_name=VALUES(city_name),uf=VALUES(uf),rate=VALUES(rate),is_active=1,notes=VALUES(notes),updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['ibge_code'],$r['city_name'],$r['uf']?:null,$r['validity_start'],$r['validity_end'],$r['rate'],'Importado pela Central SaaS']);$imported++;}
    } elseif ($type === 'municipios') {
      $st=$pdo->prepare("INSERT INTO fiscal_cities (company_id,ibge_code,name,uf,is_active,updated_at) VALUES (0,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE name=VALUES(name),uf=VALUES(uf),is_active=1,updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['ibge_code'],$r['name'],$r['uf']]);$imported++;}
    } elseif ($type === 'ufs') {
      $st=$pdo->prepare("INSERT INTO fiscal_states (company_id,ibge_code,name,uf,timezone_normal,timezone_dst,is_active,updated_at) VALUES (0,?,?,?,?,?,1,NOW()) ON DUPLICATE KEY UPDATE ibge_code=VALUES(ibge_code),name=VALUES(name),timezone_normal=VALUES(timezone_normal),timezone_dst=VALUES(timezone_dst),is_active=1,updated_at=NOW()");
      foreach($rows as $r){$st->execute([$r['ibge_code'],$r['name'],$r['uf'],$r['timezone_normal'],$r['timezone_dst']]);$imported++;}
    }
    return $imported;
  }
}

if (!function_exists('fiscal_catalog_admin_insert_catalog_log')) {
  function fiscal_catalog_admin_insert_catalog_log(PDO $pdo, string $type, array $options, string $filename, string $hash, array $stats): void {
    $typeMap=['ncm'=>'NCM','cest'=>'CEST','ibpt'=>'IBPT','ibscbs'=>'IBSCBS','cbs_rates'=>'CBS-RATES','ibs_uf_rates'=>'IBS-UF-RATES','ibs_mun_rates'=>'IBS-MUN-RATES','municipios'=>'MUNICIPIOS','ufs'=>'UFS'];
    $st=$pdo->prepare("INSERT INTO fiscal_catalog_imports (catalog_type,uf,version,filename,source,source_hash,validity_start,validity_end,rows_total,rows_imported,status,notes) VALUES (?,?,?,?,?,?,?,?,?,?,'CONCLUIDO',?)");
    $st->execute([$typeMap[$type]??strtoupper($type),$options['uf']?:null,$options['version']?:null,$filename,$options['source_name'],$hash,$options['validity_start']?:null,$options['validity_end']?:null,$stats['total'],$stats['imported'],'Importação realizada pela Central SaaS'.(!empty($options['replace_active'])?' em modo sincronização completa.':' em modo mesclar/atualizar.')]);
    if ($type === 'ibpt') {
      $st2=$pdo->prepare("INSERT INTO fiscal_ibpt_imports (company_id,uf,filename,version,validity_start,validity_end,rows_total,rows_imported,status,notes,source_hash) VALUES (0,?,?,?,?,?,?,?,'CONCLUIDO','Importação realizada pela Central SaaS',?)");
      $st2->execute([$options['uf'],$filename,$options['version']?:null,$options['validity_start']?:null,$options['validity_end']?:null,$stats['total'],$stats['imported'],$hash]);
    }
  }
}

if (!function_exists('fiscal_catalog_admin_import_upload')) {
  function fiscal_catalog_admin_import_upload(PDO $pdo, array $upload, string $type, array $options, int $adminUserId): array {
    fiscal_catalog_admin_ensure_schema($pdo);
    $defs = fiscal_catalog_admin_definitions();
    if (!isset($defs[$type])) throw new RuntimeException('Catálogo inválido.');
    $def = $defs[$type];
    if (($upload['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
      $code=(int)($upload['error']??UPLOAD_ERR_NO_FILE);
      throw new RuntimeException($code===UPLOAD_ERR_INI_SIZE||$code===UPLOAD_ERR_FORM_SIZE?'O arquivo excede o limite de upload do PHP.':'Selecione um arquivo válido para atualização.');
    }
    $size=(int)($upload['size']??0);
    if ($size<=0) throw new RuntimeException('O arquivo enviado está vazio.');
    if ($size>50*1024*1024) throw new RuntimeException('O arquivo excede o limite de 50 MB desta tela.');
    $filename=fiscal_clean_utf8(basename((string)($upload['name']??'catalogo')));
    $extension=strtolower(pathinfo($filename,PATHINFO_EXTENSION));
    if (!in_array($extension,$def['extensions'],true)) throw new RuntimeException('Formato não permitido para este catálogo. Use: '.strtoupper(implode(', ',$def['extensions'])).'.');
    $tmp=(string)($upload['tmp_name']??'');
    if ($tmp===''||!is_uploaded_file($tmp)) throw new RuntimeException('O upload temporário não é válido.');
    $uf=strtoupper(substr(preg_replace('/[^A-Za-z]/','',(string)($options['uf']??''))?:'',0,2));
    if (!empty($def['requires_uf'])&&strlen($uf)!==2) throw new RuntimeException('Informe a UF da tabela IBPT.');
    $options['uf']=$uf;
    $options['source_name']=trim((string)($options['source_name']??$def['source_name']))?:$def['source_name'];
    $options['source_url']=trim((string)($options['source_url']??$def['source_url']))?:$def['source_url'];
    $options['version']=substr(trim((string)($options['version']??'')),0,80);
    $options['validity_start']=fiscal_catalog_admin_date($options['validity_start']??null);
    $options['validity_end']=fiscal_catalog_admin_date($options['validity_end']??null);
    $options['replace_active']=!empty($options['replace_active']);
    $options['force_duplicate']=!empty($options['force_duplicate']);
    $options['preferred_sheet']=trim((string)($options['preferred_sheet']??''));
    $hash=hash_file('sha256',$tmp);
    if (!$hash) throw new RuntimeException('Não foi possível calcular o hash do arquivo.');
    if (!$options['force_duplicate']) {
      $dup=$pdo->prepare("SELECT id,created_at FROM saas_fiscal_catalog_jobs WHERE catalog_type=? AND COALESCE(scope_uf,'')=? AND source_hash=? AND status='CONCLUIDO' ORDER BY id DESC LIMIT 1");
      $dup->execute([$type,$uf,$hash]);
      $existing=$dup->fetch(PDO::FETCH_ASSOC);
      if ($existing) throw new RuntimeException('Este mesmo arquivo já foi importado em '.($existing['created_at']??'data anterior').'. Marque “Permitir reimportação do mesmo arquivo” apenas quando necessário.');
    }
    $job=$pdo->prepare("INSERT INTO saas_fiscal_catalog_jobs (admin_user_id,catalog_type,scope_uf,filename,file_extension,file_size,source_name,source_url,source_hash,version,validity_start,validity_end,replace_active,status,started_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,'PROCESSANDO',NOW())");
    $job->execute([$adminUserId,$type,$uf?:null,$filename,$extension,$size,$options['source_name'],$options['source_url'],$hash,$options['version']?:null,$options['validity_start'],$options['validity_end'],$options['replace_active']?1:0]);
    $jobId=(int)$pdo->lastInsertId();
    $lockName='nexa_catalog_'.preg_replace('/[^a-z0-9_]/','',$type).'_'.($uf?:'global');
    $locked=false;
    try {
      $lock=$pdo->prepare('SELECT GET_LOCK(?,10)');$lock->execute([$lockName]);$locked=(int)$lock->fetchColumn()===1;
      if(!$locked) throw new RuntimeException('Outro administrador está atualizando este catálogo. Tente novamente em alguns instantes.');
      $loaded=fiscal_catalog_admin_load_rows($tmp,$extension,$type,$options['preferred_sheet']);
      $prepared=fiscal_catalog_admin_prepare_rows($type,$loaded['rows'],$options);
      if($prepared['valid_count']<=0) throw new RuntimeException('Nenhum registro válido foi reconhecido no arquivo. Baixe o modelo e confira os nomes das colunas.');
      if($options['replace_active']&&$prepared['valid_count']<(int)$def['min_replace']) throw new RuntimeException('A sincronização completa foi bloqueada porque o arquivo possui apenas '.$prepared['valid_count'].' registros válidos. O mínimo de segurança para '.$def['label'].' é '.$def['min_replace'].'. Use o modo mesclar ou confira o arquivo completo.');
      $pdo->beginTransaction();
      if($options['replace_active']) fiscal_catalog_admin_deactivate_scope($pdo,$type,$options);
      $imported=fiscal_catalog_admin_import_prepared($pdo,$type,$prepared['valid'],$options,$hash);
      fiscal_catalog_admin_insert_catalog_log($pdo,$type,$options,$filename,$hash,['total'=>$prepared['total'],'imported'=>$imported]);
      $pdo->commit();
      $message=$def['label'].' atualizado com sucesso: '.$imported.' registro(s) importado(s).';
      if($prepared['invalid']>0)$message.=' '.$prepared['invalid'].' linha(s) foram ignoradas por falta de dados obrigatórios.';
      $upd=$pdo->prepare("UPDATE saas_fiscal_catalog_jobs SET rows_total=?,rows_valid=?,rows_imported=?,rows_invalid=?,status='CONCLUIDO',message=?,sample_json=?,finished_at=NOW() WHERE id=?");
      $upd->execute([$prepared['total'],$prepared['valid_count'],$imported,$prepared['invalid'],$message,json_encode(['sheet'=>$loaded['sheet'],'available_sheets'=>$loaded['sheets'],'rows'=>$prepared['sample']],JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),$jobId]);
      return ['ok'=>true,'message'=>$message,'job_id'=>$jobId,'total'=>$prepared['total'],'valid'=>$prepared['valid_count'],'imported'=>$imported,'invalid'=>$prepared['invalid'],'sheet'=>$loaded['sheet']];
    } catch(Throwable $e) {
      if($pdo->inTransaction())$pdo->rollBack();
      $upd=$pdo->prepare("UPDATE saas_fiscal_catalog_jobs SET status='ERRO',error_message=?,finished_at=NOW() WHERE id=?");
      $upd->execute([substr($e->getMessage(),0,65000),$jobId]);
      throw $e;
    } finally {
      if($locked){try{$rel=$pdo->prepare('SELECT RELEASE_LOCK(?)');$rel->execute([$lockName]);}catch(Throwable $e){}}
    }
  }
}

if (!function_exists('fiscal_catalog_admin_status')) {
  function fiscal_catalog_admin_status(PDO $pdo): array {
    fiscal_catalog_admin_ensure_schema($pdo);
    $out=[];
    foreach(fiscal_catalog_admin_definitions() as $type=>$def){
      $count=0;try{$count=(int)$pdo->query($def['count_sql'])->fetchColumn();}catch(Throwable $e){}
      $st=$pdo->prepare("SELECT * FROM saas_fiscal_catalog_jobs WHERE catalog_type=? AND status='CONCLUIDO' ORDER BY id DESC LIMIT 1");$st->execute([$type]);$last=$st->fetch(PDO::FETCH_ASSOC)?:null;
      $out[$type]=['count'=>$count,'last'=>$last]+$def;
    }
    return $out;
  }
}

if (!function_exists('fiscal_catalog_admin_jobs')) {
  function fiscal_catalog_admin_jobs(PDO $pdo, int $limit=50): array {
    fiscal_catalog_admin_ensure_schema($pdo);
    $limit=max(1,min(200,$limit));
    return $pdo->query("SELECT j.*,u.name AS admin_name,u.email AS admin_email FROM saas_fiscal_catalog_jobs j LEFT JOIN users u ON u.id=j.admin_user_id ORDER BY j.id DESC LIMIT {$limit}")->fetchAll(PDO::FETCH_ASSOC)?:[];
  }
}

if (!function_exists('fiscal_catalog_admin_template')) {
  function fiscal_catalog_admin_template(string $type): array {
    $templates=[
      'ncm'=>['filename'=>'modelo_ncm.csv','content'=>"NCM;Descricao\n22021000;Águas, incluindo águas minerais e águas gaseificadas\n"],
      'cest'=>['filename'=>'modelo_cest.csv','content'=>"CEST;NCM;Descricao;CodigoSegmento;NomeSegmento;VigenciaInicio;VigenciaFim\n0300700;22021000;Águas minerais ou gaseificadas;03;Bebidas;01/01/2026;\n"],
      'ibpt'=>['filename'=>'modelo_ibpt.csv','content'=>"Codigo;Descricao;AliqNac;AliqImp;AliqEstadual;AliqMunicipal;VigenciaInicio;VigenciaFim;Versao;Fonte;Tipo;Ex\n22021000;Águas;12,34;18,45;17,00;0,00;20/06/2026;31/07/2026;26.1.L;IBPT;NCM;\n"],
      'ibscbs'=>['filename'=>'modelo_ibscbs.csv','content'=>"cClassTrib;Nome cClassTrib;CST-IBS/CBS;Tipo de Aliquota;pRedIBS;pRedCBS;dIniVig;dFimVig;indNFSe;indNFe;indNFCe\n000001;Tributação integral;000;PADRAO;0;0;01/01/2026;;0;1;1\n"],
      'cbs_rates'=>['filename'=>'modelo_cbs_rates.csv','content'=>"Codigo;VigenciaInicio;VigenciaFim;Aliquota\nPADRAO;01/01/2026;31/12/2026;0,9000\n"],
      'ibs_uf_rates'=>['filename'=>'modelo_ibs_uf_rates.csv','content'=>"UF;VigenciaInicio;VigenciaFim;Aliquota\nMG;01/01/2026;31/12/2026;0,1000\n"],
      'ibs_mun_rates'=>['filename'=>'modelo_ibs_municipio_rates.csv','content'=>"CodigoIBGE;Municipio;UF;VigenciaInicio;VigenciaFim;Aliquota\n3131703;Itabira;MG;01/01/2026;31/12/2026;0,0000\n"],
      'municipios'=>['filename'=>'modelo_municipios.csv','content'=>"CodigoIBGE;Municipio;UF\n3131703;Itabira;MG\n"],
      'ufs'=>['filename'=>'modelo_ufs.csv','content'=>"CodigoIBGE;Nome;UF;FusoHorarioNormal;FusoHorarioVerao\n31;Minas Gerais;MG;-03:00;-02:00\n"],
    ];
    if(!isset($templates[$type]))throw new RuntimeException('Modelo não encontrado.');
    return $templates[$type];
  }
}
