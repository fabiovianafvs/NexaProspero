<?php
declare(strict_types=1);

/**
 * Mensagens programadas do Atendimento do WhatsApp (botão 🕒 na conversa).
 *
 * O atendente escreve o texto e escolhe o dia e a hora; o agendador
 * (workers/whatsapp_campanhas_worker.php, timer nexa-whatsapp-campanhas, a cada minuto)
 * envia sozinho na hora marcada, em nome de quem programou, e a mensagem aparece na
 * conversa como as outras. Não muda a situação da conversa (não aceita, não reabre).
 *
 * Tabela whatsapp_scheduled_messages (db/20261125_whatsapp_mensagens_programadas_v1.sql).
 * O horário é guardado como foi digitado (send_at, horário da empresa) e em UTC (due_utc),
 * que é o que o agendador compara — assim vale para empresas de qualquer fuso.
 */

require_once __DIR__ . '/whatsapp_chatbot.php';

if (!defined('WA_SCHED_MAX_PENDING')) define('WA_SCHED_MAX_PENDING', 20);   // por conversa
if (!defined('WA_SCHED_MAX_TRIES')) define('WA_SCHED_MAX_TRIES', 3);        // tentativas de envio
if (!defined('WA_SCHED_RETRY_MIN')) define('WA_SCHED_RETRY_MIN', 5);        // minutos entre tentativas
if (!defined('WA_SCHED_LATE_HOURS')) define('WA_SCHED_LATE_HOURS', 3);      // atrasada demais = não envia

if (!function_exists('whatsapp_sched_ready')) {
  /** Migração "WhatsApp - Mensagens programadas v1" aplicada? */
  function whatsapp_sched_ready(PDO $pdo): bool {
    static $memo = [];
    $k = spl_object_id($pdo);
    if (isset($memo[$k])) return $memo[$k];
    try { $pdo->query('SELECT 1 FROM whatsapp_scheduled_messages LIMIT 0'); return $memo[$k] = true; }
    catch (Throwable $e) { return $memo[$k] = false; }
  }
}

if (!function_exists('whatsapp_sched_status_label')) {
  function whatsapp_sched_status_label(string $s): string {
    return [
      'AGENDADA' => 'Programada', 'ENVIANDO' => 'Enviando', 'ENVIADA' => 'Enviada', 'FALHOU' => 'Não enviada',
      'CANCELADA' => 'Cancelada', 'SIMULADA' => 'Simulada (homologação)',
    ][$s] ?? $s;
  }
}

if (!function_exists('whatsapp_sched_can_write')) {
  /**
   * Pode programar nesta conversa? Só não pode quando ela está com outro atendente
   * (o supervisor pode). Na fila, no robô ou finalizada pode: a mensagem sai na hora
   * marcada sem mudar a situação da conversa (ex.: retorno ao cliente no dia seguinte).
   */
  function whatsapp_sched_can_write(array $chat, int $userId, bool $supervisor): bool {
    if ($supervisor) return true;
    $owner = (int)($chat['user_id'] ?? 0);
    return !(($chat['status'] ?? '') === 'ATENDIMENTO' && $owner > 0 && $owner !== $userId);
  }
}

if (!function_exists('whatsapp_sched_parse_when')) {
  /** Dia (AAAA-MM-DD) e hora (HH:MM) digitados, no fuso da empresa → [local, utc]. */
  function whatsapp_sched_parse_when(string $date, string $time): array {
    $date = trim($date); $time = substr(trim($time), 0, 5);
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time)) throw new RuntimeException('Informe o dia e a hora do envio.');
    $tz = new DateTimeZone(date_default_timezone_get());
    $local = DateTimeImmutable::createFromFormat('!Y-m-d H:i', $date . ' ' . $time, $tz);
    $errors = DateTimeImmutable::getLastErrors();
    if (!$local || ($errors && ($errors['warning_count'] > 0 || $errors['error_count'] > 0))) throw new RuntimeException('Dia ou hora inválidos.');
    $now = new DateTimeImmutable('now', $tz);
    if ($local <= $now->modify('+59 seconds')) throw new RuntimeException('Escolha um horário no futuro (pelo menos 1 minuto a partir de agora).');
    if ($local > $now->modify('+1 year')) throw new RuntimeException('Programe no máximo 1 ano à frente.');
    return [$local->format('Y-m-d H:i:s'), $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s')];
  }
}

if (!function_exists('whatsapp_sched_get')) {
  function whatsapp_sched_get(PDO $pdo, int $companyId, int $chatId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_scheduled_messages WHERE company_id=? AND session_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $chatId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return $r ?: null;
  }
}

