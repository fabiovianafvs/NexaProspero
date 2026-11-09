<?php
declare(strict_types=1);

/**
 * Mensagens recebidas no WhatsApp (motor leve). O motor chama este endereço
 * pelo túnel privado, assinando o corpo com HMAC-SHA256 (segredo em
 * /etc/nexa/whatsapp_engine.env → NEXA_WA_SECRET). Sem assinatura válida, nada
 * é processado. Responde 200 na hora e trata a mensagem em seguida (chatbot).
 */

header('Content-Type: application/json; charset=UTF-8');

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
  http_response_code(405);
  echo '{"ok":false}';
  exit;
}

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';
require_once __DIR__ . '/../config/whatsapp_chatbot.php';

$raw = (string)file_get_contents('php://input');
$engine = whatsapp_engine_config($pdo);
$secret = (string)($engine['webhook_secret'] ?? '');
$ts = trim((string)($_SERVER['HTTP_X_NEXA_TIMESTAMP'] ?? ''));
$signature = strtolower(trim((string)($_SERVER['HTTP_X_NEXA_SIGNATURE'] ?? '')));

if ($secret === '' || $ts === '' || !ctype_digit($ts) || abs(time() - (int)$ts) > 300
  || $signature === '' || !hash_equals(hash_hmac('sha256', $ts . '.' . $raw, $secret), $signature)) {
  http_response_code(403);
  echo '{"ok":false,"message":"Assinatura inválida."}';
  exit;
}

$payload = json_decode($raw, true);
if (!is_array($payload) || !in_array(($payload['event'] ?? ''), ['message', 'human_reply'], true)) {
  echo '{"ok":true,"ignored":true}';
  exit;
}

// Libera o motor imediatamente; o atendimento (boletos em PDF etc.) segue em segundo plano.
echo '{"ok":true}';
ignore_user_abort(true);
if (function_exists('fastcgi_finish_request')) {
  fastcgi_finish_request();
} else {
  @ob_end_flush();
  @flush();
}
@set_time_limit(180);

try {
  if (($payload['event'] ?? '') === 'human_reply') whatsapp_bot_human_reply($pdo, $payload);
  else whatsapp_bot_handle($pdo, $payload);
} catch (Throwable $e) {
  error_log('[whatsapp_entrada] ' . $e->getMessage());
}
