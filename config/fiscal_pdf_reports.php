<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/vendor/autoload.php';

use NFePHP\DA\Legacy\FPDF\Fpdf;

if (!class_exists('NexaFiscalPdf')) {
  class NexaFiscalPdf extends Fpdf {
    public bool $InHeader = false;
    public bool $InFooter = false;
    public string $reportTitle = '';
    public string $companyLine = '';
    public string $periodLine = '';
    public string $seriesLine = '';
    public string $filterLine = '';

    public static function enc($value): string {
      $text = (string)$value;
      if ($text === '') return '';
      if (function_exists('iconv')) {
        $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT//IGNORE', $text);
        if (is_string($converted)) return $converted;
      }
      return utf8_decode($text);
    }

    public static function short($value, int $limit): string {
      $text = trim((string)$value);
      if ($limit <= 0 || $text === '') return $text;
      if (function_exists('mb_strimwidth')) return mb_strimwidth($text, 0, $limit, '...', 'UTF-8');
      return strlen($text) > $limit ? substr($text, 0, max(0, $limit - 3)) . '...' : $text;
    }

    public function header(): void {
      $this->InHeader = true;
      $this->setFont('Helvetica', 'B', 11);
      $this->cell(0, 5, self::enc($this->reportTitle), 0, 1, 'C');
      $this->setFont('Helvetica', '', 7.5);
      if ($this->companyLine !== '') $this->cell(0, 4, self::enc($this->companyLine), 0, 1, 'C');
      if ($this->periodLine !== '') $this->cell(0, 4, self::enc($this->periodLine), 0, 1, 'C');
      if ($this->seriesLine !== '') $this->cell(0, 4, self::enc($this->seriesLine), 0, 1, 'C');
      if ($this->filterLine !== '') $this->cell(0, 4, self::enc($this->filterLine), 0, 1, 'C');
      $this->ln(1.5);
      $this->InHeader = false;
    }

    public function pageHeight(): float { return (float)$this->h; }

    public function footer(): void {
      $this->InFooter = true;
      $this->setY(-10);
      $this->setFont('Helvetica', 'I', 6.5);
      $this->cell(0, 4, self::enc('Emitido em ' . date('d/m/Y H:i') . ' - Página ' . $this->pageNo() . '/{nb}'), 0, 0, 'R');
      $this->InFooter = false;
    }
  }
}

if (!function_exists('nexa_pdf_money')) {
  function nexa_pdf_money($value): string { return number_format((float)$value, 2, ',', '.'); }
}
if (!function_exists('nexa_pdf_qty')) {
  function nexa_pdf_qty($value): string { return rtrim(rtrim(number_format((float)$value, 4, ',', '.'), '0'), ','); }
}
if (!function_exists('nexa_pdf_doc_label')) {
  function nexa_pdf_doc_label(string $model): string { return $model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'Documento'); }
}
if (!function_exists('nexa_pdf_company_line')) {
  function nexa_pdf_company_line(array $identity): string {
    $parts = [trim((string)($identity['name'] ?? 'Empresa'))];
    $cnpj = preg_replace('/\D+/', '', (string)($identity['cnpj'] ?? '')) ?: '';
    if ($cnpj !== '') $parts[] = 'CNPJ ' . $cnpj;
    if (trim((string)($identity['ie'] ?? '')) !== '') $parts[] = 'IE ' . trim((string)$identity['ie']);
    $cityUf = trim(trim((string)($identity['city'] ?? '')) . '/' . trim((string)($identity['uf'] ?? '')), '/');
    if ($cityUf !== '') $parts[] = $cityUf;
    return implode(' - ', $parts);
  }
}
if (!function_exists('nexa_pdf_base')) {
  function nexa_pdf_base(array $data, string $title, string $orientation = 'P'): NexaFiscalPdf {
    $pdf = new NexaFiscalPdf($orientation, 'mm', 'A4');
    $pdf->setMargins(8, 8, 8);
    $pdf->setAutoPageBreak(true, 13);
    $pdf->aliasNbPages();
    $pdf->reportTitle = $title;
    $pdf->companyLine = nexa_pdf_company_line((array)($data['identity'] ?? []));
    $pdf->periodLine = 'Período: ' . date('d/m/Y', strtotime((string)$data['ini'])) . ' a ' . date('d/m/Y', strtotime((string)$data['fim']));
    $model = (string)($data['model'] ?? '');
    $pdf->seriesLine = 'Documento: ' . ($model === '55' ? 'NF-e' : ($model === '65' ? 'NFC-e' : 'NF-e e NFC-e')) . ' - Série: ' . ((string)($data['series'] ?? '') !== '' ? (string)$data['series'] : 'Todas');
    $pdf->setTitle(NexaFiscalPdf::enc($title));
    $pdf->setAuthor(NexaFiscalPdf::enc((string)(($data['identity']['name'] ?? 'Nexa Próspero'))));
    return $pdf;
  }
}
if (!function_exists('nexa_pdf_table_header')) {
  function nexa_pdf_table_header(Fpdf $pdf, array $columns, float $height = 5.3, float $fontSize = 6.8): void {
    $pdf->setFont('Helvetica', 'B', $fontSize);
    foreach ($columns as $col) {
      $pdf->cell((float)$col['w'], $height, NexaFiscalPdf::enc((string)$col['label']), 1, 0, (string)($col['align'] ?? 'C'));
    }
    $pdf->ln();
  }
}
if (!function_exists('nexa_pdf_table_row')) {
  function nexa_pdf_table_row(Fpdf $pdf, array $columns, array $values, float $height = 4.8, float $fontSize = 6.7): void {
    $pdf->setFont('Helvetica', '', $fontSize);
    foreach ($columns as $idx => $col) {
      $text = NexaFiscalPdf::short((string)($values[$idx] ?? ''), (int)($col['chars'] ?? 40));
      $pdf->cell((float)$col['w'], $height, NexaFiscalPdf::enc($text), 1, 0, (string)($col['align'] ?? 'L'));
    }
    $pdf->ln();
  }
}
if (!function_exists('nexa_pdf_need_page')) {
  function nexa_pdf_need_page(NexaFiscalPdf $pdf, float $needed, array $columns, string $orientation = '', float $headerHeight = 5.5, float $headerFont = 6.8): void {
    $limit = $pdf->pageHeight() - 15;
    if ($pdf->getY() + $needed <= $limit) return;
    $pdf->addPage($orientation);
    nexa_pdf_table_header($pdf, $columns, $headerHeight, $headerFont);
  }
}

