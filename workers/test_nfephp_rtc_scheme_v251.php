<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/fiscal_operational.php';

try {
    $scheme = fiscal_nfce_nfephp_rtc_scheme();
    $schemeDir = $root . '/vendor/nfephp-org/sped-nfe/schemes/' . $scheme;
    $layout = $schemeDir . '/leiauteNFe_v4.00.xsd';
    $envelope = $schemeDir . '/enviNFe_v4.00.xsd';

    if (!is_file($layout) || !is_file($envelope)) {
        throw new RuntimeException('Arquivos XSD principais não encontrados no pacote detectado.');
    }

    $xsd = file_get_contents($layout);
    if (!is_string($xsd) || $xsd === '') {
        throw new RuntimeException('Não foi possível ler o leiauteNFe_v4.00.xsd.');
    }

    $markers = [
        'IBSCBS no item' => 'name="IBSCBS"',
        'vItem no item' => 'name="vItem"',
        'IBSCBSTot nos totais' => 'name="IBSCBSTot"',
        'vNFTot nos totais' => 'name="vNFTot"',
    ];
    foreach ($markers as $label => $marker) {
        if (!str_contains($xsd, $marker)) {
            throw new RuntimeException("Campo RTC ausente no schema detectado: {$label}.");
        }
    }

    $configFunction = new ReflectionFunction('fiscal_nfce_nfephp_config_json');
    $sourceFile = (string)$configFunction->getFileName();
    $source = is_file($sourceFile) ? (string)file_get_contents($sourceFile) : '';
    if (str_contains($source, "'schemes' => 'PL_009_V4'")) {
        throw new RuntimeException('Ainda existe configuração fixa PL_009_V4 no gerador fiscal.');
    }

    echo "[OK] Schema RTC detectado: {$scheme}\n";
    echo "[OK] XSD contém IBSCBS, vItem, IBSCBSTot e vNFTot.\n";
    echo "[OK] O gerador não está mais fixado em PL_009_V4.\n";
} catch (Throwable $e) {
    fwrite(STDERR, "[ERRO] " . $e->getMessage() . "\n");
    exit(1);
}
