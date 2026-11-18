<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V11: inteligência do ponto no Nexa Brain.
 *
 * Tudo aqui só aparece quando a empresa tem o módulo "Ponto Eletrônico" ligado no SaaS
 * (ponto_brain_ativo). Desligado o módulo, as regras não detectam nada (os alertas abertos são
 * resolvidos na próxima análise), o painel do Brain não mostra a seção e o assistente não responde
 * sobre o ponto.
 *
 * Entra no Brain de três formas:
 *  1) Regras na Central de Ações (brain_rules com module_code 'ponto', semeadas pela migração V11)
 *     — avaliadas junto com as demais (timer do Brain ou "Analisar agora"); nenhuma rotina nova.
 *  2) Resumo da equipe no painel (presença de hoje, pendências, extras e faltas do mês, banco).
 *  3) Respostas do assistente ("quem faltou hoje?", "horas extras do mês", "banco de horas"...).
 */

require_once __DIR__ . '/ponto_marcacao.php';
require_once __DIR__ . '/ponto_apuracao.php';
require_once __DIR__ . '/ponto_fechamento.php';

if (!function_exists('ponto_brain_ativo')) {
  function ponto_brain_ativo(PDO $pdo, int $companyId): bool {
    static $cache = [];
    if (isset($cache[$companyId])) return $cache[$companyId];
    try {
      if (!function_exists('saas_company_has_module') && is_file(__DIR__ . '/saas_modules.php')) require_once __DIR__ . '/saas_modules.php';
      $mod = function_exists('saas_company_has_module') ? saas_company_has_module($pdo, $companyId, 'ponto_eletronico') : false;
      return $cache[$companyId] = $mod && ponto_ready($pdo) && ponto_v2_ready($pdo);
    } catch (Throwable $e) {
      return $cache[$companyId] = false;
    }
  }
}

if (!function_exists('ponto_brain_hoje')) {
  function ponto_brain_hoje(PDO $pdo, int $companyId): string {
    return (new DateTimeImmutable('now', ponto_tz(null, $pdo, $companyId)))->format('Y-m-d');
  }
}

if (!function_exists('ponto_brain_atualizar_apuracao')) {
  /**
   * Recalcula a apuração (tabela derivada) do início do mês — ou dos últimos $dias, o que vier
   * antes — até ontem, para quem controla ponto. Pula meses já fechados. Uma vez por análise.
   */
  function ponto_brain_atualizar_apuracao(PDO $pdo, int $companyId, int $dias = 7): int {
    static $feito = [];
    if (isset($feito[$companyId]) || !ponto_v4_ready($pdo)) return 0;
    $feito[$companyId] = true;
    $hoje = ponto_brain_hoje($pdo, $companyId);
    $ate = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $de = min(substr($hoje, 0, 8) . '01', date('Y-m-d', strtotime($hoje . ' -' . max(1, $dias) . ' days')));
    if ($ate < $de) return 0;
    $n = 0;
    foreach (ponto_colaborador_list($pdo, $companyId, ['status' => 'ATIVOS']) as $c) {
      if (!(int)$c['controla_ponto']) continue;
      $ini = max($de, (string)$c['data_admissao']);
      // Não reescreve meses fechados (o fechamento tem a sua própria fotografia).
      while ($ini <= $ate && ponto_competencia_fechada($pdo, $companyId, (int)$c['id'], $ini)) $ini = date('Y-m-01', strtotime(substr($ini, 0, 7) . '-01 +1 month'));
      if ($ini > $ate) continue;
      try { ponto_apurar_periodo($pdo, $companyId, (int)$c['id'], $ini, $ate); $n++; } catch (Throwable $e) { error_log('[Nexa Ponto Brain] ' . $e->getMessage()); }
    }
    return $n;
  }
}