if (!function_exists('whatsapp_sched_save')) {
  /** Programa (ou altera, com $id) uma mensagem na conversa. Retorna o id. */
  function whatsapp_sched_save(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $id, string $date, string $time, string $text): int {
    if (!whatsapp_sched_ready($pdo)) throw new RuntimeException('Aplique "WhatsApp - Mensagens programadas v1" no Atualizador do Banco.');
    if (!whatsapp_sched_can_write($chat, $userId, $supervisor)) throw new RuntimeException('Esta conversa está com outro atendente.');
    $text = trim(str_replace("\r\n", "\n", $text));
    if ($text === '') throw new RuntimeException('Digite a mensagem.');
    if (mb_strlen($text) > 4000) throw new RuntimeException('Mensagem muito longa (máximo 4.000 caracteres).');
    [$local, $utc] = whatsapp_sched_parse_when($date, $time);
    $chatId = (int)$chat['id'];
    $now = date('Y-m-d H:i:s');
    if ($id > 0) {
      $row = whatsapp_sched_get($pdo, $companyId, $chatId, $id);
      if (!$row) throw new RuntimeException('Mensagem programada não encontrada.');
      if ((string)$row['status'] !== 'AGENDADA') throw new RuntimeException('Esta mensagem já foi ' . mb_strtolower(whatsapp_sched_status_label((string)$row['status'])) . ' e não pode mais ser alterada.');
      if (!$supervisor && (int)$row['user_id'] !== $userId) throw new RuntimeException('Só quem programou (ou o supervisor) pode alterar esta mensagem.');
      $st = $pdo->prepare("UPDATE whatsapp_scheduled_messages SET body=?, send_at=?, due_utc=?, attempts=0, next_try_utc=NULL, error=NULL, updated_at=? WHERE company_id=? AND id=? AND status='AGENDADA'");
      $st->execute([$text, $local, $utc, $now, $companyId, $id]);
      if ($st->rowCount() < 1) throw new RuntimeException('Esta mensagem acabou de ser enviada e não pode mais ser alterada.');
    } else {
      $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_scheduled_messages WHERE company_id=? AND session_id=? AND status='AGENDADA'");
      $st->execute([$companyId, $chatId]);
      if ((int)$st->fetchColumn() >= WA_SCHED_MAX_PENDING) throw new RuntimeException('Esta conversa já tem ' . WA_SCHED_MAX_PENDING . ' mensagens programadas.');
      $pdo->prepare("INSERT INTO whatsapp_scheduled_messages (company_id, session_id, user_id, body, send_at, due_utc, status, created_at) VALUES (?,?,?,?,?,?,'AGENDADA',?)")
        ->execute([$companyId, $chatId, $userId ?: null, $text, $local, $utc, $now]);
      $id = (int)$pdo->lastInsertId();
    }
    whatsapp_chat_notify($companyId, $chatId, ['scheduled' => true]);
    return $id;
  }
}

if (!function_exists('whatsapp_sched_cancel')) {
  function whatsapp_sched_cancel(PDO $pdo, int $companyId, array $chat, int $userId, bool $supervisor, int $id): void {
    if (!whatsapp_sched_ready($pdo)) throw new RuntimeException('Aplique "WhatsApp - Mensagens programadas v1" no Atualizador do Banco.');
    $row = whatsapp_sched_get($pdo, $companyId, (int)$chat['id'], $id);
    if (!$row) throw new RuntimeException('Mensagem programada não encontrada.');
    if (!$supervisor && (int)$row['user_id'] !== $userId) throw new RuntimeException('Só quem programou (ou o supervisor) pode cancelar esta mensagem.');
    $st = $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='CANCELADA', cancelled_by=?, updated_at=? WHERE company_id=? AND id=? AND status='AGENDADA'");
    $st->execute([$userId ?: null, date('Y-m-d H:i:s'), $companyId, $id]);
    if ($st->rowCount() < 1) throw new RuntimeException('Esta mensagem já foi ' . mb_strtolower(whatsapp_sched_status_label((string)$row['status'])) . '.');
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['scheduled' => true]);
  }
}

