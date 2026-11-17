<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V4: tratamento do ponto (PTRP), ocorrências/justificativas e apuração diária.
 *
 * - O ARP (ponto_arp_marcacoes) nunca é alterado: o tratamento fica em ponto_tratamentos
 *   (INCLUSAO / DESCONSIDERACAO / AJUSTE_CLASSIFICACAO), sempre com motivo e responsável.
 *   Desfazer um tratamento = cancelar (fica no histórico), nunca apagar.
 * - Ocorrências (atestado, falta justificada...) em ponto_ocorrencias; "abona" = não vira débito.
 * - A apuração (ponto_apuracoes_diarias) é derivada e recalculada a partir do ARP + tratamento.
 *
 * Regras de cálculo (CLT):
 * - Tolerância (art. 58, § 1º): variações de até X min por marcação (tolerância da jornada) e
 *   no máximo 10 min no dia não contam como atraso nem como hora extra.
 * - Saldo do dia = trabalhado - previsto: positivo = extra; negativo = falta/débito.
 * - Noturno (art. 73): minutos trabalhados entre 22:00 e 05:00 (relógio, sem a hora reduzida;
 *   a conversão para hora noturna reduzida fica para o fechamento/folha).
 * - Alertas: intervalo menor que o mínimo (art. 71) e descanso entre jornadas < 11h (art. 66).
 */

require_once __DIR__ . '/ponto_marcacao.php';

const PONTO_TRATAMENTO_TIPOS = ['INCLUSAO' => 'Inclusão de marcação', 'DESCONSIDERACAO' => 'Desconsiderar marcação', 'AJUSTE_CLASSIFICACAO' => 'Mudar o tipo da marcação'];
const PONTO_SITUACOES = [
  'NORMAL' => ['Normal', 'g'], 'FALTA' => ['Falta', 'r'], 'INCOMPLETO' => ['Marcação incompleta', 'r'], 'ABONADO' => ['Abonado', 'g'],
  'FOLGA' => ['Folga', 'n'], 'FERIADO' => ['Feriado', 'n'], 'FOLGA_TRABALHADA' => ['Trabalho na folga', 'y'], 'FERIADO_TRABALHADO' => ['Trabalho no feriado', 'y'],
  'SEM_JORNADA' => ['Sem jornada', 'r'], 'ANTES_INICIO' => ['Antes do ponto', 'n'], 'EM_ANDAMENTO' => ['Em andamento', ''], 'FUTURO' => ['—', 'n'], 'FORA_CONTRATO' => ['Fora do contrato', 'n'],
];

if (!function_exists('ponto_v4_ready')) {
  function ponto_v4_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ponto_tipos_ocorrencia','ponto_ocorrencias','ponto_tratamentos','ponto_apuracoes_diarias')");
      return $ready = ((int)$st->fetchColumn() === 4) && ponto_v2_ready($pdo);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('ponto_hm')) {
  /** Minutos em "8h48" / "-0h15"; 0 => "—" quando $dash. */
  function ponto_hm(int $min, bool $dash = true): string {
    if ($min === 0 && $dash) return '—';
    $s = $min < 0 ? '-' : '';
    $min = abs($min);
    return $s . intdiv($min, 60) . 'h' . str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT);
  }
}

/* ------------------------------------------------------------------------- */
/* Tipos de ocorrência                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_tipos_ocorrencia_padrao')) {
  /** [descrição, abona, exige documento, colaborador pode escolher] */
  function ponto_tipos_ocorrencia_padrao(): array {
    return [
      ['Esquecimento de marcação', 0, 0, 1], ['Atestado médico', 1, 1, 1], ['Declaração de comparecimento', 1, 1, 1],
      ['Falta justificada (abonada)', 1, 0, 0], ['Falta injustificada', 0, 0, 0], ['Atraso', 0, 0, 1], ['Saída antecipada', 0, 0, 1],
      ['Serviço externo', 1, 0, 1], ['Treinamento / curso', 1, 0, 1], ['Viagem a trabalho', 1, 0, 1], ['Folga compensatória', 1, 0, 0],
      ['Licença (maternidade, paternidade, casamento, luto...)', 1, 1, 1], ['Problema técnico no ponto', 0, 0, 1],
    ];
  }
}

if (!function_exists('ponto_tipos_ocorrencia_seed')) {
  /** Primeira vez: cria os tipos padrão da empresa (só dados, não mexe na estrutura). */
  function ponto_tipos_ocorrencia_seed(PDO $pdo, int $companyId): void {
    $st = $pdo->prepare('SELECT COUNT(*) FROM ponto_tipos_ocorrencia WHERE company_id = ?');
    $st->execute([$companyId]);
    if ((int)$st->fetchColumn() > 0) return;
    $ins = $pdo->prepare('INSERT INTO ponto_tipos_ocorrencia (company_id, descricao, abona, exige_documento, visivel_colaborador) VALUES (?,?,?,?,?)');
    foreach (ponto_tipos_ocorrencia_padrao() as [$d, $a, $e, $v]) $ins->execute([$companyId, $d, $a, $e, $v]);
  }
}

