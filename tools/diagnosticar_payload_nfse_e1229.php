<?php
declare(strict_types=1);

/**
 * Diagnóstico exato do conteúdo enviado no último POST /nfse.
 *
 * Não altera banco, não assina, não compacta novamente e não realiza HTTP.
 *
 * Uso:
 * php tools/diagnosticar_payload_nfse_e1229.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO
 */

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$companyId = max(0, (int)($argv[2] ?? 0));
$docId = max(0, (int)($argv[3] ?? 0));

if ($companyId <= 0 || $docId <= 0) {
    fwrite(STDERR, "Uso: php tools/diagnosticar_payload_nfse_e1229.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO\n");
    exit(2);
}

$pdo = require $root . '/config/pdo.php';
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$sql = "SELECT d.id,
               d.company_id,
               d.status AS document_status,
               d.xml_path,
               d.xml_raw,
               d.xml_hash,
               nd.status AS nfse_status,
               nd.dps_id,
               nd.dps_series,
               nd.dps_number,
               nd.dps_xml,
               nd.signed_dps_xml,
               nd.request_payload,
               nd.request_hash,
               nd.response_payload,
               nd.api_http_status,
               nd.api_error_code,
               nd.api_error_message,
               nd.attempts,
               nd.last_attempt_at
          FROM fiscal_documents d
     LEFT JOIN fiscal_nfse_details nd
            ON nd.company_id=d.company_id
           AND nd.fiscal_document_id=d.id
         WHERE d.company_id=?
           AND d.id=?
           AND d.model='NFSE'
         LIMIT 1";

$st = $pdo->prepare($sql);
$st->execute([$companyId, $docId]);
$row = $st->fetch(PDO::FETCH_ASSOC);
if (!$row) {
    throw new RuntimeException('Documento NFS-e não encontrado.');
}

function diag_bytes(string $label, string $value): array {
    $length = strlen($value);
    $hash = hash('sha256', $value);
    $bom = str_starts_with($value, "\xEF\xBB\xBF");
    $declaration = preg_match('/^<\?xml\s+version=["\']1\.0["\']\s+encoding=["\']UTF-8["\']\s*\?>/i', $value) === 1;
    $utf8 = $value !== '' && preg_match('//u', $value) === 1;
    $control = preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', $value) === 1;
    $prefixBytes = strtoupper(implode(' ', str_split(bin2hex(substr($value, 0, 64)), 2)));
    $prefixText = substr($value, 0, 120);
    $prefixText = str_replace(["\r", "\n", "\t"], ['\\r', '\\n', '\\t'], $prefixText);

    $xmlValid = false;
    $xmlError = '';
    if ($value !== '') {
        libxml_use_internal_errors(true);
        $dom = new DOMDocument('1.0', 'UTF-8');
        $xmlValid = $dom->loadXML($value, LIBXML_NONET | LIBXML_NOBLANKS);
        if (!$xmlValid) {
            $messages = [];
            foreach (libxml_get_errors() as $error) {
                $messages[] = trim($error->message);
            }
            $xmlError = implode(' | ', array_unique($messages));
        }
        libxml_clear_errors();
    }

    echo "\n=== {$label} ===\n";
    echo "Tamanho: {$length} bytes\n";
    echo "SHA-256: {$hash}\n";
    echo 'BOM UTF-8: ' . ($bom ? 'SIM' : 'NÃO') . "\n";
    echo 'Declaração UTF-8: ' . ($declaration ? 'SIM' : 'NÃO') . "\n";
    echo 'Bytes UTF-8 válidos: ' . ($utf8 ? 'SIM' : 'NÃO') . "\n";
    echo 'Caracteres de controle inválidos: ' . ($control ? 'SIM' : 'NÃO') . "\n";
    echo 'XML bem-formado: ' . ($xmlValid ? 'SIM' : 'NÃO') . "\n";
    if ($xmlError !== '') echo "Erro XML: {$xmlError}\n";
    echo "Primeiros bytes: {$prefixBytes}\n";
    echo "Início textual: {$prefixText}\n";

    return [
        'length' => $length,
        'hash' => $hash,
        'bom' => $bom,
        'declaration' => $declaration,
        'utf8' => $utf8,
        'control' => $control,
        'xml_valid' => $xmlValid,
    ];
}

function diag_write(string $path, string $content): void {
    if ($content === '') return;
    if (file_put_contents($path, $content, LOCK_EX) === false) {
        throw new RuntimeException('Não foi possível gravar: ' . $path);
    }
    @chmod($path, 0600);
}

echo "Banco: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . "\n";
echo "Empresa: {$companyId}\n";
echo "Documento fiscal: {$docId}\n";
echo 'DPS: ' . (string)($row['dps_number'] ?? '') . '/' . (string)($row['dps_series'] ?? '') . "\n";
echo 'Status: ' . (string)($row['nfse_status'] ?? $row['document_status'] ?? '') . "\n";
echo 'Tentativas: ' . (int)($row['attempts'] ?? 0) . "\n";
echo 'Último HTTP: ' . (int)($row['api_http_status'] ?? 0) . "\n";
echo 'Erro: ' . (string)($row['api_error_code'] ?? '') . ' - ' . (string)($row['api_error_message'] ?? '') . "\n";
echo 'Última tentativa: ' . (string)($row['last_attempt_at'] ?? '') . "\n";