if (!function_exists('whatsapp_sched_list')) {
  /** Programadas da conversa: as que vão sair (em ordem de horário) + as 10 últimas já resolvidas. */
  function whatsapp_sched_list(PDO $pdo, int $companyId, int $chatId, int $userId, bool $supervisor, bool $pendingOnly = false): array {
    if (!whatsapp_sched_ready($pdo)) return [];
    $cols = 'm.id, m.user_id, m.body, m.send_at, m.status, m.error, m.sent_at, m.created_at, u.name AS user_name';
    $join = 'LEFT JOIN users u ON u.company_id=m.company_id AND u.id=m.user_id';
    $st = $pdo->prepare("SELECT $cols FROM whatsapp_scheduled_messages m $join WHERE m.company_id=? AND m.session_id=? AND m.status IN ('AGENDADA','ENVIANDO') ORDER BY m.due_utc, m.id");
    $st->execute([$companyId, $chatId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$pendingOnly) {
      $st = $pdo->prepare("SELECT $cols FROM whatsapp_scheduled_messages m $join WHERE m.company_id=? AND m.session_id=? AND m.status NOT IN ('AGENDADA','ENVIANDO') ORDER BY m.id DESC LIMIT 10");
      $st->execute([$companyId, $chatId]);
      $rows = array_merge($rows, $st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    }
    return array_map(static function (array $r) use ($userId, $supervisor): array {
      $mine = (int)$r['user_id'] === $userId;
      return [
        'id' => (int)$r['id'],
        'at' => (string)$r['send_at'],
        'text' => (string)$r['body'],
        'user' => (string)($r['user_name'] ?? ''),
        'status' => (string)$r['status'],
        'label' => whatsapp_sched_status_label((string)$r['status']),
        'error' => (string)($r['error'] ?? ''),
        'sent_at' => (string)($r['sent_at'] ?? ''),
        'can_edit' => $r['status'] === 'AGENDADA' && ($mine || $supervisor),
      ];
    }, $rows);
  }
}

if (!function_exists('whatsapp_sched_marks')) {
  /** Ids das mensagens da conversa que saíram por programação (para a marca 🕒 no balão). */
  function whatsapp_sched_marks(PDO $pdo, int $companyId, int $chatId, array $messageIds): array {
    $messageIds = array_values(array_filter(array_map('intval', $messageIds)));
    if (!$messageIds || !whatsapp_sched_ready($pdo)) return [];
    $st = $pdo->prepare('SELECT message_row_id FROM whatsapp_scheduled_messages WHERE company_id=? AND session_id=? AND message_row_id IN (' . implode(',', array_fill(0, count($messageIds), '?')) . ')');
    $st->execute(array_merge([$companyId, $chatId], $messageIds));
    return array_fill_keys(array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []), true);
  }
}

if (!function_exists('whatsapp_sched_simulated')) {
  function whatsapp_sched_simulated(): bool {
    if (!function_exists('nexa_is_homologacao') && is_file(__DIR__ . '/ambiente.php')) require_once __DIR__ . '/ambiente.php';
    return function_exists('nexa_is_homologacao') && nexa_is_homologacao();
  }
}

if (!function_exists('whatsapp_sched_send_one')) {
  /**
   * Envia uma programada (já marcada ENVIANDO). Erro de envio: tenta de novo em alguns
   * minutos (até WA_SCHED_MAX_TRIES); na última, grava na conversa como "não enviado".
   */
  function whatsapp_sched_send_one(PDO $pdo, array $row): string {
    $companyId = (int)$row['company_id'];
    $id = (int)$row['id'];
    $now = date('Y-m-d H:i:s');
    $finish = $pdo->prepare('UPDATE whatsapp_scheduled_messages SET status=?, error=?, message_row_id=?, sent_at=?, attempts=?, next_try_utc=NULL, updated_at=? WHERE company_id=? AND id=?');
    $attempts = (int)$row['attempts'] + 1;
    $chat = whatsapp_chat_get($pdo, $companyId, (int)$row['session_id']);
    if (!$chat) { $finish->execute(['FALHOU', 'A conversa não existe mais.', null, null, $attempts, $now, $companyId, $id]); return 'sem conversa'; }
    if (whatsapp_sched_simulated()) { $finish->execute(['SIMULADA', 'Homologação: nada é enviado.', null, null, $attempts, $now, $companyId, $id]); return 'simulada'; }
    if (!whatsapp_bot_module_enabled($pdo, $companyId)) { $finish->execute(['FALHOU', 'O módulo do Chatbot do WhatsApp está desligado para a empresa.', null, null, $attempts, $now, $companyId, $id]); return 'módulo desligado'; }

    $userId = (int)($row['user_id'] ?? 0);
    $text = (string)$row['body'];
    $wire = $text;
    $cfg = whatsapp_bot_config($pdo, $companyId);
    if (!empty($cfg['sign_messages']) && $userId > 0) {
      $name = whatsapp_bot_user_name($pdo, $companyId, $userId);
      if ($name !== '') $wire = '*' . $name . ':*' . "\n" . $text;
    }
    $ctx = whatsapp_chat_ctx($pdo, $companyId, $chat);
    $payload = ['company_id' => $ctx['sid'], 'message' => $wire];
    // Como no envio do Atendimento: pelo número quando conhecido (o @lid derrubava a sessão).
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone'];
    elseif ($ctx['contact'] !== '') $payload['jid'] = $ctx['contact'];
    try {
      $res = whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 40]), 'POST', '/send', $payload);
    } catch (Throwable $e) {
      $error = mb_substr($e->getMessage(), 0, 240);
      if ($attempts < WA_SCHED_MAX_TRIES) {
        $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='AGENDADA', attempts=?, next_try_utc=?, error=?, updated_at=? WHERE company_id=? AND id=?")
          ->execute([$attempts, gmdate('Y-m-d H:i:s', time() + WA_SCHED_RETRY_MIN * 60), 'Tentativa ' . $attempts . ': ' . $error, $now, $companyId, $id]);
        return 'nova tentativa';
      }
      $msgId = whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'atendente', $text . "\n[não enviado: " . $error . ']', ['user_id' => $userId, 'send_ok' => false]);
      $finish->execute(['FALHOU', $error, $msgId ?: null, null, $attempts, $now, $companyId, $id]);
      whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => (string)$chat['status'], 'scheduled' => true]);
      return 'falhou';
    }
    $msgId = whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'atendente', $text, ['user_id' => $userId, 'message_id' => (string)($res['message_id'] ?? ''), 'send_ok' => true]);
    $finish->execute(['ENVIADA', null, $msgId ?: null, $now, $attempts, $now, $companyId, $id]);
    whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => (string)$chat['status'], 'scheduled' => true]);
    return 'enviada';
  }
}