if (!function_exists('ponto_tipos_ocorrencia')) {
  function ponto_tipos_ocorrencia(PDO $pdo, int $companyId, bool $somenteAtivos = true, bool $somenteColaborador = false): array {
    ponto_tipos_ocorrencia_seed($pdo, $companyId);
    $st = $pdo->prepare('SELECT * FROM ponto_tipos_ocorrencia WHERE company_id = ?' . ($somenteAtivos ? ' AND status = 1' : '') . ($somenteColaborador ? ' AND visivel_colaborador = 1' : '') . ' ORDER BY descricao');
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = $r;
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* Documento da ocorrência (fora da pasta pública)                            */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_doc_save')) {
  /** PDF, JPG ou PNG até 8 MB. Imagens são regravadas (remove dados ocultos). Devolve [path, nome, mime]. */
  function ponto_doc_save(int $companyId, string $tmp, string $nome): array {
    if (!is_file($tmp) || filesize($tmp) <= 0) throw new RuntimeException('Arquivo não recebido.');
    if (filesize($tmp) > 8 * 1024 * 1024) throw new RuntimeException('O arquivo passa de 8 MB.');
    $mime = (string)(new finfo(FILEINFO_MIME_TYPE))->file($tmp);
    if (!in_array($mime, ['application/pdf', 'image/jpeg', 'image/png'], true)) throw new RuntimeException('Envie o documento em PDF, JPG ou PNG.');
    $dir = ponto_storage_root() . '/' . $companyId . '/ocorrencias';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível gravar o documento (pasta de arquivos do ponto).');
    $base = bin2hex(random_bytes(16));
    if ($mime === 'application/pdf') {
      if (strncmp((string)file_get_contents($tmp, false, null, 0, 5), '%PDF-', 5) !== 0) throw new RuntimeException('PDF inválido.');
      $rel = $companyId . '/ocorrencias/' . $base . '.pdf';
      if (!@copy($tmp, ponto_storage_root() . '/' . $rel)) throw new RuntimeException('Não foi possível gravar o documento.');
    } else {
      $img = @imagecreatefromstring((string)file_get_contents($tmp));
      if (!$img) throw new RuntimeException('Imagem inválida.');
      $w = imagesx($img); $h = imagesy($img); $max = 2000;
      if ($w > $max || $h > $max) {
        $r = min($max / $w, $max / $h);
        $dst = imagecreatetruecolor((int)round($w * $r), (int)round($h * $r));
        imagecopyresampled($dst, $img, 0, 0, 0, 0, imagesx($dst), imagesy($dst), $w, $h);
        imagedestroy($img); $img = $dst;
      }
      $rel = $companyId . '/ocorrencias/' . $base . '.jpg';
      if (!imagejpeg($img, ponto_storage_root() . '/' . $rel, 85)) throw new RuntimeException('Não foi possível gravar o documento.');
      imagedestroy($img);
      $mime = 'image/jpeg';
    }
    $nome = trim(preg_replace('/[^\pL\pN ._-]+/u', '_', basename($nome)) ?? '') ?: 'documento';
    return [$rel, mb_substr($nome, 0, 150), $mime];
  }
}

if (!function_exists('ponto_doc_file')) {
  function ponto_doc_file(array $oc): ?string {
    $rel = (string)($oc['documento_path'] ?? '');
    if (!preg_match('#^\d+/ocorrencias/[a-f0-9]{32}\.(pdf|jpg)$#', $rel)) return null;
    $p = ponto_storage_root() . '/' . $rel;
    return is_file($p) ? $p : null;
  }
}

if (!function_exists('ponto_doc_output')) {
  function ponto_doc_output(array $oc): void {
    $file = ponto_doc_file($oc);
    if (!$file) { http_response_code(404); exit('Documento não encontrado.'); }
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: ' . ((string)$oc['documento_mime'] === 'application/pdf' ? 'application/pdf' : 'image/jpeg'));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    header('Content-Disposition: inline; filename="' . str_replace('"', '', (string)($oc['documento_nome'] ?: 'documento')) . '"');
    header('Content-Length: ' . (string)filesize($file));
    readfile($file);
    exit;
  }
}

/* ------------------------------------------------------------------------- */
/* Ocorrências                                                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_ocorrencia_get')) {
  function ponto_ocorrencia_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_ocorrencias WHERE company_id = ? AND id = ?');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_ocorrencia_save')) {
  /**
   * Lança uma ocorrência. Pelo responsável: já entra APROVADA. Pelo colaborador (Meu Ponto):
   * entra PENDENTE. $file = ['tmp' => ..., 'name' => ...] ou null.
   */
  function ponto_ocorrencia_save(PDO $pdo, int $companyId, array $in, bool $peloColaborador = false, ?array $file = null): int {
    $col = ponto_colaborador_get($pdo, $companyId, (int)($in['colaborador_id'] ?? 0));
    if (!$col) throw new RuntimeException('Escolha o colaborador.');
    $tipos = ponto_tipos_ocorrencia($pdo, $companyId, true, $peloColaborador);
    $tipo = $tipos[(int)($in['tipo_ocorrencia_id'] ?? 0)] ?? null;
    if (!$tipo) throw new RuntimeException('Escolha o tipo de ocorrência.');
    $ini = ponto_date_in($in['data_inicio'] ?? '', 'Data inicial');
    if ($ini === '') throw new RuntimeException('Informe a data.');
    $fim = ponto_date_in($in['data_fim'] ?? '', 'Data final') ?: $ini;
    if ($fim < $ini) throw new RuntimeException('A data final é anterior à inicial.');
    if ((strtotime($fim) - strtotime($ini)) / 86400 > 366) throw new RuntimeException('Período maior que um ano.');
    $hi = trim((string)($in['hora_inicio'] ?? '')); $hf = trim((string)($in['hora_fim'] ?? ''));
    if ($hi !== '' || $hf !== '') {
      if ($ini !== $fim) throw new RuntimeException('Ocorrência por horário vale para um único dia.');
      $hi = ponto_time_in($hi, 'Hora inicial'); $hf = ponto_time_in($hf, 'Hora final');
      if ($hi === '' || $hf === '' || $hf <= $hi) throw new RuntimeException('Informe o horário inicial e o final (o final depois do inicial).');
    }
    $desc = trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? '');
    if (mb_strlen($desc) > 500) throw new RuntimeException('Descrição: máximo de 500 caracteres.');
    if ($peloColaborador && $desc === '') throw new RuntimeException('Explique o motivo na descrição.');
    $doc = [null, null, null];
    if ($file && !empty($file['tmp'])) $doc = ponto_doc_save($companyId, (string)$file['tmp'], (string)($file['name'] ?? 'documento'));
    if ((int)$tipo['exige_documento'] && $doc[0] === null) throw new RuntimeException('Este tipo de ocorrência exige o documento (ex.: atestado). Anexe o arquivo.');
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $status = $peloColaborador ? 'PENDENTE' : 'APROVADA';
    $pdo->prepare('INSERT INTO ponto_ocorrencias (company_id, colaborador_id, tipo_ocorrencia_id, data_inicio, data_fim, hora_inicio, hora_fim, descricao, documento_path, documento_nome, documento_mime, status, solicitado_por, pelo_colaborador, decidido_por, decidido_em, created_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, (int)$col['id'], (int)$tipo['id'], $ini, $fim, $hi ?: null, $hf ?: null, $desc ?: null, $doc[0], $doc[1], $doc[2], $status, $uid ?: null, $peloColaborador ? 1 : 0,
        $peloColaborador ? null : ($uid ?: null), $peloColaborador ? null : date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, $peloColaborador ? 'solicitar_ocorrencia' : 'lancar_ocorrencia', 'ocorrencias', $id, null,
      ['tipo' => $tipo['descricao'], 'periodo' => $ini . ($fim !== $ini ? ' a ' . $fim : '') . ($hi ? ' ' . $hi . '-' . $hf : ''), 'documento' => $doc[0] ? 'sim' : 'não', 'status' => $status], (int)$col['id']);
    if ($status === 'APROVADA') ponto_apuracao_recalcular($pdo, $companyId, (int)$col['id'], $ini, $fim);
    return $id;
  }
}

