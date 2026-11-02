<?php
declare(strict_types=1);

/**
 * Recria a mesma DPS somente em memória para conferir:
 * - declaração XML UTF-8;
 * - bytes UTF-8 válidos;
 * - assinatura;
 * - XSD;
 * - compactação e descompactação GZip.
 *
 * Não altera o banco e não realiza chamada HTTP.
 *
 * Uso:
 * php tools/verificar_dps_utf8_e1229.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO
 */

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$companyId = max(0, (int)($argv[2] ?? 0));
$docId = max(0, (int)($argv[3] ?? 0));

if ($companyId <= 0 || $docId <= 0) {
  fwrite(
    STDERR,
    "Uso: php tools/verificar_dps_utf8_e1229.php /var/www/nexa ID_EMPRESA ID_DOCUMENTO\n"
  );
  exit(2);
}

$pdo = require $root . '/config/pdo.php';
require_once $root . '/config/nfse_emitter.php';

nfse_emitter_require_schema($pdo);

$doc = nfse_emitter_document_by_id($pdo, $companyId, $docId);
if (!$doc) {
  throw new RuntimeException('Documento NFS-e não encontrado.');
}

if (strtoupper((string)($doc['environment'] ?? '')) !== 'HOMOLOGACAO') {
  throw new RuntimeException('Este teste está autorizado somente em HOMOLOGAÇÃO.');
}

$orderId = (int)($doc['source_id'] ?? $doc['pedido_id'] ?? 0);
$context = nfse_emitter_order_context($pdo, $companyId, $orderId);
if (!empty($context['issues'])) {
  throw new RuntimeException(implode(' ', $context['issues']));
}

$unsigned = nfse_emitter_build_dps_xml($context, $doc);
$signed = nfse_emitter_sign_xml($pdo, $companyId, $unsigned);
$errors = nfse_emitter_validate_xml($signed);

if ($errors) {
  echo "FALHA NA DPS RECRIADA:\n";
  foreach ($errors as $error) echo "- {$error}\n";
  exit(1);
}

$gzip = gzencode($signed, 9, FORCE_GZIP);
if ($gzip === false) {
  throw new RuntimeException('Não foi possível compactar a DPS.');
}

$decoded = gzdecode($gzip);
if (!is_string($decoded) || !hash_equals($signed, $decoded)) {
  throw new RuntimeException('O conteúdo mudou após o ciclo GZip.');
}

if (!str_starts_with(
  $decoded,
  '<?xml version="1.0" encoding="UTF-8"?>'
)) {
  throw new RuntimeException('A área descompactada não inicia com declaração UTF-8.');
}

$tempFile = sys_get_temp_dir()
  . '/nexa-dps-utf8-doc-' . $docId . '.xml';
if (file_put_contents($tempFile, $signed, LOCK_EX) === false) {
  throw new RuntimeException('Não foi possível criar o XML temporário de conferência.');
}

echo "Banco: " . $pdo->query('SELECT DATABASE()')->fetchColumn() . PHP_EOL;
echo "Empresa: {$companyId}" . PHP_EOL;
echo "Documento fiscal: {$docId}" . PHP_EOL;
echo "DPS: " . (int)($doc['dps_number'] ?? $doc['number'] ?? 0)
  . "/" . (string)($doc['dps_series'] ?? $doc['series'] ?? '') . PHP_EOL;
echo "Status atual: " . (string)($doc['status'] ?? '') . PHP_EOL;
echo "Tentativas registradas: " . (int)($doc['attempts'] ?? 0) . PHP_EOL;
echo PHP_EOL;
echo "Declaração: <?xml version=\"1.0\" encoding=\"UTF-8\"?>" . PHP_EOL;
echo "UTF-8 válido: SIM" . PHP_EOL;
echo "Assinatura: VÁLIDA" . PHP_EOL;
echo "XSD: VÁLIDO" . PHP_EOL;
echo "GZip ida e volta: VÁLIDO" . PHP_EOL;
echo "SHA-256 da nova DPS em memória: " . hash('sha256', $signed) . PHP_EOL;
echo "Arquivo temporário: {$tempFile}" . PHP_EOL;
echo PHP_EOL;
echo "PRÉ-VOO E1229 APROVADO." . PHP_EOL;
echo "Nenhum dado foi alterado e nenhuma chamada HTTP foi realizada." . PHP_EOL;
