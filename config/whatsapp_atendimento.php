<?php
declare(strict_types=1);

/**
 * Tela de Atendimento do WhatsApp (estilo WhatsApp Web): fila por setor,
 * aceitar, conversar (texto e arquivos), transferir, gerar CRM e finalizar.
 * Regras das conversas/mensagens ficam em config/whatsapp_chatbot.php.
 */

require_once __DIR__ . '/whatsapp_chatbot.php';
require_once __DIR__ . '/person_contacts.php';

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
      // "Atendendo" (todas em atendimento) saiu: quem atende cada conversa aparece no Painel Ao Vivo.
      'robo' => ['No robô', "s.status='BOT'"],
      'finalizados' => ['Finalizados', "s.status='FINALIZADO'"],
      // Grupos do WhatsApp (só com a opção ligada; o número conta os grupos com mensagens não lidas).
      'grupos' => ['Grupos', "s.status='GRUPO'"],
    ];
  }
}

if (!function_exists('whatsapp_att_list')) {
  function whatsapp_att_list(PDO $pdo, int $companyId, int $userId, bool $supervisor, string $tab, string $q = ''): array {
    $tabs = whatsapp_att_tabs();
    if (!isset($tabs[$tab])) $tab = 'fila';
    [$scope, $scopeParams] = whatsapp_att_scope($pdo, $companyId, $userId, $supervisor);

    $counts = [];
    // Robô: conversas com movimento nas últimas 24 horas; Finalizados: encerrados hoje.
    $countExtra = [
      'robo' => " AND s.last_message_at >= '" . date('Y-m-d H:i:s', time() - 86400) . "'",
      'finalizados' => " AND s.closed_at >= '" . date('Y-m-d') . " 00:00:00'",
      'grupos' => ' AND s.unread > 0',
    ];
    foreach ($tabs as $key => [$label, $cond]) {
      $c = str_replace(':me', (string)$userId, $cond) . ($countExtra[$key] ?? '');
      $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_bot_sessions s WHERE s.company_id=? AND $c AND $scope");
      $st->execute(array_merge([$companyId], $scopeParams));
      $counts[$key] = (int)$st->fetchColumn();
    }

    $cond = str_replace(':me', (string)$userId, $tabs[$tab][1]);
    $ct = whatsapp_att_contact_sql($pdo);
    $where = "s.company_id=? AND $cond AND $scope";
    $params = array_merge([$companyId], $scopeParams);
    $q = trim($q);
    if ($q !== '') {
      $digits = whatsapp_bot_digits($q);
      $where .= ' AND (s.push_name LIKE ? OR p.name LIKE ? OR p.fantasy_name LIKE ?' . ($ct['search'] !== '' ? ' OR ' . $ct['search'] : '') . ($digits !== '' ? ' OR s.phone LIKE ?' : '') . ')';
      $params[] = '%' . $q . '%';
      $params[] = '%' . $q . '%';
      $params[] = '%' . $q . '%';
      if ($ct['search'] !== '') $params[] = '%' . $q . '%';
      if ($digits !== '') $params[] = '%' . $digits . '%';
    }
    $order = $tab === 'fila' ? 's.queued_at ASC, s.id ASC' : 's.last_message_at DESC, s.id DESC';
    $st = $pdo->prepare("SELECT s.id, s.contact, s.phone, s.push_name, s.status, s.department_id, s.user_id, s.unread, s.last_preview, s.last_direction,
        s.last_message_at, s.queued_at, s.accepted_at, s.person_id, p.name AS person_name, p.fantasy_name AS person_fantasy, d.name AS department_name, u.name AS user_name{$ct['cols']}
      FROM whatsapp_bot_sessions s
      LEFT JOIN finance_people p ON p.company_id=s.company_id AND p.id=s.person_id
      LEFT JOIN whatsapp_departments d ON d.company_id=s.company_id AND d.id=s.department_id
      LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id{$ct['join']}
      WHERE $where ORDER BY $order LIMIT 200");
    $st->execute($params);
    $rows = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $rows[] = whatsapp_att_row($r);
    return ['tab' => $tab, 'counts' => $counts, 'items' => $rows];
  }
}

if (!function_exists('whatsapp_att_live')) {
  /**
   * Painel Ao Vivo: conversas na fila (Pendentes) e as que estão com cada atendente agora.
   * Atendentes = quem está nos setores do chatbot + quem tem conversa aberta.
   * Conversa "respondida pelo celular" (em atendimento sem usuário) vai para o grupo "Pelo celular".
   */
  function whatsapp_att_live(PDO $pdo, int $companyId): array {
    $ct = whatsapp_att_contact_sql($pdo);
    $st = $pdo->prepare("SELECT s.id, s.contact, s.phone, s.push_name, s.status, s.department_id, s.user_id, s.unread, s.last_preview, s.last_direction,
        s.last_message_at, s.queued_at, s.accepted_at, s.person_id, p.name AS person_name, p.fantasy_name AS person_fantasy, d.name AS department_name, u.name AS user_name{$ct['cols']}
      FROM whatsapp_bot_sessions s
      LEFT JOIN finance_people p ON p.company_id=s.company_id AND p.id=s.person_id
      LEFT JOIN whatsapp_departments d ON d.company_id=s.company_id AND d.id=s.department_id
      LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id{$ct['join']}
      WHERE s.company_id=? AND s.status IN ('PENDENTE','ATENDIMENTO')
      ORDER BY s.status='ATENDIMENTO', s.queued_at ASC, s.accepted_at ASC, s.id ASC LIMIT 1000");
    $st->execute([$companyId]);
    $pending = []; $byUser = []; $phone = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $row = whatsapp_att_row($r);
      if ($row['status'] === 'PENDENTE') $pending[] = $row;
      elseif ($row['user_id'] > 0) $byUser[$row['user_id']][] = $row;
      else $phone[] = $row;
    }
    $deps = [];
    foreach (whatsapp_departments($pdo, $companyId) as $d) $deps[(int)$d['id']] = (string)$d['name'];
    $userDeps = [];
    foreach (whatsapp_department_members($pdo, $companyId) as $depId => $uids) {
      if (!isset($deps[$depId])) continue;
      foreach ($uids as $u) $userDeps[(int)$u][] = $deps[$depId];
    }
    $names = function_exists('whatsapp_painel_user_names') ? whatsapp_painel_user_names($pdo, $companyId) : [];
    $ids = array_values(array_unique(array_merge(array_keys($userDeps), array_keys($byUser))));
    $attendants = [];
    foreach ($ids as $u) {
      $name = $names[$u] ?? '';
      if ($name === '' && empty($byUser[$u])) continue; // usuário de outra empresa/excluído sem conversa
      $attendants[] = [
        'id' => $u,
        'name' => $name !== '' ? $name : ('Usuário #' . $u),
        'departments' => $userDeps[$u] ?? [],
        'items' => $byUser[$u] ?? [],
      ];
    }
    // Quem tem mais conversas primeiro; depois em ordem alfabética.
    usort($attendants, static fn($a, $b) => [count($b['items']), mb_strtolower($a['name'])] <=> [count($a['items']), mb_strtolower($b['name'])]);
    $depList = [];
    foreach ($deps as $id => $name) $depList[] = ['id' => $id, 'name' => $name];
    return ['pending' => $pending, 'attendants' => $attendants, 'phone' => $phone, 'departments' => $depList, 'now' => date('Y-m-d H:i:s')];
  }
}

if (!function_exists('whatsapp_att_phone_label')) {
  function whatsapp_att_phone_label(string $d): string {
    $d = whatsapp_bot_digits($d);
    if (strlen($d) === 13 && str_starts_with($d, '55')) return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 5) . '-' . substr($d, 9);
    if (strlen($d) === 12 && str_starts_with($d, '55')) {
      // Celular que o WhatsApp informa sem o 9 (número antigo de 8 dígitos): mostra com o 9.
      if (in_array($d[4], ['6', '7', '8', '9'], true)) return '(' . substr($d, 2, 2) . ') 9' . substr($d, 4, 4) . '-' . substr($d, 8);
      return '(' . substr($d, 2, 2) . ') ' . substr($d, 4, 4) . '-' . substr($d, 8);
    }
    return $d;
  }
}