if (!function_exists('ponto_ocorrencia_decidir')) {
  /** Aprovar / rejeitar (PENDENTE) ou cancelar (PENDENTE ou APROVADA). Motivo obrigatório ao rejeitar/cancelar. */
  function ponto_ocorrencia_decidir(PDO $pdo, int $companyId, int $id, string $acao, string $motivo = '', ?int $somenteColaboradorId = null): void {
    $oc = ponto_ocorrencia_get($pdo, $companyId, $id);
    if (!$oc || ($somenteColaboradorId !== null && (int)$oc['colaborador_id'] !== $somenteColaboradorId)) throw new RuntimeException('Ocorrência não encontrada.');
    $motivo = trim($motivo);
    $novo = ['aprovar' => 'APROVADA', 'rejeitar' => 'REJEITADA', 'cancelar' => 'CANCELADA'][$acao] ?? '';
    if ($novo === '') throw new RuntimeException('Ação inválida.');
    if ($acao !== 'cancelar' && $oc['status'] !== 'PENDENTE') throw new RuntimeException('Esta ocorrência já foi decidida.');
    if ($acao === 'cancelar' && !in_array($oc['status'], ['PENDENTE', 'APROVADA'], true)) throw new RuntimeException('Esta ocorrência não pode ser cancelada.');
    if ($acao !== 'aprovar' && mb_strlen($motivo) < 3) throw new RuntimeException('Informe o motivo.');
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $pdo->prepare('UPDATE ponto_ocorrencias SET status = ?, decidido_por = ?, decidido_em = ?, motivo_decisao = ? WHERE company_id = ? AND id = ?')
      ->execute([$novo, $uid ?: null, date('Y-m-d H:i:s'), mb_substr($motivo, 0, 300) ?: null, $companyId, $id]);
    ponto_audit($pdo, $companyId, $acao . '_ocorrencia', 'ocorrencias', $id, ['status' => $oc['status']], ['status' => $novo, 'motivo' => $motivo], (int)$oc['colaborador_id']);
    ponto_apuracao_recalcular($pdo, $companyId, (int)$oc['colaborador_id'], (string)$oc['data_inicio'], (string)$oc['data_fim']);
  }
}

if (!function_exists('ponto_ocorrencias_list')) {
  function ponto_ocorrencias_list(PDO $pdo, int $companyId, array $f = [], int $limit = 500): array {
    $w = ['o.company_id = ?']; $p = [$companyId];
    if (!empty($f['status'])) { $w[] = 'o.status = ?'; $p[] = (string)$f['status']; }
    if (!empty($f['colaborador_id'])) { $w[] = 'o.colaborador_id = ?'; $p[] = (int)$f['colaborador_id']; }
    if (!empty($f['de'])) { $w[] = 'o.data_fim >= ?'; $p[] = $f['de']; }
    if (!empty($f['ate'])) { $w[] = 'o.data_inicio <= ?'; $p[] = $f['ate']; }
    $st = $pdo->prepare('SELECT o.*, t.descricao AS tipo, t.abona, c.nome, c.matricula FROM ponto_ocorrencias o
      JOIN ponto_tipos_ocorrencia t ON t.company_id = o.company_id AND t.id = o.tipo_ocorrencia_id
      JOIN ponto_colaboradores c ON c.company_id = o.company_id AND c.id = o.colaborador_id
      WHERE ' . implode(' AND ', $w) . ' ORDER BY (o.status = \'PENDENTE\') DESC, o.data_inicio DESC, o.id DESC LIMIT ' . max(1, min(2000, $limit)));
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* ------------------------------------------------------------------------- */
/* Tratamento (inclusão / desconsideração / reclassificação)                  */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_tratamento_get')) {
  function ponto_tratamento_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_tratamentos WHERE company_id = ? AND id = ?');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_tratamento_save')) {
  /**
   * $in: colaborador_id, data (dia da apuração), tipo, motivo, tipo_ocorrencia_id?, e
   * INCLUSAO: hora (HH:MM), proximo_dia (0/1), intencao? | DESCONSIDERACAO/AJUSTE: marcacao_id, intencao (ajuste).
   * Pelo colaborador: só INCLUSAO, entra PENDENTE.
   */
  function ponto_tratamento_save(PDO $pdo, int $companyId, array $in, bool $peloColaborador = false): int {
    $col = ponto_colaborador_get($pdo, $companyId, (int)($in['colaborador_id'] ?? 0));
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $tipo = (string)($in['tipo'] ?? '');
    if (!isset(PONTO_TRATAMENTO_TIPOS[$tipo]) || ($peloColaborador && $tipo !== 'INCLUSAO')) throw new RuntimeException('Tipo de tratamento inválido.');
    $data = ponto_date_in($in['data'] ?? '', 'Data');
    if ($data === '') throw new RuntimeException('Informe o dia.');
    $motivo = trim(preg_replace('/\s+/u', ' ', (string)($in['motivo'] ?? '')) ?? '');
    if (mb_strlen($motivo) < 5) throw new RuntimeException('Explique o motivo (mínimo de 5 letras). Ele fica registrado no histórico.');
    $tipoOc = (int)($in['tipo_ocorrencia_id'] ?? 0);
    if ($tipoOc && !isset(ponto_tipos_ocorrencia($pdo, $companyId)[$tipoOc])) $tipoOc = 0;
    $intencao = isset(PONTO_INTENCOES[(string)($in['intencao'] ?? '')]) ? (string)$in['intencao'] : null;
    $estab = ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']);
    $tz = ponto_tz($estab, $pdo, $companyId);
    $row = ['marcacao_id' => null, 'data_hora' => null, 'fuso' => null];
    if ($tipo === 'INCLUSAO') {
      $hora = trim((string)($in['hora'] ?? '')) === '' ? '' : substr(ponto_time_in($in['hora'], 'Horário'), 0, 5);
      if ($hora === '') throw new RuntimeException('Informe o horário da marcação.');
      $dia = !empty($in['proximo_dia']) ? date('Y-m-d', strtotime($data . ' +1 day')) : $data;
      $dt = new DateTimeImmutable($dia . ' ' . $hora . ':00', $tz);
      if ($dt > new DateTimeImmutable('now', $tz)) throw new RuntimeException('Não é possível incluir marcação no futuro.');
      if ($dia < (string)$col['data_admissao'] || (!empty($col['data_demissao']) && $dia > (string)$col['data_demissao'])) throw new RuntimeException('Data fora do contrato do colaborador.');
      $row['data_hora'] = $dt->format('Y-m-d H:i:s');
      $row['fuso'] = $dt->format('O');
      // Mesmo minuto já marcado (original ou incluído)?
      $dup = $pdo->prepare("SELECT (SELECT COUNT(*) FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND data_hora BETWEEN ? AND ?)
        + (SELECT COUNT(*) FROM ponto_tratamentos WHERE company_id = ? AND colaborador_id = ? AND tipo = 'INCLUSAO' AND status IN ('ATIVO','PENDENTE') AND data_hora BETWEEN ? AND ?)");
      $a = $dt->format('Y-m-d H:i:00'); $b = $dt->format('Y-m-d H:i:59');
      $dup->execute([$companyId, (int)$col['id'], $a, $b, $companyId, (int)$col['id'], $a, $b]);
      if ((int)$dup->fetchColumn() > 0) throw new RuntimeException('Já existe marcação neste horário.');
    } else {
      $st = $pdo->prepare('SELECT * FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND id = ?');
      $st->execute([$companyId, (int)$col['id'], (int)($in['marcacao_id'] ?? 0)]);
      $m = $st->fetch(PDO::FETCH_ASSOC);
      if (!$m) throw new RuntimeException('Marcação não encontrada.');
      $row['marcacao_id'] = (int)$m['id'];
      if ($tipo === 'AJUSTE_CLASSIFICACAO' && !$intencao) throw new RuntimeException('Escolha o novo tipo da marcação.');
      if ($tipo === 'DESCONSIDERACAO') {
        $intencao = null;
        $ja = $pdo->prepare("SELECT COUNT(*) FROM ponto_tratamentos WHERE company_id = ? AND marcacao_id = ? AND tipo = 'DESCONSIDERACAO' AND status = 'ATIVO'");
        $ja->execute([$companyId, (int)$m['id']]);
        if ((int)$ja->fetchColumn() > 0) throw new RuntimeException('Esta marcação já está desconsiderada.');
      }
    }
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $status = $peloColaborador ? 'PENDENTE' : 'ATIVO';
    $pdo->prepare('INSERT INTO ponto_tratamentos (company_id, colaborador_id, `data`, tipo, marcacao_id, data_hora, fuso, intencao, tipo_ocorrencia_id, motivo, status, pelo_colaborador, created_by, created_at, decidido_por, decidido_em)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([$companyId, (int)$col['id'], $data, $tipo, $row['marcacao_id'], $row['data_hora'], $row['fuso'], $intencao, $tipoOc ?: null, mb_substr($motivo, 0, 300), $status,
        $peloColaborador ? 1 : 0, $uid ?: null, date('Y-m-d H:i:s'), $peloColaborador ? null : ($uid ?: null), $peloColaborador ? null : date('Y-m-d H:i:s')]);
    $id = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, $peloColaborador ? 'solicitar_inclusao' : strtolower($tipo), 'tratamentos', $id, null,
      array_filter(['dia' => $data, 'tipo' => $tipo, 'marcacao_id' => $row['marcacao_id'], 'horario' => $row['data_hora'], 'novo_tipo' => $intencao, 'motivo' => $motivo, 'status' => $status]), (int)$col['id']);
    if ($status === 'ATIVO') ponto_apuracao_recalcular($pdo, $companyId, (int)$col['id'], $data, $data);
    return $id;
  }
}

