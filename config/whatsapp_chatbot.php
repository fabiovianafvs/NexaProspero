<?php
declare(strict_types=1);

/**
 * Chatbot do WhatsApp (motor leve). O motor entrega cada mensagem recebida em
 * webhooks/whatsapp_entrada.php; aqui ficam as regras do atendimento:
 * menu numerado, 2ª via de boleto (CPF/CNPJ), consulta de pedido, abertura de
 * atendimento no CRM, mensagens automáticas configuráveis (ex.: Certificado
 * Digital) e "falar com atendente" (o robô fica em silêncio por um tempo).
 * Ligado/desligado por empresa em WhatsApp > Chatbot (config_store 'whatsapp_bot').
 */

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/whatsapp_engine.php';

const WHATSAPP_BOT_SESSION_MINUTES = 30;
const WHATSAPP_BOT_DOC_ATTEMPTS_PER_HOUR = 5;

if (!function_exists('whatsapp_bot_ready')) {
  function whatsapp_bot_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('whatsapp_bot_sessions','whatsapp_bot_messages')");
      return $ready = ((int)$st->fetchColumn() === 2);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/** Módulo comercial liberado pela plataforma (SaaS) para a empresa? */
if (!function_exists('whatsapp_bot_module_enabled')) {
  function whatsapp_bot_module_enabled(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0) return false;
    if (!function_exists('saas_company_has_module')) require_once __DIR__ . '/saas_modules.php';
    try {
      return saas_company_has_module($pdo, $companyId, 'whatsapp_chatbot');
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('whatsapp_bot_defaults')) {
  function whatsapp_bot_defaults(): array {
    return [
      'enabled' => false,
      'welcome' => "Olá{nome}! 👋 Sou o assistente virtual da *{empresa}*.\nComo posso ajudar? Responda com o *número* da opção:",
      'opt_boleto' => true,
      'opt_pedido' => true,
      'opt_crm' => true,
      'opt_atendente' => true,
      'auto_messages' => [['label' => 'Certificado Digital', 'text' => '']],
      'pause_hours' => 2,
      'atendente_text' => "Certo! Um atendente vai continuar a conversa por aqui em breve. 🙂\nSe preferir voltar ao menu, digite *menu*.",
      'crm_responsible_user_id' => 0,
      'crm_status_id' => 0,
      'crm_service_type_id' => 0,
    ];
  }
}

if (!function_exists('whatsapp_bot_config')) {
  function whatsapp_bot_config(PDO $pdo, int $companyId): array {
    $cfg = whatsapp_bot_defaults();
    try {
      $saved = config_store_get($pdo, $companyId, 'whatsapp_bot');
      if (is_array($saved)) $cfg = array_merge($cfg, $saved);
    } catch (Throwable $e) {}
    $cfg['enabled'] = !empty($cfg['enabled']);
    foreach (['opt_boleto','opt_pedido','opt_crm','opt_atendente'] as $k) $cfg[$k] = !empty($cfg[$k]);
    $cfg['pause_hours'] = max(1, min(72, (int)$cfg['pause_hours']));
    $auto = [];
    foreach ((array)($cfg['auto_messages'] ?? []) as $m) {
      $label = trim((string)($m['label'] ?? ''));
      if ($label === '') continue;
      $auto[] = ['label' => mb_substr($label, 0, 60), 'text' => mb_substr(trim((string)($m['text'] ?? '')), 0, 3000)];
    }
    $cfg['auto_messages'] = array_slice($auto, 0, 8);
    return $cfg;
  }
}

/** Liga/desliga a entrega de mensagens recebidas no motor para esta empresa. */
if (!function_exists('whatsapp_bot_sync_engine')) {
  function whatsapp_bot_sync_engine(PDO $pdo, int $companyId, bool $enabled): array {
    $waCfg = whatsapp_load_config($pdo, $companyId, false);
    $engine = whatsapp_engine_config($pdo);
    if (!whatsapp_engine_is_url($engine, (string)$waCfg['service_url'])) {
      return ['ok' => false, 'message' => 'O WhatsApp desta empresa ainda está no motor antigo. Peça ao suporte para passar para o motor novo.'];
    }
    $sid = whatsapp_service_company_id($waCfg, $companyId);
    try {
      $res = whatsapp_api_request(array_merge($waCfg, ['timeout_seconds' => 10]), 'POST', '/inbound/config', ['company_id' => $sid, 'enabled' => $enabled]);
      return ['ok' => !empty($res['ok']), 'message' => !empty($res['webhook_configured']) ? '' : 'O motor ainda não tem o endereço de retorno configurado.'];
    } catch (Throwable $e) {
      return ['ok' => false, 'message' => $e->getMessage()];
    }
  }
}

if (!function_exists('whatsapp_bot_save_config')) {
  function whatsapp_bot_save_config(PDO $pdo, int $companyId, array $input): array {
    $cfg = whatsapp_bot_config($pdo, $companyId);
    $cfg['enabled'] = !empty($input['enabled']) && whatsapp_bot_module_enabled($pdo, $companyId);
    $cfg['welcome'] = mb_substr(trim((string)($input['welcome'] ?? $cfg['welcome'])), 0, 1000) ?: whatsapp_bot_defaults()['welcome'];
    foreach (['opt_boleto','opt_pedido','opt_crm','opt_atendente'] as $k) $cfg[$k] = !empty($input[$k]);
    $cfg['pause_hours'] = max(1, min(72, (int)($input['pause_hours'] ?? 2)));
    $cfg['atendente_text'] = mb_substr(trim((string)($input['atendente_text'] ?? '')), 0, 1000) ?: whatsapp_bot_defaults()['atendente_text'];
    $cfg['crm_responsible_user_id'] = max(0, (int)($input['crm_responsible_user_id'] ?? 0));
    $cfg['crm_status_id'] = max(0, (int)($input['crm_status_id'] ?? 0));
    $cfg['crm_service_type_id'] = max(0, (int)($input['crm_service_type_id'] ?? 0));
    $auto = [];
    $labels = (array)($input['auto_label'] ?? []);
    $texts = (array)($input['auto_text'] ?? []);
    foreach ($labels as $i => $label) {
      $label = trim((string)$label);
      if ($label === '') continue;
      $auto[] = ['label' => mb_substr($label, 0, 60), 'text' => mb_substr(trim((string)($texts[$i] ?? '')), 0, 3000)];
    }
    $cfg['auto_messages'] = array_slice($auto, 0, 8);
    config_store_set($pdo, $companyId, 'whatsapp_bot', $cfg);
    return whatsapp_bot_sync_engine($pdo, $companyId, $cfg['enabled']);
  }
}

/** Empresa dona da sessão do motor (company_id da sessão = session_company_id). */
if (!function_exists('whatsapp_bot_company_by_session')) {
  function whatsapp_bot_company_by_session(PDO $pdo, string $sid): int {
    $sid = preg_replace('/\D+/', '', $sid) ?? '';
    if ($sid === '') return 0;
    $st = $pdo->prepare("SELECT company_id, cfg_json FROM app_config WHERE cfg_key='whatsapp' AND (company_id=? OR cfg_json LIKE ?)");
    $st->execute([(int)$sid, '%' . $sid . '%']);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $cfg = json_decode((string)$r['cfg_json'], true);
      if (!is_array($cfg)) $cfg = [];
      if (whatsapp_service_company_id($cfg, (int)$r['company_id']) === $sid) return (int)$r['company_id'];
    }
    return 0;
  }
}

if (!function_exists('whatsapp_bot_digits')) {
  function whatsapp_bot_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}

if (!function_exists('whatsapp_bot_valid_cpf')) {
  function whatsapp_bot_valid_cpf(string $c): bool {
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    for ($t = 9; $t < 11; $t++) {
      $d = 0;
      for ($i = 0; $i < $t; $i++) $d += (int)$c[$i] * (($t + 1) - $i);
      $d = ((10 * $d) % 11) % 10;
      if ((int)$c[$t] !== $d) return false;
    }
    return true;
  }
}

if (!function_exists('whatsapp_bot_valid_cnpj')) {
  function whatsapp_bot_valid_cnpj(string $c): bool {
    if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $len) {
      $weights = $len === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
      $sum = 0;
      for ($i = 0; $i < $len; $i++) $sum += (int)$c[$i] * $weights[$i];
      $r = $sum % 11;
      if ((int)$c[$len] !== ($r < 2 ? 0 : 11 - $r)) return false;
    }
    return true;
  }
}

