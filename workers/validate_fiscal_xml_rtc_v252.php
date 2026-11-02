<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/fiscal_operational.php';

$companyId = 0;
$docId = 0;
$xmlFile = '';
foreach ($argv as $arg) {
    if (preg_match('/^--company=(\d+)$/', $arg, $m)) $companyId = (int)$m[1];
    elseif (preg_match('/^--doc=(\d+)$/', $arg, $m)) $docId = (int)$m[1];
    elseif (str_starts_with($arg, '--xml=')) $xmlFile = substr($arg, 6);
}

if ($xmlFile === '' && ($companyId <= 0 || $docId <= 0)) {
    fwrite(STDERR, "Uso:\n");
    fwrite(STDERR, "  php workers/validate_fiscal_xml_rtc_v252.php --company=1 --doc=123\n");
    fwrite(STDERR, "  php workers/validate_fiscal_xml_rtc_v252.php --xml=/caminho/arquivo.xml\n");
    exit(2);
}

function rtc_decimal(string $value): float { return (float)str_replace(',', '.', trim($value)); }
function rtc_round2(float $value): float { return round($value + 1e-9, 2, PHP_ROUND_HALF_UP); }
function rtc_close(float $a, float $b, float $tol = 0.011): bool { return abs($a - $b) <= $tol; }