if (!function_exists('fiscal_pdf_documents')) {
  function fiscal_pdf_documents(array $data): string {
    $pdf = nexa_pdf_base($data, 'Relação de documentos fiscais', 'P');
    $pdf->addPage();
    $columns = [
      ['label'=>'Data','w'=>20,'chars'=>10,'align'=>'C'],
      ['label'=>'Tipo','w'=>13,'chars'=>8,'align'=>'C'],
      ['label'=>'Número','w'=>17,'chars'=>12,'align'=>'R'],
      ['label'=>'Série','w'=>11,'chars'=>8,'align'=>'C'],
      ['label'=>'Participante','w'=>48,'chars'=>34],
      ['label'=>'CPF/CNPJ','w'=>27,'chars'=>18],
      ['label'=>'Situação','w'=>24,'chars'=>18,'align'=>'C'],
      ['label'=>'Total','w'=>23,'chars'=>15,'align'=>'R'],
    ];
    nexa_pdf_table_header($pdf, $columns);
    $authorizedTotal = 0.0; $cancelledTotal = 0.0;
    foreach ((array)($data['documents'] ?? []) as $doc) {
      nexa_pdf_need_page($pdf, 5, $columns);
      $status = strtoupper(trim((string)($doc['status'] ?? '')));
      $value = (float)($doc['total_document'] ?? 0);
      if ($status === 'CANCELADA') $cancelledTotal += $value; else $authorizedTotal += $value;
      $date = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? '');
      nexa_pdf_table_row($pdf, $columns, [
        $date !== '' ? date('d/m/Y', strtotime($date)) : '',
        nexa_pdf_doc_label((string)($doc['model'] ?? '')),
        (string)($doc['number'] ?? ''),
        (string)($doc['series'] ?? ''),
        (string)($doc['recipient_name'] ?? 'CONSUMIDOR FINAL'),
        (string)($doc['recipient_document'] ?? ''),
        $status,
        nexa_pdf_money($value),
      ]);
    }
    $pdf->ln(2);
    $pdf->setFont('Helvetica', 'B', 8);
    $pdf->cell(0, 5, NexaFiscalPdf::enc('Autorizadas: R$ ' . nexa_pdf_money($authorizedTotal) . ' | Canceladas: R$ ' . nexa_pdf_money($cancelledTotal) . ' | Documentos: ' . count((array)($data['documents'] ?? []))), 0, 1, 'R');
    return (string)$pdf->output('', 'S');
  }
}

