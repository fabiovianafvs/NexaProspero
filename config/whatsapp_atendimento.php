<?php
declare(strict_types=1);

/**
 * Tela de Atendimento do WhatsApp (estilo WhatsApp Web): fila por setor,
 * aceitar, conversar (texto e arquivos), transferir, gerar CRM e finalizar.
 * Regras das conversas/mensagens ficam em config/whatsapp_chatbot.php.
 */

require_once __DIR__ . '/whatsapp_chatbot.php';

if (!function_exists('whatsapp_att_scope')) {
  /**
   * Conversas que o usuário pode ver. Supervisor (administrador ou quem configura
   * o chatbot) vê tudo; os demais veem as do(s) seu(s) setor(es), as sem setor e
   * as que estão com eles. Sem setores cadastrados, todos veem tudo.
   * Retorna [sql, params] para usar com alias "s".
   */
  function whatsapp_att_scope(PDO $pdo, int $companyId, int $userId, bool $supervisor): array {
    if ($supervisor) return ['1=1', []];
    if (!whatsapp_departments($pdo, $companyId)) return ['1=1', []];
    $deps = whatsapp_user_department_ids($pdo, $companyId, $userId);
    $sql = '(s.user_id = ? OR s.department_id IS NULL';
    $params = [$userId];
    if ($deps) {
      $sql .= ' OR s.department_id IN (' . implode(',', array_fill(0, count($deps), '?')) . ')';
      $params = array_merge($params, $deps);
    }
    return [$sql . ')', $params];
  }
}

if (!function_exists('whatsapp_att_can_see')) {
  function whatsapp_att_can_see(PDO $pdo, int $companyId, int $userId, bool $supervisor, int $chatId): ?array {
    [$scope, $params] = whatsapp_att_scope($pdo, $companyId, $userId, $supervisor);
    $st = $pdo->prepare("SELECT s.id FROM whatsapp_bot_sessions s WHERE s.company_id=? AND s.id=? AND $scope LIMIT 1");
    $st->execute(array_merge([$companyId, $chatId], $params));
    return $st->fetchColumn() ? whatsapp_chat_get($pdo, $companyId, $chatId) : null;
  }
}

if (!function_exists('whatsapp_att_tabs')) {
  function whatsapp_att_tabs(): array {
    return [
      'fila' => ['Fila', "s.status='PENDENTE'"],
      'meus' => ['Meus', "s.status='ATENDIMENTO' AND s.user_id=:me"],
      'atendimento' => ['Em atendimento', "s.status='ATENDIMENTO'"],
      'robo' => ['No robô', "s.status='BOT'"],
      'finalizados' => ['Finalizados', "s.status='FINALIZADO'"],
    ];
  }
}