try {
    if ($xmlFile !== '') {
        if (!is_file($xmlFile) || !is_readable($xmlFile)) throw new RuntimeException('Arquivo XML não encontrado ou sem leitura: ' . $xmlFile);
        $xml = (string)file_get_contents($xmlFile);
        $label = $xmlFile;
    } else {
        require_once $root . '/config/nexa_env.php';
        require $root . '/config/pdo.php';
        $st = $pdo->prepare('SELECT model, document_number, status, xml_raw FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$companyId, $docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
        $xml = trim((string)($doc['xml_raw'] ?? ''));
        if ($xml === '') throw new RuntimeException('O documento ainda não possui XML gravado.');
        $label = sprintf('empresa=%d documento=%d modelo=%s número=%s status=%s', $companyId, $docId, (string)$doc['model'], (string)$doc['document_number'], (string)$doc['status']);
    }

    $dom = new DOMDocument();
    libxml_use_internal_errors(true);
    if (!$dom->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS)) {
        $errors = array_map(static fn(LibXMLError $e): string => trim($e->message), libxml_get_errors());
        throw new RuntimeException('XML malformado: ' . implode(' | ', $errors));
    }
    libxml_clear_errors();

    $xp = new DOMXPath($dom);
    $xp->registerNamespace('n', 'http://www.portalfiscal.inf.br/nfe');
    $text = static function (DOMXPath $xp, string $query, ?DOMNode $ctx = null): string {
        $nodes = $xp->query($query, $ctx);
        return ($nodes && $nodes->length > 0) ? trim((string)$nodes->item(0)?->textContent) : '';
    };

    $model = $text($xp, '//n:infNFe/n:ide/n:mod');
    if (!in_array($model, ['55','65'], true)) throw new RuntimeException('Modelo não é NF-e/NFC-e 55/65: ' . $model);

    $detNodes = $xp->query('//n:infNFe/n:det');
    if (!$detNodes || $detNodes->length === 0) throw new RuntimeException('XML sem itens <det>.');

    $sumBase = $sumIbsUf = $sumIbsMun = $sumIbs = $sumCbs = $sumVItem = 0.0;
    $rtcCount = 0;
    foreach ($detNodes as $det) {
        if (!$det instanceof DOMElement) continue;
        $nItem = $det->getAttribute('nItem') ?: '?';
        $ibscbs = $xp->query('./n:imposto/n:IBSCBS', $det);
        if (!$ibscbs || $ibscbs->length === 0) continue;
        $rtcCount++;

        $required = [
            './n:imposto/n:IBSCBS/n:CST' => 'CST',
            './n:imposto/n:IBSCBS/n:cClassTrib' => 'cClassTrib',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:vBC' => 'vBC',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSUF/n:pIBSUF' => 'pIBSUF',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSUF/n:vIBSUF' => 'vIBSUF',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSMun/n:pIBSMun' => 'pIBSMun',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSMun/n:vIBSMun' => 'vIBSMun',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:vIBS' => 'vIBS',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gCBS/n:pCBS' => 'pCBS',
            './n:imposto/n:IBSCBS/n:gIBSCBS/n:gCBS/n:vCBS' => 'vCBS',
            './n:vItem' => 'vItem',
        ];
        foreach ($required as $query => $name) {
            if ($text($xp, $query, $det) === '') throw new RuntimeException("Item {$nItem}: campo RTC ausente: {$name}.");
        }

        $cst = $text($xp, './n:imposto/n:IBSCBS/n:CST', $det);
        $class = $text($xp, './n:imposto/n:IBSCBS/n:cClassTrib', $det);
        if (!preg_match('/^\d{3}$/', $cst)) throw new RuntimeException("Item {$nItem}: CST IBS/CBS inválido: {$cst}.");
        if (!preg_match('/^\d{6}$/', $class)) throw new RuntimeException("Item {$nItem}: cClassTrib inválido: {$class}.");

        $base = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:vBC', $det));
        $pUf = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSUF/n:pIBSUF', $det));
        $vUf = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSUF/n:vIBSUF', $det));
        $pMun = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSMun/n:pIBSMun', $det));
        $vMun = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gIBSMun/n:vIBSMun', $det));
        $vIbs = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:vIBS', $det));
        $pCbs = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gCBS/n:pCBS', $det));
        $vCbs = rtc_decimal($text($xp, './n:imposto/n:IBSCBS/n:gIBSCBS/n:gCBS/n:vCBS', $det));
        $vItem = rtc_decimal($text($xp, './n:vItem', $det));

        if (!rtc_close($vUf, rtc_round2($base * $pUf / 100))) throw new RuntimeException("Item {$nItem}: vIBSUF não fecha com vBC × pIBSUF.");
        if (!rtc_close($vMun, rtc_round2($base * $pMun / 100))) throw new RuntimeException("Item {$nItem}: vIBSMun não fecha com vBC × pIBSMun.");
        if (!rtc_close($vCbs, rtc_round2($base * $pCbs / 100))) throw new RuntimeException("Item {$nItem}: vCBS não fecha com vBC × pCBS.");
        if (!rtc_close($vIbs, rtc_round2($vUf + $vMun))) throw new RuntimeException("Item {$nItem}: vIBS não fecha com UF + Município.");

        // Ordem: imposto, depois vItem.
        $order = [];
        foreach ($det->childNodes as $child) if ($child instanceof DOMElement) $order[] = $child->localName;
        $posImp = array_search('imposto', $order, true);
        $posItem = array_search('vItem', $order, true);
        if ($posImp === false || $posItem === false || $posItem <= $posImp) throw new RuntimeException("Item {$nItem}: vItem deve vir após imposto.");

        $sumBase += $base; $sumIbsUf += $vUf; $sumIbsMun += $vMun; $sumIbs += $vIbs; $sumCbs += $vCbs; $sumVItem += $vItem;
    }

    if ($rtcCount === 0) throw new RuntimeException('Nenhum item contém o grupo IBSCBS.');

    $tot = $xp->query('//n:infNFe/n:total/n:IBSCBSTot');
    if (!$tot || $tot->length === 0) throw new RuntimeException('Total sem grupo IBSCBSTot.');
    $requiredTotal = [
        '//n:infNFe/n:total/n:IBSCBSTot/n:vBCIBSCBS' => 'vBCIBSCBS',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSUF/n:vDif' => 'gIBS/gIBSUF/vDif',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSUF/n:vDevTrib' => 'gIBS/gIBSUF/vDevTrib',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSUF/n:vIBSUF' => 'gIBS/gIBSUF/vIBSUF',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSMun/n:vDif' => 'gIBS/gIBSMun/vDif',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSMun/n:vDevTrib' => 'gIBS/gIBSMun/vDevTrib',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSMun/n:vIBSMun' => 'gIBS/gIBSMun/vIBSMun',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:vIBS' => 'gIBS/vIBS',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:vCredPres' => 'gIBS/vCredPres',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:vCredPresCondSus' => 'gIBS/vCredPresCondSus',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vDif' => 'gCBS/vDif',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vDevTrib' => 'gCBS/vDevTrib',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vCBS' => 'gCBS/vCBS',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vCredPres' => 'gCBS/vCredPres',
        '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vCredPresCondSus' => 'gCBS/vCredPresCondSus',
        '//n:infNFe/n:total/n:vNFTot' => 'vNFTot',
    ];
    foreach ($requiredTotal as $query => $name) if ($text($xp, $query) === '') throw new RuntimeException('Campo total RTC ausente: ' . $name . '.');

    $checks = [
        'vBCIBSCBS' => [$sumBase, rtc_decimal($text($xp, '//n:infNFe/n:total/n:IBSCBSTot/n:vBCIBSCBS'))],
        'vIBSUF total' => [$sumIbsUf, rtc_decimal($text($xp, '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSUF/n:vIBSUF'))],
        'vIBSMun total' => [$sumIbsMun, rtc_decimal($text($xp, '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:gIBSMun/n:vIBSMun'))],
        'vIBS total' => [$sumIbs, rtc_decimal($text($xp, '//n:infNFe/n:total/n:IBSCBSTot/n:gIBS/n:vIBS'))],
        'vCBS total' => [$sumCbs, rtc_decimal($text($xp, '//n:infNFe/n:total/n:IBSCBSTot/n:gCBS/n:vCBS'))],
        'vNFTot' => [$sumVItem, rtc_decimal($text($xp, '//n:infNFe/n:total/n:vNFTot'))],
    ];
    foreach ($checks as $name => [$expected, $actual]) if (!rtc_close(rtc_round2($expected), rtc_round2($actual))) throw new RuntimeException("{$name} não fecha: itens=" . number_format($expected, 2, '.', '') . ' total=' . number_format($actual, 2, '.', '') . '.');

    // Validação XSD, quando o pacote NFePHP estiver disponível.
    $scheme = '';
    $autoload = fiscal_nfce_nfephp_autoload_path();
    if (is_file($autoload)) {
        require_once $autoload;
        $scheme = fiscal_nfce_nfephp_rtc_scheme();
        $schema = $root . '/vendor/nfephp-org/sped-nfe/schemes/' . $scheme . '/nfe_v4.00.xsd';
        if (!is_file($schema)) throw new RuntimeException('XSD nfe_v4.00.xsd não encontrado em ' . $scheme . '.');
        \NFePHP\Common\Validator::isValid($xml, $schema);
    }

    $cStat = $text($xp, '//n:protNFe/n:infProt/n:cStat');
    $xMotivo = $text($xp, '//n:protNFe/n:infProt/n:xMotivo');
    echo "[OK] Estrutura e cálculos RTC conferidos.\n";
    echo "Documento: {$label}\n";
    echo "Modelo: {$model}; itens RTC: {$rtcCount}\n";
    echo 'Base IBS/CBS: ' . number_format($sumBase, 2, ',', '.') . "\n";
    echo 'IBS UF: ' . number_format($sumIbsUf, 2, ',', '.') . '; IBS Município: ' . number_format($sumIbsMun, 2, ',', '.') . '; CBS: ' . number_format($sumCbs, 2, ',', '.') . "\n";
    if ($scheme !== '') echo "Schema: {$scheme}\n";
    if ($cStat !== '') echo "Protocolo: cStat={$cStat}" . ($xMotivo !== '' ? " - {$xMotivo}" : '') . "\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