if (!function_exists('whatsapp_att_row')) {
  function whatsapp_att_row(array $r): array {
    // Contato cadastrado (quem chama) vem primeiro; a empresa/pessoa dele aparece junto.
    $contactName = trim((string)($r['contact_name'] ?? ''));
    // Cliente aparece pelo nome fantasia (razão social quando não houver).
    $personLabel = trim((string)($r['person_fantasy'] ?? '')) ?: trim((string)($r['person_name'] ?? ''));
    $name = $contactName !== '' ? $contactName : ($personLabel ?: trim((string)($r['push_name'] ?? '')));
    if ((string)($r['status'] ?? '') === 'GRUPO') $name = trim((string)($r['push_name'] ?? '')) ?: 'Grupo do WhatsApp';
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
      'accepted_at' => (string)($r['accepted_at'] ?? ''),
      'person_id' => (int)($r['person_id'] ?? 0),
      'contact_id' => (int)($r['contact_id'] ?? 0),
      'contact_role' => trim((string)($r['contact_role'] ?? '')),
      'tags' => person_contact_tags_list((string)($r['contact_tags'] ?? '')),
      'company_label' => $contactName !== '' ? $personLabel : '',
      'is_group' => (string)$r['status'] === 'GRUPO',
    ];
  }
}

if (!function_exists('whatsapp_att_contact_sql')) {
  /** Junção com o cadastro de Contatos (quando instalado). */
  function whatsapp_att_contact_sql(PDO $pdo): array {
    if (!function_exists('whatsapp_bot_sessions_has_contact') || !whatsapp_bot_sessions_has_contact($pdo)) return ['cols' => '', 'join' => '', 'search' => ''];
    $tags = person_contacts_has_tags($pdo);
    return [
      'cols' => ', s.contact_id, ct.name AS contact_name, ct.role AS contact_role' . ($tags ? ', ct.tags AS contact_tags' : ''),
      'join' => "\n      LEFT JOIN person_contacts ct ON ct.company_id=s.company_id AND ct.id=s.contact_id",
      // Busca também pelas etiquetas do contato.
      'search' => $tags ? "CONCAT_WS(' ', ct.name, ct.tags) LIKE ?" : 'ct.name LIKE ?',
    ];
  }
}

