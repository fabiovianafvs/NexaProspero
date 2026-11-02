<?php
declare(strict_types=1);

/**
 * Verifica a assinatura de um XML de DPS usando os algoritmos declarados.
 *
 * Uso:
 * php tools/verificar_assinatura_dps.php /var/www/nexa /caminho/dps.xml
 */

$root = rtrim((string)($argv[1] ?? '/var/www/nexa'), '/');
$xmlPath = (string)($argv[2] ?? '');

if ($xmlPath === '' || !is_file($xmlPath)) {
  fwrite(
    STDERR,
    "Uso: php tools/verificar_assinatura_dps.php /var/www/nexa /caminho/dps.xml\n"
  );
  exit(2);
}

require_once $root . '/config/nfse_emitter.php';

$xml = file_get_contents($xmlPath);
if (!is_string($xml) || trim($xml) === '') {
  throw new RuntimeException('O arquivo XML está vazio ou não pôde ser lido.');
}

$errors = nfse_emitter_verify_declared_signature($xml);

if ($errors) {
  echo "ASSINATURA INVÁLIDA OU INCONSISTENTE:" . PHP_EOL;
  foreach ($errors as $error) echo "- {$error}" . PHP_EOL;
  exit(1);
}

echo "ASSINATURA XML VÁLIDA." . PHP_EOL;
echo "DigestValue e SignatureValue correspondem aos algoritmos declarados." . PHP_EOL;