$xmlRaw = (string)($row['xml_raw'] ?? '');
$signed = (string)($row['signed_dps_xml'] ?? '');
$dpsXml = (string)($row['dps_xml'] ?? '');
$requestPayload = (string)($row['request_payload'] ?? '');

$payloadXml = '';
$payloadBase64 = '';
$payloadGzip = '';
$payloadJsonError = '';

if ($requestPayload !== '') {
    $json = json_decode($requestPayload, true);
    if (!is_array($json)) {
        $payloadJsonError = json_last_error_msg();
    } else {
        $payloadBase64 = (string)($json['dpsXmlGZipB64'] ?? $json['DpsXmlGZipB64'] ?? '');
        if ($payloadBase64 !== '') {
            $raw = base64_decode($payloadBase64, true);
            if (is_string($raw)) {
                $payloadGzip = $raw;
                $decoded = @gzdecode($raw);
                if (is_string($decoded)) $payloadXml = $decoded;
            }
        }
    }
}

$diskXml = '';
$relativePath = trim((string)($row['xml_path'] ?? ''));
$absolutePath = $relativePath !== ''
    ? $root . '/' . ltrim($relativePath, '/\\')
    : '';
if ($absolutePath !== '' && is_file($absolutePath)) {
    $read = file_get_contents($absolutePath);
    if (is_string($read)) $diskXml = $read;
}

$results = [];
$results['fiscal_documents.xml_raw'] = diag_bytes('fiscal_documents.xml_raw', $xmlRaw);
$results['fiscal_nfse_details.signed_dps_xml'] = diag_bytes('fiscal_nfse_details.signed_dps_xml', $signed);
$results['fiscal_nfse_details.dps_xml (sem assinatura)'] = diag_bytes('fiscal_nfse_details.dps_xml (sem assinatura)', $dpsXml);

if ($payloadJsonError !== '') {
    echo "\nERRO AO LER request_payload: {$payloadJsonError}\n";
} elseif ($requestPayload === '') {
    echo "\nrequest_payload está vazio.\n";
} elseif ($payloadBase64 === '') {
    echo "\nrequest_payload não contém dpsXmlGZipB64.\n";
} elseif ($payloadGzip === '') {
    echo "\nO Base64 de request_payload é inválido.\n";
} elseif ($payloadXml === '') {
    echo "\nO GZip de request_payload não pôde ser descompactado.\n";
} else {
    echo "\n=== request_payload ===\n";
    echo 'JSON: ' . strlen($requestPayload) . " bytes\n";
    echo 'Base64: ' . strlen($payloadBase64) . " caracteres\n";
    echo 'GZip: ' . strlen($payloadGzip) . " bytes\n";
    echo 'SHA-256 GZip: ' . hash('sha256', $payloadGzip) . "\n";
    $results['XML EXATO DESCOMPACTADO DO ÚLTIMO POST'] = diag_bytes(
        'XML EXATO DESCOMPACTADO DO ÚLTIMO POST',
        $payloadXml
    );
}

if ($absolutePath !== '') {
    echo "\nCaminho do XML no disco: {$absolutePath}\n";
    if ($diskXml !== '') {
        $results['XML do disco'] = diag_bytes('XML do disco', $diskXml);
    } else {
        echo "Arquivo do disco não encontrado ou vazio.\n";
    }
}

$tmpBase = '/tmp/nexa_nfse_e1229_doc_' . $docId;
diag_write($tmpBase . '_xml_raw.xml', $xmlRaw);
diag_write($tmpBase . '_signed_db.xml', $signed);
diag_write($tmpBase . '_unsigned_db.xml', $dpsXml);
diag_write($tmpBase . '_request.json', $requestPayload);
diag_write($tmpBase . '_payload.xml', $payloadXml);
diag_write($tmpBase . '_payload.gz', $payloadGzip);
diag_write($tmpBase . '_disk.xml', $diskXml);

echo "\n=== COMPARAÇÕES ===\n";
function diag_compare(string $aName, string $a, string $bName, string $b): void {
    if ($a === '' || $b === '') {
        echo "{$aName} x {$bName}: NÃO COMPARADO (conteúdo vazio)\n";
        return;
    }
    echo "{$aName} x {$bName}: " . (hash_equals($a, $b) ? 'IDÊNTICOS' : 'DIFERENTES') . "\n";
}
diag_compare('xml_raw', $xmlRaw, 'signed_dps_xml', $signed);
diag_compare('xml_raw', $xmlRaw, 'payload descompactado', $payloadXml);
diag_compare('signed_dps_xml', $signed, 'payload descompactado', $payloadXml);
diag_compare('XML do disco', $diskXml, 'payload descompactado', $payloadXml);

echo "\nArquivos técnicos criados em /tmp:\n";
foreach (glob($tmpBase . '_*') ?: [] as $file) {
    echo '- ' . $file . ' (' . filesize($file) . " bytes)\n";
}

echo "\nDIAGNÓSTICO CONCLUÍDO. Nenhum dado foi alterado e nenhuma chamada HTTP foi realizada.\n";
