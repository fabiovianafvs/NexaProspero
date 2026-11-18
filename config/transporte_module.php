<?php
declare(strict_types=1);

/**
 * Transporte (CT-e / MDF-e) — V1 Fundação.
 *
 * Módulo isolado: tabelas transp_* (db/20261118_transporte_fundacao_v1.sql) e a chave
 * 'transporte' de app_config. Liberado por empresa nos módulos do SaaS "cte" (CT-e) e
 * "mdfe" (MDF-e), desligados por padrão. Os dados do emitente (CNPJ, IE, endereço, CRT)
 * e o certificado A1 são os mesmos da NF-e (Configurações fiscais).
 * Bibliotecas: nfephp-org/sped-cte e sped-mdfe (composer.json, versões fixas por commit).
 * Na homologação (NEXA_AMBIENTE=homologacao) o ambiente da SEFAZ é sempre HOMOLOGAÇÃO.
 * A emissão (XML, transmissão, DACTE/DAMDFE) entra nas próximas versões.
 */

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/ambiente.php';

const TRANSP_UFS = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];
const TRANSP_MODELO_CTE = '57';
const TRANSP_MODELO_MDFE = '58';

if (!function_exists('transp_h')) {
  function transp_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('transp_digits')) {
  function transp_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}

if (!function_exists('transp_ready')) {
  /** Tabelas da V1 criadas pelo Atualizador do Banco. */
  function transp_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('transp_veiculos','transp_motoristas','transp_numeracao','transp_auditoria')");
      return $ready = ((int)$st->fetchColumn() === 4);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Validações                                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_cpf_valid')) {
  function transp_cpf_valid(string $cpf): bool {
    $c = transp_digits($cpf);
    if (strlen($c) !== 11 || preg_match('/^(\d)\1{10}$/', $c)) return false;
    for ($t = 9; $t < 11; $t++) {
      $d = 0;
      for ($i = 0; $i < $t; $i++) $d += (int)$c[$i] * (($t + 1) - $i);
      $d = ((10 * $d) % 11) % 10;
      if ((int)$c[$t] !== $d) return false;
    }
    return true;
  }
}

if (!function_exists('transp_cnpj_valid')) {
  function transp_cnpj_valid(string $cnpj): bool {
    $c = transp_digits($cnpj);
    if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    $calc = static function (string $base): int {
      $w = strlen($base) === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
      $s = 0;
      foreach ($w as $i => $p) $s += (int)$base[$i] * $p;
      $r = $s % 11;
      return $r < 2 ? 0 : 11 - $r;
    };
    $d1 = $calc(substr($c, 0, 12));
    $d2 = $calc(substr($c, 0, 12) . $d1);
    return (int)$c[12] === $d1 && (int)$c[13] === $d2;
  }
}

if (!function_exists('transp_placa_normalize')) {
  /** Placa sem traço e em maiúsculas. Aceita o padrão antigo (ABC1234) e o Mercosul (ABC1D23). */
  function transp_placa_normalize(string $placa): string {
    $p = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $placa) ?? '');
    if (!preg_match('/^[A-Z]{3}[0-9][A-Z0-9][0-9]{2}$/', $p)) throw new RuntimeException('Placa inválida. Use o formato ABC1234 ou ABC1D23 (Mercosul).');
    return $p;
  }
}

if (!function_exists('transp_date_in')) {
  function transp_date_in($v, string $label): string {
    $s = trim((string)$v);
    if ($s === '') return '';
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $s, $m)) $s = $m[3] . '-' . $m[2] . '-' . $m[1];
    $d = DateTime::createFromFormat('!Y-m-d', $s);
    if (!$d || $d->format('Y-m-d') !== $s) throw new RuntimeException($label . ': data inválida.');
    return $s;
  }
}

/* ------------------------------------------------------------------------- */
/* Tabelas do leiaute (CT-e 4.00 / MDF-e 3.00)                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_tipos_rodado')) {
  function transp_tipos_rodado(): array {
    return ['01' => 'Truck', '02' => 'Toco', '03' => 'Cavalo mecânico', '04' => 'VAN', '05' => 'Utilitário', '06' => 'Outros'];
  }
}
if (!function_exists('transp_tipos_carroceria')) {
  function transp_tipos_carroceria(): array {
    return ['00' => 'Não aplicável', '01' => 'Aberta', '02' => 'Fechada/Baú', '03' => 'Granelera', '04' => 'Porta contêiner', '05' => 'Sider'];
  }
}
if (!function_exists('transp_cfops_cte')) {
  /** CFOPs de prestação de serviço de transporte. */
  function transp_cfops_cte(): array {
    $base = [
      '351' => 'para execução de serviço da mesma natureza',
      '352' => 'a estabelecimento industrial',
      '353' => 'a estabelecimento comercial',
      '354' => 'a prestador de serviço de comunicação',
      '355' => 'a geradora/distribuidora de energia elétrica',
      '356' => 'a estabelecimento de produtor rural',
      '357' => 'a não contribuinte',
      '359' => 'a contribuinte ou não contribuinte (mercadoria dispensada de nota fiscal)',
      '360' => 'a contribuinte substituto em relação ao serviço de transporte',
      '932' => 'iniciada em UF diferente da inscrição do prestador',
    ];
    $out = [];
    foreach (['5' => 'Dentro do estado', '6' => 'Para outro estado'] as $p => $lb) {
      foreach ($base as $s => $d) $out[$p . $s] = $p . $s . ' - ' . $lb . ': ' . $d;
    }
    $out['7358'] = '7358 - Prestação de serviço de transporte (exterior)';
    return $out;
  }
}
if (!function_exists('transp_icms_situacoes_cte')) {
  function transp_icms_situacoes_cte(): array {
    return [
      '00' => '00 - Tributação normal do ICMS',
      '20' => '20 - Tributação com redução da base de cálculo',
      '40' => '40 - Isenta',
      '41' => '41 - Não tributada',
      '51' => '51 - Diferida',
      '60' => '60 - ICMS cobrado por substituição tributária (tomador responsável)',
      '90' => '90 - Outros',
      '90OUTRAUF' => '90 - ICMS devido à UF de origem da prestação, diferente da UF do emitente',
      'SN' => 'Simples Nacional (indSN = 1)',
    ];
  }
}

