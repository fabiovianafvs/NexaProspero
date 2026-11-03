<?php
declare(strict_types=1);

/**
 * Nexa Próspero - E-mail da plataforma (Central SaaS).
 *
 * Servidor SMTP único da plataforma, usado para:
 *  - enviar os dados de acesso quando uma nova empresa é cadastrada;
 *  - enviar o link de "Esqueci minha senha".
 *
 * Fica em app_config (company_id = 0, cfg_key = 'saas_email'). A senha SMTP é
 * guardada criptografada com email_secret_encrypt() de config/email_service.php.
 */

require_once __DIR__ . '/helpers.php';
require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/email_service.php';

if (!defined('SAAS_EMAIL_CFG_KEY')) define('SAAS_EMAIL_CFG_KEY', 'saas_email');

if (!function_exists('saas_email_defaults')) {
  function saas_email_defaults(): array {
    return [
      'enabled' => false,
      'smtp_host' => '',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_username' => '',
      'smtp_password_secret' => '',
      'from_email' => '',
      'from_name' => 'Nexa Próspero',
      'reply_to' => '',
      'timeout_seconds' => 30,
      'send_signup' => true,
      'send_password_reset' => true,
      'test_email' => '',
    ];
  }
}

if (!function_exists('saas_email_load')) {
  function saas_email_load(PDO $pdo): array {
    $cfg = saas_email_defaults();
    try {
      $saved = config_store_get($pdo, 0, SAAS_EMAIL_CFG_KEY);
      if (is_array($saved)) $cfg = array_merge($cfg, $saved);
    } catch (Throwable $e) {}
    return $cfg;
  }
}

if (!function_exists('saas_email_save')) {
  function saas_email_save(PDO $pdo, array $input): array {
    $old = saas_email_load($pdo);
    $enabled = !empty($input['enabled']);
    $host = trim((string)($input['smtp_host'] ?? ''));
    $port = max(1, min(65535, (int)($input['smtp_port'] ?? 587)));
    $security = strtolower(trim((string)($input['smtp_security'] ?? 'tls')));
    if (!in_array($security, ['tls', 'ssl', 'none'], true)) $security = 'tls';
    $username = trim((string)($input['smtp_username'] ?? ''));
    $password = (string)($input['smtp_password'] ?? '');
    $secret = $password !== '' ? email_secret_encrypt($password) : (string)($old['smtp_password_secret'] ?? '');
    $fromEmail = strtolower(trim((string)($input['from_email'] ?? '')));
    $fromName = trim((string)($input['from_name'] ?? '')) ?: 'Nexa Próspero';
    $replyTo = strtolower(trim((string)($input['reply_to'] ?? '')));
    $testEmail = strtolower(trim((string)($input['test_email'] ?? '')));

    if ($enabled) {
      if ($host === '') throw new RuntimeException('Informe o servidor SMTP.');
      if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail remetente válido.');
      if ($username !== '' && $secret === '') throw new RuntimeException('Informe a senha do e-mail (SMTP).');
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail remetente inválido.');
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail de resposta inválido.');
    if ($testEmail !== '' && !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail para teste inválido.');

    $cfg = [
      'enabled' => $enabled,
      'smtp_host' => $host,
      'smtp_port' => $port,
      'smtp_security' => $security,
      'smtp_username' => $username,
      'smtp_password_secret' => $secret,
      'from_email' => $fromEmail,
      'from_name' => $fromName,
      'reply_to' => $replyTo,
      'timeout_seconds' => max(10, min(120, (int)($input['timeout_seconds'] ?? 30))),
      'send_signup' => !empty($input['send_signup']),
      'send_password_reset' => !empty($input['send_password_reset']),
      'test_email' => $testEmail,
    ];
    config_store_set($pdo, 0, SAAS_EMAIL_CFG_KEY, $cfg);
    return $cfg;
  }
}

if (!function_exists('saas_email_ready')) {
  function saas_email_ready(array $cfg): bool {
    return email_settings_ready($cfg);
  }
}

if (!function_exists('saas_email_is_real_address')) {
  /**
   * Indica se o e-mail parece real (pode receber mensagens). E-mails usados só
   * como "nome de login" (ex.: joao@loja, caixa@sem-email.local) retornam false.
   */
  function saas_email_is_real_address(string $email): bool {
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) return false;
    $domain = (string)substr((string)strrchr($email, '@'), 1);
    if ($domain === '' || !str_contains($domain, '.')) return false;
    foreach (['.local', '.localhost', '.test', '.invalid', '.example', 'example.com', 'sem-email.nexaprospero.local'] as $fake) {
      if ($domain === ltrim($fake, '.') || str_ends_with($domain, $fake)) return false;
    }
    static $dns = [];
    if (!array_key_exists($domain, $dns)) {
      $dns[$domain] = function_exists('checkdnsrr') ? (@checkdnsrr($domain, 'MX') || @checkdnsrr($domain, 'A')) : true;
    }
    return $dns[$domain];
  }
}

if (!function_exists('saas_email_plain_from_whatsapp')) {
  /** Converte o texto da mensagem de WhatsApp (*negrito*) em texto simples para e-mail. */
  function saas_email_plain_from_whatsapp(string $text): string {
    $text = preg_replace('/\*([^*\n]+)\*/u', '$1', $text) ?? $text;
    return trim($text);
  }
}

if (!function_exists('saas_email_send')) {
  function saas_email_send(PDO $pdo, string $to, string $subject, string $body, string $context = 'saas', ?int $companyId = null, ?array $cfg = null): array {
    $cfg = $cfg ?? saas_email_load($pdo);
    $to = strtolower(trim($to));
    if (!saas_email_ready($cfg)) return ['ok' => false, 'skipped' => true, 'error' => 'E-mail da plataforma não configurado ou desativado.'];
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) return ['ok' => false, 'skipped' => true, 'error' => 'Destinatário sem e-mail válido.'];
    $result = email_smtp_send($cfg, [$to], $subject, $body);
    email_log_send($pdo, (int)($companyId ?? 0), null, $context, null, $to, $subject, !empty($result['ok']), (string)($result['error'] ?? ''), true);
    return $result + ['to' => $to];
  }
}

if (!function_exists('saas_email_send_signup')) {
  /** Envia ao cliente os dados de acesso do cadastro (mesmo conteúdo do WhatsApp). */
  function saas_email_send_signup(PDO $pdo, int $companyId, string $toEmail, string $companyName, string $messageText): array {
    $cfg = saas_email_load($pdo);
    if (empty($cfg['send_signup'])) return ['ok' => false, 'skipped' => true, 'error' => 'Envio de e-mail no cadastro desativado.'];
    if (!saas_email_is_real_address($toEmail)) return ['ok' => false, 'skipped' => true, 'error' => 'E-mail do cadastro não é um endereço válido para envio.'];
    $subject = 'Seu acesso ao Nexa Próspero - ' . (trim($companyName) ?: 'nova empresa');
    return saas_email_send($pdo, $toEmail, $subject, saas_email_plain_from_whatsapp($messageText), 'saas_signup', $companyId, $cfg);
  }
}
