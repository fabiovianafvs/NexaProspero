<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Fundação Multiempresa.
 *
 * Fundação Multiempresa + Contexto Seguro V8.
 *
 * Compatibilidade desta etapa:
 * - NÃO altera COMPANY_ID global;
 * - o contexto multiempresa fica separado em MULTI_COMPANY_CONTEXT_ID;
 * - tokens de contexto são opacos, vinculados à sessão/usuário e independentes por aba;
 * - não executa DDL;
 * - somente escopos explicitamente homologados podem usar o contexto operacional;
 * - V5 homologou o Dashboard;
 * - V6 homologou consulta de Produtos e Clientes;
 * - V7 homologou gravação protegida dos cadastros básicos de Produtos e Clientes;
 * - V8 homologa o bloco fiscal/tributário do cadastro de Produtos;
 * - V9 homologa somente leitura de saldo e histórico de Estoque no contexto F11;
 * - V9.1/V9.2 homologam gravação básica e conferência de Estoque no contexto F11;
 * - V10 homologa Entrada de Mercadorias por XML no contexto F11;
 * - V10.1 homologa Entrada Manual de Mercadorias no contexto F11, ainda sem Contas a Pagar;
 * - V10.2 homologa Devolução de Cliente / Outras Entradas no contexto F11;
 * - V10.3 homologa Devolução a Fornecedor / Saída de Estoque no contexto F11, sem emissão fiscal;
 * - V11 homologa somente LEITURA do Financeiro (dashboard, receber, pagar, movimentos e resultado) no contexto F11;
 * - V11.1 homologa criação e edição protegida de títulos em Contas a Receber/Pagar no contexto F11;
 * - V11.2/V11.3 homologam baixas/estornos protegidos e cadastro básico de Caixa/Banco;
 * - V11.4 homologa Plano de Contas, Centro de Custo e Fornecedores financeiros no contexto F11;
 * - V11.24 homologa leitura do Dashboard de Vendas e da Central de Pedidos no contexto F11;
 * - PDV, Caixa operacional, Comandas, Pedido Detalhado e emissão fiscal continuam protegidos;
 * - sem token válido, ou com o módulo Multiempresa desligado, tudo permanece no fluxo legado.
 */

if (!function_exists('multi_company_table_exists')) {
  function multi_company_table_exists(PDO $pdo, string $table): bool {
    if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) return false;
    static $cache = [];
    $key = spl_object_id($pdo) . ':' . strtolower($table);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
      $st->execute([$table]);
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('multi_company_schema_ready')) {
  function multi_company_schema_ready(PDO $pdo): bool {
    foreach (['business_groups','business_group_companies','business_group_profiles','business_group_users','user_company_access'] as $table) {
      if (!multi_company_table_exists($pdo, $table)) return false;
    }
    return true;
  }
}

