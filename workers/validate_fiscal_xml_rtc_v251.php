<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
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
    fwrite(STDERR, "  php workers/validate_fiscal_xml_rtc_v251.php --company=1 --doc=123\n");
    fwrite(STDERR, "  php workers/validate_fiscal_xml_rtc_v251.php --xml=/caminho/arquivo.xml\n");
    exit(2);
}

try {
    $xml = '';
    $label = '';
    if ($xmlFile !== '') {
        if (!is_file($xmlFile) || !is_readable($xmlFile)) {
            throw new RuntimeException('Arquivo XML não encontrado ou sem leitura: ' . $xmlFile);
        }
        $xml = (string)file_get_contents($xmlFile);
        $label = $xmlFile;
    } else {
        $st = $pdo->prepare('SELECT model, document_number, status, xml_raw FROM fiscal_documents WHERE company_id=? AND id=? LIMIT 1');
        $st->execute([$companyId, $docId]);
        $doc = $st->fetch(PDO::FETCH_ASSOC);
        if (!$doc) throw new RuntimeException('Documento fiscal não encontrado.');
        $xml = trim((string)($doc['xml_raw'] ?? ''));
        if ($xml === '') throw new RuntimeException('O documento ainda não possui XML gravado.');
        $label = sprintf('empresa=%d documento=%d modelo=%s número=%s status=%s', $companyId, $docId, (string)$doc['model'], (string)$doc['document_number'], (string)$doc['status']);
    }

    $autoload = fiscal_nfce_nfephp_autoload_path();
    if (!is_file($autoload)) throw new RuntimeException('Autoload do NFePHP não encontrado.');
    require_once $autoload;

    $scheme = fiscal_nfce_nfephp_rtc_scheme();
    $schema = $root . '/vendor/nfephp-org/sped-nfe/schemes/' . $scheme . '/nfe_v4.00.xsd';
    if (!is_file($schema)) throw new RuntimeException('XSD nfe_v4.00.xsd não encontrado em ' . $scheme . '.');

    \NFePHP\Common\Validator::isValid($xml, $schema);
    echo "[OK] XML válido no schema RTC.\n";
    echo "Documento: {$label}\n";
    echo "Schema: {$scheme}\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
