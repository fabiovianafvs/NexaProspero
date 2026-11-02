<?php
declare(strict_types=1);

require_once __DIR__ . '/config_store.php';
require_once __DIR__ . '/auth.php';

if (!function_exists('email_service_ensure_schema')) {
  function email_service_ensure_schema(PDO $pdo): void {
    static $done = [];
    $key = spl_object_id($pdo);
    if (!empty($done[$key])) return;

    $pdo->exec("CREATE TABLE IF NOT EXISTS user_email_settings (
      company_id INT NOT NULL,
      user_id INT NOT NULL,
      enabled TINYINT(1) NOT NULL DEFAULT 0,
      smtp_host VARCHAR(255) NULL,
      smtp_port INT NOT NULL DEFAULT 587,
      smtp_security VARCHAR(12) NOT NULL DEFAULT 'tls',
      smtp_username VARCHAR(255) NULL,
      smtp_password_secret TEXT NULL,
      from_email VARCHAR(255) NULL,
      from_name VARCHAR(160) NULL,
      reply_to VARCHAR(255) NULL,
      timeout_seconds INT NOT NULL DEFAULT 30,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL,
      PRIMARY KEY (company_id, user_id),
      KEY idx_user_email_user (user_id),
      KEY idx_user_email_enabled (company_id, enabled)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $pdo->exec("CREATE TABLE IF NOT EXISTS email_send_log (
      id BIGINT NOT NULL AUTO_INCREMENT,
      company_id INT NOT NULL,
      user_id INT NULL,
      context VARCHAR(40) NULL,
      reference_id BIGINT NULL,
      recipient VARCHAR(255) NOT NULL,
      subject VARCHAR(255) NULL,
      status VARCHAR(20) NOT NULL,
      error_message TEXT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      PRIMARY KEY (id),
      KEY idx_email_log_company_date (company_id, created_at),
      KEY idx_email_log_user_date (user_id, created_at),
      KEY idx_email_log_reference (company_id, context, reference_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $done[$key] = true;
  }
}

if (!function_exists('email_crypto_key')) {
  function email_crypto_key(): string {
    $secret = trim((string)(getenv('NEXA_EMAIL_SECRET_KEY') ?: ''));
    if ($secret === '') {
      $db = require __DIR__ . '/db.php';
      $secret = (string)($db['name'] ?? '') . '|' . (string)($db['user'] ?? '') . '|' . (string)($db['pass'] ?? '') . '|NEXA-EMAIL-V1';
    }
    return hash('sha256', $secret, true);
  }
}

if (!function_exists('email_secret_encrypt')) {
  function email_secret_encrypt(string $plain): string {
    if ($plain === '') return '';
    if (!function_exists('openssl_encrypt')) return 'plain:' . base64_encode($plain);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plain, 'aes-256-gcm', email_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    if ($cipher === false) throw new RuntimeException('Não foi possível proteger a senha SMTP.');
    return 'v1:' . base64_encode($iv . $tag . $cipher);
  }
}

if (!function_exists('email_secret_decrypt')) {
  function email_secret_decrypt(string $secret): string {
    if ($secret === '') return '';
    if (str_starts_with($secret, 'plain:')) {
      $v = base64_decode(substr($secret, 6), true);
      return $v === false ? '' : $v;
    }
    if (!str_starts_with($secret, 'v1:') || !function_exists('openssl_decrypt')) return '';
    $raw = base64_decode(substr($secret, 3), true);
    if ($raw === false || strlen($raw) < 29) return '';
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', email_crypto_key(), OPENSSL_RAW_DATA, $iv, $tag);
    return $plain === false ? '' : $plain;
  }
}

if (!function_exists('email_settings_default')) {
  function email_settings_default(): array {
    return [
      'enabled' => false,
      'smtp_host' => '',
      'smtp_port' => 587,
      'smtp_security' => 'tls',
      'smtp_username' => '',
      'smtp_password_secret' => '',
      'from_email' => '',
      'from_name' => '',
      'reply_to' => '',
      'timeout_seconds' => 30,
    ];
  }
}

if (!function_exists('email_settings_load')) {
  function email_settings_load(PDO $pdo, int $companyId, int $userId, bool $skipSchemaEnsure = false): array {
    $cfg = email_settings_default();
    if ($companyId <= 0 || $userId <= 0) return $cfg;
    if (!$skipSchemaEnsure) email_service_ensure_schema($pdo);
    $st = $pdo->prepare('SELECT * FROM user_email_settings WHERE company_id=? AND user_id=? LIMIT 1');
    $st->execute([$companyId, $userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) return $cfg;
    $cfg = array_merge($cfg, $row);
    $cfg['enabled'] = !empty($cfg['enabled']);
    $cfg['smtp_port'] = max(1, min(65535, (int)($cfg['smtp_port'] ?? 587)));
    $security = strtolower(trim((string)($cfg['smtp_security'] ?? 'tls')));
    $cfg['smtp_security'] = in_array($security, ['tls','ssl','none'], true) ? $security : 'tls';
    $cfg['timeout_seconds'] = max(10, min(120, (int)($cfg['timeout_seconds'] ?? 30)));
    return $cfg;
  }
}

if (!function_exists('email_settings_save')) {
  function email_settings_save(PDO $pdo, int $companyId, int $userId, array $input, bool $skipSchemaEnsure = false): void {
    if ($companyId <= 0 || $userId <= 0) throw new RuntimeException('Usuário inválido para configuração de e-mail.');
    if (!$skipSchemaEnsure) email_service_ensure_schema($pdo);
    $old = email_settings_load($pdo, $companyId, $userId, $skipSchemaEnsure);

    $enabled = !empty($input['enabled']) ? 1 : 0;
    $host = trim((string)($input['smtp_host'] ?? ''));
    $port = max(1, min(65535, (int)($input['smtp_port'] ?? 587)));
    $security = strtolower(trim((string)($input['smtp_security'] ?? 'tls')));
    if (!in_array($security, ['tls','ssl','none'], true)) $security = 'tls';
    $username = trim((string)($input['smtp_username'] ?? ''));
    $password = (string)($input['smtp_password'] ?? '');
    $secret = $password !== '' ? email_secret_encrypt($password) : (string)($old['smtp_password_secret'] ?? '');
    $fromEmail = strtolower(trim((string)($input['from_email'] ?? '')));
    $fromName = trim((string)($input['from_name'] ?? ''));
    $replyTo = strtolower(trim((string)($input['reply_to'] ?? '')));
    $timeout = max(10, min(120, (int)($input['timeout_seconds'] ?? 30)));

    if ($enabled) {
      if ($host === '') throw new RuntimeException('Informe o servidor SMTP.');
      if ($fromEmail === '' || !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('Informe um e-mail remetente válido.');
      if ($username !== '' && $secret === '') throw new RuntimeException('Informe a senha SMTP.');
    }
    if ($fromEmail !== '' && !filter_var($fromEmail, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail remetente inválido.');
    if ($replyTo !== '' && !filter_var($replyTo, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('E-mail de resposta inválido.');

    $sql = "INSERT INTO user_email_settings
      (company_id,user_id,enabled,smtp_host,smtp_port,smtp_security,smtp_username,smtp_password_secret,from_email,from_name,reply_to,timeout_seconds,updated_at)
      VALUES (?,?,?,?,?,?,?,?,?,?,?,?,NOW())
      ON DUPLICATE KEY UPDATE enabled=VALUES(enabled),smtp_host=VALUES(smtp_host),smtp_port=VALUES(smtp_port),smtp_security=VALUES(smtp_security),smtp_username=VALUES(smtp_username),smtp_password_secret=VALUES(smtp_password_secret),from_email=VALUES(from_email),from_name=VALUES(from_name),reply_to=VALUES(reply_to),timeout_seconds=VALUES(timeout_seconds),updated_at=NOW()";
    $pdo->prepare($sql)->execute([$companyId,$userId,$enabled,$host,$port,$security,$username,$secret,$fromEmail,$fromName,$replyTo,$timeout]);
  }
}

if (!function_exists('email_settings_ready')) {
  function email_settings_ready(array $cfg): bool {
    return !empty($cfg['enabled'])
      && trim((string)($cfg['smtp_host'] ?? '')) !== ''
      && (int)($cfg['smtp_port'] ?? 0) > 0
      && filter_var((string)($cfg['from_email'] ?? ''), FILTER_VALIDATE_EMAIL) !== false;
  }
}

if (!function_exists('email_message_model_contexts')) {
  function email_message_model_contexts(): array {
    return [
      'pedido' => 'Pedido',
      'pedido_compra' => 'Pedido de Compra',
      'boleto' => 'Boleto',
      'nfe' => 'NF-e',
      'nfce' => 'NFC-e',
      'nfse' => 'NFS-e',
      'orcamento' => 'Orçamento',
      'ordem_servico' => 'Ordem de Serviço',
      'recibo' => 'Recibo',
      'relatorio' => 'Relatório / outros documentos',
    ];
  }
}

if (!function_exists('email_message_model_variables')) {
  function email_message_model_variables(string $context): array {
    $common = ['{{empresa}}','{{cliente}}','{{email_cliente}}','{{usuario}}'];
    $specific = [
      'pedido_compra' => ['{{empresa}}','{{fornecedor}}','{{pedido}}','{{pedido_id}}','{{numero}}','{{data}}','{{itens}}','{{total}}'],
      'pedido' => ['{{pedido_id}}','{{data}}','{{itens}}','{{subtotal}}','{{taxa_entrega}}','{{total}}','{{tipo_entrega}}','{{pagamento}}','{{endereco}}','{{observacoes}}'],
      'boleto' => ['{{titulo_id}}','{{boleto_id}}','{{pedido}}','{{contrato}}','{{vencimento}}','{{valor}}','{{nosso_numero}}','{{linha_digitavel}}','{{pix_copia_cola}}'],
      'nfe' => ['{{documento}}','{{numero}}','{{serie}}','{{total}}','{{chave_acesso}}'],
      'nfce' => ['{{documento}}','{{numero}}','{{serie}}','{{total}}','{{chave_acesso}}'],
      'nfse' => ['{{documento}}','{{numero}}','{{serie}}','{{dps_serie}}','{{dps_numero}}','{{total}}','{{chave_acesso}}','{{codigo_verificacao}}'],
      'orcamento' => ['{{orcamento_id}}','{{data}}','{{validade}}','{{total}}','{{status}}'],
      'ordem_servico' => ['{{os_id}}','{{data}}','{{status}}','{{previsao}}','{{total}}'],
      'recibo' => ['{{recibo_id}}','{{data}}','{{valor}}'],
      'relatorio' => ['{{relatorio}}','{{periodo}}','{{data}}'],
    ];
    $context = strtolower(trim($context));
    return array_values(array_unique(array_merge($common, (array)($specific[$context] ?? []))));
  }
}

if (!function_exists('email_message_models_default_state')) {
  function email_message_models_default_state(): array {
    $defaults = [];
    foreach (array_keys(email_message_model_contexts()) as $context) $defaults[$context] = 0;
    return ['next_id'=>1,'items'=>[],'defaults'=>$defaults];
  }
}

if (!function_exists('email_message_models_load')) {
  function email_message_models_load(PDO $pdo, int $companyId): array {
    $state = email_message_models_default_state();
    try {
      $saved = config_store_get($pdo, $companyId, 'email_message_models');
      if (is_array($saved)) $state = config_deep_merge($state, $saved);
    } catch (Throwable $e) {}

    $contexts = email_message_model_contexts();
    $items = [];
    $maxId = 0;
    foreach ((array)($state['items'] ?? []) as $item) {
      if (!is_array($item)) continue;
      $id = max(0, (int)($item['id'] ?? 0));
      $context = strtolower(trim((string)($item['context'] ?? '')));
      $title = trim((string)($item['title'] ?? ''));
      $subject = trim((string)($item['subject'] ?? ''));
      $message = trim((string)($item['message'] ?? ''));
      if ($id <= 0 || !isset($contexts[$context]) || $title === '' || $subject === '' || $message === '') continue;
      $items[] = [
        'id'=>$id,
        'context'=>$context,
        'title'=>mb_substr($title,0,120),
        'subject'=>mb_substr($subject,0,255),
        'message'=>mb_substr($message,0,20000),
        'active'=>!array_key_exists('active',$item) || !empty($item['active']),
      ];
      $maxId = max($maxId,$id);
    }
    usort($items, static function($a,$b): int {
      $c = strcasecmp((string)$a['context'], (string)$b['context']);
      return $c !== 0 ? $c : strcasecmp((string)$a['title'], (string)$b['title']);
    });

    $valid = [];
    foreach ($items as $item) if (!empty($item['active'])) $valid[(string)$item['context']][(int)$item['id']] = true;
    $defaults = [];
    foreach (array_keys($contexts) as $context) {
      $id = max(0,(int)($state['defaults'][$context] ?? 0));
      $defaults[$context] = ($id > 0 && !empty($valid[$context][$id])) ? $id : 0;
    }
    return ['next_id'=>max($maxId+1,(int)($state['next_id'] ?? 1),1),'items'=>$items,'defaults'=>$defaults];
  }
}

if (!function_exists('email_message_models_save')) {
  function email_message_models_save(PDO $pdo, int $companyId, array $state): void {
    $contexts = email_message_model_contexts();
    $items = [];
    $maxId = 0;
    foreach ((array)($state['items'] ?? []) as $item) {
      if (!is_array($item)) continue;
      $id = max(0,(int)($item['id'] ?? 0));
      $context = strtolower(trim((string)($item['context'] ?? '')));
      $title = trim((string)($item['title'] ?? ''));
      $subject = trim((string)($item['subject'] ?? ''));
      $message = trim((string)($item['message'] ?? ''));
      if ($id <= 0 || !isset($contexts[$context]) || $title === '' || $subject === '' || $message === '') continue;
      $items[] = ['id'=>$id,'context'=>$context,'title'=>mb_substr($title,0,120),'subject'=>mb_substr($subject,0,255),'message'=>mb_substr($message,0,20000),'active'=>!empty($item['active'])];
      $maxId = max($maxId,$id);
    }
    $valid = [];
    foreach ($items as $item) if (!empty($item['active'])) $valid[(string)$item['context']][(int)$item['id']] = true;
    $defaults = [];
    foreach (array_keys($contexts) as $context) {
      $id = max(0,(int)($state['defaults'][$context] ?? 0));
      $defaults[$context] = ($id > 0 && !empty($valid[$context][$id])) ? $id : 0;
    }
    config_store_set($pdo,$companyId,'email_message_models',['next_id'=>max($maxId+1,(int)($state['next_id'] ?? 1),1),'items'=>$items,'defaults'=>$defaults]);
  }
}

if (!function_exists('email_message_model_find')) {
  function email_message_model_find(array $state, int $id): ?array {
    foreach ((array)($state['items'] ?? []) as $item) if ((int)($item['id'] ?? 0) === $id && !empty($item['active'])) return $item;
    return null;
  }
}

if (!function_exists('email_message_model_selected')) {
  function email_message_model_selected(PDO $pdo, int $companyId, string $context, int $modelId = 0): ?array {
    $state = email_message_models_load($pdo,$companyId);
    $context = strtolower(trim($context));
    if (!isset(email_message_model_contexts()[$context])) return null;
    $id = $modelId > 0 ? $modelId : (int)($state['defaults'][$context] ?? 0);
    $item = email_message_model_find($state,$id);
    return $item && (string)($item['context'] ?? '') === $context ? $item : null;
  }
}

if (!function_exists('email_render_template')) {
  function email_render_template(string $template, array $vars): string {
    $replace = [];
    foreach ($vars as $key=>$value) {
      $name = trim((string)$key, "{} \t\r\n");
      $replace['{{'.$name.'}}'] = is_scalar($value) || $value === null ? (string)$value : '';
    }
    return trim(strtr($template,$replace));
  }
}

if (!function_exists('email_message_model_render')) {
  function email_message_model_render(PDO $pdo, int $companyId, string $context, array $vars, int $modelId = 0, string $fallbackSubject = '', string $fallbackBody = ''): array {
    $item = email_message_model_selected($pdo,$companyId,$context,$modelId);
    $subjectTpl = $item ? (string)$item['subject'] : $fallbackSubject;
    $bodyTpl = $item ? (string)$item['message'] : $fallbackBody;
    return [
      'model_id'=>(int)($item['id'] ?? 0),
      'subject'=>email_render_template($subjectTpl,$vars),
      'body'=>email_render_template($bodyTpl,$vars),
    ];
  }
}

if (!function_exists('email_header_clean')) {
  function email_header_clean(string $value): string {
    return trim(str_replace(["\r","\n"], ' ', $value));
  }
}

if (!function_exists('email_address_header')) {
  function email_address_header(string $email, string $name = ''): string {
    $email = strtolower(trim($email));
    $name = email_header_clean($name);
    if ($name === '') return '<' . $email . '>';
    return mb_encode_mimeheader($name,'UTF-8','B',"\r\n") . ' <' . $email . '>';
  }
}

if (!function_exists('email_build_mime')) {
  function email_build_mime(array $cfg, array $to, string $subject, string $body, array $attachments = []): string {
    $fromEmail = strtolower(trim((string)($cfg['from_email'] ?? '')));
    $fromName = trim((string)($cfg['from_name'] ?? ''));
    $replyTo = strtolower(trim((string)($cfg['reply_to'] ?? '')));
    $subject = email_header_clean($subject);
    $boundaryMixed = '=_NexaMixed_' . bin2hex(random_bytes(12));
    $boundaryAlt = '=_NexaAlt_' . bin2hex(random_bytes(12));

    $toHeaders = [];
    foreach ($to as $item) {
      $mail = strtolower(trim((string)($item['email'] ?? $item ?? '')));
      $name = is_array($item) ? trim((string)($item['name'] ?? '')) : '';
      $toHeaders[] = email_address_header($mail,$name);
    }

    $headers = [
      'Date: ' . date(DATE_RFC2822),
      'From: ' . email_address_header($fromEmail,$fromName),
      'To: ' . implode(', ', $toHeaders),
      'Subject: ' . mb_encode_mimeheader($subject,'UTF-8','B',"\r\n"),
      'Message-ID: <' . bin2hex(random_bytes(12)) . '@' . preg_replace('/[^a-z0-9.-]/i','',substr(strrchr($fromEmail,'@') ?: '@nexa.local',1)) . '>',
      'MIME-Version: 1.0',
      'Content-Type: multipart/mixed; boundary="' . $boundaryMixed . '"',
    ];
    if ($replyTo !== '' && filter_var($replyTo,FILTER_VALIDATE_EMAIL)) $headers[] = 'Reply-To: <' . $replyTo . '>';

    $plain = str_replace(["\r\n","\r"],"\n",$body);
    $html = '<div style="font-family:Arial,Helvetica,sans-serif;font-size:14px;line-height:1.55;color:#0f172a">' . nl2br(htmlspecialchars($plain,ENT_QUOTES,'UTF-8')) . '</div>';

    $mime = implode("\r\n",$headers) . "\r\n\r\n";
    $mime .= '--' . $boundaryMixed . "\r\n";
    $mime .= 'Content-Type: multipart/alternative; boundary="' . $boundaryAlt . '"' . "\r\n\r\n";
    $mime .= '--' . $boundaryAlt . "\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $mime .= chunk_split(base64_encode($plain),76,"\r\n");
    $mime .= '--' . $boundaryAlt . "\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n\r\n";
    $mime .= chunk_split(base64_encode($html),76,"\r\n");
    $mime .= '--' . $boundaryAlt . "--\r\n";

    foreach ($attachments as $att) {
      $filename = email_header_clean((string)($att['filename'] ?? 'anexo.bin'));
      $mimeType = trim((string)($att['mimetype'] ?? 'application/octet-stream')) ?: 'application/octet-stream';
      $content = (string)($att['content'] ?? '');
      $safeFilename = str_replace(['"','\\'],['_','_'],$filename);
      $mime .= '--' . $boundaryMixed . "\r\n";
      $mime .= 'Content-Type: ' . $mimeType . '; name="' . $safeFilename . '"' . "\r\n";
      $mime .= 'Content-Disposition: attachment; filename="' . $safeFilename . '"' . "\r\n";
      $mime .= "Content-Transfer-Encoding: base64\r\n\r\n";
      $mime .= chunk_split(base64_encode($content),76,"\r\n");
    }
    $mime .= '--' . $boundaryMixed . "--\r\n";
    return $mime;
  }
}

if (!function_exists('email_smtp_send')) {
  function email_smtp_send(array $cfg, array $recipients, string $subject, string $body, array $attachments = []): array {
    if (!function_exists('curl_init')) return ['ok'=>false,'error'=>'A extensão cURL do PHP não está disponível.'];
    if (!email_settings_ready($cfg)) return ['ok'=>false,'error'=>'As configurações SMTP deste usuário estão incompletas ou desativadas.'];

    $to = [];
    foreach ($recipients as $recipient) {
      $email = strtolower(trim((string)(is_array($recipient) ? ($recipient['email'] ?? '') : $recipient)));
      if ($email !== '' && filter_var($email,FILTER_VALIDATE_EMAIL)) {
        $to[] = is_array($recipient) ? ['email'=>$email,'name'=>trim((string)($recipient['name'] ?? ''))] : ['email'=>$email,'name'=>''];
      }
    }
    if (!$to) return ['ok'=>false,'error'=>'Informe ao menos um destinatário válido.'];

    $host = trim((string)$cfg['smtp_host']);
    $port = (int)$cfg['smtp_port'];
    $security = strtolower(trim((string)$cfg['smtp_security']));
    $scheme = $security === 'ssl' ? 'smtps' : 'smtp';
    $url = $scheme . '://' . $host . ':' . $port;
    $mime = email_build_mime($cfg,$to,$subject,$body,$attachments);
    $stream = fopen('php://temp','w+b');
    if (!$stream) return ['ok'=>false,'error'=>'Não foi possível preparar a mensagem para envio.'];
    fwrite($stream,$mime);
    rewind($stream);

    $ch = curl_init($url);
    $opts = [
      CURLOPT_UPLOAD => true,
      CURLOPT_INFILE => $stream,
      CURLOPT_INFILESIZE => strlen($mime),
      CURLOPT_MAIL_FROM => '<' . strtolower(trim((string)$cfg['from_email'])) . '>',
      CURLOPT_MAIL_RCPT => array_map(static fn($r) => '<' . $r['email'] . '>', $to),
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_CONNECTTIMEOUT => min(30,(int)$cfg['timeout_seconds']),
      CURLOPT_TIMEOUT => (int)$cfg['timeout_seconds'],
      CURLOPT_SSL_VERIFYPEER => true,
      CURLOPT_SSL_VERIFYHOST => 2,
    ];
    if ($security === 'tls') $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
    elseif ($security === 'ssl') $opts[CURLOPT_USE_SSL] = CURLUSESSL_ALL;
    $username = trim((string)($cfg['smtp_username'] ?? ''));
    if ($username !== '') {
      $opts[CURLOPT_USERNAME] = $username;
      $opts[CURLOPT_PASSWORD] = email_secret_decrypt((string)($cfg['smtp_password_secret'] ?? ''));
    }
    curl_setopt_array($ch,$opts);
    $response = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $code = (int)curl_getinfo($ch,CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    fclose($stream);

    if ($response === false || $errno !== 0) return ['ok'=>false,'error'=>$error !== '' ? $error : ('Falha SMTP cURL #' . $errno),'smtp_code'=>$code];
    return ['ok'=>true,'smtp_code'=>$code];
  }
}

if (!function_exists('email_log_send')) {
  function email_log_send(PDO $pdo, int $companyId, ?int $userId, string $context, ?int $referenceId, string $recipient, string $subject, bool $ok, string $error = '', bool $skipSchemaEnsure = false): void {
    try {
      if (!$skipSchemaEnsure) email_service_ensure_schema($pdo);
      $st=$pdo->prepare('INSERT INTO email_send_log (company_id,user_id,context,reference_id,recipient,subject,status,error_message) VALUES (?,?,?,?,?,?,?,?)');
      $st->execute([$companyId,$userId ?: null,mb_substr($context,0,40),$referenceId ?: null,mb_substr($recipient,0,255),mb_substr($subject,0,255),$ok?'sent':'error',$ok?null:mb_substr($error,0,4000)]);
    } catch (Throwable $e) {}
  }
}

if (!function_exists('email_send_for_user')) {
  function email_send_for_user(PDO $pdo, int $companyId, int $userId, array $recipients, string $subject, string $body, array $attachments = [], string $context = '', ?int $referenceId = null, bool $skipSchemaEnsure = false): array {
    $cfg = email_settings_load($pdo,$companyId,$userId,$skipSchemaEnsure);
    $result = email_smtp_send($cfg,$recipients,$subject,$body,$attachments);
    $recipient = '';
    foreach ($recipients as $r) { $recipient = (string)(is_array($r) ? ($r['email'] ?? '') : $r); if ($recipient !== '') break; }
    email_log_send($pdo,$companyId,$userId,$context,$referenceId,$recipient,$subject,!empty($result['ok']),(string)($result['error'] ?? ''),$skipSchemaEnsure);
    return $result;
  }
}