if (!function_exists('whatsapp_bot_money')) {
  function whatsapp_bot_money($v): string { return 'R$ ' . number_format((float)$v, 2, ',', '.'); }
}

/** Itens do menu, na ordem exibida: [['key'=>..., 'label'=>..., 'text'=>?], ...] */
if (!function_exists('whatsapp_bot_menu_items')) {
  function whatsapp_bot_menu_items(array $cfg): array {
    $items = [];
    if ($cfg['opt_boleto']) $items[] = ['key' => 'boleto', 'label' => '2ª via de boleto'];
    if ($cfg['opt_pedido']) $items[] = ['key' => 'pedido', 'label' => 'Consultar pedido'];
    if ($cfg['opt_crm']) $items[] = ['key' => 'crm', 'label' => 'Abrir atendimento / suporte'];
    foreach ($cfg['auto_messages'] as $i => $m) $items[] = ['key' => 'auto', 'index' => $i, 'label' => $m['label'], 'text' => $m['text']];
    if ($cfg['opt_atendente']) $items[] = ['key' => 'atendente', 'label' => 'Falar com um atendente'];
    return $items;
  }
}

if (!function_exists('whatsapp_bot_menu_text')) {
  function whatsapp_bot_menu_text(array $cfg): string {
    $lines = [];
    foreach (whatsapp_bot_menu_items($cfg) as $n => $item) $lines[] = '*' . ($n + 1) . '* - ' . $item['label'];
    return implode("\n", $lines);
  }
}

