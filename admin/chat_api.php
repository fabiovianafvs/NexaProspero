<?php
declare(strict_types=1);

// API JSON do Chat interno (sempre da empresa de login; só conversas de que o usuário participa).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/chat_interno.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$isAdmin = function_exists('access_is_company_master') && access_is_company_master();
$out = static function (array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
};
if ($uid <= 0) $out(['ok' => false, 'error' => 'Sessão expirada. Entre novamente.'], 401);
if (!chat_ready($pdo)) $out(['ok' => false, 'error' => 'Aplique "Chat interno" no Atualizador do Banco.'], 409);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost && !$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
  $out(['ok' => false, 'error' => 'Arquivo maior que o permitido pelo servidor (' . ini_get('post_max_size') . ').'], 413);
}
if ($isPost) {
  $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
  $expected = (string)($_SESSION['nexa_chat_csrf'] ?? '');
  if ($expected === '' || !hash_equals($expected, $token)) $out(['ok' => false, 'error' => 'Sessão expirada. Atualize a página.'], 403);
}
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();
$conv = (int)($_GET['id'] ?? $_POST['id'] ?? 0);

try {
  switch ($action) {
    case 'list':
      $out(['ok' => true, 'items' => chat_list($pdo, $cid, $uid), 'unread' => chat_unread_total($pdo, $cid, $uid), 'me' => $uid]);
    case 'unread':
      $out(['ok' => true, 'unread' => chat_unread_total($pdo, $cid, $uid)]);
    case 'users':
      $users = chat_users($pdo, $cid);
      unset($users[$uid]);
      $out(['ok' => true, 'items' => array_map(static fn($id, $name) => ['id' => $id, 'name' => $name], array_keys($users), array_values($users))]);
    case 'messages':
      $after = max(0, (int)($_GET['after'] ?? 0));
      $out(['ok' => true, 'info' => chat_conversation_info($pdo, $cid, $uid, $conv), 'messages' => chat_messages($pdo, $cid, $uid, $conv, $after)]);
  }
  if (!$isPost) $out(['ok' => false, 'error' => 'Ação inválida.'], 400);
  switch ($action) {
    case 'open_direct':
      $out(['ok' => true, 'id' => chat_open_direct($pdo, $cid, $uid, (int)($_POST['user_id'] ?? 0))]);
    case 'group_save':
      $members = array_map('intval', explode(',', (string)($_POST['members'] ?? '')));
      $out(['ok' => true, 'id' => chat_group_save($pdo, $cid, $uid, $conv, (string)($_POST['name'] ?? ''), $members, $isAdmin)]);
    case 'group_leave':
      chat_group_leave($pdo, $cid, $uid, $conv);
      $out(['ok' => true]);
    case 'send':
      $id = chat_send($pdo, $cid, $uid, $conv, (string)($_POST['text'] ?? ''), $_FILES['file'] ?? null, (int)($_POST['reply_to'] ?? 0));
      $out(['ok' => true, 'message_id' => $id]);
    case 'read':
      chat_mark_read($pdo, $cid, $uid, $conv, (int)($_POST['last_id'] ?? 0));
      $out(['ok' => true]);
    case 'delete':
      chat_delete($pdo, $cid, $uid, $conv, (int)($_POST['message_id'] ?? 0));
      $out(['ok' => true]);
  }
  $out(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
  $out(['ok' => false, 'error' => $e instanceof RuntimeException ? $e->getMessage() : 'Não foi possível concluir. Tente novamente.'], 422);
}