if (!function_exists('whatsapp_att_list')) {
  function whatsapp_att_list(PDO $pdo, int $companyId, int $userId, bool $supervisor, string $tab, string $q = ''): array {
    $tabs = whatsapp_att_tabs();
    if (!isset($tabs[$tab])) $tab = 'fila';
    [$scope, $scopeParams] = whatsapp_att_scope($pdo, $companyId, $userId, $supervisor);

    $counts = [];
    foreach ($tabs as $key => [$label, $cond]) {
      if ($key === 'finalizados' || $key === 'robo') continue;
      $c = str_replace(':me', (string)$userId, $cond);
      $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_bot_sessions s WHERE s.company_id=? AND $c AND $scope");
      $st->execute(array_merge([$companyId], $scopeParams));
      $counts[$key] = (int)$st->fetchColumn();
    }

    $cond = str_replace(':me', (string)$userId, $tabs[$tab][1]);
    $where = "s.company_id=? AND $cond AND $scope";
    $params = array_merge([$companyId], $scopeParams);
    $q = trim($q);
    if ($q !== '') {
      $digits = whatsapp_bot_digits($q);
      $where .= ' AND (s.push_name LIKE ? OR p.name LIKE ?' . ($digits !== '' ? ' OR s.phone LIKE ?' : '') . ')';
      $params[] = '%' . $q . '%';
      $params[] = '%' . $q . '%';
      if ($digits !== '') $params[] = '%' . $digits . '%';
    }
    $order = $tab === 'fila' ? 's.queued_at ASC, s.id ASC' : 's.last_message_at DESC, s.id DESC';
    $st = $pdo->prepare("SELECT s.id, s.contact, s.phone, s.push_name, s.status, s.department_id, s.user_id, s.unread, s.last_preview, s.last_direction,
        s.last_message_at, s.queued_at, s.accepted_at, s.person_id, p.name AS person_name, d.name AS department_name, u.name AS user_name
      FROM whatsapp_bot_sessions s
      LEFT JOIN finance_people p ON p.company_id=s.company_id AND p.id=s.person_id
      LEFT JOIN whatsapp_departments d ON d.company_id=s.company_id AND d.id=s.department_id
      LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id
      WHERE $where ORDER BY $order LIMIT 200");
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[] = whatsapp_att_row($r);
    return ['tab' => $tab, 'counts' => $counts, 'items' => $rows];
  }
}

if (!function_exists('whatsapp_att_phone_label')) {
  function whatsapp_att_phone_label(string $d): string {
    $d = whatsapp_bot_digits($d);
    if (strlen($d) === 13 && str_starts_with($d, '55')) return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 5) . '-' . substr($d, 9);
    if (strlen($d) === 12 && str_starts_with($d, '55')) return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 4) . '-' . substr($d, 8);
    return $d;
  }
}

if (!function_exists('whatsapp_att_row')) {
  function whatsapp_att_row(array $r): array {
    $name = trim((string)($r['person_name'] ?? '')) ?: trim((string)($r['push_name'] ?? ''));
    $phone = whatsapp_att_phone_label((string)($r['phone'] ?? ''));
    return [
      'id' => (int)$r['id'],
      'name' => $name !== '' ? $name : ($phone !== '' ? $phone : 'Contato'),
      'phone' => $phone,
      'status' => (string)$r['status'],
      'department' => (string)($r['department_name'] ?? ''),
      'department_id' => (int)($r['department_id'] ?? 0),
      'user' => (string)($r['user_name'] ?? ''),
      'user_id' => (int)($r['user_id'] ?? 0),
      'unread' => (int)($r['unread'] ?? 0),
      'preview' => (string)($r['last_preview'] ?? ''),
      'last_direction' => (string)($r['last_direction'] ?? ''),
      'last_at' => (string)($r['last_message_at'] ?? ''),
      'queued_at' => (string)($r['queued_at'] ?? ''),
      'person_id' => (int)($r['person_id'] ?? 0),
    ];
  }
}

