<?php
declare(strict_types=1);

/**
 * Chat interno: conversas entre os usuários da empresa (diretas ou em grupo),
 * com texto, arquivos e resposta citando outra mensagem.
 * Tabelas de db/20261118_chat_interno_v1.sql. Arquivos fora da pasta pública em
 * /var/www/nexa_private/chat/<pasta do app>/ (ou env NEXA_CHAT_DIR).
 * Tempo real pelo nexa-realtime (evento chat.message, só com ids — o conteúdo é
 * buscado pela API, que confere se o usuário participa da conversa).
 */

require_once __DIR__ . '/config_store.php';

const CHAT_MAX_FILE_BYTES = 16 * 1024 * 1024;

if (!function_exists('chat_ready')) {
  function chat_ready(PDO $pdo): bool {
    static $memo = null;
    if ($memo !== null) return $memo;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('chat_conversations','chat_members','chat_messages')");
      return $memo = (int)$st->fetchColumn() === 3;
    } catch (Throwable $e) {
      return $memo = false;
    }
  }
}

const CHAT_DENY_TOKEN = '__deny_chat_interno';

if (!function_exists('chat_user_allowed')) {
  /**
   * O usuário logado pode usar o Chat interno? Liberado por padrão; o perfil de acesso
   * bloqueia com o token __deny_chat_interno (opção "Chat interno" desmarcada).
   * Administrador da empresa e usuário sem perfil sempre podem.
   */
  function chat_user_allowed(PDO $pdo, int $companyId): bool {
    if (function_exists('access_is_company_master') && access_is_company_master()) return true;
    if (!function_exists('access_current_user') || !function_exists('access_profile_permissions')) return true;
    $u = access_current_user($pdo);
    if (!$u || (int)($u['is_active'] ?? 0) !== 1) return false;
    $profileId = (int)($u['profile_id'] ?? 0);
    if ($profileId <= 0) return true;
    return !in_array(CHAT_DENY_TOKEN, access_profile_permissions($pdo, $companyId, $profileId), true);
  }
}

if (!function_exists('chat_users')) {
  /** Usuários ativos da empresa que podem usar o chat: [id => nome]. */
  function chat_users(PDO $pdo, int $companyId): array {
    if (!function_exists('access_company_users_sql') && is_file(__DIR__ . '/access_control.php')) require_once __DIR__ . '/access_control.php';
    $sql = function_exists('access_company_users_sql') ? access_company_users_sql($pdo, 'u') : '(u.company_id = ?)';
    $params = function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId];
    // Quem tem o chat bloqueado no perfil não aparece para os outros.
    $hasProfiles = false;
    try { $hasProfiles = (bool)$pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'profile_id'")->fetchColumn(); } catch (Throwable $e) {}
    $st = $pdo->prepare('SELECT u.id, u.name' . ($hasProfiles ? ', u.role, p.permissions_json' : '') . ' FROM users u'
      . ($hasProfiles ? ' LEFT JOIN user_profiles p ON p.id = u.profile_id AND p.company_id = ?' : '')
      . ' WHERE ' . $sql . ' AND u.is_active = 1 ORDER BY u.name, u.id');
    $st->execute($hasProfiles ? array_merge([$companyId], $params) : $params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $isAdmin = in_array((string)($r['role'] ?? ''), ['company_admin', 'platform_admin'], true);
      if (!$isAdmin && str_contains((string)($r['permissions_json'] ?? ''), '"' . CHAT_DENY_TOKEN . '"')) continue;
      $out[(int)$r['id']] = trim((string)$r['name']) ?: ('Usuário ' . (int)$r['id']);
    }
    return $out;
  }
}

