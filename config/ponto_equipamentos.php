<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V9: equipamentos e acesso físico (porta/catraca).
 *
 * Um equipamento é um aparelho (ponto_coletores) de tipo:
 *  - CONTROLID: Control iD em modo online. O aparelho chama o Nexa pela internet a cada leitura
 *    (crachá, rosto, digital, matrícula+senha) e o Nexa responde se libera. Endereço configurado
 *    no aparelho: https://<empresa>.nexaprospero.com.br/equipamento/controlid/<código secreto>
 *  - API: qualquer outra marca/integrador. POST em /equipamento/api/<código secreto>/identificar
 *    com cartao | usuario | matricula+pin; resposta JSON {ok, liberar, ponto_registrado, mensagem}.
 *
 * Funções marcadas pela empresa em cada equipamento: registrar o ponto (funcao_ponto) e/ou
 * liberar a porta/catraca (funcao_acesso). Regra do acesso físico: qualquer colaborador ativo,
 * ou só dentro da jornada (mesma janela e liberações do Acesso ao Nexa, sem exigir o ponto,
 * porque a pessoa passa pela porta antes de bater o ponto). Saída é sempre liberada.
 *
 * O código secreto fica só como hash (token_hash); aparece uma vez ao gerar o endereço.
 */

require_once __DIR__ . '/ponto_marcacao.php';
require_once __DIR__ . '/ponto_apuracao.php';
require_once __DIR__ . '/ponto_acesso.php';

if (!defined('PONTO_EQUIP_TIPOS')) {
  define('PONTO_EQUIP_TIPOS', ['CONTROLID' => 'Equipamento Control iD', 'API' => 'Outro equipamento (integração por API)']);
  define('PONTO_EQUIP_ACOES', [
    'door' => 'Relé do próprio aparelho (fechadura/porta)',
    'sec_box' => 'Módulo de acionamento externo (SecBox)',
    'catra' => 'Catraca (iDBlock)',
    'nenhuma' => 'Não aciona nada (só registra e mostra a mensagem)',
  ]);
  define('PONTO_EQUIP_REGRAS', ['JORNADA' => 'Só dentro da jornada (com as tolerâncias e liberações do Acesso ao Nexa)', 'ATIVO' => 'Qualquer colaborador ativo, a qualquer hora']);
  define('PONTO_EQUIP_SENTIDOS', ['ENTRADA' => 'Entrada', 'SAIDA' => 'Saída (sempre liberada)', 'AMBOS' => 'Entrada e saída (catraca nos dois sentidos)']);
  define('PONTO_EQUIP_RESULTADOS', ['LIBERADO' => 'Liberado', 'PONTO' => 'Ponto registrado', 'NEGADO' => 'Negado', 'NAO_IDENT' => 'Não identificado', 'CARTAO_NOVO' => 'Cartão cadastrado', 'ERRO' => 'Erro']);
}

