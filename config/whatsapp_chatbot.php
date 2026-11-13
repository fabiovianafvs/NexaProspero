<?php
declare(strict_types=1);

/**
 * Chatbot e conversas do WhatsApp (motor leve).
 *
 * O motor entrega cada mensagem em webhooks/whatsapp_entrada.php. Cada contato
 * tem UMA conversa (tabela whatsapp_bot_sessions) que passa pelos status:
 *   BOT         -> o robô conduz o fluxo (árvore de menus em whatsapp_bot_nodes)
 *   PENDENTE    -> transferida para um setor, aguardando um atendente aceitar
 *   ATENDIMENTO -> com um atendente (tela WhatsApp > Atendimento) ou alguém que
 *                  respondeu pelo celular
 *   FINALIZADO  -> encerrada; a próxima mensagem do cliente recomeça no robô
 * Todas as mensagens (cliente, robô, atendente, celular) ficam em
 * whatsapp_bot_messages, com arquivos guardados fora da pasta pública.
 *
 * Só funciona na empresa com o módulo do SaaS "whatsapp_chatbot" liberado.
 */

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/whatsapp_service.php';
require_once __DIR__ . '/whatsapp_engine.php';
require_once __DIR__ . '/whatsapp_bot_flows.php';

const WHATSAPP_BOT_SESSION_MINUTES = 30;
const WHATSAPP_BOT_DOC_ATTEMPTS_PER_HOUR = 5;
const WHATSAPP_MEDIA_MAX_BYTES = 16 * 1024 * 1024;

/* ------------------------------------------------------------------------- */
/* Estrutura, módulo e configuração                                           */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_bot_ready')) {
  /** Migrações 20261109_whatsapp_chatbot_v1 e 20261110_whatsapp_atendimento_v1 aplicadas? */
  function whatsapp_bot_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('whatsapp_bot_sessions','whatsapp_bot_messages','whatsapp_bot_nodes','whatsapp_departments','whatsapp_department_users')");
      if ((int)$st->fetchColumn() !== 5) return $ready = false;
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name='whatsapp_bot_sessions' AND column_name='status') OR (table_name='whatsapp_bot_messages' AND column_name='session_id'))");
      return $ready = ((int)$st->fetchColumn() === 2);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('whatsapp_bot_module_enabled')) {
  /** Módulo comercial liberado pela plataforma (SaaS) para a empresa? */
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
      'queue_text' => "Certo! Encaminhei sua conversa para *{setor}*. Em instantes um atendente vai falar com você por aqui. 🙂",
      'accept_text' => "Olá! Meu nome é *{atendente}* e vou continuar seu atendimento. 🙂",
      'close_text' => "Atendimento finalizado. Obrigado pelo contato! 👋\nSe precisar, é só mandar uma nova mensagem.",
      'sign_messages' => true,
      'pause_hours' => 2,
      'crm_responsible_user_id' => 0,
      'crm_status_id' => 0,
      'crm_service_type_id' => 0,
      // 2ª via: consultar também as outras empresas do mesmo Grupo Empresarial (pelo CPF/CNPJ).
      'group_receivables' => false,
      // Envios (boletos, pedidos, O.S., notas...) das outras empresas do grupo saem pelo WhatsApp desta empresa.
      'group_whatsapp_shared' => false,
      // Horário de atendimento (dia da semana 0=domingo ... 6=sábado; até 2 períodos por dia).
      'hours_enabled' => false,
      'hours' => whatsapp_bot_hours_default(),
      'holidays' => '',
      'offhours_text' => "Olá{nome}! No momento estamos *fora do horário de atendimento*.\n\nNosso horário:\n{horarios}\n\nSua mensagem fica registrada e respondemos assim que possível. 🙂",
      'offhours_queue_text' => "Certo! Registrei sua solicitação para *{setor}*. Como estamos fora do horário, um atendente vai responder no próximo horário de atendimento. 🙂",
      'offhours_menu' => true,
    ];
  }
}

if (!function_exists('whatsapp_bot_hours_default')) {
  function whatsapp_bot_hours_default(): array {
    $week = ['on' => 1, 'a' => '08:00', 'b' => '18:00', 'c' => '', 'd' => ''];
    return [
      0 => ['on' => 0, 'a' => '', 'b' => '', 'c' => '', 'd' => ''],
      1 => $week, 2 => $week, 3 => $week, 4 => $week, 5 => $week,
      6 => ['on' => 1, 'a' => '08:00', 'b' => '12:00', 'c' => '', 'd' => ''],
    ];
  }
}

if (!function_exists('whatsapp_bot_hours_norm')) {
  /** Horários válidos (HH:MM) por dia da semana. */
  function whatsapp_bot_hours_norm($hours): array {
    $hm = static function ($v): string {
      $v = trim((string)$v);
      if (!preg_match('/^(\d{1,2}):(\d{2})$/', $v, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) return '';
      return sprintf('%02d:%02d', (int)$m[1], (int)$m[2]);
    };
    $out = [];
    $src = is_array($hours) ? $hours : [];
    for ($d = 0; $d <= 6; $d++) {
      $r = is_array($src[$d] ?? null) ? $src[$d] : (is_array($src[(string)$d] ?? null) ? $src[(string)$d] : []);
      $out[$d] = ['on' => !empty($r['on']) ? 1 : 0, 'a' => $hm($r['a'] ?? ''), 'b' => $hm($r['b'] ?? ''), 'c' => $hm($r['c'] ?? ''), 'd' => $hm($r['d'] ?? '')];
    }
    return $out;
  }
}

if (!function_exists('whatsapp_bot_day_names')) {
  function whatsapp_bot_day_names(): array {
    return ['Domingo', 'Segunda-feira', 'Terça-feira', 'Quarta-feira', 'Quinta-feira', 'Sexta-feira', 'Sábado'];
  }
}

if (!function_exists('whatsapp_bot_hours_day_label')) {
  /** "08:00 às 12:00 e 13:30 às 18:00" ou "fechado". */
  function whatsapp_bot_hours_day_label(array $r): string {
    if (empty($r['on'])) return 'fechado';
    $parts = [];
    if ($r['a'] !== '' && $r['b'] !== '') $parts[] = $r['a'] . ' às ' . $r['b'];
    if ($r['c'] !== '' && $r['d'] !== '') $parts[] = $r['c'] . ' às ' . $r['d'];
    return $parts ? implode(' e ', $parts) : 'fechado';
  }
}

if (!function_exists('whatsapp_bot_hours_text')) {
  /** Horário para a mensagem ao cliente, juntando dias seguidos iguais (ex.: "Segunda a sexta: 08:00 às 18:00"). */
  function whatsapp_bot_hours_text(array $cfg): string {
    $h = whatsapp_bot_hours_norm($cfg['hours'] ?? []);
    $short = ['domingo', 'segunda', 'terça', 'quarta', 'quinta', 'sexta', 'sábado'];
    $order = [1, 2, 3, 4, 5, 6, 0];
    $lines = [];
    $i = 0;
    while ($i < 7) {
      $label = whatsapp_bot_hours_day_label($h[$order[$i]]);
      $j = $i;
      while ($j + 1 < 7 && whatsapp_bot_hours_day_label($h[$order[$j + 1]]) === $label) $j++;
      $days = $i === $j ? $short[$order[$i]] : $short[$order[$i]] . ($j === $i + 1 ? ' e ' : ' a ') . $short[$order[$j]];
      $lines[] = '• ' . mb_strtoupper(mb_substr($days, 0, 1)) . mb_substr($days, 1) . ': ' . $label;
      $i = $j + 1;
    }
    return implode("\n", $lines);
  }
}

if (!function_exists('whatsapp_bot_hours_open')) {
  /** Dentro do horário de atendimento? (sem controle de horário ligado: sempre aberto). */
  function whatsapp_bot_hours_open(array $cfg, ?int $ts = null): bool {
    if (empty($cfg['hours_enabled'])) return true;
    $ts = $ts ?? time();
    // Feriados: dd/mm (todo ano) ou dd/mm/aaaa, um por linha.
    foreach (preg_split('/[\r\n,;]+/', (string)($cfg['holidays'] ?? '')) ?: [] as $hd) {
      $hd = trim($hd);
      if ($hd === '') continue;
      if ($hd === date('d/m', $ts) || $hd === date('d/m/Y', $ts) || $hd === date('j/n', $ts)) return false;
    }
    $h = whatsapp_bot_hours_norm($cfg['hours'] ?? [])[(int)date('w', $ts)];
    if (empty($h['on'])) return false;
    $now = date('H:i', $ts);
    foreach ([['a', 'b'], ['c', 'd']] as [$x, $y]) {
      if ($h[$x] !== '' && $h[$y] !== '' && $now >= $h[$x] && $now < $h[$y]) return true;
    }
    return false;
  }
}

if (!function_exists('whatsapp_bot_offhours_message')) {
  function whatsapp_bot_offhours_message(PDO $pdo, int $companyId, array $cfg, string $pushName): string {
    $name = trim($pushName);
    return strtr((string)$cfg['offhours_text'], [
      '{nome}' => $name !== '' ? ', ' . $name : '',
      '{empresa}' => whatsapp_bot_company_name($pdo, $companyId),
      '{horarios}' => whatsapp_bot_hours_text($cfg),
    ]);
  }
}

if (!function_exists('whatsapp_bot_config')) {
  function whatsapp_bot_config(PDO $pdo, int $companyId): array {
    $cfg = whatsapp_bot_defaults();
    try {
      $saved = config_store_get($pdo, $companyId, 'whatsapp_bot');
      if (is_array($saved)) $cfg = array_merge($cfg, array_intersect_key($saved, $cfg));
    } catch (Throwable $e) {}
    $cfg['enabled'] = !empty($cfg['enabled']);
    $cfg['sign_messages'] = !empty($cfg['sign_messages']);
    $cfg['group_receivables'] = !empty($cfg['group_receivables']);
    $cfg['group_whatsapp_shared'] = !empty($cfg['group_whatsapp_shared']);
    $cfg['hours_enabled'] = !empty($cfg['hours_enabled']);
    $cfg['offhours_menu'] = !empty($cfg['offhours_menu']);
    $cfg['hours'] = whatsapp_bot_hours_norm($cfg['hours']);
    $cfg['pause_hours'] = max(1, min(72, (int)$cfg['pause_hours']));
    foreach (['welcome', 'queue_text', 'accept_text', 'close_text', 'holidays', 'offhours_text', 'offhours_queue_text'] as $k) $cfg[$k] = (string)$cfg[$k];
    return $cfg;
  }
}

if (!function_exists('whatsapp_bot_sync_engine')) {
  /** Liga/desliga a entrega das mensagens recebidas (motor -> sistema) desta empresa. */
  function whatsapp_bot_sync_engine(PDO $pdo, int $companyId, bool $enabled): array {
    $waCfg = whatsapp_load_config($pdo, $companyId, false, true);
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
    $def = whatsapp_bot_defaults();
    $module = whatsapp_bot_module_enabled($pdo, $companyId);
    // section = 'robo' (robô e mensagens) ou 'crm': grava só aquela parte da tela.
    $section = (string)($input['section'] ?? '');
    if ($section === 'horario') {
      // Horário de atendimento: só os campos desta parte.
      $cfg['hours_enabled'] = !empty($input['hours_enabled']);
      $cfg['hours'] = whatsapp_bot_hours_norm($input['hours'] ?? []);
      $cfg['holidays'] = mb_substr(trim((string)($input['holidays'] ?? '')), 0, 2000);
      $cfg['offhours_text'] = mb_substr(trim((string)($input['offhours_text'] ?? '')), 0, 1000) ?: $def['offhours_text'];
      $cfg['offhours_queue_text'] = mb_substr(trim((string)($input['offhours_queue_text'] ?? '')), 0, 1000) ?: $def['offhours_queue_text'];
      $cfg['offhours_menu'] = !empty($input['offhours_menu']);
      if ($cfg['hours_enabled']) {
        $any = false;
        foreach ($cfg['hours'] as $h) if (whatsapp_bot_hours_day_label($h) !== 'fechado') $any = true;
        if (!$any) throw new RuntimeException('Informe o horário de pelo menos um dia (ex.: 08:00 às 18:00).');
      }
      $cfg['enabled'] = !empty($cfg['enabled']) && $module;
      config_store_set($pdo, $companyId, 'whatsapp_bot', $cfg);
      return ['ok' => true, 'message' => ''];
    }
    if ($section !== 'crm') {
      $cfg['enabled'] = !empty($input['enabled']) && $module;
      foreach (['welcome' => 1000, 'queue_text' => 1000, 'close_text' => 1000] as $k => $max) {
        $cfg[$k] = mb_substr(trim((string)($input[$k] ?? $cfg[$k])), 0, $max) ?: $def[$k];
      }
      // Mensagem ao aceitar pode ficar vazia (não envia nada).
      $cfg['accept_text'] = mb_substr(trim((string)($input['accept_text'] ?? $cfg['accept_text'])), 0, 1000);
      $cfg['sign_messages'] = !empty($input['sign_messages']);
      $cfg['group_receivables'] = !empty($input['group_receivables']);
      $cfg['group_whatsapp_shared'] = !empty($input['group_whatsapp_shared']);
      $cfg['pause_hours'] = max(1, min(72, (int)($input['pause_hours'] ?? 2)));
    } else {
      $cfg['enabled'] = !empty($cfg['enabled']) && $module;
    }
    if ($section !== 'robo') {
      $cfg['crm_responsible_user_id'] = max(0, (int)($input['crm_responsible_user_id'] ?? 0));
      $cfg['crm_status_id'] = max(0, (int)($input['crm_status_id'] ?? 0));
      $cfg['crm_service_type_id'] = max(0, (int)($input['crm_service_type_id'] ?? 0));
    }
    config_store_set($pdo, $companyId, 'whatsapp_bot', $cfg);
    // As mensagens recebidas chegam ao sistema sempre que o módulo está liberado
    // (mesmo com o robô desligado, a tela de Atendimento continua funcionando).
    return whatsapp_bot_sync_engine($pdo, $companyId, $module);
  }
}

/* ------------------------------------------------------------------------- */
/* Mensagens rápidas do Atendimento (o atendente digita "/" ou clica no ⚡)   */
/* Ficam em app_config, chave whatsapp_quick_replies (sem tabela própria).    */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_quick_replies')) {
  /** Lista na ordem cadastrada: id, shortcut, title, text, active. */
  function whatsapp_quick_replies(PDO $pdo, int $companyId, bool $activeOnly = false): array {
    $data = config_store_get($pdo, $companyId, 'whatsapp_quick_replies') ?? [];
    $out = [];
    foreach ((array)($data['items'] ?? []) as $it) {
      if (!is_array($it) || (int)($it['id'] ?? 0) <= 0) continue;
      $row = [
        'id' => (int)$it['id'],
        'shortcut' => (string)($it['shortcut'] ?? ''),
        'title' => (string)($it['title'] ?? ''),
        'text' => (string)($it['text'] ?? ''),
        'active' => !empty($it['active']),
      ];
      if ($activeOnly && !$row['active']) continue;
      $out[] = $row;
    }
    return $out;
  }
}

