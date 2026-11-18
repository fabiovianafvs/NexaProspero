<?php
declare(strict_types=1);

/**
 * Transporte — V2: emissão do CT-e rodoviário (modelo 57, leiaute 4.00 com a Reforma Tributária).
 *
 * Tabelas cte_documentos / cte_transmissoes (db/20261118_cte_emissao_v2.sql). O CT-e nasce como
 * RASCUNHO (sem número). Na primeira transmissão recebe o número da série (transp_numeracao_reservar)
 * e um código numérico (cCT) que não mudam mais: se a SEFAZ rejeitar, a empresa corrige e transmite
 * de novo com o mesmo número. Situações: RASCUNHO, PROCESSANDO, PENDENTE (resultado incerto: usar
 * "Consultar situação"), REJEITADO, AUTORIZADO, DENEGADO (V3: CANCELADO).
 *
 * XML montado com a sped-cte (MakeCTe, esquema PL_CTe_400_RTC), validado pelo XSD no próprio Nexa
 * (a biblioteca procura os esquemas numa pasta antiga e pula a validação), assinado e enviado de forma
 * síncrona (CteRecepcaoSincV4). DACTE pela sped-da.
 *
 * IBS/CBS: só quando a configuração do CT-e tiver CST + cClassTrib (obrigatório para o Regime Normal).
 * Alíquotas pelas tabelas oficiais do Nexa (fiscal_ibs_cbs_resolve; 2026 = 0,9% CBS e 0,1% IBS UF),
 * local = município de fim da prestação, base = valor da prestação menos o ICMS (ou base informada).
 * vTotDFe = vTPrest (2026: IBS/CBS não somam; a partir de 2027 já estão dentro do vTPrest).
 */

require_once __DIR__ . '/transporte_module.php';

const CTE_EDITAVEIS = ['RASCUNHO', 'REJEITADO'];

if (!function_exists('cte_ready')) {
  function cte_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('cte_documentos','cte_transmissoes')");
      return $ready = ((int)$st->fetchColumn() === 2);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Tabelas do leiaute                                                         */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_unidades')) {
  function cte_unidades(): array { return ['01' => 'KG', '02' => 'TON', '00' => 'M3', '03' => 'UNIDADE', '04' => 'LITROS', '05' => 'MMBTU']; }
  function cte_tomadores(): array { return ['0' => 'Remetente', '1' => 'Expedidor', '2' => 'Recebedor', '3' => 'Destinatário', '4' => 'Outro (terceiro)']; }
  function cte_tipos_servico(): array { return ['0' => 'Normal', '1' => 'Subcontratação', '2' => 'Redespacho', '3' => 'Redespacho intermediário']; }
  function cte_papeis(): array { return ['remetente' => 'Remetente', 'destinatario' => 'Destinatário', 'expedidor' => 'Expedidor', 'recebedor' => 'Recebedor', 'tomador' => 'Tomador (outro)']; }
  function cte_outros_tipos(): array { return ['00' => 'Declaração', '10' => 'Dutoviário', '59' => 'CF-e SAT', '65' => 'NFC-e', '99' => 'Outros']; }
  function cte_status_labels(): array {
    return ['RASCUNHO' => ['Rascunho', 'n'], 'PROCESSANDO' => ['Transmitindo', 'y'], 'PENDENTE' => ['Aguardando consulta', 'y'],
      'REJEITADO' => ['Rejeitado', 'r'], 'AUTORIZADO' => ['Autorizado', 'g'], 'DENEGADO' => ['Denegado', 'r'], 'CANCELADO' => ['Cancelado', 'n']];
  }
}

if (!function_exists('cte_chave_valida')) {
  function cte_chave_valida(string $chave): bool {
    if (!preg_match('/^\d{44}$/', $chave)) return false;
    $soma = 0; $peso = 2;
    for ($i = 42; $i >= 0; $i--) { $soma += (int)$chave[$i] * $peso; $peso = $peso === 9 ? 2 : $peso + 1; }
    $r = $soma % 11;
    return (int)$chave[43] === ($r < 2 ? 0 : 11 - $r);
  }
}

if (!function_exists('cte_money')) {
  /** "1.234,56" / "1234.56" → 1234.56 */
  function cte_money($v, int $dec = 2): float {
    $s = trim((string)$v);
    if ($s === '') return 0.0;
    $s = str_replace(['R$', ' '], '', $s);
    if (strpos($s, ',') !== false) $s = str_replace(['.', ','], ['', '.'], $s);
    return is_numeric($s) ? round((float)$s, $dec) : 0.0;
  }
}

