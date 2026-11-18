<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V2: marcação online, coletores e ARP (Armazenamento de Registro de Ponto).
 *
 * Regras que não podem ser quebradas:
 *  - A marcação gravada em ponto_arp_marcacoes nunca é alterada nem apagada (só INSERT).
 *  - A hora oficial é a do servidor (nunca a do aparelho), no fuso do estabelecimento.
 *  - NSR por estabelecimento, gerado com bloqueio de linha (SELECT ... FOR UPDATE).
 *  - Código hash SHA-256 = campos 1 a 7 do registro tipo 7 do AFD + hash do registro anterior.
 *  - Localização é informação complementar: fora da área a marcação vale e fica sinalizada.
 *  - Login nunca gera marcação: só o clique do trabalhador em "Registrar ponto".
 */

require_once __DIR__ . '/ponto_module.php';

const PONTO_PIN_MAX_TENTATIVAS = 5;
const PONTO_PIN_BLOQUEIO_MIN = 15;
const PONTO_COLETOR_COOKIE = 'nexa_ponto_coletor';
const PONTO_INTENCOES = [
  'ENTRADA' => 'Entrada',
  'SAIDA_INTERVALO' => 'Saída para intervalo',
  'RETORNO_INTERVALO' => 'Volta do intervalo',
  'SAIDA' => 'Saída',
];
const PONTO_COLETOR_CODIGOS = ['01' => 'Aplicativo mobile', '02' => 'Navegador (browser)', '03' => 'Aplicativo desktop', '04' => 'Dispositivo eletrônico', '05' => 'Outro dispositivo eletrônico'];

if (!function_exists('ponto_v2_ready')) {
  function ponto_v2_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT (SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ponto_arp_marcacoes','ponto_arp_nsr','ponto_coletores'))
        + (SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'ponto_colaboradores' AND column_name = 'pin_hash')");
      return $ready = ((int)$st->fetchColumn() === 4);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('ponto_uuid')) {
  function ponto_uuid(): string {
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 0x0f) | 0x40);
    $b[8] = chr((ord($b[8]) & 0x3f) | 0x80);
    return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
  }
}

if (!function_exists('ponto_client_ip')) {
  function ponto_client_ip(): string {
    return mb_substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
  }
}

if (!function_exists('ponto_interno')) {
  /** Segredos internos da empresa (assinar links de comprovante e tíquetes do terminal). */
  function ponto_interno(PDO $pdo, int $companyId): array {
    $cfg = [];
    try { $cfg = config_store_get($pdo, $companyId, 'ponto_interno') ?? []; } catch (Throwable $e) {}
    if (empty($cfg['segredo']) || strlen((string)$cfg['segredo']) < 32) {
      $cfg['segredo'] = bin2hex(random_bytes(32));
      config_store_set($pdo, $companyId, 'ponto_interno', $cfg);
    }
    return $cfg;
  }
}

if (!function_exists('ponto_sign')) {
  function ponto_sign(PDO $pdo, int $companyId, string $data): string {
    return hash_hmac('sha256', $companyId . '|' . $data, (string)ponto_interno($pdo, $companyId)['segredo']);
  }
}

/* ------------------------------------------------------------------------- */
/* Tempo oficial e formato do AFD                                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_estabelecimento_get')) {
  function ponto_estabelecimento_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_estabelecimentos WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_tz')) {
  function ponto_tz(?array $estab, PDO $pdo, int $companyId): DateTimeZone {
    $tz = (string)($estab['timezone'] ?? '') ?: (string)(ponto_config($pdo, $companyId)['timezone'] ?? 'America/Sao_Paulo');
    try { return new DateTimeZone($tz); } catch (Throwable $e) { return new DateTimeZone('America/Sao_Paulo'); }
  }
}

if (!function_exists('ponto_afd_dh')) {
  /** Data e hora no formato DH do AFD: AAAA-MM-ddThh:mm:00ZZZZZ (segundos sempre 00). */
  function ponto_afd_dh(string $dataHora, string $fuso): string {
    return substr($dataHora, 0, 10) . 'T' . substr($dataHora, 11, 5) . ':00' . $fuso;
  }
}

if (!function_exists('ponto_afd_hash')) {
  /**
   * Código hash do registro tipo 7 (REP-P): SHA-256 da concatenação dos campos 1 a 7 como
   * aparecem no AFD (NSR 9 dígitos, "7", DH da marcação, CPF 12 dígitos, DH da gravação,
   * identificador do coletor, online/offline) + o hash do registro anterior, se existir.
   */
  function ponto_afd_hash(int $nsr, string $dhMarcacao, string $cpf, string $dhGravacao, string $coletor, int $offline, ?string $hashAnterior): string {
    $campos = str_pad((string)$nsr, 9, '0', STR_PAD_LEFT) . '7' . $dhMarcacao . str_pad(ponto_digits($cpf), 12, '0', STR_PAD_LEFT)
      . $dhGravacao . str_pad($coletor, 2, '0', STR_PAD_LEFT) . ($offline ? '1' : '0');
    return hash('sha256', $campos . (string)($hashAnterior ?? ''));
  }
}

/* ------------------------------------------------------------------------- */
/* Identificação: PIN e cartão                                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_pin_validate')) {
  function ponto_pin_validate(string $pin): void {
    if (!preg_match('/^\d{4,6}$/', $pin)) throw new RuntimeException('O PIN deve ter de 4 a 6 números.');
    if (preg_match('/^(\d)\1+$/', $pin) || strpos('0123456789', $pin) !== false || strpos('9876543210', $pin) !== false) {
      throw new RuntimeException('PIN muito fácil (números repetidos ou em sequência). Escolha outro.');
    }
  }
}

if (!function_exists('ponto_pin_set')) {
  /** Define/troca o PIN (4 a 6 números). '' remove. Guarda só o hash. */
  function ponto_pin_set(PDO $pdo, int $companyId, int $colaboradorId, string $pin): void {
    $col = ponto_colaborador_get($pdo, $companyId, $colaboradorId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $pin = trim($pin);
    if ($pin !== '') ponto_pin_validate($pin);
    $pdo->prepare('UPDATE ponto_colaboradores SET pin_hash = ?, pin_tentativas = 0, pin_bloqueado_ate = NULL WHERE company_id = ? AND id = ?')
      ->execute([$pin === '' ? null : password_hash($pin, PASSWORD_DEFAULT), $companyId, $colaboradorId]);
    ponto_audit($pdo, $companyId, $pin === '' ? 'remover_pin' : (!empty($col['pin_hash']) ? 'trocar_pin' : 'definir_pin'), 'colaboradores', $colaboradorId, null, null, $colaboradorId);
  }
}