if (!function_exists('whatsapp_quick_reply_shortcut')) {
  /** Atalho só com letras, números, - e _ (sem acento e sem a barra). */
  function whatsapp_quick_reply_shortcut(string $v): string {
    $v = trim(ltrim(trim($v), '/'));
    if (function_exists('iconv')) { $t = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $v); if (is_string($t)) $v = $t; }
    $v = strtolower(preg_replace('/[^A-Za-z0-9_-]+/', '', str_replace(' ', '-', $v)) ?? '');
    return substr($v, 0, 30);
  }
}

if (!function_exists('whatsapp_quick_replies_store')) {
  function whatsapp_quick_replies_store(PDO $pdo, int $companyId, array $items, ?int $nextId = null): void {
    $data = config_store_get($pdo, $companyId, 'whatsapp_quick_replies') ?? [];
    $next = $nextId ?? (int)($data['next_id'] ?? 1);
    config_store_set($pdo, $companyId, 'whatsapp_quick_replies', ['items' => array_values($items), 'next_id' => max(1, $next)]);
  }
}

if (!function_exists('whatsapp_quick_reply_save')) {
  function whatsapp_quick_reply_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $shortcut = whatsapp_quick_reply_shortcut((string)($in['shortcut'] ?? ''));
    $title = mb_substr(trim((string)($in['title'] ?? '')), 0, 80);
    $text = mb_substr(trim(str_replace("\r\n", "\n", (string)($in['text'] ?? ''))), 0, 3000);
    if ($shortcut === '') throw new RuntimeException('Informe o atalho (ex.: pix, horario, endereco).');
    if ($text === '') throw new RuntimeException('Escreva a mensagem.');
    if ($title === '') $title = $shortcut;
    $items = whatsapp_quick_replies($pdo, $companyId);
    foreach ($items as $it) {
      if ($it['shortcut'] === $shortcut && $it['id'] !== $id) throw new RuntimeException('Já existe uma mensagem com o atalho /' . $shortcut . '.');
    }
    if (count($items) >= 300 && $id <= 0) throw new RuntimeException('Limite de 300 mensagens rápidas.');
    $data = config_store_get($pdo, $companyId, 'whatsapp_quick_replies') ?? [];
    $next = max((int)($data['next_id'] ?? 1), 1);
    foreach ($items as $it) $next = max($next, $it['id'] + 1);
    $row = ['shortcut' => $shortcut, 'title' => $title, 'text' => $text, 'active' => !empty($in['active'])];
    $found = false;
    if ($id > 0) {
      foreach ($items as $i => $it) {
        if ($it['id'] === $id) { $items[$i] = ['id' => $id] + $row; $found = true; break; }
      }
      if (!$found) throw new RuntimeException('Mensagem não encontrada.');
    } else {
      $id = $next++;
      $items[] = ['id' => $id] + $row;
    }
    whatsapp_quick_replies_store($pdo, $companyId, $items, $next);
    return $id;
  }
}

if (!function_exists('whatsapp_quick_reply_delete')) {
  function whatsapp_quick_reply_delete(PDO $pdo, int $companyId, int $id): void {
    $items = array_filter(whatsapp_quick_replies($pdo, $companyId), static fn($it) => $it['id'] !== $id);
    whatsapp_quick_replies_store($pdo, $companyId, $items);
  }
}

if (!function_exists('whatsapp_quick_reply_move')) {
  function whatsapp_quick_reply_move(PDO $pdo, int $companyId, int $id, int $dir): void {
    $items = whatsapp_quick_replies($pdo, $companyId);
    foreach ($items as $i => $it) {
      if ($it['id'] !== $id) continue;
      $j = $i + ($dir < 0 ? -1 : 1);
      if ($j < 0 || $j >= count($items)) return;
      [$items[$i], $items[$j]] = [$items[$j], $items[$i]];
      whatsapp_quick_replies_store($pdo, $companyId, $items);
      return;
    }
  }
}

