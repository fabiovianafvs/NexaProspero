<?php
declare(strict_types=1);

/**
 * Nexa Ponto — V1 Fundação (cadastros do controle de jornada).
 *
 * Módulo isolado: só usa tabelas ponto_* (db/20261116_ponto_fundacao_v1.sql) e a chave
 * 'ponto' de app_config. Liberado por empresa no módulo do SaaS "ponto_eletronico".
 * Toda gravação passa por company_id da sessão e fica registrada em ponto_auditoria.
 * Ainda NÃO há marcação de ponto (REP-P/ARP/NSR entram nas próximas versões).
 */

require_once __DIR__ . '/config_store.php';

const PONTO_FOTO_MAX_BYTES = 8 * 1024 * 1024;
const PONTO_DIAS_SEMANA = [1 => 'Domingo', 2 => 'Segunda', 3 => 'Terça', 4 => 'Quarta', 5 => 'Quinta', 6 => 'Sexta', 7 => 'Sábado'];
const PONTO_DIAS_CURTOS = [0 => 'Todos', 1 => 'Dom', 2 => 'Seg', 3 => 'Ter', 4 => 'Qua', 5 => 'Qui', 6 => 'Sex', 7 => 'Sáb'];
const PONTO_UFS = ['AC','AL','AM','AP','BA','CE','DF','ES','GO','MA','MG','MS','MT','PA','PB','PE','PI','PR','RJ','RN','RO','RR','RS','SC','SE','SP','TO'];

if (!function_exists('ponto_h')) {
  function ponto_h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
}
if (!function_exists('ponto_digits')) {
  function ponto_digits($v): string { return preg_replace('/\D+/', '', (string)$v) ?? ''; }
}

if (!function_exists('ponto_ready')) {
  /** Tabelas da V1 criadas pelo Atualizador do Banco. */
  function ponto_ready(PDO $pdo): bool {
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
      $st = $pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name IN ('ponto_colaboradores','ponto_auditoria','ponto_colaborador_escalas')");
      return $ready = ((int)$st->fetchColumn() === 3);
    } catch (Throwable $e) {
      return $ready = false;
    }
  }
}

/* ------------------------------------------------------------------------- */
/* Validações                                                                 */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_cpf_valid')) {
  function ponto_cpf_valid(string $cpf): bool {
    $c = ponto_digits($cpf);
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

if (!function_exists('ponto_cnpj_valid')) {
  function ponto_cnpj_valid(string $cnpj): bool {
    $c = ponto_digits($cnpj);
    if (strlen($c) !== 14 || preg_match('/^(\d)\1{13}$/', $c)) return false;
    foreach ([12, 13] as $len) {
      $w = $len === 12 ? [5,4,3,2,9,8,7,6,5,4,3,2] : [6,5,4,3,2,9,8,7,6,5,4,3,2];
      $s = 0;
      for ($i = 0; $i < $len; $i++) $s += (int)$c[$i] * $w[$i];
      $r = $s % 11;
      if ((int)$c[$len] !== ($r < 2 ? 0 : 11 - $r)) return false;
    }
    return true;
  }
}

if (!function_exists('ponto_date_in')) {
  /** Aceita AAAA-MM-DD ou DD/MM/AAAA; '' quando vazio; lança erro quando inválida. */
  function ponto_date_in($v, string $label): string {
    $v = trim((string)$v);
    if ($v === '') return '';
    if (preg_match('#^(\d{2})/(\d{2})/(\d{4})$#', $v, $m)) $v = $m[3] . '-' . $m[2] . '-' . $m[1];
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[2], (int)$m[3], (int)$m[1])) {
      throw new RuntimeException($label . ': data inválida.');
    }
    return $v;
  }
}

if (!function_exists('ponto_date_safe')) {
  /** Data de filtro vinda da URL: inválida ou vazia vira o padrão (sem erro na tela). */
  function ponto_date_safe($v, string $default): string {
    try { return ponto_date_in($v, 'Data') ?: $default; } catch (Throwable $e) { return $default; }
  }
}

if (!function_exists('ponto_time_in')) {
  /** "8:00" / "08:00" -> "08:00:00"; erro quando inválida. */
  function ponto_time_in($v, string $label): string {
    $v = trim((string)$v);
    if (!preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?$/', $v, $m) || (int)$m[1] > 23 || (int)$m[2] > 59) {
      throw new RuntimeException($label . ': horário inválido (use HH:MM).');
    }
    return sprintf('%02d:%02d:00', (int)$m[1], (int)$m[2]);
  }
}

if (!function_exists('ponto_minutes_label')) {
  /** 528 -> "8h48". */
  function ponto_minutes_label(int $min): string {
    if ($min <= 0) return '0h';
    return intdiv($min, 60) . 'h' . ($min % 60 ? str_pad((string)($min % 60), 2, '0', STR_PAD_LEFT) : '');
  }
}

if (!function_exists('ponto_fmt_doc')) {
  function ponto_fmt_doc(string $d): string {
    $d = ponto_digits($d);
    if (strlen($d) === 11) return substr($d, 0, 3) . '.' . substr($d, 3, 3) . '.' . substr($d, 6, 3) . '-' . substr($d, 9);
    if (strlen($d) === 14) return substr($d, 0, 2) . '.' . substr($d, 2, 3) . '.' . substr($d, 5, 3) . '/' . substr($d, 8, 4) . '-' . substr($d, 12);
    return $d;
  }
}

/* ------------------------------------------------------------------------- */
/* Configuração e auditoria                                                   */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_has_column')) {
  function ponto_has_column(PDO $pdo, string $table, string $column): bool {
    static $c = [];
    $k = $table . '.' . $column;
    if (isset($c[$k])) return $c[$k];
    try {
      $st = $pdo->prepare('SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?');
      $st->execute([$table, $column]);
      return $c[$k] = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {
      return $c[$k] = false;
    }
  }
}

if (!function_exists('ponto_config_defaults')) {
  function ponto_config_defaults(): array {
    return [
      'timezone' => 'America/Sao_Paulo',
      // Coleta (usadas pela tela Bater Ponto, na próxima versão).
      'permitir_web' => 1, 'permitir_mobile' => 0, 'permitir_rfid' => 0,
      'exigir_localizacao' => 0, 'alertar_fora_area' => 1,
      // Apuração (V4): tolerância diária máxima (CLT art. 58, § 1º = 10 minutos).
      'tolerancia_diaria' => 10,
      // Início do controle de ponto no Nexa (antes disso não há falta). Vazio = 1ª marcação.
      'inicio_controle' => '',
      // Banco de horas e fechamento (V5). Regras dependem do acordo/convenção da empresa.
      'bh_ativo' => 0, 'bh_validade_meses' => 6, 'bh_fator_100' => 1, 'bh_debitar_faltas' => 1,
      'extra_percentual' => 50, 'extra_folga_percentual' => 100, 'extra_folga_100' => 1,
      // Dados do REP-P (preencher só depois do registro no INPI).
      'rep_p_registro_inpi' => '', 'rep_p_versao' => '', 'rep_p_desenvolvedor' => '', 'rep_p_cnpj_desenvolvedor' => '', 'rep_p_email_desenvolvedor' => '',
      // CPF de quem responde pelos cadastros do ponto (vai nos registros de empresa/empregado do AFD).
      'responsavel_cpf' => '',
      'afd_layout' => '', 'aej_layout' => '',
      // Acesso ao Nexa ligado ao ponto (V8). Desligado por padrão.
      'acesso_modo' => 'DESLIGADO', 'acesso_exigir_marcacao' => 1, 'acesso_bloquear_saida' => 0,
      'acesso_antes_min' => 15, 'acesso_depois_min' => 15, 'acesso_admins' => 0,
      // Biometria facial no Nexa (V10). Desligado por padrão.
      'facial_modo' => 'DESLIGADO', 'facial_falha' => 'SINALIZAR', 'facial_rigor' => 'NORMAL', 'facial_foto_dias' => 90,
    ];
  }
}

if (!function_exists('ponto_timezones')) {
  function ponto_timezones(): array {
    return [
      'America/Sao_Paulo' => 'Brasília (SP, MG, RJ, Sul, Nordeste, GO, DF...)',
      'America/Manaus' => 'Amazonas / Rondônia / Roraima / MT / MS (UTC-4)',
      'America/Cuiaba' => 'Mato Grosso (UTC-4)',
      'America/Campo_Grande' => 'Mato Grosso do Sul (UTC-4)',
      'America/Porto_Velho' => 'Rondônia (UTC-4)',
      'America/Boa_Vista' => 'Roraima (UTC-4)',
      'America/Rio_Branco' => 'Acre (UTC-5)',
      'America/Noronha' => 'Fernando de Noronha (UTC-2)',
    ];
  }
}

if (!function_exists('ponto_config')) {
  function ponto_config(PDO $pdo, int $companyId): array {
    $saved = [];
    try { $saved = function_exists('config_store_get') ? (config_store_get($pdo, $companyId, 'ponto') ?? []) : []; } catch (Throwable $e) {}
    return array_merge(ponto_config_defaults(), array_intersect_key($saved, ponto_config_defaults()));
  }
}

