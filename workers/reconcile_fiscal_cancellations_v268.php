<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit("Somente CLI.\n"); }
$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/order_cancellation.php';

$companyId = 0;
$documentId = 0;
$apply = false;
foreach ($argv ?? [] as $arg) {
  if (preg_match('/^--company=(\d+)$/', (string)$arg, $m)) $companyId = (int)$m[1];
  if (preg_match('/^--document=(\d+)$/', (string)$arg, $m)) $documentId = (int)$m[1];
  if ($arg === '--apply') $apply = true;
}
if ($companyId <= 0) {
  fwrite(STDERR, "Uso: php reconcile_fiscal_cancellations_v268.php --company=1 [--document=456] [--apply]\n");
  exit(2);
}

try {
  nexa_order_cancellation_ensure_schema($pdo);
  $where = ["fd.company_id=?", "UPPER(COALESCE(fd.status,''))='CANCELADA'", "fd.source_type='PEDIDO'", "fd.source_id IS NOT NULL"];
  $params = [$companyId];
  if ($documentId > 0) { $where[]='fd.id=?'; $params[]=$documentId; }
  $sql = "SELECT fd.id, fd.source_id order_id, fd.model, fd.number,
      COALESCE((SELECT notes FROM fiscal_document_events ev WHERE ev.company_id=fd.company_id AND ev.fiscal_document_id=fd.id AND ev.event_type IN ('CANCELAMENTO_NFE','CANCELAMENTO_NFCE') ORDER BY ev.id DESC LIMIT 1),'Cancelamento fiscal já autorizado na SEFAZ.') reason
    FROM fiscal_documents fd
    WHERE " . implode(' AND ', $where) . " ORDER BY fd.id";
  $st=$pdo->prepare($sql); $st->execute($params);
  $docs=$st->fetchAll(PDO::FETCH_ASSOC) ?: [];

  echo "NEXA FISCAL v2.6.8 - RECONCILIAÇÃO DE CANCELAMENTOS\n";
  echo "Modo: " . ($apply ? 'APLICAR' : 'SIMULAÇÃO') . "\n";
  echo str_repeat('=', 90) . "\n";
  $ok=0; $fail=0;
  foreach ($docs as $doc) {
    $label = 'Doc. ' . (int)$doc['id'] . ' / ' . ((string)$doc['model']==='55'?'NF-e':'NFC-e') . ' nº ' . (string)$doc['number'] . ' / Pedido ' . (int)$doc['order_id'];
    if (!$apply) {
      echo "[SIMULAR] {$label}\n";
      continue;
    }
    try {
      $result = nexa_cancel_order($pdo, $companyId, (int)$doc['order_id'], (string)$doc['reason'], 'FISCAL', (int)$doc['id'], 0);
      echo "[OK] {$label} | estoque " . number_format((float)$result['stock_quantity'],4,',','.') . " | financeiro R$ " . number_format((float)$result['finance_amount'],2,',','.') . " | caixa R$ " . number_format((float)$result['cash_amount'],2,',','.') . "\n";
      if (!empty($result['warning'])) echo "     Atenção: " . $result['warning'] . "\n";
      $ok++;
    } catch (Throwable $e) {
      echo "[ERRO] {$label}: " . $e->getMessage() . "\n";
      $fail++;
    }
  }
  echo "\nDocumentos encontrados: " . count($docs) . " | Processados: {$ok} | Falhas: {$fail}\n";
  if (!$apply && $docs) echo "Para aplicar, repita o comando acrescentando --apply.\n";
  if ($fail > 0) exit(3);
} catch (Throwable $e) {
  fwrite(STDERR, '[ERRO] ' . $e->getMessage() . PHP_EOL);
  exit(1);
}