if (!function_exists('fiscal_pdf_cfop')) {
  function fiscal_pdf_cfop(array $data): string {
    $pdf = nexa_pdf_base($data, 'Documentos agrupados por CFOP', 'P');
    $pdf->addPage();
    $columns = [
      ['label'=>'Data','w'=>21,'chars'=>10,'align'=>'C'],
      ['label'=>'Tipo','w'=>14,'chars'=>8,'align'=>'C'],
      ['label'=>'Número','w'=>19,'chars'=>12,'align'=>'R'],
      ['label'=>'Série','w'=>12,'chars'=>8,'align'=>'C'],
      ['label'=>'CFOP','w'=>18,'chars'=>10,'align'=>'C'],
      ['label'=>'Participante','w'=>72,'chars'=>50],
      ['label'=>'Total CFOP','w'=>30,'chars'=>16,'align'=>'R'],
    ];
    nexa_pdf_table_header($pdf, $columns);
    foreach ((array)($data['documents'] ?? []) as $doc) {
      if (strtoupper((string)($doc['status'] ?? '')) !== 'AUTORIZADA') continue;
      $date = (string)($doc['issue_datetime'] ?? $doc['created_at'] ?? '');
      $groups = (array)(($data['doc_cfop'] ?? [])[(int)($doc['id'] ?? 0)] ?? []);
      foreach ($groups as $cfop => $value) {
        nexa_pdf_need_page($pdf, 5, $columns);
        nexa_pdf_table_row($pdf, $columns, [
          $date !== '' ? date('d/m/Y', strtotime($date)) : '',
          nexa_pdf_doc_label((string)($doc['model'] ?? '')),
          (string)($doc['number'] ?? ''),
          (string)($doc['series'] ?? ''),
          (string)$cfop,
          (string)($doc['recipient_name'] ?? 'CONSUMIDOR FINAL'),
          nexa_pdf_money($value),
        ]);
      }
    }
    $pdf->ln(2);
    $pdf->setFont('Helvetica', 'B', 8);
    foreach ((array)($data['cfop_general'] ?? []) as $cfop => $value) {
      if ($pdf->getY() > $pdf->pageHeight() - 19) $pdf->addPage();
      $pdf->cell(0, 4.5, NexaFiscalPdf::enc('CFOP ' . $cfop . ': R$ ' . nexa_pdf_money($value)), 0, 1, 'R');
    }
    $pdf->cell(0, 5, NexaFiscalPdf::enc('Total dos documentos: R$ ' . nexa_pdf_money((float)(($data['totals']['documents'] ?? 0)))), 0, 1, 'R');
    return (string)$pdf->output('', 'S');
  }
}

