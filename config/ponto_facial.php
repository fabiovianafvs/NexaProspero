<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V10: biometria facial no Nexa (tablet do terminal e celular/computador do Bater Ponto).
 *
 * Regra por empresa (app_config `ponto`):
 *  - facial_modo: DESLIGADO (padrão) | FOTO (tira a foto na marcação) | RECONHECER (compara com o rosto cadastrado)
 *  - facial_falha: SINALIZAR (registra e marca para o gestor conferir) | BLOQUEAR (não registra)
 *  - facial_rigor: RIGOROSO | NORMAL | FLEXIVEL (distância máxima entre os rostos)
 *  - facial_foto_dias: por quantos dias a foto da marcação fica guardada (30 a 365)
 *
 * O reconhecimento roda no navegador (face-api, em assets/vendor/face-api): a página manda ao servidor
 * a foto e o "modelo" do rosto (128 números). O servidor compara com o modelo cadastrado do
 * colaborador. O rosto só é cadastrado com o consentimento do colaborador (LGPD art. 11, I) e pode
 * ser removido a qualquer momento; quem não consentiu continua batendo ponto (fica "sem cadastro").
 * Nada disso altera o ARP: a foto e o resultado ficam em ponto_marcacao_faciais, ao lado da marcação.
 */

if (!defined('PONTO_FACIAL_MODOS')) {
  define('PONTO_FACIAL_MODOS', ['DESLIGADO' => 'Desligado', 'FOTO' => 'Tirar a foto na hora de bater o ponto (sem comparar)', 'RECONHECER' => 'Reconhecer o rosto (compara com o rosto cadastrado)']);
  define('PONTO_FACIAL_FALHAS', ['SINALIZAR' => 'Registrar mesmo assim e marcar para o gestor conferir', 'BLOQUEAR' => 'Não registrar o ponto']);
  define('PONTO_FACIAL_RIGOR', ['RIGOROSO' => 0.45, 'NORMAL' => 0.50, 'FLEXIVEL' => 0.55]);
  define('PONTO_FACIAL_RIGOR_LABEL', ['RIGOROSO' => 'Rigoroso (menos chance de outra pessoa passar)', 'NORMAL' => 'Normal (recomendado)', 'FLEXIVEL' => 'Flexível (aceita mais variação de luz e ângulo)']);
  define('PONTO_FACIAL_RESULTADOS', [
    'CONFERE' => 'Rosto conferido', 'NAO_CONFERE' => 'Rosto não confere', 'SEM_CADASTRO' => 'Sem rosto cadastrado',
    'SEM_ROSTO' => 'Rosto não encontrado na foto', 'SEM_FOTO' => 'Sem foto (câmera indisponível)', 'FOTO' => 'Foto registrada',
  ]);
  define('PONTO_FACIAL_TERMO_VERSAO', 'v1');
}

if (!function_exists('ponto_v10_ready')) {
  function ponto_v10_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $pdo->query('SELECT 1 FROM ponto_biometria WHERE 1 = 0');
      return $ready = true;
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('ponto_facial_cfg')) {
  function ponto_facial_cfg(PDO $pdo, int $companyId): array {
    $c = ponto_config($pdo, $companyId);
    $modo = isset(PONTO_FACIAL_MODOS[(string)($c['facial_modo'] ?? '')]) ? (string)$c['facial_modo'] : 'DESLIGADO';
    if (!ponto_v10_ready($pdo)) $modo = 'DESLIGADO';
    $rigor = isset(PONTO_FACIAL_RIGOR[(string)($c['facial_rigor'] ?? '')]) ? (string)$c['facial_rigor'] : 'NORMAL';
    return [
      'modo' => $modo,
      'falha' => isset(PONTO_FACIAL_FALHAS[(string)($c['facial_falha'] ?? '')]) ? (string)$c['facial_falha'] : 'SINALIZAR',
      'rigor' => $rigor, 'limiar' => PONTO_FACIAL_RIGOR[$rigor],
      'foto_dias' => max(30, min(365, (int)($c['facial_foto_dias'] ?? 90))),
    ];
  }
}