if (!function_exists('whatsapp_att_chat_info')) {
  function whatsapp_att_chat_info(PDO $pdo, int $companyId, int $chatId): array {
    $st = $pdo->prepare("SELECT s.*, p.name AS person_name, d.name AS department_name, u.name AS user_name
      FROM whatsapp_bot_sessions s
      LEFT JOIN finance_people p ON p.company_id=s.company_id AND p.id=s.person_id
      LEFT JOIN whatsapp_departments d ON d.company_id=s.company_id AND d.id=s.department_id
      LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id
      WHERE s.company_id=? AND s.id=? LIMIT 1");
    $st->execute([$companyId, $chatId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return [];
    $row = whatsapp_att_row($r);
    $row['crm_attendance_id'] = (int)($r['crm_attendance_id'] ?? 0);
    $row['answers'] = [];
    if (function_exists('whatsapp_bot_chat_answers')) {
      foreach (whatsapp_bot_chat_answers($r) as $a) $row['answers'][] = ['label' => (string)($a['l'] ?? $a['k'] ?? ''), 'value' => (string)($a['v'] ?? '')];
    }
    return $row;
  }
}

if (!function_exists('whatsapp_att_messages')) {
  function whatsapp_att_messages(PDO $pdo, int $companyId, int $chatId, int $afterId = 0): array {
    $cols = 'm.id, m.direction, m.sender, m.user_id, m.msg_type, m.body, m.media_path, m.media_mime, m.media_name, m.media_size, m.send_ok, m.created_at, u.name AS user_name';
    $join = 'LEFT JOIN users u ON u.company_id=m.company_id AND u.id=m.user_id';
    if ($afterId > 0) {
      $st = $pdo->prepare("SELECT $cols FROM whatsapp_bot_messages m $join WHERE m.company_id=? AND m.session_id=? AND m.id>? ORDER BY m.id LIMIT 300");
      $st->execute([$companyId, $chatId, $afterId]);
    } else {
      $st = $pdo->prepare("SELECT * FROM (SELECT $cols FROM whatsapp_bot_messages m $join WHERE m.company_id=? AND m.session_id=? ORDER BY m.id DESC LIMIT 150) t ORDER BY id");
      $st->execute([$companyId, $chatId]);
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $m) {
      $out[] = [
        'id' => (int)$m['id'],
        'dir' => (string)$m['direction'],
        'sender' => (string)($m['sender'] ?? ($m['direction'] === 'IN' ? 'cliente' : 'robo')),
        'user' => (string)($m['user_name'] ?? ''),
        'type' => (string)($m['msg_type'] ?? 'text'),
        'body' => (string)($m['body'] ?? ''),
        'media' => !empty($m['media_path']),
        'mime' => (string)($m['media_mime'] ?? ''),
        'name' => (string)($m['media_name'] ?? ''),
        'size' => (int)($m['media_size'] ?? 0),
        'ok' => (int)($m['send_ok'] ?? 1) === 1,
        'at' => (string)$m['created_at'],
      ];
    }
    return $out;
  }
}

if (!function_exists('whatsapp_att_system')) {
  function whatsapp_att_system(PDO $pdo, int $companyId, array $chat, string $text, int $userId): void {
    whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'sistema', $text, ['user_id' => $userId]);
  }
}

if (!function_exists('whatsapp_att_assert_owner')) {
  /** Garante que a conversa está com este usuário; assume automaticamente quando livre. */
  function whatsapp_att_assert_owner(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor): array {
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] === $userId) return $chat;
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && !$supervisor) {
      throw new RuntimeException('Esta conversa está com outro atendente.');
    }
    return whatsapp_att_accept($pdo, $companyId, $chat, $userId, $supervisor, false);
  }
}

if (!function_exists('whatsapp_att_accept')) {
  function whatsapp_att_accept(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, bool $greet = true): array {
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId && !$supervisor) {
      throw new RuntimeException('Esta conversa já foi aceita por outro atendente.');
    }
    $now = date('Y-m-d H:i:s');
    $chatId = (int)$chat['id'];
    // Evita dois atendentes aceitando a mesma conversa ao mesmo tempo.
    $st = $pdo->prepare("UPDATE whatsapp_bot_sessions SET status='ATENDIMENTO', user_id=?, accepted_at=?, closed_at=NULL, unread=0, state='MENU', paused_until=NULL
      WHERE company_id=? AND id=? AND (status<>'ATENDIMENTO' OR user_id IS NULL OR user_id=? OR ?=1)");
    $st->execute([$userId, $now, $companyId, $chatId, $userId, $supervisor ? 1 : 0]);
    if ($st->rowCount() === 0) throw new RuntimeException('Esta conversa já foi aceita por outro atendente.');
    $name = whatsapp_bot_user_name($pdo, $companyId, $userId) ?: 'Atendente';
    $previous = (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId ? whatsapp_bot_user_name($pdo, $companyId, (int)$chat['user_id']) : '';
    whatsapp_att_system($pdo, $companyId, $chat, $previous !== '' ? $name . ' assumiu o atendimento (estava com ' . $previous . ').' : $name . ' aceitou o atendimento.', $userId);
    $chat = whatsapp_chat_get($pdo, $companyId, $chatId) ?? $chat;
    if ($greet) {
      $cfg = whatsapp_bot_config($pdo, $companyId);
      $text = trim(strtr((string)$cfg['accept_text'], ['{atendente}' => $name]));
      if ($text !== '') whatsapp_chat_send_text($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $text, 'atendente', $userId);
    }
    whatsapp_chat_notify($companyId, $chatId, ['status' => 'ATENDIMENTO']);
    return $chat;
  }
}

if (!function_exists('whatsapp_att_send_text')) {
  function whatsapp_att_send_text(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, string $text): bool {
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Digite a mensagem.');
    if (mb_strlen($text) > 4000) throw new RuntimeException('Mensagem muito longa (máximo 4.000 caracteres).');
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $cfg = whatsapp_bot_config($pdo, $companyId);
    $wire = $text;
    if ($cfg['sign_messages']) {
      $name = whatsapp_bot_user_name($pdo, $companyId, $userId);
      if ($name !== '') $wire = '*' . $name . ':*' . "\n" . $text;
    }
    $ok = whatsapp_chat_send_text($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $text, 'atendente', $userId, $wire);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'ATENDIMENTO']);
    return $ok;
  }
}