/* ------------------------------------------------------------------------- */
/* Dados do formulário                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_participante_limpo')) {
  function cte_participante_limpo($p): array {
    $p = is_array($p) ? $p : [];
    $t = static fn(string $k, int $max) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($p[$k] ?? '')) ?? ''), 0, $max);
    $doc = transp_digits($p['documento'] ?? '');
    $ie = strtoupper(trim((string)($p['ie'] ?? '')));
    $ie = $ie === 'ISENTO' ? 'ISENTO' : transp_digits($ie);
    return [
      'pessoa_id' => (int)($p['pessoa_id'] ?? 0) ?: null,
      'documento' => $doc, 'ie' => $ie, 'nome' => $t('nome', 60), 'fantasia' => $t('fantasia', 60),
      'fone' => substr(transp_digits($p['fone'] ?? ''), 0, 14), 'email' => $t('email', 60),
      'logradouro' => $t('logradouro', 255), 'numero' => $t('numero', 60), 'complemento' => $t('complemento', 60), 'bairro' => $t('bairro', 60),
      'cep' => substr(transp_digits($p['cep'] ?? ''), 0, 8), 'cidade_ibge' => substr(transp_digits($p['cidade_ibge'] ?? ''), 0, 7),
      'cidade' => $t('cidade', 60), 'uf' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($p['uf'] ?? '')) ?? '', 0, 2)),
    ];
  }
}

if (!function_exists('cte_participante_preenchido')) {
  function cte_participante_preenchido(array $p): bool { return ($p['documento'] ?? '') !== '' || ($p['nome'] ?? '') !== ''; }
}

if (!function_exists('cte_dados_padrao')) {
  function cte_dados_padrao(array $cfg): array {
    $vazio = cte_participante_limpo([]);
    return [
      'tp_serv' => '0', 'cfop' => (string)$cfg['cte_cfop_interestadual'], 'natureza' => (string)$cfg['cte_natureza'], 'tomador' => '0',
      'participantes' => ['remetente' => $vazio, 'destinatario' => $vazio, 'expedidor' => $vazio, 'recebedor' => $vazio, 'tomador' => $vazio],
      'percurso' => ['uf_ini' => '', 'mun_ini_ibge' => '', 'mun_ini_nome' => '', 'uf_fim' => '', 'mun_fim_ibge' => '', 'mun_fim_nome' => ''],
      'valores' => ['prestacao' => 0.0, 'receber' => 0.0, 'componentes' => [['nome' => 'FRETE VALOR', 'valor' => 0.0]]],
      'icms' => ['cst' => (string)$cfg['cte_icms_cst'], 'aliquota' => (float)$cfg['cte_icms_aliquota'], 'reducao' => (float)$cfg['cte_icms_reducao']],
      'ibscbs' => ['base_manual' => null],
      'carga' => ['valor' => 0.0, 'produto' => '', 'outras' => '', 'quantidades' => [['unidade' => '01', 'tipo' => 'PESO BRUTO', 'quantidade' => 0.0]]],
      'documentos' => ['nfe' => [], 'outros' => []],
      'observacao' => '', 'retira' => '1', 'det_retira' => '',
    ];
  }
}

if (!function_exists('cte_dados_do_formulario')) {
  /** Monta a estrutura do CT-e a partir do POST (validação leve; a completa é na transmissão). */
  function cte_dados_do_formulario(array $in, array $cfg): array {
    $d = cte_dados_padrao($cfg);
    $d['tp_serv'] = isset(cte_tipos_servico()[(string)($in['tp_serv'] ?? '0')]) ? (string)$in['tp_serv'] : '0';
    $cfop = transp_digits($in['cfop'] ?? '');
    if ($cfop !== '' && !isset(transp_cfops_cte()[$cfop])) throw new RuntimeException('CFOP inválido para CT-e.');
    $d['cfop'] = $cfop !== '' ? $cfop : $d['cfop'];
    $nat = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['natureza'] ?? '')) ?? ''), 'UTF-8');
    $d['natureza'] = $nat !== '' ? mb_substr($nat, 0, 60) : $d['natureza'];
    $d['tomador'] = isset(cte_tomadores()[(string)($in['tomador'] ?? '0')]) ? (string)$in['tomador'] : '0';
    foreach (array_keys(cte_papeis()) as $papel) $d['participantes'][$papel] = cte_participante_limpo($in['part'][$papel] ?? []);
    $pc = (array)($in['percurso'] ?? []);
    foreach (['ini', 'fim'] as $k) {
      $d['percurso']['uf_' . $k] = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($pc['uf_' . $k] ?? '')) ?? '', 0, 2));
      $d['percurso']['mun_' . $k . '_ibge'] = substr(transp_digits($pc['mun_' . $k . '_ibge'] ?? ''), 0, 7);
      $d['percurso']['mun_' . $k . '_nome'] = mb_substr(trim((string)($pc['mun_' . $k . '_nome'] ?? '')), 0, 60);
    }
    $comps = [];
    foreach ((array)($in['comp_nome'] ?? []) as $i => $nome) {
      $nome = mb_strtoupper(mb_substr(trim((string)$nome), 0, 15), 'UTF-8');
      $valor = cte_money($in['comp_valor'][$i] ?? 0);
      if ($nome === '' && $valor == 0.0) continue;
      if ($nome === '') throw new RuntimeException('Informe o nome de cada componente do frete.');
      if ($valor < 0) throw new RuntimeException('Valor de componente não pode ser negativo.');
      $comps[] = ['nome' => $nome, 'valor' => $valor];
    }
    $prest = cte_money($in['valor_prestacao'] ?? 0);
    $rec = trim((string)($in['valor_receber'] ?? '')) === '' ? $prest : cte_money($in['valor_receber']);
    if ($prest < 0 || $rec < 0) throw new RuntimeException('Valores do frete não podem ser negativos.');
    $d['valores'] = ['prestacao' => $prest, 'receber' => $rec, 'componentes' => $comps];
    $icms = (array)($in['icms'] ?? []);
    $cst = (string)($icms['cst'] ?? $d['icms']['cst']);
    if (!isset(transp_icms_situacoes_cte()[$cst])) throw new RuntimeException('Situação do ICMS inválida.');
    $aliq = cte_money($icms['aliquota'] ?? 0); $red = cte_money($icms['reducao'] ?? 0);
    if ($aliq < 0 || $aliq > 100 || $red < 0 || $red > 100) throw new RuntimeException('Alíquota e redução do ICMS: de 0 a 100%.');
    $d['icms'] = ['cst' => $cst, 'aliquota' => $aliq, 'reducao' => $red];
    $bm = trim((string)($in['ibscbs_base'] ?? ''));
    $d['ibscbs'] = ['base_manual' => $bm === '' ? null : max(0.0, cte_money($bm))];
    $qs = [];
    foreach ((array)($in['q_unidade'] ?? []) as $i => $u) {
      $u = (string)$u;
      $q = cte_money($in['q_quantidade'][$i] ?? 0, 4);
      $tipo = mb_strtoupper(mb_substr(trim((string)($in['q_tipo'][$i] ?? '')), 0, 20), 'UTF-8');
      if ($q == 0.0 && $tipo === '') continue;
      if (!isset(cte_unidades()[$u])) throw new RuntimeException('Unidade de medida da carga inválida.');
      $qs[] = ['unidade' => $u, 'tipo' => $tipo !== '' ? $tipo : 'PESO BRUTO', 'quantidade' => $q];
    }
    $d['carga'] = [
      'valor' => cte_money($in['carga_valor'] ?? 0),
      'produto' => mb_strtoupper(mb_substr(trim((string)($in['carga_produto'] ?? '')), 0, 60), 'UTF-8'),
      'outras' => mb_substr(trim((string)($in['carga_outras'] ?? '')), 0, 30),
      'quantidades' => $qs,
    ];
    $nfes = []; $vistas = [];
    foreach ((array)($in['nfe_chave'] ?? []) as $i => $ch) {
      $ch = transp_digits($ch);
      if ($ch === '') continue;
      if (!cte_chave_valida($ch) || substr($ch, 20, 2) !== '55') throw new RuntimeException('Chave de NF-e inválida: ' . $ch);
      if (isset($vistas[$ch])) continue;
      $vistas[$ch] = true;
      $prev = trim((string)($in['nfe_prev'][$i] ?? ''));
      $nfes[] = ['chave' => $ch, 'dprev' => $prev !== '' ? transp_date_in($prev, 'Previsão de entrega') : ''];
    }
    $outros = [];
    foreach ((array)($in['out_tipo'] ?? []) as $i => $tp) {
      $tp = (string)$tp;
      $num = mb_substr(trim((string)($in['out_numero'][$i] ?? '')), 0, 20);
      $desc = mb_substr(trim((string)($in['out_desc'][$i] ?? '')), 0, 100);
      $val = cte_money($in['out_valor'][$i] ?? 0);
      if ($num === '' && $desc === '' && $val == 0.0) continue;
      if (!isset(cte_outros_tipos()[$tp])) throw new RuntimeException('Tipo de documento inválido.');
      $dt = trim((string)($in['out_data'][$i] ?? ''));
      $outros[] = ['tipo' => $tp, 'numero' => $num, 'descricao' => $desc, 'valor' => $val, 'data' => $dt !== '' ? transp_date_in($dt, 'Data do documento') : ''];
    }
    $d['documentos'] = ['nfe' => $nfes, 'outros' => $outros];
    $d['observacao'] = mb_substr(trim((string)($in['observacao'] ?? '')), 0, 2000);
    $d['retira'] = (string)($in['retira'] ?? '1') === '0' ? '0' : '1';
    $d['det_retira'] = mb_substr(trim((string)($in['det_retira'] ?? '')), 0, 160);
    return $d;
  }
}

if (!function_exists('cte_dados_normalizar')) {
  /** Dados salvos + padrão (listas substituem, não somam ao padrão). */
  function cte_dados_normalizar(array $salvo, array $cfg): array {
    $d = cte_dados_padrao($cfg);
    foreach (['tp_serv', 'cfop', 'natureza', 'tomador', 'observacao', 'retira', 'det_retira'] as $k) if (isset($salvo[$k])) $d[$k] = (string)$salvo[$k];
    foreach (array_keys(cte_papeis()) as $papel) $d['participantes'][$papel] = cte_participante_limpo($salvo['participantes'][$papel] ?? []);
    $d['percurso'] = array_merge($d['percurso'], array_intersect_key((array)($salvo['percurso'] ?? []), $d['percurso']));
    if (isset($salvo['valores'])) $d['valores'] = ['prestacao' => (float)($salvo['valores']['prestacao'] ?? 0), 'receber' => (float)($salvo['valores']['receber'] ?? 0), 'componentes' => array_values((array)($salvo['valores']['componentes'] ?? []))];
    if (isset($salvo['icms'])) $d['icms'] = array_merge($d['icms'], (array)$salvo['icms']);
    if (isset($salvo['ibscbs'])) $d['ibscbs'] = ['base_manual' => $salvo['ibscbs']['base_manual'] ?? null];
    if (isset($salvo['carga'])) $d['carga'] = ['valor' => (float)($salvo['carga']['valor'] ?? 0), 'produto' => (string)($salvo['carga']['produto'] ?? ''), 'outras' => (string)($salvo['carga']['outras'] ?? ''), 'quantidades' => array_values((array)($salvo['carga']['quantidades'] ?? []))];
    if (isset($salvo['documentos'])) $d['documentos'] = ['nfe' => array_values((array)($salvo['documentos']['nfe'] ?? [])), 'outros' => array_values((array)($salvo['documentos']['outros'] ?? []))];
    return $d;
  }
}

if (!function_exists('cte_tomador_dados')) {
  /** Participante que é o tomador do serviço (quem paga o frete). */
  function cte_tomador_dados(array $d): array {
    $map = ['0' => 'remetente', '1' => 'expedidor', '2' => 'recebedor', '3' => 'destinatario', '4' => 'tomador'];
    return $d['participantes'][$map[(string)$d['tomador']] ?? 'remetente'] ?? cte_participante_limpo([]);
  }
}

/* ------------------------------------------------------------------------- */
/* Banco                                                                      */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_get')) {
  function cte_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM cte_documentos WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['dados'] = json_decode((string)$r['dados_json'], true) ?: [];
    return $r;
  }
}

