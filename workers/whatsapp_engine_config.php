<?php
declare(strict_types=1);

// Mostra a configuração do motor de WhatsApp leve (lida de /etc/nexa/whatsapp_engine.env)
// e testa se ele responde pelo túnel.
// Uso: php /var/www/nexa/workers/whatsapp_engine_config.php --mostrar

if (PHP_SAPI !== 'cli') { http_response_code(403); exit; }

require_once __DIR__ . '/../config/pdo.php';
require_once __DIR__ . '/../config/whatsapp_engine.php';

$cfg = whatsapp_engine_config($pdo);
echo 'Arquivo: ' . whatsapp_engine_env_file() . PHP_EOL;
echo 'URL: ' . ($cfg['url'] !== '' ? $cfg['url'] : '(não configurado)') . PHP_EOL;
echo 'Token: ' . ($cfg['token'] !== '' ? 'configurado' : 'vazio') . PHP_EOL;
echo 'Instalado em: ' . ($cfg['installed_at'] ?: '-') . PHP_EOL;
echo 'Saúde: ' . json_encode(whatsapp_engine_health($cfg), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL;