if (!function_exists('ponto_pin_verify')) {
  /** Confere o PIN com limite de tentativas (bloqueia por 15 minutos após 5 erros). */
  function ponto_pin_verify(PDO $pdo, int $companyId, array $col, string $pin): void {
    if (empty($col['pin_hash'])) throw new RuntimeException('Este colaborador ainda não tem PIN. Peça ao responsável pelo ponto para cadastrar.');
    if (!empty($col['pin_bloqueado_ate']) && strtotime((string)$col['pin_bloqueado_ate']) > time()) {
      throw new RuntimeException('PIN bloqueado por excesso de tentativas. Tente de novo às ' . date('H:i', strtotime((string)$col['pin_bloqueado_ate'])) . '.');
    }
    if (password_verify($pin, (string)$col['pin_hash'])) {
      if ((int)$col['pin_tentativas'] > 0) $pdo->prepare('UPDATE ponto_colaboradores SET pin_tentativas = 0, pin_bloqueado_ate = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, (int)$col['id']]);
      return;
    }
    $n = (int)$col['pin_tentativas'] + 1;
    $lock = $n >= PONTO_PIN_MAX_TENTATIVAS ? date('Y-m-d H:i:s', time() + PONTO_PIN_BLOQUEIO_MIN * 60) : null;
    $pdo->prepare('UPDATE ponto_colaboradores SET pin_tentativas = ?, pin_bloqueado_ate = ? WHERE company_id = ? AND id = ?')->execute([$lock ? 0 : $n, $lock, $companyId, (int)$col['id']]);
    if ($lock) {
      ponto_audit($pdo, $companyId, 'pin_bloqueado', 'colaboradores', (int)$col['id'], null, ['ip' => ponto_client_ip()], (int)$col['id']);
      throw new RuntimeException('PIN incorreto. Por segurança, o PIN ficou bloqueado por ' . PONTO_PIN_BLOQUEIO_MIN . ' minutos.');
    }
    throw new RuntimeException('PIN incorreto. Restam ' . (PONTO_PIN_MAX_TENTATIVAS - $n) . ' tentativa(s).');
  }
}

if (!function_exists('ponto_colaborador_apto')) {
  /** Confere se o colaborador pode registrar ponto (cadastro completo). */
  function ponto_colaborador_apto(array $col): void {
    if ((string)$col['status'] === 'DESLIGADO') throw new RuntimeException('Cadastro de colaborador desligado.');
    if (!(int)$col['controla_ponto']) throw new RuntimeException('Este colaborador não está marcado para controlar ponto.');
    if (empty($col['estabelecimento_id'])) throw new RuntimeException('Cadastro sem estabelecimento. Peça ao responsável pelo ponto para completar o cadastro.');
  }
}

if (!function_exists('ponto_identificar')) {
  /**
   * Identificação no terminal: cartão RFID, ou matrícula/CPF + PIN. $coletor limita os meios.
   * Nunca diz se a matrícula existe quando o PIN está errado em outra (mensagem genérica).
   */
  function ponto_identificar(PDO $pdo, int $companyId, array $in, array $coletor): array {
    $cfg = ponto_config($pdo, $companyId);
    $rfid = trim((string)($in['rfid'] ?? ''));
    if ($rfid !== '') {
      if (empty($cfg['permitir_rfid']) || empty($coletor['permitir_rfid'])) throw new RuntimeException('Cartão não habilitado neste aparelho.');
      $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND rfid_hash = ? LIMIT 1');
      $st->execute([$companyId, ponto_rfid_hash($companyId, $rfid)]);
      $col = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$col) { usleep(300000); throw new RuntimeException('Cartão não cadastrado.'); }
      ponto_colaborador_apto($col);
      return [$col, 'RFID'];
    }
    if (empty($coletor['permitir_pin'])) throw new RuntimeException('Identificação por PIN não habilitada neste aparelho.');
    $login = trim((string)($in['login'] ?? ''));
    $pin = trim((string)($in['pin'] ?? ''));
    if ($login === '' || $pin === '') throw new RuntimeException('Informe a matrícula (ou CPF) e o PIN.');
    $cpf = ponto_digits($login);
    $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND (matricula = ? OR (? <> \'\' AND cpf = ?)) ORDER BY id LIMIT 1');
    $st->execute([$companyId, $login, strlen($cpf) === 11 ? $cpf : '', $cpf]);
    $col = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$col) { usleep(400000); throw new RuntimeException('Matrícula/CPF ou PIN incorreto.'); }
    ponto_pin_verify($pdo, $companyId, $col, $pin);
    ponto_colaborador_apto($col);
    return [$col, 'PIN'];
  }
}

if (!function_exists('ponto_ticket')) {
  /** Tíquete do terminal entre "identificar" e "registrar" (vale 2 minutos). */
  function ponto_ticket(PDO $pdo, int $companyId, int $colaboradorId, int $coletorId, string $ident): string {
    $exp = time() + 120;
    $payload = $colaboradorId . '.' . $coletorId . '.' . $ident . '.' . $exp;
    return $payload . '.' . substr(ponto_sign($pdo, $companyId, 'ticket|' . $payload), 0, 40);
  }
  function ponto_ticket_check(PDO $pdo, int $companyId, string $ticket, int $coletorId): array {
    $p = explode('.', $ticket);
    if (count($p) !== 5) throw new RuntimeException('Identificação expirada. Identifique-se de novo.');
    [$col, $colet, $ident, $exp, $sig] = $p;
    $payload = $col . '.' . $colet . '.' . $ident . '.' . $exp;
    if (!hash_equals(substr(ponto_sign($pdo, $companyId, 'ticket|' . $payload), 0, 40), $sig) || (int)$colet !== $coletorId || (int)$exp < time()) {
      throw new RuntimeException('Identificação expirada. Identifique-se de novo.');
    }
    return [(int)$col, in_array($ident, ['PIN', 'RFID'], true) ? $ident : 'PIN'];
  }
}

