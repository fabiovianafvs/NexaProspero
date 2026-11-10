<?php
declare(strict_types=1);

/**
 * Chatbot do WhatsApp - fluxos e "repetir meu último pedido".
 *
 * Fluxo = sequência de passos (perguntas, mensagens e uma ação final, como
 * transferir para um setor). Pode ser iniciado por uma FRASE DE GATILHO (ex.:
 * mensagem pronta de um anúncio: "Olá, gostaria de conhecer melhor a solução")
 * ou por uma opção do menu (ação "fluxo"). As respostas ficam na conversa
 * (whatsapp_bot_sessions.answers_json) e aparecem para o atendente.
 *
 * Usado por config/whatsapp_chatbot.php (whatsapp_bot_step).
 */

if (!function_exists('whatsapp_bot_flows_ready')) {
  function whatsapp_bot_flows_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('whatsapp_bot_flows','whatsapp_bot_flow_steps')");
      if ((int)$st->fetchColumn() !== 2) return $ready = false;
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND ((table_name='whatsapp_bot_sessions' AND column_name='answers_json') OR (table_name='whatsapp_bot_nodes' AND column_name='flow_id'))");
      return $ready = ((int)$st->fetchColumn() === 2);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('whatsapp_bot_step_types')) {
  function whatsapp_bot_step_types(): array {
    return [
      'pergunta' => 'Fazer uma pergunta (espera a resposta)',
      'text' => 'Enviar mensagem (segue para o próximo passo)',
      'setor' => 'Transferir para setor (atendente) — encerra o fluxo',
      'menu' => 'Mostrar o menu principal — encerra o fluxo',
      'boleto' => '2ª via de boleto (pede CPF/CNPJ) — encerra o fluxo',
      'pedido' => 'Consultar pedido — encerra o fluxo',
      'repetir_pedido' => 'Repetir o último pedido — encerra o fluxo',
      'crm' => 'Abrir atendimento no CRM — encerra o fluxo',
    ];
  }
}

if (!function_exists('whatsapp_bot_answer_types')) {
  function whatsapp_bot_answer_types(): array {
    return ['texto' => 'Texto livre', 'numero' => 'Número', 'email' => 'E-mail', 'cpf_cnpj' => 'CPF ou CNPJ', 'telefone' => 'Telefone'];
  }
}

if (!function_exists('whatsapp_bot_text_key')) {
  /** Texto normalizado para comparar frases (sem acento, pontuação e espaços extras). */
  function whatsapp_bot_text_key(string $text): string {
    $t = whatsapp_bot_normalize($text);
    $t = strtr($t, ['è'=>'e','ì'=>'i','ò'=>'o','ù'=>'u','ü'=>'u','ñ'=>'n']);
    $t = preg_replace('/[^a-z0-9]+/', ' ', $t) ?? '';
    return trim(preg_replace('/\s+/', ' ', $t) ?? '');
  }
}

/* ----------------------------- cadastro ---------------------------------- */