if (!function_exists('ponto_tratamento_decidir')) {
  /** aprovar/rejeitar (PENDENTE) ou cancelar (ATIVO ou PENDENTE). Nada é apagado. */
  function ponto_tratamento_decidir(PDO $pdo, int $companyId, int $id, string $acao, string $motivo = '', ?int $somenteColaboradorId = null): void {
    $t = ponto_tratamento_get($pdo, $companyId, $id);
    if (!$t || ($somenteColaboradorId !== null && (int)$t['colaborador_id'] !== $somenteColaboradorId)) throw new RuntimeException('Tratamento não encontrado.');
    $novo = ['aprovar' => 'ATIVO', 'rejeitar' => 'REJEITADO', 'cancelar' => 'CANCELADO'][$acao] ?? '';
    if ($novo === '') throw new RuntimeException('Ação inválida.');
    if ($acao !== 'cancelar' && $t['status'] !== 'PENDENTE') throw new RuntimeException('Esta solicitação já foi decidida.');
    if ($acao === 'cancelar' && !in_array($t['status'], ['ATIVO', 'PENDENTE'], true)) throw new RuntimeException('Este tratamento não pode ser cancelado.');
    $motivo = trim($motivo);
    if ($acao !== 'aprovar' && mb_strlen($motivo) < 3) throw new RuntimeException('Informe o motivo.');
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $pdo->prepare('UPDATE ponto_tratamentos SET status = ?, decidido_por = ?, decidido_em = ?, motivo_decisao = ? WHERE company_id = ? AND id = ?')
      ->execute([$novo, $uid ?: null, date('Y-m-d H:i:s'), mb_substr($motivo, 0, 300) ?: null, $companyId, $id]);
    ponto_audit($pdo, $companyId, $acao . '_tratamento', 'tratamentos', $id, ['status' => $t['status']], ['status' => $novo, 'motivo' => $motivo], (int)$t['colaborador_id']);
    ponto_apuracao_recalcular($pdo, $companyId, (int)$t['colaborador_id'], (string)$t['data'], (string)$t['data']);
  }
}

if (!function_exists('ponto_solicitacoes_pendentes')) {
  /** Pedidos do Meu Ponto aguardando o responsável: inclusões e ocorrências. */
  function ponto_solicitacoes_pendentes(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare("SELECT t.*, c.nome, c.matricula FROM ponto_tratamentos t JOIN ponto_colaboradores c ON c.company_id = t.company_id AND c.id = t.colaborador_id
      WHERE t.company_id = ? AND t.status = 'PENDENTE' ORDER BY t.data, t.id");
    $st->execute([$companyId]);
    return ['inclusoes' => $st->fetchAll(PDO::FETCH_ASSOC), 'ocorrencias' => ponto_ocorrencias_list($pdo, $companyId, ['status' => 'PENDENTE'])];
  }
}

/* ------------------------------------------------------------------------- */
/* Marcações do dia (com tratamento)                                          */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_previsto_cache')) {
  function ponto_previsto_cache(PDO $pdo, int $companyId, array $col, string $date, bool $reset = false): array {
    static $c = [];
    if ($reset) { $c = []; return []; }
    $k = $companyId . '|' . (int)$col['id'] . '|' . $date;
    return $c[$k] ?? ($c[$k] = ponto_previsto_do_dia($pdo, $companyId, $col, $date));
  }
}

