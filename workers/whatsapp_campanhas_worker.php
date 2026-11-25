<?php
declare(strict_types=1);

/**
 * Agendador das Campanhas e da régua de Cobranças do WhatsApp (WhatsApp > Campanhas / Cobranças).
 *
 * Roda pelo timer nexa-whatsapp-campanhas (a cada minuto) e termina em seguida:
 *  - campanhas programadas que chegaram na hora: põe a lista conferida na fila de envio;
 *  - campanha automática de aniversário: uma vez por dia, a partir da hora marcada;
 *  - régua de cobrança: uma vez por dia, a partir da hora marcada (config/whatsapp_cobrancas.php);
 *  - traz da fila o resultado de cada envio e encerra as campanhas que terminaram;
 *  - mensagens programadas no Atendimento (config/whatsapp_programadas.php): envia na hora.
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
require_once $root . '/config/whatsapp_programadas.php';
app_apply_company_timezone($pdo, 'America/Sao_Paulo');

$lock = fopen(sys_get_temp_dir() . '/nexa_whatsapp_campanhas.lock', 'c');
if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) exit(0);

try {
    $items = [];
    // Mensagens programadas no Atendimento (botão 🕒): saem na hora marcada.
    if (whatsapp_sched_ready($pdo)) $items = whatsapp_sched_dispatch($pdo);
    if (wa_camp_ready($pdo)) $items = array_merge($items, wa_camp_dispatch($pdo), wa_cob_dispatch($pdo));
    foreach ($items as $item) {
        echo '[' . date('c') . '] ' . json_encode($item, JSON_UNESCAPED_UNICODE) . "\n";
    }
} catch (Throwable $e) {
    fwrite(STDERR, '[' . date('c') . '] ' . $e->getMessage() . "\n");
    exit(1);
}
