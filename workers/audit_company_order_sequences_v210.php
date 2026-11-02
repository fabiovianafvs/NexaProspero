<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por CLI.\n");
}

$pdo = require __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_sequences.php';

function audit_v210_arg(string $name): ?string {
  foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--' . $name . '=')) return substr($arg, strlen($name) + 3);
  }
  return null;
}

$companyFilter = max(0, (int)(audit_v210_arg('company') ?? 0));
company_sequence_ensure_schema($pdo, false);

$where = $companyFilter > 0 ? 'WHERE c.id = ?' : '';
$sql = "SELECT
  c.id,
  c.name,
  COUNT(p.id) AS pedidos,
  MIN(p.numero_pedido) AS primeiro,
  MAX(p.numero_pedido) AS ultimo,
  SUM(CASE WHEN p.id IS NOT NULL AND (p.numero_pedido IS NULL OR p.numero_pedido = 0) THEN 1 ELSE 0 END) AS sem_numero,
  COALESCE(cs.next_value, 1) AS proximo
FROM companies c
LEFT JOIN pedidos p ON p.company_id = c.id
LEFT JOIN company_sequences cs ON cs.company_id = c.id AND cs.entity = 'pedidos'
{$where}
GROUP BY c.id, c.name, cs.next_value
ORDER BY c.id";
$st = $pdo->prepare($sql);
$st->execute($companyFilter > 0 ? [$companyFilter] : []);
$rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

$dupSql = "SELECT company_id, numero_pedido, COUNT(*) qtd
FROM pedidos
WHERE numero_pedido IS NOT NULL AND numero_pedido > 0" . ($companyFilter > 0 ? ' AND company_id = ?' : '') . "
GROUP BY company_id, numero_pedido
HAVING COUNT(*) > 1
ORDER BY company_id, numero_pedido";
$dup = $pdo->prepare($dupSql);
$dup->execute($companyFilter > 0 ? [$companyFilter] : []);
$duplicates = $dup->fetchAll(PDO::FETCH_ASSOC) ?: [];

echo "NEXA v2.10.0 - AUDITORIA DAS SEQUÊNCIAS DE PEDIDOS\n";
echo str_repeat('=', 94) . "\n";
printf("%-6s %-34s %9s %9s %9s %10s %10s\n", 'ID', 'Empresa', 'Pedidos', 'Primeiro', 'Último', 'Próximo', 'Sem nº');
echo str_repeat('-', 94) . "\n";
foreach ($rows as $row) {
  printf(
    "%-6d %-34s %9d %9s %9s %10d %10d\n",
    (int)$row['id'],
    mb_strimwidth((string)$row['name'], 0, 34, '...'),
    (int)$row['pedidos'],
    $row['primeiro'] === null ? '-' : (string)$row['primeiro'],
    $row['ultimo'] === null ? '-' : (string)$row['ultimo'],
    (int)$row['proximo'],
    (int)$row['sem_numero']
  );
}

echo str_repeat('-', 94) . "\n";
if ($duplicates) {
  echo "[ERRO] Foram encontradas sequências duplicadas:\n";
  foreach ($duplicates as $row) {
    printf("  empresa=%d número=%d quantidade=%d\n", (int)$row['company_id'], (int)$row['numero_pedido'], (int)$row['qtd']);
  }
  exit(2);
}

$withoutNumber = array_sum(array_map(static fn(array $r): int => (int)$r['sem_numero'], $rows));
if ($withoutNumber > 0) {
  echo "[ATENÇÃO] Existem {$withoutNumber} pedido(s) sem número visível.\n";
  exit(3);
}

echo "[OK] Nenhuma duplicidade por empresa.\n";
echo "[OK] Todos os pedidos possuem número visível.\n";
echo "[OK] Empresas diferentes podem possuir o mesmo número de pedido.\n";
