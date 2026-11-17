<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V6: registros cadastrais do ARP, AFD (Arquivo Fonte de Dados), AEJ (Arquivo
 * Eletrônico de Jornada) e Espelho de Ponto.
 *
 * Leiautes conferidos no gov.br (Ministério do Trabalho) em 17/11/2026:
 *  - AFD versão "004": registros 1 (cabeçalho), 2 (empresa), 5 (empregado), 6 (eventos),
 *    7 (marcação REP-P), 9 (trailer) e a linha de assinatura. Posições fixas, ISO-8859-1, CRLF,
 *    ordem do NSR, CRC-16/KERMIT nos tipos 1 a 5, SHA-256 encadeado no tipo 7.
 *  - AEJ versão "002": registros 01 a 08 e 99 separados por "|", ISO-8859-1, CRLF.
 *  - Os dois arquivos levam a linha "ASSINATURA_DIGITAL_EM_ARQUIVO_P7S"; a assinatura CAdES
 *    (arquivo .p7s, certificado ICP-Brasil) fica para a V7.
 * Os registros tipo 2 e 5 (Portaria 671, Anexo IX, item 6) usam a mesma numeração (NSR) das
 * marcações do estabelecimento e ficam em ponto_arp_eventos (só INSERT).
 *
 * Carregado no fim de config/ponto_marcacao.php.
 */

if (!function_exists('ponto_v6_ready')) {
  function ponto_v6_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'ponto_arp_eventos'");
      return $ready = ((int)$st->fetchColumn() === 1);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Formatação dos arquivos                                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_iso')) {
  /** Texto para ISO-8859-1 (o que não existir vira "?"), sem quebras de linha nem "|". */
  function ponto_iso(string $s): string {
    $s = trim(preg_replace('/[\x00-\x1F\x7F|]+/u', ' ', $s) ?? '');
    $out = @mb_convert_encoding($s, 'ISO-8859-1', 'UTF-8');
    return is_string($out) ? $out : '';
  }
}

if (!function_exists('ponto_fix_a')) {
  /** Campo alfanumérico de tamanho fixo: alinhado à esquerda, espaços à direita (em bytes ISO). */
  function ponto_fix_a(string $s, int $len): string {
    return str_pad(substr(ponto_iso($s), 0, $len), $len, ' ');
  }
}