if (!function_exists('cte_list')) {
  function cte_list(PDO $pdo, int $companyId, array $f): array {
    $w = ['company_id = ?']; $p = [$companyId];
    $ini = (string)($f['ini'] ?? ''); $fim = (string)($f['fim'] ?? '');
    if ($ini !== '') { $w[] = 'COALESCE(dh_emissao, created_at) >= ?'; $p[] = $ini . ' 00:00:00'; }
    if ($fim !== '') { $w[] = 'COALESCE(dh_emissao, created_at) < ?'; $p[] = date('Y-m-d', strtotime($fim . ' +1 day')) . ' 00:00:00'; }
    $status = (string)($f['status'] ?? '');
    if ($status !== '' && isset(cte_status_labels()[$status])) { $w[] = 'status = ?'; $p[] = $status; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
      $dig = transp_digits($q);
      $parts = ['tomador_nome LIKE ?', 'remetente_nome LIKE ?', 'destinatario_nome LIKE ?'];
      array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
      if ($dig !== '') {
        $parts[] = 'chave LIKE ?'; $p[] = '%' . $dig . '%';
        $parts[] = 'tomador_documento LIKE ?'; $p[] = '%' . $dig . '%';
        if (strlen($dig) <= 9) { $parts[] = 'numero = ?'; $p[] = (int)$dig; }
      }
      $w[] = '(' . implode(' OR ', $parts) . ')';
    }
    $st = $pdo->prepare('SELECT id, serie, numero, chave, ambiente, status, cfop, dh_emissao, created_at, uf_ini, mun_ini_nome, uf_fim, mun_fim_nome, tomador_documento, tomador_nome, remetente_nome, destinatario_nome, valor_prestacao, cstat, xmotivo, protocolo, updated_at FROM cte_documentos WHERE ' . implode(' AND ', $w) . ' ORDER BY COALESCE(dh_emissao, created_at) DESC, id DESC LIMIT 300');
    $st->execute($p);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('cte_resumo_colunas')) {
  function cte_resumo_colunas(array $d): array {
    $toma = cte_tomador_dados($d);
    return [
      'tp_serv' => $d['tp_serv'], 'cfop' => $d['cfop'], 'natureza' => $d['natureza'], 'tomador' => $d['tomador'],
      'uf_ini' => $d['percurso']['uf_ini'] ?: null, 'mun_ini_ibge' => $d['percurso']['mun_ini_ibge'] ?: null, 'mun_ini_nome' => $d['percurso']['mun_ini_nome'] ?: null,
      'uf_fim' => $d['percurso']['uf_fim'] ?: null, 'mun_fim_ibge' => $d['percurso']['mun_fim_ibge'] ?: null, 'mun_fim_nome' => $d['percurso']['mun_fim_nome'] ?: null,
      'tomador_documento' => $toma['documento'] ?: null, 'tomador_nome' => $toma['nome'] ?: null, 'tomador_pessoa_id' => $toma['pessoa_id'],
      'remetente_nome' => $d['participantes']['remetente']['nome'] ?: null, 'destinatario_nome' => $d['participantes']['destinatario']['nome'] ?: null,
      'valor_prestacao' => round((float)$d['valores']['prestacao'], 2), 'valor_receber' => round((float)$d['valores']['receber'], 2),
      'valor_carga' => round((float)$d['carga']['valor'], 2),
    ];
  }
}

if (!function_exists('cte_salvar')) {
  /** Cria ou altera o CT-e (só rascunho ou rejeitado). Devolve o id. */
  function cte_salvar(PDO $pdo, int $companyId, int $id, array $in, ?int $userId): int {
    $cfg = transp_config($pdo, $companyId);
    $d = cte_dados_do_formulario($in, $cfg);
    $cols = cte_resumo_colunas($d);
    $cols['dados_json'] = json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($id > 0) {
      $old = cte_get($pdo, $companyId, $id);
      if (!$old) throw new RuntimeException('CT-e não encontrado.');
      if (!in_array($old['status'], CTE_EDITAVEIS, true)) throw new RuntimeException('Este CT-e não pode mais ser alterado (situação: ' . (cte_status_labels()[$old['status']][0] ?? $old['status']) . ').');
      $cols['updated_by'] = $userId;
      $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($cols)));
      $st = $pdo->prepare('UPDATE cte_documentos SET ' . $sets . " WHERE company_id = ? AND id = ? AND status IN ('RASCUNHO','REJEITADO')");
      $st->execute(array_merge(array_values($cols), [$companyId, $id]));
      transp_audit($pdo, $companyId, 'alterar', 'cte', $id, null, ['valor' => $cols['valor_prestacao'], 'tomador' => $cols['tomador_nome']]);
      return $id;
    }
    $cols['company_id'] = $companyId;
    $cols['serie'] = (int)$cfg['cte_serie'];
    $cols['ambiente'] = transp_ambiente_efetivo($cfg, 'cte');
    $cols['status'] = 'RASCUNHO';
    $cols['created_by'] = $userId;
    $names = array_keys($cols);
    $pdo->prepare('INSERT INTO cte_documentos (' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $names)) . ') VALUES (?' . str_repeat(', ?', count($names) - 1) . ')')
      ->execute(array_values($cols));
    $newId = (int)$pdo->lastInsertId();
    transp_audit($pdo, $companyId, 'criar', 'cte', $newId, null, ['valor' => $cols['valor_prestacao'], 'tomador' => $cols['tomador_nome']]);
    return $newId;
  }
}

if (!function_exists('cte_excluir')) {
  /** Só apaga rascunho que nunca recebeu número (número usado não pode sumir: precisa ser inutilizado). */
  function cte_excluir(PDO $pdo, int $companyId, int $id): void {
    $r = cte_get($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('CT-e não encontrado.');
    if ($r['status'] !== 'RASCUNHO' || $r['numero'] !== null) throw new RuntimeException('Só é possível excluir rascunho que ainda não foi transmitido.');
    $pdo->prepare("DELETE FROM cte_documentos WHERE company_id = ? AND id = ? AND status = 'RASCUNHO' AND numero IS NULL")->execute([$companyId, $id]);
    transp_audit($pdo, $companyId, 'excluir', 'cte', $id, ['tomador' => $r['tomador_nome'], 'valor' => $r['valor_prestacao']], null);
  }
}

if (!function_exists('cte_duplicar')) {
  /** Novo rascunho com os mesmos dados (sem número, chave nem protocolo). */
  function cte_duplicar(PDO $pdo, int $companyId, int $id, ?int $userId): int {
    $r = cte_get($pdo, $companyId, $id);
    if (!$r) throw new RuntimeException('CT-e não encontrado.');
    $cfg = transp_config($pdo, $companyId);
    $d = cte_dados_normalizar($r['dados'], $cfg);
    $cols = cte_resumo_colunas($d);
    $cols += ['dados_json' => json_encode($d, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'company_id' => $companyId, 'serie' => (int)$cfg['cte_serie'],
      'ambiente' => transp_ambiente_efetivo($cfg, 'cte'), 'status' => 'RASCUNHO', 'created_by' => $userId];
    $names = array_keys($cols);
    $pdo->prepare('INSERT INTO cte_documentos (' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $names)) . ') VALUES (?' . str_repeat(', ?', count($names) - 1) . ')')
      ->execute(array_values($cols));
    $newId = (int)$pdo->lastInsertId();
    transp_audit($pdo, $companyId, 'duplicar', 'cte', $newId, null, ['origem' => $id]);
    return $newId;
  }
}

if (!function_exists('transp_numeracao_maior_emitido')) {
  /** Maior número já usado na série (para não deixar a numeração voltar). */
  function transp_numeracao_maior_emitido(PDO $pdo, int $companyId, string $modelo, int $serie): int {
    if ($modelo !== TRANSP_MODELO_CTE || !cte_ready($pdo)) return 0;
    $st = $pdo->prepare('SELECT MAX(numero) FROM cte_documentos WHERE company_id = ? AND serie = ? AND numero IS NOT NULL');
    $st->execute([$companyId, $serie]);
    return (int)$st->fetchColumn();
  }
}

