<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

// Stubs mínimos para ambientes de teste sem php-xml. Em produção, as classes
// nativas da extensão DOM são utilizadas.
if (!class_exists('DOMElement')) {
    class DOMElement {
        public string $nodeName;
        public string $localName;
        public string $nodeValue = '';
        /** @var array<int,DOMElement> */
        public array $children = [];
        public function __construct(string $name, string $value = '') { $this->nodeName=$name; $this->localName=$name; $this->nodeValue=$value; }
        public function appendChild(DOMElement $node): DOMElement { $this->children[]=$node; return $node; }
    }
}
if (!class_exists('DOMDocument')) {
    class DOMDocument {
        public bool $formatOutput = false;
        private ?DOMElement $root = null;
        public function __construct(string $version='1.0', string $encoding='UTF-8') {}
        public function createElement(string $name): DOMElement { return new DOMElement($name); }
        public function createTextNode(string $value): DOMElement { return new DOMElement('#text', $value); }
        public function appendChild(DOMElement $node): DOMElement { $this->root=$node; return $node; }
        private function render(DOMElement $node): string {
            if ($node->nodeName === '#text') return htmlspecialchars($node->nodeValue, ENT_XML1 | ENT_QUOTES, 'UTF-8');
            $inner=''; foreach ($node->children as $child) $inner.=$this->render($child);
            return '<'.$node->nodeName.'>'.$inner.'</'.$node->nodeName.'>';
        }
        public function saveXML(): string { return '<?xml version="1.0" encoding="UTF-8"?>'.($this->root ? $this->render($this->root) : ''); }
    }
}

$root = dirname(__DIR__);
require_once $root . '/config/fiscal_module.php';
require_once $root . '/config/fiscal_operational.php';

$failures = [];

// 1) Impede regressão da regra que bloqueava automaticamente CRT 1/2/4.
$rf = new ReflectionFunction('fiscal_ibs_cbs_resolve');
$source = (string) file_get_contents((string) $rf->getFileName());
$start = $rf->getStartLine();
$end = $rf->getEndLine();
$lines = preg_split('/\R/', $source) ?: [];
$functionSource = implode("\n", array_slice($lines, max(0, $start - 1), max(0, $end - $start + 1)));
if (str_contains($functionSource, "in_array(\$crt, ['1','2','4']")) {
    $failures[] = 'O resolver ainda bloqueia IBS/CBS automático para CRT 1/2/4.';
}

// 2) Monta o grupo de item com os mesmos números do XML de referência autorizado.
$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = false;
$det = $dom->createElement('det');
$dom->appendChild($det);
$imposto = $dom->createElement('imposto');
$det->appendChild($imposto);
$add = static function (DOMElement $parent, string $name, string $value) use ($dom): DOMElement {
    $node = $dom->createElement($name);
    if ($value !== '') $node->appendChild($dom->createTextNode($value));
    $parent->appendChild($node);
    return $node;
};
$fmt = static fn($v): string => number_format((float)$v, 2, '.', '');
$fmt4 = static fn($v): string => number_format((float)$v, 4, '.', '');
$tax = [
    'tax_group' => 'IBSCBS',
    'cst' => '000',
    'base_calc' => 7.50,
    'extra_json' => json_encode([
        'cClassTrib' => '000001',
        'CST' => '000',
        'base_calc' => 7.50,
        'ibs_uf_rate' => 0.1000,
        'ibs_mun_rate' => 0.0000,
        'cbs_rate' => 0.9000,
        'ibs_uf_amount' => 0.01,
        'ibs_mun_amount' => 0.00,
        'ibs_amount' => 0.01,
        'cbs_amount' => 0.07,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];
fiscal_nfce_add_ibs_cbs_xml($dom, $add, $imposto, $tax, $fmt, $fmt4);
$add($det, 'vItem', '7.50');
$xml = $dom->saveXML() ?: '';

$orderedMarkers = [
    '<IBSCBS>', '<CST>000</CST>', '<cClassTrib>000001</cClassTrib>',
    '<gIBSCBS>', '<vBC>7.50</vBC>', '<gIBSUF>', '<pIBSUF>0.1000</pIBSUF>',
    '<vIBSUF>0.01</vIBSUF>', '<gIBSMun>', '<pIBSMun>0.0000</pIBSMun>',
    '<vIBSMun>0.00</vIBSMun>', '<vIBS>0.01</vIBS>', '<gCBS>',
    '<pCBS>0.9000</pCBS>', '<vCBS>0.07</vCBS>', '<vItem>7.50</vItem>',
];
$last = -1;
foreach ($orderedMarkers as $marker) {
    $pos = strpos($xml, $marker);
    if ($pos === false) {
        $failures[] = 'Tag/valor ausente no XML de teste: ' . $marker;
        continue;
    }
    if ($pos <= $last) $failures[] = 'Ordem incorreta no XML de teste: ' . $marker;
    $last = $pos;
}

// 3) Confirma os grupos de totais no gerador principal.
$rfBuild = new ReflectionFunction('fiscal_nfce_build_draft_xml');
$srcBuild = (string) file_get_contents((string) $rfBuild->getFileName());
$buildLines = preg_split('/\R/', $srcBuild) ?: [];
$buildSource = implode("\n", array_slice($buildLines, max(0, $rfBuild->getStartLine() - 1), max(0, $rfBuild->getEndLine() - $rfBuild->getStartLine() + 1)));
foreach (['IBSCBSTot', 'vBCIBSCBS', 'vDif', 'vDevTrib', 'vCredPres', 'vCredPresCondSus', 'vNFTot'] as $tag) {
    if (!str_contains($buildSource, "'{$tag}'")) $failures[] = "Gerador principal não contém a tag {$tag}.";
}

if ($failures) {
    fwrite(STDERR, "[ERRO] Teste de referência RTC falhou:\n- " . implode("\n- ", $failures) . "\n");
    exit(1);
}

echo "[OK] CRT 1/2/4 não é bloqueado apenas pelo regime.\n";
echo "[OK] Grupo IBSCBS do item segue a referência autorizada.\n";
echo "[OK] Gerador contém IBSCBSTot e vNFTot.\n";
echo $xml . "\n";