if (!function_exists('whatsapp_bot_company_by_session')) {
  /** Empresa dona da sessão do motor (company_id da sessão = session_company_id). */
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

/* ------------------------------------------------------------------------- */
/* Utilitários                                                                */
/* ------------------------------------------------------------------------- */

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

if (!function_exists('whatsapp_bot_normalize')) {
  function whatsapp_bot_normalize(string $text): string {
    $t = mb_strtolower(trim($text), 'UTF-8');
    return strtr($t, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
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

if (!function_exists('whatsapp_bot_user_name')) {
  function whatsapp_bot_user_name(PDO $pdo, int $companyId, int $userId): string {
    if ($userId <= 0) return '';
    $st = $pdo->prepare('SELECT name FROM users WHERE ' . (function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)') . ' AND id=? LIMIT 1');
    $st->execute(array_merge((function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId]), [$userId]));
    return trim((string)$st->fetchColumn());
  }
}

/* ------------------------------------------------------------------------- */
/* Setores                                                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_departments')) {
  function whatsapp_departments(PDO $pdo, int $companyId, bool $activeOnly = true): array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_departments WHERE company_id=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY sort_order, name, id');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('whatsapp_department_name')) {
  function whatsapp_department_name(PDO $pdo, int $companyId, ?int $departmentId): string {
    if (!$departmentId) return '';
    $st = $pdo->prepare('SELECT name FROM whatsapp_departments WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $departmentId]);
    return trim((string)$st->fetchColumn());
  }
}

if (!function_exists('whatsapp_department_members')) {
  /** [department_id => [user_id, ...]] */
  function whatsapp_department_members(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare('SELECT department_id, user_id FROM whatsapp_department_users WHERE company_id=?');
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) $out[(int)$r['department_id']][] = (int)$r['user_id'];
    return $out;
  }
}

if (!function_exists('whatsapp_user_department_ids')) {
  function whatsapp_user_department_ids(PDO $pdo, int $companyId, int $userId): array {
    $st = $pdo->prepare('SELECT du.department_id FROM whatsapp_department_users du JOIN whatsapp_departments d ON d.company_id=du.company_id AND d.id=du.department_id AND d.active=1 WHERE du.company_id=? AND du.user_id=?');
    $st->execute([$companyId, $userId]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
  }
}

if (!function_exists('whatsapp_department_save')) {
  function whatsapp_department_save(PDO $pdo, int $companyId, int $id, string $name, bool $active, array $userIds): int {
    $name = mb_substr(trim($name), 0, 80);
    if ($name === '') throw new RuntimeException('Informe o nome do setor.');
    if ($id > 0) {
      $st = $pdo->prepare('UPDATE whatsapp_departments SET name=?, active=? WHERE company_id=? AND id=?');
      $st->execute([$name, $active ? 1 : 0, $companyId, $id]);
      $chk = $pdo->prepare('SELECT id FROM whatsapp_departments WHERE company_id=? AND id=?');
      $chk->execute([$companyId, $id]);
      if (!$chk->fetchColumn()) throw new RuntimeException('Setor não encontrado.');
    } else {
      $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM whatsapp_departments WHERE company_id=?');
      $max->execute([$companyId]);
      $pdo->prepare('INSERT INTO whatsapp_departments (company_id, name, active, sort_order, created_at) VALUES (?,?,?,?,?)')
        ->execute([$companyId, $name, $active ? 1 : 0, (int)$max->fetchColumn(), date('Y-m-d H:i:s')]);
      $id = (int)$pdo->lastInsertId();
    }
    // Só usuários ativos da própria empresa.
    $valid = [];
    if ($userIds) {
      $ids = array_values(array_unique(array_filter(array_map('intval', $userIds), static fn($v) => $v > 0)));
      if ($ids) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare('SELECT id FROM users WHERE ' . (function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)') . " AND id IN ($in)");
        $st->execute(array_merge((function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId]), $ids));
        $valid = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
      }
    }
    $pdo->prepare('DELETE FROM whatsapp_department_users WHERE company_id=? AND department_id=?')->execute([$companyId, $id]);
    $ins = $pdo->prepare('INSERT INTO whatsapp_department_users (company_id, department_id, user_id) VALUES (?,?,?)');
    foreach ($valid as $uid) $ins->execute([$companyId, $id, $uid]);
    return $id;
  }
}

if (!function_exists('whatsapp_department_delete')) {
  function whatsapp_department_delete(PDO $pdo, int $companyId, int $id): void {
    $pdo->prepare('DELETE FROM whatsapp_department_users WHERE company_id=? AND department_id=?')->execute([$companyId, $id]);
    $pdo->prepare('UPDATE whatsapp_bot_nodes SET department_id=NULL WHERE company_id=? AND department_id=?')->execute([$companyId, $id]);
    $pdo->prepare('UPDATE whatsapp_bot_sessions SET department_id=NULL WHERE company_id=? AND department_id=?')->execute([$companyId, $id]);
    $pdo->prepare('DELETE FROM whatsapp_departments WHERE company_id=? AND id=?')->execute([$companyId, $id]);
  }
}

/* ------------------------------------------------------------------------- */
/* Fluxo (árvore de menus)                                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_bot_node_actions')) {
  function whatsapp_bot_node_actions(): array {
    return [
      'submenu' => 'Abrir submenu',
      'text' => 'Enviar mensagem',
      'setor' => 'Transferir para setor (atendente)',
      'boleto' => '2ª via de boleto (pede CPF/CNPJ)',
      'pedido' => 'Consultar pedido',
      'crm' => 'Abrir atendimento no CRM',
      'repetir_pedido' => 'Repetir o último pedido do cliente',
      'fluxo' => 'Iniciar um fluxo (perguntas em sequência)',
    ];
  }
}

if (!function_exists('whatsapp_bot_nodes')) {
  /** Opções de um menu (parent null = menu inicial). */
  function whatsapp_bot_nodes(PDO $pdo, int $companyId, ?int $parentId, bool $activeOnly = true): array {
    $sql = 'SELECT * FROM whatsapp_bot_nodes WHERE company_id=? AND ' . ($parentId ? 'parent_id=?' : 'parent_id IS NULL') . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY sort_order, id';
    $st = $pdo->prepare($sql);
    $st->execute($parentId ? [$companyId, $parentId] : [$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('whatsapp_bot_node')) {
  function whatsapp_bot_node(PDO $pdo, int $companyId, int $id): ?array {
    if ($id <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_nodes WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('whatsapp_bot_node_tree')) {
  /** Árvore completa para o editor: lista plana na ordem de exibição, com 'depth'. */
  function whatsapp_bot_node_tree(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_nodes WHERE company_id=? ORDER BY sort_order, id');
    $st->execute([$companyId]);
    $byParent = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $n) $byParent[(int)($n['parent_id'] ?? 0)][] = $n;
    $out = [];
    $walk = static function (int $parent, int $depth, string $prefix) use (&$walk, &$out, $byParent): void {
      foreach ($byParent[$parent] ?? [] as $i => $n) {
        $n['depth'] = $depth;
        $n['number'] = $prefix . ($i + 1);
        $out[] = $n;
        if ($depth < 6) $walk((int)$n['id'], $depth + 1, $n['number'] . '.');
      }
    };
    $walk(0, 0, '');
    return $out;
  }
}

if (!function_exists('whatsapp_bot_node_save')) {
  function whatsapp_bot_node_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $label = mb_substr(trim((string)($in['label'] ?? '')), 0, 80);
    if ($label === '') throw new RuntimeException('Informe o texto da opção.');
    $action = (string)($in['action'] ?? 'text');
    if (!isset(whatsapp_bot_node_actions()[$action])) throw new RuntimeException('Ação inválida.');
    $message = mb_substr(trim((string)($in['message'] ?? '')), 0, 3000);
    if ($action === 'text' && $message === '') throw new RuntimeException('Escreva a mensagem que o robô vai enviar.');
    $parentId = (int)($in['parent_id'] ?? 0) ?: null;
    if ($parentId) {
      $parent = whatsapp_bot_node($pdo, $companyId, $parentId);
      if (!$parent || $parent['action'] !== 'submenu') throw new RuntimeException('O menu de origem precisa ser do tipo "Abrir submenu".');
      // Evita ciclo (uma opção dentro dela mesma).
      for ($p = $parent, $guard = 0; $p && $guard < 20; $guard++) {
        if ($id > 0 && (int)$p['id'] === $id) throw new RuntimeException('Uma opção não pode ficar dentro dela mesma.');
        $p = !empty($p['parent_id']) ? whatsapp_bot_node($pdo, $companyId, (int)$p['parent_id']) : null;
      }
    }
    $departmentId = $action === 'setor' ? ((int)($in['department_id'] ?? 0) ?: null) : null;
    $flowId = $action === 'fluxo' ? ((int)($in['flow_id'] ?? 0) ?: null) : null;
    if ($action === 'fluxo' && (!$flowId || !whatsapp_bot_flows_ready($pdo) || !whatsapp_bot_flow($pdo, $companyId, $flowId))) throw new RuntimeException('Escolha o fluxo que esta opção vai iniciar.');
    $hasFlowCol = whatsapp_bot_flows_ready($pdo);
    if ($departmentId && whatsapp_department_name($pdo, $companyId, $departmentId) === '') throw new RuntimeException('Setor não encontrado.');
    $active = !empty($in['active']) ? 1 : 0;
    if ($id > 0) {
      $current = whatsapp_bot_node($pdo, $companyId, $id);
      if (!$current) throw new RuntimeException('Opção não encontrada.');
      if ($current['action'] === 'submenu' && $action !== 'submenu' && whatsapp_bot_nodes($pdo, $companyId, $id, false)) {
        throw new RuntimeException('Esta opção tem itens dentro dela. Exclua ou mova os itens antes de trocar a ação.');
      }
      $sort = (int)$current['sort_order'];
      if ((int)($current['parent_id'] ?? 0) !== (int)($parentId ?? 0)) $sort = whatsapp_bot_node_next_sort($pdo, $companyId, $parentId);
      $pdo->prepare('UPDATE whatsapp_bot_nodes SET parent_id=?, sort_order=?, label=?, action=?, message=?, department_id=?, active=? WHERE company_id=? AND id=?')
        ->execute([$parentId, $sort, $label, $action, $message !== '' ? $message : null, $departmentId, $active, $companyId, $id]);
      if ($hasFlowCol) $pdo->prepare('UPDATE whatsapp_bot_nodes SET flow_id=? WHERE company_id=? AND id=?')->execute([$flowId, $companyId, $id]);
      return $id;
    }
    $pdo->prepare('INSERT INTO whatsapp_bot_nodes (company_id, parent_id, sort_order, label, action, message, department_id, active, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, $parentId, whatsapp_bot_node_next_sort($pdo, $companyId, $parentId), $label, $action, $message !== '' ? $message : null, $departmentId, $active, date('Y-m-d H:i:s')]);
    $newId = (int)$pdo->lastInsertId();
    if ($hasFlowCol && $flowId) $pdo->prepare('UPDATE whatsapp_bot_nodes SET flow_id=? WHERE company_id=? AND id=?')->execute([$flowId, $companyId, $newId]);
    return $newId;
  }
}

if (!function_exists('whatsapp_bot_node_next_sort')) {
  function whatsapp_bot_node_next_sort(PDO $pdo, int $companyId, ?int $parentId): int {
    $st = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM whatsapp_bot_nodes WHERE company_id=? AND ' . ($parentId ? 'parent_id=?' : 'parent_id IS NULL'));
    $st->execute($parentId ? [$companyId, $parentId] : [$companyId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('whatsapp_bot_node_delete')) {
  /** Exclui a opção e tudo que estiver dentro dela. */
  function whatsapp_bot_node_delete(PDO $pdo, int $companyId, int $id): void {
    foreach (whatsapp_bot_nodes($pdo, $companyId, $id, false) as $child) whatsapp_bot_node_delete($pdo, $companyId, (int)$child['id']);
    $pdo->prepare('DELETE FROM whatsapp_bot_nodes WHERE company_id=? AND id=?')->execute([$companyId, $id]);
  }
}

if (!function_exists('whatsapp_bot_node_move')) {
  /** Sobe (-1) ou desce (+1) a opção entre as do mesmo menu. */
  function whatsapp_bot_node_move(PDO $pdo, int $companyId, int $id, int $dir): void {
    $node = whatsapp_bot_node($pdo, $companyId, $id);
    if (!$node) return;
    $siblings = whatsapp_bot_nodes($pdo, $companyId, ((int)($node['parent_id'] ?? 0)) ?: null, false);
    $ids = array_map(static fn($n) => (int)$n['id'], $siblings);
    $pos = array_search($id, $ids, true);
    $to = $pos === false ? false : $pos + ($dir < 0 ? -1 : 1);
    if ($to === false || $to < 0 || $to >= count($ids)) return;
    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
    $up = $pdo->prepare('UPDATE whatsapp_bot_nodes SET sort_order=? WHERE company_id=? AND id=?');
    foreach ($ids as $i => $nid) $up->execute([$i + 1, $companyId, $nid]);
  }
}

if (!function_exists('whatsapp_bot_seed_default_flow')) {
  /** Menu inicial sugerido (só quando a empresa ainda não tem nenhuma opção). */
  function whatsapp_bot_seed_default_flow(PDO $pdo, int $companyId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM whatsapp_bot_nodes WHERE company_id=?');
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) return;
    $items = [
      ['2ª via de boleto', 'boleto', null],
      ['Consultar pedido', 'pedido', null],
      ['Abrir atendimento / suporte', 'crm', null],
      ['Certificado Digital', 'text', 'Trabalhamos com Certificado Digital A1 e A3 para pessoa física e jurídica. Para valores e agendamento, escolha a opção de falar com um atendente.'],
      ['Falar com um atendente', 'setor', null],
    ];
    foreach ($items as $it) whatsapp_bot_node_save($pdo, $companyId, ['label' => $it[0], 'action' => $it[1], 'message' => $it[2], 'active' => 1]);
  }
}

if (!function_exists('whatsapp_bot_menu_text')) {
  function whatsapp_bot_menu_text(PDO $pdo, int $companyId, ?int $parentId): string {
    $lines = [];
    foreach (whatsapp_bot_nodes($pdo, $companyId, $parentId) as $i => $n) $lines[] = '*' . ($i + 1) . '* - ' . $n['label'];
    if ($parentId) $lines[] = '*0* - Voltar';
    return implode("\n", $lines);
  }
}

/* ------------------------------------------------------------------------- */
/* Arquivos (fora da pasta pública)                                           */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_media_root')) {
  function whatsapp_media_root(): string {
    $app = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $base = trim((string)(getenv('NEXA_WA_MEDIA_DIR') ?: ''));
    if ($base === '') $base = dirname($app) . '/nexa_private/whatsapp_media/' . basename($app);
    if ($base[0] !== '/') throw new RuntimeException('A pasta de arquivos do WhatsApp precisa ser um caminho absoluto.');
    if (!is_dir($base) && !@mkdir($base, 0700, true)) {
      throw new RuntimeException('Não foi possível criar a pasta de arquivos do WhatsApp (' . $base . ').');
    }
    $root = realpath($base);
    if ($root === false || !is_writable($root)) throw new RuntimeException('A pasta de arquivos do WhatsApp está sem permissão de gravação (' . $base . ').');
    if ($root === $app || str_starts_with($root, $app . '/')) throw new RuntimeException('A pasta de arquivos do WhatsApp não pode ficar dentro da pasta pública.');
    return $root;
  }
}

if (!function_exists('whatsapp_media_ext')) {
  function whatsapp_media_ext(string $mime, string $name = ''): string {
    $map = [
      'image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif',
      'audio/ogg' => 'ogg', 'audio/mpeg' => 'mp3', 'audio/mp4' => 'm4a', 'audio/aac' => 'aac', 'audio/amr' => 'amr', 'audio/webm' => 'weba',
      'video/mp4' => 'mp4', 'video/3gpp' => '3gp', 'video/webm' => 'webm',
      'application/pdf' => 'pdf', 'text/plain' => 'txt', 'text/csv' => 'csv', 'application/zip' => 'zip', 'application/xml' => 'xml', 'text/xml' => 'xml',
      'application/msword' => 'doc', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => 'docx',
      'application/vnd.ms-excel' => 'xls', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet' => 'xlsx',
    ];
    $mime = trim(explode(';', strtolower($mime))[0]);
    if (isset($map[$mime])) return $map[$mime];
    $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
    return preg_match('/^[a-z0-9]{1,5}$/', $ext) ? $ext : 'bin';
  }
}

if (!function_exists('whatsapp_media_store')) {
  /** Grava o arquivo e devolve o caminho relativo (empresa/AAAAMM/arquivo). */
  function whatsapp_media_store(int $companyId, string $bytes, string $mime, string $name = ''): string {
    $rel = $companyId . '/' . date('Ym');
    $dir = whatsapp_media_root() . '/' . $rel;
    if (!is_dir($dir) && !@mkdir($dir, 0700, true)) throw new RuntimeException('Não foi possível criar a pasta da empresa para arquivos do WhatsApp.');
    $file = bin2hex(random_bytes(12)) . '.' . whatsapp_media_ext($mime, $name);
    if (@file_put_contents($dir . '/' . $file, $bytes) === false) throw new RuntimeException('Não foi possível gravar o arquivo do WhatsApp.');
    return $rel . '/' . $file;
  }
}

if (!function_exists('whatsapp_media_path')) {
  /** Caminho absoluto de um arquivo gravado (validado para não sair da pasta da empresa). */
  function whatsapp_media_path(int $companyId, string $rel): ?string {
    if (!preg_match('#^' . $companyId . '/\d{6}/[a-f0-9]{24}\.[a-z0-9]{1,5}$#', $rel)) return null;
    $path = whatsapp_media_root() . '/' . $rel;
    return is_file($path) ? $path : null;
  }
}

if (!function_exists('whatsapp_engine_fetch_media')) {
  /** Busca no motor o arquivo de uma mensagem recebida. Retorna [bytes, mime]. */
  function whatsapp_engine_fetch_media(array $waCfg, string $sid, string $mediaId): array {
    if (!function_exists('curl_init')) throw new RuntimeException('cURL indisponível.');
    $url = rtrim((string)$waCfg['service_url'], '/') . '/media?' . http_build_query(['company_id' => $sid, 'id' => $mediaId]);
    $ch = curl_init($url);
    $size = 0;
    curl_setopt_array($ch, [
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_HTTPHEADER => ['X-API-Token: ' . trim((string)($waCfg['api_token'] ?? ''))],
      CURLOPT_CONNECTTIMEOUT => 4,
      CURLOPT_TIMEOUT => 60,
      CURLOPT_NOPROGRESS => false,
      CURLOPT_PROGRESSFUNCTION => static function ($c, $dlTotal, $dl) { return $dl > WHATSAPP_MEDIA_MAX_BYTES + 1024 ? 1 : 0; },
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $mime = strtolower(trim(explode(';', (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE))[0]));
    $err = curl_error($ch);
    curl_close($ch);
    if ($body === false || $code !== 200) throw new RuntimeException('Arquivo não disponível no motor (' . ($err ?: 'HTTP ' . $code) . ').');
    return [(string)$body, $mime ?: 'application/octet-stream'];
  }
}

/* ------------------------------------------------------------------------- */
/* Conversas e mensagens                                                      */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_chat_get')) {
  function whatsapp_chat_get(PDO $pdo, int $companyId, int $chatId): ?array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_sessions WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $chatId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return null;
    $row['data'] = json_decode((string)($row['data_json'] ?? ''), true) ?: [];
    return $row;
  }
}

if (!function_exists('whatsapp_chat_find_or_create')) {
  /**
   * Conversa do contato. O mesmo cliente pode chegar como número (@s.whatsapp.net)
   * ou como LID (@lid): procura pelo endereço e, se não achar, pelo telefone.
   */
  function whatsapp_chat_find_or_create(PDO $pdo, int $companyId, string $contact, string $phone): array {
    $st = $pdo->prepare('SELECT id FROM whatsapp_bot_sessions WHERE company_id=? AND contact=? LIMIT 1');
    $st->execute([$companyId, $contact]);
    $id = (int)$st->fetchColumn();
    if (!$id && $phone !== '') {
      $st = $pdo->prepare('SELECT id FROM whatsapp_bot_sessions WHERE company_id=? AND phone=? ORDER BY last_message_at DESC, id DESC LIMIT 1');
      $st->execute([$companyId, $phone]);
      $id = (int)$st->fetchColumn();
      if ($id) $pdo->prepare('UPDATE whatsapp_bot_sessions SET contact=? WHERE company_id=? AND id=?')->execute([$contact, $companyId, $id]);
    }
    if (!$id) {
      $now = date('Y-m-d H:i:s');
      $pdo->prepare("INSERT INTO whatsapp_bot_sessions (company_id, contact, phone, state, status, data_json, created_at) VALUES (?,?,?,'NOVA','BOT','{}',?)")
        ->execute([$companyId, $contact, $phone !== '' ? $phone : null, $now]);
      $id = (int)$pdo->lastInsertId();
    }
    return whatsapp_chat_get($pdo, $companyId, $id) ?? [];
  }
}

if (!function_exists('whatsapp_chat_update')) {
  function whatsapp_chat_update(PDO $pdo, int $companyId, int $chatId, array $fields): void {
    $allowed = ['contact','phone','push_name','state','status','data_json','paused_until','doc_attempts','doc_attempts_since','department_id','user_id','person_id','contact_id','unread','queued_at','accepted_at','closed_at','human_at','crm_attendance_id','last_message_at','answers_json'];
    $sets = []; $params = [];
    foreach ($fields as $k => $v) {
      if ($k === 'data') { $k = 'data_json'; $v = json_encode($v ?: new stdClass(), JSON_UNESCAPED_UNICODE); }
      if (!in_array($k, $allowed, true)) continue;
      $sets[] = $k . '=?';
      $params[] = $v;
    }
    if (!$sets) return;
    $params[] = $companyId; $params[] = $chatId;
    $pdo->prepare('UPDATE whatsapp_bot_sessions SET ' . implode(', ', $sets) . ' WHERE company_id=? AND id=?')->execute($params);
  }
}

if (!function_exists('whatsapp_chat_message_exists')) {
  function whatsapp_chat_message_exists(PDO $pdo, int $companyId, string $messageId): bool {
    if ($messageId === '') return false;
    $st = $pdo->prepare('SELECT 1 FROM whatsapp_bot_messages WHERE company_id=? AND message_id=? LIMIT 1');
    $st->execute([$companyId, $messageId]);
    return (bool)$st->fetchColumn();
  }
}

if (!function_exists('whatsapp_chat_log')) {
  /**
   * Grava uma mensagem na conversa. $sender: cliente | robo | atendente | celular | sistema.
   * Retorna o id gravado (0 = repetida).
   */
  function whatsapp_chat_log(PDO $pdo, int $companyId, array $chat, string $direction, string $sender, string $body, array $opt = []): int {
    $now = date('Y-m-d H:i:s');
    $messageId = substr((string)($opt['message_id'] ?? ''), 0, 80);
    try {
      $pdo->prepare('INSERT INTO whatsapp_bot_messages (company_id, contact, session_id, direction, sender, user_id, message_id, msg_type, body, media_path, media_mime, media_name, media_size, send_ok, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([
          $companyId, (string)$chat['contact'], (int)$chat['id'], $direction, $sender, ($opt['user_id'] ?? null) ?: null,
          $messageId !== '' ? $messageId : null, (string)($opt['type'] ?? 'text'), mb_substr($body, 0, 4000),
          $opt['media_path'] ?? null, $opt['media_mime'] ?? null, isset($opt['media_name']) ? mb_substr((string)$opt['media_name'], 0, 180) : null,
          isset($opt['media_size']) ? (int)$opt['media_size'] : null, array_key_exists('send_ok', $opt) ? ((int)(bool)$opt['send_ok']) : 1, $now,
        ]);
    } catch (PDOException $e) {
      if ((string)$e->getCode() === '23000') return 0; // o motor reenviou a mesma mensagem
      throw $e;
    }
    $id = (int)$pdo->lastInsertId();
    if ($sender !== 'sistema') {
      $preview = trim($body) !== '' ? $body : whatsapp_chat_type_label((string)($opt['type'] ?? 'text'));
      $pdo->prepare('UPDATE whatsapp_bot_sessions SET last_preview=?, last_direction=?, last_message_at=? WHERE company_id=? AND id=?')
        ->execute([mb_substr(preg_replace('/\s+/', ' ', $preview) ?? '', 0, 200), $direction, $now, $companyId, (int)$chat['id']]);
    }
    return $id;
  }
}

if (!function_exists('whatsapp_chat_type_label')) {
  function whatsapp_chat_type_label(string $type): string {
    return ['image' => '📷 Foto', 'audio' => '🎤 Áudio', 'video' => '🎬 Vídeo', 'document' => '📄 Documento', 'sticker' => 'Figurinha', 'location' => '📍 Localização', 'contact' => '👤 Contato'][$type] ?? 'Mensagem';
  }
}

if (!function_exists('whatsapp_chat_notify')) {
  /** Avisa as telas abertas (tempo real, sem consultas periódicas). */
  function whatsapp_chat_notify(int $companyId, int $chatId, array $extra = []): void {
    if (!function_exists('nexa_realtime_publish_event') && is_file(__DIR__ . '/brain_realtime.php')) require_once __DIR__ . '/brain_realtime.php';
    if (!function_exists('nexa_realtime_publish_event')) return;
    try {
      nexa_realtime_publish_event($companyId, 'whatsapp.chat.updated', 'whatsapp_chat', $chatId, $extra);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('whatsapp_chat_ctx')) {
  function whatsapp_chat_ctx(PDO $pdo, int $companyId, array $chat): array {
    $wa = whatsapp_load_config($pdo, $companyId, false, true);
    return [
      'sid' => whatsapp_service_company_id($wa, $companyId),
      'contact' => (string)$chat['contact'],
      'phone' => whatsapp_bot_digits($chat['phone'] ?? ''),
      'push_name' => (string)($chat['push_name'] ?? ''),
      'wa' => $wa,
      'chat' => $chat,
    ];
  }
}

if (!function_exists('whatsapp_chat_send_text')) {
  /** Envia texto ao contato e registra na conversa. */
  function whatsapp_chat_send_text(PDO $pdo, int $companyId, array $ctx, string $text, string $sender = 'robo', ?int $userId = null, string $wireText = ''): bool {
    $payload = ['company_id' => $ctx['sid'], 'message' => $wireText !== '' ? $wireText : $text];
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone'];
    // Responde sempre pelo número de telefone quando ele é conhecido: o envio direto
    // para o endereço interno (@lid) fazia o WhatsApp derrubar a sessão (device_removed).
    if ($ctx['phone'] === '' && $ctx['contact'] !== '') $payload['jid'] = $ctx['contact'];
    $ok = true; $messageId = '';
    try {
      $res = whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 40]), 'POST', '/send', $payload);
      $messageId = (string)($res['message_id'] ?? '');
    } catch (Throwable $e) {
      $ok = false;
      $text .= "\n[não enviado: " . $e->getMessage() . ']';
    }
    whatsapp_chat_log($pdo, $companyId, $ctx['chat'], 'OUT', $sender, $text, ['user_id' => $userId, 'message_id' => $messageId, 'send_ok' => $ok]);
    return $ok;
  }
}

if (!function_exists('whatsapp_chat_send_file')) {
  /** Envia arquivo (foto, vídeo, áudio ou documento) e registra na conversa. */
  function whatsapp_chat_send_file(PDO $pdo, int $companyId, array $ctx, string $bytes, string $mime, string $filename, string $caption = '', string $sender = 'robo', ?int $userId = null, bool $store = true, bool $ptt = false): bool {
    $mime = strtolower(trim($mime)) ?: 'application/octet-stream';
    // Tipo base sem parâmetros (ex.: "audio/ogg; codecs=opus" -> "audio/ogg").
    $base = trim(explode(';', $mime)[0]);
    $kind = 'document';
    if (in_array($base, ['image/jpeg', 'image/png', 'image/webp'], true)) $kind = 'image';
    elseif ($base === 'video/mp4') $kind = 'video';
    elseif (in_array($base, ['audio/ogg', 'audio/mpeg', 'audio/mp4', 'audio/aac'], true)) $kind = 'audio';
    // Áudio gravado (mensagem de voz): o WhatsApp exige Ogg/Opus.
    $ptt = $ptt && $kind === 'audio' && $base === 'audio/ogg';
    if ($ptt) $mime = 'audio/ogg; codecs=opus';
    $filename = mb_substr(basename($filename) ?: 'arquivo', 0, 150);
    $payload = ['company_id' => $ctx['sid'], 'kind' => $kind, 'caption' => $caption, 'filename' => $filename, 'mimetype' => $mime, 'file_base64' => base64_encode($bytes)];
    if ($ptt) $payload['ptt'] = true;
    if ($ctx['phone'] !== '') $payload['to'] = $ctx['phone'];
    // Responde sempre pelo número de telefone quando ele é conhecido: o envio direto
    // para o endereço interno (@lid) fazia o WhatsApp derrubar a sessão (device_removed).
    if ($ctx['phone'] === '' && $ctx['contact'] !== '') $payload['jid'] = $ctx['contact'];
    $ok = true; $messageId = ''; $error = '';
    try {
      $res = whatsapp_api_request(array_merge($ctx['wa'], ['timeout_seconds' => 60]), 'POST', '/send-document', $payload);
      $messageId = (string)($res['message_id'] ?? '');
    } catch (Throwable $e) {
      $ok = false;
      $error = $e->getMessage();
    }
    $opt = ['user_id' => $userId, 'message_id' => $messageId, 'send_ok' => $ok, 'type' => $kind, 'media_mime' => $base, 'media_name' => $filename, 'media_size' => strlen($bytes)];
    if ($store) {
      try { $opt['media_path'] = whatsapp_media_store($companyId, $bytes, $base, $filename); } catch (Throwable $e) {}
    }
    whatsapp_chat_log($pdo, $companyId, $ctx['chat'], 'OUT', $sender, $caption . ($ok ? '' : "\n[não enviado: " . $error . ']'), $opt);
    return $ok;
  }
}

if (!function_exists('whatsapp_chat_media_from_payload')) {
  /** Copia para a pasta do sistema o arquivo de uma mensagem recebida. */
  function whatsapp_chat_media_from_payload(int $companyId, array $waCfg, string $sid, array $payload): array {
    $media = is_array($payload['media'] ?? null) ? $payload['media'] : null;
    if (!$media) return [];
    $opt = [
      'media_mime' => mb_substr((string)($media['mimetype'] ?? ''), 0, 100) ?: null,
      'media_name' => (string)($media['filename'] ?? '') ?: null,
      'media_size' => (int)($media['size'] ?? 0) ?: null,
    ];
    if (!empty($media['error'])) {
      $opt['media_error'] = $media['error'] === 'grande' ? 'Arquivo muito grande para o sistema (veja no celular).' : 'Não foi possível baixar o arquivo (veja no celular).';
      return $opt;
    }
    $id = (string)($media['id'] ?? '');
    if ($id === '') return $opt;
    try {
      [$bytes, $mime] = whatsapp_engine_fetch_media($waCfg, $sid, $id);
      $mime = (string)($opt['media_mime'] ?: $mime);
      $opt['media_mime'] = $mime;
      $opt['media_size'] = strlen($bytes);
      $opt['media_path'] = whatsapp_media_store($companyId, $bytes, $mime, (string)$opt['media_name']);
    } catch (Throwable $e) {
      $opt['media_error'] = 'Não foi possível copiar o arquivo: ' . $e->getMessage();
    }
    return $opt;
  }
}

/* ------------------------------------------------------------------------- */
/* Ações do robô                                                              */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_bot_send_boletos')) {
  /** 2ª via: boletos em aberto do CPF/CNPJ informado. */
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
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não encontrei boletos em aberto para este CPF/CNPJ. ✅\n\nDigite *menu* para ver as outras opções.");
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
        if (whatsapp_chat_send_file($pdo, $companyId, $ctx, $pdf, 'application/pdf', 'boleto-' . (int)$r['id'] . '.pdf', $caption, 'robo', null, false)) $sent++;
        $pix = trim((string)($data['pix_emv'] ?? ''));
        if ($pix !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, "Pix copia e cola deste boleto:\n" . $pix);
      } catch (Throwable $e) {
        $withoutBoleto[] = $r;
      }
    }
    $msg = $sent > 0 ? "Pronto! Enviei " . $sent . " boleto(s) em aberto." : '';
    if ($withoutBoleto) {
      $lines = array_map(static fn($r) => '• Venc. ' . date('d/m/Y', strtotime((string)$r['due_date'])) . ' - ' . whatsapp_bot_money($r['open_amount']), array_slice($withoutBoleto, 0, 5));
      $msg .= ($msg !== '' ? "\n\n" : '') . "Estas contas em aberto não têm boleto disponível para envio automático:\n" . implode("\n", $lines) . "\nSe precisar, fale com um atendente pelo menu.";
    }
    whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg . "\n\nDigite *menu* para ver as outras opções.");
  }
}