if (!function_exists('ponto_periodo_abs')) {
  /** Períodos previstos em timestamps (período que passa da meia-noite termina no dia seguinte). */
  function ponto_periodo_abs(string $date, array $periodos): array {
    $out = [];
    $base = $date;
    $prevEnd = null;
    foreach ($periodos as $p) {
      $ini = strtotime($base . ' ' . $p['ini'] . ':00');
      if ($prevEnd !== null && $ini < $prevEnd) { $base = date('Y-m-d', strtotime($base . ' +1 day')); $ini = strtotime($base . ' ' . $p['ini'] . ':00'); }
      $fim = strtotime($base . ' ' . $p['fim'] . ':00');
      if ($fim <= $ini) { $base = date('Y-m-d', strtotime($base . ' +1 day')); $fim = strtotime($base . ' ' . $p['fim'] . ':00'); }
      $out[] = [$ini, $fim];
      $prevEnd = $fim;
    }
    return $out;
  }
}

if (!function_exists('ponto_janela_dia')) {
  /**
   * Faixa de horário cujas marcações pertencem ao dia de trabalho $date. Dia comum: 00:00–23:59.
   * Jornada que passa da meia-noite (ex.: 19:00–07:00): o dia vai até o fim previsto + 4h no dia
   * seguinte, e o dia seguinte começa depois disso. Nunca invade 2h antes do início previsto
   * do próximo dia de trabalho.
   */
  function ponto_janela_dia(PDO $pdo, int $companyId, array $col, string $date): array {
    $prev = date('Y-m-d', strtotime($date . ' -1 day'));
    $next = date('Y-m-d', strtotime($date . ' +1 day'));
    $pD = ponto_previsto_cache($pdo, $companyId, $col, $date);
    $pP = ponto_previsto_cache($pdo, $companyId, $col, $prev);
    $pN = ponto_previsto_cache($pdo, $companyId, $col, $next);
    $start = strtotime($date . ' 00:00:00');
    $end = strtotime($next . ' 00:00:00') - 1;
    if ($pP['periodos']) {
      $abs = ponto_periodo_abs($prev, $pP['periodos']);
      $prevEnd = end($abs)[1];
      if ($prevEnd > $start) {
        $start = $prevEnd + 4 * 3600;
        if ($pD['periodos']) $start = min($start, ponto_periodo_abs($date, $pD['periodos'])[0][0] - 2 * 3600);
        $start = max($start, strtotime($date . ' 00:00:00'));
      }
    }
    if ($pD['periodos']) {
      $abs = ponto_periodo_abs($date, $pD['periodos']);
      $myEnd = end($abs)[1];
      if ($myEnd > $end) {
        $end = $myEnd + 4 * 3600;
        if ($pN['periodos']) $end = min($end, ponto_periodo_abs($next, $pN['periodos'])[0][0] - 2 * 3600 - 1);
      }
    }
    return [$start, $end];
  }
}

if (!function_exists('ponto_marcacoes_tratadas')) {
  /**
   * Marcações do dia de trabalho: originais (com desconsideração/reclassificação aplicadas) +
   * inclusões ativas. 'todas' traz também as desconsideradas e as inclusões pendentes, para a tela.
   */
  function ponto_marcacoes_tratadas(PDO $pdo, int $companyId, array $col, string $date): array {
    [$a, $b] = ponto_janela_dia($pdo, $companyId, $col, $date);
    $st = $pdo->prepare('SELECT id, nsr, data_hora, intencao, geofence, identificacao FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND data_hora BETWEEN ? AND ? ORDER BY data_hora, id');
    $st->execute([$companyId, (int)$col['id'], date('Y-m-d H:i:s', $a), date('Y-m-d H:i:s', $b)]);
    $orig = $st->fetchAll(PDO::FETCH_ASSOC);
    $trat = [];
    if ($orig) {
      $ids = array_map(static fn($m) => (int)$m['id'], $orig);
      $s2 = $pdo->prepare("SELECT * FROM ponto_tratamentos WHERE company_id = ? AND status = 'ATIVO' AND marcacao_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ') ORDER BY id');
      $s2->execute(array_merge([$companyId], $ids));
      foreach ($s2->fetchAll(PDO::FETCH_ASSOC) as $t) $trat[(int)$t['marcacao_id']][] = $t;
    }
    $todas = [];
    foreach ($orig as $m) {
      $item = ['id' => (int)$m['id'], 'origem' => 'ORIGINAL', 'nsr' => (int)$m['nsr'], 'data_hora' => (string)$m['data_hora'], 'ts' => strtotime((string)$m['data_hora']),
        'intencao' => $m['intencao'], 'intencao_original' => $m['intencao'], 'geofence' => $m['geofence'], 'desconsiderada' => false, 'pendente' => false, 'tratamentos' => []];
      foreach ($trat[(int)$m['id']] ?? [] as $t) {
        $item['tratamentos'][] = (int)$t['id'];
        if ($t['tipo'] === 'DESCONSIDERACAO') { $item['desconsiderada'] = true; $item['motivo'] = $t['motivo']; $item['tratamento_id'] = (int)$t['id']; }
        if ($t['tipo'] === 'AJUSTE_CLASSIFICACAO') { $item['intencao'] = $t['intencao']; $item['ajuste_id'] = (int)$t['id']; }
      }
      $todas[] = $item;
    }
    $s3 = $pdo->prepare("SELECT * FROM ponto_tratamentos WHERE company_id = ? AND colaborador_id = ? AND tipo = 'INCLUSAO' AND status IN ('ATIVO','PENDENTE') AND `data` = ? ORDER BY data_hora");
    $s3->execute([$companyId, (int)$col['id'], $date]);
    foreach ($s3->fetchAll(PDO::FETCH_ASSOC) as $t) {
      $todas[] = ['id' => null, 'origem' => 'INCLUIDA', 'nsr' => null, 'data_hora' => (string)$t['data_hora'], 'ts' => strtotime((string)$t['data_hora']), 'intencao' => $t['intencao'],
        'intencao_original' => null, 'geofence' => null, 'desconsiderada' => false, 'pendente' => $t['status'] === 'PENDENTE', 'tratamento_id' => (int)$t['id'], 'motivo' => $t['motivo'], 'tratamentos' => [(int)$t['id']]];
    }
    usort($todas, static fn($x, $y) => [$x['ts'], $x['origem']] <=> [$y['ts'], $y['origem']]);
    $validas = array_values(array_filter($todas, static fn($m) => !$m['desconsiderada'] && !$m['pendente']));
    return ['todas' => $todas, 'validas' => $validas, 'janela' => [$a, $b]];
  }
}

/* ------------------------------------------------------------------------- */
/* Apuração                                                                   */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_overlap')) {
  function ponto_overlap(int $a1, int $a2, int $b1, int $b2): int {
    return max(0, min($a2, $b2) - max($a1, $b1));
  }
}

