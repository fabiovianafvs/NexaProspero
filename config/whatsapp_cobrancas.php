<?php
declare(strict_types=1);

/**
 * WhatsApp > Cobranças (módulo do SaaS whatsapp_chatbot): régua de cobrança do Contas a Receber.
 *
 * Cada etapa da régua tem um número de dias em relação ao vencimento (-5 = 5 dias antes, 0 = no
 * dia, 5 = 5 dias depois, 30, 60...) e a sua mensagem. Todo dia, na hora marcada, o agendador
 * procura os títulos em aberto (ABERTO/PARCIAL com saldo) que caem em cada etapa e manda um
 * lembrete por título. Com boleto, a mensagem leva a linha digitável e o Pix copia e cola vai
 * numa mensagem separada (fácil de copiar). Não repete: whatsapp_billing_sends guarda um registro
 * por título + etapa + vencimento. Envio espaçado pela fila whatsapp_outbox (tipo cobranca);
 * homologação = SIMULADO. Agendador: o mesmo das campanhas (workers/whatsapp_campanhas_worker.php).
 */

require_once __DIR__ . '/whatsapp_campanhas.php';

const WA_COB_CONFIG_KEY = 'whatsapp_cobrancas';
const WA_COB_RUN_KEY = 'whatsapp_cobrancas_run';

if (!function_exists('wa_cob_ready')) {
  function wa_cob_ready(PDO $pdo): bool {
    static $memo = [];
    $k = spl_object_id($pdo);
    if (isset($memo[$k])) return $memo[$k];
    if (!wa_camp_ready($pdo)) return $memo[$k] = false;
    try { $pdo->query('SELECT 1 FROM whatsapp_billing_sends LIMIT 0'); return $memo[$k] = true; } catch (Throwable $e) { return $memo[$k] = false; }
  }
}

if (!function_exists('wa_cob_default_steps')) {
  function wa_cob_default_steps(): array {
    return [
      ['days' => -5, 'active' => 1, 'message' => "Olá, {{primeiro_nome}}! Passando para lembrar que a sua conta de *{{valor}}* com a {{empresa}} vence em *{{vencimento}}*."],
      ['days' => 0, 'active' => 1, 'message' => "Olá, {{primeiro_nome}}! A sua conta de *{{valor}}* com a {{empresa}} vence *hoje* ({{vencimento}}). Se já pagou, desconsidere esta mensagem."],
      ['days' => 5, 'active' => 1, 'message' => "Olá, {{primeiro_nome}}. Ainda não identificamos o pagamento da conta de *{{valor}}*, vencida em {{vencimento}}. Se já pagou, desconsidere. Qualquer dúvida, é só responder esta mensagem."],
      ['days' => 30, 'active' => 0, 'message' => "Olá, {{primeiro_nome}}. A conta de *{{valor}}* com a {{empresa}}, vencida em {{vencimento}}, está em aberto há {{dias}} dias. Podemos ajudar a regularizar? É só responder esta mensagem."],
      ['days' => 60, 'active' => 0, 'message' => "Olá, {{primeiro_nome}}. A conta de *{{valor}}*, vencida em {{vencimento}}, continua em aberto ({{dias}} dias). Entre em contato para combinarmos o pagamento."],
    ];
  }
}

if (!function_exists('wa_cob_config_defaults')) {
  function wa_cob_config_defaults(): array {
    return [
      'enabled' => 0,
      'send_time' => '09:00',
      'include_payment' => 1,     // linha digitável + Pix do boleto, quando houver
      'respect_optout' => 0,      // quem respondeu PARAR às campanhas também não recebe cobrança
      'max_per_person' => 3,      // no máximo N lembretes por pessoa no mesmo dia
      'steps' => wa_cob_default_steps(),
    ];
  }
}