if (!function_exists('whatsapp_att_send_file')) {
  function whatsapp_att_send_file(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, array $file, string $caption): bool {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
      throw new RuntimeException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Arquivo maior que o permitido pelo servidor.' : 'Selecione um arquivo.');
    }
    $size = (int)$file['size'];
    if ($size <= 0 || $size > WHATSAPP_MEDIA_MAX_BYTES) throw new RuntimeException('O arquivo deve ter no máximo 16 MB.');
    $bytes = (string)file_get_contents((string)$file['tmp_name']);
    $mime = '';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_buffer($fi, $bytes); finfo_close($fi); }
    if ($mime === '' || $mime === 'application/octet-stream') $mime = (string)($file['type'] ?? 'application/octet-stream');
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $ok = whatsapp_chat_send_file($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $bytes, $mime, (string)$file['name'], trim($caption), 'atendente', $userId, true);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'ATENDIMENTO']);
    return $ok;
  }
}

if (!function_exists('whatsapp_att_transfer')) {
  function whatsapp_att_transfer(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $departmentId, int $toUserId): void {
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId && !$supervisor) {
      throw new RuntimeException('Só quem está atendendo (ou o supervisor) pode transferir esta conversa.');
    }
    $me = whatsapp_bot_user_name($pdo, $companyId, $userId) ?: 'Atendente';
    $chatId = (int)$chat['id'];
    if ($toUserId > 0) {
      $target = whatsapp_bot_user_name($pdo, $companyId, $toUserId);
      if ($target === '') throw new RuntimeException('Usuário não encontrado.');
      whatsapp_chat_update($pdo, $companyId, $chatId, ['status' => 'ATENDIMENTO', 'user_id' => $toUserId, 'department_id' => $departmentId ?: ($chat['department_id'] ?? null), 'accepted_at' => date('Y-m-d H:i:s'), 'closed_at' => null, 'unread' => 1]);
      whatsapp_att_system($pdo, $companyId, $chat, $me . ' transferiu a conversa para ' . $target . '.', $userId);
    } else {
      $name = $departmentId > 0 ? whatsapp_department_name($pdo, $companyId, $departmentId) : 'Fila geral';
      if ($name === '') throw new RuntimeException('Setor não encontrado.');
      whatsapp_chat_update($pdo, $companyId, $chatId, ['status' => 'PENDENTE', 'user_id' => null, 'department_id' => $departmentId ?: null, 'queued_at' => date('Y-m-d H:i:s'), 'accepted_at' => null, 'closed_at' => null, 'unread' => 1]);
      whatsapp_att_system($pdo, $companyId, $chat, $me . ' transferiu a conversa para ' . $name . '.', $userId);
      if ($departmentId > 0) {
        $ctx = whatsapp_chat_ctx($pdo, $companyId, whatsapp_chat_get($pdo, $companyId, $chatId) ?? $chat);
        whatsapp_chat_send_text($pdo, $companyId, $ctx, 'Sua conversa foi transferida para *' . $name . '*. Em instantes alguém continua o atendimento. 🙂', 'atendente', $userId);
      }
    }
    whatsapp_chat_notify($companyId, $chatId, ['status' => 'transfer']);
  }
}