if (!function_exists('whatsapp_bot_company_name')) {
  function whatsapp_bot_company_name(PDO $pdo, int $companyId): string {
    try {
      $st = $pdo->prepare('SELECT name FROM companies WHERE id=? LIMIT 1');
      $st->execute([$companyId]);
      return trim((string)$st->fetchColumn()) ?: 'empresa';
    } catch (Throwable $e) {
      return 'empresa';
    }
  }
}

if (!function_exists('whatsapp_bot_log')) {
  function whatsapp_bot_log(PDO $pdo, int $companyId, string $contact, string $direction, string $body, ?string $messageId = null): bool {
    try {
      $pdo->prepare('INSERT INTO whatsapp_bot_messages (company_id, contact, direction, message_id, body, created_at) VALUES (?,?,?,?,?,?)')
        ->execute([$companyId, $contact, $direction, $messageId !== '' ? $messageId : null, mb_substr($body, 0, 4000), date('Y-m-d H:i:s')]);
      return true;
    } catch (PDOException $e) {
      if ((string)$e->getCode() === '23000') return false; // mensagem repetida (o motor reenviou)
      throw $e;
    }
  }
}

/** Envia texto ao contato (pelo número quando houver; LID pelo jid). */
if (!function_exists('whatsapp_bot_send')) {
  function whatsapp_bot_send(PDO $pdo, int $companyId, array $ctx, string $text): bool {
    $payload = ['company_id' => $ctx['sid'], 'message' => $text];
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone'];
    if ($ctx['contact'] !== '') $payload['jid'] = $ctx['contact'];
    try {
      whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 40]), 'POST', '/send', $payload);
      whatsapp_bot_log($pdo, $companyId, $ctx['contact'], 'OUT', $text);
      return true;
    } catch (Throwable $e) {
      whatsapp_bot_log($pdo, $companyId, $ctx['contact'], 'OUT', '[falha no envio] ' . $e->getMessage() . "\n" . $text);
      return false;
    }
  }
}

if (!function_exists('whatsapp_bot_send_document')) {
  function whatsapp_bot_send_document(PDO $pdo, int $companyId, array $ctx, string $caption, string $filename, string $pdf): bool {
    $payload = ['company_id' => $ctx['sid'], 'caption' => $caption, 'filename' => $filename, 'mimetype' => 'application/pdf', 'file_base64' => base64_encode($pdf)];
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone'];
    if ($ctx['contact'] !== '') $payload['jid'] = $ctx['contact'];
    try {
      whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 60]), 'POST', '/send-document', $payload);
      whatsapp_bot_log($pdo, $companyId, $ctx['contact'], 'OUT', '[PDF ' . $filename . '] ' . $caption);
      return true;
    } catch (Throwable $e) {
      whatsapp_bot_log($pdo, $companyId, $ctx['contact'], 'OUT', '[falha no envio do PDF] ' . $e->getMessage());
      return false;
    }
  }
}