if (!function_exists('wa_cob_normalize_steps')) {
  function wa_cob_normalize_steps(array $steps): array {
    $out = [];
    foreach ($steps as $s) {
      if (!is_array($s)) continue;
      $days = (int)($s['days'] ?? 0);
      if ($days < -30 || $days > 365) continue;
      $msg = trim(str_replace("\r\n", "\n", (string)($s['message'] ?? '')));
      if ($msg === '') continue;
      $out[$days] = ['days' => $days, 'active' => !empty($s['active']) ? 1 : 0, 'message' => mb_substr($msg, 0, 2000)];
    }
    ksort($out);
    return array_values(array_slice($out, 0, 10, true));
  }
}

if (!function_exists('wa_cob_config')) {
  function wa_cob_config(PDO $pdo, int $companyId): array {
    $raw = config_store_get($pdo, $companyId, WA_COB_CONFIG_KEY) ?: [];
    $cfg = array_merge(wa_cob_config_defaults(), $raw);
    $cfg['enabled'] = !empty($cfg['enabled']) ? 1 : 0;
    $cfg['send_time'] = preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string)$cfg['send_time']) ? (string)$cfg['send_time'] : '09:00';
    $cfg['include_payment'] = !empty($cfg['include_payment']) ? 1 : 0;
    $cfg['respect_optout'] = !empty($cfg['respect_optout']) ? 1 : 0;
    $cfg['max_per_person'] = max(1, min(10, (int)$cfg['max_per_person']));
    $cfg['steps'] = wa_cob_normalize_steps(is_array($cfg['steps']) ? $cfg['steps'] : []);
    return $cfg;
  }
}

if (!function_exists('wa_cob_config_save')) {
  /** Salva a régua. $in['steps'] = [{days, active, message}], vindo da tela. */
  function wa_cob_config_save(PDO $pdo, int $companyId, array $in): array {
    $time = trim((string)($in['send_time'] ?? '09:00'));
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) throw new RuntimeException('Hora do envio inválida.');
    $steps = [];
    $seen = [];
    foreach ((array)($in['steps'] ?? []) as $s) {
      if (!is_array($s)) continue;
      $msg = trim((string)($s['message'] ?? ''));
      $daysRaw = trim((string)($s['days'] ?? ''));
      if ($msg === '' && $daysRaw === '') continue; // linha vazia
      if (!preg_match('/^-?\d{1,3}$/', $daysRaw)) throw new RuntimeException('Dias da régua inválidos: use números (negativo = antes do vencimento).');
      $days = (int)$daysRaw;
      if ($days < -30 || $days > 365) throw new RuntimeException('Os dias da régua vão de 30 dias antes (-30) até 365 dias depois.');
      if (isset($seen[$days])) throw new RuntimeException('Há duas etapas com ' . $days . ' dia(s). Cada etapa precisa de um dia diferente.');
      if ($msg === '') throw new RuntimeException('Escreva a mensagem da etapa de ' . $days . ' dia(s).');
      if (mb_strlen($msg) > 2000) throw new RuntimeException('Mensagem muito longa (máximo 2.000 caracteres).');
      $seen[$days] = true;
      $steps[] = ['days' => $days, 'active' => !empty($s['active']) ? 1 : 0, 'message' => $msg];
    }
    if (!$steps) throw new RuntimeException('Cadastre pelo menos uma etapa na régua.');
    if (count($steps) > 10) throw new RuntimeException('Use no máximo 10 etapas.');
    $cfg = [
      'enabled' => !empty($in['enabled']) ? 1 : 0,
      'send_time' => $time,
      'include_payment' => !empty($in['include_payment']) ? 1 : 0,
      'respect_optout' => !empty($in['respect_optout']) ? 1 : 0,
      'max_per_person' => max(1, min(10, (int)($in['max_per_person'] ?? 3))),
      'steps' => wa_cob_normalize_steps($steps),
    ];
    config_store_set($pdo, $companyId, WA_COB_CONFIG_KEY, $cfg);
    return wa_cob_config($pdo, $companyId);
  }
}