/* ------------------------------------------------------------------------- */
/* Configurações (app_config 'transporte')                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_config_defaults')) {
  function transp_config_defaults(): array {
    return [
      // CT-e
      'cte_ambiente' => 'HOMOLOGACAO', 'cte_serie' => 1, 'cte_rntrc' => '',
      'cte_cfop_estadual' => '5353', 'cte_cfop_interestadual' => '6353',
      'cte_natureza' => 'PRESTACAO DE SERVICO DE TRANSPORTE',
      'cte_icms_cst' => '00', 'cte_icms_aliquota' => 12.0, 'cte_icms_reducao' => 0.0,
      'cte_ibscbs_cst' => '', 'cte_ibscbs_cclasstrib' => '',
      'cte_obs_padrao' => '',
      // MDF-e
      'mdfe_ambiente' => 'HOMOLOGACAO', 'mdfe_serie' => 1,
      'mdfe_tipo_emitente' => '2', 'mdfe_tipo_transportador' => '',
      'mdfe_seguro_responsavel' => '1', 'mdfe_seguradora_nome' => '', 'mdfe_seguradora_cnpj' => '', 'mdfe_seguro_apolice' => '',
      'mdfe_obs_padrao' => '',
    ];
  }
}

if (!function_exists('transp_config')) {
  function transp_config(PDO $pdo, int $companyId): array {
    $saved = [];
    try { $saved = config_store_get($pdo, $companyId, 'transporte') ?? []; } catch (Throwable $e) {}
    return array_merge(transp_config_defaults(), array_intersect_key($saved, transp_config_defaults()));
  }
}

if (!function_exists('transp_ambiente_efetivo')) {
  /** Ambiente que vale de fato. No site de homologação do Nexa é sempre HOMOLOGACAO (trava). */
  function transp_ambiente_efetivo(array $cfg, string $doc = 'cte'): string {
    if (nexa_is_homologacao()) return 'HOMOLOGACAO';
    return (string)($cfg[$doc . '_ambiente'] ?? '') === 'PRODUCAO' ? 'PRODUCAO' : 'HOMOLOGACAO';
  }
}