if (!function_exists('whatsapp_att_chat_info')) {
  function whatsapp_att_chat_info(PDO $pdo, int $companyId, int $chatId): array {
    $ct = whatsapp_att_contact_sql($pdo);
    $st = $pdo->prepare("SELECT s.*, p.name AS person_name, p.fantasy_name AS person_fantasy, d.name AS department_name, u.name AS user_name{$ct['cols']}
      FROM whatsapp_bot_sessions s
      LEFT JOIN finance_people p ON p.company_id=s.company_id AND p.id=s.person_id
      LEFT JOIN whatsapp_departments d ON d.company_id=s.company_id AND d.id=s.department_id
      LEFT JOIN users u ON u.company_id=s.company_id AND u.id=s.user_id{$ct['join']}
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

if (!function_exists('whatsapp_att_doc_label')) {
  /** CPF/CNPJ com pontuação. */
  function whatsapp_att_doc_label(string $doc): string {
    $d = preg_replace('/\D+/', '', $doc) ?? '';
    if (strlen($d) === 14) return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12);
    if (strlen($d) === 11) return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
    return trim($doc);
  }
}

if (!function_exists('whatsapp_att_profile')) {
  /** Dados completos de quem está na conversa: contato, cliente (pessoa) e outros contatos do cliente. */
  function whatsapp_att_profile(PDO $pdo, int $companyId, array $chat): array {
    if (!function_exists('person_contacts_ready')) require_once __DIR__ . '/person_contacts.php';
    $ready = person_contacts_ready($pdo) && whatsapp_bot_sessions_has_contact($pdo);
    $out = [
      'phone' => whatsapp_att_phone_label((string)($chat['phone'] ?? '')),
      'push_name' => trim((string)($chat['push_name'] ?? '')),
      'contacts_ready' => $ready,
      'contact' => null,
      'person' => null,
      'contacts' => [],
      'tags_ready' => $ready && person_contacts_has_tags($pdo),
      'all_tags' => $ready ? person_contact_all_tags($pdo, $companyId) : [],
    ];
    $contactId = $ready ? (int)($chat['contact_id'] ?? 0) : 0;
    if ($contactId > 0 && ($c = person_contact_get($pdo, $companyId, $contactId))) {
      $out['contact'] = [
        'id' => (int)$c['id'], 'name' => (string)$c['name'], 'role' => (string)($c['role'] ?? ''),
        'whatsapp' => (string)($c['whatsapp'] ?? ''), 'phone' => (string)($c['phone'] ?? ''), 'email' => (string)($c['email'] ?? ''),
        'notes' => (string)($c['notes'] ?? ''), 'is_primary' => (int)$c['is_primary'], 'person_id' => (int)($c['person_id'] ?? 0),
        'tags' => (string)($c['tags'] ?? ''),
      ];
    }
    $personId = (int)($chat['person_id'] ?? 0);
    if ($out['contact'] && $out['contact']['person_id'] > 0) $personId = $out['contact']['person_id'];
    if ($personId > 0) {
      $st = $pdo->prepare('SELECT * FROM finance_people WHERE company_id = ? AND id = ? LIMIT 1');
      $st->execute([$companyId, $personId]);
      if ($p = $st->fetch(PDO::FETCH_ASSOC)) {
        $street = trim((string)($p['address'] ?? ''));
        $num = trim((string)($p['number'] ?? ''));
        $addr = array_filter([
          $street !== '' ? $street . ($num !== '' ? ', ' . $num : '') : '',
          trim((string)($p['complement'] ?? '')),
          trim((string)($p['district'] ?? '')),
          trim(trim((string)($p['city'] ?? '')) . '/' . trim((string)($p['state'] ?? '')), '/'),
          trim((string)($p['zip_code'] ?? '')) !== '' ? 'CEP ' . trim((string)$p['zip_code']) : '',
        ]);
        $out['person'] = [
          'id' => (int)$p['id'], 'name' => (string)$p['name'], 'fantasy_name' => (string)($p['fantasy_name'] ?? ''),
          'document' => whatsapp_att_doc_label((string)($p['document'] ?? '')), 'person_type' => (string)($p['person_type'] ?? ''),
          'email' => (string)($p['email'] ?? ''), 'phone' => (string)($p['phone'] ?? ''), 'whatsapp' => (string)($p['whatsapp'] ?? ''),
          'address' => implode(' · ', $addr), 'notes' => mb_strimwidth((string)($p['notes'] ?? ''), 0, 400, '…'),
          'url' => 'financeiro_pessoas.php?edit=' . (int)$p['id'],
        ];
        foreach (person_contacts_by_person($pdo, $companyId, (int)$p['id']) as $c) {
          if (!(int)$c['active'] || (int)$c['id'] === $contactId) continue;
          $out['contacts'][] = ['name' => (string)$c['name'], 'role' => (string)($c['role'] ?? ''), 'phone' => trim((string)($c['whatsapp'] ?? '')) ?: (string)($c['phone'] ?? ''), 'email' => (string)($c['email'] ?? '')];
        }
      }
    }
    return $out;
  }
}

if (!function_exists('whatsapp_att_contact_save')) {
  /** Cadastra (ou altera) o contato desta conversa e liga a conversa ao cliente escolhido. */
  function whatsapp_att_contact_save(PDO $pdo, int $companyId, array $chat, array $in): int {
    if (!function_exists('person_contact_save')) require_once __DIR__ . '/person_contacts.php';
    if (!person_contacts_ready($pdo) || !whatsapp_bot_sessions_has_contact($pdo)) throw new RuntimeException('Aplique "Contatos das pessoas" no Atualizador do Banco.');
    $cur = (int)($chat['contact_id'] ?? 0) > 0 ? person_contact_get($pdo, $companyId, (int)$chat['contact_id']) : null;
    $data = [
      'id' => $cur ? (int)$cur['id'] : 0,
      'person_id' => (int)($in['person_id'] ?? 0),
      'name' => (string)($in['name'] ?? ''),
      'role' => (string)($in['role'] ?? ''),
      'email' => (string)($in['email'] ?? ''),
      'notes' => (string)($in['notes'] ?? ''),
      'tags' => array_key_exists('tags', $in) ? (string)$in['tags'] : ($cur ? (string)($cur['tags'] ?? '') : ''),
      'whatsapp' => $cur && trim((string)($cur['whatsapp'] ?? '')) !== '' ? (string)$cur['whatsapp'] : whatsapp_att_phone_label((string)($chat['phone'] ?? '')),
      'phone' => $cur ? (string)($cur['phone'] ?? '') : '',
      'is_primary' => $cur ? (int)$cur['is_primary'] : 0,
      'active' => 1,
    ];
    $id = person_contact_save($pdo, $companyId, $data);
    $upd = ['contact_id' => $id];
    if ($data['person_id'] > 0) $upd['person_id'] = $data['person_id'];
    elseif ($cur && (int)($cur['person_id'] ?? 0) > 0 && (int)($chat['person_id'] ?? 0) === (int)$cur['person_id']) $upd['person_id'] = null;
    whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], $upd);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'contato']);
    return $id;
  }
}