if (!function_exists('whatsapp_bot_flows')) {
  function whatsapp_bot_flows(PDO $pdo, int $companyId, bool $activeOnly = false): array {
    $st = $pdo->prepare('SELECT f.*, (SELECT COUNT(*) FROM whatsapp_bot_flow_steps s WHERE s.company_id=f.company_id AND s.flow_id=f.id) AS steps_count FROM whatsapp_bot_flows f WHERE f.company_id=?' . ($activeOnly ? ' AND f.active=1' : '') . ' ORDER BY f.sort_order, f.id');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('whatsapp_bot_flow')) {
  function whatsapp_bot_flow(PDO $pdo, int $companyId, int $flowId): ?array {
    if ($flowId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_flows WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $flowId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('whatsapp_bot_flow_save')) {
  function whatsapp_bot_flow_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $name = mb_substr(trim((string)($in['name'] ?? '')), 0, 80);
    if ($name === '') throw new RuntimeException('Informe o nome do fluxo.');
    $lines = [];
    foreach (preg_split('/\R/', (string)($in['triggers'] ?? '')) ?: [] as $l) {
      $l = mb_substr(trim($l), 0, 200);
      if ($l !== '' && mb_strlen(whatsapp_bot_text_key($l)) >= 2) $lines[] = $l;
    }
    $triggers = implode("\n", array_slice(array_values(array_unique($lines)), 0, 30));
    $mode = ($in['match_mode'] ?? '') === 'exata' ? 'exata' : 'contem';
    $active = !empty($in['active']) ? 1 : 0;
    if ($id > 0) {
      if (!whatsapp_bot_flow($pdo, $companyId, $id)) throw new RuntimeException('Fluxo não encontrado.');
      $pdo->prepare('UPDATE whatsapp_bot_flows SET name=?, triggers=?, match_mode=?, active=? WHERE company_id=? AND id=?')
        ->execute([$name, $triggers !== '' ? $triggers : null, $mode, $active, $companyId, $id]);
      return $id;
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM whatsapp_bot_flows WHERE company_id=?');
    $max->execute([$companyId]);
    $pdo->prepare('INSERT INTO whatsapp_bot_flows (company_id, name, triggers, match_mode, active, sort_order, created_at) VALUES (?,?,?,?,?,?,?)')
      ->execute([$companyId, $name, $triggers !== '' ? $triggers : null, $mode, $active, (int)$max->fetchColumn(), date('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('whatsapp_bot_flow_delete')) {
  function whatsapp_bot_flow_delete(PDO $pdo, int $companyId, int $flowId): void {
    $pdo->prepare('DELETE FROM whatsapp_bot_flow_steps WHERE company_id=? AND flow_id=?')->execute([$companyId, $flowId]);
    // Opções do menu que abriam este fluxo ficam inativas (para não ficarem sem ação).
    $pdo->prepare('UPDATE whatsapp_bot_nodes SET flow_id=NULL, active=0 WHERE company_id=? AND flow_id=?')->execute([$companyId, $flowId]);
    $pdo->prepare('DELETE FROM whatsapp_bot_flows WHERE company_id=? AND id=?')->execute([$companyId, $flowId]);
  }
}

if (!function_exists('whatsapp_bot_flow_steps')) {
  function whatsapp_bot_flow_steps(PDO $pdo, int $companyId, int $flowId, bool $activeOnly = true): array {
    $st = $pdo->prepare('SELECT * FROM whatsapp_bot_flow_steps WHERE company_id=? AND flow_id=?' . ($activeOnly ? ' AND active=1' : '') . ' ORDER BY sort_order, id');
    $st->execute([$companyId, $flowId]);
    return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
  }
}

if (!function_exists('whatsapp_bot_flow_step_save')) {
  function whatsapp_bot_flow_step_save(PDO $pdo, int $companyId, int $flowId, array $in): int {
    if (!whatsapp_bot_flow($pdo, $companyId, $flowId)) throw new RuntimeException('Fluxo não encontrado.');
    $id = (int)($in['id'] ?? 0);
    $type = (string)($in['step_type'] ?? 'pergunta');
    if (!isset(whatsapp_bot_step_types()[$type])) throw new RuntimeException('Tipo de passo inválido.');
    $message = mb_substr(trim((string)($in['message'] ?? '')), 0, 3000);
    if (in_array($type, ['pergunta', 'text'], true) && $message === '') throw new RuntimeException($type === 'pergunta' ? 'Escreva a pergunta.' : 'Escreva a mensagem.');
    $var = null;
    if ($type === 'pergunta') {
      $var = whatsapp_bot_text_key((string)($in['var_name'] ?? ''));
      $var = str_replace(' ', '_', $var);
      $var = $var !== '' ? mb_substr($var, 0, 40) : null;
    }
    $answerType = (string)($in['answer_type'] ?? 'texto');
    if (!isset(whatsapp_bot_answer_types()[$answerType])) $answerType = 'texto';
    $departmentId = $type === 'setor' ? ((int)($in['department_id'] ?? 0) ?: null) : null;
    if ($departmentId && whatsapp_department_name($pdo, $companyId, $departmentId) === '') throw new RuntimeException('Setor não encontrado.');
    $active = !empty($in['active']) ? 1 : 0;
    if ($id > 0) {
      $pdo->prepare('UPDATE whatsapp_bot_flow_steps SET step_type=?, message=?, var_name=?, answer_type=?, department_id=?, active=? WHERE company_id=? AND flow_id=? AND id=?')
        ->execute([$type, $message !== '' ? $message : null, $var, $answerType, $departmentId, $active, $companyId, $flowId, $id]);
      return $id;
    }
    $max = $pdo->prepare('SELECT COALESCE(MAX(sort_order),0)+1 FROM whatsapp_bot_flow_steps WHERE company_id=? AND flow_id=?');
    $max->execute([$companyId, $flowId]);
    $pdo->prepare('INSERT INTO whatsapp_bot_flow_steps (company_id, flow_id, sort_order, step_type, message, var_name, answer_type, department_id, active, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, $flowId, (int)$max->fetchColumn(), $type, $message !== '' ? $message : null, $var, $answerType, $departmentId, $active, date('Y-m-d H:i:s')]);
    return (int)$pdo->lastInsertId();
  }
}

if (!function_exists('whatsapp_bot_flow_step_delete')) {
  function whatsapp_bot_flow_step_delete(PDO $pdo, int $companyId, int $flowId, int $stepId): void {
    $pdo->prepare('DELETE FROM whatsapp_bot_flow_steps WHERE company_id=? AND flow_id=? AND id=?')->execute([$companyId, $flowId, $stepId]);
  }
}

if (!function_exists('whatsapp_bot_flow_step_move')) {
  function whatsapp_bot_flow_step_move(PDO $pdo, int $companyId, int $flowId, int $stepId, int $dir): void {
    $ids = array_map(static fn($s) => (int)$s['id'], whatsapp_bot_flow_steps($pdo, $companyId, $flowId, false));
    $pos = array_search($stepId, $ids, true);
    $to = $pos === false ? false : $pos + ($dir < 0 ? -1 : 1);
    if ($to === false || $to < 0 || $to >= count($ids)) return;
    [$ids[$pos], $ids[$to]] = [$ids[$to], $ids[$pos]];
    $up = $pdo->prepare('UPDATE whatsapp_bot_flow_steps SET sort_order=? WHERE company_id=? AND flow_id=? AND id=?');
    foreach ($ids as $i => $sid) $up->execute([$i + 1, $companyId, $flowId, $sid]);
  }
}

/* ------------------------------ execução --------------------------------- */

if (!function_exists('whatsapp_bot_flow_match')) {
  /** Fluxo ativo cuja frase de gatilho combina com a mensagem (o primeiro na ordem). */
  function whatsapp_bot_flow_match(PDO $pdo, int $companyId, string $text): ?array {
    if (!whatsapp_bot_flows_ready($pdo)) return null;
    $key = whatsapp_bot_text_key($text);
    if ($key === '' || preg_match('/^\d+$/', $key)) return null;
    foreach (whatsapp_bot_flows($pdo, $companyId, true) as $flow) {
      foreach (preg_split('/\R/', (string)($flow['triggers'] ?? '')) ?: [] as $trigger) {
        $t = whatsapp_bot_text_key($trigger);
        if ($t === '') continue;
        $hit = $flow['match_mode'] === 'exata' ? $key === $t : (' ' . $key . ' ' !== str_replace(' ' . $t . ' ', '', ' ' . $key . ' '));
        if ($hit) return $flow;
      }
    }
    return null;
  }
}

if (!function_exists('whatsapp_bot_chat_answers')) {
  /** Respostas do cliente: [['k'=>chave,'l'=>rótulo,'v'=>valor], ...] */
  function whatsapp_bot_chat_answers(array $chat): array {
    $a = json_decode((string)($chat['answers_json'] ?? ''), true);
    return is_array($a) ? array_values(array_filter($a, 'is_array')) : [];
  }
}

if (!function_exists('whatsapp_bot_fill')) {
  /** Troca {chave} pelas respostas já dadas ({nome} cai no nome do WhatsApp se não houver resposta). */
  function whatsapp_bot_fill(PDO $pdo, int $companyId, array $ctx, array $answers, string $text): string {
    $map = ['{empresa}' => whatsapp_bot_company_name($pdo, $companyId), '{nome}' => trim((string)$ctx['push_name'])];
    foreach ($answers as $a) if (($a['k'] ?? '') !== '') $map['{' . $a['k'] . '}'] = (string)$a['v'];
    return strtr($text, $map);
  }
}

if (!function_exists('whatsapp_bot_validate_answer')) {
  /** Retorna [ok, valor normalizado, mensagem de erro]. */
  function whatsapp_bot_validate_answer(string $type, string $text): array {
    $text = trim($text);
    if ($text === '') return [false, '', 'Responda com uma mensagem de texto, por favor.'];
    switch ($type) {
      case 'numero':
        $n = str_replace(',', '.', preg_replace('/[^\d,.\-]/', '', $text) ?? '');
        return is_numeric($n) ? [true, $text, ''] : [false, '', 'Responda só com números, por favor.'];
      case 'email':
        return filter_var($text, FILTER_VALIDATE_EMAIL) ? [true, mb_strtolower($text), ''] : [false, '', 'Esse e-mail não parece válido. Digite novamente (ex.: nome@empresa.com.br).'];
      case 'cpf_cnpj':
        $d = whatsapp_bot_digits($text);
        return (whatsapp_bot_valid_cpf($d) || whatsapp_bot_valid_cnpj($d)) ? [true, $d, ''] : [false, '', 'CPF/CNPJ inválido. Digite novamente só os números.'];
      case 'telefone':
        $d = whatsapp_bot_digits($text);
        return (strlen($d) >= 10 && strlen($d) <= 13) ? [true, $d, ''] : [false, '', 'Informe o telefone com DDD, por favor.'];
    }
    return [true, mb_substr($text, 0, 1000), ''];
  }
}

if (!function_exists('whatsapp_bot_answers_summary')) {
  function whatsapp_bot_answers_summary(array $answers): string {
    $lines = [];
    foreach ($answers as $a) $lines[] = ($a['l'] ?? $a['k'] ?? 'Resposta') . ': ' . ($a['v'] ?? '');
    return implode("\n", $lines);
  }
}

if (!function_exists('whatsapp_bot_flow_start')) {
  function whatsapp_bot_flow_start(PDO $pdo, int $companyId, array $ctx, array $cfg, array $chat, int $flowId): string {
    whatsapp_chat_log($pdo, $companyId, $chat, 'OUT', 'sistema', 'Fluxo iniciado: ' . ((whatsapp_bot_flow($pdo, $companyId, $flowId)['name'] ?? '') ?: ('#' . $flowId)) . '.');
    return whatsapp_bot_flow_run($pdo, $companyId, $ctx, $cfg, $chat, $flowId, 0, whatsapp_bot_chat_answers($chat));
  }
}

if (!function_exists('whatsapp_bot_flow_run')) {
  /**
   * Executa os passos a partir da posição $from até precisar de uma resposta
   * do cliente ou chegar a uma ação final.
   */
  function whatsapp_bot_flow_run(PDO $pdo, int $companyId, array $ctx, array $cfg, array $chat, int $flowId, int $from, array $answers): string {
    $chatId = (int)$chat['id'];
    $steps = whatsapp_bot_flow_steps($pdo, $companyId, $flowId);
    for ($i = $from; $i < count($steps); $i++) {
      $s = $steps[$i];
      $msg = whatsapp_bot_fill($pdo, $companyId, $ctx, $answers, trim((string)($s['message'] ?? '')));
      switch ((string)$s['step_type']) {
        case 'text':
          if ($msg !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg);
          continue 2;
        case 'pergunta':
          whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg);
          whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'FLOW', 'data' => ['flow' => $flowId, 'pos' => $i, 'step' => (int)$s['id']], 'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE)]);
          return 'fluxo pergunta ' . $s['id'];
        case 'setor':
          whatsapp_chat_update($pdo, $companyId, $chatId, ['answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE)]);
          $chat['answers_json'] = json_encode($answers, JSON_UNESCAPED_UNICODE);
          whatsapp_chat_enqueue($pdo, $companyId, $ctx, $cfg, ((int)($s['department_id'] ?? 0)) ?: null, $msg);
          return 'fluxo setor ' . (int)($s['department_id'] ?? 0);
        default: // menu, boleto, pedido, repetir_pedido, crm: encerra o fluxo e segue como no menu
          whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'MENU', 'data' => [], 'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE)]);
          if ($s['step_type'] === 'menu') {
            if ($msg !== '') whatsapp_chat_send_text($pdo, $companyId, $ctx, $msg);
            whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
            return 'fluxo menu';
          }
          return whatsapp_bot_run_action($pdo, $companyId, $ctx, $cfg, $chat, (string)$s['step_type'], $msg, null);
      }
    }
    // Acabaram os passos sem ação final: volta ao menu principal.
    whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'MENU', 'data' => [], 'answers_json' => json_encode($answers, JSON_UNESCAPED_UNICODE)]);
    whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
    return 'fluxo fim';
  }
}

if (!function_exists('whatsapp_bot_flow_answer')) {
  /** Recebeu a resposta de uma pergunta do fluxo. */
  function whatsapp_bot_flow_answer(PDO $pdo, int $companyId, array $ctx, array $cfg, array $chat, string $text, string $type): string {
    $data = is_array($chat['data'] ?? null) ? $chat['data'] : [];
    $flowId = (int)($data['flow'] ?? 0);
    $steps = $flowId > 0 ? whatsapp_bot_flow_steps($pdo, $companyId, $flowId) : [];
    $pos = null;
    foreach ($steps as $i => $s) if ((int)$s['id'] === (int)($data['step'] ?? 0)) { $pos = $i; break; }
    if ($pos === null) { // fluxo alterado no meio da conversa
      whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], ['state' => 'MENU', 'data' => []]);
      whatsapp_bot_show_menu($pdo, $companyId, $ctx, $cfg, null, false);
      return 'fluxo perdido';
    }
    $step = $steps[$pos];
    if (!in_array($type, ['text', 'button', 'list'], true)) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Para continuar, responda com uma mensagem de texto, por favor. 🙂");
      return 'fluxo mídia';
    }
    [$ok, $value, $error] = whatsapp_bot_validate_answer((string)$step['answer_type'], $text);
    if (!$ok) { whatsapp_chat_send_text($pdo, $companyId, $ctx, $error); return 'fluxo resposta inválida'; }
    $answers = whatsapp_bot_chat_answers($chat);
    $key = (string)($step['var_name'] ?? '');
    $label = $key !== '' ? ucfirst(str_replace('_', ' ', $key)) : mb_substr(preg_replace('/\s+/', ' ', strip_tags((string)$step['message'])) ?? '', 0, 60);
    $answers = array_values(array_filter($answers, static fn($a) => $key === '' || ($a['k'] ?? '') !== $key));
    $answers[] = ['k' => $key, 'l' => $label, 'v' => $value];
    if ($key === 'nome' && trim((string)$chat['push_name']) === '') {
      whatsapp_chat_update($pdo, $companyId, (int)$chat['id'], ['push_name' => mb_substr($value, 0, 120)]);
    }
    return whatsapp_bot_flow_run($pdo, $companyId, $ctx, $cfg, $chat, $flowId, $pos + 1, $answers);
  }
}

/* ------------------------- repetir último pedido -------------------------- */

if (!function_exists('whatsapp_bot_columns')) {
  function whatsapp_bot_columns(PDO $pdo, string $table): array {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    try {
      $st = $pdo->prepare('SELECT column_name FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ?');
      $st->execute([$table]);
      return $cache[$table] = array_map('strtolower', $st->fetchAll(PDO::FETCH_COLUMN) ?: []);
    } catch (Throwable $e) {
      return $cache[$table] = [];
    }
  }
}

if (!function_exists('whatsapp_bot_last_order')) {
  /** Último pedido (não cancelado) do cliente: pelo cadastro e/ou pelo WhatsApp. */
  function whatsapp_bot_last_order(PDO $pdo, int $companyId, ?int $personId, string $phone): ?array {
    $cols = whatsapp_bot_columns($pdo, 'pedidos');
    $conds = []; $params = [$companyId];
    if ($personId && in_array('customer_id', $cols, true)) { $conds[] = 'customer_id=?'; $params[] = $personId; }
    if ($phone !== '' && in_array('whatsapp', $cols, true)) {
      $local = strlen($phone) > 11 && str_starts_with($phone, '55') ? substr($phone, 2) : $phone;
      $conds[] = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(whatsapp,''),'(',''),')',''),'-',''),' ',''),'+','') IN (?,?)";
      $params[] = $phone; $params[] = $local;
    }
    if (!$conds) return null;
    $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND (' . implode(' OR ', $conds) . ") AND LOWER(COALESCE(status,'')) NOT IN ('cancelado','cancelada','estornado') ORDER BY id DESC LIMIT 1");
    $st->execute($params);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('whatsapp_bot_repeat_items')) {
  /** Itens do pedido com o preço ATUAL do cadastro. Retorna [itens, indisponíveis, total]. */
  function whatsapp_bot_repeat_items(PDO $pdo, int $companyId, int $orderId): array {
    $itemCols = whatsapp_bot_columns($pdo, 'pedido_itens');
    $obsCol = null;
    foreach (['complemento', 'observacao', 'obs', 'observacoes'] as $c) if (in_array($c, $itemCols, true)) { $obsCol = $c; break; }
    $st = $pdo->prepare('SELECT i.produto_id, i.qtd' . ($obsCol ? ', i.`' . $obsCol . '` AS obs' : ", '' AS obs") . ', p.nome, p.preco, p.ativo FROM pedido_itens i LEFT JOIN produtos p ON p.company_id=i.company_id AND p.id=i.produto_id WHERE i.company_id=? AND i.pedido_id=? ORDER BY i.id');
    $st->execute([$companyId, $orderId]);
    $items = []; $missing = []; $total = 0.0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
      $qty = (float)$r['qtd'];
      if ($qty <= 0) continue;
      if (empty($r['nome']) || (int)$r['ativo'] !== 1 || (float)$r['preco'] <= 0) { $missing[] = (string)($r['nome'] ?: ('Produto #' . (int)$r['produto_id'])); continue; }
      $price = round((float)$r['preco'], 2);
      $line = round($qty * $price, 2);
      $items[] = ['produto_id' => (int)$r['produto_id'], 'nome' => (string)$r['nome'], 'qtd' => $qty, 'preco' => $price, 'total' => $line, 'obs' => (string)($r['obs'] ?? '')];
      $total += $line;
    }
    return [$items, $missing, round($total, 2), $obsCol];
  }
}

