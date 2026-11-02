<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/fiscal_operational.php';

function v266_assert_close(float $expected, float $actual, string $label): void {
  if (abs($expected - $actual) > 0.001) {
    throw new RuntimeException($label . ': esperado ' . $expected . ', obtido ' . $actual);
  }
}

try {
  $rate = [
    'federal_national' => 12.34,
    'federal_imported' => 18.45,
    'state_rate' => 17.00,
    'municipal_rate' => 0.00,
  ];
  $national = fiscal_nfce_ibpt_item_values(100.00, $rate, '0');
  v266_assert_close(12.34, (float)$national['federal'], 'Federal nacional');
  v266_assert_close(17.00, (float)$national['state'], 'Estadual');
  v266_assert_close(29.34, (float)$national['total'], 'Total nacional');

  $imported = fiscal_nfce_ibpt_item_values(100.00, $rate, '1');
  v266_assert_close(18.45, (float)$imported['federal'], 'Federal importado');
  v266_assert_close(35.45, (float)$imported['total'], 'Total importado');

  if (!fiscal_nfce_ibpt_is_imported_origin('7') || fiscal_nfce_ibpt_is_imported_origin('0')) {
    throw new RuntimeException('Classificação de origem nacional/importada inválida.');
  }

  echo "[OK] Cálculo IBPT nacional validado: R$ " . number_format((float)$national['total'], 2, ',', '.') . PHP_EOL;
  echo "[OK] Cálculo IBPT importado validado: R$ " . number_format((float)$imported['total'], 2, ',', '.') . PHP_EOL;
  echo "[OK] Nexa Fiscal v2.6.6 — teste concluído." . PHP_EOL;
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