if (!function_exists('ponto_minutos_noturnos')) {
  /** Minutos entre 22:00 e 05:00 dentro de [a, b]. */
  function ponto_minutos_noturnos(int $a, int $b): int {
    $tot = 0;
    for ($d = strtotime(date('Y-m-d', $a) . ' -1 day'); $d <= $b; $d = strtotime(date('Y-m-d', $d) . ' +1 day')) {
      $n1 = strtotime(date('Y-m-d', $d) . ' 22:00:00');
      $n2 = strtotime(date('Y-m-d', $d) . ' +1 day 05:00:00');
      $tot += ponto_overlap($a, $b, $n1, $n2);
    }
    return intdiv($tot, 60);
  }
}

if (!function_exists('ponto_jornada_tolerancias')) {
  function ponto_jornada_tolerancias(PDO $pdo, int $companyId, ?int $jornadaId): array {
    static $c = [];
    if (!$jornadaId) return [5, 5, 0];
    $k = $companyId . '|' . $jornadaId;
    if (!isset($c[$k])) {
      $st = $pdo->prepare('SELECT tolerancia_entrada, tolerancia_saida, tolerancia_intervalo FROM ponto_jornadas WHERE company_id = ? AND id = ?');
      $st->execute([$companyId, $jornadaId]);
      $r = $st->fetch(PDO::FETCH_ASSOC) ?: ['tolerancia_entrada' => 5, 'tolerancia_saida' => 5, 'tolerancia_intervalo' => 0];
      $c[$k] = [(int)$r['tolerancia_entrada'], (int)$r['tolerancia_saida'], (int)$r['tolerancia_intervalo']];
    }
    return $c[$k];
  }
}

