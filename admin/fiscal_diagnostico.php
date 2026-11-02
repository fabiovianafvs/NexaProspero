<?php
declare(strict_types=1);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');
error_reporting(E_ALL);
register_shutdown_function(function () {
  $e = error_get_last();
  if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
    http_response_code(500);
    echo '<pre style="white-space:pre-wrap;background:#111827;color:#fff;padding:16px;border-radius:12px">ERRO FATAL PHP\n';
    echo htmlspecialchars(($e['message'] ?? '') . "\nArquivo: " . ($e['file'] ?? '') . "\nLinha: " . ($e['line'] ?? ''), ENT_QUOTES, 'UTF-8');
    echo '</pre>';
  }
});

try {
  require_once __DIR__ . '/_bootstrap.php';
  require_once __DIR__ . '/../config/fiscal_operational.php';
  echo '<!doctype html><meta charset="utf-8"><title>Diagnóstico Fiscal</title>';
  echo '<body style="font-family:Arial;padding:24px;background:#f8fafc;color:#0f172a">';
  echo '<h1>Diagnóstico Fiscal</h1>';
  echo '<p><b>PHP:</b> ' . htmlspecialchars(PHP_VERSION, ENT_QUOTES, 'UTF-8') . '</p>';
  echo '<p><b>Empresa:</b> ' . (defined('COMPANY_ID') ? (int)COMPANY_ID : 0) . '</p>';
  echo '<h2>Tabelas operacionais</h2>';
  echo '<table border="1" cellpadding="8" cellspacing="0" style="border-collapse:collapse;background:#fff"><tr><th>Tabela</th><th>Status</th><th>Registros</th></tr>';
  foreach (fiscal_op_schema_status($pdo) as $r) {
    echo '<tr><td>' . htmlspecialchars((string)$r['table'], ENT_QUOTES, 'UTF-8') . '</td><td>' . (!empty($r['exists']) ? 'OK' : 'AUSENTE') . '</td><td>' . ($r['count'] === null ? '-' : (int)$r['count']) . '</td></tr>';
  }
  echo '</table>';
  echo '<p style="margin-top:18px"><a href="fiscal_configuracoes.php">Abrir Configurações Fiscais</a></p>';
  echo '</body>';
} catch (Throwable $e) {
  http_response_code(500);
  echo '<pre style="white-space:pre-wrap;background:#111827;color:#fff;padding:16px;border-radius:12px">ERRO NO DIAGNÓSTICO\n';
  echo htmlspecialchars($e->getMessage() . "\nArquivo: " . $e->getFile() . "\nLinha: " . $e->getLine(), ENT_QUOTES, 'UTF-8');
  echo '</pre>';
}
