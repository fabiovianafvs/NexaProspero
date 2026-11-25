<?php
declare(strict_types=1);

/**
 * Agendador das Campanhas e da régua de Cobranças do WhatsApp (WhatsApp > Campanhas / Cobranças).
 *
 * Roda pelo timer nexa-whatsapp-campanhas (a cada minuto) e termina em seguida:
 *  - campanhas programadas que chegaram na hora: põe a lista conferida na fila de envio;
 *  - campanha automática de aniversário: uma vez por dia, a partir da hora marcada;
 *  - régua de cobrança: uma vez por dia, a partir da hora marcada (config/whatsapp_cobrancas.php);
 *  - traz da fila o resultado de cada envio e encerra as campanhas que terminaram.
 * O envio em si é feito pelo processo da fila (nexa-whatsapp-outbox), espaçado.
 *
 * Uso manual: php workers/whatsapp_campanhas_worker.php
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Execução permitida somente por linha de comando.\n");
}

$root = dirname(__DIR__);
require_once $root . '/config/nexa_env.php';
require $root . '/config/pdo.php';
require_once $root . '/config/whatsapp_campanhas.php';
require_once $root . '/config/whatsapp_cobrancas.php';
app_apply_company_timezone($pdo, 'America/Sao_Paulo');

$lock = fopen(sys_get_temp_dir() . '/nexa_whatsapp_campanhas.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

if (!wa_camp_ready($pdo)) exit(0); // migração ainda não aplicada

try {
    foreach (array_merge(wa_camp_dispatch($pdo), wa_cob_dispatch($pdo)) as $item) {
        echo '[' . date('c') . '] ' . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n");
    exit(1);
}