if (!function_exists('whatsapp_att_messages')) {
  function whatsapp_att_messages(PDO $pdo, int $companyId, int $chatId, int $afterId = 0): array {
    $v3 = whatsapp_chat_has_v3($pdo);
    $cols = 'm.id, m.direction, m.sender, m.user_id, m.message_id, m.msg_type, m.body, m.media_path, m.media_mime, m.media_name, m.media_size, m.send_ok, m.created_at, u.name AS user_name'
      . (whatsapp_chat_has_wa_status($pdo) ? ', m.wa_status' : ', NULL AS wa_status')
      . ($v3 ? ', m.reply_to_id, m.quoted_message_id, m.forwarded, m.deleted_at, m.deleted_for_all, m.participant_phone, m.participant_name' : '')
      . (whatsapp_chat_has_v4($pdo) ? ', m.edited_at' : ', NULL AS edited_at');
    $join = 'LEFT JOIN users u ON u.company_id=m.company_id AND u.id=m.user_id';
    if ($afterId > 0) {
      $st = $pdo->prepare("SELECT $cols FROM whatsapp_bot_messages m $join WHERE m.company_id=? AND m.session_id=? AND m.id>? ORDER BY m.id LIMIT 300");
      $st->execute([$companyId, $chatId, $afterId]);
    } else {
      $st = $pdo->prepare("SELECT * FROM (SELECT $cols FROM whatsapp_bot_messages m $join WHERE m.company_id=? AND m.session_id=? ORDER BY m.id DESC LIMIT 150) t ORDER BY id");
      $st->execute([$companyId, $chatId]);
    }
    $out = [];
    $origins = [];
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $quotes = $v3 ? whatsapp_att_quotes($pdo, $companyId, $chatId, $rows) : [];
    foreach ($rows as $m) {
      $sender = (string)($m['sender'] ?? '');
      $origin = '';
      // Envio automático de outra empresa do grupo (WhatsApp único): mostra o nome dela.
      if (preg_match('/^envio_(\d+)$/', $sender, $mm)) {
        $oc = (int)$mm[1];
        $origins[$oc] = $origins[$oc] ?? whatsapp_bot_company_name($pdo, $oc);
        $origin = $origins[$oc];
        $sender = 'envio';
      }
      $out[] = [
        'origin' => $origin,
        'ws' => $m['wa_status'] !== null ? (int)$m['wa_status'] : null,
        'contacts' => ($m['msg_type'] ?? '') === 'contact' ? whatsapp_vcard_parse((string)($m['body'] ?? '')) : [],
        'id' => (int)$m['id'],
        'dir' => (string)$m['direction'],
        'sender' => $sender !== '' ? $sender : ($m['direction'] === 'IN' ? 'cliente' : 'robo'),
        'user' => (string)($m['user_name'] ?? ''),
        'type' => (string)($m['msg_type'] ?? 'text'),
        'body' => (string)($m['body'] ?? ''),
        'media' => !empty($m['media_path']),
        'mime' => (string)($m['media_mime'] ?? ''),
        'name' => (string)($m['media_name'] ?? ''),
        'size' => (int)($m['media_size'] ?? 0),
        'ok' => (int)($m['send_ok'] ?? 1) === 1,
        'at' => (string)$m['created_at'],
        'fwd' => !empty($m['forwarded']),
        'del' => !empty($m['deleted_at']) ? (!empty($m['deleted_for_all']) ? 2 : 1) : 0,
        'wid' => trim((string)($m['message_id'] ?? '')) !== '',
        'pname' => trim((string)($m['participant_name'] ?? '')),
        'pphone' => whatsapp_att_phone_label((string)($m['participant_phone'] ?? '')),
        'reply' => $quotes[(int)$m['id']] ?? null,
        'uid' => (int)($m['user_id'] ?? 0),
        'edited' => !empty($m['edited_at']),
      ];
      // Apagada: o conteúdo não aparece mais na tela (o registro fica no histórico).
      if (end($out)['del']) {
        $k = array_key_last($out);
        $out[$k]['body'] = ''; $out[$k]['media'] = false; $out[$k]['contacts'] = []; $out[$k]['name'] = ''; $out[$k]['reply'] = null;
      }
    }
    return $out;
  }
}

if (!function_exists('whatsapp_att_quotes')) {
  /** Trecho da mensagem citada (respondida) de cada mensagem: [id => ['id', 'who', 'text']]. */
  function whatsapp_att_quotes(PDO $pdo, int $companyId, int $chatId, array $rows): array {
    $byId = []; $byWa = [];
    foreach ($rows as $m) {
      if ((int)($m['reply_to_id'] ?? 0) > 0) $byId[(int)$m['id']] = (int)$m['reply_to_id'];
      elseif (trim((string)($m['quoted_message_id'] ?? '')) !== '') $byWa[(int)$m['id']] = (string)$m['quoted_message_id'];
    }
    if (!$byId && !$byWa) return [];
    $found = [];
    if ($byId) {
      $ids = array_values(array_unique($byId));
      $st = $pdo->prepare('SELECT m.id, m.message_id, m.direction, m.sender, m.msg_type, m.body, m.media_name, m.deleted_at, m.participant_name, u.name AS user_name FROM whatsapp_bot_messages m LEFT JOIN users u ON u.company_id=m.company_id AND u.id=m.user_id WHERE m.company_id=? AND m.session_id=? AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')');
      $st->execute(array_merge([$companyId, $chatId], $ids));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) $found['i' . $q['id']] = $q;
    }
    if ($byWa) {
      $wids = array_values(array_unique($byWa));
      $st = $pdo->prepare('SELECT m.id, m.message_id, m.direction, m.sender, m.msg_type, m.body, m.media_name, m.deleted_at, m.participant_name, u.name AS user_name FROM whatsapp_bot_messages m LEFT JOIN users u ON u.company_id=m.company_id AND u.id=m.user_id WHERE m.company_id=? AND m.session_id=? AND m.message_id IN (' . implode(',', array_fill(0, count($wids), '?')) . ')');
      $st->execute(array_merge([$companyId, $chatId], $wids));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $q) $found['w' . $q['message_id']] = $q;
    }
    $out = [];
    foreach ($rows as $m) {
      $mid = (int)$m['id'];
      $q = isset($byId[$mid]) ? ($found['i' . $byId[$mid]] ?? null) : (isset($byWa[$mid]) ? ($found['w' . $byWa[$mid]] ?? null) : null);
      if (!$q && !isset($byWa[$mid])) continue;
      if (!$q) { $out[$mid] = ['id' => 0, 'who' => '', 'text' => 'Mensagem anterior']; continue; }
      $who = $q['direction'] === 'IN' ? (trim((string)$q['participant_name']) ?: 'Cliente') : ($q['sender'] === 'robo' ? 'Robô' : ($q['sender'] === 'celular' ? 'Celular' : ((string)($q['user_name'] ?? '') ?: 'Você')));
      $text = !empty($q['deleted_at']) ? '🚫 Mensagem apagada' : (trim((string)$q['body']) !== '' ? (string)$q['body'] : (($q['msg_type'] ?? '') === 'document' && $q['media_name'] ? '📄 ' . $q['media_name'] : whatsapp_chat_type_label((string)($q['msg_type'] ?? 'text'))));
      $out[$mid] = ['id' => (int)$q['id'], 'who' => $who, 'text' => mb_strimwidth(preg_replace('/\s+/', ' ', $text) ?? '', 0, 160, '…')];
    }
    return $out;
  }
}

