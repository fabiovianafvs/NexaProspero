<?php
declare(strict_types=1);

// API JSON da tela WhatsApp > Atendimento (sempre da empresa de login, sem F11).
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../config/whatsapp_atendimento.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

$cid = (int)COMPANY_ID;
$uid = (int)(auth_user_id() ?? 0);
$supervisor = access_is_company_master() || access_has_permission($pdo, $cid, 'whatsapp_chatbot');
$out = static function (array $data, int $code = 200): void {
  http_response_code($code);
  echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  exit;
};

if ($uid <= 0) $out(['ok' => false, 'error' => 'Sessão expirada. Entre novamente.'], 401);
if (!whatsapp_bot_ready($pdo)) $out(['ok' => false, 'error' => 'Aplique "WhatsApp - Atendimento" no Atualizador do Banco.'], 409);

$action = (string)($_GET['action'] ?? $_POST['action'] ?? '');
$isPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST';
if ($isPost) {
  $token = (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? $_POST['csrf'] ?? '');
  $expected = (string)($_SESSION['nexa_waa_csrf'] ?? '');
  if ($expected === '' || !hash_equals($expected, $token)) $out(['ok' => false, 'error' => 'Sessão expirada. Atualize a página.'], 403);
}
// Libera a sessão para não travar outras abas enquanto envia arquivos.
if (session_status() === PHP_SESSION_ACTIVE) session_write_close();

$chatId = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$loadChat = static function () use ($pdo, $cid, $uid, $supervisor, $chatId, $out): array {
  $chat = $chatId > 0 ? whatsapp_att_can_see($pdo, $cid, $uid, $supervisor, $chatId) : null;
  if (!$chat) $out(['ok' => false, 'error' => 'Conversa não encontrada.'], 404);
  return $chat;
};

try {
  switch ($action) {
    case 'list':
      $out(['ok' => true] + whatsapp_att_list($pdo, $cid, $uid, $supervisor, (string)($_GET['tab'] ?? 'fila'), (string)($_GET['q'] ?? '')));

    case 'chat':
      $chat = $loadChat();
      $after = max(0, (int)($_GET['after'] ?? 0));
      if ((int)$chat['unread'] > 0 && ((int)$chat['user_id'] === $uid || (int)$chat['user_id'] === 0)) {
        whatsapp_chat_update($pdo, $cid, $chatId, ['unread' => 0]);
      }
      $out(['ok' => true, 'chat' => whatsapp_att_chat_info($pdo, $cid, $chatId), 'messages' => whatsapp_att_messages($pdo, $cid, $chatId, $after), 'me' => $uid, 'supervisor' => $supervisor]);

    case 'options':
      $st = $pdo->prepare('SELECT id, name FROM users WHERE ' . (function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)') . ' AND is_active=1 ORDER BY name, id');
      $st->execute((function_exists('access_company_users_params') ? access_company_users_params($pdo, $cid) : [$cid]));
      $out(['ok' => true, 'departments' => array_map(static fn($d) => ['id' => (int)$d['id'], 'name' => (string)$d['name']], whatsapp_departments($pdo, $cid)), 'users' => $st->fetchAll(PDO::FETCH_ASSOC) ?: []]);

    case 'contacts':
      // Nova conversa: busca no cadastro de Contatos e no WhatsApp/telefone das pessoas.
      require_once __DIR__ . '/../config/person_contacts.php';
      $q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 80);
      $items = [];
      if (mb_strlen($q) >= 2) {
        foreach (person_contacts_search($pdo, $cid, $q, 15, true) as $r) {
          $ph = trim((string)($r['whatsapp'] ?? '')) ?: trim((string)($r['phone'] ?? ''));
          if ($ph === '') continue;
          $items[] = ['name' => (string)$r['name'], 'role' => (string)($r['role'] ?? ''), 'company' => (string)($r['person_name'] ?? ''), 'phone' => $ph];
        }
        $like = '%' . $q . '%';
        $digits = person_contact_digits($q);
        $sql = "SELECT name, whatsapp, phone FROM finance_people WHERE company_id=? AND (COALESCE(whatsapp,'')<>'' OR COALESCE(phone,'')<>'') AND (name LIKE ? OR document LIKE ?";
        $params = [$cid, $like, $like];
        if (strlen($digits) >= 4) { $sql .= " OR REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),'(',''),')',''),'-',''),' ','') LIKE ?"; $params[] = '%' . $digits . '%'; }
        $st = $pdo->prepare($sql . ') ORDER BY name LIMIT 15');
        $st->execute($params);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
          $ph = trim((string)($r['whatsapp'] ?? '')) ?: trim((string)($r['phone'] ?? ''));
          $items[] = ['name' => (string)$r['name'], 'role' => 'Cadastro', 'company' => '', 'phone' => $ph];
        }
      }
      $out(['ok' => true, 'items' => array_slice($items, 0, 25)]);
  }

  if (!$isPost) $out(['ok' => false, 'error' => 'Ação inválida.'], 400);

  switch ($action) {
    case 'accept':
      whatsapp_att_accept($pdo, $cid, $loadChat(), $uid, $supervisor, true);
      $out(['ok' => true]);

    case 'send':
      $ok = whatsapp_att_send_text($pdo, $cid, $loadChat(), $uid, $supervisor, (string)($_POST['text'] ?? ''));
      $out(['ok' => true, 'sent' => $ok, 'warning' => $ok ? '' : 'A mensagem não saiu. Confira a conexão do WhatsApp.']);

    case 'file':
      $ok = whatsapp_att_send_file($pdo, $cid, $loadChat(), $uid, $supervisor, $_FILES['file'] ?? [], (string)($_POST['caption'] ?? ''));
      $out(['ok' => true, 'sent' => $ok, 'warning' => $ok ? '' : 'O arquivo não saiu. Confira a conexão do WhatsApp.']);

    case 'transfer':
      whatsapp_att_transfer($pdo, $cid, $loadChat(), $uid, $supervisor, (int)($_POST['department_id'] ?? 0), (int)($_POST['user_id'] ?? 0));
      $out(['ok' => true]);

    case 'finish':
      whatsapp_att_finish($pdo, $cid, $loadChat(), $uid, $supervisor, !empty($_POST['notify']));
      $out(['ok' => true]);

    case 'crm':
      $id = whatsapp_att_crm($pdo, $cid, $loadChat(), $uid, (string)($_POST['note'] ?? ''));
      $out(['ok' => true, 'crm_id' => $id]);

    case 'new':
      $id = whatsapp_att_new_chat($pdo, $cid, $uid, (string)($_POST['phone'] ?? ''), (string)($_POST['text'] ?? ''));
      $out(['ok' => true, 'id' => $id]);
  }
  $out(['ok' => false, 'error' => 'Ação inválida.'], 400);
} catch (Throwable $e) {
  $out(['ok' => false, 'error' => $e->getMessage()], 422);
}
