<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V5: banco de horas e fechamento mensal (competência).
 *
 * - Fechar a competência (por estabelecimento) grava uma fotografia dos totais de cada
 *   colaborador (ponto_fechamento_itens) e lança no banco de horas os créditos (extras),
 *   débitos (faltas/atrasos), compensações (folga compensatória) e vencimentos.
 * - Com o mês fechado, tratamento e ocorrências daquele mês ficam travados.
 * - Reabrir exige motivo e estorna (não apaga) os movimentos daquele fechamento.
 *   Reabrir só o último mês fechado; fechar só depois do mês anterior (o saldo passa adiante).
 * - Banco de horas: validade (CLT art. 59: até 6 meses por acordo individual, até 12 por
 *   acordo/convenção coletiva). Horas não compensadas no prazo vencem e vão para pagamento,
 *   consumindo os créditos mais antigos primeiro.
 * - Hora noturna reduzida (art. 73, § 1º): 52min30s = 1 hora → minutos × 60 / 52,5.
 */

require_once __DIR__ . '/ponto_apuracao.php';

const PONTO_BH_TIPOS = ['CREDITO' => 'Crédito (horas extras)', 'DEBITO' => 'Débito (atrasos/faltas)', 'COMPENSACAO' => 'Folga compensada', 'VENCIMENTO' => 'Vencido (vai para pagamento)',
  'PAGAMENTO' => 'Pago na folha', 'AJUSTE' => 'Ajuste manual'];

if (!function_exists('ponto_comp_label')) {
  function ponto_comp_label(string $comp): string {
    return substr($comp, 5, 2) . '/' . substr($comp, 0, 4);
  }
}

if (!function_exists('ponto_hm_in')) {
  /** "8:30", "8h30", "8h", "8" ou "8,5" (horas) => minutos. */
  function ponto_hm_in(string $v): int {
    $v = trim(str_replace(' ', '', mb_strtolower($v)));
    if (preg_match('/^(\d{1,4})[:h](\d{1,2})$/', $v, $m)) { if ((int)$m[2] > 59) throw new RuntimeException('Minutos inválidos.'); return (int)$m[1] * 60 + (int)$m[2]; }
    if (preg_match('/^(\d{1,4})(?:[.,](\d{1,2}))?h?$/', $v, $m)) return (int)round(((float)($m[1] . '.' . ($m[2] ?? '0'))) * 60);
    throw new RuntimeException('Informe as horas como 8:30.');
  }
}

/* ------------------------------------------------------------------------- */
/* Banco de horas                                                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_bh_saldo')) {
  function ponto_bh_saldo(PDO $pdo, int $companyId, int $colId, ?string $ate = null): int {
    $st = $pdo->prepare("SELECT COALESCE(SUM(minutos), 0) FROM ponto_bh_movimentos WHERE company_id = ? AND colaborador_id = ? AND status = 'ATIVO'" . ($ate ? ' AND `data` <= ?' : ''));
    $st->execute($ate ? [$companyId, $colId, $ate] : [$companyId, $colId]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('ponto_bh_saldos')) {
  /** [colaborador_id => saldo] de todos. */
  function ponto_bh_saldos(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare("SELECT colaborador_id, SUM(minutos) AS s FROM ponto_bh_movimentos WHERE company_id = ? AND status = 'ATIVO' GROUP BY colaborador_id");
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['colaborador_id']] = (int)$r['s'];
    return $out;
  }
}

if (!function_exists('ponto_bh_extrato')) {
  function ponto_bh_extrato(PDO $pdo, int $companyId, int $colId, int $limit = 300): array {
    $st = $pdo->prepare('SELECT * FROM ponto_bh_movimentos WHERE company_id = ? AND colaborador_id = ? ORDER BY `data`, id LIMIT ' . max(1, min(2000, $limit)));
    $st->execute([$companyId, $colId]);
    $saldo = 0; $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      if ($r['status'] === 'ATIVO') $saldo += (int)$r['minutos'];
      $r['saldo'] = $saldo;
      $out[] = $r;
    }
    return $out;
  }
}

