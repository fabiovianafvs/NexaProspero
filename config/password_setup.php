<?php
declare(strict_types=1);

/**
 * Nexa Próspero - Link seguro para criar/redefinir senha.
 *
 * Em vez de gerar uma senha e enviá-la pelo WhatsApp (e guardar uma cópia aberta),
 * o sistema gera um link de uso único, válido por tempo limitado. O cliente abre o
 * link (admin/definir_senha.php) e cria a própria senha.
 *
 * - O banco guarda apenas o SHA-256 do token; o token em si só existe no link.
 * - Gerar um novo link invalida os anteriores do mesmo usuário.
 * - Ao criar a senha, o link é consumido e a senha do treinamento EAD é atualizada.
 *
 * Tabela: user_password_tokens (db/20261102_senha_link_seguro_v1.sql).
 */

require_once __DIR__ . '/helpers.php';

if (!defined('NEXA_PASSWORD_LINK_HOURS')) define('NEXA_PASSWORD_LINK_HOURS', 48);
if (!defined('NEXA_PASSWORD_MIN_LENGTH')) define('NEXA_PASSWORD_MIN_LENGTH', 8);

if (!function_exists('password_setup_ready')) {
  function password_setup_ready(PDO $pdo): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'user_password_tokens' LIMIT 1");
      $st->execute();
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('password_setup_users_has_plain_column')) {
  function password_setup_users_has_plain_column(PDO $pdo): bool {
    static $cache = [];
    $key = spl_object_id($pdo);
    if (array_key_exists($key, $cache)) return $cache[$key];
    try {
      $st = $pdo->prepare("SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'last_plain_password' LIMIT 1");
      $st->execute();
      return $cache[$key] = (bool)$st->fetchColumn();
    } catch (Throwable $e) {
      return $cache[$key] = false;
    }
  }
}

if (!function_exists('password_setup_create_link')) {
  /**
   * Gera um link de criação de senha para o usuário e devolve a URL completa.
   * Lança exceção se a tabela ainda não existir (migração não aplicada).
   */
  function password_setup_create_link(PDO $pdo, array $company, int $userId, string $purpose = 'setup', ?int $createdByUserId = null, int $hours = NEXA_PASSWORD_LINK_HOURS): string {
    if (!password_setup_ready($pdo)) {
      throw new RuntimeException('Aplique a migração "Link seguro de senha" no Atualizador do Banco.');
    }
    $companyId = (int)($company['id'] ?? 0);
    if ($companyId <= 0 || $userId <= 0) throw new RuntimeException('Empresa ou usuário inválido para gerar o link de senha.');

    $st = $pdo->prepare('SELECT id FROM users WHERE id = ? AND company_id = ? LIMIT 1');
    $st->execute([$userId, $companyId]);
    if (!$st->fetchColumn()) throw new RuntimeException('Usuário não pertence a esta empresa.');

    $purpose = in_array($purpose, ['setup', 'reset'], true) ? $purpose : 'setup';
    $hours = max(1, min(168, $hours));
    $token = bin2hex(random_bytes(32));

    $pdo->prepare('UPDATE user_password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
      ->execute([$userId]);
    $pdo->prepare('INSERT INTO user_password_tokens (company_id, user_id, token_hash, purpose, expires_at, created_by_user_id) VALUES (?, ?, ?, ?, DATE_ADD(NOW(), INTERVAL ' . $hours . ' HOUR), ?)')
      ->execute([$companyId, $userId, hash('sha256', $token), $purpose, $createdByUserId]);

    return company_admin_url($company, 'admin/definir_senha.php?t=' . $token);
  }
}

if (!function_exists('password_setup_create_link_for_company_admin')) {
  /** Gera o link para o primeiro administrador da empresa. */
  function password_setup_create_link_for_company_admin(PDO $pdo, int $companyId, string $purpose = 'setup', ?int $createdByUserId = null): string {
    $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
    $st->execute([$companyId]);
    $company = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$company) throw new RuntimeException('Empresa não encontrada.');

    $st = $pdo->prepare("SELECT id FROM users WHERE company_id = ? AND role = 'company_admin' ORDER BY id ASC LIMIT 1");
    $st->execute([$companyId]);
    $userId = (int)($st->fetchColumn() ?: 0);
    if ($userId <= 0) throw new RuntimeException('Administrador da empresa não encontrado.');

    return password_setup_create_link($pdo, $company, $userId, $purpose, $createdByUserId);
  }
}

