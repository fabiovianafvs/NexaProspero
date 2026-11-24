<?php
declare(strict_types=1);

/**
 * WhatsApp > Campanhas (módulo do SaaS whatsapp_chatbot).
 *
 * Dois tipos:
 *  - UNICA:       envio programado para um público (aniversariantes de um período, quem comprou
 *                 nos últimos N dias, quem não compra há N dias ou todos). Antes de agendar a
 *                 pessoa gera a PRÉVIA (lista gravada em whatsapp_campaign_recipients), confere,
 *                 tira quem não deve receber e confirma. Na hora marcada a lista vai para a fila.
 *  - ANIVERSARIO: automática; todo dia, na hora marcada, envia para quem faz aniversário hoje
 *                 (ou daqui a N dias, para avisar antes de uma promoção).
 *
 * As mensagens entram na fila whatsapp_outbox com horários espaçados (intervalo configurável),
 * para não disparar tudo de uma vez e proteger o número de bloqueio. Quem responde PARAR entra
 * em whatsapp_optouts e não recebe mais campanhas. Na homologação nada é enviado (SIMULADO).
 * Agendador: workers/whatsapp_campanhas_worker.php (timer nexa-whatsapp-campanhas, a cada minuto).
 */

require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/config_store.php';
if (is_file(__DIR__ . '/ambiente.php')) require_once __DIR__ . '/ambiente.php';

const WA_CAMP_CONFIG_KEY = 'whatsapp_campanhas';
const WA_CAMP_OPTOUT_WORDS = ['parar', 'pare', 'stop', 'descadastrar', 'cancelar inscricao', 'nao quero receber', 'nao quero mais receber', 'remover', 'sair da lista'];

if (!function_exists('wa_camp_ready')) {
  function wa_camp_ready(PDO $pdo): bool {
    static $memo = [];
    $k = spl_object_id($pdo);
    if (isset($memo[$k])) return $memo[$k];
    foreach (['whatsapp_campaigns', 'whatsapp_campaign_recipients', 'whatsapp_optouts'] as $t) {
      try { $pdo->query("SELECT 1 FROM {$t} LIMIT 0"); } catch (Throwable $e) { return $memo[$k] = false; }
    }
    return $memo[$k] = true;
  }
}

if (!function_exists('wa_camp_h')) {
  function wa_camp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}

if (!function_exists('wa_camp_simulated')) {
  /** Homologação: as campanhas montam a lista, mas nada é enviado. */
  function wa_camp_simulated(): bool {
    return function_exists('nexa_is_homologacao') && nexa_is_homologacao();
  }
}

/* ------------------------------------------------------------------ configuração */

if (!function_exists('wa_camp_config_defaults')) {
  function wa_camp_config_defaults(): array {
    return [
      'interval' => 20,          // segundos entre uma mensagem e a próxima (mais um pequeno acaso)
      'max_per_campaign' => 1500,
      'only_customers' => 1,     // só cadastros marcados como cliente
      'footer_on' => 1,
      'footer' => 'Para não receber mais mensagens como esta, responda PARAR.',
      'optout_reply' => 'Pronto! Você não vai mais receber nossas campanhas por aqui. Se mudar de ideia, é só nos avisar. 🙂',
    ];
  }
}

if (!function_exists('wa_camp_config')) {
  function wa_camp_config(PDO $pdo, int $companyId): array {
    $cfg = array_merge(wa_camp_config_defaults(), config_store_get($pdo, $companyId, WA_CAMP_CONFIG_KEY) ?: []);
    $cfg['interval'] = max(10, min(300, (int)$cfg['interval']));
    $cfg['max_per_campaign'] = max(10, min(5000, (int)$cfg['max_per_campaign']));
    $cfg['only_customers'] = !empty($cfg['only_customers']) ? 1 : 0;
    $cfg['footer_on'] = !empty($cfg['footer_on']) ? 1 : 0;
    $cfg['footer'] = mb_substr(trim((string)$cfg['footer']), 0, 200);
    $cfg['optout_reply'] = mb_substr(trim((string)$cfg['optout_reply']), 0, 400);
    return $cfg;
  }
}

if (!function_exists('wa_camp_config_save')) {
  function wa_camp_config_save(PDO $pdo, int $companyId, array $in): array {
    $cfg = wa_camp_config($pdo, $companyId);
    $cfg['interval'] = max(10, min(300, (int)($in['interval'] ?? $cfg['interval'])));
    $cfg['max_per_campaign'] = max(10, min(5000, (int)($in['max_per_campaign'] ?? $cfg['max_per_campaign'])));
    $cfg['only_customers'] = !empty($in['only_customers']) ? 1 : 0;
    $cfg['footer_on'] = !empty($in['footer_on']) ? 1 : 0;
    $footer = trim((string)($in['footer'] ?? ''));
    $cfg['footer'] = mb_substr($footer !== '' ? $footer : wa_camp_config_defaults()['footer'], 0, 200);
    $reply = trim((string)($in['optout_reply'] ?? ''));
    $cfg['optout_reply'] = mb_substr($reply !== '' ? $reply : wa_camp_config_defaults()['optout_reply'], 0, 400);
    config_store_set($pdo, $companyId, WA_CAMP_CONFIG_KEY, $cfg);
    return $cfg;
  }
}

/* ------------------------------------------------------------------ listas fixas */

if (!function_exists('wa_camp_audiences')) {
  function wa_camp_audiences(): array {
    return [
      'ANIVERSARIANTES' => 'Aniversariantes',
      'COMPRARAM' => 'Quem comprou nos últimos dias',
      'SEM_COMPRA' => 'Quem não compra há algum tempo',
      'TODOS' => 'Todos com WhatsApp',
    ];
  }
}

if (!function_exists('wa_camp_birth_periods')) {
  function wa_camp_birth_periods(): array {
    return [
      'HOJE' => 'No dia do envio',
      'SEMANA' => 'Na semana do envio (domingo a sábado)',
      'PROX7' => 'Nos 7 dias a partir do envio',
      'MES' => 'No mês do envio',
      'INTERVALO' => 'Entre duas datas (dia/mês)',
    ];
  }
}

if (!function_exists('wa_camp_status_labels')) {
  function wa_camp_status_labels(): array {
    return [
      'RASCUNHO' => 'Rascunho', 'AGENDADA' => 'Agendada', 'ENVIANDO' => 'Enviando', 'CONCLUIDA' => 'Concluída',
      'CANCELADA' => 'Cancelada', 'ATIVA' => 'Ativa', 'PAUSADA' => 'Pausada',
    ];
  }
}