if (!function_exists('whatsapp_att_ticks')) {
  /** Situação (✓ / ✓✓ / lida / não enviada) das últimas mensagens enviadas, para atualizar os checks na tela. */
  function whatsapp_att_ticks(PDO $pdo, int $companyId, int $chatId): array {
    if (!whatsapp_chat_has_wa_status($pdo)) return [];
    $st = $pdo->prepare("SELECT id, send_ok, wa_status FROM whatsapp_bot_messages WHERE company_id=? AND session_id=? AND direction='OUT' ORDER BY id DESC LIMIT 80");
    $st->execute([$companyId, $chatId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(string)$r['id']] = (int)$r['send_ok'] === 1 ? (int)($r['wa_status'] ?? 0) : -1;
    return $out;
  }
}

if (!function_exists('whatsapp_att_send_contact')) {
  function whatsapp_att_send_contact(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, string $name, string $phone, string $org = ''): bool {
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $ok = whatsapp_chat_send_contact($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), mb_substr($name, 0, 120), $phone, mb_substr(trim($org), 0, 120), $userId);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'ATENDIMENTO']);
    return $ok;
  }
}

if (!function_exists('whatsapp_att_system')) {
  function whatsapp_att_system(PDO $pdo, int $companyId, array $chat, string $text, int $userId): void {
    whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'sistema', $text, ['user_id' => $userId]);
  }
}

if (!function_exists('whatsapp_att_assert_owner')) {
  /** Garante que a conversa está com este usuário; assume automaticamente quando livre. */
  /** Só quem aceitou a conversa envia mensagens (no robô ou na fila é preciso Aceitar antes). */
  function whatsapp_att_assert_owner(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor): array {
    $status = (string)$chat['status'];
    if ($status === 'GRUPO') return $chat;
    if ($status === 'ATENDIMENTO' && (int)$chat['user_id'] === $userId) return $chat;
    if ($status === 'BOT') throw new RuntimeException('O robô está atendendo esta conversa. Clique em Aceitar para responder.');
    if ($status === 'PENDENTE') throw new RuntimeException('Esta conversa está na fila e ainda não foi aceita. Clique em Aceitar para responder.');
    if ($status === 'FINALIZADO') throw new RuntimeException('Esta conversa foi finalizada. Clique em Aceitar para reabrir e responder.');
    if ((int)$chat['user_id'] > 0) throw new RuntimeException('Esta conversa está com outro atendente.' . ($supervisor ? ' Para assumir, use Transferir.' : ''));
    throw new RuntimeException('Esta conversa está sendo respondida pelo celular. Clique em Aceitar para responder por aqui.');
  }
}

if (!function_exists('whatsapp_att_accept')) {
  function whatsapp_att_accept(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, bool $greet = true): array {
    if (($chat['status'] ?? '') === 'GRUPO') throw new RuntimeException('Grupos não precisam ser aceitos: é só escrever.');
    // Conversa já aceita (está em "Meus" de outro atendente): ninguém aceita de novo, nem o supervisor.
    // Para mudar de atendente, quem atende ou o supervisor usa Transferir.
    $taken = static function () use ($pdo, $companyId, $chat): RuntimeException {
      $cur = whatsapp_chat_get($pdo, $companyId, (int)$chat['id']) ?? $chat;
      $who = (int)($cur['user_id'] ?? 0) > 0 ? whatsapp_bot_user_name($pdo, $companyId, (int)$cur['user_id']) : '';
      return new RuntimeException('Esta conversa já foi aceita' . ($who !== '' ? ' por ' . $who : ' por outro atendente') . '. Para mudar o atendente, use Transferir.');
    };
    if ($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId) throw $taken();
    $now = date('Y-m-d H:i:s');
    $chatId = (int)$chat['id'];
    // Evita dois atendentes aceitando a mesma conversa ao mesmo tempo (o UPDATE só passa se ninguém aceitou antes).
    $st = $pdo->prepare("UPDATE whatsapp_bot_sessions SET status='ATENDIMENTO', user_id=?, accepted_at=?, closed_at=NULL, unread=0, state='MENU', paused_until=NULL
      WHERE company_id=? AND id=? AND (status<>'ATENDIMENTO' OR user_id IS NULL OR user_id=0 OR user_id=?)");
    $st->execute([$userId, $now, $companyId, $chatId, $userId]);
    if ($st->rowCount() === 0) throw $taken();
    if (function_exists('whatsapp_painel_transition')) whatsapp_painel_transition($pdo, $companyId, $chatId, $chat, ['status' => 'ATENDIMENTO', 'user_id' => $userId], $now);
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
  function whatsapp_att_send_text(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, string $text, int $replyTo = 0): bool {
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
    $extra = $replyTo > 0 ? ['reply' => whatsapp_att_message_get($pdo, $companyId, (int)$chat['id'], $replyTo)] : [];
    $ok = whatsapp_chat_send_text($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $text, 'atendente', $userId, $wire, $extra);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => (string)$chat['status']]);
    return $ok;
  }
}