if (!function_exists('whatsapp_bot_group_company_ids')) {
  /**
   * Empresas consultadas na 2ª via: a do WhatsApp e, com a opção ligada no Chatbot,
   * as outras empresas ativas do mesmo Grupo Empresarial (cadastrado na Central SaaS).
   */
  function whatsapp_bot_group_company_ids(PDO $pdo, int $companyId, array $cfg): array {
    $ids = [$companyId];
    if (empty($cfg['group_receivables'])) return $ids;
    try {
      $st = $pdo->prepare("SELECT gc2.company_id
        FROM business_group_companies gc
        JOIN business_groups g ON g.id = gc.business_group_id AND g.is_active = 1
        JOIN business_group_companies gc2 ON gc2.business_group_id = gc.business_group_id AND gc2.is_active = 1
        JOIN companies c ON c.id = gc2.company_id
        WHERE gc.company_id = ? AND gc.is_active = 1 AND gc2.company_id <> ?
          AND LOWER(COALESCE(c.status, 'active')) NOT IN ('blocked', 'suspended', 'expired', 'cancelled', 'canceled', 'inactive')
        ORDER BY gc2.is_primary DESC, c.name, c.id");
      $st->execute([$companyId, $companyId]);
      foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $id) if ((int)$id > 0) $ids[] = (int)$id;
    } catch (Throwable $e) {}
    return array_values(array_unique($ids));
  }
}