if (!function_exists('ponto_config_save')) {
  function ponto_config_save(PDO $pdo, int $companyId, array $in): array {
    $old = ponto_config($pdo, $companyId);
    $new = $old;
    foreach (['permitir_web', 'permitir_mobile', 'permitir_rfid', 'exigir_localizacao', 'alertar_fora_area'] as $k) $new[$k] = empty($in[$k]) ? 0 : 1;
    $tz = (string)($in['timezone'] ?? $old['timezone']);
    if (!isset(ponto_timezones()[$tz])) throw new RuntimeException('Fuso horário inválido.');
    $new['timezone'] = $tz;
    if (isset($in['tolerancia_diaria'])) {
      $tol = (int)$in['tolerancia_diaria'];
      if ($tol < 0 || $tol > 10) throw new RuntimeException('Tolerância diária: de 0 a 10 minutos (limite da CLT, art. 58, § 1º).');
      $new['tolerancia_diaria'] = $tol;
    }
    if (isset($in['extra_percentual'])) {
      foreach (['bh_ativo', 'bh_debitar_faltas', 'extra_folga_100'] as $k) $new[$k] = empty($in[$k]) ? 0 : 1;
      $val = (int)($in['bh_validade_meses'] ?? 6);
      if ($val < 1 || $val > 12) throw new RuntimeException('Validade do banco de horas: de 1 a 12 meses (CLT, art. 59: até 6 meses por acordo individual; até 12 por acordo ou convenção coletiva).');
      $new['bh_validade_meses'] = $val;
      $new['bh_fator_100'] = (int)($in['bh_fator_100'] ?? 1) === 2 ? 2 : 1;
      foreach (['extra_percentual' => 50, 'extra_folga_percentual' => 100] as $k => $min) {
        $v = (int)($in[$k] ?? $min);
        if ($v < 50 || $v > 300) throw new RuntimeException('Percentual de hora extra: de 50% a 300% (a CLT exige no mínimo 50%).');
        $new[$k] = $v;
      }
    }
    if (isset($in['inicio_controle'])) $new['inicio_controle'] = trim((string)$in['inicio_controle']) === '' ? '' : ponto_date_in($in['inicio_controle'], 'Início do controle de ponto');
    $new['rep_p_registro_inpi'] = mb_substr(trim((string)($in['rep_p_registro_inpi'] ?? '')), 0, 40);
    $new['rep_p_versao'] = mb_substr(trim((string)($in['rep_p_versao'] ?? '')), 0, 20);
    $new['rep_p_desenvolvedor'] = mb_substr(trim((string)($in['rep_p_desenvolvedor'] ?? '')), 0, 150);
    $cnpjDev = ponto_digits($in['rep_p_cnpj_desenvolvedor'] ?? '');
    if ($cnpjDev !== '' && !ponto_cnpj_valid($cnpjDev)) throw new RuntimeException('CNPJ do desenvolvedor inválido.');
    $new['rep_p_cnpj_desenvolvedor'] = $cnpjDev;
    if (isset($in['rep_p_email_desenvolvedor'])) {
      $em = trim((string)$in['rep_p_email_desenvolvedor']);
      if ($em !== '' && (!filter_var($em, FILTER_VALIDATE_EMAIL) || strlen($em) > 50)) throw new RuntimeException('E-mail do desenvolvedor inválido (até 50 caracteres).');
      $new['rep_p_email_desenvolvedor'] = $em;
    }
    if (isset($in['responsavel_cpf'])) {
      $rc = ponto_digits($in['responsavel_cpf']);
      if ($rc !== '' && !ponto_cpf_valid($rc)) throw new RuntimeException('CPF do responsável pelo ponto inválido.');
      $new['responsavel_cpf'] = $rc;
    }
    if (isset($in['acesso_modo'])) {
      $modo = (string)$in['acesso_modo'];
      if (!in_array($modo, ['DESLIGADO', 'AVISAR', 'BLOQUEAR'], true)) throw new RuntimeException('Modo de acesso ao Nexa inválido.');
      $new['acesso_modo'] = $modo;
      foreach (['acesso_exigir_marcacao', 'acesso_bloquear_saida', 'acesso_admins'] as $k) $new[$k] = empty($in[$k]) ? 0 : 1;
      foreach (['acesso_antes_min', 'acesso_depois_min'] as $k) {
        $v = (int)($in[$k] ?? 15);
        if ($v < 0 || $v > 240) throw new RuntimeException('Tolerância do acesso ao Nexa: de 0 a 240 minutos.');
        $new[$k] = $v;
      }
    }
    if (isset($in['facial_modo'])) {
      $fm = (string)$in['facial_modo']; $ff = (string)($in['facial_falha'] ?? 'SINALIZAR'); $fr = (string)($in['facial_rigor'] ?? 'NORMAL');
      if (!in_array($fm, ['DESLIGADO', 'FOTO', 'RECONHECER'], true) || !in_array($ff, ['SINALIZAR', 'BLOQUEAR'], true) || !in_array($fr, ['RIGOROSO', 'NORMAL', 'FLEXIVEL'], true)) throw new RuntimeException('Opção de reconhecimento facial inválida.');
      $fd = (int)($in['facial_foto_dias'] ?? 90);
      if ($fd < 30 || $fd > 365) throw new RuntimeException('Guardar as fotos das marcações: de 30 a 365 dias.');
      $new['facial_modo'] = $fm; $new['facial_falha'] = $ff; $new['facial_rigor'] = $fr; $new['facial_foto_dias'] = $fd;
    }
    config_store_set($pdo, $companyId, 'ponto', $new);
    if (session_status() === PHP_SESSION_ACTIVE) unset($_SESSION['nexa_ponto_acesso']);
    ponto_audit($pdo, $companyId, 'alterar', 'configuracoes', null, array_diff_assoc($old, $new), array_diff_assoc($new, $old));
    return $new;
  }
}