if (!function_exists('cte_log')) {
  function cte_log(PDO $pdo, int $companyId, int $cteId, string $tipo, string $cStat, string $xMotivo, ?string $envio, ?string $retorno, ?int $userId): void {
    try {
      $pdo->prepare('INSERT INTO cte_transmissoes (company_id, cte_id, tipo, cstat, xmotivo, xml_envio, xml_retorno, user_id, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$companyId, $cteId, $tipo, mb_substr($cStat, 0, 4), mb_substr($xMotivo, 0, 1000), $envio, $retorno, $userId, date('Y-m-d H:i:s')]);
    } catch (Throwable $e) {
      error_log('[NEXA CTE] log: ' . $e->getMessage());
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Validação e impostos                                                       */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_regime_simples')) {
  function cte_regime_simples(string $crt): bool { return in_array($crt, ['1', '4'], true); }
}

if (!function_exists('cte_icms_calc')) {
  function cte_icms_calc(string $cst, float $valor, float $aliq, float $red): array {
    $base = 0.0; $vIcms = 0.0;
    if (in_array($cst, ['00', '20', '60', '90', '90OUTRAUF'], true)) {
      $base = $cst === '00' || $cst === '60' ? $valor : round($valor * (1 - $red / 100), 2);
      $vIcms = round($base * $aliq / 100, 2);
    }
    if (in_array($cst, ['40', '41', '51', 'SN'], true)) { $aliq = 0.0; $red = 0.0; }
    return ['cst' => $cst, 'base' => round($base, 2), 'aliquota' => $aliq, 'reducao' => $red, 'valor' => $vIcms];
  }
}

if (!function_exists('cte_impostos')) {
  /** ICMS e IBS/CBS do CT-e. ICMS "SN" para Simples Nacional/MEI. */
  function cte_impostos(PDO $pdo, int $companyId, array $d, array $cfg, string $dataEmissao): array {
    $em = transp_emitente($pdo, $companyId);
    $valor = (float)$d['valores']['prestacao'];
    $cst = cte_regime_simples($em['crt']) ? 'SN' : (string)$d['icms']['cst'];
    $icms = cte_icms_calc($cst, $valor, (float)$d['icms']['aliquota'], (float)$d['icms']['reducao']);
    $out = ['crt' => $em['crt'], 'icms' => $icms, 'ibscbs' => null, 'erro_ibscbs' => ''];
    $cClass = (string)$cfg['cte_ibscbs_cclasstrib'];
    if ($cClass === '') return $out;
    require_once __DIR__ . '/fiscal_module.php';
    $fiscalCfg = fiscal_company_config($pdo, $companyId);
    $res = fiscal_ibs_cbs_resolve($pdo, $companyId, ['ibs_cbs_classification_code' => $cClass, 'ibs_cbs_rate_mode' => 'AUTO'], $fiscalCfg, '57', $dataEmissao,
      (string)$d['percurso']['uf_fim'], (string)$d['percurso']['mun_fim_ibge']);
    if (empty($res['enabled'])) { $out['erro_ibscbs'] = 'IBS/CBS: ' . trim((string)($res['reason'] ?? 'configuração incompleta.')); return $out; }
    $cfgCst = (string)$cfg['cte_ibscbs_cst'];
    if ($cfgCst !== '' && $cfgCst !== (string)$res['cst']) { $out['erro_ibscbs'] = 'IBS/CBS: o CST ' . $cfgCst . ' configurado não corresponde ao cClassTrib ' . $cClass . ' (CST ' . $res['cst'] . ').'; return $out; }
    $bm = $d['ibscbs']['base_manual'] ?? null;
    $calc = $bm !== null ? fiscal_ibs_cbs_calculate((float)$bm, $res, []) : fiscal_ibs_cbs_calculate($valor, $res, ['icms' => $icms['valor']]);
    $out['ibscbs'] = ['cst' => (string)$res['cst'], 'cclass' => $cClass] + $calc;
    return $out;
  }
}

if (!function_exists('cte_validar')) {
  /** Tudo que falta para transmitir. Lista vazia = pode transmitir. */
  function cte_validar(PDO $pdo, int $companyId, array $d, array $cfg): array {
    $e = transp_pendencias($pdo, $companyId, $cfg, 'cte');
    $em = transp_emitente($pdo, $companyId);
    if ($em['crt'] === '') $e[] = 'Regime tributário (CRT) da empresa nas Configurações fiscais.';
    $papeis = cte_papeis();
    $exigidos = ['remetente' => true, 'destinatario' => true];
    $toma = (string)$d['tomador'];
    if ($toma === '1') $exigidos['expedidor'] = true;
    if ($toma === '2') $exigidos['recebedor'] = true;
    if ($toma === '4') $exigidos['tomador'] = true;
    foreach ($papeis as $papel => $label) {
      $p = $d['participantes'][$papel] ?? cte_participante_limpo([]);
      if (empty($exigidos[$papel]) && !cte_participante_preenchido($p)) continue;
      $doc = $p['documento'];
      if (!(strlen($doc) === 14 ? transp_cnpj_valid($doc) : (strlen($doc) === 11 && transp_cpf_valid($doc)))) $e[] = $label . ': CPF/CNPJ inválido.';
      if ($p['nome'] === '') $e[] = $label . ': informe o nome.';
      if ($p['logradouro'] === '' || $p['numero'] === '' || $p['bairro'] === '') $e[] = $label . ': endereço incompleto (rua, número e bairro).';
      if (strlen($p['cidade_ibge']) !== 7 || $p['cidade'] === '' || $p['uf'] === '') $e[] = $label . ': cidade com código IBGE e UF.';
      if ($p['fone'] !== '' && (strlen($p['fone']) < 6 || strlen($p['fone']) > 14)) $e[] = $label . ': telefone inválido.';
      if ($p['email'] !== '' && !filter_var($p['email'], FILTER_VALIDATE_EMAIL)) $e[] = $label . ': e-mail inválido.';
    }
    $pc = $d['percurso'];
    foreach (['ini' => 'Início', 'fim' => 'Fim'] as $k => $lb) {
      if ($pc['uf_' . $k] === '' || strlen($pc['mun_' . $k . '_ibge']) !== 7 || $pc['mun_' . $k . '_nome'] === '') $e[] = $lb . ' da prestação: informe a cidade (com código IBGE) e a UF.';
    }
    $cfop = (string)$d['cfop'];
    if (!isset(transp_cfops_cte()[$cfop])) $e[] = 'CFOP inválido.';
    elseif ($pc['uf_ini'] !== '' && $pc['uf_fim'] !== '' && $cfop[0] !== '7') {
      $esperado = $pc['uf_ini'] === $pc['uf_fim'] ? '5' : '6';
      if ($cfop[0] !== $esperado) $e[] = 'CFOP ' . $cfop . ': a prestação é ' . ($esperado === '5' ? 'dentro do estado (use CFOP 5xxx)' : 'entre estados (use CFOP 6xxx)') . '.';
    }
    $v = $d['valores'];
    if ((float)$v['prestacao'] <= 0) $e[] = 'Informe o valor da prestação (frete).';
    if ((float)$v['receber'] > (float)$v['prestacao']) $e[] = 'O valor a receber não pode ser maior que o valor da prestação.';
    if (!$v['componentes']) $e[] = 'Informe ao menos um componente do frete (ex.: FRETE VALOR).';
    $somaComp = array_sum(array_map(static fn($c) => (float)$c['valor'], $v['componentes']));
    if ($v['componentes'] && abs($somaComp - (float)$v['prestacao']) > 0.009) $e[] = 'A soma dos componentes (R$ ' . number_format($somaComp, 2, ',', '.') . ') deve ser igual ao valor da prestação.';
    if ($d['carga']['produto'] === '') $e[] = 'Informe o produto predominante da carga.';
    if (!$d['carga']['quantidades']) $e[] = 'Informe ao menos uma quantidade da carga (ex.: peso bruto em KG).';
    if (!$d['documentos']['nfe'] && !$d['documentos']['outros']) $e[] = 'Informe as notas fiscais transportadas (chave da NF-e) ou outro documento.';
    $imp = cte_impostos($pdo, $companyId, $d, $cfg, date('Y-m-d'));
    if ($imp['erro_ibscbs'] !== '') $e[] = $imp['erro_ibscbs'];
    $ic = $imp['icms'];
    if (in_array($ic['cst'], ['00', '20', '90', '90OUTRAUF'], true) && $ic['aliquota'] <= 0) $e[] = 'ICMS ' . $ic['cst'] . ': informe a alíquota.';
    if ($ic['cst'] === '20' && $ic['reducao'] <= 0) $e[] = 'ICMS 20: informe o percentual de redução da base.';
    return array_values(array_unique($e));
  }
}

/* ------------------------------------------------------------------------- */
/* XML                                                                        */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_fuso_uf')) {
  function cte_fuso_uf(string $uf): string {
    $m = ['AC' => 'America/Rio_Branco', 'AM' => 'America/Manaus', 'RR' => 'America/Boa_Vista', 'RO' => 'America/Porto_Velho', 'MT' => 'America/Cuiaba', 'MS' => 'America/Campo_Grande'];
    return $m[$uf] ?? 'America/Sao_Paulo';
  }
}

if (!function_exists('cte_resp_tecnico')) {
  /**
   * Responsável técnico (desenvolvedor do sistema), igual para todas as empresas. Lido de
   * /etc/nexa/resp_tecnico.env (deploy/fiscal/configurar_responsavel_tecnico.sh) e, no que faltar,
   * dos dados do desenvolvedor do Nexa Ponto (/etc/nexa/ponto.env). Sem CNPJ/contato/e-mail/telefone = não informa.
   */
  function cte_resp_tecnico(): ?array {
    if (is_file(__DIR__ . '/nexa_env.php')) {
      require_once __DIR__ . '/nexa_env.php';
      if (function_exists('nexa_load_environment_file')) {
        foreach (['/etc/nexa/resp_tecnico.env', '/etc/nexa/ponto.env'] as $f) if (is_readable($f)) nexa_load_environment_file($f);
      }
    }
    $g = static fn(string $k): string => trim((string)(getenv($k) ?: ''));
    $r = [
      'CNPJ' => transp_digits($g('NEXA_RESP_TEC_CNPJ') ?: $g('NEXA_PONTO_DEV_CNPJ')),
      'xContato' => $g('NEXA_RESP_TEC_CONTATO') ?: $g('NEXA_PONTO_DEV_NOME'),
      'email' => $g('NEXA_RESP_TEC_EMAIL') ?: $g('NEXA_PONTO_DEV_EMAIL'),
      'fone' => transp_digits($g('NEXA_RESP_TEC_FONE')),
    ];
    if (strlen($r['CNPJ']) !== 14 || $r['xContato'] === '' || $r['email'] === '' || strlen($r['fone']) < 7) return null;
    return $r;
  }
}

if (!function_exists('cte_montar_xml')) {
  /** Monta o XML (sem assinatura). Devolve [xml, chave]. */
  function cte_montar_xml(PDO $pdo, int $companyId, array $d, array $cfg, int $serie, int $numero, string $cCT, string $dhEmi, int $tpAmb): array {
    $em = transp_emitente($pdo, $companyId);
    $imp = cte_impostos($pdo, $companyId, $d, $cfg, substr($dhEmi, 0, 10));
    if ($imp['erro_ibscbs'] !== '') throw new RuntimeException($imp['erro_ibscbs']);
    $cUF = \NFePHP\Common\UFList::getCodeByUF($em['uf']);
    $chave = \NFePHP\Common\Keys::build($cUF, substr($dhEmi, 2, 2), substr($dhEmi, 5, 2), $em['cnpj'], '57', $serie, $numero, '1', $cCT);
    $o = static function (array $a): stdClass { return (object)$a; };
    $num = static fn(float $v, int $dec = 2): string => number_format($v, $dec, '.', '');

    $cte = new \NFePHP\CTe\MakeCTe('PL_CTe_400_RTC');
    $cte->taginfCTe($o(['Id' => 'CTe' . $chave, 'versao' => '4.00']));
    $pc = $d['percurso'];
    $toma = (string)$d['tomador'];
    $tomaDados = cte_tomador_dados($d);
    $ieToma = $tomaDados['ie'];
    $indIEToma = $ieToma === 'ISENTO' ? '2' : ($ieToma !== '' ? '1' : '9');
    $cte->tagide($o([
      'cUF' => $cUF, 'cCT' => $cCT, 'CFOP' => $d['cfop'], 'natOp' => $d['natureza'], 'mod' => '57', 'serie' => (string)$serie, 'nCT' => (string)$numero,
      'dhEmi' => $dhEmi, 'tpImp' => '1', 'tpEmis' => '1', 'cDV' => substr($chave, -1), 'tpAmb' => (string)$tpAmb, 'tpCTe' => '0', 'procEmi' => '0',
      'verProc' => 'Nexa ' . date('Y.m'), 'indGlobalizado' => '', 'cMunEnv' => $em['cidade_ibge'], 'xMunEnv' => $em['cidade'], 'UFEnv' => $em['uf'],
      'modal' => '01', 'tpServ' => $d['tp_serv'], 'cMunIni' => $pc['mun_ini_ibge'], 'xMunIni' => $pc['mun_ini_nome'], 'UFIni' => $pc['uf_ini'],
      'cMunFim' => $pc['mun_fim_ibge'], 'xMunFim' => $pc['mun_fim_nome'], 'UFFim' => $pc['uf_fim'], 'retira' => $d['retira'], 'xDetRetira' => $d['det_retira'],
      'indIEToma' => $indIEToma, 'dhCont' => '', 'xJust' => '',
    ]));
    $docs = static function (array $p): array {
      return strlen($p['documento']) === 14 ? ['CNPJ' => $p['documento'], 'CPF' => ''] : ['CNPJ' => '', 'CPF' => $p['documento']];
    };
    $ender = static function (array $p): array {
      return ['xLgr' => $p['logradouro'], 'nro' => $p['numero'], 'xCpl' => $p['complemento'], 'xBairro' => $p['bairro'], 'cMun' => $p['cidade_ibge'],
        'xMun' => $p['cidade'], 'CEP' => $p['cep'], 'UF' => $p['uf'], 'cPais' => '1058', 'xPais' => 'BRASIL'];
    };
    if ($toma === '4') {
      $cte->tagtoma4($o(['toma' => '4'] + $docs($tomaDados) + ['IE' => $tomaDados['ie'], 'xNome' => $tomaDados['nome'], 'xFant' => $tomaDados['fantasia'], 'fone' => $tomaDados['fone'], 'email' => $tomaDados['email']]));
      $cte->tagenderToma($o($ender($tomaDados)));
    } else {
      $cte->tagtoma3($o(['toma' => $toma]));
    }
    $obs = implode(' - ', array_filter([trim((string)$cfg['cte_obs_padrao']), trim((string)$d['observacao'])], 'strlen'));
    if ($obs !== '') $cte->tagcompl($o(['xObs' => mb_substr(preg_replace('/\s+/u', ' ', $obs) ?? $obs, 0, 2000)]));
    $cte->tagemit($o(['CNPJ' => $em['cnpj'], 'IE' => $em['ie'], 'IEST' => '', 'xNome' => $em['razao_social'], 'xFant' => $em['nome_fantasia'], 'CRT' => $em['crt']]));
    $cte->tagenderEmit($o(['xLgr' => $em['endereco'], 'nro' => $em['numero'] !== '' ? $em['numero'] : 'S/N', 'xCpl' => '', 'xBairro' => $em['bairro'], 'cMun' => $em['cidade_ibge'],
      'xMun' => $em['cidade'], 'CEP' => $em['cep'], 'UF' => $em['uf'], 'fone' => strlen($em['telefone']) >= 7 ? $em['telefone'] : '']));
    $parts = $d['participantes'];
    $cte->tagrem($o($docs($parts['remetente']) + ['IE' => $parts['remetente']['ie'], 'xNome' => $parts['remetente']['nome'], 'xFant' => $parts['remetente']['fantasia'], 'fone' => $parts['remetente']['fone'], 'email' => $parts['remetente']['email']]));
    $cte->tagenderReme($o($ender($parts['remetente'])));
    if (cte_participante_preenchido($parts['expedidor'])) {
      $cte->tagexped($o($docs($parts['expedidor']) + ['IE' => $parts['expedidor']['ie'], 'xNome' => $parts['expedidor']['nome'], 'fone' => $parts['expedidor']['fone'], 'email' => $parts['expedidor']['email']]));
      $cte->tagenderExped($o($ender($parts['expedidor'])));
    }
    if (cte_participante_preenchido($parts['recebedor'])) {
      $cte->tagreceb($o($docs($parts['recebedor']) + ['IE' => $parts['recebedor']['ie'], 'xNome' => $parts['recebedor']['nome'], 'fone' => $parts['recebedor']['fone'], 'email' => $parts['recebedor']['email']]));
      $cte->tagenderReceb($o($ender($parts['recebedor'])));
    }
    $cte->tagdest($o($docs($parts['destinatario']) + ['IE' => $parts['destinatario']['ie'], 'xNome' => $parts['destinatario']['nome'], 'fone' => $parts['destinatario']['fone'], 'ISUF' => '', 'email' => $parts['destinatario']['email']]));
    $cte->tagenderDest($o($ender($parts['destinatario'])));

    $v = $d['valores'];
    $cte->tagvPrest($o(['vTPrest' => $num((float)$v['prestacao']), 'vRec' => $num((float)$v['receber'])]));
    foreach ($v['componentes'] as $c) $cte->tagComp($o(['xNome' => $c['nome'], 'vComp' => $num((float)$c['valor'])]));

    $ic = $imp['icms'];
    $icmsStd = ['cst' => $ic['cst'] === '90OUTRAUF' ? '90' : $ic['cst'], 'outraUF' => $ic['cst'] === '90OUTRAUF', 'vTotTrib' => 0];
    if (in_array($ic['cst'], ['00', '20', '90'], true)) $icmsStd += ['vBC' => $num($ic['base']), 'pICMS' => $num($ic['aliquota']), 'vICMS' => $num($ic['valor']), 'pRedBC' => $ic['reducao'] > 0 ? $num($ic['reducao']) : ''];
    if ($ic['cst'] === '60') $icmsStd += ['vBCSTRet' => $num($ic['base']), 'pICMSSTRet' => $num($ic['aliquota']), 'vICMSSTRet' => $num($ic['valor'])];
    if ($ic['cst'] === '90OUTRAUF') $icmsStd += ['vBCOutraUF' => $num($ic['base']), 'pICMSOutraUF' => $num($ic['aliquota']), 'vICMSOutraUF' => $num($ic['valor']), 'pRedBCOutraUF' => $ic['reducao'] > 0 ? $num($ic['reducao']) : ''];
    $cte->tagicms($o($icmsStd));
    if ($imp['ibscbs']) {
      $x = $imp['ibscbs'];
      $ib = ['CST' => $x['cst'], 'cClassTrib' => $x['cclass'], 'vBC' => $num($x['base']),
        'gIBSUF_pIBSUF' => $num($x['ibs_uf_rate'], 4), 'gIBSUF_vIBSUF' => $num($x['ibs_uf_amount']),
        'gIBSMun_pIBSMun' => $num($x['ibs_mun_rate'], 4), 'gIBSMun_vIBSMun' => $num($x['ibs_mun_amount']),
        'vIBS' => $num($x['ibs_amount']), 'gCBS_pCBS' => $num($x['cbs_rate'], 4), 'gCBS_vCBS' => $num($x['cbs_amount'])];
      if ((float)$x['ibs_reduction_rate'] > 0) $ib += ['gIBSUF_pRedAliq' => $num($x['ibs_reduction_rate'], 4), 'gIBSUF_pAliqEfet' => $num($x['ibs_uf_effective_rate'], 4), 'gIBSMun_pRedAliq' => $num($x['ibs_reduction_rate'], 4), 'gIBSMun_pAliqEfet' => $num($x['ibs_mun_effective_rate'], 4)];
      if ((float)$x['cbs_reduction_rate'] > 0) $ib += ['gCBS_pRedAliq' => $num($x['cbs_reduction_rate'], 4), 'gCBS_pAliqEfet' => $num($x['cbs_effective_rate'], 4)];
      $cte->tagIBSCBS($o($ib));
      // NT 2025.001 v1.13 / NT 2026.004: com IBSCBS, vTotDFe = vTPrest.
      $cte->tagVTotDFe($o(['vTotDFe' => $num((float)$v['prestacao'])]));
    }
    $cte->taginfCTeNorm();
    $carga = $d['carga'];
    $cte->taginfCarga($o(['vCarga' => (float)$carga['valor'] > 0 ? $num((float)$carga['valor']) : '', 'proPred' => $carga['produto'], 'xOutCat' => $carga['outras']]));
    foreach ($carga['quantidades'] as $q) $cte->taginfQ($o(['cUnid' => $q['unidade'], 'tpMed' => $q['tipo'], 'qCarga' => $num((float)$q['quantidade'], 4)]));
    foreach ($d['documentos']['nfe'] as $n) $cte->taginfNFe($o(['chave' => $n['chave'], 'PIN' => '', 'dPrev' => $n['dprev']]));
    foreach ($d['documentos']['outros'] as $n) {
      $cte->taginfOutros($o(['tpDoc' => $n['tipo'], 'descOutros' => $n['tipo'] === '99' ? ($n['descricao'] !== '' ? $n['descricao'] : 'OUTROS') : $n['descricao'],
        'nDoc' => $n['numero'], 'dEmi' => $n['data'], 'vDocFisc' => (float)$n['valor'] > 0 ? $num((float)$n['valor']) : '', 'dPrev' => '']));
    }
    $cte->taginfModal($o(['versaoModal' => '4.00']));
    $cte->tagrodo($o(['RNTRC' => (string)$cfg['cte_rntrc']]));
    $rt = cte_resp_tecnico();
    if ($rt) $cte->taginfRespTec($o($rt));
    try {
      $cte->monta();
    } catch (Throwable $e) {
      $errs = method_exists($cte, 'getErrors') ? (array)$cte->getErrors() : [];
      throw new RuntimeException('Não foi possível montar o XML do CT-e: ' . ($errs ? implode('; ', array_map('strval', $errs)) : $e->getMessage()));
    }
    $xml = $cte->getXML();
    if (preg_match('/Id="CTe(\d{44})"/', $xml, $m)) $chave = $m[1];
    return [$xml, $chave];
  }
}

if (!function_exists('cte_xsd_validar')) {
  /** Valida o CT-e assinado pelo esquema oficial (inclusive o modal rodoviário). Devolve a lista de erros. */
  function cte_xsd_validar(string $xml): array {
    $dir = transp_cte_schema_dir();
    if (!is_file($dir . '/cte_v4.00.xsd')) return [];
    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $erros = [];
    try {
      $dom = new DOMDocument('1.0', 'UTF-8');
      $dom->preserveWhiteSpace = false;
      if (!$dom->loadXML($xml)) return ['XML inválido.'];
      $ok = $dom->schemaValidate($dir . '/cte_v4.00.xsd');
      $coleta = static function () use (&$erros): void {
        foreach (libxml_get_errors() as $er) $erros[] = trim(preg_replace('/\{[^}]*\}/', '', $er->message) ?? $er->message);
        libxml_clear_errors();
      };
      if (!$ok) $coleta();
      $rodo = $dom->getElementsByTagName('rodo')->item(0);
      if ($rodo) {
        $r = new DOMDocument('1.0', 'UTF-8');
        $r->appendChild($r->importNode($rodo, true));
        if (!$r->schemaValidate($dir . '/cteModalRodoviario_v4.00.xsd')) $coleta();
      }
    } finally {
      libxml_use_internal_errors($prev);
    }
    return array_values(array_unique($erros));
  }
}

/* ------------------------------------------------------------------------- */
/* Transmissão                                                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_ret_valores')) {
  /** cStat/xMotivo do protocolo (infProt) ou do retorno geral. */
  function cte_ret_valores(string $xml): array {
    $out = ['cStat' => '', 'xMotivo' => '', 'nProt' => '', 'dhRecbto' => '', 'tem_prot' => false];
    if (trim($xml) === '') return $out;
    $dom = new DOMDocument();
    if (!@$dom->loadXML($xml)) return $out;
    $prot = $dom->getElementsByTagName('infProt')->item(0);
    $base = $prot ?: $dom->documentElement;
    $g = static function ($node, string $tag): string { $n = $node ? $node->getElementsByTagName($tag)->item(0) : null; return $n ? trim($n->nodeValue) : ''; };
    $out['cStat'] = $g($base, 'cStat');
    $out['xMotivo'] = $g($base, 'xMotivo');
    $out['nProt'] = $g($base, 'nProt');
    $out['dhRecbto'] = $g($base, 'dhRecbto');
    $out['tem_prot'] = (bool)$prot;
    if ($out['cStat'] === '') { $out['cStat'] = $g($dom->documentElement, 'cStat'); $out['xMotivo'] = $g($dom->documentElement, 'xMotivo'); }
    return $out;
  }
}

if (!function_exists('cte_aplicar_retorno')) {
  /** Grava o resultado da SEFAZ (envio ou consulta) no CT-e. Devolve [status, mensagem]. */
  function cte_aplicar_retorno(PDO $pdo, int $companyId, array $row, string $assinado, string $retorno, string $tipo, ?int $userId): array {
    $r = cte_ret_valores($retorno);
    cte_log($pdo, $companyId, (int)$row['id'], $tipo, $r['cStat'], $r['xMotivo'], $tipo === 'ENVIO' ? $assinado : null, $retorno, $userId);
    $msg = trim($r['cStat'] . ' - ' . $r['xMotivo'], ' -');
    $dh = $r['dhRecbto'] !== '' ? date('Y-m-d H:i:s', strtotime($r['dhRecbto'])) : date('Y-m-d H:i:s');
    if ($r['tem_prot'] && in_array($r['cStat'], ['100', '150', '110', '205', '301', '302'], true)) {
      $proc = \NFePHP\CTe\Complements::toAuthorize($assinado, $retorno);
      $status = in_array($r['cStat'], ['100', '150'], true) ? 'AUTORIZADO' : 'DENEGADO';
      $pdo->prepare('UPDATE cte_documentos SET status = ?, cstat = ?, xmotivo = ?, protocolo = ?, dh_autorizacao = ?, xml = ? WHERE company_id = ? AND id = ?')
        ->execute([$status, $r['cStat'], mb_substr($r['xMotivo'], 0, 255), $r['nProt'], $dh, $proc, $companyId, (int)$row['id']]);
      transp_audit($pdo, $companyId, $status === 'AUTORIZADO' ? 'autorizar' : 'denegar', 'cte', (int)$row['id'], null, ['numero' => $row['numero'], 'chave' => $row['chave'], 'protocolo' => $r['nProt']]);
      return [$status, $msg];
    }
    if (in_array($r['cStat'], ['204', '539'], true) || $r['cStat'] === '') {
      // Duplicidade (já existe CT-e com este número) ou resposta sem situação: conferir pela consulta.
      $pdo->prepare("UPDATE cte_documentos SET status = 'PENDENTE', cstat = ?, xmotivo = ? WHERE company_id = ? AND id = ?")
        ->execute([$r['cStat'] ?: null, mb_substr($r['xMotivo'] ?: 'Resposta da SEFAZ sem situação. Use "Consultar situação".', 0, 255), $companyId, (int)$row['id']]);
      return ['PENDENTE', $msg !== '' ? $msg : 'Resposta da SEFAZ sem situação.'];
    }
    if ($tipo === 'CONSULTA' && $r['cStat'] === '217') {
      $pdo->prepare("UPDATE cte_documentos SET status = 'REJEITADO', cstat = ?, xmotivo = ? WHERE company_id = ? AND id = ?")
        ->execute(['217', 'Não consta na SEFAZ: pode transmitir de novo.', $companyId, (int)$row['id']]);
      return ['REJEITADO', '217 - CT-e não consta na SEFAZ. Ele não foi autorizado; pode transmitir de novo.'];
    }
    $pdo->prepare("UPDATE cte_documentos SET status = 'REJEITADO', cstat = ?, xmotivo = ? WHERE company_id = ? AND id = ?")
      ->execute([$r['cStat'], mb_substr($r['xMotivo'], 0, 255), $companyId, (int)$row['id']]);
    return ['REJEITADO', $msg];
  }
}

if (!function_exists('cte_transmitir')) {
  /**
   * Valida, numera (1ª vez), monta, assina e envia o CT-e. $tools só é passado nos testes.
   * Devolve ['status' => ..., 'mensagem' => ...].
   */
  function cte_transmitir(PDO $pdo, int $companyId, int $id, ?int $userId, ?object $tools = null): array {
    $row = cte_get($pdo, $companyId, $id);
    if (!$row) throw new RuntimeException('CT-e não encontrado.');
    if (!in_array($row['status'], CTE_EDITAVEIS, true)) throw new RuntimeException('Este CT-e não pode ser transmitido (situação: ' . (cte_status_labels()[$row['status']][0] ?? $row['status']) . ').');
    $cfg = transp_config($pdo, $companyId);
    $d = cte_dados_normalizar($row['dados'], $cfg);
    $erros = cte_validar($pdo, $companyId, $d, $cfg);
    if ($erros) throw new RuntimeException("Corrija antes de transmitir:\n- " . implode("\n- ", $erros));

    // Trava: só uma transmissão por vez.
    $st = $pdo->prepare("UPDATE cte_documentos SET status = 'PROCESSANDO', updated_by = ? WHERE company_id = ? AND id = ? AND status IN ('RASCUNHO','REJEITADO')");
    $st->execute([$userId, $companyId, $id]);
    if ($st->rowCount() !== 1) throw new RuntimeException('Este CT-e já está sendo transmitido.');

    $enviado = false;
    try {
      // Certificado/biblioteca primeiro: se faltar algo, nenhum número é usado.
      $tools = $tools ?? transp_tools($pdo, $companyId, 'cte', $cfg);
      if (method_exists($tools, 'model')) $tools->model(57);
      $serie = (int)$row['serie'];
      $numero = $row['numero'] !== null ? (int)$row['numero'] : 0;
      $cCT = (string)($row['cct'] ?? '');
      if ($numero <= 0) {
        $pdo->beginTransaction();
        try {
          $numero = transp_numeracao_reservar($pdo, $companyId, TRANSP_MODELO_CTE, $serie);
          $pdo->commit();
        } catch (Throwable $e) {
          if ($pdo->inTransaction()) $pdo->rollBack();
          throw $e;
        }
        $cCT = '';
      }
      if ($cCT === '') {
        require_once dirname(__DIR__) . '/vendor/autoload.php';
        $cCT = \NFePHP\Common\Keys::random($numero);
      }
      $pdo->prepare('UPDATE cte_documentos SET numero = ?, cct = ? WHERE company_id = ? AND id = ?')->execute([$numero, $cCT, $companyId, $id]);
      $row['numero'] = $numero;

      $em = transp_emitente($pdo, $companyId);
      $amb = transp_ambiente_efetivo($cfg, 'cte');
      $dhEmi = (new DateTime('now', new DateTimeZone(cte_fuso_uf($em['uf']))))->format('Y-m-d\TH:i:sP');
      [$xml, $chave] = cte_montar_xml($pdo, $companyId, $d, $cfg, $serie, $numero, $cCT, $dhEmi, $amb === 'PRODUCAO' ? 1 : 2);
      $assinado = (string)$tools->signCTe($xml);
      $row['chave'] = $chave;
      $pdo->prepare('UPDATE cte_documentos SET chave = ?, ambiente = ?, dh_emissao = ?, xml = ?, cstat = NULL, xmotivo = NULL WHERE company_id = ? AND id = ?')
        ->execute([$chave, $amb, date('Y-m-d H:i:s', strtotime($dhEmi)), $assinado, $companyId, $id]);
      $xsd = cte_xsd_validar($assinado);
      if ($xsd) {
        $motivo = 'XML recusado na validação do Nexa: ' . implode(' | ', array_slice($xsd, 0, 5));
        cte_log($pdo, $companyId, $id, 'VALIDACAO', '', $motivo, $assinado, null, $userId);
        $pdo->prepare("UPDATE cte_documentos SET status = 'REJEITADO', cstat = NULL, xmotivo = ? WHERE company_id = ? AND id = ?")->execute([mb_substr($motivo, 0, 255), $companyId, $id]);
        return ['status' => 'REJEITADO', 'mensagem' => $motivo];
      }
      $enviado = true;
      $retorno = (string)$tools->sefazEnviaCTe($assinado);
      [$status, $msg] = cte_aplicar_retorno($pdo, $companyId, $row, $assinado, $retorno, 'ENVIO', $userId);
      return ['status' => $status, 'mensagem' => $msg];
    } catch (Throwable $e) {
      // Se chegou a enviar, o resultado é incerto: fica para consulta (nunca reenviar às cegas).
      $novo = $enviado ? 'PENDENTE' : 'REJEITADO';
      $motivo = ($enviado ? 'Sem resposta da SEFAZ: ' : 'Erro antes do envio: ') . $e->getMessage();
      cte_log($pdo, $companyId, $id, $enviado ? 'ENVIO' : 'VALIDACAO', '', $motivo, null, null, $userId);
      $pdo->prepare('UPDATE cte_documentos SET status = ?, cstat = NULL, xmotivo = ? WHERE company_id = ? AND id = ?')->execute([$novo, mb_substr($motivo, 0, 255), $companyId, $id]);
      return ['status' => $novo, 'mensagem' => $motivo . ($enviado ? ' Use "Consultar situação".' : '')];
    }
  }
}

if (!function_exists('cte_consultar')) {
  /** Consulta a situação na SEFAZ pela chave (para PENDENTE, PROCESSANDO antigo e conferência). */
  function cte_consultar(PDO $pdo, int $companyId, int $id, ?int $userId, ?object $tools = null): array {
    $row = cte_get($pdo, $companyId, $id);
    if (!$row) throw new RuntimeException('CT-e não encontrado.');
    if (empty($row['chave']) || empty($row['xml'])) throw new RuntimeException('Este CT-e ainda não foi transmitido.');
    $parado = $row['status'] === 'PROCESSANDO' && strtotime((string)$row['updated_at']) < time() - 120;
    if (!in_array($row['status'], ['PENDENTE', 'REJEITADO', 'AUTORIZADO'], true) && !$parado) throw new RuntimeException('Aguarde a transmissão terminar.');
    $tools = $tools ?? transp_tools($pdo, $companyId, 'cte');
    if (method_exists($tools, 'model')) $tools->model(57);
    $retorno = (string)$tools->sefazConsultaChave((string)$row['chave']);
    if ($row['status'] === 'AUTORIZADO') {
      $r = cte_ret_valores($retorno);
      cte_log($pdo, $companyId, $id, 'CONSULTA', $r['cStat'], $r['xMotivo'], null, $retorno, $userId);
      return ['status' => 'AUTORIZADO', 'mensagem' => trim($r['cStat'] . ' - ' . $r['xMotivo'], ' -')];
    }
    $assinado = (string)$row['xml'];
    [$status, $msg] = cte_aplicar_retorno($pdo, $companyId, $row, $assinado, $retorno, 'CONSULTA', $userId);
    return ['status' => $status, 'mensagem' => $msg];
  }
}

/* ------------------------------------------------------------------------- */
/* DACTE e XML                                                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_dacte_pdf')) {
  function cte_dacte_pdf(PDO $pdo, int $companyId, int $id): string {
    $row = cte_get($pdo, $companyId, $id);
    if (!$row) throw new RuntimeException('CT-e não encontrado.');
    if (!in_array($row['status'], ['AUTORIZADO', 'CANCELADO', 'DENEGADO'], true) || empty($row['xml'])) throw new RuntimeException('O DACTE só pode ser impresso depois da autorização.');
    $inst = transp_instalacao();
    if (!$inst['dacte']) throw new RuntimeException('Impressão do DACTE indisponível no servidor (composer install).');
    $da = new \NFePHP\DA\CTe\Dacte((string)$row['xml']);
    if (method_exists($da, 'creditsIntegratorFooter')) $da->creditsIntegratorFooter('Nexa Próspero', false);
    if ($row['status'] === 'CANCELADO' && method_exists($da, 'setCancelFlag')) $da->setCancelFlag(true);
    return (string)$da->render();
  }
}

/* ------------------------------------------------------------------------- */
/* Apoio ao formulário (pessoas, cidades e NF-e)                              */
/* ------------------------------------------------------------------------- */

if (!function_exists('cte_pessoas_buscar')) {
  function cte_pessoas_buscar(PDO $pdo, int $companyId, string $q): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $dig = transp_digits($q);
    $sql = 'SELECT id, name, fantasy_name, document, state_registration, email, phone, whatsapp, zip_code, address, number, complement, district, city, city_ibge, state FROM finance_people WHERE company_id = ? AND active = 1 AND (name LIKE ? OR fantasy_name LIKE ?';
    $p = [$companyId, '%' . $q . '%', '%' . $q . '%'];
    if (strlen($dig) >= 3) { $sql .= ' OR REPLACE(REPLACE(REPLACE(document, ".", ""), "/", ""), "-", "") LIKE ?'; $p[] = '%' . $dig . '%'; }
    $sql .= ') ORDER BY name LIMIT 15';
    $st = $pdo->prepare($sql);
    $st->execute($p);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
      $out[] = cte_participante_limpo([
        'pessoa_id' => $r['id'], 'documento' => $r['document'], 'ie' => $r['state_registration'], 'nome' => $r['name'], 'fantasia' => $r['fantasy_name'],
        'fone' => $r['phone'] ?: $r['whatsapp'], 'email' => $r['email'], 'logradouro' => $r['address'], 'numero' => $r['number'], 'complemento' => $r['complement'],
        'bairro' => $r['district'], 'cep' => $r['zip_code'], 'cidade_ibge' => $r['city_ibge'], 'cidade' => $r['city'], 'uf' => $r['state'],
      ]);
    }
    return $out;
  }
}