if (!function_exists('whatsapp_att_send_file')) {
  function whatsapp_att_send_file(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, array $file, string $caption, bool $voice = false, int $replyTo = 0): bool {
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string)$file['tmp_name'])) {
      throw new RuntimeException(($file['error'] ?? 0) === UPLOAD_ERR_INI_SIZE ? 'Arquivo maior que o permitido pelo servidor (' . ini_get('upload_max_filesize') . '). Peça ao suporte para aumentar o limite de envio de arquivos.' : 'Selecione um arquivo.');
    }
    $size = (int)$file['size'];
    if ($size <= 0 || $size > WHATSAPP_MEDIA_MAX_BYTES) throw new RuntimeException('O arquivo deve ter no máximo 16 MB.');
    $bytes = (string)file_get_contents((string)$file['tmp_name']);
    $mime = '';
    if (function_exists('finfo_open')) { $fi = finfo_open(FILEINFO_MIME_TYPE); $mime = (string)finfo_buffer($fi, $bytes); finfo_close($fi); }
    if ($mime === '' || $mime === 'application/octet-stream') $mime = (string)($file['type'] ?? 'application/octet-stream');
    if ($voice) {
      // Áudio gravado na tela: Ogg/Opus vira mensagem de voz; o do Safari (MP4/AAC) vai como áudio.
      if (in_array($mime, ['audio/ogg', 'application/ogg', 'audio/x-opus+ogg', 'audio/opus'], true) && substr($bytes, 0, 4) === 'OggS') $mime = 'audio/ogg';
      elseif (in_array($mime, ['video/mp4', 'audio/mp4', 'audio/x-m4a', 'audio/m4a'], true)) $mime = 'audio/mp4';
      else throw new RuntimeException('Formato de áudio não suportado por este navegador. Use o Google Chrome, o Edge ou o Firefox.');
    }
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $mime = whatsapp_att_fix_mime($mime, (string)$file['name']);
    $extra = $replyTo > 0 ? ['reply' => whatsapp_att_message_get($pdo, $companyId, (int)$chat['id'], $replyTo)] : [];
    $ok = whatsapp_chat_send_file($pdo, $companyId, whatsapp_chat_ctx($pdo, $companyId, $chat), $bytes, $mime, (string)$file['name'], $voice ? '' : trim($caption), 'atendente', $userId, true, $voice, $extra);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => (string)$chat['status']]);
    return $ok;
  }
}

if (!function_exists('whatsapp_att_transfer')) {
  function whatsapp_att_transfer(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $departmentId, int $toUserId): void {
    if (($chat['status'] ?? '') === 'GRUPO') throw new RuntimeException('Grupos não são transferidos.');
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
    if (($chat['status'] ?? '') === 'GRUPO') throw new RuntimeException('Grupos não são finalizados.');
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
    $link = whatsapp_bot_contact_link($pdo, $companyId, $chat, $d);
    if ($link) whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], $link);
    $chat = whatsapp_chat_get($pdo, $companyId, (int)$chat['id']) ?? $chat;
    if (!($chat['status'] === 'ATENDIMENTO' && (int)$chat['user_id'] > 0 && (int)$chat['user_id'] !== $userId)) {
      $chat = whatsapp_att_accept($pdo, $companyId, $chat, $userId, false, false);
    }
    if (trim($text) !== '') whatsapp_att_send_text($pdo, $companyId, $chat, $userId, false, $text);
    return (int)$chat['id'];
  }
}

/* ------------------------------------------------------------------------- */
/* Responder, encaminhar, apagar e grupos                                     */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_att_message_get')) {
  /** Uma mensagem da conversa (null = não é desta conversa). */
  function whatsapp_att_message_get(PDO $pdo, int $companyId, int $chatId, int $messageId): ?array {
    if ($messageId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_messages WHERE company_id=? AND session_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $chatId, $messageId]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) return null;
    if (!empty($m['deleted_at'])) throw new RuntimeException('Esta mensagem foi apagada.');
    return $m;
  }
}

if (!function_exists('whatsapp_att_fix_mime')) {
  /** Arquivos compactados e XML: tipo certo pelo nome (o navegador às vezes manda genérico). */
  function whatsapp_att_fix_mime(string $mime, string $name): string {
    $base = strtolower(trim(explode(';', $mime)[0]));
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    $byExt = ['zip' => 'application/zip', 'rar' => 'application/vnd.rar', '7z' => 'application/x-7z-compressed', 'xml' => 'application/xml', 'gz' => 'application/gzip'];
    if (isset($byExt[$ext]) && ($base === '' || $base === 'application/octet-stream' || str_contains($base, 'zip') || str_contains($base, 'rar') || str_contains($base, '7z') || str_contains($base, 'xml') || $base === 'text/plain')) return $byExt[$ext];
    return $mime;
  }
}