if (!function_exists('whatsapp_bot_qty')) {
  function whatsapp_bot_qty(float $q): string {
    return rtrim(rtrim(number_format($q, 3, ',', '.'), '0'), ',');
  }
}

if (!function_exists('whatsapp_bot_repeat_offer')) {
  /** Mostra o último pedido com os preços atuais e pede confirmação. */
  function whatsapp_bot_repeat_offer(PDO $pdo, int $companyId, array $ctx, array $chat, ?int $personId): string {
    $chatId = (int)$chat['id'];
    $order = whatsapp_bot_last_order($pdo, $companyId, $personId, $ctx['phone']);
    if (!$order) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Não encontrei pedidos anteriores para você. 🤔\n\nDigite *menu* para ver as outras opções.");
      whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'MENU', 'data' => []]);
      return 'repetir: sem pedido';
    }
    [$items, $missing, $total] = whatsapp_bot_repeat_items($pdo, $companyId, (int)$order['id']);
    if (!$items) {
      whatsapp_chat_send_text($pdo, $companyId, $ctx, "Os produtos do seu último pedido não estão disponíveis no momento. Digite *menu* e escolha falar com um atendente, se preferir.");
      whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'MENU', 'data' => []]);
      return 'repetir: sem itens';
    }
    $fee = round((float)($order['taxa_entrega'] ?? 0), 2);
    $num = (int)($order['numero_pedido'] ?? 0) ?: (int)$order['id'];
    $lines = array_map(static fn($i) => '• ' . whatsapp_bot_qty($i['qtd']) . 'x ' . $i['nome'] . ' — ' . whatsapp_bot_money($i['total']), $items);
    $text = "Seu último pedido foi o nº *" . $num . "*" . (!empty($order['created_at']) ? ' (' . date('d/m/Y', strtotime((string)$order['created_at'])) . ')' : '') . ".\nCom os preços de hoje fica assim:\n\n" . implode("\n", $lines);
    if ($fee > 0) $text .= "\n• Entrega — " . whatsapp_bot_money($fee);
    $text .= "\n\n*Total: " . whatsapp_bot_money($total + $fee) . "*";
    if (trim((string)($order['forma_pagamento'] ?? '')) !== '') $text .= "\nPagamento: " . $order['forma_pagamento'];
    if ($missing) $text .= "\n\n⚠️ Indisponível(is) agora: " . implode(', ', array_slice($missing, 0, 5));
    $text .= "\n\nConfirma o novo pedido?\n*1* - Sim, fazer o pedido\n*2* - Não";
    whatsapp_chat_send_text($pdo, $companyId, $ctx, $text);
    whatsapp_chat_update($pdo, $companyId, $chatId, ['state' => 'CONFIRM_REPEAT', 'data' => ['order' => (int)$order['id'], 'person' => (int)($personId ?? 0)]]);
    return 'repetir: oferta';
  }
}