if (!function_exists('transp_cclasstrib_info')) {
  /** Busca a classificação do IBS/CBS na tabela oficial (se ela estiver carregada). null = tabela vazia/indisponível. */
  function transp_cclasstrib_info(PDO $pdo, string $code): ?array {
    try {
      $n = (int)$pdo->query("SELECT COUNT(*) FROM fiscal_ibs_cbs_classifications WHERE company_id = 0 AND is_active = 1")->fetchColumn();
      if ($n === 0) return null;
      $st = $pdo->prepare('SELECT classification_code, description, cst_code FROM fiscal_ibs_cbs_classifications WHERE company_id = 0 AND is_active = 1 AND classification_code = ? LIMIT 1');
      $st->execute([$code]);
      return $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('transp_config_save')) {
  function transp_config_save(PDO $pdo, int $companyId, array $in): array {
    $old = transp_config($pdo, $companyId);
    $new = $old;
    $amb = static function ($v): string { return (string)$v === 'PRODUCAO' ? 'PRODUCAO' : 'HOMOLOGACAO'; };

    if (array_key_exists('cte_serie', $in)) {
      $new['cte_ambiente'] = $amb($in['cte_ambiente'] ?? 'HOMOLOGACAO');
      $serie = (int)$in['cte_serie'];
      if ($serie < 0 || $serie > 999) throw new RuntimeException('Série do CT-e: de 0 a 999.');
      $new['cte_serie'] = $serie;
      $rntrc = transp_digits($in['cte_rntrc'] ?? '');
      if ($rntrc !== '' && strlen($rntrc) !== 8) throw new RuntimeException('RNTRC da empresa: 8 dígitos (registro na ANTT).');
      $new['cte_rntrc'] = $rntrc;
      $cfops = transp_cfops_cte();
      foreach (['cte_cfop_estadual' => '5', 'cte_cfop_interestadual' => '6'] as $k => $prefix) {
        $v = (string)($in[$k] ?? '');
        if (!isset($cfops[$v]) || $v[0] !== $prefix) throw new RuntimeException('CFOP padrão ' . ($prefix === '5' ? 'dentro do estado' : 'para outro estado') . ' inválido.');
        $new[$k] = $v;
      }
      $nat = mb_strtoupper(trim(preg_replace('/\s+/u', ' ', (string)($in['cte_natureza'] ?? '')) ?? ''), 'UTF-8');
      if ($nat === '' || mb_strlen($nat) > 60) throw new RuntimeException('Natureza da operação do CT-e: de 1 a 60 caracteres.');
      $new['cte_natureza'] = $nat;
      $cst = (string)($in['cte_icms_cst'] ?? '');
      if (!isset(transp_icms_situacoes_cte()[$cst])) throw new RuntimeException('Situação do ICMS inválida.');
      $new['cte_icms_cst'] = $cst;
      foreach (['cte_icms_aliquota' => 'Alíquota do ICMS', 'cte_icms_reducao' => 'Redução da base do ICMS'] as $k => $lb) {
        $s = str_replace(',', '.', trim((string)($in[$k] ?? '0')));
        if ($s === '') $s = '0';
        if (!is_numeric($s) || (float)$s < 0 || (float)$s > 100) throw new RuntimeException($lb . ': de 0 a 100%.');
        $new[$k] = round((float)$s, 2);
      }
      $ibsCst = transp_digits($in['cte_ibscbs_cst'] ?? '');
      $cClass = transp_digits($in['cte_ibscbs_cclasstrib'] ?? '');
      if ($ibsCst !== '' && strlen($ibsCst) !== 3) throw new RuntimeException('CST do IBS/CBS: 3 dígitos.');
      if ($cClass !== '' && strlen($cClass) !== 6) throw new RuntimeException('Classificação tributária (cClassTrib) do IBS/CBS: 6 dígitos.');
      if (($ibsCst === '') !== ($cClass === '')) throw new RuntimeException('Informe juntos o CST e a classificação tributária (cClassTrib) do IBS/CBS.');
      if ($cClass !== '') {
        $info = transp_cclasstrib_info($pdo, $cClass);
        if (is_array($info) && !$info) throw new RuntimeException('Classificação tributária ' . $cClass . ' não existe na tabela oficial do IBS/CBS.');
        if (is_array($info) && !empty($info['cst_code']) && transp_digits($info['cst_code']) !== $ibsCst) {
          throw new RuntimeException('A classificação ' . $cClass . ' pertence ao CST ' . transp_digits($info['cst_code']) . ', não ao ' . $ibsCst . '.');
        }
      }
      $new['cte_ibscbs_cst'] = $ibsCst;
      $new['cte_ibscbs_cclasstrib'] = $cClass;
      $new['cte_obs_padrao'] = mb_substr(trim((string)($in['cte_obs_padrao'] ?? '')), 0, 2000);
    }

    if (array_key_exists('mdfe_serie', $in)) {
      $new['mdfe_ambiente'] = $amb($in['mdfe_ambiente'] ?? 'HOMOLOGACAO');
      $serie = (int)$in['mdfe_serie'];
      if ($serie < 0 || $serie > 999) throw new RuntimeException('Série do MDF-e: de 0 a 999.');
      $new['mdfe_serie'] = $serie;
      $te = (string)($in['mdfe_tipo_emitente'] ?? '2');
      if (!in_array($te, ['1', '2', '3'], true)) throw new RuntimeException('Tipo de emitente do MDF-e inválido.');
      $new['mdfe_tipo_emitente'] = $te;
      $tt = (string)($in['mdfe_tipo_transportador'] ?? '');
      if (!in_array($tt, ['', '1', '2', '3'], true)) throw new RuntimeException('Tipo de transportador inválido.');
      $new['mdfe_tipo_transportador'] = $tt;
      $resp = (string)($in['mdfe_seguro_responsavel'] ?? '1');
      if (!in_array($resp, ['1', '2'], true)) throw new RuntimeException('Responsável pelo seguro inválido.');
      $new['mdfe_seguro_responsavel'] = $resp;
      $new['mdfe_seguradora_nome'] = mb_substr(trim((string)($in['mdfe_seguradora_nome'] ?? '')), 0, 30);
      $segCnpj = transp_digits($in['mdfe_seguradora_cnpj'] ?? '');
      if ($segCnpj !== '' && !transp_cnpj_valid($segCnpj)) throw new RuntimeException('CNPJ da seguradora inválido.');
      $new['mdfe_seguradora_cnpj'] = $segCnpj;
      $new['mdfe_seguro_apolice'] = mb_substr(trim((string)($in['mdfe_seguro_apolice'] ?? '')), 0, 20);
      $new['mdfe_obs_padrao'] = mb_substr(trim((string)($in['mdfe_obs_padrao'] ?? '')), 0, 2000);
    }

    config_store_set($pdo, $companyId, 'transporte', $new);
    transp_audit($pdo, $companyId, 'alterar', 'configuracoes', null, array_diff_assoc(array_map('strval', $old), array_map('strval', $new)), array_diff_assoc(array_map('strval', $new), array_map('strval', $old)));
    return $new;
  }
}

/* ------------------------------------------------------------------------- */
/* Numeração (modelo 57 = CT-e, 58 = MDF-e)                                   */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_numeracao_get')) {
  /** Próximo número da série (1 quando ainda não há registro). */
  function transp_numeracao_get(PDO $pdo, int $companyId, string $modelo, int $serie): int {
    $st = $pdo->prepare('SELECT proximo_numero FROM transp_numeracao WHERE company_id = ? AND modelo = ? AND serie = ? LIMIT 1');
    $st->execute([$companyId, $modelo, $serie]);
    $n = $st->fetchColumn();
    return $n === false ? 1 : max(1, (int)$n);
  }
}

if (!function_exists('transp_numeracao_set')) {
  /**
   * Ajusta o próximo número (ex.: empresa que já emitia CT-e em outro sistema).
   * Só deixa voltar a numeração quando nenhum documento desta série foi emitido pelo Nexa
   * (a checagem dos documentos entra com a tabela de CT-e/MDF-e na V2/V5).
   */
  function transp_numeracao_set(PDO $pdo, int $companyId, string $modelo, int $serie, int $proximo): void {
    if (!in_array($modelo, [TRANSP_MODELO_CTE, TRANSP_MODELO_MDFE], true)) throw new RuntimeException('Modelo inválido.');
    if ($proximo < 1 || $proximo > 999999999) throw new RuntimeException('Próximo número: de 1 a 999.999.999.');
    $atual = transp_numeracao_get($pdo, $companyId, $modelo, $serie);
    if ($proximo === $atual) return;
    if ($proximo < $atual && function_exists('transp_numeracao_maior_emitido')) {
      $maior = transp_numeracao_maior_emitido($pdo, $companyId, $modelo, $serie);
      if ($maior > 0 && $proximo <= $maior) throw new RuntimeException('O número ' . $proximo . ' já foi usado nesta série (último emitido: ' . $maior . ').');
    }
    $pdo->prepare('INSERT INTO transp_numeracao (company_id, modelo, serie, proximo_numero) VALUES (?,?,?,?) ON DUPLICATE KEY UPDATE proximo_numero = VALUES(proximo_numero)')
      ->execute([$companyId, $modelo, $serie, $proximo]);
    transp_audit($pdo, $companyId, 'alterar', 'numeracao', null, ['modelo' => $modelo, 'serie' => $serie, 'proximo' => $atual], ['modelo' => $modelo, 'serie' => $serie, 'proximo' => $proximo]);
  }
}

if (!function_exists('transp_numeracao_reservar')) {
  /**
   * Reserva o próximo número da série dentro de uma transação aberta pelo chamador
   * (SELECT ... FOR UPDATE: duas emissões ao mesmo tempo nunca pegam o mesmo número).
   */
  function transp_numeracao_reservar(PDO $pdo, int $companyId, string $modelo, int $serie): int {
    if (!$pdo->inTransaction()) throw new RuntimeException('Reserva de número fora de transação.');
    $pdo->prepare('INSERT IGNORE INTO transp_numeracao (company_id, modelo, serie, proximo_numero) VALUES (?,?,?,1)')->execute([$companyId, $modelo, $serie]);
    $st = $pdo->prepare('SELECT proximo_numero FROM transp_numeracao WHERE company_id = ? AND modelo = ? AND serie = ? FOR UPDATE');
    $st->execute([$companyId, $modelo, $serie]);
    $n = max(1, (int)$st->fetchColumn());
    $pdo->prepare('UPDATE transp_numeracao SET proximo_numero = ? WHERE company_id = ? AND modelo = ? AND serie = ?')->execute([$n + 1, $companyId, $modelo, $serie]);
    return $n;
  }
}

/* ------------------------------------------------------------------------- */
/* Auditoria                                                                  */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_audit')) {
  function transp_audit(PDO $pdo, int $companyId, string $acao, string $entidade, ?int $entidadeId, ?array $antes, ?array $depois): void {
    $enc = static function (?array $a): ?string {
      if ($a === null || $a === []) return null;
      return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    try {
      $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
      $pdo->prepare('INSERT INTO transp_auditoria (company_id, usuario_id, acao, entidade, entidade_id, dados_anteriores_json, dados_novos_json, ip, created_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([
          $companyId, $uid ?: null, mb_substr($acao, 0, 30), mb_substr($entidade, 0, 40), $entidadeId, $enc($antes), $enc($depois),
          mb_substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
          date('Y-m-d H:i:s'),
        ]);
    } catch (Throwable $e) {
      error_log('[NEXA TRANSPORTE] auditoria: ' . $e->getMessage());
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Cadastros (veículos e motoristas)                                          */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_entities')) {
  /**
   * Definição dos cadastros. Tipos: text, digits, int, date, bool, enum, fk, doc, placa.
   * Tabelas e colunas daqui são a lista branca usada no SQL dinâmico.
   */
  function transp_entities(): array {
    $ufs = array_combine(TRANSP_UFS, TRANSP_UFS);
    return [
      'veiculos' => [
        'table' => 'transp_veiculos', 'label' => 'Veículo', 'plural' => 'Veículos', 'perm' => 'transp_veiculos', 'page' => 'transporte_veiculos.php',
        'hint' => 'Veículos de tração (caminhão, van, carro) e reboques usados no CT-e e no MDF-e. Veículo de terceiro: informe os dados do proprietário e o RNTRC dele.',
        'fields' => [
          'tipo' => ['label' => 'Tipo', 'type' => 'enum', 'options' => ['TRACAO' => 'Tração (veículo com motor)', 'REBOQUE' => 'Reboque / semirreboque'], 'required' => true, 'default' => 'TRACAO'],
          'placa' => ['label' => 'Placa', 'type' => 'placa', 'required' => true, 'unique' => true, 'placeholder' => 'ABC1D23'],
          'uf' => ['label' => 'UF do licenciamento', 'type' => 'enum', 'options' => $ufs, 'required' => true, 'empty' => '—'],
          'renavam' => ['label' => 'RENAVAM', 'type' => 'digits', 'min_len' => 9, 'len' => 11, 'placeholder' => '9 a 11 dígitos'],
          'codigo_interno' => ['label' => 'Código interno', 'type' => 'text', 'max' => 10, 'placeholder' => 'opcional (frota)'],
          'tara_kg' => ['label' => 'Tara (kg)', 'type' => 'int', 'min' => 0, 'max' => 999999, 'required' => true, 'default' => 0],
          'capacidade_kg' => ['label' => 'Capacidade (kg)', 'type' => 'int', 'min' => 0, 'max' => 999999],
          'capacidade_m3' => ['label' => 'Capacidade (m³)', 'type' => 'int', 'min' => 0, 'max' => 999],
          'tipo_rodado' => ['label' => 'Tipo de rodado', 'type' => 'enum', 'options' => transp_tipos_rodado(), 'required' => true, 'default' => '06'],
          'tipo_carroceria' => ['label' => 'Tipo de carroceria', 'type' => 'enum', 'options' => transp_tipos_carroceria(), 'required' => true, 'default' => '00'],
          'motorista_id' => ['label' => 'Motorista padrão', 'type' => 'fk', 'ref' => 'motoristas', 'empty' => 'Nenhum'],
          'proprietario' => ['label' => 'Proprietário', 'type' => 'enum', 'options' => ['PROPRIO' => 'Da própria empresa', 'TERCEIRO' => 'De terceiro (agregado / TAC)'], 'required' => true, 'default' => 'PROPRIO', 'section' => 'Proprietário (somente veículo de terceiro)'],
          'prop_tipo_documento' => ['label' => 'Tipo de documento', 'type' => 'enum', 'options' => ['CPF' => 'CPF', 'CNPJ' => 'CNPJ'], 'empty' => '—', 'owner' => true],
          'prop_documento' => ['label' => 'CPF/CNPJ do proprietário', 'type' => 'doc', 'doc_type_field' => 'prop_tipo_documento', 'owner' => true],
          'prop_nome' => ['label' => 'Nome / razão social', 'type' => 'text', 'max' => 60, 'span' => 2, 'owner' => true],
          'prop_ie' => ['label' => 'Inscrição estadual', 'type' => 'text', 'max' => 14, 'placeholder' => 'ou ISENTO', 'owner' => true],
          'prop_uf' => ['label' => 'UF do proprietário', 'type' => 'enum', 'options' => $ufs, 'empty' => '—', 'owner' => true],
          'prop_rntrc' => ['label' => 'RNTRC do proprietário', 'type' => 'digits', 'len' => 8, 'placeholder' => '8 dígitos', 'owner' => true],
          'prop_tipo' => ['label' => 'Tipo de proprietário', 'type' => 'enum', 'options' => ['0' => 'TAC agregado', '1' => 'TAC independente', '2' => 'Outros'], 'empty' => '—', 'owner' => true],
          'observacoes' => ['label' => 'Observações', 'type' => 'text', 'max' => 255, 'span' => 2],
        ],
        'list' => ['placa', 'tipo', 'uf', 'tipo_rodado', 'proprietario', 'motorista_id'], 'search' => ['placa', 'codigo_interno', 'renavam', 'prop_nome'], 'order' => 'placa',
      ],
      'motoristas' => [
        'table' => 'transp_motoristas', 'label' => 'Motorista', 'plural' => 'Motoristas', 'perm' => 'transp_motoristas', 'page' => 'transporte_motoristas.php',
        'hint' => 'Condutores informados no MDF-e (nome e CPF são obrigatórios). A CNH é só para controle interno.',
        'fields' => [
          'nome' => ['label' => 'Nome', 'type' => 'text', 'max' => 60, 'required' => true, 'span' => 2],
          'cpf' => ['label' => 'CPF', 'type' => 'doc', 'fixed_doc' => 'CPF', 'required' => true, 'unique' => true],
          'telefone' => ['label' => 'Telefone', 'type' => 'text', 'max' => 20],
          'cnh' => ['label' => 'CNH (número)', 'type' => 'digits', 'len' => 11],
          'cnh_categoria' => ['label' => 'Categoria da CNH', 'type' => 'enum', 'options' => ['B' => 'B', 'C' => 'C', 'D' => 'D', 'E' => 'E', 'AB' => 'AB', 'AC' => 'AC', 'AD' => 'AD', 'AE' => 'AE'], 'empty' => '—'],
          'cnh_validade' => ['label' => 'Validade da CNH', 'type' => 'date'],
          'observacoes' => ['label' => 'Observações', 'type' => 'text', 'max' => 255, 'span' => 2],
        ],
        'list' => ['nome', 'cpf', 'telefone', 'cnh_validade'], 'search' => ['nome', 'cpf', 'cnh'], 'order' => 'nome',
      ],
    ];
  }
}

if (!function_exists('transp_entity')) {
  function transp_entity(string $key): array {
    $all = transp_entities();
    if (!isset($all[$key])) throw new RuntimeException('Cadastro inválido.');
    return $all[$key] + ['key' => $key];
  }
}

if (!function_exists('transp_options')) {
  /** [id => rótulo] para listas de seleção. */
  function transp_options(PDO $pdo, int $companyId, string $ref, bool $activeOnly = true): array {
    $map = [
      'motoristas' => ['transp_motoristas', 'nome'],
      'veiculos' => ['transp_veiculos', "CONCAT(placa, CASE WHEN tipo = 'REBOQUE' THEN ' (reboque)' ELSE '' END)"],
    ];
    if (!isset($map[$ref])) return [];
    [$table, $labelSql] = $map[$ref];
    $st = $pdo->prepare('SELECT id, ' . $labelSql . ' AS label FROM ' . $table . ' WHERE company_id = ?' . ($activeOnly ? ' AND status = 1' : '') . ' ORDER BY label');
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['label'];
    return $out;
  }
}

if (!function_exists('transp_fk_check')) {
  /** Garante que o id escolhido pertence à empresa (nunca confia no id vindo do formulário). */
  function transp_fk_check(PDO $pdo, int $companyId, string $ref, int $id, string $label): ?int {
    if ($id <= 0) return null;
    $tables = ['motoristas' => 'transp_motoristas', 'veiculos' => 'transp_veiculos'];
    if (!isset($tables[$ref])) throw new RuntimeException($label . ': referência inválida.');
    $st = $pdo->prepare('SELECT 1 FROM ' . $tables[$ref] . ' WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    if (!$st->fetchColumn()) throw new RuntimeException($label . ': registro não encontrado nesta empresa.');
    return $id;
  }
}

if (!function_exists('transp_entity_get')) {
  function transp_entity_get(PDO $pdo, int $companyId, string $key, int $id): ?array {
    $e = transp_entity($key);
    $st = $pdo->prepare('SELECT * FROM ' . $e['table'] . ' WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('transp_entity_list')) {
  function transp_entity_list(PDO $pdo, int $companyId, string $key, string $q = '', string $status = 'ativos'): array {
    $e = transp_entity($key);
    $where = ['company_id = ?']; $params = [$companyId];
    if ($status === 'ativos') $where[] = 'status = 1';
    elseif ($status === 'inativos') $where[] = 'status = 0';
    $q = trim($q);
    if ($q !== '') {
      $parts = [];
      $needle = $key === 'veiculos' ? strtoupper(preg_replace('/[^A-Za-z0-9 ]/', '', $q) ?? $q) : $q;
      foreach ($e['search'] as $col) { $parts[] = '`' . $col . '` LIKE ?'; $params[] = '%' . ($col === 'placa' ? str_replace(' ', '', $needle) : $q) . '%'; }
      $digits = transp_digits($q);
      if ($key === 'motoristas' && $digits !== '' && $digits !== $q) { $parts[] = 'cpf LIKE ?'; $params[] = '%' . $digits . '%'; }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $st = $pdo->prepare('SELECT * FROM ' . $e['table'] . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY status DESC, `' . $e['order'] . '`, id LIMIT 500');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('transp_entity_save')) {
  /** Cria/altera um cadastro. Retorna o id. */
  function transp_entity_save(PDO $pdo, int $companyId, string $key, array $in): int {
    $e = transp_entity($key);
    $id = (int)($in['id'] ?? 0);
    $old = $id > 0 ? transp_entity_get($pdo, $companyId, $key, $id) : null;
    if ($id > 0 && !$old) throw new RuntimeException($e['label'] . ' não encontrado.');
    $data = [];
    foreach ($e['fields'] as $col => $f) {
      $raw = $in[$col] ?? null;
      $label = $f['label'];
      switch ($f['type']) {
        case 'bool': $v = empty($raw) ? 0 : 1; break;
        case 'int':
          $v = trim((string)$raw) === '' ? ($f['default'] ?? null) : (int)$raw;
          if ($v !== null && ((isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max']))) throw new RuntimeException($label . ': informe um valor entre ' . $f['min'] . ' e ' . $f['max'] . '.');
          break;
        case 'date': $v = transp_date_in($raw, $label) ?: null; break;
        case 'placa':
          $v = trim((string)$raw) === '' ? null : transp_placa_normalize((string)$raw);
          break;
        case 'digits':
          $v = transp_digits($raw);
          if ($v !== '') {
            $min = (int)($f['min_len'] ?? ($f['len'] ?? 0));
            $max = (int)($f['len'] ?? 0);
            if ($max > 0 && (strlen($v) < $min || strlen($v) > $max)) throw new RuntimeException($label . ': deve ter ' . ($min === $max ? $max : $min . ' a ' . $max) . ' dígitos.');
          }
          $v = $v !== '' ? $v : null;
          break;
        case 'doc':
          $v = transp_digits($raw);
          $tipo = (string)($f['fixed_doc'] ?? ($in[$f['doc_type_field'] ?? ''] ?? (strlen($v) === 11 ? 'CPF' : 'CNPJ')));
          if ($v !== '' && (($tipo === 'CPF' && !transp_cpf_valid($v)) || ($tipo !== 'CPF' && !transp_cnpj_valid($v)))) throw new RuntimeException($label . ': ' . $tipo . ' inválido.');
          $v = $v !== '' ? $v : null;
          break;
        case 'enum':
          $v = trim((string)$raw);
          if ($v === '') $v = $f['default'] ?? null;
          if ($v !== null && !isset($f['options'][$v])) throw new RuntimeException($label . ': opção inválida.');
          break;
        case 'fk': $v = transp_fk_check($pdo, $companyId, (string)$f['ref'], (int)$raw, $label); break;
        default:
          $v = trim(preg_replace('/\s+/u', ' ', (string)$raw) ?? '');
          if (mb_strlen($v) > (int)($f['max'] ?? 255)) throw new RuntimeException($label . ': máximo de ' . $f['max'] . ' caracteres.');
          $v = $v !== '' ? $v : null;
      }
      if (!empty($f['required']) && ($v === null || $v === '')) throw new RuntimeException('Informe ' . mb_strtolower($label) . '.');
      if (!empty($f['unique']) && $v !== null) {
        $st = $pdo->prepare('SELECT id FROM ' . $e['table'] . ' WHERE company_id = ? AND `' . $col . '` = ? AND id <> ? LIMIT 1');
        $st->execute([$companyId, $v, $id]);
        if ($st->fetchColumn()) throw new RuntimeException('Já existe ' . mb_strtolower($e['label']) . ' com este(a) ' . mb_strtolower($label) . '.');
      }
      $data[$col] = $v;
    }

    // Regras específicas
    if ($key === 'veiculos') {
      if ($data['tipo'] === 'REBOQUE') {
        // Reboque não tem rodado próprio (leiaute: tpRod só no veículo de tração) nem motorista.
        $data['tipo_rodado'] = '06';
        $data['motorista_id'] = null;
      }
      if ($data['proprietario'] === 'TERCEIRO') {
        if (!$data['prop_tipo_documento'] || !$data['prop_documento']) throw new RuntimeException('Veículo de terceiro: informe o CPF/CNPJ do proprietário.');
        if ($data['prop_tipo_documento'] === 'CPF' && strlen((string)$data['prop_documento']) !== 11) throw new RuntimeException('CPF do proprietário inválido.');
        if ($data['prop_tipo_documento'] === 'CNPJ' && strlen((string)$data['prop_documento']) !== 14) throw new RuntimeException('CNPJ do proprietário inválido.');
        if (!$data['prop_nome']) throw new RuntimeException('Veículo de terceiro: informe o nome do proprietário.');
        if (!$data['prop_uf']) throw new RuntimeException('Veículo de terceiro: informe a UF do proprietário.');
        if (!$data['prop_rntrc']) throw new RuntimeException('Veículo de terceiro: informe o RNTRC do proprietário (8 dígitos).');
        if ($data['prop_tipo'] === null) throw new RuntimeException('Veículo de terceiro: informe o tipo de proprietário.');
        if ($data['prop_ie'] !== null) $data['prop_ie'] = strtoupper((string)$data['prop_ie']) === 'ISENTO' ? 'ISENTO' : (transp_digits($data['prop_ie']) ?: null);
      } else {
        foreach (['prop_tipo_documento', 'prop_documento', 'prop_nome', 'prop_ie', 'prop_uf', 'prop_rntrc', 'prop_tipo'] as $c) $data[$c] = null;
      }
    }

    if ($old) {
      $changes = [];
      foreach ($data as $k => $v) if ((string)($old[$k] ?? '') !== (string)($v ?? '')) $changes[$k] = $v;
      if (!$changes) return $id;
      $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($changes)));
      $pdo->prepare('UPDATE ' . $e['table'] . ' SET ' . $sets . ' WHERE company_id = ? AND id = ?')->execute(array_merge(array_values($changes), [$companyId, $id]));
      transp_audit($pdo, $companyId, 'alterar', $key, $id, array_intersect_key($old, $changes), $changes);
      return $id;
    }
    $cols = array_keys($data);
    $pdo->prepare('INSERT INTO ' . $e['table'] . ' (company_id, ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols)) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
      ->execute(array_merge([$companyId], array_values($data)));
    $newId = (int)$pdo->lastInsertId();
    transp_audit($pdo, $companyId, 'criar', $key, $newId, null, array_filter($data, static fn($v) => $v !== null && $v !== ''));
    return $newId;
  }
}

if (!function_exists('transp_entity_status')) {
  /** Inativa/reativa (cadastros não são excluídos: podem estar em documentos emitidos). */
  function transp_entity_status(PDO $pdo, int $companyId, string $key, int $id, bool $active): void {
    $e = transp_entity($key);
    $old = transp_entity_get($pdo, $companyId, $key, $id);
    if (!$old) throw new RuntimeException($e['label'] . ' não encontrado.');
    if ((int)$old['status'] === ($active ? 1 : 0)) return;
    $pdo->prepare('UPDATE ' . $e['table'] . ' SET status = ? WHERE company_id = ? AND id = ?')->execute([$active ? 1 : 0, $companyId, $id]);
    transp_audit($pdo, $companyId, $active ? 'reativar' : 'inativar', $key, $id, ['status' => (int)$old['status']], ['status' => $active ? 1 : 0]);
  }
}

/* ------------------------------------------------------------------------- */
/* Emitente, instalação e comunicação com a SEFAZ                             */
/* ------------------------------------------------------------------------- */

if (!function_exists('transp_emitente')) {
  /** Dados do emitente: os mesmos das Configurações fiscais da NF-e. */
  function transp_emitente(PDO $pdo, int $companyId): array {
    require_once __DIR__ . '/fiscal_operational.php';
    $c = fiscal_company_config($pdo, $companyId);
    return [
      'cnpj' => transp_digits($c['company_cnpj'] ?? ''),
      'ie' => strtoupper(trim((string)($c['state_registration'] ?? ''))),
      'razao_social' => trim((string)($c['company_name'] ?? '')),
      'nome_fantasia' => trim((string)($c['company_trade_name'] ?? '')),
      'uf' => strtoupper(substr(preg_replace('/[^A-Za-z]/', '', (string)($c['uf'] ?? '')), 0, 2)),
      'cidade' => trim((string)($c['company_city'] ?? '')),
      'cidade_ibge' => transp_digits($c['city_ibge'] ?? ''),
      'endereco' => trim((string)($c['company_address'] ?? '')),
      'numero' => trim((string)($c['company_number'] ?? '')),
      'bairro' => trim((string)($c['company_district'] ?? '')),
      'cep' => transp_digits($c['company_zip'] ?? ''),
      'telefone' => transp_digits($c['company_phone'] ?? ''),
      'crt' => trim((string)($c['crt'] ?? '')),
    ];
  }
}

if (!function_exists('transp_pendencias')) {
  /** O que falta para emitir. $doc = 'cte' ou 'mdfe'. Lista vazia = pronto. */
  function transp_pendencias(PDO $pdo, int $companyId, array $cfg, string $doc): array {
    $p = [];
    $em = transp_emitente($pdo, $companyId);
    if (!transp_cnpj_valid($em['cnpj'])) $p[] = 'CNPJ da empresa nas Configurações fiscais.';
    if ($em['ie'] === '' || $em['ie'] === 'ISENTO') $p[] = 'Inscrição estadual da empresa nas Configurações fiscais (o emitente de ' . ($doc === 'cte' ? 'CT-e' : 'MDF-e') . ' precisa ter IE).';
    if ($em['razao_social'] === '') $p[] = 'Razão social nas Configurações fiscais.';
    if ($em['uf'] === '' || strlen($em['cidade_ibge']) !== 7) $p[] = 'UF e código IBGE da cidade nas Configurações fiscais.';
    if ($em['endereco'] === '' || $em['bairro'] === '' || strlen($em['cep']) !== 8) $p[] = 'Endereço completo (rua, bairro e CEP) nas Configurações fiscais.';
    if ($doc === 'cte') {
      if ((string)$cfg['cte_rntrc'] === '') $p[] = 'RNTRC da empresa (aba CT-e).';
      if ($em['crt'] === '3' && ((string)$cfg['cte_ibscbs_cst'] === '' || (string)$cfg['cte_ibscbs_cclasstrib'] === '')) {
        $p[] = 'CST e classificação tributária do IBS/CBS (obrigatório no CT-e para o Regime Normal desde 03/08/2026).';
      }
    } else {
      if ((string)$cfg['mdfe_tipo_emitente'] === '1' && (string)$cfg['cte_rntrc'] === '') $p[] = 'RNTRC da empresa (aba CT-e) — obrigatório para transportadora.';
      if ((string)$cfg['mdfe_tipo_emitente'] === '1' && ((string)$cfg['mdfe_seguradora_cnpj'] === '' || (string)$cfg['mdfe_seguro_apolice'] === '')) {
        $p[] = 'Seguradora (CNPJ) e número da apólice do seguro da carga (aba MDF-e) — obrigatório para transportadora.';
      }
    }
    try {
      require_once __DIR__ . '/fiscal_operational.php';
      fiscal_nfce_active_certificate($pdo, $companyId);
    } catch (Throwable $e) {
      $p[] = 'Certificado digital A1: ' . $e->getMessage();
    }
    return $p;
  }
}

if (!function_exists('transp_instalacao')) {
  /** Situação das bibliotecas no servidor (composer install). */
  function transp_instalacao(): array {
    $root = dirname(__DIR__);
    $autoload = $root . '/vendor/autoload.php';
    $out = ['autoload' => is_file($autoload), 'cte' => false, 'mdfe' => false, 'cte_rtc' => false, 'mdfe_schema' => false, 'dacte' => false, 'damdfe' => false, 'extensoes' => []];
    foreach (['dom', 'libxml', 'openssl', 'soap', 'simplexml', 'zlib', 'json'] as $ext) if (!extension_loaded($ext)) $out['extensoes'][] = $ext;
    if (!$out['autoload']) return $out;
    require_once $autoload;
    $out['cte'] = class_exists('NFePHP\\CTe\\Tools') && class_exists('NFePHP\\CTe\\MakeCTe');
    $out['mdfe'] = class_exists('NFePHP\\MDFe\\Tools') && class_exists('NFePHP\\MDFe\\Make');
    $out['dacte'] = class_exists('NFePHP\\DA\\CTe\\Dacte');
    $out['damdfe'] = class_exists('NFePHP\\DA\\MDFe\\Damdfe');
    $out['cte_rtc'] = is_file(transp_cte_schema_dir() . '/cte_v4.00.xsd');
    $out['mdfe_schema'] = is_dir($root . '/vendor/nfephp-org/sped-mdfe/schemes');
    return $out;
  }
}

if (!function_exists('transp_cte_schema_dir')) {
  /** Esquemas do CT-e 4.00 com a Reforma Tributária (IBS/CBS). */
  function transp_cte_schema_dir(): string {
    $base = dirname(__DIR__) . '/vendor/nfephp-org/sped-cte';
    foreach (['/schemes/PL_CTe_400_RTC', '/storage/schemes/PL_CTe_400_RTC'] as $d) if (is_dir($base . $d)) return $base . $d;
    return $base . '/schemes/PL_CTe_400_RTC';
  }
}

if (!function_exists('transp_tools_config')) {
  /** Configuração da biblioteca (JSON) e certificado A1 ativo da empresa. Não precisa das extensões SOAP. */
  function transp_tools_config(PDO $pdo, int $companyId, string $doc, ?array $cfg = null): array {
    require_once __DIR__ . '/fiscal_operational.php';
    $autoload = dirname(__DIR__) . '/vendor/autoload.php';
    if (!is_file($autoload)) throw new RuntimeException('Bibliotecas fiscais não instaladas no servidor (composer install).');
    require_once $autoload;
    $cfg = $cfg ?? transp_config($pdo, $companyId);
    $em = transp_emitente($pdo, $companyId);
    if (!transp_cnpj_valid($em['cnpj'])) throw new RuntimeException('CNPJ da empresa inválido nas Configurações fiscais.');
    if ($em['uf'] === '') throw new RuntimeException('Informe a UF da empresa nas Configurações fiscais.');
    $cert = fiscal_nfce_active_certificate($pdo, $companyId);
    $pfx = @file_get_contents((string)$cert['absolute_path']);
    if ($pfx === false || $pfx === '') throw new RuntimeException('Não foi possível ler o certificado A1.');
    $password = (string)($cert['password_secret'] ?? '');
    if ($password === '') throw new RuntimeException('A senha do certificado A1 não está salva. Edite o certificado ativo e informe a senha.');
    $payload = [
      'atualizacao' => date('Y-m-d H:i:s'),
      'tpAmb' => transp_ambiente_efetivo($cfg, $doc) === 'PRODUCAO' ? 1 : 2,
      'razaosocial' => $em['razao_social'] !== '' ? $em['razao_social'] : 'EMPRESA',
      'cnpj' => $em['cnpj'],
      'siglaUF' => $em['uf'],
      'schemes' => $doc === 'cte' ? 'PL_CTe_400_RTC' : 'PL_MDFe_300a',
      'versao' => $doc === 'cte' ? '4.00' : '3.00',
      'proxyConf' => ['proxyIp' => '', 'proxyPort' => '', 'proxyUser' => '', 'proxyPass' => ''],
    ];
    try {
      $certificate = \NFePHP\Common\Certificate::readPfx($pfx, $password);
    } catch (Throwable $e) {
      throw new RuntimeException('Não foi possível abrir o certificado A1 (senha incorreta ou arquivo danificado).');
    }
    return [json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), $certificate];
  }
}

if (!function_exists('transp_tools')) {
  /** Ferramenta da biblioteca (NFePHP) já com o certificado A1 ativo da empresa. $doc = 'cte' ou 'mdfe'. */
  function transp_tools(PDO $pdo, int $companyId, string $doc, ?array $cfg = null): object {
    $inst = transp_instalacao();
    if ($inst['extensoes']) throw new RuntimeException('Extensões do PHP ausentes no servidor: ' . implode(', ', $inst['extensoes']) . '.');
    if (!$inst[$doc]) throw new RuntimeException('A biblioteca do ' . ($doc === 'cte' ? 'CT-e' : 'MDF-e') . ' ainda não foi instalada no servidor. Rode: cd /var/www/nexa && composer install --no-dev');
    [$json, $certificate] = transp_tools_config($pdo, $companyId, $doc, $cfg);
    return $doc === 'cte' ? new \NFePHP\CTe\Tools($json, $certificate) : new \NFePHP\MDFe\Tools($json, $certificate);
  }
}

if (!function_exists('transp_sefaz_status')) {
  /**
   * Consulta o "status do serviço" da SEFAZ autorizadora (só leitura: não emite nada).
   * Confere de uma vez certificado, biblioteca e comunicação.
   */
  function transp_sefaz_status(PDO $pdo, int $companyId, string $doc): array {
    if (!in_array($doc, ['cte', 'mdfe'], true)) throw new RuntimeException('Documento inválido.');
    $cfg = transp_config($pdo, $companyId);
    $tools = transp_tools($pdo, $companyId, $doc, $cfg);
    try {
      $xml = (string)$tools->sefazStatus();
    } catch (Throwable $e) {
      transp_audit($pdo, $companyId, 'status_sefaz', $doc, null, null, ['erro' => mb_substr($e->getMessage(), 0, 300)]);
      throw new RuntimeException('Não foi possível falar com a SEFAZ agora: ' . mb_substr($e->getMessage(), 0, 300));
    }
    $get = static function (string $tag) use ($xml): string {
      return preg_match('~<(?:\w+:)?' . $tag . '>([^<]*)</(?:\w+:)?' . $tag . '>~', $xml, $m) ? trim(html_entity_decode($m[1], ENT_QUOTES | ENT_XML1, 'UTF-8')) : '';
    };
    $cStat = $get('cStat');
    $res = [
      'ok' => in_array($cStat, ['107'], true),
      'cStat' => $cStat,
      'xMotivo' => $get('xMotivo'),
      'ambiente' => $get('tpAmb') === '1' ? 'PRODUCAO' : 'HOMOLOGACAO',
      'tempo_medio' => $get('tMed'),
      'consultado_em' => date('Y-m-d H:i:s'),
    ];
    transp_audit($pdo, $companyId, 'status_sefaz', $doc, null, null, ['cStat' => $res['cStat'], 'xMotivo' => $res['xMotivo'], 'ambiente' => $res['ambiente']]);
    return $res;
  }
}
