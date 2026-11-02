<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/order_cancellation.php';

$companyId = 0;
$orderId = 0;
$documentId = 0;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
  if (preg_match('/^--order=(\d+)$/', (string)$arg, $m)) $orderId = (int)$m[1];
  if (preg_match('/^--document=(\d+)$/', (string)$arg, $m)) $documentId = (int)$m[1];
}
if ($companyId <= 0) {
  fwrite(STDERR, "Uso: php audit_fiscal_order_cancellation_v268.php --company=1 [--order=123] [--document=456]\n");
  exit(2);
}

try {
  nexa_order_cancellation_ensure_schema($pdo);
  $where = ["fd.company_id=?", "UPPER(COALESCE(fd.status,''))='CANCELADA'", "fd.source_type='PEDIDO'", "fd.source_id IS NOT NULL"];
  $params = [$companyId];
  if ($orderId > 0) { $where[] = 'fd.source_id=?'; $params[] = $orderId; }
  if ($documentId > 0) { $where[] = 'fd.id=?'; $params[] = $documentId; }
  $sql = "SELECT fd.id document_id, fd.model, fd.series, fd.number, fd.source_id order_id,
      fd.internal_cancellation_status, fd.internal_cancellation_message,
      p.status order_status, p.canceled_at, p.cancel_reason,
      oce.status effect_status, oce.stock_reversed_qty, oce.finance_reversed_amount, oce.cash_reversed_amount, oce.error_message
    FROM fiscal_documents fd
    LEFT JOIN pedidos p ON p.company_id=fd.company_id AND p.id=fd.source_id
    LEFT JOIN order_cancellation_effects oce ON oce.company_id=fd.company_id AND oce.order_id=fd.source_id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY fd.id DESC LIMIT 500";
  $st = $pdo->prepare($sql); $st->execute($params);
  $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo "NEXA FISCAL v2.6.8 - AUDITORIA DE CANCELAMENTO INTEGRADO\n";
  echo str_repeat('=', 118) . "\n";
  echo "Empresa: {$companyId}\n";
  printf("%-8s %-6s %-8s %-9s %-12s %-12s %-10s %-12s %-12s\n", 'Doc.', 'Mod.', 'Número', 'Pedido', 'Doc interno', 'Pedido', 'Estoque', 'Financeiro', 'Caixa');
  echo str_repeat('-', 118) . "\n";
  $errors = 0;
  foreach ($rows as $r) {
    $okOrder = strtolower((string)($r['order_status'] ?? '')) === 'cancelado';
    $effect = strtoupper((string)($r['effect_status'] ?? ''));
    $okEffect = $effect === 'COMPLETED';
    if (!$okOrder || !$okEffect) $errors++;
    printf("%-8d %-6s %-8s %-9d %-12s %-12s %-10s %-12s %-12s\n",
      (int)$r['document_id'],
      (string)$r['model'],
      (string)$r['number'],
      (int)$r['order_id'],
      (string)($r['internal_cancellation_status'] ?: '—'),
      $okOrder ? 'CANCELADO' : strtoupper((string)($r['order_status'] ?: 'AUSENTE')),
      number_format((float)($r['stock_reversed_qty'] ?? 0), 4, ',', '.'),
      'R$ ' . number_format((float)($r['finance_reversed_amount'] ?? 0), 2, ',', '.'),
      'R$ ' . number_format((float)($r['cash_reversed_amount'] ?? 0), 2, ',', '.')
    );
    $msg = trim((string)($r['error_message'] ?? $r['internal_cancellation_message'] ?? ''));
    if ($msg !== '') echo "         ↳ {$msg}\n";
  }
  if (!$rows) echo "Nenhum documento cancelado vinculado a pedido foi encontrado para o filtro.\n";

  echo "\nDocumentos analisados: " . count($rows) . "\n";
  echo "Pendências encontradas: {$errors}\n";
  if ($errors > 0) {
    echo "[ATENÇÃO] Execute reconcile_fiscal_cancellations_v268.php primeiro sem --apply e, após conferir, com --apply.\n";
    exit(3);
  }
  echo "[OK] Cancelamentos fiscais vinculados a pedidos estão sincronizados.\n";
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