if (!function_exists('cte_cidades_buscar')) {
  function cte_cidades_buscar(PDO $pdo, string $q, string $uf = ''): array {
    $q = trim($q);
    if (mb_strlen($q) < 2) return [];
    $sql = 'SELECT ibge_code, name, uf FROM fiscal_cities WHERE company_id = 0 AND is_active = 1 AND name LIKE ?';
    $p = [$q . '%'];
    $uf = strtoupper(substr(preg_replace('/[^A-Za-z]/', '', $uf) ?? '', 0, 2));
    if ($uf !== '') { $sql .= ' AND uf = ?'; $p[] = $uf; }
    $sql .= ' ORDER BY name, uf LIMIT 20';
    try {
      $st = $pdo->prepare($sql);
      $st->execute($p);
      return array_map(static fn($r) => ['ibge' => (string)$r['ibge_code'], 'nome' => (string)$r['name'], 'uf' => (string)$r['uf']], $st->fetchAll(PDO::FETCH_ASSOC));
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('cte_nfe_resumo_xml')) {
  /** Lê o XML de uma NF-e e devolve remetente (emitente), destinatário, valor, peso e produto. */
  function cte_nfe_resumo_xml(string $xml): ?array {
    $xml = trim($xml);
    if ($xml === '') return null;
    $prev = libxml_use_internal_errors(true);
    $dom = new DOMDocument();
    $ok = $dom->loadXML($xml, LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$ok) return null;
    $inf = $dom->getElementsByTagName('infNFe')->item(0);
    if (!$inf) return null;
    $g = static function ($node, string $tag): string { $n = $node ? $node->getElementsByTagName($tag)->item(0) : null; return $n ? trim($n->nodeValue) : ''; };
    $chave = transp_digits(substr((string)$inf->getAttribute('Id'), 3));
    $part = static function ($node, $ender) use ($g): array {
      if (!$node) return cte_participante_limpo([]);
      return cte_participante_limpo([
        'documento' => $g($node, 'CNPJ') ?: $g($node, 'CPF'), 'ie' => $g($node, 'IE') ?: ($g($node, 'indIEDest') === '2' ? 'ISENTO' : ''),
        'nome' => $g($node, 'xNome'), 'fantasia' => $g($node, 'xFant'), 'fone' => $g($ender, 'fone'), 'email' => $g($node, 'email'),
        'logradouro' => $g($ender, 'xLgr'), 'numero' => $g($ender, 'nro'), 'complemento' => $g($ender, 'xCpl'), 'bairro' => $g($ender, 'xBairro'),
        'cep' => $g($ender, 'CEP'), 'cidade_ibge' => $g($ender, 'cMun'), 'cidade' => $g($ender, 'xMun'), 'uf' => $g($ender, 'UF'),
      ]);
    };
    $emit = $dom->getElementsByTagName('emit')->item(0);
    $dest = $dom->getElementsByTagName('dest')->item(0);
    $peso = 0.0; $pesoL = 0.0; $vol = 0.0;
    foreach ($dom->getElementsByTagName('vol') as $v) { $peso += (float)$g($v, 'pesoB'); $pesoL += (float)$g($v, 'pesoL'); $vol += (float)$g($v, 'qVol'); }
    $prod = $dom->getElementsByTagName('det')->item(0);
    $total = $dom->getElementsByTagName('ICMSTot')->item(0);
    return [
      'chave' => strlen($chave) === 44 ? $chave : '',
      'numero' => $g($dom->getElementsByTagName('ide')->item(0), 'nNF'),
      'emissao' => substr($g($dom->getElementsByTagName('ide')->item(0), 'dhEmi'), 0, 10),
      'remetente' => $part($emit, $dom->getElementsByTagName('enderEmit')->item(0)),
      'destinatario' => $part($dest, $dom->getElementsByTagName('enderDest')->item(0)),
      'valor' => (float)$g($total, 'vNF'),
      'peso_bruto' => round($peso > 0 ? $peso : $pesoL, 4),
      'volumes' => $vol,
      'produto' => mb_strtoupper(mb_substr($g($prod, 'xProd'), 0, 60), 'UTF-8'),
    ];
  }
}

if (!function_exists('cte_nfe_buscar')) {
  /** Procura o XML da NF-e no Nexa (emitidas, entradas por XML e Radar). null = não encontrada. */
  function cte_nfe_buscar(PDO $pdo, int $companyId, string $chave): ?array {
    $chave = transp_digits($chave);
    if (!cte_chave_valida($chave)) throw new RuntimeException('Chave de NF-e inválida.');
    $fontes = [
      ['SELECT xml_raw AS x, xml_path AS p FROM fiscal_documents WHERE company_id = ? AND access_key = ? ORDER BY id DESC LIMIT 1', 'fiscal_documents'],
      ['SELECT xml_content AS x, NULL AS p FROM fiscal_xml_imports WHERE company_id = ? AND access_key = ? ORDER BY id DESC LIMIT 1', 'fiscal_xml_imports'],
      ['SELECT xml_raw AS x, NULL AS p FROM fiscal_xml_radar WHERE company_id = ? AND access_key = ? ORDER BY id DESC LIMIT 1', 'fiscal_xml_radar'],
    ];
    foreach ($fontes as [$sql, $tabela]) {
      try {
        $st = $pdo->prepare($sql);
        $st->execute([$companyId, $chave]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
      } catch (Throwable $e) {
        continue;
      }
      if (!$r) continue;
      $xml = (string)($r['x'] ?? '');
      if (trim($xml) === '' && !empty($r['p'])) {
        $path = (string)$r['p'];
        $abs = $path !== '' && $path[0] === '/' ? $path : dirname(__DIR__) . '/' . ltrim($path, '/');
        if (is_file($abs) && is_readable($abs)) $xml = (string)file_get_contents($abs);
      }
      $res = cte_nfe_resumo_xml($xml);
      if ($res) return $res + ['fonte' => $tabela];
    }
    return null;
  }
}
