<?php
declare(strict_types=1);

// Arquivo de uma mensagem do Chat interno (só para quem participa da conversa).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/chat_interno.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$id = max(0, (int)($_GET['id'] ?? 0));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
  if ($uid <= 0 || !chat_ready($pdo)) throw new RuntimeException('Indisponível.');
  $st = $pdo->prepare('SELECT conversation_id, media_path, media_mime, media_name, deleted_at FROM chat_messages WHERE company_id = ? AND id = ? LIMIT 1');
  $st->execute([$cid, $id]);
  $m = $st->fetch(PDO::FETCH_ASSOC);
  if (!$m || !empty($m['deleted_at']) || empty($m['media_path']) || !chat_member($pdo, $cid, (int)$m['conversation_id'], $uid)) throw new RuntimeException('Arquivo não encontrado.');
  $path = chat_media_path($cid, (string)$m['media_path']);
  if (!$path) throw new RuntimeException('Arquivo não encontrado.');
  $mime = trim(explode(';', strtolower((string)$m['media_mime']))[0]);
  // Só tipos seguros abrem no navegador; o resto é baixado.
  $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf', 'audio/ogg', 'audio/mpeg', 'video/mp4'], true);
  $name = (string)($m['media_name'] ?: 'arquivo');
  header('X-Content-Type-Options: nosniff');
  header('Content-Security-Policy: sandbox');
  header('Cache-Control: private, max-age=86400');
  header('Content-Type: ' . ($inline ? $mime : 'application/octet-stream'));
  header('Content-Disposition: ' . ($inline && empty($_GET['download']) ? 'inline' : 'attachment') . '; filename="arquivo"; filename*=UTF-8\'\'' . rawurlencode($name));
  header('Content-Length: ' . (string)filesize($path));
  readfile($path);
} catch (Throwable $e) {
  http_response_code(404);
  header('Content-Type: text/plain; charset=UTF-8');
  echo 'Arquivo indisponível.';
}