if (!function_exists('whatsapp_att_delete')) {
  /**
   * Apaga uma mensagem. Enviada pela empresa: apaga para todos (some também no celular
   * do cliente; o WhatsApp só aceita até cerca de 2 dias). Recebida: some só no Nexa.
   * O registro continua no banco (histórico); a tela mostra "Mensagem apagada".
   */
  function whatsapp_att_delete(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $messageId): string {
    if (!whatsapp_chat_has_v3($pdo)) throw new RuntimeException('Aplique "WhatsApp - Atendimento v3" no Atualizador do Banco.');
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $m = whatsapp_att_message_get($pdo, $companyId, (int)$chat['id'], $messageId);
    if (!$m) throw new RuntimeException('Mensagem não encontrada.');
    if (($m['sender'] ?? '') === 'sistema') throw new RuntimeException('Avisos do sistema não podem ser apagados.');
    $forAll = 0;
    if ($m['direction'] === 'OUT' && trim((string)$m['message_id']) !== '' && (int)$m['send_ok'] === 1) {
      if (strtotime((string)$m['created_at']) < time() - 48 * 3600) throw new RuntimeException('O WhatsApp só permite apagar para todos as mensagens enviadas há menos de 2 dias.');
      $ctx = whatsapp_chat_ctx($pdo, $companyId, $chat);
      $payload = ['company_id' => $ctx['sid'], 'message_id' => (string)$m['message_id']];
      if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone']; else $payload['jid'] = $ctx['contact'];
      try {
        whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 30]), 'POST', '/delete', $payload);
      } catch (Throwable $e) {
        throw new RuntimeException('Não foi possível apagar no WhatsApp: ' . $e->getMessage());
      }
      $forAll = 1;
    }
    $pdo->prepare('UPDATE whatsapp_bot_messages SET deleted_at=?, deleted_by=?, deleted_for_all=? WHERE company_id=? AND id=? AND deleted_at IS NULL')
      ->execute([date('Y-m-d H:i:s'), $userId ?: null, $forAll, $companyId, $messageId]);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['deleted' => 1]);
    return $forAll ? 'Mensagem apagada para todos.' : 'Mensagem apagada no Nexa (no celular do cliente ela continua).';
  }
}

if (!function_exists('whatsapp_att_edit_minutes')) {
  /** O WhatsApp só aceita editar a mensagem até 15 minutos depois do envio. */
  function whatsapp_att_edit_minutes(): int { return 15; }
}

if (!function_exists('whatsapp_att_edit')) {
  /**
   * Edita uma mensagem de texto enviada pelo Atendimento (por quem a enviou; o supervisor
   * edita as de qualquer atendente). Muda também no celular do cliente (aparece "Editada").
   */
  function whatsapp_att_edit(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $messageId, string $text): string {
    if (!whatsapp_chat_has_v4($pdo)) throw new RuntimeException('Aplique "WhatsApp - Atendimento v4 (editar mensagens)" no Atualizador do Banco.');
    $text = trim($text);
    if ($text === '') throw new RuntimeException('Digite o texto da mensagem.');
    if (mb_strlen($text) > 4000) throw new RuntimeException('Mensagem muito longa (máximo 4.000 caracteres).');
    $chat = whatsapp_att_assert_owner($pdo, $companyId, $chat, $userId, $supervisor);
    $m = whatsapp_att_message_get($pdo, $companyId, (int)$chat['id'], $messageId);
    if (!$m) throw new RuntimeException('Mensagem não encontrada.');
    if ($m['direction'] !== 'OUT' || ($m['sender'] ?? '') !== 'atendente') throw new RuntimeException('Só dá para editar mensagens enviadas pelo Atendimento.');
    if ((string)($m['msg_type'] ?? 'text') !== 'text' || !empty($m['media_path'])) throw new RuntimeException('Só mensagens de texto podem ser editadas.');
    if ((int)($m['user_id'] ?? 0) !== $userId && !$supervisor) throw new RuntimeException('Cada atendente edita só as próprias mensagens.');
    if (trim((string)$m['message_id']) === '' || (int)$m['send_ok'] !== 1) throw new RuntimeException('Esta mensagem não chegou a ser enviada; envie de novo em vez de editar.');
    if (strtotime((string)$m['created_at']) < time() - whatsapp_att_edit_minutes() * 60) throw new RuntimeException('O WhatsApp só permite editar até ' . whatsapp_att_edit_minutes() . ' minutos depois do envio.');
    if ($text === (string)$m['body']) return 'Nada mudou na mensagem.';
    $cfg = whatsapp_bot_config($pdo, $companyId);
    $wire = $text;
    // Mesma assinatura do envio ("*Nome:*" na 1ª linha), com o nome de quem enviou.
    if ($cfg['sign_messages']) {
      $name = whatsapp_bot_user_name($pdo, $companyId, (int)($m['user_id'] ?? 0) ?: $userId);
      if ($name !== '') $wire = '*' . $name . ':*' . "\n" . $text;
    }
    $ctx = whatsapp_chat_ctx($pdo, $companyId, $chat);
    $payload = ['company_id' => $ctx['sid'], 'message_id' => (string)$m['message_id'], 'message' => $wire];
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone']; else $payload['jid'] = $ctx['contact'];
    try {
      whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 30]), 'POST', '/edit', $payload);
    } catch (Throwable $e) {
      $msg = $e->getMessage();
      if (preg_match('/\b404\b|not found|rota não encontrad|cannot post/iu', $msg)) throw new RuntimeException('O serviço de WhatsApp desta empresa ainda não permite editar mensagens (é preciso o motor novo do WhatsApp).');
      throw new RuntimeException('Não foi possível editar no WhatsApp: ' . $msg);
    }
    whatsapp_chat_apply_edit($pdo, $companyId, $m, $text);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['edited' => 1]);
    return 'Mensagem editada.';
  }
}