if (!function_exists('ponto_brain_resumo')) {
  /** Números da equipe para o painel do Brain. */
  function ponto_brain_resumo(PDO $pdo, int $companyId): array {
    $hoje = ponto_brain_hoje($pdo, $companyId);
    $mes = substr($hoje, 0, 7);
    $r = ['data' => $hoje, 'escalados' => 0, 'trabalhando' => 0, 'intervalo' => 0, 'saiu' => 0, 'nao_chegou' => 0, 'atrasados' => [], 'ausentes' => [],
      'pendentes' => 0, 'inconsistencias_7d' => 0, 'extras_mes' => 0, 'faltas_mes' => 0, 'faltas_dias_mes' => 0, 'banco_positivo' => 0, 'banco_negativo' => 0,
      'rosto_conferir_7d' => 0, 'fora_jornada_7d' => 0, 'top_extras' => []];
    foreach (ponto_presenca_dia($pdo, $companyId, $hoje) as $p) {
      $nome = (string)$p['c']['nome'];
      if (!empty($p['p']['trabalha'])) $r['escalados']++;
      switch ($p['situacao']) {
        case 'TRABALHANDO': $r['trabalhando']++; break;
        case 'INTERVALO': $r['intervalo']++; break;
        case 'SAIU': $r['saiu']++; break;
        case 'NAO_CHEGOU':
          if ((int)$p['atraso_min'] > 0) { $r['nao_chegou']++; $r['ausentes'][] = ['nome' => $nome, 'atraso' => (int)$p['atraso_min']]; }
          break;
      }
      if ($p['marcacoes'] && (int)$p['atraso_min'] > 0) $r['atrasados'][] = ['nome' => $nome, 'atraso' => (int)$p['atraso_min']];
    }
    $q = static function (string $sql, array $p) use ($pdo) { try { $st = $pdo->prepare($sql); $st->execute($p); return $st; } catch (Throwable $e) { return null; } };
    if (ponto_v4_ready($pdo)) {
      foreach (["SELECT COUNT(*) FROM ponto_tratamentos WHERE company_id = ? AND status = 'PENDENTE'", "SELECT COUNT(*) FROM ponto_ocorrencias WHERE company_id = ? AND status = 'PENDENTE'"] as $sql) {
        if ($st = $q($sql, [$companyId])) $r['pendentes'] += (int)$st->fetchColumn();
      }
      if ($st = $q('SELECT COUNT(*) FROM ponto_apuracoes_diarias WHERE company_id = ? AND `data` BETWEEN ? AND ? AND inconsistente = 1', [$companyId, date('Y-m-d', strtotime($hoje . ' -7 days')), date('Y-m-d', strtotime($hoje . ' -1 day'))])) {
        $r['inconsistencias_7d'] = (int)$st->fetchColumn();
      }
      if ($st = $q("SELECT COALESCE(SUM(minutos_extra),0) e, COALESCE(SUM(minutos_falta),0) f, COALESCE(SUM(situacao = 'FALTA'),0) d FROM ponto_apuracoes_diarias WHERE company_id = ? AND `data` BETWEEN ? AND ?", [$companyId, $mes . '-01', $hoje])) {
        $row = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['extras_mes'] = (int)($row['e'] ?? 0); $r['faltas_mes'] = (int)($row['f'] ?? 0); $r['faltas_dias_mes'] = (int)($row['d'] ?? 0);
      }
      if ($st = $q('SELECT c.nome, SUM(a.minutos_extra) e FROM ponto_apuracoes_diarias a JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id
          WHERE a.company_id = ? AND a.`data` BETWEEN ? AND ? GROUP BY a.colaborador_id, c.nome HAVING SUM(a.minutos_extra) > 0 ORDER BY e DESC LIMIT 5', [$companyId, $mes . '-01', $hoje])) {
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $r['top_extras'][] = ['nome' => (string)$row['nome'], 'minutos' => (int)$row['e']];
      }
    }
    if (function_exists('ponto_v8_ready') && ponto_v8_ready($pdo)) {
      if ($st = $q("SELECT COUNT(*) FROM ponto_acesso_liberacoes WHERE company_id = ? AND status = 'PENDENTE'", [$companyId])) $r['pendentes'] += (int)$st->fetchColumn();
      if ($st = $q('SELECT COALESCE(SUM(vezes),0) FROM ponto_acesso_eventos WHERE company_id = ? AND `data` >= ?', [$companyId, date('Y-m-d', strtotime($hoje . ' -7 days'))])) $r['fora_jornada_7d'] = (int)$st->fetchColumn();
    }
    if (ponto_v5_ready($pdo)) {
      foreach (ponto_bh_saldos($pdo, $companyId) as $s) { if ($s > 0) $r['banco_positivo'] += $s; elseif ($s < 0) $r['banco_negativo'] += $s; }
    }
    if (function_exists('ponto_v10_ready') && ponto_v10_ready($pdo)) {
      if ($st = $q("SELECT COUNT(*) FROM ponto_marcacao_faciais WHERE company_id = ? AND created_at >= ? AND resultado IN ('NAO_CONFERE','SEM_ROSTO','SEM_FOTO')", [$companyId, date('Y-m-d H:i:s', time() - 7 * 86400)])) {
        $r['rosto_conferir_7d'] = (int)$st->fetchColumn();
      }
    }
    return $r;
  }
}

if (!function_exists('ponto_brain_insights')) {
  /** "Leituras rápidas" do painel do Brain (mesmo formato de brain_insights). */
  function ponto_brain_insights(array $r): array {
    $i = [];
    if ($r['nao_chegou'] > 0) $i[] = ['level' => 'warning', 'title' => 'Equipe: ausências agora', 'message' => $r['nao_chegou'] . ' colaborador(es) escalado(s) ainda não registraram a entrada (já passou do horário).', 'action' => 'Ver painel do ponto', 'url' => 'ponto_painel.php'];
    if ($r['pendentes'] > 0) $i[] = ['level' => 'warning', 'title' => 'Ponto: pedidos aguardando', 'message' => $r['pendentes'] . ' pedido(s) de ajuste, justificativa ou liberação aguardam o gestor.', 'action' => 'Abrir ocorrências', 'url' => 'ponto_ocorrencias.php'];
    if ($r['inconsistencias_7d'] > 0) $i[] = ['level' => 'warning', 'title' => 'Ponto: dias a tratar', 'message' => $r['inconsistencias_7d'] . ' dia(s) com marcação faltando, falta ou alerta de jornada nos últimos 7 dias.', 'action' => 'Tratar o ponto', 'url' => 'ponto_tratamento.php?so=1'];
    if ($r['rosto_conferir_7d'] > 0) $i[] = ['level' => 'danger', 'title' => 'Ponto: rosto a conferir', 'message' => $r['rosto_conferir_7d'] . ' marcação(ões) nos últimos 7 dias com o rosto não conferido.', 'action' => 'Ver marcações', 'url' => 'ponto_marcacoes.php'];
    if ($r['extras_mes'] >= 600) $i[] = ['level' => 'info', 'title' => 'Ponto: horas extras no mês', 'message' => 'A equipe soma ' . ponto_hm($r['extras_mes'], false) . ' de horas extras neste mês (até ontem).', 'action' => 'Ver fechamento', 'url' => 'ponto_fechamento.php'];
    return $i;
  }
}

/* ------------------------------------------------------------------------- */
/* Regras da Central de Ações                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_brain_detectar')) {
  /** Alertas de uma regra PONTO_* (vazio com o módulo desligado: o Brain resolve os abertos). */
  function ponto_brain_detectar(PDO $pdo, int $companyId, string $code, array $settings): array {
    if (!ponto_brain_ativo($pdo, $companyId)) return [];
    $num = static fn(string $k, float $d): float => is_numeric($settings[$k] ?? null) ? (float)$settings[$k] : $d;
    $max = max(1, min(200, (int)$num('max_alerts', 30)));
    $hoje = ponto_brain_hoje($pdo, $companyId);
    $ontem = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $out = [];
    $fmt = static fn(string $d): string => date('d/m', strtotime($d));

    if (in_array($code, ['PONTO_INCONSISTENCIA', 'PONTO_RISCO_CLT', 'PONTO_HORAS_EXTRAS_MES'], true)) {
      if (!ponto_v4_ready($pdo)) return [];
      ponto_brain_atualizar_apuracao($pdo, $companyId, max(7, (int)$num('days', 7)));
    }

    switch ($code) {
      case 'PONTO_INCONSISTENCIA':
        $de = date('Y-m-d', strtotime($hoje . ' -' . max(1, (int)$num('days', 7)) . ' days'));
        $st = $pdo->prepare("SELECT a.colaborador_id, c.nome, a.`data`, a.situacao FROM ponto_apuracoes_diarias a JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id
          WHERE a.company_id = ? AND a.`data` BETWEEN ? AND ? AND a.situacao IN ('FALTA','INCOMPLETO') ORDER BY c.nome, a.`data`");
        $st->execute([$companyId, $de, $ontem]);
        $por = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) $por[(int)$row['colaborador_id']][] = $row;
        foreach ($por as $colId => $dias) {
          $faltas = count(array_filter($dias, static fn($d) => $d['situacao'] === 'FALTA'));
          $inc = count($dias) - $faltas;
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => $colId, 'title' => 'Ponto a tratar: ' . $dias[0]['nome'],
            'description' => trim(($inc ? $inc . ' dia(s) com marcação faltando' : '') . ($inc && $faltas ? ' e ' : '') . ($faltas ? $faltas . ' falta(s) sem justificativa' : '')) . ' — ' . implode(', ', array_map(static fn($d) => $fmt($d['data']), $dias)) . '.',
            'action_url' => 'ponto_tratamento.php?' . http_build_query(['c' => substr($dias[0]['data'], 0, 7), 'col' => $colId]), 'metric_value' => count($dias)];
          if (count($out) >= $max) break;
        }
        return $out;

      case 'PONTO_RISCO_CLT':
        $de = date('Y-m-d', strtotime($hoje . ' -' . max(1, (int)$num('days', 7)) . ' days'));
        $st = $pdo->prepare('SELECT a.colaborador_id, c.nome, a.`data`, a.minutos_extra, a.detalhe_json FROM ponto_apuracoes_diarias a JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id
          WHERE a.company_id = ? AND a.`data` BETWEEN ? AND ? ORDER BY c.nome, a.`data`');
        $st->execute([$companyId, $de, $ontem]);
        $por = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $al = (array)(json_decode((string)$row['detalhe_json'], true)['alertas'] ?? []);
          $tipos = [];
          foreach ($al as $a) {
            if (stripos($a, 'intervalo') !== false) $tipos[] = 'intervalo';
            if (stripos($a, 'descanso') !== false) $tipos[] = 'descanso';
          }
          if ((int)$row['minutos_extra'] > 120) $tipos[] = 'extra';
          if ($tipos) $por[(int)$row['colaborador_id']][] = ['nome' => $row['nome'], 'data' => $row['data'], 'tipos' => array_unique($tipos)];
        }
        $rot = ['intervalo' => 'intervalo de almoço menor que o mínimo (CLT art. 71)', 'descanso' => 'menos de 11h de descanso entre jornadas (art. 66)', 'extra' => 'mais de 2h extras no dia (art. 59)'];
        foreach ($por as $colId => $dias) {
          $tipos = array_unique(array_merge(...array_column($dias, 'tipos')));
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => $colId, 'title' => 'Risco trabalhista na jornada: ' . $dias[0]['nome'],
            'description' => 'Nos últimos dias: ' . implode('; ', array_map(static fn($t) => $rot[$t], $tipos)) . ' — ' . implode(', ', array_map(static fn($d) => $fmt($d['data']), $dias)) . '.',
            'action_url' => 'ponto_tratamento.php?' . http_build_query(['c' => substr($dias[0]['data'], 0, 7), 'col' => $colId]), 'metric_value' => count($dias)];
          if (count($out) >= $max) break;
        }
        return $out;

      case 'PONTO_HORAS_EXTRAS_MES':
        $min = (int)round($num('min_hours', 10) * 60);
        $st = $pdo->prepare('SELECT a.colaborador_id, c.nome, SUM(a.minutos_extra) e FROM ponto_apuracoes_diarias a JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id
          WHERE a.company_id = ? AND a.`data` BETWEEN ? AND ? GROUP BY a.colaborador_id, c.nome HAVING SUM(a.minutos_extra) >= ' . max(1, $min) . ' ORDER BY e DESC LIMIT ' . $max);
        $st->execute([$companyId, substr($hoje, 0, 8) . '01', $ontem]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => (int)$row['colaborador_id'], 'reference_key' => substr($hoje, 0, 7),
            'title' => 'Horas extras acima do limite: ' . $row['nome'],
            'description' => ponto_hm((int)$row['e'], false) . ' de horas extras em ' . date('m/Y', strtotime($hoje)) . ' (até ontem). Limite configurado: ' . ponto_hm($min, false) . '.',
            'action_url' => 'ponto_tratamento.php?' . http_build_query(['c' => substr($hoje, 0, 7), 'col' => (int)$row['colaborador_id']]), 'metric_value' => round((int)$row['e'] / 60, 2)];
        }
        return $out;

      case 'PONTO_PEDIDOS_PENDENTES':
        $limite = date('Y-m-d H:i:s', time() - (int)($num('hours', 24) * 3600));
        $n = 0; $velho = null;
        $fontes = [];
        if (ponto_v4_ready($pdo)) {
          $fontes[] = ["SELECT COUNT(*), MIN(created_at) FROM ponto_tratamentos WHERE company_id = ? AND status = 'PENDENTE' AND created_at <= ?", 'ponto_tratamento.php?so=1'];
          $fontes[] = ["SELECT COUNT(*), MIN(created_at) FROM ponto_ocorrencias WHERE company_id = ? AND status = 'PENDENTE' AND created_at <= ?", 'ponto_ocorrencias.php'];
        }
        if (function_exists('ponto_v8_ready') && ponto_v8_ready($pdo)) $fontes[] = ["SELECT COUNT(*), MIN(created_at) FROM ponto_acesso_liberacoes WHERE company_id = ? AND status = 'PENDENTE' AND created_at <= ?", 'ponto_acesso.php'];
        $url = 'ponto_ocorrencias.php';
        foreach ($fontes as [$sql, $u]) {
          $st = $pdo->prepare($sql); $st->execute([$companyId, $limite]);
          [$c, $m] = $st->fetch(PDO::FETCH_NUM) ?: [0, null];
          if ((int)$c > 0) { if ($n === 0) $url = $u; $n += (int)$c; if ($m && (!$velho || $m < $velho)) $velho = $m; }
        }
        if ($n > 0) $out[] = ['entity_type' => 'company', 'title' => $n . ' pedido(s) do ponto aguardando o gestor',
          'description' => 'Pedidos de ajuste de marcação, justificativas/atestados ou liberação de acesso esperando há mais de ' . (int)$num('hours', 24) . 'h (o mais antigo desde ' . date('d/m H:i', strtotime((string)$velho)) . ').',
          'action_url' => $url, 'metric_value' => $n];
        return $out;

      case 'PONTO_BANCO_VENCER':
        $cfg = ponto_config($pdo, $companyId);
        if (!ponto_v5_ready($pdo) || empty($cfg['bh_ativo'])) return [];
        $ahead = max(1, (int)$num('days_ahead', 30));
        $corte = date('Y-m-d', strtotime($hoje . ' +' . $ahead . ' days'));
        $validade = max(1, (int)($cfg['bh_validade_meses'] ?? 6));
        foreach (ponto_bh_saldos($pdo, $companyId) as $colId => $saldo) {
          if ($saldo <= 0) continue;
          $min = ponto_bh_a_vencer($pdo, $companyId, (int)$colId, $corte, $validade);
          if ($min <= 0) continue;
          $col = ponto_colaborador_get($pdo, $companyId, (int)$colId);
          if (!$col || $col['status'] === 'DESLIGADO') continue;
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => (int)$colId, 'title' => 'Banco de horas perto de vencer: ' . $col['nome'],
            'description' => ponto_hm($min, false) . ' do banco vencem até ' . date('d/m/Y', strtotime($corte)) . ' (saldo atual ' . ponto_hm($saldo, false) . '). O que vencer vai para pagamento como hora extra.',
            'action_url' => 'ponto_banco_horas.php?col=' . (int)$colId, 'metric_value' => round($min / 60, 2)];
          if (count($out) >= $max) break;
        }
        return $out;

      case 'PONTO_FECHAMENTO_PENDENTE':
        if (!ponto_v5_ready($pdo)) return [];
        if ((int)date('j', strtotime($hoje)) < max(1, (int)$num('day_of_month', 5))) return [];
        $comp = date('Y-m', strtotime(substr($hoje, 0, 8) . '01 -1 month'));
        $st = $pdo->prepare("SELECT e.id, COALESCE(NULLIF(e.nome_fantasia,''), e.razao_social) nome FROM ponto_estabelecimentos e
          WHERE e.company_id = ? AND e.status = 1 AND EXISTS (SELECT 1 FROM ponto_colaboradores c WHERE c.company_id = e.company_id AND c.estabelecimento_id = e.id AND c.controla_ponto = 1 AND c.data_admissao <= ?)
          AND NOT EXISTS (SELECT 1 FROM ponto_fechamentos f WHERE f.company_id = e.company_id AND f.estabelecimento_id = e.id AND f.competencia = ? AND f.status = 'FECHADO')");
        $st->execute([$companyId, $comp . '-31', $comp]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $out[] = ['entity_type' => 'ponto_estabelecimento', 'entity_id' => (int)$row['id'], 'reference_key' => $comp,
            'title' => 'Ponto de ' . date('m/Y', strtotime($comp . '-01')) . ' ainda não fechado: ' . $row['nome'],
            'description' => 'O mês terminou e o fechamento do ponto (base para a folha e para o banco de horas) ainda não foi feito.',
            'action_url' => 'ponto_fechamento.php?' . http_build_query(['e' => (int)$row['id'], 'c' => $comp])];
        }
        return $out;

      case 'PONTO_ROSTO_A_CONFERIR':
        if (!function_exists('ponto_v10_ready') || !ponto_v10_ready($pdo)) return [];
        $st = $pdo->prepare("SELECT f.colaborador_id, c.nome, COUNT(*) n, SUM(f.resultado = 'NAO_CONFERE') nc FROM ponto_marcacao_faciais f JOIN ponto_colaboradores c ON c.company_id = f.company_id AND c.id = f.colaborador_id
          WHERE f.company_id = ? AND f.created_at >= ? AND f.resultado IN ('NAO_CONFERE','SEM_ROSTO','SEM_FOTO') GROUP BY f.colaborador_id, c.nome ORDER BY n DESC LIMIT " . $max);
        $st->execute([$companyId, date('Y-m-d H:i:s', time() - max(1, (int)$num('days', 7)) * 86400)]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => (int)$row['colaborador_id'], 'severity' => (int)$row['nc'] > 0 ? 'high' : 'warning',
            'title' => 'Rosto a conferir nas marcações: ' . $row['nome'],
            'description' => (int)$row['n'] . ' marcação(ões) nos últimos dias sem o rosto conferido' . ((int)$row['nc'] > 0 ? ' (' . (int)$row['nc'] . ' com rosto diferente do cadastro)' : '') . '.',
            'action_url' => 'ponto_marcacoes.php?' . http_build_query(['de' => date('Y-m-d', strtotime($hoje . ' -' . max(1, (int)$num('days', 7)) . ' days')), 'ate' => $hoje, 'colaborador_id' => (int)$row['colaborador_id']]), 'metric_value' => (int)$row['n']];
        }
        return $out;

      case 'PONTO_USO_FORA_JORNADA':
        if (!function_exists('ponto_v8_ready') || !ponto_v8_ready($pdo)) return [];
        $st = $pdo->prepare('SELECT e.colaborador_id, c.nome, COUNT(DISTINCT e.`data`) dias, SUM(e.vezes) v FROM ponto_acesso_eventos e JOIN ponto_colaboradores c ON c.company_id = e.company_id AND c.id = e.colaborador_id
          WHERE e.company_id = ? AND e.`data` >= ? GROUP BY e.colaborador_id, c.nome HAVING COUNT(DISTINCT e.`data`) >= ' . max(1, (int)$num('min_events', 3)) . ' ORDER BY dias DESC LIMIT ' . $max);
        $st->execute([$companyId, date('Y-m-d', strtotime($hoje . ' -' . max(1, (int)$num('days', 7)) . ' days'))]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
          $out[] = ['entity_type' => 'ponto_colaborador', 'entity_id' => (int)$row['colaborador_id'], 'title' => 'Uso do Nexa fora da jornada: ' . $row['nome'],
            'description' => 'Tentou usar o Nexa fora do horário em ' . (int)$row['dias'] . ' dia(s) recentes (' . (int)$row['v'] . ' verificação(ões)). Pode indicar trabalho sem registro de ponto.',
            'action_url' => 'ponto_acesso.php', 'metric_value' => (int)$row['dias']];
        }
        return $out;
    }
    return [];
  }
}