if (!function_exists('whatsapp_bot_open_receivables')) {
  /** Contas a receber em aberto do CPF/CNPJ, empresa por empresa (só onde a pessoa existe). */
  function whatsapp_bot_open_receivables(PDO $pdo, array $companyIds, string $doc, int $limit = 20): array {
    $out = [];
    foreach ($companyIds as $cid) {
      $st = $pdo->prepare("SELECT r.id, r.due_date, r.open_amount FROM finance_receivables r
        JOIN finance_people p ON p.company_id=r.company_id AND p.id=r.person_id
        WHERE r.company_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(p.document,''),'.',''),'-',''),'/',''),' ','')=?
          AND UPPER(COALESCE(r.status,'')) IN ('ABERTO','PARCIAL') AND COALESCE(r.open_amount,0) > 0.009
        ORDER BY r.due_date, r.id LIMIT " . max(1, min(50, $limit)));
      $st->execute([(int)$cid, $doc]);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
        if (count($out) >= $limit) break 2;
        $out[] = ['c' => (int)$cid, 'r' => (int)$r['id'], 'due' => (string)$r['due_date'], 'amount' => (float)$r['open_amount']];
      }
    }
    return $out;
  }
}

if (!function_exists('whatsapp_bot_send_one_boleto')) {
  /** Envia o boleto (PDF + linha digitável + Pix do boleto) de uma conta; o envio sai pelo WhatsApp da empresa $companyId. */
  function whatsapp_bot_send_one_boleto(PDO $pdo, int $companyId, array $ctx, int $srcCompanyId, int $receivableId, string $companyLabel = ''): bool {
    require_once __DIR__ . '/billing_module.php';
    require_once __DIR__ . '/boleto_document.php';
    $charge = billing_receivable_charge($pdo, $srcCompanyId, $receivableId);
    $status = strtoupper((string)($charge['status'] ?? ''));
    if (!$charge || in_array($status, ['CANCELADO','PAGO','BAIXADO','ERRO','PENDENTE_REGISTRO'], true)) return false;
    try {
      $data = boleto_document_load($pdo, $srcCompanyId, (int)$charge['id'], $receivableId);
      if (!boleto_document_ready($data)) return false;
      $pdf = boleto_document_pdf($data);
      $caption = ($companyLabel !== '' ? $companyLabel . "\n" : '') . "Boleto - vencimento " . boleto_document_date($data['due_date'] ?? '') . " - " . boleto_document_money($data['amount'] ?? 0);
      $line = trim((string)($data['linha_digitavel'] ?? ''));
      if ($line !== '') $caption .= "\nLinha digitável: " . $line;
      if (!whatsapp_chat_send_file($pdo, $companyId, $ctx, $pdf, 'application/pdf', 'boleto-' . $receivableId . '.pdf', $caption, 'robo', null, false)) return false;
      $pix = trim((string)($data['pix_emv'] ?? ''));
      if ($pix !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, "Pix copia e cola deste boleto:\n" . $pix);
      return true;
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('whatsapp_bot_list_receivables')) {
  /** Lista numerada das contas em aberto (agrupada por empresa). Retorna as opções para guardar na conversa. */
  function whatsapp_bot_list_receivables(PDO $pdo, int $companyId, array $ctx, array $cfg, string $doc): array {
    $items = whatsapp_bot_open_receivables($pdo, whatsapp_bot_group_company_ids($pdo, $companyId, $cfg), $doc, 20);
    if (!$items) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não encontrei contas em aberto para este CPF/CNPJ. ✅\n\nDigite *menu* para ver as outras opções.");
      return [];
    }
    $names = [];
    $lines = ["Encontrei estas contas em aberto:"];
    $last = 0;
    $today = date('Y-m-d');
    foreach ($items as $i => $it) {
      if ($it['c'] !== $last) {
        $names[$it['c']] = $names[$it['c']] ?? whatsapp_bot_company_name($pdo, $it['c']);
        $lines[] = "\n*" . $names[$it['c']] . "*";
        $last = $it['c'];
      }
      $due = $it['due'] !== '' ? date('d/m/Y', strtotime($it['due'])) : '-';
      $lines[] = ($i + 1) . ') Venc. ' . $due . ' - ' . whatsapp_bot_money($it['amount']) . ($it['due'] !== '' && substr($it['due'], 0, 10) < $today ? ' (vencida)' : '');
    }
    $lines[] = "\nResponda com o *número* da conta para receber o boleto (pode mandar vários, ex.: 1,3) ou *todos*.\nDigite *menu* para voltar.";
    whatsapp_chat_send_text($pdo, $companyId, $ctx, implode("\n", $lines));
    return array_map(static fn($it) => ['c' => $it['c'], 'r' => $it['r']], $items);
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
    $label = whatsapp_status_label(strtolower(trim((string)($order['status'] ?? ''))));
    $date = !empty($order['created_at']) ? date('d/m/Y', strtotime((string)$order['created_at'])) : '';
    $text = "Pedido nº *" . $n . "*" . ($date !== '' ? " (" . $date . ")" : '') . "\nSituação: *" . $label . "*";
    whatsapp_chat_send_text($pdo, $companyId, $ctx, $text . "\n\nDigite *menu* para ver as outras opções.");
    return true;
  }
}

if (!function_exists('whatsapp_bot_contact_link')) {
  /**
   * Campos para ligar a conversa ao contato/pessoa pelo número: primeiro o cadastro de
   * Contatos (quem chama, contador... de um cliente), depois o WhatsApp/telefone da pessoa.
   * Só preenche o que a conversa ainda não tem.
   */
  function whatsapp_bot_contact_link(PDO $pdo, int $companyId, array $chat, string $phone): array {
    $upd = [];
    if ($phone === '') return $upd;
    $hasContactCol = whatsapp_bot_sessions_has_contact($pdo);
    if ($hasContactCol && empty($chat['contact_id'])) {
      try {
        if (!function_exists('person_contact_find_by_phone')) require_once __DIR__ . '/person_contacts.php';
        $c = person_contact_find_by_phone($pdo, $companyId, $phone);
        if ($c) {
          $upd['contact_id'] = (int)$c['id'];
          if (empty($chat['person_id']) && (int)($c['person_id'] ?? 0) > 0) $upd['person_id'] = (int)$c['person_id'];
        }
      } catch (Throwable $e) {}
    }
    if (empty($chat['person_id']) && empty($upd['person_id'])) {
      try { $p = whatsapp_bot_find_person($pdo, $companyId, $phone); if ($p) $upd['person_id'] = (int)$p['id']; } catch (Throwable $e) {}
    }
    return $upd;
  }
}

if (!function_exists('whatsapp_bot_sessions_has_contact')) {
  function whatsapp_bot_sessions_has_contact(PDO $pdo): bool {
    static $memo = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $memo)) return $memo[$key];
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'whatsapp_bot_sessions' AND column_name = 'contact_id'");
      return $memo[$key] = (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) {
      return $memo[$key] = false;
    }
  }
}