/* ------------------------------------------------------------------------- */
/* Situação do dia e próximo evento                                           */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_marcacoes_dia')) {
  /** Marcações do colaborador no dia (data no fuso do estabelecimento). */
  function ponto_marcacoes_dia(PDO $pdo, int $companyId, int $colaboradorId, string $date): array {
    $st = $pdo->prepare('SELECT id, nsr, data_hora, fuso, intencao, geofence, distancia_m, identificacao, coletor_codigo FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND `data` = ? ORDER BY data_hora, id');
    $st->execute([$companyId, $colaboradorId, $date]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_situacao')) {
  /**
   * Situação do colaborador agora e o próximo evento esperado, pelas marcações do dia e pela
   * jornada prevista. Só sugestão: o trabalhador pode escolher outro tipo antes de registrar.
   */
  function ponto_situacao(PDO $pdo, int $companyId, array $col): array {
    $estab = ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']);
    $now = new DateTimeImmutable('now', ponto_tz($estab, $pdo, $companyId));
    $hoje = $now->format('Y-m-d');
    $marks = ponto_marcacoes_dia($pdo, $companyId, (int)$col['id'], $hoje);
    $prev = ponto_previsto_do_dia($pdo, $companyId, $col, $hoje);
    $n = count($marks);
    $periodos = max(1, count($prev['periodos']));
    if ($n === 0) { $sit = 'NAO_INICIOU'; $next = 'ENTRADA'; }
    elseif ($n % 2 === 1) { $sit = 'TRABALHANDO'; $next = $n < 2 * $periodos - 1 ? 'SAIDA_INTERVALO' : 'SAIDA'; }
    else { $sit = $n < 2 * $periodos ? 'INTERVALO' : 'ENCERROU'; $next = $n < 2 * $periodos ? 'RETORNO_INTERVALO' : 'ENTRADA'; }
    $labels = ['NAO_INICIOU' => 'Ainda não registrou entrada', 'TRABALHANDO' => 'Trabalhando', 'INTERVALO' => 'Em intervalo', 'ENCERROU' => 'Jornada encerrada'];
    return ['situacao' => $sit, 'situacao_label' => $labels[$sit], 'proximo' => $next, 'proximo_label' => PONTO_INTENCOES[$next],
      'marcacoes' => $marks, 'previsto' => $prev, 'hoje' => $hoje, 'agora' => $now, 'estabelecimento' => $estab];
  }
}

/* ------------------------------------------------------------------------- */
/* Localização (geofence)                                                     */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_geofence')) {
  /**
   * Compara a posição com o local de trabalho do colaborador (ou os locais do estabelecimento).
   * Nunca impede a marcação: só classifica.
   */
  function ponto_geofence(PDO $pdo, int $companyId, array $col, ?float $lat, ?float $lng, ?int $precisao, ?array $coletor = null): array {
    $out = ['localizacao' => 'INDISPONIVEL', 'geofence' => 'LOCALIZACAO_INDISPONIVEL', 'local_id' => null, 'distancia_m' => null, 'local' => null];
    $localIds = array_filter([(int)($coletor['local_id'] ?? 0), (int)($col['local_id'] ?? 0)]);
    if ($localIds) {
      $in = implode(',', array_fill(0, count($localIds), '?'));
      $st = $pdo->prepare("SELECT * FROM ponto_locais WHERE company_id = ? AND status = 1 AND latitude IS NOT NULL AND id IN ($in)");
      $st->execute(array_merge([$companyId], array_values($localIds)));
    } else {
      $st = $pdo->prepare('SELECT * FROM ponto_locais WHERE company_id = ? AND status = 1 AND latitude IS NOT NULL AND (estabelecimento_id IS NULL OR estabelecimento_id = ?)');
      $st->execute([$companyId, (int)$col['estabelecimento_id']]);
    }
    $locais = $st->fetchAll(PDO::FETCH_ASSOC);
    if ($lat === null || $lng === null || abs($lat) > 90 || abs($lng) > 180) {
      if ($locais) $out['local_id'] = (int)$locais[0]['id'];
      return $out;
    }
    $out['localizacao'] = 'OBTIDA';
    if (!$locais) { $out['geofence'] = 'SEM_LOCAL_CADASTRADO'; return $out; }
    $best = null;
    foreach ($locais as $l) {
      $d = ponto_geo_distance($lat, $lng, (float)$l['latitude'], (float)$l['longitude']);
      if ($best === null || $d - (int)$l['raio_metros'] < $best[0] - (int)$best[1]['raio_metros']) $best = [$d, $l];
    }
    [$dist, $local] = $best;
    $out['local_id'] = (int)$local['id'];
    $out['local'] = (string)$local['descricao'];
    $out['distancia_m'] = (int)round($dist);
    if ($dist <= (int)$local['raio_metros']) $out['geofence'] = 'DENTRO_DA_AREA';
    else $out['geofence'] = (int)($col['permite_ponto_externo'] ?? 0) ? 'EXTERNO_AUTORIZADO' : 'FORA_DA_AREA';
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* Registro da marcação (ARP)                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_marcar')) {
  /**
   * Grava a marcação. $ctx: identificacao (SESSAO|PIN|RFID), coletor (linha|null), coletor_codigo,
   * usuario_id, intencao, request_uuid, latitude, longitude, precisao.
   * Repetir o mesmo request_uuid devolve a marcação já gravada (clique duplo / reenvio).
   */
  function ponto_marcar(PDO $pdo, int $companyId, array $col, array $ctx): array {
    $uuid = strtolower(trim((string)($ctx['request_uuid'] ?? '')));
    if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', $uuid)) throw new RuntimeException('Requisição inválida. Atualize a tela.');
    $find = $pdo->prepare('SELECT * FROM ponto_arp_marcacoes WHERE company_id = ? AND request_uuid = ? LIMIT 1');
    $find->execute([$companyId, $uuid]);
    if ($ja = $find->fetch(PDO::FETCH_ASSOC)) {
      if ((int)$ja['colaborador_id'] !== (int)$col['id']) throw new RuntimeException('Requisição inválida. Atualize a tela.');
      return $ja + ['repetida' => true];
    }
    ponto_colaborador_apto($col);
    $estab = ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']);
    if (!$estab || !(int)$estab['status']) throw new RuntimeException('Estabelecimento do colaborador não encontrado ou inativo.');
    $coletor = $ctx['coletor'] ?? null;
    $codigo = (string)($ctx['coletor_codigo'] ?? ($coletor['identificador_afd'] ?? '02'));
    if (!isset(PONTO_COLETOR_CODIGOS[$codigo])) $codigo = '02';
    $intencao = isset(PONTO_INTENCOES[(string)($ctx['intencao'] ?? '')]) ? (string)$ctx['intencao'] : null;
    $lat = isset($ctx['latitude']) && $ctx['latitude'] !== '' && $ctx['latitude'] !== null ? (float)$ctx['latitude'] : null;
    $lng = isset($ctx['longitude']) && $ctx['longitude'] !== '' && $ctx['longitude'] !== null ? (float)$ctx['longitude'] : null;
    $prec = isset($ctx['precisao']) && $ctx['precisao'] !== '' && $ctx['precisao'] !== null ? max(0, min(100000, (int)round((float)$ctx['precisao']))) : null;
    $geo = ponto_geofence($pdo, $companyId, $col, $lat, $lng, $prec, $coletor);
    $tz = ponto_tz($estab, $pdo, $companyId);
    $ip = ponto_client_ip();
    $ua = mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

    $pdo->beginTransaction();
    try {
      $pdo->prepare('INSERT IGNORE INTO ponto_arp_nsr (company_id, estabelecimento_id, ultimo_nsr) VALUES (?, ?, 0)')->execute([$companyId, (int)$estab['id']]);
      $st = $pdo->prepare('SELECT ultimo_nsr, ultimo_hash FROM ponto_arp_nsr WHERE company_id = ? AND estabelecimento_id = ? FOR UPDATE');
      $st->execute([$companyId, (int)$estab['id']]);
      $seq = $st->fetch(PDO::FETCH_ASSOC);
      // V6: empregador e empregado registrados no REP (AFD tipos 2 e 5) antes da 1ª marcação.
      if (function_exists('ponto_arp_prepara_marcacao') && ponto_v6_ready($pdo)) {
        ponto_arp_prepara_marcacao($pdo, $companyId, $estab, $col);
        $st->execute([$companyId, (int)$estab['id']]);
        $seq = $st->fetch(PDO::FETCH_ASSOC);
      }
      $nsr = (int)$seq['ultimo_nsr'] + 1;
      $prevHash = $seq['ultimo_hash'] ? (string)$seq['ultimo_hash'] : null;
      // Hora oficial: lida depois do bloqueio, para a ordem do NSR acompanhar a do horário.
      $now = new DateTimeImmutable('now', $tz);
      $dataHora = $now->format('Y-m-d H:i:s');
      $fuso = $now->format('O');
      $dh = ponto_afd_dh($dataHora, $fuso);
      $hash = ponto_afd_hash($nsr, $dh, (string)$col['cpf'], $dh, $codigo, 0, $prevHash);
      $pdo->prepare('INSERT INTO ponto_arp_marcacoes (company_id, estabelecimento_id, nsr, colaborador_id, cpf, `data`, data_hora, fuso, data_hora_gravacao, fuso_gravacao,
          coletor_codigo, offline, coletor_id, usuario_id, identificacao, intencao, request_uuid, latitude, longitude, precisao_m, localizacao, local_id, distancia_m, geofence,
          ip, user_agent, hash_anterior, hash, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,0,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, (int)$estab['id'], $nsr, (int)$col['id'], ponto_digits((string)$col['cpf']), $now->format('Y-m-d'), $dataHora, $fuso, $dataHora, $fuso,
          $codigo, $coletor ? (int)$coletor['id'] : null, (int)($ctx['usuario_id'] ?? 0) ?: null, (string)($ctx['identificacao'] ?? 'SESSAO'), $intencao, $uuid,
          $lat, $lng, $prec, $geo['localizacao'], $geo['local_id'], $geo['distancia_m'], $geo['geofence'], $ip ?: null, $ua ?: null, $prevHash, $hash, date('Y-m-d H:i:s')]);
      $id = (int)$pdo->lastInsertId();
      $pdo->prepare('UPDATE ponto_arp_nsr SET ultimo_nsr = ?, ultimo_hash = ?, updated_at = ? WHERE company_id = ? AND estabelecimento_id = ?')
        ->execute([$nsr, $hash, $dataHora, $companyId, (int)$estab['id']]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      // Corrida com o mesmo request_uuid: devolve o que já foi gravado.
      $find->execute([$companyId, $uuid]);
      if ($ja = $find->fetch(PDO::FETCH_ASSOC)) return $ja + ['repetida' => true];
      throw $e;
    }
    $find->execute([$companyId, $uuid]);
    $row = $find->fetch(PDO::FETCH_ASSOC);
    $row['local_nome'] = $geo['local'];
    return $row;
  }
}

if (!function_exists('ponto_marcacao_possivel_duplicidade')) {
  /** Duas marcações do mesmo colaborador em menos de 1 minuto: só aviso, nenhuma é descartada. */
  function ponto_marcacao_possivel_duplicidade(PDO $pdo, int $companyId, array $m): bool {
    $st = $pdo->prepare('SELECT COUNT(*) FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND id <> ? AND data_hora BETWEEN ? AND ?');
    $t = strtotime((string)$m['data_hora']);
    $st->execute([$companyId, (int)$m['colaborador_id'], (int)$m['id'], date('Y-m-d H:i:s', $t - 60), date('Y-m-d H:i:s', $t + 60)]);
    return (int)$st->fetchColumn() > 0;
  }
}

if (!function_exists('ponto_arp_verificar')) {
  /**
   * Confere a integridade das marcações de um estabelecimento: NSR em sequência e cada hash
   * recalculado e encadeado com o anterior. Qualquer alteração direta no banco aparece aqui.
   */
  function ponto_arp_verificar(PDO $pdo, int $companyId, int $estabId): array {
    $st = $pdo->prepare('SELECT nsr, cpf, data_hora, fuso, data_hora_gravacao, fuso_gravacao, coletor_codigo, offline, hash_anterior, hash FROM ponto_arp_marcacoes WHERE company_id = ? AND estabelecimento_id = ? ORDER BY nsr');
    $st->execute([$companyId, $estabId]);
    $regs = [];
    while ($r = $st->fetch(PDO::FETCH_ASSOC)) {
      if (isset($regs[(int)$r['nsr']])) return ['ok' => false, 'total' => count($regs), 'erro' => 'NSR ' . (int)$r['nsr'] . ' repetido.', 'nsr' => (int)$r['nsr']];
      $regs[(int)$r['nsr']] = $r;
    }
    // V6: registros de empresa/empregado (tipos 2 e 5) usam a mesma numeração.
    if (function_exists('ponto_v6_ready') && ponto_v6_ready($pdo)) {
      $se = $pdo->prepare('SELECT nsr FROM ponto_arp_eventos WHERE company_id = ? AND estabelecimento_id = ?');
      $se->execute([$companyId, $estabId]);
      foreach ($se->fetchAll(PDO::FETCH_COLUMN) as $nsrEv) {
        if (isset($regs[(int)$nsrEv])) return ['ok' => false, 'total' => count($regs), 'erro' => 'NSR ' . (int)$nsrEv . ' repetido.', 'nsr' => (int)$nsrEv];
        $regs[(int)$nsrEv] = null;
      }
    }
    ksort($regs);
    $prev = null; $esperado = 1; $n = 0;
    foreach ($regs as $nsr => $r) {
      $n++;
      if ($nsr !== $esperado) return ['ok' => false, 'total' => $n, 'erro' => 'Falta o NSR ' . $esperado . ' (encontrado ' . $nsr . ').', 'nsr' => $nsr];
      $esperado++;
      if ($r === null) continue;
      if ((string)($r['hash_anterior'] ?? '') !== (string)($prev ?? '')) return ['ok' => false, 'total' => $n, 'erro' => 'NSR ' . $nsr . ': encadeamento com o registro anterior não confere.', 'nsr' => $nsr];
      $calc = ponto_afd_hash($nsr, ponto_afd_dh((string)$r['data_hora'], (string)$r['fuso']), (string)$r['cpf'], ponto_afd_dh((string)$r['data_hora_gravacao'], (string)$r['fuso_gravacao']), (string)$r['coletor_codigo'], (int)$r['offline'], $prev);
      if (!hash_equals($calc, (string)$r['hash'])) return ['ok' => false, 'total' => $n, 'erro' => 'NSR ' . $nsr . ': os dados não conferem com o código hash (registro alterado).', 'nsr' => $nsr];
      $prev = (string)$r['hash'];
    }
    $st = $pdo->prepare('SELECT ultimo_nsr, ultimo_hash FROM ponto_arp_nsr WHERE company_id = ? AND estabelecimento_id = ?');
    $st->execute([$companyId, $estabId]);
    $seq = $st->fetch(PDO::FETCH_ASSOC);
    if ($seq && ((int)$seq['ultimo_nsr'] !== $esperado - 1 || (string)($seq['ultimo_hash'] ?? '') !== (string)($prev ?? ''))) {
      return ['ok' => false, 'total' => $n, 'erro' => 'O último NSR registrado (' . (int)$seq['ultimo_nsr'] . ') não confere com os registros gravados (' . ($esperado - 1) . ').', 'nsr' => null];
    }
    return ['ok' => true, 'total' => $n, 'erro' => null, 'nsr' => null];
  }
}

/* ------------------------------------------------------------------------- */
/* Comprovante                                                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_comprovante_token')) {
  function ponto_comprovante_token(PDO $pdo, int $companyId, array $m): string {
    return (int)$m['id'] . '-' . substr(ponto_sign($pdo, $companyId, 'comprovante|' . (int)$m['id'] . '|' . (string)$m['hash']), 0, 32);
  }
}

if (!function_exists('ponto_comprovante_url')) {
  /** Link do comprovante (abre sem login, só com o código do link). */
  function ponto_comprovante_url(PDO $pdo, int $companyId, array $m): string {
    return '/ponto_comprovante.php?t=' . rawurlencode(ponto_comprovante_token($pdo, $companyId, $m));
  }
}