if (!function_exists('ponto_ocorrencias_periodo')) {
  /** Ocorrências APROVADAS que tocam o período, por dia: [Y-m-d => [linhas]]. */
  function ponto_ocorrencias_periodo(PDO $pdo, int $companyId, int $colId, string $de, string $ate): array {
    $st = $pdo->prepare("SELECT o.*, t.descricao AS tipo, t.abona FROM ponto_ocorrencias o JOIN ponto_tipos_ocorrencia t ON t.company_id = o.company_id AND t.id = o.tipo_ocorrencia_id
      WHERE o.company_id = ? AND o.colaborador_id = ? AND o.status = 'APROVADA' AND o.data_inicio <= ? AND o.data_fim >= ?");
    $st->execute([$companyId, $colId, $ate, $de]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $o) {
      for ($d = max($de, (string)$o['data_inicio']); $d <= min($ate, (string)$o['data_fim']); $d = date('Y-m-d', strtotime($d . ' +1 day'))) $out[$d][] = $o;
    }
    return $out;
  }
}

if (!function_exists('ponto_inicio_controle')) {
  /**
   * Data em que a empresa começou a controlar o ponto no Nexa: a da configuração, ou a da
   * primeira marcação gravada. Antes dela não há falta (o ponto ainda não era usado).
   */
  function ponto_inicio_controle(PDO $pdo, int $companyId, bool $reset = false): string {
    static $c = [];
    if ($reset) unset($c[$companyId]);
    if (isset($c[$companyId])) return $c[$companyId];
    $cfg = (string)(ponto_config($pdo, $companyId)['inicio_controle'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $cfg)) return $c[$companyId] = $cfg;
    $st = $pdo->prepare('SELECT MIN(`data`) FROM ponto_arp_marcacoes WHERE company_id = ?');
    $st->execute([$companyId]);
    return $c[$companyId] = (string)($st->fetchColumn() ?: date('Y-m-d'));
  }
}

if (!function_exists('ponto_apurar_dia')) {
  /** Calcula um dia (não grava). $ocorrencias = ocorrências aprovadas do dia. */
  function ponto_apurar_dia(PDO $pdo, int $companyId, array $col, string $date, array $ocorrencias = [], ?string $hoje = null): array {
    $hoje = $hoje ?? (new DateTimeImmutable('now', ponto_tz(ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']), $pdo, $companyId)))->format('Y-m-d');
    $cfg = ponto_config($pdo, $companyId);
    $p = ponto_previsto_cache($pdo, $companyId, $col, $date);
    $r = ['data' => $date, 'situacao' => 'NORMAL', 'previstos' => (int)$p['minutos'], 'trabalhados' => 0, 'intervalo' => 0, 'atraso' => 0, 'saida_antecipada' => 0,
      'falta' => 0, 'extra' => 0, 'noturnos' => 0, 'abonados' => 0, 'qtd' => 0, 'inconsistente' => 0, 'alertas' => [], 'marcacoes' => [], 'pares' => [],
      'previsto' => $p, 'ocorrencias' => array_map(static fn($o) => ['id' => (int)$o['id'], 'tipo' => $o['tipo'], 'abona' => (int)$o['abona'], 'horas' => $o['hora_inicio'] ? substr((string)$o['hora_inicio'], 0, 5) . '–' . substr((string)$o['hora_fim'], 0, 5) : null], $ocorrencias),
      'primeira' => null, 'ultima' => null];
    if ($date < (string)$col['data_admissao'] || (!empty($col['data_demissao']) && $date > (string)$col['data_demissao'])) {
      $r['situacao'] = 'FORA_CONTRATO'; $r['previstos'] = 0; return $r;
    }
    $m = ponto_marcacoes_tratadas($pdo, $companyId, $col, $date);
    $r['marcacoes'] = $m['todas'];
    $v = $m['validas'];
    $r['qtd'] = count($v);
    // Antes de a empresa começar a usar o ponto: sem marcação não é falta.
    if (!$v && $date < ponto_inicio_controle($pdo, $companyId)) { $r['situacao'] = 'ANTES_INICIO'; $r['previstos'] = 0; return $r; }
    if ($date > $hoje) { $r['situacao'] = 'FUTURO'; return $r; }

    // Pares entrada/saída na ordem do horário.
    $pares = [];
    for ($i = 0; $i + 1 < count($v); $i += 2) $pares[] = [$v[$i]['ts'], $v[$i + 1]['ts']];
    $impar = count($v) % 2 === 1;
    foreach ($pares as [$a, $b]) { $r['trabalhados'] += intdiv($b - $a, 60); $r['noturnos'] += ponto_minutos_noturnos($a, $b); }
    for ($i = 1; $i < count($pares); $i++) $r['intervalo'] += intdiv($pares[$i][0] - $pares[$i - 1][1], 60);
    $r['pares'] = array_map(static fn($x) => [date('H:i', $x[0]), date('H:i', $x[1])], $pares);
    if ($v) { $r['primeira'] = $v[0]['ts']; $r['ultima'] = end($v)['ts']; }

    $trabalha = (bool)$p['trabalha'];
    if ($impar) {
      if ($date === $hoje) { $r['situacao'] = 'EM_ANDAMENTO'; }
      else { $r['situacao'] = 'INCOMPLETO'; $r['inconsistente'] = 1; $r['alertas'][] = 'Número ímpar de marcações: falta uma entrada ou saída.'; }
    }

    if (!$trabalha) {
      $r['previstos'] = 0;
      if ($p['origem'] === 'sem_jornada' && !$v) { $r['situacao'] = 'SEM_JORNADA'; return $r; }
      if ($r['situacao'] === 'NORMAL') $r['situacao'] = $v ? ($p['feriado'] ? 'FERIADO_TRABALHADO' : 'FOLGA_TRABALHADA') : ($p['feriado'] ? 'FERIADO' : 'FOLGA');
      if (!$impar) $r['extra'] = $r['trabalhados'];
      ponto_apurar_alertas_intervalo($r);
      return $r;
    }

    $abs = ponto_periodo_abs($date, $p['periodos']);
    [$tolE, $tolS] = ponto_jornada_tolerancias($pdo, $companyId, $p['jornada_id'] ? (int)$p['jornada_id'] : null);
    $tolDia = max(0, (int)($cfg['tolerancia_diaria'] ?? 10));

    if (!$v && $date === $hoje) { $r['situacao'] = time() < $abs[0][0] ? 'FUTURO' : 'EM_ANDAMENTO'; }
    if ($impar || ($date === $hoje && !$v)) {
      // Dia incompleto: não calcula saldo (aguarda o tratamento ou o fim do dia).
      ponto_apurar_abono($r, $ocorrencias, $abs);
      return $r;
    }
    if (!$v) {
      $r['situacao'] = 'FALTA';
      $r['falta'] = $r['previstos'];
      ponto_apurar_abono($r, $ocorrencias, $abs);
      if ($r['falta'] > 0) $r['inconsistente'] = 1;
      return $r;
    }

    // Atraso / saída antecipada: período a período quando os pares batem com a jornada.
    $desvios = [];
    if (count($pares) === count($abs)) {
      foreach ($abs as $i => [$pi, $pf]) {
        $dE = intdiv($pares[$i][0] - $pi, 60); $dS = intdiv($pf - $pares[$i][1], 60);
        if ($dE > 0) $r['atraso'] += $dE;
        if ($dS > 0) $r['saida_antecipada'] += $dS;
        $desvios[] = [abs($dE), $tolE]; $desvios[] = [abs($dS), $tolS];
      }
    } else {
      $dE = intdiv($pares[0][0] - $abs[0][0], 60); $dS = intdiv(end($abs)[1] - end($pares)[1], 60);
      if ($dE > 0) $r['atraso'] = $dE;
      if ($dS > 0) $r['saida_antecipada'] = $dS;
      $desvios = [[abs($dE), $tolE], [abs($dS), $tolS]];
      $r['alertas'][] = 'Quantidade de marcações diferente da jornada prevista.';
    }
    $saldo = $r['trabalhados'] - $r['previstos'];
    // Tolerância do art. 58, § 1º: cada marcação dentro da tolerância e no máximo $tolDia no dia.
    $dentro = true; $soma = 0;
    foreach ($desvios as [$d, $tol]) { $soma += $d; if ($d > $tol) $dentro = false; }
    if ($dentro && $soma <= $tolDia && abs($saldo) <= $tolDia) { $saldo = 0; $r['atraso'] = 0; $r['saida_antecipada'] = 0; }
    if ($saldo > 0) $r['extra'] = $saldo; else $r['falta'] = -$saldo;
    ponto_apurar_abono($r, $ocorrencias, $abs);
    ponto_apurar_alertas_intervalo($r);
    return $r;
  }
}

if (!function_exists('ponto_apurar_abono')) {
  /** Ocorrência aprovada que abona: dia inteiro zera o débito; por horário abona até a duração. */
  function ponto_apurar_abono(array &$r, array $ocorrencias, array $abs): void {
    foreach ($ocorrencias as $o) {
      if (!(int)$o['abona']) continue;
      if (empty($o['hora_inicio'])) {
        $aberto = in_array($r['situacao'], ['FALTA', 'INCOMPLETO', 'EM_ANDAMENTO'], true);
        $deb = $aberto ? max($r['falta'], $r['previstos'] - $r['trabalhados'], 0) : $r['falta'];
        if ($deb > 0 || $aberto) {
          $r['abonados'] += $deb; $r['falta'] = 0; $r['atraso'] = 0; $r['saida_antecipada'] = 0;
          if ($aberto) { $r['situacao'] = 'ABONADO'; $r['inconsistente'] = 0; $r['alertas'] = []; }
        }
        continue;
      }
      $min = intdiv(strtotime('2000-01-01 ' . $o['hora_fim']) - strtotime('2000-01-01 ' . $o['hora_inicio']), 60);
      $ab = min($min, $r['falta']);
      $r['abonados'] += $ab; $r['falta'] -= $ab;
      if ($r['situacao'] === 'FALTA' && $r['falta'] === 0) { $r['situacao'] = 'ABONADO'; $r['inconsistente'] = 0; }
    }
  }
}

if (!function_exists('ponto_apurar_alertas_intervalo')) {
  /** Art. 71: acima de 6h, intervalo mínimo de 1h; de 4h a 6h, 15 min. */
  function ponto_apurar_alertas_intervalo(array &$r): void {
    if ($r['trabalhados'] > 360 && $r['intervalo'] < 60) $r['alertas'][] = $r['intervalo'] ? 'Intervalo menor que 1 hora (trabalhou mais de 6h).' : 'Sem intervalo (trabalhou mais de 6h).';
    elseif ($r['trabalhados'] > 240 && $r['trabalhados'] <= 360 && $r['intervalo'] < 15 && count($r['pares']) > 1) $r['alertas'][] = 'Intervalo menor que 15 minutos.';
  }
}

if (!function_exists('ponto_apurar_periodo')) {
  /**
   * Apura o colaborador de $de a $ate e grava em ponto_apuracoes_diarias (substitui o que havia
   * no período: a tabela é derivada). Inclui o alerta de descanso entre jornadas (< 11h).
   */
  function ponto_apurar_periodo(PDO $pdo, int $companyId, int $colId, string $de, string $ate, bool $gravar = true): array {
    $col = ponto_colaborador_get($pdo, $companyId, $colId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    ponto_previsto_cache($pdo, $companyId, $col, $de, true);
    $hoje = (new DateTimeImmutable('now', ponto_tz(ponto_estabelecimento_get($pdo, $companyId, (int)$col['estabelecimento_id']), $pdo, $companyId)))->format('Y-m-d');
    $ocs = ponto_ocorrencias_periodo($pdo, $companyId, $colId, $de, $ate);
    $dias = [];
    $anterior = ponto_apurar_dia($pdo, $companyId, $col, date('Y-m-d', strtotime($de . ' -1 day')), [], $hoje);
    for ($d = $de; $d <= $ate; $d = date('Y-m-d', strtotime($d . ' +1 day'))) {
      $r = ponto_apurar_dia($pdo, $companyId, $col, $d, $ocs[$d] ?? [], $hoje);
      if ($r['primeira'] && $anterior['ultima'] && ($r['primeira'] - $anterior['ultima']) < 11 * 3600) {
        $r['alertas'][] = 'Descanso entre jornadas menor que 11h (' . ponto_hm(intdiv($r['primeira'] - $anterior['ultima'], 60), false) . ').';
      }
      $dias[$d] = $r;
      $anterior = $r;
    }
    if ($gravar) {
      $pdo->beginTransaction();
      try {
        $pdo->prepare('DELETE FROM ponto_apuracoes_diarias WHERE company_id = ? AND colaborador_id = ? AND `data` BETWEEN ? AND ?')->execute([$companyId, $colId, $de, $ate]);
        $ins = $pdo->prepare('INSERT INTO ponto_apuracoes_diarias (company_id, colaborador_id, `data`, situacao, minutos_previstos, minutos_trabalhados, minutos_intervalo, minutos_atraso, minutos_saida_antecipada,
          minutos_falta, minutos_extra, minutos_noturnos, minutos_abonados, marcacoes_qtd, inconsistente, detalhe_json, calculado_em) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)');
        $now = date('Y-m-d H:i:s');
        foreach ($dias as $d => $r) {
          $ins->execute([$companyId, $colId, $d, $r['situacao'], $r['previstos'], $r['trabalhados'], $r['intervalo'], $r['atraso'], $r['saida_antecipada'], $r['falta'], $r['extra'],
            $r['noturnos'], $r['abonados'], $r['qtd'], ($r['inconsistente'] || $r['alertas']) ? 1 : 0,
            json_encode(['pares' => $r['pares'], 'alertas' => $r['alertas'], 'ocorrencias' => array_column($r['ocorrencias'], 'tipo')], JSON_UNESCAPED_UNICODE), $now]);
        }
        $pdo->commit();
      } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
      }
    }
    return $dias;
  }
}

if (!function_exists('ponto_apuracao_recalcular')) {
  /** Recalcula os dias afetados (mais o dia seguinte, por causa da jornada noturna e do descanso). */
  function ponto_apuracao_recalcular(PDO $pdo, int $companyId, int $colId, string $de, string $ate): void {
    if (!ponto_v4_ready($pdo)) return;
    $ate2 = date('Y-m-d', strtotime($ate . ' +1 day'));
    if ((strtotime($ate2) - strtotime($de)) > 400 * 86400) return;
    ponto_apurar_periodo($pdo, $companyId, $colId, $de, $ate2);
  }
}

if (!function_exists('ponto_apuracao_totais')) {
  function ponto_apuracao_totais(array $dias): array {
    $t = ['previstos' => 0, 'trabalhados' => 0, 'extra' => 0, 'falta' => 0, 'atraso' => 0, 'saida_antecipada' => 0, 'noturnos' => 0, 'abonados' => 0, 'faltas_dias' => 0, 'inconsistencias' => 0];
    foreach ($dias as $r) {
      foreach (['previstos', 'trabalhados', 'extra', 'falta', 'atraso', 'saida_antecipada', 'noturnos', 'abonados'] as $k) $t[$k] += (int)$r[$k];
      if ($r['situacao'] === 'FALTA') $t['faltas_dias']++;
      if ($r['inconsistente'] || $r['alertas']) $t['inconsistencias']++;
    }
    $t['saldo'] = $t['extra'] - $t['falta'];
    return $t;
  }
}

if (!function_exists('ponto_competencia')) {
  /** "2026-11" => [de, ate, rótulo]. Inválida => mês atual. */
  function ponto_competencia(string $c): array {
    if (!preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $c)) $c = date('Y-m');
    $de = $c . '-01';
    $meses = ['', 'Janeiro', 'Fevereiro', 'Março', 'Abril', 'Maio', 'Junho', 'Julho', 'Agosto', 'Setembro', 'Outubro', 'Novembro', 'Dezembro'];
    return [$de, date('Y-m-t', strtotime($de)), $meses[(int)substr($c, 5, 2)] . '/' . substr($c, 0, 4), $c];
  }
}

if (!function_exists('ponto_inconsistencias')) {
  /** Dias com problema (gravados na apuração) no período, de todos os colaboradores. */
  function ponto_inconsistencias(PDO $pdo, int $companyId, string $de, string $ate): array {
    $st = $pdo->prepare('SELECT a.*, c.nome, c.matricula FROM ponto_apuracoes_diarias a JOIN ponto_colaboradores c ON c.company_id = a.company_id AND c.id = a.colaborador_id
      WHERE a.company_id = ? AND a.`data` BETWEEN ? AND ? AND a.inconsistente = 1 ORDER BY a.`data` DESC, c.nome LIMIT 500');
    $st->execute([$companyId, $de, $ate]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}
