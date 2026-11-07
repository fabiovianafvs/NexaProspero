<?php
declare(strict_types=1);

// Andamento de uma operação em curso (finalizar venda / emitir nota).
// Leitura sem sessão de propósito: a sessão PHP fica travada pela própria
// operação enquanto ela roda. O código é aleatório (128 bits) e o arquivo só
// tem o texto das etapas, sem dados de clientes. Ver config/progress.php.
require_once __DIR__ . '/../config/progress.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('X-Content-Type-Options: nosniff');

$token = (string)($_GET['t'] ?? '');
if (!preg_match('/^[a-f0-9]{32}$/', $token)) {
  http_response_code(400);
  echo '{"steps":[]}';
  exit;
}
$file = nexa_progress_file($token);
$raw = is_file($file) ? (string)@file_get_contents($file) : '';
echo $raw !== '' ? $raw : '{"steps":[]}';