if (!function_exists('ponto_v9_ready')) {
  function ponto_v9_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $pdo->query('SELECT 1 FROM ponto_equip_eventos WHERE 1 = 0');
      return $ready = true;
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

if (!function_exists('ponto_equip_is')) {
  function ponto_equip_is(array $c): bool {
    return isset(PONTO_EQUIP_TIPOS[(string)($c['tipo'] ?? '')]);
  }
}

if (!function_exists('ponto_equip_save_extra')) {
  /** Funções e regras do equipamento (colunas da V9). Chamado depois de ponto_coletor_save. */
  function ponto_equip_save_extra(PDO $pdo, int $companyId, int $id, array $in): void {
    $d = [
      'funcao_ponto' => empty($in['funcao_ponto']) ? 0 : 1,
      'funcao_acesso' => empty($in['funcao_acesso']) ? 0 : 1,
      'acesso_regra' => isset(PONTO_EQUIP_REGRAS[(string)($in['acesso_regra'] ?? '')]) ? (string)$in['acesso_regra'] : 'JORNADA',
      'acesso_sentido' => isset(PONTO_EQUIP_SENTIDOS[(string)($in['acesso_sentido'] ?? '')]) ? (string)$in['acesso_sentido'] : 'ENTRADA',
      'acesso_acao' => isset(PONTO_EQUIP_ACOES[(string)($in['acesso_acao'] ?? '')]) ? (string)$in['acesso_acao'] : 'door',
      'equip_serial' => ($s = preg_replace('/[^0-9A-Za-z]/', '', (string)($in['equip_serial'] ?? '')) ?? '') !== '' ? mb_substr($s, 0, 40) : null,
      'dupla_seg' => max(0, min(900, (int)($in['dupla_seg'] ?? 120))),
    ];
    if (!$d['funcao_ponto'] && !$d['funcao_acesso']) throw new RuntimeException('Marque o que o equipamento faz: registrar o ponto, liberar a porta/catraca ou os dois.');
    $pdo->prepare('UPDATE ponto_coletores SET funcao_ponto = ?, funcao_acesso = ?, acesso_regra = ?, acesso_sentido = ?, acesso_acao = ?, equip_serial = ?, dupla_seg = ? WHERE company_id = ? AND id = ?')
      ->execute(array_merge(array_values($d), [$companyId, $id]));
    ponto_audit($pdo, $companyId, 'alterar_funcoes', 'coletores', $id, null, $d);
  }
}

if (!function_exists('ponto_equip_url')) {
  function ponto_equip_url(array $c, string $token): string {
    return ponto_base_url() . '/equipamento/' . ((string)$c['tipo'] === 'CONTROLID' ? 'controlid' : 'api') . '/' . $token;
  }
}

if (!function_exists('ponto_equip_gerar_endereco')) {
  /** Gera (ou troca) o endereço secreto do equipamento. O anterior para de funcionar na hora. */
  function ponto_equip_gerar_endereco(PDO $pdo, int $companyId, int $id): string {
    $st = $pdo->prepare('SELECT * FROM ponto_coletores WHERE company_id = ? AND id = ? AND ativo = 1');
    $st->execute([$companyId, $id]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (!$c || !ponto_equip_is($c)) throw new RuntimeException('Equipamento não encontrado ou desativado.');
    $token = rtrim(strtr(base64_encode(random_bytes(24)), '+/', '-_'), '=');
    $pdo->prepare('UPDATE ponto_coletores SET token_hash = ?, pareamento_hash = NULL, pareamento_expira = NULL WHERE company_id = ? AND id = ?')
      ->execute([hash('sha256', $token), $companyId, $id]);
    ponto_audit($pdo, $companyId, 'gerar_endereco', 'coletores', $id, null, ['tipo' => $c['tipo']]);
    return ponto_equip_url($c, $token);
  }
}

if (!function_exists('ponto_equip_por_token')) {
  function ponto_equip_por_token(PDO $pdo, int $companyId, string $token, string $tipo): ?array {
    if (!preg_match('/^[A-Za-z0-9_-]{24,64}$/', $token)) return null;
    $st = $pdo->prepare('SELECT * FROM ponto_coletores WHERE company_id = ? AND token_hash = ? AND ativo = 1 AND tipo = ? LIMIT 1');
    $st->execute([$companyId, hash('sha256', $token), $tipo]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($c && (empty($c['ultimo_acesso']) || strtotime((string)$c['ultimo_acesso']) < time() - 60)) {
      $pdo->prepare('UPDATE ponto_coletores SET ultimo_acesso = ?, ultimo_ip = ? WHERE company_id = ? AND id = ?')->execute([date('Y-m-d H:i:s'), ponto_client_ip() ?: null, $companyId, (int)$c['id']]);
    }
    return $c;
  }
}

if (!function_exists('ponto_equip_aprender')) {
  /** O próximo cartão desconhecido lido neste equipamento (em até 2 minutos) fica para o colaborador. */
  function ponto_equip_aprender(PDO $pdo, int $companyId, int $id, int $colId): void {
    $col = ponto_colaborador_get($pdo, $companyId, $colId);
    if (!$col) throw new RuntimeException('Escolha o colaborador.');
    $st = $pdo->prepare('UPDATE ponto_coletores SET aprender_colaborador_id = ?, aprender_expira = ? WHERE company_id = ? AND id = ? AND ativo = 1');
    $st->execute([$colId, date('Y-m-d H:i:s', time() + 120), $companyId, $id]);
    if (!$st->rowCount()) throw new RuntimeException('Equipamento não encontrado ou desativado.');
    ponto_audit($pdo, $companyId, 'aguardar_cartao', 'coletores', $id, null, ['colaborador' => $col['nome']], $colId);
  }
}

if (!function_exists('ponto_equip_identificar')) {
  /**
   * Quem é. $tipo: 'cartao' (valor lido), 'usuario' (código do colaborador cadastrado no aparelho,
   * para rosto/digital identificados no próprio equipamento), 'senha' (matrícula + PIN).
   * Devolve [colaborador|null, identificacao, cartaoNovo(bool), motivo].
   */
  function ponto_equip_identificar(PDO $pdo, int $companyId, array $eq, string $tipo, string $valor, string $pin = '', string $meio = 'RFID'): array {
    $valor = trim($valor);
    if ($valor === '') return [null, 'RFID', false, 'NAO_IDENT'];
    if ($tipo === 'cartao') {
      $cfg = ponto_config($pdo, $companyId);
      $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND rfid_hash = ? LIMIT 1');
      $st->execute([$companyId, ponto_rfid_hash($companyId, $valor)]);
      $col = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      if ($col) {
        if (empty($cfg['permitir_rfid']) || empty($eq['permitir_rfid'])) return [null, 'RFID', false, 'CARTAO_DESLIGADO'];
        return [$col, 'RFID', false, ''];
      }
      // Cadastro do cartão pelo próprio equipamento.
      if (!empty($eq['aprender_colaborador_id']) && strtotime((string)$eq['aprender_expira']) >= time()) {
        $col = ponto_colaborador_get($pdo, $companyId, (int)$eq['aprender_colaborador_id']);
        if ($col) {
          $pdo->prepare('UPDATE ponto_colaboradores SET rfid_hash = ?, rfid_final = ? WHERE company_id = ? AND id = ?')
            ->execute([ponto_rfid_hash($companyId, $valor), mb_substr(preg_replace('/\s+/', '', $valor) ?? '', -4), $companyId, (int)$col['id']]);
          $pdo->prepare('UPDATE ponto_coletores SET aprender_colaborador_id = NULL, aprender_expira = NULL WHERE company_id = ? AND id = ?')->execute([$companyId, (int)$eq['id']]);
          ponto_audit($pdo, $companyId, 'cadastrar_cartao', 'colaboradores', (int)$col['id'], null, ['pelo_equipamento' => $eq['descricao'], 'final' => mb_substr($valor, -4)], (int)$col['id']);
          return [ponto_colaborador_get($pdo, $companyId, (int)$col['id']), 'RFID', true, ''];
        }
      }
      return [null, 'RFID', false, 'NAO_IDENT'];
    }
    if ($tipo === 'usuario') {
      // Código do colaborador no aparelho = ID do colaborador no Nexa (ou a matrícula numérica).
      $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND (id = ? OR matricula = ?) ORDER BY (id = ?) DESC LIMIT 1');
      $n = ctype_digit($valor) ? (int)$valor : 0;
      $st->execute([$companyId, $n, $valor, $n]);
      $col = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      return [$col, in_array($meio, ['FACIAL', 'BIOMETRIA', 'RFID'], true) ? $meio : 'BIOMETRIA', false, $col ? '' : 'NAO_IDENT'];
    }
    if ($tipo === 'senha') {
      try {
        [$col] = ponto_identificar($pdo, $companyId, ['login' => $valor, 'pin' => $pin], $eq);
        return [$col, 'PIN', false, ''];
      } catch (RuntimeException $e) {
        return [null, 'PIN', false, 'NAO_IDENT'];
      }
    }
    return [null, 'RFID', false, 'NAO_IDENT'];
  }
}

if (!function_exists('ponto_acesso_fisico')) {
  /** Regra da porta/catraca para um colaborador já identificado. */
  function ponto_acesso_fisico(PDO $pdo, int $companyId, array $eq, array $col, ?int $agora = null): array {
    if ((string)($col['status'] ?? 'ATIVO') !== 'ATIVO') return ['ok' => false, 'motivo' => 'AFASTADO', 'mensagem' => 'Cadastro afastado ou desligado'];
    if ((string)$eq['acesso_sentido'] === 'SAIDA') return ['ok' => true, 'motivo' => '', 'mensagem' => ''];
    if ((string)$eq['acesso_regra'] === 'ATIVO') return ['ok' => true, 'motivo' => '', 'mensagem' => ''];
    $r = ponto_acesso_regra_colaborador($pdo, $companyId, $col, ponto_config($pdo, $companyId), $agora, false);
    return ['ok' => $r['ok'], 'motivo' => $r['motivo'], 'mensagem' => $r['ok'] ? '' : (PONTO_ACESSO_MOTIVOS[$r['motivo']] ?? $r['mensagem'])];
  }
}

if (!function_exists('ponto_equip_processar')) {
  /**
   * Leitura identificada (ou não) num equipamento: registra o ponto e/ou decide a porta.
   * Devolve: liberar (bool), ponto (marcação|null), mensagem (curta, para a tela do aparelho),
   * resultado, motivo, colaborador.
   */
  function ponto_equip_processar(PDO $pdo, int $companyId, array $eq, ?array $col, string $ident, string $finalCartao = '', bool $cartaoNovo = false, string $motivoIdent = '', ?string $uuid = null): array {
    $out = ['liberar' => false, 'ponto' => null, 'mensagem' => '', 'resultado' => 'NEGADO', 'motivo' => '', 'colaborador' => $col];
    if (!$col) {
      $out['resultado'] = 'NAO_IDENT';
      $out['motivo'] = $motivoIdent ?: 'NAO_IDENT';
      $out['mensagem'] = $motivoIdent === 'CARTAO_DESLIGADO' ? 'Cartão não habilitado' : 'Não identificado';
      ponto_equip_evento($pdo, $companyId, $eq, null, $ident, $finalCartao, $out);
      return $out;
    }
    $nome = trim((string)$col['nome']);
    $primeiro = explode(' ', $nome)[0] ?: $nome;

    // 1) Ponto
    if ((int)$eq['funcao_ponto'] === 1) {
      $dupla = (int)$eq['dupla_seg'];
      $recente = null;
      if ($dupla > 0) {
        $st = $pdo->prepare('SELECT * FROM ponto_arp_marcacoes WHERE company_id = ? AND colaborador_id = ? AND coletor_id = ? AND created_at >= ? ORDER BY id DESC LIMIT 1');
        $st->execute([$companyId, (int)$col['id'], (int)$eq['id'], date('Y-m-d H:i:s', time() - $dupla)]);
        $recente = $st->fetch(PDO::FETCH_ASSOC) ?: null;
      }
      if ($recente) {
        $out['ponto'] = $recente + ['repetida' => true];
      } else {
        try {
          $out['ponto'] = ponto_marcar($pdo, $companyId, $col, [
            'request_uuid' => $uuid && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/', strtolower($uuid)) ? strtolower($uuid) : ponto_uuid(),
            'identificacao' => $ident, 'coletor' => $eq, 'coletor_codigo' => (string)$eq['identificador_afd'],
          ]);
        } catch (Throwable $e) {
          $out['motivo'] = 'ERRO_PONTO';
          $out['mensagem'] = $e instanceof RuntimeException ? mb_substr($e->getMessage(), 0, 120) : 'Ponto não registrado';
          error_log('[Nexa Ponto equipamento] ' . $e->getMessage());
        }
      }
    }

    // 2) Porta / catraca
    if ((int)$eq['funcao_acesso'] === 1) {
      $a = ponto_acesso_fisico($pdo, $companyId, $eq, $col);
      $out['liberar'] = $a['ok'];
      if (!$a['ok']) { $out['motivo'] = $a['motivo']; $out['mensagem'] = $a['mensagem']; }
    }

    $hora = $out['ponto'] ? substr((string)$out['ponto']['data_hora'], 11, 5) : '';
    if ($cartaoNovo) {
      $out['resultado'] = 'CARTAO_NOVO';
      $out['mensagem'] = 'Cartão cadastrado: ' . $primeiro . ($hora ? ' · ponto ' . $hora : '');
    } elseif ((int)$eq['funcao_acesso'] === 1 && !$out['liberar']) {
      $out['resultado'] = 'NEGADO';
      $out['mensagem'] = $primeiro . ': ' . $out['mensagem'] . ($hora ? ' · ponto ' . $hora : '');
    } elseif ($out['ponto']) {
      $out['resultado'] = (int)$eq['funcao_acesso'] === 1 ? 'LIBERADO' : 'PONTO';
      $out['mensagem'] = $primeiro . ' · ponto ' . $hora . (!empty($out['ponto']['repetida']) ? ' (já registrado)' : '');
    } elseif ((int)$eq['funcao_acesso'] === 1) {
      $out['resultado'] = 'LIBERADO';
      $out['mensagem'] = $primeiro . ($out['mensagem'] !== '' ? ' · ' . $out['mensagem'] : ' · acesso liberado');
    } else {
      $out['resultado'] = 'ERRO';
      $out['mensagem'] = $primeiro . ': ' . ($out['mensagem'] ?: 'ponto não registrado');
    }
    // Só registrar ponto (sem porta): o aparelho mostra "liberado" quando o ponto foi gravado.
    if ((int)$eq['funcao_acesso'] !== 1) $out['liberar'] = $out['ponto'] !== null;
    ponto_equip_evento($pdo, $companyId, $eq, $col, $ident, $finalCartao, $out);
    return $out;
  }
}

if (!function_exists('ponto_equip_evento')) {
  function ponto_equip_evento(PDO $pdo, int $companyId, array $eq, ?array $col, string $ident, string $finalCartao, array $out): void {
    try {
      $estab = !empty($eq['estabelecimento_id']) ? ponto_estabelecimento_get($pdo, $companyId, (int)$eq['estabelecimento_id']) : null;
      $dt = new DateTime('now'); $dt->setTimezone(ponto_tz($estab, $pdo, $companyId));
      $pdo->prepare('INSERT INTO ponto_equip_eventos (company_id, coletor_id, colaborador_id, data_hora, identificacao, final_cartao, resultado, motivo, mensagem, marcacao_id, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, (int)$eq['id'], $col ? (int)$col['id'] : null, $dt->format('Y-m-d H:i:s'), mb_substr($ident, 0, 10), $finalCartao !== '' ? mb_substr($finalCartao, -4) : null,
          $out['resultado'], $out['motivo'] !== '' ? mb_substr($out['motivo'], 0, 30) : null, mb_substr((string)$out['mensagem'], 0, 160),
          !empty($out['ponto']['id']) ? (int)$out['ponto']['id'] : null, ponto_client_ip() ?: null, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
      error_log('[Nexa Ponto equipamento] evento: ' . $e->getMessage());
    }
  }
}

if (!function_exists('ponto_equip_eventos_lista')) {
  function ponto_equip_eventos_lista(PDO $pdo, int $companyId, int $coletorId = 0, int $limite = 100): array {
    $sql = 'SELECT e.*, c.nome, k.descricao AS equipamento FROM ponto_equip_eventos e
      LEFT JOIN ponto_colaboradores c ON c.company_id = e.company_id AND c.id = e.colaborador_id
      LEFT JOIN ponto_coletores k ON k.company_id = e.company_id AND k.id = e.coletor_id
      WHERE e.company_id = ?' . ($coletorId > 0 ? ' AND e.coletor_id = ?' : '') . ' ORDER BY e.id DESC LIMIT ' . max(1, min(500, $limite));
    $st = $pdo->prepare($sql);
    $st->execute($coletorId > 0 ? [$companyId, $coletorId] : [$companyId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

/* ------------------------------------------------------------------------- */
/* Control iD (modo online)                                                   */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_controlid_resposta')) {
  /**
   * Resposta no formato do modo online da Control iD: {"result": {...}}.
   * event 7 = acesso liberado (com as ações de abertura), 6 = acesso negado, 3 = não identificado.
   */
  function ponto_controlid_resposta(array $eq, array $out, string $portalId = ''): array {
    $col = $out['colaborador'] ?? null;
    $res = ['event' => $out['liberar'] ? 7 : ($col ? 6 : 3), 'message' => mb_substr((string)$out['mensagem'], 0, 60)];
    if ($col) { $res['user_id'] = (int)$col['id']; $res['user_name'] = (string)$col['nome']; $res['user_image'] = false; }
    if ($portalId !== '') $res['portal_id'] = ctype_digit($portalId) ? (int)$portalId : $portalId;
    if ($out['liberar'] && (int)$eq['funcao_acesso'] === 1) {
      $acao = (string)$eq['acesso_acao'];
      if ($acao === 'door') $res['actions'] = [['action' => 'door', 'parameters' => 'door=1']];
      elseif ($acao === 'sec_box') $res['actions'] = [['action' => 'sec_box', 'parameters' => 'id=65793, reason=1']];
      elseif ($acao === 'catra') {
        $sent = ['ENTRADA' => 'clockwise', 'SAIDA' => 'anticlockwise', 'AMBOS' => 'both'][(string)$eq['acesso_sentido']] ?? 'clockwise';
        $res['actions'] = [['action' => 'catra', 'parameters' => 'allow=' . $sent]];
      }
    }
    return ['result' => $res];
  }
}

if (!function_exists('ponto_controlid_atender')) {
  /**
   * Atende uma chamada do equipamento Control iD. $acao = nome do .fcgi chamado; $in = POST.
   * Devolve [status HTTP, corpo (array)].
   */
  function ponto_controlid_atender(PDO $pdo, int $companyId, array $eq, string $acao, array $in): array {
    $portal = (string)($in['portal_id'] ?? '');
    $uuid = isset($in['uuid']) ? (string)$in['uuid'] : null;
    switch ($acao) {
      case 'session_is_valid':
      case 'device_is_alive':
        return [200, ['session_is_valid' => true]];
      case 'new_card':
        $card = trim((string)($in['card_value'] ?? ''));
        [$col, $ident, $novo, $mot] = ponto_equip_identificar($pdo, $companyId, $eq, 'cartao', $card);
        $out = ponto_equip_processar($pdo, $companyId, $eq, $col, $ident, $card, $novo, $mot, $uuid);
        return [200, ponto_controlid_resposta($eq, $out, $portal)];
      case 'new_user_identified':
        // Identificado no próprio aparelho (rosto, digital ou cartão cadastrado nele).
        $card = trim((string)($in['card_value'] ?? ''));
        $meio = $card !== '' && $card !== '0' ? 'RFID' : (isset($in['confidence']) || isset($in['face_mask']) ? 'FACIAL' : 'BIOMETRIA');
        [$col, $ident, , $mot] = ponto_equip_identificar($pdo, $companyId, $eq, 'usuario', (string)($in['user_id'] ?? ''), '', $meio);
        $out = ponto_equip_processar($pdo, $companyId, $eq, $col, $ident, $card !== '0' ? $card : '', false, $mot, $uuid);
        return [200, ponto_controlid_resposta($eq, $out, $portal)];
      case 'new_user_id_and_password':
        [$col, $ident, , $mot] = ponto_equip_identificar($pdo, $companyId, $eq, 'senha', (string)($in['user_id'] ?? ''), (string)($in['password'] ?? ''));
        $out = ponto_equip_processar($pdo, $companyId, $eq, $col, $ident, '', false, $mot, $uuid);
        return [200, ponto_controlid_resposta($eq, $out, $portal)];
      default:
        // Outros avisos do aparelho (fotos, modelos de digital, botão de saída...): só confirma.
        return [200, ['result' => ['event' => 3, 'message' => '']]];
    }
  }
}

if (!function_exists('ponto_equip_api_atender')) {
  /**
   * API genérica para outras marcas/integradores. POST (JSON ou formulário):
   *   cartao=... | usuario=<código do colaborador> | matricula=...&pin=...
   * Resposta: {ok, liberar, ponto_registrado, nsr, hora, colaborador, mensagem, resultado}.
   */
  function ponto_equip_api_atender(PDO $pdo, int $companyId, array $eq, string $acao, array $in): array {
    if ($acao === 'status') return [200, ['ok' => true, 'equipamento' => $eq['descricao'], 'agora' => date('c')]];
    if ($acao !== 'identificar') return [404, ['ok' => false, 'mensagem' => 'Ação desconhecida.']];
    if (!empty($in['cartao'])) { $tipo = 'cartao'; $valor = (string)$in['cartao']; }
    elseif (!empty($in['usuario'])) { $tipo = 'usuario'; $valor = (string)$in['usuario']; }
    elseif (!empty($in['matricula'])) { $tipo = 'senha'; $valor = (string)$in['matricula']; }
    else return [400, ['ok' => false, 'mensagem' => 'Envie cartao, usuario ou matricula+pin.']];
    $meio = strtoupper((string)($in['meio'] ?? ''));
    [$col, $ident, $novo, $mot] = ponto_equip_identificar($pdo, $companyId, $eq, $tipo, $valor, (string)($in['pin'] ?? ''), in_array($meio, ['FACIAL', 'BIOMETRIA', 'RFID'], true) ? $meio : 'BIOMETRIA');
    $out = ponto_equip_processar($pdo, $companyId, $eq, $col, $ident, $tipo === 'cartao' ? $valor : '', $novo, $mot, isset($in['uuid']) ? (string)$in['uuid'] : null);
    return [200, [
      'ok' => true, 'liberar' => $out['liberar'], 'resultado' => $out['resultado'], 'mensagem' => $out['mensagem'],
      'ponto_registrado' => $out['ponto'] !== null, 'nsr' => $out['ponto']['nsr'] ?? null, 'hora' => $out['ponto'] ? substr((string)$out['ponto']['data_hora'], 11, 5) : null,
      'colaborador' => $col ? ['codigo' => (int)$col['id'], 'nome' => (string)$col['nome']] : null,
    ]];
  }
}
