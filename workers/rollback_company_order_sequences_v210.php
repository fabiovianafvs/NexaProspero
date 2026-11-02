<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit("Execução permitida somente por CLI.\n");
}

$pdo = require __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/company_sequences.php';

$confirm = in_array('--confirm', array_slice($_SERVER['argv'] ?? [], 1), true);
if (!$confirm) {
  echo "Este comando restaura os números visíveis anteriores armazenados em company_order_number_backup_v210.\n";
  echo "Para confirmar, execute novamente com --confirm.\n";
  exit(1);
}

$exists = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'company_order_number_backup_v210' LIMIT 1")->fetchColumn();
if (!$exists) throw new RuntimeException('Tabela de backup da v2.10.0 não encontrada.');

$idx = $pdo->query("SELECT 1 FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pedidos' AND INDEX_NAME = 'uq_pedidos_company_numero' LIMIT 1")->fetchColumn();
if ($idx) $pdo->exec('ALTER TABLE pedidos DROP INDEX uq_pedidos_company_numero');

$pdo->beginTransaction();
try {
  $pdo->exec("UPDATE pedidos p
    INNER JOIN company_order_number_backup_v210 b ON b.pedido_id = p.id AND b.company_id = p.company_id
    SET p.numero_pedido = b.old_numero_pedido");

  $companies = $pdo->query('SELECT id FROM companies ORDER BY id')->fetchAll(PDO::FETCH_COLUMN) ?: [];
  $stMax = $pdo->prepare('SELECT COALESCE(MAX(numero_pedido), 0) + 1 FROM pedidos WHERE company_id = ?');
  $stSeq = $pdo->prepare("INSERT INTO company_sequences (company_id, entity, next_value) VALUES (?, 'pedidos', ?)
    ON DUPLICATE KEY UPDATE next_value = VALUES(next_value)");
  foreach ($companies as $companyIdRaw) {
    $companyId = (int)$companyIdRaw;
    $stMax->execute([$companyId]);
    $stSeq->execute([$companyId, max(1, (int)$stMax->fetchColumn())]);
  }
  $pdo->prepare('DELETE FROM nexa_schema_versions WHERE module_name = ?')->execute(['company_order_sequences']);
  $pdo->commit();
} catch (Throwable $e) {
  if ($pdo->inTransaction()) $pdo->rollBack();
  throw $e;
}

echo "[OK] Números visíveis anteriores restaurados.\n";
echo "[OK] Sequências recalculadas a partir dos números restaurados.\n";
echo "[ATENÇÃO] O índice único da v2.10.0 foi removido durante o rollback.\n";
