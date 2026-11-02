<?php
declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
$accountantId=accountant_require_login();
$id=(int)($_GET['id'] ?? 0);
try {
  $file=$id>0 ? accounting_get_accountant_file($pdo,$accountantId,$id) : null;
  if (!$file) throw new RuntimeException('Arquivo não encontrado ou acesso não permitido.');
  $path=accounting_file_absolute_path((string)$file['relative_path']);
  while(ob_get_level()>0) @ob_end_clean();
  header('Content-Type: ' . ((string)$file['mime_type'] ?: 'application/octet-stream'));
  header('Content-Disposition: attachment; filename="' . accounting_safe_part((string)$file['file_name'],'arquivo') . '"');
  header('Content-Length: ' . (string)filesize($path));
  header('Cache-Control: private, no-store, max-age=0');
  readfile($path);exit;
} catch (Throwable $e) { http_response_code(404); echo accountant_e($e->getMessage()); }
