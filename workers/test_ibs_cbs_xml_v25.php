<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

// Stubs mínimos permitem executar o teste até em servidores sem php-xml.
// Em produção, se a extensão DOM estiver instalada, as classes nativas são usadas.
if (!class_exists('DOMElement')) {
    class DOMElement {
        public string $nodeName;
        public string $nodeValue = '';
        /** @var array<int,DOMElement> */
        public array $children = [];
        public function __construct(string $name, string $value = '') { $this->nodeName=$name; $this->nodeValue=$value; }
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
require_once $root . '/config/fiscal_operational.php';

$dom = new DOMDocument('1.0', 'UTF-8');
$dom->formatOutput = true;
$rootNode = $dom->createElement('imposto');
$dom->appendChild($rootNode);
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
    'base_calc' => 100.00,
    'extra_json' => json_encode([
        'cClassTrib' => '000001',
        'CST' => '000',
        'base_calc' => 100.00,
        'ibs_uf_rate' => 0.1000,
        'ibs_mun_rate' => 0.0000,
        'cbs_rate' => 0.9000,
        'ibs_reduction_rate' => 0,
        'cbs_reduction_rate' => 0,
        'ibs_uf_amount' => 0.10,
        'ibs_mun_amount' => 0.00,
        'ibs_amount' => 0.10,
        'cbs_amount' => 0.90,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
];

fiscal_nfce_add_ibs_cbs_xml($dom, $add, $rootNode, $tax, $fmt, $fmt4);
$xml = $dom->saveXML() ?: '';

$checks = [
    '<CST>000</CST>' => str_contains($xml, '<CST>000</CST>'),
    '<cClassTrib>000001</cClassTrib>' => str_contains($xml, '<cClassTrib>000001</cClassTrib>'),
    '<vBC>100.00</vBC>' => str_contains($xml, '<vBC>100.00</vBC>'),
    '<pIBSUF>0.1000</pIBSUF>' => str_contains($xml, '<pIBSUF>0.1000</pIBSUF>'),
    '<vIBSUF>0.10</vIBSUF>' => str_contains($xml, '<vIBSUF>0.10</vIBSUF>'),
    '<pIBSMun>0.0000</pIBSMun>' => str_contains($xml, '<pIBSMun>0.0000</pIBSMun>'),
    '<vIBSMun>0.00</vIBSMun>' => str_contains($xml, '<vIBSMun>0.00</vIBSMun>'),
    '<pCBS>0.9000</pCBS>' => str_contains($xml, '<pCBS>0.9000</pCBS>'),
    '<vCBS>0.90</vCBS>' => str_contains($xml, '<vCBS>0.90</vCBS>'),
    'sem vTribOp no leiaute atual' => !str_contains($xml, '<vTribOp>'),
];

$failed = array_keys(array_filter($checks, static fn(bool $ok): bool => !$ok));
if ($failed) {
    fwrite(STDERR, "[ERRO] XML IBS/CBS não passou nos testes:\n- " . implode("\n- ", $failed) . "\n\n" . $xml . "\n");
    exit(1);
}

echo "[OK] Fragmento XML IBS/CBS validado.\n";
echo $xml . "\n";
