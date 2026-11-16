<?php
declare(strict_types=1);

/**
 * Painel do Atendimento do WhatsApp (WhatsApp > Painel do Atendimento).
 *
 * whatsapp_bot_sessions guarda só a situação ATUAL de cada contato. Este módulo grava:
 *  - whatsapp_attendances: um registro por atendimento, aberto quando a conversa vai para
 *    a fila (PENDENTE) ou é aceita (ATENDIMENTO) e fechado ao FINALIZAR ou quando volta ao
 *    robô. Guarda setor, atendente, espera (fila -> aceite), duração e transferências.
 *  - whatsapp_chat_events: assuntos escolhidos no menu do robô e fluxos iniciados.
 * A gravação nunca interrompe a conversa: qualquer erro aqui é ignorado.
 * Tabelas criadas pela migração db/20261116_whatsapp_painel_v1.sql.
 */

if (!function_exists('whatsapp_painel_ready')) {
  function whatsapp_painel_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('whatsapp_attendances','whatsapp_chat_events')");
      return $ready = ((int)$st->fetchColumn() === 2);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Gravação (chamada pelo chatbot / tela de atendimento)                      */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_painel_topic')) {
  /** Assunto escolhido: $kind = menu (opção do robô) | flow (fluxo iniciado). */
  function whatsapp_painel_topic(PDO $pdo, int $companyId, int $sessionId, string $kind, string $label, ?int $refId = null): void {
    if ($sessionId <= 0 || trim($label) === '' || !whatsapp_painel_ready($pdo)) return;
    try {
      $pdo->prepare('INSERT INTO whatsapp_chat_events (company_id, session_id, event, label, ref_id, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$companyId, $sessionId, $kind === 'flow' ? 'flow' : 'menu', mb_substr(trim($label), 0, 160), $refId ?: null, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('whatsapp_painel_node_path')) {
  /** "Financeiro > 2ª via de boleto" (até 3 níveis do menu). */
  function whatsapp_painel_node_path(PDO $pdo, int $companyId, array $item): string {
    $parts = [trim((string)($item['label'] ?? ''))];
    $parentId = (int)($item['parent_id'] ?? 0);
    for ($i = 0; $i < 2 && $parentId > 0 && function_exists('whatsapp_bot_node'); $i++) {
      $parent = whatsapp_bot_node($pdo, $companyId, $parentId);
      if (!$parent) break;
      array_unshift($parts, trim((string)$parent['label']));
      $parentId = (int)($parent['parent_id'] ?? 0);
    }
    return implode(' > ', array_filter($parts, static fn($p) => $p !== ''));
  }
}

if (!function_exists('whatsapp_painel_session_state')) {
  function whatsapp_painel_session_state(PDO $pdo, int $companyId, int $chatId): ?array {
    if (!whatsapp_painel_ready($pdo)) return null;
    try {
      $st = $pdo->prepare('SELECT id, contact, status, user_id, department_id FROM whatsapp_bot_sessions WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId, $chatId]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('whatsapp_painel_transition')) {
  /**
   * Mudança de situação da conversa ($old = linha antes; $fields = o que foi gravado).
   * Abre, atualiza ou fecha o atendimento em aberto deste contato.
   */
  function whatsapp_painel_transition(PDO $pdo, int $companyId, int $chatId, array $old, array $fields, ?string $now = null): void {
    if (!whatsapp_painel_ready($pdo)) return;
    try {
      $now = $now ?? date('Y-m-d H:i:s');
      $oldStatus = (string)($old['status'] ?? '');
      $status = array_key_exists('status', $fields) ? (string)$fields['status'] : $oldStatus;
      $user = array_key_exists('user_id', $fields) ? (int)($fields['user_id'] ?? 0) : (int)($old['user_id'] ?? 0);
      $dept = array_key_exists('department_id', $fields) ? (int)($fields['department_id'] ?? 0) : (int)($old['department_id'] ?? 0);
      $st = $pdo->prepare('SELECT * FROM whatsapp_attendances WHERE company_id=? AND session_id=? AND closed_at IS NULL ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId, $chatId]);
      $open = $st->fetch(PDO::FETCH_ASSOC) ?: null;

      if ($status === 'PENDENTE' || $status === 'ATENDIMENTO') {
        if (!$open) {
          $origin = $status === 'PENDENTE' ? 'robo' : ($user > 0 ? 'atendente' : 'celular');
          // Assunto: última opção/fluxo escolhido por este contato nas 2 horas anteriores.
          $tp = $pdo->prepare('SELECT label FROM whatsapp_chat_events WHERE company_id=? AND session_id=? AND created_at >= ? ORDER BY id DESC LIMIT 1');
          $tp->execute([$companyId, $chatId, date('Y-m-d H:i:s', strtotime($now) - 7200)]);
          $topic = (string)($tp->fetchColumn() ?: '');
          $pdo->prepare('INSERT INTO whatsapp_attendances (company_id, session_id, contact, department_id, user_id, first_user_id, topic, origin, opened_at, queued_at, accepted_at, transfers, msgs_in, msgs_out) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,0,0)')
            ->execute([
              $companyId, $chatId, (string)($old['contact'] ?? ''), $dept ?: null,
              $status === 'ATENDIMENTO' && $user > 0 ? $user : null, $status === 'ATENDIMENTO' && $user > 0 ? $user : null,
              $topic !== '' ? $topic : null, $origin, $now,
              $status === 'PENDENTE' ? $now : null, $status === 'ATENDIMENTO' ? $now : null,
            ]);
          return;
        }
        $sets = []; $params = [];
        if ($dept !== (int)($open['department_id'] ?? 0)) { $sets[] = 'department_id=?'; $params[] = $dept ?: null; }
        if ($status === 'PENDENTE') {
          if ($oldStatus === 'ATENDIMENTO') { $sets[] = 'transfers=transfers+1'; $sets[] = 'user_id=NULL'; }
          if (empty($open['queued_at'])) { $sets[] = 'queued_at=?'; $params[] = $now; }
        } else {
          if (empty($open['accepted_at'])) {
            $sets[] = 'accepted_at=?'; $params[] = $now;
            if ($user > 0) { $sets[] = 'first_user_id=?'; $params[] = $user; }
          } elseif ($user > 0 && (int)($open['user_id'] ?? 0) > 0 && (int)$open['user_id'] !== $user) {
            $sets[] = 'transfers=transfers+1';
          }
          if ($user > 0 && $user !== (int)($open['user_id'] ?? 0)) { $sets[] = 'user_id=?'; $params[] = $user; }
        }
        if ($sets) {
          $params[] = (int)$open['id'];
          $pdo->prepare('UPDATE whatsapp_attendances SET ' . implode(', ', $sets) . ' WHERE id=?')->execute($params);
        }
        return;
      }

      if (($status === 'FINALIZADO' || $status === 'BOT') && $open) {
        // Finalizado na tela: agora. Voltou ao robô sozinho (ex.: respondido pelo celular e
        // parado): termina na última mensagem enviada pela empresa.
        $closedAt = $status === 'FINALIZADO' ? $now : (string)($open['last_out_at'] ?: $now);
        $reason = empty($open['accepted_at']) ? 'sem_atendimento' : ($status === 'FINALIZADO' ? 'finalizado' : 'automatico');
        $pdo->prepare('UPDATE whatsapp_attendances SET closed_at=?, close_reason=? WHERE id=?')->execute([$closedAt, $reason, (int)$open['id']]);
      }
    } catch (Throwable $e) {}
  }
}

if (!function_exists('whatsapp_painel_message')) {
  /** Conta as mensagens do atendimento em aberto (cliente = recebida; atendente/celular = enviada). */
  function whatsapp_painel_message(PDO $pdo, int $companyId, int $sessionId, string $sender, ?string $now = null): void {
    if ($sessionId <= 0 || !in_array($sender, ['cliente', 'atendente', 'celular'], true) || !whatsapp_painel_ready($pdo)) return;
    try {
      $now = $now ?? date('Y-m-d H:i:s');
      if ($sender === 'cliente') {
        $pdo->prepare('UPDATE whatsapp_attendances SET msgs_in=msgs_in+1 WHERE company_id=? AND session_id=? AND closed_at IS NULL')->execute([$companyId, $sessionId]);
      } else {
        $pdo->prepare('UPDATE whatsapp_attendances SET msgs_out=msgs_out+1, last_out_at=? WHERE company_id=? AND session_id=? AND closed_at IS NULL')->execute([$now, $companyId, $sessionId]);
      }
    } catch (Throwable $e) {}
  }
}

/* ------------------------------------------------------------------------- */
/* Histórico anterior ao painel (montado uma vez pelas mensagens de sistema)   */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_painel_backfill')) {
  /**
   * Recria os atendimentos dos últimos 180 dias a partir das mensagens de sistema
   * ("aceitou o atendimento", "finalizou", "transferida pelo robô para...").
   * Roda uma vez por empresa, até o primeiro atendimento gravado pelo painel.
   */
  function whatsapp_painel_backfill(PDO $pdo, int $companyId): int {
    if (!whatsapp_painel_ready($pdo) || !function_exists('config_store_get')) return 0;
    $cfg = config_store_get($pdo, $companyId, 'whatsapp_painel') ?? [];
    if (!empty($cfg['backfill_done'])) return 0;
    $lock = 'nexa_wa_painel_backfill_' . $companyId;
    try { $got = (int)$pdo->query('SELECT GET_LOCK(' . $pdo->quote($lock) . ', 0)')->fetchColumn(); } catch (Throwable $e) { $got = 1; }
    if ($got !== 1) return 0;
    $created = 0;
    try {
      $st = $pdo->prepare('SELECT MIN(opened_at) FROM whatsapp_attendances WHERE company_id=?');
      $st->execute([$companyId]);
      $until = (string)($st->fetchColumn() ?: date('Y-m-d H:i:s'));
      $from = date('Y-m-d 00:00:00', strtotime('-180 days'));

      $depts = [];
      $st = $pdo->prepare('SELECT id, name FROM whatsapp_departments WHERE company_id=?');
      $st->execute([$companyId]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $d) $depts[mb_strtolower(trim((string)$d['name']))] = (int)$d['id'];
      $users = [];
      foreach (whatsapp_painel_user_names($pdo, $companyId) as $uid => $name) $users[mb_strtolower(trim($name))] = (int)$uid;

      $st = $pdo->prepare("SELECT m.session_id, m.sender, m.user_id, m.body, m.created_at, s.contact FROM whatsapp_bot_messages m
        JOIN whatsapp_bot_sessions s ON s.company_id=m.company_id AND s.id=m.session_id
        WHERE m.company_id=? AND m.created_at >= ? AND m.created_at < ? AND m.session_id IS NOT NULL
        ORDER BY m.session_id, m.id");
      $st->execute([$companyId, $from, $until]);
      $ins = $pdo->prepare('INSERT INTO whatsapp_attendances (company_id, session_id, contact, department_id, user_id, first_user_id, topic, origin, opened_at, queued_at, accepted_at, last_out_at, closed_at, close_reason, transfers, msgs_in, msgs_out) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $evt = $pdo->prepare('INSERT INTO whatsapp_chat_events (company_id, session_id, event, label, ref_id, created_at) VALUES (?,?,?,?,NULL,?)');
      $cur = null; $sid = 0; $lastTopic = null;
      $live = $pdo->prepare('SELECT 1 FROM whatsapp_attendances WHERE company_id=? AND session_id=? AND opened_at >= ? LIMIT 1');
      $flush = static function (?array &$c, ?string $closedAt, string $reason) use ($ins, $live, $companyId, $until, &$created): void {
        if (!$c) return;
        // Ainda aberto no corte: se o painel já registrou este contato depois, o atendimento
        // continuou lá (não duplica); senão fica aberto e o painel o completa ao aceitar/finalizar.
        if ($closedAt === null) {
          $live->execute([$companyId, $c['session_id'], $until]);
          if ($live->fetchColumn()) { $c = null; return; }
        }
        if ($closedAt !== null && empty($c['accepted_at'])) $reason = 'sem_atendimento';
        $ins->execute([$companyId, $c['session_id'], $c['contact'], $c['department_id'] ?: null, $c['user_id'] ?: null, $c['first_user_id'] ?: null, $c['topic'], $c['origin'],
          $c['opened_at'], $c['queued_at'], $c['accepted_at'], $c['last_out_at'], $closedAt, $closedAt !== null ? $reason : null, $c['transfers'], $c['msgs_in'], $c['msgs_out']]);
        $created++;
        $c = null;
      };
      $open = static function (array $m, string $origin, ?array $lastTopic): array {
        return ['session_id' => (int)$m['session_id'], 'contact' => (string)$m['contact'], 'department_id' => 0, 'user_id' => 0, 'first_user_id' => 0,
          'topic' => $lastTopic && strtotime((string)$m['created_at']) - strtotime($lastTopic[1]) <= 7200 ? $lastTopic[0] : null,
          'origin' => $origin, 'opened_at' => (string)$m['created_at'], 'queued_at' => null, 'accepted_at' => null, 'last_out_at' => null,
          'transfers' => 0, 'msgs_in' => 0, 'msgs_out' => 0];
      };
      while ($m = $st->fetch(PDO::FETCH_ASSOC)) {
        if ((int)$m['session_id'] !== $sid) {
          // Conversa ainda aberta no fim do período copiado: fica aberta (sem data de fim).
          $flush($cur, null, '');
          $sid = (int)$m['session_id']; $lastTopic = null;
        }
        $at = (string)$m['created_at'];
        $sender = (string)$m['sender'];
        $body = trim((string)$m['body']);
        $uid = (int)($m['user_id'] ?? 0);
        if ($sender === 'sistema') {
          if (preg_match('/^Fluxo iniciado: (.+)\.$/u', $body, $mm)) {
            $evt->execute([$companyId, $sid, 'flow', mb_substr($mm[1], 0, 160), $at]);
            $lastTopic = [mb_substr($mm[1], 0, 160), $at];
          } elseif (preg_match('/^Conversa transferida pelo robô para (.+?)\.(\n|$)/u', $body, $mm) || preg_match('/^Mensagem recebida fora do horário/u', $body)) {
            if (!$cur) $cur = $open($m, 'robo', $lastTopic);
            $cur['queued_at'] = $cur['queued_at'] ?? $at;
            if (!empty($mm[1])) $cur['department_id'] = $depts[mb_strtolower(trim($mm[1]))] ?? 0;
            $mm = [];
          } elseif (preg_match('/ (aceitou o atendimento|assumiu o atendimento)/u', $body)) {
            if (!$cur) $cur = $open($m, 'atendente', $lastTopic);
            if (empty($cur['accepted_at'])) { $cur['accepted_at'] = $at; $cur['first_user_id'] = $uid; }
            elseif ($uid > 0 && $cur['user_id'] > 0 && $cur['user_id'] !== $uid) $cur['transfers']++;
            if ($uid > 0) $cur['user_id'] = $uid;
          } elseif (preg_match('/ transferiu a conversa para (.+)\.$/u', $body, $mm)) {
            if ($cur) {
              $cur['transfers']++;
              $target = mb_strtolower(trim($mm[1]));
              if (isset($users[$target])) $cur['user_id'] = $users[$target];
              else { $cur['department_id'] = $depts[$target] ?? 0; $cur['user_id'] = 0; }
            }
          } elseif (preg_match('/ finalizou o atendimento\.$/u', $body)) {
            $flush($cur, $at, 'finalizado');
          }
          continue;
        }
        if ($sender === 'cliente') { if ($cur) $cur['msgs_in']++; continue; }
        if ($sender === 'atendente' || $sender === 'celular') {
          if (!$cur && $sender === 'celular') { $cur = $open($m, 'celular', $lastTopic); $cur['accepted_at'] = $at; }
          if ($cur) { $cur['msgs_out']++; $cur['last_out_at'] = $at; }
          continue;
        }
        // O robô voltou a falar: o atendimento anterior terminou na última mensagem da empresa.
        if ($sender === 'robo' && $cur && (!empty($cur['accepted_at']) || !empty($cur['last_out_at']))) {
          $flush($cur, (string)($cur['last_out_at'] ?: $at), 'automatico');
        }
      }
      $flush($cur, null, '');
      config_store_set($pdo, $companyId, 'whatsapp_painel', array_merge($cfg, ['backfill_done' => 1, 'backfill_at' => date('Y-m-d H:i:s'), 'backfill_rows' => $created]));
    } catch (Throwable $e) {
      // Sem histórico antigo; o painel segue com o que for gravado daqui em diante.
    } finally {
      try { $pdo->query('SELECT RELEASE_LOCK(' . $pdo->quote($lock) . ')'); } catch (Throwable $e) {}
    }
    return $created;
  }
}

/* ------------------------------------------------------------------------- */
/* Consultas do painel                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_painel_user_names')) {
  /** [user_id => nome] dos usuários da empresa. */
  function whatsapp_painel_user_names(PDO $pdo, int $companyId): array {
    $out = [];
    try {
      $where = function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)';
      $params = function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId];
      $st = $pdo->prepare('SELECT id, name FROM users WHERE ' . $where);
      $st->execute($params);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $out[(int)$u['id']] = trim((string)$u['name']) ?: ('Usuário #' . (int)$u['id']);
    } catch (Throwable $e) {}
    return $out;
  }
}

if (!function_exists('whatsapp_painel_data')) {
  /**
   * Números do painel no período [$from, $to] (datas Y-m-d), com filtro opcional de setor/atendente.
   * Durações em segundos. Só usa funções que existem no MySQL e no SQLite dos testes.
   */
  function whatsapp_painel_data(PDO $pdo, int $companyId, string $from, string $to, int $departmentId = 0, int $userId = 0): array {
    $start = $from . ' 00:00:00';
    $end = date('Y-m-d 00:00:00', strtotime($to . ' +1 day'));
    $w = 'a.company_id=? AND a.opened_at >= ? AND a.opened_at < ?';
    $p = [$companyId, $start, $end];
    if ($departmentId > 0) { $w .= ' AND a.department_id=?'; $p[] = $departmentId; }
    if ($userId > 0) { $w .= ' AND a.user_id=?'; $p[] = $userId; }
    $q = static function (string $sql, array $params) use ($pdo): array {
      $st = $pdo->prepare($sql);
      $st->execute($params);
      return $st->fetchAll(PDO::FETCH_ASSOC);
    };
    $wait = '(UNIX_TIMESTAMP(a.accepted_at) - UNIX_TIMESTAMP(COALESCE(a.queued_at, a.opened_at)))';
    $dur = '(UNIX_TIMESTAMP(a.closed_at) - UNIX_TIMESTAMP(a.accepted_at))';

    $k = $q("SELECT COUNT(*) total, COUNT(DISTINCT a.contact) contacts,
        SUM(CASE WHEN a.accepted_at IS NOT NULL THEN 1 ELSE 0 END) accepted,
        SUM(CASE WHEN a.accepted_at IS NULL AND a.closed_at IS NOT NULL THEN 1 ELSE 0 END) unattended,
        SUM(CASE WHEN a.closed_at IS NULL THEN 1 ELSE 0 END) open_now,
        SUM(a.transfers) transfers, SUM(a.msgs_in) msgs_in, SUM(a.msgs_out) msgs_out,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.origin <> 'celular' AND a.origin <> 'atendente' THEN $wait END) avg_wait,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.closed_at IS NOT NULL THEN $dur END) avg_duration
      FROM whatsapp_attendances a WHERE $w", $p)[0] ?? [];

    $byDay = [];
    foreach ($q("SELECT DATE(a.opened_at) d, COUNT(*) total, SUM(CASE WHEN a.accepted_at IS NOT NULL THEN 1 ELSE 0 END) accepted,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.origin <> 'celular' AND a.origin <> 'atendente' THEN $wait END) avg_wait
      FROM whatsapp_attendances a WHERE $w GROUP BY DATE(a.opened_at)", $p) as $r) $byDay[(string)$r['d']] = $r;

    $byHour = array_fill(0, 24, 0);
    foreach ($q("SELECT HOUR(a.opened_at) h, COUNT(*) total FROM whatsapp_attendances a WHERE $w GROUP BY HOUR(a.opened_at)", $p) as $r) $byHour[(int)$r['h']] = (int)$r['total'];
    $byWeekday = array_fill(1, 7, 0); // 1 = domingo ... 7 = sábado (DAYOFWEEK do MySQL)
    foreach ($q("SELECT DAYOFWEEK(a.opened_at) wd, COUNT(*) total FROM whatsapp_attendances a WHERE $w GROUP BY DAYOFWEEK(a.opened_at)", $p) as $r) $byWeekday[(int)$r['wd']] = (int)$r['total'];

    $byUser = $q("SELECT COALESCE(a.user_id, 0) uid, COUNT(*) total, COUNT(DISTINCT a.contact) contacts,
        SUM(CASE WHEN a.closed_at IS NOT NULL THEN 1 ELSE 0 END) closed, SUM(a.msgs_out) msgs_out, SUM(a.transfers) transfers,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.closed_at IS NOT NULL THEN $dur END) avg_duration,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.origin <> 'celular' AND a.origin <> 'atendente' THEN $wait END) avg_wait
      FROM whatsapp_attendances a WHERE $w AND a.accepted_at IS NOT NULL GROUP BY COALESCE(a.user_id, 0) ORDER BY total DESC", $p);
    $userDay = [];
    foreach ($q("SELECT COALESCE(a.user_id, 0) uid, DATE(a.opened_at) d, COUNT(*) total FROM whatsapp_attendances a
      WHERE $w AND a.accepted_at IS NOT NULL GROUP BY COALESCE(a.user_id, 0), DATE(a.opened_at)", $p) as $r) $userDay[(int)$r['uid']][(string)$r['d']] = (int)$r['total'];

    $byDept = $q("SELECT COALESCE(a.department_id, 0) dept, COUNT(*) total,
        AVG(CASE WHEN a.accepted_at IS NOT NULL AND a.origin <> 'celular' AND a.origin <> 'atendente' THEN $wait END) avg_wait
      FROM whatsapp_attendances a WHERE $w GROUP BY COALESCE(a.department_id, 0) ORDER BY total DESC", $p);

    // Assuntos: opções do robô e fluxos escolhidos no período (filtro de setor/atendente não se aplica:
    // a maioria é resolvida pelo robô, sem atendente).
    $topics = $q("SELECT e.label, COUNT(*) total, COUNT(DISTINCT e.session_id) contacts FROM whatsapp_chat_events e
      WHERE e.company_id=? AND e.created_at >= ? AND e.created_at < ? GROUP BY e.label ORDER BY total DESC, e.label LIMIT 12", [$companyId, $start, $end]);
    $topicsTotal = (int)($q("SELECT COUNT(*) n FROM whatsapp_chat_events e WHERE e.company_id=? AND e.created_at >= ? AND e.created_at < ?", [$companyId, $start, $end])[0]['n'] ?? 0);

    // Conversas (todas, com ou sem atendente): mensagens recebidas de clientes por dia.
    $conv = [];
    foreach ($q("SELECT DATE(m.created_at) d, COUNT(DISTINCT m.session_id) chats, COUNT(*) msgs FROM whatsapp_bot_messages m
      WHERE m.company_id=? AND m.created_at >= ? AND m.created_at < ? AND m.direction='IN' GROUP BY DATE(m.created_at)", [$companyId, $start, $end]) as $r) $conv[(string)$r['d']] = $r;
    $convTotal = $q("SELECT COUNT(DISTINCT m.session_id) chats, COUNT(*) msgs FROM whatsapp_bot_messages m
      WHERE m.company_id=? AND m.created_at >= ? AND m.created_at < ? AND m.direction='IN'", [$companyId, $start, $end])[0] ?? [];

    $now = ['PENDENTE' => 0, 'ATENDIMENTO' => 0];
    foreach ($q("SELECT status, COUNT(*) n FROM whatsapp_bot_sessions WHERE company_id=? AND status IN ('PENDENTE','ATENDIMENTO') GROUP BY status", [$companyId]) as $r) $now[(string)$r['status']] = (int)$r['n'];

    $days = [];
    for ($t = strtotime($from); $t <= strtotime($to); $t += 86400) {
      $d = date('Y-m-d', $t);
      $days[$d] = [
        'total' => (int)($byDay[$d]['total'] ?? 0), 'accepted' => (int)($byDay[$d]['accepted'] ?? 0),
        'avg_wait' => isset($byDay[$d]['avg_wait']) ? (float)$byDay[$d]['avg_wait'] : null,
        'chats' => (int)($conv[$d]['chats'] ?? 0), 'msgs_in' => (int)($conv[$d]['msgs'] ?? 0),
      ];
      if (count($days) > 400) break;
    }
    $dayCount = max(1, count($days));
    return [
      'kpi' => [
        'total' => (int)($k['total'] ?? 0), 'contacts' => (int)($k['contacts'] ?? 0), 'accepted' => (int)($k['accepted'] ?? 0),
        'unattended' => (int)($k['unattended'] ?? 0), 'open' => (int)($k['open_now'] ?? 0), 'transfers' => (int)($k['transfers'] ?? 0),
        'msgs_in' => (int)($k['msgs_in'] ?? 0), 'msgs_out' => (int)($k['msgs_out'] ?? 0),
        'avg_wait' => isset($k['avg_wait']) ? (float)$k['avg_wait'] : null, 'avg_duration' => isset($k['avg_duration']) ? (float)$k['avg_duration'] : null,
        'per_day' => round((int)($k['total'] ?? 0) / $dayCount, 1),
        'chats' => (int)($convTotal['chats'] ?? 0), 'chat_msgs' => (int)($convTotal['msgs'] ?? 0),
        'queue_now' => $now['PENDENTE'], 'attending_now' => $now['ATENDIMENTO'],
      ],
      'days' => $days, 'hours' => $byHour, 'weekdays' => $byWeekday,
      'users' => $byUser, 'user_days' => $userDay, 'departments' => $byDept,
      'topics' => $topics, 'topics_total' => $topicsTotal,
    ];
  }
}

if (!function_exists('whatsapp_painel_duration')) {
  /** 75 -> "1min 15s"; 3720 -> "1h 02min". */
  function whatsapp_painel_duration(?float $seconds): string {
    if ($seconds === null) return '—';
    $s = max(0, (int)round($seconds));
    if ($s < 60) return $s . 's';
    if ($s < 3600) return intdiv($s, 60) . 'min' . ($s % 60 ? ' ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's' : '');
    if ($s < 86400) return intdiv($s, 3600) . 'h ' . str_pad((string)intdiv($s % 3600, 60), 2, '0', STR_PAD_LEFT) . 'min';
    return intdiv($s, 86400) . 'd ' . intdiv($s % 86400, 3600) . 'h';
  }
}