if (!function_exists('wa_camp_recipient_labels')) {
  function wa_camp_recipient_labels(): array {
    return [
      'PREVISTO' => 'Na lista', 'REMOVIDO' => 'Retirado da lista', 'NA_FILA' => 'Na fila', 'ENVIADO' => 'Enviado',
      'FALHOU' => 'Falhou', 'IGNORADO' => 'Não enviado', 'SIMULADO' => 'Simulado (homologação)', 'CANCELADO' => 'Cancelado',
    ];
  }
}

if (!function_exists('wa_camp_variables')) {
  function wa_camp_variables(): array {
    return [
      '{{primeiro_nome}}' => 'Primeiro nome', '{{nome}}' => 'Nome completo', '{{empresa}}' => 'Nome da empresa',
      '{{data_aniversario}}' => 'Dia do aniversário (dd/mm)', '{{idade}}' => 'Idade que vai fazer', '{{ultima_compra}}' => 'Data da última compra',
    ];
  }
}

/* ------------------------------------------------------------------ telefone e descadastro */

if (!function_exists('wa_camp_phone')) {
  /** Celular válido para WhatsApp (55 + DDD + número) a partir do cadastro, ou ''. */
  function wa_camp_phone(string ...$candidates): string {
    foreach ($candidates as $raw) {
      $n = whatsapp_normalize_phone($raw);
      if (strlen($n) >= 12 && strlen($n) <= 13 && str_starts_with($n, '55')) return $n;
    }
    return '';
  }
}

if (!function_exists('wa_camp_phone_fmt')) {
  /** 5531988880001 -> (31) 98888-0001 (para mostrar na tela). */
  function wa_camp_phone_fmt(string $phone): string {
    $d = whatsapp_digits($phone);
    if (strlen($d) >= 12 && str_starts_with($d, '55')) $d = substr($d, 2);
    if (strlen($d) === 11) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 5) . '-' . substr($d, 7);
    if (strlen($d) === 10) return '(' . substr($d, 0, 2) . ') ' . substr($d, 2, 4) . '-' . substr($d, 6);
    return $phone;
  }
}

if (!function_exists('wa_camp_birthday_next_day')) {
  /** Próximo dia em que a campanha de aniversário envia (hoje, se ainda estiver no horário). */
  function wa_camp_birthday_next_day(array $camp, ?int $nowTs = null): DateTimeImmutable {
    $nowTs = $nowTs ?? time();
    $today = date('Y-m-d', $nowTs);
    $start = strtotime($today . ' ' . ((string)($camp['send_time'] ?? '') ?: '09:00') . ':00');
    $ranToday = (string)($camp['last_run_date'] ?? '') === $today;
    $d = new DateTimeImmutable($today);
    return (!$ranToday && $nowTs <= $start + 3 * 3600) ? $d : $d->modify('+1 day');
  }
}

if (!function_exists('wa_camp_optout_phones')) {
  /** Números descadastrados da empresa: [telefone => true]. */
  function wa_camp_optout_phones(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare('SELECT phone FROM whatsapp_optouts WHERE company_id=?');
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $p) $out[(string)$p] = true;
    return $out;
  }
}

if (!function_exists('wa_camp_optout_variants')) {
  /** Celular com e sem o 9 (o WhatsApp às vezes informa o número antigo sem o 9). */
  function wa_camp_optout_variants(string $phone): array {
    $p = whatsapp_normalize_phone($phone);
    if ($p === '') return [];
    $v = [$p];
    if (strlen($p) === 13 && $p[4] === '9') $v[] = substr($p, 0, 4) . substr($p, 5);
    elseif (strlen($p) === 12 && (int)$p[4] >= 6) $v[] = substr($p, 0, 4) . '9' . substr($p, 4);
    return array_values(array_unique($v));
  }
}

if (!function_exists('wa_camp_is_optout')) {
  function wa_camp_is_optout(array $optouts, string $phone): bool {
    foreach (wa_camp_optout_variants($phone) as $v) if (isset($optouts[$v])) return true;
    return false;
  }
}

if (!function_exists('wa_camp_optout_add')) {
  function wa_camp_optout_add(PDO $pdo, int $companyId, string $phone, string $source = 'MANUAL', ?int $userId = null): bool {
    $p = whatsapp_normalize_phone($phone);
    if (strlen($p) < 12) throw new RuntimeException('Informe o número com DDD.');
    $exists = $pdo->prepare('SELECT 1 FROM whatsapp_optouts WHERE company_id=? AND phone=?');
    $exists->execute([$companyId, $p]);
    if ($exists->fetchColumn()) return false;
    $pdo->prepare('INSERT INTO whatsapp_optouts (company_id, phone, source, user_id, created_at) VALUES (?,?,?,?,?)')
      ->execute([$companyId, $p, $source === 'CLIENTE' ? 'CLIENTE' : 'MANUAL', $userId ?: null, date('Y-m-d H:i:s')]);
    return true;
  }
}

if (!function_exists('wa_camp_optout_remove')) {
  function wa_camp_optout_remove(PDO $pdo, int $companyId, string $phone): void {
    $pdo->prepare('DELETE FROM whatsapp_optouts WHERE company_id=? AND phone=?')->execute([$companyId, whatsapp_normalize_phone($phone)]);
  }
}

if (!function_exists('wa_camp_optout_list')) {
  function wa_camp_optout_list(PDO $pdo, int $companyId, int $limit = 500): array {
    $st = $pdo->prepare('SELECT o.phone, o.source, o.created_at, u.name AS user_name FROM whatsapp_optouts o LEFT JOIN users u ON u.id=o.user_id WHERE o.company_id=? ORDER BY o.created_at DESC LIMIT ' . max(1, min(5000, $limit)));
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('wa_camp_is_optout_text')) {
  /** O cliente pediu para sair da lista? (PARAR, STOP, descadastrar...) — só a mensagem inteira. */
  function wa_camp_is_optout_text(string $text): bool {
    $t = mb_strtolower(trim($text), 'UTF-8');
    $t = strtr($t, ['á' => 'a', 'à' => 'a', 'â' => 'a', 'ã' => 'a', 'é' => 'e', 'ê' => 'e', 'í' => 'i', 'ó' => 'o', 'ô' => 'o', 'õ' => 'o', 'ú' => 'u', 'ç' => 'c']);
    $t = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9 ]+/', ' ', $t)));
    return $t !== '' && in_array($t, WA_CAMP_OPTOUT_WORDS, true);
  }
}