if (!function_exists('whatsapp_bot_session_save')) {
  function whatsapp_bot_session_save(PDO $pdo, int $companyId, array $s): void {
    $pdo->prepare('UPDATE whatsapp_bot_sessions SET phone=?, push_name=?, state=?, data_json=?, paused_until=?, doc_attempts=?, doc_attempts_since=?, last_message_at=? WHERE company_id=? AND id=?')
      ->execute([$s['phone'] ?: null, $s['push_name'] ?: null, $s['state'], json_encode($s['data'] ?? [], JSON_UNESCAPED_UNICODE), $s['paused_until'] ?: null, (int)$s['doc_attempts'], $s['doc_attempts_since'] ?: null, date('Y-m-d H:i:s'), $companyId, (int)$s['id']]);
  }
}

if (!function_exists('whatsapp_bot_show_menu')) {
  function whatsapp_bot_show_menu(PDO $pdo, int $companyId, array $ctx, array $cfg, array &$s, bool $welcome): void {
    $text = '';
    if ($welcome) {
      $name = trim((string)$ctx['push_name']);
      $text = strtr((string)$cfg['welcome'], ['{nome}' => $name !== '' ? ', ' . $name : '', '{empresa}' => whatsapp_bot_company_name($pdo, $companyId)]) . "\n\n";
    } else {
      $text = "Escolha uma opção (responda com o número):\n\n";
    }
    $text .= whatsapp_bot_menu_text($cfg);
    whatsapp_bot_send($pdo, $companyId, $ctx, $text);
    $s['state'] = 'MENU';
    $s['data'] = [];
  }
}

/** 2ª via: boletos em aberto do CPF/CNPJ informado. */
if (!function_exists('whatsapp_bot_send_boletos')) {
  function whatsapp_bot_send_boletos(PDO $pdo, int $companyId, array $ctx, string $doc): void {
    require_once __DIR__ . '/billing_module.php';
    require_once __DIR__ . '/boleto_document.php';
    $st = $pdo->prepare("SELECT r.id, r.due_date, r.open_amount, r.amount FROM finance_receivables r
      JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id
      WHERE r.company_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.document,''),'.',''),'-',''),'/',''),' ','')=?
        AND UPPER(COALESCE(r.status,'')) IN ('ABERTO','PARCIAL') AND COALESCE(r.open_amount,0) > 0.009
      ORDER BY r.due_date, r.id LIMIT 15");
    $st->execute([$companyId, $doc]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if (!$rows) {
      whatsapp_bot_send($pdo, $companyId, $ctx, "Não encontrei boletos em aberto para este CPF/CNPJ. ✅\n\nDigite *menu* para ver as outras opções.");
      return;
    }
    $sent = 0; $withoutBoleto = [];
    foreach ($rows as $r) {
      if ($sent >= 5) break;
      $charge = billing_receivable_charge($pdo, $companyId, (int)$r['id']);
      $status = strtoupper((string)($charge['status'] ?? ''));
      if (!$charge || in_array($status, ['CANCELADO','PAGO','BAIXADO','ERRO','PENDENTE_REGISTRO'], true)) { $withoutBoleto[] = $r; continue; }
      try {
        $data = boleto_document_load($pdo, $companyId, (int)$charge['id'], (int)$r['id']);
        if (!boleto_document_ready($data)) { $withoutBoleto[] = $r; continue; }
        $pdf = boleto_document_pdf($data);
        $caption = "Boleto - vencimento " . boleto_document_date($data['due_date'] ?? '') . " - " . boleto_document_money($data['amount'] ?? 0);
        $line = trim((string)($data['linha_digitavel'] ?? ''));
        if ($line !== '') $caption .= "\nLinha digitável: " . $line;
        if (whatsapp_bot_send_document($pdo, $companyId, $ctx, $caption, 'boleto-' . (int)$r['id'] . '.pdf', $pdf)) $sent++;
        $pix = trim((string)($data['pix_emv'] ?? ''));
        if ($pix !== '') whatsapp_bot_send($pdo, $companyId, $ctx, "Pix copia e cola deste boleto:\n" . $pix);
      } catch (Throwable $e) {
        $withoutBoleto[] = $r;
      }
    }
    $msg = $sent > 0 ? "Pronto! Enviei " . $sent . " boleto(s) em aberto." : '';
    if ($withoutBoleto) {
      $lines = array_map(static fn($r) => '• Venc. ' . date('d/m/Y', strtotime((string)$r['due_date'])) . ' - ' . whatsapp_bot_money($r['open_amount']), array_slice($withoutBoleto, 0, 5));
      $msg .= ($msg !== '' ? "\n\n" : '') . "Estas contas em aberto não têm boleto disponível para envio automático:\n" . implode("\n", $lines) . "\nSe precisar, escolha a opção de falar com um atendente.";
    }
    whatsapp_bot_send($pdo, $companyId, $ctx, $msg . "\n\nDigite *menu* para ver as outras opções.");
  }
}

