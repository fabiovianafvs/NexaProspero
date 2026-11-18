<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V8: acesso ao Nexa ligado ao ponto.
 *
 * Só vale quando a empresa liga em Ponto > Configurações (acesso_modo):
 *  - DESLIGADO (padrão): nada muda.
 *  - AVISAR: o funcionário entra normalmente, mas o uso fora da jornada fica registrado.
 *  - BLOQUEAR: fora da jornada (ou sem ter batido o ponto) o Nexa mostra a tela de bloqueio,
 *    onde dá para bater o ponto ou pedir liberação ao gestor.
 *
 * Vale só para usuários ligados a um colaborador que controla ponto. Administradores da empresa
 * ficam de fora, a não ser que a empresa marque o contrário; o administrador da plataforma
 * nunca é bloqueado. Qualquer erro na verificação libera o acesso (nunca tranca a empresa por
 * falha do sistema). A decisão fica guardada na sessão por 1 minuto (sem consultas a cada clique).
 *
 * O acesso físico (porta/catraca) entra junto com a V9 (equipamentos).
 */

if (!defined('PONTO_ACESSO_MODOS')) {
  define('PONTO_ACESSO_MODOS', ['DESLIGADO' => 'Desligado (o ponto não interfere no acesso)', 'AVISAR' => 'Só avisar e registrar', 'BLOQUEAR' => 'Bloquear (gestor pode liberar)']);
  define('PONTO_ACESSO_MOTIVOS', [
    'FORA_HORARIO' => 'Fora do horário da jornada',
    'FOLGA' => 'Dia de folga',
    'FERIADO' => 'Feriado',
    'SEM_MARCACAO' => 'Ponto de entrada não registrado',
    'INTERVALO' => 'Em intervalo (saída registrada)',
    'JORNADA_ENCERRADA' => 'Jornada encerrada (saída registrada)',
    'AFASTADO' => 'Colaborador afastado ou desligado',
  ]);
}

if (!function_exists('ponto_v8_ready')) {
  function ponto_v8_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $pdo->query('SELECT 1 FROM ponto_acesso_liberacoes WHERE 1 = 0');
      return $ready = true;
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('ponto_acesso_carregar')) {
  /** Carrega só o que a verificação usa (cadastros, marcação e períodos da jornada). */
  function ponto_acesso_carregar(): void {
    require_once __DIR__ . '/ponto_module.php';
    require_once __DIR__ . '/ponto_marcacao.php';
    require_once __DIR__ . '/ponto_apuracao.php';
  }
}

if (!function_exists('ponto_acesso_agora')) {
  /** [timestamp comparável com ponto_periodo_abs, 'Y-m-d H:i:s' local do estabelecimento]. */
  function ponto_acesso_agora(PDO $pdo, int $companyId, array $col, ?int $agora = null): array {
    $estab = !empty($col['estabelecimento_id']) ? ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']) : null;
    $dt = new DateTime('@' . ($agora ?? time()));
    $dt->setTimezone(ponto_tz($estab, $pdo, $companyId));
    $local = $dt->format('Y-m-d H:i:s');
    return [(int)strtotime($local), $local];
  }
}

if (!function_exists('ponto_acesso_agora_empresa')) {
  /** Agora no fuso padrão do ponto da empresa ('Y-m-d H:i:s'), para telas e valores padrão. */
  function ponto_acesso_agora_empresa(PDO $pdo, int $companyId): string {
    $dt = new DateTime('now');
    $dt->setTimezone(ponto_tz(null, $pdo, $companyId));
    return $dt->format('Y-m-d H:i:s');
  }
}

