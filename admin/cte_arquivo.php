<?php
declare(strict_types=1);

// Transporte: DACTE (PDF) e XML do CT-e autorizado. Só da própria empresa.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/cte_emissao.php';

if (!(access_is_company_master() || access_has_permission($pdo, (int)COMPANY_ID, 'cte_documentos'))) {
  http_response_code(403);
  exit('Sem permissão.');
}
$cid = (int)COMPANY_ID;
$id = (int)($_GET['id'] ?? 0);
$tipo = (string)($_GET['tipo'] ?? 'dacte');
try {
  $row = cte_get($pdo, $cid, $id);
  if (!$row) throw new RuntimeException('CT-e não encontrado.');
  $nome = ($row['chave'] ?: ('cte-' . $id));
  if ($tipo === 'xml') {
    if (empty($row['xml']) || !in_array($row['status'], ['AUTORIZADO', 'CANCELADO', 'DENEGADO'], true)) throw new RuntimeException('O XML fica disponível depois da autorização.');
    header('Content-Type: application/xml; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $nome . '-cte.xml"');
    header('Cache-Control: private, no-store');
    echo $row['xml'];
    exit;
  }
  $pdf = cte_dacte_pdf($pdo, $cid, $id);
  header('Content-Type: application/pdf');
  header('Content-Disposition: inline; filename="DACTE-' . $nome . '.pdf"');
  header('Cache-Control: private, no-store');
  echo $pdf;
} catch (Throwable $e) {
  http_response_code(400);
  header('Content-Type: text/plain; charset=utf-8');
  echo $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gerar o arquivo.';
}