if (!function_exists('wa_cob_step_label')) {
  function wa_cob_step_label(int $days): string {
    if ($days === 0) return 'No dia do vencimento';
    if ($days < 0) return abs($days) . ' dia' . (abs($days) > 1 ? 's' : '') . ' antes';
    return $days . ' dia' . ($days > 1 ? 's' : '') . ' depois';
  }
}

if (!function_exists('wa_cob_variables')) {
  function wa_cob_variables(): array {
    return [
      '{{primeiro_nome}}' => 'Primeiro nome', '{{nome}}' => 'Nome completo', '{{empresa}}' => 'Nome da empresa',
      '{{valor}}' => 'Valor em aberto', '{{vencimento}}' => 'Vencimento', '{{dias}}' => 'Dias (antes/depois)',
      '{{descricao}}' => 'Descrição da conta', '{{documento}}' => 'Nº do documento', '{{parcela}}' => 'Parcela',
    ];
  }
}

/* ------------------------------------------------------------------ títulos e mensagem */

if (!function_exists('wa_cob_titles')) {
  /** Títulos em aberto com vencimento em $dueDate (e o cadastro da pessoa). */
  function wa_cob_titles(PDO $pdo, int $companyId, string $dueDate): array {
    $st = $pdo->prepare("SELECT r.id, r.person_id, r.description, r.document_number, r.installment, r.due_date, r.open_amount,
        p.name, p.phone, p.whatsapp
      FROM finance_receivables r
      JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id
      WHERE r.company_id=? AND r.due_date=? AND UPPER(COALESCE(r.status,'')) IN ('ABERTO','PARCIAL') AND COALESCE(r.open_amount,0) > 0.009
      ORDER BY p.name, r.id");
    $st->execute([$companyId, $dueDate]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('wa_cob_charge')) {
  /** Linha digitável e Pix do boleto do título (só boleto válido: registrado e em aberto). */
  function wa_cob_charge(PDO $pdo, int $companyId, int $receivableId): array {
    try {
      $st = $pdo->prepare('SELECT status, linha_digitavel, pix_emv FROM finance_boleto_charges WHERE company_id=? AND receivable_id=? ORDER BY id DESC LIMIT 1');
      $st->execute([$companyId, $receivableId]);
      $c = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
      return [];
    }
    if (!$c || in_array(strtoupper((string)($c['status'] ?? '')), ['CANCELADO', 'PAGO', 'BAIXADO', 'ERRO', 'PENDENTE_REGISTRO'], true)) return [];
    $line = trim((string)($c['linha_digitavel'] ?? ''));
    $pix = trim((string)($c['pix_emv'] ?? ''));
    return ($line !== '' || $pix !== '') ? ['line' => $line, 'pix' => $pix] : [];
  }
}

if (!function_exists('wa_cob_money')) {
  function wa_cob_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
}

if (!function_exists('wa_cob_render')) {
  /** Mensagem da etapa para um título: [texto principal, Pix copia e cola (ou '')]. */
  function wa_cob_render(string $message, array $title, int $days, string $company, array $charge, bool $includePayment): array {
    $name = trim((string)($title['name'] ?? ''));
    $first = (string)(preg_split('/\s+/u', $name, -1, PREG_SPLIT_NO_EMPTY)[0] ?? $name);
    $first = mb_convert_case(mb_strtolower($first, 'UTF-8'), MB_CASE_TITLE, 'UTF-8');
    $text = strtr($message, [
      '{{primeiro_nome}}' => $first, '{{nome}}' => $name, '{{empresa}}' => $company,
      '{{valor}}' => wa_cob_money($title['open_amount'] ?? 0),
      '{{vencimento}}' => !empty($title['due_date']) ? date('d/m/Y', strtotime((string)$title['due_date'])) : '',
      '{{dias}}' => (string)abs($days),
      '{{descricao}}' => trim((string)($title['description'] ?? '')),
      '{{documento}}' => trim((string)($title['document_number'] ?? '')),
      '{{parcela}}' => trim((string)($title['installment'] ?? '')),
    ]);
    $text = trim((string)preg_replace('/\{\{[a-z_]+\}\}/', '', $text));
    $pix = '';
    if ($includePayment && $charge) {
      if ($charge['line'] !== '') $text .= "\n\n*Linha digitável do boleto:*\n" . $charge['line'];
      if ($charge['pix'] !== '') { $pix = $charge['pix']; $text .= "\n\nO *Pix copia e cola* vem na próxima mensagem."; }
    }
    return [$text, $pix];
  }
}

/* ------------------------------------------------------------------ planejamento e envio */

if (!function_exists('wa_cob_plan')) {
  /**
   * O que a régua envia no dia $runDay: [{step, title, phone, text, pix, has_boleto, skip}].
   * skip = motivo de não enviar ('' = envia). Não grava nada (usado também na tela).
   */
  function wa_cob_plan(PDO $pdo, int $companyId, DateTimeImmutable $runDay, ?array $cfg = null): array {
    $cfg = $cfg ?? wa_cob_config($pdo, $companyId);
    $company = wa_camp_company_name($pdo, $companyId);
    $optouts = $cfg['respect_optout'] ? wa_camp_optout_phones($pdo, $companyId) : [];
    $already = $pdo->prepare('SELECT 1 FROM whatsapp_billing_sends WHERE company_id=? AND receivable_id=? AND step_days=? AND due_date=? LIMIT 1');
    $perPerson = [];
    $out = [];
    foreach ($cfg['steps'] as $step) {
      if (empty($step['active'])) continue;
      $days = (int)$step['days'];
      $due = $runDay->modify(($days >= 0 ? '-' : '+') . abs($days) . ' days')->format('Y-m-d');
      foreach (wa_cob_titles($pdo, $companyId, $due) as $t) {
        $phone = wa_camp_phone((string)($t['whatsapp'] ?? ''), (string)($t['phone'] ?? ''));
        $charge = wa_cob_charge($pdo, $companyId, (int)$t['id']);
        [$text, $pix] = wa_cob_render((string)$step['message'], $t, $days, $company, $charge, (bool)$cfg['include_payment']);
        $skip = '';
        $already->execute([$companyId, (int)$t['id'], $days, $due]);
        if ($already->fetchColumn()) $skip = 'Já enviado';
        elseif ($phone === '') $skip = 'Sem celular no cadastro';
        elseif ($cfg['respect_optout'] && wa_camp_is_optout($optouts, $phone)) $skip = 'Pediu para não receber (PARAR)';
        else {
          $k = (int)$t['person_id'];
          $perPerson[$k] = ($perPerson[$k] ?? 0) + 1;
          if ($perPerson[$k] > $cfg['max_per_person']) $skip = 'Limite de lembretes por pessoa no dia';
        }
        $out[] = ['step' => $days, 'due' => $due, 'title' => $t, 'phone' => $phone, 'text' => $text, 'pix' => $pix, 'has_boleto' => $charge ? 1 : 0, 'skip' => $skip];
      }
    }
    return $out;
  }
}

if (!function_exists('wa_cob_run')) {
  /** Executa a régua do dia: grava cada lembrete e põe na fila (ou SIMULADO/IGNORADO). */
  function wa_cob_run(PDO $pdo, int $companyId, DateTimeImmutable $runDay): array {
    $cfg = wa_cob_config($pdo, $companyId);
    $campCfg = wa_camp_config($pdo, $companyId);
    $res = ['fila' => 0, 'simulado' => 0, 'ignorado' => 0, 'pulado' => 0];
    $plan = wa_cob_plan($pdo, $companyId, $runDay, $cfg);
    if (!$plan) return $res;

    $block = '';
    if (wa_camp_simulated()) $block = 'SIMULADO';
    else {
      $wa = whatsapp_load_config($pdo, $companyId);
      if (empty($wa['enabled'])) $block = 'A automação do WhatsApp da empresa está desligada.';
      elseif (!whatsapp_outbox_active($pdo)) $block = 'A fila de envio do WhatsApp não está instalada no servidor.';
    }
    $now = date('Y-m-d H:i:s');
    $run = $runDay->format('Y-m-d');
    $ins = $pdo->prepare('INSERT INTO whatsapp_billing_sends (company_id, receivable_id, person_id, step_days, due_date, run_date, name, phone, amount, message, has_boleto, status, reason, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
    $out = $pdo->prepare("INSERT INTO whatsapp_outbox (company_id, pedido_id, tipo, destino, mensagem, status, next_attempt_at, created_at) VALUES (?, NULL, 'cobranca', ?, ?, 'PENDENTE', ?, ?)");
    $link = $pdo->prepare('UPDATE whatsapp_billing_sends SET outbox_id=?, pix_outbox_id=? WHERE company_id=? AND id=?');
    $at = wa_camp_next_slot($pdo, $companyId, $campCfg, ['cobranca']);
    foreach ($plan as $p) {
      if ($p['skip'] !== '') {
        // "Já enviado" não grava de novo; os outros motivos ficam no histórico (uma vez por etapa).
        if ($p['skip'] === 'Já enviado') { $res['pulado']++; continue; }
        $status = 'IGNORADO'; $reason = $p['skip'];
      } elseif ($block !== '') {
        $status = $block === 'SIMULADO' ? 'SIMULADO' : 'IGNORADO';
        $reason = $block === 'SIMULADO' ? 'Homologação: nada é enviado.' : $block;
      } else {
        $status = 'NA_FILA'; $reason = null;
      }
      $t = $p['title'];
      try {
        $ins->execute([$companyId, (int)$t['id'], (int)$t['person_id'] ?: null, $p['step'], $p['due'], $run, mb_substr((string)$t['name'], 0, 150), $p['phone'], round((float)$t['open_amount'], 2), $p['text'], $p['has_boleto'], $status, $reason, $now]);
      } catch (Throwable $e) {
        $res['pulado']++; // outra execução gravou ao mesmo tempo (chave única)
        continue;
      }
      $sendId = (int)$pdo->lastInsertId();
      if ($status !== 'NA_FILA') { $res[$status === 'SIMULADO' ? 'simulado' : 'ignorado']++; continue; }
      $when = date('Y-m-d H:i:s', $at);
      $out->execute([$companyId, mb_substr($p['phone'], 0, 30), $p['text'], $when, $when]);
      $mainId = (int)$pdo->lastInsertId();
      $pixId = null;
      if ($p['pix'] !== '') {
        $whenPix = date('Y-m-d H:i:s', $at + 4);
        $out->execute([$companyId, mb_substr($p['phone'], 0, 30), $p['pix'], $whenPix, $whenPix]);
        $pixId = (int)$pdo->lastInsertId();
      }
      $link->execute([$mainId, $pixId, $companyId, $sendId]);
      $res['fila']++;
      $at += (int)$campCfg['interval'] + random_int(0, max(1, intdiv((int)$campCfg['interval'], 2))) + ($pixId ? 4 : 0);
    }
    if ($res['fila'] > 0) whatsapp_outbox_signal();
    return $res;
  }
}

if (!function_exists('wa_cob_sync')) {
  /** Traz da fila o resultado de cada lembrete (pela mensagem principal). */
  function wa_cob_sync(PDO $pdo, ?int $companyId = null): void {
    $where = $companyId ? ' AND s.company_id=' . (int)$companyId : '';
    $upd = $pdo->prepare('UPDATE whatsapp_billing_sends SET status=?, reason=?, sent_at=? WHERE company_id=? AND id=?');
    try {
      $rows = $pdo->query("SELECT s.id, s.company_id, o.status, o.last_error, o.sent_at FROM whatsapp_billing_sends s JOIN whatsapp_outbox o ON o.id=s.outbox_id AND o.company_id=s.company_id
        WHERE s.status='NA_FILA' AND o.status IN ('ENVIADO','FALHOU','CANCELADO'){$where} LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($rows as $r) {
        $st = $r['status'] === 'ENVIADO' ? 'ENVIADO' : ($r['status'] === 'CANCELADO' ? 'CANCELADO' : 'FALHOU');
        $upd->execute([$st, $st === 'ENVIADO' ? null : mb_substr((string)($r['last_error'] ?? ''), 0, 255), $r['sent_at'] ?: null, (int)$r['company_id'], (int)$r['id']]);
      }
      $lost = $pdo->query("SELECT s.id, s.company_id FROM whatsapp_billing_sends s LEFT JOIN whatsapp_outbox o ON o.id=s.outbox_id WHERE s.status='NA_FILA' AND o.id IS NULL{$where} LIMIT 2000")->fetchAll(PDO::FETCH_ASSOC) ?: [];
      foreach ($lost as $r) $upd->execute(['CANCELADO', 'Retirada da fila de envio.', null, (int)$r['company_id'], (int)$r['id']]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('wa_cob_next_run_day')) {
  /** Próximo dia em que a régua roda (hoje, se ainda estiver no horário e não rodou). */
  function wa_cob_next_run_day(PDO $pdo, int $companyId, array $cfg, ?int $nowTs = null): DateTimeImmutable {
    $nowTs = $nowTs ?? time();
    $today = date('Y-m-d', $nowTs);
    $start = strtotime($today . ' ' . $cfg['send_time'] . ':00');
    $ran = (string)((config_store_get($pdo, $companyId, WA_COB_RUN_KEY) ?: [])['date'] ?? '') === $today;
    $d = new DateTimeImmutable($today);
    return (!$ran && $nowTs <= $start + 3 * 3600) ? $d : $d->modify('+1 day');
  }
}

if (!function_exists('wa_cob_dispatch')) {
  /**
   * Agendador: para cada empresa com a régua ligada, roda uma vez por dia a partir da hora
   * marcada (até 3 horas depois). Chamado pelo worker das campanhas.
   */
  function wa_cob_dispatch(PDO $pdo, ?int $onlyCompany = null, ?int $nowTs = null): array {
    if (!wa_cob_ready($pdo)) return [];
    $nowTs = $nowTs ?? time();
    $today = date('Y-m-d', $nowTs);
    $log = [];
    $sql = "SELECT company_id FROM app_config WHERE cfg_key='" . WA_COB_CONFIG_KEY . "'" . ($onlyCompany ? ' AND company_id=' . (int)$onlyCompany : '');
    foreach ($pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN) ?: [] as $cid) {
      $cid = (int)$cid;
      $cfg = wa_cob_config($pdo, $cid);
      if (!$cfg['enabled']) continue;
      $start = strtotime($today . ' ' . $cfg['send_time'] . ':00');
      if ($nowTs < $start || $nowTs > $start + 3 * 3600) continue;
      $run = config_store_get($pdo, $cid, WA_COB_RUN_KEY) ?: [];
      if ((string)($run['date'] ?? '') === $today) continue;
      // Marca antes de rodar: se dois agendadores rodarem juntos, a chave única impede repetir o lembrete.
      config_store_set($pdo, $cid, WA_COB_RUN_KEY, ['date' => $today, 'at' => date('Y-m-d H:i:s', $nowTs)]);
      try {
        $log[] = ['cobranca' => $cid] + wa_cob_run($pdo, $cid, new DateTimeImmutable($today));
      } catch (Throwable $e) {
        $log[] = ['cobranca' => $cid, 'erro' => $e->getMessage()];
      }
    }
    wa_cob_sync($pdo, $onlyCompany);
    return $log;
  }
}

if (!function_exists('wa_cob_history')) {
  function wa_cob_history(PDO $pdo, int $companyId, string $from, string $to, string $status = '', int $limit = 1000): array {
    $sql = 'SELECT * FROM whatsapp_billing_sends WHERE company_id=? AND run_date BETWEEN ? AND ?';
    $params = [$companyId, $from, $to];
    if ($status !== '') { $sql .= ' AND status=?'; $params[] = $status; }
    $st = $pdo->prepare($sql . ' ORDER BY run_date DESC, id DESC LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}
