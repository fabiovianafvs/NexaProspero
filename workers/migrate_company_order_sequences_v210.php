<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por CLI.\n");
}

$pdo = require __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_sequences.php';

const NEXA_ORDER_SEQUENCE_VERSION = '2.10.0';
const NEXA_ORDER_SEQUENCE_MODULE = 'company_order_sequences';

function v210_arg(string $name): ?string {
  foreach (array_slice($_SERVER['argv'] ?? [], 1) as $arg) {
    if ($arg === '--' . $name) return '1';
    if (str_starts_with($arg, '--' . $name . '=')) return substr($arg, strlen($name) + 3);
  }
  return null;
}

function v210_index_exists(PDO $pdo, string $table, string $index): bool {
  $st = $pdo->prepare('SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1');
  $st->execute([$table, $index]);
  return (bool)$st->fetchColumn();
}

function v210_schema_version(PDO $pdo): ?string {
  try {
    $st = $pdo->prepare('SELECT version_number FROM nexa_schema_versions WHERE module_name = ? LIMIT 1');
    $st->execute([NEXA_ORDER_SEQUENCE_MODULE]);
    $v = $st->fetchColumn();
    return $v === false ? null : (string)$v;
  } catch (Throwable $e) {
    return null;
  }
}

$companyFilter = max(0, (int)(v210_arg('company') ?? 0));
$dryRun = v210_arg('dry-run') !== null;
$force = v210_arg('force') !== null;

echo "NEXA v2.10.0 - SEQUÊNCIA DE PEDIDOS POR EMPRESA\n";
echo str_repeat('=', 78) . "\n";

company_sequence_ensure_schema($pdo, false);

$pdo->exec("CREATE TABLE IF NOT EXISTS company_order_number_backup_v210 (
  pedido_id BIGINT UNSIGNED NOT NULL,
  company_id INT UNSIGNED NOT NULL,
  old_numero_pedido INT UNSIGNED NULL,
  backed_up_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (pedido_id),
  KEY idx_v210_company (company_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$pdo->exec("CREATE TABLE IF NOT EXISTS nexa_schema_versions (
  module_name VARCHAR(80) NOT NULL,
  version_number VARCHAR(30) NOT NULL,
  applied_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  notes VARCHAR(255) NULL,
  PRIMARY KEY (module_name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

$currentVersion = v210_schema_version($pdo);
if (!$dryRun && !$force && $companyFilter === 0 && $currentVersion === NEXA_ORDER_SEQUENCE_VERSION) {
  echo "[OK] Migração já aplicada na versão " . NEXA_ORDER_SEQUENCE_VERSION . ".\n";
  echo "Use --force somente se precisar reconstruir novamente a numeração visível.\n";
  exit(0);
}

$where = $companyFilter > 0 ? 'WHERE id = ?' : '';
$stCompanies = $pdo->prepare("SELECT id, name FROM companies {$where} ORDER BY id ASC");
$stCompanies->execute($companyFilter > 0 ? [$companyFilter] : []);
$companies = $stCompanies->fetchAll(PDO::FETCH_ASSOC) ?: [];

if (!$companies) {
  throw new RuntimeException('Nenhuma empresa encontrada para processar.');
}

if ($dryRun) {
  $stInfo = $pdo->prepare('SELECT COUNT(*) qtd, MIN(numero_pedido) minimo, MAX(numero_pedido) maximo, SUM(numero_pedido IS NULL OR numero_pedido = 0) sem_numero FROM pedidos WHERE company_id = ?');
  foreach ($companies as $company) {
    $stInfo->execute([(int)$company['id']]);
    $info = $stInfo->fetch(PDO::FETCH_ASSOC) ?: [];
    printf(
      "Empresa %d - %s: pedidos=%d, mínimo=%s, máximo=%s, sem número=%d\n",
      (int)$company['id'],
      (string)$company['name'],
      (int)($info['qtd'] ?? 0),
      $info['minimo'] === null ? '-' : (string)$info['minimo'],
      $info['maximo'] === null ? '-' : (string)$info['maximo'],
      (int)($info['sem_numero'] ?? 0)
    );
  }
  echo "\n[DRY-RUN] Nenhum registro foi alterado.\n";
  exit(0);
}

// Remove apenas o índice criado por esta evolução, caso exista após tentativa parcial.
if (v210_index_exists($pdo, 'pedidos', 'uq_pedidos_company_numero')) {
  $pdo->exec('ALTER TABLE pedidos DROP INDEX uq_pedidos_company_numero');
}

$stOrders = $pdo->prepare('SELECT id, numero_pedido FROM pedidos WHERE company_id = ? ORDER BY id ASC');
$stBackup = $pdo->prepare('INSERT IGNORE INTO company_order_number_backup_v210 (pedido_id, company_id, old_numero_pedido) VALUES (?, ?, ?)');
$stUpdate = $pdo->prepare('UPDATE pedidos SET numero_pedido = ? WHERE company_id = ? AND id = ?');
$stSeq = $pdo->prepare("INSERT INTO company_sequences (company_id, entity, next_value)
  VALUES (?, 'pedidos', ?)
  ON DUPLICATE KEY UPDATE next_value = VALUES(next_value)");

$totalOrders = 0;
foreach ($companies as $company) {
  $companyId = (int)$company['id'];
  $pdo->beginTransaction();
  try {
    $stOrders->execute([$companyId]);
    $orders = $stOrders->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $number = 1;
    foreach ($orders as $order) {
      $stBackup->execute([(int)$order['id'], $companyId, $order['numero_pedido'] !== null ? (int)$order['numero_pedido'] : null]);
      $stUpdate->execute([$number, $companyId, (int)$order['id']]);
      $number++;
    }
    $stSeq->execute([$companyId, $number]);
    company_sequence_initialize_company($pdo, $companyId);
    $pdo->commit();
    $count = count($orders);
    $totalOrders += $count;
    printf("[OK] Empresa %d - %s: %d pedido(s), próxima sequência %d.\n", $companyId, (string)$company['name'], $count, $number);
  } catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    throw new RuntimeException('Falha na empresa ' . $companyId . ': ' . $e->getMessage(), 0, $e);
  }
}

// Índice composto: números podem repetir em empresas distintas, nunca na mesma empresa.
if ($companyFilter === 0) {
  $pdo->exec('ALTER TABLE pedidos ADD UNIQUE KEY uq_pedidos_company_numero (company_id, numero_pedido)');
} else {
  echo "[AVISO] Execução restrita a uma empresa: o índice global será criado somente na migração sem --company.\n";
}

if ($companyFilter === 0) {
  $stVersion = $pdo->prepare("INSERT INTO nexa_schema_versions (module_name, version_number, applied_at, notes)
    VALUES (?, ?, NOW(), ?)
    ON DUPLICATE KEY UPDATE version_number = VALUES(version_number), applied_at = NOW(), notes = VALUES(notes)");
  $stVersion->execute([
    NEXA_ORDER_SEQUENCE_MODULE,
    NEXA_ORDER_SEQUENCE_VERSION,
    'Pedidos renumerados de forma independente por empresa; primeira sequência = 1.'
  ]);
}

echo str_repeat('-', 78) . "\n";
echo "[OK] Empresas processadas: " . count($companies) . "\n";
echo "[OK] Pedidos renumerados: {$totalOrders}\n";
echo $companyFilter === 0 ? "[OK] Índice único por empresa verificado.\n" : "[AVISO] Índice único não alterado nesta execução parcial.\n";
echo "[OK] Nexa v2.10.0 concluído.\n";