if (!function_exists('whatsapp_bot_find_person')) {
  /** Pessoa do cadastro com este telefone/WhatsApp. */
  function whatsapp_bot_find_person(PDO $pdo, int $companyId, string $phone): ?array {
    if ($phone === '') return null;
    $local = strlen($phone) > 11 && str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
    $clean = static fn(string $col) => "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE($col,''),'(',''),')',''),'-',''),' ',''),'+','')";
    $st = $pdo->prepare('SELECT id, name, document FROM finance_people WHERE company_id=? AND (' . $clean('whatsapp') . ' IN (?,?) OR ' . $clean('phone') . ' IN (?,?)) ORDER BY id LIMIT 1');
    $st->execute([$companyId, $phone, $local, $phone, $local]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('whatsapp_bot_open_crm')) {
  /** Cria um atendimento no CRM a partir da conversa. Retorna o número (protocolo). */
  function whatsapp_bot_open_crm(PDO $pdo, int $companyId, array $ctx, array $cfg, string $request, ?int $userId = null, string $origin = 'chatbot'): int {
    $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('crm_attendances','crm_attendance_moves')");
    if ((int)$st->fetchColumn() !== 2) throw new RuntimeException('O CRM não está instalado nesta empresa.');
    $responsible = (int)($userId ?: $cfg['crm_responsible_user_id']);
    if ($responsible > 0) {
      $q = $pdo->prepare('SELECT id FROM users WHERE ' . (function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)') . ' AND id=? AND is_active=1 LIMIT 1');
      $q->execute(array_merge((function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId]), [$responsible]));
      $responsible = (int)$q->fetchColumn();
    }
    if ($responsible <= 0) {
      $q = $pdo->prepare("SELECT id FROM users WHERE company_id=? AND is_active=1 ORDER BY (role='company_admin') DESC, id LIMIT 1");
      $q->execute([$companyId]);
      $responsible = (int)$q->fetchColumn();
    }
    if ($responsible <= 0) throw new RuntimeException('Nenhum usuário ativo para ser o responsável.');
    $statusId = (int)$cfg['crm_status_id'];
    if ($statusId <= 0) { $q = $pdo->prepare('SELECT id FROM crm_statuses WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 1'); $q->execute([$companyId]); $statusId = (int)$q->fetchColumn(); }
    $typeId = (int)$cfg['crm_service_type_id'];
    if ($typeId <= 0) { $q = $pdo->prepare('SELECT id FROM crm_service_types WHERE company_id=? AND active=1 ORDER BY sort_order,id LIMIT 1'); $q->execute([$companyId]); $typeId = (int)$q->fetchColumn(); }

    $person = whatsapp_bot_find_person($pdo, $companyId, $ctx['phone']);
    $name = $person ? (string)$person['name'] : trim((string)$ctx['push_name']);
    if ($name === '') $name = 'Contato WhatsApp';
    $intro = $origin === 'chatbot' ? 'Aberto pelo cliente no chatbot do WhatsApp.' : 'Aberto pela tela de Atendimento do WhatsApp.';
    $next = date('Y-m-d');
    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT INTO crm_attendances(company_id,person_id,customer_name,phone,document,email,address,status_id,service_type_id,request_text,lead_temperature,state,pending_reason,next_contact_date,responsible_user_id,created_by,closed_by,closed_at,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,NULL,NULL,NOW(),NOW())')
        ->execute([$companyId, $person ? (int)$person['id'] : null, mb_substr($name, 0, 180), $ctx['phone'] !== '' ? $ctx['phone'] : null, $person ? ((string)$person['document'] ?: null) : null, null, null, $statusId ?: null, $typeId ?: null, $intro . "\n" . $request, 50, 'PENDENTE', 'Aguardando retorno ao cliente.', $next, $responsible, $userId ?: null]);
      $id = (int)$pdo->lastInsertId();
      $pdo->prepare('INSERT INTO crm_attendance_moves(company_id,attendance_id,movement_text,lead_temperature,state_result,pending_reason,next_contact_date,responsible_user_id,created_by,created_at) VALUES(?,?,?,?,?,?,?,?,?,NOW())')
        ->execute([$companyId, $id, $intro, 50, 'PENDENTE', 'Aguardando retorno ao cliente.', $next, $responsible, $userId ?: null]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    whatsapp_chat_update($pdo, $companyId, (int)$ctx['chat']['id'], ['crm_attendance_id' => $id]);
    return $id;
  }
}

if (!function_exists('whatsapp_bot_run_action')) {
  /** Ações comuns ao menu e aos fluxos: boleto, pedido, crm, setor, repetir_pedido, fluxo. */
  function whatsapp_bot_run_action(PDO $pdo, int $companyId, array $ctx, array $cfg, array $chat, string $action, string $msg, ?array $node): string {
    $chatId = (int)$chat['id'];
    switch ($action) {
      case 'boleto':
        whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg !== '' ? $msg : "Para enviar a 2ª via, informe o *CPF ou CNPJ* do titular (só os números).");
        whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'ASK_DOC']);
        return 'opção boleto';
      case 'pedido':
        whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg !== '' ? $msg : "Informe o *número do pedido*.");
        whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'ASK_ORDER']);
        return 'opção pedido';
      case 'crm':
        whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg !== '' ? $msg : "Descreva em *uma mensagem* o que você precisa, que eu abro um atendimento para a nossa equipe.");
        whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'ASK_CRM']);
        return 'opção crm';
      case 'setor':
        whatsapp_chat_enqueue($pdo, $companyId, $ctx, $cfg, $node ? (((int)($node['department_id'] ?? 0)) ?: null) : null, $msg);
        return 'fila setor ' . (int)($node['department_id'] ?? 0);
      case 'repetir_pedido':
        if ($msg !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg);
        $personId = (int)($chat['person_id'] ?? 0);
        if ($personId <= 0 && $ctx['phone'] !== '') { $p = whatsapp_bot_find_person($pdo, $companyId, $ctx['phone']); $personId = $p ? (int)$p['id'] : 0; }
        if ($personId > 0 || whatsapp_bot_last_order($pdo, $companyId, null, $ctx['phone'])) {
          return whatsapp_bot_repeat_offer($pdo, $companyId, $ctx, $chat, $personId ?: null);
        }
        whatsapp_chat_send_text($pdo, $companyId, $ctx, "Para localizar seu último pedido, informe o *CPF ou CNPJ* do cadastro (só os números).");
        whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'ASK_DOC_REPEAT']);
        return 'repetir: pede documento';
      case 'fluxo':
        $flowId = (int)($node['flow_id'] ?? 0);
        if ($flowId > 0 && whatsapp_bot_flows_ready($pdo) && whatsapp_bot_flow($pdo, $companyId, $flowId)) {
          if ($msg !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg);
          return whatsapp_bot_flow_start($pdo, $companyId, $ctx, $cfg, $chat, $flowId);
        }
        whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
        return 'fluxo inexistente';
    }
    return 'sem ação';
  }
}

if (!function_exists('whatsapp_chat_enqueue')) {
  /** Transfere a conversa para a fila de um setor (null = qualquer atendente). */
  function whatsapp_chat_enqueue(PDO $pdo, int $companyId, array $ctx, array $cfg, ?int $departmentId, string $customText = ''): void {
    $name = whatsapp_department_name($pdo, $companyId, $departmentId) ?: 'nossa equipe';
    $text = trim($customText) !== '' ? $customText : (string)$cfg['queue_text'];
    // Fora do horário de atendimento: avisa que a resposta vem no próximo horário.
    if (!whatsapp_bot_hours_open($cfg)) $text = (string)$cfg['offhours_queue_text'];
    whatsapp_chat_send_text($pdo, $companyId, $ctx, strtr($text, ['{setor}' => $name]));
    $now = date('Y-m-d H:i:s');
    whatsapp_chat_update($pdo, $companyId, (int)$ctx['chat']['id'], [
      'status' => 'PENDENTE', 'state' => 'MENU', 'data' => [], 'department_id' => $departmentId, 'user_id' => null,
      'queued_at' => $now, 'accepted_at' => null, 'closed_at' => null, 'unread' => 1,
    ]);
    $fresh = whatsapp_chat_get($pdo, $companyId, (int)$ctx['chat']['id']) ?? $ctx['chat'];
    $answers = function_exists('whatsapp_bot_chat_answers') ? whatsapp_bot_chat_answers($fresh) : [];
    whatsapp_chat_log($pdo, $companyId, $ctx['chat'], 'OUT', 'sistema', 'Conversa transferida pelo robô para ' . $name . '.' . ($answers ? "\nRespostas do cliente:\n" . whatsapp_bot_answers_summary($answers) : ''));
  }
}

if (!function_exists('whatsapp_bot_show_menu')) {
  function whatsapp_bot_show_menu(PDO $pdo, int $companyId, array $ctx, array $cfg, ?int $parentId, bool $welcome): string {
    $menu = whatsapp_bot_menu_text($pdo, $companyId, $parentId);
    if ($welcome) {
      $name = trim((string)$ctx['push_name']);
      $text = strtr((string)$cfg['welcome'], ['{nome}' => $name !== '' ? ', ' . $name : '', '{empresa}' => whatsapp_bot_company_name($pdo, $companyId)]);
    } elseif ($parentId) {
      $node = whatsapp_bot_node($pdo, $companyId, $parentId);
      $text = trim((string)($node['message'] ?? '')) ?: '*' . ($node['label'] ?? 'Menu') . '* - escolha uma opção:';
    } else {
      $text = "Escolha uma opção (responda com o número):";
    }
    whatsapp_chat_send_text($pdo, $companyId, $ctx, $text . ($menu !== '' ? "\n\n" . $menu : ''));
    return $menu;
  }
}

