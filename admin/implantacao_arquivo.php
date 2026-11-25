<?php
declare(strict_types=1);

// Trilha de Implantação: entrega fotos dos equipamentos e assinaturas (arquivos fora da pasta pública).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/implantacao_module.php';

$ok = access_is_company_master();
foreach (['crm_implantacao', 'crm_implantacao_relatorios', 'crm_implantacao_documentacao'] as $p) {
  if (!$ok && access_has_permission($pdo, (int)COMPANY_ID, $p)) $ok = true;
}
if (!$ok) { http_response_code(403); exit('Sem permissão.'); }
$path = null;
try { $path = impl_file_path((int)COMPANY_ID, (string)($_GET['f'] ?? '')); } catch (Throwable $e) { $path = null; }
if (!$path) { http_response_code(404); exit('Arquivo não encontrado.'); }
$ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
header('Content-Type: ' . (['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . (string)filesize($path));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($path);
