<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/inventory_module.php';

$cid = (int)COMPANY_ID;
$id = (int)($_GET['id'] ?? 0);
$download = isset($_GET['download']) && (string)$_GET['download'] === '1';

try {
  inventory_ensure_schema($pdo);
  $inventory = $id > 0 ? inventory_get($pdo, $cid, $id) : null;
  if (!$inventory) throw new RuntimeException('Inventário não encontrado.');

  $pdf = inventory_pdf_content($pdo, $cid, $id);
  $dateKey = str_replace('-', '', (string)$inventory['inventory_date']);
  $filename = 'INVENTARIO_' . $dateKey . '_ID_' . $id . '.pdf';

  while (ob_get_level() > 0) @ob_end_clean();
  header('Content-Type: application/pdf');
  header('Content-Disposition: ' . ($download ? 'attachment' : 'inline') . '; filename="' . $filename . '"');
  header('Content-Length: ' . strlen($pdf));
  header('Cache-Control: private, no-store, max-age=0');
  echo $pdf;
  exit;
} catch (Throwable $e) {
  http_response_code(404);
  echo htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8');
}