if (!function_exists('whatsapp_bot_repeat_create')) {
  /**
   * Cria o novo pedido EM ABERTO (igual a um pedido do catálogo online): a loja
   * confirma; estoque e financeiro só mudam quando o pedido for finalizado.
   */
  function whatsapp_bot_repeat_create(PDO $pdo, int $companyId, array $ctx, int $sourceOrderId): array {
    $st = $pdo->prepare('SELECT * FROM pedidos WHERE company_id=? AND id=? LIMIT 1');
    $st->execute([$companyId, $sourceOrderId]);
    $src = $st->fetch(PDO::FETCH_ASSOC);
    if (!$src) throw new RuntimeException('Pedido de origem não encontrado.');
    [$items, $missing, $subtotal, $obsCol] = whatsapp_bot_repeat_items($pdo, $companyId, $sourceOrderId);
    if (!$items) throw new RuntimeException('Nenhum produto disponível para repetir.');
    $fee = round((float)($src['taxa_entrega'] ?? 0), 2);
    $cols = whatsapp_bot_columns($pdo, 'pedidos');
    $srcNumber = (int)($src['numero_pedido'] ?? 0) ?: (int)$src['id'];
    $values = [
      'company_id' => $companyId,
      'observacoes' => trim('Pedido repetido pelo WhatsApp (chatbot) a partir do pedido nº ' . $srcNumber . '.' . ($missing ? ' Indisponíveis: ' . implode(', ', $missing) . '.' : '')),
      'total_produtos' => $subtotal,
      'taxa_entrega' => $fee,
      'total' => round($subtotal + $fee, 2),
      'status' => 'aberto',
      'source' => 'delivery',
    ];
    // Dados do cliente/entrega copiados do pedido anterior (só colunas que existem).
    foreach (['nome_cliente','whatsapp','endereco','bairro','tipo_entrega','forma_pagamento','payment_method_id','customer_id','customer_document','zip_code','numero','complemento','referencia','cidade','uf'] as $c) {
      if (in_array($c, $cols, true) && array_key_exists($c, $src)) $values[$c] = $src[$c];
    }
    if (($values['whatsapp'] ?? '') === '' && $ctx['phone'] !== '') $values['whatsapp'] = $ctx['phone'];
    $values = array_filter($values, static fn($v, $k) => in_array($k, $cols, true), ARRAY_FILTER_USE_BOTH);

    require_once __DIR__ . '/company_sequences.php';
    $pdo->beginTransaction();
    try {
      $number = 0;
      if (function_exists('company_sequence_has_column') && company_sequence_has_column($pdo, 'pedidos')) {
        $number = (int)company_sequence_next($pdo, $companyId, 'pedidos');
        $values['numero_pedido'] = $number;
      }
      if (!$pdo->inTransaction()) $pdo->beginTransaction();
      $names = array_keys($values);
      $pdo->prepare('INSERT INTO pedidos (`' . implode('`,`', $names) . '`) VALUES (' . implode(',', array_fill(0, count($names), '?')) . ')')->execute(array_values($values));
      $orderId = (int)$pdo->lastInsertId();
      $sti = $pdo->prepare($obsCol
        ? 'INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total, `' . $obsCol . '`) VALUES (?,?,?,?,?,?,?)'
        : 'INSERT INTO pedido_itens (company_id, pedido_id, produto_id, qtd, preco_unit, total) VALUES (?,?,?,?,?,?)');
      foreach ($items as $i) {
        $args = [$companyId, $orderId, $i['produto_id'], $i['qtd'], $i['preco'], $i['total']];
        if ($obsCol) $args[] = $i['obs'];
        $sti->execute($args);
      }
      try { $pdo->prepare('INSERT IGNORE INTO pedidos_exportacao (company_id, pedido_id) VALUES (?, ?)')->execute([$companyId, $orderId]); } catch (Throwable $e) {}
      if ($pdo->inTransaction()) $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    if ($number <= 0) $number = $orderId;
    try {
      if (!function_exists('brain_profitability_recalculate_order') && is_file(__DIR__ . '/brain_profitability.php')) require_once __DIR__ . '/brain_profitability.php';
      if (function_exists('brain_profitability_recalculate_order')) brain_profitability_recalculate_order($pdo, $companyId, $orderId, true, 'order.created');
    } catch (Throwable $e) {}
    // Avisa as telas da loja (alerta de novo pedido), depois de gravado.
    try {
      if (!function_exists('nexa_realtime_publish_event') && is_file(__DIR__ . '/brain_realtime.php')) require_once __DIR__ . '/brain_realtime.php';
      if (function_exists('nexa_realtime_publish_event')) {
        nexa_realtime_publish_event($companyId, 'delivery.order.created', 'order', $orderId, [
          'order_id' => $orderId, 'order_number' => $number, 'customer' => (string)($values['nome_cliente'] ?? ''),
          'total' => (float)$values['total'], 'subtotal' => $subtotal, 'delivery_fee' => $fee,
          'delivery_type' => (string)($values['tipo_entrega'] ?? ''), 'payment_method' => (string)($values['forma_pagamento'] ?? ''), 'source' => 'whatsapp',
        ], false);
      }
    } catch (Throwable $e) {}
    return ['id' => $orderId, 'number' => $number, 'total' => (float)$values['total']];
  }
}
