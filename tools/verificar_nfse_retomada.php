<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execute somente pelo terminal.\n");
}

$root = rtrim((string)($argv[1] ?? dirname(__DIR__)), '/\\');
$expectedDb = trim((string)($argv[2] ?? ''));
$companyId = max(1, (int)($argv[3] ?? 1));

if (!is_file($root . '/config/pdo.php')) {
  fwrite(STDERR, "config/pdo.php não encontrado em {$root}\n");
  exit(2);
}

try {
  /** @var PDO $pdo */
  $pdo = require $root . '/config/pdo.php';
  require_once $root . '/config/nfse_runtime_env.php';
  require_once $root . '/config/nfse_emitter.php';

  $currentDb = (string)$pdo->query('SELECT DATABASE()')->fetchColumn();
  echo "Banco conectado: {$currentDb}\n";
  if ($expectedDb !== '' && strcasecmp($currentDb, $expectedDb) !== 0) {
    throw new RuntimeException("Banco divergente. Esperado: {$expectedDb}; conectado: {$currentDb}. Nenhuma migração deve ser executada.");
  }

  $technicalErrors = [];
  if (!function_exists('fiscal_normalize_item_type')) {
    $technicalErrors[] = 'Função fiscal_normalize_item_type não foi carregada.';
  }
  foreach (nfse_emitter_runtime_issues(true) as $issue) $technicalErrors[] = $issue;
  foreach (nfse_emitter_schema_issues($pdo) as $issue) $technicalErrors[] = $issue;

  $xsd = trim((string)(getenv('NFSE_DPS_XSD_PATH') ?: ''));
  echo 'XSD configurado: ' . ($xsd !== '' ? $xsd : '[NÃO]') . "\n";
  if ($xsd === '' || !is_file($xsd) || !is_readable($xsd)) {
    $technicalErrors[] = 'NFSE_DPS_XSD_PATH ausente ou ilegível.';
  }

  echo 'Produção liberada: ' . (nfse_emitter_production_enabled() ? 'SIM (REVISAR)' : 'NÃO') . "\n";
  if (nfse_emitter_production_enabled()) $technicalErrors[] = 'NFSE_ENABLE_PRODUCTION está ativo durante a retomada.';

  echo "\nChecklist cadastral da empresa {$companyId}:\n";
  try {
    $readiness = nfse_nacional_readiness($pdo, $companyId);
    foreach (($readiness['checks'] ?? []) as $check) {
      echo (!empty($check['ok']) ? '[OK] ' : '[PENDENTE] ') . (string)($check['label'] ?? '') . ' - ' . (string)($check['detail'] ?? '') . "\n";
    }
  } catch (Throwable $e) {
    $message = 'Não foi possível montar o checklist cadastral: ' . $e->getMessage();
    echo '[BLOQUEIO] ' . $message . "\n";
    $technicalErrors[] = $message;
  }

  if ($technicalErrors) {
    echo "\nBLOQUEIOS TÉCNICOS:\n";
    foreach (array_values(array_unique($technicalErrors)) as $error) echo "- {$error}\n";
    exit(10);
  }

  echo "\nPRÉ-VOO TÉCNICO APROVADO. A emissão deve continuar em HOMOLOGAÇÃO.\n";
} catch (Throwable $e) {
  fwrite(STDERR, "ERRO: {$e->getMessage()}\n");
  exit(3);
}
