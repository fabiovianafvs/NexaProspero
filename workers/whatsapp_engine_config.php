<?php
declare(strict_types=1);

// Grava no banco (plataforma) o endereço e as chaves do motor de WhatsApp leve.
// Chamado pelo instalador deploy/whatsapp-engine/instalar_motor_whatsapp.sh.
// Os valores chegam por variáveis de ambiente (não aparecem na lista de processos):
//   NEXA_WA_URL, NEXA_WA_TOKEN, NEXA_WA_SECRET
// Uso manual para conferir: php workers/whatsapp_engine_config.php --mostrar

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

if (in_array('--mostrar', $argv, true)) {
  $cfg = whatsapp_engine_config($pdo);
  echo 'URL: ' . ($cfg['url'] !== '' ? $cfg['url'] : '(não configurado)') . PHP_EOL;
  echo 'Token: ' . ($cfg['token'] !== '' ? 'configurado' : 'vazio') . PHP_EOL;
  echo 'Instalado em: ' . ($cfg['installed_at'] ?: '-') . PHP_EOL;
  $health = whatsapp_engine_health($cfg);
  echo 'Saúde: ' . json_encode($health, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
  exit(0);
}

$url = rtrim(trim((string)getenv('NEXA_WA_URL')), '/');
$token = trim((string)getenv('NEXA_WA_TOKEN'));
$secret = trim((string)getenv('NEXA_WA_SECRET'));
if (!preg_match('#^https?://[0-9A-Za-z.\-]+(:\d+)?$#', $url) || strlen($token) < 32 || strlen($secret) < 32) {
  fwrite(STDERR, "Parâmetros inválidos (NEXA_WA_URL / NEXA_WA_TOKEN / NEXA_WA_SECRET).\n");
  exit(1);
}
$old = config_store_get($pdo, 0, WHATSAPP_ENGINE_CFG_KEY) ?: [];
config_store_set($pdo, 0, WHATSAPP_ENGINE_CFG_KEY, [
  'url' => $url,
  'token' => $token,
  'webhook_secret' => $secret,
  'installed_at' => (string)($old['installed_at'] ?? '') !== '' ? (string)$old['installed_at'] : date('Y-m-d H:i:s'),
  'updated_at' => date('Y-m-d H:i:s'),
]);
echo "Motor de WhatsApp registrado no sistema: {$url}\n";