if (!function_exists('chat_member')) {
  /** Participação ativa do usuário na conversa (null = não participa). */
  function chat_member(PDO $pdo, int $companyId, int $convId, int $userId): ?array {
    $st = $pdo->prepare('SELECT m.*, c.kind, c.name, c.created_by FROM chat_members m JOIN chat_conversations c ON c.id = m.conversation_id AND c.company_id = m.company_id
      WHERE m.company_id = ? AND m.conversation_id = ? AND m.user_id = ? AND m.left_at IS NULL LIMIT 1');
    $st->execute([$companyId, $convId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('chat_assert_member')) {
  function chat_assert_member(PDO $pdo, int $companyId, int $convId, int $userId): array {
    $m = $convId > 0 ? chat_member($pdo, $companyId, $convId, $userId) : null;
    if (!$m) throw new RuntimeException('Conversa não encontrada.');
    return $m;
  }
}

if (!function_exists('chat_member_ids')) {
  function chat_member_ids(PDO $pdo, int $companyId, int $convId): array {
    $st = $pdo->prepare('SELECT user_id FROM chat_members WHERE company_id = ? AND conversation_id = ? AND left_at IS NULL');
    $st->execute([$companyId, $convId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('chat_notify')) {
  /** Avisa as telas abertas (só ids; quem não participa ignora). */
  function chat_notify(int $companyId, int $convId, array $members, int $by, string $what = 'message'): void {
    if (!function_exists('nexa_realtime_publish_event') && is_file(__DIR__ . '/brain_realtime.php')) require_once __DIR__ . '/brain_realtime.php';
    if (!function_exists('nexa_realtime_publish_event')) return;
    try { nexa_realtime_publish_event($companyId, 'chat.message', 'chat_conversation', $convId, ['members' => array_values($members), 'by' => $by, 'what' => $what]); } catch (Throwable $e) {}
  }
}

if (!function_exists('chat_list')) {
  /** Conversas do usuário, da mais recente para a mais antiga, com não lidas. */
  function chat_list(PDO $pdo, int $companyId, int $userId): array {
    $users = chat_users($pdo, $companyId);
    $st = $pdo->prepare("SELECT c.id, c.kind, c.name, c.direct_key, c.created_by, c.last_message_at, c.last_preview, c.created_at, m.last_read_id,
        (SELECT COUNT(*) FROM chat_messages x WHERE x.company_id = c.company_id AND x.conversation_id = c.id AND x.id > m.last_read_id AND x.deleted_at IS NULL AND (x.user_id IS NULL OR x.user_id <> m.user_id)) AS unread
      FROM chat_members m JOIN chat_conversations c ON c.id = m.conversation_id AND c.company_id = m.company_id
      WHERE m.company_id = ? AND m.user_id = ? AND m.left_at IS NULL
      ORDER BY COALESCE(c.last_message_at, c.created_at) DESC, c.id DESC LIMIT 200");
    $st->execute([$companyId, $userId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $other = 0;
      if ($r['kind'] === 'DIRETA') {
        foreach (explode('-', (string)$r['direct_key']) as $p) if ((int)$p !== $userId) $other = (int)$p;
      }
      $out[] = [
        'id' => (int)$r['id'],
        'kind' => (string)$r['kind'],
        'name' => $r['kind'] === 'GRUPO' ? (string)$r['name'] : ($users[$other] ?? 'Usuário removido'),
        'other_id' => $other,
        'preview' => (string)($r['last_preview'] ?? ''),
        'last_at' => (string)($r['last_message_at'] ?? $r['created_at']),
        'unread' => (int)$r['unread'],
        'admin' => (int)$r['created_by'] === $userId,
      ];
    }
    return $out;
  }
}

if (!function_exists('chat_unread_total')) {
  function chat_unread_total(PDO $pdo, int $companyId, int $userId): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM chat_members m JOIN chat_messages x ON x.company_id = m.company_id AND x.conversation_id = m.conversation_id
      WHERE m.company_id = ? AND m.user_id = ? AND m.left_at IS NULL AND x.id > m.last_read_id AND x.deleted_at IS NULL AND (x.user_id IS NULL OR x.user_id <> m.user_id)");
    $st->execute([$companyId, $userId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('chat_open_direct')) {
  /** Conversa direta entre dois usuários (cria na primeira vez). */
  function chat_open_direct(PDO $pdo, int $companyId, int $userId, int $otherId): int {
    if ($otherId === $userId || $otherId <= 0) throw new RuntimeException('Escolha outra pessoa.');
    $users = chat_users($pdo, $companyId);
    if (!isset($users[$otherId])) throw new RuntimeException('Usuário não encontrado.');
    $key = min($userId, $otherId) . '-' . max($userId, $otherId);
    $st = $pdo->prepare('SELECT id FROM chat_conversations WHERE company_id = ? AND direct_key = ? LIMIT 1');
    $st->execute([$companyId, $key]);
    $id = (int)$st->fetchColumn();
    if ($id) {
      // Garante as duas pessoas como participantes (ex.: conversa antiga).
      foreach ([$userId, $otherId] as $u) {
        $pdo->prepare('INSERT IGNORE INTO chat_members (conversation_id, company_id, user_id, joined_at) VALUES (?,?,?,?)')->execute([$id, $companyId, $u, date('Y-m-d H:i:s')]);
      }
      return $id;
    }
    try {
      $pdo->prepare("INSERT INTO chat_conversations (company_id, kind, direct_key, created_by, created_at) VALUES (?, 'DIRETA', ?, ?, ?)")->execute([$companyId, $key, $userId, date('Y-m-d H:i:s')]);
      $id = (int)$pdo->lastInsertId();
    } catch (PDOException $e) {
      if ((string)$e->getCode() !== '23000') throw $e;
      $st->execute([$companyId, $key]);
      return (int)$st->fetchColumn();
    }
    foreach ([$userId, $otherId] as $u) {
      $pdo->prepare('INSERT INTO chat_members (conversation_id, company_id, user_id, joined_at) VALUES (?,?,?,?)')->execute([$id, $companyId, $u, date('Y-m-d H:i:s')]);
    }
    return $id;
  }
}

if (!function_exists('chat_system')) {
  function chat_system(PDO $pdo, int $companyId, int $convId, string $text): void {
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO chat_messages (company_id, conversation_id, user_id, body, created_at) VALUES (?,?,NULL,?,?)')->execute([$companyId, $convId, mb_substr($text, 0, 500), $now]);
  }
}

if (!function_exists('chat_group_save')) {
  /**
   * Cria ($convId = 0) ou altera um grupo: nome e participantes. Só quem criou o grupo
   * (ou o administrador da empresa) altera. Quem sai continua no histórico (left_at).
   */
  function chat_group_save(PDO $pdo, int $companyId, int $userId, int $convId, string $name, array $memberIds, bool $isAdmin = false): int {
    if ($convId > 0) {
      $m = chat_assert_member($pdo, $companyId, $convId, $userId);
      if ($m['kind'] !== 'GRUPO') throw new RuntimeException('Esta conversa não é um grupo.');
      if ((int)$m['created_by'] !== $userId && !$isAdmin) throw new RuntimeException('Só quem criou o grupo pode alterá-lo.');
    }
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', $name) ?? ''), 0, 80);
    if ($name === '') throw new RuntimeException('Dê um nome ao grupo.');
    $users = chat_users($pdo, $companyId);
    $members = array_values(array_unique(array_filter(array_map('intval', $memberIds), static fn($v) => isset($users[$v]))));
    if (!in_array($userId, $members, true)) $members[] = $userId;
    if (count($members) < 2) throw new RuntimeException('Escolha pelo menos uma pessoa para o grupo.');
    $now = date('Y-m-d H:i:s');
    $me = $users[$userId] ?? 'Alguém';
    if ($convId <= 0) {
      $pdo->prepare("INSERT INTO chat_conversations (company_id, kind, name, created_by, created_at) VALUES (?, 'GRUPO', ?, ?, ?)")->execute([$companyId, $name, $userId, $now]);
      $convId = (int)$pdo->lastInsertId();
      foreach ($members as $u) {
        $pdo->prepare('INSERT INTO chat_members (conversation_id, company_id, user_id, is_admin, joined_at) VALUES (?,?,?,?,?)')->execute([$convId, $companyId, $u, $u === $userId ? 1 : 0, $now]);
      }
      chat_system($pdo, $companyId, $convId, $me . ' criou o grupo "' . $name . '".');
    } else {
      if ($name !== (string)$m['name']) {
        $pdo->prepare('UPDATE chat_conversations SET name = ? WHERE company_id = ? AND id = ?')->execute([$name, $companyId, $convId]);
        chat_system($pdo, $companyId, $convId, $me . ' mudou o nome do grupo para "' . $name . '".');
      }
      $current = chat_member_ids($pdo, $companyId, $convId);
      foreach (array_diff($members, $current) as $u) {
        $pdo->prepare('INSERT INTO chat_members (conversation_id, company_id, user_id, joined_at) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE left_at = NULL, joined_at = VALUES(joined_at)')->execute([$convId, $companyId, $u, $now]);
        chat_system($pdo, $companyId, $convId, $me . ' adicionou ' . ($users[$u] ?? 'alguém') . '.');
      }
      foreach (array_diff($current, $members) as $u) {
        $pdo->prepare('UPDATE chat_members SET left_at = ? WHERE company_id = ? AND conversation_id = ? AND user_id = ?')->execute([$now, $companyId, $convId, $u]);
        chat_system($pdo, $companyId, $convId, $me . ' removeu ' . ($users[$u] ?? 'alguém') . '.');
      }
    }
    chat_touch($pdo, $companyId, $convId);
    chat_read_all($pdo, $companyId, $convId, $userId); // os avisos que a própria pessoa gerou não contam como não lidos
    chat_notify($companyId, $convId, chat_member_ids($pdo, $companyId, $convId), $userId, 'group');
    return $convId;
  }
}

if (!function_exists('chat_group_leave')) {
  function chat_group_leave(PDO $pdo, int $companyId, int $userId, int $convId): void {
    $m = chat_assert_member($pdo, $companyId, $convId, $userId);
    if ($m['kind'] !== 'GRUPO') throw new RuntimeException('Só é possível sair de grupos.');
    $members = chat_member_ids($pdo, $companyId, $convId);
    $pdo->prepare('UPDATE chat_members SET left_at = ? WHERE company_id = ? AND conversation_id = ? AND user_id = ?')->execute([date('Y-m-d H:i:s'), $companyId, $convId, $userId]);
    chat_system($pdo, $companyId, $convId, (chat_users($pdo, $companyId)[$userId] ?? 'Alguém') . ' saiu do grupo.');
    chat_touch($pdo, $companyId, $convId);
    chat_notify($companyId, $convId, $members, $userId, 'group');
  }
}

if (!function_exists('chat_read_all')) {
  function chat_read_all(PDO $pdo, int $companyId, int $convId, int $userId): void {
    $pdo->prepare('UPDATE chat_members SET last_read_id = (SELECT COALESCE(MAX(id), 0) FROM chat_messages WHERE company_id = ? AND conversation_id = ?) WHERE company_id = ? AND conversation_id = ? AND user_id = ?')
      ->execute([$companyId, $convId, $companyId, $convId, $userId]);
  }
}

if (!function_exists('chat_touch')) {
  /** Atualiza a prévia e a hora da última mensagem da conversa. */
  function chat_touch(PDO $pdo, int $companyId, int $convId): void {
    $st = $pdo->prepare('SELECT id, user_id, body, media_name, deleted_at, created_at FROM chat_messages WHERE company_id = ? AND conversation_id = ? ORDER BY id DESC LIMIT 1');
    $st->execute([$companyId, $convId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return;
    $preview = !empty($m['deleted_at']) ? '🚫 Mensagem apagada' : (trim((string)$m['body']) !== '' ? (string)$m['body'] : '📎 ' . (string)($m['media_name'] ?? 'Arquivo'));
    $pdo->prepare('UPDATE chat_conversations SET last_message_id = ?, last_message_at = ?, last_preview = ? WHERE company_id = ? AND id = ?')
      ->execute([(int)$m['id'], (string)$m['created_at'], mb_substr(preg_replace('/\s+/', ' ', $preview) ?? '', 0, 200), $companyId, $convId]);
  }
}

if (!function_exists('chat_media_root')) {
  function chat_media_root(): string {
    $app = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $base = trim((string)(getenv('NEXA_CHAT_DIR') ?: ''));
    if ($base === '') $base = dirname($app) . '/nexa_private/chat/' . basename($app);
    if ($base[0] !== '/') throw new RuntimeException('A pasta de arquivos do chat precisa ser um caminho absoluto.');
    if (!is_dir($base) && !@mkdir($base, 0700, true)) throw new RuntimeException('Não foi possível criar a pasta de arquivos do chat.');
    $root = realpath($base);
    if ($root === false || !is_writable($root)) throw new RuntimeException('A pasta de arquivos do chat está sem permissão de gravação.');
    if ($root === $app || str_starts_with($root, $app . '/')) throw new RuntimeException('A pasta de arquivos do chat não pode ficar dentro da pasta pública.');
    return $root;
  }
}

if (!function_exists('chat_media_path')) {
  function chat_media_path(int $companyId, string $rel): ?string {
    if (!preg_match('#^' . $companyId . '/\d{6}/[a-f0-9]{24}\.[a-z0-9]{1,5}$#', $rel)) return null;
    $path = chat_media_root() . '/' . $rel;
    return is_file($path) ? $path : null;
  }
}

if (!function_exists('chat_send')) {
  /** Envia texto e/ou arquivo ($file = item de $_FILES). Retorna o id da mensagem. */
  function chat_send(PDO $pdo, int $companyId, int $userId, int $convId, string $text, ?array $file = null, int $replyTo = 0): int {
    chat_assert_member($pdo, $companyId, $convId, $userId);
    $text = trim($text);
    if (mb_strlen($text) > 4000) throw new RuntimeException('Mensagem muito longa (máximo 4.000 caracteres).');
    $media = [null, null, null, null];
    if ($file && ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
      if (($file['error'] ?? 0) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
        throw new RuntimeException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Arquivo maior que o permitido pelo servidor (' . ini_get('upload_max_filesize') . ').' : 'Não foi possível receber o arquivo.');
      }
      $size = (int)$file['size'];
      if ($size <= 0 || $size > CHAT_MAX_FILE_BYTES) throw new RuntimeException('O arquivo deve ter no máximo 16 MB.');
      $name = mb_substr(basename((string)$file['name']) ?: 'arquivo', 0, 150);
      $mime = '';
      if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_file($fi, (string)$file['tmp_name']); finfo_close($fi); }
      if ($mime === '') $mime = (string)($file['type'] ?? 'application/octet-stream');
      $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
      if (!preg_match('/^[a-z0-9]{1,5}$/', $ext)) $ext = 'bin';
      $rel = $companyId . '/' . date('Ym');
      $dir = chat_media_root() . '/' . $rel;
      if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Não foi possível gravar o arquivo.');
      $fileName = bin2hex(random_bytes(12)) . '.' . $ext;
      if (!@move_uploaded_file((string)$file['tmp_name'], $dir . '/' . $fileName)) throw new RuntimeException('Não foi possível gravar o arquivo.');
      $media = [$rel . '/' . $fileName, mb_substr($mime, 0, 100), $name, $size];
    }
    if ($text === '' && $media[0] === null) throw new RuntimeException('Digite a mensagem.');
    if ($replyTo > 0) {
      $q = $pdo->prepare('SELECT id FROM chat_messages WHERE company_id = ? AND conversation_id = ? AND id = ? AND deleted_at IS NULL');
      $q->execute([$companyId, $convId, $replyTo]);
      if (!$q->fetchColumn()) $replyTo = 0;
    }
    $pdo->prepare('INSERT INTO chat_messages (company_id, conversation_id, user_id, body, media_path, media_mime, media_name, media_size, reply_to_id, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, $convId, $userId, $text !== '' ? $text : null, $media[0], $media[1], $media[2], $media[3], $replyTo ?: null, date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    $pdo->prepare('UPDATE chat_members SET last_read_id = ? WHERE company_id = ? AND conversation_id = ? AND user_id = ?')->execute([$id, $companyId, $convId, $userId]);
    chat_touch($pdo, $companyId, $convId);
    chat_notify($companyId, $convId, chat_member_ids($pdo, $companyId, $convId), $userId);
    return $id;
  }
}

if (!function_exists('chat_messages')) {
  /** Mensagens da conversa (as 150 últimas, ou as novas depois de $after). */
  function chat_messages(PDO $pdo, int $companyId, int $userId, int $convId, int $after = 0): array {
    chat_assert_member($pdo, $companyId, $convId, $userId);
    $cols = 'm.id, m.user_id, m.body, m.media_path, m.media_mime, m.media_name, m.media_size, m.reply_to_id, m.deleted_at, m.created_at, u.name AS user_name';
    $join = 'LEFT JOIN users u ON u.id = m.user_id';
    if ($after > 0) {
      $st = $pdo->prepare("SELECT $cols FROM chat_messages m $join WHERE m.company_id = ? AND m.conversation_id = ? AND m.id > ? ORDER BY m.id LIMIT 300");
      $st->execute([$companyId, $convId, $after]);
    } else {
      $st = $pdo->prepare("SELECT * FROM (SELECT $cols FROM chat_messages m $join WHERE m.company_id = ? AND m.conversation_id = ? ORDER BY m.id DESC LIMIT 150) t ORDER BY id");
      $st->execute([$companyId, $convId]);
    }
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $quoteIds = array_values(array_unique(array_filter(array_map(static fn($r) => (int)($r['reply_to_id'] ?? 0), $rows))));
    $quotes = [];
    if ($quoteIds) {
      $q = $pdo->prepare('SELECT m.id, m.body, m.media_name, m.deleted_at, u.name AS user_name FROM chat_messages m LEFT JOIN users u ON u.id = m.user_id WHERE m.company_id = ? AND m.conversation_id = ? AND m.id IN (' . implode(',', array_fill(0, count($quoteIds), '?')) . ')');
      $q->execute(array_merge([$companyId, $convId], $quoteIds));
      foreach ($q->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        $t = !empty($r['deleted_at']) ? '🚫 Mensagem apagada' : (trim((string)$r['body']) !== '' ? (string)$r['body'] : '📎 ' . (string)$r['media_name']);
        $quotes[(int)$r['id']] = ['id' => (int)$r['id'], 'who' => (string)($r['user_name'] ?? ''), 'text' => mb_strimwidth(preg_replace('/\s+/', ' ', $t) ?? '', 0, 160, '…')];
      }
    }
    // Leitura: até onde cada participante leu (para o "visto").
    $reads = [];
    $st = $pdo->prepare('SELECT user_id, last_read_id FROM chat_members WHERE company_id = ? AND conversation_id = ? AND left_at IS NULL AND user_id <> ?');
    $st->execute([$companyId, $convId, $userId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $reads[] = (int)$r['last_read_id'];
    $out = [];
    foreach ($rows as $r) {
      $del = !empty($r['deleted_at']);
      $id = (int)$r['id'];
      $out[] = [
        'id' => $id,
        'user_id' => (int)($r['user_id'] ?? 0),
        'user' => (string)($r['user_name'] ?? ''),
        'system' => $r['user_id'] === null,
        'body' => $del ? '' : (string)($r['body'] ?? ''),
        'media' => !$del && !empty($r['media_path']),
        'mime' => $del ? '' : (string)($r['media_mime'] ?? ''),
        'name' => $del ? '' : (string)($r['media_name'] ?? ''),
        'size' => (int)($r['media_size'] ?? 0),
        'reply' => $del ? null : ($quotes[(int)($r['reply_to_id'] ?? 0)] ?? null),
        'del' => $del,
        'at' => (string)$r['created_at'],
        // Lida por todos os outros participantes?
        'read' => $reads && min($reads) >= $id,
      ];
    }
    return $out;
  }
}

if (!function_exists('chat_mark_read')) {
  function chat_mark_read(PDO $pdo, int $companyId, int $userId, int $convId, int $lastId): void {
    chat_assert_member($pdo, $companyId, $convId, $userId);
    $st = $pdo->prepare('UPDATE chat_members SET last_read_id = ? WHERE company_id = ? AND conversation_id = ? AND user_id = ? AND last_read_id < ?');
    $st->execute([$lastId, $companyId, $convId, $userId, $lastId]);
    if ($st->rowCount() > 0) chat_notify($companyId, $convId, chat_member_ids($pdo, $companyId, $convId), $userId, 'read');
  }
}

if (!function_exists('chat_delete')) {
  /** Apaga a própria mensagem (some para todos; o registro fica). */
  function chat_delete(PDO $pdo, int $companyId, int $userId, int $convId, int $messageId): void {
    chat_assert_member($pdo, $companyId, $convId, $userId);
    $st = $pdo->prepare('UPDATE chat_messages SET deleted_at = ? WHERE company_id = ? AND conversation_id = ? AND id = ? AND user_id = ? AND deleted_at IS NULL');
    $st->execute([date('Y-m-d H:i:s'), $companyId, $convId, $messageId, $userId]);
    if ($st->rowCount() < 1) throw new RuntimeException('Só é possível apagar as suas próprias mensagens.');
    chat_touch($pdo, $companyId, $convId);
    chat_notify($companyId, $convId, chat_member_ids($pdo, $companyId, $convId), $userId, 'deleted');
  }
}

if (!function_exists('chat_conversation_info')) {
  function chat_conversation_info(PDO $pdo, int $companyId, int $userId, int $convId): array {
    $m = chat_assert_member($pdo, $companyId, $convId, $userId);
    $users = chat_users($pdo, $companyId);
    $members = chat_member_ids($pdo, $companyId, $convId);
    $item = null;
    foreach (chat_list($pdo, $companyId, $userId) as $c) if ($c['id'] === $convId) $item = $c;
    return [
      'id' => $convId,
      'kind' => (string)$m['kind'],
      'name' => $item['name'] ?? (string)$m['name'],
      'admin' => (int)$m['created_by'] === $userId,
      'members' => array_map(static fn($u) => ['id' => $u, 'name' => $users[$u] ?? 'Usuário removido'], $members),
    ];
  }
}