if (!function_exists('wa_camp_optout_from_message')) {
  /**
   * Chamado pelo chatbot ao receber uma mensagem: se for um pedido de descadastro, grava e
   * devolve o texto de resposta; senão devolve null (o robô segue normalmente).
   */
  function wa_camp_optout_from_message(PDO $pdo, int $companyId, string $phone, string $text): ?string {
    if ($companyId <= 0 || $phone === '' || !wa_camp_is_optout_text($text) || !wa_camp_ready($pdo)) return null;
    try { wa_camp_optout_add($pdo, $companyId, $phone, 'CLIENTE'); } catch (Throwable $e) { return null; }
    return wa_camp_config($pdo, $companyId)['optout_reply'];
  }
}

/* ------------------------------------------------------------------ campanha */

if (!function_exists('wa_camp_get')) {
  function wa_camp_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_campaigns WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c) return null;
    $c['opts'] = json_decode((string)($c['audience_json'] ?? ''), true) ?: [];
    return $c;
  }
}

if (!function_exists('wa_camp_list')) {
  function wa_camp_list(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare("SELECT * FROM whatsapp_campaigns WHERE company_id=?
      ORDER BY CASE status WHEN 'ENVIANDO' THEN 1 WHEN 'AGENDADA' THEN 2 WHEN 'ATIVA' THEN 3 WHEN 'RASCUNHO' THEN 4 WHEN 'PAUSADA' THEN 5 ELSE 6 END,
        COALESCE(scheduled_at, created_at) DESC, id DESC LIMIT 300");
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($rows as &$r) $r['opts'] = json_decode((string)($r['audience_json'] ?? ''), true) ?: [];
    return $rows;
  }
}

if (!function_exists('wa_camp_counts')) {
  /** Quantos destinatários em cada situação: [campanha => [situação => qtd]]. */
  function wa_camp_counts(PDO $pdo, int $companyId, array $ids, ?string $runDate = null): array {
    $ids = array_values(array_filter(array_map('intval', $ids)));
    if (!$ids) return [];
    $sql = 'SELECT campaign_id, status, COUNT(*) qtd FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
    $params = array_merge([$companyId], $ids);
    if ($runDate !== null) { $sql .= ' AND run_date=?'; $params[] = $runDate; }
    $st = $pdo->prepare($sql . ' GROUP BY campaign_id, status');
    $st->execute($params);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['campaign_id']][(string)$r['status']] = (int)$r['qtd'];
    return $out;
  }
}

if (!function_exists('wa_camp_parse_daymonth')) {
  /** "25/12" -> "12-25" (mês-dia), ou ''. */
  function wa_camp_parse_daymonth(string $v): string {
    if (!preg_match('/^\s*(\d{1,2})\s*[\/\-.]\s*(\d{1,2})\s*$/', $v, $m)) return '';
    $d = (int)$m[1]; $mo = (int)$m[2];
    if ($mo < 1 || $mo > 12 || $d < 1 || $d > 31 || !checkdate($mo, $d, 2024)) return '';
    return sprintf('%02d-%02d', $mo, $d);
  }
}

if (!function_exists('wa_camp_save')) {
  /** Cria ou altera uma campanha. Envio programado só se altera em rascunho (a prévia é refeita). */
  function wa_camp_save(PDO $pdo, int $companyId, int $userId, array $in, int $id = 0): int {
    $cur = $id > 0 ? wa_camp_get($pdo, $companyId, $id) : null;
    if ($id > 0 && !$cur) throw new RuntimeException('Campanha não encontrada.');
    $kind = $cur ? (string)$cur['kind'] : (strtoupper((string)($in['kind'] ?? 'UNICA')) === 'ANIVERSARIO' ? 'ANIVERSARIO' : 'UNICA');
    if ($cur && $kind === 'UNICA' && $cur['status'] !== 'RASCUNHO') throw new RuntimeException('Só dá para alterar a campanha em rascunho. Use "Voltar para rascunho" antes.');
    if ($cur && in_array($cur['status'], ['CONCLUIDA', 'CANCELADA'], true)) throw new RuntimeException('Campanha encerrada não pode ser alterada. Use "Copiar".');

    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 120);
    if ($name === '') throw new RuntimeException('Dê um nome para a campanha.');
    $message = trim(str_replace("\r\n", "\n", (string)($in['message'] ?? '')));
    if ($message === '') throw new RuntimeException('Escreva a mensagem.');
    if (mb_strlen($message) > 3000) throw new RuntimeException('Mensagem muito longa (máximo 3.000 caracteres).');

    $audience = 'ANIVERSARIANTES'; $opts = []; $scheduled = null; $sendTime = null; $daysBefore = 0;
    if ($kind === 'UNICA') {
      $audience = strtoupper((string)($in['audience'] ?? 'TODOS'));
      if (!isset(wa_camp_audiences()[$audience])) $audience = 'TODOS';
      if ($audience === 'ANIVERSARIANTES') {
        $period = strtoupper((string)($in['birth_period'] ?? 'HOJE'));
        if (!isset(wa_camp_birth_periods()[$period])) $period = 'HOJE';
        $opts['birth_period'] = $period;
        if ($period === 'INTERVALO') {
          $from = wa_camp_parse_daymonth((string)($in['birth_from'] ?? ''));
          $to = wa_camp_parse_daymonth((string)($in['birth_to'] ?? ''));
          if ($from === '' || $to === '') throw new RuntimeException('Informe o intervalo de aniversário no formato dia/mês (ex.: 01/12 a 07/12).');
          $opts['birth_from'] = $from; $opts['birth_to'] = $to;
        }
      } elseif ($audience === 'COMPRARAM' || $audience === 'SEM_COMPRA') {
        $days = (int)($in['days'] ?? 90);
        if ($days < 1 || $days > 1095) throw new RuntimeException('Informe a quantidade de dias (1 a 1095).');
        $opts['days'] = $days;
      }
      $date = trim((string)($in['scheduled_date'] ?? ''));
      $time = trim((string)($in['scheduled_time'] ?? ''));
      if ($date !== '') {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !preg_match('/^\d{2}:\d{2}$/', $time ?: '09:00')) throw new RuntimeException('Data ou hora do envio inválida.');
        $scheduled = $date . ' ' . ($time !== '' ? $time : '09:00') . ':00';
      }
    } else {
      $sendTime = trim((string)($in['send_time'] ?? '09:00'));
      if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $sendTime)) throw new RuntimeException('Hora do envio inválida.');
      $daysBefore = max(0, min(30, (int)($in['days_before'] ?? 0)));
    }
    if (array_key_exists('only_customers', $in)) $opts['only_customers'] = !empty($in['only_customers']) ? 1 : 0;

    $now = date('Y-m-d H:i:s');
    $json = json_encode($opts, JSON_UNESCAPED_UNICODE);
    if ($cur) {
      $pdo->prepare('UPDATE whatsapp_campaigns SET name=?, audience=?, audience_json=?, message=?, scheduled_at=?, send_time=?, days_before=?, preview_at=NULL, updated_at=? WHERE company_id=? AND id=?')
        ->execute([$name, $audience, $json, $message, $scheduled, $sendTime, $daysBefore, $now, $companyId, $id]);
      // Mudou a campanha: a prévia antiga não vale mais.
      if ($kind === 'UNICA') $pdo->prepare("DELETE FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=? AND status IN ('PREVISTO','REMOVIDO')")->execute([$companyId, $id]);
      return $id;
    }
    $status = $kind === 'ANIVERSARIO' ? 'PAUSADA' : 'RASCUNHO';
    $pdo->prepare('INSERT INTO whatsapp_campaigns (company_id, name, kind, audience, audience_json, message, scheduled_at, send_time, days_before, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, $name, $kind, $audience, $json, $message, $scheduled, $sendTime, $daysBefore, $status, $userId ?: null, $now, $now]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('wa_camp_copy')) {
  function wa_camp_copy(PDO $pdo, int $companyId, int $userId, int $id): int {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c) throw new RuntimeException('Campanha não encontrada.');
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('INSERT INTO whatsapp_campaigns (company_id, name, kind, audience, audience_json, message, scheduled_at, send_time, days_before, status, created_by, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, mb_substr('Cópia de ' . $c['name'], 0, 120), $c['kind'], $c['audience'], $c['audience_json'], $c['message'], null, $c['send_time'], (int)$c['days_before'], $c['kind'] === 'ANIVERSARIO' ? 'PAUSADA' : 'RASCUNHO', $userId ?: null, $now, $now]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('wa_camp_delete')) {
  function wa_camp_delete(PDO $pdo, int $companyId, int $id): void {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c) throw new RuntimeException('Campanha não encontrada.');
    if (in_array($c['status'], ['AGENDADA', 'ENVIANDO', 'ATIVA'], true)) throw new RuntimeException('Cancele ou pause a campanha antes de excluir.');
    $sent = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=? AND status IN ('NA_FILA','ENVIADO','FALHOU')");
    $sent->execute([$companyId, $id]);
    if ((int)$sent->fetchColumn() > 0) throw new RuntimeException('Esta campanha já enviou mensagens e fica no histórico. Não dá para excluir.');
    $pdo->prepare('DELETE FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=?')->execute([$companyId, $id]);
    $pdo->prepare('DELETE FROM whatsapp_campaigns WHERE company_id=? AND id=?')->execute([$companyId, $id]);
  }
}