if (!function_exists('whatsapp_att_forward')) {
  /**
   * Encaminha uma mensagem (texto, arquivo ou contato) para outras conversas ou para um
   * número. A conversa de destino não muda de situação; o envio aparece como "Encaminhada".
   * Retorna quantos envios saíram.
   */
  function whatsapp_att_forward(PDO $pdo, int $companyId, int $userId, bool $supervisor, int $fromChatId, int $messageId, array $chatIds, string $phone = ''): int {
    $src = whatsapp_att_can_see($pdo, $companyId, $userId, $supervisor, $fromChatId);
    if (!$src) throw new RuntimeException('Conversa não encontrada.');
    $m = whatsapp_att_message_get($pdo, $companyId, $fromChatId, $messageId);
    if (!$m || ($m['sender'] ?? '') === 'sistema') throw new RuntimeException('Mensagem não encontrada.');
    $targets = [];
    foreach (array_slice(array_values(array_unique(array_filter(array_map('intval', $chatIds), static fn($v) => $v > 0))), 0, 5) as $cid) {
      $t = whatsapp_att_can_see($pdo, $companyId, $userId, $supervisor, $cid);
      if (!$t) throw new RuntimeException('Conversa de destino não encontrada.');
      if (($t['status'] ?? '') === 'ATENDIMENTO' && (int)$t['user_id'] > 0 && (int)$t['user_id'] !== $userId && !$supervisor) {
        throw new RuntimeException('Uma das conversas escolhidas está com outro atendente.');
      }
      $targets[] = $t;
    }
    if (trim($phone) !== '') {
      $d = whatsapp_bot_digits($phone);
      if (strlen($d) === 10 || strlen($d) === 11) $d = '55' . $d;
      if (strlen($d) < 12 || strlen($d) > 13) throw new RuntimeException('Informe o celular com DDD.');
      $t = whatsapp_chat_find_or_create($pdo, $companyId, $d . '@s.whatsapp.net', $d);
      $link = whatsapp_bot_contact_link($pdo, $companyId, $t, $d);
      if ($link) whatsapp_chat_update($pdo, $companyId, (int)$t['id'], $link);
      $t = whatsapp_chat_get($pdo, $companyId, (int)$t['id']) ?? $t;
      if (($t['state'] ?? '') === 'NOVA') {
        // Conversa nova só com o encaminhamento: fica com quem encaminhou.
        $t = whatsapp_att_accept($pdo, $companyId, $t, $userId, false, false);
      }
      $targets[] = $t;
    }
    if (!$targets) throw new RuntimeException('Escolha para quem encaminhar.');
    $bytes = null;
    if (!empty($m['media_path'])) {
      $path = whatsapp_media_path($companyId, (string)$m['media_path']);
      if (!$path) throw new RuntimeException('O arquivo desta mensagem não está mais disponível.');
      $bytes = (string)file_get_contents($path);
    } elseif (in_array((string)$m['msg_type'], ['image', 'audio', 'video', 'document', 'sticker'], true)) {
      throw new RuntimeException('O arquivo desta mensagem não foi guardado no Nexa (veja no celular).');
    }
    $sent = 0;
    foreach ($targets as $t) {
      $ctx = whatsapp_chat_ctx($pdo, $companyId, $t);
      if ($bytes !== null) {
        $ok = whatsapp_chat_send_file($pdo, $companyId, $ctx, $bytes, (string)$m['media_mime'], (string)($m['media_name'] ?: 'arquivo'), (string)$m['msg_type'] === 'audio' ? '' : (string)($m['body'] ?? ''), 'atendente', $userId, true, false, ['forwarded' => true]);
      } elseif ((string)$m['msg_type'] === 'contact') {
        $c = whatsapp_vcard_parse((string)$m['body'])[0] ?? null;
        if (!$c || $c['phone'] === '') throw new RuntimeException('Este contato não tem telefone para encaminhar.');
        $ok = whatsapp_chat_send_contact($pdo, $companyId, $ctx, (string)$c['name'], (string)$c['phone'], '', $userId);
      } else {
        $text = trim(preg_replace('/\n\[não enviado:[^\]]*\]$/u', '', (string)$m['body']) ?? '');
        if ($text === '') throw new RuntimeException('Mensagem vazia.');
        $ok = whatsapp_chat_send_text($pdo, $companyId, $ctx, $text, 'atendente', $userId, '', ['forwarded' => true]);
      }
      if ($ok) $sent++;
      whatsapp_chat_notify($companyId, (int)$t['id'], ['status' => (string)$t['status']]);
    }
    return $sent;
  }
}

if (!function_exists('whatsapp_att_groups_set')) {
  /** Liga/desliga os grupos do WhatsApp na tela de Atendimento (avisa o motor). */
  function whatsapp_att_groups_set(PDO $pdo, int $companyId, bool $on): array {
    if (!whatsapp_chat_has_v3($pdo)) throw new RuntimeException('Aplique "WhatsApp - Atendimento v3" no Atualizador do Banco.');
    $cfg = whatsapp_bot_config($pdo, $companyId);
    $cfg['groups_enabled'] = $on;
    config_store_set($pdo, $companyId, 'whatsapp_bot', $cfg);
    $r = whatsapp_bot_sync_engine($pdo, $companyId, whatsapp_bot_module_enabled($pdo, $companyId));
    if (empty($r['ok'])) throw new RuntimeException('A opção foi salva, mas o motor do WhatsApp não confirmou: ' . (string)($r['message'] ?? ''));
    return $r;
  }
}

if (!function_exists('whatsapp_att_groups_sync')) {
  /** Traz para a aba Grupos todos os grupos de que o número participa. Retorna quantos. */
  function whatsapp_att_groups_sync(PDO $pdo, int $companyId): int {
    if (empty(whatsapp_bot_config($pdo, $companyId)['groups_enabled'])) throw new RuntimeException('Ligue os grupos primeiro.');
    $wa = whatsapp_load_config($pdo, $companyId, false, true);
    $res = whatsapp_api_request(array_merge($wa, ['timeout_seconds' => 40]), 'GET', '/groups?' . http_build_query(['company_id' => whatsapp_service_company_id($wa, $companyId)]), []);
    $n = 0;
    foreach ((array)($res['items'] ?? []) as $g) {
      if (!is_array($g) || !preg_match('/@g\.us$/', (string)($g['jid'] ?? ''))) continue;
      whatsapp_bot_group_chat($pdo, $companyId, (string)$g['jid'], (string)($g['name'] ?? ''));
      $n++;
    }
    return $n;
  }
}