if (!function_exists('ponto_acesso_liberacao_ativa')) {
  function ponto_acesso_liberacao_ativa(PDO $pdo, int $companyId, int $colId, string $agoraLocal): ?array {
    $st = $pdo->prepare("SELECT * FROM ponto_acesso_liberacoes WHERE company_id = ? AND colaborador_id = ? AND status = 'APROVADA' AND inicio <= ? AND fim >= ? ORDER BY fim DESC LIMIT 1");
    $st->execute([$companyId, $colId, $agoraLocal, $agoraLocal]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_acesso_avaliar')) {
  /**
   * Decide se o usuário pode usar o Nexa agora. Devolve:
   *  controlado (a regra vale para ele), ok, modo, motivo (código), mensagem, colaborador,
   *  liberacao (ativa), janela [ini, fim] da jornada encontrada (texto), marcacoes (de hoje).
   */
  function ponto_acesso_avaliar(PDO $pdo, int $companyId, int $userId, string $role, ?int $agora = null): array {
    $r = ['controlado' => false, 'ok' => true, 'modo' => 'DESLIGADO', 'motivo' => '', 'mensagem' => '', 'colaborador' => null, 'liberacao' => null, 'janela' => '', 'marcacoes' => 0];
    if ($userId <= 0 || $role === 'platform_admin') return $r;
    ponto_acesso_carregar();
    $cfg = ponto_config($pdo, $companyId);
    $modo = (string)($cfg['acesso_modo'] ?? 'DESLIGADO');
    if (!isset(PONTO_ACESSO_MODOS[$modo]) || $modo === 'DESLIGADO') return $r;
    $r['modo'] = $modo;
    if ($role === 'company_admin' && empty($cfg['acesso_admins'])) return $r;
    if (!ponto_ready($pdo) || !ponto_v2_ready($pdo) || !ponto_v8_ready($pdo)) return $r;
    $col = ponto_colaborador_do_usuario($pdo, $companyId, $userId);
    if (!$col || empty($col['controla_ponto']) || !empty($col['acesso_livre'])) return $r;
    $r['controlado'] = true;
    $r['colaborador'] = $col;
    return array_merge($r, ponto_acesso_regra_colaborador($pdo, $companyId, $col, $cfg, $agora, true));
    return $r;
  }
}

if (!function_exists('ponto_acesso_regra_colaborador')) {
  /**
   * A regra da jornada para um colaborador: liberação do gestor, situação, janela da jornada
   * (com tolerâncias) e, se $usaMarcacoes, o ponto de entrada/saída. O acesso físico (V9) usa
   * sem as marcações, porque a pessoa passa pela porta antes de bater o ponto.
   */
  function ponto_acesso_regra_colaborador(PDO $pdo, int $companyId, array $col, array $cfg, ?int $agora = null, bool $usaMarcacoes = true): array {
    $r = ['ok' => true, 'motivo' => '', 'mensagem' => '', 'liberacao' => null, 'janela' => '', 'marcacoes' => 0];
    [$now, $nowLocal] = ponto_acesso_agora($pdo, $companyId, $col, $agora);

    $lib = ponto_acesso_liberacao_ativa($pdo, $companyId, (int)$col['id'], $nowLocal);
    if ($lib) { $r['liberacao'] = $lib; return $r; }

    $nega = static function (string $motivo, string $msg) use (&$r): array {
      $r['ok'] = false; $r['motivo'] = $motivo; $r['mensagem'] = $msg;
      return $r;
    };
    if ((string)($col['status'] ?? 'ATIVO') !== 'ATIVO') return $nega('AFASTADO', 'Seu cadastro no ponto está como ' . mb_strtolower((string)$col['status']) . '. Fale com o seu gestor.');

    // Jornada de ontem (pode passar da meia-noite) e de hoje.
    $antes = max(0, (int)($cfg['acesso_antes_min'] ?? 15)) * 60;
    $depois = max(0, (int)($cfg['acesso_depois_min'] ?? 15)) * 60;
    $hoje = substr($nowLocal, 0, 10);
    $ontem = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $prevHoje = ponto_previsto_do_dia($pdo, $companyId, $col, $hoje);
    $semJornada = ($prevHoje['origem'] ?? '') === 'sem_jornada';
    $achou = null;
    foreach ([$ontem, $hoje] as $d) {
      $prev = $d === $hoje ? $prevHoje : ponto_previsto_do_dia($pdo, $companyId, $col, $d);
      if (empty($prev['periodos'])) continue;
      $abs = ponto_periodo_abs($d, $prev['periodos']);
      $ini = $abs[0][0]; $fim = end($abs)[1];
      if ($now >= $ini - $antes && $now <= $fim + $depois) $achou = ['abs' => $abs, 'ini' => $ini, 'fim' => $fim];
    }
    if ($achou) $r['janela'] = date('H:i', $achou['ini']) . ' às ' . date('H:i', $achou['fim']);
    elseif (!empty($prevHoje['periodos'])) {
      $abs = ponto_periodo_abs($hoje, $prevHoje['periodos']);
      $r['janela'] = date('H:i', $abs[0][0]) . ' às ' . date('H:i', end($abs)[1]);
    }

    if (!$achou && !$semJornada) {
      if (!empty($prevHoje['feriado']) && empty($prevHoje['periodos'])) return $nega('FERIADO', 'Hoje é feriado (' . $prevHoje['feriado'] . ') e não há jornada prevista.');
      if (empty($prevHoje['periodos'])) return $nega('FOLGA', 'Hoje é dia de folga na sua escala.');
      return $nega('FORA_HORARIO', 'Fora do horário da sua jornada (' . $r['janela'] . ').');
    }

    if ($usaMarcacoes && (!empty($cfg['acesso_exigir_marcacao']) || !empty($cfg['acesso_bloquear_saida']))) {
      $desde = $achou ? date('Y-m-d H:i:s', $achou['ini'] - $antes - 7200) : $hoje . ' 00:00:00';
      $st = $pdo->prepare('SELECT COUNT(*) FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND data_hora >= ? AND data_hora <= ?');
      $st->execute([$companyId, (int)$col['id'], $desde, $nowLocal]);
      $qt = (int)$st->fetchColumn();
      $r['marcacoes'] = $qt;
      if ($qt === 0 && !empty($cfg['acesso_exigir_marcacao'])) return $nega('SEM_MARCACAO', 'Registre o ponto de entrada para usar o Nexa.');
      if ($qt > 0 && $qt % 2 === 0 && !empty($cfg['acesso_bloquear_saida'])) {
        $emIntervalo = $achou && count($achou['abs']) > 1 && $now < end($achou['abs'])[0];
        return $emIntervalo
          ? $nega('INTERVALO', 'Você registrou a saída para o intervalo. Registre a volta para usar o Nexa.')
          : $nega('JORNADA_ENCERRADA', 'Você já registrou a saída de hoje.');
      }
    }
    return $r;
  }
}

if (!function_exists('ponto_acesso_registrar_evento')) {
  /** Uma linha por usuário/dia/tipo/motivo, com contador. tipo: BLOQUEIO ou AVISO. */
  function ponto_acesso_registrar_evento(PDO $pdo, int $companyId, array $av, int $userId, string $tipo, string $pagina): void {
    $col = $av['colaborador'] ?? null;
    if (!$col || $av['motivo'] === '') return;
    [, $nowLocal] = ponto_acesso_agora($pdo, $companyId, $col);
    $data = substr($nowLocal, 0, 10);
    $ip = mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
    $pdo->prepare('INSERT IGNORE INTO ponto_acesso_eventos (company_id, colaborador_id, usuario_id, `data`, tipo, motivo, pagina, ip, vezes, primeira_em, ultima_em) VALUES (?,?,?,?,?,?,?,?,0,?,?)')
      ->execute([$companyId, (int)$col['id'], $userId, $data, $tipo, $av['motivo'], mb_substr($pagina, 0, 120), $ip, $nowLocal, $nowLocal]);
    $pdo->prepare('UPDATE ponto_acesso_eventos SET vezes = vezes + 1, ultima_em = ?, pagina = ?, ip = ? WHERE company_id = ? AND usuario_id = ? AND `data` = ? AND tipo = ? AND motivo = ?')
      ->execute([$nowLocal, mb_substr($pagina, 0, 120), $ip, $companyId, $userId, $data, $tipo, $av['motivo']]);
  }
}

if (!function_exists('ponto_acesso_paginas_livres')) {
  /** Telas que abrem mesmo bloqueado: bater o ponto, a própria tela de bloqueio e sair. */
  function ponto_acesso_paginas_livres(): array {
    return ['ponto_acesso_bloqueado.php', 'ponto_bater.php', 'logout.php', 'login.php'];
  }
}

if (!function_exists('ponto_acesso_limpar_cache')) {
  function ponto_acesso_limpar_cache(): void {
    if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['nexa_ponto_acesso']);
  }
}

if (!function_exists('ponto_acesso_guard')) {
  /** Chamado pelo admin/_bootstrap.php em toda tela do painel. */
  function ponto_acesso_guard(PDO $pdo, int $companyId, string $page): void {
    $role = function_exists('auth_role') ? auth_role() : '';
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    if ($uid <= 0 || $role === 'platform_admin' || $role === '') return;
    $page = strtolower($page);
    if (session_status() !== PHP_SESSION_ACTIVE) @session_start();
    // Depois de bater o ponto, a próxima tela verifica de novo.
    if ($page === 'ponto_bater.php' || $page === 'ponto_acesso_bloqueado.php') { ponto_acesso_limpar_cache(); return; }

    $c = $_SESSION['nexa_ponto_acesso'] ?? null;
    $fresh = false;
    if (!is_array($c) || (int)($c['cid'] ?? 0) !== $companyId || (int)($c['uid'] ?? 0) !== $uid || (int)($c['exp'] ?? 0) < time()) {
      $fresh = true;
      $c = ['cid' => $companyId, 'uid' => $uid, 'exp' => time() + 60, 'ok' => true, 'modo' => 'DESLIGADO', 'controlado' => false];
      try {
        if (function_exists('saas_company_has_module') && !saas_company_has_module($pdo, $companyId, 'ponto_eletronico')) {
          $c['exp'] = time() + 300;
        } else {
          $av = ponto_acesso_avaliar($pdo, $companyId, $uid, $role);
          $c['ok'] = $av['ok']; $c['modo'] = $av['modo']; $c['controlado'] = $av['controlado'];
          if (!$av['controlado']) $c['exp'] = time() + 300;
          if (!$av['ok']) {
            ponto_acesso_registrar_evento($pdo, $companyId, $av, $uid, $av['modo'] === 'BLOQUEAR' ? 'BLOQUEIO' : 'AVISO', $page);
          }
        }
      } catch (Throwable $e) {
        // Falha na verificação nunca tranca a empresa.
        error_log('[Nexa Ponto acesso] ' . $e->getMessage());
        $c['ok'] = true;
      }
      $_SESSION['nexa_ponto_acesso'] = $c;
    }
    if ($c['ok'] || $c['modo'] !== 'BLOQUEAR' || in_array($page, ponto_acesso_paginas_livres(), true)) return;

    $wantsJson = stripos((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json') !== false
      || strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
      || preg_match('/(^api_|_api\.php$|_ajax\.php$)/', $page);
    $msg = 'Acesso ao Nexa bloqueado fora da jornada de trabalho. Abra o Nexa de novo para bater o ponto ou pedir liberação.';
    if ($wantsJson) {
      http_response_code(403);
      header('Content-Type: application/json; charset=utf-8');
      echo json_encode(['ok' => false, 'success' => false, 'error' => $msg, 'message' => $msg, 'ponto_acesso_bloqueado' => true], JSON_UNESCAPED_UNICODE);
      exit;
    }
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
      http_response_code(403);
      if (function_exists('access_render_denied')) access_render_denied($msg);
      echo htmlspecialchars($msg, ENT_QUOTES, 'UTF-8');
      exit;
    }
    header('Location: ponto_acesso_bloqueado.php');
    exit;
  }
}

/* ------------------------------------------------------------------------- */
/* Liberações                                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_acesso_pedir')) {
  /** O colaborador pede liberação (fica PENDENTE até o gestor decidir). */
  function ponto_acesso_pedir(PDO $pdo, int $companyId, array $col, int $userId, string $motivo): int {
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5) throw new RuntimeException('Explique o motivo do pedido (pelo menos 5 letras).');
    $st = $pdo->prepare("SELECT COUNT(*) FROM ponto_acesso_liberacoes WHERE company_id = ? AND colaborador_id = ? AND status = 'PENDENTE'");
    $st->execute([$companyId, (int)$col['id']]);
    if ((int)$st->fetchColumn() > 0) throw new RuntimeException('Você já tem um pedido aguardando o gestor.');
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO ponto_acesso_liberacoes (company_id, colaborador_id, usuario_id, status, motivo, pedido_por, pedido_em, created_at) VALUES (?,?,?,'PENDENTE',?,?,?,?)")
      ->execute([$companyId, (int)$col['id'], $userId, mb_substr($motivo, 0, 300), $userId, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'pedir_liberacao', 'acesso', $id, null, ['motivo' => $motivo], (int)$col['id']);
    return $id;
  }
}

if (!function_exists('ponto_acesso_fim_por_duracao')) {
  /** Fim da liberação: '1h', '2h', '4h', '8h' ou 'dia' (até 23:59 de hoje), a partir de agora (local). */
  function ponto_acesso_fim_por_duracao(string $agoraLocal, string $dur): string {
    $t = strtotime($agoraLocal);
    $map = ['1h' => 3600, '2h' => 7200, '4h' => 14400, '8h' => 28800];
    if (isset($map[$dur])) return date('Y-m-d H:i:s', $t + $map[$dur]);
    if ($dur === 'dia') return substr($agoraLocal, 0, 10) . ' 23:59:59';
    throw new RuntimeException('Escolha por quanto tempo liberar.');
  }
}

if (!function_exists('ponto_acesso_get')) {
  function ponto_acesso_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_acesso_liberacoes WHERE company_id = ? AND id = ?');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_acesso_decidir')) {
  /** Gestor aprova (com duração) ou nega um pedido pendente. */
  function ponto_acesso_decidir(PDO $pdo, int $companyId, int $id, bool $aprovar, string $duracao, string $obs, int $gestorId): void {
    $l = ponto_acesso_get($pdo, $companyId, $id);
    if (!$l || $l['status'] !== 'PENDENTE') throw new RuntimeException('Pedido não encontrado ou já decidido.');
    $col = ponto_colaborador_get($pdo, $companyId, (int)$l['colaborador_id']);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $obs = mb_substr(trim($obs), 0, 300);
    if (!$aprovar && $obs === '') throw new RuntimeException('Informe o motivo para negar.');
    $now = date('Y-m-d H:i:s');
    if ($aprovar) {
      [, $agoraLocal] = ponto_acesso_agora($pdo, $companyId, $col);
      $fim = ponto_acesso_fim_por_duracao($agoraLocal, $duracao);
      $pdo->prepare("UPDATE ponto_acesso_liberacoes SET status = 'APROVADA', inicio = ?, fim = ?, decidido_por = ?, decidido_em = ?, obs_decisao = ? WHERE company_id = ? AND id = ? AND status = 'PENDENTE'")
        ->execute([$agoraLocal, $fim, $gestorId, $now, $obs ?: null, $companyId, $id]);
      ponto_audit($pdo, $companyId, 'aprovar_liberacao', 'acesso', $id, ['status' => 'PENDENTE'], ['status' => 'APROVADA', 'inicio' => $agoraLocal, 'fim' => $fim, 'obs' => $obs], (int)$col['id']);
    } else {
      $pdo->prepare("UPDATE ponto_acesso_liberacoes SET status = 'NEGADA', decidido_por = ?, decidido_em = ?, obs_decisao = ? WHERE company_id = ? AND id = ? AND status = 'PENDENTE'")
        ->execute([$gestorId, $now, $obs, $companyId, $id]);
      ponto_audit($pdo, $companyId, 'negar_liberacao', 'acesso', $id, ['status' => 'PENDENTE'], ['status' => 'NEGADA', 'obs' => $obs], (int)$col['id']);
    }
  }
}