if (!function_exists('whatsapp_bot_order_status')) {
  function whatsapp_bot_order_status(PDO $pdo, int $companyId, array $ctx, string $number): bool {
    $n = (int)$number;
    if ($n <= 0) return false;
    $col = null;
    if (is_file(__DIR__ . '/company_sequences.php')) {
      require_once __DIR__ . '/company_sequences.php';
      if (function_exists('company_sequence_column_for')) $col = company_sequence_column_for('pedidos');
      if ($col && function_exists('company_sequence_column_exists') && !company_sequence_column_exists($pdo, 'pedidos', $col)) $col = null;
    }
    $order = null;
    if ($col && preg_match('/^[a-z_]+$/', $col)) {
      $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND `' . $col . '`=? LIMIT 1');
      $st->execute([$companyId, $n]);
      $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$order) {
      $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
      $st->execute([$companyId, $n]);
      $order = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$order) return false;
    $status = strtolower(trim((string)($order['status'] ?? '')));
    $label = whatsapp_status_label($status);
    $date = !empty($order['created_at']) ? date('d/m/Y', strtotime((string)$order['created_at'])) : '';
    $text = "Pedido nº *" . $n . "*" . ($date !== '' ? " (" . $date . ")" : '') . "\nSituação: *" . $label . "*";
    whatsapp_bot_send($pdo, $companyId, $ctx, $text . "\n\nDigite *menu* para ver as outras opções.");
    return true;
  }
}

if (!function_exists('whatsapp_bot_open_crm')) {
  function whatsapp_bot_open_crm(PDO $pdo, int $companyId, array $ctx, array $cfg, string $request): int {
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('crm_attendances','crm_attendance_moves')");
    if ((int)$st->fetchColumn() !== 2) throw new RuntimeException('CRM não instalado.');
    $responsible = (int)$cfg['crm_responsible_user_id'];
    if ($responsible > 0) {
      $q = $pdo->prepare('SELECT id FROM users WHERE company_id=? AND id=? AND is_active=1 LIMIT 1');
      $q->execute([$companyId, $responsible]);
      $responsible = (int)$q->fetchColumn();
    }
    if ($responsible <= 0) {
      $q = $pdo->prepare("SELECT id FROM users WHERE company_id=? AND is_active=1 ORDER BY (role='company_admin') DESC, id LIMIT 1");
      $q->execute([$companyId]);
      $responsible = (int)$q->fetchColumn();
    }
    if ($responsible <= 0) throw new RuntimeException('Nenhum usuário ativo para responsável.');
    $statusId = (int)$cfg['crm_status_id'];
    if ($statusId <= 0) { $q = $pdo->prepare('SELECT id FROM crm_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 1'); $q->execute([$companyId]); $statusId = (int)$q->fetchColumn(); }
    $typeId = (int)$cfg['crm_service_type_id'];
    if ($typeId <= 0) { $q = $pdo->prepare('SELECT id FROM crm_service_types WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 1'); $q->execute([$companyId]); $typeId = (int)$q->fetchColumn(); }

    // Cliente: procura no cadastro pelo telefone do WhatsApp.
    $personId = null; $name = trim((string)$ctx['push_name']); $doc = null;
    $phone = $ctx['phone'];
    if ($phone !== '') {
      $local = strlen($phone) > 11 && str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
      $q = $pdo->prepare("SELECT id, name, document FROM finance_people WHERE company_id=? AND (REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),'(',''),')',''),'-',''),' ',''),'+','') IN (?,?) OR REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(phone,''),'(',''),')',''),'-',''),' ',''),'+','') IN (?,?)) ORDER BY id LIMIT 1");
      $q->execute([$companyId, $phone, $local, $phone, $local]);
      if ($p = $q->fetch(PDO::FETCH_ASSOC)) { $personId = (int)$p['id']; $name = (string)$p['name'] ?: $name; $doc = (string)$p['document'] ?: null; }
    }
    if ($name === '') $name = 'Contato WhatsApp';
    $text = "Aberto pelo chatbot do WhatsApp.\n" . $request;
    $next = (new DateTimeImmutable('today'))->format('Y-m-d');
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO crm_attendances(company_id,person_id,customer_name,phone,document,email,address,status_id,service_type_id,request_text,lead_temperature,state,pending_reason,next_contact_date,responsible_user_id,created_by,closed_by,closed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW(),NOW())')
        ->execute([$companyId, $personId, mb_substr($name, 0, 180), $phone !== '' ? $phone : null, $doc, null, null, $statusId ?: null, $typeId ?: null, $text, 50, 'PENDENTE', 'Aguardando retorno ao cliente.', $next, $responsible, null]);
      $id = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$companyId, $id, 'Atendimento aberto pelo cliente no chatbot do WhatsApp.', 50, 'PENDENTE', 'Aguardando retorno ao cliente.', $next, $responsible, null]);
      $pdo->commit();
      return $id;
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('whatsapp_bot_normalize')) {
  function whatsapp_bot_normalize(string $text): string {
    $t = mb_strtolower(trim($text), 'UTF-8');
    return strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
  }
}