if (!function_exists('password_setup_find_valid')) {
  /** Retorna o token válido (com dados do usuário) para a empresa informada, ou null. */
  function password_setup_find_valid(PDO $pdo, string $token, int $companyId): ?array {
    $token = trim($token);
    if ($companyId <= 0 || !preg_match('/^[a-f0-9]{64}$/', $token) || !password_setup_ready($pdo)) return null;
    $st = $pdo->prepare("
      SELECT t.id AS token_id, t.user_id, t.company_id, t.purpose, t.expires_at,
             u.name AS user_name, u.email AS user_email, u.role AS user_role
      FROM user_password_tokens t
      INNER JOIN users u ON u.id = t.user_id AND u.company_id = t.company_id
      WHERE t.token_hash = ? AND t.company_id = ? AND t.used_at IS NULL AND t.expires_at > NOW() AND u.is_active = 1
      LIMIT 1
    ");
    $st->execute([hash('sha256', $token), $companyId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
  }
}

if (!function_exists('password_setup_validate_password')) {
  /** Devolve a mensagem de erro, ou string vazia se a senha for aceita. */
  function password_setup_validate_password(string $password, string $confirm): string {
    if (strlen($password) < NEXA_PASSWORD_MIN_LENGTH) return 'A senha precisa ter pelo menos ' . NEXA_PASSWORD_MIN_LENGTH . ' caracteres.';
    if (strlen($password) > 200) return 'A senha é longa demais.';
    if (!preg_match('/[A-Za-z]/', $password) || !preg_match('/\d/', $password)) return 'Use letras e números na senha.';
    if (!hash_equals($password, $confirm)) return 'As duas senhas digitadas não são iguais.';
    return '';
  }
}

if (!function_exists('password_setup_consume')) {
  /**
   * Grava a nova senha e consome o link. Também atualiza a senha do treinamento EAD
   * para o administrador da empresa (falha do EAD não desfaz a troca de senha).
   */
  function password_setup_consume(PDO $pdo, array $tokenRow, string $newPassword): array {
    $userId = (int)$tokenRow['user_id'];
    $companyId = (int)$tokenRow['company_id'];
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);

    $pdo->beginTransaction();
    try {
      $st = $pdo->prepare('UPDATE user_password_tokens SET used_at = NOW() WHERE id = ? AND used_at IS NULL');
      $st->execute([(int)$tokenRow['token_id']]);
      if ($st->rowCount() !== 1) throw new RuntimeException('Este link já foi utilizado.');

      $sql = password_setup_users_has_plain_column($pdo)
        ? 'UPDATE users SET password_hash = ?, last_plain_password = NULL WHERE id = ? AND company_id = ?'
        : 'UPDATE users SET password_hash = ? WHERE id = ? AND company_id = ?';
      $pdo->prepare($sql)->execute([$hash, $userId, $companyId]);
      $pdo->prepare('UPDATE user_password_tokens SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')->execute([$userId]);
      $pdo->commit();
    } catch (Throwable $e) {
      if ($pdo->inTransaction()) $pdo->rollBack();
      throw $e;
    }

    $ead = ['ok' => false, 'skipped' => true];
    if ((string)($tokenRow['user_role'] ?? '') === 'company_admin') {
      $ead = password_setup_sync_ead($pdo, $companyId, (string)$tokenRow['user_name'], (string)$tokenRow['user_email'], $newPassword);
    }
    return ['ok' => true, 'ead' => $ead];
  }
}

if (!function_exists('password_setup_sync_ead')) {
  function password_setup_sync_ead(PDO $pdo, int $companyId, string $name, string $email, string $password): array {
    try {
      require_once __DIR__ . '/ead_integration.php';
      if (!function_exists('ead_integration_is_enabled') || !ead_integration_is_enabled()) return ['ok' => false, 'skipped' => true];
      $st = $pdo->prepare('SELECT * FROM companies WHERE id = ? LIMIT 1');
      $st->execute([$companyId]);
      $company = $st->fetch(PDO::FETCH_ASSOC) ?: [];
      $phone = (string)($company['whatsapp'] ?? $company['celular'] ?? '');
      $result = ead_integration_sync_company([
        'company_id' => $companyId,
        'slug' => (string)($company['slug'] ?? ''),
        'nome' => $name,
        'empresa' => (string)($company['name'] ?? ''),
        'email' => $email,
        'senha' => $password,
        'whatsapp' => $phone,
        'celular' => $phone,
      ]);
      return is_array($result) ? $result : ['ok' => false];
    } catch (Throwable $e) {
      return ['ok' => false, 'error' => $e->getMessage()];
    }
  }
}

if (!function_exists('password_setup_adjust_message')) {
  /**
   * Ajusta mensagens de boas-vindas (inclusive modelos personalizados antigos que
   * usam {{senha}}) quando o valor enviado é um link de criação de senha.
   */
  function password_setup_adjust_message(string $message, string $credential): string {
    if (!preg_match('~^https?://~i', $credential)) return $message;
    $label = 'Crie sua senha de acesso (link válido por ' . NEXA_PASSWORD_LINK_HOURS . ' horas)';
    $quoted = preg_quote($credential, '~');
    $message = preg_replace('~(Senha inicial|Senha)\s*:\s*(\r?\n)?\s*' . $quoted . '~iu', $label . ":\n" . $credential, $message) ?? $message;
    $message = str_replace(
      ['Use o mesmo usuário e senha acima para acessar o treinamento.', 'Use o mesmo usuário e senha para acessar os treinamentos.'],
      ['Depois de criar sua senha, use o mesmo usuário e senha para acessar o treinamento.', 'Depois de criar sua senha, use o mesmo usuário e senha para acessar os treinamentos.'],
      $message
    );
    $message = preg_replace('~\n?Recomendamos alterar a senha após o primeiro acesso\.?~u', '', $message) ?? $message;
    return trim($message);
  }
}