/* ------------------------------------------------------------------ público */

if (!function_exists('wa_camp_birth_dates')) {
  /** Datas (Y-m-d) em que caem os aniversários procurados, a partir do dia do envio. */
  function wa_camp_birth_dates(array $camp, DateTimeImmutable $sendDay): array {
    $sendDay = $sendDay->setTime(0, 0);
    if (($camp['kind'] ?? '') === 'ANIVERSARIO') return [$sendDay->modify('+' . (int)($camp['days_before'] ?? 0) . ' days')->format('Y-m-d')];
    $opts = $camp['opts'] ?? [];
    $period = (string)($opts['birth_period'] ?? 'HOJE');
    $start = $sendDay; $end = $sendDay;
    if ($period === 'SEMANA') { $start = $sendDay->modify('-' . (int)$sendDay->format('w') . ' days'); $end = $start->modify('+6 days'); }
    elseif ($period === 'PROX7') { $end = $sendDay->modify('+6 days'); }
    elseif ($period === 'MES') { $start = $sendDay->modify('first day of this month'); $end = $sendDay->modify('last day of this month'); }
    elseif ($period === 'INTERVALO') {
      $from = (string)($opts['birth_from'] ?? ''); $to = (string)($opts['birth_to'] ?? '');
      if ($from === '' || $to === '') return [];
      $y = (int)$sendDay->format('Y');
      $start = new DateTimeImmutable($y . '-' . $from);
      if ($start < $sendDay->modify('-180 days')) $start = $start->modify('+1 year');
      $end = new DateTimeImmutable((int)$start->format('Y') . '-' . $to);
      if ($end < $start) $end = $end->modify('+1 year');
    }
    $out = [];
    for ($d = $start, $i = 0; $d <= $end && $i < 370; $d = $d->modify('+1 day'), $i++) $out[] = $d->format('Y-m-d');
    return $out;
  }
}

if (!function_exists('wa_camp_last_purchases')) {
  /** Data da última compra finalizada de cada cliente: [pessoa => 'Y-m-d H:i:s']. */
  function wa_camp_last_purchases(PDO $pdo, int $companyId): array {
    try {
      $st = $pdo->prepare("SELECT customer_id, MAX(recognized_at) last_at FROM pedidos WHERE company_id=? AND status='finalizado' AND recognized_at IS NOT NULL AND customer_id IS NOT NULL AND customer_id>0 GROUP BY customer_id");
      $st->execute([$companyId]);
    } catch (Throwable $e) {
      throw new RuntimeException('Não foi possível ler as vendas para montar este público.');
    }
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['customer_id']] = (string)$r['last_at'];
    return $out;
  }
}