if (!function_exists('ponto_comprovante_dados')) {
  /** Dados do Comprovante de Registro de Ponto do Trabalhador (Portaria 671, art. 79). */
  function ponto_comprovante_dados(PDO $pdo, int $companyId, string $token): ?array {
    if (!preg_match('/^(\d+)-([a-f0-9]{32})$/', $token, $mm)) return null;
    $st = $pdo->prepare('SELECT * FROM ponto_arp_marcacoes WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, (int)$mm[1]]);
    $m = $st->fetch(PDO::FETCH_ASSOC);
    if (!$m || !hash_equals(ponto_comprovante_token($pdo, $companyId, $m), $token)) return null;
    $col = ponto_colaborador_get($pdo, $companyId, (int)$m['colaborador_id']) ?? [];
    $estab = ponto_estabelecimento_get($pdo, $companyId, (int)$m['estabelecimento_id']) ?? [];
    $local = null;
    if (!empty($m['local_id'])) {
      $s = $pdo->prepare('SELECT descricao, endereco FROM ponto_locais WHERE company_id = ? AND id = ?');
      $s->execute([$companyId, (int)$m['local_id']]);
      $local = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $endEstab = trim(implode(', ', array_filter([trim(($estab['endereco'] ?? '') . ' ' . ($estab['numero'] ?? '')), $estab['bairro'] ?? '', trim(($estab['cidade'] ?? '') . (!empty($estab['uf']) ? '/' . $estab['uf'] : ''))])));
    return ['m' => $m, 'colaborador' => $col, 'estabelecimento' => $estab, 'local' => $local, 'endereco_estabelecimento' => $endEstab, 'config' => ponto_config($pdo, $companyId)];
  }
}

/* ------------------------------------------------------------------------- */
/* Coletores                                                                  */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_coletor_list')) {
  function ponto_coletor_list(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare('SELECT c.*, COALESCE(NULLIF(e.nome_fantasia, \'\'), e.razao_social) AS estabelecimento, l.descricao AS local
      FROM ponto_coletores c LEFT JOIN ponto_estabelecimentos e ON e.company_id = c.company_id AND e.id = c.estabelecimento_id
      LEFT JOIN ponto_locais l ON l.company_id = c.company_id AND l.id = c.local_id WHERE c.company_id = ? ORDER BY c.ativo DESC, c.descricao');
    $st->execute([$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_coletor_save')) {
  function ponto_coletor_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $desc = trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? '');
    if ($desc === '' || mb_strlen($desc) > 80) throw new RuntimeException('Informe o nome do aparelho (até 80 caracteres). Ex.: Tablet da recepção.');
    $tipos = ['TABLET', 'TOTEM', 'COMPUTADOR', 'SMARTPHONE'];
    // V9: equipamentos (Control iD / outras marcas pela API), quando a migração já foi aplicada.
    if (function_exists('ponto_v9_ready') && ponto_v9_ready($pdo)) $tipos = array_merge($tipos, array_keys(PONTO_EQUIP_TIPOS));
    $tipo = in_array((string)($in['tipo'] ?? ''), $tipos, true) ? (string)$in['tipo'] : 'TABLET';
    $equip = !in_array($tipo, ['TABLET', 'TOTEM', 'COMPUTADOR', 'SMARTPHONE'], true);
    $cod = isset(PONTO_COLETOR_CODIGOS[(string)($in['identificador_afd'] ?? '')]) ? (string)$in['identificador_afd'] : '02';
    $data = ['descricao' => $desc, 'tipo' => $tipo, 'identificador_afd' => $cod,
      'estabelecimento_id' => ponto_fk_check($pdo, $companyId, 'estabelecimentos', (int)($in['estabelecimento_id'] ?? 0), 'Estabelecimento'),
      'local_id' => ponto_fk_check($pdo, $companyId, 'locais', (int)($in['local_id'] ?? 0), 'Local'),
      'permitir_pin' => empty($in['permitir_pin']) ? 0 : 1, 'permitir_rfid' => empty($in['permitir_rfid']) ? 0 : 1];
    if (!$data['permitir_pin'] && !$data['permitir_rfid'] && !$equip) throw new RuntimeException('Habilite o PIN, o cartão ou os dois.');
    if ($id > 0) {
      $st = $pdo->prepare('SELECT * FROM ponto_coletores WHERE company_id = ? AND id = ?');
      $st->execute([$companyId, $id]);
      $old = $st->fetch(PDO::FETCH_ASSOC);
      if (!$old) throw new RuntimeException('Aparelho não encontrado.');
      $pdo->prepare('UPDATE ponto_coletores SET descricao = ?, tipo = ?, identificador_afd = ?, estabelecimento_id = ?, local_id = ?, permitir_pin = ?, permitir_rfid = ? WHERE company_id = ? AND id = ?')
        ->execute(array_merge(array_values($data), [$companyId, $id]));
      ponto_audit($pdo, $companyId, 'alterar', 'coletores', $id, array_intersect_key($old, $data), $data);
      return $id;
    }
    $pdo->prepare('INSERT INTO ponto_coletores (company_id, uuid, descricao, tipo, identificador_afd, estabelecimento_id, local_id, permitir_pin, permitir_rfid, created_by, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, ponto_uuid(), $desc, $tipo, $cod, $data['estabelecimento_id'], $data['local_id'], $data['permitir_pin'], $data['permitir_rfid'],
        function_exists('auth_user_id') ? (auth_user_id() ?: null) : null, date('Y-m-d H:i:s')]);
    $newId = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'criar', 'coletores', $newId, null, $data);
    return $newId;
  }
}

if (!function_exists('ponto_coletor_pareamento')) {
  /** Gera um link de ativação de uso único (30 minutos) para o aparelho. Devolve o código. */
  function ponto_coletor_pareamento(PDO $pdo, int $companyId, int $id): string {
    $code = bin2hex(random_bytes(16));
    $st = $pdo->prepare('UPDATE ponto_coletores SET pareamento_hash = ?, pareamento_expira = ? WHERE company_id = ? AND id = ? AND ativo = 1');
    $st->execute([hash('sha256', $code), date('Y-m-d H:i:s', time() + 1800), $companyId, $id]);
    if (!$st->rowCount()) throw new RuntimeException('Aparelho não encontrado ou desativado.');
    ponto_audit($pdo, $companyId, 'gerar_ativacao', 'coletores', $id, null, ['validade' => '30 minutos']);
    return $code;
  }
}

if (!function_exists('ponto_coletor_ativar')) {
  /** O aparelho abre o link de ativação: troca o código de uso único por um código permanente. */
  function ponto_coletor_ativar(PDO $pdo, int $companyId, string $code): array {
    if (!preg_match('/^[a-f0-9]{32}$/', $code)) throw new RuntimeException('Link de ativação inválido.');
    $st = $pdo->prepare('SELECT * FROM ponto_coletores WHERE company_id = ? AND pareamento_hash = ? AND ativo = 1 LIMIT 1');
    $st->execute([$companyId, hash('sha256', $code)]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || strtotime((string)$c['pareamento_expira']) < time()) throw new RuntimeException('Link de ativação vencido ou já usado. Gere outro em Ponto Eletrônico > Coletores.');
    $token = bin2hex(random_bytes(32));
    $pdo->prepare('UPDATE ponto_coletores SET token_hash = ?, pareamento_hash = NULL, pareamento_expira = NULL, ultimo_acesso = ?, ultimo_ip = ? WHERE company_id = ? AND id = ?')
      ->execute([hash('sha256', $token), date('Y-m-d H:i:s'), ponto_client_ip() ?: null, $companyId, (int)$c['id']]);
    ponto_audit($pdo, $companyId, 'ativar_aparelho', 'coletores', (int)$c['id'], null, ['ip' => ponto_client_ip(), 'navegador' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 120)]);
    return [$c, $token];
  }
}