if (!function_exists('ponto_facial_descritor')) {
  /** "0.0123,-0.04,..." (128 números) → array de float, ou null se inválido. */
  function ponto_facial_descritor(?string $s): ?array {
    $s = trim((string)$s);
    if ($s === '' || strlen($s) > 4000) return null;
    $p = explode(',', $s);
    if (count($p) !== 128) return null;
    $out = [];
    foreach ($p as $v) {
      if (!is_numeric(trim($v))) return null;
      $f = (float)$v;
      if (!is_finite($f) || abs($f) > 2) return null;
      $out[] = $f;
    }
    return $out;
  }
  function ponto_facial_descritor_txt(array $d): string {
    return implode(',', array_map(static fn($f) => rtrim(rtrim(sprintf('%.6F', $f), '0'), '.') ?: '0', $d));
  }
  function ponto_facial_distancia(array $a, array $b): float {
    $s = 0.0;
    foreach ($a as $i => $v) $s += ($v - $b[$i]) ** 2;
    return sqrt($s);
  }
}

if (!function_exists('ponto_biometria_get')) {
  function ponto_biometria_get(PDO $pdo, int $companyId, int $colId): ?array {
    if (!ponto_v10_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT * FROM ponto_biometria WHERE company_id = ? AND colaborador_id = ?');
    $st->execute([$companyId, $colId]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
  /** Rosto válido para conferir: consentimento em vigor e modelo gravado. */
  function ponto_biometria_ativa(?array $b): bool {
    return $b && !empty($b['consentimento_em']) && empty($b['revogado_em']) && !empty($b['descritor']);
  }
}

if (!function_exists('ponto_facial_jpeg')) {
  /** dataURL/base64 de JPEG enviado pela página → bytes (até 600 KB), ou null. */
  function ponto_facial_jpeg(?string $data): ?string {
    $data = (string)$data;
    if ($data === '') return null;
    if (strncmp($data, 'data:image/jpeg;base64,', 23) === 0) $data = substr($data, 23);
    if (strlen($data) > 820000) throw new RuntimeException('Foto grande demais.');
    $bin = base64_decode($data, true);
    if ($bin === false || strlen($bin) < 500) return null;
    $info = @getimagesizefromstring($bin);
    if (!$info || (int)$info[2] !== IMAGETYPE_JPEG) return null;
    return $bin;
  }
}

if (!function_exists('ponto_biometria_cadastrar')) {
  /**
   * Cadastra (ou troca) o rosto do colaborador. Exige o consentimento. $foto (JPEG) é a foto tirada
   * na hora: vira a foto do cadastro quando o colaborador ainda não tem uma.
   */
  function ponto_biometria_cadastrar(PDO $pdo, int $companyId, int $colId, string $descritor, bool $consentiu, int $userId, string $origem, ?string $foto = null): void {
    if (!ponto_v10_ready($pdo)) throw new RuntimeException('A biometria facial ainda não foi instalada no banco.');
    $col = ponto_colaborador_get($pdo, $companyId, $colId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    if (!$consentiu) throw new RuntimeException('É preciso o consentimento do colaborador (termo assinado) para cadastrar o rosto.');
    $d = ponto_facial_descritor($descritor);
    if (!$d) throw new RuntimeException('Rosto não encontrado na foto. Use uma foto de frente, com boa luz e só uma pessoa.');
    $origem = in_array($origem, ['FOTO_CADASTRO', 'CAMERA'], true) ? $origem : 'CAMERA';
    if ($foto !== null && !ponto_foto_file($col)) {
      $tmp = tempnam(sys_get_temp_dir(), 'pfc');
      file_put_contents($tmp, $foto);
      try { ponto_foto_save($pdo, $companyId, $colId, $tmp); } finally { @unlink($tmp); }
    }
    $now = date('Y-m-d H:i:s');
    $b = ponto_biometria_get($pdo, $companyId, $colId);
    if ($b) {
      $pdo->prepare('UPDATE ponto_biometria SET consentimento_em = ?, consentimento_por = ?, termo_versao = ?, descritor = ?, descritor_em = ?, descritor_origem = ?, revogado_em = NULL, revogado_por = NULL, updated_at = ? WHERE company_id = ? AND colaborador_id = ?')
        ->execute([$b['consentimento_em'] && empty($b['revogado_em']) ? $b['consentimento_em'] : $now, $userId ?: null, PONTO_FACIAL_TERMO_VERSAO, ponto_facial_descritor_txt($d), $now, $origem, $now, $companyId, $colId]);
    } else {
      $pdo->prepare('INSERT INTO ponto_biometria (company_id, colaborador_id, consentimento_em, consentimento_por, termo_versao, descritor, descritor_em, descritor_origem, updated_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $colId, $now, $userId ?: null, PONTO_FACIAL_TERMO_VERSAO, ponto_facial_descritor_txt($d), $now, $origem, $now]);
    }
    ponto_audit($pdo, $companyId, 'cadastrar_rosto', 'biometria', $colId, null, ['origem' => $origem, 'termo' => PONTO_FACIAL_TERMO_VERSAO], $colId);
    if (function_exists('ponto_equip_rostos_enfileirar')) ponto_equip_rostos_enfileirar($pdo, $companyId, null, $colId, 'FOTO');
  }
}

if (!function_exists('ponto_biometria_revogar')) {
  /** Remove o rosto (o colaborador retirou o consentimento ou foi desligado). Apaga o modelo e tira dos aparelhos. */
  function ponto_biometria_revogar(PDO $pdo, int $companyId, int $colId, int $userId, string $motivo = ''): void {
    $b = ponto_biometria_get($pdo, $companyId, $colId);
    if (!$b || !empty($b['revogado_em'])) throw new RuntimeException('Este colaborador não tem rosto cadastrado.');
    $now = date('Y-m-d H:i:s');
    $pdo->prepare('UPDATE ponto_biometria SET descritor = NULL, revogado_em = ?, revogado_por = ?, updated_at = ? WHERE company_id = ? AND colaborador_id = ?')
      ->execute([$now, $userId ?: null, $now, $companyId, $colId]);
    ponto_audit($pdo, $companyId, 'remover_rosto', 'biometria', $colId, null, ['motivo' => $motivo], $colId);
    if (function_exists('ponto_equip_rostos_enfileirar')) ponto_equip_rostos_enfileirar($pdo, $companyId, null, $colId, 'REMOVER');
  }
}

if (!function_exists('ponto_facial_antes_de_marcar')) {
  /**
   * Antes de gravar a marcação feita pelo Nexa: confere a foto/rosto conforme a regra da empresa.
   * $in: foto (dataURL JPEG), descritor (128 números), camera_erro. Devolve o contexto para depois da
   * marcação (ou null com o facial desligado). Lança RuntimeException quando a empresa bloqueia.
   */
  function ponto_facial_antes_de_marcar(PDO $pdo, int $companyId, array $col, array $in): ?array {
    $cfg = ponto_facial_cfg($pdo, $companyId);
    if ($cfg['modo'] === 'DESLIGADO') return null;
    $foto = ponto_facial_jpeg(isset($in['foto']) ? (string)$in['foto'] : null);
    $ctx = ['modo' => $cfg['modo'], 'resultado' => $foto ? 'FOTO' : 'SEM_FOTO', 'distancia' => null, 'foto' => $foto];
    if ($cfg['modo'] === 'RECONHECER') {
      $b = ponto_biometria_get($pdo, $companyId, (int)$col['id']);
      if (!ponto_biometria_ativa($b)) {
        $ctx['resultado'] = 'SEM_CADASTRO';
      } elseif ($foto) {
        $d = ponto_facial_descritor(isset($in['descritor']) ? (string)$in['descritor'] : null);
        $ref = ponto_facial_descritor((string)$b['descritor']);
        if (!$d || !$ref) {
          $ctx['resultado'] = 'SEM_ROSTO';
        } else {
          $ctx['distancia'] = round(ponto_facial_distancia($d, $ref), 3);
          $ctx['resultado'] = $ctx['distancia'] <= $cfg['limiar'] ? 'CONFERE' : 'NAO_CONFERE';
        }
      }
    }
    if ($cfg['falha'] === 'BLOQUEAR' && in_array($ctx['resultado'], ['SEM_FOTO', 'SEM_ROSTO', 'NAO_CONFERE'], true)) {
      $msg = ['SEM_FOTO' => 'A empresa exige a foto para registrar o ponto. Permita o uso da câmera e tente de novo.',
        'SEM_ROSTO' => 'Rosto não encontrado na foto. Olhe para a câmera, com boa luz, e tente de novo.',
        'NAO_CONFERE' => 'O rosto não confere com o cadastro. Tente de novo com boa luz ou fale com o seu gestor.'][$ctx['resultado']];
      throw new RuntimeException($msg);
    }
    return $ctx;
  }
}

if (!function_exists('ponto_facial_dir')) {
  function ponto_facial_dir(int $companyId): string {
    return ponto_storage_root() . '/' . $companyId . '/marcacoes';
  }
}

if (!function_exists('ponto_facial_depois_de_marcar')) {
  /** Grava a foto e o resultado ao lado da marcação. Nunca impede a marcação (já gravada). */
  function ponto_facial_depois_de_marcar(PDO $pdo, int $companyId, array $m, ?array $ctx): ?array {
    if (!$ctx || empty($m['id'])) return null;
    try {
      $st = $pdo->prepare('SELECT id FROM ponto_marcacao_faciais WHERE company_id = ? AND marcacao_id = ? LIMIT 1');
      $st->execute([$companyId, (int)$m['id']]);
      if ($st->fetchColumn()) return $ctx; // repetição da mesma requisição
      $rel = null;
      if ($ctx['foto']) {
        $sub = date('Ym');
        $dir = ponto_facial_dir($companyId) . '/' . $sub;
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        $name = (int)$m['id'] . '_' . bin2hex(random_bytes(4)) . '.jpg';
        if (@file_put_contents($dir . '/' . $name, $ctx['foto']) !== false) $rel = $companyId . '/marcacoes/' . $sub . '/' . $name;
      }
      $pdo->prepare('INSERT INTO ponto_marcacao_faciais (company_id, marcacao_id, colaborador_id, modo, resultado, distancia, arquivo, created_at) VALUES (?,?,?,?,?,?,?,?)')
        ->execute([$companyId, (int)$m['id'], (int)$m['colaborador_id'], $ctx['modo'], $ctx['resultado'], $ctx['distancia'], $rel, date('Y-m-d H:i:s')]);
      ponto_facial_limpar_fotos($pdo, $companyId);
    } catch (Throwable $e) {
      error_log('[Nexa Ponto facial] ' . $e->getMessage());
    }
    return $ctx;
  }
}

if (!function_exists('ponto_facial_limpar_fotos')) {
  /** Apaga as fotos de marcação mais antigas que o prazo da empresa (aos poucos, a cada nova foto). */
  function ponto_facial_limpar_fotos(PDO $pdo, int $companyId, int $lote = 30): int {
    $dias = ponto_facial_cfg($pdo, $companyId)['foto_dias'];
    $st = $pdo->prepare('SELECT id, arquivo FROM ponto_marcacao_faciais WHERE company_id = ? AND arquivo IS NOT NULL AND created_at < ? ORDER BY id LIMIT ' . max(1, min(200, $lote)));
    $st->execute([$companyId, date('Y-m-d H:i:s', time() - $dias * 86400)]);
    $n = 0;
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $f = ponto_storage_root() . '/' . $r['arquivo'];
      if (strpos((string)$r['arquivo'], '..') === false && is_file($f)) @unlink($f);
      $pdo->prepare('UPDATE ponto_marcacao_faciais SET arquivo = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, (int)$r['id']]);
      $n++;
    }
    return $n;
  }
}

if (!function_exists('ponto_facial_por_marcacoes')) {
  /** marcacao_id → linha (para a tela de marcações). */
  function ponto_facial_por_marcacoes(PDO $pdo, int $companyId, array $ids): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
    if (!$ids || !ponto_v10_ready($pdo)) return [];
    $out = [];
    foreach (array_chunk($ids, 500) as $chunk) {
      $st = $pdo->prepare('SELECT * FROM ponto_marcacao_faciais WHERE company_id = ? AND marcacao_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')');
      $st->execute(array_merge([$companyId], $chunk));
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['marcacao_id']] = $r;
    }
    return $out;
  }
  function ponto_facial_foto_file(PDO $pdo, int $companyId, int $marcacaoId): ?string {
    if (!ponto_v10_ready($pdo)) return null;
    $st = $pdo->prepare('SELECT arquivo FROM ponto_marcacao_faciais WHERE company_id = ? AND marcacao_id = ? AND arquivo IS NOT NULL LIMIT 1');
    $st->execute([$companyId, $marcacaoId]);
    $rel = (string)($st->fetchColumn() ?: '');
    if ($rel === '' || strpos($rel, '..') !== false || strpos($rel, $companyId . '/') !== 0) return null;
    $f = ponto_storage_root() . '/' . $rel;
    return is_file($f) ? $f : null;
  }
}

if (!function_exists('ponto_facial_pagina_cfg')) {
  /** Configuração que vai para a página (JS) do Bater Ponto e do terminal. */
  function ponto_facial_pagina_cfg(PDO $pdo, int $companyId): array {
    $c = ponto_facial_cfg($pdo, $companyId);
    return ['modo' => $c['modo'], 'bloqueia' => $c['falha'] === 'BLOQUEAR', 'base' => '/assets/vendor/face-api'];
  }
}