if (!function_exists('wa_camp_company_name')) {
  function wa_camp_company_name(PDO $pdo, int $companyId): string {
    static $memo = [];
    if (isset($memo[$companyId])) return $memo[$companyId];
    try { $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1'); $st->execute([$companyId]); $n = trim((string)($st->fetchColumn() ?: '')); }
    catch (Throwable $e) { $n = ''; }
    return $memo[$companyId] = $n;
  }
}

if (!function_exists('wa_camp_audience')) {
  /**
   * Quem recebe a campanha no dia do envio: [{person_id, name, phone, vars}], sem duplicar
   * telefone, sem descadastrados e só com celular válido. Também devolve o resumo do que ficou de fora.
   */
  function wa_camp_audience(PDO $pdo, int $companyId, array $camp, DateTimeImmutable $sendDay, ?array &$skipped = null): array {
    $skipped = ['sem_whatsapp' => 0, 'descadastrado' => 0, 'repetido' => 0];
    $cfg = wa_camp_config($pdo, $companyId);
    $onlyCustomers = array_key_exists('only_customers', $camp['opts'] ?? []) ? !empty($camp['opts']['only_customers']) : !empty($cfg['only_customers']);
    $audience = ($camp['kind'] ?? '') === 'ANIVERSARIO' ? 'ANIVERSARIANTES' : (string)($camp['audience'] ?? 'TODOS');

    $sql = 'SELECT id, name, person_type, birth_opening_date, phone, whatsapp FROM finance_people WHERE company_id=? AND active=1';
    if ($onlyCustomers) $sql .= ' AND is_customer=1';
    if ($audience === 'ANIVERSARIANTES') $sql .= " AND birth_opening_date IS NOT NULL AND person_type='FISICA'";
    $st = $pdo->prepare($sql . ' ORDER BY name, id');
    $st->execute([$companyId]);
    $people = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];

    // Aniversário: data em que cai o aniversário de cada um dentro do período procurado.
    $byMonthDay = [];
    if ($audience === 'ANIVERSARIANTES') {
      foreach (wa_camp_birth_dates($camp, $sendDay) as $d) {
        $md = substr($d, 5);
        $byMonthDay[$md] = $d;
        // 29/02 em ano que não é bissexto: comemora em 28/02.
        if ($md === '02-28' && !checkdate(2, 29, (int)substr($d, 0, 4))) $byMonthDay['02-29'] = $d;
      }
    }
    $purchases = in_array($audience, ['COMPRARAM', 'SEM_COMPRA'], true) ? wa_camp_last_purchases($pdo, $companyId) : [];
    $limitAt = $sendDay->modify('-' . (int)(($camp['opts']['days'] ?? 90)) . ' days')->format('Y-m-d 00:00:00');

    $optouts = wa_camp_optout_phones($pdo, $companyId);
    $company = wa_camp_company_name($pdo, $companyId);
    $seen = []; $out = [];
    foreach ($people as $p) {
      $pid = (int)$p['id'];
      $vars = ['data_aniversario' => '', 'idade' => '', 'ultima_compra' => ''];
      if ($audience === 'ANIVERSARIANTES') {
        $birth = (string)$p['birth_opening_date'];
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $birth, $m)) continue;
        $occ = $byMonthDay[$m[2] . '-' . $m[3]] ?? null;
        if ($occ === null) continue;
        $vars['data_aniversario'] = substr($occ, 8, 2) . '/' . substr($occ, 5, 2);
        $age = (int)substr($occ, 0, 4) - (int)$m[1];
        $vars['idade'] = ($age > 0 && $age < 130) ? (string)$age : '';
      } elseif ($audience === 'COMPRARAM') {
        if (!isset($purchases[$pid]) || $purchases[$pid] < $limitAt) continue;
      } elseif ($audience === 'SEM_COMPRA') {
        if (!isset($purchases[$pid]) || $purchases[$pid] >= $limitAt) continue;
      }
      if (isset($purchases[$pid])) $vars['ultima_compra'] = date('d/m/Y', strtotime($purchases[$pid]));
      $phone = wa_camp_phone((string)($p['whatsapp'] ?? ''), (string)($p['phone'] ?? ''));
      if ($phone === '') { $skipped['sem_whatsapp']++; continue; }
      if (wa_camp_is_optout($optouts, $phone)) { $skipped['descadastrado']++; continue; }
      if (isset($seen[$phone])) { $skipped['repetido']++; continue; }
      $seen[$phone] = true;
      $name = trim((string)$p['name']);
      $first = (string)(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY)[0] ?? $name);
      $first = mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
      $out[] = ['person_id' => $pid, 'name' => $name, 'phone' => $phone, 'vars' => $vars + ['nome' => $name, 'primeiro_nome' => $first, 'empresa' => $company]];
    }
    return $out;
  }
}

if (!function_exists('wa_camp_render')) {
  function wa_camp_render(string $message, array $vars, array $cfg): string {
    $rep = [];
    foreach ($vars as $k => $v) $rep['{{' . $k . '}}'] = (string)$v;
    $text = strtr($message, $rep);
    $text = trim((string)preg_replace('/\{\{[a-z_]+\}\}/', '', $text));
    if (!empty($cfg['footer_on']) && trim((string)$cfg['footer']) !== '') $text .= "\n\n_" . trim((string)$cfg['footer']) . '_';
    return $text;
  }
}

if (!function_exists('wa_camp_send_day')) {
  /** Dia do envio da campanha programada (sem data = hoje). */
  function wa_camp_send_day(array $camp): DateTimeImmutable {
    $s = (string)($camp['scheduled_at'] ?? '');
    return new DateTimeImmutable($s !== '' ? substr($s, 0, 10) : date('Y-m-d'));
  }
}

if (!function_exists('wa_camp_fill')) {
  /** Grava a lista (situação PREVISTO) de uma execução. Retorna [gravados, resumo dos que ficaram de fora]. */
  function wa_camp_fill(PDO $pdo, int $companyId, array $camp, DateTimeImmutable $sendDay): array {
    $cfg = wa_camp_config($pdo, $companyId);
    $list = wa_camp_audience($pdo, $companyId, $camp, $sendDay, $skipped);
    if (count($list) > $cfg['max_per_campaign']) {
      throw new RuntimeException('Este público tem ' . count($list) . ' pessoas, acima do limite de ' . $cfg['max_per_campaign'] . ' por campanha (Configurações de envio). Divida em campanhas menores para proteger o número de bloqueio.');
    }
    $runDate = $sendDay->format('Y-m-d');
    $now = date('Y-m-d H:i:s');
    $ins = $pdo->prepare("INSERT INTO whatsapp_campaign_recipients (company_id, campaign_id, run_date, person_id, name, phone, message, status, created_at) VALUES (?,?,?,?,?,?,?,'PREVISTO',?)");
    $exists = $pdo->prepare('SELECT 1 FROM whatsapp_campaign_recipients WHERE campaign_id=? AND run_date=? AND phone=? LIMIT 1');
    $n = 0;
    foreach ($list as $r) {
      $exists->execute([(int)$camp['id'], $runDate, $r['phone']]);
      if ($exists->fetchColumn()) continue;
      $ins->execute([$companyId, (int)$camp['id'], $runDate, $r['person_id'] ?: null, mb_substr($r['name'], 0, 150), $r['phone'], wa_camp_render((string)$camp['message'], $r['vars'], $cfg), $now]);
      $n++;
    }
    return [$n, $skipped];
  }
}