if (!function_exists('ponto_coletor_atual')) {
  /** Aparelho deste navegador (cookie), se estiver ativo. */
  function ponto_coletor_atual(PDO $pdo, int $companyId): ?array {
    $token = (string)($_COOKIE[PONTO_COLETOR_COOKIE] ?? '');
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM ponto_coletores WHERE company_id = ? AND token_hash = ? AND ativo = 1 LIMIT 1');
    $st->execute([$companyId, hash('sha256', $token)]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($c && (empty($c['ultimo_acesso']) || strtotime((string)$c['ultimo_acesso']) < time() - 300)) {
      $pdo->prepare('UPDATE ponto_coletores SET ultimo_acesso = ?, ultimo_ip = ? WHERE company_id = ? AND id = ?')->execute([date('Y-m-d H:i:s'), ponto_client_ip() ?: null, $companyId, (int)$c['id']]);
    }
    return $c;
  }
}

if (!function_exists('ponto_coletor_status')) {
  function ponto_coletor_status(PDO $pdo, int $companyId, int $id, bool $ativo): void {
    // Desativar também desconecta o aparelho: para voltar a usar, é preciso gerar outro link de ativação.
    $sql = $ativo ? 'UPDATE ponto_coletores SET ativo = 1 WHERE company_id = ? AND id = ?'
      : 'UPDATE ponto_coletores SET ativo = 0, token_hash = NULL, pareamento_hash = NULL, pareamento_expira = NULL WHERE company_id = ? AND id = ?';
    $chk = $pdo->prepare('SELECT 1 FROM ponto_coletores WHERE company_id = ? AND id = ?');
    $chk->execute([$companyId, $id]);
    if (!$chk->fetchColumn()) throw new RuntimeException('Aparelho não encontrado.');
    $pdo->prepare($sql)->execute([$companyId, $id]);
    ponto_audit($pdo, $companyId, $ativo ? 'reativar' : 'desativar_aparelho', 'coletores', $id, null, null);
  }
}

/* ------------------------------------------------------------------------- */
/* Relógio: conferência com a Hora Legal Brasileira (NTP.br)                  */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_sntp_offset')) {
  /** Diferença (ms) entre o relógio do servidor e um servidor NTP. Positivo = servidor atrasado. */
  function ponto_sntp_offset(string $host, float $timeout = 1.5): ?int {
    $sock = @stream_socket_client('udp://' . $host . ':123', $errno, $errstr, $timeout);
    if (!$sock) return null;
    stream_set_timeout($sock, (int)ceil($timeout));
    $t1 = microtime(true);
    @fwrite($sock, "\x1b" . str_repeat("\0", 47));
    $resp = @fread($sock, 48);
    $t4 = microtime(true);
    fclose($sock);
    if (!is_string($resp) || strlen($resp) < 48) return null;
    $ts = static function (string $bytes): float { $u = unpack('N2', $bytes); return ($u[1] - 2208988800) + $u[2] / 4294967296; };
    $t2 = $ts(substr($resp, 32, 8));
    $t3 = $ts(substr($resp, 40, 8));
    if ($t3 < 1000000000) return null;
    return (int)round((($t2 - $t1) + ($t3 - $t4)) / 2 * 1000);
  }
}