if (!function_exists('whatsapp_att_finish')) {
  function whatsapp_att_finish(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, bool $notifyCustomer): void {
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId && !$supervisor) {
      throw new RuntimeException('Só quem está atendendo (ou o supervisor) pode finalizar esta conversa.');
    }
    if ($notifyCustomer) {
      $cfg = whatsapp_bot_config($pdo, $companyId);
      $text = trim((string)$cfg['close_text']);
      if ($text !== '') whatsapp_chat_send_text($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $text, 'atendente', $userId);
    }
    whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], ['status' => 'FINALIZADO', 'state' => 'FIM', 'data' => [], 'closed_at' => date('Y-m-d H:i:s'), 'unread' => 0, 'paused_until' => null]);
    whatsapp_att_system($pdo, $companyId, $chat, (whatsapp_bot_user_name($pdo, $companyId, $userId) ?: 'Atendente') . ' finalizou o atendimento.', $userId);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'FINALIZADO']);
  }
}

if (!function_exists('whatsapp_att_crm')) {
  /** Gera um atendimento no CRM com o histórico recente da conversa. */
  function whatsapp_att_crm(PDO $pdo, int $companyId, array $chat, int $userId, string $note = ''): int {
    if ((int)($chat['crm_attendance_id'] ?? 0) > 0) return (int)$chat['crm_attendance_id'];
    $since = (string)($chat['queued_at'] ?: ($chat['accepted_at'] ?: ''));
    $msgs = whatsapp_att_messages($pdo, $companyId, (int)$chat['id']);
    $lines = [];
    foreach ($msgs as $m) {
      if ($since !== '' && $m['at'] < $since && count($msgs) > 40) continue;
      if ($m['sender'] === 'sistema') continue;
      $who = $m['dir'] === 'IN' ? 'Cliente' : ($m['sender'] === 'robo' ? 'Robô' : ($m['user'] ?: 'Atendente'));
      $body = trim($m['body']) !== '' ? $m['body'] : whatsapp_chat_type_label($m['type']);
      $lines[] = '[' . date('d/m H:i', strtotime($m['at'])) . '] ' . $who . ': ' . $body;
    }
    $lines = array_slice($lines, -40);
    $request = (trim($note) !== '' ? trim($note) . "\n\n" : '') . "Conversa no WhatsApp:\n" . implode("\n", $lines);
    $cfg = whatsapp_bot_config($pdo, $companyId);
    $id = whatsapp_bot_open_crm($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $cfg, mb_substr($request, 0, 15000), $userId, 'painel');
    whatsapp_att_system($pdo, $companyId, $chat, 'Atendimento nº ' . $id . ' gerado no CRM.', $userId);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['crm' => $id]);
    return $id;
  }
}

if (!function_exists('whatsapp_att_new_chat')) {
  /** Inicia uma conversa com um número (o atendente já fica com ela). */
  function whatsapp_att_new_chat(PDO $pdo, int $companyId, int $userId, string $phone, string $text): int {
    $d = whatsapp_bot_digits($phone);
    if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
    if (strlen($d) < 12 || strlen($d) > 13) throw new RuntimeException('Informe o celular com DDD.');
    $chat = whatsapp_chat_find_or_create($pdo, $companyId, $d . '@s.whatsapp.net', $d);
    if (empty($chat['person_id'])) {
      try { $p = whatsapp_bot_find_person($pdo, $companyId, $d); if ($p) whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], ['person_id' => (int)$p['id']]); } catch (Throwable $e) {}
    }
    $chat = whatsapp_chat_get($pdo, $companyId, (int)$chat['id']) ?? $chat;
    if (!($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId)) {
      $chat = whatsapp_att_accept($pdo, $companyId, $chat, $userId, false, false);
    }
    if (trim($text) !== '') whatsapp_att_send_text($pdo, $companyId, $chat, $userId, false, $text);
    return (int)$chat['id'];
  }
}