if (!function_exists('ponto_acesso_liberar')) {
  /** Gestor libera direto (ex.: hora extra combinada). $inicio/$fim locais 'Y-m-d H:i'. */
  function ponto_acesso_liberar(PDO $pdo, int $companyId, int $colId, string $inicio, string $fim, string $motivo, int $gestorId): int {
    $col = ponto_colaborador_get($pdo, $companyId, $colId);
    if (!$col) throw new RuntimeException('Escolha o colaborador.');
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 5) throw new RuntimeException('Informe o motivo da liberação.');
    $parse = static function (string $v, string $rot): string {
      $v = str_replace('T', ' ', trim($v));
      $d = DateTime::createFromFormat('Y-m-d H:i', substr($v, 0, 16));
      if (!$d) throw new RuntimeException($rot . ' inválido.');
      return $d->format('Y-m-d H:i:00');
    };
    $ini = $parse($inicio, 'Início'); $f = $parse($fim, 'Fim');
    if ($f <= $ini) throw new RuntimeException('O fim tem que ser depois do início.');
    if (strtotime($f) - strtotime($ini) > 31 * 86400) throw new RuntimeException('Liberação de no máximo 31 dias. Para liberar sempre, marque "Acesso ao Nexa sem restrição de horário" no cadastro do colaborador.');
    $now = date('Y-m-d H:i:s');
    $pdo->prepare("INSERT INTO ponto_acesso_liberacoes (company_id, colaborador_id, usuario_id, status, motivo, inicio, fim, decidido_por, decidido_em, created_at) VALUES (?,?,?,'APROVADA',?,?,?,?,?,?)")
      ->execute([$companyId, $colId, $col['usuario_id'] ?? null, mb_substr($motivo, 0, 300), $ini, $f, $gestorId, $now, $now]);
    $id = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'liberar_acesso', 'acesso', $id, null, ['inicio' => $ini, 'fim' => $f, 'motivo' => $motivo], $colId);
    return $id;
  }
}

