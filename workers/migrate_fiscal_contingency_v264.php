<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/fiscal_operational.php';

try {
  fiscal_nfce_ensure_schema($pdo);
  $legacy = 0;
  try {
    $legacy = (int)$pdo->exec("UPDATE fiscal_documents
      SET emission_type='NFC_OFFLINE', contingency_started_at=COALESCE(contingency_started_at,issue_datetime,created_at),
          contingency_reason=COALESCE(NULLIF(TRIM(contingency_reason),''),'Documento legado identificado como contingência offline.'), updated_at=NOW()
      WHERE model='65' AND UPPER(status) IN ('CONTINGENCIA','EM_CONTINGENCIA','PENDENTE_CONTINGENCIA','OFFLINE')
        AND UPPER(COALESCE(emission_type,'NORMAL'))='NORMAL'");
  } catch (Throwable $e) {
    echo '[AVISO] Não foi possível normalizar documentos legados: ' . $e->getMessage() . PHP_EOL;
  }
  echo '[OK] Colunas de configuração de contingência criadas/verificadas.' . PHP_EOL;
  echo '[OK] Colunas de rastreabilidade nos documentos criadas/verificadas.' . PHP_EOL;
  echo '[OK] Documentos legados marcados como NFC_OFFLINE: ' . $legacy . PHP_EOL;
  $requiredExtensions = ['dom', 'openssl', 'curl', 'mbstring'];
  $missingExtensions = array_values(array_filter($requiredExtensions, static fn(string $ext): bool => !extension_loaded($ext)));
  if ($missingExtensions) {
    echo '[AVISO] Extensões PHP ausentes no CLI: ' . implode(', ', $missingExtensions) . '. Confira também o PHP do Apache/PHP-FPM antes de testar a SEFAZ.' . PHP_EOL;
  } else {
    echo '[OK] Extensões PHP essenciais disponíveis no CLI.' . PHP_EOL;
  }
  echo '[OK] Nexa Fiscal v2.6.4 concluído.' . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
