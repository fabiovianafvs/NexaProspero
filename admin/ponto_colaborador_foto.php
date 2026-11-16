<?php
declare(strict_types=1);

// Foto do colaborador (Ponto Eletrônico): GET ?id= mostra a foto; POST salvar/remover.
// O arquivo fica fora da pasta pública e só sai daqui para quem pode ver o cadastro.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/_ponto.php';

$cid = (int)COMPANY_ID;
$canView = ponto_can($pdo, 'ponto_colaboradores') || ponto_can($pdo, 'ponto_painel');
if (session_status() !== PHP_SESSION_ACTIVE) @session_start();

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
  try {
    if (!ponto_can($pdo, 'ponto_colaboradores')) throw new RuntimeException('Sem permissão para alterar colaboradores.');
    ponto_csrf_check();
    $id = (int)($_POST['id'] ?? 0);
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'salvar') {
      $file = $_FILES['foto'] ?? null;
      if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) throw new RuntimeException('Envie a foto.');
      ponto_json(['ok' => true] + ponto_foto_save($pdo, $cid, $id, (string)$file['tmp_name']));
    }
    if ($action === 'remover') {
      ponto_foto_remove($pdo, $cid, $id, (string)($_POST['motivo'] ?? ''));
      ponto_json(['ok' => true]);
    }
    throw new RuntimeException('Ação inválida.');
  } catch (Throwable $e) {
    ponto_json(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível gravar a foto.'], 400);
  }
}

if (!$canView) { http_response_code(403); exit; }
$col = ponto_colaborador_get($pdo, $cid, (int)($_GET['id'] ?? 0));
$file = $col ? ponto_foto_file($col) : null;
if (!$file) { http_response_code(404); exit; }
while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: image/jpeg');
header('Content-Length: ' . (string)filesize($file));
header('Cache-Control: private, max-age=86400');
header('X-Content-Type-Options: nosniff');
readfile($file);