if (!function_exists('multi_company_group_by_company')) {
  function multi_company_group_by_company(PDO $pdo, int $companyId): ?array {
    if ($companyId <= 0 || !multi_company_schema_ready($pdo)) return null;
    try {
      $st = $pdo->prepare("\n        SELECT g.*, gc.is_primary AS company_is_primary\n        FROM business_group_companies gc\n        INNER JOIN business_groups g ON g.id = gc.business_group_id\n        WHERE gc.company_id = ? AND gc.is_active = 1 AND g.is_active = 1\n        LIMIT 1\n      ");
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('multi_company_group_companies')) {
  function multi_company_group_companies(PDO $pdo, int $businessGroupId, bool $onlyActive = true): array {
    if ($businessGroupId <= 0 || !multi_company_schema_ready($pdo)) return [];
    try {
      $sql = "\n        SELECT c.id, c.name, c.slug, c.status, gc.is_primary, gc.is_active\n        FROM business_group_companies gc\n        INNER JOIN companies c ON c.id = gc.company_id\n        WHERE gc.business_group_id = ?\n      ";
      if ($onlyActive) $sql .= " AND gc.is_active = 1";
      $sql .= " ORDER BY gc.is_primary DESC, c.name ASC, c.id ASC";
      $st = $pdo->prepare($sql);
      $st->execute([$businessGroupId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('multi_company_user_access_rows')) {
  function multi_company_user_access_rows(PDO $pdo, int $userId): array {
    if ($userId <= 0 || !multi_company_schema_ready($pdo)) return [];
    try {
      $st = $pdo->prepare("\n        SELECT uca.user_id, uca.company_id, uca.company_profile_id, uca.is_default, uca.is_active,\n               c.name AS company_name, c.slug AS company_slug, c.status AS company_status,\n               gc.business_group_id, g.name AS business_group_name, g.operation_mode\n        FROM user_company_access uca\n        INNER JOIN companies c ON c.id = uca.company_id\n        LEFT JOIN business_group_companies gc ON gc.company_id = c.id AND gc.is_active = 1\n        LEFT JOIN business_groups g ON g.id = gc.business_group_id AND g.is_active = 1\n        WHERE uca.user_id = ? AND uca.is_active = 1\n        ORDER BY uca.is_default DESC, c.name ASC, c.id ASC\n      ");
      $st->execute([$userId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('multi_company_user_can_access')) {
  function multi_company_user_can_access(PDO $pdo, int $userId, int $companyId, ?int $legacyCompanyId = null): bool {
    if ($userId <= 0 || $companyId <= 0) return false;

    // Compatibilidade: a empresa original do usuário continua autorizada mesmo antes da migração.
    if ($legacyCompanyId !== null && $legacyCompanyId > 0 && $legacyCompanyId === $companyId) return true;
    if (!multi_company_schema_ready($pdo)) return false;

    try {
      $st = $pdo->prepare('SELECT 1 FROM user_company_access WHERE user_id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
      $st->execute([$userId, $companyId]);
      return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return false;
    }
  }
}

if (!function_exists('multi_company_user_group_profile_id')) {
  function multi_company_user_group_profile_id(PDO $pdo, int $userId, int $businessGroupId): ?int {
    if ($userId <= 0 || $businessGroupId <= 0 || !multi_company_schema_ready($pdo)) return null;
    try {
      $st = $pdo->prepare("\n        SELECT group_profile_id\n        FROM business_group_users\n        WHERE business_group_id = ? AND user_id = ? AND is_active = 1\n        LIMIT 1\n      ");
      $st->execute([$businessGroupId, $userId]);
      $value = $st->fetchColumn();
      return ($value === false || $value === null) ? null : (int)$value;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('multi_company_group_profile_permissions')) {
  function multi_company_group_profile_permissions(PDO $pdo, int $businessGroupId, int $profileId): array {
    if ($businessGroupId <= 0 || $profileId <= 0 || !multi_company_schema_ready($pdo)) return [];
    try {
      $st = $pdo->prepare("\n        SELECT permissions_json\n        FROM business_group_profiles\n        WHERE id = ? AND business_group_id = ? AND is_active = 1\n        LIMIT 1\n      ");
      $st->execute([$profileId, $businessGroupId]);
      $arr = json_decode((string)($st->fetchColumn() ?: ''), true);
      return is_array($arr) ? array_values(array_unique(array_filter(array_map('strval', $arr)))) : [];
    } catch (Throwable $e) {
      return [];
    }
  }
}

if (!function_exists('multi_company_group_profiles')) {
  function multi_company_group_profiles(PDO $pdo, int $businessGroupId, bool $onlyActive = true): array {
    if ($businessGroupId <= 0 || !multi_company_schema_ready($pdo)) return [];
    try {
      $sql = 'SELECT id, business_group_id, name, description, permissions_json, is_active, created_at, updated_at FROM business_group_profiles WHERE business_group_id = ?';
      if ($onlyActive) $sql .= ' AND is_active = 1';
      $sql .= ' ORDER BY name, id';
      $st = $pdo->prepare($sql);
      $st->execute([$businessGroupId]);
      return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { return []; }
  }
}

if (!function_exists('multi_company_user_group_config')) {
  function multi_company_user_group_config(PDO $pdo, int $businessGroupId, int $userId): ?array {
    if ($businessGroupId <= 0 || $userId <= 0 || !multi_company_schema_ready($pdo)) return null;
    try {
      $st = $pdo->prepare('SELECT business_group_id, user_id, group_profile_id, is_active FROM business_group_users WHERE business_group_id = ? AND user_id = ? LIMIT 1');
      $st->execute([$businessGroupId, $userId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) { return null; }
  }
}

/**
 * Feature flag comercial do Multiempresa.
 *
 * Nesta V2.1 a função é somente consultiva: nenhuma troca de empresa é ativada
 * automaticamente. A V3 usará esta chave antes de habilitar contexto/F11.
 */
if (!function_exists('multi_company_feature_enabled')) {
  function multi_company_feature_enabled(PDO $pdo, int $companyId): bool {
    if ($companyId <= 0) return false;
    try {
      if (!function_exists('saas_company_has_module')) {
        require_once __DIR__ . '/saas_modules.php';
      }
      return function_exists('saas_company_has_module')
        ? saas_company_has_module($pdo, $companyId, 'multiempresa')
        : false;
    } catch (Throwable $e) {
      // Fail closed: em qualquer inconsistência, Multiempresa permanece desligado.
      return false;
    }
  }
}

if (!function_exists('multi_company_user_feature_companies')) {
  function multi_company_user_feature_companies(PDO $pdo, int $userId): array {
    $rows = multi_company_user_access_rows($pdo, $userId);
    if (!$rows) return [];
    return array_values(array_filter($rows, static function(array $row) use ($pdo): bool {
      return multi_company_feature_enabled($pdo, (int)($row['company_id'] ?? 0));
    }));
  }
}

/**
 * ------------------------------
 * Contexto Seguro Multiempresa V3
 * ------------------------------
 *
 * Nesta versão o contexto é SOMENTE preparado/validado. O COMPANY_ID legado
 * continua sendo a empresa resolvida pelo tenant/subdomínio. A futura V4 poderá
 * usar este contexto no seletor F11 sem compartilhar uma "empresa ativa" global
 * na sessão entre abas.
 */

if (!function_exists('multi_company_context_request_token')) {
  function multi_company_context_request_token(): string {
    $token = trim((string)($_GET['mc_ctx'] ?? $_POST['mc_ctx'] ?? ''));
    if ($token === '' && isset($_SERVER['HTTP_X_NEXA_MULTI_COMPANY_CONTEXT'])) {
      $token = trim((string)$_SERVER['HTTP_X_NEXA_MULTI_COMPANY_CONTEXT']);
    }
    // Token opaco hexadecimal gerado pelo servidor. Rejeita qualquer outro formato.
    return preg_match('/^[a-f0-9]{48,128}$/i', $token) ? strtolower($token) : '';
  }
}

if (!function_exists('multi_company_context_session_start')) {
  function multi_company_context_session_start(): void {
    if (function_exists('auth_start')) {
      auth_start();
      return;
    }
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
  }
}

if (!function_exists('multi_company_context_prune')) {
  function multi_company_context_prune(): void {
    multi_company_context_session_start();
    $now = time();
    $rows = $_SESSION['multi_company_context_tokens'] ?? [];
    if (!is_array($rows)) $rows = [];

    foreach ($rows as $token => $row) {
      if (!is_array($row)) {
        unset($rows[$token]);
        continue;
      }
      $expiresAt = (int)($row['expires_at'] ?? 0);
      if ($expiresAt <= $now) unset($rows[$token]);
    }

    // Mantém no máximo 30 contextos recentes na sessão. Isso permite várias abas
    // sem transformar a sessão em um estado global de "empresa ativa".
    if (count($rows) > 30) {
      uasort($rows, static function($a, $b): int {
        return (int)($b['last_used_at'] ?? $b['issued_at'] ?? 0) <=> (int)($a['last_used_at'] ?? $a['issued_at'] ?? 0);
      });
      $rows = array_slice($rows, 0, 30, true);
    }

    $_SESSION['multi_company_context_tokens'] = $rows;
  }
}

if (!function_exists('multi_company_context_company_row')) {
  function multi_company_context_company_row(PDO $pdo, int $companyId): ?array {
    if ($companyId <= 0) return null;
    try {
      $st = $pdo->prepare('SELECT id, name, slug, status FROM companies WHERE id = ? LIMIT 1');
      $st->execute([$companyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      return $row ?: null;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('multi_company_context_company_operational')) {
  function multi_company_context_company_operational(PDO $pdo, int $companyId): bool {
    $company = multi_company_context_company_row($pdo, $companyId);
    if (!$company) return false;
    $status = strtolower(trim((string)($company['status'] ?? 'active')));
    if (in_array($status, ['blocked','suspended','expired'], true)) return false;

    // Se o guard SaaS já estiver carregado, também respeita assinatura/bloqueio.
    if (function_exists('saas_company_is_blocked')) {
      try {
        $check = saas_company_is_blocked($pdo, $company);
        if (!empty($check['blocked'])) return false;
      } catch (Throwable $e) {
        return false;
      }
    }
    return true;
  }
}

if (!function_exists('multi_company_context_validate_target_uncached')) {
  /**
   * Valida a troca potencial sem alterar o contexto operacional.
   * Retorna um array com ok/reason e metadados suficientes para emissão/resolução
   * de um token por aba.
   */
  function multi_company_context_validate_target_uncached(PDO $pdo, int $userId, int $entryCompanyId, int $targetCompanyId): array {
    $fail = static fn(string $reason): array => [
      'ok' => false,
      'reason' => $reason,
      'entry_company_id' => $entryCompanyId,
      'company_id' => $entryCompanyId,
      'business_group_id' => 0,
      'operation_mode' => '',
    ];

    if ($userId <= 0 || $entryCompanyId <= 0 || $targetCompanyId <= 0) return $fail('contexto_invalido');
    if (!multi_company_schema_ready($pdo)) return $fail('estrutura_multiempresa_indisponivel');

    // Regra inviolável: o processo multiempresa só existe quando a empresa de
    // entrada está explicitamente habilitada no SaaS.
    if (!multi_company_feature_enabled($pdo, $entryCompanyId)) return $fail('multiempresa_desabilitado_origem');

    $entryGroup = multi_company_group_by_company($pdo, $entryCompanyId);
    $targetGroup = multi_company_group_by_company($pdo, $targetCompanyId);
    if (!$entryGroup || !$targetGroup) return $fail('grupo_empresarial_invalido');

    $entryGroupId = (int)($entryGroup['id'] ?? 0);
    $targetGroupId = (int)($targetGroup['id'] ?? 0);
    if ($entryGroupId <= 0 || $entryGroupId !== $targetGroupId) return $fail('empresa_fora_do_grupo');

    if (!multi_company_feature_enabled($pdo, $targetCompanyId)) return $fail('multiempresa_desabilitado_destino');
    if (!multi_company_context_company_operational($pdo, $targetCompanyId)) return $fail('empresa_destino_indisponivel');

    // O usuário precisa estar ativo no grupo. Apenas estar cadastrado em users
    // ou possuir uma sessão válida não concede acesso cruzado.
    $groupUser = multi_company_user_group_config($pdo, $entryGroupId, $userId);
    if (!$groupUser || (int)($groupUser['is_active'] ?? 0) !== 1) return $fail('usuario_sem_vinculo_ativo_no_grupo');

    try {
      $st = $pdo->prepare('SELECT company_profile_id, is_default, is_active FROM user_company_access WHERE user_id = ? AND company_id = ? LIMIT 1');
      $st->execute([$userId, $targetCompanyId]);
      $access = $st->fetch(PDO::FETCH_ASSOC);
      if (!$access || (int)($access['is_active'] ?? 0) !== 1) return $fail('usuario_sem_acesso_empresa');
    } catch (Throwable $e) {
      return $fail('falha_validacao_acesso');
    }

    $mode = (string)($entryGroup['operation_mode'] ?? 'integrated');
    if (!in_array($mode, ['integrated','isolated'], true)) return $fail('modo_grupo_invalido');

    // Garante que o perfil previsto na V2 continua configurado antes de permitir
    // que a empresa apareça na futura lista do F11.
    if ($mode === 'integrated') {
      $groupProfileId = (int)($groupUser['group_profile_id'] ?? 0);
      if ($groupProfileId <= 0) return $fail('perfil_integrado_nao_configurado');
      try {
        $st = $pdo->prepare('SELECT 1 FROM business_group_profiles WHERE id = ? AND business_group_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$groupProfileId, $entryGroupId]);
        if (!$st->fetchColumn()) return $fail('perfil_integrado_inativo');
      } catch (Throwable $e) {
        return $fail('falha_validacao_perfil_integrado');
      }
    } else {
      $companyProfileId = (int)($access['company_profile_id'] ?? 0);
      if ($companyProfileId <= 0) return $fail('perfil_empresa_nao_configurado');
      try {
        $st = $pdo->prepare('SELECT 1 FROM user_profiles WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$companyProfileId, $targetCompanyId]);
        if (!$st->fetchColumn()) return $fail('perfil_empresa_inativo');
      } catch (Throwable $e) {
        return $fail('falha_validacao_perfil_empresa');
      }
    }

    return [
      'ok' => true,
      'reason' => 'ok',
      'entry_company_id' => $entryCompanyId,
      'company_id' => $targetCompanyId,
      'business_group_id' => $entryGroupId,
      'business_group_name' => (string)($entryGroup['name'] ?? ''),
      'operation_mode' => $mode,
      'company_profile_id' => isset($access['company_profile_id']) && $access['company_profile_id'] !== null ? (int)$access['company_profile_id'] : null,
      'group_profile_id' => isset($groupUser['group_profile_id']) && $groupUser['group_profile_id'] !== null ? (int)$groupUser['group_profile_id'] : null,
      'is_default' => (int)($access['is_default'] ?? 0) === 1,
    ];
  }
}

if (!function_exists('multi_company_context_validate_target')) {
  /**
   * V12: mesma validação, guardada durante a requisição. Antes, cada checagem de
   * permissão (menu, bootstrap, telas) repetia ~10 consultas ao banco; numa página
   * com F11 ativo isso passava de 100 consultas só para validar o contexto.
   * O cache vale só para a requisição atual (nada fica na sessão).
   */
  function multi_company_context_validate_target(PDO $pdo, int $userId, int $entryCompanyId, int $targetCompanyId): array {
    static $memo = [];
    $key = spl_object_id($pdo) . '|' . $userId . '|' . $entryCompanyId . '|' . $targetCompanyId;
    if (!isset($memo[$key])) $memo[$key] = multi_company_context_validate_target_uncached($pdo, $userId, $entryCompanyId, $targetCompanyId);
    return $memo[$key];
  }
}

if (!function_exists('multi_company_context_profile_cached')) {
  function multi_company_context_profile_cached(PDO $pdo, int $userId, int $entryCompanyId, int $targetCompanyId): ?array {
    static $memo = [];
    $key = spl_object_id($pdo) . '|' . $userId . '|' . $entryCompanyId . '|' . $targetCompanyId;
    if (!array_key_exists($key, $memo)) $memo[$key] = multi_company_context_profile($pdo, $userId, $entryCompanyId, $targetCompanyId);
    return $memo[$key];
  }
}

if (!function_exists('multi_company_context_available_companies')) {
  function multi_company_context_available_companies(PDO $pdo, int $userId, int $entryCompanyId): array {
    if ($userId <= 0 || $entryCompanyId <= 0) return [];
    if (!multi_company_feature_enabled($pdo, $entryCompanyId)) return [];
    $group = multi_company_group_by_company($pdo, $entryCompanyId);
    if (!$group) return [];
    $groupId = (int)($group['id'] ?? 0);
    if ($groupId <= 0) return [];

    $rows = multi_company_user_access_rows($pdo, $userId);
    $out = [];
    foreach ($rows as $row) {
      $targetId = (int)($row['company_id'] ?? 0);
      if ($targetId <= 0 || (int)($row['business_group_id'] ?? 0) !== $groupId) continue;
      $validation = multi_company_context_validate_target($pdo, $userId, $entryCompanyId, $targetId);
      if (empty($validation['ok'])) continue;
      $row['context_validation'] = $validation;
      $out[] = $row;
    }
    return $out;
  }
}

if (!function_exists('multi_company_context_issue')) {
  function multi_company_context_issue(PDO $pdo, int $userId, int $entryCompanyId, int $targetCompanyId, int $ttlSeconds = 28800): array {
    $validation = multi_company_context_validate_target($pdo, $userId, $entryCompanyId, $targetCompanyId);
    if (empty($validation['ok'])) return ['ok' => false, 'token' => '', 'reason' => (string)($validation['reason'] ?? 'acesso_negado')];

    multi_company_context_prune();
    try {
      $token = bin2hex(random_bytes(32));
    } catch (Throwable $e) {
      return ['ok' => false, 'token' => '', 'reason' => 'falha_geracao_token'];
    }

    $now = time();
    $ttlSeconds = max(300, min($ttlSeconds, 43200));
    $_SESSION['multi_company_context_tokens'][$token] = [
      'user_id' => $userId,
      'entry_company_id' => $entryCompanyId,
      'company_id' => $targetCompanyId,
      'business_group_id' => (int)($validation['business_group_id'] ?? 0),
      'operation_mode' => (string)($validation['operation_mode'] ?? ''),
      'issued_at' => $now,
      'last_used_at' => $now,
      'expires_at' => $now + $ttlSeconds,
    ];

    return ['ok' => true, 'token' => $token, 'reason' => 'ok', 'context' => $validation];
  }
}

if (!function_exists('multi_company_context_resolve')) {
  function multi_company_context_resolve(PDO $pdo, int $entryCompanyId, ?string $token = null): array {
    $base = [
      'active' => false,
      'requested' => false,
      'valid' => false,
      'reason' => 'legacy',
      'token' => '',
      'entry_company_id' => $entryCompanyId,
      'company_id' => $entryCompanyId,
      'business_group_id' => 0,
      'business_group_name' => '',
      'operation_mode' => '',
    ];

    $token = $token === null ? multi_company_context_request_token() : trim($token);
    if ($token === '') return $base;
    $base['requested'] = true;
    $base['token'] = $token;

    $userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    $role = function_exists('auth_role') ? (string)auth_role() : '';
    $authCompanyId = function_exists('auth_company_id') ? (int)(auth_company_id() ?? 0) : 0;
    if ($userId <= 0 || !in_array($role, ['company_admin','company_user'], true)) {
      $base['reason'] = 'sessao_sem_usuario_empresa';
      return $base;
    }
    // A V3 não transforma o login: o tenant de entrada precisa continuar sendo
    // a empresa original autenticada. A troca fica somente no contexto paralelo.
    if ($authCompanyId !== $entryCompanyId) {
      $base['reason'] = 'empresa_de_entrada_diferente_do_login';
      return $base;
    }

    multi_company_context_prune();
    $stored = $_SESSION['multi_company_context_tokens'][$token] ?? null;
    if (!is_array($stored)) {
      $base['reason'] = 'token_inexistente_ou_expirado';
      return $base;
    }

    if ((int)($stored['user_id'] ?? 0) !== $userId || (int)($stored['entry_company_id'] ?? 0) !== $entryCompanyId) {
      unset($_SESSION['multi_company_context_tokens'][$token]);
      $base['reason'] = 'token_nao_pertence_ao_contexto';
      return $base;
    }

    $targetCompanyId = (int)($stored['company_id'] ?? 0);
    $validation = multi_company_context_validate_target($pdo, $userId, $entryCompanyId, $targetCompanyId);
    if (empty($validation['ok'])) {
      unset($_SESSION['multi_company_context_tokens'][$token]);
      $base['reason'] = (string)($validation['reason'] ?? 'contexto_nao_autorizado');
      return $base;
    }

    $_SESSION['multi_company_context_tokens'][$token]['last_used_at'] = time();
    return array_merge($base, $validation, [
      'active' => $targetCompanyId !== $entryCompanyId,
      'requested' => true,
      'valid' => true,
      'reason' => 'ok',
      'token' => $token,
    ]);
  }
}

if (!function_exists('multi_company_context_profile')) {
  /**
   * Perfil que deverá ser usado futuramente quando o contexto operacional for
   * ativado. V3 apenas resolve/diagnostica; access_control.php ainda não é alterado.
   */
  function multi_company_context_profile(PDO $pdo, int $userId, int $entryCompanyId, int $targetCompanyId): ?array {
    $validation = multi_company_context_validate_target($pdo, $userId, $entryCompanyId, $targetCompanyId);
    if (empty($validation['ok'])) return null;
    $groupId = (int)($validation['business_group_id'] ?? 0);
    $mode = (string)($validation['operation_mode'] ?? '');

    try {
      if ($mode === 'integrated') {
        $profileId = (int)($validation['group_profile_id'] ?? 0);
        $st = $pdo->prepare('SELECT id, name, description, permissions_json, is_active FROM business_group_profiles WHERE id = ? AND business_group_id = ? AND is_active = 1 LIMIT 1');
        $st->execute([$profileId, $groupId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) return null;
        $row['profile_scope'] = 'group';
        return $row;
      }

      $profileId = (int)($validation['company_profile_id'] ?? 0);
      $st = $pdo->prepare('SELECT id, name, description, permissions_json, is_active FROM user_profiles WHERE id = ? AND company_id = ? AND is_active = 1 LIMIT 1');
      $st->execute([$profileId, $targetCompanyId]);
      $row = $st->fetch(PDO::FETCH_ASSOC);
      if (!$row) return null;
      $row['profile_scope'] = 'company';
      return $row;
    } catch (Throwable $e) {
      return null;
    }
  }
}

if (!function_exists('multi_company_context_id')) {
  function multi_company_context_id(): int {
    if (defined('MULTI_COMPANY_CONTEXT_ID')) return (int)MULTI_COMPANY_CONTEXT_ID;
    if (defined('COMPANY_ID')) return (int)COMPANY_ID;
    return 0;
  }
}

if (!function_exists('multi_company_context_is_active')) {
  function multi_company_context_is_active(): bool {
    return defined('MULTI_COMPANY_CONTEXT_ACTIVE') && MULTI_COMPANY_CONTEXT_ACTIVE === true;
  }
}

/**
 * V5 - ativação operacional progressiva por escopo.
 *
 * Regra de segurança: um contexto F11 válido NÃO transforma automaticamente todo
 * o ERP em multiempresa. Cada área precisa ser explicitamente adicionada à lista
 * abaixo e migrada/testada individualmente.
 *
 * V5: dashboard.
 * V6: products_read e customers_read para consultas/listagens.
 * V7: products_write e customers_write homologam gravações protegidas dos cadastros básicos.
 * V8: product_fiscal homologa tributação/fiscal dentro do cadastro de Produtos.
 * V8.1: fiscal_tax_profiles homologa o cadastro geral de Tributações no contexto F11.
 * V8.2: fiscal_operations homologa o cadastro geral de Operações/CFOPs no contexto F11.
 * V9: stock_read homologa somente consulta de saldo e histórico de estoque no contexto F11.
 * V9.1: stock_write homologa acerto manual, saldo inicial e parâmetros básicos de estoque
 *        no cadastro de Produtos.
 * V9.2: stock_conference homologa o ciclo completo de Conferência de Estoque (digitação,
 *        importação TXT, prévia e processamento) para a empresa selecionada pelo F11.
 * V10: goods_entries homologa Entrada de Mercadorias por XML no contexto F11: importação,
 *      conferência/vínculo de itens, cadastro rápido de produto, geração e desfazimento do estoque.
 *      Por segurança, o Contas a Pagar continua bloqueado no contexto cruzado e o RADAR/SEFAZ
 *      não executa busca, manifestação ou consulta por chave quando a empresa foi trocada por F11.
 * V10.1: goods_entries_manual homologa a Entrada Manual compacta para a empresa do F11;
 *        o lançamento continua sem Contas a Pagar em contexto cruzado e reutiliza o mesmo motor
 *        de conferência/geração de estoque da V10.
 * V10.2: customer_returns homologa Devolução de Cliente / Outras Entradas no contexto F11,
 *        incluindo leitura das vendas de origem, cliente/produto/operação e retorno de estoque.
 *        Emissão/transmissão fiscal da pré-NF-e continua bloqueada no contexto cruzado.
 * V10.3: supplier_returns homologa Devolução a Fornecedor / Saída de Estoque no contexto F11,
 *        limitada a operação DEVOLUCAO_FORNECEDOR, fornecedor/produto/entrada da empresa contextual
 *        e baixa de estoque. Financeiro e emissão/transmissão fiscal continuam bloqueados.
 * V11: finance_read homologa SOMENTE LEITURA do Financeiro no contexto F11: Dashboard Financeiro,
 *      Contas a Receber, Contas a Pagar, Movimento Financeiro e Resultado por Plano.
 * V11.1: finance_write homologa criação/edição protegida de títulos ABERTOS em Contas a Receber/Pagar.
 * V11.2: finance_settlement homologa baixas individuais e múltiplas protegidas desses títulos,
 *        gerando finance_settlements, finance_settlement_payments e finance_movements somente na
 *        empresa do F11. Títulos com boleto/cobrança ativa ou originados de Despesa Fixa permanecem
 *        bloqueados no contexto cruzado. Cancelamentos, estornos, movimento manual, configuração de
 *        caixa/banco, boletos, conciliação e despesas fixas continuam fora do escopo.
 * V11.3: finance_reversal homologa estorno protegido de baixas e finance_bank_accounts homologa
 *        cadastro básico de Caixa/Banco no contexto F11. Boletos/Itaú, Conciliação e Despesas Fixas
 *        permanecem fora do escopo. O COMPANY_ID legado continua inalterado.
 * V11.4: finance_chart_accounts e finance_cost_centers homologam os cadastros auxiliares necessários
 *        aos títulos; finance_people homologa cadastro financeiro protegido de Fornecedor quando
 *        explicitamente solicitado pela tela financeira. O fluxo V7 de Clientes permanece intacto.
 * V11.5: finance_fixed_expenses homologa Despesas Fixas no contexto F11, incluindo cadastro da regra,
 *        manutenção do horizonte de provisões e integração protegida com Contas a Pagar. Títulos
 *        gerados por Despesa Fixa podem ser baixados, estornados ou cancelados pelo F11 somente com
 *        as permissões financeiras correspondentes; edição direta do título gerado continua bloqueada.
 * V11.6: finance_payment_methods homologa somente a parametrização financeira sobre formas já existentes,
 *        sem alterar o cadastro operacional consumido por PDV/NFC-e/Boletos.
 * V11.9: finance_billing_read homologa SOMENTE LEITURA de Cobranças/Boletos no contexto F11,
 *        incluindo visualização/impressão do boleto e auditoria técnica Itaú.
 * V11.10: a mesma área libera exclusivamente a ação simulate_itau no F11. A chamada usa
 *         etapa_processo_boleto=simulacao, não reserva Nosso Número e grava somente a auditoria local
 *         da simulação.
 * V11.11: Registro/Reprocessamento Itaú passam a ser homologados no F11 por fluxo protegido: exige
 *         simulação 2xx recente e compatível com o payload, separa REGISTRAR de REPROCESSAR, reserva
 *         Nosso Número sob lock, muda para ENVIANDO_API antes da chamada externa e bloqueia reenvio
 *         em PROCESSANDO_API/VERIFICAR_API. Cancelamento bancário, configuração Itaú, WhatsApp/E-mail
 *         e Conciliação permanecem bloqueados.
 * V11.12: PROCESSANDO_API/VERIFICAR_API podem ser consultados individualmente no Itaú pelo
 *         Beneficiário + Carteira + Nosso Número. A consulta é somente leitura, não reenvia cobrança
 *         e só consolida REGISTRADO quando o banco confirma o título. Credenciais de consulta podem
 *         ser específicas por conta bancária; configuração continua fora do F11.
 * V11.13: finance_bank_reconciliation homologa SOMENTE LEITURA da Conciliação Bancária no contexto
 *         F11. Extratos já importados, KPIs, filtros, detalhes e sugestões de títulos passam a respeitar
 *         a empresa selecionada. Importação OFX, conciliar, ignorar/reabrir e criação de título/movimento
 *         continuam bloqueados até a homologação específica das gravações da Conciliação.
 * V11.14: finance_bank_reconciliation_import homologa exclusivamente a IMPORTAÇÃO OFX no contexto
 *         F11. A gravação usa a empresa selecionada, exige permissão de Conciliação e CSRF do contexto,
 *         aceita somente conta bancária ativa da empresa operacional, respeita a restrição de caixa/banco do usuário e mantém
 *         deduplicação por hash/identificador. Conciliar, ignorar/reabrir e gerar título/movimento seguem bloqueados.
 * V11.15: finance_billing_config homologa a configuração de Cobrança/Itaú por conta bancária
 *         no contexto F11. Credenciais, certificado, Beneficiário, Carteira, Webhook e parâmetros de
 *         emissão ficam isolados por company_id + bank_account_id. Processar retornos pendentes e
 *         sincronizar liquidações automaticamente continuam bloqueados no contexto cruzado.
 * V11.16: finance_bank_reconciliation_settlement homologa somente a conciliação com TÍTULO
 *         EXISTENTE no contexto F11. A baixa permanece dentro da empresa selecionada, exige a permissão
 *         específica de baixar a Receber/Pagar, CSRF Multiempresa, validações de vínculo e lock por item.
 *         Criar título, criar movimento financeiro e ignorar/reabrir itens continuam bloqueados.
 * V11.17: finance_bank_reconciliation_status homologa somente as mudanças de estado IGNORAR/REABRIR
 *         no contexto F11. A ação exige permissão de Conciliação, CSRF Multiempresa, item pertencente à
 *         empresa selecionada e lock por item compartilhado com a conciliação, evitando corrida entre
 *         ignorar/reabrir e uma baixa simultânea. Criar título e criar movimento continuam bloqueados.
 * V11.18: finance_bank_reconciliation_create_title homologa Criar título + baixar + conciliar no
 *         contexto F11. O fluxo exige permissão de Conciliação, acesso ao Contas a Receber/Pagar correspondente,
 *         permissão de baixa, CSRF Multiempresa, pessoa com papel compatível, plano/centro da empresa e lock do item.
 * V11.19: finance_bank_reconciliation_create_movement homologa Criar movimento financeiro direto +
 *         conciliar no contexto F11. O fluxo exige Conciliação, Movimento Financeiro e a permissão de
 *         lançamento manual, CSRF Multiempresa, conta/plano/centro/pessoa pertencentes à empresa e lock do item.
 * V11.20: finance_itau_settlement homologa o mesmo motor de liquidação Itaú já usado pela empresa principal
 *         para a empresa selecionada pelo F11. A configuração é resolvida por company_id + bank_account_id;
 *         reprocessamento de Webhook e sincronização da Francesinha só podem baixar títulos da empresa operacional.
 * V11.21: finance_billing_cancel homologa o MESMO cancelamento local pré-registro já usado pela empresa
 *         principal. Pelo F11 somente cobranças AGUARDANDO_API/AGUARDANDO_REMESSA/ERRO, ainda sem identidade
 *         bancária, podem ser canceladas junto com a Conta a Receber. Títulos já enviados/registrados no Itaú,
 *         em processamento ou com pagamento a verificar continuam bloqueados porque o motor atual não possui
 *         endpoint homologado de baixa/cancelamento bancário do BoleCode já registrado.
 * V11.22: finance_billing_send homologa os envios externos de BOLETO por WhatsApp e E-mail no contexto F11.
 *         Documento, pagador, modelos, configuração do WhatsApp e logs são resolvidos pela empresa selecionada.
 *         O e-mail usa a configuração SMTP do usuário dentro da empresa selecionada; não existe fallback silencioso
 *         para credenciais da empresa de entrada. POSTs exigem CSRF do contexto e nenhuma DDL é executada pelo F11.
 * V11.22.1: whatsapp_config homologa a Central do WhatsApp, QR Code, token, sessão e Modelos de Mensagens
 *           na empresa selecionada pelo F11. Cada empresa usa seu próprio registro app_config(company_id,'whatsapp'),
 *           seu próprio session_company_id no serviço Node e logs/modelos isolados por company_id.
 * V11.23: finance_receipt_send homologa o envio do RECIBO de baixa por WhatsApp e E-mail no contexto F11,
 *         reutilizando a baixa já realizada e o batch vinculado ao company_id da empresa selecionada. O SMTP do
 *         usuário pode ser configurado diretamente na tela de envio para a empresa operacional, sem fallback para
 *         a empresa de entrada; WhatsApp, SMTP e logs permanecem isolados por company_id.
 * V11.23: finance_movements_write fecha o fluxo de Movimento Financeiro manual no F11: inclusão/edição de
 *         lançamentos MANUAIS e cancelamento protegido usam somente pessoa, plano, centro e Caixa/Banco da empresa
 *         selecionada. Movimentos originados de baixas continuam protegidos contra edição/cancelamento direto.
 * V11.24: sales_dashboard_read e sales_orders_read homologam SOMENTE LEITURA do Dashboard de Vendas e
 *         da Central de Pedidos para a empresa selecionada pelo F11. Impressão simples do pedido também é permitida.
 *         Edição, troca de status, WhatsApp/E-mail, impressão de produção/cozinha, Caixa, Comandas, PDV e emissão
 *         fiscal continuam bloqueados no contexto cruzado. O acesso direto a páginas operacionais de Gestão é
 *         interrompido para evitar que o operador execute por engano na empresa de entrada.
 * V11.25: sales_pdv_order homologa o Pedido PDV padrão para a empresa selecionada pelo F11. O mesmo motor de
 *         pedido, estoque, pagamentos, financeiro, boleto e WhatsApp é reutilizado com company_id operacional.
 *         Em contexto cruzado a finalização é obrigatoriamente SOMENTE PEDIDO: NF-e/NFC-e/NFS-e continuam
 *         bloqueadas, nenhuma DDL runtime é permitida e Caixa/Comandas/PDV Resumido/Venda Mobile permanecem
 *         fora desta etapa.
 * V11.25.2: sales_payment_methods homologa o cadastro operacional de Formas de Pagamento no F11. O registro,
 *           bancos/caixas padrão, plano/centro, flags de Contas a Receber e a opção Exige caixa aberto ficam
 *           isolados pelo company_id operacional. Em contexto cruzado a página exige gestao_pagamentos, CSRF
 *           Multiempresa, valida os IDs vinculados e não executa DDL runtime.
 * V11.26: sales_cash homologa o Caixa operacional no F11. Abertura, reforço/suprimento, sangria, fechamento,
 *         impressão do resumo e vínculo das vendas do Pedido PDV usam exclusivamente a empresa selecionada.
 *         O caixa/banco é validado por company_id, os POSTs exigem gestao_caixa + CSRF Multiempresa e nenhuma
 *         DDL é executada no contexto cruzado.
 * V11.27: sales_pdv_mobile homologa a Venda Mobile no F11 reutilizando exatamente o mesmo motor do Pedido PDV,
 *         agora com escopo e permissão próprios. Cliente, endereço, produtos, estoque, pagamentos, Caixa e pedido
 *         usam o company_id operacional; POSTs exigem CSRF Multiempresa e a finalização continua SOMENTE PEDIDO,
 *         sem NF-e/NFC-e/NFS-e. Eventos/impressões especiais de evento não são acionados no contexto cruzado.
 * V11.28: sales_pdv_summary homologa o PDV Resumido no F11. Pesquisa de produtos, cadastro rápido de cliente/endereço,
 *         estoque, vendedor, pagamentos, Caixa e gravação do pedido usam exclusivamente o company_id operacional.
 *         POSTs exigem CSRF Multiempresa, DDL runtime fica desativada e a finalização permanece SOMENTE PEDIDO,
 *         sem NF-e/NFC-e/NFS-e.
 * V11.29: sales_comandas homologa Comandas e Comanda Mobile no F11 usando o mesmo motor já utilizado pela empresa
 *         principal. Comanda, itens, produtos, cliente, vendedor, pagamentos, Caixa, estoque, pedido, transferências
 *         e união de comandas usam exclusivamente o company_id operacional. POSTs exigem CSRF Multiempresa,
 *         DDL runtime e impressão/cozinha ficam desativados no contexto cruzado, e o fechamento permanece SOMENTE
 *         PEDIDO, sem NF-e/NFC-e/NFS-e.
 * V11.30: sales_detailed_order homologa o Pedido Detalhado no F11 reutilizando o mesmo motor de pedido, estoque,
 *         pagamentos, Caixa e financeiro da empresa principal. Cliente, transportadora, produtos, tributação por item
 *         e snapshot fiscal são sempre lidos/gravados pelo company_id operacional. Em contexto cruzado o POST exige
 *         CSRF Multiempresa, nenhuma DDL runtime é permitida e a finalização é SOMENTE PEDIDO, sem emissão fiscal.
 * V11.31: sales_printing homologa impressões operacionais não fiscais de Pedidos/Comandas e o Monitor de Impressão.
 *         Conferência, cozinha, produção, evento e relatório de produtos usam o company_id operacional; o monitor e sua
 *         API ficam isolados pela empresa selecionada e POSTs de fila exigem CSRF Multiempresa.
 * V11.32: sales_comanda_setup homologa Cadastro/Configuração de Comandas, QR Codes e Agentes de Impressão no F11.
 *         Configurações, grupos/setores, impressoras, tokens públicos e agentes ficam isolados por company_id; POSTs exigem
 *         CSRF Multiempresa e nenhuma DDL runtime é executada. A impressão automática/direta pode usar os agentes da empresa
 *         selecionada, enquanto toda emissão fiscal continua bloqueada.
 * Cancelamento bancário de boleto já registrado continua fora do escopo.
 * V11.33: fiscal_documents_read e fiscal_settings_read iniciam o Fiscal Multiempresa em modo SOMENTE LEITURA.
 *         Documentos fiscais e parâmetros/séries/certificados da empresa selecionada podem ser consultados.
 * V11.34: fiscal_settings_write libera exclusivamente a manutenção protegida dos parâmetros fiscais, séries e certificados
 *         da empresa selecionada. Busca DF-e, transmissão, consulta SEFAZ, emissão, cancelamento e inutilização seguem bloqueados.
 * V11.34.1: formaliza a homologação das baixas automáticas de estoque originadas pelos fluxos de venda já liberados
 *           no F11. Pedido PDV, Venda Mobile, PDV Resumido, Comandas e Pedido Detalhado reutilizam o mesmo motor
 *           gestao_pdv_apply_stock_out da empresa principal, sempre filtrado pelo company_id operacional. A baixa ocorre
 *           na finalização do pedido e NÃO será repetida pela futura emissão fiscal, evitando dupla movimentação.
 * V11.35: fiscal_nfce_homologation libera a PRIMEIRA emissão fiscal cruzada, exclusivamente NFC-e modelo 65 e
 *         somente quando a empresa selecionada estiver em HOMOLOGAÇÃO. Produção, NF-e, NFS-e, cancelamento,
 *         CC-e, inutilização e lote fiscal permanecem bloqueados no F11. A venda já baixou estoque na finalização;
 *         a autorização NFC-e não executa uma segunda baixa de estoque.
 * V11.35.2: users_read e user_profiles_read habilitam consulta contextual de Usuários/Perfis no F11.
 * V11.36: user_profiles_write libera CRUD de perfis SOMENTE em grupo/empresa no modo isolado; a identidade
 *         do usuário continua pertencendo à empresa de origem e os vínculos são mantidos por user_company_access.
 *         fiscal_catalogs_global permite consultar catálogos fiscais compartilhados pelo F11 sem convertê-los
 *         em dados por empresa. Alterações desses catálogos permanecem centralizadas no cadastro padrão.
 * V11.46: fiscal_nfe_homologation libera NF-e modelo 55 no contexto F11 exclusivamente em HOMOLOGAÇÃO,
 *         reutilizando o motor fiscal já homologado do tenant selecionado. Produção, NFS-e, cancelamento,
 *         CC-e, inutilização, contingência SVC e emissão em lote permanecem bloqueados no contexto cruzado.
 * V11.47: fiscal_cancel_homologation libera exclusivamente o evento 110111 de cancelamento para NF-e/NFC-e
 *         autorizadas em HOMOLOGAÇÃO e originadas de PEDIDO. A ação exige a permissão fiscal_documentos na
 *         empresa selecionada e mantém Produção, NFS-e, CC-e, inutilização, contingência e lote bloqueados.
 * V11.48: fiscal_cce_homologation libera exclusivamente o evento 110110 de Carta de Correção para NF-e modelo 55
 *         autorizada em HOMOLOGAÇÃO e originada de PEDIDO. A ação exige fiscal_documentos e mantém Produção,
 *         NFC-e CC-e, NFS-e, inutilização, contingência, lote e documentos de O.S. fora deste escopo.
 * V11.49: fiscal_inutilization_homologation libera a inutilização de faixas de NF-e/NFC-e da empresa selecionada
 *         pelo F11 exclusivamente em HOMOLOGAÇÃO. Exige a permissão fiscal_inutilizacao e mantém Produção,
 *         NFS-e, contingência, lote e fluxo fiscal da O.S. fora do escopo.
 * V11.50: fiscal_nfse_homologation libera NFS-e da empresa selecionada pelo F11 exclusivamente em HOMOLOGAÇÃO,
 *         reutilizando o emissor Nacional ou Pronim/GOVBR já configurado na empresa. Nesta etapa ficam liberados
 *         preparação/assinatura da DPS, transmissão, recuperação, consulta, impressão e download dos XMLs.
 *         Produção e envio por WhatsApp/E-mail permanecem fora deste escopo.
 * V11.51: fiscal_nfse_cancel_homologation libera exclusivamente o cancelamento de NFS-e autorizada pelo provider
 *         Pronim/GOVBR, em HOMOLOGAÇÃO, originada de PEDIDO e pertencente à empresa selecionada pelo F11. O emissor
 *         Nacional não possui motor de cancelamento homologado nesta base e continua bloqueado, assim como Produção.
 */
if (!function_exists('multi_company_operational_scopes')) {
  function multi_company_operational_scopes(): array {
    return ['dashboard', 'products_read', 'customers_read', 'products_write', 'customers_write', 'product_fiscal', 'fiscal_tax_profiles', 'fiscal_operations', 'stock_read', 'stock_write', 'stock_conference', 'goods_entries', 'goods_entries_manual', 'customer_returns', 'supplier_returns', 'finance_read', 'finance_write', 'finance_settlement', 'finance_reversal', 'finance_bank_accounts', 'finance_chart_accounts', 'finance_cost_centers', 'finance_people', 'finance_fixed_expenses', 'finance_payment_methods', 'finance_billing_read', 'finance_billing_config', 'finance_bank_reconciliation', 'finance_bank_reconciliation_import', 'finance_bank_reconciliation_settlement', 'finance_bank_reconciliation_status', 'finance_bank_reconciliation_create_title', 'finance_bank_reconciliation_create_movement', 'finance_itau_settlement', 'finance_billing_cancel', 'finance_billing_send', 'finance_receipt_send', 'finance_movements_write', 'finance_card_control', 'whatsapp_config', 'sales_dashboard_read', 'sales_orders_read', 'sales_pdv_order', 'sales_pdv_mobile', 'sales_pdv_summary', 'sales_comandas', 'sales_detailed_order', 'sales_printing', 'sales_comanda_setup', 'sales_payment_methods', 'sales_cash', 'fiscal_documents_read', 'fiscal_settings_read', 'fiscal_settings_write', 'fiscal_nfce_homologation', 'fiscal_nfe_homologation', 'fiscal_cancel_homologation', 'fiscal_cce_homologation', 'fiscal_inutilization_homologation', 'fiscal_nfse_homologation', 'fiscal_nfse_cancel_homologation', 'fiscal_catalogs_global', 'users_read', 'user_profiles_read', 'user_profiles_write'];
  }
}

if (!function_exists('multi_company_operational_company_id')) {
  function multi_company_operational_company_id(PDO $pdo, string $scope): int {
    $legacyCompanyId = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    if ($legacyCompanyId <= 0) return 0;

    $scope = strtolower(trim($scope));
    if ($scope === '' || !in_array($scope, multi_company_operational_scopes(), true)) {
      return $legacyCompanyId;
    }

    // Opt-in absoluto: sem módulo Multiempresa na empresa de entrada, mantém legado.
    if (!multi_company_feature_enabled($pdo, $legacyCompanyId)) {
      return $legacyCompanyId;
    }

    // O contexto só pode operar quando foi solicitado por token e revalidado com
    // sucesso nesta requisição. A própria validação já confere grupo, usuário,
    // empresa autorizada, perfil e feature flag da empresa de destino.
    if (!defined('MULTI_COMPANY_CONTEXT_VALID') || MULTI_COMPANY_CONTEXT_VALID !== true) {
      return $legacyCompanyId;
    }

    $contextCompanyId = defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : 0;
    if ($contextCompanyId <= 0) return $legacyCompanyId;

    return $contextCompanyId;
  }
}


/**
 * V6 - informa se um escopo homologado está operando em empresa diferente do
 * tenant de entrada. Usado para manter as gravações protegidas enquanto somente
 * a consulta foi homologada.
 */
if (!function_exists('multi_company_scope_is_cross_company')) {
  function multi_company_scope_is_cross_company(PDO $pdo, string $scope): bool {
    $legacyCompanyId = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    if ($legacyCompanyId <= 0) return false;
    $operationalCompanyId = multi_company_operational_company_id($pdo, $scope);
    return $operationalCompanyId > 0 && $operationalCompanyId !== $legacyCompanyId;
  }
}

/**
 * V6 - valida uma permissão no perfil efetivo da empresa selecionada.
 * Em grupo integrado usa o perfil do grupo; em grupo isolado usa o perfil
 * específico da empresa. Isso evita que a permissão da empresa de entrada
 * seja reutilizada indevidamente ao consultar outra empresa.
 */
if (!function_exists('multi_company_context_permission_allowed')) {
  function multi_company_context_permission_allowed(PDO $pdo, string $permission): bool {
    $permission = trim($permission);
    $entryCompanyId = defined('COMPANY_ID') ? (int)COMPANY_ID : 0;
    $targetCompanyId = defined('MULTI_COMPANY_CONTEXT_ID') ? (int)MULTI_COMPANY_CONTEXT_ID : $entryCompanyId;
    if ($permission === '' || $entryCompanyId <= 0 || $targetCompanyId <= 0) return false;

    // Sem troca efetiva, mantém exatamente o controle de acesso legado.
    if ($targetCompanyId === $entryCompanyId || !defined('MULTI_COMPANY_CONTEXT_VALID') || MULTI_COMPANY_CONTEXT_VALID !== true) {
      return function_exists('access_has_permission')
        ? access_has_permission($pdo, $entryCompanyId, $permission)
        : true;
    }

    $userId = function_exists('auth_user_id') ? (int)(auth_user_id() ?? 0) : 0;
    if ($userId <= 0) return false;
    $profile = multi_company_context_profile_cached($pdo, $userId, $entryCompanyId, $targetCompanyId);
    if (!$profile) return false;

    $permissions = json_decode((string)($profile['permissions_json'] ?? ''), true);
    $permissions = is_array($permissions) ? array_values(array_unique(array_filter(array_map('strval', $permissions)))) : [];
    if (!in_array($permission, $permissions, true)) return false;

    // Também respeita o módulo comercial da empresa de destino quando o catálogo
    // de permissões já estiver carregado pelo bootstrap administrativo.
    if (function_exists('access_permissions_catalog') && function_exists('access_module_enabled')) {
      $catalog = access_permissions_catalog();
      if (!isset($catalog[$permission])) return false;
      if (!access_module_enabled($pdo, $targetCompanyId, $catalog[$permission]['module'] ?? '*')) return false;
    }

    return true;
  }
}


/**
 * V7 - preserva o token da aba em redirecionamentos PHP das áreas já homologadas.
 * Links e formulários continuam sendo tratados também pelo JS da V4, mas redirects
 * executados pelo servidor precisam carregar o mc_ctx explicitamente.
 */
if (!function_exists('multi_company_context_url')) {
  function multi_company_context_url(string $url): string {
    $ctx = is_array($GLOBALS['MULTI_COMPANY_CONTEXT'] ?? null) ? $GLOBALS['MULTI_COMPANY_CONTEXT'] : [];
    $token = !empty($ctx['valid']) ? trim((string)($ctx['token'] ?? '')) : '';
    if ($token === '') return $url;

    $fragment = '';
    $hashPos = strpos($url, '#');
    if ($hashPos !== false) {
      $fragment = substr($url, $hashPos);
      $url = substr($url, 0, $hashPos);
    }
    $sep = str_contains($url, '?') ? '&' : '?';
    if (preg_match('/(?:^|[?&])mc_ctx=/', $url)) return $url . $fragment;
    return $url . $sep . 'mc_ctx=' . rawurlencode($token) . $fragment;
  }
}


/**
 * V4 - token CSRF específico do seletor F11.
 * Não compartilha o token com outros formulários do ERP.
 */
if (!function_exists('multi_company_context_csrf_token')) {
  function multi_company_context_csrf_token(): string {
    multi_company_context_session_start();
    $key = 'multi_company_context_csrf';
    if (empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
      try {
        $_SESSION[$key] = bin2hex(random_bytes(24));
      } catch (Throwable $e) {
        $_SESSION[$key] = hash('sha256', session_id() . '|multi-company-context-v4|' . (string)(auth_user_id() ?? 0));
      }
    }
    return (string)$_SESSION[$key];
  }
}

if (!function_exists('multi_company_context_csrf_valid')) {
  function multi_company_context_csrf_valid(string $token): bool {
    $expected = multi_company_context_csrf_token();
    return $token !== '' && $expected !== '' && hash_equals($expected, $token);
  }
}

if (!function_exists('multi_company_context_forget_token')) {
  function multi_company_context_forget_token(string $token): void {
    if ($token === '') return;
    multi_company_context_session_start();
    if (isset($_SESSION['multi_company_context_tokens'][$token])) {
      unset($_SESSION['multi_company_context_tokens'][$token]);
    }
  }
}

/**
 * V12 (Multiempresa Isolado) - guardião de páginas no contexto F11.
 *
 * Problema corrigido: páginas que ainda não conhecem o contexto F11 (O.S., Orçamentos,
 * CRM, Contratos, Entregas, Produção, Brain, alguns relatórios/fiscais etc.) abriam
 * normalmente com o token mc_ctx e gravavam na EMPRESA DE ENTRADA, enquanto o
 * cabeçalho mostrava a empresa selecionada. Agora, em contexto cruzado, somente as
 * páginas desta lista podem abrir; as demais são recusadas pelo _bootstrap e somem
 * do menu. Cada página da lista já trata o F11 (usa a empresa operacional ou se
 * bloqueia sozinha). Ao homologar um módulo novo, inclua as páginas aqui.
 */
if (!function_exists('multi_company_cross_aware_pages')) {
  function multi_company_cross_aware_pages(): array {
    return [
      'area_trabalho.php',
      'commercial_credit_authorize.php',
      'compras_alertas.php', 'compras_assistente.php', 'compras_cotacoes.php', 'compras_cotacoes_matriz.php', 'compras_fornecedores_avaliacao.php', 'compras_fornecedores_ranking.php', 'compras_historico_precos.php', 'compras_lista_editar.php', 'compras_listas.php', 'compras_painel.php', 'compras_pedido_conciliacao.php', 'compras_pedido_conciliar_xml.php', 'compras_pedido_receber.php', 'compras_pedidos.php',
      'configuracoes_caixa.php', 'configuracoes_cpa.php', 'configuracoes_cre.php',
      'dashboard.php',
      'email_documento.php',
      'estoque_conferencia_form.php', 'estoque_conferencia_processar.php', 'estoque_conferencias.php', 'estoque_consulta.php',
      'financeiro.php', 'financeiro_boleto_imprimir.php', 'financeiro_boleto_itau.php', 'financeiro_boleto_whatsapp.php', 'financeiro_boletos.php', 'financeiro_caixas_bancos.php', 'financeiro_cartoes.php', 'financeiro_centros_custo.php', 'financeiro_conciliacao.php', 'financeiro_contas_pagar.php', 'financeiro_contas_receber.php', 'financeiro_contas_receber_importar.php', 'financeiro_despesas_fixas.php', 'financeiro_formas_pagamento.php', 'financeiro_itau.php', 'financeiro_movimentos.php', 'financeiro_pessoas.php', 'financeiro_plano_contas.php', 'financeiro_recibo_baixa.php', 'financeiro_recibo_whatsapp.php', 'financeiro_resultado.php', 'financeiro_vales.php', 'financeiro_vales_ajax.php',
      'fiscal_cests.php', 'fiscal_cfops.php', 'fiscal_configuracoes.php', 'fiscal_cst.php', 'fiscal_devolucao_cliente.php', 'fiscal_devolucoes.php', 'fiscal_documentos.php', 'fiscal_emissao_lote.php', 'fiscal_entrada_notas.php', 'fiscal_ibscbs.php', 'fiscal_importacoes.php', 'fiscal_importar_xml.php', 'fiscal_inutilizacao.php', 'fiscal_lookup.php', 'fiscal_ncms.php', 'fiscal_nfce_acao.php', 'fiscal_nfce_editar.php', 'fiscal_nfce_imprimir.php', 'fiscal_nfce_pedido.php', 'fiscal_nfse_acao.php', 'fiscal_nfse_cancelar.php', 'fiscal_nfse_consultar_dps.php', 'fiscal_nfse_imprimir.php', 'fiscal_nfse_nacional.php', 'fiscal_nota_manual.php', 'fiscal_operacoes.php', 'fiscal_radar_xml.php', 'fiscal_relatorio_inutilizacoes.php', 'fiscal_saidas.php', 'fiscal_st_uf.php', 'fiscal_tabelas.php', 'fiscal_tributacoes.php', 'fiscal_xml_entrada.php',
      'gestao.php', 'gestao_caixa.php', 'gestao_clientes.php', 'gestao_clientes_importar.php', 'gestao_comandas.php', 'gestao_comandas_agentes.php', 'gestao_comandas_checkout.php', 'gestao_comandas_config.php', 'gestao_comandas_impressao.php', 'gestao_comandas_mobile.php', 'gestao_comandas_print_api.php', 'gestao_comandas_qrcode.php', 'gestao_formas_pagamento.php', 'gestao_pdv.php', 'gestao_pdv_checkout.php', 'gestao_pdv_mobile.php', 'gestao_pdv_resumido.php', 'gestao_pedido_detalhado.php',
      'grid_preferencias.php',
      'importacao_anexos_cplus4.php', 'importacao_anexos_cplus4_api.php',
      'index.php',
      'logout.php',
      'modulo_em_breve.php',
      'multiempresa_acessos.php', 'multiempresa_contexto.php',
      'pedido.php', 'pedido_editar.php', 'pedido_finalizado.php', 'pedido_print.php', 'pedido_print_conferencia.php', 'pedido_print_cozinha.php', 'pedido_print_evento.php', 'pedido_print_producao.php', 'pedido_regularizacao.php', 'pedido_relatorio_produtos.php', 'pedido_status.php', 'pedido_whatsapp.php',
      'pedidos.php', 'pedidos_contratos_lote.php',
      'pessoa_aniversario_whatsapp.php', 'pessoa_cep_lookup.php', 'pessoa_cnpj_lookup.php',
      'produto_form.php', 'produto_gtin_lookup.php',
      'produtos.php',
      'relatorio_estoque.php', 'relatorio_fluxo_caixa.php', 'relatorio_inadimplencia.php', 'relatorio_movimentos_caixa.php',
      'usuario_form.php',
      'usuarios.php', 'usuarios_perfis.php',
      'utilitarios_manutencao_categorias.php', 'utilitarios_manutencao_tributacoes.php',
      'whatsapp.php', 'whatsapp_api.php', 'whatsapp_modelos.php',
    ];
  }
}

if (!function_exists('multi_company_cross_page_allowed')) {
  function multi_company_cross_page_allowed(string $page): bool {
    $page = strtolower(basename(trim($page)));
    if ($page === '') return false;
    static $set = null;
    if ($set === null) $set = array_fill_keys(multi_company_cross_aware_pages(), true);
    return isset($set[$page]);
  }
}