/* ------------------------------------------------------------------------- */
/* Entrada de mensagens (webhook)                                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('whatsapp_bot_lock')) {
  function whatsapp_bot_lock(PDO $pdo, string $name, bool $release = false): void {
    try {
      $st = $pdo->prepare($release ? 'SELECT RELEASE_LOCK(?)' : 'SELECT GET_LOCK(?,15)');
      $st->execute([$name]);
      $st->fetchColumn();
      $st->closeCursor();
    } catch (Throwable $e) {}
  }
}

if (!function_exists('whatsapp_bot_payload_contact')) {
  function whatsapp_bot_payload_contact(array $payload): array {
    $contact = substr(trim((string)($payload['reply_jid'] ?? '')), 0, 80);
    $phone = whatsapp_bot_digits($payload['from'] ?? '');
    if ($contact === '' && $phone !== '') $contact = $phone . '@s.whatsapp.net';
    return [$contact, $phone];
  }
}

if (!function_exists('whatsapp_bot_human_reply')) {
  /**
   * Alguém da empresa escreveu pelo celular/WhatsApp Web: a mensagem entra na
   * conversa e o robô fica em silêncio (a conversa passa a "em atendimento").
   */
  function whatsapp_bot_human_reply(PDO $pdo, array $payload): string {
    if (!whatsapp_bot_ready($pdo)) return 'tabelas ausentes';
    $sid = whatsapp_bot_digits($payload['company_id'] ?? '');
    $companyId = whatsapp_bot_company_by_session($pdo, $sid);
    if ($companyId <= 0) return 'empresa não encontrada';
    if (!whatsapp_bot_module_enabled($pdo, $companyId)) return 'módulo não liberado';
    [$contact, $phone] = whatsapp_bot_payload_contact($payload);
    if ($contact === '') return 'sem contato';
    $messageId = (string)($payload['message_id'] ?? '');
    if (whatsapp_chat_message_exists($pdo, $companyId, $messageId)) return 'repetida';
    $type = (string)($payload['type'] ?? 'text');
    $wa = whatsapp_load_config($pdo, $companyId, false, true);
    $media = whatsapp_chat_media_from_payload($companyId, $wa, $sid, $payload);
    $lock = 'nexa_wa_chat_' . $companyId . '_' . md5($phone !== '' ? $phone : $contact);
    whatsapp_bot_lock($pdo, $lock);
    try {
      $chat = whatsapp_chat_find_or_create($pdo, $companyId, $contact, $phone);
      $body = trim((string)($payload['text'] ?? ''));
      if (!empty($media['media_error'])) $body = trim($body . "\n[" . $media['media_error'] . ']');
      unset($media['media_error']);
      if (!whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'celular', $body, array_merge($media, ['message_id' => $messageId, 'type' => $type]))) return 'repetida';
      $now = date('Y-m-d H:i:s');
      $fields = ['human_at' => $now, 'paused_until' => null];
      if ($chat['status'] !== 'ATENDIMENTO') {
        $fields += ['status' => 'ATENDIMENTO', 'accepted_at' => $now, 'closed_at' => null, 'state' => 'MENU', 'data' => []];
      }
      whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], $fields);
      whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'ATENDIMENTO']);
      return 'celular';
    } finally {
      whatsapp_bot_lock($pdo, $lock, true);
    }
  }
}

if (!function_exists('whatsapp_bot_handle')) {
  /**
   * Processa uma mensagem recebida do cliente (payload do motor).
   * Retorna uma descrição curta do que foi feito (para log/teste).
   */
  function whatsapp_bot_handle(PDO $pdo, array $payload): string {
    if (!whatsapp_bot_ready($pdo)) return 'tabelas ausentes';
    $sid = whatsapp_bot_digits($payload['company_id'] ?? '');
    $companyId = whatsapp_bot_company_by_session($pdo, $sid);
    if ($companyId <= 0) return 'empresa não encontrada';
    if (!whatsapp_bot_module_enabled($pdo, $companyId)) return 'módulo não liberado';
    $cfg = whatsapp_bot_config($pdo, $companyId);
    [$contact, $phone] = whatsapp_bot_payload_contact($payload);
    if ($contact === '') return 'sem contato';
    $messageId = substr((string)($payload['message_id'] ?? ''), 0, 80);
    if (whatsapp_chat_message_exists($pdo, $companyId, $messageId)) return 'repetida';
    $text = trim((string)($payload['text'] ?? ''));
    $type = (string)($payload['type'] ?? 'text');
    $wa = whatsapp_load_config($pdo, $companyId, false, true);
    $media = whatsapp_chat_media_from_payload($companyId, $wa, $sid, $payload);

    $lock = 'nexa_wa_chat_' . $companyId . '_' . md5($phone !== '' ? $phone : $contact);
    whatsapp_bot_lock($pdo, $lock);
    try {
      $chat = whatsapp_chat_find_or_create($pdo, $companyId, $contact, $phone);
      if (!$chat) return 'falha ao abrir conversa';
      $prevAt = (string)($chat['last_message_at'] ?? '');
      $upd = [];
      if ($phone !== '' && $phone !== (string)$chat['phone']) $upd['phone'] = $phone;
      $pushName = mb_substr(trim((string)($payload['push_name'] ?? '')), 0, 120);
      if ($pushName !== '' && $pushName !== (string)$chat['push_name']) $upd['push_name'] = $pushName;
      if ((empty($chat['person_id']) || empty($chat['contact_id'])) && ($phone !== '' || !empty($chat['phone']))) {
        $upd += whatsapp_bot_contact_link($pdo, $companyId, $chat, $phone !== '' ? $phone : (string)$chat['phone']);
      }
      if ($upd) { whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], $upd); $chat = array_merge($chat, $upd); }

      $body = $text;
      if (!empty($media['media_error'])) $body = trim($body . "\n[" . $media['media_error'] . ']');
      unset($media['media_error']);
      if (!whatsapp_chat_log($pdo, $companyId, $chat, 'IN', 'cliente', $body, array_merge($media, ['message_id' => $messageId, 'type' => $type]))) return 'repetida';

      $now = time();
      $status = (string)$chat['status'];
      // Respondida pelo celular há mais tempo que o configurado e sem atendente na tela: volta ao robô.
      if ($status === 'ATENDIMENTO' && empty($chat['user_id']) && !empty($chat['human_at'])
        && strtotime((string)$chat['human_at']) < $now - (int)$cfg['pause_hours'] * 3600) {
        $status = 'FINALIZADO';
      }
      // Na fila ou com atendente: o robô fica quieto; a tela avisa a nova mensagem.
      if ($status === 'PENDENTE' || $status === 'ATENDIMENTO') {
        $pdo->prepare('UPDATE whatsapp_bot_sessions SET unread=unread+1 WHERE company_id=? AND id=?')->execute([$companyId, (int)$chat['id']]);
        whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => $status, 'new_message' => true]);
        return $status === 'PENDENTE' ? 'na fila' : 'em atendimento humano';
      }

      $ctx = whatsapp_chat_ctx($pdo, $companyId, $chat);
      // Robô desligado: toda conversa nova vai direto para a fila geral.
      if (!$cfg['enabled']) {
        if (!whatsapp_bot_hours_open($cfg)) whatsapp_chat_send_text($pdo, $companyId, $ctx, whatsapp_bot_offhours_message($pdo, $companyId, $cfg, (string)$ctx['push_name']));
        $now2 = date('Y-m-d H:i:s');
        whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], ['status' => 'PENDENTE', 'department_id' => null, 'user_id' => null, 'queued_at' => $now2, 'accepted_at' => null, 'closed_at' => null, 'unread' => 1, 'state' => 'MENU', 'data' => []]);
        whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'PENDENTE', 'new_message' => true]);
        return 'fila (robô desligado)';
      }

      $result = whatsapp_bot_step($pdo, $companyId, $ctx, $cfg, $chat, $status, $prevAt, $text, $type);
      whatsapp_chat_notify($companyId, (int)$chat['id'], ['status' => 'BOT']);
      return $result;
    } finally {
      whatsapp_bot_lock($pdo, $lock, true);
    }
  }
}