if (!function_exists('ponto_clock_last')) {
  /** Última conferência do relógio (sem consultar a internet). */
  function ponto_clock_last(PDO $pdo): ?array {
    try { return $pdo->query('SELECT * FROM ponto_clock_status ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null; } catch (Throwable $e) { return null; }
  }
}

if (!function_exists('ponto_clock_check')) {
  /** Confere o relógio (no máximo a cada 10 minutos) e guarda o resultado. */
  function ponto_clock_check(PDO $pdo, bool $force = false): array {
    try {
      $last = $pdo->query('SELECT * FROM ponto_clock_status ORDER BY id DESC LIMIT 1')->fetch(PDO::FETCH_ASSOC) ?: null;
      if (!$force && $last && strtotime((string)$last['checked_at']) > time() - 600) return $last;
      $offset = null; $src = '';
      foreach (['a.st1.ntp.br', 'b.st1.ntp.br', 'pool.ntp.br'] as $h) {
        $offset = ponto_sntp_offset($h);
        if ($offset !== null) { $src = $h; break; }
      }
      $row = ['checked_at' => date('Y-m-d H:i:s'), 'offset_ms' => $offset, 'source' => $src ?: 'ntp.br',
        'status' => $offset === null ? 'SEM_RESPOSTA' : (abs($offset) <= 1000 ? 'OK' : 'ATENCAO'),
        'detalhe' => $offset === null ? 'Os servidores da Hora Legal (NTP.br) não responderam. Verifique se a VPS libera a porta UDP 123.' : null];
      $pdo->prepare('INSERT INTO ponto_clock_status (checked_at, offset_ms, source, status, detalhe) VALUES (?,?,?,?,?)')->execute(array_values($row));
      return $row;
    } catch (Throwable $e) {
      return ['checked_at' => date('Y-m-d H:i:s'), 'offset_ms' => null, 'source' => 'ntp.br', 'status' => 'SEM_RESPOSTA', 'detalhe' => 'Conferência indisponível.'];
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Painel: presença do dia                                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_presenca_dia')) {
  /**
   * Situação de cada colaborador ativo no dia: marcações do dia (índice company_id+data, sem
   * percorrer o ARP inteiro) + previsto pela escala/jornada. Atraso = 1ª marcação depois do
   * início previsto + tolerância de entrada.
   */
  function ponto_presenca_dia(PDO $pdo, int $companyId, string $date): array {
    $cols = ponto_colaborador_list($pdo, $companyId, ['status' => 'ATIVOS']);
    $st = $pdo->prepare('SELECT colaborador_id, data_hora, geofence, intencao FROM ponto_arp_marcacoes WHERE company_id = ? AND `data` = ? ORDER BY data_hora, id');
    $st->execute([$companyId, $date]);
    $marks = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) $marks[(int)$m['colaborador_id']][] = $m;
    $tol = [];
    $sj = $pdo->prepare('SELECT id, tolerancia_entrada FROM ponto_jornadas WHERE company_id = ?');
    $sj->execute([$companyId]);
    foreach ($sj->fetchAll(PDO::FETCH_ASSOC) as $j) $tol[(int)$j['id']] = (int)$j['tolerancia_entrada'];
    $out = [];
    foreach ($cols as $c) {
      if (!(int)$c['controla_ponto'] || $c['data_admissao'] > $date || ($c['data_demissao'] && $c['data_demissao'] < $date)) continue;
      $p = ponto_previsto_do_dia($pdo, $companyId, $c, $date);
      $ms = $marks[(int)$c['id']] ?? [];
      $n = count($ms);
      $periodos = max(1, count($p['periodos']));
      if ($c['status'] === 'AFASTADO') $sit = 'AFASTADO';
      elseif ($n === 0) $sit = $p['trabalha'] ? 'NAO_CHEGOU' : ($p['feriado'] ? 'FERIADO' : ($p['origem'] === 'sem_jornada' ? 'SEM_JORNADA' : 'FOLGA'));
      elseif ($n % 2 === 1) $sit = 'TRABALHANDO';
      else $sit = $n < 2 * $periodos && $p['trabalha'] ? 'INTERVALO' : 'SAIU';
      $atraso = 0;
      if ($p['trabalha'] && $p['periodos']) {
        $ini = strtotime($date . ' ' . $p['periodos'][0]['ini'] . ':00') + 60 * ($tol[(int)$p['jornada_id']] ?? 0);
        $ref = $n ? strtotime((string)$ms[0]['data_hora']) : ($date === date('Y-m-d') ? time() : $ini);
        if ($ref > $ini) $atraso = (int)floor(($ref - $ini + 60 * ($tol[(int)$p['jornada_id']] ?? 0)) / 60);
      }
      $fora = count(array_filter($ms, static fn($m) => $m['geofence'] === 'FORA_DA_AREA'));
      $out[] = ['c' => $c, 'p' => $p, 'marcacoes' => $ms, 'situacao' => $sit, 'atraso_min' => $atraso, 'fora_area' => $fora];
    }
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* Apoio às telas: QR Code, endereço do site, regras do ponto pelo celular    */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_qr_svg')) {
  /** QR Code em SVG (biblioteca tc-lib-barcode, já usada nos boletos). '' se indisponível. */
  function ponto_qr_svg(string $text, int $module = 4): string {
    try {
      $autoload = dirname(__DIR__) . '/vendor/autoload.php';
      if (!class_exists('\Com\Tecnick\Barcode\Barcode') && is_file($autoload)) require_once $autoload;
      if (!class_exists('\Com\Tecnick\Barcode\Barcode')) return '';
      $svg = (new \Com\Tecnick\Barcode\Barcode())->getBarcodeObj('QRCODE,M', $text, -$module, -$module, 'black', [-2, -2, -2, -2])->getSvgCode();
      $pos = strpos($svg, '<svg');
      return $pos === false ? '' : substr($svg, $pos);
    } catch (Throwable $e) {
      return '';
    }
  }
}

if (!function_exists('ponto_base_url')) {
  /** Endereço deste site (https atrás da Cloudflare), para links de QR Code. */
  function ponto_base_url(): string {
    $host = preg_replace('/[^A-Za-z0-9.\-:]/', '', (string)($_SERVER['HTTP_HOST'] ?? '')) ?? '';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https'
      || stripos((string)($_SERVER['HTTP_CF_VISITOR'] ?? ''), 'https') !== false;
    $local = preg_match('/^(localhost|127\.0\.0\.1)(:\d+)?$/', $host);
    return ($https || !$local ? 'https' : 'http') . '://' . $host;
  }
}

if (!function_exists('ponto_is_mobile')) {
  function ponto_is_mobile(): bool {
    return (bool)preg_match('/Mobi|Android|iPhone|iPad|iPod/i', (string)($_SERVER['HTTP_USER_AGENT'] ?? ''));
  }
}

if (!function_exists('ponto_pessoal_regras')) {
  /**
   * Pode bater o próprio ponto neste aparelho (tela Bater Ponto, com login)?
   * Computador: "Permitir ponto pelo navegador". Celular: "Permitir ponto pelo celular" na
   * configuração + "Permite ponto pelo celular" no cadastro do colaborador.
   * Devolve [pode, motivo, exige_localizacao].
   */
  function ponto_pessoal_regras(PDO $pdo, int $companyId, array $col): array {
    $cfg = ponto_config($pdo, $companyId);
    $exige = !empty($cfg['exigir_localizacao']);
    if (!empty($col['local_id'])) {
      $st = $pdo->prepare('SELECT exigir_localizacao FROM ponto_locais WHERE company_id = ? AND id = ? AND status = 1');
      $st->execute([$companyId, (int)$col['local_id']]);
      if ((int)$st->fetchColumn() === 1) $exige = true;
    }
    if (ponto_is_mobile()) {
      if (empty($cfg['permitir_mobile'])) return [false, 'O ponto pelo celular não está liberado para esta empresa (Ponto Eletrônico > Configurações).', $exige];
      if (empty($col['permite_ponto_mobile'])) return [false, 'O ponto pelo celular não está liberado no seu cadastro. Fale com o responsável pelo ponto.', $exige];
    } elseif (empty($cfg['permitir_web'])) {
      return [false, 'O ponto pelo navegador do computador não está liberado para esta empresa.', $exige];
    }
    return [true, '', $exige];
  }
}

if (!function_exists('ponto_colaborador_do_usuario')) {
  function ponto_colaborador_do_usuario(PDO $pdo, int $companyId, int $userId): ?array {
    if ($userId <= 0) return null;
    $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND usuario_id = ? LIMIT 1');
    $st->execute([$companyId, $userId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_coletor_form_token')) {
  /** Proteção dos envios do terminal (equivale ao CSRF das telas com login). */
  function ponto_coletor_form_token(PDO $pdo, int $companyId, array $coletor): string {
    return substr(ponto_sign($pdo, $companyId, 'coletor-form|' . (int)$coletor['id'] . '|' . (string)$coletor['token_hash']), 0, 40);
  }
}

if (!function_exists('ponto_marcacoes_list')) {
  /** Consulta de marcações (tela Marcações / Meus registros). Filtros já validados. */
  function ponto_marcacoes_list(PDO $pdo, int $companyId, array $f, int $limit = 500): array {
    $where = ['m.company_id = ?']; $p = [$companyId];
    if (!empty($f['de'])) { $where[] = 'm.`data` >= ?'; $p[] = $f['de']; }
    if (!empty($f['ate'])) { $where[] = 'm.`data` <= ?'; $p[] = $f['ate']; }
    foreach (['colaborador_id', 'estabelecimento_id'] as $k) if (!empty($f[$k])) { $where[] = 'm.' . $k . ' = ?'; $p[] = (int)$f[$k]; }
    if (!empty($f['geofence']) && in_array($f['geofence'], ['DENTRO_DA_AREA', 'FORA_DA_AREA', 'LOCALIZACAO_INDISPONIVEL', 'EXTERNO_AUTORIZADO', 'SEM_LOCAL_CADASTRADO'], true)) { $where[] = 'm.geofence = ?'; $p[] = $f['geofence']; }
    $st = $pdo->prepare('SELECT m.*, c.nome, c.matricula, l.descricao AS local_nome, k.descricao AS coletor_nome,
        COALESCE(NULLIF(e.nome_fantasia, \'\'), e.razao_social) AS estabelecimento
      FROM ponto_arp_marcacoes m
      JOIN ponto_colaboradores c ON c.company_id = m.company_id AND c.id = m.colaborador_id
      LEFT JOIN ponto_locais l ON l.company_id = m.company_id AND l.id = m.local_id
      LEFT JOIN ponto_coletores k ON k.company_id = m.company_id AND k.id = m.coletor_id
      LEFT JOIN ponto_estabelecimentos e ON e.company_id = m.company_id AND e.id = m.estabelecimento_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY m.data_hora DESC, m.id DESC LIMIT ' . max(1, min(5000, $limit)));
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_geofence_label')) {
  function ponto_geofence_label(string $g): array {
    $map = ['DENTRO_DA_AREA' => ['Dentro da área', 'g'], 'FORA_DA_AREA' => ['Fora da área', 'r'], 'EXTERNO_AUTORIZADO' => ['Externo autorizado', 'y'],
      'LOCALIZACAO_INDISPONIVEL' => ['Sem localização', 'n'], 'SEM_LOCAL_CADASTRADO' => ['Sem local cadastrado', 'n']];
    return $map[$g] ?? [$g, 'n'];
  }
}

// V6: registros cadastrais do REP, AFD, AEJ e Espelho de Ponto.
require_once __DIR__ . '/ponto_afd.php';
// V7: assinaturas digitais (certificados, CAdES/PAdES, PDF do comprovante e do atestado).
require_once __DIR__ . '/ponto_assinatura.php';