if (!function_exists('wa_camp_preview')) {
  /** Monta (ou refaz) a prévia de uma campanha programada em rascunho. */
  function wa_camp_preview(PDO $pdo, int $companyId, int $id): array {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c || $c['kind'] !== 'UNICA') throw new RuntimeException('Campanha não encontrada.');
    if ($c['status'] !== 'RASCUNHO') throw new RuntimeException('A prévia só pode ser refeita em rascunho.');
    $pdo->beginTransaction();
    try {
      $pdo->prepare('DELETE FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=?')->execute([$companyId, $id]);
      $res = wa_camp_fill($pdo, $companyId, $c, wa_camp_send_day($c));
      $pdo->prepare('UPDATE whatsapp_campaigns SET preview_at=?, updated_at=? WHERE company_id=? AND id=?')->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), $companyId, $id]);
      $pdo->commit();
      return $res;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('wa_camp_recipient_toggle')) {
  /** Tira ou devolve alguém da prévia. */
  function wa_camp_recipient_toggle(PDO $pdo, int $companyId, int $campaignId, int $recipientId): string {
    $c = wa_camp_get($pdo, $companyId, $campaignId);
    if (!$c || $c['status'] !== 'RASCUNHO') throw new RuntimeException('A lista só pode ser mudada em rascunho.');
    $st = $pdo->prepare("SELECT status FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=? AND id=?");
    $st->execute([$companyId, $campaignId, $recipientId]);
    $cur = (string)($st->fetchColumn() ?: '');
    if (!in_array($cur, ['PREVISTO', 'REMOVIDO'], true)) throw new RuntimeException('Pessoa não encontrada na lista.');
    $new = $cur === 'PREVISTO' ? 'REMOVIDO' : 'PREVISTO';
    $pdo->prepare('UPDATE whatsapp_campaign_recipients SET status=? WHERE company_id=? AND id=?')->execute([$new, $companyId, $recipientId]);
    return $new;
  }
}

if (!function_exists('wa_camp_confirm')) {
  /** Confere a prévia e agenda (ou manda já, se a hora marcada já passou ou não foi informada). */
  function wa_camp_confirm(PDO $pdo, int $companyId, int $userId, int $id, bool $sendNow = false): string {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c || $c['kind'] !== 'UNICA') throw new RuntimeException('Campanha não encontrada.');
    if ($c['status'] !== 'RASCUNHO') throw new RuntimeException('Esta campanha não está em rascunho.');
    if (empty($c['preview_at'])) throw new RuntimeException('Gere e confira a prévia antes de confirmar.');
    $cnt = wa_camp_counts($pdo, $companyId, [$id])[$id]['PREVISTO'] ?? 0;
    if ($cnt <= 0) throw new RuntimeException('A lista está vazia: ninguém vai receber esta campanha.');
    $when = (string)($c['scheduled_at'] ?? '');
    if ($sendNow || $when === '' || strtotime($when) <= time()) {
      // A lista foi montada para o dia marcado; enviar em outro dia exige refazer a prévia.
      if ($when !== '' && substr($when, 0, 10) !== date('Y-m-d') && $c['audience'] !== 'TODOS') {
        throw new RuntimeException('A prévia foi montada para ' . date('d/m/Y', strtotime($when)) . '. Ajuste a data para hoje e gere a prévia de novo antes de enviar agora.');
      }
      $when = date('Y-m-d H:i:s');
    }
    $now = date('Y-m-d H:i:s');
    $st = $pdo->prepare("UPDATE whatsapp_campaigns SET status='AGENDADA', scheduled_at=?, confirmed_by=?, confirmed_at=?, updated_at=? WHERE company_id=? AND id=? AND status='RASCUNHO'");
    $st->execute([$when, $userId ?: null, $now, $now, $companyId, $id]);
    if ($st->rowCount() !== 1) throw new RuntimeException('A campanha mudou enquanto você conferia. Atualize a página.');
    return $when;
  }
}

if (!function_exists('wa_camp_unschedule')) {
  function wa_camp_unschedule(PDO $pdo, int $companyId, int $id): void {
    $st = $pdo->prepare("UPDATE whatsapp_campaigns SET status='RASCUNHO', confirmed_by=NULL, confirmed_at=NULL, updated_at=? WHERE company_id=? AND id=? AND status='AGENDADA'");
    $st->execute([date('Y-m-d H:i:s'), $companyId, $id]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Só campanhas agendadas (que ainda não começaram) voltam para rascunho.');
  }
}

if (!function_exists('wa_camp_set_active')) {
  /** Liga ou pausa a campanha automática de aniversário. */
  function wa_camp_set_active(PDO $pdo, int $companyId, int $id, bool $on): void {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c || $c['kind'] !== 'ANIVERSARIO') throw new RuntimeException('Campanha não encontrada.');
    $pdo->prepare('UPDATE whatsapp_campaigns SET status=?, updated_at=? WHERE company_id=? AND id=?')->execute([$on ? 'ATIVA' : 'PAUSADA', date('Y-m-d H:i:s'), $companyId, $id]);
  }
}

if (!function_exists('wa_camp_cancel')) {
  /** Cancela: o que ainda está na fila não sai mais (o que já foi enviado fica no histórico). */
  function wa_camp_cancel(PDO $pdo, int $companyId, int $id): int {
    $c = wa_camp_get($pdo, $companyId, $id);
    if (!$c) throw new RuntimeException('Campanha não encontrada.');
    if (!in_array($c['status'], ['AGENDADA', 'ENVIANDO', 'RASCUNHO', 'ATIVA', 'PAUSADA'], true)) throw new RuntimeException('Esta campanha já terminou.');
    $now = date('Y-m-d H:i:s');
    $n = 0;
    $st = $pdo->prepare("SELECT id, outbox_id FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=? AND status IN ('NA_FILA','PREVISTO')");
    $st->execute([$companyId, $id]);
    $delOut = $pdo->prepare("DELETE FROM whatsapp_outbox WHERE company_id=? AND id=? AND status='PENDENTE'");
    $updRec = $pdo->prepare("UPDATE whatsapp_campaign_recipients SET status='CANCELADO', reason='Campanha cancelada.' WHERE company_id=? AND id=?");
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      if ((int)$r['outbox_id'] > 0) {
        try { $delOut->execute([$companyId, (int)$r['outbox_id']]); } catch (Throwable $e) {}
        if ($delOut->rowCount() !== 1) continue; // já saiu ou está saindo agora: o acompanhamento mostra o resultado
      }
      $updRec->execute([$companyId, (int)$r['id']]);
      $n++;
    }
    $pdo->prepare("UPDATE whatsapp_campaigns SET status='CANCELADA', finished_at=?, updated_at=? WHERE company_id=? AND id=?")->execute([$now, $now, $companyId, $id]);
    return $n;
  }
}