if (!function_exists('ponto_fix_n')) {
  /** Campo numérico de tamanho fixo: zeros à esquerda. */
  function ponto_fix_n($v, int $len): string {
    $d = preg_replace('/\D/', '', (string)$v) ?? '';
    return str_pad(substr($d, -$len), $len, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('ponto_crc16_kermit')) {
  /** CRC-16/KERMIT (CCITT-TRUE), 4 dígitos hexadecimais. Ex.: "123456789" => "2189". */
  function ponto_crc16_kermit(string $bytes): string {
    $crc = 0x0000;
    $n = strlen($bytes);
    for ($i = 0; $i < $n; $i++) {
      $crc ^= ord($bytes[$i]);
      for ($b = 0; $b < 8; $b++) $crc = ($crc & 1) ? (($crc >> 1) ^ 0x8408) : ($crc >> 1);
    }
    return sprintf('%04X', $crc & 0xFFFF);
  }
}

if (!function_exists('ponto_doc_tipo')) {
  /** [tipo (1 CNPJ / 2 CPF), documento] do empregador (aceita CNPJ alfanumérico). */
  function ponto_doc_tipo(string $tipo, string $doc): array {
    $doc = strtoupper(preg_replace('/[^0-9A-Za-z]/', '', $doc) ?? '');
    return [$tipo === 'CPF' || strlen($doc) === 11 ? '2' : '1', $doc];
  }
}

if (!function_exists('ponto_inpi_num')) {
  function ponto_inpi_num(array $cfg): string {
    return ponto_fix_n((string)($cfg['rep_p_registro_inpi'] ?? ''), 17);
  }
}

if (!function_exists('ponto_responsavel_cpf')) {
  function ponto_responsavel_cpf(PDO $pdo, int $companyId): string {
    $c = ponto_digits((string)(ponto_config($pdo, $companyId)['responsavel_cpf'] ?? ''));
    return strlen($c) === 11 ? $c : '00000000000';
  }
}

/* ------------------------------------------------------------------------- */
/* Registros cadastrais no ARP (tipos 2 e 5)                                  */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_arp_lock')) {
  /** Trava a numeração do estabelecimento (chamar dentro de transação). */
  function ponto_arp_lock(PDO $pdo, int $companyId, int $estabId): array {
    $pdo->prepare('INSERT IGNORE INTO ponto_arp_nsr (company_id, estabelecimento_id, ultimo_nsr) VALUES (?, ?, 0)')->execute([$companyId, $estabId]);
    $st = $pdo->prepare('SELECT ultimo_nsr, ultimo_hash FROM ponto_arp_nsr WHERE company_id = ? AND estabelecimento_id = ? FOR UPDATE');
    $st->execute([$companyId, $estabId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: ['ultimo_nsr' => 0, 'ultimo_hash' => null];
  }
}

if (!function_exists('ponto_arp_empresa_dados')) {
  function ponto_arp_empresa_dados(array $estab): array {
    [$tp, $doc] = ponto_doc_tipo((string)($estab['tipo_documento'] ?? 'CNPJ'), (string)($estab['documento'] ?? ''));
    $local = trim(implode(', ', array_filter([trim(($estab['endereco'] ?? '') . ' ' . ($estab['numero'] ?? '')), (string)($estab['bairro'] ?? ''),
      trim(($estab['cidade'] ?? '') . (!empty($estab['uf']) ? '/' . $estab['uf'] : ''))])));
    return ['tipo_documento' => $tp, 'documento' => $doc, 'cno_caepf' => ponto_digits((string)($estab['cno'] ?? '')) ?: (ponto_digits((string)($estab['caepf'] ?? '')) ?: null),
      'razao_social' => mb_substr((string)($estab['razao_social'] ?? ''), 0, 150), 'local_servico' => mb_substr($local, 0, 100)];
  }
}

if (!function_exists('ponto_arp_evento_inserir')) {
  /** Grava um registro tipo 2/5/6 com o próximo NSR. Exige transação e ponto_arp_lock antes. */
  function ponto_arp_evento_inserir(PDO $pdo, int $companyId, array $estab, string $tipo, array $f): int {
    $st = $pdo->prepare('SELECT ultimo_nsr FROM ponto_arp_nsr WHERE company_id = ? AND estabelecimento_id = ?');
    $st->execute([$companyId, (int)$estab['id']]);
    $nsr = (int)$st->fetchColumn() + 1;
    $now = new DateTimeImmutable('now', ponto_tz($estab, $pdo, $companyId));
    $pdo->prepare('INSERT INTO ponto_arp_eventos (company_id, estabelecimento_id, nsr, tipo, data_hora_gravacao, fuso, responsavel_cpf, tipo_documento, documento, cno_caepf, razao_social, local_servico,
        operacao, colaborador_id, cpf, nome, evento, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, (int)$estab['id'], $nsr, $tipo, $now->format('Y-m-d H:i:s'), $now->format('O'), ponto_responsavel_cpf($pdo, $companyId),
        $f['tipo_documento'] ?? null, $f['documento'] ?? null, $f['cno_caepf'] ?? null, $f['razao_social'] ?? null, $f['local_servico'] ?? null,
        $f['operacao'] ?? null, $f['colaborador_id'] ?? null, $f['cpf'] ?? null, isset($f['nome']) ? mb_substr((string)$f['nome'], 0, 52) : null, $f['evento'] ?? null, date('Y-m-d H:i:s')]);
    $pdo->prepare('UPDATE ponto_arp_nsr SET ultimo_nsr = ?, updated_at = ? WHERE company_id = ? AND estabelecimento_id = ?')
      ->execute([$nsr, $now->format('Y-m-d H:i:s'), $companyId, (int)$estab['id']]);
    return $nsr;
  }
}

if (!function_exists('ponto_arp_tx')) {
  /** Executa $fn com a numeração travada; reaproveita a transação se já houver uma. */
  function ponto_arp_tx(PDO $pdo, int $companyId, int $estabId, callable $fn) {
    $own = !$pdo->inTransaction();
    if ($own) $pdo->beginTransaction();
    try {
      ponto_arp_lock($pdo, $companyId, $estabId);
      $r = $fn();
      if ($own) $pdo->commit();
      return $r;
    } catch (Throwable $e) {
      if ($own && $pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('ponto_arp_empresa_registrar')) {
  /** Registro tipo 2: na 1ª vez e sempre que os dados do empregador mudarem. Devolve se gravou. */
  function ponto_arp_empresa_registrar(PDO $pdo, int $companyId, int $estabId): bool {
    if (!ponto_v6_ready($pdo)) return false;
    $estab = ponto_estabelecimento_get($pdo, $companyId, $estabId);
    if (!$estab) return false;
    return (bool)ponto_arp_tx($pdo, $companyId, $estabId, static function () use ($pdo, $companyId, $estab) {
      $d = ponto_arp_empresa_dados($estab);
      $st = $pdo->prepare("SELECT tipo_documento, documento, cno_caepf, razao_social, local_servico FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND tipo = '2' ORDER BY nsr DESC LIMIT 1");
      $st->execute([$companyId, (int)$estab['id']]);
      $ult = $st->fetch(PDO::FETCH_ASSOC);
      if ($ult) {
        $igual = true;
        foreach ($d as $k => $v) if ((string)($ult[$k] ?? '') !== (string)($v ?? '')) $igual = false;
        if ($igual) return false;
      }
      ponto_arp_evento_inserir($pdo, $companyId, $estab, '2', $d);
      return true;
    });
  }
}

if (!function_exists('ponto_arp_colaborador_ultimo')) {
  function ponto_arp_colaborador_ultimo(PDO $pdo, int $companyId, int $estabId, int $colId): ?array {
    $st = $pdo->prepare("SELECT * FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND tipo = '5' AND colaborador_id = ? ORDER BY nsr DESC LIMIT 1");
    $st->execute([$companyId, $estabId, $colId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_arp_colaborador_registrar')) {
  /** Registro tipo 5 (I inclusão, A alteração, E exclusão) no estabelecimento. */
  function ponto_arp_colaborador_registrar(PDO $pdo, int $companyId, int $estabId, array $col, string $op): void {
    if (!ponto_v6_ready($pdo) || !in_array($op, ['I', 'A', 'E'], true)) return;
    $estab = ponto_estabelecimento_get($pdo, $companyId, $estabId);
    if (!$estab) return;
    ponto_arp_tx($pdo, $companyId, $estabId, static function () use ($pdo, $companyId, $estab, $col, $op) {
      $tem2 = $pdo->prepare("SELECT COUNT(*) FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND tipo = '2'");
      $tem2->execute([$companyId, (int)$estab['id']]);
      if (!(int)$tem2->fetchColumn()) ponto_arp_evento_inserir($pdo, $companyId, $estab, '2', ponto_arp_empresa_dados($estab));
      ponto_arp_evento_inserir($pdo, $companyId, $estab, '5', ['operacao' => $op, 'colaborador_id' => (int)$col['id'], 'cpf' => ponto_digits((string)$col['cpf']), 'nome' => (string)$col['nome']]);
    });
  }
}

if (!function_exists('ponto_arp_colaborador_ativo')) {
  /** O empregado está incluído (última operação I ou A) neste estabelecimento? */
  function ponto_arp_colaborador_ativo(PDO $pdo, int $companyId, int $estabId, int $colId): ?array {
    $u = ponto_arp_colaborador_ultimo($pdo, $companyId, $estabId, $colId);
    return $u && $u['operacao'] !== 'E' ? $u : null;
  }
}

if (!function_exists('ponto_arp_colaborador_alterado')) {
  /** Gancho do cadastro de colaborador: inclui, altera ou exclui no REP conforme o caso. */
  function ponto_arp_colaborador_alterado(PDO $pdo, int $companyId, ?array $old, ?array $new): void {
    if (!$new || !ponto_v6_ready($pdo)) return;
    $id = (int)$new['id'];
    $eOld = (int)($old['estabelecimento_id'] ?? 0);
    $eNew = (int)($new['estabelecimento_id'] ?? 0);
    if ($eOld && $eOld !== $eNew && ponto_arp_colaborador_ativo($pdo, $companyId, $eOld, $id)) ponto_arp_colaborador_registrar($pdo, $companyId, $eOld, $old, 'E');
    if (!$eNew) return;
    $ativo = ponto_arp_colaborador_ativo($pdo, $companyId, $eNew, $id);
    $sai = $new['status'] === 'DESLIGADO' || !(int)$new['controla_ponto'];
    if ($sai) { if ($ativo) ponto_arp_colaborador_registrar($pdo, $companyId, $eNew, $new, 'E'); return; }
    if (!$ativo) { ponto_arp_colaborador_registrar($pdo, $companyId, $eNew, $new, 'I'); return; }
    if ((string)$ativo['cpf'] !== ponto_digits((string)$new['cpf']) || (string)$ativo['nome'] !== mb_substr((string)$new['nome'], 0, 52)) {
      ponto_arp_colaborador_registrar($pdo, $companyId, $eNew, $new, 'A');
    }
  }
}

if (!function_exists('ponto_arp_prepara_marcacao')) {
  /**
   * Chamado por ponto_marcar já com a numeração travada: garante que o empregador (tipo 2) e o
   * empregado (tipo 5 "I") estejam registrados no REP antes da 1ª marcação.
   */
  function ponto_arp_prepara_marcacao(PDO $pdo, int $companyId, array $estab, array $col): void {
    if (!ponto_v6_ready($pdo)) return;
    $tem2 = $pdo->prepare("SELECT COUNT(*) FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND tipo = '2'");
    $tem2->execute([$companyId, (int)$estab['id']]);
    if (!(int)$tem2->fetchColumn()) ponto_arp_evento_inserir($pdo, $companyId, $estab, '2', ponto_arp_empresa_dados($estab));
    if (!ponto_arp_colaborador_ativo($pdo, $companyId, (int)$estab['id'], (int)$col['id'])) {
      ponto_arp_evento_inserir($pdo, $companyId, $estab, '5', ['operacao' => 'I', 'colaborador_id' => (int)$col['id'], 'cpf' => ponto_digits((string)$col['cpf']), 'nome' => (string)$col['nome']]);
    }
  }
}

if (!function_exists('ponto_arp_sincronizar')) {
  /** Registra no REP o empregador e os empregados ativos que ainda não estão lá. Devolve [tipo2, tipo5]. */
  function ponto_arp_sincronizar(PDO $pdo, int $companyId, int $estabId): array {
    if (!ponto_v6_ready($pdo)) return [0, 0];
    $n2 = ponto_arp_empresa_registrar($pdo, $companyId, $estabId) ? 1 : 0;
    $n5 = 0;
    $st = $pdo->prepare("SELECT * FROM ponto_colaboradores WHERE company_id = ? AND estabelecimento_id = ? AND controla_ponto = 1 AND status <> 'DESLIGADO' ORDER BY id");
    $st->execute([$companyId, $estabId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
      if (!ponto_arp_colaborador_ativo($pdo, $companyId, $estabId, (int)$c['id'])) { ponto_arp_colaborador_registrar($pdo, $companyId, $estabId, $c, 'I'); $n5++; }
    }
    return [$n2, $n5];
  }
}

/* ------------------------------------------------------------------------- */
/* AFD                                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_afd_pendencias')) {
  /** O que falta configurar para o AFD/AEJ saírem completos. */
  function ponto_afd_pendencias(PDO $pdo, int $companyId): array {
    $cfg = ponto_config($pdo, $companyId);
    $p = [];
    if (trim((string)$cfg['rep_p_registro_inpi']) === '') $p[] = 'Número do registro do programa no INPI (Configurações > Dados do programa de ponto).';
    if (trim((string)$cfg['rep_p_cnpj_desenvolvedor']) === '') $p[] = 'CNPJ do desenvolvedor do programa (Configurações).';
    if (trim((string)$cfg['rep_p_desenvolvedor']) === '') $p[] = 'Nome do desenvolvedor do programa (Configurações).';
    if (strlen(ponto_digits((string)($cfg['responsavel_cpf'] ?? ''))) !== 11) $p[] = 'CPF do responsável pelos cadastros do ponto (Configurações).';
    return $p;
  }
}

if (!function_exists('ponto_afd_gerar')) {
  /**
   * AFD do estabelecimento no período (data da marcação/gravação entre $de e $ate), na ordem
   * do NSR. Devolve [nome do arquivo, conteúdo ISO-8859-1, contagem por tipo].
   */
  function ponto_afd_gerar(PDO $pdo, int $companyId, int $estabId, string $de, string $ate): array {
    $estab = ponto_estabelecimento_get($pdo, $companyId, $estabId);
    if (!$estab) throw new RuntimeException('Estabelecimento não encontrado.');
    $cfg = ponto_config($pdo, $companyId);
    $emp = ponto_arp_empresa_dados($estab);
    [$tpDev, $docDev] = ponto_doc_tipo('', (string)$cfg['rep_p_cnpj_desenvolvedor']);
    $now = new DateTimeImmutable('now', ponto_tz($estab, $pdo, $companyId));
    $regs = [];
    $st = $pdo->prepare('SELECT nsr, cpf, data_hora, fuso, data_hora_gravacao, fuso_gravacao, coletor_codigo, offline, hash FROM ponto_arp_marcacoes WHERE company_id = ? AND estabelecimento_id = ? AND `data` BETWEEN ? AND ?');
    $st->execute([$companyId, $estabId, $de, $ate]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
      $regs[(int)$m['nsr']] = ['7', ponto_fix_n($m['nsr'], 9) . '7' . ponto_afd_dh((string)$m['data_hora'], (string)$m['fuso']) . ponto_fix_n($m['cpf'], 12)
        . ponto_afd_dh((string)$m['data_hora_gravacao'], (string)$m['fuso_gravacao']) . ponto_fix_n($m['coletor_codigo'], 2) . ((int)$m['offline'] ? '1' : '0') . (string)$m['hash']];
    }
    if (ponto_v6_ready($pdo)) {
      $st = $pdo->prepare('SELECT * FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ? AND data_hora_gravacao BETWEEN ? AND ?');
      $st->execute([$companyId, $estabId, $de . ' 00:00:00', $ate . ' 23:59:59']);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) {
        $dh = ponto_afd_dh((string)$e['data_hora_gravacao'], (string)$e['fuso']);
        $base = ponto_fix_n($e['nsr'], 9) . $e['tipo'] . $dh;
        if ($e['tipo'] === '2') {
          $line = $base . ponto_fix_n($e['responsavel_cpf'], 14) . $e['tipo_documento'] . ponto_fix_a((string)$e['documento'], 14) . ponto_fix_n((string)$e['cno_caepf'], 14)
            . ponto_fix_a((string)$e['razao_social'], 150) . ponto_fix_a((string)$e['local_servico'], 100);
          $regs[(int)$e['nsr']] = ['2', $line . ponto_crc16_kermit($line)];
        } elseif ($e['tipo'] === '5') {
          $line = $base . $e['operacao'] . ponto_fix_n($e['cpf'], 12) . ponto_fix_a((string)$e['nome'], 52) . ponto_fix_a('', 4) . ponto_fix_n($e['responsavel_cpf'], 11);
          $regs[(int)$e['nsr']] = ['5', $line . ponto_crc16_kermit($line)];
        } elseif ($e['tipo'] === '6') {
          $regs[(int)$e['nsr']] = ['6', $base . ponto_fix_n($e['evento'], 2)];
        }
      }
    }
    ksort($regs);
    $head = '000000000' . '1' . $emp['tipo_documento'] . ponto_fix_a($emp['documento'], 14) . ponto_fix_n((string)$emp['cno_caepf'], 14) . ponto_fix_a($emp['razao_social'], 150)
      . ponto_inpi_num($cfg) . $de . $ate . ponto_afd_dh($now->format('Y-m-d H:i:s'), $now->format('O')) . '004' . $tpDev . ponto_fix_a($docDev, 14) . ponto_fix_a('', 30);
    $lines = [$head . ponto_crc16_kermit($head)];
    $qt = ['2' => 0, '3' => 0, '4' => 0, '5' => 0, '6' => 0, '7' => 0];
    foreach ($regs as [$tipo, $line]) { $lines[] = $line; $qt[$tipo]++; }
    $lines[] = '999999999' . ponto_fix_n($qt['2'], 9) . ponto_fix_n($qt['3'], 9) . ponto_fix_n($qt['4'], 9) . ponto_fix_n($qt['5'], 9) . ponto_fix_n($qt['6'], 9) . ponto_fix_n($qt['7'], 9) . '9';
    $lines[] = str_pad('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', 100, ' ');
    $nome = 'AFD' . ponto_inpi_num($cfg) . $emp['documento'] . 'REP_P.txt';
    return [$nome, implode("\r\n", $lines) . "\r\n", $qt];
  }
}

/* ------------------------------------------------------------------------- */
/* Horário contratual (AEJ 04 / Espelho)                                      */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_horario_chave')) {
  function ponto_horario_chave(array $periodos): string {
    return implode(' ', array_map(static fn($q) => $q['ini'] . '-' . $q['fim'], $periodos));
  }
}

if (!function_exists('ponto_horario_duracao')) {
  /** Duração da jornada em minutos, com a hora noturna reduzida (22h-5h: 52min30s = 1h). */
  function ponto_horario_duracao(array $periodos, string $date = '2000-01-03'): int {
    $tot = 0; $not = 0;
    foreach (ponto_periodo_abs($date, $periodos) as [$a, $b]) { $tot += intdiv($b - $a, 60); $not += ponto_minutos_noturnos($a, $b); }
    return $tot - $not + (int)round($not * 60 / 52.5);
  }
}

/* ------------------------------------------------------------------------- */
/* AEJ                                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_aej_gerar')) {
  /** AEJ do estabelecimento no período. Devolve [nome do arquivo, conteúdo ISO-8859-1, contagem]. */
  function ponto_aej_gerar(PDO $pdo, int $companyId, int $estabId, string $de, string $ate): array {
    $estab = ponto_estabelecimento_get($pdo, $companyId, $estabId);
    if (!$estab) throw new RuntimeException('Estabelecimento não encontrado.');
    $cfg = ponto_config($pdo, $companyId);
    $emp = ponto_arp_empresa_dados($estab);
    $tz = ponto_tz($estab, $pdo, $companyId);
    $now = new DateTimeImmutable('now', $tz);
    $reg = static function (array $f): string { return implode('|', array_map(static fn($v) => ponto_iso((string)$v), $f)); };
    $out = ['01' => [], '02' => [], '03' => [], '04' => [], '05' => [], '06' => [], '07' => [], '08' => []];
    $caepf = ponto_digits((string)($estab['caepf'] ?? ''));
    $cno = ponto_digits((string)($estab['cno'] ?? ''));
    $out['01'][] = $reg(['01', $emp['tipo_documento'], $emp['documento'], $caepf, $cno, $emp['razao_social'], $de, $ate, ponto_afd_dh($now->format('Y-m-d H:i:s'), $now->format('O')), '002']);
    $out['02'][] = $reg(['02', '1', '3', ponto_inpi_num($cfg)]);
    $st = $pdo->prepare("SELECT * FROM ponto_colaboradores WHERE company_id = ? AND estabelecimento_id = ? AND controla_ponto = 1 AND data_admissao <= ? AND (data_demissao IS NULL OR data_demissao >= ?) ORDER BY nome");
    $st->execute([$companyId, $estabId, $ate, $de]);
    $hor = []; $vinc = 0;
    $bhOk = function_exists('ponto_v5_ready') && ponto_v5_ready($pdo);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
      $v = ++$vinc;
      $out['03'][] = $reg(['03', $v, ponto_fix_n($c['cpf'], 11), $c['nome']]);
      $dias = ponto_apurar_periodo($pdo, $companyId, (int)$c['id'], $de, $ate, false);
      $ultCod = '';
      foreach ($dias as $d => $r) {
        $cod = '';
        if (!empty($r['previsto']['trabalha']) && $r['previsto']['periodos']) {
          $k = ponto_horario_chave($r['previsto']['periodos']);
          if (!isset($hor[$k])) {
            $hor[$k] = 'H' . (count($hor) + 1);
            $f = ['04', $hor[$k], ponto_horario_duracao($r['previsto']['periodos'])];
            foreach ($r['previsto']['periodos'] as $q) { $f[] = str_replace(':', '', $q['ini']); $f[] = str_replace(':', '', $q['fim']); }
            $out['04'][] = $reg($f);
          }
          $cod = $ultCod = $hor[$k];
        }
        $i = 0;
        foreach ($r['marcacoes'] as $m) {
          if (!empty($m['pendente'])) continue;
          $dh = ponto_afd_dh((string)$m['data_hora'], (new DateTimeImmutable((string)$m['data_hora'], $tz))->format('O'));
          $orig = $m['origem'] === 'ORIGINAL';
          if (!empty($m['desconsiderada'])) {
            $out['05'][] = $reg(['05', $v, $dh, $orig ? '1' : '', 'D', intdiv($i, 2) + 1, $orig ? 'O' : 'I', '', mb_substr((string)($m['motivo'] ?? 'Desconsiderada'), 0, 150)]);
            continue;
          }
          $tp = $i % 2 === 0 ? 'E' : 'S';
          $seq = intdiv($i, 2) + 1;
          $out['05'][] = $reg(['05', $v, $dh, $orig ? '1' : '', $tp, $seq, $orig ? 'O' : 'I', ($tp === 'E' && $seq === 1) ? ($cod ?: $ultCod ?: 'H1') : '', $orig ? '' : mb_substr((string)($m['motivo'] ?? ''), 0, 150)]);
          $i++;
        }
        if ($r['situacao'] === 'FALTA') $out['07'][] = $reg(['07', $v, '2', $d, '', '']);
      }
      if ($bhOk) {
        $sb = $pdo->prepare("SELECT `data`, tipo, minutos FROM ponto_bh_movimentos WHERE company_id = ? AND colaborador_id = ? AND status = 'ATIVO' AND `data` BETWEEN ? AND ? ORDER BY `data`, id");
        $sb->execute([$companyId, (int)$c['id'], $de, $ate]);
        foreach ($sb->fetchAll(PDO::FETCH_ASSOC) as $b) {
          if (in_array($b['tipo'], ['PAGAMENTO', 'VENCIMENTO'], true)) continue;
          $out['07'][] = $reg(['07', $v, '3', $b['data'], abs((int)$b['minutos']), (int)$b['minutos'] > 0 ? '1' : '2']);
        }
      }
    }
    [$tpDev, $docDev] = ponto_doc_tipo('', (string)$cfg['rep_p_cnpj_desenvolvedor']);
    $out['08'][] = $reg(['08', 'Nexa Ponto', mb_substr((string)($cfg['rep_p_versao'] ?: '1'), 0, 8), $tpDev, $docDev, (string)$cfg['rep_p_desenvolvedor'], (string)($cfg['rep_p_email_desenvolvedor'] ?? '')]);
    $lines = [];
    foreach ($out as $l) foreach ($l as $x) $lines[] = $x;
    $qt = array_map('count', $out);
    $lines[] = $reg(array_merge(['99'], array_values($qt)));
    $lines[] = str_pad('ASSINATURA_DIGITAL_EM_ARQUIVO_P7S', 100, ' ');
    return ['AEJ_' . $emp['documento'] . '_' . str_replace('-', '', $de) . '_' . str_replace('-', '', $ate) . '.txt', implode("\r\n", $lines) . "\r\n", $qt];
  }
}

if (!function_exists('ponto_arquivo_download')) {
  function ponto_arquivo_download(string $nome, string $conteudo): void {
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/plain; charset=ISO-8859-1');
    header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9_.-]/', '_', $nome) . '"');
    header('Content-Length: ' . strlen($conteudo));
    header('Cache-Control: no-store');
    echo $conteudo;
    exit;
  }
}

/* ------------------------------------------------------------------------- */
/* Espelho de Ponto (art. 84)                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_espelho_dados')) {
  function ponto_espelho_dados(PDO $pdo, int $companyId, int $colId, string $de, string $ate): array {
    $col = ponto_colaborador_get($pdo, $companyId, $colId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $estab = $col['estabelecimento_id'] ? ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']) : null;
    $cargo = null;
    if (!empty($col['cargo_id'])) { $s = $pdo->prepare('SELECT descricao FROM ponto_cargos WHERE company_id = ? AND id = ?'); $s->execute([$companyId, (int)$col['cargo_id']]); $cargo = $s->fetchColumn() ?: null; }
    $dias = ponto_apurar_periodo($pdo, $companyId, $colId, $de, $ate, false);
    $hor = [];
    foreach ($dias as $d => $r) {
      if (empty($r['previsto']['trabalha']) || !$r['previsto']['periodos']) continue;
      $k = ponto_horario_chave($r['previsto']['periodos']);
      $hor[$k]['dias'][(int)date('w', strtotime($d))] = true;
      $hor[$k]['duracao'] = ponto_horario_duracao($r['previsto']['periodos']);
      $hor[$k]['periodos'] = $r['previsto']['periodos'];
    }
    foreach ($dias as &$r) $r['duracao_reduzida'] = $r['trabalhados'] - $r['noturnos'] + (int)round($r['noturnos'] * 60 / 52.5);
    unset($r);
    return ['col' => $col, 'estab' => $estab, 'cargo' => $cargo, 'dias' => $dias, 'horarios' => $hor, 'totais' => ponto_apuracao_totais($dias), 'de' => $de, 'ate' => $ate];
  }
}