/* ------------------------------------------------------------------------- */
/* Assistente do Brain                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_brain_responder')) {
  /** Resposta do assistente para perguntas do ponto, ou null se a pergunta não for do ponto. */
  function ponto_brain_responder(PDO $pdo, int $companyId, string $q, ?array $r = null): ?array {
    $tem = static function (array $w) use ($q): bool { foreach ($w as $x) if (str_contains($q, $x)) return true; return false; };
    if (!$tem(['ponto', 'faltou', 'faltas', 'falta ', 'atrasad', 'atraso', 'hora extra', 'horas extras', 'banco de horas', 'equipe', 'funcionário', 'funcionario', 'colaborador', 'trabalhando', 'presen', 'chegou'])) return null;
    $r = $r ?: ponto_brain_resumo($pdo, $companyId);
    $nomes = static fn(array $l, int $n = 5): string => implode(', ', array_map(static fn($x) => $x['nome'] . (isset($x['atraso']) ? ' (' . ponto_hm((int)$x['atraso'], false) . ')' : ''), array_slice($l, 0, $n))) . (count($l) > $n ? ' e mais ' . (count($l) - $n) : '');
    if ($tem(['faltou', 'faltas hoje', 'não chegou', 'nao chegou', 'ausente', 'ausência', 'ausencia'])) {
      return ['answer' => $r['nao_chegou'] ? 'Hoje, ' . $r['nao_chegou'] . ' colaborador(es) escalado(s) ainda não registraram a entrada (já passou do horário): ' . $nomes($r['ausentes']) . '. No mês, a apuração registra ' . $r['faltas_dias_mes'] . ' dia(s) de falta até ontem.'
        : 'Ninguém escalado está com a entrada atrasada agora. No mês, a apuração registra ' . $r['faltas_dias_mes'] . ' dia(s) de falta até ontem.', 'type' => 'ponto'];
    }
    if ($tem(['atrasad', 'atraso'])) {
      $l = array_merge($r['atrasados'], $r['ausentes']);
      return ['answer' => $l ? 'Atrasos de hoje: ' . $nomes($l, 8) . '.' : 'Nenhum atraso registrado hoje até agora.', 'type' => 'ponto'];
    }
    if ($tem(['hora extra', 'horas extras'])) {
      return ['answer' => 'Neste mês (até ontem) a equipe soma ' . ponto_hm($r['extras_mes'], false) . ' de horas extras' . ($r['top_extras'] ? '. Quem mais fez: ' . implode(', ', array_map(static fn($x) => $x['nome'] . ' (' . ponto_hm($x['minutos'], false) . ')', $r['top_extras'])) : '') . '. Débitos (faltas e atrasos não abonados): ' . ponto_hm($r['faltas_mes'], false) . '.', 'type' => 'ponto'];
    }
    if ($tem(['banco de horas'])) {
      return ['answer' => 'Banco de horas: ' . ponto_hm($r['banco_positivo'], false) . ' a favor dos colaboradores e ' . ponto_hm(abs($r['banco_negativo']), false) . ' devidos por eles (saldos dos fechamentos e lançamentos).', 'type' => 'ponto'];
    }
    return ['answer' => 'Equipe hoje: ' . $r['escalados'] . ' escalado(s), ' . $r['trabalhando'] . ' trabalhando, ' . $r['intervalo'] . ' em intervalo, ' . $r['saiu'] . ' já saíram e ' . $r['nao_chegou'] . ' ainda não chegaram.'
      . ($r['pendentes'] ? ' Há ' . $r['pendentes'] . ' pedido(s) do ponto aguardando o gestor.' : '') . ($r['inconsistencias_7d'] ? ' ' . $r['inconsistencias_7d'] . ' dia(s) a tratar nos últimos 7 dias.' : ''), 'type' => 'ponto'];
  }
}