if (!function_exists('fiscal_pdf_cfop_payment')) {
  function fiscal_pdf_cfop_payment(array $data): string {
    $pdf = nexa_pdf_base($data, 'CFOP por forma de pagamento', 'L');
    $pdf->addPage('L');
    $columns = [
      ['label'=>'Data','w'=>17,'chars'=>10,'align'=>'C'],
      ['label'=>'Documento','w'=>25,'chars'=>16],
      ['label'=>'Série','w'=>10,'chars'=>7,'align'=>'C'],
      ['label'=>'CFOP','w'=>13,'chars'=>8,'align'=>'C'],
      ['label'=>'CST','w'=>10,'chars'=>6,'align'=>'C'],
      ['label'=>'CSOSN','w'=>12,'chars'=>7,'align'=>'C'],
      ['label'=>'Total','w'=>20,'chars'=>12,'align'=>'R'],
      ['label'=>'Dinheiro','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'Pix','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'Cheque','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'Cartão','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'Prazo','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'Outros','w'=>16,'chars'=>10,'align'=>'R'],
      ['label'=>'B.ICMS','w'=>19,'chars'=>12,'align'=>'R'],
      ['label'=>'ICMS','w'=>17,'chars'=>11,'align'=>'R'],
      ['label'=>'B.ST','w'=>19,'chars'=>12,'align'=>'R'],
      ['label'=>'ICMS ST','w'=>19,'chars'=>12,'align'=>'R'],
    ];
    nexa_pdf_table_header($pdf, $columns, 5, 5.5);
    foreach ((array)($data['day_rows'] ?? []) as $date => $rows) {
      foreach ((array)$rows as $row) {
        nexa_pdf_need_page($pdf, 4.5, $columns, 'L', 5, 5.5);
        $min = (int)($row['number_min'] ?? 0); $max = (int)($row['number_max'] ?? 0);
        $range = $min === $max ? (string)$min : $min . ' a ' . $max;
        nexa_pdf_table_row($pdf, $columns, [
          date('d/m/Y', strtotime((string)$date)),
          nexa_pdf_doc_label((string)($row['model'] ?? '')) . ' ' . $range,
          (string)($row['series'] ?? ''),
          (string)($row['cfop'] ?? ''),
          (string)($row['cst'] ?? ''),
          (string)($row['csosn'] ?? ''),
          nexa_pdf_money($row['total'] ?? 0),
          nexa_pdf_money($row['dinheiro'] ?? 0),
          nexa_pdf_money($row['pix'] ?? 0),
          nexa_pdf_money($row['cheque'] ?? 0),
          nexa_pdf_money($row['cartao'] ?? 0),
          nexa_pdf_money($row['prazo'] ?? 0),
          nexa_pdf_money($row['outros'] ?? 0),
          nexa_pdf_money($row['icms_base'] ?? 0),
          nexa_pdf_money($row['icms_amount'] ?? 0),
          nexa_pdf_money($row['st_base'] ?? 0),
          nexa_pdf_money($row['st_amount'] ?? 0),
        ], 4.4, 5.2);
      }
    }
    $pdf->ln(2);
    $tot = (array)($data['payment_totals'] ?? []);
    $pdf->setFont('Helvetica', 'B', 7);
    $summary = 'Total R$ ' . nexa_pdf_money($tot['total'] ?? 0)
      . ' | Dinheiro ' . nexa_pdf_money($tot['dinheiro'] ?? 0)
      . ' | Pix ' . nexa_pdf_money($tot['pix'] ?? 0)
      . ' | Cheque ' . nexa_pdf_money($tot['cheque'] ?? 0)
      . ' | Cartão ' . nexa_pdf_money($tot['cartao'] ?? 0)
      . ' | Prazo ' . nexa_pdf_money($tot['prazo'] ?? 0)
      . ' | Outros ' . nexa_pdf_money($tot['outros'] ?? 0);
    $pdf->multiCell(0, 4, NexaFiscalPdf::enc($summary), 0, 'R');
    return (string)$pdf->output('', 'S');
  }
}

if (!function_exists('nexa_inventory_report_summary')) {
  /** @return array<int,array{group:string,code:string,count:int,qty:float,value:float}> */
  function nexa_inventory_report_summary(array $items): array {
    $summary = [];
    $add = static function(array &$target, string $group, string $code, float $qty, float $value): void {
      $code = trim($code) !== '' ? trim($code) : 'SEM CÓDIGO';
      $key = $group . '|' . $code;
      if (!isset($target[$key])) $target[$key] = ['group'=>$group,'code'=>$code,'count'=>0,'qty'=>0.0,'value'=>0.0];
      $target[$key]['count']++;
      $target[$key]['qty'] += $qty;
      $target[$key]['value'] += $value;
    };
    foreach ($items as $item) {
      $qty = (float)($item['quantity'] ?? 0);
      $value = (float)($item['total_value'] ?? 0);
      $add($summary, 'CFOP', (string)($item['cfop'] ?? ''), $qty, $value);
      $add($summary, 'CST ICMS', (string)($item['icms_cst'] ?? ''), $qty, $value);
      $add($summary, 'CSOSN', (string)($item['icms_csosn'] ?? ''), $qty, $value);
      $add($summary, 'CST PIS', (string)($item['pis_cst'] ?? ''), $qty, $value);
      $add($summary, 'CST COFINS', (string)($item['cofins_cst'] ?? ''), $qty, $value);
    }
    $rows = array_values($summary);
    usort($rows, static fn(array $a, array $b): int => [$a['group'],$a['code']] <=> [$b['group'],$b['code']]);
    return $rows;
  }
}

if (!function_exists('nexa_inventory_book_meta')) {
  /** @return array{product_pages:int,summary_pages:int,page_count:int,final_page:int,final_sheet:int} */
  function nexa_inventory_book_meta(array $inventory, array $items): array {
    $summary = nexa_inventory_report_summary($items);
    $productPages = max(1, (int)ceil(max(1, count($items)) / 20));
    $summaryPages = max(1, (int)ceil(max(1, count($summary)) / 18));
    $pageCount = $productPages + $summaryPages;
    $initialPage = max(1, (int)($inventory['initial_page'] ?? 1));
    $initialSheet = max(1, (int)($inventory['initial_sheet'] ?? $initialPage));
    return [
      'product_pages'=>$productPages,
      'summary_pages'=>$summaryPages,
      'page_count'=>$pageCount,
      'final_page'=>$initialPage + $pageCount - 1,
      'final_sheet'=>$initialSheet + $pageCount - 1,
    ];
  }
}

if (!function_exists('nexa_inventory_book_header')) {
  function nexa_inventory_book_header(Fpdf $pdf, array $inventory, array $identity, int $pageNo, int $finalPage, int $sheetNo, int $finalSheet, string $title): void {
    $pdf->setFont('Helvetica', 'B', 10);
    $pdf->cell(190, 5, NexaFiscalPdf::enc($title), 0, 0, 'L');
    $pdf->cell(81, 5, NexaFiscalPdf::enc('Livro ' . str_pad((string)max(1,(int)($inventory['book_number'] ?? 1)), 2, '0', STR_PAD_LEFT)), 0, 1, 'R');
    $pdf->setFont('Helvetica', '', 7.2);
    $company = nexa_pdf_company_line($identity);
    $pdf->cell(190, 4, NexaFiscalPdf::enc($company), 0, 0, 'L');
    $pdf->cell(81, 4, NexaFiscalPdf::enc('Página ' . str_pad((string)$pageNo,3,'0',STR_PAD_LEFT) . ' de ' . str_pad((string)$finalPage,3,'0',STR_PAD_LEFT)), 0, 1, 'R');
    $pdf->cell(190, 4, NexaFiscalPdf::enc('Inventário levantado em ' . date('d/m/Y', strtotime((string)($inventory['inventory_date'] ?? date('Y-m-d'))))), 0, 0, 'L');
    $pdf->cell(81, 4, NexaFiscalPdf::enc('Folha ' . str_pad((string)$sheetNo,3,'0',STR_PAD_LEFT) . ' de ' . str_pad((string)$finalSheet,3,'0',STR_PAD_LEFT)), 0, 1, 'R');
    $pdf->setLineWidth(.4);
    $pdf->line(8, 23, 289, 23);
    $pdf->ln(2);
  }
}

if (!function_exists('nexa_inventory_book_footer')) {
  function nexa_inventory_book_footer(Fpdf $pdf, array $inventory, int $pageNo, int $sheetNo): void {
    $pdf->setY(-11);
    $pdf->setLineWidth(.2);
    $pdf->line(8, $pdf->getY(), 289, $pdf->getY());
    $pdf->setFont('Helvetica', '', 6.5);
    $pdf->cell(150, 4, NexaFiscalPdf::enc('Emitido em ' . date('d/m/Y H:i')), 0, 0, 'L');
    $pdf->cell(121, 4, NexaFiscalPdf::enc('Livro ' . max(1,(int)($inventory['book_number'] ?? 1)) . ' - Página ' . str_pad((string)$pageNo,3,'0',STR_PAD_LEFT) . ' - Folha ' . str_pad((string)$sheetNo,3,'0',STR_PAD_LEFT)), 0, 0, 'R');
  }
}

if (!function_exists('fiscal_pdf_inventory')) {
  function fiscal_pdf_inventory(array $inventory, array $items, array $identity): string {
    $meta = nexa_inventory_book_meta($inventory, $items);
    $initialPage = max(1, (int)($inventory['initial_page'] ?? 1));
    $initialSheet = max(1, (int)($inventory['initial_sheet'] ?? $initialPage));
    $finalPage = (int)$meta['final_page'];
    $finalSheet = (int)$meta['final_sheet'];
    $pageCount = (int)$meta['page_count'];
    $book = max(1, (int)($inventory['book_number'] ?? 1));
    $dateBr = date('d/m/Y', strtotime((string)($inventory['inventory_date'] ?? date('Y-m-d'))));
    $criterion = (string)($inventory['cost_method'] ?? '') === 'last' ? 'Último custo' : 'Custo médio';
    $summary = nexa_inventory_report_summary($items);
    $productChunks = array_chunk($items, 20); if (!$productChunks) $productChunks = [[]];
    $summaryChunks = array_chunk($summary, 18); if (!$summaryChunks) $summaryChunks = [[]];

    $pdf = new Fpdf('L', 'mm', 'A4');
    $pdf->setMargins(8, 8, 8);
    $pdf->setAutoPageBreak(false);
    $pdf->setTitle(NexaFiscalPdf::enc('Registro de inventário - Modelo 7'));
    $pdf->setAuthor(NexaFiscalPdf::enc((string)($identity['name'] ?? 'Nexa Próspero')));

    // Capa do livro - não consome a numeração contábil das folhas internas.
    $pdf->addPage('L');
    $pdf->setLineWidth(.7); $pdf->rect(15, 14, 267, 181);
    $pdf->setLineWidth(.2); $pdf->rect(17, 16, 263, 177);
    $pdf->setY(27);
    $pdf->setFont('Helvetica', 'B', 23);
    $pdf->cell(0, 11, NexaFiscalPdf::enc('REGISTRO DE INVENTÁRIO'), 0, 1, 'C');
    $pdf->setFont('Helvetica', 'B', 14);
    $pdf->cell(0, 8, NexaFiscalPdf::enc('MODELO 7 - LIVRO Nº ' . str_pad((string)$book, 2, '0', STR_PAD_LEFT)), 0, 1, 'C');
    $pdf->ln(12);
    $pdf->setFont('Helvetica', 'B', 14);
    $pdf->cell(0, 7, NexaFiscalPdf::enc((string)($identity['name'] ?? 'Empresa')), 0, 1, 'C');
    $pdf->setFont('Helvetica', '', 9);
    $companyParts = [];
    $cnpj = preg_replace('/\D+/', '', (string)($identity['cnpj'] ?? '')) ?: '';
    if ($cnpj !== '') $companyParts[] = 'CNPJ: ' . $cnpj;
    if (trim((string)($identity['ie'] ?? '')) !== '') $companyParts[] = 'Inscrição Estadual: ' . trim((string)$identity['ie']);
    $cityUf = trim(trim((string)($identity['city'] ?? '')) . '/' . trim((string)($identity['uf'] ?? '')), '/');
    if ($cityUf !== '') $companyParts[] = 'Município/UF: ' . $cityUf;
    foreach ($companyParts as $line) $pdf->cell(0, 5, NexaFiscalPdf::enc($line), 0, 1, 'C');

    $x=29; $y=91; $w=239; $h=14; $cellW=$w/3;
    $coverFields = [
      ['Data do inventário',$dateBr],
      ['Número do livro',str_pad((string)$book,2,'0',STR_PAD_LEFT)],
      ['Páginas/folhas numeradas',(string)$pageCount],
      ['Página inicial',str_pad((string)$initialPage,3,'0',STR_PAD_LEFT)],
      ['Página final',str_pad((string)$finalPage,3,'0',STR_PAD_LEFT)],
      ['Critério de valorização',$criterion],
      ['Folha inicial',str_pad((string)$initialSheet,3,'0',STR_PAD_LEFT)],
      ['Folha final',str_pad((string)$finalSheet,3,'0',STR_PAD_LEFT)],
      ['Produtos registrados',(string)count($items)],
    ];
    foreach ($coverFields as $idx=>$field) {
      $row=intdiv($idx,3); $col=$idx%3; $cx=$x+$col*$cellW; $cy=$y+$row*$h;
      $pdf->rect($cx,$cy,$cellW,$h);
      $pdf->setXY($cx,$cy+2); $pdf->setFont('Helvetica','',7); $pdf->cell($cellW,4,NexaFiscalPdf::enc($field[0]),0,1,'C');
      $pdf->setX($cx); $pdf->setFont('Helvetica','B',10); $pdf->cell($cellW,5,NexaFiscalPdf::enc($field[1]),0,1,'C');
    }
    $pdf->setXY(30,139); $pdf->setFont('Helvetica','',8.5);
    $note='Relação das mercadorias, matérias-primas, produtos intermediários, materiais de embalagem, produtos manufaturados e produtos em fabricação existentes no estabelecimento na data indicada.';
    $pdf->multiCell(237,5,NexaFiscalPdf::enc($note),0,'J');
    $pdf->line(35,180,125,180); $pdf->line(172,180,262,180);
    $pdf->setXY(35,181); $pdf->setFont('Helvetica','',8); $pdf->cell(90,4,NexaFiscalPdf::enc('Responsável legal'),0,0,'C');
    $pdf->setXY(172,181); $pdf->cell(90,4,NexaFiscalPdf::enc('Contador responsável'),0,0,'C');

    $columns = [
      ['label'=>'Código','w'=>17,'chars'=>11],
      ['label'=>'NCM/CEST','w'=>22,'chars'=>16],
      ['label'=>'Descrição da mercadoria','w'=>61,'chars'=>45],
      ['label'=>'Grupo','w'=>29,'chars'=>21],
      ['label'=>'Un.','w'=>8,'chars'=>4,'align'=>'C'],
      ['label'=>'Quantidade','w'=>19,'chars'=>12,'align'=>'R'],
      ['label'=>'Valor unit.','w'=>20,'chars'=>12,'align'=>'R'],
      ['label'=>'Valor total','w'=>22,'chars'=>13,'align'=>'R'],
      ['label'=>'CFOP','w'=>12,'chars'=>7,'align'=>'C'],
      ['label'=>'CST','w'=>11,'chars'=>6,'align'=>'C'],
      ['label'=>'CSOSN','w'=>13,'chars'=>7,'align'=>'C'],
      ['label'=>'PIS','w'=>11,'chars'=>6,'align'=>'C'],
      ['label'=>'COFINS','w'=>13,'chars'=>7,'align'=>'C'],
    ];

    $pageNo=$initialPage; $sheetNo=$initialSheet;
    foreach ($productChunks as $chunk) {
      $pdf->addPage('L');
      nexa_inventory_book_header($pdf,$inventory,$identity,$pageNo,$finalPage,$sheetNo,$finalSheet,'REGISTRO DE INVENTÁRIO - MODELO 7');
      nexa_pdf_table_header($pdf,$columns,5.5,5.5);
      if (!$chunk) {
        $pdf->setFont('Helvetica','',8); $pdf->cell(array_sum(array_column($columns,'w')),8,NexaFiscalPdf::enc('Nenhum produto encontrado para os filtros selecionados.'),1,1,'C');
      } else {
        foreach ($chunk as $item) {
          $ncmCest=trim((string)($item['ncm'] ?? ''));
          if (trim((string)($item['cest'] ?? '')) !== '') $ncmCest .= ($ncmCest!==''?'/':'') . trim((string)$item['cest']);
          nexa_pdf_table_row($pdf,$columns,[
            (string)($item['product_code'] ?? ''),$ncmCest,(string)($item['product_name'] ?? ''),(string)($item['product_group'] ?? ''),(string)($item['unit'] ?? 'UN'),
            nexa_pdf_qty($item['quantity'] ?? 0),nexa_pdf_money($item['valuation_cost'] ?? 0),nexa_pdf_money($item['total_value'] ?? 0),
            (string)($item['cfop'] ?? ''),(string)($item['icms_cst'] ?? ''),(string)($item['icms_csosn'] ?? ''),(string)($item['pis_cst'] ?? ''),(string)($item['cofins_cst'] ?? ''),
          ],5.6,5.2);
        }
      }
      nexa_inventory_book_footer($pdf,$inventory,$pageNo,$sheetNo);
      $pageNo++; $sheetNo++;
    }

    $summaryColumns = [
      ['label'=>'Grupo fiscal','w'=>62,'chars'=>38],
      ['label'=>'Código','w'=>49,'chars'=>30],
      ['label'=>'Produtos','w'=>42,'chars'=>15,'align'=>'R'],
      ['label'=>'Quantidade','w'=>55,'chars'=>20,'align'=>'R'],
      ['label'=>'Valor total','w'=>63,'chars'=>22,'align'=>'R'],
    ];
    foreach ($summaryChunks as $summaryIndex=>$chunk) {
      $pdf->addPage('L');
      nexa_inventory_book_header($pdf,$inventory,$identity,$pageNo,$finalPage,$sheetNo,$finalSheet,'REGISTRO DE INVENTÁRIO - TOTALIZAÇÕES');
      if ($summaryIndex===0) {
        $pdf->setFont('Helvetica','',7); $pdf->cell(90,5,NexaFiscalPdf::enc('Produtos'),1,0,'C'); $pdf->cell(90,5,NexaFiscalPdf::enc('Quantidade total'),1,0,'C'); $pdf->cell(91,5,NexaFiscalPdf::enc('Valor total do inventário'),1,1,'C');
        $pdf->setFont('Helvetica','B',10); $pdf->cell(90,7,NexaFiscalPdf::enc((string)count($items)),1,0,'C'); $pdf->cell(90,7,NexaFiscalPdf::enc(nexa_pdf_qty($inventory['total_quantity'] ?? 0)),1,0,'C'); $pdf->cell(91,7,NexaFiscalPdf::enc('R$ ' . nexa_pdf_money($inventory['total_value'] ?? 0)),1,1,'C');
        $pdf->ln(2);
      }
      $pdf->setFont('Helvetica','B',8); $pdf->cell(0,5,NexaFiscalPdf::enc('Totais separados por enquadramento fiscal'),0,1,'C');
      nexa_pdf_table_header($pdf,$summaryColumns,5.8,6.2);
      if (!$chunk) {
        $pdf->setFont('Helvetica','',8); $pdf->cell(271,8,NexaFiscalPdf::enc('Nenhuma totalização fiscal disponível.'),1,1,'C');
      } else {
        foreach ($chunk as $row) {
          nexa_pdf_table_row($pdf,$summaryColumns,[(string)$row['group'],(string)$row['code'],(string)(int)$row['count'],nexa_pdf_qty($row['qty']),'R$ ' . nexa_pdf_money($row['value'])],5.6,6.1);
        }
      }
      if ($summaryIndex===count($summaryChunks)-1) {
        $y=max($pdf->getY()+9,155); $pdf->setXY(20,$y); $pdf->setFont('Helvetica','B',8);
        $pdf->cell(257,5,NexaFiscalPdf::enc('Declaramos que as informações deste livro representam o inventário do estabelecimento na data indicada.'),0,1,'C');
        $pdf->line(35,181,125,181); $pdf->line(172,181,262,181);
        $pdf->setXY(35,182); $pdf->setFont('Helvetica','',7.5); $pdf->cell(90,4,NexaFiscalPdf::enc('Responsável legal'),0,0,'C');
        $pdf->setXY(172,182); $pdf->cell(90,4,NexaFiscalPdf::enc('Contador responsável'),0,0,'C');
      }
      nexa_inventory_book_footer($pdf,$inventory,$pageNo,$sheetNo);
      $pageNo++; $sheetNo++;
    }

    return (string)$pdf->output('', 'S');
  }
}

if (!function_exists('accounting_pdf_documents_report')) {
  function accounting_pdf_documents_report(array $data): string {
    $pdf = nexa_pdf_base($data, 'Relatório de documentos fiscais - Central do Contador', 'L');
    $pdf->seriesLine .= ' - Movimento: ' . (string)($data['movement_label'] ?? 'Todos (geral)');
    $pdf->filterLine = trim((string)($data['filter_line'] ?? ''));
    $pdf->addPage('L');
    $columns = [
      ['label'=>'Data','w'=>17,'chars'=>10,'align'=>'C'],
      ['label'=>'Movimento','w'=>21,'chars'=>14,'align'=>'C'],
      ['label'=>'Tipo','w'=>11,'chars'=>7,'align'=>'C'],
      ['label'=>'Número','w'=>15,'chars'=>11,'align'=>'R'],
      ['label'=>'Série','w'=>8,'chars'=>6,'align'=>'C'],
      ['label'=>'Participante','w'=>39,'chars'=>28],
      ['label'=>'CPF/CNPJ','w'=>22,'chars'=>16],
      ['label'=>'Natureza da operação','w'=>35,'chars'=>25],
      ['label'=>'Situação','w'=>21,'chars'=>15,'align'=>'C'],
      ['label'=>'Chave de acesso','w'=>56,'chars'=>44],
      ['label'=>'Total','w'=>22,'chars'=>13,'align'=>'R'],
    ];
    nexa_pdf_table_header($pdf,$columns,5.2,5.7);
    foreach ((array)($data['documents'] ?? []) as $doc) {
      nexa_pdf_need_page($pdf,4.9,$columns,'L',5.2,5.7);
      $date=(string)($doc['issue_datetime'] ?? $doc['created_at'] ?? '');
      $isReturn = !empty($doc['_is_devolution']);
      $direction = strtoupper((string)($doc['direction'] ?? ''));
      $movement = $isReturn ? ($direction==='ENTRADA'?'Dev. entrada':'Dev. saída') : ($direction==='ENTRADA'?'Entrada':'Saída');
      nexa_pdf_table_row($pdf,$columns,[
        $date!==''?date('d/m/Y',strtotime($date)):'',$movement,nexa_pdf_doc_label((string)($doc['model'] ?? '')),(string)($doc['number'] ?? ''),(string)($doc['series'] ?? ''),
        (string)($doc['recipient_name'] ?? 'CONSUMIDOR FINAL'),(string)($doc['recipient_document'] ?? ''),(string)($doc['operation_nature'] ?? ''),(string)($doc['status'] ?? ''),(string)($doc['access_key'] ?? ''),nexa_pdf_money($doc['total_document'] ?? 0),
      ],4.7,4.9);
    }
    $tot=(array)($data['totals'] ?? []);
    $pdf->ln(2); $pdf->setFont('Helvetica','B',7.5);
    $pdf->cell(0,5,NexaFiscalPdf::enc('Documentos: ' . count((array)($data['documents'] ?? [])) . ' | Total fiscal válido: R$ ' . nexa_pdf_money($tot['total_document'] ?? 0) . ' | ICMS: R$ ' . nexa_pdf_money($tot['total_icms'] ?? 0) . ' | PIS: R$ ' . nexa_pdf_money($tot['total_pis'] ?? 0) . ' | COFINS: R$ ' . nexa_pdf_money($tot['total_cofins'] ?? 0)),0,1,'R');
    return (string)$pdf->output('', 'S');
  }
}