if (!function_exists('whatsapp_bot_step')) {
  /** Um passo do robô (conversa com status BOT ou FINALIZADO). */
  function whatsapp_bot_step(PDO $pdo, int $companyId, array $ctx, array $cfg, array $chat, string $status, string $prevAt, string $text, string $type): string {
    $chatId = (int)$chat['id'];
    $now = time();
    $data = is_array($chat['data'] ?? null) ? $chat['data'] : [];
    $node = (int)($data['node'] ?? 0) ?: null; // submenu atual
    $state = (string)$chat['state'];
    $norm = whatsapp_bot_normalize($text);
    $save = static function (array $f) use ($pdo, $companyId, $chatId): void { whatsapp_chat_update($pdo, $companyId, $chatId, $f); };

    $restart = $status === 'FINALIZADO' || in_array($state, ['NOVA', 'FIM', 'ATENDENTE'], true)
      || $prevAt === '' || strtotime($prevAt) < $now - WHATSAPP_BOT_SESSION_MINUTES * 60;
    if ($restart) {
      $reset = ['status' => 'BOT', 'state' => 'MENU', 'data' => [], 'department_id' => null, 'user_id' => null, 'closed_at' => null, 'unread' => 0, 'crm_attendance_id' => null];
      if (whatsapp_bot_flows_ready($pdo)) $reset['answers_json'] = null;
      $save($reset);
      $chat = array_merge($chat, $reset, ['answers_json' => null, 'state' => 'MENU', 'data' => []]);
      if (!whatsapp_bot_hours_open($cfg)) {
        whatsapp_chat_send_text($pdo, $companyId, $ctx, whatsapp_bot_offhours_message($pdo, $companyId, $cfg, (string)$ctx['push_name']));
        if (empty($cfg['offhours_menu'])) {
          // Sem o menu fora do horário: a conversa fica na fila para a equipe responder depois.
          $now2 = date('Y-m-d H:i:s');
          whatsapp_chat_update($pdo, $companyId, $chatId, ['status' => 'PENDENTE', 'department_id' => null, 'user_id' => null, 'queued_at' => $now2, 'accepted_at' => null, 'closed_at' => null, 'unread' => 1, 'state' => 'MENU', 'data' => []]);
          whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'sistema', 'Mensagem recebida fora do horário de atendimento: conversa colocada na fila.');
          whatsapp_chat_notify($companyId, $chatId, ['status' => 'PENDENTE', 'new_message' => true]);
          return 'fora do horário (fila)';
        }
      }
      // Frase de gatilho (ex.: mensagem pronta de anúncio) inicia o fluxo direto.
      $flow = whatsapp_bot_flow_match($pdo, $companyId, $text);
      if ($flow) return whatsapp_bot_flow_start($pdo, $companyId, $ctx, $cfg, $chat, (int)$flow['id']);
      $menu = whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, true);
      if ($menu === '') { // empresa sem nenhuma opção cadastrada: vai direto para a fila
        whatsapp_chat_enqueue($pdo, $companyId, $ctx, $cfg, null);
        return 'fila (sem menu)';
      }
      return 'boas-vindas';
    }
    if ($state === 'MENU' && ($flow = whatsapp_bot_flow_match($pdo, $companyId, $text))) {
      return whatsapp_bot_flow_start($pdo, $companyId, $ctx, $cfg, $chat, (int)$flow['id']);
    }
    // Durante as perguntas de um fluxo, "oi"/"obrigado" podem ser respostas: só "menu" interrompe.
    $menuWords = $state === 'FLOW' ? ['menu', 'inicio'] : ['menu', 'inicio', 'oi', 'ola', 'bom dia', 'boa tarde', 'boa noite'];
    // Saudação com pontuação ou frase curta ("Oi!", "Olá, boa tarde") também volta ao menu,
    // exceto quando o robô espera um texto livre (fluxo ou descrição do CRM).
    $plain = trim((string)preg_replace('/\s+/', ' ', (string)preg_replace('/[^a-z0-9 ]+/', ' ', $norm)));
    $greeting = in_array($plain, $menuWords, true)
      || (!in_array($state, ['FLOW', 'ASK_CRM'], true) && mb_strlen($plain) <= 40 && preg_match('/^(oi|ola|opa|bom dia|boa tarde|boa noite|menu|inicio)( |$)/', $plain));
    if (in_array($norm, $menuWords, true) || $greeting) {
      $save(['state' => 'MENU', 'data' => []]);
      whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
      return 'menu';
    }
    if (in_array($norm, $state === 'FLOW' ? ['sair'] : ['sair', 'encerrar', 'tchau', 'obrigado', 'obrigada'], true)) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Obrigado pelo contato! Quando precisar, é só mandar uma mensagem. 👋");
      $save(['state' => 'FIM', 'data' => []]);
      return 'encerrado';
    }
    if (!in_array($type, ['text', 'button', 'list'], true) && !in_array($state, ['ASK_CRM', 'FLOW'], true)) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Recebi seu arquivo. 🙂 Para eu entender, responda com o *número* de uma opção ou digite *menu*.");
      return 'mídia';
    }

    switch ($state) {
      case 'FLOW':
        return whatsapp_bot_flow_answer($pdo, $companyId, $ctx, $cfg, $chat, $text, $type);

      case 'ASK_DOC_REPEAT':
        $doc = whatsapp_bot_digits($text);
        if (!whatsapp_bot_valid_cpf($doc) && !whatsapp_bot_valid_cnpj($doc)) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "CPF/CNPJ inválido. Digite novamente só os números, ou *menu* para voltar.");
          return 'documento inválido';
        }
        $attempts = (int)$chat['doc_attempts'];
        $since = !empty($chat['doc_attempts_since']) ? strtotime((string)$chat['doc_attempts_since']) : 0;
        $sinceStr = (string)($chat['doc_attempts_since'] ?? '');
        if ($since < $now - 3600) { $attempts = 0; $sinceStr = date('Y-m-d H:i:s'); }
        $attempts++;
        $save(['doc_attempts' => $attempts, 'doc_attempts_since' => $sinceStr, 'state' => 'MENU']);
        if ($attempts > WHATSAPP_BOT_DOC_ATTEMPTS_PER_HOUR) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Muitas consultas seguidas. Tente novamente mais tarde ou fale com um atendente pelo menu. Digite *menu*.");
          return 'limite de consultas';
        }
        $q = $pdo->prepare("SELECT id FROM finance_people WHERE company_id=? AND REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(document,''),'.',''),'-',''),'/',''),' ','')=? ORDER BY id LIMIT 1");
        $q->execute([$companyId, $doc]);
        $pid = (int)$q->fetchColumn();
        if ($pid <= 0) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não encontrei cadastro com este CPF/CNPJ. Digite *menu* e escolha falar com um atendente, se preferir.");
          return 'repetir: sem cadastro';
        }
        return whatsapp_bot_repeat_offer($pdo, $companyId, $ctx, $chat, $pid);

      case 'CONFIRM_REPEAT':
        if (trim($text) === '1' || in_array($norm, ['sim', 's', 'confirmo', 'confirmar'], true)) {
          $save(['state' => 'MENU', 'data' => []]);
          try {
            $new = whatsapp_bot_repeat_create($pdo, $companyId, $ctx, (int)($data['order'] ?? 0));
            whatsapp_chat_send_text($pdo, $companyId, $ctx, "Pedido nº *" . $new['number'] . "* recebido! ✅\nTotal: *" . whatsapp_bot_money($new['total']) . "*\nA loja vai confirmar e avisar por aqui.\n\nDigite *menu* para ver as outras opções.");
            whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'sistema', 'Pedido nº ' . $new['number'] . ' criado pelo robô (repetição do último pedido), aguardando confirmação da loja.');
            return 'repetir: pedido ' . $new['id'];
          } catch (Throwable $e) {
            whatsapp_chat_enqueue($pdo, $companyId, $ctx, $cfg, null, "Não consegui registrar o pedido agora, mas já encaminhei para *{setor}*. Em instantes alguém fala com você por aqui.");
            return 'repetir: erro ' . $e->getMessage();
          }
        }
        if (trim($text) === '2' || in_array($norm, ['nao', 'n', 'cancelar'], true)) {
          $save(['state' => 'MENU', 'data' => []]);
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Tudo bem, não fiz o pedido. Digite *menu* para ver as outras opções.");
          return 'repetir: recusado';
        }
        whatsapp_chat_send_text($pdo, $companyId, $ctx, "Responda *1* para fazer o pedido ou *2* para não fazer.");
        return 'repetir: aguardando';

      case 'ASK_DOC':
        $doc = whatsapp_bot_digits($text);
        if ($doc === '') { // sem números: o cliente mudou de assunto, volta ao menu
          $save(['state' => 'MENU', 'data' => []]);
          whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
          return 'menu';
        }
        if (!whatsapp_bot_valid_cpf($doc) && !whatsapp_bot_valid_cnpj($doc)) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "CPF/CNPJ inválido. Digite novamente só os números, ou *menu* para voltar.");
          return 'documento inválido';
        }
        $attempts = (int)$chat['doc_attempts'];
        $since = !empty($chat['doc_attempts_since']) ? strtotime((string)$chat['doc_attempts_since']) : 0;
        $sinceStr = (string)($chat['doc_attempts_since'] ?? '');
        if ($since < $now - 3600) { $attempts = 0; $sinceStr = date('Y-m-d H:i:s'); }
        $attempts++;
        $save(['doc_attempts' => $attempts, 'doc_attempts_since' => $sinceStr, 'state' => 'MENU']);
        if ($attempts > WHATSAPP_BOT_DOC_ATTEMPTS_PER_HOUR) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Muitas consultas seguidas. Tente novamente mais tarde ou fale com um atendente pelo menu. Digite *menu*.");
          return 'limite de consultas';
        }
        whatsapp_chat_send_text($pdo, $companyId, $ctx, "Um momento, estou buscando os boletos... ⏳");
        if (!empty($cfg['group_receivables'])) {
          // Grupo Empresarial: lista numerada com as contas das empresas do grupo; o cliente escolhe.
          $picks = whatsapp_bot_list_receivables($pdo, $companyId, $ctx, $cfg, $doc);
          if ($picks) $save(['state' => 'PICK_BOLETO', 'data' => array_merge($data, ['picks' => $picks])]);
          return 'lista de contas';
        }
        whatsapp_bot_send_boletos($pdo, $companyId, $ctx, $doc);
        return 'boletos';

      case 'PICK_BOLETO':
        $picks = array_values(array_filter((array)($data['picks'] ?? []), static fn($p) => is_array($p) && (int)($p['c'] ?? 0) > 0 && (int)($p['r'] ?? 0) > 0));
        if (!$picks) { $save(['state' => 'MENU', 'data' => []]); whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false); return 'menu'; }
        if (in_array($norm, ['todos', 'todas', 'tudo'], true)) {
          $chosen = array_keys($picks);
        } else {
          $chosen = [];
          foreach (preg_split('/\D+/', $text) ?: [] as $n) if ($n !== '' && isset($picks[(int)$n - 1])) $chosen[] = (int)$n - 1;
          $chosen = array_values(array_unique($chosen));
        }
        if (!$chosen && whatsapp_bot_digits($text) === '') { // texto sem números: volta ao menu
          $save(['state' => 'MENU', 'data' => []]);
          whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
          return 'menu';
        }
        if (!$chosen) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não entendi. Responda com o *número* da conta (de 1 a " . count($picks) . "), vários números (ex.: 1,3) ou *todos*. Digite *menu* para voltar.");
          return 'opção inválida';
        }
        $sent = 0; $without = []; $names = [];
        foreach (array_slice($chosen, 0, 5) as $idx) {
          $p = $picks[$idx];
          $names[$p['c']] = $names[$p['c']] ?? whatsapp_bot_company_name($pdo, (int)$p['c']);
          if (whatsapp_bot_send_one_boleto($pdo, $companyId, $ctx, (int)$p['c'], (int)$p['r'], (int)$p['c'] !== $companyId ? $names[$p['c']] : '')) $sent++;
          else $without[] = $idx + 1;
        }
        $msg = $sent > 0 ? "Pronto! Enviei " . $sent . " boleto(s)." : '';
        if (count($chosen) > 5) $msg .= ($msg !== '' ? "\n" : '') . "Envio no máximo 5 por vez: peça os demais em seguida.";
        if ($without) $msg .= ($msg !== '' ? "\n\n" : '') . "A(s) conta(s) " . implode(', ', $without) . " não têm boleto disponível para envio automático. Se precisar, fale com um atendente pelo menu.";
        whatsapp_chat_send_text($pdo, $companyId, $ctx, trim($msg) . "\n\nSe quiser outra conta, responda com o número ou digite *menu*.");
        return 'boletos escolhidos';

      case 'ASK_ORDER':
        $num = whatsapp_bot_digits($text);
        if ($num === '' || !whatsapp_bot_order_status($pdo, $companyId, $ctx, $num)) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não encontrei esse pedido. Confira o número e envie de novo, ou digite *menu* para voltar.");
          return 'pedido não encontrado';
        }
        $save(['state' => 'MENU']);
        return 'pedido';

      case 'ASK_CRM':
        if (mb_strlen($text) < 5) {
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Descreva em uma mensagem o que você precisa (pelo menos algumas palavras).");
          return 'texto curto';
        }
        $save(['state' => 'MENU']);
        try {
          $protocol = whatsapp_bot_open_crm($pdo, $companyId, $ctx, $cfg, $text);
          whatsapp_chat_send_text($pdo, $companyId, $ctx, "Atendimento aberto! ✅\nSeu protocolo é o nº *" . $protocol . "*.\nNossa equipe vai retornar em breve.\n\nDigite *menu* para ver as outras opções.");
          return 'protocolo ' . $protocol;
        } catch (Throwable $e) {
          whatsapp_chat_enqueue($pdo, $companyId, $ctx, $cfg, null, "Não consegui registrar agora, mas já encaminhei sua mensagem para *{setor}*. Em instantes alguém fala com você por aqui.");
          return 'erro crm: ' . $e->getMessage();
        }
    }

    // MENU: escolha de uma opção do menu atual.
    $items = whatsapp_bot_nodes($pdo, $companyId, $node);
    $digits = whatsapp_bot_digits($text);
    if ($node && $digits === '0' && trim($text) === '0') {
      $current = whatsapp_bot_node($pdo, $companyId, $node);
      $parent = $current && !empty($current['parent_id']) ? (int)$current['parent_id'] : null;
      $save(['state' => 'MENU', 'data' => $parent ? ['node' => $parent] : []]);
      whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, $parent, false);
      return 'voltar';
    }
    $choice = (int)$digits;
    $item = ($digits !== '' && $digits === trim($text) && $choice >= 1 && $choice <= count($items)) ? $items[$choice - 1] : null;
    if (!$item) {
      // Se a opção não existe mais (menu alterado), volta ao início.
      if (!$items) { $save(['data' => []]); whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false); return 'menu (vazio)'; }
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não entendi. 🤔 Responda com o *número* de uma das opções:\n\n" . whatsapp_bot_menu_text($pdo, $companyId, $node));
      return 'opção inválida';
    }
    $msg = trim((string)($item['message'] ?? ''));
    if ((string)$item['action'] === 'submenu') {
      $save(['state' => 'MENU', 'data' => ['node' => (int)$item['id']]]);
      whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, (int)$item['id'], false);
      return 'submenu ' . $item['id'];
    }
    if ((string)$item['action'] === 'text') {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, ($msg !== '' ? $msg : $item['label']) . "\n\nDigite *menu* para ver as outras opções" . ($node ? ' ou *0* para voltar.' : '.'));
      return 'texto ' . $item['id'];
    }
    return whatsapp_bot_run_action($pdo, $companyId, $ctx, $cfg, $chat, (string)$item['action'], $msg, $item);
    return 'sem ação';
  }
}