if (!function_exists('ponto_bh_a_vencer')) {
  /**
   * Minutos de crédito vencidos até $corte que ainda não foram usados (os débitos consomem os
   * créditos mais antigos primeiro). $extraDebitos = débitos que ainda vão ser lançados.
   */
  function ponto_bh_a_vencer(PDO $pdo, int $companyId, int $colId, string $corte, int $validadeMeses, int $extraDebitos = 0): int {
    $limite = date('Y-m-d', strtotime($corte . ' -' . $validadeMeses . ' months'));
    $st = $pdo->prepare("SELECT COALESCE(SUM(CASE WHEN minutos > 0 AND `data` <= ? THEN minutos ELSE 0 END), 0) AS c_old,
        COALESCE(SUM(CASE WHEN minutos < 0 THEN -minutos ELSE 0 END), 0) AS d_all
      FROM ponto_bh_movimentos WHERE company_id = ? AND colaborador_id = ? AND status = 'ATIVO'");
    $st->execute([$limite, $companyId, $colId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    return max(0, (int)$r['c_old'] - (int)$r['d_all'] - $extraDebitos);
  }
}

if (!function_exists('ponto_bh_movimento_save')) {
  /** Lançamento manual: AJUSTE (+/-), PAGAMENTO (pago na folha, -) ou COMPENSACAO (folga, -). */
  function ponto_bh_movimento_save(PDO $pdo, int $companyId, array $in): int {
    $col = ponto_colaborador_get($pdo, $companyId, (int)($in['colaborador_id'] ?? 0));
    if (!$col) throw new RuntimeException('Escolha o colaborador.');
    $tipo = (string)($in['tipo'] ?? '');
    if (!in_array($tipo, ['AJUSTE', 'PAGAMENTO', 'COMPENSACAO'], true)) throw new RuntimeException('Tipo de lançamento inválido.');
    $data = ponto_date_in($in['data'] ?? '', 'Data');
    if ($data === '') throw new RuntimeException('Informe a data.');
    if ($data > date('Y-m-d')) throw new RuntimeException('Não é possível lançar com data futura.');
    $min = ponto_hm_in((string)($in['horas'] ?? ''));
    if ($min <= 0 || $min > 1000 * 60) throw new RuntimeException('Informe as horas do lançamento.');
    $neg = $tipo !== 'AJUSTE' || (string)($in['sinal'] ?? '+') === '-';
    $desc = trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? '');
    if (mb_strlen($desc) < 5) throw new RuntimeException('Explique o lançamento (mínimo de 5 letras).');
    ponto_assert_competencia_aberta($pdo, $companyId, (int)$col['id'], $data);
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $pdo->prepare('INSERT INTO ponto_bh_movimentos (company_id, colaborador_id, `data`, competencia, tipo, minutos, origem, descricao, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, (int)$col['id'], $data, substr($data, 0, 7), $tipo, $neg ? -$min : $min, 'MANUAL', mb_substr($desc, 0, 300), $uid ?: null, date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'lancar_banco_horas', 'banco_horas', $id, null, ['tipo' => $tipo, 'minutos' => $neg ? -$min : $min, 'data' => $data, 'descricao' => $desc], (int)$col['id']);
    return $id;
  }
}

if (!function_exists('ponto_bh_estornar')) {
  /** Estorna um lançamento manual (fica no extrato, riscado). Os do fechamento saem só reabrindo o mês. */
  function ponto_bh_estornar(PDO $pdo, int $companyId, int $id, string $motivo): void {
    $st = $pdo->prepare('SELECT * FROM ponto_bh_movimentos WHERE company_id = ? AND id = ?');
    $st->execute([$companyId, $id]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m) throw new RuntimeException('Lançamento não encontrado.');
    if ($m['origem'] !== 'MANUAL') throw new RuntimeException('Lançamento do fechamento: para desfazer, reabra o mês em Fechamento.');
    if ($m['status'] !== 'ATIVO') throw new RuntimeException('Lançamento já estornado.');
    $motivo = trim($motivo);
    if (mb_strlen($motivo) < 3) throw new RuntimeException('Informe o motivo do estorno.');
    ponto_assert_competencia_aberta($pdo, $companyId, (int)$m['colaborador_id'], (string)$m['data']);
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $pdo->prepare("UPDATE ponto_bh_movimentos SET status = 'ESTORNADO', estornado_por = ?, estornado_em = ?, motivo_estorno = ? WHERE company_id = ? AND id = ?")
      ->execute([$uid ?: null, date('Y-m-d H:i:s'), mb_substr($motivo, 0, 300), $companyId, $id]);
    ponto_audit($pdo, $companyId, 'estornar_banco_horas', 'banco_horas', $id, ['minutos' => (int)$m['minutos']], ['motivo' => $motivo], (int)$m['colaborador_id']);
  }
}

/* ------------------------------------------------------------------------- */
/* Fechamento                                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_fechamento_get')) {
  function ponto_fechamento_get(PDO $pdo, int $companyId, int $estabId, string $comp): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_fechamentos WHERE company_id = ? AND estabelecimento_id = ? AND competencia = ?');
    $st->execute([$companyId, $estabId, $comp]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_fechamentos_list')) {
  function ponto_fechamentos_list(PDO $pdo, int $companyId, int $limit = 60): array {
    $st = $pdo->prepare('SELECT f.*, COALESCE(NULLIF(e.nome_fantasia, \'\'), e.razao_social) AS estabelecimento FROM ponto_fechamentos f
      LEFT JOIN ponto_estabelecimentos e ON e.company_id = f.company_id AND e.id = f.estabelecimento_id WHERE f.company_id = ? ORDER BY f.competencia DESC, f.id DESC LIMIT ' . max(1, min(500, $limit)));
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_fechamento_colaboradores')) {
  /** Colaboradores do estabelecimento com contrato no mês e que controlam ponto. */
  function ponto_fechamento_colaboradores(PDO $pdo, int $companyId, int $estabId, string $de, string $ate): array {
    $st = $pdo->prepare("SELECT * FROM ponto_colaboradores WHERE company_id = ? AND estabelecimento_id = ? AND controla_ponto = 1 AND data_admissao <= ? AND (data_demissao IS NULL OR data_demissao >= ?) ORDER BY nome");
    $st->execute([$companyId, $estabId, $ate, $de]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_fechamento_calcular')) {
  /**
   * Prévia do fechamento: totais por colaborador, banco de horas e pendências que impedem fechar
   * (marcação incompleta e pedidos do Meu Ponto aguardando aprovação).
   */
  function ponto_fechamento_calcular(PDO $pdo, int $companyId, int $estabId, string $comp): array {
    [$de, $ate] = ponto_competencia($comp);
    $cfg = ponto_config($pdo, $companyId);
    $bhEmpresa = !empty($cfg['bh_ativo']);
    $validade = max(1, min(12, (int)($cfg['bh_validade_meses'] ?? 6)));
    $fator = (int)($cfg['bh_fator_100'] ?? 1) === 2 ? 2 : 1;
    $folga100 = !empty($cfg['extra_folga_100']);
    $itens = []; $pend = [];
    foreach (ponto_fechamento_colaboradores($pdo, $companyId, $estabId, $de, $ate) as $c) {
      $colId = (int)$c['id'];
      $dias = ponto_apurar_periodo($pdo, $companyId, $colId, $de, $ate);
      $it = ['colaborador' => $c, 'minutos_previstos' => 0, 'minutos_trabalhados' => 0, 'minutos_extra_50' => 0, 'minutos_extra_100' => 0, 'minutos_falta' => 0, 'minutos_atraso' => 0,
        'minutos_noturnos' => 0, 'minutos_noturnos_reduzidos' => 0, 'minutos_abonados' => 0, 'compensados' => 0, 'dias_falta' => 0, 'dias_trabalhados' => 0, 'alertas' => [],
        'banco_horas' => ($bhEmpresa && (int)($c['banco_horas'] ?? 1)) ? 1 : 0, 'bh_saldo_anterior' => 0, 'bh_creditos' => 0, 'bh_debitos' => 0, 'bh_vencidos' => 0, 'bh_saldo_final' => 0,
        'minutos_pagar_50' => 0, 'minutos_pagar_100' => 0, 'minutos_descontar' => 0];
      foreach ($dias as $d => $r) {
        $it['minutos_previstos'] += $r['previstos'];
        $it['minutos_trabalhados'] += $r['trabalhados'];
        $cem = $r['situacao'] === 'FERIADO_TRABALHADO' || ($folga100 && $r['situacao'] === 'FOLGA_TRABALHADA');
        $it[$cem ? 'minutos_extra_100' : 'minutos_extra_50'] += $r['extra'];
        $it['minutos_falta'] += $r['falta'];
        $it['minutos_atraso'] += $r['atraso'];
        $it['minutos_noturnos'] += $r['noturnos'];
        $it['minutos_abonados'] += $r['abonados'];
        $it['compensados'] += (int)($r['compensados'] ?? 0);
        if ($r['situacao'] === 'FALTA') $it['dias_falta']++;
        if ($r['trabalhados'] > 0) $it['dias_trabalhados']++;
        if ($r['extra'] > 120 && !$cem) $it['alertas'][] = date('d/m', strtotime($d)) . ': mais de 2h extras no dia (CLT, art. 59).';
        if (in_array($r['situacao'], ['INCOMPLETO', 'EM_ANDAMENTO'], true)) $pend[] = ['colaborador_id' => $colId, 'nome' => $c['nome'], 'data' => $d, 'motivo' => 'Marcação incompleta'];
      }
      $it['minutos_noturnos_reduzidos'] = (int)round($it['minutos_noturnos'] * 60 / 52.5);
      if ($it['banco_horas']) {
        $it['bh_saldo_anterior'] = ponto_bh_saldo($pdo, $companyId, $colId, $ate);
        $it['bh_creditos'] = $it['minutos_extra_50'] + $it['minutos_extra_100'] * $fator;
        $deb = (!empty($cfg['bh_debitar_faltas']) ? $it['minutos_falta'] : 0);
        $it['bh_debitos'] = $deb + $it['compensados'];
        $it['bh_vencidos'] = ponto_bh_a_vencer($pdo, $companyId, $colId, $ate, $validade, $it['bh_debitos']);
        $it['bh_saldo_final'] = $it['bh_saldo_anterior'] + $it['bh_creditos'] - $it['bh_debitos'] - $it['bh_vencidos'];
        $it['minutos_pagar_50'] = $it['bh_vencidos'];
        $it['minutos_descontar'] = empty($cfg['bh_debitar_faltas']) ? $it['minutos_falta'] : 0;
      } else {
        $it['minutos_pagar_50'] = $it['minutos_extra_50'];
        $it['minutos_pagar_100'] = $it['minutos_extra_100'];
        $it['minutos_descontar'] = $it['minutos_falta'];
      }
      $itens[$colId] = $it;
    }
    if ($itens) {
      $ids = array_keys($itens);
      $in = implode(',', array_fill(0, count($ids), '?'));
      $st = $pdo->prepare("SELECT t.colaborador_id, t.`data`, c.nome FROM ponto_tratamentos t JOIN ponto_colaboradores c ON c.company_id = t.company_id AND c.id = t.colaborador_id
        WHERE t.company_id = ? AND t.status = 'PENDENTE' AND t.`data` BETWEEN ? AND ? AND t.colaborador_id IN ($in)");
      $st->execute(array_merge([$companyId, $de, $ate], $ids));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pend[] = ['colaborador_id' => (int)$r['colaborador_id'], 'nome' => $r['nome'], 'data' => $r['data'], 'motivo' => 'Pedido de marcação aguardando aprovação'];
      $st = $pdo->prepare("SELECT o.colaborador_id, o.data_inicio, c.nome FROM ponto_ocorrencias o JOIN ponto_colaboradores c ON c.company_id = o.company_id AND c.id = o.colaborador_id
        WHERE o.company_id = ? AND o.status = 'PENDENTE' AND o.data_inicio <= ? AND o.data_fim >= ? AND o.colaborador_id IN ($in)");
      $st->execute(array_merge([$companyId, $ate, $de], $ids));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pend[] = ['colaborador_id' => (int)$r['colaborador_id'], 'nome' => $r['nome'], 'data' => $r['data_inicio'], 'motivo' => 'Justificativa aguardando aprovação'];
    }
    $tot = [];
    foreach (['minutos_trabalhados', 'minutos_extra_50', 'minutos_extra_100', 'minutos_falta', 'minutos_noturnos', 'minutos_abonados', 'bh_creditos', 'bh_debitos', 'bh_vencidos', 'minutos_pagar_50', 'minutos_pagar_100', 'minutos_descontar', 'dias_falta'] as $k) {
      $tot[$k] = array_sum(array_column($itens, $k));
    }
    return ['itens' => $itens, 'pendencias' => $pend, 'totais' => $tot, 'de' => $de, 'ate' => $ate];
  }
}

if (!function_exists('ponto_fechar')) {
  function ponto_fechar(PDO $pdo, int $companyId, int $estabId, string $comp): int {
    if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $comp)) throw new RuntimeException('Competência inválida.');
    $estab = ponto_estabelecimento_get($pdo, $companyId, $estabId);
    if (!$estab) throw new RuntimeException('Estabelecimento não encontrado.');
    [$de, $ate] = ponto_competencia($comp);
    $hoje = (new DateTimeImmutable('now', ponto_tz($estab, $pdo, $companyId)))->format('Y-m-d');
    if ($ate >= $hoje) throw new RuntimeException('Só é possível fechar o mês depois que ele terminar (a partir de ' . date('d/m/Y', strtotime($ate . ' +1 day')) . ').');
    $f = ponto_fechamento_get($pdo, $companyId, $estabId, $comp);
    if ($f && $f['status'] === 'FECHADO') throw new RuntimeException('Este mês já está fechado.');
    // Mês anterior precisa estar fechado (o saldo do banco passa de um mês para o outro).
    $ant = date('Y-m', strtotime($de . ' -1 month'));
    if (ponto_competencia($ant)[1] >= ponto_inicio_controle($pdo, $companyId)) {
      $fa = ponto_fechamento_get($pdo, $companyId, $estabId, $ant);
      if ((!$fa || $fa['status'] !== 'FECHADO') && ponto_fechamento_colaboradores($pdo, $companyId, $estabId, ...array_slice(ponto_competencia($ant), 0, 2))) {
        throw new RuntimeException('Feche antes o mês ' . ponto_comp_label($ant) . ' (o saldo do banco de horas passa de um mês para o outro).');
      }
    }
    $calc = ponto_fechamento_calcular($pdo, $companyId, $estabId, $comp);
    if ($calc['pendencias']) throw new RuntimeException('Há ' . count($calc['pendencias']) . ' pendência(s) no mês (marcações incompletas ou pedidos aguardando aprovação). Resolva antes de fechar.');
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $now = date('Y-m-d H:i:s');
    $label = ponto_comp_label($comp);
    $pdo->beginTransaction();
    try {
      if ($f) {
        $versao = (int)$f['versao'] + 1;
        $pdo->prepare("UPDATE ponto_fechamentos SET status = 'FECHADO', versao = ?, resumo_json = ?, fechado_por = ?, fechado_em = ? WHERE company_id = ? AND id = ?")
          ->execute([$versao, json_encode($calc['totais']), $uid ?: null, $now, $companyId, (int)$f['id']]);
        $fid = (int)$f['id'];
      } else {
        $versao = 1;
        $pdo->prepare("INSERT INTO ponto_fechamentos (company_id, estabelecimento_id, competencia, status, versao, resumo_json, fechado_por, fechado_em, created_at) VALUES (?,?,?,'FECHADO',1,?,?,?,?)")
          ->execute([$companyId, $estabId, $comp, json_encode($calc['totais']), $uid ?: null, $now, $now]);
        $fid = (int)$pdo->lastInsertId();
      }
      $insI = $pdo->prepare('INSERT INTO ponto_fechamento_itens (company_id, fechamento_id, versao, colaborador_id, minutos_previstos, minutos_trabalhados, minutos_extra_50, minutos_extra_100, minutos_falta, minutos_atraso,
        minutos_noturnos, minutos_noturnos_reduzidos, minutos_abonados, dias_falta, dias_trabalhados, banco_horas, bh_saldo_anterior, bh_creditos, bh_debitos, bh_vencidos, bh_saldo_final,
        minutos_pagar_50, minutos_pagar_100, minutos_descontar, alertas_json) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
      $insM = $pdo->prepare("INSERT INTO ponto_bh_movimentos (company_id, colaborador_id, `data`, competencia, tipo, minutos, origem, fechamento_id, descricao, created_by, created_at) VALUES (?,?,?,?,?,?,'FECHAMENTO',?,?,?,?)");
      foreach ($calc['itens'] as $colId => $it) {
        $insI->execute([$companyId, $fid, $versao, $colId, $it['minutos_previstos'], $it['minutos_trabalhados'], $it['minutos_extra_50'], $it['minutos_extra_100'], $it['minutos_falta'], $it['minutos_atraso'],
          $it['minutos_noturnos'], $it['minutos_noturnos_reduzidos'], $it['minutos_abonados'], $it['dias_falta'], $it['dias_trabalhados'], $it['banco_horas'], $it['bh_saldo_anterior'], $it['bh_creditos'],
          $it['bh_debitos'], $it['bh_vencidos'], $it['bh_saldo_final'], $it['minutos_pagar_50'], $it['minutos_pagar_100'], $it['minutos_descontar'], json_encode($it['alertas'], JSON_UNESCAPED_UNICODE)]);
        if (!$it['banco_horas']) continue;
        $mov = [['CREDITO', $it['bh_creditos'], 'Horas extras de ' . $label], ['DEBITO', -($it['bh_debitos'] - $it['compensados']), 'Atrasos e faltas de ' . $label],
          ['COMPENSACAO', -$it['compensados'], 'Folgas compensadas em ' . $label], ['VENCIMENTO', -$it['bh_vencidos'], 'Horas vencidas (pagar na folha de ' . $label . ')']];
        foreach ($mov as [$tipo, $min, $desc]) {
          if ($min === 0) continue;
          $insM->execute([$companyId, $colId, $ate, $comp, $tipo, $min, $fid, $desc, $uid ?: null, $now]);
        }
      }
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    ponto_audit($pdo, $companyId, 'fechar_mes', 'fechamentos', $fid, null, ['competencia' => $label, 'estabelecimento' => $estab['razao_social'], 'versao' => $versao, 'colaboradores' => count($calc['itens'])]);
    return $fid;
  }
}

if (!function_exists('ponto_reabrir')) {
  function ponto_reabrir(PDO $pdo, int $companyId, int $estabId, string $comp, string $motivo): void {
    $f = ponto_fechamento_get($pdo, $companyId, $estabId, $comp);
    if (!$f || $f['status'] !== 'FECHADO') throw new RuntimeException('Este mês não está fechado.');
    $motivo = trim(preg_replace('/\s+/u', ' ', $motivo) ?? '');
    if (mb_strlen($motivo) < 10) throw new RuntimeException('Explique o motivo da reabertura (mínimo de 10 letras). Ele fica registrado.');
    $st = $pdo->prepare("SELECT competencia FROM ponto_fechamentos WHERE company_id = ? AND estabelecimento_id = ? AND competencia > ? AND status = 'FECHADO' ORDER BY competencia DESC LIMIT 1");
    $st->execute([$companyId, $estabId, $comp]);
    if ($pos = $st->fetchColumn()) throw new RuntimeException('Reabra antes o mês ' . ponto_comp_label((string)$pos) . ' (reabertura sempre do último mês fechado para trás).');
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $now = date('Y-m-d H:i:s');
    $pdo->beginTransaction();
    try {
      $pdo->prepare("UPDATE ponto_fechamentos SET status = 'REABERTO', reaberto_por = ?, reaberto_em = ?, motivo_reabertura = ? WHERE company_id = ? AND id = ?")
        ->execute([$uid ?: null, $now, mb_substr($motivo, 0, 300), $companyId, (int)$f['id']]);
      $pdo->prepare("UPDATE ponto_bh_movimentos SET status = 'ESTORNADO', estornado_por = ?, estornado_em = ?, motivo_estorno = ? WHERE company_id = ? AND fechamento_id = ? AND status = 'ATIVO'")
        ->execute([$uid ?: null, $now, 'Reabertura de ' . ponto_comp_label($comp) . ': ' . mb_substr($motivo, 0, 250), $companyId, (int)$f['id']]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    ponto_audit($pdo, $companyId, 'reabrir_mes', 'fechamentos', (int)$f['id'], ['status' => 'FECHADO', 'versao' => (int)$f['versao']], ['status' => 'REABERTO', 'motivo' => $motivo]);
  }
}

if (!function_exists('ponto_fechamento_itens')) {
  /** Fotografia gravada no fechamento (versão atual). */
  function ponto_fechamento_itens(PDO $pdo, int $companyId, array $f): array {
    $st = $pdo->prepare('SELECT i.*, c.nome, c.matricula, c.cpf FROM ponto_fechamento_itens i JOIN ponto_colaboradores c ON c.company_id = i.company_id AND c.id = i.colaborador_id
      WHERE i.company_id = ? AND i.fechamento_id = ? AND i.versao = ? ORDER BY c.nome');
    $st->execute([$companyId, (int)$f['id'], (int)$f['versao']]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_fechamento_csv')) {
  /** Planilha (CSV ; para Excel) com os totais para a folha de pagamento / contador. */
  function ponto_fechamento_csv(array $linhas, string $nome): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $nome) . '"');
    header('Cache-Control: no-store');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $hm = static fn($m) => ponto_hm((int)$m, false);
    fputcsv($out, ['Matrícula', 'Colaborador', 'CPF', 'Previsto', 'Trabalhado', 'Extras 50%', 'Extras 100%', 'Débito (faltas/atrasos)', 'Dias de falta', 'Noturno (relógio)', 'Noturno (reduzida)',
      'Abonado', 'Banco de horas', 'Saldo anterior', 'Créditos', 'Débitos', 'Vencidos', 'Saldo final', 'Pagar 50%', 'Pagar 100%', 'Descontar'], ';');
    foreach ($linhas as $l) {
      fputcsv($out, [$l['matricula'], $l['nome'], ponto_fmt_doc((string)$l['cpf']), $hm($l['minutos_previstos']), $hm($l['minutos_trabalhados']), $hm($l['minutos_extra_50']), $hm($l['minutos_extra_100']),
        $hm($l['minutos_falta']), (int)$l['dias_falta'], $hm($l['minutos_noturnos']), $hm($l['minutos_noturnos_reduzidos']), $hm($l['minutos_abonados']), (int)$l['banco_horas'] ? 'Sim' : 'Não',
        $hm($l['bh_saldo_anterior']), $hm($l['bh_creditos']), $hm($l['bh_debitos']), $hm($l['bh_vencidos']), $hm($l['bh_saldo_final']), $hm($l['minutos_pagar_50']), $hm($l['minutos_pagar_100']), $hm($l['minutos_descontar'])], ';');
    }
    fclose($out);
    exit;
  }
}