if (!function_exists('ponto_audit')) {
  /** Registro administrativo (quem, quando, o que mudou). Nunca grava hash/código de cartão. */
  function ponto_audit(PDO $pdo, int $companyId, string $acao, string $entidade, ?int $entidadeId, ?array $antes, ?array $depois, ?int $colaboradorId = null): void {
    $strip = static function (?array $a): ?string {
      if ($a === null || $a === []) return null;
      foreach (['rfid_hash'] as $k) if (array_key_exists($k, $a)) $a[$k] = $a[$k] ? '(alterado)' : null;
      return json_encode($a, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    };
    $uid = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $pdo->prepare('INSERT INTO ponto_auditoria (company_id, usuario_id, colaborador_id, acao, entidade, entidade_id, dados_anteriores_json, dados_novos_json, ip, user_agent, created_at) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
      ->execute([
        $companyId, $uid ?: null, $colaboradorId, mb_substr($acao, 0, 30), mb_substr($entidade, 0, 40), $entidadeId,
        $strip($antes), $strip($depois),
        mb_substr((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? ''), 0, 45) ?: null,
        mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255) ?: null,
        date('Y-m-d H:i:s'),
      ]);
  }
}

if (!function_exists('ponto_company_users')) {
  /** Usuários do Nexa desta empresa: [id => "Nome (e-mail)"]. */
  function ponto_company_users(PDO $pdo, int $companyId): array {
    $out = [];
    try {
      $where = function_exists('access_company_users_sql') ? access_company_users_sql($pdo) : '(company_id = ?)';
      $params = function_exists('access_company_users_params') ? access_company_users_params($pdo, $companyId) : [$companyId];
      $st = $pdo->prepare('SELECT id, name, email FROM users WHERE ' . $where . ' AND is_active = 1 ORDER BY name');
      $st->execute($params);
      foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $u) $out[(int)$u['id']] = trim((string)$u['name']) . ((string)$u['email'] !== '' ? ' (' . $u['email'] . ')' : '');
    } catch (Throwable $e) {}
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* Cadastros simples (departamentos, cargos, estabelecimentos, feriados, locais) */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_entities')) {
  /**
   * Definição dos cadastros simples. Tipos: text, digits, date, int, decimal, bool, enum, fk, doc.
   * As colunas e tabelas daqui são a lista branca usada no SQL dinâmico.
   */
  function ponto_entities(): array {
    $ufs = array_combine(PONTO_UFS, PONTO_UFS);
    return [
      'departamentos' => [
        'table' => 'ponto_departamentos', 'label' => 'Departamento', 'plural' => 'Departamentos', 'perm' => 'ponto_departamentos', 'page' => 'ponto_departamentos.php',
        'hint' => 'Setores da empresa (Administrativo, Financeiro, Produção...). O responsável é um colaborador.',
        'fields' => [
          'descricao' => ['label' => 'Descrição', 'type' => 'text', 'max' => 80, 'required' => true, 'unique' => true],
          'responsavel_id' => ['label' => 'Responsável', 'type' => 'fk', 'ref' => 'colaboradores'],
        ],
        'list' => ['descricao', 'responsavel_id'], 'search' => ['descricao'], 'order' => 'descricao',
      ],
      'cargos' => [
        'table' => 'ponto_cargos', 'label' => 'Cargo/Função', 'plural' => 'Cargos/Funções', 'perm' => 'ponto_cargos', 'page' => 'ponto_cargos.php',
        'hint' => 'Cargos e funções dos colaboradores. O CBO (Classificação Brasileira de Ocupações) é opcional.',
        'fields' => [
          'descricao' => ['label' => 'Descrição', 'type' => 'text', 'max' => 80, 'required' => true, 'unique' => true],
          'cbo' => ['label' => 'CBO', 'type' => 'digits', 'len' => 6, 'placeholder' => '6 dígitos, ex.: 411005'],
        ],
        'list' => ['descricao', 'cbo'], 'search' => ['descricao', 'cbo'], 'order' => 'descricao',
      ],
      'estabelecimentos' => [
        'table' => 'ponto_estabelecimentos', 'label' => 'Estabelecimento', 'plural' => 'Estabelecimentos', 'perm' => 'ponto_estabelecimentos', 'page' => 'ponto_estabelecimentos.php',
        'hint' => 'Matriz e filiais (cada CNPJ/CPF terá sua própria numeração de registros de ponto). Use "Preencher com os dados da empresa" para a matriz.',
        'fields' => [
          'tipo_documento' => ['label' => 'Tipo', 'type' => 'enum', 'options' => ['CNPJ' => 'CNPJ', 'CPF' => 'CPF'], 'required' => true, 'default' => 'CNPJ'],
          'documento' => ['label' => 'CNPJ/CPF', 'type' => 'doc', 'required' => true, 'unique' => true],
          'razao_social' => ['label' => 'Razão social / nome', 'type' => 'text', 'max' => 150, 'required' => true, 'span' => 2],
          'nome_fantasia' => ['label' => 'Nome fantasia', 'type' => 'text', 'max' => 150, 'span' => 2],
          'caepf' => ['label' => 'CAEPF', 'type' => 'digits', 'len' => 14, 'placeholder' => 'opcional (pessoa física)'],
          'cno' => ['label' => 'CNO', 'type' => 'digits', 'len' => 12, 'placeholder' => 'opcional (obra)'],
          'cep' => ['label' => 'CEP', 'type' => 'digits', 'len' => 8],
          'endereco' => ['label' => 'Endereço', 'type' => 'text', 'max' => 150, 'span' => 2],
          'numero' => ['label' => 'Número', 'type' => 'text', 'max' => 20],
          'complemento' => ['label' => 'Complemento', 'type' => 'text', 'max' => 80],
          'bairro' => ['label' => 'Bairro', 'type' => 'text', 'max' => 80],
          'cidade' => ['label' => 'Cidade', 'type' => 'text', 'max' => 80],
          'uf' => ['label' => 'UF', 'type' => 'enum', 'options' => $ufs, 'empty' => '—'],
          'timezone' => ['label' => 'Fuso horário', 'type' => 'enum', 'options' => ponto_timezones(), 'required' => true, 'default' => 'America/Sao_Paulo', 'span' => 2],
        ],
        'list' => ['razao_social', 'documento', 'cidade'], 'search' => ['razao_social', 'nome_fantasia', 'documento', 'cidade'], 'order' => 'razao_social',
      ],
      'feriados' => [
        'table' => 'ponto_feriados', 'label' => 'Feriado', 'plural' => 'Feriados', 'perm' => 'ponto_feriados', 'page' => 'ponto_feriados.php',
        'hint' => 'Feriados nacionais, estaduais, municipais e da própria empresa. "Repete todo ano" vale para datas fixas.',
        'fields' => [
          'descricao' => ['label' => 'Descrição', 'type' => 'text', 'max' => 100, 'required' => true, 'span' => 2],
          'data' => ['label' => 'Data', 'type' => 'date', 'required' => true],
          'tipo' => ['label' => 'Tipo', 'type' => 'enum', 'options' => ['NACIONAL' => 'Nacional', 'ESTADUAL' => 'Estadual', 'MUNICIPAL' => 'Municipal', 'INSTITUCIONAL' => 'Institucional (da empresa)'], 'required' => true, 'default' => 'NACIONAL'],
          'uf' => ['label' => 'UF (estadual/municipal)', 'type' => 'enum', 'options' => $ufs, 'empty' => '—'],
          'cidade' => ['label' => 'Cidade (municipal)', 'type' => 'text', 'max' => 80],
          'estabelecimento_id' => ['label' => 'Só no estabelecimento', 'type' => 'fk', 'ref' => 'estabelecimentos', 'empty' => 'Todos'],
          'recorrente' => ['label' => 'Repete todo ano', 'type' => 'bool'],
        ],
        'list' => ['data', 'descricao', 'tipo', 'recorrente'], 'search' => ['descricao', 'cidade'], 'order' => 'data',
      ],
      'locais' => [
        'table' => 'ponto_locais', 'label' => 'Local de trabalho', 'plural' => 'Locais de Trabalho', 'perm' => 'ponto_locais', 'page' => 'ponto_locais.php',
        'hint' => 'Lugares onde o ponto pode ser batido. A marcação fora do raio continua valendo, mas fica sinalizada para conferência.',
        'fields' => [
          'descricao' => ['label' => 'Descrição', 'type' => 'text', 'max' => 100, 'required' => true, 'unique' => true, 'span' => 2],
          'estabelecimento_id' => ['label' => 'Estabelecimento', 'type' => 'fk', 'ref' => 'estabelecimentos'],
          'raio_metros' => ['label' => 'Raio permitido (metros)', 'type' => 'int', 'min' => 10, 'max' => 5000, 'default' => 100, 'required' => true],
          'endereco' => ['label' => 'Endereço', 'type' => 'text', 'max' => 200, 'span' => 2],
          'latitude' => ['label' => 'Latitude', 'type' => 'decimal', 'min' => -90, 'max' => 90, 'scale' => 7],
          'longitude' => ['label' => 'Longitude', 'type' => 'decimal', 'min' => -180, 'max' => 180, 'scale' => 7],
          'exigir_localizacao' => ['label' => 'Exigir que o aparelho informe a localização', 'type' => 'bool'],
          'alertar_fora_area' => ['label' => 'Sinalizar ponto batido fora do raio', 'type' => 'bool', 'default' => 1],
        ],
        'list' => ['descricao', 'raio_metros', 'estabelecimento_id'], 'search' => ['descricao', 'endereco'], 'order' => 'descricao',
      ],
      'tipos_ocorrencia' => [
        'table' => 'ponto_tipos_ocorrencia', 'label' => 'Tipo de ocorrência', 'plural' => 'Tipos de Ocorrência', 'perm' => 'ponto_tipos_ocorrencia', 'page' => 'ponto_tipos_ocorrencia.php',
        'hint' => 'Motivos usados nas justificativas e no tratamento do ponto. "Abona" = a ausência justificada por este motivo não vira débito de horas (ex.: atestado, treinamento).',
        'fields' => [
          'descricao' => ['label' => 'Descrição', 'type' => 'text', 'max' => 80, 'required' => true, 'unique' => true, 'span' => 2],
          'abona' => ['label' => 'Abona (não desconta as horas)', 'type' => 'bool'],
          'compensa_banco' => ['label' => 'Folga paga com o banco de horas (desconta do banco)', 'type' => 'bool', 'needs_column' => true],
          'exige_documento' => ['label' => 'Exige documento anexo (ex.: atestado)', 'type' => 'bool'],
          'visivel_colaborador' => ['label' => 'O colaborador pode escolher no Meu Ponto', 'type' => 'bool', 'default' => 1],
        ],
        'list' => ['descricao', 'abona', 'compensa_banco', 'exige_documento', 'visivel_colaborador'], 'search' => ['descricao'], 'order' => 'descricao',
      ],
    ];
  }
}

if (!function_exists('ponto_entity')) {
  function ponto_entity(string $key, ?PDO $pdo = null): array {
    $all = ponto_entities();
    if (!isset($all[$key])) throw new RuntimeException('Cadastro inválido.');
    $e = $all[$key] + ['key' => $key];
    // Campos de versões novas ('needs_column'): só aparecem depois da migração aplicada.
    if ($pdo) {
      foreach ($e['fields'] as $col => $f) {
        if (!empty($f['needs_column']) && !ponto_has_column($pdo, (string)$e['table'], (string)$col)) {
          unset($e['fields'][$col]);
          $e['list'] = array_values(array_diff($e['list'], [$col]));
        }
      }
    } else {
      foreach ($e['fields'] as $col => $f) if (!empty($f['needs_column'])) { unset($e['fields'][$col]); $e['list'] = array_values(array_diff($e['list'], [$col])); }
    }
    return $e;
  }
}

if (!function_exists('ponto_options')) {
  /** [id => rótulo] para listas de seleção (colaboradores, estabelecimentos, cargos...). */
  function ponto_options(PDO $pdo, int $companyId, string $ref, bool $activeOnly = true): array {
    $map = [
      'colaboradores' => ['ponto_colaboradores', "nome", "status <> 'DESLIGADO'"],
      'estabelecimentos' => ['ponto_estabelecimentos', "COALESCE(NULLIF(nome_fantasia,''), razao_social)", 'status = 1'],
      'departamentos' => ['ponto_departamentos', 'descricao', 'status = 1'],
      'cargos' => ['ponto_cargos', 'descricao', 'status = 1'],
      'jornadas' => ['ponto_jornadas', 'descricao', 'status = 1'],
      'escalas' => ['ponto_escalas', 'descricao', 'status = 1'],
      'locais' => ['ponto_locais', 'descricao', 'status = 1'],
    ];
    if (!isset($map[$ref])) return [];
    [$table, $labelSql, $activeSql] = $map[$ref];
    $st = $pdo->prepare('SELECT id, ' . $labelSql . ' AS label FROM ' . $table . ' WHERE company_id = ?' . ($activeOnly ? ' AND ' . $activeSql : '') . ' ORDER BY label');
    $st->execute([$companyId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['id']] = (string)$r['label'];
    return $out;
  }
}

if (!function_exists('ponto_fk_check')) {
  /** Garante que o id escolhido pertence à empresa (nunca confia no id vindo do formulário). */
  function ponto_fk_check(PDO $pdo, int $companyId, string $ref, int $id, string $label): ?int {
    if ($id <= 0) return null;
    $tables = ['colaboradores' => 'ponto_colaboradores', 'estabelecimentos' => 'ponto_estabelecimentos', 'departamentos' => 'ponto_departamentos',
      'cargos' => 'ponto_cargos', 'jornadas' => 'ponto_jornadas', 'escalas' => 'ponto_escalas', 'locais' => 'ponto_locais'];
    if (!isset($tables[$ref])) throw new RuntimeException($label . ': referência inválida.');
    $st = $pdo->prepare('SELECT 1 FROM ' . $tables[$ref] . ' WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    if (!$st->fetchColumn()) throw new RuntimeException($label . ': registro não encontrado nesta empresa.');
    return $id;
  }
}

if (!function_exists('ponto_entity_get')) {
  function ponto_entity_get(PDO $pdo, int $companyId, string $key, int $id): ?array {
    $e = ponto_entity($key);
    $st = $pdo->prepare('SELECT * FROM ' . $e['table'] . ' WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_entity_list')) {
  function ponto_entity_list(PDO $pdo, int $companyId, string $key, string $q = '', string $status = 'ativos'): array {
    $e = ponto_entity($key);
    $where = ['company_id = ?']; $params = [$companyId];
    if ($status === 'ativos') $where[] = 'status = 1';
    elseif ($status === 'inativos') $where[] = 'status = 0';
    $q = trim($q);
    if ($q !== '') {
      $parts = [];
      foreach ($e['search'] as $col) { $parts[] = '`' . $col . '` LIKE ?'; $params[] = '%' . $q . '%'; }
      $digits = ponto_digits($q);
      if ($key === 'estabelecimentos' && $digits !== '') { $parts[] = 'documento LIKE ?'; $params[] = '%' . $digits . '%'; }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $st = $pdo->prepare('SELECT * FROM ' . $e['table'] . ' WHERE ' . implode(' AND ', $where) . ' ORDER BY status DESC, `' . $e['order'] . '`, id LIMIT 500');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_entity_save')) {
  /** Cria/altera um cadastro simples. Retorna o id. */
  function ponto_entity_save(PDO $pdo, int $companyId, string $key, array $in): int {
    $e = ponto_entity($key, $pdo);
    $id = (int)($in['id'] ?? 0);
    $old = $id > 0 ? ponto_entity_get($pdo, $companyId, $key, $id) : null;
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
        case 'decimal':
          $s = str_replace(',', '.', trim((string)$raw));
          if ($s === '') { $v = null; break; }
          if (!is_numeric($s)) throw new RuntimeException($label . ': número inválido.');
          $v = round((float)$s, (int)($f['scale'] ?? 2));
          if ((isset($f['min']) && $v < $f['min']) || (isset($f['max']) && $v > $f['max'])) throw new RuntimeException($label . ': valor fora do permitido.');
          break;
        case 'date': $v = ponto_date_in($raw, $label) ?: null; break;
        case 'digits':
          $v = ponto_digits($raw);
          if ($v !== '' && isset($f['len']) && strlen($v) !== $f['len']) throw new RuntimeException($label . ': deve ter ' . $f['len'] . ' dígitos.');
          $v = $v !== '' ? $v : null;
          break;
        case 'doc':
          $v = ponto_digits($raw);
          $tipo = (string)($in['tipo_documento'] ?? 'CNPJ');
          if ($v !== '' && (($tipo === 'CPF' && !ponto_cpf_valid($v)) || ($tipo !== 'CPF' && !ponto_cnpj_valid($v)))) throw new RuntimeException($tipo . ' inválido.');
          $v = $v !== '' ? $v : null;
          break;
        case 'enum':
          $v = trim((string)$raw);
          if ($v === '') $v = $f['default'] ?? null;
          if ($v !== null && !isset($f['options'][$v])) throw new RuntimeException($label . ': opção inválida.');
          break;
        case 'fk': $v = ponto_fk_check($pdo, $companyId, (string)$f['ref'], (int)$raw, $label); break;
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
    if ($key === 'feriados') ponto_feriados_ano($pdo, $companyId, 0, true);
    // Regras específicas
    if ($key === 'locais' && (($data['latitude'] === null) !== ($data['longitude'] === null))) throw new RuntimeException('Informe latitude e longitude juntas (ou clique no mapa).');
    if ($key === 'feriados') {
      if ($data['tipo'] === 'ESTADUAL' && !$data['uf']) throw new RuntimeException('Feriado estadual: informe a UF.');
      if ($data['tipo'] === 'MUNICIPAL' && (!$data['uf'] || !$data['cidade'])) throw new RuntimeException('Feriado municipal: informe UF e cidade.');
    }
    if ($old) {
      $changes = [];
      foreach ($data as $k => $v) if ((string)($old[$k] ?? '') !== (string)($v ?? '')) $changes[$k] = $v;
      if (!$changes) return $id;
      $sets = implode(', ', array_map(static fn($c) => '`' . $c . '` = ?', array_keys($changes)));
      $pdo->prepare('UPDATE ' . $e['table'] . ' SET ' . $sets . ' WHERE company_id = ? AND id = ?')->execute(array_merge(array_values($changes), [$companyId, $id]));
      ponto_audit($pdo, $companyId, 'alterar', $key, $id, array_intersect_key($old, $changes), $changes);
      // REP-P (V6): alteração do empregador fica registrada no ARP (registro tipo 2 do AFD).
      if ($key === 'estabelecimentos' && function_exists('ponto_arp_empresa_registrar')) ponto_arp_empresa_registrar($pdo, $companyId, $id);
      return $id;
    }
    $cols = array_keys($data);
    $pdo->prepare('INSERT INTO ' . $e['table'] . ' (company_id, ' . implode(', ', array_map(static fn($c) => '`' . $c . '`', $cols)) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
      ->execute(array_merge([$companyId], array_values($data)));
    $newId = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'criar', $key, $newId, null, array_filter($data, static fn($v) => $v !== null && $v !== ''));
    return $newId;
  }
}

if (!function_exists('ponto_entity_status')) {
  /** Inativa/reativa (cadastros do ponto não são excluídos: podem estar no histórico). */
  function ponto_entity_status(PDO $pdo, int $companyId, string $key, int $id, bool $active): void {
    $e = ponto_entity($key);
    $old = ponto_entity_get($pdo, $companyId, $key, $id);
    if (!$old) throw new RuntimeException($e['label'] . ' não encontrado.');
    if ((int)$old['status'] === ($active ? 1 : 0)) return;
    if ($key === 'feriados') ponto_feriados_ano($pdo, $companyId, 0, true);
    $pdo->prepare('UPDATE ' . $e['table'] . ' SET status = ? WHERE company_id = ? AND id = ?')->execute([$active ? 1 : 0, $companyId, $id]);
    ponto_audit($pdo, $companyId, $active ? 'reativar' : 'inativar', $key, $id, ['status' => (int)$old['status']], ['status' => $active ? 1 : 0]);
  }
}

if (!function_exists('ponto_estabelecimento_da_empresa')) {
  /** Dados da empresa (companies) para preencher a matriz com um clique. */
  function ponto_estabelecimento_da_empresa(PDO $pdo, int $companyId): array {
    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $c = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    $pick = static function (array $keys) use ($c): string {
      foreach ($keys as $k) if (isset($c[$k]) && trim((string)$c[$k]) !== '') return trim((string)$c[$k]);
      return '';
    };
    $doc = ponto_digits($pick(['cnpj', 'document', 'cpf_cnpj', 'documento']));
    return [
      'tipo_documento' => strlen($doc) === 11 ? 'CPF' : 'CNPJ', 'documento' => $doc,
      'razao_social' => $pick(['razao_social', 'legal_name', 'name']), 'nome_fantasia' => $pick(['nome_fantasia', 'fantasy_name', 'trade_name', 'name']),
      'cep' => ponto_digits($pick(['cep', 'zip', 'zipcode', 'postal_code'])), 'endereco' => $pick(['endereco', 'address', 'logradouro', 'street']),
      'numero' => $pick(['numero', 'address_number', 'number']), 'complemento' => $pick(['complemento', 'complement']),
      'bairro' => $pick(['bairro', 'district', 'neighborhood']), 'cidade' => $pick(['cidade', 'city', 'municipio']),
      'uf' => strtoupper($pick(['uf', 'estado', 'state'])),
    ];
  }
}

/* ------------------------------------------------------------------------- */
/* Jornadas                                                                   */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_period_minutes')) {
  /** Duração de um período em minutos (fim menor que início = passa da meia-noite). */
  function ponto_period_minutes(string $ini, string $fim): int {
    $a = (int)substr($ini, 0, 2) * 60 + (int)substr($ini, 3, 2);
    $b = (int)substr($fim, 0, 2) * 60 + (int)substr($fim, 3, 2);
    return $b > $a ? $b - $a : 1440 - $a + $b;
  }
}

if (!function_exists('ponto_jornada_periodos')) {
  /** [dia_semana => [['ini'=>'08:00','fim'=>'12:00'], ...]] */
  function ponto_jornada_periodos(PDO $pdo, int $companyId, int $jornadaId): array {
    $st = $pdo->prepare('SELECT dia_semana, hora_inicio, hora_fim FROM ponto_jornada_periodos WHERE company_id = ? AND jornada_id = ? ORDER BY dia_semana, ordem, hora_inicio');
    $st->execute([$companyId, $jornadaId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['dia_semana']][] = ['ini' => substr((string)$r['hora_inicio'], 0, 5), 'fim' => substr((string)$r['hora_fim'], 0, 5)];
    return $out;
  }
}

if (!function_exists('ponto_jornada_list')) {
  function ponto_jornada_list(PDO $pdo, int $companyId, string $status = 'ativos'): array {
    $sql = 'SELECT * FROM ponto_jornadas WHERE company_id = ?' . ($status === 'ativos' ? ' AND status = 1' : ($status === 'inativos' ? ' AND status = 0' : '')) . ' ORDER BY status DESC, descricao';
    $st = $pdo->prepare($sql);
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['periodos'] = ponto_jornada_periodos($pdo, $companyId, (int)$r['id']);
    return $rows;
  }
}

if (!function_exists('ponto_jornada_save')) {
  /**
   * $in: id, descricao, tolerancias, periodos = [['dia'=>1..7|0, 'ini'=>'08:00', 'fim'=>'12:00'], ...].
   * Calcula a carga diária (maior dia) e semanal (soma de domingo a sábado).
   */
  function ponto_jornada_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $desc = trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? '');
    if ($desc === '' || mb_strlen($desc) > 80) throw new RuntimeException('Informe a descrição da jornada (até 80 caracteres).');
    $st = $pdo->prepare('SELECT id FROM ponto_jornadas WHERE company_id = ? AND descricao = ? AND id <> ? LIMIT 1');
    $st->execute([$companyId, $desc, $id]);
    if ($st->fetchColumn()) throw new RuntimeException('Já existe uma jornada com esta descrição.');
    $tol = [];
    foreach (['tolerancia_entrada' => 'Tolerância de entrada', 'tolerancia_saida' => 'Tolerância de saída', 'tolerancia_intervalo' => 'Tolerância do intervalo'] as $k => $label) {
      $v = (int)($in[$k] ?? 0);
      if ($v < 0 || $v > 60) throw new RuntimeException($label . ': de 0 a 60 minutos.');
      $tol[$k] = $v;
    }
    $byDay = [];
    foreach ((array)($in['periodos'] ?? []) as $p) {
      $dia = (int)($p['dia'] ?? -1);
      if (trim((string)($p['ini'] ?? '')) === '' && trim((string)($p['fim'] ?? '')) === '') continue;
      if ($dia < 0 || $dia > 7) throw new RuntimeException('Dia da semana inválido.');
      $ini = ponto_time_in($p['ini'] ?? '', 'Início (' . PONTO_DIAS_CURTOS[$dia] . ')');
      $fim = ponto_time_in($p['fim'] ?? '', 'Fim (' . PONTO_DIAS_CURTOS[$dia] . ')');
      if ($ini === $fim) throw new RuntimeException('Período com início e fim iguais (' . PONTO_DIAS_CURTOS[$dia] . ' ' . substr($ini, 0, 5) . ').');
      $byDay[$dia][] = [$ini, $fim];
    }
    if (!$byDay) throw new RuntimeException('Informe pelo menos um período de trabalho.');
    if (isset($byDay[0]) && count($byDay) > 1) throw new RuntimeException('Use "Todos os dias" sozinho (para escalas como 12x36) ou os dias da semana, não os dois juntos.');
    $daily = [];
    foreach ($byDay as $dia => &$ps) {
      if (count($ps) > 4) throw new RuntimeException(PONTO_DIAS_CURTOS[$dia] . ': no máximo 4 períodos por dia.');
      usort($ps, static fn($a, $b) => strcmp($a[0], $b[0]));
      $total = 0; $prevEnd = -1;
      foreach ($ps as [$ini, $fim]) {
        $s = (int)substr($ini, 0, 2) * 60 + (int)substr($ini, 3, 2);
        $len = ponto_period_minutes($ini, $fim);
        if ($s < $prevEnd) throw new RuntimeException(PONTO_DIAS_CURTOS[$dia] . ': os períodos estão se sobrepondo.');
        $prevEnd = $s + $len;
        $total += $len;
      }
      if ($total > 1440) throw new RuntimeException(PONTO_DIAS_CURTOS[$dia] . ': mais de 24 horas no dia.');
      $daily[$dia] = $total;
    }
    unset($ps);
    $cargaDia = max($daily);
    $cargaSemana = 0;
    for ($d = 1; $d <= 7; $d++) $cargaSemana += $daily[$d] ?? 0;
    $row = ['descricao' => $desc, 'carga_diaria_minutos' => $cargaDia, 'carga_semanal_minutos' => $cargaSemana] + $tol;
    $pdo->beginTransaction();
    try {
      $before = null;
      if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM ponto_jornadas WHERE company_id = ? AND id = ? LIMIT 1');
        $st->execute([$companyId, $id]);
        $before = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$before) throw new RuntimeException('Jornada não encontrada.');
        $before['periodos'] = ponto_jornada_periodos($pdo, $companyId, $id);
        $pdo->prepare('UPDATE ponto_jornadas SET descricao = ?, carga_diaria_minutos = ?, carga_semanal_minutos = ?, tolerancia_entrada = ?, tolerancia_saida = ?, tolerancia_intervalo = ? WHERE company_id = ? AND id = ?')
          ->execute([$desc, $cargaDia, $cargaSemana, $tol['tolerancia_entrada'], $tol['tolerancia_saida'], $tol['tolerancia_intervalo'], $companyId, $id]);
        $pdo->prepare('DELETE FROM ponto_jornada_periodos WHERE company_id = ? AND jornada_id = ?')->execute([$companyId, $id]);
      } else {
        $pdo->prepare('INSERT INTO ponto_jornadas (company_id, descricao, carga_diaria_minutos, carga_semanal_minutos, tolerancia_entrada, tolerancia_saida, tolerancia_intervalo) VALUES (?,?,?,?,?,?,?)')
          ->execute([$companyId, $desc, $cargaDia, $cargaSemana, $tol['tolerancia_entrada'], $tol['tolerancia_saida'], $tol['tolerancia_intervalo']]);
        $id = (int)$pdo->lastInsertId();
      }
      $ins = $pdo->prepare('INSERT INTO ponto_jornada_periodos (company_id, jornada_id, dia_semana, ordem, hora_inicio, hora_fim) VALUES (?,?,?,?,?,?)');
      foreach ($byDay as $dia => $ps) foreach ($ps as $i => [$ini, $fim]) $ins->execute([$companyId, $id, $dia, $i + 1, $ini, $fim]);
      $after = $row + ['periodos' => ponto_jornada_periodos($pdo, $companyId, $id)];
      ponto_audit($pdo, $companyId, $before ? 'alterar' : 'criar', 'jornadas', $id, $before ? array_intersect_key($before, $after) : null, $after);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return $id;
  }
}

if (!function_exists('ponto_simple_status')) {
  /** Inativar/reativar jornada ou escala. */
  function ponto_simple_status(PDO $pdo, int $companyId, string $what, int $id, bool $active): void {
    $table = ['jornadas' => 'ponto_jornadas', 'escalas' => 'ponto_escalas'][$what] ?? '';
    if ($table === '') throw new RuntimeException('Cadastro inválido.');
    $st = $pdo->prepare('SELECT status FROM ' . $table . ' WHERE company_id = ? AND id = ?');
    $st->execute([$companyId, $id]);
    $old = $st->fetchColumn();
    if ($old === false) throw new RuntimeException('Registro não encontrado.');
    if ((int)$old === ($active ? 1 : 0)) return;
    $pdo->prepare('UPDATE ' . $table . ' SET status = ? WHERE company_id = ? AND id = ?')->execute([$active ? 1 : 0, $companyId, $id]);
    ponto_audit($pdo, $companyId, $active ? 'reativar' : 'inativar', $what, $id, ['status' => (int)$old], ['status' => $active ? 1 : 0]);
  }
}

/* ------------------------------------------------------------------------- */
/* Escalas                                                                    */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_escala_tipos')) {
  function ponto_escala_tipos(): array {
    return [
      '5X2' => ['label' => '5x2 (5 dias de trabalho, 2 de folga)', 'base' => 'SEMANA', 'ciclo' => 7],
      '6X1' => ['label' => '6x1 (6 dias de trabalho, 1 de folga)', 'base' => 'SEMANA', 'ciclo' => 7],
      '12X36' => ['label' => '12x36 (trabalha 12h, folga 36h)', 'base' => 'CICLO', 'ciclo' => 2],
      'REVEZAMENTO' => ['label' => 'Revezamento (ciclo de N dias)', 'base' => 'CICLO', 'ciclo' => 0],
      'PERSONALIZADA' => ['label' => 'Personalizada', 'base' => '', 'ciclo' => 0],
    ];
  }
}

if (!function_exists('ponto_escala_dias')) {
  /** [dia => jornada_id|null] */
  function ponto_escala_dias(PDO $pdo, int $companyId, int $escalaId): array {
    $st = $pdo->prepare('SELECT dia, jornada_id FROM ponto_escala_dias WHERE company_id = ? AND escala_id = ? ORDER BY dia');
    $st->execute([$companyId, $escalaId]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['dia']] = $r['jornada_id'] !== null ? (int)$r['jornada_id'] : null;
    return $out;
  }
}

if (!function_exists('ponto_escala_list')) {
  function ponto_escala_list(PDO $pdo, int $companyId, string $status = 'ativos'): array {
    $st = $pdo->prepare('SELECT * FROM ponto_escalas WHERE company_id = ?' . ($status === 'ativos' ? ' AND status = 1' : ($status === 'inativos' ? ' AND status = 0' : '')) . ' ORDER BY status DESC, descricao');
    $st->execute([$companyId]);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as &$r) $r['dias'] = ponto_escala_dias($pdo, $companyId, (int)$r['id']);
    return $rows;
  }
}

if (!function_exists('ponto_escala_save')) {
  /** $in: id, descricao, tipo, base (PERSONALIZADA), ciclo_dias, dias = [dia => jornada_id|'' (folga)]. */
  function ponto_escala_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $desc = trim(preg_replace('/\s+/u', ' ', (string)($in['descricao'] ?? '')) ?? '');
    if ($desc === '' || mb_strlen($desc) > 80) throw new RuntimeException('Informe a descrição da escala (até 80 caracteres).');
    $st = $pdo->prepare('SELECT id FROM ponto_escalas WHERE company_id = ? AND descricao = ? AND id <> ? LIMIT 1');
    $st->execute([$companyId, $desc, $id]);
    if ($st->fetchColumn()) throw new RuntimeException('Já existe uma escala com esta descrição.');
    $tipos = ponto_escala_tipos();
    $tipo = (string)($in['tipo'] ?? '');
    if (!isset($tipos[$tipo])) throw new RuntimeException('Tipo de escala inválido.');
    $base = $tipos[$tipo]['base'] ?: ((string)($in['base'] ?? '') === 'CICLO' ? 'CICLO' : 'SEMANA');
    $ciclo = $base === 'SEMANA' ? 7 : ($tipos[$tipo]['ciclo'] ?: (int)($in['ciclo_dias'] ?? 0));
    if ($ciclo < 2 || $ciclo > 60) throw new RuntimeException('O ciclo deve ter de 2 a 60 dias.');
    $dias = [];
    $trabalho = 0;
    for ($d = 1; $d <= $ciclo; $d++) {
      $j = (int)($in['dias'][$d] ?? 0);
      $dias[$d] = $j > 0 ? ponto_fk_check($pdo, $companyId, 'jornadas', $j, 'Jornada do dia ' . $d) : null;
      if ($dias[$d]) $trabalho++;
    }
    if ($trabalho === 0) throw new RuntimeException('Escolha a jornada de pelo menos um dia de trabalho.');
    $pdo->beginTransaction();
    try {
      $before = null;
      if ($id > 0) {
        $st = $pdo->prepare('SELECT * FROM ponto_escalas WHERE company_id = ? AND id = ? LIMIT 1');
        $st->execute([$companyId, $id]);
        $before = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        if (!$before) throw new RuntimeException('Escala não encontrada.');
        $before['dias'] = ponto_escala_dias($pdo, $companyId, $id);
        $pdo->prepare('UPDATE ponto_escalas SET descricao = ?, tipo = ?, base = ?, ciclo_dias = ? WHERE company_id = ? AND id = ?')->execute([$desc, $tipo, $base, $ciclo, $companyId, $id]);
        $pdo->prepare('DELETE FROM ponto_escala_dias WHERE company_id = ? AND escala_id = ?')->execute([$companyId, $id]);
      } else {
        $pdo->prepare('INSERT INTO ponto_escalas (company_id, descricao, tipo, base, ciclo_dias) VALUES (?,?,?,?,?)')->execute([$companyId, $desc, $tipo, $base, $ciclo]);
        $id = (int)$pdo->lastInsertId();
      }
      $ins = $pdo->prepare('INSERT INTO ponto_escala_dias (company_id, escala_id, dia, jornada_id) VALUES (?,?,?,?)');
      foreach ($dias as $d => $j) $ins->execute([$companyId, $id, $d, $j]);
      $after = ['descricao' => $desc, 'tipo' => $tipo, 'base' => $base, 'ciclo_dias' => $ciclo, 'dias' => $dias];
      ponto_audit($pdo, $companyId, $before ? 'alterar' : 'criar', 'escalas', $id, $before ? array_intersect_key($before, $after) : null, $after);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
    return $id;
  }
}

if (!function_exists('ponto_colaborador_escalas')) {
  /** Histórico de escalas do colaborador (mais recente primeiro). */
  function ponto_colaborador_escalas(PDO $pdo, int $companyId, int $colaboradorId): array {
    $st = $pdo->prepare('SELECT ce.*, e.descricao AS escala FROM ponto_colaborador_escalas ce LEFT JOIN ponto_escalas e ON e.company_id = ce.company_id AND e.id = ce.escala_id
      WHERE ce.company_id = ? AND ce.colaborador_id = ? ORDER BY ce.vigencia_inicio DESC, ce.id DESC');
    $st->execute([$companyId, $colaboradorId]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_colaborador_escala_set')) {
  /**
   * Coloca o colaborador numa escala a partir de uma data. A escala anterior não é apagada:
   * sua vigência termina no dia anterior. $escalaId = 0 encerra a escala atual (volta à jornada padrão).
   */
  function ponto_colaborador_escala_set(PDO $pdo, int $companyId, int $colaboradorId, int $escalaId, string $inicio, string $cicloInicio = '', string $obs = ''): void {
    $col = ponto_colaborador_get($pdo, $companyId, $colaboradorId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $inicio = ponto_date_in($inicio, 'Início da vigência');
    if ($inicio === '') throw new RuntimeException('Informe a data de início.');
    $hist = ponto_colaborador_escalas($pdo, $companyId, $colaboradorId);
    $atual = null;
    foreach ($hist as $h) if ($h['vigencia_fim'] === null || $h['vigencia_fim'] >= $inicio) { $atual = $h; break; }
    if ($atual && $atual['vigencia_inicio'] >= $inicio) {
      throw new RuntimeException('Já existe escala começando em ' . date('d/m/Y', strtotime($atual['vigencia_inicio'])) . '. Escolha uma data de início posterior.');
    }
    $obs = mb_substr(trim($obs), 0, 200);
    $pdo->beginTransaction();
    try {
      $ontem = date('Y-m-d', strtotime($inicio . ' -1 day'));
      if ($atual) $pdo->prepare('UPDATE ponto_colaborador_escalas SET vigencia_fim = ? WHERE company_id = ? AND id = ?')->execute([$ontem, $companyId, (int)$atual['id']]);
      if ($escalaId > 0) {
        ponto_fk_check($pdo, $companyId, 'escalas', $escalaId, 'Escala');
        $st = $pdo->prepare('SELECT base FROM ponto_escalas WHERE company_id = ? AND id = ?');
        $st->execute([$companyId, $escalaId]);
        $base = (string)$st->fetchColumn();
        $ciclo = $base === 'CICLO' ? (ponto_date_in($cicloInicio, 'Primeiro dia do ciclo') ?: $inicio) : null;
        $pdo->prepare('INSERT INTO ponto_colaborador_escalas (company_id, colaborador_id, escala_id, vigencia_inicio, ciclo_inicio, observacao, created_by, created_at) VALUES (?,?,?,?,?,?,?,?)')
          ->execute([$companyId, $colaboradorId, $escalaId, $inicio, $ciclo, $obs ?: null, function_exists('auth_user_id') ? (auth_user_id() ?: null) : null, date('Y-m-d H:i:s')]);
      } elseif (!$atual) {
        throw new RuntimeException('O colaborador não tem escala para encerrar.');
      }
      ponto_audit($pdo, $companyId, $escalaId > 0 ? 'trocar_escala' : 'encerrar_escala', 'colaboradores', $colaboradorId,
        $atual ? ['escala_id' => (int)$atual['escala_id'], 'vigencia_inicio' => $atual['vigencia_inicio']] : null,
        ['escala_id' => $escalaId ?: null, 'vigencia_inicio' => $escalaId > 0 ? $inicio : null, 'anterior_ate' => $atual ? $ontem : null, 'observacao' => $obs ?: null], $colaboradorId);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }
  }
}

if (!function_exists('ponto_feriados_ano')) {
  /** Feriados ativos do ano (com os recorrentes), guardados durante a requisição; $reset limpa. */
  function ponto_feriados_ano(PDO $pdo, int $companyId, int $year, bool $reset = false): array {
    static $cache = [];
    if ($reset) { $cache = []; return []; }
    $key = $companyId . ':' . $year;
    if (!isset($cache[$key])) {
      $st = $pdo->prepare('SELECT * FROM ponto_feriados WHERE company_id = ? AND status = 1 AND (recorrente = 1 OR YEAR(`data`) = ?)');
      $st->execute([$companyId, $year]);
      $cache[$key] = $st->fetchAll(PDO::FETCH_ASSOC);
    }
    return $cache[$key];
  }
}

if (!function_exists('ponto_feriado_do_dia')) {
  /** Feriado que vale para o colaborador na data (considera UF/cidade/estabelecimento). */
  function ponto_feriado_do_dia(PDO $pdo, int $companyId, string $date, ?array $estab): ?array {
    $md = substr($date, 5);
    foreach (ponto_feriados_ano($pdo, $companyId, (int)substr($date, 0, 4)) as $f) {
      $hit = (int)$f['recorrente'] === 1 ? substr((string)$f['data'], 5) === $md : (string)$f['data'] === $date;
      if (!$hit) continue;
      if (!empty($f['estabelecimento_id']) && (int)$f['estabelecimento_id'] !== (int)($estab['id'] ?? 0)) continue;
      $uf = strtoupper((string)($estab['uf'] ?? ''));
      if ($f['tipo'] === 'ESTADUAL' && $uf !== '' && strtoupper((string)$f['uf']) !== $uf) continue;
      if ($f['tipo'] === 'MUNICIPAL') {
        $norm = static fn($s) => mb_strtolower(trim((string)$s));
        if ($uf !== '' && (strtoupper((string)$f['uf']) !== $uf || $norm($f['cidade']) !== $norm($estab['cidade'] ?? ''))) continue;
      }
      return $f;
    }
    return null;
  }
}

if (!function_exists('ponto_previsto_do_dia')) {
  /**
   * O que está previsto para o colaborador na data: escala vigente -> jornada do dia; sem escala ->
   * jornada padrão. Retorna ['trabalha'=>bool, 'jornada_id', 'periodos'=>[[ini,fim]], 'minutos',
   * 'origem'=>'escala'|'jornada'|'sem_jornada', 'feriado'=>descrição|null, 'escala'=>descrição|null].
   * Feriado: dia de folga nas escalas semanais e na jornada padrão; nas de ciclo (12x36) segue o ciclo.
   */
  function ponto_previsto_do_dia(PDO $pdo, int $companyId, array $col, string $date): array {
    static $jornadas = [], $escalas = [], $estabs = [];
    $out = ['trabalha' => false, 'jornada_id' => null, 'periodos' => [], 'minutos' => 0, 'origem' => 'sem_jornada', 'feriado' => null, 'escala' => null];
    $colId = (int)$col['id'];
    $st = $pdo->prepare('SELECT * FROM ponto_colaborador_escalas WHERE company_id = ? AND colaborador_id = ? AND vigencia_inicio <= ? AND (vigencia_fim IS NULL OR vigencia_fim >= ?) ORDER BY vigencia_inicio DESC LIMIT 1');
    $st->execute([$companyId, $colId, $date, $date]);
    $vig = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    $weekday = (int)date('w', strtotime($date)) + 1;
    $jornadaId = null; $ciclo = false;
    if ($vig) {
      $eid = (int)$vig['escala_id'];
      if (!isset($escalas[$eid])) {
        $s = $pdo->prepare('SELECT * FROM ponto_escalas WHERE company_id = ? AND id = ?');
        $s->execute([$companyId, $eid]);
        $escalas[$eid] = ($s->fetch(PDO::FETCH_ASSOC) ?: []) + ['dias' => ponto_escala_dias($pdo, $companyId, $eid)];
      }
      $esc = $escalas[$eid];
      $out['escala'] = $esc['descricao'] ?? null;
      $out['origem'] = 'escala';
      if (($esc['base'] ?? 'SEMANA') === 'CICLO') {
        $ciclo = true;
        $n = max(1, (int)$esc['ciclo_dias']);
        $start = (string)($vig['ciclo_inicio'] ?: $vig['vigencia_inicio']);
        $diff = (int)floor((strtotime($date . ' 12:00:00') - strtotime($start . ' 12:00:00')) / 86400);
        $dia = (($diff % $n) + $n) % $n + 1;
      } else {
        $dia = $weekday;
      }
      $jornadaId = $esc['dias'][$dia] ?? null;
    } elseif (!empty($col['jornada_id'])) {
      $jornadaId = (int)$col['jornada_id'];
      $out['origem'] = 'jornada';
    }
    if ($jornadaId) {
      if (!isset($jornadas[$jornadaId])) $jornadas[$jornadaId] = ponto_jornada_periodos($pdo, $companyId, $jornadaId);
      $per = $jornadas[$jornadaId][$weekday] ?? ($jornadas[$jornadaId][0] ?? []);
      if ($per) {
        $out['trabalha'] = true;
        $out['jornada_id'] = $jornadaId;
        $out['periodos'] = $per;
        foreach ($per as $p) $out['minutos'] += ponto_period_minutes($p['ini'], $p['fim']);
      }
    }
    $eid = (int)($col['estabelecimento_id'] ?? 0);
    if (!array_key_exists($eid, $estabs)) {
      $s = $pdo->prepare('SELECT id, uf, cidade FROM ponto_estabelecimentos WHERE company_id = ? AND id = ?');
      $s->execute([$companyId, $eid]);
      $estabs[$eid] = $s->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    $fer = ponto_feriado_do_dia($pdo, $companyId, $date, $estabs[$eid]);
    if ($fer) {
      $out['feriado'] = (string)$fer['descricao'];
      if (!$ciclo) { $out['trabalha'] = false; $out['periodos'] = []; $out['minutos'] = 0; }
    }
    return $out;
  }
}

/* ------------------------------------------------------------------------- */
/* Feriados nacionais                                                         */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_pascoa')) {
  /** Domingo de Páscoa (algoritmo de Meeus/Jones/Butcher). */
  function ponto_pascoa(int $y): string {
    $a = $y % 19; $b = intdiv($y, 100); $c = $y % 100; $d = intdiv($b, 4); $e = $b % 4;
    $f = intdiv($b + 8, 25); $g = intdiv($b - $f + 1, 3); $h = (19 * $a + $b - $d - $g + 15) % 30;
    $i = intdiv($c, 4); $k = $c % 4; $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7; $m = intdiv($a + 11 * $h + 22 * $l, 451);
    $month = intdiv($h + $l - 7 * $m + 114, 31); $day = (($h + $l - 7 * $m + 114) % 31) + 1;
    return sprintf('%04d-%02d-%02d', $y, $month, $day);
  }
}

if (!function_exists('ponto_feriados_nacionais')) {
  /** Feriados nacionais do ano; com $facultativos inclui Carnaval e Corpus Christi (pontos facultativos). */
  function ponto_feriados_nacionais(int $y, bool $facultativos = false): array {
    $p = strtotime(ponto_pascoa($y) . ' 12:00:00');
    $list = [
      ['data' => "$y-01-01", 'descricao' => 'Confraternização Universal', 'fixo' => true],
      ['data' => date('Y-m-d', $p - 2 * 86400), 'descricao' => 'Paixão de Cristo', 'fixo' => false],
      ['data' => "$y-04-21", 'descricao' => 'Tiradentes', 'fixo' => true],
      ['data' => "$y-05-01", 'descricao' => 'Dia do Trabalho', 'fixo' => true],
      ['data' => "$y-09-07", 'descricao' => 'Independência do Brasil', 'fixo' => true],
      ['data' => "$y-10-12", 'descricao' => 'Nossa Senhora Aparecida', 'fixo' => true],
      ['data' => "$y-11-02", 'descricao' => 'Finados', 'fixo' => true],
      ['data' => "$y-11-15", 'descricao' => 'Proclamação da República', 'fixo' => true],
      ['data' => "$y-11-20", 'descricao' => 'Dia Nacional de Zumbi e da Consciência Negra', 'fixo' => true],
      ['data' => "$y-12-25", 'descricao' => 'Natal', 'fixo' => true],
    ];
    if ($facultativos) {
      $list[] = ['data' => date('Y-m-d', $p - 48 * 86400), 'descricao' => 'Carnaval (segunda-feira) - ponto facultativo', 'fixo' => false, 'tipo' => 'INSTITUCIONAL'];
      $list[] = ['data' => date('Y-m-d', $p - 47 * 86400), 'descricao' => 'Carnaval (terça-feira) - ponto facultativo', 'fixo' => false, 'tipo' => 'INSTITUCIONAL'];
      $list[] = ['data' => date('Y-m-d', $p + 60 * 86400), 'descricao' => 'Corpus Christi - ponto facultativo', 'fixo' => false, 'tipo' => 'INSTITUCIONAL'];
    }
    usort($list, static fn($a, $b) => strcmp($a['data'], $b['data']));
    return $list;
  }
}

if (!function_exists('ponto_feriados_importar')) {
  /** Cadastra os feriados nacionais do ano que ainda não existem. Retorna quantos foram criados. */
  function ponto_feriados_importar(PDO $pdo, int $companyId, int $year, bool $facultativos): int {
    if ($year < 2000 || $year > 2100) throw new RuntimeException('Ano inválido.');
    $n = 0;
    foreach (ponto_feriados_nacionais($year, $facultativos) as $f) {
      if ($f['fixo']) {
        $st = $pdo->prepare("SELECT 1 FROM ponto_feriados WHERE company_id = ? AND ((recorrente = 1 AND DATE_FORMAT(`data`, '%m-%d') = ?) OR `data` = ?) LIMIT 1");
        $st->execute([$companyId, substr($f['data'], 5), $f['data']]);
      } else {
        $st = $pdo->prepare('SELECT 1 FROM ponto_feriados WHERE company_id = ? AND `data` = ? LIMIT 1');
        $st->execute([$companyId, $f['data']]);
      }
      if ($st->fetchColumn()) continue;
      ponto_entity_save($pdo, $companyId, 'feriados', ['descricao' => $f['descricao'], 'data' => $f['data'], 'tipo' => $f['tipo'] ?? 'NACIONAL', 'recorrente' => $f['fixo'] ? 1 : 0]);
      $n++;
    }
    return $n;
  }
}

/* ------------------------------------------------------------------------- */
/* Colaboradores                                                              */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_colaborador_get')) {
  function ponto_colaborador_get(PDO $pdo, int $companyId, int $id): ?array {
    $st = $pdo->prepare('SELECT * FROM ponto_colaboradores WHERE company_id = ? AND id = ? LIMIT 1');
    $st->execute([$companyId, $id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
  }
}

if (!function_exists('ponto_colaborador_list')) {
  function ponto_colaborador_list(PDO $pdo, int $companyId, array $f = []): array {
    $where = ['c.company_id = ?']; $params = [$companyId];
    $status = (string)($f['status'] ?? 'ATIVOS');
    if ($status === 'ATIVOS') $where[] = "c.status <> 'DESLIGADO'";
    elseif (in_array($status, ['ATIVO', 'AFASTADO', 'DESLIGADO'], true)) { $where[] = 'c.status = ?'; $params[] = $status; }
    foreach (['departamento_id', 'estabelecimento_id', 'cargo_id'] as $k) if (!empty($f[$k])) { $where[] = 'c.' . $k . ' = ?'; $params[] = (int)$f[$k]; }
    $q = trim((string)($f['q'] ?? ''));
    if ($q !== '') {
      $d = ponto_digits($q);
      $parts = ['c.nome LIKE ?', 'c.matricula LIKE ?', 'c.email LIKE ?', 'c.codigo_interno LIKE ?'];
      array_push($params, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
      if ($d !== '') { $parts[] = 'c.cpf LIKE ?'; $params[] = '%' . $d . '%'; }
      $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    $st = $pdo->prepare('SELECT c.*, d.descricao AS departamento, g.descricao AS cargo, j.descricao AS jornada,
        COALESCE(NULLIF(e.nome_fantasia, \'\'), e.razao_social) AS estabelecimento,
        (SELECT es.descricao FROM ponto_colaborador_escalas ce JOIN ponto_escalas es ON es.company_id = ce.company_id AND es.id = ce.escala_id
          WHERE ce.company_id = c.company_id AND ce.colaborador_id = c.id AND ce.vigencia_inicio <= CURRENT_DATE AND (ce.vigencia_fim IS NULL OR ce.vigencia_fim >= CURRENT_DATE)
          ORDER BY ce.vigencia_inicio DESC LIMIT 1) AS escala
      FROM ponto_colaboradores c
      LEFT JOIN ponto_departamentos d ON d.company_id = c.company_id AND d.id = c.departamento_id
      LEFT JOIN ponto_cargos g ON g.company_id = c.company_id AND g.id = c.cargo_id
      LEFT JOIN ponto_jornadas j ON j.company_id = c.company_id AND j.id = c.jornada_id
      LEFT JOIN ponto_estabelecimentos e ON e.company_id = c.company_id AND e.id = c.estabelecimento_id
      WHERE ' . implode(' AND ', $where) . ' ORDER BY c.nome LIMIT 1000');
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
  }
}

if (!function_exists('ponto_rfid_hash')) {
  /** O código do cartão nunca é guardado: só este hash (para localizar) e os 4 últimos caracteres. */
  function ponto_rfid_hash(int $companyId, string $code): string {
    return hash('sha256', 'nexa-ponto-rfid|' . $companyId . '|' . strtoupper(preg_replace('/\s+/', '', $code) ?? ''));
  }
}

if (!function_exists('ponto_colaborador_next_matricula')) {
  function ponto_colaborador_next_matricula(PDO $pdo, int $companyId): string {
    $st = $pdo->prepare("SELECT matricula FROM ponto_colaboradores WHERE company_id = ? AND matricula REGEXP '^[0-9]+$' ORDER BY CAST(matricula AS UNSIGNED) DESC LIMIT 1");
    $st->execute([$companyId]);
    $last = (int)($st->fetchColumn() ?: 0);
    return str_pad((string)($last + 1), 4, '0', STR_PAD_LEFT);
  }
}

if (!function_exists('ponto_colaborador_save')) {
  function ponto_colaborador_save(PDO $pdo, int $companyId, array $in): int {
    $id = (int)($in['id'] ?? 0);
    $old = $id > 0 ? ponto_colaborador_get($pdo, $companyId, $id) : null;
    if ($id > 0 && !$old) throw new RuntimeException('Colaborador não encontrado.');
    $txt = static function ($v, int $max, string $label): ?string {
      $v = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? '');
      if (mb_strlen($v) > $max) throw new RuntimeException($label . ': máximo de ' . $max . ' caracteres.');
      return $v !== '' ? $v : null;
    };
    $d = [];
    $d['nome'] = $txt($in['nome'] ?? '', 120, 'Nome');
    if (!$d['nome']) throw new RuntimeException('Informe o nome do colaborador.');
    $d['cpf'] = ponto_digits($in['cpf'] ?? '');
    if (!ponto_cpf_valid($d['cpf'])) throw new RuntimeException('CPF inválido. Ele é obrigatório: identifica o trabalhador nos arquivos legais do ponto.');
    $d['matricula'] = $txt($in['matricula'] ?? '', 20, 'Matrícula') ?? ($old['matricula'] ?? ponto_colaborador_next_matricula($pdo, $companyId));
    $d['data_nascimento'] = ponto_date_in($in['data_nascimento'] ?? '', 'Data de nascimento') ?: null;
    $d['data_admissao'] = ponto_date_in($in['data_admissao'] ?? '', 'Data de admissão');
    if ($d['data_admissao'] === '') throw new RuntimeException('Informe a data de admissão.');
    $d['data_demissao'] = ponto_date_in($in['data_demissao'] ?? '', 'Data de desligamento') ?: null;
    if ($d['data_demissao'] && $d['data_demissao'] < $d['data_admissao']) throw new RuntimeException('A data de desligamento é anterior à admissão.');
    $d['status'] = in_array((string)($in['status'] ?? 'ATIVO'), ['ATIVO', 'AFASTADO', 'DESLIGADO'], true) ? (string)($in['status'] ?? 'ATIVO') : 'ATIVO';
    if ($d['status'] === 'DESLIGADO' && !$d['data_demissao']) throw new RuntimeException('Para marcar como desligado, informe a data de desligamento.');
    if ($d['data_demissao'] && $d['status'] !== 'DESLIGADO' && $d['data_demissao'] <= date('Y-m-d')) $d['status'] = 'DESLIGADO';
    $d['estabelecimento_id'] = ponto_fk_check($pdo, $companyId, 'estabelecimentos', (int)($in['estabelecimento_id'] ?? 0), 'Estabelecimento');
    $d['cargo_id'] = ponto_fk_check($pdo, $companyId, 'cargos', (int)($in['cargo_id'] ?? 0), 'Cargo');
    $d['departamento_id'] = ponto_fk_check($pdo, $companyId, 'departamentos', (int)($in['departamento_id'] ?? 0), 'Departamento');
    $d['jornada_id'] = ponto_fk_check($pdo, $companyId, 'jornadas', (int)($in['jornada_id'] ?? 0), 'Jornada');
    $d['local_id'] = ponto_fk_check($pdo, $companyId, 'locais', (int)($in['local_id'] ?? 0), 'Local de trabalho');
    $d['email'] = $txt($in['email'] ?? '', 150, 'E-mail');
    if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail inválido.');
    $tel = ponto_digits($in['telefone'] ?? '');
    if ($tel !== '' && (strlen($tel) < 10 || strlen($tel) > 13)) throw new RuntimeException('Telefone: informe com DDD.');
    $d['telefone'] = $tel ?: null;
    $d['codigo_interno'] = $txt($in['codigo_interno'] ?? '', 30, 'Código interno');
    foreach (['controla_ponto', 'permite_ponto_mobile', 'permite_ponto_externo'] as $k) $d[$k] = empty($in[$k]) ? 0 : 1;
    // Banco de horas (V5): só quando o formulário traz o campo e a coluna já existe.
    if (isset($in['banco_horas_form']) && ponto_has_column($pdo, 'ponto_colaboradores', 'banco_horas')) $d['banco_horas'] = empty($in['banco_horas']) ? 0 : 1;
    if (isset($in['acesso_livre_form']) && ponto_has_column($pdo, 'ponto_colaboradores', 'acesso_livre')) $d['acesso_livre'] = empty($in['acesso_livre']) ? 0 : 1;
    // Usuário do Nexa (opcional): precisa ser desta empresa e não estar ligado a outro colaborador.
    $uid = (int)($in['usuario_id'] ?? 0);
    if ($uid > 0 && !isset(ponto_company_users($pdo, $companyId)[$uid])) throw new RuntimeException('Usuário do Nexa não encontrado nesta empresa.');
    $d['usuario_id'] = $uid ?: null;
    // Cartão RFID: '' mantém; '-' remove; outro valor troca.
    $rfid = trim((string)($in['rfid'] ?? ''));
    if ($rfid === '-') { $d['rfid_hash'] = null; $d['rfid_final'] = null; }
    elseif ($rfid !== '') {
      if (!preg_match('/^[0-9A-Za-z]{4,40}$/', $rfid)) throw new RuntimeException('Cartão RFID: use só letras e números (4 a 40).');
      $d['rfid_hash'] = ponto_rfid_hash($companyId, $rfid);
      $d['rfid_final'] = strtoupper(substr($rfid, -4));
    }
    foreach (['cpf' => 'CPF', 'matricula' => 'matrícula', 'usuario_id' => 'usuário do Nexa', 'rfid_hash' => 'cartão RFID'] as $k => $label) {
      if (!array_key_exists($k, $d) || $d[$k] === null) continue;
      $st = $pdo->prepare('SELECT nome FROM ponto_colaboradores WHERE company_id = ? AND ' . $k . ' = ? AND id <> ? LIMIT 1');
      $st->execute([$companyId, $d[$k], $id]);
      $other = $st->fetchColumn();
      if ($other !== false) throw new RuntimeException('Este ' . $label . ' já está no cadastro de ' . $other . '.');
    }
    if ($old) {
      $changes = [];
      foreach ($d as $k => $v) if ((string)($old[$k] ?? '') !== (string)($v ?? '')) $changes[$k] = $v;
      if (!$changes) return $id;
      $sets = implode(', ', array_map(static fn($c) => $c . ' = ?', array_keys($changes)));
      $pdo->prepare('UPDATE ponto_colaboradores SET ' . $sets . ' WHERE company_id = ? AND id = ?')->execute(array_merge(array_values($changes), [$companyId, $id]));
      ponto_audit($pdo, $companyId, 'alterar', 'colaboradores', $id, array_intersect_key($old, $changes), $changes, $id);
      // REP-P (V6): inclusão/alteração/exclusão do empregado no ARP (registro tipo 5 do AFD).
      if (function_exists('ponto_arp_colaborador_alterado')) ponto_arp_colaborador_alterado($pdo, $companyId, $old, ponto_colaborador_get($pdo, $companyId, $id));
      // V10 (LGPD): desligado = o rosto é apagado e sai dos aparelhos.
      if (($d['status'] ?? null) === 'DESLIGADO' && ($old['status'] ?? '') !== 'DESLIGADO' && function_exists('ponto_biometria_revogar')) {
        try { ponto_biometria_revogar($pdo, $companyId, $id, function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0, 'Colaborador desligado'); } catch (Throwable $e) { /* sem rosto cadastrado */ }
      }
      return $id;
    }
    $cols = array_keys($d);
    $pdo->prepare('INSERT INTO ponto_colaboradores (company_id, ' . implode(', ', $cols) . ') VALUES (?' . str_repeat(', ?', count($cols)) . ')')
      ->execute(array_merge([$companyId], array_values($d)));
    $newId = (int)$pdo->lastInsertId();
    ponto_audit($pdo, $companyId, 'criar', 'colaboradores', $newId, null, array_filter($d, static fn($v) => $v !== null && $v !== ''), $newId);
    if (function_exists('ponto_arp_colaborador_alterado')) ponto_arp_colaborador_alterado($pdo, $companyId, null, ponto_colaborador_get($pdo, $companyId, $newId));
    return $newId;
  }
}

/* ------------------------------------------------------------------------- */
/* Foto do colaborador (fora da pasta pública)                                */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_storage_root')) {
  function ponto_storage_root(): string {
    // Uma pasta por instalação (nexa / nexa-homolog): a homologação usa cópia do banco e
    // não pode mexer nos arquivos da produção.
    $base = trim((string)(getenv('NEXA_PONTO_DIR') ?: ''));
    if ($base === '') $base = dirname(__DIR__, 2) . '/nexa_private/ponto/' . (preg_replace('/[^A-Za-z0-9_.-]/', '', basename(dirname(__DIR__))) ?: 'nexa');
    if (!is_dir($base) && !@mkdir($base, 0750, true) && !is_dir($base)) {
      throw new RuntimeException('Não foi possível criar a pasta de arquivos do Ponto. Peça ao suporte para criar ' . $base . ' com permissão para o servidor web.');
    }
    if (!is_file($base . '/.htaccess')) @file_put_contents($base . '/.htaccess', "Require all denied\n");
    return rtrim($base, '/');
  }
}

if (!function_exists('ponto_foto_file')) {
  /** Caminho do arquivo da foto atual (ou null). */
  function ponto_foto_file(array $col): ?string {
    $rel = (string)($col['foto_path'] ?? '');
    if ($rel === '' || !preg_match('#^\d+/fotos/[A-Za-z0-9_.-]+\.jpg$#', $rel)) return null;
    $path = ponto_storage_root() . '/' . $rel;
    return is_file($path) ? $path : null;
  }
}

if (!function_exists('ponto_foto_save')) {
  /**
   * Grava a foto (upload ou câmera, já recortada na tela). A imagem é aberta e regravada
   * como JPEG de até 800 px (remove dados ocultos/EXIF). A anterior vai para o histórico.
   */
  function ponto_foto_save(PDO $pdo, int $companyId, int $colaboradorId, string $tmpFile): array {
    $col = ponto_colaborador_get($pdo, $companyId, $colaboradorId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    $size = is_file($tmpFile) ? (int)filesize($tmpFile) : 0;
    if ($size <= 0 || $size > PONTO_FOTO_MAX_BYTES) throw new RuntimeException('A foto deve ter no máximo 8 MB.');
    $info = @getimagesize($tmpFile);
    if (!$info || !in_array((int)$info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) throw new RuntimeException('Envie uma imagem JPG, PNG ou WEBP.');
    if (!function_exists('imagecreatefromstring')) throw new RuntimeException('O servidor não tem a biblioteca de imagens (GD) instalada.');
    $src = @imagecreatefromstring((string)file_get_contents($tmpFile));
    if (!$src) throw new RuntimeException('Não foi possível abrir a imagem.');
    $w = imagesx($src); $h = imagesy($src);
    $scale = min(1.0, 800 / max($w, $h));
    $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    $root = ponto_storage_root();
    $dir = $root . '/' . $companyId . '/fotos';
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) throw new RuntimeException('Não foi possível criar a pasta das fotos.');
    $name = $colaboradorId . '_' . date('Ymd_His') . '_' . bin2hex(random_bytes(3)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $name, 86)) throw new RuntimeException('Não foi possível gravar a foto.');
    imagedestroy($src); imagedestroy($dst);
    $hash = hash_file('sha256', $dir . '/' . $name);
    ponto_foto_archive($col);
    $now = date('Y-m-d H:i:s');
    $rel = $companyId . '/fotos/' . $name;
    $pdo->prepare('UPDATE ponto_colaboradores SET foto_path = ?, foto_hash = ?, foto_mime = ?, foto_updated_at = ? WHERE company_id = ? AND id = ?')
      ->execute([$rel, $hash, 'image/jpeg', $now, $companyId, $colaboradorId]);
    ponto_audit($pdo, $companyId, $col['foto_path'] ? 'trocar_foto' : 'incluir_foto', 'colaboradores', $colaboradorId,
      $col['foto_hash'] ? ['foto_hash' => $col['foto_hash']] : null, ['foto_hash' => $hash], $colaboradorId);
    // V10: foto nova vai para os aparelhos que recebem rostos (só com o consentimento em vigor).
    if (function_exists('ponto_equip_rostos_enfileirar')) { try { ponto_equip_rostos_enfileirar($pdo, $companyId, null, $colaboradorId, 'FOTO'); } catch (Throwable $e) { error_log('[Nexa Ponto] ' . $e->getMessage()); } }
    return ['foto_hash' => $hash, 'foto_updated_at' => $now];
  }
}

if (!function_exists('ponto_foto_archive')) {
  /** Move a foto atual para fotos/historico (não apaga: a troca fica comprovável). */
  function ponto_foto_archive(array $col): void {
    $file = ponto_foto_file($col);
    if (!$file) return;
    $hist = dirname($file) . '/historico';
    if (!is_dir($hist)) @mkdir($hist, 0750, true);
    @rename($file, $hist . '/' . basename($file));
  }
}

if (!function_exists('ponto_foto_remove')) {
  function ponto_foto_remove(PDO $pdo, int $companyId, int $colaboradorId, string $motivo): void {
    $col = ponto_colaborador_get($pdo, $companyId, $colaboradorId);
    if (!$col) throw new RuntimeException('Colaborador não encontrado.');
    if (empty($col['foto_path'])) return;
    $motivo = mb_substr(trim($motivo), 0, 200);
    if ($motivo === '') throw new RuntimeException('Informe o motivo da remoção da foto.');
    ponto_foto_archive($col);
    $pdo->prepare('UPDATE ponto_colaboradores SET foto_path = NULL, foto_hash = NULL, foto_mime = NULL, foto_updated_at = ? WHERE company_id = ? AND id = ?')
      ->execute([date('Y-m-d H:i:s'), $companyId, $colaboradorId]);
    ponto_audit($pdo, $companyId, 'remover_foto', 'colaboradores', $colaboradorId, ['foto_hash' => $col['foto_hash']], ['motivo' => $motivo], $colaboradorId);
  }
}

/* ------------------------------------------------------------------------- */
/* Geolocalização (usada pelos locais e, na próxima versão, pela marcação)    */
/* ------------------------------------------------------------------------- */

if (!function_exists('ponto_geo_distance')) {
  /** Distância em metros entre dois pontos (fórmula de Haversine). */
  function ponto_geo_distance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $r = 6371000.0;
    $p1 = deg2rad($lat1); $p2 = deg2rad($lat2);
    $dp = deg2rad($lat2 - $lat1); $dl = deg2rad($lon2 - $lon1);
    $a = sin($dp / 2) ** 2 + cos($p1) * cos($p2) * sin($dl / 2) ** 2;
    return 2 * $r * asin(min(1.0, sqrt($a)));
  }
}