/* ------------------------------------------------------------------ envio */

if (!function_exists('wa_camp_enqueue')) {
  /**
   * Põe na fila do WhatsApp a lista PREVISTO de uma execução, com horários espaçados.
   * Na homologação marca SIMULADO; sem WhatsApp ligado/fila instalada marca IGNORADO.
   */
  function wa_camp_enqueue(PDO $pdo, int $companyId, array $camp, string $runDate): array {
    $cfg = wa_camp_config($pdo, $companyId);
    $st = $pdo->prepare("SELECT id, phone, message FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=? AND run_date=? AND status='PREVISTO' ORDER BY id");
    $st->execute([$companyId, (int)$camp['id'], $runDate]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $res = ['fila' => 0, 'simulado' => 0, 'ignorado' => 0];
    if (!$rows) return $res;
    $setStatus = $pdo->prepare('UPDATE whatsapp_campaign_recipients SET status=?, reason=?, queued_at=? WHERE company_id=? AND id=?');
    $now = date('Y-m-d H:i:s');

    $reason = '';
    if (wa_camp_simulated()) $reason = 'SIMULADO';
    else {
      $wa = whatsapp_load_config($pdo, $companyId);
      if (empty($wa['enabled'])) $reason = 'A automação do WhatsApp da empresa está desligada.';
      elseif (!whatsapp_outbox_active($pdo)) $reason = 'A fila de envio do WhatsApp não está instalada no servidor.';
    }
    if ($reason !== '') {
      $status = $reason === 'SIMULADO' ? 'SIMULADO' : 'IGNORADO';
      $msg = $reason === 'SIMULADO' ? 'Homologação: nada é enviado.' : $reason;
      foreach ($rows as $r) $setStatus->execute([$status, $msg, $now, $companyId, (int)$r['id']]);
      $res[$status === 'SIMULADO' ? 'simulado' : 'ignorado'] = count($rows);
      return $res;
    }

    // Quem pediu para sair depois da prévia também não recebe.
    $optouts = wa_camp_optout_phones($pdo, $companyId);
    $ins = $pdo->prepare("INSERT INTO whatsapp_outbox (company_id, pedido_id, tipo, destino, mensagem, status, next_attempt_at, created_at) VALUES (?, NULL, 'campanha', ?, ?, 'PENDENTE', ?, ?)");
    $link = $pdo->prepare("UPDATE whatsapp_campaign_recipients SET status='NA_FILA', outbox_id=?, queued_at=?, reason=NULL WHERE company_id=? AND id=?");
    $at = time() + 5;
    foreach ($rows as $r) {
      if (wa_camp_is_optout($optouts, (string)$r['phone'])) {
        $setStatus->execute(['IGNORADO', 'Pediu para não receber campanhas.', $now, $companyId, (int)$r['id']]);
        $res['ignorado']++;
        continue;
      }
      // created_at = hora prevista do envio (a fila descarta o que ficou velho a partir dela).
      $when = date('Y-m-d H:i:s', $at);
      $ins->execute([$companyId, mb_substr((string)$r['phone'], 0, 30), (string)$r['message'], $when, $when]);
      $link->execute([(int)$pdo->lastInsertId(), $now, $companyId, (int)$r['id']]);
      $res['fila']++;
      $at += $cfg['interval'] + random_int(0, max(1, intdiv($cfg['interval'], 2)));
    }
    if ($res['fila'] > 0) whatsapp_outbox_signal();
    return $res;
  }
}

if (!function_exists('wa_camp_sync')) {
  /** Traz da fila o resultado de cada envio e encerra as campanhas que terminaram. */
  function wa_camp_sync(PDO $pdo, ?int $companyId = null): void {
    $where = $companyId ? ' AND r.company_id=' . (int)$companyId : '';
    try {
      $rows = $pdo->query("SELECT r.id, r.company_id, o.status, o.last_error, o.sent_at FROM whatsapp_campaign_recipients r JOIN whatsapp_outbox o ON o.id=r.outbox_id AND o.company_id=r.company_id
        WHERE r.status='NA_FILA' AND o.status IN ('ENVIADO','FALHOU','CANCELADO'){$where} LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { $rows = []; }
    $upd = $pdo->prepare('UPDATE whatsapp_campaign_recipients SET status=?, reason=?, sent_at=? WHERE company_id=? AND id=?');
    foreach ($rows as $r) {
      $status = $r['status'] === 'ENVIADO' ? 'ENVIADO' : ($r['status'] === 'CANCELADO' ? 'CANCELADO' : 'FALHOU');
      $upd->execute([$status, $status === 'ENVIADO' ? null : mb_substr((string)($r['last_error'] ?? ''), 0, 255), $r['sent_at'] ?: null, (int)$r['company_id'], (int)$r['id']]);
    }
    // Saiu da fila sem resultado (apagada na Fila de Envio, por exemplo).
    try {
      $lost = $pdo->query("SELECT r.id, r.company_id FROM whatsapp_campaign_recipients r LEFT JOIN whatsapp_outbox o ON o.id=r.outbox_id WHERE r.status='NA_FILA' AND o.id IS NULL{$where} LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($lost as $r) $upd->execute(['CANCELADO', 'Retirada da fila de envio.', null, (int)$r['company_id'], (int)$r['id']]);
    } catch (Throwable $e) {}
    // Programada que não tem mais nada na fila: concluída.
    $cw = $companyId ? ' AND c.company_id=' . (int)$companyId : '';
    $done = $pdo->query("SELECT c.id, c.company_id FROM whatsapp_campaigns c WHERE c.status='ENVIANDO'{$cw}
      AND NOT EXISTS (SELECT 1 FROM whatsapp_campaign_recipients r WHERE r.campaign_id=c.id AND r.status IN ('NA_FILA','PREVISTO'))")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    $fin = $pdo->prepare("UPDATE whatsapp_campaigns SET status='CONCLUIDA', finished_at=?, updated_at=? WHERE company_id=? AND id=? AND status='ENVIANDO'");
    foreach ($done as $c) $fin->execute([date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), (int)$c['company_id'], (int)$c['id']]);
  }
}

if (!function_exists('wa_camp_dispatch')) {
  /**
   * Agendador (timer a cada minuto, ou botão "Processar agora"): campanhas programadas que
   * chegaram na hora e as de aniversário do dia. Retorna um resumo do que fez.
   */
  function wa_camp_dispatch(PDO $pdo, ?int $onlyCompany = null, ?int $nowTs = null): array {
    if (!wa_camp_ready($pdo)) return ['erro' => 'tabelas ausentes'];
    $nowTs = $nowTs ?? time();
    $now = date('Y-m-d H:i:s', $nowTs);
    $today = date('Y-m-d', $nowTs);
    $log = [];
    $cw = $onlyCompany ? ' AND company_id=' . (int)$onlyCompany : '';

    // 1) Programadas que chegaram na hora.
    $due = $pdo->query("SELECT id, company_id FROM whatsapp_campaigns WHERE kind='UNICA' AND status='AGENDADA' AND scheduled_at<='{$now}'{$cw} ORDER BY scheduled_at LIMIT 50")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($due as $d) {
      $cid = (int)$d['company_id']; $id = (int)$d['id'];
      $claim = $pdo->prepare("UPDATE whatsapp_campaigns SET status='ENVIANDO', started_at=?, updated_at=? WHERE company_id=? AND id=? AND status='AGENDADA'");
      $claim->execute([$now, $now, $cid, $id]);
      if ($claim->rowCount() !== 1) continue;
      try {
        $c = wa_camp_get($pdo, $cid, $id);
        $runDate = (string)$pdo->query('SELECT MIN(run_date) FROM whatsapp_campaign_recipients WHERE campaign_id=' . $id . " AND status='PREVISTO'")->fetchColumn();
        $log[] = ['campanha' => $id, 'empresa' => $cid] + ($runDate !== '' ? wa_camp_enqueue($pdo, $cid, $c, $runDate) : []);
      } catch (Throwable $e) {
        $log[] = ['campanha' => $id, 'empresa' => $cid, 'erro' => $e->getMessage()];
      }
    }

    // 2) Aniversário automático: uma vez por dia, a partir da hora marcada (até 3 horas depois,
    //    para não mandar à noite se o servidor ficou parado).
    $birth = $pdo->query("SELECT id, company_id, send_time FROM whatsapp_campaigns WHERE kind='ANIVERSARIO' AND status='ATIVA' AND (last_run_date IS NULL OR last_run_date<'{$today}'){$cw} LIMIT 200")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($birth as $b) {
      $start = strtotime($today . ' ' . ((string)$b['send_time'] ?: '09:00') . ':00');
      if ($nowTs < $start || $nowTs > $start + 3 * 3600) continue;
      $cid = (int)$b['company_id']; $id = (int)$b['id'];
      $claim = $pdo->prepare("UPDATE whatsapp_campaigns SET last_run_date=?, started_at=?, updated_at=? WHERE company_id=? AND id=? AND status='ATIVA' AND (last_run_date IS NULL OR last_run_date<?)");
      $claim->execute([$today, $now, $now, $cid, $id, $today]);
      if ($claim->rowCount() !== 1) continue;
      try {
        $c = wa_camp_get($pdo, $cid, $id);
        [$n] = wa_camp_fill($pdo, $cid, $c, new DateTimeImmutable($today));
        $log[] = ['campanha' => $id, 'empresa' => $cid, 'lista' => $n] + wa_camp_enqueue($pdo, $cid, $c, $today);
      } catch (Throwable $e) {
        $log[] = ['campanha' => $id, 'empresa' => $cid, 'erro' => $e->getMessage()];
      }
    }

    // 3) Resultados da fila; e, se a fila parou com mensagem vencida, avisa o processo de envio.
    wa_camp_sync($pdo, $onlyCompany);
    if (!wa_camp_simulated() && whatsapp_outbox_ready($pdo)) {
      try {
        $stalled = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_outbox WHERE status='PENDENTE' AND tipo='campanha' AND next_attempt_at < (NOW() - INTERVAL 3 MINUTE)")->fetchColumn();
        if ($stalled > 0 && whatsapp_outbox_active($pdo)) whatsapp_outbox_signal();
      } catch (Throwable $e) {}
    }
    return $log;
  }
}

if (!function_exists('wa_camp_recipients')) {
  function wa_camp_recipients(PDO $pdo, int $companyId, int $campaignId, string $status = '', int $limit = 500, ?string $runDate = null): array {
    $sql = 'SELECT * FROM whatsapp_campaign_recipients WHERE company_id=? AND campaign_id=?';
    $params = [$companyId, $campaignId];
    if ($status !== '') { $sql .= ' AND status=?'; $params[] = $status; }
    if ($runDate !== null) { $sql .= ' AND run_date=?'; $params[] = $runDate; }
    $st = $pdo->prepare($sql . ' ORDER BY run_date DESC, name, id LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('wa_camp_audience_summary')) {
  /** Texto curto do público para a lista. */
  function wa_camp_audience_summary(array $c): string {
    if (($c['kind'] ?? '') === 'ANIVERSARIO') {
      $d = (int)($c['days_before'] ?? 0);
      return 'Aniversariantes ' . ($d === 0 ? 'do dia' : 'de daqui a ' . $d . ' dia' . ($d > 1 ? 's' : '')) . ', todo dia às ' . ($c['send_time'] ?: '09:00');
    }
    $o = $c['opts'] ?? [];
    switch ((string)$c['audience']) {
      case 'ANIVERSARIANTES':
        $p = (string)($o['birth_period'] ?? 'HOJE');
        if ($p === 'INTERVALO') return 'Aniversariantes de ' . implode('/', array_reverse(explode('-', (string)($o['birth_from'] ?? '')))) . ' a ' . implode('/', array_reverse(explode('-', (string)($o['birth_to'] ?? ''))));
        return 'Aniversariantes: ' . mb_strtolower(wa_camp_birth_periods()[$p] ?? $p, 'UTF-8');
      case 'COMPRARAM': return 'Compraram nos últimos ' . (int)($o['days'] ?? 90) . ' dias';
      case 'SEM_COMPRA': return 'Não compram há ' . (int)($o['days'] ?? 90) . ' dias ou mais';
      default: return 'Todos com WhatsApp';
    }
  }
}
