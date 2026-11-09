<?php
declare(strict_types=1);

// Arquivo de uma mensagem do WhatsApp (foto, áudio, vídeo, documento).
// Os arquivos ficam fora da pasta pública; aqui só sai para quem pode ver a conversa.
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/whatsapp_atendimento.php';

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$supervisor = access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_chatbot');
$id = max(0, (int)($_GET['id'] ?? 0));
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

try {
  if ($uid <= 0 || !whatsapp_bot_ready($pdo)) throw new RuntimeException('Indisponível.');
  $st = $pdo->prepare('SELECT session_id, media_path, media_mime, media_name FROM whatsapp_bot_messages WHERE company_id=? AND id=? LIMIT 1');
  $st->execute([$cid, $id]);
  $m = $st->fetch(PDO::FETCH_ASSOC);
  if (!$m || empty($m['media_path']) || !whatsapp_att_can_see($pdo, $cid, $uid, $supervisor, (int)$m['session_id'])) throw new RuntimeException('Arquivo não encontrado.');
  $path = whatsapp_media_path($cid, (string)$m['media_path']);
  if (!$path) throw new RuntimeException('Arquivo não encontrado.');
  $mime = strtolower((string)$m['media_mime']);
  // Só tipos seguros abrem direto no navegador; o resto é baixado.
  $inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac', 'video/mp4', 'application/pdf'], true);
  $name = (string)($m['media_name'] ?: ('arquivo.' . pathinfo($path, PATHINFO_EXTENSION)));
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