/**
 * Alguém da empresa respondeu manualmente (celular/WhatsApp Web): o robô fica
 * em silêncio nessa conversa pelo tempo configurado (o cliente pode digitar
 * "menu" para voltar ao atendimento automático).
 */
if (!function_exists('whatsapp_bot_human_reply')) {
  function whatsapp_bot_human_reply(PDO $pdo, array $payload): string {
    if (!whatsapp_bot_ready($pdo)) return 'tabelas ausentes';
    $companyId = whatsapp_bot_company_by_session($pdo, (string)($payload['company_id'] ?? ''));
    if ($companyId <= 0) return 'empresa não encontrada';
    if (!whatsapp_bot_module_enabled($pdo, $companyId)) return 'módulo não liberado';
    $cfg = whatsapp_bot_config($pdo, $companyId);
    if (!$cfg['enabled']) return 'chatbot desligado';
    $contact = substr(trim((string)($payload['reply_jid'] ?? '')), 0, 80);
    $phone = whatsapp_bot_digits($payload['from'] ?? '');
    if ($contact === '' && $phone !== '') $contact = $phone . '@s.whatsapp.net';
    if ($contact === '') return 'sem contato';
    $until = date('Y-m-d H:i:s', time() + (int)$cfg['pause_hours'] * 3600);
    $pdo->prepare("INSERT INTO whatsapp_bot_sessions (company_id, contact, phone, state, paused_until, last_message_at, created_at) VALUES (?,?,?,'ATENDENTE',?,?,?)
      ON DUPLICATE KEY UPDATE state='ATENDENTE', paused_until=VALUES(paused_until), phone=COALESCE(VALUES(phone), phone)")
      ->execute([$companyId, $contact, $phone !== '' ? $phone : null, $until, date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    return 'pausado';
  }
}

/**
 * Processa uma mensagem recebida (payload do motor).
 * Retorna uma descrição curta do que foi feito (para log/teste).
 */
if (!function_exists('whatsapp_bot_handle')) {
  function whatsapp_bot_handle(PDO $pdo, array $payload): string {
    if (!whatsapp_bot_ready($pdo)) return 'tabelas ausentes';
    $sid = preg_replace('/\D+/', '', (string)($payload['company_id'] ?? '')) ?? '';
    $companyId = whatsapp_bot_company_by_session($pdo, $sid);
    if ($companyId <= 0) return 'empresa não encontrada';
    if (!whatsapp_bot_module_enabled($pdo, $companyId)) return 'módulo não liberado';
    $cfg = whatsapp_bot_config($pdo, $companyId);
    if (!$cfg['enabled']) return 'chatbot desligado';

    $contact = substr(trim((string)($payload['reply_jid'] ?? '')), 0, 80);
    $phone = whatsapp_bot_digits($payload['from'] ?? '');
    if ($contact === '' && $phone !== '') $contact = $phone . '@s.whatsapp.net';
    if ($contact === '') return 'sem contato';
    $text = trim((string)($payload['text'] ?? ''));
    $type = (string)($payload['type'] ?? 'text');
    $messageId = substr((string)($payload['message_id'] ?? ''), 0, 80);
    if (!whatsapp_bot_log($pdo, $companyId, $contact, 'IN', ($type !== 'text' ? '[' . $type . '] ' : '') . $text, $messageId)) return 'repetida';

    $lock = 'nexa_wa_bot_' . $companyId . '_' . md5($contact);
    $st = $pdo->prepare('SELECT GET_LOCK(?,15)');
    $st->execute([$lock]);
    $st->fetchColumn();
    $st->closeCursor();
    try {
      $pdo->prepare('INSERT IGNORE INTO whatsapp_bot_sessions (company_id, contact, state, created_at) VALUES (?,?,?,?)')->execute([$companyId, $contact, 'NOVA', date('Y-m-d H:i:s')]);
      $st = $pdo->prepare('SELECT * FROM whatsapp_bot_sessions WHERE company_id=? AND contact=? LIMIT 1');
      $st->execute([$companyId, $contact]);
      $s = $st->fetch(PDO::FETCH_ASSOC);
      $s['data'] = json_decode((string)($s['data_json'] ?? ''), true) ?: [];
      if ($phone !== '') $s['phone'] = $phone;
      $pushName = trim((string)($payload['push_name'] ?? ''));
      if ($pushName !== '') $s['push_name'] = mb_substr($pushName, 0, 120);
      $ctx = [
        'sid' => $sid, 'contact' => $contact, 'phone' => $phone !== '' ? $phone : whatsapp_bot_digits($s['phone'] ?? ''),
        'push_name' => (string)($s['push_name'] ?? ''), 'wa' => whatsapp_load_config($pdo, $companyId, false),
      ];
      $now = time();
      $norm = whatsapp_bot_normalize($text);
      $isMenuWord = in_array($norm, ['menu','inicio','voltar','0','oi','ola','bom dia','boa tarde','boa noite'], true);
      $result = '';

      // Em atendimento humano: robô em silêncio até o prazo (ou até o cliente pedir o menu).
      $paused = !empty($s['paused_until']) && strtotime((string)$s['paused_until']) > $now;
      if ($paused && !in_array($norm, ['menu','inicio'], true)) {
        whatsapp_bot_session_save($pdo, $companyId, $s);
        return 'em atendimento humano';
      }
      if ($paused) $s['paused_until'] = null;

      $expired = empty($s['last_message_at']) || strtotime((string)$s['last_message_at']) < $now - WHATSAPP_BOT_SESSION_MINUTES * 60
        || in_array($s['state'], ['NOVA','FIM','ATENDENTE'], true);
      if ($expired) {
        whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, $s, true);
        whatsapp_bot_session_save($pdo, $companyId, $s);
        return 'boas-vindas';
      }
      if ($isMenuWord) {
        whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, $s, false);
        whatsapp_bot_session_save($pdo, $companyId, $s);
        return 'menu';
      }
      if (in_array($norm, ['sair','encerrar','tchau','obrigado','obrigada'], true)) {
        whatsapp_bot_send($pdo, $companyId, $ctx, "Obrigado pelo contato! Quando precisar, é só mandar uma mensagem. 👋");
        $s['state'] = 'FIM';
        whatsapp_bot_session_save($pdo, $companyId, $s);
        return 'encerrado';
      }
      if ($type !== 'text' && $type !== 'button' && $type !== 'list' && $s['state'] !== 'ASK_CRM') {
        whatsapp_bot_send($pdo, $companyId, $ctx, "Por enquanto consigo entender só mensagens de texto. 🙂\nDigite *menu* para ver as opções.");
        whatsapp_bot_session_save($pdo, $companyId, $s);
        return 'mídia';
      }

      switch ($s['state']) {
        case 'ASK_DOC':
          $doc = whatsapp_bot_digits($text);
          if (!whatsapp_bot_valid_cpf($doc) && !whatsapp_bot_valid_cnpj($doc)) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "CPF/CNPJ inválido. Digite novamente só os números, ou *menu* para voltar.");
            $result = 'documento inválido';
            break;
          }
          $since = !empty($s['doc_attempts_since']) ? strtotime((string)$s['doc_attempts_since']) : 0;
          if ($since < $now - 3600) { $s['doc_attempts'] = 0; $s['doc_attempts_since'] = date('Y-m-d H:i:s'); }
          $s['doc_attempts'] = (int)$s['doc_attempts'] + 1;
          if ($s['doc_attempts'] > WHATSAPP_BOT_DOC_ATTEMPTS_PER_HOUR) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Muitas consultas seguidas. Tente novamente mais tarde ou escolha falar com um atendente. Digite *menu*.");
            $s['state'] = 'MENU';
            $result = 'limite de consultas';
            break;
          }
          whatsapp_bot_send($pdo, $companyId, $ctx, "Um momento, estou buscando os boletos... ⏳");
          whatsapp_bot_send_boletos($pdo, $companyId, $ctx, $doc);
          $s['state'] = 'MENU';
          $result = 'boletos';
          break;

        case 'ASK_ORDER':
          $num = whatsapp_bot_digits($text);
          if ($num === '' || !whatsapp_bot_order_status($pdo, $companyId, $ctx, $num)) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Não encontrei esse pedido. Confira o número e envie de novo, ou digite *menu* para voltar.");
            $result = 'pedido não encontrado';
            break;
          }
          $s['state'] = 'MENU';
          $result = 'pedido';
          break;

        case 'ASK_CRM':
          if (mb_strlen($text) < 5) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Descreva em uma mensagem o que você precisa (pelo menos algumas palavras).");
            $result = 'texto curto';
            break;
          }
          try {
            $protocol = whatsapp_bot_open_crm($pdo, $companyId, $ctx, $cfg, $text);
            whatsapp_bot_send($pdo, $companyId, $ctx, "Atendimento aberto! ✅\nSeu protocolo é o nº *" . $protocol . "*.\nNossa equipe vai retornar em breve.\n\nDigite *menu* para ver as outras opções.");
            $result = 'protocolo ' . $protocol;
          } catch (Throwable $e) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Não consegui abrir o atendimento agora. Um atendente vai falar com você por aqui.");
            $result = 'erro crm: ' . $e->getMessage();
          }
          $s['state'] = 'MENU';
          break;

        default: // MENU
          $items = whatsapp_bot_menu_items($cfg);
          $choice = (int)whatsapp_bot_digits($text);
          $item = ($choice >= 1 && $choice <= count($items) && whatsapp_bot_digits($text) === (string)$choice) ? $items[$choice - 1] : null;
          if (!$item) {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Não entendi. 🤔 Responda com o *número* de uma das opções:\n\n" . whatsapp_bot_menu_text($cfg));
            $result = 'opção inválida';
            break;
          }
          if ($item['key'] === 'boleto') {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Para enviar a 2ª via, informe o *CPF ou CNPJ* do titular (só os números).");
            $s['state'] = 'ASK_DOC';
          } elseif ($item['key'] === 'pedido') {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Informe o *número do pedido*.");
            $s['state'] = 'ASK_ORDER';
          } elseif ($item['key'] === 'crm') {
            whatsapp_bot_send($pdo, $companyId, $ctx, "Descreva em *uma mensagem* o que você precisa, que eu abro um atendimento para a nossa equipe.");
            $s['state'] = 'ASK_CRM';
          } elseif ($item['key'] === 'auto') {
            $autoText = trim((string)$item['text']);
            whatsapp_bot_send($pdo, $companyId, $ctx, ($autoText !== '' ? $autoText : 'Em breve teremos mais informações sobre ' . $item['label'] . '.') . "\n\nDigite *menu* para ver as outras opções.");
          } elseif ($item['key'] === 'atendente') {
            whatsapp_bot_send($pdo, $companyId, $ctx, (string)$cfg['atendente_text']);
            $s['paused_until'] = date('Y-m-d H:i:s', $now + (int)$cfg['pause_hours'] * 3600);
            $s['state'] = 'ATENDENTE';
          }
          $result = 'opção ' . $item['key'];
      }
      whatsapp_bot_session_save($pdo, $companyId, $s);
      return $result;
    } finally {
      try { $r = $pdo->prepare('SELECT RELEASE_LOCK(?)'); $r->execute([$lock]); $r->fetchColumn(); $r->closeCursor(); } catch (Throwable $e) {}
    }
  }
}