if (!function_exists('whatsapp_sched_dispatch')) {
  /** Chamado pelo agendador a cada minuto: envia as programadas que chegaram na hora. */
  function whatsapp_sched_dispatch(PDO $pdo, int $limit = 50): array {
    if (!whatsapp_sched_ready($pdo)) return [];
    $out = [];
    $nowUtc = gmdate('Y-m-d H:i:s');
    $now = date('Y-m-d H:i:s');
    // Envio interrompido no meio (servidor reiniciou): não reenvia às cegas.
    $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='FALHOU', error='O envio foi interrompido. Confira na conversa se a mensagem chegou.', updated_at=? WHERE status='ENVIANDO' AND updated_at < ?")
      ->execute([$now, date('Y-m-d H:i:s', time() - 15 * 60)]);
    // Agendador parado por muito tempo: uma mensagem "Bom dia" às 15h não faz sentido.
    $late = gmdate('Y-m-d H:i:s', time() - WA_SCHED_LATE_HOURS * 3600);
    $st = $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='FALHOU', error=?, updated_at=? WHERE status='AGENDADA' AND attempts=0 AND due_utc < ?");
    $st->execute(['Não enviada: passou mais de ' . WA_SCHED_LATE_HOURS . ' horas do horário marcado (o agendador estava parado).', $now, $late]);
    if ($st->rowCount() > 0) $out[] = ['programadas' => 'atrasadas', 'total' => $st->rowCount()];

    $st = $pdo->prepare("SELECT * FROM whatsapp_scheduled_messages WHERE status='AGENDADA' AND due_utc <= ? AND (next_try_utc IS NULL OR next_try_utc <= ?) ORDER BY due_utc, id LIMIT " . max(1, min(200, $limit)));
    $st->execute([$nowUtc, $nowUtc]);
    $claim = $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='ENVIANDO', updated_at=? WHERE id=? AND status='AGENDADA'");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
      $claim->execute([date('Y-m-d H:i:s'), (int)$row['id']]);
      if ($claim->rowCount() < 1) continue; // outro processo pegou
      try {
        $res = whatsapp_sched_send_one($pdo, $row);
      } catch (Throwable $e) {
        $pdo->prepare("UPDATE whatsapp_scheduled_messages SET status='FALHOU', error=?, updated_at=? WHERE id=?")
          ->execute([mb_substr($e->getMessage(), 0, 240), date('Y-m-d H:i:s'), (int)$row['id']]);
        $res = 'erro';
      }
      $out[] = ['programada' => (int)$row['id'], 'empresa' => (int)$row['company_id'], 'resultado' => $res];
    }
    return $out;
  }
}