if (!function_exists('ponto_acesso_cancelar')) {
  /** Encerra uma liberação aprovada (fica no histórico como CANCELADA) ou desiste de um pedido. */
  function ponto_acesso_cancelar(PDO $pdo, int $companyId, int $id, int $userId, ?int $somenteColaboradorId = null): void {
    $l = ponto_acesso_get($pdo, $companyId, $id);
    if (!$l || !in_array($l['status'], ['APROVADA', 'PENDENTE'], true)) throw new RuntimeException('Liberação não encontrada ou já encerrada.');
    if ($somenteColaboradorId !== null && (int)$l['colaborador_id'] !== $somenteColaboradorId) throw new RuntimeException('Liberação não encontrada.');
    $pdo->prepare("UPDATE ponto_acesso_liberacoes SET status = 'CANCELADA', decidido_por = ?, decidido_em = ? WHERE company_id = ? AND id = ?")
      ->execute([$userId, date('Y-m-d H:i:s'), $companyId, $id]);
    ponto_audit($pdo, $companyId, 'cancelar_liberacao', 'acesso', $id, ['status' => $l['status']], ['status' => 'CANCELADA'], (int)$l['colaborador_id']);
  }
}

if (!function_exists('ponto_acesso_lista')) {
  /** Liberações (pedidos e dadas) para a tela do gestor. */
  function ponto_acesso_lista(PDO $pdo, int $companyId, ?string $status = null, int $dias = 30): array {
    $where = ['l.company_id = ?']; $p = [$companyId];
    if ($status !== null) { $where[] = 'l.status = ?'; $p[] = $status; }
    else { $where[] = 'l.created_at >= ?'; $p[] = date('Y-m-d 00:00:00', strtotime('-' . $dias . ' days')); }
    $st = $pdo->prepare('SELECT l.*, c.nome, c.matricula FROM ponto_acesso_liberacoes l
      JOIN ponto_colaboradores c ON c.company_id = l.company_id AND c.id = l.colaborador_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY l.id DESC LIMIT 300');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_acesso_eventos_lista')) {
  function ponto_acesso_eventos_lista(PDO $pdo, int $companyId, string $de, string $ate): array {
    $st = $pdo->prepare('SELECT e.*, c.nome, c.matricula FROM ponto_acesso_eventos e
      JOIN ponto_colaboradores c ON c.company_id = e.company_id AND c.id = e.colaborador_id
      WHERE e.company_id = ? AND e.`data` >= ? AND e.`data` <= ? ORDER BY e.`data` DESC, e.ultima_em DESC LIMIT 500');
    $st->execute([$companyId, $de, $ate]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_acesso_pendentes_count')) {
  function ponto_acesso_pendentes_count(PDO $pdo, int $companyId): int {
    try {
      if (!ponto_v8_ready($pdo)) return 0;
      $st = $pdo->prepare("SELECT COUNT(*) FROM ponto_acesso_liberacoes WHERE company_id = ? AND status = 'PENDENTE'");
      $st->execute([$companyId]);
      return (int)$st->fetchColumn();
    } catch (Throwable $e) {
      return 0;
    }
  }
}
