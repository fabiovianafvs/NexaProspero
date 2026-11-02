<?php
declare(strict_types=1);

/**
 * Reassina uma DPS já preparada, mantendo o mesmo documento, série e número.
 *
 * Uso:
 * php tools/reassinar_dps_nfse.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO
 */

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$companyId = max(0, (int)($argv[2] ?? 0));
$docId = max(0, (int)($argv[3] ?? 0));

if ($companyId <= 0 || $docId <= 0) {
  fwrite(
    STDERR,
    "Uso: php tools/reassinar_dps_nfse.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO\n"
  );
  exit(2);
}

$pdo = require $root . '/config/pdo.php';
require_once $root . '/config/nfse_emitter.php';

nfse_emitter_require_schema($pdo);

$doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
if (!$doc) {
  throw new RuntimeException('Documento NFS-e não encontrado para esta empresa.');
}

if (strtoupper((string)($doc['model'] ?? '')) !== 'NFSE') {
  throw new RuntimeException('O documento informado não é uma NFS-e.');
}

$environment = strtoupper((string)($doc['environment'] ?? ''));
if ($environment !== 'HOMOLOGACAO') {
  throw new RuntimeException('A reassinatura automática está permitida somente em HOMOLOGAÇÃO.');
}

$status = strtoupper((string)($doc['status'] ?? ''));
if ($status === 'AUTORIZADA' || !empty($doc['access_key']) || !empty($doc['protocol_number'])) {
  throw new RuntimeException('Documento já autorizado ou protocolado. Nenhuma alteração foi feita.');
}

$attempts = (int)($doc['attempts'] ?? 0);
$httpStatus = (int)($doc['api_http_status'] ?? 0);
if ($attempts > 0 || $httpStatus > 0 || !empty($doc['last_attempt_at'])) {
  throw new RuntimeException(
    'O documento possui tentativa de transmissão registrada. '
    . 'Não é seguro reassiná-lo automaticamente.'
  );
}

$unsignedXml = trim((string)($doc['dps_xml'] ?? ''));
if ($unsignedXml === '') {
  throw new RuntimeException('O XML original sem assinatura não está armazenado.');
}

echo "Banco: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo "Empresa: {$companyId}" . PHP_EOL;
echo "Documento fiscal: {$docId}" . PHP_EOL;
echo "DPS: " . (int)($doc['dps_number'] ?? $doc['number'] ?? 0)
  . "/" . (string)($doc['dps_series'] ?? $doc['series'] ?? '') . PHP_EOL;
echo "Ambiente: {$environment}" . PHP_EOL;
echo "Tentativas anteriores: {$attempts}" . PHP_EOL;

$signedXml = nfse_emitter_sign_xml($pdo, $companyId, $unsignedXml);
$validationErrors = nfse_emitter_validate_xml($signedXml);
if ($validationErrors) {
  throw new RuntimeException(
    'A DPS reassinada não passou na validação: '
    . implode(' | ', $validationErrors)
  );
}

$path = nfse_emitter_store_xml_file(
  $companyId,
  $environment,
  $docId,
  'dps-assinada',
  $signedXml
);
$hash = hash('sha256', $signedXml);

$pdo->beginTransaction();
try {
  $pdo->prepare(
    "UPDATE fiscal_documents
        SET status='ASSINADA',
            xml_path=?,
            xml_raw=?,
            xml_hash=?,
            updated_at=NOW()
      WHERE company_id=? AND id=?"
  )->execute([$path, $signedXml, $hash, $companyId, $docId]);

  $pdo->prepare(
    "UPDATE fiscal_nfse_details
        SET status='ASSINADA',
            signed_dps_xml=?,
            request_hash=?,
            api_error_code=NULL,
            api_error_message=NULL,
            updated_at=NOW()
      WHERE company_id=? AND fiscal_document_id=?"
  )->execute([$signedXml, $hash, $companyId, $docId]);

  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}

echo PHP_EOL;
echo "DPS REASSINADA COM SUCESSO." . PHP_EOL;
echo "O documento, a série e o número da DPS foram preservados." . PHP_EOL;
echo "Nenhuma chamada HTTP foi realizada." . PHP_EOL;
echo "Arquivo: {$path}" . PHP_EOL;
echo "SHA-256: {$hash}" . PHP_EOL;
